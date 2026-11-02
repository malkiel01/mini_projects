<?php
/**
 * שלב 8 (docs/spec.md §9) — Platform עונה בערוץ ☁️, הצ'אט נשאר מעודכן בלי
 * להתעורר, ומעקב: סיכום, ממתינים, "נשאל כבר", התאמת סוג, והשער של 10 דקות.
 *
 *   php claude-tasks/tests/answers.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctans-' . getmypid();
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
function throws(string $name, callable $fn, int $status = 0) {
    try { $fn(); check($name, 'לא נזרקה חריגה', 'חריגה'); }
    catch (AppError $e) { check($name . ' — ' . $e->getMessage(), $status === 0 || $e->status === $status, true); }
    catch (Throwable $e) { check($name . ' — ' . $e->getMessage(), $status === 0, true); }
}
/** ספק מדומה שמחזיר טקסט קבוע; סופר קריאות ושומר את ההנחיה האחרונה. */
function fake(string $text, ?array &$seen = null): callable {
    return function ($prov, $url, $json, $key) use ($text, &$seen) {
        $seen[] = json_decode($json, true);
        return ['status' => 200, 'body' => json_encode(['content' => [['type' => 'text', 'text' => $text]],
                                                        'usage' => ['input_tokens' => 1000, 'output_tokens' => 200]])];
    };
}

createUser('boss', 'pass-12345', 'בעל הלוח');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$root = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
addConnection($boss, 'anthropic', 'sk-ant-bossbossbossbossboss', 'אישי', 'claude-opus-5-5');
$regToken = bin2hex(random_bytes(16));
db()->prepare('UPDATE projects SET agent_token = ? WHERE id = ?')->execute([encryptSecret($regToken), $root]);
$_SERVER['HTTP_X_WORKER_TOKEN'] = $regToken;
$ch = getChannel(registerChannel(workerScope(), $root, 'כותב הקוד', 'קוד', 'session_ANSWER1', '')['channel_id']);
$cid = (int) $ch['id'];
$bell = fn () => bellValue($cid);

echo "\n— במסלול הצ'אטים —\n";
$m0 = channelSend($boss, $cid, 'שאלה לצ\'אט');
throws('platform-reply בערוץ של הצ\'אטים — 409', fn () => platformReply($boss, $m0), 409);
check('ההודעה מעירה את הצ\'אט כרגיל', count(channelWait($ch, 0)), 1);
channelAck($ch, [$m0]);

echo "\n— ☁️: שליחה לא מעירה —\n";
setQuota($boss, 1, 25);
db()->prepare("UPDATE projects SET route = 'platform' WHERE id = ?")->execute([$root]);
check('הערוץ במסלול ☁️', channelRoute(getChannel($cid)), 'platform');
$b0 = $bell();
$m1 = channelSend($boss, $cid, 'מה מצב הפריסה?');
check('בלי פעמון', $bell(), $b0);
check('לא בתיבה של הצ\'אט, ולא מעירה', [count(channelInbox($ch)), count(channelWait($ch, 0))], [0, 0]);
$view = channelMessages($boss, $cid);
check('בלוח — מסומנת "ממתינה ל-Platform"', end($view['messages'])['platform_pending'], true);
check('הערוץ אומר באיזה מסלול', $view['channel']['route_effective'], 'platform');

echo "\n— platform-reply —\n";
$seen = [];
$r = platformReply($boss, $m1, fake('{"reply": "הפריסה עברה בהצלחה.", "task": null}', $seen));
check('תשובה: out, platform, answer, reply_to', [$r['message']['direction'], $r['message']['author_role'], $r['message']['kind'], $r['message']['reply_to'], $r['message']['body']],
      ['out', 'platform', 'answer', $m1, 'הפריסה עברה בהצלחה.']);
$prompt = $seen[0]['messages'][0]['content'] ?? '';
check('ההקשר: השיחה והמטלות הפתוחות בהנחיה', [str_contains($prompt, 'מה מצב הפריסה?'), str_contains($prompt, '## מטלות פתוחות')], [true, true]);
$orig = db()->query("SELECT answered_by, delivered_at FROM messages WHERE id = $m1")->fetch();
check('ההודעה המקורית — נענתה ונמסרה', [$orig['answered_by'], $orig['delivered_at'] !== null], ['platform', true]);
$inbox = channelInbox($ch);
check('הצ\'אט מקבל status בתיבה — עם השאלה והתשובה', [count($inbox), $inbox[0]['kind'] ?? null, str_contains($inbox[0]['body'] ?? '', 'הפריסה עברה')], [1, 'status', true]);
check('…אבל status לא מעיר אותו', count(channelWait($ch, 0)), 0);
check('…ובלי פעמון', $bell(), $b0);
check('השימוש נרשם כ-answer, עם הערוץ', db()->query("SELECT kind, channel_id FROM usage ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_NUM), ['answer', $cid]);
check('קריאה שנייה — אותה תשובה, בלי לשאול שוב את המודל', [platformReply($boss, $m1, fake('x', $seen))['message']['id'], count($seen)], [$r['message']['id'], 1]);

echo "\n— הצעת מטלה —\n";
$m2 = channelSend($boss, $cid, 'תתקן את הכפתור');
$r2 = platformReply($boss, $m2, fake('```json
{"reply": "זה דורש שינוי בקוד.", "task": {"title": "תיקון הכפתור", "body": "ב-app.js"}}
```'));
check('כשצריך קוד — מוצעת מטלה (meta.task)', $r2['message']['meta']['task'] ?? null, ['title' => 'תיקון הכפתור', 'body' => 'ב-app.js']);
$m3 = channelSend($boss, $cid, 'תודה');
check('תשובה שאינה JSON — הטקסט כולו, בלי מטלה', [platformReply($boss, $m3, fake('בכיף!'))['message']['body'], null], ['בכיף!', null]);

echo "\n— אין מכסה / שני מסכים —\n";
$m4 = channelSend($boss, $cid, 'עוד שאלה');
db()->prepare("UPDATE messages SET answered_by = ? WHERE id = ?")->execute(['platform-busy:' . nowTs(), $m4]);
throws('מסך אחר כבר עונה — 409', fn () => platformReply($boss, $m4, fake('x')), 409);
db()->prepare("UPDATE messages SET answered_by = ? WHERE id = ?")->execute(['platform-busy:' . (nowTs() - 600), $m4]);
check('busy ישן (בקשה שמתה) — נתפס מחדש', platformReply($boss, $m4, fake('{"reply":"כן"}'))['message']['body'], 'כן');
$m5 = channelSend($boss, $cid, 'בלי מכסה');
setQuota($boss, 1, 0);
check('המכסה כבויה — הערוץ חוזר לצ\'אטים', channelRoute(getChannel($cid)), 'chat');
$b1 = $bell();
$r5 = platformReply($boss, $m5, fake('לא אמור להישאל'));
check('הודעה שחיכתה ל-Platform עוברת לצ\'אט — עם פעמון', [$r5['handed_to_chat'] ?? false, $bell() !== $b1], [true, true]);
check('…ועכשיו היא בתיבה ומעירה', count(channelWait($ch, 0)), 1);

echo "\n— מעקב —\n";
db()->exec("UPDATE projects SET monitor_at = 0");
check('בלי מכסה — off בשקט', monitorTick($root, 0, fake('x'))['monitor'], 'off');
setQuota($boss, 1, 25);
$mk = function (string $title, string $status) use ($root): int {
    db()->prepare('INSERT INTO tasks (title, project_id, status, created_by, created_at, updated_at) VALUES (?,?,?,1,?,?)')
        ->execute([$title, $root, $status, nowTs(), nowTs()]);
    return (int) db()->lastInsertId();
};
$old = $mk('ישנה', 'answered');
addNote(db(), $old, 'bot', 'question', 'באיזה צבע לצבוע את כפתור השמירה בדף ההגדרות?');
addNote(db(), $old, 'בעל הלוח', 'answer', 'כחול של המותג', 'owner', 1);
$new = $mk('חדשה', 'blocked');
addNote(db(), $new, 'bot', 'question', 'איזה צבע לצבוע את כפתור השמירה בדף ההגדרות החדש?');
$ap = $mk('אישור', 'awaiting_approval');
addNote(db(), $ap, 'bot', 'approval', json_encode(['type' => 'other', 'summary' => 'למזג את ה-PR שלי ל-main']));
db()->prepare('UPDATE tasks SET updated_at = ? WHERE id = ?')->execute([nowTs() - 7200, $ap]);
$w = waitingItems($root);
check('ממתינים — הוותיק קודם', array_column($w, 'task_id'), [$ap, $new]);
check('"נשאל כבר" — עם התשובה הקודמת', [$w[1]['already_asked']['task_id'] ?? null, $w[1]['already_asked']['answer'] ?? null], [$old, 'כחול של המותג']);
check('בקשה בלי סוג — סוג מוצע', $w[0]['suggested_type'], 'merge-own-pr');
$t = monitorTick($root, 0, fake('{"lines": ["זז: שלב 8", "תקוע: כלום", "ממתין לך: אישור מיזוג"]}'));
check('סיכום של 3 שורות נשמר', [$t['monitor'], count($t['summary']['lines'])], ['on', 3]);
check('…ונרשם כ-monitor', db()->query("SELECT kind FROM usage ORDER BY id DESC LIMIT 1")->fetchColumn(), 'monitor');
check('אחרי block — לא יותר מפעם ב-10 דקות', monitorTick($root, MONITOR_MIN_GAP, fake('x'))['monitor'], 'skipped');
check('project-monitor: הסיכום והממתינים', [count(projectMonitor($root)['summary']['lines']), count(projectMonitor($root)['waiting']), projectMonitor($root)['monitor']], [3, 2, 'on']);
db()->exec('UPDATE projects SET monitor_at = 0');
check('פתיחת הלוח (lazy) — רץ על פרויקט שעברה שעה', monitorLazy($boss, 2, fake('{"lines":["א","ב","ג"]}')), [$root => 'on']);
check('…ושוב מיד — לא רץ', monitorLazy($boss, 2, fake('x')), []);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
