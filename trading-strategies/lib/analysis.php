<?php
/**
 * פרופיל, שיטות, בדיקה לאחור וניתוח — הצד שנוגע במסד ובספקים (spec-stage-a פרקים 5–6). המנוע עצמו — טהור,
 * ב-lib/backtest.php וב-lib/methods/.
 *
 * - **הפרופיל** (5) — לכל משתמש, לפי user_id של ה-session בלבד.
 * - **שיטות** (6.7) — שיטה בלי שורה ב-methods כבויה. שיטת דוגמה (meta sample) — גלויה רק למנהלים, גם כשהיא
 *   מופעלת, ולא נכנסת להתראות.
 * - **בדיקה לאחור** (6.2) — לפי דרישה בלבד (כשנפתח כרטיס ניתוח), כשחלף יותר מיום, או שהגרסה/הפרמטרים/העמלות
 *   השתנו. אין חישוב מתוזמן. מקור ההיסטוריה — טווח קבוע לכל מסגרת, עד 100,000 נרות: המאגר (60 יום) ← הספק על
 *   החסר. ההיסטוריה הארוכה נמשכת לזמן הריצה ולא נשמרת; נשמרת רק התוצאה, עם מצב המנוע להמשך מצטבר.
 *   העותק האישי בענן (3.7) — יתווסף כמקור עם PR 2 (חסום, ממתין לאפיון ענן MBE).
 * - **ניתוח** (6.1, 6.3–6.6) — התנאים עכשיו, הרמות מעוגלות ל-tick כמחרוזת עשרונית, ודאות Wilson, אופק, שקלול.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/market.php';
require_once __DIR__ . '/backtest.php';

final class TsBudget extends RuntimeException {}

const TS_DAY = 86400;
/** 6.2 (ג): הטווח הקבוע של הבדיקה לאחור לכל מסגרת. 30 דק' — לא נקבע באפיון; חצי שנה (הכרעה של כותב הקוד) */
const TS_BT_RANGE = ['m1' => 90 * TS_DAY, 'm5' => 90 * TS_DAY, 'm15' => 90 * TS_DAY, 'm30' => 180 * TS_DAY,
    'h1' => 730 * TS_DAY, 'h4' => 1826 * TS_DAY, 'd1' => 7305 * TS_DAY, 'w1' => 7305 * TS_DAY, 'mn1' => 7305 * TS_DAY];
const TS_BT_STALE = TS_DAY;          // חישוב מחדש כשחלף יותר מיום
/** 5: טווח ההשקעה → אילו מסגרות זמן מוצעות בניתוח */
const TS_HORIZON_TFS = ['short' => ['m5', 'm15', 'm30', 'h1'], 'medium' => ['h1', 'h4', 'd1'], 'long' => ['d1', 'w1']];
/** 5: רמת סיכון → [סיכון לעסקה %, יחס סיכוי/סיכון מינימלי] כברירת מחדל */
const TS_RISK_DEFAULTS = ['low' => ['0.5', '3'], 'medium' => ['1', '2'], 'high' => ['2', '1.5']];
/** 6.2: עמלה והחלקה לכל שוק, באחוזים לכל צד. המנהל משנה (settings bt_costs) */
const TS_COST_DEFAULTS = ['crypto' => ['fee' => '0.1', 'slip' => '0.05'], 'stocks' => ['fee' => '0.05', 'slip' => '0.05'],
    'forex' => ['fee' => '0.01', 'slip' => '0.01'], 'indices' => ['fee' => '0.02', 'slip' => '0.02'],
    'commodities' => ['fee' => '0.02', 'slip' => '0.02'], 'futures' => ['fee' => '0.02', 'slip' => '0.02'],
    'options' => ['fee' => '0.1', 'slip' => '0.1']];
/** 6.6 — ההסתייגות הקבועה, בכל מסך ניתוח והתראה */
const TS_ANALYSIS_DISCLAIMER = 'ניתוח אוטומטי לפי כללי שיטות מסחר וביצועי עבר. אינו ייעוץ השקעות ואינו מתחשב בנתונים האישיים שלך. ביצועי עבר אינם מבטיחים תוצאות בעתיד.';

/** "עכשיו". בבדיקות בלבד (TS_DEV) — הכותרת X-Dev-Now, כדי לבדוק "חלף יום" בלי לחכות יום */
function ts_now(): int
{
    if (defined('TS_DEV') && isset($_SERVER['HTTP_X_DEV_NOW']) && ctype_digit((string)$_SERVER['HTTP_X_DEV_NOW'])) return (int)$_SERVER['HTTP_X_DEV_NOW'];
    return time();
}

// ── פרופיל (5) ───────────────────────────────────────────

function ts_profile(int $uid): array
{
    $st = ts_db()->prepare('SELECT p.*, u.default_risk_pct FROM users u LEFT JOIN user_profile p ON p.user_id = u.id WHERE u.id = ?');
    $st->execute([$uid]);
    $r = $st->fetch() ?: [];
    $has = isset($r['user_id']);
    return [
        'horizon' => $has ? $r['horizon'] : 'medium',
        'risk_level' => $has ? $r['risk_level'] : 'medium',
        'risk_pct' => $has ? $r['risk_pct'] : (string)($r['default_risk_pct'] ?? '1'),
        'min_rr' => $has ? $r['min_rr'] : TS_RISK_DEFAULTS['medium'][1],
        'min_certainty' => $has ? (int)$r['min_certainty'] : 55,
        'preferred_methods' => $has ? (json_decode($r['preferred_methods'], true) ?: []) : [],
        'alert_channels' => $has ? (json_decode($r['alert_channels'], true) ?: ['inbox']) : ['inbox'],
        'quiet_hours' => $has && $r['quiet_hours'] !== null ? json_decode($r['quiet_hours'], true) : null,
        'max_alerts_per_day' => $has ? (int)$r['max_alerts_per_day'] : 10,
        'saved' => $has,
    ];
}

function ts_hhmm(mixed $v): string
{
    if (!is_string($v) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) throw new TsInvalid('שעה לא תקינה (HH:MM)');
    return $v;
}

function ts_int_in(mixed $v, int $min, int $max, string $what): int
{
    if (is_string($v) && preg_match('/^\d{1,6}$/', $v)) $v = (int)$v;
    if (!is_int($v) || $v < $min || $v > $max) throw new TsInvalid("$what — מספר שלם בין $min ל-$max");
    return $v;
}

function ts_profile_save(int $uid, array $in): array
{
    $horizon = ts_enum($in['horizon'] ?? '', array_flip(['short', 'medium', 'long']), 'טווח השקעה');
    $level = ts_enum($in['risk_level'] ?? '', TS_RISK_DEFAULTS, 'רמת סיכון');
    $pct = ts_num($in['risk_pct'] ?? '', 'סיכון לעסקה', false, false, 4);
    if (dec_cmp($pct, '100') > 0) throw new TsInvalid('סיכון לעסקה — עד 100%');
    $rr = ts_num($in['min_rr'] ?? '', 'יחס סיכוי/סיכון מינימלי', false, false, 2);
    if (dec_cmp($rr, '20') > 0) throw new TsInvalid('יחס סיכוי/סיכון — עד 20');
    $cert = ts_int_in($in['min_certainty'] ?? null, 50, 80, 'ודאות מינימלית');
    $pref = $in['preferred_methods'] ?? [];
    if (!is_array($pref) || !array_is_list($pref) || count($pref) > 20) throw new TsInvalid('שיטות מועדפות — רשימה לא תקינה');
    $visible = array_column(ts_methods_visible($uid), 'id');
    foreach ($pref as $m) if (!is_string($m) || !in_array($m, $visible, true)) throw new TsInvalid('שיטה מועדפת לא זמינה');
    $pref = array_values(array_unique($pref));
    $ch = $in['alert_channels'] ?? ['inbox'];
    if (!is_array($ch) || array_diff($ch, ['inbox', 'push'])) throw new TsInvalid('ערוצי התראה לא תקינים');
    $ch = array_values(array_unique(['inbox', ...$ch]));   // בתוך האפליקציה — תמיד (8.3)
    $q = $in['quiet_hours'] ?? null;
    $quiet = $q === null || $q === [] ? null : (is_array($q) ? ['from' => ts_hhmm($q['from'] ?? null), 'to' => ts_hhmm($q['to'] ?? null)] : throw new TsInvalid('שעות שקטות לא תקינות'));
    $max = ts_int_in($in['max_alerts_per_day'] ?? 10, 1, 100, 'מקסימום התראות ליום');

    $db = ts_db();
    $db->beginTransaction();
    $db->prepare('INSERT INTO user_profile (user_id, horizon, risk_level, risk_pct, min_rr, min_certainty, preferred_methods, alert_channels, quiet_hours, max_alerts_per_day, updated_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                  ON CONFLICT(user_id) DO UPDATE SET horizon = excluded.horizon, risk_level = excluded.risk_level, risk_pct = excluded.risk_pct,
                    min_rr = excluded.min_rr, min_certainty = excluded.min_certainty, preferred_methods = excluded.preferred_methods,
                    alert_channels = excluded.alert_channels, quiet_hours = excluded.quiet_hours, max_alerts_per_day = excluded.max_alerts_per_day,
                    updated_at = excluded.updated_at')
        ->execute([$uid, $horizon, $level, $pct, $rr, $cert, json_encode($pref), json_encode($ch), $quiet ? json_encode($quiet) : null, $max, time()]);
    // סיכון לעסקה של שלב 1 (המחשבון) — אותו ערך
    $db->prepare('UPDATE users SET default_risk_pct = ? WHERE id = ?')->execute([$pct, $uid]);
    $db->commit();
    return ts_profile($uid);
}

/** שמירת ההגדרות של שלב 1 משנה גם את הסיכון שבפרופיל — שני המסכים מציגים את אותו ערך */
function ts_profile_sync_risk(int $uid): void
{
    ts_db()->prepare('UPDATE user_profile SET risk_pct = (SELECT default_risk_pct FROM users WHERE id = ?) WHERE user_id = ?')->execute([$uid, $uid]);
}

// ── שיטות (6.7) ──────────────────────────────────────────

function ts_method_settings(string $id): array
{
    $m = ts_method($id)->meta();
    $st = ts_db()->prepare('SELECT * FROM methods WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return [
        'enabled' => $r ? (bool)$r['enabled'] : false,
        'markets' => $r ? (json_decode($r['markets'], true) ?: $m['markets']) : $m['markets'],
        'timeframes' => $r ? (json_decode($r['timeframes'], true) ?: $m['timeframes']) : $m['timeframes'],
        'params' => $r ? (json_decode($r['params'], true) ?: []) : [],
        'note' => $r ? $r['note'] : '',
    ];
}

/** השיטות שהמשתמש רואה: מופעלות, ושיטת דוגמה — רק למנהל */
function ts_methods_visible(int $uid): array
{
    $admin = ts_is_admin($uid);
    $out = [];
    foreach (array_keys(ts_methods_all()) as $id) {
        $m = ts_method($id);
        $meta = $m->meta();
        $set = ts_method_settings($id);
        if (!$set['enabled'] || (!empty($meta['sample']) && !$admin)) continue;
        $p = $m->params($set['params']);
        $out[] = ['id' => $id, 'name' => $meta['name'], 'source' => $meta['source'], 'sample' => !empty($meta['sample']),
            'label' => $meta['label'] ?? null, 'version' => $meta['version'], 'markets' => $set['markets'],
            'timeframes' => $set['timeframes'], 'horizon_bars' => $m->horizon($p), 'note' => $set['note'],
            'alerts' => !empty($meta['alerts']) && empty($meta['sample'])];
    }
    return $out;
}

/** שיטה למשתמש — רק אם הוא רואה אותה; אחרת "לא נמצאה" (כמו שיטה שלא קיימת) */
function ts_method_for(int $uid, mixed $id): array
{
    if (!is_string($id) || !ts_method_exists($id) || !in_array($id, array_column(ts_methods_visible($uid), 'id'), true)) {
        throw new TsNotFound('השיטה לא זמינה');
    }
    $m = ts_method($id);
    $set = ts_method_settings($id);
    return [$m, $set, $m->params($set['params'])];
}

function ts_admin_methods(): array
{
    $out = [];
    foreach (array_keys(ts_methods_all()) as $id) {
        $m = ts_method($id);
        $meta = $m->meta();
        $st = ts_db()->prepare('SELECT COUNT(*) AS n, MAX(computed_at) AS at FROM backtests WHERE method_id = ?');
        $st->execute([$id]);
        $bt = $st->fetch();
        $out[] = ['id' => $id, 'name' => $meta['name'], 'source' => $meta['source'], 'sample' => !empty($meta['sample']),
            'label' => $meta['label'] ?? null, 'version' => $meta['version'], 'all_markets' => $meta['markets'],
            'all_timeframes' => $meta['timeframes'], 'param_defs' => $meta['params'], 'horizon_bars' => $meta['horizon_bars'],
            'alerts' => !empty($meta['alerts']) && empty($meta['sample']), 'incremental' => !empty($meta['incremental']),
            'backtests' => (int)$bt['n'], 'last_backtest_at' => $bt['at'] !== null ? (int)$bt['at'] : null] + ts_method_settings($id);
    }
    return $out;
}

function ts_admin_method_save(int $uid, array $in): void
{
    $id = $in['id'] ?? null;
    if (!is_string($id) || !ts_method_exists($id)) throw new TsNotFound('שיטה לא מוכרת');
    $m = ts_method($id);
    $meta = $m->meta();
    $enabled = ($in['enabled'] ?? false) === true;
    $sub = function (mixed $v, array $all, string $what): array {
        if (!is_array($v) || !array_is_list($v) || !$v) throw new TsInvalid("$what — לבחור לפחות אחד");
        foreach ($v as $x) if (!is_string($x) || !in_array($x, $all, true)) throw new TsInvalid("$what — ערך שהשיטה לא תומכת בו");
        return array_values(array_intersect($all, $v));
    };
    $markets = $sub($in['markets'] ?? $meta['markets'], $meta['markets'], 'שווקים');
    $tfs = $sub($in['timeframes'] ?? $meta['timeframes'], $meta['timeframes'], 'מסגרות זמן');
    $params = [];
    $pin = $in['params'] ?? [];
    if (!is_array($pin)) throw new TsInvalid('פרמטרים לא תקינים');
    foreach ($pin as $k => $v) {
        $d = $meta['params'][$k] ?? throw new TsInvalid('פרמטר לא מוכר');
        if ($v === null || $v === '') continue;   // ריק — ברירת המחדל
        if (is_string($v) && is_numeric($v)) $v = $v + 0;
        if ((!is_int($v) && !is_float($v)) || !is_finite((float)$v)) throw new TsInvalid("{$d['label']}: מספר לא תקין");
        if (!empty($d['int']) && (float)$v !== floor((float)$v)) throw new TsInvalid("{$d['label']}: מספר שלם");
        if ($v < $d['min'] || $v > $d['max']) throw new TsInvalid("{$d['label']}: בין {$d['min']} ל-{$d['max']}");
        $params[$k] = !empty($d['int']) ? (int)$v : (float)$v;
    }
    if ($err = $m->validate($m->params($params))) throw new TsInvalid($err);
    $note = ts_text($in['note'] ?? '', 500, true);
    ts_db()->prepare('INSERT INTO methods (id, enabled, markets, timeframes, params, note) VALUES (?, ?, ?, ?, ?, ?)
                      ON CONFLICT(id) DO UPDATE SET enabled = excluded.enabled, markets = excluded.markets, timeframes = excluded.timeframes,
                        params = excluded.params, note = excluded.note')
        ->execute([$id, $enabled ? 1 : 0, json_encode($markets), json_encode($tfs), json_encode((object)$params), $note]);
    ts_admin_log($uid, 'method_save', "$id enabled=" . ($enabled ? 1 : 0) . ' ' . json_encode($params));
}

/** עמלה והחלקה לכל שוק, באחוזים (מחרוזות) */
function ts_costs(): array
{
    $saved = json_decode((string)ts_setting('bt_costs', '{}'), true) ?: [];
    $out = [];
    foreach (TS_COST_DEFAULTS as $mk => $d) $out[$mk] = ['fee' => $saved[$mk]['fee'] ?? $d['fee'], 'slip' => $saved[$mk]['slip'] ?? $d['slip']];
    return $out;
}

function ts_costs_save(int $uid, mixed $in): array
{
    if (!is_array($in)) throw new TsInvalid('עמלות לא תקינות');
    $out = ts_costs();
    foreach ($in as $mk => $c) {
        if (!isset(TS_COST_DEFAULTS[$mk]) || !is_array($c)) throw new TsInvalid('שוק לא מוכר');
        foreach (['fee', 'slip'] as $k) {
            $v = ts_num($c[$k] ?? '', $k === 'fee' ? 'עמלה' : 'החלקה', false, true, 4);
            if (dec_cmp($v, '5') > 0) throw new TsInvalid('עמלה והחלקה — עד 5%');
            $out[$mk][$k] = $v;
        }
    }
    ts_setting_set('bt_costs', json_encode($out));
    ts_admin_log($uid, 'costs_save', json_encode($out));
    return $out;
}

/** לשוק — שברים (float) למנוע */
function ts_cost_for(string $market): array
{
    $c = ts_costs()[$market] ?? TS_COST_DEFAULTS['crypto'];
    return ['fee' => (float)$c['fee'] / 100, 'slip' => (float)$c['slip'] / 100];
}

function ts_asset_market(string $asset): string
{
    $meta = ts_asset_meta($asset);
    if ($meta && $meta['market']) return $meta['market'];
    [$provider] = ts_asset_split($asset);
    return ts_provider($provider)->meta()['markets'][0] ?? 'crypto';
}

// ── היסטוריה לבדיקה לאחור (6.2 ג–ה) ─────────────────────

/**
 * נרות סגורים (מחרוזות) של נכס, מ-$from עד עכשיו. החלק שבתוך 60 היום — דרך המאגר (ts_market_candles, ופנייה לספק
 * רק על החסר); החלק הישן — ישר מהספק, לריצה הזו בלבד, ולא נשמר. התקציב נבדק לפני שמתחילים (ה): לא מספיק —
 * TsBudget. מחזיר [rows, provider_calls].
 */
function ts_bt_history(int $uid, string $asset, string $tf, int $from, int $now): array
{
    [$provider, $symbol] = ts_asset_split($asset);
    $acc = ts_access($uid, $provider) ?? throw new TsInvalid('אין לכם חיבור לספק הזה. מחברים ב"עוד ← ספקי נתונים".');
    $meta = ts_provider($provider)->meta();
    if (!isset($meta['tfs'][$tf])) throw new TsInvalid($meta['name'] . ' לא נותן את מסגרת הזמן הזו');
    $sec = TS_TF_SECONDS[$tf];
    $from = max($from, $now - TS_BT_MAX_CANDLES * $sec);
    $poolStart = $now - TS_POOL_DAYS * TS_DAY;
    $win = TS_MAX_CANDLES * $sec;

    $oldCalls = $from < $poolStart ? (int)ceil((($poolStart - $from) / $sec) / max(1, (int)($meta['max_per_call'] ?? 1000))) : 0;
    $poolCalls = max(0, (int)ceil(($now - max($from, $poolStart) + 1) / $win));
    if (ts_usage_left($uid, $provider, $acc) < $oldCalls + $poolCalls) throw new TsBudget('הבדיקה לאחור תושלם מאוחר יותר — לא נשאר היום מספיק תקציב פניות לספק');

    $rows = []; $calls = 0;
    try {
        // הישן — בחלונות של קריאה אחת לספק (max_per_call נרות), כל אחד נספר בתקציב
        $step = max(1, (int)($meta['max_per_call'] ?? 1000)) * $sec;
        for ($a = $from; $oldCalls && $a < $poolStart; $a += $step) {
            $b = min($a + $step - 1, $poolStart - 1);
            $old = ts_provider_call($uid, $provider, $acc, fn($p, $s) => $p->candles($symbol, $tf, $a, $b, $s));
            $calls++;
            foreach ($old as $r) $rows[$r['t']] = $r;
        }
        for ($a = max($from, $poolStart); $a <= $now; $a += $win) {
            $r = ts_market_candles($uid, $asset, $tf, $a, min($a + $win - 1, $now), $now);
            $calls += $r['provider_calls'];
            foreach ($r['candles'] as $c) if ($c['t'] >= $from) $rows[$c['t']] = $c;
        }
    } catch (TsProviderError $e) {
        if (str_contains($e->getMessage(), 'תקרה')) throw new TsBudget('הבדיקה לאחור תושלם מאוחר יותר — ' . $e->getMessage());
        throw $e;
    }
    ksort($rows);
    $closed = array_values(array_filter($rows, fn($c) => $c['t'] + $sec <= $now));   // הנר הפתוח — לא נכנס (6.2 א)
    return [array_slice($closed, -TS_BT_MAX_CANDLES), $calls];
}

// ── בדיקה לאחור (6.2) ────────────────────────────────────

function ts_bt_hash(array $p, array $cost): string
{
    return substr(sha1(json_encode([$p, $cost])), 0, 16);
}

/**
 * התוצאה של (שיטה, גרסה, ספק, נכס, מסגרת, פרמטרים, היקף מפתח) — שמורה אם עדכנית (פחות מיום), מצטברת אם רק חלף
 * זמן, או מלאה. מחזיר {status ok|insufficient|later|incomplete|error, ...תוצאה, computed_at, mode, provider_calls}.
 */
function ts_backtest(int $uid, TsMethod $m, array $p, string $asset, string $tf, string $market, int $now): array
{
    [$provider] = ts_asset_split($asset);
    $acc = ts_access($uid, $provider) ?? throw new TsInvalid('אין לכם חיבור לספק הזה');
    $scope = $acc['scope'];
    $meta = $m->meta();
    $cost = ts_cost_for($market);
    $hash = ts_bt_hash($p, $cost);
    $db = ts_db();
    $st = $db->prepare('SELECT * FROM backtests WHERE method_id = ? AND provider = ? AND asset = ? AND tf = ? AND cred_scope = ?');
    $st->execute([$meta['id'], $provider, $asset, $tf, $scope]);
    $row = $st->fetch() ?: null;
    $same = $row && (int)$row['method_version'] === (int)$meta['version'] && $row['params_hash'] === $hash;

    if ($same && (int)$row['computed_at'] > $now - TS_BT_STALE) {
        return ts_bt_out(json_decode($row['state'], true), (int)$row['computed_at'], 'stored', 0);
    }
    $incremental = $same && !empty($meta['incremental']);
    $state = $incremental ? json_decode($row['state'], true) : null;
    $from = $incremental ? (int)$row['to_t'] + 1 : $now - TS_BT_RANGE[$tf];
    $deadline = microtime(true) + TS_BT_MAX_SECONDS;
    try {
        [$rows, $calls] = ts_bt_history($uid, $asset, $tf, $from, $now);
        $s = ts_bt_run($m, $p, ts_bt_floats($rows), $cost, $state, $deadline);
    } catch (TsBudget $e) {
        return ['status' => 'later', 'message' => $e->getMessage()];
    } catch (TsBacktestTimeout) {
        return ['status' => 'incomplete', 'message' => 'הבדיקה לאחור לא הושלמה בתוך ' . TS_BT_MAX_SECONDS . ' שניות'];
    } catch (TsProviderError $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
    $res = ts_bt_result($s);
    $db->prepare('INSERT OR REPLACE INTO backtests (method_id, method_version, provider, asset, tf, params_hash, cred_scope, n, wins, expectancy_r, max_dd, from_t, to_t, state, computed_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$meta['id'], $meta['version'], $provider, $asset, $tf, $hash, $scope, $res['n'], $res['wins'], $res['expectancy_r'],
            $res['max_dd'], $res['from_t'], $res['to_t'], json_encode($s), $now]);
    return ts_bt_out($s, $now, $incremental ? 'incremental' : 'full', $calls);
}

function ts_bt_out(array $s, int $at, string $mode, int $calls): array
{
    $r = ts_bt_result($s);
    $cert = ts_certainty($r['wins'], $r['n']);
    return ['status' => $cert === null ? 'insufficient' : 'ok', 'certainty' => $cert,
        'win_pct' => $r['n'] >= TS_MIN_SIGNALS ? (int)round(100 * $r['win_rate']) : null] + $r
        + ['computed_at' => $at, 'mode' => $mode, 'provider_calls' => $calls];
}

// ── ניתוח (6.1, 6.3–6.6) ─────────────────────────────────

/** כמה ספרות אחרי הנקודה במחירים של הנכס — ה-tick (לפי מה שהספק נותן, ב-50 הנרות האחרונים) */
function ts_tick_decimals(array $rows): int
{
    $d = 0;
    foreach (array_slice($rows, -50) as $r) {
        foreach (['o', 'h', 'l', 'c'] as $k) {
            $frac = rtrim(explode('.', (string)$r[$k] . '.')[1], '0');
            $d = max($d, strlen($frac));
        }
    }
    return min($d, 10);
}

/** רמה מהמנוע (float) → מחרוזת עשרונית מעוגלת ל-tick (עיקרון 6). רק מכאן היא נוגעת בכסף */
function ts_level_str(?float $v, int $decimals): ?string
{
    if ($v === null || !is_finite($v)) return null;
    $s = sprintf('%.' . $decimals . 'F', round($v, $decimals));
    return dec_parse($s, 10) ?? null;
}

function ts_horizon_text(int $bars, string $tf): string
{
    $sec = $bars * TS_TF_SECONDS[$tf];
    $approx = $sec < TS_DAY ? max(1, (int)round($sec / 3600)) . ' שעות'
        : ($sec < 14 * TS_DAY ? (int)round($sec / TS_DAY) . ' ימים'
        : ($sec < 90 * TS_DAY ? (int)round($sec / (7 * TS_DAY)) . ' שבועות' : (int)round($sec / (30 * TS_DAY)) . ' חודשים'));
    return 'עד ' . $bars . ' נרות של ' . TS_TIMEFRAMES[$tf] . " (~$approx)";
}

/** האופק הארוך ביותר שהנתונים מגבים (6.4): המסגרת הארוכה ביותר עם N ≥ 30 בבדיקות לאחור השמורות */
function ts_max_horizon(TsMethod $m, array $p, string $asset, string $scope): ?array
{
    $st = ts_db()->prepare('SELECT tf, n FROM backtests WHERE method_id = ? AND asset = ? AND cred_scope = ? AND method_version = ? AND n >= ?');
    $st->execute([$m->meta()['id'], $asset, $scope, $m->meta()['version'], TS_MIN_SIGNALS]);
    $best = null;
    foreach ($st->fetchAll() as $r) if (isset(TS_TF_SECONDS[$r['tf']]) && ($best === null || TS_TF_SECONDS[$r['tf']] > TS_TF_SECONDS[$best])) $best = $r['tf'];
    return $best ? ['tf' => $best, 'text' => ts_horizon_text($m->horizon($p), $best)] : null;
}

/**
 * כרטיסי ניתוח לנכס ומסגרת, לכל שיטה שנבחרה, ושקלול (6.5). כל שיטה — בדיקה לאחור (או השמורה) וניתוח של המצב
 * אחרי הנר האחרון שנסגר.
 */
function ts_analysis(int $uid, mixed $asset, mixed $tf, mixed $methods, ?int $now = null): array
{
    [$provider, $symbol] = ts_asset_split($asset);
    if (!is_string($tf) || !isset(TS_TF_SECONDS[$tf])) throw new TsInvalid('מסגרת זמן לא תקינה');
    $ids = is_string($methods) ? array_values(array_filter(explode(',', $methods))) : [];
    if (!$ids) throw new TsInvalid('בחרו שיטה אחת לפחות');
    if (count($ids) > 10) throw new TsInvalid('עד 10 שיטות בניתוח אחד');
    $now ??= ts_now();
    $acc = ts_access($uid, $provider) ?? throw new TsInvalid('אין לכם חיבור לספק הזה. מחברים ב"עוד ← ספקי נתונים".');
    $profile = ts_profile($uid);
    $market = ts_asset_market($asset);
    $sec = TS_TF_SECONDS[$tf];

    // ניתוח המצב עכשיו — חלון של נרות אחרונים מהמאגר (פנייה לספק רק על החסר)
    $warm = 300;
    $prepared = [];
    foreach (array_unique($ids) as $id) {
        [$m, $set, $p] = ts_method_for($uid, $id);
        $prepared[$id] = [$m, $set, $p];
        $warm = max($warm, (int)($m->meta()['warmup'] ?? 0) + $m->horizon($p) + 10);
    }
    $recent = ts_market_candles($uid, $asset, $tf, $now - min($warm, TS_MAX_CANDLES) * $sec, $now, $now);
    $rows = array_values(array_filter($recent['candles'], fn($c) => $c['t'] + $sec <= $now));
    $calls = $recent['provider_calls'];
    $dec = ts_tick_decimals($rows);
    $fl = ts_bt_floats($rows);
    $atr = $fl ? ts_atr(array_column($fl, 'h'), array_column($fl, 'l'), array_column($fl, 'c'), 14) : [];
    $atrNow = $atr ? end($atr) : null;
    $u = ts_db()->prepare('SELECT account_size FROM users WHERE id = ?');
    $u->execute([$uid]);
    $account = $u->fetchColumn() ?: null;

    $cards = [];
    foreach ($prepared as $id => [$m, $set, $p]) {
        $meta = $m->meta();
        $card = ['method' => ['id' => $id, 'name' => $meta['name'], 'source' => $meta['source'], 'version' => $meta['version'],
            'sample' => !empty($meta['sample']), 'label' => !empty($meta['sample']) ? $meta['label'] : null],
            'tf' => $tf, 'horizon_bars' => $m->horizon($p), 'horizon_text' => ts_horizon_text($m->horizon($p), $tf)];
        if (!in_array($tf, $set['timeframes'], true) || !in_array($market, $set['markets'], true)) {
            $cards[] = $card + ['status' => 'unsupported', 'message' => 'השיטה לא מוגדרת לשוק או למסגרת הזמן הזו', 'bias' => 'none', 'certainty' => null];
            continue;
        }
        $a = $m->analyze($fl, $p);
        $lv = $a['levels'];
        $levels = $lv ? ['entry' => ts_level_str($lv['entry'], $dec), 'stop' => ts_level_str($lv['stop'], $dec), 'target' => ts_level_str($lv['target'], $dec)] : null;
        $bt = ts_backtest($uid, $m, $p, $asset, $tf, $market, $now);
        $calls += $bt['provider_calls'] ?? 0;
        $cert = $bt['certainty'] ?? null;
        $risk = null;
        if ($levels && $levels['stop'] !== null) {
            $dist = dec_abs(dec_sub($levels['entry'], $levels['stop']));
            $stopAtr = $atrNow ? abs($lv['entry'] - $lv['stop']) / $atrNow : null;
            $rr = $levels['target'] !== null && dec_sign($dist) > 0 ? dec_div(dec_abs(dec_sub($levels['target'], $levels['entry'])), $dist, 2) : null;
            $qty = $account && dec_sign($dist) > 0 ? dec_div(dec_div(dec_mul($account, $profile['risk_pct']), '100', 10), $dist, 8) : null;
            $risk = ['level' => $stopAtr === null ? null : ($stopAtr <= 1.5 ? 'low' : ($stopAtr <= 3 ? 'medium' : 'high')),
                'stop_atr' => $stopAtr !== null ? round($stopAtr, 2) : null, 'stop_distance' => $dist, 'rr' => $rr,
                'rr_below_min' => $rr !== null && dec_cmp($rr, $profile['min_rr']) < 0, 'risk_pct' => $profile['risk_pct'],
                'position_qty' => $qty];
        }
        $cards[] = $card + ['status' => 'ok', 'bias' => $a['bias'], 'conditions' => array_map(fn($c) => ['id' => $c['id'], 'text' => $c['text'],
            'side' => $c['side'], 'met' => (bool)$c['met']], $a['conditions']), 'signal_ago' => $a['signal_ago'] ?? null,
            'levels' => $levels, 'as_of' => $a['as_of'], 'backtest' => $bt, 'certainty' => $cert,
            'below_threshold' => $cert !== null && $cert < $profile['min_certainty'], 'risk' => $risk,
            'max_horizon' => ts_max_horizon($m, $p, $asset, $acc['scope'])];
    }
    $blend = ts_blend(array_map(fn($c) => ['id' => $c['method']['id'], 'bias' => $c['bias'], 'certainty' => $c['certainty']], $cards), $profile['preferred_methods']);
    return ['asset' => "$provider:$symbol", 'symbol' => $symbol, 'provider' => $provider, 'tf' => $tf, 'market' => $market,
        'decimals' => $dec, 'last_t' => $rows ? $rows[count($rows) - 1]['t'] : null, 'cards' => $cards, 'blend' => $blend,
        'profile' => ['min_certainty' => $profile['min_certainty'], 'min_rr' => $profile['min_rr'], 'risk_pct' => $profile['risk_pct']],
        'account_size' => $account, 'disclaimer' => TS_ANALYSIS_DISCLAIMER, 'provider_calls' => $calls];
}

// ── "פתח עסקה מזה" (פרק 11) ─────────────────────────────

/** שוק של ספק → שוק של היומן (שלב 1). מדד — כמו מניה; סחורה — חוזה עתידי (המכפיל ממולא בטופס) */
const TS_JOURNAL_MARKET = ['crypto' => 'crypto', 'forex' => 'forex', 'stocks' => 'stocks', 'indices' => 'stocks',
    'commodities' => 'futures', 'futures' => 'futures', 'options' => 'options'];

/**
 * המילוי של טופס העסקה מכרטיס ניתוח. ביומן הנכס הוא **הסימול, לא הספק**: מחפשים ב"נכסים שלי" לפי סימול מנורמל (בלי
 * ספק, אותיות גדולות, בלי '/'). נמצא — משתמשים בו, כולל המכפיל שהמשתמש קבע. לא נמצא — שוק ומטבע מה-meta של הספק,
 * מכפיל ברירת מחדל לפי השוק; הוא נוצר רק כשהמשתמש מאשר בטופס ושומר.
 */
function ts_trade_prefill(int $uid, mixed $asset): array
{
    [$provider, $symbol] = ts_asset_split($asset);
    $norm = ts_norm_symbol($symbol);
    $meta = ts_asset_meta("$provider:$symbol");
    $pmarket = $meta['market'] ?? (ts_provider($provider)->meta()['markets'][0] ?? 'stocks');
    $market = TS_JOURNAL_MARKET[$pmarket] ?? 'stocks';
    $currency = $meta['currency'] ?? null;
    if (!$currency) {
        foreach (['USDT', 'USDC', 'FDUSD', 'BTC', 'EUR', 'ETH', 'BNB', 'TRY', 'USD', 'ILS'] as $q) {
            if (strlen($norm) > strlen($q) && str_ends_with($norm, $q)) { $currency = $q; break; }
        }
    }
    $currency = strtoupper((string)($currency ?: 'USD'));
    $st = ts_db()->prepare('SELECT * FROM instruments WHERE user_id = ?');
    $st->execute([$uid]);
    $found = null;
    foreach ($st->fetchAll() as $i) {
        if (ts_norm_symbol($i['symbol']) !== $norm) continue;
        if ($found === null || ($i['market'] === $market && $found['market'] !== $market)) $found = $i;
    }
    if ($found) {
        return ['asset' => "$provider:$symbol", 'existing' => true] + ts_instrument_out($found);
    }
    return ['asset' => "$provider:$symbol", 'existing' => false, 'symbol' => $norm, 'market' => $market, 'currency' => $currency,
        'multiplier' => TS_MARKETS[$market]['multiplier'], 'unit_label' => TS_MARKETS[$market]['unit'], 'option' => null];
}
