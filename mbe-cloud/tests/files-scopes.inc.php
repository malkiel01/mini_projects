<?php
/**
 * שלב 3 — היקפי קבצים (files.readonly, files.app) והבורר. נטען מתוך tests/run.php (אותו מסד זמני, check(), code()).
 * הבדיקות לפי docs/spec-3-files-scopes.md סעיף 8 (ג1–ג11). ג10 — ב-run.php, ברישום הראשון.
 */

declare(strict_types=1);

/** @var array $owner */

// ── הכנה: שלוש אפליקציות — מלאה (fs-full), קריאה בלבד (fs-ro), ושבוחרים (fs-app). משתמש P, ואדם אחר Q ──
$fsPair = sodium_crypto_sign_keypair();
$fsSk = sodium_crypto_sign_secretkey($fsPair);
$GLOBALS['CLOUD_FETCH'] = function (string $url) use ($fsPair) {
    preg_match('#/mini_projects/([a-z0-9-]+)/#', $url, $m);
    return json_encode(['client' => $m[1] ?? '', 'keys' => [['kid' => 'fs1', 'alg' => 'Ed25519', 'key' => base64_encode(sodium_crypto_sign_publickey($fsPair))]]]);
};
$GLOBALS['CLOUD_APPS'] = ['fs-full' => ['layers' => ['files']], 'fs-ro' => ['layers' => ['files'], 'files_scope' => 'files.readonly'],
                          'fs-app' => ['layers' => ['files'], 'files_scope' => 'files.app']];
$fsRet = fn(string $c, string $a = 'drives_callback') => "https://mbe-plus.com/mini_projects/$c/api.php?action=$a";
foreach (['fs-full', 'fs-ro', 'fs-app'] as $c) {
    $st = registerClient(['client' => $c, 'name' => "אפליקציה $c", 'key_url' => "https://mbe-plus.com/mini_projects/$c/api.php?action=drives_key",
                          'origins' => ['https://mbe-plus.com'], 'return' => [$fsRet($c), $fsRet($c, 'drives_pick_callback')]])['status'];
    if ($c !== 'fs-full') check("ג10: $c (מצהירה " . clientFilesScope($c) . ') — active בלי אישור מנהל', $st === 'active');
}
check('ג10: fs-full מצהירה files בלי files_full — pending', getClient('fs-full')['status'] === 'pending');
adminClient(['client' => 'fs-full', 'files_full' => true]);

as_platform(['sub' => 'fs-p', 'name' => 'פנינה', 'email' => 'pnina@example.com']);
$P = requireAccount();
as_platform(['sub' => 'fs-q', 'name' => 'קובי', 'email' => 'kobi@example.com']);
$Q = requireAccount();
adminAccount($owner, ['account' => $P['id'], 'quota' => 50 * 1024 * 1024]);
adminAccount($owner, ['account' => $Q['id'], 'quota' => 50 * 1024 * 1024]);
$P = getAccount($P['id']); $Q = getAccount($Q['id']);

/** חיבור דרך הזרימה האמיתית, ואסימון. */
$fsToken = function (array $acc, string $client) use ($fsSk, $fsRet): string {
    $state = 'fs' . bin2hex(random_bytes(6));
    $sub = (string) $acc['platform_sub']; $exp = (string) (time() + 600);
    $q = ['client' => $client, 'scope' => clientFilesScope($client), 'state' => $state, 'return' => $fsRet($client), 'sub' => $sub, 'exp' => $exp,
          'sig' => 'fs1.' . b64u(sodium_crypto_sign_detached("connect\n$client\n$state\n$sub\n$exp\n" . $fsRet($client), $fsSk))];
    parse_str((string) parse_url(approveConnect($acc, connectRequest($q)), PHP_URL_QUERY), $bq);
    $ex = exchangeCode(activeClient($client), ['code' => $bq['code'], 'sub' => $sub]);
    return issueToken(activeClient($client), connectionRow($ex['connection'], $client))['token'];
};
/** פעולת קבצים כמו ב-api.php: actor מהאסימון, ואז filesAction. */
$fsDo = function (string $tok, string $action, array $in = [], array $get = []): mixed {
    $_SERVER['HTTP_X_CLOUD_TOKEN'] = $tok;
    try { return filesAction($action, actor($action, 'files', json_encode($in)), $in, $get); }
    finally { unset($_SERVER['HTTP_X_CLOUD_TOKEN']); }
};
$b64 = fn(string $s) => base64_encode($s);

// עץ של P בדף הענן: /דוחות/2026/מאזן.pdf, /פרטי.txt
$rep = makeFolder($P, ['name' => 'דוחות']);
$y26 = makeFolder($P, ['name' => '2026', 'parent' => $rep['id']]);
$pdf = writeFile($P, ['name' => 'מאזן.pdf', 'parent' => $y26['id'], 'content_b64' => $b64('%PDF-1'), 'mime' => 'application/pdf']);
$txt = writeFile($P, ['name' => 'פרטי.txt', 'content_b64' => $b64('סוד')]);

$tFull = $fsToken($P, 'fs-full');
$tRo = $fsToken($P, 'fs-ro');
$tApp = $fsToken($P, 'fs-app');
check('files_mode מהחיבור: full / readonly / app', ($_SERVER['HTTP_X_CLOUD_TOKEN'] = $tFull) && actor('list', 'files', '')['files_mode'] === 'full'
    && ($_SERVER['HTTP_X_CLOUD_TOKEN'] = $tRo) && actor('list', 'files', '')['files_mode'] === 'readonly'
    && ($_SERVER['HTTP_X_CLOUD_TOKEN'] = $tApp) && actor('list', 'files', '')['files_mode'] === 'app');
unset($_SERVER['HTTP_X_CLOUD_TOKEN']);

// ג1 — קריאה בלבד
$cnt = fn() => (int) db()->query('SELECT COUNT(*) || \'/\' || MAX(updated_at) FROM nodes WHERE account = ' . db()->quote($P['id']))->fetchColumn();
$snap = db()->query('SELECT GROUP_CONCAT(id || name || COALESCE(parent,\'\') || rev || COALESCE(trashed_at,\'\'), \',\') FROM nodes WHERE account = ' . db()->quote($P['id']))->fetchColumn();
check('ג1: files.readonly — list, stat ו-read על כל הכונן', count($fsDo($tRo, 'list')['items']) === 2
    && $fsDo($tRo, 'stat', [], ['id' => $txt['id']])['name'] === 'פרטי.txt' && isset($fsDo($tRo, 'read', [], ['id' => $pdf['id']])['__read']));
$codes = [];
foreach ([['write', ['name' => 'x.txt', 'content_b64' => $b64('x')]], ['mkdir', ['name' => 'x']], ['rename', ['id' => $txt['id'], 'name' => 'y']],
          ['move', ['id' => $txt['id'], 'parent' => $rep['id']]], ['trash', ['id' => $txt['id']]], ['upload_start', ['name' => 'u', 'size' => 1]],
          ['purge', ['id' => $txt['id']]], ['trash_empty', []]] as [$act, $in]) {
    $codes[$act] = code(fn() => $fsDo($tRo, $act, $in));
}
check('ג1: files.readonly — כל כתיבה 403 read_only', array_unique(array_values($codes)) === ['read_only']);
check('ג1: ושום דבר לא השתנה במסד', db()->query('SELECT GROUP_CONCAT(id || name || COALESCE(parent,\'\') || rev || COALESCE(trashed_at,\'\'), \',\') FROM nodes WHERE account = ' . db()->quote($P['id']))->fetchColumn() === $snap);

// ג2 — files.app: ריק, ותיקיית האפליקציה
check('ג2: files.app — list ריק בהתחלה', $fsDo($tApp, 'list') === ['folder' => null, 'path' => [], 'items' => []]);
$w = $fsDo($tApp, 'write', ['name' => 'נרות.csv', 'content_b64' => $b64('a,b')]);
$af = nodeRow($P, nodeRow($P, $w['id'])['parent']);
check('ג2: write בלי parent — "📁 <שם האפליקציה>" בשורש הכונן, והקובץ בתוכה', $af['name'] === 'אפליקציה fs-app' && $af['parent'] === null && $w['parent'] === $af['id']);
check('ג2: list בשורש — רק תיקיית האפליקציה, עם parent null', array_column($fsDo($tApp, 'list')['items'], 'name') === ['אפליקציה fs-app']
    && $fsDo($tApp, 'list')['items'][0]['parent'] === null);
renameNode($P, ['id' => $af['id'], 'name' => 'המסחר שלי']);
$w2 = $fsDo($tApp, 'write', ['name' => 'עוד.csv', 'content_b64' => $b64('c')]);
check('ג2: שם התיקייה שונה בדף הענן — אותה תיקייה (לפי id), לא חדשה', $w2['parent'] === $af['id']
    && (int) db()->query("SELECT COUNT(*) FROM nodes WHERE account = " . db()->quote($P['id']) . " AND parent IS NULL AND kind = 'folder'")->fetchColumn() === 2);

// ג3 — מחוץ להענקה: 404, זהה לפריט שלא קיים
$e1 = null; $e2 = null;
try { $fsDo($tApp, 'stat', [], ['id' => $txt['id']]); } catch (AppError $x) { $e1 = $x; }
try { $fsDo($tApp, 'stat', [], ['id' => 'n_לא_קיים']); } catch (AppError $x) { $e2 = $x; }
check('ג3: stat על קובץ שלא הוענק — 404, בדיוק כמו פריט שלא קיים', $e1 && $e2 && $e1->status === 404 && [$e1->getMessage(), $e1->codeName] === [$e2->getMessage(), $e2->codeName]);
check('ג3: read ו-list על מה שלא הוענק — 404', code(fn() => $fsDo($tApp, 'read', [], ['id' => $pdf['id']])) === 'not_found'
    && code(fn() => $fsDo($tApp, 'list', [], ['folder' => $rep['id']])) === 'not_found');

// ג4 + ג8 — הבורר
$pq = function (array $acc, string $client, array $extra = []) use ($fsSk, $fsRet): array {
    $state = 'pk' . bin2hex(random_bytes(6));
    $sub = $extra['sub'] ?? (string) $acc['platform_sub']; $exp = (string) (time() + 600);
    $ret = $fsRet($client, 'drives_pick_callback');
    return ['client' => $client, 'state' => $state, 'return' => $ret, 'sub' => $sub, 'exp' => $exp, 'kind' => $extra['kind'] ?? 'file',
            'multiple' => $extra['multiple'] ?? '0', 'mime' => $extra['mime'] ?? '',
            'sig' => 'fs1.' . b64u(sodium_crypto_sign_detached(($extra['purpose'] ?? 'pick') . "\n$client\n$state\n$sub\n$exp\n$ret", $fsSk))];
};
$req = pickRequest($pq($P, 'fs-app', ['mime' => 'application/pdf']));
pickAllowed($P, $req);
check('ג4: pickFits — PDF מתאים, טקסט ותיקייה לא (kind file + mime)', pickFits($req, $pdf + ['mime' => 'application/pdf'])
    && !pickFits($req, nodeRow($P, $txt['id'])) && !pickFits($req, nodeRow($P, $rep['id'])));
check('ג4: בחירה של משהו שלא תואם את הבקשה — 400', code(fn() => pickApprove($P, $req, [$txt['id']])) === 'bad_request');
$back = pickApprove($P, $req, [$pdf['id']]);
parse_str((string) parse_url($back, PHP_URL_QUERY), $bq);
check('ג4: בחירת קובץ — חזרה עם state ו-picked, ואחריה read עובד', $bq['picked'] === $pdf['id'] && $bq['state'] === $req['state']
    && isset($fsDo($tApp, 'read', [], ['id' => $pdf['id']])['__read']));
check('ג4: אותה בקשה פעמיים — bad_state (state חד-פעמי)', code(fn() => pickApprove($P, $req, [$pdf['id']])) === 'bad_state');
$req2 = pickRequest($pq($P, 'fs-app', ['kind' => 'folder']));
pickApprove($P, $req2, [$rep['id']]);
$inside = $fsDo($tApp, 'write', ['name' => 'חדש.txt', 'parent' => $y26['id'], 'content_b64' => $b64('n')]);
check('ג4: בחירת תיקייה — list בתוכה ו-write לתוכה עובדים', in_array('מאזן.pdf', array_column($fsDo($tApp, 'list', [], ['folder' => $y26['id']])['items'], 'name'), true)
    && $inside['parent'] === $y26['id']);
check('ג4: multiple:0 עם שני פריטים — 400', code(fn() => pickApprove($P, pickRequest($pq($P, 'fs-app', ['kind' => 'any'])), [$txt['id'], $pdf['id']])) === 'bad_request');

// ג5 — הנתיב מתחיל בשורש ההענקה
$l = $fsDo($tApp, 'list', [], ['folder' => $y26['id']]);
check('ג5: path מתחיל בשורש ההענקה (דוחות), בלי מה שמעליו', array_column($l['path'], 'name') === ['דוחות', '2026']);
check('ג5: parent של שורש ההענקה — null', $fsDo($tApp, 'stat', [], ['id' => $rep['id']])['parent'] === null
    && $fsDo($tApp, 'stat', [], ['id' => $y26['id']])['parent'] === $rep['id']);
check('ג5: הקובץ שנבחר לבד (מאזן.pdf) מכוסה גם בהענקה של התיקייה — שורש יחיד ב-list', !in_array('מאזן.pdf', array_column($fsDo($tApp, 'list')['items'], 'name'), true));

// ג6 — move ו-purge
check('ג6: move אל מחוץ להענקה — 404; אל שורש הכונן — 404', code(fn() => $fsDo($tApp, 'move', ['id' => $inside['id'], 'parent' => 'n_x'])) === 'not_found'
    && code(fn() => $fsDo($tApp, 'move', ['id' => $inside['id'], 'parent' => null])) === 'not_found');
$tmp = makeFolder($P, ['name' => 'אחר']);
check('ג6: move אל תיקייה של המשתמש שלא הוענקה — 404', code(fn() => $fsDo($tApp, 'move', ['id' => $inside['id'], 'parent' => $tmp['id']])) === 'not_found');
check('ג6: move בתוך ההענקה — עובד', $fsDo($tApp, 'move', ['id' => $inside['id'], 'parent' => $rep['id']])['parent'] === $rep['id']);
check('ג6: purge ו-trash_empty — 403 (רק בדף הענן)', code(fn() => $fsDo($tApp, 'purge', ['id' => $inside['id']])) === 'forbidden'
    && code(fn() => $fsDo($tApp, 'trash_empty')) === 'forbidden');
$fsDo($tApp, 'trash', ['id' => $inside['id']]);
check('ג6: trash ו-restore בתוך ההענקה; trash_list רק מה שהוענק', array_column($fsDo($tApp, 'trash_list')['items'], 'id') === [$inside['id']]
    && $fsDo($tApp, 'restore', ['id' => $inside['id']])['id'] === $inside['id']);

// ג7 — ביטול הענקה, וכיבוי 📁
grantRevoke($P, 'fs-app', $rep['id']);
check('ג7: grant_revoke על התיקייה — מיד 404 על מה שבתוכה; הקובץ שנבחר בנפרד עדיין נגיש', isset($fsDo($tApp, 'read', [], ['id' => $pdf['id']])['__read'])
    && code(fn() => $fsDo($tApp, 'list', [], ['folder' => $y26['id']])) === 'not_found');
grantRevoke($P, 'fs-app', $pdf['id']);
check('ג7: grant_revoke על הקובץ — read מיד 404', code(fn() => $fsDo($tApp, 'read', [], ['id' => $pdf['id']])) === 'not_found');
pickApprove($P, pickRequest($pq($P, 'fs-app', ['mime' => 'application/pdf'])), [$pdf['id']]);   // שוב, להמשך
$cApp = connectionRow((string) json_decode((string) unb64u(explode('.', $tApp)[0]), true)['conn'], 'fs-app');
narrowScope($cApp, []);
check('ג7: כיבוי 📁 — הפעולות נחסמות (layer-off)', code(fn() => $fsDo($tApp, 'list')) === 'forbidden');
$tApp = $fsToken($P, 'fs-app');
check('ג7: הדלקה מחדש — ההענקות נשמרו, read עובד', isset($fsDo($tApp, 'read', [], ['id' => $pdf['id']])['__read']));
check('grants_list — שורשים עם הסוג', ($gl = array_column(grantsList($P, 'fs-app')['items'], 'via', 'name')) && $gl['מאזן.pdf'] === 'picked' && $gl['המסחר שלי'] === 'created');

// ג8 — בורר עם sub אחר, ובלי files.app
$bad = pickRequest($pq($P, 'fs-app', ['sub' => 'fs-q']));
check('ג8: בורר עם sub של אדם אחר — לא אותו חשבון (המסך: "הבחירה נדחתה")', !sameSub($P, $bad['sub']));
check('ג8: בורר עם חתימה של connect (purpose אחר) — bad_identity', code(fn() => pickRequest($pq($P, 'fs-app', ['purpose' => 'connect']))) === 'bad_identity');
check('ג8: בורר לאפליקציה עם files המלא — 400', code(fn() => pickAllowed($P, pickRequest($pq($P, 'fs-full')))) === 'bad_request');
check('ג8: בורר בלי חיבור (Q לא מחובר) — 400', code(fn() => pickAllowed($Q, pickRequest($pq($Q, 'fs-app')))) === 'bad_request');

// ג9 — created גם ב-files המלא
$fw = $fsDo($tFull, 'write', ['name' => 'מלא.txt', 'content_b64' => $b64('f')]);
check('ג9: הענקת created נרשמת גם ב-files המלא', (int) db()->query("SELECT COUNT(*) FROM grants WHERE client = 'fs-full' AND via = 'created' AND node = " . db()->quote($fw['id']))->fetchColumn() === 1
    && $fw['parent'] === null);
check('ג9: ב-files המלא write בלי parent — לשורש הכונן (כמו קודם)', $fw['parent'] === null);

// ג11 — לא רואה שיתופים ולא חשבון אחר
$qf = writeFile($Q, ['name' => 'של קובי.txt', 'content_b64' => $b64('q')]);
grantAdd('fs-app', $P['id'], $qf['id'], 'picked');   // גם אם הענקה "מזויפת" נרשמה — הפריט של חשבון אחר
check('ג11: files.app — פריט של חשבון אחר: 404', code(fn() => $fsDo($tApp, 'stat', [], ['id' => $qf['id']])) === 'not_found');
$qd = makeFolder($Q, ['name' => 'לשיתוף']);
db()->prepare("INSERT INTO shares (id, owner, node, grantee, role, created_at, created_by) VALUES (?,?,?,?,'read',?,?)")->execute(['sh_fs1', $Q['id'], $qd['id'], $P['id'], nowIso(), $Q['id']]);
check('ג11: files.app — לא רואה פריטים ששותפו עם המשתמש', code(fn() => $fsDo($tApp, 'list', [], ['folder' => $qd['id']])) === 'not_found'
    && !in_array('לשיתוף', array_column($fsDo($tApp, 'list')['items'], 'name'), true));

// הצהרה מצמצמת חלה מיד; ניתוק מלא מוחק picked ומשאיר created
$GLOBALS['CLOUD_APPS']['fs-full']['files_scope'] = 'files.app';
check('הצהרה מצמצמת (files → files.app) — חלה מיד על חיבור קיים', ($_SERVER['HTTP_X_CLOUD_TOKEN'] = $tFull) && actor('list', 'files', '')['files_mode'] === 'app');
unset($_SERVER['HTTP_X_CLOUD_TOKEN']);
$GLOBALS['CLOUD_APPS']['fs-full']['files_scope'] = 'files';
revokeConnection($cApp['id']);
check('ניתוק מלא — הענקות picked נמחקות, created נשארות',
    (int) db()->query("SELECT COUNT(*) FROM grants WHERE client = 'fs-app' AND account = " . db()->quote($P['id']) . " AND via = 'picked'")->fetchColumn() === 0
    && (int) db()->query("SELECT COUNT(*) FROM grants WHERE client = 'fs-app' AND account = " . db()->quote($P['id']) . " AND via = 'created'")->fetchColumn() >= 1);
purgeNode($P, trashNode($P, ['id' => $af['id']]));
check('מחיקה לצמיתות — ההענקות על הצמתים נמחקות איתם', (int) db()->query('SELECT COUNT(*) FROM grants WHERE node = ' . db()->quote($af['id']))->fetchColumn() === 0);

unset($GLOBALS['CLOUD_APPS']);
