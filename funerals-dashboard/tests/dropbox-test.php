<?php
// בדיקות פרוסה 03 (Dropbox) מול Dropbox מדומה (tests/dropbox-mock.php) — בלי רשת. C1–C11, C13.
// מריצים מ-tests/run.sh. שני שרתי php -S: האפליקציה (עם השער המדומה) והמדומה. DBX_API_BASE רק בתהליכים האלה.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

$app = dirname(__DIR__);
$data = $app . '/data';
foreach (['funerals.sqlite', 'secret.key'] as $f) {
    if (file_exists("$data/$f")) { fwrite(STDERR, "FAIL: data/$f קיים — הבדיקה לא רצה על נתונים אמיתיים\n"); exit(1); }
}
$tmp = sys_get_temp_dir() . '/fd-dbx-' . bin2hex(random_bytes(4));
mkdir("$tmp/up", 0700, true);
$identity = "$tmp/identity.json";
$mockState = "$tmp/mock.json";
file_put_contents($mockState, '');
$mockPort = 18966; $appPort = 18967;
$mockBase = "http://127.0.0.1:$mockPort";
$base = "http://127.0.0.1:$appPort";
$procs = [];
$transcript = '';      // כל התשובות — לחיפוש סודות (C9)

function cleanup(): void {
    global $procs, $tmp, $data;
    foreach ($procs as $p) { $s = proc_get_status($p); if ($s['running']) proc_terminate($p); proc_close($p); }
    // data/ התחילה ריקה (נבדק למעלה) — מנקים כל מה שהבדיקה יצרה בה, חוץ מ-.htaccess
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($data, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { if ($f->getFilename() === '.htaccess' && $f->getPath() === $data) continue; $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f); }
    if (!getenv('KEEP')) exec('rm -rf ' . escapeshellarg($tmp)); else fwrite(STDERR, "tmp: $tmp\n");
}
register_shutdown_function('cleanup');
function fail(string $m): never { fwrite(STDERR, "FAIL: $m\n"); exit(1); }
function ok(bool $c, string $m): void { if (!$c) fail($m); }

function serve(array $cmd, array $env, string $log) {
    global $procs;
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $env + getenv());
    $procs[] = $p;
    return $p;
}
serve(['php', '-S', "127.0.0.1:$mockPort", "$app/tests/dropbox-mock.php"], ['DBX_MOCK_STATE' => $mockState], "$tmp/mock.log");
serve(['php', '-S', "127.0.0.1:$appPort", '-t', $app, '-d', "auto_prepend_file=$app/tests/gate-prepend.php",
    '-d', "upload_tmp_dir=$tmp/up", '-d', 'upload_max_filesize=60M', '-d', 'post_max_size=64M', '-d', 'memory_limit=256M'],
    ['FD_TEST_IDENTITY_FILE' => $identity, 'DBX_API_BASE' => $mockBase], "$tmp/app.log");
for ($i = 0; $i < 50; $i++) { if (@file_get_contents("$base/index.html") !== false && @file_get_contents("$mockBase/_mock/state") !== false) break; usleep(100000); }

/** בקשה → [status, body, headers] */
function http(string $method, string $url, array $opt = []): array {
    global $transcript, $tmp;
    $h = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIEJAR => $opt['jar'] ?? "$tmp/jar", CURLOPT_COOKIEFILE => $opt['jar'] ?? "$tmp/jar",
        CURLOPT_HTTPHEADER => $opt['headers'] ?? [],
        CURLOPT_HEADERFUNCTION => static function ($c, $l) use (&$h) { $p = strpos($l, ':'); if ($p) $h[strtolower(trim(substr($l, 0, $p)))] = trim(substr($l, $p + 1)); return strlen($l); }]);
    if (isset($opt['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['body']);
    $b = (string)curl_exec($ch);
    $st = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    global $base;
    if (str_starts_with($url, $base)) $transcript .= json_encode($h, JSON_UNESCAPED_UNICODE) . "\n" . $b . "\n";   // רק תשובות האפליקציה
    return [$st, $b, $h];
}
function as_(array $id): void { global $identity; file_put_contents($identity, json_encode($id + ['email_verified' => true, 'granted' => true])); }
$DEV = ['sub' => '1', 'name' => 'מפתח', 'email' => 'dev@example.com', 'platform_admin' => true];
$ADMIN = ['sub' => '2', 'name' => 'מנהל', 'email' => 'admin@example.com', 'platform_admin' => false];
$FIELD = ['sub' => '3', 'name' => 'שטח', 'email' => 'field@example.com', 'platform_admin' => false];
$OTHER = ['sub' => '4', 'name' => 'ארגון ב', 'email' => 'b@example.com', 'platform_admin' => false];

$csrf = '';
function login(array $who): void {
    global $csrf, $base, $tmp;
    @unlink("$tmp/jar");
    as_($who);
    [$st, $b] = http('GET', "$base/api.php?action=me");
    ok($st === 200, "me ($st)");
    $csrf = json_decode($b, true)['csrf'];
}
function api(string $method, string $action, array $params = [], mixed $json = null, array $opt = []): array {
    global $base, $csrf;
    $url = "$base/api.php?" . http_build_query(['action' => $action] + $params);
    $headers = ["X-CSRF-Token: $csrf"];
    if ($json !== null) { $headers[] = 'Content-Type: application/json'; $opt['body'] = json_encode($json, JSON_UNESCAPED_UNICODE); }
    $opt['headers'] = array_merge($headers, $opt['headers'] ?? []);
    [$st, $b, $h] = http($method, $url, $opt);
    return [$st, json_decode($b, true), $h, $b];
}
function mock(string $op, array $args = []): array {
    global $mockBase;
    $r = http('POST', "$mockBase/_mock/do", ['body' => json_encode(['op' => $op] + $args, JSON_UNESCAPED_UNICODE), 'headers' => ['Content-Type: application/json']]);
    return json_decode($r[1], true) ?? [];
}
function mstate(): array { global $mockBase; return json_decode(http('GET', "$mockBase/_mock/state")[1], true); }
/** שאילתה אחת, נסגרת מיד (סמן פתוח של הבדיקה היה נועל את המסד לכתיבה של השרת). */
function q(string $sql, array $params = []): array { $st = pdo()->prepare($sql); $st->execute($params); $r = $st->fetchAll(); $st->closeCursor(); return $r; }
function q1(string $sql, array $params = []): mixed { $r = q($sql, $params); return $r ? array_values($r[0])[0] : null; }
function pdo(): PDO { global $data; $p = new PDO("sqlite:$data/funerals.sqlite"); $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); return $p; }
function webhook(string $body, ?string $sig): array {
    global $base;
    return http('POST', "$base/dropbox-webhook.php", ['body' => $body, 'headers' => array_filter(['Content-Type: application/json', $sig === null ? null : "X-Dropbox-Signature: $sig"])]);
}

/* ---------- C1: challenge ---------- */
as_($DEV);
[$st, $b, $h] = http('GET', "$base/dropbox-webhook.php?challenge=abc");
ok($st === 200 && $b === 'abc' && str_starts_with($h['content-type'] ?? '', 'text/plain') && ($h['x-content-type-options'] ?? '') === 'nosniff', "C1 challenge ($st, $b)");
file_put_contents($identity, '');     // webhook ציבורי — גם בלי זהות
[$st, $b] = http('GET', "$base/dropbox-webhook.php?challenge=xyz");
ok($st === 200 && $b === 'xyz', 'C1 בלי זהות');

/* ---------- חברים (פרוסה 01): המפתח הראשון = מנהל ארגון 1; מנהל ושטח בארגון 1, מנהל בארגון ב' ---------- */
login($DEV);
foreach ([['admin@example.com', 'admin'], ['field@example.com', 'field']] as [$e, $r]) {
    [$st] = api('POST', 'invite', [], ['email' => $e, 'role' => $r]);
    ok($st === 200, "הזמנה $e ($st)");
}
[$st, $j] = api('POST', 'org_create', [], ['name' => 'ארגון ב']);
ok($st === 200 && $j['created'] === 2, "ארגון ב ($st)");
api('POST', 'org_switch', [], ['org_id' => 2]);
[$st] = api('POST', 'invite', [], ['email' => 'b@example.com', 'role' => 'admin']);
ok($st === 200, "הזמנה לארגון ב ($st)");
api('POST', 'org_switch', [], ['org_id' => 1]);

/* ---------- מפתחות האפליקציה (מסך המפתח) ---------- */
login($ADMIN);
[$st] = api('POST', 'dropbox_app_set', [], ['app_key' => 'mockkey123', 'app_secret' => 'mocksecret456']);
ok($st === 403, "app_set למי שאינו מפתח ($st)");
[$st] = api('GET', 'dropbox_health');
ok($st === 403, "health למי שאינו מפתח ($st)");
login($DEV);
[$st, $j] = api('GET', 'dropbox_status');
ok($st === 200 && $j['app_configured'] === false, 'status לפני מפתחות');
[$st, $j] = api('POST', 'dropbox_app_set', [], ['app_key' => 'mockkey123', 'app_secret' => 'mocksecret456']);
ok($st === 200 && $j['app_key_set'] && $j['app_secret_set'], "app_set ($st)");
[$st] = api('POST', 'dropbox_app_set', [], ['app_key' => 'x', 'app_secret' => '<script>']);
ok($st === 400, 'app_set ערך לא תקין');
$secrets = array_column(q('SELECT value_enc FROM secrets'), 'value_enc');
ok(count($secrets) === 2 && !str_contains(implode('', $secrets), 'mocksecret456') && !str_contains(implode('', $secrets), 'mockkey123'), 'הסודות מוצפנים במסד');
ok((fileperms("$data/secret.key") & 0777) === 0600, 'secret.key 0600');

/* ---------- תיקים ---------- */
login($ADMIN);
foreach ([['ישראל ישראלי', '2026-10-05'], ['שרה כהן', '2026-10-07'], ['דוד לוי', '2026-10-09']] as [$n, $d]) {
    [$st, $j] = api('POST', 'save', [], ['month' => '2026-10', 'name' => $n, 'date' => $d]);
    ok($st === 200, "save $n ($st)");
    $cases[$n] = $j['record']['id'];
}
// Dropbox מדומה: תיקייה ראשית עם תיקייה קיימת (ניקוד ורווח כפול) ותיקייה שלא תותאם
mock('add_folder', ['path' => '/Funerals']);
mock('add_folder', ['path' => '/Other']);
mock('add_file', ['path' => '/Other/secret.pdf', 'size' => 10]);
$f1 = mock('add_file', ['path' => '/Funerals/2026-10/2026-10-05 יִשְׂרָאֵל  ישראלי/רישיון.pdf', 'size' => 2000])['id'];
mock('add_file', ['path' => '/Funerals/2026-10/2026-10-05 יִשְׂרָאֵל  ישראלי/קבר.jpg', 'size' => 3000]);
mock('add_folder', ['path' => '/Funerals/לא מוכר']);

/* ---------- C11 + חיבור OAuth (PKCE) ---------- */
[$st] = api('GET', 'dropbox_connect');
ok($st === 405, 'connect ב-GET');
[$st, $j] = api('POST', 'dropbox_connect', [], []);
ok($st === 200 && str_contains($j['url'], '/oauth2/authorize?'), "connect ($st)");
parse_str((string)parse_url($j['url'], PHP_URL_QUERY), $q);
ok($q['code_challenge_method'] === 'S256' && $q['token_access_type'] === 'offline' && $q['client_id'] === 'mockkey123' && strlen($q['state']) >= 32, 'פרמטרי authorize');
$code = json_decode(http('GET', "$mockBase/_mock/authorize?" . http_build_query(['code_challenge' => $q['code_challenge'], 'redirect_uri' => $q['redirect_uri']]))[1], true)['code'];
mock('clear_calls');
[$st] = api('GET', 'dropbox_callback', ['state' => 'wrong' . $q['state'], 'code' => $code]);
ok($st === 400, "C11 state שגוי ($st)");
ok(mstate()['calls'] === [], 'C11 state שגוי — בלי קריאה ל-Dropbox');
[$st] = api('GET', 'dropbox_callback', ['state' => $q['state'], 'code' => $code]);
ok($st === 400, "C11 state נשרף אחרי נסיון כושל ($st)");
// חיבור מחדש — עכשיו נכון, ואחר כך חוזר
[$st, $j] = api('POST', 'dropbox_connect', [], []);
parse_str((string)parse_url($j['url'], PHP_URL_QUERY), $q);
$code = json_decode(http('GET', "$mockBase/_mock/authorize?" . http_build_query(['code_challenge' => $q['code_challenge'], 'redirect_uri' => $q['redirect_uri']]))[1], true)['code'];
// state של משתמש אחר: מנהל אחר באותו session לא יכול להשתמש בו (ה-session מתחלף לפי sub)
[$st, , $h] = api('GET', 'dropbox_callback', ['state' => $q['state'], 'code' => $code]);
ok($st === 302 && ($h['location'] ?? '') === 'index.html#dropbox', "callback ($st)");
[$st] = api('GET', 'dropbox_callback', ['state' => $q['state'], 'code' => $code]);
ok($st === 400, "C11 state חוזר ($st)");
[$st, $j] = api('GET', 'dropbox_status');
ok($j['dropbox']['status'] === 'connected' && $j['dropbox']['account_name'] === 'חשבון בדיקה', 'מחובר, עם שם החשבון');
$refreshTokens = array_keys(mstate()['refresh']);
ok(count($refreshTokens) === 1, 'אסימון רענון אחד');

/* ---------- תיקייה ראשית ---------- */
[$st, $j] = api('GET', 'dropbox_browse', ['path' => '']);
ok($st === 200 && in_array('/Funerals', array_column($j['folders'], 'path'), true) && !in_array('/Other/secret.pdf', array_column($j['folders'], 'path'), true), 'browse — רק תיקיות');
[$st] = api('GET', 'dropbox_browse', ['path' => '/Funerals/../Other']);
ok($st === 400, 'browse עם ..');
[$st, $j] = api('POST', 'dropbox_set_root', [], ['path' => '/Funerals']);
ok($st === 200 && $j['dropbox']['root_path'] === '/Funerals' && $j['sync']['ok'], "set_root ($st)");
ok((int)q1("SELECT COUNT(*) FROM files WHERE path_lower LIKE '/other/%'") === 0, 'שום קובץ מחוץ לראשית במסד');

/* ---------- C10: סריקת התאמה מציעה ולא מחברת ---------- */
[$st, $j] = api('GET', 'dropbox_scan');
ok($st === 200 && count($j['proposals']) === 1 && $j['proposals'][0]['case_id'] === $cases['ישראל ישראלי'], 'C10 הצעה אחת (ניקוד/רווחים)');
ok(in_array('/Funerals/לא מוכר', array_column($j['unmatched'], 'path'), true), 'C10 תיקייה לא משויכת');
ok((int)q1('SELECT COUNT(*) FROM case_folders') === 0, 'C10 הסריקה לא חיברה');
[$st, $j2] = api('GET', 'case_files', ['case_id' => $cases['ישראל ישראלי']]);
ok($j2['files'] === [] && $j2['folder'] === null, 'C10 לפני אישור — אין קבצים בתיק');
$folderId = $j['proposals'][0]['folder_id'];
[$st] = api('POST', 'dropbox_link', [], ['case_id' => $cases['ישראל ישראלי'], 'folder_id' => $folderId]);
ok($st === 200, "C10 אישור ($st)");
ok(q1('SELECT dbx_folder_id FROM case_folders') === $folderId, 'C10 dbx_folder_id נשמר');
[$st, $j] = api('GET', 'case_files', ['case_id' => $cases['ישראל ישראלי']]);
ok(count($j['files']) === 2, 'C3 קבצים קיימים בתיק אחרי שיוך');

/* ---------- C2: webhook ---------- */
$newId = mock('add_file', ['path' => '/Funerals/2026-10/2026-10-05 יִשְׂרָאֵל  ישראלי/תעודה.pdf', 'size' => 500])['id'];
$payload = json_encode(['list_folder' => ['accounts' => ['dbid:AAA111']], 'delta' => ['users' => [1]]]);
pdo()->exec("UPDATE dbx_orgs SET last_webhook_at=NULL, sync_pending=0");
[$st] = webhook($payload, hash_hmac('sha256', $payload, 'wrong-secret'));
ok($st === 403, "C2 חתימה שגויה ($st)");
[$st] = webhook($payload, null);
ok($st === 403, "C2 בלי חתימה ($st)");
$o = (q('SELECT last_webhook_at, sync_pending FROM dbx_orgs WHERE org_id=1')[0] ?? null);
ok($o['last_webhook_at'] === null && (int)$o['sync_pending'] === 0, 'C2 חתימה שגויה — הארגון לא סומן');
ok((int)q1('SELECT COUNT(*) FROM files WHERE dbx_id=?', [$newId]) === 0, 'C2 חתימה שגויה — לא סונכרן');
[$st] = webhook($payload, hash_hmac('sha256', $payload, 'mocksecret456'));
ok($st === 200, "C2 חתימה נכונה ($st)");
$row = q('SELECT * FROM files WHERE dbx_id=?', [$newId])[0] ?? null;
ok($row && $row['case_id'] === $cases['ישראל ישראלי'] && $row['added_via'] === 'dropbox', 'C2/C3 הקובץ החדש בתיק');
ok(q1('SELECT last_webhook_at FROM dbx_orgs WHERE org_id=1') !== null, 'webhook אחרון נרשם');
$ev = q("SELECT * FROM events WHERE action='file_added'");
ok(count($ev) >= 1 && $ev[0]['source'] === 'system' && json_decode($ev[0]['diff'], true)['via'][1] === 'dropbox', 'events: file_added, system, via dropbox');
$evCount = count($ev);
// C3: אותו סנכרון פעמיים → שורה אחת
api('POST', 'dropbox_sync', [], []);
webhook($payload, hash_hmac('sha256', $payload, 'mocksecret456'));
ok((int)q1('SELECT COUNT(*) FROM files WHERE dbx_id=?', [$newId]) === 1, 'C3 שורה אחת');
ok((int)q1("SELECT COUNT(*) FROM events WHERE action='file_added'") === $evCount, 'C3 בלי אירוע כפול');
// נעילה לארגון: סנכרון שרץ (חכירה בתוקף) → השני מדלג, לא כותב פעמיים
pdo()->exec('UPDATE dbx_orgs SET lock_token=\'other\', lock_until=' . (time() + 60));
[$st, $j] = api('POST', 'dropbox_sync', [], []);
ok(($j['result']['skipped'] ?? '') === 'locked', 'נעילה — סנכרון שני מדלג');
pdo()->exec('UPDATE dbx_orgs SET lock_token=NULL, lock_until=0');
// reset מ-Dropbox → מתחילים מחדש בלי לשכפל
mock('set', ['values' => ['reset_next' => true]]);
[$st, $j] = api('POST', 'dropbox_sync', [], []);
ok($st === 200 && $j['ok'], 'sync אחרי reset');
ok((int)q1('SELECT COUNT(*) FROM files') === (int)q1('SELECT COUNT(DISTINCT dbx_id) FROM files'), 'reset — בלי כפילות');
// עמודים: has_more
mock('set', ['values' => ['page_size' => 2]]);
pdo()->exec('UPDATE dbx_orgs SET cursor=NULL');
[$st, $j] = api('POST', 'dropbox_sync', [], []);
ok($j['ok'] && $j['result']['pages'] >= 3, 'list_folder/continue עד has_more:false');
mock('set', ['values' => ['page_size' => 1000]]);

/* ---------- C10: שינוי שם בתיקייה ב-Dropbox ---------- */
mock('move', ['from' => '/Funerals/2026-10/2026-10-05 יִשְׂרָאֵל  ישראלי', 'to' => '/Funerals/2026-10/2026-10-05 ישראל ישראלי - סגור']);
api('POST', 'dropbox_sync', [], []);
$cf = (q('SELECT * FROM case_folders')[0] ?? null);
ok($cf['dbx_folder_id'] === $folderId && $cf['dbx_path'] === '/Funerals/2026-10/2026-10-05 ישראל ישראלי - סגור', 'C10 שינוי שם — נשאר מקושר, הנתיב עודכן');
[$st, $j] = api('GET', 'case_files', ['case_id' => $cases['ישראל ישראלי']]);
ok(count($j['files']) === 3, 'C10 הקבצים נשארו בתיק אחרי שינוי השם (' . count($j['files']) . ')');

/* ---------- C4: מחיקה ב-Dropbox ---------- */
mock('delete', ['path' => '/Funerals/2026-10/2026-10-05 ישראל ישראלי - סגור/תעודה.pdf']);
api('POST', 'dropbox_sync', [], []);
[$st, $j] = api('GET', 'case_files', ['case_id' => $cases['ישראל ישראלי']]);
ok(count($j['files']) === 2 && !in_array('תעודה.pdf', array_column($j['files'], 'name'), true), 'C4 נמחק — לא מוצג');
ok((int)q1('SELECT deleted FROM files WHERE dbx_id=?', [$newId]) === 1, 'C4 השורה נשארה במסד, מסומנת נמחקה');

/* ---------- C5: העלאה של 12MB ב-upload session ---------- */
mock('add_folder', ['path' => '/Funerals/2026-10/2026-10-07 שרה כהן']);   // כפילות שם → " (2)"
$big = "$tmp/big.pdf";
file_put_contents($big, "%PDF-1.4\n" . random_bytes(12 * 1024 * 1024));
mock('clear_calls');
[$st, $j, , $raw] = api('POST', 'dropbox_upload', [], null, ['body' => ['case_id' => $cases['שרה כהן'], 'file' => new CURLFile($big, 'application/pdf', 'דוח רפואי.pdf')]]);
ok($st === 200, "C5 העלאה ($st $raw)");
$calls = mstate()['calls'];
ok(in_array('files/upload_session/start', $calls, true) && in_array('files/upload_session/finish', $calls, true) && !in_array('files/upload', $calls, true), 'C5 upload session');
$sess = array_values(mstate()['sessions']);
ok($sess[0]['offset'] === filesize($big) && $sess[0]['chunks'] === [8 * 1024 * 1024, filesize($big) - 8 * 1024 * 1024], 'C5 חלקים של 8MB, גודל מלא');
[$st, $j] = api('GET', 'case_files', ['case_id' => $cases['שרה כהן']]);
ok(count($j['files']) === 1 && $j['files'][0]['added_via'] === 'ui' && $j['files'][0]['added_by'] === 'admin@example.com', 'C5 הקובץ ב-files מיד');
ok($j['folder']['path'] === '/Funerals/2026-10/2026-10-07 שרה כהן (2)', 'תיקייה לתיק: YYYY-MM/YYYY-MM-DD שם, כפילות → (2): ' . $j['folder']['path']);
ok(array_diff(scandir("$tmp/up"), ['.', '..']) === [], 'C5 הקובץ הזמני נמחק');
// הסנכרון שאחרי לא מכפיל
api('POST', 'dropbox_sync', [], []);
ok((int)q1("SELECT COUNT(*) FROM files WHERE name='דוח רפואי.pdf'") === 1, 'C5 הסנכרון לא הכפיל');
// קטן — files/upload; סוג אסור; עובד שטח
$small = "$tmp/a.png";
file_put_contents($small, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
mock('clear_calls');
[$st, $j] = api('POST', 'dropbox_upload', [], null, ['body' => ['case_id' => $cases['שרה כהן'], 'file' => new CURLFile($small, 'image/png', 'קבר.png')]]);
ok($st === 200 && in_array('files/upload', mstate()['calls'], true), "העלאה קטנה ב-files/upload ($st)");
$imgId = $j['file']['id'];
file_put_contents("$tmp/x.svg", '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
[$st] = api('POST', 'dropbox_upload', [], null, ['body' => ['case_id' => $cases['שרה כהן'], 'file' => new CURLFile("$tmp/x.svg", 'image/svg+xml', 'x.svg')]]);
ok($st === 415, "SVG נדחה ($st)");
file_put_contents("$tmp/x.pdf", '<html><script>alert(1)</script></html>');
[$st] = api('POST', 'dropbox_upload', [], null, ['body' => ['case_id' => $cases['שרה כהן'], 'file' => new CURLFile("$tmp/x.pdf", 'application/pdf', 'x.pdf')]]);
ok($st === 415, "HTML בתחפושת PDF נדחה ($st)");
ok(array_diff(scandir("$tmp/up"), ['.', '..']) === [], 'הקבצים הזמניים נמחקו גם בכישלון');

/* ---------- תצוגה מקדימה ---------- */
[$st, , $h, $b] = api('GET', 'file_view', ['id' => $imgId]);
ok($st === 200 && $h['content-type'] === 'image/jpeg' && str_contains($b, 'w1024h768') && str_starts_with($h['cache-control'], 'private'), "תמונה ממוזערת ($st)");
ok(count(glob("$data/cache/thumbs/*.jpg")) >= 1, 'מטמון תמונות ממוזערות');
mock('clear_calls');
api('GET', 'file_view', ['id' => $imgId]);
ok(!in_array('files/get_thumbnail_v2', mstate()['calls'], true), 'מהמטמון — בלי קריאה ל-Dropbox');
$pdfId = (int)q1("SELECT id FROM files WHERE name='רישיון.pdf'");
[$st, , $h, $b] = api('GET', 'file_view', ['id' => $pdfId]);
ok($st === 200 && $h['content-type'] === 'application/pdf' && str_starts_with($b, '%PDF'), "PDF ($st)");

/* ---------- C6: נתיב מחוץ לראשית ---------- */
mock('clear_calls');
foreach (['/Other/secret.pdf', '/Funerals/../Other/secret.pdf', '/funerals/./x', 'id:' . substr($f1, 3) . 'zz', 'id:outside', '../Other/secret.pdf', 'ns:1/Other'] as $bad) {
    [$st] = api('GET', 'file_view', ['id' => $bad]);
    ok($st === 400, "C6 $bad ($st)");
}
$otherId = null;
foreach (mstate()['entries'] as $id => $e) if ($e['path_lower'] === '/other/secret.pdf') $otherId = $id;
[$st] = api('GET', 'file_view', ['id' => $otherId]);
ok($st === 400, "C6 id של קובץ מחוץ לראשית ($st)");
$otherFolder = null;
foreach (mstate()['entries'] as $id => $e) if ($e['path_lower'] === '/other') $otherFolder = $id;
[$st] = api('POST', 'dropbox_link', [], ['case_id' => $cases['דוד לוי'], 'folder_id' => $otherFolder]);
ok($st === 400, "C6 שיוך תיקייה מחוץ לראשית ($st)");
ok(mstate()['calls'] === [], 'C6 שום קריאה ל-Dropbox: ' . json_encode(mstate()['calls']));
// id של קובץ בתוך הראשית — מותר
[$st] = api('GET', 'file_view', ['id' => $f1]);
ok($st === 200, "id של קובץ בתוך הראשית ($st)");

/* ---------- C7: ארגון אחר ---------- */
login($OTHER);
[$st] = api('GET', 'file_view', ['id' => $imgId]);
ok($st === 404, "C7 file_view מארגון ב' ($st)");
[$st] = api('GET', 'case_files', ['case_id' => $cases['שרה כהן']]);
ok($st === 404, "C7 case_files מארגון ב' ($st)");
[$st] = api('POST', 'dropbox_upload', [], null, ['body' => ['case_id' => $cases['שרה כהן'], 'file' => new CURLFile($small, 'image/png', 'a.png')]]);
ok($st === 404, "C7 העלאה לתיק של ארגון א' ($st)");
login($FIELD);
[$st] = api('POST', 'dropbox_upload', [], null, ['body' => ['case_id' => $cases['שרה כהן'], 'file' => new CURLFile($small, 'image/png', 'a.png')]]);
ok($st === 403, "שטח — העלאה ב-04 ($st)");
[$st] = api('POST', 'dropbox_connect', [], []);
ok($st === 403, "שטח — חיבור ($st)");
[$st] = api('GET', 'file_view', ['id' => $imgId]);
ok($st === 200, "שטח — תצוגה ($st)");

/* ---------- C13: bin/dropbox-sync.php ---------- */
login($ADMIN);
mock('add_file', ['path' => '/Funerals/2026-10/2026-10-07 שרה כהן (2)/מהמחשב.pdf', 'size' => 50]);
$out = shell_exec('DBX_API_BASE=' . escapeshellarg($mockBase) . ' php ' . escapeshellarg("$app/bin/dropbox-sync.php") . ' 2>&1');
ok(str_contains((string)$out, 'org=1 {"ok":true'), 'C13 CLI: ' . $out);
ok((int)q1("SELECT COUNT(*) FROM files WHERE name='מהמחשב.pdf' AND case_id IS NOT NULL") === 1, 'C13 הקובץ נכנס לתיק');
$transcript .= (string)$out;
[$st] = http('GET', "$base/bin/dropbox-sync.php");
ok($st === 404, "C13 דרך HTTP ($st)");
foreach (['dropbox/lib.php', 'dropbox/actions.php', 'dropbox/compat.php'] as $f) {
    [$st] = http('GET', "$base/$f");
    ok($st === 404, "$f נגיש ישירות ($st)");
}

/* ---------- C8: invalid_grant ---------- */
mock('set', ['values' => ['fail_refresh' => true]]);
mock('expire_access');
pdo()->exec("UPDATE dbx_orgs SET last_sync_at='2000-01-01T00:00:00+00:00'");
[$st, $j] = api('GET', 'dropbox_status');      // פתיחת מסך עם סנכרון ישן → מנסה לרענן
ok($j['dropbox']['status'] === 'reauth' && $j['dropbox']['last_error'] === 'invalid_grant' && $j['can_admin'], 'C8 צריך חיבור מחדש, והמנהל רואה');
[$st, $j] = api('POST', 'dropbox_folder_create', [], ['case_id' => $cases['דוד לוי']]);
ok($st === 409 && str_contains($j['error'], 'לחבר מחדש'), 'C8 פעולה לא נופלת בשקט');
mock('set', ['values' => ['fail_refresh' => false]]);

/* ---------- health ---------- */
login($DEV);
[$st, $j] = api('GET', 'dropbox_health');
ok($st === 200 && $j['app_key_set'] && $j['orgs'][0]['status'] === 'reauth' && str_ends_with($j['webhook_uri'], '/dropbox-webhook.php'), "health ($st)");

/* ---------- ניתוק ---------- */
login($ADMIN);
pdo()->exec("UPDATE dbx_orgs SET status='connected'");
[$st, $j] = api('POST', 'dropbox_disconnect', [], []);
ok($st === 200 && $j['dropbox']['status'] === 'disconnected', "ניתוק ($st)");
ok((int)q1("SELECT COUNT(*) FROM secrets WHERE name='dropbox_refresh'") === 0, 'ניתוק — האסימון נמחק');
ok(mstate()['revoked'] === 1, 'ניתוק — token/revoke');
[$st, $j] = api('GET', 'case_files', ['case_id' => $cases['ישראל ישראלי']]);
ok($j['connected'] === false && count($j['files']) === 2, 'ניתוק — רשימת הקבצים נשארת, מסומנת מנותק');
ok(count(array_filter(mstate()['entries'], static fn($e) => $e['tag'] === 'file')) === 6, 'ניתוק — שום קובץ לא נמחק ב-Dropbox');

/* ---------- C9: סודות ---------- */
$db = pdo();
$hay = $transcript . file_get_contents("$tmp/app.log");
foreach (['events', 'dbx_orgs', 'files', 'case_folders'] as $t) $hay .= json_encode($db->query("SELECT * FROM $t")->fetchAll(), JSON_UNESCAPED_UNICODE);
if ($db->query("SELECT name FROM sqlite_master WHERE name='req_log'")->fetchColumn()) $hay .= json_encode($db->query('SELECT * FROM req_log')->fetchAll());
foreach (array_merge($refreshTokens, ['mocksecret456']) as $secret) ok(!str_contains($hay, $secret), 'C9 סוד דלף: ' . substr($secret, 0, 4));
ok(!preg_match('/\bat_[0-9a-f]{24}\b/', $hay), 'C9 אסימון גישה דלף');

$log = (string)file_get_contents("$tmp/app.log");
ok(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse)/', $log), 'שגיאות PHP בשרת: ' . (preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse)[^\n]*/', $log, $mm) ? $mm[0] : ''));

echo "PASS: Dropbox (C1–C11, C13): webhook challenge וחתימה, OAuth PKCE ו-state חד-פעמי, תיקייה ראשית והגבלה אליה, סריקת התאמה ואישור, סנכרון ב-cursor (כפילות, reset, עמודים, שינוי שם, מחיקה), העלאה ב-upload session ומחיקת הזמני, תצוגה מקדימה ומטמון, הפרדת ארגונים, invalid_grant, CLI, ניתוק, ושום סוד בתשובות/ביומן/באירועים\n";
