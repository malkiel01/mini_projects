<?php
/**
 * כלים משותפים: שגיאת API, מזהים, זמן, ביקורת, והצפנה (סודות וטוקנים במסד, ומפתח החתימה של התוסף).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

final class ApiError extends RuntimeException
{
    public function __construct(string $message, public readonly string $codeName = 'invalid', public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}

/** מזהה חיצוני עם קידומת: cus_ / acc_ / sub_ … + ‏20 תווים base32 אקראיים (לא רציף — אי אפשר לנחש). */
function new_id(string $prefix): string
{
    $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    foreach (str_split(random_bytes(20)) as $b) $s .= $alphabet[ord($b) & 31];
    return $prefix . '_' . $s;
}

function now(): int
{
    $fake = getenv('PAY_NOW');
    return $fake !== false && $fake !== '' ? (int)$fake : time();
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function audit(string $actorType, string $actorId, string $action, string $objType = '', string $objId = '', array $detail = [], ?string $tenant = null): void
{
    db_run('INSERT INTO audit (tenant, actor_type, actor_id, action, object_type, object_id, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $tenant, $actorType, $actorId, $action, $objType, $objId,
        json_encode($detail, JSON_UNESCAPED_UNICODE), client_ip(), now(),
    ]);
}

function setting(string $key, ?string $default = null): ?string
{
    $row = db_one('SELECT value FROM setting WHERE key = ?', [$key]);
    return $row['value'] ?? $default;
}

function set_setting(string $key, string $value): void
{
    db_run('INSERT INTO setting (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, $value]);
}

function json_obj(?string $s): array
{
    $j = json_decode((string)$s, true);
    return is_array($j) ? $j : [];
}

/** כתובת הבסיס של התוסף — כל הקישורים נבנים ממנה (פרק 12.8: מעבר כתובת = שינוי אחד). */
function base_url(): string
{
    $env = getenv('PAY_BASE_URL');
    if ($env) return rtrim($env, '/') . '/';
    $set = setting('base_url');
    if ($set) return rtrim($set, '/') . '/';
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . "://$host$dir/";
}

// ---------- הצפנה ----------

/** מפתח ההצפנה של המסד (data/secret.key). בלעדיו הסודות והטוקנים לא ניתנים לפענוח — לגבות בנפרד. */
function secret_key(): string
{
    static $k = null;
    if ($k !== null) return $k;
    $file = pay_data_dir() . '/secret.key';
    if (!is_file($file)) {
        $fh = fopen($file, 'x');
        if ($fh) {
            fwrite($fh, base64_encode(sodium_crypto_secretbox_keygen()));
            fclose($fh);
            @chmod($file, 0600);
        }
    }
    $raw = base64_decode(trim((string)file_get_contents($file)), true);
    if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('secret.key פגום');
    return $k = $raw;
}

function seal(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, secret_key()));
}

function unseal(string $sealed): string
{
    $raw = base64_decode($sealed, true);
    if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('ערך מוצפן פגום');
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), secret_key());
    if ($plain === false) throw new RuntimeException('פענוח נכשל');
    return $plain;
}

/**
 * זוג Ed25519 של התוסף (data/signing.key) — חותם על webhooks למערכות שעובדות ב-Ed25519.
 * הציבורי מתפרסם ב-api.php?action=key.
 * @return array{kid:string, secret:string, public:string}
 */
function signing_key(): array
{
    static $k = null;
    if ($k !== null) return $k;
    $file = pay_data_dir() . '/signing.key';
    if (!is_file($file)) {
        $pair = sodium_crypto_sign_keypair();
        $fh = fopen($file, 'x');
        if ($fh) {
            fwrite($fh, json_encode(['kid' => gmdate('Y-m', now()), 'pair' => base64_encode($pair)]));
            fclose($fh);
            @chmod($file, 0600);
        }
    }
    $j = json_decode((string)file_get_contents($file), true);
    $pair = base64_decode((string)($j['pair'] ?? ''), true);
    if (!$pair) throw new RuntimeException('signing.key פגום');
    return $k = ['kid' => (string)$j['kid'], 'secret' => sodium_crypto_sign_secretkey($pair), 'public' => sodium_crypto_sign_publickey($pair)];
}

// ---------- כסף ----------

/** מיליוניות ₪ → אגורות (עיגול כלפי מטה — לטובת הלקוח בתצוגת יתרה). */
function micros_to_agorot(int $micros): int
{
    return intdiv($micros, 10_000) - (($micros < 0 && $micros % 10_000) ? 1 : 0);
}

function agorot_to_micros(int $agorot): int
{
    return $agorot * 10_000;
}
