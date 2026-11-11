<?php
// איסוף שגיאות: שרת ודפדפן, מה לא נרשם לעולם, גודל היומן.

declare(strict_types=1);

const MARK = 'ZZSECRETMARKER7391';

function log_dump(): string
{
    return json_encode(db()->query('SELECT * FROM dev_log')->fetchAll(), JSON_UNESCAPED_UNICODE);
}

test('יומן: בקשה רגילה נרשמת עם מזהה, פעולה, משתמש ומשך; המזהה חוזר בכותרת', function () {
    $r = http('GET', '/api.php?action=words', ['user' => U_EDITOR]);
    $req = $r['headers']['x-request-id'] ?? '';
    ok(preg_match('/^[0-9a-f]{10}$/', $req), 'כותרת X-Request-Id');
    $row = devlog_rows(['reqs' => [$req]])[0] ?? null;
    ok($row, 'שורת הבקשה ביומן');
    eq(['request', 'words', 'GET', 200, 'u-editor', 'info'], [$row['event'], $row['action'], $row['method'], $row['status'], $row['sub'], $row['level']]);
    ok($row['ms'] !== null, 'משך');
});

test('יומן: חריגה בשרת — 500 עם מזהה בהודעה, ושורה עם קובץ, שורה ומחסנית; הערך המסומן אינו ביומן', function () {
    db()->exec('ALTER TABLE words RENAME TO words_hidden');          // תקלה אמיתית: הטבלה איננה
    try {
        $r = http('POST', '/api.php?action=word_save&token=' . MARK . '&password=' . MARK, ['user' => U_EDITOR,
            'headers' => ['X-Dev-Token: ' . MARK, 'Authorization: Bearer ' . MARK, 'Cookie: session=' . MARK],
            'json' => ['word' => 'בדיקה', 'meaning' => MARK, 'password' => MARK, 'secret' => MARK]]);
    } finally {
        db()->exec('ALTER TABLE words_hidden RENAME TO words');
    }
    eq(500, $r['status']);
    $req = $r['headers']['x-request-id'];
    eq($req, $r['json']['req'], 'מזהה הבקשה בתשובה');
    ok(str_contains($r['json']['error'], "מזהה $req"), 'המשתמש רואה את המזהה: ' . $r['json']['error']);
    ok(!str_contains($r['body'], 'words'), 'פרטי התקלה לא נחשפים למשתמש');
    $rows = devlog_rows(['reqs' => [$req]]);
    $ex = array_values(array_filter($rows, fn($x) => $x['event'] === 'exception'))[0] ?? null;
    ok($ex, 'שורת החריגה');
    eq(['error', 'word_save', 'u-editor'], [$ex['level'], $ex['action'], $ex['sub']]);
    ok(str_contains($ex['message'], 'words'), 'ההודעה המלאה ביומן');
    ok(str_starts_with($ex['data']['file'], 'lib/') && $ex['data']['line'] > 0, 'קובץ ושורה');
    ok(count($ex['data']['trace']) >= 2 && str_contains(implode(' ', $ex['data']['trace']), 'word_save'), 'מחסנית');
    $line = array_values(array_filter($rows, fn($x) => $x['event'] === 'request'))[0] ?? null;
    eq([500, 'error'], [$line['status'] ?? 0, $line['level'] ?? '']);
    ok(!str_contains(log_dump(), MARK), 'הערך המסומן נמצא ביומן');
});

test('יומן: אזהרת PHP נרשמת עם קובץ ושורה, והבקשה ממשיכה', function () {
    $r = http('GET', '/api.php?action=words', ['user' => U_EDITOR, 'headers' => ['X-Test-Warn: password=' . MARK]]);
    eq(200, $r['status'], 'האזהרה לא הפילה את הבקשה');
    $w = array_values(array_filter(devlog_rows(['reqs' => [$r['headers']['x-request-id']]]), fn($x) => $x['event'] === 'php.error'))[0] ?? null;
    ok($w, 'שורת האזהרה');
    eq('warn', $w['level']);
    ok(str_contains($w['message'], 'test-warning') && str_contains($w['data']['file'], 'router.php'), 'הודעה וקובץ');
    ok(!str_contains(log_dump(), MARK), 'סוד בתוך הודעת אזהרה הוסתר');
});

test('יומן: שגיאת 4xx רגילה (ApiError) נרשמת כאזהרה עם ההודעה, בלי שורת חריגה', function () {
    $r = http('GET', '/api.php?action=members', ['user' => U_EDITOR]);
    $rows = devlog_rows(['reqs' => [$r['headers']['x-request-id']]]);
    eq(1, count($rows));
    eq(['warn', 403], [$rows[0]['level'], $rows[0]['status']]);
    ok($rows[0]['message'] !== '', 'ההודעה שהמשתמש ראה');
});

test('יומן: שגיאה מהדפדפן נרשמת עם המסך, הפעולה האחרונה, הדפדפן, גודל החלון והגרסה; חוזרת — נספרת בשורה אחת', function () {
    $b = ['kind' => 'error', 'message' => 'Cannot read properties of undefined (reading 5)', 'source' => 'http://x/assets/js/words.js?v=1', 'line' => 12, 'col' => 3,
        'stack' => "TypeError: x\n at words.js:12 token=" . MARK, 'page' => '#/words', 'last_action' => 'word_save', 'viewport' => '390×844', 'version' => '0.14',
        'password' => MARK, 'extra' => MARK];
    $o = ['user' => U_EDITOR, 'headers' => ['User-Agent: TestBrowser/1.0'], 'json' => $b];
    eq(200, http('POST', '/api.php?action=dev-client-error', $o)['status']);
    $o['json']['message'] = 'Cannot read properties of undefined (reading 9)';         // אותה תקלה, מספר אחר
    http('POST', '/api.php?action=dev-client-error', $o);
    http('POST', '/api.php?action=dev-client-error', $o);
    $rows = array_values(array_filter(devlog_rows(['category' => 'client']), fn($x) => str_contains($x['message'], 'Cannot read')));
    eq(1, count($rows), 'שורה אחת לאותה חתימה');
    $r = $rows[0];
    eq(3, $r['count'], 'מונה');
    eq(['#/words', 'word_save', '390×844', '0.14', 'assets/js/words.js', 12], [$r['data']['page'], $r['data']['last_action'], $r['data']['viewport'], $r['data']['version'], $r['data']['source'], $r['data']['line']]);
    eq(['TestBrowser/1.0', 'u-editor', 'error'], [$r['ua'], $r['sub'], $r['level']]);
    ok(!str_contains(log_dump(), MARK), 'סוד במחסנית או בשדה לא מוכר הוסתר');
    eq(401, http('POST', '/api.php?action=dev-client-error', ['json' => $b])['status'], 'בלי זהות — לא נרשם');
});

test('יומן: הסתרה — סיסמאות, אסימונים, מפתחות ונתיבים', function () {
    $s = devlog_scrub(['password' => 'abc', 'api_key' => 'k', 'note' => 'ok', 'nested' => ['csrf' => 'x', 'n' => 5],
        'msg' => 'failed token=abc123 and kmd_' . str_repeat('ab', 24) . ' in ' . KOL_ROOT . '/lib/files.php hash ' . str_repeat('0f', 32)
            . ' key ' . base64_encode(random_bytes(64))]);
    eq([DEVLOG_HIDDEN, DEVLOG_HIDDEN, 'ok', DEVLOG_HIDDEN, 5], [$s['password'], $s['api_key'], $s['note'], $s['nested']['csrf'], $s['nested']['n']]);
    ok(!preg_match('/abc123|abab|0f0f|' . preg_quote(KOL_ROOT, '/') . '/', $s['msg']), $s['msg']);
    ok(str_contains($s['msg'], 'lib/files.php'), 'הנתיב היחסי נשאר');
    $secrets = KOL_DATA_DIR . '/secrets.json';
    ok(!is_file($secrets) || !str_contains(log_dump(), (string)file_get_contents($secrets)), 'תוכן secrets.json אינו ביומן');
});

test('יומן: חסום ל-5,000 שורות ול-30 יום, ומתנקה לבד — קודם שורות רגילות, השגיאות נשארות', function () {
    $pdo = db();
    $pdo->beginTransaction();
    $ins = $pdo->prepare("INSERT INTO dev_log (at, level, category, event, req) VALUES (?, ?, 'api', 'fill', 'fill')");
    $ins->execute([gmdate('Y-m-d\TH:i:s\Z', time() - 31 * 86400), 'error']);                  // ישן מ-30 יום
    for ($i = 0; $i < DEVLOG_MAX_ROWS + 300; $i++) $ins->execute([now_iso(), $i < 40 ? 'error' : 'info']);
    $pdo->commit();
    setting_set('devlog_clean_at', '0');
    http('GET', '/api.php?action=words', ['user' => U_EDITOR]);                                // בקשה רגילה מפעילה את הניקוי
    ok((int)$pdo->query('SELECT COUNT(*) FROM dev_log')->fetchColumn() <= DEVLOG_MAX_ROWS, 'עד 5,000 שורות');
    eq(0, (int)$pdo->query("SELECT COUNT(*) FROM dev_log WHERE at < '" . gmdate('Y-m-d\TH:i:s\Z', time() - 30 * 86400) . "'")->fetchColumn(), 'אין שורות ישנות מ-30 יום');
    eq(40, (int)$pdo->query("SELECT COUNT(*) FROM dev_log WHERE event = 'fill' AND level = 'error'")->fetchColumn(), 'השגיאות נשארו');
    $run = dev_last_run('log_clean');
    ok($run && $run['ok'], 'הריצה נרשמה כשירות רקע');
    $pdo->exec("DELETE FROM dev_log WHERE event = 'fill'");
});

test('יומן: תקלה במנגנון הרישום עצמו אינה מפילה את הבקשה', function () {
    db()->exec('ALTER TABLE dev_log RENAME TO dev_log_hidden');
    try {
        $r = http('GET', '/api.php?action=words', ['user' => U_EDITOR]);
        $bad = http('GET', '/api.php?action=members', ['user' => U_EDITOR]);
    } finally {
        db()->exec('ALTER TABLE dev_log_hidden RENAME TO dev_log');
    }
    eq(200, $r['status']);
    ok(is_array($r['json']['words'] ?? null), 'התשובה שלמה');
    eq(403, $bad['status'], 'גם שגיאה רגילה חוזרת כרגיל');
});
