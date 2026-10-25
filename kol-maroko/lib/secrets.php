<?php
/**
 * סודות — מפתח ה-API של Anthropic, בקובץ data/secrets.json: מחוץ לגיט, מוחרג בפריסה, 0600, ו-data/ חסומה.
 * הערך לא חוזר לדפדפן לעולם — רק "מוגדר" וארבעת התווים האחרונים. (כמו recipes-app/lib/secrets.php — הועתק.)
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const SECRET_KEYS = [
    'anthropic_api_key' => ['label' => 'מפתח API של Anthropic (עוזר הבינה)', 'prefix' => 'sk-ant-'],
];

function secrets_file(): string
{
    return KOL_DATA_DIR . '/secrets.json';
}

function secrets_load(): array
{
    $d = is_file(secrets_file()) ? json_decode((string)@file_get_contents(secrets_file()), true) : null;
    return is_array($d) ? $d : [];
}

function secrets_save(array $data): void
{
    $f = secrets_file();
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0775, true);
    $tmp = $f . '.tmp';
    if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT)) === false) throw new ApiError('לא הצלחתי לכתוב את קובץ הסודות', 500);
    @chmod($tmp, 0600);
    if (!@rename($tmp, $f)) { @unlink($tmp); throw new ApiError('לא הצלחתי לשמור את קובץ הסודות', 500); }
}

/** הערך עצמו — לשימוש בשרת בלבד. */
function secret_get(string $key): ?string
{
    $v = secrets_load()[$key] ?? null;
    return is_string($v) && $v !== '' ? $v : null;
}

function secret_set(array $owner, array $b): array
{
    $key = (string)($b['key'] ?? '');
    if (!isset(SECRET_KEYS[$key])) throw new ApiError('סוד לא מוכר');
    $value = trim((string)($b['value'] ?? ''));
    if ($value === '') {
        $all = secrets_load();
        unset($all[$key]);
        secrets_save($all);
        audit_as($owner, 'secret_remove', $key);
        return ['secret' => secrets_status()];
    }
    if (strlen($value) < 20 || preg_match('/\s/', $value)) throw new ApiError('זה לא נראה כמו מפתח API');
    $prefix = SECRET_KEYS[$key]['prefix'];
    if ($prefix !== '' && !str_starts_with($value, $prefix)) throw new ApiError('מפתח של Anthropic מתחיל ב-' . $prefix);
    $all = secrets_load();
    $all[$key] = $value;
    secrets_save($all);
    audit_as($owner, 'secret_set', $key . ' …' . substr($value, -4));
    return ['secret' => secrets_status()];
}

function secrets_status(): array
{
    $all = secrets_load();
    $out = [];
    foreach (SECRET_KEYS as $k => $m) {
        $v = $all[$k] ?? '';
        $out[$k] = ['label' => $m['label'], 'set' => is_string($v) && $v !== '', 'hint' => is_string($v) && strlen($v) >= 4 ? '…' . substr($v, -4) : null];
    }
    return $out;
}
