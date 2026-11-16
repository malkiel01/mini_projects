<?php
/**
 * פיבונאצ'י — אזורי חפיפה של נסיגות (spec-stage-a פרק 22, F-1). שיטה שלנו על בסיס כללי הספר של Constance Brown,
 * *Fibonacci Analysis* — הכללים בניסוח שלנו, עם עמודים: team/handoff/fibonacci-book-check.md. המנוע — lib/fibonacci.php.
 *
 * הממצא (22.3–22.4): נקודת התחלה אחת לכל צד, הטווחים, הרמות 38.2 / 50 / 61.8, אזורי החפיפה (ציון) והרמות הבודדות.
 * האות (22.6 — הספר לא נותן כלל כניסה מכני, הרגע [שלנו, פ10]): לונג בנר c, אחרי שנסגר, כשאזור תמיכה בציון ≥ min_score
 * שחושב מנרות ≤ c − 1 נגע (l_c ≤ הגבול העליון) והנר נסגר מעליו. סטופ מתחת לאזור (M1), יעד — האזור הבא (M2), יחס ≥ min_rr.
 *
 * **לא בפרוסה הזו (F-1):** התרחיש ויעדי ההקרנה (22.5, F-2), מתרחב/מתכווץ וואקום (F-3), הרשימה של עמ' 102, ומתנד (O1, פ7 —
 * לכן אין פרמטר rsi_filter: פרמטר שלא עושה כלום היה מטעה את מי שמשכלל).
 *
 * המצב (JSON, נשמר בסוף הבדיקה לאחור): ATR ו-zigzag (lib/elliott.php — אותה פונקציה), חלון של lookback_bars נרות,
 * נקודות המפנה שבחלון, והרשת האחרונה (g) — שחושבה מנרות ≤ הנר האחרון. האות בנר c נבדק **לפני** שהנר נכנס לרשת —
 * כך הוא רואה רק נרות ≤ c − 1 (6.2 א).
 */

declare(strict_types=1);

require_once __DIR__ . '/base.php';
require_once dirname(__DIR__) . '/fibonacci.php';

final class TsMethodFibonacci extends TsMethod
{
    public function meta(): array
    {
        return [
            'id' => 'fibonacci',
            'name' => "פיבונאצ'י",
            'source' => 'Constance Brown, Fibonacci Analysis (Bloomberg Market Essentials)',
            'label' => 'שיטה שלנו על בסיס כללי הספר (Constance Brown)',
            'sample' => false,
            'markets' => ['crypto', 'stocks', 'forex', 'indices', 'commodities', 'futures'],
            'timeframes' => ['h1', 'h4', 'd1', 'w1'],   // כמו אליוט (✅18)
            // ours: true — "הגדרה שלנו" (22.10). אף פרמטר כאן אינו מספר מהספר: היחסים עצמם קבועים (R1) ואינם פרמטר
            'params' => [
                'pivot_atr_mult' => ['label' => 'רגישות נקודות המפנה (× ATR)', 'default' => 3, 'min' => 1, 'max' => 10, 'int' => false, 'ours' => true],
                'atr_len' => ['label' => 'אורך ATR', 'default' => 14, 'min' => 5, 'max' => 50, 'int' => true, 'ours' => true],
                'lookback_bars' => ['label' => 'חלון החיפוש (נרות)', 'default' => 250, 'min' => 100, 'max' => 1000, 'int' => true, 'ours' => true],
                'max_ranges' => ['label' => 'מספר טווחים מרבי', 'default' => 5, 'min' => 2, 'max' => 6, 'int' => true, 'ours' => true],
                'strong_atr' => ['label' => 'נר חזק — גוף (× ATR)', 'default' => 2, 'min' => 1, 'max' => 4, 'int' => false, 'ours' => true],
                'close_atr' => ['label' => 'נקודות סיום קרובות (× ATR)', 'default' => 0.5, 'min' => 0.1, 'max' => 2, 'int' => false, 'ours' => true],
                'zone_atr' => ['label' => 'מרחק רמות באזור (× ATR)', 'default' => 0.5, 'min' => 0.1, 'max' => 2, 'int' => false, 'ours' => true],
                'zone_max_atr' => ['label' => 'רוחב אזור מרבי (× ATR)', 'default' => 1, 'min' => 0.5, 'max' => 3, 'int' => false, 'ours' => true],
                'min_score' => ['label' => 'ציון אזור מינימלי לאות', 'default' => 3, 'min' => 2, 'max' => 8, 'int' => true, 'ours' => true],
                'stop_atr' => ['label' => 'סטופ מתחת לאזור (× ATR)', 'default' => 0.25, 'min' => 0, 'max' => 1, 'int' => false, 'ours' => true],
                'min_rr' => ['label' => 'יחס סיכוי-סיכון מינימלי', 'default' => 2, 'min' => 1, 'max' => 5, 'int' => false, 'ours' => true],
                'horizon_bars' => ['label' => 'אופק (נרות)', 'default' => 60, 'min' => 10, 'max' => 300, 'int' => true, 'ours' => true],
            ],
            'horizon_bars' => 60,
            'warmup' => 300,
            'levels' => 'absolute',   // 6.2(ח): הסטופ והיעד הם מחירים — גבולות אזורים
            'default_off' => true,    // 22.1, 6.7: שיטה חדשה כבויה עד שמנהל מפעיל
            'version' => 1 + $this->tune,   // + החלות (18.7)
            'incremental' => true,
            'alerts' => true,         // 22.1
        ];
    }

    public function horizon(array $p): int
    {
        return (int)$p['horizon_bars'];
    }

    public function validate(array $p): ?string
    {
        return (float)$p['zone_max_atr'] < (float)$p['zone_atr'] ? 'רוחב האזור המרבי חייב להיות לפחות מרחק הרמות באזור' : null;
    }

    public function init(array $p): array
    {
        // w — חלון הנרות; piv — נקודות המפנה שבחלון; g — הרשת מנרות ≤ הנר האחרון; x — האות האחרון ומעקב אחרי תוקפו
        return ['a' => ts_ew_atr_init(), 'z' => ts_ew_zz_init(), 'w' => [], 'piv' => [], 'g' => null, 'x' => null];
    }

    public function step(array $s, array $c, array $p): array
    {
        if ($msg = $this->validate($p)) throw new InvalidArgumentException($msg);
        // תוקף האות האחרון: נגיעה בסטופ או ביעד מהנר שאחרי האות, או תום האופק
        if ($s['x'] !== null) {
            $x = &$s['x'];
            $x['ago']++;
            if ($x['alive']) {
                $long = $x['side'] === 'long';
                $hit = $long ? ($c['l'] <= $x['stop'] || $c['h'] >= $x['target']) : ($c['h'] >= $x['stop'] || $c['l'] <= $x['target']);
                if ($hit || $x['ago'] >= $this->horizon($p)) $x['alive'] = false;
            }
            unset($x);
        }
        // האות — מהרשת של הנר הקודם (נרות ≤ c − 1), לפני שהנר הזה נכנס
        $sig = $s['g'] !== null ? ts_fib_signal($s['g'], $c, $p) : null;

        $s['a'] = ts_ew_atr_step($s['a'], $c, (int)$p['atr_len']);
        [$s['z'], $pv] = ts_ew_zz_step($s['z'], $c, $s['a']['v'], (float)$p['pivot_atr_mult']);
        if ($pv) $s['piv'][] = ['type' => $pv['type'], 't' => $pv['t'], 'ct' => $pv['ct']];
        $s['w'][] = ['t' => $c['t'], 'o' => $c['o'], 'h' => $c['h'], 'l' => $c['l'], 'c' => $c['c'], 'a' => $s['a']['v']];
        $max = (int)$p['lookback_bars'];
        if (count($s['w']) > $max) $s['w'] = array_slice($s['w'], -$max);
        $from = $s['w'][0]['t'];
        while ($s['piv'] && $s['piv'][0]['t'] < $from) array_shift($s['piv']);
        $s['g'] = ts_fib_grid($s['w'], $s['piv'], $p, $s['g']);   // צד שנקודת ההתחלה שלו לא זזה — נעול (22.3(7), "ג")

        if ($sig === null) return [$s, null];
        $s['x'] = $sig + ['ago' => 0, 'alive' => true];
        return [$s, $sig];
    }

    /**
     * הכרטיס (22.7): אזור, נגיעה, יחס — לכל צד; ושלוש הנחיות "בדוק בעצמך" (met: null). כשיש אות בתוקף — התנאים מתארים
     * את האזור של האות (האזור יכול להיעלם מהרשת אחרי שהמחיר יצא ממנו — S2); אחרת — האזור הטוב ביותר עכשיו.
     */
    public function conditions(array $s, array $p): array
    {
        $g = $s['g'];
        $x = $s['x'];
        $alive = $x !== null && $x['alive'] && $x['ago'] < $this->horizon($p);
        $min = (int)$p['min_score'];
        $rr = rtrim(rtrim(number_format((float)$p['min_rr'], 2, '.', ''), '0'), '.');
        $out = [];
        foreach (['long' => ['l', 'support', 'תמיכה', 'מעליו'], 'short' => ['s', 'resistance', 'התנגדות', 'מתחתיו']] as $side => [$k, $key, $word, $back]) {
            $mine = $alive && $x['side'] === $side;
            $best = null;
            foreach ($g[$key]['zones'] ?? [] as $z) if ($best === null || $z['score'] > $best['score']) $best = $z;
            if ($mine) {
                $zt = "אזור $word בציון {$x['score']} (" . self::num($x['zone'][0]) . '–' . self::num($x['zone'][1]) . ')';
                $zm = true;
            } elseif ($best !== null) {
                $zt = "אזור $word: " . count($best['levels']) . " רמות מ-{$best['ranges']} טווחים (ציון {$best['score']})";
                $zm = $best['score'] >= $min;
            } else {
                $zt = "אזור $word בציון $min לפחות";
                $zm = false;
            }
            $out[] = ['id' => "$k-zone", 'side' => $side, 'text' => $zt, 'met' => $zm, 'value' => $mine ? $x['score'] : $best['score'] ?? null];
            $out[] = ['id' => "$k-touch", 'side' => $side, 'text' => "המחיר נגע באזור ונסגר $back", 'met' => $mine, 'value' => null];
            $out[] = ['id' => "$k-rr", 'side' => $side, 'text' => "יחס סיכוי-סיכון ≥ $rr", 'met' => $mine, 'value' => $mine ? round($x['rr'], 2) : null];
        }
        foreach ([
            'manual-trunc' => 'בדוק בעצמך: האם נקודת ההתחלה היא קוץ שצריך לקטם, או פסגה כפולה שלא מקטמים',
            'manual-pattern' => 'בדוק בעצמך: אין רשת בתוך משולש או טריז סיום',
            'manual-tf' => 'בדוק בעצמך: אותו אזור באופק זמן נוסף (פי 4)',
        ] as $id => $text) $out[] = ['id' => $id, 'side' => 'both', 'text' => $text, 'met' => null, 'value' => null];

        $bias = $alive ? $x['side'] : 'none';
        $levels = $alive ? ['entry' => $x['entry'], 'stop' => $x['stop'], 'target' => $x['target']] : null;
        return ['bias' => $bias, 'conditions' => $out, 'levels' => $levels, 'signal_ago' => $alive ? $x['ago'] : null];
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 8, '.', ''), '0'), '.');
    }

    /**
     * בגרף — הממצא בלבד (F-1): אזורי החפיפה (zone, מנקודת ההתחלה ונמשכים ימינה עד קצה הגרף — R3, extend), הרמות
     * הבודדות (polyline מלא, single: true — דהויות, ו"הסתר רמות בודדות" מסתיר; Z9), ואות בתוקף — סימון, סטופ ויעד.
     * אין כאן תרחיש (22.5 — F-2): אין פריט אחרי הנר האחרון, ואין קו מקווקו.
     */
    public function overlay(array $candles, array $p, int $tf): array
    {
        require_once dirname(__DIR__) . '/overlay.php';
        $bars = 0;
        if (!$candles) return ts_overlay_empty($candles, $bars, 'אין נרות בטווח');
        $s = $this->init($p);
        foreach ($candles as $c) [$s] = $this->step($s, $c, $p);
        $g = $s['g'];
        $last = $candles[count($candles) - 1]['t'];
        $first = $candles[0]['t'];
        $items = [];
        $pct = fn(float $r) => rtrim(rtrim(number_format(100 * $r, 1, '.', ''), '0'), '.') . '%';
        foreach (['support' => 'אזור תמיכה', 'resistance' => 'אזור התנגדות'] as $key => $word) {
            if (!$g || !$g[$key]['start']) continue;
            $t1 = max($first, $g[$key]['start']['t']);
            foreach ($g[$key]['zones'] as $z) {
                $items[] = ['kind' => 'zone', 't1' => $t1, 't2' => $last, 'p1' => $z['lo'], 'p2' => $z['hi'], 'extend' => true, 'fib' => $key,
                    'score' => $z['score'], 'text' => "$word · " . count($z['levels']) . ' רמות · ציון ' . $z['score']];
            }
            foreach ($g[$key]['singles'] as $l) {
                $items[] = ['kind' => 'polyline', 'style' => 'solid', 'single' => true, 'extend' => true, 'fib' => $key, 'r' => $l['r'],
                    'points' => [['t' => $t1, 'price' => $l['price']], ['t' => $last, 'price' => $l['price']]]];
            }
        }
        $x = $s['x'];
        if ($x !== null && $x['alive'] && $x['ago'] < $this->horizon($p) && $x['ago'] < count($candles)) {
            $sigT = $candles[count($candles) - 1 - $x['ago']]['t'];
            $items[] = ['kind' => 'marker', 't' => $sigT, 'side' => $x['side'], 'text' => ($x['side'] === 'long' ? 'אות לונג' : 'אות שורט') . ' לפי כללי השיטה'];
            $items[] = ['kind' => 'hline', 'price' => (float)$x['stop'], 'role' => 'stop', 'text' => 'סטופ לפי כללי השיטה'];
            $items[] = ['kind' => 'hline', 'price' => (float)$x['target'], 'role' => 'target', 'text' => 'רמת היציאה ברווח לפי כללי השיטה'];
        }
        $ov = ['as_of' => $last, 'range' => ['from' => $first, 'to' => $last], 'items' => $items, 'projection' => ['bars' => $bars, 'scenarios' => []]];
        if (!$items) $ov['note'] = $g ? 'אין כרגע אזור חפיפה ולא רמות' : 'אין מספיק נרות לחישוב';
        return $ov;
    }
}
