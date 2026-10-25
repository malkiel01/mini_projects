<?php
/**
 * פתיחה של ‎.mbe (creative-suite/docs/mbe-format.md) — מאותו כפתור ייבוא (import_file מזהה לפי החתימה בהיסט 30).
 * נערך כאן השטף (flows) שהמסגרת הראשונה בעמוד הראשון מפנה אליו — ה-JSON של ProseMirror, עם הגדרות העמוד שבתוכו
 * (attrs.page) או, בקובץ מאפליקציה אחרת, מ-layout ומגודל המשטח. משאבי media/ — לאחסון שלנו.
 *
 * מה שלא מובן כאן (משטחים ושכבות אחרים, שטפים נוספים, kind אחר) — מוצג כתמונה (renditions/preview.png) בראש המסמך,
 * והדוח אומר. הקובץ המקורי נשמר ליד המסמך (mbe_source_path), ובהורדה כ-‎.mbe מתחלף בו רק השטף — כל השאר בית-לבית
 * (lib/mbe_export.php). התמונה שהוספנו לא נכנסת לשטף.
 */

require_once __DIR__ . '/mbe/mbe.php';

/** חתימת ‎.mbe: ZIP שהקובץ הראשון בו הוא mimetype לא דחוס עם application/vnd.mbe+zip. */
function is_mbe(string $bytes): bool
{
    return substr($bytes, 30, 31) === 'mimetype' . Mbe::MIMETYPE;
}

function import_mbe(string $bytes, string $docId): array
{
    $f = Mbe::open($bytes);
    if (!$f->ok()) {
        $e = array_values(array_filter($f->issues, fn($i) => $i['level'] === 'error'))[0] ?? ['message' => ''];
        throw new ApiError('קובץ ‎.mbe פגום: ' . $e['message'], 'bad_file');
    }
    $d = json_decode(json_encode($f->document, JSON_UNESCAPED_UNICODE), true) ?: [];
    $flows = is_array($d['flows'] ?? null) ? $d['flows'] : [];
    $surfaces = is_array($d['surfaces'] ?? null) ? $d['surfaces'] : [];
    $isPm = fn($id) => is_string($id) && ($flows[$id]['engine'] ?? '') === 'prosemirror' && is_array($flows[$id]['doc'] ?? null);
    // השטף: של המסגרת הראשונה בעמוד הראשון (בכל עומק של קבוצות), אחרת כל שטף של ProseMirror
    $flow = null;
    $frames = [];
    $walk = function (array $layers) use (&$walk, &$frames) {
        foreach ($layers as $l) {
            if (!is_array($l)) continue;
            if (($l['type'] ?? '') === 'frame' && isset($l['flow'])) $frames[] = $l['flow'];
            if (is_array($l['children'] ?? null)) $walk($l['children']);
        }
    };
    $pages = array_values(array_filter($surfaces, fn($s) => is_array($s) && ($s['type'] ?? '') === 'page'));
    foreach ($pages as $s) $walk(is_array($s['layers'] ?? null) ? $s['layers'] : []);
    foreach ($frames as $id) if ($isPm($id)) { $flow = $id; break; }
    if ($flow === null) foreach (array_keys($flows) as $id) if ($isPm((string)$id)) { $flow = (string)$id; break; }

    // מה שלא נערך כאן: יותר ממשטח אחד, שכבה שאינה המסגרת של השטף, שטפים נוספים, או בלי שטף בכלל
    $layers = is_array($pages[0]['layers'] ?? null) ? $pages[0]['layers'] : [];
    $foreign = $flow === null || count($surfaces) !== 1 || count($pages) !== 1 || count($flows) !== 1
        || count($layers) !== 1 || ($layers[0]['type'] ?? '') !== 'frame' || ($layers[0]['flow'] ?? null) !== $flow;

    $report = [];
    $missing = 0;
    $json = $flow !== null ? $flows[$flow]['doc'] : ['type' => 'doc', 'content' => []];
    // משאבים: media/<sha256> → האחסון שלנו (בכל עומק — גם בכותרות שבתוך attrs.page)
    $media = function ($v) use (&$media, $f, $docId, &$missing) {
        if (!is_array($v)) return $v;
        foreach ($v as $k => $c) $v[$k] = $media($c);
        if (($v['type'] ?? '') === 'image' && is_array($v['attrs'] ?? null) && is_string($v['attrs']['src'] ?? null)) {
            $bytes = $f->getMedia($v['attrs']['src']);
            unset($v['attrs']['src']);
            try { if ($bytes === null) throw new ApiError('', 'bad_file'); $v['attrs']['media'] = media_store($docId, $bytes)['id']; }
            catch (ApiError $e) { $missing++; }
        }
        return $v;
    };
    $json = $media($json);
    $page = null;
    if (is_array($json['attrs']['page'] ?? null)) $page = page_normalize($json['attrs']['page']);
    elseif ($pages) $page = mbe_page_of($pages[0], is_array($d['layout'] ?? null) ? $d['layout'] : [], is_array($d['styles'] ?? null) ? $d['styles'] : []);
    unset($json['attrs']['page']);
    if (empty($json['attrs'])) unset($json['attrs']);
    $json['type'] = 'doc';
    if (!is_array($json['content'] ?? null) || !$json['content']) $json['content'] = [['type' => 'paragraph']];

    $meta = ['flow' => $flow ?? mbe_new_flow_id($flows), 'foreign' => []];
    if ($foreign) {
        $prev = null;
        foreach ($f->renditions() as $r) if ($r['path'] === Mbe::PREVIEW) $prev = $r['data'];
        $mid = null;
        if ($prev !== null) { try { $mid = media_store($docId, $prev); } catch (ApiError $e) { $mid = null; } }
        if ($mid) {
            $meta['foreign'][] = $mid['id'];
            $w = min(500, (int)$mid['width']);
            array_unshift($json['content'], ['type' => 'paragraph', 'attrs' => ['textAlign' => 'center'], 'content' => [
                ['type' => 'image', 'attrs' => ['media' => $mid['id'], 'width' => $w, 'height' => (int)round($w * $mid['height'] / max(1, $mid['width'])),
                    'alt' => 'תוכן מאפליקציה אחרת בחבילה — לא נערך כאן', 'wrap' => 'inline']]]]);
        }
        $report[] = ['key' => 'mbe_foreign', 'label' => 'תוכן מאפליקציה אחרת (' . ($f->manifest['modified']['app'] ?? $f->manifest['created']['app'] ?? 'לא ידוע') . ')',
            'count' => max(1, count($surfaces) + count($flows) - 1),
            'detail' => ($flow === null ? 'אין בקובץ טקסט לעריכה. ' : '') . 'מוצג כתמונה בראש המסמך, ונשמר כמו שהוא בהורדה כ-‎.mbe'];
    }
    if ($missing) $report[] = ['key' => 'images', 'label' => 'תמונות שלא נטענו', 'count' => $missing, 'detail' => 'חסרות בקובץ או בפורמט שאינו נתמך'];
    file_put_contents(mbe_source_path($docId), $bytes);
    file_put_contents(mbe_source_path($docId) . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE));
    return ['json' => $json, 'page' => $page, 'report' => $report];
}

/** הגדרות עמוד ממשטח של אפליקציה אחרת: הגודל (A4 / Letter, לאורך / לרוחב — הקרוב), השוליים מ-layout או מהמסגרת. */
function mbe_page_of(array $surface, array $layout, array $styles = []): array
{
    $mm = fn($pt) => round((float)$pt / PT_PER_MM, 1);
    [$w, $h] = array_map($mm, is_array($surface['size'] ?? null) ? $surface['size'] : [595.3, 841.9]);
    $best = ['A4', 'portrait'];
    $err = INF;
    foreach (PAGE_SIZES as $name => [$a, $b]) {
        foreach (['portrait' => [$a, $b], 'landscape' => [$b, $a]] as $o => [$x, $y]) {
            $e = abs($x - $w) + abs($y - $h);
            if ($e < $err) { $err = $e; $best = [$name, $o]; }
        }
    }
    $p = ['size' => $best[0], 'orientation' => $best[1]];
    $m = is_array($layout['margins'] ?? null) ? $layout['margins'] : [];
    if ($m) {
        $p['margins'] = ['top' => $mm($m['top'] ?? 70.9), 'bottom' => $mm($m['bottom'] ?? 70.9), 'right' => $mm($m['inside'] ?? 70.9), 'left' => $mm($m['outside'] ?? 70.9)];
    } else {
        foreach ($surface['layers'] ?? [] as $l) {
            if (($l['type'] ?? '') !== 'frame' || !is_array($l['box'] ?? null) || count($l['box']) !== 4) continue;
            [$x, $y, $bw, $bh] = array_map('floatval', $l['box']);
            $sw = (float)($surface['size'][0] ?? 595.3);
            $sh = (float)($surface['size'][1] ?? 841.9);
            $p['margins'] = ['top' => $mm($y), 'bottom' => $mm(max(0, $sh - $y - $bh)), 'left' => $mm($x), 'right' => $mm(max(0, $sw - $x - $bw))];
            break;
        }
    }
    if (($layout['numbering']['style'] ?? '') === 'hebrew') $p['numbering'] = ['format' => 'hebrew'];
    // סגנונות הפסקה המשותפים (mbe-format.md סעיף 3): body → normal וגופן העמוד, h1–h3 → הכותרות. ערך לא תקין — נשאר ברירת המחדל
    foreach (['body' => 'normal', 'h1' => 'h1', 'h2' => 'h2', 'h3' => 'h3'] as $k => $ours) {
        $s = is_array($styles[$k] ?? null) ? $styles[$k] : null;
        if (!$s) continue;
        $o = [];
        if (is_numeric($s['size'] ?? null)) $o['size'] = (float)$s['size'];
        if (is_numeric($s['spaceBefore'] ?? null)) $o['before'] = (float)$s['spaceBefore'];
        if (is_numeric($s['spaceAfter'] ?? null)) $o['after'] = (float)$s['spaceAfter'];
        if (is_string($s['align'] ?? null)) $o['align'] = $s['align'];
        if (is_numeric($s['weight'] ?? null)) $o['bold'] = (float)$s['weight'] >= 600;
        if ($k === 'body') {
            if (is_numeric($s['leading'] ?? null) && (float)($s['size'] ?? 0) > 0) $o['line'] = round((float)$s['leading'] / (float)$s['size'], 2);
            if (is_string($s['font'] ?? null)) $p['font']['family'] = $s['font'];
            if (isset($o['size'])) $p['font']['size'] = $o['size'];
            unset($o['size']);
        } elseif (is_string($s['font'] ?? null)) $o['font'] = $s['font'];
        $p['styles'][$ours] = $o;
    }
    return page_normalize($p);
}

/** מזהה לשטף שלנו כשאין בקובץ שטף של ProseMirror — שלא תופס מזהה קיים. */
function mbe_new_flow_id(array $flows): string
{
    for ($i = 1; isset($flows["wp$i"]); $i++);
    return "wp$i";
}
