<?php
/**
 * הכניסה לצ'אט (lib/agent.php): שם משתמש וסיסמה שנוצרו בכלי, בלי עוגייה ובלי השער של הפלטפורמה (ציבורי ב-app.json —
 * ההגנה כאן היא הכניסה עצמה). X1: פעולות של עורך על קבצי הפרויקט (📁) והמילים — כל כתיבה דרך מנוע הקבצים, עם גרסה "🤖 <שם הכניסה>". אין פרסום ואין שום דבר אחר.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/members.php';
require_once __DIR__ . '/lib/import.php';
require_once __DIR__ . '/lib/agent.php';
require_once __DIR__ . '/lib/devlog.php';

devlog_begin('agent');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
require_once __DIR__ . '/lib/csp.php';
csp_api_headers();          // C1: תשובת API לעולם אינה מסמך

function agent_out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = (string)($_GET['action'] ?? 'help');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// X1: הפעולות על קבצי הפרויקט. הישנות (draft, draft_save, render, page, code*, publish_preview) — נמחקו; אין פרסום
const AGENT_POST = ['write', 'edit', 'create', 'words_save', 'word_save', 'word_delete', 'topic_op'];

try {
    if (in_array($action, AGENT_POST, true) && $method !== 'POST') throw new ApiError('נדרש POST', 405);
    $body = $method === 'POST' ? json_decode((string)file_get_contents('php://input'), true) : [];
    if (!is_array($body)) throw new ApiError('הגוף אינו JSON תקין');
    $user = agent_auth();
    devlog_user($user);
    agent_rate($user);
    agent_authorize($action, $user);                    // P1: מול ACTION_ROLES, כמו כל משתמש
    // מחיקה ונושאים מחזירים בכלי את כל הרשימה — לצ'אט מספיק "בוצע"
    $done = fn(array $r) => ['done' => true];
    // לפני הפעולה הראשונה של הצ'אט (ואחרי חצי שעה בלי פעולה) — נקודת שחזור: "בטל את מה שהצ'אט עשה" בלחיצה
    if (in_array($action, AGENT_POST, true)) snapshot_before_chat($user);
    $result = match ($action) {
        'help' => agent_help($user),
        'tree' => agent_tree($user),
        'read' => agent_read((string)($_GET['path'] ?? '')),
        'write' => agent_write($user, $body),
        'edit' => agent_edit($user, $body),
        'create' => agent_create($user, $body),
        'search' => pf_search(['q' => (string)($_GET['q'] ?? ''), 'regex' => !empty($_GET['regex']), 'case' => !empty($_GET['case'])]),
        'versions' => agent_versions((string)($_GET['path'] ?? '')),
        'preview_url' => agent_preview_url($user),
        'words' => agent_read(pf_words_path() ?? throw new ApiError('אין בפרויקט קובץ של טבלת המילים (kol.json ← data.words)', 404)),
        'words_save' => agent_words_save($user, $body),
        'word_save' => word_save($user, $body + ['light' => true]),
        'word_delete' => $done(word_delete($user, $body)),
        'topic_op' => $done(topic_op($user, $body)),
        default => throw new ApiError('פעולה לא מוכרת. רשימת הפעולות: ?action=help', 404, in_array($action, AGENT_GONE, true) ? ['moved' => 'files'] : []),
    };
    agent_out(['ok' => true] + $result);
} catch (ApiError $e) {
    devlog_exception($e);
    agent_out(['ok' => false, 'error' => $e->getMessage()] + $e->extra, $e->getCode() ?: 400);
} catch (Throwable $e) {
    devlog_exception($e);
    error_log('kol-maroko agent [' . devlog_req() . ']: ' . $e->getMessage());
    agent_out(['ok' => false, 'error' => 'תקלה בשרת · מזהה ' . devlog_req(), 'req' => devlog_req()], 500);
}
