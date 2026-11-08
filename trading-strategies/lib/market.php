<?php
/**
 * שכבת השוק (spec-stage-a 2–3): מי מגיע לאיזה ספק ובאיזה מפתח, המאגר, החיפוש, המעקב והתקרות.
 *
 * הכלל: הדפדפן לא פונה לספק (חוץ מ-WebSocket ציבורי של Binance). כל בקשת נתונים — אחת, לשרת שלנו, שעונה מהמאגר
 * ופונה לספק רק על החסר. אין רענון אוטומטי (✅13): הנר הפתוח מתחדש רק כשמשתמש לוחץ "רענן", ורק אם עבר התוקף.
 *
 * דרך חיבור לכל (משתמש, ספק) — ts_access():
 *   1. ספק משלי (א) — המפתח של המשתמש; המאגר 'u:<id>' — רק שלו.
 *   2. ספק מהמנהל (ב) — רק כשהמתג הראשי דלוק (✅2, כבוי בשלב א'); המאגר 'c:<cred>' — משותף למי שקיבל.
 *   3. ספק בלי מפתח (Binance, ECB) — לכולם; המאגר '' — משותף, כי התנאים מתירים (meta share).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/secret.php';
require_once __DIR__ . '/providers/registry.php';

const TS_POOL_DAYS = 60;            // ✅4 — המאגר המשותף שומר 60 יום
const TS_MAX_CANDLES = 5000;        // לבקשה אחת של גרף
const TS_WATCHLIST_MAX = 100;       // spec-stage-a 2
const TS_SEARCH_TTL = 86400;        // 24 שעות לתוצאות חיפוש
const TS_MAX_SEGMENTS = 4;          // לכל היותר כמה פניות לספק בבקשת גרף אחת

// ── הגדרות ספק (מנהל) ─────────────────────────────────────

function ts_provider_settings(string $id): array
{
    $st = ts_db()->prepare('SELECT enabled, overrides FROM providers WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return ['enabled' => $row ? (bool)$row['enabled'] : true, 'overrides' => $row ? (json_decode($row['overrides'], true) ?: []) : []];
}

function ts_provider_limits(string $id): array
{
    $meta = ts_provider($id)->meta()['limits'];
    $o = ts_provider_settings($id)['overrides'];
    foreach (['per_min', 'per_day'] as $k) if (isset($o[$k]) && is_int($o[$k]) && $o[$k] > 0) $meta[$k] = $o[$k];
    return $meta;
}

function ts_shared_enabled(): bool
{
    return ts_setting('shared_providers', '0') === '1';
}

// ── גישה לספק ─────────────────────────────────────────────

/**
 * איך המשתמש מגיע לספק: [source own|shared|public, scope, secret|null, usage key, daily limit|null] — או null.
 */
function ts_access(int $uid, string $provider): ?array
{
    if (!isset(TS_PROVIDERS[$provider]) || !ts_provider_settings($provider)['enabled']) return null;
    $meta = ts_provider($provider)->meta();
    $db = ts_db();

    $st = $db->prepare("SELECT id, secret FROM provider_creds WHERE owner = 'user' AND user_id = ? AND provider_id = ?");
    $st->execute([$uid, $provider]);
    if ($c = $st->fetch()) {
        return ['source' => 'own', 'scope' => "u:$uid", 'secret' => ts_decrypt($c['secret']), 'usage' => 'cred:' . $c['id'],
            'user_limit' => null, 'cred_id' => (int)$c['id']];
    }
    if (ts_shared_enabled()) {
        $st = $db->prepare("SELECT c.id, c.secret, g.daily_limit FROM provider_grants g JOIN provider_creds c ON c.id = g.cred_id
                            WHERE g.user_id = ? AND c.owner = 'admin' AND c.provider_id = ? ORDER BY c.id LIMIT 1");
        $st->execute([$uid, $provider]);
        if ($c = $st->fetch()) {
            return ['source' => 'shared', 'scope' => 'c:' . $c['id'], 'secret' => ts_decrypt($c['secret']), 'usage' => 'cred:' . $c['id'],
                'user_limit' => (int)$c['daily_limit'], 'cred_id' => (int)$c['id']];
        }
    }
    if (!$meta['needs_key']) {
        return ['source' => 'public', 'scope' => $meta['share'] ? '' : "u:$uid", 'secret' => null, 'usage' => "provider:$provider",
            'user_limit' => null, 'cred_id' => null];
    }
    return null;
}

/** כמה פניות נשארו היום לספק (ולמשתמש, בספק מהמנהל) — לתקציב של הבדיקה לאחור (6.2 ה) */
function ts_usage_left(int $uid, string $provider, array $acc): int
{
    $day = gmdate('Y-m-d');
    $get = ts_db()->prepare('SELECT calls FROM provider_usage WHERE key = ? AND user_id = ? AND day = ?');
    $get->execute([$acc['usage'], 0, $day]);
    $left = ts_provider_limits($provider)['per_day'] - (int)$get->fetchColumn();
    if ($acc['user_limit'] !== null) {
        $get->execute([$acc['usage'], $uid, $day]);
        $left = min($left, $acc['user_limit'] - (int)$get->fetchColumn());
    }
    return max(0, $left);
}

/**
 * סופר פנייה לספק ($n פניות — משיכה ארוכה שהמתאם מחלק לכמה), ועוצר לפני התקרה היומית של הספק (או של המשתמש
 * בספק מהמנהל)
 */
function ts_usage_take(int $uid, string $provider, array $acc, int $n = 1): void
{
    $db = ts_db();
    $day = gmdate('Y-m-d');
    $get = $db->prepare('SELECT calls FROM provider_usage WHERE key = ? AND user_id = ? AND day = ?');
    $get->execute([$acc['usage'], 0, $day]);
    if ((int)$get->fetchColumn() + $n > ts_provider_limits($provider)['per_day']) {
        throw new TsProviderError(ts_provider($provider)->meta()['name'] . ': הגענו לתקרה היומית של הספק. נסו שוב מחר.');
    }
    if ($acc['user_limit'] !== null) {
        $get->execute([$acc['usage'], $uid, $day]);
        if ((int)$get->fetchColumn() + $n > $acc['user_limit']) throw new TsProviderError('הגעתם לתקרה היומית שהמנהל קבע לספק הזה.');
    }
    $add = $db->prepare('INSERT INTO provider_usage (key, user_id, day, calls) VALUES (?, ?, ?, ?)
                         ON CONFLICT(key, user_id, day) DO UPDATE SET calls = calls + excluded.calls');
    $add->execute([$acc['usage'], 0, $day, $n]);
    if ($acc['user_limit'] !== null) $add->execute([$acc['usage'], $uid, $day, $n]);
}

/** מריץ פנייה לספק; כל שגיאה יוצאת נקייה מהמפתח */
function ts_provider_call(int $uid, string $provider, array $acc, callable $fn, int $n = 1): mixed
{
    ts_usage_take($uid, $provider, $acc, $n);
    try {
        return $fn(ts_provider($provider), $acc['secret']);
    } catch (TsProviderError $e) {
        throw new TsProviderError($acc['secret'] !== null ? ts_scrub($e->getMessage(), [$acc['secret']]) : $e->getMessage());
    } catch (Throwable $e) {
        error_log('trading-strategies provider ' . $provider . ': ' . ($acc['secret'] !== null ? ts_scrub($e->getMessage(), [$acc['secret']]) : $e->getMessage()));
        throw new TsProviderError(ts_provider($provider)->meta()['name'] . ': תקלה בפנייה לספק');
    }
}

/** "binance:BTCUSDT" → ['binance', 'BTCUSDT']. סימול יכול להכיל ':' (Finnhub: OANDA:EUR_USD) */
function ts_asset_split(mixed $asset): array
{
    if (!is_string($asset) || !preg_match('/^([a-z0-9]{2,20}):([A-Za-z0-9._:\/ -]{1,40})$/', $asset, $m) || !isset(TS_PROVIDERS[$m[1]])) {
        throw new TsInvalid('נכס לא תקין');
    }
    return [$m[1], $m[2]];
}

function ts_norm_symbol(string $symbol): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)preg_replace('/^[A-Z ]+:/', '', $symbol)));
}

// ── ספקים למשתמש ─────────────────────────────────────────

/** הספקים כפי שהמשתמש רואה אותם: זמינים, מחוברים, ומה צריך כדי לחבר */
function ts_providers_for(int $uid): array
{
    $out = [];
    foreach (array_keys(TS_PROVIDERS) as $id) {
        $p = ts_provider($id);
        $m = $p->meta();
        $settings = ts_provider_settings($id);
        $acc = $settings['enabled'] ? ts_access($uid, $id) : null;
        $st = ts_db()->prepare("SELECT last4, label, last_test_at, last_test_ok FROM provider_creds WHERE owner = 'user' AND user_id = ? AND provider_id = ?");
        $st->execute([$uid, $id]);
        $own = $st->fetch() ?: null;
        $out[] = [
            'id' => $id, 'name' => $m['name'], 'markets' => $m['markets'], 'tfs' => array_keys($m['tfs']),
            'needs_key' => $m['needs_key'], 'key_help' => $m['key_help'], 'delay' => $m['delay'], 'terms' => $m['terms'],
            'bounds' => $m['bounds'], 'enabled' => $settings['enabled'],
            'available' => $acc !== null, 'source' => $acc['source'] ?? null,
            'stream' => $acc && $acc['source'] === 'public' ? $p->stream() : null,
            'own' => $own ? ['last4' => $own['last4'], 'label' => $own['label'], 'last_test_at' => $own['last_test_at'] !== null ? (int)$own['last_test_at'] : null,
                'last_test_ok' => $own['last_test_ok'] !== null ? (bool)$own['last_test_ok'] : null] : null,
        ];
    }
    return $out;
}

// ── מפתחות: ספק משלי (א) ──────────────────────────────────

function ts_secret_in(mixed $secret): string
{
    if (!is_string($secret)) throw new TsInvalid('חסר מפתח');
    $s = trim($secret);
    if (strlen($s) < 8 || strlen($s) > 200 || !preg_match('/^[\x21-\x7E]+$/', $s)) throw new TsInvalid('המפתח לא נראה תקין (8–200 תווים, בלי רווחים)');
    return $s;
}

/** שמירת מפתח: נבדק מול הספק קודם; נכשל — לא נשמר, וההודעה בלי המפתח */
function ts_cred_save(int $uid, mixed $provider, mixed $secret, mixed $label, string $owner = 'user'): int
{
    if (!is_string($provider) || !isset(TS_PROVIDERS[$provider])) throw new TsInvalid('ספק לא מוכר');
    $p = ts_provider($provider);
    if (!$p->meta()['needs_key']) throw new TsInvalid('לספק הזה לא צריך מפתח');
    $s = ts_secret_in($secret);
    try {
        $p->test($s);
    } catch (TsProviderError $e) {
        throw new TsInvalid(ts_scrub($e->getMessage(), [$s]));
    } catch (Throwable $e) {
        error_log('trading-strategies cred test: ' . ts_scrub($e->getMessage(), [$s]));
        throw new TsInvalid($p->meta()['name'] . ': בדיקת החיבור נכשלה');
    }
    $db = ts_db();
    $label = mb_substr(trim(is_string($label) ? $label : ''), 0, 60);
    $now = time();
    if ($owner === 'user') {
        $db->prepare("DELETE FROM provider_creds WHERE owner = 'user' AND user_id = ? AND provider_id = ?")->execute([$uid, $provider]);
        $db->prepare("INSERT INTO provider_creds (owner, user_id, provider_id, secret, last4, label, created_at, last_test_at, last_test_ok)
                      VALUES ('user', ?, ?, ?, ?, ?, ?, ?, 1)")->execute([$uid, $provider, ts_encrypt($s), ts_last4($s), $label, $now, $now]);
    } else {
        $db->prepare("INSERT INTO provider_creds (owner, user_id, provider_id, secret, last4, label, created_at, last_test_at, last_test_ok)
                      VALUES ('admin', NULL, ?, ?, ?, ?, ?, ?, 1)")->execute([$provider, ts_encrypt($s), ts_last4($s), $label, $now, $now]);
    }
    return (int)$db->lastInsertId();
}

/** "בדוק חיבור" למפתח שמור */
function ts_cred_test_row(array $row): bool
{
    $p = ts_provider($row['provider_id']);
    $s = ts_decrypt($row['secret']);
    $ok = true; $msg = '';
    try {
        $p->test($s);
    } catch (Throwable $e) {
        $ok = false;
        $msg = $e instanceof TsProviderError ? ts_scrub($e->getMessage(), [$s]) : $p->meta()['name'] . ': בדיקת החיבור נכשלה';
    }
    ts_db()->prepare('UPDATE provider_creds SET last_test_at = ?, last_test_ok = ? WHERE id = ?')->execute([time(), $ok ? 1 : 0, $row['id']]);
    if (!$ok) throw new TsInvalid($msg);
    return true;
}

function ts_cred_test_mine(int $uid, mixed $provider): bool
{
    $st = ts_db()->prepare("SELECT * FROM provider_creds WHERE owner = 'user' AND user_id = ? AND provider_id = ?");
    $st->execute([$uid, (string)$provider]);
    $row = $st->fetch() ?: throw new TsNotFound('אין מפתח שמור לספק הזה');
    return ts_cred_test_row($row);
}

/** מחיקת מפתח — וגם כל מה שנמשך בו (המאגר האישי של אותו ספק) */
function ts_cred_delete_mine(int $uid, mixed $provider): void
{
    $st = ts_db()->prepare("DELETE FROM provider_creds WHERE owner = 'user' AND user_id = ? AND provider_id = ?");
    $st->execute([$uid, (string)$provider]);
    if (!$st->rowCount()) throw new TsNotFound('אין מפתח שמור לספק הזה');
    ts_cache_drop_scope((string)$provider, "u:$uid");
}

function ts_cache_drop_scope(string $provider, string $scope): void
{
    $c = ts_cache();
    foreach (['candles', 'coverage', 'tails', 'search'] as $t) {
        $c->prepare("DELETE FROM $t WHERE provider = ? AND cred_scope = ?")->execute([$provider, $scope]);
    }
}

// ── חיפוש ─────────────────────────────────────────────────

/**
 * חיפוש בכל הספקים שהמשתמש מגיע אליהם (2). לכל סימול מוצג ספק אחד — המועדף שלו, ואחריו לפי סדר הספקים —
 * ושאר הספקים כחלופות.
 */
function ts_market_search(int $uid, mixed $q): array
{
    $q = trim(is_string($q) ? $q : '');
    if (mb_strlen($q) < 1 || mb_strlen($q) > 40) throw new TsInvalid('חיפוש — 1 עד 40 תווים');
    $key = mb_strtolower($q);
    $cache = ts_cache();
    $rows = []; $errors = [];
    foreach (array_keys(TS_PROVIDERS) as $id) {
        $acc = ts_access($uid, $id);
        if (!$acc) continue;
        $st = $cache->prepare('SELECT result, fetched_at FROM search WHERE provider = ? AND cred_scope = ? AND q = ?');
        $st->execute([$id, $acc['scope'], $key]);
        $hit = $st->fetch();
        if ($hit && (int)$hit['fetched_at'] > time() - TS_SEARCH_TTL) {
            $found = json_decode($hit['result'], true) ?: [];
        } else {
            try {
                $found = ts_provider_call($uid, $id, $acc, fn($p, $s) => $p->search($q, $s));
            } catch (TsProviderError $e) {
                $errors[] = $e->getMessage();
                continue;
            }
            $cache->prepare('INSERT OR REPLACE INTO search (provider, cred_scope, q, result, fetched_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $acc['scope'], $key, json_encode($found), time()]);
        }
        $meta = $cache->prepare('INSERT OR REPLACE INTO asset_meta (asset, name, market, currency, exchange, tz, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($found as $r) {
            $asset = "$id:{$r['symbol']}";
            $meta->execute([$asset, $r['name'], $r['market'], $r['currency'], $r['exchange'], $r['tz'], time()]);
            $rows[] = $r + ['asset' => $asset, 'provider' => $id, 'norm' => ts_norm_symbol($r['symbol'])];
        }
    }
    $prefs = ts_db()->prepare('SELECT norm, provider_id FROM symbol_prefs WHERE user_id = ?');
    $prefs->execute([$uid]);
    $pref = array_column($prefs->fetchAll(), 'provider_id', 'norm');
    $order = array_flip(array_keys(TS_PROVIDERS));
    $groups = [];
    foreach ($rows as $r) $groups[$r['norm']][] = $r;
    $out = [];
    foreach ($groups as $norm => $list) {
        usort($list, fn($a, $b) => [($pref[$norm] ?? '') !== $a['provider'], $order[$a['provider']]]
            <=> [($pref[$norm] ?? '') !== $b['provider'], $order[$b['provider']]]);
        $first = $list[0];
        $out[] = $first + ['alternatives' => array_map(fn($r) => ['asset' => $r['asset'], 'provider' => $r['provider'], 'name' => $r['name']],
            array_slice($list, 1))];
    }
    return ['results' => array_slice($out, 0, 40), 'errors' => $errors];
}

function ts_symbol_pref_set(int $uid, mixed $asset): void
{
    [$provider, $symbol] = ts_asset_split($asset);
    ts_db()->prepare('INSERT INTO symbol_prefs (user_id, norm, provider_id) VALUES (?, ?, ?)
                      ON CONFLICT(user_id, norm) DO UPDATE SET provider_id = excluded.provider_id')
        ->execute([$uid, ts_norm_symbol($symbol), $provider]);
}

function ts_asset_meta(string $asset): ?array
{
    $st = ts_cache()->prepare('SELECT name, market, currency, exchange, tz FROM asset_meta WHERE asset = ?');
    $st->execute([$asset]);
    return $st->fetch() ?: null;
}

// ── נרות ומאגר ───────────────────────────────────────────

/** [from, to] פחות הטווחים המכוסים → רשימת קטעים חסרים */
function ts_range_subtract(int $from, int $to, array $covered): array
{
    usort($covered, fn($a, $b) => $a[0] <=> $b[0]);
    $out = [];
    $cur = $from;
    foreach ($covered as [$a, $b]) {
        if ($b < $cur) continue;
        if ($a > $to) break;
        if ($a > $cur) $out[] = [$cur, min($a - 1, $to)];
        $cur = max($cur, $b + 1);
        if ($cur > $to) break;
    }
    if ($cur <= $to) $out[] = [$cur, $to];
    return $out;
}

function ts_tail_ttl(string $tf): int
{
    return max(60, min(TS_TF_SECONDS[$tf], 300));   // נר של דקה — 60 שניות (3.5); מעל 5 דקות — 5 דקות
}

/**
 * הנרות של נכס במסגרת ובטווח (3.5–3.6). עונה מהמאגר ופונה לספק רק על החסר. טווח ישן מ-60 יום נמשך לבקשה
 * הזו בלבד ולא נשמר. $now — לבדיקות.
 */
function ts_market_candles(int $uid, mixed $asset, mixed $tf, mixed $from, mixed $to, ?int $now = null): array
{
    [$provider, $symbol] = ts_asset_split($asset);
    if (!is_string($tf) || !isset(TS_TF_SECONDS[$tf])) throw new TsInvalid('מסגרת זמן לא תקינה');
    $acc = ts_access($uid, $provider) ?? throw new TsInvalid('אין לכם חיבור לספק הזה. מחברים ב"עוד ← ספקי נתונים".');
    $p = ts_provider($provider);
    $meta = $p->meta();
    if (!isset($meta['tfs'][$tf])) throw new TsInvalid($meta['name'] . ' לא נותן את מסגרת הזמן הזו');

    $now ??= time();
    $sec = TS_TF_SECONDS[$tf];
    $to = min(is_numeric($to) ? (int)$to : $now, $now);
    $from = is_numeric($from) ? (int)$from : $to - 300 * $sec;
    $capped = false;
    if ($from < $to - TS_MAX_CANDLES * $sec) { $from = $to - TS_MAX_CANDLES * $sec; $capped = true; }
    if ($from > $to) throw new TsInvalid('טווח לא תקין');

    $poolStart = $now - TS_POOL_DAYS * 86400;
    $cutoff = $now - $sec;                      // נר שנפתח אחרי זה — עוד פתוח
    $scope = $acc['scope'];
    $cache = ts_cache();
    $out = [];
    $calls = 0;

    // היסטוריה ישנה מהמאגר — לבקשה הזו בלבד (3.6)
    if ($from < $poolStart) {
        $oldTo = min($to, $poolStart - 1);
        $out = ts_provider_call($uid, $provider, $acc, fn($pp, $s) => $pp->candles($symbol, $tf, $from, $oldTo, $s));
        $calls++;
    }

    $rf = max($from, $poolStart);
    if ($rf <= $to) {
        $key = [$provider, $symbol, $tf, $scope];
        $st = $cache->prepare('SELECT from_t, to_t FROM coverage WHERE provider = ? AND symbol = ? AND tf = ? AND cred_scope = ?');
        $st->execute($key);
        $covered = array_map(fn($r) => [(int)$r['from_t'], (int)$r['to_t']], $st->fetchAll());

        // הקצה (הנר הפתוח) נמשך לאחרונה ב-$tailAt. כל עוד בתוקף — כל מה שאחרי הנרות הסגורים של אז נחשב מכוסה,
        // אחרת גבול "נר סגור" שזז בכל שנייה היה משאיר חור קטן ושולח פנייה לספק בכל בקשה.
        $tail = $cache->prepare('SELECT fetched_at FROM tails WHERE provider = ? AND symbol = ? AND tf = ? AND cred_scope = ?');
        $tail->execute($key);
        $tailAt = (int)$tail->fetchColumn();
        if ($tailAt > $now - ts_tail_ttl($tf)) $covered[] = [$tailAt - $sec + 1, PHP_INT_MAX];
        $missing = ts_range_subtract($rf, $to, $covered);

        $ins = $cache->prepare('INSERT OR REPLACE INTO candles (provider, symbol, tf, t, o, h, l, c, v, fetched_at, cred_scope) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (array_slice($missing, -TS_MAX_SEGMENTS) as [$a, $b]) {
            $rows = ts_provider_call($uid, $provider, $acc, fn($pp, $s) => $pp->candles($symbol, $tf, $a, $b, $s));
            $calls++;
            $cache->beginTransaction();
            foreach ($rows as $r) {
                if ($r['t'] < $poolStart - $sec) continue;
                $ins->execute([$provider, $symbol, $tf, $r['t'], $r['o'], $r['h'], $r['l'], $r['c'], $r['v'], $now, $scope]);
            }
            if (min($b, $cutoff) >= $a) {
                $cache->prepare('INSERT INTO coverage (provider, symbol, tf, cred_scope, from_t, to_t) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([...$key, $a, min($b, $cutoff)]);
            }
            if ($b > $cutoff) {
                $cache->prepare('INSERT OR REPLACE INTO tails (provider, symbol, tf, cred_scope, fetched_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([...$key, $now]);
            }
            $cache->commit();
        }
        if ($calls) ts_coverage_merge($key);

        $st = $cache->prepare('SELECT t, o, h, l, c, v FROM candles WHERE provider = ? AND symbol = ? AND tf = ? AND cred_scope = ? AND t >= ? AND t <= ? ORDER BY t');
        $st->execute([...$key, $rf - ($sec >= 86400 ? 86400 : 0), $to]);
        foreach ($st->fetchAll() as $r) $out[] = ['t' => (int)$r['t'], 'o' => $r['o'], 'h' => $r['h'], 'l' => $r['l'], 'c' => $r['c'], 'v' => $r['v']];
    }

    $by = [];
    foreach ($out as $r) $by[$r['t']] = $r;
    ksort($by);
    ts_cache_cleanup_daily($now);
    return [
        'asset' => "$provider:$symbol", 'provider' => $provider, 'symbol' => $symbol, 'tf' => $tf,
        'candles' => array_values($by), 'capped' => $capped, 'provider_calls' => $calls,
        'meta' => ts_asset_meta("$provider:$symbol"), 'delay' => $meta['delay'], 'source' => $acc['source'],
        'stream' => $acc['source'] === 'public' ? $p->stream() : null, 'stream_interval' => $meta['tfs'][$tf],
        'tail_ttl' => ts_tail_ttl($tf),
    ];
}

/** איחוד טווחים חופפים או צמודים של מפתח אחד — כדי שהטבלה לא תתנפח */
function ts_coverage_merge(array $key): void
{
    $c = ts_cache();
    $st = $c->prepare('SELECT from_t, to_t FROM coverage WHERE provider = ? AND symbol = ? AND tf = ? AND cred_scope = ? ORDER BY from_t');
    $st->execute($key);
    $merged = [];
    foreach ($st->fetchAll() as $r) {
        [$a, $b] = [(int)$r['from_t'], (int)$r['to_t']];
        if ($merged && $a <= $merged[count($merged) - 1][1] + 1) $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $b);
        else $merged[] = [$a, $b];
    }
    $c->beginTransaction();
    $c->prepare('DELETE FROM coverage WHERE provider = ? AND symbol = ? AND tf = ? AND cred_scope = ?')->execute($key);
    $ins = $c->prepare('INSERT INTO coverage (provider, symbol, tf, cred_scope, from_t, to_t) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($merged as [$a, $b]) $ins->execute([...$key, $a, $b]);
    $c->commit();
}

/** ניקוי: נרות ישנים מ-60 יום, טווחים, חיפושים ישנים (3.6, ✅4). מחזיר כמה נרות נמחקו. */
function ts_cache_cleanup(?int $now = null): int
{
    $now ??= time();
    $edge = $now - TS_POOL_DAYS * 86400;
    $c = ts_cache();
    $st = $c->prepare('DELETE FROM candles WHERE t < ?');
    $st->execute([$edge]);
    $n = $st->rowCount();
    $c->prepare('DELETE FROM coverage WHERE to_t < ?')->execute([$edge]);
    $c->prepare('UPDATE coverage SET from_t = ? WHERE from_t < ?')->execute([$edge, $edge]);
    $c->prepare("DELETE FROM search WHERE fetched_at < ? AND q NOT LIKE '\\_\\_%' ESCAPE '\\'")->execute([$now - TS_SEARCH_TTL]);
    $c->prepare('DELETE FROM tails WHERE fetched_at < ?')->execute([$now - 86400]);
    $c->prepare('DELETE FROM asset_meta WHERE updated_at < ?')->execute([$now - 180 * 86400]);
    return $n;
}

/** פעם ביום, בבקשה הראשונה אחרי שעבר יום — עד שה-cron של פרק 8 ייבנה */
function ts_cache_cleanup_daily(int $now): void
{
    if ((int)ts_setting('cache_cleanup_at', '0') > $now - 86400) return;
    ts_setting_set('cache_cleanup_at', (string)$now);
    try {
        ts_cache_cleanup($now);
    } catch (Throwable $e) {
        error_log('trading-strategies cleanup: ' . $e->getMessage());
    }
}

function ts_cache_stats(): array
{
    $c = ts_cache();
    $by = $c->query("SELECT provider, CASE WHEN cred_scope = '' THEN 'shared' WHEN cred_scope LIKE 'u:%' THEN 'personal' ELSE 'granted' END AS kind,
                     COUNT(*) AS n, MIN(t) AS oldest FROM candles GROUP BY provider, kind ORDER BY provider")->fetchAll();
    clearstatcache();
    return ['bytes' => (int)@filesize(ts_cache_path()) + (int)@filesize(ts_cache_path() . '-wal'), 'by' => array_map(fn($r) => [
        'provider' => $r['provider'], 'kind' => $r['kind'], 'candles' => (int)$r['n'], 'oldest' => (int)$r['oldest']], $by),
        'cleanup_at' => (int)ts_setting('cache_cleanup_at', '0')];
}

function ts_cache_clear(?string $provider): int
{
    $c = ts_cache();
    $n = 0;
    foreach (['candles', 'coverage', 'tails'] as $t) {
        $st = $provider ? $c->prepare("DELETE FROM $t WHERE provider = ?") : $c->prepare("DELETE FROM $t");
        $st->execute($provider ? [$provider] : []);
        if ($t === 'candles') $n = $st->rowCount();
    }
    return $n;
}

// ── המעקב שלי ────────────────────────────────────────────

function ts_watchlist(int $uid): array
{
    $st = ts_db()->prepare('SELECT asset, sort FROM watchlist WHERE user_id = ? ORDER BY sort, asset');
    $st->execute([$uid]);
    return array_map(function ($r) {
        [$provider, $symbol] = explode(':', $r['asset'], 2);
        return ['asset' => $r['asset'], 'provider' => $provider, 'symbol' => $symbol, 'meta' => ts_asset_meta($r['asset'])];
    }, $st->fetchAll());
}

function ts_watchlist_add(int $uid, mixed $asset): void
{
    ts_asset_split($asset);
    $db = ts_db();
    $st = $db->prepare('SELECT COUNT(*), COALESCE(MAX(sort), 0) FROM watchlist WHERE user_id = ?');
    $st->execute([$uid]);
    [$n, $max] = $st->fetch(PDO::FETCH_NUM);
    if ((int)$n >= TS_WATCHLIST_MAX) throw new TsInvalid('"המעקב שלי" מוגבל ל-' . TS_WATCHLIST_MAX . ' נכסים');
    $db->prepare('INSERT OR IGNORE INTO watchlist (user_id, asset, sort) VALUES (?, ?, ?)')->execute([$uid, $asset, (int)$max + 1]);
}

function ts_watchlist_remove(int $uid, mixed $asset): void
{
    $st = ts_db()->prepare('DELETE FROM watchlist WHERE user_id = ? AND asset = ?');
    $st->execute([$uid, is_string($asset) ? $asset : '']);
    if (!$st->rowCount()) throw new TsNotFound('הנכס לא במעקב');
}

function ts_watchlist_order(int $uid, mixed $assets): void
{
    if (!is_array($assets) || !array_is_list($assets) || count($assets) > TS_WATCHLIST_MAX) throw new TsInvalid('סדר לא תקין');
    $st = ts_db()->prepare('UPDATE watchlist SET sort = ? WHERE user_id = ? AND asset = ?');
    foreach ($assets as $i => $a) if (is_string($a)) $st->execute([$i, $uid, $a]);
}

// ── ספק מהמנהל (ב) ───────────────────────────────────────

function ts_admin_creds(): array
{
    $rows = ts_db()->query("SELECT id, provider_id, last4, label, created_at, last_test_at, last_test_ok FROM provider_creds WHERE owner = 'admin' ORDER BY id")->fetchAll();
    $g = ts_db()->prepare('SELECT g.user_id, g.daily_limit, u.display_name, u.email FROM provider_grants g JOIN users u ON u.id = g.user_id WHERE g.cred_id = ? ORDER BY u.display_name');
    return array_map(function ($r) use ($g) {
        $g->execute([$r['id']]);
        return ['id' => (int)$r['id'], 'provider' => $r['provider_id'], 'last4' => $r['last4'], 'label' => $r['label'],
            'last_test_at' => $r['last_test_at'] !== null ? (int)$r['last_test_at'] : null,
            'last_test_ok' => $r['last_test_ok'] !== null ? (bool)$r['last_test_ok'] : null,
            'grants' => array_map(fn($x) => ['user_id' => (int)$x['user_id'], 'daily_limit' => (int)$x['daily_limit'],
                'name' => $x['display_name'], 'email' => $x['email']], $g->fetchAll())];
    }, $rows);
}

function ts_admin_cred_row(int $id): array
{
    $st = ts_db()->prepare("SELECT * FROM provider_creds WHERE id = ? AND owner = 'admin'");
    $st->execute([$id]);
    return $st->fetch() ?: throw new TsNotFound('המפתח לא נמצא');
}

function ts_admin_cred_delete(int $id): void
{
    $row = ts_admin_cred_row($id);
    ts_db()->prepare('DELETE FROM provider_creds WHERE id = ?')->execute([$id]);
    ts_cache_drop_scope($row['provider_id'], "c:$id");
}

function ts_grant_set(int $credId, mixed $userId, mixed $limit): void
{
    ts_admin_cred_row($credId);
    $u = is_int($userId) ? $userId : (is_string($userId) && ctype_digit($userId) ? (int)$userId : 0);
    $st = ts_db()->prepare('SELECT 1 FROM users WHERE id = ?');
    $st->execute([$u]);
    if (!$st->fetchColumn()) throw new TsNotFound('המשתמש לא נמצא');
    $l = is_int($limit) ? $limit : (is_string($limit) && ctype_digit($limit) ? (int)$limit : -1);
    if ($l < 1 || $l > 1_000_000) throw new TsInvalid('תקרה יומית — מספר שלם חיובי');
    ts_db()->prepare('INSERT INTO provider_grants (cred_id, user_id, daily_limit) VALUES (?, ?, ?)
                      ON CONFLICT(cred_id, user_id) DO UPDATE SET daily_limit = excluded.daily_limit')->execute([$credId, $u, $l]);
}

function ts_grant_delete(int $credId, int $userId): void
{
    ts_db()->prepare('DELETE FROM provider_grants WHERE cred_id = ? AND user_id = ?')->execute([$credId, $userId]);
}
