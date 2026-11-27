<?php
/**
 * שכבת הנתונים — אימות snapshot מול החוזה v1.
 *
 * החוזה: dashboard/data/schema/v1/academy.schema.json במאגר האקדמיה, קומיט cc85ac1
 * (schemaVersion "1.0.0"). הקוד כאן מממש את הסכמה ידנית (אין ספריית JSON Schema בשרת),
 * ועוד שלוש בדיקות שהסכמה לא יכולה לבטא ובלעדיהן אי אפשר לבנות תמונת מצב נכונה:
 *   1. הפניות: competency.trackId, task.competencyIds ו-track.prerequisites מצביעים על מזהה קיים.
 *   2. מזהים ייחודיים בתוך כל רשימה.
 *   3. אין מעגל בדרישות הקדם בין מסלולים.
 * (נרשמו ב-docs/schema-gaps.md כהצעה להכניס לחוזה.)
 *
 * לא מנחשים: snapshot שאינו תואם — נדחה, עם רשימת שגיאות בעברית ונתיב JSON לכל אחת.
 */

declare(strict_types=1);

const ACADEMY_SCHEMA_VERSION = '1.0.0';
const ACADEMY_SCHEMA_COMMIT  = 'cc85ac1';
const ACADEMY_COMPETENCY_STATUSES = [
    'not_assessed', 'gap_identified', 'learning', 'theory_demonstrated',
    'practice_demonstrated', 'reassessment_required', 'mastered',
];
const ACADEMY_TASK_STATUSES = ['planned', 'active', 'blocked', 'completed'];
const ACADEMY_TASK_MODES    = ['computer', 'mobile_or_voice', 'either'];

/**
 * @return list<string> שגיאות. ריקה = תקין.
 */
function academy_validate(mixed $s): array {
    $err = [];
    if (!is_array($s) || ($s !== [] && array_is_list($s))) return ['$: ה-snapshot חייב להיות אובייקט JSON'];

    $root = ['schemaVersion', 'updatedAt', 'tracks', 'competencies', 'tasks'];
    _av_props($s, '$', $root, $root, $err);

    if (array_key_exists('schemaVersion', $s) && $s['schemaVersion'] !== ACADEMY_SCHEMA_VERSION) {
        $err[] = '$.schemaVersion: גרסת חוזה לא נתמכת (' . _av_show($s['schemaVersion']) . '), נתמכת רק ' . ACADEMY_SCHEMA_VERSION;
    }
    if (array_key_exists('updatedAt', $s)) _av_datetime_or_null($s['updatedAt'], '$.updatedAt', $err);

    $specs = [
        'tracks' => [
            'req' => ['id', 'title', 'order'], 'all' => ['id', 'title', 'order', 'prerequisites'],
        ],
        'competencies' => [
            'req' => ['id', 'trackId', 'title', 'status'], 'all' => ['id', 'trackId', 'title', 'status', 'evidenceRefs', 'lastAssessedAt'],
        ],
        'tasks' => [
            'req' => ['id', 'title', 'status', 'mode'], 'all' => ['id', 'title', 'status', 'mode', 'competencyIds'],
        ],
    ];
    $ids = [];
    foreach ($specs as $list => $spec) {
        $ids[$list] = [];
        if (!array_key_exists($list, $s)) continue;
        if (!is_array($s[$list]) || !array_is_list($s[$list])) { $err[] = "\$.$list: חייב להיות מערך"; continue; }
        foreach ($s[$list] as $i => $item) {
            $p = "\$.{$list}[$i]";
            if (!is_array($item) || ($item !== [] && array_is_list($item))) { $err[] = "$p: חייב להיות אובייקט"; continue; }
            _av_props($item, $p, $spec['req'], $spec['all'], $err);
            foreach (['id', 'title'] as $k) {
                if (array_key_exists($k, $item) && !is_string($item[$k])) $err[] = "$p.$k: חייב להיות מחרוזת";
            }
            if (isset($item['id']) && is_string($item['id'])) {
                if (isset($ids[$list][$item['id']])) $err[] = "$p.id: המזהה \"{$item['id']}\" כבר מופיע ב-$list (נדרש מזהה ייחודי)";
                $ids[$list][$item['id']] = true;
            }
        }
    }

    foreach (_av_items($s, 'tracks') as $i => $t) {
        $p = "\$.tracks[$i]";
        if (array_key_exists('order', $t) && (!is_int($t['order']) || $t['order'] < 0)) $err[] = "$p.order: חייב להיות מספר שלם 0 ומעלה";
        if (array_key_exists('prerequisites', $t) && _av_string_list($t['prerequisites'], "$p.prerequisites", $err)) {
            foreach ($t['prerequisites'] as $j => $ref) {
                if (!isset($ids['tracks'][$ref])) $err[] = "$p.prerequisites[$j]: אין מסלול במזהה \"$ref\"";
                elseif ($ref === ($t['id'] ?? null)) $err[] = "$p.prerequisites[$j]: מסלול לא יכול להיות דרישת קדם של עצמו";
            }
        }
    }
    foreach (_av_items($s, 'competencies') as $i => $c) {
        $p = "\$.competencies[$i]";
        if (array_key_exists('trackId', $c)) {
            if (!is_string($c['trackId'])) $err[] = "$p.trackId: חייב להיות מחרוזת";
            elseif (!isset($ids['tracks'][$c['trackId']])) $err[] = "$p.trackId: אין מסלול במזהה \"{$c['trackId']}\"";
        }
        if (array_key_exists('status', $c) && !in_array($c['status'], ACADEMY_COMPETENCY_STATUSES, true)) {
            $err[] = "$p.status: ערך לא מוכר " . _av_show($c['status']) . ' (מותר: ' . implode(', ', ACADEMY_COMPETENCY_STATUSES) . ')';
        }
        if (array_key_exists('evidenceRefs', $c)) _av_string_list($c['evidenceRefs'], "$p.evidenceRefs", $err);
        if (array_key_exists('lastAssessedAt', $c)) _av_datetime_or_null($c['lastAssessedAt'], "$p.lastAssessedAt", $err);
    }
    foreach (_av_items($s, 'tasks') as $i => $t) {
        $p = "\$.tasks[$i]";
        if (array_key_exists('status', $t) && !in_array($t['status'], ACADEMY_TASK_STATUSES, true)) {
            $err[] = "$p.status: ערך לא מוכר " . _av_show($t['status']) . ' (מותר: ' . implode(', ', ACADEMY_TASK_STATUSES) . ')';
        }
        if (array_key_exists('mode', $t) && !in_array($t['mode'], ACADEMY_TASK_MODES, true)) {
            $err[] = "$p.mode: ערך לא מוכר " . _av_show($t['mode']) . ' (מותר: ' . implode(', ', ACADEMY_TASK_MODES) . ')';
        }
        if (array_key_exists('competencyIds', $t) && _av_string_list($t['competencyIds'], "$p.competencyIds", $err)) {
            foreach ($t['competencyIds'] as $j => $ref) {
                if (!isset($ids['competencies'][$ref])) $err[] = "$p.competencyIds[$j]: אין כשירות במזהה \"$ref\"";
            }
        }
    }

    if (!$err) {
        $cycle = _av_prereq_cycle(_av_items($s, 'tracks'));
        if ($cycle) $err[] = '$.tracks: מעגל בדרישות הקדם — ' . implode(' ← ', $cycle);
    }
    return $err;
}

/** פריטים תקינים מבנית בלבד (אובייקטים) — כדי שבדיקות ההמשך לא יקרסו על פריט פגום. */
function _av_items(array $s, string $list): array {
    if (!isset($s[$list]) || !is_array($s[$list]) || !array_is_list($s[$list])) return [];
    return array_filter($s[$list], fn($x) => is_array($x) && ($x === [] || !array_is_list($x)));
}

function _av_props(array $obj, string $p, array $required, array $allowed, array &$err): void {
    foreach ($required as $k) if (!array_key_exists($k, $obj)) $err[] = "$p: חסר שדה חובה \"$k\"";
    foreach (array_keys($obj) as $k) {
        if (!in_array($k, $allowed, true)) $err[] = "$p: שדה \"$k\" אינו קיים בחוזה v1";
    }
}

function _av_string_list(mixed $v, string $p, array &$err): bool {
    if (!is_array($v) || !array_is_list($v)) { $err[] = "$p: חייב להיות מערך"; return false; }
    foreach ($v as $j => $x) if (!is_string($x)) { $err[] = "{$p}[$j]: חייב להיות מחרוזת"; return false; }
    return true;
}

/** date-time לפי RFC 3339 (כמו format: date-time ב-JSON Schema), או null. */
function _av_datetime_or_null(mixed $v, string $p, array &$err): void {
    if ($v === null) return;
    if (!is_string($v) || !academy_is_datetime($v)) $err[] = "$p: חייב להיות תאריך-שעה בתבנית RFC 3339 (למשל 2026-11-01T10:00:00Z) או null";
}

function academy_is_datetime(string $v): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/', $v, $m)) return false;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 61;
}

function _av_show(mixed $v): string {
    return (string) json_encode($v, JSON_UNESCAPED_UNICODE);
}

/** @return list<string> מסלול של מעגל, או [] */
function _av_prereq_cycle(array $tracks): array {
    $g = [];
    foreach ($tracks as $t) $g[(string) $t['id']] = $t['prerequisites'] ?? [];
    $state = [];
    $stack = [];
    $visit = function (string $n) use (&$visit, &$state, &$stack, $g): array {
        $state[$n] = 1; $stack[] = $n;
        foreach ($g[$n] ?? [] as $m) {
            if (($state[$m] ?? 0) === 1) return array_merge(array_slice($stack, (int) array_search($m, $stack, true)), [$m]);
            if (($state[$m] ?? 0) === 0) { $c = $visit($m); if ($c) return $c; }
        }
        array_pop($stack); $state[$n] = 2;
        return [];
    };
    foreach (array_keys($g) as $n) if (($state[$n] ?? 0) === 0) { $c = $visit((string) $n); if ($c) return $c; }
    return [];
}
