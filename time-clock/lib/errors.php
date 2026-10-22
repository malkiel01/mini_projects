<?php
/**
 * שגיאה שמיועדת למשתמש: מה שהוא יכול לתקן מגיע אליו במילים שלו. תקלה שלנו נרשמת ביומן
 * ומוחזרת כ"שגיאת שרת", כדי שפרטים פנימיים לא ידלפו.
 */

declare(strict_types=1);

class AppError extends RuntimeException {
    public function __construct(string $message, public int $status = 400) {
        parent::__construct($message);
    }
}
