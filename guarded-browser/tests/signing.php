<?php
/**
 * בדיקות לחתימת ההודעות של השרת (lib/signing.php).
 *
 *   php guarded-browser/tests/signing.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/gb-sign-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
    @rmdir($tmp);
});

require_once __DIR__ . '/../lib/signing.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "— מפתח —\n";
$pub = signingPublicKey();
check('מפתח ציבורי יציב', signingPublicKey(), $pub);
check('אורך המפתח הציבורי (32 בתים ב-b64u)', strlen(b64ud($pub)), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);

echo "\n— אסימון שחרור —\n";
$t = releaseToken(7);
$c = verifyToken($t, $pub);
check('מאומת', is_array($c), true);
check('typ', $c['typ'], 'release');
check('acct', $c['acct'], 7);
check('יש iat', isset($c['iat']), true);

echo "\n— דחיות —\n";
check('חתימה שהשתנתה נדחית', verifyToken($t . 'x', $pub), null);
check('מטען שהשתנה נדחה',
      verifyToken(b64u('{"typ":"release","acct":999}') . '.' . explode('.', $t)[1], $pub), null);
check('מפתח ציבורי אחר דוחה', verifyToken($t, b64u(str_repeat("\0", SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES))), null);
check('פורמט לא תקין נדחה', verifyToken('לא-אסימון', $pub), null);
check('מול המפתח של השרת עצמו', is_array(verifyToken($t)), true);

echo "\n— server_url חתום —\n";
$u = verifyToken(serverUrlToken('https://migdalor.mbe-plus.com/'), $pub);
check('typ server_url', $u['typ'], 'server_url');
check('url', $u['url'], 'https://migdalor.mbe-plus.com/');

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
