<?php
/**
 * פרוסה X1 §2.1 — ✨ עוזר הבינה על קבצי הפרויקט (lib/ai.php). בלי קריאה אמיתית ל-API: $GLOBALS['kol_ai_fake'] מחזיר תשובה מדומה
 * במבנה של /v1/messages, ובודק מה נשלח.
 */

declare(strict_types=1);

const X1_ED = ['sub' => 'u-editor', 'name' => 'עורך בדיקה', 'role' => 'editor'];

/** תשובה מדומה: edits + summary, ובודק את הבקשה ($seen). */
function x1_fake(array $edits, string $summary = 'בוצע', ?array &$seen = null, string $stop = 'end_turn'): void
{
    $GLOBALS['kol_ai_fake'] = function (array $body) use ($edits, $summary, &$seen, $stop) {
        $seen = $body;
        return ['model' => $body['model'], 'stop_reason' => $stop, 'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
            'content' => [['type' => 'text', 'text' => json_encode(['summary' => $summary, 'edits' => $edits], JSON_UNESCAPED_UNICODE)]]];
    };
}

/** קובץ בפרויקט (יוצר או מחליף) — מחזיר את השורה. */
function x1_file(string $path, string $content): array
{
    pf_seed(X1_ED);
    $r = pf_by_path($path);
    if ($r) return pf_tx(fn() => pf_put($r, $content, X1_ED, 'בדיקה'));
    return pf_tx(fn() => pf_insert($path, 'file', $content, X1_ED, null, 'בדיקה'));
}

function x1_hist(string $path): array
{
    $st = db()->prepare('SELECT h.* FROM pf_history h JOIN pf_files f ON f.id = h.file_id WHERE f.path = ? AND f.deleted_at IS NULL ORDER BY h.id');
    $st->execute([$path]);
    return $st->fetchAll();
}

test('X1 ✨: הסכימה — edits {path, old, new, create}, בלי מחיקה ובלי שינוי שם; הקבצים נשלחים כמו שהם', function () {
    $s = ai_schema();
    eq(['summary', 'edits'], $s['required']);
    eq(['path', 'old', 'new', 'create'], array_keys($s['properties']['edits']['items']['properties']));
    eq(false, $s['properties']['edits']['items']['additionalProperties']);
    x1_file('x1/a.html', "<main>\n  <h1>כותרת ישנה</h1>\n</main>\n");
    x1_fake([], 'אין', $seen);
    $r = ai_suggest_files(X1_ED, ['prompt' => 'תחליף את הכותרת', 'paths' => ['x1/a.html']]);
    $msg = $seen['messages'][0]['content'];
    ok(str_contains($msg, "<main>\n  <h1>כותרת ישנה</h1>\n</main>\n"), 'התוכן נשלח בייט בבייט');
    ok(str_contains($msg, 'תחליף את הכותרת'));
    eq('json_schema', $seen['output_config']['format']['type']);
    eq(ai_model(), $seen['model'], 'המודל — AI_MODEL / ההגדרה');
    eq([], $r['files']);
    eq(36, strlen($r['request_id']));
});

test('X1 ✨: הצעה → diff, בלי כתיבה; "החל" — גרסה אחת לכל קובץ עם "✨ <הבקשה>"; Ctrl+Z = הגרסה הקודמת בהיסטוריה', function () {
    $r0 = x1_file('x1/b.html', "<h1>א</h1>\n<p>ב</p>\n<p>ג</p>\n");
    $h0 = count(x1_hist('x1/b.html'));
    x1_fake([['path' => 'x1/b.html', 'old' => '<h1>א</h1>', 'new' => '<h1>שלום</h1>'], ['path' => 'x1/b.html', 'old' => '<p>ג</p>', 'new' => '<p>ד</p>'],
        ['path' => 'x1/new.css', 'create' => ".x { color: red; }\n"]]);
    $s = ai_suggest_files(X1_ED, ['prompt' => 'כותרת חדשה', 'paths' => ['x1/b.html']]);
    eq(2, count($s['files']));
    $f = array_column($s['files'], null, 'path');
    eq("<h1>שלום</h1>\n<p>ב</p>\n<p>ד</p>\n", $f['x1/b.html']['new']);
    eq("<h1>א</h1>\n<p>ב</p>\n<p>ג</p>\n", $f['x1/b.html']['old']);
    eq('create', $f['x1/new.css']['kind']);
    eq((int)$r0['rev'], (int)pf_by_path('x1/b.html')['rev'], 'ההצעה לא כתבה');
    eq(null, pf_by_path('x1/new.css'), 'ההצעה לא יצרה');
    $a = ai_apply_files(X1_ED, ['request_id' => $s['request_id']]);
    eq(2, count($a['applied']));
    eq("<h1>שלום</h1>\n<p>ב</p>\n<p>ד</p>\n", pf_blob_get(pf_by_path('x1/b.html')['sha']));
    $h = x1_hist('x1/b.html');
    eq($h0 + 1, count($h), 'שתי עריכות באותו קובץ = גרסה אחת');
    eq('✨ כותרת חדשה', end($h)['note']);
    eq('✨ כותרת חדשה', x1_hist('x1/new.css')[0]['note']);
    // Ctrl+Z בהיסטוריה: הגרסה הקודמת שמורה ומשוחזרת
    $prev = $h[count($h) - 2];
    eq("<h1>א</h1>\n<p>ב</p>\n<p>ג</p>\n", pf_blob_get($prev['sha']));
    eq(404, throws(fn() => ai_apply_files(X1_ED, ['request_id' => $s['request_id']])), 'אותה הצעה פעם שנייה — לא');
});

test('X1 ✨: old שלא נמצא או נמצא פעמיים — "לא הוחלה" עם הסיבה; השאר מוחל', function () {
    x1_file('x1/c.html', "<p>x</p>\n<p>x</p>\n<p>y</p>\n");
    x1_fake([['path' => 'x1/c.html', 'old' => '<p>x</p>', 'new' => '<p>z</p>'], ['path' => 'x1/c.html', 'old' => 'לא קיים', 'new' => 'q'],
        ['path' => 'x1/c.html', 'old' => '<p>y</p>', 'new' => '<p>w</p>'], ['path' => 'x1/c.html', 'old' => '', 'new' => 'q']]);
    $s = ai_suggest_files(X1_ED, ['prompt' => 'שינויים', 'paths' => ['x1/c.html']]);
    $why = array_column($s['rejected'], 'why', 'i');
    ok(str_contains($why[0], '2 פעמים'), 'כפול');
    ok(str_contains($why[1], 'לא נמצא'), 'לא נמצא');
    ok(isset($why[3]), 'old ריק');
    eq([false, false, true, false], array_column($s['edits'], 'ok'));
    eq("<p>x</p>\n<p>x</p>\n<p>w</p>\n", $s['files'][0]['new']);
});

test('X1 ✨: קובץ מחוץ לפרויקט, media/, .., נתיב מוחלט, וקובץ קיים ב-create — נדחים; שום דבר לא נכתב', function () {
    x1_file('x1/d.html', "<p>d</p>\n");
    $before = db()->query('SELECT COUNT(*) FROM pf_history')->fetchColumn();
    x1_fake([['path' => '../api.php', 'create' => '<?php system("id");'], ['path' => '/etc/passwd', 'old' => 'root', 'new' => 'x'],
        ['path' => 'media/a.mp3', 'create' => 'x'], ['path' => 'x1/../../db.php', 'old' => 'a', 'new' => 'b'], ['path' => 'x1/d.html', 'create' => 'דריסה'],
        ['path' => 'x1/nope.html', 'old' => 'a', 'new' => 'b']]);
    $s = ai_suggest_files(X1_ED, ['prompt' => 'פריצה', 'paths' => ['x1/d.html']]);
    eq(6, count($s['rejected']));
    eq([], $s['files']);
    eq(409, throws(fn() => ai_apply_files(X1_ED, ['request_id' => $s['request_id']])));
    eq($before, db()->query('SELECT COUNT(*) FROM pf_history')->fetchColumn(), 'לא נכתבה גרסה');
    eq("<p>d</p>\n", pf_blob_get(pf_by_path('x1/d.html')['sha']));
    eq(400, throws(fn() => ai_suggest_files(X1_ED, ['prompt' => 'abc', 'paths' => ['../x']])), 'הקשר מחוץ לפרויקט');
    eq(400, throws(fn() => ai_suggest_files(X1_ED, ['prompt' => 'abc', 'paths' => ['media/x.mp3']])), 'הקשר מ-media/');
});

test('X1 ✨: קובץ שהשתנה מאז ההצעה — 409 ושום דבר לא נכתב (לעולם לא דריסה שקטה); הצעה של אחר — לא', function () {
    x1_file('x1/e.html', "<p>1</p>\n");
    x1_file('x1/e2.html', "<p>2</p>\n");
    x1_fake([['path' => 'x1/e.html', 'old' => '1', 'new' => 'one'], ['path' => 'x1/e2.html', 'old' => '2', 'new' => 'two']]);
    $s = ai_suggest_files(X1_ED, ['prompt' => 'מספרים', 'paths' => ['x1/e.html', 'x1/e2.html']]);
    eq(404, throws(fn() => ai_apply_files(['sub' => 'u-publisher', 'name' => 'אחר', 'role' => 'publisher'], ['request_id' => $s['request_id']])), 'הצעה של משתמש אחר');
    x1_file('x1/e2.html', "<p>2</p>\n<p>יד</p>\n");          // שינוי ביד אחרי ההצעה
    eq(409, throws(fn() => ai_apply_files(X1_ED, ['request_id' => $s['request_id']])));
    eq("<p>1</p>\n", pf_blob_get(pf_by_path('x1/e.html')['sha']), 'גם הקובץ שלא השתנה — לא נכתב');
    eq("<p>2</p>\n<p>יד</p>\n", pf_blob_get(pf_by_path('x1/e2.html')['sha']));
});

test('X1 ✨: "החל" על חלק מהעריכות (only) — רק המסומנות', function () {
    x1_file('x1/f.html', "<p>a</p><p>b</p>\n");
    x1_fake([['path' => 'x1/f.html', 'old' => '<p>a</p>', 'new' => '<p>A</p>'], ['path' => 'x1/f.html', 'old' => '<p>b</p>', 'new' => '<p>B</p>']]);
    $s = ai_suggest_files(X1_ED, ['prompt' => 'גדולות', 'paths' => ['x1/f.html']]);
    ai_apply_files(X1_ED, ['request_id' => $s['request_id'], 'only' => [1]]);
    eq("<p>a</p><p>B</p>\n", pf_blob_get(pf_by_path('x1/f.html')['sha']));
});

test('X1 ✨: תקרת ההקשר — מעל — "צמצם את ההקשר" (413), בלי קריאה לבינה', function () {
    setting_set('ai_context_max', '2048');
    x1_file('x1/big.html', str_repeat('<p>שורה</p>' . "\n", 400));
    $called = false;
    $GLOBALS['kol_ai_fake'] = function () use (&$called) { $called = true; return []; };
    try {
        $code = 0;
        try { ai_suggest_files(X1_ED, ['prompt' => 'abc', 'paths' => ['x1/big.html']]); } catch (ApiError $e) { $code = $e->getCode(); $m = $e->getMessage(); }
        eq(413, $code);
        ok(str_contains($m, 'צמצם את ההקשר'));
        eq(false, $called);
    } finally {
        db()->exec("DELETE FROM settings WHERE k = 'ai_context_max'");
    }
});

test('X1 ✨: קובץ מערכת (js/kol-data.js) — מותר ומסומן', function () {
    x1_file('js/kol-data.js', "/* kol-data 1.1.0 */\nvar a = 1;\n");
    x1_fake([['path' => 'js/kol-data.js', 'old' => 'var a = 1;', 'new' => 'var a = 2;']]);
    $s = ai_suggest_files(X1_ED, ['prompt' => 'שנה', 'paths' => ['js/kol-data.js']]);
    eq(true, $s['files'][0]['system']);
    $a = ai_apply_files(X1_ED, ['request_id' => $s['request_id']]);
    eq(['js/kol-data.js'], $a['system']);
});

test('X1 ✨: המילים — דרך N1: "ישתנה 1", ו"החל" = words_apply (הטבלה משתנה, השאר לא)', function () {
    if (function_exists('n1_seed')) n1_seed(5); else return;
    n1_kol(true);
    $json = words_json();
    $j = json_decode($json, true);
    $id = $j['items'][0]['id'];
    $oldMeaning = $j['items'][0]['meaning'];
    $old = json_encode($oldMeaning, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ok(substr_count($json, $old) >= 1);
    // מחרוזת ייחודית: השורה של הפירוש של המילה הראשונה
    $line = '"meaning": ' . $old;
    $pos = strpos($json, '"id": ' . $id . ',');
    $snippet = substr($json, $pos, strpos($json, $line, $pos) + strlen($line) - $pos);
    x1_fake([['path' => 'data/words.json', 'old' => $snippet, 'new' => str_replace($line, '"meaning": "<p>פירוש מתוקן</p>"', $snippet)]]);
    $s = ai_suggest_files(X1_ED, ['prompt' => 'תתקן את הפירוש', 'paths' => ['data/words.json']]);
    eq('words', $s['files'][0]['kind']);
    eq(1, $s['files'][0]['plan']['counts']['changed'], 'ישתנה 1');
    eq($json, words_json(), 'ההצעה לא שינתה את הטבלה');
    ai_apply_files(X1_ED, ['request_id' => $s['request_id']]);
    $st = db()->prepare('SELECT meaning FROM words WHERE id = ?');
    $st->execute([$id]);
    eq('<p>פירוש מתוקן</p>', $st->fetchColumn());
});

test('X1 ✨: המילים — קובץ שבור מהבינה נדחה עם הסיבה של N1, והטבלה לא משתנה', function () {
    if (!function_exists('n1_seed')) return;
    $json = words_json();
    x1_fake([['path' => 'data/words.json', 'old' => '"items"', 'new' => '"itemz"']]);
    $s = ai_suggest_files(X1_ED, ['prompt' => 'שבור', 'paths' => ['data/words.json']]);
    eq([], $s['files']);
    ok(str_contains($s['rejected'][0]['why'], 'המילים'));
    eq($json, words_json());
});

test('X1 ✨: סירוב / תשובה שנקטעה / מבנה לא צפוי — שגיאה ברורה, ונרשם ב-ai_usage', function () {
    x1_file('x1/g.html', "<p>g</p>\n");
    x1_fake([], '', $seen, 'refusal');
    eq(422, throws(fn() => ai_suggest_files(X1_ED, ['prompt' => 'abc', 'paths' => ['x1/g.html']])));
    eq('refused', db()->query("SELECT status FROM ai_usage ORDER BY rowid DESC LIMIT 1")->fetchColumn());
    $GLOBALS['kol_ai_fake'] = fn() => ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'לא JSON']]];
    eq(502, throws(fn() => ai_suggest_files(X1_ED, ['prompt' => 'abc', 'paths' => ['x1/g.html']])));
    $GLOBALS['kol_ai_fake'] = fn() => throw new ApiError('רשת', 502);
    eq(502, throws(fn() => ai_suggest_files(X1_ED, ['prompt' => 'abc', 'paths' => ['x1/g.html']])));
    eq('error', db()->query("SELECT status FROM ai_usage ORDER BY rowid DESC LIMIT 1")->fetchColumn());
});

test('X1 ✨: המודל הישן נמחק — ai_page_view / ai_apply / AI_EDIT / AI_ADD_TYPES אינם; הסטודיו הישן מקבל "העוזר עבר ל-📁 פרויקט"', function () {
    foreach (['ai_page_view', 'ai_apply', 'ai_request'] as $f) ok(!function_exists($f), $f);
    foreach (['AI_EDIT', 'AI_ADD_TYPES'] as $c) ok(!defined($c), $c);
    $m = '';
    try { ai_suggest(X1_ED, ['prompt' => 'abc']); } catch (ApiError $e) { $m = $e->getMessage(); }
    ok(str_contains($m, 'העוזר עבר ל-📁 פרויקט'));
    $r = http('POST', '/api.php?action=ai_suggest', ['user' => U_EDITOR, 'json' => ['prompt' => 'abc']]);
    eq(410, $r['status']);
    unset($GLOBALS['kol_ai_fake']);
});

test('X1 ✨: דרך ה-API — עורך בלבד, POST בלבד; בלי מפתח — 503 בלי קריאה', function () {
    unset($GLOBALS['kol_ai_fake']);
    eq(403, http('POST', '/api.php?action=ai_suggest_files', ['user' => U_STRANGER, 'json' => ['prompt' => 'abc', 'paths' => ['index.html']]])['status']);
    eq(405, http('GET', '/api.php?action=ai_apply_files', ['user' => U_EDITOR])['status']);
    $r = http('POST', '/api.php?action=ai_suggest_files', ['user' => U_EDITOR, 'json' => ['prompt' => 'abc', 'paths' => ['index.html']]]);
    eq(503, $r['status']);
});
