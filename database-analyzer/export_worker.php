<?php
/**
 * Export Worker - runs as background process
 * Usage: php export_worker.php <jobId> <paramsJson>
 */

if (php_sapi_name() !== 'cli') exit('CLI only');

ini_set('memory_limit', '256M');
set_time_limit(600);

$jobId = $argv[1] ?? '';
$params = json_decode($argv[2] ?? '{}', true);

if (!$jobId || !$params) exit('Missing params');

$exportDir = __DIR__ . '/exports';
$progressFile = $exportDir . '/' . $jobId . '.json';

function progress($data) {
    global $progressFile;
    @file_put_contents($progressFile, json_encode($data, JSON_UNESCAPED_UNICODE));
}

function quoteId($name) {
    return '`' . str_replace('`', '``', $name) . '`';
}

$host = $params['host'] ?? 'localhost';
$port = intval($params['port'] ?? 3306);
$database = $params['database'] ?? '';
$username = $params['username'] ?? '';
$password = $params['password'] ?? '';
$mode = $params['mode'] ?? 'both';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username, $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    progress(['status' => 'error', 'error' => 'חיבור נכשל: ' . $e->getMessage()]);
    exit;
}

$zipName = $database . '_' . $mode . '_' . date('Ymd_His') . '.zip';
$zipPath = $exportDir . '/' . $zipName;

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    progress(['status' => 'error', 'error' => 'לא ניתן ליצור ZIP']);
    exit;
}

$stmt = $pdo->query("SHOW FULL TABLES");
$allItems = [];
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $allItems[] = ['name' => $row[0], 'type' => $row[1]];
}
$totalItems = count($allItems);

$chunkSize = 5000;
$structure = ['database' => $database, 'exportedAt' => date('c'), 'mode' => $mode, 'tables' => [], 'views' => []];
$tempFiles = [];

$stmtFK = $pdo->prepare("
    SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.UPDATE_RULE, rc.DELETE_RULE
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
    LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
    WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ? AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
");
$stmtTrig = $pdo->prepare("
    SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
    FROM INFORMATION_SCHEMA.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?
");
$stmtInfo = $pdo->prepare(
    "SELECT TABLE_COMMENT, ENGINE, TABLE_COLLATION, AUTO_INCREMENT, TABLE_ROWS, CREATE_TIME, UPDATE_TIME
     FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
);

foreach ($allItems as $idx => $item) {
    $name = $item['name'];
    $isView = ($item['type'] !== 'BASE TABLE');
    $qt = quoteId($name);

    progress([
        'status' => 'working',
        'current' => $idx + 1,
        'total' => $totalItems,
        'currentTable' => $name,
        'percent' => round(($idx + 1) / $totalItems * 100)
    ]);

    if ($mode === 'structure' || $mode === 'both') {
        $entry = ['name' => $name];
        $entry['columns'] = $pdo->query("SHOW FULL COLUMNS FROM {$qt}")->fetchAll();
        if (!$isView) {
            $entry['indexes'] = $pdo->query("SHOW INDEX FROM {$qt}")->fetchAll();
            $stmtFK->execute([$database, $name]);
            $entry['foreignKeys'] = $stmtFK->fetchAll();
            $stmtTrig->execute([$database, $name]);
            $entry['triggers'] = $stmtTrig->fetchAll();
            $stmtInfo->execute([$database, $name]);
            $entry['tableInfo'] = $stmtInfo->fetch();
        }
        $createRow = $pdo->query("SHOW CREATE TABLE {$qt}")->fetch(PDO::FETCH_NUM);
        $entry['createStatement'] = $createRow[1] ?? '';
        if ($isView) $structure['views'][] = $entry;
        else $structure['tables'][] = $entry;
    }

    if ($mode === 'data' || $mode === 'both') {
        try {
            $count = intval($pdo->query("SELECT COUNT(*) FROM {$qt}")->fetchColumn());
            $tmpFile = tempnam(sys_get_temp_dir(), 'dbexp_');
            $tempFiles[] = $tmpFile;
            $fp = fopen($tmpFile, 'w');
            fwrite($fp, '{"table":' . json_encode($name) . ',"rowCount":' . $count . ',"rows":[');
            $first = true;
            for ($offset = 0; $offset < $count; $offset += $chunkSize) {
                $chunk = $pdo->query("SELECT * FROM {$qt} LIMIT {$chunkSize} OFFSET {$offset}")->fetchAll();
                foreach ($chunk as $row) {
                    foreach ($row as $k => &$val) {
                        if (is_string($val) && !mb_check_encoding($val, 'UTF-8')) {
                            $val = base64_encode($val);
                        }
                    }
                    unset($val);
                    if (!$first) fwrite($fp, ',');
                    fwrite($fp, json_encode($row, JSON_UNESCAPED_UNICODE));
                    $first = false;
                }
                unset($chunk);
            }
            fwrite($fp, ']}');
            fclose($fp);
            $folder = $isView ? 'views' : 'tables';
            $zip->addFile($tmpFile, "data/{$folder}/{$name}.json");
        } catch (Exception $e) {
            $zip->addFromString("data/{$name}.error.txt", $e->getMessage());
        }
    }
}

if ($mode === 'structure' || $mode === 'both') {
    $zip->addFromString('structure.json', json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

$zip->close();
foreach ($tempFiles as $tmp) @unlink($tmp);

$fileSize = filesize($zipPath);

progress([
    'status' => 'done',
    'file' => 'exports/' . $zipName,
    'filename' => $zipName,
    'size' => $fileSize
]);
