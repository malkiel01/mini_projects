<?php
/**
 * מיגרציה 001 — התשתית (אפיון 01 §4): staff, staff_sessions, audit_log, jobs. (meta נוצרת לפני כל מיגרציה.)
 * רק CREATE — אין כאן מחיקה ואין שינוי של נתונים קיימים.
 */

declare(strict_types=1);

return function (PDO $db, string $driver): void {
    $t = sf_ddl_types($driver);
    ['id' => $id, 'ref' => $ref, 'int' => $int, 'key' => $key, 'text' => $text, 'ts' => $ts, 'tail' => $tail] = $t;

    $db->exec("CREATE TABLE staff (
        id $id,
        pid $key NOT NULL UNIQUE,
        platform_sub $key NULL UNIQUE,
        email $key NULL UNIQUE,
        email_verified $int NOT NULL DEFAULT 0,
        name $text NOT NULL,
        is_developer $int NOT NULL DEFAULT 0,
        status $key NOT NULL DEFAULT 'active',
        last_seen_at $ts NULL,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL
    )$tail");

    $db->exec("CREATE TABLE staff_sessions (
        id $id,
        staff_id $ref NOT NULL,
        token_hash $key NOT NULL UNIQUE,
        csrf $key NOT NULL,
        platform_sub $key NOT NULL,
        ua $text NULL,
        created_at $ts NOT NULL,
        last_used_at $ts NOT NULL,
        expires_at $ts NOT NULL,
        FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE CASCADE
    )$tail");
    $db->exec('CREATE INDEX staff_sessions_expires ON staff_sessions (expires_at)');

    $db->exec("CREATE TABLE audit_log (
        id $id,
        at $ts NOT NULL,
        actor_staff_id $ref NULL,
        actor_kind $key NOT NULL,
        action $key NOT NULL,
        entity_type $key NULL,
        entity_pid $key NULL,
        meta $text NULL,
        req $key NULL
    )$tail");
    $db->exec('CREATE INDEX audit_log_at ON audit_log (at)');
    $db->exec('CREATE INDEX audit_log_action ON audit_log (action)');

    $db->exec("CREATE TABLE jobs (
        id $id,
        pid $key NOT NULL UNIQUE,
        kind $key NOT NULL,
        payload $text NULL,
        dedupe_key $key NULL UNIQUE,
        status $key NOT NULL DEFAULT 'pending',
        attempts $int NOT NULL DEFAULT 0,
        max_attempts $int NOT NULL DEFAULT 3,
        run_after $ts NOT NULL,
        started_at $ts NULL,
        finished_at $ts NULL,
        last_error $text NULL,
        created_at $ts NOT NULL
    )$tail");
    $db->exec('CREATE INDEX jobs_due ON jobs (status, run_after)');

    // יומן הפעולות — רק מוסיפים (§7). במסד עצמו, לא רק בקוד.
    if ($driver === 'sqlite') {
        $db->exec("CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END");
        $db->exec("CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END");
    } else {
        $db->exec("CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'");
        $db->exec("CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'");
    }
};
