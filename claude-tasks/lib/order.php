<?php
/**
 * סידור אוטומטי של המטלות בתוך נושא.
 *
 * החלק השני של "שקלוד יסדר לבד": אחרי שהמטלה נכנסה לנושא הנכון, יש
 * לקבוע באיזה סדר לעבוד עליהן. הסדר נשמר בעמודת seq, ומשם התור מושך.
 *
 * שלוש שכבות, כל אחת גוברת על שלפניה:
 *   1. הערכה לפי שלב  — תשתית לפני לוגיקה, לוגיקה לפני עיצוב, בדיקות בסוף.
 *   2. תלות מפורשת    — "#12" בטקסט אומר שהמטלה תלויה ב-12, ולכן באה אחריה.
 *   3. המודל          — כשיש מפתח, הוא מסדר לפי הבנת התוכן; התלות עדיין
 *                       נאכפת עליו, כי הוא לא אמור לשבור תלות מפורשת.
 */

declare(strict_types=1);

require_once __DIR__ . '/classify.php';

/**
 * שלב בחיי הפיצ'ר. מטלה שלא מזוהה מקבלת את האמצע, כך שהמזוהות
 * מסתדרות סביבה ולא נדחפות לקצוות.
 */
const STAGE_HINTS = [
    1.0 => ['תשתית','הקמה','סכימה','מסד','נתונים','טבלה','שרת','הגדרה','קונפיג','אימות','התחברות','הרשאות','api',
            'setup','config','schema','database','migration','auth','scaffold','boilerplate'],
    2.0 => ['לוגיקה','מימוש','פיצר','פונקציה','חישוב','סנכרון','אלגוריתם','שמירה','טעינה',
            'logic','implement','feature','sync','endpoint','handler'],
    3.0 => ['ממשק','עיצוב','מסך','כפתור','צבע','גופן','אנימציה','רספונסיבי','נגישות',
            'ui','ux','css','style','layout','icon','responsive'],
    4.0 => ['בדיקה','בדיקות','תיעוד','ניקוי','רפקטור','אופטימיזציה','שיפור','ביצועים',
            'test','tests','docs','readme','refactor','cleanup','polish','optimize'],
];

const PRIORITY_RANK = ['high' => 0, 'normal' => 1, 'low' => 2];

function stageOf(array $task): float {
    $tokens = array_flip(array_merge(
        textTokens((string) $task['title']),
        array_slice(textTokens((string) ($task['body'] ?? '')), 0, 40)
    ));
    foreach (STAGE_HINTS as $stage => $words) {
        foreach ($words as $w) if (isset($tokens[stemWord(normalizeText($w))])) return (float) $stage;
    }
    return 2.5;
}

/** "#12" בכותרת או בגוף — המטלה תלויה במטלה 12. */
function dependenciesOf(array $task, array $known): array {
    preg_match_all('/#(\d+)/', (string) $task['title'] . ' ' . (string) ($task['body'] ?? ''), $m);
    $deps = [];
    foreach ($m[1] as $id) {
        $id = (int) $id;
        if ($id !== (int) $task['id'] && isset($known[$id])) $deps[] = $id;
    }
    return array_values(array_unique($deps));
}

/** סדר בסיסי: דחוף קודם, אחר כך שלב, אחר כך ותק. יציב וצפוי. */
function heuristicOrder(array $tasks): array {
    $keyed = [];
    foreach ($tasks as $i => $t) {
        $keyed[] = [
            'id'   => (int) $t['id'],
            'sort' => [
                PRIORITY_RANK[$t['priority']] ?? 1,
                stageOf($t),
                (int) $t['created_at'],
                (int) $t['id'],
            ],
            'i' => $i,
        ];
    }
    usort($keyed, fn($a, $b) => $a['sort'] <=> $b['sort'] ?: $a['i'] <=> $b['i']);
    return array_column($keyed, 'id');
}

/**
 * מכבד תלות בלי לזרוק את הסדר המבוקש.
 *
 * מיון טופולוגי יציב: בכל צעד נבחרת המטלה המוקדמת ביותר ב-$desired
 * שכל מה שהיא תלויה בו כבר יצא. מעגל תלות (א מחכה לב, ב מחכה לא) לא
 * מקפיא את הרשימה — משחררים את הראשונה בסדר המבוקש וממשיכים.
 */
function applyDependencies(array $desired, array $depsByTask): array {
    $remaining = $desired;
    $done      = [];
    $out       = [];

    while ($remaining) {
        $picked = null;
        foreach ($remaining as $idx => $id) {
            $ready = true;
            foreach ($depsByTask[$id] ?? [] as $dep) {
                if (in_array($dep, $remaining, true) && !isset($done[$dep])) { $ready = false; break; }
            }
            if ($ready) { $picked = $idx; break; }
        }
        if ($picked === null) $picked = 0;          // מעגל — פורצים אותו בסדר המבוקש

        $id = $remaining[$picked];
        $out[] = $id;
        $done[$id] = true;
        array_splice($remaining, $picked, 1);
    }
    return $out;
}

function orderPrompt(array $tasks): string {
    $lines = [];
    foreach ($tasks as $t) {
        $extra = [];
        if ($t['priority'] !== 'normal') $extra[] = $t['priority'] === 'high' ? 'דחוף' : 'נמוך';
        $extra[] = ['code' => 'קוד', 'question' => 'שאלה', 'research' => 'מחקר'][$t['kind']] ?? $t['kind'];
        $lines[] = "- id={$t['id']} [" . implode(', ', $extra) . '] ' . mb_substr((string) $t['title'], 0, 160);
    }
    $list = implode("\n", $lines);

    return <<<TXT
    להלן מטלות פתוחות של נושא אחד, בלי סדר מיוחד:
    $list

    סדר אותן לפי סדר העבודה ההגיוני: מה שחוסם אחרים קודם, תשתית לפני מה
    שנשען עליה, ומטלה דחופה מוקדם ככל שהיא לא תלויה במשהו אחר.

    החזר JSON בלבד: {"order": [<כל המזהים, בסדר>], "reason": "<משפט אחד בעברית>"}
    TXT;
}

/**
 * סידור בעזרת המודל. התשובה נאכפת מול הרשימה שנשלחה: מזהה שהומצא
 * מושמט, ומטלה שנשכחה מצורפת בסוף. כך תשובה חלקית מקלקלת קצת ולא הכל.
 */
function orderByModel(array $tasks, array $conn, ?callable $transport = null): array {
    $data = extractJson(aiComplete(
        $conn,
        'אתה מסדר מטלות לפי סדר עבודה. אתה עונה JSON בלבד.',
        orderPrompt($tasks),
        800, $transport
    ));
    $ids   = array_map(fn($t) => (int) $t['id'], $tasks);
    $order = [];

    foreach ((array) ($data['order'] ?? []) as $id) {
        $id = (int) $id;
        if (in_array($id, $ids, true) && !in_array($id, $order, true)) $order[] = $id;
    }
    if (!$order) throw new RuntimeException('המודל לא החזיר סדר תקין');

    foreach (heuristicOrder($tasks) as $id) if (!in_array($id, $order, true)) $order[] = $id;

    return ['order' => $order, 'reason' => mb_substr((string) ($data['reason'] ?? ''), 0, 300)];
}

/**
 * מסדר נושא וכותב את התוצאה ל-seq.
 *
 * ‏seq קופץ בעשרות כדי שיישאר מקום להזזה ידנית של מטלה בודדת בלי
 * לכתוב מחדש את כל הנושא.
 */
function reorderTopic(PDO $pdo, ?int $topicId, array $opts = []): array {
    $sql  = "SELECT id, title, body, kind, priority, created_at FROM tasks
              WHERE status IN ('open','answered','blocked','in_progress')";
    $args = [];
    if ($topicId === null) { $sql .= ' AND topic_id IS NULL'; }
    else                   { $sql .= ' AND topic_id = ?'; $args[] = $topicId; }
    $sql .= ' ORDER BY seq, id LIMIT 200';

    $st = $pdo->prepare($sql);
    $st->execute($args);
    $tasks = $st->fetchAll();

    if (count($tasks) < 2) return ['ordered' => count($tasks), 'source' => 'none', 'order' => array_column($tasks, 'id')];

    $source = 'heuristic';
    $reason = 'תשתית לפני לוגיקה, דחוף קודם, ותלות מפורשת נשמרת';
    $order  = heuristicOrder($tasks);

    $conn = $opts['conn'] ?? [];
    if (!empty($conn['key'])) {
        try {
            $r      = orderByModel($tasks, $conn, $opts['transport'] ?? null);
            $order  = $r['order'];
            $reason = $r['reason'] !== '' ? $r['reason'] : 'סודר על ידי המודל';
            $source = 'llm';
        } catch (Throwable $e) {
            $reason = 'סודר לפי כללים — המודל לא היה זמין (' . $e->getMessage() . ')';
        }
    }

    $known = [];
    foreach ($tasks as $t) $known[(int) $t['id']] = true;
    $deps = [];
    foreach ($tasks as $t) $deps[(int) $t['id']] = dependenciesOf($t, $known);
    $order = applyDependencies($order, $deps);

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare('UPDATE tasks SET seq = ? WHERE id = ?');
        foreach ($order as $i => $id) $upd->execute([($i + 1) * 10, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['ordered' => count($order), 'source' => $source, 'reason' => $reason, 'order' => $order];
}
