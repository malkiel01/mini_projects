<?php
/**
 * שגיאות שאפשר לראות.
 *
 * ‏500 ריק הוא התקלה הגרועה ביותר לאבחון: הוא אומר "משהו נשבר" ולא
 * אומר מה, ובאחסון משותף לוג השגיאות של PHP לרוב אינו נגיש. לכן כל
 * שגיאה נרשמת לקובץ שאפשר לקרוא מהפאנל, ולמנהל מחובר היא גם מוצגת.
 *
 * למשתמש רגיל לא מוצג דבר — הודעת שגיאה חושפת נתיבים ומבנה קוד.
 */

declare(strict_types=1);

/** האם מותר להראות את הפרטים על המסך. */
function mayShowErrors(): bool {
    return session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['admin_id']);
}

function errorLogPath(): string {
    return dataDir() . '/error.log';
}

/** רושם שורה ליומן השגיאות. נכשל בשקט — לוג שנופל לא יפיל בקשה. */
function logProblem(string $text): void {
    try {
        $dir = dataDir();
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents(errorLogPath(),
            '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable) { /* אין מה לעשות */ }
}

function renderProblem(string $title, string $detail): void {
    // ‏HTML מינימלי ועצמאי: ייתכן שהתקלה קרתה לפני שהעיצוב נטען.
    echo '<div style="font:14px/1.6 system-ui;direction:rtl;background:#fee2e2;color:#991b1b;'
       . 'padding:16px;margin:16px;border-radius:10px;border:1px solid #fca5a5">'
       . '<strong>' . htmlspecialchars($title) . '</strong>'
       . '<pre style="white-space:pre-wrap;direction:ltr;text-align:left;font-size:12.5px;'
       . 'margin:10px 0 0;overflow:auto">' . htmlspecialchars($detail) . '</pre>'
       . '<p style="margin:10px 0 0;font-size:13px">הפרטים נשמרו גם ב-'
       . '<a href="health.php" style="color:#991b1b">מסך האבחון</a>.</p></div>';
}

function installErrorHandlers(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    set_exception_handler(function (Throwable $e) {
        $text = get_class($e) . ': ' . $e->getMessage()
              . "\n" . $e->getFile() . ':' . $e->getLine()
              . "\n" . $e->getTraceAsString();
        logProblem($text);

        if (!headers_sent()) http_response_code(500);
        if (mayShowErrors()) renderProblem('שגיאה בשרת', $text);
        else echo 'שגיאת שרת. הפרטים נרשמו ביומן.';
    });

    /*
     * שגיאה קטלנית אינה חריגה, ולכן set_exception_handler אינו תופס
     * אותה. בלי הבדיקה בסיום, "קריאה לפונקציה שאינה קיימת" נשארת
     * 500 ריק — וזו בדיוק השגיאה הנפוצה כשגרסת PHP שונה מהצפוי.
     */
    register_shutdown_function(function () {
        $e = error_get_last();
        if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        $text = $e['message'] . "\n" . $e['file'] . ':' . $e['line'];
        logProblem($text);

        if (!headers_sent()) http_response_code(500);
        if (mayShowErrors()) renderProblem('שגיאה קטלנית', $text);
    });
}
