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
