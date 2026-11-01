<?php
/**
 * שלב 7 (docs/spec.md §8) — מכסה ושימוש: רישום מכל קריאה, מחיר לפי מודל,
 * 80% / 100%, איפוס חודשי, ושרשרת החיבורים לא עוקפת את המכסה.
 *
 *   php claude-tasks/tests/usage.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctu-' . getmypid();
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
/** ספק מדומה: מחזיר טקסט ו-usage; ‏$fail — מפתחות שנכשלים. */
function fake(array $usage, array $fail = [], ?array &$calls = null): callable {
    return function ($prov, $url, $json, $key) use ($usage, $fail, &$calls) {
        $calls[] = $key;
        if (isset($fail[$key])) return ['status' => $fail[$key], 'body' => json_encode(['error' => ['message' => 'x']])];
        return ['status' => 200, 'body' => json_encode([
            'content' => [['type' => 'text', 'text' => 'אישור']], 'usage' => $usage,
            'choices' => [['message' => ['content' => 'אישור']]],
        ])];
    };
}
$sum = fn (int $uid) => round((float) db()->query("SELECT COALESCE(SUM(cost_usd),0) FROM usage WHERE user_id = $uid")->fetchColumn(), 6);

createUser('boss', 'pass-12345', 'בעל הלוח');
createUser('dana', 'pass-12345', 'דנה');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$dana = ['id' => 2, 'role' => 'member', 'username' => 'dana', 'display_name' => 'דנה'];
$root = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');

echo "\n— מחיר —\n";
check('Opus 5.5 — $4 / $20 למיליון', usageCost('anthropic', 'claude-opus-5-5', 1_000_000, 1_000_000), 24.0);
check('תאריך בשם המודל — לפי התחילית', modelPrice('anthropic', 'claude-haiku-4-5-20251001'), [1.0, 5.0, true]);
check('התחילית הארוכה גוברת (gpt-5-mini ולא gpt-5)', modelPrice('openai', 'gpt-5-mini-2025')[0], 0.25);
check('מודל לא מוכר — המחיר הגבוה של אותו ספק, לא 0', modelPrice('anthropic', 'claude-future-9'), [10.0, 50.0, false]);
check('usage מהתשובה: Anthropic כולל מטמון', usageFromResponse('anthropic', ['usage' => ['input_tokens' => 10, 'cache_read_input_tokens' => 5, 'output_tokens' => 7]]), [15, 7]);
check('usage מהתשובה: Google כולל חשיבה', usageFromResponse('google', ['usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 4, 'thoughtsTokenCount' => 6]]), [3, 10]);

echo "\n— רישום —\n";
$cid = addConnection($boss, 'anthropic', 'sk-ant-bossbossbossbossboss', 'אישי', 'claude-opus-5-5');
setQuota($boss, 1, 10);
$conn = userConn($boss);
$conn['usage']['kind'] = 'answer';
$conn['usage']['project_id'] = $root;
aiComplete($conn, 's', 'p', 100, fake(['input_tokens' => 1000, 'output_tokens' => 500]));
$row = db()->query('SELECT * FROM usage ORDER BY id DESC LIMIT 1')->fetch();
check('קריאה נרשמה: מי שילם, חיבור, סוג, פרויקט, טוקנים', [(int) $row['user_id'], (int) $row['connection_id'], $row['kind'], (int) $row['project_id'], (int) $row['tokens_in'], (int) $row['tokens_out']],
      [1, $cid, 'answer', $root, 1000, 500]);
check('…ומחיר', (float) $row['cost_usd'], 0.014);
aiComplete(['provider' => 'anthropic', 'key' => 'k', 'model' => 'claude-future-9', 'user_id' => 1], 's', 'p', 100,
           fake(['input_tokens' => 1000, 'output_tokens' => 0]));
check('מודל לא מוכר — נרשם במחיר הגבוה', (float) db()->query('SELECT cost_usd FROM usage ORDER BY id DESC LIMIT 1')->fetchColumn(), 0.01);
check('…ואירוע ביומן', (int) db()->query("SELECT COUNT(*) FROM events WHERE action = 'usage.unknown_model'")->fetchColumn(), 1);
check('חיבור בלי בעלים (המפתח הכללי) — לא נרשם לאף אחד', (function () {
    $n = (int) db()->query('SELECT COUNT(*) FROM usage')->fetchColumn();
    aiComplete(['provider' => 'anthropic', 'key' => 'k', 'model' => 'claude-opus-5-5'], 's', 'p', 100, fake(['input_tokens' => 1, 'output_tokens' => 1]));
    return (int) db()->query('SELECT COUNT(*) FROM usage')->fetchColumn() - $n;
})(), 0);

echo "\n— מכסה —\n";
check('ברירת מחדל 0 — ☁️ כבוי', quotaState(2)['state'], 'off');
throws('בלי מכסה — run-task / קריאה נאכפת → 402', fn () => requireQuota(2, 'develop'), 402);
throws('מכסה — מנהל בלבד', fn () => setQuota($dana, 2, 5), 403);
check('מכסה $10: שימוש קטן — ok', quotaState(1)['state'], 'ok');

// ערוץ — כדי לראות את ההודעה של 80%
$regToken = bin2hex(random_bytes(16));
db()->prepare('UPDATE projects SET agent_token = ? WHERE id = ?')->execute([encryptSecret($regToken), $root]);
$_SERVER['HTTP_X_WORKER_TOKEN'] = $regToken;
$ch = getChannel(registerChannel(workerScope(), $root, 'כותב הקוד', 'קוד', 'session_USAGE1', '')['channel_id']);
$sys = fn () => (int) db()->query("SELECT COUNT(*) FROM messages WHERE channel_id = {$ch['id']} AND author_role = 'system'")->fetchColumn();

recordUsage(['user_id' => 1, 'kind' => 'develop'], 'anthropic', 'claude-opus-5-5', 0, 400_000);   // $8
check('80% — warn', quotaState(1)['state'], 'warn');
check('…והודעת system בערוץ של המשתמש', $sys(), 1);
recordUsage(['user_id' => 1, 'kind' => 'develop'], 'anthropic', 'claude-opus-5-5', 0, 1_000);
check('…פעם אחת בחודש (קריאה נוספת — בלי הודעה חדשה)', $sys(), 1);
recordUsage(['user_id' => 1, 'kind' => 'develop'], 'anthropic', 'claude-opus-5-5', 0, 100_000);  // $2 → מעל 10
check('100% — over', quotaState(1)['state'], 'over');
check('…והודעה על הסוף', $sys(), 2);
throws('100% — run-task → 402', fn () => requireQuota(1, 'develop'), 402);
check('100% — ☁️ לא זמין', platformAvailable(1), false);
db()->prepare("UPDATE projects SET route = 'platform' WHERE id = ?")->execute([$root]);
check('100% — ☁️ נחשב chat (מחושב), והבחירה נשמרת', [projectRoute($root), projectRoutes(true)[$root]], ['chat', 'platform']);

echo "\n— השרשרת לא עוקפת את המכסה —\n";
addConnection($boss, 'openai', 'sk-oaioaioaioaioaioaioai', 'גיבוי', 'gpt-5');
$calls = [];
$chain = userConn($boss);
check('יש שרשרת של שניים', count($chain['chain']), 2);
throws('מכסה שנגמרה → 402 לפני כל ניסיון', fn () => aiComplete($chain, 's', 'p', 100, fake(['input_tokens' => 1], [], $calls)), 402);
check('…ואף חיבור לא נוסה', count($calls), 0);
setQuota($boss, 1, 1000);
$calls = [];
$keys = array_column($chain['chain'], 'key');
aiComplete(userConn($boss), 's', 'p', 100, fake(['input_tokens' => 10, 'output_tokens' => 10], [$keys[0] => 401], $calls));
check('עם מכסה — 401 עובר לבא בתור, והשימוש נזקף לחיבור שענה', [count($calls), (int) db()->query('SELECT connection_id FROM usage ORDER BY id DESC LIMIT 1')->fetchColumn()],
      [2, (int) $chain['chain'][1]['connection_id']]);
db()->exec("UPDATE connections SET status = 'ok', failing_until = 0");

echo "\n— monitor: עד 20% —\n";
setQuota($boss, 1, 10);
db()->exec('DELETE FROM usage');
recordUsage(['user_id' => 1, 'kind' => 'monitor'], 'anthropic', 'claude-opus-5-5', 0, 100_000);   // $2 = 20%
throws('monitor ב-20% — נעצר', fn () => requireQuota(1, 'monitor'), 402);
requireQuota(1, 'develop');
check('…אבל develop ממשיך', quotaState(1)['state'], 'ok');

echo "\n— איפוס חודשי —\n";
db()->prepare('UPDATE usage SET at = ?')->execute([monthStart() - 3600]);   // הכול — בחודש שעבר
check('שימוש מהחודש שעבר לא נספר', quotaState(1)['used'], 0.0);
check('…ולכן ☁️ חוזר לבד', platformAvailable(1), true);
db()->exec("DELETE FROM usage_alerts WHERE month <> '" . monthKey() . "'");

echo "\n— הגרף —\n";
recordUsage(['user_id' => 1, 'kind' => 'develop', 'project_id' => $root], 'anthropic', 'claude-opus-5-5', 0, 50_000);  // $1
recordUsage(['user_id' => 1, 'kind' => 'monitor'], 'anthropic', 'claude-opus-5-5', 0, 25_000);                        // $0.5
$s = usageSummary(1);
$today = $s['daily'][(int) gmdate('j') - 1];
check('עמודה יומית — מוערמת לפי סוג', [$today['develop'], $today['answer'], $today['monitor']], [1.0, 0.0, 0.5]);
check('כמה ימים בחודש', count($s['daily']), (int) gmdate('t'));
check('שורה לפרויקט, והשאר "בלי פרויקט"', array_map(fn ($p) => [$p['name'], $p['cost']], $s['projects']), [['mini_projects', 1.0], ['בלי פרויקט', 0.5]]);
check('מתוך / נשאר / אחוז', [$s['used'], $s['left'], $s['pct']], [1.5, 8.5, 15.0]);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
