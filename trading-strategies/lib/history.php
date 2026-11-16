<?php
/**
 * H1 — השיטה גם על ההיסטוריה (spec-stage-a 18.1.8, 2.39): **אותות עבר** — מה שהשיטה הייתה אומרת בנרות שבגרף, ומה קרה.
 *
 * - **המקור:** אותו מנוע של הבדיקה לאחור (ts_bt_run, 6.2 א–ח) על הנרות הסגורים של הגרף בלבד — אותן עמלות (ts_cost_for) ואותם
 *   פרמטרים (ts_params_for_asset, כמו ts_backtest). אות בנר i רק מנרות ≤ i: המנוע לא מציץ קדימה, ועסקה יוצאת רק כשנר היציאה נסגר.
 * - **אות = עסקה של המנוע:** אות שהגיע בזמן עסקה פתוחה (ז) — לא עסקה, ולא מוצג. אות שלא נפתח בגלל פער (ח, skipped_gap) — לא מוצג
 *   (אין לו R). כך מספר האותות, הכניסה, הסטופ והתוצאה = המנוע (קריטריון 2).
 * - **פתוח:** עסקה שעוד בתוך האופק בנר האחרון (pos), או אות בנר האחרון שייכנס בפתיחה הבאה (pend) — result: open, בלי R.
 * - **תקרה** (סעיף 4): 50 האחרונים לשיטה; n — כמה היו בסך הכול ("מוצגים 50 האחרונים מתוך N").
 * - **הכלל:** התנאים של השיטה בנר האות (conditions, רק של הצד של האות) — לחלונית ("אות לונג · היה").
 * - **רק תצוגה** (קריטריון 10): לא נכתב כלום — לא התראות, לא שקלול, לא method_journal ולא method_outcomes.
 *
 * נקרא מ-ts_method_result (lib/refresh.php): נשמר יחד עם תוצאת השיטה במטמון (method_results), עד הנר הסגור הבא.
 * **מנות ישנות** (#1724, טעינה בפעימות) — לא כאן: השיטות רצות על החלון של chart_refresh בלבד (עד 5,000 נרות).
 */

declare(strict_types=1);

require_once __DIR__ . '/analysis.php';

const TS_HISTORY_SHOW = 50;   // [שלנו, 18.1.8 סעיף 4] — אותות לשיטה על המסך, האחרונים

/** תנאי הצד של האות, בנר האות — {text, met}. met: null — "בדוק בעצמך" (17.1.9 ז) */
function ts_history_rule(TsMethod $m, array $ms, array $p, string $side): array
{
    try {
        $c = $m->conditions($ms, $p);
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach ($c['conditions'] ?? [] as $x) {
        if (($x['side'] ?? null) !== $side && ($x['side'] ?? null) !== 'both') continue;
        $out[] = ['text' => (string)$x['text'], 'met' => $x['met'] === null ? null : (bool)$x['met']];
    }
    return $out;
}

/**
 * אותות העבר של שיטה על הנרות הסגורים של הגרף ($rows — מחרוזות, ממוינים). מחזיר:
 * {n, bars (כמה נרות סגורים — "N אותות ב-M הנרות האחרונים"), from, to, items: [{t, side, entry, stop, target, t_entry, t_exit, exit, result win|loss|horizon|open, r, rule[]}]}
 * מחירים — float (תצוגה בלבד; הדפדפן מעגל לפי ה-tick). t_exit / exit / r — null כשהאות פתוח.
 */
function ts_history_signals(TsMethod $m, array $p, string $asset, array $rows): array
{
    $fl = ts_bt_floats($rows);
    $base = ['n' => 0, 'bars' => count($fl), 'from' => $fl[0]['t'] ?? null, 'to' => $fl ? end($fl)['t'] : null, 'items' => []];
    if (!$fl) return $base;
    $p = ts_params_for_asset($m, $p, ts_tick_decimals($rows));
    $rules = [];   // t של נר האות → מצב השיטה (רק ל-TS_HISTORY_SHOW האחרונים — נחתך בסוף)
    $trades = [];
    $s = ts_bt_run($m, $p, $fl, ts_cost_for(ts_asset_market($asset)), null, null,
        function (array $t) use (&$trades): void { if ($t['result'] !== 'skipped_gap') $trades[] = $t; },
        function (int $t, array $ms) use (&$rules): void {
            $rules[$t] = $ms;
            if (count($rules) > 4 * TS_HISTORY_SHOW) $rules = array_slice($rules, -2 * TS_HISTORY_SHOW, null, true);   // אות עם פער (ח) לא נספר — מרווח
        });
    $f = fn($v) => $v === null || !is_finite((float)$v) ? null : (float)$v;
    $items = [];
    foreach ($trades as $t) {
        $items[] = ['t' => (int)$t['t_signal'], 'side' => $t['side'], 'entry' => $f($t['entry']), 'stop' => $f($t['stop']), 'target' => $f($t['target']),
            't_entry' => $t['t_entry'] === null ? null : (int)$t['t_entry'], 't_exit' => (int)$t['t_exit'], 'exit' => $f($t['exit']),
            'result' => $t['result'], 'r' => $t['r'] === null ? null : round((float)$t['r'], 4)];
    }
    // פתוח בנר האחרון: עסקה שנכנסה (pos), או אות שייכנס בפתיחה הבאה (pend) — בלי R סופי
    if (($q = $s['pos']) !== null) {
        $items[] = ['t' => (int)$q['sig_t'], 'side' => $q['side'], 'entry' => $f($q['entry']), 'stop' => $f($q['stop']), 'target' => $f($q['target']),
            't_entry' => (int)$q['entry_t'], 't_exit' => null, 'exit' => null, 'result' => 'open', 'r' => null];
    } elseif (($q = $s['pend']) !== null) {
        $items[] = ['t' => (int)$q['sig_t'], 'side' => $q['side'], 'entry' => null, 'stop' => isset($q['stop']) ? $f($q['stop']) : null,
            'target' => isset($q['stop']) ? $f($q['target']) : null, 't_entry' => null, 't_exit' => null, 'exit' => null, 'result' => 'open', 'r' => null];
    }
    $n = count($items);
    $items = array_slice($items, -TS_HISTORY_SHOW);
    foreach ($items as &$it) $it['rule'] = isset($rules[$it['t']]) ? ts_history_rule($m, $rules[$it['t']], $p, $it['side']) : [];
    unset($it);
    return ['n' => $n, 'items' => $items] + $base;
}
