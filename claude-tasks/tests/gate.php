<?php
/**
 * שלב 14 (docs/spec.md §5.13) — המסווג של השער. כל שורה ב-tests/gate-fixtures.json נבדקת פעמיים:
 * ‏gateClassify (מה שהשרת מריץ) וה-CLI ‏php bridge/classify.php (מה שה-hook מריץ) — חייבים להסכים.
 *
 *   php claude-tasks/tests/gate.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../bridge/classify.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

$fx = json_decode((string) file_get_contents(__DIR__ . '/gate-fixtures.json'), true);
$cli = __DIR__ . '/../bridge/classify.php';
$run = function (string $tool, array $input, array $ctx) use ($cli): ?string {
    $p = proc_open(['php', $cli], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode(['tool_name' => $tool, 'tool_input' => $input] + $ctx)); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); proc_close($p);
    return json_decode((string) $out, true)['type'] ?? null;
};

foreach (['cases' => $fx['ctx'], 'on_main' => ['branch' => 'main'] + $fx['ctx']] as $set => $ctx) {
    echo "\n— $set —\n";
    foreach ($fx[$set] as [$tool, $input, $want]) {
        $label = $tool . ': ' . ($input['command'] ?? $input['file_path'] ?? json_encode($input, JSON_UNESCAPED_UNICODE));
        $server = gateStrictest(gateClassify($tool, $input, $ctx));
        check("$label → " . ($want ?? 'null'), $server, $want);
        $hook = $run($tool, $input, $ctx);
        if ($hook !== $server) check("$label — ה-CLI מסכים עם השרת", $hook, $server); else $pass++;
    }
}

echo "\n— בלי תיקיית פרויקט ידועה: כל כתיבה בריפו נחשבת \"פרויקט אחר\" —\n";
check('Write בלי project_path', gateStrictest(gateClassify('Write', ['file_path' => '/repo/a/b.txt'], ['root' => '/repo'])), 'touch-other-project');
check('כל סוג ב-GATE_ORDER יש לו "מה נחשב"', array_diff(GATE_ORDER, array_keys(GATE_COVERS)), []);
check('קלט שבור ל-CLI — null', (function () use ($cli) {
    $p = proc_open(['php', $cli], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], 'not json'); fclose($pipes[0]);
    $o = stream_get_contents($pipes[1]); proc_close($p);
    return json_decode((string) $o, true);
})(), ['type' => null, 'types' => []]);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exit($fail ? 1 : 0);
