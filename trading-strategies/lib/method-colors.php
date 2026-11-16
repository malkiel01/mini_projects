<?php
/**
 * צבע לכל שיטה (spec-stage-a 18.5, S5).
 *
 * - **הפלטה** [שלנו]: 8 צבעים, משתני CSS ב-assets/css/app.css (בבהיר ובכהה) — כאן רק השמות. הסדר = סדר ברירת המחדל:
 *   שיטה חדשה מקבלת את הפנוי הראשון.
 * - **נשמר בפרופיל** (פרק 5) כ-method_colors: {method_id: צבע}. [שלנו] העמודה יושבת ב-users.method_colors (מיגרציה 10,
 *   ALTER בלבד) — מאותה סיבה כמו market_tabs (lib/tabs.php): שורה ב-user_profile מסמנת "הפרופיל נשמר".
 * - **שתי שיטות, אותו צבע — אסור.** המפה נשמרת שלמה, וכל צבע מופיע בה פעם אחת לכל היותר (ולכן לכל היותר 8 שיטות).
 *   מי שבוחר צבע של שיטה אחרת — הדפדפן מוריד אותו ממנה לפני השמירה; השרת רק אוכף.
 * - שיטה שכובתה בינתיים נשארת במפה — כשתחזור, תחזור בצבע שלה.
 */

declare(strict_types=1);

const TS_METHOD_PALETTE = ['--brand', '--m2', '--m3', '--m4', '--m5', '--m6', '--m7', '--m8'];   // = PALETTE ב-forecast.js

/** {method_id: צבע}. אין — אובייקט ריק (ב-JSON `{}`, לא `[]`) */
function ts_method_colors(int $uid): object
{
    $st = ts_db()->prepare('SELECT method_colors FROM users WHERE id = ?');
    $st->execute([$uid]);
    $raw = $st->fetchColumn();
    if (!is_string($raw) || $raw === '') return (object)[];
    try {
        return (object)ts_method_colors_clean(json_decode($raw, true, 4, JSON_THROW_ON_ERROR));
    } catch (Throwable) {
        return (object)[];   // שמור פגום — לא מפיל את הגרף
    }
}

/** בדיקת המפה. זורק TsInvalid על כל חריגה — לא מתקן בשקט */
function ts_method_colors_clean(mixed $in): array
{
    if ($in === [] || $in === null) return [];
    if (!is_array($in) || array_is_list($in)) throw new TsInvalid('צבעי השיטות — מפה לא תקינה');
    if (count($in) > count(TS_METHOD_PALETTE)) throw new TsInvalid('צבעי השיטות — יותר שיטות מצבעים');
    $out = [];
    foreach ($in as $id => $color) {
        if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/i', $id)) throw new TsInvalid('צבעי השיטות — מזהה שיטה לא תקין');
        if (!is_string($color) || !in_array($color, TS_METHOD_PALETTE, true)) throw new TsInvalid('צבע לא מהפלטה');
        if (in_array($color, $out, true)) throw new TsInvalid('הצבע תפוס על ידי שיטה אחרת');
        $out[$id] = $color;
    }
    return $out;
}

function ts_method_colors_save(int $uid, mixed $colors): object
{
    $clean = ts_method_colors_clean($colors);
    ts_db()->prepare('UPDATE users SET method_colors = ? WHERE id = ?')
        ->execute([$clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null, $uid]);
    return ts_method_colors($uid);
}
