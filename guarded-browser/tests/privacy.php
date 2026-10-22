<?php
/**
 * מה מנהל המערכת רואה (lib/privacy.php) — סקירה, 2026-10-20, שלב 10.
 *
 *   php guarded-browser/tests/privacy.php
 *
 * privacy.html: "מנהל המערכת רואה מספרים בלבד — לא כתובות ולא היסטוריה". נבדק גם שהדפים בפאנל משתמשים
 * בעזרים האלה (קריאה של הקוד — לדפים אין שכבת בדיקה בלי כניסת מנהל).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-priv-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../lib/children.php';
require_once __DIR__ . '/../lib/privacy.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n— מי —\n";
check('ילד בחשבון משפחה — לא', adminSeesActivity(['account_id' => 7]), false);
check('משתמש ישן בלי חשבון — כן', adminSeesActivity(['account_id' => 0]), true);
check('בלי משתמש (נמחק) — כן, אין של מי להסתיר', adminSeesActivity(null), true);

echo "\n— אותו כלל ב-SQL —\n";
db();
[$acc] = accountCreate(['name' => 'כהן'], 'dana@x.com', 'דנה');
[$kid] = childCreate($acc, 'נועה');
q("INSERT INTO users (username, password_hash, status, created_at) VALUES ('legacy', 'x', 'active', ?)", [nowIso()]);
$legacy = (int) db()->lastInsertId();
foreach ([$kid, $legacy] as $u) audit($u, 'nav', true, 'https://secret.example/page', 'rule_allow');
$visible = array_map('intval', array_column(all('SELECT a.user_id FROM audit a LEFT JOIN users u ON u.id = a.user_id WHERE '
                                               . adminSeesActivitySql()), 'user_id'));
check('היומן בפאנל — רק המשתמש הישן', $visible, [$legacy]);

echo "\n— רישום אבחון —\n";
$body = "showCover url=https://www.youtube.com/watch?v=abc&t=1 state=ok\nnav intent://scan/#Intent;end";
check('כתובות מוחלפות', redactUrls($body), "showCover url=[כתובת] state=ok\nnav [כתובת]");
check('טקסט בלי כתובת לא משתנה', redactUrls('api.move fail code=403'), 'api.move fail code=403');

echo "\n— הדפים בפאנל משתמשים בכלל —\n";
$src = fn(string $f) => file_get_contents(__DIR__ . "/../admin/$f");
check('audit.php — מסנן לפי adminSeesActivitySql', str_contains($src('audit.php'), "[adminSeesActivitySql()]"), true);
check('user.php — מספרים לחשבון משפחה', str_contains($src('user.php'), 'if (!adminSeesActivity($user))'), true);
check('alerts.php — הכתובת מוסתרת בשתי הרשימות', substr_count($src('alerts.php'), 'adminSeesActivitySql()'), 2);
check('health.php — האבחון עם redactUrls', str_contains($src('health.php'), 'redactUrls('), true);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
