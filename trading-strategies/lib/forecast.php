<?php
/**
 * "🔮 תחזית שיטה" (spec-stage-a 17.2.2, ✅15) — מצב נפרד מ"ניתוח": תרחיש לפי כללי השיטה על **הנרות המוצגים** בגרף.
 *
 * - **אין אחוז ודאות** ואין שקלול; ההקרנה לא נכנסת לשקלול (6.5) ולא להתראות (8). 6.4 ו-6.6 נשארים בתוקף לניתוח.
 * - הכותרת: "תרחיש לפי שיטת X — לא ייעוץ"; תמיד "לפי כללי השיטה, לא נבדק לאחור", וההסתייגות הקבועה (6.6).
 *   שיטה שאין לה בכלל בדיקה לאחור שמורה על הנכס והמסגרת — מסומנת גם "לא נבדקה לאחור".
 * - רק שיטה שהמשתמש רואה (ts_method_for) — כמו בניתוח. רק נרות סגורים.
 * - נספר בתקרת 30 לדקה (TS_DATA ב-api.php). אין רענון אוטומטי (✅13): הדפדפן מבקש רק בפעולה של המשתמש.
 */

declare(strict_types=1);

require_once __DIR__ . '/analysis.php';
require_once __DIR__ . '/overlay.php';

const TS_FORECAST_BASIS = 'לפי כללי השיטה, לא נבדק לאחור';

function ts_method_overlay(int $uid, mixed $asset, mixed $tf, mixed $method, mixed $from, mixed $to, ?int $now = null): array
{
    if (!is_string($tf) || !isset(TS_TF_SECONDS[$tf])) throw new TsInvalid('מסגרת זמן לא תקינה');
    [$provider] = ts_asset_split($asset);   // נכס לא תקין — 400 כאן, לפני הכול
    [$m, $set, $p] = ts_method_for($uid, $method);
    $meta = $m->meta();
    $now ??= ts_now();
    $sec = TS_TF_SECONDS[$tf];
    $head = ['method' => ['id' => $meta['id'], 'name' => $meta['name'], 'source' => $meta['source'], 'version' => $meta['version'],
        'sample' => !empty($meta['sample']), 'label' => $meta['label'] ?? null],
        'title' => 'תרחיש לפי שיטת ' . $meta['name'] . ' — לא ייעוץ', 'basis' => TS_FORECAST_BASIS, 'disclaimer' => TS_ANALYSIS_DISCLAIMER, 'tf' => $tf];
    $market = ts_asset_market($asset);
    if (!in_array($tf, $set['timeframes'], true) || !in_array($market, $set['markets'], true)) {
        return $head + ['status' => 'unsupported', 'message' => 'השיטה לא מוגדרת לשוק או למסגרת הזמן הזו', 'overlay' => null, 'backtested' => false, 'provider_calls' => 0];
    }
    $r = ts_market_candles($uid, $asset, $tf, $from, $to, $now);
    $rows = array_values(array_filter($r['candles'], fn($c) => $c['t'] + $sec <= $now));
    $ov = ts_overlay_check($m->overlay(ts_bt_floats($rows), $p, $sec), $sec);

    // "נבדקה לאחור" — יש תוצאה שמורה של הגרסה הזו, באותו היקף מפתח שהניתוח היה משתמש בו (6.2(ד)). לא מחשבים כאן.
    $scope = ts_access($uid, $provider)['scope'] ?? null;
    $st = ts_db()->prepare("SELECT 1 FROM backtests WHERE method_id = ? AND method_version = ? AND provider = ? AND asset = ? AND tf = ?
        AND cred_scope = ? AND source = 'provider' LIMIT 1");
    $st->execute([$meta['id'], $meta['version'], $provider, $asset, $tf, (string)$scope]);
    return $head + ['status' => 'ok', 'overlay' => $ov, 'backtested' => (bool)$st->fetchColumn(), 'candles' => count($rows),
        'tick_decimals' => ts_tick_decimals($rows), 'provider_calls' => $r['provider_calls']];
}
