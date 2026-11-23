<?php
/**
 * שכבת הנתונים — מ-snapshot מאומת לתמונת מצב שה-UI מציג כמו שהיא.
 *
 * כל החישוב כאן, לא בדפדפן, כדי שיהיה מקום אחד שקובע מה נחשב "נלמד" ומה "הוכח":
 *   learned = learning + theory_demonstrated          (נלמד / בתאוריה)
 *   proven  = practice_demonstrated + mastered         (הוכח במבחן מעשי)
 * שני מדדים נפרדים, כספירות "X מתוך N" — בכוונה אין אחוז אחד (owner-brief 2.4).
 *
 * מה שמסומן derived: true — נגזר כאן מהנתונים, ואינו קיים בחוזה (המלצות, מצב דרישות קדם).
 * מניח snapshot שעבר academy_validate.
 */

declare(strict_types=1);

require_once __DIR__ . '/validate.php';

const ACADEMY_LEARNED = ['learning', 'theory_demonstrated'];
const ACADEMY_PROVEN  = ['practice_demonstrated', 'mastered'];
const ACADEMY_GAPS    = ['gap_identified', 'reassessment_required'];

/** ספירה לפי סטטוס + שני המדדים, לקבוצת כשירויות. */
function academy_measure(array $comps): array {
    $by = array_fill_keys(ACADEMY_COMPETENCY_STATUSES, 0);
    foreach ($comps as $c) $by[$c['status']]++;
    $sum = fn(array $keys) => array_sum(array_intersect_key($by, array_flip($keys)));
    return [
        'total'   => count($comps),
        'learned' => $sum(ACADEMY_LEARNED),
        'proven'  => $sum(ACADEMY_PROVEN),
        'gaps'    => $sum(ACADEMY_GAPS),
        'notAssessed' => $by['not_assessed'],
        'byStatus' => $by,
    ];
}

function academy_model(array $s): array {
    $tracks = $s['tracks'];
    usort($tracks, fn($a, $b) => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
    $trackById = array_column($tracks, null, 'id');

    $comps = array_map(fn($c) => [
        'id' => $c['id'], 'trackId' => $c['trackId'], 'title' => $c['title'], 'status' => $c['status'],
        // שדה אופציונלי שחסר ≠ ריק: "לא דווח" ולא 0 / "טרם הוערך"
        'evidenceRefs' => $c['evidenceRefs'] ?? [], 'evidenceReported' => array_key_exists('evidenceRefs', $c),
        'lastAssessedAt' => $c['lastAssessedAt'] ?? null, 'assessedReported' => array_key_exists('lastAssessedAt', $c),
    ], $s['competencies']);
    $compsByTrack = [];
    foreach ($comps as $c) $compsByTrack[$c['trackId']][] = $c;
    $compById = array_column($comps, null, 'id');

    $tasks = array_map(function ($t) use ($compById) {
        $cids = $t['competencyIds'] ?? [];
        $trackIds = array_values(array_unique(array_map(fn($id) => $compById[$id]['trackId'], $cids)));
        return ['id' => $t['id'], 'title' => $t['title'], 'status' => $t['status'], 'mode' => $t['mode'],
                'competencyIds' => $cids, 'trackIds' => $trackIds];
    }, $s['tasks']);

    // מסלול "הוכח" = יש בו כשירויות וכולן הוכחו במעשה. נגזר — החוזה לא מגדיר סיום מסלול.
    $trackProven = [];
    foreach ($tracks as $t) {
        $m = academy_measure($compsByTrack[$t['id']] ?? []);
        $trackProven[$t['id']] = $m['total'] > 0 && $m['proven'] === $m['total'];
    }

    $outTracks = [];
    foreach ($tracks as $t) {
        $m = academy_measure($compsByTrack[$t['id']] ?? []);
        $prereqs = array_map(fn($pid) => [
            'id' => $pid, 'title' => $trackById[$pid]['title'], 'met' => $trackProven[$pid],
        ], $t['prerequisites'] ?? []);
        $started = $m['total'] - $m['notAssessed'] > 0
            || (bool) array_filter($tasks, fn($k) => in_array($t['id'], $k['trackIds'], true) && $k['status'] !== 'planned');
        $outTracks[] = [
            'id' => $t['id'], 'title' => $t['title'], 'order' => $t['order'],
            'prerequisites' => $prereqs,
            'prerequisitesReported' => array_key_exists('prerequisites', $t),
            ...academy_curriculum_status($t, $s['schemaVersion']),
            'prerequisitesMet' => !array_filter($prereqs, fn($p) => !$p['met']),
            'started' => $started,
            'measure' => $m,
            'taskCounts' => academy_task_counts(array_filter($tasks, fn($k) => in_array($t['id'], $k['trackIds'], true))),
        ];
    }

    $gaps = array_values(array_filter($comps, fn($c) => in_array($c['status'], ACADEMY_GAPS, true)));
    $unmet = [];
    foreach ($outTracks as $t) {
        foreach ($t['prerequisites'] as $p) {
            if (!$p['met']) $unmet[] = ['trackId' => $t['id'], 'trackTitle' => $t['title'], 'started' => $t['started'],
                                        'prerequisiteId' => $p['id'], 'prerequisiteTitle' => $p['title']];
        }
    }

    return [
        'schemaVersion' => $s['schemaVersion'],
        'updatedAt' => $s['updatedAt'],
        'empty' => !$s['tracks'] && !$s['competencies'] && !$s['tasks'],
        'curriculum' => academy_curriculum_status([], $s['schemaVersion']),
        // snapshot ריק — אין מדד בכלל (לא "0 מתוך 0"), כדי שלא ייראה כהתקדמות
        'measure' => $comps ? academy_measure($comps) : null,
        'tracks' => $outTracks,
        'competencies' => $comps,
        'tasks' => $tasks,
        'taskCounts' => academy_task_counts($tasks),
        'gaps' => $gaps,
        'unmetPrerequisites' => $unmet,
        'recommendations' => academy_recommendations($outTracks, $gaps, $tasks, $unmet),
        'timeline' => academy_timeline($s, $compById, $trackById),
    ];
}

/**
 * מוצע או מאושר. v1 לא מגדיר אישור סילבוס (וה-roadmap במאגר הוא "proposal"), ולכן ב-v1 הכול
 * "proposed" עם מקור "not_in_contract_v1". השדה approval נקרא רק מחוזה אחר מ-v1 — ובפועל רק אחרי
 * שהאימות יתמוך בו. לעולם לא מסיקים "approved" בעצמנו.
 */
function academy_curriculum_status(array $item, string $schemaVersion): array {
    if ($schemaVersion !== ACADEMY_SCHEMA_VERSION && in_array($item['approval'] ?? null, ['proposed', 'approved'], true)) {
        return ['curriculumStatus' => $item['approval'], 'curriculumStatusSource' => 'contract'];
    }
    return ['curriculumStatus' => 'proposed', 'curriculumStatusSource' => 'not_in_contract_v1'];
}

function academy_task_counts(array $tasks): array {
    $out = array_fill_keys(ACADEMY_TASK_STATUSES, 0);
    foreach ($tasks as $t) $out[$t['status']]++;
    return $out;
}

/**
 * המלצות — כולן נגזרות מהנתונים (derived: true), עם הסיבה. לא החלטה של מדריך.
 * סדר: הערכה מחדש → פערים → דרישות קדם שנעקפו → משימות חסומות → המסלול הבא.
 */
function academy_recommendations(array $tracks, array $gaps, array $tasks, array $unmet): array {
    $rec = [];
    foreach ($gaps as $c) {
        $rec[] = $c['status'] === 'reassessment_required'
            ? ['kind' => 'reassess', 'title' => 'הערכה מחדש: ' . $c['title'], 'reason' => 'הכשירות מסומנת "נדרשת הערכה מחדש"',
               'ref' => ['type' => 'competency', 'id' => $c['id'], 'trackId' => $c['trackId']], 'derived' => true]
            : ['kind' => 'close_gap', 'title' => 'סגירת פער: ' . $c['title'], 'reason' => 'הכשירות מסומנת "זוהה פער"',
               'ref' => ['type' => 'competency', 'id' => $c['id'], 'trackId' => $c['trackId']], 'derived' => true];
    }
    usort($rec, fn($a, $b) => ($a['kind'] === 'reassess' ? 0 : 1) <=> ($b['kind'] === 'reassess' ? 0 : 1));
    $byPrereq = [];
    foreach ($unmet as $u) {
        if ($u['started']) $byPrereq[$u['prerequisiteId']][] = $u;
    }
    foreach ($byPrereq as $pid => $us) {
        $names = implode('", "', array_map(fn($u) => $u['trackTitle'], $us));
        $rec[] = ['kind' => 'prerequisite', 'title' => 'להשלים קודם: ' . $us[0]['prerequisiteTitle'],
                  'reason' => (count($us) > 1 ? "המסלולים \"$names\" כבר התחילו" : "המסלול \"$names\" כבר התחיל")
                              . ', ודרישת הקדם עוד לא הוכחה במעשה',
                  'ref' => ['type' => 'track', 'id' => $pid], 'derived' => true];
    }
    foreach ($tasks as $t) {
        if ($t['status'] !== 'blocked') continue;
        $rec[] = ['kind' => 'unblock', 'title' => 'לבדוק חסימה: ' . $t['title'],
                  'reason' => 'המשימה חסומה (סיבת החסימה אינה בחוזה v1)',
                  'ref' => ['type' => 'task', 'id' => $t['id']], 'derived' => true];
    }
    foreach ($tracks as $t) {
        if (!$t['started'] && $t['prerequisitesMet'] && $t['measure']['total'] > 0) {
            $rec[] = ['kind' => 'next_track', 'title' => 'המסלול הבא: ' . $t['title'],
                      'reason' => 'הבא בסדר המסלולים שדרישות הקדם שלו הוכחו ועוד לא התחיל',
                      'ref' => ['type' => 'track', 'id' => $t['id']], 'derived' => true];
            break;
        }
    }
    return $rec;
}

/** ציר זמן — רק ממה שיש בחוזה: updatedAt של ה-snapshot ו-lastAssessedAt של כשירויות. */
function academy_timeline(array $s, array $compById, array $trackById): array {
    $ev = [];
    if ($s['updatedAt']) $ev[] = ['at' => $s['updatedAt'], 'kind' => 'snapshot', 'title' => 'עדכון תמונת המצב', 'ref' => null];
    foreach ($compById as $c) {
        if (!$c['lastAssessedAt']) continue;
        $ev[] = ['at' => $c['lastAssessedAt'], 'kind' => 'assessment', 'title' => $c['title'],
                 'status' => $c['status'], 'trackTitle' => $trackById[$c['trackId']]['title'],
                 'ref' => ['type' => 'competency', 'id' => $c['id'], 'trackId' => $c['trackId']]];
    }
    usort($ev, fn($a, $b) => strtotime($b['at']) <=> strtotime($a['at']));
    return $ev;
}
