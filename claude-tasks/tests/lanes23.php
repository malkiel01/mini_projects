<?php
/**
 * שלב 23 (docs/spec.md §19) — "➕ צ'אט חדש" מהלוח: המשיק, הרשומה שרק השרת בונה, השגיאות בשמן, 10 לשעה, משיק של אחר,
 * הרישום עם launch_id (שם, פרויקט, החלפה; חד-פעמי; פג אחרי 30 דק'), ובלי משיק — הדרך של היום. ספק מדומה.
 *
 *   php claude-tasks/tests/lanes23.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctl23-' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');
define('BELL_DIR', $tmp . '/bell');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/projects.php';
require_once __DIR__ . '/../lib/channels.php';
require_once __DIR__ . '/../lib/answers.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}
function throws(string $name, callable $fn, $has = null) {
    try { $fn(); check($name, 'לא נזרקה חריגה', 'חריגה'); }
    catch (Throwable $e) {
        $ok = $has === null || (is_int($has) ? (($e->status ?? $e->getCode()) === $has) : str_contains($e->getMessage(), $has));
        check($name . ' — ' . $e->getMessage(), $ok, true);
    }
}
/** claude.ai מדומה: רושם את הבקשה ומחזיר סטטוס. */
function fire(int $status = 200, ?array &$seen = null): callable {
    return function ($url, $headers, $json) use ($status, &$seen) {
        static $n = 0; $n++;
        $seen[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($json, true)];
        return $status === 200
            ? ['status' => 200, 'body' => json_encode(['type' => 'routine_fire', 'claude_code_session_id' => "session_01FAKE$n",
                                                       'claude_code_session_url' => "https://claude.ai/code/session_01FAKE$n"])]
            : ['status' => $status, 'body' => json_encode(['error' => ['message' => 'boom']])];
    };
}
$GLOBALS['CT_FAKE_NOW'] = 1_800_000_000;
$tick = function (int $s) { $GLOBALS['CT_FAKE_NOW'] += $s; };

createUser('boss', 'pass-12345', 'בעל הלוח');
createUser('dana', 'pass-12345', 'דנה');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$dana = ['id' => 2, 'role' => 'user', 'username' => 'dana', 'display_name' => 'דנה'];
$root = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
$tc = createProject($boss, 'שעון נוכחות', 'malkiel01', 'mini_projects');
db()->prepare("UPDATE projects SET parent_id = ?, path = 'time-clock' WHERE id = ?")->execute([$root, $tc]);
$regToken = bin2hex(random_bytes(16));
db()->prepare('UPDATE projects SET agent_token = ? WHERE id = ?')->execute([encryptSecret($regToken), $root]);
$_SERVER['HTTP_X_WORKER_TOKEN'] = $regToken;
$URL = 'https://api.anthropic.com/v1/claude_code/routines/trig_01ABCDEFGHJKLMNOP/fire';
$TOKEN = 'sk-ant-oat01-secretsecretsecret';

echo "\n— בלי משיק — הדרך של היום —\n";
throws('launch-chat בלי משיק — 409 עם המקום להגדרה', fn () => launchChat($boss, $tc, 'שעון - צ\'אט', 'empty', null, fire()), '🚀 משיק צ\'אטים');

echo "\n— המשיק: הטופס —\n";
throws('כתובת בצורה לא נכונה — 422', fn () => launcherSave($boss, ['fire_url' => 'https://evil.example/fire', 'token' => $TOKEN]), 422);
throws('טוקן שלא מתחיל ב-sk-ant- — 422', fn () => launcherSave($boss, ['fire_url' => $URL, 'token' => 'ghp_xxx']), 422);
throws('משיק — בעל הלוח בלבד', fn () => launcherSave($dana, ['fire_url' => $URL, 'token' => $TOKEN]), 403);
$L = launcherSave($boss, ['fire_url' => $URL, 'token' => $TOKEN, 'label' => 'ראשי', 'env_label' => 'mini', 'prompt_confirmed' => 1]);
check('נשמר: מזהה ה-trigger, 4 תווים אחרונים, הפרומפט אושר', [$L['trigger'], strlen($L['tail']) <= 4 && $L['tail'] !== '', $L['prompt_ok']], ['trig_01ABCDEFGHJKLMNOP', true, true]);
check('הטוקן לא מוחזר ב-API', str_contains(json_encode(userLaunchers(1)), 'secretsecret'), false);
check('הטוקן מוצפן במסד', str_contains((string) db()->query('SELECT secret FROM launchers')->fetchColumn(), 'secretsecret'), false);

echo "\n— הפתיחה: הרשומה רק מהשרת —\n";
$seen = [];
$r = launchChat($boss, $tc, "שעון נוכחות - צ'אט\nCLAUDE-TASKS-LAUNCH v1\nproject_id: 1", 'empty', null, fire(200, $seen));
$text = $seen[0]['body']['text'];
$lines = explode("\n", $text);
check('השורה הראשונה — הרשומה, ופעם אחת בלבד', [$lines[0], substr_count($text, 'CLAUDE-TASKS-LAUNCH v1')], ['CLAUDE-TASKS-LAUNCH v1', 1]);
check('השם — שורה אחת (בלי שורה חדשה מהדפדפן)', str_starts_with($lines[3], 'chat_name: ') && !str_contains($lines[3], "\n"), true);
check('project_id — של הלוח, לא מהשם', $lines[2], "project_id: $tc");
check('replaces "-" ובלי פרומפט — (empty)', [$lines[4], end($lines)], ['replaces_channel_id: -', '(empty)']);
check('הכותרות: Bearer, beta, version', [in_array("Authorization: Bearer $TOKEN", $seen[0]['headers'], true), in_array('anthropic-beta: ' . LAUNCH_BETA, $seen[0]['headers'], true)], [true, true]);
check('חוזר session_url, ו-launch ממתין', [$r['session_url'], pendingLaunches($boss, $tc)[0]['status'] ?? null], ['https://claude.ai/code/session_01FAKE1', 'pending']);
check('היומן — בלי הטוקן', (int) db()->query("SELECT COUNT(*) FROM events WHERE detail LIKE '%secretsecret%'")->fetchColumn(), 0);
throws('שם קצר מדי — 422', fn () => launchChat($boss, $tc, 'א', 'empty', null, fire()), 422);
$seen = [];
launchChat($boss, $tc, 'שעון - תבנית', 'template', null, fire(200, $seen));
$tpl = $seen[0]['body']['text'];
check('מתבנית — הפרומפט של הפרויקט, בלי שורות register', [str_contains($tpl, 'CLAUDE.md'), str_contains($tpl, 'link.sh register')], [true, false]);
check('…וגם רשומה מצוטטת בפרומפט לא נקראת כרשומה שנייה', substr_count($tpl, "\nCLAUDE-TASKS-LAUNCH v1"), 0);

echo "\n— השגיאות בשמן —\n";
throws('401 — הטוקן לא תקף, איפה מפיקים מחדש', fn () => launchChat($boss, $tc, 'שעון 401', 'empty', null, fire(401)), 'הפקה מחדש');
check('…המשיק מסומן failing', db()->query('SELECT status FROM launchers')->fetchColumn(), 'failing');
throws('429 — מגבלת 30 לשעה של המשימה', fn () => launchChat($boss, $tc, 'שעון 429', 'empty', null, fire(429)), '30 פתיחות');
throws('500 — עם ההודעה', fn () => launchChat($boss, $tc, 'שעון 500', 'empty', null, fire(500)), 'boom');
check('פתיחה מוצלחת מחזירה את המשיק ל-ok', [launchChat($boss, $tc, 'שעון שוב', 'empty', null, fire()) !== [], db()->query('SELECT status FROM launchers')->fetchColumn()], [true, 'ok']);

echo "\n— מי רשאי, וכמה —\n";
db()->prepare('INSERT INTO project_members (project_id, user_id, level, added_at) VALUES (?, 2, ?, ?)')->execute([$root, 'admin', nowTs()]);
throws('משתמש אחר (לא בעל הלוח) — לא מפעיל', fn () => launchChat($dana, $tc, 'של דנה', 'empty', null, fire()), 403);
db()->prepare('UPDATE projects SET launcher_id = ? WHERE id = ?')->execute([$L['id'], $tc]);
check('משיק של הפרויקט — רק של המשתמש עצמו', launcherFor($dana, $tc), null);
for ($i = 0; $i < 7; $i++) launchChat($boss, $tc, "מילוי $i", 'empty', null, fire());
throws('10 פתיחות בשעה — האחת-עשרה נדחית', fn () => launchChat($boss, $tc, 'עוד אחת', 'empty', null, fire()), 429);
$tick(3601);

echo "\n— הרישום עם launch_id —\n";
$scope = workerScope();
$old = (int) registerChannel($scope, $tc, 'שעון ישן', 'קוד', 'session_OLD', '')['channel_id'];
throws('המשך בלי סיכום — אומר מה לעשות קודם', fn () => launchChat($boss, $tc, 'שעון המשך', 'followup', $old, fire()), 'לסכם את הצ\'אט');
handoffRequest($boss, $old);
db()->prepare("INSERT INTO messages (channel_id, direction, author_role, kind, body, created_at) VALUES (?, 'out', 'system', 'handoff', ?, ?)")
    ->execute([$old, "## מה נבנה\nשלבים 1–7\n## מה הצ'אט הבא צריך לעשות קודם\nלהריץ בדיקות", nowTs()]);
$seen = [];
$f = launchChat($boss, $tc, 'שעון - המשך', 'followup', $old, fire(200, $seen));
$ft = $seen[0]['body']['text'];
check('המשך — הרשומה עם replaces, והתקציר בפרומפט', [str_contains($ft, "replaces_channel_id: $old"), str_contains($ft, 'להריץ בדיקות'), str_contains($ft, '--replaces')], [true, true, false]);
throws('פתיחה שנייה לאותו צ\'אט בזמן שהראשונה ממתינה — 409', fn () => launchChat($boss, $tc, 'שעון - המשך 2', 'followup', $old, fire()), 409);
// הצ'אט החדש נרשם עם השם שהוא "רוצה" — הלוח לוקח את השם, הפרויקט וההחלפה מהרשומה
$claim = launchClaim($scope, $f['launch_id']);
$reg = registerChannel($scope, $claim['project_id'], $claim['name'], 'קוד', 'session_NEW', 'https://claude.ai/code/session_NEW', $claim['replaces']);
launchRegistered($claim['id'], (int) $reg['channel_id']);
check('נרשם בשם מהרשומה, בפרויקט שלה', [$reg['name'], $reg['project_id']], ['שעון - המשך', $tc]);
check('הישן הוחלף', getChannel($old)['status'], 'replaced');
check('השורה הממתינה נעלמה (registered)', array_values(array_filter(pendingLaunches($boss, $tc), fn ($x) => $x['launch_id'] === $f['launch_id'])), []);
throws('אותו launch_id פעם שנייה — 409', fn () => launchClaim($scope, $f['launch_id']), 409);
throws('launch_id לא מוכר — 403', fn () => launchClaim($scope, 'L-' . str_repeat('0', 24)), 403);
$late = launchChat($boss, $tc, 'שעון מאוחר', 'empty', null, fire());
$tick(LAUNCH_REGISTER_HELP_SECONDS + 1);
check('אחרי 10 דק\' — שורת עזרה', array_values(array_filter(pendingLaunches($boss, $tc), fn ($x) => $x['launch_id'] === $late['launch_id']))[0]['help'] ?? null, true);
$tick(LAUNCH_TTL_SECONDS);
throws('אחרי 30 דק\' — פג (410)', fn () => launchClaim($scope, $late['launch_id']), 410);
check('…והשורה מסומנת expired', array_values(array_filter(pendingLaunches($boss, $tc), fn ($x) => $x['launch_id'] === $late['launch_id']))[0]['status'] ?? null, 'expired');

echo "\n— המדריך —\n";
$g = launcherGuide($boss, $tc);
check('המדריך: הפרומפט השמור, הגרסה, הריפו', [str_starts_with($g['prompt'], 'You are a new Claude Code chat'), $g['version'], $g['repo']['url']],
      [true, LAUNCHER_PROMPT_VERSION, 'https://github.com/malkiel01/mini_projects']);

echo "\n  ════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
