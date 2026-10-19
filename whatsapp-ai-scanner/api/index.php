<?php
/**
 * ה-API של סורק הוואטסאפ.
 *
 * שני קהלים:
 *   המשתמש — דרך הדפדפן: כניסה, הגדרת חשבונות, שאלות ותשובות. מחוברת
 *            לפלטפורמה — הכניסה אוטומטית (resolveUser); מנותקת — בסיסמה.
 *   הגשר   — אפליקציית האנדרואיד: מזרימה הודעות שנסרקו, באסימון של משתמש.
 *
 * כל משתמש רואה רק את שלו: כל פעולה רצה בשם currentUserId בלבד.
 *
 * כל בקשה POST עם JSON, פרט ל-GET של state (מי אני / מה מוגדר).
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/accounts.php';
require_once __DIR__ . '/../lib/ingest.php';
require_once __DIR__ . '/../lib/query.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/media.php';
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
function str_field(array $in, string $key, int $max, string $default = ''): string {
    $v = $in[$key] ?? $default;
    if (!is_string($v)) return $default;
    return mb_substr(trim($v), 0, $max);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$in     = body();

try {
    resolveUser();

    switch ($action) {

        /* ── מצב כללי ───────────────────────────────────────────── */
        case 'state':
            $me = me();
            ok([
                'user'       => isUser(),
                // מחוברת לפלטפורמה: אין מסך כניסה ואין כפתור יציאה — היציאה היא מהפלטפורמה.
                'platform'   => platformIdentity() !== null,
                'name'       => $me['name'] ?? '',
                'configured' => anyUser(),
                'can_link'   => canLinkOld(),
                'offer_link' => canLinkOld() && empty($me['link_dismissed']),
            ]);

        /* ── הכניסה הישנה — רק כשהאפליקציה מנותקת ───────────────── */
        case 'setup':
            if (!passwordLoginAllowed()) fail('הכניסה היא דרך הכלים הדיגיטליים', 403);
            if (anyUser()) fail('כבר הוגדר');
            setupFirstUser((string) ($in['password'] ?? ''));
            login((string) ($in['password'] ?? ''));
            ok(['pair_token' => me()['pair_token']]);

        case 'login':
            if (!passwordLoginAllowed()) fail('הכניסה היא דרך הכלים הדיגיטליים', 403);
            if (!login((string) ($in['password'] ?? ''))) fail('סיסמה שגויה', 401);
            ok();

        case 'logout':
            logout();
            ok();

        /* ── מסך החיבור החד-פעמי ────────────────────────────────── */
        case 'link_account':
            requireUser();
            linkOldAccount((string) ($in['password'] ?? ''));
            ok(['name' => me()['name'] ?? '']);

        case 'dismiss_link':
            requireUser();
            dismissLink();
            ok();

        /* ── הגדרות (משתמש) ─────────────────────────────────────── */
        case 'settings':
            requireUser();
            ok([
                'ai'          => aiSettingsView(),
                'pair_token'  => me()['pair_token'],
                'log_token'   => myLogToken(),
            ]);

        case 'save_ai':
            requireUser();
            saveAiSettings(
                (string) ($in['provider'] ?? 'anthropic'),
                (string) ($in['model'] ?? ''),
                isset($in['key']) ? (string) $in['key'] : null,
                isset($in['answer_lang']) ? (string) $in['answer_lang'] : null
            );
            ok(['ai' => aiSettingsView()]);

        case 'rotate_token':
            requireUser();
            ok(['pair_token' => rotatePairToken()]);

        case 'rotate_log_token':
            requireUser();
            ok(['log_token' => rotateLogToken()]);

        case 'list_models':
            requireUser();
            ok(['models' => fetchProviderModels()]);

        /* ── חשבונות (משתמש) ────────────────────────────────────── */
        case 'accounts':
            requireUser();
            ok(['accounts' => listAccounts()]);

        case 'add_account':
            requireUser();
            $a = createAccount(
                (string) ($in['label'] ?? ''),
                (string) ($in['kind'] ?? 'personal'),
                (string) ($in['package'] ?? '')
            );
            ok(['account' => $a]);

        case 'set_account_package':
            requireUser();
            setAccountPackage((int) ($in['id'] ?? 0), (string) ($in['package'] ?? ''));
            ok(['accounts' => listAccounts()]);

        case 'delete_account':
            requireUser();
            deleteAccount((int) ($in['id'] ?? 0));
            ok();

        /* ── שאלה ותשובה (משתמש) ───────────────────────────────── */
        case 'ask':
            requireUser();
            $question = trim((string) ($in['question'] ?? ''));
            if ($question === '') fail('לא נשאלה שאלה');
            if (mb_strlen($question) > 1000) fail('השאלה ארוכה מדי');

            $accountId = isset($in['account_id']) && $in['account_id'] !== '' ? (int) $in['account_id'] : null;
            $from = isset($in['from']) && $in['from'] !== '' ? (int) strtotime((string) $in['from']) : null;
            $to   = isset($in['to'])   && $in['to']   !== '' ? (int) strtotime((string) $in['to'] . ' 23:59:59') : null;

            $collected = collectMessages($accountId, $from ?: null, $to ?: null, $question);
            $answer = answerQuestion(userAiConn(), $question, $collected, userAnswerLang());
            ok([
                'answer'       => $answer,
                'used'         => count($collected['messages']),
                'scanned'      => $collected['scanned'] ?? null,
                'by_relevance' => $collected['by_relevance'] ?? false,
                'truncated'    => $collected['truncated'],
            ]);

        // רשימת החשבונות לגשר — כדי שהאפליקציה תציג בורר "חשבון פעיל"
        // במקום הקלדת מספר. מורשה באסימון צימוד.
        case 'bridge_accounts':
            requireBridge();
            ok(['accounts' => array_map(fn($a) => [
                'id'      => (int) $a['id'],
                'label'   => $a['label'],
                'kind'    => $a['kind'],
                'package' => $a['wa_package'] ?? '',
            ], listAccounts())]);

        /* ── בדיקת חיבור (גשר) ──────────────────────────────────── */
        // מאמת אסימון+כתובת+רשת בנפרד מהסריקה, כדי לבודד "בעיית חיבור"
        // מ"בעיית קריאת מסך".
        case 'ping':
            requireBridge();
            $accountId = (int) ($in['account_id'] ?? 0);
            $exists = (bool) getAccount($accountId);
            logEvent('bridge', 'ping', '200', ['account' => $accountId, 'exists' => $exists]);
            ok(['pong' => true, 'account_exists' => $exists]);

        // המכשיר דוחף אבחון: מה נסרק על המסך, כמה טקסטים, ודגימה.
        case 'log':
            requireBridge();
            logEvent('device',
                str_field($in, 'tag', 40, 'scan'),
                str_field($in, 'status', 60, ''),
                str_field($in, 'detail', 3500));
            ok();

        // קריאת היומן — של משתמש אחד. מורשית באסימון היומן שלו (כדי שאפשר
        // יהיה למשוך מבחוץ) או בכניסה שלו (לתצוגה באתר).
        case 'logs':
            $token = (string) ($_GET['token'] ?? ($in['token'] ?? ''));
            if ($token !== '' ? !useLogToken($token) : !isUser()) fail('אסימון יומן שגוי', 401);
            ok(['logs' => recentLogs((int) ($_GET['limit'] ?? ($in['limit'] ?? 300)))]);

        /* ── בליעה (גשר) ────────────────────────────────────────── */
        case 'ingest':
            requireBridge();
            $accountId = (int) ($in['account_id'] ?? 0);
            if (!getAccount($accountId)) fail('חשבון לא קיים');
            $rows = is_array($in['messages'] ?? null) ? $in['messages'] : [];
            if (count($rows) > 2000) fail('אצווה גדולה מדי — עד 2000 הודעות בבקשה');
            $res = ingestBatch($accountId, $rows);
            logEvent('bridge', 'ingest', '200', [
                'account'  => $accountId,
                'received' => count($rows),
                'ingested' => $res['ingested'],
                'skipped'  => $res['skipped'],
            ]);
            ok($res);

        // קובץ מדיה יחיד (PDF/תמונה) בבסיס64. נחלץ תוכן ונשמר כהודעה.
        case 'media':
            requireBridge();
            $accountId = (int) ($in['account_id'] ?? 0);
            if (!getAccount($accountId)) fail('חשבון לא קיים');
            $filename = str_field($in, 'filename', 200, 'file');
            $mime     = str_field($in, 'mime', 100);
            $b64      = (string) ($in['data'] ?? '');
            $bytes    = base64_decode($b64, true);
            if ($bytes === false || $bytes === '') fail('קובץ ריק או פגום');
            if (strlen($bytes) > 16 * 1024 * 1024) fail('קובץ גדול מדי (מעל 16MB)');
            $sentAt = (int) ($in['sent_at'] ?? 0);
            try {
                $r = ingestMediaFile($accountId, $filename, $mime, $bytes, $sentAt);
                logEvent('bridge', 'media', $r['status'], ['file' => $filename, 'mime' => $mime] + $r);
                ok($r);
            } catch (Throwable $e) {
                logEvent('bridge', 'media', 'error', ['file' => $filename, 'error' => $e->getMessage()]);
                throw $e;
            }

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
