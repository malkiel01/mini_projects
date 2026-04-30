<?php
/**
 * Database Analyzer API
 * MySQL database analysis and management endpoints
 */

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!$input || !is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON request']);
    exit;
}

$action = $input['action'] ?? '';

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
        // EXPORT DATABASE - full DB export in one call
        //   mode: 'structure' | 'data' | 'both'
        // ============================================================
        case 'export_database': {
            $mode = $input['mode'] ?? 'both';
            if (!in_array($mode, ['structure', 'data', 'both'], true)) {
                echo json_encode(['success' => false, 'error' => 'mode חייב להיות structure / data / both']);
                break;
            }

            $stmt = $pdo->query("SHOW FULL TABLES");
            $tables = [];
            $views = [];
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                if ($row[1] === 'BASE TABLE') $tables[] = $row[0];
                else $views[] = $row[0];
            }

            $out = [
                'database' => $database,
                'exportedAt' => date('c'),
                'mode' => $mode,
                'tables' => []
            ];

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
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
            );

            foreach ($tables as $t) {
                $qt = quoteId($t);
                $entry = ['name' => $t];

                if ($mode === 'structure' || $mode === 'both') {
                    $cs = $pdo->query("SHOW FULL COLUMNS FROM {$qt}");
                    $entry['columns'] = $cs->fetchAll();

                    $is = $pdo->query("SHOW INDEX FROM {$qt}");
                    $entry['indexes'] = $is->fetchAll();

                    $stmtFK->execute([$database, $t]);
                    $entry['foreignKeys'] = $stmtFK->fetchAll();

                    $stmtTrig->execute([$database, $t]);
                    $entry['triggers'] = $stmtTrig->fetchAll();

                    $stmtInfo->execute([$database, $t]);
                    $entry['tableInfo'] = $stmtInfo->fetch();

                    $createStmt = $pdo->query("SHOW CREATE TABLE {$qt}");
                    $createRow = $createStmt->fetch(PDO::FETCH_NUM);
                    $entry['createStatement'] = $createRow[1] ?? '';
                }

                if ($mode === 'data' || $mode === 'both') {
                    $ds = $pdo->query("SELECT * FROM {$qt}");
                    $entry['data'] = $ds->fetchAll();
                }

                $out['tables'][] = $entry;
            }

            if (!empty($views)) {
                $out['views'] = [];
                foreach ($views as $v) {
                    $qv = quoteId($v);
                    $vEntry = ['name' => $v];
                    if ($mode === 'structure' || $mode === 'both') {
                        $cs = $pdo->query("SHOW FULL COLUMNS FROM {$qv}");
                        $vEntry['columns'] = $cs->fetchAll();
                        $createStmt = $pdo->query("SHOW CREATE VIEW {$qv}");
                        $createRow = $createStmt->fetch(PDO::FETCH_NUM);
                        $vEntry['createStatement'] = $createRow[1] ?? '';
                    }
                    if ($mode === 'data' || $mode === 'both') {
                        try {
                            $ds = $pdo->query("SELECT * FROM {$qv}");
                            $vEntry['data'] = $ds->fetchAll();
                        } catch (Exception $e) {
                            $vEntry['data'] = [];
                            $vEntry['dataError'] = $e->getMessage();
                        }
                    }
                    $out['views'][] = $vEntry;
                }
            }

            echo json_encode(['success' => true, 'export' => $out]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'פעולה לא מוכרת: ' . $action]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
