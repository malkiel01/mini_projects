<?php
/**
 * גיבוי מחוץ לשרת: פעם בשבוע, עותק של המסד — דחוס ומוצפן **בסיסמה של הבעלים** — נשלח במייל לכתובת שהבעלים בחר.
 * בניגוד לגיבוי היומי (מוצפן במפתח שבשרת), את זה אפשר לפתוח גם אם השרת אבד: ‏php payments/tools/decrypt-backup.php.
 * הסיסמה לא נשמרת — רק המפתח שנגזר ממנה (מוצפן במפתח של התוסף), כדי שאפשר יהיה להצפין כל שבוע בלי לבקש אותה.
 *
 * מבנה הקובץ: ‏"MBEPAY1" · ‏salt (16) · ‏opslimit (8) · ‏memlimit (8) · ‏nonce (24) · ‏secretbox(gzip(sqlite)).
 */

declare(strict_types=1);

require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/offsite_decrypt.php';

const OFFSITE_EVERY = 7 * 86400;
const OFFSITE_MAX_BYTES = 15 * 1024 * 1024; // מגבלה מקובלת לצרופה במייל

function offsite_config(): ?array
{
    $raw = setting('offsite');
    $j = $raw ? json_decode(unseal($raw), true) : null;
    return is_array($j) ? $j : null;
}

function offsite_public(): array
{
    $c = offsite_config();
    return ['enabled' => (bool)($c['enabled'] ?? false), 'email' => (string)($c['email'] ?? ''), 'last_at' => (int)setting('offsite_last', '0') ?: null,
        'last_error' => (string)setting('offsite_error', ''), 'last_size' => (int)setting('offsite_size', '0')];
}

/** ‏$in: ‏{email, passphrase?, enabled}. סיסמה — רק בהפעלה הראשונה או בהחלפה; לפחות 12 תווים. */
function offsite_save(array $t, array $in): array
{
    if ($t['role'] !== 'owner') throw new ApiError('רק לבעלים', 'forbidden', 403);
    $c = offsite_config() ?? [];
    $email = trim((string)($in['email'] ?? ($c['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError('כתובת מייל לא תקינה', 'bad_email');
    $pass = (string)($in['passphrase'] ?? '');
    if ($pass !== '') {
        if (mb_strlen($pass) < 12) throw new ApiError('סיסמה של 12 תווים לפחות — בלעדיה אי אפשר לפתוח את הגיבוי', 'weak_passphrase');
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
        $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $pass, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $c = ['salt' => base64_encode($salt), 'ops' => $ops, 'mem' => $mem, 'key' => base64_encode($key)];
    }
    if (empty($c['key'])) throw new ApiError('צריך לבחור סיסמה לגיבוי', 'weak_passphrase');
    $c['email'] = $email;
    $c['enabled'] = !empty($in['enabled']);
    set_setting('offsite', seal(json_encode($c)));
    audit('admin', $t['id'], 'offsite.saved', 'backup', 'offsite', ['enabled' => $c['enabled'], 'new_passphrase' => $pass !== ''], $t['id']);
    return offsite_public();
}

/** הקובץ המוצפן (בלי לשלוח). */
function offsite_build(array $c): string
{
    $tmp = backup_dir() . '/.tmp-' . bin2hex(random_bytes(6)) . '.sqlite';
    db()->exec('VACUUM INTO ' . db()->quote($tmp));
    $plain = gzencode((string)file_get_contents($tmp), 9);
    unlink($tmp);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return OFFSITE_MAGIC . base64_decode($c['salt']) . pack('J', (int)$c['ops']) . pack('J', (int)$c['mem']) . $nonce
        . sodium_crypto_secretbox($plain, $nonce, base64_decode($c['key']));
}

/** מהשעון: פעם בשבוע (או עכשיו — $force). מחזיר true אם נשלח. */
function offsite_weekly(bool $force = false): bool
{
    $c = offsite_config();
    if (!$c || (!$c['enabled'] && !$force)) return false;
    if (!$force && (int)setting('offsite_last', '0') > now() - OFFSITE_EVERY) return false;
    // ניסיון שנכשל — לא יותר מפעם ביום
    if (!$force && (int)setting('offsite_tried', '0') > now() - 86400) return false;
    set_setting('offsite_tried', (string)now());
    $blob = offsite_build($c);
    if (strlen($blob) > OFFSITE_MAX_BYTES) {
        set_setting('offsite_error', 'הגיבוי גדול מדי למייל (' . round(strlen($blob) / 1048576, 1) . ' מ"ב) — הורדה ידנית מ"גיבויים"');
        return false;
    }
    $name = 'payments-' . gmdate('Ymd', now()) . '.mbepay';
    $ok = offsite_mail($c['email'], 'גיבוי שבועי — תוסף התשלומים', "מצורף גיבוי מוצפן של מסד התשלומים.\n\n"
        . "לפתוח: php payments/tools/decrypt-backup.php $name payments.sqlite — עם הסיסמה שבחרת.\n"
        . "בלי הסיסמה אי אפשר לפתוח אותו, גם לא אנחנו.", $name, $blob);
    set_setting('offsite_error', $ok ? '' : 'שליחת המייל נכשלה');
    if ($ok) {
        set_setting('offsite_last', (string)now());
        set_setting('offsite_size', (string)strlen($blob));
    }
    return $ok;
}

function offsite_mail(string $to, string $subject, string $body, string $fileName, string $data): bool
{
    $hook = $GLOBALS['PAY_OFFSITE_HOOK'] ?? null;
    if (is_callable($hook)) return (bool)$hook($to, $subject, $body, $fileName, $data);
    $host = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
    $from = setting('mail_from') ?: "no-reply@$host";
    $b = 'b' . bin2hex(random_bytes(8));
    $msg = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body))
        . "--$b\r\nContent-Type: application/octet-stream; name=\"$fileName\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$fileName\"\r\n\r\n"
        . chunk_split(base64_encode($data)) . "--$b--\r\n";
    $headers = ['MIME-Version: 1.0', "Content-Type: multipart/mixed; boundary=\"$b\"", "From: $from"];
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $msg, implode("\r\n", $headers));
}
