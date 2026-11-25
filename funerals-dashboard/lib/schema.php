<?php
declare(strict_types=1);

/**
 * המיגרציות של המסד — מוסיפות בלבד (אפיון 01 §2.1). הגרסה ב-PRAGMA user_version.
 * לפני כל קפיצת גרסה: גיבוי מלא ל-data/backup/ (VACUUM INTO, 0600). נכשל הגיבוי → אין מיגרציה, 503.
 * כל מיגרציה בטרנזקציה אחת ואידמפוטנטית. שום שורה ב-funerals וב-audit לא נמחקת ולא משתנה מעבר ל-org_id.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

const SCHEMA_VERSION = 5;

function backupDir(): string { return dataDir() . '/backup'; }

/** מריץ את מה שחסר. נעילת קובץ — כדי ששתי בקשות בו-זמנית לא יגבו ויעדכנו פעמיים. */
function migrate(PDO $pdo): void {
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() >= SCHEMA_VERSION) return;
    $lock = @fopen(dataDir() . '/.migrate.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) reply(503, ['error' => 'לא ניתן לנעול את המסד לשדרוג. בדוק הרשאות כתיבה ל-data/']);
    try {
        $from = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($from >= SCHEMA_VERSION) return;
        backupDb($pdo, $from);
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            if ($from < 1) migrateV1($pdo);
            if ($from < 2) migrateV2($pdo);
            if ($from < 3) migrateV3($pdo);
            if ($from < 4) migrateV4($pdo);
            if ($from < 5) migrateV5($pdo);
            $pdo->exec('PRAGMA user_version=' . SCHEMA_VERSION);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    } catch (Throwable $e) {
        error_log('funerals-dashboard migrate: ' . $e->getMessage());
        reply(503, ['error' => 'שדרוג המסד נכשל, והנתונים נשארו כמו שהיו. פרטים ביומן השגיאות של השרת.']);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** עותק מלא של המסד לפני קפיצת גרסה. */
function backupDb(PDO $pdo, int $from): void {
    $dir = backupDir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        reply(503, ['error' => 'לא ניתן ליצור את תיקיית הגיבוי data/backup/. המסד לא שודרג — בדוק הרשאות כתיבה.']);
    }
    if (!is_writable($dir)) reply(503, ['error' => 'תיקיית הגיבוי data/backup/ אינה ניתנת לכתיבה. המסד לא שודרג.']);
    $path = $dir . '/funerals-' . gmdate('Ymd-His') . '-v' . $from . '.sqlite';
    if (is_file($path)) $path = substr($path, 0, -7) . '-' . bin2hex(random_bytes(3)) . '.sqlite';
    $old = umask(0077);
    try {
        $pdo->exec('VACUUM INTO ' . $pdo->quote($path));
    } catch (Throwable $e) {
        @unlink($path);
        reply(503, ['error' => 'גיבוי המסד נכשל, ולכן הוא לא שודרג. בדוק מקום פנוי והרשאות ב-data/backup/.']);
    } finally {
        umask($old);
    }
    @chmod($path, 0600);
}

function hasColumn(PDO $pdo, string $table, string $col): bool {
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $c) if ($c['name'] === $col) return true;
    return false;
}

/** גרסה 1: ארגונים, משתמשים, חברים, הזמנות, אירועים, יומן בקשות, סודות; org_id לתיקים. */
function migrateV1(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS orgs (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, created_at TEXT NOT NULL, settings TEXT NOT NULL DEFAULT \'{}\')');
    // platform_admin — רק העותק האחרון של הדגל מהשער (ל-DevLoop: אסימון חי כל עוד יוצרו מפתח). לא תפקיד ולא הרשאה.
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, sub TEXT UNIQUE, email TEXT UNIQUE, name TEXT NOT NULL DEFAULT \'\',
        created_at TEXT NOT NULL, last_seen_at TEXT, active INTEGER NOT NULL DEFAULT 1, platform_admin INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS members (org_id INTEGER NOT NULL, user_id INTEGER NOT NULL, role TEXT NOT NULL CHECK (role IN (\'admin\',\'office\',\'field\')),
        created_at TEXT NOT NULL, created_by INTEGER, PRIMARY KEY (org_id, user_id))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_members_user ON members(user_id)');
    // השבתה היא לארגון (members.active). users.active — חסימה גלובלית, רק בידי מפתח.
    if (!hasColumn($pdo, 'members', 'active')) $pdo->exec('ALTER TABLE members ADD COLUMN active INTEGER NOT NULL DEFAULT 1');
    $pdo->exec('CREATE TABLE IF NOT EXISTS invites (id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER NOT NULL, email TEXT NOT NULL, role TEXT NOT NULL,
        created_by INTEGER, created_at TEXT NOT NULL, used_at TEXT)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invites_email ON invites(email)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER, case_id TEXT, at TEXT NOT NULL, actor_user_id INTEGER,
        actor TEXT NOT NULL, source TEXT NOT NULL, action TEXT NOT NULL, diff TEXT NOT NULL DEFAULT \'{}\')');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_case ON events(case_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_org ON events(org_id, id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS req_log (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, method TEXT NOT NULL, action TEXT NOT NULL,
        status INTEGER NOT NULL, ms INTEGER NOT NULL, user_id INTEGER, error TEXT NOT NULL DEFAULT \'\')');
    $pdo->exec('CREATE TABLE IF NOT EXISTS secrets (name TEXT NOT NULL, org_id INTEGER NOT NULL DEFAULT 0, value_enc TEXT NOT NULL, updated_at TEXT NOT NULL,
        PRIMARY KEY (name, org_id))');
    if (!hasColumn($pdo, 'funerals', 'org_id')) $pdo->exec('ALTER TABLE funerals ADD COLUMN org_id INTEGER');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_funerals_org_month ON funerals(org_id, month)');
    $pdo->prepare('INSERT OR IGNORE INTO orgs(id, name, created_at, settings) VALUES (1, ?, ?, \'{}\')')->execute(['חברה קדישא', gmdate('c')]);
    $pdo->exec('UPDATE funerals SET org_id = 1 WHERE org_id IS NULL');
}

/**
 * גרסה 2 (פרוסה 03, Dropbox): חיבור לארגון, מטמון התיקיות שבתוך הראשית, תיק ↔ תיקייה, וקבצים.
 * מוסיפה טבלאות בלבד; אין בהן סודות (אלה ב-secrets; ה-verifier של OAuth — מוצפן). files ייחודי לפי (org_id, dbx_id):
 * אותו חשבון Dropbox בשני ארגונים לא מערבב קבצים. upload_id — מזהה חד-פעמי של העלאה (חוזרת → אותה שורה).
 */
function migrateV2(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS dbx_orgs (
        org_id INTEGER PRIMARY KEY, status TEXT NOT NULL DEFAULT \'disconnected\', account_id TEXT, account_name TEXT,
        root_id TEXT, root_path TEXT, root_lower TEXT, cursor TEXT, sync_pending INTEGER NOT NULL DEFAULT 0,
        lock_token TEXT, lock_until INTEGER NOT NULL DEFAULT 0, last_sync_at TEXT, last_webhook_at TEXT, last_error TEXT,
        last_error_at TEXT, connected_by TEXT, connected_at TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS dbx_folders (
        org_id INTEGER NOT NULL, dbx_id TEXT NOT NULL, path TEXT NOT NULL, path_lower TEXT NOT NULL, PRIMARY KEY(org_id, dbx_id))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dbx_folders_path ON dbx_folders(org_id, path_lower)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS case_folders (
        org_id INTEGER NOT NULL, case_id TEXT NOT NULL, dbx_folder_id TEXT NOT NULL, dbx_path TEXT NOT NULL,
        linked_at TEXT NOT NULL, linked_by TEXT, PRIMARY KEY(org_id, case_id), UNIQUE(org_id, dbx_folder_id))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS files (
        id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER NOT NULL, case_id TEXT, dbx_id TEXT NOT NULL,
        path TEXT NOT NULL, path_lower TEXT NOT NULL, name TEXT NOT NULL, size INTEGER NOT NULL DEFAULT 0, rev TEXT,
        server_modified TEXT, deleted INTEGER NOT NULL DEFAULT 0, added_via TEXT NOT NULL, added_by TEXT, kind TEXT NOT NULL DEFAULT \'\', upload_id TEXT,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(org_id, dbx_id))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_files_case ON files(org_id, case_id)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_files_upload ON files(org_id, upload_id) WHERE upload_id IS NOT NULL');
    // OAuth: state ממתין בשרת (לא ב-session), לפי hash, קשור ל-sub ולארגון, חד-פעמי, 10 דקות. ה-verifier מוצפן.
    $pdo->exec('CREATE TABLE IF NOT EXISTS dbx_oauth (state_hash TEXT PRIMARY KEY, org_id INTEGER NOT NULL, sub TEXT NOT NULL,
        verifier_enc TEXT NOT NULL, expires_at INTEGER NOT NULL, used_at TEXT)');
}

/**
 * v3 (פרוסה 04 — עובד שטח): field_uploads — מזהה חד-פעמי לכל העלאה מהשטח. status: pending (בשליחה) / done / failed;
 * result — התשובה שהוחזרה, כדי שאותו מזהה יחזיר את אותו קובץ בלי לשלוח שוב.
 */
function migrateV3(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS field_uploads (
        org_id INTEGER NOT NULL, upload_id TEXT NOT NULL, case_id TEXT NOT NULL, kind TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL, result TEXT, error TEXT, user_id INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        PRIMARY KEY(org_id, upload_id))');
}

/**
 * v4 (פרוסה 06 — AI מובנה): הגדרות, מונה, שיחות, הצעות והצעות סוג לקובץ. אידמפוטנטית: בשרת שכבר הריץ את הגרסה
 * הקודמת של 06 (טבלאות ב-CREATE IF NOT EXISTS ועמודות ב-ALTER עצל) — רק העמודות שחסרות נוספות, והנתונים נשארים.
 */
function migrateV4(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_settings (org_id INTEGER PRIMARY KEY, provider TEXT NOT NULL, model TEXT NOT NULL,
        monthly_cap INTEGER NOT NULL, auto_approve INTEGER NOT NULL DEFAULT 0, key_hint TEXT NOT NULL DEFAULT \'\', key_set_at TEXT,
        key_check TEXT NOT NULL DEFAULT \'\', key_check_at TEXT, updated_at TEXT NOT NULL, token_cap INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_usage (org_id INTEGER NOT NULL, month TEXT NOT NULL, chats INTEGER NOT NULL DEFAULT 0,
        tokens INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (org_id, month))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_threads (id TEXT PRIMARY KEY, org_id INTEGER NOT NULL, user_id INTEGER NOT NULL, case_id TEXT,
        title TEXT NOT NULL DEFAULT \'\', messages TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, system TEXT)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_threads_case ON ai_threads(org_id, case_id, updated_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_proposals (id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id TEXT NOT NULL, org_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL, case_id TEXT, tool TEXT NOT NULL, input TEXT NOT NULL, card TEXT NOT NULL, status TEXT NOT NULL DEFAULT \'pending\',
        result TEXT, created_at TEXT NOT NULL, decided_at TEXT, base TEXT)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_proposals_thread ON ai_proposals(thread_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_file_kinds (org_id INTEGER NOT NULL, file_id INTEGER NOT NULL, kind TEXT NOT NULL, reason TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'pending\', created_at TEXT NOT NULL, decided_at TEXT, decided_by INTEGER, PRIMARY KEY (org_id, file_id))');
    foreach ([['ai_settings', 'token_cap', 'INTEGER'], ['ai_usage', 'tokens', 'INTEGER NOT NULL DEFAULT 0'],
        ['ai_threads', 'system', 'TEXT'], ['ai_proposals', 'base', 'TEXT']] as [$t, $c, $type]) {
        if (!hasColumn($pdo, $t, $c)) $pdo->exec("ALTER TABLE $t ADD COLUMN $c $type");
    }
}

/**
 * v5 (פרוסה 11 — הגדרות בדיקות ותפקידים גמישים): check_defs (org_id 0 = הכללית של הבעלים, N = דריסה של ארגון), roles,
 * tasks, case_archive, ו-role_id ב-members וב-invites. מוסיפה בלבד: funerals, audit ו-members.role לא משתנים (role נשאר לתאימות).
 * ממלאת את 9 בדיקות ברירת המחדל ואת שלושת התפקידים המובנים לכל ארגון — אותן הרשאות כמו PERMS שלפני הפרוסה.
 */
function migrateV5(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS check_defs (org_id INTEGER NOT NULL, key TEXT NOT NULL, name TEXT NOT NULL, type TEXT NOT NULL,
        value_kind TEXT NOT NULL DEFAULT \'\', doc_kinds TEXT NOT NULL DEFAULT \'[]\', role TEXT, cond TEXT, critical INTEGER NOT NULL DEFAULT 0,
        position INTEGER NOT NULL DEFAULT 0, removed INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL, updated_by TEXT, PRIMARY KEY (org_id, key))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS roles (id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER NOT NULL, name TEXT NOT NULL, perms TEXT NOT NULL,
        task_types TEXT NOT NULL DEFAULT \'[]\', base TEXT, builtin INTEGER NOT NULL DEFAULT 0, archived INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_roles_org ON roles(org_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER NOT NULL, case_id TEXT NOT NULL, check_key TEXT,
        type TEXT NOT NULL DEFAULT \'\', title TEXT NOT NULL, assignee_user_id INTEGER, assignee_role_id INTEGER, status TEXT NOT NULL DEFAULT \'open\',
        due TEXT, note TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL, created_by INTEGER, done_at TEXT, done_by INTEGER)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_case ON tasks(org_id, case_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_user ON tasks(org_id, assignee_user_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_role ON tasks(org_id, assignee_role_id, status)');
    if (!hasColumn($pdo, 'members', 'role_id')) $pdo->exec('ALTER TABLE members ADD COLUMN role_id INTEGER');
    if (!hasColumn($pdo, 'invites', 'role_id')) $pdo->exec('ALTER TABLE invites ADD COLUMN role_id INTEGER');
    // ארכיון (א13): תיק שכל הבדיקות שלו הושלמו. נשמר — כדי שבדיקה חדשה בהגדרות לא תוציא תיק מהארכיון לבד; restored_at — "החזרה"
    $pdo->exec('CREATE TABLE IF NOT EXISTS case_archive (org_id INTEGER NOT NULL, case_id TEXT NOT NULL, archived_at TEXT NOT NULL, restored_at TEXT,
        PRIMARY KEY (org_id, case_id))');
    checksSeed($pdo);
    foreach ($pdo->query('SELECT id FROM orgs')->fetchAll(PDO::FETCH_COLUMN) as $orgId) rolesSeed($pdo, (int)$orgId);
}
