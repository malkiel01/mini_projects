<?php
/** T2: sanitize_rich שומר lang= תקין (פירוש עם <span lang="ar">) — וערך עוין יורד, בלי לפתוח פתח להזרקה. */

test('T2 sanitize_rich: lang= תקין נשמר; ערך עוין / ארוך / לא BCP47 — התכונה יורדת', function () {
    eq('<p>אמא — <span lang="ar">أم</span></p>', sanitize_rich('<p>אמא — <span lang="ar">أم</span></p>'), 'span lang="ar"');
    eq('<span dir="rtl" lang="he-IL">שלום</span>', sanitize_rich('<span dir="rtl" lang="he-IL">שלום</span>'), 'lang + dir');
    eq('<b lang="en-US">x</b>', sanitize_rich('<b lang="en-US">x</b>'));
    eq('<i lang="zh-Hant-TW">x</i>', sanitize_rich('<i lang="zh-Hant-TW">x</i>'));
    foreach (['" onmouseover="alert(1)', 'ar" onclick="x', 'a', 'arab1c', '-ar', 'ar-', 'ar--x', 'ar x', "ar\nx", 'ar-123456789', str_repeat('ab-', 15) . 'ab', '<script>', 'javascript:x', 'ar;color:red'] as $bad) {
        $out = sanitize_rich('<span lang="' . htmlspecialchars($bad, ENT_QUOTES) . '">ט</span>');
        eq('<span>ט</span>', $out, 'ערך עוין יורד: ' . json_encode($bad));
    }
    // שאר הניקוי — כרגיל
    $out = sanitize_rich('<span lang="ar" onclick="x()" style="color:red">ט</span><script>alert(1)</script>');
    ok(str_contains($out, 'lang="ar"') && !str_contains($out, 'onclick') && !str_contains($out, 'script'), 'lang נשמר, onclick / script יורדים: ' . $out);
});

test('T2 פירוש: lang= עובר את word_input (שמירת מילה) בייט בבייט, ושמירה חוזרת אינה משנה אותו', function () {
    $m = '<p>אבא — <span lang="ar">أب</span></p>';
    $w = word_input(['word' => 'אבא', 'meaning' => $m]);
    eq($m, $w['meaning'], 'word_input');
    eq($m, word_input(['word' => 'אבא', 'meaning' => $w['meaning']])['meaning'], 'שמירה חוזרת');
});
