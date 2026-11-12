<?php
/**
 * פרוסה N3b — (1) הקלטות לפריטי רשימה בספריית ההקלטות (N2b §2.6): העלאה, החלפה, הסרה, גרסאות, סל, חיבור, העברה, ZIP — כמו
 * במילים; (2) ביטול "➕ הוסף לעמוד" (Ctrl+Z / "↶ בטל") עם בדיקת rev. הרשימה "proverbs" והקבצים "a3-…" — של הבדיקה.
 */

declare(strict_types=1);

require_once KOL_ROOT . '/lib/library.php';

/** רשימה "פתגמים" עם שני שדות שמע (rec, rec_fr) ושלושה פריטים; בפריט 1 — הקלטה. מחזיר [fid של פריט 1, ניקוי]. */
function n3b_fixture(): array
{
    l3_reset();
    eq(200, l3_create('proverbs', 'פתגמים')['status']);
    eq(200, b_post('field_save', ['list' => 'proverbs', 'key' => 'rec', 'label' => 'הקלטה', 'type' => 'audio'])['status']);
    eq(200, b_post('field_save', ['list' => 'proverbs', 'key' => 'rec_fr', 'label' => 'הקלטה בצרפתית', 'type' => 'audio'])['status']);
    $a = a3_file('mp3', 'a3-n3b-1.mp3');
    eq(200, l3_item('proverbs', ['title' => 'מי שמחכה', 'rec' => $a])['status']);
    eq(200, l3_item('proverbs', ['title' => 'אין עשן בלי אש'])['status']);
    eq(200, l3_item('proverbs', ['title' => 'שלישי'])['status']);
    rec_sync();
    $clean = a3_fixture([]);
    return [$a, function () use ($clean) {
        db()->exec("DELETE FROM pf_files WHERE path LIKE 'media/audio/proverbs-%' OR path LIKE 'media/audio/sayings-%'");
        db()->exec("DELETE FROM settings WHERE k = 'list_place_undo'");
        l3_reset();
        $clean();
    }];
}

function n3b_rec(int $id, string $field = 'rec', string $list = 'proverbs'): ?array
{
    return rec_of_word($id, $field, $list);
}

function n3b_val(int $id, string $field = 'rec', string $list = 'proverbs'): string
{
    return rec_slot_get($list, $id, $field);
}

function n3b_row(array $lib, int $id, string $field = 'rec', string $list = 'proverbs'): ?array
{
    foreach ($lib['rows'] as $r) if (($r['id'] ?? null) === "$list:$id:$field") return $r;
    return null;
}

function n3b_node(int $id, string $field = 'rec', string $list = 'proverbs'): ?array
{
    $st = db()->prepare("SELECT * FROM pf_files WHERE deleted_at IS NULL AND kind = 'media' AND path LIKE ?");
    $st->execute(["media/audio/$list-$id-$field.%"]);
    return $st->fetch() ?: null;
}

test('N3b · סנכרון וספרייה: שורה לכל פריט × שדה שמע של רשימה — "<רשימה>.<מפתח>", "פתגמים · הקלטה", "חסרה" לכל פריט בלי', function () {
    [$a, $clean] = n3b_fixture();
    try {
        $r = n3b_rec(1);
        ok($r && $r['file_id'] === $a && $r['state'] === 'tool' && $r['list'] === 'proverbs', 'רשומה במקום (proverbs, 1, rec)');
        eq('מי שמחכה', $r['name'], 'השם — הכותרת של הפריט');
        $lib = library_list();
        $row = n3b_row($lib, 1);
        ok($row && $row['state'] === 'tool' && $row['field'] === 'proverbs.rec' && $row['wid'] === 1 && $row['list'] === 'proverbs', 'שורה בספרייה');
        eq('פתגמים · הקלטה', $row['field_label']);
        ok(str_starts_with($row['src'], 'api.php?action=media&') && $row['file']['id'] === $a, 'מתנגנת מהכלי');
        eq('none', n3b_row($lib, 2)['state'] ?? null, 'פריט בלי — "חסרה"');
        eq('none', n3b_row($lib, 1, 'rec_fr')['state'] ?? null, 'השדה השני — "חסרה"');
        $f = array_column($lib['fields'], null, 'key')['proverbs.rec'] ?? null;
        ok($f && $f['list'] === 'proverbs' && $f['label'] === 'פתגמים · הקלטה', 'הסינון "רשימה · שדה"');
        eq(['audio' => 1, 'none' => 2], $lib['counts']['fields']['proverbs.rec']);
        ok(isset(files_in_use()[$a]), 'הקובץ "בשימוש" (לא נמחק מ-🗂️ קבצים)');
        $node = n3b_node(1);
        ok($node && $node['file_id'] === $a && $node['path'] === 'media/audio/proverbs-1-rec.mp3', 'נגישה גם מ-</> קוד: ' . json_encode($node));
        // המילים — כמו לפני: אין שורה של רשימה בשורות המילים, והשדה הראשי במקומו
        ok(!array_filter($lib['rows'], fn($x) => !isset($x['list']) && is_string($x['id']) && str_starts_with($x['id'], 'proverbs')), 'בלי ערבוב');
    } finally { $clean(); }
});

test('N3b · העלאה, החלפה (גרסה), חזרה לגרסה — הערך נכתב רק דרך rec_slot_set, וה-rev של data/proverbs.json משתנה', function () {
    [$a, $clean] = n3b_fixture();
    try {
        $rev0 = list_rev('proverbs');
        $b = a3_file('mp3', 'a3-n3b-2.mp3');
        $res = rec_replace(A3_ED, ['word_id' => 2, 'field' => 'proverbs.rec', 'file_id' => $b]);
        eq($b, n3b_val(2), 'מקום ריק ← הקלטה ראשונה');
        ok($res['rec_id'] > 0 && n3b_rec(2)['id'] === $res['rec_id']);
        ok($rev0 !== list_rev('proverbs'), 'rev של הקובץ');
        eq($b, n3b_node(2)['file_id'] ?? null, 'הנתיב בעץ');
        // החלפה: הקודמת — גרסה; אותה רשומה
        $c = a3_file('m4a', 'a3-n3b-3.m4a');
        $rid = (int)n3b_rec(1)['id'];
        rec_replace(A3_ED, ['rec_id' => $rid, 'file_id' => $c]);
        eq($c, n3b_val(1));
        eq($rid, (int)n3b_rec(1)['id'], 'אותה רשומה');
        eq($c, n3b_node(1)['file_id'], 'העץ מצביע על החדש; הנתיב לא השתנה');
        eq('media/audio/proverbs-1-rec.mp3', n3b_node(1)['path']);
        $v = rec_versions(['rec_id' => $rid])['versions'];
        eq(1, count($v));
        eq($a, $v[0]['file']['id']);
        rec_revert(A3_ED, ['rec_id' => $rid, 'version_id' => $v[0]['id']]);
        eq($a, n3b_val(1), 'חזר לגרסה');
        eq(1, count(rec_versions(['rec_id' => $rid])['versions']), 'הנוכחית נשמרה כגרסה');
        // שדה שאינו קיים / פריט שאינו קיים / שדה שאינו audio
        throws(fn() => rec_replace(A3_ED, ['word_id' => 99, 'field' => 'proverbs.rec', 'file_id' => $b]));
        throws(fn() => rec_replace(A3_ED, ['word_id' => 1, 'field' => 'proverbs.title', 'file_id' => $b]));
        throws(fn() => rec_replace(A3_ED, ['word_id' => 1, 'field' => 'nolist.rec', 'file_id' => $b]));
    } finally { $clean(); }
});

test('N3b · החלפה מחוץ לספרייה (הטבלה של הרשימה) — הקודמת נשמרת כגרסה, והנתיב בעץ מצביע על החדש', function () {
    [$a, $clean] = n3b_fixture();
    try {
        $rid = (int)n3b_rec(1)['id'];
        $b = a3_file('mp3', 'a3-n3b-t.mp3');
        eq(200, l3_item('proverbs', ['rec' => $b], 1)['status']);
        rec_sync();
        eq($rid, (int)n3b_rec(1)['id']);
        eq($b, n3b_rec(1)['file_id']);
        eq($a, rec_versions(['rec_id' => $rid])['versions'][0]['file']['id'] ?? null, 'גרסה');
        eq($b, n3b_node(1)['file_id'] ?? null, 'החבילה לא תצא עם הקובץ הקודם');
        // ריקון מהטבלה ⇒ "לא מחוברת" (לא נמחקת)
        eq(200, l3_item('proverbs', ['rec' => null], 1)['status']);
        rec_sync();
        $r = rec_get($rid);
        ok($r['word_id'] === null && $r['deleted_at'] === null && $r['list'] === 'proverbs', 'לא מחוברת');
        // אותו קובץ חזר למקום ⇒ אותה רשומה (שם וגרסאות)
        eq(200, l3_item('proverbs', ['rec' => $b], 1)['status']);
        rec_sync();
        eq($rid, (int)n3b_rec(1)['id'], 'התחברה מחדש');
    } finally { $clean(); }
});

test('N3b · ניתוק, "חבר ל…" (גם בין מילה לפריט), העברה לשדה באותה רשימה, סל ושחזור — למקום ברשימה', function () {
    [$a, $clean] = n3b_fixture();
    try {
        $rid = (int)n3b_rec(1)['id'];
        rec_disconnect(A3_ED, ['ids' => [$rid]]);
        eq('', n3b_val(1));
        ok(rec_get($rid)['word_id'] === null, 'לא מחוברת');
        // חבר לפריט 2
        $res = rec_connect(A3_ED, ['rec_id' => $rid, 'word_id' => 2, 'field' => 'proverbs.rec']);
        eq('proverbs.rec', $res['field']);
        eq($a, n3b_val(2));
        eq(2, (int)rec_get($rid)['word_id']);
        // לפריט 3 כבר יש הקלטה ⇒ 409 עם פרטים; באישור — הקודמת "לא מחוברת"
        $b = a3_file('mp3', 'a3-n3b-4.mp3');
        rec_replace(A3_ED, ['word_id' => 3, 'field' => 'proverbs.rec', 'file_id' => $b]);
        $code = throws(fn() => rec_connect(A3_ED, ['rec_id' => $rid, 'word_id' => 3, 'field' => 'proverbs.rec']));
        eq(409, $code);
        rec_connect(A3_ED, ['rec_id' => $rid, 'word_id' => 3, 'field' => 'proverbs.rec', 'replace' => true]);
        eq($a, n3b_val(3));
        eq('', n3b_val(2), 'המקום הקודם התרוקן');
        // העברה לשדה אחר — רק באותה רשימה
        rec_move(A3_ED, ['rec_id' => $rid, 'field' => 'rec_fr']);
        eq($a, n3b_val(3, 'rec_fr'));
        eq('', n3b_val(3));
        throws(fn() => rec_move(A3_ED, ['rec_id' => $rid, 'field' => 'audio']));
        ok(n3b_node(3, 'rec_fr'), 'נתיב בעץ לשדה החדש');
        // סל ← שחזור — חוזר לאותו מקום
        rec_delete(A3_ED, ['ids' => [$rid]]);
        eq('', n3b_val(3, 'rec_fr'));
        $t = array_values(array_filter(rec_trash(), fn($x) => $x['rec'] === $rid))[0];
        eq('proverbs', $t['list']);
        eq('proverbs.rec_fr', $t['field']);
        eq('פתגמים', $t['list_label']);
        eq('שלישי', $t['word']);
        $back = rec_restore(A3_ED, ['id' => $rid]);
        eq(3, $back['word_id']);
        eq($a, n3b_val(3, 'rec_fr'), 'חזרה למקום');
        // הקלטה של מילה ↔ פריט ברשימה: ההקלטה עוברת, והיא אותה רשומה
        $w = a3_file('mp3', 'a3-n3b-w.mp3');
        $wclean = a3_fixture([9351 => $w]);
        $wr = (int)rec_of_word(9351)['id'];
        rec_connect(A3_ED, ['rec_id' => $wr, 'word_id' => 2, 'field' => 'proverbs.rec']);
        eq($w, n3b_val(2));
        eq('', a3_audio(9351), 'המילה נשארה בלי');
        rec_connect(A3_ED, ['rec_id' => $wr, 'word_id' => 9351]);
        eq($w, a3_audio(9351), 'וחזרה למילה');
        eq('', n3b_val(2));
        $wclean();
    } finally { $clean(); }
});

test('N3b · מחיקת פריט / רשימה — "לא מחוברת" עם "מרשימה שנמחקה: <שם>"; שחזור הרשימה מחבר את אותה רשומה', function () {
    [$a, $clean] = n3b_fixture();
    try {
        $rid = (int)n3b_rec(1)['id'];
        eq(200, b_post('list_item_delete', ['list' => 'proverbs', 'id' => 1])['status']);
        rec_sync();
        ok(rec_get($rid)['word_id'] === null, 'פריט לסל ⇒ לא מחוברת');
        $tid = (int)db()->query("SELECT id FROM list_trash WHERE kind = 'item' ORDER BY id DESC")->fetchColumn();
        eq(200, b_post('trash_restore', ['kind' => 'list', 'id' => $tid])['status']);
        rec_sync();
        eq(1, (int)(rec_get($rid)['word_id'] ?? 0), 'שחזור הפריט ⇒ מחוברת שוב');
        // הרשימה כולה
        eq(200, b_post('list_delete', ['key' => 'proverbs'], U_PUBLISHER)['status']);
        rec_sync();
        ok(rec_get($rid)['word_id'] === null, 'לא מחוברת');
        $lib = library_list();
        $lo = array_values(array_filter($lib['loose'], fn($x) => $x['rec'] === $rid))[0] ?? null;
        eq('מרשימה שנמחקה: פתגמים', $lo['gone'] ?? null);
        eq('proverbs.rec', $lo['field'] ?? null);
        $lt = (int)db()->query("SELECT id FROM list_trash WHERE kind = 'list' ORDER BY id DESC")->fetchColumn();
        $r = b_post('trash_restore', ['kind' => 'list', 'id' => $lt]);
        eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
        rec_sync();
        eq(1, (int)(rec_get($rid)['word_id'] ?? 0), 'אותה רשומה — שם, משך וגרסאות');
        eq('proverbs', rec_get($rid)['list']);
    } finally { $clean(); }
});

test('N3b · שינוי מפתח של שדה ושל רשימה — recordings והנתיבים בעץ עוברים באותה עסקה, בלי כפולה', function () {
    [$a, $clean] = n3b_fixture();
    try {
        $rid = (int)n3b_rec(1)['id'];
        $n = (int)db()->query('SELECT COUNT(*) FROM recordings')->fetchColumn();
        $revs = fn(array $pv) => array_column(array_map(fn($h) => ['f' => $h['file'], 'r' => $h['rev']], $pv['json']['hits'] ?? []), 'r', 'f');
        $r = b_post('field_key_apply', ['list' => 'proverbs', 'key' => 'rec', 'to' => 'voice', 'revs' => $revs(b_get('field_key_preview', '&list=proverbs&kind=field&key=rec&to=voice'))]);
        eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
        rec_sync();
        eq($rid, (int)(n3b_rec(1, 'voice')['id'] ?? 0), 'אותה רשומה במפתח החדש');
        eq($n, (int)db()->query('SELECT COUNT(*) FROM recordings')->fetchColumn(), 'בלי כפולה');
        ok(n3b_node(1, 'voice') && !n3b_node(1, 'rec'), 'הנתיב בעץ');
        $r = b_post('list_key_apply', ['key' => 'proverbs', 'to' => 'sayings', 'revs' => $revs(b_get('list_key_preview', '&key=proverbs&to=sayings'))]);
        eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
        rec_sync();
        eq($rid, (int)(rec_of_word(1, 'voice', 'sayings')['id'] ?? 0), 'הרשימה במפתח החדש');
        eq($n, (int)db()->query('SELECT COUNT(*) FROM recordings')->fetchColumn());
        ok(n3b_node(1, 'voice', 'sayings'), 'media/audio/sayings-1-voice.mp3');
        eq(200, b_post('list_key_apply', ['key' => 'sayings', 'to' => 'proverbs', 'revs' => $revs(b_get('list_key_preview', '&key=sayings&to=proverbs'))])['status']);
    } finally { $clean(); }
});

test('N3b · ZIP "<רשימה>-<מזהה>-<מפתח>", העלאה חזרה מתאימה לאותו מקום, ו"הוספה" לשדה של רשימה; אין המרה (sha256)', function () {
    [$a, $clean] = n3b_fixture();
    try {
        $rid = (int)n3b_rec(1)['id'];
        db()->exec("INSERT INTO settings (k, v) VALUES ('rec_zip_step_bytes', '1') ON CONFLICT(k) DO UPDATE SET v = excluded.v");
        $z = rec_zip_start(A3_ED, ['ids' => [$rid]]);
        while (!$z['done']) $z = rec_zip_step(A3_ED, ['token' => $z['token']]);
        $j = rec_zip_job(A3_ED, $z['token']);
        eq('proverbs-1-rec.mp3', $j['items'][0]['path']);
        eq('מי שמחכה', $j['items'][0]['word']);
        $zip = (string)file_get_contents(rec_zip_dir() . "/zip-{$z['token']}.zip");
        ok(str_contains($zip, file_get_contents(file_path(file_row($a)))), 'הבייטים כמו שהועלו');
        ok(str_contains($zip, 'proverbs,rec,"פתגמים · הקלטה"') || str_contains($zip, '"proverbs","rec","פתגמים · הקלטה"'), 'קובץ הרשימה');
        db()->exec("DELETE FROM settings WHERE k = 'rec_zip_step_bytes'");
        // ההתאמה לפי שם — גם בלי לבחור את השדה במסך
        $m = rec_match(['names' => ['proverbs-1-rec.mp3', 'proverbs-2-rec_fr.mp3', 'אין עשן בלי אש.mp3', 'proverbs-9-rec.mp3']])['items'];
        eq([1, 'proverbs.rec', 'proverbs'], [$m[0]['word_id'], $m[0]['field'], $m[0]['list']]);
        eq($rid, $m[0]['conflict']['rec_id'] ?? null, 'מתנגש עם הקיימת');
        eq([2, 'proverbs.rec_fr', null], [$m[1]['word_id'], $m[1]['field'], $m[1]['conflict']]);
        eq(null, $m[2]['word_id'], 'שם של פריט — רק כשבוחרים את השדה של הרשימה');
        eq(null, $m[3]['word_id']);
        $m = rec_match(['names' => ['אין עשן בלי אש.mp3', '3.mp3'], 'field' => 'proverbs.rec'])['items'];
        eq([2, 'word'], [$m[0]['word_id'], $m[0]['by']]);
        eq([3, 'id'], [$m[1]['word_id'], $m[1]['by']]);
        // הוספה
        $b = a3_file('mp3', 'a3-n3b-add.mp3');
        $res = rec_add(A3_ED, ['file_id' => $b, 'word_id' => 2, 'field' => 'proverbs.rec_fr']);
        eq('proverbs.rec_fr', $res['field']);
        eq($b, n3b_val(2, 'rec_fr'));
        eq('אין עשן בלי אש', n3b_rec(2, 'rec_fr')['name']);
        eq(hash('sha256', (string)file_get_contents(file_path(file_row($b)))), file_row($b)['sha256'], 'אין המרה');
    } finally { $clean(); }
});

test('N3b · הרשאות: רק עורך ומעלה משנה הקלטה של פריט; מחיקה סופית — רק בעל העמוד; שום פנייה לאתר', function () {
    [$a, $clean] = n3b_fixture();
    try {
        $b = a3_file('mp3', 'a3-n3b-p.mp3');
        $r = b_post('rec_replace', ['word_id' => 2, 'field' => 'proverbs.rec', 'file_id' => $b], U_STRANGER);
        ok(in_array($r['status'], [401, 403], true), 'זר: ' . $r['status']);
        eq('', n3b_val(2));
        $r = b_post('rec_replace', ['word_id' => 2, 'field' => 'proverbs.rec', 'file_id' => $b]);
        eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
        eq($b, n3b_val(2));
        $rid = (int)n3b_rec(2)['id'];
        eq(200, b_post('rec_delete', ['ids' => [$rid]])['status']);
        eq(403, b_post('rec_purge', ['id' => $rid, 'confirm' => true])['status'], 'עורך — לא');
        $src = (string)file_get_contents(KOL_ROOT . '/lib/rec_actions.php');
        ok(!preg_match('/\bcurl_|\brec_http\s*\(/', $src), 'אין curl / rec_http');
    } finally { $clean(); }
});

// ── ביטול "➕ הוסף לעמוד" ─────────────────────────────────

/** עמוד נקי + רשימה; מחזיר [מזהה העמוד, התוכן, ה-CSS שלפני | null]. */
function n3b_page(): array
{
    l3_reset();
    l3_create('proverbs', 'פתגמים');
    db()->exec("DELETE FROM settings WHERE k = 'list_place_undo'");
    $page = "<!doctype html>\n<html>\n<body>\n  <main>\n    <h1 id=\"top\">כותרת</h1>\n  </main>\n</body>\n</html>\n";
    $id = b_id('n3b-place.html') ?: pf_new('n3b-place.html', $page)['json']['file']['id'];
    b_save('n3b-place.html', $page);
    $css = b_id('css/page.css');
    return [$id, $page, $css ? pf_get($css) : null];
}

test('N3b · "↶ בטל" אחרי "➕ הוסף לעמוד": העמוד וה-CSS חוזרים בדיוק למה שהיו (גרסה חדשה "בוטל"), kol-data.js שנוצר — לסל; פעם אחת', function () {
    [$id, $page, $css] = n3b_page();
    $kd = b_id('js/kol-data.js');
    if ($kd) eq(200, b_post('pf_delete', ['id' => $kd])['status']);
    $before = count(b_get('pf_versions', "&id=$id")['json']['versions']);
    $r = b_post('list_place', ['list' => 'proverbs', 'path' => 'n3b-place.html', 'where' => 'main']);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    ok(preg_match('/^[0-9a-f]{24}$/', (string)($r['json']['undo'] ?? '')), 'אסימון ביטול');
    ok(str_contains(pf_get($id), 'data-kol-list="proverbs"') && b_id('js/kol-data.js') > 0, 'נוסף');
    // אדם אחר — לא יכול לבטל את ההוספה של העורך
    eq(404, b_post('list_place_undo', ['token' => $r['json']['undo']], U_PUBLISHER)['status']);
    $u = b_post('list_place_undo', ['token' => $r['json']['undo']]);
    eq(200, $u['status'], json_encode($u['json'], JSON_UNESCAPED_UNICODE));
    eq($page, pf_get($id), 'העמוד — בדיוק');
    if ($css !== null) eq($css, pf_get(b_id('css/page.css')), 'ה-CSS — בדיוק');
    else eq(0, b_id('css/page.css'), 'ה-CSS שנוצר — לסל');
    eq(0, b_id('js/kol-data.js'), 'kol-data.js שנוצר — לסל');
    $v = b_get('pf_versions', "&id=$id")['json']['versions'];
    eq($before + 2, count($v), 'ההיסטוריה לא נמחקה: הוספה + ביטול');
    ok(str_starts_with($v[0]['note'], 'בוטל: הוסף את הרשימה'), $v[0]['note']);
    eq(404, b_post('list_place_undo', ['token' => $r['json']['undo']])['status'], 'פעם אחת');
    eq(404, b_post('list_place_undo', ['token' => 'zz'])['status']);
    ok((int)db()->query("SELECT COUNT(*) FROM audit WHERE detail LIKE 'בוטל: הוסף את הרשימה%'")->fetchColumn() >= 1, 'ביומן הפעולות');
    if (!b_id('js/kol-data.js')) {                  // מחזירים את הקובץ לשאר הבדיקות
        $t = db()->query("SELECT id FROM pf_files WHERE path = 'js/kol-data.js' AND deleted_at IS NOT NULL ORDER BY id DESC")->fetchColumn();
        if ($t) b_post('pf_restore', ['ids' => [(int)$t]]);
    }
});

test('N3b · ביטול אחרי שהעמוד השתנה — 409, ושום קובץ לא נוגע (בדיקת rev); ה-CSS שכבר היה בו הבלוק — לא נוגע', function () {
    [$id, $page, $css] = n3b_page();
    $r = b_post('list_place', ['list' => 'proverbs', 'path' => 'n3b-place.html', 'where' => 'main']);
    eq(200, $r['status']);
    $cssId = b_id('css/page.css');
    $cssAfter = pf_get($cssId);
    $edited = str_replace('כותרת', 'כותרת שנערכה', pf_get($id));
    b_save('n3b-place.html', $edited);
    $u = b_post('list_place_undo', ['token' => $r['json']['undo']]);
    eq(409, $u['status']);
    ok(str_contains((string)($u['json']['error'] ?? ''), 'n3b-place.html'), 'ההודעה אומרת מה השתנה');
    eq($edited, pf_get($id), 'העמוד — כמו שנערך');
    eq($cssAfter, pf_get($cssId), 'ה-CSS לא חזר לבד');
    // הוספה שנייה: הבלוק כבר ב-CSS ⇒ הביטול נוגע רק בעמוד
    b_save('n3b-place.html', $page);
    $r2 = b_post('list_place', ['list' => 'proverbs', 'path' => 'n3b-place.html', 'where' => 'main']);
    eq(200, b_post('list_place_undo', ['token' => $r2['json']['undo']])['status']);
    eq($page, pf_get($id));
    eq($cssAfter, pf_get($cssId), 'ה-CSS לא נגע');
});
