<?php
/**
 * סודות — מפתחות API שהמפתח מזין, בקובץ מחוץ לגיט ומחוץ לפריסה.
 *
 * הקובץ: data/secrets.json. התיקייה data/ חסומה ב-.htaccess, הקובץ לא בגיט
 * (data/.gitignore מתעלם מהכול), והפריסה (rsync בלי --delete) לא נוגעת
 * בקבצים שאינם בריפו. לכן המפתח נשאר בשרת בלבד.
 *
 * ה-API לעולם לא מחזיר את המפתח עצמו — רק "מוגדר" וארבעת התווים האחרונים,
 * כדי שהמפתח יזהה איזה מפתח הוזן.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/log.php';

const SECRET_KEYS = [
    'anthropic_api_key' => ['label' => 'מפתח API של Anthropic (ניסוח מחדש בבינה)', 'prefix' => 'sk-ant-'],
];

function secretsFile(): string {
    return defined('SECRETS_FILE') ? SECRETS_FILE : dirname(DB_FILE) . '/secrets.json';
}

function secretsLoad(): array {
    $f = secretsFile();
    if (!is_file($f)) return [];
    $data = json_decode((string) @file_get_contents($f), true);
    return is_array($data) ? $data : [];
}

function secretsSave(array $data): void {
    $f = secretsFile();
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $tmp = $f . '.tmp';
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
        throw new AppError('לא הצלחתי לכתוב את קובץ הסודות — לבדוק הרשאות על data/', 500);
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $f)) { @unlink($tmp); throw new AppError('לא הצלחתי לשמור את קובץ הסודות', 500); }
}

/** המפתח עצמו — לשימוש פנימי בלבד, לעולם לא נשלח לדפדפן. */
function secretGet(string $key): ?string {
    $v = secretsLoad()[$key] ?? null;
    return is_string($v) && $v !== '' ? $v : null;
}

function secretSet(string $key, string $value, array $developer): void {
    requireDeveloper($developer);
    if (!isset(SECRET_KEYS[$key])) throw new AppError('סוד לא מוכר', 400);
    $value = trim($value);
    if ($value === '') throw new AppError('המפתח ריק');
    if (strlen($value) < 20 || preg_match('/\s/', $value)) throw new AppError('זה לא נראה כמו מפתח API');
    $prefix = SECRET_KEYS[$key]['prefix'];
    if ($prefix !== '' && !str_starts_with($value, $prefix)) {
        throw new AppError('מפתח של Anthropic מתחיל ב-' . $prefix);
    }
    $all = secretsLoad();
    $all[$key] = $value;
    secretsSave($all);
    logEvent('info', 'secret-set', 'מפתח הוגדר', ['key' => $key], $developer);
}

function secretRemove(string $key, array $developer): void {
    requireDeveloper($developer);
    $all = secretsLoad();
    unset($all[$key]);
    secretsSave($all);
    logEvent('info', 'secret-remove', 'מפתח הוסר', ['key' => $key], $developer);
}

/** מה המסך מציג: לכל סוד — מוגדר או לא, וסיומת לזיהוי. בלי הערך. */
function secretsStatus(): array {
    $all = secretsLoad();
    $out = [];
    foreach (SECRET_KEYS as $key => $meta) {
        $v = $all[$key] ?? '';
        $out[$key] = [
            'label'  => $meta['label'],
            'set'    => is_string($v) && $v !== '',
            'hint'   => is_string($v) && strlen($v) >= 4 ? '…' . substr($v, -4) : null,
            'prefix' => $meta['prefix'],
        ];
    }
    return $out;
}
