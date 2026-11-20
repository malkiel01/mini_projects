<?php
// צבע לכל שיטה (spec-stage-a 18.5, S5): method_colors בפרופיל — ריק כברירת מחדל, שמירה, צבע אחד לשיטה אחת, רק מהפלטה,
// בידוד בין משתמשים, ושום נתון קיים לא נמחק (מיגרציה 10 — ALTER בלבד). 2.20: {id: {color, at}}, שיטה לא זמינה נשארת
// במפה והצבע שלה פנוי, והמבנה הישן נקרא בסלחנות.
// php trading-strategies/tests/method-colors-api.php — php -S עם שער מדומה. בלי רשת. הדפדפן — tests/method-colors-ui.mjs.
if (PHP_SAPI !== 'cli') exit;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ts-colors-api-' . getmypid() . '-' . bin2hex(random_bytes(4));
$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}

$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$env = ['TS_DB_PATH' => "$tmp.sqlite", 'TS_HTTP_FIXTURES' => __DIR__ . '/fixtures/market', 'TS_HTTP_LOG' => "$tmp.http.log",
    'TS_TEST_METHODS' => __DIR__ . '/fixtures/methods/alertable.php'] + getenv();
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, '-d', 'auto_prepend_file=' . __DIR__ . '/dev-prepend.php', '-d', 'log_errors=1', '-d', "error_log=$tmp.error.log", '-d', 'memory_limit=512M'],
    [1 => ['file', "$tmp.server.log", 'w'], 2 => ['file', "$tmp.server.log", 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($srv, $tmp) {
    proc_terminate($srv);
    foreach (glob("$tmp*") ?: [] as $f) @unlink($f);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);
$jar = []; $tokens = [];
$NOW = 1794094200;   // "עכשיו" קבוע (X-Dev-Now) — אותם נרות מדומים בכל ריצה; חצי שעה אחרי שעה עגולה
/** [קוד, JSON מפוענח, הגוף כמו שהגיע] */
function call(string $as, string $action, ?array $body = null, array $query = [], ?int $now = null): array
{
    global $port, $jar, $tokens, $NOW;
    $h = ["X-Dev-As: $as", 'X-Dev-Now: ' . ($now ?? $NOW)];
    if (!empty($jar[$as])) $h[] = 'Cookie: ts_session=' . $jar[$as];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF: ' . ($tokens[$as] ?? ''); }
    $ctx = stream_context_create(['http' => ['method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $h),
        'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 120]]);
    $raw = (string)file_get_contents("http://127.0.0.1:$port/trading-strategies/api.php?" . http_build_query(['action' => $action] + $query), false, $ctx);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ts_session=([a-f0-9]+)/i', $line, $mm)) $jar[$as] = $mm[1];
    return [(int)explode(' ', $http_response_header[0])[1], json_decode($raw, true) ?? $raw, $raw];
}
function httpCalls(): int
{
    global $tmp;
    return count(array_filter(explode("\n", (string)@file_get_contents("$tmp.http.log"))));
}
foreach (['alice', 'bob'] as $u) $tokens[$u] = call($u, 'me')[1]['csrf'];

// ── ברירת מחדל: מועדפים ראשונה, ואחריה כל המובנות; בפרופיל ──
[$c, $r] = call('alice', 'market_tabs');
eq([$c, array_column($r['tabs'], 'id'), $r['removed']], [200, ['favorites', 'crypto', 'fx', 'nasdaq100', 'sp500', 'indices', 'commodities'], []], 'default: ⭐ favorites first, then the built-in tabs');
eq($r['tabs'][0]['name'], '⭐ מועדפים', 'the first tab is "⭐ מועדפים"');

// ── ברירת מחדל: אין צבעים — אובייקט ריק (לא רשימה) ──
[$c, $r, $raw] = call('alice', 'profile');
eq([$c, $r['profile']['method_colors']], [200, []], 'default: no colors saved');
eq(str_contains($raw, '"method_colors":{}'), true, 'empty map is a JSON object {} (not [])');
eq(call('alice', 'me')[1]['profile']['method_colors'], [], 'the colors come with "me" (the chart reads them from there)');

$E = fn(string $c, int $at = 0) => ['color' => $c, 'at' => $at];

// ── שמירה: נשמר בפרופיל, חוזר בכל קריאה — {id: {color, at}} (2.20) ──
[$c, $r] = call('alice', 'method_colors_save', ['colors' => ['elliott-w3' => $E('--m5', 100), 'test-cross-a' => $E('--brand', 200)]]);
eq([$c, $r['method_colors']], [200, ['elliott-w3' => $E('--m5', 100), 'test-cross-a' => $E('--brand', 200)]], 'save two colors with their times');
eq(call('alice', 'profile')[1]['profile']['method_colors'], ['elliott-w3' => $E('--m5', 100), 'test-cross-a' => $E('--brand', 200)], 'criterion 3: the color is in the profile');
eq(call('bob', 'profile')[1]['profile']['method_colors'], [], 'isolation: bob has his own colors');

// ── אותו צבע לשתי שיטות זמינות — אסור (קריטריון 2) ──
[$c, $r] = call('alice', 'method_colors_save', ['colors' => ['elliott-w3' => $E('--m5'), 'test-cross-a' => $E('--m5')]]);
eq([$c, $r['error'] ?? null], [422, 'הצבע תפוס על ידי שיטה אחרת'], 'criterion 2: a taken color is refused (two available methods)');
eq(call('alice', 'profile')[1]['profile']['method_colors']['elliott-w3'], $E('--m5', 100), '… and nothing changed');

// ── 2.20: שיטה לא זמינה (שיטת הדוגמה — כבויה, ולמשתמש רגיל לא נראית; או שיטה שהוסרה) נשארת במפה, והצבע שלה פנוי ──
$keepOff = ['elliott-w3' => $E('--m5', 300), 'sample-ma-cross' => $E('--m5', 50), 'gone-method' => $E('--m5', 10)];
[$c, $r] = call('alice', 'method_colors_save', ['colors' => $keepOff]);
eq([$c, $r['method_colors']], [200, $keepOff], '2.20: an unavailable method keeps its entry, and its color is free for an available one');

// ── המבנה הישן ({id: צבע}, S5): נשמר מדפדפן ישן → at: 0; וקריאה סלחנית של מה שכבר שמור במסד ──
eq(call('alice', 'method_colors_save', ['colors' => ['elliott-w3' => '--m3']])[1]['method_colors'], ['elliott-w3' => $E('--m3')], 'old shape on save → {color, at: 0}');
$db = new PDO('sqlite:' . "$tmp.sqlite");
$bobId = (int)$db->query("SELECT id FROM users WHERE email = 'bob@test.local'")->fetchColumn();
$db->exec("UPDATE users SET method_colors = '{\"elliott-w3\":\"--m6\",\"test-cross-a\":{\"color\":\"--m2\",\"at\":5},\"bad\":\"#fff\"}' WHERE id = $bobId");
$db = null;
eq(call('bob', 'profile')[1]['profile']['method_colors'], ['elliott-w3' => $E('--m6'), 'test-cross-a' => $E('--m2', 5)],
    'lenient read: an old-shape color is kept (at 0), a new one as is; only the broken entry is skipped — no saved color is lost');
eq(call('bob', 'me')[1]['profile']['method_colors']['elliott-w3'], $E('--m6'), '… also in "me"');

// ── רק מהפלטה; מפה בלבד; מזהה תקין; זמן תקין; תקרה לגודל ──
foreach ([
    'not in the palette' => ['elliott-w3' => $E('#ff0000')],
    'css injection' => ['elliott-w3' => $E('red; background: url(x)')],
    'a list, not a map' => [$E('--m2')],
    'a bad method id' => ['../x' => $E('--m2')],
    'a non-string color' => ['elliott-w3' => 3],
    'a negative time' => ['elliott-w3' => $E('--m2', -1)],
    'a time in the future' => ['elliott-w3' => $E('--m2', $NOW + 3 * 86400)],
    'a string time' => ['elliott-w3' => ['color' => '--m2', 'at' => '5']],
] as $what => $bad) eq(call('alice', 'method_colors_save', ['colors' => $bad])[0], 422, "refused: $what");
$many = [];
for ($i = 0; $i < 65; $i++) $many["m$i"] = $E('--m2');
eq(call('alice', 'method_colors_save', ['colors' => $many])[0], 422, 'refused: more than 64 entries');
$eight = [];
foreach (['--brand', '--m2', '--m3', '--m4', '--m5', '--m6', '--m7', '--m8'] as $i => $col) $eight["m$i"] = $E($col, $i);
eq(call('alice', 'method_colors_save', ['colors' => $eight])[1]['method_colors'], $eight, 'all 8 colors, one per method');
eq(call('alice', 'method_colors_save', ['colors' => []])[1]['method_colors'], [], 'an empty map clears the colors');
eq(call('alice', 'method_colors_save', ['colors' => ['elliott-w3' => $E('--m4', 7)]])[1]['method_colors'], ['elliott-w3' => $E('--m4', 7)], 'save again after clearing');

// ── הרשאות: בלי CSRF — לא נשמר ──
$keep = $tokens['alice']; $tokens['alice'] = 'bad';
eq(call('alice', 'method_colors_save', ['colors' => ['elliott-w3' => $E('--m2')]])[0] >= 400, true, 'no CSRF → refused');
$tokens['alice'] = $keep;
eq(call('alice', 'profile')[1]['profile']['method_colors'], ['elliott-w3' => $E('--m4', 7)], '… and nothing changed');

// ── שמירת הפרופיל (profile_save) לא נוגעת בצבעים, והצבעים לא נוגעים בפרופיל ──
$prof = ['horizon' => 'short', 'risk_level' => 'medium', 'risk_pct' => '1', 'min_rr' => '1.5', 'min_certainty' => 55];
eq(call('alice', 'profile_save', $prof)[1]['profile']['method_colors'], ['elliott-w3' => $E('--m4', 7)], 'profile_save keeps the colors');
eq(call('alice', 'profile')[1]['profile']['horizon'], 'short', 'saving colors did not touch the rest of the profile');

// ── מיגרציה 10: עמודה, גרסה 10; שמור פגום לא מפיל ──
$db = new PDO('sqlite:' . "$tmp.sqlite");
eq((int)$db->query('PRAGMA user_version')->fetchColumn() >= 10, true, 'migration 10 ran');
eq(in_array('method_colors', array_column($db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true), true, 'users.method_colors exists');
$db->exec("UPDATE users SET method_colors = '{broken' WHERE id = (SELECT id FROM users ORDER BY id LIMIT 1)");
$db = null;
eq(call('alice', 'profile')[0], 200, 'a broken stored value does not break the profile');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
