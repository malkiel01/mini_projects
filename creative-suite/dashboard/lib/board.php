<?php
/**
 * החבילה בלוח "מטלות לקלוד" — מה שהמרכז והייבוא צריכים לדעת מצד הדשבורד. רק פונקציות ונתונים, בלי פלט.
 *
 * הדשבורד הוא לקוח של ה-API של הלוח (האפיון, הכרעה 1). הוא לא שומר אסימון: הקריאות יוצאות מהדפדפן של בעל המערכת,
 * עם עוגיית הלוח — שני הכלים באותו דומיין. כאן רק: השם שמזהה פרויקט של החבילה, הקטלוג, ותוכנית הייבוא מהמסמכים.
 */
declare(strict_types=1);

require_once __DIR__ . '/docs.php';

/** פרויקט בלוח ששמו מתחיל בזה — שייך לחבילה. עד שללוח תהיה תגית `suite` (תוספת לבקשה). */
const SUITE_PREFIX = 'חבילת היצירה · ';
/** הריפו שכל פרויקטי החבילה יושבים בו. */
const SUITE_REPO = ['owner' => 'malkiel01', 'name' => 'mini_projects', 'branch' => 'main'];

/**
 * קטלוג סוגי האישור — האפיון, סעיף 1.3. `always`: yes = אפשר "תמיד" · warn = רק בהקלדת שם הסוג · no = לעולם לא.
 * עד שהלוח יחזיק מדיניות (שלב 1) — כאן לתצוגה בלבד, וכל סוג הוא "פעם אחת".
 */
const APPROVAL_TYPES = [
    ['type' => 'merge-own-pr',          'label' => 'מיזוג PR שפתחתי לענף שלי ל-main',            'always' => 'yes',  'settings' => 'mcp__github__merge_pull_request'],
    ['type' => 'push-own-branch',       'label' => 'push לענף שלי',                               'always' => 'yes',  'settings' => 'Bash(git push -u origin:*)'],
    ['type' => 'send-team-message',     'label' => 'שליחת הודעה לצ\'אט אחר בצוות',                'always' => 'yes',  'settings' => 'mcp__claude-code-remote__create_trigger'],
    ['type' => 'format-change-request', 'label' => 'בקשת שינוי בפורמט / בסכמה',                  'always' => 'yes',  'settings' => ''],
    ['type' => 'add-vendor-lib',        'label' => 'הוספת ספרייה ל-vendor/ (MIT / BSD / Apache)', 'always' => 'yes',  'settings' => ''],
    ['type' => 'add-exclude',           'label' => 'הוספת --exclude ב-deploy.yml לתיקיית נתונים', 'always' => 'warn', 'settings' => 'Edit(.github/workflows/deploy.yml) — פעם אחת'],
    ['type' => 'change-deploy',         'label' => 'כל שינוי אחר ב-deploy.yml',                   'always' => 'no',   'settings' => 'deny אם "דחה תמיד"'],
    ['type' => 'delete-files',          'label' => 'מחיקת קבצים בריפו (לא בתיקייה שלי)',          'always' => 'no',   'settings' => 'deny'],
    ['type' => 'delete-user-data',      'label' => 'מחיקת נתוני משתמשים / מסד בשרת',              'always' => 'no',   'settings' => 'deny'],
    ['type' => 'secrets',               'label' => 'קריאה / כתיבה של סודות',                      'always' => 'no',   'settings' => 'מחוץ להישג ממילא'],
    ['type' => 'touch-other-project',   'label' => 'נגיעה בתיקייה של פרויקט אחר',                 'always' => 'no',   'settings' => 'deny לנתיבים של אחרים'],
];

/** "ר2: ..." / "ג3, הכנה: ..." — קוד השלב שבתחילת היעד, אם יש. */
function stage_of(string $text): ?string
{
    return preg_match('/^([א-ת]\d+)\s*[,:—]/u', $text, $m) ? $m[1] : null;
}

/** כותרת למטלה: טקסט נקי, עד 190 תווים. */
function task_title(string $text): string
{
    return mb_strimwidth(preg_replace('/\s+/u', ' ', plain($text)), 0, 190, '…');
}

/** ההערה הראשונה במטלה — "מדריך:" ו"פרומפט:" מהמסמך (האפיון, 1.1). */
function first_note(array $g): string
{
    $out = [];
    if ($g['guide']) {
        $out[] = "מה לעשות:\n" . implode("\n", array_map(fn($i, $s) => ($i + 1) . '. ' . plain($s), array_keys($g['guide']), $g['guide']));
    }
    if ($g['prompt'] !== '') $out[] = "פרומפט:\n" . $g['prompt'];
    return implode("\n\n", $out);
}

/**
 * תוכנית הייבוא — מה ייווצר בלוח מכל מסמך ב-docs/projects (האפיון, שלב 4).
 * פרויקט לכל שורה ב"## 3. הפרויקטים" שיש לה מסמך; נושא לכל שלב (ר2, ג3…) או "כללי"; יעדי "התקדמות" ← open / done;
 * "ממתין לבעל המערכת" ← מטלה ב-blocked (פתוחה) או done, וההדרכה שלה — הערה ראשונה. "שיפורים מומלצים" נשארים במסמך.
 */
function import_plan(): array
{
    $plan = [];
    foreach (projects() as $p) {
        $doc = $p['doc'];
        if (!$doc || !empty($doc['missing'])) continue;
        $rel = 'creative-suite/docs/' . $doc['file'];
        $topics = [];
        $add = function (string $topic, array $task) use (&$topics) {
            $topics[$topic] ??= [];
            $topics[$topic][] = $task;
        };
        foreach ($p['goals'] as $g) {
            $stage = stage_of(plain($g['text']));
            $add($p['name'] . ' · ' . ($stage ?? 'כללי'), [
                'title' => task_title($g['text']), 'kind' => 'code', 'status' => $g['done'] ? 'done' : 'open',
                'body' => plain($g['text']) . "\n\nמקור: $rel, \"התקדמות\".", 'note' => first_note($g), 'note_kind' => 'comment',
            ]);
        }
        foreach ($p['owner'] as $g) {
            $add($p['name'] . ' · ממתין לבעל המערכת', [
                'title' => task_title($g['text']), 'kind' => 'question', 'status' => $g['done'] ? 'done' : 'blocked',
                'body' => plain($g['text']) . "\n\nמקור: $rel, \"ממתין לבעל המערכת\".", 'note' => first_note($g), 'note_kind' => 'question',
            ]);
        }
        if (!$topics) continue;
        $plan[] = [
            'project' => SUITE_PREFIX . $p['name'], 'name' => $p['name'], 'icon' => $p['icon'], 'doc' => $rel,
            'description' => "suite: creative\npath: " . trim($p['folder'], '` ') . "\ndoc: $rel",
            'topics' => array_map(fn($name, $tasks) => ['name' => $name, 'tasks' => $tasks], array_keys($topics), $topics),
        ];
    }
    return $plan;
}

/** אייקון לכל פרויקט של החבילה, לפי השם בלוח — כדי שהמרכז ייראה כמו הדשבורד. */
function suite_icons(): array
{
    $out = [];
    foreach (projects() as $p) $out[SUITE_PREFIX . $p['name']] = $p['icon'];
    return $out;
}
