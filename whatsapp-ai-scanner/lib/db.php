<?php
/**
 * שכבת האחסון — SQLite.
 *
 * ‏SQLite ולא MySQL: אין פרטי התחברות לנהל, והקובץ נוסע עם התיקייה.
 * הכלי הזה מחזיק את הדבר הרגיש ביותר בכל הריפו — היסטוריית ההודעות
 * הפרטית של המשתמשים — ולכן גוף ההודעה נשמר **מוצפן** (ראו crypto.php),
 * ומסד הנתונים כולו יושב ב-data/ שחסומה לגישה ישירה.
 *
 * כמה משתמשים, וכל אחד רואה רק את שלו (הכרעת בעל המערכת, 2026-10-19):
 * טבלת users, ו-user_id על החשבונות ועל היומן. ההודעות והמדיה שייכות
 * לחשבון, ולכן למשתמש של החשבון. כל שאילתה מסננת לפי המשתמש הנוכחי
 * (currentUserId) — שנקבע פעם אחת בבקשה, מהכניסה או מאסימון הגשר.
 *
 * הבחירה לשמור בשרת ולא רק בטלפון נעשתה במפורש: כך אפשר לשאול שאלות
 * מכל מכשיר. המחיר הוא שההתכתבויות יושבות על אחסון משותף, ומכאן
 * ההצפנה במנוחה והמפתח בקובץ נפרד מהמסד.
 */

declare(strict_types=1);

require_once __DIR__ . '/crypto.php';

// ניתן לדריסה לפני הטעינה — הבדיקות רצות על מסד זמני.
if (!defined('DB_FILE')) define('DB_FILE', __DIR__ . '/../data/scanner.sqlite');

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    ensureDataDir(dirname(DB_FILE));

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // WAL: הבליעה מהטלפון כותבת בזמן שה-UI קורא כדי לענות על שאלה.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec("
        -- ישן: הבעלים היחיד מלפני ההפרדה לפי משתמש. עבר ל-users (migrateLegacyOwner)
        -- ונשאר כאן רק כגיבוי — הקוד כבר לא קורא ממנו ולא כותב אליו.
        CREATE TABLE IF NOT EXISTS owner (
            id             INTEGER PRIMARY KEY CHECK (id = 1),
            password_hash  TEXT NOT NULL DEFAULT '',
            pair_token     TEXT NOT NULL DEFAULT '',
            ai_provider    TEXT NOT NULL DEFAULT 'anthropic',
            ai_model       TEXT NOT NULL DEFAULT '',
            ai_key_enc     TEXT NOT NULL DEFAULT '',
            created_at     INTEGER NOT NULL DEFAULT 0,
            updated_at     INTEGER NOT NULL DEFAULT 0
        );

        -- חשבונות הוואטסאפ שהבעלים הגדיר. 'kind' מבדיל ביזנס מהפרטי,
        -- כדי שאפשר יהיה לצמצם שאלה לחשבון אחד.
        CREATE TABLE IF NOT EXISTS accounts (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            label       TEXT NOT NULL,
            kind        TEXT NOT NULL DEFAULT 'personal',
            active      INTEGER NOT NULL DEFAULT 1,
            created_at  INTEGER NOT NULL
        );

        -- הודעות שנקלטו. גוף ההודעה ושם השולח מוצפנים במנוחה.
        -- ‏ext_id הוא מזהה יציב שהגשר מחשב לכל הודעה, כדי שסריקה חוזרת
        -- לא תיצור כפילויות (ראו INSERT OR IGNORE ב-ingest.php).
        CREATE TABLE IF NOT EXISTS messages (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id    INTEGER REFERENCES accounts(id) ON DELETE CASCADE,
            ext_id        TEXT NOT NULL,
            chat_name_enc TEXT NOT NULL DEFAULT '',
            sender_enc    TEXT NOT NULL DEFAULT '',
            direction     TEXT NOT NULL DEFAULT 'in',   -- in / out
            body_enc      TEXT NOT NULL DEFAULT '',
            sent_at       INTEGER NOT NULL DEFAULT 0,    -- חותמת זמן ההודעה
            ingested_at   INTEGER NOT NULL DEFAULT 0,
            UNIQUE(account_id, ext_id)
        );

        CREATE INDEX IF NOT EXISTS idx_msg_account_time ON messages(account_id, sent_at);
        CREATE INDEX IF NOT EXISTS idx_msg_time         ON messages(sent_at);

        -- טבלת חיפוש חופשי בטקסט גלוי הייתה מפרה את ההצפנה במנוחה,
        -- ולכן החיפוש נעשה על גוף מפוענח בזיכרון (query.php). idx על
        -- הזמן מספיק כדי לצמצם את החלון לפני הפענוח.

        -- יומן אבחון. כדי לא לעבוד בעיוור: כל בליעה, ping, כשל אימות
        -- ודיווח מהמכשיר נרשמים כאן. נקרא דרך אסימון יומן (ראו auth).
        -- detail אינו מוצפן — הוא מטא-נתונים ודגימות קצרות לאבחון,
        -- לא ההיסטוריה עצמה.
        CREATE TABLE IF NOT EXISTS logs (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            ts      INTEGER NOT NULL,
            source  TEXT NOT NULL DEFAULT '',   -- bridge / device / owner
            action  TEXT NOT NULL DEFAULT '',
            status  TEXT NOT NULL DEFAULT '',
            detail  TEXT NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_logs_id ON logs(id);

        -- המשתמשים. כל אחד עם הגדרות, אסימונים ומפתח AI משלו.
        -- platform_sub — החשבון בפלטפורמה שהמשתמש מקושר אליו (platform/docs/sso.md).
        -- password_hash — הכניסה הישנה. לא נמחקת לעולם; חסומה כל עוד האפליקציה
        -- מחוברת לפלטפורמה, וחוזרת לעבוד כשהיא מנותקת (platform/docs/auth.md, סעיף 4).
        -- auto — נפתח לבד בכניסה הראשונה דרך הפלטפורמה; רק אליו אפשר עוד לחבר חשבון ישן.
        CREATE TABLE IF NOT EXISTS users (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            platform_sub      TEXT NOT NULL DEFAULT '',
            email             TEXT NOT NULL DEFAULT '',
            name              TEXT NOT NULL DEFAULT '',
            password_hash     TEXT NOT NULL DEFAULT '',
            auto              INTEGER NOT NULL DEFAULT 0,
            link_dismissed    INTEGER NOT NULL DEFAULT 0,
            link_fails        INTEGER NOT NULL DEFAULT 0,
            link_locked_until INTEGER NOT NULL DEFAULT 0,
            pair_token        TEXT NOT NULL DEFAULT '',
            log_token         TEXT NOT NULL DEFAULT '',
            ai_provider       TEXT NOT NULL DEFAULT 'anthropic',
            ai_model          TEXT NOT NULL DEFAULT '',
            ai_key_enc        TEXT NOT NULL DEFAULT '',
            answer_lang       TEXT NOT NULL DEFAULT 'he',
            created_at        INTEGER NOT NULL DEFAULT 0,
            updated_at        INTEGER NOT NULL DEFAULT 0
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_users_sub  ON users(platform_sub) WHERE platform_sub <> '';
        CREATE UNIQUE INDEX IF NOT EXISTS idx_users_pair ON users(pair_token)   WHERE pair_token <> '';
        CREATE UNIQUE INDEX IF NOT EXISTS idx_users_log  ON users(log_token)    WHERE log_token <> '';

        -- קבצי מדיה שכבר עובדו. שומרים רק גיבוב ומטא-נתונים (לא את
        -- הקובץ עצמו), כדי לא לחלץ שוב את אותו קובץ ולא לחייב שוב את
        -- המודל. הטקסט שחולץ נשמר כהודעה רגילה (מוצפן) וכך ניתן לחיפוש.
        CREATE TABLE IF NOT EXISTS media (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id  INTEGER REFERENCES accounts(id) ON DELETE CASCADE,
            hash        TEXT NOT NULL,
            filename    TEXT NOT NULL DEFAULT '',
            mime        TEXT NOT NULL DEFAULT '',
            processed_at INTEGER NOT NULL DEFAULT 0,
            UNIQUE(account_id, hash)
        );
    ");

    // עמודת אסימון היומן נוספת למסד קיים (התקנה שכבר רצה) בלי להפיל.
    if (!columnExists($pdo, 'owner', 'log_token')) {
        $pdo->exec("ALTER TABLE owner ADD COLUMN log_token TEXT NOT NULL DEFAULT ''");
    }
    // חבילת האפליקציה שהחשבון מייצג (com.whatsapp / com.whatsapp.w4b /
    // מותאם), לצורך הפרדה מוחלטת בין חשבונות.
    if (!columnExists($pdo, 'accounts', 'wa_package')) {
        $pdo->exec("ALTER TABLE accounts ADD COLUMN wa_package TEXT NOT NULL DEFAULT ''");
    }
    // שפת התשובות של ה-AI (קוד: he/en/ar/…/auto). ברירת מחדל עברית.
    if (!columnExists($pdo, 'owner', 'answer_lang')) {
        $pdo->exec("ALTER TABLE owner ADD COLUMN answer_lang TEXT NOT NULL DEFAULT 'he'");
    }
    // הפרדה לפי משתמש: החשבונות והיומן שייכים למשתמש.
    if (!columnExists($pdo, 'accounts', 'user_id')) {
        $pdo->exec("ALTER TABLE accounts ADD COLUMN user_id INTEGER NOT NULL DEFAULT 0");
    }
    if (!columnExists($pdo, 'logs', 'user_id')) {
        $pdo->exec("ALTER TABLE logs ADD COLUMN user_id INTEGER NOT NULL DEFAULT 0");
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_accounts_user ON accounts(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_logs_user ON logs(user_id, id)');
    migrateLegacyOwner($pdo);
}

/**
 * לפני ההפרדה היה בעלים אחד (טבלת owner, שורה id=1), וכל הנתונים שלו. הוא
 * עובר פעם אחת למשתמש מספר 1 — עם הסיסמה, האסימונים ומפתח ה-AI — וכל
 * החשבונות והיומן הקיימים משויכים אליו. כך הגשר שכבר מצומד ממשיך לעבוד
 * באותו אסימון, ובעל המערכת מחבר את המשתמש הזה לחשבון שלו בפלטפורמה במסך
 * החיבור החד-פעמי ("יש לי כבר חשבון" + הסיסמה הישנה).
 *
 * טבלת owner נשארת כמו שהיא (לא נמחקת) — גיבוי למקרה של חזרה לאחור.
 */
function migrateLegacyOwner(PDO $pdo): void {
    if ($pdo->query('SELECT 1 FROM users LIMIT 1')->fetchColumn()) return;
    $o = $pdo->query('SELECT * FROM owner WHERE id = 1')->fetch();
    $hasData = (bool) $pdo->query('SELECT 1 FROM accounts LIMIT 1')->fetchColumn();
    if (!$o || (($o['password_hash'] ?? '') === '' && !$hasData)) return;   // התקנה ריקה

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO users (id, password_hash, pair_token, log_token, ai_provider, ai_model,
                                          ai_key_enc, answer_lang, created_at, updated_at)
                       VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $o['password_hash'] ?? '', $o['pair_token'] ?? '', $o['log_token'] ?? '',
                $o['ai_provider'] ?? 'anthropic', $o['ai_model'] ?? '', $o['ai_key_enc'] ?? '',
                $o['answer_lang'] ?? 'he', (int) ($o['created_at'] ?? 0) ?: time(), time(),
            ]);
        $pdo->exec('UPDATE accounts SET user_id = 1 WHERE user_id = 0');
        $pdo->exec('UPDATE logs SET user_id = 1 WHERE user_id = 0');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function columnExists(PDO $pdo, string $table, string $col): bool {
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $r) {
        if (($r['name'] ?? '') === $col) return true;
    }
    return false;
}

/** רושם שורת יומן. לעולם לא מפיל את הבקשה — אבחון לא שובר תפקוד. */
function logEvent(string $source, string $action, string $status, $detail = ''): void {
    try {
        $d = is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE);
        $uid = currentUserId();
        $st = db()->prepare('INSERT INTO logs (ts, user_id, source, action, status, detail) VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([
            time(),
            $uid,
            mb_substr($source, 0, 20),
            mb_substr($action, 0, 40),
            mb_substr($status, 0, 60),
            mb_substr((string) $d, 0, 4000),
        ]);
        // גיזום: שומרים את 2000 האחרונים של כל משתמש.
        db()->prepare('DELETE FROM logs WHERE user_id = ? AND id NOT IN
                         (SELECT id FROM logs WHERE user_id = ? ORDER BY id DESC LIMIT 2000)')
            ->execute([$uid, $uid]);
    } catch (Throwable $e) {
        // בולעים בכוונה.
    }
}

function recentLogs(int $limit = 300): array {
    $limit = max(1, min($limit, 1000));
    $st = db()->prepare("SELECT * FROM logs WHERE user_id = ? ORDER BY id DESC LIMIT $limit");
    $st->execute([currentUserId()]);
    $rows = $st->fetchAll();
    return array_reverse($rows);   // ישן→חדש, נוח לקריאה
}

/**
 * המשתמש שהבקשה הנוכחית פועלת בשמו. 0 = אין (לא נכנס, או אסימון שגוי).
 * נקבע פעם אחת בבקשה — מהכניסה (auth.php) או מאסימון הגשר — וכל שאילתה
 * על נתונים מסננת לפיו.
 */
function currentUserId(?int $set = null): int {
    static $id = 0;
    if ($set !== null) $id = $set;
    return $id;
}

function getUser(int $id): ?array {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** השורה של המשתמש הנוכחי (ריק כשאין). */
function me(): array {
    $id = currentUserId();
    return $id > 0 ? (getUser($id) ?? []) : [];
}

/** האם יש משתמש כלשהו. לפני כן — מסך התקנה (רק כשהאפליקציה מנותקת). */
function anyUser(): bool {
    return (bool) db()->query('SELECT 1 FROM users LIMIT 1')->fetchColumn();
}

function updateUser(int $id, array $fields): void {
    if (!$fields || $id <= 0) return;
    $fields['updated_at'] = time();
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
    $st = db()->prepare("UPDATE users SET $sets WHERE id = ?");
    $st->execute([...array_values($fields), $id]);
}

function updateMe(array $fields): void {
    updateUser(currentUserId(), $fields);
}

/** משתמש חדש, עם אסימוני גשר ויומן משלו. מחזיר את השורה. */
function createUser(array $fields): array {
    $now = time();
    $fields += [
        'pair_token' => bin2hex(random_bytes(24)),
        'log_token'  => bin2hex(random_bytes(24)),
        'created_at' => $now,
        'updated_at' => $now,
    ];
    $cols = implode(', ', array_keys($fields));
    $qs   = implode(', ', array_fill(0, count($fields), '?'));
    db()->prepare("INSERT INTO users ($cols) VALUES ($qs)")->execute(array_values($fields));
    return getUser((int) db()->lastInsertId());
}
