<?php
/**
 * הגדרות המכשיר — מה ההורה מתיר או חוסם במכשיר עצמו (Wi‑Fi, בלוטות', מצלמה, התקנת אפליקציות…).
 *
 *   נשמר לכל ילד (policies.device_settings, JSON של {מפתח: חסום?}; חסר = ברירת המחדל), נשלח במדיניות
 *   ("device"), והמכשיר מפעיל (Enforcer.kt) — addUserRestriction / clearUserRestriction ו-DevicePolicyManager.
 *   נאכף רק במכשיר שהוא "בעל מכשיר"; בלעדיו ההגדרות נשמרות, ומצב האכיפה מסמן ⚠️.
 *
 *   ברירות המחדל — מה שננעל לפני שהיה הפאנל (שעה, חשבונות, VPN, מיקום) נשאר חסום; השאר מותר.
 *   הסדר כאן הוא סדר התצוגה אצל ההורה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** מפתח => [תווית, הסבר קצר, חסום כברירת מחדל]. המפתחות זהים ב-Enforcer.kt. */
const DEVICE_SETTINGS = [
    'wifi'           => ['📶 Wi‑Fi', 'שינוי הגדרות וחיבור לרשתות חדשות', false],
    'mobile_data'    => ['📡 נתונים סלולריים', 'שינוי הגדרות הרשת הסלולרית', false],
    'tethering'      => ['🔗 נקודה חמה', 'שיתוף האינטרנט של המכשיר', false],
    'bluetooth'      => ['🎧 בלוטות׳', 'הפעלה וחיבור מכשירים', false],
    'location'       => ['📍 מיקום', 'שינוי הגדרות המיקום ושיתוף המיקום', true],
    'camera'         => ['📷 מצלמה', 'כל המצלמות במכשיר', false],
    'screenshot'     => ['🖼️ צילום מסך', 'גם הקלטת מסך', false],
    'usb'            => ['🔌 USB והעברת קבצים', 'חיבור למחשב והעברת קבצים', false],
    'install_apps'   => ['⬇️ התקנת אפליקציות', 'מכל מקור. עדכוני מגדלור עצמו ממשיכים', false],
    'uninstall_apps' => ['🗑️ הסרת אפליקציות', 'מגדלור עצמו מוגן תמיד', false],
    'date_time'      => ['🕐 שעה ותאריך', 'בלי זה אפשר לעקוף את חלון השעות', true],
    'accounts'       => ['👤 חשבונות', 'הוספה והסרה של חשבון גוגל ואחרים', true],
    'vpn'            => ['🛡️ VPN', 'בלי זה אפשר לעקוף את הסינון', true],
];

/**
 * הגדרות שלעולם לא נחסמות. Wi‑Fi (בעל המערכת, 2026-10-20): בלי רשת המכשיר לא מגיע לשרת, ואז אי אפשר
 * לשחרר אותו מרחוק לעולם. נשלחות למכשיר כ"מותר" — כך גם חסימה ישנה מתבטלת במדיניות הבאה.
 */
const DEVICE_NEVER_BLOCK = ['wifi'];

/** ההגדרות של ילד: {מפתח: חסום?}, עם ברירות המחדל למה שלא נקבע. */
function deviceSettings(int $childId): array {
    $row = one('SELECT device_settings FROM policies WHERE user_id = ?', [$childId]);
    $saved = json_decode((string) ($row['device_settings'] ?? ''), true);
    $out = [];
    foreach (DEVICE_SETTINGS as $key => [, , $default]) {
        $out[$key] = !in_array($key, DEVICE_NEVER_BLOCK, true)
            && (is_array($saved) && array_key_exists($key, $saved) ? (bool) $saved[$key] : $default);
    }
    return $out;
}

/** לתצוגה אצל ההורה: רשימה לפי הסדר, עם תוויות. */
function deviceSettingsView(int $childId): array {
    $cur = deviceSettings($childId);
    $list = [];
    foreach (DEVICE_SETTINGS as $key => [$label, $hint]) {
        if (in_array($key, DEVICE_NEVER_BLOCK, true)) continue;   // לא מוצג — תמיד מותר
        $list[] = ['key' => $key, 'label' => $label, 'hint' => $hint, 'blocked' => $cur[$key]];
    }
    return $list;
}

/** שמירה של הגדרה אחת. מוחזרת שגיאה, או ''. */
function deviceSettingSave(int $childId, string $key, bool $blocked): string {
    if (!isset(DEVICE_SETTINGS[$key])) return 'הגדרה לא מוכרת';
    if ($blocked && in_array($key, DEVICE_NEVER_BLOCK, true)) return 'Wi‑Fi תמיד מותר — בלי רשת אי אפשר לשחרר את המכשיר מרחוק';
    $cur = deviceSettings($childId);
    $cur[$key] = $blocked;
    q('UPDATE policies SET device_settings = ?, updated_at = ? WHERE user_id = ?',
      [json_encode($cur), nowIso(), $childId]);
    return '';
}
