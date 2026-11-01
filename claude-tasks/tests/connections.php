<?php
/**
 * שלב 3a (docs/spec.md §10.1) — חיבורים: מיגרציה זהירה של הסודות, שרשרת
 * מעבר בין ספקי בינה, ובחירת חשבון גיטהאב לפי מי שמבצע.
 *
 *   php claude-tasks/tests/connections.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctn-' . getmypid();
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/projects.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}
function throws(string $name, callable $fn, string $needle = '') {
    try { $fn(); check($name, 'לא נזרקה חריגה', 'חריגה'); }
    catch (Throwable $e) { check($name . ' — ' . $e->getMessage(), $needle === '' || str_contains($e->getMessage(), $needle), true); }
}

createUser('boss', 'pass-12345', 'בעל הלוח');
createUser('dana', 'pass-12345', 'דנה');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$dana = ['id' => 2, 'role' => 'member', 'username' => 'dana', 'display_name' => 'דנה'];

echo "\n— מיגרציה: הסודות עוברים כמו שהם —\n";
// מצב "ישן": סודות בעמודות של users ו-user_providers, ★ על openai
$ghOld = encryptSecret('ghp_oldtokenoldtokenold');
$antOld = encryptSecret('sk-ant-oldoldoldoldoldold');
$oaiOld = encryptSecret('sk-oaioaioaioaioaioaioai');
db()->prepare("UPDATE users SET github_token = ?, github_login = 'malkiel01', github_scopes = 'repo,workflow',
                                default_provider = 'openai' WHERE id = 1")->execute([$ghOld]);
db()->prepare('INSERT INTO user_providers (user_id, provider, api_key, model, added_at) VALUES (1,?,?,?,1)')
    ->execute(['anthropic', $antOld, 'claude-opus-5-5']);
db()->prepare('INSERT INTO user_providers (user_id, provider, api_key, model, added_at) VALUES (1,?,?,?,2)')
    ->execute(['openai', $oaiOld, 'gpt-5']);
db()->exec("DELETE FROM meta WHERE k = 'connections_migrated'");
migrateConnections(db());
$rows = db()->query('SELECT * FROM connections WHERE user_id = 1 ORDER BY kind <> \'github\', priority')->fetchAll();
check('שלושה חיבורים', count($rows), 3);
check('הצופן הועתק כמו שהוא — בלי פענוח והצפנה מחדש', [$rows[0]['secret'] === $ghOld, $rows[1]['secret'] === $oaiOld, $rows[2]['secret'] === $antOld], [true, true, true]);
check('כל סוד מתפענח זהה', [decryptSecret($rows[0]['secret']), decryptSecret($rows[1]['secret']), decryptSecret($rows[2]['secret'])],
      ['ghp_oldtokenoldtokenold', 'sk-oaioaioaioaioaioaioai', 'sk-ant-oldoldoldoldoldold']);
check('ה-★ (openai) ראשון בסדר, אנתרופיק שני', [$rows[1]['kind'], (int) $rows[1]['priority'], $rows[2]['kind'], (int) $rows[2]['priority']], ['openai', 1, 'anthropic', 2]);
check('גיטהאב: login והיקפים', [$rows[0]['login'], $rows[0]['scopes']], ['malkiel01', 'repo,workflow']);
check('העמודות הישנות נשארו (לקריאה בלבד)', db()->query('SELECT github_token FROM users WHERE id = 1')->fetch()['github_token'], $ghOld);
migrateConnections(db());
check('מיגרציה חוזרת לא מכפילה', (int) db()->query('SELECT COUNT(*) c FROM connections')->fetch()['c'], 3);

echo "\n— כמה חיבורים, סדר וניתוק —\n";
$ant2 = addConnection($boss, 'anthropic', 'sk-ant-workworkworkworkwork', 'עבודה', 'claude-sonnet-5-5');
$chain = aiChain($boss);
check('חיבור חדש נכנס אחרון', array_column($chain, 'connection_id')[2], $ant2);
check('שני מפתחות לאותו ספק', count(array_filter($chain, fn ($c) => $c['provider'] === 'anthropic')), 2);
moveConnection($boss, $ant2, -1);
check('↑ מעלה בסדר', array_column(aiChain($boss), 'connection_id')[1], $ant2);
moveConnection($boss, $ant2, -1); moveConnection($boss, $ant2, -1);
check('↑ בראש — נשאר בראש', array_column(aiChain($boss), 'connection_id')[0], $ant2);
throws('לגיטהאב אין סדר', fn () => moveConnection($boss, (int) $rows[0]['id'], -1));
throws('חיבור של משתמש אחר — לא נמצא', fn () => removeConnection($dana, $ant2), 'לא נמצא');
check('הסוד לא יוצא — רק זנב', array_key_exists('secret', userConnections(1)[0]), false);

echo "\n— שרשרת: מעבר לחיבור הבא —\n";
$calls = [];
$fake = function (array $map) use (&$calls) {
    return function ($prov, $url, $json, $key) use ($map, &$calls) {
        $calls[] = $key;
        $status = $map[$key] ?? 200;
        return ['status' => $status, 'body' => json_encode($status === 200
            ? ['content' => [['type' => 'text', 'text' => "ok:$key"]], 'choices' => [['message' => ['content' => "ok:$key"]]]]
            : ['error' => ['message' => "err $status"]])];
    };
};
$chain = aiChain($boss);
$k = array_column($chain, 'key');
db()->exec("DELETE FROM tasks"); db()->prepare('INSERT INTO tasks (title, created_by, created_at, updated_at) VALUES (?,1,1,1)')->execute(['מטלה']);
[$text, $used, $fb] = aiCompleteChain($chain, 's', 'p', 100, $fake([$k[0] => 401]), $boss, 1);
check('401 → הבא בתור', [$text, $used['key']], ["ok:{$k[1]}", $k[1]]);
check('נכתבה הערה על המטלה', str_contains(taskNotes(db(), 1)[0]['body'] ?? '', 'עבר ל-'), true);
check('החיבור שנכשל מסומן failing', db()->query("SELECT status FROM connections WHERE id = {$chain[0]['connection_id']}")->fetch()['status'], 'failing');
check('בשרשרת הבאה — מדלגים עליו', array_column(aiChain($boss), 'connection_id')[0] !== $chain[0]['connection_id'], true);
db()->prepare('UPDATE connections SET failing_until = ? WHERE id = ?')->execute([nowTs() - 1, $chain[0]['connection_id']]);
check('אחרי 10 דקות — חוזר לראש', array_column(aiChain($boss), 'connection_id')[0], $chain[0]['connection_id']);
$calls = [];
throws('400 → לא עוברים (הבקשה עצמה שגויה)', fn () => aiCompleteChain($chain, 's', 'p', 100, $fake([$k[0] => 400])), 'שגיאה');
check('…ונוסה רק הראשון', count($calls), 1);
$calls = [];
[$t] = aiCompleteChain($chain, 's', 'p', 100, $fake([$k[0] => 429, $k[1] => 500]));
check('429 ואז 500 → השלישי', $t, "ok:{$k[2]}");
check('חיבור שנבחר במפורש — רק הוא', count(aiChain($boss, $ant2)), 1);
db()->exec("UPDATE connections SET status = 'ok', failing_until = 0");
check('userConn נושא את השרשרת', count(userConn($boss)['chain']), 3);

echo "\n— גיטהאב: איזה חשבון —\n";
$ghWork = addConnection($boss, 'github', 'ghp_workworkworkwork', 'עבודה', '', ['login' => 'acme-corp', 'scopes' => ['repo']]);
check('אותו login — מתעדכן ולא נכפל', addConnection($boss, 'github', 'ghp_workworkworkwork2', '', '', ['login' => 'acme-corp', 'scopes' => ['repo']]), $ghWork);
$mine  = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
$work  = createProject($boss, 'עבודה', 'acme-corp', 'site');
$child = createSubproject($boss, $mine, 'מתכונים', 'recipes-app');
$P = fn ($id) => db()->query("SELECT * FROM projects WHERE id = $id")->fetch();
check('בלי בחירה — לפי בעל הריפו (malkiel01)', githubTokenFor($boss, $P($mine)), 'ghp_oldtokenoldtokenold');
check('בלי בחירה — לפי בעל הריפו (acme-corp)', githubTokenFor($boss, $P($work)), 'ghp_workworkworkwork2');
setProjectGithub($boss, $mine, $ghWork);
check('בחירה בפרויקט גוברת', githubTokenFor($boss, $P($mine)), 'ghp_workworkworkwork2');
check('תת-פרויקט יורש את הבחירה', githubTokenFor($boss, $P($child)), 'ghp_workworkworkwork2');
setProjectGithub($boss, $mine, null);
check('ביטול הבחירה — חזרה לאוטומטי', githubTokenFor($boss, $P($mine)), 'ghp_oldtokenoldtokenold');
throws('בלי חיבור גיטהאב — שגיאה מסבירה', fn () => githubTokenFor($dana, $P($mine)), 'לא חובר חשבון גיטהאב');
throws('אי אפשר לבחור חיבור של מישהו אחר', fn () => setProjectGithub($dana, $mine, $ghWork), 'לא נמצא');
setProjectGithub($boss, $work, $ghWork);
$rel = removeConnection($boss, $ghWork);
check('ניתוק מחזיר את הפרויקטים שבחרו בו', array_column($rel, 'name'), ['עבודה']);
check('…והם חוזרים לאוטומטי', githubTokenFor($boss, $P($work)), 'ghp_oldtokenoldtokenold');

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
