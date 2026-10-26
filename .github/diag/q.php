<?php
// זמני: יצירת תיבת הדואר demo@mbe-plus.com — ניסיון 2: פלט גולמי של uapi (בלי הסיסמה), ואם התיבה כבר קיימת
// בלי הקובץ הפרטי — איפוס סיסמה ושמירה. הסיסמה לא יוצאת מהשרת.
$out = [];
$dir = rtrim(getenv('HOME') ?: '', '/') . '/.mailbox';
$file = "$dir/demo.json";
$run = function (string $args) use (&$out) {
    $raw = (string)shell_exec('uapi --output=json ' . $args . ' 2>&1');
    $j = json_decode($raw, true);
    if (!is_array($j)) { $s = strpos($raw, '{'); $j = $s !== false ? json_decode(substr($raw, $s), true) : null; }
    return [$j, substr(preg_replace('/password=\S+/', 'password=***', $raw), 0, 600)];
};
[$list, $rawList] = $run('Email list_pops');
$emails = array_column($list['result']['data'] ?? [], 'email');
$out['list_ok'] = is_array($list);
$out['list_raw_head'] = is_array($list) ? null : $rawList;
$exists = in_array('demo@mbe-plus.com', $emails, true);
$out['exists_before'] = $exists;
$out['home'] = getenv('HOME');
if (is_file($file)) {
    $out['saved'] = "$file (קיים כבר)";
} else {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $pw = '';
    for ($i = 0; $i < 22; $i++) $pw .= $abc[random_int(0, strlen($abc) - 1)];
    $pw .= '!' . random_int(10, 99) . 'Kq';
    [$r, $raw] = $exists
        ? $run('Email passwd_pop email=demo domain=mbe-plus.com password=' . escapeshellarg($pw))
        : $run('Email add_pop email=demo domain=mbe-plus.com quota=2048 password=' . escapeshellarg($pw));
    $out['action'] = $exists ? 'passwd_pop' : 'add_pop';
    $out['status'] = $r['result']['status'] ?? null;
    $out['errors'] = $r['result']['errors'] ?? null;
    $out['raw_head'] = is_array($r) ? null : str_replace($pw, '***', $raw);
    if ((int)($r['result']['status'] ?? 0) === 1) {
        @mkdir($dir, 0700, true);
        file_put_contents($file, json_encode(['email' => 'demo@mbe-plus.com', 'imap_host' => 'box2054.bluehost.com', 'imap_port' => 993, 'password' => $pw, 'created_at' => gmdate('c')], JSON_PRETTY_PRINT));
        chmod($file, 0600);
        $out['saved'] = $file;
        sleep(3);
        $mb = @imap_open('{box2054.bluehost.com:993/imap/ssl}INBOX', 'demo@mbe-plus.com', $pw, OP_READONLY, 1);
        $out['imap_login'] = $mb ? 'ok' : imap_last_error();
        if ($mb) { $out['messages'] = imap_num_msg($mb); imap_close($mb); }
    }
    unset($pw);
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
