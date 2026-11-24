<?php
declare(strict_types=1);

/**
 * פעולות ה-Dropbox של api.php. נקראות אחרי הזהות מהשער, ה-session ובדיקת ה-CSRF (לכל מה שאינו GET).
 * נקודת החיבור היחידה ב-api.php: `if (dbxHandles($action)) dbxAction(...)`.
 * הקובץ אינו נקודת קצה.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';

const DBX_ACTIONS = ['dropbox_status', 'dropbox_connect', 'dropbox_callback', 'dropbox_disconnect', 'dropbox_browse',
    'dropbox_set_root', 'dropbox_sync', 'dropbox_scan', 'dropbox_link', 'dropbox_unlink', 'dropbox_folder_create',
    'dropbox_upload', 'case_files', 'file_view', 'dropbox_health', 'dropbox_app_set'];

function dbxHandles(string $action): bool { return in_array($action, DBX_ACTIONS, true); }

/** הודעה למשתמש לפי תגית — בלי לחשוף את תשובת Dropbox. */
function dbxUserError(DbxError $e): never {
    [$status, $msg] = match (true) {
        $e->tag === 'outside_root' => [400, 'הנתיב מחוץ לתיקייה הראשית של הארגון'],
        $e->tag === 'no_root' => [409, 'עדיין לא נבחרה תיקייה ראשית ב-Dropbox'],
        $e->tag === 'not_connected' => [409, 'Dropbox לא מחובר לארגון'],
        $e->tag === 'reauth' => [409, 'החיבור ל-Dropbox פג — מנהל הארגון צריך לחבר מחדש'],
        $e->tag === 'no_app_key' => [409, 'מפתח האפליקציה של Dropbox לא הוגדר (מסך המפתח)'],
        $e->tag === 'network' => [502, 'Dropbox לא זמין כרגע. נסו שוב בעוד רגע'],
        str_contains($e->tag, 'not_found') => [404, 'לא נמצא ב-Dropbox'],
        str_contains($e->tag, 'insufficient_space') => [507, 'אין מספיק מקום בחשבון ה-Dropbox'],
        default => [502, 'פעולת Dropbox נכשלה'],
    };
    reply($status, ['error' => $msg, 'code' => mb_substr($e->tag, 0, 80)]);
}
function dbxNeed(array $who, string $perm): void {
    if (!dbxCan($who, $perm)) reply(403, ['error' => 'אין הרשאה לפעולה הזו']);
}
function dbxMethod(string $method, string $want): void {
    if ($method !== $want) reply(405, ['error' => 'שיטה לא מורשית']);
}
function dbxRedirectUri(): string {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = preg_replace('/[^A-Za-z0-9.:\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api.php'))), '/');
    return ($secure ? 'https' : 'http') . '://' . $host . $dir . '/api.php?action=dropbox_callback';
}
function dbxWebhookUri(): string { return str_replace('/api.php?action=dropbox_callback', '/dropbox-webhook.php', dbxRedirectUri()); }

/** מצב החיבור לתצוגה — בלי שום סוד. */
function dbxPublicStatus(array $org): array {
    return ['status' => $org['status'], 'account_name' => $org['account_name'], 'root_path' => $org['root_path'],
        'last_sync_at' => $org['last_sync_at'], 'last_webhook_at' => $org['last_webhook_at'],
        'last_error' => $org['last_error'], 'last_error_at' => $org['last_error_at'], 'sync_pending' => (int)$org['sync_pending'] === 1];
}
/** סנכרון בפתיחת מסך: כשהסנכרון האחרון ישן מ-2 דקות — עד 5 שניות, והשאר בפעם הבאה. */
function dbxSyncIfStale(PDO $pdo, int $orgId): void {
    $org = dbxOrg($pdo, $orgId);
    if ($org['status'] !== 'connected' || (string)$org['root_id'] === '') return;
    $last = $org['last_sync_at'] ? strtotime((string)$org['last_sync_at']) : 0;
    if ((int)$org['sync_pending'] === 1 || time() - $last >= 120) dbxSync($pdo, $orgId, 5.0);
}
function dbxFileRow(array $f): array {
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    $preview = match (true) {
        in_array($ext, ['jpg','jpeg','png','gif','webp','heic','heif','bmp','tif','tiff'], true) => 'image',
        $ext === 'pdf' => 'pdf',
        in_array($ext, ['doc','docx','ppt','pptx','odt','odp','rtf'], true) => 'pdf',
        default => 'none',
    };
    return ['id' => (int)$f['id'], 'name' => $f['name'], 'size' => (int)$f['size'], 'server_modified' => $f['server_modified'],
        'added_via' => $f['added_via'], 'added_by' => $f['added_by'], 'kind' => $f['kind'], 'preview' => $preview,
        'thumb' => $preview === 'image', 'dropbox_url' => dbxWebUrl((string)$f['path'], false)];
}

/** $pu — הזהות מהשער (ל-sub שנקשר ל-state של OAuth). נקרא אחרי resolveAccess() ובדיקת ה-CSRF. */
function dbxAction(string $action, string $method, array $pu): never {
    $user = $pu;
    $who = dbxWho();
    if ($who === null) reply(403, ['error' => 'אין שיוך לארגון']);
    $pdo = db();
    $orgId = $who['org_id'];
    try {
        match ($action) {
            'dropbox_status' => dbxActStatus($pdo, $who),
            'dropbox_connect' => dbxActConnect($pdo, $who, $method, $user),
            'dropbox_callback' => dbxActCallback($pdo, $who, $method, $user),
            'dropbox_disconnect' => dbxActDisconnect($pdo, $who, $method),
            'dropbox_browse' => dbxActBrowse($pdo, $who, $method),
            'dropbox_set_root' => dbxActSetRoot($pdo, $who, $method),
            'dropbox_sync' => (function () use ($pdo, $who, $method) {
                dbxNeed($who, 'files_view'); dbxMethod($method, 'POST');
                $r = dbxSync($pdo, $who['org_id'], 8.0);
                reply(200, ['ok' => $r['ok'], 'result' => $r, 'dropbox' => dbxPublicStatus(dbxOrg($pdo, $who['org_id']))]);
            })(),
            'dropbox_scan' => dbxActScan($pdo, $who, $method),
            'dropbox_link' => dbxActLink($pdo, $who, $method),
            'dropbox_unlink' => dbxActUnlink($pdo, $who, $method),
            'dropbox_folder_create' => dbxActFolderCreate($pdo, $who, $method),
            'dropbox_upload' => dbxActUpload($pdo, $who, $method),
            'case_files' => dbxActCaseFiles($pdo, $who, $method),
            'file_view' => dbxActFileView($pdo, $who, $method),
            'dropbox_health' => dbxActHealth($pdo, $who, $method),
            'dropbox_app_set' => dbxActAppSet($pdo, $who, $method, $user),
        };
    } catch (DbxError $e) {
        if (!in_array($e->tag, ['outside_root', 'no_root', 'not_connected', 'reauth'], true)) dbxOrgError($pdo, $orgId, $e->tag);
        dbxUserError($e);
    }
    reply(404, ['error' => 'פעולה לא מוכרת']);
}

function dbxActStatus(PDO $pdo, array $who): never {
    dbxNeed($who, 'files_view');
    dbxSyncIfStale($pdo, $who['org_id']);
    reply(200, ['ok' => true, 'dropbox' => dbxPublicStatus(dbxOrg($pdo, $who['org_id'])),
        'app_configured' => DbxClient::appKey() !== null && DbxClient::appSecret() !== null,
        'can_admin' => dbxCan($who, 'dropbox_admin'), 'can_upload' => dbxCan($who, 'files_upload')]);
}

/* ---------- OAuth 2 + PKCE ---------- */

function dbxActConnect(PDO $pdo, array $who, string $method, array $user): never {
    dbxNeed($who, 'dropbox_admin'); dbxMethod($method, 'POST');
    $key = DbxClient::appKey();
    if ($key === null || DbxClient::appSecret() === null) throw new DbxError('no_app_key');
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $state = bin2hex(random_bytes(24));
    // state חד-פעמי, קשור לארגון ולמשתמש, תקף 10 דקות
    $_SESSION['fd_dbx_oauth'] = ['state' => $state, 'verifier' => $verifier, 'org_id' => $who['org_id'], 'sub' => $user['sub'], 'exp' => time() + 600];
    $url = dbxBase('authorize') . '/oauth2/authorize?' . http_build_query([
        'client_id' => $key, 'response_type' => 'code', 'redirect_uri' => dbxRedirectUri(), 'state' => $state,
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256', 'token_access_type' => 'offline',
    ]);
    reply(200, ['ok' => true, 'url' => $url]);
}

/** החזרה מ-Dropbox. state שגוי, חוזר, פג או של משתמש/ארגון אחר → נדחה, בלי קריאה ל-Dropbox. */
function dbxActCallback(PDO $pdo, array $who, string $method, array $user): never {
    dbxMethod($method, 'GET');
    $pending = $_SESSION['fd_dbx_oauth'] ?? null;
    unset($_SESSION['fd_dbx_oauth']);                 // חד-פעמי: גם נסיון כושל שורף אותו
    $state = (string)($_GET['state'] ?? '');
    $ok = is_array($pending) && $state !== '' && hash_equals((string)$pending['state'], $state)
        && (int)$pending['exp'] >= time() && $pending['sub'] === $user['sub'] && (int)$pending['org_id'] === $who['org_id']
        && dbxCan($who, 'dropbox_admin');
    if (!$ok) dbxCallbackPage(400, 'state');
    if (isset($_GET['error'])) dbxCallbackPage(400, 'denied');
    $code = (string)($_GET['code'] ?? '');
    if ($code === '' || strlen($code) > 500) dbxCallbackPage(400, 'code');
    $key = DbxClient::appKey(); $secret = DbxClient::appSecret();
    if ($key === null || $secret === null) dbxCallbackPage(409, 'no_app_key');
    [$st, $body] = dbxHttp(dbxBase('api') . '/oauth2/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $pending['verifier'],
        'client_id' => $key, 'client_secret' => $secret, 'redirect_uri' => dbxRedirectUri()]));
    $j = json_decode($body, true);
    if ($st !== 200 || !is_string($j['refresh_token'] ?? null) || !is_string($j['account_id'] ?? null)) {
        dbxOrgError($pdo, $who['org_id'], 'oauth_exchange_' . (is_string($j['error'] ?? null) ? $j['error'] : $st));
        dbxCallbackPage(502, 'exchange');
    }
    $orgId = $who['org_id'];
    $prev = dbxOrg($pdo, $orgId);
    dbxSecretPut('dropbox_refresh', $orgId, $j['refresh_token']);
    $sameAccount = $prev['account_id'] === $j['account_id'];
    dbxOrgSet($pdo, $orgId, ['status' => 'connected', 'account_id' => $j['account_id'], 'connected_by' => $who['actor_label'],
        'connected_at' => gmdate('c'), 'last_error' => null] + ($sameAccount ? [] : ['root_id' => null, 'root_path' => null, 'root_lower' => null, 'cursor' => null]));
    try {
        $acc = (new DbxClient($pdo, $orgId))->rpc('users/get_current_account', null);
        dbxOrgSet($pdo, $orgId, ['account_name' => mb_substr((string)($acc['name']['display_name'] ?? $acc['email'] ?? ''), 0, 120)]);
    } catch (DbxError $e) { /* שם החשבון — לא קריטי */ }
    dbxEvent($orgId, null, 'ui', 'dropbox_connected', ['account_id' => [$prev['account_id'], $j['account_id']]], $who['actor']);
    header('Location: index.html#dropbox', true, 302);
    exit;
}
function dbxCallbackPage(int $status, string $why): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $msg = match ($why) {
        'denied' => 'החיבור בוטל ב-Dropbox.',
        'no_app_key' => 'מפתח האפליקציה של Dropbox לא הוגדר.',
        'exchange' => 'Dropbox לא אישרה את החיבור. נסו שוב.',
        default => 'הקישור אינו תקף (פג או כבר נוצל). התחילו את החיבור מחדש מההגדרות.',
    };
    echo '<!doctype html><html lang="he" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>חיבור Dropbox</title><body style="font-family:system-ui;padding:24px"><p>' . htmlspecialchars($msg) . '</p>'
        . '<p><a href="index.html#dropbox">חזרה להגדרות</a></p></body></html>';
    exit;
}

function dbxActDisconnect(PDO $pdo, array $who, string $method): never {
    dbxNeed($who, 'dropbox_admin'); dbxMethod($method, 'POST');
    $orgId = $who['org_id'];
    $wasStatus = dbxOrg($pdo, $orgId)['status'];
    try { (new DbxClient($pdo, $orgId))->rpc('auth/token/revoke', null); } catch (DbxError $e) { /* האסימון נמחק אצלנו בכל מקרה */ }
    dbxSecretDelete('dropbox_refresh', $orgId);
    // הקבצים ב-Dropbox לא נוגעים; רשימות הקבצים והקישורים לתיקים נשארים ("Dropbox מנותק")
    dbxOrgSet($pdo, $orgId, ['status' => 'disconnected', 'cursor' => null, 'sync_pending' => 0]);
    dbxEvent($orgId, null, 'ui', 'dropbox_disconnected', ['status' => [$wasStatus, 'disconnected']], $who['actor']);
    reply(200, ['ok' => true, 'dropbox' => dbxPublicStatus(dbxOrg($pdo, $orgId))]);
}

/** דפדוף בתיקיות החשבון (רק תיקיות) — לבחירת הראשית. */
function dbxActBrowse(PDO $pdo, array $who, string $method): never {
    dbxNeed($who, 'dropbox_admin'); dbxMethod($method, 'GET');
    $path = (string)($_GET['path'] ?? '');
    if ($path !== '' && (!str_starts_with($path, '/') || preg_match('~(^|/)\.\.?(/|$)|[\x00-\x1f]~', $path) || mb_strlen($path) > 1000)) {
        reply(400, ['error' => 'נתיב לא תקין']);
    }
    $client = new DbxClient($pdo, $who['org_id']);
    $folders = [];
    $page = $client->listFolder($path, false);
    for ($i = 0; ; $i++) {
        foreach ($page['entries'] ?? [] as $e) {
            if (($e['.tag'] ?? '') === 'folder') $folders[] = ['name' => $e['name'], 'path' => $e['path_display']];
        }
        if (empty($page['has_more']) || $i >= 4 || count($folders) > 1000) break;
        $page = $client->listContinue((string)$page['cursor']);
    }
    usort($folders, static fn($a, $b) => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));
    reply(200, ['ok' => true, 'path' => $path, 'folders' => $folders]);
}

function dbxActSetRoot(PDO $pdo, array $who, string $method): never {
    dbxNeed($who, 'dropbox_admin'); dbxMethod($method, 'POST');
    $path = cleanText(body()['path'] ?? '', 1000);
    if ($path === '' || !str_starts_with($path, '/') || preg_match('~(^|/)\.\.?(/|$)|[\x00-\x1f]~', $path)) reply(400, ['error' => 'נתיב לא תקין']);
    $orgId = $who['org_id'];
    $meta = (new DbxClient($pdo, $orgId))->rpc('files/get_metadata', ['path' => $path]);
    if (($meta['.tag'] ?? '') !== 'folder') reply(400, ['error' => 'יש לבחור תיקייה']);
    $org = dbxOrg($pdo, $orgId);
    if ($org['root_id'] !== $meta['id']) {
        $pdo->prepare('DELETE FROM dbx_folders WHERE org_id=?')->execute([$orgId]);   // מטמון תיקיות — נבנה מחדש בסנכרון
        dbxOrgSet($pdo, $orgId, ['root_id' => $meta['id'], 'root_path' => $meta['path_display'], 'root_lower' => $meta['path_lower'], 'cursor' => null]);
        dbxEvent($orgId, null, 'ui', 'dropbox_root_set', ['root' => [$org['root_path'], $meta['path_display']]], $who['actor']);
    }
    $r = dbxSync($pdo, $orgId, 8.0);
    reply(200, ['ok' => true, 'sync' => $r, 'dropbox' => dbxPublicStatus(dbxOrg($pdo, $orgId))]);
}

/* ---------- תיקייה לכל תיק ---------- */

function dbxReadyOrg(PDO $pdo, int $orgId): array {
    $org = dbxOrg($pdo, $orgId);
    if ($org['status'] === 'reauth') throw new DbxError('reauth');
    if ($org['status'] !== 'connected') throw new DbxError('not_connected');
    if ((string)$org['root_id'] === '') throw new DbxError('no_root');
    return $org;
}
function dbxCaseOr404(PDO $pdo, array $who, mixed $caseId): array {
    $id = is_string($caseId) ? $caseId : '';
    $case = preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $id) ? dbxCase($pdo, $who['org_id'], $id) : null;
    if ($case === null) reply(404, ['error' => 'התיק לא נמצא']);
    return $case;
}

function dbxActScan(PDO $pdo, array $who, string $method): never {
    dbxNeed($who, 'dropbox_admin'); dbxMethod($method, 'GET');
    $org = dbxReadyOrg($pdo, $who['org_id']);
    dbxSync($pdo, $who['org_id'], 8.0);
    reply(200, ['ok' => true] + dbxMatchScan($pdo, dbxOrg($pdo, $who['org_id'])));
}

/** אישור הצעה / שיוך ידני: התיקייה חייבת להיות תיקייה שהסנכרון ראה בתוך הראשית — מזהה אחר נדחה בלי קריאה ל-Dropbox. */
function dbxActLink(PDO $pdo, array $who, string $method): never {
    dbxNeed($who, 'dropbox_admin'); dbxMethod($method, 'POST');
    $in = body();
    $org = dbxReadyOrg($pdo, $who['org_id']);
    $case = dbxCaseOr404($pdo, $who, $in['case_id'] ?? null);
    $folderId = cleanText($in['folder_id'] ?? '', 200);
    $st = $pdo->prepare('SELECT dbx_id AS id, path AS path_display, path_lower FROM dbx_folders WHERE org_id=? AND dbx_id=?');
    $st->execute([$who['org_id'], $folderId]);
    $folder = $st->fetch();
    if (!$folder || !dbxLowerInRoot($org, $folder['path_lower']) || $folder['path_lower'] === $org['root_lower']) {
        reply(400, ['error' => 'התיקייה אינה בתוך התיקייה הראשית של הארגון']);
    }
    $busy = $pdo->prepare('SELECT case_id FROM case_folders WHERE org_id=? AND (case_id=? OR dbx_folder_id=?)');
    $busy->execute([$who['org_id'], $case['id'], $folderId]);
    if ($busy->fetch()) reply(409, ['error' => 'התיק או התיקייה כבר משויכים']);
    dbxLinkFolder($pdo, $org, (string)$case['id'], $folder, $who['actor_label']);
    dbxEvent($who['org_id'], (string)$case['id'], 'ui', 'dropbox_folder_linked', ['dropbox_folder' => [null, $folder['path_display']]], $who['actor']);
    reply(200, ['ok' => true, 'folder' => ['id' => $folderId, 'path' => $folder['path_display']]]);
}
function dbxActUnlink(PDO $pdo, array $who, string $method): never {
    dbxNeed($who, 'dropbox_admin'); dbxMethod($method, 'POST');
    $case = dbxCaseOr404($pdo, $who, body()['case_id'] ?? null);
    $lp = $pdo->prepare('SELECT dbx_path FROM case_folders WHERE org_id=? AND case_id=?');
    $lp->execute([$who['org_id'], $case['id']]);
    $linkedPath = $lp->fetchColumn() ?: null;
    $pdo->prepare('DELETE FROM case_folders WHERE org_id=? AND case_id=?')->execute([$who['org_id'], $case['id']]);
    dbxReassignFiles($pdo, $who['org_id']);
    dbxEvent($who['org_id'], (string)$case['id'], 'ui', 'dropbox_folder_unlinked', ['dropbox_folder' => [$linkedPath, null]], $who['actor']);
    reply(200, ['ok' => true]);
}
function dbxActFolderCreate(PDO $pdo, array $who, string $method): never {
    dbxNeed($who, 'files_upload'); dbxMethod($method, 'POST');
    $case = dbxCaseOr404($pdo, $who, body()['case_id'] ?? null);
    [$id, $path] = dbxCreateCaseFolder($pdo, dbxReadyOrg($pdo, $who['org_id']), $case, $who['actor']);
    reply(200, ['ok' => true, 'folder' => ['id' => $id, 'path' => $path, 'dropbox_url' => dbxWebUrl($path, true)]]);
}

/* ---------- קבצים ---------- */

function dbxActCaseFiles(PDO $pdo, array $who, string $method): never {
    dbxNeed($who, 'files_view'); dbxMethod($method, 'GET');
    $case = dbxCaseOr404($pdo, $who, $_GET['case_id'] ?? null);
    dbxSyncIfStale($pdo, $who['org_id']);
    $org = dbxOrg($pdo, $who['org_id']);
    $st = $pdo->prepare('SELECT * FROM files WHERE org_id=? AND case_id=? AND deleted=0 ORDER BY COALESCE(server_modified, created_at) DESC');
    $st->execute([$who['org_id'], $case['id']]);
    $f = $pdo->prepare('SELECT dbx_folder_id, dbx_path FROM case_folders WHERE org_id=? AND case_id=?');
    $f->execute([$who['org_id'], $case['id']]);
    $folder = $f->fetch() ?: null;
    reply(200, ['ok' => true, 'connected' => $org['status'] === 'connected', 'status' => $org['status'],
        'has_root' => (string)$org['root_id'] !== '',
        'folder' => $folder ? ['path' => $folder['dbx_path'], 'dropbox_url' => dbxWebUrl((string)$folder['dbx_path'], true)] : null,
        'files' => array_map('dbxFileRow', $st->fetchAll()),
        'can_upload' => dbxCan($who, 'files_upload')]);
}

/**
 * העלאה מהתיק: multipart (case_id, file, upload_id אופציונלי — מזהה חד-פעמי מהלקוח; חוזר → אותה שורה, בלי העלאה שנייה). עד 50MB; תמונה / PDF / Office. הקובץ עובר בשרת ל-Dropbox ונרשם ב-files
 * מתשובת Dropbox מיד. בלי עותק קבוע בשרת — הקובץ הזמני נמחק בסוף הבקשה, גם בכישלון.
 */
function dbxActUpload(PDO $pdo, array $who, string $method): never {
    $tmp = is_array($_FILES['file'] ?? null) && is_string($_FILES['file']['tmp_name'] ?? null) ? $_FILES['file']['tmp_name'] : '';
    // reply() יוצא ב-exit, ו-finally לא רץ אחרי exit — לכן גם פונקציית סיום
    if ($tmp !== '') register_shutdown_function(static function () use ($tmp) { if (is_file($tmp)) @unlink($tmp); });
    try {
        dbxNeed($who, 'files_upload'); dbxMethod($method, 'POST');
        $case = dbxCaseOr404($pdo, $who, $_POST['case_id'] ?? null);
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || is_array($f['error'] ?? null)) reply(400, ['error' => 'נדרש קובץ אחד']);
        if ((int)$f['error'] === UPLOAD_ERR_INI_SIZE || (int)$f['error'] === UPLOAD_ERR_FORM_SIZE) reply(413, ['error' => 'הקובץ גדול מהמותר בשרת']);
        if ((int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) reply(400, ['error' => 'ההעלאה נכשלה']);
        $size = (int)filesize($tmp);
        if ($size <= 0) reply(400, ['error' => 'קובץ ריק']);
        if ($size > DBX_MAX_FILE) reply(413, ['error' => 'הקובץ גדול מ-50MB']);
        $name = cleanText($f['name'] ?? '', 200);
        if ($name === '' || !dbxAllowedUpload($name, $tmp)) reply(415, ['error' => 'סוג קובץ לא נתמך (תמונה, PDF או מסמך Office)']);
        $uploadId = dbxUploadId($_POST['upload_id'] ?? null);
        $row = dropboxUploadToCase($pdo, $who['org_id'], $case, $tmp, $name, $who['actor'], 'ui', $uploadId);
        reply(200, ['ok' => true, 'file' => dbxFileRow($row)]);
    } finally {
        if ($tmp !== '' && is_file($tmp)) @unlink($tmp);
    }
}

/**
 * תצוגה מקדימה דרך השרת: תמונה → get_thumbnail_v2 (w1024h768, מטמון), PDF → download, מסמך Office → get_preview (PDF).
 * הרשאה לתיק בכל בקשה; קובץ של ארגון אחר — 404. מזהה Dropbox או נתיב מחוץ לראשית — 400, בלי קריאה ל-Dropbox.
 */
function dbxActFileView(PDO $pdo, array $who, string $method): never {
    dbxNeed($who, 'files_view'); dbxMethod($method, 'GET');
    $ref = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[1-9]\d{0,11}$/D', $ref)) {
        // לא מספר שורה שלנו: נתיב או id: של Dropbox. מותר רק אם הוא קובץ שכבר רשום בארגון, בתוך הראשית.
        $org = dbxOrg($pdo, $who['org_id']);
        if (str_starts_with($ref, 'id:')) {
            $st = $pdo->prepare('SELECT id, path_lower FROM files WHERE org_id=? AND dbx_id=? AND deleted=0');
            $st->execute([$who['org_id'], $ref]);
        } else {
            $path = dbxPathInRoot($org, $ref);
            $st = $pdo->prepare('SELECT id, path_lower FROM files WHERE org_id=? AND path_lower=? AND deleted=0');
            $st->execute([$who['org_id'], mb_strtolower($path)]);
        }
        $hit = $st->fetch();
        if (!$hit || !dbxLowerInRoot($org, (string)$hit['path_lower'])) reply(400, ['error' => 'הנתיב מחוץ לתיקייה הראשית של הארגון']);
        $ref = (string)$hit['id'];
    }
    $st = $pdo->prepare('SELECT * FROM files WHERE id=? AND org_id=? AND deleted=0');
    $st->execute([(int)$ref, $who['org_id']]);
    $f = $st->fetch();
    if (!$f || $f['case_id'] === null || dbxCase($pdo, $who['org_id'], (string)$f['case_id']) === null) reply(404, ['error' => 'הקובץ לא נמצא']);
    $org = dbxReadyOrg($pdo, $who['org_id']);
    if (!dbxLowerInRoot($org, (string)$f['path_lower'])) reply(400, ['error' => 'הנתיב מחוץ לתיקייה הראשית של הארגון']);
    $row = dbxFileRow($f);
    $client = new DbxClient($pdo, $who['org_id']);
    header_remove('Content-Disposition');
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    if ($row['preview'] === 'image') {
        $cache = dbxThumbDir() . '/' . hash('sha256', $f['dbx_id'] . '|' . $f['rev']) . '.jpg';
        if (!is_file($cache)) {
            $r = $client->content('files/get_thumbnail_v2', ['resource' => ['.tag' => 'path', 'path' => $f['dbx_id']],
                'format' => 'jpeg', 'size' => 'w1024h768', 'mode' => 'fitone_bestfit']);
            $tmp = $cache . '.' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $r['body']) !== false) { @chmod($tmp, 0600); @rename($tmp, $cache); }
            dbxThumbPrune(dirname($cache));
            $bytes = $r['body'];
        } else {
            @touch($cache);
            $bytes = (string)file_get_contents($cache);
        }
        header('Content-Type: image/jpeg');
        header('Content-Disposition: inline');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }
    if ($row['preview'] === 'pdf') {
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        $sink = fopen('php://temp/maxmemory:8388608', 'w+b');
        $ep = $ext === 'pdf' ? 'files/download' : 'files/get_preview';
        $client->content($ep, ['path' => $f['dbx_id']], '', $sink);
        rewind($sink);
        header('Content-Type: application/pdf');
        header("Content-Security-Policy: frame-ancestors 'self'");   // מציג ה-PDF של הדפדפן לא עובד תחת default-src 'none'
        header('Content-Disposition: inline');
        fpassthru($sink);
        exit;
    }
    reply(415, ['error' => 'אין תצוגה מקדימה לסוג הזה — לפתוח ב-Dropbox', 'dropbox_url' => $row['dropbox_url']]);
}

/* ---------- מסך המפתח ---------- */

function dbxActHealth(PDO $pdo, array $who, string $method): never {
    dbxNeed($who, 'developer'); dbxMethod($method, 'GET');
    $orgs = $pdo->query('SELECT * FROM dbx_orgs ORDER BY org_id')->fetchAll();
    reply(200, ['ok' => true, 'app_key_set' => DbxClient::appKey() !== null, 'app_secret_set' => DbxClient::appSecret() !== null,
        'redirect_uri' => dbxRedirectUri(), 'webhook_uri' => dbxWebhookUri(),
        'orgs' => array_map(static fn($o) => ['org_id' => (int)$o['org_id']] + dbxPublicStatus($o), $orgs)]);
}
/** App key / App secret — נכתבים ולא נקראים בחזרה. */
function dbxActAppSet(PDO $pdo, array $who, string $method, array $user): never {
    dbxNeed($who, 'developer'); dbxMethod($method, 'POST');
    $in = body();
    $key = trim(is_string($in['app_key'] ?? null) ? $in['app_key'] : '');
    $secret = trim(is_string($in['app_secret'] ?? null) ? $in['app_secret'] : '');
    if ($key === '' && $secret === '') reply(400, ['error' => 'נדרש App key או App secret']);
    foreach ([$key, $secret] as $v) {
        if ($v !== '' && !preg_match('/^[A-Za-z0-9]{8,64}$/D', $v)) reply(400, ['error' => 'ערך לא תקין (אותיות ומספרים בלבד)']);
    }
    if ($key !== '') dbxSecretPut('dropbox_app_key', DBX_GLOBAL_ORG, $key);
    if ($secret !== '') dbxSecretPut('dropbox_app_secret', DBX_GLOBAL_ORG, $secret);
    dbxEvent(DBX_GLOBAL_ORG, null, 'ui', 'dropbox_app_set', ['app_key' => [null, $key !== '' ? 'updated' : 'unchanged'], 'app_secret' => [null, $secret !== '' ? 'updated' : 'unchanged']], $who['actor']);
    reply(200, ['ok' => true, 'app_key_set' => DbxClient::appKey() !== null, 'app_secret_set' => DbxClient::appSecret() !== null]);
}

/** שורת "Dropbox" בבדיקות התקינות של מסך המפתח (אפיון 03 §3.6): App key, ולכל ארגון — מצב, סנכרון, webhook ושגיאה. */
function dbxHealthRow(PDO $pdo): array {
    $appOk = DbxClient::appKey() !== null && DbxClient::appSecret() !== null;
    $orgs = $pdo->query('SELECT d.*, o.name FROM dbx_orgs d LEFT JOIN orgs o ON o.id = d.org_id ORDER BY d.org_id')->fetchAll();
    $label = ['connected' => 'מחובר', 'reauth' => 'צריך חיבור מחדש', 'disconnected' => 'לא מחובר'];
    $parts = [$appOk ? 'App key מוגדר' : 'App key / App secret חסרים'];
    foreach ($orgs as $o) {
        $parts[] = ($o['name'] ?? ('ארגון ' . $o['org_id'])) . ': ' . ($label[$o['status']] ?? $o['status'])
            . ($o['last_sync_at'] ? ' · סנכרון ' . $o['last_sync_at'] : '') . ($o['last_webhook_at'] ? ' · webhook ' . $o['last_webhook_at'] : '')
            . ($o['last_error'] ? ' · שגיאה: ' . $o['last_error'] : '');
    }
    $up = dbxIniBytes((string)ini_get('upload_max_filesize')); $post = dbxIniBytes((string)ini_get('post_max_size'));
    $limitsOk = $up >= DBX_MAX_FILE && $post > DBX_MAX_FILE;
    $parts[] = 'העלאה: upload_max_filesize=' . ini_get('upload_max_filesize') . ', post_max_size=' . ini_get('post_max_size') . ($limitsOk ? '' : ' (נדרש ≥ 50M)');
    $ok = $appOk && $limitsOk && !array_filter($orgs, static fn($o) => $o['status'] === 'reauth');
    return ['name' => 'Dropbox', 'ok' => $ok, 'state' => implode(' | ', $parts)];
}

/** upload_id מהלקוח: 8–64 תווים [A-Za-z0-9_-], או null. ערך אחר → 400. */
function dbxUploadId(mixed $v): ?string {
    if ($v === null || $v === '') return null;
    if (!is_string($v) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $v)) reply(400, ['error' => 'מזהה העלאה לא תקין']);
    return $v;
}

/* ===== ממשק יציב לפרוסות 04 (שטח), 05 (API חיצוני) ו-06 (AI). ההרשאה — באחריות הקורא. ===== */

/**
 * העלאה לתיקיית התיק (נפתחת אם אין), ורישום ב-files מתשובת Dropbox. $tmp — קובץ מקומי שכבר נבדק (סוג וגודל);
 * הקורא מוחק אותו. $via: ui / field / api. $uploadId — חד-פעמי לארגון: אם כבר הועלה — מחזיר את השורה הקיימת.
 * @param array $actor ['id','actor'] @return array שורת files. זורק DbxError.
 */
function dropboxUploadToCase(PDO $pdo, int $orgId, array $case, string $tmp, string $name, array $actor, string $via, ?string $uploadId = null): array {
    $one = static function (string $sql, array $args) use ($pdo): ?array { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetch() ?: null; };
    if ($uploadId !== null && ($old = $one('SELECT * FROM files WHERE org_id=? AND upload_id=?', [$orgId, $uploadId]))) return $old;
    $org = dbxReadyOrg($pdo, $orgId);
    $folder = $one('SELECT COALESCE(df.path, cf.dbx_path) AS path FROM case_folders cf
        LEFT JOIN dbx_folders df ON df.org_id=cf.org_id AND df.dbx_id=cf.dbx_folder_id WHERE cf.org_id=? AND cf.case_id=?', [$orgId, $case['id']]);
    $folderPath = $folder ? (string)$folder['path'] : dbxCreateCaseFolder($pdo, $org, $case, $actor)[1];
    $meta = dbxUploadFile($pdo, $org, $folderPath, $tmp, $name);
    if (!isset($meta['id'], $meta['path_lower']) || !dbxLowerInRoot($org, (string)$meta['path_lower'])) throw new DbxError('upload_result');
    $by = (string)$actor['actor'];
    dbxUpsertFile($pdo, $orgId, $meta, $via, $by);
    $pdo->prepare('UPDATE files SET added_via=?, added_by=COALESCE(added_by, ?), upload_id=COALESCE(upload_id, ?) WHERE dbx_id=?')
        ->execute([$via, $by, $uploadId, $meta['id']]);
    dbxEvent($orgId, (string)$case['id'], $via === 'api' ? 'api' : 'ui', 'file_uploaded',
        ['file' => [null, $meta['name']], 'via' => [null, $via], 'size' => [null, (int)$meta['size']]], $actor);
    return $one('SELECT * FROM files WHERE dbx_id=?', [$meta['id']]);
}

/** קבצי התיק (לא מחוקים), בצורה של case_files. $case — עם id ו-org_id (כמו מ-existing()). */
function dropboxCaseFiles(PDO $pdo, array $case): array {
    $st = $pdo->prepare('SELECT * FROM files WHERE org_id=? AND case_id=? AND deleted=0 ORDER BY COALESCE(server_modified, created_at) DESC');
    $st->execute([(int)$case['org_id'], (string)$case['id']]);
    return array_map('dbxFileRow', $st->fetchAll());
}

/**
 * התוכן של קובץ בארגון (מספר השורה ב-files, כמו ב-case_files): ['name','mime','size','bytes'], עד $maxBytes.
 * קובץ של ארגון אחר / נמחק / בלי תיק → null. גדול מ-$maxBytes → DbxError('too_large'). זורק DbxError גם כש-Dropbox לא מחובר.
 */
function dropboxFileContent(PDO $pdo, int $orgId, int $fileId, int $maxBytes = 10485760): ?array {
    $st = $pdo->prepare('SELECT * FROM files WHERE id=? AND org_id=? AND deleted=0 AND case_id IS NOT NULL');
    $st->execute([$fileId, $orgId]);
    $f = $st->fetch();
    if (!$f) return null;
    if ((int)$f['size'] > $maxBytes) throw new DbxError('too_large');
    $org = dbxReadyOrg($pdo, $orgId);
    if (!dbxLowerInRoot($org, (string)$f['path_lower'])) return null;
    $sink = fopen('php://temp/maxmemory:' . $maxBytes, 'w+b');
    (new DbxClient($pdo, $orgId))->content('files/download', ['path' => $f['dbx_id']], '', $sink);
    rewind($sink);
    $bytes = (string)stream_get_contents($sink, $maxBytes + 1);
    fclose($sink);
    if (strlen($bytes) > $maxBytes) throw new DbxError('too_large');
    return ['name' => $f['name'], 'mime' => (string)(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes), 'size' => strlen($bytes), 'bytes' => $bytes];
}

/**
 * טקסט של קובץ, עד 200KB — רק לקובצי טקסט (txt, csv, md, json). PDF / תמונה / Office → DbxError('not_text'):
 * חילוץ טקסט מהם אינו של פרוסה 03 (06 שולחת את dropboxFileContent לספק ה-AI). לא קיים → null.
 */
function dropboxFileText(PDO $pdo, int $orgId, int $fileId): ?string {
    $st = $pdo->prepare('SELECT name FROM files WHERE id=? AND org_id=? AND deleted=0');
    $st->execute([$fileId, $orgId]);
    $name = $st->fetchColumn();
    if ($name === false) return null;
    if (!in_array(strtolower(pathinfo((string)$name, PATHINFO_EXTENSION)), ['txt', 'csv', 'md', 'json'], true)) throw new DbxError('not_text');
    $c = dropboxFileContent($pdo, $orgId, $fileId, 204800);
    return $c === null ? null : mb_convert_encoding($c['bytes'], 'UTF-8', 'UTF-8');
}

/** "50M" / "8M" / "1G" / בתים → בתים. */
function dbxIniBytes(string $v): int {
    $v = trim($v);
    $n = (int)$v;
    return match (strtoupper(substr($v, -1))) { 'G' => $n << 30, 'M' => $n << 20, 'K' => $n << 10, default => $n };
}
