<?php
/**
 * חיבור לבסיס הנתונים והתקנתו.
 *
 * ‏SQLite בקובץ יחיד תחת data/. הפריסה (rsync בלי --delete) מחריגה את
 * התיקייה, ולכן הנתונים שורדים כל העלאת קוד.
 */

declare(strict_types=1);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/errors.php';

/**
 * תיקיית הנתונים.
 *
 * ניתנת לעקיפה במשתנה סביבה כדי שהבדיקות יריצו את הסכימה האמיתית על
 * בסיס נתונים זמני. בלי זה שגיאת SQL הייתה מתגלה רק בשרת.
 */
installErrorHandlers();

function dataDir(): string {
    return getenv('GB_DATA_DIR') ?: __DIR__ . '/../data';
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dir = dataDir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('לא ניתן ליצור את תיקיית הנתונים');
    }

    $pdo = new PDO('sqlite:' . $dir . '/app.sqlite', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // ‏WAL: קורא אחד אינו חוסם כותב. באחסון משותף שבו כמה בקשות
    // מגיעות במקביל זה ההבדל בין עבודה לבין "database is locked".
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    foreach (schemaStatements() as $sql) $pdo->exec($sql);
    migrate($pdo);
    seedCatalog($pdo);
    return $pdo;
}

/**
 * עמודות שנוספו אחרי שכבר היו נתונים בשרת.
 *
 * ‏CREATE TABLE IF NOT EXISTS אינו מוסיף עמודה לטבלה קיימת, ולכן
 * שדרוג היה נופל על "no such column" בבסיס נתונים שכבר עובד. כאן
 * נבדק מה קיים בפועל, ומתווסף רק מה שחסר.
 */
function migrate(PDO $pdo): void {
    $columns = function (string $table) use ($pdo): array {
        return array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    };

    $have = $columns('policies');
    $add  = [
        'posture'       => "TEXT NOT NULL DEFAULT 'deny_all'",
        'blocked_types' => "TEXT NOT NULL DEFAULT ''",
    ];
    foreach ($add as $col => $def) {
        if (!in_array($col, $have, true)) {
            $pdo->exec("ALTER TABLE policies ADD COLUMN $col $def");
        }
    }

    /*
     * ‏mode ו-posture היו כרוכים יחד בגרסה הקודמת. הפירוק נעשה כאן
     * פעם אחת: המצב הישן "free" היה גם דפדפן וגם פתוח-כברירת-מחדל,
     * ו-"allowlist" היה דפדפן וסגור. בלי התרגום הזה משתמש שהיה
     * חופשי היה מוצא את עצמו חסום לפתע.
     */
    if (!in_array('migrated_posture', $columns('policies'), true)) {
        $pdo->exec("UPDATE policies SET posture = 'allow_all' WHERE mode = 'free'");
        $pdo->exec("UPDATE policies SET mode = 'browser' WHERE mode IN ('free', 'allowlist')");
        $pdo->exec("ALTER TABLE policies ADD COLUMN migrated_posture INTEGER NOT NULL DEFAULT 1");
    }
}

/**
 * זריעת שיוכי הדומיינים מהקטלוג.
 *
 * ‏INSERT OR IGNORE, ורק source='seed': שיוך שהמנהל הוסיף בעצמו לא
 * יידרס בעדכון הבא של הקטלוג.
 */
function seedCatalog(PDO $pdo): void {
    $count = (int) $pdo->query("SELECT COUNT(*) FROM domain_categories WHERE source = 'seed'")
                       ->fetchColumn();
    $seed = seedDomainCategories();
    $want = array_sum(array_map('count', $seed));
    if ($count >= $want) return;   // כבר נזרע

    $st = $pdo->prepare('INSERT OR IGNORE INTO domain_categories (domain, category, source)
                         VALUES (?, ?, ?)');
    $pdo->beginTransaction();
    foreach ($seed as $category => $domains) {
        foreach ($domains as $domain) $st->execute([$domain, $category, 'seed']);
    }
    $pdo->commit();
}

/** עכשיו ב-UTC, בפורמט שממוין נכון כמחרוזת. */
function nowIso(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

function zoneOf(string $tz): DateTimeZone {
    try { return new DateTimeZone($tz); }
    catch (Exception) { return new DateTimeZone('Asia/Jerusalem'); }
}

/** היום לפי אזור הזמן של המשתמש — המכסה היומית מתאפסת שם, לא ב-UTC. */
function todayIn(string $tz): string {
    return (new DateTimeImmutable('now', zoneOf($tz)))->format('Y-m-d');
}

/** ‏"עכשיו" באזור הזמן של המשתמש — לחלון היומי ולמסכת הימים. */
function nowIn(string $tz): DateTimeImmutable {
    return new DateTimeImmutable('now', zoneOf($tz));
}

function q(string $sql, array $args = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}

function one(string $sql, array $args = []): ?array {
    $row = q($sql, $args)->fetch();
    return $row === false ? null : $row;
}

function all(string $sql, array $args = []): array {
    return q($sql, $args)->fetchAll();
}


/**
 * הוספה-או-עדכון שעובדת בכל גרסת SQLite.
 *
 * ‏"INSERT ... ON CONFLICT DO UPDATE" נוסף רק ב-SQLite 3.24 (2018),
 * ובאחסון משותף עדיין נפוצות גרסאות ישנות יותר — שם הוא נופל על
 * "near ON: syntax error". שתי פקודות במקומו עובדות בכל מקום:
 * ‏INSERT OR IGNORE יוצר את השורה אם אינה קיימת, ו-UPDATE כותב את
 * הערכים. הסדר הזה גם בטוח מפני מרוץ בין שתי בקשות, בניגוד ל-
 * "בדוק ואז הוסף".
 *
 *   $key        — העמודות שמזהות את השורה
 *   $values     — מה שנכתב גם בהוספה וגם בעדכון
 *   $insertOnly — מה שנכתב רק בהוספה (created_at וכדומה)
 */
function upsert(string $table, array $key, array $values, array $insertOnly = []): void {
    // שמות עמודות אינם ניתנים לפרמטור, ולכן נבדקים במפורש. כל
    // הקוראים מעבירים שמות קבועים, וזו רשת ביטחון ולא הגנה יחידה.
    foreach ([$table, ...array_keys($key), ...array_keys($values), ...array_keys($insertOnly)] as $n) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $n)) {
            throw new InvalidArgumentException("שם עמודה או טבלה לא תקין: $n");
        }
    }

    $insert = $key + $values + $insertOnly;
    $cols   = implode(', ', array_map(fn($c) => "\"$c\"", array_keys($insert)));
    $marks  = implode(', ', array_fill(0, count($insert), '?'));
    q("INSERT OR IGNORE INTO \"$table\" ($cols) VALUES ($marks)", array_values($insert));

    if (!$values) return;

    $set   = implode(', ', array_map(fn($c) => "\"$c\" = ?", array_keys($values)));
    $where = implode(' AND ', array_map(fn($c) => "\"$c\" = ?", array_keys($key)));
    q("UPDATE \"$table\" SET $set WHERE $where",
      array_merge(array_values($values), array_values($key)));
}

/* ── שליפות ─────────────────────────────────────────────────────── */

/** מדיניות המשתמש, עם ברירות מחדל אם טרם נוצרה שורה. */
function policyFor(int $userId): array {
    return one('SELECT * FROM policies WHERE user_id = ?', [$userId]) ?? [
        'user_id' => $userId, 'mode' => MODE_KIOSK, 'posture' => POSTURE_DENY,
        'blocked_types' => '', 'timezone' => 'Asia/Jerusalem',
        'days_mask' => 127, 'window_start' => '', 'window_end' => '',
        'daily_quota_min' => 0, 'session_max_min' => 0, 'max_devices' => 1,
        'allow_downloads' => 0, 'block_screenshots' => 0, 'keep_history' => 1,
    ];
}

function rulesFor(int $userId): array {
    return all('SELECT * FROM rules WHERE user_id = ? AND enabled = 1
                ORDER BY sort_order, id', [$userId]);
}

function usedTodaySeconds(int $userId, string $tz): int {
    $row = one('SELECT seconds FROM usage WHERE user_id = ? AND day = ?',
               [$userId, todayIn($tz)]);
    return (int) ($row['seconds'] ?? 0);
}

/**
 * צובר שניות ליום הנוכחי.
 *
 * כאן צריך חיבור ולא החלפה, ולכן זו אינה upsert רגילה: קודם מובטח
 * שהשורה קיימת, ואז מוסיפים לה. שתי הפקודות עובדות בכל גרסת SQLite.
 */
function addUsage(int $userId, string $tz, int $seconds): void {
    if ($seconds <= 0) return;
    $day = todayIn($tz);
    q('INSERT OR IGNORE INTO usage (user_id, day, seconds) VALUES (?, ?, 0)', [$userId, $day]);
    q('UPDATE usage SET seconds = seconds + ? WHERE user_id = ? AND day = ?',
      [$seconds, $userId, $day]);
}

/**
 * רישום ליומן.
 *
 * זה מה שהופך את הפאנל מהגדרות לניהול: המנהל רואה מה נוסה ומה נחסם,
 * ולא רק מה הוגדר. נכשל בשקט — יומן שנופל לא יפיל בקשה.
 */
function audit(int $userId, string $kind, bool $allowed, string $url = '',
               string $code = '', string $detail = ''): void {
    try {
        q('INSERT INTO audit (user_id, at, kind, url, allowed, code, detail)
           VALUES (?, ?, ?, ?, ?, ?, ?)',
          [$userId, nowIso(), $kind, mb_substr($url, 0, 500), $allowed ? 1 : 0, $code, $detail]);
    } catch (Throwable) { /* יומן אינו סיבה להפיל בקשה */ }
}


/* ── קטגוריות, פלטפורמות, ופענוח בעלות ────────────────────────── */

/** מפת דומיין→קטגוריות, בצורה שהמנוע מצפה לה. */
function domainMap(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    foreach (all('SELECT domain, category FROM domain_categories') as $r) {
        $cache[$r['domain']][] = $r['category'];
    }
    return $cache;
}

/** כללי הקטגוריות של משתמש: [category => allow|deny]. */
function categoryRulesFor(int $userId): array {
    $out = [];
    foreach (all('SELECT category, action FROM category_rules WHERE user_id = ?', [$userId]) as $r) {
        $out[$r['category']] = $r['action'];
    }
    return $out;
}

/** הגדרות הפלטפורמות של משתמש: [platform => row]. */
function platformRulesFor(int $userId): array {
    $out = [];
    foreach (all('SELECT * FROM platform_rules WHERE user_id = ?', [$userId]) as $r) {
        $out[$r['platform']] = $r;
    }
    return $out;
}

/** הפריטים שאושרו: [platform => [kind => [id => action]]]. */
function platformItemsFor(int $userId): array {
    $out = [];
    foreach (all('SELECT * FROM platform_items WHERE user_id = ?', [$userId]) as $r) {
        $out[$r['platform']][$r['kind']][$r['item_id']] = $r['action'];
    }
    return $out;
}

/**
 * פענוח סרטון→ערוץ, עם מטמון.
 *
 * הבעלות על סרטון אינה משתנה, ולכן התשובה נשמרת לצמיתות. גם תשובה
 * ריקה נשמרת לזמן קצר — אחרת סרטון שיוטיוב לא ענה עליו היה גורר
 * פנייה חוזרת בכל לחיצה.
 */
function youTubeOwner(string $videoId): string {
    $row = one('SELECT channel_id, fetched_at FROM video_owner WHERE platform = ? AND video_id = ?',
               [PLATFORM_YOUTUBE, $videoId]);

    if ($row) {
        if ($row['channel_id'] !== '') return $row['channel_id'];
        // כישלון נשמר לשעה בלבד: ייתכן שהיה תקלה רגעית.
        if (strtotime($row['fetched_at']) > time() - 3600) return '';
    }

    $info = fetchYouTubeOwner($videoId);
    upsert('video_owner',
           ['platform' => PLATFORM_YOUTUBE, 'video_id' => $videoId],
           ['channel_id' => $info['channel'], 'title' => $info['title'], 'fetched_at' => nowIso()]);

    return $info['channel'];
}

/** כל מה שהמנוע צריך על משתמש, במקום אחד. */
function ruleSetFor(int $userId): array {
    return [
        'rules'          => rulesFor($userId),
        'categories'     => categoryRulesFor($userId),
        'domain_map'     => domainMap(),
        'platforms'      => platformRulesFor($userId),
        'platform_items' => platformItemsFor($userId),
        'owner_of'       => fn(string $v) => youTubeOwner($v),
    ];
}
