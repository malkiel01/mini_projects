<?php
/**
 * שיטה מוגדרת (spec-stage-a 17.2.1, PR 4ב/1): מתאם אחד שמממש את הממשק של 6.1 (TsMethod) מתוך הגדרת JSON.
 * כך הבדיקה לאחור, הניתוח והשקלול עובדים בלי שינוי, עם אותן הבטחות: טהור, בלי הצצה, מצטבר = מלא.
 *
 * ההגדרה (spec-method-builder.md 3):
 *   id, name, source, version, markets[], timeframes[], params{key: {label, default, min, max, int}},
 *   indicators[{id, type: sma|ema|rsi|atr|volume, source: open|high|low|close, length: מספר | "$param"}],
 *   rules{long|short: {all|any: [ביטוי | {left, op, right}]}},
 *   levels{stop: ביטוי, target: ביטוי | null} — עם entry (סגירת נר האות) ו-dir (+1 לונג, −1 שורט),
 *   horizon_bars: מספר | "$param", validate[{expr: ביטוי על פרמטרים בלבד, message}] (לא חובה),
 *   forecast, display — נשמרים, ומשמשים בשכבת התחזית (4ב/2). כאן לא בשימוש.
 *
 * הביטויים — lib/expr.php (שפה סגורה, בלי eval). האינדיקטורים מחושבים בזרימה, באותו סדר פעולות כמו
 * lib/indicators.php (SMA — סכום של החלון, כמו sample-ma-cross; EMA, RSI ו-ATR — Wilder/זרע כמו ts_ema/ts_rsi/ts_atr).
 *
 * עוד לא נטען מהמסד ולא ברשימת השיטות — זה 4ב/3 (מסך המנהל, methods.definition, method_versions).
 */

declare(strict_types=1);

require_once __DIR__ . '/base.php';
require_once dirname(__DIR__) . '/expr.php';

const TS_DEF_MARKETS = ['crypto', 'stocks', 'forex', 'indices', 'commodities', 'futures'];
const TS_DEF_TIMEFRAMES = ['m1', 'm5', 'm15', 'm30', 'h1', 'h4', 'd1', 'w1'];
const TS_DEF_TYPES = ['sma', 'ema', 'rsi', 'atr', 'volume'];
const TS_DEF_PRICES = ['open' => 'o', 'high' => 'h', 'low' => 'l', 'close' => 'c'];
const TS_DEF_KEYS = ['id', 'name', 'source', 'version', 'markets', 'timeframes', 'params', 'indicators', 'rules', 'levels',
    'horizon_bars', 'validate', 'forecast', 'display', 'sample', 'label'];
const TS_DEF_MAX = ['params' => 20, 'indicators' => 20, 'conditions' => 10, 'validate' => 10, 'length' => 1000, 'json' => 20000];

final class TsDefinedMethod extends TsMethod
{
    private array $d;
    /** @var array{long: list<array>, short: list<array>} העצים של התנאים */
    private array $rules = ['long' => [], 'short' => []];
    private array $mode = ['long' => 'all', 'short' => 'all'];
    private array $src = ['long' => [], 'short' => []];
    private array $stop;
    private ?array $target;
    private array $checks = [];

    /** @throws TsExprError הגדרה לא תקינה — ההודעה בעברית, בלי לשכפל את הקלט */
    public function __construct(array $def)
    {
        $bad = fn(string $m) => throw new TsExprError($m);
        if (strlen(json_encode($def)) > TS_DEF_MAX['json']) $bad('ההגדרה גדולה מדי');
        if ($extra = array_diff(array_keys($def), TS_DEF_KEYS)) $bad('שדה לא מוכר בהגדרה: ' . implode(', ', array_slice($extra, 0, 3)));
        $id = $def['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9-]{2,39}$/', $id)) $bad('מזהה לא תקין (a-z, 0-9, מקף; 3–40 תווים)');
        foreach (['name' => 80, 'source' => 200] as $k => $max) {
            if (!is_string($def[$k] ?? null) || $def[$k] === '' || mb_strlen($def[$k]) > $max) $bad("שדה $k חסר או ארוך מדי");
        }
        if (!is_int($def['version'] ?? null) || $def['version'] < 1) $bad('גרסה — מספר שלם ≥ 1');
        foreach (['markets' => TS_DEF_MARKETS, 'timeframes' => TS_DEF_TIMEFRAMES] as $k => $ok) {
            $v = $def[$k] ?? null;
            if (!is_array($v) || !$v || !array_is_list($v) || array_diff($v, $ok) || count(array_unique($v)) !== count($v)) $bad("$k — רשימה לא תקינה");
        }

        // פרמטרים
        $params = $def['params'] ?? [];
        if (!is_array($params) || count($params) > TS_DEF_MAX['params'] || ($params && array_is_list($params))) $bad('פרמטרים — עד ' . TS_DEF_MAX['params']);
        foreach ($params as $k => $p) {
            if (!is_string($k) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $k)) $bad('שם פרמטר לא תקין');
            if (!is_array($p) || !is_string($p['label'] ?? null)) $bad("פרמטר $k: חסרה תווית");
            foreach (['default', 'min', 'max'] as $f) if (!is_int($p[$f] ?? null) && !is_float($p[$f] ?? null)) $bad("פרמטר $k: $f חייב להיות מספר");
            if (!($p['min'] <= $p['default'] && $p['default'] <= $p['max'])) $bad("פרמטר $k: ברירת המחדל מחוץ לטווח");
            if (isset($p['int']) && !is_bool($p['int'])) $bad("פרמטר $k: int חייב להיות true/false");
        }
        $intParam = fn(string $ref) => preg_match('/^\$([a-z][a-z0-9_]{0,39})$/', $ref, $m) && isset($params[$m[1]]) && !empty($params[$m[1]]['int']) ? $m[1] : null;

        // אינדיקטורים
        $inds = $def['indicators'] ?? [];
        if (!is_array($inds) || !array_is_list($inds) || count($inds) > TS_DEF_MAX['indicators']) $bad('אינדיקטורים — רשימה של עד ' . TS_DEF_MAX['indicators']);
        $names = array_fill_keys(array_keys(TS_DEF_PRICES), true);
        foreach ($inds as $i => $ind) {
            $iid = $ind['id'] ?? null;
            if (!is_string($iid) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $iid) || in_array($iid, ['entry', 'dir', 'and', 'or', 'crosses_above', 'crosses_below'], true)) $bad('מזהה אינדיקטור לא תקין');
            if (isset($names[$iid])) $bad("מזהה כפול: $iid");
            if (!in_array($ind['type'] ?? null, TS_DEF_TYPES, true)) $bad("$iid: סוג לא מוכר (" . implode(', ', TS_DEF_TYPES) . ')');
            if (in_array($ind['type'], ['sma', 'ema', 'rsi'], true) && !isset(TS_DEF_PRICES[$ind['source'] ?? ''])) $bad("$iid: מקור — open, high, low או close");
            if ($ind['type'] !== 'volume') {
                $len = $ind['length'] ?? null;
                if (is_string($len) ? $intParam($len) === null : (!is_int($len) || $len < 1 || $len > TS_DEF_MAX['length'])) $bad("$iid: אורך — מספר שלם 1–" . TS_DEF_MAX['length'] . ' או פרמטר שלם ($name)');
                if (is_string($len) && ($params[substr($len, 1)]['min'] < 1 || $params[substr($len, 1)]['max'] > TS_DEF_MAX['length'])) $bad("$iid: טווח הפרמטר של האורך — 1–" . TS_DEF_MAX['length']);
            }
            $names[$iid] = true;
        }

        // כללים
        $rules = $def['rules'] ?? null;
        if (!is_array($rules) || !$rules || array_diff(array_keys($rules), ['long', 'short'])) $bad('כללים: long ו/או short');
        foreach ($rules as $side => $r) {
            $mode = is_array($r) && count($r) === 1 ? array_key_first($r) : null;
            if (!in_array($mode, ['all', 'any'], true) || !is_array($r[$mode]) || !array_is_list($r[$mode]) || !$r[$mode] || count($r[$mode]) > TS_DEF_MAX['conditions']) $bad("כללי $side: {all|any: [עד " . TS_DEF_MAX['conditions'] . ' תנאים]}');
            $this->mode[$side] = $mode;
            foreach ($r[$mode] as $c) {
                if (is_array($c)) {
                    if (count($c) !== 3 || array_diff(['left', 'op', 'right'], array_keys($c))) $bad("כללי $side: תנאי {left, op, right}");
                    if (!in_array($c['op'], TS_EXPR_CMP, true)) $bad("כללי $side: פעולה לא מוכרת");
                    foreach (['left', 'right'] as $f) if (!is_string($c[$f]) && !is_int($c[$f]) && !is_float($c[$f])) $bad("כללי $side: $f חייב להיות ביטוי");
                    $c = '(' . $c['left'] . ') ' . $c['op'] . ' (' . $c['right'] . ')';
                }
                if (!is_string($c)) $bad("כללי $side: תנאי חייב להיות ביטוי");
                $this->rules[$side][] = ts_expr_parse($c, $names, $params, 'bool');
                $this->src[$side][] = $c;
            }
        }

        // רמות
        $lv = $def['levels'] ?? null;
        if (!is_array($lv) || !is_string($lv['stop'] ?? null) || !array_key_exists('target', $lv) || array_diff(array_keys($lv), ['stop', 'target'])) $bad('רמות: {stop, target}');
        $lvNames = $names + ['entry' => true, 'dir' => true];
        $this->stop = ts_expr_parse($lv['stop'], $lvNames, $params, 'num');
        $this->target = $lv['target'] === null ? null : (is_string($lv['target']) ? ts_expr_parse($lv['target'], $lvNames, $params, 'num') : $bad('רמות: target — ביטוי או null'));

        // אופק
        $h = $def['horizon_bars'] ?? null;
        if (is_string($h) ? $intParam($h) === null : (!is_int($h) || $h < 1 || $h > 500)) $bad('אופק — מספר שלם 1–500 או פרמטר שלם');

        // בדיקות צירוף פרמטרים
        $v = $def['validate'] ?? [];
        if (!is_array($v) || !array_is_list($v) || count($v) > TS_DEF_MAX['validate']) $bad('validate — רשימה');
        foreach ($v as $c) {
            if (!is_string($c['expr'] ?? null) || !is_string($c['message'] ?? null) || mb_strlen($c['message']) > 200) $bad('validate: {expr, message}');
            $this->checks[] = [ts_expr_parse($c['expr'], [], $params, 'bool'), $c['message']];
        }
        $this->d = $def;
    }

    public function meta(): array
    {
        $d = $this->d;
        $params = [];
        foreach ($d['params'] ?? [] as $k => $p) $params[$k] = ['label' => $p['label'], 'default' => $p['default'], 'min' => $p['min'], 'max' => $p['max'], 'int' => !empty($p['int'])];
        return [
            'id' => $d['id'], 'name' => $d['name'], 'source' => $d['source'],
            'sample' => !empty($d['sample']), 'label' => $d['label'] ?? 'מוגדרת ידנית',
            'kind' => 'defined',
            'markets' => $d['markets'], 'timeframes' => $d['timeframes'], 'params' => $params,
            'horizon_bars' => is_int($d['horizon_bars']) ? $d['horizon_bars'] : (int)$d['params'][substr($d['horizon_bars'], 1)]['default'],
            'warmup' => $this->warmup(),
            'version' => $d['version'], 'incremental' => true, 'alerts' => false,
        ];
    }

    /** נרות עד שכל האינדיקטורים מחושבים — לפי ברירות המחדל והמקסימום של הפרמטרים */
    private function warmup(): int
    {
        $w = 1;
        foreach ($this->d['indicators'] as $ind) {
            $len = $ind['length'] ?? 1;
            if (is_string($len)) $len = (int)$this->d['params'][substr($len, 1)]['max'];
            $w = max($w, $len + 1);
        }
        return $w;
    }

    public function horizon(array $p): int
    {
        $h = $this->d['horizon_bars'];
        return is_int($h) ? $h : (int)$p[substr($h, 1)];
    }

    public function validate(array $p): ?string
    {
        foreach ($this->checks as [$tree, $msg]) if (!ts_expr_eval($tree, [], [], $p)) return $msg;
        return null;
    }

    private function len(array $ind, array $p): int
    {
        return is_string($ind['length']) ? (int)$p[substr($ind['length'], 1)] : (int)$ind['length'];
    }

    public function init(array $p): array
    {
        $ind = [];
        foreach ($this->d['indicators'] as $i) {
            $ind[$i['id']] = match ($i['type']) {
                'sma' => ['w' => []],
                'ema' => ['w' => [], 'e' => null],
                'rsi' => ['n' => 0, 'pv' => null, 'g' => 0.0, 'l' => 0.0, 'v' => null],
                'atr' => ['n' => 0, 'sum' => 0.0, 'v' => null, 'pc' => null],
                'volume' => [],
            };
        }
        // vars / pv — ערכי השמות בנר הנוכחי ובקודם; x — האות האחרון
        return ['ind' => $ind, 'vars' => null, 'pv' => null, 'x' => null];
    }

    public function step(array $s, array $c, array $p): array
    {
        if ($msg = $this->validate($p)) throw new InvalidArgumentException($msg);
        $vars = ['open' => $c['o'], 'high' => $c['h'], 'low' => $c['l'], 'close' => $c['c']];
        foreach ($this->d['indicators'] as $i) {
            $id = $i['id'];
            [$s['ind'][$id], $vars[$id]] = $this->stepIndicator($i, $s['ind'][$id], $c, $p);
        }
        $s['pv'] = $s['vars'];
        $s['vars'] = $vars;
        if ($s['x'] !== null) $s['x']['ago']++;

        $sig = null;
        foreach (['long' => 1.0, 'short' => -1.0] as $side => $dir) {
            if (!$this->sideMet($side, $s, $p)) continue;
            $lv = $vars + ['entry' => $c['c'], 'dir' => $dir];
            $stop = ts_expr_eval($this->stop, $lv, [], $p);
            $target = $this->target === null ? null : ts_expr_eval($this->target, $lv, [], $p);
            if ($stop === null || ($this->target !== null && $target === null)) break;   // אינדיקטור שעוד לא התחמם — אין אות
            $sig = ['side' => $side, 'entry' => $c['c'], 'stop' => $stop, 'target' => $target];
            $s['x'] = $sig + ['ago' => 0];
            break;   // לונג קודם (כמו sample-ma-cross)
        }
        return [$s, $sig];
    }

    private function sideMet(string $side, array $s, array $p): bool
    {
        if (!$this->rules[$side] || $s['vars'] === null) return false;
        $res = array_map(fn($t) => (bool)ts_expr_eval($t, $s['vars'], $s['pv'] ?? [], $p), $this->rules[$side]);
        return $this->mode[$side] === 'all' ? !in_array(false, $res, true) : in_array(true, $res, true);
    }

    /** אינדיקטור אחד, נר אחד — באותו סדר פעולות כמו lib/indicators.php */
    private function stepIndicator(array $i, array $st, array $c, array $p): array
    {
        if ($i['type'] === 'volume') return [$st, isset($c['v']) ? (float)$c['v'] : null];
        $n = $this->len($i, $p);
        if ($i['type'] === 'atr') {
            $tr = ts_tr($c['h'], $c['l'], $st['pc']);
            if ($st['n'] < $n) {
                $st['sum'] += $tr;
                if ($st['n'] === $n - 1) $st['v'] = $st['sum'] / $n;
            } else {
                $st['v'] = ($st['v'] * ($n - 1) + $tr) / $n;
            }
            $st['n']++;
            $st['pc'] = $c['c'];
            return [$st, $st['v']];
        }
        $x = $c[TS_DEF_PRICES[$i['source']]];
        if ($i['type'] === 'sma') {
            $st['w'][] = $x;
            if (count($st['w']) > $n) array_shift($st['w']);
            return [$st, count($st['w']) === $n ? array_sum($st['w']) / $n : null];
        }
        if ($i['type'] === 'ema') {
            if ($st['e'] === null) {
                $st['w'][] = $x;
                if (count($st['w']) < $n) return [$st, null];
                $st['e'] = array_sum($st['w']) / $n;
                $st['w'] = [];
            } else {
                $k = 2 / ($n + 1);
                $st['e'] = $x * $k + $st['e'] * (1 - $k);
            }
            return [$st, $st['e']];
        }
        // rsi — Wilder
        if ($st['pv'] === null) { $st['pv'] = $x; return [$st, null]; }
        $d = $x - $st['pv'];
        $st['pv'] = $x;
        $st['n']++;
        if ($st['n'] <= $n) {
            if ($d > 0) $st['g'] += $d; else $st['l'] -= $d;
            if ($st['n'] < $n) return [$st, null];
            $st['g'] /= $n; $st['l'] /= $n;
        } else {
            $st['g'] = ($st['g'] * ($n - 1) + max($d, 0)) / $n;
            $st['l'] = ($st['l'] * ($n - 1) + max(-$d, 0)) / $n;
        }
        $st['v'] = $st['l'] == 0.0 ? 100.0 : 100 - 100 / (1 + $st['g'] / $st['l']);
        return [$st, $st['v']];
    }

    /**
     * התנאים אחרי הנר האחרון: כל תנאי של ההגדרה, והאם התקיים בנר הזה. bias — הצד של האות האחרון, כל עוד לא
     * עברו horizon_bars מאז (כמו sample-ma-cross); levels — הרמות שלו.
     */
    public function conditions(array $s, array $p): array
    {
        $out = [];
        foreach (['long' => 'l', 'short' => 's'] as $side => $k) {
            foreach ($this->rules[$side] as $j => $t) {
                $met = $s['vars'] !== null && (bool)ts_expr_eval($t, $s['vars'], $s['pv'] ?? [], $p);
                $out[] = ['id' => "$k-" . ($j + 1), 'side' => $side, 'text' => $this->src[$side][$j], 'met' => $met, 'value' => null];
            }
        }
        $x = $s['x'];
        $bias = $x !== null && $x['ago'] < $this->horizon($p) ? $x['side'] : 'none';
        $levels = $bias !== 'none' ? ['entry' => $x['entry'], 'stop' => $x['stop'], 'target' => $x['target']] : null;
        return ['bias' => $bias, 'conditions' => $out, 'levels' => $levels, 'signal_ago' => $bias !== 'none' ? $x['ago'] : null];
    }
}
