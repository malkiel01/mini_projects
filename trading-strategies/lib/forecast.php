<?php
/**
 * "🔮 תחזית שיטה" (spec-stage-a 17.2.2, ✅15) — מצב נפרד מ"ניתוח": תרחיש לפי כללי השיטה על **הנרות המוצגים** בגרף.
 *
 * - **אין אחוז ודאות** ואין שקלול; ההקרנה לא נכנסת לשקלול (6.5) ולא להתראות (8). 6.4 ו-6.6 נשארים בתוקף לניתוח.
 * - הכותרת: "תרחיש לפי שיטת X — לא ייעוץ"; תמיד "לפי כללי השיטה, לא נבדק לאחור", וההסתייגות הקבועה (6.6).
 *   שיטה שאין לה בכלל בדיקה לאחור שמורה על הנכס והמסגרת — מסומנת גם "לא נבדקה לאחור".
 * - רק שיטה שהמשתמש רואה (ts_method_for) — כמו בניתוח. רק נרות סגורים.
 * - נספר בתקרת 30 לדקה (TS_DATA ב-api.php). הגרף עצמו מבקש דרך chart_refresh (lib/refresh.php, 18.3) — נרות ותוצאות
 *   כל השיטות בבקשה אחת, בלי חישוב חוזר כשלא נסגר נר. method_overlay נשאר לבקשה של שיטה אחת.
 */

declare(strict_types=1);

require_once __DIR__ . '/analysis.php';
require_once __DIR__ . '/overlay.php';

const TS_FORECAST_BASIS = 'לפי כללי השיטה, לא נבדק לאחור';

function ts_method_overlay(int $uid, mixed $asset, mixed $tf, mixed $method, mixed $from, mixed $to, ?int $now = null): array
{
    if (!is_string($tf) || !isset(TS_TF_SECONDS[$tf])) throw new TsInvalid('מסגרת זמן לא תקינה');
    ts_asset_split($asset);   // נכס לא תקין — 400 כאן, לפני הכול
    [$m, $set, $p] = ts_method_for($uid, $method);
    $now ??= ts_now();
    if (!ts_overlay_supported($set, $asset, $tf)) return ts_overlay_unsupported($m, $tf);
    $r = ts_market_candles($uid, $asset, $tf, $from, $to, $now);
    $rows = ts_closed_rows($r['candles'], $tf, $now);
    return ts_overlay_compute($m, $p, $tf, $rows) + ['backtested' => ts_overlay_backtested($uid, $m, $asset, $tf)]
        + ['provider_calls' => $r['provider_calls']];
}

/** רק נרות סגורים (17.1.9(א)) — נר שנפתח לפני פחות ממסגרת אחת עוד זז */
function ts_closed_rows(array $candles, string $tf, int $now): array
{
    $sec = TS_TF_SECONDS[$tf];
    return array_values(array_filter($candles, fn($c) => $c['t'] + $sec <= $now));
}

/** הכותרת של התשובה: השיטה, הכותרת "תרחיש לפי שיטת X — לא ייעוץ", הבסיס וההסתייגות */
function ts_overlay_head(TsMethod $m, string $tf): array
{
    $meta = $m->meta();
    return ['method' => ['id' => $meta['id'], 'name' => $meta['name'], 'source' => $meta['source'], 'version' => $meta['version'],
        'sample' => !empty($meta['sample']), 'label' => $meta['label'] ?? null],
        'title' => 'תרחיש לפי שיטת ' . $meta['name'] . ' — לא ייעוץ', 'basis' => TS_FORECAST_BASIS, 'disclaimer' => TS_ANALYSIS_DISCLAIMER, 'tf' => $tf];
}

function ts_overlay_supported(array $set, string $asset, string $tf): bool
{
    return in_array($tf, $set['timeframes'], true) && in_array(ts_asset_market($asset), $set['markets'], true);
}

function ts_overlay_unsupported(TsMethod $m, string $tf): array
{
    return ts_overlay_head($m, $tf) + ['status' => 'unsupported', 'message' => 'השיטה לא מוגדרת לשוק או למסגרת הזמן הזו',
        'overlay' => null, 'backtested' => false, 'provider_calls' => 0];
}

/** הריצה עצמה: השיטה על הנרות הסגורים → overlay (17.2.3). בלי גישה למסד — כך התוצאה תלויה רק במפתח החישוב (18.3) */
function ts_overlay_compute(TsMethod $m, array $p, string $tf, array $rows): array
{
    $sec = TS_TF_SECONDS[$tf];
    $ov = ts_overlay_check($m->overlay(ts_bt_floats($rows), $p, $sec), $sec);
    return ts_overlay_head($m, $tf) + ['status' => 'ok', 'overlay' => $ov, 'candles' => count($rows), 'tick_decimals' => ts_tick_decimals($rows)];
}

/** "נבדקה לאחור" — יש תוצאה שמורה של הגרסה הזו, באותו היקף מפתח שהניתוח היה משתמש בו (6.2(ד)). לא מחשבים כאן. */
function ts_overlay_backtested(int $uid, TsMethod $m, string $asset, string $tf): bool
{
    $meta = $m->meta();
    [$provider] = ts_asset_split($asset);
    $scope = ts_access($uid, $provider)['scope'] ?? null;
    $st = ts_db()->prepare("SELECT 1 FROM backtests WHERE method_id = ? AND method_version = ? AND provider = ? AND asset = ? AND tf = ?
        AND cred_scope = ? AND source = 'provider' LIMIT 1");
    $st->execute([$meta['id'], $meta['version'], $provider, $asset, $tf, (string)$scope]);
    return (bool)$st->fetchColumn();
}
