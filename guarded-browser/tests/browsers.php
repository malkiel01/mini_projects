<?php
/**
 * מסך הדפדפנים אצל ההורה (lib/browsers.php): מה מותקן לפי הדוח, ומה כל דפדפן מקבל.
 *
 *   php guarded-browser/tests/browsers.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-browsers-' . getmypid();
@mkdir($tmp, 0775, true); putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../lib/browsers.php';
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
[$acc2] = accountCreate(['name' => 'לוי', 'level_max' => 'browser'], 'avi@x.com', 'אבי');
[$kid] = childCreate($acc, 'דני');
[$kid2] = childCreate($acc2, 'נוי');

check('חשבון בלי ניהול אפליקציות — רק מגדלור', [browsersFor($acc2, $kid2)['apps'], array_column(browsersFor($acc2, $kid2)['browsers'], 'kind')], [false, ['migdalor']]);
check('עוד לא דווח', [browsersFor($acc, $kid)['reported'], count(browsersFor($acc, $kid)['browsers'])], [false, 1]);

q("INSERT INTO devices (user_id, device_name, token_hash, perms, created_at, enforce_report) VALUES (?, 'גלקסי', 'h1', 32, ?, ?)",
  [$kid, nowIso(), json_encode(['browsers' => [['p' => 'org.mozilla.firefox', 'n' => 'Firefox'], ['p' => 'com.android.chrome', 'n' => 'Chrome'],
                                               ['p' => 'com.mbeplus.guardedbrowser', 'n' => 'מגדלור']]])]);
q("INSERT INTO devices (user_id, device_name, token_hash, perms, created_at, enforce_report) VALUES (?, 'טאבלט', 'h2', 0, ?, ?)",
  [$kid, nowIso(), json_encode(['browsers' => [['p' => 'com.android.chrome', 'n' => 'Chrome']]])]);
appsSaveRule($kid, 'org.mozilla.firefox', 'block');
chromeManagedSave($kid, true);
$b = browsersFor($acc, $kid);
check('הסדר: מגדלור, כרום, השאר — כל אחד פעם אחת', array_column($b['browsers'], 'package'), ['com.mbeplus.guardedbrowser', 'com.android.chrome', 'org.mozilla.firefox']);
$by = array_column($b['browsers'], null, 'package');
check('כרום — מנוהל, בשני המכשירים', [$by['com.android.chrome']['managed'], $by['com.android.chrome']['devices']], [true, ['גלקסי', 'טאבלט']]);
check('פיירפוקס — אחר, חסום', [$by['org.mozilla.firefox']['kind'], $by['org.mozilla.firefox']['blocked']], ['other', true]);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
