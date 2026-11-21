<?php
/**
 * פרוסה M6 — הסרת הסטודיו הישן (docs/spec-v2/slices/M6-remove-old-studio.md). כל בדיקה מול קריטריון ב-§2.
 *
 * הקובץ יושב במקום של 80-migrate.php (שנמחק עם ההסבה), ובסופו אותו ניקוי שהיה שם: הפרויקט חוזר לשלד ברירת המחדל —
 * הבדיקות שאחריו (V15, M7) מניחות פרויקט בלי עמודים שבדיקות קודמות יצרו.
 */

declare(strict_types=1);

require_once KOL_ROOT . '/lib/preview.php';

const M6_GONE_FUNCS = ['draft_get', 'draft_save', 'draft_write', 'lock_op', 'revision_add', 'revisions_list', 'revision_restore', 'render_preview',
    'publish', 'republish', 'publish_preview', 'publish_checks', 'publish_diff', 'build_version', 'version_create', 'version_send', 'asset_send',
    'versions_list', 'version_preview', 'version_to_tool', 'publish_settings', 'import_run', 'sanitize_imported_css', 'editor_session', 'ai_suggest',
    'code_state', 'code_create', 'code_save', 'code_reset', 'code_history', 'code_history_get', 'code_snapshot', 'snapshot_preview'];
const M6_GONE_ACTIONS = ['draft', 'draft_save', 'lock', 'render', 'revisions', 'revision_restore', 'publish_preview', 'publish', 'republish', 'version_send',
    'versions', 'version_preview', 'publish_settings', 'import_run', 'import_info', 'code', 'code_create', 'code_save', 'code_reset', 'code_history',
    'code_history_get', 'pf_migrate', 'pf_migrate_last', 'pf_blank', 'editor_session', 'ai_suggest', 'snapshot_preview'];
const M6_OLD_TABLES = ['draft', 'revisions', 'edit_lock', 'versions', 'code_files', 'code_history', 'longtexts'];

/** קוד הייצור — בלי tests/ ובלי התוסף. */
function m6_prod_files(string $ext): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KOL_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = substr($f->getPathname(), strlen(KOL_ROOT) + 1);
        if (!str_ends_with($p, ".$ext") || preg_match('~^(tests|wordpress-plugin|data|docs|dev|team|assets/vendor|preview/spike)/~', $p)) continue;
        $out[$p] = (string)file_get_contents($f->getPathname());
    }
    return $out;
}

/** גרסה בפורמט הישן, כמו שפרסום 1.x כתב אותה (לפני M6). */
function m6_old_version(int $n, string $title): void
{
    $page = page_template();
    $page['sections'][0]['columns'][0]['widgets'][] = ['id' => 'm6h' . $n, 'type' => 'heading', 'props' => ['text' => $title, 'level' => 2], 'style' => []];
    db()->prepare("INSERT INTO versions (n, at, sub, name, note, json, html, css, assets, status) VALUES (?, ?, 'u-owner', 'בעלים', ?, ?, '<p>x</p>', '', '[]', ?)")
        ->execute([$n, '2026-10-0' . ($n % 9 + 1) . 'T10:00:00Z', "גרסה ישנה $n", page_json(page_normalize($page)), $n === 902 ? 'live' : 'superseded']);
}

test('M6 §2.2: אין פונקציה שנמחקה, ואין קריאה אליה בקוד הייצור (PHP ו-JS)', function () {
    foreach (M6_GONE_FUNCS as $f) ok(!function_exists($f), "הפונקציה $f עוד קיימת");
    foreach (['lib/draft.php', 'lib/publish.php', 'lib/import.php', 'lib/legacy.php', 'lib/migrate.php', 'assets/js/studio.js', 'assets/js/code.js'] as $p) {
        ok(!is_file(KOL_ROOT . "/$p"), "$p עוד קיים");
    }
    $php = m6_prod_files('php');
    foreach ($php as $p => $src) {
        ok(!preg_match('~require(_once)?\s*\(?\s*__DIR__\s*\.\s*\'/(lib/)?(draft|publish|import|legacy|migrate)\.php\'~', $src), "$p: require לקובץ שנמחק");
        foreach (M6_GONE_FUNCS as $f) ok(!preg_match('/(?<![\w>$:\'"-])(?<!TABLE )(?<!EXISTS )(?<!ON )' . $f . '\s*\(/', $src), "$p קורא ל-$f()");
    }
    $js = m6_prod_files('js');
    foreach ($js as $p => $src) {
        foreach (M6_GONE_ACTIONS as $a) ok(!preg_match("/api\\(\\s*'$a'/", $src), "$p קורא ל-api('$a')");
        ok(!preg_match('/\b(Studio|Code)\.(mount|unmount|view)\b/', $src), "$p קורא ל-Studio / Code");
    }
    // draft_row / page_render — רק ב-legacy_read.php (הגדרה), restore.php (נקודות ישנות) ו-project.php (snap_state דרכו — לא)
    foreach ($php as $p => $src) {
        if (preg_match('/\b(draft_row|page_render)\s*\(/', $src)) ok(in_array($p, ['lib/legacy_read.php', 'lib/restore.php'], true), "$p קורא ל-draft_row / page_render");
    }
});

test('M6 §2.2: הפעולות שנמחקו — 404 ב-api.php; ובמטריצת ההרשאות אין להן שורה', function () {
    $api = (string)file_get_contents(KOL_ROOT . '/api.php');
    foreach (M6_GONE_ACTIONS as $a) {
        ok(!array_key_exists($a, ACTION_ROLES), "$a עוד ב-ACTION_ROLES");
        ok(!str_contains($api, "'$a' =>"), "$a עוד ב-match של api.php");
        if (in_array($a, ['pf_migrate', 'pf_migrate_last', 'pf_blank'], true)) continue;      // שרת הבדיקות עונה עליהן מ-tests/support (זריעה לבדיקות הדפדפן)
        $r = http('GET', "/api.php?action=$a", ['user' => U_OWNER]);
        eq(404, $r['status'], "GET $a");
        if (!in_array($a, ['code', 'versions', 'revisions', 'draft'], true)) eq(404, http('POST', "/api.php?action=$a", ['user' => U_OWNER, 'json' => []])['status'], "POST $a");
    }
    foreach (['archive', 'archive_view', 'archive_html', 'site_settings', 'key_rotate', 'key_activate', 'site_set', 'site_ping'] as $a) {
        ok(array_key_exists($a, ACTION_ROLES), "$a חסרה ב-ACTION_ROLES");
    }
});

test('M6 §2.3: הטבלות הישנות נשארות; אין DROP ואין DELETE עליהן בקוד', function () {
    $have = db()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (M6_OLD_TABLES as $t) ok(in_array($t, $have, true), "הטבלה $t");
    foreach (m6_prod_files('php') as $p => $src) {
        ok(!preg_match('/DROP\s+TABLE\s+(IF\s+EXISTS\s+)?(' . implode('|', M6_OLD_TABLES) . ')\b/i', $src), "$p: DROP");
        ok(!preg_match('/DELETE\s+FROM\s+(' . implode('|', M6_OLD_TABLES) . ')\b/i', $src), "$p: DELETE");
    }
});

test('M6 §2.3: נקודת שחזור ישנה — נפתחת ומשוחזרת; הטיוטה נכתבת חזרה (בלי מסך); "legacy" ברשימה', function () {
    pf_seed(['sub' => 'system', 'name' => 'seed']);
    $pdo = db();
    $before = draft_row();
    $page = page_template();
    $page['settings']['title'] = 'הטיוטה של לפני M6';
    $pdo->prepare('UPDATE draft SET json = ? WHERE id = 1')->execute([page_json(page_normalize($page))]);
    $old = $pdo->query('SELECT json FROM draft WHERE id = 1')->fetchColumn();
    $id = snapshot_create(['sub' => 'u-owner', 'name' => 'בעלים'], 'נקודה מלפני M6', 'manual', true);
    $list = array_column(snapshots_list([])['snapshots'], null, 'id');
    eq(true, $list[$id]['legacy'], 'הנקודה כוללת את העמוד הישן');
    $pdo->prepare('UPDATE draft SET json = ? WHERE id = 1')->execute([$before['json']]);
    $revs = (int)$pdo->query('SELECT COUNT(*) FROM revisions')->fetchColumn();
    $r = http('POST', '/api.php?action=snapshot_restore', ['user' => U_OWNER, 'json' => ['id' => $id]]);
    eq(200, $r['status'], $r['body']);
    eq($old, $pdo->query('SELECT json FROM draft WHERE id = 1')->fetchColumn(), 'הטיוטה חזרה לטבלה');
    eq($revs + 1, (int)$pdo->query('SELECT COUNT(*) FROM revisions')->fetchColumn(), 'הקודמת נשמרה בהיסטוריה — הוספה בלבד');
    eq(404, http('GET', "/api.php?action=snapshot_preview&id=$id", ['user' => U_OWNER])['status'], 'אין מסך לטיוטה הישנה');
    ok(snap_fingerprint() !== '', 'snap_state ממשיך לקרוא את draft ואת code_files');
    $pdo->prepare('UPDATE draft SET json = ? WHERE id = 1')->execute([$before['json']]);
});

test('M6 §2.3: 🗄️ ארכיון — רשימה, תצוגה והורדה כ-HTML; לקריאה בלבד, ולכל מי שבכלי', function () {
    m6_old_version(901, 'הכותרת של 901');
    m6_old_version(902, 'הכותרת של 902');
    $count = fn() => db()->query('SELECT COUNT(*) || \':\' || COALESCE(SUM(length(json)), 0) FROM versions')->fetchColumn();
    $was = $count();
    $l = http('GET', '/api.php?action=archive', ['user' => U_CONTENT]);
    eq(200, $l['status'], $l['body']);
    $by = array_column($l['json']['versions'], null, 'n');
    ok(isset($by[901], $by[902]), 'שתי הגרסאות ברשימה');
    eq(['גרסה ישנה 902', 'בעלים'], [$by[902]['note'], $by[902]['name']]);
    eq(902, $l['json']['last_live'], 'האחרונה שהייתה באתר');
    $v = http('GET', '/api.php?action=archive_view&n=901', ['user' => U_CONTENT]);
    eq(200, $v['status'], $v['body']);
    ok(str_contains($v['json']['html'], 'הכותרת של 901') && str_contains($v['json']['css'], '.kol-page'), 'page_render של הגרסה');
    $d = http('GET', '/api.php?action=archive_html&n=902', ['user' => U_CONTENT]);
    eq(200, $d['status']);
    ok(str_contains($d['body'], 'הכותרת של 902') && str_starts_with($d['body'], '<!doctype html>'), 'מסמך HTML שלם');
    ok(str_contains(implode("\n", array_map(fn($k, $v) => "$k: $v", array_keys($d['headers']), $d['headers'])), 'kol-maroko-v902.html'), 'להורדה, בשם הגרסה');
    eq(404, http('GET', '/api.php?action=archive_view&n=1', ['user' => U_CONTENT])['status'], 'גרסה שאין');
    eq(403, http('GET', '/api.php?action=archive', ['user' => U_STRANGER])['status'], 'זר');
    eq($was, $count(), 'הארכיון אינו משנה דבר');
    db()->exec('UPDATE versions SET status = status WHERE n IN (901, 902)');       // נשארות — אין DELETE על versions
});

test('M6: הבאג של "🚀 פרסום" (1.x) — בערוץ משיכה שום פעולה אינה נדחפת לאתר; פעולה שאינה בתור — שגיאה ולא POST', function () {
    $keep = setting('pub_channel');
    setting_set('pub_channel', 'pull');
    try {
        $n = (int)db()->query('SELECT COUNT(*) FROM site_outbox')->fetchColumn();
        foreach (['publish', 'asset', 'ping_v1'] as $a) {
            $e = null;
            try { site_call($a, []); } catch (ApiError $x) { $e = $x; }
            ok($e !== null && ($e->extra['code'] ?? '') === 'pull_only', "$a: " . ($e ? $e->getMessage() : 'עבר'));
        }
        eq($n, (int)db()->query('SELECT COUNT(*) FROM site_outbox')->fetchColumn(), 'לא נכנס לתור');
    } finally {
        $keep === null ? db()->prepare("DELETE FROM settings WHERE k = 'pub_channel'")->execute() : setting_set('pub_channel', $keep);
    }
});

test('M6 §2.1 ממשק: אין לשונית 🎨 סטודיו ואין </> קוד; #studio ← 🎨 עורך; "🚀 פרסום" בלי פרסום הטיוטה', function () {
    $html = (string)file_get_contents(KOL_ROOT . '/studio.html');
    ok(!str_contains($html, 'studio.js') && !str_contains($html, 'code.js'), 'studio.html אינו טוען את studio.js / code.js');
    $app = (string)file_get_contents(KOL_ROOT . '/assets/js/app.js');
    ok(!preg_match("/id: 'studio'|id: 'code'/", $app), 'אין לשוניות studio / code ב-TABS');
    ok(str_contains($app, "studio: 'editor'") && str_contains($app, "code: 'project'"), 'הכתובות הישנות מועברות');
    $pub = (string)file_get_contents(KOL_ROOT . '/assets/js/publish.js');
    foreach (['publishSheet', 'versionSheet', 'importBlock', "'version_send'", "'republish'", "api('publish'", 'פרסום הטיוטה לאתר', 'המסלול של היום'] as $s) ok(!str_contains($pub, $s), "publish.js: $s");
    ok(str_contains($pub, '🗄️ ארכיון') && str_contains($pub, 'action=archive_html'), 'הארכיון ב-🚀 פרסום');
    ok(!preg_match("/api\\('pf_migrate|🪄|ייבוא מחדש מהעמוד של היום/u", (string)file_get_contents(KOL_ROOT . '/assets/js/project.js')), 'project.js — בלי ההסבה');
});

test('M6 §2.5: שורה במסך המפתח — "הסטודיו הישן הוסר", עם הטבלות ומספר השורות', function () {
    $row = array_column(dev_stats(), null, 'id')['m6'] ?? null;
    ok($row !== null, 'יש שורה');
    ok(str_contains($row['value'], 'הוסר') && str_contains($row['value'], 'versions') && str_contains($row['value'], 'draft'), $row['value'] ?? '');
});

// הועבר מ-80-migrate.php (נמחק עם ההסבה): data/words.json של התצוגה — חלק שנשאר
test('תצוגה: data/words.json נושא את המזהים שהכרטיס נקשר אליהם (wp_id, external_id, topics לפי slug)', function () {
    $pdo = db();
    $pdo->exec("INSERT OR IGNORE INTO topics (slug, name) VALUES ('food', 'אוכל הסבה'), ('greet', 'ברכות הסבה')");
    $ins = $pdo->prepare('INSERT INTO words (id, wp_id, word, meaning, topics, audio, external_id, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([9001, 501, 'כסכסו', '<p>מאכל</p>', '["אוכל הסבה"]', 'https://maghreb.co.il/a/1.mp3', 'X1', now_iso()]);
    $ins->execute([9002, 502, 'בסלאמה', '<p>להתראות</p>', '["ברכות הסבה","אוכל הסבה"]', '', '', now_iso()]);
    $ins->execute([9003, null, 'מרחבא', '<p>ברוך הבא</p>', '[]', 'https://maghreb.co.il/a/3.mp3', '', now_iso()]);
    try {
        $j = json_decode(pv_words('/'), true);
        $by = array_column($j['items'], null, 'id');
        eq(501, $by[9001]['wp_id'] ?? null);
        eq('X1', $by[9001]['external_id'] ?? null);
        ok(!array_key_exists('wp_id', $by[9003]) && !array_key_exists('external_id', $by[9003]), 'מילה בלי מזהה — בלי השדה');
        eq(['greet', 'food'], $by[9002]['topics']);
    } finally {
        $pdo->exec('DELETE FROM words WHERE id >= 9001');
        $pdo->exec("DELETE FROM topics WHERE slug IN ('food', 'greet')");
    }
});

test('M6 ניקוי: הפרויקט חוזר לשלד ברירת המחדל (כמו "פרויקט ריק" בסוף 80-migrate.php, שנמחק)', function () {
    require_once KOL_ROOT . '/tests/support/migrate.php';
    $r = pf_blank(['sub' => 'u-owner', 'name' => 'בעלים', 'role' => 'site_owner']);
    ok(in_array('index.html', $r['files'], true), 'index.html');
});
