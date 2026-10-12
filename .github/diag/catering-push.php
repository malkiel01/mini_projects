<?php
// אבחון התראות של catering-menu — רץ על השרת, קורא בלבד.
// הלוג של Actions ציבורי: לא מדפיסים שמות לקוחות, כתובות מנוי או מפתחות.
$d = getcwd() . '/data';
$j = fn($f) => is_file("$d/$f") ? json_decode((string) file_get_contents("$d/$f"), true) : null;
$t = fn($iso) => $iso ? date('d.m H:i:s', strtotime($iso)) : '—';
$kind = function (string $tag): string {
    foreach (['order-' => 'הזמנה חדשה', 'remind-' => 'תזכורת'] as $p => $k) if (str_starts_with($tag, $p)) return $k;
    return ['nag' => 'ממתינה לאישור', 'digest' => 'סיכום יומי', 'test' => 'בדיקה'][$tag] ?? ($tag ?: '?');
};
echo "=== שעון השרת: " . date('c') . " | PHP " . PHP_VERSION . " (CLI) | curl " . (function_exists('curl_init') ? 'כן' : 'לא') . "\n";

$push = $j('push.json');
$subs = $push['subs'] ?? [];
echo "\n=== מכשירים רשומים: " . count($subs) . "\n";
foreach ($subs as $i => $s) {
    echo "  #$i " . ($s['device'] ?? '?') . " | " . parse_url($s['endpoint'], PHP_URL_HOST)
       . " | נרשם " . $t($s['created'] ?? null) . " | הצלחה אחרונה " . $t($s['lastOk'] ?? null)
       . " | שגיאה אחרונה: " . ($s['lastError'] ?? '—') . "\n";
}

$log = $j('push-log.json') ?? [];
echo "\n=== יומן שליחות (" . count($log) . "):\n";
foreach (array_slice($log, -25) as $e) {
    echo "  " . $t($e['at']) . " | " . $kind($e['tag'] ?? '') . ($e['note'] ? " | " . $e['note'] : '') . "\n";
    foreach ($e['details'] ?? [] as $x) echo "      → " . ($x['device'] ?? '?') . " " . ($x['service'] ?? '') . " קוד " . $x['code'] . ($x['info'] ? " · " . mb_substr($x['info'], 0, 120) : '') . "\n";
}

$rem = $j('reminders.json') ?? [];
$cron = @filemtime("$d/reminders.json.cron");
echo "\n=== תזכורות: תקתוק אחרון " . $t($rem['lastTick'] ?? null) . " (" . ($rem['lastVia'] ?? '—') . ")"
   . " | cron אחרון: " . ($cron ? date('d.m H:i:s', $cron) : 'אף פעם') . " | נשלחו עד היום: " . count($rem['sent'] ?? []) . "\n";
foreach (array_slice($rem['sent'] ?? [], -15, null, true) as $k => $ts) {
    $parts = explode('|', $k);
    echo "  " . date('d.m H:i:s', (int) $ts) . " | " . ($parts[0] === 'digest' || $parts[0] === 'nag' ? $parts[0] : 'תזכורת ' . ($parts[1] ?? '')) . "\n";
}

$orders = glob("$d/orders/*.json") ?: [];
usort($orders, fn($a, $b) => filemtime($b) <=> filemtime($a));
echo "\n=== הזמנות: " . count($orders) . " (האחרונות, בלי שמות):\n";
foreach (array_slice($orders, 0, 8) as $f) {
    $o = json_decode((string) file_get_contents($f), true);
    echo "  נוצרה " . $t($o['createdAt'] ?? null) . " | " . ($o['approval'] ?? '?') . " | מועד " . ($o['customer']['deliveryAt'] ?? '') . "\n";
}

foreach (['error_log', 'admin/error_log'] as $f) {
    if (is_file(getcwd() . "/$f")) {
        echo "\n=== $f (30 שורות אחרונות):\n";
        $lines = file(getcwd() . "/$f");
        foreach (array_slice($lines, -30) as $l) echo "  " . rtrim($l) . "\n";
    }
}
