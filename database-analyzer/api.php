<?php
/**
 * Database Analyzer API
 * MySQL database analysis and management endpoints
 */

header('Content-Type: application/json; charset=utf-8');

$_LOG_FILE = __DIR__ . '/logs/api.log';

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!$input || !is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON request']);
    @file_put_contents($_LOG_FILE, "[" . date('Y-m-d H:i:s') . "] [{$_SERVER['REMOTE_ADDR']}] REJECT | Invalid JSON\n", FILE_APPEND | LOCK_EX);
    exit;
}

$action = $input['action'] ?? '';
$_startTime = microtime(true);
$_logDetail = $input['table'] ?? $input['view'] ?? '';

register_shutdown_function(function() {
    global $action, $_startTime, $_LOG_FILE, $_logDetail;
    $ms = round((microtime(true) - $_startTime) * 1000);
    $code = http_response_code() ?: 200;
    $status = ($code >= 200 && $code < 400) ? 'OK' : 'FAIL';
    $mem = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
    $detail = $_logDetail ? " | {$_logDetail}" : '';
    $line = "[" . date('Y-m-d H:i:s') . "] [{$ip}] {$action} => {$status} ({$code}) | {$ms}ms | {$mem}MB{$detail}";
    @file_put_contents($_LOG_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
});

/**
 * Safely quote a MySQL identifier (table/column name)
 */
function quoteId($name) {
    return '`' . str_replace('`', '``', $name) . '`';
}

/**
 * Build a PDO connection from request parameters
 */
function getConnection($input) {
    $host = $input['host'] ?? 'localhost';
    $port = intval($input['port'] ?? 3306);
    $database = $input['database'] ?? '';
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        PDO::ATTR_TIMEOUT => 10,
    ]);
}

try {
    $pdo = getConnection($input);
    $database = $input['database'] ?? '';

    switch ($action) {

        // ============================================================
        // CONNECT - test connection and return tables + views list
        // ============================================================
        case 'connect': {
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
            echo json_encode([
                'success' => true,
                'tables' => $tables,
                'views' => $views,
                'database' => $database
            ]);
            break;
        }

        // ============================================================
        // GET TABLE STRUCTURE - columns, indexes, triggers, FKs, info
        // ============================================================
        case 'get_table_structure': {
            $table = $input['table'] ?? '';
            $qt = quoteId($table);

            $stmt = $pdo->query("SHOW FULL COLUMNS FROM {$qt}");
            $columns = $stmt->fetchAll();

            $stmt = $pdo->query("SHOW INDEX FROM {$qt}");
            $indexes = $stmt->fetchAll();

            $stmt = $pdo->prepare(
                "SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
                 FROM INFORMATION_SCHEMA.TRIGGERS
                 WHERE EVENT_OBJECT_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?"
            );
            $stmt->execute([$database, $table]);
            $triggers = $stmt->fetchAll();

            $stmt = $pdo->prepare("
                SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
                       kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                       rc.UPDATE_RULE, rc.DELETE_RULE
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                    AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ?
                  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ");
            $stmt->execute([$database, $table]);
            $foreignKeys = $stmt->fetchAll();

            $stmt = $pdo->prepare(
                "SELECT TABLE_COMMENT, ENGINE, TABLE_COLLATION, AUTO_INCREMENT, TABLE_ROWS, CREATE_TIME, UPDATE_TIME
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
            );
            $stmt->execute([$database, $table]);
            $tableInfo = $stmt->fetch();

            echo json_encode([
                'success' => true,
                'columns' => $columns,
                'indexes' => $indexes,
                'triggers' => $triggers,
                'foreignKeys' => $foreignKeys,
                'tableInfo' => $tableInfo
            ]);
            break;
        }

        // ============================================================
        // GET TABLE DATA - paginated rows with primary key info
        // ============================================================
        case 'get_table_data': {
            $table = $input['table'] ?? '';
            $page = max(1, intval($input['page'] ?? 1));
            $limit = max(1, min(500, intval($input['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;
            $qt = quoteId($table);

            $stmt = $pdo->query("SELECT COUNT(*) AS total FROM {$qt}");
            $total = intval($stmt->fetch()['total']);

            $stmt = $pdo->query("SELECT * FROM {$qt} LIMIT {$limit} OFFSET {$offset}");
            $rows = $stmt->fetchAll();

            $stmt = $pdo->query("SHOW KEYS FROM {$qt} WHERE Key_name = 'PRIMARY'");
            $pkColumns = [];
            while ($row = $stmt->fetch()) {
                $pkColumns[] = $row['Column_name'];
            }

            echo json_encode([
                'success' => true,
                'rows' => $rows,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => $total > 0 ? ceil($total / $limit) : 1,
                'primaryKeys' => $pkColumns
            ]);
            break;
        }

        // ============================================================
        // GET CREATE TABLE - full CREATE TABLE statement
        // ============================================================
        case 'get_create_table': {
            $table = $input['table'] ?? '';
            $qt = quoteId($table);
            $stmt = $pdo->query("SHOW CREATE TABLE {$qt}");
            $row = $stmt->fetch(PDO::FETCH_NUM);
            $sql = $row[1] ?? ($row[0] ?? '');
            echo json_encode(['success' => true, 'sql' => $sql]);
            break;
        }

        // ============================================================
        // GET SQL SCRIPTS - everything needed to render the SQL tab:
        // CREATE statement, structural pieces, dependencies (incoming
        // FKs, referencing views, triggers) for ALTER/DROP guidance
        // ============================================================
        case 'get_sql_scripts': {
            $name = $input['table'] ?? '';
            $type = $input['type'] ?? 'table';
            $qt = quoteId($name);

            $stmt = $pdo->query("SHOW CREATE TABLE {$qt}");
            $row = $stmt->fetch(PDO::FETCH_NUM);
            $createSql = $row[1] ?? ($row[0] ?? '');

            if ($type === 'view') {
                $stmt = $pdo->prepare(
                    "SELECT VIEW_DEFINITION, IS_UPDATABLE, CHECK_OPTION, SECURITY_TYPE, DEFINER
                     FROM INFORMATION_SCHEMA.VIEWS
                     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
                );
                $stmt->execute([$database, $name]);
                $viewInfo = $stmt->fetch();

                $stmt = $pdo->query("SHOW COLUMNS FROM {$qt}");
                $viewColumns = $stmt->fetchAll();

                echo json_encode([
                    'success' => true,
                    'type' => 'view',
                    'createSql' => $createSql,
                    'viewInfo' => $viewInfo,
                    'columns' => $viewColumns
                ]);
                break;
            }

            $stmt = $pdo->query("SHOW FULL COLUMNS FROM {$qt}");
            $columns = $stmt->fetchAll();

            $stmt = $pdo->query("SHOW INDEX FROM {$qt}");
            $indexes = $stmt->fetchAll();

            $stmt = $pdo->prepare("
                SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
                       kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                       rc.UPDATE_RULE, rc.DELETE_RULE
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                    AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ?
                  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ");
            $stmt->execute([$database, $name]);
            $foreignKeys = $stmt->fetchAll();

            $stmt = $pdo->prepare("
                SELECT kcu.CONSTRAINT_NAME, kcu.TABLE_NAME AS SOURCE_TABLE,
                       kcu.COLUMN_NAME AS SOURCE_COLUMN, kcu.REFERENCED_COLUMN_NAME,
                       rc.UPDATE_RULE, rc.DELETE_RULE
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                    AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                WHERE kcu.TABLE_SCHEMA = ? AND kcu.REFERENCED_TABLE_NAME = ?
            ");
            $stmt->execute([$database, $name]);
            $incomingFks = $stmt->fetchAll();

            $stmt = $pdo->prepare(
                "SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT,
                        ACTION_ORIENTATION
                 FROM INFORMATION_SCHEMA.TRIGGERS
                 WHERE EVENT_OBJECT_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?"
            );
            $stmt->execute([$database, $name]);
            $triggers = $stmt->fetchAll();

            $stmt = $pdo->prepare(
                "SELECT TABLE_NAME, VIEW_DEFINITION FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = ?"
            );
            $stmt->execute([$database]);
            $allViews = $stmt->fetchAll();
            $referencingViews = [];
            foreach ($allViews as $view) {
                $def = $view['VIEW_DEFINITION'] ?? '';
                if (stripos($def, "`{$name}`") !== false ||
                    preg_match('/\b' . preg_quote($name, '/') . '\b/i', $def)) {
                    $referencingViews[] = $view['TABLE_NAME'];
                }
            }

            $stmt = $pdo->prepare(
                "SELECT TABLE_COMMENT, ENGINE, TABLE_COLLATION, AUTO_INCREMENT
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
            );
            $stmt->execute([$database, $name]);
            $tableInfo = $stmt->fetch();

            echo json_encode([
                'success' => true,
                'type' => 'table',
                'createSql' => $createSql,
                'columns' => $columns,
                'indexes' => $indexes,
                'foreignKeys' => $foreignKeys,
                'incomingFks' => $incomingFks,
                'triggers' => $triggers,
                'referencingViews' => $referencingViews,
                'tableInfo' => $tableInfo
            ]);
            break;
        }

        // ============================================================
        // GET RELATIONSHIPS - FKs for a specific table (in/out)
        // ============================================================
        case 'get_relationships': {
            $table = $input['table'] ?? '';

            $stmt = $pdo->prepare("
                SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
                       kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                       rc.UPDATE_RULE, rc.DELETE_RULE
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                    AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ?
                  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ");
            $stmt->execute([$database, $table]);
            $outgoing = $stmt->fetchAll();

            $stmt = $pdo->prepare("
                SELECT kcu.CONSTRAINT_NAME, kcu.TABLE_NAME AS SOURCE_TABLE,
                       kcu.COLUMN_NAME AS SOURCE_COLUMN, kcu.REFERENCED_COLUMN_NAME,
                       rc.UPDATE_RULE, rc.DELETE_RULE
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                    AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                WHERE kcu.TABLE_SCHEMA = ? AND kcu.REFERENCED_TABLE_NAME = ?
            ");
            $stmt->execute([$database, $table]);
            $incoming = $stmt->fetchAll();

            echo json_encode(['success' => true, 'outgoing' => $outgoing, 'incoming' => $incoming]);
            break;
        }

        // ============================================================
        // GET ALL RELATIONSHIPS - used by the ERD visualization
        // ============================================================
        case 'get_all_relationships': {
            $stmt = $pdo->prepare("
                SELECT kcu.TABLE_NAME, kcu.COLUMN_NAME,
                       kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                       kcu.CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                WHERE kcu.TABLE_SCHEMA = ? AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ");
            $stmt->execute([$database]);
            $relationships = $stmt->fetchAll();

            $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            $tables = [];
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }

            $tableColumns = [];
            foreach ($tables as $t) {
                $s = $pdo->query("SHOW COLUMNS FROM " . quoteId($t));
                $tableColumns[$t] = $s->fetchAll();
            }

            echo json_encode([
                'success' => true,
                'relationships' => $relationships,
                'tables' => $tables,
                'tableColumns' => $tableColumns
            ]);
            break;
        }

        // ============================================================
        // ADD ROW
        // ============================================================
        case 'add_row': {
            $table = $input['table'] ?? '';
            $data = $input['data'] ?? [];
            $qt = quoteId($table);

            $cols = [];
            $placeholders = [];
            $values = [];
            foreach ($data as $col => $val) {
                if ($val === '__SKIP__') continue;
                $cols[] = quoteId($col);
                $placeholders[] = '?';
                $values[] = ($val === '__NULL__') ? null : $val;
            }

            if (empty($cols)) {
                echo json_encode(['success' => false, 'error' => 'לא סופקו נתונים']);
                break;
            }

            $sql = "INSERT INTO {$qt} (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
            echo json_encode(['success' => true, 'insertId' => $pdo->lastInsertId()]);
            break;
        }

        // ============================================================
        // DELETE ROW
        // ============================================================
        case 'delete_row': {
            $table = $input['table'] ?? '';
            $where = $input['where'] ?? [];
            $qt = quoteId($table);

            if (empty($where)) {
                echo json_encode(['success' => false, 'error' => 'לא סופקו תנאים למחיקה']);
                break;
            }

            $conditions = [];
            $values = [];
            foreach ($where as $col => $val) {
                if ($val === null) {
                    $conditions[] = quoteId($col) . " IS NULL";
                } else {
                    $conditions[] = quoteId($col) . " = ?";
                    $values[] = $val;
                }
            }

            $sql = "DELETE FROM {$qt} WHERE " . implode(' AND ', $conditions) . " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
            echo json_encode(['success' => true, 'affectedRows' => $stmt->rowCount()]);
            break;
        }

        // ============================================================
        // ADD COLUMN
        // ============================================================
        case 'add_column': {
            $table = $input['table'] ?? '';
            $colName = $input['column_name'] ?? '';
            $colType = $input['column_type'] ?? 'VARCHAR(255)';
            $nullable = $input['nullable'] ?? true;
            $defaultVal = $input['default_value'] ?? null;
            $comment = $input['comment'] ?? '';
            $afterCol = $input['after_column'] ?? '';
            $qt = quoteId($table);
            $qc = quoteId($colName);

            $sql = "ALTER TABLE {$qt} ADD COLUMN {$qc} {$colType}";
            if (!$nullable) $sql .= " NOT NULL";
            if ($defaultVal !== null && $defaultVal !== '') {
                $sql .= " DEFAULT " . $pdo->quote($defaultVal);
            }
            if ($comment !== '') {
                $sql .= " COMMENT " . $pdo->quote($comment);
            }
            if ($afterCol !== '') {
                $sql .= " AFTER " . quoteId($afterCol);
            }

            $pdo->exec($sql);
            echo json_encode(['success' => true]);
            break;
        }

        // ============================================================
        // DROP COLUMN (with FK check)
        // ============================================================
        case 'drop_column': {
            $table = $input['table'] ?? '';
            $column = $input['column'] ?? '';

            $stmt = $pdo->prepare("
                SELECT kcu.CONSTRAINT_NAME, kcu.TABLE_NAME, kcu.COLUMN_NAME,
                       kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                WHERE kcu.TABLE_SCHEMA = ? AND (
                    (kcu.TABLE_NAME = ? AND kcu.COLUMN_NAME = ? AND kcu.REFERENCED_TABLE_NAME IS NOT NULL)
                    OR (kcu.REFERENCED_TABLE_NAME = ? AND kcu.REFERENCED_COLUMN_NAME = ?)
                )
            ");
            $stmt->execute([$database, $table, $column, $table, $column]);
            $fks = $stmt->fetchAll();

            if (!empty($fks)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'לא ניתן למחוק עמודה זו - קיימים קשרי Foreign Key',
                    'constraints' => $fks
                ]);
                break;
            }

            $pdo->exec("ALTER TABLE " . quoteId($table) . " DROP COLUMN " . quoteId($column));
            echo json_encode(['success' => true]);
            break;
        }

        // ============================================================
        // GET TABLE VIEWS - find views that reference a given table
        // ============================================================
        case 'get_table_views': {
            $table = $input['table'] ?? '';

            $stmt = $pdo->prepare(
                "SELECT TABLE_NAME, VIEW_DEFINITION FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = ?"
            );
            $stmt->execute([$database]);
            $allViews = $stmt->fetchAll();

            $relatedViews = [];
            foreach ($allViews as $view) {
                $def = $view['VIEW_DEFINITION'] ?? '';
                if (stripos($def, "`{$table}`") !== false ||
                    preg_match('/\b' . preg_quote($table, '/') . '\b/i', $def)) {
                    $vn = quoteId($view['TABLE_NAME']);
                    try {
                        $colStmt = $pdo->query("SHOW COLUMNS FROM {$vn}");
                        $viewCols = $colStmt->fetchAll();
                        $relatedViews[] = [
                            'name' => $view['TABLE_NAME'],
                            'columns' => $viewCols
                        ];
                    } catch (Exception $e) {
                        // skip views we can't read
                    }
                }
            }

            echo json_encode(['success' => true, 'views' => $relatedViews]);
            break;
        }

        // ============================================================
        // GET VIEW DATA - query a view with pagination
        // ============================================================
        case 'get_view_data': {
            $view = $input['view'] ?? '';
            $page = max(1, intval($input['page'] ?? 1));
            $limit = max(1, min(500, intval($input['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;
            $qv = quoteId($view);

            $stmt = $pdo->query("SELECT COUNT(*) AS total FROM {$qv}");
            $total = intval($stmt->fetch()['total']);

            $stmt = $pdo->query("SELECT * FROM {$qv} LIMIT {$limit} OFFSET {$offset}");
            $rows = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'rows' => $rows,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => $total > 0 ? ceil($total / $limit) : 1
            ]);
            break;
        }

        // ============================================================
        // GET FULL TABLE DATA - no pagination, used for JSON export
        // ============================================================
        case 'get_full_table_data': {
            $table = $input['table'] ?? '';
            $qt = quoteId($table);
            $stmt = $pdo->query("SELECT * FROM {$qt}");
            $rows = $stmt->fetchAll();
            echo json_encode(['success' => true, 'rows' => $rows]);
            break;
        }

        // ============================================================
        // GET FULL VIEW DATA - no pagination, used for JSON export
        // ============================================================
        case 'get_full_view_data': {
            $view = $input['view'] ?? '';
            $qv = quoteId($view);
            $stmt = $pdo->query("SELECT * FROM {$qv}");
            $rows = $stmt->fetchAll();
            echo json_encode(['success' => true, 'rows' => $rows]);
            break;
        }

        // ============================================================
        // GET DATABASE OVERVIEW - whole-DB summary for the dashboard
        // ============================================================
        case 'get_database_overview': {
            $stmt = $pdo->query("SHOW FULL TABLES");
            $tables = [];
            $views = [];
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                if ($row[1] === 'BASE TABLE') $tables[] = $row[0];
                else $views[] = $row[0];
            }

            $stmt = $pdo->prepare("
                SELECT TABLE_NAME, TABLE_ROWS, ENGINE, TABLE_COLLATION,
                       DATA_LENGTH, INDEX_LENGTH, AUTO_INCREMENT, TABLE_COMMENT
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
            ");
            $stmt->execute([$database]);
            $tableInfo = [];
            foreach ($stmt->fetchAll() as $r) {
                $tableInfo[$r['TABLE_NAME']] = $r;
            }

            $tableColumns = [];
            foreach ($tables as $t) {
                $s = $pdo->query("SHOW COLUMNS FROM " . quoteId($t));
                $tableColumns[$t] = $s->fetchAll();
            }

            $stmt = $pdo->prepare("
                SELECT kcu.TABLE_NAME, kcu.COLUMN_NAME,
                       kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                       kcu.CONSTRAINT_NAME, rc.UPDATE_RULE, rc.DELETE_RULE
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                    AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                WHERE kcu.TABLE_SCHEMA = ? AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ");
            $stmt->execute([$database]);
            $relationships = $stmt->fetchAll();

            $stmt = $pdo->prepare(
                "SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION
                 FROM INFORMATION_SCHEMA.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = ?"
            );
            $stmt->execute([$database]);
            $triggers = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'database' => $database,
                'tables' => $tables,
                'views' => $views,
                'tableInfo' => $tableInfo,
                'tableColumns' => $tableColumns,
                'relationships' => $relationships,
                'triggers' => $triggers
            ]);
            break;
        }

        // ============================================================
        // EXPORT DATABASE - async chunked ZIP export
        //   Step 1: 'export_database' starts the job, returns job ID
        //   Step 2: 'export_status' polls progress
        // ============================================================
        case 'export_database': {
            ini_set('memory_limit', '256M');
            @set_time_limit(600);
            ignore_user_abort(true);

            $mode = $input['mode'] ?? 'both';
            if (!in_array($mode, ['structure', 'data', 'both'], true)) {
                echo json_encode(['success' => false, 'error' => 'mode חייב להיות structure / data / both']);
                break;
            }

            $exportDir = __DIR__ . '/exports';
            if (!is_dir($exportDir)) @mkdir($exportDir, 0755, true);

            // Clean exports older than 1 hour
            foreach (glob($exportDir . '/*.zip') as $old) {
                if (filemtime($old) < time() - 3600) @unlink($old);
            }
            foreach (glob($exportDir . '/*.json') as $old) {
                if (filemtime($old) < time() - 3600) @unlink($old);
            }

            $jobId = uniqid('export_');
            $progressFile = $exportDir . '/' . $jobId . '.json';
            $zipName = $database . '_' . $mode . '_' . date('Ymd_His') . '.zip';
            $zipPath = $exportDir . '/' . $zipName;

            // Get list of tables for progress tracking
            $stmt = $pdo->query("SHOW FULL TABLES");
            $allItems = [];
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $allItems[] = ['name' => $row[0], 'type' => $row[1]];
            }
            $totalItems = count($allItems);

            // Write initial progress and return immediately
            $progress = ['status' => 'working', 'current' => 0, 'total' => $totalItems, 'currentTable' => '', 'jobId' => $jobId];
            @file_put_contents($progressFile, json_encode($progress, JSON_UNESCAPED_UNICODE));

            // Send response now, continue working in background
            echo json_encode(['success' => true, 'jobId' => $jobId, 'total' => $totalItems]);
            header('Content-Length: ' . ob_get_length());
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                ob_end_flush();
                flush();
            }

            // === Background work starts here ===
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                @file_put_contents($progressFile, json_encode(['status' => 'error', 'error' => 'לא ניתן ליצור ZIP'], JSON_UNESCAPED_UNICODE));
                break;
            }

            $chunkSize = 5000;
            $structure = ['database' => $database, 'exportedAt' => date('c'), 'mode' => $mode, 'tables' => [], 'views' => []];
            $tempFiles = [];

            $stmtFK = $pdo->prepare("
                SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
                       kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                       rc.UPDATE_RULE, rc.DELETE_RULE
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                    AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ?
                  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ");
            $stmtTrig = $pdo->prepare("
                SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
                FROM INFORMATION_SCHEMA.TRIGGERS
                WHERE EVENT_OBJECT_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?
            ");
            $stmtInfo = $pdo->prepare(
                "SELECT TABLE_COMMENT, ENGINE, TABLE_COLLATION, AUTO_INCREMENT, TABLE_ROWS, CREATE_TIME, UPDATE_TIME
                 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
            );

            foreach ($allItems as $idx => $item) {
                $name = $item['name'];
                $isView = ($item['type'] !== 'BASE TABLE');
                $qt = quoteId($name);

                // Update progress
                @file_put_contents($progressFile, json_encode([
                    'status' => 'working',
                    'current' => $idx + 1,
                    'total' => $totalItems,
                    'currentTable' => $name
                ], JSON_UNESCAPED_UNICODE));

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

            // Write final progress
            @file_put_contents($progressFile, json_encode([
                'status' => 'done',
                'file' => 'exports/' . $zipName,
                'filename' => $zipName,
                'size' => $fileSize
            ], JSON_UNESCAPED_UNICODE));

            break;
        }

        // ============================================================
        // EXPORT STATUS - poll progress of an export job
        // ============================================================
        case 'export_status': {
            $jobId = $input['jobId'] ?? '';
            if (!$jobId || !preg_match('/^export_[a-f0-9]+$/', $jobId)) {
                echo json_encode(['success' => false, 'error' => 'jobId לא תקין']);
                break;
            }
            $progressFile = __DIR__ . '/exports/' . $jobId . '.json';
            if (!file_exists($progressFile)) {
                echo json_encode(['success' => false, 'error' => 'Job לא נמצא']);
                break;
            }
            $data = json_decode(file_get_contents($progressFile), true);
            echo json_encode(array_merge(['success' => true], $data));
            break;
        }


        // ============================================================
        // GET TRUNCATE PLAN - returns tables in safe truncation order
        //   (children first, parents last) + per-table row counts
        // ============================================================
        case 'get_truncate_plan': {
            $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            $tables = [];
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) $tables[] = $row[0];

            $stmt = $pdo->prepare("
                SELECT TABLE_NAME, REFERENCED_TABLE_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            $stmt->execute([$database]);
            $deps = $stmt->fetchAll();

            // Build dependency graph: parents[child] = [parents...]
            $parents = [];
            foreach ($tables as $t) $parents[$t] = [];
            foreach ($deps as $d) {
                if (in_array($d['TABLE_NAME'], $tables, true) && in_array($d['REFERENCED_TABLE_NAME'], $tables, true)) {
                    if ($d['TABLE_NAME'] !== $d['REFERENCED_TABLE_NAME']) {
                        $parents[$d['TABLE_NAME']][$d['REFERENCED_TABLE_NAME']] = true;
                    }
                }
            }

            // Topological sort (Kahn's): emit parents first; we will reverse for truncate
            $remaining = $parents;
            $order = [];
            while (!empty($remaining)) {
                $emitted = [];
                foreach ($remaining as $t => $ps) {
                    $hasUnresolved = false;
                    foreach ($ps as $p => $_) {
                        if (isset($remaining[$p])) { $hasUnresolved = true; break; }
                    }
                    if (!$hasUnresolved) $emitted[] = $t;
                }
                if (empty($emitted)) {
                    // cycle - emit all remaining as-is (FK_CHECKS=0 will save us)
                    foreach ($remaining as $t => $_) $order[] = $t;
                    break;
                }
                foreach ($emitted as $t) { $order[] = $t; unset($remaining[$t]); }
            }

            // Reverse: children first, parents last (truncate-safe)
            $truncateOrder = array_reverse($order);

            // Row counts
            $rowCounts = [];
            $totalRows = 0;
            foreach ($truncateOrder as $t) {
                try {
                    $s = $pdo->query("SELECT COUNT(*) AS c FROM " . quoteId($t));
                    $c = intval($s->fetch()['c']);
                    $rowCounts[$t] = $c;
                    $totalRows += $c;
                } catch (Exception $e) {
                    $rowCounts[$t] = -1;
                }
            }

            echo json_encode([
                'success' => true,
                'truncateOrder' => $truncateOrder,
                'rowCounts' => $rowCounts,
                'totalRows' => $totalRows,
                'tableCount' => count($truncateOrder)
            ]);
            break;
        }

        // ============================================================
        // TRUNCATE DATABASE - empties all tables (data only, schema stays)
        //   Requires confirmation token: $input['confirm'] === database name
        // ============================================================
        case 'truncate_database': {
            $confirm = $input['confirm'] ?? '';
            if ($confirm !== $database) {
                echo json_encode(['success' => false, 'error' => 'אישור שגוי - יש להזין את שם מסד הנתונים בדיוק']);
                break;
            }

            $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            $tables = [];
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) $tables[] = $row[0];

            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            $truncated = [];
            $errors = [];
            try {
                foreach ($tables as $t) {
                    try {
                        $pdo->exec("TRUNCATE TABLE " . quoteId($t));
                        $truncated[] = $t;
                    } catch (Exception $e) {
                        $errors[$t] = $e->getMessage();
                    }
                }
            } finally {
                $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            }

            echo json_encode([
                'success' => empty($errors),
                'truncated' => $truncated,
                'errors' => $errors,
                'tableCount' => count($truncated)
            ]);
            break;
        }

        // ============================================================
        // CLONE DATABASE - copies all data from current DB to target DB
        //   Both DBs must exist on the same MySQL server with same creds
        // ============================================================
        case 'clone_database': {
            $target = trim($input['target'] ?? '');
            if ($target === '') {
                echo json_encode(['success' => false, 'error' => 'לא הוזן שם מסד נתונים יעד']);
                break;
            }
            if ($target === $database) {
                echo json_encode(['success' => false, 'error' => 'מסד היעד זהה למסד המקור']);
                break;
            }

            $qTarget = quoteId($target);
            $qSource = quoteId($database);

            // Verify target DB exists and is accessible
            try {
                $check = $pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
                $check->execute([$target]);
                if (!$check->fetch()) {
                    echo json_encode(['success' => false, 'error' => "מסד היעד '{$target}' לא קיים"]);
                    break;
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'אין הרשאה לבדוק את מסד היעד: ' . $e->getMessage()]);
                break;
            }

            // List source tables
            $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            $sourceTables = [];
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) $sourceTables[] = $row[0];

            // Topological order (parents first - so FKs are satisfiable as we copy)
            $stmt = $pdo->prepare("
                SELECT TABLE_NAME, REFERENCED_TABLE_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            $stmt->execute([$database]);
            $deps = $stmt->fetchAll();

            $parents = [];
            foreach ($sourceTables as $t) $parents[$t] = [];
            foreach ($deps as $d) {
                if (in_array($d['TABLE_NAME'], $sourceTables, true) && in_array($d['REFERENCED_TABLE_NAME'], $sourceTables, true)) {
                    if ($d['TABLE_NAME'] !== $d['REFERENCED_TABLE_NAME']) {
                        $parents[$d['TABLE_NAME']][$d['REFERENCED_TABLE_NAME']] = true;
                    }
                }
            }
            $remaining = $parents;
            $order = [];
            while (!empty($remaining)) {
                $emitted = [];
                foreach ($remaining as $t => $ps) {
                    $hasUnresolved = false;
                    foreach ($ps as $p => $_) {
                        if (isset($remaining[$p])) { $hasUnresolved = true; break; }
                    }
                    if (!$hasUnresolved) $emitted[] = $t;
                }
                if (empty($emitted)) {
                    foreach ($remaining as $t => $_) $order[] = $t;
                    break;
                }
                foreach ($emitted as $t) { $order[] = $t; unset($remaining[$t]); }
            }

            // Verify target tables exist
            $missing = [];
            foreach ($order as $t) {
                try {
                    $pdo->query("SELECT 1 FROM {$qTarget}." . quoteId($t) . " LIMIT 0");
                } catch (Exception $e) {
                    $missing[] = $t;
                }
            }
            if (!empty($missing)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'במסד היעד חסרות הטבלאות הבאות: ' . implode(', ', $missing),
                    'missing' => $missing
                ]);
                break;
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            $copied = [];
            $errors = [];
            try {
                foreach ($order as $t) {
                    $qt = quoteId($t);
                    try {
                        $pdo->exec("INSERT INTO {$qTarget}.{$qt} SELECT * FROM {$qSource}.{$qt}");
                        $cs = $pdo->query("SELECT COUNT(*) AS c FROM {$qTarget}.{$qt}");
                        $copied[$t] = intval($cs->fetch()['c']);
                    } catch (Exception $e) {
                        $errors[$t] = $e->getMessage();
                    }
                }
            } finally {
                $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            }

            echo json_encode([
                'success' => empty($errors),
                'copied' => $copied,
                'errors' => $errors,
                'target' => $target,
                'source' => $database
            ]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'פעולה לא מוכרת: ' . $action]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    $_logDetail .= ' | PDO: ' . $e->getMessage();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    $_logDetail .= ' | ' . $e->getMessage();
}
