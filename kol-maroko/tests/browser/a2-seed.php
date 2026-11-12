<?php
/**
 * זריעה לבדיקות הדפדפן של A2:   php a2-seed.php <תיקיית הנתונים הזמנית> [unblock]
 * מילים שההקלטה שלהן בכתובות של האתר המדומה (tests/fake-audio.php): 1-6 חסומות (Cloudflare) — כדי שהבדיקה הראשונה
 * תגלה חסימה; ואחריהן קיימות, חסרה, דף HTML, נקטעת, נופלת-פעם-אחת ואיטיות. "unblock" — כמו אחרי כלל ב-Cloudflare:
 * 1-6 עוברות לכתובות תקינות. וגם — קצב מהיר לבדיקה (בלי השהיה, קבוצה אחת בכל צעד).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc < 2 || !is_dir($argv[1])) exit(1);
define('KOL_DATA_DIR', $argv[1]);
$root = dirname(__DIR__, 2);
if (realpath(KOL_DATA_DIR) === realpath($root . '/data')) exit("לא על data/\n");
foreach (['members', 'import', 'dev', 'library'] as $lib) require_once "$root/lib/$lib.php";
$U = 'https://maghreb.co.il/wp-content/uploads/';
$pdo = db();
if (($argv[2] ?? '') === 'unblock') {
    for ($i = 1; $i <= 6; $i++) $pdo->prepare('UPDATE words SET audio = ? WHERE id = ?')->execute([$U . "ok/$i.mp3", $i]);
    exit(0);
}
if (($argv[2] ?? '') === 'stale') {                     // "הדפדפן נסגר לפני דקה ויותר"
    $j = json_decode((string)setting('rec_job'), true);
    $j['touched'] = time() - 600;
    setting_set('rec_job', json_encode($j, JSON_UNESCAPED_UNICODE));
    exit(0);
}
setting_set('site_url', 'https://maghreb.co.il');
setting_set('rec_delay_ms', '0');
setting_set('rec_step_budget', '0');
$words = [];
for ($i = 1; $i <= 6; $i++) $words[$i] = $U . "cf/$i.mp3";
for ($i = 7; $i <= 12; $i++) $words[$i] = $U . "ok/$i.mp3";
$words += [13 => $U . 'missing/13.mp3', 14 => $U . 'html/14.mp3', 15 => $U . 'cut/15.mp3', 16 => $U . 'flaky/16.mp3',
    17 => $U . 'slow/17.mp3', 18 => $U . 'slow/18.mp3', 19 => $U . 'slow/19.mp3', 20 => $U . 'slow/20.mp3', 21 => ''];
$ins = $pdo->prepare('INSERT INTO words (id, wp_id, word, meaning, topics, audio, external_id, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($words as $id => $a) $ins->execute([$id, 100 + $id, "מילה $id", '<p>פירוש</p>', '[]', $a, '', now_iso()]);
$pdo->prepare('INSERT INTO members (sub, email, name, role, added_by, added_at) VALUES (?, ?, ?, ?, ?, ?)')->execute(['u-editor', 'editor@example.test', 'Editor Test', 'editor', 'test', now_iso()]);
