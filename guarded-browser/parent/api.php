<?php
/**
 * אפליקציית ההורה — JSON בלבד. ‏?action=…  (SPEC.md, סעיף 10)
 *
 * ציבורי מבחינת שער הפלטפורמה (הורים אינם משתמשי mini_projects), ולכן כל
 * ההגנה כאן: עוגייה mg_parent, X-Migdalor ו-X-CSRF בכל שינוי, והגבלת ניסיונות
 * בכניסה. וכל פעולה על ילד עוברת ב-childOf — הורה רואה ומשנה רק את
 * הילדים שבחשבון שלו. מזהה של ילד מחשבון אחר נראה בדיוק כמו מזהה שלא קיים.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/children.php';
require_once __DIR__ . '/../lib/json.php';

jsonHeaders();

/** מה הדף צריך: מי מחובר, מה מצב החשבון, ומה החשבון רשאי לתת. */
function meState(): array {
    $p = currentParent();
    if (!$p) return ['ok' => true, 'me' => null];
    $a = accountRow((int) $p['account_id']);
    return [
        'ok'   => true,
        'me'   => ['id' => (int) $p['id'], 'login' => $p['login'], 'name' => $p['name'],
                   'must_change' => (bool) $p['must_change_pw']],
        'csrf' => (string) (sessionRow('parent')['csrf'] ?? ''),
        'account' => [
            'name' => $a['name'], 'status' => $a['status'], 'expires_at' => $a['expires_at'],
            'expiry' => expiryState((string) $a['expires_at']), 'level_max' => $a['level_max'],
            'modules' => moduleList((string) $a['modules']), 'max_children' => (int) $a['max_children'],
            'max_devices' => (int) $a['max_devices'],
        ],
        'catalogs' => childCatalogs(),
    ];
}

/**
 * כתובת השרת כפי שהמכשיר צריך אותה — מההגדרות (server_url), ואם אין,
 * מהבקשה הזו עצמה: התיקייה שמעל parent/.
 */
function serverBase(): string {
    if ($u = serverUrl()) return $u;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/x/parent/api.php'))), '/');
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

/** הילד מהבקשה — רק אם הוא בחשבון של ההורה המחובר. */
function myChild(array $parent): array {
    $c = childOf((int) $parent['account_id'], num('id'));
    if (!$c) fail('הילד לא נמצא', 404, 'not_found');
    return $c;
}

function detail(array $parent, int $childId): array {
    $c = childOf((int) $parent['account_id'], $childId);
    return childDetail($c, youTubeModesFor((int) $parent['account_id']));
}

try {
    $action = (string) ($_GET['action'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'me') out(meState());
    requirePostFrom();

    if ($action === 'login') {
        [, $err] = parentLogin(str('login'), (string) (body()['password'] ?? ''));
        if ($err) fail($err, 401);
        out(['ok' => true]);
    }

    $parent = currentParent();
    if (!$parent) fail('נדרשת כניסה', 401, 'unauthorized');
    requireCsrf('parent');
    $pid = (int) $parent['id'];
    $aid = (int) $parent['account_id'];

    if ($action === 'logout') { endSession('parent'); out(['ok' => true]); }
    if ($action === 'password') {
        $err = parentChangePassword($pid, (string) (body()['current'] ?? ''), (string) (body()['next'] ?? ''));
        if ($err) fail($err);
        out(['ok' => true]);
    }
    // סיסמה זמנית — שום דבר אחר עד שהוחלפה.
    if ($parent['must_change_pw']) fail('יש לבחור סיסמה חדשה לפני שממשיכים', 403, 'must_change');

    switch ($action) {
        case 'children':
            $rows = all('SELECT * FROM users WHERE account_id = ? AND is_admin = 0
                         ORDER BY CASE status WHEN \'pending\' THEN 0 ELSE 1 END, id', [$aid]);
            out(['ok' => true, 'children' => array_map('childCard', $rows)]);

        case 'child':
            out(['ok' => true, 'child' => detail($parent, (int) myChild($parent)['id'])]);

        case 'child_add':
            [$id, $username, $pw, $err] = childCreate($aid, str('name'));
            if ($err) fail($err);
            out(['ok' => true, 'id' => $id, 'username' => $username, 'password' => $pw]);

        case 'child_password':
            $c = myChild($parent);
            out(['ok' => true, 'username' => $c['username'], 'password' => childNewPassword((int) $c['id'])]);

        case 'child_delete':
            childDelete((int) myChild($parent)['id']);
            out(['ok' => true]);

        case 'child_status':
            $c = myChild($parent);
            $st = str('status');
            if (!in_array($st, ['active', 'suspended'], true)) fail('מצב לא מוכר');
            q('UPDATE users SET status = ? WHERE id = ?', [$st, $c['id']]);
            if ($st === 'suspended') q('DELETE FROM devices WHERE user_id = ?', [$c['id']]);
            out(['ok' => true, 'child' => detail($parent, (int) $c['id'])]);

        case 'child_save':
            $c = myChild($parent);
            $in = body()['policy'] ?? [];
            if (!is_array($in)) fail('בקשה לא תקינה');
            if ($err = childPolicySave((int) $c['id'], $in)) fail($err);
            out(['ok' => true, 'child' => detail($parent, (int) $c['id'])]);

        case 'youtube':
            $c = myChild($parent);
            $err = childYouTubeSave((int) $c['id'], str('mode'), !empty(body()['allow_search']),
                                    !empty(body()['allow_shorts']), youTubeModesFor($aid));
            if ($err) fail($err);
            out(['ok' => true, 'child' => detail($parent, (int) $c['id'])]);

        case 'yt_add':
            $c = myChild($parent);
            [$msg, $err] = childYouTubeAdd((int) $c['id'], str('input'), str('label'), str('item_action'));
            if ($err) fail($err);
            out(['ok' => true, 'message' => $msg, 'child' => detail($parent, (int) $c['id'])]);

        case 'yt_del':
            $c = myChild($parent);
            childYouTubeDelete((int) $c['id'], num('item_id'));
            out(['ok' => true, 'child' => detail($parent, (int) $c['id'])]);

        case 'rule_add':
            $c = myChild($parent);
            [$msg, $err] = childRuleAdd((int) $c['id'], str('pattern'), str('scope') ?: 'domain', str('label'),
                                        str('rule_action') ?: 'allow', (body()['show_tile'] ?? true) !== false);
            if ($err) fail($err);
            out(['ok' => true, 'message' => $msg, 'child' => detail($parent, (int) $c['id'])]);

        case 'rule_del':
            $c = myChild($parent);
            childRuleDelete((int) $c['id'], num('rule_id'));
            out(['ok' => true, 'child' => detail($parent, (int) $c['id'])]);

        case 'rule_toggle':
            $c = myChild($parent);
            childRuleToggle((int) $c['id'], num('rule_id'));
            out(['ok' => true, 'child' => detail($parent, (int) $c['id'])]);

        case 'device_del':
            $c = myChild($parent);
            childDeviceDelete((int) $c['id'], num('device_id'));
            out(['ok' => true, 'child' => detail($parent, (int) $c['id'])]);

        case 'pair_create':
            $c = myChild($parent);
            if ($c['status'] !== 'active') fail('הילד מושהה — החזירו אותו קודם');
            [$code, $exp] = pairCreate((int) $c['id']);
            out(['ok' => true, 'code' => $code, 'expires_at' => $exp, 'server' => serverBase(),
                 'qr' => 'migdalor:pair?c=' . $code . '&s=' . rawurlencode(serverBase())]);

        case 'usage_reset':
            $c = myChild($parent);
            childUsageReset((int) $c['id']);
            out(['ok' => true, 'child' => detail($parent, (int) $c['id'])]);
    }
    fail('פעולה לא מוכרת', 404, 'unknown_action');

} catch (Throwable $e) {
    logProblem('parent api: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    fail('שגיאת שרת', 500, 'server');
}
