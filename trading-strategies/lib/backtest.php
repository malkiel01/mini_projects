<?php
/**
 * מנוע הבדיקה לאחור, הוודאות והשקלול (spec-stage-a 6.2–6.5) — פונקציות טהורות, float (עיקרון 6). בלי מסד ובלי רשת:
 * הגישה לנתונים ולמסד — lib/analysis.php.
 *
 * הכללים של 6.2, ואיפה כל אחד נאכף:
 *   (א) בלי הצצה לעתיד — השיטה רצה בזרימה (lib/methods/base.php); המנוע לא מעביר לה נר שעוד לא "נסגר" אצלו.
 *   (ב) כניסה בפתיחת הנר שאחרי נר האות (i+1), ועוד החלקה. הסטופ והיעד — באותו מרחק מהכניסה בפועל כמו מהסגירה של
 *       נר האות, ונבדקים מנר הכניסה עצמו (מהפתיחה שלו) והלאה.
 *   (ו) בלי יעד — יציאה בסגירת הנר האחרון של האופק, או בסטופ אם נגע קודם. הצלחה = רווח נטו (אחרי עמלה והחלקה) > 0.
 *   (ז) אות חדש לא נפתח כל עוד הקודם פתוח (או ממתין לכניסה).
 *   נר שנוגע ביעד וגם בסטופ — הפסד (שמרני). יש יעד: הצלחה = היעד הושג לפני הסטופ, בתוך האופק; תום האופק — לא הצלחה.
 *   פער (gap) מעבר לרמה — היציאה בפתיחה, המחיר שהיה בפועל.
 *   (ח) שיטה שב-meta() שלה levels: 'absolute' — הסטופ והיעד הם המחירים שהאות החזיר, לא מרחקים; ופתיחת i+1 מעבר
 *       לסטופ או ליעד → האות לא נפתח, ונספר ב-skipped_gap (לא ב-skipped). בלי הדגל — שום שינוי: גם המצב (JSON) של
 *       שיטה relative נשאר בדיוק כמו קודם, ומצב מצטבר ישן שלה תקף. skipped_gap נוסף למצב רק כשהוא קורה.
 *
 * המצב (JSON) שומר הכול: מצב השיטה, אות ממתין, עסקה פתוחה והמצטברים. ts_bt_run($state) ממשיך מאותה נקודה —
 * ולכן מצטבר על [0..k] ואחריו (k..N] נותן בדיוק את התוצאה של [0..N] (נבדק ב-tests/backtest.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/methods/registry.php';

final class TsBacktestTimeout extends RuntimeException {}

const TS_BT_MAX_CANDLES = 100_000;   // ✅5
const TS_BT_MAX_SECONDS = 20;        // ✅5
const TS_MIN_SIGNALS = 30;           // עיקרון 4: פחות מ-30 — בלי אחוז
const TS_WILSON_Z = 1.959963984540054;   // 95% דו-צדדי

/** נרות במחרוזות (כמו מהספק) → נרות float למנוע. כאן, ורק כאן, המחרוזות הופכות ל-float. */
function ts_bt_floats(array $rows): array
{
    $out = [];
    foreach ($rows as $r) $out[] = ['t' => (int)$r['t'], 'o' => (float)$r['o'], 'h' => (float)$r['h'], 'l' => (float)$r['l'], 'c' => (float)$r['c']];
    return $out;
}

function ts_bt_init(TsMethod $m, array $p): array
{
    return ['m' => $m->init($p), 'pend' => null, 'pos' => null, 'n' => 0, 'wins' => 0, 'sum_r' => 0.0,
        'eq' => 0.0, 'peak' => 0.0, 'dd' => 0.0, 'raw' => 0, 'skipped' => 0, 'bars' => 0, 'first_t' => null, 'last_t' => null];
}

/**
 * מריץ (או ממשיך) בדיקה לאחור על נרות סגורים, ממוינים. $cost = ['fee' => שבר לכל צד, 'slip' => שבר].
 * $deadline — microtime שאחריו TsBacktestTimeout (null — בלי תקרה; בבדיקות).
 */
function ts_bt_run(TsMethod $m, array $p, array $candles, array $cost, ?array $state = null, ?float $deadline = null): array
{
    $s = $state ?? ts_bt_init($m, $p);
    $H = $m->horizon($p);
    $abs = ($m->meta()['levels'] ?? 'relative') === 'absolute';   // (ח)
    $fee = (float)($cost['fee'] ?? 0); $slip = (float)($cost['slip'] ?? 0);
    $k = 0;
    foreach ($candles as $c) {
        if ($s['last_t'] !== null && $c['t'] <= $s['last_t']) continue;   // מצטבר: רק נרות חדשים
        if ($deadline !== null && (++$k % 2000) === 0 && microtime(true) > $deadline) throw new TsBacktestTimeout('הבדיקה לאחור לא הושלמה בזמן');
        $s['first_t'] ??= $c['t'];

        // (ב) כניסה בפתיחת הנר שאחרי האות
        if ($s['pend'] !== null) {
            $q = $s['pend'];
            $dir = $q['side'] === 'long' ? 1 : -1;
            $entry = $c['o'] * (1 + $dir * $slip);
            if (isset($q['stop'])) {
                // (ח) רמות מוחלטות: פתיחה מעבר לסטופ או ליעד — האות לא נפתח
                $gap = $dir > 0 ? $c['o'] <= $q['stop'] || ($q['target'] !== null && $c['o'] >= $q['target'])
                    : $c['o'] >= $q['stop'] || ($q['target'] !== null && $c['o'] <= $q['target']);
                if ($gap) {
                    $s['skipped_gap'] = ($s['skipped_gap'] ?? 0) + 1;
                } else {
                    $s['pos'] = ['side' => $q['side'], 'entry' => $entry, 'risk' => $dir * ($entry - $q['stop']),
                        'stop' => $q['stop'], 'target' => $q['target'], 'bars' => 0, 'sig_t' => $q['sig_t'], 'entry_t' => $c['t']];
                }
            } else {
                $s['pos'] = ['side' => $q['side'], 'entry' => $entry, 'risk' => $q['stop_d'],
                    'stop' => $entry - $dir * $q['stop_d'], 'target' => $q['target_d'] === null ? null : $entry + $dir * $q['target_d'],
                    'bars' => 0, 'sig_t' => $q['sig_t'], 'entry_t' => $c['t']];
            }
            $s['pend'] = null;
        }

        // יציאה — נר הכניסה נבדק גם הוא
        if ($s['pos'] !== null) {
            $pos = &$s['pos'];
            $pos['bars']++;
            $dir = $pos['side'] === 'long' ? 1 : -1;
            $hitStop = $dir > 0 ? $c['l'] <= $pos['stop'] : $c['h'] >= $pos['stop'];
            $hitTarget = $pos['target'] !== null && ($dir > 0 ? $c['h'] >= $pos['target'] : $c['l'] <= $pos['target']);
            $exit = null; $win = false;
            if ($hitStop) {   // גם אם נגע ביעד באותו נר — הפסד
                $exit = $dir > 0 ? min($c['o'], $pos['stop']) : max($c['o'], $pos['stop']);
            } elseif ($hitTarget) {
                $exit = $dir > 0 ? max($c['o'], $pos['target']) : min($c['o'], $pos['target']);
                $win = true;
            } elseif ($pos['bars'] >= $H) {
                $exit = $c['c'];
            }
            if ($exit !== null) {
                $exitEff = $exit * (1 - $dir * $slip);
                $net = $dir * ($exitEff - $pos['entry']) - $fee * ($pos['entry'] + $exitEff);
                $r = $pos['risk'] > 0 ? $net / $pos['risk'] : 0.0;
                if ($pos['target'] === null) $win = $net > 0;   // (ו)
                $s['n']++;
                if ($win) $s['wins']++;
                $s['sum_r'] += $r;
                $s['eq'] += $r;
                if ($s['eq'] > $s['peak']) $s['peak'] = $s['eq'];
                if ($s['peak'] - $s['eq'] > $s['dd']) $s['dd'] = $s['peak'] - $s['eq'];
                unset($pos);
                $s['pos'] = null;
            } else {
                unset($pos);
            }
        }

        // השיטה רואה את הנר רק עכשיו — אחרי שנסגר
        [$s['m'], $sig] = $m->step($s['m'], $c, $p);
        if ($sig) {
            $s['raw']++;
            $risk = abs($sig['entry'] - $sig['stop']);
            if ($s['pos'] !== null || $s['pend'] !== null) {
                $s['skipped']++;   // (ז)
            } elseif ($risk > 0 && $abs) {
                $s['pend'] = ['side' => $sig['side'], 'stop' => (float)$sig['stop'],
                    'target' => $sig['target'] === null ? null : (float)$sig['target'], 'sig_t' => $c['t']];
            } elseif ($risk > 0) {
                $s['pend'] = ['side' => $sig['side'], 'stop_d' => $risk,
                    'target_d' => $sig['target'] === null ? null : abs($sig['target'] - $sig['entry']), 'sig_t' => $c['t']];
            }
        }
        $s['bars']++;
        $s['last_t'] = $c['t'];
    }
    return $s;
}

/** התוצאה לתצוגה ולשמירה (backtests) */
function ts_bt_result(array $s): array
{
    $n = (int)$s['n'];
    return ['n' => $n, 'wins' => (int)$s['wins'], 'win_rate' => $n ? $s['wins'] / $n : null,
        'expectancy_r' => $n ? $s['sum_r'] / $n : null, 'max_dd' => (float)$s['dd'],
        'from_t' => $s['first_t'], 'to_t' => $s['last_t'], 'bars' => (int)$s['bars'],
        'signals' => (int)$s['raw'], 'skipped' => (int)$s['skipped'], 'skipped_gap' => (int)($s['skipped_gap'] ?? 0), 'open' => $s['pos'] !== null || $s['pend'] !== null];
}

/** הגבול התחתון של רווח הסמך של Wilson (95%) — 6.3. null כש-N = 0 */
function ts_wilson_lower(int $wins, int $n, float $z = TS_WILSON_Z): ?float
{
    if ($n <= 0) return null;
    $p = $wins / $n;
    $z2 = $z * $z;
    return ($p + $z2 / (2 * $n) - $z * sqrt($p * (1 - $p) / $n + $z2 / (4 * $n * $n))) / (1 + $z2 / $n);
}

/** ודאות באחוזים שלמים — או null כש-N < 30 (עיקרון 4) */
function ts_certainty(int $wins, int $n): ?int
{
    if ($n < TS_MIN_SIGNALS) return null;
    return (int)round(100 * ts_wilson_lower($wins, $n));
}

/** העדפה לפי המקום ברשימה (6.5): 1.0, 0.8, 0.6 … ולא ברשימה — 0.5. לא יורד מתחת ל-0.5 */
function ts_preference(string $id, array $preferred): float
{
    $i = array_search($id, $preferred, true);
    return $i === false ? 0.5 : max(0.5, 1.0 - 0.2 * $i);
}

/**
 * שקלול (6.5). $cards: [{id, bias long|short|none, certainty int|null}]. שיטה בלי ודאות — לא בשקלול, ברשימה נפרדת.
 * מחזיר score בין −1 ל-+1 (או null), הסכמה, ומחלוקת חזקה: Σ(לונג) ≥ 30% וגם Σ(שורט) ≥ 30% מ-Σ משקל.
 */
function ts_blend(array $cards, array $preferred): array
{
    $sum = 0.0; $num = 0.0; $long = 0.0; $short = 0.0;
    $in = []; $out = []; $counts = ['long' => 0, 'short' => 0, 'none' => 0];
    foreach ($cards as $c) {
        if ($c['certainty'] === null) { $out[] = $c['id']; continue; }
        $w = ($c['certainty'] / 100) * ts_preference($c['id'], $preferred);
        $d = ['long' => 1, 'short' => -1][$c['bias']] ?? 0;
        $sum += $w; $num += $w * $d;
        if ($d > 0) $long += $w; elseif ($d < 0) $short += $w;
        $counts[$c['bias'] === 'long' || $c['bias'] === 'short' ? $c['bias'] : 'none']++;
        $in[] = ['id' => $c['id'], 'weight' => $w];
    }
    $score = $sum > 0 ? $num / $sum : null;
    return ['score' => $score, 'included' => $in, 'excluded' => $out, 'counts' => $counts, 'total' => count($in),
        'strong_disagreement' => $sum > 0 && $long >= 0.3 * $sum && $short >= 0.3 * $sum,
        'lean' => $score === null ? null : ($score > 0.05 ? 'long' : ($score < -0.05 ? 'short' : 'none'))];
}
