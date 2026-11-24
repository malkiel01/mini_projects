<?php
declare(strict_types=1);
/**
 * הפעלה ראשונית: php funerals-dashboard/setup.php
 * אסור להריץ מתוך דפדפן. סודות נכתבים ל-data/ בלבד.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$path = __DIR__ . '/data/config.php';
if (file_exists($path)) { fwrite(STDERR, "כבר קיימות הגדרות. לא שונתה אף סיסמה.\n"); exit(1); }
if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "נדרשת הרחבת PDO SQLite.\n"); exit(1); }
if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
    fwrite(STDERR, "יש להריץ במסוף אינטראקטיבי (SSH), ולא מתוך בקשת HTTP או CI.\n"); exit(1);
}
echo "הגדרת מנהל לדשבורד לוויות\nשם משתמש [admin]: ";
$username = trim((string)fgets(STDIN));
if ($username === '') $username = 'admin';
if (strlen($username) > 60 || !preg_match('/^[a-zA-Z0-9_-]+$/D', $username)) { fwrite(STDERR, "שם משתמש לא תקין\n"); exit(1); }
echo "סיסמה (לפחות 12 תווים; לא תוצג): ";
if (function_exists('shell_exec')) shell_exec('stty -echo');
try { $password = rtrim((string)fgets(STDIN), "\r\n"); }
finally { if (function_exists('shell_exec')) shell_exec('stty echo'); echo "\n"; }
if (strlen($password) < 12) { fwrite(STDERR, "סיסמה קצרה מדי\n"); exit(1); }
$token = bin2hex(random_bytes(32));
$cfg = [
    'username' => $username,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'api_token_sha256' => hash('sha256', $token),
    'dropbox_app_key' => '',
];
unset($password);
$dir = dirname($path);
if (!is_dir($dir) && !mkdir($dir, 0700, true)) { fwrite(STDERR, "לא ניתן ליצור תיקיית נתונים\n"); exit(1); }
umask(0077);
$created = @fopen($path, 'x');
if (!$created) { fwrite(STDERR, "קובץ ההגדרות כבר קיים או שאינו ניתן לכתיבה\n"); exit(1); }
fwrite($created, "<?php\nreturn " . var_export($cfg, true) . ";\n");
fclose($created);
@chmod($path, 0600);
echo "ההגדרה הושלמה. יש לשמור את אסימון ה-API במקום פרטי — הוא מוצג פעם אחת בלבד:\n";
echo $token . "\n";
