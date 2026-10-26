<?php
// בדיקות יחידה: זיהוי קוד אימות וסינון HTML. php mailbox/tests/run.php
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../lib/imap.php';
require __DIR__ . '/../lib/sanitize.php';
$fail = 0;
$eq = function ($got, $want, $what) use (&$fail) {
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
};
foreach ([
    ['', 'קוד האימות שלך: 482913', '482913'],
    ['', 'Your verification code is 7731.', '7731'],
    ['קוד אימות', '<b>551234</b> הוא הקוד', '551234'],
    ['', '482913 is your code', '482913'],
    ['', 'הקוד תקף עד 2026. קוד: 90817', '90817'],
    ['', 'הזמנה מספר 123456 נשלחה', null],
    ['', 'code: 12', null],
    ['', 'קוד 123456789', null],
    ['', 'התקשרו 03-6123456 לקבלת קוד', null],
] as [$s, $t, $want]) $eq(mb_find_code($s, $t), $want, "code in «{$t}»");

$evil = mb_sanitize_html('<body onload="x()"><script>alert(1)</script><iframe src="//e"></iframe><img src=x onerror=alert(1)>'
    . '<a href="javascript:alert(1)">a</a><a href="https://ok.test">b</a><form><input></form><svg onload=x></svg>'
    . '<div style="background:url(javascript:alert(1))">t</div><p style="color:red">r</p><object></object><img src="cid:logo@x"></body>',
    ['logo@x' => 'data:image/png;base64,AAAA']);
foreach (['script', 'onload', 'onerror', 'iframe', 'javascript:', '<form', '<input', '<svg', '<object', 'alert'] as $bad) {
    $eq(stripos($evil, $bad), false, "sanitized contains $bad");
}
$eq(str_contains($evil, 'href="https://ok.test" target="_blank" rel="noopener noreferrer"'), true, 'safe link kept, new tab');
$eq(str_contains($evil, 'style="color:red"'), true, 'safe style kept');
$eq(str_contains($evil, 'src="data:image/png;base64,AAAA"'), true, 'cid image inlined');
$eq(mb_clean_css('width:\6a avascript:x'), '', 'css escape');
$eq(mb_sanitize_html('<p>שלום עולם</p>'), '<div><p>שלום עולם</p></div>', 'hebrew utf-8');
echo $fail ? "$fail נכשלו\n" : "הכול עבר\n";
exit($fail ? 1 : 0);
