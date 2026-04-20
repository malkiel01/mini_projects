<?php
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/github.php';

Auth::require_();

$cfg = Config::load();
$settings = $cfg['settings'];
$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::csrfCheck($_POST['csrf'] ?? '')) {
        $err = 'CSRF token לא תקף.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            if (!password_verify($current, $settings['password_hash'])) $err = 'הסיסמה הנוכחית שגויה.';
            elseif (strlen($new) < 6) $err = 'הסיסמה החדשה חייבת לפחות 6 תווים.';
            elseif ($new !== $confirm) $err = 'אישור הסיסמה לא תואם.';
            else {
                $cfg['settings']['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
                Config::save($cfg);
                $msg = 'הסיסמה עודכנה.';
                $settings = $cfg['settings'];
            }
        } elseif ($action === 'change_ips') {
            $raw = trim($_POST['allowed_ips'] ?? '');
            $ips = [];
            foreach (preg_split('/[\s,]+/', $raw) as $ip) {
                $ip = trim($ip);
                if ($ip !== '') $ips[] = $ip;
            }
            $cfg['settings']['allowed_ips'] = $ips;
            Config::save($cfg);
            $msg = 'רשימת IPs עודכנה.';
            $settings = $cfg['settings'];
        } elseif ($action === 'change_token') {
            $token = trim($_POST['github_token'] ?? '');
            if (!$token) $err = 'נדרש טוקן.';
            else {
                $gh = new GitHub($token);
                $me = $gh->me();
                if (!$me['ok']) $err = 'הטוקן לא תקף: ' . ($me['body']['message'] ?? '');
                else {
                    $cfg['settings']['github_token'] = $token;
                    $cfg['settings']['github_user'] = $me['body']['login'] ?? '';
                    Config::save($cfg);
                    $msg = 'הטוקן עודכן (משתמש: ' . htmlspecialchars($me['body']['login']) . ').';
                    $settings = $cfg['settings'];
                }
            }
        }
    }
}

$csrf = Auth::csrfToken();
$currentIp = Auth::clientIp();
$tokenMasked = $settings['github_token'] ? '•••••••' . substr($settings['github_token'], -4) : '(לא מוגדר)';
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>git-deploy · הגדרות</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <div class="brand"><span class="brand-icon">🚀</span><span>git-deploy</span></div>
        <nav class="topbar-nav">
            <a href="index.php" class="btn btn-ghost">← חזרה לדשבורד</a>
            <a href="logout.php" class="btn btn-ghost">יציאה</a>
        </nav>
    </div>
</header>

<main class="container">
    <h1>הגדרות</h1>

    <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <section class="settings-section">
        <h2>סיסמת ניהול</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="change_password">
            <div class="field"><label>סיסמה נוכחית</label><input type="password" name="current_password" required></div>
            <div class="field"><label>סיסמה חדשה</label><input type="password" name="new_password" required minlength="6"></div>
            <div class="field"><label>אישור</label><input type="password" name="confirm_password" required minlength="6"></div>
            <button type="submit" class="btn btn-primary">עדכן סיסמה</button>
        </form>
    </section>

    <section class="settings-section">
        <h2>IPs מורשים</h2>
        <p class="muted small">ה-IP הנוכחי שלך: <code dir="ltr"><?= htmlspecialchars($currentIp) ?></code></p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="change_ips">
            <div class="field">
                <label>רשימה (מופרדים בפסיק או שורות)</label>
                <textarea name="allowed_ips" rows="4" dir="ltr" placeholder="ריק = ללא הגבלה"><?= htmlspecialchars(implode("\n", $settings['allowed_ips'] ?? [])) ?></textarea>
                <small>תומך ב-<code>1.2.3.4</code>, CIDR <code>1.2.3.0/24</code>, ו-wildcard <code>1.2.3.*</code>.</small>
            </div>
            <button type="submit" class="btn btn-primary">עדכן IPs</button>
        </form>
    </section>

    <section class="settings-section">
        <h2>GitHub Token</h2>
        <p class="muted small">משתמש: <code><?= htmlspecialchars($settings['github_user'] ?: '?') ?></code> · נוכחי: <code><?= htmlspecialchars($tokenMasked) ?></code></p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="change_token">
            <div class="field">
                <label>טוקן חדש</label>
                <input type="text" name="github_token" required dir="ltr" placeholder="ghp_...">
                <small>הטוקן ייבדק מול GitHub לפני שמירה.</small>
            </div>
            <button type="submit" class="btn btn-primary">עדכן טוקן</button>
        </form>
    </section>

    <section class="settings-section">
        <h2>מצב מערכת</h2>
        <p class="muted small">הוקמה: <?= htmlspecialchars($settings['created_at'] ?? '?') ?></p>
        <p class="muted small">קונפיגורציה ב: <code dir="ltr"><?= htmlspecialchars(realpath(__DIR__ . '/data/config.json') ?: '(טרם נוצרה)') ?></code></p>
    </section>
</main>
</body>
</html>
