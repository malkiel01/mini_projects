<?php
/**
 * זריעה ל-R2 (אחרי f3-seed.php):   php r2-seed.php <תיקיית הנתונים הזמנית>
 * תמונת הרקע של המילון בטיוטה ← כתובת באתר המדומה (tests/fake-audio.php, img/7.png), כדי שהבורר יציג אותה "באתר".
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc < 2 || !is_dir($argv[1])) exit(1);
define('KOL_DATA_DIR', $argv[1]);
$root = dirname(__DIR__, 2);
if (realpath(KOL_DATA_DIR) === realpath($root . '/data')) exit("לא על data/\n");
foreach (['members', 'site', 'restore', 'library'] as $lib) require_once "$root/lib/$lib.php";
setting_set('site_url', 'https://maghreb.co.il');
$json = (string)db()->query('SELECT json FROM draft WHERE id = 1')->fetchColumn();
$n = 0;
$json = str_replace('https:\/\/maghreb.co.il\/wp-content\/uploads\/hero.jpg', 'https:\/\/maghreb.co.il\/wp-content\/uploads\/img\/7.png', $json, $n);
if (!$n) $json = str_replace('https://maghreb.co.il/wp-content/uploads/hero.jpg', 'https://maghreb.co.il/wp-content/uploads/img/7.png', $json, $n);
if (!$n) exit("אין תמונת רקע בטיוטה\n");
db()->prepare('UPDATE draft SET json = ? WHERE id = 1')->execute([$json]);
