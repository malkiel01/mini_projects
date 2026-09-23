<?php
/**
 * הצפנת סודות במנוחה.
 *
 * מהרגע שמשתמשים אחרים מפקידים כאן טוקן גיטהאב ומפתח Anthropic, מסד
 * הנתונים מחזיק כוח לפעול בשמם. אחסון משותף אינו מקום לשמור דברים כאלה
 * בטקסט גלוי: גיבוי שדלף, שגיאת הרשאות בתיקייה, או מי שיש לו גישה
 * לחשבון ה-cPanel — כל אחד מהם היה מספיק.
 *
 * המפתח יושב בקובץ נפרד מהמסד. מי שמשיג את אחד מהם בלבד אינו מקבל דבר.
 */

declare(strict_types=1);

if (!defined('SECRET_KEY_FILE')) define('SECRET_KEY_FILE', __DIR__ . '/../data/secret.key');

/** התוכן שחוסם גישה ישירה ל-data/ בשרתי Apache. */
const DATA_HTACCESS = <<<TXT
    # נוצר אוטומטית. data/ מכילה את מסד המטלות, אסימונים, ומפתח ההצפנה.
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
    TXT;

/**
 * מוודא שתיקיית הנתונים קיימת ושהיא חסומה לגישה ישירה.
 *
 * ‏.htaccess מגיע עם הריפו, אבל תיקייה שנוצרה בזמן ריצה — בהתקנה חדשה,
 * או אחרי שמישהו מחק אותה — הייתה נוצרת בלעדיו, ואז מפתח ההצפנה ומסד
 * המטלות מוגשים ישירות מהדפדפן. ההגנה לא צריכה להיות תלויה בפריסה.
 */
function ensureDataDir(string $dir): void {
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("התיקייה $dir אינה ניתנת ליצירה");
    }
    $guard = $dir . '/.htaccess';
    if (!is_file($guard)) @file_put_contents($guard, DATA_HTACCESS);
}

/** נוצר בשימוש הראשון. 32 בתים אקראיים, בהרשאות קריאה לבעלים בלבד. */
function secretKey(): string {
    static $key = null;
    if (is_string($key)) return $key;

    if (is_file(SECRET_KEY_FILE)) {
        $raw = (string) file_get_contents(SECRET_KEY_FILE);
        if (strlen($raw) === 32) return $key = $raw;
    }

    ensureDataDir(dirname(SECRET_KEY_FILE));

    $key = random_bytes(32);
    // כתיבה לקובץ זמני ואז שינוי שם: אחרת חלון קצר שבו הקובץ קיים
    // בהרשאות ברירת המחדל, ותהליך אחר יכול לקרוא אותו.
    $tmp = SECRET_KEY_FILE . '.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $key);
    @chmod($tmp, 0600);
    rename($tmp, SECRET_KEY_FILE);
    return $key;
}

/**
 * מצפין. הפורמט נושא סימון גרסה, כדי שיהיה אפשר להחליף אלגוריתם בעתיד
 * בלי לנחש מה נכתב בעבר.
 */
function encryptSecret(string $plain): string {
    if ($plain === '') return '';

    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, secretKey()));
    }

    // ‏ext-sodium חסר באחסון ישן. GCM נותן את אותה הגנה מפני שינוי תוכן.
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', secretKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) throw new RuntimeException('הצפנה נכשלה');
    return 'v2:' . base64_encode($iv . $tag . $ct);
}

/** מפענח. מחזיר מחרוזת ריקה על כל תקלה — קורא לעולם לא מקבל זבל. */
function decryptSecret(string $blob): string {
    if ($blob === '') return '';

    try {
        if (str_starts_with($blob, 'v1:')) {
            if (!function_exists('sodium_crypto_secretbox_open')) return '';
            $raw   = base64_decode(substr($blob, 3), true);
            if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $out   = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, secretKey());
            return is_string($out) ? $out : '';
        }
        if (str_starts_with($blob, 'v2:')) {
            $raw = base64_decode(substr($blob, 3), true);
            if ($raw === false || strlen($raw) < 28) return '';
            $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secretKey(),
                                   OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
            return is_string($out) ? $out : '';
        }
    } catch (Throwable $e) {
        return '';
    }
    return '';
}

/** ‏ארבעת התווים האחרונים — מספיק כדי לזהות סוד, לא מספיק כדי להשתמש בו. */
function secretTail(string $plain): string {
    return $plain === '' ? '' : substr($plain, -4);
}
