<?php
/**
 * שגיאה שמיועדת למשתמש.
 *
 * ‏api.php מבדיל בין שני סוגי כשל: מה שהמשתמש יכול לתקן ("לא חובר חשבון
 * גיטהאב", "אין לך הרשאה"), ומה שהוא תקלה שלנו. הראשון חייב להגיע אליו
 * במילים שלו; השני נרשם ביומן ומוחזר כ"שגיאת שרת", כדי שפרטים פנימיים
 * לא ידלפו החוצה.
 *
 * בלי ההבחנה הזו כל חריגה נראית זהה, וההודעה המדויקת שכתבנו נמחקת בדרך.
 */

declare(strict_types=1);

class AppError extends RuntimeException {
    public function __construct(string $message, public int $status = 400) {
        parent::__construct($message);
    }
}
