<?php
/**
 * תבנית Word — שלב 9. ממלאים בתוך קובץ ה-Word עצמו, כך שהעיצוב נשאר 1:1.
 *
 * בקובץ מסמנים שדות כך: {{שם_לקוח}}. כשמעלים, המערכת:
 *   1. מאחדת כל סימון לריצת טקסט אחת. Word נוטה לפצל "{{שם_לקוח}}" לכמה
 *      <w:r> (בגלל בדיקת איות, עריכה, שינוי עיצוב באמצע) — בלי איחוד, החלפה
 *      פשוטה לא תמצא אותו. האיחוד שומר את העיצוב של תחילת הסימון.
 *   2. שומרת את הקובץ המאוחד (מוצפן) כקובץ הרקע של התבנית.
 *   3. בונה שדה לכל סימון ייחודי. הסוג מנוחש מהשם (תאריך / סכום / סימון).
 *
 * במילוי: כל {{...}} מוחלף בערך, בגוף המסמך, בכותרות העליונות והתחתונות.
 * ירידת שורה בערך הופכת ל-<w:br/>. אין המרה ל-PDF (הוכרע: בלי מנוע המרה) —
 * הפלט הוא Word, ו-PDF מפיקים ב-Word עצמו.
 */

declare(strict_types=1);

const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
const PLACEHOLDER = '/\{\{\s*([^{}]{1,60}?)\s*\}\}/u';

/** קובצי ה-XML שיש בהם טקסט: גוף, כותרות, הערות שוליים. */
function docx_parts(ZipArchive $zip): array
{
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if (preg_match('#^word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$#', $n)) $parts[] = $n;
    }
    return $parts;
}

function is_docx(string $bytes): bool
{
    if (!class_exists('ZipArchive') || !str_starts_with($bytes, "PK")) return false;
    $tmp = tempnam(sys_get_temp_dir(), 'dx');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    $ok = $zip->open($tmp) === true && $zip->locateName('word/document.xml') !== false;
    if ($ok) $zip->close();
    @unlink($tmp);
    return $ok;
}

/** פותח docx מבתים, מריץ $fn על כל חלק XML (DOMDocument), ומחזיר את הבתים החדשים. */
function docx_transform(string $bytes, callable $fn): string
{
    if (!class_exists('ZipArchive')) throw new ApiError('השרת לא תומך בקובצי ZIP — אי אפשר לעבוד עם Word', 501);
    $tmp = tempnam(sys_get_temp_dir(), 'dx');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { @unlink($tmp); throw new ApiError('קובץ Word פגום', 422); }
    foreach (docx_parts($zip) as $part) {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        if (!@$dom->loadXML((string)$zip->getFromName($part), LIBXML_NONET)) continue;
        if ($fn($dom, $part) !== false) $zip->addFromString($part, $dom->saveXML());
    }
    $zip->close();
    $out = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $out;
}

/** כל ה-<w:t> בפסקה, לפי הסדר. */
function para_texts(DOMXPath $xp, DOMElement $p): array
{
    return iterator_to_array($xp->query('.//w:r/w:t', $p));
}

/**
 * מאחד סימונים מפוצלים: הטקסט של הפסקה נבנה מכל ה-<w:t>, ולכל סימון שחוצה
 * כמה מהם — הסימון כולו נכתב בראשון, והחלקים נמחקים מהבאים.
 */
function docx_normalize(string $bytes, ?array &$found = null): string
{
    $found = [];
    return docx_transform($bytes, function (DOMDocument $dom) use (&$found) {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', W_NS);
        foreach ($xp->query('//w:p') as $p) {
            $ts = para_texts($xp, $p);
            if (!$ts) continue;
            $full = '';
            $starts = [];
            foreach ($ts as $i => $t) { $starts[$i] = strlen($full); $full .= $t->textContent; }
            if (!preg_match_all(PLACEHOLDER, $full, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) continue;
            foreach ($m as $mm) $found[] = trim($mm[1][0]);   // לפי סדר ההופעה
            // מהסוף להתחלה, כדי שהיסטים מוקדמים יישארו נכונים.
            foreach (array_reverse($m) as $match) {
                [$text, $off] = $match[0];
                $end = $off + strlen($text);
                $si = $ei = null;
                foreach ($ts as $i => $t) {
                    $a = $starts[$i];
                    $b = $a + strlen($t->textContent);
                    if ($si === null && $off >= $a && $off < $b) $si = $i;
                    if ($end > $a && $end <= $b) { $ei = $i; break; }
                }
                if ($si === null || $ei === null || $si === $ei) continue;   // כבר בריצה אחת
                $first = $ts[$si];
                $before = substr($first->textContent, 0, $off - $starts[$si]);
                $last = $ts[$ei];
                $after = substr($last->textContent, $end - $starts[$ei]);
                set_text($first, $before . '{{' . trim($match[1][0]) . '}}');
                for ($i = $si + 1; $i < $ei; $i++) set_text($ts[$i], '');
                set_text($last, $after);
            }
        }
    });
}

function set_text(DOMElement $t, string $s): void
{
    $t->nodeValue = '';
    $t->appendChild($t->ownerDocument->createTextNode($s));
    $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
}

/**
 * שדות מהסימונים שנמצאו — לפי סדר ההופעה, בלי כפילויות.
 * שלב 11: {{פריטים.כמות}} — עמודה בטבלה "פריטים"; שורה בטבלת Word שיש בה סימונים
 * כאלה חוזרת לכל שורה שממלאים.
 */
function docx_fields(array $names): array
{
    $fields = [];
    $seen = [];
    $guess = fn(string $n) => (bool)preg_match('/סכום|מחיר|כמות|amount|price|sum|qty/iu', $n) ? 'number' : 'text';
    foreach ($names as $n) {
        if (str_contains($n, '.')) {
            [$t, $col] = array_map('trim', explode('.', $n, 2));
            if ($t === '' || $col === '') continue;
            $i = array_search($t, array_column($fields, 'placeholder'), true);
            if ($i === false) {
                $fields[] = ['id' => 'f' . count($fields), 'key' => 'f' . (count($fields) + 1), 'placeholder' => $t,
                    'label' => trim(str_replace('_', ' ', $t)), 'type' => 'table', 'columns' => [],
                    'page' => 0, 'x' => 0, 'y' => 0, 'w' => 0.3, 'h' => 0.03, 'required' => false, 'role' => 'owner'];
                $i = count($fields) - 1;
            }
            $fields[$i]['type'] = 'table';
            $fields[$i]['columns'] ??= [];
            if (!in_array($col, array_column($fields[$i]['columns'], 'key'), true) && count($fields[$i]['columns']) < 12) {
                // מפתח העמודה = השם בקובץ (כך הסימון בשורה נמצא); clean_schema מנקה רק את המפתח הפנימי
                $fields[$i]['columns'][] = ['key' => 'c' . (count($fields[$i]['columns']) + 1), 'name' => $col,
                    'label' => trim(str_replace('_', ' ', $col)), 'type' => $guess($col)];
            }
            $seen[$n] = true;
            continue;
        }
        if (isset($seen[$n]) || in_array($n, array_column($fields, 'placeholder'), true)) continue;
        $seen[$n] = true;
        $label = trim(str_replace('_', ' ', $n));
        $type = match (true) {
            (bool)preg_match('/תאריך|date/iu', $n) => 'date',
            (bool)preg_match('/סכום|מחיר|כמות|amount|price|sum/iu', $n) => 'number',   // "מספר_חוזה" — לרוב עם אותיות, לא מספר
            (bool)preg_match('/סימון|מאשר|☐|check/iu', $n) => 'checkbox',
            (bool)preg_match('/הערות|תיאור|פירוט|notes/iu', $n) => 'textarea',
            default => 'text',
        };
        $fields[] = ['id' => 'f' . count($fields), 'key' => 'f' . (count($fields) + 1), 'placeholder' => $n, 'label' => $label,
            'type' => $type, 'page' => 0, 'x' => 0, 'y' => 0, 'w' => 0.3, 'h' => 0.03, 'required' => false, 'role' => 'owner'];
    }
    return $fields;
}

/** הערך כפי שהוא נכתב במסמך. */
function docx_value(array $f, string $v, array $attachments): string
{
    if (($custom = fmt_apply($f, $v)) !== null) return $custom;   // מבנה מותאם — כמו ב-PDF
    return match ($f['type']) {
        'calc' => calc_display($v, $f['format'] ?? 'number'),
        'select' => select_label($f, $v),
        'system' => in_array($f['source'] ?? '', ['issued', 'completed'], true) ? calc_display($v, 'date') : $v,
        'table' => implode("\n", array_map(fn($r) => implode(' · ', array_filter(array_map(
            fn($c) => cell_display($c, (string)($r[$c['key']] ?? '')), $f['columns'] ?? []), 'strlen')), table_rows($v))),
        'date' => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) ? "$m[3]/$m[2]/$m[1]" : $v,
        'checkbox' => $v === '1' ? '☒' : '☐',
        'signature' => $v !== '' ? '✔ נחתם' : '',
        'file' => implode(', ', array_map(fn($id) => $attachments[$id] ?? 'קובץ', json_decode($v ?: '[]', true) ?: [])),
        default => $v,
    };
}

/**
 * ממלא את הקובץ: כל {{שם}} → ערך. ירידת שורה → <w:br/> באותה ריצה.
 * $appendix — שורות להוספה בסוף הגוף (דף אישור), או ריק.
 */
function docx_fill(string $bytes, array $schema, array $values, array $attachments, array $appendix = []): string
{
    // שלב 11: שדה מוסתר (תנאי הצגה) — ריק במסמך
    $shown = array_column(visible_fields($schema, $values), 'key', 'key');
    $map = [];
    $tables = [];
    foreach ($schema['fields'] as $f) {
        if (empty($f['placeholder'])) continue;
        // מוסתר בתנאי, או "לא להדפיס" — ריק במסמך
        $v = isset($shown[$f['key']]) && ($f['print'] ?? true) ? (string)($values[$f['key']] ?? '') : '';
        $map[$f['placeholder']] = docx_value($f, $v, $attachments);
        if ($f['type'] === 'table') $tables[$f['placeholder']] = [$f, table_rows($v)];
    }
    return docx_transform($bytes, function (DOMDocument $dom, string $part) use ($map, $tables, $appendix) {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', W_NS);
        $changed = false;
        // שורה בטבלת Word עם {{טבלה.עמודה}} — עותק לכל שורה שמולאה; התבנית עצמה יוצאת
        foreach (iterator_to_array($xp->query('//w:tr')) as $tr) {
            if (!preg_match_all(PLACEHOLDER, $tr->textContent, $m)) continue;
            $hit = null;
            foreach ($m[1] as $name) {
                $t = trim(explode('.', trim($name), 2)[0]);
                if (str_contains($name, '.') && isset($tables[$t])) { $hit = $t; break; }
            }
            if ($hit === null) continue;
            [$f, $rows] = $tables[$hit];
            foreach ($rows as $r) {
                $copy = $tr->cloneNode(true);
                foreach ((new DOMXPath($dom))->query('.//w:t', $copy) as $t) {
                    $text = $t->textContent;
                    if (!str_contains($text, '{{')) continue;
                    set_text($t, preg_replace_callback(PLACEHOLDER, function ($mm) use ($f, $r, $hit) {
                        [$tn, $cn] = array_pad(array_map('trim', explode('.', trim($mm[1]), 2)), 2, '');
                        if ($tn !== $hit) return $mm[0];   // סימון אחר — המילוי הרגיל יטפל
                        $c = table_col($f, $cn);
                        return $c ? cell_display($c, (string)($r[$c['key']] ?? '')) : '';
                    }, $text));
                }
                $tr->parentNode->insertBefore($copy, $tr);
            }
            $tr->parentNode->removeChild($tr);
            $changed = true;
        }
        foreach (iterator_to_array($xp->query('//w:t')) as $t) {
            $text = $t->textContent;
            if (!str_contains($text, '{{')) continue;
            $new = preg_replace_callback(PLACEHOLDER, fn($m) => $map[trim($m[1])] ?? '', $text);
            $changed = true;
            $lines = preg_split('/\r?\n/', $new);
            set_text($t, array_shift($lines));
            $after = $t;
            foreach ($lines as $line) {
                $br = $dom->createElementNS(W_NS, 'w:br');
                $nt = $dom->createElementNS(W_NS, 'w:t');
                set_text($nt, $line);
                $after->parentNode->insertBefore($br, $after->nextSibling);
                $br->parentNode->insertBefore($nt, $br->nextSibling);
                $after = $nt;
            }
        }
        if ($appendix && $part === 'word/document.xml') {
            $body = $xp->query('//w:body')->item(0);
            $sect = $xp->query('w:sectPr', $body)->item(0);
            $mk = function (string $text, bool $pageBreak = false) use ($dom) {
                $p = $dom->createElementNS(W_NS, 'w:p');
                $pPr = $dom->createElementNS(W_NS, 'w:pPr');
                $pPr->appendChild($dom->createElementNS(W_NS, 'w:bidi'));
                $p->appendChild($pPr);
                $r = $dom->createElementNS(W_NS, 'w:r');
                $rPr = $dom->createElementNS(W_NS, 'w:rPr');   // rPr חייב להיות ראשון בריצה
                $rPr->appendChild($dom->createElementNS(W_NS, 'w:rtl'));
                $r->appendChild($rPr);
                if ($pageBreak) { $br = $dom->createElementNS(W_NS, 'w:br'); $br->setAttributeNS(W_NS, 'w:type', 'page'); $r->appendChild($br); }
                $t = $dom->createElementNS(W_NS, 'w:t');
                set_text($t, $text);
                $r->appendChild($t);
                $p->appendChild($r);
                return $p;
            };
            foreach ($appendix as $i => $line) $body->insertBefore($mk($line, $i === 0), $sect);
            $changed = true;
        }
        return $changed;
    });
}

/** תא בטבלה כפי שהוא נכתב: עמודה מחושבת — לפי התצוגה שלה. */
function cell_display(array $c, string $v): string
{
    return $c['type'] === 'calc' ? calc_display($v, $c['format'] ?? 'number') : $v;
}

/**
 * שלב 11: תבנית שנכתבה בכלי → קובץ Word, עם הסימונים {{...}} במקומם; docx_fill
 * ממלא אותו כמו כל תבנית Word. קטע שתנאי ההצגה שלו לא מתקיים — לא נכנס.
 */
function authored_docx(array $schema, array $values): string
{
    $x = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $shown = array_column(visible_fields($schema, $values), 'key', 'key');
    $byKey = array_column($schema['fields'] ?? [], null, 'key');
    $run = function (string $text, bool $bold = false, int $half = 0) use ($x) {
        $rPr = '<w:rPr>' . ($bold ? '<w:b/><w:bCs/>' : '') . ($half ? "<w:sz w:val=\"$half\"/><w:szCs w:val=\"$half\"/>" : '') . '<w:rtl/></w:rPr>';
        $parts = preg_split('/\r?\n/', $text);
        $out = '';
        foreach ($parts as $i => $line) $out .= ($i ? '<w:br/>' : '') . '<w:t xml:space="preserve">' . $x($line) . '</w:t>';
        return "<w:r>$rPr$out</w:r>";
    };
    $para = function (string $inner, string $align = 'right', string $extra = '') {
        $jc = ['center' => '<w:jc w:val="center"/>', 'justify' => '<w:jc w:val="both"/>'][$align] ?? '';
        return "<w:p><w:pPr>$extra<w:bidi/>$jc</w:pPr>$inner</w:p>";
    };
    $body = '';
    foreach ($schema['body'] ?? [] as $b) {
        $c = $b['showIf'] ?? null;
        if ($c && (!isset($shown[$c['key']]) || !is_shown($c, $schema['fields'] ?? [], $values))) continue;
        switch ($b['type']) {
            case 'heading':
                $body .= $para($run($b['text'], true, 32), $b['align'] ?? 'right', '<w:keepNext/><w:spacing w:after="160"/>');
                break;
            case 'p':
                $body .= $para($run($b['text']), $b['align'] ?? 'right', '<w:spacing w:after="120"/>');
                break;
            case 'break':
                $body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
                break;
            case 'table':
                $f = $byKey[$b['table']] ?? null;
                if (!$f || !isset($shown[$f['key']])) break;
                $cols = $f['columns'] ?? [];
                $w = (int)floor(9026 / max(1, count($cols)));
                $cell = fn(string $inner) => "<w:tc><w:tcPr><w:tcW w:w=\"$w\" w:type=\"dxa\"/></w:tcPr>" . $para($inner) . '</w:tc>';
                $borders = '';
                foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $side) $borders .= "<w:$side w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"999999\"/>";
                $body .= '<w:tbl><w:tblPr><w:bidiVisual/><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>' . $borders . '</w:tblBorders></w:tblPr><w:tblGrid>'
                    . str_repeat("<w:gridCol w:w=\"$w\"/>", count($cols)) . '</w:tblGrid>'
                    . '<w:tr><w:trPr><w:tblHeader/></w:trPr>' . implode('', array_map(fn($c) => $cell($run($c['label'], true)), $cols)) . '</w:tr>'
                    . '<w:tr>' . implode('', array_map(fn($c) => $cell($run('{{' . $f['placeholder'] . '.' . $c['key'] . '}}')), $cols)) . '</w:tr>'
                    . '</w:tbl>' . $para('', 'right', '<w:spacing w:after="0"/>');
                break;
        }
    }
    $W = 'xmlns:w="' . W_NS . '"';
    $doc = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<w:document $W><w:body>$body"
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/><w:bidi/></w:sectPr>'
        . '</w:body></w:document>';
    $styles = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<w:styles $W><w:docDefaults><w:rPrDefault><w:rPr>"
        . '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/><w:sz w:val="22"/><w:szCs w:val="22"/><w:lang w:val="en-US" w:bidi="he-IL"/>'
        . '</w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:bidi/><w:spacing w:after="80" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults></w:styles>';
    $tmp = tempnam(sys_get_temp_dir(), 'dtw');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $z->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('word/document.xml', $doc);
    $z->addFromString('word/styles.xml', $styles);
    $z->close();
    $bytes = (string)file_get_contents($tmp);
    unlink($tmp);
    return $bytes;
}

/** מסמך מתבנית Word (או שנכתבה בכלי) → קובץ Word ממולא. למסמך שהושלם — דף אישור בסוף. */
function doc_docx(array $user, int $id, bool $preview = false): never
{
    $d = doc_owned($id, $user, 'view');
    $a = tpl_asset_row((int)$d['template_version_id']);
    if ($a['mime'] !== DOCX_MIME && $a['mime'] !== AUTHORED_MIME) throw new ApiError('המסמך לא נוצר מתבנית Word', 409);
    $schema = doc_schema($d);
    $names = array_column(attachments_of($id), 'name', 'id');

    $appendix = [];
    if ($d['status'] === 'completed' && $d['final_hash']) {
        $title = (string)unseal($d['title_enc']);
        $appendix = ['דף אישור', "מסמך: $title" . ($d['number'] ? " ({$d['number']})" : ''),
            'נוצר: ' . $d['created_at'] . ' · הושלם: ' . $d['completed_at'],
            'טביעת אצבע (SHA-256) של הערכים: ' . $d['final_hash'], 'יומן:'];
        $st = db()->prepare('SELECT actor, type, at, ip FROM events WHERE document_id = ? ORDER BY id');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $e) $appendix[] = "{$e['at']} · {$e['type']} · " . actor_name($e['actor'], $d) . ($e['ip'] ? " · IP {$e['ip']}" : '');
    }
    $values = doc_values($id);
    $src = $a['mime'] === AUTHORED_MIME ? authored_docx($schema, $values) : asset_bytes($a);
    $bytes = docx_fill($src, $schema, $values, $names, $appendix);
    if (!$preview) log_event($id, 'user:' . $user['id'], 'downloaded', ['format' => 'docx']);

    $name = trim(($d['number'] ? $d['number'] . ' ' : '') . unseal($d['title_enc'])) ?: 'מסמך';
    $name = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', $name) . '.docx';
    header('Content-Type: ' . DOCX_MIME);
    header('Content-Length: ' . strlen($bytes));
    header("Content-Disposition: attachment; filename=\"document.docx\"; filename*=UTF-8''" . rawurlencode($name));
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
}
