<?php
// תקלת 500 ב-16.11 ("תקלה בשרת" בהפעלת שיטה בגרף): שאילתה שנקראה ממנה שורה אחת ולא נסגרה השאירה את החיבור על תמונת
// מצב ישנה, ואחרי כתיבה של בקשה אחרת — הכתיבה הבאה באותו חיבור נכשלה מיד ב-"database is locked" (בלי busy_timeout).
// התיקון: TsStmt (lib/pdo.php) סוגר את הסמן אחרי fetch / fetchColumn. php trading-strategies/tests/sqlite-lock.php — בלי רשת.
// הבקשה השנייה מדומה בחיבור PDO נפרד, כמו תהליך PHP אחר בשרת.
if (PHP_SAPI !== 'cli') exit;

$tmp = sys_get_temp_dir() . '/ts-sqlite-lock-' . getmypid() . '-' . bin2hex(random_bytes(4));
define('TS_DB_PATH', "$tmp.sqlite");
define('TS_CACHE_PATH', "$tmp.cache.sqlite");
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp*") ?: [] as $f) @unlink($f); });
require __DIR__ . '/../lib/roles.php';
require __DIR__ . '/../lib/cache.php';

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
/** מריץ ומחזיר 'ok' או הודעת החריגה */
function attempt(callable $f): string
{
    try { $f(); return 'ok'; } catch (Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); }
}

$db = ts_db();
$cache = ts_cache();
$other = new PDO('sqlite:' . TS_DB_PATH, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$other->exec('PRAGMA busy_timeout = 5000');
$otherCache = new PDO('sqlite:' . TS_CACHE_PATH, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$otherCache->exec('PRAGMA busy_timeout = 5000');
$db->exec("INSERT INTO users (id, platform_sub, display_name, created_at) VALUES (1, 's1', 'בעל המערכת', '2026-11-16T13:00:00Z')");

// ── 1. fetchColumn ולא נסגר — כמו ts_usage_take / ts_rate_hit; בקשה אחרת כותבת; ואז כתיבה באותו חיבור ──
$st = $db->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
$st->execute([1]);
eq($st->fetchColumn(), 1, 'the read itself');
$other->exec("INSERT INTO rate_hits (user_id, minute, hits) VALUES (1, 1, 1)");   // chart_refresh אחר בטלפון
eq(attempt(fn() => ts_rate_hit(1)), 'ok', 'fetchColumn left open + another request wrote → the next write on this connection still works');

// ── 2. fetch ולא נסגר — כמו ts_backtest ($st חי לאורך כל הבדיקה לאחור, ובתוכה ts_usage_take כותב) ──
$st2 = $db->prepare('SELECT * FROM users WHERE id = ?');
$st2->execute([1]);
eq($st2->fetch()['display_name'] ?? null, 'בעל המערכת', 'the row itself');
$other->exec("INSERT INTO rate_hits (user_id, minute, hits) VALUES (1, 2, 1)");
eq(attempt(fn() => $db->prepare("INSERT INTO provider_usage (key, user_id, day, calls) VALUES ('binance', 0, '2026-11-16', 1)
    ON CONFLICT(key, user_id, day) DO UPDATE SET calls = calls + excluded.calls")->execute()), 'ok',
    'fetch left open (a long backtest) + another request wrote → counting a provider call does not fail');

// ── 3. אותו דבר במטמון (cache.sqlite): המטמון של התוצאות, והנרות ──
$st3 = $cache->prepare('SELECT result FROM method_results WHERE k = ?');
$st3->execute(['none']);
eq($st3->fetchColumn(), false, 'no row');
$cache->exec("INSERT INTO method_runs (method_id, runs) VALUES ('a', 1)");
$st4 = $cache->prepare('SELECT runs FROM method_runs WHERE method_id = ?');
$st4->execute(['a']);
eq($st4->fetchColumn(), 1, 'a row');
$otherCache->exec("INSERT INTO method_runs (method_id, runs) VALUES ('b', 1)");
eq(attempt(fn() => $cache->prepare("INSERT INTO method_runs (method_id, runs) VALUES ('c', 1)")->execute()), 'ok',
    'cache: fetchColumn left open + another request wrote → the next write works');

// ── 4. מה שלא השתנה: fetchAll ו-foreach קוראים הכול, ושורה אחת עדיין חוזרת נכון ──
$db->exec("INSERT INTO users (id, platform_sub, display_name, created_at) VALUES (2, 's2', 'אליס', '2026-11-16T13:00:00Z')");
eq(array_column($db->query('SELECT id FROM users ORDER BY id')->fetchAll(), 'id'), [1, 2], 'fetchAll — all the rows');
$ids = [];
foreach ($db->query('SELECT id FROM users ORDER BY id') as $r) $ids[] = $r['id'];
eq($ids, [1, 2], 'foreach on a statement — all the rows');
eq($db->query('SELECT display_name FROM users ORDER BY id')->fetch(), ['display_name' => 'בעל המערכת'], 'fetch — the first row');
eq(get_class($db->prepare('SELECT 1')), 'TsStmt', 'both databases use TsStmt');
eq(get_class($cache->prepare('SELECT 1')), 'TsStmt', 'the cache too');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
