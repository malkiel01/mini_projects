<?php
/**
 * הסכימה. מוגדרת בקוד ולא בקובץ .sql כדי שהתקנה תהיה פעולה אחת:
 * הקובץ הראשון שנוגע בבסיס הנתונים יוצר אותו אם אינו קיים.
 *
 * ‏SQLite ולא MySQL: זה אחסון משותף, והפריסה היא rsync שאינה נוגעת
 * ב-data/. קובץ אחד ששורד פריסות, בלי הרשאות ובלי שרת נוסף.
 */

declare(strict_types=1);

/**
 * ‏DDL לפי סדר תלות. כל משפט רץ בנפרד כדי ששגיאה תצביע על הטבלה.
 *
 * ‏TEXT לזמנים בפורמט ISO-8601 ב-UTC. SQLite ממיין מחרוזות כאלה נכון,
 * ואין בו טיפוס תאריך אמיתי שהיה חוסך משהו.
 */
function schemaStatements(): array {
    return [

    // ── משתמשים ────────────────────────────────────────────────
    // ‏status הוא שער הכניסה: הרשמה עצמית יוצרת pending, והמנהל מאשר.
    // ההפרדה מ-expires_at מכוונת — השעיה היא החלטה, פקיעה היא תאריך.
    "CREATE TABLE IF NOT EXISTS users (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        username      TEXT    NOT NULL UNIQUE,
        email         TEXT    NOT NULL DEFAULT '',
        display_name  TEXT    NOT NULL DEFAULT '',
        password_hash TEXT    NOT NULL,
        status        TEXT    NOT NULL DEFAULT 'pending',
        is_admin      INTEGER NOT NULL DEFAULT 0,
        expires_at    TEXT    NOT NULL DEFAULT '',
        note          TEXT    NOT NULL DEFAULT '',
        created_at    TEXT    NOT NULL,
        approved_at   TEXT    NOT NULL DEFAULT '',
        last_seen_at  TEXT    NOT NULL DEFAULT ''
    )",

    // ── מדיניות לכל משתמש ──────────────────────────────────────
    // שורה אחת לכל משתמש. מצב הגלישה הוא הרשאה ולא הגדרה גלובלית:
    // למשתמש אחד קיוסק סגור, לאחר שורת כתובת, לשלישי גלישה חופשית.
    //
    // ‏days_mask הוא ביט לכל יום, ראשון=1 ... שבת=64. 127 = כל השבוע.
    // window_start/end הן HH:MM; אם start > end החלון חוצה חצות.
    "CREATE TABLE IF NOT EXISTS policies (
        user_id            INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
        mode               TEXT    NOT NULL DEFAULT 'kiosk',
        timezone           TEXT    NOT NULL DEFAULT 'Asia/Jerusalem',
        days_mask          INTEGER NOT NULL DEFAULT 127,
        window_start       TEXT    NOT NULL DEFAULT '',
        window_end         TEXT    NOT NULL DEFAULT '',
        daily_quota_min    INTEGER NOT NULL DEFAULT 0,
        session_max_min    INTEGER NOT NULL DEFAULT 0,
        max_devices        INTEGER NOT NULL DEFAULT 1,
        allow_downloads    INTEGER NOT NULL DEFAULT 0,
        block_screenshots  INTEGER NOT NULL DEFAULT 0,
        keep_history       INTEGER NOT NULL DEFAULT 1,
        updated_at         TEXT    NOT NULL DEFAULT ''
    )",

    // ── כללי כתובות ────────────────────────────────────────────
    // ‏scope קובע את גבול הניווט לכל כלל בנפרד:
    //   exact       — רק הכתובת המדויקת
    //   domain      — כל הדומיין ותת-הדומיינים
    //   domain_plus — הדומיין לניווט, וכל משאב נלווה מכל מקור
    //
    // ‏action=deny גובר תמיד על allow, בלי קשר לסדר.
    "CREATE TABLE IF NOT EXISTS rules (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        label       TEXT    NOT NULL DEFAULT '',
        pattern     TEXT    NOT NULL,
        scope       TEXT    NOT NULL DEFAULT 'domain',
        action      TEXT    NOT NULL DEFAULT 'allow',
        show_tile   INTEGER NOT NULL DEFAULT 1,
        sort_order  INTEGER NOT NULL DEFAULT 0,
        enabled     INTEGER NOT NULL DEFAULT 1,
        created_at  TEXT    NOT NULL
    )",
    "CREATE INDEX IF NOT EXISTS idx_rules_user ON rules(user_id, enabled)",

    // ── מכשירים וחיבורים ───────────────────────────────────────
    // אסימון לכל מכשיר. ביטול הוא מחיקת שורה — כך "נתק מכשיר" בפאנל
    // מנתק מיד, בלי להמתין לפקיעה.
    "CREATE TABLE IF NOT EXISTS devices (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        token_hash   TEXT    NOT NULL UNIQUE,
        device_name  TEXT    NOT NULL DEFAULT '',
        device_id    TEXT    NOT NULL DEFAULT '',
        created_at   TEXT    NOT NULL,
        last_seen_at TEXT    NOT NULL DEFAULT ''
    )",
    "CREATE INDEX IF NOT EXISTS idx_devices_user ON devices(user_id)",

    // ── מדידת זמן ──────────────────────────────────────────────
    // שורה ליום לכל משתמש. האפליקציה מדווחת פעימות, והשרת צובר.
    "CREATE TABLE IF NOT EXISTS usage (
        user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        day         TEXT    NOT NULL,
        seconds     INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, day)
    )",

    // ── יומן ───────────────────────────────────────────────────
    // כל הכרעה נרשמת. זה מה שהופך את הפאנל מהגדרות לניהול: רואים
    // מה המשתמש ניסה לפתוח ומה נחסם, ולא רק מה הוגדר לו.
    "CREATE TABLE IF NOT EXISTS audit (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER NOT NULL,
        at         TEXT    NOT NULL,
        kind       TEXT    NOT NULL,
        url        TEXT    NOT NULL DEFAULT '',
        allowed    INTEGER NOT NULL DEFAULT 0,
        code       TEXT    NOT NULL DEFAULT '',
        detail     TEXT    NOT NULL DEFAULT ''
    )",
    "CREATE INDEX IF NOT EXISTS idx_audit_user ON audit(user_id, id DESC)",

    ];
}
