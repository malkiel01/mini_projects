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

/** שדות מהסימונים שנמצאו — לפי סדר ההופעה, בלי כפילויות. */
function docx_fields(array $names): array
{
    $fields = [];
    $seen = [];
    foreach ($names as $n) {
        if (isset($seen[$n])) continue;
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
    return match ($f['type']) {
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
    $map = [];
    foreach ($schema['fields'] as $f) {
        if (!empty($f['placeholder'])) $map[$f['placeholder']] = docx_value($f, (string)($values[$f['key']] ?? ''), $attachments);
    }
    return docx_transform($bytes, function (DOMDocument $dom, string $part) use ($map, $appendix) {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', W_NS);
        $changed = false;
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

/** מסמך מתבנית Word → קובץ Word ממולא. למסמך שהושלם — דף אישור בסוף. */
function doc_docx(array $user, int $id, bool $preview = false): never
{
    $d = doc_owned($id, $user, 'view');
    $a = tpl_asset_row((int)$d['template_version_id']);
    if ($a['mime'] !== DOCX_MIME) throw new ApiError('המסמך לא נוצר מתבנית Word', 409);
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
    $bytes = docx_fill(load_file($a['path']), $schema, doc_values($id), $names, $appendix);
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
