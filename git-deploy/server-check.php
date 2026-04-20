<?php
/**
 * Server Environment Check for git-deploy
 *
 * Upload this file to your server and access it via browser.
 * It will show whether your server supports everything needed
 * for the git-deploy tool to work.
 *
 * DELETE THIS FILE after you are done checking - it exposes server info.
 */

header('Content-Type: text/html; charset=utf-8');

function check_ok($label, $value, $ok) {
    $color = $ok ? '#2e7d32' : '#c62828';
    $icon = $ok ? 'OK' : 'FAIL';
    $value = htmlspecialchars($value);
    echo "<tr><td><b>$label</b></td><td style='color:$color'><b>[$icon]</b></td><td><pre style='margin:0'>$value</pre></td></tr>";
}

function check_info($label, $value) {
    $value = htmlspecialchars((string)$value);
    echo "<tr><td><b>$label</b></td><td style='color:#1565c0'><b>[INFO]</b></td><td><pre style='margin:0'>$value</pre></td></tr>";
}

function safe_exec($cmd) {
    if (!function_exists('shell_exec')) return '[shell_exec disabled]';
    $out = @shell_exec($cmd . ' 2>&1');
    return $out === null ? '[no output / blocked]' : trim($out);
}

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<title>Server Check - git-deploy</title>
<style>
body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; padding: 20px; color: #222; }
.container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
h1 { margin: 0 0 10px; color: #1a1a2e; }
h2 { margin: 30px 0 10px; color: #16213e; border-bottom: 2px solid #eee; padding-bottom: 5px; }
.warning { background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 6px; margin: 20px 0; }
table { width: 100%; border-collapse: collapse; direction: ltr; text-align: left; }
td { padding: 8px 12px; border-bottom: 1px solid #eee; vertical-align: top; }
td:first-child { width: 25%; font-weight: 600; }
td:nth-child(2) { width: 15%; }
pre { font-family: 'Courier New', monospace; font-size: 13px; white-space: pre-wrap; word-break: break-all; max-width: 600px; }
.summary { margin-top: 30px; padding: 20px; background: #e3f2fd; border-radius: 6px; }
.summary.bad { background: #ffebee; }
.summary.good { background: #e8f5e9; }
</style>
</head>
<body>
<div class="container">
    <h1>🔍 Server Environment Check</h1>
    <p>בדיקת תאימות השרת לפרויקט <b>git-deploy</b></p>

    <div class="warning">
        ⚠️ <b>חשוב:</b> מחק קובץ זה מהשרת אחרי שסיימת לבדוק. הוא חושף מידע על השרת שלך.
    </div>

<?php
// ============================================================
// 1. PHP & Server Info
// ============================================================
echo "<h2>1. PHP & Server</h2><table>";
check_info('PHP Version', PHP_VERSION);
check_ok('PHP >= 7.4', PHP_VERSION, version_compare(PHP_VERSION, '7.4.0', '>='));
check_info('OS', PHP_OS . ' (' . php_uname('s') . ' ' . php_uname('r') . ')');
check_info('Server Software', $_SERVER['SERVER_SOFTWARE'] ?? 'unknown');
check_info('Document Root', $_SERVER['DOCUMENT_ROOT'] ?? 'unknown');
check_info('Script Path', __FILE__);
check_info('Script Dir', __DIR__);
echo "</table>";

// ============================================================
// 2. Shell Execution
// ============================================================
echo "<h2>2. Shell Execution (הכי קריטי!)</h2><table>";

$shell_exec_ok = function_exists('shell_exec');
$exec_ok = function_exists('exec');
$system_ok = function_exists('system');
$passthru_ok = function_exists('passthru');
$proc_open_ok = function_exists('proc_open');

check_ok('shell_exec() available', $shell_exec_ok ? 'yes' : 'no', $shell_exec_ok);
check_ok('exec() available', $exec_ok ? 'yes' : 'no', $exec_ok);
check_ok('system() available', $system_ok ? 'yes' : 'no', $system_ok);
check_ok('passthru() available', $passthru_ok ? 'yes' : 'no', $passthru_ok);
check_ok('proc_open() available', $proc_open_ok ? 'yes' : 'no', $proc_open_ok);

$disabled = ini_get('disable_functions');
check_info('disable_functions', $disabled ?: '(none)');

$safe_mode = ini_get('safe_mode');
check_info('safe_mode (legacy)', $safe_mode ?: 'off');

echo "</table>";

// ============================================================
// 3. User & Permissions
// ============================================================
echo "<h2>3. User & Permissions</h2><table>";

if ($shell_exec_ok) {
    check_info('whoami', safe_exec('whoami'));
    check_info('id', safe_exec('id'));
    check_info('pwd', safe_exec('pwd'));
    check_info('HOME', safe_exec('echo $HOME'));
    check_info('PATH', safe_exec('echo $PATH'));
} else {
    check_info('PHP user (posix)', function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : 'posix not available');
}

$current_dir = __DIR__;
check_ok('Script dir writable', $current_dir, is_writable($current_dir));
check_ok('Script dir readable', $current_dir, is_readable($current_dir));

$parent_dir = dirname($current_dir);
check_ok('Parent dir writable', $parent_dir, is_writable($parent_dir));

echo "</table>";

// ============================================================
// 4. Git
// ============================================================
echo "<h2>4. Git</h2><table>";

if ($shell_exec_ok) {
    $git_version = safe_exec('git --version');
    $git_ok = strpos($git_version, 'git version') !== false;
    check_ok('git installed', $git_version, $git_ok);

    check_info('which git', safe_exec('which git'));
    check_info('git config user.name', safe_exec('git config --global user.name'));
    check_info('git config user.email', safe_exec('git config --global user.email'));

    // Check for SSH keys in common location
    $home = trim(safe_exec('echo $HOME'));
    if ($home && is_dir($home . '/.ssh')) {
        $ssh_files = @scandir($home . '/.ssh');
        $keys = [];
        if ($ssh_files) {
            foreach ($ssh_files as $f) {
                if (preg_match('/^id_/', $f) || $f === 'config' || $f === 'known_hosts') {
                    $keys[] = $f;
                }
            }
        }
        check_info('~/.ssh contents', empty($keys) ? '(empty or no access)' : implode(', ', $keys));
    } else {
        check_info('~/.ssh directory', 'not found');
    }
} else {
    check_info('git check skipped', 'shell_exec not available');
}

echo "</table>";

// ============================================================
// 5. Network - GitHub Connectivity
// ============================================================
echo "<h2>5. Network - GitHub Connectivity</h2><table>";

if ($shell_exec_ok) {
    $ping = safe_exec('curl -sI -m 5 https://github.com | head -n 1');
    $github_ok = strpos($ping, '200') !== false || strpos($ping, '301') !== false || strpos($ping, '302') !== false;
    check_ok('HTTPS to github.com', $ping ?: '(no response)', $github_ok);

    $api = safe_exec('curl -sI -m 5 https://api.github.com | head -n 1');
    $api_ok = strpos($api, '200') !== false;
    check_ok('HTTPS to api.github.com', $api ?: '(no response)', $api_ok);
}

$curl_ok = function_exists('curl_init');
check_ok('PHP curl extension', $curl_ok ? 'yes' : 'no', $curl_ok);

$allow_url_fopen = ini_get('allow_url_fopen');
check_ok('allow_url_fopen', $allow_url_fopen ? 'on' : 'off', (bool)$allow_url_fopen);

echo "</table>";

// ============================================================
// 6. Summary
// ============================================================
$critical_ok = $shell_exec_ok;
if ($shell_exec_ok) {
    $git_test = safe_exec('git --version');
    $critical_ok = $critical_ok && (strpos($git_test, 'git version') !== false);
}

$summary_class = $critical_ok ? 'good' : 'bad';
echo "<div class='summary $summary_class'>";
if ($critical_ok) {
    echo "<h2 style='margin-top:0'>✅ השרת תואם</h2>";
    echo "<p>הבדיקות הקריטיות עברו. אפשר להתקדם לבניית הממשק.</p>";
    echo "<p><b>המידע שחשוב לי ממך:</b></p>";
    echo "<ul>";
    echo "<li>הפלט של <b>whoami</b> ו-<b>id</b> (סעיף 3)</li>";
    echo "<li>הפלט של <b>git --version</b> ו-<b>which git</b> (סעיף 4)</li>";
    echo "<li>תוכן התיקייה <b>~/.ssh</b> (סעיף 4) - חשוב כדי לדעת אם יש SSH key</li>";
    echo "<li>תוצאת <b>HTTPS to github.com</b> (סעיף 5)</li>";
    echo "</ul>";
} else {
    echo "<h2 style='margin-top:0'>❌ יש בעיה</h2>";
    echo "<p>אחת מהבדיקות הקריטיות נכשלה. הממשק לא יעבוד בלעדיה.</p>";
    echo "<p>שלח לי את כל הפלט של הדף הזה ונבדוק מה הבעיה וחלופות.</p>";
}
echo "</div>";

echo "<p style='margin-top:20px;color:#888;font-size:12px'>🗑️ אחרי ששלחת לי את הפלט - <b>מחק את הקובץ הזה מהשרת</b>.</p>";
?>

</div>
</body>
</html>
