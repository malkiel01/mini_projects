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
} catch (Throwable $e) {
    $fails++;
    echo "✗ חריגה: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    rrmdir($tmpDir);
}

echo "\n" . ($n - $fails) . " / $n עברו\n";
exit($fails ? 1 : 0);
