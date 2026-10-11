<?php
// נתב לשרת הדוגמה של api-check: קבצים קיימים — כרגיל. /recipe/<שם>/ — דף מתכון
// מסומן (JSON-LD) בשם מהכתובת, בלי תמונה; /news/<שם>/ — כתבה בלי מתכון (כמו
// הכתבות שפודיס מערבב במפת האתר). __trashed — לא קיים (404).
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (is_file(__DIR__ . $path)) return false;
if (preg_match('~^/(recipe|news)/([^/]+)/$~u', $path, $m) && !str_contains($m[2], '__trashed')) {
    $title = htmlspecialchars(str_replace('-', ' ', preg_replace('/-\d+$/', '', $m[2])));
    header('Content-Type: text/html; charset=utf-8');
    if ($m[1] === 'news') {
        echo "<!doctype html><html lang=\"he\"><head><title>$title</title><meta property=\"og:title\" content=\"$title\"></head>",
             "<body><article><h1>$title</h1><p>כתבה על השוק, בלי מתכון.</p><h2>תגובות</h2></article></body></html>";
        return true;
    }
    $ld = json_encode(['@context' => 'https://schema.org', '@type' => 'Recipe', 'name' => html_entity_decode($title),
                       'recipeIngredient' => ['2 כוסות קמח', '3 ביצים'], 'recipeInstructions' => 'מערבבים ואופים.'], JSON_UNESCAPED_UNICODE);
    echo "<!doctype html><html lang=\"he\"><head><title>$title</title><script type=\"application/ld+json\">$ld</script></head><body><h1>$title</h1></body></html>";
    return true;
}
http_response_code(404);
echo 'not found';
return true;
