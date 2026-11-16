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
    $st = $db->prepare("SELECT * FROM learning_findings WHERE method_id = ? AND method_version = ? AND source = ? AND sig != 'gone'");
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
