<?php
/**
 * פתיחת אתר ספציפי אצל ההורה (lib/sitefind.php): כתובת או חיפוש, הקטגוריות ומצבן, ומה הילד מקבל עכשיו —
 * וכלל היתר שפותח אתר גם כשהקטגוריה שלו חסומה.
 *
 *   php guarded-browser/tests/sitefind.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-sitefind-' . getmypid();
@mkdir($tmp, 0775, true); putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../lib/sitefind.php';
$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}
db();
[$acc] = accountCreate(['name' => 'כהן', 'level_max' => 'apps'], 'dana@x.com', 'דנה');
[$kid] = childCreate($acc, 'דני');
$child = one('SELECT * FROM users WHERE id = ?', [$kid]);
q("INSERT OR IGNORE INTO domain_categories (domain, category, source) VALUES ('liveball.test', 'sports', 'admin'), ('liveball.test', 'video', 'admin')");
q("INSERT INTO category_rules (user_id, category, action, created_at) VALUES (?, 'sports', 'deny', ?)", [$kid, nowIso()]);

echo "\n— כתובת —\n";
check('כתובת עם רווח — חיפוש, לא כתובת', siteLooksLikeUrl('לייב בול'), false);
check('שם שרת — כתובת', siteLooksLikeUrl('www.liveball.test/match/1'), true);
$r = siteFind($child, 'www.liveball.test/match/1');
check('תוצאה אחת, השרת בלי www', [$r['engine'], count($r['results']), $r['results'][0]['host']], ['url', 1, 'liveball.test']);
$cats = array_column($r['results'][0]['categories'], 'state', 'key');
ksort($cats);
check('הקטגוריות ומצבן אצל הילד', $cats, ['sports' => 'deny', 'video' => '']);
check('עכשיו — חסום, בגלל הקטגוריה', [$r['results'][0]['allow'], $r['results'][0]['where']], [false, '🔐 גישה — קטגוריה חסומה']);
check('אין עליו כלל', $r['results'][0]['rule'], '');

echo "\n— פתיחה גם כשהקטגוריה חסומה —\n";
childRuleAdd($kid, 'liveball.test', 'domain', 'לייב בול', 'allow');
$r = siteFind($child, 'liveball.test');
check('נפתח — הכלל גובר על הקטגוריה', [$r['results'][0]['allow'], $r['results'][0]['rule']], [true, 'allow']);
check('והקטגוריה נשארת חסומה', array_column($r['results'][0]['categories'], 'state', 'key')['sports'], 'deny');
check('אתר אחר מאותה קטגוריה — עדיין חסום', simulateSite($child, 'https://other-sports.test')['allow'] ?? null, false);

echo "\n— חיפוש —\n";
$r = siteFind($child, 'לייב בול');
check('בלי מפתח Brave — הסבר וקישור לגוגל', [$r['engine'], str_contains($r['google'], 'google.com/search')], ['none', true]);
braveKeySave('test_key_1234567890abcd');
$fake = fn($u, $k) => ['status' => 200, 'body' => json_encode(['web' => ['results' => [
    ['url' => 'https://www.liveball.test/a', 'title' => 'Live <b>Ball</b>', 'description' => 'שידורים'],
    ['url' => 'https://liveball.test/b', 'title' => 'כפול', 'description' => ''],
    ['url' => 'https://news.test/x', 'title' => 'חדשות', 'description' => ''],
]]])];
$r = siteFind($child, 'לייב בול', $fake);
check('תוצאות לפי שרת — בלי כפילות', [$r['engine'], array_column($r['results'], 'host')], ['migdalor', ['liveball.test', 'news.test']]);
check('כותרת נקייה', $r['results'][0]['title'], 'Live Ball');
check('"הכול חסום" — אתר בלי כלל חסום', [$r['results'][1]['allow'], $r['results'][1]['where']], [false, '🔐 גישה — ברירת המחדל "רק מה שאישרתי"']);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
