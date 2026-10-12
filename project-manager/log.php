<?php
/**
 * קריאת היומן מבחוץ — לחיבור לצ'אט (README, "יומן").
 *
 *   log.php?token=<אסימון>&format=text|json&level=info|warn|error
 *          &category=api|auth|admin|data|client|php&q=<טקסט>&since=<ISO>&limit=<עד 1000>
 *
 * ציבורי בשער של הפלטפורמה ("public" ב-app.json), ולכן נבדק כאן: בלי אסימון
 * תקף — 403. האסימון מתחלף לבד אחת לשבוע; המפתח רואה אותו בהגדרות ← יומן, ויכול
 * להחליף מיד. קריאה בלבד — אין כאן שום פעולה שמשנה דבר.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/log.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex');

$token = (string)($_GET['token'] ?? '');
try {
    $ok = pm_log_token_valid($token);
} catch (Throwable) {
    $ok = false;
}
if (!$ok) {
    pm_log('warn', 'auth', 'log.read_denied', ['message' => 'אסימון קריאה שגוי או שפג', 'data' => ['token_length' => strlen($token)]]);
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 — אסימון לא תקף או שפג (מתחלף אחת לשבוע; האסימון הנוכחי בהגדרות ← יומן)\n";
    exit;
}

$entries = pm_log_query($_GET + ['limit' => 300]);
pm_log('info', 'admin', 'log.read', ['data' => ['count' => count($entries), 'filters' => array_diff_key($_GET, ['token' => 1])]]);

if (($_GET['format'] ?? 'text') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['stats' => pm_log_stats(), 'entries' => $entries], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
header('Content-Type: text/plain; charset=utf-8');
$s = pm_log_stats();
echo "# יומן ניהול פרויקטים — {$s['total']} רשומות מאז {$s['oldest']}; 24 שעות: "
   . "{$s['last24h']['error']} שגיאות, {$s['last24h']['warn']} אזהרות, {$s['last24h']['info']} מידע\n";
echo "# החדש למעלה. עמודות: זמן · רמה · קטגוריה · אירוע · משתמש · IP · פעולה →קוד משך | הודעה {נתונים} req\n\n";
foreach ($entries as $r) echo pm_log_line($r), "\n";
