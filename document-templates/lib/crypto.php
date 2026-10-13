<?php
/**
 * הצפנת התוכן במנוחה (רמה ב' באפיון, סעיף 8).
 *
 * כל תוכן של משתמש — שדות התבנית, ערכים, אנשי קשר, קבצים — נשמר מוצפן.
 * מי שמעיין במסד, או גיבוי שדלף, אינו קורא דבר. המפתח בקובץ נפרד מהמסד.
 *
 * האפליקציה עצמאית (CLAUDE.md, "אפליקציה פנימית עצמאית מהמעטפת"): אין
 * כאן require לשום קובץ מחוץ לתיקייה.
 */

declare(strict_types=1);

if (!defined('DT_DATA_DIR')) define('DT_DATA_DIR', __DIR__ . '/../data');
if (!defined('DT_KEY_FILE')) define('DT_KEY_FILE', DT_DATA_DIR . '/secret.key');

const DT_DATA_HTACCESS = <<<TXT
    # נוצר אוטומטית. data/ מכילה את המסד, מפתח ההצפנה והקבצים.
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
    TXT;

/** תיקייה שנוצרה בזמן ריצה הייתה נוצרת בלי .htaccess — ההגנה לא תלויה בפריסה. */
function ensure_data_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("התיקייה $dir אינה ניתנת ליצירה");
    }
    $guard = DT_DATA_DIR . '/.htaccess';
    if (!is_file($guard)) @file_put_contents($guard, DT_DATA_HTACCESS);
}

/** נוצר בשימוש הראשון: 32 בתים אקראיים, קריאה לבעלים בלבד. */
function secret_key(): string
{
    static $key = null;
    if (is_string($key)) return $key;

    if (is_file(DT_KEY_FILE)) {
        $raw = (string)file_get_contents(DT_KEY_FILE);
        if (strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) return $key = $raw;
        // מפתח פגום אינו מוחלף בשקט: זה היה הופך את כל התוכן הקיים לבלתי קריא.
        throw new RuntimeException('קובץ מפתח ההצפנה פגום');
    }

    ensure_data_dir(dirname(DT_KEY_FILE));
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    // קובץ זמני ואז שינוי שם — בלי חלון שבו הקובץ קיים בהרשאות ברירת מחדל.
    $tmp = DT_KEY_FILE . '.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $key);
    @chmod($tmp, 0600);
    rename($tmp, DT_KEY_FILE);
    return $key;
}

/** הפורמט נושא גרסה ("v1:"), כדי שאפשר יהיה להחליף שיטה בלי לנחש מה נכתב. */
function seal(?string $plain): ?string
{
    if ($plain === null) return null;
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, secret_key()));
}

function unseal(?string $sealed): ?string
{
    if ($sealed === null) return null;
    if (!str_starts_with($sealed, 'v1:')) throw new RuntimeException('פורמט הצפנה לא מוכר');
    $raw = base64_decode(substr($sealed, 3), true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('תוכן מוצפן פגום');
    }
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, secret_key());
    if ($plain === false) throw new RuntimeException('פענוח נכשל');
    return $plain;
}

// ── קבצים ─────────────────────────────────────────────────────
// קובץ נשמר בינארי (nonce + מוצפן), בלי base64 — חוסך שליש מהנפח.

if (!defined('DT_FILES_DIR')) define('DT_FILES_DIR', DT_DATA_DIR . '/files');

/** מצפין ושומר. מחזיר שם קובץ אקראי — שמו המקורי של הקובץ לא נשמר על הדיסק. */
function store_file(string $bytes): string
{
    ensure_data_dir(DT_FILES_DIR);
    $name = bin2hex(random_bytes(16)) . '.bin';
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    if (file_put_contents(DT_FILES_DIR . '/' . $name, $nonce . sodium_crypto_secretbox($bytes, $nonce, secret_key())) === false) {
        throw new RuntimeException('שמירת הקובץ נכשלה');
    }
    return $name;
}

function load_file(string $name): string
{
    if (!preg_match('/^[0-9a-f]{32}\.bin$/', $name)) throw new RuntimeException('שם קובץ לא תקין');
    $raw = @file_get_contents(DT_FILES_DIR . '/' . $name);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('הקובץ חסר');
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), secret_key());
    if ($plain === false) throw new RuntimeException('פענוח הקובץ נכשל');
    return $plain;
}

function delete_file(string $name): void
{
    if (preg_match('/^[0-9a-f]{32}\.bin$/', $name)) @unlink(DT_FILES_DIR . '/' . $name);
}
