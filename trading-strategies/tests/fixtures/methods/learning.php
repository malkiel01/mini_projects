<?php
// שיטות בדיקה ללמידה (L5b, 23.9) — TS_TEST_METHODS, רק בבדיקות (TS_DEV).
// test-learn — האותות של sample-ma-cross; ועוד zone_atr ([שלנו], 0.1–2, צעד 0.1) ו-book_ratio ("מהספר", ours: false) שמוצהר בצעד —
//   ולכן לא נלמד (קריטריון 10: פרמטר מהספר — רק עם values).
// test-neighbors — אות לונג בכל נר ש-t שלו מתחלק ב-20 שעות (תלוי בזמן בלבד — אותם אותות גם כשההרצה מתחילה באמצע), כניסה בסגירה,
//   סטופ stop_atr × 5 ויעד target_atr × 5 מתחת ומעל (5 = "ATR" קבוע). מתאים לסטים הקבועים של tests/fixtures/learn/sets.php.
require_once dirname(__DIR__, 3) . '/lib/methods/sample-ma-cross.php';

final class TsTestLearn extends TsMethod
{
    private TsMethodSampleMaCross $base;
    public function __construct() { $this->base = new TsMethodSampleMaCross(); }
    public function meta(): array
    {
        $m = $this->base->meta();
        foreach ($m['params'] as &$d) $d['ours'] = true;
        unset($d);
        $m['params']['zone_atr'] = ['label' => 'רוחב אזור (× ATR)', 'default' => 0.5, 'min' => 0.1, 'max' => 2, 'int' => false, 'ours' => true];
        $m['params']['book_ratio'] = ['label' => 'יחס מהספר', 'default' => 1.618, 'min' => 1, 'max' => 2.618, 'int' => false, 'ours' => false];
        return ['id' => 'test-learn', 'name' => 'בדיקת למידה', 'source' => 'בדיקות', 'sample' => false, 'label' => null, 'alerts' => false, 'version' => 1 + $this->tune,
            'learn_params' => ['zone_atr' => ['step' => 0.1], 'stop_atr' => ['step' => 0.25], 'book_ratio' => ['step' => 0.1]]] + $m;
    }
    public function horizon(array $p): int { return $this->base->horizon($p); }
    public function init(array $p): array { return $this->base->init($p); }
    public function step(array $s, array $c, array $p): array { return $this->base->step($s, $c, $p); }
    public function conditions(array $s, array $p): array { return $this->base->conditions($s, $p); }
}

final class TsTestNeighbors extends TsMethod
{
    public function meta(): array
    {
        return ['id' => 'test-neighbors', 'name' => 'בדיקת שכנים', 'source' => 'בדיקות', 'sample' => false, 'label' => null, 'alerts' => false,
            'markets' => ['crypto', 'forex'], 'timeframes' => ['h1'], 'version' => 1 + $this->tune, 'incremental' => true, 'warmup' => 0, 'horizon_bars' => 10,
            'params' => [
                'stop_atr' => ['label' => 'סטופ (× ATR)', 'default' => 1, 'min' => 0.5, 'max' => 3, 'int' => false, 'ours' => true],
                'target_atr' => ['label' => 'יעד (× ATR)', 'default' => 2, 'min' => 1, 'max' => 4, 'int' => false, 'ours' => true],
            ],
            'learn_params' => ['stop_atr' => ['step' => 0.25]]];
    }
    public function horizon(array $p): int { return 10; }
    public function init(array $p): array { return []; }
    public function step(array $s, array $c, array $p): array
    {
        if ($c['t'] % 72000 !== 0) return [$s, null];
        return [$s, ['side' => 'long', 'entry' => $c['c'], 'stop' => $c['c'] - 5 * $p['stop_atr'], 'target' => $c['c'] + 5 * $p['target_atr']]];
    }
    public function conditions(array $s, array $p): array { return ['bias' => 'none', 'conditions' => [], 'levels' => null, 'signal_ago' => null]; }
}

return ['test-learn' => TsTestLearn::class, 'test-neighbors' => TsTestNeighbors::class];
