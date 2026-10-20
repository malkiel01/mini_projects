<?php
/**
 * יומן אבחון — מה קורה בכל מכשיר, כדי לא לנחש: באיזו "עטיפה" נפתח דף (דפדפן, PWA מותקן, APK — WebView
 * או TWA), האם עוגיית הכניסה הגיעה, למה השער הפנה לכניסה, ולאן הלכו אחרי הכניסה.
 *
 * שני מקורות, טבלה אחת (diag_log במסד של הפלטפורמה):
 *   - השער (lib/gate.php, gate.php, gate-prepend.php): כל פתיחת דף באפליקציה מחוברת — "gate:ok" או
 *     הסירוב ("gate:login", "gate:forbidden"), עם מה שהשרת רואה: User-Agent, Referer, האם נשלחה עוגייה.
 *   - הדפדפן (assets/diag.js, POST api.php?action=diag): מה שרק הדף יודע — display-mode, document.referrer
 *     (android-app://… — נפתח מאפליקציית אנדרואיד), WebView, מזהה המכשיר, ואירועים של הפלטפורמה.
 * המנהל רואה ב-⚙️ ← 📜 יומן, ומעתיק לצ'אט. נשמר 14 יום, עד 20,000 שורות.
 *
 * רץ גם בתוך התהליך של כלי (gate-prepend) — לכן namespace משלו, כמו lib/gate.php. כשל ברישום לעולם
 * לא עוצר דף.
 */

declare(strict_types=1);

namespace Platform\Diag;

const KEEP_DAYS = 14;
const MAX_ROWS = 20000;

/** ערכים רגישים בכתובת (אסימונים, קודים) — נמחקים לפני שמירה. השמות נשארים. */
function redact(string $url): string
{
    $url = mb_substr($url, 0, 500);
    return (string)preg_replace('/([?&#](?:[^=&#]*(?:token|key|code|pass|secret|handoff|verify|reset|invite)[^=&#]*)=)[^&#]*/i', '$1…', $url);
}

function ensure(\PDO $db): void
{
    static $done = false;
    if ($done) return;
    $db->exec('CREATE TABLE IF NOT EXISTS diag_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        at TEXT NOT NULL, event TEXT NOT NULL, app TEXT, user_id INTEGER, device TEXT,
        ua TEXT, path TEXT, referrer TEXT, info TEXT)');
    $db->exec('CREATE INDEX IF NOT EXISTS diag_at ON diag_log (at)');
    $done = true;
}

/** שורה ביומן. $row: event, app, user_id, device, ua, path, referrer, info (מערך). */
function write(\PDO $db, array $row): void
{
    try {
        ensure($db);
        $db->exec('PRAGMA busy_timeout = 2000');
        $db->prepare('INSERT INTO diag_log (at, event, app, user_id, device, ua, path, referrer, info) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([
                gmdate('Y-m-d\TH:i:s\Z'),
                mb_substr((string)$row['event'], 0, 60),
                isset($row['app']) ? mb_substr((string)$row['app'], 0, 60) : null,
                $row['user_id'] ?? null,
                isset($row['device']) ? mb_substr((string)$row['device'], 0, 64) : null,
                mb_substr((string)($row['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 400),
                redact((string)($row['path'] ?? '')),
                redact((string)($row['referrer'] ?? '')),
                json_encode($row['info'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        if (random_int(1, 200) === 1) {
            $db->prepare('DELETE FROM diag_log WHERE at < ?')->execute([gmdate('Y-m-d\TH:i:s\Z', time() - KEEP_DAYS * 86400)]);
            $db->exec('DELETE FROM diag_log WHERE id <= (SELECT MAX(id) FROM diag_log) - ' . MAX_ROWS);
        }
    } catch (\Throwable $e) {
        error_log('platform diag: ' . $e->getMessage());
    }
}

/** מה שהשרת רואה בבקשה — לשורות של השער. */
function request_info(bool $hasCookie, bool $validSession): array
{
    $h = fn(string $k) => isset($_SERVER[$k]) ? mb_substr((string)$_SERVER[$k], 0, 120) : null;
    return array_filter([
        'cookie'      => $hasCookie ? ($validSession ? 'valid' : 'invalid/expired') : 'none',
        'fetch_site'  => $h('HTTP_SEC_FETCH_SITE'),
        'fetch_mode'  => $h('HTTP_SEC_FETCH_MODE'),
        'requested_with' => $h('HTTP_X_REQUESTED_WITH'),   // WebView ישן שולח את שם החבילה
        'method'      => $h('REQUEST_METHOD'),
    ], fn($v) => $v !== null);
}
