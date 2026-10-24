<?php
/** פענוח הגיבוי השבועי — בלי תלות בתוסף, כדי שכלי השחזור ירוץ גם בלי השרת (ראו offsite.php). */

declare(strict_types=1);

if (!defined('OFFSITE_MAGIC')) define('OFFSITE_MAGIC', 'MBEPAY1');

/** פענוח — לכלי השחזור ולבדיקות. ‏null = סיסמה שגויה או קובץ פגום. */
function offsite_decrypt(string $blob, string $passphrase): ?string
{
    $h = strlen(OFFSITE_MAGIC);
    $saltLen = SODIUM_CRYPTO_PWHASH_SALTBYTES;
    if (strlen($blob) < $h + $saltLen + 16 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || substr($blob, 0, $h) !== OFFSITE_MAGIC) return null;
    $salt = substr($blob, $h, $saltLen);
    $ops = unpack('J', substr($blob, $h + $saltLen, 8))[1];
    $mem = unpack('J', substr($blob, $h + $saltLen + 8, 8))[1];
    $nonce = substr($blob, $h + $saltLen + 16, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    $gz = sodium_crypto_secretbox_open(substr($blob, $h + $saltLen + 16 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
    if ($gz === false) return null;
    $out = gzdecode($gz);
    return $out === false ? null : $out;
}
