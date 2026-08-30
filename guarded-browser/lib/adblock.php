<?php
/**
 * חסימת פרסומות.
 *
 * ציר נפרד מהקטגוריות, ובכוונה: קטגוריה "פרסום" קובעת אם מותר
 * *לנווט* לאתר פרסומי, וזה כמעט אף פעם לא מה שקורה. פרסומת אמיתית
 * היא משאב שנטען בתוך דף שהמשתמש כן ביקש — ולכן היא נבדקת לפני
 * כללי הכתובות, ולא אחריהם. אחרת "התר את האתר הזה" היה מחזיר את
 * כל הפרסומות שבו.
 */

declare(strict_types=1);

/**
 * ארבעה סוגים, כי הם נחסמים במקומות שונים לגמרי.
 *
 * מי שמציע מתג יחיד "חסום פרסומות" מבטיח משהו שהוא לא מקיים:
 * חסימת רשת אינה עוצרת פרסומת שמוגשת מאותו דומיין כמו התוכן, וזה
 * בדיוק המקרה של יוטיוב.
 */
function adBlockCatalog(): array {
    return [
        'network'  => ['חסימת רשת', '🚫',
                       'לא לטעון כלל מדומייני פרסום ומעקב. זו החסימה היעילה ביותר.'],
        'cosmetic' => ['הסתרת שטחי פרסום', '👁️',
                       'להסתיר באנרים ומסגרות שנשארו, כדי שלא יישארו חורים בדף.'],
        'youtube'  => ['פרסומות ביוטיוב', '⏭️',
                       'פרסומת ביוטיוב מוגשת מאותו מקור כמו הסרטון, ולכן היא מדולגת ולא נחסמת.'],
        'popups'   => ['חלונות קופצים', '🪟',
                       'לחסום ניווט לחלונות ולדפי ביניים פרסומיים.'],
    ];
}

/**
 * דומייני פרסום ומעקב.
 *
 * רשימה שימושית ולא ממצה — הכיסוי הוא של מה שנתקלים בו בפועל.
 * ההתאמה היא על הדומיין ותת-דומייניו, ולכן ערך אחד מכסה הרבה.
 */
function adHosts(): array {
    return [
        // גוגל
        'doubleclick.net', 'googlesyndication.com', 'googleadservices.com',
        'google-analytics.com', 'googletagmanager.com', 'googletagservices.com',
        'adservice.google.com', 'adsense.com', 'admob.com', '2mdn.net',
        // רשתות גדולות
        'criteo.com', 'criteo.net', 'taboola.com', 'outbrain.com', 'zemanta.com',
        'adnxs.com', 'rubiconproject.com', 'pubmatic.com', 'openx.net',
        'casalemedia.com', 'indexexchange.com', 'sharethrough.com', 'smartadserver.com',
        'adform.net', 'teads.tv', 'spotxchange.com', 'moatads.com',
        // רשתות חברתיות ומעקב
        'facebook.net', 'connect.facebook.net', 'ads-twitter.com', 'analytics.twitter.com',
        'bat.bing.com', 'ads.linkedin.com', 'snapchat.com/ads',
        // אנליטיקה והקלטת מסך
        'hotjar.com', 'mouseflow.com', 'fullstory.com', 'clarity.ms',
        'mixpanel.com', 'segment.io', 'segment.com', 'amplitude.com',
        'scorecardresearch.com', 'quantserve.com', 'chartbeat.com', 'newrelic.com',
        // ישראל
        'ads.walla.co.il', 'adx.ynet.co.il', 'exelate.com',
    ];
}

/**
 * נתיבים שנראים כמו פרסומת גם בדומיין תמים.
 *
 * אתר שמגיש את הפרסומות שלו בעצמו אינו נתפס ברשימת דומיינים, ואלה
 * התבניות שכן תופסות אותו.
 */
function adPathPatterns(): array {
    return [
        '#^/pagead/#', '#^/ads?/#', '#^/adserver#', '#^/adframe#', '#^/advert#',
        '#^/banners?/#', '#^/sponsor#', '#^/ptracking#', '#^/pcs/activeview#',
        '#/googleads?#', '#/prebid#', '#[?&]ad_type=#', '#[?&]adunit=#',
    ];
}

/** נתיבי הפרסומות של יוטיוב עצמו — מדידה, לא הווידאו. */
function youTubeAdPaths(): array {
    return ['#^/api/stats/ads#', '#^/pagead/#', '#^/ptracking#', '#^/get_midroll#'];
}

/** האם המצב הזה הופעל למשתמש. */
function adModeOn(array $policy, string $mode): bool {
    $on = array_filter(array_map('trim', explode(',', (string) ($policy['ad_block'] ?? ''))));
    return in_array($mode, $on, true);
}

/**
 * האם הבקשה היא פרסומת שיש לחסום.
 *
 * ניווט אמיתי נחסם רק כשחסימת חלונות קופצים הופעלה: דומיין פרסומי
 * שהמשתמש הקליד בעצמו אינו פרסומת, והחסימה שם היא החלטה נפרדת.
 */
function isAdRequest(array $policy, array $url, bool $isMainFrame): bool {
    $host = $url['host'];
    $path = $url['path'] . ($url['query'] !== '' ? '?' . $url['query'] : '');

    if ($isMainFrame && !adModeOn($policy, 'popups')) return false;
    if (!$isMainFrame && !adModeOn($policy, 'network')) return false;

    foreach (adHosts() as $adHost) {
        if (hostMatches($host, $adHost)) return true;
        // כמה ערכים ברשימה הם דומיין+נתיב, ולא דומיין בלבד.
        if (str_contains($adHost, '/') && str_starts_with($host . $url['path'], $adHost)) return true;
    }
    foreach (adPathPatterns() as $re) {
        if (preg_match($re, $path)) return true;
    }

    // יוטיוב: רק נתיבי המדידה. הווידאו עצמו מגיע מאותו מקור, ולחסום
    // אותו פירושו לחסום את הסרטון שכן אושר.
    if (platformOf($host) === PLATFORM_YOUTUBE) {
        foreach (youTubeAdPaths() as $re) {
            if (preg_match($re, $url['path'])) return true;
        }
    }
    return false;
}

/**
 * בוררי CSS להסתרת שטחים שנשארו.
 *
 * חסימת רשת מונעת את הטעינה, אבל המסגרת הריקה נשארת ומשאירה חור
 * בדף. ההסתרה היא השלמה, לא תחליף.
 */
function adCssSelectors(): string {
    return implode(',', [
        'ins.adsbygoogle', '.adsbygoogle', 'iframe[id^="google_ads"]',
        'iframe[src*="doubleclick"]', 'iframe[src*="googlesyndication"]',
        'iframe[src*="adserver"]', '[id^="div-gpt-ad"]', '[class*="taboola"]',
        '[id*="taboola"]', '[class*="outbrain"]', '[id*="outbrain"]',
        '.ad-banner', '.ad-container', '.ad-wrapper', '.advertisement',
        '[class^="ad-slot"]', '[data-ad-slot]', '[aria-label="Advertisement"]',
        'ytd-promoted-sparkles-web-renderer', 'ytd-display-ad-renderer',
        'ytd-in-feed-ad-layout-renderer', 'ytd-ad-slot-renderer',
        '.ytp-ad-overlay-container', '#player-ads', '#masthead-ad',
    ]);
}
