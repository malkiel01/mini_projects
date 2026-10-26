<?php
// זמני: קניות משותפות — חלק ב' של platform-move-runtime.md. בעל המערכת אישר את המעבר (2026-10-26):
// "כרגע תבצע את המעבר; הם ייחסמו לזמן קצר עד שנשלח באישורי". לא מוחקים כלום.
$home = rtrim(getenv('HOME') ?: '/home2/mbeplusc', '/');
$src = "$home/public_html/form/family";
$dst = getcwd() . '/family-shopping';
$out = [];
// 1. סנכרון אחרון של הקבלות
exec('rsync -a --ignore-existing ' . escapeshellarg("$src/uploads/") . ' ' . escapeshellarg("$dst/uploads/") . ' 2>&1', $o, $rc);
$out['1_uploads'] = ['rc' => $rc, 'src' => count(glob("$src/uploads/*") ?: []), 'dst' => count(glob("$dst/uploads/*") ?: [])];
// 2. cron — להחליף את הנתיב בשורה הקיימת (לא להוסיף שורה). גיבוי של ה-crontab קודם.
$old = '/public_html/form/family/cron/process-notifications.php';
$new = '/public_html/mini_projects/family-shopping/cron/process-notifications.php';
$cron = (string)shell_exec('crontab -l 2>/dev/null');
if (!is_file("$home/crontab.before-family-move")) file_put_contents("$home/crontab.before-family-move", $cron);
$hits = substr_count($cron, $old);
$out['2_cron'] = ['old_lines' => $hits, 'already_new' => substr_count($cron, $new)];
if ($hits === 1) {
    $tmp = tempnam(sys_get_temp_dir(), 'cr');
    file_put_contents($tmp, str_replace($old, $new, $cron));
    exec('crontab ' . escapeshellarg($tmp) . ' 2>&1', $o2, $rc2);
    unlink($tmp);
    $after = (string)shell_exec('crontab -l 2>/dev/null');
    $out['2_cron'] += ['rc' => $rc2, 'old_after' => substr_count($after, $old), 'new_after' => substr_count($after, $new),
        'lines_before' => count(array_filter(explode("\n", $cron))), 'lines_after' => count(array_filter(explode("\n", $after)))];
}
// 3. הפניה — גיבוי .htaccess והחלפה
$ht = "$src/.htaccess";
$bak = "$src/.htaccess.before-move";
if (!is_file($bak)) $out['3_backup'] = is_file($ht) ? copy($ht, $bak) : 'אין .htaccess';
else $out['3_backup'] = 'קיים כבר';
if (is_file($bak) || !is_file($ht)) {
    file_put_contents($ht, "RewriteEngine On\nRewriteRule ^(.*)$ https://mbe-plus.com/mini_projects/family-shopping/$1 [R=301,L]\n");
    $out['3_htaccess_written'] = true;
}
// 4. לא נמחק כלום
$out['4_src_intact'] = ['code' => is_file("$src/config.php"), 'env' => is_file("$src/.env"), 'uploads' => is_dir("$src/uploads")];
// בדיקה: קודי HTTP (בלי לעקוב אחרי הפניות)
foreach (['https://mbe-plus.com/form/family/join.php?token=x', 'https://form.mbe-plus.com/family/', 'https://mbe-plus.com/mini_projects/family-shopping/'] as $u) {
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => false]);
    curl_exec($ch);
    $out['http'][$u] = [curl_getinfo($ch, CURLINFO_HTTP_CODE), curl_getinfo($ch, CURLINFO_REDIRECT_URL)];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
