<?php
/** מנוע התוכן — קריטריונים 1–9 ו-12 של docs/spec/02-content-engine.md §10 ("C1 …"), ובדיקות הזרקה לניקוי. */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$A = ident('301', 'עורכת', 'a@example.test');
$B = ident('302', 'עורך', 'b@example.test');
call('GET', 'me', [], $A);
call('GET', 'me', [], $B);

$ok = function (array $r, string $name): array {
    eq($r[0], 200, $name . ($r[0] !== 200 ? ' — ' . json_encode($r[1]['error'] ?? $GLOBALS['t_last_500'] ?? null, JSON_UNESCAPED_UNICODE) : ''));
    return $r[1];
};
$msgs = fn(array $list) => implode(' | ', array_column($list, 'msg'));

// ── כותר ומהדורה ─────────────────────────────────────────────
$t = $ok(call('POST', 'content-title-save', ['name' => 'סידור עדות המזרח', 'kind' => 'siddur', 'author' => 'דוגמה'], $A), 'יצירת כותר')['title'];
check('כותר: pid ציבורי, כבוי לציבור', sf_is_pid($t['pid']) && $t['public_allowed'] === false && $t['access'] === 'free');
$ed = $ok(call('POST', 'content-edition-save', ['title' => $t['pid'], 'label' => 'נוסח ראשון'], $A), 'יצירת מהדורה')['edition'];
$E = $ed['pid'];
$view = fn() => $ok(call('GET', 'content-edition', [], $A, ['edition' => $E]), 'content-edition');
eq(code(call('POST', 'content-title-save', ['name' => 'x', 'kind' => 'novel'], $A)), 'bad_input', 'סוג כותר לא מוכר → bad_input');
eq(code(call('GET', 'content-titles', [], ident('399', 'זר', null, ['granted' => false]))), 'login_required', 'בלי הרשאה — אין גישה לניהול התוכן');
eq(call('GET', 'content-titles', [])[0], 401, 'בלי זהות — אין גישה לניהול התוכן');

// ── C1: script / onclick / style / iframe → נשמר בלעדיהם, עם ⚠️ ─
$dirty = "<sf-section title=\"שחרית\"><p onclick=\"steal()\" style=\"color:red\">בָּרוּךְ אַתָּה</p>\n<script>alert(1)</script><iframe src=\"https://evil.test\"></iframe>\n<p>שני</p></sf-section>";
$r = $ok(call('POST', 'content-save', ['edition' => $E, 'html' => $dirty, 'base' => 0, 'from' => 0], $A), 'C1 שמירה עם רכיבים אסורים');
$h1 = $r['draft']['html'];
foreach (['<script', 'alert(1)', 'onclick', 'style=', '<iframe', 'evil.test'] as $bad) check("C1 \"$bad\" לא נשמר", !str_contains($h1, $bad), $h1);
check('C1 הטקסט נשמר', str_contains($h1, 'בָּרוּךְ אַתָּה') && str_contains($h1, 'שני'));
$w = $msgs($r['warnings']);
foreach (['script', 'iframe', 'onclick', 'style'] as $x) check("C1 ⚠️ מזכירה את $x", str_contains($w, $x), $w);
check('C1 האזהרות אינן חוסמות פרסום', !array_filter($r['warnings'], fn($x) => $x['blocks']));
eq($r['draft']['status'], 'draft', 'C1 נוצרה טיוטה');
eq($r['draft']['n'], 1, 'C1 גרסה 1');
$v = $r['draft']['v'];

// ── C2: תגית לא סגורה / sid כפול → השמירה נכשלת, עם המקום ───────
$r = call('POST', 'content-save', ['edition' => $E, 'html' => "<p>שורה אחת</p>\n<p>שורה <b>שתיים</p>", 'base' => $v], $A);
eq([$r[0], code($r)], [422, 'invalid'], 'C2 קינון שבור → 422');
$e0 = $r[1]['error']['details']['errors'][0] ?? [];
eq($e0['line'] ?? null, 2, 'C2 ההודעה מצביעה על שורה 2');
check('C2 ההודעה מזכירה את התגית', str_contains($e0['msg'] ?? '', '‹b›'), $e0);
$r = call('POST', 'content-save', ['edition' => $E, 'html' => "<p>פתוח", 'base' => $v], $A);
eq(code($r), 'invalid', 'C2 תגית שלא נסגרה → invalid');
check('C2 "לא נסגרה" + שורה ועמודה', str_contains($r[1]['error']['details']['errors'][0]['msg'] ?? '', 'לא נסגרה') && ($r[1]['error']['details']['errors'][0]['col'] ?? 0) === 1);
$r = call('POST', 'content-save', ['edition' => $E, 'html' => "<p sid=\"aaaa1111\">א</p>\n\n<p sid=\"aaaa1111\">ב</p>", 'base' => $v], $A);
eq(code($r), 'invalid', 'C2 sid כפול → invalid');
$e0 = $r[1]['error']['details']['errors'][0] ?? [];
check('C2 sid כפול: שורה 3 ושם המזהה', ($e0['line'] ?? 0) === 3 && str_contains($e0['msg'] ?? '', 'aaaa1111'), $e0);
eq($view()['draft']['v'], $v, 'C2 שמירה שנכשלה לא שינתה את הטיוטה');
foreach (["<p>a</b></p>", "<table><tr>x<td>1</td></tr></table>", "<li>x</li>", "<sf-anchor />", "<p><ul><li>x</li></ul></p>", "<p title=\"x>t</p>"] as $bad) {
    eq(code(call('POST', 'content-check', ['edition' => $E, 'html' => $bad], $A)), null, "check לא נכשל בעצמו: $bad");
    check("C2 מבנה שבור נדחה: $bad", (call('POST', 'content-check', ['edition' => $E, 'html' => $bad], $A)[1]['errors'] ?? []) !== []);
}

// ── C4: sid נשמר בעריכה ובפרסום; רכיב חדש — sid חדש ─────────────
preg_match_all('/sid="([a-z0-9]{8})"/', $h1, $m);
$sids1 = $m[1];
eq(count($sids1), 3, 'C4 מקטע + שתי פסקאות קיבלו sid');
$edit = str_replace('שני', 'שני (מתוקן)', $h1) . '<p>פסקה חדשה</p>';
$r = $ok(call('POST', 'content-save', ['edition' => $E, 'html' => $edit, 'base' => $v], $A), 'C4 עריכה');
$v = $r['draft']['v'];
eq($r['draft']['n'], 1, 'C4 שמירה נוספת — אותה טיוטה');
preg_match_all('/sid="([a-z0-9]{8})"/', $r['draft']['html'], $m);
eq(array_slice($m[1], 0, 3), $sids1, 'C4 המזהים הקיימים לא השתנו');
check('C4 הפסקה החדשה קיבלה sid חדש', count($m[1]) === 4 && !in_array($m[1][3], $sids1, true));
$sids2 = $m[1];

// ── C3: קישור ליעד חסר / תנאי לא מוכר → שמירה מותרת, פרסום חסום ───
$withBad = $r['draft']['html'] . '<p><sf-link to="zzzz9999">לשם</sf-link> <sf-cond rule="no-such">x</sf-cond></p>';
$r = $ok(call('POST', 'content-save', ['edition' => $E, 'html' => $withBad, 'base' => $v], $A), 'C3 שמירה עם קישור ותנאי שגויים');
$v = $r['draft']['v'];
$blk = array_filter($r['warnings'], fn($x) => $x['blocks']);
eq(count($blk), 2, 'C3 שתי אזהרות שחוסמות פרסום');
check('C3 ⚠️ על הקישור ועל התנאי', str_contains($msgs($blk), 'zzzz9999') && str_contains($msgs($blk), 'no-such'), $msgs($blk));
$r = call('POST', 'content-publish', ['edition' => $E, 'base' => $v], $A);
eq([$r[0], code($r)], [422, 'invalid'], 'C3 פרסום חסום');
eq(count($r[1]['error']['details']['warnings'] ?? []), 2, 'C3 הפרסום מחזיר את הבעיות');
eq((int)sf_val("SELECT COUNT(*) FROM revisions WHERE status = 'published'"), 0, 'C3 לא פורסם דבר');
$cond = sf_mk_clean('<sf-cond rule="eretz"><sf-cond rule="chul"><p>x</p></sf-cond></sf-cond>', sf_mk_ctx(1));
check('C3 תנאים סותרים (אותה קבוצה) → חוסם פרסום', (bool)array_filter($cond['warnings'], fn($x) => $x['blocks'] && str_contains($x['msg'], 'סותרים')));
$cond = sf_mk_clean('<sf-cond rule="eretz"><sf-cond rule="minyan"><p>x</p></sf-cond></sf-cond>', sf_mk_ctx(1));
eq($cond['warnings'], [], 'C3 שני תנאים מקבוצות שונות — תקין');
$sty = sf_mk_clean('<sf-style v="pink">x</sf-style><sf-style v="bold">y</sf-style>');
check('C3 סגנון לא מהרשימה: הוסר, ⚠️ לא חוסמת', $sty['html'] === 'x<sf-style v="bold">y</sf-style>' && count($sty['warnings']) === 1 && !$sty['warnings'][0]['blocks'], $sty);

// מתקנים ומפרסמים: קישור לפסקה שקיימת, תנאי קיים
$fixed = str_replace(['zzzz9999', 'no-such'], [$sids2[1], 'eretz'], $r[1] ? $view()['draft']['html'] : '');
$r = $ok(call('POST', 'content-save', ['edition' => $E, 'html' => $fixed, 'base' => $v], $A), 'C3 תיקון');
$v = $r['draft']['v'];
eq(array_filter($r['warnings'], fn($x) => $x['blocks']), [], 'C3 אחרי התיקון — אין חוסמים');

// ── C6 / C7 לפני פרסום: טיוטה בלבד → לא מוחזרת לקריאה הציבורית ───
$ok(call('POST', 'content-title-public', ['title' => $t['pid'], 'on' => true], $A), 'C7 הדלקת "ציבורי" לסידור');
eq(call('GET', 'read-titles')[1]['titles'] ?? null, [], 'C6 כותר עם טיוטה בלבד — לא ברשימה הציבורית');
eq(code(call('GET', 'read-edition', [], null, ['edition' => $E])), 'not_found', 'C6 מהדורה עם טיוטה בלבד → not_found');
eq(code(call('GET', 'read-edition', [], $A, ['edition' => $E])), 'not_found', 'C6 גם מנהל לא מקבל טיוטה מפעולת קריאה ציבורית');

// ── C5: פרסום → גרסה חדשה; שחזור → טיוטה חדשה; שום דבר לא נמחק ─
$r = $ok(call('POST', 'content-publish', ['edition' => $E, 'base' => $v], $A), 'C5 פרסום');
eq($r['published']['status'], 'published', 'C5 הגרסה פורסמה');
$segs = sf_rows("SELECT sid, kind, parent_sid, text_norm FROM segments s JOIN revisions r ON r.id = s.revision_id WHERE r.status = 'published' ORDER BY ord");
eq(array_column($segs, 'sid'), array_values(array_unique(array_merge($sids2, array_column($segs, 'sid')))), 'C4 המקטעים נבנו עם אותם sid');
check('C4 כל המזהים של העריכה נמצאים בגרסה שפורסמה', !array_diff($sids2, array_column($segs, 'sid')));
eq($segs[0]['kind'] ?? null, 'section', 'segments: המקטע ראשון');
eq($segs[1]['parent_sid'] ?? null, $sids2[0], 'segments: parent_sid = המקטע');
eq(audit_count('content.publish'), 1, 'C12 content.publish ביומן');
$pub1 = $r['published']['pid'];

// C9: text_norm בלי ניקוד; source_html עם ניקוד
eq($segs[1]['text_norm'] ?? null, 'ברוך אתה', 'C9 text_norm של "בָּרוּךְ אַתָּה" = "ברוך אתה"');
eq(sf_norm('בָּרוּךְ'), 'ברוך', 'C9 sf_norm("בָּרוּךְ") = "ברוך"');
eq(sf_norm("בְּרֵאשִׁ֖ית  בָּרָ֣א"), 'בראשית ברא', 'C9 טעמים וניקוד יורדים, רווחים מתאחדים');
eq(sf_norm('על־פי'), 'על פי', 'C9 מקף עברי = רווח');
eq(sf_norm('מלכ ואמנ'), 'מלך ואמן', 'C9 אותיות סופיות מאוחדות');
check('C9 source_html שומר ניקוד וטעמים בתו', str_contains((string)sf_val("SELECT source_html FROM revisions WHERE status = 'published'"), 'בָּרוּךְ אַתָּה'));

// C6 / C7 אחרי פרסום — הגרסה שפורסמה, ורק היא
$pubView = $ok(call('GET', 'read-edition', [], null, ['edition' => $E]), 'C7 read-edition בלי זהות');
eq($pubView['revision']['n'], 1, 'C7 מוחזרת הגרסה שפורסמה');
check('C7 ה-toc כולל את המקטע', in_array($sids2[0], array_column($pubView['toc'], 'sid'), true));
$r = $ok(call('POST', 'content-save', ['edition' => $E, 'html' => $fixed . '<p>טיוטה סודית</p>', 'base' => 0, 'from' => 1], $A), 'טיוטה חדשה אחרי פרסום');
$v = $r['draft']['v'];
eq($r['draft']['n'], 2, 'טיוטה חדשה — גרסה 2');
eq($r['draft']['based_on_n'], 1, 'הטיוטה מבוססת על 1');
check('C6 read-edition לא מחזיר את הטיוטה', !str_contains(json_encode(call('GET', 'read-edition', [], $A, ['edition' => $E])[1], JSON_UNESCAPED_UNICODE), 'טיוטה סודית'));
check('C6 read-titles לא מחזיר את הטיוטה', !str_contains(json_encode(call('GET', 'read-titles')[1], JSON_UNESCAPED_UNICODE), 'טיוטה סודית'));
eq(count(call('GET', 'read-titles')[1]['titles']), 1, 'C7 הכותר הציבורי ברשימה');
foreach (SF_ACTIONS as $act => $spec) if ($spec['who'] === 'reader') check("C6 $act מתחיל ב-read-", str_starts_with($act, 'read-'));

// ── C8: שתי שמירות מקבילות → השנייה מקבלת התנגשות ──────────────
$base = $view()['draft']['v'];
$r = $ok(call('POST', 'content-save', ['edition' => $E, 'html' => $fixed . '<p>של ב</p>', 'base' => $base], $B), 'C8 ב שומר');
$r = call('POST', 'content-save', ['edition' => $E, 'html' => $fixed . '<p>של א</p>', 'base' => $base], $A);
eq([$r[0], code($r)], [409, 'conflict'], 'C8 א מקבל התנגשות');
check('C8 ההודעה אומרת מי שינה ומציעה לטעון', str_contains($r[1]['error']['message'], 'עורך') && str_contains($r[1]['error']['message'], 'לטעון'), $r[1]['error']);
$d = $view()['draft'];
check('C8 לא נדרס: התוכן של ב', str_contains($d['html'], 'של ב') && !str_contains($d['html'], 'של א'));
eq($d['updated_by'], 'עורך', 'C8 updated_by = ב');
eq(code(call('POST', 'content-publish', ['edition' => $E, 'base' => $base], $A)), 'conflict', 'C8 פרסום עם גרסה ישנה → התנגשות');
eq(code(call('POST', 'content-save', ['edition' => $E, 'html' => '<p>x</p>', 'base' => 0, 'from' => 1], $A)), 'conflict', 'C8 "טיוטה חדשה" כשכבר יש טיוטה → התנגשות');
$v = $d['v'];

// ── C5: פרסום שני, שחזור, ושום דבר לא נמחק ────────────────────
$ok(call('POST', 'content-publish', ['edition' => $E, 'base' => $v], $B), 'C5 פרסום שני');
eq(sf_rows('SELECT n, status FROM revisions ORDER BY n'), [['n' => 1, 'status' => 'superseded'], ['n' => 2, 'status' => 'published']], 'C5 הקודמת superseded');
$count = (int)sf_val('SELECT COUNT(*) FROM revisions');
$r = $ok(call('POST', 'content-restore', ['revision' => $pub1, 'base' => 0], $A), 'C5 שחזור גרסה 1');
eq([$r['draft']['n'], $r['draft']['status'], $r['draft']['based_on_n']], [3, 'draft', 1], 'C5 השחזור יצר טיוטה 3 מבוססת על 1');
eq($r['draft']['html'], sf_val('SELECT source_html FROM revisions WHERE pid = ?', [$pub1]), 'C5 התוכן של הטיוטה = התוכן של 1');
eq((int)sf_val('SELECT COUNT(*) FROM revisions'), $count + 1, 'C5 שחזור רק הוסיף');
eq(audit_count('content.restore'), 1, 'C12 content.restore ביומן');
$r = $ok(call('POST', 'content-restore', ['revision' => $pub1, 'base' => $r['draft']['v']], $A), 'C5 שחזור כשיש טיוטה פתוחה');
eq(sf_val('SELECT status FROM revisions WHERE n = 3'), 'discarded', 'C5 הטיוטה הקודמת נשמרה כ-discarded');
eq((int)sf_val("SELECT COUNT(*) FROM revisions WHERE status = 'draft'"), 1, 'C5 תמיד לכל היותר טיוטה אחת');
eq(code(call('POST', 'content-restore', ['revision' => $pub1, 'base' => 0], $A)), 'conflict', 'C5 שחזור עם base ישן → התנגשות');
$ok(call('POST', 'content-discard', ['edition' => $E, 'base' => $r['draft']['v']], $A), 'ביטול טיוטה');
eq((int)sf_val("SELECT COUNT(*) FROM revisions WHERE status = 'draft'"), 0, 'ביטול: אין טיוטה פתוחה');
eq((int)sf_val('SELECT COUNT(*) FROM revisions'), $count + 2, 'C5 ביטול לא מחק');
$thrown = fn(callable $f) => (function () use ($f) { try { $f(); return false; } catch (PDOException) { return true; } })();
check('C5 המסד חוסם מחיקת גרסה', $thrown(fn() => sf_exec('DELETE FROM revisions WHERE n = 1')));
check('C5 המסד חוסם שינוי תוכן של גרסה שפורסמה', $thrown(fn() => sf_exec("UPDATE revisions SET source_html = 'x' WHERE status = 'published'")));
eq((int)sf_val('SELECT COUNT(*) FROM revisions'), $count + 2, 'C5 אף גרסה לא נמחקה');
$hist = $view()['revisions'];
eq(array_column($hist, 'status', 'n'), [4 => 'discarded', 3 => 'discarded', 2 => 'published', 1 => 'superseded'], 'ההיסטוריה המלאה במסך העורך');

// ── C7: כבוי / future_paid / ספר — לא מוחזרים ─────────────────
$ok(call('POST', 'content-title-public', ['title' => $t['pid'], 'on' => false], $A), 'C7 כיבוי');
eq(call('GET', 'read-titles')[1]['titles'], [], 'C7 public_allowed = 0 → לא ברשימה');
eq(code(call('GET', 'read-edition', [], null, ['edition' => $E])), 'not_found', 'C7 public_allowed = 0 → read-edition not_found');
eq(audit_count('title.public_on'), 1, 'C12 title.public_on ביומן');
eq(audit_count('title.public_off'), 1, 'C12 title.public_off ביומן');
$ok(call('POST', 'content-title-save', ['title' => $t['pid'], 'name' => $t['name'], 'kind' => 'siddur', 'access' => 'future_paid'], $A), 'C7 future_paid');
eq(code(call('POST', 'content-title-public', ['title' => $t['pid'], 'on' => true], $A)), 'state', 'C7 future_paid לא נפתח לציבור');
sf_exec('UPDATE titles SET public_allowed = 1 WHERE pid = ?', [$t['pid']]);   // גם אם הדגל דולק בטעות
eq(call('GET', 'read-titles')[1]['titles'], [], 'C7 future_paid לא מוחזר גם כשהדגל דולק');
eq(code(call('GET', 'read-edition', [], null, ['edition' => $E])), 'not_found', 'C7 future_paid → read-edition not_found');
sf_exec('UPDATE titles SET public_allowed = 0 WHERE pid = ?', [$t['pid']]);
$book = $ok(call('POST', 'content-title-save', ['name' => 'ספר אחר', 'kind' => 'book'], $A), 'כותר מסוג ספר')['title'];
$r = call('POST', 'content-title-public', ['title' => $book['pid'], 'on' => true], $A);
check("C7 ספר לא נפתח לציבור בשלב א'", code($r) === 'state' && str_contains($r[1]['error']['message'], 'סידור ותהילים'));
eq(audit_count('title.public_on'), 1, 'ניסיון שנכשל לא נרשם');

// ── הזרקה: הניקוי מוציא רק את מה שמותר ────────────────────────
$evil = [
    '<img src=x onerror=alert(1)>',
    '<img src="files/a.png" onerror="alert(1)">',
    '<svg><script>alert(1)</script></svg>',
    '<svg onload=alert(1)></svg>',
    '<a href="javascript:alert(1)">x</a>',
    '<p style="background:url(javascript:alert(1))">x</p>',
    '<sf-link to="javascript:alert(1)">x</sf-link>',
    '<sf-style v="bold&quot; onmouseover=&quot;alert(1)">x</sf-style>',
    '<sf-section title="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;">x</sf-section>',
    '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
    '<!--<script>alert(1)</script>--><p>x</p>',
    '<p><b>x</b><object data="x.swf"></object><embed src="x"></embed></p>',
    '<math><mi xlink:href="javascript:alert(1)">x</mi></math>',
    '<p sid="aaaa2222" onfocus="alert(1)" autofocus tabindex="1" id="x" class="y" data-x="1">x</p>',
    '<img src="files/../../data/sforno.sqlite.png"><img src="files//evil.test/a.png"><img src="data:image/png;base64,AAAA">',
    '<form action="https://evil.test"><input name="q"><button formaction="javascript:alert(1)">x</button></form>',
    '<table><tr><td background="javascript:alert(1)" colspan="2">x</td></tr></table>',
    '<p dir="rtl" lang="he">ok</p><p dir="javascript:" lang="&lt;x">bad</p>',
    "<p>\u{0000}null\u{0007}bell</p>",
    '<base href="https://evil.test/"><meta http-equiv="refresh" content="0;url=https://evil.test"><link rel="import" href="x">',
    '<p><sf-note kind="commentary" onclick="x">פירוש</sf-note></p>',
    '<sf-audio sid="bbbb3333" src="https://evil.test/a.mp3"></sf-audio>',
];
foreach ($evil as $i => $in) {
    $c = sf_mk_clean($in, sf_mk_ctx(1));
    if ($c['html'] === null) { check("הזרקה #$i נדחתה במבנה", $c['errors'] !== []); continue; }
    $h = $c['html'];
    $bad = preg_match('/\son[a-z]+\s*=|<script|<svg|<math|<iframe|<object|<embed|<form|<input|<button|<base|<meta|<link|<a[\s>]|javascript:|style=|evil\.test|data:|\.\.\/|\bid=|\bclass=|\btabindex|\bautofocus|\bdata-x|xlink|background=|[\x00-\x08]/i', $h);
    check("הזרקה #$i: בפלט רק מה שמותר", !$bad, ['in' => $in, 'out' => $h]);
    eq(sf_mk_clean($h, sf_mk_ctx(1))['html'], $h, "הזרקה #$i: ניקוי שני לא משנה (אידמפוטנטי)");
    // כל רכיב ותכונה בפלט — מהרשימה
    $root = sf_mk_parse($h);
    foreach ((new DOMXPath($root->ownerDocument))->query('.//*', $root) as $el) {
        $tag = $el->localName;
        check("הזרקה #$i: ‹{$tag}› ברשימה", isset(SF_MK_TAGS[$tag]));
        foreach ($el->attributes as $a) check("הזרקה #$i: $tag@{$a->nodeName} ברשימה", in_array($a->nodeName, array_merge(SF_MK_TAGS[$tag] ?? [], ['dir', 'lang']), true));
    }
}
$c = sf_mk_clean('<sf-section title="&quot;&gt;&lt;script&gt;">x</sf-section>');
// הסריאליזציה של libxml משתנה בין גרסאות (מרכאות בודדות מול &quot;) — בודקים את התכונה הבטיחותית, לא את המחרוזת.
$cd = new DOMDocument();
@$cd->loadHTML('<?xml encoding="utf-8"?><div>' . (string)$c['html'] . '</div>', LIBXML_NONET);
$cs = $cd->getElementsByTagName('sf-section')->item(0);
check('תכונה עם מרכאות נשמרת כטקסט, מקודדת',
    $cs !== null && $cs->getAttribute('title') === '"><script>' && !str_contains((string)$c['html'], '<script')
    && $cd->getElementsByTagName('script')->length === 0, $c['html']);
$c = sf_mk_clean('<p dir="rtl" lang="he">ok</p>');
check('dir ו-lang תקינים נשמרים', str_contains((string)$c['html'], 'dir="rtl" lang="he"'), $c['html']);
$c = sf_mk_clean('<img src="files/pic.png" alt="תמונה">');
check('תמונה מקובצי הספר נשמרת', str_contains((string)$c['html'], '<img src="files/pic.png" alt="תמונה">'), $c['html']);

// ── מילון החיפוש ─────────────────────────────────────────────
$r = $ok(call('POST', 'content-alias-add', ['term' => 'מִצְוָה', 'alias' => 'מצווה'], $A), 'מילון: הוספה');
eq($r['aliases'][0]['term'] ?? null, 'מצוה', 'מילון: נשמר מנורמל');
eq(code(call('POST', 'content-alias-add', ['term' => 'מצוה', 'alias' => 'מצווה'], $A)), 'state', 'מילון: צמד כפול');
$ok(call('POST', 'content-alias-remove', ['term' => 'מצוה', 'alias' => 'מצווה'], $A), 'מילון: הסרה');
eq(sf_aliases()['aliases'], [], 'מילון: ריק');

// גודל
eq(code(call('POST', 'content-check', ['edition' => $E, 'html' => str_repeat('א', SF_CONTENT_MAX)], $A)), 'too_large', 'תוכן גדול מדי → too_large');
eq(SF_ACTIONS['content-save']['max'] ?? 0, 8_000_000, 'api.php מקבל גוף גדול רק לפעולות התוכן');

done();
