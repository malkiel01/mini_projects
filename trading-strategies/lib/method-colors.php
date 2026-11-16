<?php
/**
 * צבע לכל שיטה (spec-stage-a 18.5, S5; הכלל של יותר מ-8 שיטות — 2.20).
 *
 * - **הפלטה** [שלנו]: 8 צבעים, משתני CSS ב-assets/css/app.css (בבהיר ובכהה) — כאן רק השמות. הסדר = סדר ברירת המחדל:
 *   שיטה חדשה מקבלת את הפנוי הראשון.
 * - **נשמר בפרופיל** (פרק 5) כ-method_colors: {method_id: {color, at}} — at = מתי השיטה סומנה בפעם האחרונה (שניות unix).
 *   [שלנו] העמודה יושבת ב-users.method_colors (מיגרציה 10, ALTER בלבד) — מאותה סיבה כמו market_tabs (lib/tabs.php).
 * - **המבנה הישן** ({method_id: צבע}, S5) נקרא בסלחנות: צבע בלי at → {color, at: 0} ("לא סומנה מזמן"). לא נמחק ולא
 *   מומר במסד; השמירה הבאה של המשתמש כותבת את המבנה החדש. רשומה פגומה אחת לא מוחקת את השאר.
 * - **צבע שמור לשיטה רק כל עוד היא זמינה** (2.20). שיטה שכובתה או שהוסרה נשארת במפה, והצבע שלה נחשב פנוי — ולכן
 *   שתי שיטות **זמינות** לא יחלקו צבע (השרת אוכף), אבל שיטה זמינה ושיטה כבויה — כן.
 * - **בלי צבע פנוי:** השיטה החדשה מקבלת את הצבע של השיטה הזמינה שלא סומנה הכי הרבה זמן (at הקטן), וזו יורדת מהמפה.
 *   ההחלטה — בדפדפן (assets/js/forecast.js, colorFor); השרת רק אוכף.
 */

declare(strict_types=1);

const TS_METHOD_PALETTE = ['--brand', '--m2', '--m3', '--m4', '--m5', '--m6', '--m7', '--m8'];   // = PALETTE ב-forecast.js
const TS_METHOD_COLORS_MAX = 64;   // [שלנו] תקרה לגודל המפה — שיטות כבויות נשארות בה

/** מזהה שיטה תקין למפה */
function ts_method_color_id(mixed $id): bool
{
    return is_string($id) && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/i', $id) === 1;
}

/** רשומה אחת, בכל אחד משני המבנים → {color, at}, או null כשהיא פגומה */
function ts_method_color_entry(mixed $v): ?array
{
    if (is_string($v)) return in_array($v, TS_METHOD_PALETTE, true) ? ['color' => $v, 'at' => 0] : null;   // המבנה הישן
    if (!is_array($v) || !is_string($v['color'] ?? null) || !in_array($v['color'], TS_METHOD_PALETTE, true)) return null;
    $at = $v['at'] ?? 0;
    if (!is_int($at) || $at < 0) return null;
    return ['color' => $v['color'], 'at' => $at];
}

/** {method_id: {color, at}}. אין — אובייקט ריק (ב-JSON `{}`, לא `[]`). קריאה סלחנית: רשומה פגומה מדולגת, השאר נשארות */
function ts_method_colors(int $uid): object
{
    $st = ts_db()->prepare('SELECT method_colors FROM users WHERE id = ?');
    $st->execute([$uid]);
    $raw = $st->fetchColumn();
    if (!is_string($raw) || $raw === '') return (object)[];
    try {
        $in = json_decode($raw, true, 5, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return (object)[];   // שמור פגום — לא מפיל את הגרף
    }
    $out = [];
    if (is_array($in) && !array_is_list($in)) {
        foreach ($in as $id => $v) if (ts_method_color_id($id) && ($e = ts_method_color_entry($v))) $out[$id] = (object)$e;
    }
    return (object)$out;
}

/**
 * בדיקת המפה לשמירה. $available — המזהים של השיטות שהמשתמש רואה עכשיו: ביניהן אסור צבע כפול. זורק TsInvalid על כל
 * חריגה — לא מתקן בשקט. מקבל גם את המבנה הישן (צבע בלי at — מדפדפן שעוד לא התעדכן).
 */
function ts_method_colors_clean(mixed $in, array $available, ?int $now = null): array
{
    if ($in === [] || $in === null) return [];
    if (!is_array($in) || array_is_list($in)) throw new TsInvalid('צבעי השיטות — מפה לא תקינה');
    if (count($in) > TS_METHOD_COLORS_MAX) throw new TsInvalid('צבעי השיטות — יותר מדי שיטות');
    $now ??= time();
    $out = [];
    $taken = [];
    foreach ($in as $id => $v) {
        if (!ts_method_color_id($id)) throw new TsInvalid('צבעי השיטות — מזהה שיטה לא תקין');
        $e = ts_method_color_entry($v) ?? throw new TsInvalid('צבע לא מהפלטה');
        if ($e['at'] > $now + 86400) throw new TsInvalid('צבעי השיטות — זמן לא תקין');
        if (in_array($id, $available, true)) {
            if (isset($taken[$e['color']])) throw new TsInvalid('הצבע תפוס על ידי שיטה אחרת');
            $taken[$e['color']] = true;
        }
        $out[$id] = $e;
    }
    return $out;
}

function ts_method_colors_save(int $uid, mixed $colors): object
{
    $clean = ts_method_colors_clean($colors, array_column(ts_methods_visible($uid), 'id'), ts_now());
    ts_db()->prepare('UPDATE users SET method_colors = ? WHERE id = ?')
        ->execute([$clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null, $uid]);
    return ts_method_colors($uid);
}
