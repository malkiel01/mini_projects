<?php
/**
 * בדיקות למסלול ההקמה — דיווח ההרשאות מהמכשיר, ופענוחן אצל ההורה.
 *
 *   php guarded-browser/tests/setup.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-setup-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp);
});
require_once __DIR__ . '/../lib/children.php';
require_once __DIR__ . '/../lib/appversion.php';
$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

db();
[$a] = accountCreate(['name' => 'כהן'], 'dana@x.com', 'דנה');
[$kid] = childCreate($a, 'נועה');
$tok = registerDevice($kid, 'Galaxy', 'g1', 2);
$dev = fn() => one('SELECT * FROM devices WHERE token_hash = ?', [tokenHash($tok)]);

echo "\n— פענוח ההרשאות —\n";
check('ריק — חסר הכול, לא תקין', devicePermsState(0)['ok'], false);
check('...וכולן חיוניות', devicePermsState(0)['required_missing'], ['התראות', 'ריצה רציפה', 'ניהול אפליקציות', 'ניהול מכשיר', 'עדכונים']);
check('רק התראות וריצה (3) — כבר לא מספיק', devicePermsState(PERM_NOTIF | PERM_BATTERY)['ok'], false);
check('...וחסר ברשימה', devicePermsState(PERM_NOTIF | PERM_BATTERY)['required_missing'], ['ניהול אפליקציות', 'ניהול מכשיר', 'עדכונים']);
check('הכול (31) — נכנס, אבל לא הושלם', [devicePermsState(31)['ok'], devicePermsState(31)['complete']], [true, false]);
check('...חסר "בעל מכשיר", ומסומן להורה', devicePermsState(31)['missing'], ['בעל מכשיר']);
check('בעל מכשיר (63) — הושלם', [devicePermsState(63)['owner'], devicePermsState(63)['complete']], [true, true]);
check('רק התראות — עוד חסרה ריצה', devicePermsState(PERM_NOTIF)['ok'], false);
check('ניהול דלוק', devicePermsState(PERM_ADMIN | PERM_REQUIRED)['admin'], true);

echo "\n— דיווח מהמכשיר —\n";
check('ברירת מחדל 0', (int) $dev()['perms'], 0);
$_SERVER['HTTP_X_MIGDALOR_PERMS'] = (string) (PERM_NOTIF | PERM_BATTERY | PERM_ADMIN);
userByToken($tok);
check('נשמר מהכותרת', (int) $dev()['perms'], 7);
unset($_SERVER['HTTP_X_MIGDALOR_PERMS']);
userByToken($tok);
check('בלי כותרת — לא משתנה (גרסה ישנה)', (int) $dev()['perms'], 7);
$_SERVER['HTTP_X_MIGDALOR_PERMS'] = '31';
userByToken($tok);
check('עדכון למצב חדש', (int) $dev()['perms'], 31);
unset($_SERVER['HTTP_X_MIGDALOR_PERMS']);

echo "\n— בגיליון הילד —\n";
$detail = childDetail(one('SELECT * FROM users WHERE id = ?', [$kid]), ['off']);
check('רשימת המכשירים כוללת perms', (int) $detail['device_list'][0]['perms'], 31);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
