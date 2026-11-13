<?php
/**
 * שער: כל assets/css/*.css מאוזן — כל `{` נסגר ב-`}`, אחרי הסרת הערות, מחרוזות ו-url(...) בלי מרכאות.
 * @media שלא נסגר בולע בשקט כל כלל שנכתב אחריו (נמצא ב-editor.css: שני @media לא סגורים, V14 / V15) — הדפדפן אינו מתריע.
 */

declare(strict_types=1);

/** null — מאוזן; אחרת — מה לא בסדר, עם שורה. */
function css_balance(string $css): ?string
{
    $n = strlen($css); $line = 1; $open = [];
    for ($i = 0; $i < $n; $i++) {
        $c = $css[$i];
        if ($c === "\n") { $line++; continue; }
        if ($c === '/' && ($css[$i + 1] ?? '') === '*') {                            // הערה
            $end = strpos($css, '*/', $i + 2);
            if ($end === false) return "הערה שלא נסגרה (/*) בשורה $line";
            $line += substr_count($css, "\n", $i, $end - $i); $i = $end + 1; continue;
        }
        if ($c === '"' || $c === "'") {                                                // מחרוזת
            for ($j = $i + 1; $j < $n && $css[$j] !== $c; $j++) {
                if ($css[$j] === '\\') { $j++; continue; }
                if ($css[$j] === "\n") return "מחרוזת שלא נסגרה בשורה $line";
            }
            if ($j >= $n) return "מחרוזת שלא נסגרה בשורה $line";
            $i = $j; continue;
        }
        if (($c === 'u' || $c === 'U') && preg_match('/\Gurl\(\s*(?!["\'])/Ai', $css, $m, 0, $i)) {   // url( בלי מרכאות — עד )
            $end = strpos($css, ')', $i);
            if ($end === false) return "url( שלא נסגר בשורה $line";
            $line += substr_count($css, "\n", $i, $end - $i); $i = $end; continue;
        }
        if ($c === '{') $open[] = $line;
        elseif ($c === '}') { if (!$open) return "} מיותר בשורה $line"; array_pop($open); }
    }
    if ($open) return count($open) . ' { לא נסגרו — הפתוח הראשון בשורה ' . $open[0] . ($open[0] !== end($open) ? ', האחרון בשורה ' . end($open) : '');
    return null;
}

test('שער CSS: כל assets/css/*.css מאוזן ({ מול }, אחרי הסרת הערות ומחרוזות)', function () {
    $files = glob(KOL_ROOT . '/assets/css/*.css') ?: [];
    ok(count($files) >= 5, 'נמצאו קובצי CSS: ' . count($files));
    $bad = [];
    foreach ($files as $f) if (($e = css_balance((string)file_get_contents($f))) !== null) $bad[] = basename($f) . ': ' . $e;
    eq([], $bad, 'קובצי CSS לא מאוזנים — כלל שנכתב אחרי { פתוח נבלע בשקט');
});

test('שער CSS: הבדיקה עצמה — תופסת @media לא סגור, } מיותר, הערה / מחרוזת פתוחה; לא נופלת על { } בהערה, במחרוזת וב-url()', function () {
    eq(null, css_balance(".a { color: red; }\n@media (max-width: 10px) { .b { x: y; } }\n"), 'מאוזן');
    eq(null, css_balance("/* { */ .a { content: \"}\"; } .b { content: '{\\'}'; background: url(a{b}.png); } /* } } */"), '{ } בהערה, במחרוזת וב-url() — אינם נספרים');
    eq(null, css_balance('.a { background: url("x}.png"); }'), 'url עם מרכאות');
    $e = css_balance(".a { x: y; }\n@media (max-width: 999px) {\n  .b { x: y; }\n\n.c { x: y; }\n");
    ok($e !== null && str_contains($e, 'בשורה 2'), '@media לא סגור — נתפס, עם השורה שלו: ' . $e);
    $e = css_balance(".a { x: y; }\n}\n");
    ok($e !== null && str_contains($e, '} מיותר בשורה 2'), '} מיותר: ' . $e);
    ok(str_contains((string)css_balance('.a { x: y; } /* פתוחה'), 'הערה שלא נסגרה'), 'הערה פתוחה');
    ok(str_contains((string)css_balance(".a { content: \"x; }\n"), 'מחרוזת שלא נסגרה'), 'מחרוזת פתוחה');
});
