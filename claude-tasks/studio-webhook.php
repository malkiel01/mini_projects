<?php
/**
 * סטודיו — קולט webhook מ-Anthropic ("הסשן נעצר / התחיל / הסתיים").
 *
 * ציבורי (מוצהר ב-app.json): Anthropic לא מתחברת. ההגנה היא החתימה.
 * תשובה שאינה 2xx נספרת נגד נקודת הקצה, ו-3xx משבית אותה מיד — לכן הקובץ
 * הזה אף פעם לא מפנה, ועונה 2xx גם על אירוע שאינו שלנו.
 *
 * ה-webhook נושא רק סוג ומזהה. הוא לא מקור אמת: הוא רק אומר "לכו למשוך".
 * אם הוא לא מגיע (חסימה באחסון, נקודת קצה מושבתת) — הקורן משלים.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/studio/studio.php';
require_once __DIR__ . '/lib/studio/webhook.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

function whEnd(int $code, string $note): never {
    http_response_code($code);
    echo json_encode(['ok' => $code < 300, 'note' => $note]);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') whEnd(405, 'post only');

$raw = (string) file_get_contents('php://input');
if ($raw === '' || strlen($raw) > 65536) whEnd(400, 'bad body');
$ev = json_decode($raw, true);
$type = (string) ($ev['data']['type'] ?? '');
$sid  = (string) ($ev['data']['id'] ?? '');
if (!is_array($ev) || $type === '' || $sid === '') whEnd(400, 'bad event');

try {
    // ה-webhook הוא של כל סביבת העבודה. מה שאינו סשן של הסטודיו — מתעלמים, ב-2xx.
    if (!str_starts_with($type, 'session.')) whEnd(200, 'ignored');
    studioMigrate();
    $st = db()->prepare("SELECT * FROM studio_convs WHERE session_id = ? AND session_id <> ''");
    $st->execute([$sid]);
    $conv = $st->fetch();
    if (!$conv) whEnd(200, 'ignored');

    $uid = (int) $conv['user_id'];
    $secret = decryptSecret((string) (studioSettings($uid)['webhook_secret'] ?? ''));
    $ok = studioWebhookVerify($secret, $raw,
        (string) ($_SERVER['HTTP_WEBHOOK_ID'] ?? ''),
        (string) ($_SERVER['HTTP_WEBHOOK_TIMESTAMP'] ?? ''),
        (string) ($_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? ''));
    if (!$ok) whEnd(400, 'invalid signature');

    // אותו אירוע פעם נוספת = ניסיון חוזר. מתעדים פעם אחת.
    $whId = (string) ($ev['id'] ?? $_SERVER['HTTP_WEBHOOK_ID']);
    $ins = db()->prepare('INSERT OR IGNORE INTO studio_webhooks (id, at) VALUES (?, ?)');
    $ins->execute([mb_substr($whId, 0, 120), nowTs()]);
    if ($ins->rowCount() === 0) whEnd(200, 'duplicate');
    db()->prepare('DELETE FROM studio_webhooks WHERE at < ?')->execute([nowTs() - 7 * 86400]);
    studioSaveSettings($uid, ['webhook_last_at' => nowTs()]);

    try {
        // אותה תפיסה כמו של הדפדפן והקורן — שני סנכרונים של אותה שיחה לא רצים במקביל.
        // מישהו אחר מסנכרן ממש עכשיו? מסמנים שנשאר מה למשוך, והוא או הקורן ישלימו.
        $before = (int) $conv['synced_at'];
        $after = studioSyncIfDue($conv, studioKey($uid), 1);
        if ((int) $after['synced_at'] === $before) studioUpdateConv((int) $conv['id'], ['more' => 1]);
    } catch (Throwable $e) {
        // המשיכה נכשלה — מסמנים שהשיחה דורשת סנכרון, והקורן ימשוך. ל-Anthropic עונים 2xx: קיבלנו.
        studioUpdateConv((int) $conv['id'], ['more' => 1]);
        error_log('[studio-webhook] sync: ' . $e->getMessage());
    }
    whEnd(200, 'synced');
} catch (Throwable $e) {
    error_log('[studio-webhook] ' . $e->getMessage());
    whEnd(500, 'error');
}
