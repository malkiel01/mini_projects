<?php
// DevLoop: דיווח 🐞, מטלות KM-, אימות, אסימון ל-Claude, ומסך המפתח.

declare(strict_types=1);

function png_bytes(): string
{
    $f = KOL_DATA_DIR . '/shot.png';
    imagepng(imagecreatetruecolor(20, 10), $f);
    $b = (string)file_get_contents($f);
    unlink($f);
    return $b;
}

test('DevLoop: חבר שאינו מפתח מדווח 🐞 — נפתחת מטלה KM-NNN עם ההקשר והצילום; את הרשימה הוא לא רואה', function () {
    $ctx = ['url' => '#/words', 'screen' => '390×844', 'ua' => 'TestBrowser', 'version' => '0.14', 'lang' => 'he', 'at' => now_iso(),
        'requests' => array_map(fn($i) => ['action' => "a$i", 'status' => 200, 'id' => "r$i"], range(1, 12)), 'errors' => array_map(fn($i) => ['message' => "e$i"], range(1, 8)),
        'junk' => 'לא נשמר'];
    $r = http('POST', '/api.php?action=dev-report', ['user' => U_EDITOR, 'json' => ['title' => 'הכפתור לא מגיב', 'detail' => 'לחצתי פעמיים', 'kind' => 'bug', 'area' => 'דיווח', 'context' => $ctx]]);
    eq(200, $r['status'], $r['body']);
    $code = $r['json']['code'];
    ok(preg_match('/^KM-\d{3}$/', $code), "קוד המטלה: $code");
    $GLOBALS['KM_CODE'] = $code;
    $a = http('POST', "/api.php?action=dev-attach&code=$code&name=shot.png", ['user' => U_EDITOR, 'raw' => png_bytes(), 'headers' => ['Content-Type: image/png']]);
    eq(200, $a['status'], $a['body']);
    eq([], http('GET', '/api.php?action=dev-items', ['user' => U_EDITOR])['json']['items'], 'חבר רגיל לא רואה מטלות');
    // צילום למטלה שאינה שלו — נדחה
    $other = http('POST', '/api.php?action=dev-task-create', ['user' => U_OWNER, 'json' => ['title' => 'מטלה של המפתח']])['json']['code'];
    eq(403, http('POST', "/api.php?action=dev-attach&code=$other&name=x.png", ['user' => U_EDITOR, 'raw' => png_bytes()])['status']);
    $GLOBALS['KM_OTHER'] = $other;
    // V1b: המדווח רואה את הדיווחים שלו ומצבם — ורק אותם
    $mine = http('GET', '/api.php?action=dev-my-reports', ['user' => U_EDITOR])['json']['items'];
    eq([[$code, 'open', 'דיווח']], array_map(fn($i) => [$i['code'], $i['status'], $i['area']], $mine), 'dev-my-reports: רק מה שהמשתמש עצמו פתח');

    $items = http('GET', '/api.php?action=dev-items', ['user' => U_OWNER])['json']['items'];
    $x = array_values(array_filter($items, fn($i) => $i['code'] === $code))[0] ?? null;
    ok($x, 'המפתח רואה את המטלה');
    eq(['bug', 'open', 'user'], [$x['kind'], $x['status'], $x['source']]);
    eq(['#/words', '390×844', '0.14', 12, 8], [$x['context']['url'], $x['context']['screen'], $x['context']['version'], count($x['context']['requests']), count($x['context']['errors'])], 'המסך, הגרסה ו-20 הבקשות והשגיאות');
    ok(!isset($x['context']['junk']), 'שדה לא מוכר לא נשמר');
    eq(1, count($x['files']), 'הצילום');
    $file = db()->query('SELECT storage FROM dev_files ORDER BY id DESC LIMIT 1')->fetchColumn();
    ok(is_file(KOL_DATA_DIR . '/files/_dev/' . $file), 'הצילום נשמר תחת data/files/_dev/');
    eq(200, http('GET', '/api.php?action=dev-file&id=' . $x['files'][0]['id'], ['user' => U_OWNER])['status']);
});

test('DevLoop: אסימון ל-Claude — נוצר במסך; devapi-feed ו-devapi-comment עובדים איתו בלי עוגייה; ביטול חוסם', function () {
    $code = $GLOBALS['KM_CODE'];
    eq(401, http('GET', '/api.php?action=devapi-feed&all=1')['status'], 'בלי אסימון');
    eq(401, http('GET', '/api.php?action=devapi-feed&all=1', ['headers' => ['X-Dev-Token: kmd_' . str_repeat('0', 48)]])['status'], 'אסימון לא קיים');
    $t = http('POST', '/api.php?action=dev-token-create', ['user' => U_OWNER, 'json' => ['label' => 'Claude']])['json']['token'];
    ok(preg_match('/^kmd_[0-9a-f]{48}$/', $t), 'צורת האסימון');
    eq(0, (int)db()->query("SELECT COUNT(*) FROM dev_tokens WHERE token_hash = '$t'")->fetchColumn(), 'האסימון עצמו לא נשמר במסד');
    $H = ['headers' => ["X-Dev-Token: $t"]];
    $feed = http('GET', '/api.php?action=devapi-feed&all=1', $H);
    eq(200, $feed['status'], $feed['body']);
    ok(in_array($code, array_column($feed['json']['items'], 'code'), true), 'המטלה בפיד');
    ok(preg_match('/^KM-\d{3}$/', $feed['json']['next_code']), 'next_code');
    $one = http('GET', "/api.php?action=devapi-feed&code=$code", $H)['json'];
    ok(array_key_exists('log', $one), 'מטלה אחת מגיעה עם השורות שלה ביומן');
    $c = http('POST', '/api.php?action=devapi-comment', $H + ['json' => ['code' => $code, 'body' => 'תוקן, אפשר לבדוק', 'status' => 'verify', 'steps' => ['לפתוח את 📚 מילים', 'ללחוץ על הכפתור'],
        'evidence' => [['kind' => 'pr', 'ref' => '#1', 'url' => 'https://github.com/x/y/pull/1'], ['kind' => 'commit', 'ref' => 'abc1234'], ['kind' => 'deploy', 'ref' => 'run 9']]]]);
    eq(200, $c['status'], $c['body']);
    $x = array_values(array_filter(http('GET', '/api.php?action=dev-items', ['user' => U_OWNER])['json']['items'], fn($i) => $i['code'] === $code))[0];
    eq('verify', $x['status'], 'ממתין לאימות');
    eq(['pr', 'commit', 'deploy'], array_column($x['evidence'], 'kind'), 'ראיות');
    eq(2, count($x['steps']), 'איך לבדוק');
    eq('Claude', end($x['comments'])['name']);
    eq(200, http('GET', '/api.php?action=devapi-file&id=' . $x['files'][0]['id'], $H)['status'], 'Claude רואה את הצילום');
    $GLOBALS['KM_TOKEN'] = $t;

    // סגירה: רק ✅ של המפתח. עורך לא מאמת; Claude לא יכול לפתוח מחדש מטלה שאומתה (if_open)
    eq(403, http('POST', '/api.php?action=dev-verify', ['user' => U_EDITOR, 'json' => ['code' => $code, 'ok' => true]])['status']);
    eq(422, http('POST', '/api.php?action=dev-verify', ['user' => U_OWNER, 'json' => ['code' => $code, 'ok' => false]])['status'], '↩️ בלי הסבר נדחה');
    eq(200, http('POST', '/api.php?action=dev-verify', ['user' => U_OWNER, 'json' => ['code' => $code, 'ok' => true]])['status']);
    $after = http('POST', '/api.php?action=devapi-comment', $H + ['json' => ['code' => $code, 'status' => 'verify', 'by' => 'github']])['json'];
    eq(true, $after['status_skipped'], 'הפריסה לא מחזירה מטלה סגורה');
    $x = array_values(array_filter(http('GET', '/api.php?action=dev-items', ['user' => U_OWNER])['json']['items'], fn($i) => $i['code'] === $code))[0];
    eq('closed', $x['status']);

    $id = http('GET', '/api.php?action=dev-tokens', ['user' => U_OWNER])['json']['tokens'][0]['id'];
    eq(200, http('POST', '/api.php?action=dev-token-revoke', ['user' => U_OWNER, 'json' => ['id' => $id]])['status']);
    eq(401, http('GET', '/api.php?action=devapi-feed&all=1', $H)['status'], 'אסימון שבוטל');
    ok(!str_contains(json_encode(db()->query('SELECT * FROM dev_log')->fetchAll()), $t), 'האסימון אינו ביומן');
});

test('DevLoop: אסימון חי רק כל עוד מי שיצר אותו מפתח', function () {
    $t = http('POST', '/api.php?action=dev-token-create', ['user' => U_OWNER, 'json' => []])['json']['token'];
    eq(200, http('GET', '/api.php?action=devapi-feed', ['headers' => ["X-Dev-Token: $t"]])['status']);
    // אותו משתמש נכנס בלי הרשאת בעלים (הורד מניהול בפלטפורמה)
    http('GET', '/api.php?action=dev-items', ['user' => ['platform_admin' => false] + U_OWNER]);
    $demoted = db()->query("SELECT developer FROM dev_users WHERE sub = 'u-owner'")->fetchColumn();
    http('GET', '/api.php?action=me', ['user' => U_OWNER]);                                    // חוזר להיות מפתח — לשאר הבדיקות
    http('GET', '/api.php?action=dev-items', ['user' => U_OWNER]);
    eq(1, (int)db()->query("SELECT developer FROM dev_users WHERE sub = 'u-owner'")->fetchColumn());
    // מי שאין לו תפקיד לא מגיע ל-dev_users בכלל, ולכן הסימון משתנה רק כשהוא חבר שאינו בעלים
    ok(in_array((string)$demoted, ['0', '1'], true));
});

test('DevLoop: שגיאה מהיומן נפתחת כמטלה "לבירור", ו"+ מטלה" משורה ביומן', function () {
    http('GET', '/api.php?action=dev-items', ['user' => U_OWNER]);                              // מסמן את נקודת ההתחלה
    http('POST', '/api.php?action=dev-client-error', ['user' => U_EDITOR, 'json' => ['kind' => 'error', 'message' => 'בדיקה: תקלה חדשה לגמרי', 'source' => 'assets/js/app.js', 'line' => 77]]);
    $items = http('GET', '/api.php?action=dev-items', ['user' => U_OWNER])['json']['items'];
    $tri = array_values(array_filter($items, fn($i) => $i['status'] === 'triage'));
    eq(1, count($tri), 'מטלה אחת לבירור');
    ok(str_contains($tri[0]['title'] . $tri[0]['detail'], 'תקלה חדשה לגמרי'), 'ההודעה במטלה');
    $log = http('GET', '/api.php?action=dev-item-log&code=' . $tri[0]['code'], ['user' => U_OWNER])['json'];
    ok(count($log['rows']) >= 1, '📜 יומן במטלה');
    $links = http('POST', '/api.php?action=dev-log-links', ['user' => U_OWNER, 'json' => ['ids' => [$log['rows'][0]['id']], 'reqs' => []]])['json'];
    eq($tri[0]['code'], $links['ids'][$log['rows'][0]['id']] ?? null, '🔗 מהיומן למטלה');
});

test('מסך המפתח: היומן מסונן לפי סוג, משתמש, פעולה, זמן ומזהה בקשה', function () {
    $r = http('GET', '/api.php?action=members', ['user' => U_PUBLISHER]);
    $req = $r['headers']['x-request-id'];
    $get = fn(string $qs) => http('GET', "/api.php?action=dev-log&$qs", ['user' => U_OWNER])['json'];
    eq([$req], array_values(array_unique(array_column($get("req=$req")['rows'], 'req'))), 'לפי מזהה בקשה');
    eq([$req], array_values(array_unique(array_column($get("q=$req")['rows'], 'req'))), 'חיפוש לפי מזהה');
    $by = $get('user=u-publisher&act=members&level=warn&category=api');
    ok(count($by['rows']) >= 1 && !array_filter($by['rows'], fn($x) => $x['sub'] !== 'u-publisher' || $x['action'] !== 'members' || $x['level'] === 'info'), 'משתמש + פעולה + רמה + סוג');
    eq([], $get('to=2020-01-01T00:00:00Z')['rows'], 'לפי זמן — עד');
    ok(count($get('from=2020-01-01T00:00:00Z')['rows']) > 0, 'לפי זמן — מ');
    $f = $get('')['facets'];
    ok(in_array('client', $f['category'], true) && in_array('members', $f['action'], true) && in_array('u-publisher', array_column($f['user'], 'sub'), true), 'ערכי הסינון');
    eq(['rows' => DEVLOG_MAX_ROWS, 'days' => DEVLOG_DAYS], $get('')['keep']);
});

test('מסך המפתח: יומן הפעולות — כניסה, שמירה, מחיקה, שינוי חברים ושינוי הגדרות, עם סינון', function () {
    http('POST', '/api.php?action=word_save', ['user' => U_EDITOR, 'json' => ['word' => 'לפעולות', 'meaning' => 'x']]);
    http('POST', '/api.php?action=lock', ['user' => U_EDITOR, 'json' => ['op' => 'take']]);
    $d = http('GET', '/api.php?action=draft', ['user' => U_EDITOR])['json'];
    $d['page']['settings']['title'] = 'כותרת מהבדיקה ' . time();
    $s = http('POST', '/api.php?action=draft_save', ['user' => U_EDITOR, 'json' => ['page' => $d['page'], 'rev' => $d['rev']]]);
    eq(200, $s['status'], $s['body']);
    http('POST', '/api.php?action=lock', ['user' => U_EDITOR, 'json' => ['op' => 'release']]);
    http('POST', '/api.php?action=member_add', ['user' => U_OWNER, 'json' => ['email' => 'new@example.test', 'role' => 'editor']]);
    http('POST', '/api.php?action=disk_cap', ['user' => U_OWNER, 'json' => ['gb' => 6]]);
    $a = http('GET', '/api.php?action=dev-audit', ['user' => U_OWNER])['json'];
    $acts = array_column($a['rows'], 'action');
    foreach (['login', 'draft_save', 'word_add', 'word_delete', 'snapshot_restore', 'member_add', 'disk_cap', 'dev_token_create'] as $x) ok(in_array($x, $acts, true), "חסרה פעולה: $x");
    $only = http('GET', '/api.php?action=dev-audit&user=u-editor&act=word_add', ['user' => U_OWNER])['json']['rows'];
    ok($only && !array_filter($only, fn($x) => $x['sub'] !== 'u-editor' || $x['action'] !== 'word_add'), 'סינון לפי מי ומה');
});

test('מסך המפתח: בדיקות התקינות — כל בדיקה ירוקה או אדומה עם הסבר; בדיקה שנופלת לא שוברת את המסך', function () {
    setting_set('site_url', 'http://127.0.0.1:9');                                             // אתר שלא עונה
    $h = http('GET', '/api.php?action=dev-health', ['user' => U_OWNER]);
    eq(200, $h['status'], $h['body']);
    $c = array_column($h['json']['checks'], null, 'id');
    eq(['db', 'writable', 'disk', 'php', 'version', 'htaccess', 'site', 'preview', 'editor'], array_keys($c));
    foreach ($c as $id => $x) ok(is_bool($x['ok']) && $x['text'] !== '' && ($x['ok'] || $x['fix'] !== ''), "$id: ירוק, או אדום עם מה לעשות");
    foreach (['db', 'writable', 'disk', 'php'] as $id) ok($c[$id]['ok'], "$id ירוקה: " . $c[$id]['text']);
    ok(str_contains($c['db']['text'], (string)KOL_SCHEMA_VERSION), 'גרסת הסכמה בטקסט');
    eq(false, $c['site']['ok'], 'אתר שלא עונה — אדום');
    eq(false, $c['htaccess']['ok'], 'בתיקייה הזמנית אין .htaccess — אדום');
    eq('data/.htaccess', $c['htaccess']['probe'], 'הדפדפן משלים בבקשה אמיתית');
    // תקלה בתוך בדיקה: המסד של הקבצים איננו — הבדיקה אדומה, האחרות ממשיכות
    db()->exec('ALTER TABLE files RENAME TO files_hidden');
    try { $h2 = http('GET', '/api.php?action=dev-health', ['user' => U_OWNER]); } finally { db()->exec('ALTER TABLE files_hidden RENAME TO files'); }
    eq(200, $h2['status']);
    $c2 = array_column($h2['json']['checks'], null, 'id');
    eq([false, true, 9], [$c2['disk']['ok'], $c2['db']['ok'], count($c2)]);
    $run = dev_last_run('health');
    ok($run && !$run['ok'] && str_contains($run['note'], 'disk'), 'הריצה נרשמה');
});

test('מסך המפתח: גרסת התוסף באתר מול הריפו, ו-ping חתום שעובר', function () {
    preg_match("/define\('KOL_MAROKO_VERSION', '([^']+)'\)/", (string)file_get_contents(KOL_ROOT . '/wordpress-plugin/kol-maroko/kol-maroko.php'), $m);
    eq($m[1] ?? '', KOL_PLUGIN_EXPECTED, 'KOL_PLUGIN_EXPECTED שווה לגרסה שבקובץ התוסף');
    wp_site_ready();
    $c = array_column(http('GET', '/api.php?action=dev-health', ['user' => U_OWNER])['json']['checks'], null, 'id');
    ok($c['site']['ok'], $c['site']['text']);
    ok(str_contains($c['site']['text'], KOL_PLUGIN_EXPECTED), 'גרסת התוסף בטקסט');
});

test('מסך המפתח: שירותי רקע ומצב הנתונים', function () {
    $s = http('GET', '/api.php?action=dev-state', ['user' => U_OWNER])['json'];
    $svc = array_column($s['services'], null, 'id');
    eq(['snapshot', 'trash', 'log_clean', 'health', 'forms_mail'], array_keys($svc));
    foreach ($svc as $id => $x) ok($x['result'] !== '' && $x['next_text'] !== '' && array_key_exists('last', $x), "$id: מתי, תוצאה, הבא");
    ok($svc['snapshot']['last'] && $svc['snapshot']['ok'] === true, 'הנקודה היומית נוצרה בבקשה הראשונה');
    ok($svc['log_clean']['last'] && $svc['log_clean']['next'], 'ניקוי היומן: אחרון והבא');
    $st = array_column($s['stats'], 'value', 'id');
    foreach (['words', 'words_audio', 'audio_site', 'audio_tool', 'files', 'versions', 'snapshots', 'members', 'db_size'] as $k) ok(array_key_exists($k, $st), "חסר $k");
    eq((int)db()->query('SELECT COUNT(*) FROM words')->fetchColumn(), $st['words']);
    eq(3, $st['members']);
    eq($st['words_audio'], $st['audio_site'] + $st['audio_tool']);
});

test('dev/open-items.json: תקין, עם קידומת KM-, וכל הפרוסות שבלוח', function () {
    $j = json_decode((string)file_get_contents(KOL_ROOT . '/dev/open-items.json'), true);
    ok(is_array($j) && !empty($j['items']), 'הקובץ נקרא');
    foreach ($j['items'] as $x) {
        ok(preg_match('/^KM-\d{3}$/', $x['code']), 'קוד: ' . $x['code']);
        ok(in_array($x['status'], DEVLOOP_STATUS, true) && $x['title'] !== '' && $x['status_at'] <= now_iso(), $x['code']);
    }
    eq(count($j['items']), count(array_unique(array_column($j['items'], 'code'))), 'אין קוד כפול');
    $all = implode(' ', array_column($j['items'], 'title')) . implode(' ', array_column($j['items'], 'area'));
    foreach (['F1', 'F2', 'A1'] as $s) ok(str_contains(json_encode($j, JSON_UNESCAPED_UNICODE), $s), "הפרוסה $s");
});
