<?php
/**
 * חיבור החשבון לכלים הדיגיטליים - מסך חד-פעמי
 * auth/link.php
 *
 * מי שנכנס דרך הכלים הדיגיטליים, ואין לו כאן חשבון מקושר (לא לפי
 * ה-sub ולא לפי מייל מאומת), מגיע לכאן פעם אחת - לפני שנפתח לו
 * חשבון חדש. ייתכן שיש לו חשבון ישן במייל אחר או בשם משתמש.
 * (platform/docs/auth.md, סעיף 4)
 *
 *   "יש לי כבר חשבון" - שם המשתמש והסיסמה הישנים, פעם אחת.
 *   "אין לי חשבון"   - חשבון חדש, פעיל.
 *
 * עם ?old=1 המסך פתוח גם למי שכבר מקושר - למי שנזכר אחר כך בחשבון
 * ישן. החשבון הנוכחי נשאר כמו שהוא, רק לא מקושר לאיש.
 */

require_once '../config.php';
require_once '../includes/rate_limit.php';
require_once '../includes/session.php';

$id = platformIdentity();

// מנותקת מהשער - אין למה לקשר. הכניסה הרגילה.
if (!$id) {
    header('Location: ' . APP_BASE_PATH . '/auth/login.php');
    exit;
}

/** סיום: ליעד שנשמר (למשל קישור הזמנה), או לדשבורד */
function finishLink() {
    platformSyncSession();
    $target = consumeLoginRedirect();
    header('Location: ' . ($target ?: APP_BASE_PATH . '/dashboard.php'));
    exit;
}

$pdo = getDBConnection();

try {
    $linked = platformLinkedUser($pdo, $id);
} catch (Exception $e) {
    // עמודת platform_sub עוד לא קיימת. בלי המיגרציה אי אפשר לקשר
    // אף אחד - וגם לא לפתוח חשבון, כי הוא היה נפתח בלי הקישור.
    error_log('platform link: ' . $e->getMessage());
    http_response_code(503);
    exit('האפליקציה בעדכון (מיגרציה 015 ממתינה). נסו שוב בעוד כמה דקות.');
}

$remember = isset($_GET['old']);
$blocked  = $linked && empty($linked['is_active']);
$error    = null;

if ($linked && !$blocked && !$remember && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    finishLink();
}

if (!$blocked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        rejectRequest();
    }

    $choice = $_POST['choice'] ?? '';

    if ($choice === 'old') {
        $error = platformLinkOldAccount($pdo, $id, $_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($error === '') {
            finishLink();
        }
    } elseif ($choice === 'new' && !$linked) {
        [$created, $error] = platformCreateUser($pdo, $id);
        if ($created) {
            finishLink();
        }
    }
}

$displayName = $id['name'] !== '' ? $id['name'] : $id['email'];
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>חיבור החשבון - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="<?php echo asset('/css/styles.css'); ?>" rel="stylesheet">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo APP_BASE_PATH; ?>/images/icons/ios/32.png">
    <style>
        .link-intro { margin-bottom: 20px; line-height: 1.6; }
        .link-choice { margin-top: 24px; padding-top: 20px; border-top: 1px solid #e5e7eb; }
        .link-choice p { margin: 0 0 12px; }
        .btn-secondary-link {
            width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #667eea;
            background: #fff; color: #667eea; font-size: 16px; cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1><i class="fas fa-shopping-cart"></i> <?php echo SITE_NAME; ?></h1>
        </div>

        <div class="login-body">
            <?php if ($blocked): ?>
                <div class="alert alert-error">
                    <i class="fas fa-ban"></i> החשבון שלך באפליקציה מושבת. פנו למנהל המערכת.
                </div>
            <?php else: ?>
                <p class="link-intro">
                    שלום <?php echo htmlspecialchars($displayName); ?>,
                    <?php if ($remember): ?>
                        אם יש לך חשבון ישן באפליקציה, אפשר לעבור אליו עכשיו. החשבון הנוכחי יישאר
                        כמו שהוא, אבל לא תיכנס אליו יותר.
                    <?php else: ?>
                        לא מצאנו כאן חשבון במייל של הכלים הדיגיטליים. אם כבר היה לך חשבון
                        באפליקציה (במייל אחר, או בשם משתמש), אפשר לחבר אותו - והאירועים שלך יחכו לך.
                    <?php endif; ?>
                </p>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="choice" value="old">

                    <div class="form-group">
                        <label for="username">שם המשתמש או המייל הישן</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="username" name="username"
                                   autocomplete="username" required>
                            <i class="fas fa-user"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password">הסיסמה הישנה</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="password" name="password"
                                   autocomplete="current-password" required>
                            <i class="fas fa-lock"></i>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">
                        <i class="fas fa-link"></i> יש לי כבר חשבון — לחבר אותו
                    </button>
                </form>

                <div class="link-choice">
                    <?php if ($remember): ?>
                        <a href="<?php echo APP_BASE_PATH; ?>/dashboard.php">חזרה, בלי לשנות</a>
                    <?php else: ?>
                        <p>נכנסת לראשונה? (חשבון ישן שנכנס עם Google במייל אחר — לא כאן: פנו למנהל המערכת)</p>
                        <form method="POST" action="">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="choice" value="new">
                            <button type="submit" class="btn-secondary-link">
                                <i class="fas fa-user-plus"></i> אין לי חשבון — להמשיך
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
