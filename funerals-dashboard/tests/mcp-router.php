<?php
// בדיקות מקומיות בלבד (php -S … tests/mcp-router.php): מריץ את כללי ה-rewrite של .htaccess שבתיקייה (פרוסה 09),
// כדי שהבדיקות יעברו בכתובות האמיתיות (/mcp, /.well-known/…) ויבדקו את הכללים עצמם. כל השאר — כרגיל.
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$fdApp = dirname(__DIR__);
$fdPath = ltrim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
foreach (file("$fdApp/.htaccess") as $fdLine) {
    if (!preg_match('/^\s*RewriteRule\s+(\S+)\s+(\S+)\s+\[[^\]]*L[^\]]*\]/', $fdLine, $fdM) || $fdM[2] === '-') continue;
    if (!preg_match('#' . $fdM[1] . '#', $fdPath)) continue;
    [$fdFile, $fdQuery] = array_pad(explode('?', $fdM[2], 2), 2, '');
    parse_str($fdQuery, $fdQ);
    $_GET = $fdQ + $_GET;
    $_SERVER['SCRIPT_FILENAME'] = "$fdApp/$fdFile";
    $_SERVER['SCRIPT_NAME'] = '/' . $fdFile;
    $_SERVER['PHP_SELF'] = '/' . $fdFile;
    chdir(dirname("$fdApp/$fdFile"));
    unset($fdLine, $fdM, $fdQuery, $fdQ, $fdPath);
    require "$fdApp/$fdFile";
    return true;
}
return false;
