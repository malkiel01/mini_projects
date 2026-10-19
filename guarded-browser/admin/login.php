<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ui.php';

$msg = '';
$id = platformIdentity();

/*
 * מחוברת לפלטפורמה: אין כניסה בסיסמה. מי שהזהות שלו עוד לא קושרה מגיע לכאן — המסך החד-פעמי
 * (platform/docs/auth.md, סעיף 4). מי שכבר מחובר — ‏?link=1 מהתפריט, למי שנזכר אחר כך בחשבון ישן.
 */
if ($id) {
    $me = currentAdmin();
    $linking = isset($_GET['link']);
    if ($me && !$linking) { header('Location: index.php'); exit; }
    $row = platformLinked('admin', $id);
    if ($row && $row['status'] !== 'active' && !$linking) {
        layoutTop('כניסה');
        note('החשבון שלך בפאנל מושעה. פנו למנהל המערכת.', 'bad');
        layoutEnd();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // לפני החיבור אין session, ולכן אין CSRF — כמו בכניסה הרגילה. אחריו — כרגיל.
        if ($me) checkCsrf();
        if (($_POST['choice'] ?? '') === 'new') {
            if (!$row) platformCreate('admin', $id);
            header('Location: index.php');
            exit;
        }
        $msg = platformLinkOld('admin', $id, (string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($msg === '') { forgetSessions(); header('Location: index.php'); exit; }
    }

    layoutTop('חיבור חשבון', $me);
    note($msg, 'bad');
    ?>
<div class="card">
  <h2>יש לך כבר חשבון בפאנל?</h2>
  <p class="hint">פעם אחת: אם נכנסת לכאן בעבר בשם משתמש וסיסמה, היכנס בהם עכשיו — החשבון יחובר
    לחשבון שלך בכלים הדיגיטליים, ומכאן הכניסה תהיה אוטומטית.</p>
  <form method="post">
    <?php if ($me): ?><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><?php endif; ?>
    <label><span class="lbl">שם משתמש</span><input type="text" name="username" required autofocus autocomplete="username"></label>
    <label><span class="lbl">סיסמה</span><input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn btn--go" type="submit">חיבור החשבון הישן</button>
  </form>
</div>
<?php if (!$me): ?>
<div class="card">
  <form method="post">
    <input type="hidden" name="choice" value="new">
    <button class="btn" type="submit">אין לי חשבון — פתיחת חשבון חדש</button>
  </form>
</div>
<?php endif;
    layoutEnd();
    exit;
}

/* ── מנותקת: הכניסה הישנה בסיסמה ── */
if (!one('SELECT id FROM users WHERE is_admin = 1')) { header('Location: setup.php'); exit; }
if (currentAdmin()) { header('Location: index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (tooManyFailures($username)) {
        $msg = 'יותר מדי ניסיונות. נסו שוב בעוד רבע שעה.';
    } elseif (!passwordLoginOpen()) {
        $msg = 'הכניסה בסיסמה כבויה — היכנסו דרך הכלים הדיגיטליים';
    } else {
        $u = one('SELECT * FROM users WHERE username = ? AND is_admin = 1', [$username]);
        if ($u && password_verify($password, $u['password_hash']) && $u['status'] === 'active') {
            startAdminSession((int) $u['id']);
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
    <label><span class="lbl">שם משתמש</span><input type="text" name="username" required autofocus></label>
    <label><span class="lbl">סיסמה</span><input type="password" name="password" required></label>
    <button class="btn btn--go" type="submit">כניסה</button>
  </form>
</div>
<?php layoutEnd();
