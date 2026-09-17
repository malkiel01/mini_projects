<?php
/**
 * זיהוי כשל אכיפה, נעילה אוטומטית, והתרעה למנהל.
 *
 * ‏האכיפה כפולה — במכשיר ובשרת — וכל עוד שניהם מסכימים אין חדש.
 * העניין הוא ברגע שהם *חולקים*: אם האפליקציה התירה כתובת שהשרת
 * אוסר, האכיפה במכשיר לא עשתה את שלה. זה הסימן החד ביותר לפריצה
 * שאפשר לקבל, והוא לא דורש לנחש דפוסי התנהגות.
 *
 * מה שקורה אז אינו רק רישום: הגישה לפלטפורמה ננעלת מיד. התרעה
 * שמחכה שהמנהל יסתכל אינה הגנה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** כמה סירובים ברצף נחשבים לניסיון שיטתי, ובאיזה חלון זמן. */
const PROBE_LIMIT   = 12;
const PROBE_MINUTES = 10;

/**
 * רושם התרעה. אינה נכתבת פעמיים על אותו אירוע בתוך דקה, כדי
 * שהתקלה לא תציף את המסך ותסתיר את עצמה.
 */
function raiseAlert(int $userId, string $kind, string $title,
                    string $detail = '', string $url = '', string $severity = 'warn'): void {
    $since = (new DateTimeImmutable('-1 minute', new DateTimeZone('UTC')))
             ->format('Y-m-d\TH:i:s\Z');
    $dup = one('SELECT id FROM alerts WHERE user_id = ? AND kind = ? AND at > ?',
               [$userId, $kind, $since]);
    if ($dup) return;

    q('INSERT INTO alerts (user_id, at, kind, severity, title, detail, url)
       VALUES (?,?,?,?,?,?,?)',
      [$userId, nowIso(), $kind, $severity, $title,
       mb_substr($detail, 0, 500), mb_substr($url, 0, 500)]);
}

/**
 * נועל פלטפורמה למשתמש.
 *
 * ‏mode=off ולא מחיקת הפריטים: הרשימה שהמנהל בנה נשארת שלמה,
 * והפתיחה מחדש היא לחיצה אחת ולא בנייה מחדש.
 */
function lockPlatform(int $userId, string $platform): void {
    upsert('platform_rules',
           ['user_id' => $userId, 'platform' => $platform],
           ['mode' => 'off'],
           ['created_at' => nowIso()]);
}

/**
 * הלקוח דיווח מה הוא החליט. משווים.
 *
 * ‏$clientAllowed הוא ההכרעה של האפליקציה; $server היא שלנו.
 * אי-הסכמה בכיוון אחד בלבד מדאיגה: לקוח שחסם משהו שמותר הוא
 * מחמיר מדי, ולקוח שהתיר משהו אסור הוא לקוח שאיבד את האכיפה.
 */
function checkEnforcementGap(int $userId, string $url, bool $clientAllowed,
                             array $server, string $platform = ''): void {
    if ($clientAllowed && !$server['allow']) {
        raiseAlert($userId, 'enforcement_gap',
            'האפליקציה התירה כתובת שהשרת חוסם',
            'ייתכן שהאכיפה במכשיר נעקפה. סיבת החסימה בשרת: ' . $server['code'],
            $url, 'high');

        if ($platform !== '') lockPlatform($userId, $platform);
    }
}

/**
 * ניסיונות חוזרים ונשנים להגיע למה שנחסם.
 *
 * אדם שנתקל בחסימה מפסיק; מי שממשיך לנסות עשרות פעמים בדקות
 * בודדות מחפש פרצה. זו אינה ראיה לפריצה, ולכן היא מתריעה ולא
 * נועלת — נעילה על סמך דפוס בלבד הייתה חוסמת משתמש תמים שנתקע.
 */
function checkProbing(int $userId, string $url): void {
    $since = (new DateTimeImmutable('-' . PROBE_MINUTES . ' minutes', new DateTimeZone('UTC')))
             ->format('Y-m-d\TH:i:s\Z');
    $row = one("SELECT COUNT(*) AS n FROM audit
                WHERE user_id = ? AND kind = 'nav' AND allowed = 0 AND at > ?",
               [$userId, $since]);

    if ((int) ($row['n'] ?? 0) >= PROBE_LIMIT) {
        raiseAlert($userId, 'probing',
            'ניסיונות חוזרים להגיע לתוכן חסום',
            (int) $row['n'] . ' חסימות ב-' . PROBE_MINUTES . ' דקות',
            $url, 'warn');
    }
}

/** התרעות פתוחות, לתצוגה בפאנל. */
function openAlerts(int $limit = 50): array {
    return all("SELECT a.*, u.username FROM alerts a
                LEFT JOIN users u ON u.id = a.user_id
                WHERE a.acked_at = '' ORDER BY a.id DESC LIMIT ?", [$limit]);
}

function openAlertCount(): int {
    return (int) one("SELECT COUNT(*) AS n FROM alerts WHERE acked_at = ''")['n'];
}
