<?php
/**
 * פרוסה N1 — data/words.json כפנים של טבלת המילים; סדר ידני; אקסל לפי id (docs/spec-v2/slices/N1-data-as-file.md).
 * כל בדיקה מול קריטריון של הפרוסה, כולל קלט עוין והרשאה שנדחית. המסד זמני (tests/run.php) — המילים כאן הן של הבדיקה.
 */

declare(strict_types=1);

const N1_ED = ['sub' => 'u-editor', 'name' => 'עורך בדיקה', 'role' => 'editor'];
const N1_PUB = ['sub' => 'u-publisher', 'name' => 'מפרסם בדיקה', 'role' => 'publisher'];

/** מילים לבדיקה: עברית עם ניקוד, ערבית, לטינית, וכפילויות (סדר יציב). $n מילים, חלקן עם הקלטה באתר. */
function n1_seed(int $n = 30): array
{
    $pdo = db();
    $pdo->exec('DELETE FROM words; DELETE FROM words_trash; DELETE FROM topics; DELETE FROM sqlite_sequence WHERE name = \'words\'');
    $pdo->exec("INSERT INTO topics (slug, name) VALUES ('food', 'אוכל'), ('family', 'משפחה'), ('bless', 'ברכות')");
    $he = ['בְּסְלָאמָה', 'לְבְּרָכָּה', 'זוהרה', 'מליחה', 'סעודה', 'חביבי', 'לעזיז', 'כסכסו', 'מימונה', 'תפילה', 'שבת', 'אבא'];
    $ar = ['بسلامة', 'البركة', 'الزهرة'];
    $la = ['bslama', 'Zohra', "s'ouda", 'k&sksu'];
    $all = array_merge($he, $ar, $la);
    $tp = [['אוכל'], ['משפחה', 'ברכות'], [], ['ברכות'], ['נושא שאינו בטבלה']];
    $ins = $pdo->prepare('INSERT INTO words (wp_id, word, meaning, topics, audio, external_id, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $seed = 7;
    for ($i = 0; $i < $n; $i++) {
        $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
        $w = $all[$seed % count($all)] . ($i % 7 === 0 ? '' : ' ' . ($seed % 13));
        $ins->execute([$i % 4 ? 1000 + $i : null, $w, "<p>פירוש <b>$i</b></p>", json_encode($tp[$i % 5], JSON_UNESCAPED_UNICODE),
            $i % 3 ? 'https://maghreb.co.il/wp-content/uploads/a' . $i . '.mp3' : '', $i % 6 ? '' : 'X' . $i, '2026-11-01T00:00:00Z', 'seed']);
    }
    return array_map('intval', $pdo->query('SELECT id FROM words ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
}

/** kol.json עם data.words (או בלי) — כדי שהקובץ יהיה בעץ. */
function n1_kol(bool $with = true): void
{
    $id = pf_paths()['kol.json'] ?? null;
    $c = $id ? json_decode(pf_get((int)$id), true) : null;
    if (!is_array($c)) $c = ['version' => 1, 'entry' => 'index.html'];
    if ($with) $c['data']['words'] = ['file' => 'data/words.json', 'source' => 'words']; else unset($c['data']['words']);
    $bytes = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($id) { $rev = http('GET', "/api.php?action=pf_read&id=$id", ['user' => U_EDITOR])['json']['file']['rev']; b_post('pf_write', ['id' => (int)$id, 'rev' => $rev, 'content' => $bytes]); }
    else pf_new('kol.json', $bytes);
}

/** טביעה של הטבלה כולה (כולל הסל והנושאים) — "הטבלה אינה משתנה". */
function n1_fp(): string
{
    $pdo = db();
    return md5(json_encode([$pdo->query('SELECT * FROM words ORDER BY id')->fetchAll(), $pdo->query('SELECT * FROM words_trash ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM topics ORDER BY slug')->fetchAll()]));
}

function n1_read(array $user = U_EDITOR): array
{
    return http('GET', '/api.php?action=pf_read&id=-1', ['user' => $user]);
}

/** שמירה של הקובץ דרך pf_write: תוכנית, ואם $apply — החלה עם האסימון. */
function n1_save(string $content, bool $apply = true, array $extra = [], array $user = U_EDITOR): array
{
    $rev = n1_read()['json']['file']['rev'];
    $c = mb_check_encoding($content, 'UTF-8') ? ['content' => $content] : ['content_b64' => base64_encode($content)];
    $p = b_post('pf_write', ['id' => -1, 'rev' => $rev] + $c + $extra, $user);
    if (!$apply || $p['status'] !== 200) return $p;
    return b_post('pf_write', ['id' => -1, 'rev' => $rev, 'content' => $content, 'apply' => true, 'token' => $p['json']['plan']['token']] + $extra, $user);
}

function n1_file(): array
{
    return json_decode(n1_read()['json']['content'], true);
}

function n1_enc(array $j): string
{
    return words_json_encode($j);
}

/** שגיאה אחת צפויה: 422, עם שורה ועמודה, והטבלה לא השתנתה. */
function n1_bad(string $content, int $line, int $col, string $needle): void
{
    $fp = n1_fp();
    $r = n1_save($content, false);
    eq(422, $r['status'], "422: $needle");
    $e = $r['json']['errors'][0] ?? null;
    ok($e && str_contains($e['msg'], $needle), "ההודעה מכילה \"$needle\": " . json_encode($e, JSON_UNESCAPED_UNICODE));
    eq([$line, $col], [$e['line'], $e['col']], "שורה ועמודה ($needle)");
    eq($fp, n1_fp(), "הטבלה לא השתנתה ($needle)");
}

test('N1 סדר: המילוי הראשון של sort_order משחזר בדיוק את הסדר של היום (lex_sort), על 1,496 מילים; קריאה אינה כותבת', function () {
    n1_seed(1496);
    $pdo = db();
    $rows = $pdo->query('SELECT id, word FROM words ORDER BY id')->fetchAll();
    $before = array_map(fn($r) => (int)$r['id'], lex_sort($rows, 'word'));       // הסדר של לפני N1: words_rows = lex_sort על ה-SELECT
    $words = fn() => md5(json_encode($pdo->query('SELECT id, wp_id, word, meaning, topics, audio, external_id, updated_at, updated_by FROM words ORDER BY id')->fetchAll()));
    $w0 = $words();
    eq($before, array_column(words_rows(), 'id'), 'לפני המילוי — אותו סדר');
    eq(1496, (int)$pdo->query('SELECT COUNT(*) FROM words WHERE sort_order IS NULL')->fetchColumn(), 'קריאה אינה כותבת');
    $live = json_decode(pv_get('data/words.json')['body'], true);
    eq($before, array_column($live['items'], 'id'), 'התצוגה החיה — לפני');
    words_order_fill();
    eq(0, (int)$pdo->query('SELECT COUNT(*) FROM words WHERE sort_order IS NULL')->fetchColumn());
    eq($before, array_column(words_rows(), 'id'), 'אחרי המילוי — זהה');
    eq(range(10, 14960, 10), array_column(words_rows(), 'sort_order'), 'בקפיצות של 10');
    eq($before, array_column(json_decode(pv_get('data/words.json')['body'], true)['items'], 'id'), 'התצוגה החיה — אחרי, זהה');
    eq($w0, $words(), 'שום שדה של מילה לא השתנה');
    // מילה חדשה — בסוף
    $id = word_save(N1_ED, ['word' => 'אאא חדשה', 'meaning' => ''])['id'];
    eq($id, array_column(words_rows(), 'id')[1496], 'מילה חדשה בסוף, גם כשבא"ב היא ראשונה');
});

test('N1 קובץ: words_json — פונקציה אחת לתצוגה, לבנייה ולעורך; שני רווחים; בסדר התצוגה', function () {
    n1_seed(12);
    n1_kol();
    eq(pv_words('/app/'), words_json('/app/'), 'pv_words קוראת ל-words_json');
    $j = words_json();
    ok(str_starts_with($j, "{\n  \"topics\": [\n    {\n      \"id\""), 'הזחה של שני רווחים');
    ok(!str_contains($j, '\\u05'), 'UTF-8 בלי escape');
    $f = json_decode($j, true);
    eq(array_column(words_rows(), 'id'), array_column($f['items'], 'id'));
    eq(['id', 'word', 'meaning', 'topics', 'audio'], array_slice(array_keys($f['items'][0]), 0, 5));
    // הבנייה (M1) קוראת דרך pv_words — אותם פריטים, אותו סדר
    require_once KOL_ROOT . '/lib/build.php';
    [$files] = build_words([]);
    eq(array_column($f['items'], 'id'), array_column(json_decode(pf_blob_get($files['data/words.json']['sha']), true)['items'], 'id'), 'הבנייה — אותו סדר');
});

test('N1 עץ: data/words.json בעץ, מקושר לטבלה; קריאה, הורדה, rev; אין גוש שמור ואין שורה ב-pf_files', function () {
    n1_seed(10);
    n1_kol();
    $t = http('GET', '/api.php?action=pf_tree', ['user' => U_EDITOR])['json'];
    $f = array_values(array_filter($t['files'], fn($x) => $x['id'] === -1))[0] ?? null;
    ok($f, 'בעץ');
    eq(['data/words.json', 'words', 'נבנה מטבלת המילים — שמירה מעדכנת את הטבלה'], [$f['path'], $f['linked'], $f['note']]);
    $r = n1_read();
    eq(words_json(), $r['json']['content']);
    eq(words_rev(), $r['json']['file']['rev']);
    $raw = http('GET', '/api.php?action=pf_raw&id=-1&download=1', ['user' => U_EDITOR]);
    eq(words_json(), $raw['body'], 'הורדה');
    ok(str_contains($raw['headers']['content-security-policy'] ?? '', 'sandbox'), 'ארגז חול');
    eq(0, (int)db()->query("SELECT COUNT(*) FROM pf_files WHERE path = 'data/words.json'")->fetchColumn(), 'אין שורה ב-pf_files');
    eq(403, n1_read(U_STRANGER)['status'], 'זר — 403');
    // שינוי בטבלה ← rev אחר (עורך פתוח יודע)
    $rev = $r['json']['file']['rev'];
    word_save(N1_ED, ['id' => array_column(words_rows(), 'id')[0], 'word' => 'שונתה בטבלה', 'meaning' => 'x']);
    ok($rev !== n1_read()['json']['file']['rev'], 'rev השתנה');
    // שם הקובץ נקבע ב-kol.json; בלי data.words — אינו בעץ
    n1_kol(false);
    eq([], array_filter(http('GET', '/api.php?action=pf_tree', ['user' => U_EDITOR])['json']['files'], fn($x) => $x['id'] === -1), 'בלי data.words — אין קובץ');
    eq(404, n1_read()['status']);
    n1_kol();
    eq(409, pf_new('data/words.json', '{}')['status'], 'אי אפשר ליצור קובץ רגיל באותו נתיב');
    eq(409, pf_new('data/words.json/x.txt', 'x')['status'], 'ולא "בתוכו"');
});

test('N1 אימות: כל שגיאה עם שורה ועמודה, והטבלה אינה משתנה (כולל JSON עוין)', function () {
    n1_seed(6);
    n1_kol();
    $ok = n1_read()['json']['content'];
    n1_bad("{\n  \"items\": [\n    {\"id\": 1 \"word\": \"x\"}\n  ]\n}", 3, 14, 'JSON לא תקין');
    n1_bad("{\n  \"topics\": []\n}", 1, 1, '"items" חסר');
    n1_bad("{\n  \"items\": {}\n}", 2, 12, '"items" חסר');
    n1_bad("[1, 2]", 1, 1, 'חייב להיות אובייקט');
    $j = json_decode($ok, true);
    $dup = $j; $dup['items'][1]['id'] = $j['items'][0]['id'];
    $s = n1_enc($dup);
    [$l] = wj_linecol($s, strpos($s, '"id"', strpos($s, '"id"', strpos($s, '"items"')) + 1));
    $r = n1_save($s, false);
    eq(422, $r['status']);
    ok(str_contains($r['json']['errors'][0]['msg'], 'מופיע פעמיים'), 'id כפול');
    eq($l, $r['json']['errors'][0]['line'], 'id כפול — בשורה של הפריט השני');
    foreach (['"5"' => 'מספר שלם חיובי', '1.5' => 'מספר שלם חיובי', '-3' => 'מספר שלם חיובי', '0' => 'מספר שלם חיובי', '99999999999999999999' => 'מספר שלם חיובי'] as $v => $msg) {
        n1_bad("{\"items\": [\n  {\"id\": $v, \"word\": \"x\"}\n]}", 2, 10, $msg);
    }
    n1_bad("{\"items\": [\n  {\"word\": \"  \"}\n]}", 2, 12, '"word" ריק');
    n1_bad("{\"items\": [\n  {\"word\": 5}\n]}", 2, 12, 'חייב להיות מחרוזת');
    n1_bad("{\"items\": [\n  {\"word\": \"x\", \"topics\": \"food\"}\n]}", 2, 27, '"topics" חייב להיות רשימה');
    n1_bad("{\"items\": [\n  {\"word\": \"x\", \"topics\": [1]}\n]}", 2, 27, '"topics" חייב להיות רשימה');
    n1_bad("{\"items\": [\n  {\"word\": \"x\", \"topics\": {}}\n]}", 2, 27, '"topics" חייב להיות רשימה');
    n1_bad("{\"items\": [\n  []\n]}", 2, 3, 'פריט חייב להיות אובייקט');
    n1_bad("{\"items\": [\n  {\"word\": \"x\", \"color\": \"red\"}\n]}", 2, 17, 'שדה לא מוכר "color"');
    n1_bad("{\"items\": [\n  {\"word\": \"x\", \"__proto__\": {}}\n]}", 2, 17, 'שדה לא מוכר "__proto__"');
    n1_bad("{\"items\": [], \"x\": 1}", 1, 15, 'שדה לא מוכר "x"');
    n1_bad("{\"items\": [\n  {\"word\": \"a\", \"word\": \"b\"}\n]}", 2, 17, 'מופיע פעמיים באותו אובייקט');
    n1_bad("{\"items\": [\n  \"x\"\n]}", 2, 3, 'פריט חייב להיות אובייקט');
    n1_bad('{"items": ' . str_repeat('[', 200) . str_repeat(']', 200) . '}', 1, 75, 'עומק');
    n1_bad("{\"items\": [\n  {\"word\": \"\xff\xfe\"}\n]}", 2, 1, 'UTF-8');
    n1_bad("{\"items\": [\n  {\"word\": \"a\tb\"}\n]}", 2, 12, 'מחרוזת לא תקינה');
    n1_bad("{\"items\": []} x", 1, 15, 'אחרי סוף');
    // wp_id אינו נערך
    $w = $j; foreach ($w['items'] as &$it) if (isset($it['wp_id'])) { $it['wp_id'] = 1; break; } unset($it);
    $r = n1_save(n1_enc($w), false);
    eq(422, $r['status']);
    ok(str_contains($r['json']['errors'][0]['msg'], 'wp_id'), 'wp_id לקריאה בלבד');
    // שמירה אוטומטית — לא
    eq(400, b_post('pf_write', ['id' => -1, 'rev' => n1_read()['json']['file']['rev'], 'content' => $ok, 'auto' => true])['status'], 'שמירה אוטומטית נדחית');
});

test('N1 שמירה: "מה ישתנה" לפי id; מילה בלי id מקבלת מזהה; סדר; עסקה אחת, audit אחד; הקובץ חוזר עם המזהה', function () {
    $ids = n1_seed(8);
    n1_kol();
    $fp = n1_fp();
    $j = n1_file();
    $first = $j['items'][0];
    $j['items'][0]['meaning'] = '<p>פירוש חדש</p>';
    $j['items'][] = ['word' => 'מילה חדשה', 'meaning' => '', 'topics' => ['food'], 'audio' => ''];
    [$j['items'][1], $j['items'][2]] = [$j['items'][2], $j['items'][1]];
    $p = n1_save(n1_enc($j), false);
    eq(200, $p['status'], json_encode($p['json'], JSON_UNESCAPED_UNICODE));
    $plan = $p['json']['plan'];
    eq(['changed' => 1, 'added' => 1, 'trashed' => 0, 'moved' => 1, 'topics' => 0], $plan['counts']);
    eq('תשתנה מילה אחת · תתווסף 1 · יעברו לסל 0 · ישתנה הסדר של 1', $plan['summary']);
    eq(['<p>פירוש <b>' . ($first['id'] - 1) . '</b></p>', '<p>פירוש חדש</p>'], $plan['changed'][0]['fields']['meaning'], 'לפני ← אחרי');
    eq($fp, n1_fp(), 'התוכנית לבדה אינה כותבת');
    $audits = (int)db()->query("SELECT COUNT(*) FROM audit WHERE action = 'words_file'")->fetchColumn();
    $r = n1_save(n1_enc($j));
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    $new = $r['json']['done'] ? $r['json']['new_ids'][0] : 0;
    eq(max($ids) + 1, $new, 'המספר הבא');
    $after = json_decode($r['json']['content'], true);
    eq($new, $after['items'][8]['id'], 'הקובץ חוזר עם המזהה, בסוף');
    eq('<p>פירוש חדש</p>', $after['items'][0]['meaning']);
    eq(array_column($j['items'], 'word'), array_column($after['items'], 'word'), 'הסדר בקובץ = הסדר בטבלה');
    eq(array_column($after['items'], 'id'), array_column(words_rows(), 'id'));
    eq($audits + 1, (int)db()->query("SELECT COUNT(*) FROM audit WHERE action = 'words_file'")->fetchColumn(), 'audit אחד');
    ok(str_contains((string)db()->query("SELECT detail FROM audit WHERE action = 'words_file' ORDER BY id DESC LIMIT 1")->fetchColumn(), '1 שונו, 1 נוספו'));
    eq(['עורך בדיקה'], array_values(array_unique(db()->query("SELECT updated_by FROM words WHERE id IN ({$first['id']}, $new)")->fetchAll(PDO::FETCH_COLUMN))), 'updated_by');
    // אותו קובץ שוב — 0 שינויים
    $p2 = n1_save($r['json']['content'], false);
    eq(['changed' => 0, 'added' => 0, 'trashed' => 0, 'moved' => 0, 'topics' => 0], $p2['json']['plan']['counts'], 'שמירה חוזרת — כלום');
    // ההיסטוריה של הקובץ — השמירה, מתי ומי
    $v = b_get('pf_versions', '&id=-1')['json'];
    ok(str_contains($v['versions'][0]['note'], 'words.json') && $v['versions'][0]['by'] === 'עורך בדיקה', 'בהיסטוריה');
});

test('N1 מחיקה: מילה שאינה בקובץ — לסל עם ההקלטה המקושרת; נקודת שחזור; מעל 20 — רק מפרסם; id שהשתנה = נמחקה ונוספה', function () {
    n1_seed(30);
    n1_kol();
    $j = n1_file();
    $gone = $j['items'][1];
    ok($gone['audio'] !== '' || true);
    $withAudio = array_values(array_filter($j['items'], fn($x) => $x['audio'] !== ''))[0];
    $j['items'] = array_values(array_filter($j['items'], fn($x) => $x['id'] !== $withAudio['id']));
    $snaps = (int)db()->query('SELECT COUNT(*) FROM snapshots')->fetchColumn();
    $r = n1_save(n1_enc($j));
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    eq(1, $r['json']['done']['trashed']);
    ok($r['json']['snapshot'] > 0 && (int)db()->query('SELECT COUNT(*) FROM snapshots')->fetchColumn() === $snaps + 1, 'נקודת שחזור לפני');
    $t = db()->query('SELECT * FROM words_trash')->fetchAll();
    eq(1, count($t));
    $row = json_decode($t[0]['row'], true);
    eq([$withAudio['id'], $withAudio['audio']], [(int)$row['id'], $row['audio']], 'בסל — עם ההקלטה');
    // שחזור מהסל מחזיר אותה בדיוק
    trash_restore(N1_ED, ['kind' => 'word', 'id' => (int)$t[0]['id']]);
    ok(in_array($withAudio['id'], array_column(words_rows(), 'id'), true), 'חזרה מהסל');
    // מחיקה של 21 — עורך נדחה, מפרסם עובר
    $j = n1_file();
    $keep = array_slice($j['items'], 0, count($j['items']) - 21);
    $fp = n1_fp();
    $r = n1_save(n1_enc(['topics' => $j['topics'], 'items' => $keep]));
    eq(403, $r['status'], 'עורך — 403');
    eq($fp, n1_fp());
    $r = n1_save(n1_enc(['topics' => $j['topics'], 'items' => $keep]), true, [], U_PUBLISHER);
    eq(200, $r['status']);
    eq(21, $r['json']['done']['trashed']);
    // id שהשתנה
    $j = n1_file();
    $old = $j['items'][0]['id'];
    $j['items'][0]['id'] = 5000;
    $p = n1_save(n1_enc($j), false)['json']['plan'];
    eq([1, 1], [$p['counts']['trashed'], $p['counts']['added']], 'נמחקה אחת ונוספה אחת');
    eq([$old], array_column($p['trashed'], 'id'));
    ok(str_contains(json_encode($p['warnings'], JSON_UNESCAPED_UNICODE), 'המפתח של ההקלטה'), 'האזהרה על המזהה');
});

test('N1 נושאים: מפתח בקובץ ← שם בטבלה; נושא שאינו קיים — מוצע, ונוצר רק באישור; הקלטה שאינה קיימת — אזהרה', function () {
    n1_seed(5);
    n1_kol();
    $j = n1_file();
    $id = $j['items'][0]['id'];
    $j['items'][0]['topics'] = ['family', 'proverbs'];
    $j['topics'][] = ['id' => 'proverbs', 'label' => 'פתגמים'];
    $fp = n1_fp();
    $r = n1_save(n1_enc($j), false);
    eq(422, $r['status']);
    eq('proverbs', $r['json']['errors'][0]['propose']);
    eq([['slug' => 'proverbs', 'name' => 'פתגמים']], array_map(fn($p) => ['slug' => $p['slug'], 'name' => $p['name']], $r['json']['proposals']));
    eq($fp, n1_fp());
    $r = n1_save(n1_enc($j), true, ['create_topics' => ['proverbs']]);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    eq('פתגמים', db()->query("SELECT name FROM topics WHERE slug = 'proverbs'")->fetchColumn(), 'נוסף לטבלת הנושאים');
    $st = db()->prepare('SELECT topics FROM words WHERE id = ?');
    $st->execute([$id]);
    eq(['משפחה', 'פתגמים'], json_decode($st->fetchColumn(), true), 'בטבלה — שמות');
    eq(['family', 'proverbs'], n1_file()['items'][0]['topics'], 'בקובץ — מפתחות');
    // מפתח עוין
    $j = n1_file();
    $j['items'][0]['topics'] = ['<script>'];
    eq(422, n1_save(n1_enc($j), false, ['create_topics' => ['<script>']])['status'], 'מפתח נושא לא תקין');
    // הקלטה
    $j = n1_file();
    $j['items'][0]['audio'] = 'https://evil.example/x.mp3';
    $j['items'][1]['audio'] = 'https://' . lex_host() . '/wp-content/uploads/new.mp3';
    $before0 = db()->query("SELECT audio FROM words WHERE id = {$j['items'][0]['id']}")->fetchColumn();
    $p = n1_save(n1_enc($j), false)['json']['plan'];
    ok(str_contains(json_encode($p['warnings'], JSON_UNESCAPED_UNICODE), 'לא נמצאה'), 'אזהרה');
    eq(1, $p['counts']['changed'], 'רק ההקלטה שבאתר המחובר');
    n1_save(n1_enc($j));
    eq($before0, db()->query("SELECT audio FROM words WHERE id = {$j['items'][0]['id']}")->fetchColumn(), 'כתובת זרה — נשארה כמו שהייתה');
});

test('N1 עוין: HTML בפירוש מנוקה כמו בטבלה; תווים מיוחדים במילה נשמרים כטקסט; הקובץ שנבנה הוא JSON תקין', function () {
    n1_seed(3);
    n1_kol();
    $j = n1_file();
    $j['items'][0]['meaning'] = '<p onclick="x()">טוב</p><script>alert(1)</script><img src=x onerror=alert(1)>';
    $j['items'][1]['word'] = '"</script><b>x</b>\\u0000';
    $r = n1_save(n1_enc($j));
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    $f = json_decode($r['json']['content'], true);
    ok(!str_contains($f['items'][0]['meaning'], 'script') && !str_contains($f['items'][0]['meaning'], 'onerror') && !str_contains($f['items'][0]['meaning'], 'onclick'), 'נוקה: ' . $f['items'][0]['meaning']);
    eq('"</script><b>x</b>\\u0000', $f['items'][1]['word'], 'המילה — טקסט כמו שהוא (הקובץ אינו HTML)');
    ok(json_decode(words_json()) !== null);
});

test('N1 התנגשות ועסקה: rev ישן — 409 עם התוכן; אסימון ישן — 409; כשל באמצע — דבר לא השתנה', function () {
    n1_seed(6);
    n1_kol();
    $r0 = n1_read()['json'];
    $j = json_decode($r0['content'], true);
    $j['items'][0]['meaning'] = 'מהעורך';
    word_save(N1_ED, ['id' => $j['items'][1]['id'], 'word' => 'בינתיים בטבלה', 'meaning' => '']);
    $c = b_post('pf_write', ['id' => -1, 'rev' => $r0['file']['rev'], 'content' => n1_enc($j)]);
    eq(409, $c['status']);
    eq(words_json(), $c['json']['conflict']['content'], 'הגרסה שבשרת — להשוואה');
    // אסימון של תוכנית שהתיישנה
    $j = n1_file();
    $j['items'][0]['meaning'] = 'מהעורך';
    $plan = words_file_save(N1_ED, n1_enc($j), [])['plan'];
    word_save(N1_ED, ['id' => $j['items'][2]['id'], 'word' => 'עוד שינוי', 'meaning' => '']);
    $fp = n1_fp();
    eq(409, throws(fn() => words_file_save(N1_ED, n1_enc($j), ['apply' => true, 'token' => $plan['token']])), 'אסימון ישן');
    eq($fp, n1_fp());
    // כשל באמצע: עדכון ואחריו הוספה שנכשלת (טריגר במסד) — גם העדכון לא נשאר
    db()->exec("CREATE TRIGGER n1_boom BEFORE INSERT ON words WHEN NEW.word = 'בום' BEGIN SELECT RAISE(ABORT, 'boom'); END");
    try {
        $j = n1_file();
        $j['items'][0]['meaning'] = 'עדכון שלא יישאר';
        $j['items'][] = ['word' => 'בום'];
        $plan = words_file_save(N1_ED, n1_enc($j), [])['plan'];
        $fp = n1_fp();
        $failed = false;
        try { words_file_save(N1_ED, n1_enc($j), ['apply' => true, 'token' => $plan['token']]); } catch (PDOException) { $failed = true; }
        ok($failed, 'נכשל');
        eq($fp, n1_fp(), 'שום דבר לא השתנה');
        ok(!db()->inTransaction(), 'העסקה נסגרה');
    } finally {
        db()->exec('DROP TRIGGER n1_boom');
    }
});

test('N1 גרירה: word_move — רק השורות שזזו נכתבות; "העבר ל…"; בחירה מרובה; אין מקום — מספור מחדש', function () {
    $ids = n1_seed(10);
    words_order_fill();
    $order = array_column(words_rows(), 'id');
    $snap = fn() => array_column(db()->query('SELECT id, sort_order FROM words')->fetchAll(), 'sort_order', 'id');
    $s0 = $snap();
    $last = $order[9];
    $r = http('POST', '/api.php?action=word_move', ['user' => U_EDITOR, 'json' => ['ids' => [$last], 'to' => 1]]);
    eq(200, $r['status']);
    eq(1, $r['json']['moved']);
    eq($last, array_column(words_rows(), 'id')[0], 'ראשונה');
    $s1 = $snap();
    eq(1, count(array_diff_assoc($s1, $s0)), 'נכתבה שורה אחת');
    // בחירה מרובה לפני מילה
    word_move(N1_ED, ['ids' => [$order[2], $order[5]], 'before' => $order[8]]);
    $o = array_column(words_rows(), 'id');
    eq([$order[2], $order[5], $order[8]], array_slice($o, array_search($order[2], $o, true), 3), 'יחד, לפני היעד');
    // אין מקום בין שכנים
    db()->exec('UPDATE words SET sort_order = id');
    $o = array_column(words_rows(), 'id');
    [$set] = words_order_assign([$o[0], $o[2], $o[1]], [$o[0] => 1, $o[1] => 2, $o[2] => 3]);
    ok(count($set) >= 1, 'מספור');
    word_move(N1_ED, ['ids' => [$o[5]], 'before' => $o[1]]);
    eq($o[5], array_column(words_rows(), 'id')[1], 'גם בלי מקום — הסדר נכון');
    eq(403, http('POST', '/api.php?action=word_move', ['user' => U_STRANGER, 'json' => ['ids' => [$o[0]], 'to' => 3]])['status'], 'זר — 403');
    eq(404, http('POST', '/api.php?action=word_move', ['user' => U_EDITOR, 'json' => ['ids' => [999999], 'to' => 1]])['status'], 'מילה שאינה קיימת');
    // הסדר בטבלה ← הקובץ השתנה (rev)
    n1_kol();
    $rev = n1_read()['json']['file']['rev'];
    word_move(N1_ED, ['ids' => [$o[3]], 'to' => 1]);
    ok($rev !== n1_read()['json']['file']['rev'], 'גרירה בטבלה — הקובץ השתנה');
    eq($o[3], n1_file()['items'][0]['id']);
});

test('N1 אקסל: ייצוא ← ייבוא = 0 שינויים (גם עם נוסחאות ופסיקים); תצוגה לפי id; אינו מוחק; "החלף הכול" — מפרסם; קידוד', function () {
    n1_seed(12);
    $first = array_column(words_rows(), 'id')[0];
    word_save(N1_ED, ['id' => $first, 'word' => '=HYPERLINK("http://evil")', 'meaning' => '<p>+1 שורה</p><p>-שנייה, עם פסיק</p>', 'topics' => ['אוכל, ושתייה', 'ברכות']]);
    $csv = http('GET', '/api.php?action=words_csv', ['user' => U_EDITOR])['body'];
    ok(str_starts_with($csv, "\xEF\xBB\xBF"), 'BOM');
    eq(['מזהה', 'מילה', 'פירוש', 'נושאים', 'הקלטה', 'מספר מהאקסל', 'סדר', 'מזהה באתר'], str_getcsv(substr(strtok($csv, "\n"), 3), ',', '"', ''), 'עמודות');
    ok(str_contains($csv, "'=HYPERLINK"), 'נוסחה מנוטרלת בייצוא');
    $fp = n1_fp();
    $p = http('POST', '/api.php?action=words_csv_import', ['user' => U_EDITOR, 'json' => ['csv' => $csv]]);
    eq(200, $p['status'], json_encode($p['json'], JSON_UNESCAPED_UNICODE));
    eq(['changed' => 0, 'added' => 0, 'trashed' => 0, 'moved' => 0, 'topics' => 0], $p['json']['plan']['counts'], 'הלוך-חזור — 0 שינויים');
    eq('UTF-8 (עם BOM)', $p['json']['encoding']);
    eq($fp, n1_fp(), 'התצוגה אינה כותבת');
    // עריכה בגיליון: שינוי, שורה חדשה בלי id, שורה שנמחקה (לא נמחקת), סדר
    [$rows] = words_csv_read($csv);
    $rows[1][2] = 'פירוש מהאקסל';
    $gone = (int)$rows[2][0];
    unset($rows[2]);
    $rows[] = ['', 'מאקסל', 'חדשה', 'נושא מאקסל', '', '', '', ''];
    $rows[3][6] = '5';
    $out = fopen('php://temp', 'w+');
    foreach ($rows as $r) fputcsv($out, $r, ',', '"', '');
    rewind($out);
    $csv2 = stream_get_contents($out);
    $p = http('POST', '/api.php?action=words_csv_import', ['user' => U_EDITOR, 'json' => ['csv' => $csv2]])['json'];
    eq(['changed' => 1, 'added' => 1, 'trashed' => 0, 'moved' => 1, 'topics' => 1], $p['plan']['counts']);
    eq('UTF-8', $p['encoding']);
    $a = http('POST', '/api.php?action=words_csv_import', ['user' => U_EDITOR, 'json' => ['csv' => $csv2, 'apply' => true, 'token' => $p['plan']['token']]]);
    eq(200, $a['status'], json_encode($a['json'], JSON_UNESCAPED_UNICODE));
    ok(in_array($gone, array_column(words_rows(), 'id'), true), 'שורה שנמחקה מהגיליון — לא נמחקה');
    eq((int)$rows[3][0], array_column(words_rows(), 'id')[0], 'סדר 5 — ראשונה');
    eq('נושא מאקסל', db()->query("SELECT name FROM topics WHERE name = 'נושא מאקסל'")->fetchColumn());
    // "החלף הכול": מה שאינו בגיליון — לסל. עורך נדחה; מפרסם עם נקודת שחזור
    $p = http('POST', '/api.php?action=words_csv_import', ['user' => U_EDITOR, 'json' => ['csv' => $csv2, 'replace_all' => true]])['json'];
    eq(2, $p['plan']['counts']['trashed'], 'השורה שנמחקה, והמילה "מאקסל" מהייבוא הקודם (בגיליון היא שוב בלי מזהה — מילה חדשה)');
    eq('publisher', $p['plan']['needs']);
    eq(403, http('POST', '/api.php?action=words_csv_import', ['user' => U_EDITOR, 'json' => ['csv' => $csv2, 'replace_all' => true, 'apply' => true, 'token' => $p['plan']['token']]])['status']);
    $a = http('POST', '/api.php?action=words_csv_import', ['user' => U_PUBLISHER, 'json' => ['csv' => $csv2, 'replace_all' => true, 'apply' => true, 'token' => $p['plan']['token']]]);
    eq(200, $a['status'], json_encode($a['json'], JSON_UNESCAPED_UNICODE));
    ok($a['json']['snapshot'] > 0, 'נקודת שחזור');
    ok(!in_array($gone, array_column(words_rows(), 'id'), true) && (int)db()->query("SELECT COUNT(*) FROM words_trash WHERE word_id = $gone")->fetchColumn() === 1, 'לסל');
    // Windows-1255, שגיאות עם מספר שורה
    $w1255 = iconv('UTF-8', 'WINDOWS-1255', "מזהה,מילה,פירוש\n,שלום,ברכה\nabc,x,y\n,,ריקה\n");
    $r = http('POST', '/api.php?action=words_csv_import', ['user' => U_EDITOR, 'json' => ['csv_b64' => base64_encode($w1255)]]);
    eq(422, $r['status']);
    eq('Windows-1255 (אקסל עברי ישן)', $r['json']['encoding']);
    eq(['שורה 3: מזהה לא תקין "abc" (מספר שלם חיובי, או ריק — מילה חדשה)', 'שורה 4: המילה ריקה'], array_column($r['json']['errors'], 'msg'));
    $ok = http('POST', '/api.php?action=words_csv_import', ['user' => U_EDITOR, 'json' => ['csv_b64' => base64_encode(iconv('UTF-8', 'WINDOWS-1255', "מזהה,מילה,פירוש\n,שלום,ברכה\n"))]])['json'];
    eq('שלום', $ok['plan']['added'][0]['word'], 'עברית מ-Windows-1255');
    eq(422, http('POST', '/api.php?action=words_csv_import', ['user' => U_EDITOR, 'json' => ['csv' => "a,b\n1,2\n"]])['status'], 'בלי עמודת מילה');
});

test('N1 מנהל קבצים: מחיקה אינה מוחקת מילים (מסירה את data.words מ-kol.json); שינוי שם — ב-kol.json; שכפול — עותק סטטי', function () {
    n1_seed(5);
    n1_kol();
    $fp = n1_fp();
    $d = b_post('pf_delete', ['id' => -1]);
    eq(409, $d['status']);
    eq('unlink', $d['json']['action']);
    ok(str_contains($d['json']['error'], 'data.words'), 'ההסבר');
    eq(409, b_post('pf_rename', ['id' => -1, 'to' => 'data/w2.json'])['status'], 'שינוי שם — ב-kol.json');
    $c = b_post('pf_copy', ['id' => -1, 'to' => 'data/words-copy.json']);
    eq(200, $c['status'], json_encode($c['json'], JSON_UNESCAPED_UNICODE));
    ok($c['json']['static_copy']);
    eq(words_json(), pf_get($c['json']['file']['id']), 'עותק סטטי');
    $u = b_post('pf_delete', ['id' => -1, 'unlink' => true]);
    eq(200, $u['status'], json_encode($u['json'], JSON_UNESCAPED_UNICODE));
    ok(!isset(json_decode(pf_get(pf_paths()['kol.json']), true)['data']['words']), 'data.words הוסר');
    eq($fp, n1_fp(), 'המילים לא נגעו');
    eq(404, n1_read()['status'], 'הקובץ אינו בעץ');
    n1_kol();
    eq(200, n1_read()['status'], 'וחוזר כש-data.words חוזר');
    b_post('pf_delete', ['id' => pf_paths()['data/words-copy.json']]);
});

test('N1 ZIP: הקובץ בתוך ה-ZIP; ייבוא ZIP שבו הוא שונה — דרך המנוע (תוכנית, ואז הטבלה)', function () {
    n1_seed(5);
    n1_kol();
    $tmp = tempnam(sys_get_temp_dir(), 'n1z');
    file_put_contents($tmp, http('GET', '/api.php?action=pf_zip', ['user' => U_EDITOR])['body']);
    $z = new ZipArchive();
    $z->open($tmp);
    eq(words_json(), $z->getFromName('data/words.json'), 'ב-ZIP — מהטבלה');
    $z->close(); unlink($tmp);
    $j = n1_file();
    $j['items'][0]['meaning'] = 'מה-ZIP';
    $zip = b_zip([['data/words.json', n1_enc($j)]]);
    $fp = n1_fp();
    $p = b_zip_post($zip);
    eq(200, $p['status'], json_encode($p['json'], JSON_UNESCAPED_UNICODE));
    eq(1, $p['json']['plan']['words']['counts']['changed']);
    eq(['data/words.json'], $p['json']['plan']['replace']);
    eq($fp, n1_fp());
    $a = b_zip_post($zip, '&apply=1&token=' . $p['json']['plan']['token']);
    eq(200, $a['status'], json_encode($a['json'], JSON_UNESCAPED_UNICODE));
    eq('מה-ZIP', n1_file()['items'][0]['meaning']);
    eq(0, (int)db()->query("SELECT COUNT(*) FROM pf_files WHERE path = 'data/words.json'")->fetchColumn(), 'לא נוצר קובץ רגיל');
    // ZIP עם קובץ מילים שבור — נדחה, הטבלה לא משתנה
    $fp = n1_fp();
    $p = b_zip_post(b_zip([['data/words.json', '{"items": [{"id": "x"}]}']]));
    ok(str_contains(json_encode($p['json']['plan']['errors'], JSON_UNESCAPED_UNICODE), 'טבלת המילים'), 'שגיאה בתוכנית');
    eq(409, b_zip_post(b_zip([['data/words.json', '{"items": [{"id": "x"}]}']]), '&apply=1&token=' . $p['json']['plan']['token'])['status']);
    eq($fp, n1_fp());
});

test('N1 שלוש הדלתות: טבלה ← קובץ ← תצוגה חיה ← בנייה — אותם נתונים', function () {
    n1_seed(6);
    n1_kol();
    $id = array_column(words_rows(), 'id')[0];
    word_save(N1_ED, ['id' => $id, 'word' => 'מהטבלה', 'meaning' => 'פ']);
    eq('מהטבלה', n1_file()['items'][array_search($id, array_column(n1_file()['items'], 'id'), true)]['word'], 'טבלה ← קובץ');
    $j = n1_file();
    $j['items'][0]['word'] = 'מהקובץ';
    eq(200, n1_save(n1_enc($j))['status']);
    $st = db()->prepare('SELECT word FROM words WHERE id = ?');
    $st->execute([$j['items'][0]['id']]);
    eq('מהקובץ', $st->fetchColumn(), 'קובץ ← טבלה');
    $live = json_decode(pv_get('data/words.json')['body'], true);
    eq(array_column(n1_file()['items'], 'word'), array_column($live['items'], 'word'), 'התצוגה החיה');
    [$files] = build_words([]);
    eq(array_column($live['items'], 'word'), array_column(json_decode(pf_blob_get($files['data/words.json']['sha']), true)['items'], 'word'), 'הבנייה');
});
