<?php
/**
 * בדיקות לכניסה דרך הכלים הדיגיטליים
 * tests/platform_test.php
 *
 * רץ מול SQLite בזיכרון. הזהות מהשער מדומה ב-$_SERVER['PLATFORM_USER'],
 * והבקשה לפלטפורמה מוחלפת בפונקציה שמחזירה תשובה קבועה.
 *
 * הרצה: php tests/platform_test.php
 */

define('APP_BASE_PATH', '/mini_projects/family-shopping');

// ה-session נפתח כאן ומתחלף באמצע (platformLogin). בלי מאגר פלט,
// השורות שהבדיקה מדפיסה הן "כותרות שכבר נשלחו", ו-PHP מזהיר.
ob_start();

$pass = 0;
$fail = 0;

function check($label, $condition, $detail = '') {
    global $pass, $fail;

    if ($condition) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

function makeDb() {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->sqliteCreateFunction('NOW', function () { return date('Y-m-d H:i:s'); }, 0);

    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, email TEXT, name TEXT,
        password TEXT, profile_picture TEXT, is_active INTEGER DEFAULT 1,
        platform_sub TEXT UNIQUE, created_at TEXT, last_login TEXT)");
    $pdo->exec("CREATE TABLE admin_actions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER, action TEXT,
        target_type TEXT, target_id INTEGER, details TEXT)");
    $pdo->exec("CREATE TABLE app_settings (
        name TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");

    return $pdo;
}

$GLOBALS['db'] = makeDb();
function getDBConnection() {
    return $GLOBALS['db'];
}

/** הזהות שהשער היה כותב */
function identity($sub, $email = '', $verified = true, $name = 'דנה') {
    $_SERVER['PLATFORM_USER'] = json_encode([
        'sub' => $sub, 'name' => $name, 'email' => $email, 'email_verified' => $verified,
    ]);
}

function addUser(PDO $pdo, array $row) {
    $row += ['is_active' => 1, 'password' => null, 'platform_sub' => null];
    $pdo->prepare("INSERT INTO users (username, email, name, password, is_active, platform_sub)
                   VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$row['username'], $row['email'], $row['name'] ?? $row['username'],
                   $row['password'], $row['is_active'], $row['platform_sub']]);

    return (int)$pdo->lastInsertId();
}

function subOf(PDO $pdo, $userId) {
    $stmt = $pdo->prepare("SELECT platform_sub FROM users WHERE id = ?");
    $stmt->execute([$userId]);

    return $stmt->fetchColumn();
}

unset($_SERVER['PLATFORM_USER']);
require_once __DIR__ . '/../includes/session.php';

$pdo = $GLOBALS['db'];

echo "\nהזהות מהשער\n";

check('בלי PLATFORM_USER אין זהות', platformIdentity() === null);
$_SERVER['PLATFORM_USER'] = '{"sub":""}';
check('sub ריק אינו זהות', platformIdentity() === null);
identity('17', ' Dana@Example.COM ', true);
$id = platformIdentity();
check('המייל מנורמל לאותיות קטנות', $id['email'] === 'dana@example.com', $id['email']);
check('email_verified נשמר', $id['email_verified'] === true);
$_SERVER['PLATFORM_USER'] = '{"sub":"17","email_verified":"true"}';
check('email_verified שאינו true בוליאני - לא מאומת', platformIdentity()['email_verified'] === false);

echo "\nקישור לפי מייל\n";

$dana = addUser($pdo, ['username' => 'dana', 'email' => 'Dana@Example.com', 'password' => password_hash('secret1', PASSWORD_DEFAULT)]);
identity('17', 'dana@example.com', false);
check('מייל שלא אומת - אין קישור', platformLinkedUser($pdo, platformIdentity()) === null);
check('...והחשבון לא קושר', subOf($pdo, $dana) === null);

identity('17', 'dana@example.com', true);
$user = platformLinkedUser($pdo, platformIdentity());
check('מייל מאומת, בלי תלות באותיות - מקשר', $user && (int)$user['id'] === $dana);
check('ה-sub נשמר בחשבון', subOf($pdo, $dana) === '17');
$log = $pdo->query("SELECT action, details FROM admin_actions ORDER BY id DESC LIMIT 1")->fetch();
check('הקישור נרשם ביומן', $log && $log['action'] === 'platform_link' && str_starts_with($log['details'], 'email'));

identity('17', 'other@example.com', true);
$user = platformLinkedUser($pdo, platformIdentity());
check('אחרי הקישור - לפי sub, גם כשהמייל השתנה', $user && (int)$user['id'] === $dana);

addUser($pdo, ['username' => 'twin1', 'email' => 'twin@example.com']);
addUser($pdo, ['username' => 'twin2', 'email' => 'TWIN@example.com']);
identity('20', 'twin@example.com', true);
check('שני חשבונות באותו מייל - אין קישור אוטומטי', platformLinkedUser($pdo, platformIdentity()) === null);

echo "\nסנכרון ה-session\n";

identity('17', 'dana@example.com', true);
$_SESSION = ['csrf_token' => 'x', 'user_id' => 999, 'name' => 'מישהו אחר'];
platformSyncSession();
check('session של משתמש אחר מוחלף', (int)($_SESSION['user_id'] ?? 0) === $dana);
check('הכניסה מסומנת כפלטפורמה', ($_SESSION['auth_type'] ?? '') === 'platform' && $_SESSION['platform_sub'] === '17');

identity('99', 'nobody@example.com', true);
platformSyncSession();
check('זהות בלי חשבון - אין משתמש', !isset($_SESSION['user_id']));
check('...וה-CSRF נשאר', ($_SESSION['csrf_token'] ?? '') !== '');

$off = addUser($pdo, ['username' => 'off', 'email' => 'off@example.com', 'is_active' => 0]);
identity('30', 'off@example.com', true);
$_SESSION['user_id'] = $off;
platformSyncSession();
check('חשבון מושבת - אין משתמש', !isset($_SESSION['user_id']));
check('...והוא לא נחשב "אין חשבון" (לא ייפתח חדש באותו מייל)',
    ($row = platformLinkedUser($pdo, platformIdentity())) && (int)$row['id'] === $off);

unset($_SERVER['PLATFORM_USER']);
$_SESSION = ['csrf_token' => 'x', 'user_id' => 5];
platformSyncSession();
check('בלי זהות (מנותקת) - ה-session לא נגע', ($_SESSION['user_id'] ?? 0) === 5);

echo "\nחשבון חדש\n";

identity('40', 'new.person@example.com', true, 'נועה');
$created = platformCreateUser($pdo, platformIdentity());
check('נפתח חשבון פעיל', $created && (int)$created['is_active'] === 1);
check('מקושר ל-sub', $created['platform_sub'] === '40');
check('שם המשתמש מהמייל', $created['username'] === 'new.person', $created['username']);
check('השם מהפלטפורמה', $created['name'] === 'נועה');
check('בלי סיסמה', empty($created['password']));

identity('41', 'new.person@gmail.com', true);
$second = platformCreateUser($pdo, platformIdentity());
check('שם משתמש תפוס - סיומת', $second['username'] === 'new.person2', $second['username']);

identity('42', '', false, '');
$noMail = platformCreateUser($pdo, platformIdentity());
check('בלי מייל - שם משתמש מה-sub', $noMail['username'] === 'user42', $noMail['username']);

echo "\nחיבור חשבון ישן בסיסמה\n";

$old = addUser($pdo, ['username' => 'grandpa', 'email' => 'grandpa@old-isp.co.il',
                      'password' => password_hash('pass123', PASSWORD_DEFAULT)]);
identity('40', 'new.person@example.com', true);
$id = platformIdentity();

check('סיסמה שגויה - שגיאה', platformLinkOldAccount($pdo, $id, 'grandpa', 'wrong') !== '');
check('...והחשבון לא קושר', subOf($pdo, $old) === null);
check('חשבון בלי סיסמה (Google) - לא ניתן לקשר בסיסמה',
    platformLinkOldAccount($pdo, $id, 'twin1', '') !== '');

check('סיסמה נכונה (לפי מייל) - מתקשר', platformLinkOldAccount($pdo, $id, 'grandpa@old-isp.co.il', 'pass123') === '');
check('ה-sub עבר לחשבון הישן', subOf($pdo, $old) === '40');
check('החשבון שנפתח קודם נשאר, לא מקושר', subOf($pdo, (int)$created['id']) === null);

identity('50', 'someone@example.com', true);
check('חשבון שמקושר ל-sub אחר - לא ניתן לקחת',
    platformLinkOldAccount($pdo, platformIdentity(), 'grandpa', 'pass123') !== '');
check('...ונשאר אצל הראשון', subOf($pdo, $old) === '40');

$pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash('x1', PASSWORD_DEFAULT), $off]);
check('חשבון מושבת - לא מתקשר', platformLinkOldAccount($pdo, platformIdentity(), 'off', 'x1') !== '');

echo "\nהזמנה לפלטפורמה\n";

$payload = provisionKeyPayload($pdo);
check('המפתח הציבורי בתבנית של sso.md', $payload['client'] === 'family-shopping'
    && $payload['keys'][0]['alg'] === 'Ed25519' && $payload['keys'][0]['kid'] === PLATFORM_KID);
check('המפתח נשמר ולא מתחלף', provisionKeyPayload($pdo)['keys'][0]['key'] === $payload['keys'][0]['key']);

$sent = null;
$fake = function ($status, $body) use (&$sent) {
    return function ($b, $h) use (&$sent, $status, $body) {
        $sent = ['body' => $b, 'headers' => $h];
        return ['status' => $status, 'body' => $body];
    };
};

[$status, $sub, $error] = platformProvision($pdo, ' Guest@Example.com ', 'אורח', true,
    APP_BASE_PATH . '/join.php?token=abc', $fake(200, '{"status":"invited","sub":"77"}'));
check('תשובה invited', $status === 'invited' && $sub === '77' && $error === '');

$body = json_decode($sent['body'], true);
check('הגוף: מייל מנורמל, grant ו-next', $body['email'] === 'guest@example.com' && $body['grant'] === true
    && $body['next'] === '/mini_projects/family-shopping/join.php?token=abc');

$h = [];
foreach ($sent['headers'] as $line) {
    [$k, $v] = explode(': ', $line, 2);
    $h[$k] = $v;
}
[$kid, $sigB64] = explode('.', $h['X-Embed-Signature'], 2);
$msg = "provision\n{$h['X-Embed-Time']}\n{$h['X-Embed-Nonce']}\n" . hash('sha256', $sent['body']);
check('החתימה תקפה במפתח הציבורי', $kid === PLATFORM_KID && sodium_crypto_sign_verify_detached(
    base64_decode($sigB64), $msg, base64_decode($payload['keys'][0]['key'])));
check('X-Embed-Client ו-nonce בתבנית', $h['X-Embed-Client'] === 'family-shopping'
    && preg_match('/^[a-f0-9]{32}$/', $h['X-Embed-Nonce']));

[$status, , $error] = platformProvision($pdo, 'guest@example.com', '', true, '/x', $fake(500, '{"error":"תקלה"}'));
check('תשובה שאינה 200 - שגיאה', $status === '' && str_contains($error, 'תקלה'));
check('מייל לא תקין - בלי בקשה', platformProvision($pdo, 'not-mail', '', true, '/x')[2] !== '');

echo "\nהזמנה לאירוע\n";

unset($_SERVER['PLATFORM_USER']);
$sent = null;
$result = platformInviteToEvent($pdo, 'guest@example.com', 'אורח', 'tok', $fake(200, '{"status":"invited","sub":"77"}'));
check('מנותקת - אין בקשה לפלטפורמה', $result === ['', ''] && $sent === null);

identity('17', 'dana@example.com', true);
$guest = addUser($pdo, ['username' => 'guest', 'email' => 'Guest@example.com']);
$result = platformInviteToEvent($pdo, 'guest@example.com', 'אורח', 'tok 1', $fake(200, '{"status":"granted","sub":"77"}'));
check('מחוברת - הזמנה נשלחה', $result === ['granted', '']);
check('next הוא קישור ההצטרפות', json_decode($sent['body'], true)['next'] === APP_BASE_PATH . '/join.php?token=tok%201');
check('החשבון באותו מייל קושר ל-sub שחזר', subOf($pdo, $guest) === '77');

$result = platformInviteToEvent($pdo, 'blocked@example.com', '', 'tok', $fake(200, '{"status":"blocked","sub":"88"}'));
check('חסום - הסטטוס מוחזר', $result[0] === 'blocked');

$result = platformInviteToEvent($pdo, 'x@example.com', '', 'tok', $fake(502, ''));
check('הפלטפורמה לא ענתה - שגיאה, בלי לזרוק', $result[0] === '' && $result[1] !== '');

echo "\n=======================================================\n";
echo "עבר: $pass | נכשל: $fail\n";
exit($fail > 0 ? 1 : 0);
