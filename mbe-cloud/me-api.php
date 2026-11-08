<?php
/**
 * השרת של "החשבון שלי בענן" (me.php), ורק שלו (docs/spec-5-account-tiers.md 4.1א).
 *
 * ב-"signed_in" של app.json, כמו me.php: כל משתמש פלטפורמה פעיל, גם בלי כונן. api.php לא נפתח כאן — הוא היה חושף
 * למשתמש בלי הרשאה את כל פעולות הכונן. לכן רשימה סגורה; כל action אחר — 404. אותן פונקציות של lib/, בלי הניתוב של api.php.
 *
 *   GET  me                → {account, granted, appdata: {used, quota}}      — בלי מכסת כונן כשאין כונן
 *   GET  connections       → {items} — לקריאה בלבד (spec-6 4.1)
 *   GET  appdata_apps      → {items} — נפח לכל אפליקציה
 *   POST appdata_wipe      {client}              — 🗑️ הנתונים של אפליקציה אחת (spec-4)
 *   POST account_prefs / account_prefs_set       — ההגדרות האישיות של ui-core
 *   POST account_export    → הכול כ-JSON (בלי כונן — רק מה שיש)
 *   POST account_delete    {confirm: "DELETE"}   — מחיקת החשבון בענן
 *
 * רק החשבון שנכנס דרך השער (PLATFORM_USER): בלי אסימון, בלי חתימת לקוח ובלי PAT (Authorization → 400).
 * כתיבה — POST עם application/json בלבד (כמו WRITES ב-api.php), נגד CSRF מטופס זר. בלי CORS.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/tier.php';
require_once __DIR__ . '/lib/appdata.php';
require_once __DIR__ . '/lib/me.php';
require_once __DIR__ . '/lib/ui-core/account/account.php';

const ME_ACTIONS = ['me' => 'GET', 'connections' => 'GET', 'appdata_apps' => 'GET', 'appdata_wipe' => 'POST',
                    'account_prefs' => 'POST', 'account_prefs_set' => 'POST', 'account_export' => 'POST', 'account_delete' => 'POST'];

/** פעולה אחת. $method, $contentType, $auth — מהבקשה (בבדיקות — ישירות). */
function meApi(string $action, string $method, string $contentType, bool $auth, array $in): array {
    if (!isset(ME_ACTIONS[$action])) throw new AppError('לא נמצא', 404, 'not_found');
    if ($auth) throw new AppError('בקשה לא תקינה', 400, 'bad_request');   // לא אסימון, לא חתימה ולא PAT — רק השער
    if (ME_ACTIONS[$action] === 'POST' && ($method !== 'POST' || !str_starts_with(strtolower($contentType), 'application/json'))) {
        throw new AppError('בקשה לא תקינה', 400, 'bad_request');
    }
    $a = requireAccount();
    if (str_starts_with($action, 'account_')) {
        try {
            return uic_account_api($action, $in, [
                'app' => 'suite-cloud',   // כמו ב-api.php — מפתח הנתונים של ההגדרות במסד
                'pdo' => fn() => db(),
                'user' => fn() => $a['id'],
                'export' => fn($uid) => accountExport($a),
                'delete' => fn($uid) => accountDelete($a),
            ]);
        } catch (UicAccountError $e) {
            throw new AppError($e->getMessage(), $e->status, $e->status === 401 ? 'unauthorized' : ($e->status === 404 ? 'not_found' : 'bad_request'));
        }
    }
    return match ($action) {
        'me' => ['account' => publicAccount($a), 'granted' => accountGranted($a),
                 'appdata' => ['used' => appdataAccountBytes($a['id']), 'quota' => (int) setting('appdata_account_quota')]],
        'connections' => ['items' => accountConnections($a)],
        'appdata_apps' => appdataApps($a),
        'appdata_wipe' => appdataWipeOwn($a, $in['client'] ?? null),
    };
}

if (PHP_SAPI === 'cli') return;   // בבדיקות: רק הפונקציות

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
try {
    $raw = (string) file_get_contents('php://input');
    $in = json_decode($raw, true);
    $auth = !empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) || !empty($_SERVER['HTTP_X_CLOUD_TOKEN'])
         || !empty($_SERVER['HTTP_X_CLOUD_SIGNATURE']);
    $out = meApi((string) ($_GET['action'] ?? ''), $_SERVER['REQUEST_METHOD'] ?? 'GET', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), $auth,
                 is_array($in) ? $in : []);
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (AppError $e) {
    http_response_code($e->status);
    echo json_encode(['error' => $e->getMessage(), 'code' => $e->codeName] + $e->extra, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('mbe-cloud me-api.php: ' . $e);
    http_response_code(500);
    echo json_encode(['error' => 'תקלה בענן', 'code' => 'server'], JSON_UNESCAPED_UNICODE);
}
