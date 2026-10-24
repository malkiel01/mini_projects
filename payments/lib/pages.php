<?php
/**
 * הדפים שהלקוח המשלם רואה: דף התשלום (pay/) ו"החשבון שלי" (portal/). ציבוריים — ההגנה היא מזהה אקראי
 * חד-פעמי, סשן קצר בעוגייה משלנו, ואסימון נגד זיוף בכל טופס. ממותגים לפי המערכת, לא לפי הפלטפורמה.
 */

declare(strict_types=1);

require_once __DIR__ . '/jobs.php';

const PORTAL_TTL = 1800;
const PORTAL_COOKIE = 'payments_portal';

function h(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function page_headers(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self' https:");
}

/** מעטפת דף: כותרת במיתוג המערכת, הסגנון של התוסף. ‏$depth — כמה תיקיות מתחת לשורש התוסף. */
function page_open(string $title, string $brand, int $depth = 1): void
{
    page_headers();
    $up = str_repeat('../', $depth);
    echo '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
        . '<meta name="robots" content="noindex"><title>' . h($title) . '</title>'
        . '<link rel="stylesheet" href="' . $up . 'assets/css/app.css"></head><body>'
        . '<header class="top"><div class="titles"><h1>' . h($brand) . '</h1><div class="sub">' . h($title) . '</div></div></header>'
        . '<main id="main" tabindex="-1">';
}

function page_close(string $note = ''): void
{
    $up = str_repeat('../', 1);
    echo ($note !== '' ? '<p class="hint" style="margin-top:16px">' . $note . '</p>' : '') . '</main><script src="' . $up . 'assets/js/pages.js"></script></body></html>';
}

function page_error(string $msg, string $brand = 'תשלום', int $depth = 1): never
{
    http_response_code(400);
    page_open('שגיאה', $brand, $depth);
    echo '<p class="error" role="alert">' . h($msg) . '</p>';
    page_close();
    exit;
}

function money_html(int $agorot): string
{
    return h(money_text($agorot));
}

// ---------- אסימון נגד זיוף (CSRF) — קשור למזהה הקנייה / לסשן ----------

function form_token(string $bind): string
{
    return hash_hmac('sha256', 'form:' . $bind, secret_key());
}

function form_check(string $bind): void
{
    if (!hash_equals(form_token($bind), (string)($_POST['t'] ?? ''))) page_error('הטופס פג תוקף — רענן את הדף ונסה שוב.');
}

// ---------- "החשבון שלי" ----------

/** portal_session (API): קישור חד-פעמי לחשבון הלקוח, 15 דקות לפתוח אותו. */
function portal_session_create(array $client, string $env, array $in): array
{
    $cus = customer_find($client, $env, external_id($in));
    $ret = (string)($in['return_url'] ?? '');
    if (!valid_url($ret)) throw new ApiError('return_url — כתובת https מלאה', 'bad_url');
    $token = bin2hex(random_bytes(24));
    db_run('INSERT INTO portal_session (hash, customer, return_url, expires_at) VALUES (?, ?, ?, ?)', ['open:' . hash('sha256', $token), $cus['id'], $ret, now() + 900]);
    return ['url' => base_url() . 'portal/?s=' . $token, 'expires_in' => 900];
}

/** פותח את הקישור החד-פעמי → עוגייה של סשן. מחזיר את הלקוח, או null. */
function portal_customer(): ?array
{
    if (isset($_GET['s'])) {
        $open = db_one('SELECT * FROM portal_session WHERE hash = ? AND expires_at > ?', ['open:' . hash('sha256', (string)$_GET['s']), now()]);
        if (!$open) return null;
        db_run('DELETE FROM portal_session WHERE hash = ?', [$open['hash']]); // חד-פעמי
        $sid = bin2hex(random_bytes(24));
        db_run('INSERT INTO portal_session (hash, customer, return_url, expires_at) VALUES (?, ?, ?, ?)', [hash('sha256', $sid), $open['customer'], $open['return_url'], now() + PORTAL_TTL]);
        setcookie(PORTAL_COOKIE, $sid, ['expires' => 0, 'path' => parse_url(base_url(), PHP_URL_PATH) . 'portal/', 'secure' => str_starts_with(base_url(), 'https'), 'httponly' => true, 'samesite' => 'Lax']);
        header('Location: ./');
        exit;
    }
    $sid = (string)($_COOKIE[PORTAL_COOKIE] ?? '');
    if (strlen($sid) !== 48) return null;
    $row = db_one('SELECT * FROM portal_session WHERE hash = ? AND expires_at > ?', [hash('sha256', $sid), now()]);
    if (!$row) return null;
    db_run('UPDATE portal_session SET expires_at = ? WHERE hash = ?', [now() + PORTAL_TTL, $row['hash']]);
    $cus = db_one('SELECT * FROM customer WHERE id = ?', [$row['customer']]);
    return $cus ? $cus + ['_sid' => $row['hash'], '_return' => $row['return_url']] : null;
}
