<?php
declare(strict_types=1);
/**
 * מייצר את openapi.json, gpt-prompt.md (GPT עם Actions) ו-gpt-project-prompt.md (פרויקט ChatGPT, פרוסה 08)
 * מטבלת הפעולות והבדיקות שב-ext-lib.php (מקור אחד).
 *   php funerals-dashboard/tools/build-ext.php          — כותב את שני הקבצים
 *   php funerals-dashboard/tools/build-ext.php --check  — נכשל אם הקבצים שבריפו לא תואמים לקוד (tests/run.sh)
 * שורת פקודה בלבד; בקשת רשת → 404.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['SCRIPT_FILENAME'] = __FILE__;   // ext-lib.php מחזיר 404 רק כשהוא עצמו הסקריפט
function reply(int $status, array $data): never { fwrite(STDERR, json_encode($data, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
require __DIR__ . '/../ext-lib.php';
$root = dirname(__DIR__);
$out = ['openapi.json' => extJson(extOpenApi()), 'gpt-prompt.md' => extPrompt(), 'gpt-project-prompt.md' => extProjectPrompt()];
$check = in_array('--check', $argv, true);
$bad = 0;
foreach ($out as $file => $content) {
    $path = "$root/$file";
    if ($check) {
        if (!is_file($path) || file_get_contents($path) !== $content) { fwrite(STDERR, "FAIL: $file לא תואם לקוד — להריץ php funerals-dashboard/tools/build-ext.php\n"); $bad++; }
    } else {
        file_put_contents($path, $content);
        echo "נכתב: $file\n";
    }
}
exit($bad ? 1 : 0);
