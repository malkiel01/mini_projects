<?php
/**
 * שלב 7b (docs/spec.md §6.1) — אישורים לכל פרויקט: סדר הפתרון, מיגרציה
 * ששומרת את הכללים הישנים כלליים, decide על הפרויקט של המטלה, ask שמוחק רק
 * את הכלל המקומי, מגבלות הקטלוג, הרשאות, וההיסטוריה.
 *
 *   php claude-tasks/tests/policy.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctp-' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');
define('BELL_DIR', $tmp . '/bell');

// מסד "ישן" — טבלת policy של שלב 5 (type PK), עם כלל אחד
$old = new PDO('sqlite:' . DB_FILE);
$old->exec("CREATE TABLE policy (type TEXT PRIMARY KEY, decision TEXT NOT NULL, by_user INTEGER, at INTEGER NOT NULL,
                                 prev TEXT NOT NULL DEFAULT '', source_task INTEGER);
            INSERT INTO policy VALUES ('push-own-branch', 'allow', 1, 1, 'ask', NULL);");
$old = null;

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
function task(string $title, ?int $project): array {
    db()->prepare("INSERT INTO tasks (title, project_id, status, created_by, created_at, updated_at) VALUES (?,?, 'in_progress', 1,?,?)")
        ->execute([$title, $project, nowTs(), nowTs()]);
    return getTask(db(), (int) db()->lastInsertId());
}
$T = fn (array $t) => getTask(db(), (int) $t['id']);

createUser('boss', 'pass-12345', 'בעל הלוח');
createUser('dana', 'pass-12345', 'דנה');
createUser('rami', 'pass-12345', 'רמי');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$dana = ['id' => 2, 'role' => 'member', 'username' => 'dana', 'display_name' => 'דנה'];
$rami = ['id' => 3, 'role' => 'member', 'username' => 'rami', 'display_name' => 'רמי'];

echo "\n— מיגרציה —\n";
check('הכלל הישן נשאר — כללי לכל הלוח', [db()->query('SELECT project_id, decision FROM policy')->fetch(PDO::FETCH_NUM)], [[null, 'allow']]);
check('…ואין שינוי בהתנהגות', policyVerdict('push-own-branch'), 'allow');
check('הטבלה הישנה לא נשארה', db()->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'policy_v1'")->fetchColumn(), 0);

$root   = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
$suite  = createSubproject($boss, $root, 'חבילת היצירה', 'creative-suite');
$raster = createSubproject($boss, $suite, 'רסטר', 'creative-suite/raster');
$app    = createSubproject($boss, $root, 'מתכונים', 'recipes-app');
setMember($boss, $root, 2, 'write');
setMember($boss, $root, 3, 'read');

echo "\n— סדר הפתרון —\n";
check('בלי כלל בענף — הכלל של כל הלוח', policyResolve('push-own-branch', $raster), ['verdict' => 'allow', 'source' => 'board']);
policySet($boss, $suite, 'push-own-branch', 'deny');
check('אב גובר על כל הלוח — וגם על הצאצאים', [policyResolve('push-own-branch', $raster), policyVerdict('push-own-branch', $app)],
      [['verdict' => 'deny', 'source' => ['project_id' => $suite, 'name' => 'חבילת היצירה']], 'allow']);
policySet($boss, $raster, 'push-own-branch', 'allow');
check('ילד גובר על האב ("מותר בחבילה, אבל לא ברסטר" — ולהפך)', policyResolve('push-own-branch', $raster)['source']['project_id'], $raster);
check('…והאב לא מושפע', policyVerdict('push-own-branch', $suite), 'deny');
check('בלי כלל בשום מקום — ask', policyResolve('merge-own-pr', $raster), ['verdict' => 'ask', 'source' => null]);

echo "\n— ask מוחק רק את הכלל המקומי —\n";
policySet($boss, $raster, 'push-own-branch', 'ask');
check('הכלל של רסטר נמחק — חוזר לירושה מהאב', policyResolve('push-own-branch', $raster)['source']['project_id'], $suite);
check('…והכלל של האב ושל הלוח נשארו', [localPolicy('push-own-branch', $suite)['decision'], localPolicy('push-own-branch', null)['decision']], ['deny', 'allow']);

echo "\n— מגבלות הקטלוג ב-policy-set —\n";
throws('סוג no — אין "תמיד מותר" (422)', fn () => policySet($boss, $root, 'delete-files', 'allow'), 422);
policySet($boss, $root, 'delete-files', 'deny');
check('סוג no — "אף פעם" כן', policyVerdict('delete-files', $app), 'deny');
throws('warn + allow בלי הקלדה — 422', fn () => policySet($boss, $root, 'add-exclude', 'allow'), 422);
policySet($boss, $root, 'add-exclude', 'allow', 'add-exclude');
check('warn + allow עם השם — נשמר', localPolicy('add-exclude', $root)['decision'], 'allow');
throws('other — אין כללים', fn () => policySet($boss, $root, 'other', 'deny'), 0);
throws('סוג לא מוכר — נדחה', fn () => policySet($boss, $root, 'launch-rockets', 'deny'));
throws('verdict לא מוכר — נדחה', fn () => policySet($boss, $root, 'merge-own-pr', 'maybe'));

echo "\n— הרשאות —\n";
policySet($dana, $app, 'merge-own-pr', 'allow');
check('write בפרויקט רגיל — קובעת כלל', localPolicy('merge-own-pr', $app)['decision'], 'allow');
throws('read — לא קובע', fn () => policySet($rami, $app, 'merge-own-pr', 'deny'), 403);
db()->prepare('UPDATE projects SET owner_only = 1 WHERE id = ?')->execute([$suite]);
throws('write בענף owner_only — לא קובעת', fn () => policySet($dana, $raster, 'merge-own-pr', 'deny'), 403);
throws('כל הלוח — רק בעל הלוח', fn () => policySet($dana, null, 'merge-own-pr', 'deny'), 403);
db()->prepare('UPDATE projects SET owner_only = 0 WHERE id = ?')->execute([$suite]);

echo "\n— decide: על הפרויקט של המטלה —\n";
$t = task('מיזוג ברסטר', $raster);
askApproval($t, 'bot', ['type' => 'merge-own-pr', 'summary' => 'למזג']);
decide($boss, $T($t), 'always');
check('always — כלל על רסטר, לא על כל הלוח', [localPolicy('merge-own-pr', $raster)['decision'], localPolicy('merge-own-pr', null)], ['allow', null]);
$t2 = task('בלי פרויקט', null);
askApproval($t2, 'bot', ['type' => 'send-team-message', 'summary' => 'הודעה']);
decide($boss, $T($t2), 'never');
check('מטלה בלי פרויקט — כלל לכל הלוח', localPolicy('send-team-message', null)['decision'], 'deny');
$t3 = task('פעם אחת', $raster);
askApproval($t3, 'bot', ['type' => 'push-own-branch', 'summary' => 'push']);
decide($boss, $T($t3), 'once', 'רק הפעם');
// באותה שנייה הסדר לא מוגדר — בחיים הם רחוקים זה מזה
db()->prepare("UPDATE notes SET created_at = created_at + 5 WHERE task_id = ? AND kind = 'decision'")->execute([$t3['id']]);

echo "\n— policy-list —\n";
$l = policyList($raster);
$byType = array_column($l['types'], null, 'type');
check('כל סוג בקטלוג (בלי other)', count($l['types']), count(APPROVAL_TYPES) - 1);
check('מקומי / ירושה / כללי / לא נקבע', [
    [$byType['merge-own-pr']['local'], $byType['merge-own-pr']['source']['project_id'] ?? null],
    [$byType['push-own-branch']['local'], $byType['push-own-branch']['source']['name'] ?? null],
    [$byType['send-team-message']['local'], $byType['send-team-message']['source']],
    [$byType['secrets']['local'], $byType['secrets']['source']],
], [['allow', $raster], [null, 'חבילת היצירה'], [null, 'board'], [null, null]]);
check('ההיסטוריה — גם "פעם אחת", עם המטלה, החדש קודם', [$l['history'][0]['kind'], $l['history'][0]['decision'], $l['history'][0]['task_id'], $l['history'][0]['note'] ?? ''],
      ['once', 'once', (int) $t3['id'], 'רק הפעם']);
check('…וכללים עם מה היה קודם', array_map(fn ($h) => [$h['decision'], $h['prev']], array_values(array_filter($l['history'], fn ($h) => $h['kind'] === 'rule'))),
      [['allow', 'ask'], ['ask', 'allow'], ['allow', 'ask']]);
check('מונים: מותר / חסום (מקומיים בלבד)', $l['counts'], ['allow' => 1, 'deny' => 0]);
check('כל הלוח — היסטוריה בלי מטלות של פרויקטים', array_unique(array_column(policyList(null)['history'], 'kind')), ['rule']);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
