<?php
// זמני: קניות משותפות — חלק א2 של platform-move-runtime.md (בקשת הצ'אט, באישור בעל המערכת 2026-10-26).
// 1. מיגרציה (גיבוי users ואז תוספות). 2. בדיקת הזמנות בלי שליחה. 3. רק בעל המערכת (--owner --send).
// אין --send בלי --owner. הפלט — ספירות בלבד.
$dir = getcwd() . '/family-shopping';
$run = function (string $cmd) use ($dir) {
    $o = []; $rc = 0;
    exec('cd ' . escapeshellarg($dir) . ' && ' . $cmd . ' 2>&1', $o, $rc);
    // ליתר ביטחון — מסתירים כל דבר שנראה כמו מייל
    $text = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[מייל]', implode("\n", $o));
    return ['rc' => $rc, 'out' => $text];
};
$out = ['code_has_015' => str_contains((string)@file_get_contents("$dir/db/migrations.php"), "'015'"),
        'code_has_tool' => is_file("$dir/tools/invite-existing.php")];
if ($out['code_has_015'] && $out['code_has_tool']) {
    $out['1_migrate'] = $run('php db/migrate.php');
    $out['2_dry_run'] = $run('php tools/invite-existing.php');
    $out['3_owner'] = $run('php tools/invite-existing.php --owner --send');
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
