<?php
/**
 * "X מבקשת גישה לענן שלך" — המשתמש מאשר פעם אחת, והאפליקציה מקבלת קוד חד-פעמי (docs/spec-1-skeleton.md, סעיף 7.2).
 *
 *   GET  connect.php?client=<id>&scope=files&state=<אקראי>&return=<כתובת רשומה>
 *        → כבר אושר (אותן הרשאות או פחות) — ישר חזרה עם ?code&state. אחרת — מסך אישור.
 *   POST connect.php (אישור / ביטול, עם אסימון טופס) → <return>?code=…&state=…  או  ?error=access_denied&state=…
 *
 * מאחורי השער: מי שלא מחובר מגיע לכניסה וחוזר לכאן (לכן הכתובת אינה ב-public).
 *
 * רק אותו חשבון (spec-0-vision.md 6.3, הכרעת בעל המערכת): הבקשה נושאת את ה-sub של הפלטפורמה של המשתמש באפליקציה, חתום
 * במפתח הלקוח (&sub&exp&sig). sub שונה מזה של מי שנכנס לענן — "החיבור נדחה", בלי כפתור אישור ובלי עקיפה, גם למנהל.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/clients.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; form-action 'self' https:; frame-ancestors 'none'");
header('X-Frame-Options: DENY');

function page(string $title, string $body): never {
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
<main id="main" class="connect">$body</main>
</body>
</html>
HTML;
    exit;
}

function fail(string $msg): never {
    http_response_code(400);
    page('שגיאה', '<p class="error" role="alert">' . htmlspecialchars($msg) . '</p>');
}

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_name('MBECLOUD');   // עוגייה משלנו — לא של הפלטפורמה ולא של אפליקציה אחרת
    session_set_cookie_params(['path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

try {
    $a = requireAccount();
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $q = $post ? ($_SESSION['connect'] ?? null) : $_GET;
    if (!is_array($q)) fail('הבקשה פגה — חזרו לאפליקציה ונסו שוב.');
    $req = connectRequest($q);
    if (!sameSub($a, $req['sub'])) {
        unset($_SESSION['connect'], $_SESSION['connect_form']);
        error_log("mbe-cloud: connect.php account_mismatch (client {$req['client']['id']})");
        http_response_code(403);
        $name = htmlspecialchars($req['client']['name']);
        $who = htmlspecialchars($a['name']);
        $back = htmlspecialchars(withQuery($req['return'], ['error' => 'account_mismatch', 'state' => $req['state']]));
        page('החיבור נדחה', <<<HTML
<p class="error" role="alert"><b>החיבור נדחה: החשבון באפליקציה אינו החשבון שלך.</b></p>
<p class="hint">נכנסת לענן בתור <b>$who</b>, אבל באפליקציה <b>$name</b> מחובר חשבון אחר. הענן מתחבר רק לאפליקציה של אותו אדם.</p>
<p class="hint">אם גם החשבון באפליקציה שלך — היכנסו לשניהם באותו חשבון ונסו שוב.</p>
<div class="row-btns"><a class="btn ghost" href="$back">חזרה לאפליקציה</a></div>
HTML);
    }

    if ($post) {
        $form = (string) ($_POST['form'] ?? '');
        if (!hash_equals((string) ($_SESSION['connect_form'] ?? ''), $form) || $form === '') fail('הבקשה פגה — חזרו לאפליקציה ונסו שוב.');
        unset($_SESSION['connect'], $_SESSION['connect_form']);
        $to = ($_POST['answer'] ?? '') === 'allow'
            ? approveConnect($a, $req)
            : withQuery($req['return'], ['error' => 'access_denied', 'state' => $req['state']]);
        header('Location: ' . $to, true, 303);
        exit;
    }

    if (alreadyGranted($a, $req)) {
        header('Location: ' . approveConnect($a, $req), true, 303);
        exit;
    }

    $_SESSION['connect'] = ['client' => $req['client']['id'], 'scope' => implode(',', $req['scope']), 'state' => $req['state'], 'return' => $req['return'],
                            'sub' => $q['sub'], 'exp' => $q['exp'], 'sig' => $q['sig']];
    $_SESSION['connect_form'] = $form = b64u(random_bytes(18));
    $name = htmlspecialchars($req['client']['name']);
    $host = htmlspecialchars((string) parse_url($req['return'], PHP_URL_HOST));
    $who = htmlspecialchars($a['name']);
    // חיבור קיים — רק השכבה החדשה (7.3 כלל 3). היא מתווספת לאותו חיבור
    $live = liveConnection($a['id'], $req['client']['id']);
    $ask = $live ? array_values(array_diff($req['scope'], json_decode($live['scope'], true) ?: [])) : $req['scope'];
    $rows = '';
    foreach ($ask as $s) {
        $ic = ['files' => '📁', 'libs' => '📚', 'appdata' => '🗂️', 'ai' => '🤖'][scopeLayer($s)] ?? '•';
        $rows .= '<li class="acc-row"><span class="use-ic" aria-hidden="true">' . $ic . '</span><div class="body"><div class="l1">'
               . htmlspecialchars(SCOPE_NAMES[$s]) . '</div></div></li>';
    }
    $askTitle = $live ? 'מה עוד היא תוכל לעשות' : 'מה היא תוכל לעשות';
    page("חיבור $name", <<<HTML
<div class="summary">
  <div class="line"><b>$name</b> <span class="muted">מבקשת גישה לענן שלך</span></div>
  <div class="hint">מחובר בתור $who · חזרה אל $host</div>
</div>
<h2 class="sec-title">$askTitle</h2>
<ul class="list">$rows</ul>
<p class="hint">אפשר לנתק בכל רגע — בענן, בלשונית "🔗 חיבורים", או מתוך האפליקציה.</p>
<form method="post" class="row-btns">
  <input type="hidden" name="form" value="$form">
  <button class="btn ghost" name="answer" value="deny" type="submit">ביטול</button>
  <button class="btn primary" name="answer" value="allow" type="submit">אישור</button>
</form>
HTML);
} catch (AppError $e) {
    if ($e->status === 401 && $e->codeName === 'unauthorized') fail('נדרשת כניסה דרך הכלים הדיגיטליים.');
    fail($e->getMessage());
}
