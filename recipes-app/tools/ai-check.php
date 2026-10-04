<?php
/**
 * בדיקת ai.php ו-secrets.php — בלי רשת.
 * הרצה: php recipes-app/tools/ai-check.php
 *
 * מוכיחה: גוף הבקשה ל-Claude API (מודל, פלט מובנה, fallbacks), פענוח תשובה
 * כולל סירוב וקטיעה, מדד הדמיון למקור, וקובץ הסודות (הרשאות, אימות, הסתרה).
 * המסלול עם HTTP אמיתי (מול מדמה) נבדק ב-api-check.sh.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-ai-' . getmypid();
@mkdir($tmp, 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
define('SECRETS_FILE', $tmp . '/secrets.json');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ai.php';

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

$dev  = createUser('malkiel', 'd@x.com', 'sod12345', 'מלכיאל');
$devU = ['id' => $dev['id'], 'role' => 'admin', 'username' => 'malkiel'];
$mali = createUser('mali', 'm@x.com', 'sod12345', 'מלי'); verifyEmail($mali['token']);
$maliU = ['id' => $mali['id'], 'role' => 'user', 'username' => 'mali'];

echo "\n1. גוף הבקשה\n";
$sections = [['name' => '', 'steps' => ['מחממים תנור ל-180 מעלות.', 'מערבבים קמח וסוכר.']], ['name' => 'ציפוי', 'steps' => ['ממיסים שוקולד.']]];
$b = aiRequestBody($sections, 'עוגה');
check('המודל', $b['model'], 'claude-opus-5-5');
check('פלט מובנה לפי סכימה', $b['output_config']['format']['type'], 'json_schema');
check('הסכימה סגורה (additionalProperties=false)', $b['output_config']['format']['schema']['additionalProperties'], false);
check('fallbacks ברירת מחדל', $b['fallbacks'], 'default');
check('effort מפורש', $b['output_config']['effort'], 'medium');
check('ה-system בעברית ודורש לשמור כמויות', str_contains($b['system'], 'כל כמות'), true);
check('השלבים בהודעת המשתמש כ-JSON', str_contains($b['messages'][0]['content'], 'ממיסים שוקולד'), true);
check('ניסיון שני — הוראה חזקה יותר', str_contains(aiRequestBody($sections, 'עוגה', true)['messages'][0]['content'], 'הניסיון הקודם'), true);

echo "\n2. פענוח תשובה\n";
$ok = ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode(['sections' => [['name' => '', 'steps' => ['א', ' ב ', '']]]], JSON_UNESCAPED_UNICODE)]]];
check('שלבים מנוקים, ריקים נזרקים', aiParseSections($ok), [['name' => '', 'steps' => ['א', 'ב']]]);
expectError('סירוב', fn() => aiParseSections(['stop_reason' => 'refusal', 'stop_details' => ['category' => 'x'], 'content' => []]), 'סירב');
expectError('קטיעה', fn() => aiParseSections(['stop_reason' => 'max_tokens', 'content' => []]), 'נקטעה');
expectError('לא JSON', fn() => aiParseSections(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'סתם טקסט']]]), 'הצפויה');
expectError('ריק', fn() => aiParseSections(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => '{"sections":[{"name":"","steps":[]}]}']]]), 'ריקים');

echo "\n3. מדד הדמיון\n";
check('נרמול', aiNormalize('  מחממים, תנור — ל-180 מעלות!  '), 'מחממים תנור ל 180 מעלות');
check('זהה לגמרי → 1', aiSimilarity($sections, $sections), 1.0);
$new = [['name' => '', 'steps' => ['מכוונים את התנור ל-180 מעלות ומערבבים בינתיים קמח עם סוכר.']], ['name' => 'ציפוי', 'steps' => ['מכינים את הציפוי: שוקולד מומס.']]];
check('ניסוח אחר → 0', aiSimilarity($sections, $new), 0.0);
$half = [['name' => '', 'steps' => ['מחממים תנור ל-180 מעלות.', 'קמח וסוכר נערבבים יחד.']], ['name' => 'ציפוי', 'steps' => ['השוקולד מומס.']]];
check('אחד מתוך שלושה זהה → 0.333', aiSimilarity($sections, $half), 0.333);
$long = [['name' => '', 'steps' => ['מערבבים את הקמח עם הסוכר ואבקת האפייה בקערה גדולה עד שהכול אחיד.']]];
$contains = [['name' => '', 'steps' => ['קודם: מערבבים את הקמח עם הסוכר ואבקת האפייה בקערה גדולה עד שהכול אחיד, ואז ממשיכים.']]];
check('משפט מקורי ארוך שמוטמע בתוך שלב → נספר', aiSimilarity($long, $contains), 1.0);
check('ריק → 0', aiSimilarity([], $new), 0.0);

echo "\n4. סודות\n";
check('לפני: אין', secretGet('anthropic_api_key'), null);
check('aiAvailable=false בלי מפתח', aiAvailable(), false);
expectError('משתמש רגיל אינו מגדיר', fn() => secretSet('anthropic_api_key', 'sk-ant-xxxxxxxxxxxxxxxxxxxxxxxx', $GLOBALS['maliU']), 'מפתח');
expectError('סוד לא מוכר', fn() => secretSet('other', 'sk-ant-xxxxxxxxxxxxxxxxxxxxxxxx', $GLOBALS['devU']), 'לא מוכר');
expectError('ריק', fn() => secretSet('anthropic_api_key', '  ', $GLOBALS['devU']), 'ריק');
expectError('קצר/עם רווח', fn() => secretSet('anthropic_api_key', 'sk-ant-a b', $GLOBALS['devU']), 'לא נראה');
expectError('קידומת', fn() => secretSet('anthropic_api_key', 'abcdefghijklmnopqrstuvwxyz', $GLOBALS['devU']), 'sk-ant-');
secretSet('anthropic_api_key', '  sk-ant-api03-TESTKEY00000000001234  ', $devU);
check('נשמר מנוקה', secretGet('anthropic_api_key'), 'sk-ant-api03-TESTKEY00000000001234');
check('הקובץ 0600', substr(sprintf('%o', fileperms(SECRETS_FILE)), -3), '600');
$st = secretsStatus()['anthropic_api_key'];
check('הסטטוס: מוגדר, סיומת בלבד, בלי הערך', [$st['set'], $st['hint'], isset($st['value'])], [true, '…1234', false]);
check('aiAvailable=true', aiAvailable(), true);
check('נרשם ביומן בלי הערך', str_contains(json_encode(listLog(['action' => 'secret-set']), JSON_UNESCAPED_UNICODE), 'TESTKEY'), false);
secretRemove('anthropic_api_key', $devU);
check('הוסר', secretGet('anthropic_api_key'), null);
expectError('בלי מפתח — ניסוח נדחה בהודעה ברורה', fn() => aiRewriteSteps($GLOBALS['sections'], 'x', $GLOBALS['maliU']), 'אינו מופעל');

foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp . '/media'); @rmdir($tmp);
echo "\n";
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ": " . implode(', ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
