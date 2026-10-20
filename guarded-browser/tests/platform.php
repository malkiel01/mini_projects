<?php
/**
 * בדיקות לכניסה דרך הפלטפורמה (lib/platform.php) — קונסולת המפתח והפאנל הישן, מול מסד זמני.
 *
 *   php guarded-browser/tests/platform.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-platform-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp);
});
require_once __DIR__ . '/../lib/accounts.php';
$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

/** בקשה חדשה: זהות מהשער (או בלי — מנותקת), בלי עוגיות. */
function asPlatform(?array $id): void {
    if ($id) $_SERVER['PLATFORM_USER'] = json_encode($id, JSON_UNESCAPED_UNICODE);
    else unset($_SERVER['PLATFORM_USER']);
    $_COOKIE = [];
    forgetSessions();
}
$moshe = ['sub' => '7', 'name' => 'משה', 'email' => 'Moshe@Example.com', 'email_verified' => true];
$rina  = ['sub' => '9', 'name' => 'רינה', 'email' => 'rina@example.com', 'email_verified' => false];

db();

echo "\n— הזהות מהשער —\n";
asPlatform(null);
check('בלי PLATFORM_USER — מנותקת', platformIdentity(), null);
$_SERVER['PLATFORM_USER'] = 'not json';
check('ערך פגום — מנותקת', platformIdentity(), null);
asPlatform($moshe);
check('המייל באותיות קטנות', platformIdentity()['email'], 'moshe@example.com');

echo "\n— קונסולה ריקה: חשבון נפתח מיד —\n";
$d = currentDeveloper();
check('נפתח מפתח', $d['name'] ?? null, 'משה');
check('שם משתמש מהמייל', $d['username'] ?? null, 'moshe');
check('נפתח session מסוג dev', sessionRow('dev') !== null, true);
check('בלי סיסמה', one('SELECT password_hash FROM developers WHERE id = ?', [$d['id']])['password_hash'], '');
asPlatform($moshe);
check('בקשה הבאה — אותו חשבון, לא חדש', [(int) currentDeveloper()['id'], developerCount()], [(int) $d['id'], 1]);
check('כניסה בסיסמה חסומה כשמחוברת', developerLogin('Moshe', 'x')[1], 'הכניסה היא דרך הכלים הדיגיטליים');
check('התקנה חסומה כשמחוברת', developerSetup('zz', 'z', 'sod123456')[1], 'כבר יש מנהל מערכת');

echo "\n— חשבון ישן: המסך החד-פעמי —\n";
q("INSERT INTO developers (username, name, password_hash, created_at) VALUES ('old', 'ותיק', ?, ?)",
  [hashPassword('sod12345'), nowIso()]);
$oldId = (int) db()->lastInsertId();
asPlatform($rina);
check('רינה — יש חשבון לא מקושר, לכן אין חשבון חדש', currentDeveloper(), null);
check('...ואין session', sessionRow('dev'), null);
for ($i = 0; $i < 5; $i++) $err = platformLinkOld('dev', platformIdentity(), 'old', 'wrong');
check('סיסמה שגויה', $err, 'שם משתמש או סיסמה שגויים');
check('אחרי 5 — נעילה', platformLinkOld('dev', platformIdentity(), 'old', 'sod12345'), 'יותר מדי ניסיונות. נסו שוב בעוד רבע שעה.');
q("DELETE FROM audit WHERE detail = 'link:dev:9'");
check('חשבון של המסך האחר (admin) לא נחשב', platformLinkOld('dev', platformIdentity(), 'nobody', 'x'), 'שם משתמש או סיסמה שגויים');
q("DELETE FROM audit WHERE detail = 'link:dev:9'");
check('חיבור בסיסמה הישנה', platformLinkOld('dev', platformIdentity(), 'old', 'sod12345'), '');
asPlatform($rina);
check('מעכשיו — החשבון הישן, אוטומטית', (int) (currentDeveloper()['id'] ?? 0), $oldId);
$old = one('SELECT * FROM developers WHERE id = ?', [$oldId]);
check('הסיסמה הישנה לא נמחקה', password_verify('sod12345', $old['password_hash']), true);
check('המייל נשמר לחשבון', $old['email'], 'rina@example.com');
asPlatform($moshe);
check('חשבון מקושר — לא ניתן לקשר שוב', platformLinkOld('dev', platformIdentity(), 'old', 'sod12345'),
      'החשבון הזה כבר מחובר לחשבון אחר בפלטפורמה');

echo "\n— מישהו אחר נכנס לפלטפורמה באותו דפדפן —\n";
asPlatform($moshe);
currentDeveloper();
$cookie = $_COOKIE['mg_dev'];
$_SERVER['PLATFORM_USER'] = json_encode($rina);
forgetSessions();
check('ה-session מוחלף לחשבון של רינה', (int) currentDeveloper()['id'], $oldId);
check('...בעוגייה חדשה', $_COOKIE['mg_dev'] !== $cookie, true);

echo "\n— מנותקת: הסיסמה הישנה חוזרת לעבוד —\n";
asPlatform(null);
check('בלי עוגייה — לא מחובר', currentDeveloper(), null);
[$x, $err] = developerLogin('old', 'sod12345');
check('כניסה בסיסמה', $err, '');
q("INSERT OR REPLACE INTO app_meta (key, value, updated_at) VALUES ('platform_login', ?, ?)",
  [json_encode(['at' => time(), 'login' => ['platform' => true, 'password' => false]]), nowIso()]);
check('המנהל כיבה "סיסמה משלה" — חסומה', developerLogin('old', 'sod12345')[1], 'הכניסה בסיסמה כבויה — היכנסו דרך הכלים הדיגיטליים');
q("DELETE FROM app_meta WHERE key = 'platform_login'");

echo "\n— הפאנל הישן (admin/) —\n";
q("INSERT INTO users (username, email, display_name, password_hash, status, is_admin, created_at)
   VALUES ('boss', 'RINA@example.com', 'בוס', ?, 'active', 1, ?), ('kid', 'moshe@example.com', 'ילד', ?, 'active', 0, ?),
          ('boss2', '', 'בוס שני', ?, 'active', 1, ?)",
  [hashPassword('boss12345'), nowIso(), hashPassword('kid12345'), nowIso(), hashPassword('boss12345'), nowIso()]);
asPlatform($rina);
$a = currentAdmin();
check('רינה — איחוד לפי מייל עם המנהל הישן', $a['username'] ?? null, 'boss');
check('...והקישור נשמר', one("SELECT platform_sub FROM users WHERE username = 'boss'")['platform_sub'], '9');
asPlatform($moshe);
check('משה — המייל שלו של ילד, לא מנהל: אין איחוד, יש מה לקשר → המסך', currentAdmin(), null);
check('ילד לא מתקשר כמנהל', platformLinkOld('admin', platformIdentity(), 'kid', 'kid12345'), 'שם משתמש או סיסמה שגויים');
$new = platformCreate('admin', platformIdentity());
check('"אין לי חשבון" — מנהל חדש, פעיל', [$new['status'], (int) $new['is_admin']], ['active', 1]);
check('...עם מדיניות', one('SELECT user_id FROM policies WHERE user_id = ?', [$new['id']]) !== null, true);
asPlatform($moshe);
check('...ונכנס', (int) (currentAdmin()['id'] ?? 0), (int) $new['id']);
q("UPDATE users SET status = 'suspended' WHERE id = ?", [$new['id']]);
asPlatform($moshe);
check('מנהל מושעה — אין כניסה, ואין חשבון חדש במקומו', [currentAdmin(), (int) one('SELECT COUNT(*) n FROM users WHERE is_admin = 1')['n']], [null, 3]);


echo "\n— אפליקציית ההורים — רק דרך הפלטפורמה —\n";
[$acc] = accountCreate(['name' => 'כהן'], 'dana@example.com', 'דנה');
q("INSERT INTO parents (account_id, login, name, password_hash, created_at) VALUES (?, 'avi', 'אבי', ?, ?)",
  [$acc, hashPassword('avi12345'), nowIso()]);
$dana = ['sub' => '21', 'name' => 'דנה', 'email' => 'Dana@Example.com'];
asPlatform($dana);
check('דנה — מקושרת לפי המייל, אוטומטית', currentParent()['login'] ?? null, 'dana@example.com');
check('...וה-sub נשמר', one("SELECT platform_sub FROM parents WHERE login = 'dana@example.com'")['platform_sub'], '21');
check('כניסה בסיסמה של מגדלור — חסומה', parentLogin('dana@example.com', 'x')[1], 'הכניסה היא דרך הכלים הדיגיטליים');
$stranger = ['sub' => '22', 'name' => 'זר', 'email' => 'nobody@example.com'];
asPlatform($stranger);
check('בלי חשבון משפחה — לא נפתח חשבון לבד', currentParent(), null);
check('...ולא נוצר הורה', (int) one('SELECT COUNT(*) n FROM parents')['n'], 2);
check('אבי (שם כניסה בלי מייל) — מתחבר במסך החד-פעמי', platformLinkOld('parent', platformIdentity(), 'AVI', 'avi12345'), '');
asPlatform($stranger);
check('...ומעכשיו נכנס אוטומטית', currentParent()['login'] ?? null, 'avi');

echo "\n— הזמנה לפלטפורמה (חתימה) —\n";
$sent = null;
$fake = function (string $body, array $headers) use (&$sent): array {
    $sent = [$body, $headers];
    return ['status' => 200, 'body' => '{"status":"invited","sub":"99"}'];
};
q("INSERT INTO parents (account_id, login, name, password_hash, created_at) VALUES (?, 'new@example.com', 'חדש', 'x', ?)", [$acc, nowIso()]);
$newId = (int) db()->lastInsertId();
check('הזמנה', parentInvite($newId, $fake), ['invited', '']);
check('...ה-sub נשמר להורה', one('SELECT platform_sub FROM parents WHERE id = ?', [$newId])['platform_sub'], '99');
$h = [];
foreach ($sent[1] as $line) { [$k, $v] = explode(': ', $line, 2); $h[$k] = $v; }
[$kid, $sig] = explode('.', $h['X-Embed-Signature'], 2);
$key = base64_decode(provisionKeyPayload()['keys'][0]['key']);
$msg = "provision\n{$h['X-Embed-Time']}\n{$h['X-Embed-Nonce']}\n" . hash('sha256', $sent[0]);
check('החתימה מתאמתת מול המפתח הציבורי (כמו בפלטפורמה)', sodium_crypto_sign_verify_detached(base64_decode($sig), $msg, $key), true);
check('...לקוח ו-next נכונים', [$h['X-Embed-Client'], json_decode($sent[0], true)['next']],
      ['guarded-browser', '/mini_projects/guarded-browser/parent/']);
check('שם כניסה בלי מייל — אי אפשר להזמין', parentInvite((int) one("SELECT id FROM parents WHERE login = 'avi'")['id'], $fake)[1],
      'אין מייל — אי אפשר להזמין לפלטפורמה');

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
