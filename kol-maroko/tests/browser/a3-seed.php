<?php
/**
 * זריעה לבדיקות הדפדפן של A3:   php a3-seed.php <תיקיית הנתונים הזמנית> <תיקייה לקבצים להעלאה>
 * מילים 1–12: בכלי (קבצים בספרייה), באתר (רק כתובת — אף בקשה לא יוצאת אליה), נכשלה בהבאה, בלי הקלטה; שתי מילים עם
 * אותו קובץ בתוכן (כפולות), הקלטה לא מחוברת, עורך. בתיקייה השנייה — קבצים להעלאה (התאמה לפי שם) + sha256 של כל קובץ.
 * פלט: JSON — מזהי הקבצים וה-sha256 שלהם.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc < 3 || !is_dir($argv[1]) || !is_dir($argv[2])) exit(1);
define('KOL_DATA_DIR', $argv[1]);
$root = dirname(__DIR__, 2);
if (realpath(KOL_DATA_DIR) === realpath($root . '/data')) exit("לא על data/\n");
foreach (['members', 'import', 'dev', 'library'] as $lib) require_once "$root/lib/$lib.php";
$U = 'https://maghreb.co.il/wp-content/uploads/';
$pdo = db();
setting_set('site_url', 'https://maghreb.co.il');
$who = ['sub' => 'u-owner', 'name' => 'Owner Test'];
$mp3 = fn(string $salt) => "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xff\xfb\x90\x64" . str_repeat("\x00", 413), 5) . $salt;
$store = function (string $bytes, string $name) use ($who): string {
    $tmp = files_dir() . '/tmp/seed-' . bin2hex(random_bytes(4));
    @mkdir(dirname($tmp), 0775, true);
    file_put_contents($tmp, $bytes);
    return file_store($tmp, $name, $who, 'audio/');
};
$f = [];
foreach ([1, 2, 3, 4] as $i) $f[$i] = $store($mp3("word-$i"), "מילה $i.mp3");
$f[7] = $store($mp3('dup'), 'כפולה א.mp3');
$f[8] = $store($mp3('dup'), 'כפולה ב.mp3');
$f['loose'] = $store($mp3('loose'), 'הקלטה ישנה.mp3');
$words = [1 => $f[1], 2 => $f[2], 3 => $f[3], 4 => $f[4], 5 => '', 6 => $U . 'x/6.mp3', 7 => $f[7], 8 => $f[8], 9 => $U . 'x/9.mp3', 10 => '', 11 => '', 12 => ''];
$ins = $pdo->prepare('INSERT INTO words (id, wp_id, word, meaning, topics, audio, external_id, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($words as $id => $a) $ins->execute([$id, 100 + $id, "מילה $id", '<p>פירוש</p>', '[]', $a, '', now_iso()]);
$pdo->prepare("INSERT INTO users (sub, email, name, role, status, created_at) VALUES (?, ?, ?, ?, 'active', ?)")->execute(['u-editor', 'editor@example.test', 'Editor Test', 'editor', now_iso()]);     // P1
$pdo->prepare("INSERT INTO users (sub, email, name, role, status, created_at) VALUES (?, ?, ?, ?, 'active', ?)")->execute(['u-pown', 'pown@example.test', 'Page Owner Test', 'page_owner', now_iso()]);     // T1
rec_sync();
$pdo->exec("UPDATE recordings SET duration = 1.5 WHERE word_id = 1");
$pdo->exec("UPDATE recordings SET state = 'failed', f_code = 'missing', f_note = 'HTTP 404' WHERE word_id = 9");
$pdo->prepare("INSERT INTO recordings (word_id, state, file_id, name, how, at, by_name) VALUES (NULL, 'tool', ?, 'הקלטה ישנה', 'uploaded', ?, 'seed')")->execute([$f['loose'], now_iso()]);
// קבצים להעלאה: 5.mp3 → מילה 5 (לפי מזהה) · "מילה 1.ogg" → מילה 1 (לפי המילה; מתנגשת) · zzz.wav — לא מותאם · notes.txt — אינו שמע
$up = $argv[2];
file_put_contents("$up/5.mp3", $mp3('up-5'));
file_put_contents("$up/מילה 1.ogg", "OggS\x00\x02" . str_repeat("\x00", 20) . "\x01\x13OpusHead\x01\x01" . str_repeat("\x00", 200) . 'up-3');
file_put_contents("$up/zzz.wav", "RIFF" . pack('V', 136) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', 100) . str_repeat("\x00", 100));
file_put_contents("$up/notes.txt", 'not audio');
file_put_contents("$up/new-1.flac", "fLaC\x00\x00\x00\x22" . str_repeat("\x00", 134) . 'replace-1');
$sha = [];
foreach ($f as $k => $id) $sha[$k] = file_row($id)['sha256'];
echo json_encode(['files' => $f, 'sha' => $sha]);
