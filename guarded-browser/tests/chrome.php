<?php
/**
 * בדיקות לכרום מנוהל (lib/chrome.php): הרשימות שכרום מקבל, איסור גובר על היתר, יוטיוב, הדוח והתצוגה.
 *
 *   php guarded-browser/tests/chrome.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-chrome-' . getmypid();
@mkdir($tmp, 0775, true); putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../lib/appcontrol.php';
require_once __DIR__ . '/../lib/enforce.php';
require_once __DIR__ . '/../lib/history.php';
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
[$kid] = childCreate($acc, 'נועה');
$user = ['id' => $kid, 'account_id' => $acc] + (array) one('SELECT * FROM users WHERE id = ?', [$kid]);
$rule = fn(string $pattern, string $scope, string $action) =>
    q('INSERT INTO rules (user_id, label, pattern, scope, action, created_at) VALUES (?,?,?,?,?,?)', [$kid, $pattern, $pattern, $scope, $action, nowIso()]);

echo "\n— כבוי —\n";
check('ברירת מחדל — לא מנוהל', chromePolicy($kid), ['managed' => false]);
check('...וכך גם במה שהמכשיר מקבל', appsPayload($user)['chrome'], ['managed' => false]);

echo "\n— \"הכול חסום\" —\n";
q("UPDATE policies SET posture = 'deny_all' WHERE user_id = ?", [$kid]);
$rule('https://wikipedia.org/', 'domain', 'allow');
$rule('https://www.khanacademy.org/math/algebra', 'exact', 'allow');
$rule('https://example.com/', 'domain', 'deny');
$rule('https://example.com/ok', 'exact', 'allow');
$rule('https://cdn.task.com/', 'resource', 'allow');
chromeManagedSave($kid, true);
$p = chromePolicy($kid);
check('מנוהל, לכל ערוצי כרום', [$p['managed'], $p['packages']], [true, CHROME_PACKAGES]);
check('חסימה: הכול (*), האיסור המפורש ויוטיוב (לא "הכול מותר")', $p['block'],
      ['*', 'example.com', 'youtube.com', 'youtu.be', 'youtube-nocookie.com']);
check('היתר: האתר, והדף המדויק (שרת ונתיב) — בלי משאב של משימה ובלי היתר תחת איסור', $p['allow'],
      ['khanacademy.org/math/algebra', 'wikipedia.org']);
check('יוטיוב בכרום — סגור', $p['youtube'], false);

echo "\n— יוטיוב —\n";
q("INSERT INTO platform_rules (user_id, platform, mode, created_at) VALUES (?, 'youtube', 'restricted', ?)", [$kid, nowIso()]);
$rule('https://youtube.com/', 'domain', 'allow');
check('ערוצים מאושרים בלבד — עדיין חסום בכרום (הרשימות לא עוברות), גם היתר מפורש לא פותח',
      [in_array('youtube.com', chromePolicy($kid)['block'], true), in_array('youtube.com', chromePolicy($kid)['allow'], true)], [true, false]);
q("UPDATE platform_rules SET mode = 'full' WHERE user_id = ?", [$kid]);
$p = chromePolicy($kid);
check('"הכול מותר" — פתוח בכרום (במצב מוגבל)', [in_array('youtube.com', $p['block'], true), $p['youtube']], [false, true]);

echo "\n— \"הכול פתוח\" וקטגוריות —\n";
q("UPDATE policies SET posture = 'allow_all' WHERE user_id = ?", [$kid]);
$set = ruleSetFor($kid);
$set['domain_map'] = ['casino.com' => ['gambling'], 'news.com' => ['news'], 'wikipedia.org' => ['gambling']];
$set['categories'] = ['gambling' => 'deny', 'news' => 'allow'];
$p = chromePolicy($kid, $set);
check('בלי *: רק האיסור המפורש ואתרי הקטגוריה האסורה', $p['block'], ['example.com', 'casino.com', 'wikipedia.org']);
check('היתר מפורש על אתר בקטגוריה אסורה — נשאר (כמו במנוע); קטגוריה מותרת — בהיתר', $p['allow'],
      ['khanacademy.org/math/algebra', 'news.com', 'wikipedia.org', 'youtube.com']);
q("UPDATE policies SET posture = 'deny_all' WHERE user_id = ?", [$kid]);
check('ב"הכול חסום" קטגוריה אסורה לא נשלחת (* כבר חוסם)', in_array('casino.com', chromePolicy($kid, ['categories' => $set['categories']] + $set)['block'], true), false);

echo "\n— מגבלת 1000 —\n";
$big = ruleSetFor($kid);
for ($i = 0; $i < 1005; $i++) $big['rules'][] = ['pattern' => "https://site$i.com/", 'scope' => 'domain', 'action' => 'allow', 'enabled' => 1];
$p = chromePolicy($kid, $big);
check('נחתך ל-1000, ומסומן', [count($p['allow']), $p['truncated']], [1000, true]);

echo "\n— המכשיר מושך מחדש —\n";
chromeManagedSave($kid, false);
$r1 = policyRev($kid);
chromeManagedSave($kid, true);
check('הפעלה משנה את טביעת המדיניות', policyRev($kid) !== $r1, true);
$snap = policySnapshot($kid);
$was = $snap; $was['policy']['chrome_managed'] = 0;
check('בהיסטוריה — שינוי עם תווית', count(array_filter(policyDiff($was, $snap), fn($l) => str_contains($l, 'כרום מנוהל'))), 1);

echo "\n— הדוח והתצוגה —\n";
q("INSERT INTO devices (user_id, device_name, token_hash, perms, created_at) VALUES (?, 'גלקסי', 'h1', 32, ?)", [$kid, nowIso()]);
$dev = (int) db()->lastInsertId();
enforceReportSave($dev, ['rev' => policyRev($kid), 'owner' => true, 'access' => true, 'chrome' => 'verified',
                         'browsers' => [['p' => 'com.android.chrome', 'n' => 'Chrome', 'blocked' => false, 'managed' => true]]]);
$st = enforcementState($acc, $kid);
$row = fn(string $k) => array_values(array_filter(enforcementState($acc, $kid)['rows'], fn($r) => $r['key'] === $k))[0] ?? null;
check('שורת כרום — נאכף', $row('chrome')['status'] ?? null, 'full');
check('כרום מנוהל לא נחשב "דפדפן פתוח"', $row('web_other')['status'] ?? null, 'full');
enforceReportSave($dev, ['rev' => policyRev($kid), 'owner' => true, 'chrome' => 'fail',
                         'browsers' => [['p' => 'com.android.chrome', 'n' => 'Chrome', 'blocked' => false, 'managed' => false]]]);
$st = enforcementState($acc, $kid);
check('נכשל — לא נאכף, וכרום חוזר להיות "פתוח"', [$row('chrome')['status'], $row('web_other')['status']], ['none', 'none']);
enforceReportSave($dev, ['rev' => policyRev($kid), 'chrome' => 'hacked']);
check('תוצאה לא מוכרת מהמכשיר — לא נשמרת', json_decode(one('SELECT enforce_report FROM devices WHERE id = ?', [$dev])['enforce_report'], true)['chrome'], '');
$cs = appsList($acc, $kid)['chrome'];
check('אצל ההורה: מנוהל, ולכל מכשיר — מה דיווח', [$cs['managed'], $cs['devices'][0]['name'], $cs['devices'][0]['owner']], [true, 'גלקסי', true]);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
