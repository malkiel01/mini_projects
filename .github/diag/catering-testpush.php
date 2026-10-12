<?php
// שליחת התראת בדיקה לכל המכשירים הרשומים — רץ על השרת, מתוך catering-menu/.
// משתמש ב-push.php של הכלי עצמו, עם עזרים מינימליים במקום api.php.
declare(strict_types=1);
define('DATA_DIR', getcwd() . '/data');   // הסקריפט מגיע ב-stdin, אז __DIR__ ריק
$_SERVER['HTTP_HOST'] = 'mbe-plus.com';
function readJson(string $p): ?array { if (!is_file($p)) return null; $d = json_decode((string) file_get_contents($p), true); return is_array($d) ? $d : null; }
function writeJson(string $p, array $d): void { file_put_contents($p . '.tmp', json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX); rename($p . '.tmp', $p); }
function fail(string $m, int $c = 400) { fwrite(STDERR, "$m\n"); exit(1); }
function loadSettings(): array {
    $s = readJson(DATA_DIR . '/settings.json') ?? [];
    return ['email' => ($s['email'] ?? []) + ['replyTo' => '', 'adminNotify' => ''], 'notify' => ($s['notify'] ?? []) + ['sticky' => true]];
}
require getcwd() . "/push.php";
[$sent, $failed, $details] = pushToAll([
    'title' => '🔔 בדיקת התראות — ניחוחות',
    'body'  => 'התראת בדיקה מהשרת. אם רואים אותה — ההתראות עובדות.',
    'url'   => 'admin/',
    'tag'   => 'test',
]);
echo "=== התראת בדיקה: נשלחו $sent, נכשלו $failed\n";
foreach ($details as $d) echo "  → {$d['device']} {$d['service']} קוד {$d['code']}" . ($d['info'] ? " · {$d['info']}" : '') . "\n";
