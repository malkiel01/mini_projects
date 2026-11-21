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

/** השורות והמבנה של מחירון, בלי סימון "כללית" — ל-gp_edition ול-gp_fees */
function gp_edition_load(array $e): array
{
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
    return [$rows, $layout];
}

/** המחירון שבתוקף היום של קבוצה — או null */
function gp_current_row(string $audience): ?array
{
    $st = db()->prepare('SELECT * FROM editions WHERE audience = ? AND effective_from <= ? ORDER BY effective_from DESC, id DESC LIMIT 1');
    $st->execute([$audience, gp_today()]);
    return $st->fetch() ?: null;
}

/**
 * מחירון עם השורות שלו. לכל שורה מחיר אחד — של הקבוצה של המחירון (רשומות של הקבוצה השנייה לא נקראות).
 * לכל שורת אגרה/הערה באזור האגרות — shared: האם תודפס ככללית בדף המשותף מול המחירון של הקבוצה השנייה שבתוקף
 * היום (05 סעיף 4; אותה פונקציה כמו ההדפסה — gp_fees_merge).
 */
function gp_edition(string $pid): array
{
    $e = gp_edition_row($pid);
    [$rows, $layout] = gp_edition_load($e);
    $otherAud = $e['audience'] === 'il' ? 'abroad' : 'il';
    $other = gp_current_row($otherAud);
    $otherRows = $other ? gp_edition_load($other)[0] : [];
    $m = $e['audience'] === 'il' ? gp_fees_merge($rows, $otherRows) : gp_fees_merge($otherRows, $rows);
    $mine = $m['shared'][$e['audience']];
    foreach ($rows as $i => $r) {
        if ($i >= $layout['fee_start'] && ($r['kind'] === 'fee' || $r['kind'] === 'note')) $rows[$i]['shared'] = $mine[$r['pid']] ?? false;
    }
    return [
        'pid' => $e['pid'],
        'audience' => $e['audience'],
        'audience_name' => GP_AUDIENCES[$e['audience']],
        'other_name' => GP_AUDIENCES[$otherAud],   // לתג "כללית" במצב עריכה
        'effective_from' => $e['effective_from'],
        'subtitle' => $e['subtitle'],
        'future' => $e['effective_from'] > gp_today(),   // עתידי: נמחק ומשנה תאריך; עריכתו בלי אישור "כבר בתוקף"
        'signatures' => gp_signatures(),   // הסט הגלובלי — לא של המחירון
        'rows' => $rows,
        'layout' => $layout,   // איפה כפתורי ➕ במצב עריכה (04 סעיף 2)
    ];
}

/**
 * מפתח של אגרה/הערה (05 סעיף 2): בלי סימון (** __ (( ))), בלי כוכביות ורווחים בהתחלה, בלי נקודה בסוף,
 * ורווחים כפולים → רווח אחד. שתי תוויות עם אותו מפתח — "אותו טקסט".
 */
function gp_fee_key(string $label): string
{
    $k = str_replace(['**', '__', '((', '))'], '', $label);
    $k = preg_replace('/\s+/u', ' ', $k);
    $k = preg_replace('/^[\s*]+/u', '', $k);
    $k = preg_replace('/[\s.]+$/u', '', $k);
    return $k;
}

/**
 * אזור האגרות המשותף (05 סעיף 2) — מקור האמת היחיד: גם ההדפסה (GET fees) וגם התג במצב עריכה (shared ב-edition).
 * $il, $ab — שורות של מחירון (gp_edition_load); נלקחות רק השורות שמ-fee_start ואילך: שורת מחיר (fee, וגם item שהגיע
 * לשם) עם מחיר → "אגרה"; note → "הערה"; כותרות — לא (מוחלפות בכותרת המשותפת); שורת מחיר בלי מחיר — לא מודפסת.
 * כללית: אותו מפתח ואותו מחיר (הערה — אותו מפתח), התאמה אחת-לאחת. הסדר: כל האגרות ואחריהן כל ההערות (קריטריון 5:
 * "אחריהן ההערות לפי אותו כלל"), ובכל אחת — כלליות (בסדר של ישראל) → ישראל בלבד → חו"ל בלבד.
 * מחזיר rows (להדפסה: label/html עם הציון, price, scope) ו-shared[audience][pid] => bool.
 */
function gp_fees_merge(array $il, array $ab): array
{
    $pick = function (array $rows): array {
        $out = [];
        if (!$rows) return $out;
        $from = gp_layout($rows)['fee_start'];
        foreach (array_slice($rows, $from) as $r) {
            if ($r['kind'] === 'note') $out[] = $r + ['type' => 'note', 'key' => gp_fee_key($r['label'])];
            elseif ($r['priced'] && $r['price'] !== null) $out[] = $r + ['type' => 'fee', 'key' => gp_fee_key($r['label'])];
        }
        return $out;
    };
    $a = $pick($il);
    $b = $pick($ab);
    $shared = ['il' => [], 'abroad' => []];
    foreach ($a as $r) $shared['il'][$r['pid']] = false;
    foreach ($b as $r) $shared['abroad'][$r['pid']] = false;
    $used = [];
    $general = $ilOnly = [];
    foreach ($a as $r) {
        $match = null;
        foreach ($b as $j => $o) {
            if (isset($used[$j]) || $o['type'] !== $r['type'] || $o['key'] !== $r['key']) continue;
            if ($r['type'] === 'fee' && $o['price'] !== $r['price']) continue;
            $match = $j;
            break;
        }
        if ($match === null) { $ilOnly[] = $r; continue; }
        $used[$match] = true;
        $shared['il'][$r['pid']] = $shared['abroad'][$b[$match]['pid']] = true;
        $general[] = $r;
    }
    $abOnly = array_values(array_filter($b, fn($o, $j) => !isset($used[$j]), ARRAY_FILTER_USE_BOTH));
    $out = [];
    foreach (['fee', 'note'] as $type) {
        foreach ([['all', $general], ['il', $ilOnly], ['abroad', $abOnly]] as [$scope, $list]) {
            foreach ($list as $r) {
                if ($r['type'] !== $type) continue;
                $label = $scope === 'all' ? $r['label'] : $r['label'] . ' ((ל' . GP_AUDIENCES[$scope] . '))';
                $out[] = ['kind' => $type, 'scope' => $scope, 'label' => $label, 'html' => gp_label_html($label),
                    'price' => $type === 'fee' ? $r['price'] : null];
            }
        }
    }
    return ['rows' => $out, 'shared' => $shared];
}

/** GET fees?il=&abroad= — אזור האגרות המרוכז של הדף המשותף (05 סעיף 2) */
function gp_fees(string $il, string $abroad): array
{
    $ei = gp_edition_row($il);
    $ea = gp_edition_row($abroad);
    if ($ei['audience'] !== 'il' || $ea['audience'] !== 'abroad') throw new ApiError('צריך מחירון ישראל ומחירון חו"ל');
    return ['title' => 'תשלומי אגרות', 'rows' => gp_fees_merge(gp_edition_load($ei)[0], gp_edition_load($ea)[0])['rows']];
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
