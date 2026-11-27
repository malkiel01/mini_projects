<?php
/**
 * בדיקות אינטגרציות מנהל (lib/integrations.php). נכלל מתוך tests/run.php — משתמש ב-check() שם.
 * בלי רשת. תיקיות זמניות מחוץ לריפו. האסימון בבדיקות הוא ערך מזויף.
 */

require_once __DIR__ . '/../lib/integrations.php';

$intTok = 'int-test-token-0123456789abcdef';
$intDir = sys_get_temp_dir() . '/ca-int-' . getmypid();
@mkdir($intDir, 0700);
chmod($intDir, 0700);
$intPath = "$intDir/ingest-token";
$intCfg = ['progress' => ['managers' => ['mgr-1']], 'ingest' => ['token_file' => $intPath]];
$intSrv = fn(string $sub = 'mgr-1', bool $granted = true, string $origin = 'https://mbe-plus.com'): array =>
    ['HTTP_HOST' => 'mbe-plus.com', 'HTTP_ORIGIN' => $origin, 'PLATFORM_USER' => json_encode(['sub' => $sub, 'granted' => $granted])];
$intSave = fn(array $cfg, mixed $body, array $srv) => integration_save_token($cfg, $body, $srv, 'litespeed');

// ---------- הרשאות ומקור ----------
check('integrations: אנונימי → 403', $intSave($intCfg, ['token' => $intTok], ['HTTP_HOST' => 'mbe-plus.com', 'HTTP_ORIGIN' => 'https://mbe-plus.com'], )[0] === 403);
check('integrations: צופה שאינו מנהל → 403', $intSave($intCfg, ['token' => $intTok], $intSrv('someone-else'))[0] === 403);
check('integrations: granted=false → 403', $intSave($intCfg, ['token' => $intTok], $intSrv('mgr-1', false))[0] === 403);
check('integrations: Origin זר → 403', $intSave($intCfg, ['token' => $intTok], $intSrv('mgr-1', true, 'https://evil.example'))[0] === 403);
check('integrations: סטטוס בלי זהות → 403', integration_token_status($intCfg, [], 'litespeed')[0] === 403);
check('integrations: סנכרון בלי זהות → 403', integration_sync($intCfg, [], 'litespeed')[0] === 403);

// ---------- פורמט האסימון ----------
check('integrations: אסימון קצר → 422', $intSave($intCfg, ['token' => 'short'], $intSrv())[0] === 422);
check('integrations: אסימון עם רווח → 422', $intSave($intCfg, ['token' => 'int test token 0123456789abcdef'], $intSrv())[0] === 422);
check('integrations: בלי שדה token → 422', $intSave($intCfg, ['x' => 1], $intSrv())[0] === 422);
check('integrations: token לא מחרוזת → 422', $intSave($intCfg, ['token' => 12345678901234567890123], $intSrv())[0] === 422);
check('integrations: אסימון ארוך מדי → 422', $intSave($intCfg, ['token' => str_repeat('a', 513)], $intSrv())[0] === 422);

// ---------- נתיב בטוח ----------
check('integrations: בלי token_file → 503 not_configured', $intSave(['progress' => ['managers' => ['mgr-1']]], ['token' => $intTok], $intSrv())[1]['code'] === 'not_configured');
check('integrations: נתיב יחסי → 503', $intSave(array_replace($intCfg, ['ingest' => ['token_file' => 'relative/tok']]), ['token' => $intTok], $intSrv())[0] === 503);
check('integrations: נתיב בתוך public_html → 503', $intSave(array_replace($intCfg, ['ingest' => ['token_file' => '/home/u/public_html/tok']]), ['token' => $intTok], $intSrv())[0] === 503);
check('integrations: אסימון בקונפיג → 503', $intSave(array_replace($intCfg, ['ingest' => ['token_file' => $intPath, 'token' => 'x']]), ['token' => $intTok], $intSrv())[0] === 503);
$intWide = "$intDir/wide";
@mkdir($intWide, 0700);
chmod($intWide, 0777);
check('integrations: תיקייה הפתוחה לכולם → 503', $intSave(array_replace($intCfg, ['ingest' => ['token_file' => "$intWide/tok"]]), ['token' => $intTok], $intSrv())[0] === 503);
check('integrations: תיקייה חסרה → 503 (לא נוצרת)', $intSave(array_replace($intCfg, ['ingest' => ['token_file' => "$intDir/missing/tok"]]), ['token' => $intTok], $intSrv())[0] === 503 && !is_dir("$intDir/missing"));

// ---------- שמירה ----------
[$st, $res] = $intSave($intCfg, ['token' => $intTok], $intSrv());
check('integrations: מנהל + מקור זהה → 200', $st === 200 && $res['ok'] === true && $res['configured'] === true);
check('integrations: הקובץ נכתב עם התוכן הנכון', is_file($intPath) && trim((string) file_get_contents($intPath)) === $intTok);
check('integrations: הרשאות הקובץ 0600', (fileperms($intPath) & 0o777) === 0o600);
check('integrations: התשובה לא מכילה את האסימון', !str_contains(json_encode($res), $intTok));
check('integrations: בלי קבצי זמניים שנשארו', glob("$intDir/.ingest-token.*.tmp") === []);

$intTok2 = 'int-test-token-second-0123456789';
$intSave($intCfg, ['token' => $intTok2], $intSrv());
check('integrations: שמירה חוזרת מחליפה את הערך', trim((string) file_get_contents($intPath)) === $intTok2);

// ---------- סטטוס ----------
[$st, $res] = integration_token_status($intCfg, $intSrv(), 'litespeed');
check('integrations: סטטוס — מוגדר ונשמר', $st === 200 && $res['pathConfigured'] === true && $res['tokenStored'] === true);
check('integrations: סטטוס לא מחזיר את התוכן', !str_contains(json_encode($res), $intTok2));
chmod($intPath, 0o644);
check('integrations: קובץ קריא לאחרים → לא נחשב שמור', integration_token_status($intCfg, $intSrv(), 'litespeed')[1]['tokenStored'] === false);
chmod($intPath, 0o600);
check('integrations: בלי token_file הסטטוס מוגדר=לא', integration_token_status(['progress' => ['managers' => ['mgr-1']]], $intSrv(), 'litespeed')[1]['pathConfigured'] === false);

// ---------- סנכרון ----------
[$st, $res] = integration_sync($intCfg, $intSrv(), 'litespeed');
check('integrations: סנכרון בלי מקור → no_source_connected, בלי פעולה', $st === 200 && $res['status'] === 'no_source_connected' && $res['results'] === []);
check('integrations: סנכרון מול Origin זר → 403', integration_sync($intCfg, $intSrv('mgr-1', true, 'https://evil.example'), 'litespeed')[0] === 403);
check('integrations: מקור שהוגדר אבל לא נתמך → 501 (לא מדווח כהצלחה)',
    integration_sync(array_replace($intCfg, ['ingest' => ['token_file' => $intPath, 'sources' => ['x']]]), $intSrv(), 'litespeed')[0] === 501);
