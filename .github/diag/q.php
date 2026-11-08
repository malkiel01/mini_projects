<?php
// זמני, קריאה בלבד — 5 השורות האחרונות בלוג ה-cron של ההתראות, ושורות ה-crontab המסומנות שלנו.
$f = 'trading-strategies/data/alerts-cron.log';
$log = is_readable($f) ? array_slice(file($f, FILE_IGNORE_NEW_LINES), -5) : null;
$tab = array_values(array_filter(preg_split('/\n/', (string)shell_exec('crontab -l 2>&1')), fn($l) => str_contains($l, '# mini_projects:')));
echo json_encode(['log_exists' => is_file($f), 'log_size' => is_file($f) ? filesize($f) : 0, 'log_tail' => $log,
  'tagged' => array_map(fn($l) => preg_replace('#/home\d*/[^/ ]+#', '~', $l), $tab)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
