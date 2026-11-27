<?php
/**
 * מיגרציה 007 — תזכורות (אפיון 08). רק CREATE — אין כאן מחיקה ואין שינוי של נתונים קיימים.
 * **ההגדרות עצמן לא כאן:** תזכורת של קורא — personal_items (kind = reminder, 05); של אורח — מוצפנת ב-push_devices.guest_data.
 *
 *   push_devices          — מנוי Web Push של דפדפן אחד. קורא: reader_id + device_id (המכשיר של 05 — ביטול שלו עוצר את
 *                           ה-Push). אורח: reader_id NULL, guest_hash = hash של אסימון העוגייה sf_push — **בלי שום פרט
 *                           אישי**. sub — ה-endpoint והמפתחות, מוצפנים (sf_seal); endpoint_hash — לזיהוי כפילות בלבד.
 *                           guest_data — התזכורות, ההעדפות והמיקום של אורח (מוצפן); קורא — NULL. gone_at — הספק אמר 404/410
 *   reminder_occurrences  — מופע אחד לשליחה (§5): owner ('r:<id>' / 'g:<id>'), התזכורת (המזהה מהמכשיר), מתי, ערוץ, יעד.
 *                           dedupe_key ייחודי — ניסיון חוזר / תכנון חוזר לא יוצר שני מופעים. **בלי שם ובלי תוכן.**
 *                           status: pending · sending · sent · failed · cancelled · missed (עבר זמנו בלי שליחה)
 *   reminder_send_log     — לוג השליחה (§5): מזהה מופע, ערוץ, תוצאה, קוד HTTP. **בלי שם תזכורת ובלי שם תפילה** (קריטריון 10)
 */

declare(strict_types=1);

return function (PDO $db, string $driver): void {
    $t = sf_ddl_types($driver);
    ['id' => $id, 'ref' => $ref, 'int' => $int, 'key' => $key, 'text' => $text, 'ts' => $ts, 'long' => $long, 'tail' => $tail] = $t;

    $db->exec("CREATE TABLE push_devices (
        id $id,
        pid $key NOT NULL UNIQUE,
        reader_id $ref NULL,
        device_id $ref NULL,
        guest_hash $key NULL UNIQUE,
        endpoint_hash $key NOT NULL UNIQUE,
        sub $long NOT NULL,
        label $text NOT NULL,
        guest_data $long NULL,
        created_at $ts NOT NULL,
        last_seen_at $ts NOT NULL,
        revoked_at $ts NULL,
        gone_at $ts NULL
    )$tail");
    $db->exec('CREATE INDEX push_devices_reader ON push_devices (reader_id)');

    $db->exec("CREATE TABLE reminder_occurrences (
        id $id,
        owner $key NOT NULL,
        reminder $key NOT NULL,
        fire_at $ts NOT NULL,
        channel $key NOT NULL,
        push_device_id $ref NULL,
        dedupe_key $key NOT NULL UNIQUE,
        status $key NOT NULL DEFAULT 'pending',
        attempts $int NOT NULL DEFAULT 0,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL,
        sent_at $ts NULL
    )$tail");
    $db->exec('CREATE INDEX reminder_occ_due ON reminder_occurrences (status, fire_at)');
    $db->exec('CREATE INDEX reminder_occ_owner ON reminder_occurrences (owner, status, fire_at)');

    $db->exec("CREATE TABLE reminder_send_log (
        id $id,
        occurrence_id $ref NOT NULL,
        channel $key NOT NULL,
        result $key NOT NULL,
        http $int NULL,
        at $ts NOT NULL
    )$tail");
    $db->exec('CREATE INDEX reminder_send_log_at ON reminder_send_log (at)');
};
