<?php
/**
 * מי המשתמש ומה התפקיד שלו (docs/spec.md, פרק 5).
 *
 * הזהות — רק מהשער: $_SERVER['PLATFORM_USER'] (JSON: sub, name, email, email_verified, platform_admin).
 * השער מעביר אותה רק למי שהבעלים אישר לכלי (restricted). התפקיד — מטבלת members, אישור שני.
 *   owner     — platform_admin: true. אוטומטי, לא בטבלה.
 *   publisher — עורך + פרסום לאתר.
 *   editor    — עריכה ושמירת טיוטות.
 * בלי תפקיד — נרשם ב"ביקשו גישה", ולא רואה כלום.
 *
 * CSRF: אין לכלי עוגייה משלו, ולכן האסימון הוא HMAC של ה-sub בסוד שבמסד. בקשה משנה חייבת גם
 * X-Kol (אתר זר לא יכול לשלוח כותרת כזו בלי preflight) וגם X-CSRF תואם.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const ROLES = ['editor' => 'עורך', 'publisher' => 'מפרסם'];
const ROLE_RANK = ['editor' => 1, 'publisher' => 2, 'owner' => 3];

function identity(): ?array
{
    $id = json_decode((string)($_SERVER['PLATFORM_USER'] ?? ''), true);
    if (!is_array($id) || !is_scalar($id['sub'] ?? null) || (string)$id['sub'] === '') return null;
    $email = is_string($id['email'] ?? null) && filter_var($id['email'], FILTER_VALIDATE_EMAIL) ? mb_strtolower($id['email']) : null;
    return [
        'sub' => (string)$id['sub'],
        'name' => mb_substr(trim((string)($id['name'] ?? '')), 0, 80),
        'email' => $email,
        'email_verified' => ($id['email_verified'] ?? false) === true,
        'admin' => ($id['platform_admin'] ?? false) === true,
    ];
}

function member_by_sub(string $sub): ?array
{
    $st = db()->prepare('SELECT * FROM members WHERE sub = ?');
    $st->execute([$sub]);
    return $st->fetch() ?: null;
}

/**
 * המשתמש הנוכחי: {sub, name, email, role|null}. חבר שהוזמן לפי מייל מתקשר ל-sub בכניסה הראשונה —
 * רק כשהפלטפורמה אימתה את המייל.
 */
function current_user(): ?array
{
    $id = identity();
    if (!$id) return null;
    $pdo = db();
    $m = member_by_sub($id['sub']);
    if (!$m && $id['email'] && $id['email_verified']) {
        $st = $pdo->prepare('UPDATE members SET sub = ? WHERE sub IS NULL AND email = ?');
        $st->execute([$id['sub'], $id['email']]);
        if ($st->rowCount()) {
            $m = member_by_sub($id['sub']);
            audit_as($id, 'member_linked', $id['email']);
        }
    }
    if ($m) {
        // שם ומייל מתעדכנים מהפלטפורמה; last_seen — פעם בדקה לכל היותר
        if ($m['name'] !== $id['name'] || ($id['email'] && $m['email'] !== $id['email']) || !$m['last_seen'] || strtotime($m['last_seen']) < time() - 60) {
            $pdo->prepare('UPDATE members SET name = ?, email = COALESCE(?, email), last_seen = ? WHERE id = ?')
                ->execute([$id['name'], $id['email'], now_iso(), $m['id']]);
        }
        $pdo->prepare('DELETE FROM access_requests WHERE sub = ?')->execute([$id['sub']]);
    }
    $role = $id['admin'] ? 'owner' : ($m['role'] ?? null);
    if (!$role) {
        $pdo->prepare('INSERT INTO access_requests (sub, name, email, at) VALUES (?, ?, ?, ?)
                       ON CONFLICT(sub) DO UPDATE SET name = excluded.name, email = excluded.email')
            ->execute([$id['sub'], $id['name'], $id['email'], now_iso()]);
    }
    return ['sub' => $id['sub'], 'name' => $id['name'], 'email' => $id['email'], 'role' => $role];
}

function require_role(?array $user, string $min): array
{
    if (!$user) throw new ApiError('נדרשת כניסה דרך הכלים הדיגיטליים', 401);
    if (!$user['role']) throw new ApiError('אין לך תפקיד בכלי. בקש מבעל המערכת', 403);
    if (ROLE_RANK[$user['role']] < ROLE_RANK[$min]) throw new ApiError('אין לך הרשאה לפעולה הזו', 403);
    return $user;
}

function csrf_secret(): string
{
    $s = setting('csrf_secret');
    if (!$s) {
        $s = bin2hex(random_bytes(32));
        setting_set('csrf_secret', $s);
    }
    return $s;
}

function csrf_token(string $sub): string
{
    return hash_hmac('sha256', 'csrf|' . $sub, csrf_secret());
}

function check_csrf(array $user): void
{
    if (($_SERVER['HTTP_X_KOL'] ?? '') !== '1' || !hash_equals(csrf_token($user['sub']), (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        throw new ApiError('הבקשה נדחתה. רענן את הדף', 403);
    }
}

function audit_as(array $who, string $action, string $detail = ''): void
{
    db()->prepare('INSERT INTO audit (at, sub, name, action, detail) VALUES (?, ?, ?, ?, ?)')
        ->execute([now_iso(), $who['sub'], $who['name'] ?? '', $action, mb_substr($detail, 0, 500)]);
}
