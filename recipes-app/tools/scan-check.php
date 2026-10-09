<?php
/**
 * בדיקת scan.php — סריקת מתכון מתמונה — על מסד זמני, בלי רשת ובלי בינה.
 * הרצה: php recipes-app/tools/scan-check.php
 *
 * מוכיחה: העלאה (סוג, כמות, תקרת ממתינות), הרשאות (רק הבעלים והמפתח), ביטול,
 * ייצוא עם התמונות, החלת קובץ שחזר מקלוד — פתק עם רכיבים בלבד, כותרת משנה
 * כחלק, כמה מתכונים מסריקה אחת, דחייה — והמתכון נוצר אצל מי שסרק, בפרטיות
 * שבחר, עם התמונות, וקבצי הסריקה נמחקים.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-scan-' . getmypid();
@mkdir($tmp, 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
define('SECRETS_FILE', $tmp . '/secrets.json');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/scan.php';

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

$dev = createUser('malkiel', 'd@x.com', 'sod12345', 'מלכיאל');
$devU = ['id' => $dev['id'], 'role' => 'admin', 'username' => 'malkiel', 'display_name' => 'מלכיאל'];
$noa = createUser('noa', 'n@x.com', 'sod12345', 'נועה'); verifyEmail($noa['token']);
$noaU = ['id' => $noa['id'], 'role' => 'user', 'username' => 'noa', 'display_name' => 'נועה'];
$dan = createUser('dan', 'dn@x.com', 'sod12345', 'דן'); verifyEmail($dan['token']);
$danU = ['id' => $dan['id'], 'role' => 'user', 'username' => 'dan', 'display_name' => 'דן'];

// תמונת PNG זעירה אמיתית (finfo מזהה אותה), וקובץ טקסט שמתחזה לתמונה
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$file = function (string $bytes) use ($tmp): array {
    $p = tempnam($tmp, 'up');
    file_put_contents($p, $bytes);
    return ['tmp_name' => $p, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK];
};
$dirFiles = fn() => count(glob(dirname(DB_FILE) . '/scans/*') ?: []);

echo "\n1. העלאה\n";
$s1 = scanCreate($noaU, [$file($png), $file($png)], 'סלט אבוקדו, שני צדדים של הפתק', 'public', true);
check('נשמרה: ממתינה, ציבורי, 2 תמונות', [$s1['status'], $s1['visibility'], $s1['images']], ['pending', 'public', 2]);
check('הקבצים בתיקיית הסריקות', $dirFiles(), 2);
expectError('לא תמונה — נדחה', fn() => scanCreate($noaU, [$file('<?php echo 1;')], '', 'private', true), 'רק תמונות');
expectError('בלי תמונות', fn() => scanCreate($noaU, [], '', 'private', true), 'לא נבחרו');
expectError('יותר מ-' . SCAN_MAX_IMAGES . ' תמונות', fn() => scanCreate($noaU, array_fill(0, SCAN_MAX_IMAGES + 1, $file($png)), '', 'private', true), 'עד');
$s2 = scanCreate($noaU, [$file($png)], '', 'private', false);
$s3 = scanCreate($noaU, [$file($png)], 'לבטל', 'private', true);
for ($i = 0; $i < SCAN_MAX_PENDING - 3; $i++) scanCreate($noaU, [$file($png)], '', 'private', true);
expectError('תקרת ממתינות למשתמש', fn() => scanCreate($noaU, [$file($png)], '', 'private', true), 'ממתינות');

echo "\n2. הרשאות וביטול\n";
expectError('משתמש אחר לא רואה את הסריקה', fn() => scanFor($s1['id'], $GLOBALS['danU']), 'אינה קיימת');
check('הבעלים והמפתח — כן', [scanFor($s1['id'], $noaU)['id'], scanFor($s1['id'], $devU)['id']], [$s1['id'], $s1['id']]);
expectError('משתמש רגיל לא רואה את התור', fn() => scansPending($GLOBALS['noaU']));
expectError('ולא מחיל קובץ', fn() => scanApply(['format' => SCAN_FORMAT, 'items' => []], $GLOBALS['noaU']));
expectError('אחר לא מבטל', fn() => scanCancel($GLOBALS['s3']['id'], $GLOBALS['danU']), 'אינה קיימת');
$before = $dirFiles();
scanCancel($s3['id'], $noaU);
check('ביטול: נמחקה, והקובץ שלה איתה', [count(scansMine($noaU)['scans']), $dirFiles()], [SCAN_MAX_PENDING - 1, $before - 1]);

echo "\n3. ייצוא לקלוד\n";
$ex = scanExport($devU);
check('פורמט, וכל הממתינות', [$ex['format'], $ex['total_waiting'], $ex['items'][0]['id']], [SCAN_FORMAT, SCAN_MAX_PENDING - 1, 's:' . $s1['id']]);
check('התמונות בתוך הקובץ, וההערה', [count($ex['items'][0]['images']), base64_decode($ex['items'][0]['images'][0]['data']) === $png, $ex['items'][0]['note']],
      [2, true, 'סלט אבוקדו, שני צדדים של הפתק']);
$exu = scanExport($devU, false, fn($id, $i) => "https://x/logs.php?view=scan-image&id=$id&i=$i");
check('בגרסת הטוקן — קישורים, בלי base64', [$exu['items'][0]['images'][1]['url'], isset($exu['items'][0]['images'][1]['data'])],
      ["https://x/logs.php?view=scan-image&id={$s1['id']}&i=1", false]);

echo "\n4. החלת הקובץ שחזר\n";
// הפתק מהדוגמה: רכיבים בלבד, בלי שלבים, ו"תיבול:" ככותרת משנה
$avocado = ['title' => 'סלט אבוקדו', 'servings' => null, 'yield_text' => '', 'difficulty' => null, 'work_minutes' => null, 'wait_minutes' => null, 'tips' => '',
    'sections' => [
        ['name' => '', 'ingredients' => ['2 אבוקדו', '2 מלפפונים חתוכים לקוביות', '½ פלפל צ\'ילי חריף', 'חצי כוס עלי כוסברה/פטרוזיליה'], 'steps' => []],
        ['name' => 'תיבול', 'ingredients' => ['2 כפות מיץ לימון', 'כף וחצי שמן זית', 'מלח', 'חצי כפית חרדל דיז\'ון (לא חובה)'], 'steps' => []],
    ]];
$all = scansMine($noaU)['scans'];
$s4 = $all[0]['id'];   // האחרונה — לשני מתכונים
$r = scanApply(['format' => SCAN_FORMAT, 'items' => [
    ['id' => 's:' . $s1['id'], 'recipes' => [$avocado]],
    ['id' => 's:' . $s2['id'], 'reject' => 'הכתב מטושטש — כדאי לצלם שוב באור טוב'],
    ['id' => 's:' . $s4, 'recipes' => [['title' => 'בצק שמרים', 'sections' => [['ingredients' => ['1 קמח', '3 ביצים', '2½ כפות שמרים']]]],
                                      ['title' => 'זיגוג', 'sections' => [['ingredients' => ['כוס אבקת סוכר'], 'steps' => ['לערבב עם מעט מים']]]]]],
    ['id' => 's:999999', 'recipe' => $avocado],
    ['id' => 's:' . $s1['id'], 'recipe' => $avocado],
]], $devU);
check('תוצאות: נוצר, נדחה, שניים, לא קיים, כבר טופל', array_column($r['results'], 'status'), ['applied', 'rejected', 'applied', 'error', 'error']);
check('נשארו ממתינות', $r['waiting'], SCAN_MAX_PENDING - 4);

$sc = scanFor($s1['id'], $noaU);
$rid = (int) $sc['recipe_id'];
$rec = loadRecipe($rid, $noaU);
check('המתכון אצל מי שסרק, בפרטיות שבחר', [$rec['owner_id'] ?? null, $rec['visibility']], [$noa['id'], 'public']);
check('פתק בלי שלבים — רכיבים בלבד, ו"תיבול" כחלק', [count($rec['sections']), $rec['sections'][1]['name'], count($rec['sections'][1]['ingredients']), count($rec['sections'][0]['steps'])],
      [2, 'תיבול', 4, 0]);
check('הרכיבים מפוענחים, והטקסט כמו שנכתב', [$rec['sections'][0]['ingredients'][0]['free_text'], $rec['sections'][0]['ingredients'][2]['amount_min']], ['2 אבוקדו', 0.5]);
check('שתי התמונות צורפו, והראשונה ראשית', [count(array_filter($rec['media'], fn($m) => $m['kind'] === 'image')), $rec['main_media_id'] !== null], [2, true]);
check('בלי צירוף — אין תמונות; קבצי הסריקה נמחקו', [$dirFiles()], [SCAN_MAX_PENDING - 4]);
$mine = array_column(scansMine($noaU)['scans'], null, 'id');
check('דחייה: הסיבה למשתמש', [$mine[$s2['id']]['status'], $mine[$s2['id']]['message']], ['rejected', 'הכתב מטושטש — כדאי לצלם שוב באור טוב']);
check('סריקה אחת — שני מתכונים', count($mine[$s4]['recipe_ids']), 2);
$yeast = loadRecipe($mine[$s4]['recipe_ids'][0], $noaU);
check('כמות בלי יחידה נשארת כמו שנכתבה; שבר', [$yeast['sections'][0]['ingredients'][0]['free_text'], $yeast['sections'][0]['ingredients'][2]['amount_min'], $yeast['visibility']],
      ['1 קמח', 2.5, 'private']);
expectError('מתכון בלי שם', fn() => scanToDraft(['sections' => [['ingredients' => ['x']]]]), 'שם');
expectError('מתכון ריק', fn() => scanToDraft(['title' => 'x', 'sections' => [['ingredients' => [], 'steps' => []]]]), 'אין רכיבים');
expectError('קובץ לא שלנו', fn() => scanApply(['items' => []], $GLOBALS['devU']), 'format');
expectError('בבינה בלי מפתח — הודעה ברורה', fn() => scanRunAi($GLOBALS['all'][1]['id'], $GLOBALS['devU']), 'ידנית');

echo "\n";
@array_map('unlink', glob("$tmp/{,*/}*", GLOB_BRACE) ?: []);
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ': ' . implode(' | ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
