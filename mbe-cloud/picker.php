<?php
/**
 * בורר הקבצים של הענן (docs/spec-3-files-scopes.md סעיף 4). אפליקציה ב-files.app לא בוחרת בעצמה — המשתמש בוחר כאן,
 * במסך של הענן, וכל מה שנבחר נרשם כהענקה (picked). הענקה על תיקייה חלה על כל מה שבתוכה.
 *
 *   GET  picker.php?client&state&return&sub&exp&sig&kind&multiple&mime   → העץ (הבקשה נשמרת בסשן)
 *   GET  picker.php?folder=<id>                                          → ניווט בעץ
 *   POST picker.php (pick[] + form, או answer=cancel)                    → <return>?state&picked=n_a,n_b  או  ?pick=cancelled
 *
 * מאחורי השער (כמו connect.php). רק אותו חשבון: ה-sub החתום (purpose "pick") שונה ממי שנכנס — "הבחירה נדחתה", בלי כפתור.
 * רק לאפליקציה מחוברת ב-files.app — אחרת 400.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/grants.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; form-action 'self' https:; frame-ancestors 'none'");
header('X-Frame-Options: DENY');

function pickPage(string $title, string $body, int $status = 200): never {
    http_response_code($status);
    $t = htmlspecialchars($title);
    echo <<<HTML
<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2d4a8a">
<title>$t — ענן MBE</title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/ui-core/ui.css">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<header class="top"><div class="titles"><h1>☁️ ענן MBE</h1></div></header>
<main id="main" class="connect picker">$body</main>
</body>
</html>
HTML;
    exit;
}

function pickFail(string $msg, int $status = 400): never {
    pickPage('שגיאה', '<p class="error" role="alert">' . htmlspecialchars($msg) . '</p>', $status);
}

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_name('MBECLOUD');
    session_set_cookie_params(['path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

try {
    $a = requireAccount();
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $fresh = !$post && isset($_GET['client']);
    $q = $fresh ? $_GET : ($_SESSION['pick'] ?? null);
    if (!is_array($q)) pickFail('הבקשה פגה — חזרו לאפליקציה ונסו שוב.');
    $req = pickRequest($q);
    if ($fresh) {
        $keep = array_intersect_key($_GET, array_flip(['client', 'state', 'return', 'sub', 'exp', 'sig', 'kind', 'multiple', 'mime']));
        $_SESSION['pick'] = $keep;
        $_SESSION['pick_form'] = b64u(random_bytes(18));
    }
    $back = fn(array $extra) => withQuery($req['return'], ['state' => $req['state']] + $extra);

    // רק אותו חשבון (spec-0 6.3)
    if (!sameSub($a, $req['sub'])) {
        unset($_SESSION['pick'], $_SESSION['pick_form']);
        error_log("mbe-cloud: picker.php account_mismatch (client {$req['client']['id']})");
        $who = htmlspecialchars($a['name']);
        $name = htmlspecialchars($req['client']['name']);
        $b = htmlspecialchars($back(['pick' => 'account_mismatch']));
        pickPage('הבחירה נדחתה', <<<HTML
<p class="error" role="alert"><b>הבחירה נדחתה: החשבון באפליקציה אינו החשבון שלך.</b></p>
<p class="hint">נכנסת לענן בתור <b>$who</b>, אבל באפליקציה <b>$name</b> מחובר חשבון אחר.</p>
<div class="row-btns"><a class="btn ghost" href="$b">חזרה לאפליקציה</a></div>
HTML, 403);
    }
    pickAllowed($a, $req);

    if ($post) {
        $form = (string) ($_POST['form'] ?? '');
        if ($form === '' || !hash_equals((string) ($_SESSION['pick_form'] ?? ''), $form)) pickFail('הבקשה פגה — חזרו לאפליקציה ונסו שוב.');
        unset($_SESSION['pick'], $_SESSION['pick_form']);
        $to = ($_POST['answer'] ?? '') === 'cancel' ? $back(['pick' => 'cancelled']) : pickApprove($a, $req, $_POST['pick'] ?? null);
        header('Location: ' . $to, true, 303);
        exit;
    }

    // העץ — תיקייה אחר תיקייה, מהשורש של הכונן
    $folder = isset($_GET['folder']) && $_GET['folder'] !== '' ? (string) $_GET['folder'] : null;
    $list = listFolder($a, $folder);
    $name = htmlspecialchars($req['client']['name']);
    $what = ['file' => 'קובץ', 'folder' => 'תיקייה', 'any' => 'קובץ או תיקייה'][$req['kind']];
    $type = $req['multiple'] ? 'checkbox' : 'radio';
    $crumbs = '<a href="picker.php">☁️ הכונן שלי</a>';
    foreach ($list['path'] as $p) {
        $crumbs .= ' <span class="gt">‹</span> <a href="picker.php?folder=' . rawurlencode($p['id']) . '">' . htmlspecialchars($p['name']) . '</a>';
    }
    $rows = '';
    foreach ($list['items'] as $n) {
        $ic = $n['kind'] === 'folder' ? '📁' : '📄';
        $label = htmlspecialchars($n['name']);
        $fits = pickFits($req, $n + ['mime' => $n['mime'] ?? '']);
        $box = $fits ? '<input type="' . $type . '" name="pick[]" value="' . htmlspecialchars($n['id']) . '" aria-label="בחירת ' . $label . '">' : '<span class="pick-gap"></span>';
        $open = $n['kind'] === 'folder' ? '<a class="btn ghost sm" href="picker.php?folder=' . rawurlencode($n['id']) . '" aria-label="פתיחת ' . $label . '">‹</a>' : '';
        $rows .= '<li><label class="acc-row' . ($fits ? '' : ' muted') . '">' . $box . '<span class="use-ic' . ($n['kind'] === 'folder' ? ' folder' : '') . '" aria-hidden="true">' . $ic . '</span>'
               . '<span class="body"><span class="l1">' . $label . '</span></span>' . $open . '</label></li>';
    }
    if ($rows === '') $rows = '<li><p class="empty">התיקייה ריקה.</p></li>';
    $form = htmlspecialchars((string) $_SESSION['pick_form']);
    $warnFolder = $req['kind'] !== 'file' ? "<p class=\"hint\">בחירת תיקייה: <b>$name</b> תוכל לקרוא ולשנות את כל מה שבתיקייה הזו.</p>" : '';
    pickPage("בחירה ל-$name", <<<HTML
<div class="summary"><div class="line"><b>$name</b> <span class="muted">מבקשת לפתוח $what</span></div></div>
<nav class="crumbs pick-crumbs" aria-label="מיקום">$crumbs</nav>
<form method="post">
  <input type="hidden" name="form" value="$form">
  <ul class="list">$rows</ul>
  $warnFolder
  <p class="hint">האפליקציה תראה רק את מה שתבחר כאן. אפשר לבטל ב-🔗 חיבורים.</p>
  <div class="row-btns pick-bar">
    <button class="btn ghost" name="answer" value="cancel" type="submit" formnovalidate>ביטול</button>
    <button class="btn primary" name="answer" value="pick" type="submit">אישור</button>
  </div>
</form>
HTML);
} catch (AppError $e) {
    if ($e->status === 401 && $e->codeName === 'unauthorized') pickFail('נדרשת כניסה דרך הכלים הדיגיטליים.', 401);
    pickFail($e->getMessage(), $e->status >= 400 && $e->status < 500 ? $e->status : 400);
}
