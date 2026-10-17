<?php
/**
 * כניסה מאוחדת — נקודות הקצה (lib/sso.php: הלוגיקה; docs/sso.md: המדריך לאפליקציות).
 *
 *   GET  sso.php?client=<id>&state=<…>&return=<כתובת חזרה>   — הדפדפן של המשתמש
 *   POST sso.php?client=…&state=…&return=…                    — "המשך" / "ביטול" במסך האישור
 *   GET  sso.php?action=key                                   — המפתח הציבורי לבדיקת החתימה
 *
 * בחזרה: <return>?token=<זהות חתומה>&state=… (או ?error=access_denied&state=…).
 * כתובת חזרה שאינה ב-"sso.return" של ה-app.json — רק דף שגיאה, לעולם לא הפניה.
 */

declare(strict_types=1);

require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/sso.php';

header('X-Frame-Options: DENY');                          // מסך האישור לא ייטען במסגרת זרה (clickjacking)
header("Content-Security-Policy: frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');                   // הקוד בכתובת לא ידלוף הלאה
header('Cache-Control: no-store');

try {
    if (($_GET['action'] ?? '') === 'key') {
        header('Access-Control-Allow-Origin: *');        // ציבורי בכוונה — זה רק המפתח לבדיקה
        header('Cache-Control: public, max-age=3600');
        json_out(['alg' => 'Ed25519', 'key' => sso_keys()['public_key'], 'issuer' => sso_issuer()]);
    }
    sso_authorize();
} catch (ApiError $e) {
    sso_page($e->status, 'לא ניתן להמשיך', $e->getMessage());
} catch (Throwable $e) {
    error_log('platform sso: ' . $e);
    sso_page(500, 'שגיאה בשרת', 'נסו שוב בעוד רגע.');
}

function sso_authorize(): never
{
    $client = sso_client(is_string($_GET['client'] ?? null) ? $_GET['client'] : '');
    if (!$client) throw new ApiError('האפליקציה לא רשומה לכניסה המאוחדת', 400);
    if ($client['disabled']) throw new ApiError('הכניסה המאוחדת לאפליקציה הזו כבויה כרגע', 403);
    $return = is_string($_GET['return'] ?? null) ? $_GET['return'] : '';
    if (!in_array($return, $client['returns'], true)) {
        sso_log($client['id'], null, 'bad-return');
        throw new ApiError('כתובת החזרה אינה רשומה לאפליקציה הזו', 400);
    }
    $state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
    if (!preg_match('/^[\x21-\x7e]{8,200}$/', $state)) throw new ApiError('state חסר או לא תקין', 400);

    // לא מחובר, או חייב להחליף סיסמה זמנית — לדף הכניסה הרגיל, ומשם חזרה לכאן (?next=).
    $user = current_user();
    if (!$user || !empty($user['must_change_pw'])) {
        header('Location: ' . cookie_path() . '?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''), true, 302);
        exit;
    }
    if ($user['status'] !== 'active') throw new ApiError('החשבון שלך עוד ממתין לאישור המנהל', 403);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!hash_equals($user['csrf'], (string)($_POST['csrf'] ?? ''))) throw new ApiError('בקשה לא תקינה — נסו שוב', 403);
        if (($_POST['decision'] ?? '') !== 'allow') {
            sso_log($client['id'], (int)$user['id'], 'denied');
            sso_go(sso_redirect_url($return, ['error' => 'access_denied', 'state' => $state]));
        }
        sso_grant((int)$user['id'], $client['id']);
    } elseif (!sso_has_consent((int)$user['id'], $client['id'])) {
        sso_consent_page($client, $user, $return);
    }
    sso_go(sso_redirect_url($return, ['token' => sso_issue_token($client, $user, $return, $state), 'state' => $state]));
}

function sso_go(string $url): never
{
    header('Location: ' . $url, true, 302);
    exit;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** מסגרת דף בעיצוב הפלטפורמה (app.css), בלי JavaScript. */
function sso_shell(int $status, string $title, string $inner): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $css = h(cookie_path() . 'platform/assets/app.css');
    echo <<<HTML
    <!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1"><title>{$title}</title>
    <link rel="stylesheet" href="{$css}"><meta name="robots" content="noindex"></head>
    <body><main><div class="auth"><div class="panel sso">{$inner}</div></div></main></body></html>
    HTML;
    exit;
}

function sso_logo(): string
{
    return '<span class="logo" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8" rx="2.5"/>'
        . '<rect x="13" y="3" width="8" height="8" rx="4"/><rect x="3" y="13" width="8" height="8" rx="2.5"/>'
        . '<rect x="13" y="13" width="8" height="8" rx="2.5" opacity=".6"/></svg></span>';
}

function sso_page(int $status, string $title, string $text): never
{
    sso_shell($status, h($title), sso_logo() . '<h2>' . h($title) . '</h2><p class="lead">' . h($text) . '</p>'
        . '<p class="switch"><a href="' . h(cookie_path()) . '">לדף האפליקציות</a></p>');
}

function sso_consent_page(array $client, array $user, string $return): never
{
    $host = h((string)parse_url($return, PHP_URL_HOST));
    $name = h($client['name']);
    $who  = h($user['display_name']);
    $csrf = h($user['csrf']);
    $home = h(cookie_path());
    $logo = sso_logo();
    sso_shell(200, "כניסה ל-$name", <<<HTML
        {$logo}
        <h2>כניסה ל{$name}</h2>
        <p class="lead"><b>{$name}</b> (<bdi>{$host}</bdi>) מבקשת לזהות אותך בעזרת חשבון כלים דיגיטליים.</p>
        <ul class="perks">
          <li><span aria-hidden="true">✓</span><span>יימסרו לה רק השם שלך, המייל ומספר מזהה</span></li>
          <li><span aria-hidden="true">✕</span><span>לא הסיסמה, ולא אילו אפליקציות יש לך</span></li>
          <li><span aria-hidden="true">↩</span><span>אפשר לבטל בכל רגע: בתפריט האישי ← "חיבורים"</span></li>
        </ul>
        <form method="post" class="sso-actions">
          <input type="hidden" name="csrf" value="{$csrf}">
          <button type="submit" name="decision" value="allow">המשך בתור {$who}</button>
          <button type="submit" name="decision" value="deny" class="link-btn">ביטול</button>
        </form>
        <p class="switch">לא {$who}? יוצאים ב<a href="{$home}">דף האפליקציות</a> ונכנסים בחשבון הנכון.</p>
        HTML);
}
