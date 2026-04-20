<?php
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/auth.php';

if (!Config::exists()) {
    header('Location: setup.php');
    exit;
}

Auth::start();
$error = null;
$ip = Auth::clientIp();
$ipBlocked = !Auth::ipAllowed($ip);

if (Auth::isLoggedIn()) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $r = Auth::login($password);
    if ($r['ok']) {
        header('Location: index.php');
        exit;
    }
    if ($r['reason'] === 'ip_blocked') $error = 'ה-IP שלך לא מורשה.';
    elseif ($r['reason'] === 'bad_password') $error = 'סיסמה שגויה.';
    else $error = 'התחברות נכשלה.';
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>git-deploy · כניסה</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="page-auth">
<div class="auth-card">
    <h1>git-deploy</h1>
    <p class="subtitle">כניסה</p>

    <?php if ($ipBlocked): ?>
        <div class="alert alert-error">
            ה-IP שלך <b dir="ltr"><?= htmlspecialchars($ip) ?></b> לא מורשה.
            צריך להוסיף אותו לרשימה ב-<code>data/config.json</code> בשרת.
        </div>
    <?php else: ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <div class="field">
                <label>סיסמה</label>
                <input type="password" name="password" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary">כניסה</button>
        </form>
    <?php endif; ?>

    <p class="muted small" style="margin-top:20px;text-align:center">IP נוכחי: <code dir="ltr"><?= htmlspecialchars($ip) ?></code></p>
</div>
</body>
</html>
