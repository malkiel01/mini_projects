<?php
/**
 * בדיקות השרת: php suite-cloud/tests/run.php
 * רצות על תיקיית נתונים זמנית; הזהות של השער מדומה ב-$_SERVER['PLATFORM_USER'].
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmpDir = sys_get_temp_dir() . '/suite-cloud-test-' . getmypid();
define('DATA_DIR', $tmpDir);
define('CLOUD_ORIGIN', 'https://mbe-plus.com');
define('CLOUD_ISSUER', 'https://mbe-plus.com/mini_projects/suite-cloud/');
require_once __DIR__ . '/../lib/drive.php';
require_once __DIR__ . '/../lib/clients.php';
require_once __DIR__ . '/../lib/ai.php';
require_once __DIR__ . '/../lib/fonts.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok): void {
    global $fails, $n;
    $n++;
    if (!$ok) { $fails++; echo "✗ $name\n"; } else echo "✓ $name\n";
}
function as_platform(?array $id): void {
    if ($id === null) unset($_SERVER['PLATFORM_USER']);
    else $_SERVER['PLATFORM_USER'] = json_encode($id);
}
function code(callable $fn): string {
    try { $fn(); return 'ok'; } catch (AppError $e) { return $e->codeName; }
}
function b64(string $s): string { return base64_encode($s); }
function body(string $bytes): string {
    $f = tempnam(sys_get_temp_dir(), 'chunk');
    file_put_contents($f, $bytes);
    return $f;
}
function rrmdir(string $d): void {
    if (!is_dir($d)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($d);
}

try {
    // ── חשבון ──
    as_platform(null);
    check('בלי זהות מהשער — אין חשבון', currentAccount() === null);
    check('בלי זהות — 401', code('requireAccount') === 'unauthorized');

    as_platform(['sub' => '1', 'name' => 'בעל המערכת', 'email' => 'Owner@Example.com', 'platform_admin' => true]);
    $owner = requireAccount();
    check('platform_admin — מנהל הענן', $owner['role'] === 'admin');
    check('המייל נשמר באותיות קטנות', $owner['email'] === 'owner@example.com');
    check('כניסה חוזרת — אותו חשבון', requireAccount()['id'] === $owner['id']);

    as_platform(['sub' => '2', 'name' => 'דנה', 'email' => 'dana@example.com']);
    $dana = requireAccount();
    check('משתמש רגיל — user', $dana['role'] === 'user');
    check('מכסת ברירת מחדל — 0 (הכרעה 1)', quotaInfo($dana)['quota'] === 0);
    check('תקרה לקובץ — 500MB (הכרעה 2)', quotaInfo($dana)['max_file'] === 524288000);
    check('בלי מכסה — כתיבה נחסמת', code(fn() => writeFile($dana, ['name' => 'a.txt', 'content_b64' => b64('x')])) === 'quota_exceeded');
    check('משתמש רגיל — לא בניהול', code('requireAdmin') === 'forbidden');

    db()->prepare("INSERT INTO accounts (id, email, name, created_at) VALUES ('a_old', 'old@example.com', 'ותיק', ?)")->execute([nowIso()]);
    as_platform(['sub' => '3', 'email' => 'OLD@example.com']);
    check('איחוד לפי מייל', requireAccount()['id'] === 'a_old');
    as_platform(['sub' => '4', 'email' => 'dana@example.com']);
    check('מייל מקושר ל-sub אחר — 403', code('requireAccount') === 'forbidden');

    // ── ניהול ──
    adminAccount($owner, ['account' => $dana['id'], 'quota' => 1000]);
    $dana = getAccount($dana['id']);
    check('המנהל קבע מכסה', quotaInfo($dana)['quota'] === 1000);
    check('המנהל לא מוריד את עצמו', code(fn() => adminAccount($owner, ['account' => $owner['id'], 'role' => 'user'])) === 'bad_request');
    adminSettings(['default_quota' => 5000]);
    check('ברירת מחדל חדשה — לא דורסת מכסה אישית', quotaInfo($dana)['quota'] === 1000 && accountQuota(getAccount('a_old')) === 5000);

    // ── שמות ──
    check('שם עם / — נדחה', code(fn() => cleanName('a/b')) === 'bad_name');
    check('שם ".." — נדחה', code(fn() => cleanName('..')) === 'bad_name');
    check('שם עם תו בקרה — נדחה', code(fn() => cleanName("a\x01")) === 'bad_name');
    check('רווחים בקצוות — נחתכים', cleanName('  ספר.mbe ') === 'ספר.mbe');

    // ── כתיבה ──
    $f = writeFile($dana, ['name' => 'ספר.txt', 'content_b64' => b64('שלום')]);
    check('write — נוצר קובץ', $f['kind'] === 'file' && $f['size'] === strlen('שלום') && $f['rev'] === 1);
    check('mime מהסיומת', $f['mime'] === 'text/plain');
    check('הנפח נספר', quotaInfo($dana)['used'] === strlen('שלום'));
    $f2 = writeFile($dana, ['name' => 'ספר.txt', 'content_b64' => b64('עוד')]);
    check('שם תפוס — "(2)"', $f2['name'] === 'ספר (2).txt');
    check('שם תפוס, אותיות שונות — גם "(n)"', writeFile($dana, ['name' => 'X.txt', 'content_b64' => b64('1')])['name'] === 'X.txt'
        && writeFile($dana, ['name' => 'x.txt', 'content_b64' => b64('1')])['name'] === 'x (2).txt');
    check('on_conflict=fail — name_taken', code(fn() => writeFile($dana, ['name' => 'ספר.txt', 'content_b64' => b64('z'), 'on_conflict' => 'fail'])) === 'name_taken');
    $r = writeFile($dana, ['name' => 'ספר.txt', 'content_b64' => b64('חדש'), 'if_rev' => 1]);
    check('if_rev תואם — כתיבה על אותו קובץ, rev 2', $r['id'] === $f['id'] && $r['rev'] === 2);
    check('if_rev ישן — conflict', code(fn() => writeFile($dana, ['name' => 'ספר.txt', 'content_b64' => b64('ישן'), 'if_rev' => 1])) === 'conflict');
    check('חריגה מהמכסה — quota_exceeded', code(fn() => writeFile($dana, ['name' => 'big', 'content_b64' => b64(str_repeat('x', 2000))])) === 'quota_exceeded');
    check('החלפה נספרת כהפרש, לא כתוספת', (function () use ($dana) {
        $u = quotaInfo($dana)['used'];
        writeFile($dana, ['name' => 'ספר.txt', 'content_b64' => b64('חדש'), 'on_conflict' => 'replace']);
        return quotaInfo($dana)['used'] === $u;
    })());

    // ── תיקיות, העברה, שינוי שם ──
    $dir = makeFolder($dana, ['name' => 'פרויקטים']);
    check('mkdir', $dir['kind'] === 'folder');
    check('mkdir replace — מחזיר את הקיימת', makeFolder($dana, ['name' => 'פרויקטים', 'on_conflict' => 'replace'])['id'] === $dir['id']);
    $sub = makeFolder($dana, ['name' => 'ספרים', 'parent' => $dir['id']]);
    $m = moveNode($dana, ['id' => $f['id'], 'parent' => $sub['id']]);
    check('move', $m['parent'] === $sub['id']);
    check('תיקייה לתוך עצמה — נדחה', code(fn() => moveNode($dana, ['id' => $dir['id'], 'parent' => $sub['id']])) === 'bad_request');
    check('rename', renameNode($dana, ['id' => $f['id'], 'name' => 'פרק 1.txt'])['name'] === 'פרק 1.txt');
    $l = listFolder($dana, $sub['id']);
    check('list — פירורי לחם', array_column($l['path'], 'name') === ['פרויקטים', 'ספרים']);
    check('list — תיקיות קודם', listFolder($dana, null)['items'][0]['kind'] === 'folder');

    // ── בידוד בין חשבונות ──
    check('קובץ של חשבון אחר — 404', code(fn() => liveNode($owner, $f['id'])) === 'not_found');
    check('כתיבה לתיקייה של חשבון אחר — 404', code(fn() => makeFolder($owner, ['name' => 'x', 'parent' => $dir['id']])) === 'not_found');

    // ── סל ──
    trashNode($dana, ['id' => $dir['id']]);
    check('בסל — לא ברשימה', !in_array($dir['id'], array_column(listFolder($dana, null)['items'], 'id'), true));
    check('מה שבתוך תיקייה בסל — לא נגיש', code(fn() => liveNode($dana, $f['id'])) === 'not_found');
    check('הסל נספר במכסה', quotaInfo($dana)['used'] > 0);
    makeFolder($dana, ['name' => 'פרויקטים']);
    $back = restoreNode($dana, ['id' => $dir['id']]);
    check('שחזור לשם תפוס — "(2)"', $back['name'] === 'פרויקטים (2)' && liveNode($dana, $f['id'])['id'] === $f['id']);

    // ── העלאה בחלקים ──
    adminAccount($owner, ['account' => $owner['id'], 'quota' => 100 * 1024 * 1024]);
    $owner = getAccount($owner['id']);
    rrmdir(DATA_DIR . '/uploads');   // שרת נקי: העלאה בחלקים לפני כל write
    $data = random_bytes(CHUNK + 1234);
    $up = uploadStart($owner, ['name' => 'גדול.bin', 'size' => strlen($data), 'sha256' => hash('sha256', $data)]);
    check('upload_start', $up['chunk'] === CHUNK && $up['received'] === 0);
    check('חלק לא בסדר — נדחה', code(fn() => uploadChunk($owner, $up['upload'], 1, body('x'))) === 'bad_request');
    check('upload_finish לפני הסוף — נדחה', code(fn() => uploadFinish($owner, $up['upload'])) === 'bad_request');
    uploadChunk($owner, $up['upload'], 0, body(substr($data, 0, CHUNK)));
    check('חלק שכבר התקבל — מתעלמים (חידוש)', uploadChunk($owner, $up['upload'], 0, body(substr($data, 0, CHUNK)))['received'] === CHUNK);
    uploadChunk($owner, $up['upload'], 1, body(substr($data, CHUNK)));
    $big = uploadFinish($owner, $up['upload']);
    check('upload_finish — הקובץ בעץ', $big['size'] === strlen($data) && is_file(blobPath($big['sha256'])));
    [$path] = readTarget($owner, $big['id']);
    check('read — אותו תוכן', hash_file('sha256', $path) === hash('sha256', $data));
    $dup = uploadStart($owner, ['name' => 'עותק.bin', 'size' => strlen($data), 'sha256' => hash('sha256', $data)]);
    check('תוכן שכבר קיים — בלי העלאה', $dup['received'] === strlen($data));
    $copy = uploadFinish($owner, $dup['upload']);
    check('עותק — נספר פעמיים במכסה', quotaInfo($owner)['used'] === 2 * strlen($data));

    $nosha = uploadStart($owner, ['name' => 'בלי גיבוב.txt', 'size' => 5]);
    uploadChunk($owner, $nosha['upload'], 0, body('hello'));
    $ns = uploadFinish($owner, $nosha['upload']);
    check('בלי sha256 — השרת מחשב', $ns['sha256'] === hash('sha256', 'hello'));
    $empty = uploadStart($owner, ['name' => 'ריק.txt', 'size' => 0]);
    check('קובץ ריק', uploadFinish($owner, $empty['upload'])['size'] === 0);
    trashNode($owner, ['id' => $ns['id']]);
    purgeNode($owner, ['id' => $ns['id']]);

    $bad = uploadStart($owner, ['name' => 'פגום.bin', 'size' => 3, 'sha256' => hash('sha256', 'abc')]);
    uploadChunk($owner, $bad['upload'], 0, body('abd'));
    check('גיבוב לא תואם — bad_checksum', code(fn() => uploadFinish($owner, $bad['upload'])) === 'bad_checksum');
    check('upload_start מעל המכסה — נדחה מראש', code(fn() => uploadStart($dana, ['name' => 'x', 'size' => 10 ** 9, 'sha256' => str_repeat('a', 64)])) === 'too_large');

    // ── מחיקה לצמיתות ──
    trashNode($owner, ['id' => $big['id']]);
    purgeNode($owner, ['id' => $big['id']]);
    check('מחיקה לצמיתות — התוכן נשאר כל עוד עותק מפנה אליו', is_file(blobPath($copy['sha256'])));
    trashNode($owner, ['id' => $copy['id']]);
    check('רוקן סל', emptyTrash($owner) === 1);
    check('אחרי העותק האחרון — התוכן נמחק מהדיסק', !is_file(blobPath($copy['sha256'])));
    check('המכסה התפנתה', quotaInfo($owner)['used'] === 0);

    trashNode($dana, ['id' => $dir['id']]);
    db()->prepare('UPDATE nodes SET trashed_at = ? WHERE id = ?')->execute([nowIso(time() - 31 * 86400), $dir['id']]);
    purgeTrashMaybe(true);
    check('אחרי 30 יום — נמחק עם כל מה שבתוכו', code(fn() => nodeRow($dana, $f['id'])) === 'not_found');

    check('cloud_id — קבוע ואקראי', str_starts_with(setting('cloud_id'), 'cl_') && setting('cloud_id') === setting('cloud_id'));
    check('ניהול — רשימת חשבונות עם שימוש', count(adminState()['accounts']) === 3);

    // ── חיבור אפליקציה ──
    $pair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($pair);
    $keyJson = fn($id, $pk) => json_encode(['client' => $id, 'keys' => [['kid' => 'k1', 'alg' => 'Ed25519', 'key' => base64_encode($pk)]]]);
    $GLOBALS['CLOUD_FETCH'] = fn($url) => str_contains($url, 'vector') ? $keyJson('vector-studio', sodium_crypto_sign_publickey($pair))
                                                                       : $keyJson('far-app', sodium_crypto_sign_publickey($pair));
    $reg = ['client' => 'vector-studio', 'name' => 'וקטורי', 'key_url' => 'https://mbe-plus.com/mini_projects/vector-studio/api.php?action=cloud_key',
            'origins' => ['https://mbe-plus.com'], 'return' => ['https://mbe-plus.com/mini_projects/vector-studio/api.php?action=cloud_callback']];
    check('רישום מאותו origin — active', registerClient($reg)['status'] === 'active');
    check('רישום חוזר זהה — בלי שינוי', registerClient($reg)['status'] === 'active');
    $far = ['client' => 'far-app', 'name' => 'רחוקה', 'key_url' => 'https://far.example/key', 'origins' => ['https://far.example'],
            'return' => ['https://far.example/cb']];
    check('רישום מדומיין אחר — pending', registerClient($far)['status'] === 'pending');
    check('key_url מחוץ ל-origins — נדחה', code(fn() => registerClient(['key_url' => 'https://evil.example/k'] + $reg)) === 'bad_request');
    check('כתובת חזרה מחוץ ל-origins — נדחה', code(fn() => registerClient(['return' => ['https://evil.example/cb']] + $reg)) === 'bad_request');
    check('מפתח של לקוח אחר ב-key_url — נדחה', code(fn() => registerClient(['client' => 'other-app'] + $reg)) === 'bad_key_url');
    check('לקוח pending — לא מתחבר', code(fn() => connectRequest(['client' => 'far-app', 'state' => 'abcdefgh', 'return' => 'https://far.example/cb'])) === 'pending');

    $q = ['client' => 'vector-studio', 'scope' => 'files', 'state' => 'st4te-1234', 'return' => $reg['return'][0]];
    check('כתובת חזרה לא רשומה — נדחה', code(fn() => connectRequest(['return' => 'https://mbe-plus.com/other'] + $q)) === 'bad_request');
    check('הרשאה לא מוכרת — נדחה', code(fn() => connectRequest(['scope' => 'root'] + $q)) === 'bad_request');
    $req = connectRequest($q);
    check('בפעם הראשונה — צריך אישור', alreadyGranted($owner, $req) === null);
    $back = approveConnect($owner, $req);
    parse_str((string) parse_url($back, PHP_URL_QUERY), $bq);
    check('אישור — חזרה עם code ו-state', ($bq['state'] ?? '') === 'st4te-1234' && strlen($bq['code'] ?? '') > 20 && ($bq['action'] ?? '') === 'cloud_callback');
    check('אחרי אישור — בפעם הבאה בלי מסך', alreadyGranted($owner, $req) !== null);
    check('הרשאה נוספת — שוב מסך', alreadyGranted($owner, connectRequest(['scope' => 'files,ai'] + $q)) === null);

    $sign = function (string $action, string $body, ?string $nonce = null, ?int $t = null) use ($sk) {
        $t ??= time(); $nonce ??= bin2hex(random_bytes(16));
        return ['client' => 'vector-studio', 'time' => (string) $t, 'nonce' => $nonce,
                'signature' => 'k1.' . base64_encode(sodium_crypto_sign_detached("$action\n$t\n$nonce\n" . hash('sha256', $body), $sk))];
    };
    $body = json_encode(['code' => $bq['code']]);
    $client = verifySigned('connect_exchange', $sign('connect_exchange', $body), $body);
    check('חתימה תקינה — הלקוח', $client['id'] === 'vector-studio');
    $ex = exchangeCode($client, ['code' => $bq['code']]);
    check('connect_exchange — חיבור, שם וענן', str_starts_with($ex['connection'], 'c_') && $ex['account']['name'] === 'בעל המערכת' && $ex['scope'] === ['files'] && $ex['cloud_id'] === setting('cloud_id'));
    check('קוד חד-פעמי', code(fn() => exchangeCode($client, ['code' => $bq['code']])) === 'bad_code');
    $h = $sign('x', '{}', 'aaaaaaaaaaaaaaaa');
    verifySigned('x', $h, '{}');
    check('אותו nonce פעמיים — replay', code(fn() => verifySigned('x', $h, '{}')) === 'replay');
    check('בקשה ישנה — stale', code(fn() => verifySigned('x', $sign('x', '{}', null, time() - 1000), '{}')) === 'stale');
    check('גוף ששונה — bad_signature', code(fn() => verifySigned('x', $sign('x', '{}'), '{"a":1}')) === 'bad_signature');
    check('פעולה אחרת מזו שנחתמה — bad_signature', code(fn() => verifySigned('y', $sign('x', '{}'), '{}')) === 'bad_signature');

    $conn = connectionRow($ex['connection'], 'vector-studio');
    $tok = issueToken($client, $conn);
    [$c2] = verifyToken($tok['token']);
    check('אסימון — 15 דקות, תקין', $tok['expires_in'] === 900 && $c2['id'] === $conn['id']);
    [$p, $sg] = explode('.', $tok['token']);
    $forged = b64u(str_replace('"files"', '"ai"', (string) unb64u($p))) . '.' . $sg;
    check('אסימון ששונה — נדחה', code(fn() => verifyToken($forged)) === 'bad_token');
    check('בלי ההרשאה — forbidden', code(fn() => actorFrom($conn, $client, 'ai')) === 'forbidden');

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tok['token'];
    $x = actor('write', 'files', '');
    check('Bearer — הפעולה בשם החשבון והאפליקציה', $x['account']['id'] === $owner['id'] && $x['app'] === 'vector-studio');
    $w = writeFile($x['account'], ['name' => 'מוקטורי.txt', 'content_b64' => b64('v')], $x['app']);
    check('קובץ שאפליקציה כתבה — רשום עם שמה', $w['updated_by']['app'] === 'vector-studio');
    unset($_SERVER['HTTP_AUTHORIZATION']);

    $_SERVER += ['HTTP_X_CLOUD_CONNECTION' => $conn['id']];
    foreach ($sign('list', '') as $k => $v) $_SERVER['HTTP_X_CLOUD_' . strtoupper($k)] = $v;
    check('חתימה + X-Cloud-Connection — הפעולה בשם החשבון', actor('list', 'files', '')['app'] === 'vector-studio');
    foreach (['CLIENT', 'TIME', 'NONCE', 'SIGNATURE', 'CONNECTION'] as $k) unset($_SERVER['HTTP_X_CLOUD_' . $k]);

    check('החיבור מופיע ב"חיבורים"', count(accountConnections($owner)) === 1);
    check('ניתוק של חיבור של חשבון אחר — 404', code(fn() => revokeOwnConnection($dana, $conn['id'])) === 'not_found');
    revokeOwnConnection($owner, $conn['id']);
    check('ניתוק — אסימון שעוד בתוקף נדחה מיד', code(fn() => verifyToken($tok['token'])) === 'disconnected');
    check('אחרי ניתוק — שוב מסך אישור', alreadyGranted($owner, $req) === null);
    adminClient(['client' => 'far-app', 'status' => 'active']);
    check('מנהל מאשר לקוח ממתין', activeClient('far-app')['status'] === 'active');
    $far2 = ['key_url' => 'https://far.example/key2'] + $far;
    check('פרטים שהשתנו — חזרה ל-pending', registerClient($far2)['status'] === 'pending');
    check('מפתח הענן — 0600 ומפורסם', (fileperms(DATA_DIR . '/sign.key') & 0777) === 0600 && cloudKeyPublic()['alg'] === 'Ed25519');

    // ── בינה מלאכותית (הספקים מדומים — אף קריאה לא יוצאת) ──
    $png = function (int $w, int $h, bool $alpha = false, int $gray = 255) {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false); imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, $gray, $gray, $gray, $alpha ? 127 : 0));
        if ($alpha) imagefilledrectangle($im, 0, 0, (int) ($w / 2), (int) ($h / 2), imagecolorallocatealpha($im, 200, 0, 0, 0));
        ob_start(); imagepng($im); return ob_get_clean();
    };
    $calls = [];
    $replies = [];
    $GLOBALS['AI_HTTP'] = function ($method, $url, $headers, $body) use (&$calls, &$replies) {
        $calls[] = compact('method', 'url', 'headers', 'body');
        foreach ($replies as $pat => $r) if (str_contains($url, $pat)) return is_callable($r) ? $r($body) : $r;
        return [404, '{"error":{"message":"no mock"}}'];
    };
    $acc = getAccount($owner['id']);

    $cap = aiCapabilities($acc, '');
    check('בלי מפתחות — הכול false', !in_array(true, $cap['tasks'], true));
    aiSetKey('openai', 'sk-test-SECRET-1234');
    check('המפתח מוצפן במסד', !str_contains((string) db()->query("SELECT key_enc FROM ai_providers WHERE id='openai'")->fetchColumn(), 'SECRET')
        && aiKey('openai') === 'sk-test-SECRET-1234' && aiAdminState()['providers'][0]['key_tail'] === '1234');
    check('מפתח הצפנה — 0600', (fileperms(DATA_DIR . '/secret.key') & 0777) === 0600);
    check('יש מפתח, אבל המשתמש לא הורשה — false', aiCapabilities($acc, '')['tasks']['generate-image'] === false);
    check('ai_run בלי הרשאה — not-allowed', code(fn() => aiRun($acc, '', ['task' => 'generate-image', 'prompt' => 'x'])) === 'not-allowed');
    check('משימה בלי שום ספק — no-provider', code(fn() => aiRun($acc, '', ['task' => 'segment', 'image' => $png(10, 10)])) === 'no-provider');

    aiAdminUser(['account' => $acc['id'], 'providers' => ['openai'], 'monthly' => 0.2]);
    $cap = aiCapabilities($acc, '');
    check('הורשה ל-OpenAI — יצירה, מילוי וטקסט', $cap['tasks']['generate-image'] && $cap['tasks']['inpaint'] && $cap['tasks']['complete']
        && !$cap['tasks']['remove-background'] && $cap['default']->{'generate-image'} === 'openai' && $cap['quota']['limit'] == 0.2);

    $replies = ['images/generations' => [200, json_encode(['data' => [['b64_json' => base64_encode($png(1024, 1536, true))]]])]];
    $r = aiRun($acc, '', ['task' => 'generate-image', 'prompt' => 'לוגו', 'size' => '1080x1350', 'transparent' => true]);
    $sent = json_decode($calls[0]['body'], true);
    check('יחס חופשי 1080x1350 → 1024x1536, רקע שקוף', $sent['size'] === '1024x1536' && $sent['background'] === 'transparent' && $sent['model'] === 'gpt-image-1');
    check('יצירה — PNG, הגודל שיצא, שקוף', aiSize($r['png']) === [1024, 1536] && $r['size'] === [1024, 1536] && $r['transparent'] === true);
    check('המפתח נשלח רק בכותרת לספק', in_array('Authorization: Bearer sk-test-SECRET-1234', $calls[0]['headers'], true));

    $calls = [];
    $replies = ['images/edits' => [200, json_encode(['data' => [['b64_json' => base64_encode($png(1536, 1024))]]])]];
    $r = aiRun($acc, '', ['task' => 'inpaint', 'image' => $png(300, 200), 'mask' => $png(300, 200, false, 255), 'prompt' => 'שמיים']);
    $mask = imagecreatefromstring($calls[0]['body']['mask']->data);
    check('מילוי — התוצאה בגודל הקלט', aiSize($r['png']) === [300, 200]);
    check('מסכה ל-OpenAI: לבן → שקוף', (imagecolorat($mask, 5, 5) >> 24 & 0x7F) === 127 && imagesx($mask) === 1536);
    check('מכסה — נגמרה אחרי שתי קריאות של $0.07', code(fn() => aiRun($acc, '', ['task' => 'generate-image', 'prompt' => 'x'])) === 'quota');
    check('השימוש נרשם', aiSpent($acc['id']) == 0.14);

    aiAdminUser(['account' => $acc['id'], 'providers' => ['openai', 'google', 'replicate'], 'monthly' => 5]);
    $calls = [];
    $replies = ['chat/completions' => [200, json_encode(['choices' => [['message' => ['content' => "```json\n{\"title\":\"מודעה\"}\n```"]]]])]];
    $r = aiRun($acc, '', ['task' => 'complete', 'prompt' => 'תוכנית', 'system' => 'מעצב', 'schema' => ['type' => 'object']]);
    $sent = json_decode($calls[0]['body'], true);
    check('complete עם schema — JSON', $r['json'] === ['title' => 'מודעה'] && $sent['response_format']['type'] === 'json_schema' && $sent['messages'][0]['role'] === 'system');
    $calls = [];
    $replies = ['chat/completions' => [200, json_encode(['choices' => [['message' => ['content' => 'לא JSON']]]])]];
    check('JSON לא תקין — נסיון נוסף אחד ואז provider', code(fn() => aiRun($acc, '', ['task' => 'complete', 'prompt' => 'x', 'schema' => ['type' => 'object']])) === 'provider' && count($calls) === 2);
    $replies = ['chat/completions' => [401, '{"error":{"message":"bad key"}}']];
    check('מפתח שנדחה — provider, בלי חיוב', code(fn() => aiRun($acc, '', ['task' => 'complete', 'prompt' => 'x'])) === 'provider'
        && (float) db()->query('SELECT cost FROM ai_usage ORDER BY id DESC LIMIT 1')->fetchColumn() === 0.0);

    // ─── Anthropic (Claude) — complete בלבד ───
    aiSetKey('anthropic', 'sk-ant-test');
    aiAdminUser(['account' => $acc['id'], 'providers' => ['openai', 'google', 'replicate', 'anthropic'], 'monthly' => 5]);
    check('Claude — רק complete', AI_PROVIDERS['anthropic']['tasks'] === ['complete'] && !in_array('anthropic', aiProvidersFor($acc, 'generate-image'), true));
    $calls = [];
    $replies = ['api.anthropic.com/v1/messages' => [200, json_encode(['stop_reason' => 'end_turn', 'content' => [
        ['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => '{"title":"שלום"}']]])]];
    $r = aiRun($acc, '', ['task' => 'complete', 'provider' => 'anthropic', 'prompt' => 'תאר', 'system' => 'מעצב', 'schema' => ['type' => 'object'],
                          'images' => [$png(8, 8)]]);
    $sent = json_decode($calls[0]['body'], true);
    check('Claude — JSON מהבלוק הטקסטואלי, המודל העדכני, system למעלה', $r['json'] === ['title' => 'שלום'] && $sent['model'] === 'claude-opus-5-5'
        && $sent['system'] === 'מעצב' && $sent['output_config']['format']['type'] === 'json_schema' && $sent['output_config']['effort'] === 'medium');
    check('Claude — תמונה כ-base64 לפני הטקסט', $sent['messages'][0]['content'][0]['type'] === 'image'
        && $sent['messages'][0]['content'][0]['source']['media_type'] === 'image/png' && $sent['messages'][0]['content'][1]['text'] === 'תאר');
    check('Claude — מפתח ב-x-api-key, גרסה, fallbacks', in_array('x-api-key: sk-ant-test', $calls[0]['headers'], true)
        && in_array('anthropic-version: 2026-10-21', $calls[0]['headers'], true) && $sent['fallbacks'] === 'default'
        && in_array('anthropic-beta: server-side-fallback-2026-07-01', $calls[0]['headers'], true));
    $calls = [];
    $tries = 0;
    $replies = ['api.anthropic.com/v1/messages' => function ($body) use (&$tries) {
        return ++$tries === 1 ? [400, '{"type":"error","error":{"type":"invalid_request_error","message":"output_config.format.schema: additionalProperties must be false"}}']
                          : [200, json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => "```json
[1,2]
```"]]])];
    }];
    $r = aiRun($acc, '', ['task' => 'complete', 'provider' => 'anthropic', 'prompt' => 'x', 'schema' => ['type' => 'array']]);
    $second = json_decode($calls[1]['body'], true);
    check('Claude — סכמה שנדחתה: נסיון בלי format, הסכמה בהנחיה', $r['json'] === [1, 2] && !isset($second['output_config']['format'])
        && str_contains($second['system'], '"type":"array"'));
    $replies = ['api.anthropic.com/v1/messages' => [200, json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'שלום']]])]];
    check('Claude — טקסט', aiRun($acc, '', ['task' => 'complete', 'provider' => 'anthropic', 'prompt' => 'x'])['text'] === 'שלום');
    $replies = ['api.anthropic.com/v1/messages' => [200, json_encode(['stop_reason' => 'refusal', 'stop_details' => ['category' => 'cyber'], 'content' => []])]];
    check('Claude — סירוב → provider', code(fn() => aiRun($acc, '', ['task' => 'complete', 'provider' => 'anthropic', 'prompt' => 'x'])) === 'provider');
    $calls = [];
    $replies = ['api.anthropic.com/v1/models' => [200, '{"data":[]}']];
    aiAdminTest('anthropic');
    check('Claude — בדיקת מפתח בלי עלות (models)', str_contains($calls[0]['url'], '/v1/models') && $calls[0]['method'] === 'GET');

    aiSetKey('google', 'g-key');
    aiAdminDefault(['task' => 'generate-image', 'provider' => 'google']);
    $calls = [];
    $replies = [':generateContent' => [200, json_encode(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($png(896, 1152))]]]]]]])]];
    $r = aiRun($acc, '', ['task' => 'generate-image', 'prompt' => 'x', 'size' => '1080x1350']);
    $sent = json_decode($calls[0]['body'], true);
    check('ברירת מחדל Google — יחס 4:5, המפתח בכותרת', $r['provider'] === 'google' && $sent['generationConfig']['imageConfig']['aspectRatio'] === '4:5'
        && in_array('x-goog-api-key: g-key', $calls[0]['headers'], true) && str_contains($calls[0]['url'], 'gemini-2.5-flash-image'));
    check('provider בבקשה גובר על ברירת המחדל', (function () use ($acc, &$replies, $png) {
        $replies['images/generations'] = [200, json_encode(['data' => [['b64_json' => base64_encode($png(1024, 1024))]]])];
        return aiRun($acc, '', ['task' => 'generate-image', 'prompt' => 'x', 'provider' => 'openai'])['provider'] === 'openai';
    })());

    aiSetKey('replicate', 'r8-key');
    $calls = [];
    $replies = [
        'models/851-labs/background-remover/predictions' => [201, json_encode(['status' => 'starting', 'urls' => ['get' => 'https://api.replicate.com/v1/predictions/p1']])],
        'predictions/p1' => [200, json_encode(['status' => 'succeeded', 'output' => 'https://replicate.delivery/out.png'])],
        'replicate.delivery/out.png' => [200, $png(640, 480, true)],
    ];
    $r = aiRun($acc, '', ['task' => 'remove-background', 'image' => $png(320, 240)]);
    check('Replicate — המתנה לתוצאה, הורדה, בגודל הקלט', aiSize($r['png']) === [320, 240] && $r['transparent'] && count($calls) === 3);
    $replies = [
        'models/meta/sam-2/predictions' => [201, json_encode(['status' => 'succeeded', 'output' => ['combined_mask' => 'https://replicate.delivery/c.png',
            'individual_masks' => ['https://replicate.delivery/m1.png', 'https://replicate.delivery/m2.png']]])],
        'm1.png' => [200, (function () { $im = imagecreatetruecolor(100, 100); imagefilledrectangle($im, 10, 20, 39, 59, imagecolorallocate($im, 255, 255, 255)); ob_start(); imagepng($im); return ob_get_clean(); })()],
        'm2.png' => [200, $png(100, 100, false, 0)],
    ];
    $r = aiRun($acc, '', ['task' => 'segment', 'image' => $png(200, 200)]);
    $b = $r['masks'][0]['box'] ?? [];
    check('segment — מסכה לכל עצם, תיבה בקואורדינטות הקלט, ריקה נזרקת', count($r['masks']) === 1 && abs($b[0] - 20) <= 2 && abs($b[1] - 40) <= 2 && abs($b[2] - 60) <= 4);

    aiAdminApp(['client' => 'vector-studio', 'tasks' => ['complete']]);
    $capApp = aiCapabilities($acc, 'vector-studio');
    check('משימות לאפליקציה — רק מה שהמנהל התיר', $capApp['tasks']['complete'] && !$capApp['tasks']['generate-image']);
    check('משימה שלא הותרה לאפליקציה — not-allowed', code(fn() => aiRun($acc, 'vector-studio', ['task' => 'generate-image', 'prompt' => 'x'])) === 'not-allowed');
    aiAdminProvider(['provider' => 'openai', 'models' => ['complete' => 'gpt-5-mini']]);
    check('המנהל מחליף מודל', aiModel('openai', 'complete') === 'gpt-5-mini' && aiModel('openai', 'generate-image') === 'gpt-image-1');
    aiAdminProvider(['provider' => 'openai', 'enabled' => false]);
    check('ספק כבוי — לא מוצע', !in_array('openai', aiProvidersFor($acc, 'complete'), true));
    $replies = ['v1/account' => [401, '{"detail":"Invalid token"}']];
    check('בדיקת מפתח שנדחה — הודעה ברורה', code(fn() => aiAdminTest('replicate')) === 'provider');
    check('תמונה גדולה מ-4096 — too-big', code(fn() => aiRun($acc, '', ['task' => 'remove-background', 'image' => $png(4100, 10)])) === 'too-big');
    check('שימוש החודש בניהול', aiAdminState()['usage']['calls'] >= 8);

    // ── ספריית הגופנים ──
    $alef = file_get_contents(__DIR__ . '/../../document-templates/assets/fonts/Alef-Regular.ttf');
    $heebo = file_get_contents(__DIR__ . '/../../greeting-cards/editor/fonts/heebo-hebrew-100-normal.woff2');
    // WOFF מתוך Alef: רק name ו-OS/2, דחוסים — מספיק כדי לבדוק את הקריאה
    $woff = (function (string $ttf) {
        $u16 = fn($o) => unpack('n', substr($ttf, $o, 2))[1]; $u32 = fn($o) => unpack('N', substr($ttf, $o, 4))[1];
        $tables = [];
        for ($i = 0; $i < $u16(4); $i++) { $e = 12 + $i * 16; $tag = substr($ttf, $e, 4);
            if (in_array($tag, ['name', 'OS/2'], true)) $tables[$tag] = substr($ttf, $u32($e + 8), $u32($e + 12)); }
        $dir = ''; $data = ''; $off = 44 + 20 * count($tables);
        foreach ($tables as $tag => $raw) { $z = gzcompress($raw); $dir .= $tag . pack('NNNN', $off + strlen($data), strlen($z), strlen($raw), 0); $data .= $z; }
        return 'wOFF' . pack('NNnnNnnNNNNN', 0x10000, 44 + strlen($dir) + strlen($data), count($tables), 0, 0, 1, 0, 0, 0, 0, 0, 0) . $dir . $data;
    })($alef);
    check('ttf — משפחה, משקל וסגנון מהטבלאות', fontMeta($alef)['family'] === 'Alef' && fontMeta($alef)['weight'] === 400 && fontMeta($alef)['style'] === 'normal');
    check('woff (דחוס) — אותם פרטים', fontMeta($woff)['format'] === 'woff' && fontMeta($woff)['family'] === 'Alef');
    check('woff2 — בלי טבלאות, רק הפורמט', fontMeta($heebo)['format'] === 'woff2' && fontMeta($heebo)['family'] === null);
    check('שם קובץ — משפחה, משקל וסגנון', fontFromFilename('heebo-hebrew-100-normal.woff2') === ['Heebo', 100, 'normal']
        && fontFromFilename('Rubik-BoldItalic.ttf') === ['Rubik', 700, 'italic'] && fontFromFilename('OpenSans_SemiBold.ttf') === ['Open Sans', 600, 'normal']);

    $acc = getAccount($owner['id']);
    check('בלי אישור רישיון — נדחה', code(fn() => fontAdd($acc, $alef, ['filename' => 'Alef-Regular.ttf'])) === 'license');
    check('לא גופן — נדחה', code(fn() => fontAdd($acc, 'hello world', ['filename' => 'x.ttf', 'license_ack' => true])) === 'bad_format');
    $usedBefore = quotaInfo($acc)['used'];
    $f1 = fontAdd($acc, $alef, ['filename' => 'Alef-Regular.ttf', 'license_ack' => true]);
    check('font_add ttf — פריט עם rev = sha256 ו-url', $f1['family'] === 'Alef' && $f1['rev'] === hash('sha256', $alef) && !$f1['replaced']
        && str_contains($f1['url'], 'font_file') && str_starts_with($f1['id'], 'fnt_'));
    check('הגופן נספר במכסה', quotaInfo($acc)['used'] === $usedBefore + strlen($alef));
    $f2 = fontAdd($acc, $heebo, ['filename' => 'heebo-hebrew-100-normal.woff2', 'license_ack' => true]);
    check('font_add woff2 — לפי שם הקובץ', $f2['family'] === 'Heebo' && $f2['weight'] === 100 && $f2['format'] === 'woff2');
    $f3 = fontAdd($acc, $heebo, ['filename' => 'x.woff2', 'family' => 'Heebo', 'weight' => 700, 'license_ack' => true]);
    check('פרטים מהדפדפן גוברים על שם הקובץ', $f3['family'] === 'Heebo' && $f3['weight'] === 700);
    $f4 = fontAdd($acc, $woff, ['filename' => 'Alef.woff', 'license_ack' => true]);
    check('אותה משפחה, משקל וסגנון — החלפה: אותו id, rev חדש', $f4['id'] === $f1['id'] && $f4['replaced'] && $f4['rev'] === hash('sha256', $woff));
    check('הקובץ הישן נמחק מהדיסק', !is_file(blobPath(hash('sha256', $alef))));
    $list = fontsList($acc);
    check('fonts_list — לפי משפחה', count($list['fonts']) === 3 && array_column($list['families'], 'family') === ['Alef', 'Heebo']
        && count($list['families'][1]['items']) === 2);
    check('font_update — שם משפחה', fontUpdate($acc, ['id' => $f2['id'], 'family' => 'Heebo Thin'])['family'] === 'Heebo Thin');
    check('font_update לכפיל — name_taken', code(fn() => fontUpdate($acc, ['id' => $f2['id'], 'family' => 'Heebo', 'weight' => 700])) === 'name_taken');
    check('משקל לא תקין — נדחה', code(fn() => fontUpdate($acc, ['id' => $f2['id'], 'weight' => 450])) === 'bad_request');
    [$path] = fontFile($acc, $f2['id']);
    check('font_file — הקובץ עצמו', file_get_contents($path) === $heebo);
    check('גופן של חשבון אחר — 404', code(fn() => fontFile(getAccount($dana['id']), $f2['id'])) === 'not_found');
    fontDelete($acc, $f2['id']);
    check('font_delete — הקובץ נשאר כל עוד גופן אחר באותו תוכן', is_file(blobPath(hash('sha256', $heebo))));
    fontDelete($acc, $f3['id']);
    check('ואחרי האחרון — נמחק מהדיסק', !is_file(blobPath(hash('sha256', $heebo))));
    $_SERVER['HTTP_X_CLOUD_TOKEN'] = (function () use ($acc) {
        $req = connectRequest(['client' => 'vector-studio', 'scope' => 'files', 'state' => 'fontstate1', 'return' => 'https://mbe-plus.com/mini_projects/vector-studio/api.php?action=cloud_callback']);
        parse_str((string) parse_url(approveConnect($acc, $req), PHP_URL_QUERY), $q);
        $ex = exchangeCode(activeClient('vector-studio'), ['code' => $q['code']]);
        return issueToken(activeClient('vector-studio'), connectionRow($ex['connection'], 'vector-studio'))['token'];
    })();
    check('אפליקציה בלי הרשאת libs — לא רואה את הגופנים', code(fn() => actor('fonts_list', 'libs', '')) === 'forbidden');
    unset($_SERVER['HTTP_X_CLOUD_TOKEN']);
} catch (Throwable $e) {
    $fails++;
    echo "✗ חריגה: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    rrmdir($tmpDir);
}

echo "\n" . ($n - $fails) . " / $n עברו\n";
exit($fails ? 1 : 0);
