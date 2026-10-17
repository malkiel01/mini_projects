<?php
/**
 * בדיקות לאפליקציית ההורה (שלב 3): כניסה, סיסמה זמנית, בידוד בין חשבונות,
 * ושמירת הרשאות דרך lib/children.php.
 *
 *   php guarded-browser/tests/parents.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/gb-par-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
    @rmdir($tmp);
});

require_once __DIR__ . '/../lib/children.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

db();
[$a1, $pw1] = accountCreate(['name' => 'כהן', 'max_children' => 2, 'modules' => ['yt_filtered']], 'dana@x.com', 'דנה');
[$a2, $pw2] = accountCreate(['name' => 'לוי', 'modules' => []], 'avi@x.com', 'אבי');

echo "\n— כניסת הורה —\n";
check('סיסמה שגויה', parentLogin('dana@x.com', 'nope')[1], 'שם כניסה או סיסמה שגויים');
[$p, $err] = parentLogin('DANA@x.com ', $pw1);
check('כניסה עם הסיסמה הזמנית (בלי תלות באותיות גדולות)', $err, '');
check('חייבת להחליף', (int) $p['must_change_pw'], 1);
$_COOKIE['mg_parent'] = 'x';
check('החלפה — הנוכחית שגויה', parentChangePassword((int) $p['id'], 'nope', 'newpass123'), 'הסיסמה הנוכחית שגויה');
check('החלפה — קצרה מדי', parentChangePassword((int) $p['id'], $pw1, 'short'), 'הסיסמה חייבת להיות באורך 8 תווים לפחות');
check('החלפה תקינה', parentChangePassword((int) $p['id'], $pw1, 'newpass123'), '');
check('...והדגל ירד', (int) one('SELECT must_change_pw m FROM parents WHERE id = ?', [$p['id']])['m'], 0);
check('הסיסמה הישנה כבר לא עובדת', parentLogin('dana@x.com', $pw1)[1], 'שם כניסה או סיסמה שגויים');
check('החדשה עובדת', parentLogin('dana@x.com', 'newpass123')[1], '');

echo "\n— ילדים ומכסה —\n";
[$k1, $u1, $kp1, $err] = childCreate($a1, 'נועה');
check('ילד ראשון', $err, '');
check('שם משתמש למכשיר נוצר', (bool) preg_match('/^k\d{6}$/', $u1), true);
check('הסיסמה למכשיר עובדת', password_verify($kp1, one('SELECT password_hash h FROM users WHERE id = ?', [$k1])['h']), true);
check('ברירת מחדל בטוחה: קיוסק וחסום', [policyFor($k1)['mode'], policyFor($k1)['posture']], ['kiosk', 'deny_all']);
childCreate($a1, 'יוסי');
check('מעבר למכסה נחסם', childCreate($a1, 'שלישי')[3],
      'הגעתם למספר הילדים בחשבון (2). אפשר לבקש הגדלה ממנהל המערכת.');
check('שם ריק נדחה', childCreate($a2, '  ')[3], 'שם הילד: 1–40 תווים');
[$k2] = childCreate($a2, 'דני');

echo "\n— בידוד בין חשבונות —\n";
check('הילד שלי', (int) (childOf($a1, $k1)['id'] ?? 0), $k1);
check('ילד של חשבון אחר — כאילו לא קיים', childOf($a1, $k2), null);
check('מזהה שלא קיים', childOf($a1, 99999), null);
check('חשבון 0 לא רואה כלום', childOf(0, $k1), null);

echo "\n— שמירת הרשאות —\n";
check('מצב לא מוכר נדחה', childPolicySave($k1, ['mode' => 'root']), 'מצב לא מוכר');
check('שעה לא תקינה נדחית', childPolicySave($k1, ['window_start' => '25:00', 'window_end' => '20:00']), 'שעה לא תקינה (HH:MM)');
check('זמנים נשמרים', childPolicySave($k1, ['days' => [0, 1, 2], 'daily_quota_min' => 60,
                                            'window_start' => '15:00', 'window_end' => '19:00']), '');
check('...מסכת הימים', (int) policyFor($k1)['days_mask'], 7);
check('שמירה חלקית לא נגעה במצב', policyFor($k1)['mode'], 'kiosk');
childPolicySave($k1, ['window_start' => '08:00', 'window_end' => '']);
check('חצי חלון מבטל את שניהם', [policyFor($k1)['window_start'], policyFor($k1)['window_end']], ['', '']);
childPolicySave($k1, ['categories' => ['education' => 'allow', 'adult' => 'deny', 'bogus' => 'allow', 'news' => '']]);
check('קטגוריות — רק החלטות תקינות', categoryRulesFor($k1), ['adult' => 'deny', 'education' => 'allow']);
childPolicySave($k1, ['types' => ['video', 'nope'], 'allow_pip' => true]);
check('סוגי תוכן מסוננים', policyFor($k1)['blocked_types'], 'video');
check('מתג נשמר', (int) policyFor($k1)['allow_pip'], 1);

echo "\n— כתובות ויוטיוב —\n";
check('כתובת לא תקינה', childRuleAdd($k1, 'not a url :/')[1], 'הכתובת אינה תקינה');
check('כלל רגיל', childRuleAdd($k1, 'wikipedia.org', 'domain', 'ויקיפדיה')[0], 'הכלל נוסף');
[$msg] = childRuleAdd($k1, 'https://www.youtube.com/@MercazDafYomi');
check('כתובת יוטיוב מנותבת לרשימת יוטיוב', str_starts_with($msg, 'זו כתובת יוטיוב'), true);
check('...ונשמרה כערוץ', one('SELECT kind FROM platform_items WHERE user_id = ?', [$k1])['kind'] ?? null, 'handle');
check('לא נוצר כלל כתובת ליוטיוב', (int) one('SELECT COUNT(*) n FROM rules WHERE user_id = ?', [$k1])['n'], 1);
check('חשבון בלי זכאות — רק "כבוי"', youTubeModesFor($a2), ['off']);
check('...ולכן "מלא" נדחה', childYouTubeSave($k2, 'full', true, true, youTubeModesFor($a2)),
      'יוטיוב לא פתוח בחשבון הזה. אפשר לבקש ממנהל המערכת.');
check('חשבון עם זכאות — מותר', childYouTubeSave($k1, 'restricted', false, true, youTubeModesFor($a1)), '');

echo "\n— פרטי ילד —\n";
$d = childDetail(childOf($a1, $k1), youTubeModesFor($a1));
check('ימים מוחזרים כרשימה', $d['policy']['days'], [0, 1, 2]);
check('יוטיוב: מצב ופריטים', [$d['youtube']['mode'], count($d['youtube']['items'])], ['restricted', 1]);
check('כללים', count($d['rules']), 1);
check('כרטיס: מכסה', $d['quota_min'], 60);

echo "\n— צימוד בקוד —\n";
[$code, $exp] = pairCreate($k1);
check('קוד בן 8 תווים', (bool) preg_match('/^[a-z0-9]{8}$/', $code), true);
check('קוד לא תקין', pairRedeem('abc', 'P', 'd1')[2], 'הקוד לא תקין — 8 אותיות וספרות');
check('קוד שגוי', pairRedeem('zzzzzzzz', 'P', 'd1')[2], 'הקוד לא נמצא או שפג תוקפו. בקשו מההורה קוד חדש.');
[$code2] = pairCreate($k1);
check('קוד חדש מבטל את הקודם', pairRedeem($code, 'P', 'd1')[2], 'הקוד לא נמצא או שפג תוקפו. בקשו מההורה קוד חדש.');
[$pu, $ptok, $err] = pairRedeem(strtoupper(substr($code2, 0, 4)) . '-' . substr($code2, 4), 'Pixel', 'd1');
check('צימוד עם הקוד הנוכחי (גם באותיות גדולות ועם מקף)', $err, '');
check('האסימון מזהה את הילד', (int) (userByToken($ptok)['id'] ?? 0), $k1);
check('קוד שנוצל לא עובד שוב', pairRedeem($code2, 'P2', 'd2')[2], 'הקוד לא נמצא או שפג תוקפו. בקשו מההורה קוד חדש.');
[$code3] = pairCreate($k1);
q('UPDATE pair_codes SET expires_at = ? WHERE child_id = ?', ['2000-01-01T00:00:00Z', $k1]);
check('קוד שפג', pairRedeem($code3, 'P', 'd3')[2], 'הקוד לא נמצא או שפג תוקפו. בקשו מההורה קוד חדש.');
q("UPDATE users SET status = 'suspended' WHERE id = ?", [$k1]);
[$code4] = pairCreate($k1);
check('ילד מושהה לא מצטמד', pairRedeem($code4, 'P', 'd4')[2], 'הילד הזה מושהה. ההורה צריך להחזיר אותו קודם.');
q("UPDATE users SET status = 'active' WHERE id = ?", [$k1]);
check('...ואחרי ההחזרה הקוד עוד תקף', pairRedeem($code4, 'P', 'd4')[2], '');

echo "\n— סיסמה חדשה ומחיקה —\n";
registerDevice($k1, 'Phone', 'p1', 0);
$np = childNewPassword($k1);
check('סיסמה חדשה מנתקת מכשירים', (int) one('SELECT COUNT(*) n FROM devices WHERE user_id = ?', [$k1])['n'], 0);
check('...והחדשה עובדת', password_verify($np, one('SELECT password_hash h FROM users WHERE id = ?', [$k1])['h']), true);
audit($k1, 'nav', false, 'https://x.com', 'not_listed');
childDelete($k1);
check('מחיקה — הילד נעלם', childOf($a1, $k1), null);
check('...והיומן שלו נמחק', (int) one('SELECT COUNT(*) n FROM audit WHERE user_id = ?', [$k1])['n'], 0);
check('...והכללים', (int) one('SELECT COUNT(*) n FROM rules WHERE user_id = ?', [$k1])['n'], 0);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exit($fail === 0 ? 0 : 1);
