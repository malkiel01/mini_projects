<?php
/**
 * נקודת הכניסה לשרת. שלב 1: רק בדיקת מצב.
 *
 * health מחזיר מה הסביבה מסוגלת לעשות — בלי שום תוכן ובלי מספרים של משתמשים.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    if ($action === 'health') {
        $probe = 'בדיקה';
        db();
        json_out([
            'ok'      => true,
            'stage'   => 1,
            'php'     => PHP_VERSION,
            'sqlite'  => true,
            'crypto'  => unseal(seal($probe)) === $probe,
            'mail'    => function_exists('mail'),
            'zip'     => class_exists('ZipArchive'),
        ]);
    }
    json_out(['error' => 'פעולה לא מוכרת'], 404);
} catch (Throwable $e) {
    // הודעת השגיאה עלולה לכלול נתיבים — לא מוחזרת ללקוח.
    error_log('document-templates: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'שגיאת שרת'], 500);
}
