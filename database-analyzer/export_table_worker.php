<?php
/**
 * Exports data from a single table to a JSON file
 * Usage: php export_table_worker.php <outputFile> <paramsJson>
 */
if (php_sapi_name() !== 'cli') exit;

ini_set('memory_limit', '128M');
set_time_limit(300);

$outputFile = $argv[1] ?? '';
$params = json_decode($argv[2] ?? '{}', true);

if (!$outputFile || !$params) exit(1);

$host = $params['host'] ?? 'localhost';
$port = intval($params['port'] ?? 3306);
$database = $params['database'] ?? '';
$username = $params['username'] ?? '';
$password = $params['password'] ?? '';
$table = $params['table'] ?? '';
$chunkSize = 5000;

if (!$table || !$database) exit(1);

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username, $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $qt = '`' . str_replace('`', '``', $table) . '`';
    $count = intval($pdo->query("SELECT COUNT(*) FROM {$qt}")->fetchColumn());

    $fp = fopen($outputFile, 'w');
    fwrite($fp, '{"table":' . json_encode($table) . ',"rowCount":' . $count . ',"rows":[');

    $first = true;
    for ($offset = 0; $offset < $count; $offset += $chunkSize) {
        $rows = $pdo->query("SELECT * FROM {$qt} LIMIT {$chunkSize} OFFSET {$offset}")->fetchAll();
        foreach ($rows as $row) {
            foreach ($row as &$val) {
                if (is_string($val) && !mb_check_encoding($val, 'UTF-8')) {
                    $val = base64_encode($val);
                }
            }
            unset($val);
            if (!$first) fwrite($fp, ',');
            fwrite($fp, json_encode($row, JSON_UNESCAPED_UNICODE));
            $first = false;
        }
        unset($rows);
    }

    fwrite($fp, ']}');
    fclose($fp);
    exit(0);
} catch (Exception $e) {
    @file_put_contents($outputFile, json_encode(['error' => $e->getMessage()]));
    exit(1);
}
