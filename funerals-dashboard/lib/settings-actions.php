<?php
declare(strict_types=1);

/**
 * פרוסה 11 — פעולות ה-API של ההגדרות: בדיקות (check_*), דחוף (urgent_save), תפקידים (role*), משימות (task*, my_tasks),
 * ומצב הבדיקות בתיק (case_checks). נטען מ-api.php בשתי שורות (settingsHandles / settingsAction), אחרי הזהות, ה-CSRF והארגון.
 * הלוגיקה — lib/checks.php ו-lib/roles.php; כאן רק הרשאה, קלט, תשובה ורישום ב-events. לא נקודת קצה: גישה ישירה → 404.
 *
 * הרשאות: הגדרות הארגון — settings.manage; ההגדרה הכללית (scope: global) — מפתח בלבד; תפקידים ושיוך — members.manage;
 * הקצאת משימות — tasks.manage; סימון משימה — tasks.manage או מי שהיא מוקצית לו (או לתפקיד שלו).
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

const SETTINGS_ACTIONS = ['checks', 'check_save', 'check_remove', 'check_reset', 'check_order', 'urgent_save', 'case_checks',
    'case_restore', 'roles', 'role_save', 'role_archive', 'tasks', 'task_save', 'task_status', 'my_tasks'];

function settingsHandles(string $action): bool { return in_array($action, SETTINGS_ACTIONS, true); }

/** הבדיקה (scope) שהבקשה עורכת: org → הארגון הפעיל (settings.manage); global → 0 (מפתח). */
function settingsScope(array $in, int $org): int {
    $scope = (string)($in['scope'] ?? 'org');
    if ($scope === 'global') { need('dev'); return 0; }
    if ($scope !== 'org') reply(400, ['error' => 'scope: org או global']);
    need('settings.manage');
    return $org;
}

/** התיק בארגון, או 404; ורק אם המשתמש רואה אותו (כל התיקים, או שהוקצה לו). */
function settingsCase(PDO $pdo, int $org, string $id): array {
    $case = $id === '' ? null : existing($pdo, $id, $org);
    if ($case === null) reply(404, ['error' => 'התיק לא נמצא']);
    need('case.view', ['org_id' => $org, 'id' => $id]);
    return $case;
}

function settingsChecksBody(PDO $pdo, int $org): array {
    return ['checks' => checkDefsAll($pdo, $org), 'global' => ctx()['dev'] ? checkDefsAll($pdo, 0) : null,
        'types' => CHECK_TYPES, 'value_kinds' => CHECK_VALUE_KINDS, 'states' => CHECK_STATES,
        'urgent_days' => urgentDays($pdo, $org), 'can_edit' => can('settings.manage'), 'can_edit_global' => (bool)ctx()['dev']];
}

function settingsAction(PDO $pdo, string $action, string $method, int $org, array $actor): never {
    $uid = (int)ctx()['user']['id'];
    $post = function () use ($method): array {
        if ($method !== 'POST') reply(405, ['error' => 'שיטה לא מורשית']);
        return body();
    };
    $get = function () use ($method): void { if ($method !== 'GET') reply(405, ['error' => 'שיטה לא מורשית']); };
    try {
        switch ($action) {
            /* ===== בדיקות ===== */
            case 'checks':
                $get();
                if (!canSeeCases() && !can('settings.manage')) need('case.view');
                reply(200, settingsChecksBody($pdo, $org));
            case 'check_save':
                $in = $post();
                $o = settingsScope($in, $org);
                $d = checkSave($pdo, $o, $in, (string)$actor['actor']);
                recordEvent($o ?: null, null, $actor, 'ui', 'check_save', ['check' => [null, $d['key']], 'scope' => [null, $o ? 'org' : 'global']]);
                reply(200, ['saved' => $d] + settingsChecksBody($pdo, $org));
            case 'check_remove':
                $in = $post();
                $o = settingsScope($in, $org);
                $key = (string)($in['key'] ?? '');
                checkRemove($pdo, $o, $key, (string)$actor['actor']);
                recordEvent($o ?: null, null, $actor, 'ui', 'check_remove', ['check' => [$key, null], 'scope' => [null, $o ? 'org' : 'global']]);
                reply(200, settingsChecksBody($pdo, $org));
            case 'check_reset':
                $in = $post();
                need('settings.manage');
                $key = (string)($in['key'] ?? '');
                checkReset($pdo, $org, $key);
                recordEvent($org, null, $actor, 'ui', 'check_reset', ['check' => [$key, $key]]);
                reply(200, settingsChecksBody($pdo, $org));
            case 'check_order':
                $in = $post();
                $o = settingsScope($in, $org);
                if (!is_array($in['keys'] ?? null)) reply(400, ['error' => 'keys: רשימת מפתחות']);
                checkOrder($pdo, $o, $in['keys']);
                recordEvent($o ?: null, null, $actor, 'ui', 'check_order', ['order' => [null, implode(',', $in['keys'])]]);
                reply(200, settingsChecksBody($pdo, $org));
            case 'urgent_save':
                $in = $post();
                need('settings.manage');
                $days = $in['days'] ?? null;
                if (!is_int($days) || $days < 0 || $days > 365) reply(400, ['error' => 'ימים: מספר שלם בין 0 ל-365']);
                $was = urgentDays($pdo, $org);
                orgSettingsPut($pdo, $org, ['urgent_days' => $days]);
                recordEvent($org, null, $actor, 'ui', 'urgent_save', ['urgent_days' => [$was, $days]]);
                reply(200, settingsChecksBody($pdo, $org));
            case 'case_checks':
                $get();
                $id = (string)($_GET['id'] ?? '');
                $case = settingsCase($pdo, $org, $id);
                $state = caseChecks($pdo, $org, $case);
                reply(200, ['id' => $id, 'archived' => caseArchived($pdo, $org, $id), 'urgent' => caseUrgent($pdo, $org, $case, $state)] + $state + ['tasks' => caseTasks($pdo, $org, $id)]);

            case 'case_restore':
                $in = $post();
                $id = (string)($in['id'] ?? '');
                settingsCase($pdo, $org, $id);
                need('case.track', ['org_id' => $org, 'id' => $id]);
                if (!caseRestore($pdo, $org, $id)) reply(409, ['error' => 'התיק אינו בארכיון']);
                recordEvent($org, $id, $actor, 'ui', 'case_restore', ['archived' => [true, false]]);
                reply(200, ['ok' => true]);

            /* ===== תפקידים ===== */
            case 'roles':
                $get();
                if (!can('members.manage') && !can('settings.manage') && !can('tasks.manage')) need('members.manage');
                reply(200, ['roles' => rolesOf($pdo, $org, !empty($_GET['all'])), 'perms' => ROLE_PERMS]);
            case 'role_save':
                $in = $post();
                need('members.manage');
                $was = !empty($in['id']) ? roleGet($pdo, $org, (int)$in['id']) : null;
                $r = roleSave($pdo, $org, $in);
                recordEvent($org, null, $actor, 'ui', $was ? 'role_update' : 'role_create', ['role' => [$was['name'] ?? null, $r['name']],
                    'perms' => [$was ? implode(',', $was['perms']) : null, implode(',', $r['perms'])]]);
                reply(200, ['saved' => $r, 'roles' => rolesOf($pdo, $org), 'perms' => ROLE_PERMS]);
            case 'role_archive':
                $in = $post();
                need('members.manage');
                roleArchive($pdo, $org, (int)($in['id'] ?? 0));
                recordEvent($org, null, $actor, 'ui', 'role_archive', ['role_id' => [(int)$in['id'], null]]);
                reply(200, ['roles' => rolesOf($pdo, $org), 'perms' => ROLE_PERMS]);

            /* ===== משימות ===== */
            case 'tasks':
                $get();
                $id = (string)($_GET['case_id'] ?? '');
                settingsCase($pdo, $org, $id);
                reply(200, ['tasks' => caseTasks($pdo, $org, $id)]);
            case 'task_save':
                $in = $post();
                $caseId = !empty($in['id']) ? (string)(taskGet($pdo, $org, (int)$in['id'])['case_id'] ?? '') : (string)($in['case_id'] ?? '');
                if ($caseId === '' || existing($pdo, $caseId, $org) === null) reply(404, ['error' => !empty($in['id']) ? 'המשימה לא נמצאה' : 'התיק לא נמצא']);
                need('tasks.manage');
                $t = taskSave($pdo, $org, $in, $uid);
                recordEvent($org, $t['case_id'], $actor, 'ui', empty($in['id']) ? 'task_create' : 'task_update',
                    ['task' => [null, $t['title']], 'assignee' => [null, $t['assignee_user_id'] !== null ? 'user:' . $t['assignee_user_id'] : 'role:' . $t['assignee_role_id']]]);
                reply(200, ['task' => $t, 'tasks' => caseTasks($pdo, $org, $t['case_id'])]);
            case 'task_status':
                $in = $post();
                $t = taskGet($pdo, $org, (int)($in['id'] ?? 0)) ?? reply(404, ['error' => 'המשימה לא נמצאה']);
                $mine = $t['assignee_user_id'] === $uid || ($t['assignee_role_id'] !== null && $t['assignee_role_id'] === (ctxRole()['id'] ?? null));
                if (!$mine) need('tasks.manage');
                $t2 = taskStatus($pdo, $org, $t['id'], (string)($in['status'] ?? ''), $uid);
                recordEvent($org, $t['case_id'], $actor, 'ui', 'task_status', ['task' => [$t['title'], $t['title']], 'status' => [$t['status'], $t2['status']]]);
                reply(200, ['task' => $t2]);
            case 'my_tasks':
                $get();
                $all = can('case.view');
                $roleId = ctxRole()['id'] ?? null;
                $assigned = $all ? [] : array_flip(assignedCaseIds($pdo, $org, $uid, $roleId));
                reply(200, ['items' => myTasks($pdo, $org, $uid, $roleId, fn(string $id) => $all || isset($assigned[$id])),
                    'role' => ctxRole()]);
        }
    } catch (CheckError | RoleError $e) {
        $code = $e->getCode();
        reply($code >= 400 && $code < 500 ? $code : 400, ['error' => $e->getMessage()]);
    }
    reply(404, ['error' => 'פעולה לא מוכרת']);
}
