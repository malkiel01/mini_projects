<?php
/**
 * OAuth 2 (authorization code) לחיבור ה-GPT לחשבון בסטודיו.
 *
 * - ‏authorize.php — דף אישור למשתמש (עובר בשער כרגיל: מי שלא מחובר — נשלח לכניסה וחוזר).
 * - ‏token.php — ציבורי (app.json → public): שרתי OpenAI מחליפים כאן קוד בטוקן.
 * שתי כתובות נפרדות ולא ‏?step=, כי ChatGPT מוסיף פרמטרים משלו לכתובת ההרשאה.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/chat.php';

$step = $step ?? '';   // נקבע ב-authorize.php / token.php; פנייה ישירה לכאן — 404

if ($step === 'token') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError('invalid_request', 405);
        $in = $_POST ?: (json_decode((string)file_get_contents('php://input'), true) ?: []);
        // הלקוח יכול לשלוח את פרטיו בכותרת Basic או בגוף הבקשה — שתי הדרכים מותרות בתקן.
        $clientId = (string)($in['client_id'] ?? $_SERVER['PHP_AUTH_USER'] ?? '');
        $secret = (string)($in['client_secret'] ?? $_SERVER['PHP_AUTH_PW'] ?? '');
        if (($in['grant_type'] ?? '') !== 'authorization_code') throw new ApiError('unsupported_grant_type', 400);
        echo json_encode(exchangeOAuthCode($clientId, $secret, (string)($in['code'] ?? ''), (string)($in['redirect_uri'] ?? '')));
    } catch (ApiError $e) {
        http_response_code($e->status);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($step !== 'authorize') { http_response_code(404); exit; }

header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; form-action 'self' https://chatgpt.com https://chat.openai.com");
header('X-Frame-Options: DENY');

$cfg = gptConfig();
$clientId = (string)($_GET['client_id'] ?? '');
$redirect = (string)($_GET['redirect_uri'] ?? '');
$state = (string)($_GET['state'] ?? '');
$error = null;
if (empty($cfg['client_id']) || !hash_equals($cfg['client_id'], $clientId)) $error = 'ה-GPT לא מוגדר אצלנו (client_id לא מוכר).';
elseif (!validRedirect($redirect)) $error = 'כתובת החזרה אינה של ChatGPT.';

$user = currentUser();
if (!$error && !$user) $error = 'צריך להיכנס לסטודיו עיצוב קודם.';

// אישור: טופס POST לאותה כתובת, עם אסימון CSRF בסשן — כדי שאתר זר לא יוכל "לאשר" בשם המשתמש.
if (!$error && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    session_name('gc_oauth');
    session_start();
    if (!hash_equals((string)($_SESSION['oauth_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        $error = 'פג תוקף הדף — נסו שוב מ-ChatGPT.';
    } else {
        unset($_SESSION['oauth_csrf']);
        $code = issueOAuthCode((int)$user['id'], $redirect);
        header('Location: ' . $redirect . '?' . http_build_query(['code' => $code, 'state' => $state]), true, 302);
        exit;
    }
}
if (!$error) {
    session_name('gc_oauth');
    session_start();
    $_SESSION['oauth_csrf'] = bin2hex(random_bytes(16));
}
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>חיבור ChatGPT — סטודיו עיצוב</title>
<style>
    body { margin: 0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background: #f4f5f7; color: #16192b; }
    main { max-width: 440px; margin: 10vh auto; padding: 24px 16px; background: #fff; border: 1px solid #e3e6eb; border-radius: 10px; }
    h1 { font-size: 1.2rem; margin: 0 0 12px; }
    p { margin: 0 0 12px; color: #5d6475; }
    button { width: 100%; min-height: 44px; border: 0; border-radius: 10px; background: #2d4a8a; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
    .err { color: #b3261e; }
</style>
</head>
<body>
<main>
    <h1>🎨 חיבור ChatGPT לסטודיו עיצוב</h1>
<?php if ($error): ?>
    <p class="err" role="alert"><?= $e($error) ?></p>
<?php else: ?>
    <p>ה-GPT "מעצב הכרטיסים" יוכל לשלוח עיצובים ותמונות לחשבון שלך (<?= $e($user['name'] ?: $user['email'] ?: 'החשבון שלך') ?>). הוא לא יוכל לקרוא או למחוק את העיצובים הקיימים.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= $e($_SESSION['oauth_csrf']) ?>">
        <button type="submit">אישור וחיבור</button>
    </form>
<?php endif; ?>
</main>
</body>
</html>
