<?php
/**
 * פרוסה A3 — הפעולות על הקלטה (lib/rec_actions.php). כל בדיקה מול קריטריון ב-docs/spec-v2/slices/A3-audio-actions.md §2.
 * המילים 9300–9399 והקבצים "a3-…" — של הבדיקה, ונמחקים בסוף כל בדיקה. בלי רשת: אין לאתר שום תפקיד ב-A3.
 */

declare(strict_types=1);

require_once KOL_ROOT . '/lib/library.php';

const A3_OWNER = ['sub' => 'u-owner', 'name' => 'בעלים בדיקה', 'role' => 'owner'];
const A3_ED = ['sub' => 'u-ed', 'name' => 'עורכת בדיקה', 'role' => 'editor'];
const A3_U = 'https://maghreb.co.il/wp-content/uploads/';

/** דוגמה קטנה לכל סוג — הבייטים הראשונים מספיקים לזיהוי לפי תוכן. */
function a3_bytes(string $kind, string $salt = ''): string
{
    return match ($kind) {
        'mp3' => "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xff\xfb\x90\x64" . str_repeat("\x00", 413), 5) . $salt,
        'flac' => "fLaC\x00\x00\x00\x22" . str_repeat("\x00", 134) . $salt,
        'wav' => "RIFF" . pack('V', 136) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', 100) . str_repeat("\x00", 100) . $salt,
        'ogg' => "OggS\x00\x02" . str_repeat("\x00", 20) . "\x01\x13OpusHead\x01\x01" . str_repeat("\x00", 200) . $salt,
        'aac' => str_repeat("\xff\xf1\x50\x80\x02\x1f\xfc" . str_repeat("\x00", 9), 20) . $salt,
        'm4a' => pack('N', 24) . "ftypM4A \x00\x00\x00\x00M4A mp42isom" . str_repeat("\x00", 100) . $salt,
        'html' => '<!doctype html><html><body>not audio</body></html>',
    };
}

/** קובץ בספרייה, כמו אחרי העלאה. */
function a3_file(string $kind = 'mp3', string $name = '', string $salt = '', string $declared = 'audio/'): string
{
    $tmp = files_dir() . '/tmp/a3-' . bin2hex(random_bytes(4));
    @mkdir(dirname($tmp), 0775, true);
    file_put_contents($tmp, a3_bytes($kind, $salt === '' ? bin2hex(random_bytes(6)) : $salt));
    return file_store($tmp, $name !== '' ? $name : "a3-$kind.$kind", A3_ED, $declared);
}

/** מילים 9300+ [id => audio]. מחזיר ניקוי. */
function a3_fixture(array $words): callable
{
    $pdo = db();
    $ins = $pdo->prepare('INSERT INTO words (id, wp_id, word, meaning, topics, audio, external_id, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($words as $id => $audio) $ins->execute([$id, $id + 60000, "מילה $id", '<p>פירוש</p>', '[]', $audio, '', now_iso()]);
    rec_sync();
    return function () use ($pdo) {
        $pdo->exec('DELETE FROM words WHERE id >= 9300 AND id < 9400');
        rec_sync();
        $fids = $pdo->query("SELECT id FROM files WHERE name LIKE 'a3-%' OR name LIKE 'מילה 93%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($pdo->query('SELECT id FROM recordings WHERE word_id IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $rid) {
            $r = rec_get((int)$rid);
            if ($r['file_id'] === null || in_array($r['file_id'], $fids, true) || str_starts_with($r['name'], 'a3')) {
                $pdo->prepare('DELETE FROM rec_versions WHERE rec_id = ?')->execute([$rid]);
                $pdo->prepare('DELETE FROM recordings WHERE id = ?')->execute([$rid]);
            }
        }
        foreach ($fids as $fid) {
            $pdo->prepare('DELETE FROM rec_versions WHERE file_id = ?')->execute([$fid]);
            $pdo->prepare('DELETE FROM pf_files WHERE file_id = ?')->execute([$fid]);
            $f = $pdo->query("SELECT * FROM files WHERE id = '$fid'")->fetch();
            if ($f) @unlink(file_path($f));
            $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$fid]);
        }
        $pdo->exec("DELETE FROM settings WHERE k = 'rec_file_max'");
        rec_sync();
    };
}

function a3_rec(int $wid): array
{
    return rec_of_word($wid) ?? [];
}

function a3_audio(int $wid): string
{
    return (string)db()->query("SELECT audio FROM words WHERE id = $wid")->fetchColumn();
}

test('A3 · החלפה שומרת את הקודמת כגרסה; "חזור לגרסה הזו"; המזהה והרשומה אינם משתנים', function () {
    $a = a3_file('mp3');
    $clean = a3_fixture([9301 => $a]);
    try {
        $r0 = a3_rec(9301);
        $b = a3_file('m4a', 'a3-new.m4a');
        rec_replace(A3_ED, ['word_id' => 9301, 'file_id' => $b]);
        $r1 = a3_rec(9301);
        eq([$r0['id'], $b, 'replaced'], [$r1['id'], $r1['file_id'], $r1['how']], 'אותה רשומה, קובץ חדש');
        eq($b, a3_audio(9301), 'המילה מצביעה על החדש');
        $v = rec_versions(['rec_id' => $r1['id']])['versions'];
        eq(1, count($v), 'גרסה אחת');
        eq($a, $v[0]['file']['id'], 'הגרסה — הקובץ הקודם');
        ok(str_contains($v[0]['file']['url'], 'action=media'), 'הגרסה מתנגנת (כתובת חתומה)');
        // הקובץ של הגרסה אינו "לא בשימוש" — אי אפשר למחוק אותו מ-🗂️ קבצים בטעות
        throws(fn() => file_delete(A3_ED, ['id' => $a]), 'גרסה נמחקה מקבצים');
        rec_revert(A3_ED, ['rec_id' => $r1['id'], 'version_id' => $v[0]['id']]);
        eq([$a, $r0['id']], [a3_audio(9301), a3_rec(9301)['id']], 'חזרה לגרסה');
        $v2 = rec_versions(['rec_id' => $r1['id']])['versions'];
        eq([1, $b], [count($v2), $v2[0]['file']['id']], 'החדש נשמר עכשיו כגרסה');
        // גם החלפה מ-📚 מילים (word_save, מחוץ לספרייה) שומרת את הקודמת — rec_sync
        $c = a3_file('ogg', 'a3-c.ogg');
        db()->prepare('UPDATE words SET audio = ? WHERE id = 9301')->execute([$c]);
        rec_sync();
        eq(2, count(rec_versions(['rec_id' => $r1['id']])['versions']), 'החלפה מ-📚 מילים');
        throws(fn() => rec_revert(A3_ED, ['rec_id' => $r1['id'], 'version_id' => 999999]), 'גרסה שאינה קיימת');
    } finally { $clean(); }
});

test('A3 · הקלטה שבאתר: מוחלפת (הכתובת נשמרת כגרסה — חזרה מחזירה את הכתובת); שאר הפעולות מושבתות עם הסבר', function () {
    $clean = a3_fixture([9302 => A3_U . 'x/2.mp3']);
    try {
        $r = a3_rec(9302);
        foreach ([fn() => rec_rename(A3_ED, ['rec_id' => $r['id'], 'name' => 'x']), fn() => rec_delete(A3_ED, ['ids' => [$r['id']]]),
                     fn() => rec_disconnect(A3_ED, ['ids' => [$r['id']]])] as $fn) {
            try { $fn(); throw new TestFail('פעולה על הקלטה שבאתר עברה'); }
            catch (ApiError $e) { ok(str_contains($e->getMessage(), 'אחרי ההבאה'), 'ההסבר: ' . $e->getMessage()); }
        }
        $b = a3_file('mp3');
        rec_replace(A3_ED, ['rec_id' => $r['id'], 'file_id' => $b]);
        eq([$b, 'tool', A3_U . 'x/2.mp3'], [a3_audio(9302), a3_rec(9302)['state'], a3_rec(9302)['source']], 'הוחלפה; המקור נשמר');
        $v = rec_versions(['rec_id' => $r['id']])['versions'];
        eq([true, A3_U . 'x/2.mp3'], [$v[0]['site'], $v[0]['source']], 'הגרסה — הכתובת באתר');
        rec_revert(A3_ED, ['rec_id' => $r['id'], 'version_id' => $v[0]['id']]);
        eq([A3_U . 'x/2.mp3', 'site'], [a3_audio(9302), a3_rec(9302)['state']], 'חזרה לכתובת שבאתר');
    } finally { $clean(); }
});

test('A3 · שינוי שם: השם לתצוגה בלבד; קלט עוין מנוקה; הקובץ והמילה אינם משתנים', function () {
    $a = a3_file('mp3');
    $clean = a3_fixture([9303 => $a]);
    try {
        $r = a3_rec(9303);
        rec_rename(A3_ED, ['rec_id' => $r['id'], 'name' => "  שלום\u{202E}evil\x00\n<b>x</b>  " . str_repeat('א', 400)]);
        $n = a3_rec(9303)['name'];
        ok(!preg_match('/[\x00-\x1f\x{202E}]/u', $n), 'תווי בקרה / כיווניות נסתרת');
        eq(REC_NAME_MAX, mb_strlen($n), 'אורך');
        ok(str_starts_with($n, 'שלוםevil<b>x</b> א'), 'הטקסט נשמר כטקסט: ' . mb_substr($n, 0, 30));
        eq([$a, $r['id']], [a3_audio(9303), a3_rec(9303)['id']], 'הקובץ והרשומה');
        throws(fn() => rec_rename(A3_ED, ['rec_id' => $r['id'], 'name' => "\u{200F}  "]), 'שם ריק');
        throws(fn() => rec_rename(A3_ED, ['rec_id' => $r['id'], 'name' => ['x']]), 'שם שאינו טקסט');
    } finally { $clean(); }
});

test('A3 · ניתוק וחיבור: המילה נשארת "בלי הקלטה", ההקלטה "לא מחוברת"; חיבור למילה עם הקלטה — 409, ובאישור הקודמת לא נמחקת', function () {
    $a = a3_file('mp3');
    $b = a3_file('wav', 'a3-b.wav');
    $clean = a3_fixture([9304 => $a, 9305 => $b, 9306 => '']);
    try {
        $ra = a3_rec(9304);
        rec_disconnect(A3_ED, ['ids' => [$ra['id']]]);
        eq(['', []], [a3_audio(9304), a3_rec(9304)], 'המילה בלי הקלטה');
        $lib = library_list();
        $loose = array_values(array_filter($lib['loose'], fn($x) => $x['rec'] === (int)$ra['id']));
        eq(1, count($loose), 'בספרייה תחת "לא מחוברות"');
        eq([-(int)$ra['id'], 'tool', true], [$loose[0]['id'], $loose[0]['state'], $loose[0]['loose']], 'שורת הקלטה לא מחוברת');
        // חיבור למילה בלי הקלטה
        rec_connect(A3_ED, ['rec_id' => $ra['id'], 'word_id' => 9306]);
        eq([$a, (int)$ra['id']], [a3_audio(9306), (int)a3_rec(9306)['id']], 'חוברה ל-9306');
        // חיבור למילה שיש לה הקלטה — 409 עם הפרטים, בלי שינוי
        try { rec_connect(A3_ED, ['rec_id' => $ra['id'], 'word_id' => 9305]); throw new TestFail('חיבור דרס הקלטה'); }
        catch (ApiError $e) { eq([409, 9305], [$e->getCode(), $e->extra['conflict']['word_id'] ?? null], 'התנגשות'); }
        eq($b, a3_audio(9305), 'לא השתנה');
        $rb = a3_rec(9305);
        rec_connect(A3_ED, ['rec_id' => $ra['id'], 'word_id' => 9305, 'replace' => true]);
        eq([$a, '', null], [a3_audio(9305), a3_audio(9306), rec_get((int)$rb['id'])['word_id']], 'עברה; הקודמת לא מחוברת');
        ok(rec_get((int)$rb['id'])['deleted_at'] === null, 'הקודמת לא בסל');
        // מילה שנמחקה (📚 מילים) — ההקלטה שלה נשארת "לא מחוברת"; מילה ששוחזרה — מתחברת אליה שוב (אותה רשומה)
        db()->exec('DELETE FROM words WHERE id = 9305');
        rec_sync();
        eq(null, rec_get((int)$ra['id'])['word_id'], 'המילה נמחקה — ההקלטה נשארת');
        db()->prepare("INSERT INTO words (id, wp_id, word, meaning, topics, audio, external_id, updated_at) VALUES (9305, 69305, 'מילה 9305', '', '[]', ?, '', ?)")->execute([$a, now_iso()]);
        rec_sync();
        eq(9305, (int)rec_get((int)$ra['id'])['word_id'], 'חזרה לאותה רשומה');
        throws(fn() => rec_connect(A3_ED, ['rec_id' => $ra['id'], 'word_id' => 987654]), 'מילה שאינה קיימת');
    } finally { $clean(); }
});

test('A3 · מחיקה לסל; שחזור מחזיר גם את החיבור; מחיקה סופית — רק מהסל, באישור, והקובץ יורד מהדיסק', function () {
    $a = a3_file('mp3');
    $b = a3_file('flac', 'a3-b.flac');
    $clean = a3_fixture([9307 => $a, 9308 => $b]);
    try {
        $ra = a3_rec(9307);
        rec_delete(A3_ED, ['ids' => [$ra['id']]]);
        eq('', a3_audio(9307), 'המילה בלי הקלטה');
        $t = array_values(array_filter(rec_trash(), fn($x) => $x['rec'] === (int)$ra['id']));
        eq([1, 9307, 'מילה 9307'], [count($t), $t[0]['word_id'], $t[0]['word']], 'בסל, עם המילה');
        ok(!array_filter(library_list()['loose'], fn($x) => $x['rec'] === (int)$ra['id']), 'לא ב"לא מחוברות"');
        ok(is_file(file_path(file_row($a))), 'הקובץ בשרת');
        rec_restore(A3_ED, ['id' => $ra['id']]);
        eq([$a, (int)$ra['id']], [a3_audio(9307), (int)a3_rec(9307)['id']], 'השחזור מחזיר את החיבור');
        // בינתיים למילה יש הקלטה אחרת — חוזרת כ"לא מחוברת", עם הסבר; שום דבר לא נדרס
        rec_delete(A3_ED, ['ids' => [$ra['id']]]);
        $c = a3_file('mp3');
        rec_replace(A3_ED, ['word_id' => 9307, 'file_id' => $c]);
        $res = rec_restore(A3_ED, ['id' => $ra['id']]);
        ok(str_contains($res['note'], 'הקלטה אחרת'), 'הסבר');
        eq([$c, null], [a3_audio(9307), rec_get((int)$ra['id'])['word_id']], 'לא נדרס');
        // מחיקה סופית
        $rb = a3_rec(9308);
        throws(fn() => rec_purge(A3_OWNER, ['id' => $rb['id'], 'confirm' => true]), 'מחיקה סופית שלא מהסל');
        rec_delete(A3_ED, ['ids' => [$rb['id']]]);
        throws(fn() => rec_purge(A3_OWNER, ['id' => $rb['id']]), 'בלי אישור');
        throws(fn() => rec_purge(A3_OWNER, ['id' => $rb['id'], 'confirm' => 'true']), 'אישור שאינו true');
        $path = file_path(file_row($b));
        $res = rec_purge(A3_OWNER, ['id' => $rb['id'], 'confirm' => true]);
        ok($res['freed'] > 0 && !is_file($path), 'הקובץ נמחק מהדיסק');
        throws(fn() => rec_get((int)$rb['id']), 'הרשומה נמחקה');
        // מחיקה מרובה — נקודת שחזור לפני
        $n0 = (int)db()->query('SELECT COUNT(*) FROM snapshots')->fetchColumn();
        $d = a3_file('mp3'); $e = a3_file('mp3');
        db()->prepare("UPDATE words SET audio = ? WHERE id = 9308")->execute([$d]);
        rec_sync();
        $loose = rec_add(A3_ED, ['file_id' => $e])['rec_id'];
        eq(2, rec_delete(A3_ED, ['ids' => [a3_rec(9308)['id'], $loose, 'x', -3]])['done'], 'שתיים לסל (מזהים עוינים — מדולגים)');
        ok((int)db()->query('SELECT COUNT(*) FROM snapshots')->fetchColumn() >= $n0, 'נקודת שחזור');
        throws(fn() => rec_delete(A3_ED, ['ids' => 'all']), 'ids שאינו רשימה');
        throws(fn() => rec_delete(A3_ED, ['ids' => range(1, REC_IDS_MAX + 1)]), 'יותר מדי');
    } finally { $clean(); }
});

test('A3 · הוספה: התאמה לפי שם הקובץ (מזהה / המילה) לפני העלאה; מתנגשים; שם עוין; שום חיבור בלי rec_add', function () {
    $a = a3_file('mp3');
    $clean = a3_fixture([9310 => '', 9311 => $a, 9312 => '', 9313 => '']);
    try {
        db()->exec("UPDATE words SET word = 'שָׁלוֹם' WHERE id = 9312");
        db()->exec("UPDATE words SET word = 'כפול' WHERE id IN (9313)");
        db()->prepare("INSERT INTO words (id, wp_id, word, meaning, topics, audio, external_id, updated_at) VALUES (9314, 69314, 'כפול', '', '[]', '', '', ?)")->execute([now_iso()]);
        $before = db()->query('SELECT id, audio FROM words WHERE id >= 9300 AND id < 9400 ORDER BY id')->fetchAll();
        $m = rec_match(['names' => ['9310.mp3', '9311.m4a', 'שלום.wav', 'כפול.mp3', '99999999.mp3', '../../9310.ogg', 'C:\\x\\9313.flac', 'אחר.mp3', 9, ['x']]])['items'];
        eq([9310, 'id', null], [$m[0]['word_id'], $m[0]['by'], $m[0]['conflict']], 'לפי מזהה');
        eq([9311, (int)a3_rec(9311)['id']], [$m[1]['word_id'], $m[1]['conflict']['rec_id']], 'מתנגש עם הקלטה קיימת');
        eq([9312, 'word'], [$m[2]['word_id'], $m[2]['by']], 'לפי המילה (בלי ניקוד)');
        ok($m[3]['word_id'] === null && str_contains($m[3]['note'], 'בחרו ידנית'), 'שתי מילים באותו שם — לא מנחשים');
        ok($m[4]['word_id'] === null && str_contains($m[4]['note'], 'אין מילה'), 'מזהה שאינו קיים');
        eq(['9310.ogg', 9310], [$m[5]['name'], $m[5]['word_id']], 'נתיב בשם — רק השם');
        ok(str_contains($m[5]['note'], 'עוד קובץ'), 'שני קבצים לאותה מילה — מסומן');
        eq(['9313.flac', 9313], [$m[6]['name'], $m[6]['word_id']], 'נתיב Windows');
        eq([null, null, ''], [$m[7]['word_id'], $m[8]['word_id'], $m[9]['name']], 'לא הותאמו; קלט שאינו טקסט');
        eq($before, db()->query('SELECT id, audio FROM words WHERE id >= 9300 AND id < 9400 ORDER BY id')->fetchAll(), 'ההתאמה לא חיברה דבר');
        throws(fn() => rec_match(['names' => 'x']), 'names שאינו רשימה');
        // אחרי האישור
        $f1 = a3_file('mp3', 'a3-9310.mp3');
        rec_add(A3_ED, ['file_id' => $f1, 'word_id' => 9310]);
        eq([$f1, 'uploaded'], [a3_audio(9310), a3_rec(9310)['how']], 'הותאמה וחוברה');
        $f2 = a3_file('m4a', 'a3-9311.m4a');
        $r = rec_add(A3_ED, ['file_id' => $f2, 'word_id' => 9311, 'on_conflict' => 'skip']);
        eq([$a, null], [a3_audio(9311), $r['word_id']], 'דלג — הקיימת נשארת, החדשה לא מחוברת');
        $f3 = a3_file('ogg', 'a3-9311b.ogg');
        rec_add(A3_ED, ['file_id' => $f3, 'word_id' => 9311, 'on_conflict' => 'replace']);
        eq($f3, a3_audio(9311), 'החלף');
        eq($a, rec_versions(['rec_id' => a3_rec(9311)['id']])['versions'][0]['file']['id'], 'הקודמת — גרסה');
        throws(fn() => rec_add(A3_ED, ['file_id' => $f3]), 'אותו קובץ פעמיים');
        $html = a3_file('aac', 'a3-x.aac');
        eq('audio', file_row($html)['kind'], 'aac גולמי — שמע');
        // קובץ שאינו שמע — נדחה (file_store אינו מקבל HTML; PDF/תמונה אינם kind=audio)
        $tmp = files_dir() . '/tmp/a3-t'; file_put_contents($tmp, "%PDF-1.4\n%\xe2\xe3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $pdf = file_store($tmp, 'a3-doc.pdf', A3_ED);
        try { rec_add(A3_ED, ['file_id' => $pdf]); throw new TestFail('PDF התקבל כהקלטה'); }
        catch (ApiError $e) { ok(str_contains($e->getMessage(), 'flac'), 'ההודעה מונה את הסוגים'); }
        // תקרה לקובץ — ההודעה אומרת מה המגבלה ומי משנה אותה
        setting_set('rec_file_max', (string)(1 << 20));
        $big = a3_file('mp3', 'a3-big.mp3', str_repeat('x', (1 << 20) + 10));
        try { rec_add(A3_ED, ['file_id' => $big]); throw new TestFail('קובץ מעל התקרה התקבל'); }
        catch (ApiError $e) { ok(str_contains($e->getMessage(), '1MB') && str_contains($e->getMessage(), 'בעל העמוד'), $e->getMessage()); }
    } finally { $clean(); }
});

test('A3 · סוגים: mp3, m4a, aac, ogg/opus, wav, webm, flac — זיהוי לפי תוכן; HTML בשם .mp3 — נדחה', function () {
    $clean = a3_fixture([]);
    try {
        foreach (['mp3' => 'mp3', 'm4a' => 'm4a', 'aac' => 'aac', 'ogg' => 'ogg', 'wav' => 'wav', 'flac' => 'flac'] as $k => $ext) {
            $f = file_row(a3_file($k, "a3-t.$k"));
            eq(['audio', $ext], [$f['kind'], $f['ext']], $k);
        }
        $tmp = files_dir() . '/tmp/a3-h'; file_put_contents($tmp, a3_bytes('html'));
        throws(fn() => file_store($tmp, 'a3-fake.mp3', A3_ED, 'audio/mpeg'), 'HTML בשם mp3');
    } finally { $clean(); }
});

test('A3 · הורדה: ZIP בחלקים, עם קובץ רשימה; כל קובץ זהה בבייט למה שהועלה (sha256); שמות בטוחים; רק למי שהתחיל', function () {
    $a = a3_file('mp3'); $b = a3_file('flac', 'a3-b.flac');
    $clean = a3_fixture([9320 => $a, 9321 => $b, 9322 => A3_U . 'x/22.mp3']);
    try {
        $c = a3_file('ogg', 'a3-c.ogg');
        $loose = rec_add(A3_ED, ['file_id' => $c, 'name' => '=../../HYPERLINK("x")'])['rec_id'];
        $ids = [a3_rec(9320)['id'], a3_rec(9321)['id'], a3_rec(9322)['id'], $loose];
        $z = rec_zip_start(A3_ED, ['ids' => $ids]);
        eq(3, $z['count'], 'רק מה שבכלי (לא הכתובת שבאתר)');
        setting_set('rec_zip_step_bytes', '1');                 // קובץ אחד בכל צעד
        $steps = 0;
        $z = rec_zip_step(A3_ED, ['token' => $z['token']]);
        eq([1, false], [$z['i'], $z['done']], 'צעד ראשון — קובץ אחד');
        // צעד שנקטע באמצע (זבל בסוף הקובץ) — הצעד הבא מתחיל מהמקום שנשמר
        file_put_contents(files_dir() . "/tmp/zip-{$z['token']}.zip", str_repeat('X', 999), FILE_APPEND);
        for ($i = 0; $i < 20 && !$z['done']; $i++, $steps++) $z = rec_zip_step(A3_ED, ['token' => $z['token']]);
        db()->exec("DELETE FROM settings WHERE k = 'rec_zip_step_bytes'");
        ok($z['done'] && $steps === 2, "הסתיים בחלקים ($steps צעדים נוספים)");
        throws(fn() => rec_zip_step(['sub' => 'u-other'] + A3_ED, ['token' => $z['token']]), 'משתמש אחר (2)');
        throws(fn() => rec_zip_step(A3_ED, ['token' => '../../etc/passwd']), 'אסימון עוין');
        $zip = new ZipArchive();
        eq(true, $zip->open(files_dir() . "/tmp/zip-{$z['token']}.zip", ZipArchive::CHECKCONS), 'ארכיון תקין');
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
        eq(['9320.mp3', '9321.flac', 'extra/=.._.._HYPERLINK(_x_).ogg', 'recordings.csv'], $names, 'השמות בארכיון');
        foreach ($names as $n) ok(!in_array('..', explode('/', $n), true) && substr_count($n, '/') <= 1 && $n[0] !== '/', "שם בטוח: $n");
        foreach ([['9320.mp3', $a], ['9321.flac', $b], ['extra/=.._.._HYPERLINK(_x_).ogg', $c]] as [$n, $fid]) {
            eq(file_row($fid)['sha256'], hash('sha256', (string)$zip->getFromName($n)), "זהה בבייט: $n");
            eq(hash_file('sha256', file_path(file_row($fid))), hash('sha256', (string)$zip->getFromName($n)), "זהה לקובץ בדיסק: $n");
        }
        $csv = (string)$zip->getFromName('recordings.csv');
        ok(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, '"9320","מילה 9320"'), 'רשימה: מזהה, מילה');
        ok(str_contains($csv, "\"'=../../HYPERLINK(\"\"x\"\")\""), 'ערך שמתחיל בנוסחה — מנוטרל; מרכאות כפולות');
        $zip->close();
        throws(fn() => rec_zip_start(A3_ED, ['ids' => [a3_rec(9322)['id']]]), 'רק הקלטה שבאתר — אין מה להוריד');
        // כולן
        $all = rec_zip_start(A3_ED, ['all' => true]);
        ok($all['count'] >= 3, 'כולן');
    } finally { $clean(); }
});

test('A3 · כפולות: "השאר אחת" — המילים עוברות לעותק שנשאר (הקודם — גרסה), הלא מחוברות — לסל', function () {
    $salt = bin2hex(random_bytes(6));
    $a = a3_file('mp3', 'a3-1.mp3', $salt); $b = a3_file('mp3', 'a3-2.mp3', $salt); $c = a3_file('mp3', 'a3-3.mp3', $salt);
    $clean = a3_fixture([9330 => $a, 9331 => $b]);
    try {
        eq(file_row($a)['sha256'], file_row($b)['sha256'], 'אותו sha256');
        $loose = rec_add(A3_ED, ['file_id' => $c])['rec_id'];
        $lib = library_list();
        $sha = array_values(array_filter($lib['rows'], fn($r) => $r['id'] === 9330))[0]['file']['sha'];
        eq(file_row($a)['sha256'], $sha, 'sha256 בשורה — לקבוצת הכפולות');
        $res = rec_dedupe(A3_ED, ['keep' => a3_rec(9330)['id']]);
        eq(['moved' => 1, 'trashed' => 1], $res, 'אחת עברה, אחת לסל');
        eq([$a, $a], [a3_audio(9330), a3_audio(9331)], 'שתי המילים על העותק שנשאר');
        eq($b, rec_versions(['rec_id' => a3_rec(9331)['id']])['versions'][0]['file']['id'], 'הקודם — גרסה (הפיך)');
        ok(rec_get($loose)['deleted_at'] !== null, 'הלא מחוברת — בסל');
        throws(fn() => rec_dedupe(A3_ED, ['keep' => a3_rec(9330)['id']]), 'אין עוד כפולות');
    } finally { $clean(); }
});

test('A3 · גרסאות: המקום שהן תופסות; "נקה גרסאות ישנות מ-…" — בעל העמוד, באישור; קובץ שבשימוש לא נמחק', function () {
    $a = a3_file('mp3'); $b = a3_file('mp3');
    $clean = a3_fixture([9340 => $a]);
    try {
        rec_replace(A3_ED, ['word_id' => 9340, 'file_id' => $b]);
        $u = rec_versions_usage();
        ok($u['count'] >= 1 && $u['bytes'] >= (int)file_row($a)['size'], 'נספר');
        throws(fn() => rec_versions_clean(A3_OWNER, ['before' => gmdate('Y-m-d', time() + 86400)]), 'בלי אישור');
        throws(fn() => rec_versions_clean(A3_OWNER, ['before' => "2026-01-01'; DROP TABLE words; --", 'confirm' => true]), 'תאריך עוין');
        $path = file_path(file_row($a));
        // אותו קובץ גם במילה אחרת — הגרסה יורדת, הקובץ נשאר
        db()->prepare("INSERT INTO words (id, wp_id, word, meaning, topics, audio, external_id, updated_at) VALUES (9341, 69341, 'מילה 9341', '', '[]', ?, '', ?)")->execute([$a, now_iso()]);
        rec_versions_clean(A3_OWNER, ['before' => gmdate('Y-m-d', time() + 86400), 'confirm' => true]);
        ok(is_file($path), 'קובץ שבשימוש נשאר');
        eq(0, count(rec_versions(['rec_id' => a3_rec(9340)['id']])['versions']), 'הגרסה ירדה');
        $c = a3_file('mp3');
        rec_replace(A3_ED, ['word_id' => 9340, 'file_id' => $c]);
        $p2 = file_path(file_row($b));
        $res = rec_versions_clean(A3_OWNER, ['before' => gmdate('Y-m-d', time() + 86400), 'confirm' => true]);
        ok($res['freed'] > 0 && !is_file($p2), 'קובץ שאף אחד אינו מפנה אליו — נמחק');
    } finally { $clean(); }
});

test('A3 · משך נשמר גם להקלטה לא מחוברת (לפי מזהה הרשומה)', function () {
    $clean = a3_fixture([]);
    try {
        $f = a3_file('mp3');
        $rid = rec_add(A3_ED, ['file_id' => $f])['rec_id'];
        eq(['saved' => true], rec_duration(A3_ED, ['rec_id' => $rid, 'file_id' => $f, 'duration' => 2.5]), 'נשמר');
        eq(2.5, (float)rec_get($rid)['duration'], 'המשך');
    } finally { $clean(); }
});

test('A3 · מה שלעולם לא: אין בקוד של הפעולות שום פנייה לאתר', function () {
    $src = (string)file_get_contents(KOL_ROOT . '/lib/rec_actions.php');
    $code = (string)preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src);
    foreach (['curl_', 'rec_http', 'fsockopen', 'stream_socket', 'file_get_contents(\'http', 'file_get_contents("http', 'https://', 'http://', 'get_headers', 'wp()'] as $bad) {
        ok(!str_contains($code, $bad), "נמצא: $bad");
    }
});
