<?php
// בדיקות השרת של עיצוב רסטר: php raster-studio/tests/run.php
// רצות על מסד זמני — לא נוגעות ב-data/.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/raster-studio-test-' . getmypid() . '.sqlite';
@unlink($tmp);
putenv('RASTER_STUDIO_DB=' . $tmp);
register_shutdown_function(fn() => array_map('unlink', glob($tmp . '*') ?: []));
$files = sys_get_temp_dir() . '/raster-studio-files-' . getmypid();
putenv('RASTER_STUDIO_FILES=' . $files);
register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($files)));

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/docs.php';
require_once __DIR__ . '/../lib/store.php';

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

// ---- שמירה בשרת: ‎.mbe עם גרסאות ----
// PNG של 1×1 (לבן) — לשכבה ול-preview
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
function mbe_bytes(string $kind, array $size, string $png, string $title = 'x'): string
{
    $f = Mbe::create($kind, ['units' => 'pt', 'surfaces' => []]);
    $src = $f->addMedia($png, 'image/png');
    $f->document['surfaces'] = $kind === 'raster'
        ? [['id' => 's1', 'type' => 'canvas', 'size' => $size, 'layers' => [['id' => 'L1', 'name' => $title, 'type' => 'raster', 'src' => $src]]]]
        : [['id' => 'p1', 'type' => 'page', 'size' => $size, 'layers' => []]];
    $f->setRendition(Mbe::PREVIEW, $png);
    return Mbe::save($f, ['app' => 'raster-studio']);
}
function rejected(callable $f, string $code): bool
{
    try { $f(); } catch (RsMbeRejected $e) { return $code === 'mbe_invalid' && count($e->issues) > 0; } catch (InvalidArgumentException $e) { return $e->getMessage() === $code; }
    return false;
}
$doc = rs_doc_create($uidA, 'לשמירה', 'square');
check('מסמך חדש — גרסה 0, בלי קובץ', $doc['version'] === 0 && rs_doc_file($uidA, $doc['id']) === null);
$m1 = mbe_bytes('raster', [640, 480], $png);
$r = rs_doc_save($uidA, $doc['id'], $m1, 0);
check('שמירה ראשונה — גרסה 1, הגודל מהקנבס שבקובץ', $r['version'] === 1 && $r['doc']['version'] === 1 && $r['doc']['size'] === [640, 480]);
check('הקובץ נשמר בדיוק', file_get_contents(rs_doc_file($uidA, $doc['id'])) === $m1);
check('התמונה הממוזערת חולצה', file_get_contents(rs_doc_preview($uidA, $doc['id'])) === $png);
check('base ישן — conflict', rejected(fn() => rs_doc_save($uidA, $doc['id'], $m1, 0), 'conflict'));
check('force — נשמר בכל זאת', rs_doc_save($uidA, $doc['id'], $m1, 0, true)['version'] === 2);
check('בלי base — נשמר', rs_doc_save($uidA, $doc['id'], $m1, null)['version'] === 3);
check('גרסה ישנה עדיין נקראת', rs_doc_file($uidA, $doc['id'], 1) !== null && rs_doc_file($uidA, $doc['id'], 9) === null);
$vs = rs_doc_versions($uidA, $doc['id']);
check('רשימת הגרסאות — מהחדשה', array_column($vs, 'n') === [3, 2, 1] && $vs[0]['bytes'] === strlen($m1));
check('קובץ פגום — נדחה עם הבעיות מהליבה', rejected(fn() => rs_doc_save($uidA, $doc['id'], 'PK' . str_repeat('x', 50), null), 'mbe_invalid'));
$fd = Mbe::create('design', ['units' => 'pt', 'surfaces' => []]);
$srcD = $fd->addMedia($png, 'image/png');
$fd->document['surfaces'] = [['id' => 'gc', 'type' => 'page', 'size' => [225, 150], 'layers' => [['id' => 'R1', 'type' => 'raster', 'src' => $srcD, 'px' => [300, 200]]]]];
$fd->setRendition(Mbe::PREVIEW, $png);
$docD = rs_doc_create($uidA, 'עמוד', 'square');
check('עמוד של סטודיו העיצוב (pt, עם רסטר) — נשמר, בגודל הפיקסלים של הרסטר', rs_doc_save($uidA, $docD['id'], Mbe::save($fd, ['app' => 'greeting-cards']), null)['doc']['size'] === [300, 200]);
check('‎.mbe בלי קנבס (מסמך טקסט) — נדחה', rejected(fn() => rs_doc_save($uidA, $doc['id'], mbe_bytes('text', [595, 842], $png), null), 'not_raster'));
check('משתמש אחר — לא נמצא (לא שומר, לא קורא)', rejected(fn() => rs_doc_save($uidB, $doc['id'], $m1, null), 'not_found')
    && rs_doc_file($uidB, $doc['id']) === null && rs_doc_preview($uidB, $doc['id']) === null && rejected(fn() => rs_doc_versions($uidB, $doc['id']), 'not_found'));
check('גדול מהתקרה — נדחה', rs_mbe_max() > 0 && rejected(fn() => rs_doc_save($uidA, $doc['id'], str_repeat('x', rs_mbe_max() + 1), null), 'too_big'));
for ($i = 0; $i < RS_KEEP_VERSIONS; $i++) rs_doc_save($uidA, $doc['id'], $m1, null);
$vs = rs_doc_versions($uidA, $doc['id']);
check('עד ' . RS_KEEP_VERSIONS . ' גרסאות — הישנות נמחקות (גם הקובץ)', count($vs) === RS_KEEP_VERSIONS && $vs[0]['n'] === 3 + RS_KEEP_VERSIONS
    && !is_file(rs_doc_dir($uidA, $doc['id']) . '/1.mbe') && is_file(rs_doc_dir($uidA, $doc['id']) . '/' . (3 + RS_KEEP_VERSIONS) . '.mbe'));
check('בלי קבצים זמניים שנשארו', glob(rs_doc_dir($uidA, $doc['id']) . '/*.tmp') === []);
check('ברשימת המסמכים — מספר הגרסה', array_values(array_filter(rs_docs_list($uidA), fn($x) => $x['id'] === $doc['id']))[0]['version'] === 3 + RS_KEEP_VERSIONS);

echo "\n" . ($n - $fails) . " / $n עברו\n";
exit($fails ? 1 : 0);
