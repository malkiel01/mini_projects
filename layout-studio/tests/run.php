<?php
// בדיקות השרת של העימוד: php layout-studio/tests/run.php
// רצות על מסד זמני — לא נוגעות ב-data/.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/layout-studio-test-' . getmypid() . '.sqlite';
@unlink($tmp);
putenv('LAYOUT_STUDIO_DB=' . $tmp);
$tmpFiles = sys_get_temp_dir() . '/layout-studio-files-' . getmypid();
putenv('LAYOUT_STUDIO_FILES=' . $tmpFiles);
register_shutdown_function(function () use ($tmpFiles) {
    foreach (glob($tmpFiles . '/*/*') ?: [] as $f) unlink($f);
    foreach (glob($tmpFiles . '/*') ?: [] as $d) rmdir($d);
    @rmdir($tmpFiles);
});
register_shutdown_function(fn() => array_map('unlink', glob($tmp . '*') ?: []));

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/docs.php';
require_once __DIR__ . '/../lib/model.php';
require_once __DIR__ . '/../lib/media.php';

$fails = 0;
$n = 0;
function check(string $name, bool $ok): void
{
    global $fails, $n;
    $n++;
    if (!$ok) $fails++;
    echo ($ok ? '✓ ' : '✗ ') . $name . "\n";
}
function as_user(?array $u): void
{
    $_SERVER['PLATFORM_USER'] = $u === null ? '' : json_encode($u);
}
function throws(callable $f, string $code): bool
{
    try { $f(); } catch (InvalidArgumentException $e) { return $e->getMessage() === $code; }
    return false;
}

// ---- משתמשים ----
as_user(null);
check('בלי זהות מהשער — אין משתמש', ls_account() === null);
$_SERVER['PLATFORM_USER'] = '{"name":"x"}';
check('זהות בלי sub — אין משתמש', ls_account() === null);
$_SERVER['PLATFORM_USER'] = 'not json';
check('זהות פגומה — אין משתמש', ls_account() === null);

as_user(['sub' => '17', 'name' => 'דנה', 'email' => 'Dana@Example.com']);
$a = ls_account();
check('משתמש ראשון נפתח מיד', $a !== null && $a['sub'] === '17');
check('המשתמש הראשון — מנהל', $a['role'] === 'admin');
check('המייל נשמר באותיות קטנות', $a['email'] === 'dana@example.com');
check('כניסה שנייה — אותו חשבון', ls_account()['id'] === $a['id']);

as_user(['sub' => '18', 'name' => 'יוסי', 'email' => 'yosi@example.com']);
$b = ls_account();
check('משתמש שני — חשבון חדש, לא מנהל', $b['id'] !== $a['id'] && $b['role'] === 'user');

as_user(['sub' => '17', 'name' => 'דנה לוי', 'email' => 'dana@example.com']);
check('שם מתעדכן מהפלטפורמה', ls_account()['name'] === 'דנה לוי');

// חשבון ישן בלי sub (למשל מייבוא) — מתאחד לפי מייל
ls_db()->prepare('INSERT INTO accounts (sub, email, name, role, created_at) VALUES (NULL, ?, ?, ?, ?)')
    ->execute(['old@example.com', 'ישן', 'user', ls_now()]);
$oldId = (int)ls_db()->lastInsertId();
as_user(['sub' => '30', 'name' => 'ישן', 'email' => 'OLD@example.com']);
$c = ls_account();
check('איחוד לפי מייל — אותו חשבון, מקושר ל-sub', (int)$c['id'] === $oldId && $c['sub'] === '30');

// ---- מסמכים ----
$uidA = (int)$a['id'];
$uidB = (int)$b['id'];
check('רשימה ריקה', ls_docs_list($uidA) === []);
$d = ls_doc_create($uidA, '  סידור   לשבת ', 'a5');
check('יצירה — השם מנוקה', $d['title'] === 'סידור לשבת');
check('A5 ב-pt', abs($d['page'][0] - 419.53) < 0.01 && abs($d['page'][1] - 595.28) < 0.01);
$c2 = ls_doc_create($uidA, 'מותאם', 'custom', ['100', 150]);
check('גודל מותאם במ"מ', abs($c2['page'][0] - 283.46) < 0.01);
check('שם ריק — נדחה', throws(fn() => ls_doc_create($uidA, '   ', 'a4'), 'title_required'));
check('שם ארוך — נדחה', throws(fn() => ls_doc_create($uidA, str_repeat('א', 121), 'a4'), 'title_too_long'));
check('גודל לא מוכר — נדחה', throws(fn() => ls_doc_create($uidA, 'x', 'a9'), 'bad_size'));
check('גודל קטן מדי — נדחה', throws(fn() => ls_doc_create($uidA, 'x', 'custom', [10, 200]), 'bad_size'));
check('גודל לא מספר — נדחה', throws(fn() => ls_doc_create($uidA, 'x', 'custom', ['abc', 200]), 'bad_size'));
check('רשימה — שני מסמכים', count(ls_docs_list($uidA)) === 2);
check('משתמש אחר לא רואה', ls_docs_list($uidB) === []);
check('משתמש אחר לא מוחק', ls_doc_delete($uidB, $d['id']) === false);
check('הבעלים מוחק', ls_doc_delete($uidA, $d['id']) === true);
check('מחיקה שנייה — לא נמצא', ls_doc_delete($uidA, $d['id']) === false);
check('נמחק — לא ברשימה', count(ls_docs_list($uidA)) === 1);

// ---- מודל: פתיחה ושמירה ----
$doc = ls_doc_create($uidA, 'ספר', '17x24');
$g = ls_doc_get($uidA, $doc['id']);
$pagesIn = fn($m) => count(array_filter((array)$m->surfaces, fn($x) => $x->type === 'page'));
check('מסמך חדש — 4 עמודים, כפולים, כריכה מימין', $pagesIn($g['model']) === 4 && $g['model']->layout->facing === true && $g['model']->layout->binding === 'right');
check('מסמך חדש — rev 1', $g['rev'] === 1);
check('flows ריק נשאר אובייקט', json_encode($g['model']->flows) === '{}');
check('משתמש אחר לא פותח', ls_doc_get($uidB, $doc['id']) === null);

$m = json_decode(json_encode($g['model']), true);
$m['surfaces'][] = ['id' => 'pX', 'type' => 'page', 'size' => $m['surfaces'][0]['size'], 'master' => null, 'layers' => []];
$m['futureField'] = ['x' => 1];   // שדה שאיננו מכירים — נשמר כמו שהוא
$raw = json_encode($m, JSON_UNESCAPED_UNICODE);
$rev = ls_doc_save($uidA, $doc['id'], 1, $raw);
check('שמירה — rev עולה', $rev === 2);
$g2 = ls_doc_get($uidA, $doc['id']);
check('נשמר — 5 עמודים, ושדה לא מוכר נשאר', $pagesIn($g2['model']) === 5 && $g2['model']->futureField->x === 1);
check('שמירה עם rev ישן — conflict', throws(fn() => ls_doc_save($uidA, $doc['id'], 1, $raw), 'conflict'));
check('שמירה למסמך של אחר — not_found', throws(fn() => ls_doc_save($uidB, $doc['id'], 2, $raw), 'not_found'));

$bad = function (callable $mut, string $code) use ($m, $uidA, $doc) {
    $x = $m; $mut($x);
    return throws(fn() => ls_doc_save($uidA, $doc['id'], 2, json_encode($x)), 'bad_model:' . $code);
};
check('כריכה לא מוכרת — נדחה', $bad(function (&$x) { $x['layout']['binding'] = 'top'; }, 'binding'));
check('שוליים שליליים — נדחה', $bad(function (&$x) { $x['layout']['margins']['top'] = -1; }, 'margins'));
check('שוליים שלא משאירים מקום — נדחה', $bad(function (&$x) { $x['layout']['margins']['inside'] = 450; }, 'margins'));
check('מזהה עמוד כפול — נדחה', $bad(function (&$x) { $x['surfaces'][1]['id'] = $x['surfaces'][0]['id']; }, 'page_id'));
check('עמודים בגדלים שונים — נדחה', $bad(function (&$x) { $x['surfaces'][1]['size'] = [300, 500]; }, 'mixed_size'));
check('בלי עמודים — נדחה', $bad(function (&$x) { $x['surfaces'] = []; }, 'pages'));
check('JSON פגום — נדחה', throws(fn() => ls_doc_save($uidA, $doc['id'], 2, '{oops'), 'bad_model:root'));
check('משטח מסוג אחר — נשמר בלי בדיקת גודל', ls_doc_save($uidA, $doc['id'], 2, json_encode(array_merge($m, ['surfaces' => array_merge($m['surfaces'], [['id' => 'm1', 'type' => 'master']])]))) === 3);

// מסמך מגרסת מסד 1 (בלי model) — מקבל מודל בפתיחה
ls_db()->prepare('INSERT INTO docs (owner_id, title, page_w, page_h, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$uidA, 'ישן', 419.53, 595.28, ls_now(), ls_now()]);
$old = ls_doc_get($uidA, (int)ls_db()->lastInsertId());
check('מסמך ישן — מודל בגודל שלו', $old && $old['model']->surfaces[0]->size[0] == 419.53 && $old['rev'] === 1);

// ---- עמודי אב ומספור ----
$mm = json_decode(json_encode($g['model']), true);
$master = array_values(array_filter($mm['surfaces'], fn($x) => $x['type'] === 'master'))[0] ?? null;
check('מסמך חדש — עמוד אב A עם מספר עמוד', $master && $master['id'] === 'mA' && $master['running']['folio']['align'] === 'outside');
check('מסמך חדש — כל העמודים על A', count(array_filter($mm['surfaces'], fn($x) => $x['type'] === 'page' && $x['master'] === 'mA')) === 4);
check('מסמך חדש — מספור מ-1 בספרות', $mm['layout']['numbering'] === ['start' => 1, 'style' => 'arabic']);
$d3 = ls_doc_create($uidA, 'אב', 'a5');
$try = function (callable $mut, string $code) use ($uidA, $d3) {
    $x = json_decode(json_encode(ls_doc_get($uidA, $d3['id'])['model']), true);
    $mut($x);
    return throws(fn() => ls_doc_save($uidA, $d3['id'], 1, json_encode($x)), 'bad_model:' . $code);
};
check('עמוד שמפנה לעמוד אב שלא קיים — נדחה', $try(function (&$x) { $x['surfaces'][0]['master'] = 'mZ'; }, 'master_ref'));
check('מספור מ-0 — נדחה', $try(function (&$x) { $x['layout']['numbering']['start'] = 0; }, 'numbering'));
check('סגנון מספור לא מוכר — נדחה', $try(function (&$x) { $x['layout']['numbering']['style'] = 'roman'; }, 'numbering'));
check('מיקום מספר לא מוכר — נדחה', $try(function (&$x) { $x['surfaces'][4]['running']['folio']['align'] = 'middle'; }, 'running'));
check('כותרת רצה בלי טקסט זוגי — נדחה', $try(function (&$x) {
    $x['surfaces'][4]['running']['header'] = ['odd' => 'פרק', 'position' => 'top', 'align' => 'center', 'size' => 9]; }, 'running'));
$ok = json_decode(json_encode(ls_doc_get($uidA, $d3['id'])['model']), true);
$ok['surfaces'][0]['master'] = null;
$ok['surfaces'][4]['running']['header'] = ['odd' => 'פרק א', 'even' => 'סידור לשבת', 'position' => 'top', 'align' => 'center', 'size' => 9];
$ok['layout']['numbering'] = ['start' => 3, 'style' => 'hebrew'];
check('עמוד בלי אב, כותרת רצה ומספור עברי — נשמר', ls_doc_save($uidA, $d3['id'], 1, json_encode($ok, JSON_UNESCAPED_UNICODE)) === 2);
$mOld = json_decode(json_encode($ok), true);
unset($mOld['layout']['numbering']);
check('מסמך בלי numbering (לפני עמודי האב) — נשמר', ls_doc_save($uidA, $d3['id'], 2, json_encode($mOld)) === 3);

// ---- מסגרות טקסט ושטפים ----
$d4 = ls_doc_create($uidA, 'טקסט', 'a5');
$base = json_decode(json_encode(ls_doc_get($uidA, $d4['id'])['model']), true);
$withFrame = function (array $m) {
    $m['surfaces'][0]['layers'][] = ['id' => 't1', 'type' => 'frame', 'name' => 'טקסט', 'box' => [40, 40, 300, 500], 'flow' => 'f1', 'columns' => 1, 'gutter' => 12];
    $m['surfaces'][1]['layers'][] = ['id' => 't2', 'type' => 'frame', 'box' => [40, 40, 300, 500], 'flow' => 'f1', 'columns' => 2, 'gutter' => 12];
    $m['flows'] = ['f1' => ['engine' => 'prosemirror', 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'שָׁלוֹם']]]]], 'frames' => ['t1', 't2'], 'autogrow' => true, 'style' => 'body']];
    $m['styles'] = ['body' => ['font' => 'Noto Serif Hebrew', 'size' => 12, 'leading' => 18, 'align' => 'justify', 'spaceAfter' => 6]];
    return $m;
};
$rv = 1;
$tryF = function (callable $mut, string $code) use ($uidA, $d4, $base, $withFrame, &$rv) {
    $x = $withFrame($base); $mut($x);
    return throws(fn() => ls_doc_save($uidA, $d4['id'], $rv, json_encode($x)), 'bad_model:' . $code);
};
check('מסגרות בשרשור, שטף וסגנון — נשמר', ($rv = ls_doc_save($uidA, $d4['id'], 1, json_encode($withFrame($base), JSON_UNESCAPED_UNICODE))) === 2);
check('מסגרת שמפנה לשטף שלא קיים — נדחה', $tryF(function (&$x) { $x['surfaces'][0]['layers'][0]['flow'] = 'f9'; }, 'flow_ref'));
check('שרשור עם מסגרת שלא קיימת — נדחה', $tryF(function (&$x) { $x['flows']['f1']['frames'][] = 'tX'; }, 'thread'));
check('מסגרת פעמיים בשרשור — נדחה', $tryF(function (&$x) { $x['flows']['f1']['frames'] = ['t1', 't1']; }, 'thread'));
check('מזהה שכבה שזהה למזהה עמוד — נדחה', $tryF(function (&$x) { $x['surfaces'][0]['layers'][0]['id'] = $x['surfaces'][0]['id']; }, 'layer_id'));
check('מסגרת ברוחב 0 — נדחה', $tryF(function (&$x) { $x['surfaces'][0]['layers'][0]['box'][2] = 0; }, 'frame'));
check('13 טורים — נדחה', $tryF(function (&$x) { $x['surfaces'][1]['layers'][0]['columns'] = 13; }, 'frame'));
check('גודל גופן 1 — נדחה', $tryF(function (&$x) { $x['styles']['body']['size'] = 1; }, 'style'));
check('יישור לא מוכר — נדחה', $tryF(function (&$x) { $x['styles']['body']['align'] = 'middle'; }, 'style'));
check('שטף של אפליקציה אחרת (בלי frames) — נשמר כמו שהוא', ls_doc_save($uidA, $d4['id'], 2, json_encode(array_merge($withFrame($base),
    ['flows' => $withFrame($base)['flows'] + ['f7' => ['engine' => 'other', 'doc' => ['x' => 1]]]]), JSON_UNESCAPED_UNICODE)) === 3);

// ---- סגנונות ----
$st = $withFrame($base);
$st['styles']['h1'] = ['name' => 'כותרת 1', 'font' => 'Noto Serif Hebrew', 'size' => 20, 'leading' => 26, 'align' => 'center', 'weight' => 700, 'spaceBefore' => 12, 'spaceAfter' => 10, 'indent' => 0];
$st['styles']['other'] = ['x' => 'של אפליקציה אחרת'];
$st['flows']['f1']['doc']['content'][] = ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'פרק', 'marks' => [['type' => 'bold']]]]];
check('כותרת, הדגשה וסגנון זר — נשמרים', ls_doc_save($uidA, $d4['id'], 3, json_encode($st, JSON_UNESCAPED_UNICODE)) === 4);
$rv = 4;
check('משקל 1000 — נדחה', $tryF(function (&$x) { $x['styles']['h2'] = ['font' => 'a', 'size' => 12, 'leading' => 18, 'align' => 'right', 'spaceAfter' => 0, 'weight' => 1000]; }, 'style'));
check('הזחה שלילית — נדחה', $tryF(function (&$x) { $x['styles']['body']['indent'] = -5; }, 'style'));

// ---- תמונות ----
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAEklEQVR4nGP4z8DAwMDAxMAAAAs/AQX+M4EaAAAAAElFTkSuQmCC');
$m1 = ls_media_put($uidA, $png);
check('העלאת PNG — src לפי הגיבוב, וגודל בפיקסלים', $m1['src'] === 'media/' . hash('sha256', $png) . '.png' && $m1['px'] === [2, 3]);
check('אותה תמונה פעמיים — אותו קובץ', ls_media_put($uidA, $png)['src'] === $m1['src']);
check('הקובץ קיים אצל הבעלים בלבד', ls_media_path($uidA, substr($m1['src'], 6)) !== null && ls_media_path($uidB, substr($m1['src'], 6)) === null);
check('שם עם נתיב — נדחה', ls_media_path($uidA, '../../x.png') === null);
check('לא תמונה — נדחה', throws(fn() => ls_media_put($uidA, '<?php echo 1;'), 'bad_image'));
check('PNG פגום (רק החתימה) — נדחה', throws(fn() => ls_media_put($uidA, "\x89PNG\r\n\x1a\n" . str_repeat('x', 40)), 'bad_image'));
check('SVG — נדחה', throws(fn() => ls_media_put($uidA, '<svg xmlns="http://www.w3.org/2000/svg"/>'), 'bad_image'));

$img = $withFrame($base);
$img['styles'] = $st['styles'];
$img['surfaces'][2]['layers'][] = ['id' => 'i1', 'type' => 'frame', 'name' => 'תמונה', 'box' => [40, 40, 200, 300], 'src' => $m1['src'],
    'px' => [2, 3], 'fit' => 'cover', 'focus' => [0.5, 0.2], 'zoom' => 1.5, 'wrap' => 'box', 'wrapGap' => 8];
$exists = fn($src) => ls_media_path($uidA, substr($src, 6)) !== null;
$rv = ls_doc_save($uidA, $d4['id'], $rv, json_encode($img, JSON_UNESCAPED_UNICODE), $exists);
check('מסגרת תמונה עם חיתוך וגלישה — נשמרת', $rv === 5);
$tryI = function (callable $mut, string $code) use ($uidA, $d4, $img, $exists, &$rv) {
    $x = $img; $mut($x);
    return throws(fn() => ls_doc_save($uidA, $d4['id'], $rv, json_encode($x), $exists), 'bad_model:' . $code);
};
check('תמונה שלא הועלתה — נדחה', $tryI(function (&$x) { $x['surfaces'][2]['layers'][0]['src'] = 'media/' . str_repeat('a', 64) . '.png'; }, 'image_missing'));
check('src עם https — נדחה', $tryI(function (&$x) { $x['surfaces'][2]['layers'][0]['src'] = 'https://x.y/a.png'; }, 'image'));
check('התאמה לא מוכרת — נדחה', $tryI(function (&$x) { $x['surfaces'][2]['layers'][0]['fit'] = 'stretch'; }, 'image'));
check('מיקוד מחוץ ל-0..1 — נדחה', $tryI(function (&$x) { $x['surfaces'][2]['layers'][0]['focus'] = [2, 0]; }, 'image'));
check('גלישה לא מוכרת — נדחה', $tryI(function (&$x) { $x['surfaces'][2]['layers'][0]['wrap'] = 'around'; }, 'frame'));
check('מסגרת תמונה בשרשור — נדחה', $tryI(function (&$x) { $x['flows']['f1']['frames'][] = 'i1'; }, 'thread'));

echo "\n" . ($n - $fails) . " / $n עברו\n";
exit($fails ? 1 : 0);
