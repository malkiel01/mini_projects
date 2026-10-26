<?php
// זמני: בדיקה בלבד — האם ה-crontab גדל בגלל כפילות. מסתיר מיילים.
$home = rtrim(getenv('HOME') ?: '/home2/mbeplusc', '/');
$mask = fn($s) => preg_replace('/[^\s=@]+@[^\s]+/', '<mail>', $s);
echo json_encode(['now' => explode("\n", $mask((string)shell_exec('crontab -l 2>/dev/null'))),
    'before' => explode("\n", $mask((string)@file_get_contents("$home/crontab.before-family-move")))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
