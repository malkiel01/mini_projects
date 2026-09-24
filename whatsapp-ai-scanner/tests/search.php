<?php
/**
 * בדיקת חיפוש רלוונטיות: שאלת כסף מוצאת אסמכתא ישנה, לא רק חלון אחרון.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/was_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0700, true);
define('DB_FILE', $tmp . '/db.sqlite');
define('SECRET_KEY_FILE', $tmp . '/secret.key');

require_once __DIR__ . '/../lib/query.php';
require_once __DIR__ . '/../lib/accounts.php';
require_once __DIR__ . '/../lib/ingest.php';

$failed = 0;
function check(string $n, bool $c): void { global $failed; echo ($c?"  ok  ":"  FAIL")."  $n\n"; if(!$c)$failed++; }

$acc = createAccount('פרטי', 'personal');

// הודעה כספית ישנה
$old = mktime(9, 0, 0, 1, 5, 2026);
ingestBatch($acc['id'], [[
    'ext_id' => 'pay1', 'chat_name' => 'דני', 'sender' => 'דני', 'direction' => 'in',
    'body' => 'שלחתי אסמכתא על תשלום 1500 ₪', 'sent_at' => $old,
]]);

// המון רעש חדש יותר (רשימת אנשי קשר), שידחוף את הישנה מחוץ לחלון 400.
$rows = [];
for ($i = 0; $i < 600; $i++) {
    $rows[] = ['ext_id' => "noise$i", 'chat_name' => 'רשימה', 'sender' => 'מערכת', 'direction' => 'in',
        'body' => "איש קשר ב-WhatsApp @user$i", 'sent_at' => $old + 1000 + $i];
}
ingestBatch($acc['id'], $rows);

// חלון בלבד לא היה מוצא — כי הכספית היא ה-601 מהסוף.
$col = collectMessages($acc['id'], null, null, 'מי שלח אסמכתא על תשלום 1500');
check('נבחר לפי רלוונטיות', $col['by_relevance'] === true);
$found = false;
foreach ($col['messages'] as $m) if (strpos($m['body'], '1500') !== false) $found = true;
check('האסמכתא הישנה נמצאה למרות 600 הודעות רעש אחריה', $found);
check('נסרקה כל ההיסטוריה', $col['scanned'] === 601);

// שאלה שמילותיה אינן מופיעות בשום הודעה → נפילה לחלון האחרון, לא ריק.
$col2 = collectMessages($acc['id'], null, null, 'בוקר טוב לכולם');
check('שאלה ללא התאמה נופלת לחלון', $col2['by_relevance'] === false);
check('החלון אינו ריק', count($col2['messages']) > 0);

array_map('unlink', glob($tmp . '/*'));
@rmdir($tmp);
echo $failed === 0 ? "\nהכול עבר\n" : "\n$failed נכשלו\n";
exit($failed === 0 ? 0 : 1);
