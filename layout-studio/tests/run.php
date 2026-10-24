<?php
// בדיקות השרת של העימוד: php layout-studio/tests/run.php
// רצות על מסד זמני — לא נוגעות ב-data/.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/layout-studio-test-' . getmypid() . '.sqlite';
@unlink($tmp);
putenv('LAYOUT_STUDIO_DB=' . $tmp);
register_shutdown_function(fn() => array_map('unlink', glob($tmp . '*') ?: []));

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/docs.php';
require_once __DIR__ . '/../lib/model.php';

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
check('מסמך חדש — 4 עמודים, כפולים, כריכה מימין', count($g['model']->surfaces) === 4 && $g['model']->layout->facing === true && $g['model']->layout->binding === 'right');
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
check('נשמר — 5 עמודים, ושדה לא מוכר נשאר', count($g2['model']->surfaces) === 5 && $g2['model']->futureField->x === 1);
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

echo "\n" . ($n - $fails) . " / $n עברו\n";
exit($fails ? 1 : 0);
