<?php
/**
 * קונסולת המפתח — JSON בלבד. ‏?action=…
 *
 * נקרא רק מ-dev/index.html. מאחורי שער הפלטפורמה (לא ב-"public" של app.json),
 * ובנוסף כניסה משלו בעוגייה mg_dev — הגנה כפולה, כי מכאן נפתחים ונסגרים חשבונות.
 *
 * כל בקשה משנה: POST עם JSON, הכותרת X-Migdalor (אתר זר לא יכול לשלוח כותרת
 * כזו בלי CORS), ולמחובר — גם X-CSRF שצמוד ל-session.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/accounts.php';
require_once __DIR__ . '/../lib/json.php';
require_once __DIR__ . '/../lib/search.php';
require_once __DIR__ . '/../lib/appversion.php';
require_once __DIR__ . '/../lib/signing.php';

jsonHeaders();

const INVITE_MESSAGES = [
    'invited' => 'נשלחה הזמנה במייל — ההורה בוחר סיסמה ונכנס',
    'granted' => 'יש לו כבר חשבון בפלטפורמה — נוספה הרשאה למגדלור',
    'blocked' => 'החשבון שלו חסום בפלטפורמה — לא נשלחה הזמנה',
];

/** הזמנה אחרי פתיחת הורה: הודעה לתצוגה. כישלון לא מבטל את פתיחת ההורה — אפשר לשלוח שוב מהגיליון. */
function inviteResult(int $parentId): string {
    [$status, $err] = parentInvite($parentId);
    return $err ?: (INVITE_MESSAGES[$status] ?? $status);
}

/**
 * מה הדף צריך כדי להחליט מה להציג: כניסה, התקנה, המסך החד-פעמי, או הקונסולה.
 * ‏platform — מחוברת לפלטפורמה: אין כניסה, התקנה ויציאה משלנו. ‏link — הזהות עוד לא קושרה לחשבון.
 */
function meState(): array {
    $d = currentDeveloper();
    $platform = platformIdentity() !== null;
    return [
        'ok'    => true,
        'platform' => $platform,
        'link'  => $platform && !$d,
        'setup' => !$platform && developerCount() === 0,
        'me'    => $d ? ['id' => (int) $d['id'], 'username' => $d['username'], 'name' => $d['name']] : null,
        'csrf'  => $d ? (string) (sessionRow('dev')['csrf'] ?? '') : '',
        'meta'  => ['levels' => ACCOUNT_LEVELS, 'modules' => ACCOUNT_MODULES],
    ];
}

try {
    $action = (string) ($_GET['action'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'me') out(meState());
    requirePostFrom();

    /* ── לפני כניסה ── */
    if ($action === 'login') {
        [$d, $err] = developerLogin(str('username'), (string) (body()['password'] ?? ''));
        if ($err) fail($err, 401);
        out(['ok' => true]);
    }
    if ($action === 'setup') {
        [, $err] = developerSetup(str('username'), str('name'), (string) (body()['password'] ?? ''));
        if ($err) fail($err);
        out(['ok' => true]);
    }

    /* ── המסך החד-פעמי (מחוברת לפלטפורמה): "יש לי כבר חשבון" — גם מהתפריט, למי שנזכר אחר כך ── */
    if ($action === 'link' || $action === 'link_new') {
        $id = platformIdentity();
        if (!$id) fail('האפליקציה לא מחוברת לפלטפורמה');
        if ($action === 'link') {
            if ($err = platformLinkOld('dev', $id, str('username'), (string) (body()['password'] ?? ''))) fail($err);
        } elseif (!platformLinked('dev', $id)) {
            platformCreate('dev', $id);
        }
        forgetSessions();
        out(meState());
    }

    /* ── מכאן — רק מפתח מחובר ── */
    $dev = currentDeveloper();
    if (!$dev) fail('נדרשת כניסה', 401, 'unauthorized');
    requireCsrf('dev');

    switch ($action) {
        case 'logout':
            // מחוברת: היציאה היא מהפלטפורמה — אחרת הבקשה הבאה הייתה מכניסה שוב.
            if (platformIdentity()) fail('היציאה היא מהכלים הדיגיטליים');
            endSession('dev');
            out(['ok' => true]);

        case 'password':
            $err = developerChangePassword((int) $dev['id'], (string) (body()['current'] ?? ''),
                                           (string) (body()['next'] ?? ''));
            if ($err) fail($err);
            out(['ok' => true]);

        case 'accounts':
            out(['ok' => true, 'accounts' => accountsList()]);

        case 'account':
            $a = accountDetail(num('id'));
            if (!$a) fail('החשבון לא נמצא', 404);
            out(['ok' => true, 'account' => $a]);

        case 'account_create':
            [$id, $pw, $err] = accountCreate(body()['account'] ?? [], str('parent_login'), str('parent_name'));
            if ($err) fail($err);
            $p = one('SELECT id FROM parents WHERE account_id = ? ORDER BY id LIMIT 1', [$id]);
            out(['ok' => true, 'id' => $id, 'temp_password' => $pw, 'invite' => inviteResult((int) $p['id']),
                 'account' => accountDetail($id)]);

        case 'account_update':
            if ($err = accountUpdate(num('id'), body()['account'] ?? [])) fail($err);
            out(['ok' => true, 'account' => accountDetail(num('id'))]);

        case 'account_status':
            if ($err = accountSetStatus(num('id'), str('status'))) fail($err);
            out(['ok' => true, 'account' => accountDetail(num('id'))]);

        case 'account_extend':
            if ($err = accountExtend(num('id'), num('months'))) fail($err);
            out(['ok' => true, 'account' => accountDetail(num('id'))]);

        case 'parent_add':
            [$pw, $err] = parentAdd(num('account_id'), str('login'), str('name'));
            if ($err) fail($err);
            $p = one('SELECT id FROM parents WHERE login = ?', [cleanLogin(str('login'))]);
            out(['ok' => true, 'temp_password' => $pw, 'invite' => inviteResult((int) $p['id']),
                 'account' => accountDetail(num('account_id'))]);

        /* ── הזמנה לפלטפורמה (platform/docs/sso.md, סעיף 6): כל ההורים נכנסים דרכה ── */
        case 'parent_invite':
            [$status, $err] = parentInvite(num('id'));
            if ($err) fail($err);
            out(['ok' => true, 'status' => $status, 'message' => INVITE_MESSAGES[$status] ?? $status]);

        case 'invite_all':
            @set_time_limit(120);
            $res = ['invited' => 0, 'granted' => 0, 'skipped' => [], 'failed' => []];
            foreach (all("SELECT id, login FROM parents WHERE platform_sub = '' AND status = 'active'") as $row) {
                if (!filter_var($row['login'], FILTER_VALIDATE_EMAIL)) { $res['skipped'][] = $row['login']; continue; }
                [$status, $err] = parentInvite((int) $row['id']);
                if ($err || !isset($res[$status])) $res['failed'][] = $row['login'] . ($err ? ": $err" : ": $status");
                else $res[$status]++;
            }
            out(['ok' => true] + $res);

        case 'parent_reset':
            [$pw, $err] = parentReset(num('id'));
            if ($err) fail($err);
            out(['ok' => true, 'temp_password' => $pw]);

        case 'parent_remove':
            $p = one('SELECT account_id, login FROM parents WHERE id = ?', [num('id')]);
            if ($err = parentRemove(num('id'))) fail($err);
            // ההרשאה למגדלור בפלטפורמה מוסרת (החשבון שם נשאר — אולי יש לו אפליקציות אחרות).
            if ($p && filter_var($p['login'], FILTER_VALIDATE_EMAIL)) platformProvision($p['login'], '', false);
            out(['ok' => true, 'account' => accountDetail((int) ($p['account_id'] ?? 0))]);

        case 'system':
            out(['ok' => true, 'system' => systemOverview()
                + ['search' => searchStatus(), 'provision' => provisionConfig(), 'sign_pubkey' => signingPublicKey()]]);

        /* ── חיפוש (שלב 8): מפתח Brave, וסריקה עכשיו ── */
        case 'brave_key':
            if ($err = braveKeySave(str('key'))) fail($err);
            out(['ok' => true, 'search' => searchStatus()]);

        case 'search_tick':
            @set_time_limit(60);
            $n = searchTick(20.0);
            out(['ok' => true, 'scanned' => $n, 'search' => searchStatus()]);
    }
    fail('פעולה לא מוכרת', 404, 'unknown_action');

} catch (Throwable $e) {
    logProblem('dev api: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    fail('שגיאת שרת', 500, 'server');
}
