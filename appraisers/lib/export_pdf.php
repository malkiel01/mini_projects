<?php
/**
 * פרוסה 18 — כותב ה-PDF (docs/spec/18-export-delivery.md §4.3), ב-mPDF שמצורפת ב-vendor/ (ההכרעה: team/decisions.md, ש-38).
 * הקלט הוא ייצוג הביניים (lib/export_doc.php), אותו אחד שממנו נכתב ה-Word. **זה הקובץ היחיד באפליקציה שטוען את vendor/** — ורק כשמפיקים.
 *
 * מה שנכנס ל-mPDF הוא HTML שנבנה כאן, ורק כאן:
 * - **כל טקסט עובר apr_pdf_h()** (htmlspecialchars) לפני שהוא נכנס ל-HTML: תגית, <img src="file://…">, כתובת חיצונית או ישות בתוך טקסט
 *   של משתמש מודפסים כטקסט. ה-CSS קבוע; שום ערך של משתמש אינו נכנס לסגנון או למאפיין.
 * - **mPDF אינה מביאה דבר:** whitelistStreamWrappers ריק (לא http, לא https, לא file), בלי ייבוא PDF ובלי קובצי הערות. התמונות נמסרות לה
 *   מהזיכרון (imageVars, "var:") — רק מה ש-apr_export_doc קרא מקובצי התיק.
 * - **מחרוזות הקסם של mPDF מנוטרלות:** במקור היא מחליפה בכל עמוד את {PAGENO}, {nb}, {nbpg} ו-{DATE …} — גם כשהן טקסט של משתמש. המחלקה
 *   AprMpdf מחליפה רק שלושה אסימונים אקראיים, שנוצרים לכל הפקה. טקסט כמו "{PAGENO}" בדוח נשאר כמו שהוא.
 * - tempDir (מטמון הגופנים) — data/mpdf/. שום כתיבה ל-vendor/.
 */

declare(strict_types=1);

const APR_PDF_FONT = 'dejavusans';

/** טוען את mPDF ומגדיר את AprMpdf — רק בקריאה הראשונה. בקשות שאינן מפיקות PDF אינן טוענות את הספרייה כלל. */
function apr_pdf_load(): void
{
    if (class_exists('AprMpdf', false)) return;
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    if (!class_exists('Mpdf\\Mpdf')) throw new RuntimeException('pdf: library');

    final class AprMpdf extends \Mpdf\Mpdf
    {
        /** האסימון של "מספר העמוד" בהפקה הזו — אקראי, במקום {PAGENO} הקבוע. */
        public string $aprPageToken = '';

        /** רק שלושת האסימונים של ההפקה מוחלפים — לא {PAGENO} ולא {DATE …}: טקסט של משתמש אינו נוגע במספור העמודים, ולהפך. */
        protected function aliasReplace($html, $PAGENO, $NbPgGp, $NbPg)
        {
            foreach ([[$this->aprPageToken, $PAGENO], [$this->aliasNbPgGp, $NbPgGp], [$this->aliasNbPg, $NbPg]] as [$from, $to]) {
                if (!is_string($from) || $from === '') continue;
                $html = str_replace($from, (string)$to, $html);
                $html = str_replace(mb_convert_encoding($from, 'UTF-16BE', 'UTF-8'), mb_convert_encoding((string)$to, 'UTF-16BE', 'UTF-8'), $html);
            }
            return $html;
        }
    }
}

function apr_pdf_engine(): string
{
    apr_pdf_load();
    return 'mPDF ' . \Mpdf\Mpdf::VERSION;
}

/** התיקייה שבה mPDF שומרת את מטמון הגופנים וקבצים זמניים — תחת data/, שמוחרגת מהפריסה וחסומה לדפדפן. */
function apr_pdf_tmp_dir(): string
{
    $d = apr_data_dir() . '/mpdf';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}

/** טקסט ← HTML: & < > " ' מקודדים. אין דרך אחרת שבה טקסט נכנס למסמך. */
function apr_pdf_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

const APR_PDF_CSS = <<<'CSS'
body { font-family: dejavusans; font-size: 11pt; line-height: 1.45; color: #111; }
h1 { font-size: 20pt; text-align: center; margin: 0 0 1.5mm 0; }
h2 { font-size: 15pt; color: #1f3a6e; margin: 7mm 0 2mm 0; page-break-after: avoid; }
h3 { font-size: 12.5pt; margin: 5mm 0 1.5mm 0; page-break-after: avoid; }
p { margin: 0 0 2.2mm 0; }
.sub { text-align: center; font-size: 13pt; margin-bottom: 4mm; }
.stamp { text-align: center; font-weight: bold; font-size: 12pt; margin: 1.5mm 0; }
.details { text-align: center; font-size: 10pt; color: #4a5160; margin: 2mm 0 6mm 0; }
.toc-h { font-weight: bold; font-size: 13pt; margin: 6mm 0 2mm 0; }
.toc { margin: 0 0 1.2mm 0; }
.mark { color: #9a5b00; font-size: 9pt; }
.note { color: #9a5b00; }
.k { font-weight: bold; }
.cap { font-weight: bold; font-size: 10pt; margin: 3mm 0 1mm 0; }
table.t { border-collapse: collapse; width: 100%; margin: 0 0 4mm 0; }
table.t td, table.t th { border: 0.2mm solid #8a8f98; padding: 1.2mm 1.6mm; font-size: 9.5pt; vertical-align: top; text-align: right; }
table.t th { background-color: #eef1f6; font-weight: bold; }
.img { text-align: center; margin: 2mm 0 1mm 0; }
.imgcap { text-align: center; font-size: 9pt; color: #4a5160; margin: 0 0 4mm 0; }
.fp { text-align: center; font-size: 8pt; color: #4a5160; margin-top: 12mm; }
.hd { text-align: center; font-size: 9pt; color: #4a5160; }
.banner { text-align: center; font-size: 10pt; font-weight: bold; color: #b42318; }
.ft { text-align: center; font-size: 8pt; color: #4a5160; }
CSS;

/** בלוק אחד ← HTML שלם (אלמנט סגור), כך שאפשר לכתוב את המסמך בלוק-בלוק ולבדוק את מגבלת הזמן ביניהם. */
function apr_pdf_block(array $b, array $images): string
{
    $h = 'apr_pdf_h';
    switch ($b['t']) {
        case 'title': return '<h1>' . $h($b['text']) . '</h1><div class="sub">' . $h($b['sub']) . '</div>';
        case 'stamp': return '<div class="stamp">' . $h($b['text']) . '</div>';
        case 'details': return '<div class="details">' . $h($b['text']) . '</div>';
        case 'toc': return '<div class="toc-h">תוכן העניינים</div>' . implode('', array_map(fn($i) => '<div class="toc">' . $h($i) . '</div>', $b['items']));
        case 'h': return $b['level'] === 1 ? '<h2>' . $h($b['text']) . '</h2>' : '<h3>' . $h($b['text']) . '</h3>';
        case 'p': return '<p>' . (isset($b['mark']) ? '<span class="mark">[' . $h($b['mark']) . ']</span> ' : '') . $h($b['text']) . '</p>';
        case 'kv': return '<p><span class="k">' . $h($b['label']) . ':</span> ' . $h($b['value']) . ($b['note'] !== null ? ' <span class="note">(' . $h($b['note']) . ')</span>' : '') . '</p>';
        case 'missing': return '<p class="note">' . $h($b['text']) . '</p>';
        case 'list': return '<ul>' . implode('', array_map(fn($i) => '<li>' . $h($i) . '</li>', $b['items'])) . '</ul>';
        case 'table':
            $row = fn(array $r, string $tag) => '<tr>' . implode('', array_map(fn($c) => "<$tag>" . $h((string)$c) . "</$tag>", $r)) . '</tr>';
            return ($b['caption'] !== null && $b['caption'] !== '' ? '<div class="cap">' . $h($b['caption']) . '</div>' : '') . '<table class="t">'
                . ($b['head'] !== null ? '<thead>' . $row($b['head'], 'th') . '</thead>' : '') . '<tbody>' . implode('', array_map(fn($r) => $row($r, 'td'), $b['rows'])) . '</tbody></table>';
        case 'image':
            $img = $images[$b['id']] ?? null;
            if ($img === null || preg_match('/^img\d+\z/', $b['id']) !== 1) return '';
            // הרוחב במילימטרים מחושב כאן ממידות התמונה; "var:<מזהה>" הוא שם של תמונה בזיכרון — לא נתיב ולא כתובת
            $mm = min(150, (int)round($img['w'] * 25.4 / 96));
            return '<div class="img"><img src="var:' . $b['id'] . '" width="' . $mm . 'mm" /></div>' . ($b['caption'] !== '' ? '<div class="imgcap">' . $h($b['caption']) . '</div>' : '');
        case 'image_missing': return '<p class="note" style="text-align: center">' . $h('התמונה לא זמינה' . ($b['caption'] !== '' ? ' — ' . $b['caption'] : '')) . '</p>';
        case 'fingerprint': return '<div class="fp">' . $h($b['text']) . '</div>';
    }
    return '';
}

/**
 * כותב את המסמך לקובץ $path. זורק חריגה כשההפקה נכשלה (של mPDF, או RuntimeException('export: timeout') כש-$deadline עבר). אינו כותב
 * דבר מחוץ ל-$path ול-data/mpdf/.
 */
function apr_export_pdf(array $doc, string $path, ?float $deadline = null): void
{
    apr_pdf_load();
    $m = $doc['meta'];
    $h = 'apr_pdf_h';
    $tok = fn() => '{' . bin2hex(random_bytes(10)) . '}';
    $mpdf = new AprMpdf([
        'mode' => 'utf-8', 'format' => 'A4', 'tempDir' => apr_pdf_tmp_dir(),
        'fontDir' => [__DIR__ . '/fonts'],
        // useOTL = 0: בלי החלפות של גופן (ליגטורות כמו "fi") — הטקסט שבקובץ הוא הטקסט שנכתב. עברית בלי ניקוד אינה צריכה אותן
        'fontdata' => [APR_PDF_FONT => ['R' => 'DejaVuSans.ttf', 'B' => 'DejaVuSans-Bold.ttf', 'useOTL' => 0x00]],
        'default_font' => APR_PDF_FONT, 'default_font_size' => 11,
        'margin_left' => 20, 'margin_right' => 20, 'margin_top' => 30, 'margin_bottom' => 24, 'margin_header' => 9, 'margin_footer' => 9,
        'setAutoTopMargin' => 'stretch', 'setAutoBottomMargin' => 'stretch',
        // שום הבאה: לא מהרשת, לא מהדיסק לפי נתיב שבתוכן, לא PDF מיובא ולא קובצי הערות
        'whitelistStreamWrappers' => [], 'enableImports' => false, 'allowAnnotationFiles' => false, 'curlFollowLocation' => false,
        'showImageErrors' => false, 'debug' => false, 'autoScriptToLang' => false, 'autoLangToFont' => false, 'useSubstitutions' => false,
        'aliasNbPg' => $tok(), 'aliasNbPgGp' => $tok(),
        'watermarkAngle' => 45, 'watermark_font' => APR_PDF_FONT, 'h2bookmarks' => ['H2' => 0, 'H3' => 1],
    ]);
    $mpdf->aprPageToken = $tok();
    $mpdf->SetDirectionality('rtl');
    // המטא-דאטה (§4.5): הכותרת — מספר התיק והגרסה — ושם המשרד. לא שם משתמש, ולא נתיב
    $mpdf->SetTitle($m['title']);
    $mpdf->SetAuthor($m['office']);
    $mpdf->SetCreator($m['app']);
    foreach ($doc['images'] as $id => $img) if (preg_match('/^img\d+\z/', (string)$id) === 1) $mpdf->imageVars[$id] = $img['data'];
    $head = '';
    if ($m['logo'] !== null) {
        $mpdf->imageVars['logo'] = $m['logo']['data'];
        $head .= '<div class="hd"><img src="var:logo" height="10mm" /></div>';
    }
    if ($m['header_text'] !== '') $head .= '<div class="hd">' . $h($m['header_text']) . '</div>';
    // סימון המצב נכתב לתוך הקובץ, בכותרת כל עמוד: "טיוטה — לא מאושר" / "גרסה שהוחלפה — קיימת גרסה N" (§4.4)
    if ($m['banner'] !== null) $head .= '<div class="banner">' . $h($m['banner']) . '</div>';
    $foot = '<div class="ft">' . $h($m['footer']) . ' · עמוד ' . $mpdf->aprPageToken . ' מתוך ' . $mpdf->aliasNbPgGp . '</div>' . ($m['footer_text'] !== '' ? '<div class="ft">' . $h($m['footer_text']) . '</div>' : '');
    if ($m['watermark'] !== null) {
        $mpdf->SetWatermarkText($m['watermark'], 0.09);
        $mpdf->showWatermarkText = true;
    }
    $mpdf->WriteHTML(APR_PDF_CSS, \Mpdf\HTMLParserMode::HEADER_CSS);
    if ($head !== '') $mpdf->SetHTMLHeader('<div dir="rtl">' . $head . '</div>');
    $mpdf->SetHTMLFooter('<div dir="rtl">' . $foot . '</div>');
    foreach ($doc['blocks'] as $b) {
        if ($deadline !== null && microtime(true) > $deadline) throw new RuntimeException('export: timeout');
        $html = apr_pdf_block($b, $doc['images']);
        if ($html !== '') $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
    }
    $mpdf->Output($path, \Mpdf\Output\Destination::FILE);
}

apr_health_register('export', 'הפקת Word ו-PDF (פרוסה 18)', function (): array {
    $missing = array_keys(array_filter(['gd' => !function_exists('imagecreatetruecolor'), 'mbstring' => !function_exists('mb_convert_encoding'), 'zip' => !class_exists('ZipArchive')]));
    if ($missing) return ['bad', 'חסרות הרחבות PHP: ' . implode(', ', $missing) . ' — בלי gd ו-mbstring אין PDF, ובלי zip אין Word'];
    if (!is_file(dirname(__DIR__) . '/vendor/autoload.php') || !is_file(dirname(__DIR__) . '/vendor/mpdf/mpdf/src/Mpdf.php')) return ['bad', 'ספריית ה-PDF (vendor/) לא נפרסה'];
    foreach (['DejaVuSans.ttf', 'DejaVuSans-Bold.ttf'] as $f) if (!is_file(__DIR__ . '/fonts/' . $f)) return ['bad', 'קובץ הגופן חסר: lib/fonts/' . $f];
    $dir = apr_pdf_tmp_dir();
    $probe = $dir . '/.probe-' . bin2hex(random_bytes(4));
    $ok = is_dir($dir) && @file_put_contents($probe, 'x') === 1;
    @unlink($probe);
    if (!$ok) return ['bad', 'תיקיית העבודה של ה-PDF (data/mpdf) אינה ניתנת לכתיבה'];
    return ['ok', 'gd, mbstring ו-zip קיימות · data/mpdf כתיבה · הגופן במקומו'];
});
