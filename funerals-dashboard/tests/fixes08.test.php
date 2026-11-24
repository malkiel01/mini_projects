<?php
declare(strict_types=1);
/**
 * פרוסה 08 — בדיקות יחידה בלי שרת ובלי data/ (נקרא מ-tests/run.sh):
 *   P4 — caseDiff לא רושם 402 מול 402.0 כשינוי (lib/events.php)
 *   P2 — ההנחיות לפרויקט ChatGPT: נוצרות מ-ext-lib.php, מפתחות הבדיקות כמו ב-form.js, ואף פעם לא verified
 *   P1/P3 — FDForm.parseImport (הדבקה ב-📥) ב-node
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
function reply(int $status, array $data): never { fwrite(STDERR, json_encode($data, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
require $root . '/lib/events.php';
require $root . '/ext-lib.php';

$fails = 0;
function ok(bool $cond, string $what): void {
    global $fails;
    echo ($cond ? '  ✓ ' : '  ✗ ') . $what . "\n";
    if (!$cond) $fails++;
}

// ===== P4 =====
$base = ['id' => 'fd_x', 'month' => '2026-11', 'name' => 'כהן', 'notes' => '', 'checks' => [],
    'payments' => [['amount' => 402, 'purpose' => 'קבר', 'reference' => '', 'source' => '']]];
$same = $base; $same['payments'][0]['amount'] = 402.0;
ok(caseDiff($base, $same) === [], 'P4: 402 מול 402.0 — אין שינוי');
ok(caseDiff($same, $base) === [], 'P4: 402.0 מול 402 — אין שינוי');
$changed = $base; $changed['payments'][0]['amount'] = 402.5;
ok(array_keys(caseDiff($base, $changed)) === ['payments'], 'P4: 402 מול 402.5 — שינוי');
$str = $base; $str['payments'][0]['amount'] = '402';
ok(array_keys(caseDiff($base, $str)) === ['payments'], 'P4: "402" (מחרוזת) מול 402 — עדיין שינוי (השוואה מחמירה)');
$nul = $base; $nul['payments'][0]['amount'] = null;
ok(array_keys(caseDiff($base, $nul)) === ['payments'], 'P4: null מול 402 — שינוי');
$zero = $nul; $zero['payments'][0]['amount'] = 0;
ok(array_keys(caseDiff($nul, $zero)) === ['payments'], 'P4: null מול 0 — שינוי (ביקורת 4 של 01)');
$more = $base; $more['payments'][] = ['amount' => 1, 'purpose' => '', 'reference' => '', 'source' => ''];
ok(array_keys(caseDiff($base, $more)) === ['payments'], 'P4: תשלום נוסף — שינוי');

// ===== P2 =====
$p = extProjectPrompt();
$file = (string)@file_get_contents($root . '/gpt-project-prompt.md');
ok($file === $p, 'P2: gpt-project-prompt.md נוצר מ-extProjectPrompt() (php tools/build-ext.php)');
preg_match("/const labels = \\{([^}]*)\\}/", (string)file_get_contents($root . '/assets/js/form.js'), $m);
preg_match_all('/(\w+):/', $m[1] ?? '', $keys);
ok($keys[1] === array_keys(EXT_CHECKS), 'P2: מפתחות הבדיקות ב-EXT_CHECKS זהים ל-form.js, באותו סדר');
foreach (array_keys(EXT_CHECKS) as $k) if (!str_contains($p, "`$k`")) ok(false, "P2: חסר $k בהנחיות");
ok(!in_array('verified', EXT_PROJECT_STATUSES, true) && array_diff(EXT_PROJECT_STATUSES, array_keys(EXT_STATUSES)) === [],
    'P2: הסטטוסים המותרים — מתוך הרשימה, בלי verified');
$allowed = substr($p, (int)strpos($p, '## הסטטוסים שמותר להחזיר'));
ok(!str_contains($allowed, '`verified`') && str_contains($p, 'אף פעם לא `verified`'), 'P2: "אף פעם לא verified", ו-verified לא ברשימת המותרים');
ok(str_contains($p, '**בלי `id`**') && str_contains($p, '`id` בדיוק כמו בקובץ הייצוא'), 'P2: id מהייצוא; תיק חדש בלי id');
ok(!preg_match('/\b(find|case_update|whoami|file_text)\b/', $p), 'P2: בלי פעולות API (זרימת קובץ, בלי Actions)');
preg_match('/```json\n(.*?)\n```/s', $p, $ex);
$j = json_decode($ex[1] ?? '', true);
ok(is_array($j) && $j['schema_version'] === 1 && count($j['records']) === 2 && !isset($j['records'][1]['id']), 'P2: הדוגמה היא JSON תקין לייבוא');
$tok = (string)file_get_contents($root . '/tokens.php');
ok(str_contains($tok, "'project_prompt' => extProjectPrompt()"), 'P2: מסך החיבור מקבל את ההנחיות מאותו מקור (tokens.php)');

// ===== P1/P3: parseImport ב-node =====
$js = <<<'JS'
const F = require(process.argv[1]); const a = require('assert');
const rec = {schema_version: 1, month: '2026-11', records: [{name: 'כהן', month: '2026-11'}]};
a.deepStrictEqual(F.parseImport(JSON.stringify(rec)), rec.records);
a.deepStrictEqual(F.parseImport('הנה התשובה:\n```json\n' + JSON.stringify(rec, null, 2) + '\n```\nלהדביק ב-📥'), rec.records);
a.deepStrictEqual(F.parseImport(JSON.stringify(rec.records)), rec.records);
for (const bad of ['', 'שלום', '{"records": 5}', '{"records": []}', '{"records": [1]}', '```json\n{"records":[{"a":1},]}\n```'])
  a.throws(() => F.parseImport(bad), /./, 'parseImport: ' + bad);
console.log('ok');
JS;
$out = []; $rc = 0;
exec('node -e ' . escapeshellarg($js) . ' ' . escapeshellarg($root . '/assets/js/form.js') . ' 2>&1', $out, $rc);
ok($rc === 0, 'P1: parseImport — JSON, ```json עם טקסט מסביב, רשימה; שגיאה בעברית על קלט פגום' . ($rc ? ' — ' . implode(' ', $out) : ''));

if ($fails) { echo "FAIL fixes08.test.php: $fails\n"; exit(1); }
echo "PASS fixes08.test.php: P4 (caseDiff מספרי), P2 (הנחיות לפרויקט ממקור אחד), parseImport\n";
