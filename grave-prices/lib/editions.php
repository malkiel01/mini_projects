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
    return [
        'pid' => $e['pid'],
        'audience' => $e['audience'],
        'audience_name' => GP_AUDIENCES[$e['audience']],
        'effective_from' => $e['effective_from'],
        'subtitle' => $e['subtitle'],
        'future' => $e['effective_from'] > gp_today(),   // עתידי: נמחק ומשנה תאריך; עריכתו בלי אישור "כבר בתוקף"
        'signatures' => gp_signatures(),   // הסט הגלובלי — לא של המחירון
        'rows' => $rows,
    ];
}
