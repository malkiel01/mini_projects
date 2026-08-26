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

/**
 * תיקיית הנתונים.
 *
 * ניתנת לעקיפה במשתנה סביבה כדי שהבדיקות יריצו את הסכימה האמיתית על
 * בסיס נתונים זמני. בלי זה שגיאת SQL הייתה מתגלה רק בשרת.
 */
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
    return $pdo;
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

/* ── שליפות ─────────────────────────────────────────────────────── */

/** מדיניות המשתמש, עם ברירות מחדל אם טרם נוצרה שורה. */
function policyFor(int $userId): array {
    return one('SELECT * FROM policies WHERE user_id = ?', [$userId]) ?? [
        'user_id' => $userId, 'mode' => MODE_KIOSK, 'timezone' => 'Asia/Jerusalem',
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

/** צובר שניות ליום הנוכחי. UPSERT כדי שלא יידרש SELECT לפני. */
function addUsage(int $userId, string $tz, int $seconds): void {
    if ($seconds <= 0) return;
    q('INSERT INTO usage (user_id, day, seconds) VALUES (?, ?, ?)
       ON CONFLICT(user_id, day) DO UPDATE SET seconds = seconds + excluded.seconds',
      [$userId, todayIn($tz), $seconds]);
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
