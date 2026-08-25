<?php
/**
 * אבחון עצמאי.
 *
 * נכתב בכוונה בתחביר של PHP 5: הוא צריך לרוץ דווקא כשהשאר לא רץ. אם
 * קובץ אחר במערכת אינו נתמך בגרסת ה-PHP של השרת, כל הפנייה ל-api.php
 * נכשלת עוד לפני שורת קוד אחת — והדף מציג "טעינה נכשלה" בלי לומר למה.
 * הקובץ הזה עונה על ה"למה".
 *
 * פתחו אותו ישירות בדפדפן:  https://<דומיין>/claude-tasks/health.php
 */

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

/**
 * מסתיר מפתחות ואסימונים.
 *
 * הקובץ הזה נגיש לכל מי שיודע את הכתובת — זה הרי כל תכליתו, לעבוד גם
 * כשהכניסה שבורה. לכן שורה מיומן השגיאות עוברת סינון לפני שהיא מוצגת:
 * הודעת שגיאה עלולה לגרור איתה מפתח שנשלח בבקשה.
 */
function redact($line) {
    return preg_replace(
        array('/sk-ant-[A-Za-z0-9_\-]+/', '/sk-[A-Za-z0-9_\-]{20,}/', '/gh[pousr]_[A-Za-z0-9]+/',
              '/github_pat_[A-Za-z0-9_]+/', '/AIza[A-Za-z0-9_\-]+/', '/[a-f0-9]{40,}/'),
        '[הוסתר]',
        $line
    );
}

echo "בדיקת סביבה — מטלות לקלוד\n";
echo "==========================\n\n";

echo "PHP:      " . PHP_VERSION . "\n";
echo "נדרש:     8.0 ומעלה\n";

if (version_compare(PHP_VERSION, '8.0', '<')) {
    echo "\n>>> זו הבעיה: גרסת ה-PHP של השרת ישנה מדי.\n";
    echo ">>> ב-cPanel: Select PHP Version -> בחרו 8.1 ומעלה.\n";
    exit;
}
echo "          תקין\n\n";

echo "הרחבות\n------\n";
$need = array(
    'pdo_sqlite' => 'חובה — מסד הנתונים',
    'mbstring'   => 'חובה — טקסט בעברית',
    'json'       => 'חובה',
    'curl'       => 'נדרש לגיטהאב ולספקי הבינה',
    'sodium'     => 'נדרש להצפנת סודות עבור גיטהאב (יש נפילה חלופית)',
    'openssl'    => 'גיבוי להצפנה',
);
foreach ($need as $ext => $why) {
    echo str_pad($ext, 12) . (extension_loaded($ext) ? 'קיים' : 'חסר  <<<') . "   $why\n";
}

echo "\nקבצים\n-----\n";
$dir = dirname(__FILE__);
$bad = 0;
$files = glob($dir . '/lib/*.php');
$files[] = $dir . '/api.php';

foreach ($files as $f) {
    $name = str_replace($dir . '/', '', $f);
    if (!is_readable($f)) { echo str_pad($name, 22) . "לא נמצא  <<<\n"; $bad++; continue; }

    // token_get_all עם TOKEN_PARSE זורק ParseError על תחביר שאינו נתמך
    // בגרסה הזו — בדיוק מה שמפיל את api.php בלי הודעה.
    try {
        token_get_all(file_get_contents($f), TOKEN_PARSE);
        echo str_pad($name, 22) . "תקין\n";
    } catch (Throwable $e) {
        echo str_pad($name, 22) . "שגיאת תחביר  <<<  " . $e->getMessage() . "\n";
        $bad++;
    }
}

echo "\nתיקיית הנתונים\n--------------\n";
$data = $dir . '/data';
echo "קיימת:        " . (is_dir($data) ? 'כן' : 'לא  <<<') . "\n";
echo "ניתנת לכתיבה: " . (is_writable($data) ? 'כן' : 'לא  <<<') . "\n";
echo "חסומה:        " . (is_file($data . '/.htaccess') ? 'כן' : 'לא — .htaccess חסר  <<<') . "\n";
foreach (array('tasks.sqlite', 'config.json', 'secret.key') as $f) {
    $p = $data . '/' . $f;
    echo str_pad($f, 14) . (is_file($p) ? filesize($p) . ' בתים' : 'טרם נוצר') . "\n";
}

echo "\nחיבור למסד\n----------\n";
try {
    $pdo = new PDO('sqlite:' . $data . '/tasks.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $v = $pdo->query('SELECT sqlite_version()')->fetch();
    echo "SQLite:  " . $v[0] . "   (נדרש 3.9 ומעלה)\n";

    $tables = array();
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table'") as $r) $tables[] = $r[0];
    echo "טבלאות:  " . (count($tables) ? implode(', ', $tables) : '(ריק — טרם נוצרו)') . "\n";
} catch (Exception $e) {
    echo "נכשל  <<<  " . $e->getMessage() . "\n";
    $bad++;
}

echo "\nיומן השגיאות של השרת\n--------------------\n";
$log = null;
foreach (array($dir . '/error_log', dirname($dir) . '/error_log') as $candidate) {
    if (is_file($candidate)) { $log = $candidate; break; }
}
if ($log === null) {
    echo "לא נמצא קובץ error_log ליד הפרויקט.\n";
} else {
    $lines = array_slice(file($log, FILE_IGNORE_NEW_LINES), -15);
    echo "מתוך $log — 15 השורות האחרונות:\n\n";
    foreach ($lines as $l) echo "  " . redact($l) . "\n";
}

echo "\n==========================\n";
echo $bad === 0
    ? "לא נמצאה תקלה בשכבה הזו. אם הדף עדיין לא נטען — שלחו את הפלט הזה.\n"
    : "נמצאו $bad תקלות. השורות המסומנות ב-<<< הן מה שצריך לתקן.\n";

echo "\nהקובץ הזה נועד לאבחון בלבד. אחרי שהתקלה נפתרה אפשר למחוק אותו.\n";
