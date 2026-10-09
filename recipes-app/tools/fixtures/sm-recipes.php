<?php
// חלק המתכונים: סלאגים בעברית (כמו 10dakot), כתובות זבל שצריכות להיפסל, ודף אמיתי לפתיחה.
$gen = ($f = getenv('FX_STATE')) ? ((int) @file_get_contents($f) ?: 1) : 1;
$base = 'http://' . $_SERVER['HTTP_HOST'];
$urls = [
    '/recipe/' . rawurlencode('עוגת-גבינה-אפויה') . '/',
    '/recipe/' . rawurlencode('עוגות-גבינה-קרות-2') . '/',
    '/jsonld-carine.html',
    '/recipe/__trashed-3/',                         // בלי שם — לא נכנס
    "/recipe/' + product_url + '",                  // תבנית שלא רונדרה — לא נכנס
    '/foody_ingredient/' . rawurlencode('קמח') . '/',   // דף רכיב — לא נכנס
];
if ($gen < 2) $urls[] = '/recipe/' . rawurlencode('עוגת-שוקולד-של-סבתא') . '/';   // בדור 2 הוסר מהאתר
header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($urls as $u) echo '  <url><loc>', htmlspecialchars($base . $u, ENT_XML1), "</loc><lastmod>2026-09-28T11:19:45+00:00</lastmod></url>\n";
echo "</urlset>\n";
