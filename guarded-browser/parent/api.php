<?php
/**
 * אפליקציית ההורה — JSON בלבד. ‏?action=…  (SPEC.md, סעיף 10)
 *
 * מאחורי שער הפלטפורמה: כל ההורים נכנסים בחשבון הפלטפורמה שלהם (בעל המערכת, 2026-10-20), והזהות
 * מגיעה מ-PLATFORM_USER (lib/platform.php). הכניסה בסיסמה של מגדלור — רק כשהאפליקציה מנותקת מהשער.
 * בנוסף: עוגייה mg_parent, X-Migdalor ו-X-CSRF בכל שינוי. וכל פעולה על ילד עוברת ב-childOf — הורה
 * רואה ומשנה רק את הילדים שבחשבון שלו. מזהה של ילד מחשבון אחר נראה בדיוק כמו מזהה שלא קיים.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/children.php';
require_once __DIR__ . '/../lib/json.php';
require_once __DIR__ . '/../lib/push.php';
require_once __DIR__ . '/../lib/activity.php';
require_once __DIR__ . '/../lib/appversion.php';
require_once __DIR__ . '/../lib/search.php';
require_once __DIR__ . '/../lib/appcontrol.php';
require_once __DIR__ . '/../lib/enforce.php';
require_once __DIR__ . '/../lib/devicesettings.php';
require_once __DIR__ . '/../lib/signing.php';

jsonHeaders();

/**
 * מה הדף צריך: מי מחובר, מה מצב החשבון, ומה החשבון רשאי לתת. ‏platform — מחוברת לפלטפורמה: אין כניסה,
 * סיסמה ויציאה משלנו. ‏link — הזהות עוד לא מקושרת לאף הורה (המסך החד-פעמי, או "אין לך חשבון משפחה").
 */
function meState(): array {
    $p = currentParent();
    $platform = platformIdentity() !== null;
    if (!$p) return ['ok' => true, 'me' => null, 'platform' => $platform, 'link' => $platform];
    $a = accountRow((int) $p['account_id']);
    return [
        'ok'   => true,
        'platform' => $platform,
        'me'   => ['id' => (int) $p['id'], 'login' => $p['login'], 'name' => $p['name'],
                   'must_change' => !$platform && (bool) $p['must_change_pw']],
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
    $out = childDetail($c, youTubeModesFor((int) $parent['account_id']));
    // גרסת האפליקציה בכל מכשיר, מול האחרונה שפורסמה.
    $latest = latestApp();
    $out['latest_app'] = $latest;
    foreach ($out['device_list'] as &$d) { $d['app'] = deviceAppState($d, $latest); $d['perms'] = devicePermsState((int) $d['perms']); }
    unset($d);
    $out['enforcement'] = enforcementState((int) $parent['account_id'], $childId);
    $out['device_settings'] = deviceSettingsView($childId);
    // מצב הביצוע בפועל לכל הגדרה — לא רק מה שנשמר (lib/enforce.php, deviceSettingsStatus).
    $status = deviceSettingsStatus($childId);
    foreach ($out['device_settings'] as &$s) $s['status'] = $status[$s['key']] ?? null;
    unset($s);
    return $out;
}

try {
    $action = (string) ($_GET['action'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'me') out(meState());

    /*
     * ה-service worker התעורר מצלצול ושואל מה חדש. ‏GET בלי CSRF: אין לו את
     * האסימון, והפעולה רק קוראת (ומסמנת כנמסר). העוגייה עדיין נדרשת.
     */
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'push_pending') {
        $p = currentParent();
        $ep = (string) ($_GET['ep'] ?? '');
        out(['ok' => true, 'items' => $p && (platformIdentity() || !$p['must_change_pw']) ? pushPending($p, $ep) : []]);
    }
    requirePostFrom();

    if ($action === 'login') {
        [, $err] = parentLogin(str('login'), (string) (body()['password'] ?? ''));
        if ($err) fail($err, 401);
        out(['ok' => true]);
    }

    /* המסך החד-פעמי: "יש לי כבר חשבון" — שם הכניסה והסיסמה הישנים של מגדלור. גם מהתפריט. */
    if ($action === 'link') {
        $id = platformIdentity();
        if (!$id) fail('האפליקציה לא מחוברת לפלטפורמה');
        if ($err = platformLinkOld('parent', $id, str('login'), (string) (body()['password'] ?? ''))) fail($err);
        forgetSessions();
        out(meState());
    }

    $parent = currentParent();
    if (!$parent) fail('נדרשת כניסה', 401, 'unauthorized');
    requireCsrf('parent');
    $pid = (int) $parent['id'];
    $aid = (int) $parent['account_id'];
    $viaPlatform = platformIdentity() !== null;

    // מחוברת: היציאה והסיסמה הן של הכלים הדיגיטליים, לא שלנו.
    if ($viaPlatform && in_array($action, ['logout', 'password'], true)) fail('זה נעשה בחשבון של הכלים הדיגיטליים');
    if ($action === 'logout') { endSession('parent'); out(['ok' => true]); }
    if ($action === 'password') {
        $err = parentChangePassword($pid, (string) (body()['current'] ?? ''), (string) (body()['next'] ?? ''));
        if ($err) fail($err);
        out(['ok' => true]);
    }
    // משוחרר (SPEC סעיף 5): מחיקת כל חשבונות הילדים. מגדלור שבמכשירים יסיר את עצמו בפעימה הבאה.
    // מותר רק כשהמנוי באמת משוחרר — לא דרך לנקות ילדים בזמן חשבון פעיל.
    if ($action === 'release_cleanup') {
        $a = accountRow($aid);
        if (!$a || expiryState((string) $a['expires_at']) !== 'released') fail('החשבון אינו משוחרר');
        $ids = all('SELECT id FROM users WHERE account_id = ? AND is_admin = 0', [$aid]);
        foreach ($ids as $r) childDelete((int) $r['id']);
        out(['ok' => true, 'deleted' => count($ids)]);
    }
    // אתר או ערוץ שנוסף עכשיו — נכנס לאינדקס החיפוש כבר אחרי התשובה, לא מחר.
    if (in_array($action, ['rule_add', 'yt_add', 'request_decide', 'children'], true)) searchTickAfterResponse(5);
    // סיסמה זמנית — שום דבר אחר עד שהוחלפה.
    if (!$viaPlatform && $parent['must_change_pw']) fail('יש לבחור סיסמה חדשה לפני שממשיכים', 403, 'must_change');

    switch ($action) {
        case 'children':
            $rows = all('SELECT * FROM users WHERE account_id = ? AND is_admin = 0
                         ORDER BY CASE status WHEN \'pending\' THEN 0 ELSE 1 END, id', [$aid]);
            $latest = latestApp();
            out(['ok' => true, 'children' => array_map(function ($u) use ($latest) {
                $card = childCard($u);
                // מכשירים שצריכים עדכון — לסימן ⬆️ בכרטיס.
                $card['outdated'] = count(array_filter(all('SELECT app_version FROM devices WHERE user_id = ?', [$u['id']]),
                    fn($d) => deviceAppState($d, $latest)['status'] !== 'current'));
                // מכשירים שחסרה בהם הרשאה, או שאינם "בעל מכשיר" — לסימן 🛡️ בכרטיס.
                $card['perms_bad'] = count(array_filter(all('SELECT perms FROM devices WHERE user_id = ?', [$u['id']]),
                    fn($d) => !devicePermsState((int) $d['perms'])['complete']));
                return $card;
            }, $rows)]);

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

        case 'device_update':
            $c = myChild($parent);
            if ($err = requestDeviceUpdate((int) $c['id'], num('device_id'))) fail($err);
            out(['ok' => true, 'child' => detail($parent, (int) $c['id'])]);

        case 'device_update_cancel':
            $c = myChild($parent);
            cancelDeviceUpdate((int) $c['id'], num('device_id'));
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
                 'qr' => 'migdalor:pair?c=' . $code . '&s=' . rawurlencode(serverBase()),
                 // הקמה כ"בעל מכשיר" (מומלץ): QR שסורקים במסך הפתיחה של מכשיר מאופס. null — עוד אין בנייה חתומה.
                 'provision' => provisionConfig()]);

        /* ── ניהול אפליקציות (שלב 11) ── */
        case 'apps':
            out(['ok' => true, 'apps' => appsList($aid, (int) myChild($parent)['id'])]);

        /* ── הגדרות המכשיר (lib/devicesettings.php) — נאכף ב"בעל מכשיר" ── */
        case 'device_setting':
            $c = myChild($parent);
            if ($err = deviceSettingSave((int) $c['id'], str('key'), (bool) (body()['blocked'] ?? false))) fail($err);
            out(['ok' => true, 'child' => detail($parent, (int) $c['id'])]);

        /* ── העברה בלי אינטרנט: QR חתום שמכשיר הילד סורק (lib/signing.php, offlineToken) ── */
        case 'offline_code':
            $c = myChild($parent);
            $cid = (int) $c['id'];
            $apps = appsPayload(['id' => $cid, 'account_id' => $aid]);
            $tok = offlineToken($aid, $cid, deviceSettings($cid), $apps);
            $partial = false;
            // QR גדול מדי לא נסרק במצלמה של טלפון זול: בלי רשימות האפליקציות — רק ברירת המחדל והגדרות המכשיר.
            if (strlen($tok) > 1600 && !empty($apps['manage'])) {
                $tok = offlineToken($aid, $cid, deviceSettings($cid),
                                    ['manage' => true, 'posture' => $apps['posture'], 'partial' => true]);
                $partial = true;
            }
            out(['ok' => true, 'qr' => 'migdalor:policy?t=' . $tok, 'expires_in' => OFFLINE_TTL, 'partial' => $partial]);

        case 'apps_posture':
            $c = myChild($parent);
            if ($err = appsSavePosture((int) $c['id'], str('posture'))) fail($err);
            out(['ok' => true, 'apps' => appsList($aid, (int) $c['id'])]);

        case 'app_rule':
            $c = myChild($parent);
            if ($err = appsSaveRule((int) $c['id'], str('package'), str('app_action'))) fail($err);
            out(['ok' => true, 'apps' => appsList($aid, (int) $c['id'])]);

        /* ── פעילות (שלב 7) ── */
        case 'activity':
            $c = myChild($parent);
            if (mt_rand(1, 20) === 1) activityCleanup();
            out(['ok' => true, 'activity' => activityReport($aid, (int) $c['id'], num('days'))]);

        case 'report_level':
            $c = myChild($parent);
            if ($err = reportLevelSave($aid, (int) $c['id'], str('level'))) fail($err);
            out(['ok' => true, 'activity' => activityReport($aid, (int) $c['id'], num('days'))]);

        /* ── בקשות גישה (שלב 6) ── */
        case 'requests':
            requestsCleanup();
            out(['ok' => true, 'requests' => requestList($aid)]);

        case 'request_decide':
            [$msg, $err] = requestDecide($aid, $pid, num('req'), str('decision'), str('scope') ?: 'page');
            if ($err) fail($err);
            out(['ok' => true, 'message' => $msg, 'requests' => requestList($aid)]);

        /* ── התראות לטלפון ── */
        case 'push_key':
            $k = pushKeys();
            if (!$k) fail('השרת אינו תומך בהתראות');
            out(['ok' => true, 'key' => $k['public']]);

        case 'push_sub':
            if ($err = pushSubscribe($pid, str('endpoint'))) fail($err);
            out(['ok' => true]);

        case 'push_unsub':
            pushUnsubscribe($pid, str('endpoint'));
            out(['ok' => true]);

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
