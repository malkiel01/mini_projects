<?php
/**
 * ניהול ה-IPs המורשים — הדף היחיד שאינו מאחורי שער ה-IP.
 *
 * זו כל נקודת הקיום שלו: כשה-IP הדינמי מתחלף, כל שאר המערכת נועלת אותך בחוץ
 * ואי אפשר לתקן בלי גישת FTP/SSH לשרת. לכן כאן השער הוא הסיסמה בלבד — ומכיוון
 * שהדף חשוף לאינטרנט, יש עליו הגבלת ניסיונות ותיעוד מלא של כל פעולה.
 */

require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/ipstore.php';

if (!Config::exists()) {
    header('Location: setup.php');
    exit;
}

Auth::start();

$ip       = Auth::clientIp();
$settings = Config::get('settings');
$msg = null;
$err = null;

$authed  = !empty($_SESSION['ip_admin']) && ($_SESSION['ip_admin_ip'] ?? null) === $ip;
$lock    = IpStore::throttle($ip);

/* ── התחברות / התנתקות ──────────────────────────────────────────── */

if (($_GET['logout'] ?? '') === '1') {
    unset($_SESSION['ip_admin'], $_SESSION['ip_admin_ip']);
    header('Location: ip-admin.php');
    exit;
}

if (!$authed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if ($lock['locked']) {
        $err = 'יותר מדי ניסיונות. נסה שוב מאוחר יותר.';
    } elseif (empty($settings['password_hash'])) {
        $err = 'לא הוגדרה סיסמה במערכת.';
    } elseif (password_verify($_POST['password'] ?? '', $settings['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['ip_admin'] = true;
        $_SESSION['ip_admin_ip'] = $ip;
        IpStore::clearFailures($ip);
        IpStore::log('login_ok', ['ip' => $ip]);
        header('Location: ip-admin.php');
        exit;
    } else {
        // עיכוב קבוע: מאט תוקף שמחליף כתובות ולכן חומק מהמונה הפר-IP,
        // בלי להסתכן בנעילת הבעלים.
        usleep(400000);
        $lock = IpStore::noteFailure($ip);
        $err = $lock['locked']
            ? 'יותר מדי ניסיונות. הגישה ננעלה זמנית.'
            : 'סיסמה שגויה.';
    }
}

/* ── פעולות (רק אחרי אימות) ─────────────────────────────────────── */

if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'login') {
    if (!Auth::csrfCheck($_POST['csrf'] ?? '')) {
        $err = 'CSRF token לא תקף. רענן את הדף ונסה שוב.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'add_mine') {
            $raw   = $action === 'add_mine' ? $ip : ($_POST['rule'] ?? '');
            $label = $action === 'add_mine' ? ($_POST['label'] ?? 'הכתובת שלי') : ($_POST['label'] ?? '');
            $r = IpStore::addRule($raw, $label, $ip);
            if ($r['ok']) $msg = 'נוסף: ' . $r['rule'];
            else $err = $r['error'];

        } elseif ($action === 'remove') {
            $rule = $_POST['rule'] ?? '';
            $remaining = array_values(array_diff(IpStore::rules(), [$rule]));

            // הרשימה ריקה = ללא הגבלה, ולכן מחיקת הכלל האחרון דווקא *פותחת* גישה.
            if (!IpStore::wouldAllow($ip, $remaining) && empty($_POST['confirm_lockout'])) {
                $err = 'מחיקת הכלל הזה תנעל אותך בחוץ — הכתובת הנוכחית שלך לא תתאים לאף כלל שנשאר. '
                     . 'סמן «אני מבין» כדי למחוק בכל זאת.';
            } else {
                $r = IpStore::removeRule($rule, $ip);
                if ($r['ok']) $msg = 'נמחק: ' . $rule;
                else $err = $r['error'];
            }

        } elseif ($action === 'clear_history') {
            IpStore::clearHistory($ip);
            $msg = 'ההיסטוריה נוקתה.';
        }
    }
}

$csrf      = Auth::csrfToken();
$rules     = IpStore::rules();
$meta      = IpStore::meta();
$history   = IpStore::history();
$ipAllowed = Auth::ipAllowedBy($ip, $rules);
$unlimited = empty($rules) || in_array('*', $rules, true);

function fmt_ts($ts) {
    return $ts ? date('d/m/Y H:i', (int)$ts) : '—';
}

$EVENT_LABELS = [
    'add'             => ['הוספה', '#1b5e20', '#e8f5e9'],
    'remove'          => ['מחיקה', '#b71c1c', '#ffebee'],
    'login_ok'        => ['כניסה', '#0d47a1', '#e3f2fd'],
    'login_fail'      => ['כישלון', '#e65100', '#fff3e0'],
    'locked'          => ['נעילה', '#b71c1c', '#ffebee'],
    'history_cleared' => ['ניקוי יומן', '#555', '#eef0f4'],
];
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>git-deploy · ניהול IPs</title>
<link rel="stylesheet" href="assets/style.css">
<style>
    .ip-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    .ip-table th { text-align: right; font-size: 0.78rem; color: #666; font-weight: 600; padding: 6px 8px; border-bottom: 2px solid #e5e7eb; white-space: nowrap; }
    .ip-table td { padding: 9px 8px; border-bottom: 1px solid #eef0f4; vertical-align: middle; }
    .ip-table tr:last-child td { border-bottom: none; }
    .ip-table .rule { font-family: ui-monospace, 'Courier New', monospace; direction: ltr; display: inline-block; }
    .row-mine { background: #f0f7ff; }
    .badge { display: inline-block; font-size: 0.7rem; padding: 2px 8px; border-radius: 10px; font-weight: 600; white-space: nowrap; }
    .now-box { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
    .now-ip { font-family: ui-monospace, 'Courier New', monospace; font-size: 1.35rem; font-weight: 700; direction: ltr; display: inline-block; }
    .inline-form { display: inline; }
    .add-grid { display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; align-items: end; }
    .add-grid .field { margin-bottom: 0; }
    .confirm-line { margin: 10px 0 0; font-size: 0.85rem; }
    @media (max-width: 640px) { .add-grid { grid-template-columns: 1fr; } }
</style>
</head>
<?php if (!$authed): ?>
<body class="page-auth">
<div class="auth-card">
    <h1>ניהול IPs</h1>
    <p class="subtitle">git-deploy</p>

    <?php if ($lock['locked']): ?>
        <div class="alert alert-error">
            הגישה מכתובת זו ננעלה בעקבות ניסיונות כושלים.<br>
            אפשר לנסות שוב אחרי <b><?= htmlspecialchars(fmt_ts($lock['until'])) ?></b>.
        </div>
    <?php else: ?>
        <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>
        <p class="muted small">
            הדף הזה מוגן בסיסמה בלבד ולא ברשימת ה-IPs — כדי שתוכל להיכנס דווקא כשהכתובת שלך נחסמה.
        </p>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="login">
            <div class="field">
                <label>סיסמת ניהול</label>
                <input type="password" name="password" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary">כניסה</button>
        </form>
    <?php endif; ?>

    <p class="muted small" style="margin-top:20px;text-align:center">
        IP נוכחי: <code dir="ltr"><?= htmlspecialchars($ip) ?></code>
    </p>
</div>
</body>
<?php else: ?>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <div class="brand"><span class="brand-icon">🚀</span><span>git-deploy · ניהול IPs</span></div>
        <nav class="topbar-nav">
            <a href="login.php" class="btn btn-ghost">← למסך הכניסה</a>
            <a href="ip-admin.php?logout=1" class="btn btn-ghost">יציאה</a>
        </nav>
    </div>
</header>

<main class="container">

    <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <section class="settings-section">
        <div class="now-box">
            <div>
                <div class="muted small">הכתובת שלך כרגע</div>
                <span class="now-ip"><?= htmlspecialchars($ip) ?></span>
                <div style="margin-top:6px">
                    <?php if ($ipAllowed): ?>
                        <span class="badge" style="background:#e8f5e9;color:#1b5e20">מורשית ✓</span>
                    <?php else: ?>
                        <span class="badge" style="background:#ffebee;color:#b71c1c">חסומה ✗</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!in_array($ip, $rules, true)): ?>
                <form method="post" class="inline-form">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="add_mine">
                    <input type="hidden" name="label" value="הכתובת שלי">
                    <button type="submit" class="btn btn-primary">הוסף את הכתובת שלי</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($unlimited): ?>
        <div class="alert alert-error">
            <b>הגישה כרגע פתוחה לכולם.</b>
            <?php if (empty($rules)): ?>
                הרשימה ריקה, ורשימה ריקה משמעותה ללא הגבלה.
            <?php else: ?>
                קיים כלל <code>*</code> שמתאים לכל כתובת.
            <?php endif; ?>
            הוסף כלל כדי לצמצם את הגישה.
        </div>
    <?php endif; ?>

    <section class="settings-section">
        <h2>כללים מורשים <span class="muted small">(<?= count($rules) ?>)</span></h2>

        <?php if (empty($rules)): ?>
            <p class="muted small">אין כללים. כל כתובת יכולה להגיע למסך הכניסה.</p>
        <?php else: ?>
            <table class="ip-table">
                <thead>
                    <tr><th>כלל</th><th>תווית</th><th>נוסף</th><th>ע"י</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($rules as $rule):
                    $m = $meta[$rule] ?? [];
                    $isMine = Auth::ipAllowedBy($ip, [$rule]);
                    $remaining = array_values(array_diff($rules, [$rule]));
                    $locksMeOut = !IpStore::wouldAllow($ip, $remaining);
                ?>
                    <tr class="<?= $isMine ? 'row-mine' : '' ?>">
                        <td>
                            <code class="rule"><?= htmlspecialchars($rule) ?></code>
                            <?php if ($isMine): ?>
                                <span class="badge" style="background:#e3f2fd;color:#0d47a1">מתאים לך</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($m['label'] ?? '') ?: '<span class="muted">—</span>' ?></td>
                        <td class="muted small"><?= htmlspecialchars(fmt_ts($m['added_at'] ?? 0)) ?></td>
                        <td class="muted small"><code dir="ltr"><?= htmlspecialchars($m['added_by'] ?? '—') ?></code></td>
                        <td style="text-align:left">
                            <form method="post" class="inline-form"
                                  onsubmit="return confirm('למחוק את הכלל <?= htmlspecialchars($rule, ENT_QUOTES) ?>?')">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="rule" value="<?= htmlspecialchars($rule) ?>">
                                <?php if ($locksMeOut): ?>
                                    <label class="confirm-line" style="display:block">
                                        <input type="checkbox" name="confirm_lockout" value="1"> אני מבין שזה ינעל אותי
                                    </label>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-danger-ghost">מחק</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="settings-section">
        <h2>הוספת כלל</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="add">
            <div class="add-grid">
                <div class="field">
                    <label>כלל</label>
                    <input type="text" name="rule" dir="ltr" required placeholder="1.2.3.4 / 1.2.3.0/24 / 1.2.3.*">
                    <small>כתובת בודדת, CIDR (IPv4 בלבד) או wildcard בסוף.</small>
                </div>
                <div class="field">
                    <label>תווית <span class="muted">(רשות)</span></label>
                    <input type="text" name="label" placeholder="בית / משרד / סלולרי">
                </div>
                <div class="field">
                    <button type="submit" class="btn btn-primary">הוסף</button>
                </div>
            </div>
        </form>
    </section>

    <section class="settings-section">
        <h2>
            היסטוריה
            <?php if (!empty($history)): ?>
                <form method="post" class="inline-form" style="float:left"
                      onsubmit="return confirm('לנקות את כל ההיסטוריה?')">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="clear_history">
                    <button type="submit" class="btn btn-danger-ghost">נקה</button>
                </form>
            <?php endif; ?>
        </h2>

        <?php if (empty($history)): ?>
            <p class="muted small">אין רשומות עדיין.</p>
        <?php else: ?>
            <table class="ip-table">
                <thead>
                    <tr><th>מתי</th><th>פעולה</th><th>כלל</th><th>מכתובת</th></tr>
                </thead>
                <tbody>
                <?php foreach ($history as $h):
                    $e = $EVENT_LABELS[$h['event'] ?? ''] ?? [$h['event'] ?? '?', '#555', '#eef0f4'];
                ?>
                    <tr>
                        <td class="muted small" style="white-space:nowrap"><?= htmlspecialchars(fmt_ts($h['ts'] ?? 0)) ?></td>
                        <td><span class="badge" style="background:<?= $e[2] ?>;color:<?= $e[1] ?>"><?= htmlspecialchars($e[0]) ?></span></td>
                        <td>
                            <?php if (!empty($h['rule'])): ?>
                                <code class="rule"><?= htmlspecialchars($h['rule']) ?></code>
                                <?php if (!empty($h['label'])): ?>
                                    <span class="muted small"><?= htmlspecialchars($h['label']) ?></span>
                                <?php endif; ?>
                            <?php else: ?><span class="muted">—</span><?php endif; ?>
                        </td>
                        <td class="muted small"><code dir="ltr"><?= htmlspecialchars($h['ip'] ?? '') ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="muted small" style="margin-top:10px">
                נשמרות עד <?= IpStore::HISTORY_MAX ?> רשומות אחרונות.
            </p>
        <?php endif; ?>
    </section>

</main>
</body>
<?php endif; ?>
</html>
