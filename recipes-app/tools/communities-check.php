<?php
/**
 * בדיקת קהילות (lib/communities.php) — על מסד זמני.
 * הרצה: php recipes-app/tools/communities-check.php
 *
 * מוכיחה: הצטרפות רק בהזמנה ובאישור (ומוזמן לא רואה כלום); רק מנהל מזמין, ורק מי שמקבל
 * ממנו שיתופים; מתכון פרטי שנוסף — גלוי לכל החברים, ולא לאחרים; מתכון פרטי של אחר — אי אפשר;
 * הסרה: מי שהוסיף או מנהל; "הסר מהרשימה שלי" וחסימה מכובדים; עזיבה מוציאה את מה שהחבר
 * הוסיף; הבעלים לא עוזב, מוחק; מחיקת חשבון הבעלים — השיתופים דרך הקבוצה נעלמים; פעילות.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-comm-' . getmypid();
@mkdir($tmp . '/media', 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/communities.php';
require_once __DIR__ . '/../lib/activity.php';

$fail = [];
function check(string $label, $got, $want): void {
    global $fail;
    $ok = $got === $want;
    echo ($ok ? "  \u{2705} " : "  \u{274C} ") . $label . ': ' . var_export($got, true) .
         ($ok ? "\n" : '  (צפוי ' . var_export($want, true) . ")\n");
    if (!$ok) $fail[] = $label;
}
function expectError(string $label, callable $fn, string $needle = ''): void {
    global $fail;
    try { $fn(); echo "  \u{274C} $label: לא נזרקה שגיאה\n"; $fail[] = $label; }
    catch (AppError $e) {
        $ok = $needle === '' || str_contains($e->getMessage(), $needle);
        echo ($ok ? "  \u{2705} " : "  \u{274C} ") . "$label: \"{$e->getMessage()}\"\n";
        if (!$ok) $fail[] = $label;
    }
}
$mk = function (string $u, string $name) {
    $r = createUser($u, "$u@x.com", 'sod12345', $name);
    if (!empty($r['token'])) verifyEmail($r['token']);
    return ['id' => $r['id'], 'role' => 'user', 'username' => $u, 'display_name' => $name];
};
$dana = $mk('dana', 'דנה'); $noa = $mk('noa', 'נועה'); $dan = $mk('dan', 'דן'); $eli = $mk('eli', 'אלי');
$sec = [['ingredients' => [['free_text' => 'קמח']], 'steps' => [['text' => 'לאפות']]]];
$rec = fn(array $u, string $t, string $vis = 'private') => saveRecipe(['title' => $t, 'visibility' => $vis, 'sections' => $sec], $u);
$danaSecret = $rec($dana, 'הסוד של דנה');
$noaSecret = $rec($noa, 'הסוד של נועה');
$eliSecret = $rec($eli, 'הסוד של אלי');
$eliPublic = $rec($eli, 'הציבורי של אלי', 'public');
$sees = fn(array $u, int $rid) => loadRecipe($rid, $u) !== null;
$kinds = fn(array $u) => array_column(activityFeed($u)['items'], 'kind');

echo "\n1. יצירה והזמנה\n";
$c = communityCreate(['name' => 'ספר המתכונים של המשפחה', 'icon' => '🏠', 'description' => 'של כולנו'], $dana);
$id = $c['community']['id'];
check('נוצרה; היוצרת — owner', [$c['community']['name'], $c['community']['my_role'], $c['community']['members_n']], ['ספר המתכונים של המשפחה', 'owner', 1]);
setUserPrefs(['share_from' => 'none'], $dan);
expectError('מי שלא מקבל שיתופים — לא מוזמן', fn() => communityInvite($GLOBALS['id'], [$GLOBALS['dan']['id']], $GLOBALS['dana']), 'לא מקבל');
setUserPrefs(['share_from' => 'all'], $dan);
check('הזמנה לנועה', communityInvite($id, [$noa['id'], $noa['id']], $dana), 1);
check('שוב — לא כפול', communityInvite($id, [$noa['id']], $dana), 0);
check('אצל נועה — הזמנה מדנה, לא קבוצה', [communitiesMine($noa)['invites'][0]['inviter'] ?? null, communitiesMine($noa)['mine']], ['דנה', []]);
check('ובפעילות — "הזמין/ה אותך"', in_array('group_invite', $kinds($noa)), true);
communityAddRecipe($id, $danaSecret, $dana);
check('מוזמנת שעוד לא אישרה — לא רואה את הפרטי, ולא את המתכונים', [$sees($noa, $danaSecret), communityView($id, $noa)['recipes'], communityView($id, $noa)['community']['my_status']], [false, [], 'invited']);
expectError('מוזמנת לא מוסיפה', fn() => communityAddRecipe($GLOBALS['id'], $GLOBALS['noaSecret'], $GLOBALS['noa']), 'אינה קיימת');
expectError('חברה רגילה לא מזמינה', fn() => communityInvite($GLOBALS['id'], [$GLOBALS['eli']['id']], $GLOBALS['noa']), 'אינה קיימת');

echo "\n2. אישור ומתכונים\n";
communityRespond($id, true, $noa);
check('אישרה — רואה את הפרטי של דנה, והוא ב"שותפו איתי"', [$sees($noa, $danaSecret), in_array($danaSecret, array_column(searchRecipes($noa, '', ['shared' => true]), 'id'))], [true, true]);
check('אלי (לא בקבוצה) — לא רואה', $sees($eli, $danaSecret), false);
communityAddRecipe($id, $noaSecret, $noa);
check('נועה הוסיפה את שלה — דנה רואה', $sees($dana, $noaSecret), true);
expectError('מתכון פרטי של אחר — לא', fn() => communityAddRecipe($GLOBALS['id'], $GLOBALS['eliSecret'], $GLOBALS['noa']), 'אינו קיים');
communityAddRecipe($id, $eliPublic, $noa);
check('ציבורי של אחר — כן, בלי שיתוף מיותר', [in_array($eliPublic, array_column(communityView($id, $dana)['recipes'], 'id')), (int) db()->query("SELECT COUNT(*) FROM recipe_shares WHERE recipe_id = $eliPublic")->fetchColumn()], [true, 0]);
check('פעילות אצל נועה: "הוסיף/ה לקבוצה", בלי "שיתף/ה" כפול', [in_array('group_add', $kinds($noa)), in_array('share', $kinds($noa))], [true, false]);
expectError('חברה רגילה לא מסירה מתכון של אחרת', fn() => communityRemoveRecipe($GLOBALS['id'], $GLOBALS['danaSecret'], $GLOBALS['noa']), 'רק מי שהוסיף');
communitySetRole($id, $noa['id'], 'admin', $dana);
check('נועה מנהלת — מזמינה את אלי', communityInvite($id, [$eli['id']], $noa), 1);
communityRespond($id, false, $eli);
check('אלי סירב — לא חבר, ולא רואה', [communityMembership($id, $eli['id']), $sees($eli, $danaSecret)], [null, false]);

echo "\n3. הסתרה וחסימה\n";
leaveShare($danaSecret, $noa);
check('"הסר מהרשימה שלי" — לא רואה, גם אחרי סנכרון', [(communityResync($id) ?? true) && $sees($noa, $danaSecret)], [false]);
unhideRecipe($danaSecret, $noa);
check('"החזר לרשימה שלי" — רואה שוב', $sees($noa, $danaSecret), true);
blockUser($dana['id'], $noa);
communityResync($id);
check('חסימה — לא רואה, גם אחרי סנכרון', $sees($noa, $danaSecret), false);
unblockUser($dana['id'], $noa);
check('ביטול חסימה — רואה שוב (בלי סנכרון ידני)', $sees($noa, $danaSecret), true);

echo "\n4. עזיבה ומחיקה\n";
expectError('היוצרת לא עוזבת', fn() => communityLeave($GLOBALS['id'], $GLOBALS['dana']), 'למחוק');
communityLeave($id, $noa);
check('נועה עזבה — המתכון שלה יצא, ושתיהן לא רואות זו את הפרטי של זו',
      [in_array($noaSecret, array_column(communityView($id, $dana)['recipes'], 'id')), $sees($dana, $noaSecret), $sees($noa, $danaSecret)], [false, false, false]);
communityInvite($id, [$noa['id']], $dana); communityRespond($id, true, $noa);
communityAddRecipe($id, $noaSecret, $noa);
communityDelete($id, $dana);
check('מחיקת הקבוצה — השיתופים דרכה נעלמו', [$sees($dana, $noaSecret), $sees($noa, $danaSecret), communitiesMine($noa)['mine']], [false, false, []]);

$id2 = communityCreate(['name' => 'חוג בישול'], $dana)['community']['id'];
communityInvite($id2, [$noa['id']], $dana); communityRespond($id2, true, $noa);
communityAddRecipe($id2, $noaSecret, $noa);
check('לפני מחיקת חשבון הבעלים — דנה רואה את של נועה', $sees($dana, $noaSecret), true);
deleteAccount($dana, 'sod12345');
check('מחיקת חשבון הבעלים — הקבוצה והשיתוף דרכה נעלמו', [communityRow($id2), (int) db()->query("SELECT COUNT(*) FROM recipe_shares WHERE recipe_id = $noaSecret")->fetchColumn()], [null, 0]);

echo "\n";
@array_map('unlink', glob("$tmp/*") ?: []);
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ': ' . implode(' | ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
