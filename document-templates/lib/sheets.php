<?php
/**
 * שלב 12: גיליונות — קריאה של Excel (xlsx) ו-CSV, וכתיבה של xlsx.
 *
 * בלי ספריות: xlsx הוא ZIP עם XML. קוראים את הגיליון הראשון; שורה ראשונה =
 * כותרות. תאריך ב-Excel הוא מספר ימים עם עיצוב תאריך — מזהים לפי העיצוב
 * ומחזירים YYYY-MM-DD. CSV ש-Excel שמר בעברית הוא לרוב Windows-1255 — ממירים.
 */

declare(strict_types=1);

const SHEET_MAX_ROWS = 1000;
const SHEET_MAX_COLS = 60;
const SHEET_MAX_MB   = 5;
const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

/** מספר עמודה מאות (A=0, AA=26). */
function col_index(string $ref): int
{
    $letters = preg_replace('/\d+/', '', strtoupper($ref));
    $n = 0;
    foreach (str_split($letters) as $c) $n = $n * 26 + (ord($c) - 64);
    return $n - 1;
}

function col_letter(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

/** קורא קובץ (xlsx או csv) → מערך שורות של מחרוזות. */
function sheet_read(string $bytes): array
{
    if ($bytes === '') throw new ApiError('הקובץ ריק', 422);
    $rows = str_starts_with($bytes, "PK\x03\x04") ? xlsx_read($bytes) : csv_read($bytes);
    // בלי שורות ריקות בסוף, ובלי עמודות ריקות בקצה
    while ($rows && !array_filter(end($rows), fn($v) => trim($v) !== '')) array_pop($rows);
    if (!$rows) throw new ApiError('לא נמצאו שורות בקובץ', 422);
    $width = 0;
    foreach ($rows as $r) for ($i = count($r) - 1; $i >= 0; $i--) if (trim($r[$i]) !== '') { $width = max($width, $i + 1); break; }
    if ($width > SHEET_MAX_COLS) throw new ApiError('יותר מדי עמודות (עד ' . SHEET_MAX_COLS . ')', 422);
    if (count($rows) > SHEET_MAX_ROWS + 1) throw new ApiError('יותר מדי שורות (עד ' . SHEET_MAX_ROWS . ')', 422);
    return array_map(fn($r) => array_map(fn($i) => trim((string)($r[$i] ?? '')), range(0, max(0, $width - 1))), $rows);
}

function csv_read(string $bytes): array
{
    if (str_starts_with($bytes, "\xEF\xBB\xBF")) $bytes = substr($bytes, 3);
    if (!mb_check_encoding($bytes, 'UTF-8')) {
        // Excel בעברית שומר CSV ב-Windows-1255
        $conv = @iconv('WINDOWS-1255', 'UTF-8//IGNORE', $bytes);
        if ($conv === false || $conv === '') throw new ApiError('קידוד הקובץ לא מזוהה. שמרו כ-CSV UTF-8 או כ-Excel', 422);
        $bytes = $conv;
    }
    if (str_contains(substr($bytes, 0, 2000), "\0")) throw new ApiError('הקובץ אינו CSV או Excel', 415);
    $first = strtok($bytes, "\n") ?: '';
    $delims = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    arsort($delims);
    $d = array_key_first($delims);
    $h = fopen('php://temp', 'r+');
    fwrite($h, $bytes);
    rewind($h);
    $rows = [];
    while (($r = fgetcsv($h, 0, $d, '"', '')) !== false) {
        if ($r === [null]) $r = [''];
        $rows[] = array_map(fn($v) => (string)$v, $r);
        if (count($rows) > SHEET_MAX_ROWS + 1) break;
    }
    fclose($h);
    return $rows;
}

function xlsx_read(string $bytes): array
{
    if (!class_exists('ZipArchive')) throw new ApiError('השרת לא תומך בקובצי Excel (חסר ZipArchive). שמרו כ-CSV', 501);
    $tmp = tempnam(sys_get_temp_dir(), 'dtx');
    file_put_contents($tmp, $bytes);
    $z = new ZipArchive();
    if ($z->open($tmp) !== true) { unlink($tmp); throw new ApiError('קובץ Excel פגום', 422); }
    try {
        $xml = function (string $name) use ($z): ?SimpleXMLElement {
            $s = $z->getFromName($name);
            if ($s === false) return null;
            $x = @simplexml_load_string($s, SimpleXMLElement::class, LIBXML_NONET);
            return $x === false ? null : $x;
        };
        $wb = $xml('xl/workbook.xml');
        if (!$wb) throw new ApiError('הקובץ אינו Excel (xlsx)', 415);
        // הגיליון הראשון — לפי הקשר שלו ב-workbook.xml.rels
        $path = 'xl/worksheets/sheet1.xml';
        $ns = $wb->getNamespaces(true);
        $sheet = $wb->sheets->sheet[0] ?? null;
        $rels = $xml('xl/_rels/workbook.xml.rels');
        if ($sheet && $rels) {
            $rid = (string)$sheet->attributes($ns['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($rels->Relationship as $r) {
                if ((string)$r['Id'] === $rid) {
                    $t = ltrim((string)$r['Target'], '/');
                    $path = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                }
            }
        }
        $strings = [];
        if ($ss = $xml('xl/sharedStrings.xml')) {
            foreach ($ss->si as $si) {
                $t = '';
                if (isset($si->t)) $t = (string)$si->t;
                foreach ($si->r as $run) $t .= (string)$run->t;
                $strings[] = $t;
            }
        }
        $dateStyles = xlsx_date_styles($xml('xl/styles.xml'));
        $ws = $xml($path);
        if (!$ws) throw new ApiError('לא נמצא גיליון בקובץ', 422);
        $rows = [];
        foreach ($ws->sheetData->row as $row) {
            $ri = (int)$row['r'] - 1;
            if ($ri < 0) $ri = count($rows);
            if ($ri > SHEET_MAX_ROWS + 1) break;
            $cells = [];
            $ci = 0;
            foreach ($row->c as $c) {
                if (isset($c['r'])) $ci = col_index((string)$c['r']);
                if ($ci >= SHEET_MAX_COLS * 2) break;
                $type = (string)$c['t'];
                $v = (string)$c->v;
                $val = match ($type) {
                    's' => $strings[(int)$v] ?? '',
                    'inlineStr' => inline_text($c),
                    'b' => $v === '1' ? 'TRUE' : 'FALSE',
                    'str', 'e' => $v,
                    default => $v,
                };
                if ($type === '' || $type === 'n') {
                    if ($v !== '' && is_numeric($v) && isset($dateStyles[(int)$c['s']])) $val = excel_date((float)$v);
                    elseif (is_numeric($v)) $val = num_text($v);
                }
                $cells[$ci] = $val;
                $ci++;
            }
            $line = [];
            if ($cells) for ($i = 0, $n = max(array_keys($cells)); $i <= $n; $i++) $line[] = $cells[$i] ?? '';
            for ($k = count($rows); $k < $ri; $k++) $rows[] = [];   // שורות שדולגו בקובץ
            $rows[] = $line;
        }
        return $rows;
    } finally {
        $z->close();
        unlink($tmp);
    }
}

function inline_text(SimpleXMLElement $c): string
{
    $t = isset($c->is->t) ? (string)$c->is->t : '';
    foreach ($c->is->r ?? [] as $r) $t .= (string)$r->t;
    return $t;
}

/** אינדקסים של סגנונות שהם תאריך (מובנים 14–22, 45–47, או תבנית עם d/m/y). */
function xlsx_date_styles(?SimpleXMLElement $st): array
{
    if (!$st) return [];
    $custom = [];
    foreach ($st->numFmts->numFmt ?? [] as $f) {
        $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', strtolower((string)$f['formatCode']));
        if (preg_match('/[dmy]/', $code) && !preg_match('/^[#0.,%]+$/', $code)) $custom[(int)$f['numFmtId']] = true;
    }
    $out = [];
    $i = 0;
    foreach ($st->cellXfs->xf ?? [] as $xf) {
        $id = (int)$xf['numFmtId'];
        if (($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47) || isset($custom[$id])) $out[$i] = true;
        $i++;
    }
    return $out;
}

/** מספר ימים של Excel (מ-1900) → YYYY-MM-DD. */
function excel_date(float $serial): string
{
    $days = (int)floor($serial);
    if ($days < 1) return '';
    return gmdate('Y-m-d', ($days - 25569) * 86400);   // 25569 = 1970-01-01; כולל הבאג של 1900 ב-Excel
}

/** מספר כטקסט, בלי 4500.0 ובלי 1.2E+3. */
function num_text(string $v): string
{
    $f = (float)$v;
    if (floor($f) === $f && abs($f) < 1e15) return (string)(int)$f;
    return rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');
}

/**
 * כותב xlsx: שורה ראשונה מודגשת וקפואה, מימין לשמאל. ערך מסוג int/float —
 * תא מספר; כל השאר — טקסט.
 */
function xlsx_write(array $rows, string $sheetName = 'גיליון1'): string
{
    $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $width = [];
    $data = '';
    foreach ($rows as $ri => $row) {
        $data .= '<row r="' . ($ri + 1) . '">';
        foreach (array_values($row) as $ci => $v) {
            $ref = col_letter($ci) . ($ri + 1);
            $s = $ri === 0 ? ' s="1"' : (is_string($v) && str_contains($v, "\n") ? ' s="2"' : '');
            if (is_int($v) || is_float($v)) {
                $data .= "<c r=\"$ref\"$s><v>" . num_text((string)$v) . '</v></c>';
            } elseif ((string)$v !== '') {
                $data .= "<c r=\"$ref\" t=\"inlineStr\"$s><is><t xml:space=\"preserve\">" . $x(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$v)) . '</t></is></c>';
            }
            $len = max(array_map('mb_strlen', explode("\n", (string)$v)));
            $width[$ci] = max($width[$ci] ?? 8, min(60, $len + 2));
        }
        $data .= '</row>';
    }
    $cols = '';
    foreach ($width as $ci => $w) $cols .= '<col min="' . ($ci + 1) . '" max="' . ($ci + 1) . '" width="' . $w . '" customWidth="1"/>';
    $NS = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    $sheet = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<worksheet $NS>"
        . '<sheetViews><sheetView workbookViewId="0" rightToLeft="1"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . ($cols ? "<cols>$cols</cols>" : '') . "<sheetData>$data</sheetData></worksheet>";
    $styles = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<styleSheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\">"
        . '<fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs>'
        . '</styleSheet>';
    $tmp = tempnam(sys_get_temp_dir(), 'dtw');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<workbook $NS>"
        . '<sheets><sheet name="' . $x(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/u', ' ', $sheetName), 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $z->addFromString('xl/styles.xml', $styles);
    $z->close();
    $bytes = (string)file_get_contents($tmp);
    unlink($tmp);
    return $bytes;
}

/** שולח קובץ xlsx להורדה. */
function send_xlsx(string $bytes, string $name): never
{
    $name = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', $name) . '.xlsx';
    header('Content-Type: ' . XLSX_MIME);
    header('Content-Length: ' . strlen($bytes));
    header("Content-Disposition: attachment; filename=\"export.xlsx\"; filename*=UTF-8''" . rawurlencode($name));
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
}
