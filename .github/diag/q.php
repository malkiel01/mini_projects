<?php
// זמני: מה זמין לכלי חדש בשרת — PHP, מגבלות, הרחבות, Node, HTTPS החוצה, נפח. בלי נתוני משתמשים.
$sh = fn($c) => trim((string)@shell_exec($c . ' 2>&1'));
$out = [
    'php' => PHP_VERSION, 'sapi_cli' => PHP_SAPI,
    'ini_cli' => array_combine($k = ['upload_max_filesize','post_max_size','memory_limit','max_execution_time','max_input_time','allow_url_fopen','disable_functions'], array_map('ini_get', $k)),
    'ext' => array_values(array_intersect(get_loaded_extensions(), ['pdo_sqlite','sqlite3','pdo_mysql','mysqli','curl','openssl','sodium','gd','imagick','fileinfo','zip','mbstring','intl','exif','xml','dom'])),
    'sqlite' => class_exists('SQLite3') ? SQLite3::version()['versionString'] : null,
    'node' => $sh('command -v node; node -v'), 'npm' => $sh('command -v npm'), 'python3' => $sh('python3 -V'),
    'ffmpeg' => $sh('command -v ffmpeg'), 'crontab' => $sh('crontab -l | head -3 | sed "s/[A-Za-z0-9]\{20,\}/…/g"'),
    'disk' => $sh('df -h . | tail -1'), 'quota' => $sh('quota -s 2>/dev/null | tail -2'),
    'du_platform_data' => $sh('du -sh platform/data | cut -f1'),
];
// מגבלות ה-PHP של השרת (לא CLI): מה .user.ini / php.ini של האתר קובעים
foreach (['.user.ini', 'php.ini', '.htaccess'] as $f) if (is_file($f)) $out['root_' . $f] = array_values(array_filter(file($f, FILE_IGNORE_NEW_LINES), fn($l) => preg_match('/upload|post_max|memory|php_value|execution/i', $l)));
$ch = curl_init('https://api.anthropic.com/v1/models'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_NOBODY => true]);
curl_exec($ch); $out['https_out_anthropic'] = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: curl_error($ch);
$ch = curl_init('https://maghreb.co.il/'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_NOBODY => true]);
curl_exec($ch); $out['https_out_maghreb'] = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: curl_error($ch);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
