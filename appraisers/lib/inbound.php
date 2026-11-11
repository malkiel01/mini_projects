<?php
/**
 * קליטה נכנסת (אפיון 08 §7): ה-webhook הציבורי, השיוך האוטומטי, ותור השיוך הידני.
 *
 * **ה-webhook** — api.php?action=inbound-relay&c=<מזהה החיבור>, POST, גוף JSON, והכותרת
 *   X-Relay-Signature: t=<שניות unix>,v1=<hex של HMAC-SHA256(סוד החיבור, "<t>.<מזהה החיבור>.<הגוף הגולמי>")>
 * מזהה החיבור בתוך המחרוזת הנחתמת: חתימה שנוצרה לחיבור אחד אינה תקפה לחיבור אחר, גם אם שניהם הוגדרו עם אותו סוד.
 * הסדר: הגבלת קצב לפי IP ← תקרת גודל (לפי Content-Length, לפני הקריאה) ← חתימה על **הגוף הגולמי**, בזמן קבוע, לפני כל פענוח ←
 * חותמת זמן (±5 דקות) ← הגבלת קצב לחיבור ← פענוח ← מזהה ההודעה (UNIQUE לחיבור: שידור חוזר אינו נרשם שוב).
 * חיבור שאינו קיים, מושבת, מספק אחר, בלי סוד, חתימה שגויה, חותמת ישנה או גוף גדול מדי — **אותה תשובה: 404**, בלי להבחין.
 * הגוף: {id, from, subject?, text?, ref?, files?: [{name, data (base64)}]}.
 *
 * **שום דבר מהגוף אינו מגיע לנתיב בדיסק** (הקבצים נשמרים תחת מפתח אקראי), ל-SQL בשרשור, או לכותרות מייל.
 * קובץ נכנס עובר את אותו סינון של העלאה (apr_file_sniff, apr_file_scan), נכנס לתיק רק דרך apr_file_ingest, ולעולם אינו מאושר לבד.
 *
 * **שיוך אוטומטי — רק כשהוא ודאי:** מזהה שיוך תקף + השולח הוא הנמען של אותה בקשה + הבקשה פעילה. כל מקרה אחר — לתור
 * (inbound_items), ו**אפס שורות בתיק** עד אישור אנושי. בחיבור מערכת: שולח שתואם ליותר ממשרד אחד, או לאף אחד — נשאר אצל מנהל
 * המערכת, שרואה ערוץ, זמן ושולח מקוצר בלבד.
 */

declare(strict_types=1);

require_once __DIR__ . '/reminders.php';
require_once __DIR__ . '/documents.php';

const APR_INBOUND_MAX = 12 * 1024 * 1024;
const APR_INBOUND_SKEW = 300;
const APR_INBOUND_IP = [120, 60];                  // בקשות לדקה מ-IP אחד
const APR_INBOUND_BAD = [20, 600];                 // בקשות שנדחו מ-IP אחד
const APR_INBOUND_CONN = [300, 3600];              // הודעות לשעה לחיבור
const APR_INBOUND_FILES = 5;
const APR_INBOUND_TEXT = 20000;
const APR_INBOUND_QUEUE = 500;                     // פריטים פתוחים לחיבור; מעבר לכך — הפריט נרשם בלי קבצים
const APR_STOP_WORDS = ['הסר', 'הסירו', 'הסירו אותי', 'הפסק', 'להפסיק', 'stop', 'unsubscribe'];

function apr_inbound_dir(): string
{
    return apr_data_dir() . '/inbound';
}

/** הנתיב של קובץ בהסגר — לפי מפתח שנוצר בשרת בלבד. */
function apr_inbound_path(string $key): string
{
    if (preg_match('/^[a-f0-9]{32}\z/', $key) !== 1) apr_fail('not_found');
    return apr_inbound_dir() . '/' . $key;
}

/** ה-webhook. $env: ip, signature (X-Relay-Signature), raw_reader (fn(int $max): ?string — null כשהגוף גדול מדי). */
function apr_inbound_hook(string $provider, array $get, array $env): array
{
    $ipKey = apr_portal_ip_key($env);
    if (!apr_limit('inbound:i:' . $ipKey, ...APR_INBOUND_IP)) apr_fail('inbound_rate');
    $deny = function () use ($ipKey): never {
        if (!apr_limit('inbound:bad:' . $ipKey, ...APR_INBOUND_BAD)) apr_fail('inbound_rate');
        apr_fail('not_found');
    };
    $pid = $get['c'] ?? null;
    $conn = apr_is_pid($pid) ? apr_row("SELECT * FROM channel_connections WHERE pid = ? AND provider = ? AND status = 'active'", [$pid, $provider]) : null;
    $secret = $conn ? (apr_conn_config($conn)['webhook_secret'] ?? null) : null;
    if (!is_string($secret) || strlen($secret) < 32) $secret = null;
    // הגוף נקרא גם כשאין חיבור — אותו מסלול ואותו זמן לחיבור קיים ולחיבור שאינו קיים
    $raw = ($env['raw_reader'] ?? fn() => null)(APR_INBOUND_MAX);
    $ok = is_string($raw) && preg_match('/^t=(\d{9,11}),v1=([0-9a-f]{64})\z/', (string)($env['signature'] ?? ''), $s) === 1;
    $calc = hash_hmac('sha256', ($ok ? $s[1] : '0') . '.' . ($conn['pid'] ?? '-') . '.' . ($ok ? $raw : ''), $secret ?? ('none:' . apr_secret()));
    if (!$ok || !hash_equals($calc, $s[2]) || $secret === null) $deny();
    if (abs(apr_time() - (int)$s[1]) > APR_INBOUND_SKEW) $deny();
    if (!apr_limit('inbound:c:' . $conn['id'], ...APR_INBOUND_CONN)) apr_fail('inbound_rate');
    $msg = apr_channel_adapter($conn)->parseInbound($raw) ?? apr_fail('inbound_invalid');
    unset($raw);
    return apr_inbound_receive($conn, $msg);
}

/** השולח, מנורמל לערוץ: [כתובת להשוואה (או null כשאינה תקינה), מחרוזת להצגה]. */
function apr_inbound_from(string $channel, string $from): array
{
    $from = trim((string)preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $from));
    if ($channel === 'email') {
        if (preg_match('/<([^<>\s]+)>\s*\z/', $from, $m) === 1) $from = $m[1];
        $addr = mb_strtolower($from);
        return [strlen($addr) <= 200 && filter_var($addr, FILTER_VALIDATE_EMAIL) ? $addr : null, mb_substr($from, 0, 200)];
    }
    $digits = (string)preg_replace('/\D+/', '', $from);
    return [strlen($digits) >= 7 && strlen($digits) <= 15 ? $digits : null, mb_substr($from, 0, 40)];
}

/** שולח מקוצר — מה שמנהל המערכת רואה. */
function apr_inbound_mask(string $display): string
{
    $at = strrpos($display, '@');
    return $at === false ? mb_substr($display, 0, 3) . '…' . mb_substr($display, -2) : mb_substr($display, 0, min(2, $at)) . '…' . mb_substr($display, $at);
}

/** תיקים פתוחים במשרד שיש בהם גורם עם הכתובת הזו. */
function apr_inbound_cases(int $office, string $channel, string $addr): array
{
    $match = $channel === 'email' ? 'LOWER(ct.email) = ?' : "REPLACE(REPLACE(REPLACE(REPLACE(ct.phone, '-', ''), ' ', ''), '+', ''), '.', '') = ?";
    return array_map('intval', array_column(apr_rows("SELECT DISTINCT c.id FROM cases c JOIN case_parties cp ON cp.case_id = c.id AND cp.removed_at IS NULL JOIN contacts ct ON ct.id = cp.contact_id AND ct.deleted_at IS NULL
        WHERE c.office_id = ? AND c.deleted_at IS NULL AND c.status <> 'closed' AND $match ORDER BY c.id DESC LIMIT 20", [$office, $addr]), 'id'));
}

/** המשרדים (הפעילים, שהחיבור הזה מותר להם) שהשולח תואם לגורם בתיק פתוח שלהם. */
function apr_inbound_offices(array $conn, string $channel, ?string $addr): array
{
    if ($addr === null) return [];
    $out = [];
    foreach (apr_rows("SELECT id FROM offices WHERE status = 'active' ORDER BY id") as $o) {
        if (apr_conn_serves($conn, (int)$o['id']) && apr_inbound_cases((int)$o['id'], $channel, $addr)) $out[] = (int)$o['id'];
    }
    return $out;
}

/** קבצים נכנסים ← הסגר: סינון וסריקה כמו בהעלאה; מה שנדחה נרשם עם הסיבה, בלי הקובץ (מקרה קצה 11). */
function apr_inbound_quarantine(array $files): array
{
    $out = [];
    foreach (array_slice($files, 0, APR_INBOUND_FILES) as $f) {
        $name = apr_file_clean_name($f['name']);
        $size = strlen($f['data']);
        if ($size === 0 || $size > apr_file_max()) {
            $out[] = ['name' => $name, 'rejected' => 'file_too_large'];
            continue;
        }
        $key = bin2hex(random_bytes(16));
        if (!is_dir(apr_inbound_dir())) @mkdir(apr_inbound_dir(), 0700, true);
        $path = apr_inbound_path($key);
        if (file_put_contents($path, $f['data']) !== $size) {
            @unlink($path);
            $out[] = ['name' => $name, 'rejected' => 'storage_full'];
            continue;
        }
        @chmod($path, 0600);
        try {
            $info = apr_file_sniff($path);
            if (apr_file_scan($path) === 'rejected') throw new AprFileRejected('file_infected', 'scanner');
            $out[] = ['key' => $key, 'name' => $name, 'size' => $size, 'mime' => $info['mime']];
        } catch (AprError $e) {
            @unlink($path);
            $out[] = ['name' => $name, 'rejected' => $e->errorCode];
        }
    }
    if (count($files) > APR_INBOUND_FILES) $out[] = ['name' => '', 'rejected' => 'inbound_too_many'];
    return $out;
}

function apr_inbound_drop(array $files): void
{
    foreach ($files as $f) {
        if (isset($f['key'])) @unlink(apr_inbound_path($f['key']));
    }
}

/** הודעה שחתימתה אומתה: רישום חד-פעמי, ואז — לתיק (שיוך ודאי) או לתור. */
function apr_inbound_receive(array $conn, array $msg): array
{
    $channel = $conn['channel'];
    [$addr, $display] = apr_inbound_from($channel, $msg['from']);
    $hash = apr_addr_hash($channel, $addr ?? ('?' . $display));
    $subject = mb_substr(trim((string)preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $msg['subject'])), 0, 300);
    $text = mb_substr((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\r", '', mb_scrub($msg['text'], 'UTF-8'))), 0, APR_INBOUND_TEXT);
    // מזהה השיוך: מהשדה ref (כתובת ה-Reply-To), או מתוך הנושא והגוף
    $ref = preg_match('/APR-[0-9A-F]{20}/', $msg['ref'] . ' ' . $subject . ' ' . $text, $m) === 1 ? $m[0] : null;
    $msgKey = hash('sha256', $msg['id']);
    if (apr_val('SELECT 1 FROM inbound_receipts WHERE connection_id = ? AND message_id = ?', [$conn['id'], $msgKey])) return ['received' => true, 'repeat' => true];
    $files = apr_inbound_quarantine($msg['files']);
    $ingest = null;
    try {
        $out = apr_tx(function () use ($conn, $channel, $addr, $display, $hash, $subject, $text, $ref, $msgKey, &$files, &$ingest) {
            try {
                apr_insert('inbound_receipts', ['connection_id' => $conn['id'], 'message_id' => $msgKey, 'received_at' => apr_now()]);
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'UNIQUE')) return ['received' => true, 'repeat' => true];
                throw $e;
            }
            // ── שיוך ודאי? ──
            $why = 'no_ref';
            $q = $ref === null ? null : apr_row('SELECT q.*, c.pid AS case_pid, c.status AS case_status, c.deleted_at AS case_deleted, o.status AS office_status,
                    cp.removed_at AS party_removed, ct.email AS contact_email, ct.phone AS contact_phone, ct.deleted_at AS contact_deleted
                FROM requests q JOIN cases c ON c.id = q.case_id JOIN offices o ON o.id = q.office_id JOIN case_parties cp ON cp.id = q.party_id JOIN contacts ct ON ct.id = cp.contact_id
                WHERE q.ref_hash = ?', [apr_request_ref_hash($ref)]);
            if ($q && !apr_conn_serves($conn, (int)$q['office_id'])) $q = null;          // מזהה של משרד שהחיבור אינו שלו — כאילו אין מזהה
            if ($q) {
                $to = apr_contact_addr($q, $channel);
                $why = match (true) {
                    $addr === null || $to === null || !hash_equals(apr_addr_hash($channel, $to), $hash) => 'sender_mismatch',
                    $q['status'] !== 'active' || $q['expires_at'] <= apr_now() || $q['case_status'] === 'closed' || $q['case_deleted'] !== null
                        || $q['party_removed'] !== null || $q['contact_deleted'] !== null || $q['office_status'] !== 'active' => 'request_inactive',
                    default => null,
                };
            }
            if ($why === null) {
                $c = ['id' => (int)$q['case_id'], 'pid' => $q['case_pid'], 'office_id' => (int)$q['office_id'], 'status' => $q['case_status']];
                $pid = apr_pid();
                apr_insert('messages', ['pid' => $pid, 'office_id' => $q['office_id'], 'case_id' => $q['case_id'], 'request_id' => $q['id'], 'party_id' => $q['party_id'], 'direction' => 'in',
                    'channel' => $channel, 'connection_id' => $conn['id'], 'kind' => 'inbound', 'to_hash' => $hash, 'status' => 'received', 'subject' => $subject, 'body' => $text,
                    'idempotency_key' => 'in:' . $conn['id'] . ':' . $msgKey, 'created_at' => apr_now(), 'sent_at' => apr_now()]);
                apr_request_answered((int)$q['id']);
                $stop = in_array(mb_strtolower(trim($text, " \t\n.!")), APR_STOP_WORDS, true);
                if ($stop) {
                    // הנמען ביקש להפסיק (מקרה קצה 6): התוכנית נעצרת, השמאי מקבל משימה, ואין שליחה אוטומטית ליעד הזה עד שישלח בעצמו
                    apr_exec('INSERT OR IGNORE INTO channel_optouts (office_id, channel, to_hash, created_at) VALUES (?, ?, ?, ?)', [$q['office_id'], $channel, $hash, apr_now()]);
                    apr_exec("UPDATE reminder_plans SET state = 'stopped', stopped_reason = 'recipient_opted_out', next_at = NULL WHERE request_id = ? AND state = 'active'", [$q['id']]);
                    apr_case_system_task($c, 'הנמען ביקש להפסיק לקבל הודעות — לבדוק מולו', 'optout:' . $q['pid'], 'request', $q['pid']);
                }
                apr_case_audit($c, 'inbound.received', 'request', $q['pid'], ['channel' => $channel, 'files' => count(array_filter($files, fn($f) => isset($f['key']))), 'stop' => $stop], null, null, 'recipient');
                apr_case_touch($c);
                $ingest = [$c, $pid];
                return ['received' => true];
            }
            // ── לתור ──
            $office = $conn['office_id'] !== null ? (int)$conn['office_id'] : null;
            if ($office === null) {
                // חיבור מערכת: למשרד — רק כשהשולח תואם לגורם במשרד אחד בלבד
                $offices = apr_inbound_offices($conn, $channel, $addr);
                $office = count($offices) === 1 ? $offices[0] : null;
                if ($office === null) $why = $offices ? 'shared' : 'unknown_sender';
            }
            $candidates = $office !== null && $addr !== null ? apr_inbound_cases($office, $channel, $addr) : [];
            if ((int)apr_val("SELECT COUNT(*) FROM inbound_items WHERE connection_id = ? AND status = 'unassigned'", [$conn['id']]) >= APR_INBOUND_QUEUE) {
                apr_inbound_drop($files);
                $files = [['name' => '', 'rejected' => 'inbound_queue_full']];
            }
            apr_insert('inbound_items', ['pid' => apr_pid(), 'connection_id' => $conn['id'], 'office_id' => $office, 'channel' => $channel, 'from_hash' => $hash, 'from_display' => $display,
                'received_at' => apr_now(), 'subject' => $subject, 'text' => $text, 'file_ids' => json_encode($files, JSON_UNESCAPED_UNICODE), 'candidates' => json_encode($candidates), 'why' => $why]);
            return ['received' => true];
        });
    } catch (Throwable $e) {
        apr_inbound_drop($files);
        throw $e;
    }
    if (!empty($out['repeat'])) apr_inbound_drop($files);
    if ($ingest !== null) apr_inbound_ingest($ingest[0], $files, 'in:' . $ingest[1], $channel, [null, 'recipient'], null);
    return $out;
}

/**
 * קבצים מההסגר אל התיק — דרך apr_file_ingest: אותן מכסות, אותו סינון (שוב), ו"ממתין": בלי דרישה הם ב"קבצים שלא שויכו";
 * עם דרישה — ממתינים לבדיקה. לעולם אינם מאושרים כאן. מחזיר את הרשימה המעודכנת (file = pid, או rejected = קוד).
 */
function apr_inbound_ingest(array $c, array $files, string $keyBase, string $channel, array $audit, ?string $requirement, ?int $uid = null): array
{
    foreach ($files as $i => $f) {
        if (!isset($f['key'])) continue;
        try {
            $r = apr_file_ingest(fn() => $c, ['kind' => 'system', 'uid' => $uid, 'channel' => $channel === 'email' ? 'email' : 'other', 'rate_key' => 'inbound:' . $c['id'], 'rate' => [200, 3600],
                'key' => "$keyBase:$i", 'audit' => $audit], ['name' => $f['name'], 'dup' => '1'] + ($requirement !== null ? ['requirement' => $requirement] : []), ['upload' => apr_inbound_path($f['key'])]);
            $files[$i] = ['name' => $f['name'], 'file' => $r['file']];
        } catch (AprError $e) {
            @unlink(apr_inbound_path($f['key']));
            $files[$i] = ['name' => $f['name'], 'rejected' => $e->errorCode];
        }
    }
    return $files;
}

// ── תור השיוך של המשרד ────────────────────────────────────────

/** מי רואה את התור: מנהל המשרד, וחבר שהוסמך ("מטפל בתור"). */
function apr_inbox_allowed(array $ctx): bool
{
    $office = apr_office_id($ctx);
    return (bool)apr_val("SELECT 1 FROM memberships WHERE office_id = ? AND user_id = ? AND status = 'active' AND deleted_at IS NULL AND (is_admin = 1 OR inbox_handler = 1)", [$office, $ctx['user']['id']]);
}

/** ללוח הראשי: כמה פריטים ממתינים — או null למי שאינו רואה את התור. */
function apr_inbox_count(array $ctx): ?int
{
    return apr_inbox_allowed($ctx) ? (int)apr_val("SELECT COUNT(*) FROM inbound_items WHERE office_id = ? AND status = 'unassigned'", [apr_office_id($ctx)]) : null;
}

/** פריט בתור של המשרד שלי, שעוד לא טופל. של משרד אחר, או שאינו קיים — לא נמצא, בלי הבדל. */
function apr_inbox_item(array $ctx, mixed $pid): array
{
    if (!apr_inbox_allowed($ctx)) apr_fail('forbidden');
    $i = apr_is_pid($pid) ? apr_row("SELECT * FROM inbound_items WHERE pid = ? AND office_id = ? AND status = 'unassigned'", [$pid, apr_office_id($ctx)]) : null;
    return $i ?? apr_fail('not_found');
}

function apr_inbox_files_out(array $i): array
{
    return array_map(fn($f) => array_intersect_key($f, array_flip(['name', 'size', 'mime', 'rejected', 'file'])), json_decode($i['file_ids'], true) ?: []);
}

/** התור. candidates — רק תיקים שהצופה משתתף בהם: ההצעה אינה חושפת תיק שאינו שלו. */
function apr_inbox(array $ctx): array
{
    if (!apr_inbox_allowed($ctx)) apr_fail('forbidden');
    $office = apr_office_id($ctx);
    $mine = array_column(apr_rows('SELECT c.id, c.pid, c.number, c.title ' . apr_my_cases_sql('view'), [$ctx['user']['id'], $office]), null, 'id');
    $items = [];
    foreach (apr_rows("SELECT i.*, k.label AS connection FROM inbound_items i JOIN channel_connections k ON k.id = i.connection_id WHERE i.office_id = ? AND i.status = 'unassigned' ORDER BY i.id DESC LIMIT 200", [$office]) as $i) {
        $cand = [];
        foreach (json_decode($i['candidates'], true) ?: [] as $id) {
            if (isset($mine[$id])) $cand[] = ['pid' => $mine[$id]['pid'], 'number' => $mine[$id]['number'], 'title' => $mine[$id]['title']];
        }
        $items[] = ['pid' => $i['pid'], 'channel' => $i['channel'], 'connection' => $i['connection'], 'from' => $i['from_display'], 'received_at' => $i['received_at'], 'subject' => $i['subject'],
            'text' => $i['text'], 'why' => $i['why'], 'files' => apr_inbox_files_out($i), 'candidates' => $cand];
    }
    return ['items' => $items];
}

/** שיוך לתיק (ולדרישה, אם נבחרה): מי שרואה את התור **וגם** יש לו documents באותו תיק. {item, case, requirement?}. */
function apr_inbox_assign(array $ctx, array $in): array
{
    $i = apr_inbox_item($ctx, $in['item'] ?? null);
    $c = apr_case_get($ctx, $in['case'] ?? null, 'documents');
    $reqPid = ($in['requirement'] ?? null) !== null ? apr_req_row($c, $in['requirement'])['pid'] : null;
    $uid = (int)$ctx['user']['id'];
    $msgPid = apr_tx(function () use ($i, $c, $uid) {
        apr_case_still_open($c);
        if (apr_exec("UPDATE inbound_items SET status = 'assigned', assigned_case_id = ?, assigned_by = ?, assigned_at = ? WHERE id = ? AND status = 'unassigned'", [$c['id'], $uid, apr_now(), $i['id']]) !== 1) apr_fail('not_found');
        $pid = apr_pid();
        apr_insert('messages', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'direction' => 'in', 'channel' => $i['channel'], 'connection_id' => $i['connection_id'], 'kind' => 'inbound',
            'to_hash' => $i['from_hash'], 'status' => 'received', 'subject' => $i['subject'], 'body' => $i['text'], 'idempotency_key' => 'inbox:' . $i['pid'], 'created_by' => $uid,
            'created_at' => $i['received_at'], 'sent_at' => $i['received_at']]);
        apr_case_audit($c, 'inbound.assign', 'inbound', $i['pid'], ['channel' => $i['channel']]);
        apr_case_touch($c);
        return $pid;
    });
    $files = apr_inbound_ingest($c, json_decode($i['file_ids'], true) ?: [], 'inbox:' . $i['pid'], $i['channel'], [$uid, 'user'], $reqPid, $uid);
    apr_exec('UPDATE inbound_items SET file_ids = ? WHERE id = ?', [json_encode($files, JSON_UNESCAPED_UNICODE), $i['id']]);
    return ['message' => $msgPid, 'files' => array_map(fn($f) => array_intersect_key($f, array_flip(['name', 'file', 'rejected'])), $files)];
}

function apr_inbound_dismiss_row(array $i, int $uid, string $reason, ?int $office): void
{
    apr_tx(function () use ($i, $uid, $reason, $office) {
        if (apr_exec("UPDATE inbound_items SET status = 'dismissed', dismiss_reason = ?, assigned_by = ?, assigned_at = ?, text = NULL, subject = NULL WHERE id = ? AND status = 'unassigned'", [$reason, $uid, apr_now(), $i['id']]) !== 1) apr_fail('not_found');
        apr_audit('inbound.dismiss', 'inbound', $i['pid'], [], $reason, $office);
    });
    apr_inbound_drop(json_decode($i['file_ids'], true) ?: []);
}

/** דחייה, עם נימוק. התוכן והקבצים נמחקים. */
function apr_inbox_dismiss(array $ctx, array $in): array
{
    $i = apr_inbox_item($ctx, $in['item'] ?? null);
    apr_inbound_dismiss_row($i, (int)$ctx['user']['id'], apr_reason($in['reason'] ?? null), apr_office_id($ctx));
    return [];
}

// ── "לא מזוהה": אצל מנהל המערכת, בלי תוכן ─────────────────────

function apr_sys_inbox_item(mixed $pid): array
{
    $i = apr_is_pid($pid) ? apr_row("SELECT * FROM inbound_items WHERE pid = ? AND office_id IS NULL AND status = 'unassigned'", [$pid]) : null;
    return $i ?? apr_fail('not_found');
}

/** מה שמנהל המערכת רואה: ערוץ, זמן ושולח מקוצר — ולאילו משרדים אפשר לנתב. **בלי נושא, בלי גוף ובלי שמות קבצים.** */
function apr_sys_inbox(): array
{
    $items = [];
    foreach (apr_rows("SELECT i.*, k.label AS connection FROM inbound_items i JOIN channel_connections k ON k.id = i.connection_id WHERE i.office_id IS NULL AND i.status = 'unassigned' ORDER BY i.id DESC LIMIT 200") as $i) {
        $conn = apr_row('SELECT * FROM channel_connections WHERE id = ?', [$i['connection_id']]);
        [$addr] = apr_inbound_from($i['channel'], $i['from_display']);
        $offices = apr_inbound_offices($conn, $i['channel'], $addr);
        $items[] = ['pid' => $i['pid'], 'channel' => $i['channel'], 'connection' => $i['connection'], 'from' => apr_inbound_mask($i['from_display']), 'received_at' => $i['received_at'], 'why' => $i['why'],
            'offices' => $offices ? array_map(fn($o) => ['pid' => $o['pid'], 'name' => $o['name']], apr_rows('SELECT pid, name FROM offices WHERE id IN (' . implode(',', $offices) . ') ORDER BY name')) : [],
            'can_reply' => $i['channel'] === 'email' && $addr !== null && $i['replied_at'] === null];
    }
    return ['items' => $items];
}

function apr_sys_inbox_dismiss(array $ctx, array $in): array
{
    apr_inbound_dismiss_row(apr_sys_inbox_item($in['item'] ?? null), (int)$ctx['user']['id'], apr_reason($in['reason'] ?? null), null);
    return [];
}

/** ניתוב למשרד — רק לאחד מהמשרדים שהשולח תואם לגורם בתיק פתוח שלהם. הפריט עובר לתור של המשרד; מנהל המערכת אינו רואה את תוכנו גם אז. */
function apr_sys_inbox_route(array $in): array
{
    return apr_tx(function () use ($in) {
        $i = apr_sys_inbox_item($in['item'] ?? null);
        $o = apr_is_pid($in['office'] ?? null) ? apr_row("SELECT id, pid FROM offices WHERE pid = ? AND status = 'active'", [$in['office']]) : null;
        $conn = apr_row('SELECT * FROM channel_connections WHERE id = ?', [$i['connection_id']]);
        [$addr] = apr_inbound_from($i['channel'], $i['from_display']);
        if (!$o || !in_array((int)$o['id'], apr_inbound_offices($conn, $i['channel'], $addr), true)) apr_fail('not_found');
        if (apr_exec("UPDATE inbound_items SET office_id = ?, candidates = ? WHERE id = ? AND office_id IS NULL AND status = 'unassigned'",
            [$o['id'], json_encode(apr_inbound_cases((int)$o['id'], $i['channel'], (string)$addr)), $i['id']]) !== 1) apr_fail('not_found');
        apr_audit('inbound.route', 'inbound', $i['pid'], ['office' => $o['pid']]);
        return [];
    });
}

/** תשובה אוטומטית לשולח, בנוסח קבוע — פעם אחת לפריט. הכתובת עברה אימות, והנושא והגוף אינם נושאים דבר מההודעה הנכנסת. */
function apr_sys_inbox_reply(array $in): array
{
    $i = apr_sys_inbox_item($in['item'] ?? null);
    [$addr] = apr_inbound_from($i['channel'], $i['from_display']);
    if ($i['channel'] !== 'email' || $addr === null) apr_fail('channel_unavailable');
    apr_tx(function () use ($i) {
        if (apr_exec('UPDATE inbound_items SET replied_at = ? WHERE id = ? AND replied_at IS NULL', [apr_now(), $i['id']]) !== 1) apr_fail('request_state');
        apr_audit('inbound.reply', 'inbound', $i['pid']);
    });
    $status = apr_mail_send('inbound_reply', $addr, 'לא זוהתה הפנייה', "שלום,\n\nההודעה ששלחתם התקבלה, אך לא ניתן היה לשייך אותה.\nאנא השיבו להודעה המקורית שקיבלתם, או פנו ישירות למשרד.\n\nתודה", 'inbound-reply:' . $i['pid']);
    return ['message' => $status];
}
