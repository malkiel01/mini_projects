<?php
/**
 * שלב 15 (docs/spec.md §15.3) — Platform נעצר: סיווג כשלי ספק, המפסק לכל משלם, העברה לצ'אט (גם של מה שכבר
 * ממתין, במעבר אחד), הודעה אחת בפתיחה ואחת בחזרה, "נסה שוב", וההגדרה של המשתמש (כבויה → ממתינות).
 * ספק מדומה בלבד — הבדיקה החיה היא שלב 19.
 *
 *   php claude-tasks/tests/lanes.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctlanes-' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');
define('BELL_DIR', $tmp . '/bell');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/projects.php';
require_once __DIR__ . '/../lib/channels.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}
function throws(string $name, callable $fn) {
    try { $fn(); check($name, 'לא נזרקה חריגה', 'חריגה'); }
    catch (Throwable $e) { check($name . ' — ' . $e->getMessage(), true, true); }
}
/** ספק מדומה: קוד וגוף קבועים; סופר קריאות. */
function provider(int $status, array $body, int &$calls = 0): callable {
    return function () use ($status, $body, &$calls) { $calls++; return ['status' => $status, 'body' => json_encode($body)]; };
}
$okBody = ['content' => [['type' => 'text', 'text' => '{"reply": "כן.", "task": null}']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]];
$credit = ['type' => 'error', 'error' => ['type' => 'invalid_request_error',
           'message' => 'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.']];

echo "\n— סיווג כשלי ספק —\n";
check('Anthropic 400 עם הודעת קרדיט → credit_exhausted', aiFailureClass(400, $credit['error']['message'], 'invalid_request_error'), 'credit_exhausted');
check('402 → credit_exhausted', aiFailureClass(402, ''), 'credit_exhausted');
check('OpenAI 429 insufficient_quota → credit_exhausted', aiFailureClass(429, 'You exceeded your current quota', 'insufficient_quota'), 'credit_exhausted');
check('401 → key_rejected', aiFailureClass(401, 'invalid x-api-key'), 'key_rejected');
check('403 → key_rejected', aiFailureClass(403, 'forbidden'), 'key_rejected');
check('429 → rate_limited', aiFailureClass(429, 'rate limit'), 'rate_limited');
check('529 (overloaded) → rate_limited', aiFailureClass(529, 'Overloaded'), 'rate_limited');
check('500 → down', aiFailureClass(500, ''), 'down');
check('0 (רשת) → down', aiFailureClass(0, ''), 'down');
check('400 אחר → bad_request', aiFailureClass(400, 'max_tokens: must be positive'), 'bad_request');
check('404 → bad_request', aiFailureClass(404, 'model not found'), 'bad_request');
$err = null;
try { aiComplete(['provider' => 'anthropic', 'key' => 'sk-ant-x', 'model' => 'claude-opus-5-5'], 's', 'p', 10, provider(400, $credit)); }
catch (AppError $e) { $err = $e; }
check('aiComplete מסמן את הכשל', [$err?->aiClass, $err?->aiStatus], ['credit_exhausted', 400]);
check('…והשורה בעברית', str_contains((string) $err?->getMessage(), 'נגמר הקרדיט'), true);
check('קרדיט ריק עובר לחיבור הבא (לא עוצר את השרשרת)', shouldFallOver($err), true);
$bad = new AppError('x'); $bad->aiStatus = 400; $bad->aiClass = 'bad_request';
check('רק בקשה שגויה עוצרת את השרשרת', shouldFallOver($bad), false);

echo "\n— הכנה: ערוץ ☁️ —\n";
createUser('boss', 'pass-12345', 'בעל הלוח');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$root = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
addConnection($boss, 'anthropic', 'sk-ant-bossbossbossbossboss', 'אישי', 'claude-opus-5-5');
setQuota($boss, 1, 25);
db()->prepare("UPDATE projects SET route = 'platform' WHERE id = ?")->execute([$root]);
$regToken = bin2hex(random_bytes(16));
db()->prepare('UPDATE projects SET agent_token = ? WHERE id = ?')->execute([encryptSecret($regToken), $root]);
$_SERVER['HTTP_X_WORKER_TOKEN'] = $regToken;
$ch = getChannel(registerChannel(workerScope(), $root, 'מתכלל', 'תכלול', 'session_LANES1', '')['channel_id']);
$cid = (int) $ch['id'];
$ch2 = getChannel(registerChannel(workerScope(), $root, 'רסטר', 'רסטר', 'session_LANES2', '')['channel_id']);
$systemCount = fn (int $c, string $like) => (int) db()->query("SELECT COUNT(*) FROM messages WHERE channel_id = $c AND author_role = 'system' AND body LIKE '%$like%'")->fetchColumn();
check('המסלול ☁️, המפסק סגור', [channelRoute(getChannel($cid)), platformBreaker(1)['open']], ['platform', false]);
check('ברירת המחדל של המשתמש: להעביר לצ\'אט', lanePrefs(1), ['platform_stop' => 'handover']);

echo "\n— קרדיט נגמר: המפסק נפתח, הכול עובר לצ'אט —\n";
$waiting = channelSend($boss, (int) $ch2['id'], 'הודעה שממתינה ל-Platform בערוץ אחר');
$m1 = channelSend($boss, $cid, 'תפעיל את הרסטר');
$b0 = bellValue($cid);
$r = platformReply($boss, $m1, provider(400, $credit));
check('platform-reply לא נזרק — מועבר לצ\'אט עם סיבה', [$r['answered'], $r['handed_to_chat'] ?? false, str_contains($r['reason'] ?? '', 'נגמר הקרדיט')], [false, true, true]);
check('ההודעה — אצל הצ\'אט (לא תלויה)', db()->query("SELECT answered_by FROM messages WHERE id = $m1")->fetchColumn(), '');
check('…והצ\'אט מתעורר', bellValue($cid) !== $b0, true);
check('גם מה שכבר חיכה ל-Platform בערוץ אחר — עבר במעבר אחד', db()->query("SELECT answered_by FROM messages WHERE id = $waiting")->fetchColumn(), '');
$bk = platformBreaker(1);
check('המפסק: credit_exhausted, ידני (בלי ניסיון לבד)', [$bk['open'], $bk['state'], $bk['manual'], $bk['retry_at']], [true, 'credit_exhausted', true, 0]);
check('הערוץ מחושב עכשיו כ-💬', channelRoute(getChannel($cid)), 'chat');
check('הודעת system אחת לכל ערוץ — "נעצר"', [$systemCount($cid, 'Platform נעצר'), $systemCount((int) $ch2['id'], 'Platform נעצר')], [1, 1]);
$calls = 0;
$m2 = channelSend($boss, $cid, 'ועוד הודעה');
check('הודעה חדשה הולכת ישר לצ\'אט (לא ממתינה ל-Platform)', db()->query("SELECT answered_by FROM messages WHERE id = $m2")->fetchColumn(), '');
throws('platform-reply עליה — המסלול צ\'אט (409)', fn () => platformReply($boss, $m2, provider(200, $okBody, $calls)));
check('…והספק לא נקרא', $calls, 0);
check('עדיין הודעת "נעצר" אחת בלבד', $systemCount($cid, 'Platform נעצר'), 1);

echo "\n— \"נסה שוב\" —\n";
$st = platformRetry($boss, provider(400, $credit));
check('בדיקה שנכשלה — נשאר פתוח, בלי הודעה נוספת', [$st['open'], $systemCount($cid, 'Platform נעצר')], [true, 1]);
$st = platformRetry($boss, provider(200, ['content' => [['type' => 'text', 'text' => 'ok']]]));
check('בדיקה שהצליחה — נסגר', $st['open'], false);
check('הודעת system אחת לכל ערוץ — "חזר"', [$systemCount($cid, 'Platform חזר'), $systemCount((int) $ch2['id'], 'Platform חזר')], [1, 1]);
check('הערוץ שוב ☁️', channelRoute(getChannel($cid)), 'platform');

echo "\n— עומס: ניסיון חוזר לבד אחרי 5 דקות —\n";
$m3 = channelSend($boss, $cid, 'שאלה בזמן עומס');
platformReply($boss, $m3, provider(529, ['error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]));
$bk = platformBreaker(1);
check('rate_limited, ניסיון בעוד 5 דק\'', [$bk['state'], $bk['retry_at'] - time() >= 295 && $bk['retry_at'] - time() <= 300], ['rate_limited', true]);
db()->prepare('UPDATE platform_state SET retry_at = ? WHERE user_id = 1')->execute([time() - 1]);
check('הגיע הזמן — ☁️ שוב (half-open)', channelRoute(getChannel($cid)), 'platform');
$m4 = channelSend($boss, $cid, 'אחרי העומס');
$r = platformReply($boss, $m4, provider(200, $okBody));
check('ההודעה הבאה נענתה — והמפסק נסגר', [$r['answered'], platformBreaker(1)['open']], [true, false]);
check('"חזר" פעם שנייה (אפיזודה חדשה), לא יותר', $systemCount($cid, 'Platform חזר'), 2);

echo "\n— תקלה כללית: 30 דקות; מפתח: ידני —\n";
platformReply($boss, channelSend($boss, $cid, 'x'), provider(503, []));
check('down → בעוד 30 דק\'', [platformBreaker(1)['state'], platformBreaker(1)['retry_at'] - time() >= 1795], ['down', true]);
breakerReset(1);
platformReply($boss, channelSend($boss, $cid, 'y'), provider(401, ['error' => ['message' => 'invalid x-api-key']]));
check('key_rejected → ידני', [platformBreaker(1)['state'], platformBreaker(1)['manual']], ['key_rejected', true]);
updateConnection($boss, (int) db()->query('SELECT id FROM connections LIMIT 1')->fetchColumn(), ['label' => 'אישי 2']);
check('שינוי חיבור → נבדק שוב בהודעה הבאה', platformBreaker(1)['probe_due'], true);
breakerReset(1);

echo "\n— בקשה שגויה: לא פותח, אבל ההודעה לא נתקעת —\n";
$m5 = channelSend($boss, $cid, 'בקשה שגויה');
$r = platformReply($boss, $m5, provider(404, ['error' => ['message' => 'model not found']]));
check('bad_request — המפסק סגור', platformBreaker(1)['open'], false);
check('…ההודעה עברה לצ\'אט, עם שורת system על ההודעה הזו', [$r['handed_to_chat'] ?? false, $systemCount($cid, 'לא הצליח לענות')], [true, 1]);

echo "\n— ההגדרה כבויה: ההודעות ממתינות —\n";
throws('ערך לא מוכר', fn () => setLanePrefs($boss, ['platform_stop' => 'maybe']));
check('המשתמש בוחר "ממתינות"', setLanePrefs($boss, ['platform_stop' => 'wait'])['platform_stop'], 'wait');
$m6 = channelSend($boss, $cid, 'הודעה כשהקרדיט נגמר וההגדרה כבויה');
$r = platformReply($boss, $m6, provider(400, $credit));
check('נעצר — אבל ההודעה ממתינה ל-Platform', [$r['waiting'] ?? false, db()->query("SELECT answered_by FROM messages WHERE id = $m6")->fetchColumn()], [true, 'platform']);
check('הערוץ נשאר ☁️ (לא עובר לצ\'אט)', channelRoute(getChannel($cid)), 'platform');
check('הודעת "נעצר" אומרת שממתינות', $systemCount($cid, 'ההודעות ממתינות'), 1);
$calls = 0;
$m7 = channelSend($boss, $cid, 'עוד אחת');
$r = platformReply($boss, $m7, provider(200, $okBody, $calls));
check('בזמן שהמפסק פתוח — הספק לא נקרא, ממתינה', [$calls, $r['waiting'] ?? false], [0, true]);
check('סיכום השימוש כולל את המפסק ואת ההגדרה', [usageSummary(1)['platform']['state'], usageSummary(1)['lane_prefs']['platform_stop']], ['credit_exhausted', 'wait']);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
