<?php
/**
 * גלי אליוט — המנוע (spec-stage-a 17.1, PR 4א/1). טהור: בלי מסד, בלי רשת, בלי שעון. float (עיקרון 6).
 *
 * שלושה חלקים:
 *   1. ATR של Wilder בזרימה — באותו סדר פעולות כמו ts_atr (lib/indicators.php), כך שהערך זהה.
 *   2. נקודות מפנה (pivots) — zigzag לפי pivot_atr_mult × ATR, בזרימה, **בלי הצצה לעתיד** (17.1.9 א):
 *      נקודה קיימת רק מנר האישור שלה.
 *   3. הכללים הקשיחים של דחף 1–6 (17.1.1), וחיפוש ספירות דחף על רצף נקודות המפנה (17.1.5 שלב 2).
 *      הציון, היחסים, הספירות החלופיות וההקרנה — PR 4א/2, לא כאן.
 *
 * כל מה שמסומן [שלנו] — הגדרה שלנו כדי שמחשב יוכל להריץ, לא טענה על הספר.
 */

declare(strict_types=1);

require_once __DIR__ . '/indicators.php';

/** סבולת להשוואות של טווח סגור (r = 0.382 בדיוק נכנס, גם כשה-float יוצא 0.38199999…) [שלנו] */
const TS_EW_EPS = 1e-9;

// ── 1. ATR בזרימה ───────────────────────────────────────

function ts_ew_atr_init(): array
{
    return ['n' => 0, 'sum' => 0.0, 'v' => null, 'pc' => null];
}

/** מעדכן את ה-ATR בנר אחד. ה-ATR של נר i מחושב מנרות ≤ i בלבד */
function ts_ew_atr_step(array $a, array $c, int $len): array
{
    $tr = ts_tr($c['h'], $c['l'], $a['pc']);
    if ($a['n'] < $len) {
        $a['sum'] += $tr;
        if ($a['n'] === $len - 1) $a['v'] = $a['sum'] / $len;
    } else {
        $a['v'] = ($a['v'] * ($len - 1) + $tr) / $len;
    }
    $a['n']++;
    $a['pc'] = $c['c'];
    return $a;
}

// ── 2. נקודות מפנה (zigzag) ─────────────────────────────

/**
 * מצב ה-zigzag. dir: null (עוד לא אושרה אף נקודה), 'H' (הקיצון הפתוח הוא שיא), 'L' (שפל).
 * ext — הקיצון הפתוח {v, t, atr}; opp — הקיצון ההפוך הטוב ביותר מאז ext (הנרות שאחריו בלבד).
 * בהתחלה (dir = null) עוקבים אחרי שני הצדדים: hi/lo, ו-lo_after_hi / hi_after_lo.
 */
function ts_ew_zz_init(): array
{
    return ['dir' => null, 'ext' => null, 'opp' => null, 'hi' => null, 'lo' => null, 'loa' => null, 'hia' => null];
}

/**
 * נר אחד, אחרי שנסגר. $atr — ה-ATR של הנר הזה (null בחימום: ה-zigzag מתחיל רק מהנר הראשון שיש לו ATR [שלנו]).
 * מחזיר [מצב, נקודה שאושרה בנר הזה | null]. נקודה: {type: H|L, v, t (נר הקיצון), atr (ATR בנר הקיצון), ct (נר האישור)}.
 *
 * הכללים (17.1.9 א): שיא H בנר p מאושר בנר הראשון c > p שבו l_c ≤ H − mult × ATR(p). שפל — במראה (h_c ≥ L + סף).
 * עד האישור הקיצון יכול להתעדכן (שיא גבוה יותר — במובן החזק — מחליף אותו). בכל נר נבדק רק האישור של הקיצון הפתוח
 * הנוכחי, אחד. אחרי אישור, הקיצון הפתוח הבא = ההפוך הטוב ביותר מאז הנקודה שאושרה (כולל נר האישור).
 */
function ts_ew_zz_step(array $z, array $c, ?float $atr, float $mult): array
{
    if ($atr === null) return [$z, null];
    $t = $c['t'];
    $mk = fn(float $v) => ['v' => $v, 't' => $t, 'atr' => $atr];

    if ($z['dir'] === null) {
        // התחלה: עוד לא ידוע אם הנקודה הראשונה שיא או שפל
        if ($z['hi'] === null || $c['h'] > $z['hi']['v']) { $z['hi'] = $mk($c['h']); $z['loa'] = null; }
        elseif ($z['loa'] === null || $c['l'] < $z['loa']['v']) $z['loa'] = $mk($c['l']);
        if ($z['lo'] === null || $c['l'] < $z['lo']['v']) { $z['lo'] = $mk($c['l']); $z['hia'] = null; }
        elseif ($z['hia'] === null || $c['h'] > $z['hia']['v']) $z['hia'] = $mk($c['h']);

        $hiOk = $z['hi']['t'] < $t && $c['l'] <= $z['hi']['v'] - $mult * $z['hi']['atr'];
        $loOk = $z['lo']['t'] < $t && $c['h'] >= $z['lo']['v'] + $mult * $z['lo']['atr'];
        if ($hiOk && $loOk) {   // שניהם באותו נר — הקיצון המוקדם יותר אושר קודם [שלנו]
            if ($z['lo']['t'] < $z['hi']['t']) $hiOk = false; else $loOk = false;
        }
        if ($hiOk) {
            $p = ['type' => 'H'] + $z['hi'] + ['ct' => $t];
            return [['dir' => 'L', 'ext' => $z['loa'], 'opp' => null] + ts_ew_zz_init(), $p];
        }
        if ($loOk) {
            $p = ['type' => 'L'] + $z['lo'] + ['ct' => $t];
            return [['dir' => 'H', 'ext' => $z['hia'], 'opp' => null] + ts_ew_zz_init(), $p];
        }
        return [$z, null];
    }

    if ($z['dir'] === 'H') {
        if ($c['h'] > $z['ext']['v']) {
            $z['ext'] = $mk($c['h']);
            $z['opp'] = null;
            return [$z, null];
        }
        if ($z['opp'] === null || $c['l'] < $z['opp']['v']) $z['opp'] = $mk($c['l']);
        if ($c['l'] <= $z['ext']['v'] - $mult * $z['ext']['atr']) {
            $p = ['type' => 'H'] + $z['ext'] + ['ct' => $t];
            return [['dir' => 'L', 'ext' => $z['opp'], 'opp' => null] + ts_ew_zz_init(), $p];
        }
        return [$z, null];
    }

    // dir === 'L' — במראה
    if ($c['l'] < $z['ext']['v']) {
        $z['ext'] = $mk($c['l']);
        $z['opp'] = null;
        return [$z, null];
    }
    if ($z['opp'] === null || $c['h'] > $z['opp']['v']) $z['opp'] = $mk($c['h']);
    if ($c['h'] >= $z['ext']['v'] + $mult * $z['ext']['atr']) {
        $p = ['type' => 'L'] + $z['ext'] + ['ct' => $t];
        return [['dir' => 'H', 'ext' => $z['opp'], 'opp' => null] + ts_ew_zz_init(), $p];
    }
    return [$z, null];
}

/** כל נקודות המפנה שאושרו על הנרות (לבדיקות ולשכבה החזותית): ATR(atr_len) + zigzag(mult) */
function ts_ew_pivots(array $candles, float $mult = 3.0, int $atrLen = 14): array
{
    $a = ts_ew_atr_init();
    $z = ts_ew_zz_init();
    $out = [];
    foreach ($candles as $c) {
        $a = ts_ew_atr_step($a, $c, $atrLen);
        [$z, $p] = ts_ew_zz_step($z, $c, $a['v'], $mult);
        if ($p) $out[] = $p;
    }
    return $out;
}

// ── 3. הכללים הקשיחים של דחף (17.1.1) ──────────────────

/** ברירות המחדל של הספירה (17.1.6) — רק מה שהכללים הקשיחים צריכים */
const TS_EW_RULE_DEFAULTS = ['overlap_tolerance' => 0.0, 'allow_diagonal' => true];

/** אחוז השינוי של גל (סקאלה לוגריתמית — המדד של כלל 3), תמיד חיובי לגל בכיוון הדחף */
function ts_ew_pct(float $from, float $to): float
{
    return abs($to / $from - 1);
}

/**
 * בודק ספירת דחף מול הכללים הקשיחים 1–6. מחזיר את מספרי הכללים שהופרו (ריק = הספירה תקפה).
 *
 * $count: ['dir' => 'up'|'down', 'p' => [p0, p1, …] (3 עד 6 מחירים: תחילת גל 1, סוף 1, סוף 2 …),
 *          'diagonal' => bool, 'extended' => [מספרי הגלים שסומנו מורחבים]]
 * ספירה חלקית נבדקת רק בכללים שיש להם כבר את הנקודות.
 * $opt: overlap_tolerance — שבר מטווח גל 1 שגל 4 רשאי לחדור (0–0.25, #1342); allow_diagonal.
 *
 * ההגדרות [שלנו] במקרי הקצה: כלל 3 — גל 3 נפסל רק כשהוא קצר **ממש** משני האחרים (שוויון לקצר — לא נפסל);
 * כלל 5 — חדירה ≤ סבולת לא נפסלת (נגיעה בדיוק בסוף גל 1, עם סבולת 0 — לא נפסלת).
 */
function ts_ew_impulse_violations(array $count, array $opt = []): array
{
    $opt += TS_EW_RULE_DEFAULTS;
    $tol = (float)$opt['overlap_tolerance'];
    if ($tol < 0 || $tol > 0.25) throw new InvalidArgumentException('overlap_tolerance מחוץ לטווח 0–0.25');
    $p = array_values(array_map('floatval', $count['p']));
    if (count($p) < 3 || count($p) > 6) throw new InvalidArgumentException('ספירה של 3 עד 6 נקודות');
    $d = ($count['dir'] ?? 'up') === 'down' ? -1 : 1;
    $diag = !empty($count['diagonal']) && $opt['allow_diagonal'];
    // במראה: בדחף יורד כופלים ב-−1, וכל ההשוואות הן של דחף עולה
    $x = array_map(fn($v) => $d * $v, $p);
    $bad = [];
    if ($x[2] <= $x[0]) $bad[] = 1;                                   // 1: גל 2 מתקן פחות מ-100% מגל 1
    if (isset($x[3]) && $x[3] <= $x[1]) $bad[] = 2;                   // 2: גל 3 עובר את סוף גל 1
    if (isset($x[5])) {                                                // 3: גל 3 אינו הקצר — באחוזים
        $w1 = ts_ew_pct($p[0], $p[1]); $w3 = ts_ew_pct($p[2], $p[3]); $w5 = ts_ew_pct($p[4], $p[5]);
        if ($w3 < $w1 && $w3 < $w5) $bad[] = 3;
    }
    if (isset($x[4]) && $x[4] <= $x[2]) $bad[] = 4;                   // 4: גל 4 מתקן פחות מ-100% מגל 3
    if (isset($x[4]) && !$diag) {                                      // 5: אין חפיפה של 4 עם 1 (חוץ מאלכסוני)
        $range1 = $x[1] - $x[0];
        $pen = $range1 > 0 ? ($x[1] - $x[4]) / $range1 : 0.0;
        if ($pen > $tol + TS_EW_EPS) $bad[] = 5;
    }
    $ext = array_map('intval', $count['extended'] ?? []);
    if (!array_diff([1, 3, 5], $ext)) $bad[] = 6;                     // 6: לא שלושת 1, 3, 5 מורחבים
    sort($bad);
    return $bad;
}

/**
 * חיפוש ספירות דחף (17.1.5 שלב 2): כל רצף של 6 נקודות מפנה עוקבות שמתחיל בשפל (דחף עולה) או בשיא (יורד),
 * ושעומד בכללים הקשיחים. מחזיר [{dir, from_t, pivots[6], violations: []}] — בסדר הזמן. דטרמיניסטי.
 * הציון, היחסים והספירות החלופיות (17.1.5 שלב 3) — PR 4א/2.
 */
function ts_ew_impulse_counts(array $pivots, array $opt = []): array
{
    $out = [];
    $n = count($pivots);
    for ($i = 0; $i + 5 < $n; $i++) {
        $w = array_slice($pivots, $i, 6);
        $dir = $w[0]['type'] === 'L' ? 'up' : 'down';
        $v = ts_ew_impulse_violations(['dir' => $dir, 'p' => array_column($w, 'v')], $opt);
        if (!$v) $out[] = ['dir' => $dir, 'from_t' => $w[0]['t'], 'pivots' => $w, 'violations' => []];
    }
    return $out;
}

// ── 4. ציון, ספירות חלופיות, הקרנה ורמת ביטול → overlay (17.1.3–17.1.7, PR 4א/2) ──
//
// הספירה היא תמיד של **הגלים האחרונים** — מנקודת מפנה שממנה מתחיל דחף ועד הנקודה המאושרת האחרונה. לכל k = 2…5
// (מספר הגלים שהושלמו) לוקחים את k+1 הנקודות האחרונות: מתחילה בשפל — דחף עולה; בשיא — יורד. ספירה שמפרה כלל קשיח
// (17.1.1) נפסלת. לכל ספירה שנשארה: ציון מהיחסים (17.1.4), הגל הבא (אזור יעד, טווח זמן) ורמת ביטול (17.1.5 שלב 4).
// רק נקודות מאושרות — בלי הצצה לעתיד, ודטרמיניסטי.
//
// [שלנו] — כל מה שלמטה שאינו כתוב בספר: נוסחת הציון, המשקלים, הסבולת ההדרגתית, טווחי הפרמטרים, הטווח בזמן ואזור התיקון.
// הנחיות שאינן נמדדות כאן (חילופיות, ערוץ ו-throw-over, נפח, עומק בתוך גל 4 של הדרגה הקטנה) — לא בציון; הן מוחזרות
// ב-"check" כדי שיוצגו כ"בדוק בעצמך". אלכסוני ו-flat לא מזוהים (רק דחפים), ולכן allow_diagonal ו-allow_running_flat
// משפיעים רק על ספירה שסומנה כך.

/** הפרמטרים של השכבה החזותית (17.1.6). ברירות המחדל מהאפיון; הטווחים — [שלנו], חוץ מ-overlap_tolerance (#1342) */
const TS_EW_PARAMS = [
    'pivot_mode' => ['default' => 'atr', 'options' => ['atr']],   // percent — לא מוגדר סף באפיון; ראו README
    'pivot_atr_mult' => ['default' => 3.0, 'min' => 1, 'max' => 10],
    'atr_len' => ['default' => 14, 'min' => 5, 'max' => 50, 'int' => true],
    'max_counts' => ['default' => 3, 'min' => 1, 'max' => 5, 'int' => true],
    'fib_tolerance' => ['default' => 0.05, 'min' => 0.01, 'max' => 0.2],
    'forecast_bars' => ['default' => 30, 'min' => 5, 'max' => 200, 'int' => true],
    'allow_diagonal' => ['default' => true, 'bool' => true],
    'overlap_tolerance' => ['default' => 0.0, 'min' => 0, 'max' => 0.25],
    'allow_running_flat' => ['default' => false, 'bool' => true],
    'min_score' => ['default' => 0.5, 'min' => 0.5, 'max' => 0.95],   // מתחת ל-0.5 אין מילה לציון (✅16)
];

/** פרמטרים: ברירות מחדל + מה שנמסר, בטווח. מחוץ לטווח או מפתח לא מוכר → InvalidArgumentException */
function ts_ew_params(array $in = []): array
{
    if ($extra = array_diff(array_keys($in), array_keys(TS_EW_PARAMS))) throw new InvalidArgumentException('פרמטר לא מוכר: ' . implode(', ', $extra));
    $out = [];
    foreach (TS_EW_PARAMS as $k => $d) {
        $v = $in[$k] ?? $d['default'];
        if (isset($d['options'])) {
            if (!in_array($v, $d['options'], true)) throw new InvalidArgumentException("$k: " . implode(' / ', $d['options']) . ' בלבד');
        } elseif (!empty($d['bool'])) {
            if (!is_bool($v)) throw new InvalidArgumentException("$k: true/false");
        } else {
            if (!is_int($v) && !is_float($v)) throw new InvalidArgumentException("$k: מספר");
            if ($v < $d['min'] || $v > $d['max']) throw new InvalidArgumentException("$k: מחוץ לטווח {$d['min']}–{$d['max']}");
            $v = !empty($d['int']) ? (int)$v : (float)$v;
        }
        $out[$k] = $v;
    }
    return $out;
}

/** משקל ליחס: כפולות (גלים באותו כיוון) — 2; תיקונים (retracement) — 1 (17.1.4: "הכפולות אמינות יותר") [המספרים — שלנו] */
const TS_EW_W_MULTIPLE = 2.0;
const TS_EW_W_RETRACE = 1.0;

/**
 * התאמה של ערך ליחס הקרוב ביותר מתוך הרשימה: 1 בתוך הסבולת (|x/i − 1| ≤ tol), יורד בקו ישר עד 0 בשלוש פעמים הסבולת
 * [שלנו]. מחזיר [התאמה 0..1, היחס הקרוב].
 */
function ts_ew_fit(float $x, array $ideals, float $tol): array
{
    $best = [0.0, $ideals[0]];
    foreach ($ideals as $i) {
        $dev = abs($x / $i - 1);
        $m = max(0.0, min(1.0, 1 - ($dev - $tol) / (2 * $tol)));
        if ($m > $best[0] || ($m === $best[0] && abs($x - $i) < abs($x - $best[1]))) $best = [$m, $i];
    }
    return $best;
}

/** הציון: Σ משקל × התאמה ÷ Σ משקל, על היחסים שיש להם נקודות. בלי יחסים — 0 */
function ts_ew_score(array $relations): float
{
    $w = 0.0; $s = 0.0;
    foreach ($relations as $r) { $w += $r['weight']; $s += $r['weight'] * $r['fit']; }
    return $w > 0 ? $s / $w : 0.0;
}

/** הציון במילים (✅16): ≥ 0.75 — "התאמה גבוהה"; 0.5 ≤ ציון < 0.75 — "התאמה בינונית"; אחרת null (לא מוצג) */
function ts_ew_score_words(float $score): ?string
{
    return $score >= 0.75 ? 'התאמה גבוהה' : ($score >= 0.5 ? 'התאמה בינונית' : null);
}

/** היחסים של ספירה בת k גלים (17.1.4). p — מחירי הנקודות, d — הכיוון (+1/−1) */
function ts_ew_relations(array $p, int $k, float $tol): array
{
    $rel = [];
    $add = function (string $id, string $text, float $x, array $ideals, float $w) use (&$rel, $tol) {
        [$fit, $ideal] = ts_ew_fit($x, $ideals, $tol);
        $rel[] = ['id' => $id, 'text' => $text, 'value' => $x, 'ideal' => $ideal, 'fit' => $fit, 'weight' => $w];
    };
    $len = fn(int $a, int $b) => abs($p[$b] - $p[$a]);
    // גל 2: 50% או 61.8% מגל 1 (חד)
    $add('w2', 'גל 2 מתקן 50% או 61.8% מגל 1', $len(1, 2) / $len(0, 1), [0.5, 0.618], TS_EW_W_RETRACE);
    if ($k >= 3) {   // גל 3 = 1, 1.618 או 2.618 × גל 1, באחוזים
        $add('w3', 'גל 3 = 1, 1.618 או 2.618 × גל 1 (באחוזים)', ts_ew_pct($p[2], $p[3]) / ts_ew_pct($p[0], $p[1]), [1.0, 1.618, 2.618], TS_EW_W_MULTIPLE);
    }
    if ($k >= 4) {
        $add('w4', 'גל 4 מתקן 38.2% מגל 3', $len(3, 4) / $len(2, 3), [0.382], TS_EW_W_RETRACE);
        $add('w4w2', 'גל 4 מול גל 2 — שוויון או יחס פיבונאצ\'י', $len(3, 4) / $len(1, 2), [0.618, 1.0, 1.618], TS_EW_W_MULTIPLE);
    }
    if ($k >= 5) {
        // לא מורחב: 0.382 / 0.618 מהמרחק 1→3, או שוויון לגל 1; מורחב: 1.618 × (1→3), מסוף גל 4 (אומת, #1342)
        $w5 = $len(4, 5);
        [$fa, $ia] = ts_ew_fit($w5 / $len(0, 1), [1.0], $tol);
        [$fb, $ib] = ts_ew_fit($w5 / $len(0, 3), [0.382, 0.618, 1.618], $tol);
        $b = $fb > $fa;
        $rel[] = ['id' => 'w5', 'text' => $b ? 'גל 5 = ' . $ib . ' × המרחק 1→3' : 'גל 5 שווה לגל 1',
            'value' => $b ? $w5 / $len(0, 3) : $w5 / $len(0, 1), 'ideal' => $b ? $ib : $ia, 'fit' => max($fa, $fb), 'weight' => TS_EW_W_MULTIPLE];
    }
    return $rel;
}

/**
 * הגל הבא אחרי ספירה בת k גלים: {wave, zone: [נמוך, גבוה], center, rule (הכלל או היחס שממנו נגזר), invalidation, bars}.
 * $bars — אורכי הגלים שהושלמו בנרות (bars[1] = גל 1 …). הזמן: [שלנו] — גל דחף כמו גל 1, גל תיקון כמו גל 2,
 * והתיקון אחרי 5 — כמו גלים 2 + 4; תמיד 1…forecast_bars.
 */
function ts_ew_next(array $p, int $k, array $bars, array $prm): array
{
    $d = $p[1] > $p[0] ? 1 : -1;
    $tol = $prm['fib_tolerance'];
    $len = fn(int $a, int $b) => abs($p[$b] - $p[$a]);
    switch ($k) {
        case 2:   // גל 3: 1–1.618 × גל 1, באחוזים, מסוף גל 2
            $g1 = ts_ew_pct($p[0], $p[1]);
            $a = $p[2] * (1 + $d * 1.0 * $g1); $b = $p[2] * (1 + $d * 1.618 * $g1);
            $out = ['wave' => '3', 'zone' => [min($a, $b), max($a, $b)], 'center' => $b, 'rule' => 'גל 3 = 1–1.618 × גל 1 (באחוזים, לפי הספר)',
                'invalidation' => $p[0], 'invalidation_rule' => 'גל 2 לא חוזר מעבר לתחילת גל 1 (כלל 1)', 'bars' => $bars[1]];
            break;
        case 3:   // גל 4: 38.2% מגל 3, ± הסבולת
            $w3 = $len(2, 3);
            $a = $p[3] - $d * (0.382 - $tol) * $w3; $b = $p[3] - $d * (0.382 + $tol) * $w3;
            $out = ['wave' => '4', 'zone' => [min($a, $b), max($a, $b)], 'center' => $p[3] - $d * 0.382 * $w3, 'rule' => 'גל 4 מתקן בדרך כלל 38.2% מגל 3 (לפי הספר)',
                'invalidation' => $p[1] + (-$d) * $prm['overlap_tolerance'] * $len(0, 1), 'invalidation_rule' => 'גל 4 לא חופף לגל 1 (כלל 5)', 'bars' => $bars[2]];
            break;
        case 4:   // גל 5: שוויון לגל 1, או 0.618 × (1→3), מסוף גל 4
            $a = $p[4] + $d * $len(0, 1); $b = $p[4] + $d * 0.618 * $len(0, 3);
            $out = ['wave' => '5', 'zone' => [min($a, $b), max($a, $b)], 'center' => $a, 'rule' => 'גל 5 = גל 1, או 0.618 × המרחק 1→3 (לפי הספר)',
                'invalidation' => $p[1] + (-$d) * $prm['overlap_tolerance'] * $len(0, 1), 'invalidation_rule' => 'גל 4 לא חופף לגל 1 (כלל 5)', 'bars' => $bars[1]];
            break;
        default:  // 5 — תיקון A-B-C אל טווח גל 4 [האזור — שלנו, על בסיס ההנחיה "עומק תיקון"]
            $out = ['wave' => 'A-B-C', 'zone' => [min($p[3], $p[4]), max($p[3], $p[4])], 'center' => ($p[3] + $p[4]) / 2, 'rule' => 'התיקון אחרי דחף מגיע בדרך כלל לטווח גל 4 (הנחיה)',
                'invalidation' => $p[5], 'invalidation_rule' => 'מחיר מעבר לסוף גל 5 — הדחף עוד לא הסתיים', 'bars' => $bars[2] + $bars[4]];
    }
    $out['bars'] = max(1, min((int)$prm['forecast_bars'], (int)$out['bars']));
    return $out;
}

/**
 * הספירות על נקודות המפנה המאושרות (חדשה קודם): עד max_counts, ציון ≥ min_score, ממוינות לפי ציון ואז k (יותר גלים
 * קודם). $tf — אורך הנר בשניות (לטווח הזמן). כל ספירה: {id, dir, k, pivots, labels, score, words, relations, next}.
 */
function ts_ew_counts(array $pivots, int $tf, array $prm): array
{
    $n = count($pivots);
    $all = [];
    for ($k = 5; $k >= 2; $k--) {
        if ($n < $k + 1) continue;
        $w = array_slice($pivots, $n - $k - 1);
        $dir = $w[0]['type'] === 'L' ? 'up' : 'down';
        $p = array_map(fn($x) => (float)$x['v'], $w);
        if (ts_ew_impulse_violations(['dir' => $dir, 'p' => $p], $prm)) continue;
        $rel = ts_ew_relations($p, $k, $prm['fib_tolerance']);
        $score = ts_ew_score($rel);
        if ($score < $prm['min_score']) continue;
        $bars = [0];
        for ($j = 1; $j <= $k; $j++) $bars[$j] = max(1, intdiv($w[$j]['t'] - $w[$j - 1]['t'], $tf));
        $all[] = ['dir' => $dir, 'k' => $k, 'pivots' => $w, 'labels' => array_slice(['0', '1', '2', '3', '4', '5'], 0, $k + 1),
            'score' => $score, 'words' => ts_ew_score_words($score), 'relations' => $rel, 'next' => ts_ew_next($p, $k, $bars, $prm)];
    }
    usort($all, fn($a, $b) => [$b['score'], $b['k']] <=> [$a['score'], $a['k']]);
    $all = array_slice($all, 0, $prm['max_counts']);
    foreach ($all as $i => &$c) $c['id'] = 'c' . ($i + 1);
    unset($c);
    return $all;
}

/** ההנחיות שהמחשב כאן לא מודד — מוצגות כ"בדוק בעצמך" (17.1.3) */
const TS_EW_CHECK = [
    'חילופיות בין גל 2 לגל 4 (חד מול צדדי)',
    'ערוץ ו-throw-over — קו דרך 2 ו-4, ומקביל דרך 3',
    'נפח — בגל 5 בדרך כלל נמוך מגל 3',
    'המבנה הפנימי — חמישה תתי-גלים בדחף, אלכסוני או תיקון',
    'הדרגה — האם הספירה היא של הדרגה שבחרת',
];

/**
 * השכבה החזותית של אליוט (17.1.7, החוזה ב-17.2.3): {as_of, range, items[], projection{bars, scenarios[]}, counts, check}.
 * $candles — נרות סגורים (float), $tf — אורך הנר בשניות. הזמנים העתידיים — t + k·tf (17.2.2). float לתצוגה בלבד.
 * כל פריט מסומן ב-scenario (מזהה הספירה): הספירה המובילה — primary: true; החלופות — false (עמומות, 17.1.7).
 */
function ts_ew_overlay(array $candles, int $tf, array $in = []): array
{
    if ($tf <= 0) throw new InvalidArgumentException('מסגרת זמן לא תקינה');
    $prm = ts_ew_params($in);
    $pivots = ts_ew_pivots($candles, $prm['pivot_atr_mult'], $prm['atr_len']);
    $counts = ts_ew_counts($pivots, $tf, $prm);
    $last = $candles ? $candles[count($candles) - 1]['t'] : null;
    $items = []; $scen = [];
    foreach ($counts as $i => $c) {
        $sid = $c['id']; $primary = $i === 0;
        $tag = ['scenario' => $sid, 'primary' => $primary];
        $pts = array_map(fn($x) => ['t' => $x['t'], 'price' => (float)$x['v']], $c['pivots']);
        $items[] = ['kind' => 'polyline', 'points' => $pts, 'style' => 'solid', 'role' => 'count'] + $tag;
        foreach ($c['pivots'] as $j => $x) {
            if ($j === 0) continue;
            $items[] = ['kind' => 'label', 't' => $x['t'], 'price' => (float)$x['v'], 'text' => $c['labels'][$j]] + $tag;
        }
        $nx = $c['next'];
        $end = end($c['pivots']);
        $t2 = $end['t'] + $nx['bars'] * $tf;
        $items[] = ['kind' => 'zone', 't1' => $end['t'] + $tf, 't2' => $t2, 'p1' => $nx['zone'][0], 'p2' => $nx['zone'][1],
            'text' => 'אזור לגל ' . $nx['wave'] . ' — ' . $nx['rule']] + $tag;
        $items[] = ['kind' => 'hline', 'price' => (float)$nx['invalidation'], 'role' => 'invalidation',
            'text' => 'רמת ביטול — ' . $nx['invalidation_rule']] + $tag;
        $proj = [['t' => $end['t'], 'price' => (float)$end['v']], ['t' => $t2, 'price' => $nx['center']]];
        $items[] = ['kind' => 'polyline', 'points' => $proj, 'style' => 'dashed', 'role' => 'projection'] + $tag;
        $scen[] = ['id' => $sid, 'score' => $c['score'], 'words' => $c['words'], 'primary' => $primary,
            'label' => $c['words'] . ' · ' . ($c['dir'] === 'up' ? 'דחף עולה' : 'דחף יורד') . ', ' . $c['k'] . ' גלים — הבא: ' . $nx['wave'],
            'points' => $proj];
    }
    return ['as_of' => $last, 'range' => ['from' => $candles ? $candles[0]['t'] : null, 'to' => $last], 'items' => $items,
        'projection' => ['bars' => $prm['forecast_bars'], 'scenarios' => $scen], 'counts' => $counts, 'check' => TS_EW_CHECK];
}
