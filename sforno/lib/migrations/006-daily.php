<?php
/**
 * מיגרציה 006 — זמני היום והלכה יומית (אפיון 07). רק CREATE ו-INSERT של שיטת בדיקה אחת — אין כאן מחיקה ואין שינוי של
 * נתונים קיימים. **אין כאן שום מידע של קורא:** המיקום והשיטה שבחר — במכשיר ובסנכרון של 05 (personal_items, pref).
 *
 *   zmanim_methods         — השיטות (§3). status: draft (בעבודה) · approved (הקורא יכול לבחור) · retired (לא מוצעת עוד).
 *                            test = 1 — "לבדיקה בלבד": לא ניתנת לאישור, ולא יוצאת לקוראים (רק למנהלים). is_default — ברירת
 *                            המחדל של המנהל; לכל היותר אחת, ורק מאושרת. **אין ברירת מחדל ← הקורא בוחר לפני שרואה שעות** (§11)
 *   zmanim_method_versions — כל שמירה של הגדרה = גרסה חדשה, רק מתווספות (טריגר). חבילה שהורדה נושאת את הגרסה, כדי לדעת
 *                            שהיא ישנה (§3: "גרסת השיטה")
 *   halacha_days           — הלכה יומית לפי תאריך עברי בלי שנה (חודש 1–13 לפי ש6, יום 1–30), אחת לכל תאריך. draft_html —
 *                            הטיוטה; published_html — מה שהקורא רואה (NULL = לא פורסם). text_norm — אינדקס החיפוש של
 *                            מה שפורסם בלבד (sf_norm של 02). בלי מהדורות (§4)
 */

declare(strict_types=1);

return function (PDO $db, string $driver): void {
    $t = sf_ddl_types($driver);
    ['id' => $id, 'ref' => $ref, 'int' => $int, 'key' => $key, 'text' => $text, 'ts' => $ts, 'long' => $long, 'tail' => $tail] = $t;

    $db->exec("CREATE TABLE zmanim_methods (
        id $id,
        pid $key NOT NULL UNIQUE,
        name $text NOT NULL,
        status $key NOT NULL DEFAULT 'draft',
        test $int NOT NULL DEFAULT 0,
        is_default $int NOT NULL DEFAULT 0,
        version $int NOT NULL,
        note $text NULL,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL,
        updated_by $ref NULL
    )$tail");

    $db->exec("CREATE TABLE zmanim_method_versions (
        id $id,
        method_id $ref NOT NULL,
        version $int NOT NULL,
        def $long NOT NULL,
        created_by $ref NULL,
        created_at $ts NOT NULL,
        UNIQUE (method_id, version),
        FOREIGN KEY (method_id) REFERENCES zmanim_methods (id)
    )$tail");

    $db->exec("CREATE TABLE halacha_days (
        id $id,
        pid $key NOT NULL UNIQUE,
        hmonth $int NOT NULL,
        hday $int NOT NULL,
        draft_html $long NULL,
        published_html $long NULL,
        text_norm $long NULL,
        draft_v $int NOT NULL DEFAULT 1,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL,
        updated_by $ref NULL,
        published_at $ts NULL,
        published_by $ref NULL,
        UNIQUE (hmonth, hday)
    )$tail");

    // שיטת הבדיקה — **לא הלכתית ולא לציבור**: זוויות של לוחות אסטרונומיים (NOAA, timeanddate), כדי לבדוק את החישוב עד
    // שיגיעו השיטות מהבעלים (📦 §24). test = 1 — אי אפשר לאשר אותה
    $now = sf_iso(sf_time());
    $def = json_encode(['day_end' => 'night', 'zmanim' => [
        ['key' => 'dawn', 'name' => 'שחר אסטרונומי (18°)', 'type' => 'angle', 'deg' => 18, 'dir' => 'am'],
        ['key' => 'sunrise', 'name' => 'זריחה', 'type' => 'angle', 'deg' => 0.833, 'dir' => 'am'],
        ['key' => 'third', 'name' => 'רבע היום (3 שעות זמניות)', 'type' => 'hours', 'from' => 'sunrise', 'to' => 'sunset', 'hours' => 3],
        ['key' => 'noon', 'name' => 'אמצע היום', 'type' => 'hours', 'from' => 'sunrise', 'to' => 'sunset', 'hours' => 6],
        ['key' => 'sunset', 'name' => 'שקיעה', 'type' => 'angle', 'deg' => 0.833, 'dir' => 'pm'],
        ['key' => 'night', 'name' => 'לילה (שמש 8.5° מתחת לאופק)', 'type' => 'angle', 'deg' => 8.5, 'dir' => 'pm'],
    ]], JSON_UNESCAPED_UNICODE);
    $pid = sf_pid();
    $db->prepare("INSERT INTO zmanim_methods (pid, name, status, test, is_default, version, note, created_at, updated_at) VALUES (?, ?, 'draft', 1, 0, 1, ?, ?, ?)")
        ->execute([$pid, 'שיטת בדיקה — לבדיקה בלבד', 'לא הלכתית. לבדיקת החישוב עד שיגיעו השיטות המאושרות (§24)', $now, $now]);
    $mid = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO zmanim_method_versions (method_id, version, def, created_at) VALUES (?, 1, ?, ?)')->execute([$mid, $def, $now]);

    if ($driver === 'sqlite') {
        $db->exec("CREATE TRIGGER zmanim_versions_no_delete BEFORE DELETE ON zmanim_method_versions BEGIN SELECT RAISE(ABORT, 'method versions are append-only'); END");
        $db->exec("CREATE TRIGGER zmanim_versions_no_update BEFORE UPDATE ON zmanim_method_versions BEGIN SELECT RAISE(ABORT, 'method versions are append-only'); END");
    } else {
        $db->exec("CREATE TRIGGER zmanim_versions_no_delete BEFORE DELETE ON zmanim_method_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'method versions are append-only'");
        $db->exec("CREATE TRIGGER zmanim_versions_no_update BEFORE UPDATE ON zmanim_method_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'method versions are append-only'");
    }
};
