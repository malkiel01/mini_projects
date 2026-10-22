<?php
/**
 * מצב למידה ביוטיוב (אפיון, שלב 12 — ה): "בכפוף ליכולת טכנית מאומתת".
 *
 *   הגבול שלא תלוי בדף — במנוע (youTubeVerdict, mode=learn): רק הסרטונים שבתור, בלי Shorts, ערוצים, חיפוש
 *   ודף הבית. נבדק כאן ב-tests/policy.php ובמכשיר ב-PolicyEngineTest.
 *   מה שתלוי בדף של יוטיוב — הסתרת ההמלצות, כיבוי הניגון האוטומטי ועצירה בסוף סרטון (YouTubeLearn.kt) —
 *   יוטיוב יכול לשנות בכל רגע. לכן הסקריפט *בודק בחזרה* מה הצליח, והמכשיר מדווח (do=yt_learn_report).
 *   ההורה רואה "אומת במכשיר" רק על סמך הדוח הזה, ועם הזמן שלו.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** היכולות שהסקריפט בודק. ‏true — אומת בדף; false — נכשל; null — לא נבדק (למשל אין נגן בדף). */
const YT_LEARN_CHECKS = [
    'related'  => 'ההמלצות מוסתרות',
    'autoplay' => 'הניגון האוטומטי כבוי',
    'end_stop' => 'עצירה בסוף הסרטון',
];

/** דוח מהמכשיר. נשמר רק מה שמוכר. */
function ytLearnReportSave(int $deviceRowId, array $r): void {
    $clean = [];
    foreach (array_keys(YT_LEARN_CHECKS) as $k) {
        $clean[$k] = array_key_exists($k, $r) && $r[$k] !== null ? (bool) $r[$k] : null;
    }
    $clean['version'] = (int) ($r['version'] ?? 0);
    q('UPDATE devices SET yt_learn = ?, yt_learn_at = ? WHERE id = ?', [json_encode($clean), nowIso(), $deviceRowId]);
}

/**
 * לכל יכולת: [{key, label, state: verified|failed|unknown, at, device}] — המכשיר העדכני ביותר שדיווח.
 * דוח בן יותר משבוע — "לא ידוע" (יוטיוב משתנה).
 */
function ytLearnStatus(int $childId, ?int $now = null): array {
    $now ??= time();
    $d = one("SELECT device_name, yt_learn, yt_learn_at FROM devices WHERE user_id = ? AND yt_learn_at != ''
              ORDER BY yt_learn_at DESC LIMIT 1", [$childId]);
    $r = $d && strtotime($d['yt_learn_at']) > $now - 7 * 86400 ? json_decode((string) $d['yt_learn'], true) : null;
    $out = [];
    foreach (YT_LEARN_CHECKS as $k => $label) {
        $v = is_array($r) ? ($r[$k] ?? null) : null;
        $out[] = ['key' => $k, 'label' => $label, 'state' => $v === true ? 'verified' : ($v === false ? 'failed' : 'unknown'),
                  'at' => $v === null ? '' : (string) $d['yt_learn_at'], 'device' => $v === null ? '' : (string) $d['device_name']];
    }
    return $out;
}
