<?php
/**
 * פרוסה N2b — הקלטה שנייה (וכל שדה שמע) בספרייה (docs/spec-v2/slices/N2b-more-recordings.md). כל בדיקה מול קריטריון ב-§2.
 * המילים 9800–9899 (ו-20000+ בבדיקת המיגרציה) והקבצים "a3-…" — של הבדיקה, ונמחקים בסוף. בלי רשת.
 */

declare(strict_types=1);

require_once KOL_ROOT . '/lib/library.php';

const N2B_FR = 'audio_fr';

/** הסכמה של recordings לפני N2b (A2 + העמודות ש-A3 הוסיפה ב-ALTER), בשם $t. $nameType — לבדיקת הביטול בלבד. */
function n2b_old_schema(string $t, string $nameType = 'TEXT'): string
{
    return "CREATE TABLE $t (id INTEGER PRIMARY KEY, word_id INTEGER UNIQUE, state TEXT NOT NULL DEFAULT 'site' CHECK (state IN ('site','tool','failed')),
        source TEXT NOT NULL DEFAULT '', file_id TEXT, name $nameType NOT NULL DEFAULT '', duration REAL, how TEXT NOT NULL DEFAULT '', at TEXT NOT NULL,
        by_name TEXT NOT NULL DEFAULT '', scan TEXT NOT NULL DEFAULT '', scan_size INTEGER, scan_type TEXT NOT NULL DEFAULT '', scan_note TEXT NOT NULL DEFAULT '',
        scan_at TEXT, f_code TEXT NOT NULL DEFAULT '', f_note TEXT NOT NULL DEFAULT '', f_at TEXT,
        deleted_at TEXT, deleted_by TEXT NOT NULL DEFAULT '', deleted_word INTEGER, deleted_audio TEXT NOT NULL DEFAULT '')";
}

const N2B_OLD_COLS = 'id, word_id, state, source, file_id, name, duration, how, at, by_name, scan, scan_size, scan_type, scan_note, scan_at, f_code, f_note, f_at, deleted_at, deleted_by, deleted_word, deleted_audio';

/** recordings חוזרת לסכמה של לפני N2b (מסד בגרסה 19), עם אותן שורות. */
function n2b_downgrade(PDO $pdo, string $nameType = 'TEXT'): void
{
    $pdo->exec(n2b_old_schema('rec_old', $nameType));
    $pdo->exec('INSERT INTO rec_old (' . N2B_OLD_COLS . ') SELECT ' . N2B_OLD_COLS . " FROM recordings WHERE list = 'words' AND field = 'audio'");
    $pdo->exec('DROP TABLE recordings; ALTER TABLE rec_old RENAME TO recordings; PRAGMA user_version = 19');
}

/** שדה "הקלטה בצרפתית" (audio_fr), ומילים 9800+ [id => [ראשי, צרפתית]]. מחזיר ניקוי. */
function n2b_fixture(array $words, bool $published = true): callable
{
    $pdo = db();
    $pdo->exec("DELETE FROM word_fields; DELETE FROM field_trash");
    field_save(A3_OWNER, ['key' => N2B_FR, 'label' => 'הקלטה בצרפתית', 'type' => 'audio', 'published' => $published]);
    $ins = $pdo->prepare('INSERT INTO words (id, wp_id, word, meaning, topics, audio, external_id, extra, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($words as $id => [$a, $fr]) {
        $ins->execute([$id, $id + 70000, "מילה $id", '<p>פירוש</p>', '[]', $a, '', $fr !== '' ? json_encode([N2B_FR => $fr]) : '{}', now_iso()]);
    }
    rec_sync();
    $a3 = a3_fixture([]);                                       // הניקוי של A3: קבצי a3-…, רשומות לא מחוברות, גרסאות
    return function () use ($pdo, $a3) {
        $pdo->exec('DELETE FROM words WHERE id >= 9800 AND id < 9900');
        $pdo->exec('DELETE FROM words_trash WHERE word_id >= 9800 AND word_id < 9900');
        $pdo->exec("DELETE FROM word_fields; DELETE FROM field_trash; DELETE FROM pf_files WHERE path LIKE 'media/audio/98%'");
        $pdo->exec('DELETE FROM recordings WHERE deleted_word >= 9800 AND deleted_word < 9900');
        $a3();
    };
}

function n2b_rec(int $wid, string $field = N2B_FR): array
{
    return rec_of_word($wid, $field) ?? [];
}

function n2b_fr(int $wid): string
{
    return rec_slot_get('words', $wid, N2B_FR);
}

/** השורה של מקום ברשימת הספרייה. */
function n2b_row(array $lib, int $wid, string $field = N2B_FR): ?array
{
    foreach ($lib['rows'] as $r) if (($r['wid'] ?? $r['id']) === $wid && ($r['field'] ?? 'audio') === $field) return $r;
    return null;
}

/** בלי השדות של N2b — להשוואה עם מה שהיה לפני. */
function n2b_strip(array $rows): array
{
    return array_map(fn($r) => array_diff_key($r, ['list' => 1, 'field' => 1, 'deleted_list' => 1, 'deleted_field' => 1]), $rows);
}

// ── §2.1 המודל — מיגרציה אחת ─────────────────────────────

test('N2b · מיגרציה 20 על מסד בגודל אמיתי (1,500 הקלטות + גרסאות + סל): אותן רשומות, אותן גרסאות, אותו סל; words.json ו-rec_rows זהים', function () {
    $pdo = db();
    $clean = a3_fixture([]);
    try {
        $pdo->exec("DELETE FROM word_fields");
        // 1,500 מילים: 1,200 כתובות באתר, 300 קבצים בכלי
        $fids = [];
        for ($i = 0; $i < 300; $i++) $fids[] = a3_file('mp3', "a3-mig-$i.mp3");
        $ins = $pdo->prepare('INSERT INTO words (id, wp_id, word, meaning, topics, audio, external_id, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $pdo->exec('BEGIN');
        for ($i = 0; $i < 1500; $i++) {
            $id = 20000 + $i;
            $ins->execute([$id, $id + 70000, "מיגרציה $i", '<p>פ</p>', '[]', $i < 300 ? $fids[$i] : A3_U . "2024/01/m$i.mp3", '', now_iso()]);
        }
        $pdo->exec('COMMIT');
        rec_sync();
        // גרסאות ל-200 הקלטות, סל ל-40, ומשך ל-100
        $ids = $pdo->query('SELECT id FROM recordings WHERE word_id >= 20000 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        eq(1500, count($ids), '1,500 רשומות');
        $v = $pdo->prepare("INSERT INTO rec_versions (rec_id, file_id, source, name, how, since, at, by_name) VALUES (?, NULL, ?, 'ישנה', 'linked', ?, ?, 'בדיקה')");
        foreach (array_slice($ids, 0, 200) as $rid) $v->execute([$rid, A3_U . "old/$rid.mp3", now_iso(), now_iso()]);
        $pdo->exec('UPDATE recordings SET duration = 1.25 WHERE word_id >= 20000 AND word_id < 20100');
        rec_delete(A3_ED, ['ids' => array_map('intval', array_slice($ids, 0, 40))]);
        $before = [
            'json' => words_json(), 'rows' => n2b_strip(rec_rows()), 'trash' => rec_trash(), 'use' => files_in_use(),
            'versions' => $pdo->query('SELECT * FROM rec_versions ORDER BY id')->fetchAll(),
            'all' => n2b_strip($pdo->query('SELECT * FROM recordings ORDER BY id')->fetchAll()),
            'lib' => array_map(fn($r) => array_diff_key($r, ['field' => 1, 'field_label' => 1, 'wid' => 1, 'internal' => 1]), library_list()['rows']),
        ];
        $n = (int)$pdo->query('SELECT COUNT(*) FROM recordings')->fetchColumn();
        n2b_downgrade($pdo);
        ok(str_contains((string)$pdo->query("SELECT sql FROM sqlite_master WHERE name = 'recordings'")->fetchColumn(), 'word_id INTEGER UNIQUE'), 'לפני: הסכמה הישנה');
        $t0 = microtime(true);
        migrate($pdo);
        $ms = (int)((microtime(true) - $t0) * 1000);
        eq(20, (int)$pdo->query('PRAGMA user_version')->fetchColumn(), 'הגרסה');
        $m = $GLOBALS['kol_migrate_20'] ?? [];
        eq($n, $m['recordings'] ?? null, 'הספירה לפני (מהמיגרציה)');
        eq($n, (int)$pdo->query('SELECT COUNT(*) FROM recordings')->fetchColumn(), 'הספירה אחרי');
        fwrite(STDERR, "   מיגרציה 20: $n רשומות, " . ($m['versions'] ?? 0) . " גרסאות, $ms ms · sha " . substr($m['sha'] ?? '', 0, 12) . "\n");
        $after = [
            'json' => words_json(), 'rows' => n2b_strip(rec_rows()), 'trash' => array_map(fn($t) => array_diff_key($t, ['field' => 1, 'field_label' => 1, 'primary' => 1]), rec_trash()), 'use' => files_in_use(),
            'versions' => $pdo->query('SELECT * FROM rec_versions ORDER BY id')->fetchAll(),
            'all' => n2b_strip($pdo->query('SELECT * FROM recordings ORDER BY id')->fetchAll()),
            'lib' => array_map(fn($r) => array_diff_key($r, ['field' => 1, 'field_label' => 1, 'wid' => 1, 'internal' => 1]), library_list()['rows']),
        ];
        $before['trash'] = array_map(fn($t) => array_diff_key($t, ['field' => 1, 'field_label' => 1, 'primary' => 1]), $before['trash']);
        foreach ($before as $k => $want) ok($want === $after[$k], "זהה אחרי המיגרציה: $k");
        // הסל — המקום נזכר: deleted_list / deleted_field של השדה הראשי
        eq(40, (int)$pdo->query("SELECT COUNT(*) FROM recordings WHERE deleted_at IS NOT NULL AND deleted_field = 'audio' AND deleted_list = 'words' AND deleted_word >= 20000")->fetchColumn(), 'הסל — המקום');
        eq(0, (int)$pdo->query("SELECT COUNT(*) FROM recordings WHERE list != 'words' OR field != 'audio'")->fetchColumn(), 'כל הקיימות — של הראשי');
        // הסכמה החדשה: UNIQUE לפי מקום — אותה מילה בשדה אחר מותרת, אותו מקום פעמיים לא
        $rid = (int)$pdo->query('SELECT id FROM recordings WHERE word_id = 20500')->fetchColumn();
        $pdo->exec("INSERT INTO recordings (list, word_id, field, state, at) VALUES ('words', 20500, 'audio_x', 'tool', 'x')");
        ok(throws_pdo(fn() => $pdo->exec("INSERT INTO recordings (list, word_id, field, state, at) VALUES ('words', 20500, 'audio', 'tool', 'x')")), 'אותו מקום פעמיים — נדחה');
        $pdo->exec("DELETE FROM recordings WHERE field = 'audio_x'");
        ok($rid > 0, 'המזהה נשמר');
        // השחזור מהסל עובד אחרי המיגרציה — חוזר לאותו מקום
        $t = rec_trash()[0];
        $res = rec_restore(A3_ED, ['id' => $t['rec']]);
        eq([$t['word_id'], 'audio'], [$res['word_id'], $res['field']], 'שחזור אחרי המיגרציה — לאותו מקום');
    } finally {
        $pdo->exec('DELETE FROM rec_versions WHERE rec_id IN (SELECT id FROM recordings WHERE word_id >= 20000 OR deleted_word >= 20000)');
        $pdo->exec('DELETE FROM recordings WHERE word_id >= 20000 OR deleted_word >= 20000');
        $pdo->exec('DELETE FROM words WHERE id >= 20000 AND id < 21500');
        $clean();
    }
});

function throws_pdo(callable $fn): bool
{
    try { $fn(); } catch (PDOException) { return true; }
    return false;
}

test('N2b · מיגרציה 20: checksum שאינו תואם — ROLLBACK, והמסד נשאר כמו שהיה (גרסה 19, הטבלה הישנה, אותן שורות)', function () {
    $pdo = tmp_pdo('n2b-rollback');
    migrate($pdo);
    // טבלה ישנה שבה ההעתקה משנה ערך (name INTEGER ← TEXT): המיגרציה חייבת לזהות ולבטל
    $pdo->exec('DROP TABLE recordings');
    $pdo->exec(n2b_old_schema('recordings', 'INTEGER'));
    $pdo->exec("INSERT INTO recordings (id, word_id, state, name, at) VALUES (1, 1, 'site', 5, 'x'), (2, 2, 'tool', 6, 'x'), (3, NULL, 'tool', 7, 'x')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rec_versions (id INTEGER PRIMARY KEY, rec_id INTEGER NOT NULL, file_id TEXT, source TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '', duration REAL, how TEXT NOT NULL DEFAULT '', since TEXT, at TEXT NOT NULL, by_name TEXT NOT NULL DEFAULT '')");
    $pdo->exec("INSERT INTO rec_versions (rec_id, at) VALUES (1, 'x'), (2, 'x')");
    $pdo->exec('PRAGMA user_version = 19');
    $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE name = 'recordings'")->fetchColumn();
    $rows = $pdo->query('SELECT * FROM recordings ORDER BY id')->fetchAll();
    $err = '';
    try { migrate($pdo); } catch (RuntimeException $e) { $err = $e->getMessage(); }
    ok(str_contains($err, 'מיגרציה 20 בוטלה'), 'חריגה עם הסבר: ' . $err);
    eq(19, (int)$pdo->query('PRAGMA user_version')->fetchColumn(), 'הגרסה נשארה 19');
    eq($sql, $pdo->query("SELECT sql FROM sqlite_master WHERE name = 'recordings'")->fetchColumn(), 'הטבלה הישנה במקומה');
    eq($rows, $pdo->query('SELECT * FROM recordings ORDER BY id')->fetchAll(), 'אותן שורות');
    eq(2, (int)$pdo->query('SELECT COUNT(*) FROM rec_versions')->fetchColumn(), 'הגרסאות');
    ok(!$pdo->query("SELECT 1 FROM sqlite_master WHERE name = 'recordings_n2b'")->fetchColumn(), 'אין טבלת ביניים');
    ok(!$pdo->inTransaction(), 'אין עסקה פתוחה');
    // ובלי הפגם — אותו מסד עובר
    $pdo->exec('DROP TABLE recordings');
    $pdo->exec(n2b_old_schema('recordings'));
    $pdo->exec("INSERT INTO recordings (id, word_id, state, name, at, deleted_at, deleted_word) VALUES (1, 1, 'site', 'א', 'x', NULL, NULL), (2, NULL, 'tool', 'ב', 'x', 'y', 7)");
    migrate($pdo);
    eq(20, (int)$pdo->query('PRAGMA user_version')->fetchColumn(), 'עכשיו — 20');
    eq([['id' => 1, 'field' => 'audio', 'deleted_field' => null], ['id' => 2, 'field' => 'audio', 'deleted_field' => 'audio']],
        $pdo->query('SELECT id, field, deleted_field FROM recordings ORDER BY id')->fetchAll(), 'השדה הראשי, והסל זוכר את המקום');
});

test('N2b · מיגרציה 20: המפתח של השדה הראשי שונה ב-N2 (audio → sound) — הרשומות מקבלות את המפתח', function () {
    $pdo = tmp_pdo('n2b-key');
    migrate($pdo);
    $pdo->exec("INSERT INTO word_fields (key, label, type, col, sort_order) VALUES ('sound', 'הקלטה', 'audio', 'audio', 10)");
    $pdo->exec('DROP TABLE recordings');
    $pdo->exec(n2b_old_schema('recordings'));
    $pdo->exec("INSERT INTO recordings (id, word_id, state, name, at) VALUES (1, 1, 'site', 'א', 'x')");
    $pdo->exec('PRAGMA user_version = 19');
    migrate($pdo);
    eq('sound', $pdo->query('SELECT field FROM recordings WHERE id = 1')->fetchColumn());
});

// ── §2.2 הקריאה והכתיבה של מקום ─────────────────────────

test('N2b · rec_sync גוזר רשומה מכל שדה audio; כתיבה למקום מותאם — דרך N2 (בדיקת סוג, updated_by, rev); "חסרה" לכל מקום ריק', function () {
    $a = a3_file('mp3');
    $b = a3_file('ogg', 'a3-fr.ogg');
    $clean = n2b_fixture([9801 => [$a, $b], 9802 => [A3_U . '2024/01/x.mp3', ''], 9803 => ['', '']]);
    try {
        $r = n2b_rec(9801);
        eq(['words', 9801, N2B_FR, $b, 'tool', 'linked'], [$r['list'], (int)$r['word_id'], $r['field'], $r['file_id'], $r['state'], $r['how']], 'רשומה לשדה המותאם');
        eq($a, n2b_rec(9801, 'audio')['file_id'] ?? null, 'והראשית — בנפרד');
        $lib = library_list();
        $fr2 = n2b_row($lib, 9802);
        eq(['none', '9802:audio_fr', 'הקלטה בצרפתית'], [$fr2['state'], $fr2['id'], $fr2['field_label']], 'חסרה בצרפתית');
        eq('site', n2b_row($lib, 9802, 'audio')['state'], 'הראשית של 9802 — באתר');
        eq(9802, n2b_row($lib, 9802, 'audio')['id'], 'השורה של הראשי — המזהה של המילה, כמו לפני');
        eq('tool', n2b_row($lib, 9801)['state'], 'מותאם — בכלי');
        $fr = array_filter($lib['rows'], fn($r) => ($r['field'] ?? '') === N2B_FR);
        eq(['audio' => count(array_filter($fr, fn($r) => $r['state'] !== 'none')), 'none' => count(array_filter($fr, fn($r) => $r['state'] === 'none'))],
            $lib['counts']['fields'][N2B_FR], 'מונה לשדה');
        eq(1, $lib['counts']['fields'][N2B_FR]['audio'], 'הקלטה אחת בצרפתית');
        eq(['audio', N2B_FR], array_column($lib['fields'], 'key'), 'השדות, לפי הסדר');
        eq('בצרפתית', $lib['fields'][1]['short'], 'שם קצר');
        // כתיבה: הערך נבדק לפי השדה (קובץ שאינו שמע — נדחה; כתובת באתר — רק בראשי)
        $tmp = files_dir() . '/tmp/a3-png';
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $img = file_store($tmp, 'a3-x.png', A3_ED, 'image/png');
        throws(fn() => rec_slot_set('words', 9803, N2B_FR, $img, A3_ED), 'קובץ שאינו שמע');
        throws(fn() => rec_slot_set('words', 9803, N2B_FR, A3_U . 'x.mp3', A3_ED), 'כתובת באתר בשדה מותאם');
        throws(fn() => rec_slot_set('words', 9803, 'nope', $b, A3_ED), 'שדה שאינו קיים');
        $rev0 = words_rev();
        rec_slot_set('words', 9803, N2B_FR, $b, A3_ED);
        eq([$b, A3_ED['name']], [n2b_fr(9803), db()->query('SELECT updated_by FROM words WHERE id = 9803')->fetchColumn()], 'נכתב, עם updated_by');
        ok($rev0 !== words_rev(), 'ה-rev של הקובץ הווירטואלי השתנה');
        rec_slot_set('words', 9803, N2B_FR, '', A3_ED);
        ok(!array_key_exists(N2B_FR, json_decode((string)db()->query('SELECT extra FROM words WHERE id = 9803')->fetchColumn(), true)), 'ריק — המפתח נמחק');
        // החלפה מחוץ לספרייה (מהטבלה) — הקודם נשמר כגרסה; מקום שהתרוקן — "לא מחוברת"; קובץ שחזר — מתחבר עם שמו וגרסאותיו
        $c = a3_file('flac', 'a3-c.flac');
        word_save(A3_ED, ['id' => 9801, 'word' => 'מילה 9801', 'meaning' => 'פ', 'audio' => $a, 'extra' => [N2B_FR => $c]]);
        rec_sync();
        $r1 = n2b_rec(9801);
        eq([$r['id'], $c], [$r1['id'], $r1['file_id']], 'אותה רשומה, הקובץ החדש');
        eq($b, rec_versions(['rec_id' => $r['id']])['versions'][0]['file']['id'] ?? null, 'הקודם — גרסה');
        rec_rename(A3_ED, ['rec_id' => $r['id'], 'name' => 'צרפתית של 9801']);
        word_save(A3_ED, ['id' => 9801, 'word' => 'מילה 9801', 'meaning' => 'פ', 'audio' => $a, 'extra' => [N2B_FR => null]]);
        rec_sync();
        $g = rec_get((int)$r['id']);
        eq([null, N2B_FR], [$g['word_id'], $g['field']], 'התרוקן — לא מחוברת (לא נמחקה), עם השדה');
        word_save(A3_ED, ['id' => 9801, 'word' => 'מילה 9801', 'meaning' => 'פ', 'audio' => $a, 'extra' => [N2B_FR => $c]]);
        rec_sync();
        $g = rec_get((int)$r['id']);
        eq([9801, 'צרפתית של 9801', 1], [(int)$g['word_id'], $g['name'], count(rec_versions(['rec_id' => $r['id']])['versions'])], 'חזר — מתחבר עם השם והגרסאות');
        // מצב "באתר" והבאה — רק בשדה הראשי
        eq(0, (int)db()->query("SELECT COUNT(*) FROM recordings WHERE field != 'audio' AND state != 'tool'")->fetchColumn(), 'מותאם — תמיד בכלי');
    } finally { $clean(); }
});

test('N2b · הנתיב בעץ: הראשית media/audio/<id>.<ext>, מותאם media/audio/<id>-<key>.<ext>; החלפה מצביעה על החדש והנתיב לא זז', function () {
    $a = a3_file('mp3');
    $clean = n2b_fixture([9804 => [$a, '']]);
    try {
        pf_seed(A3_OWNER);
        $b = a3_file('ogg', 'a3-b.ogg');
        rec_replace(A3_ED, ['word_id' => 9804, 'field' => N2B_FR, 'file_id' => $b]);
        eq($b, pf_by_path('media/audio/9804-audio_fr.ogg')['file_id'] ?? null, 'נוצר בנתיב של השדה');
        ok(!pf_by_path('media/audio/9804.ogg'), 'לא בנתיב של הראשית');
        $c = a3_file('mp3', 'a3-c.mp3');
        rec_replace(A3_ED, ['word_id' => 9804, 'field' => N2B_FR, 'file_id' => $c]);
        eq($c, pf_by_path('media/audio/9804-audio_fr.ogg')['file_id'] ?? null, 'החלפה — אותו נתיב, הקובץ החדש');
        eq($c, n2b_fr(9804), 'המקום מצביע על החדש');
        eq(1, count(rec_versions(['rec_id' => n2b_rec(9804)['id']])['versions']), 'גרסה');
        // הקלטה ראשונה דרך "החלף" לראשית — לא נוגעת בשדה המותאם
        eq($a, rec_slot_get('words', 9804, 'audio'), 'הראשית לא השתנתה');
        // כתובת באתר אינה נכנסת לשדה מותאם, גם לא דרך "חבר ל…"
        $site = db()->query("SELECT id FROM recordings WHERE state = 'site' LIMIT 1")->fetchColumn();
        if ($site) eq(400, throws(fn() => rec_connect(A3_ED, ['rec_id' => (int)$site, 'word_id' => 9804, 'field' => N2B_FR, 'replace' => true])), 'באתר → שדה מותאם');
    } finally { $clean(); }
});

// ── §2.3 בספרייה — כל פעולות A3 על כל מקום ───────────────

test('N2b · חבר ל… עם שדה; תפוס — 409 ו"החלף"; העבר לשדה — אותה רשומה (שם, משך, גרסאות); נתק', function () {
    $a = a3_file('mp3');
    $b = a3_file('ogg', 'a3-b.ogg');
    $clean = n2b_fixture([9805 => [$a, ''], 9806 => ['', $b]]);
    try {
        $loose = rec_add(A3_ED, ['file_id' => a3_file('flac', 'a3-loose.flac')]);
        eq(null, $loose['word_id'], 'לא מחוברת');
        rec_connect(A3_ED, ['rec_id' => $loose['rec_id'], 'word_id' => 9805, 'field' => N2B_FR]);
        eq([9805, N2B_FR], [(int)rec_get($loose['rec_id'])['word_id'], rec_get($loose['rec_id'])['field']], 'חוברה לבצרפתית');
        eq(rec_get($loose['rec_id'])['file_id'], n2b_fr(9805), 'extra נכתב');
        eq($a, rec_slot_get('words', 9805, 'audio'), 'הראשית לא נגעה');
        // תפוס
        $other = n2b_rec(9806);
        $e = null;
        try { rec_connect(A3_ED, ['rec_id' => $loose['rec_id'], 'word_id' => 9806, 'field' => N2B_FR]); } catch (ApiError $x) { $e = $x; }
        eq(409, $e?->getCode(), 'תפוס — 409');
        eq([N2B_FR, (int)$other['id']], [$e->extra['conflict']['field'], $e->extra['conflict']['rec_id']], 'הפרטים — לפי מקום');
        rec_connect(A3_ED, ['rec_id' => $loose['rec_id'], 'word_id' => 9806, 'field' => N2B_FR, 'replace' => true]);
        eq(null, rec_get((int)$other['id'])['word_id'], 'הקודמת — לא מחוברת');
        eq('', n2b_fr(9805), 'המקום הקודם התרוקן');
        // העבר לשדה: מבצרפתית לראשית של אותה מילה (9806 — בלי ראשית)
        $rid = $loose['rec_id'];
        rec_replace(A3_ED, ['rec_id' => $rid, 'file_id' => a3_file('mp3', 'a3-v.mp3')]);
        db()->prepare('UPDATE recordings SET duration = 2.5, name = ? WHERE id = ?')->execute(['שם', $rid]);
        rec_move(A3_ED, ['rec_id' => $rid, 'field' => 'audio']);
        $g = rec_get($rid);
        eq([9806, 'audio', 'שם', 2.5], [(int)$g['word_id'], $g['field'], $g['name'], (float)$g['duration']], 'הועברה לראשית — שם ומשך נשמרו');
        eq(1, count(rec_versions(['rec_id' => $rid])['versions']), 'הגרסאות נשמרו');
        eq($g['file_id'], rec_slot_get('words', 9806, 'audio'), 'words.audio נכתב');
        eq('', n2b_fr(9806), 'המקום הקודם (בצרפתית) התרוקן');
        ok(str_contains((string)db()->query("SELECT detail FROM audit WHERE action = 'rec_move' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'words/9806/audio'), 'ביומן — המקום');
        // העבר למקום תפוס — 409
        rec_connect(A3_ED, ['rec_id' => (int)n2b_rec(9805, 'audio')['id'], 'word_id' => 9806, 'field' => N2B_FR]);
        eq(409, throws(fn() => rec_move(A3_ED, ['rec_id' => $rid, 'field' => N2B_FR])), 'העברה למקום תפוס');
        eq(404, throws(fn() => rec_move(A3_ED, ['rec_id' => $rid, 'field' => 'nope'])), 'שדה שאינו קיים');
        throws(fn() => rec_move(A3_ED, ['rec_id' => (int)$other['id'], 'field' => 'audio']), 'לא מחוברת — "חבר ל…"');
        // נתק
        rec_disconnect(A3_ED, ['ids' => [(int)n2b_rec(9806)['id']]]);
        eq('', n2b_fr(9806), 'נותקה — המקום ריק');
    } finally { $clean(); }
});

test('N2b · מחיקה לסל ושחזור — חוזר לאותו מקום; תפוס בינתיים — "לא מחוברת" עם ההסבר', function () {
    $b = a3_file('ogg', 'a3-b.ogg');
    $clean = n2b_fixture([9807 => ['', $b]]);
    try {
        $rid = (int)n2b_rec(9807)['id'];
        rec_delete(A3_ED, ['ids' => [$rid]]);
        eq('', n2b_fr(9807), 'המקום התרוקן');
        $t = array_values(array_filter(rec_trash(), fn($x) => $x['rec'] === $rid))[0];
        eq([9807, N2B_FR, 'הקלטה בצרפתית', false], [$t['word_id'], $t['field'], $t['field_label'], $t['primary']], 'בסל — המקום');
        $res = rec_restore(A3_ED, ['id' => $rid]);
        eq([9807, N2B_FR, $b], [$res['word_id'], $res['field'], n2b_fr(9807)], 'חזר לבצרפתית של 9807');
        eq('', rec_slot_get('words', 9807, 'audio'), 'לא לראשית');
        // תפוס בינתיים
        rec_delete(A3_ED, ['ids' => [$rid]]);
        rec_replace(A3_ED, ['word_id' => 9807, 'field' => N2B_FR, 'file_id' => a3_file('mp3', 'a3-new.mp3')]);
        $res = rec_restore(A3_ED, ['id' => $rid]);
        eq(null, $res['word_id'], 'תפוס — לא מחוברת');
        ok(str_contains($res['note'], 'הקלטה בצרפתית'), 'ההסבר: ' . $res['note']);
        // מחיקה סופית — רק לבעלים, וכמו A3
        rec_delete(A3_ED, ['ids' => [$rid]]);
        ok(rec_purge(A3_OWNER, ['id' => $rid, 'confirm' => true])['freed'] >= 0, 'מחיקה סופית');
    } finally { $clean(); }
});

test('N2b · מחיקת מילה — לכל המקומות שלה; שחזור המילה מחבר את כולם', function () {
    $a = a3_file('mp3');
    $b = a3_file('ogg', 'a3-b.ogg');
    $clean = n2b_fixture([9808 => [$a, $b]]);
    try {
        $ra = (int)n2b_rec(9808, 'audio')['id'];
        $rb = (int)n2b_rec(9808)['id'];
        word_delete(A3_ED, ['id' => 9808]);
        rec_sync();
        eq([null, null], [rec_get($ra)['word_id'], rec_get($rb)['word_id']], 'שתיהן — לא מחוברות');
        $tid = (int)db()->query('SELECT id FROM words_trash WHERE word_id = 9808 ORDER BY id DESC')->fetchColumn();
        trash_restore(A3_ED, ['kind' => 'word', 'id' => $tid]);
        rec_sync();
        eq([9808, 9808], [(int)rec_get($ra)['word_id'], (int)rec_get($rb)['word_id']], 'שתיהן חזרו');
        eq([$ra, $rb], [(int)n2b_rec(9808, 'audio')['id'], (int)n2b_rec(9808)['id']], 'כל אחת למקומה');
    } finally { $clean(); }
});

test('N2b · שדה: שינוי מפתח מעדכן את recordings.field (באותה עסקה) ואת הנתיב בעץ; מחיקה — "לא מחוברות" עם "משדה שנמחק"; שחזור — מחבר', function () {
    $b = a3_file('ogg', 'a3-b.ogg');
    $c = a3_file('mp3', 'a3-c.mp3');
    $clean = n2b_fixture([9809 => ['', $b], 9810 => ['', $c]]);
    try {
        pf_seed(A3_OWNER);
        rec_tree_point(9809, $b, A3_ED, N2B_FR);
        ok(pf_by_path('media/audio/9809-audio_fr.ogg'), 'בעץ');
        $rb = (int)n2b_rec(9809)['id'];
        field_key_apply(A3_OWNER, ['kind' => 'field', 'key' => N2B_FR, 'to' => 'audio_fra', 'revs' => []]);
        eq('audio_fra', rec_get($rb)['field'], 'recordings.field');
        rec_sync();
        eq([$rb, 9809], [(int)(rec_of_word(9809, 'audio_fra')['id'] ?? 0), (int)rec_get($rb)['word_id']], 'אותה רשומה, מחוברת');
        eq(0, (int)db()->query("SELECT COUNT(*) FROM recordings WHERE field = 'audio_fr'")->fetchColumn(), 'אין כפילות במפתח הישן');
        ok(pf_by_path('media/audio/9809-audio_fra.ogg') && !pf_by_path('media/audio/9809-audio_fr.ogg'), 'הנתיב בעץ — המפתח החדש');
        // מחיקת השדה — הספירה באישור, וההקלטות "לא מחוברות" עם התווית
        eq(2, field_delete(A3_OWNER, ['key' => 'audio_fra', 'dry' => true])['recordings'], 'האישור סופר את ההקלטות');
        $res = field_delete(A3_OWNER, ['key' => 'audio_fra']);
        eq(2, $res['recordings'], 'נספרו');
        $lib = library_list();
        $l = array_values(array_filter($lib['loose'], fn($r) => $r['rec'] === $rb))[0] ?? null;
        eq('הקלטה בצרפתית', $l['dropped'] ?? null, '"משדה שנמחק: <שם>"');
        ok(!n2b_row($lib, 9809, 'audio_fra'), 'אין עוד שורות לשדה');
        // שחזור השדה — מחבר אותן
        $tid = (int)db()->query('SELECT id FROM field_trash ORDER BY id DESC')->fetchColumn();
        trash_restore(A3_OWNER, ['kind' => 'field', 'id' => $tid]);
        rec_sync();
        eq([9809, 'audio_fra'], [(int)rec_get($rb)['word_id'], rec_get($rb)['field']], 'חזרה למקום');
        // השדה הראשי: שינוי מפתח (audio → sound) — הרשומות עוברות למפתח, והראשית ממשיכה לעבוד
        $a = a3_file('mp3', 'a3-a.mp3');
        rec_replace(A3_ED, ['word_id' => 9810, 'file_id' => $a]);
        $ra = (int)rec_of_word(9810)['id'];
        field_key_apply(A3_OWNER, ['kind' => 'field', 'key' => 'audio', 'to' => 'sound', 'revs' => []]);
        eq(['sound', 'sound'], [rec_get($ra)['field'], rec_primary()], 'הראשי — sound');
        rec_sync();
        eq($ra, (int)(rec_of_word(9810)['id'] ?? 0), 'אותה רשומה לראשית');
        eq($a, n2b_row(library_list(), 9810, 'sound')['file']['id'] ?? null, 'בספרייה');
        field_key_apply(A3_OWNER, ['kind' => 'field', 'key' => 'sound', 'to' => 'audio', 'revs' => []]);
    } finally { $clean(); }
});

test('N2b · הוספה מרובה: התאמה לפי שם הקובץ — השדה שנבחר, וסיומת מפתח / שם השדה גוברת; התנגשות לפי מקום', function () {
    $b = a3_file('ogg', 'a3-b.ogg');
    $clean = n2b_fixture([9811 => ['', $b], 9812 => ['', '']]);
    try {
        db()->prepare("UPDATE words SET word = 'בסטילה' WHERE id = 9812")->execute();
        $m = rec_match(['names' => ['9812.mp3', '9812-audio_fr.mp3', 'בסטילה - בצרפתית.mp3', 'בסטילה-הקלטה בצרפתית.ogg', '9811-audio_fr.mp3', '9811.mp3', 'בסטילה.mp3']])['items'];
        eq(['audio', 9812, false], [$m[0]['field'], $m[0]['word_id'], $m[0]['by_field']], 'בלי סיומת — הראשי (ברירת המחדל)');
        eq([N2B_FR, 9812, true], [$m[1]['field'], $m[1]['word_id'], $m[1]['by_field']], 'מפתח אחרי מקף');
        eq([N2B_FR, 9812, 'word'], [$m[2]['field'], $m[2]['word_id'], $m[2]['by']], 'שם קצר אחרי מקף');
        ok(isset($m[3]['dup']) && $m[3]['field'] === N2B_FR, 'השם המלא — אותו מקום, מסומן ככפול');
        ok($m[4]['conflict'] && $m[4]['conflict']['rec_id'] === (int)n2b_rec(9811)['id'], 'התנגשות — לפי מקום');
        eq(null, $m[5]['conflict'], 'הראשי של 9811 פנוי');
        $m2 = rec_match(['names' => ['9812.mp3', 'בסטילה.mp3'], 'field' => N2B_FR])['items'];
        eq([N2B_FR, N2B_FR], [$m2[0]['field'], $m2[1]['field']], 'השדה שנבחר במסך ההעלאה');
        throws(fn() => rec_match(['names' => ['x.mp3'], 'field' => 'nope']), 'שדה לא קיים');
        // rec_add למקום
        $f = a3_file('mp3', 'a3-add.mp3');
        $res = rec_add(A3_ED, ['file_id' => $f, 'word_id' => 9812, 'field' => N2B_FR]);
        eq([9812, N2B_FR, $f], [$res['word_id'], $res['field'], n2b_fr(9812)], 'נוספה ל-בצרפתית');
        eq('', rec_slot_get('words', 9812, 'audio'), 'הראשית ריקה');
        $f2 = a3_file('mp3', 'a3-add2.mp3');
        $res = rec_add(A3_ED, ['file_id' => $f2, 'word_id' => 9812, 'field' => N2B_FR]);
        eq(null, $res['word_id'], 'תפוס, בלי replace — לא מחוברת');
        $f3 = a3_file('mp3', 'a3-add3.mp3');
        $res = rec_add(A3_ED, ['file_id' => $f3, 'word_id' => 9812, 'field' => N2B_FR, 'on_conflict' => 'replace']);
        ok($res['replaced'] && n2b_fr(9812) === $f3, 'replace — הקודמת גרסה');
    } finally { $clean(); }
});

test('N2b · ZIP: <id>-<key>.<ext> לשדה מותאם, עמודות list/field/field_label; הורדה ⇒ מחיקה ⇒ העלאה ⇒ הכול חוזר למקום, sha256 זהה', function () {
    if (!class_exists('ZipArchive')) { fwrite(STDERR, "   (בלי ZipArchive — דילוג)\n"); return; }
    $a = a3_file('mp3');
    $b = a3_file('ogg', 'a3-b.ogg');
    $clean = n2b_fixture([9813 => [$a, $b]], false);
    try {
        $lib = library_list();
        ok(n2b_row($lib, 9813)['internal'], 'שדה שאינו מתפרסם — "פנימי"');
        $ids = [(int)n2b_rec(9813, 'audio')['id'], (int)n2b_rec(9813)['id']];
        $z = rec_zip_start(A3_ED, ['ids' => $ids]);
        while (!$z['done']) $z = rec_zip_step(A3_ED, ['token' => $z['token']]);
        $zip = new ZipArchive();
        $zip->open(rec_zip_dir() . "/zip-{$z['token']}.zip");
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
        eq(['9813.mp3', '9813-audio_fr.ogg', 'recordings.csv'], $names, 'השמות בארכיון');
        $csv = (string)$zip->getFromName('recordings.csv');
        ok(str_contains($csv, '"list","field","field_label"') && str_contains($csv, '"words","audio_fr","הקלטה בצרפתית"'), 'עמודות המקום');
        $bytes = [$zip->getFromName('9813.mp3'), $zip->getFromName('9813-audio_fr.ogg')];
        $zip->close();
        eq([hash_file('sha256', file_path(file_row($a))), hash_file('sha256', file_path(file_row($b)))], array_map(fn($x) => hash('sha256', $x), $bytes), 'בלי המרה — sha256 זהה');
        // מחיקה ⇒ העלאה של אותו ZIP ⇒ חזרה למקום
        rec_delete(A3_ED, ['ids' => $ids]);
        eq(['', ''], [rec_slot_get('words', 9813, 'audio'), n2b_fr(9813)], 'נמחקו');
        $m = rec_match(['names' => ['9813.mp3', '9813-audio_fr.ogg']])['items'];
        foreach ([[0, 'mp3', $bytes[0]], [1, 'ogg', $bytes[1]]] as [$i, $ext, $raw]) {
            $tmp = files_dir() . '/tmp/a3-up-' . $i;
            file_put_contents($tmp, $raw);
            $fid = file_store($tmp, "a3-up-$i.$ext", A3_ED, 'audio/');
            rec_add(A3_ED, ['file_id' => $fid, 'word_id' => $m[$i]['word_id'], 'field' => $m[$i]['field']]);
        }
        $pa = rec_slot_get('words', 9813, 'audio');
        $pb = n2b_fr(9813);
        eq([hash('sha256', $bytes[0]), hash('sha256', $bytes[1])], [file_row($pa)['sha256'], file_row($pb)['sha256']], 'חזרו למקום — sha256 זהה');
        // הורדה של אחת — הקובץ כמו שהוא (api.php?action=file) — אותו קובץ בדיסק
        eq(hash('sha256', $bytes[1]), hash_file('sha256', file_path(file_row($pb))), 'הקובץ בדיסק זהה');
    } finally { $clean(); }
});

test('N2b · בלי שדה שמע נוסף — הספרייה זהה לזו של A3 (אין field/wid בשורות); הרשאות — כמו A3; אין פנייה לאתר', function () {
    $a = a3_file('mp3');
    $clean = a3_fixture([9301 => $a]);
    try {
        db()->exec('DELETE FROM word_fields');
        $lib = library_list();
        $r = array_values(array_filter($lib['rows'], fn($x) => $x['id'] === 9301))[0];
        ok(!isset($r['field']) && !isset($r['wid']), 'שורה בלי שדות N2b');
        eq(1, count($lib['fields']), 'שדה שמע אחד');
        eq(count($lib['rows']), count(words_rows()), 'שורה לכל מילה — כמו לפני');
    } finally { $clean(); }
    $src = (string)file_get_contents(KOL_ROOT . '/lib/rec_actions.php');
    ok(!preg_match('/\bcurl_|rec_http\s*\(/', $src), 'אין curl / rec_http ב-rec_actions.php');
});
