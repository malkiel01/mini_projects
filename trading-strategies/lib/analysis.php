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
require_once __DIR__ . '/method-colors.php';
require_once __DIR__ . '/journal.php';
require_once __DIR__ . '/learn.php';

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
        // הלשוניות של מסך השוק (18.1.4) — בפרופיל, נשמרות ב-users.market_tabs (lib/tabs.php)
        'market_tabs' => function_exists('ts_market_tabs') ? ts_market_tabs($uid) : null,
        // הצבע של כל שיטה (18.5) — {method_id: צבע מהפלטה}; אותה שיטה, אותו צבע בכל הזוגות (lib/method-colors.php)
        'method_colors' => ts_method_colors($uid),
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

/**
 * ההגדרות של שיטה. בלי שורה ב-`methods`: שיטת קוד מהספר (לא `sample`) — מופעלת; שיטת הבדיקה ושיטה מוגדרת — כבויות
 * (18.1.2, F1; 6.7). שורה עם enabled = 0 — ההחלטה של המנהל גוברת.
 */
function ts_method_settings(string $id): array
{
    $m = ts_method($id)->meta();
    $st = ts_db()->prepare('SELECT * FROM methods WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return [
        'enabled' => $r ? (bool)$r['enabled'] : ts_method_on_by_default($m),
        'markets' => $r ? (json_decode($r['markets'], true) ?: $m['markets']) : $m['markets'],
        'timeframes' => $r ? (json_decode($r['timeframes'], true) ?: $m['timeframes']) : $m['timeframes'],
        'params' => $r ? (json_decode($r['params'], true) ?: []) : [],
        'note' => $r ? $r['note'] : '',
    ];
}

/** שיטה מהספר = שיטת קוד ש-sample שלה לא true (18.1.2) */
function ts_method_on_by_default(array $meta): bool
{
    // default_off — שיטה חדשה שהאפיון קבע שתתחיל כבויה עד שמנהל מפעיל (פיבונאצ'י, 22.1, 6.7)
    return ($meta['kind'] ?? 'code') === 'code' && empty($meta['sample']) && empty($meta['default_off']);
}

/** התווית של השיטה (שיטות קוד בלבד — בונה השיטות נמחק, 21.1) */
function ts_method_label(TsMethod $m): ?string
{
    return $m->meta()['label'] ?? null;
}

/** השיטות שהמשתמש רואה: מופעלות, ושיטת דוגמה — רק למנהל */
function ts_methods_visible(int $uid): array
{
    $admin = ts_is_editor($uid);
    $out = [];
    foreach (ts_method_ids() as $id) {
        $m = ts_method($id);
        $meta = $m->meta();
        $set = ts_method_settings($id);
        if (!$set['enabled'] || (!empty($meta['sample']) && !$admin)) continue;
        $p = $m->params($set['params']);
        $out[] = ['id' => $id, 'name' => $meta['name'], 'source' => $meta['source'], 'sample' => !empty($meta['sample']),
            'label' => ts_method_label($m), 'kind' => $meta['kind'] ?? 'code', 'version' => $meta['version'], 'markets' => $set['markets'],
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
    foreach (ts_method_ids() as $id) {
        $m = ts_method($id);
        $meta = $m->meta();
        $st = ts_db()->prepare("SELECT COUNT(*) AS n, MAX(computed_at) AS at FROM backtests WHERE method_id = ? AND source = 'provider'");
        $st->execute([$id]);
        $bt = $st->fetch();
        $out[] = ['id' => $id, 'name' => $meta['name'], 'source' => $meta['source'], 'sample' => !empty($meta['sample']),
            'label' => ts_method_label($m), 'kind' => $meta['kind'] ?? 'code',
            'version' => $meta['version'], 'all_markets' => $meta['markets'],
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
    // שכלול (18.7, ✅24): שינוי פרמטר משנה את התוצאה על נרות זהים — רק המנהל הראשי, והגרסה עולה (tune + 1), כדי
    // שהבדיקות לאחור והיומן לא יערבבו את הערך הישן עם החדש. מנהל אחר — דרך הצעה (lib/tune.php). הפעלה, שווקים, מסגרות
    // והערה — כמו קודם, כל מנהל.
    $changed = $m->params($params) !== $m->params(ts_method_settings($id)['params']);
    if ($changed) ts_require_chief();
    ts_db()->prepare('INSERT INTO methods (id, enabled, markets, timeframes, params, note, tune) VALUES (?, ?, ?, ?, ?, ?, ?)
                      ON CONFLICT(id) DO UPDATE SET enabled = excluded.enabled, markets = excluded.markets, timeframes = excluded.timeframes,
                        params = excluded.params, note = excluded.note, tune = methods.tune + ?')
        ->execute([$id, $enabled ? 1 : 0, json_encode($markets), json_encode($tfs), json_encode((object)$params), $note, $changed ? 1 : 0, $changed ? 1 : 0]);
    ts_method_forget();
    ts_admin_log($uid, 'method_save', "$id enabled=" . ($enabled ? 1 : 0) . ' ' . json_encode($params)
        . ($changed ? ' v' . $meta['version'] . ' → v' . ts_method($id)->meta()['version'] : ''));
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
    // זוג מהקטלוג (18.1) בלי פרטים מהחיפוש: לפי הצד הראשון — קריפטו או מט"ח
    if (($key = ts_pair_of_asset($asset)) !== null) return (TS_PAIR_SIDES[explode('/', $key)[0]][2] ?? '') === 'crypto' ? 'crypto' : 'forex';
    [$provider] = ts_asset_split($asset);
    return ts_provider($provider)->meta()['markets'][0] ?? 'crypto';
}

// ── היסטוריה לבדיקה לאחור (6.2 ג–ה) ─────────────────────

/**
 * נרות סגורים (מחרוזות) של נכס, מ-$from עד עכשיו. החלק שבתוך 60 היום — דרך המאגר (ts_market_candles, ופנייה לספק
 * רק על החסר); החלק הישן — ישר מהספק, לריצה הזו בלבד, ולא נשמר. התקציב נבדק לפני שמתחילים (ה): לא מספיק —
 * TsBudget. מחזיר [rows, provider_calls, complete].
 *
 * $deadline (microtime) — הספק איטי: לפני כל פנייה נבדק הזמן, ואם עבר (ויש כבר משהו) — עוצרים. מה שחוזר הוא תמיד
 * **רצף מ-$from** (החלונות נמשכים מהישן לחדש), ו-complete = false; ts_backtest שומר את המצב עד שם וממשיך ממנו בפעם הבאה.
 */
function ts_bt_history(int $uid, string $asset, string $tf, int $from, int $now, ?float $deadline = null): array
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

    $rows = []; $calls = 0; $complete = true;
    try {
        // הישן — בחלונות של קריאה אחת לספק (max_per_call נרות), כל אחד נספר בתקציב
        $step = max(1, (int)($meta['max_per_call'] ?? 1000)) * $sec;
        // עוצרים רק אחרי פנייה אחת לפחות — כך כל קריאה מתקדמת ($calls ברפרנס: הוא גדל בלולאות)
        $late = function () use (&$calls, $deadline): bool { return $deadline !== null && $calls > 0 && microtime(true) > $deadline; };
        for ($a = $from; $oldCalls && $a < $poolStart; $a += $step) {
            if ($late()) { $complete = false; break; }
            $b = min($a + $step - 1, $poolStart - 1);
            // זוג מחושב ("1/USDILS", 18.1): הספק נותן את הכיוון המקורי, והופכים
            $inv = str_starts_with($symbol, '1/');
            $old = ts_provider_call($uid, $provider, $acc, fn($p, $s) => $p->candles($inv ? substr($symbol, 2) : $symbol, $tf, $a, $b, $s));
            if ($inv) $old = ts_pair_invert_candles($old);
            $calls++;
            foreach ($old as $r) $rows[$r['t']] = $r;
        }
        for ($a = max($from, $poolStart); $complete && $a <= $now; $a += $win) {
            if ($late()) { $complete = false; break; }
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
    return [array_slice($closed, -TS_BT_MAX_CANDLES), $calls, $complete];
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
/**
 * ההיקפים שמהם קוראים בדיקה לאחור שמורה, לפי סדר (21.3 (2), ביקורת 🟡5): בספק משותף — קודם '' (המאגר של כולם), ואם אין —
 * ה-u:<id> הישן של המשתמש עצמו (מלפני 21.3, רק שלו). אחרת — ההיקף של הדרך שלו. ריק — אין מאיפה.
 */
function ts_bt_read_scopes(int $uid, string $provider): array
{
    $scope = ts_scope_for($uid, $provider);
    if ($scope === null) return [];
    return $scope === '' && ts_provider($provider)->meta()['share'] ? ['', "u:$uid"] : [$scope];
}

/** התנאי ב-SQL וסדר ההעדפה של ts_bt_read_scopes: [" AND cred_scope IN (…)", " ORDER BY …", params] */
function ts_bt_scope_sql(array $scopes): array
{
    if (!$scopes) return [' AND 0', '', []];
    return [' AND cred_scope IN (' . implode(',', array_fill(0, count($scopes), '?')) . ')', " ORDER BY cred_scope = '' DESC", $scopes];
}

function ts_backtest(int $uid, TsMethod $m, array $p, string $asset, string $tf, string $market, int $now): array
{
    [$provider] = ts_asset_split($asset);
    // ספק משותף בלי דרך אליו (21.3): אין במה לחשב — רק מה שכבר שמור במאגר המשותף
    if (!ts_access($uid, $provider) && ts_pool_only($uid, $provider)) return ts_backtest_stored($uid, $m, $p, $asset, $tf, $market, $now);
    $acc = ts_access($uid, $provider) ?? throw new TsInvalid('אין לכם חיבור לספק הזה');
    $scope = $acc['scope'];
    $meta = $m->meta();
    $cost = ts_cost_for($market);
    $hash = ts_bt_hash($p, $cost);
    $db = ts_db();
    $st = $db->prepare("SELECT * FROM backtests WHERE method_id = ? AND provider = ? AND asset = ? AND tf = ? AND cred_scope = ? AND source = 'provider'");
    $st->execute([$meta['id'], $provider, $asset, $tf, $scope]);
    $row = $st->fetch() ?: null;
    // ספק משותף (🟡5): אין ב-'' — תוצאה ישנה של המשתמש עצמו ב-u:<id>. מה שיחושב מכאן — נשמר ב-''
    if (!$row && $scope === '' && ts_provider($provider)->meta()['share']) {
        $st->execute([$meta['id'], $provider, $asset, $tf, "u:$uid"]);
        $row = $st->fetch() ?: null;
    }
    $same = $row && (int)$row['method_version'] === (int)$meta['version'] && $row['params_hash'] === $hash;

    if ($same && (int)$row['computed_at'] > $now - TS_BT_STALE) {
        return ts_bt_out(json_decode($row['state'], true), (int)$row['computed_at'], 'stored', 0);
    }
    $incremental = $same && !empty($meta['incremental']);
    $state = $incremental ? json_decode($row['state'], true) : null;
    $from = $incremental ? (int)$row['to_t'] + 1 : $now - TS_BT_RANGE[$tf];
    // בדיקה שלא הושלמה בפעם הקודמת (ספק איטי) — ממשיכים מהמצב שלה, ולא מושכים שוב את מה שכבר נמשך. רק לשיטה מצטברת:
    // אצלה המשך מהמצב = ריצה אחת על כל הנרות (tests/backtest.php)
    $pk = ts_bt_partial_key($meta, $provider, $asset, $tf, (string)$scope, $hash, $incremental ? (int)$row['to_t'] : null);
    if (!empty($meta['incremental']) && ($part = ts_bt_partial_get($pk, $now))) [$state, $from] = [$part['state'], $part['to_t'] + 1];
    $max = ts_bt_max_seconds();
    $deadline = microtime(true) + $max;
    try {
        [$rows, $calls, $complete] = ts_bt_history($uid, $asset, $tf, $from, $now, $deadline);
    } catch (TsBudget $e) {
        return ['status' => 'later', 'message' => $e->getMessage()];
    } catch (TsProviderError $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
    // הריצה בחלקים, והזמן נבדק ביניהם. משך משלה (מהרגע שהיא מתחילה): המשיכה כבר יכלה לגמור את התקרה, ונר שנמשך ולא
    // נבדק היה נמשך שוב בפעם הבאה. המשך מהמצב = ריצה אחת, ולכן כשהכול הספיק — אותה תוצאה כמו קודם
    $s = $state ?? ts_bt_init($m, $p);
    $runUntil = microtime(true) + $max;
    $trades = [];   // למידה (23.4, L1): כל עסקה שהסתיימה בריצה הזו → method_outcomes
    $onTrade = function (array $t) use (&$trades): void { $trades[] = $t; };
    foreach (array_chunk(ts_bt_floats($rows), 2000) as $i => $chunk) {
        if ($i > 0 && microtime(true) > $runUntil) { $complete = false; break; }
        $s = ts_bt_run($m, $p, $chunk, $cost, $s, null, $onTrade);
    }
    // קודם השורות, ואחר כך המצב: כשל בכתיבה → שום דבר לא נשמר, והריצה הבאה מחשבת שוב את אותן עסקאות (בלי כפילות — 23.3)
    $added = ts_outcomes_write(['source' => 'backtest', 'method_id' => $meta['id'], 'method_version' => $meta['version'], 'params_hash' => $hash,
        'provider' => $provider, 'asset' => $asset, 'tf' => $tf, 'cred_scope' => (string)$scope, 'market' => $market,
        'decimals' => ts_tick_decimals($rows), 'now' => $now], $trades);
    // שינוי דפוס (23.8, L4): רק כשנוספו עסקאות. ממצא חריג → DevLoop. כשל כאן לא מפיל את הבדיקה לאחור — רק נרשם
    if ($added > 0) {
        try {
            ts_learn_findings_sync($meta['id'], (int)$meta['version'], 'backtest', $now);
        } catch (Throwable $e) {
            error_log('trading-strategies learning findings: ' . $e->getMessage());
        }
    }
    if (!$complete) {
        if (!empty($meta['incremental']) && $s['last_t'] !== null) ts_bt_partial_put($pk, $s, $from, (int)$s['last_t'], $now);
        return ['status' => 'incomplete', 'provider_calls' => $calls,
            'message' => "הבדיקה לאחור לא הושלמה בתוך $max שניות — היא תמשיך מאותה נקודה בפעם הבאה"];
    }
    ts_bt_partial_drop($pk);
    $res = ts_bt_result($s);
    $db->prepare('INSERT OR REPLACE INTO backtests (method_id, method_version, provider, asset, tf, params_hash, cred_scope, n, wins, expectancy_r, max_dd, from_t, to_t, state, computed_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$meta['id'], $meta['version'], $provider, $asset, $tf, $hash, $scope, $res['n'], $res['wins'], $res['expectancy_r'],
            $res['max_dd'], $res['from_t'], $res['to_t'], json_encode($s), $now]);
    ts_journal_backtest($meta, $asset, $tf, (string)$scope, $res, $now);   // יומן השיטה (18.6) — רק בחישוב חדש
    return ts_bt_out($s, $now, $incremental ? 'incremental' : 'full', $calls);
}

/** תקרת הזמן לבדיקה לאחור (✅5). בפיתוח ובבדיקות — אפשר קצרה יותר (TS_BT_MAX_SECONDS בסביבה), כדי לדמות ספק איטי */
function ts_bt_max_seconds(): float
{
    $v = defined('TS_DEV') ? getenv('TS_BT_MAX_SECONDS') : false;
    return is_string($v) && is_numeric($v) && (float)$v > 0 ? (float)$v : (float)TS_BT_MAX_SECONDS;
}

/**
 * המפתח של בדיקה לאחור חלקית (bt_partial ב-data/cache.sqlite): כל מה שהתוצאה תלויה בו — שיטה וגרסה, ספק, נכס, מסגרת,
 * היקף, פרמטרים ועמלות — ו-$base: ה-to_t של התוצאה השמורה שממנה ממשיכים (null — ריצה מלאה מההתחלה).
 */
function ts_bt_partial_key(array $meta, string $provider, string $asset, string $tf, string $scope, string $hash, ?int $base): string
{
    return hash('sha256', json_encode([$meta['id'], (int)$meta['version'], $provider, $asset, $tf, $scope, $hash, $base]));
}

/** המצב החלקי, אם יש ולא עבר יום — {state, from_t, to_t}. מטמון: כל כשל = אין, ומתחילים מחדש */
function ts_bt_partial_get(string $k, int $now): ?array
{
    try {
        $st = ts_cache()->prepare('SELECT state, from_t, to_t FROM bt_partial WHERE k = ? AND at > ?');
        $st->execute([$k, $now - TS_BT_STALE]);
        $r = $st->fetch();
        $state = $r ? json_decode($r['state'], true) : null;
        return is_array($state) ? ['state' => $state, 'from_t' => (int)$r['from_t'], 'to_t' => (int)$r['to_t']] : null;
    } catch (Throwable $e) {
        error_log('trading-strategies bt_partial: ' . $e->getMessage());
        return null;
    }
}

function ts_bt_partial_put(string $k, array $s, int $from, int $to, int $now): void
{
    $json = json_encode($s);
    if ($json === false) return;   // NaN/INF — לא נשמר; הפעם הבאה מתחילה מחדש
    try {
        ts_cache()->prepare('INSERT OR REPLACE INTO bt_partial (k, state, from_t, to_t, at) VALUES (?, ?, ?, ?, ?)')->execute([$k, $json, $from, $to, $now]);
    } catch (Throwable $e) {
        error_log('trading-strategies bt_partial: ' . $e->getMessage());
    }
}

function ts_bt_partial_drop(string $k): void
{
    try {
        ts_cache()->prepare('DELETE FROM bt_partial WHERE k = ?')->execute([$k]);
    } catch (Throwable $e) {
        error_log('trading-strategies bt_partial: ' . $e->getMessage());
    }
}

/** צורת התוצאה השמורה. 2 — S4: ממצא ותרחיש בשדות נפרדים. תוצאות בצורה הישנה פשוט לא נמצאות (ונמחקות אחרי יומיים) */
const TS_RESULT_FORMAT = 2;

/** מפתח החישוב (18.3) — כל מה שהתוצאה תלויה בו, ושום דבר אחר */
function ts_method_key(TsMethod $m, array $p, string $asset, string $tf, string $scope, array $rows): string
{
    $meta = $m->meta();
    ksort($p);
    return hash('sha256', json_encode([TS_RESULT_FORMAT, $asset, $tf, $scope, $rows ? $rows[0]['t'] : null, $rows ? end($rows)['t'] : null, count($rows),
        $meta['id'], $meta['version'], $p], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * הבדיקה לאחור השמורה בלבד — בלי לחשב (הגרף, 18.3/18.4). אותה גרסה ואותם פרמטרים ועמלות; גם אם עבר יותר מיום
 * (stale: true — הניתוח הבא שהמשתמש פותח יחשב מחדש). אין שמורה → status: missing, "לא נבדק לאחור".
 */
function ts_backtest_stored(int $uid, TsMethod $m, array $p, string $asset, string $tf, string $market, int $now): array
{
    [$provider] = ts_asset_split($asset);
    [$in, $order, $sc] = ts_bt_scope_sql(ts_bt_read_scopes($uid, $provider));
    $meta = $m->meta();
    $st = ts_db()->prepare("SELECT state, computed_at FROM backtests WHERE method_id = ? AND method_version = ? AND provider = ? AND asset = ? AND tf = ?
        AND params_hash = ? AND source = 'provider'$in$order LIMIT 1");
    $st->execute([$meta['id'], $meta['version'], $provider, $asset, $tf, ts_bt_hash($p, ts_cost_for($market)), ...$sc]);
    $row = $st->fetch();
    if (!$row) return ['status' => 'missing', 'message' => 'לא נבדק לאחור', 'provider_calls' => 0];
    return ts_bt_out(json_decode($row['state'], true), (int)$row['computed_at'], 'stored', 0)
        + ['stale' => (int)$row['computed_at'] <= $now - TS_BT_STALE];
}

/**
 * הניתוח של הכרטיס (התנאים עכשיו), שמור לפי מפתח החישוב (18.3): הנרות הסגורים של החלון, שיטה+גרסה, פרמטרים, ומתי
 * חושבה הבדיקה לאחור שהוא ממשיך ממנה (ו1). מפתח שכבר חושב — מהשמור, והשיטה לא רצה (2ה.4). $state — רק כשצריך.
 */
function ts_card_analyze(TsMethod $m, array $p, string $asset, string $tf, string $scope, array $rows, array $fl, array $bt, Closure $state, int $now): array
{
    $k = hash('sha256', 'card|' . ts_method_key($m, $p, $asset, $tf, $scope, $rows) . '|' . ($bt['computed_at'] ?? '-'));
    $c = ts_cache();
    try {
        $st = $c->prepare('SELECT result FROM method_results WHERE k = ?');
        $st->execute([$k]);
        $hit = $st->fetchColumn();
        if (is_string($hit) && is_array($v = json_decode($hit, true))) return $v;
    } catch (Throwable $e) {
        error_log('trading-strategies card cache: ' . $e->getMessage());
    }
    $v = ts_analyze_for_card($m, $p, $fl, $state());
    $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) return $v;   // NaN/INF — לא נשמר, רק מוחזר
    try {
        $c->prepare('INSERT OR REPLACE INTO method_results (k, method_id, result, at) VALUES (?, ?, ?, ?)')->execute([$k, $m->meta()['id'], $json, $now]);
    } catch (Throwable $e) {
        error_log('trading-strategies card cache: ' . $e->getMessage());
    }
    return json_decode($json, true);
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

/**
 * הפרמטרים של שיטה על נכס: שיטה ש-meta.tick שלה true (אליוט — "יעד ≤ tick → אין אות", 17.1.9 ג) מקבלת גם את ה-tick של
 * הנכס, $p['tick'] = 10^−decimals. הוא נכנס גם ל-hash של הבדיקה לאחור — tick אחר, תוצאה אחרת.
 */
function ts_params_for_asset(TsMethod $m, array $p, int $decimals): array
{
    return empty($m->meta()['tick']) ? $p : $p + ['tick' => 10 ** -$decimals];
}

/**
 * ו1 (17.1.9): הכרטיס של שיטה שתלויה במצב פנימי ארוך ממשיך **ממצב המנוע של הבדיקה לאחור** — על הנרות החדשים בלבד —
 * כדי שהכרטיס והבדיקה לאחור לא יראו אותות שונים. בלי מצב שמור (הבדיקה לא רצה או נכשלה) — מהחלון האחרון, שמתחיל
 * warmup_bars לפני הנרות המוצגים, ומסומן "חושב בלי בדיקה לאחור". מחזיר [ניתוח, basis: state|warmup].
 */
function ts_analyze_for_card(TsMethod $m, array $p, array $fl, ?array $btState): array
{
    if (empty($m->meta()['analyze_from_state']) || !$btState || !isset($btState['m'], $btState['last_t'])) {
        return [$m->analyze($fl, $p), empty($m->meta()['analyze_from_state']) ? null : 'warmup'];
    }
    $s = $btState['m'];
    $asOf = $btState['last_t'];
    foreach ($fl as $c) {
        if ($c['t'] <= $btState['last_t']) continue;
        [$s] = $m->step($s, $c, $p);
        $asOf = $c['t'];
    }
    return [$m->conditions($s, $p) + ['horizon_bars' => $m->horizon($p), 'as_of' => $asOf], 'state'];
}

/** ז0: ליד כל פרמטר [שלנו] — "הגדרה שלנו"; ליד פרמטר מהספר — "לפי הספר". רק לשיטה שמסמנת (ours) */
function ts_param_notes(TsMethod $m): ?array
{
    $defs = $m->meta()['params'] ?? [];
    if (!array_filter($defs, fn($d) => array_key_exists('ours', $d))) return null;
    $out = [];
    foreach ($defs as $k => $d) $out[$k] = ['label' => $d['label'], 'note' => !empty($d['ours']) ? 'הגדרה שלנו' : 'לפי הספר'];
    return $out;
}

/** המצב השמור של הבדיקה לאחור של הכרטיס (אותה גרסה ואותם פרמטרים) — או null */
function ts_bt_state(int $uid, TsMethod $m, array $p, string $asset, string $tf, string $market): ?array
{
    [$provider] = ts_asset_split($asset);
    [$in, $order, $sc] = ts_bt_scope_sql(ts_bt_read_scopes($uid, $provider));
    $meta = $m->meta();
    $st = ts_db()->prepare("SELECT state FROM backtests WHERE method_id = ? AND method_version = ? AND provider = ? AND asset = ? AND tf = ?
        AND params_hash = ? AND source = 'provider'$in$order LIMIT 1");
    $st->execute([$meta['id'], $meta['version'], $provider, $asset, $tf, ts_bt_hash($p, ts_cost_for($market)), ...$sc]);
    $raw = $st->fetchColumn();
    return $raw ? json_decode($raw, true) : null;
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
function ts_max_horizon(TsMethod $m, array $p, string $asset, array $scopes): ?array
{
    [$in, , $sc] = ts_bt_scope_sql($scopes);
    $st = ts_db()->prepare("SELECT tf, n FROM backtests WHERE method_id = ? AND asset = ? AND method_version = ? AND n >= ? AND source = 'provider'$in");
    $st->execute([$m->meta()['id'], $asset, $m->meta()['version'], TS_MIN_SIGNALS, ...$sc]);
    $best = null;
    foreach ($st->fetchAll() as $r) if (isset(TS_TF_SECONDS[$r['tf']]) && ($best === null || TS_TF_SECONDS[$r['tf']] > TS_TF_SECONDS[$best])) $best = $r['tf'];
    return $best ? ['tf' => $best, 'text' => ts_horizon_text($m->horizon($p), $best)] : null;
}

/**
 * כרטיסי ניתוח לנכס ומסגרת, לכל שיטה שנבחרה, ושקלול (6.5). כל שיטה — בדיקה לאחור (או השמורה) וניתוח של המצב
 * אחרי הנר האחרון שנסגר. רק מתוצאות שהשרת חישב מנתוני הספק (source = 'provider').
 */
function ts_analysis(int $uid, mixed $asset, mixed $tf, mixed $methods, ?int $now = null): array
{
    ts_asset_split($asset);
    if (!is_string($tf) || !isset(TS_TF_SECONDS[$tf])) throw new TsInvalid('מסגרת זמן לא תקינה');
    $ids = is_string($methods) ? array_values(array_filter(explode(',', $methods))) : [];
    if (!$ids) throw new TsInvalid('בחרו שיטה אחת לפחות');
    if (count($ids) > 10) throw new TsInvalid('עד 10 שיטות בניתוח אחד');
    $prepared = [];
    foreach (array_unique($ids) as $id) $prepared[$id] = ts_method_for($uid, $id);
    return ts_analysis_cards($uid, $asset, $tf, $prepared, $now ?? ts_now(), true);
}

/**
 * הכרטיסים והשקלול לשיטות שכבר נטענו ([id => [m, set, p]]). $runBt — האם להריץ בדיקה לאחור כשאין שמורה עדכנית.
 * **בגרף (chart_refresh, S4 18.4) — false:** הרענון לא מריץ בדיקה לאחור (18.3), והכרטיס מציג את השמורה, גם אם עברה יום.
 * הניתוח עצמו (התנאים עכשיו) נשמר לפי מפתח החישוב — אותו נר סגור אחרון ואותה בדיקה לאחור → לא רץ שוב (2ה.4).
 */
function ts_analysis_cards(int $uid, string $asset, string $tf, array $prepared, int $now, bool $runBt): array
{
    [$provider, $symbol] = ts_asset_split($asset);
    // ספק משותף בלי דרך אליו (21.3) — הניתוח רץ על מה שבמאגר המשותף
    $acc = ts_access($uid, $provider) ?? (ts_pool_only($uid, $provider) ? ['source' => 'pool', 'scope' => '']
        : throw new TsInvalid('אין לכם חיבור לספק הזה. מחברים ב"עוד ← ספקי נתונים".'));
    $profile = ts_profile($uid);
    $market = ts_asset_market($asset);
    $sec = TS_TF_SECONDS[$tf];

    // ניתוח המצב עכשיו — חלון של נרות אחרונים מהמאגר (פנייה לספק רק על החסר)
    $warm = 300;
    foreach ($prepared as [$m, , $p]) $warm = max($warm, (int)($p['warmup_bars'] ?? $m->meta()['warmup'] ?? 0) + $m->horizon($p) + 10);
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
            'sample' => !empty($meta['sample']), 'label' => ts_method_label($m)],
            'tf' => $tf, 'horizon_bars' => $m->horizon($p), 'horizon_text' => ts_horizon_text($m->horizon($p), $tf)];
        if (!in_array($tf, $set['timeframes'], true) || !in_array($market, $set['markets'], true)) {
            $cards[] = $card + ['status' => 'unsupported', 'message' => 'השיטה לא מוגדרת לשוק או למסגרת הזמן הזו', 'bias' => 'none', 'certainty' => null];
            continue;
        }
        $p = ts_params_for_asset($m, $p, $dec);
        // הבדיקה לאחור קודם — הכרטיס של שיטה עם מצב ארוך ממשיך מהמצב שלה (ו1)
        $bt = $runBt ? ts_backtest($uid, $m, $p, $asset, $tf, $market, $now) : ts_backtest_stored($uid, $m, $p, $asset, $tf, $market, $now);
        [$a, $basis] = ts_card_analyze($m, $p, $asset, $tf, (string)$acc['scope'], $rows, $fl, $bt,
            fn() => !empty($meta['analyze_from_state']) ? ts_bt_state($uid, $m, $p, $asset, $tf, $market) : null, $now);
        $lv = $a['levels'];
        $levels = $lv ? ['entry' => ts_level_str($lv['entry'], $dec), 'stop' => ts_level_str($lv['stop'], $dec), 'target' => ts_level_str($lv['target'], $dec)] : null;
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
        // met: null — "בדוק בעצמך" (תנאי שהמחשב לא בודק, 17.1.9 ז); לא נהפך ל-false
        $cards[] = $card + ['status' => 'ok', 'bias' => $a['bias'], 'conditions' => array_map(fn($c) => ['id' => $c['id'], 'text' => $c['text'],
            'side' => $c['side'], 'met' => $c['met'] === null ? null : (bool)$c['met']], $a['conditions']), 'signal_ago' => $a['signal_ago'] ?? null,
            // מידע בלבד (פיבונאצ'י S25): לא תנאי — לא נספר, ולא "בדוק בעצמך"
            'info' => array_map(fn($x) => ['id' => $x['id'], 'text' => $x['text']], $a['info'] ?? []),
            'basis' => $basis, 'param_notes' => ts_param_notes($m),
            'levels' => $levels, 'as_of' => $a['as_of'], 'backtest' => $bt, 'certainty' => $cert,
            'below_threshold' => $cert !== null && $cert < $profile['min_certainty'], 'risk' => $risk,
            'max_horizon' => ts_max_horizon($m, $p, $asset, ts_bt_read_scopes($uid, $provider))];
    }
    $blend = ts_blend(array_map(fn($c) => ['id' => $c['method']['id'], 'bias' => $c['bias'],
        'certainty' => $c['certainty']], $cards), $profile['preferred_methods']);
    return ['asset' => "$provider:$symbol", 'symbol' => $symbol, 'provider' => $provider, 'tf' => $tf, 'market' => $market,
        'decimals' => $dec, 'last_t' => $rows ? $rows[count($rows) - 1]['t'] : null, 'cards' => $cards, 'blend' => $blend,
        'profile' => ['min_certainty' => $profile['min_certainty'], 'min_rr' => $profile['min_rr'], 'risk_pct' => $profile['risk_pct']],
        'account_size' => $account, 'disclaimer' => TS_ANALYSIS_DISCLAIMER, 'provider_calls' => $calls] + ts_pair_pick(ts_pair_label("$provider:$symbol"));
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
