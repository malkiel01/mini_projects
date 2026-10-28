<?php
// זמני: קניות משותפות — הרצת המיגרציות (016 יחסיות לקנייה, 017 מטבע זר; תוספות בלבד) באישור בעל המערכת.
// db/migrate.php אידמפוטנטי: מה שכבר בוצע — מדולג. מיילים מוסתרים בפלט.
chdir('family-shopping');
$php = PHP_BINARY ?: '/usr/local/bin/php';
exec(escapeshellarg($php) . ' db/migrate.php 2>&1', $o, $rc);
$out = preg_replace('/[^\s"@]+@[^\s"]+/', '<mail>', implode("\n", $o));
echo json_encode(['rc' => $rc, 'out' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
