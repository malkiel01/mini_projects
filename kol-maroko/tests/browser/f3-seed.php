<?php
/**
 * זריעה לבדיקות הדפדפן של F3:   php f3-seed.php <תיקיית הנתונים הזמנית> <קובץ הפלט של הרינדור הישן>
 * ייבוא מדומה מהאתר (CSS בסגנון ערכת הנושא — dev/spike/fixtures/lexicon.base.css), 1,496 מילים (1,494 עם הקלטה),
 * שישה נושאים, ורכיב מילון בטיוטה עם כל ערכי הפאנל. כותב גם את "העמוד של היום" כפי שהכלי הישן מרנדר אותו
 * (lexicon_render, מסלול האתר: כל המילים) — זה מה שרתמת הנאמנות משווה אליו. דטרמיניסטי.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc < 3 || !is_dir($argv[1])) exit(1);
define('KOL_DATA_DIR', $argv[1]);
$root = dirname(__DIR__, 2);
if (realpath(KOL_DATA_DIR) === realpath($root . '/data')) exit("לא על data/\n");
foreach (['members', 'import', 'ai', 'agent', 'dev', 'preview', 'migrate'] as $lib) require_once "$root/lib/$lib.php";

$pdo = db();
$themeLex = (string)file_get_contents("$root/dev/spike/fixtures/lexicon.base.css");
$themeStyle = "/* חברה קדישא המערבית - CSS (קובץ בדיקה) */\n:root { --ka-font-body: 'Assistant', sans-serif; --ka-font-title: 'Frank Ruhl Libre', serif; }\n"
    . ".kadisha-page { font-family: var(--ka-font-body); color: #1b1b1b; background: #fbf8f1; }\n.kadisha-page h1, .kadisha-page h2, .kadisha-page h3 { font-family: var(--ka-font-title); margin: 0 0 .4em; }\n"
    . ".kadisha-page p { margin: 0 0 1em; }\n.kadisha-page button { font: inherit; cursor: pointer; }\n";
setting_set('theme_lexicon_css', $themeLex);
setting_set('theme_style_css', $themeStyle);
setting_set('preview_css', $themeStyle . $themeLex);
setting_set('preview_js', "/* lexicon.js של ערכת הנושא — לא זמין לצוות; קובץ בדיקה */\n");

$seed = 20261111;
$rnd = function (int $n) use (&$seed): int { $seed = ($seed * 1103515245 + 12345) & 0x7fffffff; return $seed % $n; };
$he = ['בְּסְלָאמָה', 'לְבְּרָכָּה', 'זוהרה', 'מליחה', 'סעודה', 'חביבי', 'לעזיז', 'כסכסו', 'מימונה', 'תפילה', 'שבת'];
$ar = ['بسلامة', 'البركة', 'الزهرة', 'مليحة', 'سعودة', 'حبيبي'];
$la = ['bslama', 'lbaraka', 'Zohra', 'mliha', "s'ouda", 'k&sksu'];
$topics = ['ברכות', 'אוכל', 'משפחה', 'חגים', 'בית הכנסת', 'פתגמים'];
foreach ($topics as $i => $n) $pdo->prepare('INSERT OR IGNORE INTO topics (slug, name) VALUES (?, ?)')->execute(['topic-' . ($i + 1), $n]);
$ins = $pdo->prepare('INSERT INTO words (id, wp_id, word, meaning, topics, audio, external_id, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$pdo->beginTransaction();
for ($i = 1; $i <= 1496; $i++) {
    $w = $he[$rnd(count($he))] . ' ' . $i;
    $l = $la[$rnd(count($la))];
    $ts = [];
    for ($k = 0, $nt = $rnd(3); $k < $nt; $k++) $ts[$topics[($i + $k * 2) % 6]] = true;
    $meaning = '<p>' . e("פירוש המילה $w — $l") . ' <span lang="ar">' . $ar[$rnd(count($ar))] . '</span>.</p>' . ($i % 7 === 0 ? "\n<p>דוגמה: <em>נאמר בכניסה לבית</em>&nbsp;ועוד.</p>" : '');
    $audio = $i === 500 || $i === 1000 ? '' : "https://maghreb.co.il/wp-content/uploads/lexicon/$i.mp3";
    $ins->execute([$i, $i % 11 === 0 ? null : 7000 + $i, $w, $meaning, json_encode(array_keys($ts), JSON_UNESCAPED_UNICODE), $audio, $i % 5 === 0 ? "E$i" : '', '2026-11-11T00:00:00Z']);
}
$pdo->commit();

$page = page_normalize(lexicon_page(['settings' => ['page_title_he' => 'קול מרוקו', 'page_subtitle_main_he' => 'מילון הערבית־היהודית של מרוקו', 'page_title_fr' => 'La Voix du Maroc', 'page_title_en' => 'The Voice of Morocco',
    'page_subtitle_he' => 'מילים, פתגמים וברכות — עם ההגייה', 'page_credit_he' => 'אסף ותיעד: בעל העמוד', 'title_size_px' => 60, 'subtitle_size_px' => 22, 'title_en_size_px' => 24, 'title_weight' => 700,
    'hero_min_height' => 420, 'intro_max_width_px' => 780],
    'hero_image' => 'https://maghreb.co.il/wp-content/uploads/hero.jpg', 'hero_overlay' => 45, 'feedback' => true,
    'intro_html' => "<p>פסקת ההקדמה של המילון: מה יש בו ואיך נאסף.</p><!--more--><p>המבוא המלא: על ההגייה, על הכתיב ועל הנושאים.</p><p>פסקה שנייה במבוא.</p>"], 'קול מרוקו — המילון'));
draft_row();                                                 // יוצר את שורת הטיוטה אם אין
$pdo->prepare('UPDATE draft SET json = ?')->execute([page_json($page)]);

// "העמוד של היום": הרינדור של רכיב המילון בכלי הישן (lexicon_render, מסלול האתר: כל המילים, הגדלים ב-!important),
// עם ה-CSS של ערכת הנושא שהאתר טוען לעמוד הזה. בלי העטיפות של הסטודיו (.kol-row / .kol-col) — הן אינן חלק מהעמוד החי,
// שהוא תבנית של ערכת הנושא. box-sizing ו-line-height: מה שהאתר (Astra) נותן לכל עמוד, ומה ש-css/base.css כותב במפורש.
page_render($page, false, fn(array $f) => 'file/' . $f['id']);          // מכין את ההקשר של הקבצים
$GLOBALS['kol_files'] ??= [];
$GLOBALS['kol_url'] ??= fn(array $f) => 'file/' . $f['id'];
$w = $page['sections'][0]['columns'][0]['widgets'][0];
[$html, $css] = lexicon_render($w, false, 'kol-w', '');
// ההכהיה: הכלי הישן כותב את האחוז כמו שהוא (--ka-hero-overlay:45), ו-rgba(…, 45) נחתך ל-1 — כיסוי מלא, התמונה לא נראית.
// זה באג של הכלי, שההסבה מתקנת (mg_overlay: 45 ← 0.45) ושהבעלים מתקן באתר (מטלה T14, "תקן ל-0.45"). הייחוס הוא העמוד
// כפי שהוא נראה אחרי התיקון, ולכן כאן אותו יחס — אחרת ההשוואה מודדת את הבאג ולא את ההסבה.
$fixed = str_replace('--ka-hero-overlay:45;', '--ka-hero-overlay:' . mg_overlay(45) . ';', $html, $n);
if ($n !== 1) { fwrite(STDERR, "f3-seed: --ka-hero-overlay:45 לא נמצא פעם אחת בדיוק ברינדור הישן ($n)\n"); exit(1); }
$html = $fixed;
$old = "<!doctype html>\n<html lang=\"he\" dir=\"rtl\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>old</title>\n"
    . '<link rel="stylesheet" href="' . e(LEX_FONTS) . "\">\n<style>*, *::before, *::after { box-sizing: border-box; }\nbody { margin: 0; line-height: 1.6; }\n"
    . ".screen-reader-text { border: 0; clip: rect(1px, 1px, 1px, 1px); clip-path: inset(50%); height: 1px; margin: -1px; overflow: hidden; padding: 0; position: absolute; width: 1px; word-wrap: normal; }\n"
    . "$themeStyle\n$themeLex\n$css</style>\n</head>\n<body>\n$html\n</body>\n</html>\n";
file_put_contents($argv[2], $old);
echo strlen($old), "\n";
