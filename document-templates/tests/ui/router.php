<?php
// שרת מקומי לסריקה העיצובית (tests/ui/audit.js) — מסד זמני ב-E2E_DATA.
// php -S 127.0.0.1:8766 document-templates/tests/ui/router.php   (מתיקיית השורש של הריפו)
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/document-templates/api.php') {
    define('DT_DATA_DIR', getenv('E2E_DATA'));
    define('DT_MAIL_SINK', getenv('E2E_DATA') . '/mail.log');
    define('DT_ALLOW_LOCAL_FETCH', true);   // המערכת החיצונית המדומה רצה מקומית
    $_SERVER['SCRIPT_NAME'] = $p;
    chdir(dirname(__DIR__, 2));
    require dirname(__DIR__, 2) . '/api.php';
    return true;
}
return false;
