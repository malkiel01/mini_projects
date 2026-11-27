<?php
/**
 * מיגרציה 005 — קוראים רשומים, מכשירים ומידע אישי מסונכרן (אפיון 05 §3). רק CREATE — אין כאן מחיקה ואין שינוי של
 * נתונים קיימים. **עולם נפרד מ-staff:** טבלאות משלו, עוגייה משלו (sf_reader), ואין מפתח זר בין השניים.
 *
 *   readers          — החשבון. סיסמה — רק password_hash; google_sub — אחרי אימות ID token. sync_rev — מונה הסנכרון
 *                      של הקורא (כל שינוי בשרת מקבל את הערך הבא, §4: "לפי rev של השרת, לא לפי שעון המכשיר")
 *   devices          — מכשיר = כניסה אחת. revoked_at — בוטל (ה-session שלו נמחק, הסנכרון נעצר)
 *   reader_sessions  — כמו staff_sessions: במסד רק ה-hash של האסימון
 *   reader_tokens    — קישורי אימות מייל ואיפוס סיסמה: חד-פעמיים, רק ה-hash
 *   personal_items   — פריט מידע אישי. pid נוצר במכשיר, ייחודי לקורא. data — JSON; בהערות ובשמות לתפילה — מוצפן
 *                      (sodium, data/secret.key). מחיקה = deleted_at (tombstone), לא מחיקת שורה
 *   login_attempts   — הגבלת ניסיונות: hash (HMAC) של המייל / ה-IP בלבד, לא הערך עצמו. שורות של יותר מיום — מתנקות
 */

declare(strict_types=1);

return function (PDO $db, string $driver): void {
    $t = sf_ddl_types($driver);
    ['id' => $id, 'ref' => $ref, 'int' => $int, 'key' => $key, 'text' => $text, 'ts' => $ts, 'long' => $long, 'tail' => $tail] = $t;

    $db->exec("CREATE TABLE readers (
        id $id,
        pid $key NOT NULL UNIQUE,
        email $key NOT NULL UNIQUE,
        email_verified_at $ts NULL,
        password_hash $text NULL,
        google_sub $key NULL UNIQUE,
        name $text NOT NULL,
        status $key NOT NULL DEFAULT 'active',
        failed_logins $int NOT NULL DEFAULT 0,
        locked_until $ts NULL,
        sync_rev $int NOT NULL DEFAULT 0,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL,
        deleted_at $ts NULL
    )$tail");

    $db->exec("CREATE TABLE devices (
        id $id,
        pid $key NOT NULL UNIQUE,
        reader_id $ref NOT NULL,
        label $text NOT NULL,
        created_at $ts NOT NULL,
        last_seen_at $ts NOT NULL,
        revoked_at $ts NULL,
        FOREIGN KEY (reader_id) REFERENCES readers (id)
    )$tail");
    $db->exec('CREATE INDEX devices_reader ON devices (reader_id)');

    $db->exec("CREATE TABLE reader_sessions (
        id $id,
        reader_id $ref NOT NULL,
        device_id $ref NOT NULL,
        token_hash $key NOT NULL UNIQUE,
        csrf $key NOT NULL,
        ua $text NULL,
        created_at $ts NOT NULL,
        last_used_at $ts NOT NULL,
        expires_at $ts NOT NULL,
        FOREIGN KEY (reader_id) REFERENCES readers (id),
        FOREIGN KEY (device_id) REFERENCES devices (id)
    )$tail");
    $db->exec('CREATE INDEX reader_sessions_reader ON reader_sessions (reader_id)');

    // kind: verify (24 שעות) · reset (שעה)
    $db->exec("CREATE TABLE reader_tokens (
        id $id,
        reader_id $ref NOT NULL,
        kind $key NOT NULL,
        token_hash $key NOT NULL UNIQUE,
        expires_at $ts NOT NULL,
        used_at $ts NULL,
        created_at $ts NOT NULL,
        FOREIGN KEY (reader_id) REFERENCES readers (id)
    )$tail");

    $db->exec("CREATE TABLE personal_items (
        id $id,
        pid $key NOT NULL,
        reader_id $ref NOT NULL,
        kind $key NOT NULL,
        ref $key NULL,
        data $long NULL,
        rev $int NOT NULL,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL,
        deleted_at $ts NULL,
        device_id $ref NULL,
        FOREIGN KEY (reader_id) REFERENCES readers (id)
    )$tail");
    $db->exec('CREATE UNIQUE INDEX personal_items_pid ON personal_items (reader_id, pid)');
    $db->exec('CREATE INDEX personal_items_rev ON personal_items (reader_id, rev)');

    // scope: login (מייל) · ip · mail (שליחות למייל) · register (IP)
    $db->exec("CREATE TABLE login_attempts (
        id $id,
        scope $key NOT NULL,
        key_hash $key NOT NULL,
        at $ts NOT NULL
    )$tail");
    $db->exec('CREATE INDEX login_attempts_key ON login_attempts (scope, key_hash, at)');
    $db->exec('CREATE INDEX login_attempts_at ON login_attempts (at)');
};
