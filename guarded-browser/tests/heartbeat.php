<?php
/**
 * בדיקות לחוזה הפעימה (lib/heartbeat.php) — מה האפליקציה מקבלת כל 30 שניות.
 *
 *   php guarded-browser/tests/heartbeat.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-beat-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp);
});
require_once __DIR__ . '/../lib/children.php';
require_once __DIR__ . '/../lib/heartbeat.php';
$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

db();
[$acc] = accountCreate(['name' => 'כהן'], 'dana@x.com', 'דנה');
q("UPDATE accounts SET status = 'active' WHERE id = ?", [$acc]);
[$kid] = childCreate($acc, 'נועה');
q("UPDATE users SET status = 'active' WHERE id = ?", [$kid]);
$token = registerDevice($kid, 'Galaxy', 'g1', 3);
$user = fn() => userByToken($token);

childRuleAdd($kid, 'https://example.org/', 'domain', 'דוגמה', 'allow');

echo "\n— בלי כתובת —\n";
$r = heartbeatPayload($user(), 30, '', 0);
check('החשבון פעיל', [$r['ok'], $r['allowed']], [true, true]);
check('אין הכרעה על דף', array_key_exists('page', $r), false);
check('הזמן נצבר', $r['used_today_sec'], 30);
check('חסם עליון לפעימה מנופחת', heartbeatPayload($user(), 99999, '', 0)['used_today_sec'], 330);

echo "\n— הדף שעל המסך —\n";
$r = heartbeatPayload($user(), 0, 'https://example.org/page', 0);
check('אתר מותר — הדף מותר', $r['page']['allowed'], true);
$r = heartbeatPayload($user(), 0, 'https://other.example.net/', 0);
check('אתר שלא הותר — הדף נחסם, אבל החשבון עדיין פעיל', [$r['allowed'], $r['page']['allowed']], [true, false]);

echo "\n— היתר זמני שפג בזמן צפייה —\n";
q("UPDATE rules SET expires_at = ? WHERE user_id = ?", [gmdate('c', time() + 3600), $kid]);
$before = heartbeatPayload($user(), 0, 'https://example.org/v', 0);
check('לפני התפוגה — מותר', $before['page']['allowed'], true);
q("UPDATE rules SET expires_at = ? WHERE user_id = ?", [gmdate('c', time() - 60), $kid]);
$r = heartbeatPayload($user(), 0, 'https://example.org/v', 0);
check('אחרי התפוגה — הפעימה הבאה מחזירה חסימה לדף', $r['page']['allowed'], false);
check('...והטביעה השתנתה, כדי שהמכשיר ימשוך מדיניות', $r['rev'] !== $before['rev'], true);

echo "\n— החשבון נעצר —\n";
q("UPDATE users SET status = 'suspended' WHERE id = ?", [$kid]);
$r = heartbeatPayload($user(), 0, 'https://example.org/v', 0);
check('השעיה — allowed=false, ואין צורך בהכרעת דף', [$r['allowed'], array_key_exists('page', $r)], [false, false]);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
