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
        $schema['fields'][] = $f;
        $added = true;
    }
    $json = json_encode($schema, JSON_UNESCAPED_UNICODE);
    if (strlen($json) > TPL_MAX_SCHEMA) throw new ApiError('התבנית גדולה מדי', 413);

    if (function_exists('assert_room')) assert_room((int)$t['owner_id'], strlen($bytes));
    $path = store_file($bytes);
    $old = null;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $version = (int)$t['current_version'];
        if (!$t['used']) {
            $old = (string)$a['path'];
            $pdo->prepare('UPDATE template_versions SET schema_enc = ? WHERE id = ?')->execute([seal($json), $t['version_id']]);
            $pdo->prepare('UPDATE template_assets SET path = ?, size = ?, sha256 = ? WHERE template_version_id = ?')
                ->execute([$path, strlen($bytes), hash('sha256', $bytes), $t['version_id']]);
        } else {
            $version++;
            $pdo->prepare('INSERT INTO template_versions (template_id, version, schema_enc, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$t['id'], $version, seal($json), now_iso()]);
            $pdo->prepare('INSERT INTO template_assets (template_version_id, path, mime, size, sha256) VALUES (?, ?, ?, ?, ?)')
                ->execute([(int)$pdo->lastInsertId(), $path, DOCX_MIME, strlen($bytes), hash('sha256', $bytes)]);
            $pdo->prepare('UPDATE templates SET current_version = ? WHERE id = ?')->execute([$version, $t['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        delete_file($path);
        throw $e;
    }
    // הקובץ הקודם — נמחק רק אם אף גרסה אחרת לא מצביעה עליו
    if ($old !== null && $old !== $path) {
        $st = db()->prepare('SELECT COUNT(*) FROM template_assets WHERE path = ?');
        $st->execute([$old]);
        if (!(int)$st->fetchColumn()) delete_file($old);
    }
    return ['ok' => true, 'name' => $name, 'added' => $added, 'version' => $version];
}
