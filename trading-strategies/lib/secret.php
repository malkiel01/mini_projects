<?php
/**
 * מפתחות של ספקי נתונים (spec-stage-a 3.4): מוצפנים במסד ב-sodium_crypto_secretbox, ומפתח ההצפנה ב-data/secret.key
 * (נוצר בשרת בפעם הראשונה; data/ מוחרגת בפריסה ולא בגיט).
 *
 * המפתח לא חוזר לדפדפן, לא נכתב ללוג ולא נכנס לייצוא. מה שמוצג — רק ארבעת התווים האחרונים.
 * ts_scrub() מנקה כל טקסט שיוצא מאיתנו (שגיאת ספק, לוג) מהמפתח — וגם מכל חלק ממנו באורך 5 תווים ומעלה.
 */

declare(strict_types=1);

function ts_secret_key_path(): string
{
    return defined('TS_SECRET_KEY_PATH') ? TS_SECRET_KEY_PATH : dirname(__DIR__) . '/data/secret.key';
}

function ts_box_key(): string
{
    static $key = null;
    if ($key !== null) return $key;
    $path = ts_secret_key_path();
    if (!is_file($path)) {
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        $new = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        // x — לא דורסים קובץ קיים (שתי בקשות במקביל): מי שהפסיד קורא את מה שנכתב
        $h = @fopen($path, 'x');
        if ($h) {
            fwrite($h, $new);
            fclose($h);
            @chmod($path, 0600);
        }
    }
    $key = (string)file_get_contents($path);
    if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('מפתח ההצפנה פגום');
    return $key;
}

function ts_encrypt(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, ts_box_key()));
}

function ts_decrypt(string $box): string
{
    $raw = base64_decode($box, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('מפתח מוצפן פגום');
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), ts_box_key());
    if ($plain === false) throw new RuntimeException('לא ניתן לפענח את המפתח');
    return $plain;
}

function ts_last4(string $secret): string
{
    return mb_substr($secret, -4);
}

/**
 * מוחק מהטקסט את המפתחות, וכל קטע רציף מהם באורך 5 ומעלה (פרק 15: "גם חלק ממנו מעבר ל-4 התווים האחרונים").
 * מהארוך לקצר — כך מפתח שלם מוחלף בבת אחת.
 */
function ts_scrub(string $text, array $secrets): string
{
    foreach ($secrets as $s) {
        $s = (string)$s;
        $n = strlen($s);
        if ($n < 5) continue;
        for ($len = $n; $len >= 5; $len--) {
            for ($i = 0; $i + $len <= $n; $i++) {
                $part = substr($s, $i, $len);
                if (str_contains($text, $part)) $text = str_replace($part, '••••', $text);
            }
        }
    }
    return $text;
}
