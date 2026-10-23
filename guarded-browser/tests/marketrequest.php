<?php
/**
 * בקשה מהמרקט (lib/apprequests.php, marketRequestCreate): הילד מבקש אפליקציה מהמלאי, ההורה מאשר = שחרור.
 *
 *   php guarded-browser/tests/marketrequest.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-mreq-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../lib/apprequests.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

db();
[$acc, $parent] = accountCreate(['name' => 'כהן', 'level_max' => 'apps'], 'dana@x.com', 'דנה');
[$acc2] = accountCreate(['name' => 'לוי', 'level_max' => 'apps'], 'avi@x.com', 'אבי');
[$kid] = childCreate($acc, 'נועה');
$user = ['id' => $kid, 'account_id' => $acc] + (array) one('SELECT * FROM users WHERE id = ?', [$kid]);
$parent = (int) ($parent ?? 0);
$pkg = 'com.duolingo';

echo "\n— מה אפשר לבקש —\n";
$more = array_column(marketRequestable($kid), 'p');
check('כל המלאי, כשעוד לא שוחרר כלום', in_array($pkg, $more, true), true);
check('...וגם במה שהמכשיר מקבל', in_array($pkg, array_column(appsPayload($user)['market_more'], 'p'), true), true);

echo "\n— הבקשה —\n";
[$id, $msg, $err] = marketRequestCreate($user, $pkg, 'בשביל אנגלית');
check('נוצרה', [$id > 0, $err], [true, '']);
$r = one('SELECT * FROM access_requests WHERE id = ?', [$id]);
check('בקשת אפליקציה עם code=market, בשם מהמלאי', [$r['kind'], $r['code'], $r['host'] !== ''], ['app', 'market', true]);
check('בקשה כפולה — אותה בקשה, בלי חדשה', marketRequestCreate($user, $pkg)[0], $id);
check('לא במלאי — נדחה', marketRequestCreate($user, 'com.not.in.market')[2], 'האפליקציה לא במרקט');
check('אצל ההורה — עם code', array_values(array_filter(requestList($acc), fn($x) => $x['id'] === $id))[0]['code'] ?? '', 'market');
check('לא נשלחת למכשיר כהחלטה להחלה', array_filter(appDecisions($kid), fn($d) => $d['id'] === $id), []);

echo "\n— ההחלטה —\n";
check('"זמני" — לא לבקשה מהמרקט', appRequestDecide($acc, $parent, $id, 'temporary', 30)[1], 'בקשה מהמרקט — מאשרים או דוחים');
check('חשבון אחר — לא נמצאה', appRequestDecide($acc2, 0, $id, 'permanent')[1], 'הבקשה לא נמצאה');
[$m, $e] = appRequestDecide($acc, $parent, $id, 'permanent');
check('אישור', $e, '');
check('= שחרור במרקט', in_array($pkg, marketReleased($kid), true), true);
check('הבקשה אושרה, בלי לחכות למכשיר', [one('SELECT status FROM access_requests WHERE id = ?', [$id])['status'],
      one('SELECT apply_state FROM access_requests WHERE id = ?', [$id])['apply_state']], ['approved', 'applied']);
check('כבר לא ברשימה לבקשה', in_array($pkg, array_column(marketRequestable($kid), 'p'), true), false);
check('בקשה על משוחררת — נדחית עם הסבר', marketRequestCreate($user, $pkg)[2], 'ההורה כבר שחרר אותה — היא ב"📲 אפליקציות"');
check('ומגיעה למכשיר כמשוחררת', in_array($pkg, array_column(appsPayload($user)['market'], 'p'), true), true);

echo "\n— סירוב —\n";
[$id2] = marketRequestCreate($user, 'com.waze');
check('סירוב', appRequestDecide($acc, $parent, $id2, 'deny')[1], '');
check('לא שוחררה', in_array('com.waze', marketReleased($kid), true), false);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
