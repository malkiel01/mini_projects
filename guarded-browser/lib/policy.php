<?php
/**
 * מנוע ההרשאות — האם המשתמש הזה רשאי לפתוח את הכתובת הזו עכשיו.
 *
 * הכול כאן טהור: מקבל מערכים, מחזיר החלטה. אין בסיס נתונים ואין HTTP,
 * כדי שכל תנאי ייבדק בבידוד. גם פענוח "לאיזה ערוץ שייך הסרטון", שהוא
 * הדבר היחיד שדורש רשת, מוזרק פנימה כפונקציה — ולכן ניתן לזייף בבדיקה.
 *
 * ‏האכיפה עצמה כפולה בכוונה: האפליקציה בודקת מקומית לפי המדיניות
 * שקיבלה, והשרת בודק שוב בכל רענון. לקוח שנפרץ מקבל מדיניות ישנה
 * לכל היותר, ולא מדיניות שהמציא לעצמו.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/platforms.php';

/* ── שני צירים נפרדים ──────────────────────────────────────────────
 *
 * ‏mode עונה על "האם יש שורת כתובת"; posture עונה על "מה קורה כשאף
 * כלל לא מתאים". בגרסה הקודמת הם היו כרוכים יחד, ולכן "קיוסק שפתוח
 * לכול" או "דפדפן מלא שסגור כברירת מחדל" לא היו ניתנים להבעה.
 */
const MODE_KIOSK   = 'kiosk';     // אריחים בלבד; אין שורת כתובת
const MODE_BROWSER = 'browser';   // יש שורת כתובת
const MODES = [MODE_KIOSK, MODE_BROWSER];

const POSTURE_DENY  = 'deny_all';   // הכול חסום, נפתח לפי היתר
const POSTURE_ALLOW = 'allow_all';  // הכול פתוח, נחסם לפי איסור
const POSTURES = [POSTURE_DENY, POSTURE_ALLOW];

const SCOPES = ['exact', 'domain', 'domain_plus'];

/** החלטה אחידה. $code לזיהוי במבחנים וביומן; $reason למשתמש. */
function decision(bool $allow, string $code, string $reason = ''): array {
    return ['allow' => $allow, 'code' => $code, 'reason' => $reason];
}

/* ── נרמול כתובות ──────────────────────────────────────────────── */

/**
 * מפרק כתובת לצורה שאפשר להשוות.
 *
 * ‏www מוסר, האות מוקטנת, והיציאה הרגילה מושמטת. בלי זה
 * "HTTPS://WWW.Example.com/x" ו-"https://example.com/x" היו שתי
 * כתובות שונות, והמשתמש היה נחסם בגלל הבדל שאינו קיים.
 */
function normalizeUrl(string $url): ?array {
    $url = trim($url);
    if ($url === '') return null;

    if (!preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) $url = 'https://' . $url;

    $p = parse_url($url);
    if (!$p || empty($p['host'])) return null;

    $scheme = strtolower($p['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) return null;

    $host = strtolower($p['host']);
    if (str_starts_with($host, 'www.')) $host = substr($host, 4);

    $path = $p['path'] ?? '/';
    if ($path === '') $path = '/';
    if ($path !== '/') $path = rtrim($path, '/');

    return ['scheme' => $scheme, 'host' => $host, 'path' => $path,
            'query' => $p['query'] ?? '', 'full' => $url];
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

    if ($scope === 'domain_plus' && !$isMainFrame) return true;
    if (!hostMatches($url['host'], $pattern['host'])) return false;

    if ($scope === 'exact') {
        if ($url['path'] !== $pattern['path']) return false;
        if ($pattern['query'] !== '' && $url['query'] !== $pattern['query']) return false;
    }
    return true;
}

/**
 * כללי כתובות — הציר המפורש ביותר, ולכן הראשון שנבדק.
 *
 * מחזיר 'deny' / 'allow' / '' (אין התאמה). ‏deny גובר על allow תמיד
 * ובלי קשר לסדר: כלל אוסר שאפשר לעקוף בהוספת כלל מתיר אינו כלל אוסר.
 */
function matchUrlRules(array $rules, array $url, bool $isMainFrame): array {
    $allowHit = null;

    foreach ($rules as $rule) {
        if (!($rule['enabled'] ?? 1)) continue;
        if (!ruleMatches($rule, $url, $isMainFrame)) continue;

        if (($rule['action'] ?? 'allow') === 'deny') {
            return ['verdict' => 'deny', 'label' => (string) ($rule['label'] ?? '')];
        }
        $allowHit ??= $rule;
    }

    return $allowHit
        ? ['verdict' => 'allow', 'label' => (string) ($allowHit['label'] ?? '')]
        : ['verdict' => '', 'label' => ''];
}

/* ── קטגוריות ──────────────────────────────────────────────────── */

/**
 * הקטגוריות של דומיין, כולל התאמת תת-דומיין.
 *
 * ‏$map הוא [domain => [category,...]]. ההתאמה נעשית גם על דומיין-האב,
 * אחרת "m.youtube.com" לא היה מסווג כמו "youtube.com".
 */
function categoriesOfHost(string $host, array $map): array {
    $out = [];
    foreach ($map as $domain => $cats) {
        if (hostMatches($host, (string) $domain)) {
            foreach ((array) $cats as $c) $out[$c] = true;
        }
    }
    return array_keys($out);
}

/**
 * הכרעה לפי קטגוריות.
 *
 * ‏deny גובר: אתר שהוא גם "ספורט" (מותר) וגם "הימורים" (אסור) נחסם.
 * זה הכיוון הנכון — הסיווג המחמיר הוא זה שנועד לתפוס אותו.
 */
function matchCategories(string $host, array $map, array $categoryRules): array {
    $cats = categoriesOfHost($host, $map);
    if (!$cats) return ['verdict' => '', 'category' => ''];

    $allowHit = '';
    foreach ($cats as $c) {
        $action = $categoryRules[$c] ?? '';
        if ($action === 'deny')  return ['verdict' => 'deny', 'category' => $c];
        if ($action === 'allow' && $allowHit === '') $allowHit = $c;
    }
    return $allowHit
        ? ['verdict' => 'allow', 'category' => $allowHit]
        : ['verdict' => '', 'category' => ''];
}

/* ── פלטפורמות ─────────────────────────────────────────────────── */

/**
 * הכרעה ליוטיוב.
 *
 * ‏$items הוא ['channel'=>[id=>action], 'video'=>..., 'handle'=>..., 'playlist'=>...].
 * ‏$ownerOf היא פונקציה (videoId) => channelId|'' שמוזרקת מבחוץ, כדי
 * שהמנוע יישאר טהור ושהבדיקות לא ידרשו רשת.
 */
function youTubeVerdict(array $url, array $rule, array $items, bool $isMainFrame,
                        ?callable $ownerOf = null): array {
    $mode = (string) ($rule['mode'] ?? 'off');

    if ($mode === 'off') {
        return decision(false, 'yt_off', 'יוטיוב חסום בחשבון שלך');
    }

    // משאבי הנגן עצמם אינם ניווט. בלי המעבר הזה, מצב מוגבל היה חוסם
    // את הווידאו של הסרטון שכן אושר, והמשתמש היה רואה מסך שחור.
    if (!$isMainFrame && isYouTubeAsset($url['full'])) {
        return decision(true, 'yt_asset');
    }

    $parsed = parseYouTube($url['full']);
    $kind   = $parsed['kind'];
    $id     = $parsed['id'];

    $itemAction = function (string $k, string $v) use ($items): string {
        return (string) ($items[$k][$v] ?? '');
    };

    // איסור מפורש על פריט גובר גם במצב "הכול פתוח".
    if ($id !== '') {
        $direct = $itemAction($kind === 'shorts' ? 'video' : $kind, $id);
        if ($direct === 'deny') {
            return decision(false, 'yt_item_denied', 'הפריט הזה ביוטיוב חסום עבורך');
        }
    }

    if ($mode === 'full') return decision(true, 'yt_full');

    /* ── מצב מוגבל: רק מה שאושר ─────────────────────────────────── */

    if ($kind === 'search') {
        return ($rule['allow_search'] ?? 0)
            ? decision(true, 'yt_search')
            : decision(false, 'yt_no_search', 'החיפוש ביוטיוב חסום עבורך');
    }

    if ($kind === 'home' || $kind === 'other') {
        return decision(false, 'yt_no_browse',
            'אפשר לפתוח רק את הערוצים והסרטונים שאושרו לך, לא את יוטיוב עצמו');
    }

    if ($kind === 'shorts' && !($rule['allow_shorts'] ?? 0)) {
        return decision(false, 'yt_no_shorts', 'סרטוני Shorts חסומים בחשבון שלך');
    }

    if ($kind === 'channel' || $kind === 'handle' || $kind === 'playlist') {
        return $itemAction($kind, $id) === 'allow'
            ? decision(true, 'yt_item_allowed')
            : decision(false, 'yt_not_approved', 'הערוץ הזה אינו ברשימה שאושרה לך');
    }

    if ($kind === 'video' || $kind === 'shorts') {
        if ($itemAction('video', $id) === 'allow') return decision(true, 'yt_video_allowed');

        // הסרטון עצמו לא אושר — אולי הערוץ שלו כן. מכתובת אי אפשר
        // לדעת, ולכן שואלים את יוטיוב (התשובה נשמרת במטמון).
        if (!$ownerOf) {
            return decision(false, 'yt_owner_unknown',
                'לא ניתן לבדוק לאיזה ערוץ הסרטון שייך');
        }
        $channel = (string) $ownerOf($id);

        if ($channel === '') {
            // כישלון פענוח נסגר ולא נפתח: היתר שניתן מחוסר ידיעה אינו היתר.
            return decision(false, 'yt_owner_unknown',
                'לא הצלחנו לוודא לאיזה ערוץ הסרטון שייך, ולכן הוא לא נפתח');
        }
        if ($itemAction('channel', $channel) === 'deny') {
            return decision(false, 'yt_item_denied', 'הערוץ של הסרטון הזה חסום עבורך');
        }
        return $itemAction('channel', $channel) === 'allow'
            ? decision(true, 'yt_channel_allowed')
            : decision(false, 'yt_not_approved', 'הסרטון הזה אינו מערוץ שאושר לך');
    }

    return decision(false, 'yt_not_approved', 'הכתובת הזו ביוטיוב אינה מאושרת');
}

/* ── סוגי תוכן ─────────────────────────────────────────────────── */

/**
 * חסימה לפי *מה* נטען, ולא לפי לאן פונים.
 *
 * נבדק רק אחרי שכללי הכתובות לא נתנו היתר מפורש — כך "לחסום ווידאו
 * בכל מקום חוץ מהאתר הזה" ניתן להבעה בלי חריג מיוחד.
 */
function matchContentType(array $policy, string $url, string $declared = ''): array {
    $blocked = array_filter(array_map('trim',
        explode(',', (string) ($policy['blocked_types'] ?? ''))));
    if (!$blocked) return decision(true, 'no_type_block');

    $type = contentTypeOf($url, $declared);
    if ($type === '' || !in_array($type, $blocked, true)) {
        return decision(true, 'type_ok');
    }

    $label = contentTypeCatalog()[$type]['label'] ?? $type;
    return decision(false, 'type_blocked', "תוכן מסוג \"$label\" חסום בחשבון שלך");
}

/* ── תנאי החשבון ───────────────────────────────────────────────── */

function accountState(array $user, DateTimeImmutable $now): array {
    $status = (string) ($user['status'] ?? 'pending');

    if ($status === 'pending')   return decision(false, 'pending', 'החשבון ממתין לאישור המנהל');
    if ($status === 'suspended') return decision(false, 'suspended', 'החשבון הושעה');
    if ($status !== 'active')    return decision(false, 'inactive', 'החשבון אינו פעיל');

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
        : ($hhmm >= $start || $hhmm < $end);

    return $inside
        ? decision(true, 'in_window')
        : decision(false, 'outside_window', "הגישה מותרת לך בין $start ל-$end");
}

function withinQuota(array $policy, int $secondsUsedToday): array {
    $quota = (int) ($policy['daily_quota_min'] ?? 0);
    if ($quota <= 0) return decision(true, 'no_quota');

    return $secondsUsedToday >= $quota * 60
        ? decision(false, 'quota_spent', "מכסת הצפייה היומית שלך ($quota דקות) נוצלה")
        : decision(true, 'quota_left');
}

function withinSession(array $policy, int $sessionSeconds): array {
    $cap = (int) ($policy['session_max_min'] ?? 0);
    if ($cap <= 0) return decision(true, 'no_session_cap');

    return $sessionSeconds >= $cap * 60
        ? decision(false, 'session_over', "משך הישיבה המותר ($cap דקות) הסתיים")
        : decision(true, 'session_left');
}

/* ── ההכרעה השלמה ──────────────────────────────────────────────── */

/**
 * כל התנאים לפי סדר, ועוצר בראשון שמכריע.
 *
 * הסדר הוא מהמפורש לכללי, וזו ההחלטה המרכזית כאן:
 *
 *   1. מצב החשבון       — מושעה, פג תוקף
 *   2. תנאי זמן         — יום, חלון, מכסה, ישיבה
 *   3. כלל כתובת אוסר   — הדבר המפורש ביותר שיש
 *   4. כלל כתובת מתיר   — ומכאן מותר, בלי בדיקות נוספות
 *   5. פלטפורמה         — יוטיוב וכדומה, היתר ברמת ערוץ וסרטון
 *   6. סוג תוכן         — מה נטען, לא לאן פונים
 *   7. קטגוריה אוסרת
 *   8. קטגוריה מתירה
 *   9. ברירת המחדל      — posture
 *
 * מי שהופך שניים מהשלבים האלה מקבל מערכת שאי אפשר להסביר: כלל שהמנהל
 * כתב במפורש חייב לגבור על סיווג אוטומטי, ואיסור חייב לגבור על היתר.
 *
 * ‏$ctx: url, main_frame, used_today, session, content_type
 * ‏$set: rules, categories, domain_map, platforms, platform_items, owner_of
 */
function evaluate(array $user, array $policy, array $set, DateTimeImmutable $now, array $ctx): array {
    $d = accountState($user, $now);
    if (!$d['allow']) return $d;

    foreach ([withinWindow($policy, $now),
              withinQuota($policy, (int) ($ctx['used_today'] ?? 0)),
              withinSession($policy, (int) ($ctx['session'] ?? 0))] as $d) {
        if (!$d['allow']) return $d;
    }

    // אין כתובת — נשאלנו רק אם החשבון פעיל עכשיו.
    if (($ctx['url'] ?? '') === '') return decision(true, 'session_ok');

    $url = normalizeUrl((string) $ctx['url']);
    if (!$url) return decision(false, 'bad_url', 'הכתובת אינה תקינה');

    $main    = (bool) ($ctx['main_frame'] ?? true);
    $posture = (string) ($policy['posture'] ?? POSTURE_DENY);

    // 3–4. כללי כתובות
    $u = matchUrlRules($set['rules'] ?? [], $url, $main);
    if ($u['verdict'] === 'deny') {
        return decision(false, 'rule_deny',
            'הכתובת הזו נחסמה עבורך' . ($u['label'] ? " ({$u['label']})" : ''));
    }
    if ($u['verdict'] === 'allow') return decision(true, 'rule_allow');

    // 5. פלטפורמה
    $platform = platformOf($url['host']);
    if ($platform !== '' && isset($set['platforms'][$platform])) {
        if ($platform === PLATFORM_YOUTUBE) {
            return youTubeVerdict($url, $set['platforms'][$platform],
                                  $set['platform_items'][$platform] ?? [], $main,
                                  $set['owner_of'] ?? null);
        }
    }

    // 6. סוג תוכן
    $d = matchContentType($policy, $url['full'], (string) ($ctx['content_type'] ?? ''));
    if (!$d['allow']) return $d;

    // 7–8. קטגוריות
    $c = matchCategories($url['host'], $set['domain_map'] ?? [], $set['categories'] ?? []);
    if ($c['verdict'] === 'deny') {
        return decision(false, 'category_deny',
            'הקטגוריה "' . categoryLabel($c['category']) . '" חסומה בחשבון שלך');
    }
    if ($c['verdict'] === 'allow') return decision(true, 'category_allow');

    // 9. ברירת המחדל
    return $posture === POSTURE_ALLOW
        ? decision(true, 'posture_allow')
        : decision(false, 'not_listed',
            (string) ($policy['mode'] ?? MODE_KIOSK) === MODE_KIOSK
                ? 'הכתובת הזו אינה ברשימה שהוגדרה לך'
                : 'הכתובת הזו אינה ברשימת ההיתר שלך');
}
