<?php
/**
 * מסמכים (אפיון 06): מצבי הדרישה, העלאה ושיוך, גרסאות וקבצים נלווים, בדיקה ואישור, ניהול הרשימה, חיפוש.
 * הצינור של הקובץ עצמו (זיהוי סוג, אחסון, הגשה) — lib/files.php.
 *
 * הכללים:
 * - כל פעולה עוברת ב-apr_case_get(): משרד אחר / לא משתתף / לא קיים → אותו 404. דרישה, קובץ ושיוך נטענים **תמיד**
 *   עם case_id של התיק שנבדק — מזהה של תיק אחר הוא "לא נמצא".
 * - `documents`: רשימה, העלאה, שיוך, תיוג. `review_docs`: אישור, החזרה, דחייה, "לא רלוונטי", הורדת חובה, פתיחה מחדש, מחיקה.
 * - **העלאה לעולם אינה מאשרת**: קובץ חדש ← received, והשיוך pending — גם כשהמעלה הוא האחראי.
 * - מצב הדרישה נגזר מהגרסה הנוכחית שלה (apr_req_derive): משהו ממתין ← received; הוחזר ← returned; הכול אושר ← approved.
 * - כל שינוי מצב הוא UPDATE … WHERE <המצב הקודם> עם בדיקת מספר השורות, בתוך טרנזקציה אחת עם שורת היומן.
 */

declare(strict_types=1);

require_once __DIR__ . '/cases.php';
require_once __DIR__ . '/case_parts.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/health.php';

const APR_REQ_STATUSES = ['required', 'requested', 'received', 'returned', 'approved', 'not_relevant'];
/** מכונת המצבים (אפיון 06 §5, האפיון 23.1): מאיזה מצב לאיזה מותר לעבור. */
const APR_REQ_TRANSITIONS = [
    'required'     => ['requested', 'received', 'not_relevant'],
    'requested'    => ['received', 'not_relevant', 'required'],
    'received'     => ['approved', 'returned'],
    'returned'     => ['received', 'not_relevant'],
    'approved'     => ['received'],
    'not_relevant' => ['required'],
];
/** דרישת חובה פתוחה: לא אושרה, לא סומנה "לא רלוונטי" ולא פוצלה. $a — הכינוי של הטבלה בשאילתה, עם נקודה. */
function apr_req_open_sql(string $a = ''): string
{
    return "{$a}mandatory = 1 AND {$a}split_at IS NULL AND {$a}status NOT IN ('approved', 'not_relevant')";
}
const APR_RETURN_CODES = ['missing_pages', 'unreadable', 'outdated', 'wrong_document', 'other'];
const APR_LINK_SELECT = 'SELECT rf.*, f.deleted_at AS file_deleted FROM requirement_files rf JOIN files f ON f.id = rf.file_id';

/** הקובץ כבר נבדק בידי מישהו אחר (מקרה קצה 6) — התשובה נושאת מי ומה התוצאה. */
class AprReviewed extends AprError
{
    public function __construct(public readonly array $review)
    {
        parent::__construct('already_reviewed');
    }
}

function apr_req_can(string $from, string $to): bool
{
    return in_array($to, APR_REQ_TRANSITIONS[$from] ?? [], true);
}

// ── טעינה, תמיד בתוך התיק ─────────────────────────────────────

/** התיק של ישות-בן לפי ה-pid שלה — רק כדי לדעת איזה תיק לבדוק. null כשאין. */
function apr_doc_case_pid(string $table, mixed $pid): ?string
{
    if (!apr_is_pid($pid)) return null;
    $v = apr_val("SELECT c.pid FROM $table x JOIN cases c ON c.id = x.case_id WHERE x.pid = ?", [$pid]);
    return $v === false ? null : (string)$v;
}

function apr_req_row(array $c, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row('SELECT * FROM requirements WHERE pid = ? AND case_id = ?', [$pid, $c['id']]) : null) ?? apr_fail('not_found');
}

/** קובץ של התיק. קובץ שנמחק — "לא נמצא", אלא אם $deletedOk. */
function apr_file_row(array $c, mixed $pid, bool $deletedOk = false): array
{
    $f = (apr_is_pid($pid) ? apr_row('SELECT * FROM files WHERE pid = ? AND case_id = ?', [$pid, $c['id']]) : null) ?? apr_fail('not_found');
    if ($f['deleted_at'] !== null && !$deletedOk) apr_fail('not_found');
    return $f;
}

/** התיק נבדק לפני הטרנזקציה; בתוכה בודקים שוב שלא נסגר בינתיים. */
function apr_case_still_open(array $c): void
{
    if (apr_val('SELECT status FROM cases WHERE id = ? AND deleted_at IS NULL', [$c['id']]) === 'closed') apr_fail('case_closed');
}

// ── מצב הדרישה ────────────────────────────────────────────────

/** מספר הגרסה הנוכחית של דרישה: הגבוהה ביותר שלא נדחתה ושהקובץ שלה לא נמחק. null — אין קובץ. */
function apr_req_current(int $reqId): ?int
{
    $v = apr_val("SELECT MAX(rf.version_no) FROM requirement_files rf JOIN files f ON f.id = rf.file_id
        WHERE rf.requirement_id = ? AND rf.relation = 'version' AND rf.review_state <> 'rejected' AND f.deleted_at IS NULL", [$reqId]);
    return $v === null || $v === false ? null : (int)$v;
}

/** המצב שנגזר מהגרסה הנוכחית ומהנלווים שלה. null — לדרישה אין קובץ פעיל. */
function apr_req_derive(int $reqId): ?string
{
    $cur = apr_req_current($reqId);
    if ($cur === null) return null;
    $states = array_column(apr_rows("SELECT rf.review_state FROM requirement_files rf JOIN files f ON f.id = rf.file_id
        WHERE rf.requirement_id = ? AND rf.version_no = ? AND rf.review_state <> 'rejected' AND f.deleted_at IS NULL", [$reqId, $cur]), 'review_state');
    if (in_array('pending', $states, true)) return 'received';
    return in_array('returned', $states, true) ? 'returned' : 'approved';
}

/**
 * מעביר דרישה ממצב למצב: UPDATE … WHERE status = <הקודם>. אפס שורות — מישהו הקדים, 409.
 * $strict — המעבר חייב להופיע בטבלה (APR_REQ_TRANSITIONS). בלי $strict: חזרה לאחור אחרי דחייה או מחיקת קובץ (§6, §11).
 */
function apr_req_move(array $r, string $to, ?string $reason, ?int $by, bool $strict = true): void
{
    if ($r['status'] === $to) {
        if ($to !== 'received') return;                      // עוד קובץ לדרישה שממתינה לבדיקה
    } elseif ($strict && !apr_req_can($r['status'], $to)) apr_fail('req_transition');
    $n = apr_exec('UPDATE requirements SET status = ?, status_reason = ?, status_by = ?, status_at = ?, updated_at = ? WHERE id = ? AND status = ? AND split_at IS NULL',
        [$to, $reason, $by, apr_now(), apr_now(), $r['id'], $r['status']]);
    if ($n !== 1) apr_fail('req_changed');
    apr_requests_on_req_change((int)$r['id']);              // בקשה שכל הדרישות שלה נסגרו — "הושלמה" (07 §6)
}

/** משייך קובץ לדרישה (גרסה חדשה או נלווה), והדרישה עוברת ל-received. בתוך טרנזקציה. מחזיר [pid של השיוך, מספר גרסה, relation]. */
function apr_req_link(array $c, array $r, int $fileId, string $relation, ?int $by): array
{
    if ($r['split_at'] !== null) apr_fail('req_state');
    if ($r['status'] !== 'received' && !apr_req_can($r['status'], 'received')) apr_fail('req_state');
    if (apr_val("SELECT 1 FROM requirement_files WHERE requirement_id = ? AND file_id = ? AND review_state <> 'rejected'", [$r['id'], $fileId])) apr_fail('file_linked');
    $cur = apr_req_current((int)$r['id']);
    if ($cur === null) $relation = 'version';               // הקובץ הראשון הוא תמיד גרסה
    $ver = $relation === 'version' ? (int)apr_val("SELECT COALESCE(MAX(version_no), 0) + 1 FROM requirement_files WHERE requirement_id = ? AND relation = 'version'", [$r['id']]) : $cur;
    $pid = apr_pid();
    apr_insert('requirement_files', ['pid' => $pid, 'case_id' => $c['id'], 'requirement_id' => $r['id'], 'file_id' => $fileId, 'relation' => $relation, 'version_no' => $ver,
        'review_state' => 'pending', 'status_before' => $r['status'], 'created_by' => $by, 'created_at' => apr_now()]);
    apr_req_move($r, 'received', null, $by);
    return [$pid, $ver, $relation];
}

/** אחרי דחייה או מחיקה של קובץ: המצב שנגזר ממה שנשאר; אם לא נשאר קובץ — המצב שלפני (required / requested). */
function apr_req_fallback(array $r, string $before, ?int $by): string
{
    $to = apr_req_derive((int)$r['id']) ?? (in_array($before, ['required', 'requested'], true) ? $before : 'required');
    if ($to !== $r['status']) apr_req_move($r, $to, null, $by, false);
    return $to;
}

// ── העלאה ─────────────────────────────────────────────────────

const APR_UPLOAD_RATE = [60, 600];                 // העלאות למשתמש, בחלון של שניות
const APR_QUOTA_CASE = 2 * 1024 ** 3;              // נפח הקבצים של תיק
const APR_QUOTA_OFFICE = 20 * 1024 ** 3;           // נפח הקבצים של משרד

/**
 * קצב ומכסה. $rateKey — מי מעלה (מזהה משתמש, או "p<קישור>" לנמען בפורטל). $count — לספור את הניסיון (פעם אחת לבקשה, לפני קריאת הגוף). הנפח כולל קבצים שנמחקו מחיקה רכה: הם עדיין
 * בדיסק (מחזור החיים — פרוסה 21).
 */
function apr_upload_allowed(array $c, string $rateKey, int $size, bool $count = true, ?array $rate = null): void
{
    [$max, $window] = $rate ?? $GLOBALS['apr_upload_rate'] ?? APR_UPLOAD_RATE;
    if ($count && !apr_limit('upload:' . $rateKey, $max, $window)) apr_fail('upload_rate');
    [$qc, $qo] = $GLOBALS['apr_quota'] ?? [APR_QUOTA_CASE, APR_QUOTA_OFFICE];
    $need = max($size, 1);                             // לפני קריאת הגוף הגודל עוד לא ידוע: נכשלים רק אם כבר אין מקום לבית אחד
    if ((int)apr_val('SELECT COALESCE(SUM(size), 0) FROM files WHERE case_id = ?', [$c['id']]) + $need > $qc) apr_fail('quota_case');
    if ((int)apr_val('SELECT COALESCE(SUM(size), 0) FROM files WHERE office_id = ?', [$c['office_id']]) + $need > $qo) apr_fail('quota_office');
}

/** מזהה פעולה מהדפדפן: אותיות, ספרות, מקף וקו תחתון. נשמר עם מזהה המשתמש, כדי שמזהה של אחד לא יפגוש של אחר. */
function apr_upload_key(array $ctx, mixed $v): ?string
{
    if ($v === null || $v === '') return null;
    if (!is_string($v) || preg_match('/^[A-Za-z0-9_-]{8,64}\z/', $v) !== 1) apr_fail('content_invalid');
    return $ctx['user']['id'] . ':' . $v;
}

/**
 * העלאת קובץ לתיק. $q (מהכתובת): case, requirement? (בלעדיו — "קבצים שלא שויכו"), relation (version | attachment),
 * name, key (מזהה פעולה), dup=1 ("להעלות בכל זאת"), source_note, doc_date. הגוף הגולמי נשמר לקובץ זמני בידי api.php —
 * $env['upload'] הוא הנתיב שלו, ורק השרת קובע אותו.
 *
 * הסדר (§4): גודל → סוג לפי התוכן → תוכן פעיל → סורק → כפילות → שמירה → שורה ב-files → מצב הדרישה. כישלון בכל שלב:
 * אין שורה ב-files, והדרישה לא זזה. קובץ שנדחה בגלל תוכנו נרשם ביומן התיק עם ה-hash בלבד.
 */
function apr_file_upload(array $ctx, array $q, array $env): array
{
    $uid = (int)$ctx['user']['id'];
    return apr_file_ingest(fn() => apr_case_get($ctx, $q['case'] ?? null, 'documents'), ['kind' => 'user', 'uid' => $uid, 'channel' => 'internal', 'rate_key' => (string)$uid,
        'key' => fn() => apr_upload_key($ctx, $q['key'] ?? null)], $q, $env);
}

/**
 * הצינור של קובץ נכנס — אחד לכל ערוץ (העלאה פנימית, פורטל). $case — מחזיר את התיק אחרי שבדק מי מעלה (הרשאה בתיק, או
 * אסימון של בקשה); נקרא לפני שנוגעים בגוף הבקשה. $who: kind (user | recipient), uid (או null), channel, rate_key, rate?
 * ([מספר, חלון]), key (מזהה פעולה, כבר עם קידומת של המעלה), request (id של בקשה, לפורטל), audit ([actor, kind]).
 * לנמען: דרישה חובה, כפילות נבדקת רק מול מה שהועלה באותה בקשה, והתשובה אינה חושפת דבר על שאר התיק.
 */
function apr_file_ingest(callable $case, array $who, array $q, array $env): array
{
    $tmp = is_string($env['upload'] ?? null) ? $env['upload'] : '';
    $recipient = $who['kind'] === 'recipient';
    $uid = $who['uid'] ?? null;
    $actor = $who['audit'] ?? [null, 'user'];
    try {
        $c = $case();
        foreach (['rate_key', 'request'] as $lazy) {        // בפורטל הקישור ידוע רק אחרי ש-$case אימת את האסימון
            if (($who[$lazy] ?? null) instanceof Closure) $who[$lazy] = ($who[$lazy])();
        }
        apr_upload_allowed($c, $who['rate_key'], 0, true, $who['rate'] ?? null);
        if (isset($who['limit'])) ($who['limit'])(0);       // פורטל: תקרת הנפח ומספר הקבצים של הבקשה
        // רק עכשיו — אחרי זהות (או אסימון), CSRF, הרשאה ומכסה — קוראים את גוף הבקשה לדיסק (api.php מוסר את הקורא)
        if (isset($env['upload_reader'])) {
            $env = ($env['upload_reader'])() + $env;
            $tmp = is_string($env['upload'] ?? null) ? $env['upload'] : '';
        }
        if (($env['upload_error'] ?? '') !== '') $env['upload_error'] === 'file_too_large' ? apr_fail_size() : apr_fail($env['upload_error']);
        if ($tmp === '' || !is_file($tmp)) apr_fail('file_missing');
        $size = (int)filesize($tmp);
        if ($size > apr_file_max()) apr_fail_size();
        apr_upload_allowed($c, $who['rate_key'], $size, false);
        $key = ($who['key'] ?? null) instanceof Closure ? ($who['key'])() : ($who['key'] ?? null);
        $relation = apr_clean_choice($q['relation'] ?? null, ['version', 'attachment']) ?? 'version';
        $name = apr_file_clean_name($q['name'] ?? null);
        $note = $recipient ? null : apr_clean_text($q['source_note'] ?? '', 200);
        $docDate = $recipient ? null : apr_clean_date($q['doc_date'] ?? null);
        $reqPid = $q['requirement'] ?? null;
        if ($reqPid !== null && $reqPid !== '') apr_req_row($c, $reqPid);       // דרישה של תיק אחר — 404, לפני שנוגעים בקובץ
        else $reqPid = null;
        if ($recipient && $reqPid === null) apr_fail('not_found');
        // העלאה חוזרת עם אותו מזהה פעולה — בלי לעבד את הקובץ שוב
        if ($key !== null && ($old = apr_row('SELECT pid, case_id FROM files WHERE idempotency_key = ?', [$key]))) {
            if ((int)$old['case_id'] !== (int)$c['id']) apr_fail('content_invalid');
            return ['file' => $old['pid'], 'repeat' => true];
        }
        $sha = (string)hash_file('sha256', $tmp);
        try {
            $info = apr_file_sniff($tmp);
            $scan = apr_file_scan($tmp);
            if ($scan === 'rejected') throw new AprFileRejected('file_infected', 'scanner');
        } catch (AprFileRejected $e) {
            apr_tx(fn() => apr_case_audit($c, 'file.rejected', 'file', $c['pid'], ['sha256' => $sha, 'size' => $size, 'why' => $e->why] + ($recipient ? ['channel' => 'portal'] : []), null, $actor[0], $actor[1]));
            throw $e;
        }
        [$ext] = APR_FILE_TYPES[$info['mime']];
        $stored = null;
        try {
            return apr_tx(function () use ($c, $q, $who, $recipient, $actor, $tmp, $sha, $size, $info, $scan, $ext, $key, $relation, $name, $note, $docDate, $reqPid, $uid, &$stored) {
                apr_case_still_open($c);
                if (isset($who['recheck'])) ($who['recheck'])();     // פורטל: הקישור והבקשה עדיין בתוקף, בתוך הטרנזקציה
                if ($key !== null && ($old = apr_row('SELECT pid FROM files WHERE idempotency_key = ? AND case_id = ?', [$key, $c['id']]))) return ['file' => $old['pid'], 'repeat' => true];
                $r = $reqPid !== null ? apr_req_row($c, $reqPid) : null;
                if ($recipient) {
                    // לנמען: אותו קובץ שכבר העלה בבקשה הזו לאותה דרישה — "כבר התקבל". שום בדיקה מול שאר התיק: התשובה הייתה חושפת מה יש בו.
                    $d = apr_row("SELECT f.pid FROM files f JOIN requirement_files rf ON rf.file_id = f.id AND rf.requirement_id = ? AND rf.review_state <> 'rejected'
                        WHERE f.request_id = ? AND f.sha256 = ? AND f.deleted_at IS NULL ORDER BY f.id LIMIT 1", [$r['id'], $who['request'], $sha]);
                    if ($d) return ['file' => $d['pid'], 'repeat' => true];
                    if ($r['status'] === 'approved') apr_fail('req_state');           // דרישה שאושרה — הנמען אינו פותח אותה מחדש
                    $relation = $r['status'] === 'received' ? 'attachment' : 'version';
                } elseif (($q['dup'] ?? '') !== '1' && ($d = apr_row('SELECT * FROM files WHERE case_id = ? AND sha256 = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$c['id'], $sha]))) {
                    // כפילות — בתוך התיק בלבד. לעולם לא בין תיקים: ההתרעה עצמה הייתה חושפת שהקובץ קיים במקום אחר.
                    return ['duplicate' => ['file' => $d['pid'], 'name' => $d['original_name'], 'received_at' => $d['received_at'],
                        'requirement' => apr_val("SELECT r.title FROM requirement_files rf JOIN requirements r ON r.id = rf.requirement_id WHERE rf.file_id = ? AND rf.review_state <> 'rejected' ORDER BY rf.id LIMIT 1", [$d['id']]) ?: null]];
                }
                apr_upload_allowed($c, $who['rate_key'], $size, false);          // שוב, בתוך הטרנזקציה: שתי העלאות במקביל לא עוברות יחד את התקרה
                if (isset($who['limit'])) ($who['limit'])($size);
                $stored = apr_file_store($tmp);
                if (isset($GLOBALS['apr_after_store'])) ($GLOBALS['apr_after_store'])();   // בדיקות: כישלון אחרי השמירה לדיסק
                $pid = apr_pid();
                $id = apr_insert('files', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'sha256' => $sha, 'size' => $size, 'mime' => $info['mime'], 'ext' => $ext,
                    'original_name' => $name, 'storage_key' => $stored, 'uploader_kind' => $who['kind'], 'uploader_id' => $uid, 'channel' => $who['channel'], 'source_note' => $note, 'doc_date' => $docDate,
                    'received_at' => apr_now(), 'scan_state' => $scan, 'pages' => $info['pages'], 'is_encrypted' => (int)$info['encrypted'], 'idempotency_key' => $key, 'request_id' => $who['request'] ?? null]);
                $out = ['file' => $pid];
                $meta = ['mime' => $info['mime'], 'size' => $size] + ($recipient ? ['channel' => 'portal'] : []);
                if ($r) {
                    [$out['link'], $out['version'], $out['relation']] = apr_req_link($c, $r, $id, $relation, $uid);
                    $meta += ['requirement' => $r['pid'], 'version' => $out['version'], 'relation' => $out['relation']];
                }
                apr_case_audit($c, 'file.upload', 'file', $pid, $meta, null, $actor[0], $actor[1]);
                apr_case_touch($c);
                return $out;
            });
        } catch (Throwable $e) {
            if ($stored !== null) @unlink(apr_file_path($stored));   // הטרנזקציה בוטלה — לא משאירים קובץ יתום
            throw $e;
        }
    } finally {
        if ($tmp !== '' && is_file($tmp)) @unlink($tmp);
    }
}

/** שיוך קובץ שכבר בתיק לדרישה ("קבצים שלא שויכו", או אותו קובץ לדרישה נוספת — מקרה קצה 9). */
function apr_file_assign(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, apr_doc_case_pid('files', $in['file'] ?? null), 'documents');
    $relation = apr_clean_choice($in['relation'] ?? null, ['version', 'attachment']) ?? 'version';
    return apr_tx(function () use ($ctx, $c, $in, $relation) {
        apr_case_still_open($c);
        $f = apr_file_row($c, $in['file'] ?? null);
        $r = apr_req_row($c, $in['requirement'] ?? null);
        [$link, $ver, $rel] = apr_req_link($c, $r, (int)$f['id'], $relation, (int)$ctx['user']['id']);
        apr_case_audit($c, 'file.assign', 'file', $f['pid'], ['requirement' => $r['pid'], 'version' => $ver, 'relation' => $rel]);
        apr_case_touch($c);
        return ['link' => $link, 'version' => $ver];
    });
}

/** פרטי קובץ: שם להצגה, תאריך המסמך, "מאיפה המסמך", תגיות. */
function apr_file_update(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, apr_doc_case_pid('files', $in['file'] ?? null), 'documents');
    $set = [];
    if (array_key_exists('name', $in)) $set['original_name'] = apr_file_clean_name($in['name']);
    if (array_key_exists('doc_date', $in)) $set['doc_date'] = apr_clean_date($in['doc_date']);
    if (array_key_exists('source_note', $in)) $set['source_note'] = apr_clean_text($in['source_note'], 200);
    $tags = null;
    if (array_key_exists('tags', $in)) {
        if (!is_array($in['tags']) || count($in['tags']) > 10) apr_fail('content_invalid');
        $tags = array_values(array_unique(array_filter(array_map(fn($t) => apr_clean_text($t, 30), $in['tags']), fn($t) => $t !== null)));
    }
    return apr_tx(function () use ($c, $in, $set, $tags) {
        apr_case_still_open($c);
        $f = apr_file_row($c, $in['file'] ?? null);
        if ($set) apr_exec('UPDATE files SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ?', [...array_values($set), $f['id']]);
        if ($tags !== null) {
            apr_exec('DELETE FROM file_tags WHERE file_id = ?', [$f['id']]);
            foreach ($tags as $t) apr_insert('file_tags', ['file_id' => $f['id'], 'tag' => $t]);
        }
        if (!$set && $tags === null) return [];
        apr_case_audit($c, 'file.update', 'file', $f['pid'], ['fields' => [...array_keys($set), ...($tags !== null ? ['tags'] : [])]]);
        return [];
    });
}

/** מחיקה רכה, בנימוק. דרישה שנשארה בלי קובץ חוזרת ל-required (מקרה קצה 8); מזהה נכס שאומת מול הקובץ חוזר ל"לבדיקה". */
function apr_file_delete(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, apr_doc_case_pid('files', $in['file'] ?? null), 'review_docs');
    $reason = apr_reason($in['reason'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $reason) {
        apr_case_still_open($c);
        $f = apr_file_row($c, $in['file'] ?? null);
        $uid = (int)$ctx['user']['id'];
        if (apr_exec('UPDATE files SET deleted_at = ?, deleted_by = ?, delete_reason = ? WHERE id = ? AND deleted_at IS NULL', [apr_now(), $uid, $reason, $f['id']]) !== 1) apr_fail('not_found');
        $back = [];
        foreach (apr_rows("SELECT DISTINCT requirement_id FROM requirement_files WHERE file_id = ? AND review_state <> 'rejected'", [$f['id']]) as $l) {
            $r = apr_row('SELECT * FROM requirements WHERE id = ?', [$l['requirement_id']]);
            if ($r['split_at'] !== null || $r['status'] === 'not_relevant') continue;
            $back[$r['pid']] = apr_req_fallback($r, 'required', $uid);
        }
        apr_exec("UPDATE case_identifiers SET state = 'needs_check' WHERE case_id = ? AND source_ref = ? AND state = 'verified'", [$c['id'], $f['pid']]);
        apr_facts_on_file_delete($c, $f);                    // נתון מאומת שנשען על הקובץ חוזר ל"דורש בירור" (10, מקרה קצה 6)
        apr_case_audit($c, 'file.delete', 'file', $f['pid'], ['requirements' => $back], $reason);
        apr_case_touch($c);
        return ['requirements' => $back];
    });
}

// ── בדיקה ואישור ──────────────────────────────────────────────

/**
 * בדיקת קובץ בדרישה: link (השיוך), do = approve | return | reject.
 * approve — שלוש התיבות (chk_readable, chk_complete, chk_matches) מסומנות. return — return_code מהרשימה + return_reason
 * (ההסבר לנמען; חובה ל"אחר"). reject — reason; הקובץ מנותק מהדרישה ונשאר בתיק. עוד: note (פנימי), doc_date.
 * שני בודקים על אותו קובץ: רק הראשון מצליח; השני מקבל 409 already_reviewed עם שם הבודק והתוצאה.
 */
function apr_file_review(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, apr_doc_case_pid('requirement_files', $in['link'] ?? null), 'review_docs');
    $do = apr_clean_choice($in['do'] ?? null, ['approve', 'return', 'reject']) ?? apr_fail('content_invalid');
    $chk = [];
    foreach (['chk_readable', 'chk_complete', 'chk_matches'] as $k) $chk[$k] = is_bool($in[$k] ?? null) ? (int)$in[$k] : null;
    if ($do === 'approve' && array_sum(array_map('intval', $chk)) !== 3) apr_fail('review_incomplete');
    $note = apr_clean_text($in['note'] ?? '', 600);
    $code = $do === 'return' ? (apr_clean_choice($in['return_code'] ?? null, APR_RETURN_CODES) ?? apr_fail('return_code_required')) : null;
    $explain = $do === 'return' ? apr_clean_text($in['return_reason'] ?? '', 600) : null;
    if ($code === 'other' && $explain === null) apr_fail('reason_required');
    $reason = $do === 'reject' ? apr_reason($in['reason'] ?? null) : null;
    $docDate = array_key_exists('doc_date', $in) ? [apr_clean_date($in['doc_date'])] : null;
    $state = ['approve' => 'approved', 'return' => 'returned', 'reject' => 'rejected'][$do];
    return apr_tx(function () use ($ctx, $c, $in, $do, $chk, $note, $code, $explain, $reason, $docDate, $state) {
        apr_case_still_open($c);
        $uid = (int)$ctx['user']['id'];
        $l = apr_row(APR_LINK_SELECT . ' WHERE rf.pid = ? AND rf.case_id = ?', [$in['link'], $c['id']]) ?? apr_fail('not_found');
        $r = apr_row('SELECT * FROM requirements WHERE id = ? AND case_id = ?', [$l['requirement_id'], $c['id']]) ?? apr_fail('not_found');
        if ($l['file_deleted'] !== null) apr_fail('not_found');
        // בודקים רק את הגרסה הנוכחית (ונלווים שלה); גרסה שהוחלפה נשארת בהיסטוריה כמו שהיא
        if ((int)$l['version_no'] !== apr_req_current((int)$r['id']) && $l['review_state'] === 'pending') apr_fail('req_state');
        $n = apr_exec("UPDATE requirement_files SET review_state = ?, chk_readable = ?, chk_complete = ?, chk_matches = ?, review_note = ?, return_code = ?, return_reason = ?,
            reviewed_by = ?, reviewed_at = ? WHERE id = ? AND review_state = 'pending'", [$state, $chk['chk_readable'], $chk['chk_complete'], $chk['chk_matches'], $note, $code, $explain ?? $reason, $uid, apr_now(), $l['id']]);
        if ($n !== 1) {
            $now = apr_row('SELECT rf.review_state, rf.reviewed_at, u.name FROM requirement_files rf LEFT JOIN users u ON u.id = rf.reviewed_by WHERE rf.id = ?', [$l['id']]);
            throw new AprReviewed(['by' => $now['name'], 'state' => $now['review_state'], 'at' => $now['reviewed_at']]);
        }
        if ($docDate !== null) apr_exec('UPDATE files SET doc_date = ? WHERE id = ?', [$docDate[0], $l['file_id']]);
        if ($do === 'reject') $to = apr_req_fallback($r, $l['status_before'], $uid);
        else {
            $to = apr_req_derive((int)$r['id']) ?? apr_fail('req_state');
            apr_req_move($r, $to, $do === 'return' ? $explain : null, $uid);
        }
        apr_case_audit($c, 'file.' . $do, 'file', (string)apr_val('SELECT pid FROM files WHERE id = ?', [$l['file_id']]),
            ['requirement' => $r['pid'], 'version' => (int)$l['version_no'], 'status' => $to] + ($code !== null ? ['code' => $code] : []), $reason ?? $explain);
        apr_case_touch($c);
        return ['status' => $to, 'review_state' => $state];
    });
}

// ── ניהול הרשימה ──────────────────────────────────────────────

/**
 * שינוי מצב יזום של דרישה: requirement, status, reason.
 *   not_relevant (מ-required / requested / returned) — review_docs, עם נימוק.
 *   required מ-not_relevant (ביטול הסימון) — review_docs, עם נימוק. required מ-requested (הבקשה בוטלה) — documents.
 *   received מ-approved ("פתיחה לבדיקה מחדש") — review_docs, עם נימוק: הגרסה הנוכחית חוזרת ל-pending.
 * שאר המעברים שבטבלה קורים רק מאירוע: קובץ חדש, בדיקה, או שליחת בקשה (פרוסה 07).
 */
function apr_requirement_status(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, apr_doc_case_pid('requirements', $in['requirement'] ?? null));   // ההרשאה תלויה במעבר — נבדקת למטה
    $to = apr_clean_choice($in['status'] ?? null, APR_REQ_STATUSES) ?? apr_fail('status_invalid');
    return apr_tx(function () use ($ctx, $c, $in, $to) {
        apr_case_still_open($c);
        $r = apr_req_row($c, $in['requirement'] ?? null);
        $from = $r['status'];
        if ($r['split_at'] !== null) apr_fail('req_state');
        if ($from === $to) return ['status' => $to];            // אין שינוי — אין שורת יומן
        // רק מעבר יזום; שהמעבר עצמו בטבלה — נבדק ב-apr_req_move
        $manual = ($to === 'not_relevant') || ($to === 'required') || ($from === 'approved' && $to === 'received');
        if (!$manual) apr_fail('req_transition');
        $review = !($from === 'requested' && $to === 'required');
        if (empty($c['perms'][$review ? 'review_docs' : 'documents'])) apr_fail('forbidden');
        $reason = $review ? apr_reason($in['reason'] ?? null) : apr_clean_text($in['reason'] ?? '', 500);
        $uid = (int)$ctx['user']['id'];
        if ($to === 'received') {
            apr_exec("UPDATE requirement_files SET review_state = 'pending' WHERE requirement_id = ? AND version_no = ? AND review_state = 'approved'", [$r['id'], apr_req_current((int)$r['id'])]);
        }
        apr_req_move($r, $to, $reason, $uid);
        apr_case_audit($c, 'requirement.status', 'requirement', $r['pid'], ['from' => $from, 'to' => $to], $reason);
        apr_case_touch($c);
        return ['status' => $to];
    });
}

/** סדר וקבוצה: items = [{requirement, group?}] בסדר הרצוי — הן עולות לראש הרשימה, והשאר נשארות אחריהן בסדרן. כל הדרישות — של התיק הזה. */
function apr_requirement_order(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'documents');
    $items = $in['items'] ?? null;
    if (!is_array($items) || !$items || count($items) > 400) apr_fail('content_invalid');
    $groups = array_column(apr_case_version_content((int)$c['profile_version_id'])['requirements']['groups'], 'key');
    return apr_tx(function () use ($c, $items, $groups) {
        apr_case_still_open($c);
        $listed = [];
        foreach (array_values($items) as $i => $it) {
            if (!is_array($it)) apr_fail('content_invalid');
            $r = apr_req_row($c, $it['requirement'] ?? null);
            $group = $r['group_key'];
            if (array_key_exists('group', $it)) {
                $group = $it['group'] === null || $it['group'] === '' ? null : (is_string($it['group']) && in_array($it['group'], $groups, true) ? $it['group'] : apr_fail('content_invalid'));
            }
            apr_exec('UPDATE requirements SET sort = ?, group_key = ?, updated_at = ? WHERE id = ?', [($i + 1) * 10, $group, apr_now(), $r['id']]);
            $listed[$r['id']] = true;
        }
        $n = count($listed);
        foreach (apr_rows('SELECT id FROM requirements WHERE case_id = ? ORDER BY sort, id', [$c['id']]) as $rest) {
            if (!isset($listed[$rest['id']])) apr_exec('UPDATE requirements SET sort = ? WHERE id = ?', [++$n * 10, $rest['id']]);
        }
        apr_case_audit($c, 'requirement.order', 'case', null, ['count' => count($items)]);
        return [];
    });
}

/**
 * פיצול לגורמים: requirement, parts = [{party, title?}] — שניים לפחות, כל אחד לגורם אחר של התיק. נוצרות דרישות חדשות
 * (split_from_id), והמקורית נסגרת כ"פוצלה" ואינה נספרת. רק דרישה שעוד לא נקלט בה דבר (required).
 */
function apr_requirement_split(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, apr_doc_case_pid('requirements', $in['requirement'] ?? null), 'documents');
    $parts = $in['parts'] ?? null;
    if (!is_array($parts) || count($parts) < 2 || count($parts) > 10) apr_fail('split_parts');
    return apr_tx(function () use ($c, $in, $parts) {
        apr_case_still_open($c);
        $r = apr_req_row($c, $in['requirement'] ?? null);
        $rows = [];
        foreach ($parts as $p) {
            $party = apr_party_row($c, $p['party'] ?? null);
            if (isset($rows[$party['id']])) apr_fail('split_parts');
            $name = (string)apr_val('SELECT name FROM contacts WHERE id = ?', [$party['contact_id']]);
            $rows[$party['id']] = [$party, array_key_exists('title', $p) && $p['title'] !== '' && $p['title'] !== null ? apr_clean_name($p['title'], 160) : mb_substr($r['title'] . ' — ' . $name, 0, 160)];
        }
        $now = apr_now();
        if (apr_exec("UPDATE requirements SET split_at = ?, updated_at = ? WHERE id = ? AND split_at IS NULL AND status = 'required'", [$now, $now, $r['id']]) !== 1) apr_fail('req_state');
        $new = [];
        foreach (array_values($rows) as $i => [$party, $title]) {
            $pid = apr_pid();
            apr_insert('requirements', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'origin' => 'manual', 'group_key' => $r['group_key'], 'doc_type' => $r['doc_type'],
                'title' => $title, 'explain_recipient' => $r['explain_recipient'], 'explain_internal' => $r['explain_internal'], 'priority' => $r['priority'], 'mandatory' => $r['mandatory'],
                'sort' => (int)$r['sort'] + $i + 1, 'party_kind' => $party['kind'], 'party_id' => $party['id'], 'due_at' => $r['due_at'], 'split_from_id' => $r['id'], 'created_at' => $now, 'updated_at' => $now]);
            $new[] = $pid;
        }
        apr_case_audit($c, 'requirement.split', 'requirement', $r['pid'], ['into' => $new]);
        apr_case_touch($c);
        return ['requirements' => $new];
    });
}

// ── תצוגה ─────────────────────────────────────────────────────

function apr_file_out(array $f, array $tags = []): array
{
    $gone = $f['deleted_at'] !== null;
    return ['pid' => $f['pid'], 'name' => $f['original_name'], 'mime' => $f['mime'], 'ext' => $f['ext'], 'kind' => APR_FILE_TYPES[$f['mime']][1] ?? 'other', 'size' => (int)$f['size'],
        'channel' => $f['channel'], 'source_note' => $f['source_note'], 'doc_date' => $f['doc_date'], 'received_at' => $f['received_at'],
        'uploader' => $f['uploader_name'] ?? null, 'uploader_kind' => $f['uploader_kind'], 'scan_state' => $f['scan_state'], 'pages' => $f['pages'] === null ? null : (int)$f['pages'],
        'encrypted' => (bool)$f['is_encrypted'], 'tags' => $tags, 'deleted' => $gone,
        'preview' => !$gone && in_array($f['mime'], APR_FILE_PREVIEW, true), 'thumb' => !$gone && in_array($f['mime'], APR_FILE_THUMB, true) && function_exists('imagecreatetruecolor')];
}

/**
 * לשונית "דרישות וקבצים": הדרישות עם הקבצים, הגרסאות והבדיקות שלהן; קבצים שלא שויכו; ומה מותר לי.
 * דרישה שפוצלה מוחזרת עם split = true — המסך מציג אותה בנפרד, והיא אינה נספרת.
 */
function apr_case_docs(array $ctx, array $q): array
{
    $c = apr_case_get($ctx, $q['case'] ?? null);
    $files = array_column(apr_rows('SELECT f.*, COALESCE(u.name, ct.name) AS uploader_name FROM files f LEFT JOIN users u ON u.id = f.uploader_id
        LEFT JOIN requests q ON q.id = f.request_id LEFT JOIN case_parties cp ON cp.id = q.party_id LEFT JOIN contacts ct ON ct.id = cp.contact_id WHERE f.case_id = ? ORDER BY f.id', [$c['id']]), null, 'id');
    $tags = [];
    foreach (apr_rows('SELECT t.file_id, t.tag FROM file_tags t JOIN files f ON f.id = t.file_id WHERE f.case_id = ? ORDER BY t.tag', [$c['id']]) as $t) $tags[$t['file_id']][] = $t['tag'];
    $links = [];
    $assigned = [];
    foreach (apr_rows('SELECT rf.*, u.name AS reviewer FROM requirement_files rf LEFT JOIN users u ON u.id = rf.reviewed_by WHERE rf.case_id = ? ORDER BY rf.version_no DESC, rf.id DESC', [$c['id']]) as $l) {
        $links[$l['requirement_id']][] = $l;
        if ($l['review_state'] !== 'rejected') $assigned[$l['file_id']] = true;
    }
    $reqs = [];
    foreach (apr_rows('SELECT r.*, cp.pid AS party_pid, u.name AS status_by_name, sf.pid AS split_from FROM requirements r LEFT JOIN case_parties cp ON cp.id = r.party_id
        LEFT JOIN users u ON u.id = r.status_by LEFT JOIN requirements sf ON sf.id = r.split_from_id WHERE r.case_id = ? ORDER BY r.sort, r.id', [$c['id']]) as $r) {
        $cur = apr_req_current((int)$r['id']);
        $reqs[] = apr_requirement_out($r) + ['status_reason' => $r['status_reason'], 'status_at' => $r['status_at'], 'status_by' => $r['status_by_name'], 'split' => $r['split_at'] !== null,
            'split_from' => $r['split_from'], 'files' => array_map(fn($l) => ['link' => $l['pid'], 'relation' => $l['relation'], 'version' => (int)$l['version_no'],
                'current' => $cur !== null && (int)$l['version_no'] === $cur && $l['review_state'] !== 'rejected', 'review_state' => $l['review_state'],
                'chk' => ['readable' => $l['chk_readable'] === null ? null : (bool)$l['chk_readable'], 'complete' => $l['chk_complete'] === null ? null : (bool)$l['chk_complete'], 'matches' => $l['chk_matches'] === null ? null : (bool)$l['chk_matches']],
                'review_note' => $l['review_note'], 'return_code' => $l['return_code'], 'return_reason' => $l['return_reason'], 'reviewed_by' => $l['reviewer'], 'reviewed_at' => $l['reviewed_at'],
                'created_at' => $l['created_at'], 'file' => apr_file_out($files[$l['file_id']], $tags[$l['file_id']] ?? [])], $links[$r['id']] ?? [])];
    }
    return ['case' => ['pid' => $c['pid'], 'number' => $c['number'], 'status' => $c['status']],
        'perms' => ['documents' => !empty($c['perms']['documents']), 'review_docs' => !empty($c['perms']['review_docs'])],
        'limits' => ['max_size' => apr_file_max(), 'types' => array_column(APR_FILE_TYPES, 0), 'scanner' => apr_scanner_bin() !== null || isset($GLOBALS['apr_scanner'])],
        'return_codes' => APR_RETURN_CODES,
        'groups' => apr_case_version_content((int)$c['profile_version_id'])['requirements']['groups'],
        'requirements' => $reqs,
        'unassigned' => array_values(array_map(fn($f) => apr_file_out($f, $tags[$f['id']] ?? []), array_filter($files, fn($f) => $f['deleted_at'] === null && !isset($assigned[$f['id']]))))];
}

/**
 * האם מותר לי לראות את הקובץ הזה של התיק — המקום היחיד שמכריע. היום: כל מי שרואה את התיק (view). שיתוף ברמת פריט (items,
 * פרוסה 05) ייכנס לכאן, וכל מי שמציג קובץ או פרט שלו (הורדה, תמונה ממוזערת, מקור של נתון — פרוסה 10) כבר שואל כאן.
 * $GLOBALS['apr_file_visible'] — תפר לבדיקות, עד שיהיה שיתוף כזה.
 */
function apr_file_visible(array $ctx, array $c, array $f): bool
{
    if (isset($GLOBALS['apr_file_visible'])) return (bool)$GLOBALS['apr_file_visible']($ctx, $c, $f);
    return !empty($c['perms']['view']);
}

/** הורדה / תצוגה מקדימה: file, inline=1. הרשאה: view בתיק (שותף חיצוני ותמיכה — פרוסה 05). קובץ שנמחק — לא נמצא. */
function apr_file_serve(array $ctx, array $q): array
{
    $c = apr_case_get($ctx, apr_doc_case_pid('files', $q['file'] ?? null));
    $f = apr_file_row($c, $q['file'] ?? null);
    if (!apr_file_visible($ctx, $c, $f)) apr_fail('not_found');
    return apr_file_response(apr_file_path($f['storage_key']), $f['mime'], apr_file_download_name($f['original_name'], $f['ext']), ($q['inline'] ?? '') === '1');
}

/** תמונה ממוזערת (JPEG) לתמונה; נוצרת בפעם הראשונה ונשמרת. לסוג אחר, או בלי GD — לא נמצא, והמסך מציג אייקון. */
function apr_file_thumb(array $ctx, array $q): array
{
    $c = apr_case_get($ctx, apr_doc_case_pid('files', $q['file'] ?? null));
    $f = apr_file_row($c, $q['file'] ?? null);
    if (!apr_file_visible($ctx, $c, $f)) apr_fail('not_found');
    $t = apr_row('SELECT * FROM file_thumbs WHERE file_id = ?', [$f['id']]);
    if (!$t || !is_file(apr_file_path($t['storage_key'], 'thumbs'))) {
        $made = apr_thumb_make(apr_file_path($f['storage_key']), $f['mime']) ?? apr_fail('not_found');
        apr_exec('INSERT INTO file_thumbs (file_id, storage_key, w, h) VALUES (?, ?, ?, ?) ON CONFLICT(file_id) DO UPDATE SET storage_key = excluded.storage_key, w = excluded.w, h = excluded.h',
            [$f['id'], $made['key'], $made['w'], $made['h']]);
        $t = ['storage_key' => $made['key']];
    }
    return apr_file_response(apr_file_path($t['storage_key'], 'thumbs'), 'image/jpeg', 'thumb.jpg', true);
}

// ── חיפוש, והלוח הראשי ────────────────────────────────────────

/** התיקים שאני משתתף בהם במשרד הפעיל, עם הרשאה נתונה — הבסיס לכל שאילתה שחוצה תיקים. */
function apr_my_cases_sql(string $perm): string
{
    return "FROM cases c JOIN case_members cm ON cm.case_id = c.id AND cm.user_id = ? AND cm.revoked_at IS NULL
        WHERE c.office_id = ? AND c.deleted_at IS NULL AND json_extract(cm.perms, '$.view') = 1 AND json_extract(cm.perms, '$.$perm') = 1";
}

/** חיפוש: שם קובץ, כותרת דרישה, תגית — **רק בתיקים שאני משתתף בהם**. q, ואופציונלי case. */
function apr_docs_search(array $ctx, array $q): array
{
    $office = apr_office_id($ctx);
    $needle = trim(is_string($q['q'] ?? null) ? mb_substr($q['q'], 0, 100) : '');
    if (mb_strlen($needle) < 2) return ['results' => []];
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($needle)) . '%';
    $mine = 'SELECT c.id ' . apr_my_cases_sql('view');
    $args = [$ctx['user']['id'], $office];
    $only = '';
    if (apr_is_pid($q['case'] ?? null)) { $only = ' AND c.pid = ?'; $args[] = $q['case']; }
    $out = [];
    foreach (apr_rows("SELECT f.pid, f.original_name, f.mime, f.received_at, c.pid AS case_pid, c.number, c.title FROM files f JOIN cases c ON c.id = f.case_id
        WHERE f.deleted_at IS NULL AND f.case_id IN ($mine$only) AND (lower(f.original_name) LIKE ? ESCAPE '\\'
            OR EXISTS (SELECT 1 FROM file_tags t WHERE t.file_id = f.id AND lower(t.tag) LIKE ? ESCAPE '\\')) ORDER BY f.id DESC LIMIT 50", [...$args, $like, $like]) as $f) {
        $out[] = ['type' => 'file', 'case' => ['pid' => $f['case_pid'], 'number' => $f['number'], 'title' => $f['title']], 'file' => $f['pid'], 'title' => $f['original_name'],
            'kind' => APR_FILE_TYPES[$f['mime']][1] ?? 'other', 'at' => $f['received_at']];
    }
    foreach (apr_rows("SELECT r.pid, r.title AS rtitle, r.status, r.updated_at, c.pid AS case_pid, c.number, c.title FROM requirements r JOIN cases c ON c.id = r.case_id
        WHERE r.split_at IS NULL AND r.case_id IN ($mine$only) AND lower(r.title) LIKE ? ESCAPE '\\' ORDER BY r.id DESC LIMIT 50", [...$args, $like]) as $r) {
        $out[] = ['type' => 'requirement', 'case' => ['pid' => $r['case_pid'], 'number' => $r['number'], 'title' => $r['title']], 'requirement' => $r['pid'], 'title' => $r['rtitle'],
            'status' => $r['status'], 'at' => $r['updated_at']];
    }
    return ['results' => $out];
}

/** ללוח הראשי: מסמכים שהתקבלו וממתינים לבדיקה — בתיקים פתוחים שיש לי בהם review_docs. */
function apr_docs_pending(array $ctx): array
{
    $rows = apr_rows("SELECT r.pid, r.title AS rtitle, r.status_at, c.pid AS case_pid, c.number, c.title FROM requirements r JOIN cases c ON c.id = r.case_id
        WHERE r.status = 'received' AND r.split_at IS NULL AND c.status <> 'closed' AND r.case_id IN (SELECT c.id " . apr_my_cases_sql('review_docs') . ')
        ORDER BY r.status_at, r.id LIMIT 100', [$ctx['user']['id'], apr_office_id($ctx)]);
    return ['pending' => array_map(fn($r) => ['requirement' => $r['pid'], 'title' => $r['rtitle'], 'since' => $r['status_at'],
        'case' => ['pid' => $r['case_pid'], 'number' => $r['number'], 'title' => $r['title']]], $rows)];
}

/** קובץ של התיק שיכול לשמש מקור למזהה נכס (04-C3): קיים, של התיק הזה, ולא נמחק. מחזיר את ה-pid, או נכשל. */
function apr_source_file(array $c, mixed $pid): string
{
    if (!apr_is_pid($pid) || !apr_val('SELECT 1 FROM files WHERE pid = ? AND case_id = ? AND deleted_at IS NULL', [$pid, $c['id']])) apr_fail('source_file_required');
    return $pid;
}

/** קבצים זמניים של העלאה שנקטעה (מקרה קצה 1) — נמחקים אחרי שעה. */
function apr_upload_tmp_sweep(): void
{
    foreach (glob(apr_upload_tmp_dir() . '/*') ?: [] as $f) {
        if (is_file($f) && (int)@filemtime($f) < time() - 3600) @unlink($f);
    }
}

/** שגיאת גודל עם הגודל המותר בפועל (השרת עשוי להגביל מתחת ל-25MB). */
function apr_fail_size(): never
{
    throw new AprError('file_too_large', sprintf('הקובץ גדול מדי — עד %dMB', max(1, intdiv(apr_file_max(), 1048576))));
}

// ── בדיקות תקינות (מסך המפתח) ──
apr_health_register('files', 'אחסון קבצים', function () {
    $dir = apr_files_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return ['bad', 'אי אפשר ליצור את data/files'];
    if (!is_writable($dir)) return ['bad', 'אין הרשאת כתיבה ל-data/files'];
    $free = @disk_free_space($dir);
    $max = apr_file_max();
    // הערכים בפועל של השרת: לאפליקציה אין .user.ini משלה (השער של הפלטפורמה יוצר אותו), ולכן אלה אינם בשליטתנו
    $mem = apr_ini_bytes((string)ini_get('memory_limit'));
    $detail = sprintf('קובץ עד %dMB', intdiv($max, 1048576)) . ($free !== false ? sprintf(' · פנוי בדיסק: %.1fGB', $free / (1 << 30)) : '')
        . sprintf(' · בשרת: post_max_size=%s, upload_max_filesize=%s, memory_limit=%s', ini_get('post_max_size') ?: '?', ini_get('upload_max_filesize') ?: '?', ini_get('memory_limit') ?: '?');
    if ($free !== false && $free < 500 * (1 << 20)) return ['warn', $detail . ' — מעט מקום פנוי'];
    if ($max < APR_FILE_MAX) return ['warn', $detail . ' — השרת מגביל את גוף הבקשה (post_max_size) מתחת ל-25MB; המסכים מציגים את התקרה בפועל'];
    if ($mem > 0 && $mem < 3 * $max) return ['warn', $detail . ' — memory_limit נמוך לבדיקת PDF בגודל המרבי'];
    return ['ok', $detail];
});
apr_health_register('scanner', 'סורק קבצים', function () {
    $bin = apr_scanner_bin();
    return $bin !== null ? ['ok', basename($bin) . ' זמין — כל קובץ נסרק בקליטה'] : ['warn', 'אין clamscan בשרת — קבצים נשמרים כ"לא נסרק", ושום מסך אינו כותב "נסרק"'];
});
apr_health_register('thumbs', 'תמונות ממוזערות', fn() => function_exists('imagecreatetruecolor') ? ['ok', 'GD זמין'] : ['warn', 'אין GD — במקום תמונה ממוזערת מוצג אייקון']);