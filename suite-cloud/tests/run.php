<?php
/**
 * בדיקות השרת: php suite-cloud/tests/run.php
 * רצות על תיקיית נתונים זמנית; הזהות של השער מדומה ב-$_SERVER['PLATFORM_USER'].
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmpDir = sys_get_temp_dir() . '/suite-cloud-test-' . getmypid();
define('DATA_DIR', $tmpDir);
require_once __DIR__ . '/../lib/drive.php';

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
} catch (Throwable $e) {
    $fails++;
    echo "✗ חריגה: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    rrmdir($tmpDir);
}

echo "\n" . ($n - $fails) . " / $n עברו\n";
exit($fails ? 1 : 0);
