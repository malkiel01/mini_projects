<?php
/**
 * אוסף מסמכי הבדיקה (tests/corpus/*.json) — נוצר מכאן, כדי שיהיה אפשר לשנות ולהוסיף בלי לערוך JSON ביד.
 *   php word-processor/tests/corpus/make.php
 * כל קובץ: {title, json, page, known?, known_pdf?}. known — הבדל ידוע בין העורך לבין ה-PDF וההדפסה; known_pdf — רק מול
 * ה-PDF מהשרת. בכל אחד: מה ההבדל ומי מטפל בו. בדיקת ההשוואה מדווחת עליו (⚠) ולא נכשלת — וכשהוא נעלם, צריך להסיר אותו.
 * הטקסט דטרמיניסטי (מחולל קבוע) — אותו מסמך בכל הרצה.
 */
if (PHP_SAPI !== 'cli') exit;

$seed = 7;
$rand = function (int $n) use (&$seed): int { $seed = ($seed * 1103515245 + 12345) & 0x7fffffff; return $seed % $n; };
$words = explode(' ', 'העמותה פועלת למען הקהילה בתחומי חינוך רווחה ותרבות ומקיימת פעילות שוטפת לאורך כל השנה בשיתוף '
    . 'מתנדבים רבים אנשי מקצוע ותורמים נאמנים התקציב המוצע מבוסס על ניסיון השנים הקודמות ועל יעדים חדשים שהוגדרו '
    . 'בישיבת ההנהלה האחרונה כולל הרחבת השירותים לשכונות נוספות ופתיחת מרכז למידה לנוער 2026 שקלים בשנה');
$sentence = function (int $min, int $max) use ($rand, $words): string {
    $n = $min + $rand($max - $min + 1);
    $out = [];
    for ($i = 0; $i < $n; $i++) $out[] = $words[$rand(count($words))];
    return implode(' ', $out) . '.';
};
$text = fn(string $t, array $marks = []) => ['type' => 'text', 'text' => $t] + ($marks ? ['marks' => $marks] : []);
$p = fn(string $t, array $attrs = [], array $marks = []) => ['type' => 'paragraph'] + ($attrs ? ['attrs' => $attrs] : []) + ['content' => [$text($t, $marks)]];
$hd = fn(string $t, int $level) => ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => [$text($t)]];
$li = fn(string $t) => ['type' => 'listItem', 'content' => [$p($t)]];
$doc = fn(array $content) => ['type' => 'doc', 'content' => $content];

$corpus = [];

// 1. טקסט ארוך עם כותרות — שבירה באמצע פסקה, שורות יתומות, כותרת לא נשארת לבד בתחתית
$c = [$hd('דוח שנתי', 1)];
for ($i = 1; $i <= 45; $i++) {
    if ($i % 8 === 0) $c[] = $hd("פרק $i", 2);
    $c[] = $p("$i. " . $sentence(20, 90));
}
$corpus['long-text'] = ['title' => 'טקסט ארוך', 'json' => $doc($c), 'page' => null,
    'known_pdf' => 'שורה בתחתית עמוד: העורך מודד שורה לפי גובה האותיות ולא לפי גובה השורה המלא, וה-PDF שובר שורה אחת קודם — לטיפול: יישור מנוע ה-PDF והעורך'];

// 2. רשימות ממוספרות ותבליטים, כולל מקוננות
$c = [$hd('רשימות', 1)];
for ($k = 0; $k < 4; $k++) {
    $items = [];
    for ($i = 0; $i < 9; $i++) {
        $item = $li($sentence(6, 40));
        if ($i % 4 === 3) $item['content'][] = ['type' => 'bulletList', 'content' => [$li($sentence(5, 20)), $li($sentence(5, 20))]];
        $items[] = $item;
    }
    $c[] = ['type' => $k % 2 ? 'bulletList' : 'orderedList', 'content' => $items];
    $c[] = $p($sentence(20, 50));
}
$corpus['lists'] = ['title' => 'רשימות', 'json' => $doc($c), 'page' => null];

// 3. מעברי עמוד ידניים, ריווח שורות ורווח לפני/אחרי
$c = [];
for ($s = 1; $s <= 4; $s++) {
    $c[] = $hd("חלק $s", 1);
    for ($i = 0; $i < 5 + $s; $i++) $c[] = $p($sentence(15, 60), ['lineHeight' => [1, 1.15, 1.5, 2][$s - 1], 'spaceAfter' => 6 * $s]);
    if ($s < 4) $c[] = ['type' => 'pageBreak'];
}
$corpus['breaks-spacing'] = ['title' => 'מעברי עמוד וריווח', 'json' => $doc($c), 'page' => null,
    'known_pdf' => 'ריווח שורות 2: ה-PDF עוטף שורה מילה אחת לפני העורך — לטיפול: יישור מנוע ה-PDF והעורך'];

// 4. גדלי גופן שונים בתוך פסקאות
$c = [$hd('גדלים', 1)];
foreach ([10, 12, 14, 18, 11, 16, 9, 13] as $i => $size) {
    for ($k = 0; $k < 3; $k++) $c[] = $p($sentence(20, 60), [], [['type' => 'textStyle', 'attrs' => ['fontSize' => $size . 'pt']]]);
}
$corpus['font-sizes'] = ['title' => 'גדלי גופן', 'json' => $doc($c), 'page' => null];

// 5. Letter לרוחב, שוליים צרים, גופן אחר
$c = [$hd('לרוחב', 1)];
for ($i = 0; $i < 30; $i++) $c[] = $p($sentence(25, 80));
$corpus['landscape'] = ['title' => 'Letter לרוחב', 'json' => $doc($c),
    'page' => ['size' => 'Letter', 'orientation' => 'landscape', 'margins' => ['top' => 15, 'bottom' => 15, 'right' => 15, 'left' => 15], 'font' => ['family' => 'Frank Ruehl', 'size' => 13]],
    'known_pdf' => 'Frank Ruehl 13: ה-PDF יוצא עמוד ארוך יותר מהעורך — לטיפול: יישור מנוע ה-PDF והעורך'];

// 6. טבלה ארוכה עם שורת כותרת
$rows = [['type' => 'tableRow', 'content' => array_map(fn($h) => ['type' => 'tableHeader', 'content' => [$p($h)]], ['סעיף', 'תיאור', 'סכום'])]];
for ($i = 1; $i <= 70; $i++) {
    $rows[] = ['type' => 'tableRow', 'content' => [
        ['type' => 'tableCell', 'content' => [$p((string)$i)]],
        ['type' => 'tableCell', 'content' => [$p($sentence(3, 14))]],
        ['type' => 'tableCell', 'content' => [$p(number_format(1000 + $rand(90000)))]],
    ]];
}
$corpus['long-table'] = ['title' => 'טבלה ארוכה', 'json' => $doc([$hd('תקציב', 1), $p($sentence(10, 20)), ['type' => 'table', 'content' => $rows], $p($sentence(10, 30))]),
    'page' => null];

// 7. מקטעים (אב-טיפוס): לאורך → עמוד לרוחב עם טבלה רחבה → חזרה לאורך
$wide = [['type' => 'tableRow', 'content' => array_map(fn($h) => ['type' => 'tableHeader', 'content' => [$p($h)]], ['חודש', 'הכנסות', 'הוצאות', 'יתרה', 'הערות'])]];
for ($i = 1; $i <= 12; $i++) {
    $wide[] = ['type' => 'tableRow', 'content' => array_map(fn($v) => ['type' => 'tableCell', 'content' => [$p($v)]],
        ["חודש $i", number_format(10000 + $rand(50000)), number_format(5000 + $rand(30000)), number_format($rand(20000)), $sentence(3, 8)])];
}
$c = [$hd('מקטעים', 1)];
for ($i = 0; $i < 8; $i++) $c[] = $p($sentence(20, 60));
$c[] = ['type' => 'sectionBreak', 'attrs' => ['page' => ['orientation' => 'landscape']]];
$c[] = $hd('נספח: טבלה רחבה', 2);
$c[] = ['type' => 'table', 'content' => $wide];
$c[] = ['type' => 'sectionBreak', 'attrs' => ['page' => ['orientation' => 'portrait']]];
$c[] = $hd('סיכום', 2);
for ($i = 0; $i < 6; $i++) $c[] = $p($sentence(20, 60));
$corpus['sections'] = ['title' => 'מקטעים', 'json' => $doc($c), 'page' => null];

// 8. הערות שוליים (אב-טיפוס): פסקאות עם הערות קצרות וארוכות — חלקן בתחתית עמוד, שם ההערה דוחפת שורה לעמוד הבא
$fn = fn(string $t) => ['type' => 'footnote', 'attrs' => ['text' => $t]];
$c = [$hd('הערות שוליים', 1)];
for ($i = 1; $i <= 30; $i++) {
    $parts = [$text($sentence(15, 50))];
    if ($i % 3 === 0) { $parts[] = $fn("הערה $i: " . $sentence(4, $i % 2 ? 40 : 10)); $parts[] = $text(' ' . $sentence(5, 20)); }
    $c[] = ['type' => 'paragraph', 'content' => $parts];
}
$corpus['footnotes'] = ['title' => 'הערות שוליים', 'json' => $doc($c), 'page' => null,
    'known_pdf' => 'ההערות בתחתית העמוד גם ב-PDF; השבירה זזה בפסקה — אותם הבדלי מדידת שורה כמו ב"טקסט ארוך" — לטיפול: יישור מנוע ה-PDF והעורך'];

foreach ($corpus as $name => $d) {
    file_put_contents(__DIR__ . "/$name.json", json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    echo "$name.json\n";
}
