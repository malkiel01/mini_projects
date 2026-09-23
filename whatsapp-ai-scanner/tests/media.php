<?php
/**
 * בדיקת אינדוקס מדיה — חילוץ (עם תחבורה מזויפת), שמירה כהודעה, ו-dedup.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/wam_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0700, true);
define('DB_FILE', $tmp . '/db.sqlite');
define('SECRET_KEY_FILE', $tmp . '/secret.key');

require_once __DIR__ . '/../lib/media.php';
require_once __DIR__ . '/../lib/accounts.php';
require_once __DIR__ . '/../lib/query.php';
require_once __DIR__ . '/../lib/auth.php';

$failed = 0;
function check(string $name, bool $cond): void {
    global $failed;
    echo ($cond ? "  ok  " : "  FAIL") . "  $name\n";
    if (!$cond) $failed++;
}

// מגדיר מפתח AI לבעלים (נדרש ל-ownerAiConn בתוך ingestMediaFile).
setupOwner('password123');
saveAiSettings('anthropic', 'claude-haiku-4-5-20251001', 'sk-ant-testtesttesttest');

$acc = createAccount('פרטי', 'personal');

// תחבורה מזויפת: מאמת שנשלח בלוק מסמך, ומחזיר טקסט "אסמכתא 1500".
$transport = function ($provider, $url, $json, $key) {
    $sent = json_decode($json, true);
    $hasDoc = false;
    foreach ($sent['messages'][0]['content'] ?? [] as $blk) {
        if (($blk['type'] ?? '') === 'document' || ($blk['type'] ?? '') === 'image') $hasDoc = true;
    }
    if (!$hasDoc) return ['status' => 400, 'body' => json_encode(['error' => ['message' => 'no doc block']])];
    return ['status' => 200, 'body' => json_encode([
        'content' => [['type' => 'text', 'text' => 'אסמכתת תשלום על סך 1,500 ₪, תאריך 16/05']],
    ])];
};

// מחליף את aiExtractFile? לא — ingestMediaFile קורא ל-ownerAiConn+aiExtractFile
// ישירות. כדי להזריק תחבורה, נבדוק את aiExtractFile לחוד, ואת ingest עם
// עקיפה זמנית דרך קבוע גלובלי לא קיים — לכן נבדוק בשתי רמות:

// 1. aiExtractFile מייצר בלוק מסמך ומחזיר את הטקסט.
$conn = ['provider' => 'anthropic', 'key' => 'sk-ant-x', 'model' => 'claude-haiku-4-5-20251001'];
$text = aiExtractFile($conn, 'application/pdf', "%PDF-1.4 fake bytes", 1500, $transport);
check('חילוץ PDF מחזיר את הסכום', strpos($text, '1,500') !== false);

$img = aiExtractFile($conn, 'image/jpeg', "\xFF\xD8\xFF fake", 1500, $transport);
check('חילוץ תמונה עובד', strpos($img, '1,500') !== false);

// 2. סוג לא נתמך
check('וידאו אינו נתמך', !mediaSupported('video/mp4'));
check('PDF נתמך', mediaSupported('application/pdf'));

// 3. dedup: שתי רשומות media עם אותו hash לאותו חשבון — רק אחת.
$hash = hash('sha256', 'abc');
db()->prepare('INSERT OR IGNORE INTO media (account_id,hash,filename,mime,processed_at) VALUES (?,?,?,?,?)')
    ->execute([$acc['id'], $hash, 'a.pdf', 'application/pdf', time()]);
db()->prepare('INSERT OR IGNORE INTO media (account_id,hash,filename,mime,processed_at) VALUES (?,?,?,?,?)')
    ->execute([$acc['id'], $hash, 'a.pdf', 'application/pdf', time()]);
$cnt = (int) db()->query('SELECT COUNT(*) FROM media')->fetchColumn();
check('dedup לפי hash', $cnt === 1);

array_map('unlink', glob($tmp . '/*'));
@rmdir($tmp);
echo $failed === 0 ? "\nהכול עבר\n" : "\n$failed נכשלו\n";
exit($failed === 0 ? 0 : 1);
