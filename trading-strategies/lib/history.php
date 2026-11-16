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
 * - **אזור** (H1b, סעיף 3): אות שהשיטה שלו נשענת על אזור (פיבונאצ'י — x.zone במצב בנר האות) — zone: [נמוך, גבוה], למלבן
 *   עמום מנר האות עד היציאה. בלי אזור — null.
 * - **ספירות עבר** (H1b, סעיף 2): counts — דחפים שלמים שנגמרו בעבר (TsMethod::pastCounts; רק אליוט). עד 5, בלי חפיפה.
 * - **רק תצוגה** (קריטריון 10): לא נכתב כלום — לא התראות, לא שקלול, לא method_journal ולא method_outcomes.
 *
 * נקרא מ-ts_method_result (lib/refresh.php): נשמר יחד עם תוצאת השיטה במטמון (method_results), עד הנר הסגור הבא.
 * **מנות ישנות** (#1724, טעינה בפעימות) — ts_method_history (H1c, סעיף 6): לכל מנה, רק מה שלפני תחילת הטווח הקודם.
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
 * {n, bars (כמה נרות סגורים — "N אותות ב-M הנרות האחרונים"), from, to, items: [{t, side, entry, stop, target, t_entry, t_exit, exit, result win|loss|horizon|open, r, rule[], zone}],
 *  counts: [ספירות עבר — ts_ew_past_counts]}. $tf — אורך הנר בשניות (null — לפי שני הנרות הראשונים).
 * $before (H1c, מנה ישנה): רק אותות וספירות עם t < before — n, items (50 האחרונים מביניהם) ו-counts; bars — הנרות שלפני before.
 * מחירים — float (תצוגה בלבד; הדפדפן מעגל לפי ה-tick). t_exit / exit / r — null כשהאות פתוח.
 */
function ts_history_signals(TsMethod $m, array $p, string $asset, array $rows, ?int $tf = null, ?int $before = null): array
{
    $fl = ts_bt_floats($rows);
    $base = ['n' => 0, 'bars' => count($fl), 'from' => $fl[0]['t'] ?? null, 'to' => $fl ? end($fl)['t'] : null, 'items' => [], 'counts' => []];
    if (!$fl) return $base;
    $tf ??= count($fl) > 1 ? max(1, $fl[1]['t'] - $fl[0]['t']) : 3600;
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
    if ($before !== null) {
        $items = array_values(array_filter($items, fn($x) => $x['t'] < $before));
        $base['bars'] = count(array_filter($fl, fn($c) => $c['t'] < $before));
        $base['to'] = $base['bars'] ? $fl[$base['bars'] - 1]['t'] : null;
    }
    $n = count($items);
    $items = array_slice($items, -TS_HISTORY_SHOW);
    foreach ($items as &$it) {
        $ms = $rules[$it['t']] ?? null;
        $it['rule'] = $ms !== null ? ts_history_rule($m, $ms, $p, $it['side']) : [];
        $z = $ms['x']['zone'] ?? null;   // האות של הנר הזה (x נקבע באותו step) — האזור שהוא נגע בו
        $it['zone'] = is_array($z) && count($z) === 2 && ($ms['x']['ago'] ?? null) === 0 ? [(float)min($z), (float)max($z)] : null;
    }
    unset($it);
    return ['n' => $n, 'items' => $items, 'counts' => $m->pastCounts($fl, $p, $tf, $before)] + $base;
}

/** [שלנו, H1c] — כמה נרות אחרי before נכנסים לחישוב של מנה ישנה, מעבר לאופק: אישור נקודות המפנה שבגבול (ספירות אליוט) */
const TS_HISTORY_TAIL = 300;

/**
 * H1c (18.1.8 סעיף 6, קריטריון 12): אותות וספירות עבר של מנה ישנה שנטענה בגלילה (market_history). $from — הנר הראשון של המנה,
 * $before — תחילת הטווח שהיה טעון קודם. מחזיר {methods: {id: {status, history}}}, ובכל history רק פריטים עם t < before —
 * מה שכבר על המסך לא זז.
 *
 * - **הנרות והחימום** — ts_market_candles: הטבלה history (שם market_history שמר את המנה) והמאגר; פנייה לספק רק על חסר.
 * - **הטווח:** [from, before + (אופק + TS_HISTORY_TAIL) נרות] — לא כל הגרף. המנוע לא מציץ קדימה, ועסקה שנפתחה לפני before
 *   נסגרת בתוך האופק — ולכן כל אות עם t < before זהה לחישוב על כל הנרות שבגרף, בכמה אלפי נרות לבקשה גם בגרף של 50,000.
 * - **מטמון:** method_results, במפתח של הנרות + before. מנה ישנה לא משתנה — אותה מנה שוב לא מריצה את השיטה.
 * - **רק תצוגה** — כמו H1a: בלי יומן, בלי התראות, בלי שקלול.
 */
function ts_method_history(int $uid, mixed $asset, mixed $tf, mixed $from, mixed $before, mixed $methods, ?int $now = null): array
{
    if (!is_string($tf) || !isset(TS_TF_SECONDS[$tf])) throw new TsInvalid('מסגרת זמן לא תקינה');
    if (!is_numeric($from) || !is_numeric($before) || (int)$from >= (int)$before) throw new TsInvalid('טווח לא תקין');
    [$provider] = ts_asset_split($asset);
    if (($alt = ts_pair_tf_asset($uid, (string)$asset, $tf)) !== null) [$asset, $provider] = [$alt, explode(':', $alt, 2)[0]];
    $ids = is_string($methods) && $methods !== '' ? array_values(array_unique(explode(',', $methods))) : [];
    if (!$ids) throw new TsInvalid('לא נבחרה שיטה');
    if (count($ids) > TS_REFRESH_MAX_METHODS) throw new TsInvalid('יותר מדי שיטות בבקשה אחת (עד ' . TS_REFRESH_MAX_METHODS . ')');
    $now ??= ts_now();
    $sec = TS_TF_SECONDS[$tf];
    $from = (int)$from; $before = (int)$before;
    $prepared = [];
    $tail = 0;
    $out = [];
    foreach ($ids as $id) {
        try {
            [$m, $set, $p] = ts_method_for($uid, $id);
        } catch (TsNotFound) {
            $out[$id] = ['status' => 'unavailable', 'history' => null];
            continue;
        }
        if (!ts_overlay_supported($set, (string)$asset, $tf)) { $out[$id] = ['status' => 'unavailable', 'history' => null]; continue; }
        $p = ts_params_for_segment($m, $set, $p, (string)$asset, $tf);
        $prepared[$id] = [$m, $p];
        $tail = max($tail, $m->horizon($p) + TS_HISTORY_TAIL);
    }
    $r = ['provider_calls' => 0, 'candles' => []];
    if ($prepared) {
        // המנה + הזנב — לא יותר מבקשה אחת של גרף (ts_market_candles חותך מההתחלה; כאן חותכים את הזנב)
        $from = max($from, $before - (TS_MAX_CANDLES - 2 * TS_HISTORY_TAIL) * $sec);
        $to = min($now, $before + $tail * $sec, $from + (TS_MAX_CANDLES - 1) * $sec);
        $r = ts_market_candles($uid, $asset, $tf, $from, $to, $now);
    }
    $rows = ts_closed_rows(array_values(array_filter($r['candles'], fn($c) => $c['t'] >= $from)), $tf, $now);
    $scope = (string)(ts_scope_for($uid, $provider) ?? '');
    $c = ts_cache();
    foreach ($prepared as $id => [$m, $p]) {
        $k = ts_method_key($m, $p, (string)$asset, $tf, "$scope|h1c|$before", $rows);
        $st = $c->prepare('SELECT result FROM method_results WHERE k = ?');
        $st->execute([$k]);
        $hit = $st->fetchColumn();
        if (is_string($hit) && is_array($h = json_decode($hit, true))) { $out[$id] = ['status' => 'ok', 'history' => $h, 'ran' => false]; continue; }
        try {
            $h = ts_history_signals($m, $p, (string)$asset, $rows, $sec, $before);
        } catch (Throwable $e) {
            error_log('trading-strategies method_history ' . $id . ': ' . $e->getMessage());
            $out[$id] = ['status' => 'error', 'history' => null];
            continue;
        }
        $json = json_encode($h, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        try {
            $c->prepare('INSERT OR REPLACE INTO method_results (k, method_id, result, at) VALUES (?, ?, ?, ?)')->execute([$k, $m->meta()['id'], $json, $now]);
        } catch (Throwable $e) {
            error_log('trading-strategies method_results: ' . $e->getMessage());
        }
        $out[$id] = ['status' => 'ok', 'history' => json_decode($json, true), 'ran' => true];
    }
    return ['asset' => (string)$asset, 'tf' => $tf, 'before' => $before, 'from' => $rows[0]['t'] ?? null, 'provider_calls' => $r['provider_calls'],
        'methods' => (object)$out];
}
