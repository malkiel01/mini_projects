<?php
/**
 * מנוע ההרשאות — האם המשתמש הזה רשאי לפתוח את הכתובת הזו עכשיו.
 *
 * הכול כאן טהור: מקבל מערכים, מחזיר החלטה. אין בסיס נתונים ואין HTTP,
 * כדי שכל תנאי ייבדק בבידוד. זה הקובץ שחייב להיות נכון — הוא היחיד
 * שעומד בין משתמש לבין תוכן שלא הותר לו.
 *
 * ‏האכיפה עצמה כפולה בכוונה: האפליקציה בודקת מקומית לפי המדיניות
 * שקיבלה, והשרת בודק שוב בכל רענון. לקוח שנפרץ מקבל מדיניות ישנה
 * לכל היותר, ולא מדיניות שהמציא לעצמו.
 */

declare(strict_types=1);

/* ── מצבי גלישה ────────────────────────────────────────────────
 *
 * המצב הוא הרשאה של המשתמש, לא הגדרה של האפליקציה: אותה אפליקציה
 * היא קיוסק סגור למשתמש אחד ודפדפן חופשי לאחר.
 */
const MODE_KIOSK     = 'kiosk';      // רק האריחים שהוגדרו; אין שורת כתובת
const MODE_ALLOWLIST = 'allowlist';  // שורת כתובת, אבל רק מה שברשימה נפתח
const MODE_FREE      = 'free';       // הכול פתוח חוץ ממה שנאסר

const MODES = [MODE_KIOSK, MODE_ALLOWLIST, MODE_FREE];
const SCOPES = ['exact', 'domain', 'domain_plus'];

/** החלטה אחידה. $code הוא לזיהוי במבחנים וביומן; $reason למשתמש. */
function decision(bool $allow, string $code, string $reason = ''): array {
    return ['allow' => $allow, 'code' => $code, 'reason' => $reason];
}

/* ── נרמול כתובות ──────────────────────────────────────────────── */

/**
 * מפרק כתובת לצורה שאפשר להשוות.
 *
 * ‏www מוסר, האות מוקטנת, והיציאה הרגילה מושמטת. בלי זה
 * "HTTPS://WWW.Example.com:443/x" ו-"https://example.com/x" היו שתי
 * כתובות שונות, והמשתמש היה נחסם בגלל הבדל שאינו קיים.
 */
function normalizeUrl(string $url): ?array {
    $url = trim($url);
    if ($url === '') return null;

    // ‏"example.com/x" בלי סכימה היא כתובת שהמשתמש התכוון אליה.
    if (!preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) $url = 'https://' . $url;

    $p = parse_url($url);
    if (!$p || empty($p['host'])) return null;

    $scheme = strtolower($p['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) return null;

    $host = strtolower($p['host']);
    if (str_starts_with($host, 'www.')) $host = substr($host, 4);

    $path = $p['path'] ?? '/';
    if ($path === '') $path = '/';
    // סלאש בסוף אינו הבדל משמעותי בין כתובות.
    if ($path !== '/' ) $path = rtrim($path, '/');

    return [
        'scheme' => $scheme,
        'host'   => $host,
        'path'   => $path,
        'query'  => $p['query'] ?? '',
    ];
}

/** האם $host הוא הדומיין עצמו או תת-דומיין שלו. */
function hostMatches(string $host, string $domain): bool {
    if ($host === $domain) return true;
    return str_ends_with($host, '.' . $domain);
}

/**
 * האם הכלל חל על הכתובת.
 *
 * ‏$isMainFrame מבדיל בין ניווט למשאב נלווה, וזה ההבדל שבגללו
 * domain_plus קיים: דף לגיטימי טוען נגן, גופנים ותמונות מדומיינים
 * אחרים, ולחסום אותם פירושו לשבור את הדף שכן הותר.
 */
function ruleMatches(array $rule, array $url, bool $isMainFrame): bool {
    $pattern = normalizeUrl((string) $rule['pattern']);
    if (!$pattern) return false;

    $scope = (string) ($rule['scope'] ?? 'domain');

    if ($scope === 'domain_plus' && !$isMainFrame) {
        // משאב נלווה תחת כלל כזה — מותר מכל מקור.
        return true;
    }

    if (!hostMatches($url['host'], $pattern['host'])) return false;

    if ($scope === 'exact') {
        if ($url['path'] !== $pattern['path']) return false;
        // מחרוזת שאילתה בכלל היא דרישה; היעדרה בכלל אינו אוסר אותה בכתובת.
        if ($pattern['query'] !== '' && $url['query'] !== $pattern['query']) return false;
        return true;
    }

    return true;   // domain / domain_plus בניווט — כל הדומיין
}

/**
 * מכריע לפי הכללים בלבד, אחרי שכל תנאי החשבון כבר עברו.
 *
 * ‏deny גובר על allow תמיד ובלי קשר לסדר. כלל אוסר שאפשר לעקוף
 * בהוספת כלל מתיר אינו כלל אוסר.
 */
function matchRules(array $rules, string $mode, array $url, bool $isMainFrame): array {
    $allowHit = null;

    foreach ($rules as $rule) {
        if (!($rule['enabled'] ?? 1)) continue;
        if (!ruleMatches($rule, $url, $isMainFrame)) continue;

        if (($rule['action'] ?? 'allow') === 'deny') {
            return decision(false, 'rule_deny',
                'הכתובת הזו נחסמה עבורך' . ($rule['label'] ? " ({$rule['label']})" : ''));
        }
        $allowHit ??= $rule;
    }

    if ($allowHit) return decision(true, 'rule_allow');

    if ($mode === MODE_FREE) return decision(true, 'free_mode');

    return decision(false, 'not_listed', $mode === MODE_KIOSK
        ? 'הכתובת הזו אינה ברשימה שהוגדרה לך'
        : 'הכתובת הזו אינה ברשימת ההיתר שלך');
}

/* ── תנאי החשבון ───────────────────────────────────────────────── */

/**
 * האם החשבון פעיל ברגע הזה.
 *
 * ‏$now הוא DateTimeImmutable שמוזרק מבחוץ, ולא time(). בלעדיו אי אפשר
 * לבדוק חלון זמן או פקיעה בלי לחכות לשעה הנכונה.
 */
function accountState(array $user, DateTimeImmutable $now): array {
    $status = (string) ($user['status'] ?? 'pending');

    if ($status === 'pending') {
        return decision(false, 'pending', 'החשבון ממתין לאישור המנהל');
    }
    if ($status === 'suspended') {
        return decision(false, 'suspended', 'החשבון הושעה');
    }
    if ($status !== 'active') {
        return decision(false, 'inactive', 'החשבון אינו פעיל');
    }

    $expires = (string) ($user['expires_at'] ?? '');
    if ($expires !== '' && $now > new DateTimeImmutable($expires)) {
        return decision(false, 'expired', 'תוקף החשבון פג');
    }

    return decision(true, 'active');
}

/** ‏1 לראשון עד 64 לשבת — ביט לכל יום, כדי שיישב במספר אחד. */
function dayBit(DateTimeImmutable $t): int {
    return 1 << ((int) $t->format('w'));
}

/**
 * האם עכשיו בתוך חלון הזמן היומי.
 *
 * חלון שבו ההתחלה מאוחרת מהסיום חוצה חצות (22:00–02:00), ואז התנאי
 * מתהפך: מותר מחוץ לטווח ולא בתוכו.
 */
function withinWindow(array $policy, DateTimeImmutable $now): array {
    $mask = (int) ($policy['days_mask'] ?? 127);
    if ($mask !== 0 && ($mask & dayBit($now)) === 0) {
        return decision(false, 'day_blocked', 'היום אינו מהימים שהוגדרו לך');
    }

    $start = (string) ($policy['window_start'] ?? '');
    $end   = (string) ($policy['window_end'] ?? '');
    if ($start === '' || $end === '') return decision(true, 'no_window');

    $hhmm = $now->format('H:i');
    $inside = $start <= $end
        ? ($hhmm >= $start && $hhmm < $end)
        : ($hhmm >= $start || $hhmm < $end);   // חוצה חצות

    return $inside
        ? decision(true, 'in_window')
        : decision(false, 'outside_window', "הגישה מותרת לך בין $start ל-$end");
}

/** האם נותרה מכסת זמן להיום. 0 = בלי מכסה. */
function withinQuota(array $policy, int $secondsUsedToday): array {
    $quota = (int) ($policy['daily_quota_min'] ?? 0);
    if ($quota <= 0) return decision(true, 'no_quota');

    if ($secondsUsedToday >= $quota * 60) {
        return decision(false, 'quota_spent', "מכסת הצפייה היומית שלך ($quota דקות) נוצלה");
    }
    return decision(true, 'quota_left');
}

/** האם החיבור הנוכחי לא חרג ממשך הישיבה המותר. 0 = בלי הגבלה. */
function withinSession(array $policy, int $sessionSeconds): array {
    $cap = (int) ($policy['session_max_min'] ?? 0);
    if ($cap <= 0) return decision(true, 'no_session_cap');

    if ($sessionSeconds >= $cap * 60) {
        return decision(false, 'session_over', "משך הישיבה המותר ($cap דקות) הסתיים");
    }
    return decision(true, 'session_left');
}

/* ── ההכרעה השלמה ──────────────────────────────────────────────── */

/**
 * כל התנאים לפי סדר, ועוצר בראשון שנכשל.
 *
 * הסדר אינו שרירותי — הוא מהכללי לפרטני, כדי שהסיבה שתוצג תהיה
 * השורשית. לומר "הכתובת אינה ברשימה" למשתמש מושעה זו תשובה נכונה
 * טכנית ומטעה לחלוטין.
 *
 * ‏$ctx: ['url'=>string, 'main_frame'=>bool, 'used_today'=>int, 'session'=>int]
 */
function evaluate(array $user, array $policy, array $rules, DateTimeImmutable $now, array $ctx): array {
    $d = accountState($user, $now);
    if (!$d['allow']) return $d;

    $d = withinWindow($policy, $now);
    if (!$d['allow']) return $d;

    $d = withinQuota($policy, (int) ($ctx['used_today'] ?? 0));
    if (!$d['allow']) return $d;

    $d = withinSession($policy, (int) ($ctx['session'] ?? 0));
    if (!$d['allow']) return $d;

    // אין כתובת לבדוק — נשאלנו רק אם החשבון פעיל עכשיו.
    if (($ctx['url'] ?? '') === '') return decision(true, 'session_ok');

    $url = normalizeUrl((string) $ctx['url']);
    if (!$url) return decision(false, 'bad_url', 'הכתובת אינה תקינה');

    $mode = (string) ($policy['mode'] ?? MODE_KIOSK);
    return matchRules($rules, $mode, $url, (bool) ($ctx['main_frame'] ?? true));
}
