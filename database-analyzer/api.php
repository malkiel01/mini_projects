<?php
ini_set('display_errors', 0);
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '120');
@set_time_limit(120);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

function success($data = []) {
    $json = json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'JSON encode error: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo $json;
    exit;
}

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function getPdo($input) {
    $host = $input['host'] ?? 'localhost';
    $port = intval($input['port'] ?? 3306);
    $db   = $input['database'] ?? '';
    $user = $input['username'] ?? '';
    $pass = $input['password'] ?? '';
    if (!$db) fail('חסר שם מסד נתונים');
    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
            $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        return $pdo;
    } catch (PDOException $e) {
        fail('שגיאת חיבור: ' . $e->getMessage());
    }
}

function qi($name) {
    return '`' . str_replace('`', '``', $name) . '`';
}

function getDbName($input) {
    return $input['database'] ?? '';
}

function getColumns($pdo, $db, $table) {
    $stmt = $pdo->prepare("SELECT COLUMN_NAME AS `Field`, COLUMN_TYPE AS `Type`, IS_NULLABLE AS `Null`,
        COLUMN_KEY AS `Key`, COLUMN_DEFAULT AS `Default`, EXTRA AS `Extra`,
        COLLATION_NAME AS `Collation`, COLUMN_COMMENT AS `Comment`
        FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
    $stmt->execute([$db, $table]);
    return $stmt->fetchAll();
}

function getIndexes($pdo, $table) {
    try {
        return $pdo->query("SHOW INDEX FROM " . qi($table))->fetchAll();
    } catch (Exception $e) { return []; }
}

function getForeignKeys($pdo, $db, $table) {
    $stmt = $pdo->prepare("SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
        kcu.CONSTRAINT_NAME, rc.UPDATE_RULE, rc.DELETE_RULE
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
        JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
        WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ? AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY kcu.ORDINAL_POSITION");
    $stmt->execute([$db, $table]);
    return $stmt->fetchAll();
}

function getIncomingFks($pdo, $db, $table) {
    $stmt = $pdo->prepare("SELECT kcu.TABLE_NAME AS SOURCE_TABLE, kcu.COLUMN_NAME AS SOURCE_COLUMN,
        kcu.REFERENCED_COLUMN_NAME, kcu.CONSTRAINT_NAME, rc.UPDATE_RULE, rc.DELETE_RULE
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
        JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
        WHERE kcu.TABLE_SCHEMA = ? AND kcu.REFERENCED_TABLE_NAME = ?
        ORDER BY kcu.ORDINAL_POSITION");
    $stmt->execute([$db, $table]);
    return $stmt->fetchAll();
}

function getTriggers($pdo, $db, $table) {
    $stmt = $pdo->prepare("SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
        FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?");
    $stmt->execute([$db, $table]);
    return $stmt->fetchAll();
}

function getTableInfo($pdo, $db, $table) {
    $stmt = $pdo->prepare("SELECT ENGINE, TABLE_COLLATION, TABLE_ROWS, AUTO_INCREMENT,
        TABLE_COMMENT, CREATE_TIME, DATA_LENGTH, INDEX_LENGTH
        FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
    $stmt->execute([$db, $table]);
    return $stmt->fetch() ?: [];
}

function getPrimaryKeys($pdo, $db, $table) {
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION");
    $stmt->execute([$db, $table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function getReferencingViews($pdo, $db, $table) {
    $stmt = $pdo->prepare("SELECT TABLE_NAME, VIEW_DEFINITION FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = ?");
    $stmt->execute([$db]);
    $views = $stmt->fetchAll();
    $result = [];
    foreach ($views as $v) {
        if (stripos($v['VIEW_DEFINITION'], $table) !== false && $v['TABLE_NAME'] !== $table) {
            $result[] = $v['TABLE_NAME'];
        }
    }
    return $result;
}

function sanitizeRows($rows) {
    foreach ($rows as &$row) {
        foreach ($row as $key => &$val) {
            if (is_string($val) && !mb_check_encoding($val, 'UTF-8')) {
                $val = base64_encode($val);
            }
        }
    }
    return $rows;
}

try {
    switch ($action) {

        case 'ping': {
            success(['pong' => true, 'php' => PHP_VERSION, 'memory_limit' => ini_get('memory_limit'), 'time' => date('c')]);
            break;
        }

        case 'connect': {
            $pdo = getPdo($input);
            $rows = $pdo->query("SHOW FULL TABLES")->fetchAll();
            $tables = [];
            $views = [];
            foreach ($rows as $r) {
                $keys = array_keys($r);
                $name = $r[$keys[0]];
                $type = $r[$keys[1]] ?? 'BASE TABLE';
                if ($type === 'VIEW') {
                    $views[] = ['name' => $name, 'type' => 'view'];
                } else {
                    $tables[] = ['name' => $name, 'type' => 'table'];
                }
            }
            success(['tables' => $tables, 'views' => $views]);
        }

        case 'get_database_overview': {
            $pdo = getPdo($input);
            $db = getDbName($input);

            $rows = $pdo->query("SHOW FULL TABLES")->fetchAll();
            $tables = [];
            $views = [];
            foreach ($rows as $r) {
                $keys = array_keys($r);
                $name = $r[$keys[0]];
                $type = $r[$keys[1]] ?? 'BASE TABLE';
                if ($type === 'VIEW') $views[] = ['name' => $name];
                else $tables[] = ['name' => $name];
            }

            $stmt = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL");
            $stmt->execute([$db]);
            $relationships = $stmt->fetchAll();

            $stmt = $pdo->prepare("SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION
                FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = ?");
            $stmt->execute([$db]);
            $triggers = $stmt->fetchAll();

            $stmt = $pdo->prepare("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_ROWS, AUTO_INCREMENT,
                TABLE_COMMENT, CREATE_TIME, DATA_LENGTH, INDEX_LENGTH
                FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'");
            $stmt->execute([$db]);
            $tableInfoRows = $stmt->fetchAll();
            $tableInfo = [];
            $tableColumns = [];
            foreach ($tableInfoRows as $ti) {
                $tableInfo[$ti['TABLE_NAME']] = $ti;
            }

            $stmt = $pdo->prepare("SELECT TABLE_NAME, COUNT(*) AS col_count
                FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? GROUP BY TABLE_NAME");
            $stmt->execute([$db]);
            foreach ($stmt->fetchAll() as $row) {
                $tableColumns[$row['TABLE_NAME']] = intval($row['col_count']);
            }

            success([
                'tables' => $tables,
                'views' => $views,
                'relationships' => $relationships,
                'triggers' => $triggers,
                'tableInfo' => $tableInfo,
                'tableColumns' => $tableColumns,
            ]);
        }

        case 'get_table_structure': {
            $pdo = getPdo($input);
            $db = getDbName($input);
            $table = $input['table'] ?? '';
            if (!$table) fail('חסר שם טבלה');

            success([
                'columns' => getColumns($pdo, $db, $table),
                'indexes' => getIndexes($pdo, $table),
                'foreignKeys' => getForeignKeys($pdo, $db, $table),
                'triggers' => getTriggers($pdo, $db, $table),
                'tableInfo' => getTableInfo($pdo, $db, $table),
            ]);
        }

        case 'get_table_data': {
            $pdo = getPdo($input);
            $db = getDbName($input);
            $table = $input['table'] ?? '';
            if (!$table) fail('חסר שם טבלה');

            $page = max(1, intval($input['page'] ?? 1));
            $limit = min(500, max(1, intval($input['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;

            $total = $pdo->query("SELECT COUNT(*) FROM " . qi($table))->fetchColumn();
            $rows = $pdo->query("SELECT * FROM " . qi($table) . " LIMIT {$limit} OFFSET {$offset}")->fetchAll();
            $pk = getPrimaryKeys($pdo, $db, $table);

            success(['rows' => sanitizeRows($rows), 'total' => intval($total), 'primaryKeys' => $pk]);
            break;
        }

        case 'get_view_data': {
            $pdo = getPdo($input);
            $view = $input['view'] ?? '';
            if (!$view) fail('חסר שם תצוגה');

            $page = max(1, intval($input['page'] ?? 1));
            $limit = min(500, max(1, intval($input['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;

            $total = $pdo->query("SELECT COUNT(*) FROM " . qi($view))->fetchColumn();
            $rows = $pdo->query("SELECT * FROM " . qi($view) . " LIMIT {$limit} OFFSET {$offset}")->fetchAll();

            success(['rows' => sanitizeRows($rows), 'total' => intval($total)]);
            break;
        }

        case 'get_full_table_data': {
            $pdo = getPdo($input);
            $table = $input['table'] ?? '';
            if (!$table) fail('חסר שם טבלה');
            $rows = $pdo->query("SELECT * FROM " . qi($table))->fetchAll();
            success(['rows' => sanitizeRows($rows)]);
            break;
        }

        case 'get_full_view_data': {
            $pdo = getPdo($input);
            $view = $input['view'] ?? '';
            if (!$view) fail('חסר שם תצוגה');
            $rows = $pdo->query("SELECT * FROM " . qi($view))->fetchAll();
            success(['rows' => sanitizeRows($rows)]);
            break;
        }

        case 'get_create_table': {
            $pdo = getPdo($input);
            $table = $input['table'] ?? '';
            if (!$table) fail('חסר שם טבלה');
            $row = $pdo->query("SHOW CREATE TABLE " . qi($table))->fetch();
            $sql = $row['Create Table'] ?? $row['Create View'] ?? '';
            success(['sql' => $sql]);
        }

        case 'get_relationships': {
            $pdo = getPdo($input);
            $db = getDbName($input);
            $table = $input['table'] ?? '';
            if (!$table) fail('חסר שם טבלה');

            success([
                'outgoing' => getForeignKeys($pdo, $db, $table),
                'incoming' => getIncomingFks($pdo, $db, $table),
            ]);
        }

        case 'get_all_relationships': {
            $pdo = getPdo($input);
            $db = getDbName($input);
            $stmt = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL");
            $stmt->execute([$db]);
            success(['relationships' => $stmt->fetchAll()]);
        }

        case 'get_sql_scripts': {
            $pdo = getPdo($input);
            $db = getDbName($input);
            $table = $input['table'] ?? '';
            $type = $input['type'] ?? 'table';
            if (!$table) fail('חסר שם טבלה');

            $createRow = $pdo->query("SHOW CREATE TABLE " . qi($table))->fetch();
            $createSql = $createRow['Create Table'] ?? $createRow['Create View'] ?? '';
            $columns = getColumns($pdo, $db, $table);

            if ($type === 'view') {
                $stmt = $pdo->prepare("SELECT IS_UPDATABLE, CHECK_OPTION, SECURITY_TYPE
                    FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
                $stmt->execute([$db, $table]);
                $viewInfo = $stmt->fetch() ?: [];

                success([
                    'createSql' => $createSql,
                    'columns' => $columns,
                    'viewInfo' => $viewInfo,
                ]);
            } else {
                $indexes = getIndexes($pdo, $table);
                $fks = getForeignKeys($pdo, $db, $table);
                $incomingFks = getIncomingFks($pdo, $db, $table);
                $triggers = getTriggers($pdo, $db, $table);
                $referencingViews = getReferencingViews($pdo, $db, $table);
                $tableInfo = getTableInfo($pdo, $db, $table);

                success([
                    'createSql' => $createSql,
                    'columns' => $columns,
                    'indexes' => $indexes,
                    'foreignKeys' => $fks,
                    'incomingFks' => $incomingFks,
                    'triggers' => $triggers,
                    'referencingViews' => $referencingViews,
                    'tableInfo' => $tableInfo,
                ]);
            }
        }

        case 'get_table_views': {
            $pdo = getPdo($input);
            $db = getDbName($input);
            $table = $input['table'] ?? '';
            if (!$table) fail('חסר שם טבלה');

            $stmt = $pdo->prepare("SELECT TABLE_NAME, VIEW_DEFINITION FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = ?");
            $stmt->execute([$db]);
            $allViews = $stmt->fetchAll();

            $result = [];
            foreach ($allViews as $v) {
                if (stripos($v['VIEW_DEFINITION'], $table) !== false) {
                    $viewName = $v['TABLE_NAME'];
                    $viewCols = getColumns($pdo, $db, $viewName);
                    $result[] = ['name' => $viewName, 'columns' => $viewCols];
                }
            }
            success(['views' => $result]);
        }

        case 'export_database': {
            $pdo = getPdo($input);
            $db = getDbName($input);
            $mode = $input['mode'] ?? 'both';
            $chunkSize = 5000;

            writeLog("export_database ZIP: mode={$mode}, db={$db}");

            $exportDir = __DIR__ . '/exports';
            if (!is_dir($exportDir)) mkdir($exportDir, 0755, true);

            // Clean old exports (older than 1 hour)
            foreach (glob($exportDir . '/*.zip') as $old) {
                if (filemtime($old) < time() - 3600) @unlink($old);
            }

            $zipName = $db . '_' . $mode . '_' . date('Ymd_His') . '.zip';
            $zipPath = $exportDir . '/' . $zipName;

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                fail('לא ניתן ליצור קובץ ZIP');
            }

            $tablesList = $pdo->query("SHOW FULL TABLES")->fetchAll();
            $structure = ['database' => $db, 'exportedAt' => date('c'), 'tables' => []];
            $tempFiles = [];

            foreach ($tablesList as $r) {
                $keys = array_keys($r);
                $name = $r[$keys[0]];
                $type = $r[$keys[1]] ?? 'BASE TABLE';
                $isView = ($type === 'VIEW');

                writeLog("export_database: processing {$name}");

                // Structure
                if ($mode === 'structure' || $mode === 'both') {
                    $tableStruct = [
                        'name' => $name,
                        'type' => $isView ? 'view' : 'table',
                        'columns' => getColumns($pdo, $db, $name),
                    ];
                    if (!$isView) {
                        $tableStruct['indexes'] = getIndexes($pdo, $name);
                        $tableStruct['foreignKeys'] = getForeignKeys($pdo, $db, $name);
                    }
                    try {
                        $createRow = $pdo->query("SHOW CREATE TABLE " . qi($name))->fetch();
                        $tableStruct['createSql'] = $createRow['Create Table'] ?? $createRow['Create View'] ?? '';
                    } catch (Exception $e) {
                        $tableStruct['createSql'] = '';
                    }
                    $structure['tables'][] = $tableStruct;
                }

                // Data - stream in chunks to temp file
                if ($mode === 'data' || $mode === 'both') {
                    try {
                        $count = intval($pdo->query("SELECT COUNT(*) FROM " . qi($name))->fetchColumn());
                        $tmpFile = tempnam(sys_get_temp_dir(), 'dbexp_');
                        $tempFiles[] = $tmpFile;
                        $fp = fopen($tmpFile, 'w');

                        fwrite($fp, '{"table":' . json_encode($name) . ',"type":"' . ($isView ? 'view' : 'table') . '","rowCount":' . $count . ',"rows":[');

                        $firstRow = true;
                        for ($offset = 0; $offset < $count; $offset += $chunkSize) {
                            $chunk = $pdo->query("SELECT * FROM " . qi($name) . " LIMIT {$chunkSize} OFFSET {$offset}")->fetchAll();
                            $chunk = sanitizeRows($chunk);
                            foreach ($chunk as $row) {
                                if (!$firstRow) fwrite($fp, ',');
                                fwrite($fp, json_encode($row, JSON_UNESCAPED_UNICODE));
                                $firstRow = false;
                            }
                            unset($chunk);
                        }

                        fwrite($fp, ']}');
                        fclose($fp);

                        $zip->addFile($tmpFile, "data/{$name}.json");
                        writeLog("export_database: {$name} done ({$count} rows)");
                    } catch (Exception $e) {
                        writeLog("export_database: FAILED {$name}: " . $e->getMessage());
                        $zip->addFromString("data/{$name}.error.txt", $e->getMessage());
                    }
                }
            }

            // Add structure JSON
            if ($mode === 'structure' || $mode === 'both') {
                $zip->addFromString('structure.json', json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }

            $zip->close();

            // Clean temp files
            foreach ($tempFiles as $tmp) {
                @unlink($tmp);
            }

            $fileSize = filesize($zipPath);
            writeLog("export_database: ZIP created: {$zipName} ({$fileSize} bytes)");

            success([
                'file' => 'exports/' . $zipName,
                'filename' => $zipName,
                'size' => $fileSize
            ]);
            break;
        }

        case 'add_row': {
            $pdo = getPdo($input);
            $table = $input['table'] ?? '';
            $data = $input['data'] ?? [];
            if (!$table) fail('חסר שם טבלה');
            if (empty($data)) fail('חסרים נתונים');

            $cols = [];
            $vals = [];
            $params = [];
            foreach ($data as $col => $val) {
                if ($val === '__SKIP__' || $val === '') continue;
                $cols[] = qi($col);
                if ($val === '__NULL__' || $val === null) {
                    $vals[] = 'NULL';
                } else {
                    $vals[] = '?';
                    $params[] = $val;
                }
            }

            if (empty($cols)) fail('אין נתונים להוספה');

            $sql = "INSERT INTO " . qi($table) . " (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            success(['insertId' => $pdo->lastInsertId()]);
        }

        case 'delete_row': {
            $pdo = getPdo($input);
            $table = $input['table'] ?? '';
            $where = $input['where'] ?? [];
            if (!$table) fail('חסר שם טבלה');
            if (empty($where)) fail('חסרים תנאי מחיקה');

            $conditions = [];
            $params = [];
            foreach ($where as $col => $val) {
                if ($val === null) {
                    $conditions[] = qi($col) . " IS NULL";
                } else {
                    $conditions[] = qi($col) . " = ?";
                    $params[] = $val;
                }
            }

            $sql = "DELETE FROM " . qi($table) . " WHERE " . implode(' AND ', $conditions) . " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            success(['affectedRows' => $stmt->rowCount()]);
        }

        case 'add_column': {
            $pdo = getPdo($input);
            $table = $input['table'] ?? '';
            $colName = $input['column_name'] ?? '';
            $colType = $input['column_type'] ?? '';
            $nullable = $input['nullable'] ?? true;
            $defaultValue = $input['default_value'] ?? null;
            $comment = $input['comment'] ?? '';
            $afterColumn = $input['after_column'] ?? '';

            if (!$table) fail('חסר שם טבלה');
            if (!$colName) fail('חסר שם עמודה');
            if (!$colType) fail('חסר סוג עמודה');

            $sql = "ALTER TABLE " . qi($table) . " ADD COLUMN " . qi($colName) . " " . $colType;
            if (!$nullable) $sql .= " NOT NULL";
            if ($defaultValue !== null && $defaultValue !== '') {
                $sql .= " DEFAULT " . $pdo->quote($defaultValue);
            }
            if ($comment) $sql .= " COMMENT " . $pdo->quote($comment);
            if ($afterColumn) $sql .= " AFTER " . qi($afterColumn);

            $pdo->exec($sql);
            success([]);
        }

        case 'drop_column': {
            $pdo = getPdo($input);
            $db = getDbName($input);
            $table = $input['table'] ?? '';
            $column = $input['column'] ?? '';
            if (!$table || !$column) fail('חסר שם טבלה או עמודה');

            // Check FK references
            $stmt = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?");
            $stmt->execute([$db, $table, $column]);
            $refs = $stmt->fetchAll();

            if (!empty($refs)) {
                $details = array_map(function($r) {
                    return $r['TABLE_NAME'] . '.' . $r['COLUMN_NAME'] . ' (' . $r['CONSTRAINT_NAME'] . ')';
                }, $refs);
                fail("לא ניתן למחוק את העמודה - קיימים הקשרים:\n" . implode("\n", $details));
            }

            $stmt = $pdo->prepare("SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL");
            $stmt->execute([$db, $table, $column]);
            $outRefs = $stmt->fetchAll();

            if (!empty($outRefs)) {
                $details = array_map(function($r) {
                    return $r['CONSTRAINT_NAME'] . ' → ' . $r['REFERENCED_TABLE_NAME'];
                }, $outRefs);
                fail("לא ניתן למחוק - העמודה חלק ממפתח זר:\n" . implode("\n", $details));
            }

            $pdo->exec("ALTER TABLE " . qi($table) . " DROP COLUMN " . qi($column));
            success([]);
        }

        case 'get_truncate_plan': {
            $pdo = getPdo($input);
            $db = getDbName($input);

            $stmt = $pdo->prepare("SELECT TABLE_NAME, TABLE_ROWS
                FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'");
            $stmt->execute([$db]);
            $tables = $stmt->fetchAll();

            $totalRows = 0;
            $tableList = [];
            foreach ($tables as $t) {
                $rows = intval($t['TABLE_ROWS']);
                $totalRows += $rows;
                $tableList[] = ['name' => $t['TABLE_NAME'], 'rows' => $rows];
            }

            success(['tableCount' => count($tables), 'totalRows' => $totalRows, 'tables' => $tableList]);
        }

        case 'truncate_database': {
            $pdo = getPdo($input);
            $db = getDbName($input);
            $confirm = $input['confirm'] ?? '';

            if ($confirm !== $db) fail('שם מסד הנתונים לא תואם');

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            $stmt = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'");
            $stmt->execute([$db]);
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $truncated = [];
            $errors = [];
            foreach ($tables as $t) {
                try {
                    $pdo->exec("TRUNCATE TABLE " . qi($t));
                    $truncated[] = $t;
                } catch (Exception $e) {
                    $errors[$t] = $e->getMessage();
                }
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            success(['truncated' => $truncated, 'errors' => $errors]);
        }

        case 'clone_database': {
            $pdo = getPdo($input);
            $db = getDbName($input);
            $target = $input['target'] ?? '';

            if (!$target) fail('חסר שם יעד');
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $target)) fail('שם מסד נתונים לא חוקי');

            $pdo->exec("CREATE DATABASE IF NOT EXISTS " . qi($target) . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $stmt = $pdo->prepare("SELECT TABLE_NAME, TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?");
            $stmt->execute([$db]);
            $items = $stmt->fetchAll();

            $created = [];
            $errors = [];

            // Clone tables first
            foreach ($items as $item) {
                if ($item['TABLE_TYPE'] !== 'BASE TABLE') continue;
                $name = $item['TABLE_NAME'];
                try {
                    $pdo->exec("CREATE TABLE " . qi($target) . "." . qi($name) . " LIKE " . qi($db) . "." . qi($name));
                    $pdo->exec("INSERT INTO " . qi($target) . "." . qi($name) . " SELECT * FROM " . qi($db) . "." . qi($name));
                    $created[] = $name;
                } catch (Exception $e) {
                    $errors[$name] = $e->getMessage();
                }
            }

            // Clone views
            foreach ($items as $item) {
                if ($item['TABLE_TYPE'] === 'BASE TABLE') continue;
                $name = $item['TABLE_NAME'];
                try {
                    $row = $pdo->query("SHOW CREATE VIEW " . qi($db) . "." . qi($name))->fetch();
                    $viewSql = $row['Create View'] ?? '';
                    if ($viewSql) {
                        $viewSql = preg_replace('/DEFINER=`[^`]*`@`[^`]*`/', 'DEFINER=CURRENT_USER', $viewSql);
                        $viewSql = str_replace(qi($db) . '.', qi($target) . '.', $viewSql);
                        $pdo->exec("USE " . qi($target));
                        $pdo->exec($viewSql);
                        $pdo->exec("USE " . qi($db));
                        $created[] = $name . ' (VIEW)';
                    }
                } catch (Exception $e) {
                    $errors[$name] = $e->getMessage();
                }
            }

            success(['created' => $created, 'errors' => $errors, 'targetDatabase' => $target]);
        }

        default:
            fail('פעולה לא ידועה: ' . $action, 404);
    }
} catch (PDOException $e) {
    fail('שגיאת מסד נתונים: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}
