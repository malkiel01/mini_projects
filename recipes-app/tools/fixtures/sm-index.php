<?php
// אינדקס sitemaps כמו של אתר WordPress (api-check, 8יא). הדור — מקובץ שהבדיקה כותבת
// (FX_STATE), כדי לדמות עדכון שבועי: דור 2 משנה lastmod ומוריד מתכון אחד.
$gen = ($f = getenv('FX_STATE')) ? ((int) @file_get_contents($f) ?: 1) : 1;
$base = 'http://' . $_SERVER['HTTP_HOST'];
header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
echo "  <sitemap><loc>$base/sm-pages.php</loc><lastmod>2026-01-01T00:00:00+00:00</lastmod></sitemap>\n";
echo "  <sitemap><loc>$base/sm-recipes.php</loc><lastmod>2026-10-0{$gen}T00:00:00+00:00</lastmod></sitemap>\n";
echo "</sitemapindex>\n";
