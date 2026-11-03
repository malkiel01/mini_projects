<?php
/**
 * המסווג של השער (docs/spec.md §5.13.2, שלב 14) — פעולה → סוג אישור מהקטלוג (lib/approvals.php).
 *
 * קובץ אחד, בלי תלויות: השרת עושה לו require (gate-check מסווג בעצמו, לא סומך על ה-hook), וה-hook
 * מריץ אותו ב-CLI (‏php classify.php < input.json). כך השרת וה-hook לא יכולים לחלוק — זה אותו קוד.
 * מיפוי חדש = PR. בעל הלוח לא כותב תבניות (תבנית שגויה פותחת או סוגרת הכול בשקט).
 *
 * פקודת shell מנותחת לחלקים (; && || | & שורה חדשה, $(…) ו-`…`), עם פירוק עוטפים (bash -c, env, timeout,
 * xargs, find -exec…). כל חלק שממופה — נספר; הסוג המחמיר גובר (GATE_ORDER).
 *
 * CLI — קלט JSON: {tool_name, tool_input, project_path, root, session_id, cwd, branch}; פלט: {"type": "…"|null, "types": […]}.
 */
declare(strict_types=1);

/** מהמחמיר לקל. שלושת הראשונים חדשים בשלב 14 — בלי "תמיד" בכלל. */
const GATE_ORDER = ['push-protected', 'change-gate', 'run-remote-script', 'change-deploy', 'secrets', 'delete-files',
                    'touch-other-project', 'merge-own-pr', 'send-team-message', 'push-own-branch'];

/** "מה נחשב לסוג הזה" — במילים, לגיליון 🔐 (לקריאה בלבד). */
const GATE_COVERS = [
    'push-protected'      => 'push בכוח: git push --force, -f, +ref · push ל: main, master · עדכון קובץ ב-main דרך GitHub',
    'change-gate'         => 'כתיבה או מחיקה של: .claude/settings*.json, claude-tasks/bridge/, .github/claude-agent/, ~/.claude-tasks-bridge',
    'run-remote-script'   => 'סקריפט מהרשת: curl … | sh, wget … | bash, bash <(curl …)',
    'change-deploy'       => 'כתיבה או מחיקה ב: .github/workflows/',
    'secrets'             => 'קריאה או כתיבה של: .env*, *.key, data/config.json, קובץ עם secret בשם',
    'delete-files'        => 'מחיקה מחוץ לתיקיית הפרויקט (לא /tmp): rm, git rm, unlink, rmdir, find -delete · מחיקת קובץ דרך GitHub',
    'touch-other-project' => 'כתיבה בריפו מחוץ לתיקיית הפרויקט של הצ\'אט',
    'merge-own-pr'        => 'מיזוג: gh pr merge, merge_pull_request',
    'send-team-message'   => 'הודעה לסשן אחר: create_trigger עם persistent_session_id',
    'push-own-branch'     => 'git push לענף שאינו main / master',
];

const GATE_PROTECTED_BRANCHES = ['main', 'master'];
const GATE_WRAPPERS = ['env', 'timeout', 'nohup', 'time', 'nice', 'command', 'builtin', 'exec', 'xargs', 'sudo', 'stdbuf', 'ionice'];
const GATE_SHELLS = ['sh', 'bash', 'zsh', 'dash', 'ksh'];
const GATE_WRITERS = ['cp', 'mv', 'ln', 'install', 'tee', 'touch', 'truncate', 'chmod', 'chown', 'dd', 'rsync', 'mkdir'];
const GATE_DELETERS = ['rm', 'unlink', 'rmdir', 'shred'];

/** הסוג המחמיר מתוך רשימה, או null. */
function gateStrictest(array $types): ?string {
    foreach (GATE_ORDER as $t) if (in_array($t, $types, true)) return $t;
    return null;
}

/**
 * ‏$ctx: project_path (יחסי לשורש, למשל "claude-tasks"; ריק — לא ידוע), root (שורש הריפו), session_id.
 * מחזיר את כל הסוגים שנמצאו, בלי כפילויות, מהמחמיר לקל.
 */
function gateClassify(string $tool, array $input, array $ctx = []): array {
    $ctx += ['project_path' => '', 'root' => '', 'session_id' => '', 'cwd' => '', 'branch' => ''];
    $found = [];
    if ($tool === 'Bash') {
        $found = gateShell((string) ($input['command'] ?? ''), $ctx, 0);
    } elseif (in_array($tool, ['Write', 'Edit', 'MultiEdit', 'NotebookEdit'], true)) {
        $found = gatePathTypes((string) ($input['file_path'] ?? $input['notebook_path'] ?? ''), $ctx, 'write');
    } elseif ($tool === 'Read') {
        if (gateIsSecretPath((string) ($input['file_path'] ?? ''))) $found[] = 'secrets';
    } elseif (str_starts_with($tool, 'mcp__')) {
        $found = gateMcp($tool, $input, $ctx);
    }
    $out = [];
    foreach (GATE_ORDER as $t) if (in_array($t, $found, true)) $out[] = $t;
    return $out;
}

/* ── קבצים ───────────────────────────────────────────── */

/** נתיב יחסי לשורש הריפו; null — מחוץ לריפו (/tmp, ‏$HOME, scratchpad…). */
function gateRelPath(string $path, array $ctx): ?string {
    $path = trim($path, " \t\"'");
    if ($path === '') return null;
    $root = rtrim((string) $ctx['root'], '/');
    if ($path[0] !== '/' && !str_starts_with($path, '~') && !str_starts_with($path, '$')) {
        // יחסי — לתיקייה הנוכחית (cd לא נעקב; ברירת המחדל: השורש)
        $cwd = rtrim((string) ($ctx['cwd'] ?: $root), '/');
        $path = $cwd !== '' ? "$cwd/$path" : $path;
        if ($root === '' || $cwd === '') return gateNormalize($path);
    }
    if ($root === '' || !str_starts_with($path, '/')) return null;
    $path = gateNormalize($path);
    if ($path === gateNormalize($root)) return '';
    return str_starts_with($path, gateNormalize($root) . '/') ? substr($path, strlen(gateNormalize($root)) + 1) : null;
}

function gateNormalize(string $p): string {
    $abs = str_starts_with($p, '/');
    $parts = [];
    foreach (explode('/', $p) as $s) {
        if ($s === '' || $s === '.') continue;
        if ($s === '..') { array_pop($parts); continue; }
        $parts[] = $s;
    }
    return ($abs ? '/' : '') . implode('/', $parts);
}

function gateIsSecretPath(string $path): bool {
    $path = trim($path, " \t\"'");
    if ($path === '') return false;
    $base = basename($path);
    if (preg_match('/^\.env(\..+)?$/i', $base)) return true;
    if (preg_match('/\.key$/i', $base)) return true;
    if (preg_match('#(^|/)data/config\.json$#', $path)) return true;
    // "secret" בשם קובץ — רק כשזה באמת נתיב (יש בו נקודה או לוכסן), לא מילה בתבנית grep
    return (bool) preg_match('/secret/i', $base) && (str_contains($path, '/') || str_contains($base, '.'));
}

function gateIsGatePath(string $path): bool {
    // ‏.github/claude-agent/ — העותק של השער בריפו של Platform (lib/engine.php CC_PATHS)
    return (bool) preg_match('#(^|/)\.claude/settings[^/]*\.json$|(^|/)claude-tasks/bridge(/|$)|(^|/)\.claude-tasks-bridge(/|$)|(^|/)\.github/claude-agent(/|$)#', $path);
}

/** סוגים לכתיבה ($mode write) או למחיקה ($mode delete) של נתיב אחד. */
function gatePathTypes(string $path, array $ctx, string $mode): array {
    $path = trim($path, " \t\"'");
    if ($path === '') return [];
    $t = [];
    if (gateIsGatePath($path)) $t[] = 'change-gate';
    if (preg_match('#(^|/)\.github/workflows(/|$)#', $path)) $t[] = 'change-deploy';
    if (gateIsSecretPath($path)) $t[] = 'secrets';
    $rel = gateRelPath($path, $ctx);
    $variable = str_contains($path, '$') || str_contains($path, '*') && $mode === 'delete';
    if ($mode === 'delete' && $rel !== null) {
        // מחיקת תיקייה שמכילה את מה שמוגן (‎rm -rf .github‎, ‏rm -rf claude-tasks)
        foreach (['.github/workflows' => 'change-deploy', '.claude/settings.json' => 'change-gate', 'claude-tasks/bridge' => 'change-gate'] as $guarded => $type) {
            if ($rel === '' || str_starts_with($guarded, rtrim($rel, '/') . '/')) $t[] = $type;
        }
    }
    if ($mode === 'delete') {
        // מחיקה מחוץ לתיקיית הפרויקט. מחוץ לריפו — רק /tmp והתיקייה הזמנית חופשיים
        if ($variable) { $t[] = 'delete-files'; }
        elseif ($rel === null) { if (!gateIsTemp($path)) $t[] = 'delete-files'; }
        elseif (!gateInProject($rel, $ctx)) { $t[] = 'delete-files'; }
    } elseif ($rel !== null && !gateInProject($rel, $ctx) && !in_array('change-gate', $t, true) && !in_array('change-deploy', $t, true)) {
        $t[] = 'touch-other-project';
    }
    return $t;
}

function gateIsTemp(string $path): bool {
    return (bool) preg_match('#^(/tmp/|/var/tmp/|\$TMPDIR|\$\{TMPDIR)#', $path);
}

/** בתוך תיקיית הפרויקט? בלי תיקייה ידועה — לא (המחמיר). */
function gateInProject(string $rel, array $ctx): bool {
    $p = trim((string) $ctx['project_path'], '/');
    if ($p === '') return false;
    return $rel === $p || str_starts_with($rel, "$p/");
}

/* ── shell ───────────────────────────────────────────── */

function gateShell(string $cmd, array $ctx, int $depth): array {
    if ($depth > 4 || trim($cmd) === '') return [];
    $found = [];
    // curl | sh — לפני הפירוק לחלקים, כי הצינור הוא העניין
    if (preg_match('/\b(curl|wget)\b[^;&\n]*\|\s*(sudo\s+)?(env\s+\S+\s+)?(ba|z|da|k)?sh\b/', $cmd)
        || preg_match('/\b(curl|wget)\b[^;&\n]*\|\s*(sudo\s+)?(python3?|perl|ruby|node|php)\b/', $cmd)
        || preg_match('/(ba|z|da|k)?sh\s+(-c\s+)?["\']?\s*<\(\s*(curl|wget)\b/', $cmd)
        || preg_match('/(ba|z|da|k)?sh\s+-c\s+["\']?\$\(\s*(curl|wget)\b/', $cmd)
        || preg_match('/\beval\s+["\']?\$\(\s*(curl|wget)\b/', $cmd)) {
        $found[] = 'run-remote-script';
    }
    // תת-פקודות — $(…) ו-`…` נבדקות גם בפני עצמן
    if (preg_match_all('/\$\(([^()]*)\)|`([^`]*)`/', $cmd, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $x) $found = array_merge($found, gateShell($x[1] !== '' ? $x[1] : ($x[2] ?? ''), $ctx, $depth + 1));
    }
    foreach (gateSegments($cmd) as $seg) $found = array_merge($found, gateSegment($seg, $ctx, $depth));
    return array_values(array_unique($found));
}

/** פירוק לחלקים לפי ; && || | & ושורה חדשה — מחוץ למירכאות. */
function gateSegments(string $cmd): array {
    $out = []; $cur = ''; $q = null; $n = strlen($cmd);
    for ($i = 0; $i < $n; $i++) {
        $c = $cmd[$i];
        if ($q !== null) { $cur .= $c; if ($c === $q) $q = null; elseif ($c === '\\' && $q === '"' && $i + 1 < $n) $cur .= $cmd[++$i]; continue; }
        if ($c === '"' || $c === "'") { $q = $c; $cur .= $c; continue; }
        if ($c === '\\' && $i + 1 < $n) { $cur .= $c . $cmd[++$i]; continue; }
        if ($c === ';' || $c === "\n" || $c === '|' || ($c === '&' && ($cmd[$i + 1] ?? '') !== '>' && ($i === 0 || $cmd[$i - 1] !== '>'))) {
            $out[] = $cur; $cur = '';
            if (($c === '|' || $c === '&') && ($cmd[$i + 1] ?? '') === $c) $i++;
            continue;
        }
        $cur .= $c;
    }
    $out[] = $cur;
    return array_values(array_filter(array_map('trim', $out), fn ($s) => $s !== ''));
}

/** מילים, עם מירכאות מוסרות. הפניות (>, >>) נשארות מילים נפרדות. */
function gateWords(string $seg): array {
    $words = []; $cur = ''; $has = false; $q = null; $n = strlen($seg);
    for ($i = 0; $i < $n; $i++) {
        $c = $seg[$i];
        if ($q !== null) { if ($c === $q) { $q = null; continue; } $cur .= $c; continue; }
        if ($c === '"' || $c === "'") { $q = $c; $has = true; continue; }
        if ($c === '\\' && $i + 1 < $n) { $cur .= $seg[++$i]; $has = true; continue; }
        if (ctype_space($c)) { if ($has || $cur !== '') { $words[] = $cur; $cur = ''; $has = false; } continue; }
        if ($c === '>' || $c === '<') {
            if ($has || $cur !== '') {
                if (preg_match('/^\d+$/', $cur)) { $cur = ''; $has = false; }   // 2>
                else { $words[] = $cur; $cur = ''; $has = false; }
            }
            $op = $c; while ($i + 1 < $n && ($seg[$i + 1] === '>' || $seg[$i + 1] === '&' || $seg[$i + 1] === '|')) $op .= $seg[++$i];
            $words[] = $op; continue;
        }
        $cur .= $c; $has = true;
    }
    if ($has || $cur !== '') $words[] = $cur;
    return $words;
}

function gateSegment(string $seg, array $ctx, int $depth): array {
    $w = gateWords($seg);
    $found = [];
    // הפניות כתיבה: > קובץ, >> קובץ (לא ‎>&2‎, לא ‎/dev/null‎)
    for ($i = 0; $i < count($w); $i++) {
        if (preg_match('/^>{1,2}\|?$/', $w[$i]) && isset($w[$i + 1]) && !str_starts_with($w[$i + 1], '/dev/')) {
            $found = array_merge($found, gatePathTypes($w[$i + 1], $ctx, 'write'));
        }
    }
    $w = array_values(array_filter($w, fn ($x) => !preg_match('/^[<>]/', $x)));
    // משתני סביבה ועוטפים
    while ($w && (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $w[0]) || in_array(basename($w[0]), GATE_WRAPPERS, true))) {
        $b = basename(array_shift($w));
        if ($b === 'timeout' && $w && preg_match('/^\d/', $w[0])) array_shift($w);
        while ($w && str_starts_with($w[0], '-')) {
            $opt = array_shift($w);
            if (in_array($b, ['nice', 'timeout', 'xargs', 'sudo'], true) && in_array($opt, ['-n', '-s', '-k', '-u', '-I', '-P', '-L'], true) && $w) array_shift($w);
        }
        if ($b === 'timeout' && $w && preg_match('/^\d/', $w[0])) array_shift($w);
    }
    if (!$w) return $found;
    $cmd = basename($w[0]);
    $args = array_slice($w, 1);

    if (in_array($cmd, GATE_SHELLS, true) || $cmd === 'eval') {
        $i = array_search('-c', $args, true);
        if ($cmd === 'eval') return array_merge($found, gateShell(implode(' ', $args), $ctx, $depth + 1));
        if ($i !== false && isset($args[$i + 1])) return array_merge($found, gateShell($args[$i + 1], $ctx, $depth + 1));
        return $found;   // ‏bash script.sh — לא רואים פנימה
    }
    if ($cmd === 'git') return array_merge($found, gateGit($args, $ctx));
    if ($cmd === 'gh') {
        if (($args[0] ?? '') === 'pr' && ($args[1] ?? '') === 'merge') $found[] = 'merge-own-pr';
        return $found;
    }
    if ($cmd === 'find') {
        if (in_array('-delete', $args, true)) {
            // מוחק בתוך התיקייה (לפי מסננים), לא את התיקייה עצמה — נבדק כקובץ בתוכה
            $root = (isset($args[0]) && $args[0] !== '' && !str_starts_with($args[0], '-')) ? $args[0] : '.';
            $found = array_merge($found, gatePathTypes(rtrim($root, '/') . '/x', $ctx, 'delete'));
        }
        foreach (['-exec', '-execdir', '-ok', '-okdir'] as $x) {
            $i = array_search($x, $args, true);
            if ($i !== false) {
                $sub = [];
                for ($j = $i + 1; $j < count($args) && $args[$j] !== ';' && $args[$j] !== '+' && $args[$j] !== '\\;'; $j++) $sub[] = $args[$j];
                $root = (isset($args[0]) && !str_starts_with($args[0], '-')) ? $args[0] : '.';
                $sub = array_map(fn ($a) => $a === '{}' ? rtrim($root, '/') . '/x' : $a, $sub);
                $found = array_merge($found, gateSegment(implode(' ', array_map('gateQuote', $sub)), $ctx, $depth + 1));
            }
        }
        return $found;
    }
    $paths = array_values(array_filter($args, fn ($a) => $a !== '' && !str_starts_with($a, '-')));
    if (in_array($cmd, GATE_DELETERS, true)) {
        foreach ($paths as $p) $found = array_merge($found, gatePathTypes($p, $ctx, 'delete'));
        return $found;
    }
    if ($cmd === 'sed' || $cmd === 'perl') {
        $inPlace = (bool) array_filter($args, fn ($a) => preg_match('/^-[a-zA-Z]*i/', $a) || str_starts_with($a, '--in-place'));
        foreach ($paths as $p) {
            $found = array_merge($found, $inPlace ? gatePathTypes($p, $ctx, 'write') : (gateIsSecretPath($p) ? ['secrets'] : []));
        }
        return $found;
    }
    if (in_array($cmd, GATE_WRITERS, true)) {
        // ‏cp/mv/ln/install: היעד הוא האחרון; ‏mv גם מוחק את המקור. ‏tee/touch/…: כל הנתיבים
        if (in_array($cmd, ['cp', 'mv', 'ln', 'install', 'rsync'], true) && count($paths) >= 2) {
            $found = array_merge($found, gatePathTypes(end($paths), $ctx, 'write'));
            foreach (array_slice($paths, 0, -1) as $src) {
                if ($cmd === 'mv') $found = array_merge($found, gatePathTypes($src, $ctx, 'delete'));
                elseif (gateIsSecretPath($src)) $found[] = 'secrets';
            }
        } else {
            foreach ($paths as $p) $found = array_merge($found, gatePathTypes($p, $ctx, 'write'));
        }
        return $found;
    }
    // כל פקודה אחרת שנוגעת בקובץ סודות (cat .env, source .env, grep x secrets.json)
    foreach ($paths as $p) if (gateIsSecretPath($p)) { $found[] = 'secrets'; break; }
    return $found;
}

function gateQuote(string $a): string {
    return preg_match('/^[A-Za-z0-9_.\/@%+=:,-]+$/', $a) ? $a : "'" . str_replace("'", "'\\''", $a) . "'";
}

function gateGit(array $args, array $ctx): array {
    // אופציות גלובליות: ‏-C dir, ‏-c k=v, ‏--git-dir=…
    while ($args && str_starts_with($args[0], '-')) {
        $o = array_shift($args);
        if (in_array($o, ['-C', '-c'], true) && $args) array_shift($args);
    }
    $sub = array_shift($args) ?? '';
    if ($sub === 'push') return [gatePushType($args, (string) ($ctx['branch'] ?? ''))];
    if ($sub === 'rm') {
        $out = [];
        foreach (array_filter($args, fn ($a) => !str_starts_with($a, '-')) as $p) $out = array_merge($out, gatePathTypes($p, $ctx, 'delete'));
        return $out;
    }
    if ($sub === 'clean' && array_filter($args, fn ($a) => preg_match('/^-[a-zA-Z]*f/', $a))) return gatePathTypes('.', $ctx, 'delete');
    return [];
}

function gatePushType(array $args, string $branch = ''): string {
    $pos = [];
    foreach ($args as $a) {
        if (in_array($a, ['--force', '-f', '--force-with-lease', '--force-if-includes', '--mirror', '--delete', '-d'], true)
            || str_starts_with($a, '--force-with-lease=') || preg_match('/^-[a-zA-Z]*f/', $a) && !str_starts_with($a, '--')) {
            return 'push-protected';
        }
        if (!str_starts_with($a, '-')) $pos[] = $a;
    }
    // ‏pos[0] = ה-remote, השאר refspecs
    foreach (array_slice($pos, 1) as $ref) {
        if (str_starts_with($ref, '+') || str_starts_with($ref, ':')) return 'push-protected';
        $dst = str_contains($ref, ':') ? substr($ref, strrpos($ref, ':') + 1) : $ref;
        $dst = preg_replace('#^refs/heads/#', '', $dst);
        if ($dst === 'HEAD') $dst = $branch;
        if (in_array($dst, GATE_PROTECTED_BRANCHES, true)) return 'push-protected';
    }
    // בלי refspec — הענף הנוכחי (מה-hook); לא ידוע — כענף משלך
    if (count($pos) < 2 && in_array($branch, GATE_PROTECTED_BRANCHES, true)) return 'push-protected';
    return 'push-own-branch';
}

/* ── MCP ─────────────────────────────────────────────── */

function gateMcp(string $tool, array $input, array $ctx): array {
    $name = preg_replace('/^mcp__.*__/', '', $tool);
    if ($name === 'merge_pull_request') return ['merge-own-pr'];
    if ($name === 'create_trigger') {
        $to = (string) ($input['persistent_session_id'] ?? '');
        $me = (string) $ctx['session_id'];
        $norm = fn ($s) => preg_replace('/^cse_/', 'session_', $s);
        return $to !== '' && $norm($to) !== $norm($me) ? ['send-team-message'] : [];
    }
    if (in_array($name, ['create_or_update_file', 'push_files', 'delete_file'], true)) {
        $branch = (string) ($input['branch'] ?? '');
        if ($branch === '' || in_array($branch, GATE_PROTECTED_BRANCHES, true)) return ['push-protected'];
        return [$name === 'delete_file' ? 'delete-files' : 'push-own-branch'];
    }
    return [];
}

/* ── CLI (ה-hook) ─────────────────────────────────────── */

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $in = json_decode((string) stream_get_contents(STDIN), true);
    if (!is_array($in)) { echo '{"type":null,"types":[]}'; exit(0); }
    $types = gateClassify((string) ($in['tool_name'] ?? ''), is_array($in['tool_input'] ?? null) ? $in['tool_input'] : [],
                          ['project_path' => (string) ($in['project_path'] ?? ''), 'root' => (string) ($in['root'] ?? ''),
                           'session_id' => (string) ($in['session_id'] ?? ''), 'cwd' => (string) ($in['cwd'] ?? ''),
                           'branch' => (string) ($in['branch'] ?? '')]);
    echo json_encode(['type' => gateStrictest($types), 'types' => $types]);
    exit(0);
}
