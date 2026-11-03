<?php
/**
 * שלב 22א (docs/spec.md §18.3) — יושר ובועה אחת לכל תור: README שלא נקרא נאמר ("לא נקרא — <למה>"), platform-reply לא מכניס
 * העתקים כהודעות, ההקשר של Platform בלי שורות system / status (מקבע את הרצף מהצילום), needs_chat פותח מחדש את ההודעה
 * המקורית, ובדיקת הפרויקט אומרת מה Platform רואה. ספק מדומה.
 *
 *   php claude-tasks/tests/lanes22a.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctl22a-' . getmypid();
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
function model(array $out, ?array &$seen = null): callable {
    return function ($prov, $url, $json) use ($out, &$seen) {
        $seen[] = json_decode($json, true);
        return ['status' => 200, 'body' => json_encode(['content' => [['type' => 'text', 'text' => json_encode($out, JSON_UNESCAPED_UNICODE)]],
                                                        'usage' => ['input_tokens' => 100, 'output_tokens' => 20]])];
    };
}
$GLOBALS['CT_FAKE_NOW'] = 1_800_000_000;
$col = fn (string $sql) => db()->query($sql)->fetchColumn();

createUser('boss', 'pass-12345', 'בעל הלוח');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$root = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
$tc = createProject($boss, 'שעון נוכחות', 'malkiel01', 'mini_projects');
db()->prepare("UPDATE projects SET parent_id = ?, path = 'time-clock', route = 'platform' WHERE id = ?")->execute([$root, $tc]);
addConnection($boss, 'anthropic', 'sk-ant-bossbossbossbossboss', 'אישי', 'claude-opus-5-5');
setQuota($boss, 1, 25);
$regToken = bin2hex(random_bytes(16));
db()->prepare('UPDATE projects SET agent_token = ? WHERE id = ?')->execute([encryptSecret($regToken), $root]);
$_SERVER['HTTP_X_WORKER_TOKEN'] = $regToken;
$cid = (int) registerChannel(workerScope(), $tc, 'שעון נוכחות - צ\'אט', 'קוד', 'session_T22a', '')['channel_id'];
db()->prepare('UPDATE channels SET listening_until = 0 WHERE id = ?')->execute([$cid]);

echo "\n— 22א.1: README שלא נקרא — נאמר, עם הסיבה —\n";
$project = requireProject($tc, $boss, 'read');
[$txt, $src] = platformReadme($project, payerUser($tc));
check('בלי חיבור גיטהאב — "לא נקרא", לא "אין"', [$txt, $src['ok'], str_contains($src['why'], 'time-clock/README.md')], ['', false, true]);
$m1 = channelSend($boss, $cid, 'אני רוצה שתגיד לי בדיוק מה פיתחנו בשעון הנוכחות');
$seen = [];
$r1 = platformReply($boss, $m1, model(['reply' => 'לא הצלחתי לקרוא את ה-README.'], $seen));
$prompt = $seen[0]['messages'][0]['content'] ?? '';
check('בפרומפט: "README: לא נקרא — <למה>", לא "(אין)"', [str_contains($prompt, 'README: לא נקרא —'), str_contains($prompt, "## README של התיקייה\n(אין)")], [true, false]);
check('בהנחיה: לא לומר שמקור שלא נקרא לא קיים', str_contains($seen[0]['system'] ?? '', 'לעולם אל תאמר שהוא לא קיים'), true);
check('meta.sources — README נכשל, עם הסיבה (לשורה "⚠ לא נקרא")', [$r1['message']['meta']['sources'][0]['name'] ?? null, $r1['message']['meta']['sources'][0]['ok'] ?? null], ['README', false]);

echo "\n— 22א.3: אין העתקים כהודעות —\n";
check('platform-reply לא הכניס status / system', (int) $col("SELECT COUNT(*) FROM messages WHERE channel_id = $cid AND author_role = 'system' AND id > $m1"), 0);
check('…הצ\'אט רואה את זה בתקציר', str_contains(channelDigest(getChannel($cid)), 'Platform ענה על 1'), true);
$m2 = channelSend($boss, $cid, 'תריץ את הבדיקות של השעון');
$b0 = @file_get_contents(BELL_DIR . "/$cid") ?: '';
$r2 = platformReply($boss, $m2, model(['reply' => 'העברתי לצ\'אט.', 'needs_chat' => 'להריץ את הבדיקות']));
$orig = db()->query("SELECT answered_by, handled_by, delivered_at, meta FROM messages WHERE id = $m2")->fetch();
check('needs_chat — ההודעה המקורית נפתחת לצ\'אט עם ההערה', [$orig['answered_by'], $orig['handled_by'], $orig['delivered_at'], json_decode($orig['meta'], true)['platform_note'] ?? null],
      ['', 'chat', null, 'להריץ את הבדיקות']);
check('…בלי שורת system שנייה', (int) $col("SELECT COUNT(*) FROM messages WHERE channel_id = $cid AND author_role = 'system' AND id > $m1"), 0);
check('…והתיבה של הצ\'אט מחזירה אותה עם ההערה', array_values(array_filter(channelInbox(getChannel($cid)), fn ($x) => (int) $x['id'] === $m2))[0]['meta']['platform_note'] ?? null, 'להריץ את הבדיקות');
check('…צלצול', (@file_get_contents(BELL_DIR . "/$cid") ?: '') !== $b0, true);
check('הכרטיס יודע שהצ\'אט לא מאזין', $r2['message']['meta']['needs_chat']['listening'] ?? null, false);

echo "\n— 22א.4: ההקשר — תורות בלבד (הרצף מהצילום) —\n";
// שורות ישנות כמו בצילום: העתק "📨 לצ'אט" ו"לא יכול לבצע" של מערכת
channelInsertIn($cid, 'system', null, PLATFORM_RELAY_PREFIX . "\n💬 הודעה מבעל הלוח:\nלמה אתה לא יכול\n\n☁️ Platform ענה:\nאין לי גישה", 'status', null, $m1, false);
channelInsertIn($cid, 'system', null, "☁️ Platform לא יכול לבצע את זה בעצמו — צריך ממך: סיכום\n\n💬 ההודעה של בעל הלוח:\nמה פיתחנו", 'text', null, $m1, false, 'platform');
db()->exec("UPDATE messages SET delivered_at = 1 WHERE author_role = 'system'");
$ctx = platformContext(getChannel($cid), $project, payerUser($tc));
$roles = array_unique(array_column($ctx['messages'], 'author_role'));
sort($roles);
check('בהקשר רק בעל הלוח ו-Platform — בלי מערכת', $roles, ['owner', 'platform']);
$m3 = channelSend($boss, $cid, 'למה אתה לא יכול, צריך להיות מחובר לגיט?');
$seen = [];
platformReply($boss, $m3, model(['reply' => 'כן'], $seen));
$p3 = $seen[0]['messages'][0]['content'] ?? '';
check('הפרומפט לא כולל את ההעתקים הישנים', [str_contains($p3, '📨 לצ\'אט'), str_contains($p3, 'לא יכול לבצע את זה בעצמו')], [false, false]);
check('…וכן כולל את השאלות האמיתיות', [str_contains($p3, 'מה פיתחנו בשעון הנוכחות'), str_contains($p3, 'תריץ את הבדיקות')], [true, true]);

echo "\n— 22א.2: מה Platform רואה בפרויקט —\n";
$c = platformCheck($boss, $tc);
check('שורת הבדיקה: התיקייה, והכשל של גיטהאב בשמו', [$c['path'], $c['github']['ok'], $c['github']['why'] !== '', $c['readme']['ok']], ['time-clock', false, true, false]);
db()->exec("UPDATE projects SET path = '' WHERE id = $tc");
check('תת-פרויקט בלי תיקייה — אומר איפה מתקנים', str_contains(platformCheck($boss, $tc)['readme']['why'], 'לתת-פרויקט אין תיקייה'), true);

echo "\n  ════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
