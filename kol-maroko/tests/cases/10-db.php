<?php
// מיגרציות, שמירה וקריאה של מילה, נקודת שחזור, העלאת קובץ.

declare(strict_types=1);

function tmp_pdo(string $name): PDO
{
    $pdo = new PDO('sqlite:' . dirname(KOL_DATA_DIR) . "/$name.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $pdo;
}

test('מיגרציות: מסד ריק מגיע לגרסת הסכמה שהקוד מצפה לה', function () {
    $pdo = tmp_pdo('empty');
    migrate($pdo);
    eq(KOL_SCHEMA_VERSION, (int)$pdo->query('PRAGMA user_version')->fetchColumn());
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['members', 'audit', 'words', 'files', 'versions', 'snapshots', 'dev_users', 'dev_log', 'dev_runs'] as $t) ok(in_array($t, $tables, true), "חסרה הטבלה $t");
    migrate($pdo);                                          // ריצה שנייה — לא עושה כלום ולא נופלת
    eq(KOL_SCHEMA_VERSION, (int)$pdo->query('PRAGMA user_version')->fetchColumn());
});

test('מיגרציות: מסד בגרסה קודמת (11) עולה לגרסה הנוכחית, והנתונים שבו נשארים', function () {
    $pdo = tmp_pdo('old');
    migrate($pdo);
    // חזרה למצב שלפני פרוסה F1: בלי הטבלאות של שלב 12
    $pdo->exec('DROP TABLE dev_users; DROP TABLE dev_log; DROP TABLE dev_runs; DROP TABLE pf_files; DROP TABLE pf_history; DROP TABLE pkg_builds; DROP TABLE pkg_site_blobs; DROP TABLE notices; DROP INDEX idx_words_sort; ALTER TABLE words DROP COLUMN sort_order; PRAGMA user_version = 11');
    $pdo->exec("INSERT INTO words (word, meaning, updated_at) VALUES ('סבאח', 'בוקר', '2026-01-01T00:00:00Z')");
    $pdo->exec("INSERT INTO audit (at, sub, name, action, detail) VALUES ('2026-01-01T00:00:00Z', 'x', 'מישהו', 'publish', 'גרסה 3')");
    migrate($pdo);
    eq(KOL_SCHEMA_VERSION, (int)$pdo->query('PRAGMA user_version')->fetchColumn());
    eq('בוקר', $pdo->query("SELECT meaning FROM words WHERE word = 'סבאח'")->fetchColumn(), 'המילה נשארה');
    eq(1, (int)$pdo->query('SELECT COUNT(*) FROM audit')->fetchColumn(), 'יומן הפעולות נשאר');
    eq(0, (int)$pdo->query('SELECT COUNT(*) FROM dev_log')->fetchColumn(), 'הטבלה החדשה נוצרה');
});

test('מילה: שמירה, קריאה, עריכה ומחיקה לסל', function () {
    $u = ['sub' => 'u-editor', 'name' => 'עורך בדיקה'];
    $r = word_save($u, ['word' => 'לבאס', 'meaning' => 'בסדר', 'topics' => ['ברכות']]);
    $id = $r['id'];
    $w = array_values(array_filter(words_list()['words'], fn($x) => $x['id'] === $id))[0] ?? null;
    ok($w, 'המילה לא נמצאה ברשימה');
    eq('לבאס', $w['word']);
    eq('בסדר', $w['meaning']);
    word_save($u, ['id' => $id, 'word' => 'לבאס', 'meaning' => 'הכול טוב', 'topics' => ['ברכות']]);
    eq('הכול טוב', db()->query("SELECT meaning FROM words WHERE id = $id")->fetchColumn());
    word_delete($u, ['id' => $id]);
    eq(0, (int)db()->query("SELECT COUNT(*) FROM words WHERE id = $id")->fetchColumn(), 'נמחקה מהרשימה');
    eq(1, (int)db()->query("SELECT COUNT(*) FROM words_trash WHERE word_id = $id")->fetchColumn(), 'נמצאת בסל');
    eq(400, throws(fn() => word_save($u, ['word' => '', 'meaning' => 'x']), 'מילה ריקה') >= 400 ? 400 : 0);
});

test('נקודת שחזור: יצירה, שינוי, ושחזור מחזיר את המילים כפי שהיו', function () {
    $u = ['sub' => 'u-editor', 'name' => 'עורך בדיקה', 'role' => 'editor'];
    $id = word_save($u, ['word' => 'מזיאן', 'meaning' => 'יפה'])['id'];
    $snap = snapshot_create($u, 'בדיקה', 'manual');
    word_save($u, ['id' => $id, 'word' => 'מזיאן', 'meaning' => 'שונה אחרי הנקודה']);
    word_save($u, ['word' => 'נוספה-אחרי', 'meaning' => 'x']);
    $r = snapshot_restore($u, ['id' => $snap]);
    eq($snap, $r['restored']);
    ok($r['pre'] > $snap, 'לפני השחזור נוצרה נקודה של המצב הנוכחי');
    eq('יפה', db()->query("SELECT meaning FROM words WHERE word = 'מזיאן'")->fetchColumn(), 'המשמעות חזרה');
    eq(0, (int)db()->query("SELECT COUNT(*) FROM words WHERE word = 'נוספה-אחרי'")->fetchColumn(), 'מה שנוסף אחרי הנקודה איננו');
    // וגם השחזור עצמו ניתן לביטול
    snapshot_restore($u, ['id' => $r['pre']]);
    eq(1, (int)db()->query("SELECT COUNT(*) FROM words WHERE word = 'נוספה-אחרי'")->fetchColumn(), 'ביטול השחזור החזיר את המילה');
});

test('קובץ: הסוג מזוהה לפי התוכן ולא לפי השם', function () {
    $u = ['sub' => 'u-editor', 'name' => 'עורך בדיקה'];
    $png = KOL_DATA_DIR . '/t.bin';
    $im = imagecreatetruecolor(8, 6);
    imagepng($im, $png);
    $id = file_store($png, 'בלי-סיומת-נכונה.txt', $u);
    $f = file_row($id);
    eq('image', $f['kind'], 'PNG בשם של טקסט זוהה כתמונה');
    eq(8, (int)$f['w']);
    ok(is_file(file_path($f)), 'הקובץ נשמר');
    ok(str_starts_with(realpath(file_path($f)), realpath(KOL_DATA_DIR)), 'נשמר בתיקייה הזמנית');
    $php = KOL_DATA_DIR . '/evil.bin';
    file_put_contents($php, "<?php echo 'x'; ?>");
    throws(fn() => file_store($php, 'תמונה.png', $u), 'קוד PHP בשם של תמונה');
    ok(!is_file($php), 'הקובץ שנדחה נמחק');
    $svg = KOL_DATA_DIR . '/x.bin';
    file_put_contents($svg, '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    throws(fn() => file_store($svg, 'x.svg', $u), 'SVG');
});
