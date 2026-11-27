<?php
/**
 * פרוסה 18 — כותב ה-Word (docs/spec/18-export-delivery.md §4.2): קובץ OOXML שנכתב ישירות, ZIP של XML ב-ZipArchive — בלי ספרייה.
 * הקלט הוא ייצוג הביניים (lib/export_doc.php), אותו אחד שממנו נכתב ה-PDF.
 *
 * - כיוון מימין לשמאל בכל פסקה (<w:bidi/>) ובכל טבלה (<w:bidiVisual/>); ריצה שיש בה עברית מסומנת <w:rtl/>.
 * - סגנונות כותרת אמיתיים (heading 1 / 2) — Word בונה מהם את תוכן העניינים; טבלאות אמיתיות עם שורת כותרת חוזרת; תמונות משובצות;
 *   כותרת עליונה ותחתונה; "עמוד X מתוך Y" בשדות. הקובץ עריך.
 * - סימוני המצב נכתבים לתוך הקובץ: "טיוטה — לא מאושר" / "גרסה שהוחלפה" בכותרת כל עמוד, סימן מים אלכסוני לטיוטה, "אושר בידי …" בעמוד
 *   הראשון, וטביעת האצבע בעמוד האחרון. **אין "חתום".**
 * - **כל טקסט נכנס דרך apr_docx_x()** (קידוד XML מלא), ורק כתוכן של <w:t> או כערך של מאפיין. שום טקסט אינו נכתב כ-XML.
 * - המטא-דאטה (docProps): הכותרת ושם המשרד בלבד — לא שם משתמש, ולא נתיב.
 * - הגופן (DejaVu Sans, lib/fonts/) מוטמע בקובץ, בערפול שהתקן דורש (odttf).
 */

declare(strict_types=1);

const APR_DOCX_FONT = 'DejaVu Sans';
const APR_DOCX_NS = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
const APR_DOCX_PAGE_W = 9638;                      // רוחב הטקסט ב-twips: A4 (11906) פחות שוליים של 2 ס"מ מכל צד
const APR_DOCX_IMG_W = 5400000;                    // רוחב תמונה מרבי, ב-EMU (15 ס"מ)

/** קידוד XML מלא: & < > " ' — וכל מה שהגיע ממשתמש עובר כאן לפני שהוא נכנס לקובץ. */
function apr_docx_x(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** ריצת טקסט. $o: bold, italic, color (hex), size (חצאי נקודה). ריצה שיש בה אות עברית או ערבית — מימין לשמאל. */
function apr_docx_run(string $text, array $o = []): string
{
    if ($text === '') return '';
    $rpr = (!empty($o['bold']) ? '<w:b/><w:bCs/>' : '') . (!empty($o['italic']) ? '<w:i/><w:iCs/>' : '')
        . (isset($o['color']) ? '<w:color w:val="' . apr_docx_x((string)$o['color']) . '"/>' : '')
        . (isset($o['size']) ? '<w:sz w:val="' . (int)$o['size'] . '"/><w:szCs w:val="' . (int)$o['size'] . '"/>' : '')
        . (preg_match('/[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text) === 1 ? '<w:rtl/>' : '');
    return '<w:r>' . ($rpr !== '' ? '<w:rPr>' . $rpr . '</w:rPr>' : '') . '<w:t xml:space="preserve">' . apr_docx_x($text) . '</w:t></w:r>';
}

/** פסקה מימין לשמאל. $runs — XML של ריצות (כבר מקודד); $o: style, center, keep (נשארת עם הפסקה הבאה), list, before / after (ריווח, twips). */
function apr_docx_p(string $runs, array $o = []): string
{
    $ppr = (isset($o['style']) ? '<w:pStyle w:val="' . apr_docx_x((string)$o['style']) . '"/>' : '') . (!empty($o['keep']) ? '<w:keepNext/>' : '')
        . (!empty($o['list']) ? '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>' : '') . '<w:bidi/>'
        . (isset($o['before']) || isset($o['after']) ? '<w:spacing w:before="' . (int)($o['before'] ?? 0) . '" w:after="' . (int)($o['after'] ?? 120) . '"/>' : '')
        . (!empty($o['center']) ? '<w:jc w:val="center"/>' : '');
    return '<w:p><w:pPr>' . $ppr . '</w:pPr>' . $runs . '</w:p>';
}

/** שדה של Word (PAGE, NUMPAGES) — הערך מחושב ב-Word; "1" הוא רק מה שמוצג עד אז. $o — כמו ב-apr_docx_run. */
function apr_docx_field(string $instr, array $o = []): string
{
    return '<w:fldSimple w:instr=" ' . $instr . ' ">' . apr_docx_run('1', $o) . '</w:fldSimple>';
}

/** טבלה מימין לשמאל. $head — שורת כותרת (חוזרת בכל עמוד) או null. */
function apr_docx_table(?array $head, array $rows): string
{
    $cols = max(1, $head !== null ? count($head) : 0, ...array_map('count', $rows ?: [[]]));
    // רוחב העמודות יחסי לאורך התוכן (בין 5 ל-40 תווים), כדי שעמודה של כתובות לא תישבר לשתי שורות ועמודה של מספרים לא תתפוס חצי עמוד
    $len = array_fill(0, $cols, 5);
    foreach (array_merge($head !== null ? [$head] : [], $rows) as $r) foreach (array_values($r) as $i => $x) $len[$i] = max($len[$i], min(40, mb_strlen((string)$x)));
    $widths = array_map(fn($l) => (int)floor(APR_DOCX_PAGE_W * $l / array_sum($len)), $len);
    $small = $cols > 7 ? ['size' => 16] : ($cols > 4 ? ['size' => 18] : ['size' => 20]);      // טבלה רחבה — אותיות קטנות יותר, כדי שתיכנס לרוחב העמוד
    $border = fn(string $side) => '<w:' . $side . ' w:val="single" w:sz="4" w:space="0" w:color="8A8F98"/>';
    $cell = fn(string $text, bool $h, int $w) => '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/>' . ($h ? '<w:shd w:val="clear" w:color="auto" w:fill="EEF1F6"/>' : '') . '</w:tcPr>'
        . apr_docx_p(apr_docx_run($text, $small + ($h ? ['bold' => true] : [])), ['before' => 40, 'after' => 40]) . '</w:tc>';
    $out = '<w:tbl><w:tblPr><w:bidiVisual/><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>' . $border('top') . $border('left') . $border('bottom') . $border('right') . $border('insideH') . $border('insideV')
        . '</w:tblBorders><w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>' . implode('', array_map(fn($w) => '<w:gridCol w:w="' . $w . '"/>', $widths)) . '</w:tblGrid>';
    $tr = fn(array $r, bool $h) => '<w:tr><w:trPr><w:cantSplit/>' . ($h ? '<w:tblHeader/>' : '') . '</w:trPr>' . implode('', array_map(fn($x, $w) => $cell((string)$x, $h, $w), array_pad(array_values($r), $cols, ''), $widths)) . '</w:tr>';
    if ($head !== null) $out .= $tr($head, true);
    foreach ($rows as $r) $out .= $tr($r, false);
    // אחרי טבלה חייבת לבוא פסקה (גם כדי ששתי טבלות רצופות לא יתמזגו)
    return $out . '</w:tbl>' . apr_docx_p('', ['before' => 0, 'after' => 60]);
}

/** תמונה משובצת. $rid — מזהה הקשר לקובץ שב-word/media; $n — מספר סידורי ייחודי במסמך. */
function apr_docx_image(string $rid, int $n, int $wpx, int $hpx, int $maxEmu = APR_DOCX_IMG_W): string
{
    $cx = min($maxEmu, $wpx * 9525);
    $cy = (int)round($cx * $hpx / max(1, $wpx));
    return '<w:r><w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
        . '<wp:docPr id="' . $n . '" name="image' . $n . '"/><wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'
        . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="' . $n . '" name="image' . $n . '"/><pic:cNvPicPr/></pic:nvPicPr>'
        . '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
}

/** סימן המים של טיוטה: צורת טקסט אלכסונית בכותרת העליונה — כך Word מציג אותה מאחורי הטקסט, בכל עמוד. */
function apr_docx_watermark(string $text): string
{
    return '<w:r><w:rPr><w:noProof/></w:rPr><w:pict><v:shapetype id="_x0000_t136" coordsize="21600,21600" o:spt="136" adj="10800" path="m@7,l@8,m@5,21600l@6,21600e"><v:formulas>'
        . '<v:f eqn="sum #0 0 10800"/><v:f eqn="prod #0 2 1"/><v:f eqn="sum 21600 0 @1"/><v:f eqn="sum 0 0 @2"/><v:f eqn="sum 21600 0 @3"/><v:f eqn="if @0 @3 0"/><v:f eqn="if @0 21600 @1"/><v:f eqn="if @0 0 @2"/>'
        . '<v:f eqn="if @0 @4 21600"/><v:f eqn="mid @5 @6"/><v:f eqn="mid @8 @5"/><v:f eqn="mid @7 @8"/><v:f eqn="mid @6 @7"/><v:f eqn="sum @6 0 @5"/></v:formulas>'
        . '<v:path textpathok="t" o:connecttype="custom" o:connectlocs="@9,0;@10,10800;@11,21600;@12,10800" o:connectangles="270,180,90,0"/><v:textpath on="t" fitshape="t"/>'
        . '<v:handles><v:h position="#0,bottomRight" xrange="6629,14971"/></v:handles><o:lock v:ext="edit" text="t" shapetype="t"/></v:shapetype>'
        . '<v:shape id="PowerPlusWaterMarkObject1" o:spid="_x0000_s2049" type="#_x0000_t136" style="position:absolute;margin-left:0;margin-top:0;width:440pt;height:90pt;rotation:315;z-index:-251658752;'
        . 'mso-position-horizontal:center;mso-position-horizontal-relative:margin;mso-position-vertical:center;mso-position-vertical-relative:margin" o:allowincell="f" fillcolor="#c8c8c8" stroked="f">'
        . '<v:fill opacity=".45"/><v:textpath style="font-family:&quot;' . APR_DOCX_FONT . '&quot;;font-size:1pt" string="' . apr_docx_x($text) . '"/><w10:wrap anchorx="margin" anchory="margin"/></v:shape></w:pict></w:r>';
}

/** גופן מוטמע, מעורפל לפי התקן (ECMA-376, 17.8.1): 32 הבתים הראשונים ב-XOR עם ה-GUID, בסדר בתים הפוך. */
function apr_docx_font_obfuscate(string $ttf, string $guid): string
{
    $key = strrev((string)hex2bin(str_replace(['{', '}', '-'], '', $guid)));
    for ($i = 0; $i < 32; $i++) $ttf[$i] = $ttf[$i] ^ $key[$i % 16];
    return $ttf;
}

/**
 * כותב את המסמך לקובץ $path. זורק RuntimeException כשאי אפשר לכתוב (אין ZipArchive, הדיסק מלא). $deadline — microtime שאחריו עוצרים
 * (מגבלת הזמן של ההפקה).
 */
function apr_export_docx(array $doc, string $path, ?float $deadline = null): void
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('docx: zip');
    $m = $doc['meta'];
    $x = 'apr_docx_x';
    $rels = [];                                    // קשרי המסמך: [מזהה, סוג, יעד]
    $media = [];                                   // שם בקובץ => תוכן
    $rid = function (string $type, string $target) use (&$rels): string {
        $rels[] = ['rId' . (count($rels) + 1), $type, $target];
        return 'rId' . count($rels);
    };
    $R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
    $rid($R . 'styles', 'styles.xml');
    $rid($R . 'settings', 'settings.xml');
    $rid($R . 'numbering', 'numbering.xml');
    $rid($R . 'fontTable', 'fontTable.xml');
    $hdr = $rid($R . 'header', 'header1.xml');
    $ftr = $rid($R . 'footer', 'footer1.xml');

    // ── גוף המסמך ──
    $body = '';
    $imgNo = 0;
    $bookmark = 0;
    foreach ($doc['blocks'] as $b) {
        if ($deadline !== null && microtime(true) > $deadline) throw new RuntimeException('export: timeout');
        switch ($b['t']) {
            case 'title':
                $body .= apr_docx_p(apr_docx_run($b['text']), ['style' => 'Title', 'center' => true]) . apr_docx_p(apr_docx_run($b['sub'], ['size' => 26]), ['center' => true, 'after' => 200]);
                break;
            case 'stamp':
                $body .= apr_docx_p(apr_docx_run($b['text'], ['bold' => true, 'size' => 24]), ['center' => true, 'after' => 160]);
                break;
            case 'details':
                $body .= apr_docx_p(apr_docx_run($b['text'], ['size' => 20, 'color' => '4A5160']), ['center' => true, 'after' => 240]);
                break;
            case 'toc':
                // שדה תוכן עניינים אמיתי: Word בונה אותו מהכותרות ב"עדכון שדה". עד אז מוצגת הרשימה שכאן (שמות הפרקים)
                $body .= apr_docx_p(apr_docx_run('תוכן העניינים', ['bold' => true, 'size' => 26]), ['before' => 240, 'after' => 120, 'keep' => true]);
                $first = true;
                foreach ($b['items'] as $i => $item) {
                    $open = $first ? '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> TOC \o "1-2" \h \z \u </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r>' : '';
                    $close = $i === count($b['items']) - 1 ? '<w:r><w:fldChar w:fldCharType="end"/></w:r>' : '';
                    $body .= apr_docx_p($open . apr_docx_run($item) . $close, ['style' => 'TOC1', 'after' => 60]);
                    $first = false;
                }
                break;
            case 'h':
                $bookmark++;
                $body .= apr_docx_p('<w:bookmarkStart w:id="' . $bookmark . '" w:name="_Toc' . $bookmark . '"/>' . apr_docx_run($b['text']) . '<w:bookmarkEnd w:id="' . $bookmark . '"/>', ['style' => $b['level'] === 1 ? 'Heading1' : 'Heading2', 'keep' => true]);
                break;
            case 'p':
                $body .= apr_docx_p((isset($b['mark']) ? apr_docx_run('[' . $b['mark'] . '] ', ['italic' => true, 'color' => '9A5B00', 'size' => 18]) : '') . apr_docx_run($b['text']));
                break;
            case 'kv':
                $body .= apr_docx_p(apr_docx_run($b['label'] . ': ', ['bold' => true]) . apr_docx_run($b['value']) . ($b['note'] !== null ? apr_docx_run(' (' . $b['note'] . ')', ['italic' => true, 'color' => '9A5B00']) : ''), ['after' => 60]);
                break;
            case 'missing':
                $body .= apr_docx_p(apr_docx_run($b['text'], ['italic' => true, 'color' => '9A5B00']));
                break;
            case 'list':
                foreach ($b['items'] as $item) $body .= apr_docx_p(apr_docx_run($item), ['style' => 'ListParagraph', 'list' => true, 'after' => 60]);
                break;
            case 'table':
                if ($b['caption'] !== null && $b['caption'] !== '') $body .= apr_docx_p(apr_docx_run($b['caption'], ['bold' => true, 'size' => 20]), ['keep' => true, 'before' => 120, 'after' => 60]);
                $body .= apr_docx_table($b['head'], $b['rows']);
                break;
            case 'image':
                $img = $doc['images'][$b['id']] ?? null;
                if ($img === null) break;
                $imgNo++;
                $media['image' . $imgNo . '.jpeg'] = $img['data'];
                $body .= apr_docx_p(apr_docx_image($rid($R . 'image', 'media/image' . $imgNo . '.jpeg'), $imgNo, $img['w'], $img['h']), ['center' => true, 'keep' => $b['caption'] !== '', 'after' => 60]);
                if ($b['caption'] !== '') $body .= apr_docx_p(apr_docx_run($b['caption'], ['size' => 18, 'color' => '4A5160']), ['center' => true, 'after' => 200]);
                break;
            case 'image_missing':
                $body .= apr_docx_p(apr_docx_run('התמונה לא זמינה' . ($b['caption'] !== '' ? ' — ' . $b['caption'] : ''), ['italic' => true, 'color' => '9A5B00']), ['center' => true]);
                break;
            case 'fingerprint':
                $body .= apr_docx_p(apr_docx_run($b['text'], ['size' => 16, 'color' => '4A5160']), ['center' => true, 'before' => 480]);
                break;
        }
    }
    $sect = '<w:sectPr><w:headerReference w:type="default" r:id="' . $hdr . '"/><w:footerReference w:type="default" r:id="' . $ftr . '"/>'
        . '<w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1701" w:right="1134" w:bottom="1418" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/><w:bidi/></w:sectPr>';
    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . APR_DOCX_NS . '><w:body>' . $body . $sect . '</w:body></w:document>';

    // ── כותרת עליונה: כותרת המשרד (ולוגו), סימון המצב בכל עמוד, וסימן המים של טיוטה ──
    $hrels = [];
    $head = '';
    if ($m['logo'] !== null) {
        $media['logo.png'] = $m['logo']['data'];
        $hrels[] = ['rId1', $R . 'image', 'media/logo.png'];
        $head .= apr_docx_p(apr_docx_image('rId1', 9000, $m['logo']['w'], $m['logo']['h'], 1260000), ['center' => true, 'after' => 40]);
    }
    if ($m['header_text'] !== '') $head .= apr_docx_p(apr_docx_run($m['header_text'], ['size' => 18, 'color' => '4A5160']), ['style' => 'Header', 'center' => true, 'after' => 40]);
    if ($m['banner'] !== null) $head .= apr_docx_p(apr_docx_run($m['banner'], ['bold' => true, 'size' => 20, 'color' => 'B42318']) . ($m['watermark'] !== null ? apr_docx_watermark($m['watermark']) : ''), ['style' => 'Header', 'center' => true, 'after' => 0]);
    if ($head === '') $head = apr_docx_p('', ['style' => 'Header']);
    $header = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr ' . APR_DOCX_NS . ' xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w10="urn:schemas-microsoft-com:office:word">' . $head . '</w:hdr>';

    // ── כותרת תחתונה, תמיד: מספר התיק · גרסה · תאריך ההפקה · מצב האישור · עמוד X מתוך Y ──
    $fo = ['size' => 16, 'color' => '4A5160'];
    $foot = apr_docx_p(apr_docx_run($m['footer'] . ' · עמוד ', $fo) . apr_docx_field('PAGE', $fo) . apr_docx_run(' מתוך ', $fo) . apr_docx_field('NUMPAGES', $fo), ['style' => 'Footer', 'center' => true, 'after' => 0]);
    if ($m['footer_text'] !== '') $foot .= apr_docx_p(apr_docx_run($m['footer_text'], $fo), ['style' => 'Footer', 'center' => true, 'after' => 0]);
    $footer = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr ' . APR_DOCX_NS . '>' . $foot . '</w:ftr>';

    // ── סגנונות: ברירת מחדל מימין לשמאל, והכותרות המובנות של Word ──
    $f = '<w:rFonts w:ascii="' . APR_DOCX_FONT . '" w:hAnsi="' . APR_DOCX_FONT . '" w:cs="' . APR_DOCX_FONT . '" w:eastAsia="' . APR_DOCX_FONT . '"/>';
    $style = fn(string $id, string $name, string $ppr, string $rpr, string $extra = '') => '<w:style w:type="paragraph" w:styleId="' . $id . '"><w:name w:val="' . $name . '"/><w:basedOn w:val="Normal"/>' . $extra
        . '<w:pPr>' . $ppr . '</w:pPr><w:rPr>' . $rpr . '</w:rPr></w:style>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles ' . APR_DOCX_NS . '><w:docDefaults><w:rPrDefault><w:rPr>' . $f
        . '<w:sz w:val="22"/><w:szCs w:val="22"/><w:lang w:val="en-US" w:eastAsia="en-US" w:bidi="he-IL"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="288" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/><w:pPr><w:bidi/></w:pPr></w:style>'
        . $style('Title', 'Title', '<w:bidi/><w:spacing w:before="240" w:after="120"/><w:jc w:val="center"/>', '<w:b/><w:bCs/><w:sz w:val="40"/><w:szCs w:val="40"/>', '<w:qFormat/>')
        . $style('Heading1', 'heading 1', '<w:keepNext/><w:bidi/><w:spacing w:before="360" w:after="120"/><w:outlineLvl w:val="0"/>', '<w:b/><w:bCs/><w:color w:val="1F3A6E"/><w:sz w:val="30"/><w:szCs w:val="30"/>', '<w:next w:val="Normal"/><w:qFormat/>')
        . $style('Heading2', 'heading 2', '<w:keepNext/><w:bidi/><w:spacing w:before="240" w:after="80"/><w:outlineLvl w:val="1"/>', '<w:b/><w:bCs/><w:sz w:val="25"/><w:szCs w:val="25"/>', '<w:next w:val="Normal"/><w:qFormat/>')
        . $style('TOC1', 'toc 1', '<w:bidi/><w:spacing w:after="60"/>', '')
        . $style('ListParagraph', 'List Paragraph', '<w:bidi/><w:ind w:left="567" w:hanging="283"/>', '', '<w:qFormat/>')
        . $style('Header', 'header', '<w:bidi/><w:spacing w:after="0"/>', '')
        . $style('Footer', 'footer', '<w:bidi/><w:spacing w:after="0"/>', '')
        . '</w:styles>';
    $numbering = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering ' . APR_DOCX_NS . '><w:abstractNum w:abstractNumId="0"><w:multiLevelType w:val="hybridMultilevel"/><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/>'
        . '<w:lvlText w:val="•"/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="567" w:hanging="283"/></w:pPr></w:lvl></w:abstractNum><w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num></w:numbering>';
    $settings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings ' . APR_DOCX_NS . '><w:embedTrueTypeFonts/><w:defaultTabStop w:val="720"/><w:characterSpacingControl w:val="doNotCompress"/>'
        . '<w:themeFontLang w:val="en-US" w:bidi="he-IL"/></w:settings>';

    // ── הגופן המוטמע ──
    $fonts = [];
    $frels = [];
    $embed = '';
    foreach (['Regular' => 'DejaVuSans.ttf', 'Bold' => 'DejaVuSans-Bold.ttf'] as $kind => $file) {
        $ttf = @file_get_contents(__DIR__ . '/fonts/' . $file);
        if (!is_string($ttf) || strlen($ttf) < 1000) continue;                  // בלי קובץ הגופן המסמך עדיין תקין — Word בוחר גופן אחר
        $n = count($fonts) + 1;
        // GUID קבוע לכל גופן: נגזר מתוכן הגופן, לא אקראי — אותו קובץ גופן נותן אותו מפתח, ואין בו שום דבר על המסמך
        $h = strtoupper(substr(hash('sha256', $ttf), 0, 32));
        $guid = '{' . substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12) . '}';
        $fonts['font' . $n . '.odttf'] = apr_docx_font_obfuscate($ttf, $guid);
        $frels[] = ['rId' . $n, $R . 'font', 'fonts/font' . $n . '.odttf'];
        $embed .= '<w:embed' . $kind . ' r:id="rId' . $n . '" w:fontKey="' . $guid . '"/>';
    }
    $fontTable = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:fonts ' . APR_DOCX_NS . '><w:font w:name="' . APR_DOCX_FONT . '"><w:panose1 w:val="020B0603030804020204"/><w:charset w:val="00"/>'
        . '<w:family w:val="swiss"/><w:pitch w:val="variable"/>' . $embed . '</w:font></w:fonts>';

    $relsXml = fn(array $list) => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . implode('', array_map(fn($r) => '<Relationship Id="' . $r[0] . '" Type="' . $r[1] . '" Target="' . $x($r[2]) . '"/>', $list)) . '</Relationships>';
    // ── המטא-דאטה: הכותרת (מספר התיק והגרסה) ושם המשרד. שום שם של משתמש ושום נתיב ──
    $now = gmdate('Y-m-d\TH:i:s\Z', apr_time());
    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" '
        . 'xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . $x($m['title']) . '</dc:title><dc:creator>' . $x($m['office']) . '</dc:creator>'
        . '<cp:lastModifiedBy>' . $x($m['office']) . '</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified></cp:coreProperties>';
    $app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>' . $x($m['app']) . '</Application>'
        . '<Company>' . $x($m['office']) . '</Company></Properties>';
    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/><Default Extension="jpeg" ContentType="image/jpeg"/><Default Extension="png" ContentType="image/png"/>'
        . '<Default Extension="odttf" ContentType="application/vnd.openxmlformats-officedocument.obfuscatedFont"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
        . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
        . '<Override PartName="/word/fontTable.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.fontTable+xml"/>'
        . '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
        . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>';
    $root = [['rId1', $R . 'officeDocument', 'word/document.xml'], ['rId2', 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties', 'docProps/core.xml'],
        ['rId3', $R . 'extended-properties', 'docProps/app.xml']];

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('docx: open');
    $parts = ['[Content_Types].xml' => $types, '_rels/.rels' => $relsXml($root), 'docProps/core.xml' => $core, 'docProps/app.xml' => $app, 'word/document.xml' => $document,
        'word/_rels/document.xml.rels' => $relsXml($rels), 'word/styles.xml' => $styles, 'word/settings.xml' => $settings, 'word/numbering.xml' => $numbering, 'word/fontTable.xml' => $fontTable,
        'word/_rels/fontTable.xml.rels' => $relsXml($frels), 'word/header1.xml' => $header, 'word/footer1.xml' => $footer];
    if ($hrels) $parts['word/_rels/header1.xml.rels'] = $relsXml($hrels);
    foreach ($media as $name => $data) $parts['word/media/' . $name] = $data;
    foreach ($fonts as $name => $data) $parts['word/fonts/' . $name] = $data;
    foreach ($parts as $name => $data) {
        if (!$zip->addFromString($name, $data)) { $zip->close(); throw new RuntimeException('docx: write'); }
    }
    if (!$zip->close()) throw new RuntimeException('docx: close');
}
