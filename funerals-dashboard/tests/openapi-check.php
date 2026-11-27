<?php
// E8: ולידציה מבנית של openapi.json מול OpenAPI 3.1 (בלי תלות חיצונית), והתאמה מדויקת לטבלת הפעולות שבקוד.
declare(strict_types=1);
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
function reply(int $s, array $d): never { fwrite(STDERR, json_encode($d, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
require __DIR__ . '/../ext-lib.php';
$errors = [];
$e = static function (string $m) use (&$errors) { $errors[] = $m; };
$doc = json_decode((string)file_get_contents(__DIR__ . '/../openapi.json'), true);
if (!is_array($doc)) { fwrite(STDERR, "openapi.json אינו JSON\n"); exit(1); }
if (!preg_match('/^3\.1\.\d+$/', (string)($doc['openapi'] ?? ''))) $e('openapi חייב להיות 3.1.x');
if (!is_string($doc['info']['title'] ?? null) || !is_string($doc['info']['version'] ?? null)) $e('info.title / info.version חסרים');
foreach ($doc['servers'] ?? [] as $s) if (!preg_match('#^https://#', (string)($s['url'] ?? ''))) $e('servers.url חייב https');
$allowedTop = ['openapi', 'info', 'jsonSchemaDialect', 'servers', 'paths', 'webhooks', 'components', 'security', 'tags', 'externalDocs'];
foreach (array_keys($doc) as $k) if (!in_array($k, $allowedTop, true) && !str_starts_with($k, 'x-')) $e("שדה עליון לא חוקי: $k");
$schemes = $doc['components']['securitySchemes'] ?? [];
if (($schemes['bearer']['type'] ?? '') !== 'http' || ($schemes['bearer']['scheme'] ?? '') !== 'bearer') $e('securitySchemes.bearer: http/bearer');
foreach ($doc['security'] ?? [] as $req) foreach (array_keys($req) as $n) if (!isset($schemes[$n])) $e("security מפנה ל-$n שלא קיים");
$ops = [];
$methods = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];
foreach ($doc['paths'] ?? [] as $path => $item) {
    if (!str_starts_with($path, '/') || str_contains($path, '?')) $e("נתיב לא חוקי: $path");
    foreach ($item as $method => $op) {
        if (!in_array($method, $methods, true)) { $e("שיטה לא חוקית $method ב-$path"); continue; }
        if ($method === 'delete') $e("יש פעולת מחיקה: $path");
        $id = $op['operationId'] ?? null;
        if (!is_string($id) || isset($ops[$id])) $e("operationId חסר או כפול ב-$path");
        $ops[$id] = strtoupper($method);
        if (empty($op['responses']) || !is_array($op['responses'])) $e("responses חסר ב-$id");
        foreach ($op['responses'] ?? [] as $code => $r) {
            if (!preg_match('/^([1-5](\d\d|XX)|default)$/', (string)$code)) $e("קוד תשובה לא חוקי $code ב-$id");
            if (!is_string($r['description'] ?? null)) $e("description חסר בתשובה $code ב-$id");
        }
        foreach ($op['parameters'] ?? [] as $p) {
            if (!in_array($p['in'] ?? '', ['query', 'header', 'path', 'cookie'], true) || !is_string($p['name'] ?? null)) $e("פרמטר לא חוקי ב-$id");
            if (!isset($p['schema']) && !isset($p['content'])) $e("פרמטר בלי schema ב-$id");
        }
        if (isset($op['requestBody']) && empty($op['requestBody']['content'])) $e("requestBody בלי content ב-$id");
        if (mb_strlen((string)($op['description'] ?? '')) > 300) $e("description ארוך מ-300 (מגבלת GPT) ב-$id");
    }
}
// כל $ref מצביע לקיים
array_walk_recursive($doc, static function ($v, $k) use ($doc, $e) {
    if ($k !== '$ref') return;
    $node = $doc;
    foreach (explode('/', substr((string)$v, 2)) as $part) { if (!is_array($node) || !array_key_exists($part, $node)) { $e("\$ref שבור: $v"); return; } $node = $node[$part]; }
});
$code = array_map(static fn($a) => $a['method'], extActions());
ksort($code); ksort($ops);
if ($code !== $ops) $e('הפעולות ב-openapi.json אינן בדיוק טבלת הקוד: ' . json_encode([$code, $ops]));
if ($errors) { fwrite(STDERR, "openapi.json:\n- " . implode("\n- ", $errors) . "\n"); exit(1); }
echo "openapi.json: 3.1 תקין, " . count($ops) . " פעולות = טבלת הקוד\n";
