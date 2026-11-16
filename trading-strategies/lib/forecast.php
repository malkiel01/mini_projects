<?php
/**
 * השיטות על הגרף (spec-stage-a 17.2.2, 18.1.2, 18.4 — S4): שלוש שכבות לכל שיטה, מובחנות בשם, בקו ובמקום בפס שמתחת לגרף.
 *
 * - **ממצא** — מה שכבר קרה על הנרות הסגורים: הספירה, התוויות, הסימונים. קו מלא. (`overlay`)
 * - **תרחיש** — ההמשך האפשרי לפי כללי השיטה: אזור, קו מקווקו, רמת ביטול. **מתחיל רק אחרי הנר הסגור האחרון.** (`forecast`)
 * - **הסתברות** — הוודאות של הבדיקה לאחור (6.3, ✅26), בכרטיס (lib/analysis.php). לתרחיש עצמו — אין אחוז.
 *
 * **נפרד בלוגיקה ובנתונים (2ה.2):** התרחיש נגזר **אחרי** הספירה, מתוך ה-overlay שהשיטה כבר החזירה, לקריאה בלבד
 * (ts_forecast_split), ונשמר בשדה משלו. הספירה לא נקראת מחדש בגלל התרחיש, והתרחיש לא נכנס לשקלול (6.5) ולא להתראות (8).
 * הניסוח (6.6): "תרחיש לפי שיטת X — לא ייעוץ", בלי "יעד" ובלי "קנה/מכור".
 *
 * המצב הנפרד "🔮 תחזית שיטה" (method_overlay, 17.2.2) — בוטל ב-S4 (19.2). הגרף מבקש הכול דרך chart_refresh (lib/refresh.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/analysis.php';
require_once __DIR__ . '/overlay.php';

const TS_FORECAST_BASIS = 'לפי כללי השיטה, לא נבדק לאחור';

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
        'overlay' => null, 'forecast' => null, 'backtested' => false, 'provider_calls' => 0];
}

/** הריצה עצמה: השיטה על הנרות הסגורים → overlay (17.2.3). בלי גישה למסד — כך התוצאה תלויה רק במפתח החישוב (18.3) */
function ts_overlay_compute(TsMethod $m, array $p, string $tf, array $rows): array
{
    $sec = TS_TF_SECONDS[$tf];
    $ov = ts_overlay_check($m->overlay(ts_bt_floats($rows), $p, $sec), $sec);
    [$finding, $forecast] = ts_forecast_split($ov);
    return ts_overlay_head($m, $tf) + ['status' => 'ok', 'overlay' => $finding, 'forecast' => $forecast, 'candles' => count($rows),
        'tick_decimals' => ts_tick_decimals($rows)];
}

/** פריט של תרחיש: נוגע בזמן שאחרי הנר הסגור האחרון, קו מקווקו, או רמת ביטול (של ההמשך — לא של מה שקרה) */
function ts_forecast_item(array $it, ?int $asOf): bool
{
    if (($it['kind'] ?? '') === 'hline') return ($it['role'] ?? '') === 'invalidation';
    if (($it['kind'] ?? '') === 'polyline' && ($it['style'] ?? '') === 'dashed') return true;
    if ($asOf === null) return false;
    foreach ([$it['t'] ?? null, $it['t1'] ?? null, $it['t2'] ?? null, ...array_column($it['points'] ?? [], 't')] as $t) if (is_int($t) && $t > $asOf) return true;
    return false;
}

/**
 * מפריד את ה-overlay של השיטה לממצא ולתרחיש (S4). לא משנה את הממצא: אותם פריטים, באותו סדר, ואותם שדות של השיטה
 * (הספירות — counts — נשארות בממצא). התרחיש **נחתך לנר הסגור האחרון:** קו מקווקו שמתחיל לפניו מתחיל בו (נקודה
 * מחושבת על הקו), ואזור — ממנו והלאה. מחזיר [finding, forecast{as_of, bars, items, scenarios}].
 */
function ts_forecast_split(array $ov): array
{
    $asOf = $ov['as_of'];
    $finding = $ov;
    $finding['items'] = [];
    $items = [];
    foreach ($ov['items'] as $it) {
        if (!ts_forecast_item($it, $asOf)) { $finding['items'][] = $it; continue; }
        if ($asOf !== null && $it['kind'] === 'zone') { $it['t1'] = max($it['t1'], $asOf); if ($it['t2'] <= $asOf) continue; }
        if ($asOf !== null && $it['kind'] === 'polyline') { $it['points'] = ts_forecast_clip($it['points'], $asOf); if (!$it['points']) continue; }
        $items[] = $it;
    }
    unset($finding['projection']);
    $scen = array_map(fn($s) => ['points' => $asOf !== null ? ts_forecast_clip($s['points'] ?? [], $asOf) : ($s['points'] ?? [])] + $s,
        $ov['projection']['scenarios']);
    return [$finding, ['as_of' => $asOf, 'bars' => $ov['projection']['bars'], 'items' => $items, 'scenarios' => $scen]];
}

/** הקו מהנר הסגור האחרון והלאה. נקודה לפני as_of → הנקודה על הקו בזמן as_of. פחות משתי נקודות אחרי החיתוך → [] */
function ts_forecast_clip(array $pts, int $asOf): array
{
    $out = [];
    foreach ($pts as $i => $p) {
        if ($p['t'] < $asOf) {
            $q = $pts[$i + 1] ?? null;
            if ($q && $q['t'] > $asOf) $out[] = ['t' => $asOf, 'price' => $p['price'] + ($q['price'] - $p['price']) * ($asOf - $p['t']) / ($q['t'] - $p['t'])];
            continue;
        }
        $out[] = $p;
    }
    return count($out) >= 2 ? $out : [];
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
