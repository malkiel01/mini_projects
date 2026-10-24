<?php
/**
 * בדיקות השרת: php vector-studio/tests/run.php
 * רצות על מסד זמני; הזהות של השער מדומה ב-$_SERVER['PLATFORM_USER'].
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('DB_FILE', sys_get_temp_dir() . '/vector-studio-test-' . getmypid() . '.sqlite');
require_once __DIR__ . '/../lib/auth.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok): void {
    global $fails, $n;
    $n++;
    if (!$ok) { $fails++; echo "✗ $name\n"; } else echo "✓ $name\n";
}
function as_platform(?array $id): void {
    if ($id === null) unset($_SERVER['PLATFORM_USER']);
    else $_SERVER['PLATFORM_USER'] = json_encode($id);
}
function throws(callable $fn, int $status): bool {
    try { $fn(); return false; } catch (AppError $e) { return $e->status === $status; }
}

try {
    as_platform(null);
    check('בלי זהות מהשער — אין משתמש', currentUser() === null);
    check('בלי זהות — requireUser זורק 401', throws('requireUser', 401));

    as_platform(['sub' => '7', 'name' => 'דנה', 'email' => 'Dana@Example.com']);
    $a = currentUser();
    check('משתמש ראשון נפתח כמנהל', $a['role'] === 'admin' && $a['platform_sub'] === '7');
    check('המייל נשמר באותיות קטנות', $a['email'] === 'dana@example.com');
    check('כניסה חוזרת — אותו חשבון', (int) currentUser()['id'] === (int) $a['id']);

    as_platform(['sub' => '8', 'name' => 'יוסי', 'email' => 'yossi@example.com']);
    $b = currentUser();
    check('משתמש שני — עורך', $b['role'] === 'editor');
    check('החלפת משתמש באותו דפדפן מחליפה סשן', $_SESSION['uid'] === (int) $b['id']);

    // חשבון קיים במייל, בלי קישור — מתקשר ל-sub החדש.
    db()->prepare("INSERT INTO users (email, name, created_at) VALUES ('old@example.com', 'ותיק', ?)")->execute([nowIso()]);
    as_platform(['sub' => '9', 'email' => 'OLD@example.com']);
    $c = currentUser();
    check('איחוד לפי מייל', $c['name'] === 'ותיק' && $c['platform_sub'] === '9');

    // מייל שכבר מקושר ל-sub אחר — חסום.
    as_platform(['sub' => '10', 'email' => 'dana@example.com']);
    check('מייל מקושר ל-sub אחר — 403', throws('currentUser', 403));

    as_platform(['sub' => '11', 'name' => '']);
    $d = currentUser();
    check('בלי מייל — כתובת פנימית', str_ends_with($d['email'], '@platform.invalid') && $d['name'] === 'משתמש');

    db()->prepare('UPDATE users SET blocked = 1 WHERE id = ?')->execute([$b['id']]);
    as_platform(['sub' => '8']);
    check('חשבון חסום — 403', throws('currentUser', 403));

    as_platform(['sub' => '']);
    check('sub ריק — אין משתמש', currentUser() === null);
    $_SERVER['PLATFORM_USER'] = 'not json';
    check('זהות פגומה — אין משתמש', currentUser() === null);

    check('publicUser לא חושף sub', !array_key_exists('platform_sub', publicUser($a)));
} finally {
    foreach (glob(DB_FILE . '*') ?: [] as $f) @unlink($f);
}

echo "\n" . ($n - $fails) . " / $n עברו\n";
exit($fails ? 1 : 0);
