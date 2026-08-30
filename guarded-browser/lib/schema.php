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
        posture            TEXT    NOT NULL DEFAULT 'deny_all',
        blocked_types      TEXT    NOT NULL DEFAULT '',
        ad_block           TEXT    NOT NULL DEFAULT '',
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


    // ── קטגוריות תוכן ────────────────────────────────────────────
    // ‏ההיתר לפי *סוג* ולא לפי כתובת. "לחסום קניות" הוא כלל אחד,
    // במקום רשימה של מאות דומיינים שתמיד תהיה חסרה.
    "CREATE TABLE IF NOT EXISTS category_rules (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        category   TEXT    NOT NULL,
        action     TEXT    NOT NULL DEFAULT 'allow',
        created_at TEXT    NOT NULL,
        UNIQUE (user_id, category)
    )",

    // שיוך דומיין לקטגוריה. נזרע מקטלוג מובנה, והמנהל מוסיף משלו.
    // ‏source מפריד בין השניים כדי שעדכון הקטלוג לא ימחק ידניים.
    "CREATE TABLE IF NOT EXISTS domain_categories (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        domain   TEXT    NOT NULL,
        category TEXT    NOT NULL,
        source   TEXT    NOT NULL DEFAULT 'seed',
        UNIQUE (domain, category)
    )",
    "CREATE INDEX IF NOT EXISTS idx_domcat ON domain_categories(domain)",

    // ── פלטפורמות עם היתר מפורט ─────────────────────────────────
    // יוטיוב אינו אתר אחד: דף הבית, החיפוש, ערוץ וסרטון הם דברים
    // שונים לחלוטין מבחינת מה שמותר. שורה לכל משתמש ולכל פלטפורמה.
    //
    // ‏mode: off (חסום) | full (הכול) | restricted (רק המאושרים)
    "CREATE TABLE IF NOT EXISTS platform_rules (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        platform   TEXT    NOT NULL,
        mode       TEXT    NOT NULL DEFAULT 'off',
        allow_search  INTEGER NOT NULL DEFAULT 0,
        allow_shorts  INTEGER NOT NULL DEFAULT 0,
        created_at TEXT    NOT NULL,
        UNIQUE (user_id, platform)
    )",

    // הפריטים שאושרו בתוך פלטפורמה: ערוץ, סרטון או פלייליסט.
    "CREATE TABLE IF NOT EXISTS platform_items (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        platform    TEXT    NOT NULL,
        kind        TEXT    NOT NULL,
        item_id     TEXT    NOT NULL,
        label       TEXT    NOT NULL DEFAULT '',
        action      TEXT    NOT NULL DEFAULT 'allow',
        created_at  TEXT    NOT NULL,
        UNIQUE (user_id, platform, kind, item_id)
    )",
    "CREATE INDEX IF NOT EXISTS idx_platitems ON platform_items(user_id, platform)",

    // מטמון סרטון→ערוץ.
    //
    // מכתובת של סרטון אי אפשר לדעת לאיזה ערוץ הוא שייך; צריך לשאול את
    // יוטיוב. שאלה אחת לכל סרטון, והתשובה נשמרת — אחרת כל צפייה הייתה
    // פנייה נוספת, והמשתמש היה מחכה.
    "CREATE TABLE IF NOT EXISTS video_owner (
        platform   TEXT NOT NULL,
        video_id   TEXT NOT NULL,
        channel_id TEXT NOT NULL DEFAULT '',
        handle     TEXT NOT NULL DEFAULT '',
        title      TEXT NOT NULL DEFAULT '',
        fetched_at TEXT NOT NULL,
        PRIMARY KEY (platform, video_id)
    )",


    // ── התרעות ──────────────────────────────────────────────────
    // כשהאכיפה במכשיר נכשלת, מישהו חייב לדעת. שורה כאן היא אירוע
    // שהמנהל צריך לראות, ולא רק שורה נוספת ביומן שאיש לא קורא.
    "CREATE TABLE IF NOT EXISTS alerts (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER NOT NULL,
        at         TEXT    NOT NULL,
        kind       TEXT    NOT NULL,
        severity   TEXT    NOT NULL DEFAULT 'warn',
        title      TEXT    NOT NULL,
        detail     TEXT    NOT NULL DEFAULT '',
        url        TEXT    NOT NULL DEFAULT '',
        acked_at   TEXT    NOT NULL DEFAULT ''
    )",
    "CREATE INDEX IF NOT EXISTS idx_alerts_open ON alerts(acked_at, id DESC)",

    ];
}
