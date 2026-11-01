<?php
/**
 * שלב 1 באפיון (docs/spec.md, 4) — בטיחות ומבנה:
 * מי כתב הערה (author_role), מי רשאי להחזיר מטלה לתור, ענף owner_only,
 * אסימון שמכסה את כל הענף, בדיקת Origin, root_id ושיוך מטלות ישנות.
 *
 *   php claude-tasks/tests/safety.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/cts-' . getmypid();
// תיקייה שנשארה מהרצה קודמת עם אותו מזהה תהליך — מסד ישן, ו"שם המשתמש כבר תפוס"
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/projects.php';
require_once __DIR__ . '/../lib/errors.php';

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
function task(string $title, ?int $project = null, ?int $topic = null, string $status = 'open'): int {
    db()->prepare('INSERT INTO tasks (title, project_id, topic_id, status, created_by, created_at, updated_at)
                   VALUES (?,?,?,?,1,?,?)')->execute([$title, $project, $topic, $status, nowTs(), nowTs()]);
    return (int) db()->lastInsertId();
}
function row(int $id): array { return getTask(db(), $id); }
function release(int $id): void {
    db()->prepare("UPDATE tasks SET status='open', claimed_by='', claim_until=0 WHERE id=?")->execute([$id]);
}

createUser('boss', 'pass-12345', 'בעל הלוח');      // admin — הראשון
createUser('dana', 'pass-12345', 'דנה');           // member
createUser('rami', 'pass-12345', 'רמי');           // member
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$dana = ['id' => 2, 'role' => 'member', 'username' => 'dana', 'display_name' => 'דנה'];
$rami = ['id' => 3, 'role' => 'member', 'username' => 'rami', 'display_name' => 'רמי'];

$root  = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
$app   = createSubproject($boss, $root, 'מתכונים', 'recipes-app');
$suite = createSubproject($boss, $root, 'חבילת היצירה', 'creative-suite');
$raster = createSubproject($boss, $suite, 'רסטר', 'creative-suite/raster');
setMember($boss, $root, 2, 'write');               // דנה כותבת בכל הענף
setMember($boss, $root, 3, 'read');                // רמי רק צופה
db()->prepare('UPDATE projects SET owner_only = 1 WHERE id = ?')->execute([$suite]);

echo "\n— author_role —\n";
check('בעל הלוח = owner', authorRole($boss), 'owner');
check('משתמש אחר = member', authorRole($dana), 'member');
check('בלי משתמש (אסימון) = worker', authorRole(null), 'worker');
$t = task('שאלה ישנה');
db()->prepare('INSERT INTO notes (task_id, author, kind, body, created_at) VALUES (?,?,?,?,?)')
    ->execute([$t, 'מישהו', 'comment', 'הערה מלפני השלב', nowTs()]);
check('הערה ישנה — legacy', taskNotes(db(), $t)[0]['author_role'], 'legacy');
$r = addNote(db(), $t, 'דנה', 'comment', 'חדשה', 'member', 2);
$n = taskNotes(db(), $t)[1];
check('הערה חדשה — התפקיד והמזהה נשמרים', [$n['author_role'], (int) $n['author_user_id']], ['member', 2]);
check('addNote מחזיר את מזהה ההערה', $r['note_id'], (int) $n['id']);
addNote(db(), $t, 'bot', 'comment', 'תפקיד מומצא', 'admin', null);
check('תפקיד לא מוכר — נשמר כ-worker', taskNotes(db(), $t)[2]['author_role'], 'worker');

echo "\n— מי מחזיר מטלה לתור —\n";
$plain = task('חסומה במתכונים', $app, null, 'blocked');
$locked = task('חסומה ברסטר', $raster, null, 'blocked');
$loose = task('חסומה בלי פרויקט', null, null, 'blocked');
check('owner עונה בענף owner_only', noteKind($boss, row($locked), 'comment'), 'answer');
check('member עם write לא עונה בענף owner_only (בירושה מהאב)', noteKind($dana, row($locked), 'comment'), 'comment');
check('member לא יכול לכפות answer בענף owner_only', noteKind($dana, row($locked), 'answer'), 'comment');
check('member עם write עונה בפרויקט רגיל', noteKind($dana, row($plain), 'comment'), 'answer');
check('member בצפייה בלבד — לא עונה', noteKind($rami, row($plain), 'comment'), 'comment');
check('בלי פרויקט — כל מחובר עונה (כמו קודם)', noteKind($rami, row($loose), 'comment'), 'answer');
check('worker לעולם לא עונה', noteKind(null, row($plain), 'answer'), 'comment');
check('worker — שאלה נשארת שאלה', noteKind(null, row($plain), 'question'), 'question');
check('הערה על מטלה שאינה חסומה — נשארת הערה', noteKind($dana, row(task('פתוחה', $app)), 'comment'), 'comment');

$r = addNote(db(), $locked, 'דנה', noteKind($dana, row($locked), 'comment'), 'אני חושבת ש…', 'member', 2);
check('member בענף owner_only — המטלה נשארת חסומה', [$r['reopened'], row($locked)['status']], [false, 'blocked']);
$r = addNote(db(), $locked, 'בעל הלוח', noteKind($boss, row($locked), 'comment'), 'כן, תמשיך', 'owner', 1);
check('owner — המטלה חוזרת לתור', [$r['reopened'], row($locked)['status']], [true, 'answered']);

echo "\n— הרשאה למטלה אחת (task, add-note: read · update-task: write) —\n";
$outsider = (function () { createUser('zvi', 'pass-12345', 'צבי');
    return ['id' => 4, 'role' => 'member', 'username' => 'zvi', 'display_name' => 'צבי']; })();
$denied = function (array $u, int $task, string $lvl): bool {
    try { requireTaskAccess($u, row($task), $lvl); return false; } catch (AppError $e) { return $e->status === 403; }
};
check('לא חבר — לא קורא מטלה של פרויקט', $denied($outsider, $plain, 'read'), true);
check('צופה — קורא', $denied($rami, $plain, 'read'), false);
check('צופה — לא עורך (update-task)', $denied($rami, $plain, 'write'), true);
check('כותבת — עורכת', $denied($dana, $plain, 'write'), false);
check('בעל הלוח — עורך הכול', $denied($boss, $locked, 'write'), false);
check('מטלה בלי פרויקט — כל מחובר, כמו קודם', $denied($outsider, $loose, 'write'), false);

echo "\n— subtree —\n";
$all = taskScopeIds($root); sort($all);
check('ברירת מחדל — כל הענף', $all, [$root, $app, $suite, $raster]);
check('subtree=0 — רק הפרויקט', taskScopeIds($root, 0), [$root]);
check('subtree="0" (מחרוזת) — רק הפרויקט', taskScopeIds($root, '0'), [$root]);
check('subtree=1 — כל הענף', count(taskScopeIds($root, 1)), 4);

echo "\n— owner_only בירושה —\n";
check('הפרויקט שסומן', projectOwnerOnly($suite), true);
check('תת-פרויקט שלו — בירושה', projectOwnerOnly($raster), true);
check('האב לא מושפע', projectOwnerOnly($root), false);
check('אח לא מושפע', projectOwnerOnly($app), false);
$ids = ownerOnlyProjectIds(); sort($ids);
check('רשימת הדילוג — כל הענף', $ids, [$suite, $raster]);
db()->prepare('UPDATE projects SET owner_only = 0 WHERE id = ?')->execute([$raster]);
check('תת-פרויקט לא "מבטל" סימון של אב', projectOwnerOnly($raster), true);
$byId = array_column(listProjects($boss), null, 'id');
check('projects מחזיר את הסימון האפקטיבי', [$byId[$raster]['owner_only'], $byId[$raster]['owner_only_effective']], [0, true]);

echo "\n— claim —\n";
db()->exec("UPDATE tasks SET status = 'done'");
$inSuite = task('מטלה של החבילה', $raster);
$inApp   = task('מטלה של מתכונים', $app);
$got = claimNextTask(db(), 'general', null, null, ownerOnlyProjectIds());
check('האסימון הכללי מדלג על owner_only', $got['id'] ?? null, $inApp);
check('…ואחריו אין מה למשוך', claimNextTask(db(), 'general', null, null, ownerOnlyProjectIds()), null);
release($inApp);
$topic = (function () use ($raster) {
    db()->prepare('INSERT INTO topics (name, created_at, project_id) VALUES (?,?,?)')->execute(['רסטר · עיצוב', nowTs(), $raster]);
    return (int) db()->lastInsertId();
})();
db()->prepare('UPDATE tasks SET topic_id = ? WHERE id = ?')->execute([$topic, $inSuite]);
check('topic_id מפורש — כן מושך מ-owner_only', claimNextTask(db(), 'general', $topic, null, [])['id'] ?? null, $inSuite);
release($inSuite);

echo "\n— אסימון של פרויקט = כל הענף —\n";
$sub = projectSubtree($root);
$a = claimNextTask(db(), 'root-token', null, $sub)['id'] ?? null;
$b = claimNextTask(db(), 'root-token', null, $sub)['id'] ?? null;
$both = [$a, $b]; sort($both);
$want = [$inSuite, $inApp]; sort($want);
check('האסימון של האב רואה את תתי-הפרויקטים (גם owner_only — ניתן לו במפורש)', $both, $want);
release($inSuite); release($inApp);
check('אסימון של עלה — לא רואה את האח', claimNextTask(db(), 'leaf', null, projectSubtree($app))['id'] ?? null, $inApp);
release($inApp);
check('תחום ריק — כלום', claimNextTask(db(), 'x', null, []), null);
db()->exec("UPDATE tasks SET status = 'done'");
task('בלי פרויקט');
check('מטלה בלי פרויקט — לא בתחום של אסימון פרויקט', claimNextTask(db(), 'root-token', null, $sub), null);

echo "\n— Origin —\n";
$srv = fn (array $h) => $h + ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'mbe-plus.com'];
check('אותו מקור', sameOriginRequest($srv(['HTTP_ORIGIN' => 'https://mbe-plus.com'])), true);
check('מקור זר — נחסם', sameOriginRequest($srv(['HTTP_ORIGIN' => 'https://evil.example'])), false);
check('תת-דומיין — מקור אחר', sameOriginRequest($srv(['HTTP_ORIGIN' => 'https://x.mbe-plus.com'])), false);
check('דומיין שמתחיל באותו שם — נחסם', sameOriginRequest($srv(['HTTP_ORIGIN' => 'https://mbe-plus.com.evil.io'])), false);
check('Origin: null — נחסם', sameOriginRequest($srv(['HTTP_ORIGIN' => 'null'])), false);
check('בלי Origin — Referer מאותו מקור', sameOriginRequest($srv(['HTTP_REFERER' => 'https://mbe-plus.com/mini_projects/creative-suite/dashboard/'])), true);
check('בלי Origin — Referer זר', sameOriginRequest($srv(['HTTP_REFERER' => 'https://evil.example/mbe-plus.com'])), false);
check('בלי Origin ובלי Referer — נחסם', sameOriginRequest($srv([])), false);
check('GET — לא נבדק', sameOriginRequest(['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'mbe-plus.com']), true);
check('פורט ברירת מחדל מפורש', sameOriginRequest($srv(['HTTP_HOST' => 'mbe-plus.com:443', 'HTTP_ORIGIN' => 'https://mbe-plus.com'])), true);
check('פורט אחר — מקור אחר', sameOriginRequest($srv(['HTTP_ORIGIN' => 'https://mbe-plus.com:8443'])), false);
check('סכמה לא http — נחסם', sameOriginRequest($srv(['HTTP_ORIGIN' => 'file://mbe-plus.com'])), false);

echo "\n— root_id —\n";
$ids = array_column(listProjects($boss, $suite), 'id'); sort($ids);
check('root_id — רק הענף', $ids, [$suite, $raster]);
check('root_id — גם לחבר, לפי מה שהוא רואה', count(listProjects($dana, $suite)), 2);
check('root_id של פרויקט שלא קיים — כלום', listProjects($boss, 9999), []);

echo "\n— שיוך מטלות ישנות (U1) —\n";
db()->exec('DELETE FROM notes'); db()->exec('DELETE FROM tasks');
db()->prepare('INSERT INTO topics (name, created_at) VALUES (?,?)')->execute(['שיפוצים', nowTs()]);
$general = (int) db()->lastInsertId();
$o1 = task('לתקן ברז', null, $general);
$o2 = task('לצבוע', null, $general);
$o3 = task('בלי נושא');
$elsewhere = task('כבר במתכונים', $app, $general);     // אותו נושא, אבל כבר בפרויקט
$stage = task('בשלב של רסטר', null, $topic);           // נושא של פרויקט אחר — לא מוצע להעברה
$o = orphanTasks();
check('ספירת המטלות בלי פרויקט', $o['count'], 4);
check('קבוצות: הנושא הכללי, ו"ללא נושא" בסוף',
      array_map(fn ($g) => [$g['topic_id'], count($g['tasks'])], $o['groups']), [[$general, 2], [null, 2]]);
throws('member לא משייך', fn () => assignToProject($dana, $root, [$general], []));
$r = assignToProject($boss, $root, [$general], [$o3]);
check('נושא + מטלה בודדת', $r, ['topics' => 1, 'tasks' => 3]);
check('הנושא נעשה שלב של הפרויקט', (int) db()->query("SELECT project_id FROM topics WHERE id = $general")->fetch()['project_id'], $root);
check('המטלות עברו', [row($o1)['project_id'], row($o2)['project_id'], row($o3)['project_id']], [$root, $root, $root]);
check('מטלה של פרויקט אחר לא נגררה דרך הנושא', row($elsewhere)['project_id'], $app);
$r = assignToProject($boss, $root, [$topic], []);
check('נושא של פרויקט אחר — לא זז', $r, ['topics' => 0, 'tasks' => 0]);
check('נשארה רק המטלה שבשלב של רסטר', orphanTasks()['count'], 1);

echo "\n— העברת פרויקט (זמני) —\n";
$loose1 = createProject($boss, 'חבילת היצירה · וקטורי', 'malkiel01', 'mini_projects');
$t1 = task('מטלה של וקטורי', $loose1);
throws('member לא מעביר', fn () => moveProject($dana, $loose1, $suite));
moveProject($boss, $loose1, $suite);
check('הפרויקט עבר לתוך החבילה', (int) db()->query("SELECT parent_id FROM projects WHERE id = $loose1")->fetch()['parent_id'], $suite);
check('המטלה נשארה עליו', row($t1)['project_id'], $loose1);
check('ועכשיו בענף של mini_projects', in_array($loose1, projectSubtree($root), true), true);
check('יורש owner_only מהחבילה', projectOwnerOnly($loose1), true);
throws('לא לתוך עצמו', fn () => moveProject($boss, $suite, $suite));
throws('לא לתוך צאצא — מעגל', fn () => moveProject($boss, $suite, $loose1));
moveProject($boss, $loose1, null);
check('חזרה לרמה העליונה', db()->query("SELECT parent_id FROM projects WHERE id = $loose1")->fetch()['parent_id'], null);

echo "\n— עריכה ומחיקה של פרויקט —\n";
$empty = createSubproject($boss, $suite, 'וקטורי (ריק מהייבוא)', 'vector-studio');
$full  = createProject($boss, 'חבילת היצירה · וקטורי', 'malkiel01', 'mini_projects');
task('מטלה אמיתית', $full);
throws('member לא מוחק', fn () => deleteProject($rami, $empty));
throws('פרויקט עם מטלות — לא נמחק', fn () => deleteProject($boss, $full));
throws('פרויקט עם תתי-פרויקטים — לא נמחק', fn () => deleteProject($boss, $root));
db()->prepare("UPDATE projects SET agent_token = 'x' WHERE id = ?")->execute([$empty]);
deleteProject($boss, $empty);
$gone = db()->query("SELECT archived, agent_token FROM projects WHERE id = $empty")->fetch();
check('פרויקט ריק — נמחק, והאסימון שלו נפסל', [(int) $gone['archived'], $gone['agent_token']], [1, '']);
check('לא מופיע ברשימה', in_array($empty, array_column(listProjects($boss), 'id'), true), false);
moveProject($boss, $full, $suite);
$fullRow = db()->query("SELECT * FROM projects WHERE id = $full")->fetch();
check('התיקייה של הנמחק התפנתה — אפשר לתת אותה למי שהועבר', setProjectPath($fullRow, '/vector-studio/'), 'vector-studio');
db()->prepare('UPDATE projects SET path = ? WHERE id = ?')->execute(['vector-studio', $full]);
$other = createSubproject($boss, $suite, 'אחר', 'other');
$otherRow = db()->query("SELECT * FROM projects WHERE id = $other")->fetch();
throws('תיקייה תפוסה אצל אח — נדחית', fn () => setProjectPath($otherRow, 'vector-studio'));
throws('תיקייה לא תקינה — נדחית', fn () => setProjectPath($otherRow, '../etc'));
$rootRow = db()->query("SELECT * FROM projects WHERE id = $root")->fetch();
throws('לפרויקט אב אין תיקייה', fn () => setProjectPath($rootRow, 'x'));

echo "\n— ירושת ריפו —\n";
db()->exec("INSERT INTO projects (name, created_at) VALUES ('בלי ריפו', 0)");
$bare = (int) db()->lastInsertId();
check('פרויקט עליון בלי ריפו — נשאר בלי', requireProject($bare, $boss, 'read')['repo_name'], '');
moveProject($boss, $bare, $suite);
$r = requireProject($bare, $boss, 'read');
check('אחרי העברה — יורש את הריפו של האב', [$r['repo_owner'], $r['repo_name'], $r['repo_inherited']], ['malkiel01', 'mini_projects', true]);
$byId = array_column(listProjects($boss), null, 'id');
check('גם ברשימה', $byId[$bare]['repo_name'], 'mini_projects');
check('ריפו משלו — לא מסומן כירושה', requireProject($root, $boss, 'read')['repo_inherited'], false);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exit($fail ? 1 : 0);
