<?php
/**
 * API של הסטודיו — צ'אט פיתוח מול Claude Platform (docs/studio.md).
 *
 * נקודת קצה נפרדת מ-api.php בכוונה: בלי אסימוני עובדים, בלי בלמי הגשר, ובלי
 * שום תלות במנגנונים הישנים. רק אדם מחובר, ורק בעל הלוח.
 *
 * כל בקשה היא POST עם JSON. הזדהות ובדיקת מקור — אותם כללים של api.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/platform.php';
require_once __DIR__ . '/lib/studio/studio.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function sFail(string $message, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
function sOk(array $payload = []): never {
    echo json_encode(['success' => true] + $payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$in = json_decode($raw === false ? '' : $raw, true);
if (!is_array($in)) $in = [];
$action = (string) ($_GET['action'] ?? '');

try {
    $user = currentUser();
    $identity = platformIdentity();
    if ($identity) {
        $user = platformResolve($identity)['user'];
    } elseif ($user && platformMode()['state'] === 'connected') {
        logout();
        $user = null;
    }
    if (($user || $identity) && !sameOriginRequest($_SERVER)) sFail('הבקשה נחסמה: היא לא נשלחה מהלוח עצמו', 403);
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') sFail('POST בלבד', 405);

    $user = studioRequireOwner($user);
    $uid = (int) $user['id'];
    studioMigrate();

    $conv = fn () => studioConv($uid, (int) ($in['id'] ?? 0));
    $webhookUrl = (isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : '')
                . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/studio-webhook.php';

    switch ($action) {

    /** מצב הסטודיו: חיבורים, הגדרות, רשימת השיחות. פנייה אחת בכניסה לדף. */
    case 'state':
        $s = studioSettings($uid);
        $gh = studioConnection($uid, 'github');
        sOk([
            'user' => ['name' => (string) $user['display_name']],
            'connections' => [
                'anthropic' => studioKey($uid) !== '',
                'github'    => $gh !== null,
                'github_login' => (string) ($gh['login'] ?? ''),
            ],
            'settings' => [
                'model' => studioDefaultModel($uid),
                'repo' => (string) ($s['repo'] ?? ''),
                'budget_cents' => (int) ($s['budget_cents'] ?? 2000),
                'allow_merge' => (bool) ($s['allow_merge'] ?? true),
                'webhook_secret_set' => ($s['webhook_secret'] ?? '') !== '',
                'webhook_url' => $webhookUrl,
                'webhook_last_at' => (int) ($s['webhook_last_at'] ?? 0),
                'provisioned' => ($s['agent_id'] ?? '') !== '' && ($s['env_id'] ?? '') !== '',
            ],
            // מסלולי ההרצה. Claude Code נעול בכוונה — אופציה עתידית (docs/studio.md §6).
            'engines' => [
                ['id' => 'platform', 'label' => 'Claude Platform', 'enabled' => true],
                ['id' => 'code', 'label' => 'Claude Code', 'enabled' => false, 'note' => 'בקרוב'],
            ],
            'convs' => array_map('studioPublicConv', studioConvs($uid, !empty($in['archived']))),
        ]);

    case 'settings-save':
        $patch = [];
        if (isset($in['model'])) {
            $m = trim((string) $in['model']);
            if ($m !== '' && !studioValidModel($m)) sFail('מזהה מודל לא תקין');
            $patch['model'] = $m;
        }
        if (isset($in['repo'])) {
            $r = trim((string) $in['repo']);
            if ($r !== '' && !studioValidRepo($r)) sFail('ריפו לא תקין. הצורה: owner/name');
            $patch['repo'] = $r;
        }
        if (isset($in['budget_cents'])) $patch['budget_cents'] = max(0, min(100000, (int) $in['budget_cents']));
        if (isset($in['allow_merge'])) $patch['allow_merge'] = (bool) $in['allow_merge'];
        if (isset($in['webhook_secret'])) {
            $w = trim((string) $in['webhook_secret']);
            if ($w !== '' && !str_starts_with($w, 'whsec_')) sFail('סוד ה-webhook מתחיל ב-whsec_');
            $patch['webhook_secret'] = $w === '' ? '' : encryptSecret($w);
        }
        studioSaveSettings($uid, $patch);
        logEvent($user, 'studio.settings', implode(',', array_keys($patch)));
        sOk();

    case 'conv-create':
        if (($in['engine'] ?? 'platform') !== 'platform') sFail('המסלול הזה עוד לא זמין. כרגע הסטודיו עובד רק דרך Claude Platform', 409);
        $c = studioCreateConv($user, $in);
        logEvent($user, 'studio.conv.create', (string) $c['id'], true, (string) $c['repo']);
        sOk(['conv' => studioPublicConv($c)]);

    /** אירועים מאז seq. בזמן ריצה — גם משיכה מהפלטפורמה, לכל היותר פעם ב-STUDIO_SYNC_GAP שניות לשיחה. */
    case 'conv-events':
        $c = $conv();
        $syncError = '';
        if ((string) $c['session_id'] !== '' && ($c['status'] === 'running' || (int) $c['more'] === 1 || !empty($in['force']))) {
            try { $c = studioSyncIfDue($c, studioKey($uid), !empty($in['force']) ? 1 : STUDIO_SYNC_GAP); }
            catch (AppError $e) { $syncError = $e->getMessage(); }
        }
        sOk(['conv' => studioPublicConv($c), 'sync_error' => $syncError] + studioEventsSince((int) $c['id'], max(0, (int) ($in['since'] ?? 0))));

    case 'conv-send':
        $c = studioSend($user, $conv(), (string) ($in['text'] ?? ''));
        sOk(['conv' => studioPublicConv($c)]);

    case 'conv-interrupt':
        sOk(['conv' => studioPublicConv(studioInterrupt($user, $conv()))]);

    case 'conv-confirm':
        sOk(['conv' => studioPublicConv(studioConfirm($user, $conv(), (string) ($in['result'] ?? ''), (string) ($in['message'] ?? '')))]);

    case 'conv-budget':
        $c = studioSetBudget($user, $conv(), (int) ($in['budget_cents'] ?? 0));
        logEvent($user, 'studio.conv.budget', (string) $c['id'], true, (string) $c['budget_cents']);
        sOk(['conv' => studioPublicConv($c)]);

    case 'conv-update':
        $c = $conv();
        $patch = [];
        if (isset($in['title'])) {
            $t = mb_substr(trim((string) $in['title']), 0, 120);
            if ($t !== '') $patch['title'] = $t;
        }
        if (isset($in['archived'])) $patch['archived'] = (int) (bool) $in['archived'];
        studioUpdateConv((int) $c['id'], $patch);
        sOk(['conv' => studioPublicConv(studioConv($uid, (int) $c['id']))]);

    default:
        sFail('פעולה לא מוכרת', 404);
    }
} catch (AppError $e) {
    sFail($e->getMessage(), $e->status >= 400 && $e->status < 600 ? $e->status : 400);
} catch (Throwable $e) {
    error_log('[studio] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    sFail('שגיאת שרת בסטודיו: ' . mb_substr($e->getMessage(), 0, 200), 500);
}
