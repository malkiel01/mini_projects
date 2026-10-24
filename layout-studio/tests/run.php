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

echo "\n" . ($n - $fails) . " / $n עברו\n";
exit($fails ? 1 : 0);
