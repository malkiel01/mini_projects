<?php
/**
 * שפות — עברית, אנגלית, צרפתית, רוסית וערבית (2026-10-12).
 *
 * העברית היא שפת המקור: הטקסט העברי בקוד הוא גם המפתח. המילונים —
 * assets/lang/<שפה>.json — ממפים "טקסט עברי" ← תרגום. אין תרגום? חוזרת העברית.
 * אותם קבצים משמשים את הדפדפן (assets/js/i18n.js) ואת השרת (כאן).
 *
 * משתנים: {name} בתוך הטקסט — pm_t('נשלח מייל ל{name}', ['name' => $n]).
 * ערך משתנה שהוא טקסט עובר גם הוא דרך המילון — כך שם שלב שנזרע ("מדידה")
 * מתורגם, ומה שהמשתמש כתב בעצמו נשאר כמו שהוא.
 * רבים: תרגום יכול להיות אובייקט {"one": …, "few": …, "other": …} לפי {n}.
 *
 * הבדיקה: php tools/i18n-check.php — טקסט עברי שלא עובר תרגום, ותרגומים חסרים.
 */

declare(strict_types=1);

/** קוד ← [שם בשפה עצמה, כיוון, locale]. */
const PM_LANGS = [
    'he' => ['עברית', 'rtl', 'he-IL'], // i18n-none — שם השפה בשפה עצמה
    'en' => ['English', 'ltr', 'en-GB'],
    'fr' => ['Français', 'ltr', 'fr-FR'],
    'ru' => ['Русский', 'ltr', 'ru-RU'],
    'ar' => ['العربية', 'rtl', 'ar'],
];

function pm_lang_valid(?string $lang): string
{
    return isset(PM_LANGS[$lang ?? '']) ? (string)$lang : 'he';
}

/**
 * שפת הבקשה: מה שנקבע (pm_lang('en')), אחרת כותרת X-Lang מהדפדפן, אחרת עברית.
 * הודעות שגיאה ותשובות — בשפה הזו. מיילים — בשפת הנמען (pm_t עם $lang).
 */
function pm_lang(?string $set = null): string
{
    static $cur = null;
    if ($set !== null) $cur = pm_lang_valid($set);
    if ($cur === null) {
        $h = (string)($_SERVER['HTTP_X_LANG'] ?? '');
        $cur = isset(PM_LANGS[$h]) ? $h : 'he';
    }
    return $cur;
}

/**
 * שפת המשתמש (מנהל / מפתח) — למיילים אליו. users.lang, ואם עוד לא נבחרה — עברית.
 * $u — שורת משתמש (עם lang) או מזהה.
 */
function pm_user_lang(array|int $u): string
{
    if (is_array($u) && array_key_exists('lang', $u)) return pm_lang_valid((string)$u['lang']);
    $id = is_array($u) ? (int)($u['id'] ?? 0) : $u;
    $st = pm_db()->prepare('SELECT lang FROM users WHERE id = ?');
    $st->execute([$id]);
    return pm_lang_valid((string)$st->fetchColumn());
}

function pm_dict(string $lang): array
{
    static $cache = [];
    if ($lang === 'he') return [];
    if (!isset($cache[$lang])) {
        $f = __DIR__ . "/../assets/lang/$lang.json";
        $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        $cache[$lang] = is_array($d) ? $d : [];
    }
    return $cache[$lang];
}

/** קטגוריית רבים (CLDR, מקוצר) — לבחירת הצורה מתוך {"one", "few", …}. */
function pm_plural(string $lang, float|int $n): string
{
    $i = (int)abs($n);
    $int = (float)$i === abs((float)$n);
    return match ($lang) {
        'he' => $i === 1 && $int ? 'one' : ($i === 2 && $int ? 'two' : 'other'),
        'en' => $i === 1 && $int ? 'one' : 'other',
        'fr' => $i <= 1 ? 'one' : 'other',
        'ru' => !$int ? 'other' : ($i % 10 === 1 && $i % 100 !== 11 ? 'one'
            : ($i % 10 >= 2 && $i % 10 <= 4 && ($i % 100 < 12 || $i % 100 > 14) ? 'few' : 'many')),
        'ar' => !$int ? 'other' : ($i === 0 ? 'zero' : ($i === 1 ? 'one' : ($i === 2 ? 'two'
            : ($i % 100 >= 3 && $i % 100 <= 10 ? 'few' : ($i % 100 >= 11 ? 'many' : 'other'))))),
        default => 'other',
    };
}

/** {name} ← ערך. בלי תרגום — בשביל הטקסט העברי שנשמר (ציר זמן, יומן). */
function pm_fill(string $s, array $vars): string
{
    if (!$vars) return $s;
    $map = [];
    foreach ($vars as $k => $v) $map['{' . $k . '}'] = is_scalar($v) || $v === null ? (string)$v : '';
    return strtr($s, $map);
}

/** תרגום: הטקסט העברי ← השפה ($lang, או שפת הבקשה). */
function pm_t(string $s, array $vars = [], ?string $lang = null): string
{
    $lang = pm_lang_valid($lang ?? pm_lang());
    $out = $s;
    if ($lang !== 'he') {
        $tr = pm_dict($lang)[$s] ?? null;
        if (is_array($tr)) {
            $n = $vars['n'] ?? 0;
            $tr = $tr[pm_plural($lang, is_numeric($n) ? (float)$n : 0)] ?? $tr['other'] ?? null;
        }
        if (is_string($tr) && $tr !== '') $out = $tr;
        foreach ($vars as $k => $v) {
            if (is_string($v) && $v !== '') $vars[$k] = pm_t($v, [], $lang);
        }
    }
    return pm_fill($out, $vars);
}

/** כמה פריטים — לקבוצת ערכים (תוויות סטטוס וכד'). */
function pm_t_map(array $labels, ?string $lang = null): array
{
    return array_map(fn($v) => is_string($v) ? pm_t($v, [], $lang) : $v, $labels);
}
