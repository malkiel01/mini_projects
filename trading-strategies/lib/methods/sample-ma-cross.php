<?php
/**
 * 🧪 שיטת דוגמה — לא מהספר (spec-stage-a פרק 10, "שיטת בדיקה פנימית").
 *
 * מטרתה היחידה — לבנות ולבדוק את המנוע (6.1–6.5) בלי לחכות לספר. כבויה כברירת מחדל; גם כשמנהל מפעיל אותה —
 * מוצגת **רק למנהלים**, עם התווית בכל כרטיס, ולא נכנסת להתראות (alerts: false).
 *
 * הכללים: חציית ממוצעים נעים פשוטים (20 / 50) על הסגירה, בסגירת הנר. לונג — הקצר חוצה מעל הארוך; שורט — מתחת.
 * סטופ — 2 × ATR(14) ממחיר הכניסה; יעד — 3 × ATR(14); אופק — 50 נרות.
 */

declare(strict_types=1);

require_once __DIR__ . '/base.php';

final class TsMethodSampleMaCross extends TsMethod
{
    public function meta(): array
    {
        return [
            'id' => 'sample-ma-cross',
            'name' => 'חציית ממוצעים 20/50',
            'source' => 'שיטת דוגמה — לא מהספר',
            'sample' => true,
            'label' => '🧪 שיטת דוגמה — לא מהספר',
            'markets' => ['crypto', 'stocks', 'forex', 'indices', 'commodities', 'futures'],
            'timeframes' => ['m1', 'm5', 'm15', 'm30', 'h1', 'h4', 'd1', 'w1'],
            'params' => [
                'fast' => ['label' => 'ממוצע קצר', 'default' => 20, 'min' => 2, 'max' => 200, 'int' => true],
                'slow' => ['label' => 'ממוצע ארוך', 'default' => 50, 'min' => 3, 'max' => 400, 'int' => true],
                'atr' => ['label' => 'אורך ATR', 'default' => 14, 'min' => 2, 'max' => 100, 'int' => true],
                'stop_atr' => ['label' => 'סטופ (× ATR)', 'default' => 2, 'min' => 0.5, 'max' => 10, 'int' => false],
                'target_atr' => ['label' => 'יעד (× ATR)', 'default' => 3, 'min' => 0.5, 'max' => 20, 'int' => false],
                'horizon' => ['label' => 'אופק (נרות)', 'default' => 50, 'min' => 1, 'max' => 500, 'int' => true],
            ],
            'horizon_bars' => 50,
            'warmup' => 60,
            'version' => 1,
            'incremental' => true,
            'alerts' => false,
        ];
    }

    public function horizon(array $p): int
    {
        return (int)$p['horizon'];
    }

    public function validate(array $p): ?string
    {
        return (int)$p['fast'] >= (int)$p['slow'] ? 'הממוצע הקצר חייב להיות קצר מהארוך' : null;
    }

    public function init(array $p): array
    {
        // w — הסגירות האחרונות (באורך הממוצע הארוך); a — מצב ה-ATR; x — החצייה האחרונה
        return ['w' => [], 'pf' => null, 'ps' => null, 'f' => null, 's' => null,
            'a' => ['n' => 0, 'sum' => 0.0, 'v' => null, 'pc' => null], 'x' => null];
    }

    public function step(array $s, array $c, array $p): array
    {
        $fast = (int)$p['fast']; $slow = (int)$p['slow']; $n = (int)$p['atr'];
        if ($fast >= $slow) throw new InvalidArgumentException('הממוצע הקצר חייב להיות קצר מהארוך');

        // ATR של Wilder, בזרימה — באותו סדר פעולות כמו ts_atr
        $a = $s['a'];
        $tr = ts_tr($c['h'], $c['l'], $a['pc']);
        if ($a['n'] < $n) {
            $a['sum'] += $tr;
            if ($a['n'] === $n - 1) $a['v'] = $a['sum'] / $n;
        } else {
            $a['v'] = ($a['v'] * ($n - 1) + $tr) / $n;
        }
        $a['n']++;
        $a['pc'] = $c['c'];
        $s['a'] = $a;

        $s['w'][] = $c['c'];
        if (count($s['w']) > $slow) array_shift($s['w']);
        $s['pf'] = $s['f']; $s['ps'] = $s['s'];
        $cnt = count($s['w']);
        $s['f'] = $cnt >= $fast ? array_sum(array_slice($s['w'], -$fast)) / $fast : null;
        $s['s'] = $cnt >= $slow ? array_sum($s['w']) / $slow : null;

        if ($s['x'] !== null) $s['x']['ago']++;
        $sig = null;
        if ($s['pf'] !== null && $s['ps'] !== null && $s['f'] !== null && $s['s'] !== null && $a['v'] !== null) {
            $side = null;
            if ($s['pf'] <= $s['ps'] && $s['f'] > $s['s']) $side = 'long';
            elseif ($s['pf'] >= $s['ps'] && $s['f'] < $s['s']) $side = 'short';
            if ($side) {
                $dir = $side === 'long' ? 1 : -1;
                $sig = ['side' => $side, 'entry' => $c['c'], 'stop' => $c['c'] - $dir * $p['stop_atr'] * $a['v'],
                    'target' => $c['c'] + $dir * $p['target_atr'] * $a['v']];
                $s['x'] = $sig + ['ago' => 0];
            }
        }
        return [$s, $sig];
    }

    public function conditions(array $s, array $p): array
    {
        $f = $s['f']; $sl = $s['s']; $x = $s['x'];
        $H = (int)$p['horizon'];
        $fresh = fn(string $side) => $x !== null && $x['side'] === $side && $x['ago'] < $H;
        $ago = $x !== null ? $x['ago'] : null;
        $fs = (int)$p['fast'] . ''; $ss = (int)$p['slow'] . '';
        $cond = [
            ['id' => 'l-above', 'side' => 'long', 'text' => "ממוצע $fs מעל ממוצע $ss", 'met' => $f !== null && $sl !== null && $f > $sl, 'value' => $f !== null && $sl !== null ? $f - $sl : null],
            ['id' => 'l-cross', 'side' => 'long', 'text' => "חצייה כלפי מעלה ב-$H הנרות האחרונים", 'met' => $fresh('long'), 'value' => $x && $x['side'] === 'long' ? $ago : null],
            ['id' => 's-below', 'side' => 'short', 'text' => "ממוצע $fs מתחת לממוצע $ss", 'met' => $f !== null && $sl !== null && $f < $sl, 'value' => $f !== null && $sl !== null ? $f - $sl : null],
            ['id' => 's-cross', 'side' => 'short', 'text' => "חצייה כלפי מטה ב-$H הנרות האחרונים", 'met' => $fresh('short'), 'value' => $x && $x['side'] === 'short' ? $ago : null],
        ];
        $bias = 'none';
        if ($cond[0]['met'] && $cond[1]['met']) $bias = 'long';
        elseif ($cond[2]['met'] && $cond[3]['met']) $bias = 'short';
        $levels = $bias !== 'none' ? ['entry' => $x['entry'], 'stop' => $x['stop'], 'target' => $x['target']] : null;
        return ['bias' => $bias, 'conditions' => $cond, 'levels' => $levels, 'signal_ago' => $bias !== 'none' ? $ago : null];
    }
}
