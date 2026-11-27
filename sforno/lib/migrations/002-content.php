<?php
/**
 * מיגרציה 002 — מנוע התוכן (אפיון 02 §3): titles, editions, revisions, segments, audio_links, search_aliases, customs.
 * רק CREATE ו-INSERT של כללי דוגמה — אין כאן מחיקה ואין שינוי של נתונים קיימים.
 *
 * revisions רק מתווספות: טריגר חוסם מחיקה, וחוסם שינוי תוכן של גרסה שאינה טיוטה (גרסה שפורסמה קפואה).
 * customs — רשימת המנהגים שעליה מצביע sf-cond (04 בונה את הניהול שלה). עד שתגיע הרשימה מהבעלים (📦 §24) — ארבעה
 * כללי דוגמה, מסומנים sample = 1.
 */

declare(strict_types=1);

return function (PDO $db, string $driver): void {
    $t = sf_ddl_types($driver);
    ['id' => $id, 'ref' => $ref, 'int' => $int, 'key' => $key, 'text' => $text, 'ts' => $ts, 'long' => $long, 'tail' => $tail] = $t;

    $db->exec("CREATE TABLE titles (
        id $id,
        pid $key NOT NULL UNIQUE,
        name $text NOT NULL,
        kind $key NOT NULL,
        author $text NULL,
        description $text NULL,
        public_allowed $int NOT NULL DEFAULT 0,
        access $key NOT NULL DEFAULT 'free',
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL,
        deleted_at $ts NULL
    )$tail");

    $db->exec("CREATE TABLE editions (
        id $id,
        pid $key NOT NULL UNIQUE,
        title_id $ref NOT NULL,
        label $text NOT NULL,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL,
        FOREIGN KEY (title_id) REFERENCES titles (id)
    )$tail");
    $db->exec('CREATE INDEX editions_title ON editions (title_id)');

    // status: draft · published · superseded · discarded (טיוטה שבוטלה או הוחלפה בשחזור — נשמרת, לא נמחקת)
    $db->exec("CREATE TABLE revisions (
        id $id,
        pid $key NOT NULL UNIQUE,
        edition_id $ref NOT NULL,
        n $int NOT NULL,
        based_on_revision_id $ref NULL,
        status $key NOT NULL,
        source_html $long NOT NULL,
        source_hash $key NOT NULL,
        draft_v $int NOT NULL DEFAULT 1,
        created_by $ref NULL,
        created_at $ts NOT NULL,
        updated_by $ref NULL,
        updated_at $ts NOT NULL,
        published_by $ref NULL,
        published_at $ts NULL,
        UNIQUE (edition_id, n),
        FOREIGN KEY (edition_id) REFERENCES editions (id),
        FOREIGN KEY (based_on_revision_id) REFERENCES revisions (id)
    )$tail");
    $db->exec('CREATE INDEX revisions_status ON revisions (edition_id, status)');

    $db->exec("CREATE TABLE segments (
        id $id,
        revision_id $ref NOT NULL,
        sid $key NOT NULL,
        kind $key NOT NULL,
        parent_sid $key NULL,
        ord $int NOT NULL,
        title $text NULL,
        text_norm $long NOT NULL,
        UNIQUE (revision_id, sid),
        FOREIGN KEY (revision_id) REFERENCES revisions (id)
    )$tail");
    $db->exec('CREATE INDEX segments_sid ON segments (sid)');

    $db->exec("CREATE TABLE audio_links (
        id $id,
        edition_id $ref NOT NULL,
        sid $key NOT NULL,
        url $text NULL,
        start_ms $int NULL,
        end_ms $int NULL,
        FOREIGN KEY (edition_id) REFERENCES editions (id)
    )$tail");

    $db->exec("CREATE TABLE search_aliases (
        id $id,
        term $key NOT NULL,
        alias $key NOT NULL,
        created_by $ref NULL,
        created_at $ts NOT NULL,
        UNIQUE (term, alias)
    )$tail");

    $db->exec("CREATE TABLE customs (
        id $id,
        rule $key NOT NULL UNIQUE,
        name $text NOT NULL,
        grp $key NOT NULL,
        default_on $int NOT NULL DEFAULT 0,
        available $int NOT NULL DEFAULT 1,
        sample $int NOT NULL DEFAULT 0,
        created_at $ts NOT NULL
    )$tail");
    $now = sf_iso(sf_time());
    $ins = $db->prepare('INSERT INTO customs (rule, name, grp, default_on, available, sample, created_at) VALUES (?, ?, ?, ?, 1, 1, ?)');
    foreach ([['eretz', 'ארץ ישראל', 'place', 1], ['chul', 'חוץ לארץ', 'place', 0], ['yachid', 'יחיד', 'quorum', 1], ['minyan', 'מניין', 'quorum', 0]] as [$r, $n, $g, $d]) {
        $ins->execute([$r, $n, $g, $d, $now]);
    }

    // גרסה רק מתווספת; תוכן של גרסה שאינה טיוטה לא משתנה
    if ($driver === 'sqlite') {
        $db->exec("CREATE TRIGGER revisions_no_delete BEFORE DELETE ON revisions BEGIN SELECT RAISE(ABORT, 'revisions are append-only'); END");
        $db->exec("CREATE TRIGGER revisions_frozen BEFORE UPDATE OF source_html, source_hash, edition_id, n ON revisions WHEN OLD.status <> 'draft'
            BEGIN SELECT RAISE(ABORT, 'a published revision is frozen'); END");
    } else {
        $db->exec("CREATE TRIGGER revisions_no_delete BEFORE DELETE ON revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'revisions are append-only'");
        $db->exec("CREATE TRIGGER revisions_frozen BEFORE UPDATE ON revisions FOR EACH ROW BEGIN
            IF OLD.status <> 'draft' AND (NEW.source_html <> OLD.source_html OR NEW.source_hash <> OLD.source_hash OR NEW.edition_id <> OLD.edition_id OR NEW.n <> OLD.n)
            THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a published revision is frozen'; END IF; END");
    }
};
