<?php
/**
 * בדיקות לחשבונות, הורים ומפתחים (שלב 2) — מול מסד אמיתי וזמני.
 *
 *   php guarded-browser/tests/accounts.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/gb-acc-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
    @rmdir($tmp);
});

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

/*
 * מסד "ישן" — כמו בשרת לפני שלב 2: מנהל וילד, בלי טבלאות החשבונות.
 * המיגרציה צריכה להפוך אותו לחשבון אחד, בלי לאבד דבר.
 */
$old = new PDO("sqlite:$tmp/app.sqlite");
$old->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL DEFAULT '', display_name TEXT NOT NULL DEFAULT '', password_hash TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending', is_admin INTEGER NOT NULL DEFAULT 0,
            expires_at TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL,
            approved_at TEXT NOT NULL DEFAULT '', last_seen_at TEXT NOT NULL DEFAULT '')");
$h = password_hash('sod12345', PASSWORD_DEFAULT);
$old->exec("INSERT INTO users (username, display_name, password_hash, status, is_admin, created_at)
            VALUES ('boss', 'מלכיאל', '$h', 'active', 1, '2026-08-01T00:00:00Z'),
                   ('noa', 'נועה', '$h', 'active', 0, '2026-08-02T00:00:00Z')");
$old = null;

require_once __DIR__ . '/../lib/accounts.php';

echo "\n— המעבר לרב-דיירות —\n";
db();
check('נוצר חשבון אחד', (int) one('SELECT COUNT(*) n FROM accounts')['n'], 1);
$acc = one('SELECT * FROM accounts');
check('בשם החשבון הראשי', $acc['name'], 'החשבון הראשי');
check('פעיל', $acc['status'], 'active');
check('בלי מכסות (0 = בלי הגבלה)', [(int) $acc['max_children'], (int) $acc['max_devices']], [0, 0]);
check('הילד משויך לחשבון', (int) one("SELECT account_id FROM users WHERE username = 'noa'")['account_id'],
      (int) $acc['id']);
check('המנהל הפך למפתח', one("SELECT username FROM developers")['username'] ?? null, 'boss');
check('...עם אותה סיסמה', password_verify('sod12345', one('SELECT password_hash FROM developers')['password_hash']), true);
check('...וגם להורה בחשבון', one("SELECT account_id FROM parents WHERE login = 'boss'")['account_id'] ?? null,
      $acc['id']);
db();
(function () { migrateToAccounts(db()); })();
check('הרצה חוזרת לא יוצרת חשבון נוסף', (int) one('SELECT COUNT(*) n FROM accounts')['n'], 1);

echo "\n— חשבון חדש —\n";
[$id, $pw, $err] = accountCreate(['name' => 'משפחת כהן', 'max_devices' => 2, 'modules' => ['yt_closed', 'bogus']],
                                 'Dana@Example.com', 'דנה');
check('נוצר בלי שגיאה', $err, '');
check('סיסמה זמנית באורך 10', strlen($pw), 10);
$p = one('SELECT * FROM parents WHERE account_id = ?', [$id]);
check('שם הכניסה באותיות קטנות', $p['login'], 'dana@example.com');
check('חייבת להחליף סיסמה', (int) $p['must_change_pw'], 1);
check('מודול לא מוכר נזרק', moduleList(accountRow($id)['modules']), ['yt_closed']);
[, , $err] = accountCreate(['name' => 'כפול'], 'dana@example.com', '');
check('שם כניסה תפוס', $err, 'שם הכניסה הזה כבר תפוס');
[, , $err] = accountCreate(['name' => ''], 'x@y.z', '');
check('שם חשבון ריק', $err, 'שם החשבון: 1–80 תווים');

echo "\n— עדכון —\n";
check('רמה לא מוכרת נדחית', accountUpdate($id, ['level_max' => 'root']), 'רמת אכיפה לא מוכרת');
check('מכסה שלילית נדחית', accountUpdate($id, ['max_children' => -1]), 'מכסה: מספר בין 0 ל-999 (0 = בלי הגבלה)');
check('תאריך שגוי נדחה', accountUpdate($id, ['expires_at' => '2026-13-40']), 'תאריך תוקף לא תקין');
check('עדכון חלקי', accountUpdate($id, ['level_max' => 'apps']), '');
check('...לא נגע בשאר', (int) accountRow($id)['max_devices'], 2);

echo "\n— תוקף —\n";
$now = new DateTimeImmutable('2026-10-16', new DateTimeZone('UTC'));
check('בלי תוקף', expiryState('', $now), 'none');
check('חסד (עבר אתמול)', expiryState('2026-10-15', $now), 'grace');
check('משוחרר (עבר מזמן)', expiryState('2026-10-01', $now), 'released');
check('קרוב', expiryState('2026-10-20', $now), 'soon');
check('רחוק', expiryState('2027-01-01', $now), 'ok');
accountUpdate($id, ['expires_at' => '']);
accountExtend($id, 1, $now);
check('הארכה מהיום כשאין תוקף', accountRow($id)['expires_at'], '2026-11-16');
accountExtend($id, 1, $now);
check('הארכה מצטברת מהתוקף העתידי', accountRow($id)['expires_at'], '2026-12-16');
accountUpdate($id, ['expires_at' => '2026-01-01']);
accountExtend($id, 12, $now);
check('תוקף שעבר — מתחילים מהיום', accountRow($id)['expires_at'], '2027-10-16');

echo "\n— השעיה ומכסת מכשירים —\n";
q('INSERT INTO users (username, password_hash, status, created_at, account_id) VALUES (?,?,?,?,?)',
  ['kid', hashPassword('sod12345'), 'active', nowIso(), $id]);
$kid = (int) db()->lastInsertId();
q('INSERT INTO policies (user_id, updated_at) VALUES (?, ?)', [$kid, nowIso()]);
registerDevice($kid, 'A', 'dev-a', 0);
check('מכשיר ראשון — יש מקום', accountDeviceQuotaFull($id, 'dev-b'), false);
registerDevice($kid, 'B', 'dev-b', 0);
check('מלא — מכשיר חדש נדחה', accountDeviceQuotaFull($id, 'dev-c'), true);
check('מלא — אבל מכשיר שכבר רשום נכנס', accountDeviceQuotaFull($id, 'dev-a'), false);
$kidRow = one('SELECT * FROM users WHERE id = ?', [$kid]);
check('חשבון פעיל — לא חוסם', accountBlock($kidRow), null);
accountSetStatus($id, 'suspended');
check('השעיה חוסמת', accountBlock($kidRow)[0] ?? null, 'account_suspended');
check('...ומוחקת את המכשירים', accountDeviceCount($id), 0);
accountSetStatus($id, 'active');
check('החזרה פותחת', accountBlock($kidRow), null);

echo "\n— הורים —\n";
[$pw2, $err] = parentAdd($id, 'avi', 'אבי');
check('הורה שני נוסף', $err, '');
$avi = (int) one("SELECT id FROM parents WHERE login = 'avi'")['id'];
startSession('parent', $avi);
[$pw3, $err] = parentReset($avi);
check('איפוס — סיסמה חדשה עובדת',
      password_verify($pw3, one('SELECT password_hash FROM parents WHERE id = ?', [$avi])['password_hash']), true);
check('...וכל הכניסות שלו נותקו',
      (int) one("SELECT COUNT(*) n FROM admin_sessions WHERE kind = 'parent' AND user_id = ?", [$avi])['n'], 0);
check('הסרת הורה', parentRemove($avi), '');
$dana = (int) one("SELECT id FROM parents WHERE login = 'dana@example.com'")['id'];
check('ההורה האחרון לא נמחק', parentRemove($dana), 'לחשבון חייב להישאר לפחות הורה אחד');

echo "\n— המפתח —\n";
check('אין התקנה כשכבר יש מפתח', developerSetup('new', 'x', 'sod123456')[1], 'כבר יש מנהל מערכת');
[$d, $err] = developerLogin('boss', 'wrong-pass');
check('סיסמה שגויה', $err, 'שם משתמש או סיסמה שגויים');
[$d, $err] = developerLogin('boss', 'sod12345');
check('כניסה תקינה', $err, '');
$tok = (string) one("SELECT token_hash FROM admin_sessions WHERE kind = 'dev'")['token_hash'];
check('נפתח session מסוג dev', $tok !== '', true);
check('עוגיית מנהל ישן לא פותחת את הקונסולה', (function () {
    // בלי עוגיית המפתח מהכניסה שלמעלה — רק עוגיית מנהל.
    $keep = $_COOKIE['mg_dev'] ?? null; unset($_COOKIE['mg_dev']);
    $_COOKIE['mg_admin'] = str_repeat('b', 64); forgetSessions();
    $d = currentDeveloper(); $_COOKIE['mg_dev'] = $keep; forgetSessions(); return $d;
})(), null);
check('החלפת סיסמה — הנוכחית שגויה', developerChangePassword((int) $d['id'], 'nope', 'newpass123'),
      'הסיסמה הנוכחית שגויה');
check('החלפת סיסמה', developerChangePassword((int) $d['id'], 'sod12345', 'newpass123'), '');

echo "\n— מבט מערכת: מספרים בלבד —\n";
$sys = systemOverview();
check('חשבונות', $sys['accounts'], 2);
check('ילדים (בלי המנהל הישן)', $sys['children'], 2);
check('אין שדה של כתובות', array_key_exists('history', $sys) || array_key_exists('audit', $sys), false);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exit($fail === 0 ? 0 : 1);
