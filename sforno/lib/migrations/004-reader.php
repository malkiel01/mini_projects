<?php
/**
 * מיגרציה 004 — הקורא, הסידור והתהילים (אפיון 04). רק CREATE ו-ADD COLUMN — אין כאן מחיקה ואין שינוי של נתונים קיימים.
 *
 *   siddur_items  — רשימת הבסיס של הסידור והקיצורים (§2), של המנהל. פריט שהוסר — available = 0 (לא נמחק), כדי שהסדר
 *                   האישי של קורא יסמן אותו "לא זמין" ולא יאבד אותו.
 *   custom_checks — "בדיקת מנהגים" (§2, §21.4): כל אישור של שילוב מנהגים מול הטקסט שנראה — שורה חדשה, עם הגרסה והתאריך.
 *                   רק מתווספות (טריגר) — זו ראיית הקבלה.
 *   editions.pages_import_id — הייבוא שהעמודים שלו (PDF / תמונה) מוצגים לקוראים כ"עמודים" (03 §2: PDF סרוק — עמודים בלבד).
 */

declare(strict_types=1);

return function (PDO $db, string $driver): void {
    $t = sf_ddl_types($driver);
    ['id' => $id, 'ref' => $ref, 'int' => $int, 'key' => $key, 'text' => $text, 'ts' => $ts, 'tail' => $tail] = $t;

    // kind: prayer (תפילה ברשימה) · shortcut (קיצור למקום בתוך תפילה). sid — היעד במהדורה; NULL = תחילת המהדורה
    $db->exec("CREATE TABLE siddur_items (
        id $id,
        pid $key NOT NULL UNIQUE,
        edition_id $ref NOT NULL,
        name $text NOT NULL,
        sid $key NULL,
        kind $key NOT NULL,
        ord $int NOT NULL,
        available $int NOT NULL DEFAULT 1,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL,
        FOREIGN KEY (edition_id) REFERENCES editions (id)
    )$tail");
    $db->exec('CREATE INDEX siddur_items_edition ON siddur_items (edition_id, ord)');

    // combo — השילוב בצורה קנונית: "grp=rule;grp=rule" ממוין לפי הקבוצה
    $db->exec("CREATE TABLE custom_checks (
        id $id,
        edition_id $ref NOT NULL,
        combo $key NOT NULL,
        revision_id $ref NOT NULL,
        approved_by $ref NULL,
        approved_at $ts NOT NULL,
        FOREIGN KEY (edition_id) REFERENCES editions (id),
        FOREIGN KEY (revision_id) REFERENCES revisions (id)
    )$tail");
    $db->exec('CREATE INDEX custom_checks_edition ON custom_checks (edition_id, combo)');

    $db->exec("ALTER TABLE editions ADD COLUMN pages_import_id $ref NULL");

    if ($driver === 'sqlite') {
        $db->exec("CREATE TRIGGER custom_checks_no_delete BEFORE DELETE ON custom_checks BEGIN SELECT RAISE(ABORT, 'custom checks are append-only'); END");
        $db->exec("CREATE TRIGGER custom_checks_no_update BEFORE UPDATE ON custom_checks BEGIN SELECT RAISE(ABORT, 'custom checks are append-only'); END");
    } else {
        $db->exec("CREATE TRIGGER custom_checks_no_delete BEFORE DELETE ON custom_checks FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'custom checks are append-only'");
        $db->exec("CREATE TRIGGER custom_checks_no_update BEFORE UPDATE ON custom_checks FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'custom checks are append-only'");
    }
};
