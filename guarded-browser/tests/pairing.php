<?php
/**
 * צימוד מכשיר בקוד חד-פעמי — גם תחת בקשות בו-זמניות (סקירה, 2026-10-20 — שלב 8).
 *
 *   php guarded-browser/tests/pairing.php
 *
 * קודם: SELECT ‏used_at='' ← רישום מכשיר ← UPDATE. שתי בקשות עם אותו קוד באותו רגע עברו שתיהן, וקוד
 * חד-פעמי צימד כמה מכשירים. כאן 8 תהליכים נפרדים (כמו 8 בקשות HTTP) מממשים את אותו קוד יחד.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// תהליך-בן: מחכה לאות ההתחלה, מנסה לממש, ומדפיס OK או את השגיאה.
if (($argv[1] ?? '') === '--redeem') {
    putenv('GB_DATA_DIR=' . $argv[2]);
    require_once __DIR__ . '/../lib/children.php';
    db();
    while (!file_exists($argv[2] . '/go')) usleep(500);
    [$u, $token, $err] = pairRedeem($argv[3], 'P', $argv[4]);
    echo $err === '' && $token !== '' ? 'OK' : 'ERR:' . $err;
    exit;
}

$tmp = sys_get_temp_dir() . '/gb-pair-' . getmypid();
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
[$acc] = accountCreate(['name' => 'כהן'], 'dana@x.com', 'דנה');
q("UPDATE accounts SET status = 'active', max_devices = 0 WHERE id = ?", [$acc]);
[$kid] = childCreate($acc, 'נועה');
q('UPDATE policies SET max_devices = 20 WHERE user_id = ?', [$kid]);
$devices = fn() => (int) one('SELECT COUNT(*) n FROM devices WHERE user_id = ?', [$kid])['n'];

/** 8 תהליכים מממשים את אותו קוד באותו רגע. מחזיר [כמה הצליחו, כמה מכשירים נרשמו]. */
function race(string $code, int $n = 8): array {
    global $tmp, $devices;
    @unlink("$tmp/go");
    $procs = [];
    for ($i = 0; $i < $n; $i++) {
        $p = proc_open([PHP_BINARY, __FILE__, '--redeem', $tmp, $code, "race-$code-$i"],
                       [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    usleep(300_000);           // כולם עלו וממתינים
    touch("$tmp/go");
    $ok = 0;
    foreach ($procs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $errOut = stream_get_contents($pipes[2]);
        proc_close($p);
        if ($out === 'OK') $ok++;
        elseif (!str_starts_with($out, 'ERR:')) echo "       (תהליך: " . trim($out . ' ' . $errOut) . ")\n";
    }
    return [$ok, $devices()];
}

echo "\n— רצף רגיל —\n";
[$code] = pairCreate($kid);
check('מימוש ראשון מצליח', pairRedeem($code, 'P', 'seq-1')[2], '');
check('מימוש שני של אותו קוד נדחה', pairRedeem($code, 'P', 'seq-2')[2], 'הקוד לא נמצא או שפג תוקפו. בקשו מההורה קוד חדש.');
check('מכשיר אחד נרשם', $devices(), 1);

echo "\n— כישלון בדיקה לא שורף את הקוד —\n";
[$code] = pairCreate($kid);
q("UPDATE users SET status = 'suspended' WHERE id = ?", [$kid]);
check('ילד מושהה — נדחה', pairRedeem($code, 'P', 'sus-1')[2], 'הילד הזה מושהה. ההורה צריך להחזיר אותו קודם.');
q("UPDATE users SET status = 'active' WHERE id = ?", [$kid]);
check('אחרי ההחזרה — אותו קוד עובד', pairRedeem($code, 'P', 'sus-2')[2], '');
check('...ואז הוא משומש', (string) one('SELECT used_at FROM pair_codes WHERE child_id = ? ORDER BY rowid DESC', [$kid])['used_at'] !== '', true);

echo "\n— 8 בקשות בו-זמניות עם אותו קוד —\n";
for ($round = 1; $round <= 3; $round++) {
    $before = $devices();
    [$code] = pairCreate($kid);
    [$ok, $after] = race($code);
    check("סבב $round: בדיוק מימוש אחד הצליח", $ok, 1);
    check("סבב $round: בדיוק מכשיר אחד נוסף", $after - $before, 1);
}

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
