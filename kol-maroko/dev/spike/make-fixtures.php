<?php
/**
 * ספייק V0 — יוצר את קבצי הבדיקה lexicon.html ו-lexicon.css מהתבנית האמיתית (LEX_TPL, LEX_TPL_SIZES)
 * עם 1,496 מילים סינתטיות. דטרמיניסטי: הרצה חוזרת נותנת אותם בייטים. הרצה: php dev/spike/make-fixtures.php
 * (handwritten.* ו-broken.* נכתבו ביד ואינם נוצרים כאן.)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
if (!function_exists('e')) { function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
require __DIR__ . '/../../lib/code.php';

$seed = 20261111;
$rnd = function (int $n) use (&$seed): int { $seed = ($seed * 1103515245 + 12345) & 0x7fffffff; return $seed % $n; };
$he = ['בְּסְלָאמָה', 'לְבְּרָכָּה', 'זְ׳וֹהְרָה', 'מְלִיחָה', 'סְעוּדָה', 'חְבִּיבִּי', 'לְעָזִיז', 'כְּסְכְּסוּ', 'מִימוּנָה', 'תְּפִילָה', 'שְׁבָּת', 'דְּרוֹשׁ'];
$ar = ['بسلامة', 'البركة', 'الزهرة', 'مليحة', 'سعودة', 'حبيبي', 'العزيز', 'كسكسو'];
$la = ['bslama', 'lbaraka', 'zohra', 'mliha', "s'ouda", 'hbibi', 'la3ziz', 'k&sksu'];
$topicNames = ['ברכות', 'אוכל', 'משפחה', 'חגים', 'בית הכנסת', 'פתגמים'];
$topics = [];
foreach ($topicNames as $i => $n) $topics[] = ['slug' => 'topic-' . ($i + 1), 'name' => $n, 'count' => 0];
$words = [];
for ($i = 1; $i <= 1496; $i++) {
    $w = $he[$rnd(count($he))] . ' ' . $i;
    $a = $ar[$rnd(count($ar))];
    $l = $la[$rnd(count($la))];
    $nt = $rnd(3);
    $ts = [];
    for ($k = 0; $k < $nt; $k++) { $t = ($i + $k * 2) % count($topics); $ts[$t] = ['name' => $topics[$t]['name']]; $topics[$t]['count']++; }
    $slugs = implode(' ', array_map(fn($t) => 'topic-' . ($t + 1), array_keys($ts)));
    $meaning = '<p>' . e("פירוש המילה $w — $l") . ' <span dir="rtl" lang="ar">' . e($a) . '</span>.</p>'
        . ($i % 7 === 0 ? "\n<p>דוגמה: <em>" . e("«{$w}» נאמר בכניסה לבית") . '</em>&nbsp;<a href="https://example.org/w/' . $i . '">מקור</a></p>' : '');
    $words[] = ['id' => $i, 'data_id' => 'w' . $i, 'word' => $w, 'search' => mb_strtolower("$w $l $a"), 'search_full' => mb_strtolower("$w $l $a פירוש"),
        'slugs' => $slugs, 'topics' => array_values($ts), 'has_topics' => $nt > 0, 'single' => $nt === 1, 'meaning' => $meaning,
        'audio' => $i % 3 ? 'audio/w' . $i . '.mp3' : '', 'ext_id' => $i % 5 === 0 ? (string)(1000 + $i) : '', 'feedback_id' => $i];
}
$ctx = [
    'title_he' => 'קול מרוקו', 'subtitle_main_he' => 'מילון המילים והביטויים', 'title_fr' => 'La voix du Maroc', 'title_en' => 'The Voice of Morocco',
    'subtitle_he' => 'אוצר הלשון של יהודי מרוקו', 'credit_he' => 'בעריכת צוות המילון', 'eyebrow' => true,
    'hero' => 'media/hero.jpg', 'hero_overlay' => '0.45', 'has_gates' => true,
    'gates' => [
        ['i' => 1, 'label' => 'על המילון', 'html' => "<p>המילון נאסף מפי דוברים.</p>\n<p>כל מילה מוקלטת.</p>", 'more' => true],
        ['i' => 2, 'label' => 'איך משתמשים', 'html' => '<p>מחפשים מילה, או בוחרים נושא.</p>', 'more' => false],
    ],
    'more_label' => 'קרא עוד', 'less_label' => 'סגור', 'intro_style' => 'max-width:820px', 'feedback' => true,
    'has_topics' => true, 'topics' => $topics, 'count' => count($words), 'empty' => false, 'words' => $words, 'preview_note' => '',
];
$body = tpl_render(LEX_TPL, $ctx);
$html = "<!doctype html>\n<html lang=\"he\" dir=\"rtl\">\n<head>\n  <meta charset=\"utf-8\">\n  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
    . "  <title>קול מרוקו — המילון</title>\n  <link rel=\"stylesheet\" href=\"lexicon.css\">\n</head>\n<body>\n" . $body . "\n</body>\n</html>\n";
file_put_contents(__DIR__ . '/fixtures/lexicon.html', $html);

$sizes = tpl_render(LEX_TPL_SIZES, ['hero_min_css' => 'min-height: 420px;', 'title_size' => 64, 'title_weight' => 700, 'subtitle_size' => 22, 'credit_size' => 16,
    'main_size' => 40, 'title_en_size' => 15, 'm_title_size' => 38, 'm_main_size' => 26, 'm_subtitle_size' => 19, 'm_title_en_size' => 12, 'm_credit_size' => 14]);
$base = file_get_contents(__DIR__ . '/fixtures/lexicon.base.css');
file_put_contents(__DIR__ . '/fixtures/lexicon.css', $base . "\n" . LEX_CSS_MARK . "\n" . $sizes . "\n");
printf("lexicon.html %d bytes, %d cards · lexicon.css %d bytes\n", strlen($html), substr_count($html, '<article class="kadisha-word-card"'), strlen($base . $sizes));
