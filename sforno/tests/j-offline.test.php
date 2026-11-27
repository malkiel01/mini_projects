<?php
/**
 * הורדות, גרסאות ו-PWA — docs/spec/06-downloads-versions.md §5 ("K<מספר> …").
 *
 *   השרת: read-package (רק תוכן שפורסם, hash לכל קובץ), גרסה קודמת נשארת זמינה (K3), גרסה לא נמחקת (K7), תמונה של גרסה
 *   קודמת. הלוגיקה במכשיר (K1 K2 K4 K5, ו-K6 — ה-service worker לא מוחק הורדות) — ב-node, tests/offline-core.mjs, על
 *   fixture שנבנה כאן מהשרת האמיתי (ה-hash של המכשיר נבדק מול זה של השרת).
 *   המעטפת: sw.js — VERSION = app.json, BUILD = ה-hash של קובצי המעטפת, וכל מה ש-index.html טוען נמצא ב-SHELL.
 *   עדכון BUILD אחרי שינוי בקובץ מעטפת: SF_UPDATE_SW=1 php sforno/tests/run.php
 *   👁 (K1 במצב טיסה, K6, K8, K9) — אצל הבעלים; tests/browser/offline.cjs בודק בכרומיום אמיתי (לא רץ ב-run.php).
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$root = dirname(__DIR__);
$A = ident('601', 'מנהלת', 'a@example.test');
call('GET', 'me', [], $A);
$ok = function (array $r, string $name): array {
    eq($r[0], 200, $name . ($r[0] !== 200 ? ' — ' . json_encode($r[1]['error'] ?? $GLOBALS['t_last_500'] ?? null, JSON_UNESCAPED_UNICODE) : ''));
    return $r[1];
};
$app = json_decode((string)file_get_contents("$root/app.json"), true);

// ── הרשאות ───────────────────────────────────────────────────
eq(SF_ACTIONS['read-package'] ?? null, ['m' => 'GET', 'who' => 'reader'], 'read-package — reader, GET (בלי עוגייה, בלי מידע אישי)');
check('read-package לא ב-"public" של app.json עד ההשקה (11)', !str_contains(implode(' ', $app['public']), 'read-package'));
eq(code(call('POST', 'read-package', [], null, ['edition' => 'abcdefghijklmnop'])), 'method', 'read-package ב-POST — 405');

// ── תוכן: שתי גרסאות. 1: שלוש שורות, באמצעית תמונה. 2: בלי השורה האמצעית ──
$dir = sf_imp_dir('k6k6k6k6k6k6k6k6') . '/files';
sf_imp_mkdir($dir);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
file_put_contents("$dir/1.png", $png);
$t = $ok(call('POST', 'content-title-save', ['name' => 'סידור הורדות', 'kind' => 'siddur'], $A), 'כותר')['title']['pid'];
$ok(call('POST', 'content-title-public', ['title' => $t, 'on' => true], $A), 'ציבורי');
$E = $ok(call('POST', 'content-edition-save', ['title' => $t, 'label' => 'מהדורה א'], $A), 'מהדורה')['edition']['pid'];
$eid = (int)sf_val('SELECT id FROM editions WHERE pid = ?', [$E]);
$draftV = fn() => (int)sf_val("SELECT draft_v FROM revisions WHERE edition_id = ? AND status = 'draft'", [$eid]);
$ok(call('POST', 'content-save', ['edition' => $E, 'html' => '<p>ברוך אתה שורה ראשונה</p><p>שורה שנייה עם תמונה<img src="files/k6k6k6k6k6k6k6k6/1.png" alt=""></p><p>שורה שלישית</p>', 'base' => 0], $A), 'טיוטה 1');
eq(code(call('GET', 'read-package', [], null, ['edition' => $E])), 'not_found', 'K מהדורה בלי גרסה שפורסמה — אין חבילה');
$ok(call('POST', 'content-publish', ['edition' => $E, 'base' => $draftV()], $A), 'פרסום 1');
$r1 = $ok(call('GET', 'read-revision', [], null, ['edition' => $E]), 'גרסה 1');
preg_match_all('/\ssid="([a-z0-9]{8})"/', $r1['html'], $m);
[$sidA, $sidB, $sidC] = $m[1] + [null, null, null];
check('לכל שורה sid', $sidA && $sidB && $sidC);

$pkg = $ok(call('GET', 'read-package', [], null, ['edition' => $E]), 'read-package');
eq($pkg['revision']['pid'], $r1['revision']['pid'], 'K החבילה — של הגרסה שפורסמה');
eq($pkg['text']['sha256'], hash('sha256', $r1['html']), 'K ה-hash של הטקסט = של ה-source_html');
eq(array_column($pkg['files'], 'url'), ['api.php?action=read-file&path=files/k6k6k6k6k6k6k6k6/1.png'], 'K החבילה: התמונה שבטקסט');
eq($pkg['files'][0]['sha256'] ?? null, hash('sha256', $png), 'K ה-hash של התמונה');
eq($pkg['size'], strlen($r1['html']) + strlen($png), 'K הגודל = הטקסט + הקבצים');
check('K החבילה לא כוללת שדות ניהול או טיוטה', !preg_match('/draft|staff|import_id/', json_encode($pkg)));
$pkgOld = $pkg;

// גרסה 2 — בלי השורה האמצעית (והתמונה)
$html2 = preg_replace('~<p[^>]*\ssid="' . $sidB . '"[^>]*>.*?</p>~su', '', (string)sf_val('SELECT source_html FROM revisions WHERE pid = ?', [$r1['revision']['pid']]));
$ok(call('POST', 'content-save', ['edition' => $E, 'html' => $html2, 'base' => 0, 'from' => 1], $A), 'טיוטה 2');
$ok(call('POST', 'content-publish', ['edition' => $E, 'base' => $draftV()], $A), 'פרסום 2');
$r2 = $ok(call('GET', 'read-revision', [], null, ['edition' => $E]), 'גרסה 2');
check('גרסה 2: השורה האמצעית נמחקה, האחרות נשארו באותו sid', !str_contains($r2['html'], $sidB) && str_contains($r2['html'], $sidA) && str_contains($r2['html'], $sidC));

// ── K3: פרסום חדש — הקורא ממשיך בישנה (גם מקוון) ────────────────
$old = $ok(call('GET', 'read-revision', [], null, ['edition' => $E, 'revision' => $r1['revision']['pid']]), 'K3 הגרסה הישנה לפי מזהה');
check('K3 הגרסה שנבחרה מוגשת כמו שהיא אחרי פרסום חדש, ומסומנת "לא האחרונה"', $old['html'] === $r1['html'] && $old['revision']['current'] === false);
$cat = $ok(call('GET', 'read-catalog'), 'קטלוג');
$ced = null;
foreach ($cat['titles'] as $ti) foreach ($ti['editions'] as $ed) if ($ed['pid'] === $E) $ced = $ed;
eq($ced['revision']['pid'] ?? null, $r2['revision']['pid'], 'K3 הקטלוג — הגרסה הנוכחית (כדי להציע "לעבור?")');
$pk1 = $ok(call('GET', 'read-package', [], null, ['edition' => $E, 'revision' => $r1['revision']['pid']]), 'K3 הורדה של הגרסה הישנה');
eq([$pk1['revision']['pid'], $pk1['text']['sha256'], count($pk1['files'])], [$r1['revision']['pid'], hash('sha256', $r1['html']), 1], 'K3 החבילה של הגרסה שנבחרה — לא של החדשה');
check('K3 תמונה של גרסה קודמת — עדיין מוגשת לקורא שנשאר בה', is_array(sf_read_file_path(['path' => 'files/k6k6k6k6k6k6k6k6/1.png'])));
$pk2 = $ok(call('GET', 'read-package', [], null, ['edition' => $E]), 'חבילה של הנוכחית');
eq([$pk2['revision']['pid'], $pk2['files']], [$r2['revision']['pid'], []], 'החבילה של הנוכחית — בלי התמונה שנמחקה');
eq(code(call('GET', 'read-package', [], null, ['edition' => $E, 'revision' => 'zzzzzzzzzzzzzzzz'])), 'not_found', 'גרסה לא קיימת — 404');
// טיוטה — לעולם לא
$ok(call('POST', 'content-save', ['edition' => $E, 'html' => '<p>טיוטה-סודית</p>', 'base' => 0, 'from' => 2], $A), 'טיוטה 3');
$dPid = (string)sf_val("SELECT pid FROM revisions WHERE edition_id = ? AND status = 'draft'", [$eid]);
eq(code(call('GET', 'read-package', [], null, ['edition' => $E, 'revision' => $dPid])), 'not_found', 'K טיוטה לפי מזהה — אין חבילה');
// כותר שאינו ציבורי — כאילו לא קיים, גם לגרסה ישנה
$ok(call('POST', 'content-title-public', ['title' => $t, 'on' => false], $A), 'כיבוי ציבורי');
eq(code(call('GET', 'read-package', [], null, ['edition' => $E, 'revision' => $r1['revision']['pid']])), 'not_found', 'K כותר לא ציבורי — אין חבילה');
check('K כותר לא ציבורי — התמונה של הגרסה הקודמת 404', (function () { try { sf_read_file_path(['path' => 'files/k6k6k6k6k6k6k6k6/1.png']); return false; } catch (SfError) { return true; } })());
$ok(call('POST', 'content-title-public', ['title' => $t, 'on' => true], $A), 'הדלקה');

// ── K7: גרסה עם הערות של משתמש לא נמחקת מהשרת ─────────────────
$rid1 = (int)sf_val('SELECT id FROM revisions WHERE pid = ?', [$r1['revision']['pid']]);
$reader = sf_insert('readers', ['pid' => sf_pid(), 'email' => 'k7@example.test', 'name' => 'k7', 'status' => 'active', 'created_at' => sf_now(), 'updated_at' => sf_now()]);
sf_insert('personal_items', ['reader_id' => $reader, 'pid' => 'm:k7note', 'kind' => 'note', 'ref' => $E, 'data' => sf_seal(json_encode(['edition' => $E, 'rev' => $r1['revision']['pid'], 'sid' => $sidB, 'note' => 'הערה'])),
    'rev' => 1, 'created_at' => sf_now(), 'updated_at' => sf_now()]);
check('K7 גרסה עם הערה — DELETE נחסם במסד (טריגר)', (function () use ($rid1) { try { sf_exec('DELETE FROM revisions WHERE id = ?', [$rid1]); return false; } catch (Throwable) { return true; } })());
eq((int)sf_val('SELECT COUNT(*) FROM revisions WHERE id = ?', [$rid1]), 1, 'K7 הגרסה עדיין בשרת');
$libs = implode("\n", array_map('file_get_contents', glob("$root/lib/*.php")));
check('K7 אין בקוד מחיקה של גרסאות', !preg_match('/DELETE\s+FROM\s+revisions/i', $libs));

// ── fixture ל-node: החבילה של גרסה 1 והקבצים כפי שהשרת מגיש ─────
$files = [];
$files['api.php?action=read-revision&edition=' . $E . '&revision=' . $pkgOld['revision']['pid']] =
    base64_encode((string)json_encode(['ok' => true] + $old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$files[$pkgOld['files'][0]['url']] = base64_encode($png);
preg_match_all('/\ssid="([a-z0-9]{8})"/', $r2['html'], $m2);
$fx = ['pkg' => $pkgOld, 'files' => $files, 'html' => $r1['html'],
    'revs' => ['old' => $r1['revision']['pid'], 'new' => $r2['revision']['pid'], 'oldHtml' => $r1['html'], 'newHtml' => $r2['html'], 'newSids' => $m2[1],
        'keptSid' => $sidA, 'goneSid' => $sidB]];
file_put_contents(SF_DATA_DIR . '/offline-fx.json', json_encode($fx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exec('command -v node 2>/dev/null', $_n, $nodeCode);
check('node מותקן (בדיקות ההורדות במכשיר)', $nodeCode === 0);
if ($nodeCode === 0) {
    exec('node ' . escapeshellarg(__DIR__ . '/offline-core.mjs') . ' ' . escapeshellarg(SF_DATA_DIR . '/offline-fx.json') . ' 2>&1', $out, $code);
    eq($code, 0, 'offline-core.mjs רץ' . ($code ? ': ' . implode(' | ', array_slice($out, -5)) : ''));
    $js = json_decode(implode("\n", $out), true);
    if (is_array($js)) {
        foreach ($js['fails'] as $f) check($f, false);
        check('offline-core: ' . $js['n'] . ' בדיקות', $js['n'] >= 35 && !$js['fails']);
    }
}

// ── המעטפת: sw.js, manifest, index.html ───────────────────────
$sw = (string)file_get_contents("$root/sw.js");
preg_match("/const SHELL = \[(.*?)\];/s", $sw, $sm);
preg_match_all("/'([^']+)'/", $sm[1] ?? '', $sl);
$shell = $sl[1];
foreach ($shell as $p) if ($p !== './') check("SHELL: $p קיים", is_file("$root/$p"));
$build = function () use ($shell, $root): string {
    $h = hash_init('sha256');
    foreach ($shell as $p) if ($p !== './' && is_file("$root/$p")) hash_update($h, $p . "\0" . file_get_contents("$root/$p") . "\0");
    return substr(hash_final($h), 0, 12);
};
if (getenv('SF_UPDATE_SW') === '1') {
    $sw = (string)preg_replace("/const VERSION = '[^']*';/", "const VERSION = '{$app['version']}';", $sw);
    $sw = (string)preg_replace("/const BUILD = '[^']*';/", "const BUILD = '{$build()}';", $sw);
    file_put_contents("$root/sw.js", $sw);
    echo "  (sw.js עודכן: {$app['version']} / {$build()})\n";
}
check("K6 sw.js: VERSION = הגרסה שב-app.json ({$app['version']})", str_contains($sw, "const VERSION = '{$app['version']}';"));
check('K6 sw.js: BUILD = ה-hash של קובצי המעטפת — שינוי בקובץ בלי עדכון BUILD היה משאיר קוראים על הקוד הישן. לעדכן: SF_UPDATE_SW=1 php sforno/tests/run.php',
    str_contains($sw, "const BUILD = '{$build()}';"), $build());
$index = (string)file_get_contents("$root/index.html");
preg_match_all('/(?:src|href)="([^"#:]+)"/', $index, $im);
preg_match_all("/url\\('([^']+)'\\)/", $index, $fm);
foreach (array_merge($im[1], $fm[1]) as $p) check("index.html טוען $p — והוא במעטפת (נפתח בלי רשת)", in_array($p, $shell, true));
check('sw.js — רישום יחסי בתיקייה בלבד', str_contains((string)file_get_contents("$root/assets/js/offline.js"), "register('sw.js', { scope: './' })"));
check('sw.js — בלי skipWaiting בהתקנה (רק אחרי "לרענן")', substr_count($sw, 'skipWaiting()') === 1 && str_contains($sw, "'SKIP_WAITING'"));
check('sw.js — לא מבקש כתובת מחוץ לתיקייה', !preg_match('~\.\./|https?://~', preg_replace('~/\*.*?\*/~s', '', $sw)));

$mf = json_decode((string)file_get_contents("$root/manifest.webmanifest"), true);
check('K9 manifest: standalone, start_url/scope בתיקייה, עברית מימין לשמאל', ($mf['display'] ?? '') === 'standalone' && $mf['start_url'] === './' && $mf['scope'] === './' && $mf['lang'] === 'he' && $mf['dir'] === 'rtl');
$sizes = [];
foreach ($mf['icons'] as $ic) {
    if (($ic['type'] ?? '') !== 'image/png') continue;
    $gi = @getimagesize("$root/{$ic['src']}");
    check("K9 אייקון {$ic['src']} — {$ic['sizes']}", $gi && "{$gi[0]}x{$gi[1]}" === $ic['sizes']);
    $sizes[] = $ic['sizes'];
}
check('K9 אייקונים 192 ו-512 (Android), ו-maskable', in_array('192x192', $sizes, true) && in_array('512x512', $sizes, true) && in_array('maskable', array_column($mf['icons'], 'purpose'), true));
check('K9 iPhone: apple-touch-icon 180 ומסך מלא', ($g = @getimagesize("$root/assets/apple-touch-icon.png")) && $g[0] === 180 && str_contains($index, 'apple-mobile-web-app-capable'));
check('K9 manifest נטען עם עוגיות (השער בזמן הפיתוח)', str_contains($index, 'href="manifest.webmanifest" crossorigin="use-credentials"'));

// ── §1.2 שום דבר לא יורד מעצמו ───────────────────────────────
$js = '';
foreach (glob("$root/assets/js/{,screens/}*.js", GLOB_BRACE) as $f) $js .= file_get_contents($f);
preg_match_all('/SF\.offline\.download\(/', $js, $calls);
$ctx = [];
foreach (['assets/js/screens/downloads.js', 'assets/js/screens/reader.js'] as $f) $ctx[$f] = substr_count((string)file_get_contents("$root/$f"), 'SF.offline.download(');
eq(count($calls[0]), array_sum($ctx), 'K הורדה רק מהמסכים (בלחיצה) — downloads.js והמעבר בקורא');
check('K המעבר בקורא מוריד רק כשכבר יש הורדה (record) — לא מעצמו', (bool)preg_match('/const rec = await SF\.offline\.record\(edition\);\s*if \(rec && SF\.offline\.supported\)/', (string)file_get_contents("$root/assets/js/screens/reader.js")));

done();
