<?php
/**
 * הפורטל לנמען (אפיון 07 §5, §9) — הפעולות הציבוריות היחידות שנוגעות בנתוני תיק. **לנמען אין חשבון**: הזהות היא האסימון
 * של הקישור, ורק הוא. הקוד כאן אינו קורא את PLATFORM_USER ואת apr_session, ואינו יוצר session — גם אם הם קיימים בדפדפן.
 *
 * כל פעולה, לפי הסדר: קצב לפי IP ← האסימון (hash, תוקף, ביטול, בקשה, תיק, משרד) ← נעילה ← קצב לפי קישור ← הפעולה.
 * - אסימון שגוי, שפג, שבוטל, של בקשה שבוטלה, של תיק שנמחק או של משרד מושעה — **אותה תשובה בדיוק** (portal_invalid).
 * - האסימון מגיע בגוף הבקשה (ובהעלאה — בכותרת X-Portal-Token), לעולם לא בכתובת; הוא אינו נרשם בשום יומן.
 * - התשובות בנויות כאן שדה-שדה: רק הדרישות שב-request_items, ורק הקבצים שהועלו דרך הבקשה הזו. אין בהן מספר תיק,
 *   כתובת, גורמים אחרים, הערות פנימיות (explain_internal, review_note) או מזהים של התיק.
 * - צפייה והורדה של קובץ: רק ממכשיר שעבר קוד חד-פעמי למייל של הנמען.
 */

declare(strict_types=1);

require_once __DIR__ . '/requests.php';

const APR_PORTAL_COOKIE = 'apr_portal';
const APR_PORTAL_RATE = [60, 60];                  // בקשות לדקה — לכל IP, ולכל קישור
const APR_PORTAL_BAD = [20, 600];                  // אסימונים שגויים מ-IP אחד
const APR_PORTAL_UPLOADS = [20, 3600];             // העלאות לקישור
const APR_PORTAL_REPLIES = [30, 3600];             // תשובות לקישור
const APR_PORTAL_OTP_SENDS = [3, 900];             // קודים שנשלחים לקישור
const APR_PORTAL_OTP_TTL = 600;
const APR_PORTAL_OTP_TRIES = 5;
const APR_PORTAL_LOCK = 900;
const APR_PORTAL_DEVICE_TTL = 30 * 86400;
/** המצב בעיני הנמען — מיפוי, לא המצב הפנימי. not_relevant אינו מופיע: הדרישה נעלמת מהרשימה. */
const APR_PORTAL_STATES = ['required' => 'waiting', 'requested' => 'waiting', 'received' => 'checking', 'returned' => 'fix', 'approved' => 'done'];

function apr_portal_ip_key(array $env): string
{
    return substr(hash_hmac('sha256', 'ip:' . (string)($env['ip'] ?? ''), apr_secret()), 0, 24);
}

/**
 * מהאסימון אל הקישור, הבקשה, התיק והגורם — או portal_invalid, בלי להבחין למה. $token מהגוף, או מהכותרת (בהעלאה).
 * מחזיר שורה אחת: l_* (הקישור), הבקשה, case_* ו-contact_*. תיק סגור אינו "לא תקף": closed = true, והפעולות נחסמות.
 */
function apr_portal_link(mixed $token, array $env): array
{
    if (!apr_limit('portal:i:' . apr_portal_ip_key($env), ...APR_PORTAL_RATE)) apr_fail('portal_rate');
    $row = null;
    if (is_string($token) && preg_match('/^[A-Za-z0-9_-]{43}\z/', $token) === 1) {
        $hash = apr_portal_token_hash($token);
        $row = apr_row("SELECT q.*, l.id AS l_id, l.token_hash AS l_hash, l.expires_at AS l_expires, l.revoked_at AS l_revoked, l.locked_until AS l_locked,
                c.pid AS case_pid, c.status AS case_status, c.deleted_at AS case_deleted, c.responsible_user_id, o.status AS office_status, o.name AS office_name, o.portal_always_otp,
                cp.pid AS party_pid, cp.removed_at AS party_removed, ct.name AS contact_name, ct.email AS contact_email, ct.deleted_at AS contact_deleted, u.name AS sender_name
            FROM portal_links l JOIN requests q ON q.id = l.request_id JOIN cases c ON c.id = q.case_id JOIN offices o ON o.id = q.office_id
            JOIN case_parties cp ON cp.id = q.party_id JOIN contacts ct ON ct.id = cp.contact_id LEFT JOIN users u ON u.id = q.created_by
            WHERE l.token_hash = ?", [$hash]);
        if ($row && !hash_equals($row['l_hash'], $hash)) $row = null;
    }
    $now = apr_now();
    if (!$row || $row['l_revoked'] !== null || $row['l_expires'] <= $now || $row['expires_at'] <= $now || !in_array($row['status'], ['active', 'completed'], true)
        || $row['case_deleted'] !== null || $row['office_status'] !== 'active' || $row['party_removed'] !== null || $row['contact_deleted'] !== null) {
        // ניסיונות שגויים נספרים לכל IP; מעבר לתקרה — 429, גם לאסימון שגוי וגם לנכון
        if (!apr_limit('portal:bad:' . apr_portal_ip_key($env), ...APR_PORTAL_BAD)) apr_fail('portal_rate');
        apr_fail('portal_invalid');
    }
    if ($row['l_locked'] !== null && $row['l_locked'] > $now) apr_fail('portal_locked');
    if (!apr_limit('portal:l:' . $row['l_id'], ...APR_PORTAL_RATE)) apr_fail('portal_rate');
    $row['closed'] = $row['case_status'] === 'closed';
    $row['perms'] = apr_request_perms(json_decode($row['perms'], true), $row['contact_email'] !== null && $row['contact_email'] !== '');
    $row['has_email'] = $row['contact_email'] !== null && $row['contact_email'] !== '';
    $row['verified'] = apr_portal_device_ok((int)$row['l_id'], $env);
    return $row;
}

/** התיק של הבקשה, בצורה שהצינור של הקבצים והיומן מצפים לה. */
function apr_portal_case(array $l): array
{
    return ['id' => (int)$l['case_id'], 'pid' => $l['case_pid'], 'office_id' => (int)$l['office_id'], 'status' => $l['case_status']];
}

/** פעולה שמשנה או חושפת: תיק סגור — "הבקשה נסגרה"; "תמיד לדרוש קוד" במשרד — רק ממכשיר מאומת. */
function apr_portal_usable(array $l): void
{
    if ($l['closed']) apr_fail('portal_closed');
    if ((int)$l['portal_always_otp'] && !$l['verified']) apr_fail('portal_otp_required');
}

/** בתוך טרנזקציה, לפני כתיבה: הקישור והבקשה עדיין בתוקף (ביטול ושליחה מחדש הם מיידיים). */
function apr_portal_recheck(array $l): void
{
    $ok = apr_val("SELECT 1 FROM portal_links l JOIN requests q ON q.id = l.request_id WHERE l.id = ? AND l.revoked_at IS NULL AND l.expires_at > ? AND q.expires_at > ? AND q.status IN ('active', 'completed')",
        [$l['l_id'], apr_now(), apr_now()]);
    if (!$ok) apr_fail('portal_invalid');
}

/** דרישה של הבקשה הזו, לפי pid — ורק שלה. דרישה של בקשה אחרת, שסומנה "לא רלוונטי" או שפוצלה: לא נמצא. */
function apr_portal_item(array $l, mixed $pid): array
{
    $r = apr_is_pid($pid) ? apr_row("SELECT r.* FROM request_items ri JOIN requirements r ON r.id = ri.requirement_id
        WHERE ri.request_id = ? AND r.pid = ? AND r.case_id = ? AND r.split_at IS NULL AND r.status <> 'not_relevant'", [$l['id'], $pid, $l['case_id']]) : null;
    return $r ?? apr_fail('not_found');
}

/** העוגייה של מכשיר מאומת: אקראית, HttpOnly, SameSite=Strict, לתיקיית האפליקציה (שם ה-API). לא session: היא לבדה אינה פותחת דבר. */
function apr_set_portal_cookie(string $value): void
{
    if (headers_sent()) return;
    setcookie(APR_PORTAL_COOKIE, $value, ['expires' => time() + APR_PORTAL_DEVICE_TTL, 'path' => rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
        'secure' => !defined('APR_DEV'), 'httponly' => true, 'samesite' => 'Strict']);
}

// ── מכשיר מאומת וקוד חד-פעמי ──────────────────────────────────

function apr_portal_device_hash(string $cookie): string
{
    return hash('sha256', 'device:' . $cookie);
}

function apr_portal_device_ok(int $linkId, array $env): bool
{
    $cookie = (string)($env['portal_cookie'] ?? '');
    if (preg_match('/^[A-Za-z0-9_-]{43}\z/', $cookie) !== 1) return false;
    return (bool)apr_val('SELECT 1 FROM portal_devices WHERE link_id = ? AND device_hash = ? AND expires_at > ?', [$linkId, apr_portal_device_hash($cookie), apr_now()]);
}

function apr_portal_otp_hash(int $linkId, string $code): string
{
    return hash_hmac('sha256', "portal-otp:$linkId:$code", apr_secret());
}

/** שולח קוד בן 6 ספרות למייל של הנמען — לכתובת שבספר הכתובות, לא לכתובת מהבקשה. התשובה אינה מכילה את הכתובת. */
function apr_portal_otp_send(array $in, array $env): array
{
    $l = apr_portal_link($in['token'] ?? null, $env);
    if ($l['closed']) apr_fail('portal_closed');
    if (!$l['has_email']) apr_fail('portal_otp_unavailable');
    if (!apr_limit('portal:otp:' . $l['l_id'], ...APR_PORTAL_OTP_SENDS)) apr_fail('too_many_codes');
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $id = apr_tx(function () use ($l, $code) {
        apr_portal_recheck($l);
        apr_exec('UPDATE portal_otps SET used_at = ? WHERE link_id = ? AND used_at IS NULL', [apr_now(), $l['l_id']]);   // קוד חדש מבטל את הקודם
        return apr_insert('portal_otps', ['link_id' => $l['l_id'], 'code_hash' => apr_portal_otp_hash((int)$l['l_id'], $code), 'created_at' => apr_now(), 'expires_at' => apr_iso(apr_time() + APR_PORTAL_OTP_TTL)]);
    });
    // מפתח האידמפוטנטיות של המייל כולל חלק אקראי: מזהה שורה ב-portal_otps חוזר אחרי מחיקה (שליחה מחדש), ואז המייל לא היה יוצא
    $status = apr_mail_send('portal_otp', (string)$l['contact_email'], 'קוד אימות — ' . $l['office_name'],
        "הקוד שלכם: $code\n\nהקוד תקף ל-10 דקות, ומשמש לצפייה במסמכים שהעליתם.\nאם לא ביקשתם קוד — אפשר להתעלם מההודעה.", 'portal-otp:' . $id . ':' . apr_pid(), (int)$l['office_id'], ['from_name' => $l['office_name']]);
    if ($status !== 'sent') apr_fail('portal_otp_unavailable');
    return ['sent' => true, 'minutes' => intdiv(APR_PORTAL_OTP_TTL, 60)];
}

/**
 * מאמת קוד. 5 קודים שגויים ברצף — הקישור נחסם לרבע שעה (07-B5); הספירה אטומית, בתוך הטרנזקציה. הצלחה: המכשיר נזכר
 * 30 יום (העוגייה apr_portal — אקראית, HttpOnly; במסד רק ה-hash שלה, ורק לקישור הזה).
 */
function apr_portal_otp_verify(array $in, array $env): array
{
    $l = apr_portal_link($in['token'] ?? null, $env);
    if ($l['closed']) apr_fail('portal_closed');
    $code = is_string($in['code'] ?? null) ? trim($in['code']) : '';
    $valid = preg_match('/^\d{6}\z/', $code) === 1;
    $cookie = (string)($env['portal_cookie'] ?? '');
    if (preg_match('/^[A-Za-z0-9_-]{43}\z/', $cookie) !== 1) $cookie = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $ok = apr_tx(function () use ($l, $code, $valid, $cookie) {
        apr_portal_recheck($l);
        $locked = apr_val('SELECT locked_until FROM portal_links WHERE id = ?', [$l['l_id']]);
        if (is_string($locked) && $locked > apr_now()) return null;
        $o = apr_row('SELECT * FROM portal_otps WHERE link_id = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1', [$l['l_id'], apr_now()]);
        if ($valid && $o && hash_equals($o['code_hash'], apr_portal_otp_hash((int)$l['l_id'], $code))
            && apr_exec('UPDATE portal_otps SET used_at = ? WHERE id = ? AND used_at IS NULL', [apr_now(), $o['id']]) === 1) {
            apr_exec('UPDATE portal_links SET otp_fails = 0 WHERE id = ?', [$l['l_id']]);
            apr_exec('INSERT INTO portal_devices (link_id, device_hash, verified_at, expires_at) VALUES (?, ?, ?, ?)
                ON CONFLICT (link_id, device_hash) DO UPDATE SET verified_at = excluded.verified_at, expires_at = excluded.expires_at',
                [$l['l_id'], apr_portal_device_hash($cookie), apr_now(), apr_iso(apr_time() + APR_PORTAL_DEVICE_TTL)]);
            apr_case_audit(apr_portal_case($l), 'portal.device_verified', 'request', $l['pid'], [], null, null, 'recipient');
            return true;
        }
        apr_exec('UPDATE portal_links SET otp_fails = otp_fails + 1 WHERE id = ?', [$l['l_id']]);
        if ((int)apr_val('SELECT otp_fails FROM portal_links WHERE id = ?', [$l['l_id']]) >= APR_PORTAL_OTP_TRIES) {
            apr_exec('UPDATE portal_links SET locked_until = ?, otp_fails = 0 WHERE id = ?', [apr_iso(apr_time() + APR_PORTAL_LOCK), $l['l_id']]);
            apr_exec('UPDATE portal_otps SET used_at = ? WHERE link_id = ? AND used_at IS NULL', [apr_now(), $l['l_id']]);
            apr_case_audit(apr_portal_case($l), 'portal.locked', 'request', $l['pid'], [], null, null, 'recipient');
        }
        return false;
    });
    if ($ok === null) apr_fail('portal_locked');
    if (!$ok) apr_fail('portal_otp_wrong');
    $GLOBALS['apr_portal_cookie_out'] = $cookie;
    return ['verified' => true];
}

// ── מה הנמען רואה ─────────────────────────────────────────────

/**
 * הפורטל: שם המשרד והשולח, הנוסח, והדרישות של הבקשה הזו. הקבצים שהנמען העלה מפורטים רק למכשיר מאומת עם view_own;
 * אחרת — רק כמה התקבלו. תיק סגור: {state: closed} ותו לא.
 */
function apr_portal_open(array $in, array $env): array
{
    $l = apr_portal_link($in['token'] ?? null, $env);
    if ($l['closed']) return ['state' => 'closed'];
    apr_exec('UPDATE portal_links SET last_used_at = ?, use_count = use_count + 1 WHERE id = ?', [apr_now(), $l['l_id']]);
    $otp = ['available' => $l['has_email'], 'verified' => $l['verified'], 'required' => (bool)$l['portal_always_otp']];
    if ((int)$l['portal_always_otp'] && !$l['verified']) return ['state' => 'otp', 'otp' => $otp];
    $canView = $l['verified'] && ($l['perms']['view_own'] || $l['perms']['download_own']);
    $files = [];
    foreach (apr_rows("SELECT rf.requirement_id, rf.review_state, f.pid, f.original_name, f.size, f.mime, f.received_at FROM files f JOIN requirement_files rf ON rf.file_id = f.id
        WHERE f.request_id = ? AND f.case_id = ? AND f.deleted_at IS NULL AND rf.review_state <> 'rejected' ORDER BY f.id", [$l['id'], $l['case_id']]) as $f) {
        $files[$f['requirement_id']][] = ['id' => $f['pid'], 'name' => $f['original_name'], 'size' => (int)$f['size'], 'received_at' => $f['received_at'],
            'preview' => in_array($f['mime'], APR_FILE_PREVIEW, true)];
    }
    $said = array_flip(array_column(apr_rows("SELECT DISTINCT requirement_id FROM requirement_responses WHERE request_id = ? AND kind = 'no_document'", [$l['id']]), 'requirement_id'));
    $items = [];
    foreach (apr_rows("SELECT r.* FROM request_items ri JOIN requirements r ON r.id = ri.requirement_id
        WHERE ri.request_id = ? AND r.case_id = ? AND r.split_at IS NULL AND r.status <> 'not_relevant' ORDER BY r.sort, r.id", [$l['id'], $l['case_id']]) as $r) {
        $state = APR_PORTAL_STATES[$r['status']] ?? 'waiting';
        $mine = $files[$r['id']] ?? [];
        $item = ['id' => $r['pid'], 'title' => $r['title'], 'explain' => $r['explain_recipient'], 'due_at' => $r['due_at'], 'state' => $state,
            'files_count' => count($mine), 'no_document' => isset($said[$r['id']])];
        if ($state === 'fix') {
            $cur = apr_req_current((int)$r['id']);
            $item['reason'] = $cur === null ? null : (apr_val("SELECT rf.return_reason FROM requirement_files rf JOIN files f ON f.id = rf.file_id
                WHERE rf.requirement_id = ? AND rf.version_no = ? AND rf.review_state = 'returned' AND f.deleted_at IS NULL ORDER BY rf.id DESC LIMIT 1", [$r['id'], $cur]) ?: null);
        }
        if ($canView) $item['files'] = $mine;
        $items[] = $item;
    }
    return ['state' => 'open', 'office' => $l['office_name'], 'sender' => $l['sender_name'], 'intro' => $l['body'], 'expires_at' => $l['expires_at'],
        'can' => ['upload' => $l['perms']['upload'], 'reply' => $l['perms']['reply'], 'view' => $l['perms']['view_own'], 'download' => $l['perms']['download_own']],
        'otp' => $otp, 'items' => $items, 'limits' => ['max_size' => apr_file_max(), 'types' => array_column(APR_FILE_TYPES, 0)]];
}

// ── העלאה, תשובה וקובץ ────────────────────────────────────────

/**
 * העלאה מהפורטל — אותו צינור של העלאה פנימית (apr_file_ingest), עם uploader_kind = recipient ו-channel = portal.
 * $q (מהכתובת): requirement, name, key. האסימון בכותרת. הגוף נקרא לדיסק **רק אחרי** שהאסימון אומת, הבקשה פתוחה,
 * הדרישה שייכת לבקשה, והקצב והמכסה עברו. הקובץ נכנס כ"ממתין לבדיקה" — העלאה לעולם אינה מאשרת.
 */
function apr_portal_upload(array $q, array $env): array
{
    $l = null;
    $out = apr_file_ingest(function () use ($q, $env, &$l) {
        $l = apr_portal_link($env['portal_token'] ?? null, $env);
        apr_portal_usable($l);
        if (!$l['perms']['upload']) apr_fail('portal_forbidden');
        $r = apr_portal_item($l, $q['requirement'] ?? null);
        if ($r['status'] === 'approved') apr_fail('req_state');
        return apr_portal_case($l);
    }, ['kind' => 'recipient', 'uid' => null, 'channel' => 'portal', 'audit' => [null, 'recipient'],
        'rate_key' => function () use (&$l) { return 'p' . $l['l_id']; }, 'rate' => APR_PORTAL_UPLOADS, 'request' => function () use (&$l) { return (int)$l['id']; },
        'key' => function () use ($q, &$l) {
            $v = $q['key'] ?? null;
            if (!is_string($v) || preg_match('/^[A-Za-z0-9_-]{8,64}\z/', $v) !== 1) apr_fail('content_invalid');   // מזהה פעולה חובה: לחיצה כפולה = קובץ אחד
            return 'r' . $l['id'] . ':' . $v;
        },
        'recheck' => function () use ($q, &$l) { apr_portal_recheck($l); apr_portal_item($l, $q['requirement'] ?? null); }],
        ['requirement' => $q['requirement'] ?? null, 'name' => $q['name'] ?? null], $env);
    return ['received' => true, 'file' => $out['file'], 'repeat' => !empty($out['repeat'])];
}

/** "אין ברשותי" או הערה. "אין ברשותי" יוצר משימת המשך לשמאי — הדרישה **אינה** משנה מצב: זו החלטה של השמאי (§6). */
function apr_portal_respond(array $in, array $env): array
{
    $l = apr_portal_link($in['token'] ?? null, $env);
    apr_portal_usable($l);
    if (!$l['perms']['reply']) apr_fail('portal_forbidden');
    $kind = apr_clean_choice($in['kind'] ?? null, ['no_document', 'comment']) ?? apr_fail('content_invalid');
    $text = apr_clean_text($in['text'] ?? '', 1000);
    if ($kind === 'comment' && $text === null) apr_fail('content_invalid');
    $r = apr_portal_item($l, $in['requirement'] ?? null);
    if (!apr_limit('portal:r:' . $l['l_id'], ...APR_PORTAL_REPLIES)) apr_fail('portal_rate');
    return apr_tx(function () use ($l, $kind, $text, $in) {
        $c = apr_portal_case($l);
        apr_case_still_open($c);
        apr_portal_recheck($l);
        $r = apr_portal_item($l, $in['requirement'] ?? null);
        $pid = apr_pid();
        apr_insert('requirement_responses', ['pid' => $pid, 'requirement_id' => $r['id'], 'party_id' => $l['party_id'], 'request_id' => $l['id'], 'kind' => $kind, 'text' => $text, 'created_at' => apr_now()]);
        if ($kind === 'no_document') {
            $origin = 'no_document:' . $r['id'] . ':' . $l['party_id'];
            if (!apr_val("SELECT 1 FROM tasks WHERE case_id = ? AND origin_key = ? AND status IN ('open', 'in_progress')", [$c['id'], $origin])) {
                apr_insert('tasks', ['pid' => apr_pid(), 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'kind' => 'followup',
                    'title' => mb_substr($l['contact_name'] . ' הודיע/ה שאין ברשותו/ה: ' . $r['title'], 0, 160), 'detail' => $text,
                    'assignee_user_id' => $l['responsible_user_id'], 'link_type' => 'requirement', 'link_pid' => $r['pid'], 'origin_key' => $origin,
                    'created_at' => apr_now(), 'updated_at' => apr_now()]);
            }
        }
        apr_case_audit($c, 'portal.response', 'requirement', $r['pid'], ['kind' => $kind, 'request' => $l['pid']], null, null, 'recipient');
        apr_case_touch($c);
        return ['saved' => true];
    });
}

/**
 * קובץ שהנמען עצמו העלה בבקשה הזו: file, inline. רק ממכשיר מאומת (07-B4), ורק לפי ההרשאה: view_own — תצוגה בתוך הדף
 * (לסוגים שמוצגים), download_own — הורדה. קובץ אחר של התיק, גם אם ה-pid שלו נכון — לא נמצא (07-B2).
 */
function apr_portal_file(array $in, array $env): array
{
    $l = apr_portal_link($in['token'] ?? null, $env);
    if ($l['closed']) apr_fail('portal_closed');
    if (!$l['verified']) apr_fail('portal_otp_required');
    $inline = ($in['inline'] ?? false) === true;
    if (!$l['perms'][$inline ? 'view_own' : 'download_own']) apr_fail('portal_forbidden');
    $f = apr_is_pid($in['file'] ?? null) ? apr_row('SELECT * FROM files WHERE pid = ? AND request_id = ? AND case_id = ? AND deleted_at IS NULL', [$in['file'], $l['id'], $l['case_id']]) : null;
    if (!$f) apr_fail('not_found');
    if ($inline && !in_array($f['mime'], APR_FILE_PREVIEW, true)) apr_fail('portal_forbidden');
    return apr_file_response(apr_file_path($f['storage_key']), $f['mime'], apr_file_download_name($f['original_name'], $f['ext']), $inline);
}

/** שגיאת JavaScript מדף הפורטל — נרשמת בלי האסימון ובלי תוכן: רק סוג, הודעה מקוצרת, קובץ ושורה. כל רצף שנראה כמו אסימון נמחק. */
function apr_portal_client_error(array $in, array $env): array
{
    if (!apr_limit('portal:i:' . apr_portal_ip_key($env), ...APR_PORTAL_RATE) || !apr_limit('portal:e:' . apr_portal_ip_key($env), 10, 600)) apr_fail('portal_rate');
    $clean = fn(mixed $v, int $max) => mb_substr((string)preg_replace('/[A-Za-z0-9_-]{24,}/', '[…]', is_scalar($v) ? (string)$v : ''), 0, $max);
    devloop_log_write('error', 'client', 'portal.client_error', ['message' => $clean($in['message'] ?? '', 300),
        'data' => ['source' => basename($clean($in['source'] ?? '', 120)), 'line' => (int)($in['line'] ?? 0), 'col' => (int)($in['col'] ?? 0), 'kind' => $clean($in['kind'] ?? '', 20)]]);
    return [];
}
