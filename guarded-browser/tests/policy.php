<?php
/**
 * בדיקות מנוע ההרשאות.
 *
 *   php guarded-browser/tests/policy.php
 *
 * הדגש: שכל תנאי נבדק בבידוד, ושהסיבה שמוצגת היא השורשית ולא הראשונה
 * שנתקלנו בה. משתמש מושעה שמקבל "הכתובת אינה ברשימה" הוא באג.
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
$pol  = fn(array $o = []) => $o + ['mode' => 'kiosk', 'days_mask' => 127,
        'window_start' => '', 'window_end' => '', 'daily_quota_min' => 0, 'session_max_min' => 0];
$rule = fn(string $p, array $o = []) => $o + ['pattern' => $p, 'scope' => 'domain',
        'action' => 'allow', 'enabled' => 1, 'label' => ''];

echo "\n— נרמול כתובות —\n";
check('סכימה חסרה מושלמת',   normalizeUrl('example.com')['host'], 'example.com');
check('www מוסר',            normalizeUrl('https://www.example.com')['host'], 'example.com');
check('אותיות מוקטנות',      normalizeUrl('HTTPS://Example.COM/A')['host'], 'example.com');
check('סלאש בסוף אינו הבדל', normalizeUrl('https://a.com/x/')['path'], '/x');
check('שורש נשאר שורש',      normalizeUrl('https://a.com')['path'], '/');
check('סכימה אסורה נדחית',   normalizeUrl('file:///etc/passwd'), null);
check('javascript נדחה',     normalizeUrl('javascript:alert(1)'), null);
check('כתובת ריקה נדחית',    normalizeUrl('  '), null);

echo "\n— התאמת דומיין —\n";
check('דומיין זהה',      hostMatches('a.com', 'a.com'), true);
check('תת-דומיין',       hostMatches('cdn.a.com', 'a.com'), true);
/*
 * ‏"nota.com" נגמר ב-"a.com" כמחרוזת. בלי הנקודה המפרידה, כלל שמתיר
 * a.com היה פותח כל דומיין שנגמר כך — כולל כזה שנרשם בדיוק לשם כך.
 */
check('דומיין דומה אינו תת-דומיין', hostMatches('nota.com', 'a.com'), false);
check('דומיין אחר',      hostMatches('b.com', 'a.com'), false);

echo "\n— גבול הניווט לכל כלל —\n";
$u = normalizeUrl('https://liveball.sx/team/772');

check('exact — הכתובת המדויקת',
      ruleMatches($rule('liveball.sx/team/772', ['scope' => 'exact']), $u, true), true);
check('exact — דף אחר באותו אתר נדחה',
      ruleMatches($rule('liveball.sx/team/999', ['scope' => 'exact']), $u, true), false);
check('domain — כל דף באתר',
      ruleMatches($rule('liveball.sx', ['scope' => 'domain']), $u, true), true);
check('domain — תת-דומיין',
      ruleMatches($rule('liveball.sx', ['scope' => 'domain']),
                  normalizeUrl('https://cdn.liveball.sx/p'), true), true);
/*
 * ההבחנה שבגללה domain_plus קיים: דף מותר טוען נגן, גופן ותמונה
 * מדומיינים אחרים. לחסום אותם זה לשבור את הדף שכן הותר.
 */
check('domain_plus — ניווט לדומיין זר נדחה',
      ruleMatches($rule('liveball.sx', ['scope' => 'domain_plus']),
                  normalizeUrl('https://evil.com/x'), true), false);
check('domain_plus — משאב נלווה מדומיין זר מותר',
      ruleMatches($rule('liveball.sx', ['scope' => 'domain_plus']),
                  normalizeUrl('https://cdn-player.net/p.js'), false), true);
check('domain — משאב נלווה מדומיין זר עדיין נדחה',
      ruleMatches($rule('liveball.sx', ['scope' => 'domain']),
                  normalizeUrl('https://cdn-player.net/p.js'), false), false);

echo "\n— מצבי גלישה —\n";
$allow = [$rule('liveball.sx')];

check('קיוסק — מה שברשימה',
      matchRules($allow, MODE_KIOSK, $u, true)['allow'], true);
check('קיוסק — מה שאינו ברשימה',
      matchRules($allow, MODE_KIOSK, normalizeUrl('https://other.com'), true)['allow'], false);
check('רשימת היתר — מה שאינו ברשימה',
      matchRules($allow, MODE_ALLOWLIST, normalizeUrl('https://other.com'), true)['allow'], false);
check('חופשי — מה שאינו ברשימה מותר',
      matchRules($allow, MODE_FREE, normalizeUrl('https://other.com'), true)['allow'], true);

/*
 * ‏deny חייב לגבור בלי קשר לסדר. כלל אוסר שאפשר לנטרל בהוספת כלל
 * מתיר אחריו אינו כלל אוסר.
 */
$mixed = [$rule('bad.com', ['action' => 'deny']), $rule('bad.com')];
check('איסור גובר על היתר שאחריו',
      matchRules($mixed, MODE_FREE, normalizeUrl('https://bad.com'), true)['allow'], false);
$mixed2 = [$rule('bad.com'), $rule('bad.com', ['action' => 'deny'])];
check('ואיסור גובר גם כשהוא אחרון',
      matchRules($mixed2, MODE_FREE, normalizeUrl('https://bad.com'), true)['allow'], false);
check('כלל מנוטרל אינו חל',
      matchRules([$rule('liveball.sx', ['enabled' => 0])], MODE_KIOSK, $u, true)['allow'], false);

echo "\n— מצב החשבון —\n";
$now = $at('2026-08-26 18:00:00');
check('פעיל',          accountState($user(), $now)['allow'], true);
check('ממתין לאישור',  accountState($user(['status' => 'pending']), $now)['code'], 'pending');
check('מושעה',         accountState($user(['status' => 'suspended']), $now)['code'], 'suspended');
check('תוקף פג',       accountState($user(['expires_at' => '2026-01-01']), $now)['code'], 'expired');
check('תוקף עתידי תקין',
      accountState($user(['expires_at' => '2027-01-01']), $now)['allow'], true);

echo "\n— חלון זמן יומי —\n";
check('בתוך החלון',
      withinWindow($pol(['window_start' => '16:00', 'window_end' => '22:00']), $at('2026-08-26 18:00'))['allow'], true);
check('לפני החלון',
      withinWindow($pol(['window_start' => '16:00', 'window_end' => '22:00']), $at('2026-08-26 09:00'))['allow'], false);
check('בדיוק בסיום — כבר מחוץ',
      withinWindow($pol(['window_start' => '16:00', 'window_end' => '22:00']), $at('2026-08-26 22:00'))['allow'], false);
check('בדיוק בהתחלה — בפנים',
      withinWindow($pol(['window_start' => '16:00', 'window_end' => '22:00']), $at('2026-08-26 16:00'))['allow'], true);
// חלון שחוצה חצות — התנאי מתהפך, וזו הטעות הקלה ביותר לעשות כאן.
$night = $pol(['window_start' => '22:00', 'window_end' => '02:00']);
check('חוצה חצות — לפני חצות',  withinWindow($night, $at('2026-08-26 23:00'))['allow'], true);
check('חוצה חצות — אחרי חצות',  withinWindow($night, $at('2026-08-26 01:00'))['allow'], true);
check('חוצה חצות — באמצע היום', withinWindow($night, $at('2026-08-26 12:00'))['allow'], false);
// 2026-08-26 הוא רביעי → ביט 8.
check('יום מותר',  withinWindow($pol(['days_mask' => 8]),  $at('2026-08-26 12:00'))['allow'], true);
check('יום אסור',  withinWindow($pol(['days_mask' => 1]),  $at('2026-08-26 12:00'))['code'], 'day_blocked');
check('כל השבוע', withinWindow($pol(['days_mask' => 127]), $at('2026-08-26 12:00'))['allow'], true);

echo "\n— מכסות —\n";
check('בלי מכסה',        withinQuota($pol(), 999999)['allow'], true);
check('מכסה שנותרה',     withinQuota($pol(['daily_quota_min' => 120]), 3600)['allow'], true);
check('מכסה שנוצלה',     withinQuota($pol(['daily_quota_min' => 120]), 7200)['code'], 'quota_spent');
check('בלי תקרת ישיבה',  withinSession($pol(), 999999)['allow'], true);
check('תקרת ישיבה עברה', withinSession($pol(['session_max_min' => 30]), 1800)['code'], 'session_over');

echo "\n— ההכרעה השלמה: הסיבה חייבת להיות השורשית —\n";
/*
 * הבאג שהסדר הזה מונע: משתמש מושעה שמבקש כתובת שאינה ברשימתו. שתי
 * הסיבות נכונות, אבל "אינה ברשימה" שולח אותו לבקש כלל נוסף במקום
 * לומר לו שהחשבון סגור.
 */
$ctx = ['url' => 'https://other.com', 'main_frame' => true, 'used_today' => 0, 'session' => 0];
check('השעיה קודמת לרשימה',
      evaluate($user(['status' => 'suspended']), $pol(), $allow, $now, $ctx)['code'], 'suspended');
check('פקיעה קודמת לרשימה',
      evaluate($user(['expires_at' => '2020-01-01']), $pol(), $allow, $now, $ctx)['code'], 'expired');
check('חלון זמן קודם לרשימה',
      evaluate($user(), $pol(['window_start' => '01:00', 'window_end' => '02:00']), $allow, $now, $ctx)['code'],
      'outside_window');
check('מכסה קודמת לרשימה',
      // ‏array_replace ולא +: איחוד מערכים אינו דורס מפתח שכבר קיים,
      // וכאן $ctx כבר מכיל used_today=0 — הערך החדש היה נזרק בשקט.
      evaluate($user(), $pol(['daily_quota_min' => 10]), $allow, $now,
               array_replace($ctx, ['used_today' => 600]))['code'],
      'quota_spent');
check('וכשהכול תקין — הרשימה מכריעה',
      evaluate($user(), $pol(), $allow, $now, $ctx)['code'], 'not_listed');
check('וכתובת ברשימה נפתחת',
      evaluate($user(), $pol(), $allow, $now,
               ['url' => 'https://liveball.sx/team/772', 'main_frame' => true])['allow'], true);
check('בקשה בלי כתובת בודקת רק את החשבון',
      evaluate($user(), $pol(), [], $now, ['url' => ''])['code'], 'session_ok');
check('כתובת פסולה נדחית',
      evaluate($user(), $pol(), $allow, $now, ['url' => 'javascript:alert(1)'])['code'], 'bad_url');

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exit($fail === 0 ? 0 : 1);
