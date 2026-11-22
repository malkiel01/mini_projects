<?php
/**
 * מיגרציה 003 — ייבוא (אפיון 03): imports. רק CREATE — אין כאן מחיקה ואין שינוי של נתונים קיימים.
 *
 * ייבוא שייך למהדורה, והתוצאה שלו נכנסת אליה כטיוטה רק כשהמנהל מאשר (import-apply). הקובץ המקורי והתמונות שחולצו —
 * data/sources/<pid>/ (מוחרג בפריסה). status: uploaded · awaiting_text (PDF — מחכה לטקסט מהדפדפן) · queued ·
 * processing · ready · failed · applied.
 */

declare(strict_types=1);

return function (PDO $db, string $driver): void {
    $t = sf_ddl_types($driver);
    ['id' => $id, 'ref' => $ref, 'int' => $int, 'key' => $key, 'text' => $text, 'ts' => $ts, 'long' => $long, 'tail' => $tail] = $t;

    // kind: html · docx · epub · pdf (טקסט) · pdf_scan · image. text_checked — המנהל סימן שהטקסט נבדק מול העמודים (PDF)
    $db->exec("CREATE TABLE imports (
        id $id,
        pid $key NOT NULL UNIQUE,
        edition_id $ref NOT NULL,
        kind $key NOT NULL,
        ext $key NOT NULL,
        file_name $text NULL,
        size $int NOT NULL,
        sha256 $key NOT NULL,
        owner_permission $int NOT NULL DEFAULT 0,
        status $key NOT NULL,
        progress $int NOT NULL DEFAULT 0,
        job_pid $key NULL,
        error $text NULL,
        warnings $long NULL,
        result_html $long NULL,
        pages $int NULL,
        text_checked $int NOT NULL DEFAULT 0,
        revision_id $ref NULL,
        created_by $ref NULL,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL,
        FOREIGN KEY (edition_id) REFERENCES editions (id),
        FOREIGN KEY (revision_id) REFERENCES revisions (id)
    )$tail");
    $db->exec('CREATE INDEX imports_edition ON imports (edition_id)');
};
