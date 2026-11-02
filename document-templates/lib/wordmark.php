<?php
/**
 * סימון שדות בתוך קובץ Word — בלי לחזור ל-Word.
 *
 * מעלים Word רגיל (בלי {{...}}), ובעורך מסמנים טקסט ("______", "ישראל ישראלי", "תאריך")
 * והופכים אותו לשדה: המערכת כותבת במקומו {{שם_השדה}} בתוך הקובץ עצמו, כך שהעיצוב נשאר 1:1,
 * ומוסיפה לתבנית שדה מוגדר. בדיוק כמו שהמשתמש היה מקליד את הסימון ב-Word ומעלה מחדש.
 *
 * פסקה מזוהה ב-[part, p]: קובץ ה-XML (word/document.xml, כותרת, תחתית) ומספר הפסקה בו לפי
 * הסדר של //w:p. היסטים — בתווים (כמו בדפדפן); הטקסט שנבחר נשלח גם הוא, ונבדק מול הקובץ,
 * כך ששינוי שקרה בינתיים לא יחליף טקסט אחר.
 */

declare(strict_types=1);

const WORD_MARK_NAME = '/^[^{}.\r\n]{1,60}$/u';

/** ה-<w:t> של הפסקה עצמה — בלי פסקאות שבתוכה (תיבת טקסט). */
function para_own_texts(DOMXPath $xp, DOMElement $p): array
{
    $out = [];
    foreach (para_texts($xp, $p) as $t) {
        $n = $t->parentNode;
        while ($n && !($n instanceof DOMElement && $n->localName === 'p' && $n->namespaceURI === W_NS)) $n = $n->parentNode;
        if ($n === $p) $out[] = $t;
    }
    return $out;
}

/** פותח docx לקריאה: [part => DOMDocument], גוף המסמך ראשון. */
function docx_doms(string $bytes): array
{
    if (!class_exists('ZipArchive')) throw new ApiError('השרת לא תומך בקובצי ZIP — אי אפשר לעבוד עם Word', 501);
    $tmp = tempnam(sys_get_temp_dir(), 'dx');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { @unlink($tmp); throw new ApiError('קובץ Word פגום', 422); }
    $parts = docx_parts($zip);
    usort($parts, fn($a, $b) => ($b === 'word/document.xml') <=> ($a === 'word/document.xml') ?: strcmp($a, $b));
    $out = [];
    foreach ($parts as $part) {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        if (@$dom->loadXML((string)$zip->getFromName($part), LIBXML_NONET)) $out[$part] = $dom;
    }
    $zip->close();
    @unlink($tmp);
    return $out;
}

/** הפסקאות שיש בהן טקסט, לפי הסדר: [{part, p, text}]. */
function docx_paragraphs(string $bytes): array
{
    $out = [];
    foreach (docx_doms($bytes) as $part => $dom) {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', W_NS);
        foreach ($xp->query('//w:p') as $i => $p) {
            $text = implode('', array_map(fn($t) => $t->textContent, para_own_texts($xp, $p)));
            if (trim($text) !== '') $out[] = ['part' => $part, 'p' => $i, 'text' => $text];
        }
    }
    return $out;
}

/**
 * כותב {{$name}} במקום הטקסט שנבחר. $start, $len — בתווים; $expect — הטקסט שנבחר.
 * העיצוב — של הריצה שבה מתחילה הבחירה (כמו באיחוד הסימונים ב-docx_normalize).
 */
function docx_mark(string $bytes, string $part, int $pi, int $start, int $len, string $expect, string $name): string
{
    if ($len < 1 || $expect === '' || mb_strlen($expect) !== $len) throw new ApiError('לא נבחר טקסט', 422);
    $done = false;
    $out = docx_transform($bytes, function (DOMDocument $dom, string $p) use ($part, $pi, $start, $expect, $name, &$done) {
        if ($p !== $part) return false;
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', W_NS);
        $para = $xp->query('//w:p')->item($pi);
        if (!$para instanceof DOMElement) throw new ApiError('הפסקה לא נמצאה בקובץ — רעננו את הדף', 409);
        $ts = para_own_texts($xp, $para);
        $full = '';
        $starts = [];
        foreach ($ts as $i => $t) { $starts[$i] = strlen($full); $full .= $t->textContent; }
        $off = strlen(mb_substr($full, 0, $start));
        $end = $off + strlen($expect);
        if (substr($full, $off, strlen($expect)) !== $expect) throw new ApiError('הטקסט בקובץ השתנה — רעננו את הדף ובחרו שוב', 409);
        // לא חותכים סימון קיים
        if (preg_match_all(PLACEHOLDER, $full, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$ph, $o]) {
                if ($off < $o + strlen($ph) && $end > $o) throw new ApiError('הבחירה נוגעת בשדה שכבר מסומן', 422);
            }
        }
        $si = $ei = null;
        foreach ($ts as $i => $t) {
            $a = $starts[$i];
            $b = $a + strlen($t->textContent);
            if ($si === null && $off >= $a && $off < $b) $si = $i;
            if ($end > $a && $end <= $b) { $ei = $i; break; }
        }
        if ($si === null || $ei === null) throw new ApiError('לא הצלחתי למצוא את הטקסט בקובץ', 409);
        $before = substr($ts[$si]->textContent, 0, $off - $starts[$si]);
        $after = substr($ts[$ei]->textContent, $end - $starts[$ei]);
        if ($si === $ei) {
            set_text($ts[$si], $before . '{{' . $name . '}}' . $after);
        } else {
            set_text($ts[$si], $before . '{{' . $name . '}}');
            for ($i = $si + 1; $i < $ei; $i++) set_text($ts[$i], '');
            set_text($ts[$ei], $after);
        }
        $done = true;
    });
    if (!$done) throw new ApiError('הפסקה לא נמצאה בקובץ — רעננו את הדף', 409);
    return $out;
}

/** שם לשדה: מה שהמשתמש כתב, רווחים → "_" (כמו בסימון שמקלידים ב-Word). */
function word_mark_name(string $label): string
{
    $label = trim(preg_replace('/\s+/u', ' ', $label));
    $name = str_replace(' ', '_', $label);
    if (!preg_match(WORD_MARK_NAME, $name)) throw new ApiError('שם השדה: עד 60 תווים, בלי { } ובלי נקודה', 422);
    return $name;
}

function tpl_word_text(array $user, int $id): array
{
    $t = tpl_owned($id, $user, 'view');
    $a = tpl_asset_row((int)$t['version_id']);
    if ($a['mime'] !== DOCX_MIME) throw new ApiError('רק בתבנית Word', 422);
    return ['ok' => true, 'paragraphs' => array_slice(docx_paragraphs(asset_bytes($a)), 0, 3000)];
}

/**
 * מסמן שדה בקובץ: הקובץ החדש מחליף את הקודם, והשדה נוסף להגדרות. תבנית שכבר הונפקו
 * ממנה מסמכים — גרסה חדשה (המסמכים הקיימים נשארים על הקובץ הקודם), כמו בשמירה.
 */
function tpl_word_mark(array $user, array $b): array
{
    $t = tpl_owned((int)($b['id'] ?? 0), $user, 'edit');
    $a = tpl_asset_row((int)$t['version_id']);
    if ($a['mime'] !== DOCX_MIME) throw new ApiError('רק בתבנית Word', 422);
    $name = word_mark_name((string)($b['name'] ?? ''));
    $bytes = docx_mark(asset_bytes($a), (string)($b['part'] ?? ''), (int)($b['p'] ?? -1), (int)($b['start'] ?? -1),
        (int)($b['len'] ?? 0), (string)($b['text'] ?? ''), $name);

    // השדה החדש — כמו מסימון שהוקלד ב-Word; שדה שכבר קיים בשם הזה (אותו שדה בעוד מקום) — נשאר כמו שהוא
    $schema = json_decode((string)unseal($t['schema_enc']), true) ?: [];
    $schema['pages'] = $schema['pages'] ?: [['w' => 595, 'h' => 842]];
    $schema['fields'] ??= [];
    $added = false;
    if (!in_array($name, array_column($schema['fields'], 'placeholder'), true)) {
        $f = docx_fields([$name])[0];
        $n = 1;
        $keys = array_column($schema['fields'], 'key');
        $ids = array_column($schema['fields'], 'id');
        while (in_array('f' . $n, $keys, true) || in_array('f' . $n, $ids, true)) $n++;
        $f['key'] = $f['id'] = 'f' . $n;
        $f['orig'] = mb_substr((string)($b['text'] ?? ''), 0, 500);   // ביטול סימון מחזיר אותו
        $schema['fields'][] = $f;
        $added = true;
    }
    $json = json_encode($schema, JSON_UNESCAPED_UNICODE);
    if (strlen($json) > TPL_MAX_SCHEMA) throw new ApiError('התבנית גדולה מדי', 413);

    $version = tpl_replace_asset($t, $a, $bytes, $json);
    wp_after_local_change($t, $bytes);
    return ['ok' => true, 'name' => $name, 'added' => $added, 'version' => $version];
}

/** כל {{name}} בקובץ (בכל החלקים) → $with. מחזיר [הקובץ, כמה הוחלפו]. */
function docx_replace_ph(string $bytes, string $name, string $with): array
{
    $n = 0;
    $out = docx_transform(docx_normalize($bytes), function (DOMDocument $dom) use ($name, $with, &$n) {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', W_NS);
        $hit = false;
        foreach ($xp->query('//w:t') as $t) {
            $text = $t->textContent;
            if (!str_contains($text, '{{')) continue;
            $new = preg_replace_callback(PLACEHOLDER, function ($m) use ($name, $with, &$n, &$hit) {
                if (trim($m[1]) !== $name) return $m[0];
                $n++; $hit = true;
                return $with;
            }, $text);
            if ($new !== $text) set_text($t, $new);
        }
        return $hit;
    });
    return [$out, $n];
}

/** השדה של סימון בתבנית Word: [התבנית, הקובץ, ההגדרות, מספר השדה ברשימה]. */
function word_field(array $user, array $b): array
{
    $t = tpl_owned((int)($b['id'] ?? 0), $user, 'edit');
    $a = tpl_asset_row((int)$t['version_id']);
    if ($a['mime'] !== DOCX_MIME) throw new ApiError('רק בתבנית Word', 422);
    $schema = json_decode((string)unseal($t['schema_enc']), true) ?: [];
    $schema['pages'] = $schema['pages'] ?: [['w' => 595, 'h' => 842]];
    $name = trim((string)($b['name'] ?? ''));
    $i = array_search($name, array_column($schema['fields'] ?? [], 'placeholder'), true);
    if ($i === false) throw new ApiError('השדה לא נמצא בתבנית — רעננו את הדף', 404);
    return [$t, $a, $schema, $i, $name];
}

/**
 * ביטול סימון (15.5): {{שם}} יוצא מהקובץ — במקומו חוזר הטקסט שהיה לפני הסימון (או קו "______" לסימון
 * שהוקלד ב-Word), והשדה יוצא מההגדרות. בתבנית שהונפקו ממנה מסמכים — גרסה חדשה.
 */
function tpl_word_unmark(array $user, array $b): array
{
    [$t, $a, $schema, $i, $name] = word_field($user, $b);
    $orig = (string)($schema['fields'][$i]['orig'] ?? '');
    [$bytes, $n] = docx_replace_ph(asset_bytes($a), $name, $orig !== '' ? $orig : '______');
    array_splice($schema['fields'], $i, 1);
    $json = json_encode($schema, JSON_UNESCAPED_UNICODE);
    $version = tpl_replace_asset($t, $a, $bytes, $json);
    wp_after_local_change($t, $bytes);
    return ['ok' => true, 'restored' => $orig !== '' ? $orig : '______', 'places' => $n, 'version' => $version];
}

/** שינוי שם של סימון: {{ישן}} → {{חדש}} בכל מקום בקובץ, והתווית מתעדכנת. ההגדרות האחרות נשארות. */
function tpl_word_rename(array $user, array $b): array
{
    [$t, $a, $schema, $i, $name] = word_field($user, $b);
    $to = word_mark_name((string)($b['to'] ?? ''));
    if ($to === $name) return ['ok' => true, 'changed' => false];
    if (in_array($to, array_column($schema['fields'], 'placeholder'), true)) throw new ApiError('כבר יש שדה בשם הזה', 409);
    [$bytes, $n] = docx_replace_ph(asset_bytes($a), $name, '{{' . $to . '}}');
    $schema['fields'][$i]['placeholder'] = $to;
    $schema['fields'][$i]['label'] = str_replace('_', ' ', $to);
    $json = json_encode($schema, JSON_UNESCAPED_UNICODE);
    $version = tpl_replace_asset($t, $a, $bytes, $json);
    wp_after_local_change($t, $bytes);
    return ['ok' => true, 'changed' => true, 'places' => $n, 'version' => $version];
}
