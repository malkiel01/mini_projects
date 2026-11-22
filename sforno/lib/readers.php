<?php
/**
 * חשבונות הקוראים (אפיון 05 §1–§2, §6) — **עולם נפרד מהמנהלים** (lib/auth.php): טבלאות readers / reader_sessions /
 * devices, העוגייה sf_reader, ו-CSRF משלה (כותרת X-Reader-CSRF). עוגיית sf_reader לא נבדקת בשום פעולת ניהול, ו-sf_staff
 * לא נבדקת כאן.
 *
 *   כניסה      — מייל + סיסמה (password_hash: Argon2id, או bcrypt), או Google (ID token שנבדק בשרת).
 *   הגבלה      — 5 כישלונות למייל ב-15 דקות ← נעילה; 30 לכתובת IP. נספר לפי hash של המייל — גם כשהוא לא קיים, כך
 *                שההודעות והנעילה זהות לשני המקרים.
 *   קישורים    — אימות (24 שעות) ואיפוס (שעה): חד-פעמיים, במסד רק ה-hash. האיפוס מבטל את כל ה-sessions.
 *   מחיקה      — רכה ומיידית (status = deleted): הכניסה נחסמת. המחיקה המלאה אחרי 30 יום — 10 ⏳.
 *
 * **שום סיסמה, אסימון או מידע אישי לא נכנס ליומן הפעולות או ליומן הטכני** — רק pid ומצב.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/audit.php';

const SF_READER_COOKIE = 'sf_reader';
const SF_READER_TTL = 180 * 86400;
const SF_LOGIN_MAX = 5;
const SF_LOGIN_WINDOW = 900;
const SF_LOGIN_IP_MAX = 30;
const SF_VERIFY_TTL = 86400;
const SF_RESET_TTL = 3600;

// ── המפתח הסודי וההצפנה ──────────────────────────────────────

/**
 * data/secret.key — 32 בתים אקראיים, נוצר בשימוש הראשון (קובץ זמני + link, כך ששתי בקשות במקביל לא יוצרות שני
 * מפתחות). בלי המפתח אין פענוח של ההערות — הוא חלק מהגיבוי של data/ (10).
 */
function sf_secret_key(): string
{
    $f = sf_data_dir() . '/secret.key';
    if (isset($GLOBALS['sf_secret'][$f])) return $GLOBALS['sf_secret'][$f];
    if (!is_file($f)) {
        $tmp = $f . '.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            @chmod($tmp, 0600);
            @link($tmp, $f);   // נכשל אם כבר נוצר — אז משתמשים בקיים
        }
        @unlink($tmp);
    }
    $k = @file_get_contents($f);
    if (!is_string($k) || strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new SfError('storage');
    return $GLOBALS['sf_secret'][$f] = $k;
}

/** הצפנה: "s1:" + base64(nonce ‖ box). */
function sf_seal(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, sf_secret_key()));
}

function sf_unseal(string $sealed): ?string
{
    if (!str_starts_with($sealed, 's1:')) return null;
    $raw = base64_decode(substr($sealed, 3), true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $out = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), sf_secret_key());
    return $out === false ? null : $out;
}

/**
 * hash של ערך להגבלת ניסיונות — HMAC, כך שגם המסד לא מגלה אילו מיילים / כתובות ניסו. המפתח נגזר מ-secret.key (KDF),
 * כך שמפתח ההצפנה עצמו לא משמש לשתי מטרות.
 */
function sf_key_hash(string $scope, string $value): string
{
    $k = $GLOBALS['sf_hmac_key'][sf_data_dir()] ??= sodium_crypto_kdf_derive_from_key(32, 1, 'sfhmac01', sf_secret_key());
    return hash_hmac('sha256', $scope . ':' . $value, $k);
}

// ── הגבלת ניסיונות ───────────────────────────────────────────

function sf_attempts(string $scope, string $value, int $window): int
{
    return (int)sf_val('SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND key_hash = ? AND at > ?', [$scope, sf_key_hash($scope, $value), sf_iso(sf_time() - $window)]);
}

function sf_attempt_add(string $scope, string $value): void
{
    sf_insert('login_attempts', ['scope' => $scope, 'key_hash' => sf_key_hash($scope, $value), 'at' => sf_now()]);
    sf_exec('DELETE FROM login_attempts WHERE at < ?', [sf_iso(sf_time() - 86400)]);
}

function sf_attempts_clear(string $scope, string $value): void
{
    sf_exec('DELETE FROM login_attempts WHERE scope = ? AND key_hash = ?', [$scope, sf_key_hash($scope, $value)]);
}

/** מגבלה כללית לפעולה (מיילים, הרשמה): עד $max בחלון — אחרת 429. נספר גם כשהפעולה לא תעשה דבר. */
function sf_rate(string $scope, string $value, int $max, int $window): void
{
    if (sf_attempts($scope, $value, $window) >= $max) sf_fail('rate');
    sf_attempt_add($scope, $value);
}

// ── קלט ───────────────────────────────────────────────────────

function sf_reader_email(mixed $v): string
{
    $e = is_string($v) ? strtolower(trim($v)) : '';
    if ($e === '' || strlen($e) > 190 || filter_var($e, FILTER_VALIDATE_EMAIL) === false) sf_fail('bad_input', 'כתובת המייל אינה תקינה');
    return $e;
}

function sf_reader_password(mixed $v): string
{
    $p = is_string($v) ? $v : '';
    if (mb_strlen($p) < 8) sf_fail('bad_input', 'הסיסמה צריכה להיות באורך 8 תווים לפחות');
    if (strlen($p) > 200 || (!defined('PASSWORD_ARGON2ID') && strlen($p) > 72)) sf_fail('bad_input', 'הסיסמה ארוכה מדי');
    return $p;
}

function sf_password_hash(string $p): string
{
    return password_hash($p, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
}

/** hash קבוע לבדיקה כשהמייל לא קיים — אותו זמן חישוב, כך שגם הזמן לא מגלה. */
function sf_dummy_hash(): string
{
    return $GLOBALS['sf_dummy_hash'] ??= sf_password_hash(bin2hex(random_bytes(8)));
}

function sf_reader_name(mixed $v, string $email): string
{
    $n = is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v) ?? '') : '';
    $n = mb_substr($n, 0, 80);
    return $n !== '' ? $n : explode('@', $email)[0];
}

function sf_reader_ip(array $env): string
{
    $ip = (string)($env['ip'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
}

// ── העוגייה וה-session ──────────────────────────────────────

/** כותב את עוגיית הקורא. ריק = מחיקה. בבדיקות — $GLOBALS['sf_reader_cookie_out']. */
function sf_set_reader_cookie(string $token, int $ttl): void
{
    $GLOBALS['sf_reader_cookie_out'] = $token;
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    setcookie(SF_READER_COOKIE, $token, ['expires' => $token === '' ? 1 : time() + $ttl, 'path' => sf_base_path(),
        'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}

/** "Chrome · Android" — מה-User-Agent, לרשימת המכשירים. */
function sf_device_label(string $ua): string
{
    $b = match (true) {
        str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
        str_contains($ua, 'Edg/') => 'Edge',
        str_contains($ua, 'OPR/') => 'Opera',
        str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS') => 'Firefox',
        str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
        str_contains($ua, 'Safari/') => 'Safari',
        default => 'דפדפן',
    };
    $os = match (true) {
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'iPhone') => 'iPhone',
        str_contains($ua, 'iPad') => 'iPad',
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'Mac',
        str_contains($ua, 'CrOS') => 'Chromebook',
        str_contains($ua, 'Linux') => 'Linux',
        default => '',
    };
    return $os !== '' ? "$b · $os" : $b;
}

/** כניסה מוצלחת: מכשיר חדש + session, והעוגייה. */
function sf_reader_session_new(array $reader, array $env): array
{
    $now = sf_time();
    $ua = (string)($env['ua'] ?? '');
    $dev = sf_insert('devices', ['pid' => sf_pid(), 'reader_id' => $reader['id'], 'label' => sf_device_label($ua),
        'created_at' => sf_iso($now), 'last_seen_at' => sf_iso($now)]);
    $token = bin2hex(random_bytes(32));
    $id = sf_insert('reader_sessions', ['reader_id' => $reader['id'], 'device_id' => $dev, 'token_hash' => hash('sha256', $token),
        'csrf' => bin2hex(random_bytes(32)), 'ua' => mb_substr($ua, 0, 300), 'created_at' => sf_iso($now), 'last_used_at' => sf_iso($now),
        'expires_at' => sf_iso($now + SF_READER_TTL)]);
    sf_set_reader_cookie($token, SF_READER_TTL);
    return sf_row('SELECT * FROM reader_sessions WHERE id = ?', [$id]);
}

/**
 * הקורא של הבקשה, מהעוגייה sf_reader בלבד: ['reader' => …, 'session' => …] או null. session שפג, של מכשיר שבוטל או של
 * חשבון שנמחק — נמחק והעוגייה מתנקה.
 */
function sf_reader_auth(array $env): ?array
{
    $cookie = (string)($env['reader_cookie'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $cookie) !== 1) return null;
    $now = sf_time();
    $s = sf_row('SELECT * FROM reader_sessions WHERE token_hash = ?', [hash('sha256', $cookie)]);
    $r = $s ? sf_row("SELECT * FROM readers WHERE id = ? AND status = 'active' AND deleted_at IS NULL", [$s['reader_id']]) : null;
    $d = $s ? sf_row('SELECT * FROM devices WHERE id = ? AND revoked_at IS NULL', [$s['device_id']]) : null;
    if (!$s || !$r || !$d || $s['expires_at'] <= sf_iso($now)) {
        if ($s) sf_exec('DELETE FROM reader_sessions WHERE id = ?', [$s['id']]);
        sf_set_reader_cookie('', 0);
        return null;
    }
    if (strtotime($s['last_used_at']) < $now - 3600) {
        sf_exec('UPDATE reader_sessions SET last_used_at = ?, expires_at = ? WHERE id = ?', [sf_iso($now), sf_iso($now + SF_READER_TTL), $s['id']]);
        sf_exec('UPDATE devices SET last_seen_at = ? WHERE id = ?', [sf_iso($now), $d['id']]);
        sf_set_reader_cookie($cookie, SF_READER_TTL);
    }
    return ['reader' => $r, 'session' => $s];
}

/** מבטל מכשירים וה-sessions שלהם. $deviceId null = כל המכשירים של הקורא. */
function sf_reader_revoke(int $readerId, ?int $deviceId = null): void
{
    $now = sf_now();
    if ($deviceId === null) {
        sf_exec('DELETE FROM reader_sessions WHERE reader_id = ?', [$readerId]);
        sf_exec('UPDATE devices SET revoked_at = ? WHERE reader_id = ? AND revoked_at IS NULL', [$now, $readerId]);
        sf_exec('UPDATE push_devices SET revoked_at = ? WHERE reader_id = ? AND revoked_at IS NULL', [$now, $readerId]);
    } else {
        sf_exec('DELETE FROM reader_sessions WHERE reader_id = ? AND device_id = ?', [$readerId, $deviceId]);
        sf_exec('UPDATE devices SET revoked_at = ? WHERE reader_id = ? AND id = ? AND revoked_at IS NULL', [$now, $readerId, $deviceId]);
        sf_exec('UPDATE push_devices SET revoked_at = ? WHERE reader_id = ? AND device_id = ? AND revoked_at IS NULL', [$now, $readerId, $deviceId]);
    }
    // 08: מכשיר שבוטל לא מקבל Push — גם המופעים שכבר תוכננו אליו מתבטלים
    sf_exec("UPDATE reminder_occurrences SET status = 'cancelled', updated_at = ? WHERE owner = ? AND status = 'pending' AND channel = 'push'
        AND push_device_id IN (SELECT id FROM push_devices WHERE reader_id = ? AND revoked_at IS NOT NULL)", [$now, 'r:' . $readerId, $readerId]);
}

function sf_reader_public(array $r): array
{
    return ['pid' => $r['pid'], 'email' => $r['email'], 'name' => $r['name'], 'verified' => $r['email_verified_at'] !== null,
        'password' => $r['password_hash'] !== null, 'google' => $r['google_sub'] !== null, 'created_at' => $r['created_at']];
}

/** account-me: מי מחובר (או null), ה-CSRF של הקורא, ומזהה הלקוח של Google (אם הוגדר). */
function sf_account_me(?array $ra): array
{
    return ['reader' => $ra ? sf_reader_public($ra['reader']) : null, 'csrf' => $ra ? $ra['session']['csrf'] : null,
        'google_client_id' => sf_meta('google_client_id') ?: null];
}

/** אחרי כניסה: התשובה כמו account-me, עם ה-session החדש. */
function sf_account_after_login(array $reader, array $session): array
{
    return sf_account_me(['reader' => sf_row('SELECT * FROM readers WHERE id = ?', [$reader['id']]), 'session' => $session]);
}

// ── מיילים ────────────────────────────────────────────────────

/** שליחה דרך mail() של השרת. בבדיקות — $GLOBALS['sf_mail_out'][] (to, subject, body), בלי שליחה. */
function sf_reader_mail(string $to, string $subject, string $body): bool
{
    if (isset($GLOBALS['sf_mail_out'])) { $GLOBALS['sf_mail_out'][] = compact('to', 'subject', 'body'); return true; }
    if (!function_exists('mail')) return false;
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n");
}

/** קישור חד-פעמי: הישנים מאותו סוג מתבטלים, ובמסד רק ה-hash. האסימון עצמו — רק בכתובת שבמייל (אחרי #, לא נשלח לשרת בטעינה). */
function sf_reader_token(array $reader, string $kind, int $ttl): string
{
    $now = sf_now();
    sf_exec('UPDATE reader_tokens SET used_at = ? WHERE reader_id = ? AND kind = ? AND used_at IS NULL', [$now, $reader['id'], $kind]);
    $token = bin2hex(random_bytes(32));
    sf_insert('reader_tokens', ['reader_id' => $reader['id'], 'kind' => $kind, 'token_hash' => hash('sha256', $token),
        'expires_at' => sf_iso(sf_time() + $ttl), 'created_at' => $now]);
    return $token;
}

/** בודק ומסמן כמשומש. מחזיר את הקורא, או 400 token. */
function sf_reader_token_use(mixed $token, string $kind): array
{
    if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) sf_fail('token');
    $t = sf_row('SELECT * FROM reader_tokens WHERE token_hash = ? AND kind = ?', [hash('sha256', $token), $kind]);
    if (!$t || $t['used_at'] !== null || $t['expires_at'] <= sf_now()) sf_fail('token');
    $r = sf_row("SELECT * FROM readers WHERE id = ? AND status = 'active' AND deleted_at IS NULL", [$t['reader_id']]);
    if (!$r) sf_fail('token');
    sf_exec('UPDATE reader_tokens SET used_at = ? WHERE id = ?', [sf_now(), $t['id']]);
    return $r;
}

function sf_verify_mail(array $reader): void
{
    $link = sf_self_url('index.html#/account/verify/' . sf_reader_token($reader, 'verify', SF_VERIFY_TTL));
    sf_reader_mail($reader['email'], 'ספורנו — אימות כתובת המייל',
        "שלום,\n\nכדי לאמת את כתובת המייל בחשבון ספורנו — פתחו את הקישור (בתוקף 24 שעות):\n$link\n\n" .
        "לא נרשמתם לספורנו? אפשר להתעלם מהמייל הזה.\n");
}

// ── הפעולות ───────────────────────────────────────────────────

/** הרשמה: חשבון חדש, מחובר מיד (אפשר להשתמש בלי אימות — §2), ומייל אימות. */
function sf_account_register(array $in, array $env): array
{
    $email = sf_reader_email($in['email'] ?? null);
    $pass = sf_reader_password($in['password'] ?? null);
    sf_rate('register', sf_reader_ip($env), 10, 3600);
    $hash = sf_password_hash($pass);
    return sf_tx(function () use ($email, $hash, $in, $env) {
        if (sf_val('SELECT 1 FROM readers WHERE email = ?', [$email])) sf_fail('email_taken');
        $now = sf_now();
        $id = sf_insert('readers', ['pid' => sf_pid(), 'email' => $email, 'password_hash' => $hash, 'name' => sf_reader_name($in['name'] ?? null, $email),
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $r = sf_row('SELECT * FROM readers WHERE id = ?', [$id]);
        sf_audit('reader.create', 'reader', $r['pid'], ['via' => 'password'], actor: null, kind: 'reader');
        sf_verify_mail($r);
        return sf_account_after_login($r, sf_reader_session_new($r, $env));
    });
}

/**
 * כניסה במייל וסיסמה. הודעה אחת לכל כישלון ("פרטי הכניסה שגויים"); נעילה אחרי 5 — לפי המייל (גם כשאינו קיים) ולפי
 * ה-IP. בזמן נעילה גם סיסמה נכונה נדחית.
 */
function sf_account_login(array $in, array $env): array
{
    $email = is_string($in['email'] ?? null) ? strtolower(trim($in['email'])) : '';
    $pass = is_string($in['password'] ?? null) ? $in['password'] : '';
    if ($email === '' || $pass === '' || strlen($email) > 190 || strlen($pass) > 200) sf_fail('bad_login');
    $ip = sf_reader_ip($env);
    $r = sf_row("SELECT * FROM readers WHERE email = ? AND status = 'active' AND deleted_at IS NULL", [$email]);
    $locked = sf_attempts('login', $email, SF_LOGIN_WINDOW) >= SF_LOGIN_MAX || sf_attempts('ip', $ip, SF_LOGIN_WINDOW) >= SF_LOGIN_IP_MAX
        || ($r && $r['locked_until'] !== null && $r['locked_until'] > sf_now());
    $ok = password_verify($pass, $r['password_hash'] ?? sf_dummy_hash()) && $r && $r['password_hash'] !== null;
    if ($locked) sf_fail('locked');
    if (!$ok) {
        sf_tx(function () use ($email, $ip, $r) {
            sf_attempt_add('login', $email);
            sf_attempt_add('ip', $ip);
            if ($r) {
                $n = (int)$r['failed_logins'] + 1;
                sf_exec('UPDATE readers SET failed_logins = ?, locked_until = ? WHERE id = ?',
                    [$n >= SF_LOGIN_MAX ? 0 : $n, $n >= SF_LOGIN_MAX ? sf_iso(sf_time() + SF_LOGIN_WINDOW) : $r['locked_until'], $r['id']]);
            }
        });
        sf_fail('bad_login');
    }
    return sf_tx(function () use ($r, $email, $env, $pass) {
        sf_attempts_clear('login', $email);
        sf_exec('UPDATE readers SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$r['id']]);
        // bcrypt מפעם (שרת בלי Argon2) → Argon2id, כשהוא זמין
        if (password_needs_rehash($r['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            sf_exec('UPDATE readers SET password_hash = ? WHERE id = ?', [sf_password_hash($pass), $r['id']]);
        }
        return sf_account_after_login($r, sf_reader_session_new($r, $env));
    });
}

function sf_account_logout(array $ra): array
{
    sf_reader_revoke((int)$ra['reader']['id'], (int)$ra['session']['device_id']);
    sf_set_reader_cookie('', 0);
    return [];
}

function sf_account_verify(array $in): array
{
    return sf_tx(function () use ($in) {
        $r = sf_reader_token_use($in['token'] ?? null, 'verify');
        if ($r['email_verified_at'] === null) sf_exec('UPDATE readers SET email_verified_at = ?, updated_at = ? WHERE id = ?', [sf_now(), sf_now(), $r['id']]);
        return ['verified' => true];
    });
}

function sf_account_verify_resend(array $ra): array
{
    $r = $ra['reader'];
    if ($r['email_verified_at'] !== null) return ['verified' => true];
    sf_rate('mail', $r['email'], 3, 3600);
    sf_tx(fn() => sf_verify_mail($r));
    return ['sent' => true];
}

/** בקשת איפוס: תמיד אותה תשובה — לא חושפים אם המייל רשום. מוגבל לפי המייל ולפי ה-IP. */
function sf_account_reset_request(array $in, array $env): array
{
    $email = sf_reader_email($in['email'] ?? null);
    sf_rate('mail', $email, 3, 3600);
    sf_rate('register', sf_reader_ip($env), 10, 3600);
    sf_tx(function () use ($email) {
        $r = sf_row("SELECT * FROM readers WHERE email = ? AND status = 'active' AND deleted_at IS NULL", [$email]);
        if (!$r) return;
        $link = sf_self_url('index.html#/account/reset/' . sf_reader_token($r, 'reset', SF_RESET_TTL));
        sf_reader_mail($r['email'], 'ספורנו — איפוס סיסמה',
            "שלום,\n\nביקשתם לאפס את הסיסמה בחשבון ספורנו. הקישור בתוקף שעה אחת, לשימוש אחד:\n$link\n\n" .
            "לא ביקשתם? אפשר להתעלם — הסיסמה לא תשתנה.\n");
    });
    return ['sent' => true];
}

/** איפוס: סיסמה חדשה, כל ה-sessions והמכשירים מתבטלים, ונכנסים מחדש במכשיר הזה. הקישור מאמת גם את המייל. */
function sf_account_reset(array $in, array $env): array
{
    $pass = sf_reader_password($in['password'] ?? null);
    $hash = sf_password_hash($pass);
    return sf_tx(function () use ($in, $hash, $env) {
        $r = sf_reader_token_use($in['token'] ?? null, 'reset');
        $now = sf_now();
        sf_exec('UPDATE readers SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, ?), failed_logins = 0, locked_until = NULL, updated_at = ? WHERE id = ?',
            [$hash, $now, $now, $r['id']]);
        sf_exec('UPDATE reader_tokens SET used_at = ? WHERE reader_id = ? AND used_at IS NULL', [$now, $r['id']]);
        sf_reader_revoke((int)$r['id']);
        sf_attempts_clear('login', $r['email']);
        sf_audit('reader.reset', 'reader', $r['pid'], [], actor: null, kind: 'reader');
        return sf_account_after_login($r, sf_reader_session_new($r, $env));
    });
}

function sf_account_devices(array $ra): array
{
    $cur = (int)$ra['session']['device_id'];
    return ['devices' => array_map(fn($d) => ['pid' => $d['pid'], 'label' => $d['label'], 'created_at' => $d['created_at'],
        'last_seen_at' => $d['last_seen_at'], 'current' => (int)$d['id'] === $cur],
        sf_rows('SELECT * FROM devices WHERE reader_id = ? AND revoked_at IS NULL ORDER BY last_seen_at DESC', [$ra['reader']['id']]))];
}

/** ביטול מכשיר של הקורא עצמו. מכשיר של קורא אחר — 404, כאילו לא קיים (§21.15). */
function sf_account_device_revoke(array $ra, array $in): array
{
    $pid = $in['device'] ?? null;
    if (!sf_is_pid($pid)) sf_fail('not_found');
    $d = sf_row('SELECT * FROM devices WHERE pid = ? AND reader_id = ? AND revoked_at IS NULL', [$pid, $ra['reader']['id']]);
    if (!$d) sf_fail('not_found');
    sf_tx(fn() => sf_reader_revoke((int)$ra['reader']['id'], (int)$d['id']));
    if ((int)$d['id'] === (int)$ra['session']['device_id']) sf_set_reader_cookie('', 0);
    return ['revoked' => $pid];
}

/** ייצוא: כל המידע האישי, כולל הערות ושמות מפוענחים (§6). */
function sf_account_export(array $ra): array
{
    $r = $ra['reader'];
    return ['export' => [
        'app' => 'sforno', 'exported_at' => sf_now(),
        'account' => ['email' => $r['email'], 'name' => $r['name'], 'created_at' => $r['created_at'], 'email_verified_at' => $r['email_verified_at'],
            'google' => $r['google_sub'] !== null],
        'devices' => array_map(fn($d) => ['label' => $d['label'], 'created_at' => $d['created_at'], 'last_seen_at' => $d['last_seen_at'], 'revoked_at' => $d['revoked_at']],
            sf_rows('SELECT * FROM devices WHERE reader_id = ? ORDER BY id', [$r['id']])),
        'items' => array_map(fn($i) => array_diff_key(sf_sync_out($i), ['rev' => 0, 'deleted' => 0]),
            sf_rows('SELECT * FROM personal_items WHERE reader_id = ? AND deleted_at IS NULL ORDER BY id', [$r['id']])),
    ]];
}

/** בקשת מחיקה: מחיקה רכה מיד — הכניסה נחסמת, כל המכשירים מתבטלים. המחיקה המלאה אחרי 30 יום — 10 ⏳. */
function sf_account_delete(array $ra, array $in): array
{
    if (($in['confirm'] ?? null) !== true) sf_fail('bad_input', 'צריך לאשר את המחיקה');
    $r = $ra['reader'];
    sf_tx(function () use ($r) {
        $now = sf_now();
        sf_exec("UPDATE readers SET status = 'deleted', deleted_at = ?, updated_at = ? WHERE id = ?", [$now, $now, $r['id']]);
        sf_exec('UPDATE reader_tokens SET used_at = ? WHERE reader_id = ? AND used_at IS NULL', [$now, $r['id']]);
        sf_reader_revoke((int)$r['id']);
        sf_audit('reader.delete_request', 'reader', $r['pid'], [], actor: null, kind: 'reader');
    });
    sf_set_reader_cookie('', 0);
    return ['deleted' => true, 'purge_after_days' => 30];
}

// ── Google ────────────────────────────────────────────────────

const SF_GOOGLE_CERTS = 'https://www.googleapis.com/oauth2/v3/certs';
const SF_GOOGLE_ISS = ['accounts.google.com', 'https://accounts.google.com'];

function sf_b64url(string $s): string|false
{
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

function sf_der(int $tag, string $v): string
{
    $n = strlen($v);
    $len = $n < 128 ? chr($n) : chr(0x80 | strlen(ltrim(pack('N', $n), "\0"))) . ltrim(pack('N', $n), "\0");
    return chr($tag) . $len . $v;
}

/** מפתח RSA ציבורי (JWK: n, e) → PEM, בשביל openssl_verify. */
function sf_jwk_pem(string $n, string $e): ?string
{
    $n = sf_b64url($n);
    $e = sf_b64url($e);
    if ($n === false || $e === false || $n === '' || $e === '') return null;
    $int = fn(string $v) => sf_der(0x02, (ord($v[0]) & 0x80) ? "\0" . $v : $v);
    $spki = sf_der(0x30, sf_der(0x30, sf_der(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00") . sf_der(0x03, "\0" . sf_der(0x30, $int($n) . $int($e))));
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** המפתחות של Google (kid → PEM), במטמון 6 שעות ב-meta. $force — מפתח חדש שעוד לא במטמון. */
function sf_google_keys(bool $force = false): array
{
    $at = (int)sf_meta('google_jwks_at', '0');
    $raw = sf_meta('google_jwks');
    if ($force || $raw === null || $at < sf_time() - 6 * 3600) {
        $res = sf_http_get(SF_GOOGLE_CERTS);
        if ($res && $res[0] === 200 && is_array(json_decode($res[1], true))) {
            $raw = $res[1];
            sf_meta_set('google_jwks', $raw);
            sf_meta_set('google_jwks_at', (string)sf_time());
        }
    }
    $out = [];
    foreach ((json_decode((string)$raw, true)['keys'] ?? []) as $k) {
        if (($k['kty'] ?? '') !== 'RSA' || !is_string($k['kid'] ?? null) || !is_string($k['n'] ?? null) || !is_string($k['e'] ?? null)) continue;
        if (($pem = sf_jwk_pem($k['n'], $k['e'])) !== null) $out[$k['kid']] = $pem;
    }
    return $out;
}

/** בדיקת ID token (§2): חתימת RS256 במפתח של Google, iss, aud = מזהה הלקוח שלנו, תפוגה. מחזיר את ה-claims או 401 google. */
function sf_google_verify(mixed $jwt, string $aud): array
{
    if (!is_string($jwt) || strlen($jwt) > 8000) sf_fail('google');
    $p = explode('.', $jwt);
    if (count($p) !== 3) sf_fail('google');
    $head = json_decode((string)sf_b64url($p[0]), true);
    $claims = json_decode((string)sf_b64url($p[1]), true);
    $sig = sf_b64url($p[2]);
    if (!is_array($head) || !is_array($claims) || $sig === false || ($head['alg'] ?? '') !== 'RS256' || !is_string($head['kid'] ?? null)) sf_fail('google');
    $keys = sf_google_keys();
    // kid שלא במטמון — רענון כפוי, לכל היותר פעם בדקה (אחרת kid מומצא = בקשה ל-Google בכל ניסיון)
    if (!isset($keys[$head['kid']]) && (int)sf_meta('google_jwks_forced_at', '0') < sf_time() - 60) {
        sf_meta_set('google_jwks_forced_at', (string)sf_time());
        $keys = sf_google_keys(true);
    }
    $pem = $keys[$head['kid']] ?? null;
    if ($pem === null || openssl_verify($p[0] . '.' . $p[1], $sig, $pem, OPENSSL_ALGO_SHA256) !== 1) sf_fail('google');
    $now = sf_time();
    $audOk = is_string($claims['aud'] ?? null) ? hash_equals($aud, $claims['aud']) : false;
    if (!$audOk || !in_array($claims['iss'] ?? null, SF_GOOGLE_ISS, true) || !is_int($claims['exp'] ?? null) || $claims['exp'] < $now - 30
        || (is_int($claims['iat'] ?? null) && $claims['iat'] > $now + 300) || !is_string($claims['sub'] ?? null) || $claims['sub'] === '') sf_fail('google');
    return $claims;
}

/**
 * כניסה עם Google. לפי הסדר: google_sub מקושר → הוא. אחרת, חשבון באותו מייל: מאומת → קישור; **לא מאומת** → קישור, המייל
 * מאומת, והסיסמה וכל ה-sessions מתבטלים (מי שנרשם עם המייל הזה בלי להוכיח שהוא שלו — לא נשאר עם גישה). אחרת — חשבון חדש.
 */
function sf_account_google(array $in, array $env): array
{
    $aud = (string)sf_meta('google_client_id', '');
    if ($aud === '') sf_fail('google_off');
    sf_rate('google', sf_reader_ip($env), 30, 900);
    $c = sf_google_verify($in['credential'] ?? null, $aud);
    $email = is_string($c['email'] ?? null) ? strtolower(trim($c['email'])) : '';
    $verified = ($c['email_verified'] ?? false) === true || ($c['email_verified'] ?? '') === 'true';
    return sf_tx(function () use ($c, $email, $verified, $env) {
        $now = sf_now();
        $r = sf_row('SELECT * FROM readers WHERE google_sub = ?', [$c['sub']]);
        if (!$r) {
            if ($email === '' || !$verified) sf_fail('google', 'חשבון ה-Google הזה אינו מאשר כתובת מייל');
            $r = sf_row('SELECT * FROM readers WHERE email = ?', [$email]);
            if ($r && $r['google_sub'] !== null) sf_fail('google');
            if ($r) {
                $takeover = $r['email_verified_at'] === null;
                sf_exec('UPDATE readers SET google_sub = ?, email_verified_at = COALESCE(email_verified_at, ?), password_hash = ' . ($takeover ? 'NULL' : 'password_hash') . ', updated_at = ? WHERE id = ?',
                    [$c['sub'], $now, $now, $r['id']]);
                if ($takeover) sf_reader_revoke((int)$r['id']);
                sf_audit('reader.google_link', 'reader', $r['pid'], ['takeover' => $takeover], actor: null, kind: 'reader');
            } else {
                $id = sf_insert('readers', ['pid' => sf_pid(), 'email' => $email, 'email_verified_at' => $now, 'google_sub' => $c['sub'],
                    'name' => sf_reader_name($c['name'] ?? null, $email), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
                $r = sf_row('SELECT * FROM readers WHERE id = ?', [$id]);
                sf_audit('reader.create', 'reader', $r['pid'], ['via' => 'google'], actor: null, kind: 'reader');
            }
            $r = sf_row('SELECT * FROM readers WHERE id = ?', [$r['id']]);
        }
        if ($r['status'] !== 'active' || $r['deleted_at'] !== null) sf_fail('bad_login');
        return sf_account_after_login($r, sf_reader_session_new($r, $env));
    });
}

/** מסך המפתח: מזהה הלקוח של Google (לא סוד — הוא גלוי בדף). ריק = כיבוי. */
function sf_dev_google_set(array $in): array
{
    $id = is_string($in['client_id'] ?? null) ? trim($in['client_id']) : '';
    if ($id !== '' && preg_match('/^[0-9]{5,30}-[a-z0-9]{10,64}\.apps\.googleusercontent\.com$/', $id) !== 1) sf_fail('bad_input', 'מזהה הלקוח אינו בצורה של Google (…apps.googleusercontent.com)');
    sf_meta_set('google_client_id', $id);
    sf_audit('settings.google_client', null, null, ['on' => $id !== '']);
    return ['client_id' => $id ?: null];
}
