<?php
/**
 * בדיקת התרגום — php project-manager/tools/i18n-check.php [--keys | --missing <שפה> | --quiet]
 *
 * עובר על כל הקוד (PHP, JS, HTML) ומוצא את הטקסט העברי:
 *   שגיאה  — עברית בתוך מחרוזת עם משתנים ("…{$x}…" ב-PHP, `…${x}…` ב-JS): אי אפשר לתרגם.
 *            במקום זה: t('… {name} …', { name }) / pm_t('… {name} …', ['name' => …]).
 *   אזהרה  — מחרוזת עברית שלא עוברת בפונקציה שמתרגמת (t, pm_t, PmError, pm_event…).
 *            אם היא מתורגמת במקום אחר (תווית ברשימה שמוצגת דרך t) — `// i18n-ok` בסוף השורה.
 *            אם היא לא למשתמש בכלל — `// i18n-none` (לא נכנסת למילון).
 *            לבלוק שלם: `// i18n-ok-start` … `// i18n-ok-end` (וכך גם none).
 *   חסר    — מפתח בלי תרגום באחד המילונים (assets/lang/<שפה>.json), או תרגום שמשתנה
 *            ({name}) שבמקור חסר בו.
 *
 * --keys          כל המפתחות (JSON) — בשביל מי שמתרגם
 * --keys-where    המפתחות עם המקום הראשון שבו הם מופיעים (קובץ:שורה)
 * --missing en    המפתחות שחסרים בשפה (JSON)
 * קוד יציאה 1 כשיש שגיאה, אזהרה או תרגום חסר.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const ROOT = __DIR__ . '/..';
const LANGS = ['en', 'fr', 'ru', 'ar'];
const HEB = '/[\x{0590}-\x{05FF}]/u';
/** פונקציות שהטקסט שבארגומנטים שלהן מתורגם. */
const PHP_TR = ['pm_t', 'PmError', 'pm_event', 'pm_str', 'pm_t_map'];
/** פונקציות שהטקסט בהן לא למשתמש (יומן). */
const PHP_NONE = ['pm_log', 'pm_audit', 'error_log'];
const JS_TR = ['t'];
const JS_NONE = ['report', 'log', 'warn', 'error'];

$errors = $warnings = [];
$keys = [];

function rel(string $f): string { return substr($f, strlen(ROOT) + 1); }

function files(): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', (string)$f);
        $r = rel($p);
        if (preg_match('#^(data|tools|assets/lang)/#', $r)) continue;
        if (preg_match('/\.(php|js|html)$/', $r)) $out[] = $p;
    }
    sort($out);
    return $out;
}

/** סימוני השורות: i18n-ok / i18n-none, כולל בלוקים. */
function markers(string $src): array
{
    $m = [];
    $block = null;
    foreach (explode("\n", $src) as $i => $line) {
        if (preg_match('/i18n-(ok|none)-start/', $line, $x)) $block = $x[1];
        $m[$i + 1] = $block ?? (preg_match('/i18n-(ok|none)\b(?!-)/', $line, $x) ? $x[1] : null);
        if (preg_match('/i18n-(ok|none)-end/', $line)) $block = null;
    }
    return $m;
}

function add_key(string $s, string $where): void
{
    global $keys;
    $keys[$s] ??= $where;
}

/** מחרוזת שנמצאה: למילון, ואזהרה אם לא מתורגמת. */
function found(string $s, array $stack, array $tr, array $none, ?string $mark, string $where): void
{
    global $warnings;
    if ($mark === 'none' || array_intersect($stack, $none)) return;
    add_key($s, $where);
    if ($mark === 'ok' || array_intersect($stack, $tr)) return;
    $warnings[] = "$where  לא מתורגם: " . mb_substr($s, 0, 60);
}

// ---- PHP ----

function scan_php(string $file): void
{
    global $errors;
    $src = (string)file_get_contents($file);
    $marks = markers($src);
    $stack = [];
    $pendingName = null;
    $toks = token_get_all($src);
    foreach ($toks as $i => $tok) {
        if (is_array($tok)) {
            [$id, $text, $line] = $tok;
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            if ($id === T_STRING || $id === T_NAME_QUALIFIED) { $pendingName = $text; continue; }
            if ($id === T_CONSTANT_ENCAPSED_STRING && preg_match(HEB, $text)) {
                $q = $text[0];
                $s = substr($text, 1, -1);
                $s = $q === "'" ? strtr($s, ["\\'" => "'", '\\\\' => '\\']) : stripcslashes($s);
                found($s, $stack, PHP_TR, PHP_NONE, $marks[$line] ?? null, rel($file) . ":$line");
            } elseif ($id === T_ENCAPSED_AND_WHITESPACE && preg_match(HEB, $text)) {
                $mark = $marks[$line] ?? null;
                if ($mark !== 'none' && !array_intersect($stack, PHP_NONE)) $errors[] = rel($file) . ":$line  עברית במחרוזת עם משתנים: " . mb_substr(trim($text), 0, 60);
            }
            $pendingName = null;
            continue;
        }
        if ($tok === '(') { $stack[] = $pendingName ?? ''; }
        elseif ($tok === ')') { array_pop($stack); }
        $pendingName = null;
    }
}

// ---- JS ----

function js_unescape(string $s): string
{
    return preg_replace_callback('/\\\\(u\{[0-9a-fA-F]+\}|u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|.)/su', function ($m) {
        $c = $m[1];
        if ($c[0] === 'u') return mb_chr(hexdec(trim(substr($c, 1), '{}')));
        if ($c[0] === 'x') return mb_chr(hexdec(substr($c, 1)));
        return ['n' => "\n", 't' => "\t", 'r' => "\r", '0' => "\0"][$c] ?? $c;
    }, $s);
}

function scan_js(string $file, ?string $src = null, int $lineBase = 0): void
{
    global $errors;
    $src ??= (string)file_get_contents($file);
    $marks = markers($src);
    $n = strlen($src);
    $line = 1;
    $stack = [];
    $tpl = [];         // עומק ה-${ } בכל תבנית פתוחה
    $last = '';        // התו המשמעותי האחרון — להבדיל בין / של חילוק ל-regex
    $ident = '';
    for ($i = 0; $i < $n; $i++) {
        $c = $src[$i];
        if ($c === "\n") { $line++; continue; }
        if (ctype_space($c)) continue;
        // הערות
        if ($c === '/' && ($src[$i + 1] ?? '') === '/') { $e = strpos($src, "\n", $i); $i = ($e === false ? $n : $e) - 1; continue; }
        if ($c === '/' && ($src[$i + 1] ?? '') === '*') { $e = strpos($src, '*/', $i + 2); $e = $e === false ? $n : $e + 2; $line += substr_count($src, "\n", $i, $e - $i); $i = $e - 1; continue; }
        // מחרוזות
        if ($c === "'" || $c === '"') {
            $j = $i + 1; $start = $line;
            while ($j < $n && $src[$j] !== $c) { if ($src[$j] === '\\') $j++; elseif ($src[$j] === "\n") $line++; $j++; }
            $s = js_unescape(substr($src, $i + 1, $j - $i - 1));
            if (preg_match(HEB, $s)) found($s, $stack, JS_TR, JS_NONE, $marks[$start] ?? null, rel($file) . ':' . ($start + $lineBase));
            $i = $j; $last = 'v'; $ident = ''; continue;
        }
        if ($c === '`' || ($c === '}' && $tpl && end($tpl) === 0)) {
            // תבנית: מהבקטיק (או מסוף ${…}) ועד ${ או בקטיק
            if ($c === '}') array_pop($tpl);
            $j = $i + 1; $start = $line; $buf = '';
            while ($j < $n && $src[$j] !== '`' && !($src[$j] === '$' && ($src[$j + 1] ?? '') === '{')) {
                if ($src[$j] === '\\') { $buf .= $src[$j] . ($src[$j + 1] ?? ''); $j += 2; continue; }
                if ($src[$j] === "\n") $line++;
                $buf .= $src[$j++];
            }
            $open = $j < $n && $src[$j] === '$';
            $whole = $c === '`' && !$open; // `…` בלי משתנים — כמו מחרוזת רגילה
            if (preg_match(HEB, $buf)) {
                $mark = $marks[$start] ?? null;
                if ($whole) found(js_unescape($buf), $stack, JS_TR, JS_NONE, $mark, rel($file) . ':' . ($start + $lineBase));
                elseif ($mark !== 'none' && !array_intersect($stack, JS_NONE)) $errors[] = rel($file) . ':' . ($start + $lineBase) . '  עברית בתבנית עם ${}: ' . mb_substr(trim($buf), 0, 60);
            }
            if ($open) { $tpl[] = 0; $i = $j + 1; } else { $i = $j; }
            $last = 'v'; $ident = ''; continue;
        }
        if ($c === '{' && $tpl) { $tpl[count($tpl) - 1]++; }
        if ($c === '}' && $tpl) { $tpl[count($tpl) - 1]--; }
        // regex: / אחרי אופרטור או בתחילת ביטוי
        if ($c === '/' && ($last === '' || str_contains('(,=:[!&|?{};+-*%<>~^', $last))) {
            $j = $i + 1; $cls = false;
            while ($j < $n && ($src[$j] !== '/' || $cls) && $src[$j] !== "\n") {
                if ($src[$j] === '\\') $j++; elseif ($src[$j] === '[') $cls = true; elseif ($src[$j] === ']') $cls = false;
                $j++;
            }
            $i = $j; while ($i + 1 < $n && ctype_alpha($src[$i + 1])) $i++;
            $last = 'v'; $ident = ''; continue;
        }
        if (ctype_alnum($c) || $c === '_' || $c === '$') {
            $j = $i; while ($j < $n && (ctype_alnum($src[$j]) || $src[$j] === '_' || $src[$j] === '$')) $j++;
            $ident = substr($src, $i, $j - $i); $i = $j - 1; $last = 'v'; continue;
        }
        if ($c === '(') { $stack[] = $ident; }
        elseif ($c === ')') { array_pop($stack); }
        $last = $c; $ident = '';
    }
}

// ---- HTML ----

function scan_html(string $file): void
{
    $src = (string)file_get_contents($file);
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $src, LIBXML_NOERROR);
    $xp = new DOMXPath($dom);
    $norm = fn($s) => trim((string)preg_replace('/\s+/u', ' ', $s));
    foreach ($xp->query('//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::*[@data-no-i18n])]') as $n) {
        $s = $norm($n->nodeValue);
        if ($s !== '' && preg_match(HEB, $s)) add_key($s, rel($file) . ':' . $n->getLineNo());
    }
    foreach (['placeholder', 'title', 'aria-label', 'alt'] as $a) {
        foreach ($xp->query("//*[@$a and not(ancestor-or-self::*[@data-no-i18n])]") as $el) {
            $s = $norm($el->getAttribute($a));
            if ($s !== '' && preg_match(HEB, $s)) add_key($s, rel($file) . ':' . $el->getLineNo());
        }
    }
    foreach ($xp->query('//script[not(@src)]') as $sc) scan_js($file, $sc->nodeValue, $sc->getLineNo() - 1);
}

foreach (files() as $f) {
    match (pathinfo($f, PATHINFO_EXTENSION)) {
        'php' => scan_php($f), 'js' => scan_js($f), 'html' => scan_html($f),
    };
}

$args = array_slice($argv, 1);
if (($args[0] ?? '') === '--keys-where') {
    echo json_encode($keys, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    exit(0);
}
if (($args[0] ?? '') === '--keys') {
    echo json_encode(array_keys($keys), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    exit(0);
}

$vars = fn($s) => preg_match_all('/\{([^{}]+)\}/u', $s, $m) ? array_unique($m[1]) : [];
$missing = [];
$bad = [];
$unused = [];
foreach (LANGS as $l) {
    $f = ROOT . "/assets/lang/$l.json";
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
    if (!is_array($d)) { $errors[] = "assets/lang/$l.json  JSON לא תקין"; $d = []; }
    foreach ($keys as $k => $where) {
        $tr = $d[$k] ?? null;
        if ($tr === null || $tr === '' || $tr === []) { $missing[$l][] = $k; continue; }
        if (preg_match('/^\{[^{}]+\}$/u', $k)) continue; // כינוי של משתנה בנוסח: "{קישור}" ← "{link}"
        foreach (is_array($tr) ? $tr : [$tr] as $form) {
            foreach ($vars($k) as $v) {
                // בנוסחי ההודעות המשתנה עצמו מתורגם: {קישור} ← {link}
                if ($v === 'n' && is_array($tr)) continue;
                $alias = isset($d['{' . $v . '}']) ? trim((string)$d['{' . $v . '}'], '{}') : null;
                if (!str_contains((string)$form, '{' . $v . '}') && !(is_string($alias) && str_contains((string)$form, '{' . $alias . '}'))) {
                    $bad[] = "$l  חסר {{$v}} בתרגום של: " . mb_substr($k, 0, 60);
                    break 2;
                }
            }
        }
    }
    foreach ($d as $k => $_) if (!isset($keys[$k])) $unused[$l][] = $k;
}

if (($args[0] ?? '') === '--missing') {
    echo json_encode($missing[$args[1] ?? ''] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    exit(0);
}

$quiet = in_array('--quiet', $args, true);
foreach ($errors as $e) echo "שגיאה  $e\n";
foreach ($warnings as $w) echo "אזהרה  $w\n";
foreach ($bad as $b) echo "משתנה  $b\n";
if (!$quiet) foreach ($unused as $l => $list) foreach ($list as $k) echo "מיותר  $l  " . mb_substr($k, 0, 60) . "\n";
echo "\nמפתחות: " . count($keys) . " · שגיאות: " . count($errors) . " · אזהרות: " . count($warnings) . " · משתנים: " . count($bad) . "\n";
foreach (LANGS as $l) echo "  $l: חסרים " . count($missing[$l] ?? []) . "\n";
exit($errors || $warnings || $bad || array_filter($missing) ? 1 : 0);
