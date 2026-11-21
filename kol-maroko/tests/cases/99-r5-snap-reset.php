<?php
// R5 — איפוס נקודות השחזור (הבעלים, 15.11): רק בעל האתר, באישור מפורש; נשארת נקודה אחת "מקור" של המצב הנוכחי,
// מוגנת ובראש הרשימה; ונקודה בלי קובצי העמוד (מלפני F2a) מסומנת.

declare(strict_types=1);

test('R5 איפוס נקודות: רק בעל האתר (403 לכל השאר), בלי confirm — שגיאה, ושום דבר לא נמחק', function () {
    snapshot_create(['sub' => 'u-editor', 'name' => 'עורך בדיקה'], 'לפני איפוס', 'manual', true);
    $n = (int)db()->query('SELECT COUNT(*) FROM snapshots')->fetchColumn();
    ok($n >= 1, 'יש נקודות לפני האיפוס');
    foreach ([U_CONTENT, U_EDITOR, U_PUBLISHER, U_PAGE, U_STRANGER] as $u) {
        eq(403, http('POST', '/api.php?action=snapshots_reset', ['user' => $u, 'json' => ['confirm' => true]])['status'], $u['sub']);
    }
    eq(403, throws(fn() => snapshots_reset(['sub' => 'u-page', 'name' => 'x', 'role' => 'page_owner'], ['confirm' => true])), 'גם בפונקציה עצמה');
    $r = http('POST', '/api.php?action=snapshots_reset', ['user' => U_OWNER, 'json' => []]);
    eq(400, $r['status'], 'בלי confirm');
    eq(400, http('POST', '/api.php?action=snapshots_reset', ['user' => U_OWNER, 'json' => ['confirm' => 'כן']])['status'], 'confirm שאינו true');
    eq($n, (int)db()->query('SELECT COUNT(*) FROM snapshots')->fetchColumn(), 'לא נמחקה אף נקודה');
});

test('R5 איפוס נקודות: נשארת נקודה אחת "מקור" של המצב הנוכחי, התוכן היתום נמחק, ושחזור אליה עובד', function () {
    $u = ['sub' => 'u-editor', 'name' => 'עורך בדיקה', 'role' => 'editor'];
    word_save($u, ['word' => 'r5-ישנה', 'meaning' => 'רק בנקודה הישנה']);
    snapshot_create($u, 'ישנה', 'manual', true);
    $wid = word_save($u, ['word' => 'r5-מקור', 'meaning' => 'במקור'])['id'];
    db()->exec("DELETE FROM words WHERE word = 'r5-ישנה'");
    $fp = snap_fingerprint();
    $before = (int)db()->query('SELECT COUNT(*) FROM snapshots')->fetchColumn();

    $r = http('POST', '/api.php?action=snapshots_reset', ['user' => U_OWNER, 'json' => ['confirm' => true]]);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    $id = $r['json']['origin'];
    eq($before, $r['json']['deleted'], 'כמה נקודות היו');
    eq(1, $r['json']['total'], 'נשארה נקודה אחת');
    $s = snapshot_row($id);
    eq(['origin', 'מקור'], [$s['kind'], $s['reason']]);
    eq($fp, hash('sha256', implode('|', array_map(fn($sha) => hash('sha256', blob_get($sha)), json_decode($s['parts'], true)))), 'המקור = המצב הנוכחי בדיוק');
    $keep = array_values(json_decode($s['parts'], true));
    eq(count(array_unique($keep)), (int)db()->query('SELECT COUNT(*) FROM blobs')->fetchColumn(), 'נשאר רק התוכן שהמקור מפנה אליו');
    ok(str_contains((string)db()->query("SELECT detail FROM audit WHERE action = 'snapshots_reset' ORDER BY id DESC LIMIT 1")->fetchColumn(), "#$id"), 'ביומן');

    // המקור מוגן: נקודות חדשות — והוא נשאר ראשון ברשימה
    word_save($u, ['id' => $wid, 'word' => 'r5-מקור', 'meaning' => 'שונה אחרי המקור']);
    snapshot_create($u, 'אחרי המקור', 'manual');
    $l = snapshots_list([]);
    eq([$id, 'origin'], [$l['snapshots'][0]['id'], $l['snapshots'][0]['kind']], 'המקור ראשון');
    eq(2, $l['total']);

    // שחזור אליו — כמו כל נקודה
    $rr = snapshot_restore($u, ['id' => $id]);
    eq($id, $rr['restored']);
    eq('במקור', db()->query("SELECT meaning FROM words WHERE id = $wid")->fetchColumn(), 'המילה חזרה');
    eq(0, (int)db()->query("SELECT COUNT(*) FROM words WHERE word = 'r5-ישנה'")->fetchColumn(), 'מה שהיה רק בנקודה שנמחקה — לא חזר');
    eq($fp, snap_fingerprint(), 'המצב = המקור');

    // איפוס שני — מחליף את המקור
    $r2 = snapshots_reset(['sub' => 'u-owner', 'name' => 'בעלים', 'role' => 'site_owner'], ['confirm' => true]);
    ok($r2['origin'] > $id, 'מקור חדש');
    eq([$r2['origin']], array_map('intval', db()->query('SELECT id FROM snapshots')->fetchAll(PDO::FETCH_COLUMN)), 'רק המקור החדש');
});

test('R5 נקודות: נקודה בלי קובצי העמוד (מלפני F2a) מסומנת ברשימה; נקודה רגילה — כוללת', function () {
    $u = ['sub' => 'u-editor', 'name' => 'עורך בדיקה', 'role' => 'editor'];
    $with = snapshot_create($u, 'עם קבצים', 'manual', true);
    $p = json_decode(snapshot_row($with)['parts'], true);
    eq(pf_seeded(), isset($p['project']), 'נקודה חדשה כוללת את project כשהפרויקט קיים');
    if (!isset($p['project'])) {                                              // הרצה מסוננת (R5 לבד) — הפרויקט עוד לא נוצר
        $p2 = $p + ['project' => blob_put('[]')];
        db()->prepare("UPDATE snapshots SET parts = ?, meta = json_set(meta, '$.project', json('true')) WHERE id = ?")->execute([json_encode($p2), $with]);
    }
    unset($p['project']);
    db()->prepare("INSERT INTO snapshots (at, sub, name, reason, kind, parts, meta) VALUES (?, 'u-editor', 'עורך', 'ישנה בלי קבצים', 'daily', ?, '{}')")->execute([now_iso(), json_encode($p)]);
    $old = (int)db()->lastInsertId();
    $by = array_column(snapshots_list([])['snapshots'], null, 'id');
    eq(true, $by[$with]['project'], 'עם קבצים');
    eq(false, $by[$old]['project'], 'בלי קבצים — מסומנת');
    eq(true, json_decode(snapshot_row($with)['meta'], true)['project'], 'וגם ב-meta');
    db()->prepare('DELETE FROM snapshots WHERE id = ?')->execute([$old]);
});
