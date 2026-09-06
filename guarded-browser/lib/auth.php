<?php
/**
 * הרשמה, כניסה, ואסימוני מכשיר.
 *
 * שני עולמות נפרדים בכוונה:
 *   • האפליקציה מזדהה באסימון מכשיר ארוך-חיים (Bearer).
 *   • פאנל הניהול מזדהה בעוגיית session רגילה של PHP.
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

    $row = one('SELECT u.*, d.id AS device_row_id FROM devices d
                JOIN users u ON u.id = d.user_id
                WHERE d.token_hash = ?', [tokenHash($token)]);
    if (!$row) return null;

    $now = nowIso();
    q('UPDATE devices SET last_seen_at = ? WHERE id = ?', [$now, $row['device_row_id']]);
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

/* ── פאנל הניהול ────────────────────────────────────────────────── */

function startAdminSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_start();
    }
}

function currentAdmin(): ?array {
    startAdminSession();
    $id = (int) ($_SESSION['admin_id'] ?? 0);
    if ($id <= 0) return null;

    $u = one('SELECT * FROM users WHERE id = ? AND is_admin = 1 AND status = ?', [$id, 'active']);
    return $u ?: null;
}

function requireAdmin(): array {
    $a = currentAdmin();
    if (!$a) { header('Location: login.php'); exit; }
    return $a;
}

/**
 * אסימון CSRF לטפסי הפאנל.
 *
 * הפאנל משנה הרשאות של משתמשים, ולכן טופס שנשלח מאתר אחר בזמן
 * שהמנהל מחובר הוא בדיוק התרחיש שצריך למנוע.
 */
function csrfToken(): string {
    startAdminSession();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function checkCsrf(): void {
    startAdminSession();
    $sent = (string) ($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
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
