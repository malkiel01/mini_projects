<?php
/**
 * שלב 16 (docs/spec.md §15.12.1) — הצ'אט לא עונה: הגלאי, הכרטיס, המעבר, החכירה, החזרה והתקציר, הטרנזקציה,
 * ושום קריאה למודל בדרך. ספק מדומה ושעון מדומה (CT_FAKE_NOW).
 *
 *   php claude-tasks/tests/lanes16.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctl16-' . getmypid();
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
function throws(string $name, callable $fn, ?string $has = null) {
    try { $fn(); check($name, 'לא נזרקה חריגה', 'חריגה'); }
    catch (Throwable $e) { check($name . ' — ' . $e->getMessage(), $has === null || str_contains($e->getMessage(), $has), true); }
}
$calls = 0;
/** ספק מדומה שסופר קריאות. */
function transport(string $text, int &$calls): callable {
    return function () use ($text, &$calls) {
        $calls++;
        return ['status' => 200, 'body' => json_encode(['content' => [['type' => 'text', 'text' => $text]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 50]])];
    };
}
$GLOBALS['CT_FAKE_NOW'] = 1_800_000_000;
$tick = function (int $s) { $GLOBALS['CT_FAKE_NOW'] += $s; };
$col = fn (string $sql) => db()->query($sql)->fetchColumn();
$count = fn (int $cid, string $where) => (int) db()->query("SELECT COUNT(*) FROM messages WHERE channel_id = $cid AND $where")->fetchColumn();

createUser('boss', 'pass-12345', 'בעל הלוח');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$root = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
addConnection($boss, 'anthropic', 'sk-ant-bossbossbossbossboss', 'אישי', 'claude-opus-5-5');
setQuota($boss, 1, 25);
// §15.11: בלי תקרה אין מעבר אוטומטי שעולה כסף — כאן מגדירים תקרה, ובשלב 18 בודקים גם בלעדיה
db()->exec('UPDATE users SET auto_ceiling_usd = 10 WHERE id = 1');
$regToken = bin2hex(random_bytes(16));
db()->prepare('UPDATE projects SET agent_token = ? WHERE id = ?')->execute([encryptSecret($regToken), $root]);
$_SERVER['HTTP_X_WORKER_TOKEN'] = $regToken;
$mk = function (string $name) use ($root) {
    static $n = 0; $n++;
    $c = getChannel(registerChannel(workerScope(), $root, $name, 'קוד', "session_L16_$n", '')['channel_id']);
    db()->prepare('UPDATE channels SET listening_until = 0 WHERE id = ?')->execute([$c['id']]);
    return (int) $c['id'];
};

echo "\n— 1. שתיקה: מתחת לסף — כלום; בסף — בדיוק אחד; הודעה שנייה באותה אפיזודה — כלום —\n";
$a = $mk('שקט');
$m1 = channelSend($boss, $a, 'הודעה ראשונה');
$tick(9 * 60);
check('9 דק\' — כלום', [silenceCheck(getChannel($a)), $count($a, "kind = 'lane_card'")], ['', 0]);
$tick(60);
check('10 דק\' (ברירת המחדל) — כרטיס אחד ("לשאול")', [silenceCheck(getChannel($a)), $count($a, "kind = 'lane_card'")], ['card', 1]);
check('…הערוץ מסומן "לא עונה"', (int) getChannel($a)['unresponsive_since'] > 0, true);
channelSend($boss, $a, 'הודעה שנייה');
$tick(20 * 60);
check('הודעה שנייה באותה אפיזודה — אין כרטיס שני', [silenceCheck(getChannel($a)), $count($a, "kind = 'lane_card'")], ['', 1]);
$b = $mk('מאזין');
channelSend($boss, $b, 'הצ\'אט מאזין');
db()->prepare('UPDATE channels SET listening_until = ? WHERE id = ?')->execute([nowTs() + 3600, $b]);
$tick(30 * 60);
check('המאזין פעיל — לא "לא עונה" גם אחרי 30 דק\'', silenceCheck(getChannel($b)), '');
check('…ואין שתיקה בלי הודעה שממתינה', silenceCheck(getChannel($mk('ריק'))), '');
setLanePrefs($boss, ['silence_minutes' => '5']);
$c5 = $mk('חמש');
channelSend($boss, $c5, 'x');
$tick(5 * 60);
check('ההגדרה 5 דק\' — נספרת', silenceCheck(getChannel($c5)), 'card');
setLanePrefs($boss, ['silence_minutes' => '10', 'on_stop' => 'wait']);
$w = $mk('ממתין');
channelSend($boss, $w, 'x'); $tick(11 * 60);
check('"להמתין" — שורה אפורה אחת, בלי כרטיס ובלי מעבר', [silenceCheck(getChannel($w)), $count($w, "kind = 'status' AND body LIKE '%להמתין%'"), $count($w, "kind = 'lane_card'")], ['waiting', 1, 0]);
setLanePrefs($boss, ['on_stop' => 'auto']);
$au = $mk('אוטומטי');
$am = channelSend($boss, $au, 'x'); $tick(11 * 60);
check('"אוטומטי" — עובר ל-☁️', [silenceCheck(getChannel($au)), $col("SELECT answered_by FROM messages WHERE id = $am"), channelRoute(getChannel($au))], ['moved', 'platform', 'platform']);
check('…עם חכירה ו-handled_by=platform', [$col("SELECT handled_by FROM messages WHERE id = $am"), (int) $col("SELECT lease_until FROM messages WHERE id = $am") > nowTs()], ['platform', true]);
check('…ושורת system אחת', $count($au, "kind = 'status' AND body LIKE '%אוטומטי%'"), 1);

echo "\n— 2. הכרטיס: ⏳ — כלום לא זז; ☁️ — ההודעות עוברות ל-Platform —\n";
setLanePrefs($boss, ['on_stop' => 'ask']);
$card = (int) getChannel($a)['silence_card_id'];
$meta = json_decode((string) $col("SELECT meta FROM messages WHERE id = $card"), true);
check('הכרטיס: כמה ממתינות, הישנה, התקרה שנשארה', [$meta['waiting'], $meta['oldest_at'] > 0, $meta['ceiling_left'] > 0, $meta['platform_ok']], [1, true, true, true]);
db()->prepare("UPDATE channels SET limit_resets_at = ? WHERE id = ?")->execute([nowTs() + 7200, $a]);
$r = laneCardAnswer($boss, $card, 'wait');
check('⏳ — כלום לא זז, הערוץ נשאר 💬', [$r['moved'], channelRoute(getChannel($a)), $col("SELECT answered_by FROM messages WHERE id = $m1")], [0, 'chat', '']);
check('…ושעת האיפוס בשורה', $count($a, "kind = 'status' AND body LIKE '%מתאפסת%'"), 1);
throws('כרטיס שנסגר — לא עונים שוב', fn () => laneCardAnswer($boss, $card, 'platform'), 'נסגר');
$a2 = $mk('שקט 2');
$m2 = channelSend($boss, $a2, 'שאלה'); $tick(11 * 60); silenceCheck(getChannel($a2));
$r = laneCardAnswer($boss, (int) getChannel($a2)['silence_card_id'], 'platform');
check('☁️ — ההודעה עוברת ל-Platform, והלוח מקבל אותה להפעלה', [$r['moved'], $col("SELECT handled_by FROM messages WHERE id = $m2"), $r['run']], [1, 'platform', [$m2]]);
check('…הערוץ ☁️ עד שהצ\'אט יחזור', channelRoute(getChannel($a2)), 'platform');
$member = ['id' => 99, 'role' => 'user', 'username' => 'm', 'display_name' => 'm'];
throws('רק בעל הלוח עונה על הכרטיס', fn () => laneCardAnswer($member, $card, 'wait'), 'בעל הלוח');

echo "\n— 5. חכירה: הצ'אט מסמן הודעה ש-Platform כבר טיפל בה —\n";
$platformText = '{"reply": "תשובה מ-Platform", "task": null}';
$r = laneRun($boss, $a2, transport($platformText, $calls));
check('lane-run — Platform ענה על מה שעבר', [$r['ran'][0]['answered'] ?? null, $col("SELECT handled_by FROM messages WHERE id = $m2")], [true, 'platform']);
$ack = channelAckDetailed(getChannel($a2), [$m2]);
check('ack על מה שטופל — already_handled, לא מטופל שוב', [$ack['acked'], $ack['already_handled']], [0, [$m2]]);
check('…והצ\'אט לא רואה אותה בתיבה', in_array($m2, array_column(channelInbox(getChannel($a2)), 'id'), true), false);

echo "\n— 3. התקרה והמכסה —\n";
db()->exec('UPDATE users SET auto_ceiling_usd = 1 WHERE id = 1');
db()->prepare('INSERT INTO usage (at, user_id, project_id, kind, provider, model, cost_usd, auto) VALUES (?,1,?,?,?,?,?,1)')
    ->execute([nowTs(), $root, 'answer', 'anthropic', 'claude-opus-5-5', 0.98]);
setLanePrefs($boss, ['on_stop' => 'auto']);
$ce = $mk('תקרה');
channelSend($boss, $ce, 'x'); $tick(11 * 60);
check('מעבר שהיה עובר את התקרה הקרובה — הופך לכרטיס', silenceCheck(getChannel($ce)), 'card');
$cm = json_decode((string) $col('SELECT meta FROM messages WHERE id = ' . (int) getChannel($ce)['silence_card_id']), true);
check('…והכרטיס אומר למה', [str_contains($cm['blocked'], 'התקרה'), $cm['ceiling_by']], [true, 'התקרה שלך']);
check('…אבל בעל הלוח יכול לבחור ☁️ (התקרה רק למעבר אוטומטי)', laneCardAnswer($boss, (int) getChannel($ce)['silence_card_id'], 'platform')['decision'], 'platform');
db()->exec("UPDATE projects SET auto_ceiling_usd = 0.5 WHERE id = $root");
check('תקרת הפרויקט נמוכה משלי — היא החוסמת, בשמה', autoCeilingLeft(1, $root)['by'], 'mini_projects');
db()->exec('UPDATE users SET auto_ceiling_usd = 10 WHERE id = 1'); db()->exec("UPDATE projects SET auto_ceiling_usd = 0 WHERE id = $root");
db()->prepare('INSERT INTO usage (at, user_id, project_id, kind, provider, model, cost_usd) VALUES (?,1,?,?,?,?,?)')
    ->execute([nowTs(), $root, 'answer', 'anthropic', 'claude-opus-5-5', 30]);
$q = $mk('מכסה');
channelSend($boss, $q, 'x'); $tick(11 * 60);
check('מכסה 100% — כרטיס, ו"המכסה נגמרה"', [silenceCheck(getChannel($q)), str_contains((string) $col('SELECT meta FROM messages WHERE id = ' . (int) getChannel($q)['silence_card_id']), 'המכסה נגמרה')], ['card', true]);
throws('…ו-☁️ בכרטיס נדחה', fn () => laneCardAnswer($boss, (int) getChannel($q)['silence_card_id'], 'platform'), 'המכסה נגמרה');
db()->exec('DELETE FROM usage');

echo "\n— 4. Platform נעצר גם הוא —\n";
breakerTrip(1, 'credit_exhausted', 'Your credit balance is too low');
$br = $mk('מפסק');
channelSend($boss, $br, 'x'); $tick(11 * 60);
$calls = 0;
check('המפסק פתוח + צ\'אט שותק — כרטיס, לא מעבר', silenceCheck(getChannel($br)), 'card');
check('…הכרטיס אומר ש-Platform לא יכול לקחת', str_contains((string) $col('SELECT meta FROM messages WHERE id = ' . (int) getChannel($br)['silence_card_id']), 'נעצר גם הוא'), true);
throws('…ו-☁️ נדחה', fn () => laneCardAnswer($boss, (int) getChannel($br)['silence_card_id'], 'platform'), 'נעצר גם הוא');
check('…ושום דבר לא נשלח ל-Platform', [$calls, platformQueue($br)], [0, []]);
breakerReset(1);

echo "\n— 6. החזרה: התקציר, ≤ 1,500 תווים, לפני ההודעות; \"לחזור באמצע: לא\" — המסלול נשאר —\n";
for ($i = 0; $i < 12; $i++) {
    db()->prepare("INSERT INTO messages (channel_id, direction, author_role, kind, body, reply_to, answered_by, created_at)
                   VALUES (?, 'out', 'platform', 'answer', ?, ?, '', ?)")->execute([$a2, str_repeat("תשובה ארוכה $i ", 40), 1000 + $i, nowTs()]);
}
$waitMsg = channelSend($boss, $a2, 'עוד שאלה בזמן ש-Platform עונה');
db()->prepare("UPDATE messages SET answered_by = '', handled_by = '' WHERE id = ?")->execute([$waitMsg]);
$digest = laneReturn(getChannel($a2));
check('התקציר: מניין, ממתינות, אחרונה לכל שרשור', [str_contains($digest, 'Platform ענה על'), str_contains($digest, 'ממתינות לך: 1'), str_contains($digest, '— #')], [true, true, true]);
check('…לכל היותר 1,500 תווים', mb_strlen($digest) <= 1500, true);
check('…ומה שלא נכנס — "ועוד N — link.sh show"', str_contains($digest, 'link.sh show'), true);
check('האפיזודה נסגרה + שורת system אחת', [(int) getChannel($a2)['unresponsive_since'], $count($a2, "kind = 'status' AND body LIKE '%הצ''אט חזר%'")], [0, 1]);
check('"לחזור באמצע: לא" (ברירת המחדל) — הערוץ נשאר ☁️', channelRoute(getChannel($a2)), 'platform');
check('…חזרה נוספת — אין שורה שנייה', [laneReturn(getChannel($a2)) !== '', $count($a2, "kind = 'status' AND body LIKE '%הצ''אט חזר%'")], [true, 1]);
setLanePrefs($boss, ['return_preferred' => 'yes', 'preferred' => 'chat', 'on_stop' => 'ask']);
$rt = $mk('חוזר');
channelSend($boss, $rt, 'x'); $tick(11 * 60); silenceCheck(getChannel($rt));
laneCardAnswer($boss, (int) getChannel($rt)['silence_card_id'], 'platform');
laneReturn(getChannel($rt));
check('"לחזור באמצע: כן" — הערוץ חוזר ל-💬', channelRoute(getChannel($rt)), 'chat');
setLanePrefs($boss, ['return_preferred' => 'no', 'preferred' => '']);
check('בלי אפיזודה ובלי כלום במסלול אחר — תקציר ריק', laneReturn(getChannel($mk('שקט לגמרי'))), '');
check('show — הטקסט המלא של הודעה מהתקציר', mb_strlen(channelShow(getChannel($a2), (int) $col("SELECT MAX(id) FROM messages WHERE channel_id = $a2 AND author_role = 'platform'"))['body']) > 300, true);
throws('show — רק הודעה מהערוץ הזה', fn () => channelShow(getChannel($a), $m2), 'לא נמצאה');

// ערוץ ותיק (הודעות מלפני שלב 16, handled_by ריק): התקציר סופר רק מאז ה-ack האחרון שלו — לא את כל ההיסטוריה
$old = $mk('ותיק');
db()->prepare("INSERT INTO messages (channel_id, direction, author_role, kind, body, reply_to, answered_by, created_at) VALUES (?, 'out', 'platform', 'answer', 'ישן', 1, '', ?)")->execute([$old, nowTs() - 7200]);
$om = channelInsertIn($old, 'owner', 1, 'הודעה ישנה');
db()->prepare('UPDATE messages SET delivered_at = ?, handled_by = \'\' WHERE id = ?')->execute([nowTs() - 3600, $om]);
check('ערוץ ותיק — תשובה מלפני ה-ack האחרון לא בתקציר', channelDigest(getChannel($old)), '');

echo "\n— 7. ההודעה והמצב — טרנזקציה אחת —\n";
$tx = $mk('טרנזקציה');
$before = $count($tx, '1');
$GLOBALS['CT_STATE_FAIL'] = true;
try { channelInsertIn($tx, 'owner', 1, 'נופל באמצע'); } catch (Throwable $e) {}
unset($GLOBALS['CT_STATE_FAIL']);
check('כשל בין ההודעה למצב — שניהם לא נכתבו', [$count($tx, '1'), db()->inTransaction()], [$before, false]);
$id = channelInsertIn($tx, 'owner', 1, 'עובר');
check('בלי כשל — ההודעה והמצב יחד', [$count($tx, '1'), in_array($id, channelState($tx)['waiting'], true)], [$before + 1, true]);
channelAck(getChannel($tx), [$id]);
check('ack — המצב מתעדכן באותה פעולה', in_array($id, channelState($tx)['waiting'], true), false);

echo "\n— 8. אין קריאה למודל בדרך: שתיקה → כרטיס / מעבר → תקציר —\n";
$calls = 0;
$GLOBALS['CT_TRANSPORT_GUARD'] = true;
setLanePrefs($boss, ['on_stop' => 'auto']);
$nm = $mk('בלי מודל');
channelSend($boss, $nm, 'x'); $tick(11 * 60);
silenceCheck(getChannel($nm)); channelsFor($boss, $root); laneReturn(getChannel($nm)); channelDigest(getChannel($nm));
check('הספק לא נקרא אף פעם', $calls, 0);
setLanePrefs($boss, ['on_stop' => 'ask']);

echo "\n— מגבלה (StopFailure, שלב 0) —\n";
$lm = $mk('מגבלה');
channelSend($boss, $lm, 'x');
check('error לא מוכר (תקלה רגעית) — לא נרשם', channelLimit(getChannel($lm), 'overloaded')['recorded'], false);
$r = channelLimit(getChannel($lm), 'rate_limit', 'limit reached, resets 2027-02-01T10:00:00Z');
check('rate_limit — אפיזודה מיד, בלי לחכות לשתיקה', [$r['action'], getChannel($lm)['unresponsive_reason']], ['card', 'limit']);
check('…וזמן האיפוס נשמר', (int) getChannel($lm)['limit_resets_at'], (int) strtotime('2027-02-01T10:00:00Z'));
$info = channelLaneInfo(getChannel($lm));
check('השורה בלוח: לא עונה + מגבלה', [$info['unresponsive_since'] > 0, $info['limit_status']], [true, 'rate_limit']);

echo "\n— 9. סיכום חכם —\n";
$calls = 0;
$sm = $mk('סיכום');
channelSend($boss, $sm, 'מה המצב?');
channelPost(getChannel($sm), 'chat', 'עובדים על שלב 16');
$s = channelSummary($boss, $sm, transport('מצב: שלב 16 בעבודה. פתוח: הבדיקות.', $calls));
check('קריאה אחת, נשמר כהודעת system מוצמדת', [$calls, $s['message']['author_role'], (int) $col("SELECT COUNT(*) FROM message_pins WHERE message_id = {$s['message']['id']}")], [1, 'system', 1]);
check('…נזקף למשלם (answer)', (int) $col("SELECT COUNT(*) FROM usage WHERE user_id = 1 AND kind = 'answer' AND channel_id = $sm"), 1);
$s2 = channelSummary($boss, $sm, transport('לא אמור להיקרא', $calls));
check('שוב בלי הודעות חדשות — אותו סיכום, בלי קריאה', [$s2['cached'], $calls, $s2['message']['id']], [true, 1, $s['message']['id']]);
throws('רק בעל הלוח', fn () => channelSummary($member, $sm), 'בעל הלוח');
db()->prepare('INSERT INTO usage (at, user_id, project_id, kind, provider, model, cost_usd) VALUES (?,1,?,?,?,?,?)')
    ->execute([nowTs(), $root, 'answer', 'anthropic', 'claude-opus-5-5', 30]);
channelSend($boss, $sm, 'עוד הודעה');
throws('מכסה 100% — נדחה', fn () => channelSummary($boss, $sm, transport('x', $calls)), 'המכסה החודשית נגמרה');
check('…ובלי קריאה', $calls, 1);
db()->exec('DELETE FROM usage');
$hr = handoffRequest($boss, $sm);
$hp = handoffPrompt($boss, $sm, true);
check('צ\'אט המשך בלי תקציר — הסיכום החכם הוא הבסיס', str_contains($hp['prompt'], 'שלב 16 בעבודה'), true);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
