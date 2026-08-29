<?php
/**
 * בדיקות אינטגרציה — הסכימה והשאילתות רצות באמת.
 *
 *   php guarded-browser/tests/integration.php
 *
 * ‏tests/policy.php בודק את ההכרעה בבידוד; כאן נבדק שהיא מקבלת את
 * הנתונים הנכונים מבסיס נתונים אמיתי. שגיאת SQL שמתגלה רק בייצור היא
 * בדיוק מה שהקובץ הזה מונע.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/gb-test-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");

require_once __DIR__ . '/../lib/auth.php';

// ניקוי גם כשהבדיקה נופלת באמצע.
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

echo "\n— הסכימה נוצרת —\n";
db();
$tables = array_column(all("SELECT name FROM sqlite_master WHERE type='table'"), 'name');
foreach (['users', 'policies', 'rules', 'devices', 'usage', 'audit'] as $t) {
    check("טבלה $t קיימת", in_array($t, $tables, true), true);
}
check('הרצה חוזרת אינה נכשלת', (function () { db(); return true; })(), true);

echo "\n— הרשמה ואישור —\n";
q('INSERT INTO users (username, password_hash, status, created_at) VALUES (?,?,?,?)',
  ['dani', hashPassword('sod12345'), 'pending', nowIso()]);
$uid = (int) db()->lastInsertId();
q('INSERT INTO policies (user_id, updated_at) VALUES (?, ?)', [$uid, nowIso()]);

$u = one('SELECT * FROM users WHERE id = ?', [$uid]);
check('נוצר כממתין', $u['status'], 'pending');
check('סיסמה מאומתת', password_verify('sod12345', $u['password_hash']), true);
check('סיסמה שגויה נדחית', password_verify('nope', $u['password_hash']), false);

$now = new DateTimeImmutable('2026-08-26 18:00:00');
check('ממתין אינו מקבל גישה', accountState($u, $now)['code'], 'pending');

q('UPDATE users SET status = ?, approved_at = ? WHERE id = ?', ['active', nowIso(), $uid]);
$u = one('SELECT * FROM users WHERE id = ?', [$uid]);
check('אחרי אישור — פעיל', accountState($u, $now)['allow'], true);

echo "\n— ברירת מחדל היא סגירה —\n";
/*
 * משתמש מאושר בלי כללים אינו משתמש עם גישה. אם ברירת המחדל הייתה
 * פתוחה, אישור חשבון היה פותח את כל האינטרנט בלי שאיש התכוון לכך.
 */
$pol = policyFor($uid);
check('מצב ברירת המחדל הוא קיוסק', $pol['mode'], MODE_KIOSK);
check('בלי כללים — אין גישה לשום כתובת',
      evaluate($u, $pol, ruleSetFor($uid), $now, ['url' => 'https://liveball.sx'])['code'], 'not_listed');

echo "\n— כללים —\n";
q('INSERT INTO rules (user_id, label, pattern, scope, action, created_at) VALUES (?,?,?,?,?,?)',
  [$uid, 'ליגה', 'liveball.sx', 'domain_plus', 'allow', nowIso()]);
$set = ruleSetFor($uid);
check('הכלל נשלף', count($set['rules']), 1);
check('כתובת מותרת נפתחת',
      evaluate($u, $pol, $set, $now, ['url' => 'https://liveball.sx/team/772'])['allow'], true);
check('משאב נלווה מדומיין אחר מותר',
      evaluate($u, $pol, $set, $now,
               ['url' => 'https://cdn.player.net/p.js', 'main_frame' => false])['allow'], true);
check('ניווט לדומיין אחר נחסם',
      evaluate($u, $pol, $set, $now, ['url' => 'https://other.com'])['allow'], false);

q('INSERT INTO rules (user_id, pattern, scope, action, created_at) VALUES (?,?,?,?,?)',
  [$uid, 'liveball.sx/admin', 'exact', 'deny', nowIso()]);
check('איסור ספציפי גובר על היתר הדומיין',
      evaluate($u, $pol, ruleSetFor($uid), $now, ['url' => 'https://liveball.sx/admin'])['code'], 'rule_deny');

echo "\n— הקטלוג נזרע —\n";
$map = domainMap();
check('הקטלוג לא ריק',        count($map) > 50, true);
check('יוטיוב מסווג כווידאו', in_array('video', $map['youtube.com'] ?? [], true), true);
check('הימורים מסווגים',      in_array('gambling', $map['bet365.com'] ?? [], true), true);
// זריעה חוזרת אינה מכפילה: אחרת כל טעינת דף הייתה מנפחת את הטבלה.
$before = (int) one('SELECT COUNT(*) n FROM domain_categories')['n'];
seedCatalog(db());
check('זריעה חוזרת אינה מכפילה',
      (int) one('SELECT COUNT(*) n FROM domain_categories')['n'], $before);

echo "\n— קטגוריות מול בסיס נתונים —\n";
q('INSERT INTO category_rules (user_id, category, action, created_at) VALUES (?,?,?,?)',
  [$uid, 'gambling', 'deny', nowIso()]);
q('INSERT INTO category_rules (user_id, category, action, created_at) VALUES (?,?,?,?)',
  [$uid, 'education', 'allow', nowIso()]);
$set = ruleSetFor($uid);
check('הימורים נחסמים',
      evaluate($u, $pol, $set, $now, ['url' => 'https://bet365.com'])['code'], 'category_deny');
check('לימודים נפתחים גם בברירת מחדל סגורה',
      evaluate($u, $pol, $set, $now, ['url' => 'https://khanacademy.org'])['code'], 'category_allow');

echo "\n— יוטיוב מול בסיס נתונים —\n";
q('INSERT INTO platform_rules (user_id, platform, mode, created_at) VALUES (?,?,?,?)',
  [$uid, PLATFORM_YOUTUBE, 'restricted', nowIso()]);
q('INSERT INTO platform_items (user_id, platform, kind, item_id, action, created_at)
   VALUES (?,?,?,?,?,?)',
  [$uid, PLATFORM_YOUTUBE, 'channel', 'UCtestChannel123456789', 'allow', nowIso()]);
$set = ruleSetFor($uid);
check('דף הבית של יוטיוב חסום',
      evaluate($u, $pol, $set, $now, ['url' => 'https://youtube.com/'])['code'], 'yt_no_browse');
check('ערוץ מאושר נפתח',
      evaluate($u, $pol, $set, $now,
               ['url' => 'https://youtube.com/channel/UCtestChannel123456789'])['allow'], true);
check('ערוץ אחר נחסם',
      evaluate($u, $pol, $set, $now,
               ['url' => 'https://youtube.com/channel/UCsomeoneElse12345678'])['code'], 'yt_not_approved');

// מטמון הבעלות: תשובה ריקה נשמרת, ואינה גוררת פנייה בכל לחיצה.
q('INSERT INTO video_owner (platform, video_id, channel_id, title, fetched_at) VALUES (?,?,?,?,?)',
  [PLATFORM_YOUTUBE, 'cachedVid1', 'UCtestChannel123456789', 'בדיקה', nowIso()]);
check('המטמון מחזיר בלי רשת', youTubeOwner('cachedVid1'), 'UCtestChannel123456789');
check('וסרטון מהערוץ הזה נפתח',
      evaluate($u, $pol, ruleSetFor($uid), $now,
               ['url' => 'https://youtube.com/watch?v=cachedVid1'])['code'], 'yt_channel_allowed');

echo "\n— סוגי תוכן —\n";
q('UPDATE policies SET posture = ?, blocked_types = ? WHERE user_id = ?',
  ['allow_all', 'video,executable', $uid]);
$pol2 = policyFor($uid);
check('ווידאו חסום',
      evaluate($u, $pol2, ruleSetFor($uid), $now, ['url' => 'https://a.com/x.mp4'])['code'], 'type_blocked');
check('קובץ התקנה חסום',
      evaluate($u, $pol2, ruleSetFor($uid), $now, ['url' => 'https://a.com/x.apk'])['code'], 'type_blocked');
check('דף רגיל עובר',
      evaluate($u, $pol2, ruleSetFor($uid), $now, ['url' => 'https://a.com/page'])['allow'], true);

echo "\n— המיגרציה —\n";
/*
 * המצב הישן "free" היה גם דפדפן וגם פתוח כברירת מחדל. הפירוק לשני
 * צירים חייב לתרגם אותו, אחרת משתמש חופשי היה מוצא את עצמו חסום.
 */
q("UPDATE policies SET mode = 'free', posture = 'deny_all' WHERE user_id = ?", [$uid]);
q('UPDATE policies SET migrated_posture = 0 WHERE user_id = ?', [$uid]);
db()->exec('ALTER TABLE policies RENAME COLUMN migrated_posture TO old_flag');
migrate(db());
$after = policyFor($uid);
check('free הפך לדפדפן', $after['mode'], MODE_BROWSER);
check('ולפתוח כברירת מחדל', $after['posture'], POSTURE_ALLOW);

echo "\n— מכשירים —\n";
$token = registerDevice($uid, 'Pixel 8', 'abc-123', 1);
check('אסימון באורך צפוי', strlen($token), 64);
check('האסימון אינו נשמר כמות שהוא',
      one('SELECT token_hash FROM devices WHERE user_id = ?', [$uid])['token_hash'] !== $token, true);
check('האסימון מזהה את המשתמש', (int) userByToken($token)['id'], $uid);
check('אסימון מומצא אינו מזהה', userByToken('deadbeef'), null);
check('אסימון ריק אינו מזהה', userByToken(''), null);

// מכשיר שני כשמותר אחד — הוותיק מוחלף, והישן מפסיק לעבוד מיד.
$token2 = registerDevice($uid, 'Tablet', 'xyz-999', 1);
check('רק מכשיר אחד נשאר',
      (int) one('SELECT COUNT(*) n FROM devices WHERE user_id = ?', [$uid])['n'], 1);
check('האסימון הישן בוטל', userByToken($token), null);
check('החדש עובד', (int) userByToken($token2)['id'], $uid);

echo "\n— מדידת זמן —\n";
$tz = (string) $pol['timezone'];
check('מתחילים מאפס', usedTodaySeconds($uid, $tz), 0);
addUsage($uid, $tz, 600);
addUsage($uid, $tz, 300);
check('הצבירה מסכמת', usedTodaySeconds($uid, $tz), 900);
check('מכסה שנותרה', withinQuota(['daily_quota_min' => 20], 900)['allow'], true);
check('מכסה שנוצלה', withinQuota(['daily_quota_min' => 15], 900)['code'], 'quota_spent');

echo "\n— השעיה מנתקת מיד —\n";
q('UPDATE users SET status = ? WHERE id = ?', ['suspended', $uid]);
q('DELETE FROM devices WHERE user_id = ?', [$uid]);
check('האסימון בטל', userByToken($token2), null);
check('והחשבון חסום',
      accountState(one('SELECT * FROM users WHERE id = ?', [$uid]), $now)['code'], 'suspended');

echo "\n— יומן —\n";
audit($uid, 'nav', false, 'https://x.com', 'not_listed');
check('הרשומה נכתבה',
      (int) one('SELECT COUNT(*) n FROM audit WHERE user_id = ?', [$uid])['n'] > 0, true);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exit($fail === 0 ? 0 : 1);
