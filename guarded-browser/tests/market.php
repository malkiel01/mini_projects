<?php
/**
 * מרקט האפליקציות (lib/market.php): מלאי בסיס, הוספה מקישור Play, אפליקציה פרטית, "מה ילדים מתקינים",
 * שחרור לילד — ומה שהמכשיר מקבל (משוחררת = מותרת).
 *
 *   php guarded-browser/tests/market.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir() . '/gb-market-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../lib/appcontrol.php';
require_once __DIR__ . '/../lib/history.php';

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
[$acc2] = accountCreate(['name' => 'לוי', 'level_max' => 'apps'], 'avi@x.com', 'אבי');
[$kid] = childCreate($acc, 'נועה');
[$kid2] = childCreate($acc2, 'דני');
$user = fn(int $id, int $a) => ['id' => $id, 'account_id' => $a] + (array) one('SELECT * FROM users WHERE id = ?', [$id]);
// דף של Google Play מדומה — בלי רשת בבדיקות.
$play = fn(string $title, string $img = 'https://play-lh.googleusercontent.com/abc=s0-br30') => fn(string $u) =>
    ['status' => 200, 'body' => '<meta property="og:title" content="' . $title . ' - Apps on Google Play"><meta property="og:image" content="' . $img . '">'];
$missing = fn(string $u) => ['status' => 404, 'body' => ''];

echo "\n— מלאי בסיס —\n";
$seed = json_decode(file_get_contents(__DIR__ . '/../lib/market_seed.json'), true)['apps'];
$cat = marketCatalog($acc);
check('כל מלאי הבסיס נטען', count($cat), count($seed));
check('כל שם חבילה במלאי תקין, ובלי כפילויות', [count(array_filter($seed, fn($a) => marketPackage($a['package']) !== '')),
      count(array_unique(array_column($seed, 'package')))], [count($seed), count($seed)]);
check('כל קטגוריה מוכרת', count(array_filter($seed, fn($a) => isset(MARKET_CATEGORIES[$a['category']]))), count($seed));
check('אייקון — בגודל קטן', marketIcon('https://play-lh.googleusercontent.com/abc=s0-br30', 96), 'https://play-lh.googleusercontent.com/abc=s96');
marketEnsureSeed();
check('טעינה חוזרת לא מכפילה', (int) one("SELECT COUNT(*) n FROM market_apps WHERE source = 'seed'")['n'], count($seed));

echo "\n— הוספה מקישור Play —\n";
check('קישור לא תקין', marketAdd(0, 'https://example.com', 'learn', 6, '', '', $missing)[1], 'זה לא קישור של Google Play ולא שם חבילה (למשל com.duolingo)');
check('לא נמצאה בחנות', marketAdd(0, 'com.nothing.here', 'learn', 6, '', '', $missing)[1], 'האפליקציה com.nothing.here לא נמצאה ב-Google Play');
[$id] = marketAdd(0, 'https://play.google.com/store/apps/details?id=com.example.math&hl=iw', 'learn', 7, 'פרסומות', '', $play('Math &amp; Fun'));
$row = one('SELECT * FROM market_apps WHERE id = ?', [$id]);
check('נוספה — שם מהחנות (פענוח &amp;), מקור admin', [$row['package'], $row['play_title'], $row['source'], (int) $row['account_id']],
      ['com.example.math', 'Math & Fun', 'admin', 0]);
check('הוספה חוזרת — אותה שורה', marketAdd(0, 'com.example.math', 'learn', 7, '', '', $play('Math'))[0], $id);

echo "\n— אפליקציה פרטית של הורה —\n";
[$pid] = marketAdd($acc, 'com.example.private', 'games', 0, '', 'המשחק של נועה', $play('Private Game'));
check('פרטית — רק בחשבון שלה', [in_array('com.example.private', array_column(marketCatalog($acc), 'package'), true),
      in_array('com.example.private', array_column(marketCatalog($acc2), 'package'), true)], [true, false]);
check('קיימת בכללי — לא נוצרת פרטית כפולה', marketAdd($acc, 'com.duolingo', 'learn', 0, '', '', $play('Duolingo'))[0],
      (int) one("SELECT id FROM market_apps WHERE package = 'com.duolingo' AND account_id = 0")['id']);
check('הורה לא מוחק מהמלאי הכללי', marketRemove($id, $acc), false);

echo "\n— שחרור לילד —\n";
check('אפליקציה שלא במלאי — לא', marketRelease($kid, 'com.not.listed', true), 'האפליקציה לא במלאי');
check('פרטית של חשבון אחר — לא', marketRelease($kid2, 'com.example.private', true), 'האפליקציה לא במלאי');
check('שחרור', [marketRelease($kid, 'com.duolingo', true), marketRelease($kid, 'com.example.private', true)], ['', '']);
check('משוחררות', marketReleased($kid), ['com.duolingo', 'com.example.private']);
q("UPDATE policies SET apps_posture = 'deny_all' WHERE user_id = ?", [$kid]);
q("INSERT INTO app_rules (child_id, package, action, created_at) VALUES (?, 'com.duolingo', 'block', ?)", [$kid, nowIso()]);
$p = appsPayload($user($kid, $acc));
check('למכשיר: משוחררת = מותרת, גם ב"רק מה שאישרתי" וגם מעל חסימה', [in_array('com.duolingo', $p['allowed'], true), in_array('com.duolingo', $p['blocked'], true)], [true, false]);
$mk = array_column($p['market'], 'n', 'p');
check('למכשיר: המרקט עם שם (פרטית — בשם שההורה נתן) ואייקון', [count($mk), $mk['com.example.private'] ?? '', str_contains($p['market'][0]['icon'], '=s96')],
      [2, 'המשחק של נועה', true]);
// מוסתרת (👻) שההורה גם שחרר — נשארת מוסתרת אחרי ההתקנה (בעל המערכת, 2026-10-24).
q("UPDATE app_rules SET action = 'hide' WHERE child_id = ? AND package = 'com.duolingo'", [$kid]);
$p = appsPayload($user($kid, $acc));
check('מוסתרת ומשוחררת — נשארת מוסתרת (וחסומה), לא "מותרת"',
      [in_array('com.duolingo', $p['hidden'], true), in_array('com.duolingo', $p['blocked'], true), in_array('com.duolingo', $p['allowed'], true)], [true, true, false]);
q("UPDATE app_rules SET action = 'block' WHERE child_id = ? AND package = 'com.duolingo'", [$kid]);
$r1 = policyRev($kid);
marketRelease($kid, 'com.example.math', true);
check('שחרור — המכשיר מושך מחדש', policyRev($kid) !== $r1, true);
check('להורה: מסומן משוחרר', count(array_filter(marketForParent($acc, $kid)['apps'], fn($a) => $a['released'])), 3);
$snap = policySnapshot($kid);
check('בהיסטוריה', $snap['market'] ?? null, [['package' => 'com.duolingo'], ['package' => 'com.example.math'], ['package' => 'com.example.private']]);

echo "\n— הסרה מהמלאי —\n";
check('המנהל מסיר — השחרור מתבטל', [marketRemove($id, 0), in_array('com.example.math', marketReleased($kid), true)], [true, false]);
check('ההורה מסיר את הפרטית שלו', [marketRemove($pid, $acc), in_array('com.example.private', marketReleased($kid), true)], [true, false]);

echo "\n— מה ילדים מתקינים —\n";
appsReport($kid, [['p' => 'com.cool.app', 'n' => 'Cool'], ['p' => 'com.rare.app', 'n' => 'Rare'], ['p' => 'com.duolingo', 'n' => 'Duolingo']]);
appsReport($kid2, [['p' => 'com.cool.app', 'n' => 'Cool']]);
$pop = marketPopular();
check('מופיע רק מה שאצל 2 ילדים ואינו במלאי — ספירה בלבד', $pop, [['package' => 'com.cool.app', 'label' => 'Cool', 'children' => 2]]);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
