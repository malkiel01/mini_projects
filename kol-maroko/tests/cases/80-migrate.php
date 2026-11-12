<?php
/**
 * פרוסה F3 — ההסבה (lib/migrate.php). כל בדיקה עומדת מול קריטריון ב-docs/spec-v2/slices/F3-migration-project.md §2.
 * המילים והטיוטה של הבדיקה מוסרים בסופה — הבדיקות שאחריה (tests/*_test.php) מניחות מסד בלי מילים.
 */

declare(strict_types=1);

require_once KOL_ROOT . '/lib/preview.php';
require_once KOL_ROOT . '/lib/migrate.php';

const MG_THEME_LEX = "/**\n * קול מרוקו - עיצוב עמוד המילון\n */\n:root { --ka-gold: #c9a227; --ka-cream: #f5ebd6; }\n.kadisha-hero-title { font-size: 40px !important; color: var(--ka-gold); }\n.kadisha-word-card { padding: 12px; }\n";
const MG_THEME_STYLE = "/* חברה קדישא המערבית - CSS */\n:root { --ka-navy: #14213d; }\n.kadisha-hero { background: var(--ka-navy); }\n";

/** מצב התחלתי: ייבוא מדומה מהאתר, רכיב מילון בטיוטה, שלוש מילים. מחזיר פונקציה שמנקה. */
function mg_fixture(array $props = []): callable
{
    $pdo = db();
    $draft = draft_row();
    $keep = [];
    foreach (['theme_lexicon_css' => MG_THEME_LEX, 'theme_style_css' => MG_THEME_STYLE, 'preview_js' => "/* lexicon.js של הערכה */\nconsole.log('theme');\n"] as $k => $v) {
        $keep[$k] = setting($k);
        setting_set($k, $v);
    }
    $page = page_normalize(lexicon_page(['settings' => ['page_title_he' => 'קול מרוקו <b>', 'page_subtitle_main_he' => 'מילון', 'page_title_fr' => 'La Voix', 'page_title_en' => 'Voice',
        'page_subtitle_he' => 'תת כותרת', 'page_credit_he' => 'קרדיט', 'title_size_px' => 64, 'hero_min_height' => 420, 'intro_max_width_px' => 700],
        'hero_image' => 'https://' . lex_host() . '/wp-content/uploads/hero.jpg', 'hero_overlay' => 40, 'intro_html' => "פתיחה<!--more-->המשך",
        'feedback' => $props['feedback'] ?? false, 'show_external_id' => $props['show_ids'] ?? false], 'קול מרוקו — המילון'));
    $pdo->prepare('UPDATE draft SET json = ?')->execute([page_json($page)]);
    $pdo->exec("INSERT OR IGNORE INTO topics (slug, name) VALUES ('food', 'אוכל הסבה'), ('greet', 'ברכות הסבה')");
    $ins = $pdo->prepare('INSERT INTO words (id, wp_id, word, meaning, topics, audio, external_id, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([9001, 501, 'כסכסו', '<p>מאכל</p>', '["אוכל הסבה"]', 'https://maghreb.co.il/a/1.mp3', 'X1', now_iso()]);
    $ins->execute([9002, 502, 'בסלאמה', '<p>להתראות</p>', '["ברכות הסבה","אוכל הסבה"]', '', '', now_iso()]);
    $ins->execute([9003, null, 'מרחבא', '<p>ברוך הבא</p>', '[]', 'https://maghreb.co.il/a/3.mp3', '', now_iso()]);
    return function () use ($pdo, $draft, $keep) {
        $pdo->exec('DELETE FROM words WHERE id >= 9001');
        $pdo->exec("DELETE FROM topics WHERE slug IN ('food', 'greet')");
        $pdo->exec('DELETE FROM code_files');
        $pdo->prepare('UPDATE draft SET json = ?')->execute([$draft['json']]);
        foreach ($keep as $k => $v) $v === null ? $pdo->prepare('DELETE FROM settings WHERE k = ?')->execute([$k]) : setting_set($k, $v);
    };
}

test('הסבה: index.html הוא HTML רגיל ומלא, בלי Mustache; הלולאות הן <template> ותכונות data-kol-*', function () {
    $done = mg_fixture();
    try {
        $b = mg_build();
        $h = $b['files']['index.html'];
        if ($dir = getenv('KOL_DUMP')) foreach ($b['files'] as $p => $c) { @mkdir(dirname("$dir/$p"), 0775, true); file_put_contents("$dir/$p", $c); }
        ok(str_starts_with($h, "<!doctype html>\n<html lang=\"he\" dir=\"rtl\">"), 'doctype');
        ok(!str_contains($h, '{{') && !str_contains($h, '}}') && !preg_match('/[\x01\x02]/', $h), 'נשאר תחביר תבנית');
        ok(!str_contains($h, 'kol-migrate:'), 'בתבנית המובנית אין חלק שלא הומר');
        foreach (['<title>קול מרוקו — המילון</title>', 'fonts.googleapis.com', 'href="css/theme-style.css"', 'href="css/lexicon.css"', 'href="css/tokens.css"', 'href="css/page.css"',
            'src="js/kol-data.js"', '<h1 id="lexicon-hero-title" class="kadisha-hero-title">קול מרוקו &lt;b&gt;</h1>', 'kadisha-hero-subtitle-main">מילון<', '>La Voix<', '>Voice<', '>תת כותרת<', '>קרדיט<',
            'kadisha-hero kadisha-hero--has-image"', 'id="kadisha-lex-panel-0"', 'id="kadisha-lex-panel-1"', 'data-more-text="קרא עוד"',
            'data-kol-search="words"', 'data-kol-search-scope="words"', 'data-kol-filter="words:topics"', 'data-kol-filter-value=""', 'data-kol-count-text="{shown}"', 'data-kol-count-text="{total}"',
            'data-kol-list="words" data-kol-sort="manual" class="kadisha-lexicon-grid" id="kadisha-lex-grid"', '<template>', 'data-kol-text="word"', 'data-kol-html="meaning"', 'data-kol-if="audio"', 'data-kol-play', 'data-kol-each="topics"',
            'data-kol-text="label"', 'data-kol-class-is-single="topics.count=1"', 'data-kol-if="topics"', 'data-kol-attr-aria-label="השמע את המילה {word}"', 'data-kol-empty="words" hidden',
            'data-kol-attr-data-topic="id"', 'data-kol-text="count"'] as $need) ok(str_contains($h, $need), "חסר ב-index.html: $need");
        ok(!str_contains($h, 'style="background-image') && !str_contains($h, 'data-search'), 'style של הכלי / תכונות החיפוש הישנות נשארו');
        ok(!str_contains($h, 'kadisha-word-feedback-btn') && !str_contains($h, 'kadisha-lex-feedback-modal'), 'המשוב כבוי בפאנל — לא נכנס');
        eq(2, substr_count($h, '<template>'), 'שתי תבניות: הכרטיס וכפתור הנושא');
    } finally { $done(); }
});

test('הסבה: ה-CSS של ערכת הנושא בייט בבייט; ערכי הפאנל ב-page.css בלי !important; המשתנים ב-tokens.css', function () {
    $done = mg_fixture();
    try {
        $b = mg_build();
        eq(MG_THEME_LEX, $b['files']['css/lexicon.css'], 'lexicon.css כמו שהוא');
        eq(MG_THEME_STYLE, $b['files']['css/theme-style.css'], 'theme-style.css כמו שהוא');
        $p = $b['files']['css/page.css'];
        ok(!str_contains($p, '!important') && !str_contains($p, '{{'), 'page.css: נשאר !important או תבנית');
        foreach (['font-size: 64px', 'font-weight: 700', 'min-height:420px', "background-image: url('https://" . lex_host() . "/wp-content/uploads/hero.jpg')", '--ka-hero-overlay: 40', 'max-width: 700px', '@media (max-width: 600px)'] as $need) ok(str_contains($p, $need), "חסר ב-page.css: $need");
        ok($b['counts']['important_removed'] > 10, 'מספר ה-!important שהוסרו מדווח');
        foreach (['--ka-gold: #c9a227', '--ka-cream: #f5ebd6', '--ka-navy: #14213d'] as $v) ok(str_contains($b['files']['css/tokens.css'], $v), "חסר ב-tokens.css: $v");
        ok((bool)array_filter($b['notes'], fn($n) => $n[0] === 'warn' && str_contains($n[1], '.kadisha-hero-title · font-size')), 'אזהרה על !important של ערכת הנושא שמתנגש בערך מהפאנל');
        ok(str_contains($b['files']['js/lexicon.js'], "console.log('theme')") && !str_contains($b['files']['index.html'], 'js/lexicon.js'), 'lexicon.js — עותק לעיון, לא נטען');
        $kol = json_decode($b['files']['kol.json'], true);
        eq(['version' => '1.3.0', 'modified' => false], $kol['system']['js/kol-data.js'] ?? null, 'kol.json רושם את kol-data.js כקובץ מערכת');
        ok(!preg_match('/[\x{0590}-\x{05FF}]/u', preg_replace('~/\*[\s\S]*?\*/|//[^\n]*~', '', $b['files']['js/kol-data.js'])), 'ב-kol-data.js אין טקסט שהגולש רואה (עברית מחוץ להערות)');
    } finally { $done(); }
});

test('הסבה: משוב ומזהים דולקים בפאנל ← החלון, הכפתור והמזהה בקובץ, קשורים', function () {
    $done = mg_fixture(['feedback' => true, 'show_ids' => true]);
    try {
        $h = mg_build()['files']['index.html'];
        foreach (['data-kol-dialog="feedback"', 'data-kol-form="feedback"', '<span data-kol-form-done hidden>', '<span data-kol-form-error hidden>', 'name="website" tabindex="-1" autocomplete="off" data-kol-trap', '<input type="hidden" name="word_id" data-kol-value="id">', 'data-kol-close', 'data-kol-open="feedback"', 'data-kol-if="wp_id"',
            'data-kol-if="external_id"', 'data-kol-text="#{external_id}"', 'id="kadisha-lex-feedback-word-name" data-kol-text="word"'] as $need) ok(str_contains($h, $need), "חסר: $need");
        ok(!str_contains($h, '{{'), 'נשאר Mustache');
    } finally { $done(); }
});

test('הסבה: יוצאת מהגרסה של בעל העמוד; ביטוי שלא הומר נשאר כהערה ומדווח — לא נזרק בשקט', function () {
    $done = mg_fixture();
    try {
        $mine = str_replace('<h1 id="lexicon-hero-title"', '<p class="mine">שלי {{title_he}}</p>{{#weird}}<i>{{strange}}</i>{{/weird}}<h1 id="lexicon-hero-title"', LEX_TPL);
        $mine = str_replace('<h3 class="kadisha-word-title">{{word}}</h3>', '<h3 class="kadisha-word-title">{{word}}</h3><em>{{odd_field}}</em>', $mine);
        $st = db()->prepare('INSERT INTO code_files (path, content, updated_at, updated_by) VALUES (?, ?, ?, ?)');
        $st->execute(['lexicon.html', $mine, now_iso(), 'בעלים']);
        $st->execute(['lexicon.css', ".mine { color: red; }\n\n" . LEX_CSS_MARK . "\n" . LEX_TPL_SIZES, now_iso(), 'בעלים']);
        $b = mg_build();
        $h = $b['files']['index.html'];
        ok(str_contains($h, '<p class="mine">שלי קול מרוקו &lt;b&gt;</p>'), 'השינוי של בעל העמוד נכנס');
        ok(str_contains($h, '<!-- kol-migrate: לא הומר: {{odd_field}} -->'), 'ביטוי חריג בכרטיס — הערה בקובץ');
        ok(!str_contains($h, '{{strange}}'), 'ביטוי חריג ברמת העמוד');
        ok((bool)array_filter($b['notes'], fn($n) => $n[0] === 'failed' && str_contains($n[1], 'odd_field')), 'מדווח: odd_field');
        ok((bool)array_filter($b['notes'], fn($n) => str_contains($n[1], 'weird')), 'מדווח: weird');
        ok(str_contains($b['taken'][0][1], 'מהגרסה שלך') && str_contains($b['taken'][1][1], 'מהגרסה שלך'), 'מוצג מה נלקח מהגרסה שלו');
        eq(".mine { color: red; }\n", $b['files']['css/lexicon.css'], 'ה-CSS שלו עד הסימון, כמו שהוא');
    } finally { $done(); }
});

test('הסבה: בעלים בלבד; נקודת שחזור לפני; המודל הישן לא משתנה; כל קובץ נפתח; הרצה חוזרת ← הקודם לסל; "פרויקט ריק"', function () {
    $done = mg_fixture();
    try {
        eq(403, http('POST', '/api.php?action=pf_migrate', ['user' => U_EDITOR, 'json' => []])['status'], 'עורך אינו מסב');
        $old = pf_paths();
        $before = [draft_row()['json'], code_snapshot(), db()->query('SELECT k, v FROM settings WHERE k NOT IN (\'pf_migrate_last\') ORDER BY k')->fetchAll(), words_snapshot(),
            (int)db()->query('SELECT COUNT(*) FROM versions')->fetchColumn(), (int)db()->query('SELECT COUNT(*) FROM revisions')->fetchColumn()];
        $snaps = (int)db()->query('SELECT COUNT(*) FROM snapshots')->fetchColumn();
        $dry = http('POST', '/api.php?action=pf_migrate', ['user' => U_OWNER, 'json' => ['dry' => true]])['json'];
        ok($dry['dry'] === true && pf_paths() === $old, 'בדיקה יבשה אינה כותבת');
        $r = http('POST', '/api.php?action=pf_migrate', ['user' => U_OWNER, 'json' => []]);
        eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
        ok((int)db()->query('SELECT COUNT(*) FROM snapshots')->fetchColumn() === $snaps + 1 && $r['json']['snapshot'] > 0, 'נקודת שחזור לפני');
        $after = [draft_row()['json'], code_snapshot(), db()->query('SELECT k, v FROM settings WHERE k NOT IN (\'pf_migrate_last\') ORDER BY k')->fetchAll(), words_snapshot(),
            (int)db()->query('SELECT COUNT(*) FROM versions')->fetchColumn(), (int)db()->query('SELECT COUNT(*) FROM revisions')->fetchColumn()];
        ok($before === $after, 'הטיוטה, קובצי הקוד, ההגדרות, המילים, הגרסאות — לא השתנו');
        $paths = pf_paths();
        foreach (['index.html', 'css/base.css', 'css/theme-style.css', 'css/lexicon.css', 'css/tokens.css', 'css/page.css', 'js/kol-data.js', 'js/page.js', 'js/lexicon.js', 'kol.json'] as $p) {
            ok(isset($paths[$p]), "חסר $p");
            ok(strlen(pf_get((int)$paths[$p], U_OWNER)) > 0 || $p === 'css/theme-style.css', "$p נפתח");
        }
        ok(isset($paths['from-current/lexicon.html']), 'from-current/ נשאר');
        eq(true, http('GET', '/api.php?action=pf_tree', ['user' => U_OWNER])['json']['kol']['valid'], 'kol.json תקין');
        // audit 1.2, שורות 1–9: כל שכבה היא קובץ בפרויקט, או מסומנת "מוסר" ב-09 §2
        $layers = [1 => 'index.html', 2 => 'css/lexicon.css', 3 => 'js/lexicon.js', 4 => 'js/kol-data.js', 5 => 'css/base.css', 6 => null, 7 => 'css/theme-style.css', 8 => 'from-current/imported.css', 9 => null];
        foreach ($layers as $n => $p) ok($p === null || isset($paths[$p]), "שכבה $n של audit 1.2 אינה קובץ בפרויקט");
        eq(200, http('GET', '/api.php?action=pf_migrate_last', ['user' => U_OWNER])['status']);
        // הרצה חוזרת: הקודם לסל
        $id1 = $paths['index.html'];
        $r2 = http('POST', '/api.php?action=pf_migrate', ['user' => U_OWNER, 'json' => []])['json'];
        ok($r2['trashed'] >= 10, 'הפרויקט הקודם עבר לסל');
        $paths2 = pf_paths();
        ok($paths2['index.html'] !== $id1, 'index.html חדש');
        ok(in_array($id1, array_column(http('GET', '/api.php?action=pf_trash', ['user' => U_OWNER])['json']['items'] ?? [], 'id')), 'הקודם בסל');
        // פרויקט ריק
        eq(403, http('POST', '/api.php?action=pf_blank', ['user' => U_EDITOR, 'json' => []])['status']);
        $r3 = http('POST', '/api.php?action=pf_blank', ['user' => U_OWNER, 'json' => []]);
        eq(200, $r3['status']);
        $paths3 = pf_paths();
        ok(isset($paths3['index.html']) && !isset($paths3['css/lexicon.css']) && isset($paths3['from-current/lexicon.html']), 'פרויקט ריק: השלד בלבד');
        ok(str_contains(pf_get((int)$paths3['index.html'], U_OWNER), '<h1>קול מרוקו</h1>'), 'index.html של השלד');
    } finally { $done(); }
});

test('הסבה: data/words.json נושא את המזהים שהכרטיס נקשר אליהם', function () {
    $done = mg_fixture();
    try {
        $j = json_decode(pv_words('/'), true);
        $by = array_column($j['items'], null, 'id');
        eq(501, $by[9001]['wp_id'] ?? null);
        eq('X1', $by[9001]['external_id'] ?? null);
        ok(!array_key_exists('wp_id', $by[9003]) && !array_key_exists('external_id', $by[9003]), 'מילה בלי מזהה — בלי השדה');
        eq(['greet', 'food'], $by[9002]['topics']);
    } finally { $done(); }
});
