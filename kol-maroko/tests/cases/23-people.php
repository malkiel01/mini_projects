<?php
// P1 — 👥 אנשים: הזמנה דרך provision (מדומה: invited / granted / blocked / כשל), חתימה, בעל עמוד אחרון, הסרה, ⚙️ בעל האתר, אישור פרסום.

declare(strict_types=1);

function p1_prov(array $set): void
{
    $st = is_file($GLOBALS['PROV_FILE']) ? json_decode((string)file_get_contents($GLOBALS['PROV_FILE']), true) : [];
    file_put_contents($GLOBALS['PROV_FILE'], json_encode(array_merge($st ?: [], $set)));
}

function p1_prov_log(): array
{
    return (json_decode((string)@file_get_contents($GLOBALS['PROV_FILE']), true) ?: [])['log'] ?? [];
}

test('P1 אנשים: platform_key ציבורי — מפתח Ed25519, זוג נפרד מ-embed_key; הפרטי במסד בלבד', function () {
    $r = http('GET', '/api.php?action=platform_key');
    eq(200, $r['status']);
    $k = $r['json']['keys'][0];
    eq(['kol-maroko', 'Ed25519'], [$r['json']['client'], $k['alg']]);
    eq(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen(base64_decode($k['key'])));
    ok(!str_contains($r['body'], 'secret'), 'בלי המפתח הפרטי');
    $embed = http('GET', '/api.php?action=embed_key')['body'];
    ok(!str_contains($embed, $k['key']), 'לא אותו מפתח של embed_key');
    $app = json_decode((string)file_get_contents(KOL_ROOT . '/app.json'), true);
    ok(str_ends_with($app['provision']['key_url'], 'api.php?action=platform_key') && $app['grant_status']['key_url'] === $app['provision']['key_url'], 'הצהרות ב-app.json');
});

test('P1 אנשים: הוסף עוזר ← provision חתום; invited / granted / blocked — ההודעה, והשורה ב-users עם sub ותפקיד', function () {
    p1_prov(['mode' => 'invited', 'sub' => '8101', 'log' => []]);
    $r = http('POST', '/api.php?action=person_invite', ['user' => U_PAGE, 'json' => ['email' => 'Helper1@Example.test', 'name' => 'עוזר <b>1</b>', 'role' => 'content_editor']]);
    eq(200, $r['status'], $r['body']);
    eq('נשלחה הזמנה ל-helper1@example.test, תקפה 7 ימים', $r['json']['message']);
    $u = user_by_sub('8101');
    eq(['content_editor', 'invited', 'עוזר <b>1</b>'], [$u['role'], $u['invite'], $u['name']]);
    $log = p1_prov_log()[0];
    $body = json_decode($log['body'], true);
    eq(['helper1@example.test', true, '/mini_projects/kol-maroko/'], [$body['email'], $body['grant'], $body['next']]);
    // החתימה — מול המפתח הציבורי שבכתובת הציבורית
    [$kid, $sig] = explode('.', $log['sig'], 2);
    $pub = http('GET', '/api.php?action=platform_key')['json']['keys'][0];
    eq($pub['kid'], $kid);
    ok(sodium_crypto_sign_verify_detached(base64_decode($sig), "provision\n{$log['time']}\n{$log['nonce']}\n" . hash('sha256', $log['body']), base64_decode($pub['key'])), 'חתימה תקפה');
    eq('kol-maroko', $log['client']);

    p1_prov(['mode' => 'granted', 'sub' => '8102']);
    eq('יש לו כבר חשבון בכלים הדיגיטליים; ניתנה לו גישה', http('POST', '/api.php?action=person_invite', ['user' => U_PAGE, 'json' => ['email' => 'helper2@example.test', 'role' => 'editor']])['json']['message']);
    p1_prov(['mode' => 'blocked']);
    $b = http('POST', '/api.php?action=person_invite', ['user' => U_PAGE, 'json' => ['email' => 'helper3@example.test', 'role' => 'editor']]);
    eq('החשבון חסום בפלטפורמה — רק בעל האתר יכול לשחרר', $b['json']['message']);
    eq('blocked', db()->query("SELECT invite FROM users WHERE email = 'helper3@example.test'")->fetchColumn());
    // כפילות ותפקיד לא חוקי
    eq(400, http('POST', '/api.php?action=person_invite', ['user' => U_PAGE, 'json' => ['email' => 'helper2@example.test', 'role' => 'editor']])['status']);
    eq(400, http('POST', '/api.php?action=person_invite', ['user' => U_PAGE, 'json' => ['email' => 'x@example.test', 'role' => 'site_owner']])['status']);
    eq(403, http('POST', '/api.php?action=person_invite', ['user' => U_PUBLISHER, 'json' => ['email' => 'x@example.test', 'role' => 'editor']])['status'], 'מפרסם אינו מזמין');
});

test('P1 אנשים: provision נכשל ← "ממתינה" עם הסיבה, "נסה שוב" מצליח; ומי שהוזמן נכנס ומקבל את התפקיד', function () {
    p1_prov(['mode' => 'fail']);
    $r = http('POST', '/api.php?action=person_invite', ['user' => U_PAGE, 'json' => ['email' => 'later@example.test', 'name' => 'מאוחר', 'role' => 'publisher']]);
    eq(200, $r['status']);
    ok(str_contains($r['json']['message'], 'ממתינה') && str_contains($r['json']['message'], '403'), $r['json']['message']);
    $row = db()->query("SELECT * FROM users WHERE email = 'later@example.test'")->fetch();
    eq(['pending', null], [$row['invite'], $row['sub']]);
    ok(str_contains(json_encode(http('GET', '/api.php?action=dev-state', ['user' => U_OWNER])['json'], JSON_UNESCAPED_UNICODE), 'provision'), 'במסך המפתח');
    p1_prov(['mode' => 'invited', 'sub' => '8104']);
    $t = http('POST', '/api.php?action=person_retry', ['user' => U_PAGE, 'json' => ['id' => (int)$row['id']]]);
    eq('נשלחה הזמנה ל-later@example.test, תקפה 7 ימים', $t['json']['message']);
    $me = http('GET', '/api.php?action=me', ['user' => ['sub' => '8104', 'name' => 'מאוחר', 'email' => 'later@example.test', 'email_verified' => true]])['json'];
    eq('publisher', $me['user']['role'], 'נכנס מהמייל — עם התפקיד');
});

test('P1 אנשים: מי שביקש גישה ← "הוסף" נותן תפקיד בלי provision; "התעלם" מוציא מהרשימה', function () {
    http('GET', '/api.php?action=me', ['user' => ['sub' => '8201', 'name' => 'מבקש', 'email' => 'req@example.test', 'email_verified' => true]]);
    $id = (int)user_by_sub('8201')['id'];
    $n = count(p1_prov_log());
    $r = http('POST', '/api.php?action=person_invite', ['user' => U_PAGE, 'json' => ['id' => $id, 'role' => 'editor']]);
    eq(200, $r['status'], $r['body']);
    eq('editor', user_by_sub('8201')['role']);
    eq($n, count(p1_prov_log()), 'בלי קריאה לפלטפורמה');
    http('GET', '/api.php?action=me', ['user' => ['sub' => '8202', 'name' => 'זר2', 'email' => 'req2@example.test', 'email_verified' => true]]);
    http('POST', '/api.php?action=request_dismiss', ['user' => U_PAGE, 'json' => ['id' => (int)user_by_sub('8202')['id']]]);
    ok(!in_array('req2@example.test', array_column(http('GET', '/api.php?action=people', ['user' => U_PAGE])['json']['requests'], 'email'), true));
});

test('P1 אנשים: שינוי תפקיד מיידי; הסרה ← grant:false, removed, וכל ה-sessions נמחקים מיד', function () {
    $who = ['sub' => '8101', 'name' => 'עוזר 1', 'email' => 'helper1@example.test', 'email_verified' => true];
    eq('content_editor', http('GET', '/api.php?action=me', ['user' => $who])['json']['user']['role']);
    $id = (int)user_by_sub('8101')['id'];
    http('POST', '/api.php?action=person_role', ['user' => U_PAGE, 'json' => ['id' => $id, 'role' => 'editor']]);
    eq('editor', http('GET', '/api.php?action=me', ['user' => $who])['json']['user']['role'], 'מיידי, באותו session');
    ok((int)db()->query("SELECT COUNT(*) FROM sessions WHERE user_id = $id")->fetchColumn() >= 1);
    $before = count(p1_prov_log());
    $r = http('POST', '/api.php?action=person_remove', ['user' => U_PAGE, 'json' => ['id' => $id]]);
    eq(200, $r['status'], $r['body']);
    eq(0, (int)db()->query("SELECT COUNT(*) FROM sessions WHERE user_id = $id")->fetchColumn(), 'ה-sessions נמחקו');
    eq('removed', user_get($id)['status']);
    $last = p1_prov_log()[$before];
    eq(false, json_decode($last['body'], true)['grant'], 'grant:false');
    $me = http('GET', '/api.php?action=me', ['user' => $who])['json']['user'];
    eq([null, true], [$me['role'], $me['removed']], 'נזרק מיד');
    eq(403, http('GET', '/api.php?action=words', ['user' => $who])['status']);
});

test('P1 אנשים: בעל עמוד אחרון — אי אפשר להסיר או להוריד; המינוי הראשון — רק בעל האתר', function () {
    $page = (int)user_by_sub('u-page')['id'];
    eq(409, http('POST', '/api.php?action=person_role', ['user' => U_PAGE, 'json' => ['id' => $page, 'role' => 'editor']])['status'], 'הורדה של האחרון');
    eq(409, http('POST', '/api.php?action=person_remove', ['user' => U_OWNER, 'json' => ['id' => $page]])['status'], 'הסרה של האחרון — גם בידי בעל האתר');
    $ed = (int)user_by_sub('8102')['id'];
    eq(200, http('POST', '/api.php?action=person_role', ['user' => U_PAGE, 'json' => ['id' => $ed, 'role' => 'page_owner']])['status'], 'בעל עמוד ממנה שני');
    eq(200, http('POST', '/api.php?action=person_role', ['user' => U_PAGE, 'json' => ['id' => $ed, 'role' => 'editor']])['status'], 'כשיש שניים — מותר להוריד');
    // בלי בעל עמוד בכלל: רק בעל האתר ממנה
    db()->exec("UPDATE users SET role = 'publisher' WHERE sub = 'u-page'");
    try {
        eq(403, http('POST', '/api.php?action=person_role', ['user' => U_PAGE, 'json' => ['id' => $ed, 'role' => 'page_owner']])['status'], 'מפרסם אינו ממנה');
        eq(200, http('POST', '/api.php?action=person_role', ['user' => U_OWNER, 'json' => ['id' => $page, 'role' => 'page_owner']])['status'], 'בעל האתר ממנה ראשון');
    } finally {
        db()->exec("UPDATE users SET role = 'page_owner' WHERE sub = 'u-page'");
    }
    $site = (int)user_by_sub('u-owner')['id'];
    eq(403, http('POST', '/api.php?action=person_remove', ['user' => U_PAGE, 'json' => ['id' => $site]])['status'], 'את בעל האתר לא מסירים');
});

test('P1 ⚙️ בעל האתר: הרשימה לכולם לקריאה ("נקבע בידי בעל האתר"); המתג — רק לבעל האתר', function () {
    $o = http('GET', '/api.php?action=owner_settings', ['user' => U_PAGE])['json']['owner'];
    eq(false, $o['can_edit']);
    ok(count($o['rows']) >= 6, 'כל השורות');
    foreach ($o['rows'] as $r) ok(str_ends_with($r['text'], 'נקבע בידי בעל האתר'), $r['text']);
    ok((bool)array_filter($o['rows'], fn($r) => $r['label'] === 'גודל קובץ מקסימלי'), 'גודל קובץ');
    eq(403, http('POST', '/api.php?action=owner_set', ['user' => U_PAGE, 'json' => ['approval' => true]])['status']);
    eq(true, http('POST', '/api.php?action=owner_set', ['user' => U_OWNER, 'json' => ['approval' => true]])['json']['owner']['approval']);
    http('POST', '/api.php?action=owner_set', ['user' => U_OWNER, 'json' => ['approval' => false]]);
});

test('P1 יומן: user_id ו-source בכל כתיבה; בעל העמוד אינו רואה את פעולות החיבור; סינון לפי אדם ולפי מקור', function () {
    http('POST', '/api.php?action=disk_cap', ['user' => U_OWNER, 'json' => ['gb' => 7]]);
    $row = db()->query("SELECT * FROM audit ORDER BY id DESC LIMIT 1")->fetch();
    eq(['disk_cap', (int)user_by_sub('u-owner')['id'], 'studio'], [$row['action'], (int)$row['user_id'], $row['source']]);
    $page = http('GET', '/api.php?action=audit', ['user' => U_PAGE])['json'];
    ok(!in_array('disk_cap', array_column($page['audit'], 'action'), true), 'בעל העמוד — בלי פעולות של בעל האתר');
    ok(in_array('disk_cap', array_column(http('GET', '/api.php?action=audit', ['user' => U_OWNER])['json']['audit'], 'action'), true), 'בעל האתר — הכול');
    $pid = (int)user_by_sub('u-page')['id'];
    $mine = http('GET', "/api.php?action=audit&person=$pid", ['user' => U_PAGE])['json']['audit'];
    ok($mine && !array_filter($mine, fn($a) => (int)$a['user_id'] !== $pid), 'סינון לפי אדם');
    eq([], array_filter(http('GET', '/api.php?action=audit&source=agent', ['user' => U_PAGE])['json']['audit'], fn($a) => $a['source'] !== 'agent'), 'סינון לפי מקור');
});

test('P1 מיגרציה 23: users מתמלאת מ-members (תפקיד כמו שהוא) ומ-audit בלי חבר (removed); אידמפוטנטי; members נשארת', function () {
    $f = sys_get_temp_dir() . '/kol-p1-mig-' . bin2hex(random_bytes(3)) . '.sqlite';
    $pdo = new PDO("sqlite:$f", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    try {
        $pdo->exec("CREATE TABLE members (id INTEGER PRIMARY KEY, sub TEXT UNIQUE, email TEXT, name TEXT NOT NULL DEFAULT '', role TEXT NOT NULL, added_by TEXT NOT NULL, added_at TEXT NOT NULL, last_seen TEXT, ai_quota INTEGER);
            CREATE TABLE audit (id INTEGER PRIMARY KEY, at TEXT NOT NULL, sub TEXT NOT NULL, name TEXT NOT NULL DEFAULT '', action TEXT NOT NULL, detail TEXT NOT NULL DEFAULT '');
            CREATE TABLE notices (id INTEGER PRIMARY KEY, role_to TEXT NOT NULL, kind TEXT NOT NULL, text TEXT NOT NULL, build_id INTEGER, by_name TEXT NOT NULL DEFAULT '', at TEXT NOT NULL, read_at TEXT, read_by TEXT);
            INSERT INTO members VALUES (1, '11', 'a@x.test', 'א', 'publisher', 'o', '2026-01-01T00:00:00Z', NULL, 5000), (2, NULL, 'b@x.test', '', 'editor', 'o', '2026-01-02T00:00:00Z', NULL, NULL);
            INSERT INTO audit (at, sub, name, action) VALUES ('2026-01-03T00:00:00Z', '11', 'א', 'login'), ('2026-01-04T00:00:00Z', '99', 'בעלים לשעבר', 'publish');");
        migrate_people($pdo);
        migrate_people($pdo);                                       // פעמיים — אותה תוצאה
        $u = $pdo->query('SELECT sub, email, role, status, ai_quota FROM users ORDER BY id')->fetchAll();
        eq([['sub' => '11', 'email' => 'a@x.test', 'role' => 'publisher', 'status' => 'active', 'ai_quota' => 5000],
            ['sub' => null, 'email' => 'b@x.test', 'role' => 'editor', 'status' => 'active', 'ai_quota' => null],
            ['sub' => '99', 'email' => null, 'role' => null, 'status' => 'removed', 'ai_quota' => null]], $u);
        eq(2, (int)$pdo->query('SELECT COUNT(*) FROM members')->fetchColumn(), 'members נשארת');
        eq(0, (int)$pdo->query('SELECT COUNT(*) FROM audit WHERE user_id IS NULL')->fetchColumn(), 'audit.user_id מולא');
    } finally {
        $pdo = null;
        @unlink($f);
    }
});
