<?php
/**
 * PDF מהשרת — מנוע משלנו (lib/pdf/), בלי ספרייה חיצונית ובלי דפדפן: גופנים מוטמעים, עברית מימין לשמאל,
 * עמודים כמו בעורך, כותרת עליונה ותחתונה ומספרי עמודים, רשימות, טבלאות ותמונות.
 */

require_once __DIR__ . '/pdf/layout.php';

function export_pdf(array $json, array $page, string $title = ''): string
{
    return (new PdfLayout($page))->render(track_visual(seq_flatten($json)), $title);
}
