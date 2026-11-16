<?php
/**
 * רענון הגרף (spec-stage-a 18.3, S3, ✅23): **בקשה אחת** מחזירה את הנרות ואת התוצאה של כל השיטות שנבחרו — לא בקשה לכל
 * שיטה. כך גם 5 שיטות ברענון כל 30 שניות = 2 בקשות לדקה לזוג, בתוך תקרת ה-30 (✅3).
 *
 * **בלי חישוב חוזר כשלא השתנה דבר:** מפתח החישוב = (נכס, מסגרת, היקף המאגר, הנרות הסגורים — הראשון, האחרון ומספרם,
 * מזהה וגרסת שיטה, פרמטרים). מפתח שכבר חושב — התוצאה השמורה חוזרת כמו שנשמרה (אותם בייטים), והשיטה לא רצה. נר פתוח
 * שזז לא משנה את המפתח, כי שיטות רצות על נרות סגורים (17.1.9(א)) — רק הנרות בתשובה מתעדכנים.
 *
 * **הכרטיס (S4, 18.4)** חוזר באותה בקשה (`analysis`): כיוון, תנאים, רמות, שקלול, והבדיקה לאחור **השמורה** — הרענון לא
 * מריץ בדיקה לאחור (18.3). הבדיקה עצמה רצה רק בפעולה של המשתמש, דרך `analysis` (lib/analysis.php).
 *
 * "נבדקה לאחור" לא בתוך המפתח: הוא נקרא מחדש בכל בקשה (שאילתה קטנה), כי ניתוח שרץ בינתיים יכול לשנות אותו.
 * המונה (method_runs) מוצג במנהל ← 🗄️ מאגר — שם רואים ששיטה לא רצה בלי נר חדש.
 */

declare(strict_types=1);

require_once __DIR__ . '/forecast.php';

/** כמה שיטות בבקשה אחת — כמו הבחירה בגרף (18.5) */
const TS_REFRESH_MAX_METHODS = 8;
/** הקצבים המותרים לרענון האוטומטי, בשניות (18.3): ברירת מחדל 60, ואפשר 30. לא פחות מ-30 ולא יותר מ-60. */
const TS_REFRESH_EVERY = ['default' => 60, 'choices' => [60, 30]];

function ts_chart_refresh(int $uid, mixed $asset, mixed $tf, mixed $from, mixed $to, mixed $methods, ?int $now = null): array
{
    if (!is_string($tf) || !isset(TS_TF_SECONDS[$tf])) throw new TsInvalid('מסגרת זמן לא תקינה');
    [$provider] = ts_asset_split($asset);
    // מסגרת שהספק לא נותן (21.3, 2.26): הגרף כולו — הנרות, השיטות והכרטיס — על אותו זוג בספק שנותן אותה
    if (($alt = ts_pair_tf_asset($uid, (string)$asset, $tf)) !== null) [$asset, $provider] = [$alt, explode(':', $alt, 2)[0]];
    $ids = is_string($methods) && $methods !== '' ? array_values(array_unique(explode(',', $methods))) : [];
    if (count($ids) > TS_REFRESH_MAX_METHODS) throw new TsInvalid('יותר מדי שיטות בבקשה אחת (עד ' . TS_REFRESH_MAX_METHODS . ')');
    $now ??= ts_now();

    $r = ts_market_candles($uid, $asset, $tf, $from, $to, $now);
    $rows = ts_closed_rows($r['candles'], $tf, $now);
    $scope = (string)(ts_scope_for($uid, $provider) ?? '');
    $out = [];
    $runs = 0;
    $reused = 0;
    $prepared = [];
    foreach ($ids as $id) {
        try {
            [$m, $set, $p] = $prepared[$id] = ts_method_for($uid, $id);
        } catch (TsNotFound) {
            // שיטה שכובתה בינתיים — לא מפילה את הרענון של האחרות
            $out[$id] = ['status' => 'unavailable', 'message' => 'השיטה לא זמינה', 'overlay' => null];
            continue;
        }
        if (!ts_overlay_supported($set, $asset, $tf)) {
            $u = ts_overlay_unsupported($m, $tf);
            unset($u['provider_calls']);
            $out[$id] = $u;
            continue;
        }
        [$res, $ran] = ts_method_result($m, ts_params_for_segment($m, $set, $p, (string)$asset, $tf), (string)$asset, $tf, $scope, $rows, $now);
        $ran ? $runs++ : $reused++;
        $out[$id] = $res + ['backtested' => ts_overlay_backtested($uid, $m, (string)$asset, $tf)];
    }
    // הכרטיס (S4, 18.4) — באותה בקשה: הממצא עכשיו, והבדיקה לאחור **השמורה** בלבד. הרענון לא מריץ בדיקה לאחור (18.3)
    $an = null;
    if ($prepared) {
        $an = ts_analysis_cards($uid, (string)$asset, $tf, $prepared, $now, false);
        $r['provider_calls'] += $an['provider_calls'];
        $an = array_intersect_key($an, array_flip(['cards', 'blend', 'profile', 'account_size', 'decimals', 'last_t', 'disclaimer']));
    }
    return $r + ['methods' => (object)$out, 'analysis' => $an, 'runs' => $runs, 'reused' => $reused,
        'as_of' => $rows ? end($rows)['t'] : null, 'refresh' => TS_REFRESH_EVERY];
}

/**
 * התוצאה של שיטה: מהמטמון אם המפתח כבר חושב, אחרת ריצה ושמירה. מחזיר [התוצאה, האם השיטה רצה].
 * כשל בכתיבת המטמון לא מפיל את הבקשה — רק הריצה הבאה תחשב שוב.
 */
function ts_method_result(TsMethod $m, array $p, string $asset, string $tf, string $scope, array $rows, int $now): array
{
    $k = ts_method_key($m, $p, $asset, $tf, $scope, $rows);
    $id = $m->meta()['id'];
    $c = ts_cache();
    $st = $c->prepare('SELECT result FROM method_results WHERE k = ?');
    $st->execute([$k]);
    $hit = $st->fetchColumn();
    if (is_string($hit) && is_array($res = json_decode($hit, true))) {
        ts_method_count($id, false, $now);
        return [$res, false];
    }
    $res = ts_overlay_compute($m, $p, $tf, $rows);
    // נשמר מקודד ומוחזר מפוענח מאותה מחרוזת — כך גם הריצה הראשונה וגם הבאות יוצאות באותם בייטים
    $json = json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    try {
        $c->prepare('INSERT OR REPLACE INTO method_results (k, method_id, result, at) VALUES (?, ?, ?, ?)')->execute([$k, $id, $json, $now]);
    } catch (Throwable $e) {
        error_log('trading-strategies method_results: ' . $e->getMessage());
    }
    ts_method_count($id, true, $now);
    $out = json_decode($json, true);
    // יומן השיטה (18.6, S6): רק כאן — השיטה רצה באמת, כלומר נר סגור חדש. ה-UNIQUE בטבלה משאיר רשומה אחת לנר
    ts_journal_analysis($m, $asset, $tf, $scope, $out, $now);
    return [$out, true];
}

function ts_method_count(string $id, bool $ran, int $now): void
{
    try {
        $col = $ran ? 'runs' : 'reused';
        $at = $ran ? 'last_run_at' : 'last_reused_at';
        ts_cache()->prepare("INSERT INTO method_runs (method_id, $col, $at) VALUES (?, 1, ?)
            ON CONFLICT(method_id) DO UPDATE SET $col = $col + 1, $at = excluded.$at")->execute([$id, $now]);
    } catch (Throwable $e) {
        error_log('trading-strategies method_runs: ' . $e->getMessage());
    }
}

/** המונה למסך המפתח (מנהל ← 🗄️ מאגר) */
function ts_method_runs(): array
{
    return array_map(fn($r) => ['method_id' => $r['method_id'], 'runs' => (int)$r['runs'], 'reused' => (int)$r['reused'],
        'last_run_at' => (int)$r['last_run_at'], 'last_reused_at' => (int)$r['last_reused_at']],
        ts_cache()->query('SELECT * FROM method_runs ORDER BY method_id')->fetchAll());
}
