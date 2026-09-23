<?php
/**
 * ה-API של סורק הוואטסאפ.
 *
 * שני קהלים:
 *   הבעלים — דרך הדפדפן: התקנה, כניסה, הגדרת חשבונות, שאלות ותשובות.
 *   הגשר   — אפליקציית האנדרואיד: מזרימה הודעות שנסרקו.
 *
 * כל בקשה POST עם JSON, פרט ל-GET של state (מי אני / מה מוגדר).
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/accounts.php';
require_once __DIR__ . '/../lib/ingest.php';
require_once __DIR__ . '/../lib/query.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/errors.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function fail(string $message, int $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
function ok(array $payload = []) {
    echo json_encode(['success' => true] + $payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$in     = body();

try {
    switch ($action) {

        /* ── מצב כללי (GET) ─────────────────────────────────────── */
        case 'state':
            ok([
                'configured' => ownerConfigured(),
                'owner'      => isOwner(),
            ]);

        /* ── התקנה וכניסה ───────────────────────────────────────── */
        case 'setup':
            if (ownerConfigured()) fail('כבר הוגדר');
            setupOwner((string) ($in['password'] ?? ''));
            login((string) ($in['password'] ?? ''));
            ok(['pair_token' => owner()['pair_token']]);

        case 'login':
            if (!login((string) ($in['password'] ?? ''))) fail('סיסמה שגויה', 401);
            ok();

        case 'logout':
            logout();
            ok();

        /* ── הגדרות (בעלים) ─────────────────────────────────────── */
        case 'settings':
            requireOwner();
            ok([
                'ai'          => aiSettingsView(),
                'pair_token'  => owner()['pair_token'],
            ]);

        case 'save_ai':
            requireOwner();
            saveAiSettings(
                (string) ($in['provider'] ?? 'anthropic'),
                (string) ($in['model'] ?? ''),
                isset($in['key']) ? (string) $in['key'] : null
            );
            ok(['ai' => aiSettingsView()]);

        case 'rotate_token':
            requireOwner();
            ok(['pair_token' => rotatePairToken()]);

        /* ── חשבונות (בעלים) ────────────────────────────────────── */
        case 'accounts':
            requireOwner();
            ok(['accounts' => listAccounts()]);

        case 'add_account':
            requireOwner();
            $a = createAccount((string) ($in['label'] ?? ''), (string) ($in['kind'] ?? 'personal'));
            ok(['account' => $a]);

        case 'delete_account':
            requireOwner();
            deleteAccount((int) ($in['id'] ?? 0));
            ok();

        /* ── שאלה ותשובה (בעלים) ───────────────────────────────── */
        case 'ask':
            requireOwner();
            $question = trim((string) ($in['question'] ?? ''));
            if ($question === '') fail('לא נשאלה שאלה');
            if (mb_strlen($question) > 1000) fail('השאלה ארוכה מדי');

            $accountId = isset($in['account_id']) && $in['account_id'] !== '' ? (int) $in['account_id'] : null;
            $from = isset($in['from']) && $in['from'] !== '' ? (int) strtotime((string) $in['from']) : null;
            $to   = isset($in['to'])   && $in['to']   !== '' ? (int) strtotime((string) $in['to'] . ' 23:59:59') : null;

            $collected = collectMessages($accountId, $from ?: null, $to ?: null);
            $answer = answerQuestion(ownerAiConn(), $question, $collected);
            ok([
                'answer'     => $answer,
                'used'       => count($collected['messages']),
                'truncated'  => $collected['truncated'],
            ]);

        /* ── בליעה (גשר) ────────────────────────────────────────── */
        case 'ingest':
            requireBridge();
            $accountId = (int) ($in['account_id'] ?? 0);
            if (!getAccount($accountId)) fail('חשבון לא קיים');
            $rows = is_array($in['messages'] ?? null) ? $in['messages'] : [];
            if (count($rows) > 2000) fail('אצווה גדולה מדי — עד 2000 הודעות בבקשה');
            ok(ingestBatch($accountId, $rows));

        default:
            fail('פעולה לא מוכרת: ' . $action, 404);
    }
} catch (AppError $e) {
    fail($e->getMessage(), 400);
} catch (InvalidArgumentException $e) {
    fail($e->getMessage(), 400);
} catch (Throwable $e) {
    fail('שגיאת שרת', 500);
}
