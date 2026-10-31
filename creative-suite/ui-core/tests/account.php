<?php
/**
 * בדיקות של ui-core/php/account — SQLite בזיכרון, בלי שרת.
 *   php creative-suite/ui-core/tests/account.php
 */
declare(strict_types=1);
require __DIR__ . '/../src/php/account/account.php';

$fail = 0;
function ok(bool $c, string $m): void { global $fail; echo ($c ? '  ✓ ' : '  ✗ ') . $m . "\n"; if (!$c) $fail++; }
function err(callable $f): ?UicAccountError { try { $f(); } catch (UicAccountError $e) { return $e; } return null; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec("INSERT INTO users VALUES (1, 'א'), (2, 'ב')");
$me = 1;
$deleted = [];
$hooks = [
    'app' => 'test-app',
    'pdo' => fn() => $pdo,
    'user' => function () use (&$me) { return $me; },
    'export' => fn($uid) => ['docs' => ["doc-of-$uid"]],
    'delete' => function ($uid) use ($pdo, &$deleted) { $deleted[] = $uid; $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]); },
];

ok(uic_account_api('account_prefs', [], $hooks)['prefs'] === [], 'הגדרות ריקות למשתמש חדש');
uic_account_api('account_prefs_set', ['ns' => 'general', 'value' => ['theme' => 'dark', 'units' => 'mm']], $hooks);
uic_account_api('account_prefs_set', ['ns' => 'general', 'value' => ['theme' => 'light']], $hooks);
uic_account_api('account_prefs_set', ['ns' => 'raster-studio', 'value' => ['engine' => 'webgl2']], $hooks);
$p = uic_account_api('account_prefs', [], $hooks)['prefs'];
ok($p['general'] === ['theme' => 'light'] && $p['raster-studio']['engine'] === 'webgl2', 'שמירה ועדכון לפי ns');

$me = 2;
ok(uic_account_api('account_prefs', [], $hooks)['prefs'] === [], 'משתמש אחר לא רואה את ההגדרות של הראשון');
$me = null;
ok(err(fn() => uic_account_api('account_prefs', [], $hooks))?->status === 401, 'לא מחובר → 401');
$me = 1;
ok(err(fn() => uic_account_api('account_prefs_set', ['ns' => '../x', 'value' => []], $hooks))?->status === 400, 'ns לא חוקי → 400');
ok(err(fn() => uic_account_api('account_prefs_set', ['ns' => 'a', 'value' => 'str'], $hooks))?->status === 400, 'value שאינו אובייקט → 400');
ok(err(fn() => uic_account_api('account_prefs_set', ['ns' => 'a', 'value' => ['x' => str_repeat('א', 40000)]], $hooks))?->status === 400, 'גדול מ-64KB → 400');
ok(err(fn() => uic_account_api('account_nope', [], $hooks))?->status === 404, 'פעולה לא ידועה → 404');

$x = uic_account_api('account_export', [], $hooks);
ok($x['app'] === 'test-app' && $x['user'] === '1' && $x['data'] === ['docs' => ['doc-of-1']] && isset($x['prefs']['general']), 'ייצוא: הגדרות + הנתונים של האפליקציה');

ok(err(fn() => uic_account_api('account_delete', [], $hooks))?->status === 400, 'מחיקה בלי אישור → 400');
uic_account_api('account_delete', ['confirm' => 'DELETE'], $hooks);
ok($deleted === ['1'] && (int) $pdo->query('SELECT COUNT(*) FROM uic_prefs WHERE user_id = 1')->fetchColumn() === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'מחיקה: ההגדרות + ה-hook של האפליקציה, רק של המשתמש');
$me = 2;
uic_account_api('account_prefs_set', ['ns' => 'general', 'value' => ['theme' => 'dark']], $hooks);
$h3 = $hooks; $h3['delete'] = function () { throw new RuntimeException('נכשל'); };
try { uic_account_api('account_delete', ['confirm' => 'DELETE'], $h3); } catch (RuntimeException) {}
ok((int) $pdo->query("SELECT COUNT(*) FROM uic_prefs WHERE user_id = '2'")->fetchColumn() === 1, 'מחיקה שנכשלה באפליקציה — ההגדרות לא נמחקו (טרנזקציה)');
$me = 1;
$h2 = $hooks; unset($h2['delete']);
ok(err(fn() => uic_account_api('account_delete', ['confirm' => 'DELETE'], $h2))?->status === 501, 'אין hook מחיקה → 501');

echo $fail ? "\n✗ $fail נכשלו\n" : "\n✓ הכול עבר\n";
exit($fail ? 1 : 0);
