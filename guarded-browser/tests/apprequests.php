<?php
/**
 * בקשת פתיחה לאפליקציה חסומה (lib/apprequests.php) — אפיון 2026-10-22.
 *
 *   php guarded-browser/tests/apprequests.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-appreq-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../lib/children.php';
require_once __DIR__ . '/../lib/apprequests.php';
require_once __DIR__ . '/../lib/push.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

db();
[$acc] = accountCreate(['name' => 'כהן', 'level_max' => 'apps'], 'dana@x.com', 'דנה');
q("UPDATE accounts SET status = 'active' WHERE id = ?", [$acc]);
[$kid] = childCreate($acc, 'נועה');
$token = registerDevice($kid, 'Galaxy', 'g1', 5);
$user = fn() => userByToken($token);
$parent = (int) one('SELECT id FROM parents WHERE account_id = ?', [$acc])['id'];
appsReport($kid, [['p' => 'com.roblox.client', 'n' => 'Roblox'], ['p' => 'com.whatsapp', 'n' => 'WhatsApp']]);
appsSaveRule($kid, 'com.roblox.client', 'block');
$row = fn(int $id) => one('SELECT * FROM access_requests WHERE id = ?', [$id]);

echo "\n— הילד מבקש —\n";
[$id, $msg, $err] = appRequestCreate($user(), 'com.roblox.client', 'סיימתי שיעורים');
check('אפליקציה חסומה — נשלחה', [$id > 0, $err], [true, '']);
check('עם השם, המכשיר וההערה', [$row($id)['host'], (int) $row($id)['device_id'] > 0, $row($id)['note']], ['Roblox', true, 'סיימתי שיעורים']);
[$id2, $msg2] = appRequestCreate($user(), 'com.roblox.client');
check('בקשה כפולה — אותה בקשה, בלי חדשה', [$id2, str_contains($msg2, 'כבר אצל ההורה')], [$id, true]);
check('...ובלי שורה נוספת', (int) one("SELECT COUNT(*) n FROM access_requests WHERE kind = 'app'")['n'], 1);
check('אפליקציה פתוחה — אין על מה לבקש', appRequestCreate($user(), 'com.whatsapp')[2], 'האפליקציה כבר פתוחה — נסו לפתוח אותה שוב');
check('מגדלור / מסך ההתקנה — תמיד פתוחים', appRequestCreate($user(), 'com.android.packageinstaller')[2], 'האפליקציה הזו תמיד פתוחה');
check('נכנסת להתראה להורה', count(pushPending(one('SELECT * FROM parents WHERE id = ?', [$parent]), 'x')), 1);
$pp = pushPending(one('SELECT * FROM parents WHERE id = ?', [$parent]), 'y');
check('...בניסוח של אפליקציה', str_contains($pp[0]['title'] ?? '', 'לפתוח אפליקציה'), true);

echo "\n— רק ההורים של המשפחה —\n";
[$acc2] = accountCreate(['name' => 'לוי', 'level_max' => 'apps'], 'avi@x.com', 'אבי');
$parent2 = (int) one('SELECT id FROM parents WHERE account_id = ?', [$acc2])['id'];
check('הורה ממשפחה אחרת — לא נמצאה', appRequestDecide($acc2, $parent2, $id, 'permanent')[1], 'הבקשה לא נמצאה');
check('ובקשת אפליקציה לא עוברת במסלול של אתרים', requestDecide($acc, $parent, $id, 'approve')[1], 'הבקשה לא נמצאה');

echo "\n— אישור קבוע —\n";
$rev0 = policyRev($kid);
[$m, $e] = appRequestDecide($acc, $parent, $id, 'permanent');
check('אושר — כלל "מותר" לאפליקציה', [$e, appRules($kid)['com.roblox.client']], ['', 'allow']);
check('ממתין לאישור ביצוע מהמכשיר (לא "נפתח")', $row($id)['apply_state'], 'pending');
check('הטביעה השתנתה — המכשיר ימשוך', policyRev($kid) !== $rev0, true);
check('ההחלטה נשלחת למכשיר', appDecisions($kid)[0]['scope'] ?? '', 'permanent');
check('הכרעה שנייה — "כבר טיפלו" (לא דורסת)', appRequestDecide($acc, $parent, $id, 'deny')[1], 'כבר טיפלו בבקשה הזו');
check('המכשיר מאשר ביצוע', [appRequestApplied($user(), $id, true), $row($id)['apply_state']], ['', 'applied']);

echo "\n— אישור זמני —\n";
appsSaveRule($kid, 'com.roblox.client', 'block');
[$t] = appRequestCreate($user(), 'com.roblox.client');
check('משך לא מוכר — נדחה', appRequestDecide($acc, $parent, $t, 'temporary', 7)[1], 'משך לא מוכר');
appRequestDecide($acc, $parent, $t, 'temporary', 30);
$g = appTempGrants($kid);
check('נשלח למכשיר — 30 דקות, עוד בלי סוף (מתחיל בהחלה)', [$g[0]['id'] ?? 0, $g[0]['minutes'] ?? 0, $g[0]['ends_at'] ?? 'x'], [$t, 30, '']);
check('הכלל הקבוע לא השתנה — עדיין חסום', appRules($kid)['com.roblox.client'], 'block');
check('בקשה חדשה בזמן שאושר ועוד לא הוחל — לא חדשה', appRequestCreate($user(), 'com.roblox.client')[0], $t);
appRequestApplied($user(), $t, true, '', (time() + 999 * 3600) * 1000);
$ends = strtotime($row($t)['ends_at']);
check('המכשיר לא מאריך לעצמו — הסוף חסום ל-30 דקות (+2)', $ends <= time() + 32 * 60 && $ends > time() + 29 * 60, true);
appRequestApplied($user(), $t, true, '', (time() + 5) * 1000);
check('אישור ביצוע חוזר לא מזיז את הסוף', strtotime($row($t)['ends_at']), $ends);
check('פעיל — בקשה חדשה: כבר פתוחה', appRequestCreate($user(), 'com.roblox.client')[2], 'האפליקציה כבר פתוחה — נסו לפתוח אותה שוב');

echo "\n— סוף ההיתר, ביטול, ותשובה מאוחרת —\n";
q('UPDATE access_requests SET ends_at = ? WHERE id = ?', [gmdate('Y-m-d\TH:i:s\Z', time() - 1), $t]);
check('הסתיים — לא נשלח עוד למכשיר', appTempGrants($kid), []);
[$t2] = appRequestCreate($user(), 'com.roblox.client');
appRequestDecide($acc, $parent, $t2, 'temporary', 60);
appsSaveRule($kid, 'com.roblox.client', 'block');     // ההורה חזר בו וחסם
check('כלל חדש של ההורה מבטל היתר זמני פעיל', $row($t2)['apply_state'], 'revoked');
check('...והוא לא נשלח עוד', appTempGrants($kid), []);
check('אישור ביצוע מאוחר לא מחזיר אותו', [appRequestApplied($user(), $t2, true), $row($t2)['apply_state']],
      ['הבקשה כבר לא פעילה', 'revoked']);

echo "\n— ההחלה נכשלה —\n";
[$t3] = appRequestCreate($user(), 'com.roblox.client');
appRequestDecide($acc, $parent, $t3, 'temporary', 15);
appRequestApplied($user(), $t3, false, 'setPackagesSuspended: החבילה לא נמצאה');
$l = array_values(array_filter(requestList($acc), fn($r) => $r['id'] === $t3))[0];
check('ההורה רואה "נכשל" ואת הסיבה', [$l['apply_state'], $l['apply_error']], ['failed', 'setPackagesSuspended: החבילה לא נמצאה']);
check('...ואת המכשיר והחבילה', [$l['device'], $l['package'], $l['kind']], ['Galaxy', 'com.roblox.client', 'app']);

echo "\n— סירוב —\n";
[$t4] = appRequestCreate($user(), 'com.roblox.client');
appRequestDecide($acc, $parent, $t4, 'deny');
check('נדחה — נשלח למכשיר כדי להודיע לילד', array_values(array_filter(appDecisions($kid), fn($d) => $d['id'] === $t4))[0]['status'], 'denied');
check('אישור ביצוע לבקשה שנדחתה — נדחה', appRequestApplied($user(), $t4, true), 'הבקשה כבר לא פעילה');

echo "\n— ילד אחר לא מאשר בשם הילד הזה —\n";
[$kid2] = childCreate($acc, 'דני');
$tok2 = registerDevice($kid2, 'Redmi', 'r1', 5);
check('אישור ביצוע מהמכשיר של ילד אחר — לא נמצאה', appRequestApplied(userByToken($tok2), $t3, true), 'הבקשה לא נמצאה');

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
