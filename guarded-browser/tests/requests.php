<?php
/**
 * בדיקות לבקש גישה ולהתראות להורה (שלב 6).
 *
 *   php guarded-browser/tests/requests.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/gb-req-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
    @rmdir($tmp);
});

require_once __DIR__ . '/../lib/push.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

/** האם הילד יכול לפתוח עכשיו — אותה הכרעה שהשרת נותן במכשיר. */
function opens(int $kid, string $url): bool {
    $u = one('SELECT * FROM users WHERE id = ?', [$kid]);
    $p = policyFor($kid);
    return evaluate($u, $p, ruleSetFor($kid), nowIn((string) $p['timezone']), ['url' => $url, 'main_frame' => true, 'used_today' => 0])['allow'];
}
$user = fn(int $id) => one('SELECT * FROM users WHERE id = ?', [$id]);

db();
[$a1] = accountCreate(['name' => 'כהן', 'modules' => ['yt_filtered']], 'dana@x.com', 'דנה');
[$a2] = accountCreate(['name' => 'לוי', 'modules' => []], 'avi@x.com', 'אבי');
$p1 = one('SELECT * FROM parents WHERE account_id = ?', [$a1]);
$p2 = one('SELECT * FROM parents WHERE account_id = ?', [$a2]);
[$kid] = childCreate($a1, 'נועה');
[$kid2] = childCreate($a2, 'דני');

echo "\n— הילד מבקש —\n";
check('ברירת מחדל: הכול סגור', opens($kid, 'https://he.wikipedia.org/wiki/x'), false);
[$id, $msg, $err] = requestCreate($user($kid), 'https://he.wikipedia.org/wiki/x', 'לשיעורי בית');
check('בקשה נקלטה', [$err, $id > 0], ['', true]);
check('...ונרשם הקוד שבגללו נחסם', one('SELECT code FROM access_requests WHERE id = ?', [$id])['code'], 'not_listed');
check('אותה כתובת שוב — אותה בקשה, לא כפולה', requestCreate($user($kid), 'https://he.wikipedia.org/wiki/x')[0], $id);
check('כתובת לא תקינה', requestCreate($user($kid), 'not a url')[2], 'הכתובת אינה תקינה');
childRuleAdd($kid, 'evil.com', 'domain', '', 'deny');
check('איסור מפורש — אי אפשר לבקש', requestCreate($user($kid), 'https://evil.com/')[2], 'ההורה חסם את זה במפורש, ולכן אי אפשר לבקש');
childRuleAdd($kid, 'ok.org');
check('כבר פתוח — אין צורך', requestCreate($user($kid), 'https://ok.org/a')[2], 'הכתובת כבר פתוחה לך — נסו לפתוח אותה שוב');
childPolicySave($kid, ['daily_quota_min' => 1]);
q('INSERT INTO usage (user_id, day, seconds) VALUES (?, ?, ?)', [$kid, todayIn(policyFor($kid)['timezone']), 120]);
check('חסום בגלל הזמן — אין על מה לבקש', requestCreate($user($kid), 'https://new.org/')[2],
      'החסימה עכשיו היא בגלל הזמן, לא בגלל האתר — אין על מה לבקש');
childPolicySave($kid, ['daily_quota_min' => 0]);
check('הילד רואה את הבקשה שלו ממתינה', requestsOfChild($kid)[0]['status'] ?? '', 'pending');

echo "\n— מגבלות —\n";
for ($i = 0; $i < 12; $i++) $last = requestCreate($user($kid2), "https://site$i.org/");
check('לא יותר מ-10 ממתינות', $last[2], 'יש כבר הרבה בקשות שמחכות להורה. חכו שיענה עליהן.');
check('ספירה — 10', requestPendingCount($a2), 10);

echo "\n— ההורה רואה רק את החשבון שלו —\n";
$list = requestList($a1);
check('ברשימה של דנה — הבקשה של נועה', [$list[0]['child'] ?? '', $list[0]['host'] ?? '', $list[0]['note'] ?? ''],
      ['נועה', 'he.wikipedia.org', 'לשיעורי בית']);
check('אצל דנה אין בקשות של לוי', count(array_filter($list, fn($r) => $r['child_id'] === $kid2)), 0);
check('הורה מחשבון אחר לא יכול להכריע', requestDecide($a2, (int) $p2['id'], $id, 'approve', 'site')[1], 'הבקשה לא נמצאה');
check('הכרטיס של הילד מראה בקשה ממתינה', childCard($user($kid))['requests'], 1);

echo "\n— אישור: הדף הזה —\n";
check('היקף לא מוכר', requestDecide($a1, (int) $p1['id'], $id, 'approve', 'forever')[1], 'אי אפשר לאשר כך את הכתובת הזו');
[$m, $e] = requestDecide($a1, (int) $p1['id'], $id, 'approve', 'page');
check('אושר', $e, '');
check('הדף נפתח', opens($kid, 'https://he.wikipedia.org/wiki/x'), true);
check('דף אחר באתר — עדיין לא', opens($kid, 'https://he.wikipedia.org/wiki/y'), false);
check('הבקשה מסומנת כמאושרת, עם ההיקף', array_values(array_intersect_key(
      one('SELECT * FROM access_requests WHERE id = ?', [$id]), ['status' => 1, 'scope' => 1])), ['approved', 'page']);
check('הכרעה שנייה על אותה בקשה', requestDecide($a1, (int) $p1['id'], $id, 'deny')[1], 'כבר טיפלו בבקשה הזו');
check('הילד רואה שאושר', requestsOfChild($kid)[0]['status'], 'approved');

echo "\n— אישור: כל האתר, ובקשות שנפתחות איתו —\n";
[$s1] = requestCreate($user($kid), 'https://www.khanacademy.org/math');
[$s2] = requestCreate($user($kid), 'https://khanacademy.org/science');
[$m] = requestDecide($a1, (int) $p1['id'], $s1, 'approve', 'site');
check('כל האתר נפתח', opens($kid, 'https://khanacademy.org/anything'), true);
check('הבקשה השנייה לאותו אתר נסגרה יחד', one('SELECT status FROM access_requests WHERE id = ?', [$s2])['status'], 'approved');
check('...וההודעה אומרת את זה', str_contains($m, 'עוד 1'), true);
check('"כל האתר" מופיע כאריח', (int) one("SELECT show_tile FROM rules WHERE user_id = ? AND pattern = 'khanacademy.org'", [$kid])['show_tile'], 1);

echo "\n— אישור: לשעה —\n";
[$h] = requestCreate($user($kid), 'https://scratch.mit.edu/');
requestDecide($a1, (int) $p1['id'], $h, 'approve', 'hour');
check('נפתח עכשיו', opens($kid, 'https://scratch.mit.edu/projects'), true);
$rev = policyRev($kid);
q("UPDATE rules SET expires_at = ? WHERE pattern = 'scratch.mit.edu'", [gmdate('Y-m-d\TH:i:s\Z', time() - 5)]);
check('אחרי שעה — נסגר', opens($kid, 'https://scratch.mit.edu/projects'), false);
check('...וטביעת האצבע השתנתה, כדי שהמכשיר ימשוך מדיניות', policyRev($kid) !== $rev, true);
check('כלל שפג אינו מוצג להורה ברשימת האתרים',
      count(array_filter(childDetail($user($kid), ['off'])['rules'], fn($r) => $r['pattern'] === 'scratch.mit.edu')), 0);
q("UPDATE rules SET expires_at = ? WHERE pattern = 'scratch.mit.edu'", [gmdate('Y-m-d\TH:i:s\Z', time() - 2 * 86400)]);
requestsCleanup();
check('ניקוי מוחק כלל שפג לפני יותר מיום', one("SELECT COUNT(*) n FROM rules WHERE pattern = 'scratch.mit.edu'")['n'], 0);

echo "\n— דחייה —\n";
[$d] = requestCreate($user($kid), 'https://games.example.com/');
check('נדחה', requestDecide($a1, (int) $p1['id'], $d, 'deny')[1], '');
check('ועדיין חסום', opens($kid, 'https://games.example.com/'), false);
check('הילד רואה שנדחה', array_values(array_filter(requestsOfChild($kid), fn($r) => $r['id'] === $d))[0]['status'], 'denied');

echo "\n— יוטיוב —\n";
childYouTubeSave($kid, 'restricted', false, false, ['off', 'restricted']);
q("INSERT INTO video_owner (platform, video_id, channel_id, handle, title, fetched_at) VALUES ('youtube', 'abcdefghijk', 'UCxxxxxxxxxxxxxxxxxxxxxx', 'kidschan', 'סרטון', ?)", [nowIso()]);
q("INSERT INTO video_owner (platform, video_id, channel_id, handle, title, fetched_at) VALUES ('youtube', 'bcdefghijkl', 'UCxxxxxxxxxxxxxxxxxxxxxx', 'kidschan', 'עוד', ?)", [nowIso()]);
[$y1, , $ye] = requestCreate($user($kid), 'https://m.youtube.com/watch?v=abcdefghijk');
check('סרטון לא מאושר — אפשר לבקש', $ye, '');
check('ביוטיוב אין "לשעה"', requestList($a1)[0]['scopes'], ['page', 'site']);
check('ביוטיוב "לשעה" נדחה', requestDecide($a1, (int) $p1['id'], $y1, 'approve', 'hour')[1], 'אי אפשר לאשר כך את הכתובת הזו');
requestDecide($a1, (int) $p1['id'], $y1, 'approve', 'page');
check('הסרטון נפתח', opens($kid, 'https://m.youtube.com/watch?v=abcdefghijk'), true);
check('סרטון אחר באותו ערוץ — עדיין לא', opens($kid, 'https://m.youtube.com/watch?v=bcdefghijkl'), false);
check('נוצר פריט יוטיוב, לא כלל כתובת', one("SELECT COUNT(*) n FROM rules WHERE user_id = ? AND pattern LIKE '%youtube%'", [$kid])['n'], 0);
[$y2] = requestCreate($user($kid), 'https://m.youtube.com/watch?v=bcdefghijkl');
requestDecide($a1, (int) $p1['id'], $y2, 'approve', 'site');
check('"כל האתר" ביוטיוב = הערוץ של הסרטון', one("SELECT kind || ':' || item_id k FROM platform_items WHERE user_id = ? AND kind = 'handle'", [$kid])['k'] ?? '', 'handle:kidschan');
check('ועכשיו כל סרטון בערוץ נפתח', opens($kid, 'https://m.youtube.com/watch?v=bcdefghijkl'), true);

echo "\n— התראות —\n";
check('כתובת שירות מוכרת', pushEndpointOk('https://fcm.googleapis.com/fcm/send/abc'), true);
check('תת-דומיין של שירות מוכר', pushEndpointOk('https://xyz.notify.windows.com/w/?token=1'), true);
check('שרת זר — נדחה', pushSubscribe((int) $p1['id'], 'https://evil.com/fcm.googleapis.com'), 'שירות ההתראות של הדפדפן הזה אינו נתמך');
check('http — נדחה', pushEndpointOk('http://fcm.googleapis.com/x'), false);
$keys = pushKeys();
check('נוצר מפתח VAPID (65 בתים)', $keys ? strlen(base64_decode(strtr($keys['public'], '-_', '+/'))) : 0, 65);
check('המפתח נשמר ולא נוצר מחדש', pushKeys()['public'], $keys['public']);
$jwt = explode('.', pushJwt('https://fcm.googleapis.com/fcm/send/abc', $keys['private']));
check('JWT: aud הוא המקור של השירות', json_decode(base64_decode(strtr($jwt[1], '-_', '+/')), true)['aud'], 'https://fcm.googleapis.com');
check('JWT: חתימה r||s באורך 64', strlen(base64_decode(strtr($jwt[2], '-_', '+/') . '==')), 64);

sleep(1);   // הרישום מסמן "מעכשיו"; בקשה באותה שנייה הייתה נחשבת ישנה
check('רישום תקין', pushSubscribe((int) $p1['id'], 'https://fcm.googleapis.com/fcm/send/dana'), '');
pushSubscribe((int) $p2['id'], 'https://fcm.googleapis.com/fcm/send/avi');
$rung = [];
$fake = function (string $ep) use (&$rung) { $rung[] = $ep; return 201; };
check('אין בקשה חדשה מאז הרישום — אין צלצול', pushFlush($fake), 0);
sleep(1);
[$n] = requestCreate($user($kid), 'https://new-site.org/page', 'בבקשה');
$rung = [];
check('בקשה חדשה — צלצול', pushFlush($fake), 1);
check('...רק למכשיר של דנה, לא של אבי', $rung, ['https://fcm.googleapis.com/fcm/send/dana']);
$rung = [];
check('צלצול פעם אחת בלבד', pushFlush($fake), 0);
pushSubscribe((int) $p1['id'], 'https://fcm.googleapis.com/fcm/send/dana-tablet');
q("UPDATE push_subs SET delivered_at = ? WHERE endpoint LIKE '%dana-tablet'", [gmdate('Y-m-d\TH:i:s\Z', time() - 60)]);
$items = pushPending($p1, 'https://fcm.googleapis.com/fcm/send/dana');
check('הטלפון שואל — מקבל את הבקשה', [$items[0]['title'] ?? '', $items[0]['body'] ?? '', $items[0]['link'] ?? ''],
      ['נועה מבקש/ת גישה', 'new-site.org — "בבקשה"', "./?req=$n"]);
check('ונמסר פעם אחת', pushPending($p1, 'https://fcm.googleapis.com/fcm/send/dana'), []);
check('...אבל גם לטאבלט של דנה (כל מכשיר בנפרד)', count(pushPending($p1, 'https://fcm.googleapis.com/fcm/send/dana-tablet')), 1);
check('מכשיר לא מוכר — רק מה שחדש מ-10 הדקות האחרונות', count(pushPending($p1, 'https://fcm.googleapis.com/x')), 1);
check('אבי לא מקבל בקשות של דנה', pushPending($p2, 'https://fcm.googleapis.com/fcm/send/avi'), []);
sleep(1);
requestCreate($user($kid), 'https://another.org/');
$gone = function (string $ep) { return 410; };
pushFlush($gone);
check('שירות אומר "אין מכשיר כזה" — נמחק', (int) one('SELECT COUNT(*) n FROM push_subs WHERE parent_id = ?', [$p1['id']])['n'], 0);
check('גם JOIN על הורה שנמחק לא נופל', pushFlush($fake) >= 0, true);

echo "\n— מחיקת ילד —\n";
childDelete($kid);
check('הבקשות שלו נמחקות', (int) one('SELECT COUNT(*) n FROM access_requests WHERE child_id = ?', [$kid])['n'], 0);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
