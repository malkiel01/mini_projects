<?php
// מודל המסמך — בצורת document.json של ‎.mbe (mbe-format.md סעיף 3), כדי שהייצוא יהיה העתקה ולא תרגום.
//
//   units, color          — כמו במעטפת
//   layout                — הגדרות העימוד (שלנו; נשמר במעטפת כשדה לא מוכר לאחרים):
//     facing   bool       — עמודים כפולים
//     binding  right|left — צד הכריכה. ספר עברי — right: עמוד 1 משמאל לשדרה, ועמודים זוגיים מימין
//     margins  {top, bottom, inside, outside} ב-pt. inside = הצד של השדרה
//     numbering {start, style: arabic|hebrew} — מספור העמודים: מאיזה מספר, ובספרות או באותיות (א, ב… טו, טז)
//   surfaces              — עמוד = {id, type:"page", size:[w,h], master:<id של עמוד אב> | null, layers:[]}
//                           עמוד אב = {id, type:"master", name, size, layers:[], running:{folio, header}}
//     running.folio  {position: top|bottom, align: outside|center|inside, size} | null — מספר העמוד
//     running.header {odd, even, position, align, size} | null — כותרת רצה (טקסט לעמוד אי-זוגי / זוגי)
//   flows                 — שטפי טקסט (בשלב המסגרות)
//
// השרת לא בונה את המודל מחדש — הוא בודק את מה שהוא מבין ושומר את השאר כמו שהוא.

require_once __DIR__ . '/docs.php';

const LS_MAX_PAGES = 2000;
const LS_MODEL_MAX_BYTES = 8 * 1024 * 1024;

function ls_default_margins(): array
{
    return ['top' => ls_mm_to_pt(15), 'bottom' => ls_mm_to_pt(20), 'inside' => ls_mm_to_pt(20), 'outside' => ls_mm_to_pt(15)];
}

function ls_page_id(): string
{
    return 'p' . bin2hex(random_bytes(6));
}

/** עמוד האב שכל מסמך חדש מקבל: "A", עם מספר עמוד בתחתית, בצד החיצוני. */
function ls_master_new(float $w, float $h): array
{
    return ['id' => 'mA', 'type' => 'master', 'name' => 'A', 'size' => [$w, $h], 'layers' => [],
        'running' => ['folio' => ['position' => 'bottom', 'align' => 'outside', 'size' => 10], 'header' => null]];
}

function ls_model_new(float $w, float $h, int $pages = 4): array
{
    $surfaces = [];
    for ($i = 0; $i < $pages; $i++) {
        $surfaces[] = ['id' => ls_page_id(), 'type' => 'page', 'size' => [$w, $h], 'master' => 'mA', 'layers' => []];
    }
    $surfaces[] = ls_master_new($w, $h);
    return [
        'units' => 'pt',
        'color' => ['space' => 'sRGB'],
        'layout' => ['facing' => true, 'binding' => 'right', 'margins' => ls_default_margins(),
            'numbering' => ['start' => 1, 'style' => 'arabic']],
        'surfaces' => $surfaces,
        'flows' => new stdClass(),
    ];
}

/**
 * בדיקת מודל שהגיע מהדפדפן. מחזיר את גודל העמוד [w, h] (לרשימה), או זורק InvalidArgumentException('bad_model:<מה>').
 * גרסה 1: כל העמודים באותו גודל.
 */
function ls_model_check($m, ?callable $mediaExists = null): array
{
    $bad = fn(string $what) => new InvalidArgumentException('bad_model:' . $what);
    if (!is_array($m)) throw $bad('root');
    if (($m['units'] ?? 'pt') !== 'pt') throw $bad('units');

    $L = $m['layout'] ?? null;
    if (!is_array($L)) throw $bad('layout');
    if (!is_bool($L['facing'] ?? null)) throw $bad('facing');
    if (!in_array($L['binding'] ?? null, ['right', 'left'], true)) throw $bad('binding');
    $mg = $L['margins'] ?? null;
    if (!is_array($mg)) throw $bad('margins');
    foreach (['top', 'bottom', 'inside', 'outside'] as $k) {
        if (!is_int($mg[$k] ?? null) && !is_float($mg[$k] ?? null)) throw $bad('margins');
        if ($mg[$k] < 0) throw $bad('margins');
    }

    $nb = $L['numbering'] ?? null;   // רשות — מסמך מלפני עמודי האב אין לו
    if ($nb !== null) {
        if (!is_array($nb) || !is_int($nb['start'] ?? null) || $nb['start'] < 1 || $nb['start'] > 99999
            || !in_array($nb['style'] ?? null, ['arabic', 'hebrew'], true)) throw $bad('numbering');
    }

    $S = $m['surfaces'] ?? null;
    if (!is_array($S) || !array_is_list($S) || count($S) < 1 || count($S) > LS_MAX_PAGES) throw $bad('pages');
    $size = null;
    $ids = [];
    $masters = [];
    $refs = [];
    $frames = [];   // מזהה מסגרת → השטף שלה
    foreach ($S as $s) {
        if (!is_array($s)) throw $bad('page');
        $id = $s['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $id) || isset($ids[$id])) throw $bad('page_id');
        $ids[$id] = true;
        if (($s['type'] ?? null) === 'master') { ls_master_check($s, $bad); $masters[$id] = true; continue; }
        if (($s['type'] ?? null) !== 'page') continue;   // משטח מסוג אחר — נשמר כמו שהוא
        if (($s['master'] ?? null) !== null) $refs[] = $s['master'];
        $sz = $s['size'] ?? null;
        if (!is_array($sz) || count($sz) !== 2) throw $bad('size');
        foreach ($sz as $v) {
            if ((!is_int($v) && !is_float($v)) || $v < LS_PAGE_MIN || $v > LS_PAGE_MAX) throw $bad('size');
        }
        if ($size === null) $size = [(float)$sz[0], (float)$sz[1]];
        elseif (abs($size[0] - $sz[0]) > 0.01 || abs($size[1] - $sz[1]) > 0.01) throw $bad('mixed_size');
        $layers = $s['layers'] ?? [];
        if (!is_array($layers) || !array_is_list($layers)) throw $bad('layers');
        foreach ($layers as $l) {
            if (!is_array($l)) throw $bad('layer');
            $lid = $l['id'] ?? null;
            if (!is_string($lid) || !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $lid) || isset($ids[$lid])) throw $bad('layer_id');
            $ids[$lid] = true;   // מזהים ייחודיים בכל המסמך — משטחים ושכבות יחד
            if (($l['type'] ?? null) === 'frame') {
                ls_frame_check($l, $bad, $mediaExists);
                if (array_key_exists('flow', $l)) $frames[$lid] = $l['flow'];
            }
        }
    }
    ls_flows_check($m['flows'] ?? null, $frames, $bad);
    ls_style_check($m['styles'] ?? null, $bad);
    if ($size === null) throw $bad('pages');
    foreach ($refs as $r) {
        if (!is_string($r) || !isset($masters[$r])) throw $bad('master_ref');   // עמוד שמפנה לעמוד אב שלא קיים
    }
    // השוליים משאירים לפחות 1 ס"מ לתוכן — אחרת אין איפה לשים טקסט
    $minBody = ls_mm_to_pt(10);
    if ($mg['inside'] + $mg['outside'] > $size[0] - $minBody || $mg['top'] + $mg['bottom'] > $size[1] - $minBody) throw $bad('margins');
    return $size;
}

function ls_master_check(array $s, callable $bad): void
{
    $name = $s['name'] ?? '';
    if (!is_string($name) || mb_strlen($name) > 40) throw $bad('master');
    $run = $s['running'] ?? null;
    if ($run === null) return;
    if (!is_array($run)) throw $bad('running');
    $place = function ($o) use ($bad) {
        if (!in_array($o['position'] ?? null, ['top', 'bottom'], true)) throw $bad('running');
        if (!in_array($o['align'] ?? null, ['outside', 'center', 'inside'], true)) throw $bad('running');
        $sz = $o['size'] ?? null;
        if ((!is_int($sz) && !is_float($sz)) || $sz < 4 || $sz > 72) throw $bad('running');
    };
    $f = $run['folio'] ?? null;
    if ($f !== null) { if (!is_array($f)) throw $bad('running'); $place($f); }
    $h = $run['header'] ?? null;
    if ($h !== null) {
        if (!is_array($h)) throw $bad('running');
        $place($h);
        foreach (['odd', 'even'] as $k) {
            if (!is_string($h[$k] ?? null) || mb_strlen($h[$k]) > 200) throw $bad('running');
        }
    }
}

/**
 * מסגרת: box — 4 מספרים, רוחב וגובה חיוביים. טקסט (flow): טורים 1–12. תמונה (src): media/<sha256>.<ext> שקיים,
 * px — גודל התמונה בפיקסלים, fit (contain / cover), focus [0–1, 0–1], zoom 1–10; גלישת טקסט (wrap: none / box, wrapGap).
 */
function ls_frame_check(array $l, callable $bad, ?callable $mediaExists = null): void
{
    $num = fn($v, $min, $max) => (is_int($v) || is_float($v)) && $v >= $min && $v <= $max;
    if (array_key_exists('wrap', $l) && !in_array($l['wrap'], ['none', 'box'], true)) throw $bad('frame');
    if (array_key_exists('wrapGap', $l) && !$num($l['wrapGap'], 0, 500)) throw $bad('frame');
    if (array_key_exists('src', $l)) {
        if (!is_string($l['src']) || !preg_match('#^media/[0-9a-f]{64}\.(png|jpg|webp)$#', $l['src'])) throw $bad('image');
        if ($mediaExists && !$mediaExists($l['src'])) throw $bad('image_missing');
        $px = $l['px'] ?? null;
        if (!is_array($px) || count($px) !== 2 || !is_int($px[0]) || !is_int($px[1]) || $px[0] < 1 || $px[1] < 1) throw $bad('image');
        if (!in_array($l['fit'] ?? 'contain', ['contain', 'cover'], true)) throw $bad('image');
        $fo = $l['focus'] ?? [0.5, 0.5];
        if (!is_array($fo) || count($fo) !== 2 || !$num($fo[0], 0, 1) || !$num($fo[1], 0, 1)) throw $bad('image');
        if (!$num($l['zoom'] ?? 1, 1, 10)) throw $bad('image');
    }
    $b = $l['box'] ?? null;
    if (!is_array($b) || !array_is_list($b) || count($b) !== 4) throw $bad('frame');
    foreach ($b as $v) if (!is_int($v) && !is_float($v)) throw $bad('frame');
    if ($b[2] <= 0 || $b[3] <= 0) throw $bad('frame');
    $c = $l['columns'] ?? 1;
    if (!is_int($c) || $c < 1 || $c > 12) throw $bad('frame');
    $g = $l['gutter'] ?? 0;
    if ((!is_int($g) && !is_float($g)) || $g < 0) throw $bad('frame');
    if (array_key_exists('flow', $l) && !is_string($l['flow'])) throw $bad('frame');
}

/**
 * שטפים: מסגרת שמפנה לשטף — השטף קיים (כלל של הליבה). שטף שלנו (frames) — כל מסגרת בשרשור קיימת, שייכת אליו
 * ומופיעה פעם אחת.
 */
function ls_flows_check($flows, array $frames, callable $bad): void
{
    if ($flows === null) $flows = [];
    if (!is_array($flows)) throw $bad('flows');
    foreach ($frames as $fid => $flow) {
        if ($flow !== null && !isset($flows[$flow])) throw $bad('flow_ref');
    }
    foreach ($flows as $id => $f) {
        if (!is_array($f) || !is_string($f['engine'] ?? null)) throw $bad('flow');
        if (array_key_exists('doc', $f) && !is_array($f['doc'])) throw $bad('flow');
        if (!array_key_exists('frames', $f)) continue;   // שטף של אפליקציה אחרת — נשמר כמו שהוא
        if (!is_array($f['frames']) || !array_is_list($f['frames'])) throw $bad('thread');
        $seen = [];
        foreach ($f['frames'] as $fid) {
            if (!is_string($fid) || !array_key_exists($fid, $frames) || $frames[$fid] !== (string)$id || isset($seen[$fid])) throw $bad('thread');
            $seen[$fid] = true;
        }
        if (array_key_exists('autogrow', $f) && !is_bool($f['autogrow'])) throw $bad('flow');
    }
}

/**
 * סגנונות הפסקה שלנו (body, h1–h3) — אם קיימים. סגנון בשם אחר — של אפליקציה אחרת, נשמר כמו שהוא.
 * font, size, leading, align, spaceAfter — חובה; name, weight, spaceBefore, indent — רשות (נוספו בשלב הסגנונות).
 */
function ls_style_check($styles, callable $bad): void
{
    if ($styles === null) return;
    if (!is_array($styles)) throw $bad('styles');
    $num = fn($v, $min, $max) => (is_int($v) || is_float($v)) && $v >= $min && $v <= $max;
    foreach (['body', 'h1', 'h2', 'h3'] as $key) {
        $b = $styles[$key] ?? null;
        if ($b === null) continue;
        if (!is_array($b)) throw $bad('style');
        if (!is_string($b['font'] ?? null) || mb_strlen($b['font']) > 100) throw $bad('style');
        if (!$num($b['size'] ?? null, 4, 200) || !$num($b['leading'] ?? null, 4, 400)) throw $bad('style');
        if (!in_array($b['align'] ?? null, ['justify', 'right', 'center', 'left'], true)) throw $bad('style');
        if (!$num($b['spaceAfter'] ?? null, 0, 200)) throw $bad('style');
        if (array_key_exists('name', $b) && (!is_string($b['name']) || mb_strlen($b['name']) > 40)) throw $bad('style');
        if (array_key_exists('weight', $b) && !$num($b['weight'], 100, 900)) throw $bad('style');
        if (array_key_exists('spaceBefore', $b) && !$num($b['spaceBefore'], 0, 200)) throw $bad('style');
        if (array_key_exists('indent', $b) && !$num($b['indent'], 0, 500)) throw $bad('style');
    }
}
