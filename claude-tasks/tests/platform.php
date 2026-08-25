<?php
/**
 * בדיקות שכבת הפלטפורמה: הצפנת סודות, ספקי בינה מלאכותית, הרשאות
 * פרויקט, והלקוח של גיטהאב.
 *
 *   php claude-tasks/tests/platform.php
 *
 * כל הפניות החוצה מוחלפות בתעבורה מזויפת — אין צורך במפתחות, אין עלות,
 * ואין תלות ברשת.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctp-' . getmypid();
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');

require_once __DIR__ . '/../lib/projects.php';
require_once __DIR__ . '/../lib/github.php';

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
    catch (Throwable $e) {
        check($name, $needle === '' || str_contains($e->getMessage(), $needle), true);
    }
}

$pdo = db();
function mkUser(string $name, string $role = 'member'): array {
    db()->prepare('INSERT INTO users (username,password_hash,display_name,role,created_at) VALUES (?,?,?,?,?)')
        ->execute([$name, 'x', $name, $role, time()]);
    $id = (int) db()->lastInsertId();
    $st = db()->prepare('SELECT id, username, display_name, role, github_login, github_scopes, default_provider
                           FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

echo "\n— הצפנת סודות —\n";
$secret = 'ghp_averysecrettoken1234';
$blob   = encryptSecret($secret);
check('הלוך ושוב', decryptSecret($blob), $secret);
check('הטקסט אינו גלוי', str_contains($blob, $secret), false);
check('אותו סוד, טקסט מוצפן אחר', $blob === encryptSecret($secret), false);
check('שינוי בתוכן פוסל', decryptSecret(substr($blob, 0, -4) . 'AAAA'), '');
check('קלט זבל אינו מפיל', decryptSecret('לא-מוצפן-בכלל'), '');
check('הרשאות מפתח ההצפנה', substr(sprintf('%o', fileperms(SECRET_KEY_FILE)), -3), '600');

echo "\n— צורת מפתח לפי ספק —\n";
throws('מפתח OpenAI בתיבה של Anthropic', fn() => checkProviderKey('anthropic', 'sk-proj-abcdefghijklmnop'), 'sk-ant-');
throws('מפתח קצר מדי', fn() => checkProviderKey('openai', 'sk-123'), 'קצר');
throws('ספק לא מוכר', fn() => checkProviderKey('nosuch', 'sk-ant-abcdefghijklmnopqrst'), 'לא מוכר');
checkProviderKey('anthropic', 'sk-ant-abcdefghijklmnopqrst');
checkProviderKey('google', 'AIzaabcdefghijklmnopqrst');
check('מפתחות תקינים עוברים', true, true);

echo "\n— בניית הבקשה לכל ספק —\n";
$spy = function (&$seen) {
    return function ($provider, $url, $json, $key) use (&$seen) {
        $seen = ['provider' => $provider, 'url' => $url, 'body' => json_decode($json, true), 'key' => $key];
        return ['status' => 200, 'body' => json_encode(match ($provider) {
            'anthropic' => ['content' => [['type' => 'text', 'text' => 'שלום']]],
            'openai'    => ['choices' => [['message' => ['content' => 'שלום']]]],
            'google'    => ['candidates' => [['content' => ['parts' => [['text' => 'שלום']]]]]],
        }, JSON_UNESCAPED_UNICODE)];
    };
};

$seen = null;
check('Anthropic מחזיר טקסט',
      aiComplete(['provider' => 'anthropic', 'key' => 'k', 'model' => 'm'], 'sys', 'hi', 50, $spy($seen)), 'שלום');
check('Anthropic: כתובת', $seen['url'], 'https://api.anthropic.com/v1/messages');
check('Anthropic: system בשדה נפרד', $seen['body']['system'], 'sys');

$seen = null;
check('OpenAI מחזיר טקסט',
      aiComplete(['provider' => 'openai', 'key' => 'k', 'model' => 'gpt-x'], 'sys', 'hi', 50, $spy($seen)), 'שלום');
check('OpenAI: כתובת', $seen['url'], 'https://api.openai.com/v1/chat/completions');
check('OpenAI: system כהודעה', $seen['body']['messages'][0]['role'], 'system');
check('OpenAI: המודל עובר', $seen['body']['model'], 'gpt-x');

$seen = null;
check('Gemini מחזיר טקסט',
      aiComplete(['provider' => 'google', 'key' => 'k', 'model' => 'gemini-x'], 'sys', 'hi', 50, $spy($seen)), 'שלום');
check('Gemini: המודל בכתובת', str_contains($seen['url'], 'gemini-x:generateContent'), true);
check('Gemini: system_instruction', $seen['body']['system_instruction']['parts'][0]['text'], 'sys');

echo "\n— שגיאות ספק —\n";
$err = fn(int $code, string $msg = 'nope') => fn($p, $u, $j, $k) =>
    ['status' => $code, 'body' => json_encode(['error' => ['message' => $msg]])];
$conn = ['provider' => 'openai', 'key' => 'k', 'model' => 'm'];
throws('401 → המפתח נדחה',  fn() => aiComplete($conn, 's', 'p', 10, $err(401)), 'נדחה');
throws('429 → מכסה',         fn() => aiComplete($conn, 's', 'p', 10, $err(429)), 'מכסה');
throws('404 → מודל לא קיים', fn() => aiComplete($conn, 's', 'p', 10, $err(404)), 'אינו קיים');
throws('תשובה ריקה נתפסת',   fn() => aiComplete($conn, 's', 'p', 10,
        fn($p, $u, $j, $k) => ['status' => 200, 'body' => '{"choices":[{"message":{"content":"  "}}]}']), 'ריקה');
throws('בלי מפתח',           fn() => aiComplete(['provider' => 'openai', 'key' => '', 'model' => 'm'], 's', 'p'), 'לא הוגדר מפתח');
throws('בלי מודל',           fn() => aiComplete(['provider' => 'openai', 'key' => 'k', 'model' => ''], 's', 'p'), 'לא הוגדר מודל');

echo "\n— ספקים של משתמש —\n";
$mal = mkUser('mal', 'admin');
$dev = mkUser('dev');

setUserProvider($mal, 'anthropic', 'sk-ant-aaaaaaaaaaaaaaaaaaaa', '');
$mal = (function ($id) { $st = db()->prepare('SELECT id,username,display_name,role,default_provider FROM users WHERE id=?');
                         $st->execute([$id]); return $st->fetch(); })($mal['id']);
check('הספק הראשון נעשה ברירת מחדל', $mal['default_provider'], 'anthropic');
check('המודל מולא מברירת המחדל של הספק',
      userProviders((int) $mal['id'])['anthropic']['model'], PROVIDERS['anthropic']['default']);
check('רק זנב המפתח נחשף', userProviders((int) $mal['id'])['anthropic']['tail'], 'aaaa');

throws('ספק ללא מודל נדחה', fn() => setUserProvider($mal, 'openai', 'sk-abcdefghijklmnopqrst', ''), 'שם מודל');
setUserProvider($mal, 'openai', 'sk-abcdefghijklmnopqrst', 'gpt-x');
check('שני ספקים מחוברים', count(userProviders((int) $mal['id'])), 2);

check('החיבור לפי ברירת המחדל', userConn($mal)['provider'], 'anthropic');
check('בחירה מפורשת גוברת', userConn($mal, 'openai')['provider'], 'openai');
check('המפתח מפוענח בדרך החוצה', userConn($mal, 'openai')['key'], 'sk-abcdefghijklmnopqrst');
check('המקור מסומן', userConn($mal, 'openai')['source'], 'user');
throws('ספק שאינו מחובר נדחה', fn() => userConn($mal, 'google'), 'אינו מחובר');

check('משתמש בלי ספק — אין חיבור', userConn($dev)['source'], 'none');
configSet(['ai_key' => 'sk-ant-systemsystemsystem']);
check('נופלים לחיבור הכללי', userConn($dev)['source'], 'system');
configSet(['ai_key' => null]);

removeUserProvider($mal, 'anthropic');
$mal = (function ($id) { $st = db()->prepare('SELECT id,username,display_name,role,default_provider FROM users WHERE id=?');
                         $st->execute([$id]); return $st->fetch(); })($mal['id']);
check('ניתוק מעביר את ברירת המחדל', $mal['default_provider'], 'openai');

echo "\n— הרשאות פרויקט —\n";
$pid = createProject($mal, 'האתר', 'malkiel01', 'mini_projects');
check('היוצר נעשה מנהל הפרויקט', projectLevel($pid, $mal), 'admin');
check('מי שאינו חבר — אין לו דרגה', projectLevel($pid, $dev), null);
throws('קריאה נחסמת ללא חברות', fn() => requireProject($pid, $dev, 'read'), 'הרשאה');

setMember($mal, $pid, (int) $dev['id'], 'read');
check('חבר בדרגת קריאה', projectLevel($pid, $dev), 'read');
check('קריאה מותרת', requireProject($pid, $dev, 'read')['my_level'], 'read');
throws('כתיבה עדיין חסומה', fn() => requireProject($pid, $dev, 'write'), 'הרשאה');

setMember($mal, $pid, (int) $dev['id'], 'write');
check('שדרוג לדרגת כתיבה', requireProject($pid, $dev, 'write')['my_level'], 'write');
throws('ניהול עדיין חסום', fn() => requireProject($pid, $dev, 'admin'), 'הרשאה');

$root = mkUser('root', 'admin');
check('מנהל-על מקבל admin בכל פרויקט', projectLevel($pid, $root), 'admin');
check('גם בלי להיות חבר', count(array_filter(projectMembers($pid), fn($m) => $m['username'] === 'root')), 0);

throws('אי אפשר להסיר מנהל אחרון', fn() => removeMember($mal, $pid, (int) $mal['id']), 'המנהל האחרון');
removeMember($mal, $pid, (int) $dev['id']);
check('הסרת חבר רגיל עובדת', projectLevel($pid, $dev), null);

check('רואים רק פרויקטים שחברים בהם', count(listProjects($dev)), 0);
check('היוצר רואה את שלו', count(listProjects($mal)), 1);
check('מנהל-על רואה הכול', count(listProjects($root)), 1);

echo "\n— יומן הפעולות —\n";
$events = db()->query('SELECT action, ok FROM events ORDER BY id')->fetchAll();
$actions = array_column($events, 'action');
check('חיבור ספק נרשם', in_array('provider.connect', $actions, true), true);
check('יצירת פרויקט נרשמה', in_array('project.create', $actions, true), true);
check('סירוב הרשאה נרשם', in_array('deny', $actions, true), true);
check('הסירוב מסומן ככישלון',
      (int) db()->query("SELECT ok FROM events WHERE action='deny' LIMIT 1")->fetch()['ok'], 0);

echo "\n— לקוח גיטהאב —\n";
$gh = function (int $status, $data, array $headers = []) {
    return fn($method, $url, $json, $token) => ['status' => $status, 'data' => $data,
        'headers' => $headers, 'raw' => json_encode($data), 'sent' => $json];
};

$who = ghWhoAmI('t', $gh(200, ['login' => 'malkiel01', 'name' => 'מלכיאל'],
                          ['x-oauth-scopes' => 'repo, workflow']));
check('שם המשתמש', $who['login'], 'malkiel01');
check('ההיקפים מהכותרת', $who['scopes'], ['repo', 'workflow']);
check('טוקן עדין בלי scopes', ghWhoAmI('t', $gh(200, ['login' => 'x']))['scopes'], []);

throws('401 → הטוקן נדחה', fn() => ghWhoAmI('t', $gh(401, ['message' => 'Bad credentials'])), 'נדחה');
throws('מכסה שנגמרה',      fn() => ghWhoAmI('t', $gh(403, ['message' => 'API rate limit exceeded'])), 'מכסת');
throws('403 אחר = הרשאות', fn() => ghRepos('t', 1, 30, $gh(403, ['message' => 'Forbidden'])), 'scopes');
throws('404 מוסבר',        fn() => ghRepo('t', 'o', 'r', $gh(404, ['message' => 'Not Found'])), 'לא נמצא');

$repos = ghRepos('t', 1, 30, $gh(200, [[
    'full_name' => 'malkiel01/mini_projects', 'name' => 'mini_projects',
    'owner' => ['login' => 'malkiel01'], 'private' => false, 'default_branch' => 'main',
    'permissions' => ['push' => true, 'admin' => false],
]]));
check('ריפו ממופה', $repos[0]['full_name'], 'malkiel01/mini_projects');
check('הרשאת דחיפה נשמרת', $repos[0]['can_push'], true);
check('ברירת מחדל לאדמין', $repos[0]['can_admin'], false);

$file = ghContents('t', 'o', 'r', 'README.md', 'main',
                   $gh(200, ['type' => 'file', 'path' => 'README.md', 'sha' => 'abc',
                             'size' => 5, 'content' => base64_encode('שלום')]));
check('קובץ מפוענח מ-base64', $file['content'], 'שלום');
check('סוג: קובץ', $file['kind'], 'file');

$dir = ghContents('t', 'o', 'r', '', 'main', $gh(200, [
    ['name' => 'b.txt', 'path' => 'b.txt', 'type' => 'file'],
    ['name' => 'src',   'path' => 'src',   'type' => 'dir'],
]));
check('סוג: תיקייה', $dir['kind'], 'dir');
check('תיקיות לפני קבצים', $dir['items'][0]['name'], 'src');

$sent = null;
$capture = function ($method, $url, $json, $token) use (&$sent) {
    $sent = ['method' => $method, 'url' => $url, 'body' => json_decode((string) $json, true)];
    return ['status' => 200, 'data' => ['content' => ['sha' => 'new'], 'commit' => ['sha' => 'c1']],
            'headers' => [], 'raw' => ''];
};
ghPutFile('t', 'o', 'r', 'dir/file.txt', 'תוכן', 'הודעה', 'main', 'oldsha', $capture);
check('כתיבה היא PUT', $sent['method'], 'PUT');
check('התוכן מקודד', base64_decode($sent['body']['content']), 'תוכן');
check('ה-sha הקיים נשלח', $sent['body']['sha'], 'oldsha');
check('הענף נשלח', $sent['body']['branch'], 'main');

$dispatched = null;
ghDispatchWorkflow('t', 'o', 'r', 'agent.yml', 'main', ['task_id' => '7'],
    function ($m, $u, $j, $tok) use (&$dispatched) {
        $dispatched = ['url' => $u, 'body' => json_decode((string) $j, true)];
        return ['status' => 204, 'data' => [], 'headers' => [], 'raw' => ''];
    });
check('הופעל ה-workflow הנכון', str_contains($dispatched['url'], 'workflows/agent.yml/dispatches'), true);
check('הקלט הועבר', $dispatched['body']['inputs']['task_id'], '7');
throws('כישלון הפעלה מדווח',
       fn() => ghDispatchWorkflow('t', 'o', 'r', 'agent.yml', 'main', [], $gh(404, ['message' => 'x'])), 'לא נמצא');

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);
exit($fail === 0 ? 0 : 1);
