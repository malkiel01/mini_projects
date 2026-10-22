<?php
/**
 * PDF מהשרת — מנוע משלנו (lib/pdf/), בלי ספרייה חיצונית ובלי דפדפן: גופנים מוטמעים, עברית מימין לשמאל,
 * עמודים כמו בעורך, כותרת עליונה ותחתונה ומספרי עמודים, רשימות, טבלאות ותמונות.
 */

require_once __DIR__ . '/pdf/layout.php';

function export_pdf(array $json, array $page, string $title = ''): string
{
    return (new PdfLayout($page))->render(seq_flatten(pdf_toc_dests(sections_top($json))), $title);
}

/**
 * יעדים לשורות תוכן העניינים (כמו הלחיצה על שורה בעורך — toc.js): כל כותרת — attrs._dest "_h<i>" לפי הסדר במסמך
 * (כל הרמות, כמו headings()), וכל פסקת כיתוב — "_c<תווית>_<i>" לפי הסדר בין הכיתובים עם אותה תווית. לפני seq_flatten.
 */
function pdf_toc_dests(array $json): array
{
    $h = 0;
    $cap = [];
    $walk = function (array $nodes) use (&$walk, &$h, &$cap) {
        foreach ($nodes as $k => $n) {
            if (!is_array($n)) continue;
            $t = $n['type'] ?? '';
            if ($t === 'heading') $nodes[$k]['attrs']['_dest'] = '_h' . $h++;
            if ($t === 'paragraph' || $t === 'heading') {
                foreach ($n['content'] ?? [] as $c) {
                    if (($c['type'] ?? '') !== 'seq') continue;
                    $l = seq_label($c['attrs']['label'] ?? '');
                    $nodes[$k]['attrs']['_cdest'] = '_c' . $l . '_' . (($cap[$l] = ($cap[$l] ?? -1) + 1));
                    break;
                }
                continue;
            }
            if (is_array($n['content'] ?? null)) $nodes[$k]['content'] = $walk($n['content']);
        }
        return $nodes;
    };
    if (is_array($json['content'] ?? null)) $json['content'] = $walk($json['content']);
    return $json;
}
