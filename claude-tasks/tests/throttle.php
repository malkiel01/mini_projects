<?php
/**
 * בלמי הגשר (docs/spec.md §5.6, אחרי ה-429 ב-2026-11-02): בלי long-poll כברירת מחדל,
 * עצירה כללית (503 + Retry-After), ותקציב לכל אסימון ערוץ (429).
 *
 *   php claude-tasks/tests/throttle.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctthr-' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');
define('BELL_DIR', $tmp . '/bell');
define('THROTTLE_DIR', $tmp . '/throttle');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/projects.php';
require_once __DIR__ . '/../lib/channels.php';
require_once __DIR__ . '/../lib/throttle.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}
function throws(string $name, callable $fn, int $status = 0) {
    try { $fn(); check($name, 'לא נזרקה חריגה', 'חריגה'); }
    catch (AppError $e) { check($name . ' — ' . $e->getMessage(), $status === 0 || $e->status === $status, true); }
}

createUser('boss', 'pass-12345', 'בעל הלוח');
createUser('dana', 'pass-12345', 'דנה');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$dana = ['id' => 2, 'role' => 'member', 'username' => 'dana', 'display_name' => 'דנה'];
$root = createProject($boss, 'Mini projects', 'malkiel01', 'mini_projects');
$ch = registerChannel(['project_id' => null, 'channel_id' => null], $root, 'בדיקה', 'dev', 'session_thr_a', '');
$channel = getChannel($ch['channel_id']);

echo "\n— בלי long-poll כברירת מחדל —\n";
$sleeps = 0;
$t0 = nowTs();
$msgs = channelWait($channel, 20, function () use (&$sleeps) { $sleeps++; });
check('wait=20 בלי long_poll_max — חוזר מיד, בלי שינה', [$msgs, $sleeps], [[], 0]);
$lu = (int) getChannel($ch['channel_id'])['listening_until'];
check('"מקשיב" נשאר דולק בין שתי בדיקות (≥ 80 ש\')', $lu - $t0 >= 80 && $lu - $t0 <= 95, true);
configSet(['long_poll_max' => 5]);
$sleeps = 0;
channelWait($channel, 20, function () use (&$sleeps) { $sleeps++; });
check('long_poll_max מפורש — עדיין תקרה', $sleeps, 5);
configSet(['long_poll_max' => null]);

echo "\n— ההכרעה —\n";
check('פעולה אחרת — עוברת, גם בעצירה', bridgeVerdict('channel-send', ['bridge_paused' => 1], 30), null);
check('בלי עצירה ובלי חריגה — עוברת', bridgeVerdict('channel-wait', [], 0), null);
check('עצירה — channel-wait: 503 + שעה', array_slice(bridgeVerdict('channel-wait', ['bridge_paused' => 1], null), 0, 3), [503, 3600, 'bridge_paused']);
check('עצירה — גם channel-inbox', bridgeVerdict('channel-inbox', ['bridge_paused' => 1], null)[0], 503);
check('חריגה מהתקציב — 429 עם כמה לחכות', array_slice(bridgeVerdict('channel-wait', [], 7), 0, 3), [429, 7, 'rate_limited']);
check('התקציב — רק channel-wait', bridgeVerdict('channel-inbox', [], 7), null);

echo "\n— התקציב: בקשה אחת לכל 10 ש' לאסימון —\n";
$now = 1_000_000;
check('ראשונה — עוברת', bridgeBudget('tok-a', $now), 0);
check('אחרי 4 ש\' — לחכות 6', bridgeBudget('tok-a', $now + 4), 6);
check('אסימון אחר — לא מושפע', bridgeBudget('tok-b', $now + 4), 0);
check('דחייה לא מאריכה את החלון — אחרי 10 ש\' עוברת', bridgeBudget('tok-a', $now + 10), 0);
check('ומיד אחריה — שוב 10', bridgeBudget('tok-a', $now + 10), 10);
$files = glob(THROTTLE_DIR . '/*');
check('בקובץ, לא במסד — ושם הקובץ אינו האסימון', [count($files), str_contains(implode('', $files), 'tok-a')], [2, false]);

echo "\n— ⏸ עצירה / ▶ החזרה —\n";
throws('דנה — לא עוצרת', fn () => bridgeSetPaused($dana, true), 403);
$r = bridgeSetPaused($boss, true);
check('בעל הלוח — עוצר (זמן העצירה נשמר)', $r['bridge_paused'] > 0 && !empty(config()['bridge_paused']), true);
check('ואז כל channel-wait נבלם', bridgeVerdict('channel-wait', config(), null)[0], 503);
bridgeSetPaused($boss, false);
check('החזרה — השדה נמחק', array_key_exists('bridge_paused', config()), false);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
