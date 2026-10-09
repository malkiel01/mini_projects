<?php
// חלק של דפים — לא נקרא כלל, כי יש חלק עם "recipe" בשם.
header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0"?><urlset><url><loc>http://' . $_SERVER['HTTP_HOST'] . '/about/</loc></url></urlset>';
