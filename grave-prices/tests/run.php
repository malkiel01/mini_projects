<?php
/**
 * בדיקות CLI — php grave-prices/tests/run.php. מסד זמני, לא נוגע ב-data/.
 * מיגרציה על מסד ריק, נתוני ההתחלה (מול ה-PDF), פענוח התוויות, והזהות החתומה של הכניסה המנותקת.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/gp-test-' . bin2hex(random_bytes(4));
putenv("GP_DATA_DIR=$tmp");
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/editions.php';

$fails = 0;
$n = 0;
function check(string $name, bool $ok, string $got = ''): void
{
    global $fails, $n;
    $n++;
    if ($ok) return;
    $fails++;
    echo "✗ $name" . ($got !== '' ? " — קיבלנו: $got" : '') . "\n";
}

// --- מיגרציה ונתוני ההתחלה
$pdo = db();
check('גרסת המסד', (int)$pdo->query('PRAGMA user_version')->fetchColumn() === count(gp_migrations()));
$list = gp_editions()['editions'];
check('שני מחירונים', count($list) === 2, (string)count($list));
check('מהחדש לישן', array_column($list, 'effective_from') === ['2025-03-15', '2024-01-02']);
check('"בתוקף היום" — רק החדש', $list[0]['in_effect'] === true && $list[1]['in_effect'] === false);
check('pid — 12 תווים', (bool)preg_match('/^[a-z0-9]{12}$/', $list[0]['pid']));

$sum = fn(array $e, string $a) => array_sum(array_map(fn($r) => (int)($r['prices'][$a] ?? 0), $e['rows']));
$e25 = gp_edition($list[0]['pid']);
$e24 = gp_edition($list[1]['pid']);
check('28 שורות ב-2025', count($e25['rows']) === 28, (string)count($e25['rows']));
check('סכום מחירי ישראל 2025 = 584,979', $sum($e25, 'il') === 584979, (string)$sum($e25, 'il'));
check('סכום מחירי ישראל 2024 = 574,099', $sum($e24, 'il') === 574099, (string)$sum($e24, 'il'));
check('חו"ל ריק', $sum($e25, 'abroad') === 0 && $e25['rows'][2]['prices']['abroad'] === null);
check('השורה הראשונה במחיר', $e25['rows'][2]['prices']['il'] === 16947);
check('כותרת בלי מחירים', $e25['rows'][0]['kind'] === 'section' && $e25['rows'][0]['prices'] === null);
check('שלוש חתימות', count($e25['signatures']) === 3 && $e25['signatures'][0]['name'] === 'אבשלום מלכה');

// מיגרציה שנייה (פריסה חוזרת) לא טוענת שוב, ולא נוגעת בשינוי
$pdo->exec("UPDATE prices SET amount = 1 WHERE audience = 'il' AND row_id = (SELECT MIN(row_id) FROM prices)");
$pdo->exec('PRAGMA user_version = 1');   // כאילו המיגרציה של נתוני ההתחלה טרם רצה
gp_migrate($pdo);
check('נתוני ההתחלה לא נטענים שוב', (int)$pdo->query('SELECT COUNT(*) FROM editions')->fetchColumn() === 2);
check('שינוי נשמר', (int)$pdo->query("SELECT amount FROM prices WHERE row_id = (SELECT MIN(row_id) FROM prices) AND audience = 'il'")->fetchColumn() === 1);

// --- הסימון בתוויות
$cases = [
    '**__לתושב ירושלים__**' => '<b><u>לתושב ירושלים</u></b>',
    '**__לתושבי ירושלים__** ((2 בודדים מחיים))' => '<b><u>לתושבי ירושלים</u></b> <span class="plain">(2 בודדים מחיים)</span>',
    '**בגוש 26** (חלקות א-ב)' => '<b>בגוש 26</b> (חלקות א-ב)',
    '** תשלום עבור העברת בעלות' => '** תשלום עבור העברת בעלות',
    '** המחירון מתייחס לרכישת אחוזת הקבר __בלבד__' => '** המחירון מתייחס לרכישת אחוזת הקבר <u>בלבד</u>',
    '<b>x</b> & "y"' => '&lt;b&gt;x&lt;/b&gt; &amp; &quot;y&quot;',
    '**<script>alert(1)</script>**' => '<b>&lt;script&gt;alert(1)&lt;/script&gt;</b>',
    'יו"ר' => 'יו&quot;ר',
];
foreach ($cases as $in => $want) {
    $got = gp_label_html($in);
    check("תווית: $in", $got === $want, $got);
}

// --- הזהות החתומה (כניסה מנותקת, sso.md סעיף 2)
$kp = sodium_crypto_sign_keypair();
$b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$pub = $b64(sodium_crypto_sign_publickey($kp));
$sign = function (array $p) use ($kp, $b64) {
    $payload = $b64(json_encode($p));
    return $payload . '.' . $b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($kp)));
};
$good = ['aud' => 'grave-prices', 'sub' => '17', 'name' => 'דנה', 'state' => 'abc12345', 'return' => gp_sso_return(), 'exp' => time() + 60];
check('כתובת החזרה מ-app.json', gp_sso_return() === 'https://mbe-plus.com/mini_projects/grave-prices/api.php?action=sso_callback');
check('כתובת sso.php', gp_sso_url() === 'https://mbe-plus.com/mini_projects/platform/sso.php', gp_sso_url());
check('זהות תקינה', is_array(gp_sso_verify($sign($good), 'abc12345', $pub)));
check('state אחר', gp_sso_verify($sign($good), 'zzz12345', $pub) === 'זהות לא תקפה');
check('aud אחר', gp_sso_verify($sign(['aud' => 'other'] + $good), 'abc12345', $pub) === 'זהות לא תקפה');
check('פג תוקף', gp_sso_verify($sign(['exp' => time() - 1] + $good), 'abc12345', $pub) === 'זהות לא תקפה');
check('חתימה מזויפת', gp_sso_verify($sign($good) . 'x', 'abc12345', $pub) === 'חתימה לא תקינה');

// --- המשתמשים: חשבון נפתח פעם אחת, is_dev לפי platform_admin, וזהות מ-sso לא משנה אותו
$u = gp_user_upsert('17', 'דנה', 'd@example.com', true);
$u2 = gp_user_upsert('17', 'דנה לוי', '', null);
check('חשבון אחד ל-sub', (int)$u['id'] === (int)$u2['id'] && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1);
check('is_dev נשמר בכניסה בלי platform_admin', (int)$u2['is_dev'] === 1 && $u2['email'] === 'd@example.com');
check('is_dev יורד', (int)gp_user_upsert('17', 'דנה', '', false)['is_dev'] === 0);

// ניקוי
foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
@rmdir($tmp);

echo $fails ? "\n$fails מתוך $n בדיקות נכשלו\n" : "✓ $n בדיקות עברו\n";
exit($fails ? 1 : 0);
