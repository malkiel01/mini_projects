<?php
/**
 * בדיקת קצה-לקצה של השרת, בלי רשת ובלי מפתחות אמיתיים.
 *
 * מסד זמני, ותחבורת AI מזויפת שמחזירה את ההקשר שקיבלה — כדי לוודא
 * שהצינור בונה הקשר נכון, מצפין ומפענח, ולא מכפיל בסריקה חוזרת.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/wascanner_test_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0700, true);
define('DB_FILE', $tmp . '/db.sqlite');
define('SECRET_KEY_FILE', $tmp . '/secret.key');

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/accounts.php';
require_once __DIR__ . '/../lib/ingest.php';
require_once __DIR__ . '/../lib/query.php';
require_once __DIR__ . '/../lib/settings.php';

$failed = 0;
function check(string $name, bool $cond): void {
    global $failed;
    echo ($cond ? "  ok  " : "  FAIL") . "  $name\n";
    if (!$cond) $failed++;
}

// 1. חשבונות
$biz = createAccount('הביזנס שלי', 'business');
$pri = createAccount('פרטי', 'personal');
check('נוצרו שני חשבונות', count(listAccounts()) === 2);
check('סוג נשמר', $biz['kind'] === 'business');

// 2. בליעה + הצפנה במנוחה
$day = mktime(9, 0, 0, 5, 16, 2026);   // 16 במאי
$rows = [
    ['ext_id' => 'a1', 'chat_name' => 'דני', 'sender' => 'דני', 'direction' => 'in',
     'body' => 'שלחתי לך אסמכתא על תשלום 750 ₪', 'sent_at' => $day],
    ['ext_id' => 'a2', 'chat_name' => 'שרה', 'sender' => 'שרה', 'direction' => 'in',
     'body' => 'מתי נפגשים?', 'sent_at' => $day + 3600],
];
$r1 = ingestBatch($biz['id'], $rows);
check('נקלטו שתי הודעות', $r1['ingested'] === 2);

// סריקה חוזרת של אותן הודעות — לא מכפילה
$r2 = ingestBatch($biz['id'], $rows);
check('סריקה חוזרת דילגה על הכפילויות', $r2['ingested'] === 0 && $r2['skipped'] === 2);

// גוף ההודעה מוצפן במסד, לא גלוי
$rawBody = db()->query('SELECT body_enc FROM messages LIMIT 1')->fetchColumn();
check('גוף ההודעה מוצפן במסד', strpos($rawBody, 'אסמכתא') === false && $rawBody !== '');

// 3. איסוף לפי טווח תאריכים
$from = mktime(0, 0, 0, 5, 16, 2026);
$to   = mktime(23, 59, 59, 5, 16, 2026);
$col = collectMessages($biz['id'], $from, $to);
check('נאספו הודעות היום המבוקש', count($col['messages']) === 2);
check('הפענוח החזיר את הטקסט', strpos($col['messages'][0]['body'], 'אסמכתא') !== false);

// חשבון אחר — ריק
$colPri = collectMessages($pri['id'], null, null);
check('חשבון הפרטי נשאר ריק', count($colPri['messages']) === 0);

// 4. שאלה ותשובה עם תחבורה מזויפת
$fakeTransport = function ($provider, $url, $json, $key) {
    $sent = json_decode($json, true);
    // מחזירים תשובת Anthropic תקינה שמצטטת שנשלח הקשר
    return ['status' => 200, 'body' => json_encode([
        'content' => [['type' => 'text', 'text' => 'ההקשר הכיל ' .
            (strpos($json, 'אסמכתא') !== false ? 'אסמכתא' : 'כלום')]],
    ])];
};
$conn = ['provider' => 'anthropic', 'key' => 'sk-ant-testtesttesttest', 'model' => 'claude-haiku-4-5-20251001'];
$answer = answerQuestion($conn, 'מי שלח אסמכתא?', $col, $fakeTransport);
check('התשובה נבנתה מההקשר שהכיל את האסמכתא', strpos($answer, 'אסמכתא') !== false);

// 5. הגדרות AI — מפתח נשמר מוצפן ומוחזר רק כזנב
saveAiSettings('anthropic', 'claude-haiku-4-5-20251001', 'sk-ant-abcd1234abcd1234abcd');
$view = aiSettingsView();
check('המפתח מסומן כקיים', $view['has_key'] === true);
check('רק זנב המפתח נחשף', $view['key_tail'] === 'abcd' && strlen($view['key_tail']) === 4);
$rawKey = db()->query("SELECT ai_key_enc FROM owner WHERE id=1")->fetchColumn();
check('המפתח מוצפן במסד', strpos($rawKey, 'sk-ant') === false && $rawKey !== '');

// ניקוי
array_map('unlink', glob($tmp . '/*'));
@rmdir($tmp);

echo $failed === 0 ? "\nהכול עבר\n" : "\n$failed בדיקות נכשלו\n";
exit($failed === 0 ? 0 : 1);
