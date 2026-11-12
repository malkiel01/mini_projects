<?php
/**
 * פרוסה X1 §2.2 — 🤖 עבודה מצ'אט: agent.php על קבצי הפרויקט, דרך השרת האמיתי (כותרות X-Kol-User / X-Kol-Password).
 */

declare(strict_types=1);

function x1_agent(): array
{
    static $t = null;
    if ($t) return $t;
    $r = agent_token_create(['sub' => 'u-editor', 'name' => 'עורך בדיקה', 'role' => 'editor'], ['label' => 'צ\'אט בדיקה']);
    return $t = [$r['user'], $r['password'], $r['skill']];
}

function x1_ag(string $method, string $action, array $json = null, string $qs = ''): array
{
    [$u, $p] = x1_agent();
    $o = ['headers' => ["X-Kol-User: $u", "X-Kol-Password: $p"]];
    if ($json !== null) $o['json'] = $json;
    return http($method, "/agent.php?action=$action$qs", $o);
}

test('X1 🤖: tree / read / edit / write / create / search / versions — כל כתיבה גרסה עם "🤖 <שם הכניסה>" ו-audit עם source: agent', function () {
    pf_seed(['sub' => 'u-editor', 'name' => 'x']);
    eq(200, x1_ag('GET', 'help')['status']);
    $t = x1_ag('GET', 'tree')['json'];
    ok(in_array('index.html', array_column($t['files'], 'path'), true));
    $c = x1_ag('POST', 'create', ['path' => 'ag/a.html', 'content' => "<h1>אחת</h1>\n"]);
    eq(200, $c['status']);
    eq(1, $c['json']['rev']);
    $r = x1_ag('GET', 'read', null, '&path=ag/a.html')['json'];
    eq("<h1>אחת</h1>\n", $r['content']);
    $e = x1_ag('POST', 'edit', ['path' => 'ag/a.html', 'old' => 'אחת', 'new' => 'שתיים', 'rev' => $r['rev']]);
    eq(200, $e['status'], json_encode($e['json'], JSON_UNESCAPED_UNICODE));
    $w = x1_ag('POST', 'write', ['path' => 'ag/a.html', 'content' => "<h1>שלוש</h1>\n", 'rev' => $e['json']['rev']]);
    eq(200, $w['status']);
    eq("<h1>שלוש</h1>\n", x1_ag('GET', 'read', null, '&path=ag/a.html')['json']['content']);
    $v = x1_ag('GET', 'versions', null, '&path=ag/a.html')['json']['versions'];
    eq(3, count($v));
    foreach ($v as $x) ok(str_starts_with($x['note'], "🤖 צ'אט בדיקה"), 'הערת הגרסה: ' . $x['note']);
    $s = x1_ag('GET', 'search', null, '&q=' . rawurlencode('שלוש'))['json'];
    eq(['ag/a.html'], array_column($s['files'], 'path'));
    $a = db()->query("SELECT action, detail, name FROM audit WHERE action LIKE 'agent!_%' ESCAPE '!' ORDER BY id DESC LIMIT 3")->fetchAll();
    eq(['agent_write', 'agent_edit', 'agent_create'], array_column($a, 'action'));
    foreach ($a as $x) { ok(str_contains($x['detail'], 'source: agent'), 'source'); ok(str_contains($x['name'], '🤖')); }
});

test('X1 🤖: rev ישן — 409 עם הגרסה הנוכחית; edit עם old שלא נמצא / כפול — 422 ושום דבר לא נכתב', function () {
    x1_ag('POST', 'create', ['path' => 'ag/b.html', 'content' => "x x y\n"]);
    $w = x1_ag('POST', 'write', ['path' => 'ag/b.html', 'content' => 'דריסה', 'rev' => 99]);
    eq(409, $w['status']);
    eq("x x y\n", $w['json']['conflict']['content']);
    eq(409, x1_ag('POST', 'edit', ['path' => 'ag/b.html', 'old' => 'y', 'new' => 'z', 'rev' => 99])['status']);
    eq(422, x1_ag('POST', 'edit', ['path' => 'ag/b.html', 'old' => 'x', 'new' => 'z', 'rev' => 1])['status'], 'כפול');
    eq(422, x1_ag('POST', 'edit', ['path' => 'ag/b.html', 'old' => 'q', 'new' => 'z', 'rev' => 1])['status'], 'לא נמצא');
    eq(409, x1_ag('POST', 'create', ['path' => 'ag/b.html', 'content' => 'דריסה'])['status'], 'create אינו דורס');
    eq("x x y\n", x1_ag('GET', 'read', null, '&path=ag/b.html')['json']['content']);
});

test('X1 🤖: נתיבים — .., מוחלט, media/ — נדחים', function () {
    foreach (['../api.php', '/etc/passwd', 'ag/../../x', 'media/a.html'] as $p) {
        ok(x1_ag('POST', 'create', ['path' => $p, 'content' => 'x'])['status'] >= 400, $p);
        ok(x1_ag('GET', 'read', null, '&path=' . rawurlencode($p))['status'] >= 400, "read $p");
    }
});

test('X1 🤖: המילים — words ואז words_save: קודם "מה ישתנה" כטקסט + token, ורק בבקשה שנייה עם apply — החלה', function () {
    if (!function_exists('n1_seed')) return;
    n1_seed(4);
    n1_kol(true);
    $w = x1_ag('GET', 'words')['json'];
    $j = json_decode($w['content'], true);
    $j['items'][0]['word'] = 'מילה מהצ\'אט';
    $content = words_json_encode($j);
    $before = words_json();
    $p = x1_ag('POST', 'words_save', ['content' => $content, 'rev' => $w['rev']]);
    eq(200, $p['status'], json_encode($p['json'], JSON_UNESCAPED_UNICODE));
    ok(str_contains($p['json']['preview'], 'תשתנה מילה אחת'));
    eq($before, words_json(), 'התצוגה לא כתבה');
    eq(409, x1_ag('POST', 'words_save', ['content' => $content, 'rev' => $w['rev'], 'apply' => true, 'token' => 'x'])['status'], 'token שגוי');
    $a = x1_ag('POST', 'words_save', ['content' => $content, 'rev' => $w['rev'], 'apply' => true, 'token' => $p['json']['token']]);
    eq(200, $a['status'], json_encode($a['json'], JSON_UNESCAPED_UNICODE));
    ok(str_contains(words_json(), 'מילה מהצ\'אט'));
});

test('X1 🤖: הפעולות הישנות נמחקו (draft, render, code*, publish_preview…) ואין פרסום', function () {
    foreach (AGENT_GONE as $a) eq(404, x1_ag('GET', $a)['status'], $a);
    foreach (['publish', 'republish', 'build_send', 'pf_delete', 'members', 'secret_set'] as $a) eq(404, x1_ag('POST', $a, [])['status'], $a);
    eq(405, x1_ag('GET', 'write')['status']);
});

test('X1 🤖: preview_url — כתובת התצוגה החיה עם אסימון תקף 30 דקות', function () {
    $r = x1_ag('GET', 'preview_url')['json'];
    ok(preg_match('~/preview/([0-9a-z]+\.[A-Za-z0-9_-]{43})/index\.html$~', $r['url'], $m), $r['url']);
    ok(pv_token_ok($m[1]));
    eq(1800, $r['ttl']);
});

test('X1 🤖: הסקיל — נבנה מהקבצים: kol.json, העץ, תכונות data-kol-* מהכותרת של kol-data.js, והכלל "כל כיתוב — תוכן של אלמנט"', function () {
    x1_file('js/kol-data.js', (string)file_get_contents(KOL_ROOT . '/assets/project-seed/kol-data.js'));   // ✨ בבדיקות 96 שינה אותו
    $skill = x1_ag('GET', 'help')['json']['skill'];
    ok(str_contains($skill, '"entry": "index.html"'), 'kol.json');
    ok(str_contains($skill, '`ag/a.html`'), 'העץ — כולל קובץ שנוצר בבדיקה');
    ok(str_contains($skill, 'data-kol-list="words"'), 'תכונות מ-kol-data.js');
    ok(str_contains($skill, 'כל כיתוב בעמוד הוא תוכן של אלמנט'));
    ok(str_contains($skill, 'data/words.json'));
    ok(!str_contains($skill, x1_agent()[1]), 'help אינו מחזיר את הסיסמה');
    ok(str_contains(x1_agent()[2], x1_agent()[1]), 'הסקיל ביצירה — עם הסיסמה');
    foreach (['draft_save', 'code_save', 'publish_preview'] as $old) ok(!str_contains($skill, "`$old`"), "אין $old בסקיל");
});

test('X1 🤖: מגבלת קצב — 120 בקשות בדקה לכניסה, ואז 429', function () {
    [$u] = x1_agent();
    setting_set('agent_rate_' . $u, json_encode(['m' => intdiv(time(), 60), 'n' => AGENT_RATE]));
    $r = x1_ag('GET', 'tree');
    if ($r['status'] === 200) { setting_set('agent_rate_' . $u, json_encode(['m' => intdiv(time(), 60), 'n' => AGENT_RATE])); $r = x1_ag('GET', 'tree'); }   // הדקה התחלפה באמצע
    eq(429, $r['status']);
    db()->prepare('DELETE FROM settings WHERE k = ?')->execute(['agent_rate_' . $u]);
    eq(200, x1_ag('GET', 'tree')['status']);
});

test('X1 🤖: בלי כניסה / סיסמה שגויה — 401, ושום פעולה', function () {
    eq(401, http('GET', '/agent.php?action=tree')['status']);
    [$u] = x1_agent();
    eq(401, http('POST', '/agent.php?action=create', ['headers' => ["X-Kol-User: $u", 'X-Kol-Password: wrong'], 'json' => ['path' => 'ag/z.html', 'content' => 'x']])['status']);
    eq(null, pf_by_path('ag/z.html'));
    db()->exec("DELETE FROM settings WHERE k LIKE 'agent_fail_%'");
});
