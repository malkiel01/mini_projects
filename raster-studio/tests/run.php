<?php
// בדיקות השרת של עיצוב רסטר: php raster-studio/tests/run.php
// רצות על מסד זמני — לא נוגעות ב-data/.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/raster-studio-test-' . getmypid() . '.sqlite';
@unlink($tmp);
putenv('RASTER_STUDIO_DB=' . $tmp);
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
check('בלי זהות מהשער — אין משתמש', rs_account() === null);
$_SERVER['PLATFORM_USER'] = '{"name":"x"}';
check('זהות בלי sub — אין משתמש', rs_account() === null);
$_SERVER['PLATFORM_USER'] = 'not json';
check('זהות פגומה — אין משתמש', rs_account() === null);

as_user(['sub' => '17', 'name' => 'דנה', 'email' => 'Dana@Example.com']);
$a = rs_account();
check('משתמש ראשון נפתח מיד', $a !== null && $a['sub'] === '17');
check('המשתמש הראשון — מנהל', $a['role'] === 'admin');
check('המייל נשמר באותיות קטנות', $a['email'] === 'dana@example.com');
check('כניסה שנייה — אותו חשבון', rs_account()['id'] === $a['id']);

as_user(['sub' => '18', 'name' => 'יוסי', 'email' => 'yosi@example.com']);
$b = rs_account();
check('משתמש שני — חשבון חדש, לא מנהל', $b['id'] !== $a['id'] && $b['role'] === 'user');

as_user(['sub' => '17', 'name' => 'דנה לוי', 'email' => 'dana@example.com']);
check('שם מתעדכן מהפלטפורמה', rs_account()['name'] === 'דנה לוי');

// חשבון ישן בלי sub (למשל מייבוא) — מתאחד לפי מייל
rs_db()->prepare('INSERT INTO accounts (sub, email, name, role, created_at) VALUES (NULL, ?, ?, ?, ?)')
    ->execute(['old@example.com', 'ישן', 'user', rs_now()]);
$oldId = (int)rs_db()->lastInsertId();
as_user(['sub' => '30', 'name' => 'ישן', 'email' => 'OLD@example.com']);
$c = rs_account();
check('איחוד לפי מייל — אותו חשבון, מקושר ל-sub', (int)$c['id'] === $oldId && $c['sub'] === '30');

// ---- מסמכים ----
$uidA = (int)$a['id'];
$uidB = (int)$b['id'];
check('רשימה ריקה', rs_docs_list($uidA) === []);
$d = rs_doc_create($uidA, '  כרזה   לחג ', 'screen');
check('יצירה — השם מנוקה', $d['title'] === 'כרזה לחג');
check('מסך — 1920×1080', $d['size'] === [1920, 1080]);
check('A4 ב-150dpi', rs_doc_create($uidA, 'דף', 'a4')['size'] === [1240, 1754]);
$c2 = rs_doc_create($uidA, 'מותאם', 'custom', ['800', 600]);
check('גודל מותאם בפיקסלים', $c2['size'] === [800, 600]);
check('שם ריק — נדחה', throws(fn() => rs_doc_create($uidA, '   ', 'screen'), 'title_required'));
check('שם ארוך — נדחה', throws(fn() => rs_doc_create($uidA, str_repeat('א', 121), 'screen'), 'title_too_long'));
check('גודל לא מוכר — נדחה', throws(fn() => rs_doc_create($uidA, 'x', 'huge'), 'bad_size'));
check('אפס — נדחה', throws(fn() => rs_doc_create($uidA, 'x', 'custom', [0, 200]), 'bad_size'));
check('מעל 30000 — נדחה', throws(fn() => rs_doc_create($uidA, 'x', 'custom', [30001, 200]), 'bad_size'));
check('שבר — נדחה, לא מעוגל', throws(fn() => rs_doc_create($uidA, 'x', 'custom', ['12.5', 200]), 'bad_size'));
check('שלילי — נדחה', throws(fn() => rs_doc_create($uidA, 'x', 'custom', ['-5', 200]), 'bad_size'));
check('לא מספר — נדחה', throws(fn() => rs_doc_create($uidA, 'x', 'custom', ['abc', 200]), 'bad_size'));
check('רשימה — שלוש תמונות', count(rs_docs_list($uidA)) === 3);
check('משתמש אחר לא רואה', rs_docs_list($uidB) === []);
check('קריאה — הבעלים', rs_doc_get($uidA, $d['id'])['size'] === [1920, 1080]);
check('קריאה — משתמש אחר לא רואה', rs_doc_get($uidB, $d['id']) === null);
check('משתמש אחר לא מוחק', rs_doc_delete($uidB, $d['id']) === false);
check('הבעלים מוחק', rs_doc_delete($uidA, $d['id']) === true);
check('מחיקה שנייה — לא נמצא', rs_doc_delete($uidA, $d['id']) === false);
check('נמחק — לא ברשימה', count(rs_docs_list($uidA)) === 2);
check('נמחק — לא נקרא', rs_doc_get($uidA, $d['id']) === null);

echo "\n" . ($n - $fails) . " / $n עברו\n";
exit($fails ? 1 : 0);
