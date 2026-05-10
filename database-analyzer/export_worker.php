<?php
/**
 * Export Worker - coordinates parallel table export
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
$maxParallel = 4;

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
$includeViews = !empty($params['includeViews']);

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

// Get all tables and views
$stmt = $pdo->query("SHOW FULL TABLES");
$tables = [];
$views = [];
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    if ($row[1] === 'BASE TABLE') {
        $tables[] = $row[0];
    } else {
        $views[] = $row[0];
    }
}

$allForData = $tables;
if ($includeViews) {
    $allForData = array_merge($tables, $views);
}
$totalItems = count($allForData);
$totalStructure = count($tables) + ($includeViews ? count($views) : count($views)); // structure always includes views list

progress(['status' => 'working', 'current' => 0, 'total' => $totalItems, 'currentTable' => 'מבנה...', 'percent' => 0]);

// ============ STRUCTURE (fast, sequential) ============
if ($mode === 'structure' || $mode === 'both') {
    $structure = ['database' => $database, 'exportedAt' => date('c'), 'mode' => $mode, 'tables' => [], 'views' => []];

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

    foreach ($tables as $t) {
        $qt = quoteId($t);
        $entry = ['name' => $t];
        $entry['columns'] = $pdo->query("SHOW FULL COLUMNS FROM {$qt}")->fetchAll();
        $entry['indexes'] = $pdo->query("SHOW INDEX FROM {$qt}")->fetchAll();
        $stmtFK->execute([$database, $t]);
        $entry['foreignKeys'] = $stmtFK->fetchAll();
        $stmtTrig->execute([$database, $t]);
        $entry['triggers'] = $stmtTrig->fetchAll();
        $stmtInfo->execute([$database, $t]);
        $entry['tableInfo'] = $stmtInfo->fetch();
        $createRow = $pdo->query("SHOW CREATE TABLE {$qt}")->fetch(PDO::FETCH_NUM);
        $entry['createStatement'] = $createRow[1] ?? '';
        $structure['tables'][] = $entry;
    }

    foreach ($views as $v) {
        $qv = quoteId($v);
        $entry = ['name' => $v];
        $entry['columns'] = $pdo->query("SHOW FULL COLUMNS FROM {$qv}")->fetchAll();
        $createRow = $pdo->query("SHOW CREATE VIEW {$qv}")->fetch(PDO::FETCH_NUM);
        $entry['createStatement'] = $createRow[1] ?? '';
        $structure['views'][] = $entry;
    }

    $zip->addFromString('structure.json', json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    unset($structure);
}

// ============ DATA (parallel) ============
if ($mode === 'data' || $mode === 'both') {
    $workerScript = __DIR__ . '/export_table_worker.php';
    $connParams = [
        'host' => $host, 'port' => $port,
        'database' => $database, 'username' => $username, 'password' => $password
    ];

    $tempFiles = [];
    $pending = [];

    // Process in batches of $maxParallel
    for ($i = 0; $i < count($allForData); $i += $maxParallel) {
        $batch = array_slice($allForData, $i, $maxParallel);
        $processes = [];

        foreach ($batch as $tableName) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'dbexp_');
            $tempFiles[$tableName] = $tmpFile;

            $tableParams = array_merge($connParams, ['table' => $tableName]);
            $cmd = 'php ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg(json_encode($tableParams));

            $proc = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            if (is_resource($proc)) {
                fclose($pipes[0]);
                $processes[] = ['proc' => $proc, 'pipes' => $pipes, 'table' => $tableName];
            }
        }

        // Wait for batch to complete
        foreach ($processes as $p) {
            stream_get_contents($p['pipes'][1]);
            fclose($p['pipes'][1]);
            fclose($p['pipes'][2]);
            proc_close($p['proc']);
        }

        // Update progress
        $done = min($i + $maxParallel, count($allForData));
        $pct = round($done / $totalItems * 100);
        $currentNames = implode(', ', $batch);
        progress([
            'status' => 'working',
            'current' => $done,
            'total' => $totalItems,
            'currentTable' => $currentNames,
            'percent' => $pct
        ]);
    }

    // Add all temp files to ZIP
    foreach ($tempFiles as $tableName => $tmpFile) {
        if (file_exists($tmpFile) && filesize($tmpFile) > 0) {
            $isView = in_array($tableName, $views);
            $folder = $isView ? 'views' : 'tables';
            $zip->addFile($tmpFile, "data/{$folder}/{$tableName}.json");
        }
    }
}

$zip->close();

// Clean temp files
if (!empty($tempFiles)) {
    foreach ($tempFiles as $tmp) @unlink($tmp);
}

$fileSize = filesize($zipPath);

progress([
    'status' => 'done',
    'file' => 'exports/' . $zipName,
    'filename' => $zipName,
    'size' => $fileSize
]);
