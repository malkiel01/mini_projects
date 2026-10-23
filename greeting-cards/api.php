<?php
/**
 * API for Greeting Cards Generator
 * Auth, contacts, font uploads, template saving, background gallery
 */

session_start();

header('Content-Type: application/json; charset=utf-8');
// ‏בלי CORS: האפליקציה (גם ב-APK) פונה מאותו מקור. ‏`*` עם credentials פתח את ה-API לכל אתר.

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ‏const ברמת קובץ מתבצע לפי סדר ואינו מוקדם אוטומטית, ולכן
// ההצהרות חייבות להיות לפני ה-switch שמפעיל את ה-handlers.

/** שמות הנכסים המותרים בעיצוב: שכבת הציור, ועד 100 מדבקות. */
const DESIGN_SLOT_RE = '/^(paint|s\d{1,2})$/';

/** הפורמטים המותרים לכל תמונה. SVG אינו ברשימה — הוא נושא סקריפט. */
const DESIGN_IMAGE_EXT = ['jpg', 'png', 'webp'];

/** תמונות שמותר להעלות. SVG אינו ברשימה — הוא מוגש מאותו מקור ונושא סקריפט. */
const UPLOAD_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'gif', 'tiff', 'tif', 'heic', 'heif', 'avif'];

/**
 * מזהה שמגיע מהבקשה ונכנס לנתיב קובץ. ‏uniqid מייצר <קידומת>_<hex>, ולכן כל דבר אחר — כולל
 * ‏`../` — נדחה לפני שהוא נוגע במערכת הקבצים.
 */
const ID_RE = '/^[a-z]{2,5}_[a-f0-9]{8,32}$/';

/** סיסמת הניהול הישנה. מכניסה עדיין (אחרת בעל המערכת ננעל בחוץ), אבל מחייבת החלפה מיד. */
const ADMIN_DEFAULT_PASSWORD = 'admin123';

$dataDir = __DIR__ . '/data';
$usersDir = $dataDir . '/users';
$fontsDir = __DIR__ . '/fonts';
$templatesDir = __DIR__ . '/templates';
$backgroundsDir = __DIR__ . '/backgrounds';

foreach ([$dataDir, $usersDir, $fontsDir, $templatesDir, $backgroundsDir] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// Get input
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = null;
$rawBody = file_get_contents('php://input');
if ($rawBody) $input = json_decode($rawBody, true);
if (!$input) $input = $_POST;

// Token-based auth (header or parameter)
$token = $_SERVER['HTTP_X_AUTH_TOKEN']
    ?? $_GET['token'] ?? $input['token'] ?? $_COOKIE['gc_token'] ?? '';

// מחוברת לפלטפורמה: השער כותב את הזהות ל-PLATFORM_USER (רק למי שיש לו הרשאה). היא קובעת מי
// המשתמש — האסימון שהדפדפן שלח לא נחשב, כך שאי אפשר להיכנס לחשבון אחר. ראו platform/docs/sso.md.
$platformUser = platformAccount($usersDir);
if ($platformUser) $token = $platformUser['token'];

try {
    // כניסה בסיסמה של האפליקציה חסומה כשהיא מחוברת לפלטפורמה.
    if ($platformUser && in_array($action, ['register', 'login', 'logout'], true)) {
        respond(false, 'הכניסה היא דרך הכלים הדיגיטליים', ['platform' => true]);
    }

    switch ($action) {
        // ─── Auth ────────────────────────────────────
        case 'register':    requirePasswordLogin($dataDir); handleRegister($usersDir, $input); break;
        case 'login':       requirePasswordLogin($dataDir); handleLogin($usersDir, $input); break;
        case 'logout':      handleLogout(); break;
        case 'check_auth':  handleCheckAuth($usersDir, $token, $platformUser); break;
        case 'link_account':    linkOldAccount($usersDir, $platformUser, $input); break;
        case 'dismiss_link':    dismissLink($usersDir, $platformUser); break;

        // ─── Contacts (require auth) ────────────────
        case 'save_contacts':   requireAuth($usersDir, $token); saveContacts($usersDir, $token, $input); break;
        case 'load_contacts':   requireAuth($usersDir, $token); loadContacts($usersDir, $token); break;
        case 'delete_contact':  requireAuth($usersDir, $token); deleteContact($usersDir, $token, $input); break;

        // ─── User Data (require auth) ───────────────
        case 'save_user_data':  requireAuth($usersDir, $token); saveUserData($usersDir, $token, $input); break;
        case 'load_user_data':  requireAuth($usersDir, $token); loadUserData($usersDir, $token); break;

        // ─── Fonts — המאגר משותף: הרשימה והקריאה לכולם, העלאה רק למחובר, ומחיקה רק לבעלים או למנהל
        case 'upload_font':     requireAuth($usersDir, $token); handleFontUpload($fontsDir, currentUsername($usersDir, $token)); break;
        case 'list_fonts':      listFonts($fontsDir); break;
        case 'get_font':        getFont($fontsDir); break;
        case 'delete_font':     requireAuth($usersDir, $token); deleteFont($fontsDir, currentUsername($usersDir, $token)); break;

        // ─── Templates — האפליקציה לא משתמשת בהן; כתיבה רק למנהל ─────
        case 'save_template':   requireAdmin(); saveTemplate($templatesDir); break;
        case 'list_templates':  listTemplates($templatesDir); break;
        case 'get_template':    getTemplate($templatesDir); break;
        case 'delete_template': requireAdmin(); deleteTemplate($templatesDir); break;

        // ─── Backgrounds (global defaults) — כתיבה רק למנהל ──────────
        case 'upload_background':   requireAdmin(); handleBackgroundUpload($backgroundsDir); break;
        case 'list_backgrounds':    listBackgrounds($backgroundsDir); break;
        case 'delete_background':   requireAdmin(); deleteBackground($backgroundsDir); break;

        // ─── User Backgrounds (per-user) ────────────
        case 'upload_user_bg':      requireAuth($usersDir, $token); uploadUserBackground($usersDir, $token); break;
        case 'upload_user_bg_zip':  requireAuth($usersDir, $token); uploadUserBackgroundZip($usersDir, $token); break;
        case 'list_user_bgs':       requireAuth($usersDir, $token); listUserBackgrounds($usersDir, $token); break;
        case 'delete_user_bg':      requireAuth($usersDir, $token); deleteUserBackground($usersDir, $token, $input); break;
        case 'get_user_bg':         requireAuth($usersDir, $token); getUserBackground($usersDir, $token); break;

        // ─── Designs (per-user saved layouts) ───────
        case 'save_design':         requireAuth($usersDir, $token); saveDesign($usersDir, $token, $input); break;
        case 'list_designs':        requireAuth($usersDir, $token); listDesigns($usersDir, $token); break;
        case 'get_design':          requireAuth($usersDir, $token); getDesign($usersDir, $token); break;
        case 'get_design_bg':       requireAuth($usersDir, $token); getDesignBackground($usersDir, $token); break;
        case 'delete_design':       requireAuth($usersDir, $token); deleteDesign($usersDir, $token, $input); break;

        // ─── Admin (global defaults management) ─────
        case 'admin_check':         adminCheck($dataDir, $input); break;
        case 'admin_set_password':  requireAdmin(); adminSetPassword($dataDir, $input); break;
        case 'admin_upload_bg':     requireAdmin(); handleBackgroundUpload($backgroundsDir); break;
        case 'admin_upload_bg_zip': requireAdmin(); adminUploadBgZip($backgroundsDir); break;
        case 'admin_delete_bg':     requireAdmin(); deleteBackground($backgroundsDir); break;
        case 'admin_upload_font':   requireAdmin(); handleFontUpload($fontsDir, null); break;
        case 'admin_delete_font':   requireAdmin(); deleteFont($fontsDir, null); break;
        case 'admin_list_users':    requireAdmin(); adminListUsers($usersDir); break;

        default:
            respond(false, 'Unknown action: ' . $action);
    }
} catch (Exception $e) {
    respond(false, $e->getMessage());
}

// ═══════════════════════════════════════════════════════
// Auth Functions
// ═══════════════════════════════════════════════════════

/**
 * כניסה והרשמה בסיסמה של האפליקציה — רק כשהיא מנותקת מהשער, והמנהל השאיר את "סיסמה משלה" פתוחה
 * (⚙️ ← אפליקציות). ההגדרה נקראת מהפלטפורמה (sso.php?action=config) ונשמרת לדקה. הפלטפורמה לא
 * זמינה — ההגדרה האחרונה שנקראה; אף פעם לא נקראה — פתוח, כדי שאפליקציה שיצאה לדרך עצמאית לא תינעל.
 */
function requirePasswordLogin($dataDir) {
    $cache = $dataDir . '/login-config.json';
    $cached = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
    $login = $cached['login'] ?? null;
    if (!$cached || time() - (int)($cached['at'] ?? 0) > 60) {
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
        $raw = @file_get_contents('https://mbe-plus.com/mini_projects/platform/sso.php?action=config&client=greeting-cards', false, $ctx);
        $config = $raw ? json_decode($raw, true) : null;
        if (is_array($config['login'] ?? null)) {
            $login = $config['login'];
            file_put_contents($cache, json_encode(['at' => time(), 'login' => $login]));
        }
    }
    if (is_array($login) && empty($login['password'])) {
        respond(false, 'הכניסה בסיסמה כבויה — היכנסו דרך הכלים הדיגיטליים', ['platform' => true]);
    }
}

function handleRegister($usersDir, $input) {
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $displayName = trim($input['displayName'] ?? $username);

    if (strlen($username) < 2) respond(false, 'שם משתמש חייב להיות לפחות 2 תווים');
    if (strlen($password) < 4) respond(false, 'סיסמה חייבת להיות לפחות 4 תווים');
    if (!preg_match('/^[a-zA-Z0-9_\-\p{Hebrew}]+$/u', $username)) {
        respond(false, 'שם משתמש יכול להכיל אותיות, מספרים, קו תחתון ומקף בלבד');
    }

    $userDir = $usersDir . '/' . safeFilename($username);
    if (is_dir($userDir)) respond(false, 'שם משתמש כבר קיים');

    mkdir($userDir, 0755, true);

    $token = bin2hex(random_bytes(32));

    $profile = [
        'username' => $username,
        'displayName' => $displayName,
        'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
        'token' => $token,
        'created' => date('c'),
        'lastLogin' => date('c'),
    ];

    file_put_contents($userDir . '/profile.json', json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    file_put_contents($userDir . '/contacts.json', json_encode([], JSON_UNESCAPED_UNICODE));
    file_put_contents($userDir . '/user_data.json', json_encode([], JSON_UNESCAPED_UNICODE));

    setTokenCookie($token);

    respond(true, 'נרשמת בהצלחה!', [
        'token' => $token,
        'username' => $username,
        'displayName' => $displayName,
    ]);
}

function handleLogin($usersDir, $input) {
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    $userDir = $usersDir . '/' . safeFilename($username);
    $profileFile = $userDir . '/profile.json';

    if (!file_exists($profileFile)) respond(false, 'שם משתמש או סיסמה שגויים');

    $profile = json_decode(file_get_contents($profileFile), true);
    if (!password_verify($password, $profile['passwordHash'])) {
        respond(false, 'שם משתמש או סיסמה שגויים');
    }

    // Generate new token
    $token = bin2hex(random_bytes(32));
    $profile['token'] = $token;
    $profile['lastLogin'] = date('c');
    file_put_contents($profileFile, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    setTokenCookie($token);

    respond(true, 'התחברת בהצלחה!', [
        'token' => $token,
        'username' => $profile['username'],
        'displayName' => $profile['displayName'] ?? $profile['username'],
    ]);
}

function handleLogout() {
    setcookie('gc_token', '', time() - 3600, '/');
    respond(true, 'התנתקת');
}

function handleCheckAuth($usersDir, $token, $platformUser = null) {
    if (!$token) respond(false, 'לא מחובר');
    $profile = findUserByToken($usersDir, $token);
    if (!$profile) respond(false, 'לא מחובר');
    $canLink = $platformUser && isAutoAccount($profile);
    respond(true, 'מחובר', [
        'token' => $token,
        'username' => $profile['username'],
        'displayName' => $profile['displayName'] ?? $profile['username'],
        'platform' => (bool)$platformUser,
        // חשבון שנפתח לבד בכניסה הראשונה — אפשר עדיין לחבר אליו חשבון ישן.
        'canLink' => $canLink,
        'offerLink' => $canLink && empty($profile['linkDismissed']),
    ]);
}

/** חשבון שנפתח לבד למשתמש פלטפורמה (ולא חשבון ישן שחובר אליו). */
function isAutoAccount($profile) {
    return strpos(basename($profile['_dir'] ?? ''), 'platform_') === 0;
}

/**
 * חיבור חשבון ישן (שם משתמש וסיסמה מלפני השער) למשתמש הפלטפורמה. מכאן והלאה הוא החשבון שלו,
 * והחשבון שנפתח לבד מתנתק. 5 ניסיונות שגויים → נעילה של 15 דקות, כדי שאי אפשר יהיה לנחש סיסמאות.
 */
function linkOldAccount($usersDir, $platformUser, $input) {
    if (!$platformUser) respond(false, 'החיבור זמין רק בכניסה דרך הכלים הדיגיטליים');
    $autoFile = $platformUser['_file'];
    $auto = json_decode(file_get_contents($autoFile), true);
    if (!isAutoAccount(['_dir' => dirname($autoFile)])) respond(false, 'כבר חובר חשבון');

    $lockedUntil = (int)($auto['linkLockedUntil'] ?? 0);
    if ($lockedUntil > time()) respond(false, 'יותר מדי ניסיונות. נסו שוב בעוד ' . ceil(($lockedUntil - time()) / 60) . ' דקות');

    $username = trim($input['username'] ?? '');
    $password = (string)($input['password'] ?? '');
    $oldFile = $usersDir . '/' . safeFilename($username) . '/profile.json';
    $old = ($username !== '' && file_exists($oldFile)) ? json_decode(file_get_contents($oldFile), true) : null;
    $ok = $old && !empty($old['passwordHash']) && empty($old['platformSub'])
        && password_verify($password, $old['passwordHash']);

    if (!$ok) {
        $fails = (int)($auto['linkFails'] ?? 0) + 1;
        $auto['linkFails'] = $fails >= 5 ? 0 : $fails;
        if ($fails >= 5) $auto['linkLockedUntil'] = time() + 15 * 60;
        file_put_contents($autoFile, json_encode($auto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        respond(false, 'שם משתמש או סיסמה שגויים');
    }

    // החשבון הישן עובר לפלטפורמה: מקושר ל-sub. הסיסמה נשארת — היא רק חסומה כל עוד האפליקציה מחוברת
    // (login חסום כשיש PLATFORM_USER). מנותקת, והמנהל פתח "סיסמה משלה" — היא עובדת שוב (auth.md, סעיף 4).
    $old['platformSub'] = $platformUser['platformSub'];
    $old['linkedAt'] = date('c');
    if (empty($old['token'])) $old['token'] = bin2hex(random_bytes(32));
    file_put_contents($oldFile, json_encode($old, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // החשבון שנפתח לבד נשאר בשרת, אבל כבר לא מקושר לאיש.
    $auto['replacedBy'] = $old['username'];
    unset($auto['platformSub'], $auto['token']);
    file_put_contents($autoFile, json_encode($auto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    respond(true, 'החשבון חובר', [
        'token' => $old['token'],
        'username' => $old['username'],
        'displayName' => $old['displayName'] ?? $old['username'],
    ]);
}

/** "אין לי חשבון ישן" — ההצעה לא תופיע שוב (נשארת בתפריט המשתמש). */
function dismissLink($usersDir, $platformUser) {
    if (!$platformUser) respond(false, 'לא מחובר');
    $p = json_decode(file_get_contents($platformUser['_file']), true);
    $p['linkDismissed'] = true;
    file_put_contents($platformUser['_file'], json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    respond(true, 'OK');
}

/**
 * החשבון באפליקציה של מי שנכנס דרך הפלטפורמה, או null כשאין זהות (האפליקציה מנותקת מהשער).
 *
 * חשבון שמקושר ל-sub, ואם אין — חשבון חדש (ההרשאה בפלטפורמה היא האישור). אין איחוד לפי מייל:
 * שם משתמש באפליקציה אינו יכול להכיל @, ולכן אף חשבון ישן אינו מייל.
 */
function platformAccount($usersDir) {
    $identity = json_decode($_SERVER['PLATFORM_USER'] ?? '', true);
    $sub = (string)($identity['sub'] ?? '');
    if ($sub === '') return null;

    $file = null;
    foreach (glob($usersDir . '/*/profile.json') as $f) {
        $p = json_decode(file_get_contents($f), true);
        if ($p && (string)($p['platformSub'] ?? '') === $sub) { $file = $f; break; }
    }

    if ($file) {
        $profile = json_decode(file_get_contents($file), true);
    } else {
        $dir = $usersDir . '/platform_' . safeFilename($sub);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $file = $dir . '/profile.json';
        $name = trim((string)($identity['name'] ?? ''));
        $profile = [
            'username' => ($identity['email'] ?? '') ?: 'platform-' . $sub,
            'displayName' => $name !== '' ? $name : 'משתמש',
            'passwordHash' => '',
            'created' => date('c'),
        ];
        foreach (['contacts.json', 'user_data.json'] as $empty) {
            if (!file_exists("$dir/$empty")) file_put_contents("$dir/$empty", '[]');
        }
    }

    if (empty($profile['token'])) {
        $profile['platformSub'] = $sub;
        $profile['token'] = bin2hex(random_bytes(32));
        $profile['lastLogin'] = date('c');
        file_put_contents($file, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    $profile['_file'] = $file;
    return $profile;
}

function requireAuth($usersDir, $token) {
    if (!$token) respond(false, 'נדרשת התחברות', ['auth_required' => true]);
    $profile = findUserByToken($usersDir, $token);
    if (!$profile) respond(false, 'טוקן לא תקף, התחבר מחדש', ['auth_required' => true]);
}

function findUserByToken($usersDir, $token) {
    if (!$token) return null;
    foreach (glob($usersDir . '/*/profile.json') as $file) {
        $profile = json_decode(file_get_contents($file), true);
        if ($profile && ($profile['token'] ?? '') === $token) {
            $profile['_dir'] = dirname($file);
            return $profile;
        }
    }
    return null;
}

function getUserDir($usersDir, $token) {
    $profile = findUserByToken($usersDir, $token);
    return $profile ? $profile['_dir'] : null;
}

function setTokenCookie($token) {
    setcookie('gc_token', $token, [
        'expires' => time() + 86400 * 90, // 90 days
        'path' => '/',
        // הדף מקבל את האסימון בתשובה של check_auth, ואין סיבה שסקריפט יקרא אותו מהעוגייה.
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
}

function safeFilename($name) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
}

/** מזהה תקין מהבקשה, או עצירה. */
function requestId($value) {
    $id = is_string($value) ? $value : '';
    if (!preg_match(ID_RE, $id)) respond(false, 'מזהה לא תקין');
    return $id;
}

function currentUsername($usersDir, $token) {
    $profile = findUserByToken($usersDir, $token);
    return $profile ? (string)$profile['username'] : null;
}

/** פעולות ניהול: רק אחרי admin_check מוצלח באותו סשן. */
/** פעולות ניהול: הבעלים (לפי חשבון הפלטפורמה), או מי שנכנס בסיסמת הניהול באותו סשן. */
function requireAdmin() {
    global $dataDir, $platformUser;
    if (!empty($_SESSION['gc_admin']) || isPlatformOwner($dataDir, $platformUser)) return;
    respond(false, 'נדרשת כניסת מנהל', ['admin_required' => true]);
}

/**
 * המנהל המפתח מזוהה לפי חשבון הפלטפורמה (sub), ולא לפי סיסמה. הבעלים נקבע פעם אחת: החשבון הראשון
 * שנכנס לממשק הניהול כשעוד אין בעלים. משם — רק הוא (ומי שהוא יוסיף בעתיד).
 * כשהאפליקציה תצא לדומיין משלה, הבעלות תעבור לחשבון במערכת המשתמשים שלה (spec/06-users-and-admin.md).
 */
function isPlatformOwner($dataDir, $platformUser) {
    if (!$platformUser || empty($platformUser['platformSub'])) return false;
    $owners = adminConfig($dataDir)['owners'] ?? [];
    return in_array((string)$platformUser['platformSub'], $owners, true);
}

/** שם תצוגה שמגיע מהמשתמש: בלי תגיות ובלי תווי בקרה, באורך סביר. */
function cleanName($value, $fallback) {
    $name = trim(preg_replace('/[\x00-\x1F\x7F<>"\'`]/u', '', strip_tags((string)$value)));
    return mb_substr($name !== '' ? $name : $fallback, 0, 80);
}

/** כותרות לכל תמונה שמוגשת: גם קובץ ישן (SVG) לא יכול להריץ סקריפט כשפותחים אותו ישירות. */
function imageHeaders($mime, $path) {
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
}

// ═══════════════════════════════════════════════════════
// Contacts Functions
// ═══════════════════════════════════════════════════════

function saveContacts($usersDir, $token, $input) {
    $userDir = getUserDir($usersDir, $token);
    if (!$userDir) respond(false, 'משתמש לא נמצא');

    $contacts = $input['contacts'] ?? [];

    // Validate and sanitize
    $clean = [];
    foreach ($contacts as $c) {
        $clean[] = [
            'id' => $c['id'] ?? uniqid('c_'),
            'name' => trim($c['name'] ?? ''),
            'displayName' => trim($c['displayName'] ?? $c['name'] ?? ''),
            'phone' => trim($c['phone'] ?? ''),
            'email' => trim($c['email'] ?? ''),
            'selected' => $c['selected'] ?? true,
        ];
    }

    file_put_contents(
        $userDir . '/contacts.json',
        json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    respond(true, 'אנשי הקשר נשמרו', ['count' => count($clean)]);
}

function loadContacts($usersDir, $token) {
    $userDir = getUserDir($usersDir, $token);
    if (!$userDir) respond(false, 'משתמש לא נמצא');

    $file = $userDir . '/contacts.json';
    $contacts = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

    respond(true, 'OK', ['contacts' => $contacts ?: []]);
}

function deleteContact($usersDir, $token, $input) {
    $userDir = getUserDir($usersDir, $token);
    if (!$userDir) respond(false, 'משתמש לא נמצא');

    $id = $input['id'] ?? '';
    if (!$id) respond(false, 'Missing contact ID');

    $file = $userDir . '/contacts.json';
    $contacts = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $contacts = array_values(array_filter($contacts, fn($c) => ($c['id'] ?? '') !== $id));
    file_put_contents($file, json_encode($contacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    respond(true, 'איש קשר נמחק');
}

// ═══════════════════════════════════════════════════════
// User Data Functions (save/load card state)
// ═══════════════════════════════════════════════════════

function saveUserData($usersDir, $token, $input) {
    $userDir = getUserDir($usersDir, $token);
    if (!$userDir) respond(false, 'משתמש לא נמצא');

    $data = $input['data'] ?? $input;
    unset($data['action'], $data['token']);

    file_put_contents(
        $userDir . '/user_data.json',
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    respond(true, 'הנתונים נשמרו');
}

function loadUserData($usersDir, $token) {
    $userDir = getUserDir($usersDir, $token);
    if (!$userDir) respond(false, 'משתמש לא נמצא');

    $file = $userDir . '/user_data.json';
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

    respond(true, 'OK', ['data' => $data ?: []]);
}

// ═══════════════════════════════════════════════════════
// User Backgrounds (per-user)
// ═══════════════════════════════════════════════════════

function getUserBgDir($usersDir, $token) {
    $userDir = getUserDir($usersDir, $token);
    if (!$userDir) return null;
    $bgDir = $userDir . '/backgrounds';
    if (!is_dir($bgDir)) mkdir($bgDir, 0755, true);
    return $bgDir;
}

function uploadUserBackground($usersDir, $token) {
    $bgDir = getUserBgDir($usersDir, $token);
    if (!$bgDir) respond(false, 'משתמש לא נמצא');
    if (empty($_FILES['background'])) respond(false, 'No file provided');

    $file = $_FILES['background'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, UPLOAD_IMAGE_EXT, true)) respond(false, 'תמונה לא נתמכת');
    if ($file['size'] > 20 * 1024 * 1024) respond(false, 'קובץ גדול מדי (20MB מקסימום)');

    $id = uniqid('ubg_');
    $filename = $id . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $bgDir . '/' . $filename)) respond(false, 'שגיאה בשמירה');

    $meta = [
        'id' => $id,
        'name' => cleanName($_POST['name'] ?? '', pathinfo($file['name'], PATHINFO_FILENAME)),
        'filename' => $filename,
        'ext' => $ext,
        'size' => $file['size'],
        'uploaded' => date('c'),
    ];
    file_put_contents($bgDir . '/' . $id . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    respond(true, 'רקע הועלה', $meta);
}

function uploadUserBackgroundZip($usersDir, $token) {
    $bgDir = getUserBgDir($usersDir, $token);
    if (!$bgDir) respond(false, 'משתמש לא נמצא');
    if (empty($_FILES['zipfile'])) respond(false, 'No ZIP file provided');

    $file = $_FILES['zipfile'];
    if ($file['size'] > 100 * 1024 * 1024) respond(false, 'ZIP גדול מדי (100MB מקסימום)');

    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name']) !== true) respond(false, 'שגיאה בפתיחת ה-ZIP');

    $added = 0;
    $errors = [];
    $validExts = UPLOAD_IMAGE_EXT;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        // Skip directories and hidden files
        if (substr($entry, -1) === '/' || strpos(basename($entry), '.') === 0) continue;

        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (!in_array($ext, $validExts)) continue;

        $data = $zip->getFromIndex($i);
        if ($data === false || strlen($data) < 100) continue;
        if (strlen($data) > 20 * 1024 * 1024) { $errors[] = basename($entry) . ' גדול מדי'; continue; }

        $id = uniqid('ubg_');
        $filename = $id . '.' . $ext;
        file_put_contents($bgDir . '/' . $filename, $data);

        $meta = [
            'id' => $id,
            'name' => cleanName(pathinfo(basename($entry), PATHINFO_FILENAME), 'רקע'),
            'filename' => $filename,
            'ext' => $ext,
            'size' => strlen($data),
            'uploaded' => date('c'),
        ];
        file_put_contents($bgDir . '/' . $id . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $added++;
    }
    $zip->close();

    respond(true, "הועלו $added רקעים מ-ZIP", ['added' => $added, 'errors' => $errors]);
}

function listUserBackgrounds($usersDir, $token) {
    $bgDir = getUserBgDir($usersDir, $token);
    if (!$bgDir) respond(false, 'משתמש לא נמצא');

    $bgs = [];
    foreach (glob($bgDir . '/*.json') as $f) {
        $m = json_decode(file_get_contents($f), true);
        if ($m) $bgs[] = $m;
    }
    usort($bgs, fn($a, $b) => strcmp($b['uploaded'] ?? '', $a['uploaded'] ?? ''));
    respond(true, 'OK', ['backgrounds' => $bgs]);
}

function getUserBackground($usersDir, $token) {
    $bgDir = getUserBgDir($usersDir, $token);
    if (!$bgDir) respond(false, 'משתמש לא נמצא');

    $id = requestId($_GET['id'] ?? '');

    $metaFile = $bgDir . '/' . $id . '.json';
    if (!file_exists($metaFile)) respond(false, 'רקע לא נמצא');

    $meta = json_decode(file_get_contents($metaFile), true);
    $imgFile = $bgDir . '/' . basename((string)($meta['filename'] ?? ''));
    if (!is_file($imgFile)) respond(false, 'קובץ רקע חסר');

    // Return image directly
    $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'gif' => 'image/gif', 'tiff' => 'image/tiff', 'tif' => 'image/tiff', 'heic' => 'image/heic', 'heif' => 'image/heif', 'svg' => 'image/svg+xml', 'avif' => 'image/avif'];
    imageHeaders($mime[$meta['ext']] ?? 'application/octet-stream', $imgFile);
    readfile($imgFile);
    exit;
}

function deleteUserBackground($usersDir, $token, $input) {
    $bgDir = getUserBgDir($usersDir, $token);
    if (!$bgDir) respond(false, 'משתמש לא נמצא');

    $id = requestId($input['id'] ?? $_GET['id'] ?? '');

    $metaFile = $bgDir . '/' . $id . '.json';
    if (!file_exists($metaFile)) respond(false, 'רקע לא נמצא');

    $meta = json_decode(file_get_contents($metaFile), true);
    @unlink($bgDir . '/' . basename((string)($meta['filename'] ?? '')));
    @unlink($metaFile);
    respond(true, 'רקע נמחק');
}

// ═══════════════════════════════════════════════════════
// Designs (per-user saved layouts)
//
// עיצוב = רקע + שכבת ציור + מדבקות + כל השדות. כל התמונות
// נשמרות כקבצים לצד ה-JSON ולא בתוכו: base64 בתוך JSON תופח
// בשליש, וכמה עיצובים היו הופכים את הקובץ לכבד מכדי לטעון אותו
// בכל רשימה. ה-JSON מחזיק רק גאומטריה ומצביע לקבצים.
// ═══════════════════════════════════════════════════════

function getDesignsDir($usersDir, $token) {
    $userDir = getUserDir($usersDir, $token);
    if (!$userDir) return null;
    $dir = $userDir . '/designs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

/**
 * מפרק data URL של תמונה לסיומת ולתוכן בינארי, או עוצר בשגיאה.
 * ‏$label נכנס להודעה כדי שהמשתמש ידע איזו תמונה נדחתה.
 */
function decodeImageDataUrl($dataUrl, $label) {
    if (!preg_match('#^data:image/([a-z0-9.+-]+);base64,#i', $dataUrl, $m)) {
        respond(false, "פורמט $label לא נתמך");
    }
    $ext = strtolower($m[1]);
    if ($ext === 'jpeg') $ext = 'jpg';
    if (!in_array($ext, DESIGN_IMAGE_EXT)) respond(false, "פורמט $label לא נתמך: $ext");

    $binary = base64_decode(substr($dataUrl, strlen($m[0])), true);
    if ($binary === false) respond(false, "$label פגום");
    if (strlen($binary) > 25 * 1024 * 1024) respond(false, "$label גדול מדי");

    return [$ext, $binary];
}

function designAssetPath($dir, $id, $slot, $ext) {
    return "$dir/{$id}__{$slot}.$ext";
}

/** מוחק את הקובץ של נכס בכל סיומת אפשרית. */
function deleteDesignAsset($dir, $id, $slot) {
    foreach (DESIGN_IMAGE_EXT as $ext) {
        @unlink(designAssetPath($dir, $id, $slot, $ext));
    }
}

function saveDesign($usersDir, $token, $input) {
    $dir = getDesignsDir($usersDir, $token);
    if (!$dir) respond(false, 'משתמש לא נמצא');

    $name = trim($input['name'] ?? '');
    if ($name === '') respond(false, 'נדרש שם לעיצוב');

    // מזהה קיים = שמירה על עיצוב קיים; אחרת עיצוב חדש.
    $id = $input['id'] ?? '';
    if ($id !== '' && !preg_match('/^dsg_[a-z0-9]+$/i', $id)) respond(false, 'מזהה לא תקין');
    if ($id === '') $id = uniqid('dsg_');

    $existing = json_decode(@file_get_contents("$dir/$id.json"), true) ?: [];

    $meta = [
        'id' => $id,
        'name' => $name,
        'fields' => $input['fields'] ?? [],
        'backgroundNatural' => $input['backgroundNatural'] ?? null,
        // גאומטריה בלבד; התמונה של כל מדבקה יושבת בקובץ נכס נפרד.
        'stickers' => $input['stickers'] ?? [],
        'adjust' => $input['adjust'] ?? null,
        // סדר השכבות ומצב שכבת הציור; ההסתרה והנעילה של כל שכבה
        // נוסעות בתוך fields ו-stickers עצמם.
        'layerOrder' => $input['layerOrder'] ?? [],
        'paintLayer' => $input['paintLayer'] ?? null,
        'updated' => date('c'),
    ];

    // רקע חדש מגיע כ-data URL. אם לא נשלח רקע, נשמר זה שכבר קיים.
    $background = $input['background'] ?? '';
    if (is_string($background) && str_starts_with($background, 'data:image/')) {
        [$ext, $binary] = decodeImageDataUrl($background, 'רקע');
        foreach (glob("$dir/$id.{jpg,png,webp}", GLOB_BRACE) ?: [] as $old) @unlink($old);
        file_put_contents("$dir/$id.$ext", $binary);
        $meta['bgExt'] = $ext;
    } elseif (!empty($existing['bgExt'])) {
        $meta['bgExt'] = $existing['bgExt'];
    }

    // ‏assets הוא מפה מלאה של הנכסים: כל ערך הוא data URL חדש, או
    // המחרוזת "keep" כדי להשאיר את הקיים. נכס שאינו במפה נמחק —
    // כך מדבקה שהוסרה מהעיצוב אינה משאירה קובץ יתום.
    $incoming = $input['assets'] ?? null;
    $oldAssets = $existing['assets'] ?? [];
    if (is_array($incoming)) {
        $assets = [];
        foreach ($incoming as $slot => $value) {
            if (!preg_match(DESIGN_SLOT_RE, (string)$slot)) respond(false, 'נכס לא תקין: ' . $slot);

            if ($value === 'keep') {
                if (isset($oldAssets[$slot])) $assets[$slot] = $oldAssets[$slot];
                continue;
            }
            if (!is_string($value) || !str_starts_with($value, 'data:image/')) continue;

            [$ext, $binary] = decodeImageDataUrl($value, 'שכבה');
            deleteDesignAsset($dir, $id, $slot);
            file_put_contents(designAssetPath($dir, $id, $slot, $ext), $binary);
            $assets[$slot] = $ext;
        }
        foreach ($oldAssets as $slot => $ext) {
            if (!isset($assets[$slot])) deleteDesignAsset($dir, $id, $slot);
        }
        $meta['assets'] = $assets;
    } else {
        $meta['assets'] = $oldAssets;
    }

    file_put_contents("$dir/$id.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    respond(true, 'העיצוב נשמר', ['id' => $id, 'name' => $name]);
}

function listDesigns($usersDir, $token) {
    $dir = getDesignsDir($usersDir, $token);
    if (!$dir) respond(false, 'משתמש לא נמצא');

    $designs = [];
    foreach (glob($dir . '/*.json') as $file) {
        $d = json_decode(file_get_contents($file), true);
        if (!$d) continue;
        // בלי השדות: הרשימה נטענת בכל פתיחת דיאלוג, ואין בה בהם צורך.
        $designs[] = [
            'id' => $d['id'] ?? '',
            'name' => $d['name'] ?? '',
            'updated' => $d['updated'] ?? '',
            'fieldCount' => count($d['fields'] ?? []),
            'hasBackground' => !empty($d['bgExt']),
        ];
    }
    usort($designs, fn($a, $b) => strcmp($b['updated'], $a['updated']));
    respond(true, 'OK', ['designs' => $designs]);
}

function getDesign($usersDir, $token) {
    $dir = getDesignsDir($usersDir, $token);
    if (!$dir) respond(false, 'משתמש לא נמצא');

    $id = $_GET['id'] ?? '';
    if (!preg_match('/^dsg_[a-z0-9]+$/i', $id)) respond(false, 'מזהה לא תקין');

    $file = "$dir/$id.json";
    if (!file_exists($file)) respond(false, 'העיצוב לא נמצא');

    respond(true, 'OK', ['design' => json_decode(file_get_contents($file), true)]);
}

/**
 * מחזיר תמונה שמורה של עיצוב, כדי שה-JSON שלו יישאר קטן.
 * ‏slot ריק או "bg" = הרקע; אחרת שכבת הציור או מדבקה.
 */
function getDesignBackground($usersDir, $token) {
    $dir = getDesignsDir($usersDir, $token);
    if (!$dir) respond(false, 'משתמש לא נמצא');

    $id = $_GET['id'] ?? '';
    if (!preg_match('/^dsg_[a-z0-9]+$/i', $id)) respond(false, 'מזהה לא תקין');

    $meta = json_decode(@file_get_contents("$dir/$id.json"), true);
    $slot = $_GET['slot'] ?? 'bg';

    if ($slot === 'bg') {
        $ext = $meta['bgExt'] ?? '';
        $path = "$dir/$id.$ext";
    } else {
        if (!preg_match(DESIGN_SLOT_RE, $slot)) respond(false, 'נכס לא תקין');
        $ext = $meta['assets'][$slot] ?? '';
        $path = $ext ? designAssetPath($dir, $id, $slot, $ext) : '';
    }
    if (!$ext || !file_exists($path)) respond(false, 'הנכס לא נמצא בעיצוב');

    $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    imageHeaders($mime[$ext] ?? 'application/octet-stream', $path);
    readfile($path);
    exit;
}

function deleteDesign($usersDir, $token, $input) {
    $dir = getDesignsDir($usersDir, $token);
    if (!$dir) respond(false, 'משתמש לא נמצא');

    $id = $input['id'] ?? $_GET['id'] ?? '';
    if (!preg_match('/^dsg_[a-z0-9]+$/i', $id)) respond(false, 'מזהה לא תקין');
    if (!file_exists("$dir/$id.json")) respond(false, 'העיצוב לא נמצא');

    $meta = json_decode(@file_get_contents("$dir/$id.json"), true) ?: [];
    @unlink("$dir/$id.json");
    foreach (glob("$dir/$id.{jpg,png,webp}", GLOB_BRACE) ?: [] as $bg) @unlink($bg);
    foreach (array_keys($meta['assets'] ?? []) as $slot) {
        if (preg_match(DESIGN_SLOT_RE, (string)$slot)) deleteDesignAsset($dir, $id, $slot);
    }

    respond(true, 'העיצוב נמחק');
}

// ═══════════════════════════════════════════════════════
// Font Functions
// ═══════════════════════════════════════════════════════

/** ‏$owner — שם המשתמש שהעלה; null = מנהל (פונט של המערכת). */
function handleFontUpload($dir, $owner) {
    if (empty($_FILES['font'])) respond(false, 'No font file provided');
    $file = $_FILES['font'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['ttf', 'otf', 'woff', 'woff2'])) respond(false, 'Unsupported font format');
    if ($file['size'] > 10 * 1024 * 1024) respond(false, 'Font file too large (max 10MB)');

    $displayName = cleanName($_POST['name'] ?? '', pathinfo($file['name'], PATHINFO_FILENAME));
    $id = uniqid('font_');
    $filename = $id . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) respond(false, 'Failed to save font file');

    $meta = ['id' => $id, 'name' => $displayName, 'filename' => $filename, 'ext' => $ext, 'size' => $file['size'], 'uploaded' => date('c'), 'owner' => $owner];
    file_put_contents($dir . '/' . $id . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    respond(true, 'Font uploaded', $meta);
}

function listFonts($dir) {
    $fonts = [];
    foreach (glob($dir . '/*.json') as $f) { $m = json_decode(file_get_contents($f), true); if ($m) $fonts[] = $m; }
    usort($fonts, fn($a, $b) => strcmp($b['uploaded'] ?? '', $a['uploaded'] ?? ''));
    respond(true, 'OK', ['fonts' => $fonts]);
}

function getFont($dir) {
    $id = requestId($_GET['id'] ?? '');
    $metaFile = $dir . '/' . $id . '.json';
    if (!file_exists($metaFile)) respond(false, 'Font not found');
    $meta = json_decode(file_get_contents($metaFile), true);
    $fontFile = $dir . '/' . basename((string)($meta['filename'] ?? ''));
    if (!file_exists($fontFile)) respond(false, 'Font file missing');
    $meta['data'] = base64_encode(file_get_contents($fontFile));
    respond(true, 'OK', $meta);
}

/** ‏$user — המשתמש שמבקש; null = מנהל. משתמש מוחק רק פונט שהוא העלה — המאגר משותף לכולם. */
function deleteFont($dir, $user) {
    $id = requestId($_GET['id'] ?? $_POST['id'] ?? '');
    $metaFile = $dir . '/' . $id . '.json';
    if (!file_exists($metaFile)) respond(false, 'Font not found');
    $meta = json_decode(file_get_contents($metaFile), true);
    if ($user !== null && ($meta['owner'] ?? null) !== $user) respond(false, 'רק מי שהעלה את הפונט יכול למחוק אותו');
    @unlink($dir . '/' . basename((string)($meta['filename'] ?? '')));
    @unlink($metaFile);
    respond(true, 'Font deleted');
}

// ═══════════════════════════════════════════════════════
// Template Functions
// ═══════════════════════════════════════════════════════

function saveTemplate($dir) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = isset($input['id']) ? requestId($input['id']) : uniqid('tmpl_');
    $template = ['id' => $id, 'name' => cleanName($input['name'] ?? '', 'ללא שם'), 'fields' => $input['fields'] ?? [], 'background' => $input['background'] ?? null, 'updated' => date('c')];
    file_put_contents($dir . '/' . $id . '.json', json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    respond(true, 'Template saved', $template);
}

function listTemplates($dir) {
    $t = [];
    foreach (glob($dir . '/*.json') as $f) { $m = json_decode(file_get_contents($f), true); if ($m) { unset($m['fields']); $t[] = $m; } }
    usort($t, fn($a, $b) => strcmp($b['updated'] ?? '', $a['updated'] ?? ''));
    respond(true, 'OK', ['templates' => $t]);
}

function getTemplate($dir) {
    $id = requestId($_GET['id'] ?? '');
    $f = $dir . '/' . $id . '.json';
    if (!file_exists($f)) respond(false, 'Template not found');
    respond(true, 'OK', json_decode(file_get_contents($f), true));
}

function deleteTemplate($dir) {
    $id = requestId($_GET['id'] ?? $_POST['id'] ?? '');
    $f = $dir . '/' . $id . '.json';
    if (!file_exists($f)) respond(false, 'Template not found');
    @unlink($f);
    respond(true, 'Template deleted');
}

// ═══════════════════════════════════════════════════════
// Background Functions
// ═══════════════════════════════════════════════════════

function handleBackgroundUpload($dir) {
    if (empty($_FILES['background'])) respond(false, 'No background file provided');
    $file = $_FILES['background'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, UPLOAD_IMAGE_EXT, true)) respond(false, 'Unsupported image format');
    if ($file['size'] > 20 * 1024 * 1024) respond(false, 'Image too large (max 20MB)');

    $id = uniqid('bg_');
    $filename = $id . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) respond(false, 'Failed to save background');

    $meta = ['id' => $id, 'name' => cleanName($_POST['name'] ?? '', pathinfo($file['name'], PATHINFO_FILENAME)), 'filename' => $filename, 'ext' => $ext, 'size' => $file['size'], 'uploaded' => date('c')];
    file_put_contents($dir . '/' . $id . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    respond(true, 'Background uploaded', $meta);
}

function listBackgrounds($dir) {
    $bgs = [];
    foreach (glob($dir . '/*.json') as $f) { $m = json_decode(file_get_contents($f), true); if ($m) $bgs[] = $m; }
    respond(true, 'OK', ['backgrounds' => $bgs]);
}

function deleteBackground($dir) {
    $id = requestId($_GET['id'] ?? $_POST['id'] ?? '');
    $metaFile = $dir . '/' . $id . '.json';
    if (!file_exists($metaFile)) respond(false, 'Background not found');
    $meta = json_decode(file_get_contents($metaFile), true);
    @unlink($dir . '/' . basename((string)($meta['filename'] ?? '')));
    @unlink($metaFile);
    respond(true, 'Background deleted');
}

// ═══════════════════════════════════════════════════════
// Admin Functions
// ═══════════════════════════════════════════════════════

/**
 * סיסמת הניהול נשמרת כ-hash. קובץ ישן עם סיסמה גלויה עובר ל-hash בכניסה הראשונה. ברירת המחדל
 * ‏ADMIN_DEFAULT_PASSWORD עדיין מכניסה, אבל ממשק הניהול מחייב להחליף אותה מיד.
 */
function adminConfig($dataDir) {
    $file = $dataDir . '/admin_config.json';
    $cfg = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    if (empty($cfg['hash'])) {
        $plain = (string)($cfg['password'] ?? ADMIN_DEFAULT_PASSWORD);
        unset($cfg['password']);
        $cfg = ['hash' => password_hash($plain, PASSWORD_DEFAULT), 'isDefault' => $plain === ADMIN_DEFAULT_PASSWORD] + $cfg;
        file_put_contents($file, json_encode($cfg, JSON_PRETTY_PRINT));
    }
    return $cfg;
}

/** 5 ניסיונות שגויים מאותה כתובת → נעילה של 15 דקות. */
function adminLockFile($dataDir) {
    return $dataDir . '/admin_lock_' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '') . '.json';
}

function adminCheck($dataDir, $input) {
    global $platformUser;
    // כניסה בלי סיסמה: הבעלים לפי הפלטפורמה, או תביעת בעלות כשעוד אין בעלים.
    if ($platformUser && !empty($platformUser['platformSub'])) {
        $cfg = adminConfig($dataDir);
        $sub = (string)$platformUser['platformSub'];
        if (empty($cfg['owners'])) {
            $cfg['owners'] = [$sub];
            file_put_contents($dataDir . '/admin_config.json', json_encode($cfg, JSON_PRETTY_PRINT));
            respond(true, 'OK', ['owner' => true, 'claimed' => true]);
        }
        if (in_array($sub, $cfg['owners'], true)) respond(true, 'OK', ['owner' => true]);
    }
    if (($input['password'] ?? '') === '') respond(false, 'נדרשת סיסמת ניהול', ['needsPassword' => true]);

    $lockFile = adminLockFile($dataDir);
    $lock = is_file($lockFile) ? (json_decode((string)file_get_contents($lockFile), true) ?: []) : [];
    if ((int)($lock['until'] ?? 0) > time()) respond(false, 'יותר מדי ניסיונות. נסו שוב בעוד ' . ceil(((int)$lock['until'] - time()) / 60) . ' דקות');

    $cfg = adminConfig($dataDir);
    if (password_verify((string)($input['password'] ?? ''), $cfg['hash'])) {
        @unlink($lockFile);
        session_regenerate_id(true);
        $_SESSION['gc_admin'] = true;
        respond(true, 'OK', ['mustChange' => !empty($cfg['isDefault'])]);
    }

    $fails = (int)($lock['fails'] ?? 0) + 1;
    file_put_contents($lockFile, json_encode($fails >= 5 ? ['fails' => 0, 'until' => time() + 15 * 60] : ['fails' => $fails]));
    respond(false, 'סיסמה שגויה');
}

function adminSetPassword($dataDir, $input) {
    $pass = (string)($input['password'] ?? '');
    if (mb_strlen($pass) < 10) respond(false, 'הסיסמה חייבת להיות באורך 10 תווים לפחות');
    if ($pass === ADMIN_DEFAULT_PASSWORD) respond(false, 'בחרו סיסמה אחרת');
    $cfg = adminConfig($dataDir);
    $cfg['hash'] = password_hash($pass, PASSWORD_DEFAULT);
    $cfg['isDefault'] = false;
    file_put_contents($dataDir . '/admin_config.json', json_encode($cfg, JSON_PRETTY_PRINT));
    respond(true, 'הסיסמה עודכנה');
}

function adminUploadBgZip($backgroundsDir) {
    if (empty($_FILES['zipfile'])) respond(false, 'No ZIP file');
    $file = $_FILES['zipfile'];
    if ($file['size'] > 100 * 1024 * 1024) respond(false, 'ZIP גדול מדי');

    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name']) !== true) respond(false, 'שגיאה בפתיחת ZIP');

    $added = 0;
    $validExts = UPLOAD_IMAGE_EXT;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if (substr($entry, -1) === '/' || strpos(basename($entry), '.') === 0) continue;
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (!in_array($ext, $validExts)) continue;

        $data = $zip->getFromIndex($i);
        if ($data === false || strlen($data) < 100) continue;
        if (strlen($data) > 20 * 1024 * 1024) continue;

        $id = uniqid('bg_');
        $filename = $id . '.' . $ext;
        file_put_contents($backgroundsDir . '/' . $filename, $data);

        $meta = [
            'id' => $id,
            'name' => cleanName(pathinfo(basename($entry), PATHINFO_FILENAME), 'רקע'),
            'filename' => $filename,
            'ext' => $ext,
            'size' => strlen($data),
            'uploaded' => date('c'),
        ];
        file_put_contents($backgroundsDir . '/' . $id . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $added++;
    }
    $zip->close();
    respond(true, "הועלו $added רקעים", ['added' => $added]);
}

function adminListUsers($usersDir) {
    $users = [];
    foreach (glob($usersDir . '/*/profile.json') as $file) {
        $profile = json_decode(file_get_contents($file), true);
        if (!$profile) continue;

        $userDir = dirname($file);
        $contactsCount = 0;
        $contactsFile = $userDir . '/contacts.json';
        if (file_exists($contactsFile)) {
            $contacts = json_decode(file_get_contents($contactsFile), true);
            $contactsCount = is_array($contacts) ? count($contacts) : 0;
        }

        $bgCount = 0;
        $bgDir = $userDir . '/backgrounds';
        if (is_dir($bgDir)) {
            $bgCount = count(glob($bgDir . '/*.json'));
        }

        $users[] = [
            'username' => $profile['username'] ?? '',
            'displayName' => $profile['displayName'] ?? '',
            'contactsCount' => $contactsCount,
            'backgroundsCount' => $bgCount,
            'created' => $profile['created'] ?? '',
            'lastLogin' => $profile['lastLogin'] ?? '',
        ];
    }

    usort($users, fn($a, $b) => strcmp($b['lastLogin'] ?? '', $a['lastLogin'] ?? ''));
    respond(true, 'OK', ['users' => $users]);
}

// ═══════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════

function respond($success, $message, $data = []) {
    echo json_encode(array_merge(
        ['success' => $success, 'message' => $message],
        $data
    ), JSON_UNESCAPED_UNICODE);
    exit;
}
