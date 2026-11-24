<?php
declare(strict_types=1);

/**
 * API חיצוני לצ'אט (GPT Actions) — פרוסה 05. Bearer בלבד (אסימון אישי fdx_…), בלי עוגייה ובלי session.
 * רשום ב-"public" של app.json: מכונה ניגשת בלי השער. הפעולות — מטבלה אחת (extActions() ב-ext-lib.php),
 * ממנה נוצר גם openapi.json. הפעולות עצמן — extDo() ב-ext-lib.php (גם ה-AI של 06 קורא לה). הנתיב: ext.php/<action> (או ext.php?action=<action>). אין פעולת מחיקה.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/ext-lib.php';

$t0 = microtime(true);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
if ($action === '') $action = (string)($_GET['action'] ?? '');
$pdo = db();   // מריץ את המיגרציות של 01
// יומן הבקשות של 01 — פעולה, סטטוס, זמן ומשתמש. אף פעם לא האסימון או הכותרת.
// 401 ו-429 של הצפה לא נרשמים (ext_nolog). logRequest מבטל טרנזקציה פתוחה לפני הרישום — כך דחייה באמצע כתיבה נרשמת.
register_shutdown_function(static function () use ($method, &$action, $t0): void {
    if (!empty($GLOBALS['ext_nolog'])) return;
    logRequest($method, 'ext:' . substr((string)$action, 0, 40), (int)(http_response_code() ?: 200),
        (int)round((microtime(true) - $t0) * 1000), $GLOBALS['fd_ctx']['user']['id'] ?? null, (string)($GLOBALS['fd_err'] ?? ''));
});

extEnsureSchema($pdo);
extAuthenticate($pdo);   // בונה את ctx() של 01 מהאסימון

$actions = extActions();
if (!isset($actions[$action])) { $action = 'unknown'; reply(404, ['error' => 'פעולה לא מוכרת. הפעולות: ' . implode(', ', array_keys($actions))]); }
if ($method !== $actions[$action]['method']) reply(405, ['error' => 'שיטה לא מורשית: ' . $action . ' היא ' . $actions[$action]['method']]);

try {
    $in = $method === 'POST' ? body() + $_GET : $_GET;
    reply(200, extDo($pdo, $action, $in, 'api'));
} catch (ExtError $e) {
    reply($e->getCode(), ['error' => $e->getMessage()]);
}
