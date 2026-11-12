<?php
/**
 * פרוסה N2 — שדות מותאמים למילה ("מבנה המילה"), ערכים ב-words.extra, "מתפרסם" (docs/spec-v2/slices/N2-custom-fields.md).
 * כל בדיקה מול קריטריון של הפרוסה, כולל קלט עוין והרשאה שנדחית. הנתונים — של הבדיקה (n1_seed מ-95-words-file.php).
 */

declare(strict_types=1);

/** מצב נקי של N2: בלי שדות שמורים (ארבעת המובנים — בזיכרון), בלי סל שדות, ונושאים בלי סדר/צבע/תמונה. */
function n2_reset(int $n = 12): array
{
    $ids = n1_seed($n);
    db()->exec("DELETE FROM word_fields; DELETE FROM field_trash; DELETE FROM topic_trash; UPDATE words SET extra = '{}'");
    return $ids;
}

/** הקובץ כפי ש-N1 בנתה אותו (העתק של words_json לפני N2) — להשוואת בייטים. */
function n2_n1_json(): string
{
    $rows = words_rows();
    $map = topic_slugs();
    $count = [];
    foreach ($rows as $w) foreach ($w['topics'] as $t) $count[$t] = ($count[$t] ?? 0) + 1;
    $topics = [];
    foreach ($count as $name => $n) $topics[] = ['name' => (string)$name, 'slug' => topic_slug((string)$name, $map)];
    $topics = array_map(fn($t) => ['id' => $t['slug'], 'label' => $t['name']], lex_sort($topics, 'name'));
    $meta = files_meta(array_values(array_unique(array_filter(array_map(fn($w) => file_id($w['audio']), $rows)))));
    $items = [];
    foreach ($rows as $w) {
        $item = ['id' => $w['id'], 'word' => $w['word'], 'meaning' => $w['meaning'], 'topics' => array_map(fn($t) => topic_slug((string)$t, $map), $w['topics']),
            'audio' => words_audio_ref((string)$w['audio'], $meta)];
        if ($w['wp_id'] !== null) $item['wp_id'] = $w['wp_id'];
        if ((string)$w['external_id'] !== '') $item['external_id'] = $w['external_id'];
        $items[] = $item;
    }
    return words_json_encode(['topics' => $topics, 'items' => $items]);
}

test('N2 מיגרציה: אחרי המיגרציה words.json זהה בבייטים לזה של N1; קריאה אינה כותבת שדות; המילוי אידמפוטנטי', function () {
    n2_reset(40);
    eq(n2_n1_json(), words_json(), 'הקובץ של העורך — זהה בבייטים');
    eq(0, (int)db()->query('SELECT COUNT(*) FROM word_fields')->fetchColumn(), 'קריאה אינה כותבת');
    eq(['word', 'meaning', 'topics', 'audio'], array_column(fields_all(), 'key'), 'ארבעת המובנים בזיכרון');
    fields_fill();
    fields_fill();
    eq(4, (int)db()->query('SELECT COUNT(*) FROM word_fields')->fetchColumn(), 'המילוי אידמפוטנטי');
    topics_fill();
    topics_fill();
    eq(n2_n1_json(), words_json(), 'אחרי המילוי (שדות + סדר נושאים + שורות לנושאים חסרים) — עדיין זהה');
    $t = db()->query("SELECT slug, sort_order FROM topics WHERE name = 'נושא שאינו בטבלה'")->fetch();
    eq('kt-' . substr(md5('נושא שאינו בטבלה'), 0, 10), $t['slug'], 'נושא בלי שורה קיבל שורה עם ה-slug שהיה לו');
    ok($t['sort_order'] > 0, 'ולו סדר');
    $live = words_json('/app/');
    ok(!str_contains($live, '"hidden"') && !str_contains($live, '"color"'), 'בלי צבע/מוסתר — אין מפתחות חדשים');
});

/** קובץ בספרייה (תמונה / הקלטה) עם בייטים אמיתיים בדיסק. מחזיר את המזהה. */
function n2_file(string $kind, string $seed): string
{
    $id = md5("n2-$kind-$seed");
    $ext = $kind === 'image' ? 'png' : 'mp3';
    $bytes = "N2 $kind $seed";
    $f = ['id' => $id, 'ext' => $ext];
    @mkdir(dirname(file_path($f)), 0775, true);
    file_put_contents(file_path($f), $bytes);
    db()->prepare("INSERT OR REPLACE INTO files (id, name, type, kind, ext, size, sha256, by_sub, by_name, at) VALUES (?, ?, ?, ?, ?, ?, ?, 'u1', 'בודק', ?)")
        ->execute([$id, "$seed.$ext", $kind === 'image' ? 'image/png' : 'audio/mpeg', $kind, $ext, strlen($bytes), hash('sha256', $bytes), now_iso()]);
    return $id;
}

function n2_field(array $b, array $user = U_PUBLISHER): array
{
    return b_post('field_save', $b, $user);
}

function n2_word(int $id): array
{
    foreach (words_rows() as $w) if ($w['id'] === $id) return $w;
    throw new TestFail("אין מילה $id");
}

/** שמירה בטבלה (עריכה בתא): השדות המובנים של המילה כמו שהם, ועוד $extra. */
function n2_cell(int $id, array $extra, array $user = U_EDITOR): array
{
    $w = n2_word($id);
    return b_post('word_save', ['id' => $id, 'word' => $w['word'], 'meaning' => $w['meaning'], 'topics' => $w['topics'], 'audio' => $w['audio'],
        'external_id' => $w['external_id'], 'extra' => $extra, 'light' => true], $user);
}

test('N2 מבנה: הוספת שדה — רק מפרסם; מפתח נבדק (תבנית, שמורים, קיימים, עוין); העמודה מופיעה, וה-rev של הקובץ משתנה', function () {
    n2_reset();
    n1_kol();
    $rev = n1_read()['json']['file']['rev'];
    eq(403, n2_field(['key' => 'translit', 'label' => 'תעתיק', 'type' => 'text'], U_EDITOR)['status'], 'עורך — לא');
    foreach (['Translit', '1x', 'a b', 'a"b', 'a<b', 'a}b', 'x-y', str_repeat('a', 41), 'id', 'wp_id', 'label', 'topics_x', 'word', ''] as $bad) {
        $r = n2_field(['key' => $bad, 'label' => 'x', 'type' => 'text']);
        eq(400, $r['status'], "מפתח נדחה: $bad");
    }
    eq(0, (int)db()->query('SELECT COUNT(*) FROM word_fields WHERE col IS NULL')->fetchColumn(), 'שום שדה לא נוצר');
    eq(400, n2_field(['key' => 'x1', 'label' => 'x', 'type' => 'script'])['status'], 'סוג לא מוכר');
    $r = n2_field(['key' => 'translit', 'label' => 'תעתיק לטיני', 'type' => 'text', 'dir' => 'ltr', 'help' => 'באותיות לטיניות']);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    eq(['word', 'meaning', 'topics', 'audio', 'translit'], array_column($r['json']['fields'], 'key'));
    $f = $r['json']['fields'][4];
    eq([true, 'ltr', false, 0], [$f['published'], $f['dir'], $f['builtin'], $f['filled']], 'מתפרסם כברירת מחדל');
    ok($rev !== n1_read()['json']['file']['rev'], 'ה-rev של הקובץ כולל את המבנה');
    eq(400, n2_field(['key' => 'translit', 'label' => 'שוב', 'type' => 'text'])['status'], 'מפתח קיים');
    eq(1, (int)db()->query("SELECT COUNT(*) FROM audit WHERE action = 'field_add'")->fetchColumn(), 'audit');
});

test('N2 ערכים: כל סוג נבדק בעריכה בתא; ערך עוין נשמר רק כ-JSON; חובה; ברירת מחדל רק למילה חדשה', function () {
    $ids = n2_reset();
    $img = n2_file('image', 'a');
    $aud = n2_file('audio', 'b');
    $defs = [['t', 'text'], ['lt', 'longtext'], ['r', 'rich'], ['n', 'number'], ['b', 'bool'], ['d', 'date'], ['u', 'url'], ['img', 'image'], ['au', 'audio'], ['l', 'list']];
    foreach ($defs as [$k, $t]) eq(200, n2_field(['key' => $k, 'label' => "שדה $k", 'type' => $t])['status'], $t);
    eq(200, n2_field(['key' => 'c', 'label' => 'בחירה', 'type' => 'choice', 'options' => [['value' => 'm', 'label' => 'זכר'], ['value' => 'f', 'label' => 'נקבה']]])['status']);
    $id = $ids[0];
    $good = ['t' => 'a "b" <c> }', 'lt' => "שורה\nשנייה", 'r' => '<p>x<script>alert(1)</script></p>', 'n' => 12.5, 'b' => false, 'd' => '2024-02-29',
        'u' => 'https://example.com/a?b=1', 'img' => $img, 'au' => $aud, 'l' => ['א', 'ב'], 'c' => 'f'];
    $r = n2_cell($id, $good);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    $x = n2_word($id)['extra'];
    eq('<p>x</p>', $x['r'], 'rich מנוקה');
    eq('a "b" <c> }', $x['t'], 'טקסט עוין נשמר כמו שהוא');
    eq(false, $x['b']);
    $file = words_json();
    ok(json_decode($file, true) !== null, 'הקובץ JSON תקין');
    ok(str_contains($file, '"t": "a \"b\" <c> }"'), 'בקובץ — דרך json_encode');
    $item = json_decode($file, true)['items'][array_search($id, array_column(json_decode($file, true)['items'], 'id'), true)];
    eq("media/images/$img.png", $item['img'], 'תמונה — הנתיב בחבילה');
    eq("media/audio/$aud-au.mp3", $item['au'], 'הקלטה שנייה — <id>-<key>');
    $bad = ['t' => "שורה\nשנייה", 'n' => '12', 'b' => 'כן', 'd' => '2023-02-29', 'u' => 'javascript:alert(1)', 'img' => md5('nope'), 'au' => $img,
        'l' => [1, 2], 'c' => 'x', 'zz' => 'לא קיים'];
    foreach ($bad as $k => $v) eq(400, n2_cell($id, [$k => $v])['status'], "נדחה: $k");
    eq($x, n2_word($id)['extra'], 'שום ערך לא השתנה');
    eq(200, n2_cell($id, ['t' => '', 'l' => []])['status']);
    ok(!isset(n2_word($id)['extra']['t']) && !isset(n2_word($id)['extra']['l']), 'ריק — המפתח חסר');
    // חובה וברירת מחדל
    eq(200, n2_field(['orig' => 'n', 'label' => 'מספר', 'required' => true, 'def' => 7])['status']);
    eq(400, n2_cell($ids[1], ['t' => 'x'])['status'], 'חובה — מילה בלי ערך נדחית');
    $before = db()->query("SELECT COUNT(*) FROM words WHERE extra LIKE '%\"n\"%'")->fetchColumn();
    eq(200, n2_field(['orig' => 'n', 'label' => 'מספר', 'required' => false, 'def' => 7])['status']);
    eq($before, db()->query("SELECT COUNT(*) FROM words WHERE extra LIKE '%\"n\"%'")->fetchColumn(), 'ברירת מחדל אינה כותבת למילים קיימות');
    $new = b_post('word_save', ['word' => 'חדשה'], U_EDITOR)['json']['id'];
    eq(7, n2_word($new)['extra']['n'], 'מילה חדשה — ברירת המחדל');
    eq(400, n2_field(['orig' => 'd', 'label' => 'd', 'def' => '2023-02-30'])['status'], 'ברירת מחדל נבדקת');
});

test('N2 מתפרסם: שדה פנימי אינו בתצוגה החיה ואינו בבייטים של החבילה; העורך רואה הכול; קבצי שדה שמתפרסם — בחבילה', function () {
    $ids = n2_reset(6);
    n1_kol();
    $img = n2_file('image', 'pub');
    n2_field(['key' => 'notes', 'label' => 'הערות', 'type' => 'text', 'published' => false]);
    n2_field(['key' => 'pic', 'label' => 'תמונה', 'type' => 'image']);
    n2_cell($ids[0], ['notes' => 'סוד-פנימי-123', 'pic' => $img]);
    ok(str_contains(words_json(), 'סוד-פנימי-123'), 'העורך רואה');
    $live = pv_get('data/words.json')['body'];
    ok(!str_contains($live, 'סוד-פנימי-123') && !str_contains($live, '"notes"'), 'התצוגה החיה — לא');
    [$files] = build_words([]);
    $pkg = pf_blob_get($files['data/words.json']['sha']);
    ok(!str_contains($pkg, 'סוד-פנימי-123') && !str_contains($pkg, 'notes'), 'החבילה — לא');
    ok(isset($files["media/images/$img.png"]), 'התמונה בחבילה');
    eq("media/images/$img.png", json_decode($pkg, true)['items'][array_search($ids[0], array_column(json_decode($pkg, true)['items'], 'id'), true)]['pic']);
    // מובנה שאינו מתפרסם: "פירוש"
    n2_field(['orig' => 'meaning', 'label' => 'פירוש', 'published' => false]);
    ok(!str_contains(pv_get('data/words.json')['body'], '"meaning"'), 'פירוש מוסתר');
    ok(str_contains(words_json(), '"meaning"'));
    eq(400, b_post('field_delete', ['key' => 'meaning'], U_PUBLISHER)['status'], 'מובנה אינו נמחק');
    eq(400, n2_field(['orig' => 'word', 'label' => 'מילה', 'type' => 'rich'])['status'], 'סוג של מובנה — רק במשפחה');
});

test('N2 מחיקה: שדה וכל ערכיו לסל, עם נקודת שחזור; שחזור מהסל מחזיר הכול; ונקודת שחזור מחזירה גם את המבנה', function () {
    $ids = n2_reset(8);
    n2_field(['key' => 'translit', 'label' => 'תעתיק', 'type' => 'text']);
    foreach (array_slice($ids, 0, 5) as $i => $id) n2_cell($id, ['translit' => "t$i"]);
    $snapBefore = (int)db()->query('SELECT COUNT(*) FROM snapshots')->fetchColumn();
    $fp = md5(json_encode(array_column(words_rows(), 'extra')));
    $d = b_post('field_delete', ['key' => 'translit', 'dry' => true], U_PUBLISHER)['json'];
    eq([5, 0], [$d['values'], $d['bindings']], 'תצוגה לפני');
    eq(403, b_post('field_delete', ['key' => 'translit'], U_EDITOR)['status']);
    eq(200, b_post('field_delete', ['key' => 'translit'], U_PUBLISHER)['status']);
    ok((int)db()->query('SELECT COUNT(*) FROM snapshots')->fetchColumn() > $snapBefore, 'נקודת שחזור');
    ok(!str_contains(words_json(), 'translit'), 'השדה והערכים יצאו');
    $t = b_get('trash')['json']['fields'];
    eq([1, 'translit', 5], [count($t), $t[0]['key'], $t[0]['values']]);
    eq(200, b_post('trash_restore', ['kind' => 'field', 'id' => $t[0]['id']])['status']);
    eq($fp, md5(json_encode(array_column(words_rows(), 'extra'))), 'הערכים חזרו כמו שהיו');
    eq('תעתיק', array_column(fields_all(), 'label', 'key')['translit']);
    // נקודה ← מחיקה ← שחזור הנקודה
    $snap = snapshot_create(N1_ED, 'בדיקה N2');
    b_post('field_delete', ['key' => 'translit'], U_PUBLISHER);
    b_post('snapshot_restore', ['id' => $snap], U_EDITOR);
    ok(in_array('translit', array_column(fields_all(), 'key'), true), 'המבנה חזר');
    eq($fp, md5(json_encode(array_column(words_rows(), 'extra'))), 'והערכים');
});

test('N2 שינוי סוג: "יומרו / ייכשלו" עם הרשימה; נעצר עד אישור "רוקן"; ערך אינו נמחק בשקט', function () {
    $ids = n2_reset(6);
    n2_field(['key' => 'num', 'label' => 'מספר', 'type' => 'text']);
    foreach (['12', 'שתים', '3,5', '7', '', 'x'] as $i => $v) if ($v !== '') n2_cell($ids[$i], ['num' => $v]);
    $r = n2_field(['orig' => 'num', 'label' => 'מספר', 'type' => 'number', 'dry' => true]);
    eq([3, 2], [$r['json']['convert']['converted'], $r['json']['convert']['failed_count']], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    eq(['שתים', 'x'], array_map(fn($f) => json_decode($f['value']), $r['json']['convert']['failed']));
    $fp = md5(json_encode(array_column(words_rows(), 'extra')));
    eq(422, n2_field(['orig' => 'num', 'label' => 'מספר', 'type' => 'number'])['status'], 'נעצר');
    eq($fp, md5(json_encode(array_column(words_rows(), 'extra'))), 'שום ערך לא השתנה');
    eq('text', array_column(fields_all(), 'type', 'key')['num']);
    $r = n2_field(['orig' => 'num', 'label' => 'מספר', 'type' => 'number', 'clear_failed' => true]);
    eq(200, $r['status']);
    ok($r['json']['snapshot'] > 0, 'נקודת שחזור לפני');
    eq([12, 3.5, 7], array_values(array_filter(array_map(fn($id) => n2_word($id)['extra']['num'] ?? null, $ids), fn($v) => $v !== null)));
    // ובחזרה: מספר ← טקסט — הכול מומר
    $r = n2_field(['orig' => 'num', 'label' => 'מספר', 'type' => 'text']);
    eq(200, $r['status']);
    eq(['12', '3.5', '7'], array_values(array_filter(array_map(fn($id) => n2_word($id)['extra']['num'] ?? null, $ids), fn($v) => $v !== null)));
});

function n2_csv(array $rows): string
{
    $out = fopen('php://temp', 'w+');
    foreach ($rows as $r) fputcsv($out, $r, ',', '"', '');
    rewind($out);
    return (string)stream_get_contents($out);
}

function n2_csv_import(string $csv, array $more = [], array $user = U_EDITOR, bool $apply = true): array
{
    $p = b_post('words_csv_import', ['csv' => $csv] + $more, $user);
    if (!$apply || $p['status'] !== 200) return $p;
    return b_post('words_csv_import', ['csv' => $csv, 'apply' => true, 'token' => $p['json']['plan']['token']] + $more, $user);
}

test('N2 קובץ: מפתח לכל שדה, אחרי המובנים; ערך לפי סוג עם שורה ועמודה; "ישתנה translit ב-2 מילים"; מפתח לא מוכר — הצעה (מפרסם)', function () {
    $ids = n2_reset(6);
    n1_kol();
    n2_field(['key' => 'translit', 'label' => 'תעתיק', 'type' => 'text']);
    n2_field(['key' => 'year', 'label' => 'שנה', 'type' => 'number']);
    n2_field(['key' => 'gender', 'label' => 'מין', 'type' => 'choice', 'options' => ['m', 'f']]);
    $j = n1_file();
    $keys = array_keys($j['items'][0]);
    eq(['id', 'word', 'meaning', 'topics', 'audio'], array_slice($keys, 0, 5), 'המובנים קודם');
    $j['items'][0]['translit'] = 'bslama';
    $j['items'][1]['translit'] = 'lbraka';
    $j['items'][1]['year'] = 1950;
    $r = n1_save(n1_enc($j), false);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    ok(str_contains($r['json']['plan']['summary'], 'ישתנה translit ב-2 מילים'), $r['json']['plan']['summary']);
    eq(200, n1_save(n1_enc($j))['status']);
    eq('lbraka', n2_word($j['items'][1]['id'])['extra']['translit'], 'קובץ ← טבלה');
    eq(1950, n2_word($j['items'][1]['id'])['extra']['year']);
    // שגיאות לפי סוג — עם שורה ועמודה, והטבלה לא משתנה
    $j = n1_file();
    $j['items'][2]['year'] = 'אלף';
    $enc = n1_enc($j);
    $line = substr_count(substr($enc, 0, strpos($enc, '"year": "אלף"')), "\n") + 1;
    n1_bad($enc, $line, 15, '"year" חייב להיות מספר');
    $j = n1_file();
    $j['items'][2]['gender'] = 'x';
    $r = n1_save(n1_enc($j), false);
    eq(422, $r['status']);
    eq('gender:x', $r['json']['errors'][0]['propose_option'] ?? null, 'בחירה שאינה ברשימה — הצעה');
    eq(403, n1_save(n1_enc($j), true, ['create_options' => ['gender:x']])['status'], 'הוספת אפשרות — מפרסם');
    eq(200, n1_save(n1_enc($j), true, ['create_options' => ['gender:x']], U_PUBLISHER)['status']);
    ok(in_array('x', array_column(array_column(fields_all(), 'options', 'key')['gender'], 'value'), true), 'האפשרות נוספה');
    // מפתח לא מוכר
    $j = n1_file();
    $j['items'][0]['notes'] = 'הערה';
    $j['items'][1]['notes'] = 'עוד';
    $j['items'][2]['evil"key'] = 1;
    $r = n1_save(n1_enc($j), false);
    eq(422, $r['status']);
    ok(count(array_filter($r['json']['errors'], fn($e) => ($e['propose_field'] ?? '') === 'notes')) === 1, 'הצעה "צור שדה notes"');
    ok(count(array_filter($r['json']['errors'], fn($e) => str_contains($e['msg'], 'evil'))) === 1, 'מפתח עוין — שגיאה, בלי הצעה');
    unset($j['items'][2]['evil"key']);
    eq(403, n1_save(n1_enc($j), true, ['create_fields' => ['notes']])['status'], 'עורך — לא יוצר שדה');
    $a = n1_save(n1_enc($j), true, ['create_fields' => ['notes']], U_PUBLISHER);
    eq(200, $a['status'], json_encode($a['json'], JSON_UNESCAPED_UNICODE));
    eq('text', array_column(fields_all(), 'type', 'key')['notes'], 'סוג מנוחש');
    eq('עוד', n2_word($j['items'][1]['id'])['extra']['notes']);
    // מחיקת מפתח מפריט — הערך מתרוקן
    $j = n1_file();
    unset($j['items'][1]['notes']);
    eq(200, n1_save(n1_enc($j))['status']);
    ok(!isset(n2_word($j['items'][1]['id'])['extra']['notes']));
    eq(0, n1_save(n1_file() ? n1_enc(n1_file()) : '', false)['json']['plan']['counts']['changed'], 'הלוך-חזור — 0 שינויים');
});

test('N2 קובץ ונושאים: שם / צבע / מוסתר / סדר של נושא קיים — נשמרים, וה-slug לא זז; צבע עוין נדחה', function () {
    n2_reset(10);
    n1_kol();
    $j = n1_file();
    $slugs = array_column($j['topics'], 'id');
    $i = array_search('food', $slugs, true);
    $j['topics'][$i]['label'] = 'מאכלים';
    $j['topics'][$i]['color'] = '#aa0033';
    $j['topics'][$i === 0 ? 1 : 0]['hidden'] = true;
    $j['topics'] = array_reverse($j['topics']);
    $r = n1_save(n1_enc($j), false);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    ok(str_contains($r['json']['plan']['summary'], 'נושאים'), $r['json']['plan']['summary']);
    eq(200, n1_save(n1_enc($j))['status']);
    $f = n1_file();
    eq(array_column($j['topics'], 'id'), array_column($f['topics'], 'id'), 'הסדר נשמר, אותם slugs');
    $food = $f['topics'][array_search('food', array_column($f['topics'], 'id'), true)];
    eq(['id' => 'food', 'label' => 'מאכלים', 'color' => '#aa0033'], $food, 'שם חדש, אותו slug');
    eq(0, (int)db()->query("SELECT COUNT(*) FROM words WHERE topics LIKE '%\"אוכל\"%'")->fetchColumn(), 'השם הישן לא נשאר במילים');
    ok(str_contains(words_json(), '"hidden": true'), 'מוסתר — בקובץ של העורך');
    $live = json_decode(pv_get('data/words.json')['body'], true);
    eq(count($f['topics']) - 1, count($live['topics']), 'מוסתר — לא ברשימה המתפרסמת');
    $hid = array_values(array_filter($f['topics'], fn($t) => !empty($t['hidden'])))[0]['id'];
    ok(count(array_filter($live['items'], fn($it) => in_array($hid, $it['topics'], true))) > 0, 'ונשאר על המילים');
    foreach (['red', '#FFF', '#12345g', '"><script>', '#aa0033;x'] as $bad) {
        $j = n1_file();
        $j['topics'][0]['color'] = $bad;
        eq(422, n1_save(n1_enc($j), false)['status'], "צבע נדחה: $bad");
    }
});

test('N2 אקסל: עמודה לכל שדה (הכותרת — השם); הלוך-חזור = 0 שינויים; התאמה לפי שם או מפתח; עמודה לא מוכרת — "לא תיובא" + הצעה', function () {
    $ids = n2_reset(6);
    n2_field(['key' => 'translit', 'label' => 'תעתיק', 'type' => 'text']);
    n2_field(['key' => 'n', 'label' => 'מספר', 'type' => 'number']);
    n2_field(['key' => 'ok', 'label' => 'מאושר', 'type' => 'bool']);
    n2_field(['key' => 'tags', 'label' => 'תגיות', 'type' => 'list']);
    n2_cell($ids[0], ['translit' => '=cmd', 'n' => 3.5, 'ok' => true, 'tags' => ['א', 'ב']]);
    $csv = http('GET', '/api.php?action=words_csv', ['user' => U_EDITOR])['body'];
    [$rows] = words_csv_read($csv);
    eq(['תעתיק', 'מספר', 'מאושר', 'תגיות'], array_slice($rows[0], 8), 'עמודות השדות אחרי של N1');
    ok(str_contains($csv, "'=cmd"), 'נוסחה מנוטרלת');
    $p = n2_csv_import($csv, [], U_EDITOR, false);
    eq(200, $p['status'], json_encode($p['json'], JSON_UNESCAPED_UNICODE));
    eq(['changed' => 0, 'added' => 0, 'trashed' => 0, 'moved' => 0, 'topics' => 0], $p['json']['plan']['counts'], 'הלוך-חזור');
    // עריכה בגיליון: כותרת לפי מפתח, פסיק עשרוני, "לא", עמודה לא מוכרת
    $rows[0][8] = 'translit';
    $rows[0][] = 'region';
    $x = array_search((string)$ids[1], array_column($rows, 0), true);
    $rows[$x][8] = 'lbraka';
    $rows[$x][9] = '2,5';
    $rows[$x][10] = 'לא';
    $rows[$x][12] = 'צפון';
    $r = n2_csv_import(n2_csv($rows));
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    eq(['n' => 2.5, 'ok' => false, 'translit' => 'lbraka'], n2_word($ids[1])['extra'], 'אקסל ← טבלה');
    $p = b_post('words_csv_import', ['csv' => n2_csv($rows)], U_EDITOR)['json'];
    ok(str_contains(json_encode($p['plan']['warnings'], JSON_UNESCAPED_UNICODE), 'region'), 'לא תיובא');
    eq('region', $p['plan']['field_proposals'][0]['key'] ?? null);
    eq(403, n2_csv_import(n2_csv($rows), ['create_fields' => ['region']])['status'], 'יצירת שדה — מפרסם');
    eq(200, n2_csv_import(n2_csv($rows), ['create_fields' => ['region']], U_PUBLISHER)['status']);
    eq('צפון', n2_word($ids[1])['extra']['region']);
    $rows[$x][9] = 'שתיים';
    eq(422, n2_csv_import(n2_csv($rows), [], U_EDITOR, false)['status'], 'מספר לא תקין — שגיאה עם שורה');
});

test('N2 שלוש הדלתות: שדה במסך ← עמודה בטבלה ← מפתח בקובץ ← עמודה ב-CSV ← ערך מהקובץ בטבלה ובתצוגה החיה', function () {
    $ids = n2_reset(4);
    n1_kol();
    n2_field(['key' => 'translit', 'label' => 'תעתיק', 'type' => 'text']);
    eq('translit', array_column(b_get('words')['json']['fields'], 'key')[4], 'עמודה בטבלה');
    n2_cell($ids[0], ['translit' => 'מהטבלה']);
    $j = n1_file();
    $x = array_search($ids[0], array_column($j['items'], 'id'), true);
    eq('מהטבלה', $j['items'][$x]['translit'], 'טבלה ← קובץ');
    $csv = http('GET', '/api.php?action=words_csv', ['user' => U_EDITOR])['body'];
    ok(str_contains($csv, 'מהטבלה'), 'טבלה ← CSV');
    $j['items'][$x]['translit'] = 'מהקובץ';
    eq(200, n1_save(n1_enc($j))['status']);
    eq('מהקובץ', n2_word($ids[0])['extra']['translit'], 'קובץ ← טבלה');
    ok(str_contains(pv_get('data/words.json')['body'], 'מהקובץ'), 'קובץ ← תצוגה חיה');
});

require_once KOL_ROOT . '/lib/fields_bind.php';

/** קובץ בפרויקט: יוצר, או כותב מחדש אם קיים. מחזיר את המזהה. */
function n2_pf(string $path, string $bytes): int
{
    $id = pf_paths()[$path] ?? null;
    if (!$id) return (int)pf_new($path, $bytes)['json']['file']['id'];
    $rev = http('GET', "/api.php?action=pf_read&id=$id", ['user' => U_EDITOR])['json']['file']['rev'];
    b_post('pf_write', ['id' => (int)$id, 'rev' => $rev, 'content' => $bytes]);
    return (int)$id;
}

const N2_HTML = <<<'HTML'
<ul data-kol-list="words" data-kol-sort="-translit" data-kol-search-fields="word translit">
  <template><li data-kol-class-latin="translit=1" data-kol-if="!translit">
    <b data-kol-text="translit"></b> <i data-kol-text="#{id} · {translit.count}"></i>
    <s data-kol-text="translit_long"></s> <u data-kol-attr-title='השמע translit'></u> translit
  </li></template>
</ul>
<nav data-kol-filter="words:translit"><button data-kol-filter-value="">הכל</button></nav>
<nav data-kol-filter="words:topics"><button data-kol-filter-value="food">אוכל</button><p data-kol-if="topics=food"></p></nav>
HTML;

test('N2 שינוי מפתח: כל הקשירות (קובץ · שורה · לפני ← אחרי), רק בתוך ערך תכונה / {key}; JS — לבדיקה ידנית; עסקה אחת עם גרסה בהיסטוריה', function () {
    $ids = n2_reset(4);
    n1_kol();
    n2_field(['key' => 'translit', 'label' => 'תעתיק', 'type' => 'text']);
    n2_cell($ids[0], ['translit' => 'bslama']);
    $html = n2_pf('n2/index.html', N2_HTML);
    $js = n2_pf('n2/app.js', "// translit\nconst a = item.translit; const b = item['translit']; const c = 'translit';\n");
    $p = http('GET', '/api.php?action=field_key_preview&key=translit&to=latin', ['user' => U_PUBLISHER]);
    eq(200, $p['status'], json_encode($p['json'], JSON_UNESCAPED_UNICODE));
    $auto = array_values(array_filter($p['json']['hits'], fn($h) => !$h['manual']));
    $manual = array_values(array_filter($p['json']['hits'], fn($h) => $h['manual']));
    eq(['data-kol-sort', 'data-kol-search-fields', 'data-kol-class-latin', 'data-kol-if', 'data-kol-text', 'data-kol-text', 'data-kol-filter'], array_column($auto, 'what'), 'שבע קשירות ב-HTML');
    eq(2, count($manual), 'JS: item.translit ו-[\'translit\'] — לבדיקה ידנית (לא ההערה ולא המחרוזת)');
    eq(1, $p['json']['values']);
    ok(str_contains($auto[0]['after'], 'data-kol-sort="-latin"'), 'אחרי');
    eq(403, http('GET', '/api.php?action=field_key_preview&key=translit&to=latin', ['user' => U_EDITOR])['status'], 'עורך — לא');
    // מפתח עוין — נדחה בשרת, ושום קובץ לא נגע
    foreach (['a"b', 'a<b', 'a}b', 'a b', 'Latin', 'id', 'word', '{x}', "a\nb"] as $bad) {
        eq(400, b_post('field_key_apply', ['key' => 'translit', 'to' => $bad, 'revs' => []], U_PUBLISHER)['status'], "נדחה: $bad");
    }
    eq(N2_HTML, pf_get($html), 'הקובץ לא נגע');
    // rev ישן — 409, ושום דבר לא השתנה
    $revs = array_column($p['json']['hits'], 'rev', 'file');
    eq(409, b_post('field_key_apply', ['key' => 'translit', 'to' => 'latin', 'revs' => [$html => 999]], U_PUBLISHER)['status']);
    ok(in_array('translit', array_column(fields_all(), 'key'), true), 'המבנה לא השתנה');
    $skip = [$auto[6]['id']];                                      // בלי ה-filter
    $a = b_post('field_key_apply', ['key' => 'translit', 'to' => 'latin', 'revs' => $revs, 'skip' => $skip], U_PUBLISHER);
    eq(200, $a['status'], json_encode($a['json'], JSON_UNESCAPED_UNICODE));
    eq(6, $a['json']['replaced']);
    $out = pf_get($html);
    eq(str_replace(['-translit"', 'word translit"', '"translit=1"', '"!translit"', 'data-kol-text="translit"', '{translit.count}'],
        ['-latin"', 'word latin"', '"latin=1"', '"!latin"', 'data-kol-text="latin"', '{latin.count}'], N2_HTML), $out, 'רק המקומות שסומנו; translit_long, הטקסט וה-filter שבוטל — נשארו');
    eq("// translit\nconst a = item.translit; const b = item['translit']; const c = 'translit';\n", pf_get($js), 'JS לא מוחלף');
    eq('bslama', n2_word($ids[0])['extra']['latin'], 'הערכים עברו למפתח החדש');
    ok(str_contains(words_json(), '"latin": "bslama"'));
    $v = b_get('pf_versions', "&id=$html")['json']['versions'] ?? [];
    ok(str_contains(json_encode($v, JSON_UNESCAPED_UNICODE), 'שינוי מפתח `translit` → `latin`'), 'גרסה בהיסטוריה עם הסיבה');
    eq(1, (int)db()->query("SELECT COUNT(*) FROM audit WHERE action = 'field_key'")->fetchColumn());
    // מובנה: meaning → definition — הקובץ והקשירה בלבד; העמודה במסד נשארת
    eq(200, b_post('field_key_apply', ['kind' => 'field', 'key' => 'meaning', 'to' => 'definition', 'revs' => []], U_PUBLISHER)['status']);
    $j = json_decode(words_json(), true);
    ok(isset($j['items'][0]['definition']) && !isset($j['items'][0]['meaning']), 'המפתח בקובץ');
    eq('meaning', array_column(fields_all(), 'col', 'key')['definition'], 'העמודה במסד — אותה');
    $j['items'][0]['definition'] = '<p>חדש</p>';
    eq(200, n1_save(n1_enc($j))['status'], 'הקובץ נשמר עם המפתח החדש');
    eq('<p>חדש</p>', n2_word($j['items'][0]['id'])['meaning']);
});

test('N2 שינוי slug של נושא: filter-value ו-topics=slug מתעדכנים; מפתח עוין נדחה; המילים לא משתנות', function () {
    n2_reset(6);
    n1_kol();
    $html = n2_pf('n2/topics.html', N2_HTML);
    $css = n2_pf('n2/t.css', "[data-kol-filter-value=\"food\"] { color: red }\n.food { x: 1 }\n");
    $p = http('GET', '/api.php?action=field_key_preview&kind=topic&key=food&to=meals', ['user' => U_PUBLISHER])['json'];
    eq(3, count(array_filter($p['hits'], fn($h) => !$h['manual'] && in_array($h['file'], [$html, $css], true))), 'filter-value, topics=food, ו-CSS שמפנה לתכונה');
    foreach (['me"als', 'Meals', 'm<x', 'family'] as $bad) ok(in_array(b_post('field_key_apply', ['kind' => 'topic', 'key' => 'food', 'to' => $bad], U_PUBLISHER)['status'], [400, 409], true), "נדחה: $bad");
    $words = md5(json_encode(db()->query('SELECT topics FROM words ORDER BY id')->fetchAll()));
    $a = b_post('field_key_apply', ['kind' => 'topic', 'key' => 'food', 'to' => 'meals', 'revs' => array_column($p['hits'], 'rev', 'file')], U_PUBLISHER);
    eq(200, $a['status'], json_encode($a['json'], JSON_UNESCAPED_UNICODE));
    ok(str_contains(pf_get($html), 'data-kol-filter-value="meals"') && str_contains(pf_get($html), 'data-kol-if="topics=meals"'));
    eq("[data-kol-filter-value=\"meals\"] { color: red }\n.food { x: 1 }\n", pf_get($css), 'מחלקה בשם food — לא נגעה');
    eq($words, md5(json_encode(db()->query('SELECT topics FROM words ORDER BY id')->fetchAll())), 'המילים — לפי שם, לא השתנו');
    ok(in_array('meals', array_column(json_decode(words_json(), true)['topics'], 'id'), true));
    eq(1, (int)db()->query("SELECT COUNT(*) FROM audit WHERE action = 'topic_slug'")->fetchColumn());
});

test('N2 מתפרסם בעורך: תווית "שדות פנימיים" במנהל הקבצים (לא בתוך ה-JSON); שכפול — עותק שכולל אותם, עם אזהרה', function () {
    n2_reset(3);
    n1_kol();
    $f = n1_read()['json']['file'];
    eq([], $f['internal']);
    n2_field(['key' => 'notes', 'label' => 'הערות', 'type' => 'text', 'published' => false]);
    $f = n1_read()['json']['file'];
    eq([['key' => 'notes', 'label' => 'הערות']], $f['internal']);
    ok(str_contains($f['note'], 'שדות פנימיים: notes — לא מתפרסמים'), $f['note']);
    ok(!str_contains(n1_read()['json']['content'], 'שדות פנימיים'), 'לא בתוך הקובץ');
    $id = array_column(words_rows(), 'id')[0];
    n2_cell($id, ['notes' => 'פנימי']);
    $c = b_post('pf_copy', ['id' => -1, 'to' => 'n2/copy.json']);
    eq(200, $c['status'], json_encode($c['json'], JSON_UNESCAPED_UNICODE));
    ok(str_contains((string)$c['json']['warning'], 'שדה פנימי אחד (notes)'), 'אזהרה בשכפול');
    ok(str_contains(pf_get((int)$c['json']['file']['id']), '"notes": "פנימי"'), 'העותק כולל את השדה הפנימי');
});
