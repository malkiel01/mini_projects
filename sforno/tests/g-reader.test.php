<?php
/**
 * הקורא, הסידור והתהילים — docs/spec/04-reader-siddur-tehillim.md §6 ("R<מספר> …").
 *
 *   השרת: הקריאה הציבורית (read-*), החיפוש מול tests/search-cases.json (R5), תמונות ועמודים רק של תוכן שפורסם, הניהול
 *   (מנהגים, רשימת הסידור, בדיקת שילובים), ותוכן הדוגמה.
 *   הלוגיקה של הדפדפן (R2 המקום, R3 מנהגים זמניים, R6 ניקוד והעתקה, R7 קישור, R9 wakeLock, R10 הסדר האישי) — ב-node,
 *   tests/reader-core.mjs. הבדיקה בדפדפן אמיתי — tests/browser/reader.cjs (Playwright; לא רץ ב-run.php).
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$root = dirname(__DIR__);
$A = ident('501', 'מנהלת', 'a@example.test');
call('GET', 'me', [], $A);
$ok = function (array $r, string $name): array {
    eq($r[0], 200, $name . ($r[0] !== 200 ? ' — ' . json_encode($r[1]['error'] ?? $GLOBALS['t_last_500'] ?? null, JSON_UNESCAPED_UNICODE) : ''));
    return $r[1];
};
$me = (int)staff_by_sub('501')['id'];

// ── ההרשאות: קריאה בלי זיהוי, ניהול רק למנהל ─────────────────
foreach (['read-catalog', 'read-toc', 'read-revision', 'read-search', 'read-file', 'read-pages'] as $a) {
    eq(SF_ACTIONS[$a]['who'], 'reader', "$a — reader (בלי עוגייה, בלי מידע אישי)");
    eq(SF_ACTIONS[$a]['m'], 'GET', "$a — GET בלבד");
}
foreach (['customs-list', 'custom-save', 'siddur-items', 'siddur-item-save', 'siddur-item-move', 'siddur-item-available', 'customs-checks', 'customs-check-approve', 'content-pages-set', 'content-sample'] as $a) {
    eq(SF_ACTIONS[$a]['who'], 'staff', "$a — מנהלים בלבד");
    eq(code(call(SF_ACTIONS[$a]['m'], $a, [], null)), 'login_required', "$a בלי זהות — 401");
}
$app = json_decode((string)file_get_contents("$root/app.json"), true);
check('read-* לא ב-"public" של app.json עד ההשקה (11)', !str_contains(implode(' ', $app['public']), 'read-'));

// ── קטלוג ריק, ותוכן הדוגמה ──────────────────────────────────
$cat = $ok(call('GET', 'read-catalog'), 'read-catalog בלי זהות');
eq($cat['titles'], [], 'אין כותר ציבורי — קטלוג ריק');
eq(array_column($cat['customs'], 'grp'), ['place', 'quorum'], 'הקטלוג כולל את קבוצות המנהגים');
$sample = $ok(call('POST', 'content-sample', [], $A), 'content-sample');
eq(code(call('POST', 'content-sample', [], $A)), 'state', 'תוכן דוגמה — פעם אחת');
$S = $sample['siddur'];
$T = $sample['tehillim'];
$cat = $ok(call('GET', 'read-catalog'), 'read-catalog');
$names = array_column($cat['titles'], 'name');
check('תוכן הדוגמה מסומן "דוגמה"', $names === ['סידור (דוגמה)', 'תהילים (דוגמה)'], $names);
$sid = $cat['titles'][0]['editions'][0];
eq(array_column($sid['items'], 'name'), ['שחרית', 'קריאת שמע', 'מנחה', 'ערבית'], 'רשימת הבסיס של הסידור');
check('הקטלוג לא חושף טיוטות או שדות ניהול', !str_contains(json_encode($cat), 'draft') && !isset($sid['items'][0]['available']));

// ── read-revision / read-toc ────────────────────────────────
$rv = $ok(call('GET', 'read-revision', [], null, ['edition' => $S]), 'read-revision');
check('הגרסה שפורסמה, עם תוכן עניינים', $rv['revision']['n'] === 1 && $rv['revision']['current'] && count($rv['toc']) >= 8 && str_contains($rv['html'], 'sf-cond rule="chul"'));
$toc = $ok(call('GET', 'read-toc', [], null, ['edition' => $T]), 'read-toc');
eq(array_column(array_filter($toc['toc'], fn($t) => $t['parent_sid'] === null), 'title'), ['פרק א', 'פרק ב', 'פרק ג', 'פרק ד', 'פרק ה'], 'תהילים: הפרקים');
// טיוטה חדשה — הקורא עדיין מקבל את מה שפורסם
$eid = (int)sf_val('SELECT id FROM editions WHERE pid = ?', [$S]);
sf_rev_insert($eid, (int)sf_published($eid)['id'], '<p>טיוטה-סודית-שלא-פורסמה</p>', $me);
$rv2 = $ok(call('GET', 'read-revision', [], null, ['edition' => $S]), 'read-revision עם טיוטה פתוחה');
check('טיוטה לא מגיעה לקורא', !str_contains(json_encode($rv2, JSON_UNESCAPED_UNICODE), 'טיוטה-סודית'));
$draftPid = sf_val("SELECT pid FROM revisions WHERE edition_id = ? AND status = 'draft'", [$eid]);
eq(code(call('GET', 'read-revision', [], null, ['edition' => $S, 'revision' => $draftPid])), 'not_found', 'טיוטה לפי מזהה — 404');
eq(code(call('GET', 'read-revision', [], null, ['edition' => 'x'])), 'bad_input', 'מזהה לא תקין — 400');
// כותר שכובה "ציבורי" — כאילו לא קיים
$tPid = $cat['titles'][1]['pid'];
$ok(call('POST', 'content-title-public', ['title' => $tPid, 'on' => false], $A), 'כיבוי "ציבורי"');
eq(code(call('GET', 'read-revision', [], null, ['edition' => $T])), 'not_found', 'כותר לא ציבורי — 404');
eq(count($ok(call('GET', 'read-catalog'), 'קטלוג')['titles']), 1, 'כותר לא ציבורי — לא בקטלוג');
$hidden = $ok(call('GET', 'read-search', [], null, ['q' => 'צדיקים']), 'חיפוש');
eq($hidden['results'], [], 'כותר לא ציבורי — לא בחיפוש');
$ok(call('POST', 'content-title-public', ['title' => $tPid, 'on' => true], $A), 'הדלקה חוזרת');

// ── R5: אוסף בדיקות החיפוש ──────────────────────────────────
$sc = json_decode((string)file_get_contents(__DIR__ . '/search-cases.json'), true);
check('search-cases.json תקין', is_array($sc['cases'] ?? null) && count($sc['cases']) >= 10);
foreach ($sc['cases'] as $c) {
    $res = $ok(call('GET', 'read-search', [], null, ['q' => $c['q']]), "R5 חיפוש: {$c['q']}");
    $texts = array_map(fn($r) => (string)sf_val('SELECT s.text_norm FROM segments s JOIN revisions v ON v.id = s.revision_id JOIN editions e ON e.id = v.edition_id
        WHERE e.pid = ? AND s.sid = ? AND v.status = \'published\'', [$r['edition'], $r['sid']]), $res['results']);
    foreach ($c['must'] as $m) check("R5 \"{$c['q']}\" ← חייב: $m", (bool)array_filter($texts, fn($t) => str_contains($t, $m)), $texts);
    foreach ($c['must_not'] as $m) check("R5 \"{$c['q']}\" ← אסור: $m", !array_filter($texts, fn($t) => str_contains($t, $m)), $texts);
}
$r = $ok(call('GET', 'read-search', [], null, ['q' => 'השם']), 'חיפוש בכינוי');
check('תוצאה: מקור, מקטע, תקציר, והמילים להדגשה (גם בכתיב החלופי)', isset($r['results'][0]['title'], $r['results'][0]['snippet'], $r['results'][0]['sid']) && in_array("ה'", $r['words'], true));
check('תוצאה בתוך מקטע — שם המקטע', in_array('ברכות השחר', array_column($r['results'], 'section'), true));
$r = $ok(call('GET', 'read-search', [], null, ['q' => 'צדיקים', 'edition' => $S]), 'חיפוש בספר אחד');
eq($r['results'], [], 'חיפוש בסידור לא מחזיר תהילים');
$r = $ok(call('GET', 'read-search', [], null, ['q' => '']), 'שאילתה ריקה');
eq($r['results'], [], 'שאילתה ריקה — אין תוצאות');
check('טיוטה לא בחיפוש', $ok(call('GET', 'read-search', [], null, ['q' => 'טיוטה-סודית-שלא-פורסמה']), 'חיפוש טיוטה')['results'] === []);
// עימוד: 20 בכל פעם
$p1 = $ok(call('GET', 'read-search', [], null, ['q' => 'ברוך']), 'עימוד');
check('עימוד: עד 20, more כשיש עוד', count($p1['results']) <= 20 && is_bool($p1['more']));

// wordHit: JS = PHP
$pairs = [['שכוי', 'לשכוי'], ['שכוי', 'שכוי'], ['רשע', 'רשעים'], ['אל', 'ישראל'], ['אל', 'ואל'], ['דרך', 'ובדרך'], ['דרך', 'שובדרך'], ['עמד', 'בעמידה'],
    ['ה\'', 'וה\''], ['א', 'בא'], ['ישב', 'אישב'], ['שם', 'השם'], ['שם', 'ושבהשם'], ['', 'ו']];
file_put_contents(SF_DATA_DIR . '/pairs.json', json_encode($pairs, JSON_UNESCAPED_UNICODE));
exec('command -v node 2>/dev/null', $_n, $nodeCode);
check('node מותקן (בדיקות הלוגיקה של הקורא)', $nodeCode === 0);
if ($nodeCode === 0) {
    exec('node ' . escapeshellarg(__DIR__ . '/reader-core.mjs') . ' ' . escapeshellarg(SF_DATA_DIR . '/pairs.json') . ' 2>&1', $out, $code);
    eq($code, 0, 'reader-core.mjs רץ' . ($code ? ': ' . implode(' | ', array_slice($out, -5)) : ''));
    $js = json_decode(implode("\n", $out), true);
    if (is_array($js)) {
        foreach ($js['fails'] as $f) check($f, false);
        check('reader-core: ' . $js['n'] . ' בדיקות', $js['n'] >= 30 && !$js['fails']);
        eq($js['wordHit'], array_map(fn($p) => sf_search_word_hit($p[0], $p[1]), $pairs), 'R5 התאמת מילה: render (JS) = השרת');
    }
}

// ── תמונות: רק של תוכן שפורסם ────────────────────────────────
// ── תמונות: רק של תוכן שפורסם (במהדורה נפרדת, כדי לא לשנות את הדוגמה) ─────
$mk = function (string $html) use ($A, $ok): array {
    $t = $ok(call('POST', 'content-title-save', ['name' => 'בדיקה', 'kind' => 'siddur'], $A), 'כותר לבדיקה')['title']['pid'];
    $ok(call('POST', 'content-title-public', ['title' => $t, 'on' => true], $A), 'ציבורי');
    $e = $ok(call('POST', 'content-edition-save', ['title' => $t, 'label' => 'בדיקה'], $A), 'מהדורה')['edition']['pid'];
    $ok(call('POST', 'content-save', ['edition' => $e, 'html' => $html, 'base' => 0], $A), 'טיוטה');
    return ['title' => $t, 'edition' => $e, 'id' => (int)sf_val('SELECT id FROM editions WHERE pid = ?', [$e])];
};
$dir = sf_imp_dir('abcdefghij012345') . '/files';
sf_imp_mkdir($dir);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
file_put_contents("$dir/1.png", $png);
file_put_contents("$dir/2.png", $png);
$fp = fn(string $p) => (function () use ($p) { try { return sf_read_file_path(['path' => $p]); } catch (SfError $e) { return $e->getMessage(); } })();
check('R תמונה שאינה בשום תוכן — 404', !is_array($fp('files/abcdefghij012345/1.png')));
$X = $mk('<p>בדיקתתמונה<img src="files/abcdefghij012345/1.png" alt=""></p>');
check('R תמונה בטיוטה בלבד — 404', !is_array($fp('files/abcdefghij012345/1.png')));
$v = (int)sf_val("SELECT draft_v FROM revisions WHERE edition_id = ? AND status = 'draft'", [$X['id']]);
$pubRes = call('POST', 'content-publish', ['edition' => $X['edition'], 'base' => $v], $A);
eq($pubRes[0], 200, 'פרסום גרסה עם תמונה');
check('R תמונה בגרסה שפורסמה — מוגשת', is_array($fp('files/abcdefghij012345/1.png')));
check('R תמונה אחרת באותה תיקייה — 404', !is_array($fp('files/abcdefghij012345/2.png')));
check('R נתיב זר — 404', !is_array($fp('files/abcdefghij012345/../../x.png')) && !is_array($fp('files/x.png')));
$ok(call('POST', 'content-title-public', ['title' => $X['title'], 'on' => false], $A), 'כיבוי');
check('R כותר לא ציבורי — התמונה 404', !is_array($fp('files/abcdefghij012345/1.png')));
$ok(call('POST', 'content-title-public', ['title' => $X['title'], 'on' => true], $A), 'הדלקה');
// גרסה שנייה — הקודמת נשארת זמינה לפי מזהה (מיקום שנשמר עליה)
$ok(call('POST', 'content-save', ['edition' => $X['edition'], 'html' => '<p>בדיקתתמונה שתיים</p>', 'base' => 0, 'from' => 1], $A), 'טיוטה 2');
$first = $ok(call('GET', 'read-revision', [], null, ['edition' => $X['edition']]), 'גרסה 1')['revision']['pid'];
$ok(call('POST', 'content-publish', ['edition' => $X['edition'], 'base' => (int)sf_val("SELECT draft_v FROM revisions WHERE edition_id = ? AND status = 'draft'", [$X['id']])], $A), 'פרסום 2');
$rv3 = $ok(call('GET', 'read-revision', [], null, ['edition' => $X['edition']]), 'גרסה 2');
$old = $ok(call('GET', 'read-revision', [], null, ['edition' => $X['edition'], 'revision' => $first]), 'גרסה קודמת לפי מזהה');
check('גרסה קודמת (superseded) — לפי מזהה, מסומנת לא-נוכחית', $old['revision']['n'] === 1 && $old['revision']['current'] === false && $rv3['revision']['n'] === 2);

// ── PDF שלא נבדק: לא כטקסט ולא בחיפוש; עמודים — כן ─────────
$ins = function (string $kind, string $status, ?int $rev, int $checked = 0) use ($X, $me): array {
    $eid = $X['id'];
    $pid = sf_pid();
    sf_insert('imports', ['pid' => $pid, 'edition_id' => $eid, 'kind' => $kind, 'ext' => $kind === 'image' ? 'png' : 'pdf', 'file_name' => 'x', 'size' => 1, 'sha256' => 'x',
        'status' => $status, 'progress' => 100, 'pages' => 3, 'text_checked' => $checked, 'revision_id' => $rev, 'created_by' => $me, 'created_at' => sf_now(), 'updated_at' => sf_now()]);
    return sf_import_by_pid($pid);
};
$eid = $X['id'];
$pubId = (int)sf_published($eid)['id'];
$pdf = $ins('pdf', 'applied', $pubId);
eq(code(call('GET', 'read-revision', [], null, ['edition' => $X['edition']])), 'not_found', 'PDF שלא נבדק, בלי עמודים — 404');
eq($ok(call('GET', 'read-search', [], null, ['q' => 'שתיים']), 'חיפוש')['results'], [], 'PDF שלא נבדק — לא בחיפוש');
$ok(call('POST', 'content-pages-set', ['edition' => $X['edition'], 'import' => $pdf['pid']], $A), 'עמודים לקוראים');
$rv4 = $ok(call('GET', 'read-revision', [], null, ['edition' => $X['edition']]), 'עמודים בלבד');
check('PDF שלא נבדק — עמודים בלבד (html = null)', $rv4['html'] === null && $rv4['pages']['kind'] === 'pdf' && $rv4['pages']['pages'] === 3 && $rv4['toc'] === []);
mkdir(sf_imp_dir($pdf['pid']), 0700, true);
file_put_contents(sf_imp_dir($pdf['pid']) . '/original.pdf', '%PDF-1.4');
check('read-pages מגיש את המקור של העמודים', sf_read_pages_path(['edition' => $X['edition']])[1] === 'application/pdf');
sf_exec('UPDATE imports SET text_checked = 1 WHERE id = ?', [$pdf['id']]);
$rv5 = $ok(call('GET', 'read-revision', [], null, ['edition' => $X['edition']]), 'PDF שנבדק');
check('PDF שנבדק — טקסט ועמודים', $rv5['html'] !== null && $rv5['pages'] !== null);
check('PDF שנבדק — בחיפוש', count($ok(call('GET', 'read-search', [], null, ['q' => 'שתיים']), 'חיפוש')['results']) === 1);
$scan = $ins('pdf_scan', 'ready', null);
eq(code(call('POST', 'content-pages-set', ['edition' => $S, 'import' => $scan['pid']], $A)), 'bad_input', 'עמודים מייבוא של מהדורה אחרת — נדחה');
$docx = $ins('docx', 'ready', null);
eq(code(call('POST', 'content-pages-set', ['edition' => $X['edition'], 'import' => $docx['pid']], $A)), 'state', 'ייבוא בלי עמודים — נדחה');
$ok(call('POST', 'content-pages-set', ['edition' => $X['edition'], 'import' => null], $A), 'הסרת העמודים');
check('בלי עמודים — read-pages 404', (function () use ($X) { try { sf_read_pages_path(['edition' => $X['edition']]); return false; } catch (SfError $e) { return true; } })());
eq(audit_count('edition.pages_on') + audit_count('edition.pages_off'), 2, 'העמודים — ביומן');
$view = $ok(call('GET', 'import-status', [], $A, ['import' => $scan['pid']]), 'import-status');
check('import-status: reader_pages', $view['import']['reader_pages'] === false);

// ── המנהגים ──────────────────────────────────────────────────
$g = $ok(call('POST', 'custom-save', ['create' => true, 'rule' => 'sefard', 'name' => 'ספרדי', 'grp' => 'nusach', 'default_on' => true], $A), 'מנהג חדש');
$ng = array_values(array_filter($g['groups'], fn($x) => $x['grp'] === 'nusach'))[0];
check('מנהג חדש בקבוצה חדשה, ברירת מחדל', $ng['customs'][0]['rule'] === 'sefard' && $ng['customs'][0]['default_on'] && !$ng['customs'][0]['sample']);
$ok(call('POST', 'custom-save', ['create' => true, 'rule' => 'ashk', 'name' => 'אשכנזי', 'grp' => 'nusach', 'default_on' => true], $A), 'מנהג שני');
check('ברירת מחדל — אחת לקבוצה', (int)sf_val("SELECT COUNT(*) FROM customs WHERE grp = 'nusach' AND default_on = 1") === 1 && sf_val("SELECT rule FROM customs WHERE grp = 'nusach' AND default_on = 1") === 'ashk');
eq(code(call('POST', 'custom-save', ['create' => true, 'rule' => 'ashk', 'name' => 'x', 'grp' => 'nusach'], $A)), 'state', 'מזהה כפול — נדחה');
eq(code(call('POST', 'custom-save', ['create' => true, 'rule' => 'Bad Rule', 'name' => 'x', 'grp' => 'nusach'], $A)), 'bad_input', 'מזהה לא תקין — נדחה');
$ok(call('POST', 'custom-save', ['rule' => 'ashk', 'name' => 'אשכנז', 'available' => false, 'grp' => 'other'], $A), 'עדכון: לא זמין');
check('עדכון לא משנה קבוצה; לא זמין מוריד ברירת מחדל', sf_row("SELECT grp, available, default_on, name FROM customs WHERE rule = 'ashk'") == ['grp' => 'nusach', 'available' => 0, 'default_on' => 0, 'name' => 'אשכנז']);
$pubCustoms = $ok(call('GET', 'read-catalog'), 'קטלוג')['customs'];
check('קורא: רק מנהגים זמינים', !str_contains(json_encode($pubCustoms), 'ashk') && str_contains(json_encode($pubCustoms), 'sefard'));
check('מחיקת מנהג — אין פעולה כזו', !isset(SF_ACTIONS['custom-delete']));

// ── רשימת הסידור ─────────────────────────────────────────────
$it = $ok(call('GET', 'siddur-items', [], $A, ['edition' => $S]), 'siddur-items');
check('רשימת הניהול: כל הפריטים, עם תוכן עניינים ליעד', count($it['items']) === 4 && count($it['toc']) > 4);
$secSid = $it['toc'][0]['sid'];
$it = $ok(call('POST', 'siddur-item-save', ['edition' => $S, 'name' => 'תיקון חצות', 'kind' => 'prayer', 'sid' => $secSid], $A), 'פריט חדש');
$new = end($it['items']);
check('פריט חדש — בסוף', $new['name'] === 'תיקון חצות' && $new['available']);
eq(code(call('POST', 'siddur-item-save', ['edition' => $S, 'name' => 'x', 'sid' => 'zzzzzzzz'], $A)), 'bad_input', 'יעד שאינו בגרסה שפורסמה — נדחה');
eq(code(call('POST', 'siddur-item-save', ['edition' => $T, 'name' => 'x'], $A)), 'state', 'רשימת תפילות — רק לסידור');
$it = $ok(call('POST', 'siddur-item-move', ['item' => $new['pid'], 'dir' => 'up'], $A), 'הזזה');
eq(array_column($it['items'], 'name')[3], 'תיקון חצות', 'הזזה למעלה');
$first = $it['items'][0]['pid'];
$ok(call('POST', 'siddur-item-available', ['item' => $first, 'on' => false], $A), 'לא זמין');
$items = $ok(call('GET', 'read-catalog'), 'קטלוג')['titles'][0]['editions'][0]['items'];
check('R10 פריט שהוסר — לא בקטלוג (הקורא מסמן "לא זמין" אצלו), ולא נמחק', !in_array($first, array_column($items, 'pid'), true) && sf_val('SELECT 1 FROM siddur_items WHERE pid = ?', [$first]));

// ── R4: בדיקת שילובי מנהגים ──────────────────────────────────
$ck = $ok(call('GET', 'customs-checks', [], $A, ['edition' => $S]), 'customs-checks');
eq(count($ck['combos']), 2 * 2 * 1, 'כל השילובים: מנהג זמין אחד מכל קבוצה');
check('עדיין לא אושר דבר', !array_filter($ck['combos'], fn($c) => $c['approved']));
$combo = $ck['combos'][0]['combo'];
eq(code(call('POST', 'customs-check-approve', ['edition' => $S, 'combo' => $combo, 'revision' => 'aaaaaaaaaaaaaaaa'], $A)), 'conflict', 'R4 אישור מול גרסה אחרת — נדחה');
eq(code(call('POST', 'customs-check-approve', ['edition' => $S, 'combo' => ['place' => 'eretz'], 'revision' => $ck['published']['pid']], $A)), 'bad_input', 'R4 שילוב חסר — נדחה');
eq(code(call('POST', 'customs-check-approve', ['edition' => $S, 'combo' => ['place' => 'eretz', 'quorum' => 'yachid', 'nusach' => 'ashk'], 'revision' => $ck['published']['pid']], $A)), 'bad_input', 'R4 מנהג לא זמין — נדחה');
$ck = $ok(call('POST', 'customs-check-approve', ['edition' => $S, 'combo' => $combo, 'revision' => $ck['published']['pid']], $A), 'R4 אושר');
$a = $ck['combos'][0]['approved'];
check('R4 נשמר: הגרסה, התאריך, מי, ונוכחי', $a['n'] === $ck['published']['n'] && $a['current'] && $a['by'] === 'מנהלת' && $a['at'] !== '');
check('R4 האישור — רק מתווסף (טריגר)', (function () { try { sf_exec('DELETE FROM custom_checks'); return false; } catch (Throwable) { return true; } })()
    && (function () { try { sf_exec("UPDATE custom_checks SET combo = 'x'"); return false; } catch (Throwable) { return true; } })());
eq(audit_count('customs.check'), 1, 'R4 האישור — ביומן');
$sId = (int)sf_val('SELECT id FROM editions WHERE pid = ?', [$S]);
$ok(call('POST', 'content-publish', ['edition' => $S, 'base' => (int)sf_val("SELECT draft_v FROM revisions WHERE edition_id = ? AND status = 'draft'", [$sId])], $A), 'גרסה חדשה לסידור');
$ck = $ok(call('GET', 'customs-checks', [], $A, ['edition' => $S]), 'customs-checks אחרי פרסום');
check('R4 גרסה חדשה — האישור הישן נשאר, ומסומן "לבדוק שוב"', $ck['combos'][0]['approved']['n'] === 1 && $ck['combos'][0]['approved']['current'] === false);

// ── R7, R8 (סטטי): הקוד של הקורא ─────────────────────────────
$rd = (string)file_get_contents("$root/assets/js/screens/reader.js");
$sd = (string)file_get_contents("$root/assets/js/screens/siddur.js");
$core = (string)file_get_contents("$root/assets/js/reader-core.js");
check('R7 השיתוף — רק דרך shareLink(base, edition, sid)', preg_match_all('/shareLink\(/', $rd) === 1 && str_contains($rd, 'C.shareLink(location.origin + location.pathname, edition, here)'));
check('R8 קטע מוסתר — hidden (לא רק CSS)', str_contains($rd, 'x.hidden = true'));
foreach (['reader.js' => $rd, 'siddur.js' => $sd, 'reader-admin.js' => (string)file_get_contents("$root/assets/js/screens/reader-admin.js"), 'reader-core.js' => $core,
    'store.js' => (string)file_get_contents("$root/assets/js/store.js")] as $f => $src) {
    check("$f בלי innerHTML / outerHTML / insertAdjacentHTML / document.write", !preg_match('/innerHTML|outerHTML|insertAdjacentHTML|document\.write/', $src));
    check("$f בלי localStorage (המידע האישי — ב-IndexedDB)", !str_contains($src, 'localStorage'));
}
check('השמות לתפילה לא נשלחים לשרת', !preg_match('/SF\.api\([^)]*names/', $sd) && !str_contains($sd, "SF.api('read-search', { params: Object.assign({ q: q.value, offset }, edition ? { edition } : {}), names"));
check('R9 wakeLock — רק דרך C.wakeLock (כשל שקט)', !preg_match('/navigator\.wakeLock\.request/', $rd));
$idx = (string)file_get_contents("$root/index.html");
check('index.html: reader-core ו-store לפני המסכים', strpos($idx, 'reader-core.js') < strpos($idx, 'screens/reader.js') && strpos($idx, 'store.js') < strpos($idx, 'screens/siddur.js'));

done();
