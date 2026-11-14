<?php
/**
 * R2 — "באתר" בבורר התמונות של העורך (lib/site_images.php): הרשימה, ההבאה לספרייה של הכלי, שימוש חוזר בעותק,
 * ודחייה של כל כתובת שאינה תמונה של העמוד באתר — דומיין זר, כתובת שלא ברשימה, http, מחוץ ל-uploads (SSRF).
 * "האתר" הוא tests/fake-audio.php (גם תמונות מ-R2), ממופה ל-https://maghreb.co.il דרך kol_rec_test_map (CLI בלבד).
 */

declare(strict_types=1);

require_once KOL_ROOT . '/lib/site_images.php';

$R2_DIR = $TMP . '/r2site';
mkdir($R2_DIR, 0775, true);
[$procs[], $R2_SITE] = serve(__DIR__ . '/../fake-audio.php', ['KOL_TEST_AUDIO' => $R2_DIR, 'PHP_CLI_SERVER_WORKERS' => '2']);
$GLOBALS['R2_DIR'] = $R2_DIR;
$GLOBALS['R2_SITE'] = $R2_SITE;

const R2_OWNER = ['sub' => 'u-editor', 'name' => 'עורך בדיקה', 'role' => 'editor'];
const R2_U = 'https://maghreb.co.il/wp-content/uploads/';

/** מצב לבדיקה: האתר המחובר, המיפוי לאתר המדומה, וטיוטה שתמונת הרקע של המילון בה היא $hero. מחזיר שחזור. */
function r2_fixture(string $hero): callable
{
    $pdo = db();
    $site = setting('site_url');
    setting_set('site_url', 'https://maghreb.co.il');
    $map = $GLOBALS['kol_rec_test_map'] ?? null;
    $GLOBALS['kol_rec_test_map'] = ['https://maghreb.co.il' => $GLOBALS['R2_SITE'], 'https://www.maghreb.co.il' => $GLOBALS['R2_SITE']];
    $draft = $pdo->query('SELECT * FROM draft WHERE id = 1')->fetch() ?: null;
    $json = json_encode(['settings' => ['title' => 'קול מרוקו'], 'sections' => [['columns' => [['widgets' => [['type' => 'lexicon', 'props' =>
        ['hero_url' => $hero, 'hero_file' => '']]]]]]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($draft) $pdo->prepare('UPDATE draft SET json = ? WHERE id = 1')->execute([$json]);
    else $pdo->prepare("INSERT INTO draft (id, json, rev, updated_by, updated_at) VALUES (1, ?, 1, '', ?)")->execute([$json, now_iso()]);
    @unlink($GLOBALS['R2_DIR'] . '/log.jsonl');
    return function () use ($pdo, $site, $map, $draft) {
        foreach ($pdo->query("SELECT * FROM files WHERE origin LIKE 'https://maghreb.co.il/wp-content/uploads/img%'")->fetchAll() as $f) {
            @unlink(file_path($f));
            $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$f['id']]);
        }
        if ($draft) $pdo->prepare('UPDATE draft SET json = ? WHERE id = 1')->execute([$draft['json']]); else $pdo->exec('DELETE FROM draft WHERE id = 1');
        $site === null ? $pdo->exec("DELETE FROM settings WHERE k = 'site_url'") : setting_set('site_url', $site);
        if ($map === null) unset($GLOBALS['kol_rec_test_map']); else $GLOBALS['kol_rec_test_map'] = $map;
    };
}

/** בקשות שהאתר המדומה קיבל. */
function r2_hits(): array
{
    $f = $GLOBALS['R2_DIR'] . '/log.jsonl';
    return is_file($f) ? array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)file_get_contents($f)))) : [];
}

test('R2: בורר התמונות — "באתר" ← עותק בספרייה של הכלי (תמונה, origin), ובחירה שנייה משתמשת בו', function () {
    $hero = R2_U . 'img/1.png';
    $done = r2_fixture($hero);
    try {
        $p = site_images_pick()['images'];
        eq(1, count($p), 'תמונה אחת באתר');
        eq($hero, $p[0]['url']);
        ok(!isset($p[0]['file']), 'עוד אין עותק בכלי');
        eq([], r2_hits(), 'הרשימה אינה פונה לאתר');
        $a = site_image_take(R2_OWNER, ['url' => $hero]);
        eq(false, $a['reused']);
        eq('image', $a['file']['kind']);
        eq('1.png', preg_replace('/\.webp$/', '.png', $a['file']['name']), 'השם — מהכתובת');
        $row = file_row($a['file']['id']);
        eq($hero, $row['origin'], 'המקור נשמר');
        ok(is_file(file_path($row)), 'הקובץ בספרייה');
        $hits = r2_hits();
        eq(1, count($hits), 'בקשה אחת לאתר');
        eq('GET', $hits[0]['method']);
        ok(!$hits[0]['cookie'] && !$hits[0]['auth'], 'בלי עוגייה ובלי הרשאה');
        eq($a['file']['id'], site_images_pick()['images'][0]['file']['id'] ?? null, 'הבורר מראה את העותק');
        $b = site_image_take(R2_OWNER, ['url' => $hero]);
        eq(true, $b['reused']);
        eq($a['file']['id'], $b['file']['id'], 'אותו עותק');
        eq(1, count(r2_hits()), 'לא הורדה שנייה');
    } finally { $done(); }
});

test('R2: בורר התמונות — דחיית דומיין זר, כתובת שלא ברשימה, http, IP, מחוץ ל-uploads — בלי שום בקשה', function () {
    $hero = R2_U . 'img/1.png';
    $done = r2_fixture($hero);
    try {
        $n0 = (int)db()->query('SELECT COUNT(*) FROM files')->fetchColumn();
        foreach (['https://evil.example/wp-content/uploads/img/1.png' => 400, 'https://maghreb.co.il.evil.example/wp-content/uploads/img/1.png' => 400,
                  'http://maghreb.co.il/wp-content/uploads/img/1.png' => 400, 'https://127.0.0.1/wp-content/uploads/img/1.png' => 400,
                  'https://maghreb.co.il/wp-admin/img/1.png' => 400, 'https://maghreb.co.il./wp-content/uploads/img/1.png' => 400,
                  R2_U . 'img/2.png' => 404, '' => 400] as $url => $code) {
            eq($code, throws(fn() => site_image_take(R2_OWNER, ['url' => $url]), $url), $url);
        }
        eq(400, throws(fn() => site_image_take(R2_OWNER, ['url' => ['x']])), 'לא מחרוזת');
        eq([], r2_hits(), 'אף בקשה לא יצאה');
        eq($n0, (int)db()->query('SELECT COUNT(*) FROM files')->fetchColumn(), 'לא נוסף קובץ');
    } finally { $done(); }
});

test('R2: בורר התמונות — מהאתר הגיע דף HTML או טקסט במקום תמונה: נדחה, ולא נשאר קובץ', function () {
    foreach (['img-html/3.png', 'img-text/4.png'] as $p) {
        $done = r2_fixture(R2_U . $p);
        try {
            $n0 = (int)db()->query('SELECT COUNT(*) FROM files')->fetchColumn();
            eq(415, throws(fn() => site_image_take(R2_OWNER, ['url' => R2_U . $p])), $p);
            eq($n0, (int)db()->query('SELECT COUNT(*) FROM files')->fetchColumn(), "$p: לא נוסף קובץ");
            eq([], glob(files_dir() . '/tmp/img-*') ?: [], "$p: לא נשאר קובץ זמני");
        } finally { $done(); }
    }
});

test('R2: בורר התמונות — ההרשאות ב-API: עורך תוכן — 403; עורך עם דומיין זר — 400', function () {
    eq('editor', ACTION_ROLES['site_image_take'] ?? null);
    eq('content_editor', ACTION_ROLES['site_images'] ?? null);
    eq(403, http('POST', '/api.php?action=site_image_take', ['user' => U_CONTENT, 'json' => ['url' => R2_U . 'img/1.png']])['status']);
    $r = http('POST', '/api.php?action=site_image_take', ['user' => U_EDITOR, 'json' => ['url' => 'https://evil.example/wp-content/uploads/img/1.png']]);
    eq(400, $r['status']);
    ok(str_contains((string)($r['json']['error'] ?? ''), 'דומיין אחר'), 'הסיבה במילים');
    eq(200, http('GET', '/api.php?action=site_images', ['user' => U_CONTENT])['status']);
});

test('R2: קובץ מקור — תמיד אפשר להחזיר, ואין מחיקה שלו (לסל או סופית) חוץ מבעל האתר; נאכף בשרת', function () {
    require_once KOL_ROOT . '/lib/restore.php';
    $hero = R2_U . 'img/5.png';
    $done = r2_fixture($hero);
    try {
        $fid = site_image_take(R2_OWNER, ['url' => $hero])['file']['id'];
        $pub = file_public(file_row($fid));
        ok(!empty($pub['source']) && $pub['origin'] === $hero, 'מסומן "מקור" עם הכתובת');
        foreach (['content_editor', 'editor', 'publisher', 'page_owner'] as $role) {
            eq(403, throws(fn() => file_delete(['sub' => "u-$role", 'name' => $role, 'role' => $role], ['id' => $fid])), "$role: לסל");
        }
        ok(file_row($fid)['deleted_at'] === null, 'לא הועבר לסל');
        // גם אם הגיע לסל (בעל האתר) — מחיקה סופית רק לבעל האתר
        $site = ['sub' => 'u-owner', 'name' => 'בעל האתר', 'role' => 'site_owner'];
        file_delete($site, ['id' => $fid]);
        ok(file_row($fid)['deleted_at'] !== null, 'בעל האתר — לסל');
        eq(403, throws(fn() => trash_purge(['sub' => 'u-page', 'name' => 'page', 'role' => 'page_owner'], ['kind' => 'file', 'id' => $fid, 'confirm' => true])), 'page_owner: סופית');
        ok(is_file(file_path(file_row($fid))), 'הקובץ עדיין בשרת');
        // אחרי שבעל האתר מחק — "מקור" באתר עדיין מחזיר (עותק חדש)
        $again = site_image_take(R2_OWNER, ['url' => $hero]);
        ok(!$again['reused'] && $again['file']['id'] !== $fid, 'אחרי מחיקה — אפשר להחזיר שוב מהאתר');
        trash_purge($site, ['kind' => 'file', 'id' => $fid, 'confirm' => true]);
        eq(0, (int)db()->query("SELECT COUNT(*) FROM files WHERE id = '$fid'")->fetchColumn(), 'בעל האתר — סופית');
        // קובץ רגיל (בלי מקור) — כמו קודם
        ok(!file_is_source(['origin' => '', 'kind' => 'image']) && !file_is_source(['origin' => $hero, 'kind' => 'audio']), 'רק תמונה עם מקור');
    } finally { $done(); }
});
