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

    if (!in_array('handle', $columns('video_owner'), true)) {
        $pdo->exec("ALTER TABLE video_owner ADD COLUMN handle TEXT NOT NULL DEFAULT ''");
    }

    $have = $columns('policies');
    $add  = [
        'posture'       => "TEXT NOT NULL DEFAULT 'deny_all'",
        'blocked_types' => "TEXT NOT NULL DEFAULT ''",
        'ad_block'      => "TEXT NOT NULL DEFAULT ''",
        'allow_pip'        => 'INTEGER NOT NULL DEFAULT 0',
        'allow_background' => 'INTEGER NOT NULL DEFAULT 0',
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

    /*
     * שלב 5: מתג ה-Shorts קובע עכשיו את מצב "פתוח" (קודם לא השפיע בו כלל).
     * מי שכבר במצב פתוח — ממשיך לקבל Shorts, כדי שלא ייחסמו לו פתאום.
     */
    if (!in_array('shorts_v2', $columns('platform_rules'), true)) {
        $pdo->exec("UPDATE platform_rules SET allow_shorts = 1 WHERE mode = 'full'");
        $pdo->exec("ALTER TABLE platform_rules ADD COLUMN shorts_v2 INTEGER NOT NULL DEFAULT 1");
    }

    if (!in_array('kind', $columns('admin_sessions'), true)) {
        $pdo->exec("ALTER TABLE admin_sessions ADD COLUMN kind TEXT NOT NULL DEFAULT 'admin'");
    }

    if (!in_array('account_id', $columns('users'), true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN account_id INTEGER NOT NULL DEFAULT 0");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_account ON users(account_id)");
    }
    // גרסת האפליקציה בכל מכשיר (מכותרת X-Migdalor-App), ובקשת עדכון מההורה.
    if (!in_array('app_version', $columns('devices'), true)) {
        $pdo->exec("ALTER TABLE devices ADD COLUMN app_version INTEGER NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE devices ADD COLUMN update_requested_at TEXT NOT NULL DEFAULT ''");
    }
    // ההרשאות שניתנו במכשיר (מסלול ההקמה), כמסכת ביטים מכותרת X-Migdalor-Perms.
    if (!in_array('perms', $columns('devices'), true)) {
        $pdo->exec("ALTER TABLE devices ADD COLUMN perms INTEGER NOT NULL DEFAULT 0");
    }
    // דוח האכיפה האחרון מהמכשיר (lib/enforce.php) — מה הוחל בפועל ומה נכשל, ומתי.
    if (!in_array('enforce_report', $columns('devices'), true)) {
        $pdo->exec("ALTER TABLE devices ADD COLUMN enforce_report TEXT NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE devices ADD COLUMN enforce_at TEXT NOT NULL DEFAULT ''");
    }
    // שלב 11: ברירת המחדל לאפליקציות (allow_all — חוסם רק מה שסומן; deny_all — רק מה שאושר).
    if (!in_array('apps_posture', $columns('policies'), true)) {
        $pdo->exec("ALTER TABLE policies ADD COLUMN apps_posture TEXT NOT NULL DEFAULT 'allow_all'");
    }
    // שלב 7: רמת הדיווח להורה, וזמן לכל כתובת בהיסטוריה המלאה.
    if (!in_array('report_level', $columns('policies'), true)) {
        $pdo->exec("ALTER TABLE policies ADD COLUMN report_level TEXT NOT NULL DEFAULT 'summary'");
    }
    if (!in_array('seconds', $columns('audit'), true)) {
        $pdo->exec("ALTER TABLE audit ADD COLUMN seconds INTEGER NOT NULL DEFAULT 0");
    }
    // שלב 6: היתר "לשעה" מבקשת גישה — כלל עם תפוגה. '' = קבוע.
    if (!in_array('expires_at', $columns('rules'), true)) {
        $pdo->exec("ALTER TABLE rules ADD COLUMN expires_at TEXT NOT NULL DEFAULT ''");
    }
    // כניסה דרך הפלטפורמה (lib/platform.php): החשבון בממשק הניהול מקושר ל-sub של חשבון הפלטפורמה.
    if (!in_array('platform_sub', $columns('users'), true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN platform_sub TEXT NOT NULL DEFAULT ''");
    }
    // הגדרות המכשיר (lib/devicesettings.php) — '' = ברירות המחדל.
    if (!in_array('device_settings', $columns('policies'), true)) {
        $pdo->exec("ALTER TABLE policies ADD COLUMN device_settings TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('platform_sub', $columns('parents'), true)) {
        $pdo->exec("ALTER TABLE parents ADD COLUMN platform_sub TEXT NOT NULL DEFAULT ''");
    }
    $have = $columns('developers');
    foreach (['email', 'platform_sub'] as $col) {
        if (!in_array($col, $have, true)) $pdo->exec("ALTER TABLE developers ADD COLUMN $col TEXT NOT NULL DEFAULT ''");
    }
    migrateToAccounts($pdo);
}

/**
 * המעבר לרב-דיירות (שלב 2), פעם אחת ובלי לאבד דבר.
 *
 * כל המשתמשים של היום הופכים לילדים בחשבון אחד — "החשבון הראשי" — עם
 * המדיניות שלהם כמו שהיא. כל מנהל קיים הופך גם למפתח (קונסולה) וגם
 * להורה בחשבון הזה, עם אותה סיסמה: אף אחד לא ננעל בחוץ ביום המעבר.
 *
 * רץ רק כשאין עדיין אף חשבון. משתמש חדש שנרשם מהאפליקציה בלי חשבון
 * (account_id=0) משויך לחשבון הראשון — עד שהצימוד (שלב 4) יחליף את ההרשמה.
 */
function migrateToAccounts(PDO $pdo): void {
    $hasAccounts = (int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn() > 0;
    $hasUsers    = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;

    if (!$hasAccounts && $hasUsers) {
        $now = nowIso();
        $pdo->beginTransaction();
        $st = $pdo->prepare("INSERT INTO accounts (name, status, level_max, modules, max_children,
                             max_devices, created_at, approved_at)
                             VALUES (?, 'active', 'browser', ?, 0, 0, ?, ?)");
        $st->execute(['החשבון הראשי', implode(',', ACCOUNT_MODULES), $now, $now]);
        $accountId = (int) $pdo->lastInsertId();

        $admins = $pdo->query("SELECT * FROM users WHERE is_admin = 1 AND status = 'active'")->fetchAll();
        foreach ($admins as $a) {
            $pdo->prepare('INSERT OR IGNORE INTO developers (username, name, password_hash, created_at)
                           VALUES (?, ?, ?, ?)')
                ->execute([$a['username'], $a['display_name'], $a['password_hash'], $now]);
            $pdo->prepare('INSERT OR IGNORE INTO parents (account_id, login, name, password_hash, created_at)
                           VALUES (?, ?, ?, ?, ?)')
                ->execute([$accountId, $a['username'], $a['display_name'], $a['password_hash'], $now]);
        }
        $pdo->commit();
    }

    $first = (int) $pdo->query('SELECT MIN(id) FROM accounts')->fetchColumn();
    if ($first > 0) {
        $pdo->prepare('UPDATE users SET account_id = ? WHERE account_id = 0')->execute([$first]);
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
/**
 * הגדרות שרת שאינן בגיט — data/config.json (מוחרג מהפריסה כמו המסד).
 *
 * ‏server_url: הכתובת שהאפליקציות צריכות לפנות אליה מעכשיו. כך מעבר
 * לדומיין אחר לא מחייב התקנה מחדש במכשירים שכבר אצל אנשים.
 */
function serverConfig(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $raw = @file_get_contents(dataDir() . '/config.json');
    $data = $raw === false ? null : json_decode($raw, true);
    return $cfg = is_array($data) ? $data : [];
}

/** הכתובת הקבועה של השרת, או '' כשלא הוגדרה. */
function serverUrl(): string {
    return cleanServerUrl((string) (serverConfig()['server_url'] ?? ''));
}

/** רק https עם שם שרת, תמיד עם / בסוף; כל דבר אחר — ''. */
function cleanServerUrl(string $u): string {
    $u = trim($u);
    if (!preg_match('~^https://[a-z0-9.-]+(:[0-9]+)?(/[A-Za-z0-9._/-]*)?$~i', $u)) return '';
    return rtrim($u, '/') . '/';
}

function nowIso(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

/** base64url בלי ריפוד — של בתים גולמיים, ובחזרה. */
function b64u(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function b64ud(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/'), true); }

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
        'ad_block' => '', 'daily_quota_min' => 0, 'session_max_min' => 0, 'max_devices' => 1,
        'allow_downloads' => 0, 'block_screenshots' => 0, 'keep_history' => 1,
        'allow_pip' => 0, 'allow_background' => 0,
    ];
}

function rulesFor(int $userId): array {
    // כלל שפג תוקפו (היתר לשעה) פשוט אינו קיים מבחינת המנוע.
    return all("SELECT * FROM rules WHERE user_id = ? AND enabled = 1
                AND (expires_at = '' OR expires_at > ?)
                ORDER BY sort_order, id", [$userId, nowIso()]);
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
function addUsage(int $userId, string $tz, int $seconds, ?string $day = null): void {
    if ($seconds <= 0) return;
    $day ??= todayIn($tz);
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
function youTubeOwner(string $videoId): array {
    $row = one('SELECT channel_id, handle, fetched_at FROM video_owner
                WHERE platform = ? AND video_id = ?', [PLATFORM_YOUTUBE, $videoId]);

    if ($row) {
        if ($row['channel_id'] !== '' || $row['handle'] !== '') {
            return ['channel' => $row['channel_id'], 'handle' => $row['handle']];
        }
        // כישלון נשמר לשעה בלבד: ייתכן שהייתה תקלה רגעית.
        if (strtotime($row['fetched_at']) > time() - 3600) {
            return ['channel' => '', 'handle' => ''];
        }
    }

    $info = fetchYouTubeOwner($videoId);
    upsert('video_owner',
           ['platform' => PLATFORM_YOUTUBE, 'video_id' => $videoId],
           ['channel_id' => $info['channel'], 'handle' => $info['handle'],
            'title' => $info['title'], 'fetched_at' => nowIso()]);

    return ['channel' => $info['channel'], 'handle' => $info['handle']];
}

/** כל מה שהמנוע צריך על משתמש, במקום אחד. */
function ruleSetFor(int $userId): array {
    return [
        'rules'          => rulesFor($userId),
        'categories'     => categoryRulesFor($userId),
        'domain_map'     => domainMap(),
        'platforms'      => platformRulesFor($userId),
        'platform_items' => platformItemsFor($userId),
        'owner_of'       => fn(string $v) => youTubeOwner($v),   // ['channel'=>…, 'handle'=>…]
    ];
}

/**
 * טביעת אצבע של כל מה שהמכשיר אוכף (שלב 6).
 *
 * הפעימה מחזירה אותה, והמכשיר מושך מדיניות מחדש רק כשהיא השתנתה — כך אישור
 * של בקשה, או שינוי שההורה עשה, מגיעים תוך פעימה אחת (30 שניות) ולא רק בכניסה
 * הבאה לדפדפן. גם כלל "לשעה" שפג משנה אותה, כי rulesFor כבר לא מחזיר אותו.
 */
function policyRev(int $userId): string {
    $user = one('SELECT status FROM users WHERE id = ?', [$userId]);
    // גם כללי האפליקציות (app_rules): בלעדיהם חסימת אפליקציה לא שינתה את הטביעה, והמכשיר לא ידע
    // למשוך מדיניות חדשה עד שינוי אחר (סקירה, 2026-10-20).
    $apps = all('SELECT package, action FROM app_rules WHERE child_id = ? ORDER BY package', [$userId]);
    return substr(md5(json_encode([
        $user['status'] ?? '', policyFor($userId), rulesFor($userId), categoryRulesFor($userId),
        platformRulesFor($userId), platformItemsFor($userId), $apps,
    ])), 0, 12);
}
