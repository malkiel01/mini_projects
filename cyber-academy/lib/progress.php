<?php
/**
 * התקדמות לומד — תשתית (אפיון: docs/spec/student-progress.md). נפרד לגמרי מתוכנית האקדמיה:
 * curriculum.php לא קורא מכאן ולא מחזיר שדה לומד.
 *
 * - אחסון: קובץ JSON לכל לומד ב-progress_dir — **רק** נתיב מוחלט, קיים, מחוץ לעץ האתר ולא פתוח לכולם (הוראת מנהל 00).
 *   אין ברירת מחדל ואין יצירת תיקייה: בלי תיקייה תקינה — קריאה וכתיבה חסומות (503 progress_unavailable), ושום קובץ
 *   לא נפתח. מפתח sub → learnerId ב-learners.json באותה תיקייה. אין מקור מ-GitHub או מ-Issues.
 * - שלוש קטגוריות לכל מיומנות, שלא מתמזגות: verified (הערכה מאושרת) · reported (דיווח מורה, "ממתין לאימות", לא
 *   משנה סטטוס) · not_assessed (ברירת המחדל, "לא הוערך" — לא 0).
 * - כללי המצב נאכפים כאן, בשרת: academy_progress_validate דוחה קובץ שמפר אותם, וכל כתיבה עוברת אותו.
 * - כל שינוי נרשם ב-audit.log (JSONL): מי, מתי, מה, אילו ראיות — בלי תוכן ראיה.
 */

declare(strict_types=1);

require_once __DIR__ . '/validate-v2.php';
require_once __DIR__ . '/access.php';

const ACADEMY_PROGRESS_SCHEMA = 'progress-1';
const ACADEMY_PROGRESS_STATUSES = ACADEMY_COMPETENCY_STATUSES;
// סטטוס שמעיד על הערכה — דורש הערכה evaluated מאושרת על המיומנות
const ACADEMY_PROGRESS_ASSESSED = ['gap_identified', 'theory_demonstrated', 'practice_demonstrated', 'reassessment_required', 'mastered'];
const ACADEMY_PROGRESS_ASSISTANCE = ['none', 'hint', 'guided', 'full'];
// רמת הסיוע בדיווח מורה — כולל unknown ("לא ידוע"), שלעולם לא נספר כ-none
const ACADEMY_PROGRESS_REPORT_ASSISTANCE = ['none', 'hint', 'guided', 'full', 'unknown'];

final class AcademyProgressError extends RuntimeException {
    public function __construct(string $m, public readonly int $http = 422, public readonly string $errorCode = 'progress_invalid',
                                public readonly array $errors = [], public readonly ?string $reason = null) {
        parent::__construct($m);
    }
}

// ---------------------------------------------------------------- אחסון

/**
 * התיקייה של נתוני הלומדים — או חריגה (503 progress_unavailable + reason), בלי נתיב בהודעה:
 *   ריק → progress_dir_missing · לא מוחלט → progress_dir_not_absolute · בתוך עץ האתר (גם דרך realpath / קישור
 *   סימבולי, או עם ..) → progress_dir_unsafe · לא קיימת / לא תיקייה → progress_dir_missing ·
 *   קריאה או כתיבה לכולם (o+r / o+w) → progress_dir_insecure.
 * אין ברירת מחדל, ולא נוצרת תיקייה.
 */
function academy_progress_dir(array $config): string {
    $fail = fn(string $reason) => new AcademyProgressError('נתוני ההתקדמות אינם זמינים — אחסון לא מוגדר כראוי', 503, 'progress_unavailable', [], $reason);
    $d = trim((string) ($config['progress_dir'] ?? ''));
    if ($d === '') throw $fail('progress_dir_missing');
    if (!str_starts_with($d, '/')) throw $fail('progress_dir_not_absolute');
    if (preg_match('~(^|/)\.\.(/|$)~', $d) || academy_path_in_webroot($d)) throw $fail('progress_dir_unsafe');
    $real = realpath($d);
    if ($real === false || !is_dir($real)) throw $fail('progress_dir_missing');
    if (academy_path_in_webroot($real)) throw $fail('progress_dir_unsafe');
    if ((fileperms($real) & 0o006) !== 0) throw $fail('progress_dir_insecure');
    return $real;
}

/** מזהה לומד פנימי לפי sub של הפלטפורמה, או null אם אינו רשום. */
function academy_progress_learner_id(string $dir, string $sub): ?string {
    $id = academy_progress_index($dir)[$sub] ?? null;
    return is_string($id) && preg_match(ACADEMY_V2_ID, $id) ? $id : null;
}

/** learners.json — [] אם אינו קיים; קובץ פגום → חריגה (לא "ריק"). */
function academy_progress_index(string $dir): array {
    return _ap_read_json("$dir/learners.json") ?? [];
}

/**
 * קורא JSON של אחסון ההתקדמות. null = **אין קובץ** (מותר). קובץ שקיים ולא נקרא / לא JSON / לא אובייקט →
 * 503 progress_unavailable / progress_file_corrupt — לעולם לא מתייחסים אליו כריק, ולכן גם לא דורסים אותו (F1).
 */
function _ap_read_json(string $f): ?array {
    if (!file_exists($f)) return null;
    $raw = is_file($f) ? @file_get_contents($f) : false;
    $d = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($d) || ($d !== [] && array_is_list($d))) {
        throw new AcademyProgressError('קובץ התקדמות פגום', 503, 'progress_unavailable', [], 'progress_file_corrupt');
    }
    return $d;
}

/** הקובץ של הלומד, או null כשאין (לא שגיאה). מזהה לא תקין — null, בלי לגעת בדיסק. */
function academy_progress_load(string $dir, string $learnerId): ?array {
    if (!preg_match(ACADEMY_V2_ID, $learnerId)) return null;
    return _ap_read_json("$dir/learner-$learnerId.json");
}

function academy_progress_empty(string $learnerId): array {
    return ['schema' => ACADEMY_PROGRESS_SCHEMA, 'learnerId' => $learnerId, 'updatedAt' => null,
            'skills' => [], 'lessons' => [], 'assessments' => [], 'evidence' => [], 'reports' => []];
}

function academy_progress_save(string $dir, array $p): void {
    academy_progress_write_atomic("$dir/learner-{$p['learnerId']}.json", academy_progress_encode($p));
}

/** JSON בלי כשל שקט (F6): כשל קידוד → חריגה, ולא כותבים קובץ ריק. */
function academy_progress_encode(array $d, int $flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT): string {
    try {
        return json_encode($d, $flags | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new AcademyProgressError('קידוד ההתקדמות נכשל', 500, 'internal');
    }
}

/**
 * נעילה על מחזור קריאה-שינוי-שמירה (F2): flock על קובץ נעילה נפרד בתיקייה. כל כתיבה (register / report / apply)
 * רצה בתוכה, כך ששתי כתיבות במקביל לא מאבדות אחת את השנייה.
 */
function academy_progress_locked(string $dir, callable $fn): mixed {
    $old = umask(0027);
    $h = @fopen("$dir/.lock", 'c');
    umask($old);
    if ($h === false || !flock($h, LOCK_EX)) {
        if ($h) fclose($h);
        throw new AcademyProgressError('נעילת ההתקדמות נכשלה', 500, 'internal');
    }
    try {
        return $fn();
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

/** כתיבה אטומית: קובץ זמני באותה תיקייה ואז rename. כשל — הזמני נמחק, ולא נשאר קובץ חלקי. התיקייה לא נוצרת. */
function academy_progress_write_atomic(string $f, string $content): void {
    $tmp = $f . '.tmp-' . bin2hex(random_bytes(6));
    $old = umask(0027);                                    // נוצר כבר בלי הרשאות לאחרים (F10)
    $ok = @file_put_contents($tmp, $content) === strlen($content) && @chmod($tmp, 0640) && @rename($tmp, $f);
    umask($old);
    if (!$ok) {
        if (is_file($tmp)) @unlink($tmp);
        throw new AcademyProgressError('שמירת ההתקדמות נכשלה', 500, 'internal');
    }
}

/**
 * שמירה עם יומן — היומן לעולם לא מתעד שינוי שלא נשמר:
 *   1. הקובץ החדש נכתב לקובץ זמני (כשל → כלום לא השתנה, ואין שורה ביומן).
 *   2. שורות היומן נכתבות (כשל → הזמני נמחק, הקובץ הקודם בתוקף, ואין שורה).
 *   3. rename של הזמני לקובץ הסופי — נקודת ההתחייבות. כשל → היומן מקוצץ חזרה לגודלו שלפני (ftruncate), כך שאין בו
 *      שורה על השינוי; אם גם הקיצוץ נכשל — נרשמת שורת rollback עם אותו tx, כדי שהשינוי לא ייראה כמוצלח.
 * הכול רץ בתוך academy_progress_locked, ולכן אף כותב אחר לא נכנס בין השלבים. $rename — להזרקה בבדיקות.
 */
function academy_progress_commit(string $dir, string $file, string $content, array $entries, array $hooks = []): void {
    $rename = $hooks['rename'] ?? null;
    academy_progress_scan_open_tx($dir);
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(6));
    $old = umask(0027);
    $staged = @file_put_contents($tmp, $content) === strlen($content) && @chmod($tmp, 0640);
    umask($old);
    if (!$staged) {
        if (is_file($tmp)) @unlink($tmp);
        throw new AcademyProgressError('שמירת ההתקדמות נכשלה', 500, 'internal');
    }
    $tx = bin2hex(random_bytes(8));
    $log = "$dir/audit.log";
    clearstatcache(true, $log);
    $sizeBefore = is_file($log) ? (int) filesize($log) : 0;
    try {
        academy_progress_audit($dir, array_map(fn($e) => $e + ['tx' => $tx], $entries), $hooks['auditWrite'] ?? null);
    } catch (AcademyProgressError $e) {
        @unlink($tmp);
        throw $e;
    }
    if (!($rename ?? 'rename')($tmp, $file)) {
        @unlink($tmp);
        $h = @fopen($log, 'r+');
        $undone = $h && ftruncate($h, $sizeBefore);
        if ($h) fclose($h);
        if (!$undone) {
            try { academy_progress_audit($dir, [['at' => gmdate('Y-m-d\TH:i:s\Z'), 'action' => 'rollback', 'tx' => $tx]]); }
            catch (AcademyProgressError) { error_log('cyber-academy progress: rollback של היומן נכשל'); }
        }
        throw new AcademyProgressError('שמירת ההתקדמות נכשלה', 500, 'internal');
    }
    // השינוי נשמר. שורת commit מסמנת את ה-tx כגמור; כשל כאן לא מבטל שינוי שכבר נשמר — רק נרשם בלוג.
    if ($entries) {
        try { academy_progress_audit($dir, [['at' => gmdate('Y-m-d\TH:i:s\Z'), 'action' => 'commit', 'tx' => $tx]]); }
        catch (AcademyProgressError) { error_log('cyber-academy progress: שורת commit ליומן נכשלה'); }
    }
}

/**
 * סיכון שנותר (מתועד, לא מתוקן — הכרעת הבקרה): תהליך שנהרג בין רישום היומן ל-rename משאיר ביומן tx בלי commit/rollback.
 * בכל כתיבה סורקים את סוף היומן ומסמנים בלוג השרת tx פתוח — **בלי לשנות נתונים** ובלי מזהה לומד.
 * @return list<string> מזהי tx פתוחים (לבדיקות)
 */
function academy_progress_scan_open_tx(string $dir, int $tail = 500): array {
    $log = "$dir/audit.log";
    if (!is_file($log)) return [];
    $lines = @file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $open = [];
    foreach (array_slice($lines, -$tail) as $l) {
        $e = json_decode($l, true);
        if (!is_array($e) || !isset($e['tx'])) continue;
        if (in_array($e['action'] ?? '', ['commit', 'rollback'], true)) unset($open[$e['tx']]);
        else $open[$e['tx']] = true;
    }
    if ($open) error_log('cyber-academy progress: ביומן ' . count($open) . ' tx בלי commit/rollback (ייתכן שתהליך נקטע) — לבדיקה ידנית');
    return array_keys($open);
}

/** יומן ביקורת — שורה לכל שינוי, דרך academy_progress_commit בלבד. בלי reference ובלי תוכן ראיה. כשל → חריגה. */
function academy_progress_audit(string $dir, array $entries, ?callable $write = null): void {
    $keys = array_flip(['at', 'by', 'learnerId', 'action', 'target', 'from', 'to', 'fromSub', 'assessmentId', 'evidenceIds', 'reportId', 'tx']);
    $lines = '';
    foreach ($entries as $e) $lines .= academy_progress_encode(array_intersect_key($e, $keys), JSON_UNESCAPED_UNICODE) . "\n";
    if ($lines === '') return;
    $f = "$dir/audit.log";
    clearstatcache(true, $f);
    $sizeBefore = is_file($f) ? (int) filesize($f) : 0;
    $old = umask(0027);                                    // נוצר כבר 0640, בלי חלון של הרשאות ברירת מחדל (F10)
    // בלי @ (F3): כשל נרשם בלוג השרת. $write — להזרקת כתיבה חלקית בבדיקות.
    $written = $write ? $write($f, $lines) : file_put_contents($f, $lines, FILE_APPEND | LOCK_EX);
    umask($old);
    if ($written !== strlen($lines)) {
        // כתיבה חלקית: שורה שבורה לא נשארת — מקצצים לגודל שלפני
        clearstatcache(true, $f);
        if (is_file($f) && (int) filesize($f) !== $sizeBefore) {
            $h = @fopen($f, 'r+');
            if (!$h || !ftruncate($h, $sizeBefore)) error_log('cyber-academy progress: קיצוץ יומן אחרי כתיבה חלקית נכשל');
            if ($h) fclose($h);
        }
        // כשל ביומן אינו נבלע (F3): הקורא מבטל את השמירה
        throw new AcademyProgressError('רישום יומן הביקורת נכשל', 500, 'internal');
    }
    @chmod($f, 0640);
}

// ---------------------------------------------------------------- אימות וכללי מצב

/** @return list<string> */
function academy_progress_validate(mixed $p): array {
    $err = [];
    if (!is_array($p) || ($p !== [] && array_is_list($p))) return ['$: קובץ ההתקדמות חייב להיות אובייקט'];
    $root = ['schema', 'learnerId', 'updatedAt', 'skills', 'lessons', 'assessments', 'evidence', 'reports'];
    _av_props($p, '$', $root, $root, $err);
    if (($p['schema'] ?? null) !== ACADEMY_PROGRESS_SCHEMA) $err[] = '$.schema: חייב להיות ' . ACADEMY_PROGRESS_SCHEMA;
    if (array_key_exists('learnerId', $p)) _av2_type($p['learnerId'], 'id', '$.learnerId', $err);
    if (array_key_exists('updatedAt', $p)) _av2_type($p['updatedAt'], 'dt?', '$.updatedAt', $err);
    if ($err) return $err;

    $lists = [
        'evidence' => ['id' => [1, 'id'], 'kind' => [1, 'enum:command_output|lab_report|quiz|oral_explanation|code|other'],
            'reference' => [1, 'str'], 'assessor' => [1, 'str1'], 'createdAt' => [1, 'dt'], 'approvedBy' => [0, 'str?'], 'approvedAt' => [0, 'dt?']],
        'assessments' => ['id' => [1, 'id'], 'skillIds' => [1, 'ids'], 'status' => [1, 'enum:planned|in_progress|evaluated'],
            'assistance' => [1, 'enum:' . implode('|', ACADEMY_PROGRESS_ASSISTANCE)], 'assessor' => [1, 'str1'],
            'assessedAt' => [1, 'dt?'], 'evidenceIds' => [1, 'ids'], 'approvedBy' => [0, 'str?'], 'approvedAt' => [0, 'dt?']],
        // דיווח מורה: אין בו status — הוא לא משנה מצב ולא מאפשר mastered, רק נוסף למעקב (גם עם assistanceLevel none)
        // at — תאריך בלבד או תאריך-שעה (הכרעת מנהל 00: לא ממציאים שעה). רק כאן; שאר שדות הזמן נשארים dt.
        'reports' => ['id' => [1, 'id'], 'at' => [1, 'date_or_dt'], 'reporter' => [1, 'str1'], 'skillIds' => [1, 'ids'],
            'lessonIds' => [0, 'ids'], 'assistanceLevel' => [1, 'enum:' . implode('|', ACADEMY_PROGRESS_REPORT_ASSISTANCE)],
            'summary' => [1, 'str']],
    ];
    $ids = [];
    foreach ($lists as $list => $spec) {
        $ids[$list] = [];
        if (!is_array($p[$list]) || !array_is_list($p[$list])) { $err[] = "\$.$list: חייב להיות מערך"; continue; }
        foreach ($p[$list] as $i => $it) {
            if (!_av2_obj($it)) { $err[] = "\$.{$list}[$i]: חייב להיות אובייקט"; continue; }
            _av2_item($it, "\$.{$list}[$i]", $spec, $err);
            if (isset($it['id']) && is_string($it['id'])) {
                if (isset($ids[$list][$it['id']])) $err[] = "\$.{$list}[$i].id: מזהה כפול";
                $ids[$list][$it['id']] = $it;
            }
        }
    }
    foreach (['skills', 'lessons'] as $map) {
        if (!_av2_obj($p[$map]) && $p[$map] !== []) $err[] = "\$.$map: חייב להיות אובייקט (מזהה → מצב)";
    }
    if ($err) return $err;

    // ראיה מאומתת: הפניה בטוחה + מעריך + אישור מנהל
    $verified = [];
    foreach ($ids['evidence'] as $id => $e) {
        if ($why = academy_v2_unsafe_reference((string) $e['reference'])) $err[] = "\$.evidence[$id].reference: הפניה לא בטוחה — $why";
        elseif (!empty($e['approvedBy']) && !empty($e['approvedAt'])) $verified[$id] = true;
    }
    $refEv = function (array $list, string $p) use (&$err, $ids) {
        foreach ($list as $j => $eid) if (!isset($ids['evidence'][$eid])) $err[] = "{$p}[$j]: אין ראיה במזהה \"$eid\"";
    };
    foreach ($ids['assessments'] as $id => $a) $refEv($a['evidenceIds'], "\$.assessments[$id].evidenceIds");

    foreach ($p['skills'] as $sid => $s) {
        $sp = "\$.skills[$sid]";
        if (!preg_match(ACADEMY_V2_ID, (string) $sid)) { $err[] = "$sp: מזהה מיומנות לא תקין"; continue; }
        if (!_av2_obj($s)) { $err[] = "$sp: חייב להיות אובייקט"; continue; }
        _av2_item($s, $sp, ['status' => [1, 'enum:' . implode('|', ACADEMY_PROGRESS_STATUSES)], 'updatedAt' => [1, 'dt?'],
            'assessmentIds' => [1, 'ids'], 'evidenceIds' => [1, 'ids']], $err);
        if (!isset($s['status'], $s['assessmentIds'], $s['evidenceIds']) || !is_array($s['assessmentIds']) || !is_array($s['evidenceIds'])) continue;
        foreach ($s['assessmentIds'] as $j => $aid) if (!isset($ids['assessments'][$aid])) $err[] = "$sp.assessmentIds[$j]: אין הערכה במזהה \"$aid\"";
        $refEv($s['evidenceIds'], "$sp.evidenceIds");
        // הערכות מאושרות על המיומנות הזו, מתוך אלה שהמיומנות מפנה אליהן
        $approved = array_filter(array_map(fn($aid) => $ids['assessments'][$aid] ?? null, $s['assessmentIds']), fn($a) => $a
            && $a['status'] === 'evaluated' && in_array((string) $sid, $a['skillIds'], true)
            && !empty($a['approvedBy']) && !empty($a['approvedAt']) && !empty($a['assessedAt']));
        if (in_array($s['status'], ACADEMY_PROGRESS_ASSESSED, true) && !$approved) {
            $err[] = "$sp.status: \"{$s['status']}\" מחייב הערכה evaluated מאושרת (approvedBy + approvedAt) על המיומנות";
        }
        if ($s['status'] === 'mastered') {
            $independent = array_filter($approved, fn($a) => $a['assistance'] === 'none');
            if (!$independent) $err[] = "$sp.status: mastered מחייב הערכה עצמאית (assistance: none)";
            $evs = $s['evidenceIds'];
            foreach ($independent as $a) $evs = array_merge($evs, $a['evidenceIds']);
            if (!array_filter($evs, fn($e) => isset($verified[$e]))) $err[] = "$sp.status: mastered מחייב ראיה מאומתת (עם approvedBy + approvedAt)";
        }
    }
    foreach ($p['lessons'] as $lid => $l) {
        $lp = "\$.lessons[$lid]";
        if (!preg_match(ACADEMY_V2_ID, (string) $lid)) { $err[] = "$lp: מזהה שיעור לא תקין"; continue; }
        if (!_av2_obj($l)) { $err[] = "$lp: חייב להיות אובייקט"; continue; }
        _av2_item($l, $lp, ['completed' => [1, 'bool'], 'evidenceIds' => [1, 'ids'], 'approvedBy' => [1, 'str?'], 'approvedAt' => [1, 'dt?']], $err);
        if (!isset($l['completed'], $l['evidenceIds']) || !is_array($l['evidenceIds'])) continue;
        $refEv($l['evidenceIds'], "$lp.evidenceIds");
        if ($l['completed'] === true) {
            if (empty($l['approvedBy']) || empty($l['approvedAt'])) $err[] = "$lp.completed: שיעור \"הושלם\" רק עם אישור מנהל (approvedBy + approvedAt)";
            if (!array_filter($l['evidenceIds'], fn($e) => isset($verified[$e]))) $err[] = "$lp.completed: שיעור \"הושלם\" רק עם ראיה מאומתת";
        }
    }
    return $err;
}

// ---------------------------------------------------------------- תצוגה (בלי אחוזים)

/** קטגוריה לכל מיומנות: verified / reported / not_assessed — לא מתמזגות. */
function academy_progress_view(array $p, array $skillIds = []): array {
    $reported = [];
    foreach ($p['reports'] as $r) foreach ($r['skillIds'] as $sid) $reported[$sid][] = ['id' => $r['id'], 'at' => $r['at'], 'assistanceLevel' => $r['assistanceLevel']];
    // רמת הסיוע בדיווחים — כל ערך בנפרד; unknown לעולם לא מצטרף ל-none
    $assist = array_fill_keys(ACADEMY_PROGRESS_REPORT_ASSISTANCE, 0);
    foreach ($p['reports'] as $r) $assist[$r['assistanceLevel']]++;
    $all = array_values(array_unique(array_merge($skillIds, array_keys($p['skills']), array_keys($reported))));
    $skills = [];
    foreach ($all as $sid) {
        $s = $p['skills'][$sid] ?? null;
        $isVerified = $s && in_array($s['status'], ACADEMY_PROGRESS_ASSESSED, true);
        $skills[$sid] = [
            'category' => $isVerified ? 'verified' : (isset($reported[$sid]) ? 'reported' : 'not_assessed'),
            // סטטוס רשמי — רק ממה שאומת. דיווח מורה לא משנה אותו.
            'status' => $s['status'] ?? 'not_assessed',
            'pendingReports' => count($reported[$sid] ?? []),
            'reportAssistance' => array_values(array_unique(array_column($reported[$sid] ?? [], 'assistanceLevel'))),
            'updatedAt' => $s['updatedAt'] ?? null,
        ];
    }
    $count = fn(string $c) => count(array_filter($skills, fn($x) => $x['category'] === $c));
    $by = fn(array $st) => count(array_filter($skills, fn($x) => $x['category'] === 'verified' && in_array($x['status'], $st, true)));
    return [
        'learnerId' => $p['learnerId'],
        'updatedAt' => $p['updatedAt'],
        'counts' => ['verified' => $count('verified'), 'reported' => $count('reported'), 'notAssessed' => $count('not_assessed')],
        // שני מדדים נפרדים, רק ממה שאומת; בלי אחוז
        'learned' => $by(['theory_demonstrated']), 'proven' => $by(['practice_demonstrated', 'mastered']),
        'skills' => $skills,
        'lessonsCompleted' => array_keys(array_filter($p['lessons'], fn($l) => $l['completed'] === true)),
        // דיווחי מורה לפי רמת סיוע — מידע למעקב בלבד, לא שליטה
        'reportsByAssistance' => $assist,
    ];
}

/**
 * התצוגה של הלומד עצמו — מצומצמת: רק מה שאומת, בלי נתיבים ובלי מעריכים, ובלי שום שדה מדיווחי המורים.
 *  - assessments: רק evaluated + assessedAt + approvedBy + approvedAt → {id, skillIds, at, result, approvedAt}.
 *    result = הסטטוס המאומת של כל מיומנות שמפנה להערכה הזו ({skillId: status}); אין שדה result בהערכה עצמה.
 *  - evidence: רק מאומתת (approvedBy + approvedAt + הפניה בטוחה) → {id, kind, createdAt}. **בלי reference.**
 *  - skills: category + status + updatedAt בלבד (בלי pendingReports / reportAssistance). אין reportsByAssistance.
 */
function academy_progress_learner_view(array $p): array {
    $full = academy_progress_view($p);
    $skills = array_map(fn($s) => ['category' => $s['category'], 'status' => $s['status'], 'updatedAt' => $s['updatedAt']], $full['skills']);
    $assessments = [];
    foreach ($p['assessments'] as $a) {
        if ($a['status'] !== 'evaluated' || empty($a['assessedAt']) || empty($a['approvedBy']) || empty($a['approvedAt'])) continue;
        $result = [];
        foreach ($p['skills'] as $sid => $s) {
            if (in_array($a['id'], $s['assessmentIds'], true) && in_array((string) $sid, $a['skillIds'], true)) $result[(string) $sid] = $s['status'];
        }
        $assessments[] = ['id' => $a['id'], 'skillIds' => $a['skillIds'], 'at' => $a['assessedAt'], 'result' => $result, 'approvedAt' => $a['approvedAt']];
    }
    $evidence = [];
    foreach ($p['evidence'] as $e) {
        if (empty($e['approvedBy']) || empty($e['approvedAt']) || academy_v2_unsafe_reference((string) $e['reference']) !== '') continue;
        $evidence[] = ['id' => $e['id'], 'kind' => $e['kind'], 'createdAt' => $e['createdAt']];
    }
    return [
        'learnerId' => $full['learnerId'], 'updatedAt' => $full['updatedAt'], 'counts' => $full['counts'],
        'learned' => $full['learned'], 'proven' => $full['proven'], 'skills' => $skills,
        'lessonsCompleted' => $full['lessonsCompleted'], 'assessments' => $assessments, 'evidence' => $evidence,
    ];
}

// ---------------------------------------------------------------- הרשאות

/**
 * מי שואל: ['role' => viewer|manager, 'sub' => string|null] או null (→ 403).
 * מנהל = sub שמופיע ב-progress.managers בקונפיג השרת (ברירת מחדל ריקה — אין מנהל).
 */
function academy_progress_actor(array $config, ?array $server = null, string $sapi = PHP_SAPI): ?array {
    $role = academy_role($server, $sapi);
    if ($role === null) return null;
    $server ??= $_SERVER;
    $u = json_decode((string) ($server['PLATFORM_USER'] ?? ''), true);
    $sub = is_array($u) && isset($u['sub']) && is_string($u['sub']) && $u['sub'] !== '' ? $u['sub'] : null;
    $managers = (array) ($config['progress']['managers'] ?? []);
    if ($sub !== null && in_array($sub, array_map('strval', $managers), true)) return ['role' => 'manager', 'sub' => $sub];
    return ['role' => $role, 'sub' => $sub];
}
