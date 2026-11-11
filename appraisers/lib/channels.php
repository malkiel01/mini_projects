<?php
/**
 * ערוצים וחיבורים (אפיון 08 §6): קטלוג, חיבורי משרד וחיבורי מערכת, המתאם האחיד, והסודות של חיבור.
 *
 * **סודות (08-D1):** ההגדרות של חיבור (מפתח, סוד חתימה) נשמרות רק ב-channel_connections.config_enc — מוצפנות במפתח שנגזר
 * מ-data/secret.key. הן לעולם אינן חוזרות ב-API (apr_conn_out מחזיר "מוגדר" ו-4 תווים אחרונים), אינן נכתבות ליומן הפעולות,
 * ליומן הטכני או להודעת שגיאה: last_error הוא קוד טכני (סוג החריגה), לא ההודעה שלה.
 *
 * **ספקים:** בפרוסה הזו אין ספק חיצוני אמיתי. server_mail — מייל דרך השרת (apr_mail_send); relay — קליטה נכנסת ב-webhook חתום
 * (lib/inbound.php), בלי שליחה. מסרון ו-WhatsApp רשומים בקטלוג כ"נדרש חשבון ספק" ואי אפשר ליצור להם חיבור.
 * בבדיקות: $GLOBALS['apr_channel_providers'] מוסיף ספק מדומה (עם 'adapter' => fn(array $conn, array $config): AprChannelAdapter).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/health.php';

/** המתאם האחיד (§6.2). */
interface AprChannelAdapter
{
    /** $m: kind, to, subject, text, idem, office, from_name, reply_to. מחזיר ['status' => sent | failed | unknown, 'ref' => ?string]. חריגה = failed. */
    public function send(array $m): array;

    /** מצב אצל הספק: delivered / sent / failed, או null כשאינו יודע. */
    public function status(string $ref): ?string;

    public function capabilities(): array;

    /** ['ok' | 'warn' | 'bad', הסבר]. */
    public function health(): array;

    /** גוף גולמי של webhook ← ['id', 'from', 'subject', 'text', 'ref', 'files' => [['name', 'data' (בינארי)]]], או null. */
    public function parseInbound(string $raw): ?array;
}

const APR_CAPS_NONE = ['send' => false, 'attach_files' => false, 'delivery_reports' => false, 'inbound' => false, 'free_text' => true];

/** fields: שדות ההגדרה של חיבור — כולם סודיים, כולם מחרוזת באורך min…max. */
const APR_CHANNEL_PROVIDERS = [
    'server_mail' => ['channel' => 'email', 'label' => 'מייל דרך השרת', 'builtin' => true, 'scopes' => ['system'], 'fields' => [],
        'caps' => ['send' => true] + APR_CAPS_NONE],
    'relay' => ['channel' => 'email', 'label' => 'קליטת מייל נכנס (webhook חתום)', 'builtin' => false, 'scopes' => ['system', 'office'],
        'fields' => ['webhook_secret' => [32, 200]], 'caps' => ['inbound' => true] + APR_CAPS_NONE],
];

/** הקטלוג שמוצג (§6.2): מה זמין, ומה ממתין לספק. */
const APR_CHANNEL_CATALOG = [
    ['channel' => 'email', 'state' => 'available'],
    ['channel' => 'sms', 'state' => 'needs_provider'],
    ['channel' => 'whatsapp', 'state' => 'needs_provider'],
    ['channel' => 'storage', 'state' => 'unavailable'],
];

function apr_channel_providers(): array
{
    return APR_CHANNEL_PROVIDERS + ($GLOBALS['apr_channel_providers'] ?? []);
}

// ── סודות ─────────────────────────────────────────────────────

function apr_secret_box(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, hash_hkdf('sha256', apr_secret(), 32, 'apr:channel-config')));
}

function apr_secret_open(?string $box): ?string
{
    $raw = $box === null ? false : base64_decode($box, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), hash_hkdf('sha256', apr_secret(), 32, 'apr:channel-config'));
    return $plain === false ? null : $plain;
}

/** ההגדרות המפוענחות של חיבור — לשימוש המתאם בלבד. אינן יוצאות מהשרת. */
function apr_conn_config(array $conn): array
{
    $c = json_decode((string)apr_secret_open($conn['config_enc']), true);
    return is_array($c) ? $c : [];
}

// ── מתאמים ────────────────────────────────────────────────────

final class AprServerMailAdapter implements AprChannelAdapter
{
    public function send(array $m): array
    {
        return ['status' => apr_mail_send((string)$m['kind'], (string)$m['to'], (string)$m['subject'], (string)$m['text'], (string)$m['idem'], $m['office'] ?? null,
            ['from_name' => $m['from_name'] ?? null, 'reply_to' => $m['reply_to'] ?? null]), 'ref' => null];
    }

    public function status(string $ref): ?string
    {
        return null;                                        // השרת אינו מדווח מסירה
    }

    public function capabilities(): array
    {
        return APR_CHANNEL_PROVIDERS['server_mail']['caps'];
    }

    public function health(): array
    {
        return function_exists('mail') ? ['ok', 'mail() זמין'] : ['bad', 'mail() אינו זמין בשרת'];
    }

    public function parseInbound(string $raw): ?array
    {
        return null;
    }
}

/** קליטה נכנסת בלבד: הודעה ב-JSON שהועברה אלינו (ממסר מייל, או שירות שהבעלים יחבר). הפורמט: lib/inbound.php. */
final class AprRelayAdapter implements AprChannelAdapter
{
    public function send(array $m): array
    {
        throw new LogicException('relay: inbound only');
    }

    public function status(string $ref): ?string
    {
        return null;
    }

    public function capabilities(): array
    {
        return APR_CHANNEL_PROVIDERS['relay']['caps'];
    }

    public function health(): array
    {
        return ['ok', 'ממתין להודעות נכנסות'];
    }

    public function parseInbound(string $raw): ?array
    {
        $j = json_decode($raw, true, 8);
        if (!is_array($j) || !is_string($j['id'] ?? null) || preg_match('/^[\x21-\x7E]{1,200}\z/', $j['id']) !== 1 || !is_string($j['from'] ?? null)) return null;
        $files = [];
        foreach (is_array($j['files'] ?? null) ? $j['files'] : [] as $f) {
            if (!is_array($f) || !is_string($f['data'] ?? null)) return null;
            $bin = base64_decode($f['data'], true);
            if ($bin === false) return null;
            $files[] = ['name' => is_string($f['name'] ?? null) ? $f['name'] : '', 'data' => $bin];
        }
        $str = fn(string $k) => is_string($j[$k] ?? null) ? $j[$k] : '';
        return ['id' => $j['id'], 'from' => $j['from'], 'subject' => $str('subject'), 'text' => $str('text'), 'ref' => $str('ref'), 'files' => $files];
    }
}

function apr_channel_adapter(array $conn): AprChannelAdapter
{
    $p = apr_channel_providers()[$conn['provider']] ?? throw new RuntimeException('ספק לא רשום');
    if (isset($p['adapter'])) return ($p['adapter'])($conn, apr_conn_config($conn));
    return match ($conn['provider']) {
        'server_mail' => new AprServerMailAdapter(),
        'relay' => new AprRelayAdapter(),
    };
}

function apr_conn_caps(array $conn): array
{
    return (json_decode((string)$conn['capabilities'], true) ?: []) + APR_CAPS_NONE;
}

// ── מי רשאי להשתמש בחיבור ─────────────────────────────────────

const APR_CONN_FOR_OFFICE = "((c.scope = 'office' AND c.office_id = ?) OR (c.scope = 'system' AND (c.all_offices = 1 OR EXISTS (SELECT 1 FROM channel_grants g WHERE g.connection_id = c.id AND g.office_id = ?))))";

/** החיבורים שזמינים למשרד: שלו, ושל המערכת שהותרו לו (08-D2). */
function apr_conns_for_office(int $office): array
{
    return apr_rows('SELECT c.* FROM channel_connections c WHERE ' . APR_CONN_FOR_OFFICE . ' ORDER BY c.scope, c.id', [$office, $office]);
}

function apr_conn_serves(array $conn, int $office): bool
{
    return (bool)apr_val('SELECT 1 FROM channel_connections c WHERE c.id = ? AND ' . APR_CONN_FOR_OFFICE, [$conn['id'], $office, $office]);
}

/** האם אפשר לשלוח דרך החיבור הזה, עכשיו, בשם המשרד הזה. */
function apr_conn_can_send(?array $conn, int $office): bool
{
    return $conn !== null && $conn['status'] === 'active' && !empty(apr_conn_caps($conn)['send']) && apr_conn_serves($conn, $office);
}

/** החיבור לשליחה בערוץ: זה שנבחר ($pid), או הראשון הפעיל שזמין למשרד. אין — channel_unavailable (העתקת קישור תמיד זמינה). */
function apr_conn_pick(int $office, string $channel, mixed $pid = null): array
{
    if ($pid !== null) {
        $c = apr_is_pid($pid) ? apr_row('SELECT c.* FROM channel_connections c WHERE c.pid = ? AND c.channel = ? AND ' . APR_CONN_FOR_OFFICE, [$pid, $channel, $office, $office]) : null;
        if (!$c) apr_fail('not_found');
        return apr_conn_can_send($c, $office) ? $c : apr_fail('channel_unavailable');
    }
    foreach (apr_conns_for_office($office) as $c) {
        if ($c['channel'] === $channel && apr_conn_can_send($c, $office)) return $c;
    }
    apr_fail('channel_unavailable');
}

// ── שליחה ─────────────────────────────────────────────────────

/**
 * שולח דרך המתאם, **מחוץ לטרנזקציה**. חריגה מהמתאם = failed (08-B1); תשובה שאינה sent / failed = unknown (08-B3).
 * מחזיר ['status', 'ref', 'error' (קוד טכני)]. רושם את התוצאה על החיבור; 3 כשלים רצופים — החיבור עובר ל-failed (§6.3).
 */
function apr_channel_send(array $conn, array $m): array
{
    $ref = $error = null;
    try {
        $r = apr_channel_adapter($conn)->send($m);
        $status = in_array($r['status'] ?? null, ['sent', 'failed'], true) ? $r['status'] : 'unknown';
        $ref = is_string($r['ref'] ?? null) ? mb_substr($r['ref'], 0, 200) : null;
        if ($status === 'failed') $error = 'rejected';
    } catch (Throwable $e) {
        $status = 'failed';
        $error = get_class($e);                             // בלי הודעת החריגה — עלולה לכלול יעד או מפתח
    }
    apr_conn_result((int)$conn['id'], $status, $error);
    return ['status' => $status, 'ref' => $ref, 'error' => $error];
}

function apr_conn_result(int $id, string $status, ?string $error): void
{
    if ($status === 'unknown') return;
    apr_tx(function () use ($id, $status, $error) {
        if ($status === 'sent') {
            apr_exec('UPDATE channel_connections SET fail_count = 0, last_ok_at = ?, last_error = NULL WHERE id = ?', [apr_now(), $id]);
            return;
        }
        apr_exec('UPDATE channel_connections SET fail_count = fail_count + 1, last_error = ? WHERE id = ?', [$error, $id]);
        if (apr_exec("UPDATE channel_connections SET status = 'failed' WHERE id = ? AND status = 'active' AND fail_count >= 3", [$id]) === 1) {
            $c = apr_row('SELECT pid, office_id FROM channel_connections WHERE id = ?', [$id]);
            apr_audit('channel.failed', 'channel', $c['pid'], ['error' => $error], null, $c['office_id'] === null ? null : (int)$c['office_id'], null, 'system');
        }
    });
}

/** הודעה לערוץ שאינו נושא טקסט חופשי או מוגבל באורך (מסרון): נוסח קצר, והקישור **תמיד שלם** — בסוף. */
function apr_channel_short_text(string $text, string $link, int $max): string
{
    $text = trim((string)preg_replace('/\s+/u', ' ', str_replace($link, '', $text)));
    $room = max(0, $max - mb_strlen($link) - 1);
    if (mb_strlen($text) > $room) $text = rtrim(mb_substr($text, 0, max(0, $room - 1))) . '…';
    return trim($text . ' ' . $link);
}

// ── תצוגה וניהול ──────────────────────────────────────────────

/** חיבור כפי שהוא יוצא ב-API: בלי config_enc, ובלי שום ערך מתוכו — רק "מוגדר" ו-4 תווים אחרונים. */
function apr_conn_out(array $c): array
{
    return ['pid' => $c['pid'], 'scope' => $c['scope'], 'channel' => $c['channel'], 'provider' => $c['provider'], 'label' => $c['label'], 'status' => $c['status'],
        'capabilities' => apr_conn_caps($c), 'secret' => ['set' => $c['config_enc'] !== null, 'tail' => $c['config_enc'] !== null ? $c['secret_tail'] : null],
        'builtin' => !empty(apr_channel_providers()[$c['provider']]['builtin']), 'all_offices' => (bool)$c['all_offices'],
        'last_ok_at' => $c['last_ok_at'], 'last_error' => $c['last_error'], 'created_at' => $c['created_at']];
}

function apr_channel_catalog(): array
{
    $providers = [];
    foreach (apr_channel_providers() as $key => $p) {
        if (!empty($p['builtin'])) continue;
        $providers[] = ['provider' => $key, 'channel' => $p['channel'], 'label' => $p['label'], 'scopes' => $p['scopes'], 'fields' => array_keys($p['fields']), 'capabilities' => $p['caps'] + APR_CAPS_NONE];
    }
    return ['channels' => APR_CHANNEL_CATALOG, 'providers' => $providers];
}

/** החיבור לפי pid — של המשרד ($office), או של המערכת ($office = null). חיבור של משרד אחר, או של המסלול האחר: לא נמצא. */
function apr_conn_row(?int $office, mixed $pid): array
{
    $c = !apr_is_pid($pid) ? null : ($office === null ? apr_row("SELECT * FROM channel_connections WHERE pid = ? AND scope = 'system'", [$pid])
        : apr_row("SELECT * FROM channel_connections WHERE pid = ? AND scope = 'office' AND office_id = ?", [$pid, $office]));
    return $c ?? apr_fail('not_found');
}

/** מנקה את שדות ההגדרה שנשלחו. שדה שלא נשלח נשאר כפי שהיה. ערך שאינו מחרוזת באורך המותר, או עם תווי בקרה — channel_config. */
function apr_conn_fields(array $provider, mixed $in, array $old): array
{
    $out = $old;
    foreach ($provider['fields'] as $name => [$min, $max]) {
        $v = is_array($in) ? ($in[$name] ?? null) : null;
        if ($v === null || $v === '') continue;
        if (!is_string($v) || strlen($v) < $min || strlen($v) > $max || preg_match('/[\x00-\x1F\x7F]/', $v) === 1) apr_fail('channel_config');
        $out[$name] = $v;
    }
    return array_intersect_key($out, $provider['fields']);
}

/** יצירה או עדכון של חיבור. $office — של המשרד; null — של המערכת. {connection?, provider, label, config{}}. */
function apr_channel_save(?int $office, int $uid, array $in): array
{
    $label = apr_clean_name($in['label'] ?? null, 80);
    return apr_tx(function () use ($office, $uid, $in, $label) {
        $old = ($in['connection'] ?? null) !== null ? apr_conn_row($office, $in['connection']) : null;
        $key = $old['provider'] ?? (is_string($in['provider'] ?? null) ? $in['provider'] : '');
        $p = apr_channel_providers()[$key] ?? apr_fail('channel_config');
        if (!$old && (!empty($p['builtin']) || !in_array($office === null ? 'system' : 'office', $p['scopes'], true))) apr_fail('channel_config');
        $config = apr_conn_fields($p, $in['config'] ?? null, $old ? apr_conn_config($old) : []);
        $complete = count($config) === count($p['fields']);
        $first = $config ? (string)reset($config) : '';
        $set = ['label' => $label, 'config_enc' => $config ? apr_secret_box((string)json_encode($config)) : null, 'secret_tail' => $first !== '' ? substr($first, -4) : null];
        if ($old) {
            // חיבור שהיה "דורש חיבור" והושלם — פעיל; מושבת או שנכשל נשאר כך עד שמפעילים אותו במפורש
            $status = $old['status'] === 'needs_auth' && $complete ? 'active' : (!$complete ? 'needs_auth' : $old['status']);
            apr_exec('UPDATE channel_connections SET label = ?, config_enc = ?, secret_tail = ?, status = ? WHERE id = ?', [$set['label'], $set['config_enc'], $set['secret_tail'], $status, $old['id']]);
            $pid = $old['pid'];
        } else {
            $pid = apr_pid();
            apr_insert('channel_connections', $set + ['pid' => $pid, 'scope' => $office === null ? 'system' : 'office', 'office_id' => $office, 'channel' => $p['channel'], 'provider' => $key,
                'status' => $complete ? 'active' : 'needs_auth', 'capabilities' => json_encode($p['caps'] + APR_CAPS_NONE), 'created_by' => $uid, 'created_at' => apr_now()]);
        }
        // ביומן: מה השתנה — לעולם לא ההגדרות עצמן
        apr_audit($old ? 'channel.update' : 'channel.create', 'channel', $pid, ['provider' => $key, 'configured' => $complete], null, $office);
        return ['connection' => apr_conn_out(apr_row('SELECT * FROM channel_connections WHERE pid = ?', [$pid]))];
    });
}

/** הפעלה או השבתה. הפעלה — רק כשההגדרות שלמות; מאפסת את מונה הכשלים. השבתה עוצרת את התוכניות שנשענות על החיבור. */
function apr_channel_status(?int $office, array $in): array
{
    $to = in_array($in['status'] ?? null, ['active', 'disabled'], true) ? $in['status'] : apr_fail('content_invalid');
    return apr_tx(function () use ($office, $in, $to) {
        $c = apr_conn_row($office, $in['connection'] ?? null);
        $p = apr_channel_providers()[$c['provider']] ?? apr_fail('channel_config');
        if ($to === 'active' && count(apr_conn_config($c)) !== count($p['fields'])) apr_fail('channel_config');
        if (apr_exec('UPDATE channel_connections SET status = ?, fail_count = 0 WHERE id = ? AND status <> ?', [$to, $c['id'], $to]) !== 1) apr_fail('channel_state');
        apr_audit('channel.status', 'channel', $c['pid'], ['from' => $c['status'], 'to' => $to], null, $office);
        if ($to === 'disabled') apr_plans_stop_for_conn((int)$c['id'], null, 'connection_unavailable');
        return ['status' => $to];
    });
}

/** "בדיקה": health() של המתאם. הודעת חריגה אינה חוזרת — רק סוג התקלה. */
function apr_channel_test(?int $office, array $in): array
{
    $c = apr_conn_row($office, $in['connection'] ?? null);
    try {
        [$state, $text] = apr_channel_adapter($c)->health();
    } catch (Throwable $e) {
        [$state, $text] = ['bad', get_class($e)];
    }
    return ['health' => ['state' => in_array($state, ['ok', 'warn', 'bad'], true) ? $state : 'bad', 'text' => mb_substr((string)$text, 0, 200)]];
}

// ── המסכים: משרד ──────────────────────────────────────────────

function apr_office_channels(array $ctx): array
{
    $office = apr_office_id($ctx);
    $handlers = apr_rows("SELECT u.pid, u.name, m.inbox_handler, m.is_admin FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.office_id = ? AND m.status = 'active' AND m.deleted_at IS NULL ORDER BY u.name", [$office]);
    return ['connections' => array_map('apr_conn_out', apr_conns_for_office($office)), 'catalog' => apr_channel_catalog(),
        'members' => array_map(fn($m) => ['pid' => $m['pid'], 'name' => $m['name'], 'is_admin' => (bool)$m['is_admin'], 'inbox_handler' => (bool)$m['inbox_handler']], $handlers)];
}

/** "מטפל בתור": מנהל המשרד מסמיך חבר לראות את תור השיוך. */
function apr_office_inbox_handler(array $ctx, array $in): array
{
    $office = apr_office_id($ctx);
    $on = ($in['on'] ?? null) === true;
    return apr_tx(function () use ($ctx, $office, $in, $on) {
        $u = apr_is_pid($in['user'] ?? null) ? apr_row("SELECT u.id, u.pid FROM memberships m JOIN users u ON u.id = m.user_id WHERE u.pid = ? AND m.office_id = ? AND m.status = 'active' AND m.deleted_at IS NULL", [$in['user'], $office]) : null;
        if (!$u) apr_fail('not_found');
        apr_exec('UPDATE memberships SET inbox_handler = ? WHERE office_id = ? AND user_id = ?', [(int)$on, $office, $u['id']]);
        apr_audit('office.inbox_handler', 'user', $u['pid'], ['on' => $on], null, $office);
        return ['inbox_handler' => $on];
    });
}

// ── המסכים: מערכת ─────────────────────────────────────────────

function apr_sys_channels(): array
{
    $grants = [];
    foreach (apr_rows('SELECT g.connection_id, o.pid FROM channel_grants g JOIN offices o ON o.id = g.office_id') as $g) $grants[$g['connection_id']][] = $g['pid'];
    return ['catalog' => apr_channel_catalog(),
        'connections' => array_map(fn($c) => apr_conn_out($c) + ['offices' => $grants[$c['id']] ?? []], apr_rows("SELECT * FROM channel_connections WHERE scope = 'system' ORDER BY id")),
        'offices' => array_map(fn($o) => ['pid' => $o['pid'], 'name' => $o['name']], apr_rows("SELECT pid, name FROM offices WHERE status = 'active' ORDER BY name"))];
}

/** הקצאת חיבור מערכת: {connection, all_offices: bool} או {connection, office, on: bool}. שלילה עוצרת את התוכניות של המשרד בחיבור הזה (מקרה קצה 8). */
function apr_sys_channel_grant(array $in): array
{
    return apr_tx(function () use ($in) {
        $c = apr_conn_row(null, $in['connection'] ?? null);
        if (array_key_exists('all_offices', $in)) {
            $all = $in['all_offices'] === true;
            apr_exec('UPDATE channel_connections SET all_offices = ? WHERE id = ?', [(int)$all, $c['id']]);
            apr_audit('channel.grant', 'channel', $c['pid'], ['all_offices' => $all]);
            if (!$all) {
                foreach (apr_rows("SELECT DISTINCT q.office_id FROM reminder_plans p JOIN requests q ON q.id = p.request_id WHERE p.connection_id = ? AND p.state = 'active'", [$c['id']]) as $o) {
                    if (!apr_conn_serves($c, (int)$o['office_id'])) apr_plans_stop_for_conn((int)$c['id'], (int)$o['office_id'], 'connection_unavailable');
                }
            }
            return ['all_offices' => $all];
        }
        $o = apr_is_pid($in['office'] ?? null) ? apr_row('SELECT id, pid FROM offices WHERE pid = ?', [$in['office']]) : null;
        if (!$o) apr_fail('not_found');
        $on = ($in['on'] ?? null) === true;
        if ($on) apr_exec('INSERT OR IGNORE INTO channel_grants (connection_id, office_id) VALUES (?, ?)', [$c['id'], $o['id']]);
        else apr_exec('DELETE FROM channel_grants WHERE connection_id = ? AND office_id = ?', [$c['id'], $o['id']]);
        apr_audit('channel.grant', 'channel', $c['pid'], ['office' => $o['pid'], 'on' => $on]);
        if (!$on && !apr_conn_serves($c, (int)$o['id'])) apr_plans_stop_for_conn((int)$c['id'], (int)$o['id'], 'connection_unavailable');
        return ['on' => $on];
    });
}

// לוח החגים זרוע מראש (מיגרציה 008). כשהוא עומד להיגמר — חג ייחשב יום רגיל, בשקט; לכן מזהירים 90 יום לפני
apr_health_register('holidays', 'לוח החגים של התזכורות', function () {
    $last = apr_val('SELECT MAX(day) FROM holidays');
    if (!$last) return ['bad', 'לוח החגים ריק — תזכורות יישלחו גם בחג'];
    $days = intdiv(strtotime($last . 'T00:00:00Z') - apr_time(), 86400);
    return $days < 90 ? ['warn', $days < 0 ? "לוח החגים הסתיים ב-$last — תזכורות נשלחות גם בחג" : "לוח החגים מסתיים ב-$last (בעוד $days ימים) — יש להאריך אותו"] : ['ok', "זרוע עד $last"];
});

apr_health_register('channels', 'ערוצים וחיבורים', function () {
    $bad = [];
    $n = 0;
    foreach (apr_rows("SELECT * FROM channel_connections WHERE status IN ('active', 'failed')") as $c) {
        $n++;
        try {
            $state = $c['status'] === 'failed' ? 'bad' : apr_channel_adapter($c)->health()[0];
        } catch (Throwable) {
            $state = 'bad';
        }
        if ($state !== 'ok') $bad[] = $c['label'];
    }
    return $bad ? ['warn', 'חיבורים שאינם תקינים: ' . implode(', ', $bad)] : ['ok', "$n חיבורים פעילים, כולם תקינים"];
});
