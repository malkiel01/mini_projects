<?php
/**
 * פיבונאצ'י — המנוע (spec-stage-a פרק 22, F-1 ו-F-2 — התרחיש: A-B-X, קופסת השוויון, Z6, יעד חלופי). טהור: בלי מסד, בלי רשת, בלי שעון. float (עיקרון 6).
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

/** יחסי ההקרנה של A-B-X — רק R5 (22.5א, עמ' 87). לא 261.8 (R7); עמ' 102 — מחוץ ל-F-2 (פ1) */
const TS_FIB_PROJ = [0.618, 1.0, 1.382, 1.5, 1.618];

/** box_target (22.5ח, 2.32): היעד החלופי — חלק מהקופסה, מאמצע האזור עד T. רק אלה */
const TS_FIB_BOX_TARGETS = [0.5, 0.618, 1.0];

/** סבולת השוואות של float (מרחק "עד" סף — כולל) [שלנו] */
const TS_FIB_EPS = 1e-9;

/** קצה הגוף העליון והתחתון (S29) — לא הפתיל */
function ts_fib_top(array $c): float { return max($c['o'], $c['c']); }
function ts_fib_bot(array $c): float { return min($c['o'], $c['c']); }

/**
 * הרשת על חלון. $win — [{t, o, h, l, c, a}] ממוין (a = ATR בנר, או null בחימום). $pivots — [{type: H|L, t, ct}] שאושרו
 * (t — נר הקיצון, ct — נר האישור).
 * $p — הפרמטרים של השיטה (close_atr, strong_atr, max_ranges, zone_atr, zone_max_atr). $prev — הרשת של הנר הקודם:
 * צד שנקודת ההתחלה שלו לא השתנתה **נשאר נעול** (הכרעת הבקרה על 22.3(7), "ג" — ראו ts_fib_side).
 * $abx — שלוש נקודות המפנה האחרונות של הזיגזג, במחיר הגוף (ts_fib_pivot_body) — ל-A-B-X (22.5א, F-2).
 * מחזיר null כשאין ATR בנר האחרון (חימום). אחרת {atr, close, t, pl, support, resistance, abx} — ולכל אזור גם box ו-z6 (22.5).
 */
function ts_fib_grid(array $win, array $pivots, array $p, ?array $prev = null, array $abx = []): ?array
{
    $n = count($win);
    if ($n < 2 || ($atr = $win[$n - 1]['a'] ?? null) === null) return null;
    $pl = $pivots ? $pivots[count($pivots) - 1]['t'] : null;
    $out = ['atr' => (float)$atr, 'close' => (float)$win[$n - 1]['c'], 't' => $win[$n - 1]['t'], 'pl' => $pl];
    // נתיב מהיר (ביצועים, F-2): הרשת של הנר הקודם, ואין נקודת מפנה חדשה — כל צד נבדק מול הנר החדש בלבד (ts_fib_side_next).
    // צד שלא ניתן לקבוע כך — מחושב במלואו, בדיוק כמו קודם. התוצאה זהה לחישוב המלא (tests/fibonacci.php בודק על כל נר)
    $fast = $prev !== null && $prev['t'] === $win[$n - 2]['t'] && array_key_exists('pl', $prev) && $prev['pl'] === $pl;
    $c = $win[$n - 1];
    $w0 = $win[0]['t'];
    foreach (['support' => 1, 'resistance' => -1] as $key => $sign) {
        $side = $fast ? ts_fib_side_next($prev[$key], $c, $w0, $sign, $p) : null;
        if ($side === null) {
            $w = $sign === 1 ? $win : array_map(fn($c) => ['t' => $c['t'], 'o' => -$c['o'], 'h' => -$c['l'], 'l' => -$c['h'], 'c' => -$c['c'], 'a' => $c['a']], $win);
            $pv = $sign === 1 ? $pivots : array_map(fn($x) => ['type' => $x['type'] === 'H' ? 'L' : 'H'] + $x, $pivots);
            $side = ts_fib_side($w, $pv, $p, (float)$atr, $sign, $prev[$key] ?? null);
        }
        $out[$key] = $side;
    }
    return ts_fib_project($out, ts_fib_abx($abx));
}

/**
 * הצד אחרי נר אחד נוסף, בלי לעבור על כל החלון — או null כשצריך חישוב מלא (ts_fib_side). נקרא רק כשהרשת הקודמת היא של
 * הנר הקודם ואין נקודת מפנה חדשה. אז נקודת ההתחלה לא משתנה כל עוד: נקודת המפנה שלה עוד בחלון, היא לא הנר הראשון בחלון,
 * נר הבנייה עוד בחלון, והגוף של הנר החדש לא עבר אותה (נקודות מפנה חדשות יותר נפסלו כבר — בנר שעדיין בחלון). ואז הצד נעול,
 * וטווח יוצא רק לפי S17 מול הנר החדש (הטווחים הקודמים כבר סוננו מול כל הנרות שלפניו). צד ריק בלי נקודת מפנה מתאימה
 * נשאר ריק: נר חדש רק פוסל נקודות מפנה, לא מכשיר.
 */
function ts_fib_side_next(array $prev, array $c, int $w0, int $sign, array $p): ?array
{
    if (!array_key_exists('pv', $prev)) return null;
    if ($prev['start'] === null) return $prev['pv'] === null ? $prev : null;
    if ($prev['pv'] < $w0 || $prev['start']['t'] <= $w0 || $prev['built_t'] < $w0) return null;
    $top = $sign === 1 ? max($c['o'], $c['c']) : -min($c['o'], $c['c']);
    if ($top > $sign * $prev['start']['price']) return null;
    $bot = $sign === 1 ? min($c['o'], $c['c']) : -max($c['o'], $c['c']);
    $kept = array_values(array_filter($prev['ranges'], fn($r) => $bot >= $sign * $r['end'] - TS_FIB_EPS));
    if (count($kept) === count($prev['ranges'])) return $prev;
    [$zones, $singles] = ts_fib_zones($kept, (float)$prev['atr'], $p);
    return ['ranges' => $kept, 'zones' => $zones, 'singles' => $singles] + $prev;
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
    // pv — נקודת המפנה (t של הקיצון) שממנה נקבעה נקודת ההתחלה, או null כשאין כזו (לנתיב המהיר — ts_fib_side_next)
    $out = ['start' => null, 'built_t' => null, 'atr' => null, 'pv' => null, 'move' => null, 'ranges' => [], 'dropped' => [], 'zones' => [], 'singles' => []];
    $idx = [];
    foreach ($w as $i => $c) $idx[$c['t']] = $i;
    // הגוף הגבוה ביותר אחרי כל נר, והנמוך ביותר אחריו (לבדיקת "לא עבר" ו"נמחק") — O(n)
    $sufTop = array_fill(0, $n + 1, -INF);
    $sufBot = array_fill(0, $n + 1, INF);
    for ($i = $n - 1; $i >= 0; $i--) {
        $sufTop[$i] = max($sufTop[$i + 1], ts_fib_top($w[$i]));
        $sufBot[$i] = min($sufBot[$i + 1], ts_fib_bot($w[$i]));
    }

    // 1. נקודת ההתחלה H: השיא המאושר האחרון שאף נר אחריו לא עבר אותו — בגוף (S29, S3, E4). ה-zigzag בוחר את נר הקיצון
    // לפי הפתיל; H הוא קצה הגוף הגבוה ביותר **מנר הקיצון ועד נר האישור, כולל שניהם** (הבקרה, ביקורת #1662 🔴1) — אחרת נר
    // סמוך עם גוף גבוה יותר ופתיל נמוך יותר "עובר" את H, ונופלים לשיא ישן.
    $iH = null;
    foreach (array_reverse($pivots) as $pv) {
        if ($pv['type'] !== 'H' || !isset($idx[$pv['t']])) continue;
        $i = $idx[$pv['t']];
        $ic = $idx[$pv['ct'] ?? $pv['t']] ?? $i;
        for ($j = $i + 1; $j <= $ic; $j++) if (ts_fib_top($w[$j]) > ts_fib_top($w[$i])) $i = $j;
        if ($sufTop[$i + 1] <= ts_fib_top($w[$i])) { $iH = $i; $out['pv'] = $pv['t']; break; }
    }
    if ($iH === null || $iH === 0) return $out;
    $H = ts_fib_top($w[$iH]);

    // נעול: אותה נקודת התחלה — אותם טווחים, פחות מה שנשבר אחרי הבנייה (S17)
    if ($prev !== null && $prev['start'] !== null && $prev['start']['t'] === $w[$iH]['t'] && abs($sign * $prev['start']['price'] - $H) <= TS_FIB_EPS
        && isset($idx[$prev['built_t']])) {
        $after = $sufBot[$idx[$prev['built_t']] + 1];
        $kept = array_values(array_filter($prev['ranges'], fn($r) => $after >= $sign * $r['end'] - TS_FIB_EPS));
        [$zones, $singles] = ts_fib_zones($kept, (float)$prev['atr'], $p);
        return ['pv' => $out['pv'], 'ranges' => $kept, 'zones' => $zones, 'singles' => $singles] + $prev;
    }

    // 2. המהלך: מהשפל הנמוך ביותר (בגוף) בחלון שלפני H, ועד H
    $i0 = 0;
    for ($i = 1; $i < $iH; $i++) if (ts_fib_bot($w[$i]) < ts_fib_bot($w[$i0])) $i0 = $i;

    // 3. נקודות סיום מועמדות בתוך המהלך. מפתח — המחיר (אותו מחיר משני מקורות = נקודה אחת עם שני הסוגים)
    $cand = [];
    // S17 / E3 — אחיד לכל סוג (ביקורת #1662 🟡2): נקודת סיום שגוף של נר אחריה, עד הנר האחרון (גם אחרי H), ירד מתחתיה —
    // "נמחקה", ולא נכנסת. כך נשארות רק "מדרגות" שלא נשברו. אותו כלל ממשיך גם ברשת הנעולה
    $add = function (float $price, int $i, string $kind) use (&$cand, $H, $sufBot) {
        if ($price >= $H - TS_FIB_EPS) return;   // טווח באורך 0 — אין
        if ($sufBot[$i + 1] < $price - TS_FIB_EPS) return;
        $k = sprintf('%.10F', $price);
        $cand[$k] ??= ['price' => $price, 'i' => $i, 'kinds' => []];
        if (!in_array($kind, $cand[$k]['kinds'], true)) $cand[$k]['kinds'][] = $kind;
    };
    // השפל הקיצוני של המהלך ושפלים מאושרים בתוכו (בתמיכה — שפל; בהתנגדות — שיא)
    $lows = [$i0];
    foreach ($pivots as $pv) if ($pv['type'] === 'L' && isset($idx[$pv['t']]) && $idx[$pv['t']] > $i0 && $idx[$pv['t']] < $iH) $lows[] = $idx[$pv['t']];
    foreach ($lows as $j) $add(ts_fib_bot($w[$j]), $j, 'pivot');
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

    // 4. מהגבוהה (הקרובה ל-H) כלפי מטה (S18)
    $cand = array_values($cand);
    usort($cand, fn($x, $y) => $y['price'] <=> $x['price'] ?: $x['i'] <=> $y['i']);
    // 5. דילוג על נקודות קרובות (S20) [שלנו, פ4]: פחות מ-close_atr × ATR מהקודמת שנבחרה — מדלגים. הארוך ביותר נשאר
    $isLong = fn($x) => in_array('longest', $x['kinds'], true);
    $sel = [];
    foreach ($cand as $x) {
        $last = $sel ? $sel[count($sel) - 1] : null;
        if ($last !== null && $last['price'] - $x['price'] < $p['close_atr'] * $atr - TS_FIB_EPS) {
            if ($isLong($x) && !$isLong($last)) $sel[count($sel) - 1] = $x;
            continue;
        }
        $sel[] = $x;
    }

    // רמות: H − r × (H − E) (R1). 7. פסילה (S2, E2): רמה מעל הסגירה של רגע הבנייה — הטווח לא מזהה תמיכה
    $close = $w[$n - 1]['c'];
    $back = fn(float $v) => $sign * $v;
    $out['start'] = ['price' => $back($H), 't' => $w[$iH]['t']];
    // M0 — תחילת המהלך (22.3(2), בגוף): הבסיס של קופסת השוויון (22.5ב). ננעל עם הצד
    $out['move'] = ['price' => $back(ts_fib_bot($w[$i0])), 't' => $w[$i0]['t']];
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
    // 6. עד max_ranges [שלנו, פ5] — סופרים **רק טווחים שלא נפסלו** (ביקורת #1662 🟡3: התקרה לפני הפסילה חתכה טווחים עמוקים
    // תקפים). טווח הנר הארוך ביותר תמיד בפנים (S15) — במקום האחרון אם צריך
    $max = (int)$p['max_ranges'];
    if (count($out['ranges']) > $max) {
        $keep = array_slice($out['ranges'], 0, $max);
        if (!array_filter($keep, $isLong) && ($lx = array_values(array_filter($out['ranges'], $isLong)))) $keep[$max - 1] = $lx[0];
        $out['ranges'] = $keep;
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
 * ונסגר מעליו. סטופ מתחת לאזור ומתחת לפתיל של נר האות (M1; הכרעת הבעלים, פ10), יעד — הגבול התחתון של אזור ההתנגדות הבא מעל (M2); אין כזה — T של קופסת השוויון של
 * אזור האות (F-2, 22.5ב). יחס ≥ min_rr. שורט — במראה. הציון — כולל Z6 (ts_fib_project).
 * מחזיר אות {side, entry, stop, target, zone: [lo, hi], score, rr} או null.
 */
function ts_fib_signal(array $g, array $c, array $p): ?array
{
    $buf = $p['stop_atr'] * $g['atr'];
    $long = null;
    foreach ($g['support']['zones'] as $z) {
        // "בחזרה" — רק מלמעלה (ביקורת #1662 🟡4): גם הנר הקודם נסגר מעל האזור; אחרת זו פריצה מלמטה
        if ($z['score'] < $p['min_score'] || !($g['close'] > $z['hi'] && $c['l'] <= $z['hi'] && $c['c'] > $z['hi'])) continue;
        if ($long === null || $z['hi'] > $long['hi']) $long = $z;   // [שלנו] כמה אזורים — הגבוה (הקרוב למחיר)
    }
    if ($long !== null) {
        $tgt = null;
        foreach ($g['resistance']['zones'] as $z) if ($z['lo'] > $c['c'] && ($tgt === null || $z['lo'] < $tgt)) $tgt = $z['lo'];
        // הסטופ (הכרעת הבעלים, פ10 — אפיון 2.34): מתחת לאזור **ומתחת לפתיל של נר האות**, ועוד stop_atr × ATR (M1)
        // יעד חלופי (F-2, 22.6): אין אזור התנגדות מעל → T של קופסת השוויון של **אזור האות** (22.5ב)
        $sig = ts_fib_levels('long', $c['c'], min($long['lo'], $c['l']) - $buf, $tgt ?? ts_fib_box_target($long, $p), $long, $p, $tgt === null ? 'box' : 'zone');
        if ($sig) return $sig;
    }
    $short = null;
    foreach ($g['resistance']['zones'] as $z) {
        if ($z['score'] < $p['min_score'] || !($g['close'] < $z['lo'] && $c['h'] >= $z['lo'] && $c['c'] < $z['lo'])) continue;
        if ($short === null || $z['lo'] < $short['lo']) $short = $z;
    }
    if ($short === null) return null;
    $tgt = null;
    foreach ($g['support']['zones'] as $z) if ($z['hi'] < $c['c'] && ($tgt === null || $z['hi'] > $tgt)) $tgt = $z['hi'];
    return ts_fib_levels('short', $c['c'], max($short['hi'], $c['h']) + $buf, $tgt ?? ts_fib_box_target($short, $p), $short, $p, $tgt === null ? 'box' : 'zone');
}

/** היעד החלופי של אזור (22.5ב, 22.5ח): אמצע + box_target × (T − אמצע); בלי קופסה — null */
function ts_fib_box_target(array $z, array $p): ?float
{
    if (empty($z['box'])) return null;
    $b = $z['box'];
    $bt = (float)($p['box_target'] ?? 1);
    return abs($bt - 1) < 1e-12 ? $b['T'] : $b['mid'] + $bt * ($b['T'] - $b['mid']);   // 1 — T עצמו, בלי עיגול נוסף
}

/**
 * הרמות של אות, או null: אין יעד, יעד שאינו מעבר לכניסה (T ≤ הכניסה בלונג — F-2 קריטריון 7), סיכון ≤ 0, או יחס < min_rr.
 * $via — מאיפה היעד: zone (האזור הבא, M2) או box (קופסת השוויון של אזור האות, 22.5ב)
 */
function ts_fib_levels(string $side, float $entry, float $stop, ?float $target, array $z, array $p, string $via = 'zone'): ?array
{
    if ($target === null) return null;
    $d = $side === 'long' ? 1 : -1;
    $risk = $d * ($entry - $stop);
    if ($risk <= 0 || $d * ($target - $entry) <= 0) return null;
    $rr = $d * ($target - $entry) / $risk;
    if ($rr < $p['min_rr'] - TS_FIB_EPS) return null;
    return ['side' => $side, 'entry' => $entry, 'stop' => $stop, 'target' => $target, 'zone' => [$z['lo'], $z['hi']],
        'score' => $z['score'], 'z6' => $z['z6'] ?? false, 'rr' => $rr, 'via' => $via,
        'box_t' => $z['box']['T'] ?? null];
}

/**
 * מחיר נקודת מפנה בגוף (22.5א לפי 2.29): שיא — max(o,c) הגבוה ביותר מנר הקיצון ועד נר האישור, כולל שניהם; שפל — במראה.
 * $win — החלון, שכבר כולל את נר האישור. נר הקיצון שיצא מהחלון (חלון קצר מהתנודה) — מה שנשאר ממנו בחלון.
 */
function ts_fib_pivot_body(array $win, array $pv): float
{
    $hi = $pv['type'] === 'H';
    $v = null;
    for ($i = count($win) - 1; $i >= 0 && $win[$i]['t'] >= $pv['t']; $i--) {
        if ($win[$i]['t'] > $pv['ct']) continue;
        $b = $hi ? ts_fib_top($win[$i]) : ts_fib_bot($win[$i]);
        if ($v === null || ($hi ? $b > $v : $b < $v)) $v = $b;
    }
    return $v ?? (float)$pv['v'];
}

/**
 * A-B-X (22.5א, P1, P3) [שלנו — איך בוחרים]: שלוש נקודות המפנה המאושרות האחרונות, בגוף. $k — [{type, p, t, ct}], עד שלוש.
 * מעלה: A שפל, B שיא, X שפל ו-X > A → X + r × (B − A). מטה — במראה. X שעבר את A — אין (S17). r — רק R5.
 * מחזיר {dir: up|down, a, b, x, targets: [{r, price}]} או null.
 */
function ts_fib_abx(array $k): ?array
{
    if (count($k) < 3) return null;
    [$A, $B, $X] = array_slice($k, -3);
    $up = $A['type'] === 'L';
    if ($up ? !($X['p'] > $A['p'] + TS_FIB_EPS) : !($X['p'] < $A['p'] - TS_FIB_EPS)) return null;
    $len = abs($B['p'] - $A['p']);
    $d = $up ? 1 : -1;
    return ['dir' => $up ? 'up' : 'down', 'a' => $A, 'b' => $B, 'x' => $X,
        'targets' => array_map(fn($r) => ['r' => $r, 'price' => $X['p'] + $d * $r * $len], TS_FIB_PROJ)];
}

/**
 * קופסת השוויון של אזור (22.5ב, P4, P5, P9): הגובה מתחילת המהלך M0 ועד אמצע האזור, מועתק מעבר לאזור באותו כיוון.
 * T = 2 × אמצע − M0 (תמיכה — מעלה; התנגדות — מטה, כי M0 שלה מעליה). יעדי ביניים — 38.2 / 50 / 61.8 מהאמצע עד T.
 */
function ts_fib_box(array $z, float $m0): array
{
    $mid = ($z['lo'] + $z['hi']) / 2;
    $T = 2 * $mid - $m0;
    return ['m0' => $m0, 'mid' => $mid, 'T' => $T, 'levels' => array_map(fn($r) => ['r' => $r, 'price' => $mid + $r * ($T - $mid)], TS_FIB_RATIOS)];
}

/**
 * האזור של צד שהקופסה שלו מייצגת אותו (22.5ב): הציון הגבוה ביותר; בשוויון — האמצע הקרוב ל-$close. הציון — **לפני Z6** (base),
 * כי Z6 עצמו נגזר מהבחירה הזו. רק אזור עם קופסה. מחזיר את האינדקס, או null.
 */
function ts_fib_best_zone(array $zones, ?float $close): ?int
{
    $b = null; $bs = null; $bd = null;
    foreach ($zones as $i => $z) {
        if (empty($z['box'])) continue;
        $sc = $z['base'] ?? $z['score'];
        $d = $close === null ? 0.0 : abs(($z['lo'] + $z['hi']) / 2 - $close);
        if ($b === null || $sc > $bs || ($sc === $bs && $d < $bd - TS_FIB_EPS)) { $b = $i; $bs = $sc; $bd = $d; }
    }
    return $b;
}

/**
 * התרחיש על הרשת (22.5, F-2): A-B-X, קופסת שוויון לכל אזור (box), ו-Z6 (22.5ג). יעדי ה-100% של Z6 (הכרעת הבקרה, ביקורת
 * #1685 🟡1, לפי 22.5ב): של A-B-X, ו-T של הקופסה של **האזור המוביל בכל צד** (ts_fib_best_zone, לפי הסגירה של הרשת).
 * אזור שבתוכו נופל אחד מהם — z6 = true והציון + 1, פעם אחת. base — הציון של F-1.
 * לא משנה טווחים, גבולות או רמות (22.5ה). הרשת נבנית מנרות ≤ הנר האחרון, ולכן גם היעדים — אות בנר c רואה רק ≤ c − 1.
 */
function ts_fib_project(array $g, ?array $abx): array
{
    $g['abx'] = $abx;
    $hits = $abx ? [$abx['targets'][1]['price']] : [];   // 100%
    foreach (['support', 'resistance'] as $key) {
        $m0 = $g[$key]['move']['price'] ?? null;
        foreach ($g[$key]['zones'] as $i => $z) $g[$key]['zones'][$i]['box'] = $m0 === null ? null : ts_fib_box($z, (float)$m0);
        $top = ts_fib_best_zone($g[$key]['zones'], isset($g['close']) ? (float)$g['close'] : null);
        if ($top !== null) $hits[] = $g[$key]['zones'][$top]['box']['T'];
    }
    foreach (['support', 'resistance'] as $key) {
        foreach ($g[$key]['zones'] as $i => $z) {
            $base = $z['base'] ?? $z['score'];
            $in = (bool)array_filter($hits, fn($t) => $t >= $z['lo'] - TS_FIB_EPS && $t <= $z['hi'] + TS_FIB_EPS);
            $g[$key]['zones'][$i] = ['base' => $base, 'z6' => $in, 'score' => $base + (int)$in] + $z;
        }
    }
    return $g;
}

/** מחיר לטקסט של החלונית (18.1.6): עד שש ספרות משמעותיות, בלי אפסים מיותרים. התצוגה עצמה מעגלת לפי ה-tick של הנכס */
function ts_fib_px(float $v): string
{
    $a = abs($v);
    $d = $a >= 1 ? max(0, min(8, 5 - (int)floor(log10($a)))) : 8;
    $s = number_format($v, $d, '.', ',');
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

/** טקסט עד TS_OVERLAY_TEXT_MAX (200) תווים: חלקים מחוברים ב-" · "; מה שלא נכנס — "…" */
function ts_fib_fit(array $parts, int $max = 200): string
{
    $out = '';
    foreach ($parts as $i => $p) {
        $next = $out === '' ? $p : "$out · $p";
        if (mb_strlen($next) > $max - 2) return $out === '' ? mb_substr($p, 0, $max - 1) . '…' : "$out …";
        $out = $next;
    }
    return $out;
}

/** "61.8% של 200→150" — רמה ביחס לטווח שלה ($ranges — לפי id) */
function ts_fib_level_words(array $l, array $ranges): string
{
    $rg = $ranges[$l['range']] ?? null;
    $pct = rtrim(rtrim(number_format(100 * $l['r'], 1, '.', ''), '0'), '.') . '%';
    return $rg ? "$pct של " . ts_fib_px((float)$rg['start']) . '→' . ts_fib_px((float)$rg['end']) : $pct;
}

/**
 * החלוניות של הממצא (18.1.6, קריטריון 3): אזור — "אזור פיבונאצ'י · ציון N" והיחסים שלו; רמה בודדת — "רמה בודדת, חלשה (R8)".
 * ניסוח 6.6 — בלי "יעד", "קנה", "מכור".
 */
function ts_fib_zone_tip(array $z, array $side, string $word): array
{
    $ranges = array_column($side['ranges'], null, 'id');
    $parts = [count($z['levels']) . ' רמות מ-' . $z['ranges'] . " טווחים, באזור $word"];
    foreach ($z['levels'] as $l) $parts[] = ts_fib_level_words($l, $ranges);
    if (!empty($z['z6'])) $parts[] = 'ועוד 1 — הקרנה של 100% בתוך האזור (Z6)';
    return ['title' => "אזור פיבונאצ'י · ציון " . $z['score'], 'explain' => ts_fib_fit($parts)];
}

function ts_fib_single_tip(array $l, array $side): array
{
    $pct = rtrim(rtrim(number_format(100 * $l['r'], 1, '.', ''), '0'), '.') . '%';
    return ['title' => "רמה בודדת · $pct",
        'explain' => 'רמה בודדת, חלשה (R8): ' . ts_fib_level_words($l, array_column($side['ranges'], null, 'id')) . '. לא נכנסה לאף אזור חפיפה.'];
}

/**
 * התרחיש בגרף וביומן (22.5ד, 22.5ו, F-2) — פריטים של overlay שכולם **אחרי הנר האחרון** (מקווקו / שקוף), ו-projection.scenarios.
 * $x — אות בתוקף או null; $last — הנר האחרון; $tf — אורך נר בשניות; $bars — האופק (horizon_bars).
 *
 * - **כיוון** [שלנו]: אות בתוקף — בכיוון שלו; אחרת — בכיוון A-B-X; אין A-B-X — אין כיוון: רק קופסאות השוויון של שני הצדדים,
 *   דהויות, ובלי תרחיש ליומן ("אין תרחיש").
 * - **A-B-X** — רק כשהוא באותו כיוון. **קופסה:** של אזור האות; בלי אות — של האזור בציון הגבוה בצד של הכיוון (שוויון — הקרוב
 *   למחיר). יעד בתוך אזור חפיפה — מודגש (bold); יעד לבד — רמה בודדת, דהויה (single, R8).
 * - **ליומן** (primary): אזור החפיפה הקרוב בכיוון שבתוכו יש יעד; אחרת הפס 61.8%–100% של A-B-X (בלי A-B-X — של הקופסה
 *   [שלנו]). רמת הביטול — X של A-B-X; בלי A-B-X (אות בלבד) — הסטופ של האות [שלנו]. הטווח — עד סוף האופק.
 * מחזיר [items, scenarios].
 */
function ts_fib_scenario(array $g, ?array $x, array $last, int $tf, int $bars): array
{
    $dir = $x ? ($x['side'] === 'long' ? 'up' : 'down') : ($g['abx']['dir'] ?? null);
    $abx = $dir !== null && ($g['abx']['dir'] ?? null) === $dir ? $g['abx'] : null;
    $k = max(1, $bars - ($x['ago'] ?? 0));
    $t0 = $last['t'];
    $end = $t0 + $k * $tf;
    $close = (float)$last['c'];
    $zones = [...$g['support']['zones'], ...$g['resistance']['zones']];
    $inZone = fn(float $v) => (bool)array_filter($zones, fn($z) => $v >= $z['lo'] - TS_FIB_EPS && $v <= $z['hi'] + TS_FIB_EPS);
    $pct = fn(float $r) => rtrim(rtrim(number_format(100 * $r, 1, '.', ''), '0'), '.') . '%';
    // רמת הביטול לחלונית (18.1.6): X של A-B-X בכיוון; אחרת — הסטופ של האות; בלי כיוון — אין
    $upDir = $dir === 'up';
    $tinv = $abx ? ['price' => (float)$abx['x']['p'], 'side' => $upDir ? 'below' : 'above', 'rule' => 'סגירה ' . ($upDir ? 'מתחת ל-X' : 'מעל X') . ' של A-B-X']
        : ($x ? ['price' => (float)$x['stop'], 'side' => $x['side'] === 'long' ? 'below' : 'above', 'rule' => 'הסטופ של האות'] : null);
    $tip = fn(string $title, string $explain) => ['tip' => ['title' => $title, 'explain' => $explain] + ($tinv ? ['invalidation' => $tinv] : [])];
    $line = fn(float $v, string $text, bool $faint, string $explain, ?string $title = null) => ['kind' => 'polyline', 'style' => 'dashed', 'text' => $text,
        'points' => [['t' => $t0, 'price' => $v], ['t' => $end, 'price' => $v]]]
        + ($faint || !$inZone($v) ? ['single' => true] : ['bold' => true])
        + $tip($title ?? $text, $explain . ($faint || !$inZone($v) ? ' לבד — רמה חלשה (R8).' : ' בתוך אזור חפיפה — מודגשת.'));
    // האזור המוביל בצד — אותה בחירה כמו ב-Z6 (ts_fib_best_zone)
    $best = function (string $key) use ($g, $close) {
        $i = ts_fib_best_zone($g[$key]['zones'], $close);
        if ($i === null) return null;
        $b = $g[$key]['zones'][$i];
        return ['lo' => $b['lo'], 'hi' => $b['hi'], 'T' => $b['box']['T'], 'side' => $key];
    };
    $boxes = [];
    if ($x && $x['box_t'] !== null) $boxes[] = ['lo' => $x['zone'][0], 'hi' => $x['zone'][1], 'T' => $x['box_t'], 'side' => $x['side'] === 'long' ? 'support' : 'resistance'];
    elseif ($dir !== null) $boxes[] = $best($dir === 'up' ? 'support' : 'resistance');
    else { $boxes[] = $best('support'); $boxes[] = $best('resistance'); }
    $boxes = array_values(array_filter($boxes));
    $faint = $dir === null;

    $items = []; $targets = [];
    if ($abx) {
        foreach ($abx['targets'] as $t) {
            $items[] = $line($t['price'], 'A-B-X ' . $pct($t['r']), false, 'מ-X (' . ts_fib_px((float)$abx['x']['p']) . ') באורך ' . $pct($t['r'])
                . ' של A→B (' . ts_fib_px((float)$abx['a']['p']) . '→' . ts_fib_px((float)$abx['b']['p']) . ').', 'הקרנה A-B-X, ' . $pct($t['r']));
            $targets[] = $t['price'];
        }
    }
    foreach ($boxes as $b) {
        $box = ts_fib_box($b, 2 * (($b['lo'] + $b['hi']) / 2) - $b['T']);
        $boxWhy = 'הגובה מתחילת המהלך (' . ts_fib_px($box['m0']) . ') עד אמצע האזור (' . ts_fib_px($box['mid']) . '), מועתק מעבר לאזור באותו כיוון (22.5ב).';
        $items[] = ['kind' => 'zone', 't1' => $t0, 't2' => $end, 'p1' => $box['mid'], 'p2' => $box['T'], 'box' => $b['side'],
            'text' => 'קופסת שוויון — מאמצע האזור עד ההקרנה'] + ($faint ? ['single' => true] : []) + $tip('קופסת שוויון', $boxWhy);
        $items[] = $line($box['T'], 'קופסת שוויון 100%', $faint, 'סוף הקופסה: ' . $boxWhy);
        foreach ($box['levels'] as $l) $items[] = $line($l['price'], 'קופסת שוויון ' . $pct($l['r']), true, 'הקרנת ביניים בתוך הקופסה (P5).');
        $targets = [...$targets, $box['T'], ...array_column($box['levels'], 'price')];
    }
    if ($dir === null) return [$items, []];

    // ליומן (22.5ו): האזור הקרוב בכיוון שבתוכו יש יעד
    $up = $dir === 'up';
    $jz = null;
    foreach ($zones as $z) {
        if ($up ? !($z['lo'] > $close) : !($z['hi'] < $close)) continue;
        if (!array_filter($targets, fn($v) => $v >= $z['lo'] - TS_FIB_EPS && $v <= $z['hi'] + TS_FIB_EPS)) continue;
        if ($jz === null || ($up ? $z['lo'] < $jz['lo'] : $z['hi'] > $jz['hi'])) $jz = $z;
    }
    $main = $abx ? $abx['targets'][1]['price'] : ($boxes[0]['T'] ?? null);
    $head = [];
    if ($jz) $band = [$jz['lo'], $jz['hi'], 'אזור חפיפה שבתוכו הקרנה', 'אזור החפיפה הקרוב ' . ($up ? 'מעל' : 'מתחת ל') . 'מחיר שיש בתוכו הקרנה — ציון ' . $jz['score'] . '.'];
    elseif ($abx) $band = [$abx['targets'][0]['price'], $main, 'בין הקרנה 61.8% להקרנה 100% של A-B-X', 'אין אזור חפיפה עם הקרנה בכיוון — הפס בין שתי ההקרנות של A-B-X.'];
    elseif ($boxes) $band = [ts_fib_box($boxes[0], 2 * (($boxes[0]['lo'] + $boxes[0]['hi']) / 2) - $boxes[0]['T'])['levels'][2]['price'], $boxes[0]['T'], 'בין 61.8% ל-100% של קופסת השוויון',
        'אין A-B-X ואין אזור חפיפה עם הקרנה בכיוון — הפס בסוף קופסת השוויון של אזור האות.'];
    else $band = null;
    if ($band) $head[] = ['kind' => 'zone', 't1' => $t0, 't2' => $end, 'p1' => (float)min($band[0], $band[1]), 'p2' => (float)max($band[0], $band[1]),
        'primary' => true, 'text' => $band[2]] + $tip('אזור תרחיש — ' . $band[2], $band[3] . ' עד סוף האופק (' . $k . ' נרות).');
    $inv = $abx ? $abx['x']['p'] : ($x['stop'] ?? null);
    if ($inv !== null) $head[] = ['kind' => 'hline', 'price' => (float)$inv, 'role' => 'invalidation', 'primary' => true,
        'text' => $abx ? 'רמת הביטול — סגירה ' . ($up ? 'מתחת ל-X' : 'מעל X') . ' של A-B-X' : 'רמת הביטול — הסטופ של האות']
        + $tip('רמת ביטול', $abx ? 'סגירה ' . ($up ? 'מתחת' : 'מעל') . ' לנקודה X של A-B-X מבטלת את התרחיש (22.5ו).' : 'אין A-B-X בכיוון האות — התרחיש מתבטל בסטופ של האות.');
    $path = $main !== null ? [['t' => $t0, 'price' => $close], ['t' => $end, 'price' => (float)$main]] : [];
    if ($path) $head[] = ['kind' => 'polyline', 'style' => 'dashed', 'points' => $path]
        + $tip('קו התרחיש — ' . ($up ? 'מעלה' : 'מטה'), 'המסלול האפשרי לפי כללי השיטה, מהסגירה האחרונה אל ההקרנה של 100%' . ($abx ? ' של A-B-X.' : ' של קופסת השוויון.'));
    $label = 'לפי כללי השיטה — ' . ($up ? 'מעלה' : 'מטה') . ($abx ? ' (A-B-X)' : '') . ", עד $k נרות";
    return [[...$head, ...$items], $path ? [['id' => 's1', 'primary' => true, 'score' => null, 'points' => $path, 'label' => $label]] : []];
}

/**
 * S25 (F-3, 2.31) — מתרחב / מתכווץ / סימטרי, מקווי ה-50% של הטווחים המקוננים [שלנו — ההגדרה]. $mids — מהפנימי (i = 1) החוצה.
 * כולם בתוך $thr זה מזה → symmetric; עולים ממש → expanding; יורדים ממש → contracting. פחות משלושה, או לא מונוטוני — unknown [שלנו].
 */
function ts_fib_s25_classify(array $mids, float $thr): string
{
    if (count($mids) < 3) return 'unknown';
    if (max($mids) - min($mids) <= $thr + TS_FIB_EPS) return 'symmetric';
    $up = true; $down = true;
    for ($i = 1; $i < count($mids); $i++) {
        if (!($mids[$i] > $mids[$i - 1])) $up = false;
        if (!($mids[$i] < $mids[$i - 1])) $down = false;
    }
    return $up ? 'expanding' : ($down ? 'contracting' : 'unknown');
}

/** המילים של S25 בכרטיס (22.9, F-3 קריטריון 2) */
const TS_FIB_S25_WORDS = ['expanding' => 'מתרחב', 'contracting' => 'מתכווץ', 'symmetric' => 'סימטרי', 'unknown' => 'לא ידוע'];

/**
 * S25 על החלון (F-3 קריטריון 1). K — הנר עם הגוף הארוך ביותר במהלך (S15) של צד התמיכה (מ-M0 עד H); אם אין צד תמיכה — של צד
 * ההתנגדות, במראה [שלנו]. טווח מקונן i = 1..3: מהשפל המאושר ה-i לפני K ועד השיא המאושר ה-i אחרי K, במחירי גוף
 * (ts_fib_pivot_body). בהתנגדות — הכול במראה: "עולים" במראה = מתרחב. הסף — zone_atr × ATR של הרשת.
 * מידע בלבד (S26): לא נוגע ברשת, באזורים, בציונים או באותות. מחזיר {state, side, k_t, ranges: [[lo, hi]], mids}.
 */
function ts_fib_market(array $w, array $piv, ?array $g, array $p): array
{
    $out = ['state' => 'unknown', 'side' => null, 'k_t' => null, 'ranges' => [], 'mids' => []];
    if (!$g) return $out;
    $key = $g['support']['start'] ? 'support' : ($g['resistance']['start'] ? 'resistance' : null);
    if ($key === null || !$g[$key]['move']) return $out;
    $sign = $key === 'support' ? 1 : -1;
    $idx = array_flip(array_column($w, 't'));
    $i0 = $idx[$g[$key]['move']['t']] ?? null;
    $iH = $idx[$g[$key]['start']['t']] ?? null;
    if ($i0 === null || $iH === null || $i0 > $iH) return $out;
    $iK = $i0;
    for ($i = $i0; $i <= $iH; $i++) if (abs($w[$i]['c'] - $w[$i]['o']) > abs($w[$iK]['c'] - $w[$iK]['o'])) $iK = $i;
    $kt = $w[$iK]['t'];
    // במראה: "שפל לפני K" של ההתנגדות הוא שיא, ו"שיא אחרי K" — שפל
    $lowT = $sign === 1 ? 'L' : 'H';
    $before = array_values(array_filter(array_reverse($piv), fn($x) => $x['type'] === $lowT && $x['t'] < $kt));
    $after = array_values(array_filter($piv, fn($x) => $x['type'] !== $lowT && $x['t'] > $kt));
    $n = min(3, count($before), count($after));
    $ranges = []; $mids = [];
    for ($i = 0; $i < $n; $i++) {
        $a = ts_fib_pivot_body($w, $before[$i] + ['v' => 0.0]);
        $b = ts_fib_pivot_body($w, $after[$i] + ['v' => 0.0]);
        $ranges[] = [min($a, $b), max($a, $b)];
        $mids[] = $sign * ($a + $b) / 2;   // במראה — כך "עולים" = מתרחב בשני הצדדים
    }
    $state = ts_fib_s25_classify($mids, (float)$p['zone_atr'] * (float)$g['atr']);
    return ['state' => $state, 'side' => $key, 'k_t' => $kt, 'ranges' => $ranges, 'mids' => array_map(fn($m) => $sign * $m, $mids)];
}

/**
 * ואקום (Z10, F-3 קריטריון 3): שני אזורים סמוכים **באותו צד**, בלי אף רמה ביניהם (גם בודדת, משני הצדדים [שלנו]), ובמרחק
 * ≥ zone_max_atr × ATR (של הבנייה של הצד). מידע בלבד. מחזיר [{side, lo, hi}] — lo/hi: הגבולות שבין שני האזורים.
 */
function ts_fib_vacuums(?array $g, array $p): array
{
    if (!$g) return [];
    $levels = [];
    foreach (['support', 'resistance'] as $key) {
        foreach ($g[$key]['zones'] as $z) foreach ($z['levels'] as $l) $levels[] = $l['price'];
        foreach ($g[$key]['singles'] as $l) $levels[] = $l['price'];
    }
    $out = [];
    foreach (['support', 'resistance'] as $key) {
        $zs = $g[$key]['zones'];
        usort($zs, fn($a, $b) => $a['lo'] <=> $b['lo']);
        $min = (float)$p['zone_max_atr'] * (float)($g[$key]['atr'] ?? $g['atr']);
        for ($i = 1; $i < count($zs); $i++) {
            $lo = $zs[$i - 1]['hi']; $hi = $zs[$i]['lo'];
            if ($hi - $lo < $min - TS_FIB_EPS) continue;
            if (array_filter($levels, fn($v) => $v > $lo + TS_FIB_EPS && $v < $hi - TS_FIB_EPS)) continue;
            $out[] = ['side' => $key, 'lo' => $lo, 'hi' => $hi];
        }
    }
    return $out;
}

/**
 * נר אחד בזרימה של השיטה (step, ts_fib_grid_of): ATR, zigzag, החלון, נקודות המפנה שבחלון, שלוש האחרונות ל-A-B-X (k, בגוף —
 * לא נחתכות בחלון), והרשת — רק כש-$grid (ts_fib_grid_of מדלג על הנרות שאין להם השפעה).
 */
function ts_fib_advance(array $s, array $c, array $p, bool $grid = true): array
{
    $s['a'] = ts_ew_atr_step($s['a'], $c, (int)$p['atr_len']);
    [$s['z'], $pv] = ts_ew_zz_step($s['z'], $c, $s['a']['v'], (float)$p['pivot_atr_mult']);
    $s['w'][] = ['t' => $c['t'], 'o' => $c['o'], 'h' => $c['h'], 'l' => $c['l'], 'c' => $c['c'], 'a' => $s['a']['v']];
    $max = (int)$p['lookback_bars'];
    if (count($s['w']) > $max) $s['w'] = array_slice($s['w'], -$max);
    if ($pv) {
        $s['piv'][] = ['type' => $pv['type'], 't' => $pv['t'], 'ct' => $pv['ct']];
        $s['k'] = array_slice([...($s['k'] ?? []), ['type' => $pv['type'], 'p' => ts_fib_pivot_body($s['w'], $pv), 't' => $pv['t'], 'ct' => $pv['ct']]], -3);
    }
    $from = $s['w'][0]['t'];
    while ($s['piv'] && $s['piv'][0]['t'] < $from) array_shift($s['piv']);
    if ($grid) $s['g'] = ts_fib_grid($s['w'], $s['piv'], $p, $s['g'], $s['k'] ?? []);   // צד שנקודת ההתחלה שלו לא זזה — נעול (22.3(7), "ג")
    return $s;
}

/** המצב ההתחלתי של הזרימה (בלי x — האות, שהוא של השיטה) */
function ts_fib_init(): array
{
    return ['a' => ts_ew_atr_init(), 'z' => ts_ew_zz_init(), 'w' => [], 'piv' => [], 'k' => [], 'g' => null];
}

/**
 * הרשת אחרי הנר האחרון (לבדיקות ולסט הקבוע) — אותה זרימה כמו ב-step: חלון נע של lookback_bars, והרשת של כל נר נבנית על
 * של הקודם (נעילה). רק lookback_bars + 1 הנרות האחרונים מחושבים: נקודת ההתחלה תמיד בחלון, ולכן הרשת הנעולה נבנתה
 * בתוכם — מוקדם יותר אין לה השפעה. התוצאה זהה לריצה של השיטה מנר 0 (tests/fibonacci.php בודק).
 */
function ts_fib_grid_of(array $candles, array $p): ?array
{
    $s = ts_fib_init();
    $from = count($candles) - (int)$p['lookback_bars'] - 1;
    foreach ($candles as $k => $c) $s = ts_fib_advance($s, $c, $p, $k >= $from);
    return $s['g'];
}
