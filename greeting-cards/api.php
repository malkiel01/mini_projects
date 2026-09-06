<?php
/**
 * API for Greeting Cards Generator
 * Auth, contacts, font uploads, template saving, background gallery
 */

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

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

try {
    switch ($action) {
        // ─── Auth ────────────────────────────────────
        case 'register':    handleRegister($usersDir, $input); break;
        case 'login':       handleLogin($usersDir, $input); break;
        case 'logout':      handleLogout(); break;
        case 'check_auth':  handleCheckAuth($usersDir, $token); break;

        // ─── Contacts (require auth) ────────────────
        case 'save_contacts':   requireAuth($usersDir, $token); saveContacts($usersDir, $token, $input); break;
        case 'load_contacts':   requireAuth($usersDir, $token); loadContacts($usersDir, $token); break;
        case 'delete_contact':  requireAuth($usersDir, $token); deleteContact($usersDir, $token, $input); break;

        // ─── User Data (require auth) ───────────────
        case 'save_user_data':  requireAuth($usersDir, $token); saveUserData($usersDir, $token, $input); break;
        case 'load_user_data':  requireAuth($usersDir, $token); loadUserData($usersDir, $token); break;

        // ─── Fonts (public) ─────────────────────────
        case 'upload_font':     handleFontUpload($fontsDir); break;
        case 'list_fonts':      listFonts($fontsDir); break;
        case 'get_font':        getFont($fontsDir); break;
        case 'delete_font':     deleteFont($fontsDir); break;

        // ─── Templates ─────────────────────────────
        case 'save_template':   saveTemplate($templatesDir); break;
        case 'list_templates':  listTemplates($templatesDir); break;
        case 'get_template':    getTemplate($templatesDir); break;
        case 'delete_template': deleteTemplate($templatesDir); break;

        // ─── Backgrounds ────────────────────────────
        case 'upload_background':   handleBackgroundUpload($backgroundsDir); break;
        case 'list_backgrounds':    listBackgrounds($backgroundsDir); break;
        case 'delete_background':   deleteBackground($backgroundsDir); break;

        default:
            respond(false, 'Unknown action: ' . $action);
    }
} catch (Exception $e) {
    respond(false, $e->getMessage());
}

// ═══════════════════════════════════════════════════════
// Auth Functions
// ═══════════════════════════════════════════════════════

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

function handleCheckAuth($usersDir, $token) {
    if (!$token) respond(false, 'לא מחובר');
    $profile = findUserByToken($usersDir, $token);
    if (!$profile) respond(false, 'לא מחובר');
    respond(true, 'מחובר', [
        'username' => $profile['username'],
        'displayName' => $profile['displayName'] ?? $profile['username'],
    ]);
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
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

function safeFilename($name) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
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
// Font Functions
// ═══════════════════════════════════════════════════════

function handleFontUpload($dir) {
    if (empty($_FILES['font'])) respond(false, 'No font file provided');
    $file = $_FILES['font'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['ttf', 'otf', 'woff', 'woff2'])) respond(false, 'Unsupported font format');
    if ($file['size'] > 10 * 1024 * 1024) respond(false, 'Font file too large (max 10MB)');

    $displayName = $_POST['name'] ?? pathinfo($file['name'], PATHINFO_FILENAME);
    $id = uniqid('font_');
    $filename = $id . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) respond(false, 'Failed to save font file');

    $meta = ['id' => $id, 'name' => $displayName, 'filename' => $filename, 'ext' => $ext, 'size' => $file['size'], 'uploaded' => date('c')];
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
    $id = $_GET['id'] ?? '';
    if (!$id) respond(false, 'Missing font ID');
    $metaFile = $dir . '/' . $id . '.json';
    if (!file_exists($metaFile)) respond(false, 'Font not found');
    $meta = json_decode(file_get_contents($metaFile), true);
    $fontFile = $dir . '/' . $meta['filename'];
    if (!file_exists($fontFile)) respond(false, 'Font file missing');
    $meta['data'] = base64_encode(file_get_contents($fontFile));
    respond(true, 'OK', $meta);
}

function deleteFont($dir) {
    $id = $_GET['id'] ?? $_POST['id'] ?? '';
    if (!$id) respond(false, 'Missing font ID');
    $metaFile = $dir . '/' . $id . '.json';
    if (!file_exists($metaFile)) respond(false, 'Font not found');
    $meta = json_decode(file_get_contents($metaFile), true);
    @unlink($dir . '/' . $meta['filename']);
    @unlink($metaFile);
    respond(true, 'Font deleted');
}

// ═══════════════════════════════════════════════════════
// Template Functions
// ═══════════════════════════════════════════════════════

function saveTemplate($dir) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = $input['id'] ?? uniqid('tmpl_');
    $template = ['id' => $id, 'name' => $input['name'] ?? 'ללא שם', 'fields' => $input['fields'] ?? [], 'background' => $input['background'] ?? null, 'updated' => date('c')];
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
    $id = $_GET['id'] ?? '';
    if (!$id) respond(false, 'Missing template ID');
    $f = $dir . '/' . $id . '.json';
    if (!file_exists($f)) respond(false, 'Template not found');
    respond(true, 'OK', json_decode(file_get_contents($f), true));
}

function deleteTemplate($dir) {
    $id = $_GET['id'] ?? $_POST['id'] ?? '';
    if (!$id) respond(false, 'Missing template ID');
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
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) respond(false, 'Unsupported image format');
    if ($file['size'] > 20 * 1024 * 1024) respond(false, 'Image too large (max 20MB)');

    $id = uniqid('bg_');
    $filename = $id . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) respond(false, 'Failed to save background');

    $meta = ['id' => $id, 'name' => $_POST['name'] ?? pathinfo($file['name'], PATHINFO_FILENAME), 'filename' => $filename, 'ext' => $ext, 'size' => $file['size'], 'uploaded' => date('c')];
    file_put_contents($dir . '/' . $id . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    respond(true, 'Background uploaded', $meta);
}

function listBackgrounds($dir) {
    $bgs = [];
    foreach (glob($dir . '/*.json') as $f) { $m = json_decode(file_get_contents($f), true); if ($m) $bgs[] = $m; }
    respond(true, 'OK', ['backgrounds' => $bgs]);
}

function deleteBackground($dir) {
    $id = $_GET['id'] ?? $_POST['id'] ?? '';
    if (!$id) respond(false, 'Missing background ID');
    $metaFile = $dir . '/' . $id . '.json';
    if (!file_exists($metaFile)) respond(false, 'Background not found');
    $meta = json_decode(file_get_contents($metaFile), true);
    @unlink($dir . '/' . $meta['filename']);
    @unlink($metaFile);
    respond(true, 'Background deleted');
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
