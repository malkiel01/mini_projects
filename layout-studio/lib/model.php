<?php
// מודל המסמך — בצורת document.json של ‎.mbe (mbe-format.md סעיף 3), כדי שהייצוא יהיה העתקה ולא תרגום.
//
//   units, color          — כמו במעטפת
//   layout                — הגדרות העימוד (שלנו; נשמר במעטפת כשדה לא מוכר לאחרים):
//     facing   bool       — עמודים כפולים
//     binding  right|left — צד הכריכה. ספר עברי — right: עמוד 1 משמאל לשדרה, ועמודים זוגיים מימין
//     margins  {top, bottom, inside, outside} ב-pt. inside = הצד של השדרה
//   surfaces              — עמוד = {id, type:"page", size:[w,h], master:null, layers:[]}
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

function ls_model_new(float $w, float $h, int $pages = 4): array
{
    $surfaces = [];
    for ($i = 0; $i < $pages; $i++) {
        $surfaces[] = ['id' => ls_page_id(), 'type' => 'page', 'size' => [$w, $h], 'master' => null, 'layers' => []];
    }
    return [
        'units' => 'pt',
        'color' => ['space' => 'sRGB'],
        'layout' => ['facing' => true, 'binding' => 'right', 'margins' => ls_default_margins()],
        'surfaces' => $surfaces,
        'flows' => new stdClass(),
    ];
}

/**
 * בדיקת מודל שהגיע מהדפדפן. מחזיר את גודל העמוד [w, h] (לרשימה), או זורק InvalidArgumentException('bad_model:<מה>').
 * גרסה 1: כל העמודים באותו גודל.
 */
function ls_model_check($m): array
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

    $S = $m['surfaces'] ?? null;
    if (!is_array($S) || !array_is_list($S) || count($S) < 1 || count($S) > LS_MAX_PAGES) throw $bad('pages');
    $size = null;
    $ids = [];
    foreach ($S as $s) {
        if (!is_array($s)) throw $bad('page');
        $id = $s['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $id) || isset($ids[$id])) throw $bad('page_id');
        $ids[$id] = true;
        if (($s['type'] ?? null) !== 'page') continue;   // משטח מסוג אחר (למשל עמוד אב, בהמשך) — נשמר כמו שהוא
        $sz = $s['size'] ?? null;
        if (!is_array($sz) || count($sz) !== 2) throw $bad('size');
        foreach ($sz as $v) {
            if ((!is_int($v) && !is_float($v)) || $v < LS_PAGE_MIN || $v > LS_PAGE_MAX) throw $bad('size');
        }
        if ($size === null) $size = [(float)$sz[0], (float)$sz[1]];
        elseif (abs($size[0] - $sz[0]) > 0.01 || abs($size[1] - $sz[1]) > 0.01) throw $bad('mixed_size');
    }
    if ($size === null) throw $bad('pages');
    // השוליים משאירים לפחות 1 ס"מ לתוכן — אחרת אין איפה לשים טקסט
    $minBody = ls_mm_to_pt(10);
    if ($mg['inside'] + $mg['outside'] > $size[0] - $minBody || $mg['top'] + $mg['bottom'] > $size[1] - $minBody) throw $bad('margins');
    return $size;
}
