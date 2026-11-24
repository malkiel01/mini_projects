<?php
declare(strict_types=1);

/**
 * פרוסה 06 — הפעולות של api.php לבינה המלאכותית (ai_*). החיבור ב-api.php הוא שתי שורות.
 * כל פעולה עוברת את השער, ה-session וה-CSRF של api.php; ההרשאות — can()/need() של 01.
 * הגדרות ומפתח — מנהל (members.manage). צ'אט — כל מי שרואה תיקים (case.view); הכלים עצמם בודקים את השאר.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';

const AI_ACTIONS = ['ai_status', 'ai_settings', 'ai_settings_save', 'ai_key_test', 'ai_chat', 'ai_thread', 'ai_decide'];

function aiHandles(string $action): bool { return in_array($action, AI_ACTIONS, true); }

function aiAction(PDO $pdo, string $action, string $method): never {
    aiSchema($pdo);
    $org = scope();
    $c = ctx();
    $want = fn(string $m) => $method === $m ? null : reply(405, ['error' => 'שיטה לא מורשית']);
    try {
        switch ($action) {
            case 'ai_status':
                $want('GET');
                need('case.view');
                $s = aiSettings($pdo, $org);
                reply(200, ['has_key' => aiKey($org) !== null, 'used' => aiUsed($pdo, $org), 'cap' => $s['monthly_cap'],
                    'model' => $s['model'], 'can_manage' => can('members.manage'), 'tools_ready' => aiToolsReady(),
                    'auto_approve' => $s['auto_approve']]);

            case 'ai_settings':
                $want('GET');
                need('members.manage');
                reply(200, aiPublicSettings($pdo, $org));

            case 'ai_settings_save':
                $want('POST');
                need('members.manage');
                $in = body();
                $s = aiSettings($pdo, $org);
                $was = $s;
                $provider = (string)($in['provider'] ?? $s['provider']);
                if (!isset(AI_PROVIDERS[$provider])) reply(400, ['error' => 'ספק לא מוכר']);
                $model = (string)($in['model'] ?? $s['model']);
                if (!isset(AI_PROVIDERS[$provider]['models'][$model])) reply(400, ['error' => 'מודל לא מוכר. הרשימה: ' . implode(', ', array_keys(AI_PROVIDERS[$provider]['models']))]);
                $cap = (int)($in['monthly_cap'] ?? $s['monthly_cap']);
                if ($cap < 0 || $cap > 100000) reply(400, ['error' => 'תקרה חודשית: 0 עד 100,000']);
                $s['provider'] = $provider; $s['model'] = $model; $s['monthly_cap'] = $cap;
                if (array_key_exists('auto_approve', $in)) $s['auto_approve'] = (bool)$in['auto_approve'];
                $diff = [];
                foreach (['provider', 'model', 'monthly_cap', 'auto_approve'] as $k) if ($was[$k] !== $s[$k]) $diff[$k] = [$was[$k], $s[$k]];
                $key = trim((string)($in['key'] ?? ''));
                if ($key !== '') {
                    if (strlen($key) < 20 || strlen($key) > 300 || preg_match('/\s/', $key)) reply(400, ['error' => 'המפתח לא נראה תקין (בלי רווחים, 20–300 תווים)']);
                    secretPut('ai_key', $org, $key);
                    $s['key_hint'] = aiKeyHint($key); $s['key_set_at'] = gmdate('c'); $s['key_check'] = ''; $s['key_check_at'] = null;
                    $diff['key'] = ['—', 'הוחלף'];   // לא המפתח ולא סופו — רק שהוחלף
                }
                aiSettingsSave($pdo, $org, $s);
                if ($diff) recordEvent($org, null, $c['actor'], 'ui', 'ai_settings', $diff);
                reply(200, aiPublicSettings($pdo, $org));

            case 'ai_key_test':
                $want('POST');
                need('members.manage');
                $key = aiKey($org) ?? reply(400, ['error' => 'לא הוגדר מפתח']);
                $s = aiSettings($pdo, $org);
                $r = aiKeyTest($s['provider'], $s['model'], $key);
                $s['key_check'] = $r['result']; $s['key_check_at'] = gmdate('c');
                aiSettingsSave($pdo, $org, $s);
                reply(200, $r + ['settings' => aiPublicSettings($pdo, $org)]);

            case 'ai_chat':
                $want('POST');
                need('case.view');
                $in = body();
                $text = trim(cleanText($in['text'] ?? '', AI_MAX_MESSAGE));
                if ($text === '') reply(400, ['error' => 'נדרש טקסט']);
                aiPurge($pdo);
                $tid = (string)($in['thread'] ?? '');
                if ($tid !== '') {
                    $thread = aiThreadGet($pdo, $tid, $org, (int)$c['user']['id']) ?? reply(404, ['error' => 'השיחה לא נמצאה (אולי עברו 30 יום)']);
                } else {
                    $caseId = (string)($in['case_id'] ?? '');
                    if ($caseId !== '') {
                        if (!preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $caseId) || existing($pdo, $caseId, $org) === null) reply(404, ['error' => 'התיק לא נמצא']);
                    }
                    $thread = aiThreadNew($pdo, $org, (int)$c['user']['id'], $caseId !== '' ? $caseId : null);
                }
                $r = aiChat($pdo, $thread, $text);
                reply(200, $r + ['thread' => $thread['id']]);

            case 'ai_thread':
                $want('GET');
                need('case.view');
                aiPurge($pdo);
                $tid = (string)($_GET['id'] ?? '');
                if ($tid === '') {
                    // השיחה האחרונה של המשתמש בהקשר הזה (תיק, או המסך הראשי)
                    $caseId = (string)($_GET['case'] ?? '');
                    $st = $pdo->prepare('SELECT id FROM ai_threads WHERE org_id = ? AND user_id = ? AND ' . ($caseId === '' ? 'case_id IS NULL' : 'case_id = ?')
                        . ' ORDER BY updated_at DESC LIMIT 1');
                    $st->execute($caseId === '' ? [$org, $c['user']['id']] : [$org, $c['user']['id'], $caseId]);
                    $tid = (string)$st->fetchColumn();
                    if ($tid === '') reply(200, ['thread' => null]);
                }
                $t = aiThreadGet($pdo, $tid, $org, (int)$c['user']['id']) ?? reply(404, ['error' => 'השיחה לא נמצאה']);
                reply(200, ['thread' => aiThreadView($pdo, $t)]);

            case 'ai_decide':
                $want('POST');
                need('case.view');
                $in = body();
                reply(200, aiDecide($pdo, (int)($in['id'] ?? 0), !empty($in['approve'])));
        }
    } catch (AiError $e) {
        $code = $e->getCode();
        reply($code >= 400 && $code < 600 ? $code : 502, ['error' => $e->getMessage()]);
    }
    reply(404, ['error' => 'פעולה לא מוכרת']);
}
