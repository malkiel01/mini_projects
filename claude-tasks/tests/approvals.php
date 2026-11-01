<?php
/**
 * שלב 5 (docs/spec.md §6) — אישורים ומדיניות: בקשה, החלטה, "תמיד" לפי
 * הקטלוג, מי רשאי להחליט, היומן, והחיבור לערוץ.
 *
 *   php claude-tasks/tests/approvals.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/cta-' . getmypid();
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
function task(string $title, ?int $project, string $status = 'in_progress'): array {
    db()->prepare("INSERT INTO tasks (title, project_id, status, claimed_by, claim_until, created_by, created_at, updated_at)
                   VALUES (?,?,?, 'bot', ?, 1,?,?)")
        ->execute([$title, $project, $status, nowTs() + 600, nowTs(), nowTs()]);
    return getTask(db(), (int) db()->lastInsertId());
}
$T = fn (array $t) => getTask(db(), (int) $t['id']);
function lastNote(int $taskId): array {
    $st = db()->prepare('SELECT * FROM notes WHERE task_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$taskId]);
    return $st->fetch();
}

createUser('boss', 'pass-12345', 'בעל הלוח');
createUser('dana', 'pass-12345', 'דנה');
createUser('rami', 'pass-12345', 'רמי');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$dana = ['id' => 2, 'role' => 'member', 'username' => 'dana', 'display_name' => 'דנה'];
$rami = ['id' => 3, 'role' => 'member', 'username' => 'rami', 'display_name' => 'רמי'];
$root  = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
$suite = createSubproject($boss, $root, 'חבילת היצירה', 'creative-suite');
setMember($boss, $root, 2, 'write');
setMember($boss, $root, 3, 'read');
db()->prepare('UPDATE projects SET owner_only = 1 WHERE id = ?')->execute([$suite]);

echo "\n— הקטלוג והמצב —\n";
check('awaiting_approval ב-STATUSES', in_array('awaiting_approval', STATUSES, true), true);
check('הקטלוג: merge-own-pr=yes, add-exclude=warn, change-deploy=no',
      [APPROVAL_TYPES['merge-own-pr']['always'], APPROVAL_TYPES['add-exclude']['always'], APPROVAL_TYPES['change-deploy']['always']],
      ['yes', 'warn', 'no']);
check('סוג לא מוכר → other', approvalType('launch-rockets'), 'other');
check('approval-types — לכל סוג type, label, always, settings_hint',
      array_keys(approvalTypes()[0]), ['type', 'label', 'always', 'settings_hint']);
check('בלי מדיניות — ask', policyVerdict('merge-own-pr'), 'ask');

echo "\n— בקשת אישור —\n";
$t1 = task('מיזוג', $root);
$r = askApproval($t1, 'bot', ['type' => 'merge-own-pr', 'summary' => 'למזג את #12', 'detail' => 'הבדיקות עברו', 'target' => '#12']);
check('המטלה ב-awaiting_approval ומשוחררת', [$T($t1)['status'], $T($t1)['claimed_by'], (int) $T($t1)['claim_until']],
      ['awaiting_approval', '', 0]);
$n = lastNote((int) $t1['id']);
check('נשמרה הערת approval עם השדות כ-JSON', [$n['kind'], json_decode($n['body'], true)['summary'], json_decode($n['body'], true)['target']],
      ['approval', 'למזג את #12', '#12']);
check('…ומחזירה את המדיניות', $r['verdict'], 'ask');
throws('בלי summary — נדחה', fn () => askApproval(task('x', $root), 'bot', ['type' => 'merge-own-pr']));
$tOther = task('אחר', $root);
askApproval($tOther, 'bot', ['type' => 'launch-rockets', 'summary' => 'משהו']);
check('סוג לא מוכר נשמר כ-other (והמבוקש נזכר)', [lastApproval((int) $tOther['id'])['type'], lastApproval((int) $tOther['id'])['requested_type']],
      ['other', 'launch-rockets']);
$c = db()->query("SELECT status, COUNT(*) c FROM tasks GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
check('נספר במונים (הבקשה בלי summary לא שינתה מצב)', (int) ($c['awaiting_approval'] ?? 0), 2);

echo "\n— החלטה —\n";
$d = decide($boss, $T($t1), 'once', 'רק הפעם');
check('once → answered, הערת decision', [$T($t1)['status'], lastNote((int) $t1['id'])['kind']], ['answered', 'decision']);
check('…והמדיניות לא השתנתה', policyVerdict('merge-own-pr'), 'ask');
check('…ההערה נושאת את ההחלטה וההערה', json_decode(lastNote((int) $t1['id'])['body'], true),
      ['decision' => 'once', 'type' => 'merge-own-pr', 'note' => 'רק הפעם']);
throws('החלטה על מטלה שלא ממתינה — 409', fn () => decide($boss, $T($t1), 'once'), 409);

$t2 = task('דחייה', $root);
askApproval($t2, 'bot', ['type' => 'push-own-branch', 'summary' => 'push']);
decide($boss, $T($t2), 'deny');
check('deny → גם answered (הצ\'אט צריך לקבל את הדחייה)', $T($t2)['status'], 'answered');

$t3 = task('תמיד', $root);
askApproval($t3, 'bot', ['type' => 'merge-own-pr', 'summary' => 'למזג']);
decide($dana, $T($t3), 'always');
check('always → policy allow', policyVerdict('merge-own-pr'), 'allow');
$pol = policyList();
check('policy: מי, ומאיזו מטלה', [$pol['policy'][0]['by_user'], $pol['policy'][0]['source_task'], $pol['policy'][0]['prev']],
      [2, (int) $t3['id'], 'ask']);

$t4 = task('אף פעם', $root);
askApproval($t4, 'bot', ['type' => 'delete-files', 'summary' => 'למחוק קבצים']);
throws('no + always → 422', fn () => decide($boss, $T($t4), 'always'), 422);
check('…והמטלה עדיין ממתינה', $T($t4)['status'], 'awaiting_approval');
decide($boss, $T($t4), 'never');
check('no + never → policy deny', policyVerdict('delete-files'), 'deny');

$t5 = task('אזהרה', $root);
askApproval($t5, 'bot', ['type' => 'add-exclude', 'summary' => 'exclude לנתונים']);
throws('warn + always בלי הקלדה → 422', fn () => decide($boss, $T($t5), 'always'), 422);
throws('warn + always עם שם שגוי → 422', fn () => decide($boss, $T($t5), 'always', '', 'add-exclud'), 422);
decide($boss, $T($t5), 'always', '', 'add-exclude');
check('warn + always עם השם המדויק → allow', policyVerdict('add-exclude'), 'allow');

throws('other + always → 422', fn () => decide($boss, $T($tOther), 'always'), 422);
throws('other + never → 422', fn () => decide($boss, $T($tOther), 'never'), 422);
throws('החלטה לא מוכרת — נדחית', fn () => decide($boss, $T($tOther), 'maybe'));

echo "\n— מי מחליט —\n";
$t6 = task('בענף owner_only', $suite);
askApproval($t6, 'bot', ['type' => 'push-own-branch', 'summary' => 'push']);
throws('write בענף owner_only — לא מחליט', fn () => decide($dana, $T($t6), 'once'), 403);
throws('read — לא מחליט', fn () => decide($rami, $T($tOther), 'once'), 403);
decide($boss, $T($t6), 'once');
check('בעל הלוח מחליט גם בענף owner_only', $T($t6)['status'], 'answered');

echo "\n— המדיניות והיומן —\n";
throws('ביטול — רק בעל הלוח', fn () => revokePolicy($dana, 'merge-own-pr'), 403);
revokePolicy($boss, 'merge-own-pr');
check('ביטול → חזרה ל-ask', policyVerdict('merge-own-pr'), 'ask');
$log = policyList()['log'];
check('היומן: הביטול רשום, עם מה היה קודם', [$log[0]['type'], $log[0]['decision'], $log[0]['prev'], $log[0]['by_user']],
      ['merge-own-pr', 'ask', 'allow', 1]);
check('היומן: כל שינוי שורה (always, never, always, ביטול)', count($log), 4);

echo "\n— ערוץ —\n";
$regToken = bin2hex(random_bytes(16));
db()->prepare('UPDATE projects SET agent_token = ? WHERE id = ?')->execute([encryptSecret($regToken), $root]);
$_SERVER['HTTP_X_WORKER_TOKEN'] = $regToken;
$reg = registerChannel(workerScope(), $root, 'כותב הקוד', 'קוד', 'session_APPR01', '');
$ch = getChannel($reg['channel_id']);
$t7 = task('מערוץ', $root);
askApproval($t7, 'bot', ['type' => 'push-own-branch', 'summary' => 'push לענף'], $ch);
$msgs = channelMessages($boss, (int) $ch['id'])['messages'];
$out = end($msgs);
check('ask-approval מערוץ → בועת 🔐 (out, approval) עם המטלה', [$out['direction'], $out['kind'], $out['task_id'], $out['body']],
      ['out', 'approval', (int) $t7['id'], '🔐 push לענף']);
check('…והבועה נושאת את הבקשה, ממתינה, ובעל הלוח רשאי', [$out['approval']['type'], $out['approval']['pending'], $out['approval']['can_decide']],
      ['push-own-branch', true, true]);
check('…רמי (read) רואה, אבל לא רשאי', end(channelMessages($rami, (int) $ch['id'])['messages'])['approval']['can_decide'], false);
$d = decide($boss, $T($t7), 'once', 'בסדר');
$in = channelInbox($ch);
check('decide → הודעה נכנסת מסוג decision לערוץ', [count($in), $in[0]['kind'], $in[0]['task_id'], $d['channel_message_id'] === $in[0]['id']],
      [1, 'decision', (int) $t7['id'], true]);
check('…בנוסח קריא', $in[0]['body'], "🔐 אושר פעם אחת: push לענף\nבסדר");
$after = channelMessages($boss, (int) $ch['id'])['messages'];
$bubble = array_values(array_filter($after, fn ($m) => $m['kind'] === 'approval'))[0];
check('אחרי ההחלטה — הבועה כבר לא ממתינה', [$bubble['approval']['pending'], $bubble['approval']['can_decide']], [false, false]);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
