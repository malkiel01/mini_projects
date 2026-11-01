<?php
/**
 * הכניסה לצ'אט (lib/agent.php): שם משתמש וסיסמה שנוצרו בכלי, בלי עוגייה ובלי השער של הפלטפורמה (ציבורי ב-app.json —
 * ההגנה כאן היא הכניסה עצמה). רק פעולות של עורך על הטיוטה, המילים וקבצי הקוד. אין פרסום ואין שום דבר אחר.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/members.php';
require_once __DIR__ . '/lib/import.php';
require_once __DIR__ . '/lib/agent.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function agent_out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = (string)($_GET['action'] ?? 'help');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
const AGENT_POST = ['draft_save', 'render', 'word_save', 'word_delete', 'topic_op', 'code_create', 'code_save'];

try {
    if (in_array($action, AGENT_POST, true) && $method !== 'POST') throw new ApiError('נדרש POST', 405);
    $body = $method === 'POST' ? json_decode((string)file_get_contents('php://input'), true) : [];
    if (!is_array($body)) throw new ApiError('הגוף אינו JSON תקין');
    $user = agent_auth();
    // שמירת הטיוטה — כמו בסטודיו: הנעילה לחבר הזה (אם מישהו אחר עורך עכשיו — 423, לנסות אחר כך)
    $saveDraft = function () use ($user, $body) {
        if (!lock_op($user, ['op' => 'take'])['lock']['mine']) throw new ApiError('הטיוטה פתוחה לעריכה אצל מישהו אחר בסטודיו. לנסות שוב בעוד כמה דקות', 423);
        return draft_save($user, $body);
    };
    // מחיקה ונושאים מחזירים בכלי את כל הרשימה — לצ'אט מספיק "בוצע" (ו-words כשצריך)
    $done = fn(array $r) => ['done' => true];
    // לפני הפעולה הראשונה של הצ'אט (ואחרי חצי שעה בלי פעולה) — נקודת שחזור: "בטל את מה שהצ'אט עשה" בלחיצה
    if (in_array($action, AGENT_POST, true) && $action !== 'render') snapshot_before_chat($user);
    $result = match ($action) {
        'help' => agent_help($user),
        'draft' => draft_get($user),
        'draft_save' => $saveDraft(),
        'render' => render_preview(['page' => $body['page'] ?? json_decode((string)draft_row()['json'], true)]),
        'words' => words_list(),
        'word_save' => word_save($user, $body + ['light' => true]),
        'word_delete' => $done(word_delete($user, $body)),
        'topic_op' => $done(topic_op($user, $body)),
        'code' => code_state(),
        'code_create' => code_create($user),
        'code_save' => code_save($user, $body),
        'code_history' => code_history($_GET),
        'files' => files_list(),
        'publish_preview' => publish_preview($user),
        default => throw new ApiError('פעולה לא מוכרת. רשימת הפעולות: ?action=help', 404),
    };
    agent_out(['ok' => true] + $result);
} catch (ApiError $e) {
    agent_out(['ok' => false, 'error' => $e->getMessage()] + $e->extra, $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('kol-maroko agent: ' . $e);
    agent_out(['ok' => false, 'error' => 'תקלה בשרת'], 500);
}
