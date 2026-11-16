<?php
/**
 * מילוי איטי של המאגר ברקע (spec-stage-a 21.2, ✅30). שלב ב-cron הקיים (bin/alerts-cron.php, כל 15 דקות), אחרי
 * ההתראות ויומן השיטה — כך שהם לא נדחים בגללו.
 *
 * - **מה:** הנר היומי (d1) של כל נכס בלשוניות המובנות ובמועדפים — בקשה אחת לנכס. ממנו המחיר והשינוי היומי בשורה
 *   (ts_cached_quote), כך שלשונית שנפתחת לא נשארת עם "—".
 * - **מי משלם:** לא התקרה של 30 לדקה למשתמש — ה-cron אינו משתמש. רק התקרה של הספק, ובחלק ממנה בלבד:
 *   [שלנו] עד **חצי** מהתקרה לדקה בכל ריצה, ועד **25%** מהתקרה היומית — לכל מפתח. השאר פנוי לבקשות של משתמשים.
 * - **רישוי (ש10, ברירת המחדל):** Binance ו-ECB — מילוי משותף (cred_scope ''). Twelve Data — רק במפתח **של המשתמש**,
 *   לנכסים שלו, ונשמר ב-u:<id>: לעולם לא במפתח של המנהל ולא לאחרים. המשתמש יכול לכבות (users.prefill_off).
 * - **הסדר:** מועדפים (לפי מספר המשתמשים שסימנו), אחר כך נפתחו ב-7 הימים האחרונים (fetch_log), אחר כך השאר לפי סדר
 *   הקטלוג. נכס שנכשל בפעם האחרונה — לסוף התור.
 * - **בלי ניסיון חוזר:** 429 או שגיאה — הנכס לסוף התור, והמפתח הזה לא מקבל עוד פנייה בריצה הזו.
 *
 * המצב — prefill_state ב-data/app.sqlite (מיגרציה 15). נכס שהמאגר שלו נמחק (ניקוי, "נקה מאגר") — נחשב לא מולא.
 */

declare(strict_types=1);

require_once __DIR__ . '/market.php';
require_once __DIR__ . '/tabs.php';

/** כל כמה שניות לנכס, לפי ספק. ספק שאינו כאן — לא ממולא. needs_key → רק במפתח של המשתמש (ש10) */
const TS_PREFILL_EVERY = [
    'binance' => 0,              // כל ריצה — בלי מפתח, משותף, תקרה גבוהה
    'ecb' => 86400,              // ECB מפרסם שער אחד ליום
    'twelvedata' => 3 * 86400,   // "אחת לכמה ימים" — ~620 נכסים ÷ 3 ≈ 207 ליום, בתוך 25% מ-800
];
const TS_PREFILL_TF = 'd1';
const TS_PREFILL_RUN_SHARE = 2;    // [שלנו] חצי מהתקרה לדקה בכל ריצה
const TS_PREFILL_DAY_SHARE = 4;    // [שלנו] רבע מהתקרה היומית
const TS_PREFILL_LOG_MAX = 60;     // כמה נכסים נרשמים בסיכום הריצה

// ── ההגדרה של המשתמש ─────────────────────────────────────

function ts_prefill_on(int $uid): bool
{
    $st = ts_db()->prepare('SELECT prefill_off FROM users WHERE id = ?');
    $st->execute([$uid]);
    return (int)$st->fetchColumn() === 0;
}

function ts_prefill_set(int $uid, mixed $on): void
{
    if (!is_bool($on)) throw new TsInvalid('ערך לא תקין');
    ts_db()->prepare('UPDATE users SET prefill_off = ? WHERE id = ?')->execute([$on ? 0 : 1, $uid]);
}

// ── התור ─────────────────────────────────────────────────

/** "binance:1/X" → "binance:X" — זוג מחושב נמשך ונשמר במאגר כמו הכיוון המקורי */
function ts_prefill_base(string $asset): string
{
    [$p, $s] = explode(':', $asset, 2);
    return str_starts_with($s, '1/') ? "$p:" . substr($s, 2) : $asset;
}

/** כמה משתמשים סימנו כל נכס במועדפים: asset → n (מהגבוה לנמוך) */
function ts_prefill_favorites(): array
{
    $out = [];
    foreach (ts_db()->query('SELECT asset, COUNT(DISTINCT user_id) AS n FROM watchlist GROUP BY asset ORDER BY n DESC, asset') as $r) {
        $out[ts_prefill_base((string)$r['asset'])] = ($out[ts_prefill_base((string)$r['asset'])] ?? 0) + (int)$r['n'];
    }
    arsort($out);
    return $out;
}

/** נכסים שנפתחו בגרף ב-7 הימים האחרונים (fetch_log; user_id 0 = ה-cron עצמו, לא נספר). $uid — רק שלו */
function ts_prefill_recent(int $now, ?int $uid = null): array
{
    try {
        $st = ts_cache()->prepare('SELECT provider, symbol, MAX(at) AS at FROM fetch_log WHERE at >= ? AND user_id ' . ($uid === null ? '> 0' : '= ?')
            . ' GROUP BY provider, symbol ORDER BY at DESC');
        $st->execute($uid === null ? [$now - 7 * 86400] : [$now - 7 * 86400, $uid]);
        return array_map(fn($r) => "$r[provider]:$r[symbol]", $st->fetchAll());
    } catch (Throwable) {
        return [];
    }
}

/**
 * התור של מפתח אחד, לפי הסדר של 21.2: [asset → prio]. $pool — "שאר" הנכסים בסדר הקטלוג; $mine — המועדפים של המשתמש
 * (null = של כולם); הכול מסונן לספק.
 */
function ts_prefill_order(string $provider, array $fav, array $recent, array $pool, ?array $mine = null): array
{
    $of = fn($a) => explode(':', $a, 2)[0] === $provider;
    $q = [];
    foreach ($fav as $a => $n) if ($of($a) && ($mine === null || isset($mine[$a]))) $q[$a] ??= 1;
    foreach ($recent as $a) if ($of($a)) $q[$a] ??= 2;
    foreach ($pool as $a) if ($of($a)) $q[$a] ??= 3;
    return $q;
}

/** הנכסים של הלשוניות המובנות כפי ש-$uid רואה אותן (0 = בלי מפתחות — רק מה שפתוח לכולם), ושל הלשוניות שלו */
function ts_prefill_pool(int $uid, bool $ownTabs): array
{
    $tabs = $ownTabs ? ts_market_tabs($uid) : ts_tabs_default();
    $out = [];
    foreach ($tabs as $t) {
        $items = match ($t['kind']) {
            'builtin' => ts_tab_builtin_assets($uid, $t['id']),
            'custom' => array_map(fn($a) => ['asset' => $a], $t['assets']),
            default => [],
        };
        foreach ($items as $it) if ($it['asset'] !== null) $out[ts_prefill_base($it['asset'])] = true;
    }
    return array_keys($out);
}

/** המפתחות שממלאים בהם: [{provider, uid, scope, acc, queue}] */
function ts_prefill_keys(int $now): array
{
    $fav = ts_prefill_favorites();
    $recent = array_map('ts_prefill_base', ts_prefill_recent($now));
    $keys = [];
    $shared = null;
    foreach (TS_PREFILL_EVERY as $provider => $_) {
        if (!isset(TS_PROVIDERS[$provider])) continue;
        $meta = ts_provider($provider)->meta();
        if ($meta['needs_key']) {
            // ש10: רק מי שחיבר מפתח משלו ולא כיבה — המועדפים, הפתיחות והלשוניות שלו
            $st = ts_db()->prepare("SELECT u.id FROM provider_creds c JOIN users u ON u.id = c.user_id
                                    WHERE c.owner = 'user' AND c.provider_id = ? AND u.prefill_off = 0 ORDER BY u.id");
            $st->execute([$provider]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $uid = (int)$uid;
                $acc = ts_access($uid, $provider);
                if (!$acc || $acc['source'] !== 'own') continue;
                $mine = array_fill_keys(array_map(fn($w) => ts_prefill_base($w['asset']), ts_watchlist($uid)), true);
                $keys[] = ['provider' => $provider, 'uid' => $uid, 'scope' => $acc['scope'], 'acc' => $acc,
                    'queue' => ts_prefill_order($provider, $fav, array_map('ts_prefill_base', ts_prefill_recent($now, $uid)), ts_prefill_pool($uid, true), $mine)];
            }
            continue;
        }
        $acc = ts_access(0, $provider);   // בלי משתמש — רק מה שפתוח לכולם
        if (!$acc || $acc['scope'] !== '') continue;
        $shared ??= ts_prefill_pool(0, false);
        $keys[] = ['provider' => $provider, 'uid' => 0, 'scope' => '', 'acc' => $acc, 'queue' => ts_prefill_order($provider, $fav, $recent, $shared)];
    }
    return $keys;
}

/** האם יש במאגר נר יומי לנכס בהיקף הזה (אחרי ניקוי — אין, והמצב השמור לא קובע) */
function ts_prefill_cached(string $asset, string $scope): bool
{
    [$p, $s] = explode(':', $asset, 2);
    $st = ts_cache()->prepare('SELECT 1 FROM candles WHERE provider = ? AND symbol = ? AND tf = ? AND cred_scope = ? LIMIT 1');
    $st->execute([$p, $s, TS_PREFILL_TF, $scope]);
    return (bool)$st->fetchColumn();
}

/** מה שעוד צריך מילוי, בסדר: לפי prio, ונכס שנכשל בפעם האחרונה — לסוף */
function ts_prefill_due(array $key, int $now): array
{
    $st = ts_db()->prepare('SELECT symbol, filled_at, failed_at, empty FROM prefill_state WHERE provider = ? AND cred_scope = ?');
    $st->execute([$key['provider'], $key['scope']]);
    $state = [];
    foreach ($st->fetchAll() as $r) $state[$key['provider'] . ':' . $r['symbol']] = [(int)$r['filled_at'], (int)$r['failed_at'], (bool)$r['empty']];
    $every = TS_PREFILL_EVERY[$key['provider']];
    $ok = []; $failed = [];
    foreach ($key['queue'] as $asset => $prio) {
        [$filled, $fail, $empty] = $state[$asset] ?? [0, 0, false];
        if ($filled > 0 && $filled + $every > $now && ($empty || ts_prefill_cached($asset, $key['scope']))) continue;
        if ($fail > $filled) $failed[] = $asset; else $ok[] = $asset;
    }
    return [...$ok, ...$failed];
}

// ── התקרות ───────────────────────────────────────────────

function ts_prefill_day_used(array $acc, int $now): int
{
    $st = ts_db()->prepare('SELECT calls FROM provider_usage WHERE key = ? AND user_id = 0 AND day = ?');
    $st->execute(['prefill:' . $acc['usage'], gmdate('Y-m-d', $now)]);
    return (int)$st->fetchColumn();
}

function ts_prefill_day_add(array $acc, int $now, int $n): void
{
    if ($n <= 0) return;
    ts_db()->prepare('INSERT INTO provider_usage (key, user_id, day, calls) VALUES (?, 0, ?, ?)
                      ON CONFLICT(key, user_id, day) DO UPDATE SET calls = calls + excluded.calls')
        ->execute(['prefill:' . $acc['usage'], gmdate('Y-m-d', $now), $n]);
}

/**
 * ריצה אחת של המילוי. נקרא מ-ts_alerts_run, בתוך הנעילה. $used — פניות לספק שכבר נעשו בריצה (התראות, יומן), ומתעדכן;
 * $budget — דריסה של התקרה לדקה (--budget, לבדיקות); $t0 — תחילת הריצה (תקרת הזמן המשותפת, 4 דקות).
 */
function ts_prefill_run(int $now, array &$used, ?array $budget, float $t0): array
{
    $sum = ['filled' => 0, 'errors' => 0, 'deferred' => 0, 'stopped' => false, 'day_capped' => [], 'calls' => [], 'done' => []];
    $db = ts_db();
    // empty — הספק ענה בלי נרות (סימול שאין לו נתונים): לא פונים שוב לפני הזמן, גם כשאין מה להראות במאגר
    $mark = $db->prepare('INSERT INTO prefill_state (provider, symbol, cred_scope, filled_at, failed_at, empty) VALUES (?, ?, ?, ?, 0, ?)
                          ON CONFLICT(provider, symbol, cred_scope) DO UPDATE SET filled_at = excluded.filled_at, empty = excluded.empty');
    $fail = $db->prepare('INSERT INTO prefill_state (provider, symbol, cred_scope, filled_at, failed_at) VALUES (?, ?, ?, 0, ?)
                          ON CONFLICT(provider, symbol, cred_scope) DO UPDATE SET failed_at = excluded.failed_at');
    ts_fetch_as_system(true);   // ביומן בקשות הנרות — ה-cron, לא המשתמש (וגם לא "נפתח לאחרונה")
    try {
        foreach (ts_prefill_keys($now) as $key) {
            $provider = $key['provider'];
            $lim = ts_provider_limits($provider);
            $perMin = $budget[$provider] ?? $lim['per_min'];
            $run = intdiv($perMin, TS_PREFILL_RUN_SHARE);
            // משותף — גם מה שההתראות כבר לקחו מהספק בריצה הזו
            if ($key['scope'] === '') $run = min($run, $perMin - ($used[$provider] ?? 0));
            $day = intdiv($lim['per_day'], TS_PREFILL_DAY_SHARE) - ts_prefill_day_used($key['acc'], $now);
            $due = ts_prefill_due($key, $now);
            $took = 0;
            foreach ($due as $i => $asset) {
                $label = $key['scope'] === '' ? $provider : "$provider|" . ts_scope_kind($key['scope']);
                if (microtime(true) - $t0 > TS_CRON_MAX_SECONDS) { $sum['stopped'] = true; $sum['deferred'] += count($due) - $i; break; }
                if ($took >= $day) { $sum['day_capped'][] = $label; $sum['deferred'] += count($due) - $i; break; }
                if ($took >= $run) { $sum['deferred'] += count($due) - $i; break; }
                [, $sym] = explode(':', $asset, 2);
                try {
                    $r = ts_market_candles($key['uid'], $asset, TS_PREFILL_TF, $now - 3 * 86400, $now, $now);
                    $n = (int)$r['provider_calls'];
                    $mark->execute([$provider, $sym, $key['scope'], $now, $r['candles'] ? 0 : 1]);
                    $sum['filled']++;
                    if (count($sum['done']) < TS_PREFILL_LOG_MAX) $sum['done'][] = $asset;
                } catch (Throwable $e) {
                    // 429 או שגיאה: לסוף התור, ובלי עוד פנייה במפתח הזה בריצה הזו (בלי ניסיון חוזר בלולאה)
                    $n = 1;
                    $fail->execute([$provider, $sym, $key['scope'], $now]);
                    $sum['errors']++;
                    $sum['deferred'] += count($due) - $i - 1;
                    error_log('trading-strategies prefill ' . $asset . ': ' . $e->getMessage());
                    $took += $n;
                    ts_prefill_day_add($key['acc'], $now, $n);
                    $used[$provider] = ($used[$provider] ?? 0) + $n;
                    $sum['calls'][$label] = ($sum['calls'][$label] ?? 0) + $n;
                    break;
                }
                $took += $n;
                ts_prefill_day_add($key['acc'], $now, $n);
                $used[$provider] = ($used[$provider] ?? 0) + $n;
                if ($n) $sum['calls'][$label] = ($sum['calls'][$label] ?? 0) + $n;
            }
        }
    } finally {
        ts_fetch_as_system(false);
    }
    $sum['day_capped'] = array_values(array_unique($sum['day_capped']));
    return $sum;
}
