<?php
/**
 * ‎.mbe — קובץ ההחלפה של חבילת היצירה (creative-suite/docs/mbe-format.md), דרך הליבה (lib/mbe — עותק של
 * creative-suite/mbe-core/src/php, לא עורכים כאן). הורדה: kind "text" — שטף אחד (flows.f1) עם ה-JSON של המסמך כמו
 * שהוא (כולל attrs.page), משטח עמוד ומסגרת בשוליים, layout ו-styles מהגדרות העמוד, תמונות ב-media/<sha256>,
 * וייצוגים: preview.png (העמוד הראשון, מאותו עימוד של ה-PDF), document.pdf, document.docx.
 *
 * מסמך שיובא מ-‎.mbe: הקובץ המקורי נשמר ליד המסמך (mbe_source_path), ובהורדה הבאה מחליפים בו רק את השטף שלנו,
 * את המשאבים שלו ואת הייצוגים — כל השאר (שכבות, אפליקציות אחרות, שדות וחלקים לא מוכרים) נשמר בית-לבית.
 */

require_once __DIR__ . '/mbe/mbe.php';
require_once __DIR__ . '/pdf.php';

const MBE_APP = 'word-processor';
const MBE_APP_VERSION = '1';
const MBE_PREVIEW_PX = 600;

/** הקובץ המקורי של מסמך שיובא מ-‎.mbe, ו-meta שלו (איזה שטף נערך כאן, איזו תמונה הוספנו בייבוא). */
function mbe_source_path(string $doc): string
{
    $dir = wp_media_dir() . '/mbe';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return "$dir/$doc.mbe";
}

function mbe_source_meta(string $doc): array
{
    $f = mbe_source_path($doc) . '.json';
    return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
}

/** ההורדה: bytes של ‎.mbe תקין (Mbe::save בודק את מה שכתב, וזורק אם יש שגיאה). */
function export_mbe(array $row): string
{
    $json = doc_json($row);
    $page = doc_page($row);
    $title = (string)$row['title'];
    $src = mbe_source_path($row['id']);
    $meta = mbe_source_meta($row['id']);
    $f = is_file($src) ? Mbe::open((string)file_get_contents($src)) : null;
    if ($f && !$f->ok()) $f = null;   // לא אמור לקרות: נבדק בייבוא
    [$wmm, $hmm] = page_mm($page);
    $W = round($wmm * PT_PER_MM, 2);
    $H = round($hmm * PT_PER_MM, 2);
    $m = $page['margins'];
    $mm = fn($v) => round((float)$v * PT_PER_MM, 2);
    if (!$f) {
        $f = Mbe::create('text', [
            'units' => 'pt',
            'color' => ['space' => 'sRGB'],
            'surfaces' => [['id' => 'p1', 'type' => 'page', 'size' => [$W, $H], 'master' => null, 'layers' => [
                ['id' => 'fr1', 'type' => 'frame', 'box' => [$mm($m['left']), $mm($m['top']), round($W - $mm($m['left']) - $mm($m['right']), 2),
                    round($H - $mm($m['top']) - $mm($m['bottom']), 2)], 'flow' => 'f1'],
            ]]],
            'flows' => ['f1' => ['engine' => 'prosemirror']],
        ]);
        $flow = 'f1';
        // המעטפת: הגדרות עמוד שאפליקציות אחרות קוראות (ספר עברי — כריכה מימין: inside = ימין)
        $num = $page['numbering'] ?? [];
        $f->document['layout'] = ['facing' => false, 'binding' => 'right',
            'margins' => ['top' => $mm($m['top']), 'bottom' => $mm($m['bottom']), 'inside' => $mm($m['right']), 'outside' => $mm($m['left'])],
            'numbering' => ['start' => (int)($num['start'] ?? 1), 'style' => ($num['format'] ?? '') === 'hebrew' ? 'hebrew' : 'arabic']];
        $f->document['fonts'] = [['family' => (string)($page['font']['family'] ?? 'David')]];
        $f->document['styles'] = $page['styles'] ? $page['styles'] : new stdClass();
    } else {
        $flow = (string)($meta['flow'] ?? 'f1');
    }
    $gaps = [];
    $doc = mbe_flow_doc($json, $page, $f, $row['id'], array_map('strval', (array)($meta['foreign'] ?? [])), $gaps);
    if (!is_array($f->document['flows'] ?? null)) $f->document['flows'] = [];
    $f->document['flows'][$flow] = array_merge(is_array($f->document['flows'][$flow] ?? null) ? $f->document['flows'][$flow] : [], ['engine' => 'prosemirror', 'doc' => $doc]);
    $f->setApp(MBE_APP, 'meta.json', json_encode(['title' => $title, 'flow' => $flow], JSON_UNESCAPED_UNICODE));

    [$pdf, $png] = mbe_pdf_and_preview($json, $page, $title);
    $f->setRendition(Mbe::PREVIEW, $png, ['type' => 'image/png', 'by' => MBE_APP]);
    $f->setRendition('renditions/document.pdf', $pdf, ['type' => 'application/pdf', 'by' => MBE_APP, 'gaps' => $gaps]);
    $f->setRendition('renditions/document.docx', export_docx($json, $page, $title), ['by' => MBE_APP, 'gaps' => $gaps]);
    return Mbe::save($f, ['app' => MBE_APP, 'appVersion' => MBE_APP_VERSION]);
}

/**
 * השטף: ה-JSON של המסמך עם הגדרות העמוד (attrs.page — כמו שהעורך טוען אותו), וכל תמונה — בכל עומק, גם בכותרות
 * ובמקטעים — מהאחסון שלנו ל-media/<sha256>.<ext> בתוך הקובץ (מזהה השרת יוצא). תמונה שהוספנו בייבוא כתצוגה של מה
 * שלא נערך כאן ($foreign) — לא חלק מהשטף. כתובת שאינה שלנו — יוצאת (gap).
 */
function mbe_flow_doc(array $json, array $page, MbeFile $f, string $docId, array $foreign, array &$gaps): array
{
    $json = doc_migrate($json);
    $json['attrs']['page'] = $page;
    $walk = function ($v) use (&$walk, $f, $docId, $foreign, &$gaps) {
        if (!is_array($v)) return $v;
        $out = [];
        foreach ($v as $k => $c) {
            if (is_array($c) && ($c['type'] ?? '') === 'image' && in_array((string)($c['attrs']['media'] ?? ''), $foreign, true)) continue;
            $out[$k] = $walk($c);
        }
        if (array_is_list($out)) $out = array_values($out);
        if (($out['type'] ?? '') === 'image' && is_array($out['attrs'] ?? null)) {
            $id = (string)($out['attrs']['media'] ?? '');
            $mrow = $id !== '' ? media_of_doc($id, $docId) : null;
            $bytes = $mrow && is_file(media_path($mrow['id'], $mrow['type'])) ? file_get_contents(media_path($mrow['id'], $mrow['type'])) : null;
            unset($out['attrs']['media']);
            if ($bytes !== null && $bytes !== false) $out['attrs']['src'] = $f->addMedia($bytes, $mrow['type']);
            else { unset($out['attrs']['src']); $gaps[] = 'image without stored media → removed src'; }
        } elseif (isset($out['src']) || isset($out['embedded'])) {
            // לא אמור להיות במסמך שלנו — כתובת חיצונית תיפסל בקובץ
            unset($out['src'], $out['embedded']);
            $gaps[] = 'external src → removed';
        }
        return $out;
    };
    $doc = $walk($json);
    $gaps = array_values(array_unique($gaps));
    return $doc;
}

/** PDF (כמו export_pdf) ותמונה ממוזערת של העמוד הראשון — מאותו עימוד, בלי לעמד פעמיים. */
function mbe_pdf_and_preview(array $json, array $page, string $title): array
{
    $tv = track_view(seq_flatten(pdf_toc_dests(sections_top($json))), 'all', []);
    $layout = new PdfLayout($page);
    $layout->writer()->rec = [];
    $pdf = $layout->render($tv, $title);
    $w = $layout->writer();
    $p0 = $w->pages[0] ?? ['w' => 595.3, 'h' => 841.9];
    return [$pdf, mbe_preview_png($w->rec ?? [], (float)$p0['w'], (float)$p0['h'], (string)($page['page_color'] ?? ''))];
}

/**
 * העמוד הראשון כ-PNG (GD): מה שנרשם ב-PdfCanvas — טקסט (הגליפים כבר בסדר חזותי, משמאל לימין), מלבנים, קווים
 * ותמונות. תמונה ממוזערת בלבד: בלי סיבוב וחיתוך, ניקוד כמו ש-FreeType מציב. בלי FreeType — פסים אפורים במקום טקסט.
 */
function mbe_preview_png(array $rec, float $W, float $H, string $bg = ''): string
{
    $s = MBE_PREVIEW_PX / max($W, $H);
    $iw = max(1, (int)round($W * $s));
    $ih = max(1, (int)round($H * $s));
    $im = imagecreatetruecolor($iw, $ih);
    imagealphablending($im, true);
    $paper = preg_match('/^#([0-9a-f]{6})$/i', $bg, $mm) ? array_map('hexdec', str_split($mm[1], 2)) : [255, 255, 255];
    imagefilledrectangle($im, 0, 0, $iw, $ih, imagecolorallocate($im, ...$paper));
    $col = fn(array $c) => imagecolorallocate($im, (int)$c[0], (int)$c[1], (int)$c[2]);
    $ttf = function_exists('imagettftext');
    $files = [];
    foreach ($rec as $op) {
        switch ($op[0]) {
            case 'rect':
                [, $x, $y, $w, $h, $c] = $op;
                imagefilledrectangle($im, (int)round($x * $s), (int)round($y * $s), (int)round(($x + $w) * $s), (int)round(($y + $h) * $s), $col($c));
                break;
            case 'frame':
                [, $x, $y, $w, $h, $c] = $op;
                imagerectangle($im, (int)round($x * $s), (int)round($y * $s), (int)round(($x + $w) * $s), (int)round(($y + $h) * $s), $col($c));
                break;
            case 'line':
                [, $x1, $y1, $x2, $y2, $c, $lw] = $op;
                imagesetthickness($im, max(1, (int)round($lw * $s)));
                imageline($im, (int)round($x1 * $s), (int)round($y1 * $s), (int)round($x2 * $s), (int)round($y2 * $s), $col($c));
                imagesetthickness($im, 1);
                break;
            case 'image':
                [, $bytes, $x, $y, $w, $h] = $op;
                $src = @imagecreatefromstring($bytes);
                if ($src) {
                    imagecopyresampled($im, $src, (int)round($x * $s), (int)round($y * $s), 0, 0, max(1, (int)round($w * $s)), max(1, (int)round($h * $s)), imagesx($src), imagesy($src));
                    imagedestroy($src);
                }
                break;
            case 'glyphs':
                [, $font, $size, $x, $base, $hex, $c] = $op;
                $cps = [];
                foreach (str_split($hex, 4) as $g) {
                    $gid = hexdec($g);
                    if (isset($font->used[$gid])) $cps[] = $font->used[$gid];
                }
                if (!$cps) break;
                $text = implode('', array_map(fn($cp) => mb_chr($cp, 'UTF-8'), $cps));
                if ($ttf) {
                    $id = spl_object_id($font);
                    if (!isset($files[$id])) { $files[$id] = tempnam(sys_get_temp_dir(), 'wpfont'); file_put_contents($files[$id], $font->sfnt); }
                    // GD: גודל בנקודות ב-96 לאינץ' — פיקסלים = גודל × 96 / 72
                    @imagettftext($im, $size * $s * 72 / 96, 0, (int)round($x * $s), (int)round($base * $s), $col($c), $files[$id], $text);
                } else {
                    $w = 0;
                    foreach ($cps as $cp) $w += $font->advance($cp, $size);
                    imagefilledrectangle($im, (int)round($x * $s), (int)round(($base - $size * 0.6) * $s), (int)round(($x + $w) * $s), (int)round($base * $s), $col([200, 200, 200]));
                }
                break;
        }
    }
    foreach ($files as $t) @unlink($t);
    ob_start();
    imagepng($im, null, 6);
    imagedestroy($im);
    return (string)ob_get_clean();
}
