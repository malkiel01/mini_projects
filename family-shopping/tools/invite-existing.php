<?php
/**
 * הזמנת המשתמשים הקיימים לכלים הדיגיטליים (שלב 5 במעבר)
 * tools/invite-existing.php
 *
 *   php tools/invite-existing.php                  בדיקה בלבד: כמה יוזמנו
 *   php tools/invite-existing.php --send           שליחה - מיילים יוצאים לאנשים
 *   php tools/invite-existing.php --owner --send   רק בעל המערכת (ADMIN_EMAIL ב-.env),
 *                                                  כדי שיבדוק לפני שמזמינים את כולם
 *
 * רק מהטרמינל. מדפיס ספירות בלבד, בלי מיילים ושמות, כי הפלט נשמר
 * ביומן של GitHub Actions. אפשר להריץ שוב: מי שכבר מקושר לא מוזמן.
 * הפירוט: docs/migration.md, סעיף 7; includes/platform.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/platform.php';

$send  = in_array('--send', $argv, true);
$owner = null;

if (in_array('--owner', $argv, true)) {
    $owner = trim($_ENV['ADMIN_EMAIL'] ?? '');
    if ($owner === '') {
        fwrite(STDERR, "ADMIN_EMAIL אינו מוגדר ב-.env\n");
        exit(1);
    }
}

try {
    $counts = platformInviteExisting(getDBConnection(), !$send, null, 1000, $owner);
} catch (Exception $e) {
    fwrite(STDERR, "נכשל: " . $e->getMessage() . "\n");
    exit(1);
}

echo ($send ? "נשלח" : "בדיקה בלבד (להוסיף --send כדי לשלוח)")
    . ($owner !== null ? " — רק בעל המערכת" : '') . "\n";
foreach ($counts as $key => $n) {
    echo "  $key: $n\n";
}

exit(isset($counts['error']) ? 1 : 0);
