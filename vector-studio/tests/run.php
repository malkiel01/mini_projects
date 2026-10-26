<?php
/**
 * בדיקות השרת: php vector-studio/tests/run.php
 * רצות על מסד זמני; הזהות של השער מדומה ב-$_SERVER['PLATFORM_USER'].
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('DB_FILE', sys_get_temp_dir() . '/vector-studio-test-' . getmypid() . '.sqlite');
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/drawings.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok): void {
    global $fails, $n;
    $n++;
    if (!$ok) { $fails++; echo "✗ $name\n"; } else echo "✓ $name\n";
}
function as_platform(?array $id): void {
    if ($id === null) unset($_SERVER['PLATFORM_USER']);
    else $_SERVER['PLATFORM_USER'] = json_encode($id);
}
function throws(callable $fn, int $status): bool {
    try { $fn(); return false; } catch (AppError $e) { return $e->status === $status; }
}

try {
    as_platform(null);
    check('בלי זהות מהשער — אין משתמש', currentUser() === null);
    check('בלי זהות — requireUser זורק 401', throws('requireUser', 401));

    as_platform(['sub' => '7', 'name' => 'דנה', 'email' => 'Dana@Example.com']);
    $a = currentUser();
    check('משתמש ראשון נפתח כמנהל', $a['role'] === 'admin' && $a['platform_sub'] === '7');
    check('המייל נשמר באותיות קטנות', $a['email'] === 'dana@example.com');
    check('כניסה חוזרת — אותו חשבון', (int) currentUser()['id'] === (int) $a['id']);

    as_platform(['sub' => '8', 'name' => 'יוסי', 'email' => 'yossi@example.com']);
    $b = currentUser();
    check('משתמש שני — עורך', $b['role'] === 'editor');
    check('החלפת משתמש באותו דפדפן מחליפה סשן', $_SESSION['uid'] === (int) $b['id']);

    // חשבון קיים במייל, בלי קישור — מתקשר ל-sub החדש.
    db()->prepare("INSERT INTO users (email, name, created_at) VALUES ('old@example.com', 'ותיק', ?)")->execute([nowIso()]);
    as_platform(['sub' => '9', 'email' => 'OLD@example.com']);
    $c = currentUser();
    check('איחוד לפי מייל', $c['name'] === 'ותיק' && $c['platform_sub'] === '9');

    // מייל שכבר מקושר ל-sub אחר — חסום.
    as_platform(['sub' => '10', 'email' => 'dana@example.com']);
    check('מייל מקושר ל-sub אחר — 403', throws('currentUser', 403));

    as_platform(['sub' => '11', 'name' => '']);
    $d = currentUser();
    check('בלי מייל — כתובת פנימית', str_ends_with($d['email'], '@platform.invalid') && $d['name'] === 'משתמש');

    db()->prepare('UPDATE users SET blocked = 1 WHERE id = ?')->execute([$b['id']]);
    as_platform(['sub' => '8']);
    check('חשבון חסום — 403', throws('currentUser', 403));

    as_platform(['sub' => '']);
    check('sub ריק — אין משתמש', currentUser() === null);
    $_SERVER['PLATFORM_USER'] = 'not json';
    check('זהות פגומה — אין משתמש', currentUser() === null);

    check('publicUser לא חושף sub', !array_key_exists('platform_sub', publicUser($a)));

    // ---- הספרייה ----
    $doc = '{"units":"pt","surfaces":[{"id":"s1","type":"artboard","size":[100,100],"layers":[]}],"x":{}}';
    $png = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 20));
    $c = createDrawing($a, ['name' => '  לוגו ', 'doc' => $doc, 'thumb' => $png]);
    check('יצירה — rev 1', $c['rev'] === 1);
    $list = listDrawings($a);
    check('רשימה — של המשתמש, עם שם מנוקה ותמונה', count($list) === 1 && $list[0]['name'] === 'לוגו' && $list[0]['thumb'] === true);
    check('המסמך חוזר בית-לבית ({} נשאר {})', getDrawing($a, $c['id'])['doc'] === $doc);
    check('משתמש אחר לא רואה — 404', throws(fn () => getDrawing(getUser((int) $d["id"]), $c["id"]), 404));
    check('רשימה של משתמש אחר ריקה', listDrawings(getUser((int) $d['id'])) === []);

    $doc2 = str_replace('[100,100]', '[200,100]', $doc);
    $sv = saveDrawing($a, $c['id'], ['rev' => 1, 'doc' => $doc2]);
    check('שמירה — rev עולה', $sv['rev'] === 2 && getDrawing($a, $c['id'])['doc'] === $doc2);
    check('גרסה נשמרה לפני השינוי', count(listVersions($a, $c['id'])) === 1 && listVersions($a, $c['id'])[0]['rev'] === 1);
    saveDrawing($a, $c['id'], ['rev' => 2, 'doc' => str_replace('[200,100]', '[300,100]', $doc)]);
    check('שמירה צמודה — בלי גרסה נוספת (פחות מ-10 דקות)', count(listVersions($a, $c['id'])) === 1);
    check('rev ישן — 409, לא דורס', throws(fn () => saveDrawing($a, $c['id'], ['rev' => 1, 'doc' => $doc]), 409));
    check('מסמך פגום — נדחה', throws(fn () => saveDrawing($a, $c['id'], ['rev' => 3, 'doc' => '{"surfaces":[]}']), 400));
    check('JSON לא תקין — נדחה', throws(fn () => createDrawing($a, ['name' => 'x', 'doc' => '{nope']), 400));
    check('תמונה שאינה PNG — נדחית', throws(fn () => createDrawing($a, ['name' => 'x', 'doc' => $doc, 'thumb' => 'data:image/png;base64,' . base64_encode('GIF89a')]), 400));
    check('שם ריק — נדחה', throws(fn () => createDrawing($a, ['name' => ' ', 'doc' => $doc]), 400));
    check('שינוי שם בלי מסמך', saveDrawing($a, $c['id'], ['rev' => 3, 'name' => 'לוגו חדש'])['rev'] === 4 && getDrawing($a, $c['id'])['name'] === 'לוגו חדש');

    $v = listVersions($a, $c['id'])[0];
    $r = restoreVersion($a, $c['id'], $v['id'], 4);
    check('שחזור גרסה — המסמך הישן חוזר, והנוכחי נשמר בהיסטוריה', $r['doc'] === $doc && $r['rev'] === 5 && count(listVersions($a, $c['id'])) === 2);
    check('תמונה ממוזערת נשמרה', str_starts_with((string) drawingThumb($a, $c['id']), "\x89PNG"));
    // ‎.mbe מקורי: נבדק בליבה; פגום — נדחה
    check('container פגום — נדחה', throws(fn () => createDrawing($a, ['name' => 'x', 'doc' => $doc, 'container' => base64_encode('PK not a zip')]), 400));
    require_once __DIR__ . '/../lib/mbe/mbe.php';
    $mf = Mbe::create('vector', json_decode($doc, true));
    $mf->setRendition('renditions/preview.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $mbe = Mbe::save($mf, ['app' => 'vector-studio']);
    $cc = createDrawing($a, ['name' => 'מקובץ', 'doc' => $doc, 'container' => base64_encode($mbe)]);
    check('container תקין — נשמר וחוזר', drawingContainer($a, $cc['id']) === $mbe && getDrawing($a, $cc['id'])['container'] === true);
    check('בלי container — false', getDrawing($a, $c['id'])['container'] === false);
    // תמונות בציור
    $pngB = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $m1 = putMedia($a, base64_encode($pngB));
    check('תמונה — src לפי sha256', $m1['src'] === 'media/' . hash('sha256', $pngB) . '.png');
    check('אותה תמונה פעמיים — אותו src, שורה אחת', putMedia($a, base64_encode($pngB))['src'] === $m1['src'] && (int) db()->query('SELECT COUNT(*) FROM media')->fetchColumn() === 1);
    check('קריאה חזרה — הבתים והסוג', getMedia($a, $m1['src']) === [$pngB, 'image/png']);
    check('משתמש אחר — לא רואה', getMedia(getUser((int) $d['id']), $m1['src']) === null);
    check('src עם נתיב זר — null', getMedia($a, '../data/x.png') === null);
    check('לא תמונה — נדחה', throws(fn () => putMedia($a, base64_encode('<svg/>')), 400));
    check('JPEG ו-WebP — מזוהים', imageExt("\xFF\xD8\xFF\xE0xx") === 'jpg' && imageExt('RIFF0000WEBPVP8 ') === 'webp');
    // צבעים וסגנונות שמורים
    check('ברירת מחדל — ריק', getPrefs($a) === ['swatches' => [], 'styles' => []]);
    $pp = putPrefs($a, ['swatches' => ['#FF0000', '#ff0000', 'red', '#00ff00'], 'styles' => [['name' => ' כפתור ', 'fill' => ['color' => '#2d4a8a'], 'stroke' => null], ['name' => '']]]);
    check('צבעים — מנורמלים, בלי כפולים ובלי לא-תקינים', $pp['swatches'] === ['#ff0000', '#00ff00']);
    check('סגנון — שם מנוקה, בלי שם — נזרק', count($pp['styles']) === 1 && $pp['styles'][0]['name'] === 'כפתור');
    check('נשמר וחוזר', getPrefs($a) === $pp && getPrefs(getUser((int) $d['id']))['swatches'] === []);
    check('יותר מדי צבעים — נדחה', throws(fn () => putPrefs($a, ['swatches' => array_fill(0, 65, '#000000')]), 400));
    deleteDrawing($a, $cc['id']);
    deleteDrawing($a, $c['id']);
    check('מחיקה — הגרסאות נמחקות איתו', listDrawings($a) === [] && (int) db()->query('SELECT COUNT(*) FROM drawing_versions')->fetchColumn() === 0);
} finally {
    foreach (glob(DB_FILE . '*') ?: [] as $f) @unlink($f);
}

echo "\n" . ($n - $fails) . " / $n עברו\n";
exit($fails ? 1 : 0);
