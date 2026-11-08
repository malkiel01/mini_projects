<?php
/**
 * בדיקה לאחור על העותק האישי בענן MBE (spec-stage-a 3.7, ✅14).
 *
 * השרת לא קורא קבצים מהענן: הדפדפן קורא את העותק (drives.suite) ושולח אותו בבקשה אחת — "🧪 הרץ על העותק שלי",
 * בפעולה של המשתמש בלבד. **השרת לא סומך על מה שקיבל** — כל בדיקה של 3.7 נכשלת ב-422 ולא כותבת דבר, וההודעה אומרת
 * מה נכשל ובאיזו שורה, בלי להחזיר את התוכן.
 *
 * איך זה רץ:
 *   - בתחום של המשתמש בלבד: cred_scope = u:<id>, source = copy. **אף נר מהעותק לא נכתב ל-candles** — הנרות חיים
 *     בזיכרון של הבקשה, ונשמרת רק התוצאה. אין השפעה על תוצאה משותפת או של משתמש אחר.
 *   - צירוף: העותק לטווח הישן; **המאגר גובר בחפיפה** (60 היום האחרונים — מהמאגר); פער בין סוף העותק לתחילת המאגר —
 *     מהספק, בכפוף לתקציב (ts_bt_history). אין ערבוב ספקים: העותק חייב להיות של אותו ספק, סימול ומסגרת.
 *   - חישוב מלא בכל ריצה (state = null): אי אפשר להניח שהעותק לא השתנה.
 *   - הבקשה נספרת בתקרת 30 לדקה (TS_DATA ב-api.php).
 *
 * הגוף: CSV של 3.7, מכווץ ב-gzip וב-base64 (csv_gz). 100,000 נרות הם כ-8.5MB של טקסט — מעל post_max_size הנפוץ;
 * מכווצים הם כ-2–3MB. התקרה על הגוף — TS_COPY_MAX_BODY, ועל הטקסט אחרי הפריסה — TS_COPY_MAX_CSV (gzdecode עוצר שם,
 * כך שקובץ "פצצה" לא מנפח את הזיכרון).
 */

declare(strict_types=1);

require_once __DIR__ . '/analysis.php';

const TS_COPY_MAX_BODY = 6_000_000;    // הגוף (JSON עם csv_gz) — מתחת ל-post_max_size של 8M
const TS_COPY_MAX_CSV = 12_000_000;    // הטקסט אחרי הפריסה (100,000 שורות ארוכות)

/**
 * יישור לגבול הנר (3.6, לפי meta()['align'] של הספק). 'utc' — הגבולות של קריפטו ושל ECB: יום 00:00 UTC, שבוע מיום
 * שני, חודש מה-1; ובתוך היום — כפולה של המסגרת. אחרת (מושב מסחר באזור הזמן של הבורסה) — לפחות דקה שלמה.
 */
function ts_copy_aligned(string $align, string $tf, int $t): bool
{
    if ($t % 60 !== 0) return false;
    if ($align !== 'utc') return true;
    return match ($tf) {
        'w1' => ($t - 345600) % 604800 === 0,          // 1970-01-05 — יום שני
        'mn1' => gmdate('j H:i:s', $t) === '1 00:00:00',
        default => $t % TS_TF_SECONDS[$tf] === 0,
    };
}

/**
 * CSV של העותק → נרות (מחרוזות עשרוניות). כל חריגה — TsInvalid ("שורה N: …"), בלי התוכן עצמו.
 * $line — מספר השורה בקובץ (1 = שורת ההערה).
 */
function ts_copy_parse(string $csv, string $provider, string $symbol, string $tf, int $now, string $align): array
{
    $lines = explode("\n", str_replace("\r", '', $csv));
    while ($lines && trim(end($lines)) === '') array_pop($lines);
    $head = $lines[0] ?? '';
    if (!str_starts_with($head, '#')) throw new TsInvalid('שורה 1: חסרה שורת ההערה (# provider=…; symbol=…; tf=…)');
    $kv = [];
    foreach (explode(';', substr($head, 1)) as $part) {
        $pair = explode('=', $part, 2);
        if (count($pair) === 2) $kv[trim($pair[0])] = trim($pair[1]);
    }
    if (($kv['provider'] ?? null) !== $provider || ($kv['symbol'] ?? null) !== $symbol || ($kv['tf'] ?? null) !== $tf) {
        throw new TsInvalid('שורה 1: הספק, הסימול או מסגרת הזמן בקובץ לא תואמים לנכס של הבדיקה');
    }
    if (trim($lines[1] ?? '') !== 't,o,h,l,c,v') throw new TsInvalid('שורה 2: שורת הכותרת צריכה להיות t,o,h,l,c,v');
    $count = count($lines) - 2;
    if ($count < 1) throw new TsInvalid('אין נרות בעותק');
    if ($count > TS_BT_MAX_CANDLES) throw new TsInvalid('יותר מ-' . number_format(TS_BT_MAX_CANDLES) . ' נרות בעותק');

    $sec = TS_TF_SECONDS[$tf];
    $rows = [];
    $prev = null;
    for ($i = 2, $k = count($lines); $i < $k; $i++) {
        $no = $i + 1;
        $f = explode(',', trim($lines[$i]));
        if (count($f) !== 6) throw new TsInvalid("שורה $no: צריכים להיות 6 שדות");
        if (!preg_match('/^\d{1,11}$/', $f[0])) throw new TsInvalid("שורה $no: הזמן (t) אינו מספר שלם של שניות");
        $t = (int)$f[0];
        if ($prev !== null && $t <= $prev) throw new TsInvalid("שורה $no: הנרות לא ממוינים או כפולים (t חייב לעלות)");
        if (!ts_copy_aligned($align, $tf, $t)) throw new TsInvalid("שורה $no: הזמן לא מיושר לגבול הנר של המסגרת");
        if ($t + $sec > $now) throw new TsInvalid("שורה $no: נר עתידי או נר שעוד לא נסגר");
        $v = [];
        foreach (['o' => 1, 'h' => 2, 'l' => 3, 'c' => 4, 'v' => 5] as $key => $j) {
            $d = dec_parse($f[$j]);
            if ($d === null) throw new TsInvalid("שורה $no: $key אינו מספר עשרוני תקין");
            if (dec_sign($d) < 0) throw new TsInvalid("שורה $no: $key שלילי");
            $v[$key] = $d;
        }
        $lo = dec_cmp($v['o'], $v['c']) <= 0 ? $v['o'] : $v['c'];
        $hi = dec_cmp($v['o'], $v['c']) <= 0 ? $v['c'] : $v['o'];
        if (dec_cmp($v['l'], $lo) > 0 || dec_cmp($hi, $v['h']) > 0) throw new TsInvalid("שורה $no: הנר לא עקבי (צריך l ≤ o,c ≤ h)");
        $rows[] = ['t' => $t] + $v;
        $prev = $t;
    }
    return $rows;
}

/**
 * העותק + המאגר + הספק על הפער. המאגר (והספק) גוברים בחפיפה. מחזיר [rows, provider_calls, n_copy, n_provider].
 * הנרות לא נשמרים בשום מקום.
 */
function ts_copy_merge(int $uid, string $asset, string $tf, array $copy, int $now): array
{
    $sec = TS_TF_SECONDS[$tf];
    $poolStart = $now - TS_POOL_DAYS * TS_DAY;
    $from = min((int)end($copy)['t'] + $sec, $poolStart);
    [$hist, $calls] = ts_bt_history($uid, $asset, $tf, $from, $now);
    $all = []; $src = [];
    foreach ($copy as $r) { $all[$r['t']] = $r; $src[$r['t']] = 'c'; }
    foreach ($hist as $r) { $all[$r['t']] = $r; $src[$r['t']] = 'p'; }
    ksort($all);
    $rows = array_slice(array_values(array_filter($all, fn($c) => $c['t'] + $sec <= $now)), -TS_BT_MAX_CANDLES);
    $nCopy = 0;
    foreach ($rows as $r) if ($src[$r['t']] === 'c') $nCopy++;
    return [$rows, $calls, $nCopy, count($rows) - $nCopy];
}

/** התוצאה מהעותק לתצוגה — מהשורה השמורה (בלי state: חישוב מלא) */
function ts_bt_copy_out(array $row): array
{
    $n = (int)$row['n'];
    $w = (int)$row['wins'];
    $cert = ts_certainty($w, $n);
    return ['status' => $cert === null ? 'insufficient' : 'ok', 'certainty' => $cert,
        'win_pct' => $n >= TS_MIN_SIGNALS ? (int)round(100 * $w / $n) : null, 'n' => $n, 'wins' => $w,
        'expectancy_r' => $row['expectancy_r'] !== null ? (float)$row['expectancy_r'] : null, 'max_dd' => (float)$row['max_dd'],
        'from_t' => $row['from_t'] !== null ? (int)$row['from_t'] : null, 'to_t' => $row['to_t'] !== null ? (int)$row['to_t'] : null,
        'computed_at' => (int)$row['computed_at'], 'source' => 'copy',
        'n_copy' => (int)$row['n_copy'], 'n_provider' => (int)$row['n_provider']];
}

/** התוצאה מהעותק של המשתמש הזה בלבד — לאותה גרסה, פרמטרים ועלויות. null — אין */
function ts_bt_copy_row(int $uid, TsMethod $m, array $p, string $asset, string $tf, string $market): ?array
{
    [$provider] = ts_asset_split($asset);
    $meta = $m->meta();
    $st = ts_db()->prepare("SELECT * FROM backtests WHERE method_id = ? AND provider = ? AND asset = ? AND tf = ? AND cred_scope = ?
                            AND source = 'copy' AND method_version = ? AND params_hash = ?");
    $st->execute([$meta['id'], $provider, $asset, $tf, "u:$uid", $meta['version'], ts_bt_hash($p, ts_cost_for($market))]);
    $row = $st->fetch();
    return $row ? ts_bt_copy_out($row) : null;
}

function ts_copy_used(int $uid, string $asset, string $tf, string $methodId): bool
{
    $st = ts_db()->prepare('SELECT 1 FROM copy_blend WHERE user_id = ? AND asset = ? AND tf = ? AND method_id = ?');
    $st->execute([$uid, $asset, $tf, $methodId]);
    return (bool)$st->fetchColumn();
}

/** "🧪 שקלול לפי העותק שלך" — או חזרה לרגילה (ברירת המחדל) */
function ts_copy_use(int $uid, array $in): void
{
    [$provider, $symbol] = ts_asset_split($in['asset'] ?? '');
    $tf = $in['tf'] ?? '';
    if (!is_string($tf) || !isset(TS_TF_SECONDS[$tf])) throw new TsInvalid('מסגרת זמן לא תקינה');
    [$m] = ts_method_for($uid, $in['method_id'] ?? '');
    $asset = "$provider:$symbol";
    $db = ts_db();
    if (!empty($in['use'])) {
        $db->prepare('INSERT OR IGNORE INTO copy_blend (user_id, asset, tf, method_id) VALUES (?, ?, ?, ?)')->execute([$uid, $asset, $tf, $m->meta()['id']]);
    } else {
        $db->prepare('DELETE FROM copy_blend WHERE user_id = ? AND asset = ? AND tf = ? AND method_id = ?')->execute([$uid, $asset, $tf, $m->meta()['id']]);
    }
}

/**
 * "🧪 הרץ על העותק שלי": מאמת את העותק, מצרף, מריץ חישוב מלא לכל שיטה שנבחרה, שומר את התוצאה בתחום המשתמש,
 * ומחזיר את הניתוח (עם התוצאות מהעותק לצד הרגילות).
 */
function ts_analysis_copy(int $uid, array $in, ?int $now = null): array
{
    $now ??= ts_now();
    [$provider, $symbol] = ts_asset_split($in['asset'] ?? '');
    $asset = "$provider:$symbol";
    $tf = $in['tf'] ?? '';
    if (!is_string($tf) || !isset(TS_TF_SECONDS[$tf])) throw new TsInvalid('מסגרת זמן לא תקינה');
    $methods = $in['methods'] ?? '';
    $ids = is_string($methods) ? array_values(array_filter(explode(',', $methods))) : [];
    if (!$ids) throw new TsInvalid('בחרו שיטה אחת לפחות');
    if (count($ids) > 10) throw new TsInvalid('עד 10 שיטות בניתוח אחד');
    $meta = ts_provider($provider)->meta();
    if (empty($meta['personal_copy'])) throw new TsInvalid('התנאים של ' . $meta['name'] . ' לא מתירים עותק אישי');
    if (!isset($meta['tfs'][$tf])) throw new TsInvalid($meta['name'] . ' לא נותן את מסגרת הזמן הזו');
    ts_access($uid, $provider) ?? throw new TsInvalid('אין לכם חיבור לספק הזה. מחברים ב"עוד ← ספקי נתונים".');

    $gz = $in['csv_gz'] ?? null;
    $bin = is_string($gz) ? base64_decode($gz, true) : false;
    $csv = $bin === false ? false : @gzdecode($bin, TS_COPY_MAX_CSV);
    if (!is_string($csv)) throw new TsInvalid('העותק לא נקרא (gzip/base64), או שהוא גדול מהתקרה');
    $copy = ts_copy_parse($csv, $provider, $symbol, $tf, $now, (string)($meta['align'] ?? 'session'));
    unset($csv, $bin, $gz);

    $market = ts_asset_market($asset);
    $cost = ts_cost_for($market);
    try {
        [$rows, $calls, $nCopy, $nProv] = ts_copy_merge($uid, $asset, $tf, $copy, $now);
    } catch (TsBudget $e) {
        throw new TsInvalid($e->getMessage());
    }
    $fl = ts_bt_floats($rows);
    unset($rows, $copy);
    $db = ts_db();
    $ins = $db->prepare("INSERT OR REPLACE INTO backtests (method_id, method_version, provider, asset, tf, params_hash, cred_scope, source,
                         n, wins, expectancy_r, max_dd, from_t, to_t, state, n_copy, n_provider, computed_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'copy', ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)");
    $errors = [];
    foreach (array_unique($ids) as $id) {
        [$m, $set, $p] = ts_method_for($uid, $id);
        if (!in_array($tf, $set['timeframes'], true) || !in_array($market, $set['markets'], true)) continue;
        $mm = $m->meta();
        try {
            $s = ts_bt_run($m, $p, $fl, $cost, null, microtime(true) + TS_BT_MAX_SECONDS);
        } catch (TsBacktestTimeout) {
            $errors[$id] = 'הבדיקה לאחור על העותק לא הושלמה בתוך ' . TS_BT_MAX_SECONDS . ' שניות';
            continue;
        }
        $res = ts_bt_result($s);
        $ins->execute([$mm['id'], $mm['version'], $provider, $asset, $tf, ts_bt_hash($p, $cost), "u:$uid", $res['n'], $res['wins'],
            $res['expectancy_r'], $res['max_dd'], $res['from_t'], $res['to_t'], $nCopy, $nProv, $now]);
    }
    $out = ts_analysis($uid, $asset, $tf, implode(',', $ids), $now, true);
    return $out + ['copy_run' => ['n_copy' => $nCopy, 'n_provider' => $nProv, 'provider_calls' => $calls, 'errors' => $errors]];
}
