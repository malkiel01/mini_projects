<?php
/**
 * גלי אליוט — כניסה לגל 3 (spec-stage-a 17.1.9, `elliott-w3`). שיטה שלנו על בסיס כללי הספר (Frost & Prechter).
 *
 * הרעיון (spec-elliott-wave.md 6): כניסה בסוף גל 2 שלא עבר את תחילת גל 1, סטופ ברמת הביטול (תחילת גל 1), יעד
 * 1.618 × גל 1. רק כלל 1 והיחס 1.618 הם "לפי הספר"; כל השאר [שלנו] — פרמטרים בטווח, לא קבועים בקוד.
 *
 * לונג (שורט — במראה): אות בנר c, אחרי שנסגר, כש-c הוא נר האישור של שפל P2, ושלוש נקודות המפנה המאושרות האחרונות
 * הן P0 (שפל) → P1 (שיא) → P2 (שפל), ו:
 *   1. P2 > P0 (כלל 1; שוויון — אין אות)
 *   2. r = (P1 − P2) / (P1 − P0) בתוך [w2_min, w2_max] (סגור)
 *   3. P1 − P0 ≥ min_w1_atr × ATR, ה-ATR בנר c
 * רמות: כניסה (לחישוב) = סגירת c · סטופ = P0 · יעד = P2 × (1 + w3_mult × g1), g1 = P1 / P0 − 1 (באחוזים, כפולה
 * לינארית — דוגמת הספר). יעד ≤ tick → אין אות.
 *
 * הרמות מוחלטות (meta.levels = 'absolute', 6.2(ח)): המנוע לא מזיז אותן לפי הכניסה בפועל, ופער בפתיחה מעבר לסטופ
 * או ליעד — האות לא נפתח (skipped_gap).
 *
 * tick: השיטה טהורה ואינה יודעת את ה-tick של הנכס. מי שמריץ אותה מעביר אותו כ-$p['tick'] (לא פרמטר של מנהל);
 * בלי — 0, כלומר "יעד ≤ 0 → אין אות". [פתוח לחיבור ב-4א/3: העברת ה-tick מ-lib/analysis.php]
 *
 * בלי הצצה לעתיד (6.2(א)): נקודת מפנה קיימת רק מנר האישור שלה (lib/elliott.php), והאות נקבע רק בנר הזה.
 */

declare(strict_types=1);

require_once __DIR__ . '/base.php';
require_once dirname(__DIR__) . '/elliott.php';

final class TsMethodElliottW3 extends TsMethod
{
    public function meta(): array
    {
        return [
            'id' => 'elliott-w3',
            'name' => 'גלי אליוט — כניסה לגל 3',
            'source' => 'Frost & Prechter, פרק 1 (כלל 1), פרק 4 (יחסים)',
            'label' => 'שיטה שלנו על בסיס כללי הספר (Frost & Prechter)',
            'sample' => false,
            'markets' => ['crypto', 'stocks', 'forex', 'indices', 'commodities', 'futures'],
            'timeframes' => ['h1', 'h4', 'd1', 'w1'],   // ✅18: שעה ומעלה
            // ours: true — "הגדרה שלנו" (ז0); רק w3_mult "לפי הספר"
            'params' => [
                'pivot_atr_mult' => ['label' => 'רגישות נקודות המפנה (× ATR)', 'default' => 3, 'min' => 1, 'max' => 10, 'int' => false, 'ours' => true],
                'atr_len' => ['label' => 'אורך ATR', 'default' => 14, 'min' => 5, 'max' => 50, 'int' => true, 'ours' => true],
                'w2_min' => ['label' => 'עומק גל 2 — מינימום', 'default' => 0.382, 'min' => 0.236, 'max' => 0.618, 'int' => false, 'ours' => true],
                'w2_max' => ['label' => 'עומק גל 2 — מקסימום', 'default' => 0.786, 'min' => 0.618, 'max' => 0.95, 'int' => false, 'ours' => true],
                'min_w1_atr' => ['label' => 'גל 1 מינימלי (× ATR)', 'default' => 2, 'min' => 0, 'max' => 10, 'int' => false, 'ours' => true],
                'w3_mult' => ['label' => 'יעד גל 3 (× גל 1)', 'default' => 1.618, 'min' => 1.0, 'max' => 2.618, 'int' => false, 'ours' => false],
                'horizon_bars' => ['label' => 'אופק (נרות)', 'default' => 60, 'min' => 10, 'max' => 500, 'int' => true, 'ours' => true],
                'warmup_bars' => ['label' => 'חימום בלי בדיקה לאחור (נרות)', 'default' => 500, 'min' => 100, 'max' => 5000, 'int' => true, 'ours' => true],
            ],
            'horizon_bars' => 60,
            'warmup' => 500,
            'levels' => 'absolute',   // 6.2(ח)
            'version' => 1,
            'incremental' => true,
            'alerts' => false,        // לא נקבע באפיון — כבוי עד שיוכרע
        ];
    }

    public function horizon(array $p): int
    {
        return (int)$p['horizon_bars'];
    }

    public function validate(array $p): ?string
    {
        return (float)$p['w2_max'] <= (float)$p['w2_min'] ? 'עומק גל 2: המקסימום חייב להיות גדול מהמינימום' : null;
    }

    public function init(array $p): array
    {
        // piv — שלוש נקודות המפנה המאושרות האחרונות; x — האות האחרון ומעקב אחרי תוקפו (לכרטיס)
        return ['a' => ts_ew_atr_init(), 'z' => ts_ew_zz_init(), 'piv' => [], 'x' => null];
    }

    public function step(array $s, array $c, array $p): array
    {
        if ($msg = $this->validate($p)) throw new InvalidArgumentException($msg);
        $s['a'] = ts_ew_atr_step($s['a'], $c, (int)$p['atr_len']);
        $atr = $s['a']['v'];

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

        [$s['z'], $pv] = ts_ew_zz_step($s['z'], $c, $atr, (float)$p['pivot_atr_mult']);
        if ($pv === null) return [$s, null];
        $s['piv'][] = $pv + ['catr' => $atr];   // catr — ה-ATR בנר האישור
        if (count($s['piv']) > 3) array_shift($s['piv']);
        if (count($s['piv']) < 3) return [$s, null];

        $e = self::evaluate($s['piv'], $p);
        if (!$e['ok']) return [$s, null];
        $sig = ['side' => $e['side'], 'entry' => $c['c'], 'stop' => $e['stop'], 'target' => $e['target']];
        $s['x'] = $sig + ['ago' => 0, 'alive' => true];
        return [$s, $sig];
    }

    /**
     * הבדיקה של שלוש נקודות P0 → P1 → P2 (מאושרות). המראה: בשורט מכפילים ב-−1 ומשווים כמו בלונג.
     * מחזיר את כל החלקים (לכרטיס) ו-ok כשכולם מתקיימים.
     */
    public static function evaluate(array $piv, array $p): array
    {
        [$a, $b, $cc] = $piv;
        $side = $a['type'] === 'L' ? 'long' : 'short';
        $d = $side === 'long' ? 1 : -1;
        $P0 = $a['v']; $P1 = $b['v']; $P2 = $cc['v'];
        $w1 = $d * ($P1 - $P0);
        $r1 = $d * ($P2 - $P0) > 0;                                            // כלל 1 (שוויון — לא)
        $r = $w1 > 0 ? ($d * ($P1 - $P2)) / $w1 : null;
        $depth = $r !== null && $r >= $p['w2_min'] - TS_EW_EPS && $r <= $p['w2_max'] + TS_EW_EPS;
        $atr = $cc['catr'] ?? $cc['atr'];
        $w1ok = $w1 > 0 && $atr !== null && $w1 >= $p['min_w1_atr'] * $atr;
        $g1 = $d * ($P1 / $P0 - 1);
        $target = $P2 * (1 + $d * $p['w3_mult'] * $g1);
        $tick = (float)($p['tick'] ?? 0);
        $tgtOk = $target > $tick;                                             // יעד ≤ tick → אין אות
        return ['side' => $side, 'w1' => $w1ok, 'r1' => $r1, 'r' => $r, 'depth' => $depth, 'target_ok' => $tgtOk,
            'stop' => $P0, 'target' => $target, 'ok' => $w1ok && $r1 && $depth && $tgtOk];
    }

    public function conditions(array $s, array $p): array
    {
        $piv = $s['piv'];
        $n = count($piv);
        $atrNow = $s['a']['v'];
        $pct = fn(float $v) => rtrim(rtrim(number_format(100 * $v, 1, '.', ''), '0'), '.') . '%';
        $range = $pct((float)$p['w2_min']) . '–' . $pct((float)$p['w2_max']);

        $out = [];
        foreach (['long' => ['l', 'L', 'מעלה', 'מתחת'], 'short' => ['s', 'H', 'מטה', 'מעל']] as $side => [$k, $startType, $way, $beyond]) {
            $w1 = false; $r1 = false; $depth = false; $confirm = false; $r = null;
            if ($n === 3 && $piv[0]['type'] === $startType) {
                // P2 מאושר — הנקודה האחרונה היא סוף גל 2
                $e = self::evaluate($piv, $p);
                [$w1, $r1, $depth, $r, $confirm] = [$e['w1'], $e['r1'], $e['depth'], $e['r'], true];
            } elseif ($n >= 2 && $piv[$n - 2]['type'] === $startType && ($open = $s['z']['ext'] ?? null) !== null) {
                // גל 2 עוד פתוח: P0, P1 מאושרים, והקיצון הפתוח הוא השפל (בשורט: השיא) הנוכחי
                $e = self::evaluate([$piv[$n - 2], $piv[$n - 1], ['type' => $startType, 'v' => $open['v'], 'catr' => $atrNow]], $p);
                [$w1, $r1, $depth, $r] = [$e['w1'], $e['r1'], $e['depth'], $e['r']];
            }
            $out[] = ['id' => "$k-w1", 'side' => $side, 'text' => "גל 1 כלפי $way אושר (P0 → P1)", 'met' => $w1, 'value' => null];
            $out[] = ['id' => "$k-r1", 'side' => $side, 'text' => "גל 2 לא חזר $beyond לתחילת גל 1", 'met' => $r1, 'value' => null];
            $out[] = ['id' => "$k-depth", 'side' => $side, 'text' => "עומק גל 2 בטווח ($range)", 'met' => $depth, 'value' => $r];
            $out[] = ['id' => "$k-confirm", 'side' => $side, 'text' => 'גל 2 הסתיים (היפוך אושר)', 'met' => $confirm, 'value' => null];
        }
        // ז: "בדוק בעצמך" — מה בספר שהמחשב כאן לא בודק
        foreach ([
            'manual-structure' => 'המבנה הפנימי של גל 1 — חמישה תתי-גלים (דחף) או אלכסוני',
            'manual-degree' => 'הדרגה — האם P0–P2 הם באמת גלים 1–2 של הדרגה, או חלק מתיקון גדול יותר',
            'manual-guidelines' => 'חילופיות, ערוץ ונפח — הנחיות, לא נכנסות לאות',
            'manual-context' => 'ההקשר של הדרגה הגדולה יותר — האם המגמה הגדולה תומכת בכיוון',
        ] as $id => $text) $out[] = ['id' => $id, 'side' => 'both', 'text' => $text, 'met' => null, 'value' => null];

        $x = $s['x'];
        $all = fn(string $k) => !array_filter($out, fn($c) => str_starts_with($c['id'], "$k-") && $c['met'] !== true);
        $bias = 'none';
        if ($x !== null && $x['alive'] && $x['ago'] < $this->horizon($p)) {
            if ($x['side'] === 'long' && $all('l')) $bias = 'long';
            elseif ($x['side'] === 'short' && $all('s')) $bias = 'short';
        }
        $levels = $bias !== 'none' ? ['entry' => $x['entry'], 'stop' => $x['stop'], 'target' => $x['target']] : null;
        return ['bias' => $bias, 'conditions' => $out, 'levels' => $levels, 'signal_ago' => $bias !== 'none' ? $x['ago'] : null];
    }
}
