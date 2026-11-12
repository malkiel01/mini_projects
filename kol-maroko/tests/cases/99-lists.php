<?php
/**
 * פרוסה N3 — רשימות נוספות ("פתגמים"): טבלה, קובץ, שדות ואקסל לכל רשימה (docs/spec-v2/slices/N3-more-lists.md).
 * כל בדיקה מול קריטריון של הפרוסה, כולל קלט עוין ("  <  >  }  </script>) והרשאה שנדחית. רצה דרך ה-API (השרת של הבדיקות).
 */

declare(strict_types=1);

const L3_HOSTILE = '"><script>alert(1)</script>}{</main>';

/** מצב נקי של N3: בלי רשימות, בלי סל רשימות, בלי data.<list> ב-kol.json. */
function l3_reset(): void
{
    pf_seed(['sub' => 'system', 'name' => 'seed']);
    foreach (array_keys(lists_all()) as $k) {
        $r = b_post('list_delete', ['key' => $k], U_PUBLISHER);
        ok($r['status'] === 200, "ניקוי $k: " . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    }
    db()->exec('DELETE FROM list_trash');
    $r = b_post('lock', ['op' => 'release'], U_EDITOR);
}

function l3_create(string $key = 'proverbs', string $label = 'פתגמים', array $more = [], array $user = U_EDITOR): array
{
    return b_post('list_create', ['label' => $label, 'item_label' => 'פתגם', 'key' => $key] + $more, $user);
}

function l3_item(string $list, array $data, ?int $id = null, array $user = U_EDITOR): array
{
    return b_post('list_item_save', ['list' => $list, 'data' => $data] + ($id ? ['id' => $id] : []), $user);
}

function l3_fid(string $key): int
{
    return -(LIST_FILE_BASE + list_get($key)['fid']);
}

function l3_file(string $key): array
{
    return json_decode(pf_get(l3_fid($key)), true);
}

/** שמירת הקובץ של רשימה דרך pf_write: תוכנית ← החלה. */
function l3_save(string $key, string $content, array $extra = [], array $user = U_EDITOR, bool $apply = true): array
{
    $id = l3_fid($key);
    $rev = b_get('pf_read', "&id=$id")['json']['file']['rev'];
    $p = b_post('pf_write', ['id' => $id, 'rev' => $rev, 'content' => $content] + $extra, $user);
    if (!$apply || $p['status'] !== 200) return $p;
    return b_post('pf_write', ['id' => $id, 'rev' => $rev, 'content' => $content, 'apply' => true, 'token' => $p['json']['plan']['token']] + $extra, $user);
}

function l3_kol(): array
{
    return json_decode(pf_get(b_id('kol.json')), true);
}

test('N3 מודל: רשימה חדשה — שורה, שדה title, רישום ב-kol.json דרך מנוע הקבצים (גרסה), והקובץ בעץ "מקושר לטבלה"', function () {
    l3_reset();
    $kolId = b_id('kol.json');
    $hist = count(b_get('pf_versions', "&id=$kolId")['json']['versions']);
    $r = l3_create();
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    eq('proverbs', $r['json']['created']);
    $l = array_column($r['json']['lists'], null, 'key');
    ok(isset($l['words']) && $r['json']['lists'][0]['key'] === 'words', 'המילים — הרשימה הראשונה בבורר');
    eq('data/proverbs.json', $l['proverbs']['file']);
    eq(['file' => 'data/proverbs.json', 'source' => 'list:proverbs'], l3_kol()['data']['proverbs']);
    ok(isset(l3_kol()['data']['words']), 'data.words נשאר');
    eq($hist + 1, count(b_get('pf_versions', "&id=$kolId")['json']['versions']), 'kol.json — גרסה חדשה בהיסטוריה');
    $f = list_fields('proverbs');
    eq(['title'], array_column($f, 'key'));
    ok($f[0]['required'] && $f[0]['published'] && $f[0]['type'] === 'text', 'title — טקסט קצר, חובה, מתפרסם');
    $tree = array_column(b_get('pf_tree')['json']['files'], null, 'path');
    eq('list', $tree['data/proverbs.json']['linked'] ?? null, 'בעץ — מקושר');
    eq(l3_fid('proverbs'), $tree['data/proverbs.json']['id']);
    eq("{\n  \"items\": []\n}\n", pf_get(l3_fid('proverbs')), 'קובץ ריק');
    // מפתחות עוינים / שמורים — 400 בשרת
    foreach (['words', 'Proverbs', 'a"b', '<x>', 'pro}', 'pro verbs', '1abc', str_repeat('a', 31), 'proverbs', 'kol'] as $bad) {
        ok(in_array(l3_create($bad)['status'], [400, 409], true), "מפתח \"$bad\" נדחה");
    }
    eq(400, l3_create('xyz', '')['status'], 'שם ריק');
    eq(409, l3_create('other', 'אחר', ['file' => 'data/proverbs.json'])['status'], 'נתיב תפוס');
    eq(409, l3_create('other', 'אחר', ['file' => 'data/words.json'])['status'], 'הנתיב של המילים');
    eq(400, l3_create('other', 'אחר', ['file' => '../x.json'])['status'], 'נתיב מחוץ לפרויקט');
});

test('N3 פריטים: id לכל רשימה בנפרד, מ-1, לא חוזר אחרי מחיקה; חובה; בחירה שאינה ברשימה; שחזור מהסל עם אותו id', function () {
    l3_reset();
    l3_create();
    l3_create('recipes', 'מתכונים');
    foreach (['מי שמחכה — מקבל', 'אל תעשה לחברך', 'ההר והעכבר'] as $t) eq(200, l3_item('proverbs', ['title' => $t])['status']);
    eq(200, l3_item('recipes', ['title' => 'כסכסו'])['status']);
    eq([1, 2, 3], array_column(list_rows('proverbs'), 'id'));
    eq([1], array_column(list_rows('recipes'), 'id'), 'id לכל רשימה בנפרד');
    eq(400, l3_item('proverbs', ['title' => ''])['status'], 'חובה');
    eq(400, l3_item('proverbs', ['nope' => 'x', 'title' => 'x'])['status'], 'שדה לא מוכר');
    eq(400, l3_item('proverbs', ['title' => "שורה\nשנייה"])['status'], 'טקסט קצר — שורה אחת');
    eq(200, b_post('list_item_delete', ['list' => 'proverbs', 'id' => 2])['status']);
    eq(200, l3_item('proverbs', ['title' => 'חדש'])['status']);
    eq([1, 3, 4], array_column(list_rows('proverbs'), 'id'), 'המזהה 2 אינו חוזר');
    $t = array_values(array_filter(b_get('trash')['json']['lists'], fn($x) => $x['kind'] === 'item' && $x['list'] === 'proverbs'));
    eq(1, count($t));
    eq(200, b_post('trash_restore', ['kind' => 'list', 'id' => $t[0]['id']])['status']);
    eq([1, 2, 3, 4], array_column(list_rows('proverbs'), 'id'), 'שחזור — עם אותו id, באותו מקום');
    // עריכה, וגרירה (לפני / למקום)
    eq(200, l3_item('proverbs', ['title' => 'מי שמחכה — מקבל!'], 1)['status']);
    eq(404, l3_item('proverbs', ['title' => 'x'], 99)['status']);
    $m = b_post('list_item_move', ['list' => 'proverbs', 'ids' => [4], 'before' => 1]);
    eq(200, $m['status']);
    eq([4, 1, 2, 3], array_column(list_rows('proverbs'), 'id'));
    b_post('list_item_move', ['list' => 'proverbs', 'ids' => [4], 'to' => 4]);
    eq([1, 2, 3, 4], array_column(list_rows('proverbs'), 'id'));
    eq(404, b_get('list_items', '&list=nolist')['status']);
    eq(400, b_get('list_items', '&list=' . rawurlencode('a"b'))['status']);
});

test('N3 שדות: מבנה לרשימה (field_save עם list) — בחירה עם טבלת תוויות בראש הקובץ, "מתפרסם", שינוי סוג, מחיקה לסל', function () {
    l3_reset();
    l3_create();
    $f = b_post('field_save', ['list' => 'proverbs', 'key' => 'meaning', 'label' => 'פירוש', 'type' => 'rich']);
    eq(200, $f['status'], json_encode($f['json'], JSON_UNESCAPED_UNICODE));
    eq(200, b_post('field_save', ['list' => 'proverbs', 'key' => 'source', 'label' => 'מקור', 'type' => 'choice', 'options' => [['value' => 'morocco', 'label' => 'מרוקו'], ['value' => 'talmud', 'label' => 'תלמוד']]])['status']);
    eq(200, b_post('field_save', ['list' => 'proverbs', 'key' => 'notes', 'label' => 'הערות', 'type' => 'text', 'published' => false])['status']);
    eq(400, b_post('field_save', ['list' => 'proverbs', 'key' => 'items', 'label' => 'x', 'type' => 'text'])['status'], '"items" שמור');
    eq(400, b_post('field_save', ['list' => 'proverbs', 'key' => 'Bad"', 'label' => 'x', 'type' => 'text'])['status'], 'מפתח עוין');
    eq(['title', 'meaning', 'source', 'notes'], array_column(b_get('fields', '&list=proverbs')['json']['fields'], 'key'));
    ok(!in_array('source', array_column(b_get('fields')['json']['fields'], 'key'), true), 'המבנה של המילים לא השתנה');
    l3_item('proverbs', ['title' => 'א', 'meaning' => '<p>פ<script>x</script></p>', 'source' => 'morocco', 'notes' => 'פנימי']);
    eq(400, l3_item('proverbs', ['title' => 'ב', 'source' => 'nowhere'])['status'], 'בחירה שאינה ברשימה');
    $file = l3_file('proverbs');
    eq([['id' => 'morocco', 'label' => 'מרוקו'], ['id' => 'talmud', 'label' => 'תלמוד']], $file['source'], 'טבלת תוויות בראש הקובץ');
    eq(['source', 'items'], array_keys($file));
    eq('פנימי', $file['items'][0]['notes'], 'בעורך — גם הפנימי');
    ok(!str_contains($file['items'][0]['meaning'], '<script'), 'rich — מנוקה');
    $live = json_decode(list_json('proverbs', 'https://app.test/'), true);
    ok(!isset($live['items'][0]['notes']), 'בתצוגה החיה — בלי שדה פנימי');
    // שינוי סוג: choice → text — מומר; מחיקה עם ערכים — מפרסם
    $dry = b_post('field_save', ['list' => 'proverbs', 'orig' => 'source', 'label' => 'מקור', 'type' => 'text', 'dry' => true]);
    eq(1, $dry['json']['convert']['converted']);
    eq(403, b_post('field_delete', ['list' => 'proverbs', 'key' => 'notes'], U_EDITOR)['status'], 'מחיקת שדה עם ערכים — מפרסם');
    eq(200, b_post('field_delete', ['list' => 'proverbs', 'key' => 'notes'], U_PUBLISHER)['status']);
    ok(!array_key_exists('notes', list_rows('proverbs')[0]['data']), 'הערך יצא');
    $t = array_values(array_filter(b_get('trash')['json']['lists'], fn($x) => $x['kind'] === 'field'));
    eq(200, b_post('trash_restore', ['kind' => 'list', 'id' => $t[0]['id']])['status']);
    eq('פנימי', list_rows('proverbs')[0]['data']['notes'], 'השדה והערך חזרו');
    // שינוי מפתח של שדה ברשימה — הערכים
    eq(200, b_post('field_key_apply', ['list' => 'proverbs', 'key' => 'notes', 'to' => 'remarks', 'revs' => []])['status']);
    eq('פנימי', list_rows('proverbs')[0]['data']['remarks']);
});

test('N3 הקובץ: עריכה ב-data/<key>.json — תוכנית לפי id, החלה, שגיאות עם שורה, id שנמחק אינו חוזר, "צור שדה"; rev ישן — 409', function () {
    l3_reset();
    l3_create();
    foreach (['א', 'ב', 'ג'] as $t) l3_item('proverbs', ['title' => $t]);
    $j = l3_file('proverbs');
    eq([1, 2, 3], array_column($j['items'], 'id'));
    // אותו קובץ — 0 שינויים
    $p = l3_save('proverbs', words_json_encode($j), [], U_EDITOR, false);
    eq(['changed' => 0, 'added' => 0, 'trashed' => 0, 'moved' => 0], $p['json']['plan']['counts']);
    // שינוי, הוספה, הסרה, סדר
    $j['items'][0]['title'] = 'א!';
    unset($j['items'][1]);
    $j['items'] = array_values($j['items']);
    array_unshift($j['items'], ['title' => 'חדש']);
    $r = l3_save('proverbs', words_json_encode($j));
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    eq(['changed' => 1, 'added' => 1, 'trashed' => 1, 'moved' => 0], array_intersect_key($r['json']['done'], array_flip(['changed', 'added', 'trashed', 'moved'])));
    eq([4, 1, 3], array_column(list_rows('proverbs'), 'id'), 'החדש — המזהה הבא (4), בראש');
    eq(['חדש', 'א!', 'ג'], array_column(array_column(l3_file('proverbs')['items'], null), 'title'));
    // id שנמחק — שגיאה עם שורה; מפתח לא מוכר — הצעה; JSON שבור — שורה ועמודה
    $bad = "{\n  \"items\": [\n    { \"id\": 2, \"title\": \"x\" }\n  ]\n}\n";
    $e = l3_save('proverbs', $bad, [], U_EDITOR, false);
    eq(422, $e['status']);
    eq(3, $e['json']['errors'][0]['line']);
    $u = "{\n  \"items\": [\n    { \"id\": 4, \"title\": \"חדש\", \"origin\": \"מרוקו\" }\n  ]\n}\n";
    $e = l3_save('proverbs', $u, [], U_EDITOR, false);
    eq(422, $e['status']);
    eq('origin', $e['json']['field_proposals'][0]['key']);
    $h = l3_save('proverbs', "{\n  \"items\": [ { \"title\": \"x\", " . json_encode(L3_HOSTILE) . ": 1 } ]\n}", [], U_EDITOR, false);
    eq(422, $h['status'], 'מפתח עוין — שגיאה');
    ok(!$h['json']['field_proposals'], 'ולא הצעה ליצור שדה');
    $r = l3_save('proverbs', $u, ['create_fields' => ['origin']], U_PUBLISHER);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    ok(in_array('origin', array_column(list_fields('proverbs'), 'key'), true), 'השדה נוצר');
    eq([4], array_column(list_rows('proverbs'), 'id'), 'הקובץ שלם: מה שאינו בו — לסל');
    $broken = b_post('pf_write', ['id' => l3_fid('proverbs'), 'rev' => b_get('pf_read', '&id=' . l3_fid('proverbs'))['json']['file']['rev'], 'content' => "{\n  \"items\": [\n"]);
    eq(422, $broken['status']);
    eq(409, b_post('pf_write', ['id' => l3_fid('proverbs'), 'rev' => 'lold', 'content' => '{}'])['status'], 'rev ישן');
    eq(400, b_post('pf_write', ['id' => l3_fid('proverbs'), 'rev' => 'x', 'content' => '{}', 'auto' => true])['status'], 'בלי שמירה אוטומטית');
    eq(409, b_post('pf_create', ['path' => 'data/proverbs.json', 'content' => '{}'])['status'], 'הנתיב תפוס בעץ');
    // שכפול — עותק סטטי
    $c = b_post('pf_copy', ['id' => l3_fid('proverbs'), 'to' => 'data/proverbs-copy.json']);
    eq(200, $c['status']);
    ok($c['json']['static_copy']);
});

test('N3 אקסל: ייצוא ← ייבוא חזרה "0 שינויים"; שורה חדשה; עמודה לא מוכרת; "החלף הכול" — מפרסם; מאקסל כרשימה חדשה', function () {
    l3_reset();
    l3_create();
    b_post('field_save', ['list' => 'proverbs', 'key' => 'meaning', 'label' => 'פירוש', 'type' => 'longtext']);
    foreach (['א', '=SUM(1)', 'ג'] as $t) l3_item('proverbs', ['title' => $t, 'meaning' => "פירוש $t"]);
    $csv = http('GET', '/api.php?action=list_csv&list=proverbs', ['user' => U_EDITOR])['body'];
    ok(str_starts_with($csv, "\xEF\xBB\xBF"), 'BOM');
    ok(str_contains($csv, "'=SUM(1)"), 'נוסחה — עם \' לפניה');
    $p = b_post('list_csv_import', ['list' => 'proverbs', 'csv_b64' => base64_encode($csv)]);
    eq(200, $p['status'], json_encode($p['json'], JSON_UNESCAPED_UNICODE));
    eq(['changed' => 0, 'added' => 0, 'trashed' => 0, 'moved' => 0], $p['json']['plan']['counts'], 'אקסל הלוך-חזור — 0 שינויים');
    $csv2 = "מזהה,פתגם,פירוש,עמודה זרה\n1,א!,פירוש א,x\n,חדש,,y\n";
    $p = b_post('list_csv_import', ['list' => 'proverbs', 'csv' => $csv2]);
    eq(200, $p['status'], json_encode($p['json'], JSON_UNESCAPED_UNICODE));
    eq(1, $p['json']['plan']['counts']['changed']);
    eq(1, $p['json']['plan']['counts']['added']);
    ok(str_contains(json_encode($p['json']['plan']['warnings'], JSON_UNESCAPED_UNICODE), 'לא תיובא'));
    $a = b_post('list_csv_import', ['list' => 'proverbs', 'csv' => $csv2, 'apply' => true, 'token' => $p['json']['plan']['token']]);
    eq(200, $a['status']);
    eq([1, 2, 3, 4], array_column(list_rows('proverbs'), 'id'));
    $p = b_post('list_csv_import', ['list' => 'proverbs', 'csv' => $csv2, 'replace_all' => true]);
    eq(403, b_post('list_csv_import', ['list' => 'proverbs', 'csv' => $csv2, 'replace_all' => true, 'apply' => true, 'token' => $p['json']['plan']['token']])['status'], 'החלף הכול — מפרסם');
    // מאקסל כרשימה חדשה: תצוגה, ואז יצירה + ייבוא באותה עסקה
    $x = "פתגם,ארץ,מספר\nמי שמחכה,מרוקו,3\nהר,תוניס,5\n";
    $pv = l3_create('sayings', 'אמרות', ['csv_b64' => base64_encode($x)]);
    eq(200, $pv['status'], json_encode($pv['json'], JSON_UNESCAPED_UNICODE));
    eq(['text', 'text', 'number'], array_column($pv['json']['fields'], 'type'), 'סוג מנוחש');
    eq(2, $pv['json']['count']);
    ok(!isset(lists_all()['sayings']), 'תצוגה — לא נוצר דבר');
    $cr = l3_create('sayings', 'אמרות', ['csv_b64' => base64_encode($x), 'apply' => true]);
    eq(200, $cr['status'], json_encode($cr['json'], JSON_UNESCAPED_UNICODE));
    $rows = list_rows('sayings');
    eq(2, count($rows));
    $k = array_column(list_fields('sayings'), 'key');
    eq(5, $rows[1]['data'][$k[2]]);
});

test('N3 שינוי מפתח של רשימה: הקשירות (list/search/filter/count/more/empty/src), kol.json והנתיב — עסקה אחת', function () {
    l3_reset();
    l3_create();
    l3_item('proverbs', ['title' => 'א']);
    $html = "<div data-kol-list=\"proverbs\" data-kol-sort=\"manual\"><template><b data-kol-text=\"title\"></b></template></div>\n"
        . "<input data-kol-search=\"proverbs\"><div data-kol-filter=\"proverbs:source\"></div><p data-kol-count=\"proverbs\"></p>\n"
        . "<button data-kol-more=\"proverbs\"></button><p data-kol-empty=\"proverbs\"></p><div data-kol-list=\"words\" data-kol-src=\"data/proverbs.json\"></div>\n"
        . "<p data-kol-text=\"proverbs\">לא קשירה של רשימה</p>\n";
    $id = b_id('l3-page.html') ?: pf_new('l3-page.html', $html)['json']['file']['id'];
    if (pf_get($id) !== $html) b_save('l3-page.html', $html);
    $pv = b_get('list_key_preview', '&key=proverbs&to=sayings');
    eq(200, $pv['status'], json_encode($pv['json'], JSON_UNESCAPED_UNICODE));
    eq(7, count(array_filter($pv['json']['hits'], fn($h) => $h['path'] === 'l3-page.html')), 'שבע הקשירות, בלי data-kol-text');
    eq(['from' => 'data/proverbs.json', 'to' => 'data/sayings.json'], $pv['json']['file']);
    eq(400, b_get('list_key_preview', '&key=proverbs&to=' . rawurlencode('say"ings'))['status'], 'מפתח עוין');
    eq(400, b_get('list_key_preview', '&key=proverbs&to=words')['status'], 'שמור');
    $revs = [];
    foreach ($pv['json']['hits'] as $h) $revs[$h['file']] = $h['rev'];
    $a = b_post('list_key_apply', ['key' => 'proverbs', 'to' => 'sayings', 'revs' => $revs]);
    eq(200, $a['status'], json_encode($a['json'], JSON_UNESCAPED_UNICODE));
    $after = pf_get(b_id('l3-page.html'));
    eq(str_replace(['"proverbs"', '"proverbs:', 'data/proverbs.json'], ['"sayings"', '"sayings:', 'data/sayings.json'], str_replace('data-kol-text="proverbs"', 'data-kol-text="@@"', $html)), str_replace('data-kol-text="proverbs"', 'data-kol-text="@@"', $after));
    ok(str_contains($after, 'data-kol-text="proverbs"'), 'שם שדה זהה — לא נגע');
    eq(['file' => 'data/sayings.json', 'source' => 'list:sayings'], l3_kol()['data']['sayings']);
    ok(!isset(l3_kol()['data']['proverbs']));
    eq(1, count(list_rows('sayings')), 'הפריטים עברו');
    ok(isset(array_column(b_get('pf_tree')['json']['files'], null, 'path')['data/sayings.json']));
    // rev ישן — 409, ושום דבר לא השתנה
    $pv = b_get('list_key_preview', '&key=sayings&to=proverbs');
    $revs = [];
    foreach ($pv['json']['hits'] as $h) $revs[$h['file']] = $h['rev'] - 1;
    eq(409, b_post('list_key_apply', ['key' => 'sayings', 'to' => 'proverbs', 'revs' => $revs])['status']);
    ok(isset(lists_all()['sayings']), 'העסקה בוטלה');
});

test('N3 מחיקת רשימה: dry סופר קשירות; עם פריטים — מפרסם; לסל כולה, kol.json מוסר; "שחזור" מחזיר הכול', function () {
    l3_reset();
    l3_create();
    l3_item('proverbs', ['title' => 'א']);
    b_save('l3-page.html', '<div data-kol-list="proverbs"></div><input data-kol-search="proverbs">');
    $d = b_post('list_delete', ['key' => 'proverbs', 'dry' => true]);
    eq(['items' => 1, 'fields' => 1, 'bindings' => 2, 'needs' => 'publisher'], array_diff_key($d['json'], ['ok' => 1]));
    eq(403, b_post('list_delete', ['key' => 'proverbs'], U_EDITOR)['status']);
    eq(200, b_post('list_delete', ['key' => 'proverbs'], U_PUBLISHER)['status']);
    ok(!isset(lists_all()['proverbs']) && !isset(l3_kol()['data']['proverbs']), 'נמחקה, והרישום הוסר');
    ok(!isset(array_column(b_get('pf_tree')['json']['files'], null, 'path')['data/proverbs.json']), 'הקובץ יצא מהעץ');
    $t = array_values(array_filter(b_get('trash')['json']['lists'], fn($x) => $x['kind'] === 'list'));
    eq(200, b_post('trash_restore', ['kind' => 'list', 'id' => $t[0]['id']])['status']);
    eq(['title' => 'א'], list_rows('proverbs')[0]['data']);
    eq('list:proverbs', l3_kol()['data']['proverbs']['source']);
    // רשימה ריקה — עורך
    l3_create('empty1', 'ריקה');
    eq(200, b_post('list_delete', ['key' => 'empty1'], U_EDITOR)['status']);
    // ה"מחיקה" של הקובץ בעץ — רק מסירה מ-kol.json
    eq(409, b_post('pf_delete', ['id' => l3_fid('proverbs')])['status']);
    eq(200, b_post('pf_delete', ['id' => l3_fid('proverbs'), 'unlink' => true])['status']);
    ok(isset(lists_all()['proverbs']) && !isset(l3_kol()['data']['proverbs']), 'הרשימה נשארה');
});

test('N3 "➕ הוסף לעמוד": קטע HTML רגיל + בלוק CSS, דרך מנוע הקבצים; payload עוין — טקסט; kol-data.js נוסף כשחסר', function () {
    l3_reset();
    l3_create('proverbs', L3_HOSTILE);
    b_post('field_save', ['list' => 'proverbs', 'key' => 'meaning', 'label' => '</script>"<', 'type' => 'rich']);
    b_post('field_save', ['list' => 'proverbs', 'key' => 'source', 'label' => 'מקור', 'type' => 'choice', 'options' => [['value' => 'a"<b>', 'label' => '</script>']]]);
    b_post('field_save', ['list' => 'proverbs', 'key' => 'rec', 'label' => 'הקלטה', 'type' => 'audio']);
    b_post('field_save', ['list' => 'proverbs', 'key' => 'secret', 'label' => 'פנימי', 'type' => 'text', 'published' => false]);
    $page = "<!doctype html>\n<html>\n<body>\n  <main class=\"page\">\n    <h1 id=\"top\">כותרת</h1>\n  </main>\n</body>\n</html>\n";
    $id = b_id('l3-place.html') ?: pf_new('l3-place.html', $page)['json']['file']['id'];
    b_save('l3-place.html', $page);
    $before = count(b_get('pf_versions', "&id=$id")['json']['versions']);
    $r = b_post('list_place', ['list' => 'proverbs', 'path' => 'l3-place.html', 'where' => 'after', 'id' => 'top']);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    $html = pf_get($id);
    $v = b_get('pf_versions', "&id=$id")['json']['versions'];
    eq($before + 1, count($v), 'גרסה בהיסטוריה');
    ok(str_contains($v[0]['note'], 'הוסף את הרשימה'), 'הערת הגרסה');
    ok(!str_contains($html, '<script>alert') && str_contains($html, '&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;}{&lt;/main&gt;'), 'השם — escape');
    eq(1, substr_count($html, '</main>'), 'שום תגית לא נשברה');
    ok(strpos($html, 'id="list-proverbs"') > strpos($html, 'id="top"') && strpos($html, 'list-proverbs') < strpos($html, '</main>'), 'אחרי האלמנט');
    foreach (['data-kol-list="proverbs"', 'data-kol-sort="manual"', 'data-kol-search="proverbs"', 'data-kol-filter="proverbs:source"', 'data-kol-count="proverbs"',
        'data-kol-more="proverbs"', 'data-kol-empty="proverbs"', 'data-kol-html="meaning"', 'data-kol-play="rec"', '<template>'] as $needle) ok(str_contains($html, $needle), $needle);
    ok(!str_contains($html, 'secret'), 'שדה פנימי — לא בקטע');
    ok(str_contains($html, 'js/kol-data.js'), 'kol-data.js נוסף');
    ok(b_id('js/kol-data.js') > 0, 'והקובץ קיים בפרויקט');
    ok(str_contains(pf_get(b_id('css/page.css')), '.kol-list-proverbs {'), 'CSS');
    // שוב — ה-CSS אינו כפול; "בסוף <main>" ו"בסוף <body>"
    eq(200, b_post('list_place', ['list' => 'proverbs', 'path' => 'l3-place.html', 'where' => 'main'])['status']);
    eq(1, substr_count(pf_get(b_id('css/page.css')), '.kol-list-proverbs {'));
    eq(404, b_post('list_place', ['list' => 'proverbs', 'path' => 'l3-place.html', 'where' => 'after', 'id' => 'nope'])['status']);
    eq(400, b_post('list_place', ['list' => 'proverbs', 'path' => 'l3-place.html', 'where' => 'after', 'id' => 'a"b'])['status']);
    eq(400, b_post('list_place', ['list' => 'proverbs', 'path' => 'css/page.css', 'where' => 'main'])['status']);
    eq(400, b_post('list_place', ['list' => 'proverbs', 'path' => 'l3-place.html', 'where' => 'head'])['status']);
});

test('N3 תצוגה חיה ובנייה: data/<key>.json מוגש ונבנה לחבילה — בסדר הנכון, בלי שדה פנימי', function () {
    l3_reset();
    l3_create();
    b_post('field_save', ['list' => 'proverbs', 'key' => 'secret', 'label' => 'פנימי', 'type' => 'text', 'published' => false]);
    foreach (['ג', 'א', 'ב'] as $t) l3_item('proverbs', ['title' => $t, 'secret' => 's']);
    b_post('list_item_move', ['list' => 'proverbs', 'ids' => [2], 'to' => 1]);
    $tok = b_get('pv_token')['json']['token'];
    $live = http('GET', "/preview/$tok/data/proverbs.json");
    eq(200, $live['status']);
    $j = json_decode($live['body'], true);
    eq(['א', 'ג', 'ב'], array_column($j['items'], 'title'));
    ok(!str_contains($live['body'], 'secret'), 'בלי שדה פנימי');
    [$files] = build_collect(build_opts([]));
    ok(isset($files['data/proverbs.json']), 'בחבילה');
    $b = json_decode(pf_blob_get($files['data/proverbs.json']['sha']), true);
    eq(['א', 'ג', 'ב'], array_column($b['items'], 'title'));
    ok(!isset($b['items'][0]['secret']));
});

test('N3 המילים זהות בבייטים: words.json (עורך + תצוגה) לפני ואחרי כל פעולות הרשימות; בחירה במילים — טבלה רק כשיש', function () {
    n2_reset(20);
    $ed = words_json();
    $app = words_json('https://app.test/');
    eq(n2_n1_json(), $ed, 'כמו ב-N1');
    l3_reset();
    l3_create();
    l3_item('proverbs', ['title' => 'א']);
    b_post('field_save', ['list' => 'proverbs', 'key' => 'source', 'label' => 'מקור', 'type' => 'choice', 'options' => [['value' => 'x', 'label' => 'X']]]);
    b_post('list_key_apply', ['key' => 'proverbs', 'to' => 'sayings', 'revs' => []]);
    eq($ed, words_json(), 'העורך — זהה בבייטים');
    eq($app, words_json('https://app.test/'), 'התצוגה החיה — זהה בבייטים');
    eq(-1, array_column(b_get('pf_tree')['json']['files'], 'id', 'path')['data/words.json'], 'המילים — עדיין -1');
    // שדה בחירה במילים — טבלת תוויות בראש הקובץ, והקובץ נשמר חזרה בלי שינוי
    eq(200, b_post('field_save', ['key' => 'region', 'label' => 'אזור', 'type' => 'choice', 'options' => [['value' => 'fes', 'label' => 'פאס']]])['status']);
    $j = json_decode(words_json(), true);
    eq([['id' => 'fes', 'label' => 'פאס']], $j['region']);
    eq(['topics', 'region', 'items'], array_keys($j));
    $p = n1_save(words_json(), false);
    eq(200, $p['status'], json_encode($p['json'], JSON_UNESCAPED_UNICODE));
    eq(0, $p['json']['plan']['counts']['changed']);
    b_post('field_delete', ['key' => 'region'], U_PUBLISHER);
    eq($ed, words_json(), 'בלי שדה בחירה — שוב זהה');
});

test('N3 נקודת שחזור כוללת את הרשימות; מסך המפתח — שורה; הרשאות: זר — 403', function () {
    l3_reset();
    l3_create();
    l3_item('proverbs', ['title' => 'א']);
    ok(isset(snap_state()['lists']), 'snap_state — lists');
    $snap = snapshot_create(['sub' => 'u-owner', 'name' => 'o', 'role' => 'owner'], 'בדיקה');
    l3_item('proverbs', ['title' => 'ב']);
    eq(2, count(list_rows('proverbs')));
    eq(200, b_post('snapshot_restore', ['id' => $snap], U_EDITOR)['status']);
    eq(1, count(list_rows('proverbs')), 'השחזור החזיר את הרשימה');
    $line = lists_dev_line();
    ok(str_contains($line['text'], 'proverbs'), $line['text']);
    eq(403, b_get('lists', '', U_STRANGER)['status']);
    eq(403, l3_create('zzz', 'ז', [], U_STRANGER)['status']);
});
