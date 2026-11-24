<?php
declare(strict_types=1);

/**
 * סודות מוצפנים במסד (אפיון 00 §3.6, 01 §2.1.7): sodium_crypto_secretbox, במפתח של 32 בתים ב-data/secret.key (0600).
 * המפתח נוצר בבקשה הראשונה אם אין. ערך סוד לא נכתב ליומן ולא חוזר בתשובת API.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

function secretKeyPath(): string { return dataDir() . '/secret.key'; }

/** המפתח (32 בתים). אין קובץ → נוצר עכשיו, אטומית ו-0600. קובץ פגום → 503 (לא דורסים: סודות קיימים תלויים בו). */
function secretKey(): string {
    static $key = null;
    if (is_string($key)) return $key;
    $path = secretKeyPath();
    if (!is_file($path)) {
        $old = umask(0077);
        try {
            $tmp = @tempnam(dataDir(), '.key-');
            if ($tmp === false || @file_put_contents($tmp, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                if (is_string($tmp)) @unlink($tmp);
                reply(503, ['error' => 'לא ניתן ליצור את מפתח ההצפנה ב-data/. בדוק הרשאות כתיבה.']);
            }
            @chmod($tmp, 0600);
            // link() נכשל אם תהליך אחר כבר יצר מפתח — ואז משתמשים בשלו
            if (!@link($tmp, $path) && !is_file($path)) { @unlink($tmp); reply(503, ['error' => 'לא ניתן לשמור את מפתח ההצפנה']); }
            @unlink($tmp);
        } finally {
            umask($old);
        }
    }
    $k = @file_get_contents($path);
    if (!is_string($k) || strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) reply(503, ['error' => 'מפתח ההצפנה ב-data/secret.key אינו תקין']);
    return $key = $k;
}

function secretEncrypt(string $plain): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, secretKey()));
}
function secretDecrypt(string $enc): ?string {
    if (!str_starts_with($enc, 'v1:')) return null;
    $raw = base64_decode(substr($enc, 3), true);
    if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), secretKey());
    return $plain === false ? null : $plain;
}

/** שומר סוד לארגון (0 = של השרת כולו). */
function secretPut(string $name, int $orgId, string $value): void {
    db()->prepare('INSERT INTO secrets(name, org_id, value_enc, updated_at) VALUES (?,?,?,?)
        ON CONFLICT(name, org_id) DO UPDATE SET value_enc = excluded.value_enc, updated_at = excluded.updated_at')
        ->execute([$name, $orgId, secretEncrypt($value), gmdate('c')]);
}
function secretGet(string $name, int $orgId): ?string {
    $st = db()->prepare('SELECT value_enc FROM secrets WHERE name = ? AND org_id = ?');
    $st->execute([$name, $orgId]);
    $v = $st->fetchColumn();
    return $v === false ? null : secretDecrypt((string)$v);
}
/** בדיקת תקינות: הצפנה ופענוח של ערך בדיקה, בלי לכתוב למסד. */
function secretSelfTest(): bool {
    $probe = bin2hex(random_bytes(8));
    return secretDecrypt(secretEncrypt($probe)) === $probe;
}
