<?php
/**
 * הרשמה, כניסה, ואסימוני מכשיר.
 *
 * שני עולמות נפרדים בכוונה:
 *   • האפליקציה מזדהה באסימון מכשיר ארוך-חיים (Bearer).
 *   • פאנל הניהול מזדהה בעוגייה משלו (mg_admin), שהאסימון שלה נשמר במסד.
 * ערבוב ביניהם היה נותן לאסימון של אפליקציה גישה לפאנל.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/alerts.php';

/**
 * ‏Argon2id אם קיים, ואחרת ברירת המחדל של PHP.
 *
 * ‏PASSWORD_DEFAULT לבדו כבר טוב, אבל באחסון משותף הוא לעיתים עדיין
 * bcrypt. ההעדפה מפורשת, והנפילה חזרה שקטה ותקינה.
 */
function hashPassword(string $plain): string {
    if (defined('PASSWORD_ARGON2ID')) {
        return password_hash($plain, PASSWORD_ARGON2ID);
    }
    return password_hash($plain, PASSWORD_DEFAULT);
}

/** דרישות סיסמה. מוחזרת שגיאה אחת ומדויקת, לא רשימה. */
function passwordProblem(string $plain): string {
    if (mb_strlen($plain) < 8)  return 'הסיסמה חייבת להיות באורך 8 תווים לפחות';
    if (mb_strlen($plain) > 200) return 'הסיסמה ארוכה מדי';
    return '';
}

function usernameProblem(string $name): string {
    if (!preg_match('/^[a-zA-Z0-9._\-]{3,32}$/', $name)) {
        return 'שם המשתמש חייב להיות 3–32 תווים באנגלית, ספרות, נקודה, מקף או קו תחתון';
    }
    return '';
}

/* ── אסימוני מכשיר ──────────────────────────────────────────────── */

/**
 * אסימון חדש. מוחזר פעם אחת בלבד; בבסיס הנתונים נשמר רק ה-hash שלו.
 *
 * ‏hash ולא הצפנה: לשרת אין צורך לשחזר את האסימון לעולם, ודליפה של
 * הטבלה אינה מספיקה כדי להתחזות למכשיר.
 */
function newDeviceToken(): string {
    return bin2hex(random_bytes(32));
}

function tokenHash(string $token): string {
    return hash('sha256', $token);
}

/**
 * רושם מכשיר ומחזיר את האסימון.
 *
 * חריגה ממספר המכשירים המותר מפנה את הוותיק ביותר. כך "מכשיר אחד"
 * פירושו באמת אחד, בלי שהמשתמש ייתקע בלי גישה אחרי החלפת טלפון.
 */
function registerDevice(int $userId, string $deviceName, string $deviceId, int $maxDevices): string {
    $token = newDeviceToken();

    q('INSERT INTO devices (user_id, token_hash, device_name, device_id, created_at, last_seen_at)
       VALUES (?, ?, ?, ?, ?, ?)',
      [$userId, tokenHash($token), mb_substr($deviceName, 0, 80),
       mb_substr($deviceId, 0, 128), nowIso(), nowIso()]);

    if ($maxDevices > 0) {
        $keep = all('SELECT id FROM devices WHERE user_id = ? ORDER BY last_seen_at DESC, id DESC LIMIT ?',
                    [$userId, $maxDevices]);
        $ids = array_column($keep, 'id');
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            q("DELETE FROM devices WHERE user_id = ? AND id NOT IN ($marks)",
              array_merge([$userId], $ids));
        }
    }
    return $token;
}

/** המשתמש שמאחורי אסימון, או null. מעדכן "נראה לאחרונה" אגב כך. */
function userByToken(string $token): ?array {
    if ($token === '') return null;

    // ‏a.* של החשבון כ-account_status / account_expires_at: התוקף והמצב של *מנוי המשפחה*
    // (SPEC סעיף 5) נאכפים על המכשיר, לא רק המצב הפרטני של הילד.
    $row = one('SELECT u.*, d.id AS device_row_id,
                       a.status AS account_status, a.expires_at AS account_expires_at
                FROM devices d
                JOIN users u ON u.id = d.user_id
                LEFT JOIN accounts a ON a.id = u.account_id
                WHERE d.token_hash = ?', [tokenHash($token)]);
    if (!$row) return null;

    $now = nowIso();
    q('UPDATE devices SET last_seen_at = ? WHERE id = ?', [$now, $row['device_row_id']]);
    // גרסת האפליקציה — רק מ-1.43 היא שולחת אותה. בלי הכותרת: גרסה ישנה, או "דפדפן מנוהל".
    $ver = (int) ($_SERVER['HTTP_X_MIGDALOR_APP'] ?? 0);
    if ($ver > 0) q('UPDATE devices SET app_version = ? WHERE id = ? AND app_version != ?', [$ver, $row['device_row_id'], $ver]);
    // מצב ההרשאות במכשיר (מסלול ההקמה) — נשמר לתצוגה אצל ההורה.
    if (isset($_SERVER['HTTP_X_MIGDALOR_PERMS'])) {
        $perms = (int) $_SERVER['HTTP_X_MIGDALOR_PERMS'];
        q('UPDATE devices SET perms = ? WHERE id = ? AND perms != ?', [$perms, $row['device_row_id'], $perms]);
    }
    q('UPDATE users SET last_seen_at = ? WHERE id = ?', [$now, $row['id']]);
    return $row;
}

/** האסימון מכותרת Authorization. */
function bearerToken(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m) ? $m[1] : '';
}

/* ── פאנל הניהול ────────────────────────────────────────────────
 *
 * עוגייה משלנו ולא session של PHP. ה-session המובנה נקרא PHPSESSID,
 * יושב בנתיב שורש האתר — כלומר משותף לכל כלי באותו דומיין — ובאחסון
 * משותף נמחק אחרי 24 דקות בלי פעילות. מנהל שמגדיר הרשאות מהטלפון
 * התנתק באמצע עבודה. כאן: אסימון אקראי בעוגייה, ובמסד רק ה-hash שלו.
 */

const ADMIN_COOKIE   = 'mg_admin';
const ADMIN_TTL_DAYS = 30;

/** עוגייה לכל סוג כניסה: הפאנל הישן, קונסולת המפתח, ואפליקציית ההורה. */
const SESSION_COOKIES = ['admin' => ADMIN_COOKIE, 'dev' => 'mg_dev', 'parent' => 'mg_parent'];

/** תיקיית הסקריפט, לנתיב העוגייה — כדי שלא תישלח לכלים אחרים באותו דומיין. */
function adminCookiePath(): string {
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/x.php'));
    return rtrim($dir, '/') . '/';
}

function setSessionCookie(string $kind, string $value, int $expires): void {
    if (headers_sent() || PHP_SAPI === 'cli') return;
    setcookie(SESSION_COOKIES[$kind], $value, [
        'expires'  => $expires,
        'path'     => adminCookiePath(),
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

/** שוכח את מה שנקרא מהעוגיות בבקשה הזו (לבדיקות, ואחרי כניסה/יציאה). */
function forgetSessions(): void {
    $GLOBALS['GB_SESSIONS'] = [];
}

/** פותח session חדש. אסימון חדש בכל כניסה — עוגייה שהושתלה מראש אינה שווה דבר. */
function startSession(string $kind, int $userId): string {
    $token   = bin2hex(random_bytes(32));
    $expires = time() + ADMIN_TTL_DAYS * 86400;
    q('INSERT INTO admin_sessions (token_hash, kind, user_id, csrf, created_at, last_seen_at, expires_at)
       VALUES (?, ?, ?, ?, ?, ?, ?)',
      [hash('sha256', $token), $kind, $userId, bin2hex(random_bytes(16)), nowIso(), nowIso(),
       gmdate('Y-m-d\TH:i:s\Z', $expires)]);
    // ניקוי אגבי: sessions שפג תוקפם לא נשארים במסד לנצח.
    q('DELETE FROM admin_sessions WHERE expires_at < ?', [nowIso()]);
    setSessionCookie($kind, $token, $expires);
    forgetSessions();
    return $token;
}

/** שורת ה-session הנוכחית מסוג מסוים, או null. נשמרת בזיכרון לאורך הבקשה. */
function sessionRow(string $kind): ?array {
    $cache = $GLOBALS['GB_SESSIONS'] ?? [];
    if (array_key_exists($kind, $cache)) return $cache[$kind] ?: null;

    $token = (string) ($_COOKIE[SESSION_COOKIES[$kind]] ?? '');
    $row = null;
    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
        $row = one('SELECT * FROM admin_sessions WHERE token_hash = ? AND kind = ? AND expires_at > ?',
                   [hash('sha256', $token), $kind, nowIso()]);
        if ($row && substr((string) $row['last_seen_at'], 0, 13) !== substr(nowIso(), 0, 13)) {
            // חלון נגלל: מי שפעיל לא מתנתק. עדכון פעם בשעה, לא בכל בקשה.
            $expires = time() + ADMIN_TTL_DAYS * 86400;
            q('UPDATE admin_sessions SET last_seen_at = ?, expires_at = ? WHERE token_hash = ?',
              [nowIso(), gmdate('Y-m-d\TH:i:s\Z', $expires), $row['token_hash']]);
            setSessionCookie($kind, $token, $expires);
        }
    }
    $GLOBALS['GB_SESSIONS'][$kind] = $row ?: false;
    return $row ?: null;
}

/** יציאה: מוחק את ה-session מהמסד ואת העוגייה. */
function endSession(string $kind): void {
    $token = (string) ($_COOKIE[SESSION_COOKIES[$kind]] ?? '');
    if ($token !== '') {
        q('DELETE FROM admin_sessions WHERE token_hash = ? AND kind = ?', [hash('sha256', $token), $kind]);
    }
    setSessionCookie($kind, '', time() - 3600);
    $GLOBALS['GB_SESSIONS'][$kind] = false;
}

/** מנתק את כל הכניסות של משתמש מסוג מסוים — אחרי איפוס סיסמה או השעיה. */
function endAllSessions(string $kind, int $userId): void {
    q('DELETE FROM admin_sessions WHERE kind = ? AND user_id = ?', [$kind, $userId]);
    forgetSessions();
}

/* הפאנל הישן (admin/) — אותו מנגנון, סוג 'admin'. */
function startAdminSession(int $userId): string { return startSession('admin', $userId); }
function adminSession(): ?array { return sessionRow('admin'); }

function currentAdmin(): ?array {
    $s = adminSession();
    if (!$s) return null;

    $u = one('SELECT * FROM users WHERE id = ? AND is_admin = 1 AND status = ?',
             [(int) $s['user_id'], 'active']);
    $GLOBALS['GB_ADMIN_ID'] = $u ? (int) $u['id'] : 0;
    return $u ?: null;
}

function requireAdmin(): array {
    $a = currentAdmin();
    if (!$a) { header('Location: login.php'); exit; }
    return $a;
}

function endAdminSession(): void { endSession('admin'); }

/**
 * אסימון CSRF לטפסי הפאנל — צמוד ל-session.
 *
 * הפאנל משנה הרשאות של משתמשים, ולכן טופס שנשלח מאתר אחר בזמן
 * שהמנהל מחובר הוא בדיוק התרחיש שצריך למנוע.
 */
function csrfToken(): string {
    return (string) (adminSession()['csrf'] ?? '');
}

function checkCsrf(): void {
    $sent = (string) ($_POST['csrf'] ?? '');
    $have = csrfToken();
    if ($sent === '' || $have === '' || !hash_equals($have, $sent)) {
        http_response_code(400);
        exit('בקשה לא תקינה (CSRF)');
    }
}

/**
 * מגביל קצב פשוט על ניסיונות כניסה, מבוסס על היומן.
 *
 * ‏SQLite ואחסון משותף — אין Redis ואין מה להתקין. ספירת הכישלונות
 * האחרונים מהיומן מספיקה כדי לעצור ניחוש סיסמאות בכוח.
 */
function tooManyFailures(string $username, int $limit = 8, int $minutes = 15): bool {
    $since = (new DateTimeImmutable("-$minutes minutes", new DateTimeZone('UTC')))
             ->format('Y-m-d\TH:i:s\Z');
    $row = one("SELECT COUNT(*) AS n FROM audit
                WHERE kind = 'login_fail' AND detail = ? AND at > ?", [$username, $since]);
    return ((int) ($row['n'] ?? 0)) >= $limit;
}
