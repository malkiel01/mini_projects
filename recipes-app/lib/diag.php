<?php
/**
 * אבחון למנהל — מה שהשרת אומר על עצמו.
 *
 * הקובץ הזה קיים בגלל פרק 11 באפיון: שתי עובדות שאין לי דרך להשיג
 * מחוץ לשרת — מגבלות ההעלאה והנפח הפנוי. במקום לנחש אותן, האפליקציה
 * שואלת את השרת בזמן ריצה ומציגה את התשובה.
 *
 * הכול לקריאה בלבד. אין כאן פעולה שמשנה משהו, ולכן גם אין מה לאשר.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

/** ממיר "20M" של php.ini למספר בייטים. */
function iniBytes(string $value): int {
    $value = trim($value);
    if ($value === '') return 0;
    $unit = strtolower($value[strlen($value) - 1]);
    $num  = (int) $value;
    return match ($unit) {
        'g'     => $num * 1024 * 1024 * 1024,
        'm'     => $num * 1024 * 1024,
        'k'     => $num * 1024,
        default => (int) $value,
    };
}

function humanBytes(int $bytes): string {
    if ($bytes <= 0) return '0';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int) floor(log($bytes, 1024));
    $i = max(0, min($i, count($units) - 1));
    return round($bytes / (1024 ** $i), $i === 0 ? 0 : 1) . ' ' . $units[$i];
}

function diagnostics(): array {
    $dataDir  = dirname(DB_FILE);
    $upload   = iniBytes((string) ini_get('upload_max_filesize'));
    $post     = iniBytes((string) ini_get('post_max_size'));

    // התקרה האמיתית היא המינימום בין מה שהאפיון קבע לבין מה שהשרת מרשה.
    // post_max_size נושא גם את שאר שדות הטופס, ולכן הוא חייב להיות גדול
    // מהקובץ — ואם אינו, הוא זה שחוסם.
    $videoSpec = appSetting('video_max_bytes');
    $quota     = appSetting('quota_bytes');
    $effectiveVideo = min($videoSpec, $upload ?: PHP_INT_MAX, $post ?: PHP_INT_MAX);

    $free  = @disk_free_space($dataDir);
    $total = @disk_total_space($dataDir);

    $counts = [];
    foreach (['users', 'recipes', 'media', 'comments', 'products'] as $table) {
        $counts[$table] = (int) db()->query("SELECT COUNT(*) c FROM $table")->fetch()['c'];
    }

    $mediaBytes = (int) db()->query("SELECT COALESCE(SUM(bytes),0) b FROM media
                                      WHERE source = 'upload'")->fetch()['b'];

    return [
        'php' => [
            'version'             => PHP_VERSION,
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size'       => (string) ini_get('post_max_size'),
            'max_file_uploads'    => (string) ini_get('max_file_uploads'),
            'memory_limit'        => (string) ini_get('memory_limit'),
        ],
        'limits' => [
            'video_spec'        => humanBytes($videoSpec),
            'video_effective'   => humanBytes($effectiveVideo),
            // הדגל שבגללו המסך הזה נכתב: אם השרת מגביל יותר מהאפיון,
            // אסור שהאפליקציה תבטיח 20MB.
            'video_capped_by_server' => $effectiveVideo < $videoSpec,
            'image_max'         => humanBytes(appSetting('image_max_bytes')),
            'quota_per_user'    => humanBytes($quota),
        ],
        'disk' => [
            'free'          => is_float($free)  ? humanBytes((int) $free)  : 'לא זמין',
            'total'         => is_float($total) ? humanBytes((int) $total) : 'לא זמין',
            'media_used'    => humanBytes($mediaBytes),
            // כמה חשבונות מלאים הדיסק מחזיק — זו השאלה האמיתית מאחורי
            // "מה הנפח הפנוי", והיא חשובה כי ההרשמה חופשית.
            'full_accounts' => is_float($free) ? (int) floor($free / $quota) : null,
        ],
        'extensions' => [
            'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            'fileinfo'   => extension_loaded('fileinfo'),   // בדיקת סוג אמיתית
            'mbstring'   => extension_loaded('mbstring'),
            'gd'         => extension_loaded('gd'),
        ],
        'mail' => [
            'function_exists' => function_exists('mail'),
            'sendmail_path'   => (string) ini_get('sendmail_path'),
        ],
        'storage' => [
            'data_dir'       => $dataDir,
            'data_writable'  => is_writable($dataDir),
            'media_writable' => is_dir(MEDIA_DIR) ? is_writable(MEDIA_DIR) : null,
            'db_size'        => is_file(DB_FILE) ? humanBytes((int) filesize(DB_FILE)) : '—',
            'wal_mode'       => (string) db()->query('PRAGMA journal_mode')->fetchColumn(),
            'foreign_keys'   => (bool) db()->query('PRAGMA foreign_keys')->fetchColumn(),
        ],
        'counts' => $counts,
    ];
}
