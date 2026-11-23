<?php
/**
 * שכבת הנתונים — אימות snapshot מול חוזה v2 (הצעה).
 *
 * המקור: dashboard/data/schema/v2/academy-with-lessons-proposal.schema.json במאגר האקדמיה (schemaVersion "2.0.0"),
 * ו-dashboard/data/schema/v2/migration-and-validation-proposal.md — כללי ההפניות והסטטוס. התוכן הועבר לצוות על ידי
 * הבקרה; הקבצים לא הועתקו לריפו. v2 מסומן במאגר "PROPOSED ONLY": הדשבורד יודע לקרוא אותו, אבל המקור כבוי.
 *
 * שתי שכבות:
 *   1. מקבילה ל-JSON Schema — סוגים, enums, שדות חובה, additionalProperties/unevaluatedProperties, pattern של id,
 *      uniqueItems, minLength/minItems, date-time.
 *   2. כללי מסמך המעבר — מזהים ייחודיים, הפניות לישות קיימת, dependsOn בלי עצמה ובלי מחזור, curriculum approved⇔
 *      approvedAt+approvedBy, mastered⇔ראיה + הערכה מאושרת, evidence.reference בטוח.
 */

declare(strict_types=1);

require_once __DIR__ . '/validate.php';

const ACADEMY_SCHEMA_V2 = '2.0.0';
const ACADEMY_V2_ID = '/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/';
// לאילו רשימות timeline.referenceId רשאי להפנות, לפי kind (ראיה מותרת תמיד)
const ACADEMY_V2_TIMELINE_TARGETS = [
    'task' => ['tasks'],
    'assessment' => ['assessments'],
    'curriculum' => ['stages', 'tracks', 'modules', 'lessons', 'topics', 'skills'],
    'decision' => ['stages', 'tracks', 'modules', 'lessons', 'topics', 'skills', 'assessments'],
];
const ACADEMY_V2_LISTS = ['stages', 'tracks', 'modules', 'topics', 'skills', 'competencies', 'tasks', 'assessments', 'evidence', 'timeline', 'lessons'];

/**
 * מפרט כל ישות: שדה → [חובה?, סוג]. סוגים: id · ids · str · str1 (minLength 1) · int0 · bool · strs · strs1 (minItems 1,
 * minLength 1) · dt · dt? · str? · enum:a|b
 */
function academy_v2_spec(): array {
    $base = ['id' => [1, 'id'], 'title' => [1, 'str1'], 'order' => [1, 'int0'], 'description' => [0, 'str'], 'prerequisites' => [0, 'ids']];
    return [
        'stages'  => $base + ['trackIds' => [1, 'ids']],
        'tracks'  => $base + ['stageId' => [1, 'id'], 'approvalStatus' => [1, 'enum:proposed|approved'], 'instructorId' => [0, 'str?']],
        'modules' => $base + ['trackId' => [1, 'id'], 'learningObjectives' => [1, 'strs'], 'labRequired' => [1, 'bool'], 'lessonIds' => [0, 'ids']],
        'topics'  => $base + ['moduleId' => [1, 'id']],
        'skills'  => $base + ['moduleId' => [1, 'id'], 'assessmentCriteria' => [1, 'strs']],
        'competencies' => ['id' => [1, 'id'], 'skillId' => [1, 'id'],
            'status' => [1, 'enum:' . implode('|', ACADEMY_COMPETENCY_STATUSES)], 'evidenceRefs' => [1, 'ids'], 'lastAssessedAt' => [1, 'dt?']],
        'tasks' => ['id' => [1, 'id'], 'title' => [1, 'str'], 'status' => [1, 'enum:planned|active|blocked|completed'],
            'mode' => [1, 'enum:computer|mobile_or_voice|either'], 'skillIds' => [1, 'ids'], 'dependsOn' => [1, 'ids'], 'authorizedLab' => [0, 'bool']],
        'assessments' => ['id' => [1, 'id'], 'skillIds' => [1, 'ids'], 'status' => [1, 'enum:planned|in_progress|evaluated'],
            'rubric' => [1, 'strs'], 'evidenceRefs' => [1, 'ids'], 'assessedAt' => [0, 'dt?'], 'approvedBy' => [0, 'str?']],
        'evidence' => ['id' => [1, 'id'], 'kind' => [1, 'enum:command_output|lab_report|quiz|oral_explanation|code|other'],
            'reference' => [1, 'str'], 'createdAt' => [1, 'dt'], 'visibility' => [0, 'enum:private|restricted']],
        'timeline' => ['id' => [1, 'id'], 'kind' => [1, 'enum:curriculum|task|assessment|decision'], 'at' => [1, 'dt'],
            'title' => [1, 'str'], 'referenceId' => [0, 'refid?']],
        'lessons' => ['id' => [1, 'id'], 'moduleId' => [1, 'id'], 'title' => [1, 'str1'], 'kind' => [1, 'enum:theory|analysis|lab'],
            'order' => [1, 'int0'], 'learningObjectives' => [1, 'strs1'], 'assessmentMode' => [1, 'enum:computer|mobile_or_voice|either'],
            'authorizedLabRequired' => [0, 'bool']],
    ];
}

/** @return list<string> שגיאות בעברית עם נתיב JSON. ריקה = תקין. */
function academy_validate_v2(mixed $s): array {
    $err = [];
    if (!is_array($s) || ($s !== [] && array_is_list($s))) return ['$: ה-snapshot חייב להיות אובייקט JSON'];
    $root = array_merge(['schemaVersion', 'updatedAt', 'curriculum'], ACADEMY_V2_LISTS);
    _av_props($s, '$', $root, $root, $err);
    if (array_key_exists('schemaVersion', $s) && $s['schemaVersion'] !== ACADEMY_SCHEMA_V2) {
        $err[] = '$.schemaVersion: חייב להיות ' . ACADEMY_SCHEMA_V2;
    }
    if (array_key_exists('updatedAt', $s)) _av2_type($s['updatedAt'], 'dt?', '$.updatedAt', $err);

    // curriculum
    $cur = $s['curriculum'] ?? null;
    if (array_key_exists('curriculum', $s)) {
        if (!_av2_obj($cur)) $err[] = '$.curriculum: חייב להיות אובייקט';
        else {
            $spec = ['status' => [1, 'enum:proposed|approved'], 'version' => [1, 'str'], 'approvedAt' => [1, 'dt?'], 'approvedBy' => [1, 'str?']];
            _av2_item($cur, '$.curriculum', $spec, $err);
            if (($cur['status'] ?? null) === 'approved' && (empty($cur['approvedAt']) || empty($cur['approvedBy']))) {
                $err[] = '$.curriculum: סילבוס מאושר (approved) מחייב approvedAt ו-approvedBy';
            }
            if (($cur['status'] ?? null) === 'proposed' && (($cur['approvedAt'] ?? null) !== null || ($cur['approvedBy'] ?? null) !== null)) {
                $err[] = '$.curriculum: סילבוס מוצע (proposed) מחייב ש-approvedAt ו-approvedBy יהיו null';
            }
        }
    }

    // מבנה כל רשימה + מזהים ייחודיים
    $spec = academy_v2_spec();
    $ids = [];
    $items = [];
    foreach (ACADEMY_V2_LISTS as $list) {
        $ids[$list] = [];
        $items[$list] = [];
        if (!array_key_exists($list, $s)) continue;
        if (!is_array($s[$list]) || !array_is_list($s[$list])) { $err[] = "\$.$list: חייב להיות מערך"; continue; }
        foreach ($s[$list] as $i => $item) {
            $p = "\$.{$list}[$i]";
            if (!_av2_obj($item)) { $err[] = "$p: חייב להיות אובייקט"; continue; }
            _av2_item($item, $p, $spec[$list], $err);
            $items[$list][$i] = $item;
            if (isset($item['id']) && is_string($item['id'])) {
                if (isset($ids[$list][$item['id']])) $err[] = "$p.id: המזהה \"{$item['id']}\" כבר מופיע ב-$list (נדרש מזהה ייחודי)";
                $ids[$list][$item['id']] = $item;
            }
        }
    }

    // הפניות
    $ref = function (array $item, string $p, string $field, string $target, bool $many = false) use (&$err, $ids) {
        if (!array_key_exists($field, $item)) return;
        $vals = $many ? (is_array($item[$field]) && array_is_list($item[$field]) ? $item[$field] : []) : [$item[$field]];
        foreach ($vals as $j => $v) {
            if (!is_string($v)) continue;
            if (!isset($ids[$target][$v])) $err[] = $p . ".$field" . ($many ? "[$j]" : '') . ": אין ב-$target מזהה \"$v\"";
        }
    };
    foreach (['stages', 'tracks', 'modules', 'topics', 'skills'] as $list) {
        foreach ($items[$list] as $i => $it) {
            $ref($it, "\$.{$list}[$i]", 'prerequisites', $list, true);
            if (in_array($it['id'] ?? null, (array) ($it['prerequisites'] ?? []), true)) $err[] = "\$.{$list}[$i].prerequisites: ישות לא יכולה להיות דרישת קדם של עצמה";
        }
    }
    foreach ($items['stages'] as $i => $it) {
        $ref($it, "\$.stages[$i]", 'trackIds', 'tracks', true);
        foreach ((array) ($it['trackIds'] ?? []) as $j => $tid) {
            $t = $ids['tracks'][$tid] ?? null;
            if (is_array($t) && ($t['stageId'] ?? null) !== ($it['id'] ?? null)) {
                $err[] = "\$.stages[$i].trackIds[$j]: המסלול \"$tid\" שייך ל-stage אחר (stageId)";
            }
        }
    }
    foreach ($items['tracks'] as $i => $it) $ref($it, "\$.tracks[$i]", 'stageId', 'stages');
    foreach ($items['modules'] as $i => $it) {
        $ref($it, "\$.modules[$i]", 'trackId', 'tracks');
        $ref($it, "\$.modules[$i]", 'lessonIds', 'lessons', true);
        foreach ((array) ($it['lessonIds'] ?? []) as $j => $lid) {
            $l = $ids['lessons'][$lid] ?? null;
            if (is_array($l) && ($l['moduleId'] ?? null) !== ($it['id'] ?? null)) {
                $err[] = "\$.modules[$i].lessonIds[$j]: השיעור \"$lid\" שייך למודול אחר (moduleId)";
            }
        }
    }
    foreach (['topics', 'skills', 'lessons'] as $list) {
        foreach ($items[$list] as $i => $it) $ref($it, "\$.{$list}[$i]", 'moduleId', 'modules');
    }
    foreach ($items['competencies'] as $i => $it) {
        $ref($it, "\$.competencies[$i]", 'skillId', 'skills');
        $ref($it, "\$.competencies[$i]", 'evidenceRefs', 'evidence', true);
    }
    foreach ($items['assessments'] as $i => $it) {
        $ref($it, "\$.assessments[$i]", 'skillIds', 'skills', true);
        $ref($it, "\$.assessments[$i]", 'evidenceRefs', 'evidence', true);
    }
    foreach ($items['tasks'] as $i => $it) {
        $ref($it, "\$.tasks[$i]", 'skillIds', 'skills', true);
        $ref($it, "\$.tasks[$i]", 'dependsOn', 'tasks', true);
        if (in_array($it['id'] ?? null, (array) ($it['dependsOn'] ?? []), true)) $err[] = "\$.tasks[$i].dependsOn: משימה לא יכולה להיות תלויה בעצמה";
    }

    // timeline.referenceId — חייב להפנות לישות קיימת: ראיה, או ישות לפי kind
    foreach ($items['timeline'] as $i => $it) {
        $rid = $it['referenceId'] ?? null;
        if (!is_string($rid) || !preg_match(ACADEMY_V2_ID, $rid) || str_contains($rid, '..')) continue; // הצורה נבדקה בסכמה
        $targets = array_merge(['evidence'], ACADEMY_V2_TIMELINE_TARGETS[$it['kind'] ?? ''] ?? []);
        $found = false;
        foreach ($targets as $t) if (isset($ids[$t][$rid])) { $found = true; break; }
        if (!$found) $err[] = "\$.timeline[$i].referenceId: אין מזהה \"$rid\" ב-" . implode('/', $targets);
    }

    // evidence.reference בטוח
    foreach ($items['evidence'] as $i => $it) {
        if (isset($it['reference']) && is_string($it['reference']) && ($why = academy_v2_unsafe_reference($it['reference']))) {
            $err[] = "\$.evidence[$i].reference: הפניה לא בטוחה — $why";
        }
    }

    // mastered: ראיה קיימת + הערכה מאושרת על אותה מיומנות
    foreach ($items['competencies'] as $i => $it) {
        if (($it['status'] ?? null) !== 'mastered') continue;
        $refs = is_array($it['evidenceRefs'] ?? null) ? $it['evidenceRefs'] : [];
        $okEvidence = $refs && !array_filter($refs, fn($r) => !is_string($r) || !isset($ids['evidence'][$r])
            || academy_v2_unsafe_reference((string) ($ids['evidence'][$r]['reference'] ?? '')));
        if (!$okEvidence) $err[] = "\$.competencies[$i].status: mastered מחייב ראיה תקינה אחת לפחות (evidenceRefs)";
        $approved = array_filter($items['assessments'], fn($a) => ($a['status'] ?? null) === 'evaluated'
            && in_array($it['skillId'] ?? null, (array) ($a['skillIds'] ?? []), true)
            && !empty($a['approvedBy']) && !empty($a['assessedAt']));
        if (!$approved) $err[] = "\$.competencies[$i].status: mastered מחייב הערכה מתועדת (evaluated) על המיומנות, עם assessedAt ו-approvedBy";
    }

    // מחזורים — רק כשהמבנה תקין, כדי לא לקרוס על נתונים שבורים
    if (!$err) {
        $c = _av2_cycle($items['tasks'], 'dependsOn');
        if ($c) $err[] = '$.tasks: מחזור בתלויות (dependsOn) — ' . implode(' ← ', $c);
        foreach (['stages', 'tracks', 'modules', 'topics', 'skills'] as $list) {
            $c = _av2_cycle($items[$list], 'prerequisites');
            if ($c) $err[] = "\$.$list: מעגל בדרישות הקדם — " . implode(' ← ', $c);
        }
    }
    return $err;
}

/** מחזיר סיבה אם ההפניה לא בטוחה, אחרת ''. מותר: נתיב יחסי בלי .. , או https. */
function academy_v2_unsafe_reference(string $r): string {
    $t = trim($r);
    if ($t === '') return 'ריקה';
    if (preg_match('/(gh[pousr]_[A-Za-z0-9]{8,}|github_pat_|-----BEGIN|AKIA[0-9A-Z]{12,})/', $t)) return 'נראית כמו מפתח או אסימון';
    if (preg_match('/(password|passwd|secret|api[_-]?key|apikey|token|bearer|private[_-]?key|credential)/i', $t)) return 'מכילה מילה של סוד (סיסמה / מפתח / אסימון)';
    if (preg_match('~(^|/)\.\.(/|$)~', $t)) return 'מכילה ..';
    if (preg_match('#(^/|^~|^[A-Za-z]:[\\\\/]|\\\\)#', $t)) return 'נתיב מוחלט';
    if (preg_match('~(^|/)(\.env|\.ssh|\.git|id_rsa|id_ed25519|data/config)~i', $t)) return 'נתיב רגיש';
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $t) && !preg_match('~^https://~i', $t)) return 'רק https או נתיב יחסי';
    if (preg_match('~[?&](sig|signature|key|token|auth|access_token|x-amz-[a-z-]+)=~i', $t)) return 'קישור עם פרמטר הרשאה';
    return '';
}

function _av2_obj(mixed $v): bool { return is_array($v) && ($v === [] || !array_is_list($v)); }

function _av2_item(array $item, string $p, array $spec, array &$err): void {
    foreach ($spec as $k => [$req, $type]) {
        if (!array_key_exists($k, $item)) { if ($req) $err[] = "$p: חסר שדה חובה \"$k\""; continue; }
        _av2_type($item[$k], $type, "$p.$k", $err);
    }
    foreach (array_keys($item) as $k) if (!isset($spec[$k])) $err[] = "$p: שדה \"$k\" אינו קיים בחוזה v2";
}

function _av2_type(mixed $v, string $type, string $p, array &$err): void {
    if (str_starts_with($type, 'enum:')) {
        $opts = explode('|', substr($type, 5));
        if (!in_array($v, $opts, true)) $err[] = "$p: ערך לא מוכר " . _av_show($v) . ' (מותר: ' . implode(', ', $opts) . ')';
        return;
    }
    $isId = fn($x) => is_string($x) && preg_match(ACADEMY_V2_ID, $x);
    switch ($type) {
        case 'id':   if (!$isId($v)) $err[] = "$p: מזהה לא תקין (אותיות לטיניות, ספרות, . _ -)"; break;
        case 'ids':
            if (!is_array($v) || !array_is_list($v)) { $err[] = "$p: חייב להיות מערך מזהים"; break; }
            foreach ($v as $j => $x) if (!$isId($x)) $err[] = "{$p}[$j]: מזהה לא תקין";
            if (count($v) !== count(array_unique(array_map('json_encode', $v)))) $err[] = "$p: מזהה כפול ברשימה";
            break;
        case 'str':  if (!is_string($v)) $err[] = "$p: חייב להיות מחרוזת"; break;
        case 'str1': if (!is_string($v) || $v === '') $err[] = "$p: חייב להיות מחרוזת לא ריקה"; break;
        case 'refid?': // מזהה בלבד — לא נתיב: בלי /, בלי .., לא מתחיל בנקודה
            if ($v !== null && (!$isId($v) || str_contains($v, '..'))) $err[] = "$p: חייב להיות מזהה (בלי /, .. או נתיב) או null";
            break;
        case 'str?': if ($v !== null && !is_string($v)) $err[] = "$p: חייב להיות מחרוזת או null"; break;
        case 'int0': if (!is_int($v) || $v < 0) $err[] = "$p: חייב להיות מספר שלם 0 ומעלה"; break;
        case 'bool': if (!is_bool($v)) $err[] = "$p: חייב להיות true או false"; break;
        case 'strs':
        case 'strs1':
            if (!is_array($v) || !array_is_list($v)) { $err[] = "$p: חייב להיות מערך מחרוזות"; break; }
            if ($type === 'strs1' && !$v) $err[] = "$p: נדרש פריט אחד לפחות";
            foreach ($v as $j => $x) {
                if (!is_string($x) || ($type === 'strs1' && $x === '')) $err[] = "{$p}[$j]: חייב להיות מחרוזת" . ($type === 'strs1' ? ' לא ריקה' : '');
            }
            break;
        case 'dt':   if (!is_string($v) || !academy_is_datetime($v)) $err[] = "$p: חייב להיות תאריך-שעה RFC 3339"; break;
        // תאריך בלבד (YYYY-MM-DD, כשהשעה לא ידועה — בלי חצות מומצאת) או תאריך-שעה RFC 3339. נשמר כמו שנשלח.
        case 'date_or_dt':
            $okDate = is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
            if (!$okDate && (!is_string($v) || !academy_is_datetime($v))) $err[] = "$p: חייב להיות תאריך YYYY-MM-DD או תאריך-שעה RFC 3339";
            break;
        case 'dt?':  if ($v !== null && (!is_string($v) || !academy_is_datetime($v))) $err[] = "$p: חייב להיות תאריך-שעה RFC 3339 או null"; break;
    }
}

/** @return list<string> מחזור לפי שדה הפניות, או [] */
function _av2_cycle(array $items, string $field): array {
    $g = [];
    foreach ($items as $it) $g[(string) $it['id']] = (array) ($it[$field] ?? []);
    $state = []; $stack = [];
    $visit = function (string $n) use (&$visit, &$state, &$stack, $g): array {
        $state[$n] = 1; $stack[] = $n;
        foreach ($g[$n] ?? [] as $m) {
            if (($state[$m] ?? 0) === 1) return array_merge(array_slice($stack, (int) array_search($m, $stack, true)), [$m]);
            if (($state[$m] ?? 0) === 0 && isset($g[$m])) { $c = $visit($m); if ($c) return $c; }
        }
        array_pop($stack); $state[$n] = 2;
        return [];
    };
    foreach (array_keys($g) as $n) if (($state[$n] ?? 0) === 0) { $c = $visit((string) $n); if ($c) return $c; }
    return [];
}
