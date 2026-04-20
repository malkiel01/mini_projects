<?php
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/github.php';

$error = null;
$success = false;

if (Config::exists()) {
    // already configured - send to login
    header('Location: login.php');
    exit;
}

$currentIp = Auth::clientIp();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $token = trim($_POST['github_token'] ?? '');
    $ipsRaw = trim($_POST['allowed_ips'] ?? '');

    if (strlen($password) < 6) {
        $error = 'הסיסמה חייבת להיות לפחות 6 תווים.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'הסיסמאות לא תואמות.';
    } elseif (!$token) {
        $error = 'נדרש GitHub Personal Access Token.';
    } else {
        // verify token
        $gh = new GitHub($token);
        $me = $gh->me();
        if (!$me['ok']) {
            $error = 'הטוקן לא תקף או שאין הרשאות. ' . ($me['body']['message'] ?? '');
        } else {
            $ips = [];
            foreach (preg_split('/[\s,]+/', $ipsRaw) as $ip) {
                $ip = trim($ip);
                if ($ip !== '') $ips[] = $ip;
            }
            // always include current IP
            if (!in_array($currentIp, $ips, true)) array_unshift($ips, $currentIp);

            $cfg = Config::load();
            $cfg['settings'] = [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'allowed_ips' => $ips,
                'github_token' => $token,
                'github_user' => $me['body']['login'] ?? '',
                'created_at' => date('c'),
            ];
            Config::save($cfg);
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>git-deploy · התקנה</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="page-auth">
<div class="auth-card">
    <h1>git-deploy</h1>
    <p class="subtitle">התקנה ראשונית</p>

<?php if ($success): ?>
    <div class="alert alert-success">
        ההתקנה הושלמה. <a href="login.php">היכנס למערכת</a>.
    </div>
<?php else: ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <div class="field">
            <label>סיסמת ניהול</label>
            <input type="password" name="password" required minlength="6" autocomplete="new-password">
            <small>לפחות 6 תווים. תשמש לכל כניסה לממשק.</small>
        </div>

        <div class="field">
            <label>אימות סיסמה</label>
            <input type="password" name="password_confirm" required minlength="6" autocomplete="new-password">
        </div>

        <div class="field">
            <label>GitHub Personal Access Token</label>
            <input type="text" name="github_token" required placeholder="ghp_..." autocomplete="off" dir="ltr">
            <small>
                Fine-grained token עם הרשאת <code>Contents: Read</code> ו-<code>Metadata: Read</code> על הריפוז שתרצה לעדכן.
                <a href="https://github.com/settings/personal-access-tokens/new" target="_blank">צור חדש</a>
            </small>
        </div>

        <div class="field">
            <label>IPs מורשים</label>
            <textarea name="allowed_ips" rows="3" dir="ltr" placeholder="לדוגמה: 1.2.3.4, 5.6.0.0/16"></textarea>
            <small>
                ה-IP הנוכחי שלך <b dir="ltr"><?= htmlspecialchars($currentIp) ?></b> יתווסף אוטומטית.
                אפשר להוסיף עוד IPs (מופרדים בפסיק או רווח), כולל CIDR (<code>1.2.3.0/24</code>) או wildcard (<code>1.2.3.*</code>).
                השאר ריק כדי לא להגביל IP נוסף.
            </small>
        </div>

        <button type="submit" class="btn btn-primary">בצע התקנה</button>
    </form>
<?php endif; ?>
</div>
</body>
</html>
