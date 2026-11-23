<?php
/**
 * שכבת הנתונים — מודל לחוזה v2: stage → track → module → lesson, ותחת המודול topics ו-skills.
 *
 * עקרונות (כמו ב-v1):
 * - "נלמד" ו"הוכח" — שני מדדים נפרדים, ספירות ולא אחוז; אין אחוזים משוקללים בשום רמה.
 * - אין כשירויות/הערכות → measure: null, וה-UI מציג "אין הערכות מתועדות" — לא 0.
 * - מסלול מוצג "מאושר" רק כש-approvalStatus=approved **וגם** curriculum.status=approved. אחרת — "מוצע · לא אושר".
 * - המלצות — נגזרות (derived: true).
 * מניח snapshot שעבר academy_validate_v2.
 */

declare(strict_types=1);

require_once __DIR__ . '/model.php';

function academy_model_v2(array $s): array {
    $byOrder = fn($a, $b) => [$a['order'], $a['id']] <=> [$b['order'], $b['id']];
    $index = fn(array $list) => array_column($list, null, 'id');
    $cur = $s['curriculum'];
    // מאושר רק מהקובץ עצמו, ורק עם תאריך ומאשר (גם האימות דורש את זה — כאן ליתר ביטחון)
    $curApproved = $cur['status'] === 'approved' && !empty($cur['approvedAt']) && !empty($cur['approvedBy']);

    $stagesById = $index($s['stages']);
    $tracksById = $index($s['tracks']);
    $modulesById = $index($s['modules']);
    $skillsById = $index($s['skills']);
    $tasksById = $index($s['tasks']);

    // מסלול ← מודול ← מיומנות: כל כשירות משויכת למסלול דרך המיומנות שלה
    $comps = [];
    foreach ($s['competencies'] as $c) {
        $sk = $skillsById[$c['skillId']];
        $mod = $modulesById[$sk['moduleId']];
        $comps[] = ['id' => $c['id'], 'skillId' => $c['skillId'], 'title' => $sk['title'], 'moduleId' => $mod['id'],
            'trackId' => $mod['trackId'], 'status' => $c['status'], 'evidenceRefs' => $c['evidenceRefs'],
            'evidenceReported' => true, 'lastAssessedAt' => $c['lastAssessedAt'], 'assessedReported' => true];
    }
    $compsBy = fn(string $field, string $id) => array_values(array_filter($comps, fn($c) => $c[$field] === $id));
    $measure = fn(array $cs) => $cs ? academy_measure($cs) : null;

    $lessonsByModule = [];
    foreach ($s['lessons'] as $l) $lessonsByModule[$l['moduleId']][] = $l;
    $under = function (string $list, string $moduleId) use ($s, $byOrder) {
        $out = array_values(array_filter($s[$list], fn($x) => $x['moduleId'] === $moduleId));
        usort($out, $byOrder);
        return $out;
    };

    $modules = [];
    foreach ($s['modules'] as $m) {
        $lessons = $lessonsByModule[$m['id']] ?? [];
        usort($lessons, $byOrder);
        $modules[] = [
            'id' => $m['id'], 'title' => $m['title'], 'order' => $m['order'], 'trackId' => $m['trackId'],
            'description' => $m['description'] ?? null, 'learningObjectives' => $m['learningObjectives'], 'labRequired' => $m['labRequired'],
            'prerequisites' => array_map(fn($id) => ['id' => $id, 'title' => $modulesById[$id]['title']], $m['prerequisites'] ?? []),
            'lessons' => array_map(fn($l) => [
                'id' => $l['id'], 'title' => $l['title'], 'kind' => $l['kind'], 'order' => $l['order'],
                'learningObjectives' => $l['learningObjectives'], 'assessmentMode' => $l['assessmentMode'],
                'authorizedLabRequired' => $l['authorizedLabRequired'] ?? false], $lessons),
            'topics' => array_map(fn($t) => ['id' => $t['id'], 'title' => $t['title'], 'description' => $t['description'] ?? null], $under('topics', $m['id'])),
            'skills' => array_map(fn($k) => ['id' => $k['id'], 'title' => $k['title'], 'assessmentCriteria' => $k['assessmentCriteria'],
                'competencyStatus' => ($compsBy('skillId', $k['id'])[0]['status'] ?? null)], $under('skills', $m['id'])),
            'measure' => $measure($compsBy('moduleId', $m['id'])),
        ];
    }
    usort($modules, $byOrder);

    $tracks = [];
    foreach ($s['tracks'] as $t) {
        $mods = array_values(array_filter($modules, fn($m) => $m['trackId'] === $t['id']));
        $effective = ($t['approvalStatus'] === 'approved' && $curApproved) ? 'approved' : 'proposed';
        $tracks[] = [
            'id' => $t['id'], 'title' => $t['title'], 'order' => $t['order'], 'stageId' => $t['stageId'],
            'description' => $t['description'] ?? null, 'instructorId' => $t['instructorId'] ?? null,
            'approvalStatus' => $t['approvalStatus'],
            'curriculumStatus' => $effective, 'curriculumStatusSource' => 'contract',
            'prerequisites' => array_map(fn($id) => ['id' => $id, 'title' => $tracksById[$id]['title']], $t['prerequisites'] ?? []),
            'prerequisitesReported' => true,
            'moduleIds' => array_column($mods, 'id'),
            'counts' => ['modules' => count($mods), 'lessons' => array_sum(array_map(fn($m) => count($m['lessons']), $mods))],
            'measure' => $measure($compsBy('trackId', $t['id'])),
        ];
    }
    usort($tracks, $byOrder);

    $stages = [];
    foreach ($s['stages'] as $st) {
        $stages[] = ['id' => $st['id'], 'title' => $st['title'], 'order' => $st['order'], 'description' => $st['description'] ?? null,
            'trackIds' => array_column(array_filter($tracks, fn($t) => $t['stageId'] === $st['id']), 'id')];
    }
    usort($stages, $byOrder);

    $tasks = array_map(function ($t) use ($skillsById, $modulesById, $tasksById) {
        $trackIds = array_values(array_unique(array_map(fn($k) => $modulesById[$skillsById[$k]['moduleId']]['trackId'], $t['skillIds'])));
        $deps = array_map(fn($id) => ['id' => $id, 'title' => $tasksById[$id]['title'], 'status' => $tasksById[$id]['status']], $t['dependsOn']);
        return ['id' => $t['id'], 'title' => $t['title'], 'status' => $t['status'], 'mode' => $t['mode'], 'skillIds' => $t['skillIds'],
            'trackIds' => $trackIds, 'dependsOn' => $deps, 'authorizedLab' => $t['authorizedLab'] ?? false,
            'waitingOn' => array_values(array_filter($deps, fn($d) => $d['status'] !== 'completed'))];
    }, $s['tasks']);

    $assessments = array_map(fn($a) => ['id' => $a['id'], 'status' => $a['status'], 'rubric' => $a['rubric'],
        'skills' => array_map(fn($k) => ['id' => $k, 'title' => $skillsById[$k]['title']], $a['skillIds']),
        'evidenceRefs' => $a['evidenceRefs'], 'assessedAt' => $a['assessedAt'] ?? null, 'approvedBy' => $a['approvedBy'] ?? null], $s['assessments']);
    $evidence = array_map(fn($e) => ['id' => $e['id'], 'kind' => $e['kind'], 'reference' => $e['reference'],
        'createdAt' => $e['createdAt'], 'visibility' => $e['visibility'] ?? null], $s['evidence']);

    $gaps = array_values(array_filter($comps, fn($c) => in_array($c['status'], ACADEMY_GAPS, true)));
    $rec = [];
    foreach ($gaps as $c) {
        $rec[] = ['kind' => $c['status'] === 'reassessment_required' ? 'reassess' : 'close_gap',
            'title' => ($c['status'] === 'reassessment_required' ? 'הערכה מחדש: ' : 'סגירת פער: ') . $c['title'],
            'reason' => $c['status'] === 'reassessment_required' ? 'הכשירות מסומנת "נדרשת הערכה מחדש"' : 'הכשירות מסומנת "זוהה פער"',
            'ref' => ['type' => 'module', 'id' => $c['moduleId'], 'trackId' => $c['trackId']], 'derived' => true];
    }
    foreach ($tasks as $t) {
        if ($t['status'] !== 'blocked') continue;
        $rec[] = ['kind' => 'unblock', 'title' => 'לבדוק חסימה: ' . $t['title'],
            'reason' => $t['waitingOn'] ? 'ממתינה ל: ' . implode(', ', array_column($t['waitingOn'], 'title')) : 'חסומה (סיבת החסימה אינה בחוזה)',
            'ref' => ['type' => 'task', 'id' => $t['id']], 'derived' => true];
    }

    $timeline = [];
    if ($s['updatedAt']) $timeline[] = ['at' => $s['updatedAt'], 'kind' => 'snapshot', 'title' => 'עדכון תמונת המצב', 'referenceId' => null];
    foreach ($s['timeline'] as $e) $timeline[] = ['at' => $e['at'], 'kind' => $e['kind'], 'title' => $e['title'], 'referenceId' => $e['referenceId'] ?? null];
    usort($timeline, fn($a, $b) => strtotime($b['at']) <=> strtotime($a['at']));

    return [
        'version' => 2,
        'schemaVersion' => $s['schemaVersion'],
        'updatedAt' => $s['updatedAt'],
        'empty' => !$s['stages'] && !$s['tracks'] && !$s['modules'] && !$s['lessons'],
        'curriculum' => ['curriculumStatus' => $curApproved ? 'approved' : 'proposed', 'curriculumStatusSource' => 'contract', 'version' => $cur['version'],
            'approvedAt' => $cur['approvedAt'], 'approvedBy' => $cur['approvedBy']],
        // גודל הסילבוס — לא התקדמות
        'counts' => ['stages' => count($stages), 'tracks' => count($tracks), 'modules' => count($modules), 'lessons' => count($s['lessons']),
            'topics' => count($s['topics']), 'skills' => count($s['skills'])],
        'measure' => $measure($comps),
        'assessmentsRecorded' => count(array_filter($s['assessments'], fn($a) => $a['status'] === 'evaluated')),
        'stages' => $stages, 'tracks' => $tracks, 'modules' => $modules,
        'competencies' => $comps, 'tasks' => $tasks, 'taskCounts' => academy_task_counts($tasks),
        'assessments' => $assessments, 'evidence' => $evidence,
        'gaps' => $gaps, 'recommendations' => $rec, 'timeline' => $timeline,
    ];
}

/**
 * מקור github, חוזה v2: **רק סילבוס מאושר.** אם הסילבוס כולו לא מאושר — אין מסלולים בכלל. מסלול לא מאושר נופל עם
 * כל מה שתחתיו. נתוני לומד (כשירויות, משימות, הערכות, ראיות, ציר זמן) לא עוברים לעולם — גם אם הם בקובץ.
 */
function academy_v2_curriculum_only(array $s): array {
    $approved = ($s['curriculum']['status'] ?? null) === 'approved';
    $tracks = $approved ? array_values(array_filter($s['tracks'], fn($t) => $t['approvalStatus'] === 'approved')) : [];
    $trackIds = array_column($tracks, 'id');
    // דרישת קדם למסלול שסונן — נשמטת (אין הפניה לישות שלא נשלחה)
    $tracks = array_map(function ($t) use ($trackIds) {
        if (isset($t['prerequisites'])) $t['prerequisites'] = array_values(array_intersect($t['prerequisites'], $trackIds));
        return $t;
    }, $tracks);
    $modules = array_values(array_filter($s['modules'], fn($m) => in_array($m['trackId'], $trackIds, true)));
    $moduleIds = array_column($modules, 'id');
    $keep = fn(string $list) => array_values(array_filter($s[$list], fn($x) => in_array($x['moduleId'], $moduleIds, true)));
    $stages = array_values(array_filter(array_map(function ($st) use ($trackIds) {
        $st['trackIds'] = array_values(array_intersect($st['trackIds'], $trackIds));
        if (isset($st['prerequisites'])) $st['prerequisites'] = [];
        return $st;
    }, $s['stages']), fn($st) => $st['trackIds'] !== []));
    $modules = array_map(function ($m) use ($moduleIds) {
        if (isset($m['prerequisites'])) $m['prerequisites'] = array_values(array_intersect($m['prerequisites'], $moduleIds));
        return $m;
    }, $modules);
    return [
        'schemaVersion' => $s['schemaVersion'], 'updatedAt' => $s['updatedAt'], 'curriculum' => $s['curriculum'],
        'stages' => $stages, 'tracks' => $tracks, 'modules' => $modules,
        'topics' => $keep('topics'), 'skills' => $keep('skills'), 'lessons' => $keep('lessons'),
        'competencies' => [], 'tasks' => [], 'assessments' => [], 'evidence' => [], 'timeline' => [],
    ];
}
