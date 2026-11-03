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
    /** קוד ה-HTTP שהחזיר ספק הבינה, כשהשגיאה ממנו — כדי להחליט אם לעבור לחיבור הבא. */
    public ?int $aiStatus = null;

    /** סוג הכשל אצל הספק (lib/ai.php aiFailureClass, §15.3): credit_exhausted | key_rejected | rate_limited | down | bad_request. */
    public string $aiClass = '';

    /** קוד שגיאה יציב למכונה (למשל token_scope ל-link.sh), לצד ההודעה לאדם. */
    public string $errorCode = '';

    public function __construct(string $message, public int $status = 400, string $code = '') {
        parent::__construct($message);
        $this->errorCode = $code;
    }
}
