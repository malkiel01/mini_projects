<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ui.php';

if (!one('SELECT id FROM users WHERE is_admin = 1')) { header('Location: setup.php'); exit; }
if (currentAdmin()) { header('Location: index.php'); exit; }

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (tooManyFailures($username)) {
        $msg = 'יותר מדי ניסיונות. נסו שוב בעוד רבע שעה.';
    } else {
        $u = one('SELECT * FROM users WHERE username = ? AND is_admin = 1', [$username]);
        if ($u && password_verify($password, $u['password_hash']) && $u['status'] === 'active') {
            startAdminSession();
            // מזהה חדש לאחר כניסה — כך עוגייה שהושתלה מראש אינה שווה דבר.
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $u['id'];
            audit((int) $u['id'], 'admin_login', true, '', 'ok');
            header('Location: index.php');
            exit;
        }
        audit((int) ($u['id'] ?? 0), 'login_fail', false, '', 'admin', $username);
        $msg = 'שם משתמש או סיסמה שגויים';
    }
}

layoutTop('כניסה');
note($msg, 'bad');
?>
<div class="card">
  <h2>כניסת מנהל</h2>
  <form method="post">
    <label><span>שם משתמש</span><input type="text" name="username" required autofocus></label>
    <label><span>סיסמה</span><input type="password" name="password" required></label>
    <button class="btn btn--go" type="submit">כניסה</button>
  </form>
</div>
<?php layoutEnd();
