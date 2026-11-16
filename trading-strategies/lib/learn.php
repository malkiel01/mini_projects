<?php
/**
 * למידה עצמית, שלב א' — המסד והתגיות (spec-stage-a 23.3–23.5, L1).
 *
 * - **`method_outcomes`** (data/app.sqlite, מיגרציה 17): שורה לכל עסקה שהסתיימה, של כל שיטה, מכל מקור. לא נמחקת ולא
 *   משתנה: INSERT OR IGNORE על המפתח הייחודי (23.3) — חישוב חוזר לא מכפיל, ומוסיף רק עסקאות חדשות. גרסה חדשה — שורות
 *   חדשות, והישנות נשארות.
 * - **מקור `backtest`** — כאן (ts_backtest ← ts_bt_run($onTrade)). `live` ו-`trade` — L2.
 * - **התגיות** (23.5) — מזמן נר האות, ב-UTC, כשהשורה נכתבת: שוק, מסגרת, חודש, רבעון, יום (ISO), שעה (רק בתוך היום),
 *   וחגים — מ-lib/learn/holidays.json (lib/learn/holidays-build.php).
 * - **כל שיטה לחוד** (23.1): כל קריאה מסוננת לפי method_id; אין שאילתה "של כולן".
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/decimal.php';

const TS_LEARN_SOURCES = ['backtest', 'live', 'trade'];
/** מסגרות "בתוך היום" — רק בהן נשמרת שעה (23.5) */
const TS_LEARN_INTRADAY = ['m1', 'm5', 'm15', 'm30', 'h1', 'h4'];

/** date (Y-m-d) → [אזורים]. נטען פעם אחת לבקשה */
function ts_learn_holidays(): array
{
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    foreach (json_decode((string)file_get_contents(__DIR__ . '/learn/holidays.json'), true) ?: [] as $h) $map[$h['date']][$h['region']] = true;
    return $map;
}

/**
 * האזורים שנר שנפתח ב-$t נמצא "בתקופת חג" שלהם (23.5, [שלנו]): היום עצמו, או יום מסחר אחד (שני–שישי) לפניו או אחריו.
 * XMAS — רק בטווח עצמו (24.12–1.1), בלי ההרחבה: הוא כבר תקופה ולא יום. ממוין, בלי כפילויות.
 */
function ts_learn_holiday_regions(int $t): array
{
    $map = ts_learn_holidays();
    $day = gmdate('Y-m-d', $t);
    $step = function (string $d, int $dir): string {
        do { $d = gmdate('Y-m-d', strtotime("$d UTC") + $dir * 86400); } while ((int)gmdate('N', strtotime("$d UTC")) >= 6);
        return $d;
    };
    $out = array_keys($map[$day] ?? []);
    foreach ([$step($day, -1), $step($day, 1)] as $d) foreach (array_keys($map[$d] ?? []) as $r) if ($r !== 'XMAS') $out[] = $r;
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
}

/** תגיות הפלח של עסקה (23.5) מזמן נר האות */
function ts_learn_tags(int $tSignal, string $tf, string $market): array
{
    $m = (int)gmdate('n', $tSignal);
    return ['market' => $market, 'tf' => $tf, 'month' => $m, 'quarter' => intdiv($m - 1, 3) + 1, 'weekday' => (int)gmdate('N', $tSignal),
        'hour' => in_array($tf, TS_LEARN_INTRADAY, true) ? (int)gmdate('G', $tSignal) : null, 'holidays' => ts_learn_holiday_regions($tSignal)];
}

/** מחיר מהמנוע (float) → מחרוזת עשרונית לפי ה-tick של הנכס (עיקרון 6). null / לא סופי → null */
function ts_learn_price(mixed $v, int $decimals): ?string
{
    if (!is_int($v) && !is_float($v)) return null;
    $v = (float)$v;
    if (!is_finite($v)) return null;
    return dec_parse(sprintf('%.' . $decimals . 'F', round($v, $decimals)), 10);
}

/**
 * כותב עסקאות (מ-ts_bt_run $onTrade) ל-method_outcomes. $ctx: source, method_id, method_version, params_hash, provider,
 * asset, tf, cred_scope, market, decimals, now [, user_id, trade_id]. מחזיר כמה שורות **חדשות** נכתבו (כפולות — מדולגות).
 * עסקה בלי יציאה (פתוחה) לא מגיעה לכאן: המנוע קורא ל-$onTrade רק ברגע היציאה.
 */
function ts_outcomes_write(array $ctx, array $trades): int
{
    if (!$trades) return 0;
    if (!in_array($ctx['source'], TS_LEARN_SOURCES, true)) throw new InvalidArgumentException('מקור לא מוכר');
    $db = ts_db();
    $ins = $db->prepare('INSERT OR IGNORE INTO method_outcomes (source, method_id, method_version, params_hash, provider, asset, tf, cred_scope,
            t_signal, t_entry, t_exit, side, entry, stop, exit_level, exit, result, win, r, mae_r, mfe_r, atr,
            market, month, quarter, weekday, hour, holidays, features, user_id, trade_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $dec = (int)$ctx['decimals'];
    $fin = fn($v) => (is_int($v) || is_float($v)) && is_finite((float)$v) ? (float)$v : null;
    $n = 0;
    $db->beginTransaction();
    try {
        foreach ($trades as $t) {
            $tag = ts_learn_tags((int)$t['t_signal'], $ctx['tf'], $ctx['market']);
            // יציאה ברווח: היעד, או בסטופ — הסטופ; בתום האופק — אין רמה (exit_level), רק המחיר
            $level = $t['result'] === 'win' ? $t['target'] : ($t['result'] === 'loss' ? $t['stop'] : null);
            $features = is_array($t['features'] ?? null) ? array_map($fin, $t['features']) : null;
            $ins->execute([$ctx['source'], $ctx['method_id'], (int)$ctx['method_version'], $ctx['params_hash'], $ctx['provider'], $ctx['asset'],
                $ctx['tf'], (string)$ctx['cred_scope'], (int)$t['t_signal'], $t['t_entry'], (int)$t['t_exit'], $t['side'],
                ts_learn_price($t['entry'], $dec), ts_learn_price($t['stop'], $dec), ts_learn_price($level, $dec), ts_learn_price($t['exit'], $dec),
                $t['result'], $t['win'] ? 1 : 0, $fin($t['r']), $fin($t['mae_r']), $fin($t['mfe_r']), ts_learn_price($t['atr'], $dec + 2),
                $tag['market'], $tag['month'], $tag['quarter'], $tag['weekday'], $tag['hour'], json_encode($tag['holidays']),
                $features !== null ? json_encode($features, JSON_UNESCAPED_UNICODE) : null, $ctx['user_id'] ?? null, (int)($ctx['trade_id'] ?? 0),
                (int)$ctx['now']]);
            $n += $ins->rowCount();
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $n;
}

/**
 * השורות של **שיטה אחת** (23.1 — אין שאילתה של כמה שיטות). $f: version, source, asset, tf, limit. מהישן לחדש.
 * holidays ו-features — מפוענחים. user_id לא חוזר מכאן (ל3 — רק המסך של המנהל הראשי, L3).
 */
function ts_outcomes(string $methodId, array $f = []): array
{
    $sql = 'SELECT id, source, method_id, method_version, params_hash, provider, asset, tf, t_signal, t_entry, t_exit, side, entry, stop,
            exit_level, exit, result, win, r, mae_r, mfe_r, atr, market, month, quarter, weekday, hour, holidays, features
        FROM method_outcomes WHERE method_id = ?';
    $args = [$methodId];
    foreach (['version' => 'method_version', 'source' => 'source', 'asset' => 'asset', 'tf' => 'tf'] as $k => $col) {
        if (isset($f[$k])) { $sql .= " AND $col = ?"; $args[] = $f[$k]; }
    }
    $sql .= ' ORDER BY t_signal, id LIMIT ' . max(1, min((int)($f['limit'] ?? 100000), 100000));
    $st = ts_db()->prepare($sql);
    $st->execute($args);
    return array_map(fn($r) => ['holidays' => json_decode($r['holidays'], true) ?: [], 'features' => $r['features'] !== null ? json_decode($r['features'], true) : null] + $r,
        $st->fetchAll());
}
