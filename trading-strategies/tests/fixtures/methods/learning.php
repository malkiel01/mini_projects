<?php
// שיטת בדיקה ללמידה (L5, 23.9) — TS_TEST_METHODS, רק בבדיקות (TS_DEV). האותות — של sample-ma-cross; ועוד פרמטר zone_atr ([שלנו],
// 0.1–2) שנלמד מהסטטיסטיקה "zone" (לא מושהית), ו-stop_atr שנלמד מ-"stop" (מושהית — ביקורת L5 🟡2). יחסי "ספר" (ours: false) — לא נלמדים.
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
            'learn_params' => ['zone_atr' => ['stat' => 'zone'], 'stop_atr' => ['stat' => 'stop'], 'book_ratio' => ['stat' => 'zone']]] + $m;
    }
    public function horizon(array $p): int { return $this->base->horizon($p); }
    public function init(array $p): array { return $this->base->init($p); }
    public function step(array $s, array $c, array $p): array { return $this->base->step($s, $c, $p); }
    public function conditions(array $s, array $p): array { return $this->base->conditions($s, $p); }
}

return ['test-learn' => TsTestLearn::class];
