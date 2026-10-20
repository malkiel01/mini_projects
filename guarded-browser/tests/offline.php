<?php
/**
 * בדיקות ל"העברה בלי אינטרנט" (offlineToken, lib/signing.php) — QR חתום שמכשיר הילד סורק.
 *
 *   php guarded-browser/tests/offline.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-offline-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp);
});
require_once __DIR__ . '/../lib/children.php';
require_once __DIR__ . '/../lib/enforce.php';
require_once __DIR__ . '/../lib/signing.php';
$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

db();
[$a] = accountCreate(['name' => 'כהן'], 'dana@x.com', 'דנה');
[$kid] = childCreate($a, 'נועה');
deviceSettingSave($kid, 'camera', true);

echo "\n— האסימון —\n";
$tok = offlineToken($a, $kid, deviceSettings($kid), ['manage' => false]);
$c = verifyToken($tok, signingPublicKey());
check('החתימה תקפה מול המפתח הציבורי של המכשירים', is_array($c), true);
check('סוג, חשבון וילד', [$c['typ'], $c['acct'], $c['child']], ['offline', $a, $kid]);
check('הגדרות המכשיר בפנים — המצלמה חסומה', $c['dev']['camera'], true);
check('...ו-Wi‑Fi תמיד מותר', $c['dev']['wifi'], false);
check('תוקף של רבע שעה', $c['exp'] - $c['iat'], OFFLINE_TTL);

echo "\n— זיוף —\n";
[$body, $sig] = explode('.', $tok);
$claims = json_decode(b64ud($body), true);
$claims['dev']['camera'] = false;
$forged = b64u(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '.' . $sig;
check('שינוי התוכן — החתימה כבר לא תקפה', verifyToken($forged, signingPublicKey()), null);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
