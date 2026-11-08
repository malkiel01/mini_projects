<?php
/**
 * שלב 2 — שיתוף לקריאה, חשבון שירות ואסימון אישי. נטען מתוך tests/run.php (אותו מסד זמני, אותם $owner ו-check()).
 * הבדיקות לפי docs/spec-2-share-token.md סעיף 8 (ד1–ד7, ת1–ת6). מה שתלוי ב-HTTP (קודים, כותרות, Range, 429) רץ מול
 * השרת המובנה של PHP (php -S) על אותה תיקיית נתונים, עם נתב שמדמה את הכתובת (X-Test-IP) ואת זהות השער (X-Test-User).
 */

declare(strict_types=1);

/** @var array $owner */
global $tmpDir;

// ── הכנה: A הבעלים, B אדם אחר, S חשבון שירות; העץ A:/ראשי/שיטות/ספר.pdf + A:/ראשי/פרטי.txt ──
as_platform(['sub' => 's2a', 'name' => 'אבי הבעלים', 'email' => 'avi@example.com']);
$A = requireAccount();
as_platform(['sub' => 's2b', 'name' => 'בתיה', 'email' => 'batya@example.com']);
$B = requireAccount();
adminAccount($owner, ['account' => $A['id'], 'quota' => 200 * 1024 * 1024]);
adminAccount($owner, ['account' => $B['id'], 'quota' => 1024 * 1024]);
$A = getAccount($A['id']); $B = getAccount($B['id']);

$main = makeFolder($A, ['name' => 'ראשי']);
$methods = makeFolder($A, ['name' => 'שיטות', 'parent' => $main['id']]);
$priv = writeFile($A, ['name' => 'פרטי.txt', 'parent' => $main['id'], 'content_b64' => b64('סוד של אבי')]);

// ספר של 40MB — בהעלאה בחלקים, כמו שהדפדפן עושה
$big = '';
for ($i = 0; strlen($big) < 40 * 1024 * 1024; $i++) $big .= hash('sha256', (string) $i, true) . str_repeat(chr($i % 251), 224);
$big = substr($big, 0, 40 * 1024 * 1024);
$up = uploadStart($A, ['name' => 'ספר.pdf', 'parent' => $methods['id'], 'size' => strlen($big), 'sha256' => hash('sha256', $big), 'mime' => 'application/pdf']);
foreach (str_split($big, CHUNK) as $k => $part) uploadChunk($A, $up['upload'], $k, body($part));
$book = uploadFinish($A, $up['upload']);
check('הכנה — ספר של 40MB הועלה', $book['size'] === 40 * 1024 * 1024);

check('service_create — רק לבעלים קיים (מייל)', code(fn() => serviceCreate($owner, ['name' => 'X', 'owner' => 'nobody@example.com'])) === 'bad_request');
$svcs = serviceCreate($owner, ['name' => 'Claude — אסטרטגיות למסחר', 'owner' => 'avi@example.com'])['services'];
$S = getAccount($svcs[0]['id']);
check('חשבון שירות — kind=service, מכסה 0, בלי platform_sub, מייל .invalid', $S['kind'] === 'service' && (int) $S['quota'] === 0
    && $S['platform_sub'] === null && str_ends_with($S['email'], '@service.invalid') && $S['owner'] === $A['id']);
check('חשבון שירות — לא ברשימת החשבונות של הניהול', !in_array($S['id'], array_column(adminState()['accounts'], 'id'), true));
check('token_create לאדם — נדחה (רק לחשבון שירות בשלב הזה)', code(fn() => tokenCreate($owner, ['account' => $A['id'], 'name' => 'x'])) === 'not_found');
check('token_create — תוקף לא תקין נדחה', code(fn() => tokenCreate($owner, ['account' => $S['id'], 'name' => 'x', 'expires_days' => 0])) === 'bad_request');

$tok = tokenCreate($owner, ['account' => $S['id'], 'name' => 'Claude', 'expires_days' => null]);
$PAT = $tok['token'];
$secret = substr($PAT, strlen('mbe_pat_') + 9);
check('הצורה: mbe_pat_<8>_<43>', (bool) preg_match('/^mbe_pat_[a-z0-9]{8}_[A-Za-z0-9_-]{43}$/', $PAT) && strlen($secret) === 43);
check('חשבון שירות — מותר ללא תפוגה', $tok['expires_at'] === null);
$tok90 = tokenCreate($owner, ['account' => $S['id'], 'name' => 'ברירת מחדל']);
check('ברירת המחדל — 90 יום', abs(strtotime($tok90['expires_at']) - (time() + 90 * 86400)) < 60);

check('ת4: B לא משתף לחשבון השירות של A — 403', code(fn() => shareCreate($B, ['node' => writeFile($B, ['name' => 'b.txt', 'content_b64' => b64('b')])['id'], 'grantee' => $S['id']])) === 'forbidden');
check('share_create — מייל שלא קיים: אותה תשובה כמו כל סירוב', code(fn() => shareCreate($A, ['node' => $methods['id'], 'grantee' => 'ghost@example.com'])) === 'forbidden');
check('share_create — לא לעצמי', code(fn() => shareCreate($A, ['node' => $methods['id'], 'grantee' => 'avi@example.com'])) === 'forbidden');
check('share_create — רק על צומת שלי', code(fn() => shareCreate($B, ['node' => $methods['id'], 'grantee' => 'avi@example.com'])) === 'not_found');
$share = shareCreate($A, ['node' => $methods['id'], 'grantee' => $S['id']])['share'];
check('share_create — כפול מחזיר את הקיים', shareCreate($A, ['node' => $methods['id'], 'grantee' => $S['id']])['share']['id'] === $share['id']);
check('share_list + shared_nodes (לסימון 👥)', count(shareList($A, $methods['id'])['items']) === 1 && sharedNodeIds($A) === [$methods['id']]);
check('ת4: אסימון אפליקציה של B לא רואה את שיטות', code(fn() => liveNode($B, $methods['id'])) === 'not_found');

// ── השרת ──
$port = 18000 + getmypid() % 2000;
$router = "$tmpDir/router.php";
file_put_contents($router, '<?php
define("DATA_DIR", ' . var_export($tmpDir, true) . ');
define("CLOUD_ORIGIN", "https://mbe-plus.com");
define("CLOUD_ISSUER", "https://mbe-plus.com/mini_projects/mbe-cloud/");
if (isset($_SERVER["HTTP_X_TEST_IP"])) $_SERVER["REMOTE_ADDR"] = $_SERVER["HTTP_X_TEST_IP"];
if (isset($_SERVER["HTTP_X_TEST_USER"])) $_SERVER["PLATFORM_USER"] = $_SERVER["HTTP_X_TEST_USER"];
require ' . var_export(realpath(__DIR__ . '/../api.php'), true) . ';
');
$errLog = "$tmpDir/server.log";
$proc = proc_open([PHP_BINARY, '-d', 'log_errors=1', '-d', 'error_log=' . $errLog, '-S', "127.0.0.1:$port", $router],
                  [0 => ['pipe', 'r'], 1 => ['file', "$tmpDir/stdout.log", 'a'], 2 => ['file', "$tmpDir/stderr.log", 'a']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);

$bodies = [];   // כל גוף תשובה — לבדיקה שהסוד לא דולף (ד7)
/** [status, headers (lowercase), body] */
$http = function (string $method, string $query, array $h = [], ?array $json = null) use ($port, &$bodies): array {
    $c = curl_init("http://127.0.0.1:$port/api.php?$query");
    $hdr = [];
    foreach ($h as $k => $v) $hdr[] = "$k: $v";
    if ($json !== null) $hdr[] = 'Content-Type: application/json';
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $hdr, CURLOPT_TIMEOUT => 60]);
    if ($json !== null) curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($json));
    $raw = (string) curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $hs = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    $headers = [];
    foreach (explode("\r\n", substr($raw, 0, $hs)) as $line) if (str_contains($line, ':')) {
        [$k, $v] = explode(':', $line, 2);
        $headers[strtolower(trim($k))] = trim($v);
    }
    $body = substr($raw, $hs);
    $bodies[] = $body;
    return [$status, $headers, $body];
};
$ip = fn(string $addr) => ['X-Test-IP' => $addr];
$pat = fn(string $t, string $addr = '10.0.0.1') => ['Authorization' => "Bearer $t", 'X-Test-IP' => $addr];
$j = fn(array $r) => json_decode($r[2], true) ?: [];

try {
    // ד1 + ד2
    $r = $http('GET', 'action=shared_roots', $pat($PAT));
    check('ד1: mbe-cloud/api.php עם Bearer — 200 ישירות, בלי 3xx', $r[0] === 200);
    check('ד2: shared_roots עם PAT ובלי עוגייה — 200', $r[0] === 200 && isset($j($r)['items']));
    $r = $http('GET', 'action=list', $ip('10.0.0.1'));
    check('ד2: list בלי Bearer ובלי עוגייה — 401 JSON unauthorized',
        $r[0] === 401 && str_starts_with($r[1]['content-type'] ?? '', 'application/json') && ($j($r)['code'] ?? '') === 'unauthorized');

    // ד3
    $roots = $j($http('GET', 'action=shared_roots', $pat($PAT)))['items'];
    check('ד3: shared_roots — רק שיטות, עם שם הבעלים', count($roots) === 1 && $roots[0]['id'] === $methods['id']
        && $roots[0]['share']['owner_name'] === 'אבי הבעלים');
    $l = $j($http('GET', 'action=list&folder=' . $methods['id'], $pat($PAT)));
    check('ד3: list של שיטות — ספר.pdf', array_column($l['items'] ?? [], 'name') === ['ספר.pdf']);
    check('ד3: ב-path אין ראשי — רק משורש השיתוף', array_column($l['path'] ?? [], 'name') === ['שיטות']);
    $st = $j($http('GET', 'action=stat&id=' . $methods['id'], $pat($PAT)));
    check('ד3: parent של שורש השיתוף — null', array_key_exists('parent', $st) && $st['parent'] === null);
    check('ד3: updated_by בלי שם הבעלים', ($st['updated_by']['name'] ?? 'x') === '');
    check('list בלי folder — 400', $http('GET', 'action=list', $pat($PAT))[0] === 400);

    // ד4
    $st = $j($http('GET', 'action=stat&id=' . $book['id'], $pat($PAT)));
    check('ד4: stat — size, sha256, updated_at', ($st['size'] ?? 0) === strlen($big) && ($st['sha256'] ?? '') === hash('sha256', $big) && !empty($st['updated_at']));
    $r = $http('GET', 'action=read&id=' . $book['id'], $pat($PAT));
    check('ד4: read — Content-Length נכון והתוכן זהה', $r[0] === 200 && (int) ($r[1]['content-length'] ?? 0) === strlen($big) && $r[2] === $big);
    array_pop($bodies);   // 40MB — לא שומרים לבדיקת ד7 (הבינארי לא יכול להכיל את הסוד)
    $r = $http('GET', 'action=read&id=' . $book['id'], $pat($PAT) + ['Range' => 'bytes=0-1023']);
    check('ד4: Range 0-1023 — 206, Content-Range ו-1024 בתים', $r[0] === 206 && ($r[1]['content-range'] ?? '') === 'bytes 0-1023/' . strlen($big)
        && $r[2] === substr($big, 0, 1024));
    $r = $http('GET', 'action=read&id=' . $book['id'], $pat($PAT) + ['Range' => 'bytes=-100']);
    check('ד4: Range -100 — 100 הבתים האחרונים', $r[0] === 206 && $r[2] === substr($big, -100));
    $r = $http('GET', 'action=read&id=' . $book['id'], $pat($PAT) + ['Range' => 'bytes=' . (strlen($big) + 10) . '-']);
    check('ד4: טווח מעבר לסוף — 416', $r[0] === 416);

    // ד5 — תמיד 404, ותמיד אותו גוף
    $nf = [];
    $nf[] = $http('GET', 'action=stat&id=' . $priv['id'], $pat($PAT));
    $nf[] = $http('GET', 'action=stat&id=n_doesnotexist', $pat($PAT));
    $nf[] = $http('GET', 'action=read&id=' . $priv['id'], $pat($PAT));
    trashNode($A, ['id' => $book['id']]);
    $nf[] = $http('GET', 'action=stat&id=' . $book['id'], $pat($PAT));
    restoreNode($A, ['id' => $book['id']]);
    trashNode($A, ['id' => $main['id']]);   // סל מעל שורש השיתוף — מסתיר הכול
    $nf[] = $http('GET', 'action=stat&id=' . $book['id'], $pat($PAT));
    check('ד5: תיקייה בסל מעל השיתוף — שותף איתי ריק', $j($http('GET', 'action=shared_roots', $pat($PAT)))['items'] === []);
    restoreNode($A, ['id' => $main['id']]);
    moveNode($A, ['id' => $book['id'], 'parent' => $main['id']]);
    $nf[] = $http('GET', 'action=stat&id=' . $book['id'], $pat($PAT));
    moveNode($A, ['id' => $book['id'], 'parent' => $methods['id']]);
    check('ד5: חזר לתיקייה המשותפת — נגיש שוב', $http('GET', 'action=stat&id=' . $book['id'], $pat($PAT))[0] === 200);
    shareRevoke($A, $share['id']);
    $nf[] = $http('GET', 'action=stat&id=' . $book['id'], $pat($PAT));
    $nf[] = $http('GET', 'action=list&folder=' . $methods['id'], $pat($PAT));
    adminAccount($owner, ['account' => $A['id'], 'blocked' => true]);
    $share = shareCreate(getAccount($A['id']), ['node' => $methods['id'], 'grantee' => $S['id']])['share'];
    $nf[] = $http('GET', 'action=stat&id=' . $book['id'], $pat($PAT));
    adminAccount($owner, ['account' => $A['id'], 'blocked' => false]);
    check('ד5: לא שותף / לא קיים / בסל / סל מעל / הועבר החוצה / בוטל / בעלים חסום — כולם 404',
        array_unique(array_column($nf, 0)) === [404]);
    check('ד5: הגוף זהה בכל המקרים', count(array_unique(array_column($nf, 2))) === 1);
    check('ד5: ביטול חל מיד — ואחרי שיתוף מחדש נגיש', $http('GET', 'action=stat&id=' . $book['id'], $pat($PAT))[0] === 200);

    // ד6 — בלי כתיבה
    $count = fn() => implode(',', array_map(fn($t) => db()->query("SELECT COUNT(*) || '/' || COALESCE(MAX(rowid),0) FROM $t")->fetchColumn(),
        ['nodes', 'shares', 'tokens', 'accounts', 'uploads', 'lib_items']));
    $before = $count();
    $deny = [];
    foreach ([['write', ['name' => 'x.txt', 'parent' => $methods['id'], 'content_b64' => b64('x')]], ['mkdir', ['name' => 'x', 'parent' => $methods['id']]],
              ['upload_start', ['name' => 'x', 'size' => 1, 'sha256' => str_repeat('0', 64)]], ['rename', ['id' => $book['id'], 'name' => 'y.pdf']],
              ['move', ['id' => $book['id'], 'parent' => null]], ['trash', ['id' => $book['id']]],
              ['share_create', ['node' => $methods['id'], 'grantee' => 'batya@example.com']], ['token_create', ['account' => $S['id'], 'name' => 'x']],
              ['ai_run', ['task' => 'complete', 'prompt' => 'x']], ['font_add', ['content_b64' => b64('x')]], ['quota', []], ['me', []]] as [$act, $body]) {
        $r = $http('POST', "action=$act", $pat($PAT), $body);
        $deny[$act] = $r[0] === 403 && ($j($r)['code'] ?? '') === 'forbidden';
    }
    check('ד6: write/mkdir/upload_start/rename/move/trash/share_create/token_create/ai_run/font_add/quota/me — כולן 403 forbidden',
        !in_array(false, $deny, true));
    if (in_array(false, $deny, true)) echo '   ' . implode(', ', array_keys(array_filter($deny, fn($v) => !$v))) . "\n";
    check('ד6: שום דבר במסד לא השתנה', $count() === $before);

    // ת1 + ת3
    $bad = substr($PAT, 0, -1) . (str_ends_with($PAT, 'A') ? 'B' : 'A');
    $rBad = $http('GET', 'action=shared_roots', $pat($bad, '10.0.0.2'));
    $tExp = tokenCreate($owner, ['account' => $S['id'], 'name' => 'פג']);
    db()->prepare('UPDATE tokens SET expires_at = ? WHERE id = ?')->execute([nowIso(time() - 5), $tExp['id']]);
    $rExp = $http('GET', 'action=shared_roots', $pat($tExp['token'], '10.0.0.2'));
    $tRev = tokenCreate($owner, ['account' => $S['id'], 'name' => 'בוטל']);
    tokenRevoke($tRev['id']);
    $rRev = $http('GET', 'action=shared_roots', $pat($tRev['token'], '10.0.0.2'));
    $rForm = $http('GET', 'action=shared_roots', $pat('mbe_pat_short', '10.0.0.2'));
    $rGhost = $http('GET', 'action=shared_roots', $pat('mbe_pat_zzzzzzzz_' . str_repeat('A', 43), '10.0.0.2'));
    check('ת1: פג / בוטל / שגוי / צורה / id לא קיים — 401', [$rBad[0], $rExp[0], $rRev[0], $rForm[0], $rGhost[0]] === [401, 401, 401, 401, 401]);
    check('ת1: אותה הודעה בכולם', count(array_unique([$rBad[2], $rExp[2], $rRev[2], $rForm[2], $rGhost[2]])) === 1);
    check('ת3: אסימון בכתובת — 400', $http('GET', 'action=shared_roots&token=' . urlencode($PAT), $ip('10.0.0.2'))[0] === 400
        && $http('GET', 'action=list&access_token=x', $ip('10.0.0.2'))[0] === 400);

    // ת2 — 429
    for ($i = 0; $i < 10; $i++) $http('GET', 'action=shared_roots', $pat($bad, '10.0.0.3'));
    $r = $http('GET', 'action=shared_roots', $pat($PAT, '10.0.0.3'));
    check('ת2: אחרי 10 כישלונות — גם אסימון תקף מקבל 429, עם Retry-After ו-code rate_limited',
        $r[0] === 429 && (int) ($r[1]['retry-after'] ?? 0) > 800 && ($j($r)['code'] ?? '') === 'rate_limited');
    check('ת2: כתובת אחרת לא מושפעת', $http('GET', 'action=shared_roots', $pat($PAT, '10.0.0.4'))[0] === 200);
    check('ת2: החסימה רק על PAT — בלי אסימון מאותה כתובת: 401 רגיל ולא 429', $http('GET', 'action=list', $ip('10.0.0.3'))[0] === 401);
    $l429 = db()->prepare("SELECT COUNT(*) FROM token_log WHERE token = ? AND status = 429");
    $l429->execute([$tok['id']]);
    $n429 = (int) $l429->fetchColumn();
    $l429->closeCursor();   // סמן קריאה פתוח מחזיק snapshot ישן ב-WAL, והכתיבה הבאה הייתה נכשלת מול כתיבות השרת
    check('ת2: שורת ה-429 נכנסת ליומן של בעל האסימון', $n429 === 1);

    // ת6 — אסימון אפליקציה של 15 דקות לא נשבר
    $aSub = (string) getAccount($A['id'])['platform_sub'];
    $req = connectRequest($GLOBALS['idq'](['client' => 'vector-studio', 'scope' => 'files', 'state' => 'st2state1', 'return' => 'https://mbe-plus.com/mini_projects/vector-studio/api.php?action=cloud_callback'], $aSub));
    parse_str((string) parse_url(approveConnect(getAccount($A['id']), $req), PHP_URL_QUERY), $q);
    $ex = exchangeCode(activeClient('vector-studio'), ['code' => $q['code'], 'sub' => $aSub]);
    $appTok = issueToken(activeClient('vector-studio'), connectionRow($ex['connection'], 'vector-studio'))['token'];
    $r = $http('GET', 'action=list&folder=' . $main['id'], ['Authorization' => "Bearer $appTok", 'X-Test-IP' => '10.0.0.3']);
    check('ת6: אסימון אפליקציה (בלי mbe_pat_) — list עובד כמו קודם, גם מכתובת חסומה ל-PAT',
        $r[0] === 200 && in_array('פרטי.txt', array_column($j($r)['items'] ?? [], 'name'), true));
    $r = $http('POST', 'action=mkdir', ['Authorization' => "Bearer $appTok"], ['name' => 'מהאפליקציה', 'parent' => $main['id']]);
    check('ת6: ואפליקציה עם files עדיין כותבת', $r[0] === 200);

    // ⬇️ מדריך ה-API (לשונית החיבורים): רק למי שנכנס דרך השער; עם PAT — 403; בלי הקובץ — 404
    check('api_guide בלי כניסה — 401', $http('GET', 'action=api_guide', $ip('10.0.0.6'))[0] === 401);
    check('api_guide עם PAT — 403', $http('GET', 'action=api_guide', $pat($PAT, '10.0.0.6'))[0] === 403);
    $g = $http('GET', 'action=api_guide', ['X-Test-User' => json_encode(['sub' => 's2a', 'name' => 'אבי הבעלים', 'email' => 'avi@example.com']), 'X-Test-IP' => '10.0.0.6']);
    $guide = __DIR__ . '/../docs/api-guide.md';
    check('api_guide מחובר — הקובץ כהורדה, או 404 כשעוד לא פורסם', is_file($guide)
        ? $g[0] === 200 && str_contains($g[1]['content-disposition'] ?? '', 'attachment') && $g[2] === file_get_contents($guide)
        : $g[0] === 404 && ($j($g)['code'] ?? '') === 'not_found');

    // היומן
    $log = tokenLog($tok['id'])['items'];
    check('token_log — פעולה, צומת, כתובת וקוד; read עם 206 ועם הצומת',
        (bool) array_filter($log, fn($r) => $r['action'] === 'read' && $r['status'] === 206 && $r['node'] === $book['id'] && $r['node_name'] === 'ספר.pdf'));
    check('token_log — גם 403 ו-404 נרשמים', in_array(403, array_column($log, 'status'), true) && in_array(404, array_column($log, 'status'), true));

    // ד7 — הסוד לא נרשם בשום מקום
    usleep(200000);
    $dbBytes = '';
    foreach (glob("$tmpDir/cloud.sqlite*") as $f) $dbBytes .= (string) file_get_contents($f);
    $logs = (string) @file_get_contents($errLog) . (string) @file_get_contents("$tmpDir/stderr.log") . (string) @file_get_contents("$tmpDir/stdout.log");
    check('ד7: הסוד לא בקובץ המסד (כולל WAL)', $dbBytes !== '' && !str_contains($dbBytes, $secret));
    check('ד7: הסוד לא ב-token_log', !db()->query('SELECT 1 FROM token_log WHERE action LIKE ' . db()->quote("%$secret%") . ' OR node LIKE ' . db()->quote("%$secret%"))->fetchColumn());
    check('ד7: הסוד לא ביומני השרת', !str_contains($logs, $secret));
    check('ד7: הסוד לא באף גוף תשובה', !array_filter($bodies, fn($b) => str_contains($b, $secret)));
    $tl = tokenList($S['id'])['items'];
    check('ד7: token_list — בלי hash ובלי הסוד', !array_filter($tl, fn($t) => isset($t['hash'])) && !str_contains(json_encode($tl), $secret)
        && str_contains(json_encode(serviceList(), JSON_UNESCAPED_UNICODE), 'mbe_pat_' . $tok['id'] . '_…') && !str_contains(json_encode(serviceList()), $secret));
    check('ד7: במסד נשמר sha256 של הסוד', db()->query('SELECT hash FROM tokens WHERE id = ' . db()->quote($tok['id']))->fetchColumn() === hash('sha256', $secret));

    // ד7 — חריגה בתוך patActor עם אסימון ידוע: ה-trace לא מכיל את הסוד, גם כשהשרת שומר ארגומנטים ארוכים
    ini_set('zend.exception_ignore_args', '0');
    ini_set('zend.exception_string_param_max_len', '1000000');
    db()->exec('ALTER TABLE token_log RENAME TO token_log_off');
    $trace = '';
    try { patActor('shared_roots', $PAT); } catch (Throwable $e) { $trace = (string) $e; }
    db()->exec('ALTER TABLE token_log_off RENAME TO token_log');
    check('ד7: חריגה בתוך patActor — ה-trace לא מכיל את הסוד (#[\SensitiveParameter])', $trace !== '' && str_contains($trace, 'patActor')
        && !str_contains($trace, $secret) && str_contains($trace, 'SensitiveParameterValue'));
    ini_restore('zend.exception_ignore_args');
    ini_restore('zend.exception_string_param_max_len');
    check('ד7: api.php מכבה ארגומנטים ב-trace', str_contains((string) file_get_contents(__DIR__ . '/../api.php'), "ini_set('zend.exception_ignore_args', '1')"));

    // shared_roots: הורה בסל מעל שורש השיתוף — השורש לא ברשימה; שורש שבסל בעצמו — לא; ובחזרה — כן
    trashNode(getAccount($A['id']), ['id' => $main['id']]);
    check('shared_roots — הורה בסל: השורש לא ברשימה', sharedRoots($S)['items'] === []);
    restoreNode(getAccount($A['id']), ['id' => $main['id']]);
    trashNode(getAccount($A['id']), ['id' => $methods['id']]);
    check('shared_roots — השורש עצמו בסל: לא ברשימה', sharedRoots($S)['items'] === []);
    restoreNode(getAccount($A['id']), ['id' => $methods['id']]);
    check('shared_roots — אחרי שחזור: חוזר לרשימה', array_column(sharedRoots($S)['items'], 'id') === [$methods['id']]);

    // ת5 — מחיקת חשבון שירות
    serviceDelete($S['id']);
    check('ת5: אחרי service_delete — האסימון 401', $http('GET', 'action=shared_roots', $pat($PAT, '10.0.0.5'))[0] === 401);
    check('ת5: והשיתוף לא ב-share_list של A', shareList(getAccount($A['id']), null)['items'] === []);
    check('ת5: חשבון השירות נמחק', !db()->query('SELECT 1 FROM accounts WHERE id = ' . db()->quote($S['id']))->fetchColumn());

    // מחיקה לצמיתות של צומת משותף — השיתוף נעלם איתו (מפתח זר)
    $S2 = getAccount(serviceCreate($owner, ['name' => 'זמני', 'owner' => 'avi@example.com'])['services'][0]['id']);
    $tmpF = makeFolder(getAccount($A['id']), ['name' => 'זמני']);
    shareCreate(getAccount($A['id']), ['node' => $tmpF['id'], 'grantee' => $S2['id']]);
    trashNode(getAccount($A['id']), ['id' => $tmpF['id']]);
    purgeNode(getAccount($A['id']), ['id' => $tmpF['id']]);
    check('purge של צומת משותף — עובר, והשיתוף נמחק', !db()->query('SELECT 1 FROM shares WHERE node = ' . db()->quote($tmpF['id']))->fetchColumn());
} finally {
    proc_terminate($proc);
    proc_close($proc);
}
