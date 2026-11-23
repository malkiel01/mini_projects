<?php
/**
 * api/progress.php (GET) ו-api/progress-write.php (POST, מנהל בלבד) — הלוגיקה כפונקציות שאפשר לבדוק.
 * $opts — לבדיקות בלבד: server / sapi (זהות וכותרות), now (זמן). התיקייה — רק מהקונפיג, ותמיד דרך academy_progress_dir.
 *
 * תיקוני ביקורת צוות 1 (docs/audit/progress-audit-2026-11-23.md):
 *  F1 קובץ פגום → 503, לא "ריק" ולא נדרס · F2 נעילה על כל מחזור כתיבה · F3 יומן לפני שמירה, כשל → בלי שמירה ·
 *  F4 register לא דורס מיפוי (409) · F5 רק same-origin · F7 assessmentId מחרוזת · F8 reason רק למנהל · F9 בלי learnerId בלוג
 */

declare(strict_types=1);

require_once __DIR__ . '/academy-source.php';
require_once __DIR__ . '/progress.php';

function _ap_fail(int $st, string $code, string $msg, array $extra = []): array {
    return [$st, ['ok' => false, 'code' => $code, 'error' => $msg] + $extra];
}

/** אחסון לא זמין: 503. הסיבה — רק למנהל (F8). */
function _ap_unavailable(AcademyProgressError $e, ?array $actor): array {
    $isManager = $actor !== null && academy_can('progress.read.all', $actor['role']);
    return _ap_fail(503, 'progress_unavailable', 'נתוני ההתקדמות אינם זמינים', $isManager ? ['reason' => $e->reason] : []);
}

/**
 * F5 — בקשת כתיבה רק מאותו מקור. Sec-Fetch-Site (אם נשלח) חייב להיות same-origin; Origin (אם נשלח) חייב להיות
 * המארח של הבקשה. בלי שתי הכותרות — נדחה, חוץ מ-CLI (בדיקות). דפדפן מודרני שולח את שתיהן ב-fetch.
 */
function academy_progress_same_origin(array $server, string $sapi): bool {
    $site = $server['HTTP_SEC_FETCH_SITE'] ?? null;
    $origin = $server['HTTP_ORIGIN'] ?? null;
    if ($site === null && $origin === null) return $sapi === 'cli';
    if ($site !== null && strtolower((string) $site) !== 'same-origin') return false;
    if ($origin !== null) {
        $u = parse_url((string) $origin);
        if (!is_array($u) || empty($u['host']) || !in_array($u['scheme'] ?? '', ['https', 'http'], true)) return false;
        $o = strtolower($u['host'] . (isset($u['port']) ? ':' . $u['port'] : ''));
        if ($o !== strtolower((string) ($server['HTTP_HOST'] ?? ''))) return false;
    }
    return true;
}

/** GET — לומד רואה רק את עצמו; מנהל — כל לומד (?learner=). בלי קובץ — תשובה ריקה. */
function academy_api_progress_read(string $method, array $config, array $query = [], array $opts = []): array {
    if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) return _ap_fail(405, 'method_not_allowed', 'קריאה בלבד');
    $actor = academy_progress_actor($config, $opts['server'] ?? null, $opts['sapi'] ?? PHP_SAPI);
    if ($actor === null || !academy_can('progress.read.self', $actor['role'])) return _ap_fail(403, 'forbidden', 'אין הרשאה');
    try {
        // לפני כל קובץ: אם האחסון לא תקין — לא נוגעים בשום קובץ
        $dir = academy_progress_dir($config);
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
    } catch (AcademyProgressError $e) {
        return _ap_unavailable($e, $actor);
    }
    $errors = academy_progress_validate($p);
    if ($errors) {
        error_log('cyber-academy progress: קובץ לומד אינו תקין (' . count($errors) . ' שגיאות)');   // בלי learnerId (F9)
        return _ap_fail(422, 'progress_invalid', 'נתוני ההתקדמות אינם תקינים — לא מוצגים',
            academy_can('progress.write', $actor['role']) ? ['errors' => array_slice($errors, 0, 50)] : []);
    }
    // מנהל — התצוגה המלאה (כולל דיווחים); כל אחד אחר — רק המאומת, בלי נתיבים, מעריכים או דיווחים
    $view = academy_can('progress.read.all', $actor['role']) ? academy_progress_view($p) : academy_progress_learner_view($p);
    return [200, ['ok' => true, 'learnerId' => $id, 'progress' => $view]];
}

/**
 * POST — מנהל בלבד, same-origin בלבד, JSON בלבד. גוף: {"action": ..., "learnerId": ...}
 *  - register: {"sub", "learnerId", "force"?} — מיפוי משתמש פלטפורמה ללומד. מיפוי קיים אחר → 409, אלא אם force: true.
 *  - report:   {"report": {...}} — דיווח מורה, לא משנה סטטוס
 *  - apply:    {"evidence": [...], "assessments": [...], "skills": {...}, "lessons": {...}} — כל הכללים נאכפים על הקובץ
 *              כולו אחרי השינוי; כשל → 422 והקובץ לא משתנה.
 * כל פעולה רצה בנעילה; היומן נכתב לפני השמירה, וכשל ביומן מבטל אותה.
 */
function academy_api_progress_write(string $method, array $config, string $contentType, string $raw, array $opts = []): array {
    if (strtoupper($method) !== 'POST') return _ap_fail(405, 'method_not_allowed', 'POST בלבד');
    $media = strtolower(trim(explode(';', $contentType, 2)[0]));
    if ($media !== 'application/json') return _ap_fail(415, 'unsupported_media_type', 'נדרש JSON');
    $server = $opts['server'] ?? $_SERVER;
    $sapi = $opts['sapi'] ?? PHP_SAPI;
    if (!academy_progress_same_origin($server, $sapi)) return _ap_fail(403, 'forbidden', 'בקשה ממקור אחר נדחתה');
    $actor = academy_progress_actor($config, $server, $sapi);
    if ($actor === null || !academy_can('progress.write', $actor['role'])) return _ap_fail(403, 'forbidden', 'כתיבה — למנהל בלבד');
    $b = json_decode($raw, true);
    if (!is_array($b)) return _ap_fail(400, 'bad_request', 'גוף הבקשה אינו JSON תקין');
    $now = $opts['now'] ?? gmdate('Y-m-d\TH:i:s\Z');
    $id = (string) ($b['learnerId'] ?? '');
    if (!preg_match(ACADEMY_V2_ID, $id)) return _ap_fail(400, 'bad_request', 'מזהה לומד לא תקין');
    $audit = ['at' => $now, 'by' => $actor['sub'], 'learnerId' => $id];
    try {
        $dir = academy_progress_dir($config);
        return academy_progress_locked($dir, fn() => _ap_write_action($b, $dir, $id, $now, $audit));
    } catch (AcademyProgressError $e) {
        if ($e->errorCode === 'progress_unavailable') return _ap_unavailable($e, $actor);
        return _ap_fail(500, 'internal', 'שמירת ההתקדמות נכשלה');   // בלי נתיב ובלי תוכן
    }
}

/** בתוך הנעילה. כל קריאה פגומה זורקת (F1) — ולכן שום דבר לא נכתב מעל קובץ פגום. */
function _ap_write_action(array $b, string $dir, string $id, string $now, array $audit): array {
    switch ($b['action'] ?? '') {
        case 'register':
            $sub = (string) ($b['sub'] ?? '');
            if ($sub === '' || strlen($sub) > 64) return _ap_fail(400, 'bad_request', 'sub לא תקין');
            $force = ($b['force'] ?? false) === true;
            $idx = academy_progress_index($dir);
            $prevId = $idx[$sub] ?? null;
            $prevSub = array_search($id, $idx, true);
            $prevSub = $prevSub === false || (string) $prevSub === $sub ? null : (string) $prevSub;
            if ($prevId === $id && $prevSub === null) return [200, ['ok' => true, 'learnerId' => $id, 'unchanged' => true]];
            if (!$force && ($prevId !== null || $prevSub !== null)) {
                return _ap_fail(409, 'conflict', 'המשתמש או הלומד כבר ממופים — נדרש force: true כדי לשנות');
            }
            if ($prevSub !== null) unset($idx[$prevSub]);
            $idx[$sub] = $id;
            academy_progress_audit($dir, [$audit + ['action' => 'register', 'target' => $sub, 'from' => $prevId, 'to' => $id, 'fromSub' => $prevSub]]);
            academy_progress_write_atomic("$dir/learners.json", academy_progress_encode($idx, JSON_UNESCAPED_UNICODE));
            return [200, ['ok' => true, 'learnerId' => $id]];

        case 'report':
            $p = academy_progress_load($dir, $id) ?? academy_progress_empty($id);
            $before = $p['skills'];
            $p['reports'][] = $b['report'] ?? null;
            $p['updatedAt'] = $now;
            if ($e = academy_progress_validate($p)) return _ap_fail(422, 'progress_invalid', 'הדיווח נדחה', ['errors' => $e]);
            if ($p['skills'] !== $before) return _ap_fail(500, 'internal', 'דיווח לא אמור לשנות סטטוס'); // הגנה כפולה
            academy_progress_audit($dir, [$audit + ['action' => 'report', 'reportId' => $b['report']['id'], 'target' => $b['report']['skillIds']]]);
            academy_progress_save($dir, $p);
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
            $entries = [];
            foreach ($p['skills'] as $sid => $s) {
                $from = $old['skills'][$sid]['status'] ?? 'not_assessed';
                // assessmentId — מחרוזת (F7): ההערכה האחרונה שהמיומנות מפנה אליה
                if ($from !== $s['status']) $entries[] = $audit + ['action' => 'skill_status', 'target' => (string) $sid, 'from' => $from,
                    'to' => $s['status'], 'evidenceIds' => $s['evidenceIds'], 'assessmentId' => $s['assessmentIds'] ? (string) end($s['assessmentIds']) : null];
            }
            foreach ($p['lessons'] as $lid => $l) {
                $from = (bool) ($old['lessons'][$lid]['completed'] ?? false);
                if ($from !== $l['completed']) $entries[] = $audit + ['action' => 'lesson_completed', 'target' => (string) $lid,
                    'from' => $from, 'to' => $l['completed'], 'evidenceIds' => $l['evidenceIds']];
            }
            $newEv = array_diff(array_column($p['evidence'], 'id'), array_column($old['evidence'], 'id'));
            if ($newEv) $entries[] = $audit + ['action' => 'evidence_added', 'evidenceIds' => array_values($newEv)];
            academy_progress_audit($dir, $entries);     // קודם היומן (F3)
            academy_progress_save($dir, $p);
            return [200, ['ok' => true, 'learnerId' => $id, 'progress' => academy_progress_view($p)]];
    }
    return _ap_fail(400, 'bad_request', 'פעולה לא מוכרת');
}
