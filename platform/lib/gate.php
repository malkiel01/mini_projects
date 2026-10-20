<?php
/**
 * השער: מי רשאי לבקש קובץ מתוך תיקיית כלי.
 *
 * נטען בשתי דרכים:
 *   - gate.php       — קבצים סטטיים (HTML, JS, תמונות…) שה-.htaccess של הכלי מפנה אליו
 *   - gate-prepend.php — לפני כל קובץ PHP של הכלי, דרך auto_prepend_file ב-.user.ini
 *
 * במקרה השני הקוד רץ בתוך התהליך של הכלי, ולכן כאן **אין שום שם גלובלי**:
 * הכול ב-namespace, בלי include של שאר lib/ (שמגדיר db() — שם שגם כלים
 * מגדירים, וזה היה מפיל אותם על "cannot redeclare"), ובלי משתנים גלובליים.
 *
 * לוגיקת ההרשאה היא אותה לוגיקה כמו ב-access.php (can_see), בכתיבה עצמאית
 * מאותה סיבה. שינוי באחת — לשנות גם בשנייה.
 */

declare(strict_types=1);

namespace Platform\Gate;

const ROOT = __DIR__ . '/../..';
const DB_FILE = __DIR__ . '/../data/platform.sqlite';
const COOKIE = 'mp_session';
const SESSION_DAYS = 182;   // כמו ב-auth.php: חצי שנה מהשימוש האחרון (docs/auth.md, סעיף 5)

/** הגדרות השער של אפליקציה מתוך ה-app.json שלה, או null אם אינה רשומה. */
function app_config(string $app): ?array
{
    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $app)) return null;
    $file = ROOT . "/$app/app.json";
    if (!is_file($file)) return null;
    $cfg = json_decode((string)file_get_contents($file), true);
    return is_array($cfg) ? $cfg : null;
}

/**
 * האם נתיב (יחסי לתיקיית הכלי) ציבורי לפי רשימת public של app.json.
 * תבנית: "view.html", "assets/**", "api.php?action=view|view-assembly".
 * '*' אינו חוצה '/', '**' כן.
 */
function is_public(array $cfg, string $path, array $params): bool
{
    foreach ($cfg['public'] ?? [] as $pattern) {
        if (!is_string($pattern)) continue;
        [$glob, $query] = array_pad(explode('?', $pattern, 2), 2, null);
        $re = '#^' . strtr(preg_quote($glob, '#'), ['\*\*' => '.*', '\*' => '[^/]*']) . '$#';
        if (!preg_match($re, $path)) continue;
        if ($query === null) return true;
        [$key, $values] = array_pad(explode('=', $query, 2), 2, '');
        if (in_array((string)($params[$key] ?? ''), explode('|', $values), true)) return true;
    }
    return false;
}

/** המשתמש המחובר: [id, role, status, username, display_name, email, email_verified_at, expires_at] או null. */
function current_user(): ?array
{
    static $cache = [];
    $token = $_COOKIE[COOKIE] ?? '';
    if (!is_string($token) || strlen($token) !== 64 || !is_file(DB_FILE)) return null;
    if (array_key_exists($token, $cache)) return $cache[$token];
    $st = pdo()->prepare(
        'SELECT u.id, u.role, u.status, u.username, u.display_name, u.email, u.email_verified_at, s.expires_at
           FROM sessions s JOIN users u ON u.id = s.user_id
          WHERE s.token_hash = ? AND s.expires_at > ?'
    );
    $st->execute([hash('sha256', $token), gmdate('Y-m-d\TH:i:s\Z')]);
    $u = $st->fetch(\PDO::FETCH_ASSOC);
    return $cache[$token] = ($u && $u['status'] === 'active' ? $u : null);
}

/**
 * חצי שנה מהשימוש האחרון: כל בקשה לאפליקציה מחוברת מאריכה את ההתחברות (פעם ביום לכל היותר, כדי
 * לא לכתוב למסד בכל בקשה). בלי זה מי שעובד רק באפליקציות, בלי לפתוח את הפלטפורמה, היה מתנתק.
 */
function touch_session(string $base): void
{
    try {
        $u = current_user();
        if (!$u || strtotime($u['expires_at']) > time() + (SESSION_DAYS - 1) * 86400 || headers_sent()) return;
        $expires = time() + SESSION_DAYS * 86400;
        pdo()->prepare('UPDATE sessions SET expires_at = ? WHERE token_hash = ?')
            ->execute([gmdate('Y-m-d\TH:i:s\Z', $expires), hash('sha256', (string)$_COOKIE[COOKIE])]);
        setcookie(COOKIE, (string)$_COOKIE[COOKIE], [
            'expires' => $expires, 'path' => $base, 'httponly' => true, 'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
    } catch (\Throwable $e) {
        error_log('platform gate session: ' . $e->getMessage());   // לעולם לא עוצר את הכלי
    }
}

/**
 * הזהות שהשער מעביר לאפליקציה מחוברת (docs/auth.md, סעיף 7): מי המשתמש, בלי redirect ובלי מסך.
 * רק למשתמש שיש לו הרשאה לאפליקציה. אותם שדות כמו בזהות החתומה של הכניסה המאוחדת (lib/sso.php).
 */
function identity(array $user): array
{
    $verified = !empty($user['email']) && !empty($user['email_verified_at']);
    $email = $verified ? $user['email'] : filter_var((string)$user['username'], FILTER_VALIDATE_EMAIL);
    return [
        'sub'            => (string)$user['id'],
        'name'           => (string)$user['display_name'],
        'email'          => $email ? mb_strtolower((string)$email) : null,
        'email_verified' => $verified,
    ];
}

function pdo(): \PDO
{
    static $pdo = null;
    return $pdo ??= new \PDO('sqlite:' . DB_FILE, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
}

/** האם המשתמש רשאי לאפליקציה — כמו can_see ב-access.php. */
function can_use(array $user, string $app, array $cfg): bool
{
    if ($user['role'] === 'admin') return true;
    $o = pdo()->prepare('SELECT category, hidden FROM app_overrides WHERE app_id = ?');
    try {
        $o->execute([$app]);
    } catch (\PDOException) {
        return false; // טבלאות ההרשאה עוד לא נוצרו — אין הרשאות
    }
    $override = $o->fetch(\PDO::FETCH_ASSOC) ?: [];
    if (!empty($override['hidden'])) return false;
    $category = $override['category'] ?? null ?: $cfg['category'];

    $g = pdo()->prepare('SELECT kind, target, allowed FROM grants WHERE user_id = ? AND
        ((kind = \'app\' AND target = ?) OR (kind = \'category\' AND target = ?))');
    $g->execute([$user['id'], $app, $category]);
    $byKind = [];
    foreach ($g->fetchAll(\PDO::FETCH_ASSOC) as $row) $byKind[$row['kind']] = (bool)$row['allowed'];
    if (array_key_exists('app', $byKind)) return $byKind['app'];
    return empty($cfg['restricted']) && ($byKind['category'] ?? false);
}

/**
 * ההכרעה. מחזיר null כשמותר; אחרת [קוד HTTP, סיבה].
 * @param array $params פרמטרי הבקשה (לתבניות כמו api.php?action=…)
 */
/**
 * המנהל ניתק את האפליקציה מהפלטפורמה (ממשק המנהל ← אפליקציות ← "🔌 מחוברת"): השער מעביר הכול,
 * ומעקב הכניסות לא נרשם — האפליקציה עומדת לבד, כמו אפליקציה חיצונית. הטבלה נוצרת ב-access.php;
 * עוד לא קיימת → אף אפליקציה לא מנותקת.
 */
function is_detached(string $app): bool
{
    if (!is_file(DB_FILE)) return false;
    try {
        $st = pdo()->prepare('SELECT 1 FROM app_detached WHERE app_id = ?');
        $st->execute([$app]);
        return (bool)$st->fetchColumn();
    } catch (\PDOException) {
        return false;
    }
}

function decide(string $app, string $path, array $params): ?array
{
    $cfg = app_config($app);
    if ($cfg === null || empty($cfg['gate'])) return null;      // לא רשום, או בלי שער
    if (is_detached($app)) return null;                          // נותקה מהפלטפורמה
    if (is_public($cfg, $path, $params)) return null;

    try {
        $user = current_user();
        if (!$user) return [401, 'login'];
        return can_use($user, $app, $cfg) ? null : [403, 'forbidden'];
    } catch (\Throwable $e) {
        error_log('platform gate: ' . $e->getMessage());
        return [503, 'error']; // נכשל סגור: תקלה במסד אינה פותחת את הכלי
    }
}

/** תשובת סירוב: דף לבני אדם, JSON לכל השאר. */
function deny(array $verdict, string $base): never
{
    [$code, $reason] = $verdict;
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isPage = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && str_contains($accept, 'text/html');

    if ($isPage && $reason === 'login') {
        $next = $_SERVER['REQUEST_URI'] ?? $base;
        header('Location: ' . $base . '?next=' . rawurlencode($next), true, 302);
        exit;
    }
    http_response_code($code);
    header('Cache-Control: no-store');
    $messages = [
        'login'     => 'נדרשת כניסה',
        'forbidden' => 'אין לך הרשאת גישה לאפליקציה הזו — פנו למנהל',
        'error'     => 'תקלה זמנית בבדיקת ההרשאה',
    ];
    if ($isPage) {
        header('Content-Type: text/html; charset=utf-8');
        $msg = htmlspecialchars($messages[$reason]);
        $home = htmlspecialchars($base);
        echo "<!doctype html><html lang=\"he\" dir=\"rtl\"><meta charset=\"utf-8\">"
           . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>$msg</title>"
           . "<body style=\"font-family:system-ui,sans-serif;text-align:center;padding:60px 20px;background:#f0f2f5\">"
           . "<h1 style=\"font-size:1.4rem\">$msg</h1><p><a href=\"$home\">חזרה לאפליקציות שלי</a></p></body></html>";
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $messages[$reason], 'gate' => $reason], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ---------- מעקב כניסות ----------
// כל פתיחה של דף באפליקציה (לא כל קובץ שנטען) נרשמת בטבלה visits. אותה כניסה
// של אותו משתמש לאותה אפליקציה בתוך 30 דקות נספרת פעם אחת. כשל ברישום לעולם
// לא עוצר את הכלי.

const VISIT_DEDUP_MIN = 30;
const VISIT_KEEP_DAYS = 365;
const VISIT_MAX_ROWS  = 200000;

/** האם הבקשה היא פתיחת דף (ניווט) — לא fetch, לא תמונה, לא iframe. */
function is_navigation(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;
    $dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';
    if ($dest !== '') return $dest === 'document';
    // דפדפן ישן בלי Sec-Fetch — לפי Accept
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html');
}

/** תיאור קצר של המכשיר מתוך User-Agent: "iPhone · Safari". */
function device_label(string $ua): string
{
    $os = match (true) {
        str_contains($ua, 'iPhone') => 'iPhone',
        str_contains($ua, 'iPad') => 'iPad',
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Mac OS X') => 'Mac',
        str_contains($ua, 'Linux') => 'Linux',
        default => 'אחר',
    };
    $browser = match (true) {
        str_contains($ua, 'Edg/') => 'Edge',
        str_contains($ua, 'SamsungBrowser') => 'Samsung',
        str_contains($ua, 'Firefox') || str_contains($ua, 'FxiOS') => 'Firefox',
        str_contains($ua, 'Chrome') || str_contains($ua, 'CriOS') => 'Chrome',
        str_contains($ua, 'Safari') => 'Safari',
        default => '',
    };
    if (str_contains($ua, '; wv)')) $browser = 'אפליקציה'; // WebView של אפליקציית אנדרואיד
    return $browser !== '' ? "$os · $browser" : $os;
}

/**
 * יומן אבחון (lib/diag.php): פתיחת דף באפליקציה מחוברת — "gate:ok", או הסירוב ("gate:login" …),
 * עם מה שהשרת רואה: האם עוגיית הכניסה נשלחה ותקפה, מאיפה הגיעה הבקשה.
 */
function diag_nav(string $event, string $app): void
{
    try {
        if (!is_navigation() || !is_file(DB_FILE)) return;
        require_once __DIR__ . '/diag.php';
        $user = current_user();
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        \Platform\Diag\write(pdo(), [
            'event' => $event, 'app' => $app, 'user_id' => $user ? (int)$user['id'] : null,
            'device' => device_label($ua), 'ua' => $ua,
            'path' => (string)($_SERVER['REQUEST_URI'] ?? ''), 'referrer' => (string)($_SERVER['HTTP_REFERER'] ?? ''),
            'info' => \Platform\Diag\request_info(isset($_COOKIE[COOKIE]), $user !== null),
        ]);
    } catch (\Throwable $e) {
        error_log('platform diag: ' . $e->getMessage());
    }
}

/** רושם כניסה לאפליקציה. נקרא אחרי שהשער התיר את הבקשה. */
function record_visit(string $app): void
{
    try {
        if (!is_navigation() || !is_file(DB_FILE)) return;
        $cfg = app_config($app);
        if ($cfg === null || empty($cfg['gate']) || is_detached($app)) return;
        $user = current_user();
        $uid = $user ? (int)$user['id'] : null;
        $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $app);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $since = gmdate('Y-m-d\TH:i:s\Z', time() - VISIT_DEDUP_MIN * 60);

        $db = pdo();
        $db->exec('PRAGMA busy_timeout = 2000');
        // אורח (דף ציבורי) מזוהה לפי IP — רק כדי לא לספור רענון כפול.
        $dup = $uid !== null
            ? $db->prepare('SELECT 1 FROM visits WHERE app = ? AND user_id = ? AND at > ? LIMIT 1')
            : $db->prepare('SELECT 1 FROM visits WHERE app = ? AND user_id IS NULL AND ip_hash = ? AND at > ? LIMIT 1');
        $dup->execute([$app, $uid ?? $ipHash, $since]);
        if ($dup->fetchColumn()) return;

        $db->prepare('INSERT INTO visits (app, user_id, at, device, ip_hash) VALUES (?,?,?,?,?)')
            ->execute([$app, $uid, $now, device_label((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), $ipHash]);

        // רוטציה: מדי פעם (בערך 1 מכל 200 כניסות) — מחיקת ישן משנה, ותקרה של שורות.
        if (random_int(1, 200) === 1) {
            $db->prepare('DELETE FROM visits WHERE at < ?')->execute([gmdate('Y-m-d\TH:i:s\Z', time() - VISIT_KEEP_DAYS * 86400)]);
            $db->exec('DELETE FROM visits WHERE id <= (SELECT MAX(id) FROM visits) - ' . VISIT_MAX_ROWS);
        }
    } catch (\Throwable $e) {
        // הטבלה עוד לא קיימת (המסד נוצר לפני השלב הזה) — נוצרת בפעם הבאה שהדף הראשי נטען.
        error_log('platform visits: ' . $e->getMessage());
    }
}

/** נתיב ה-URL של שורש האתר (התיקייה שמעל platform/), מתוך הבקשה הנוכחית. */
function base_url(string $scriptUrl, string $relativeScript): string
{
    $base = substr($scriptUrl, 0, strlen($scriptUrl) - strlen($relativeScript));
    return $base === '' ? '/' : $base;
}
