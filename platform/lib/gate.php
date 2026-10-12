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

/** המשתמש המחובר: [id, role, status] או null. */
function current_user(): ?array
{
    $token = $_COOKIE[COOKIE] ?? '';
    if (!is_string($token) || strlen($token) !== 64 || !is_file(DB_FILE)) return null;
    $st = pdo()->prepare(
        'SELECT u.id, u.role, u.status FROM sessions s JOIN users u ON u.id = s.user_id
          WHERE s.token_hash = ? AND s.expires_at > ?'
    );
    $st->execute([hash('sha256', $token), gmdate('Y-m-d\TH:i:s\Z')]);
    $u = $st->fetch(\PDO::FETCH_ASSOC);
    return $u && $u['status'] === 'active' ? $u : null;
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
function decide(string $app, string $path, array $params): ?array
{
    $cfg = app_config($app);
    if ($cfg === null || empty($cfg['gate'])) return null;      // לא רשום, או בלי שער
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
        'forbidden' => 'אין לך הרשאה לאפליקציה הזו',
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

/** נתיב ה-URL של שורש האתר (התיקייה שמעל platform/), מתוך הבקשה הנוכחית. */
function base_url(string $scriptUrl, string $relativeScript): string
{
    $base = substr($scriptUrl, 0, strlen($scriptUrl) - strlen($relativeScript));
    return $base === '' ? '/' : $base;
}
