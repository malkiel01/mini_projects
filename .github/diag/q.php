<?php
// זמני: יצירת תיבת הדואר demo@mbe-plus.com לאפליקציית תיבת-מייל (בקשת בעל המערכת, 2026-10-26).
// הסיסמה נוצרת כאן, נשמרת בקובץ פרטי מחוץ לתיקיית האתר (~/.mailbox/demo.json, 600), ולא יוצאת מהשרת —
// הפלט (מוצפן ממילא) מכיל רק "נשמר" ואת הנתיב.
$out = [];
$home = getenv('HOME') ?: dirname(getcwd(), 1);
$dir = rtrim($home, '/') . '/.mailbox';
$file = "$dir/demo.json";
$uapi = trim((string)shell_exec('command -v uapi'));
$out['uapi'] = $uapi ?: null;
$exists = false;
if ($uapi) {
    $list = json_decode((string)shell_exec('uapi --output=json Email list_pops regex=' . escapeshellarg('^demo@mbe-plus\.com$') . ' 2>&1'), true);
    $exists = !empty($list['result']['data']);
    $out['existed'] = $exists;
}
if ($uapi && !$exists && !is_file($file)) {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $pw = '';
    for ($i = 0; $i < 22; $i++) $pw .= $abc[random_int(0, strlen($abc) - 1)];
    $pw .= '!' . random_int(10, 99) . 'Kq';
    $r = json_decode((string)shell_exec('uapi --output=json Email add_pop email=demo domain=mbe-plus.com quota=2048 password=' . escapeshellarg($pw) . ' 2>&1'), true);
    $out['created'] = (int)($r['result']['status'] ?? 0) === 1;
    $out['errors'] = $r['result']['errors'] ?? null;
    if ($out['created']) {
        @mkdir($dir, 0700, true);
        file_put_contents($file, json_encode(['email' => 'demo@mbe-plus.com', 'imap_host' => 'box2054.bluehost.com', 'imap_port' => 993, 'password' => $pw, 'created_at' => gmdate('c')], JSON_PRETTY_PRINT));
        chmod($file, 0600);
        $out['saved'] = $file;
        // בדיקה שהתיבה נפתחת ב-IMAP עם הסיסמה
        $mb = @imap_open('{box2054.bluehost.com:993/imap/ssl}INBOX', 'demo@mbe-plus.com', $pw, OP_READONLY, 1);
        $out['imap_login'] = $mb ? 'ok' : imap_last_error();
        if ($mb) { $out['messages'] = imap_num_msg($mb); imap_close($mb); }
    }
    unset($pw);
} elseif (is_file($file)) {
    $out['saved'] = $file . ' (קיים כבר)';
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
