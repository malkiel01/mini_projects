<?php
// שיטות בדיקה (TS_TEST_METHODS) — לא שיטות דוגמה, ומותרות בהתראות. רק בבדיקות (TS_DEV), כדי לבדוק התראות ושקלול.
require_once dirname(__DIR__, 3) . '/lib/methods/sample-ma-cross.php';

final class TsTestCrossA extends TsMethod
{
    private TsMethodSampleMaCross $base;
    public function __construct() { $this->base = new TsMethodSampleMaCross(); }
    public function meta(): array
    {
        return ['id' => 'test-cross-a', 'name' => 'בדיקה א', 'source' => 'בדיקות', 'sample' => false, 'label' => null, 'alerts' => true, 'version' => 1] + $this->base->meta();
    }
    public function horizon(array $p): int { return $this->base->horizon($p); }
    public function init(array $p): array { return $this->base->init($p); }
    public function step(array $s, array $c, array $p): array { return $this->base->step($s, $c, $p); }
    public function conditions(array $s, array $p): array { return $this->base->conditions($s, $p); }
}

final class TsTestCrossB extends TsMethod
{
    private TsMethodSampleMaCross $base;
    public function __construct() { $this->base = new TsMethodSampleMaCross(); }
    public function meta(): array
    {
        $m = $this->base->meta();
        $m['params']['fast']['default'] = 10; $m['params']['slow']['default'] = 30;
        return ['id' => 'test-cross-b', 'name' => 'בדיקה ב', 'source' => 'בדיקות', 'sample' => false, 'label' => null, 'alerts' => true, 'version' => 1] + $m;
    }
    public function horizon(array $p): int { return $this->base->horizon($p); }
    public function init(array $p): array { return $this->base->init($p); }
    public function step(array $s, array $c, array $p): array { return $this->base->step($s, $c, $p); }
    public function conditions(array $s, array $p): array { return $this->base->conditions($s, $p); }
}

return ['test-cross-a' => TsTestCrossA::class, 'test-cross-b' => TsTestCrossB::class];
