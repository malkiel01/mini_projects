<?php
// פרוסה 18 — יצוא ל-Word ול-PDF (docs/spec/18-export-delivery.md). **בשלב הזה: בדיקת עשן לשני הכותבים בלבד**, על מסמך ביניים שנבנה ביד.
// הפעולות, משימת הרקע, המסירה והקריטריונים 18-A1…18-C9 — עוד לא נבנו (team/log/code.md, 2026-11-27).
require __DIR__ . '/lib.php';

$dir = APR_DATA_DIR . '/v-export';
mkdir($dir, 0700, true);

// ── טקסט ותאים ──
eq([apr_export_cell('=SUM(A1)'), apr_export_cell('@cmd'), apr_export_cell("+1+cmd|' /C calc'!A0"), apr_export_cell('-2+3'), apr_export_cell('-5%'), apr_export_cell('-50,000 ₪'), apr_export_cell('+972 3 5551234'), apr_export_cell('30045/12')],
    ["'=SUM(A1)", "'@cmd", "'+1+cmd|' /C calc'!A0", "'-2+3", '-5%', '-50,000 ₪', '+972 3 5551234', '30045/12'], 'תא שנראה כמו נוסחה מנוטרל; מספר שלילי או חיובי נשאר כמו שהוא');
eq(apr_export_text("א\x00ב\x08ג\u{202E}ד\u{2066}ה\u{200F}ו"), "אבגדה\u{200F}ו", 'טקסט לקובץ: בלי תווי בקרה ובלי תווי היפוך כיוון נסתרים (סימן כיוון רגיל נשאר)');

// ── מסמך ביניים שנבנה ביד: כל סוגי הבלוקים, וטקסט שמנסה לצאת מהקידוד ──
$im = imagecreatetruecolor(400, 250);
imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 160));
ob_start();
imagejpeg($im, null, 80);
$jpg = (string)ob_get_clean();
$evil = 'file {PAGENO} {nb} {nbpg} {DATE j-m-Y} <img src="file:///etc/passwd"> &amp; <script>alert(1)</script> </w:t><w:br/>';
$doc = fn(string $state) => ['meta' => ['title' => 'דוח שמאות — תיק 2026-0012 · גרסה 1', 'office' => 'משרד "אלף" & בניו', 'app' => 'שמאות מקרקעין', 'case_number' => '2026-0012', 'version' => 1,
        'state' => $state, 'state_label' => APR_EXPORT_STATE_LABELS[$state], 'date' => '27.11.2026', 'banner' => $state === 'draft' ? APR_EXPORT_DRAFT_MARK : null, 'watermark' => $state === 'draft' ? APR_EXPORT_DRAFT_MARK : null,
        'footer' => 'תיק 2026-0012 · גרסה 1 · 27.11.2026 · ' . APR_EXPORT_STATE_LABELS[$state], 'header_text' => 'משרד "אלף" & בניו', 'footer_text' => '', 'logo' => null],
    'blocks' => [['t' => 'title', 'text' => 'דוח שמאות — תיק 2026-0012', 'sub' => 'גרסה 1'], ['t' => 'stamp', 'text' => $state === 'draft' ? APR_EXPORT_DRAFT_MARK : 'אושר בידי אבי כהן, 27.11.2026'],
        ['t' => 'toc', 'items' => ['תיאור הנכס', 'עסקאות', 'מסקנה']], ['t' => 'h', 'level' => 1, 'text' => 'תיאור הנכס'],
        ['t' => 'p', 'text' => 'הנכס ברחוב Herzl 12, גוש/חלקה 30045/12, שטח 85.5 מ"ר.', 'mark' => APR_EXPORT_AI_MARK], ['t' => 'p', 'text' => $evil], ['t' => 'p', 'text' => 'Only English with {PAGENO} here.'],
        ['t' => 'kv', 'label' => 'שטח רשום', 'value' => '85.5 מ"ר (רשום)', 'note' => 'לא אומת'], ['t' => 'missing', 'text' => 'לא ידוע: שנת בנייה'], ['t' => 'list', 'items' => ['פריט ראשון', 'Second']],
        ['t' => 'h', 'level' => 2, 'text' => 'תמונות'], ['t' => 'image', 'id' => 'img1', 'caption' => 'חזית'], ['t' => 'image_missing', 'caption' => 'סלון'],
        ['t' => 'table', 'head' => ['כתובת', 'מחיר (₪)'], 'caption' => 'עסקאות', 'rows' => [['רחוב הרצל 1', '1,501,000'], ["'=SUM(A1)", '-5%']]], ['t' => 'table', 'head' => null, 'caption' => null, 'rows' => [['א', 'ב']]],
        ['t' => 'fingerprint', 'text' => 'טביעת האצבע של הגרסה: 0123456789ab']],
    'images' => ['img1' => ['data' => $jpg, 'w' => 400, 'h' => 250]], 'content_hash' => str_repeat('0', 64)];

// ── Word: ZIP תקין, XML תקין, מימין לשמאל, והטקסט מקודד ──
foreach (['draft', 'approved'] as $state) {
    apr_export_docx($doc($state), "$dir/$state.docx");
    $zip = new ZipArchive();
    check("18-A2 [$state] ה-DOCX נפתח כ-ZIP", $zip->open("$dir/$state.docx") === true);
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) $parts[$zip->getNameIndex($i)] = (string)$zip->getFromIndex($i);
    $bad = [];
    foreach ($parts as $name => $xml) if (preg_match('/\.(xml|rels)\z/', $name) && !@(new DOMDocument())->loadXML($xml)) $bad[] = $name;
    eq($bad, [], "18-A2 [$state] כל חלק XML בקובץ תקין");
    $body = $parts['word/document.xml'] ?? '';
    eq([substr_count($body, '<w:p>'), substr_count($body, '<w:tbl>')], [substr_count($body, '<w:bidi/></w:pPr>') + substr_count($body, '<w:bidi/><w:spacing') + substr_count($body, '<w:bidi/><w:jc'), substr_count($body, '<w:bidiVisual/>')],
        "18-A2 [$state] כל פסקה מסומנת מימין לשמאל, וכל טבלה");
    $text = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $body)), ENT_QUOTES | ENT_XML1, 'UTF-8');
    check("[$state] הטקסט של המשתמש נכתב כטקסט — בדיוק כפי שהוא, ואינו הופך ל-XML", str_contains($text, $evil) && !str_contains($body, '<img') && !str_contains($body, '<script') && substr_count($body, '<w:br/>') === 0);
    $all = implode("\n", array_map(fn($n) => preg_match('/\.(xml|rels)\z/', $n) ? $parts[$n] : '', array_keys($parts)));
    eq([str_contains($all, APR_EXPORT_DRAFT_MARK), str_contains($parts['word/header1.xml'], 'v:textpath'), str_contains($all, 'אושר בידי אבי כהן')], $state === 'draft' ? [true, true, false] : [false, false, true],
        "18-A3 [$state] סימון המצב נכתב לתוך הקובץ: טיוטה — בכותרת כל עמוד ובסימן מים; מאושרת — מי אישר");
    check("18-A5 [$state] אין \"חתום\" בקובץ", !str_contains($all, 'חתום'));
    check("[$state] כותרת תחתונה: מספר התיק, הגרסה, התאריך, המצב ו\"עמוד X מתוך Y\" בשדות", str_contains($parts['word/footer1.xml'], 'תיק 2026-0012 · גרסה 1 · 27.11.2026') && str_contains($parts['word/footer1.xml'], ' PAGE ') && str_contains($parts['word/footer1.xml'], ' NUMPAGES '));
    check("18-A8 [$state] המטא-דאטה: הכותרת ושם המשרד בלבד", str_contains($parts['docProps/core.xml'], '<dc:creator>משרד &quot;אלף&quot; &amp; בניו</dc:creator>') && !preg_match('~/home/|/tmp/|\\\\~', $parts['docProps/core.xml'] . $parts['docProps/app.xml']));
    check("[$state] כותרות אמיתיות, שורת כותרת חוזרת בטבלה, תמונה משובצת וגופן מוטמע ומעורפל", str_contains($body, '<w:pStyle w:val="Heading1"/>') && str_contains($body, '<w:tblHeader/>') && ($parts['word/media/image1.jpeg'] ?? '') === $jpg
        && isset($parts['word/fonts/font1.odttf'], $parts['word/fonts/font2.odttf']) && substr($parts['word/fonts/font1.odttf'], 0, 4) !== "\x00\x01\x00\x00" && substr($parts['word/fonts/font1.odttf'], 32, 64) === substr((string)file_get_contents(__DIR__ . '/../lib/fonts/DejaVuSans.ttf'), 32, 64));
    $zip->close();
}

// ── PDF: mPDF נטענת רק כשמפיקים; הקובץ נוצר; מחרוזות הקסם שלה אינן נוגעות בטקסט של משתמש ──
check('ספריית ה-PDF אינה נטענת עד שמפיקים PDF', !class_exists('Mpdf\\Mpdf', false));
foreach (['draft', 'approved'] as $state) {
    apr_export_pdf($doc($state), "$dir/$state.pdf");
    $pdf = (string)file_get_contents("$dir/$state.pdf");
    check("[$state] ה-PDF נוצר, ומטמון הגופנים נכתב תחת data/mpdf", str_starts_with($pdf, '%PDF-') && strlen($pdf) > 10000 && is_dir(APR_DATA_DIR . '/mpdf/mpdf/ttfontdata'));
    $bin = apr_pdf_text_bin();
    if ($bin === null) continue;                    // בלי pdftotext במכונה — הבדיקה המלאה (חילוץ טקסט משלנו) תיכתב עם הפעולות
    $text = (string)shell_exec(escapeshellarg($bin) . ' -layout ' . escapeshellarg("$dir/$state.pdf") . ' - 2>/dev/null');
    $flat = (string)preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\s]+/u', ' ', $text);
    check("[$state] טקסט של משתמש: {PAGENO}, {nb}, {nbpg}, {DATE …} ותגית <img> מודפסים כמו שהם", str_contains($flat, '{PAGENO} {nb} {nbpg} {DATE j-m-Y} <img src="file:///etc/passwd">') && str_contains($flat, 'English with {PAGENO} here'));
    eq([str_contains($flat, APR_EXPORT_DRAFT_MARK), str_contains($flat, 'אושר בידי אבי כהן')], $state === 'draft' ? [true, false] : [false, true], "18-A3 [$state] סימון המצב נכתב לתוך ה-PDF");
    check("18-A1 / 18-A5 [$state] מספר התיק והגרסה בכל עמוד; גוש/חלקה בכיוון נכון; אין \"חתום\"", str_contains($flat, '2026-0012') && str_contains($flat, '30045/12') && preg_match('/עמוד 1 מתוך \d/u', $flat) === 1 && !str_contains($flat, 'חתום'));
}
check('vendor/: שום דבר לא נכתב לתיקיית הספרייה', !is_dir(__DIR__ . '/../vendor/mpdf/mpdf/tmp') && !glob(__DIR__ . '/../vendor/mpdf/mpdf/ttfonts/*.json'));
check('בדיקת התקינות של היצוא רשומה במסך המפתח, ותקינה כאן', (array_column(apr_health()['checks'], 'state', 'id')['export'] ?? null) === 'ok');

done();
