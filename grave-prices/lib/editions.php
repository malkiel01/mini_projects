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

function gp_editions(): array
{
    $list = db()->query('SELECT id, pid, effective_from FROM editions ORDER BY effective_from DESC')->fetchAll();
    $today = gp_today();
    $current = null;
    foreach ($list as $e) if ($e['effective_from'] <= $today) { $current = $e['id']; break; }
    return ['editions' => array_map(fn($e) => [
        'pid' => $e['pid'], 'effective_from' => $e['effective_from'], 'in_effect' => $e['id'] === $current,
        'future' => $e['effective_from'] > $today,
    ], $list)];
}

function gp_edition_row(string $pid): array
{
    $st = db()->prepare('SELECT * FROM editions WHERE pid = ?');
    $st->execute([$pid]);
    return $st->fetch() ?: throw new ApiError('המחירון לא נמצא', 404);
}

function gp_edition(string $pid): array
{
    $e = gp_edition_row($pid);
    $st = db()->prepare('SELECT r.id, r.pid, r.kind, r.indent, r.label, p.audience, p.amount FROM rows r
        LEFT JOIN prices p ON p.row_id = r.id WHERE r.edition_id = ? ORDER BY r.sort, r.id');
    $st->execute([$e['id']]);
    $rows = [];
    foreach ($st as $r) {
        $rows[$r['id']] ??= ['pid' => $r['pid'], 'kind' => $r['kind'], 'indent' => (int)$r['indent'], 'label' => $r['label'],
            'html' => gp_label_html($r['label']),
            'prices' => in_array($r['kind'], GP_PRICED, true) ? array_fill_keys(array_keys(GP_AUDIENCES), null) : null];
        if ($r['audience'] !== null && $rows[$r['id']]['prices'] !== null && isset(GP_AUDIENCES[$r['audience']])) {
            $rows[$r['id']]['prices'][$r['audience']] = $r['amount'] === null ? null : (int)$r['amount'];
        }
    }
    return [
        'pid' => $e['pid'],
        'effective_from' => $e['effective_from'],
        'future' => $e['effective_from'] > gp_today(),   // עתידי: נמחק ומשנה תאריך; עריכתו בלי אישור "כבר בתוקף"
        'signatures' => json_decode($e['signatures'], true) ?: [],
        'audiences' => GP_AUDIENCES,
        'rows' => array_values($rows),
    ];
}
