<?php
/**
 * פיבונאצ'י — המנוע (spec-stage-a פרק 22, F-1). טהור: בלי מסד, בלי רשת, בלי שעון. float (עיקרון 6).
 *
 * המקור לכללים: team/handoff/fibonacci-book-check.md (Constance Brown, *Fibonacci Analysis*). כל כלל כאן — עם המספר שלו
 * שם (R, S, Z, E) ועם הסעיף בפרק 22. מה שמסומן [שלנו] — הגדרה שלנו כדי שמחשב יוכל להריץ, לא טענה על הספר.
 *
 * הכניסה: חלון נרות סגורים (הנר האחרון = "הסגירה האחרונה"), לכל נר גם ה-ATR שלו (a), ונקודות המפנה שאושרו בחלון —
 * מאותו zigzag של elliott-w3 (lib/elliott.php, ts_ew_zz_step — קוד משותף, לא העתקה; 22.2). היציאה — הרשת:
 * לכל צד (תמיכה / התנגדות) נקודת ההתחלה, הטווחים, הרמות, אזורי החפיפה והרמות הבודדות.
 *
 * **התנגדות = תמיכה במראה** (S1, 22.3): המחירים מוכפלים ב-−1 (o, c, ושיא ↔ שפל), מריצים את אותו קוד של תמיכה, ומחזירים.
 * כך שני הצדדים זהים בהגדרה, ולא שני קודים שעלולים להתפצל.
 */

declare(strict_types=1);

require_once __DIR__ . '/elliott.php';

/** היחסים — רק אלה (R1, 22.3). לא 23.6, לא 75, לא 78.6 */
const TS_FIB_RATIOS = [0.382, 0.5, 0.618];

/** סבולת השוואות של float (מרחק "עד" סף — כולל) [שלנו] */
const TS_FIB_EPS = 1e-9;

/** קצה הגוף העליון והתחתון (S29) — לא הפתיל */
function ts_fib_top(array $c): float { return max($c['o'], $c['c']); }
function ts_fib_bot(array $c): float { return min($c['o'], $c['c']); }

/**
 * הרשת על חלון. $win — [{t, o, h, l, c, a}] ממוין (a = ATR בנר, או null בחימום). $pivots — [{type: H|L, t}] שאושרו.
 * $p — הפרמטרים של השיטה (close_atr, strong_atr, max_ranges, zone_atr, zone_max_atr). $prev — הרשת של הנר הקודם:
 * צד שנקודת ההתחלה שלו לא השתנתה **נשאר נעול** (הכרעת הבקרה על 22.3(7), "ג" — ראו ts_fib_side).
 * מחזיר null כשאין ATR בנר האחרון (חימום). אחרת {atr, close, t, support, resistance}.
 */
function ts_fib_grid(array $win, array $pivots, array $p, ?array $prev = null): ?array
{
    $n = count($win);
    if ($n < 2 || ($atr = $win[$n - 1]['a'] ?? null) === null) return null;
    $mirror = array_map(fn($c) => ['t' => $c['t'], 'o' => -$c['o'], 'h' => -$c['l'], 'l' => -$c['h'], 'c' => -$c['c'], 'a' => $c['a']], $win);
    $flip = array_map(fn($x) => ['type' => $x['type'] === 'H' ? 'L' : 'H'] + $x, $pivots);
    return ['atr' => (float)$atr, 'close' => (float)$win[$n - 1]['c'], 't' => $win[$n - 1]['t'],
        'support' => ts_fib_side($win, $pivots, $p, (float)$atr, 1, $prev['support'] ?? null),
        'resistance' => ts_fib_side($mirror, $flip, $p, (float)$atr, -1, $prev['resistance'] ?? null)];
}

/**
 * צד אחד בכיוון "תמיכה" (מהשיא למטה). $sign = 1 — תמיכה; −1 — התנגדות (הנרות כבר במראה, והמחירים בפלט חוזרים × −1).
 * הסעיפים — 22.3 (1–8) ו-22.4.
 *
 * **נעילה (הבקרה, הכרעה "ג" על 22.3(7); E2 עמ' 144 — "הסגירה הנוכחית" היא של רגע הבנייה):** הצד נבנה כשנקודת ההתחלה
 * נקבעת, והפסילה נבדקת רק אז, מול הסגירה של אותו נר (built_t). כל עוד נקודת ההתחלה לא השתנתה ($prev עם אותו start), הצד
 * נשאר כמו שנבנה, וטווח יוצא ממנו רק לפי S17: גוף של נר **אחרי הבנייה** ירד מתחת לנקודת הסיום שלו. האזורים מחושבים מחדש
 * מהטווחים שנשארו, עם ה-ATR של הבנייה. בלי זה אזור תמיכה נעלם לפני שהמחיר מגיע אליו (רמת ה-38.2 עוברת מעל הסגירה).
 */
function ts_fib_side(array $w, array $pivots, array $p, float $atr, int $sign, ?array $prev = null): array
{
    $n = count($w);
    $out = ['start' => null, 'built_t' => null, 'atr' => null, 'ranges' => [], 'dropped' => [], 'zones' => [], 'singles' => []];
    $idx = [];
    foreach ($w as $i => $c) $idx[$c['t']] = $i;
    // הגוף הגבוה ביותר אחרי כל נר, והנמוך ביותר אחריו (לבדיקת "לא עבר" ו"נמחק") — O(n)
    $sufTop = array_fill(0, $n + 1, -INF);
    $sufBot = array_fill(0, $n + 1, INF);
    for ($i = $n - 1; $i >= 0; $i--) {
        $sufTop[$i] = max($sufTop[$i + 1], ts_fib_top($w[$i]));
        $sufBot[$i] = min($sufBot[$i + 1], ts_fib_bot($w[$i]));
    }

    // 1. נקודת ההתחלה H: השיא המאושר האחרון שאף נר אחריו לא עבר אותו — בגוף (S29, S3, E4)
    $iH = null;
    foreach (array_reverse($pivots) as $pv) {
        if ($pv['type'] !== 'H' || !isset($idx[$pv['t']])) continue;
        $i = $idx[$pv['t']];
        if ($sufTop[$i + 1] <= ts_fib_top($w[$i])) { $iH = $i; break; }
    }
    if ($iH === null || $iH === 0) return $out;
    $H = ts_fib_top($w[$iH]);

    // נעול: אותה נקודת התחלה — אותם טווחים, פחות מה שנשבר אחרי הבנייה (S17)
    if ($prev !== null && $prev['start'] !== null && $prev['start']['t'] === $w[$iH]['t'] && abs($sign * $prev['start']['price'] - $H) <= TS_FIB_EPS
        && isset($idx[$prev['built_t']])) {
        $after = $sufBot[$idx[$prev['built_t']] + 1];
        $kept = array_values(array_filter($prev['ranges'], fn($r) => $after >= $sign * $r['end'] - TS_FIB_EPS));
        [$zones, $singles] = ts_fib_zones($kept, (float)$prev['atr'], $p);
        return ['ranges' => $kept, 'zones' => $zones, 'singles' => $singles] + $prev;
    }

    // 2. המהלך: מהשפל הנמוך ביותר (בגוף) בחלון שלפני H, ועד H
    $i0 = 0;
    for ($i = 1; $i < $iH; $i++) if (ts_fib_bot($w[$i]) < ts_fib_bot($w[$i0])) $i0 = $i;

    // 3. נקודות סיום מועמדות בתוך המהלך. מפתח — המחיר (אותו מחיר משני מקורות = נקודה אחת עם שני הסוגים)
    $cand = [];
    $add = function (float $price, int $i, string $kind) use (&$cand, $H) {
        if ($price >= $H - TS_FIB_EPS) return;   // טווח באורך 0 — אין
        $k = sprintf('%.10F', $price);
        $cand[$k] ??= ['price' => $price, 'i' => $i, 'kinds' => []];
        if (!in_array($kind, $cand[$k]['kinds'], true)) $cand[$k]['kinds'][] = $kind;
    };
    // שפל שנמחק (S17, E3): נר כלשהו אחריו — עד הנר האחרון, גם אחרי H — ירד מתחתיו (בגוף). נשארות רק "מדרגות" שלא נשברו
    $alive = fn(int $j) => $sufBot[$j + 1] >= ts_fib_bot($w[$j]);
    $lows = [$i0];
    foreach ($pivots as $pv) if ($pv['type'] === 'L' && isset($idx[$pv['t']]) && $idx[$pv['t']] > $i0 && $idx[$pv['t']] < $iH) $lows[] = $idx[$pv['t']];
    foreach ($lows as $j) if ($alive($j)) $add(ts_fib_bot($w[$j]), $j, 'pivot');   // בתמיכה — שפל; בהתנגדות — שיא
    // פער אמיתי (S14): l[k+1] > h[k] — הקצה התחתון של הפער. [שלנו] במסגרת תוך-יומית, פער מעל "חור" בזמן (הפסקת מסחר בין
    // סשנים, סוף שבוע) — לא נחשב (S14, עמ' 105). המסגרת = המרווח הקטן ביותר בין נרות בחלון.
    $step = PHP_INT_MAX;
    for ($i = 1; $i < $n; $i++) $step = min($step, $w[$i]['t'] - $w[$i - 1]['t']);
    for ($k = $i0; $k < $iH; $k++) {
        $a = $w[$k]; $b = $w[$k + 1];
        if (!($b['l'] > $a['h'])) continue;   // במראה זה h[k+1] < l[k] — פער כלפי מטה
        if ($step < 86400 && $b['t'] - $a['t'] > $step) continue;
        $add($a['h'], $k, 'gap');
    }
    // הנר עם הגוף הארוך ביותר במהלך (S15) — תמיד נכלל; והנרות החזקים (S13) [שלנו, פ3: גוף ≥ strong_atr × ATR]
    $iL = $i0;
    for ($i = $i0; $i <= $iH; $i++) {
        $body = abs($w[$i]['c'] - $w[$i]['o']);
        if ($body > abs($w[$iL]['c'] - $w[$iL]['o'])) $iL = $i;
        if ($w[$i]['a'] !== null && $body > 0 && $body >= $p['strong_atr'] * $w[$i]['a'] - TS_FIB_EPS) $add(ts_fib_bot($w[$i]), $i, 'strong');
    }
    $add(ts_fib_bot($w[$iL]), $iL, 'longest');
    $longest = sprintf('%.10F', ts_fib_bot($w[$iL]));

    // 4. מהגבוהה (הקרובה ל-H) כלפי מטה (S18)
    $cand = array_values($cand);
    usort($cand, fn($x, $y) => $y['price'] <=> $x['price'] ?: $x['i'] <=> $y['i']);
    // 5. דילוג על נקודות קרובות (S20) [שלנו, פ4]: פחות מ-close_atr × ATR מהקודמת שנבחרה — מדלגים. הארוך ביותר נשאר
    $isLong = fn($x) => in_array('longest', $x['kinds'], true);
    $sel = [];
    foreach ($cand as $x) {
        $prev = $sel ? $sel[count($sel) - 1] : null;
        if ($prev !== null && $prev['price'] - $x['price'] < $p['close_atr'] * $atr - TS_FIB_EPS) {
            if ($isLong($x) && !$isLong($prev)) $sel[count($sel) - 1] = $x;
            continue;
        }
        $sel[] = $x;
    }
    // 6. עד max_ranges [שלנו, פ5]; טווח הנר הארוך ביותר תמיד בפנים (S15) — במקום האחרון אם צריך
    $max = (int)$p['max_ranges'];
    if (count($sel) > $max) {
        $keep = array_slice($sel, 0, $max);
        if (!array_filter($keep, $isLong) && ($lx = array_values(array_filter($sel, $isLong)))) $keep[$max - 1] = $lx[0];
        $sel = $keep;
    }

    // רמות: H − r × (H − E) (R1). 7. פסילה (S2, E2): רמה מעל הסגירה האחרונה — הטווח לא מזהה תמיכה
    $close = $w[$n - 1]['c'];
    $back = fn(float $v) => $sign * $v;
    $out['start'] = ['price' => $back($H), 't' => $w[$iH]['t']];
    $out['built_t'] = $w[$n - 1]['t'];
    $out['atr'] = $atr;
    $id = 0;
    foreach ($sel as $x) {
        $lv = array_map(fn($r) => ['r' => $r, 'price' => $H - $r * ($H - $x['price'])], TS_FIB_RATIOS);
        $range = ['id' => $id++, 'start' => $back($H), 'start_t' => $w[$iH]['t'], 'end' => $back($x['price']), 'end_t' => $w[$x['i']]['t'],
            'kinds' => $x['kinds']];
        if (max(array_column($lv, 'price')) > $close + TS_FIB_EPS) { $out['dropped'][] = $range; continue; }
        $range['levels'] = array_map(fn($l) => ['r' => $l['r'], 'price' => $back($l['price'])], $lv);
        $out['ranges'][] = $range;
    }

    [$out['zones'], $out['singles']] = ts_fib_zones($out['ranges'], $atr, $p);
    return $out;
}

/**
 * 22.4 — אזורי החפיפה מהטווחים (מחירים אמיתיים, אחרי המראה): [zones, singles], בסדר עולה במחיר.
 */
function ts_fib_zones(array $ranges, float $atr, array $p): array
{
    $zones = []; $singles = [];
    $levels = [];
    foreach ($ranges as $rg) foreach ($rg['levels'] as $l) $levels[] = $l + ['range' => $rg['id']];
    usort($levels, fn($a, $b) => $a['price'] <=> $b['price'] ?: $a['range'] <=> $b['range']);
    $groups = [];
    // [שלנו] ממוינות; רמה מצטרפת כשהיא עד zone_atr × ATR מהקודמת באזור, וכל עוד רוחב האזור עד zone_max_atr × ATR
    foreach ($levels as $l) {
        $k = count($groups) - 1;
        $joins = $k >= 0 && $l['price'] - $groups[$k][count($groups[$k]) - 1]['price'] <= $p['zone_atr'] * $atr + TS_FIB_EPS
            && $l['price'] - $groups[$k][0]['price'] <= $p['zone_max_atr'] * $atr + TS_FIB_EPS;
        if ($joins) $groups[$k][] = $l; else $groups[] = [$l];
    }
    $byId = array_column($ranges, null, 'id');
    foreach ($groups as $g) {
        // Z1: לפחות שתי רמות משני טווחים שונים בשני יחסים שונים
        $ok = false;
        foreach ($g as $a) foreach ($g as $b) if ($a['range'] !== $b['range'] && $a['r'] !== $b['r']) { $ok = true; break 2; }
        if (!$ok) { foreach ($g as $l) $singles[] = $l; continue; }
        $rids = array_values(array_unique(array_column($g, 'range')));
        // Z5 [שלנו — המשקלים]: מספר הרמות, ועוד 1 לכל טווח שמסתיים בפער / בנר חזק / בנר הארוך ביותר
        $bonus = count(array_filter($rids, fn($r) => array_intersect($byId[$r]['kinds'], ['gap', 'strong', 'longest'])));
        $prices = array_column($g, 'price');
        $zones[] = ['lo' => min($prices), 'hi' => max($prices), 'levels' => $g, 'ranges' => count($rids),
            'score' => count($g) + $bonus];
    }
    return [$zones, $singles];
}

/**
 * האות בנר $c לפי הרשת שחושבה מנרות ≤ c − 1 (22.6). לונג: אזור תמיכה בציון ≥ min_score שהנר נגע בו (l ≤ גבול עליון)
 * ונסגר מעליו. סטופ מתחת לאזור (M1), יעד — הגבול התחתון של אזור ההתנגדות הבא מעל (M2), ויחס ≥ min_rr. שורט — במראה.
 * מחזיר אות {side, entry, stop, target, zone: [lo, hi], score, rr} או null.
 */
function ts_fib_signal(array $g, array $c, array $p): ?array
{
    $buf = $p['stop_atr'] * $g['atr'];
    $long = null;
    foreach ($g['support']['zones'] as $z) {
        if ($z['score'] < $p['min_score'] || !($c['l'] <= $z['hi'] && $c['c'] > $z['hi'])) continue;
        if ($long === null || $z['hi'] > $long['hi']) $long = $z;   // [שלנו] כמה אזורים — הגבוה (הקרוב למחיר)
    }
    if ($long !== null) {
        $tgt = null;
        foreach ($g['resistance']['zones'] as $z) if ($z['lo'] > $c['c'] && ($tgt === null || $z['lo'] < $tgt)) $tgt = $z['lo'];
        $sig = ts_fib_levels('long', $c['c'], $long['lo'] - $buf, $tgt, $long, $p);
        if ($sig) return $sig;
    }
    $short = null;
    foreach ($g['resistance']['zones'] as $z) {
        if ($z['score'] < $p['min_score'] || !($c['h'] >= $z['lo'] && $c['c'] < $z['lo'])) continue;
        if ($short === null || $z['lo'] < $short['lo']) $short = $z;
    }
    if ($short === null) return null;
    $tgt = null;
    foreach ($g['support']['zones'] as $z) if ($z['hi'] < $c['c'] && ($tgt === null || $z['hi'] > $tgt)) $tgt = $z['hi'];
    return ts_fib_levels('short', $c['c'], $short['hi'] + $buf, $tgt, $short, $p);
}

/** הרמות של אות, או null: אין יעד (לפני F-2 — אין אות), סיכון ≤ 0, או יחס סיכוי-סיכון < min_rr */
function ts_fib_levels(string $side, float $entry, float $stop, ?float $target, array $z, array $p): ?array
{
    if ($target === null) return null;
    $d = $side === 'long' ? 1 : -1;
    $risk = $d * ($entry - $stop);
    if ($risk <= 0) return null;
    $rr = $d * ($target - $entry) / $risk;
    if ($rr < $p['min_rr'] - TS_FIB_EPS) return null;
    return ['side' => $side, 'entry' => $entry, 'stop' => $stop, 'target' => $target, 'zone' => [$z['lo'], $z['hi']],
        'score' => $z['score'], 'rr' => $rr];
}

/**
 * הרשת אחרי הנר האחרון (לבדיקות ולסט הקבוע) — אותה זרימה כמו ב-step: חלון נע של lookback_bars, והרשת של כל נר נבנית על
 * של הקודם (נעילה). רק lookback_bars + 1 הנרות האחרונים מחושבים: נקודת ההתחלה תמיד בחלון, ולכן הרשת הנעולה נבנתה
 * בתוכם — מוקדם יותר אין לה השפעה. התוצאה זהה לריצה של השיטה מנר 0 (tests/fibonacci.php בודק).
 */
function ts_fib_grid_of(array $candles, array $p): ?array
{
    $a = ts_ew_atr_init();
    $z = ts_ew_zz_init();
    $win = [];
    $piv = [];
    $g = null;
    $max = (int)$p['lookback_bars'];
    $from = count($candles) - $max - 1;
    foreach ($candles as $k => $c) {
        $a = ts_ew_atr_step($a, $c, (int)$p['atr_len']);
        [$z, $pv] = ts_ew_zz_step($z, $c, $a['v'], (float)$p['pivot_atr_mult']);
        if ($pv) $piv[] = ['type' => $pv['type'], 't' => $pv['t']];
        $win[] = ['t' => $c['t'], 'o' => $c['o'], 'h' => $c['h'], 'l' => $c['l'], 'c' => $c['c'], 'a' => $a['v']];
        if (count($win) > $max) $win = array_slice($win, -$max);
        while ($piv && $piv[0]['t'] < $win[0]['t']) array_shift($piv);
        if ($k >= $from) $g = ts_fib_grid($win, $piv, $p, $g);
    }
    return $g;
}
