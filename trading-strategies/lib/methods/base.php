<?php
/**
 * הממשק של שיטת מסחר (spec-stage-a 6.1). כל שיטה — קובץ אחד ב-lib/methods/<id>.php, מחלקה שיורשת מ-TsMethod,
 * ושורה ב-TS_METHODS (registry.php). שיטה חדשה מופיעה בניהול **כבויה**, עד שמנהל מפעיל (6.7).
 *
 * השיטה כתובה כזרימה: init(params) → מצב התחלתי, ו-step(מצב, נר, params) → [מצב חדש, אות|null] — נר אחרי נר,
 * אחרי שהנר נסגר. מכאן שני דברים נובעים מהמבנה עצמו:
 *   - **בלי הצצה לעתיד (6.2 א):** אות בנר i נקבע רק מהמצב שנבנה מנרות ≤ i. tests/backtest.php מוודא גם בפועל:
 *     הרצה על [0..i] ועל כל הסט נותנת אותו אות ב-i.
 *   - **חישוב מצטבר:** המצב (JSON) נשמר בסוף הבדיקה לאחור, וממשיכים ממנו על הנרות החדשים בלבד. מצטבר = מלא.
 *
 * פונקציות טהורות: אותם נרות + אותם פרמטרים = אותה תוצאה. בלי רשת, בלי שעון, בלי מסד. הכול float (עיקרון 6) —
 * המנוע מעגל את הרמות ל-tick והופך אותן למחרוזת לפני שהן נוגעות בכסף (lib/analysis.php).
 *
 * נר במנוע: ['t' => int, 'o','h','l','c' => float]. אות: ['side' => long|short, 'entry','stop' => float, 'target' => float|null]
 * — הרמות לפי סגירת נר האות. המנוע נכנס בפתיחת i+1 ומזיז את הסטופ והיעד באותו מרחק מהכניסה בפועל (6.2 ב).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/indicators.php';

abstract class TsMethod
{
    /**
     * id, name, source (מקור: ספר ופרק, או "שיטת דוגמה"), sample (bool), markets[], timeframes[],
     * params{key: {label, default, min, max, int}}, horizon_bars, warmup (נרות עד שהשיטה "מתחממת"), version (int),
     * incremental (bool — המצב ממשיך על נרות חדשים), alerts (bool — מותרת בהתראות, פרק 8)
     */
    abstract public function meta(): array;

    abstract public function init(array $p): array;

    /** @return array{0: array, 1: ?array} */
    abstract public function step(array $s, array $c, array $p): array;

    /**
     * התנאים במצב הנוכחי (אחרי הנר האחרון שנסגר):
     * ['bias' => long|short|none, 'conditions' => [{id, text, side, met, value}], 'levels' => {entry, stop, target}|null]
     */
    abstract public function conditions(array $s, array $p): array;

    /** האופק של השיטה בנרות — יכול להיות תלוי בפרמטרים */
    public function horizon(array $p): int
    {
        return (int)$this->meta()['horizon_bars'];
    }

    /** כל האותות בהיסטוריה (לבדיקה לאחור ולבדיקות) — [{t, side, entry, stop, target}] */
    public function signals(array $candles, array $p): array
    {
        $s = $this->init($p);
        $out = [];
        foreach ($candles as $c) {
            [$s, $sig] = $this->step($s, $c, $p);
            if ($sig) $out[] = ['t' => $c['t']] + $sig;
        }
        return $out;
    }

    /** ניתוח של המצב אחרי הנר האחרון (6.1) */
    public function analyze(array $candles, array $p): array
    {
        $s = $this->init($p);
        foreach ($candles as $c) [$s] = $this->step($s, $c, $p);
        $last = $candles ? $candles[count($candles) - 1]['t'] : null;
        return $this->conditions($s, $p) + ['horizon_bars' => $this->horizon($p), 'as_of' => $last];
    }

    /** אורך ההקרנה קדימה בנרות (17.2.2) — meta.forecast_bars, ובלעדיו האופק של השיטה */
    public function forecastBars(array $p): int
    {
        return (int)($this->meta()['forecast_bars'] ?? $this->horizon($p));
    }

    /**
     * "🔮 תחזית שיטה" (17.2.2, החוזה — lib/overlay.php): התרחיש לפי כללי השיטה על הנרות המוצגים. $tf — אורך הנר בשניות.
     * ברירת המחדל לכל שיטה: מהאות האחרון שעוד בתוקף (analyze) — סימון על נר האות, קווי הסטופ ורמת היציאה ברווח, אזור
     * ביניהם, וקו מקווקו מהסגירה האחרונה אל רמת היציאה, עד סוף האופק (ולא יותר מ-forecastBars). בלי אות בתוקף — ריק.
     * שיטה עם תצוגה משלה (אליוט — 4א/4) דורסת. float לתצוגה בלבד.
     */
    public function overlay(array $candles, array $p, int $tf): array
    {
        require_once dirname(__DIR__) . '/overlay.php';
        $bars = $this->forecastBars($p);
        if (!$candles) return ts_overlay_empty($candles, $bars, 'אין נרות בטווח');
        $a = $this->analyze($candles, $p);
        $lv = $a['levels'];
        $ago = $a['signal_ago'] ?? null;
        if ($a['bias'] === 'none' || !$lv || $ago === null || $ago >= count($candles)) {
            return ts_overlay_empty($candles, $bars, 'אין כרגע תרחיש פעיל לפי כללי השיטה');
        }
        $last = $candles[count($candles) - 1];
        $sigT = $candles[count($candles) - 1 - $ago]['t'];
        $k = max(1, min($bars, $this->horizon($p) - $ago));
        $end = $last['t'] + $k * $tf;
        $side = $a['bias'];
        $items = [
            ['kind' => 'marker', 't' => $sigT, 'side' => $side, 'text' => ($side === 'long' ? 'אות לונג' : 'אות שורט') . ' לפי כללי השיטה'],
            ['kind' => 'hline', 'price' => (float)$lv['stop'], 'role' => 'stop', 'text' => 'סטופ לפי כללי השיטה'],
        ];
        $points = [['t' => $last['t'], 'price' => (float)$last['c']]];
        if ($lv['target'] !== null) {
            $items[] = ['kind' => 'hline', 'price' => (float)$lv['target'], 'role' => 'target', 'text' => 'רמת היציאה ברווח לפי כללי השיטה'];
            $items[] = ['kind' => 'zone', 't1' => $sigT, 't2' => $end, 'p1' => (float)min($lv['entry'], $lv['target']), 'p2' => (float)max($lv['entry'], $lv['target']),
                'text' => 'מהכניסה עד רמת היציאה ברווח, עד סוף האופק'];
            $points[] = ['t' => $end, 'price' => (float)$lv['target']];
        } else {
            $points[] = ['t' => $end, 'price' => (float)$last['c']];
        }
        $items[] = ['kind' => 'polyline', 'points' => $points, 'style' => 'dashed'];
        return ['as_of' => $last['t'], 'range' => ['from' => $candles[0]['t'], 'to' => $last['t']], 'items' => $items,
            'projection' => ['bars' => $bars, 'scenarios' => [['id' => 's1', 'score' => null, 'points' => $points,
                'label' => 'לפי כללי השיטה — ' . ($side === 'long' ? 'צד לונג' : 'צד שורט') . ", עד $k נרות"]]]];
    }

    /** בדיקה של צירוף פרמטרים (מעבר לטווח של כל אחד). מחזירה הודעה, או null כשתקין */
    public function validate(array $p): ?string
    {
        return null;
    }

    /** ברירות המחדל של הפרמטרים, ועליהן מה שהמנהל קבע (רק מפתחות מוכרים, בטווח) */
    public function params(array $overrides = []): array
    {
        $out = [];
        foreach ($this->meta()['params'] as $k => $d) {
            $v = $overrides[$k] ?? $d['default'];
            $out[$k] = !empty($d['int']) ? (int)$v : (float)$v;
        }
        return $out;
    }
}
