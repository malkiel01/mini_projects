<?php
/**
 * בדיקות מנוע ההרשאות.
 *
 *   php guarded-browser/tests/policy.php
 *
 * הדגש: שכל תנאי נבדק בבידוד, ושסדר הקדימויות נשמר. מנהל שכתב כלל
 * מפורש חייב לגבור על סיווג אוטומטי, ואיסור חייב לגבור על היתר —
 * מערכת שלא שומרת על זה אי אפשר להסביר למי שמגדיר אותה.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../lib/policy.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

$at   = fn(string $s) => new DateTimeImmutable($s);
$user = fn(array $o = []) => $o + ['status' => 'active', 'expires_at' => ''];
$pol  = fn(array $o = []) => $o + ['mode' => MODE_KIOSK, 'posture' => POSTURE_DENY,
        'blocked_types' => '', 'days_mask' => 127, 'window_start' => '', 'window_end' => '',
        'daily_quota_min' => 0, 'session_max_min' => 0];
$rule = fn(string $p, array $o = []) => $o + ['pattern' => $p, 'scope' => 'domain',
        'action' => 'allow', 'enabled' => 1, 'label' => ''];
$set  = fn(array $o = []) => $o + ['rules' => [], 'categories' => [], 'domain_map' => [],
        'platforms' => [], 'platform_items' => [], 'owner_of' => null];
$now  = $at('2026-08-26 18:00:00');

echo "\n— נרמול כתובות —\n";
check('סכימה חסרה מושלמת',   normalizeUrl('example.com')['host'], 'example.com');
check('www מוסר',            normalizeUrl('https://www.example.com')['host'], 'example.com');
check('אותיות מוקטנות',      normalizeUrl('HTTPS://Example.COM/A')['host'], 'example.com');
check('סלאש בסוף אינו הבדל', normalizeUrl('https://a.com/x/')['path'], '/x');
check('שורש נשאר שורש',      normalizeUrl('https://a.com')['path'], '/');
check('סכימה אסורה נדחית',   normalizeUrl('file:///etc/passwd'), null);
check('javascript נדחה',     normalizeUrl('javascript:alert(1)'), null);

echo "\n— התאמת דומיין —\n";
check('דומיין זהה', hostMatches('a.com', 'a.com'), true);
check('תת-דומיין',  hostMatches('cdn.a.com', 'a.com'), true);
/*
 * ‏"nota.com" נגמר ב-"a.com" כמחרוזת. בלי הנקודה המפרידה, כלל שמתיר
 * a.com היה פותח כל דומיין שנרשם בדיוק לשם כך.
 */
check('דומיין דומה אינו תת-דומיין', hostMatches('nota.com', 'a.com'), false);

echo "\n— גבול הניווט לכל כלל —\n";
$u = normalizeUrl('https://liveball.sx/team/772');
check('exact — הכתובת המדויקת',
      ruleMatches($rule('liveball.sx/team/772', ['scope' => 'exact']), $u, true), true);
check('exact — דף אחר נדחה',
      ruleMatches($rule('liveball.sx/team/999', ['scope' => 'exact']), $u, true), false);
check('domain — כל האתר',
      ruleMatches($rule('liveball.sx'), $u, true), true);
check('domain_plus — משאב נלווה מדומיין זר מותר',
      ruleMatches($rule('liveball.sx', ['scope' => 'domain_plus']),
                  normalizeUrl('https://cdn-player.net/p.js'), false), true);
check('domain_plus — ניווט לדומיין זר נדחה',
      ruleMatches($rule('liveball.sx', ['scope' => 'domain_plus']),
                  normalizeUrl('https://evil.com/x'), true), false);

echo "\n— איסור גובר על היתר —\n";
$mixed = [$rule('bad.com', ['action' => 'deny']), $rule('bad.com')];
check('גם כשהאיסור ראשון',
      matchUrlRules($mixed, normalizeUrl('https://bad.com'), true)['verdict'], 'deny');
check('וגם כשהוא אחרון',
      matchUrlRules(array_reverse($mixed), normalizeUrl('https://bad.com'), true)['verdict'], 'deny');
check('כלל מנוטרל אינו חל',
      matchUrlRules([$rule('a.com', ['enabled' => 0])], normalizeUrl('https://a.com'), true)['verdict'], '');

echo "\n— ברירת מחדל: פתוח מול סגור —\n";
check('סגור — מה שלא הותר נחסם',
      evaluate($user(), $pol(), $set(), $now, ['url' => 'https://x.com'])['code'], 'not_listed');
check('פתוח — מה שלא נאסר מותר',
      evaluate($user(), $pol(['posture' => POSTURE_ALLOW]), $set(), $now,
               ['url' => 'https://x.com'])['code'], 'posture_allow');
/*
 * ‏mode ו-posture הופרדו בכוונה: בגרסה הקודמת הם היו כרוכים, ולכן
 * "קיוסק שפתוח לכול" לא היה ניתן להבעה בכלל.
 */
check('קיוסק יכול להיות פתוח',
      evaluate($user(), $pol(['mode' => MODE_KIOSK, 'posture' => POSTURE_ALLOW]),
               $set(), $now, ['url' => 'https://x.com'])['allow'], true);
check('דפדפן יכול להיות סגור',
      evaluate($user(), $pol(['mode' => MODE_BROWSER, 'posture' => POSTURE_DENY]),
               $set(), $now, ['url' => 'https://x.com'])['allow'], false);

echo "\n— קטגוריות —\n";
$map = ['youtube.com' => ['video'], 'bet365.com' => ['gambling'],
        'sport5.co.il' => ['sports', 'video'], 'winner.co.il' => ['sports', 'gambling']];

check('תת-דומיין מסווג כמו האב',
      categoriesOfHost('m.youtube.com', $map), ['video']);
check('קטגוריה חסומה',
      matchCategories('bet365.com', $map, ['gambling' => 'deny'])['verdict'], 'deny');
check('קטגוריה מותרת',
      matchCategories('sport5.co.il', $map, ['sports' => 'allow'])['verdict'], 'allow');
/*
 * אתר ששייך גם לקטגוריה מותרת וגם לאסורה — האיסור מכריע. הסיווג
 * המחמיר הוא זה שנועד לתפוס אותו מלכתחילה.
 */
check('שתי קטגוריות — האוסרת מכריעה',
      matchCategories('winner.co.il', $map,
                      ['sports' => 'allow', 'gambling' => 'deny'])['verdict'], 'deny');
check('דומיין לא מסווג אינו מכריע',
      matchCategories('unknown.com', $map, ['gambling' => 'deny'])['verdict'], '');

$catSet = $set(['domain_map' => $map, 'categories' => ['gambling' => 'deny', 'sports' => 'allow']]);
check('בפועל: הימורים נחסמים',
      evaluate($user(), $pol(['posture' => POSTURE_ALLOW]), $catSet, $now,
               ['url' => 'https://bet365.com'])['code'], 'category_deny');
check('בפועל: ספורט נפתח גם כשהכול סגור',
      evaluate($user(), $pol(), $catSet, $now,
               ['url' => 'https://sport5.co.il'])['code'], 'category_allow');
/*
 * הקדימות שבלעדיה המערכת אינה ניתנת להסבר: כלל שהמנהל כתב בידיים
 * חייב לגבור על סיווג אוטומטי מהקטלוג.
 */
check('כלל כתובת מפורש גובר על קטגוריה חסומה',
      evaluate($user(), $pol(['posture' => POSTURE_ALLOW]),
               $set(['domain_map' => $map, 'categories' => ['gambling' => 'deny'],
                     'rules' => [$rule('bet365.com')]]),
               $now, ['url' => 'https://bet365.com'])['code'], 'rule_allow');

echo "\n— סוגי תוכן —\n";
check('זיהוי לפי סיומת',            contentTypeOf('https://a.com/clip.mp4'), 'video');
check('שאילתה אינה מסתירה סיומת',   contentTypeOf('https://a.com/clip.mp4?t=1'), 'video');
check('זיהוי לפי Content-Type',     contentTypeOf('https://a.com/x', 'video/mp4'), 'video');
check('‏m3u8 הוא ווידאו',            contentTypeOf('https://a.com/s.m3u8'), 'video');
check('‏apk הוא קובץ התקנה',         contentTypeOf('https://a.com/app.apk'), 'executable');
check('דף רגיל אינו מסווג',         contentTypeOf('https://a.com/page'), '');

$noVideo = $pol(['posture' => POSTURE_ALLOW, 'blocked_types' => 'video']);
check('ווידאו חסום',
      evaluate($user(), $noVideo, $set(), $now, ['url' => 'https://a.com/clip.mp4'])['code'],
      'type_blocked');
check('שאר התוכן עובר',
      evaluate($user(), $noVideo, $set(), $now, ['url' => 'https://a.com/page'])['allow'], true);
check('היתר מפורש גובר על חסימת סוג',
      evaluate($user(), $noVideo, $set(['rules' => [$rule('a.com')]]), $now,
               ['url' => 'https://a.com/clip.mp4'])['code'], 'rule_allow');

echo "\n— פירוק כתובות יוטיוב —\n";
check('צפייה',       parseYouTube('https://www.youtube.com/watch?v=abc123XYZ'),
      ['kind' => 'video', 'id' => 'abc123XYZ']);
check('קישור מקוצר', parseYouTube('https://youtu.be/abc123XYZ'),
      ['kind' => 'video', 'id' => 'abc123XYZ']);
check('נגן מוטמע',   parseYouTube('https://www.youtube.com/embed/abc123XYZ')['kind'], 'video');
check('Shorts',      parseYouTube('https://youtube.com/shorts/abc123XYZ')['kind'], 'shorts');
check('ערוץ',        parseYouTube('https://youtube.com/channel/UCabcdefghijk')['id'], 'UCabcdefghijk');
check('כינוי',       parseYouTube('https://youtube.com/@HaSratim')['id'], 'hasratim');
check('פלייליסט',    parseYouTube('https://youtube.com/playlist?list=PL123')['id'], 'PL123');
check('חיפוש',       parseYouTube('https://youtube.com/results?search_query=x')['kind'], 'search');
check('דף הבית',     parseYouTube('https://youtube.com/')['kind'], 'home');
check('זיהוי פלטפורמה', platformOf('m.youtube.com'), PLATFORM_YOUTUBE);
check('דומיין אחר',     platformOf('vimeo.com'), '');

echo "\n— היתר ביוטיוב —\n";
$ytUrl   = fn(string $u) => normalizeUrl($u);
$ytItems = ['channel' => ['UCgoodChannel1234567890' => 'allow', 'UCbadChannel12345678901' => 'deny'],
            'video'   => ['vid_ok' => 'allow'],
            'handle'  => ['torah' => 'allow']];
$owner   = fn(string $v) => match ($v) {
    'vid_from_good'   => ['channel' => 'UCgoodChannel1234567890', 'handle' => 'goodguy'],
    'vid_from_bad'    => ['channel' => 'UCbadChannel12345678901', 'handle' => 'badguy'],
    'vid_orphan'      => ['channel' => '', 'handle' => ''],
    // סרטון שהפענוח החזיר עליו רק כינוי, בלי מזהה ערוץ.
    'vid_handle_only' => ['channel' => '', 'handle' => 'torah'],
    default           => ['channel' => 'UCotherChan1234567890z', 'handle' => 'someone'],
};

check('כבוי — הכול חסום',
      youTubeVerdict($ytUrl('https://youtube.com/watch?v=vid_ok'),
                     ['mode' => 'off'], $ytItems, true)['code'], 'yt_off');
check('פתוח — הכול מותר',
      youTubeVerdict($ytUrl('https://youtube.com/'), ['mode' => 'full'], $ytItems, true)['allow'], true);
check('פתוח — אבל ערוץ אסור עדיין חסום',
      youTubeVerdict($ytUrl('https://youtube.com/channel/UCbadChannel12345678901'),
                     ['mode' => 'full'], $ytItems, true)['code'], 'yt_item_denied');

$restricted = ['mode' => 'restricted', 'allow_search' => 0, 'allow_shorts' => 0];

check('מוגבל — דף הבית חסום',
      youTubeVerdict($ytUrl('https://youtube.com/'), $restricted, $ytItems, true)['code'], 'yt_no_browse');
check('מוגבל — חיפוש חסום',
      youTubeVerdict($ytUrl('https://youtube.com/results?search_query=x'),
                     $restricted, $ytItems, true)['code'], 'yt_no_search');
check('מוגבל — חיפוש מותר כשהופעל',
      youTubeVerdict($ytUrl('https://youtube.com/results?search_query=x'),
                     ['mode' => 'restricted', 'allow_search' => 1], $ytItems, true)['allow'], true);
check('מוגבל — ערוץ מאושר',
      youTubeVerdict($ytUrl('https://youtube.com/channel/UCgoodChannel1234567890'),
                     $restricted, $ytItems, true)['allow'], true);
check('מוגבל — ערוץ שלא אושר',
      youTubeVerdict($ytUrl('https://youtube.com/channel/UCnopeChannel123456789'),
                     $restricted, $ytItems, true)['code'], 'yt_not_approved');
check('מוגבל — כינוי מאושר',
      youTubeVerdict($ytUrl('https://youtube.com/@Torah'), $restricted, $ytItems, true)['allow'], true);
check('מוגבל — סרטון שאושר ישירות',
      youTubeVerdict($ytUrl('https://youtube.com/watch?v=vid_ok'),
                     $restricted, $ytItems, true, $owner)['allow'], true);
/*
 * הלב של הבקשה: לאשר ערוץ שלם. מכתובת של סרטון אי אפשר לדעת למי הוא
 * שייך, ולכן הפענוח מוזרק — וכאן נבדק שהוא באמת מכריע.
 */
check('מוגבל — סרטון מערוץ מאושר',
      youTubeVerdict($ytUrl('https://youtube.com/watch?v=vid_from_good'),
                     $restricted, $ytItems, true, $owner)['code'], 'yt_channel_allowed');
check('מוגבל — סרטון מערוץ אסור',
      youTubeVerdict($ytUrl('https://youtube.com/watch?v=vid_from_bad'),
                     $restricted, $ytItems, true, $owner)['code'], 'yt_item_denied');
check('מוגבל — סרטון מערוץ לא מוכר',
      youTubeVerdict($ytUrl('https://youtube.com/watch?v=whatever'),
                     $restricted, $ytItems, true, $owner)['code'], 'yt_not_approved');
/*
 * כשהפענוח נכשל — נסגר, לא נפתח. היתר שניתן מחוסר ידיעה אינו היתר,
 * וזו בדיוק הדרך שבה בקשה אחת שנכשלת הופכת לפרצה.
 */
check('פענוח שנכשל סוגר ולא פותח',
      youTubeVerdict($ytUrl('https://youtube.com/watch?v=vid_orphan'),
                     $restricted, $ytItems, true, $owner)['code'], 'yt_owner_unknown');
check('ובלי פענוח כלל — גם סוגר',
      youTubeVerdict($ytUrl('https://youtube.com/watch?v=x'),
                     $restricted, $ytItems, true, null)['code'], 'yt_owner_unknown');
check('Shorts חסום כברירת מחדל',
      youTubeVerdict($ytUrl('https://youtube.com/shorts/vid_ok'),
                     $restricted, $ytItems, true, $owner)['code'], 'yt_no_shorts');
check('Shorts מאושר כשהופעל',
      youTubeVerdict($ytUrl('https://youtube.com/shorts/vid_ok'),
                     ['mode' => 'restricted', 'allow_shorts' => 1], $ytItems, true, $owner)['allow'], true);
/*
 * בלי המעבר הזה, מצב מוגבל היה חוסם את זרם הווידאו של הסרטון שכן
 * אושר — והמשתמש היה רואה מסך שחור במקום את מה שהותר לו.
 */
check('משאבי הנגן עוברים',
      youTubeVerdict($ytUrl('https://rr3---sn-x.googlevideo.com/videoplayback?x=1'),
                     $restricted, $ytItems, false)['code'], 'yt_asset');

echo "\n— יוטיוב בתוך ההכרעה השלמה —\n";
$ytSet = $set(['platforms' => [PLATFORM_YOUTUBE => $restricted],
               'platform_items' => [PLATFORM_YOUTUBE => $ytItems],
               'owner_of' => $owner]);
check('גם כשהכול פתוח, יוטיוב נשאר מוגבל',
      evaluate($user(), $pol(['posture' => POSTURE_ALLOW]), $ytSet, $now,
               ['url' => 'https://youtube.com/'])['code'], 'yt_no_browse');
check('וסרטון מאושר נפתח',
      evaluate($user(), $pol(), $ytSet, $now,
               ['url' => 'https://youtube.com/watch?v=vid_from_good'])['allow'], true);

echo "\n— תנאי חשבון וזמן —\n";
check('ממתין לאישור', accountState($user(['status' => 'pending']), $now)['code'], 'pending');
check('מושעה',        accountState($user(['status' => 'suspended']), $now)['code'], 'suspended');
check('תוקף פג',      accountState($user(['expires_at' => '2026-01-01']), $now)['code'], 'expired');
$win = $pol(['window_start' => '16:00', 'window_end' => '22:00']);
check('בתוך החלון',      withinWindow($win, $at('2026-08-26 18:00'))['allow'], true);
check('בדיוק בסיום',     withinWindow($win, $at('2026-08-26 22:00'))['allow'], false);
$night = $pol(['window_start' => '22:00', 'window_end' => '02:00']);
check('חוצה חצות — אחרי חצות',  withinWindow($night, $at('2026-08-26 01:00'))['allow'], true);
check('חוצה חצות — באמצע היום', withinWindow($night, $at('2026-08-26 12:00'))['allow'], false);
check('יום אסור', withinWindow($pol(['days_mask' => 1]), $at('2026-08-26 12:00'))['code'], 'day_blocked');
check('מכסה שנוצלה', withinQuota($pol(['daily_quota_min' => 120]), 7200)['code'], 'quota_spent');
check('תקרת ישיבה',  withinSession($pol(['session_max_min' => 30]), 1800)['code'], 'session_over');

echo "\n— הסיבה חייבת להיות השורשית —\n";
/*
 * משתמש מושעה שמבקש כתובת חסומה: שתי הסיבות נכונות, אבל "אינה
 * ברשימה" שולח אותו לבקש כלל נוסף במקום לומר לו שהחשבון סגור.
 */
$c = ['url' => 'https://x.com', 'main_frame' => true];
check('השעיה קודמת לרשימה',
      evaluate($user(['status' => 'suspended']), $pol(), $set(), $now, $c)['code'], 'suspended');
check('חלון זמן קודם לרשימה',
      evaluate($user(), $pol(['window_start' => '01:00', 'window_end' => '02:00']),
               $set(), $now, $c)['code'], 'outside_window');
check('מכסה קודמת לרשימה',
      evaluate($user(), $pol(['daily_quota_min' => 10]), $set(), $now,
               array_replace($c, ['used_today' => 600]))['code'], 'quota_spent');
check('בקשה בלי כתובת בודקת רק את החשבון',
      evaluate($user(), $pol(), $set(), $now, ['url' => ''])['code'], 'session_ok');

echo "\n— קלט מקוצר של יוטיוב —\n";
/*
 * הדרישה להדביק כתובת מלאה היא עבודה שהמערכת אמורה לעשות במקום
 * המנהל: מי שרוצה לאשר ערוץ מכיר אותו בשם, לא ב-URL.
 */
check('כינוי בלבד',
      normalizeYouTubeInput('@MercazDafYomi'), ['kind' => 'handle', 'id' => 'mercazdafyomi']);
check('כינוי באות גדולה יורד לקטנה',
      normalizeYouTubeInput('@MERCAZ')['id'], 'mercaz');
check('שם בלי @',
      normalizeYouTubeInput('MercazDafYomi'), ['kind' => 'handle', 'id' => 'mercazdafyomi']);
/*
 * הבאג שהתיקון הזה מונע: השלמה עיוורת של הקידומת הפכה
 * "youtube.com/@x" ל-"youtube.com/youtube.com/@x".
 */
check('דומיין בלי סכימה',
      normalizeYouTubeInput('youtube.com/@MercazDafYomi'),
      ['kind' => 'handle', 'id' => 'mercazdafyomi']);
check('עם www ובלי סכימה',
      normalizeYouTubeInput('www.youtube.com/@Mercaz')['id'], 'mercaz');
check('כתובת מלאה',
      normalizeYouTubeInput('https://www.youtube.com/@Mercaz')['id'], 'mercaz');
check('קישור מקוצר בלי סכימה',
      normalizeYouTubeInput('youtu.be/dQw4w9WgXcQ'),
      ['kind' => 'video', 'id' => 'dQw4w9WgXcQ']);
check('מזהה ערוץ חשוף',
      normalizeYouTubeInput('UCabcdefghijklmnopqrstuv'),
      ['kind' => 'channel', 'id' => 'UCabcdefghijklmnopqrstuv']);
check('מזהה סרטון חשוף',
      normalizeYouTubeInput('dQw4w9WgXcQ'), ['kind' => 'video', 'id' => 'dQw4w9WgXcQ']);
check('מזהה פלייליסט חשוף',
      normalizeYouTubeInput('PLabcdefghij123')['kind'], 'playlist');
check('כתובת צפייה מלאה',
      normalizeYouTubeInput('https://youtube.com/watch?v=dQw4w9WgXcQ'),
      ['kind' => 'video', 'id' => 'dQw4w9WgXcQ']);
check('Shorts',
      normalizeYouTubeInput('youtube.com/shorts/dQw4w9WgXcQ')['kind'], 'shorts');
check('ריק אינו מזהה',   normalizeYouTubeInput('   ')['kind'], 'other');
check('תווים פסולים',     normalizeYouTubeInput('@שלום עולם')['kind'], 'other');
check('דומיין אחר לגמרי', normalizeYouTubeInput('vimeo.com/123')['kind'], 'other');


echo "\n— כתובת של פריט יוטיוב —\n";
/*
 * בלי הכתובות האלה, ערוץ מאושר אינו נגיש בכלל במצב קיוסק: האריחים
 * נבנים מכללי כתובות, ולפריט פלטפורמה אין כתובת משלו.
 */
check('ערוץ',     youTubeItemUrl('channel', 'UCabc'), 'https://www.youtube.com/channel/UCabc');
check('כינוי',    youTubeItemUrl('handle', 'mercaz'), 'https://www.youtube.com/@mercaz');
check('סרטון',    youTubeItemUrl('video', 'abc123'), 'https://www.youtube.com/watch?v=abc123');
check('פלייליסט', youTubeItemUrl('playlist', 'PL1'), 'https://www.youtube.com/playlist?list=PL1');
check('סוג לא מוכר', youTubeItemUrl('nope', 'x'), '');

// הכתובות שנוצרות חייבות להיות מזוהות חזרה, אחרת האריח ייחסם.
foreach ([['channel', 'UCabcdefghijklmnopqrstuv'], ['handle', 'mercaz'],
          ['video', 'dQw4w9WgXcQ'], ['playlist', 'PLabcdefghij123']] as [$k, $id]) {
    $back = parseYouTube(youTubeItemUrl($k, $id));
    check("הלוך-חזור: $k", [$back['kind'], $back['id']], [$k, $id]);
}

/*
 * המלכודת שהתגלתה בייצור: youtu.be/ID מפנה ל-youtube.com/watch,
 * דומיין אחר שכלל הכתובת כבר אינו חל עליו. ההכרעה נופלת אז לכללי
 * הפלטפורמה, והמנהל רואה "חסום" על כתובת שהוא בטוח שהתיר.
 */
$shortLink = [$rule('https://youtu.be/_rYPW4QzwG8', ['scope' => 'domain_plus'])];
$ytOff = $set(['rules' => $shortLink, 'platforms' => [PLATFORM_YOUTUBE => ['mode' => 'off']]]);
check('הקישור המקוצר עצמו מותר לפי הכלל',
      evaluate($user(), $pol(), $ytOff, $now, ['url' => 'https://youtu.be/_rYPW4QzwG8'])['code'],
      'rule_allow');
check('אבל היעד שאליו הוא מפנה כבר לא',
      evaluate($user(), $pol(), $ytOff, $now,
               ['url' => 'https://www.youtube.com/watch?v=_rYPW4QzwG8'])['code'], 'yt_off');


echo "\n— חיפוש באתר עמוד-יחיד —\n";
/*
 * יוטיוב הוא אתר עמוד-יחיד: לחיצה על תוצאת חיפוש אינה ניווט, אלא
 * בקשת רקע שמחליפה את תוכן הדף. חסימת /results לבדה אינה עוצרת
 * את החיפוש — צריך לחסום את הבקשה שמחזירה את התוצאות.
 */
$noSearch = ['mode' => 'restricted', 'allow_search' => 0];
$yesSearch = ['mode' => 'restricted', 'allow_search' => 1];

check('בקשת החיפוש נחסמת',
      youTubeVerdict($ytUrl('https://www.youtube.com/youtubei/v1/search?key=x'),
                     $noSearch, $ytItems, false)['code'], 'yt_no_search');
check('וכשהחיפוש הותר — עוברת',
      youTubeVerdict($ytUrl('https://www.youtube.com/youtubei/v1/search?key=x'),
                     $yesSearch, $ytItems, false)['allow'], true);
// הנגן חייב להמשיך לעבוד: חסימה גורפת של youtubei הייתה משאירה
// מסך שחור גם על סרטון שאושר.
check('בקשת הנגן ממשיכה לעבוד',
      youTubeVerdict($ytUrl('https://www.youtube.com/youtubei/v1/player?key=x'),
                     $noSearch, $ytItems, false)['code'], 'yt_asset');
check('ובקשת דף הערוץ גם',
      youTubeVerdict($ytUrl('https://www.youtube.com/youtubei/v1/browse?key=x'),
                     $noSearch, $ytItems, false)['code'], 'yt_asset');
check('במצב פתוח החיפוש אינו נחסם',
      youTubeVerdict($ytUrl('https://www.youtube.com/youtubei/v1/search?key=x'),
                     ['mode' => 'full'], $ytItems, false)['allow'], true);


echo "\n— חיפוש בתוך דף ערוץ —\n";
/*
 * הפער שהתגלה: /@ערוץ/search נקרא "ערוץ מאושר" ועבר, בזמן שיוטיוב
 * מציג שם גם תוצאות מערוצים אחרים. חיפוש הוא חיפוש, לא משנה מאיזה
 * דף התחילו אותו.
 */
check('חיפוש בדף ערוץ מזוהה כחיפוש',
      parseYouTube('https://www.youtube.com/@Mercaz/search?query=x')['kind'], 'search');
check('וגם בצורת channel',
      parseYouTube('https://www.youtube.com/channel/UCabcdefghijk/search?query=x')['kind'], 'search');
check('ודף הערוץ עצמו נשאר ערוץ',
      parseYouTube('https://www.youtube.com/@Mercaz')['kind'], 'handle');
check('וגם לשוניות אחרות שלו',
      parseYouTube('https://www.youtube.com/@Mercaz/videos')['kind'], 'handle');
check('בפועל — חיפוש בדף ערוץ מאושר עדיין נחסם',
      youTubeVerdict($ytUrl('https://www.youtube.com/@Torah/search?query=x'),
                     $restricted, $ytItems, true)['code'], 'yt_no_search');
check('וכשהחיפוש הותר — נפתח',
      youTubeVerdict($ytUrl('https://www.youtube.com/@Torah/search?query=x'),
                     ['mode' => 'restricted', 'allow_search' => 1], $ytItems, true)['allow'], true);


echo "\n— נקודות הקצה של החיפוש —\n";
/*
 * חסימת /results לבדה אינה מספיקה: יוטיוב הוא אתר עמוד-יחיד,
 * והתוצאות וההצעות מגיעות בבקשות רקע. אם הן עוברות, המשתמש רואה
 * תוצאות ותמונות ממוזערות גם כשהניווט אליהן ייחסם.
 */
check('בקשת חיפוש',   isYouTubeSearchEndpoint('/youtubei/v1/search'), true);
check('הצעות השלמה',  isYouTubeSearchEndpoint('/complete/search'), true);
check('הנגן אינו חיפוש', isYouTubeSearchEndpoint('/youtubei/v1/player'), false);
check('דף הערוץ אינו חיפוש', isYouTubeSearchEndpoint('/youtubei/v1/browse'), false);
check('הצעות ההשלמה נחסמות בפועל',
      youTubeVerdict($ytUrl('https://www.youtube.com/complete/search?q=x'),
                     $noSearch, $ytItems, false)['code'], 'yt_no_search');


echo "\n— ערוץ שאושר בכינוי —\n";
/*
 * המנהל מדביק "@Name" — זה מה שהוא מכיר. הפענוח מחזיר "UC..." בלבד,
 * והשניים לעולם לא נפגשו: ערוץ שאושר בכינוי לא התאים לאף סרטון,
 * והמשתמש קיבל "לא הצלחנו לוודא" על ערוץ שאושר לו במפורש.
 */
check('סרטון מותאם לפי הכינוי כשאין מזהה',
      youTubeVerdict($ytUrl('https://youtube.com/watch?v=vid_handle_only'),
                     $restricted, $ytItems, true, $owner)['code'], 'yt_channel_allowed');
check('ומול מזהה הערוץ כרגיל',
      youTubeVerdict($ytUrl('https://youtube.com/watch?v=vid_from_good'),
                     $restricted, $ytItems, true, $owner)['code'], 'yt_channel_allowed');
check('פענוח ריק לגמרי עדיין סוגר',
      youTubeVerdict($ytUrl('https://youtube.com/watch?v=vid_orphan'),
                     $restricted, $ytItems, true, $owner)['code'], 'yt_owner_unknown');
// איסור על אחת הצורות חוסם, גם אם השנייה לא הוגדרה.
$denyHandle = ['handle' => ['someone' => 'deny']];
check('איסור לפי כינוי חוסם',
      youTubeVerdict($ytUrl('https://youtube.com/watch?v=whatever'),
                     $restricted, $denyHandle, true, $owner)['code'], 'yt_item_denied');


echo "\n— פענוח בעלות מ-oEmbed —\n";
/*
 * הדף הרגיל של יוטיוב הוא מגה-בייט, וגוגל מחליפה אותו בדף הסכמה
 * לעוגיות בפניות מדאטה-סנטר — התשובה חוזרת מהר ובהצלחה, ופשוט אין
 * בה מזהה ערוץ. ‏oEmbed הוא קילובייט של JSON שמחזיר בדיוק את זה.
 */
check('כינוי מ-author_url',
      parseOEmbedOwner(['author_url' => 'https://www.youtube.com/@MercazDafYomi'])['handle'],
      'mercazdafyomi');
check('מזהה ערוץ מ-author_url',
      parseOEmbedOwner(['author_url' => 'https://www.youtube.com/channel/UCabcdefghijk'])['channel'],
      'UCabcdefghijk');
check('כותרת נשמרת',
      parseOEmbedOwner(['author_url' => 'https://www.youtube.com/@x', 'title' => 'שיעור'])['title'],
      'שיעור');
check('תשובה ריקה אינה ממציאה',
      parseOEmbedOwner([]), ['channel' => '', 'handle' => '', 'title' => '']);

// הגיבוי: גריפת דף הצפייה, כששני הזיהויים קיימים בו.
$html = '<meta name="title" content="שיעור יומי">'
      . '{"channelId":"UCabcdefghijklmnop","canonicalBaseUrl":"/@MercazDafYomi"}';
check('גיבוי HTML — מזהה', parseYouTubeOwnerHtml($html)['channel'], 'UCabcdefghijklmnop');
check('גיבוי HTML — כינוי', parseYouTubeOwnerHtml($html)['handle'], 'mercazdafyomi');
check('גיבוי HTML — כותרת', parseYouTubeOwnerHtml($html)['title'], 'שיעור יומי');
check('דף הסכמה אינו מייצר זהות',
      parseYouTubeOwnerHtml('<html><body>Before you continue to YouTube</body></html>'),
      ['channel' => '', 'handle' => '', 'title' => '']);


echo "\n— חסימת פרסומות —\n";
/*
 * פרסומת אינה יעד שהמשתמש ביקש, אלא משאב בתוך דף שכן ביקש. לכן
 * היא נבדקת לפני כללי הכתובות: אילו נבדקה אחריהם, "התר את האתר
 * הזה" היה מחזיר את כל הפרסומות שבו.
 */
$ads = $pol(['posture' => POSTURE_ALLOW, 'ad_block' => 'network,cosmetic,youtube']);
$adUrl = fn(string $u) => normalizeUrl($u);

check('דומיין פרסום נחסם',
      isAdRequest($ads, $adUrl('https://doubleclick.net/x'), false), true);
check('גם תת-דומיין שלו',
      isAdRequest($ads, $adUrl('https://cdn.googlesyndication.com/a.js'), false), true);
check('נתיב פרסומי בדומיין תמים',
      isAdRequest($ads, $adUrl('https://news.co.il/pagead/banner.js'), false), true);
check('משאב רגיל עובר',
      isAdRequest($ads, $adUrl('https://news.co.il/style.css'), false), false);
check('מדידת פרסומות של יוטיוב נחסמת',
      isAdRequest($ads, $adUrl('https://www.youtube.com/api/stats/ads?x=1'), false), true);
// חסימת הווידאו עצמו הייתה חוסמת את הסרטון שכן אושר.
check('אבל הווידאו עצמו עובר',
      isAdRequest($ads, $adUrl('https://rr1.googlevideo.com/videoplayback?x=1'), false), false);

// כשהמצב כבוי — שום דבר לא נחסם.
$noAds = $pol(['posture' => POSTURE_ALLOW]);
check('כשהחסימה כבויה — עובר',
      isAdRequest($noAds, $adUrl('https://doubleclick.net/x'), false), false);

// ניווט אמיתי נחסם רק כשחסימת חלונות קופצים הופעלה.
check('ניווט לדומיין פרסום — רק עם popups',
      isAdRequest($ads, $adUrl('https://doubleclick.net/x'), true), false);
check('ועם popups — נחסם',
      isAdRequest($pol(['ad_block' => 'popups']), $adUrl('https://doubleclick.net/x'), true), true);

/*
 * הקדימות שהיא כל העניין: כלל שמתיר את האתר אינו מחזיר את
 * הפרסומות שבתוכו.
 */
check('היתר מפורש לאתר אינו מחזיר את פרסומותיו',
      evaluate($user(), $ads, $set(['rules' => [$rule('news.co.il', ['scope' => 'domain_plus'])]]),
               $now, ['url' => 'https://doubleclick.net/ad.js', 'main_frame' => false])['code'],
      'ad_blocked');
check('והתוכן של אותו אתר כן נטען',
      evaluate($user(), $ads, $set(['rules' => [$rule('news.co.il', ['scope' => 'domain_plus'])]]),
               $now, ['url' => 'https://news.co.il/article', 'main_frame' => true])['code'],
      'rule_allow');


echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exit($fail === 0 ? 0 : 1);
