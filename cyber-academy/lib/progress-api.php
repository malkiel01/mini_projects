<?php
/**
 * api/progress.php (GET) ו-api/progress-write.php (POST, מנהל בלבד) — הלוגיקה כפונקציות שאפשר לבדוק.
 * $opts — לבדיקות בלבד: server / sapi (זהות), dir (תיקיית ההתקדמות), now (זמן).
 */

declare(strict_types=1);

require_once __DIR__ . '/academy-source.php';
require_once __DIR__ . '/progress.php';

function _ap_fail(int $st, string $code, string $msg, array $extra = []): array {
    return [$st, ['ok' => false, 'code' => $code, 'error' => $msg] + $extra];
}

/** GET — לומד רואה רק את עצמו; מנהל — כל לומד (?learner=). בלי קובץ — תשובה ריקה. */
function academy_api_progress_read(string $method, array $config, array $query = [], array $opts = []): array {
    if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) return _ap_fail(405, 'method_not_allowed', 'קריאה בלבד');
    $actor = academy_progress_actor($config, $opts['server'] ?? null, $opts['sapi'] ?? PHP_SAPI);
    if ($actor === null || !academy_can('progress.read.self', $actor['role'])) return _ap_fail(403, 'forbidden', 'אין הרשאה');
    try { $dir = $opts['dir'] ?? academy_progress_dir($config); }
    catch (AcademyProgressError $e) { return _ap_fail($e->http, $e->errorCode, 'אחסון ההתקדמות אינו מוגדר כראוי'); }
    $want = isset($query['learner']) ? (string) $query['learner'] : null;
    $own = $actor['sub'] !== null ? academy_progress_learner_id($dir, $actor['sub']) : null;

    if ($want !== null && $want !== $own) {
        if (!academy_can('progress.read.all', $actor['role'])) return _ap_fail(403, 'forbidden', 'אין הרשאה לצפות בלומד אחר');
        if (!preg_match(ACADEMY_V2_ID, $want)) return _ap_fail(400, 'bad_request', 'מזהה לומד לא תקין');
        $id = $want;
    } else {
        $id = $own;
    }
    if ($id === null) return [200, ['ok' => true, 'learnerId' => null, 'progress' => null]];   // אין רישום — ריק, לא שגיאה
    $p = academy_progress_load($dir, $id) ?? academy_progress_empty($id);
    $errors = academy_progress_validate($p);
    if ($errors) {
        error_log("cyber-academy progress: קובץ הלומד $id אינו תקין (" . count($errors) . ')');
        return _ap_fail(422, 'progress_invalid', 'נתוני ההתקדמות אינם תקינים — לא מוצגים',
            academy_can('progress.write', $actor['role']) ? ['errors' => array_slice($errors, 0, 50)] : []);
    }
    return [200, ['ok' => true, 'learnerId' => $id, 'progress' => academy_progress_view($p)]];
}

/**
 * POST — מנהל בלבד. גוף JSON: {"action": ..., "learnerId": ...}
 *  - register: {"sub", "learnerId"} — מיפוי משתמש פלטפורמה ללומד
 *  - report:   {"report": {id, at, reporter, skillIds, summary}} — דיווח מורה, לא משנה סטטוס
 *  - apply:    {"evidence": [...], "assessments": [...], "skills": {...}, "lessons": {...}} — כל הכללים נאכפים על הקובץ
 *              כולו אחרי השינוי; כשל → 422 והקובץ לא משתנה.
 */
function academy_api_progress_write(string $method, array $config, string $contentType, string $raw, array $opts = []): array {
    if (strtoupper($method) !== 'POST') return _ap_fail(405, 'method_not_allowed', 'POST בלבד');
    if (!str_starts_with(strtolower(trim($contentType)), 'application/json')) return _ap_fail(415, 'unsupported_media_type', 'נדרש JSON');
    $actor = academy_progress_actor($config, $opts['server'] ?? null, $opts['sapi'] ?? PHP_SAPI);
    if ($actor === null || !academy_can('progress.write', $actor['role'])) return _ap_fail(403, 'forbidden', 'כתיבה — למנהל בלבד');
    $b = json_decode($raw, true);
    if (!is_array($b)) return _ap_fail(400, 'bad_request', 'גוף הבקשה אינו JSON תקין');
    try { $dir = $opts['dir'] ?? academy_progress_dir($config); }
    catch (AcademyProgressError $e) { return _ap_fail($e->http, $e->errorCode, 'אחסון ההתקדמות אינו מוגדר כראוי'); }
    $now = $opts['now'] ?? gmdate('Y-m-d\TH:i:s\Z');
    $id = (string) ($b['learnerId'] ?? '');
    if (!preg_match(ACADEMY_V2_ID, $id)) return _ap_fail(400, 'bad_request', 'מזהה לומד לא תקין');
    $audit = ['at' => $now, 'by' => $actor['sub'], 'learnerId' => $id];

    switch ($b['action'] ?? '') {
        case 'register':
            $sub = (string) ($b['sub'] ?? '');
            if ($sub === '' || strlen($sub) > 64) return _ap_fail(400, 'bad_request', 'sub לא תקין');
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            $idx = json_decode((string) @file_get_contents("$dir/learners.json"), true) ?: [];
            $idx[$sub] = $id;
            @file_put_contents("$dir/learners.json", json_encode($idx, JSON_UNESCAPED_UNICODE), LOCK_EX);
            @chmod("$dir/learners.json", 0640);
            academy_progress_audit($dir, $audit + ['action' => 'register']);
            return [200, ['ok' => true, 'learnerId' => $id]];

        case 'report':
            $p = academy_progress_load($dir, $id) ?? academy_progress_empty($id);
            $before = $p['skills'];
            $p['reports'][] = $b['report'] ?? null;
            $p['updatedAt'] = $now;
            if ($e = academy_progress_validate($p)) return _ap_fail(422, 'progress_invalid', 'הדיווח נדחה', ['errors' => $e]);
            if ($p['skills'] !== $before) return _ap_fail(500, 'internal', 'דיווח לא אמור לשנות סטטוס'); // הגנה כפולה
            academy_progress_save($dir, $p);
            academy_progress_audit($dir, $audit + ['action' => 'report', 'reportId' => $b['report']['id'], 'target' => $b['report']['skillIds']]);
            return [200, ['ok' => true, 'learnerId' => $id, 'progress' => academy_progress_view($p)]];

        case 'apply':
            $p = academy_progress_load($dir, $id) ?? academy_progress_empty($id);
            $old = $p;
            foreach (['evidence', 'assessments'] as $list) {
                foreach ((array) ($b[$list] ?? []) as $it) {
                    $p[$list] = array_values(array_filter($p[$list], fn($x) => ($x['id'] ?? null) !== ($it['id'] ?? null)));
                    $p[$list][] = $it;
                }
            }
            foreach (['skills', 'lessons'] as $map) {
                foreach ((array) ($b[$map] ?? []) as $k => $v) $p[$map][(string) $k] = $v;
            }
            $p['updatedAt'] = $now;
            if ($e = academy_progress_validate($p)) return _ap_fail(422, 'progress_invalid', 'השינוי נדחה — מפר את כללי המצב', ['errors' => $e]);
            academy_progress_save($dir, $p);
            foreach ($p['skills'] as $sid => $s) {
                $from = $old['skills'][$sid]['status'] ?? 'not_assessed';
                if ($from !== $s['status']) academy_progress_audit($dir, $audit + ['action' => 'skill_status', 'target' => $sid,
                    'from' => $from, 'to' => $s['status'], 'evidenceIds' => $s['evidenceIds'], 'assessmentId' => $s['assessmentIds']]);
            }
            foreach ($p['lessons'] as $lid => $l) {
                $from = (bool) ($old['lessons'][$lid]['completed'] ?? false);
                if ($from !== $l['completed']) academy_progress_audit($dir, $audit + ['action' => 'lesson_completed', 'target' => $lid,
                    'from' => $from, 'to' => $l['completed'], 'evidenceIds' => $l['evidenceIds']]);
            }
            $newEv = array_diff(array_column($p['evidence'], 'id'), array_column($old['evidence'], 'id'));
            if ($newEv) academy_progress_audit($dir, $audit + ['action' => 'evidence_added', 'evidenceIds' => array_values($newEv)]);
            return [200, ['ok' => true, 'learnerId' => $id, 'progress' => academy_progress_view($p)]];
    }
    return _ap_fail(400, 'bad_request', 'פעולה לא מוכרת');
}
