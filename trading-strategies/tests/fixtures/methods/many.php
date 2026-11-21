<?php
// תשע שיטות בדיקה (TS_TEST_METHODS) — לכלל "יותר מ-8 שיטות" של צבעי השיטות (spec-stage-a 18.5, 2.20). רק בבדיקות (TS_DEV).
// כל אחת — חציית הממוצעים של שיטת הדוגמה, אבל לא שיטת דוגמה: מופעלת בלי שורה, ונראית גם למשתמש רגיל.
require_once dirname(__DIR__, 3) . '/lib/methods/sample-ma-cross.php';

abstract class TsTestManyBase extends TsMethod
{
    private TsMethodSampleMaCross $base;
    public function __construct() { $this->base = new TsMethodSampleMaCross(); }
    private function no(): int { return (int)substr(static::class, strlen('TsTestMany')); }
    public function meta(): array
    {
        return ['id' => 't' . $this->no(), 'name' => 'בדיקה ' . $this->no(), 'source' => 'בדיקות', 'sample' => false, 'label' => null,
            'alerts' => false, 'version' => 1 + $this->tune] + $this->base->meta();
    }
    public function horizon(array $p): int { return $this->base->horizon($p); }
    public function init(array $p): array { return $this->base->init($p); }
    public function step(array $s, array $c, array $p): array { return $this->base->step($s, $c, $p); }
    public function conditions(array $s, array $p): array { return $this->base->conditions($s, $p); }
}
final class TsTestMany1 extends TsTestManyBase {}
final class TsTestMany2 extends TsTestManyBase {}
final class TsTestMany3 extends TsTestManyBase {}
final class TsTestMany4 extends TsTestManyBase {}
final class TsTestMany5 extends TsTestManyBase {}
final class TsTestMany6 extends TsTestManyBase {}
final class TsTestMany7 extends TsTestManyBase {}
final class TsTestMany8 extends TsTestManyBase {}
final class TsTestMany9 extends TsTestManyBase {}

$out = [];
for ($i = 1; $i <= 9; $i++) $out["t$i"] = "TsTestMany$i";
return $out;
