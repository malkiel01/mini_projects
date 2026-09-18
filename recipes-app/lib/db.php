<?php
/**
 * שכבת האחסון — SQLite דרך PDO.
 *
 * נבחר SQLite ולא MySQL כי אין פרטי התחברות לנהל והקובץ נוסע עם התיקייה,
 * וזה מה שכבר עובד בשרת הזה ב-claude-tasks. הסכימה כאן זהה ל-SCHEMA.sql
 * שבתיקיית האב; אותו קובץ הוא התיעוד, וזה המימוש. שינוי באחד מחייב את השני.
 *
 * המיגרציות הן CREATE TABLE IF NOT EXISTS בכוונה: פריסה על מסד קיים אינה
 * נופלת ואינה דורסת, ואין כאן שלב התקנה נפרד שמישהו צריך לזכור להריץ.
 */

declare(strict_types=1);

require_once __DIR__ . '/errors.php';

// ניתן לדריסה לפני הטעינה — כך הבדיקות רצות על מסד זמני ולא נוגעות
// בנתונים האמיתיים.
if (!defined('DB_FILE'))   define('DB_FILE',   __DIR__ . '/../data/recipes.sqlite');
if (!defined('MEDIA_DIR')) define('MEDIA_DIR', __DIR__ . '/../data/media');

/** מקצב אחסון לחשבון, בבייטים. 200MB — הכרעה 8א.2 באפיון. */
const STORAGE_QUOTA_BYTES = 200 * 1024 * 1024;

/** תקרה לקובץ וידאו בודד. 20MB — הכרעה 3.4 באפיון. */
const VIDEO_MAX_BYTES = 20 * 1024 * 1024;

/** תקרה קשה לתמונה. הדפדפן מקטין לפני העלאה; זו רשת הביטחון. */
const IMAGE_MAX_BYTES = 5 * 1024 * 1024;

/** חתימת זמן אחידה. ISO-8601 ב-UTC — נשמר כטקסט, ומסתדר לקסיקוגרפית. */
function nowIso(): string {
    return gmdate('Y-m-d\TH:i:s\Z');
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dir = dirname(DB_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new AppError('לא ניתן ליצור את תיקיית הנתונים', 500);
    }

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // WAL מאפשר קוראים במקביל לכותב — נחוץ כשכמה אנשים מעיינים בעוד אחד שומר.
    $pdo->exec('PRAGMA journal_mode = WAL');
    // בלעדיו כל ה-CASCADE וה-SET NULL שבסכימה אינם קיימים. SQLite מכבה
    // מפתחות זרים כברירת מחדל, וזו הנקודה שהכי קל לשכוח.
    $pdo->exec('PRAGMA foreign_keys = ON');
    // בלי זה, כותב שני נכשל מיד ב-SQLITE_BUSY במקום להמתין בתור.
    $pdo->exec('PRAGMA busy_timeout = 5000');

    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            username       TEXT    NOT NULL UNIQUE,
            email          TEXT    NOT NULL UNIQUE,
            password_hash  TEXT    NOT NULL,
            display_name   TEXT    NOT NULL,
            role           TEXT    NOT NULL DEFAULT 'user'
                           CHECK (role IN ('user','admin')),
            email_verified INTEGER NOT NULL DEFAULT 0,
            blocked        INTEGER NOT NULL DEFAULT 0,
            storage_used   INTEGER NOT NULL DEFAULT 0,
            created_at     TEXT    NOT NULL
        );

        CREATE TABLE IF NOT EXISTS user_tokens (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            kind       TEXT    NOT NULL CHECK (kind IN ('verify_email','reset_password')),
            token_hash TEXT    NOT NULL UNIQUE,
            expires_at TEXT    NOT NULL,
            used_at    TEXT,
            created_at TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_tokens_user ON user_tokens(user_id, kind);

        CREATE TABLE IF NOT EXISTS recipes (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            title         TEXT    NOT NULL,
            visibility    TEXT    NOT NULL DEFAULT 'private'
                          CHECK (visibility IN ('private','public')),
            servings      INTEGER,
            difficulty    TEXT    CHECK (difficulty IN ('easy','medium','hard')),
            work_minutes  INTEGER,
            wait_minutes  INTEGER,
            tips          TEXT,
            comments_open INTEGER NOT NULL DEFAULT 1,
            main_media_id INTEGER,
            search_text   TEXT,
            created_at    TEXT    NOT NULL,
            updated_at    TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_recipes_owner      ON recipes(owner_id);
        CREATE INDEX IF NOT EXISTS idx_recipes_public     ON recipes(visibility, updated_at);
        CREATE INDEX IF NOT EXISTS idx_recipes_difficulty ON recipes(difficulty);

        CREATE TABLE IF NOT EXISTS sections (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
            name      TEXT,
            position  INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_sections_recipe ON sections(recipe_id, position);

        CREATE TABLE IF NOT EXISTS products (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            name          TEXT NOT NULL UNIQUE,
            name_norm     TEXT NOT NULL,
            grams_per_cup REAL,
            created_at    TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_products_norm ON products(name_norm);

        CREATE TABLE IF NOT EXISTS ingredients (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            section_id INTEGER NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
            free_text  TEXT    NOT NULL,
            amount_min REAL,
            amount_max REAL,
            unit       TEXT    CHECK (unit IN ('gram','kg','ml','liter','cup',
                                              'tbsp','tsp','unit','package','pinch')),
            product_id INTEGER REFERENCES products(id) ON DELETE SET NULL,
            optional   INTEGER NOT NULL DEFAULT 0,
            position   INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_ingredients_section ON ingredients(section_id, position);
        CREATE INDEX IF NOT EXISTS idx_ingredients_product ON ingredients(product_id);

        CREATE TABLE IF NOT EXISTS steps (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            section_id INTEGER NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
            position   INTEGER NOT NULL,
            text       TEXT    NOT NULL,
            media_id   INTEGER,
            CHECK (position > 0)
        );
        CREATE INDEX IF NOT EXISTS idx_steps_section ON steps(section_id, position);

        CREATE TABLE IF NOT EXISTS media (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id   INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
            uploader_id INTEGER NOT NULL REFERENCES users(id)   ON DELETE CASCADE,
            kind        TEXT    NOT NULL CHECK (kind IN ('image','video')),
            source      TEXT    NOT NULL CHECK (source IN ('upload','link')),
            path_or_url TEXT    NOT NULL,
            mime        TEXT,
            bytes       INTEGER NOT NULL DEFAULT 0,
            position    INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_media_recipe   ON media(recipe_id, position);
        CREATE INDEX IF NOT EXISTS idx_media_uploader ON media(uploader_id);

        CREATE TABLE IF NOT EXISTS tags (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            axis     TEXT    NOT NULL CHECK (axis IN ('topic','method','kosher',
                                                      'suitable','cuisine')),
            name     TEXT    NOT NULL,
            position INTEGER NOT NULL DEFAULT 0,
            UNIQUE (axis, name)
        );

        CREATE TABLE IF NOT EXISTS recipe_tags (
            recipe_id INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
            tag_id    INTEGER NOT NULL REFERENCES tags(id)    ON DELETE CASCADE,
            PRIMARY KEY (recipe_id, tag_id)
        );
        CREATE INDEX IF NOT EXISTS idx_recipe_tags_tag ON recipe_tags(tag_id);

        CREATE TABLE IF NOT EXISTS user_tags (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            name    TEXT    NOT NULL,
            UNIQUE (user_id, name)
        );

        CREATE TABLE IF NOT EXISTS recipe_user_tags (
            recipe_id   INTEGER NOT NULL REFERENCES recipes(id)   ON DELETE CASCADE,
            user_tag_id INTEGER NOT NULL REFERENCES user_tags(id) ON DELETE CASCADE,
            PRIMARY KEY (recipe_id, user_tag_id)
        );

        CREATE TABLE IF NOT EXISTS favorites (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            recipe_id      INTEGER REFERENCES recipes(id) ON DELETE SET NULL,
            title_snapshot TEXT    NOT NULL,
            created_at     TEXT    NOT NULL,
            UNIQUE (user_id, recipe_id)
        );
        CREATE INDEX IF NOT EXISTS idx_favorites_user ON favorites(user_id);

        CREATE TABLE IF NOT EXISTS comments (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id  INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
            user_id    INTEGER NOT NULL REFERENCES users(id)   ON DELETE CASCADE,
            parent_id  INTEGER REFERENCES comments(id) ON DELETE CASCADE,
            visibility TEXT    NOT NULL DEFAULT 'public'
                       CHECK (visibility IN ('public','private_note')),
            text       TEXT    NOT NULL,
            media_id   INTEGER REFERENCES media(id) ON DELETE SET NULL,
            created_at TEXT    NOT NULL,
            edited_at  TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_comments_recipe ON comments(recipe_id, visibility, created_at);
        CREATE INDEX IF NOT EXISTS idx_comments_user   ON comments(user_id, visibility);

        CREATE TABLE IF NOT EXISTS shopping_lists (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            name       TEXT,
            created_at TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_lists_user ON shopping_lists(user_id, created_at);

        CREATE TABLE IF NOT EXISTS shopping_list_recipes (
            list_id   INTEGER NOT NULL REFERENCES shopping_lists(id) ON DELETE CASCADE,
            recipe_id INTEGER REFERENCES recipes(id) ON DELETE SET NULL,
            PRIMARY KEY (list_id, recipe_id)
        );

        CREATE TABLE IF NOT EXISTS shopping_list_items (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            list_id    INTEGER NOT NULL REFERENCES shopping_lists(id) ON DELETE CASCADE,
            product_id INTEGER REFERENCES products(id) ON DELETE SET NULL,
            label      TEXT    NOT NULL,
            amount     REAL,
            unit       TEXT,
            free_text  TEXT,
            optional   INTEGER NOT NULL DEFAULT 0,
            manual     INTEGER NOT NULL DEFAULT 0,
            checked    INTEGER NOT NULL DEFAULT 0,
            position   INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_list_items ON shopping_list_items(list_id, position);

        CREATE TABLE IF NOT EXISTS cooking_progress (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            step_id INTEGER NOT NULL REFERENCES steps(id) ON DELETE CASCADE,
            done_at TEXT    NOT NULL,
            PRIMARY KEY (user_id, step_id)
        );
    ");

    seedTags($pdo);
}

/**
 * הצירים הסגורים (סעיף 4 באפיון) נזרעים פעם אחת ומנוהלים אחר כך על ידי
 * המנהל מתוך האפליקציה. הזריעה מדלגת על מה שקיים, ולכן שינוי או מחיקה
 * שהמנהל עשה אינם חוזרים בפריסה הבאה — ותג שנמחק בכוונה לא יצוץ שוב.
 */
function seedTags(PDO $pdo): void {
    $seed = [
        'topic'    => ['עוגות','עוגיות','מאפים','בצקים','מנה עיקרית','מנת פתיחה',
                       'מרקים','סלטים','תוספות','קינוחים','שתייה'],
        'method'   => ['אפייה','בישול','טיגון','ללא בישול','גריל','אידוי'],
        'kosher'   => ['חלבי','בשרי','פרווה'],
        'suitable' => ['פסח','טבעוני','צמחוני','ללא גלוטן','ללא לקטוז'],
        'cuisine'  => ['מרוקאי','איטלקי','אסייתי','מזרחי','אשכנזי','אמריקאי'],
    ];

    $seeded = (int) $pdo->query('SELECT COUNT(*) c FROM tags')->fetch()['c'];
    if ($seeded > 0) return;

    $st = $pdo->prepare('INSERT INTO tags (axis, name, position) VALUES (?,?,?)');
    foreach ($seed as $axis => $names) {
        foreach (array_values($names) as $i => $name) {
            $st->execute([$axis, $name, $i + 1]);
        }
    }
}

/**
 * נרמול טקסט לחיפוש (סעיף 5): ניקוד, גרשיים ופיסוק מוסרים, רווחים מכווצים.
 *
 * זה מה שמאפשר למצוא "גבינה 5%" כשמקלידים "גבינה 5", ו"עוגיות" בתוך
 * "עוגיות שוקולד". מה שזה לא עושה — ולא מתיימר — הוא נטיות: "עוגייה"
 * לא יימצא מחיפוש "עוגיות". זה כתוב בפרק 5 באפיון, ולא נשבר כאן בשקט.
 */
function normalizeText(string $text): string {
    // ניקוד עברי: U+0591..U+05C7
    $text = preg_replace('/[\x{0591}-\x{05C7}]/u', '', $text) ?? $text;
    $text = str_replace(['״', '׳', '"', "'", '`'], '', $text);
    $text = preg_replace('/[^\p{L}\p{N}%]+/u', ' ', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', mb_strtolower($text, 'UTF-8')) ?? $text);
}

/** מחזיר את צריכת האחסון בפועל, מחושבת ולא נסמכת על השדה שבשורה. */
function storageUsedReal(int $userId): int {
    $st = db()->prepare("SELECT COALESCE(SUM(bytes),0) b FROM media
                          WHERE uploader_id = ? AND source = 'upload'");
    $st->execute([$userId]);
    return (int) $st->fetch()['b'];
}
