<?php
/**
 * יצירת המנהל הראשון.
 *
 * נועל את עצמו ברגע שקיים מנהל אחד. בלי הנעילה, הקובץ היה נשאר דלת
 * פתוחה בשרת שכל מי שמצא את כתובתה יכול ליצור לעצמו מנהל.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ui.php';

$exists = one('SELECT id FROM users WHERE is_admin = 1');
$msg = ''; $kind = 'bad';

if ($exists) {
    layoutTop('התקנה');
    note('כבר קיים מנהל במערכת. ההתקנה נעולה.', 'bad');
    echo '<p><a href="login.php">למסך הכניסה</a></p>';
    layoutEnd();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($e = usernameProblem($username))      $msg = $e;
    elseif ($e = passwordProblem($password))  $msg = $e;
    else {
        db()->beginTransaction();
        q('INSERT INTO users (username, display_name, password_hash, status, is_admin, created_at, approved_at)
           VALUES (?, ?, ?, ?, 1, ?, ?)',
          [$username, 'מנהל', hashPassword($password), 'active', nowIso(), nowIso()]);
        $uid = (int) db()->lastInsertId();
        q('INSERT INTO policies (user_id, mode, updated_at) VALUES (?, ?, ?)',
          [$uid, MODE_FREE, nowIso()]);
        db()->commit();

        startAdminSession();
        $_SESSION['admin_id'] = $uid;
        header('Location: index.php');
        exit;
    }
}

layoutTop('התקנה');
note($msg, $kind);
?>
<div class="card">
  <h2>יצירת המנהל הראשון</h2>
  <p class="hint">פעולה חד-פעמית. אחריה המסך הזה נועל את עצמו.</p>
  <form method="post">
    <label><span class="lbl">שם משתמש</span><input type="text" name="username" required autofocus></label>
    <label><span class="lbl">סיסמה (8 תווים לפחות)</span><input type="password" name="password" required></label>
    <button class="btn btn--go" type="submit">יצירה</button>
  </form>
</div>
<?php layoutEnd();
