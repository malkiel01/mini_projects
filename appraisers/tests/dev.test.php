<?php
// מסך המפתח: בדיקות תקינות, יומן הפעולות של כל האפליקציה, יומן הבקשות והשגיאות, ו-DevLoop דרך המודול המשותף.
require __DIR__ . '/lib.php';

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
$user = ident('10', 'אבי', 'avi@a.co');
call('GET', 'me', [], $user);
[, $me] = call('GET', 'me', [], $owner);

// ── רק למפתח ──
foreach (['dev-health', 'dev-audit', 'dev-items', 'dev-tokens', 'dev-log', 'dev-item-log'] as $a) eq(call('GET', $a, [], $user)[0], 403, "GET $a — לא למשתמש רגיל");
foreach (['dev-task-create', 'dev-comment', 'dev-verify', 'dev-report', 'dev-token-create', 'dev-task-set', 'dev-task-from-log', 'dev-log-links', 'dev-attach'] as $a) {
    eq(call('POST', $a, ['title' => 'x', 'code' => 'AP-001', 'body' => 'x'], $user)[0], 403, "POST $a — לא למשתמש רגיל");
}
eq((int)apr_row("SELECT COUNT(*) AS n FROM sqlite_master WHERE name = 'dev_tasks'")['n'], 0, 'ומשתמש רגיל לא גרם ליצירת שום דבר');

// ── בדיקות תקינות ──
copy(__DIR__ . '/../data/.htaccess', APR_DATA_DIR . '/.htaccess');
[$st, $h] = call('GET', 'dev-health', [], $owner);
eq($st, 200, 'בדיקות תקינות למפתח');
$by = array_column($h['checks'], null, 'id');
foreach (['db', 'schema', 'write', 'protect', 'disk', 'version', 'ext', 'gate', 'log', 'connections', 'services'] as $id) check("יש בדיקה: $id", isset($by[$id]));
check('לכל בדיקה: שם, מצב ופירוט', !array_filter($h['checks'], fn($c) => $c['label'] === '' || !in_array($c['state'], ['ok', 'warn', 'bad'], true) || $c['detail'] === ''));
foreach (['db', 'schema', 'write', 'protect', 'version', 'ext', 'log'] as $id) eq($by[$id]['state'], 'ok', "תקין: $id — {$by[$id]['detail']}");
check('המצב הכולל אינו "תקלה"', $h['state'] !== 'bad', $h);

// בלי data/.htaccess — תקלה
unlink(APR_DATA_DIR . '/.htaccess');
$by = array_column(call('GET', 'dev-health', [], $owner)[1]['checks'], null, 'id');
eq($by['protect']['state'], 'bad', 'חסר data/.htaccess — תקלה');

// שירות רקע: מדווח, נכשל, נתקע
apr_service_beat('mail', 'שליחת מיילים', 600);
$svc = fn() => array_column(call('GET', 'dev-health', [], $owner)[1]['checks'], null, 'id')['svc:mail'];
eq($svc()['state'], 'ok', 'שירות שרץ עכשיו — תקין');
apr_service_beat('mail', 'שליחת מיילים', 600, 'SMTP 535');
check('שירות שנכשל — תקלה עם ההודעה', $svc()['state'] === 'bad' && str_contains($svc()['detail'], 'SMTP 535'));
apr_service_beat('mail', 'שליחת מיילים', 600);
$GLOBALS['apr_time'] = time() + 3600;
eq($svc()['state'], 'bad', 'שירות שלא רץ שלוש תקופות — תקלה');
unset($GLOBALS['apr_time']);
eq(call('GET', 'dev-health', [], $owner)[1]['state'], 'bad', 'ותקלה אחת צובעת את המצב הכולל');

// חיבור חיצוני נרשם ונבדק; בדיקה שזורקת לא מפילה את השאר
$GLOBALS['apr_connections'] = ['x' => ['label' => 'שירות דמה', 'check' => fn() => throw new RuntimeException('אין מענה')]];
$by = array_column(call('GET', 'dev-health', [], $owner)[1]['checks'], null, 'id');
eq([$by['conn:x']['state'], $by['conn:x']['detail'], $by['db']['state']], ['bad', 'אין מענה', 'ok'], 'חיבור שנכשל — מסומן, והשאר נבדקו');

// ── יומן הפעולות של כל האפליקציה ──
call('POST', 'office-create', ['name' => 'משרד א', 'admin_email' => 'avi@a.co'], $owner);
[, $au] = call('GET', 'dev-audit', [], $owner);
$acts = array_column($au['rows'], 'action');
check('המפתח רואה את כל הפעולות', in_array('office.created', $acts, true) && in_array('member.added', $acts, true) && in_array('user.created', $acts, true));
check('מהחדשה לישנה', $au['rows'][0]['id'] > end($au['rows'])['id']);
eq(array_unique(array_column(call('GET', 'dev-audit', [], $owner, ['what' => 'office.'])[1]['rows'], 'action')), ['office.created'], 'סינון לפי פעולה');
eq(count(call('GET', 'dev-audit', [], $owner, ['limit' => 1])[1]['rows']), 1, 'limit');
check('אין סודות ביומן', !preg_match('/csrf|token_hash|[a-f0-9]{64}/', json_encode($au)));

// ── DevLoop: מטלות ──
[$st, $items] = call('GET', 'dev-items', [], $owner);
eq($st, 200, 'רשימת המטלות');
$file = json_decode(file_get_contents(__DIR__ . '/../dev/open-items.json'), true);
check('מפת הדרכים: JSON תקין עם פריטים', is_array($file['items'] ?? null) && count($file['items']) > 0);
$codes = array_column($file['items'], 'code');
check('לכל פריט קוד ייחודי AP-000', count(array_unique($codes)) === count($codes) && !array_filter($codes, fn($c) => !preg_match('/^AP-\d{3}$/', $c)));
check('לכל פריט כותרת, מצב ואזור', !array_filter($file['items'], fn($x) => empty($x['title']) || empty($x['area'])
    || !in_array($x['status'] ?? '', ['open', 'in_progress', 'verify', 'question', 'closed'], true)));
eq(count($items['items']), count($file['items']), 'הרשימה במסך = הקובץ');

[$st, $t] = call('POST', 'dev-task-create', ['title' => 'כפתור השמירה לא מגיב', 'kind' => 'bug'], $owner);
check('מטלה חדשה מהמסך מקבלת את הקוד הבא', $st === 200 && preg_match('/^AP-\d{3}$/', $t['code']) === 1 && !in_array($t['code'], $codes, true), $t);
eq(call('POST', 'dev-comment', ['code' => $t['code'], 'body' => 'קורה רק בטלפון'], $owner)[0], 200, 'הערה בשיח');

// 🐞 עם ההקשר הטכני
[$st, $rep] = call('POST', 'dev-report', ['title' => 'המסך נתקע', 'context' => ['url' => '#/team', 'screen' => '390×844', 'requests' => [], 'errors' => []]], $owner);
check('דיווח 🐞 פותח מטלה', $st === 200 && !empty($rep['code']), $rep);

// ── האסימון של Claude: בלי שער ובלי עוגייה ──
[$st, $tok] = call('POST', 'dev-token-create', ['label' => 'Claude'], $owner);
check('אסימון apd_', $st === 200 && str_starts_with($tok['token'], 'apd_'));
check('יצירת האסימון ביומן הפעולות, בלי האסימון עצמו', ($r = apr_row("SELECT * FROM audit_log WHERE action = 'devtasks.token_create'")) && !str_contains(json_encode($r), $tok['token']));
unset($_SERVER['HTTP_X_DEV_TOKEN']);
eq(call('GET', 'devapi-feed')[0], 401, 'devapi-feed בלי אסימון — 401');
$_SERVER['HTTP_X_DEV_TOKEN'] = 'apd_' . str_repeat('ab', 24);
eq(call('GET', 'devapi-feed')[0], 401, 'אסימון מומצא — 401');
$_SERVER['HTTP_X_DEV_TOKEN'] = 'pmd_' . substr($tok['token'], 4);
eq(call('GET', 'devapi-feed')[0], 401, 'אסימון של אפליקציה אחרת — 401');
$_SERVER['HTTP_X_DEV_TOKEN'] = $tok['token'];
[$st, $feed] = call('GET', 'devapi-feed');
check('הפיד, בלי זהות מהשער', $st === 200 && in_array($t['code'], array_column($feed['items'], 'code'), true) && !empty($feed['next_code']), array_keys((array)$feed));
eq(call('POST', 'devapi-comment', ['code' => $t['code'], 'body' => 'תוקן', 'status' => 'verify'])[0], 200, 'Claude עונה ומעביר לאימות');
eq(call('GET', 'dev-items', [], null)[0], 401, 'האסימון לא פותח פעולות של המפתח');
eq(call('GET', 'me')[0], 401, 'ולא את שאר ה-API');

// רק המפתח סוגר
eq(call('POST', 'dev-verify', ['code' => $t['code'], 'ok' => true], $user)[0], 403, 'משתמש רגיל לא מאמת');
[$st] = call('POST', 'dev-verify', ['code' => $t['code'], 'ok' => true], $owner);
$now = array_column(call('GET', 'dev-items', [], $owner)[1]['items'], 'status', 'code');
eq([$st, $now[$t['code']]], [200, 'closed'], '✅ של המפתח סוגר');

// מפתח שהוסר בפלטפורמה — האסימון שלו מפסיק לעבוד
call('GET', 'me', [], ident('1', 'הבעלים', 'owner@example.com'));
eq(call('GET', 'devapi-feed')[0], 401, 'האסימון תקף רק כל עוד יוצרו מפתח פעיל');
call('GET', 'me', [], $owner);
eq(call('POST', 'dev-token-revoke', ['id' => 1], $owner)[0], 200, 'ביטול האסימון');
eq(call('GET', 'devapi-feed')[0], 401, 'אסימון שבוטל — 401');
unset($_SERVER['HTTP_X_DEV_TOKEN']);

// ── היומן המובנה: בקשות, שגיאות שרת ושגיאות דפדפן ──
$_GET['action'] = 'member-invite';
devloop_log_write('error', 'php', 'exception', ['message' => 'SQLSTATE[HY000]: disk I/O error', 'data' => ['file' => 'offices.php', 'line' => 120]]);
eq(call('POST', 'devloop-client-error', ['message' => 'x is not defined', 'source' => 'app.js', 'line' => 12, 'page' => '#/team'], $user)[0], 200, 'שגיאת JavaScript של משתמש רגיל נרשמת');
eq(call('POST', 'devloop-client-error', ['message' => 'x'])[0], 401, 'אבל לא בלי זהות');
[$st, $log] = call('GET', 'dev-log', [], $owner, ['level' => 'error']);
$ev = array_column($log['rows'], 'event');
check('היומן: שגיאת השרת ושגיאת הדפדפן', $st === 200 && in_array('exception', $ev, true) && in_array('client.error', $ev, true), $ev);
$js = array_values(array_filter($log['rows'], fn($r) => $r['event'] === 'client.error'))[0];
eq([$js['username'], $js['data']['page'] ?? null, $js['data']['line'] ?? null], ['אבי', '#/team', 12], 'שגיאת הדפדפן עם מי, איפה ובאיזו שורה');
check('לכל שורה מזהה בקשה', !array_filter($log['rows'], fn($r) => $r['req'] === ''));
// שגיאות מהיומן נפתחות לבד כמטלות "לבירור"
devloop_errors_sync();   // בשרת — רץ בכל פתיחה של המטלות (פעם בבקשה)
$tri = array_filter(call('GET', 'dev-items', [], $owner)[1]['items'], fn($x) => $x['status'] === 'triage');
check('שגיאה ביומן נפתחת לבד כמטלה ⚠️ לבירור', count($tri) >= 1, array_column(call('GET', 'dev-items', [], $owner)[1]['items'], 'status'));
check('היומן נשמר בתיקיית הנתונים (מוחרגת מהפריסה)', is_file(APR_DATA_DIR . '/devloop/log.sqlite'));

done();
