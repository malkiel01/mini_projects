<?php
/**
 * זמן מסך לאפליקציות (בעל המערכת, 2026-10-22: "תתחיל"): מגבלה יומית בדקות לכל אפליקציה.
 *
 *   ההגבלה נאכפת במכשיר (AppTimeMath.kt, ‏AppGuardService): שירות הנגישות יודע איזו אפליקציה בחזית, וטיימר
 *   סופר זמן רק כשהמסך דלוק. בסוף הזמן — מסך חסימה "נגמר הזמן להיום", עם בקשה להורה (היתר זמני גובר על המגבלה).
 *   **בלי שירות נגישות אין מדידה ואין אכיפה** — גם ב"בעל מכשיר". ההורה רואה זאת (appsList, ‏access_missing).
 *   היום — לפי אזור הזמן של הילד, בשעון האמין של המכשיר.
 *
 *   המכשיר מדווח את הזמן שנצבר (do=app_usage) — ההורה רואה כמה כל אפליקציה שימשה היום. הדיווח מצטבר במכשיר,
 *   ולכן השרת שומר את המקסימום: דיווח כפול או ישן לא מוריד ולא מכפיל.
 */

declare(strict_types=1);

require_once __DIR__ . '/appcontrol.php';

const APP_LIMIT_MAX_MIN = 720;

/** {package: דקות ליום}. */
function appLimits(int $childId): array {
    $out = [];
    foreach (all('SELECT package, daily_min FROM app_limits WHERE child_id = ? ORDER BY package', [$childId]) as $r) {
        $out[$r['package']] = (int) $r['daily_min'];
    }
    return $out;
}

/** ‏$minutes = 0 — בלי מגבלה. מוחזרת שגיאה, או ''. */
function appLimitSave(int $childId, string $pkg, int $minutes): string {
    $pkg = cleanPackage($pkg);
    if ($pkg === '') return 'שם חבילה לא תקין';
    if ($why = alwaysOpen($pkg)) return "אי אפשר להגביל — $why";
    if ($minutes < 0 || $minutes > APP_LIMIT_MAX_MIN) return 'מגבלה: 1–' . APP_LIMIT_MAX_MIN . ' דקות ביום (0 — בלי מגבלה)';
    if ($minutes === 0) { q('DELETE FROM app_limits WHERE child_id = ? AND package = ?', [$childId, $pkg]); return ''; }
    upsert('app_limits', ['child_id' => $childId, 'package' => $pkg], ['daily_min' => $minutes], ['created_at' => nowIso()]);
    return '';
}

/** היום של הילד (YYYY-MM-DD), באזור הזמן שלו. */
function appUsageDay(int $childId, ?int $now = null): string {
    $tz = new DateTimeZone((string) (policyFor($childId)['timezone'] ?? 'Asia/Jerusalem'));
    return (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz)->format('Y-m-d');
}

/** דיווח מהמכשיר: {day, apps: {package: שניות}}. רק היום או אתמול; לכל חבילה — המקסימום. מוחזר כמה נשמרו. */
function appUsageSave(int $childId, array $r, ?int $now = null): int {
    $day = (string) ($r['day'] ?? '');
    $today = appUsageDay($childId, $now);
    $yesterday = appUsageDay($childId, ($now ?? time()) - 86400);
    if ($day !== $today && $day !== $yesterday) return 0;
    $n = 0;
    foreach (array_slice((array) ($r['apps'] ?? []), 0, 300, true) as $pkg => $sec) {
        $pkg = cleanPackage((string) $pkg);
        $sec = max(0, min(86400, (int) $sec));
        if ($pkg === '' || $sec === 0) continue;
        // בלי upsert של SQLite 3.24 (tests/integration.php, "תאימות SQLite") — הכנסה, ואז המקסימום.
        q('INSERT OR IGNORE INTO app_usage (child_id, day, package, seconds) VALUES (?,?,?,0)', [$childId, $day, $pkg]);
        // ‏CAST: הפרמטר נקשר כטקסט, וב-SQLite טקסט "גדול" מכל מספר — ‏MAX(600, '300') היה מחזיר '300'.
        q('UPDATE app_usage SET seconds = CAST(? AS INTEGER) WHERE child_id = ? AND day = ? AND package = ? AND seconds < CAST(? AS INTEGER)',
          [$sec, $childId, $day, $pkg, $sec]);
        $n++;
    }
    q('DELETE FROM app_usage WHERE child_id = ? AND day < ?', [$childId, appUsageDay($childId, ($now ?? time()) - 30 * 86400)]);
    return $n;
}

/** {package: שניות} — היום. */
function appUsageToday(int $childId, ?int $now = null): array {
    $out = [];
    foreach (all('SELECT package, seconds FROM app_usage WHERE child_id = ? AND day = ?', [$childId, appUsageDay($childId, $now)]) as $r) {
        $out[$r['package']] = (int) $r['seconds'];
    }
    return $out;
}
