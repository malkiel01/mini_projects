<?php
/**
 * זריעה לבדיקות הדפדפן של N2b:   php n2b-seed.php <תיקיית הנתונים הזמנית> <תיקייה לקבצים להעלאה>
 * שדה "הקלטה בצרפתית" (audio_fr, סוג audio). מילים: 1 — ראשית + צרפתית · 2 "בסטילה" — ראשית בלבד · 3 "כוסכוס" — בלי
 * הקלטה · 4–6 — ראשית בלבד. הקלטה לא מחוברת. בתיקייה השנייה — "בסטילה - בצרפתית.mp3" ו-"repl.ogg". פלט: JSON — קבצים ו-sha256.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc < 3 || !is_dir($argv[1]) || !is_dir($argv[2])) exit(1);
define('KOL_DATA_DIR', $argv[1]);
$root = dirname(__DIR__, 2);
if (realpath(KOL_DATA_DIR) === realpath($root . '/data')) exit("לא על data/\n");
foreach (['members', 'import', 'dev', 'library'] as $lib) require_once "$root/lib/$lib.php";
$pdo = db();
setting_set('site_url', 'https://maghreb.co.il');
$who = ['sub' => 'u-owner', 'name' => 'Owner Test', 'role' => 'owner'];
$mp3 = fn(string $salt) => "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xff\xfb\x90\x64" . str_repeat("\x00", 413), 5) . $salt;
$store = function (string $bytes, string $name) use ($who): string {
    $tmp = files_dir() . '/tmp/seed-' . bin2hex(random_bytes(4));
    @mkdir(dirname($tmp), 0775, true);
    file_put_contents($tmp, $bytes);
    return file_store($tmp, $name, $who, 'audio/');
};
$f = [];
foreach ([1, 2, 4, 5, 6] as $i) $f[$i] = $store($mp3("word-$i"), "מילה $i.mp3");
$f['fr1'] = $store($mp3('fr-1'), 'צרפתית 1.mp3');
$f['loose'] = $store($mp3('loose'), 'הקלטה ישנה.mp3');
field_save($who, ['key' => 'audio_fr', 'label' => 'הקלטה בצרפתית', 'type' => 'audio']);
$names = [1 => 'מילה 1', 2 => 'בסטילה', 3 => 'כוסכוס', 4 => 'מילה 4', 5 => 'מילה 5', 6 => 'מילה 6'];
$ins = $pdo->prepare('INSERT INTO words (id, wp_id, word, meaning, topics, audio, external_id, extra, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($names as $id => $w) $ins->execute([$id, 100 + $id, $w, '<p>פירוש</p>', '[]', $f[$id] ?? '', '', $id === 1 ? json_encode(['audio_fr' => $f['fr1']]) : '{}', now_iso()]);
rec_sync();
$pdo->prepare("INSERT INTO recordings (list, word_id, field, state, file_id, name, how, at, by_name) VALUES ('words', NULL, 'audio', 'tool', ?, 'הקלטה ישנה', 'uploaded', ?, 'seed')")->execute([$f['loose'], now_iso()]);
$up = $argv[2];
file_put_contents("$up/בסטילה - בצרפתית.mp3", $mp3('up-bastilla-fr'));
file_put_contents("$up/repl.ogg", "OggS\x00\x02" . str_repeat("\x00", 20) . "\x01\x13OpusHead\x01\x01" . str_repeat("\x00", 200) . 'repl');
$sha = [];
foreach ($f as $k => $id) $sha[$k] = file_row($id)['sha256'];
foreach (['בסטילה - בצרפתית.mp3', 'repl.ogg'] as $n) $sha[$n] = hash_file('sha256', "$up/$n");
echo json_encode(['files' => $f, 'sha' => $sha], JSON_UNESCAPED_UNICODE);
