<?php
/**
 * מוכנות לכסף אמיתי (שלב 8) — רשימה שמחושבת מהמסד, ולצדה פריטים שרק אדם יכול לאשר (נוסח משפטי, קישור הביטול
 * באפליקציה). אף פריט לא חוסם בקוד חוץ מהתנאים של אישור "כסף אמיתי" במערכת (client_update); הרשימה — כדי לא לשכוח.
 */

declare(strict_types=1);

require_once __DIR__ . '/clients.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/offsite.php';

/** פריטים שאדם מאשר — מפתח ⇐ [כותרת, הסבר]. */
const READY_MANUAL = [
    'cancel_link' => ['קישור "ביטול מנוי" בולט בכל מערכת שגובה מנוי', 'חוק הגנת הצרכן §14ט — בדף הבית, ומוביל ל"החשבון שלי".'],
    'terms_text' => ['התנאים כוללים: ביטול תוך 14 יום ודמי הביטול, חידוש, החזרים, ודרך יצירת קשר', 'אותו נוסח שמופיע בדף התשלום — שלא תהיה סתירה.'],
    'legal_review' => ['עורך דין עבר על נוסחי הגילוי והתנאים', 'הנוסחים בדף התשלום, בהודעות לפני חידוש וב"החשבון שלי".'],
    'backup_offsite' => ['גיבוי הורד ונשמר מחוץ לשרת', 'עד שיוכרע לאן נשלח גיבוי אוטומטי — הורדה ידנית מ"גיבויים".'],
];

function readiness(array $t): array
{
    $tid = $t['id'];
    $has = fn(string $sql, array $a = []) => (bool)db_one($sql, [$tid, ...$a]);
    $items = [];
    $add = function (string $key, string $title, bool $ok, string $todo, bool $blocking = true) use (&$items) {
        $items[] = ['key' => $key, 'title' => $title, 'state' => $ok ? 'ok' : ($blocking ? 'todo' : 'warn'), 'todo' => $ok ? '' : $todo, 'manual' => false];
    };

    $merchants = db_all('SELECT * FROM merchant WHERE tenant = ?', [$tid]);
    $complete = array_filter($merchants, fn($m) => !merchant_missing($m));
    $add('merchant', 'פרטי העוסק מלאים (מספר, כתובת, דרך קשר)', (bool)$complete,
        $merchants ? 'חסר: ' . implode(', ', merchant_missing($merchants[0])) . ' — הגדרות ← העוסק' : 'הגדרות ← "+ עוסק"');

    $prov = fn(string $env) => db_one("SELECT pa.* FROM provider_account pa JOIN merchant m ON m.id = pa.merchant
        WHERE m.tenant = ? AND pa.env = ? AND pa.status = 'active' ORDER BY pa.checked_at DESC LIMIT 1", [$tid, $env]);
    $pt = $prov('test');
    $add('provider_test', 'חברת הסליקה מחוברת בסביבת הניסוי', $pt && $pt['checked_at'] && $pt['check_error'] === '',
        $pt ? 'בדיקת החיבור נכשלה — הגדרות ← העוסק ← "בדיקה"' : 'קודי הניסוי של מורנינג — הגדרות ← העוסק');

    $paid = "SELECT 1 FROM payment p JOIN account a ON a.id = p.account JOIN merchant m ON m.id = a.merchant
        WHERE m.tenant = ? AND p.env = ? AND p.provider LIKE 'pa\\_%' ESCAPE '\\' AND p.status IN ('succeeded','refunded','partially_refunded')";
    $add('sandbox_payment', 'תשלום ניסיון עבר דרך הסולק האמיתי', $has($paid . ' LIMIT 1', ['test']),
        'תשלום בכרטיס ניסיון של מורנינג, מקישור או מהמערכת, בסביבת הבדיקות');
    $add('sandbox_document', 'נוצרה קבלה / חשבונית לתשלום הניסיון', $has("SELECT 1 FROM tax_document d JOIN payment p ON p.id = d.payment
        JOIN account a ON a.id = p.account JOIN merchant m ON m.id = a.merchant WHERE m.tenant = ? AND p.env = 'test' LIMIT 1"),
        'בודקים בלשונית כספים שליד התשלום מופיע מספר מסמך');

    $pl = $prov('live');
    $add('provider_live', 'חברת הסליקה מחוברת לכסף אמיתי', $pl && $pl['checked_at'] && $pl['check_error'] === '',
        'קודי החשבון האמיתי של מורנינג — הגדרות ← העוסק ← "+ מורנינג · כסף אמיתי"');

    $clients = db_all('SELECT * FROM client WHERE tenant = ? AND status = \'active\'', [$tid]);
    $noTerms = array_values(array_filter($clients, fn($c) => $c['terms_url'] === ''));
    $add('terms', 'לכל מערכת יש כתובת תנאים', $clients && !$noTerms,
        $clients ? 'חסר ב: ' . implode(', ', array_map(fn($c) => $c['name'], $noTerms)) . ' — מערכות ← המערכת' : 'אין עדיין מערכת');
    $add('client_live', 'מערכת אושרה לכסף אמיתי ויש לה קוד סודי חי', $has("SELECT 1 FROM client c JOIN api_key k ON k.client = c.id
        WHERE c.tenant = ? AND c.live_ok = 1 AND k.env = 'live' AND k.kind = 'sk' AND k.status = 'active' LIMIT 1"),
        'מערכות ← המערכת ← "מאושרת לכסף אמיתי", ואז "+ סודי · חי"');
    $add('webhook', 'הודעה למערכת נמסרה בהצלחה', $has("SELECT 1 FROM webhook_delivery d JOIN client c ON c.id = d.client
        WHERE c.tenant = ? AND d.status = 'delivered' LIMIT 1"), 'מערכות ← המערכת ← "שליחת בדיקה" (מערכת בלי כתובת — מדלגים)', false);

    $sms = function_exists('sms_config') ? sms_config($tid) : null;
    $add('sms', 'ספק מסרונים מחובר ובדיקה יצאה', $sms && !empty($sms['checked_at']) && ($sms['check_error'] ?? '') === '',
        'הגדרות ← 📱 מסרונים — חובה אם יש מנוי שנתי או מחיר מבצע (הודעה 21 יום לפני)', false);
    $cron = max((int)setting('tick_last_cron', '0'), (int)setting('tick_last_external', '0'));
    $add('clock', 'משימה מתוזמנת פועלת', $cron > now() - 20 * 60, 'הגדרות ← ⏰ השעון — חידושים יקרו גם בלי זה, אבל באיחור', false);
    if ($t['role'] === 'owner') {
        $last = backups_list()[0]['at'] ?? 0;
        $add('backup', 'גיבוי מהיממה האחרונה', $last > now() - 26 * 3600, 'הגדרות ← גיבויים ← "גיבוי עכשיו"');
    }

    $add('live_test', 'חיוב אמיתי קטן (₪1) והחזר שלו', $has("SELECT 1 FROM payment p JOIN account a ON a.id = p.account JOIN merchant m ON m.id = a.merchant
        WHERE m.tenant = ? AND p.env = 'live' AND p.provider <> 'fake' AND p.amount <= 500 AND EXISTS (SELECT 1 FROM refund r WHERE r.payment = p.id) LIMIT 1"),
        'קישור בסכום לבחירה ← לשלם ₪1 בכרטיס שלך ← החזר בממשק מורנינג ← "רישום החזר" כאן');

    $marks = json_obj(setting('ready_manual:' . $tid, '{}'));
    // גיבוי מחוץ לשרת — מסומן לבד כשהגיבוי השבועי במייל יצא בשבוע האחרון
    if ($t['role'] === 'owner' && function_exists('offsite_public')) {
        $o = offsite_public();
        if ($o['enabled'] && $o['last_at'] && $o['last_at'] > now() - 8 * 86400) $marks['backup_offsite'] = ['at' => $o['last_at'], 'auto' => true];
    }
    foreach (READY_MANUAL as $key => [$title, $hint]) {
        $m = $marks[$key] ?? null;
        $items[] = ['key' => $key, 'title' => $title, 'state' => $m ? 'ok' : 'todo', 'todo' => $m ? '' : $hint, 'manual' => true, 'marked_at' => $m['at'] ?? null];
    }
    $open = count(array_filter($items, fn($i) => $i['state'] === 'todo'));
    return ['items' => $items, 'open' => $open, 'ready' => $open === 0];
}

function readiness_mark(array $t, string $key, bool $done): array
{
    if (!isset(READY_MANUAL[$key])) throw new ApiError('פריט לא מוכר', 'bad_key');
    $k = 'ready_manual:' . $t['id'];
    $marks = json_obj(setting($k, '{}'));
    if ($done) $marks[$key] = ['at' => now(), 'by' => $t['id']]; else unset($marks[$key]);
    set_setting($k, json_encode($marks ?: new stdClass()));
    audit('admin', $t['id'], $done ? 'readiness.marked' : 'readiness.unmarked', 'readiness', $key, [], $t['id']);
    return readiness($t);
}
