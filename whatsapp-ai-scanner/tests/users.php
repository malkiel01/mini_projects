<?php
/**
 * בדיקת המשתמשים: מעבר הבעלים הישן, כניסה דרך הפלטפורמה (sub, מייל, חדש),
 * מסך החיבור החד-פעמי, הפרדת הנתונים, ואסימון הגשר שמזהה משתמש.
 * בלי רשת: PLATFORM_USER מוזרק כמו שהשער כותב אותו.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/wau_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0700, true);
define('DB_FILE', $tmp . '/db.sqlite');
define('SECRET_KEY_FILE', $tmp . '/secret.key');

$failed = 0;
function check(string $n, bool $c): void { global $failed; echo ($c ? "  ok  " : "  FAIL") . "  $n\n"; if (!$c) $failed++; }

// 0. מסד ישן — בעלים יחיד, חשבון והודעה, כמו בשרת לפני ההפרדה.
$old = new PDO('sqlite:' . DB_FILE);
$old->exec("
    CREATE TABLE owner (id INTEGER PRIMARY KEY CHECK (id = 1), password_hash TEXT NOT NULL DEFAULT '',
        pair_token TEXT NOT NULL DEFAULT '', ai_provider TEXT NOT NULL DEFAULT 'anthropic', ai_model TEXT NOT NULL DEFAULT '',
        ai_key_enc TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0,
        log_token TEXT NOT NULL DEFAULT '', answer_lang TEXT NOT NULL DEFAULT 'he');
    CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL, kind TEXT NOT NULL DEFAULT 'personal',
        active INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL, wa_package TEXT NOT NULL DEFAULT '');
    CREATE TABLE logs (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL, source TEXT NOT NULL DEFAULT '',
        action TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT '', detail TEXT NOT NULL DEFAULT '');
");
$old->prepare('INSERT INTO owner (id, password_hash, pair_token, log_token) VALUES (1, ?, ?, ?)')
    ->execute([password_hash('old-secret-pw', PASSWORD_DEFAULT), 'PAIR-OLD', 'LOG-OLD']);
$old->exec("INSERT INTO accounts (label, kind, created_at) VALUES ('הביזנס', 'business', 1)");
$old->exec("INSERT INTO logs (ts, source, action, status) VALUES (1, 'bridge', 'ingest', '200')");
$old = null;

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/accounts.php';
require_once __DIR__ . '/../lib/ingest.php';
require_once __DIR__ . '/../lib/query.php';

// 1. המעבר: הבעלים הישן הוא משתמש 1, עם הסיסמה, האסימונים והנתונים.
$u1 = getUser(1);
check('הבעלים הישן עבר למשתמש 1', $u1 !== null && password_verify('old-secret-pw', $u1['password_hash']));
check('אסימון הגשר נשמר — המכשיר המצומד ממשיך לעבוד', $u1['pair_token'] === 'PAIR-OLD');
check('החשבון הקיים משויך לבעל המערכת', (int) db()->query('SELECT user_id FROM accounts WHERE id = 1')->fetchColumn() === 1);
check('היומן הקיים משויך לבעל המערכת', (int) db()->query('SELECT user_id FROM logs WHERE id = 1')->fetchColumn() === 1);
migrateLegacyOwner(db());
check('המעבר רץ פעם אחת בלבד', (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1);

currentUserId(1);
ingestBatch(1, [['ext_id' => 'x1', 'chat_name' => 'דני', 'sender' => 'דני', 'body' => 'תשלום 500 ₪', 'sent_at' => 100]]);

// 2. אסימון הגשר מזהה את המשתמש.
currentUserId(0);
$_SERVER['HTTP_X_PAIR_TOKEN'] = 'PAIR-OLD';
check('אסימון הגשר תקין', bridgeAuthorized());
check('אסימון הגשר קבע את המשתמש', currentUserId() === 1);
$_SERVER['HTTP_X_PAIR_TOKEN'] = 'PAIR-WRONG';
currentUserId(0);
check('אסימון שגוי נדחה', !bridgeAuthorized() && currentUserId() === 0);
unset($_SERVER['HTTP_X_PAIR_TOKEN']);

// 3. כניסה דרך הפלטפורמה — אין התאמה לפי sub או מייל → משתמש חדש, auto.
$_SERVER['PLATFORM_USER'] = json_encode(['sub' => '17', 'name' => 'בעל המערכת', 'email' => 'Owner@Example.com', 'email_verified' => true]);
$u2 = userForPlatform(platformIdentity());
check('נפתח משתמש חדש, פעיל', (int) $u2['id'] === 2 && (int) $u2['auto'] === 1);
check('המייל נשמר מנורמל', $u2['email'] === 'owner@example.com');
check('לחדש אסימון גשר משלו', $u2['pair_token'] !== '' && $u2['pair_token'] !== 'PAIR-OLD');
check('אותו sub — אותו משתמש', (int) userForPlatform(platformIdentity())['id'] === 2);
check('מחוברת — הכניסה בסיסמה חסומה', !passwordLoginAllowed());

// 4. הפרדה: החדש לא רואה את הנתונים של בעל המערכת.
currentUserId(2);
check('החדש לא רואה חשבונות של אחר', listAccounts() === []);
check('החדש לא שולף חשבון של אחר', getAccount(1) === null);
check('החדש לא מקבל הודעות של אחר', collectMessages(null, null, null, 'תשלום')['messages'] === []);
deleteAccount(1);
check('החדש לא מוחק חשבון של אחר', (int) db()->query('SELECT COUNT(*) FROM accounts WHERE id = 1')->fetchColumn() === 1);
check('היומן של אחר אינו נראה', recentLogs() === []);
$mine = createAccount('שלי', 'personal');
check('החשבון החדש שייך לחדש', (int) $mine['user_id'] === 2);

// 5. מסך החיבור החד-פעמי.
check('אפשר לחבר חשבון ישן', canLinkOld());
for ($i = 0; $i < 5; $i++) {
    try { linkOldAccount('wrong'); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
}
check('5 ניסיונות שגויים → נעילה', (int) getUser(2)['link_locked_until'] > time());
try { linkOldAccount('old-secret-pw'); $locked = false; } catch (InvalidArgumentException $e) { $locked = true; }
check('נעול — גם הסיסמה הנכונה נדחית', $locked);
updateUser(2, ['link_locked_until' => 0]);

$linked = linkOldAccount('old-secret-pw');
check('החשבון הישן חובר ל-sub', (int) $linked['id'] === 1 && $linked['platform_sub'] === '17');
check('המשתמש הנוכחי הוא החשבון הישן', currentUserId() === 1);
check('הסיסמה הישנה לא נמחקה', password_verify('old-secret-pw', getUser(1)['password_hash']));
check('החשבון שנפתח לבד נמחק', getUser(2) === null);
check('הנתונים שלו עברו לחשבון הישן', (int) db()->query('SELECT user_id FROM accounts WHERE id = ' . (int) $mine['id'])->fetchColumn() === 1);
check('ההודעות הישנות חזרו', count(collectMessages(null, null, null, 'תשלום')['messages']) === 1);
check('הכניסה הבאה — ישר לחשבון הישן', (int) userForPlatform(platformIdentity())['id'] === 1);
check('אחרי החיבור — אין עוד מה לחבר', !canLinkOld());

// 6. איחוד לפי מייל: חשבון לא מקושר באותו מייל מתקשר אוטומטית.
$byMail = createUser(['email' => 'dana@example.com', 'password_hash' => password_hash('dana-pw-123', PASSWORD_DEFAULT)]);
$_SERVER['PLATFORM_USER'] = json_encode(['sub' => '18', 'email' => 'DANA@example.com', 'email_verified' => true]);
$u = userForPlatform(platformIdentity());
check('איחוד לפי מייל', (int) $u['id'] === (int) $byMail['id'] && $u['platform_sub'] === '18');

// חשבון שכבר מקושר — לא ניתן לקשר שוב (גם בסיסמה הנכונה).
$_SERVER['PLATFORM_USER'] = json_encode(['sub' => '19', 'email' => 'other@example.com']);
$u19 = userForPlatform(platformIdentity());
currentUserId((int) $u19['id']);
try { linkOldAccount('old-secret-pw'); $relinked = true; } catch (InvalidArgumentException $e) { $relinked = false; }
check('חשבון מקושר — לא מתחבר שוב', !$relinked && getUser(1)['platform_sub'] === '17');

check('כל החשבונות הישנים חוברו — אף אחד לא נשאל', !canLinkOld());

// "אין לי חשבון" — ההצעה לא חוזרת.
dismissLink();
check('ההצעה לא חוזרת', (int) getUser((int) $u19['id'])['link_dismissed'] === 1);

// 7. אסימון יומן קובע את המשתמש שהיומן שלו נקרא.
currentUserId(0);
check('אסימון יומן של בעל המערכת', useLogToken('LOG-OLD') && currentUserId() === 1);
check('אסימון יומן שגוי', !useLogToken('nope'));

// 8. מנותקת: אין זהות → אין משתמש מהפלטפורמה.
unset($_SERVER['PLATFORM_USER']);
check('בלי PLATFORM_USER — אין זהות', platformIdentity() === null);
check('כניסה בסיסמה ישנה מוצאת את בעל המערכת', (int) (userByPassword('old-secret-pw')['id'] ?? 0) === 1);

array_map('unlink', glob($tmp . '/*'));
@rmdir($tmp);

echo $failed === 0 ? "\nהכול עבר\n" : "\n$failed בדיקות נכשלו\n";
exit($failed === 0 ? 0 : 1);
