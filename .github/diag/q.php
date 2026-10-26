<?php
// זמני: לקראת "תיבת-מייל" — האם יש ext-imap, ואפשר להגיע ל-IMAP של השרת. בלי נתוני משתמשים.
$out = ['php' => PHP_VERSION, 'imap_ext' => extension_loaded('imap'), 'imap_open' => function_exists('imap_open'),
    'mailparse' => extension_loaded('mailparse'), 'iconv' => extension_loaded('iconv'), 'openssl' => extension_loaded('openssl')];
foreach (['mail.mbe-plus.com', 'localhost', gethostname()] as $h) {
    $t = microtime(true);
    $s = @stream_socket_client("ssl://$h:993", $en, $es, 6, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]));
    $line = $s ? trim((string)fgets($s, 512)) : null;
    $out['imap993'][$h] = $s ? ['ok' => true, 'banner' => substr($line, 0, 80), 'ms' => (int)((microtime(true) - $t) * 1000)] : ['ok' => false, 'err' => "$en $es"];
    if ($s) fclose($s);
}
$out['hostname'] = gethostname();
$out['mx'] = @dns_get_record('mbe-plus.com', DNS_MX);
$out['mail_a'] = @gethostbyname('mail.mbe-plus.com');
echo json_encode($out, JSON_UNESCAPED_UNICODE);
