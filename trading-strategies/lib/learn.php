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

// ── L3 (23.10, 23.5–23.6): סטטיסטיקה לפי פלח, ו-CSV ─────────────────

/** הפלחים (23.5): כל תגית לבד, ועוד צירוף אחד שתמיד קיים — שוק × מסגרת. holidays — אזור לכל ערך, ו"none" לעסקה בלי חג */
const TS_LEARN_DIMS = ['market', 'tf', 'market_tf', 'month', 'quarter', 'weekday', 'hour', 'holidays'];

/** N, הצלחות, אחוז, תוחלת ו-ודאות — רק כש-N ≥ 30 (23.6). פחות — רק N, בלי שום מספר שנגזר ממנו */
function ts_learn_cell(int $n, int $wins, float $sumR): array
{
    $enough = $n >= TS_MIN_SIGNALS;
    return ['n' => $n, 'enough' => $enough, 'wins' => $enough ? $wins : null, 'win_pct' => $enough ? round(100 * $wins / $n, 1) : null,
        'expectancy_r' => $enough ? round($sumR / $n, 4) : null, 'certainty' => $enough ? ts_certainty($wins, $n) : null];
}

/**
 * לשונית "למידה" של שיטה אחת (23.10, L3). $version — ברירת המחדל: הגרסה הנוכחית. $source — מקור אחד (23.7: לא מערבבים).
 * מחזיר: counts (N לפי מקור וגרסה), total, segments [{dim, value, …ts_learn_cell}], gaps (skipped_gap — בלי R, לא בסטטיסטיקה),
 * rows ו-size (≈ 300 בתים לשורה, 23.3). אין כאן user_id בכלל.
 */
function ts_learn_stats(string $methodId, ?int $version = null, string $source = 'backtest'): array
{
    if (!in_array($source, TS_LEARN_SOURCES, true)) throw new TsInvalid('מקור לא מוכר');
    $db = ts_db();
    $st = $db->prepare('SELECT source, method_version, COUNT(*) AS n FROM method_outcomes WHERE method_id = ? GROUP BY source, method_version ORDER BY method_version DESC, source');
    $st->execute([$methodId]);
    $counts = array_map(fn($r) => ['source' => $r['source'], 'version' => (int)$r['method_version'], 'n' => (int)$r['n']], $st->fetchAll());
    $all = array_sum(array_column($counts, 'n'));

    $st = $db->prepare("SELECT win, r, market, tf, month, quarter, weekday, hour, holidays, result FROM method_outcomes
        WHERE method_id = ? AND method_version = ? AND source = ?");
    $st->execute([$methodId, $version, $source]);
    $acc = []; $tot = [0, 0, 0.0]; $gaps = 0;
    foreach ($st->fetchAll() as $r) {
        if ($r['result'] === 'skipped_gap' || $r['r'] === null) { $gaps++; continue; }
        $w = (int)$r['win']; $x = (float)$r['r'];
        $tot = [$tot[0] + 1, $tot[1] + $w, $tot[2] + $x];
        $hol = json_decode((string)$r['holidays'], true) ?: [];
        $keys = [['market', $r['market']], ['tf', $r['tf']], ['market_tf', $r['market'] . '|' . $r['tf']], ['month', (string)$r['month']],
            ['quarter', (string)$r['quarter']], ['weekday', (string)$r['weekday']]];
        if ($r['hour'] !== null) $keys[] = ['hour', (string)$r['hour']];
        foreach ($hol ?: ['none'] as $h) $keys[] = ['holidays', (string)$h];
        foreach ($keys as [$d, $v]) {
            $c = $acc[$d][$v] ?? [0, 0, 0.0];
            $acc[$d][$v] = [$c[0] + 1, $c[1] + $w, $c[2] + $x];
        }
    }
    $segments = [];
    foreach (TS_LEARN_DIMS as $d) {
        $vals = $acc[$d] ?? [];
        uksort($vals, fn($a, $b) => is_numeric($a) && is_numeric($b) ? (int)$a <=> (int)$b : strcmp((string)$a, (string)$b));
        foreach ($vals as $v => [$n, $w, $s]) $segments[] = ['dim' => $d, 'value' => (string)$v] + ts_learn_cell($n, $w, $s);
    }
    return ['method' => $methodId, 'version' => $version, 'source' => $source, 'counts' => $counts,
        'total' => ts_learn_cell($tot[0], $tot[1], $tot[2]), 'segments' => $segments, 'gaps' => $gaps, 'min_n' => TS_MIN_SIGNALS,
        'rows' => $all, 'size' => $all * 300];
}

/**
 * תא ב-CSV. נגד נוסחאות באקסל (ביקורת L3 ⚪): טקסט שמתחיל ב-= + - @ (או טאב / שורה חדשה) — גרש בהתחלה.
 * מספר שלילי ("-1.5") נשאר מספר: רק טקסט שאינו מספר מנוטרל.
 */
function ts_learn_csv_cell(mixed $v): string
{
    if ($v === null) return '';
    $s = (string)$v;
    return preg_match('/^[=+\-@\t\r]/', $s) && !is_numeric($s) ? "'" . $s : $s;
}

/**
 * ייצוא CSV (23.10) — כל השורות של שיטה אחת (ואפשר גרסה / מקור), כמו שהן במסד. user_id — רק כש-$withUser (העורך הראשי, ל3);
 * אחרת העמודה לא קיימת בכלל. מחרוזת CSV עם BOM (עברית באקסל).
 */
function ts_learn_csv(string $methodId, ?int $version, ?string $source, bool $withUser): string
{
    $cols = ['id', 'source', 'method_id', 'method_version', 'params_hash', 'provider', 'asset', 'tf', 'cred_scope', 't_signal', 't_entry', 't_exit',
        'side', 'entry', 'stop', 'exit_level', 'exit', 'result', 'win', 'r', 'mae_r', 'mfe_r', 'atr', 'market', 'month', 'quarter', 'weekday', 'hour',
        'holidays', 'features', 'trade_id', 'created_at'];
    if ($withUser) $cols[] = 'user_id';
    $sql = 'SELECT ' . implode(', ', $cols) . ' FROM method_outcomes WHERE method_id = ?';
    $args = [$methodId];
    if ($version !== null) { $sql .= ' AND method_version = ?'; $args[] = $version; }
    if ($source !== null) {
        if (!in_array($source, TS_LEARN_SOURCES, true)) throw new TsInvalid('מקור לא מוכר');
        $sql .= ' AND source = ?'; $args[] = $source;
    }
    $st = ts_db()->prepare($sql . ' ORDER BY id');
    $st->execute($args);
    $f = fopen('php://temp', 'w+');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, $cols, ',', '"', '');
    foreach ($st as $r) fputcsv($f, array_map('ts_learn_csv_cell', array_values($r)), ',', '"', '');
    rewind($f);
    $out = (string)stream_get_contents($f);
    fclose($f);
    return $out;
}

// ── L4 (23.8): שינוי דפוס — מובהקות מול סף, וממצאים חריגים ל-DevLoop (23.8א, ✅37) ─────────────────

/** הסף (23.8): p < 0.01 — יש עשרות פלחים, ובסף 0.05 חלק מהם ייראו "שונים" במקרה */
const TS_LEARN_P = 0.01;
/** "אחרון מול היסטוריה" (23.8, מבחן 2): 30 העסקאות האחרונות */
const TS_LEARN_RECENT = 30;
/** [שלנו] פלחים שאפשר לטפל בהם בתוך השיטה — ערך פרמטר לפלח (23.9, L5). כל השאר (יום, חודש, חג…) — "כלל חדש", לבעלים */
const TS_LEARN_METHOD_DIMS = ['market', 'tf', 'market_tf'];

/** ln Γ(x) — Lanczos (g = 7) */
function ts_learn_lgamma(float $x): float
{
    static $c = [0.99999999999980993, 676.5203681218851, -1259.1392167224028, 771.32342877765313, -176.61502916214059,
        12.507343278686905, -0.13857109526572012, 9.9843695780195716e-6, 1.5056327351493116e-7];
    if ($x < 0.5) return log(M_PI / abs(sin(M_PI * $x))) - ts_learn_lgamma(1 - $x);
    $x -= 1;
    $a = $c[0];
    $t = $x + 7.5;
    for ($i = 1; $i < 9; $i++) $a += $c[$i] / ($x + $i);
    return 0.5 * log(2 * M_PI) + ($x + 0.5) * log($t) - $t + log($a);
}

/** I_x(a, b) — פונקציית בטא הלא שלמה המנורמלת (שבר משולב, Lentz) */
function ts_learn_ibeta(float $x, float $a, float $b): float
{
    if ($x <= 0) return 0.0;
    if ($x >= 1) return 1.0;
    if ($x > ($a + 1) / ($a + $b + 2)) return 1 - ts_learn_ibeta(1 - $x, $b, $a);
    $front = exp(ts_learn_lgamma($a + $b) - ts_learn_lgamma($a) - ts_learn_lgamma($b) + $a * log($x) + $b * log(1 - $x)) / $a;
    $tiny = 1e-300;
    $f = 1.0; $c = 1.0; $d = 0.0;
    for ($i = 0; $i <= 300; $i++) {
        $m = intdiv($i, 2);
        if ($i === 0) $num = 1.0;
        elseif ($i % 2 === 0) $num = ($m * ($b - $m) * $x) / (($a + 2 * $m - 1) * ($a + 2 * $m));
        else $num = -(($a + $m) * ($a + $b + $m) * $x) / (($a + 2 * $m) * ($a + 2 * $m + 1));
        $d = 1 + $num * $d; if (abs($d) < $tiny) $d = $tiny; $d = 1 / $d;
        $c = 1 + $num / $c; if (abs($c) < $tiny) $c = $tiny;
        $cd = $c * $d;
        $f *= $cd;
        if (abs(1 - $cd) < 1e-12) break;
    }
    return $front * ($f - 1);
}

/** p דו-צדדי של התפלגות נורמלית סטנדרטית: 2·(1 − Φ(|z|)) = I_{1}… — דרך erfc (Numerical Recipes, דיוק ~1e-7) */
function ts_learn_p_normal(float $z): float
{
    $x = abs($z) / M_SQRT2;
    $t = 1 / (1 + 0.5 * $x);
    $erfc = $t * exp(-$x * $x - 1.26551223 + $t * (1.00002368 + $t * (0.37409196 + $t * (0.09678418 + $t * (-0.18628806
        + $t * (0.27886807 + $t * (-1.13520398 + $t * (1.48851587 + $t * (-0.82215223 + $t * 0.17087277)))))))));
    return min(1.0, max(0.0, $erfc));
}

/** p דו-צדדי של t עם df דרגות חופש */
function ts_learn_p_t(float $t, float $df): float
{
    if (!is_finite($t)) return 0.0;
    return min(1.0, max(0.0, ts_learn_ibeta($df / ($df + $t * $t), $df / 2, 0.5)));
}

/** [שלנו] מבחן שני-פרופורציות (23.8): אחוז ההצלחה בפלח מול השאר. p דו-צדדי */
function ts_learn_test_prop(int $w1, int $n1, int $w2, int $n2): float
{
    if ($n1 <= 0 || $n2 <= 0) return 1.0;
    $p = ($w1 + $w2) / ($n1 + $n2);
    $se = sqrt($p * (1 - $p) * (1 / $n1 + 1 / $n2));
    if ($se <= 0) return 1.0;   // כולן הצליחו (או נכשלו) בשני הצדדים — אין הבדל
    return ts_learn_p_normal(($w1 / $n1 - $w2 / $n2) / $se);
}

/** [שלנו] מבחן Welch (23.8): תוחלת R בפלח מול השאר. סטטיסטיקה מצטברת [n, Σr, Σr²]. p דו-צדדי */
function ts_learn_test_welch(array $a, array $b): float
{
    [$n1, $s1, $q1] = $a; [$n2, $s2, $q2] = $b;
    if ($n1 < 2 || $n2 < 2) return 1.0;
    $m1 = $s1 / $n1; $m2 = $s2 / $n2;
    $v1 = max(0.0, ($q1 - $n1 * $m1 * $m1) / ($n1 - 1)); $v2 = max(0.0, ($q2 - $n2 * $m2 * $m2) / ($n2 - 1));
    $e1 = $v1 / $n1; $e2 = $v2 / $n2;
    if ($e1 + $e2 <= 1e-15) return abs($m1 - $m2) < 1e-12 ? 1.0 : 0.0;
    $t = ($m1 - $m2) / sqrt($e1 + $e2);
    $df = ($e1 + $e2) ** 2 / (($e1 > 0 ? $e1 * $e1 / ($n1 - 1) : 0) + ($e2 > 0 ? $e2 * $e2 / ($n2 - 1) : 0));
    return ts_learn_p_t($t, $df);
}

/**
 * הממצאים (23.8) — טהור, בלי צד: כל פלח עם N ≥ 30 ו-N ≥ 30 בשאר (מבחן 1), ו-30 האחרונות מול כל מה שלפניהן (מבחן 2).
 * ממצא = אחד מהמבחנים (אחוז או R) עם p < 0.01. לכל ממצא: key (שיטה, גרסה, מקור, סוג, פלח), המספרים משני הצדדים, p של כל
 * מבחן, ו-scope: method (פלח שערך פרמטר לפלח יכול לטפל בו — L5) או owner ("כלל חדש" — לבעלים בלבד, עיקרון 3).
 */
function ts_learn_findings(string $methodId, int $version, string $source = 'backtest'): array
{
    $st = ts_db()->prepare("SELECT win, r, market, tf, month, quarter, weekday, hour, holidays FROM method_outcomes
        WHERE method_id = ? AND method_version = ? AND source = ? AND r IS NOT NULL AND result != 'skipped_gap' ORDER BY t_exit, id");
    $st->execute([$methodId, $version, $source]);
    $rows = $st->fetchAll();
    $N = count($rows);
    $agg = fn(array $rs) => [count($rs), array_sum(array_column($rs, 'win')), array_sum(array_map(fn($r) => (float)$r['r'], $rs)),
        array_sum(array_map(fn($r) => (float)$r['r'] ** 2, $rs))];
    $sub = fn(array $a, array $b) => [$a[0] - $b[0], $a[1] - $b[1], $a[2] - $b[2], $a[3] - $b[3]];
    $all = $agg($rows);
    $out = [];
    $finding = function (string $kind, string $dim, string $value, array $in, array $rest) use ($methodId, $version, $source, $N): ?array {
        if ($in[0] < TS_MIN_SIGNALS || $rest[0] < TS_MIN_SIGNALS) return null;   // 23.6 — פלח קטן לא נבדק
        $pw = ts_learn_test_prop((int)$in[1], $in[0], (int)$rest[1], $rest[0]);
        $pr = ts_learn_test_welch([$in[0], $in[2], $in[3]], [$rest[0], $rest[2], $rest[3]]);
        if (min($pw, $pr) >= TS_LEARN_P) return null;
        return ['key' => implode('|', [$methodId, $version, $source, $kind, $dim, $value, '']), 'kind' => $kind, 'dim' => $dim, 'value' => $value,
            'n' => $in[0], 'win_pct' => round(100 * $in[1] / $in[0], 1), 'expectancy_r' => round($in[2] / $in[0], 4),
            'rest_n' => $rest[0], 'rest_win_pct' => round(100 * $rest[1] / $rest[0], 1), 'rest_expectancy_r' => round($rest[2] / $rest[0], 4),
            'p_win' => $pw, 'p_r' => $pr, 'p' => min($pw, $pr), 'total' => $N,
            'scope' => $kind === 'segment' && in_array($dim, TS_LEARN_METHOD_DIMS, true) ? 'method' : 'owner'];
    };
    // מבחן 1: פלח מול השאר — אותם פלחים כמו בטבלה (L3)
    $groups = [];
    foreach ($rows as $r) {
        $keys = [['market', $r['market']], ['tf', $r['tf']], ['market_tf', $r['market'] . '|' . $r['tf']], ['month', (string)$r['month']],
            ['quarter', (string)$r['quarter']], ['weekday', (string)$r['weekday']]];
        if ($r['hour'] !== null) $keys[] = ['hour', (string)$r['hour']];
        foreach ((json_decode((string)$r['holidays'], true) ?: ['none']) as $h) $keys[] = ['holidays', (string)$h];
        foreach ($keys as [$d, $v]) $groups[$d][$v][] = $r;
    }
    foreach (TS_LEARN_DIMS as $d) foreach ($groups[$d] ?? [] as $v => $rs) {
        $in = $agg($rs);
        if (($f = $finding('segment', $d, (string)$v, $in, $sub($all, $in)))) $out[] = $f;
    }
    // מבחן 2: 30 האחרונות (לפי זמן היציאה) מול כל מה שלפניהן
    if ($N >= TS_LEARN_RECENT + TS_MIN_SIGNALS) {
        $in = $agg(array_slice($rows, -TS_LEARN_RECENT));
        if (($f = $finding('recent', 'recent', (string)TS_LEARN_RECENT, $in, $sub($all, $in)))) $out[] = $f;
    }
    usort($out, fn($a, $b) => $a['p'] <=> $b['p']);
    return $out;
}

/** הפלח במילים — לכותרת של פריט ה-DevLoop */
function ts_learn_segment_text(string $dim, string $value): string
{
    static $market = ['crypto' => 'קריפטו', 'forex' => 'מט"ח', 'stocks' => 'מניות', 'indices' => 'מדדים', 'commodities' => 'סחורות', 'futures' => 'חוזים'];
    static $days = [1 => 'ימי שני', 2 => 'ימי שלישי', 3 => 'ימי רביעי', 4 => 'ימי חמישי', 5 => 'ימי שישי', 6 => 'שבת', 7 => 'ימי ראשון'];
    static $regions = ['US' => 'חגי ארה"ב', 'EU' => 'חגי אירופה', 'UK' => 'חגי בריטניה', 'JP' => 'חגי יפן', 'CN' => 'חגי סין', 'IL' => 'חגי ישראל',
        'XMAS' => 'חג המולד – ראש השנה', 'none' => 'בלי חג'];
    return match ($dim) {
        'market' => $market[$value] ?? $value,
        'tf' => TS_TIMEFRAMES[$value] ?? $value,
        'market_tf' => ($market[explode('|', $value)[0]] ?? explode('|', $value)[0]) . ' · ' . (TS_TIMEFRAMES[explode('|', $value)[1] ?? ''] ?? ($value)),
        'month' => 'חודש ' . $value,
        'quarter' => 'רבעון ' . $value,
        'weekday' => $days[(int)$value] ?? $value,
        'hour' => sprintf('%02d:00 UTC', (int)$value),
        'holidays' => $regions[$value] ?? $value,
        'recent' => "$value העסקאות האחרונות",
        default => "$dim $value",
    };
}

/** המספרים של ממצא במילים (23.8): "22% הצלחה (N = 41) מול 48% בשאר (p = 0.004)" */
function ts_learn_finding_text(array $f): string
{
    $other = $f['kind'] === 'recent' ? 'לפניהן' : 'בשאר';
    $p = fn(float $v) => $v < 0.001 ? '< 0.001' : '= ' . number_format($v, 3);
    return sprintf('%s%% הצלחה, תוחלת %sR (N = %d) מול %s%% ותוחלת %sR %s (N = %d) · p %s באחוז, p %s בתוחלת',
        $f['win_pct'], round($f['expectancy_r'], 2), $f['n'], $f['rest_win_pct'], round($f['rest_expectancy_r'], 2), $other, $f['rest_n'],
        $p($f['p_win']), $p($f['p_r']));
}

/** [שלנו] תקרה לפריטי DevLoop חדשים בריצה אחת, לכל שיטה + גרסה + מקור (ביקורת L4 🟡2): לפי p הקטן; השאר — רק בלשונית */
const TS_LEARN_MAX_NEW = 3;

/**
 * ממצאים חריגים → DevLoop (23.8א, ✅37). בלי כפילות ובלי רעש — הבעלים קורא את הפריטים (ביקורת L4 🟡1–🟡2):
 * - ממצא חדש → פריט (kind research, area learning). לכל היותר TS_LEARN_MAX_NEW בריצה, לפי p הקטן. עברנו את התקרה — שורה
 *   אחת בפריט האחרון שנפתח: "ועוד N ממצאים בלשונית"; הם ייפתחו בריצה הבאה שבה יש מקום.
 * - ממצא קיים → תגובה **רק** כשנוספו מאז הדיווח האחרון לפחות TS_LEARN_RECENT (30) עסקאות. עסקה בודדת — שקט. פריט שנסגר —
 *   באותו תנאי, ונפתח מחדש.
 * - ממצא שהפסיק להיות מובהק — תגובה אחת "כבר לא מובהק" (בפריט פתוח), ו-sig = 'gone'. חוזר — תגובה, בלי לחכות ל-30.
 * - **לא משנה שום דבר בשיטה.** מחזיר [פריטים חדשים, תגובות]. בלי המודול (CLI בלי devtasks.php) — כלום.
 */
function ts_learn_findings_sync(string $methodId, int $version, string $source, int $now): array
{
    if (!function_exists('devloop_task_create')) return [0, 0];
    $db = ts_db();
    $name = ts_method_exists($methodId) ? ts_method($methodId)->meta()['name'] : $methodId;
    $get = $db->prepare('SELECT * FROM learning_findings WHERE k = ?');
    $save = $db->prepare('UPDATE learning_findings SET sig = ?, total = ?, updated_at = ? WHERE k = ?');
    $item = function (string $code): ?array {
        try { return devloop_task_get($code); } catch (Throwable) { return null; }   // הפריט נמחק — לא פותחים שוב לבד
    };
    $created = 0; $comments = 0; $skipped = 0; $lastCode = null; $seen = [];
    $findings = ts_learn_findings($methodId, $version, $source);   // ממוינים לפי p
    foreach ($findings as $f) {
        $seen[$f['key']] = true;
        $seg = ts_learn_segment_text($f['dim'], $f['value']);
        $text = ts_learn_finding_text($f);
        $sig = json_encode([$f['n'], $f['rest_n'], $f['win_pct'], $f['rest_win_pct'], round($f['p'], 6)]);
        $get->execute([$f['key']]);
        $row = $get->fetch();
        if (!$row) {
            if ($created >= TS_LEARN_MAX_NEW) { $skipped++; continue; }
            $what = $f['kind'] === 'recent' ? 'שינוי לאחרונה' : 'פלח שונה מהשאר';
            $r = devloop_task_create(['id' => null], ['kind' => 'research', 'area' => 'learning', 'priority' => 'normal',
                'title' => "ממצא למידה: $name · $seg · $what",
                'detail' => "שיטה: $name ($methodId), גרסה $version, מקור $source.\nפלח: $seg.\n$text.\n"
                    . ($f['scope'] === 'owner' ? 'זה דפוס שהשיטה לא מגדירה ("כלל חדש") — להכרעת הבעלים, לא הצעה.' : 'פלח שאפשר לטפל בו בתוך השיטה (ערך פרמטר לפלח, 23.9).')
                    . "\nהמערכת לא שינתה דבר. מסך: ניהול ← 🧪 שיטות ← $name ← 📚 למידה (#/admin)."]);
            $db->prepare('INSERT INTO learning_findings (k, method_id, method_version, source, kind, dim, value, code, scope, sig, total, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$f['key'], $methodId, $version, $source, $f['kind'], $f['dim'], $f['value'], $r['code'], $f['scope'], $sig, $f['total'], $now, $now]);
            $created++;
            $lastCode = $r['code'];
            continue;
        }
        $back = $row['sig'] === 'gone';   // היה "כבר לא מובהק", וחזר
        $more = $f['total'] - (int)$row['total'];
        if (!$back && $more < TS_LEARN_RECENT) continue;   // פחות מ-30 עסקאות חדשות — שקט (גם בפריט סגור)
        $it = $item($row['code']);
        if (!$it) continue;
        $closed = ($it['status'] ?? '') === 'closed';
        devloop_comment_add($row['code'], 'claude', null, ($back ? 'הממצא חזר להיות מובהק. ' : "עדכון אחרי $more עסקאות חדשות: ") . "$text.");
        if ($closed) devloop_task_status($row['code'], 'open', 'claude');
        $save->execute([$sig, $f['total'], $now, $f['key']]);
        $comments++;
    }
    if ($skipped && $lastCode) {
        devloop_comment_add($lastCode, 'claude', null, "ועוד $skipped ממצאים בלשונית \"למידה\" של $name — לא נפתחו כפריטים בריצה הזו (עד " . TS_LEARN_MAX_NEW . ' בריצה).');
    }
    // ממצא שהיה, ועכשיו לא מובהק (או שהפלח ירד מתחת ל-30) — תגובה אחת בפריט פתוח, ולא שוב עד שיחזור
    $st = $db->prepare("SELECT * FROM learning_findings WHERE method_id = ? AND method_version = ? AND source = ? AND sig != 'gone' AND kind IN ('segment', 'recent')");
    $st->execute([$methodId, $version, $source]);
    foreach ($st->fetchAll() as $row) {
        if (isset($seen[$row['k']])) continue;
        $it = $item($row['code']);
        if ($it && ($it['status'] ?? '') !== 'closed') {
            devloop_comment_add($row['code'], 'claude', null, 'הממצא כבר לא מובהק (p ≥ ' . TS_LEARN_P . ' בשני המבחנים, או שהפלח ירד מתחת ל-' . TS_MIN_SIGNALS . ' עסקאות). אין מה לעשות — הוא נשמר כאן לתיעוד.');
            $comments++;
        }
        $save->execute(['gone', (int)$row['total'], $now, $row['k']]);
    }
    return [$created, $comments];
}

/** הקודים של פריטי ה-DevLoop לממצאים (ל-⚠️ במסך) — מפתח → קוד */
function ts_learn_finding_codes(string $methodId): array
{
    $st = ts_db()->prepare('SELECT k, code FROM learning_findings WHERE method_id = ?');
    $st->execute([$methodId]);
    return array_column($st->fetchAll(), 'code', 'k');
}

// ── L5 (23.9, 2.37): "איפה למקם" — השוואת שכנים, ב-cron ──────────────────────────────────────────────────

/*
 * 2.37: ה-MAE / MFE שבשורות נחתכים בסטופ וביעד הנוכחיים — אי אפשר ללמוד מהם ערך אחר (ביקורת L5 🟡2). במקומם — **השוואת שכנים**:
 * הנוכחי וצעד אחד לכל כיוון (בתוך הטווח בלבד), כל אחד **בהרצה מלאה** של המנוע (6.2) על הנרות שלפני t_cut, לפי תוחלת R, עם סף
 * 0.05R; ההצעה רק אם הנבחר טוב יותר גם על הנרות שמ-t_cut. **רץ ב-cron בלבד** (ts_learn_cron), בתקציב של 6.2(ה) — לא בבקשה של
 * משתמש. ההיסטוגרמות של MAE / MFE — לדיון בלבד (ts_learn_hist), לא מקור להצעה.
 *
 * **ההצהרה — בממשק של השיטה** (meta().learn_params), לכל פרמטר שאפשר ללמוד, אחד מהשניים:
 *   'stop_atr'   => ['step' => 0.25]               צעד קבוע; הטווח — min / max של הפרמטר
 *   'box_target' => ['values' => [0.5, 0.618, 1]]  רשימה סגורה; השכן — הערך הבא ברשימה, והקצוות — הראשון והאחרון
 * פרמטר "מהספר" (ours !== true) — **רק עם values** (ערכי הספר, 17.1.4); בלעדיהם — לא נלמד אף פעם (קריטריון 10).
 */

/** [שלנו] 23.9א: נקודת החיתוך — האחוזון ה-70 של t_signal; "בתוך הדגימה" — לפניה, "מחוץ לדגימה" — ממנה והלאה */
const TS_LEARN_IN_SHARE = 0.7;
/** [שלנו] 23.9: שכן נבחר רק אם התוחלת שלו בתוך הדגימה גבוהה מזו של הנוכחי ב-0.05R לפחות */
const TS_LEARN_MIN_GAIN = 0.05;
/** [שלנו] כמה שניות מריצת ה-cron (8.2) הלמידה לוקחת לכל היותר. היא אחרונה בריצה — ההתראות, היומן והמילוי לא נדחים בגללה */
const TS_LEARN_CRON_SECONDS = 90;
/** ההיסטוגרמות (23.10) — ב-ATR, תאים של 0.25 עד 5 (האחרון — "5 ומעלה"). לדיון בלבד */
const TS_LEARN_HIST_STEP = 0.25;
const TS_LEARN_HIST_MAX = 5.0;
/** תוצאות ההשוואה (learning_values.result) */
const TS_LEARN_RESULTS = ['propose', 'edge', 'current_best', 'out_worse', 'no_out_of_sample', 'no_trades', 'no_pairs'];

/** אחוזון q של רשימה ממוינת — אינטרפולציה לינארית בין שני הסמוכים (R type 7). 10 ערכים 0.1…1.0, q = 0.8 → 0.82 */
function ts_learn_quantile(array $sorted, float $q): ?float
{
    $n = count($sorted);
    if (!$n) return null;
    $h = ($n - 1) * $q;
    $lo = (int)floor($h);
    $hi = min($n - 1, $lo + 1);
    return $sorted[$lo] + ($h - $lo) * ($sorted[$hi] - $sorted[$lo]);
}

/**
 * היסטוגרמות MAE / MFE (23.10) — **לדיון בלבד**, "חתוך בסטופ וביעד הנוכחיים": עסקה שנגעה בסטופ נסגרה, ולכן אין MAE גדול ממנו.
 * ב-ATR: mae_r × |כניסה − סטופ| ÷ ATR. שורה בלי הנתונים — מדולגת. {step, max, n, mae: [ספירות], mfe: [ספירות]}
 */
function ts_learn_hist(array $rows): array
{
    $bins = (int)round(TS_LEARN_HIST_MAX / TS_LEARN_HIST_STEP) + 1;
    $out = ['step' => TS_LEARN_HIST_STEP, 'max' => TS_LEARN_HIST_MAX, 'n' => 0, 'mae' => array_fill(0, $bins, 0), 'mfe' => array_fill(0, $bins, 0)];
    foreach ($rows as $r) {
        $risk = abs((float)$r['entry'] - (float)$r['stop']);
        if ($r['atr'] === null || (float)$r['atr'] <= 0 || $risk <= 0 || $r['mae_r'] === null || $r['mfe_r'] === null) continue;
        $k = $risk / (float)$r['atr'];
        foreach (['mae', 'mfe'] as $f) $out[$f][min($bins - 1, (int)floor((float)$r[$f . '_r'] * $k / TS_LEARN_HIST_STEP + 1e-9))]++;
        $out['n']++;
    }
    return $out;
}

/** ההיסטוגרמה של שיטה, גרסה ומקור — מכל השורות (הפלח הכללי) */
function ts_learn_hist_for(string $methodId, int $version, string $source = 'backtest'): array
{
    $st = ts_db()->prepare("SELECT entry, stop, atr, mae_r, mfe_r FROM method_outcomes WHERE method_id = ? AND method_version = ? AND source = ? AND r IS NOT NULL");
    $st->execute([$methodId, $version, $source]);
    return ts_learn_hist($st->fetchAll());
}

/**
 * הפרמטרים שהשיטה מצהירה שאפשר ללמוד (meta().learn_params). → {param: {step, values, def, book}}. הצהרה לא תקינה — מדולגת.
 * values — רק מה שבתוך הטווח, ממוין. פרמטר "מהספר" (ours !== true, לא שיטת דוגמה) — רק עם values (קריטריון 10).
 */
function ts_learn_params(TsMethod $m): array
{
    $meta = $m->meta();
    $out = [];
    foreach ($meta['learn_params'] ?? [] as $k => $cfg) {
        $d = $meta['params'][$k] ?? null;
        if (!$d || !is_array($cfg)) continue;
        $book = ($d['ours'] ?? null) !== true && empty($meta['sample']);
        $values = null; $step = null;
        if (isset($cfg['values']) && is_array($cfg['values'])) {
            $values = [];
            foreach ($cfg['values'] as $v) {
                if (is_numeric($v) && $v >= $d['min'] - 1e-9 && $v <= $d['max'] + 1e-9 && !in_array((float)$v, $values, true)) $values[] = (float)$v;
            }
            sort($values);
            if (!$values) continue;
        } elseif (!$book && isset($cfg['step']) && is_numeric($cfg['step']) && (float)$cfg['step'] > 0) {
            $step = (float)$cfg['step'];
        } else {
            continue;
        }
        $out[$k] = ['step' => $step, 'values' => $values, 'def' => $d, 'book' => $book];
    }
    return $out;
}

/**
 * המועמדים (23.9) — **שלושה בלבד**: צעד אחד למטה, הנוכחי וצעד אחד למעלה, בתוך הטווח (בקצה — שניים). אין מועמד מחוץ לטווח.
 * ממוינים מהקטן לגדול: [{value, current, edge}]. edge — השכן הוא קצה הטווח (או קצה רשימת הערכים).
 */
function ts_learn_neighbors(array $cfg, float $current): array
{
    $d = $cfg['def'];
    $eps = 1e-9;
    $fix = fn(float $v): float => !empty($d['int']) ? (float)round($v) : round($v, 10);
    $lo = null; $hi = null;
    if ($cfg['values']) {
        foreach ($cfg['values'] as $v) {
            if ($v < $current - $eps) $lo = $v;
            if ($v > $current + $eps && $hi === null) $hi = $v;
        }
        $ends = [$cfg['values'][0], $cfg['values'][count($cfg['values']) - 1]];
    } else {
        $a = $fix($current - $cfg['step']);
        $b = $fix($current + $cfg['step']);
        if ($a >= $d['min'] - $eps) $lo = $a;
        if ($b <= $d['max'] + $eps) $hi = $b;
        $ends = [(float)$d['min'], (float)$d['max']];
    }
    $out = [];
    foreach ([$lo, $current, $hi] as $i => $v) {
        if ($v === null) continue;
        $out[] = ['value' => $v, 'current' => $i === 1, 'edge' => $i !== 1 && (abs($v - $ends[0]) < $eps || abs($v - $ends[1]) < $eps)];
    }
    return $out;
}

/**
 * 23.9א: נקודת החיתוך — **זמן אחד** לכל הנכסים יחד: האחוזון ה-70 של t_signal בכל השורות של השיטה, הגרסה והפלח (מעוגל למעלה).
 * "בתוך הדגימה" — t_signal < t_cut; "מחוץ" — ≥ t_cut. אין עסקה בשני החלקים.
 */
function ts_learn_cut(array $tSignals): ?int
{
    if (!$tSignals) return null;
    $t = array_map('floatval', $tSignals);
    sort($t);
    return (int)ceil(ts_learn_quantile($t, TS_LEARN_IN_SHARE));
}

/**
 * הבחירה (23.9) — טהורה. $cands — של ts_learn_neighbors, ולכל אחד in: {n, expectancy_r} (או null — לא רץ). מחזיר את האינדקס של
 * השכן עם התוחלת הגבוהה ביותר בתוך הדגימה, **רק אם** היא גבוהה מזו של הנוכחי ב-0.05R לפחות; אחרת null (הנוכחי, בלי הצעה).
 */
function ts_learn_choose(array $cands): ?int
{
    $cur = null;
    foreach ($cands as $c) if ($c['current']) $cur = $c['in']['expectancy_r'] ?? null;
    if ($cur === null) return null;
    $best = null;
    foreach ($cands as $i => $c) {
        $e = $c['in']['expectancy_r'] ?? null;
        if ($c['current'] || $e === null) continue;
        if ($best === null || $e > $cands[$best]['in']['expectancy_r']) $best = $i;
    }
    return $best !== null && $cands[$best]['in']['expectancy_r'] - $cur >= TS_LEARN_MIN_GAIN - 1e-12 ? $best : null;
}

/**
 * המצב של כל פרמטר נלמד × פלח (23.9, 23.10) — קריאה בלבד, בלי הרצה. פלחים: '' (הכול) ו-market|tf, כל אחד עם 30 שורות לפחות.
 * לכל אחד: הנוכחי (של הפלח, אם הוחל לו ערך), השכנים, t_cut, N בכל חלק, הזוגות (נכס × מסגרת) שהשורות באו מהם, וה-status:
 * not_enough — פחות מ-30 באחד החלקים; waiting — פחות מ-30 שורות חדשות מאז ההשוואה האחרונה; due — ה-cron ישווה. last — ההשוואה
 * האחרונה (learning_values), עם התוחלת של כל שכן.
 */
function ts_learn_values(string $methodId, int $version, string $source = 'backtest'): array
{
    if (!ts_method_exists($methodId)) return [];
    $m = ts_method($methodId);
    $lp = ts_learn_params($m);
    if (!$lp) return [];
    $set = ts_method_settings($methodId);
    $db = ts_db();
    $st = $db->prepare("SELECT asset, tf, cred_scope, market, t_signal FROM method_outcomes
        WHERE method_id = ? AND method_version = ? AND source = ? AND r IS NOT NULL AND result != 'skipped_gap' ORDER BY t_signal, id");
    $st->execute([$methodId, $version, $source]);
    $rows = $st->fetchAll();
    $segs = ['' => $rows];
    foreach ($rows as $r) $segs[$r['market'] . '|' . $r['tf']][] = $r;
    $get = $db->prepare('SELECT n_at, result, at, detail FROM learning_values WHERE method_id = ? AND method_version = ? AND source = ? AND segment = ? AND param = ?');
    $out = [];
    foreach ($segs as $seg => $rs) {
        if (count($rs) < TS_MIN_SIGNALS) continue;
        $tCut = ts_learn_cut(array_column($rs, 't_signal'));
        $nIn = count(array_filter($rs, fn($r) => (int)$r['t_signal'] < $tCut));
        // הזוגות שההרצה המלאה רצה עליהם. מפתח אישי של משתמש — לעולם לא בשם המערכת (21.3): נספר, ולא רץ
        $pairs = []; $private = [];
        foreach ($rs as $r) {
            if ($r['cred_scope'] === '') $pairs[$r['asset'] . '|' . $r['tf']] = ['asset' => $r['asset'], 'tf' => $r['tf']];
            else $private[$r['asset'] . '|' . $r['tf'] . '|' . $r['cred_scope']] = true;
        }
        $cur = $m->params(($seg !== '' ? ($set['seg_params'][$seg] ?? []) : []) + $set['params']);
        foreach ($lp as $k => $cfg) {
            $get->execute([$methodId, $version, $source, $seg, $k]);
            $l = $get->fetch() ?: null;
            $status = $nIn < TS_MIN_SIGNALS || count($rs) - $nIn < TS_MIN_SIGNALS ? 'not_enough'
                : (count($rs) - ($l ? (int)$l['n_at'] : 0) >= TS_LEARN_RECENT ? 'due' : 'waiting');
            $out[] = ['segment' => $seg, 'param' => $k, 'label' => $cfg['def']['label'], 'range' => [$cfg['def']['min'], $cfg['def']['max']],
                'step' => $cfg['step'], 'values' => $cfg['values'], 'current' => $cur[$k],
                'neighbors' => array_column(ts_learn_neighbors($cfg, (float)$cur[$k]), 'value'),
                'rows' => count($rs), 'n_in' => $nIn, 'n_out' => count($rs) - $nIn, 't_cut' => $tCut,
                'pairs' => array_values($pairs), 'private_pairs' => count($private), 'status' => $status,
                'last' => $l ? ['n_at' => (int)$l['n_at'], 'result' => $l['result'], 'at' => (int)$l['at'],
                    'detail' => $l['detail'] !== null ? json_decode($l['detail'], true) : null] : null];
        }
    }
    return $out;
}

/**
 * "הרצה" אחת (23.9): הפרמטרים $p על כל הזוגות של הפלח — כל זוג מאפס (מצב מנוע חדש), על החלק שלו (in — הנרות שלפני t_cut;
 * out — מ-t_cut). $pairs: [{asset, tf, in, out, dec, cost}]. התוצאה — מצטברת על כל העסקאות (כמו ts_tune_summary).
 */
function ts_learn_run(TsMethod $m, array $p, array $pairs, string $part, ?float $deadline): array
{
    $n = 0; $wins = 0; $sumR = 0.0; $dd = 0.0; $bars = 0; $from = null; $to = null;
    foreach ($pairs as $pr) {
        if (!$pr[$part]) continue;
        $s = ts_bt_run($m, ts_params_for_asset($m, $p, $pr['dec']), $pr[$part], $pr['cost'], null, $deadline);
        $n += (int)$s['n']; $wins += (int)$s['wins']; $sumR += (float)$s['sum_r']; $dd = max($dd, (float)$s['dd']); $bars += (int)$s['bars'];
        if ($s['first_t'] !== null) $from = $from === null ? $s['first_t'] : min($from, $s['first_t']);
        if ($s['last_t'] !== null) $to = $to === null ? $s['last_t'] : max($to, $s['last_t']);
    }
    return ['n' => $n, 'wins' => $wins, 'win_rate' => $n ? $wins / $n : null, 'certainty' => ts_certainty($wins, $n),
        'expectancy_r' => $n ? $sumR / $n : null, 'max_dd' => $dd, 'from_t' => $from, 'to_t' => $to, 'bars' => $bars];
}

/** נרות של זוג (float, ממוינים) → {asset, tf, in, out, dec, cost}: החלוקה לפי t_cut, פעם אחת לכל הערכים */
function ts_learn_pair(string $asset, string $tf, array $candles, int $tCut): array
{
    $fl = ts_bt_floats($candles);
    $in = []; $out = [];
    foreach ($fl as $c) { if ($c['t'] < $tCut) $in[] = $c; else $out[] = $c; }
    return ['asset' => $asset, 'tf' => $tf, 'in' => $in, 'out' => $out, 'dec' => ts_tick_decimals($candles), 'cost' => ts_cost_for(ts_asset_market($asset))];
}

/**
 * השוואת השכנים לפרמטר אחד בפלח אחד (23.9) — טהורה מבחינת המסד, יכולה לזרוק TsBacktestTimeout. $base — הפרמטרים של הפלח.
 * כל מועמד תקין — הרצה אחת בתוך הדגימה (3, בקצה 2). נבחר שכן (ts_learn_choose) → עוד שתי הרצות מחוץ לדגימה: הנוכחי והנבחר.
 * result: propose / edge (הנבחר טוב יותר גם מחוץ לדגימה; edge — והוא קצה הטווח), out_worse, no_out_of_sample (אין עסקאות
 * מחוץ לדגימה), current_best (אין שכן טוב יותר ב-0.05R), no_trades (לנוכחי אין עסקאות בתוך הדגימה).
 */
function ts_learn_eval(TsMethod $m, array $base, string $param, array $cfg, array $pairs, ?float $deadline = null): array
{
    $pCur = $m->params($base);
    $cands = ts_learn_neighbors($cfg, (float)$pCur[$param]);
    $runsIn = 0; $runsOut = 0; $ci = 0; $ps = [];
    foreach ($cands as $i => &$c) {
        $v = !empty($cfg['def']['int']) ? (int)round($c['value']) : (float)$c['value'];
        $p = $c['current'] ? $pCur : $m->params([$param => $v] + $base);
        $c['value'] = $p[$param];
        $ps[$i] = $p;
        if ($c['current']) $ci = $i;
        $c['in'] = $m->validate($p) === null ? ts_learn_run($m, $p, $pairs, 'in', $deadline) : null;
        $c['out'] = null;
        if ($c['in'] !== null) $runsIn++;
    }
    unset($c);
    $chosen = ts_learn_choose($cands);
    $result = ($cands[$ci]['in']['n'] ?? 0) === 0 ? 'no_trades' : 'current_best';
    if ($chosen !== null) {
        foreach ([$ci, $chosen] as $i) { $cands[$i]['out'] = ts_learn_run($m, $ps[$i], $pairs, 'out', $deadline); $runsOut++; }
        $co = $cands[$ci]['out']['expectancy_r'];
        $no = $cands[$chosen]['out']['expectancy_r'];
        $result = $co === null || $no === null ? 'no_out_of_sample' : ($no > $co ? ($cands[$chosen]['edge'] ? 'edge' : 'propose') : 'out_worse');
    }
    return ['candidates' => $cands, 'current' => $ci, 'chosen' => $chosen, 'result' => $result, 'runs_in' => $runsIn, 'runs_out' => $runsOut];
}

/**
 * הכתיבה של השוואה אחת — **בטרנזקציה אחת** (🟡6): ההצעה (propose / edge → הצעת S7, created_by 0, origin learning — ts_tune_learned_save),
 * בקצה הטווח — גם פריט DevLoop (✅37) "הנתונים מצביעים אל קצה הטווח — אולי מעבר לו", ותמיד — learning_values עם הסמן (n_at) והפירוט.
 * מחזיר [הצעות, ממצאים].
 */
function ts_learn_commit(TsMethod $m, array $v, array $ev, int $pairsRun, int $now): array
{
    require_once __DIR__ . '/tune.php';
    $meta = $m->meta();
    $db = ts_db();
    $pick = fn(?array $x) => $x === null ? null : ['n' => $x['n'], 'win_rate' => $x['win_rate'], 'expectancy_r' => $x['expectancy_r']];
    $detail = ['t_cut' => $v['t_cut'], 'n_in' => $v['n_in'], 'n_out' => $v['n_out'], 'pairs' => $pairsRun, 'private_pairs' => $v['private_pairs'],
        'runs_in' => $ev['runs_in'] ?? 0, 'runs_out' => $ev['runs_out'] ?? 0, 'chosen' => $ev['chosen'] ?? null,
        'candidates' => array_map(fn($c) => ['value' => $c['value'], 'current' => $c['current'], 'edge' => $c['edge'], 'in' => $pick($c['in']), 'out' => $pick($c['out'])],
            $ev['candidates'] ?? [])];
    $prop = null; $fk = null;
    $seg = $v['segment'] === '' ? 'כל השווקים' : ts_learn_segment_text('market_tf', $v['segment']);
    if (in_array($ev['result'], ['propose', 'edge'], true)) {
        $cur = $ev['candidates'][$ev['current']];
        $new = $ev['candidates'][$ev['chosen']];
        $one = count($v['pairs']) === 1 ? $v['pairs'][0] : null;
        $prop = ['method_id' => $meta['id'], 'method_version' => $meta['version'], 'param' => $v['param'], 'current' => $cur['value'], 'candidate' => $new['value'],
            'asset' => $one['asset'] ?? '', 'tf' => $one['tf'] ?? ($v['segment'] !== '' ? explode('|', $v['segment'])[1] : ''), 'split_t' => $v['t_cut'],
            'results' => ['split_t' => $v['t_cut'], 'current' => ['in' => $cur['in'], 'out' => $cur['out']], 'candidate' => ['in' => $new['in'], 'out' => $new['out']]],
            'note' => sprintf('מהלמידה — השוואת שכנים (%s): הרצה מלאה על %d %s; בתוך הדגימה — הנרות שלפני %s, מחוץ לה — מאז.', $seg, $pairsRun,
                $pairsRun === 1 ? 'זוג (נכס × מסגרת)' : 'זוגות (נכס × מסגרת)', gmdate('Y-m-d', $v['t_cut'])),
            'now' => $now, 'segment' => $v['segment'], 'stats' => $detail + ['kind' => 'neighbors']];
    }
    if ($ev['result'] === 'edge' && function_exists('devloop_task_create')) {
        $fk = implode('|', [$meta['id'], $meta['version'], 'backtest', 'edge', $v['segment'], $v['param'], json_encode($prop['candidate'])]);
        $has = $db->prepare('SELECT 1 FROM learning_findings WHERE k = ?');
        $has->execute([$fk]);
        if ($has->fetchColumn()) $fk = null;
    }
    $props = 0; $finds = 0;
    if ($fk && function_exists('devloop_migrate')) devloop_migrate();   // המיגרציה של DevLoop (BEGIN משלה) — לא בתוך הטרנזקציה שלנו
    $db->beginTransaction();
    try {
        if ($prop) { ts_tune_learned_save($prop); $props++; }
        if ($fk) {
            $e = fn(array $x) => $x['expectancy_r'] === null ? '—' : round($x['expectancy_r'], 3) . 'R';
            $cur = $prop['results']['current']; $new = $prop['results']['candidate'];
            $r = devloop_task_create(['id' => null], ['kind' => 'research', 'area' => 'learning', 'priority' => 'normal',
                'title' => "ממצא למידה: {$meta['name']} · $seg · {$v['label']} בקצה הטווח",
                'detail' => "שיטה: {$meta['name']} ({$meta['id']}), גרסה {$meta['version']}, מקור backtest.\nפלח: $seg.\n"
                    . "הנתונים מצביעים אל קצה הטווח — אולי מעבר לו. השוואת שכנים: {$v['param']} {$prop['current']} → {$prop['candidate']} (הטווח {$v['range'][0]}–{$v['range'][1]}).\n"
                    . "בתוך הדגימה: {$e($cur['in'])} → {$e($new['in'])}. מחוץ לדגימה: {$e($cur['out'])} → {$e($new['out'])}.\n"
                    . "נפתחה הצעה (S7) לקצה. אין מועמד מחוץ לטווח — מעבר לקצה רק הבעלים מחליט (עיקרון 2).\n"
                    . "מסך: ניהול ← 🧪 שיטות ← {$meta['name']} ← 📚 למידה (#/admin)."]);
            $db->prepare('INSERT INTO learning_findings (k, method_id, method_version, source, kind, dim, value, code, scope, sig, total, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$fk, $meta['id'], $meta['version'], 'backtest', 'value', $v['param'], $v['segment'], $r['code'], 'owner', json_encode($prop['candidate']), $v['rows'], $now, $now]);
            $finds++;
        }
        $db->prepare('INSERT OR REPLACE INTO learning_values (method_id, method_version, source, segment, param, n_at, result, at, detail) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$meta['id'], $meta['version'], 'backtest', $v['segment'], $v['param'], $v['rows'], $ev['result'], $now, json_encode($detail)]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return [$props, $finds];
}

/**
 * הלמידה ב-cron (23.9 "עלות"): לכל שיטה עם learn_params, בגרסה הנוכחית, מקור backtest — כל פרמטר × פלח שמצבו due. הנרות —
 * ts_bt_history בשם המערכת (uid 0: רק מה שפתוח לכולם או מפתח האפליקציה, כמו המילוי, 21.3), **באותו תקציב לדקה** של הריצה ($used,
 * $budget — 6.2(ה)) ובתקרת זמן משלה. חסר תקציב / זמן → נדחה לריצה הבאה, והסמן לא זז. $load — לבדיקות בלבד: (asset, tf) → נרות.
 * מחזיר סיכום ליומן הריצה: כמה הושוו, כמה הרצות בתוך הדגימה ומחוצה לה, הצעות, קצוות, נדחו.
 */
function ts_learn_cron(int $now, array &$used, ?array $budget, float $t0, ?callable $load = null): array
{
    $sum = ['evaluated' => 0, 'proposals' => 0, 'edges' => 0, 'runs_in' => 0, 'runs_out' => 0, 'deferred' => 0, 'errors' => 0, 'stopped' => false, 'log' => []];
    $until = min($t0 + (defined('TS_CRON_MAX_SECONDS') ? TS_CRON_MAX_SECONDS : 240), microtime(true) + TS_LEARN_CRON_SECONDS);
    $load ??= function (string $asset, string $tf) use ($now, &$used, $budget, $until): array {
        $provider = explode(':', $asset, 2)[0];
        $cap = $budget[$provider] ?? ts_provider_limits($provider)['per_min'];
        if (($used[$provider] ?? 0) >= $cap) throw new TsBudget('תקציב הפניות לספק בריצה הזו נגמר');
        [$rows, $calls, $complete] = ts_bt_history(0, $asset, $tf, $now - TS_BT_RANGE[$tf], $now, $until);
        $used[$provider] = ($used[$provider] ?? 0) + $calls;
        if (!$complete) throw new TsBacktestTimeout('המשיכה לא הושלמה בזמן');
        return $rows;
    };
    ts_fetch_as_system(true);
    try {
        foreach (ts_method_ids() as $id) {
            $m = ts_method($id);
            $lp = ts_learn_params($m);
            if (!$lp) continue;
            $memo = [];   // הנרות של זוג — פעם אחת לשיטה בריצה
            foreach (ts_learn_values($id, (int)$m->meta()['version'], 'backtest') as $v) {
                if ($v['status'] !== 'due') continue;
                if (microtime(true) > $until) { $sum['stopped'] = true; $sum['deferred']++; continue; }
                try {
                    $pairs = [];
                    foreach ($v['pairs'] as $pr) {
                        $key = $pr['asset'] . '|' . $pr['tf'];
                        if (!array_key_exists($key, $memo)) {
                            try {
                                $memo[$key] = $load($pr['asset'], $pr['tf']);
                            } catch (TsInvalid) {
                                $memo[$key] = [];   // אין למערכת דרך לספק הזה (או למסגרת) — הזוג לא רץ
                            }
                        }
                        if ($memo[$key]) $pairs[] = ts_learn_pair($pr['asset'], $pr['tf'], $memo[$key], (int)$v['t_cut']);
                    }
                    $set = ts_method_settings($id);
                    $base = ($v['segment'] !== '' ? ($set['seg_params'][$v['segment']] ?? []) : []) + $set['params'];
                    $ev = $pairs ? ts_learn_eval($m, $base, $v['param'], $lp[$v['param']], $pairs, $until) : ['result' => 'no_pairs'];
                    [$p, $f] = ts_learn_commit($m, $v, $ev, count($pairs), $now);
                    $sum['evaluated']++;
                    $sum['proposals'] += $p;
                    $sum['edges'] += $ev['result'] === 'edge' ? 1 : 0;
                    $sum['runs_in'] += $ev['runs_in'] ?? 0;
                    $sum['runs_out'] += $ev['runs_out'] ?? 0;
                    if (count($sum['log']) < 20) {
                        $sum['log'][] = sprintf('%s %s%s: %s · בתוך %d, מחוץ %d', $id, $v['param'], $v['segment'] !== '' ? '@' . $v['segment'] : '', $ev['result'],
                            $ev['runs_in'] ?? 0, $ev['runs_out'] ?? 0);
                    }
                } catch (TsBudget | TsBacktestTimeout | TsProviderError $e) {
                    $sum['deferred']++;   // לריצה הבאה — הסמן לא זז, ושום דבר לא נכתב
                } catch (Throwable $e) {
                    $sum['errors']++;
                    error_log('trading-strategies learning cron ' . $id . ' ' . $v['param'] . ': ' . $e->getMessage());
                }
            }
        }
    } finally {
        ts_fetch_as_system(false);
    }
    return $sum;
}
