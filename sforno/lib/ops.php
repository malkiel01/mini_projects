<?php
/**
 * ניהול תפעולי (אפיון 10): דיווחים מבחוץ, מדדים מצרפיים, פעילות לתמיכה, לוח הבקרה, והניקוי של חשבונות שנמחקו.
 * הגיבויים — lib/backup.php.
 *
 *   דיווח        — report-create (guest): kind content (מזהים בלבד: edition / revision / sid) או contact. נשמרים **רק** השדות
 *                  שברשימה — שום דבר אחר שנשלח לא נכנס למסד (קריטריון 1). "לחזרה אליי" — רק מה שהמשתמש כתב, מוצפן.
 *                  בלי קורא, בלי IP (ה-IP — HMAC בהגבלת הקצב בלבד), בלי המייל של החשבון
 *   מדדים        — metrics_daily: (יום, סוג, מפתח). המפתח הוא **מזהה בלבד** (SF_METRIC_KEY) — אין דרך להכניס טקסט חופשי
 *                  (קריטריון 7). אורח — מזהה אנונימי שהמכשיר מחליף כל יום, נשמר כ-hash ליומיים
 *   פעילות       — reader_activity: ספירות לפי קורא ויום (כניסות, סנכרונים, שגיאות). 90 יום. **בלי תוכן**
 *   ניקוי        — ops.purge (יומי): קורא שמחק את החשבון לפני 30 יום — נמחקים המידע האישי, המכשירים, ה-sessions, האסימונים,
 *                  ה-Push והמופעים (05, 08, 09), והמייל מוחלף (אפשר להירשם שוב). שורת readers נשארת (pid) — היומן מצביע עליה
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/health.php';

const SF_REPORT_CATS = [
    'content' => ['typo' => 'טעות הקלדה או ניקוד', 'wrong' => 'טעות בתוכן', 'missing' => 'חסר או מיותר', 'order' => 'סדר / מנהג', 'other' => 'אחר'],
    'contact' => ['question' => 'שאלה', 'tech' => 'תקלה באפליקציה', 'idea' => 'הצעה', 'other' => 'אחר'],
];
const SF_REPORT_STATUS = ['new' => 'חדש', 'working' => 'בטיפול', 'closed' => 'נסגר'];
const SF_REPORT_BODY = 2000;
const SF_REPORT_CONTACT = 200;
const SF_REPORT_PER_HOUR = 10;              // דיווחים לכתובת IP בשעה
const SF_REPORT_CONTACT_KEEP = 90 * 86400;  // "לחזרה אליי" נמחק 90 יום אחרי הסגירה
const SF_ACTIVITY_KEEP = 90 * 86400;        // פעילות מפורטת (§16 ⏳)
const SF_PURGE_AFTER = 30 * 86400;          // מחיקה מלאה של חשבון (05)
const SF_METRICS_PER_MIN = 120;             // metrics-open לכתובת IP בדקה

/** סוגי המדדים. מפתח: '' · pid · pid:sid (רק [a-z0-9]) */
const SF_METRIC_KINDS = ['open', 'open_sec', 'guest', 'reader', 'login', 'sync', 'sync_err', 'mail_fail', 'report', 'rem_sent', 'rem_fail', 'store_err'];
const SF_METRIC_KEY = '/^[a-z0-9_]{0,40}(:[a-z0-9_]{1,40})?\z/';
const SF_ACTIVITY_KINDS = ['login', 'open', 'sync', 'sync_err'];

function sf_ops_day(int $offset = 0): string
{
    return gmdate('Y-m-d', sf_time() + $offset * 86400);
}

// ── מדדים ───────────────────────────────────────────────────

/** +n למדד של היום. סוג או מפתח לא מוכרים — שגיאת תכנות (לא נכנס טקסט חופשי במקרה). */
function sf_metric_add(string $kind, string $key = '', int $n = 1, ?string $day = null): void
{
    if (!in_array($kind, SF_METRIC_KINDS, true) || !preg_match(SF_METRIC_KEY, $key)) throw new LogicException('מדד לא מוכר');
    if ($n <= 0) return;
    $day ??= sf_ops_day();
    if (sf_exec('UPDATE metrics_daily SET n = n + ? WHERE day = ? AND kind = ? AND mkey = ?', [$n, $day, $kind, $key])) return;
    try {
        sf_insert('metrics_daily', ['day' => $day, 'kind' => $kind, 'mkey' => $key, 'n' => $n]);
    } catch (PDOException) {
        sf_exec('UPDATE metrics_daily SET n = n + ? WHERE day = ? AND kind = ? AND mkey = ?', [$n, $day, $kind, $key]);   // מרוץ: השורה נוצרה בינתיים
    }
}

/** קובע את הערך של יום (סיכום לילי — rem_sent / rem_fail / store_err). */
function sf_metric_set(string $kind, string $key, int $n, string $day): void
{
    if (!in_array($kind, SF_METRIC_KINDS, true) || !preg_match(SF_METRIC_KEY, $key)) throw new LogicException('מדד לא מוכר');
    if (!sf_exec('UPDATE metrics_daily SET n = ? WHERE day = ? AND kind = ? AND mkey = ?', [$n, $day, $kind, $key]) && $n > 0) {
        sf_insert('metrics_daily', ['day' => $day, 'kind' => $kind, 'mkey' => $key, 'n' => $n]);
    }
}

/** "נראה היום" — true בפעם הראשונה ביום עבור ה-hash הזה. */
function sf_metric_seen(string $what): bool
{
    $day = sf_ops_day();
    $h = hash('sha256', $day . '|' . $what);
    if (sf_val('SELECT 1 FROM metrics_seen WHERE h = ?', [$h])) return false;
    try {
        sf_insert('metrics_seen', ['day' => $day, 'h' => $h]);
    } catch (PDOException) {
        return false;
    }
    return true;
}

/** פעילות של קורא היום (תמיכה, §16) — ספירה בלבד. */
function sf_activity_add(int $readerId, string $kind, int $n = 1): void
{
    if (!in_array($kind, SF_ACTIVITY_KINDS, true)) throw new LogicException('פעילות לא מוכרת');
    if ($n <= 0) return;
    $day = sf_ops_day();
    if (sf_exec('UPDATE reader_activity SET n = n + ?, last_at = ? WHERE reader_id = ? AND day = ? AND kind = ?', [$n, sf_now(), $readerId, $day, $kind])) return;
    try {
        sf_insert('reader_activity', ['reader_id' => $readerId, 'day' => $day, 'kind' => $kind, 'n' => $n, 'last_at' => sf_now()]);
    } catch (PDOException) {
        sf_exec('UPDATE reader_activity SET n = n + ?, last_at = ? WHERE reader_id = ? AND day = ? AND kind = ?', [$n, sf_now(), $readerId, $day, $kind]);
    }
}

/** כניסה של קורא (נקרא מ-sf_account_after_login): מדד login + "קורא פעיל היום" + פעילות. */
function sf_ops_login(int $readerId): void
{
    sf_metric_add('login');
    if (sf_metric_seen('r:' . $readerId)) sf_metric_add('reader');
    sf_activity_add($readerId, 'login');
}

/** sync-push הסתיים: פריטים שנדחו (bad / full) הם "שגיאת סנכרון" — במדד ובפעילות של הקורא. */
function sf_ops_sync(int $readerId, array $results): void
{
    $err = count(array_filter($results, fn($r) => in_array($r['status'] ?? '', ['bad', 'full'], true)));
    if (sf_metric_seen('r:' . $readerId)) sf_metric_add('reader');
    sf_metric_add('sync');
    sf_activity_add($readerId, 'sync');
    if ($err) {
        sf_metric_add('sync_err', '', $err);
        sf_activity_add($readerId, 'sync_err', $err);
    }
}

/**
 * metrics-open (guest, POST): הקורא נפתח. {edition, sec?, anon?} — sec: ה-sid של המקטע העליון; anon: מזהה שהמכשיר מחליף כל
 * יום (אורח). **שום טקסט:** edition — מהדורה ציבורית קיימת; sec — sid שקיים בגרסה שפורסמה שלה; anon — רק לבדיקת "נראה היום".
 */
function sf_metrics_open(array $in, array $env): array
{
    $e = sf_read_edition_row($in['edition'] ?? null);
    $sec = $in['sec'] ?? null;
    if ($sec !== null && !(function_exists('sf_mk_is_sid') && sf_mk_is_sid($sec))) sf_fail('bad_input');
    $anon = $in['anon'] ?? null;
    if ($anon !== null && !(is_string($anon) && preg_match('/^[a-z0-9]{16,40}\z/', $anon))) sf_fail('bad_input');
    sf_rate('metrics', sf_reader_ip($env), SF_METRICS_PER_MIN, 60);
    sf_metric_add('open', (string)$e['pid']);
    if ($sec !== null && sf_val("SELECT 1 FROM segments s JOIN revisions r ON r.id = s.revision_id
            WHERE r.edition_id = ? AND s.sid = ? AND r.status IN ('published', 'superseded') LIMIT 1", [$e['id'], $sec])) {
        sf_metric_add('open_sec', $e['pid'] . ':' . $sec);
    }
    $ra = $env['_reader'] ?? null;
    if ($ra) {
        $rid = (int)$ra['reader']['id'];
        if (sf_metric_seen('r:' . $rid)) sf_metric_add('reader');
        sf_activity_add($rid, 'open');
    } elseif ($anon !== null && sf_metric_seen('g:' . $anon)) {
        sf_metric_add('guest');
    }
    return [];
}

// ── דיווחים ─────────────────────────────────────────────────

/** טקסט של משתמש: בלי תווי בקרה (חוץ משורה חדשה), חתוך. ריק → ''. */
function sf_ops_text(mixed $v, int $max): string
{
    if (!is_string($v)) return '';
    $v = trim(preg_replace('/[^\P{C}\n]+/u', '', str_replace("\r\n", "\n", $v)) ?? '');
    return mb_substr($v, 0, $max);
}

/**
 * report-create (guest, POST בלי אסימון — סוג מדיה מדויק ו-Sec-Fetch-Site בנתב). נשמרים רק: kind, category, edition, revision,
 * sid, text, contact. **לא** הקורא, **לא** המייל של החשבון, **לא** שדות אחרים שנשלחו (הערה, שם לתפילה…).
 */
function sf_report_create(array $in, array $env): array
{
    $kind = $in['kind'] ?? null;
    if (!is_string($kind) || !isset(SF_REPORT_CATS[$kind])) sf_fail('bad_input');
    $cat = $in['category'] ?? null;
    if (!is_string($cat) || !isset(SF_REPORT_CATS[$kind][$cat])) sf_fail('bad_input', 'לבחור את סוג הדיווח');
    $body = sf_ops_text($in['text'] ?? null, SF_REPORT_BODY);
    $contact = sf_ops_text($in['contact'] ?? null, SF_REPORT_CONTACT);
    $row = ['edition_pid' => null, 'revision_pid' => null, 'sid' => null];
    if ($kind === 'content') {
        $e = sf_read_edition_row($in['edition'] ?? null);
        $row['edition_pid'] = $e['pid'];
        $rev = $in['revision'] ?? null;
        if ($rev !== null) {
            if (!sf_is_pid($rev)) sf_fail('bad_input');
            $r = sf_row("SELECT id, pid FROM revisions WHERE pid = ? AND edition_id = ? AND status IN ('published', 'superseded')", [$rev, $e['id']]) ?? sf_fail('not_found');
            $row['revision_pid'] = $r['pid'];
        }
        $sid = $in['sid'] ?? null;
        if (!sf_mk_is_sid($sid)) sf_fail('bad_input', 'חסר הקטע');
        if (isset($r) && !sf_val('SELECT 1 FROM segments WHERE revision_id = ? AND sid = ?', [$r['id'], $sid])) sf_fail('not_found');
        $row['sid'] = $sid;
    } elseif ($body === '') {
        sf_fail('bad_input', 'לכתוב את ההודעה');
    }
    sf_rate('report', sf_reader_ip($env), SF_REPORT_PER_HOUR, 3600);
    $pid = sf_pid();
    $now = sf_now();
    sf_tx(function () use ($pid, $kind, $cat, $row, $body, $contact, $now) {
        sf_insert('reports', ['pid' => $pid, 'kind' => $kind, 'category' => $cat] + $row + ['body' => $body,
            'contact' => $contact === '' ? null : sf_seal($contact), 'status' => 'new', 'created_at' => $now, 'updated_at' => $now]);
        sf_metric_add('report', $kind);
        sf_job_add('ops.report_mail', ['report' => $pid], 'report_mail:' . $pid);
    });
    return ['report' => $pid];
}

/** ההגדרות של התמיכה (📦 §24): מייל ווואטסאפ. ריק — אין. */
function sf_ops_support(): array
{
    $c = json_decode((string)sf_meta('ops_support', '{}'), true);
    return ['email' => is_string($c['email'] ?? null) ? $c['email'] : '', 'whatsapp' => is_string($c['whatsapp'] ?? null) ? $c['whatsapp'] : ''];
}

/** read-support (reader): מה שטופס הקשר מציג — הקטגוריות ודרכי הקשר שהוגדרו. */
function sf_read_support(): array
{
    $s = sf_ops_support();
    return ['email' => $s['email'] ?: null, 'whatsapp' => $s['whatsapp'] ?: null, 'categories' => SF_REPORT_CATS];
}

/** dev-ops-set (dev): {email, whatsapp} של התמיכה. */
function sf_dev_ops_set(array $in): array
{
    $email = trim((string)($in['email'] ?? ''));
    $wa = preg_replace('/[\s+\-()]/', '', (string)($in['whatsapp'] ?? '')) ?? '';
    if ($email !== '' && (mb_strlen($email) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) sf_fail('bad_input', 'כתובת מייל לא תקינה');
    if ($wa !== '' && !preg_match('/^[1-9][0-9]{7,14}\z/', $wa)) sf_fail('bad_input', 'מספר וואטסאפ בפורמט בינלאומי, ספרות בלבד (972…)');
    sf_tx(function () use ($email, $wa) {
        sf_meta_set('ops_support', json_encode(['email' => $email, 'whatsapp' => $wa]));
        sf_audit('ops.support_set', null, null, ['email' => $email !== '', 'whatsapp' => $wa !== '']);
    });
    return ['support' => sf_ops_support()];
}

/** המיקום של דיווח, לתצוגה: הכותר, המהדורה, כותרת הקטע (מהגרסה שדווחה, או מהאחרונה). */
function sf_report_where(array $r): ?array
{
    if ($r['edition_pid'] === null) return null;
    $e = sf_row('SELECT e.id, e.pid, e.label, t.name AS t_name FROM editions e JOIN titles t ON t.id = e.title_id WHERE e.pid = ?', [$r['edition_pid']]);
    if (!$e) return ['edition' => $r['edition_pid'], 'revision' => $r['revision_pid'], 'sid' => $r['sid']];
    $rev = $r['revision_pid'] ? sf_row('SELECT id, n FROM revisions WHERE pid = ?', [$r['revision_pid']]) : null;
    $seg = $rev ? sf_row('SELECT kind, title, parent_sid FROM segments WHERE revision_id = ? AND sid = ?', [$rev['id'], $r['sid']]) : null;
    return ['edition' => $e['pid'], 'edition_label' => $e['label'], 'title' => $e['t_name'], 'revision' => $r['revision_pid'], 'revision_n' => $rev ? (int)$rev['n'] : null,
        'sid' => $r['sid'], 'sid_title' => $seg['title'] ?? null,
        'reader_href' => '#/r/' . $e['pid'] . '/' . $r['sid'], 'editor_href' => '#/admin/content/e/' . $e['pid']];
}

function sf_report_out(array $r, bool $full = false): array
{
    $out = ['pid' => $r['pid'], 'kind' => $r['kind'], 'category' => $r['category'], 'category_label' => SF_REPORT_CATS[$r['kind']][$r['category']] ?? $r['category'],
        'status' => $r['status'], 'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'], 'closed_at' => $r['closed_at'],
        'body' => $full ? $r['body'] : mb_substr((string)$r['body'], 0, 140), 'has_contact' => $r['contact'] !== null, 'where' => sf_report_where($r)];
    if ($full) {
        $out['contact'] = $r['contact'] !== null ? sf_unseal((string)$r['contact']) : null;
        $out['staff_note'] = $r['staff_note'];
        $out['notified_at'] = $r['notified_at'];
    }
    return $out;
}

/** reports-list (staff): ?status=new|working|closed|open (ברירת מחדל: open = חדש ובטיפול), ?page */
function sf_reports_list(array $q): array
{
    $st = is_string($q['status'] ?? null) ? $q['status'] : 'open';
    $page = max(0, (int)($q['page'] ?? 0));
    [$where, $args] = match (true) {
        isset(SF_REPORT_STATUS[$st]) => ['status = ?', [$st]],
        $st === 'all' => ['1 = 1', []],
        default => ["status IN ('new', 'working')", []],
    };
    $rows = sf_rows("SELECT * FROM reports WHERE $where ORDER BY CASE status WHEN 'new' THEN 0 WHEN 'working' THEN 1 ELSE 2 END, id DESC LIMIT 51 OFFSET " . ($page * 50), $args);
    $counts = array_map('intval', array_column(sf_rows('SELECT status, COUNT(*) AS n FROM reports GROUP BY status'), 'n', 'status'));
    return ['reports' => array_map('sf_report_out', array_slice($rows, 0, 50)), 'more' => count($rows) > 50, 'counts' => (object)$counts,
        'statuses' => SF_REPORT_STATUS];
}

function sf_report_row(mixed $pid): array
{
    return (sf_is_pid($pid) ? sf_row('SELECT * FROM reports WHERE pid = ?', [$pid]) : null) ?? sf_fail('not_found');
}

/** report-get (staff): הכול, כולל "לחזרה אליי" (מפוענח). */
function sf_report_get(array $q): array
{
    return ['report' => sf_report_out(sf_report_row($q['report'] ?? null), true), 'statuses' => SF_REPORT_STATUS];
}

/** report-set (staff): {report, status?, note?} */
function sf_report_set(array $in): array
{
    return sf_tx(function () use ($in) {
        $r = sf_report_row($in['report'] ?? null);
        $st = $in['status'] ?? $r['status'];
        if (!is_string($st) || !isset(SF_REPORT_STATUS[$st])) sf_fail('bad_input');
        $note = array_key_exists('note', $in) ? sf_ops_text($in['note'], 1000) : (string)$r['staff_note'];
        $closed = $st === 'closed' ? ($r['closed_at'] ?? sf_now()) : null;
        sf_exec('UPDATE reports SET status = ?, staff_note = ?, closed_at = ?, updated_at = ? WHERE id = ?', [$st, $note === '' ? null : $note, $closed, sf_now(), $r['id']]);
        if ($st !== $r['status']) sf_audit('report.status', 'report', $r['pid'], ['from' => $r['status'], 'to' => $st]);
        return ['report' => sf_report_out(sf_report_row($r['pid']), true)];
    });
}

/** ההתראה למייל התמיכה — אחת לדיווח (dedupe בתור + notified_at). **בלי** מה שהמשתמש כתב ובלי "לחזרה אליי": רק סוג וקישור. */
sf_job_register('ops.report_mail', 'דיווח חדש — התראה אחת למייל התמיכה', function (array $p): void {
    $r = sf_is_pid($p['report'] ?? null) ? sf_row('SELECT * FROM reports WHERE pid = ?', [$p['report']]) : null;
    if (!$r || $r['notified_at'] !== null) return;
    $to = sf_ops_support()['email'];
    if ($to === '') return;   // 📦 עוד אין מייל תמיכה — הדיווח ברשימה בניהול
    $where = sf_report_where($r);
    $label = SF_REPORT_CATS[$r['kind']][$r['category']] ?? $r['category'];
    $body = "דיווח חדש בספורנו — " . ($r['kind'] === 'content' ? 'טעות בתוכן' : 'פנייה') . ": $label\n"
        . ($where ? 'מיקום: ' . ($where['title'] ?? '') . ' · ' . ($where['edition_label'] ?? '') . ' · קטע ' . $where['sid'] . "\n" : '')
        . "\nלצפייה ולטיפול: " . sf_self_url('#/admin/reports/' . $r['pid']) . "\n";
    if (!sf_reader_mail($to, 'ספורנו — דיווח חדש', $body)) throw new RuntimeException('mail() נכשלה');
    sf_exec('UPDATE reports SET notified_at = ? WHERE id = ?', [sf_now(), $r['id']]);
});

// ── תמיכה: קורא אחד ─────────────────────────────────────────

/**
 * ops-reader (staff): ?q=מייל או pid. מה שהתמיכה צריכה: מצב, כניסות, מכשירים, מצב הסנכרון (ספירות לפי סוג), שגיאות.
 * **בלי תוכן:** לא הערות, לא שמות לתפילה, לא פריטים בכלל — רק ספירות (קריטריון 2, §3, §19).
 */
function sf_ops_reader(array $q): array
{
    $s = trim(mb_strtolower((string)($q['q'] ?? '')));
    if ($s === '' || mb_strlen($s) > 200) sf_fail('bad_input');
    $r = sf_is_pid($s) ? sf_row('SELECT * FROM readers WHERE pid = ?', [$s]) : sf_row('SELECT * FROM readers WHERE email = ?', [$s]);
    if (!$r) sf_fail('not_found', 'אין קורא עם המייל או המזהה הזה');
    $rid = (int)$r['id'];
    $since = gmdate('Y-m-d', sf_time() - SF_ACTIVITY_KEEP);
    $act = [];
    foreach (sf_rows('SELECT day, kind, n, last_at FROM reader_activity WHERE reader_id = ? AND day >= ? ORDER BY day DESC', [$rid, $since]) as $a) {
        $act[$a['day']][$a['kind']] = (int)$a['n'];
    }
    $occFail = (int)sf_val("SELECT COUNT(*) FROM reminder_occurrences o JOIN reminder_send_log l ON l.occurrence_id = o.id
        WHERE o.owner = ? AND l.result = 'failed' AND l.at > ?", ['r:' . $rid, sf_iso(sf_time() - 30 * 86400)]);
    sf_audit('reader.support_view', 'reader', $r['pid']);
    return ['reader' => [
        'pid' => $r['pid'], 'name' => $r['name'], 'email' => $r['status'] === 'purged' ? null : $r['email'], 'status' => $r['status'],
        'email_verified' => $r['email_verified_at'] !== null, 'google' => $r['google_sub'] !== null, 'password' => $r['password_hash'] !== null,
        'locked_until' => $r['locked_until'], 'created_at' => $r['created_at'], 'deleted_at' => $r['deleted_at'],
        'purge_at' => $r['deleted_at'] && $r['status'] === 'deleted' ? sf_iso((int)strtotime($r['deleted_at']) + SF_PURGE_AFTER) : null,
    ],
        'devices' => array_map(fn($d) => ['pid' => $d['pid'], 'label' => $d['label'], 'created_at' => $d['created_at'], 'last_seen_at' => $d['last_seen_at'], 'revoked_at' => $d['revoked_at']],
            sf_rows('SELECT * FROM devices WHERE reader_id = ? ORDER BY last_seen_at DESC LIMIT 30', [$rid])),
        'sync' => ['rev' => (int)$r['sync_rev']] + sf_reader_items_summary($rid),
        'push' => (int)sf_val('SELECT COUNT(*) FROM push_devices WHERE reader_id = ? AND revoked_at IS NULL AND gone_at IS NULL', [$rid]),
        'reminder_failures_30d' => $occFail,
        'activity' => array_map(fn($d, $k) => ['day' => $d] + $k, array_keys($act), $act)];
}

// ── הניקוי היומי ────────────────────────────────────────────

/** ops.purge: חשבונות שנמחקו לפני 30 יום; פעילות מעל 90 יום; "נראה היום" ישן; "לחזרה אליי" 90 יום אחרי סגירה. */
function sf_ops_purge(): array
{
    $ids = array_map('intval', array_column(sf_rows("SELECT id FROM readers WHERE status = 'deleted' AND deleted_at IS NOT NULL AND deleted_at < ?",
        [sf_iso(sf_time() - SF_PURGE_AFTER)]), 'id'));
    foreach ($ids as $id) sf_reader_purge($id);
    $act = sf_exec('DELETE FROM reader_activity WHERE day < ?', [gmdate('Y-m-d', sf_time() - SF_ACTIVITY_KEEP)]);
    sf_exec('DELETE FROM metrics_seen WHERE day < ?', [sf_ops_day(-1)]);
    $contacts = sf_exec("UPDATE reports SET contact = NULL WHERE status = 'closed' AND contact IS NOT NULL AND closed_at < ?", [sf_iso(sf_time() - SF_REPORT_CONTACT_KEEP)]);
    return ['readers' => count($ids), 'activity' => $act, 'contacts' => $contacts];
}

sf_job_register('ops.purge', 'ניקוי: חשבונות שנמחקו לפני 30 יום (מחיקה מלאה), פעילות מעל 90 יום', function (): void { sf_ops_purge(); }, daily: true);

/** ops.rollup: מסכם את אתמול מתוך יומני השליחה והסנכרון (שנמחקים אחרי 90 יום) — המצרפי נשאר לתמיד. */
function sf_ops_rollup(string $day): void
{
    $from = $day . 'T00:00:00Z';
    $to = gmdate('Y-m-d', (int)strtotime($day . 'T00:00:00Z') + 86400) . 'T00:00:00Z';
    $sent = (int)sf_val("SELECT COUNT(*) FROM reminder_send_log WHERE result = 'sent' AND channel <> 'push-test' AND at >= ? AND at < ?", [$from, $to]);
    $fail = (int)sf_val("SELECT COUNT(*) FROM reminder_send_log WHERE result IN ('failed', 'gone') AND channel <> 'push-test' AND at >= ? AND at < ?", [$from, $to]);
    $store = (int)sf_val("SELECT COUNT(*) FROM store_sync_runs WHERE status = 'failed' AND source = 'woo' AND started_at >= ? AND started_at < ?", [$from, $to]);
    sf_metric_set('rem_sent', '', $sent, $day);
    sf_metric_set('rem_fail', '', $fail, $day);
    sf_metric_set('store_err', '', $store, $day);
}

sf_job_register('ops.rollup', 'מדדים: סיכום אתמול (תזכורות שנשלחו ונכשלו, שגיאות סנכרון החנות)', function (): void { sf_ops_rollup(sf_ops_day(-1)); }, daily: true);

// ── לוח הבקרה ───────────────────────────────────────────────

/** בדיקות התקינות — לכל היותר פעם ב-10 דקות (חלקן פונות לרשת). */
function sf_ops_health(bool $fresh = false): array
{
    $c = json_decode((string)sf_meta('ops_health_cache', ''), true);
    if (!$fresh && is_array($c) && sf_time() - (int)strtotime((string)($c['at'] ?? '')) < 600) return $c;
    $h = sf_health();
    sf_meta_set('ops_health_cache', json_encode($h, JSON_UNESCAPED_UNICODE));
    return $h;
}

/** מה דורש תשומת לב — כל כשל של החנות, המייל, התזכורות, המתזמן והגיבוי (קריטריון 6). */
function sf_ops_alerts(): array
{
    $a = [];
    $day = sf_iso(sf_time() - 86400);
    $add = function (string $level, string $area, string $text, ?string $href = null) use (&$a): void {
        $a[] = ['level' => $level, 'area' => $area, 'text' => $text, 'href' => $href];
    };
    // החנות
    $run = sf_row("SELECT status, error, started_at FROM store_sync_runs WHERE source = 'woo' AND status <> 'running' ORDER BY id DESC LIMIT 1");
    if ($run && $run['status'] === 'failed') $add('bad', 'store', 'סנכרון החנות נכשל (' . $run['started_at'] . '): ' . $run['error'], '#/admin/store');
    elseif (sf_woo_ready() && ($v = sf_meta('store_verified_at')) && sf_time() - (int)strtotime($v) >= SF_STORE_FRESH) $add('warn', 'store', "החנות לא סונכרנה מאז $v — המחירים מוצגים כלא מאומתים", '#/admin/store');
    // התזכורות
    $rf = (int)sf_val("SELECT COUNT(*) FROM reminder_send_log WHERE result IN ('failed', 'gone') AND channel <> 'push-test' AND at > ?", [$day]);
    $rm = (int)sf_val("SELECT COUNT(*) FROM reminder_occurrences WHERE status = 'missed' AND fire_at > ?", [$day]);
    if ($rf) $add('bad', 'reminders', "$rf תזכורות נכשלו בשליחה ביממה האחרונה");
    if ($rm) $add('warn', 'reminders', "$rm תזכורות הוחמצו ביממה האחרונה (המתזמן לא רץ בזמן)");
    // המייל
    $mf = (int)sf_val("SELECT COALESCE(SUM(n), 0) FROM metrics_daily WHERE kind = 'mail_fail' AND day >= ?", [sf_ops_day(-1)]);
    $rmf = (int)sf_val("SELECT COUNT(*) FROM reminder_send_log WHERE channel = 'mail' AND result = 'failed' AND at > ?", [$day]);
    if ($mf + $rmf) $add('bad', 'mail', ($mf + $rmf) . ' מיילים לא נשלחו (אימות, איפוס, תזכורות, תמיכה) מאתמול', '#/dev/health');
    // המתזמן והתור
    $ok = sf_meta('cron_last_ok_at');
    if ($ok === null) $add('warn', 'cron', 'המתזמן (cron) עוד לא רץ — בלעדיו אין תזכורות, סנכרון, גיבוי וניקוי', '#/dev/jobs');
    elseif (sf_time() - (int)strtotime($ok) > 900) $add('bad', 'cron', 'המתזמן לא רץ ' . intdiv(sf_time() - (int)strtotime($ok), 60) . ' דקות', '#/dev/jobs');
    $jf = (int)sf_val("SELECT COUNT(*) FROM jobs WHERE status = 'failed'");
    if ($jf) $add('bad', 'jobs', "$jf משימות רקע נכשלו", '#/dev/jobs');
    // הגיבוי ובדיקת השחזור
    $b = sf_row('SELECT created_at, verified_at, verify_ok FROM backups WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');
    if (!$b) $add('warn', 'backup', 'עוד אין גיבוי', '#/dev/backup');
    elseif (sf_time() - (int)strtotime($b['created_at']) > 36 * 3600) $add('bad', 'backup', 'הגיבוי האחרון מ-' . $b['created_at'] . ' — מעל 36 שעות', '#/dev/backup');
    $v = sf_row('SELECT verified_at, verify_ok FROM backups WHERE verified_at IS NOT NULL ORDER BY verified_at DESC LIMIT 1');
    if ($v && !(int)$v['verify_ok']) $add('bad', 'backup', 'בדיקת השחזור האחרונה נכשלה (' . $v['verified_at'] . ')', '#/dev/backup');
    return $a;
}

/** ops-dashboard (staff): מסך הבית של הניהול (§16). */
function sf_ops_dashboard(array $q, ?array $staff): array
{
    $h = sf_ops_health(($q['fresh'] ?? '') === '1' && $staff && (int)$staff['is_developer']);
    $day = sf_iso(sf_time() - 86400);
    $send = [];
    foreach (sf_rows("SELECT channel, result, COUNT(*) AS n FROM reminder_send_log WHERE at > ? AND channel <> 'push-test' GROUP BY channel, result", [$day]) as $r) {
        $send[$r['channel']][$r['result']] = (int)$r['n'];
    }
    $b = sf_row('SELECT pid, kind, bytes, created_at, verified_at, verify_ok FROM backups WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');
    $run = sf_row("SELECT kind, status, stage, products, error, started_at, finished_at FROM store_sync_runs ORDER BY id DESC LIMIT 1");
    return [
        'alerts' => sf_ops_alerts(),
        'published' => array_map(fn($r) => ['title' => $r['t_name'], 'edition' => $r['e_pid'], 'edition_label' => $r['label'], 'n' => (int)$r['n'],
            'at' => $r['published_at'], 'by' => $r['by_name']],
            sf_rows("SELECT r.n, r.published_at, e.pid AS e_pid, e.label, t.name AS t_name, s.name AS by_name FROM revisions r JOIN editions e ON e.id = r.edition_id
                JOIN titles t ON t.id = e.title_id LEFT JOIN staff s ON s.id = r.published_by WHERE r.published_at IS NOT NULL ORDER BY r.published_at DESC LIMIT 6")),
        'reports' => ['counts' => (object)array_map('intval', array_column(sf_rows('SELECT status, COUNT(*) AS n FROM reports GROUP BY status'), 'n', 'status')),
            'latest' => array_map('sf_report_out', sf_rows("SELECT * FROM reports WHERE status IN ('new', 'working') ORDER BY id DESC LIMIT 5"))],
        'store' => ['ready' => sf_woo_ready(), 'status' => sf_store_status(), 'run' => $run],
        'cron' => ['last_ok_at' => sf_meta('cron_last_ok_at'), 'failed_jobs' => (int)sf_val("SELECT COUNT(*) FROM jobs WHERE status = 'failed'"),
            'pending' => (int)sf_val("SELECT COUNT(*) FROM jobs WHERE status = 'pending'")],
        'reminders' => ['day' => (object)$send, 'pending' => (int)sf_val("SELECT COUNT(*) FROM reminder_occurrences WHERE status = 'pending'")],
        'backup' => $b ? ['created_at' => $b['created_at'], 'bytes' => (int)$b['bytes'], 'kind' => $b['kind'], 'verified_at' => $b['verified_at'],
            'verify_ok' => $b['verify_ok'] === null ? null : (bool)$b['verify_ok']] : null,
        'health' => ['state' => $h['state'], 'at' => $h['at'], 'issues' => array_values(array_map(fn($c) => ['label' => $c['label'], 'state' => $c['state'], 'detail' => $c['detail']],
            array_filter($h['checks'] ?? [], fn($c) => $c['state'] !== 'ok')))],
        'today' => ['readers' => (int)sf_val("SELECT n FROM metrics_daily WHERE day = ? AND kind = 'reader' AND mkey = ''", [sf_ops_day()]),
            'guests' => (int)sf_val("SELECT n FROM metrics_daily WHERE day = ? AND kind = 'guest' AND mkey = ''", [sf_ops_day()]),
            'opens' => (int)sf_val("SELECT COALESCE(SUM(n), 0) FROM metrics_daily WHERE day = ? AND kind = 'open'", [sf_ops_day()])],
    ];
}

/** ops-metrics (staff): ?days=7|30|90|365. לפי יום, ופתיחות לפי כותר ומקטע עליון. **רק ספירות ומזהים** (קריטריון 7). */
function sf_ops_metrics(array $q): array
{
    $days = (int)($q['days'] ?? 30);
    if (!in_array($days, [7, 30, 90, 365], true)) $days = 30;
    $from = sf_ops_day(-($days - 1));
    $byDay = [];
    foreach (sf_rows("SELECT day, kind, SUM(n) AS n FROM metrics_daily WHERE day >= ? AND kind NOT IN ('open_sec') GROUP BY day, kind", [$from]) as $r) {
        $byDay[$r['day']][$r['kind']] = (int)$r['n'];
    }
    foreach (sf_rows('SELECT day, kind, SUM(n) AS n FROM store_stats WHERE day >= ? GROUP BY day, kind', [$from]) as $r) {
        $byDay[$r['day']]['store_' . $r['kind']] = (int)$r['n'];
    }
    foreach (sf_rows('SELECT substr(created_at, 1, 10) AS day, COUNT(*) AS n FROM readers WHERE created_at >= ? GROUP BY substr(created_at, 1, 10)', [$from . 'T00:00:00Z']) as $r) {
        $byDay[$r['day']]['register'] = (int)$r['n'];
    }
    krsort($byDay);
    $titles = array_map(fn($r) => ['edition' => $r['mkey'], 'title' => $r['t_name'], 'edition_label' => $r['label'], 'n' => (int)$r['n']],
        sf_rows("SELECT m.mkey, SUM(m.n) AS n, e.label, t.name AS t_name FROM metrics_daily m LEFT JOIN editions e ON e.pid = m.mkey LEFT JOIN titles t ON t.id = e.title_id
            WHERE m.kind = 'open' AND m.day >= ? GROUP BY m.mkey, e.label, t.name ORDER BY n DESC LIMIT 20", [$from]));
    $secs = [];
    foreach (sf_rows("SELECT mkey, SUM(n) AS n FROM metrics_daily WHERE kind = 'open_sec' AND day >= ? GROUP BY mkey ORDER BY n DESC LIMIT 20", [$from]) as $r) {
        [$ed, $sid] = explode(':', $r['mkey'], 2) + [1 => ''];
        $seg = sf_row("SELECT s.title FROM segments s JOIN revisions r ON r.id = s.revision_id JOIN editions e ON e.id = r.edition_id
            WHERE e.pid = ? AND s.sid = ? AND r.status IN ('published', 'superseded') ORDER BY r.n DESC LIMIT 1", [$ed, $sid]);
        $secs[] = ['edition' => $ed, 'sid' => $sid, 'title' => $seg['title'] ?? null, 'n' => (int)$r['n']];
    }
    $products = array_map(fn($r) => ['product' => (int)$r['product_woo_id'], 'name' => $r['name'], 'view' => (int)$r['v'], 'out' => (int)$r['o']],
        sf_rows("SELECT st.product_woo_id, p.name, SUM(CASE WHEN st.kind = 'view' THEN st.n ELSE 0 END) AS v, SUM(CASE WHEN st.kind = 'out' THEN st.n ELSE 0 END) AS o
            FROM store_stats st LEFT JOIN store_products p ON p.woo_id = st.product_woo_id WHERE st.day >= ? GROUP BY st.product_woo_id, p.name ORDER BY v DESC LIMIT 20", [$from]));
    return ['days' => $days, 'from' => $from, 'by_day' => array_map(fn($d, $k) => ['day' => $d] + $k, array_keys($byDay), $byDay),
        'titles' => $titles, 'sections' => $secs, 'products' => $products];
}
