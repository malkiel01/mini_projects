<?php
/**
 * המחירונים — קריאה (פרוסה 01), והסימון בתוויות. העריכה — lib/edit.php.
 */

declare(strict_types=1);

/**
 * תווית → HTML בטוח. קודם הכול מוברח, ורק אחר כך שלושת הסימונים:
 * **מודגש** · __קו תחתון__ · ((קטן ורגיל)) — מוצג בסוגריים, כמו במקור. סימן בלי סוגר — נשאר כטקסט.
 */
function gp_label_html(string $label): string
{
    $h = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $h = preg_replace('/\(\((.+?)\)\)/us', '<span class="plain">($1)</span>', $h);
    $h = preg_replace('/\*\*(.+?)\*\*/us', '<b>$1</b>', $h);
    return preg_replace('/__(.+?)__/us', '<u>$1</u>', $h);
}

/** "בתוקף היום" — לפי שעון ישראל. */
function gp_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jerusalem')))->format('Y-m-d');
}

/**
 * הרשימה — לכל מחירון הקבוצה שלו. "בתוקף היום" — לכל קבוצה בנפרד: האחרון שתאריכו ≤ היום.
 * $audience — רק קבוצה אחת (?audience=), ריק — שתיהן.
 */
function gp_editions(string $audience = ''): array
{
    if ($audience !== '' && !isset(GP_AUDIENCES[$audience])) throw new ApiError('קבוצה לא מוכרת');
    $list = db()->query('SELECT id, pid, audience, effective_from, subtitle FROM editions ORDER BY effective_from DESC, id DESC')->fetchAll();
    $today = gp_today();
    $current = [];
    foreach ($list as $e) if ($e['effective_from'] <= $today) $current[$e['audience']] ??= $e['id'];
    $out = [];
    foreach ($list as $e) {
        if ($audience !== '' && $e['audience'] !== $audience) continue;
        $out[] = ['pid' => $e['pid'], 'audience' => $e['audience'], 'effective_from' => $e['effective_from'], 'subtitle' => $e['subtitle'],
            'in_effect' => ($current[$e['audience']] ?? null) === $e['id'], 'future' => $e['effective_from'] > $today];
    }
    return ['editions' => $out, 'audiences' => GP_AUDIENCES];
}

function gp_edition_row(string $pid): array
{
    $st = db()->prepare('SELECT * FROM editions WHERE pid = ?');
    $st->execute([$pid]);
    return $st->fetch() ?: throw new ApiError('המחירון לא נמצא', 404);
}

/** מחירון עם השורות שלו. לכל שורה מחיר אחד — של הקבוצה של המחירון (רשומות של הקבוצה השנייה לא נקראות). */
function gp_edition(string $pid): array
{
    $e = gp_edition_row($pid);
    $st = db()->prepare('SELECT r.id, r.pid, r.kind, r.indent, r.label, p.amount FROM rows r
        LEFT JOIN prices p ON p.row_id = r.id AND p.audience = ? WHERE r.edition_id = ? ORDER BY r.sort, r.id');
    $st->execute([$e['audience'], $e['id']]);
    $rows = [];
    foreach ($st as $r) {
        $priced = in_array($r['kind'], GP_PRICED, true);
        $rows[] = ['pid' => $r['pid'], 'kind' => $r['kind'], 'indent' => (int)$r['indent'], 'label' => $r['label'],
            'html' => gp_label_html($r['label']), 'priced' => $priced,
            'price' => $priced && $r['amount'] !== null ? (int)$r['amount'] : null];
    }
    $layout = gp_layout($rows);
    foreach ($layout['ends'] as $i => $end) $rows[$i]['block'] = $end - $i - 1;   // כותרת: כמה שורות במקטע שלה
    unset($layout['ends']);
    return [
        'pid' => $e['pid'],
        'audience' => $e['audience'],
        'audience_name' => GP_AUDIENCES[$e['audience']],
        'effective_from' => $e['effective_from'],
        'subtitle' => $e['subtitle'],
        'future' => $e['effective_from'] > gp_today(),   // עתידי: נמחק ומשנה תאריך; עריכתו בלי אישור "כבר בתוקף"
        'signatures' => gp_signatures(),   // הסט הגלובלי — לא של המחירון
        'rows' => $rows,
        'layout' => $layout,   // איפה כפתורי ➕ במצב עריכה (04 סעיף 2)
    ];
}

/** רמה לכל כותרת (04 סעיף 1): מספר קטן = גבוהה יותר */
const GP_LEVELS = ['section' => 1, 'band' => 1, 'subsection' => 2, 'heading' => 3];

/**
 * גבולות המקטעים ואזור האגרות (04 סעיף 1), על שורות לפי הסדר (צריך רק kind ו-indent).
 * - fee_start: השורה הראשונה מסוג fee/note; כותרת subsection שמיד לפניה שייכת לאזור האגרות. אין — סוף המחירון.
 * - ends[i]: לכל כותרת — האינדקס שבו המקטע שלה נגמר (לא כולל). עד כותרת שרמתה שווה או גבוהה יותר, או עד אזור
 *   האגרות. heading — רק השורות המוזחות שאחריה (כמו בהדפסה: שורה לא מוזחת שאחריה כבר לא "תחתיה"). כותרת שבתוך
 *   אזור האגרות — המקטע שלה היא עצמה בלבד, כדי שמחיקתה לא תמחק את האגרות.
 * - add_row: לפני אילו שורות ➕ שורה — לפני כותרת רמה 1–2 ולפני אזור האגרות, כשהשורה שמעל היא item או כותרת
 *   שהמקטע שלה ריק (נגמר כאן). לא לפני heading: השורות שמעליה הן עדיין חלק מאותו מקטע.
 * - add_block: בסוף כל מקטע רמה 1; אין כותרת רמה 1 — לפני אזור האגרות.
 * - add_fee: אחרי האגרה האחרונה באזור האגרות; אין — לפני ההערה הראשונה בו (או בסוף).
 */
function gp_layout(array $rows): array
{
    $n = count($rows);
    $fee = $n;
    foreach ($rows as $i => $r) if ($r['kind'] === 'fee' || $r['kind'] === 'note') { $fee = $i; break; }
    if ($fee < $n && $fee > 0 && $rows[$fee - 1]['kind'] === 'subsection') $fee--;
    $ends = [];
    foreach ($rows as $i => $r) {
        if (!isset(GP_LEVELS[$r['kind']])) continue;
        $lv = GP_LEVELS[$r['kind']];
        $j = $i + 1;
        if ($i < $fee) {
            for (; $j < $fee; $j++) {
                $k = $rows[$j];
                if (isset(GP_LEVELS[$k['kind']]) ? GP_LEVELS[$k['kind']] <= $lv : $lv === 3 && !$k['indent']) break;
            }
        }
        $ends[$i] = $j;
    }
    $addRow = [];
    foreach (array_merge(array_keys(array_filter($ends, fn($e, $i) => $i < $fee && GP_LEVELS[$rows[$i]['kind']] < 3, ARRAY_FILTER_USE_BOTH)), [$fee]) as $p) {
        if ($p === 0 || in_array($p, $addRow, true)) continue;
        $prev = $p - 1;
        if ($rows[$prev]['kind'] === 'item' || (isset($ends[$prev]) && $ends[$prev] === $p)) $addRow[] = $p;
    }
    $addBlock = [];
    foreach ($ends as $h => $e) if ($h < $fee && GP_LEVELS[$rows[$h]['kind']] === 1 && !in_array($e, $addBlock, true)) $addBlock[] = $e;
    if (!$addBlock) $addBlock[] = $fee;
    $lastFee = null;
    $firstNote = null;
    for ($i = $fee; $i < $n; $i++) {
        if ($rows[$i]['kind'] === 'fee') $lastFee = $i;
        if ($rows[$i]['kind'] === 'note') $firstNote ??= $i;
    }
    $addFee = $lastFee !== null ? $lastFee + 1 : ($firstNote ?? $n);
    sort($addRow);
    sort($addBlock);
    return ['fee_start' => $fee, 'ends' => $ends, 'add_row' => $addRow, 'add_block' => $addBlock, 'add_fee' => $addFee];
}
