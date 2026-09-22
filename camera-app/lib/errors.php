<?php
/**
 * שגיאה שמיועדת למשתמש.
 *
 * מבדילה בין מה שהמשתמש יכול לתקן ("שם המשתמש תפוס", "אין מצלמה כזו")
 * לבין תקלה שלנו. הראשון מגיע אליו במילים שלו; השני נרשם ביומן ומוחזר
 * כ"שגיאת שרת" — כאן במיוחד, כי מאחורי הדף יושבות מצלמות בבית.
 */

declare(strict_types=1);

class AppError extends RuntimeException {
    public function __construct(string $message, public int $status = 400) {
        parent::__construct($message);
    }
}
