<?php
/**
 * בדיקות לפעילות ודוחות (שלב 7): מה נשמר בכל רמה, ירידה ברמה מוחקת, הדוח.
 *
 *   php guarded-browser/tests/activity.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/gb-act-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
    @rmdir($tmp);
});

require_once __DIR__ . '/../lib/activity.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}
$ok = ['allow' => true, 'code' => 'rule_allow'];
$no = ['allow' => false, 'code' => 'not_listed'];
$tz = 'Asia/Jerusalem';
$n = fn(string $sql, array $a = []) => (int) one($sql, $a)['n'];

db();
[$a1] = accountCreate(['name' => 'כהן', 'modules' => ['reports_full']], 'dana@x.com', 'דנה');
[$a2] = accountCreate(['name' => 'לוי', 'modules' => []], 'avi@x.com', 'אבי');
[$kid] = childCreate($a1, 'נועה');
[$kid2] = childCreate($a2, 'דני');

echo "\n— ברירת מחדל: מסוכם —\n";
check('ילד חדש — מסוכם', reportLevel($kid), 'summary');
activityNav($kid, 'https://he.wikipedia.org/wiki/A', $ok, $tz);
activityNav($kid, 'https://he.wikipedia.org/wiki/B', $ok, $tz);
activityBeat($kid, 'https://he.wikipedia.org/wiki/B', 30, $tz);
activityBeat($kid, 'https://he.wikipedia.org/wiki/B', 30, $tz);
activityNav($kid, 'https://tiktok.com/@x', $no, $tz);
check('כתובות מותרות לא נשמרות', $n("SELECT COUNT(*) n FROM audit WHERE user_id = ? AND allowed = 1", [$kid]), 0);
check('האתר נספר: 2 כניסות, 60 שניות', array_values(array_intersect_key(
      one('SELECT * FROM site_usage WHERE user_id = ? AND host = ?', [$kid, 'he.wikipedia.org']), ['seconds' => 1, 'visits' => 1])), [60, 2]);
check('חסימה נשמרת תמיד, עם הכתובת', one("SELECT url FROM audit WHERE user_id = ? AND allowed = 0", [$kid])['url'], 'https://tiktok.com/@x');
activityBeat($kid, '', 30, $tz);
check('פעימה בלי כתובת — לא נזקפת לאתר', $n('SELECT COUNT(*) n FROM site_usage WHERE user_id = ?', [$kid]), 1);

echo "\n— מלא —\n";
check('רמה לא מוכרת', reportLevelSave($a1, $kid, 'spy'), 'רמה לא מוכרת');
check('חשבון בלי reports_full — אין "מלא"', reportLevelSave($a2, $kid2, 'full'), 'דיווח מלא אינו כלול בחשבון. אפשר לבקש ממנהל המערכת.');
check('...והרמות שמוצעות לו', reportLevelsFor($a2), ['summary', 'off']);
check('חשבון עם reports_full', reportLevelSave($a1, $kid, 'full'), '');
activityNav($kid, 'https://khanacademy.org/math', $ok, $tz);
activityBeat($kid, 'https://khanacademy.org/math/x', 30, $tz);
activityBeat($kid, 'https://khanacademy.org/math/x', 30, $tz);
activityNav($kid, 'https://he.wikipedia.org/wiki/C', $ok, $tz);
activityBeat($kid, 'https://khanacademy.org/other', 30, $tz);   // אתר אחר מהכתובת האחרונה — לא נזקף לה
$h = all("SELECT url, seconds FROM audit WHERE user_id = ? AND allowed = 1 ORDER BY id", [$kid]);
check('כל כתובת נשמרת', array_column($h, 'url'), ['https://khanacademy.org/math', 'https://he.wikipedia.org/wiki/C']);
check('הזמן נזקף לכתובת שעל המסך', array_map('intval', array_column($h, 'seconds')), [60, 0]);

echo "\n— הדוח —\n";
addUsage($kid, $tz, 1200);
q('INSERT INTO usage (user_id, day, seconds) VALUES (?, ?, ?)', [$kid, (new DateTimeImmutable('-2 days', zoneOf($tz)))->format('Y-m-d'), 900]);
$r = activityReport($a1, $kid, 7);
check('7 ימים, גם ריקים', count($r['daily']), 7);
check('היום — האחרון', end($r['daily'])['day'], todayIn($tz));
check('לפני יומיים — 900', $r['daily'][4]['seconds'], 900);
check('סכום', $r['total_sec'], 2100);
check('האתרים, מהארוך לקצר', array_column($r['sites'], 'host'), ['khanacademy.org', 'he.wikipedia.org']);
check('חסימות — עם סיבה בעברית', [$r['blocked_count'], $r['blocks'][0]['host'], $r['blocks'][0]['reason']], [1, 'tiktok.com', 'לא ברשימה']);
check('היסטוריה — החדש קודם', $r['history'][0]['url'], 'https://he.wikipedia.org/wiki/C');
check('30 יום', count(activityReport($a1, $kid, 30)['daily']), 30);
check('ערך אחר — 7', count(activityReport($a1, $kid, 999)['daily']), 7);

echo "\n— ירידה ברמה מוחקת —\n";
reportLevelSave($a1, $kid, 'summary');
check('מלא → מסוכם: ההיסטוריה נמחקה', $n("SELECT COUNT(*) n FROM audit WHERE user_id = ? AND allowed = 1", [$kid]), 0);
check('...הסיכום לפי אתר נשאר', $n('SELECT COUNT(*) n FROM site_usage WHERE user_id = ?', [$kid]), 2);
check('...והחסימות נשארו', $n("SELECT COUNT(*) n FROM audit WHERE user_id = ? AND allowed = 0", [$kid]), 1);
check('בדוח אין היסטוריה', activityReport($a1, $kid)['history'], []);
reportLevelSave($a1, $kid, 'off');
check('כבוי: גם הסיכום נמחק', $n('SELECT COUNT(*) n FROM site_usage WHERE user_id = ?', [$kid]), 0);
activityNav($kid, 'https://he.wikipedia.org/wiki/D', $ok, $tz);
activityBeat($kid, 'https://he.wikipedia.org/wiki/D', 30, $tz);
check('כבוי: לא נשמר כלום על אתרים', $n('SELECT COUNT(*) n FROM site_usage WHERE user_id = ?', [$kid]), 0);
activityNav($kid, 'https://evil.com/', ['allow' => false, 'code' => 'rule_deny'], $tz);
check('כבוי: חסימות עדיין נשמרות', $n("SELECT COUNT(*) n FROM audit WHERE user_id = ? AND allowed = 0", [$kid]), 2);
check('כבוי: הזמן היומי נשאר (נדרש למכסה)', activityReport($a1, $kid)['total_sec'], 2100);
check('כבוי: בדוח אין אתרים', activityReport($a1, $kid)['sites'], []);

echo "\n— שמירה —\n";
q("INSERT INTO audit (user_id, at, kind, url, allowed, code) VALUES (?, ?, 'nav', 'https://old.com/', 0, 'not_listed')",
  [$kid, gmdate('Y-m-d\TH:i:s\Z', time() - 91 * 86400)]);
q('INSERT INTO site_usage (user_id, day, host, seconds) VALUES (?, ?, ?, ?)', [$kid, gmdate('Y-m-d', time() - 400 * 86400), 'old.com', 5]);
activityCleanup();
check('יומן מעל 90 יום — נמחק', $n("SELECT COUNT(*) n FROM audit WHERE url = 'https://old.com/'"), 0);
check('סיכום מעל שנה — נמחק', $n("SELECT COUNT(*) n FROM site_usage WHERE host = 'old.com'"), 0);
check('החדש נשאר', $n("SELECT COUNT(*) n FROM audit WHERE user_id = ? AND allowed = 0", [$kid]), 2);

echo "\n— בידוד —\n";
check('הילד של לוי — הדוח שלו ריק מאתרים של נועה', activityReport($a2, $kid2)['blocks'], []);
childDelete($kid);
check('מחיקת ילד מוחקת את הפעילות', $n('SELECT COUNT(*) n FROM site_usage WHERE user_id = ?', [$kid]) + $n('SELECT COUNT(*) n FROM audit WHERE user_id = ?', [$kid]), 0);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
