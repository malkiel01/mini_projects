<?php
/**
 * שגיאה שמיועדת למשתמש.
 *
 * מבדיל בין מה שהמשתמש יכול לתקן ("הסיסמה קצרה מדי", "אין לך הרשאה") לבין
 * תקלה שלנו. הראשון מגיע אליו במילים שלו; השני נרשם ביומן ומוחזר כ"שגיאת
 * שרת", כדי שפרטים פנימיים לא ידלפו.
 */

declare(strict_types=1);

class AppError extends RuntimeException {
    public function __construct(string $message, public int $status = 400) {
        parent::__construct($message);
    }
}
