<?php
/**
 * מיגרציה 009 — ניהול תפעולי (אפיון 10). רק CREATE — אין כאן מחיקה ואין שינוי של נתונים קיימים.
 *
 *   reports          — דיווח מבחוץ: kind content (טעות בקטע: edition / revision / sid — מזהים בלבד) או contact (טופס קשר).
 *                      body — מה שהמשתמש כתב; contact — "לחזרה אליי", רק אם כתב, מוצפן (sf_seal). **בלי קורא, בלי IP, בלי מייל
 *                      של החשבון.** status: new · working · closed. notified_at — ההתראה למייל התמיכה (אחת לדיווח)
 *   metrics_daily    — מצרפי לפי יום (UTC): kind + key (מזהה בלבד — pid / sid / סוג; לעולם לא טקסט חופשי). נשמר לתמיד
 *   metrics_seen     — "ראינו את המכשיר האנונימי הזה היום" (hash של היום + מזהה שמתחלף כל יום) — לספירת אורחים. יומיים
 *   reader_activity  — פעילות לפי קורא ויום, לתמיכה (§16): כניסות, סנכרונים, שגיאות סנכרון. **ספירות בלבד.** 90 יום
 *   backups          — גיבוי יומי (§19): הקובץ ב-data/backups/, גודל, sha256, ספירות לפי טבלה, בדיקת שחזור. deleted_at — נמחק בשימור
 */

declare(strict_types=1);

return function (PDO $db, string $driver): void {
    ['id' => $id, 'ref' => $ref, 'int' => $int, 'key' => $key, 'text' => $text, 'ts' => $ts, 'long' => $long, 'tail' => $tail] = sf_ddl_types($driver);

    $db->exec("CREATE TABLE reports (
        id $id,
        pid $key NOT NULL UNIQUE,
        kind $key NOT NULL,
        category $key NOT NULL,
        edition_pid $key NULL,
        revision_pid $key NULL,
        sid $key NULL,
        body $text NOT NULL,
        contact $text NULL,
        status $key NOT NULL DEFAULT 'new',
        staff_note $text NULL,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL,
        closed_at $ts NULL,
        notified_at $ts NULL
    )$tail");
    $db->exec('CREATE INDEX reports_status ON reports (status, created_at)');

    $db->exec("CREATE TABLE metrics_daily (
        id $id,
        day $key NOT NULL,
        kind $key NOT NULL,
        mkey $key NOT NULL DEFAULT '',
        n $int NOT NULL DEFAULT 0,
        UNIQUE (day, kind, mkey)
    )$tail");

    $db->exec("CREATE TABLE metrics_seen (
        id $id,
        day $key NOT NULL,
        h $key NOT NULL UNIQUE
    )$tail");
    $db->exec('CREATE INDEX metrics_seen_day ON metrics_seen (day)');

    $db->exec("CREATE TABLE reader_activity (
        id $id,
        reader_id $ref NOT NULL,
        day $key NOT NULL,
        kind $key NOT NULL,
        n $int NOT NULL DEFAULT 0,
        last_at $ts NOT NULL,
        UNIQUE (reader_id, day, kind)
    )$tail");
    $db->exec('CREATE INDEX reader_activity_day ON reader_activity (day)');

    $db->exec("CREATE TABLE backups (
        id $id,
        pid $key NOT NULL UNIQUE,
        file $key NOT NULL UNIQUE,
        kind $key NOT NULL,
        bytes $ref NOT NULL,
        sha256 $key NOT NULL,
        counts $long NOT NULL,
        created_at $ts NOT NULL,
        verified_at $ts NULL,
        verify_ok $int NULL,
        verify_note $text NULL,
        deleted_at $ts NULL
    )$tail");
};
