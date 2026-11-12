<?php
/**
 * פרוסה A2 — ההקלטות כרשומות, הסריקה וההבאה (lib/recordings.php). כל בדיקה מול קריטריון ב-
 * docs/spec-v2/slices/A2-audio-fetch.md §2. בלי רשת אמיתית: "האתר" הוא tests/fake-audio.php על 127.0.0.1, ממופה
 * ל-https://maghreb.co.il דרך $GLOBALS['kol_rec_test_map'] (פועל רק ב-CLI — rec_test_map).
 * בסוף — המילים, הקבצים, הרשומות וההגדרות של הבדיקה מוסרים (הבדיקות שאחריה מניחות מסד בלי מילים).
 */

declare(strict_types=1);

require_once KOL_ROOT . '/lib/library.php';

$AUD_DIR = $TMP . '/aud';
mkdir($AUD_DIR, 0775, true);
[$procs[], $AUD] = serve(__DIR__ . '/../fake-audio.php', ['KOL_TEST_AUDIO' => $AUD_DIR, 'PHP_CLI_SERVER_WORKERS' => '3']);
$GLOBALS['AUD_DIR'] = $AUD_DIR;
$GLOBALS['kol_rec_test_map'] = ['https://maghreb.co.il' => $AUD, 'https://www.maghreb.co.il' => $AUD];

const A2_OWNER = ['sub' => 'u-owner', 'name' => 'בעלים בדיקה', 'role' => 'site_owner'];
const A2_U = 'https://maghreb.co.il/wp-content/uploads/';

/** האתר המחובר בזמן הבדיקה: https://maghreb.co.il (בדיקות קודמות מחברות את וורדפרס המדומה). מחזיר שחזור. */
function a2_site(): callable
{
    $was = setting('site_url');
    setting_set('site_url', 'https://maghreb.co.il');
    return fn() => $was === null ? db()->exec("DELETE FROM settings WHERE k = 'site_url'") : setting_set('site_url', $was);
}

/** בקשות שהאתר המדומה קיבל (ומאפס את היומן). */
function a2_log(bool $reset = true): array
{
    $f = $GLOBALS['AUD_DIR'] . '/log.jsonl';
    $rows = is_file($f) ? array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)file_get_contents($f)))) : [];
    if ($reset) @unlink($f);
    return $rows;
}

/** מצב התחלתי: מילים לפי מפרט [id => audio], בלי עבודה קודמת. מחזיר פונקציה שמנקה. */
function a2_fixture(array $words): callable
{
    $pdo = db();
    $site = a2_site();
    $ins = $pdo->prepare('INSERT INTO words (id, wp_id, word, meaning, topics, audio, external_id, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($words as $id => $audio) $ins->execute([$id, $id + 50000, "מילה $id", '<p>פירוש</p>', '[]', $audio, '', now_iso()]);
    foreach (['rec_job', 'rec_conc', 'rec_delay_ms', 'rec_file_max', 'rec_step_budget', 'rec_timeout'] as $k) $pdo->prepare('DELETE FROM settings WHERE k = ?')->execute([$k]);
    setting_set('rec_delay_ms', '0');
    setting_set('rec_step_budget', '0');
    a2_log();
    return function () use ($pdo, $site) {
        $site();
        $pdo->exec('DELETE FROM words WHERE id >= 9100 AND id < 9300');
        foreach ($pdo->query("SELECT * FROM files WHERE origin LIKE 'https://maghreb.co.il/wp-content/uploads/%' OR name LIKE 'מילה 9%'")->fetchAll() as $f) {
            @unlink(file_path($f));
            $pdo->prepare('DELETE FROM pf_files WHERE file_id = ?')->execute([$f['id']]);
            $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$f['id']]);
        }
        rec_sync();
        foreach (['rec_job', 'rec_conc', 'rec_delay_ms', 'rec_file_max', 'rec_step_budget', 'rec_timeout'] as $k) $pdo->prepare('DELETE FROM settings WHERE k = ?')->execute([$k]);
    };
}

/** קובץ שמע בספרייה (כמו העלאה), למילה "בכלי". */
function a2_file(string $origin = ''): string
{
    $tmp = files_dir() . '/tmp/a2-' . bin2hex(random_bytes(4));
    @mkdir(dirname($tmp), 0775, true);
    file_put_contents($tmp, "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xff\xfb\x90\x64" . str_repeat("\x00", 413), 5));
    $id = file_store($tmp, 'מילה 9 הקלטה.mp3', A2_OWNER, 'audio/');
    if ($origin !== '') db()->prepare('UPDATE files SET origin = ? WHERE id = ?')->execute([$origin, $id]);
    return $id;
}

function a2_rec(int $wid): array
{
    $st = rec_db()->prepare('SELECT * FROM recordings WHERE word_id = ?');
    $st->execute([$wid]);
    return $st->fetch() ?: [];
}

/** סריקה מלאה (צעדים עד הסוף). */
function a2_scan(): array
{
    rec_scan_start(A2_OWNER);
    for ($i = 0; $i < 100; $i++) { $s = rec_scan_step(A2_OWNER); if ($s['job']['status'] !== 'running') return $s; }
    throw new TestFail('הסריקה לא הסתיימה');
}

function a2_fetch_all(): array
{
    $p = rec_fetch_plan(A2_OWNER)['plan'];
    rec_fetch_start(A2_OWNER, ['confirm' => true, 'token' => $p['token']]);
    for ($i = 0; $i < 100; $i++) { $s = rec_fetch_step(A2_OWNER); if ($s['job']['status'] !== 'running') return $s; }
    throw new TestFail('ההבאה לא הסתיימה');
}

test('A2 · המודל: רשומה לכל מילה עם הקלטה (כתובת / קובץ / ריק); שדה audio של המילה אינו משתנה', function () {
    $fid = a2_file();
    $clean = a2_fixture([9101 => A2_U . 'ok/1.mp3', 9102 => $fid, 9103 => '', 9104 => A2_U . 'ok/4.mp3', 9105 => 'https://maghreb.co.il/wp-content/uploads/ok/1.mp3']);
    try {
        $words = fn() => db()->query('SELECT id, audio, updated_at FROM words WHERE id >= 9100 AND id < 9300 ORDER BY id')->fetchAll();
        $before = $words();
        rec_sync(); rec_sync();
        eq($before, $words(), 'המילים השתנו');
        $n = (int)db()->query('SELECT COUNT(*) FROM recordings WHERE word_id >= 9100 AND word_id < 9300')->fetchColumn();
        eq(4, $n, 'מספר הרשומות = מספר המילים עם הקלטה');
        eq(['site', A2_U . 'ok/1.mp3', null], [a2_rec(9101)['state'], a2_rec(9101)['source'], a2_rec(9101)['file_id']], 'כתובת');
        eq(['tool', $fid], [a2_rec(9102)['state'], a2_rec(9102)['file_id']], 'מזהה קובץ');
        eq([], a2_rec(9103), 'מילה בלי הקלטה — בלי רשומה');
        // ספריית A1 קוראת מהמודל, והמונים שלה כמו הספירה הישירה במילים
        $lib = library_list();
        $mine = array_values(array_filter($lib['rows'], fn($r) => $r['id'] >= 9100 && $r['id'] < 9300));
        eq(['site', 'tool', 'none', 'site', 'site'], array_column($mine, 'state'), 'מצבים בספרייה');
        $direct = (int)db()->query("SELECT COUNT(*) FROM words WHERE audio != ''")->fetchColumn();
        eq($direct, $lib['counts']['audio'], 'המונה של הספרייה');
        // מילה שהקלטתה נמחקה — הרשומה יורדת; מילה שהוחלפה בקובץ — הרשומה עוברת ל"בכלי"
        db()->exec("UPDATE words SET audio = '' WHERE id = 9104");
        db()->prepare('UPDATE words SET audio = ? WHERE id = 9105')->execute([$fid]);
        rec_sync();
        eq([], a2_rec(9104), 'אחרי הסרת ההקלטה מהמילה');
        eq(['tool', 'replaced', A2_U . 'ok/1.mp3'], [a2_rec(9105)['state'], a2_rec(9105)['how'], a2_rec(9105)['source']], 'החלפה שומרת את המקור');
    } finally { $clean(); }
});

test('A2 · הגבולות: רק https, רק הדומיין, רק תחת uploads — כל השאר נדחה', function () {
    $site = a2_site();
    foreach ([A2_U . 'a.mp3', 'https://www.maghreb.co.il/wp-content/uploads/2024/05/%D7%90.mp3', 'https://MAGHREB.co.il/wp-content/uploads/x.m4a'] as $good) {
        eq('', rec_url_check($good), $good);
    }
    foreach (['http://maghreb.co.il/wp-content/uploads/a.mp3', 'https://evil.example/wp-content/uploads/a.mp3', 'https://maghreb.co.il.evil.example/wp-content/uploads/a.mp3',
              'https://maghreb.co.il/wp-admin/admin-ajax.php', 'https://maghreb.co.il/wp-content/plugins/x.mp3', 'https://93.184.216.34/wp-content/uploads/a.mp3',
              'https://[::1]/wp-content/uploads/a.mp3', 'https://localhost/wp-content/uploads/a.mp3', 'file:///etc/passwd', 'ftp://maghreb.co.il/wp-content/uploads/a.mp3',
              'https://maghreb.co.il:8443/wp-content/uploads/a.mp3', 'https://u:p@maghreb.co.il/wp-content/uploads/a.mp3', 'https://maghreb.co.il/wp-content/uploads/../../wp-config.php',
              'https://maghreb.co.il/wp-content/uploads/%2e%2e/%2e%2e/wp-admin/', 'https://maghreb.co.il/wp-content/uploads/%252e%252e/x', "https://maghreb.co.il/wp-content/uploads/a b.mp3",
              'https://maghreb.co.il/wp-content/uploads\\..\\x', 'https://2130706433/wp-content/uploads/a.mp3', 'javascript:alert(1)', ''] as $bad) {
        ok(rec_url_check($bad) !== '', "התקבלה: $bad");
    }
    foreach (['127.0.0.1', '10.1.2.3', '192.168.1.1', '172.16.0.5', '169.254.169.254', '0.0.0.0', '::1', 'fc00::1', 'fe80::1'] as $ip) ok(!rec_ip_public($ip), "IP פנימי התקבל: $ip");
    ok(rec_ip_public('93.184.216.34'), 'IP ציבורי נדחה');
    // בשרת האמיתי (לא CLI) מיפוי הבדיקות אינו פועל לעולם
    $code = (string)file_get_contents(KOL_ROOT . '/lib/recordings.php');
    ok(str_contains($code, "in_array(PHP_SAPI, ['cli', 'cli-server'], true)"), 'מיפוי הבדיקות אינו מוגבל ל-CLI');
    $site();
});

test('A2 · הפניה: בתוך uploads — נעקבת ונבדקת; לדומיין אחר או ל-wp-admin — נדחית ואינה נשלחת', function () {
    $site = a2_site();
    a2_log();
    $r = rec_http(['in' => ['method' => 'HEAD', 'url' => A2_U . 'redir-in/3.mp3'], 'out' => ['method' => 'HEAD', 'url' => A2_U . 'redir-out/3.mp3'],
        'adm' => ['method' => 'HEAD', 'url' => A2_U . 'redir-admin/3.mp3'], 'ip' => ['method' => 'GET', 'url' => 'https://127.0.0.1/wp-content/uploads/a.mp3', 'save' => '/dev/null']], 2);
    eq([200, '', A2_U . 'ok/3.mp3'], [$r['in']['status'], $r['in']['error'], $r['in']['url']], 'הפניה פנימית');
    eq('redirect', $r['out']['error'], 'הפניה לדומיין אחר');
    ok(str_contains($r['out']['note'], 'evil.example'), 'הסיבה אינה מציינת את הכתובת');
    eq('redirect', $r['adm']['error'], 'הפניה ל-wp-admin');
    eq('bad_url', $r['ip']['error'], 'כתובת IP');
    $paths = array_column(a2_log(), 'path');
    sort($paths);
    eq(['/wp-content/uploads/ok/3.mp3', '/wp-content/uploads/redir-admin/3.mp3', '/wp-content/uploads/redir-in/3.mp3', '/wp-content/uploads/redir-out/3.mp3'], $paths, 'מה הגיע לאתר');
    try { rec_http(['x' => ['method' => 'POST', 'url' => A2_U . 'ok/1.mp3']], 1); throw new TestFail('POST עבר'); } catch (LogicException) {}
    $site();
});

test('A2 · בלי לחיצה שום בייט אינו יורד: הספרייה, המצב, התוכנית וצעדים בלי התחלה — אפס בקשות', function () {
    $clean = a2_fixture([9111 => A2_U . 'ok/1.mp3', 9112 => A2_U . 'ok/2.mp3']);
    try {
        library_list(); rec_status(A2_OWNER); rec_fetch_plan(A2_OWNER);
        rec_scan_step(A2_OWNER); rec_fetch_step(A2_OWNER);                 // אין עבודה פעילה
        eq([], a2_log(), 'בקשות לאתר בלי לחיצה');
        a2_scan();
        eq(['HEAD'], array_values(array_unique(array_column(a2_log(), 'method'))), 'הסריקה שלחה משהו שאינו HEAD');
        rec_fetch_step(A2_OWNER);                                          // סריקה הסתיימה, אבל ההבאה לא אושרה
        eq([], a2_log(), 'הורדה בלי אישור');
        eq(409, throws(fn() => rec_fetch_start(A2_OWNER, ['token' => rec_fetch_plan(A2_OWNER)['plan']['token']])), 'בלי confirm');
        eq(409, throws(fn() => rec_fetch_start(A2_OWNER, ['confirm' => true, 'token' => 'x'])), 'אסימון שאינו של התוכנית');
        eq([], a2_log(), 'בקשות אחרי אישורים פסולים');
        // מסלול ההורדה היחיד: curl_ רק ב-rec_http, ו-rec_http נקרא רק מהצעדים
        $code = (string)preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string)file_get_contents(KOL_ROOT . '/lib/recordings.php'));
        preg_match_all('~function (\w+)\(.*?\n}~s', $code, $fns, PREG_SET_ORDER);
        $curl = $calls = [];
        foreach ($fns as [$body, $name]) {
            if (preg_match('/curl_init|curl_multi_init|file_get_contents|fopen\(\$r\[.url|fsockopen|stream_socket_client|get_headers/', $body)) $curl[] = $name;
            if ($name !== 'rec_http' && str_contains($body, 'rec_http(')) $calls[] = $name;
        }
        eq(['rec_http'], $curl, 'פונקציות שפונות לרשת');
        eq(['rec_scan_step', 'rec_fetch_step'], $calls, 'מי קורא ל-rec_http');
        foreach (glob(KOL_ROOT . '/{lib,.}/*.php', GLOB_BRACE) as $f) {
            if (str_ends_with($f, 'recordings.php')) continue;
            $c = (string)file_get_contents($f);
            ok(!preg_match('/rec_http\(|rec_fetch_step\(|rec_scan_step\(/', preg_replace('~\'rec_(fetch|scan)_step\' => rec_\1_step\(\$u\)~', '', $c)), basename($f) . ' מפעיל הורדה');
        }
    } finally { $clean(); }
});

test('A2 · סריקה: קיימות / חסרות / חסומות / שגיאה / מדולגות, נפח כולל, ורשימה עם המילה והמזהה', function () {
    $clean = a2_fixture([9121 => A2_U . 'ok/1.mp3', 9122 => A2_U . 'ok/2.mp3', 9123 => A2_U . 'missing/1.mp3', 9124 => A2_U . 'cf/1.mp3',
        9125 => A2_U . 'html/1.mp3', 9126 => A2_U . 'redir-out/1.mp3', 9127 => a2_file()]);
    try {
        $s = a2_scan();
        eq('done', $s['job']['status'], 'מצב');
        $sc = $s['counts']['scan'];
        eq([2, 1, 1, 1, 1, 0], [$sc['ok'], $sc['missing'], $sc['blocked'], $sc['error'], $sc['skipped'], $sc['']], 'מונים');
        eq(10 + 417 * 5 + 10 + 417 * 6, $s['counts']['bytes'], 'נפח כולל');
        eq(2, $s['counts']['todo'], 'להבאה');
        ok($s['scanned'], 'סומן כנסרק');
        $by = array_column($s['lists']['scan'], null, 'word_id');
        eq(['missing', 'מילה 9123', A2_U . 'missing/1.mp3'], [$by[9123]['scan'], $by[9123]['word'], $by[9123]['url']], 'חסרה ברשימה');
        eq('blocked', $by[9124]['scan'], 'חסומה');
        ok(str_contains($by[9126]['note'], 'evil.example'), 'מדולגת עם הסיבה');
        ok(!isset($by[9127]), 'הקלטה שבכלי אינה נסרקת');
        ok($s['free'] > 0 && $s['cap'] === disk_cap(), 'המקום הפנוי מול התקרה');
        $log = a2_log();
        eq(6, count($log), 'בקשה אחת לכל כתובת (פלוס ההפניה הנדחית — לא נשלחה)');
        foreach ($log as $l) {
            ok(str_starts_with($l['ua'], 'KolMaroko-AudioFetch/'), 'User-Agent גלוי');
            ok(!$l['cookie'] && !$l['auth'], 'נשלחה עוגייה / הרשאה');
        }
    } finally { $clean(); }
});

test('A2 · חסימה גורפת (Cloudflare) — "האתר חוסם את הכלי", הסריקה נעצרת, וההבאה אינה מתחילה', function () {
    $ws = [];
    for ($i = 1; $i <= 12; $i++) $ws[9130 + $i] = A2_U . "cf/$i.mp3";
    $clean = a2_fixture($ws);
    try {
        $s = a2_scan();
        eq('blocked', $s['job']['status'], 'מצב');
        ok($s['job']['blocked'], 'דגל חסימה');
        ok($s['counts']['scan'][''] > 0, 'לא נשלחו כל 12 הבקשות');
        ok(count(a2_log()) < 12, 'נשלחו כל הבקשות');
        $p = rec_fetch_plan(A2_OWNER)['plan'];
        ok(!$p['ok'] && $p['blocked'], 'התוכנית מאשרת הבאה');
        eq(400, throws(fn() => rec_fetch_start(A2_OWNER, ['confirm' => true, 'token' => $p['token']])), 'ההבאה התחילה');
    } finally { $clean(); }
});

test('A2 · מקום: נפח גדול מהמקום הפנוי — ההבאה אינה מתחילה, והמסך אומר כמה חסר ואיפה מגדילים', function () {
    $clean = a2_fixture([9141 => A2_U . 'big/1.mp3', 9142 => A2_U . 'ok/2.mp3']);
    $cap = setting('disk_cap');
    try {
        a2_scan();
        setting_set('disk_cap', (string)(disk_used() + 100_000));
        $p = rec_fetch_plan(A2_OWNER)['plan'];
        ok(!$p['enough'] && !$p['ok'] && $p['short'] > 3_000_000, 'חסר');
        $e = null;
        try { rec_fetch_start(A2_OWNER, ['confirm' => true, 'token' => $p['token']]); } catch (ApiError $x) { $e = $x; }
        ok($e && $e->getCode() === 507 && str_contains($e->getMessage(), 'תקרת הקבצים'), 'השגיאה אינה אומרת איפה מגדילים');
        eq(0, count(array_filter(a2_log(), fn($l) => $l['method'] === 'GET')), 'ירד משהו');
    } finally {
        $cap === null ? db()->exec("DELETE FROM settings WHERE k = 'disk_cap'") : setting_set('disk_cap', $cap);
        $clean();
    }
});

test('A2 · הבאה: שמע נכנס "בכלי" — המילה, המזהה והמקור לא השתנו; sha256; בעץ הפרויקט; כישלונות עם סיבה, בלי קובץ חלקי', function () {
    pf_seed(A2_OWNER);
    $urls = [9151 => A2_U . 'ok/1.mp3', 9152 => A2_U . 'redir-in/2.mp3', 9153 => A2_U . 'html/3.mp3', 9154 => A2_U . 'big/4.mp3', 9155 => A2_U . 'stream/5.mp3',
        9156 => A2_U . 'cut/6.mp3', 9157 => A2_U . 'text/7.mp3', 9158 => A2_U . 'slow/8.mp3'];
    $clean = a2_fixture($urls);
    setting_set('rec_file_max', (string)(1 << 20));
    setting_set('rec_timeout', '2');
    $tmpBefore = glob(files_dir() . '/tmp/rec-*') ?: [];
    try {
        $before = db()->query('SELECT id, wp_id, word, audio, updated_at FROM words WHERE id >= 9151 AND id <= 9158 ORDER BY id')->fetchAll();
        // סריקה — html נדחה כבר שם; את השאר מורידים
        a2_scan();
        db()->exec("UPDATE recordings SET scan = 'ok' WHERE word_id = 9153");     // גם אם HEAD עבר — ה-GET בודק את התוכן
        a2_log();
        $s0 = &devlog_state(); $s0['n'] = 0;                                  // מגבלת השורות לבקשה — כל הריצה כאן היא "בקשה" אחת
        $s = a2_fetch_all();
        eq('done', $s['job']['status'], 'מצב');
        eq($before, db()->query('SELECT id, wp_id, word, audio, updated_at FROM words WHERE id >= 9151 AND id <= 9158 ORDER BY id')->fetchAll(), 'המילים השתנו');
        foreach ([9151 => 'ok/1.mp3', 9152 => 'redir-in/2.mp3'] as $wid => $p) {
            $r = a2_rec($wid);
            eq(['tool', 'fetched', A2_U . $p], [$r['state'], $r['how'], $r['source']], "#$wid");
            $f = file_row($r['file_id']);
            eq(['audio', 'audio/mpeg', A2_U . $p], [$f['kind'], $f['type'], $f['origin']], "הקובץ של #$wid");
            eq(hash_file('sha256', file_path($f)), $f['sha256'], 'sha256');
            ok(pf_by_path("media/audio/$wid.mp3") !== null, "media/audio/$wid.mp3 בעץ");
        }
        $codes = [];
        foreach ([9153, 9154, 9155, 9156, 9157, 9158] as $wid) $codes[$wid] = a2_rec($wid)['state'] . ':' . a2_rec($wid)['f_code'];
        eq([9153 => 'failed:html', 9154 => 'failed:too_big', 9155 => 'failed:too_big', 9156 => 'failed:partial', 9157 => 'failed:not_audio', 9158 => 'failed:timeout'], $codes, 'הסיבות');
        eq($tmpBefore, glob(files_dir() . '/tmp/rec-*') ?: [], 'נשארו קבצים זמניים / חלקיים');
        eq(0, (int)db()->query("SELECT COUNT(*) FROM files WHERE origin LIKE '%/html/%' OR origin LIKE '%/big/%' OR origin LIKE '%/cut/%' OR origin LIKE '%/text/%'")->fetchColumn(), 'קובץ שנדחה נשמר');
        eq(['GET'], array_values(array_unique(array_column(a2_log(), 'method'))), 'שיטות');
        // הספרייה: מתנגנת מהכלי; הכישלון — עם הסיבה; המונה לא השתנה
        $lib = array_column(library_list()['rows'], null, 'id');
        ok(str_starts_with($lib[9151]['src'], 'api.php?action=media&'), 'לא מתנגנת מהכלי');
        eq(A2_U . 'ok/1.mp3', $lib[9151]['file']['origin'], 'המקור בספרייה');
        eq(['site', 'הגיע דף אינטרנט במקום קובץ שמע'], [$lib[9153]['state'], $lib[9153]['fetch']['reason']], 'כישלון בספרייה');
        // מסך ההתקדמות: הובאו / נכשלו
        $st = rec_status(A2_OWNER);
        eq([2, 6, 0], [$st['job']['done'], $st['job']['failed'], $st['counts']['todo']], 'הובאו / נכשלו / נותרו');
        eq(6, count($st['lists']['failed']), 'רשימת הכישלונות');
        // יומן המפתח: התחלה, סיום, וכל כישלון עם המזהה והכתובת
        $log = devlog_rows(['category' => 'audio', 'limit' => 100]);
        $ev = array_count_values(array_column($log, 'event'));
        ok(($ev['fetch_start'] ?? 0) >= 1 && ($ev['fetch_done'] ?? 0) >= 1 && ($ev['fetch_fail'] ?? 0) >= 6, 'אירועים ביומן: ' . json_encode($ev));
        $fail = array_values(array_filter($log, fn($x) => $x['event'] === 'fetch_fail' && ($x['data']['word_id'] ?? 0) === 9156))[0] ?? null;
        ok($fail && str_contains($fail['data']['url'] ?? '', 'cut/6.mp3'), 'הכישלון ביומן בלי הכתובת');
    } finally { $clean(); }
});

test('A2 · המשך אחרי עצירה: הדפדפן נסגר באמצע, השרת נופל באמצע — ממשיכים מאותה נקודה, ומה שהובא אינו יורד שוב', function () {
    $ws = [];
    for ($i = 1; $i <= 6; $i++) $ws[9160 + $i] = A2_U . "ok/$i.mp3";
    $ws[9167] = A2_U . 'flaky/7.mp3';
    $clean = a2_fixture($ws);
    try {
        a2_scan();
        a2_log();
        $p = rec_fetch_plan(A2_OWNER)['plan'];
        eq(7, $p['count'], 'בתוכנית');
        rec_fetch_start(A2_OWNER, ['confirm' => true, 'token' => $p['token']]);
        rec_fetch_step(A2_OWNER);                                           // צעד אחד (שתיים במקביל) — ואז "הדפדפן נסגר"
        eq(2, (int)db()->query("SELECT COUNT(*) FROM recordings WHERE word_id > 9160 AND word_id < 9170 AND state = 'tool'")->fetchColumn(), 'אחרי צעד אחד');
        $j = rec_job(); $j['touched'] = time() - 3600; setting_set('rec_job', json_encode($j));
        $s = rec_status(A2_OWNER);
        ok($s['job']['stale'] && $s['job']['status'] === 'running', 'לא מזוהה כ"נעצרה באמצע"');
        rec_job_op(A2_OWNER, ['op' => 'pause']);
        rec_fetch_step(A2_OWNER);
        eq(2, count(a2_log(false)), 'בהשהיה ירד משהו');
        rec_job_op(A2_OWNER, ['op' => 'resume']);
        for ($i = 0; $i < 20 && rec_fetch_step(A2_OWNER)['job']['status'] === 'running'; $i++);
        eq(['failed', 'partial'], [a2_rec(9167)['state'], a2_rec(9167)['f_code']], 'השרת נפל באמצע ההורדה');
        rec_job_op(A2_OWNER, ['op' => 'retry', 'word_id' => 9167]);
        for ($i = 0; $i < 20 && rec_fetch_step(A2_OWNER)['job']['status'] === 'running'; $i++);
        eq('tool', a2_rec(9167)['state'], 'אחרי "נסה שוב"');
        $gets = array_count_values(array_column(array_filter(a2_log(), fn($l) => $l['method'] === 'GET'), 'path'));
        foreach ($gets as $path => $n) eq(str_contains($path, 'flaky') ? 2 : 1, $n, "ירד יותר מפעם אחת: $path");
        eq(7, count($gets), 'כל הכתובות');
        // סריקה והבאה חדשות — מה שבכלי אינו נבדק ואינו יורד
        a2_scan();
        eq(0, rec_fetch_plan(A2_OWNER)['plan']['count'], 'מה שבכלי חזר לתוכנית');
        eq([], a2_log(), 'בקשות על מה שכבר בכלי');
        eq(400, throws(fn() => rec_job_op(A2_OWNER, ['op' => 'retry'])), 'נסה שוב בלי כישלונות');
    } finally { $clean(); }
});

test('A2 · משך: נשמר לקובץ שבכלי, רק כשזה עדיין הקובץ שלה; מוצג בספרייה', function () {
    $fid = a2_file();
    $clean = a2_fixture([9171 => $fid, 9172 => A2_U . 'ok/1.mp3']);
    try {
        eq(['saved' => true], rec_duration(A2_OWNER, ['word_id' => 9171, 'file_id' => $fid, 'duration' => 3.456]), 'שמירה');
        eq(3.46, array_column(library_list()['rows'], null, 'id')[9171]['file']['duration'], 'בספרייה');
        eq(['saved' => false], rec_duration(A2_OWNER, ['word_id' => 9172, 'file_id' => $fid, 'duration' => 2]), 'הקלטה "באתר"');
        eq(['saved' => false], rec_duration(A2_OWNER, ['word_id' => 9171, 'file_id' => str_repeat('a', 32), 'duration' => 2]), 'קובץ אחר');
        foreach ([-1, 0, 'x', 99999, null] as $bad) throws(fn() => rec_duration(A2_OWNER, ['word_id' => 9171, 'file_id' => $fid, 'duration' => $bad]), 'משך ' . json_encode($bad));
        $fid2 = a2_file();
        db()->prepare('UPDATE words SET audio = ? WHERE id = 9171')->execute([$fid2]);      // הוחלפה — המשך הישן אינו שלה
        eq(null, array_column(library_list()['rows'], null, 'id')[9171]['file']['duration'], 'המשך של הקובץ הקודם נשאר');
    } finally { $clean(); }
});

test('A2 · ייבוא ה-ZIP: התאמה קודם לפי מזהה המילה, אחר כך לפי הכתובת; מה שלא הותאם — ברשימה; נכנס למודל', function () {
    // 9181: הכתובת באתר השתנתה מאז הייצוא — רק המזהה מתאים · 9182: לפי הכתובת (בלי מזהה) · 9183: בשם הקובץ · זר: לא מתאים
    $clean = a2_fixture([9181 => A2_U . 'ok/81-new.mp3', 9182 => A2_U . 'ok/82.mp3', 9183 => A2_U . 'ok/83-x.mp3']);
    try {
        $mp3 = "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xff\xfb\x90\x64" . str_repeat("\x00", 413), 5);
        $id = bin2hex(random_bytes(16));
        $zipPath = tmp_path($id);
        @mkdir(dirname($zipPath), 0775, true);
        $z = new ZipArchive();
        $z->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $list = [['url' => A2_U . 'ok/81-old.mp3', 'file' => 'audio/2020/81-old.mp3', 'word_id' => 9181 + 50000, 'word' => 'מילה 9181'],
            ['url' => A2_U . 'ok/82.mp3', 'file' => 'audio/2020/82.mp3', 'word' => 'מילה 9182'],
            ['url' => A2_U . 'ok/zz.mp3', 'file' => 'audio/2020/' . (9183 + 50000) . '.mp3', 'word' => 'מילה 9183'],
            ['url' => A2_U . 'ok/stranger.mp3', 'file' => 'audio/2020/stranger.mp3', 'word_id' => 777777, 'word' => 'זרה']];
        foreach ($list as $f) $z->addFromString($f['file'], $mp3);
        $z->addFromString('audio.json', json_encode(['format' => 'kol-audio', 'plugin' => '1.4.0', 'part' => 1, 'parts' => 1, 'files' => $list]));
        $z->close();
        db()->prepare('INSERT INTO uploads (id, name, size, sub, started, purpose) VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, 'a.zip', filesize($zipPath), A2_OWNER['sub'], time(), 'import']);
        $r = audio_import(A2_OWNER, ['id' => $id])['stats'];
        foreach ([9181, 9182, 9183] as $wid) eq('tool', a2_rec($wid)['state'], "#$wid במודל");
        eq(A2_U . 'ok/81-new.mp3', a2_rec(9181)['source'], 'המקור של 9181 — הכתובת של המילה');
        eq(1, $r['unmatched'], 'לא הותאם');
        eq('זרה', $r['unmatched_list'][0]['word'] ?? null, 'רשימת מה שלא הותאם');
        eq(3, $r['linked'], 'קושרו');
    } finally { $clean(); }
});

test('A2 · הרשאות: רק בעל האתר סורק, מביא ורואה את המצב; עורך — רק משך; GET לפעולה משנה — 405', function () {
    foreach (['rec_scan_start', 'rec_scan_step', 'rec_fetch_start', 'rec_fetch_step', 'rec_job_op', 'rec_settings_set'] as $a) {
        eq(403, http('POST', "/api.php?action=$a", ['user' => U_EDITOR, 'json' => ['confirm' => true]])['status'], "$a לעורך");
        eq(403, http('POST', "/api.php?action=$a", ['user' => U_PUBLISHER, 'json' => []])['status'], "$a למפרסם");
        eq(403, http('POST', "/api.php?action=$a", ['user' => U_STRANGER, 'json' => []])['status'], "$a לזר");
        eq(401, http('POST', "/api.php?action=$a", ['json' => []])['status'], "$a בלי כניסה");
        eq(405, http('GET', "/api.php?action=$a", ['user' => U_OWNER])['status'], "$a ב-GET");
    }
    foreach (['rec_status', 'rec_fetch_plan'] as $a) eq(403, http('GET', "/api.php?action=$a", ['user' => U_EDITOR])['status'], "$a לעורך");
    eq(200, http('GET', '/api.php?action=rec_status', ['user' => U_OWNER])['status'], 'בעלים רואה את המצב');
    eq(403, http('POST', '/api.php?action=rec_scan_start', ['user' => U_OWNER, 'csrf' => false, 'json' => []])['status'], 'בלי CSRF');
    eq(400, http('POST', '/api.php?action=rec_duration', ['user' => U_EDITOR, 'json' => ['word_id' => 1, 'file_id' => 'x', 'duration' => 1]])['status'], 'משך לעורך — נבדק, לא נחסם');
    eq([], a2_log(), 'בקשות לאתר מהבדיקות האלה');
});
