<?php
/**
 * פרוסה N2 — נושאים מורחבים: שינוי שם שומר slug (הפגם של לפני N2), מיזוג ושחזור, מוסתר, סדר, צבע ותמונה.
 */

declare(strict_types=1);

function n2t_topic(string $slug): ?array
{
    foreach (b_get('topics')['json']['topics'] as $t) if ($t['slug'] === $slug) return $t;
    return null;
}

test('N2 נושאים: שינוי שם — ה-slug לא זז (בקובץ, בתצוגה החיה ובמילים); גם דרך topic_op הישנה', function () {
    n2_reset(10);
    n1_kol();
    $live0 = json_decode(pv_get('data/words.json')['body'], true);
    $ids0 = array_map(fn($it) => $it['topics'], $live0['items']);
    $r = b_post('topic_save', ['slug' => 'food', 'name' => 'מאכלים']);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    $live = json_decode(pv_get('data/words.json')['body'], true);
    eq($ids0, array_map(fn($it) => $it['topics'], $live['items']), 'אותם מפתחות על המילים');
    eq('מאכלים', array_column($live['topics'], 'label', 'id')['food'], 'אותו id, שם חדש');
    eq(0, (int)db()->query("SELECT COUNT(*) FROM topics WHERE name = 'אוכל'")->fetchColumn(), 'אין שורה חדשה');
    eq(1, (int)db()->query("SELECT COUNT(*) FROM topics WHERE slug = 'food'")->fetchColumn());
    // הממשק הישן
    eq(200, b_post('topic_op', ['op' => 'rename', 'name' => 'מאכלים', 'to' => 'אוכל טוב'])['status']);
    eq('אוכל טוב', array_column(json_decode(pv_get('data/words.json')['body'], true)['topics'], 'label', 'id')['food'], 'topic_op rename — ה-slug נשאר');
    eq(409, b_post('topic_save', ['slug' => 'food', 'name' => 'משפחה'])['status'], 'שם תפוס — מיזוג, לא שינוי שם');
    foreach (['red', '#ABCDEF', '#abc', '";x'] as $bad) eq(400, b_post('topic_save', ['slug' => 'food', 'color' => $bad])['status'], "צבע: $bad");
    eq(400, b_post('topic_save', ['slug' => 'food', 'image' => '../../etc/passwd'])['status'], 'תמונה — רק מזהה קובץ');
});

test('N2 נושאים: מסך — כל הנושאים גם בלי מילים, סדר בגרירה, צבע ותמונה בקובץ ובחבילה; מוסתר — בעורך כן, בעמוד לא', function () {
    n2_reset(10);
    n1_kol();
    db()->exec("INSERT INTO topics (slug, name) VALUES ('empty', 'ריק')");
    $all = b_get('topics')['json']['topics'];
    ok(in_array('empty', array_column($all, 'slug'), true), 'נושא בלי מילים — במסך');
    eq(0, n2t_topic('empty')['count']);
    $order = array_reverse(array_column($all, 'slug'));
    eq(200, b_post('topic_save', ['order' => $order])['status']);
    eq($order, array_column(b_get('topics')['json']['topics'], 'slug'));
    $used = array_values(array_filter($order, fn($s) => $s !== 'empty'));
    eq($used, array_column(json_decode(words_json(), true)['topics'], 'id'), 'הקובץ — לפי הסדר, בלי נושא ריק');
    $img = n2_file('image', 'topic');
    eq(200, b_post('topic_save', ['slug' => 'family', 'color' => '#00aa11', 'image' => $img, 'hidden' => true])['status']);
    $t = array_column(json_decode(words_json(), true)['topics'], null, 'id')['family'];
    eq(['id' => 'family', 'label' => 'משפחה', 'color' => '#00aa11', 'image' => "media/images/$img.png", 'hidden' => true], $t, 'בעורך');
    ok(!in_array('family', array_column(json_decode(pv_get('data/words.json')['body'], true)['topics'], 'id'), true), 'מוסתר — לא בעמוד');
    eq(200, b_post('topic_save', ['slug' => 'family', 'hidden' => false])['status']);
    [$files] = build_words([]);
    ok(isset($files["media/images/$img.png"]), 'תמונת הנושא בחבילה');
    $pkg = json_decode(pf_blob_get($files['data/words.json']['sha']), true);
    eq("media/images/$img.png", array_column($pkg['topics'], null, 'id')['family']['image']);
});

test('N2 נושאים: מיזוג א ← ב — בלי כפילות, לסל; "שחזור" מחזיר את שני הנושאים, השיוכים, ה-slug והצבע', function () {
    n2_reset(10);
    b_post('topic_save', ['slug' => 'bless', 'color' => '#123456']);
    $before = md5(json_encode(db()->query('SELECT id, topics FROM words ORDER BY id')->fetchAll()));
    $fileBefore = words_json();
    $d = b_post('topic_merge', ['from' => 'bless', 'to' => 'family', 'dry' => true])['json'];
    ok($d['words'] > 0, 'תצוגה: כמה מילים');
    eq($fileBefore, words_json(), 'dry אינו כותב');
    eq(400, b_post('topic_merge', ['from' => 'family', 'to' => 'family'])['status']);
    $r = b_post('topic_merge', ['from' => 'bless', 'to' => 'family']);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    eq(0, (int)db()->query("SELECT COUNT(*) FROM words WHERE topics LIKE '%ברכות%'")->fetchColumn());
    foreach (words_rows() as $w) eq(count($w['topics']), count(array_unique($w['topics'])), 'בלי כפילות');
    eq(null, n2t_topic('bless'));
    $t = b_get('trash')['json']['topics'][0];
    eq(['merge', 'ברכות', 'משפחה'], [$t['op'], $t['name'], $t['to']]);
    eq(200, b_post('trash_restore', ['kind' => 'topic', 'id' => $t['id']])['status']);
    eq($before, md5(json_encode(db()->query('SELECT id, topics FROM words ORDER BY id')->fetchAll())), 'השיוכים חזרו');
    eq('#123456', n2t_topic('bless')['color'], 'ה-slug והצבע חזרו');
    eq($fileBefore, words_json(), 'הקובץ זהה לזה שלפני');
    // הסרה — גם היא לסל עם ה-slug
    b_post('topic_op', ['op' => 'delete', 'name' => 'ברכות']);
    eq(null, n2t_topic('bless'));
    eq(200, b_post('trash_restore', ['kind' => 'topic', 'id' => b_get('trash')['json']['topics'][0]['id']])['status']);
    eq($fileBefore, words_json(), 'הסרה ושחזור — זהה');
});
