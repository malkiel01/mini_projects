<?php
/** פרטי ה-PHP בשרת — למנהל בלבד, כי יש בהם נתיבים. */
declare(strict_types=1);
require __DIR__ . '/../lib/http.php';
require __DIR__ . '/../lib/auth.php';

try {
    $user = current_user();
    if (!$user || $user['role'] !== 'admin') throw new ApiError('למנהל בלבד', 403);
    json_out([
        'php'            => PHP_VERSION,
        'sapi'           => PHP_SAPI,
        'server'         => $_SERVER['SERVER_SOFTWARE'] ?? '',
        'user_ini'       => ini_get('user_ini.filename'),
        'user_ini_ttl'   => ini_get('user_ini.cache_ttl'),
        'include_path'   => get_include_path(),
        'document_root'  => $_SERVER['DOCUMENT_ROOT'] ?? '',
        'platform_dir'   => realpath(__DIR__ . '/..'),
        'script_name'    => $_SERVER['SCRIPT_NAME'] ?? '',
        'pdo_sqlite'     => extension_loaded('pdo_sqlite'),
        'cwd'            => getcwd(),
    ]);
} catch (ApiError $e) {
    json_out(['error' => $e->getMessage()], $e->status);
}
