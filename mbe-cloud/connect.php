<?php
/**
 * החיבור של משתמש לאפליקציה — בלי מסך אישור (docs/spec-6-admin-layers.md 3.1). המנהל כבר החליט אילו שכבות דלוקות.
 *
 *   GET connect.php?client=<id>&state=<אקראי>&return=<כתובת רשומה>&sub&exp&sig
 *       → <return>?code=…&state=…   (המדיניות לא ריקה)
 *       → <return>?error=off&state=…   (המנהל לא הדליק כלום — בלי חיבור)
 *   scope בבקשה — מתעלמים (ההיקף במדיניות). לקוח pending — "ממתין לאישור מנהל הענן", בלי כפתור.
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

try {
    $a = requireAccount();
    $req = connectRequest($_GET);
    if (!sameSub($a, $req['sub'])) {
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

    // spec-6 3.1: אין טופס. כל הבדיקות של 6.3 כבר עברו (החתימה, ה-sub מול מי שנכנס דרך השער) — החיבור נוצר מיד.
    // המדיניות ריקה (המנהל לא הדליק כלום) — חזרה בלי חיבור (?error=off → במודול cloud=off)
    if (!$req['scope']) {
        header('Location: ' . withQuery($req['return'], ['error' => 'off', 'state' => $req['state']]), true, 303);
        exit;
    }
    header('Location: ' . approveConnect($a, $req), true, 303);
    exit;
} catch (AppError $e) {
    if ($e->status === 401 && $e->codeName === 'unauthorized') fail('נדרשת כניסה דרך הכלים הדיגיטליים.');
    fail($e->getMessage());
}
