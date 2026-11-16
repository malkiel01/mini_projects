<?php
// R1 — עורך ראשי, עורך משני, משתמש (spec-stage-a פרק 24, 24.6). קריטריון לכל בלוק.
// php trading-strategies/tests/roles-api.php — מיגרציה על עותק עם נתונים (בתהליך), ואז php -S עם שער מדומה (dev-prepend).
// owner — עורך ראשי מהשער (platform_admin) · dave — עורך ראשי ממונה · carol — עורך משני · alice — משתמש · bob — יעד למינויים.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-roles-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}

// ── קריטריון 1: מיגרציה 18 על עותק עם נתונים ────────────────────────
// מסד בגרסה 17 בדיוק: בונים מסד מלא, מחזירים את app_roles לסכמה של 17 (CHECK 'admin') עם נתונים, ו-user_version = 17.
// בין 17 ל-18 משתנה רק app_roles — לכן זה המסד שבשרת לפני הפריסה.
define('TS_DB_PATH', "$tmp.mig.sqlite");
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/roles.php';
$m = new PDO('sqlite:' . TS_DB_PATH, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$m->exec('PRAGMA foreign_keys = ON');
ts_migrate($m);
eq((int)$m->query('PRAGMA user_version')->fetchColumn() >= 18, true, 'fresh database reaches 18');
$m->exec(<<<'SQL'
    PRAGMA foreign_keys = OFF;
    DROP TABLE app_roles;
    CREATE TABLE app_roles (
        user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
        role TEXT NOT NULL CHECK (role IN ('admin')),
        granted_by INTEGER NOT NULL,
        granted_at INTEGER NOT NULL
    );
    PRAGMA foreign_keys = ON;
    PRAGMA user_version = 17;
    SQL);
foreach ([[1, '100', 'בעלים'], [2, '101', 'א'], [3, '102', 'ב'], [4, '103', 'ג']] as [$id, $sub, $name])
    $m->prepare('INSERT INTO users (id, platform_sub, display_name, created_at, last_login_at) VALUES (?, ?, ?, 1, 1)')->execute([$id, $sub, $name]);
$m->exec("INSERT INTO app_roles VALUES (2, 'admin', 1, 1700000001), (3, 'admin', 1, 1700000002)");
$m->exec("INSERT INTO admin_log (user_id, action, target, at) VALUES (1, 'admin_grant', 'user:2', 1700000001), (1, 'admin_grant', 'user:3', 1700000002)");
$before = ['users' => $m->query('SELECT * FROM users ORDER BY id')->fetchAll(), 'log' => $m->query('SELECT * FROM admin_log ORDER BY id')->fetchAll()];
$failed = false;
try { $m->exec("INSERT INTO app_roles VALUES (4, 'editor', 1, 1)"); } catch (PDOException) { $failed = true; }
eq($failed, true, 'the v17 copy really has the old CHECK (editor refused)');

ts_migrate($m);
$roles = fn() => $m->query('SELECT user_id, role, granted_by, granted_at FROM app_roles ORDER BY user_id')->fetchAll();
$want = [['user_id' => 2, 'role' => 'editor', 'granted_by' => 1, 'granted_at' => 1700000001], ['user_id' => 3, 'role' => 'editor', 'granted_by' => 1, 'granted_at' => 1700000002]];
eq($roles(), $want, 'criterion 1: two admin → two editor, same granted_by and granted_at');
eq((int)$m->query('PRAGMA user_version')->fetchColumn(), 18, 'user_version = 18');
eq(['users' => $m->query('SELECT * FROM users ORDER BY id')->fetchAll(), 'log' => $m->query('SELECT * FROM admin_log ORDER BY id')->fetchAll()], $before, 'no other row touched (users, admin_log)');
ts_migrate($m);
eq([$roles(), (int)$m->query('PRAGMA user_version')->fetchColumn()], [$want, 18], 'criterion 1: second run → no change');
foreach (['admin', 'owner', ''] as $bad) {
    $failed = false;
    try { $m->exec("INSERT INTO app_roles VALUES (4, '$bad', 1, 1)"); } catch (PDOException) { $failed = true; }
    eq($failed, true, "criterion 1: INSERT role = '$bad' after the migration → fails (CHECK)");
}
$m->exec("INSERT INTO app_roles VALUES (4, 'chief', 1, 1)");
eq((int)$m->query("SELECT COUNT(*) FROM app_roles WHERE role = 'chief'")->fetchColumn(), 1, 'chief is accepted');
eq((int)$m->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'app_roles_v%'")->fetchColumn(), 0, 'no leftover temp table');
$m->exec('DELETE FROM users WHERE id = 4');
eq((int)$m->query('SELECT COUNT(*) FROM app_roles WHERE user_id = 4')->fetchColumn(), 0, 'ON DELETE CASCADE kept');
$fk = $m->query('PRAGMA foreign_key_check')->fetchAll();
eq($fk, [], 'foreign keys consistent');

// טרנזקציה: מיגרציה שנכשלת באמצע לא משאירה חצי. מסד בגרסה 17 עם שורה שה-CHECK החדש דוחה ('other') → הכול חוזר
$m2 = new PDO("sqlite:$tmp.mig2.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$m2->exec("CREATE TABLE users (id INTEGER PRIMARY KEY); INSERT INTO users VALUES (1), (2);
           CREATE TABLE app_roles (user_id INTEGER PRIMARY KEY, role TEXT NOT NULL, granted_by INTEGER NOT NULL, granted_at INTEGER NOT NULL);
           INSERT INTO app_roles VALUES (2, 'other', 1, 5); PRAGMA user_version = 17;");
$threw = false;
try { ts_migrate($m2); } catch (PDOException) { $threw = true; }
// כמו בבקשה בשרת: החיבור נסגר עם הטרנזקציה הפתוחה — SQLite מחזיר הכול. בודקים בחיבור חדש
$m2 = null;
$m2 = new PDO("sqlite:$tmp.mig2.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
eq([$threw, (int)$m2->query('PRAGMA user_version')->fetchColumn(), $m2->query('SELECT role FROM app_roles')->fetchAll(PDO::FETCH_COLUMN),
    (int)$m2->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'app_roles_v18'")->fetchColumn()], [true, 17, ['other'], 0],
    'a failing migration rolls back: version 17, the row and the table as they were');

// ביקורת R1 ⚪1: שורה יתומה ב-app_roles (משתמש שנמחק כשהמפתחות הזרים היו כבויים) לא מפילה את המיגרציה — היא לא עוברת
$m3 = new PDO("sqlite:$tmp.mig3.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$m3->exec("CREATE TABLE users (id INTEGER PRIMARY KEY); INSERT INTO users VALUES (1), (2);
           CREATE TABLE app_roles (user_id INTEGER PRIMARY KEY, role TEXT NOT NULL CHECK (role IN ('admin')), granted_by INTEGER NOT NULL, granted_at INTEGER NOT NULL);
           INSERT INTO app_roles VALUES (2, 'admin', 1, 5), (9, 'admin', 1, 6); PRAGMA user_version = 17; PRAGMA foreign_keys = ON;");
ts_migrate($m3);
eq([(int)$m3->query('PRAGMA user_version')->fetchColumn(), $m3->query('SELECT user_id || role FROM app_roles')->fetchAll(PDO::FETCH_COLUMN)], [18, ['2editor']],
    'R1 ⚪1: an orphan row (user 9) is left behind, the migration completes');

// ── השרת ─────────────────────────────────────────────────────────
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log",
    'TS_TEST_METHODS' => __DIR__ . '/fixtures/methods/alertable.php'] + getenv();
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'log_errors=1', '-d', "error_log=$tmp.error.log", '-d', 'memory_limit=512M'],
    [1 => ['file', "$tmp.server.log", 'w'], 2 => ['file', "$tmp.server.log", 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($srv, $tmp) {
    proc_terminate($srv);
    foreach (glob("$tmp*") ?: [] as $f) is_dir($f) ? array_map('unlink', glob("$f/*") ?: []) && @rmdir($f) : @unlink($f);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);
$jar = []; $tokens = [];
$NOW = 1794094200;
/** [קוד, JSON מפוענח] */
function call(string $as, string $action, ?array $body = null, array $query = [], array $extra = []): array
{
    global $port, $jar, $tokens, $NOW;
    $h = array_merge(["X-Dev-As: $as", "X-Dev-Now: $NOW"], $extra);
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 120]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $mm)) $jar[$as] = $mm[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw];
}
foreach (['alice', 'bob', 'carol', 'dave', 'owner'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];
$db = new PDO("sqlite:$tmp.sqlite", null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$idOf = fn(string $sub) => (int)$db->query("SELECT id FROM users WHERE platform_sub = '$sub'")->fetchColumn();
[$OWNER, $ALICE, $BOB, $CAROL, $DAVE] = [$idOf('100'), $idOf('101'), $idOf('102'), $idOf('103'), $idOf('104')];

eq(call('owner', 'admin_role_add', ['user_id' => $CAROL])[0], 200, 'owner appoints carol (role defaults to editor)');
eq(call('owner', 'admin_role_add', ['user_id' => $DAVE, 'role' => 'chief'])[0], 200, 'ר1: owner appoints dave as a chief editor');
eq(call('owner', 'admin_role_add', ['user_id' => $BOB, 'role' => 'admin'])[0], 422, 'unknown role → 422');
$me = function (string $u) { $r = array_intersect_key(call($u, 'me')[1], array_flip(['role', 'is_chief', 'is_editor', 'is_admin', 'is_primary_admin'])); ksort($r); return $r; };
eq([$me('owner'), $me('dave'), $me('carol'), $me('alice')], [
    ['is_admin' => true, 'is_chief' => true, 'is_editor' => true, 'is_primary_admin' => true, 'role' => 'chief'],
    ['is_admin' => true, 'is_chief' => true, 'is_editor' => true, 'is_primary_admin' => true, 'role' => 'chief'],
    ['is_admin' => true, 'is_chief' => false, 'is_editor' => true, 'is_primary_admin' => false, 'role' => 'editor'],
    ['is_admin' => false, 'is_chief' => false, 'is_editor' => false, 'is_primary_admin' => false, 'role' => 'user']], 'me: role per user');

// ── קריטריון 2: טבלת ההרשאות 24.2 — שורה לכל פעולה, שלושת התפקידים (ועורך ראשי ממונה) ──
$S = 'sample-ma-cross';
$mset = ['id' => $S, 'enabled' => true, 'markets' => ['crypto'], 'timeframes' => ['h1', 'h4', 'd1'], 'params' => [], 'note' => ''];
eq(call('owner', 'admin_method_save', $mset)[0], 200, 'setup: the sample method on');
$fast = 10;   // ערך חדש בכל קריאה — "שינוי פרמטר" אמיתי
$prop = fn() => ['method' => $S, 'param' => 'fast', 'value' => ++$GLOBALS['fast'], 'asset' => 'binance:BTCUSDT', 'tf' => 'h4'];
$newItem = fn() => call('owner', 'dev-task-create', ['title' => 'פריט לבדיקה'])[1]['code'];
// כל שורה: [שם, מי מותר (chief/editor/user), fn($as) → קוד]. משתמש ועורך משני קודם — 403 לא משנה כלום
$ROWS = [
    ['methods: on/off, markets, frames, note', ['chief', 'editor'], fn($as) => call($as, 'admin_method_save', ['note' => "הערה $as"] + $mset)[0]],
    ['methods: save a parameter', ['chief'], fn($as) => call($as, 'admin_method_save', ['params' => ['fast' => ++$GLOBALS['fast']]] + $mset)[0]],
    ['S7: proposal list', ['chief', 'editor'], fn($as) => call($as, 'admin_tune_list')[0]],
    ['S7: create a proposal', ['chief', 'editor'], fn($as) => call($as, 'admin_tune_propose', $prop())[0]],
    ['S7: apply', ['chief'], function ($as) use ($prop) {
        $id = call('owner', 'admin_tune_propose', $prop())[1]['proposal']['id'];
        return call($as, 'admin_tune_apply', ['id' => $id])[0];
    }],
    ['providers: settings', ['chief', 'editor'], fn($as) => call($as, 'admin_provider_save', ['provider' => 'binance', 'enabled' => true])[0]],
    ['providers: editor keys', ['chief', 'editor'], fn($as) => call($as, 'admin_cred_save', ['provider' => 'finnhub', 'secret' => 'good-Qx' . $as . 'Wv', 'label' => $as])[0]],
    ['providers: grant and limit', ['chief', 'editor'], function ($as) use ($db, $ALICE) {
        $cid = (int)$db->query("SELECT id FROM provider_creds WHERE owner = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
        return call($as, 'admin_grant_save', ['cred_id' => $cid, 'user_id' => $ALICE, 'daily_limit' => 5])[0];
    }],
    ['providers: the master switch', ['chief'], fn($as) => call($as, 'admin_shared_switch', ['on' => false])[0]],
    ['the app key', ['chief'], fn($as) => call($as, 'admin_app_key', ['provider' => 'twelvedata', 'cred_id' => null])[0]],
    ['cache: clear', ['chief', 'editor'], fn($as) => call($as, 'admin_cache_clear', [])[0]],
    ['fee and slippage', ['chief', 'editor'], fn($as) => call($as, 'admin_costs_save', ['costs' => ['crypto' => ['fee' => '0.1', 'slip' => '0.05']]])[0]],
    ['users: password reset', ['chief'], fn($as) => call($as, 'admin_password_reset', ['user_id' => $BOB])[0]],
    ['roles: appoint and revoke a secondary editor', ['chief'], function ($as) use ($BOB) {
        $a = call($as, 'admin_role_add', ['user_id' => $BOB, 'role' => 'editor'])[0];
        $r = call($as, 'admin_role_remove', ['user_id' => $BOB])[0];
        return $a === $r ? $a : "$a/$r";
    }],
    ['roles: appoint and revoke a chief editor', ['chief'], function ($as) use ($BOB) {
        $a = call($as, 'admin_role_add', ['user_id' => $BOB, 'role' => 'chief'])[0];
        $r = call($as, 'admin_role_remove', ['user_id' => $BOB])[0];
        return $a === $r ? $a : "$a/$r";
    }],
    ['method journal (what each one sees)', ['chief', 'editor', 'user'], fn($as) => call($as, 'method_journal', null, ['method' => $S])[0]],
    ['DevLoop: view', ['chief', 'editor'], fn($as) => call($as, 'dev-items')[0]],
    ['DevLoop: comment', ['chief', 'editor'], fn($as) => call($as, 'dev-comment', ['code' => $newItem(), 'body' => "תגובה של $as"])[0]],
    ['DevLoop: open an item', ['chief', 'editor'], fn($as) => call($as, 'dev-task-create', ['title' => "פריט של $as", 'kind' => 'feature'])[0]],
    ['🐞 report', ['chief', 'editor', 'user'], fn($as) => call($as, 'dev-report', ['title' => "🐞 של $as", 'context' => ['url' => '#/market']])[0]],
    ['DevLoop: verify and close', ['chief'], function ($as) use ($newItem) {
        $code = $newItem();
        call('owner', 'dev-task-set', ['code' => $code, 'status' => 'verify']);
        return call($as, 'dev-verify', ['code' => $code, 'ok' => true])[0];
    }],
    ['DevLoop: status change', ['chief'], fn($as) => call($as, 'dev-task-set', ['code' => $newItem(), 'status' => 'in_progress'])[0]],
    ['DevLoop: Claude token', ['chief'], fn($as) => call($as, 'dev-token-create', ['label' => "Claude $as"])[0]],
    ['admin_log: view', ['chief', 'editor'], fn($as) => call($as, 'admin_state')[0]],
];
// למידה (פרק 23: הלשונית, "החל", ממצא חריג) — עוד לא בנויה (L1). הפעולות שלה ישתמשו ב-ts_require_editor / ts_require_chief
$WHO = ['user' => ['alice'], 'editor' => ['carol'], 'chief' => ['owner', 'dave']];
foreach ($ROWS as [$name, $allowed, $fn]) {
    foreach ($WHO as $role => $users) foreach ($users as $as) {
        $want = in_array($role, $allowed, true) ? 200 : 403;
        eq($fn($as), $want, "criterion 2 · $name · $role ($as) → $want");
    }
}
eq(str_contains((string)call('carol', 'admin_tune_apply', ['id' => 1])[1]['error'], 'עורך ראשי'), true, '403 message: "פעולה של עורך ראשי בלבד"');
eq(call('alice', 'admin_state')[1]['error'] ?? null, 'פעולה של עורך בלבד', '403 message for a user: "פעולה של עורך בלבד"');
// 🐞 של משתמש: צילום רק לדיווח שלו
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
function attach(string $as, string $code, string $png): int
{
    global $port, $jar, $tokens;
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'content' => $png, 'header' => implode("\r\n",
        ["X-Dev-As: $as", 'Cookie: ts_session=' . ($jar[$as] ?? ''), 'X-CSRF: ' . $tokens[$as], 'Content-Type: image/png'])]]);
    file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => 'dev-attach', 'code' => $code, 'name' => 'a.png']), false, $ctx);
    return (int)explode(' ', $http_response_header[0])[1];
}
$mine = call('alice', 'dev-report', ['title' => 'שלי'])[1]['code'];
eq([attach('alice', $mine, $png), attach('alice', $newItem(), $png), attach('carol', $mine, $png)], [200, 403, 200],
    'a user attaches a screenshot to her own 🐞 only; an editor — to any item');

// ── קריטריון 3: העורך מהשער ───────────────────────────────────────
eq((int)$db->query("SELECT COUNT(*) FROM app_roles WHERE user_id = $OWNER")->fetchColumn(), 0, 'criterion 3: the gate chief has no app_roles row');
$ed = array_column(call('owner', 'admin_state')[1]['editors'], null, 'user_id');
eq([$ed[$OWNER]['role'], $ed[$OWNER]['gate'], $ed[$DAVE]['role'], $ed[$DAVE]['gate'], $ed[$CAROL]['role'], $ed[$DAVE]['granted_by'], isset($ed[$ALICE])],
    ['chief', true, 'chief', false, 'editor', $OWNER, false], 'the editors list: gate chief, appointed chief, editor (with who appointed)');
eq(call('dave', 'admin_role_remove', ['user_id' => $OWNER])[0], 422, 'criterion 3: the gate chief cannot be revoked (422)');
eq(call('dave', 'admin_role_add', ['user_id' => $OWNER, 'role' => 'editor'])[0], 422, '… nor demoted to editor (422)');
eq($me('owner')['role'], 'chief', '… and stays chief');

// ── קריטריון 5: לבטל את עצמך ───────────────────────────────────────
eq(call('dave', 'admin_role_remove', ['user_id' => $DAVE])[0], 422, 'criterion 5: an appointed chief revoking himself → 422');
eq(call('owner', 'admin_role_remove', ['user_id' => $OWNER])[0], 422, 'criterion 5: the gate chief revoking himself → 422');
eq(call('dave', 'admin_role_add', ['user_id' => $DAVE, 'role' => 'editor'])[0], 422, 'demoting yourself → 422 too');
eq(call('owner', 'admin_role_remove', ['user_id' => $ALICE])[0], 404, 'revoking a non-editor → 404');

// ── קריטריון 6: עורך משני מנסה למנות ────────────────────────────────
eq([call('carol', 'admin_role_add', ['user_id' => $BOB])[0], call('carol', 'admin_role_add', ['user_id' => $BOB, 'role' => 'chief'])[0],
    call('carol', 'admin_role_add', ['user_id' => $CAROL, 'role' => 'chief'])[0], call('carol', 'admin_role_remove', ['user_id' => $DAVE])[0]],
    [403, 403, 403, 403], 'criterion 6: a secondary editor cannot appoint, promote himself or revoke → 403');
eq((int)$db->query("SELECT COUNT(*) FROM app_roles WHERE user_id = $BOB")->fetchColumn(), 0, 'nothing written by the 403 attempts');

// ── קריטריון 8: DevLoop — עורך משני רואה ומגיב; ✅ לא אצלו. עורך ראשי ממונה — כן, והאסימון שלו חי כל עוד הוא ראשי ──
$code = $newItem();
call('owner', 'dev-task-set', ['code' => $code, 'status' => 'verify']);
eq([call('carol', 'dev-comment', ['code' => $code, 'body' => 'ראיתי'])[0], call('carol', 'dev-verify', ['code' => $code, 'ok' => true])[0]], [200, 403],
    'criterion 8: carol comments (200), "✅ verified" is not hers (403)');
eq(call('dave', 'dev-verify', ['code' => $code, 'ok' => true])[0], 200, 'criterion 8: an appointed chief verifies (ר2)');
$tok = call('dave', 'dev-token-create', ['label' => 'Claude'])[1]['token'];
eq(call('none', 'devapi-feed', null, [], ['X-Dev-Token: ' . $tok])[0], 200, 'the token of an appointed chief works');

// ── קריטריון 4: עורך ראשי ממונה — "החל" ו-admin_app_key → 200; אחרי ביטול → 403 מהבקשה הבאה ──
$pid = call('owner', 'admin_tune_propose', $prop())[1]['proposal']['id'];
eq(call('dave', 'admin_tune_apply', ['id' => $pid])[0], 200, 'criterion 4: dave (appointed chief) applies');
eq(call('dave', 'admin_app_key', ['provider' => 'twelvedata', 'cred_id' => null])[0], 200, 'criterion 4: dave sets the app key');
eq(call('owner', 'admin_role_add', ['user_id' => $DAVE, 'role' => 'editor'])[0], 200, 'owner changes dave to a secondary editor (the row is updated)');
eq([(int)$db->query("SELECT COUNT(*) FROM app_roles WHERE user_id = $DAVE")->fetchColumn(), $me('dave')['role']], [1, 'editor'], 'one role per user');
eq(call('dave', 'admin_app_key', ['provider' => 'twelvedata', 'cred_id' => null])[0], 403, 'criterion 4: as an editor — 403 at once');
eq(call('owner', 'admin_role_add', ['user_id' => $DAVE, 'role' => 'chief'])[0], 200, 'and back to chief');
eq(call('owner', 'admin_role_remove', ['user_id' => $DAVE])[0], 200, 'owner revokes dave');
$pid = call('owner', 'admin_tune_propose', $prop())[1]['proposal']['id'];
eq([call('dave', 'admin_tune_apply', ['id' => $pid])[0], call('dave', 'admin_app_key', ['provider' => 'twelvedata', 'cred_id' => null])[0], call('dave', 'admin_state')[0]],
    [403, 403, 403], 'criterion 4: after the revoke → 403, from the next request');
eq(call('none', 'devapi-feed', null, [], ['X-Dev-Token: ' . $tok])[0], 401, 'criterion 8: his Claude token dies with the role');

// ── קריטריון 10: admin_log — כל מינוי וביטול, עם התפקיד ──────────────
$log = $db->query("SELECT action, target FROM admin_log WHERE action IN ('role_grant', 'role_revoke') ORDER BY id")->fetchAll();
eq(array_slice($log, 0, 2), [['action' => 'role_grant', 'target' => "user:$CAROL role:editor"], ['action' => 'role_grant', 'target' => "user:$DAVE role:chief"]],
    'criterion 10: the first grants, with the role');
eq(array_slice($log, -3), [['action' => 'role_grant', 'target' => "user:$DAVE role:editor"], ['action' => 'role_grant', 'target' => "user:$DAVE role:chief"],
    ['action' => 'role_revoke', 'target' => "user:$DAVE role:chief"]], 'criterion 10: change, change back and revoke — each a row with the role');
eq(count(array_filter($log, fn($l) => $l['action'] === 'role_revoke')) >= 5, true, 'every revoke in the table test is logged too');

// ── קריטריון 9: מנותקת — עורך ראשי ממונה מ-app_roles, בלי תלות בשער; העורך מהשער — לפי platform_admin השמור ──
// בתהליך, על אותו מסד: session מנותק (via = password / sso), בלי זהות מהשער (אין PLATFORM_USER ב-CLI)
$db->exec("INSERT INTO app_roles (user_id, role, granted_by, granted_at) VALUES ($DAVE, 'chief', $OWNER, 1)");
// ts_db() בתהליך הזה כבר פתוח על מסד המיגרציה — לכן תהליך נפרד על מסד השרת
$code9 = <<<'PHP'
    define('TS_DB_PATH', $argv[1]);
    require $argv[2] . '/lib/db.php'; require $argv[2] . '/lib/auth.php'; require $argv[2] . '/lib/roles.php';
    $out = [];
    foreach (array_slice($argv, 3) as $spec) {
        [$id, $via] = explode(':', $spec);
        $u = ts_db()->query('SELECT * FROM users WHERE id = ' . (int)$id)->fetch();
        $GLOBALS['ts_current'] = ['user' => $u, 'via' => $via, 'token_hash' => '', 'grant_checked_at' => time()];
        $out[] = [ts_is_chief(), ts_is_editor((int)$id)];
    }
    echo json_encode($out);
    PHP;
file_put_contents("$tmp.c9.php", "<?php\n" . $code9);
$r9 = json_decode((string)shell_exec(implode(' ', array_map('escapeshellarg', ['php', "$tmp.c9.php", "$tmp.sqlite", dirname(__DIR__),
    "$DAVE:password", "$DAVE:sso", "$OWNER:password", "$CAROL:password", "$ALICE:sso"]))), true);
eq($r9, [[true, true], [true, true], [true, true], [false, true], [false, false]],
    'criterion 9: detached — dave (appointed chief) is chief by app_roles; owner by the saved platform_admin; carol editor; alice nothing');

// ── ביקורת R1, סבב 2 ─────────────────────────────────────────────
// 🟡3: עורך ראשי ממונה לא מאפס את הסיסמה של העורך מהשער (כמו בביטול)
$db->exec("INSERT OR REPLACE INTO app_roles (user_id, role, granted_by, granted_at) VALUES ($DAVE, 'chief', $OWNER, 1)");
eq(call('dave', 'admin_password_reset', ['user_id' => $OWNER])[0], 422, 'R1 🟡3: an appointed chief cannot reset the gate chief\'s password (422)');
eq(call('dave', 'admin_password_reset', ['user_id' => $BOB])[0], 200, '… but can reset another user\'s');
// ⚪2: דיווח של משתמש — רק כותרת, פירוט והקשר. סוג, עדיפות, תחום וקבלה — ברירת המחדל
$code = call('bob', 'dev-report', ['title' => 'בוב', 'kind' => 'feature', 'priority' => 'high', 'area' => 'X', 'acceptance' => 'Y', 'context' => ['url' => '#/x']])[1]['code'];
eq($db->query("SELECT kind || '|' || priority || '|' || area || '|' || acceptance || '|' || (context LIKE '%#/x%') FROM dev_tasks WHERE code = '$code'")->fetchColumn(),
    'bug|normal|||1', 'R1 ⚪2: a user\'s report keeps title/detail/context only');
$code = call('carol', 'dev-report', ['title' => 'קרול', 'priority' => 'high'])[1]['code'];
eq($db->query("SELECT priority FROM dev_tasks WHERE code = '$code'")->fetchColumn(), 'high', '… an editor\'s report keeps its fields');
// 🟡1: תקרה לשעה למשתמש — 10 דיווחים, 30 צילומים; מעבר — 429. לעורך אין תקרה כזו
$codes = [];
for ($i = 1; $i < 10; $i++) $codes[] = [call('bob', 'dev-report', ['title' => "בוב $i"])][0];
eq(array_unique(array_map(fn($r) => $r[0], $codes)), [200], 'R1 🟡1: a user — 10 reports in an hour are fine');
eq(call('bob', 'dev-report', ['title' => 'בוב 11'])[0], 429, 'R1 🟡1: the 11th report in an hour → 429');
$bobCode = $codes[0][1]['code'];
$ok = 0;
for ($i = 0; $i < 30; $i++) $ok += attach('bob', $bobCode, $png) === 200 ? 1 : 0;
eq([$ok, attach('bob', $bobCode, $png)], [30, 429], 'R1 🟡1: 30 screenshots in an hour, the 31st → 429');
$db->exec("UPDATE dev_tasks SET created_at = '2000-01-01T00:00:00Z' WHERE created_by = $BOB");
eq(call('bob', 'dev-report', ['title' => 'שעה אחרי'])[0], 200, 'R1 🟡1: an hour later — allowed again');
for ($i = 0; $i < 12; $i++) $last = call('carol', 'dev-report', ['title' => "קרול $i"])[0];
eq($last, 200, 'R1 🟡1: no such limit for an editor');

// ── קריטריון 7: הממשק — אין "מנהל" באף מחרוזת שמוצגת; יש "עורך ראשי" ו"עורך משני" ──
$js = '';
foreach (glob(dirname(__DIR__) . '/assets/js/*.js') as $f) {
    // בלי הערות: // בתחילת שורה או אחרי רווח, ו-/* … */. מה שנשאר — קוד ומחרוזות
    $src = preg_replace('#/\*.*?\*/#s', '', (string)file_get_contents($f));
    foreach (explode("\n", $src) as $i => $line) {
        $code = preg_replace('#(^|\s)//.*$#u', '', $line);
        if (str_contains($code, 'מנהל')) { eq(basename($f) . ':' . ($i + 1) . ' ' . trim($code), '', 'criterion 7: no "מנהל" in a shown string'); }
        $js .= $code . "\n";
    }
}
eq([str_contains($js, 'עורך ראשי'), str_contains($js, 'עורך משני')], [true, true], 'criterion 7: "עורך ראשי" and "עורך משני" are shown');
eq(str_contains((string)file_get_contents(dirname(__DIR__) . '/index.html'), 'מנהל'), false, 'index.html: no "מנהל"');

echo $fail ? "$fail/$n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
