<?php
/**
 * בדיקת activity.php — מרכז פעילות: כל סוגי הפעילות, "חדש" ומספר על הפעמון,
 * סימון כנקרא, וחסימה — על מסד זמני.
 * הרצה: php recipes-app/tools/activity-check.php
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-act-' . getmypid();
@mkdir($tmp . '/media', 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/social.php';
require_once __DIR__ . '/../lib/network.php';
require_once __DIR__ . '/../lib/collections.php';
require_once __DIR__ . '/../lib/circles.php';
require_once __DIR__ . '/../lib/activity.php';

$fail = [];
function check(string $label, $got, $want): void {
    global $fail;
    $ok = $got === $want;
    echo ($ok ? "  \u{2705} " : "  \u{274C} ") . $label . ': ' . var_export($got, true) .
         ($ok ? "\n" : '  (צפוי ' . var_export($want, true) . ")\n");
    if (!$ok) $fail[] = $label;
}
$mk = function (string $u, string $name) {
    $r = createUser($u, "$u@x.com", 'sod12345', $name);
    if (!empty($r['token'])) verifyEmail($r['token']);
    $row = db()->query('SELECT role FROM users WHERE id = ' . (int) $r['id'])->fetch();
    return ['id' => $r['id'], 'role' => $row['role'], 'username' => $u, 'display_name' => $name];
};
$dana = $mk('dana', 'דנה'); $noa = $mk('noa', 'נועה'); $eli = $mk('eli', 'אלי');
$sec = [['ingredients' => [['free_text' => 'קמח']], 'steps' => [['text' => 'לאפות']]]];
$rec = fn(string $t, array $u, string $v = 'public') => saveRecipe(['title' => $t, 'visibility' => $v, 'sections' => $sec], $u);
$kinds = fn(array $f) => array_map(fn($i) => $i['kind'] . ':' . $i['actor']['name'], $f['items']);

echo "\n1. כל סוגי הפעילות\n";
$danaPub = $rec('העוגה של דנה', $dana);
followUser($noa['id'], $dana);                                   // דנה עוקבת אחרי נועה
followUser($dana['id'], $eli);                                   // אלי עוקב אחרי דנה — עוקב חדש
$noaPub = $rec('המרק של נועה', $noa);                            // נועה פרסמה
$noaPriv = $rec('הסוד של נועה', $noa, 'private');
shareRecipe($noaPriv, [$dana['id']], $noa);                      // שיתוף מתכון
$book = collectionCreate(['name' => 'ספר של נועה'], $noa);
shareCollection($book['id'], [$dana['id']], $noa);               // שיתוף אוסף
addComment($danaPub, null, 'יצא מעולה!', $eli);                  // תגובה על מתכון שלי
$c = addComment($noaPub, null, 'נראה טעים', $dana);
addComment($noaPub, $c, 'תודה דנה', $noa);                      // תשובה לתגובה שלי
$f = activityFeed($dana);
$got = $kinds($f); sort($got);
$want = ['collection:נועה', 'comment:אלי', 'follow:אלי', 'published:נועה', 'reply:נועה', 'share:נועה']; sort($want);
check('שישה סוגים, עם מי עשה', $got, $want);
check('הכול חדש, והמספר על הפעמון', [$f['unread'], activityUnread($dana)], [6, 6]);
check('לכל אחד קישור — התגובה לדף המתכון', array_column($f['items'], 'link', 'kind')['comment'], "#/r/$danaPub");
check('ציטוט מהתגובה', array_column($f['items'], 'snippet', 'kind')['comment'], 'יצא מעולה!');
check('לא רואה את מה שעשתה בעצמה', in_array('comment:דנה', $kinds($f), true), false);

echo "\n2. נקרא — וחדש אחר כך\n";
activityMarkSeen($dana);
check('אחרי פתיחת הדף — אפס', activityUnread($dana), 0);
sleep(1);
$pdo = db();
$pdo->prepare('INSERT INTO index_sites (host, name, start_url, added_at, next_crawl_at) VALUES (?,?,?,?,?)')->execute(['a.co.il', 'א', 'https://a.co.il/', nowIso(), time() + 9999]);
$pdo->prepare('INSERT INTO index_entries (site_id, url, title, stems, seen_at) VALUES (?,?,?,?,1)')->execute([(int) $pdo->lastInsertId(), 'https://a.co.il/1/', 'פשטידה', ' פשטיד', ]);
recommend(['entry_id' => (int) $pdo->lastInsertId()], [$dana['id']], 'תנסי', $noa);
$f = activityFeed($dana);
check('המלצה חדשה — אחת, ראשונה, עם ההערה', [$f['unread'], $f['items'][0]['kind'], $f['items'][0]['snippet']], [1, 'reco', 'תנסי']);

echo "\n3. מעגל — שיתוף דרכו נכנס גם הוא\n";
$fam = circleCreate(['name' => 'משפחה', 'user_ids' => [$dana['id']]], $eli);
$eliPriv = $rec('הסוד של אלי', $eli, 'private');
shareWithCircle('recipe', $eliPriv, $fam['circle']['id'], $eli);
$viaCircle = array_values(array_filter(activityFeed($dana)['items'], fn($i) => $i['kind'] === 'share' && $i['actor']['name'] === 'אלי'));
check('"אלי שיתף/ה איתך מתכון" — בלי שם המעגל', [$viaCircle[0]['title'] ?? null, str_contains(json_encode($viaCircle, JSON_UNESCAPED_UNICODE), 'משפחה')], ['הסוד של אלי', false]);

echo "\n4. חסימה\n";
blockUser($eli['id'], $dana);
check('מי שחסמתי — לא מופיע', array_values(array_filter($kinds(activityFeed($dana)), fn($k) => str_ends_with($k, ':אלי'))), []);
db()->exec('UPDATE users SET blocked = 1 WHERE id = ' . (int) $noa['id']);
check('משתמש שנחסם באתר — לא מופיע', array_values(array_filter($kinds(activityFeed($dana)), fn($k) => str_ends_with($k, ':נועה'))), []);

echo "\n";
@array_map('unlink', glob("$tmp/{,media/}*", GLOB_BRACE) ?: []);
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ': ' . implode(' | ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
