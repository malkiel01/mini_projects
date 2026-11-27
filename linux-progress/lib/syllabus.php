<?php
/**
 * סיכום ההתקדמות מול התוכנית המאושרת של האקדמיה.
 *
 * מקור התוכנית: dashboard/data/curriculum-v2-approved.json בריפו האקדמיה (קריאה בלבד, דרך השרת).
 * רשומת תרגול יכולה לכלול moduleId (למשל "03.02"). רשומה בלי מזהה, או עם מזהה שלא קיים, נספרת כ"לא ממופה".
 *
 * כללי הספירה:
 * - מודול "נלמד": יש לו לפחות תרגול אחד (done או verified).
 * - מודול "אומת": יש לו לפחות תרגול אחד verified. מוצג בנפרד.
 * - סטטוס מודול: none (לא התחלתי) | done (נלמד) | verified (אומת).
 * - אחוז מסלול = נלמדו / סה"כ מודולים במסלול, מעוגל לעשירית.
 * - קצב = מודולים נלמדים לשבוע, מאז התאריך הראשון ברשומות.
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/model.php';

const LP_CURRICULUM_PATH = 'dashboard/data/curriculum-v2-approved.json';
const LP_KINDS = ['theory' => 'תיאוריה', 'analysis' => 'ניתוח', 'lab' => 'מעבדה'];

/** מסלולים, מודולים ושיעורים מהתוכנית. מחזיר null אם הצורה לא תקינה. */
function lp_read_curriculum(mixed $data): ?array {
    if (!is_array($data) || !isset($data['tracks'], $data['modules']) || !is_array($data['tracks']) || !is_array($data['modules'])) return null;
    $tracks = [];
    foreach ($data['tracks'] as $t) {
        if (!is_array($t) || !is_string($t['id'] ?? null)) return null;
        $tracks[$t['id']] = (string) ($t['title'] ?? $t['name'] ?? $t['id']);
    }
    $modules = [];
    foreach ($data['modules'] as $m) {
        if (!is_array($m) || !is_string($m['id'] ?? null) || !preg_match(LP_MODULE_ID, $m['id'])) continue;
        $tid = (string) ($m['trackId'] ?? '');
        if (!isset($tracks[$tid])) continue;
        $modules[$m['id']] = ['trackId' => $tid, 'title' => (string) ($m['title'] ?? $m['id']), 'lessons' => []];
    }
    foreach (($data['lessons'] ?? []) as $l) {
        if (!is_array($l) || !is_string($l['moduleId'] ?? null) || !isset($modules[$l['moduleId']])) continue;
        $kind = (string) ($l['kind'] ?? '');
        $modules[$l['moduleId']]['lessons'][] = [
            'id' => (string) ($l['id'] ?? ''),
            'title' => (string) ($l['title'] ?? ''),
            'kind' => LP_KINDS[$kind] ?? $kind,
            'objectives' => array_values(array_filter((array) ($l['learningObjectives'] ?? []), 'is_string')),
            'order' => (int) ($l['order'] ?? 0),
        ];
    }
    foreach ($modules as &$m) usort($m['lessons'], fn($a, $b) => $a['order'] <=> $b['order']);
    unset($m);
    return ['tracks' => $tracks, 'modules' => $modules];
}

/**
 * הסיכום עצמו, לפי החלטה 00B. $items: רשומות שעברו lp_validate.
 * $effective: מצב אפקטיבי מהחלטות המנהל (lp_effective_decisions). $today: מוזרק לבדיקות.
 * מודול נלמד/מאומת ויחידה שהושלמה — רק לפי החלטת מנהל. רשומות תרגול ואימותי יכולת נספרים בנפרד.
 */
function lp_summary(array $curriculum, array $items, array $effective, DateTimeImmutable $today): array {
    $mods = $curriculum['modules'];
    $activity = [];       // moduleId => true, רק למודולים קיימים
    $lessonActivity = []; // lessonId => true
    $unmapped = 0;
    $lessonMismatch = 0;
    $capability = 0;
    $first = null;
    $timeRecords = 0; $timeRecorded = 0; $minutes = ['phone' => 0, 'computer' => 0, 'other' => 0, 'unknown' => 0];
    foreach ($items as $it) {
        $d = (string) ($it['date'] ?? '');
        if ($first === null || $d < $first) $first = $d;
        $timeRecords++;
        if (($it['durationMinutes'] ?? null) !== null) {
            $timeRecorded++;
            $mode = $it['studyMode'] ?? 'unknown';
            $minutes[$mode] = ($minutes[$mode] ?? 0) + $it['durationMinutes'];
        }
        if (($it['status'] ?? '') === 'verified') $capability++;
        $mid = $it['moduleId'] ?? null;
        if (!is_string($mid) || !isset($mods[$mid])) { $unmapped++; continue; }
        $activity[$mid] = true;
        $lid = $it['lessonId'] ?? null;
        if (is_string($lid)) {
            $lessonOk = isset($mods[$mid]['lessons']) && in_array($lid, array_column($mods[$mid]['lessons'], 'id'), true);
            if ($lessonOk) $lessonActivity[$lid] = true; else $lessonMismatch++;
        }
    }

    $tracks = [];
    $tot = ['modules' => 0, 'inProgress' => 0, 'learnedPending' => 0, 'verified' => 0, 'lessons' => 0, 'lessonsDone' => 0, 'lessonsVerified' => 0];
    foreach ($curriculum['tracks'] as $tid => $title) {
        // מפתחות כמו "10" הופכים למספרים שלמים ב-PHP. ההשוואה למזהה מודול דורשת מחרוזת.
        $tid = (string) $tid;
        $modList = array_filter($mods, fn($m) => $m['trackId'] === $tid);
        if (count($modList) === 0) continue;
        $t = ['modules' => 0, 'verified' => 0, 'lessons' => 0, 'lessonsDone' => 0];
        $modules = [];
        foreach ($modList as $mid => $m) {
            $state = lp_module_state($effective, (string) $mid, isset($activity[$mid]));
            $t['modules']++;
            $tot['modules']++;
            if ($state === 'in_progress') $tot['inProgress']++;
            if ($state === 'learned_pending_verification') $tot['learnedPending']++;
            if ($state === 'verified') { $tot['verified']++; $t['verified']++; }
            $lessons = [];
            foreach ($m['lessons'] as $l) {
                $ls = lp_lesson_state($effective, $l['id'], $l['kind'] === LP_KINDS['lab'] ? 'lab' : $l['kind']) ?? (isset($lessonActivity[$l['id']]) ? 'in_progress' : 'not_started');
                $t['lessons']++; $tot['lessons']++;
                if ($ls === 'completed' || $ls === 'verified') { $t['lessonsDone']++; $tot['lessonsDone']++; }
                if ($ls === 'verified') $tot['lessonsVerified']++;
                $lessons[] = ['id' => $l['id'], 'title' => $l['title'], 'kind' => $l['kind'], 'objectives' => $l['objectives'], 'status' => $ls];
            }
            $modules[] = ['id' => (string) $mid, 'title' => $m['title'], 'status' => $state, 'lessons' => $lessons];
        }
        $tracks[] = [
            'id' => $tid,
            'title' => $title,
            'modulesTotal' => $t['modules'],
            'modulesVerified' => $t['verified'],
            'lessonsTotal' => $t['lessons'],
            'lessonsCompleted' => $t['lessonsDone'],
            'percentLessons' => $t['lessons'] > 0 ? round($t['lessonsDone'] / $t['lessons'] * 100, 1) : 0.0,
            'modules' => $modules,
        ];
    }

    $pace = null;
    if ($first !== null && $first !== '') {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $first, $today->getTimezone());
        if ($start !== false) {
            $days = max(0, (int) $start->diff($today)->format('%a'));
            $weeks = max(1, (int) ceil(($days + 1) / 7));
            $pace = ['since' => $first, 'weeks' => $weeks, 'modulesTouchedPerWeek' => round(count($activity) / $weeks, 1)];
        }
    }

    return [
        'overall' => [
            'modulesTotal' => $tot['modules'],
            'modulesInProgress' => $tot['inProgress'],
            'modulesLearnedPending' => $tot['learnedPending'],
            'modulesVerified' => $tot['verified'],
            'percentModulesVerified' => $tot['modules'] > 0 ? round($tot['verified'] / $tot['modules'] * 100, 1) : 0.0,
            'lessonsTotal' => $tot['lessons'],
            'lessonsCompleted' => $tot['lessonsDone'],
            'lessonsVerified' => $tot['lessonsVerified'],
            'percentLessonsCompleted' => $tot['lessons'] > 0 ? round($tot['lessonsDone'] / $tot['lessons'] * 100, 1) : 0.0,
        ],
        'practice' => [
            'records' => $timeRecords,
            'modulesTouched' => count($activity),
            'capabilityChecks' => $capability,
            'unmapped' => $unmapped,
            'lessonMismatch' => $lessonMismatch,
        ],
        'time' => [
            'recordsTotal' => $timeRecords,
            'recordsWithDuration' => $timeRecorded,
            // null = אין רשומות בכלל. חסר זמן ברשומה אינו נספר כאפס.
            'coveragePercent' => $timeRecords > 0 ? round($timeRecorded / $timeRecords * 100, 1) : null,
            'minutesReported' => array_sum($minutes),
            'minutesByStudyMode' => $minutes,
        ],
        'tracks' => $tracks,
        'pace' => $pace,
        'unmapped' => $unmapped,
    ];
}

/** קריאת התוכנית מהריפו דרך השרת. $fetch — להזרקה בבדיקות. מחזיר [status, curriculum|null]. */
function lp_fetch_curriculum(?string $token, ?callable $fetch = null): array {
    if ($token === null) return ['no_token', null];
    $url = 'https://api.github.com/repos/' . LP_REPO . '/contents/' . LP_CURRICULUM_PATH;
    [$code, $raw] = ($fetch ?? 'lp_http_get')($url, $token);
    if ($code !== 200 || !is_string($raw)) return ['unavailable', null];
    $cur = lp_read_curriculum(json_decode($raw, true));
    return $cur === null ? ['invalid', null] : ['ok', $cur];
}
