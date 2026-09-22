<?php
/**
 * הצפנת סיסמאות המצלמות.
 *
 * סיסמת מצלמה חייבת להישמר בצורה שאפשר לשחזר — הגשר צריך אותה כדי
 * להתחבר ב-RTSP — ולכן לא hash אלא הצפנה סימטרית (libsodium secretbox).
 * המפתח נוצר פעם אחת בשרת ונשמר ב-data/secret.key, מחוץ לגיט ומוחרג
 * מהפריסה. מי שמעתיק את המסד בלי המפתח מקבל סיסמאות חסרות ערך.
 */

declare(strict_types=1);

require_once __DIR__ . '/errors.php';

if (!defined('SECRET_FILE')) define('SECRET_FILE', __DIR__ . '/../data/secret.key');

function secretKey(): string {
    static $key = null;
    if ($key !== null) return $key;
    if (is_file(SECRET_FILE)) {
        $raw = file_get_contents(SECRET_FILE);
        $key = base64_decode(trim((string) $raw), true) ?: '';
        if (strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) return $key;
    }
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $dir = dirname(SECRET_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (@file_put_contents(SECRET_FILE, base64_encode($key) . "\n", LOCK_EX) === false) {
        throw new AppError('לא ניתן לכתוב את מפתח ההצפנה ב-data/', 500);
    }
    @chmod(SECRET_FILE, 0600);
    return $key;
}

function encryptSecret(string $plain): string {
    if ($plain === '') return '';
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, secretKey()));
}

function decryptSecret(string $stored): string {
    if ($stored === '') return '';
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $box   = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($box, $nonce, secretKey());
    return $plain === false ? '' : $plain;
}

/** טוקן אקראי לגשרים ולקישורים — 32 בתים, מוצג כ-hex. */
function randomToken(int $bytes = 32): string {
    return bin2hex(random_bytes($bytes));
}
