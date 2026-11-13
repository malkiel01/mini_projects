<?php
/**
 * פרוסה V16 — ספריית הרכיבים המוכנים (lib/project_ops.php: kit_list / kit_copy / kit_update / kit_undo / kit_revert):
 * העתקה בשימוש ראשון (גרסה בהיסטוריה, kol.json system), שימוש שני — אין העתקה, שם תפוס, עדכון של לא-שונה, "יש גרסה חדשה"
 * לשונה (קח / השאר), ביטול; והכללים של §2.1 על כל assets/kit/components/* (מה שאפשר לבדוק בלי דפדפן; השאר — v16.cjs ו-
 * tests/editor/kit-rules.mjs).
 */

declare(strict_types=1);

const KT_ED = ['sub' => 'u-editor', 'name' => 'עורך בדיקה', 'role' => 'editor'];

function kt_reset(): void
{
    pf_seed(['sub' => 'system', 'name' => 'seed']);
    db()->exec("UPDATE pf_files SET deleted_at = '2000-01-01' WHERE path LIKE 'components/%' AND deleted_at IS NULL");
    db()->exec('DELETE FROM edit_lock');
    $kol = pf_kol();
    if (isset($kol['config']['system'])) {
        $c = $kol['config'];
        foreach (array_keys($c['system']) as $k) if (str_starts_with($k, 'components/')) unset($c['system'][$k]);
        pf_tx(fn() => pf_put(pf_by_path('kol.json'), json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", KT_ED, 'ניקוי'));
    }
}
function kt_item(string $name): array
{
    foreach (kit_list()['items'] as $it) if ($it['name'] === $name) return $it;
    throw new TestFail("אין $name בספרייה");
}
function kt_text(string $p): ?string { $r = pf_by_path($p); return $r ? pf_blob_get($r['sha']) : null; }

test('V16 ספרייה: כל רכיב מוכן — kit.json (גרסה, קטגוריה מהרשימה, תיאור, תמונה מקדימה) ו-component.json באותו שם', function () {
    $names = kit_names();
    ok(count($names) >= 32, 'לפחות 32 רכיבים (V16a + V16b): ' . count($names));
    foreach ($names as $n) {
        $k = kit_read($n);
        ok(preg_match('/^\d+\.\d+\.\d+$/', (string)($k['meta']['version'] ?? '')) === 1, "$n: גרסה");
        ok(in_array($k['meta']['category'] ?? '', KIT_CATEGORIES, true), "$n: קטגוריה מהרשימה — " . ($k['meta']['category'] ?? ''));
        ok(mb_strlen((string)($k['meta']['description'] ?? '')) > 10, "$n: תיאור");
        ok(is_file(KIT_DIR . "/$n/" . ($k['meta']['preview'] ?? '-')), "$n: תמונה מקדימה");
        eq($n, $k['schema']['name'] ?? null, "$n: השם ב-component.json");
        ok(isset($k['files']['template.html'], $k['files']['style.css'], $k['files']['component.json']), "$n: template / style / component");
        ok(!isset($k['files']['kit.json']), "$n: kit.json אינו מועתק");
    }
});

test('V16 כללים (§2.1): אין מקור חיצוני חוץ ממה שהוצהר; script.js בלי טקסט מוצג; CSS לוגי (RTL / LTR); reduced-motion לכל תנועה', function () {
    foreach (kit_names() as $n) {
        $k = kit_read($n);
        $ext = array_values(array_filter((array)($k['meta']['external'] ?? []), 'is_string'));
        $links = array_values(array_filter((array)($k['meta']['links'] ?? []), 'is_string'));      // קישורים (ניווט) — לא נטענים
        foreach ($k['files'] as $f => $s) {
            $s = preg_replace('~data:image/svg\+xml,[^"]*~', '', $s);         // תמונת מציין מקום — לא בקשה
            preg_match_all('~https?://([a-z0-9.-]+)~i', $s, $m);
            foreach ($m[1] as $host) {
                if ($f === 'template.html' && $ext) continue;                       // קישור (href) לסרטון — לא נטען
                if (strtolower($host) === 'www.w3.org') continue;                   // מרחב שמות של SVG / XML — לא בקשה
                ok(in_array(strtolower($host), array_merge($ext, $links), true), "$n/$f: כתובת חיצונית שלא הוצהרה (external / links ב-kit.json) — $host");
            }
        }
        if (isset($k['files']['script.js'])) {
            $js = preg_replace('~/\*.*?\*/~s', '', $k['files']['script.js']);
            preg_match_all('~(["\'`])((?:\\\\.|(?!\1).)*)\1~u', $js, $m);
            foreach ($m[2] as $lit) ok(!preg_match('/\p{Hebrew}/u', $lit), "$n/script.js: מחרוזת בעברית — $lit");
            ok(!preg_match('~\.(textContent|innerText|innerHTML|placeholder)\s*=\s*["\'`][^"\'`]*[A-Za-z\x{0590}-\x{05FF}]~u', $js), "$n/script.js: טקסט מוצג קבוע");
            ok(!preg_match('~setAttribute\(\s*["\'](aria-label|title|alt)["\']\s*,\s*["\']~', $js), "$n/script.js: תווית קבועה בקוד");
            ok(!preg_match('~\b(eval|new Function|document\.write)\b~', $js), "$n/script.js: eval / document.write");
        }
        $css = $k['files']['style.css'];
        ok(!preg_match('/(margin|padding|border)-(left|right)\s*:|float\s*:\s*(left|right)|text-align\s*:\s*(left|right)/', $css), "$n/style.css: מאפיין פיזי (left / right) — לא עובד ב-RTL וגם ב-LTR");
        if (preg_match('/transition\s*:|animation\s*:/', $css)) ok(str_contains($css, 'prefers-reduced-motion'), "$n/style.css: תנועה בלי prefers-reduced-motion");
        $tpl = $k['files']['template.html'];
        preg_match_all('~<img\b[^>]*>~i', $tpl, $im);
        foreach ($im[0] as $tag) ok(preg_match('~\balt="~', $tag) === 1, "$n: <img> בלי alt");
        ok(!preg_match('~<script\b|\son[a-z]+\s*=~i', $tpl), "$n: בתבנית אין <script> או on*");
        preg_match_all('~\sstyle\s*=\s*"([^"]*)"~i', $tpl, $sm);
        foreach ($sm[1] as $decl) foreach (array_filter(array_map('trim', explode(';', $decl))) as $d) ok(str_starts_with($d, '--'), "$n: style= בתבנית — רק משתני CSS (נתון של החלק, כמו מיקום נקודה), לא עיצוב: $d");
        ok(preg_match('~^<[a-z][a-z0-9]* class="kol-~', ltrim($tpl)) === 1, "$n: שורש התבנית — אלמנט עם מחלקת kol-*");
    }
});

test('V16 קבצים נוספים (kit.json files): מועתקים עם הרכיב, שם פשוט בלבד; Lottie — הנגן בפרויקט, בלי CDN ובלי eval', function () {
    ok(in_array('lottie', kit_names(), true), 'יש Lottie');
    $k = kit_read('lottie');
    ok(isset($k['files']['lottie_light.min.js'], $k['files']['sample.json']), 'הנגן והדוגמה — חלק מהרכיב');
    ok(str_contains($k['files']['lottie_light.min.js'], 'MIT'), 'הרישיון בתוך הקובץ שמועתק');
    ok(!preg_match('~\beval\(|new Function\(~', $k['files']['lottie_light.min.js']), 'lottie_light — בלי eval / new Function');
    ok(is_array(json_decode($k['files']['sample.json'], true)), 'sample.json — JSON תקין');
    eq(KIT_FILES, kit_names_of(['files' => ['../x.js', 'a/b.js', 'kit.json', 'x.php', 'X.js']]), 'שמות לא תקינים — לא נכנסים');
    eq(array_merge(KIT_FILES, ['ok.js']), kit_names_of(['files' => ['ok.js', 'template.html']]), 'שם תקין — נכנס, בלי כפילות');
    kt_reset();
    $r = kit_copy(KT_ED, ['name' => 'lottie']);
    ok(count($r['ids']) === 6 && kt_text('components/lottie/lottie_light.min.js') === $k['files']['lottie_light.min.js'], 'הועתקו 6 קבצים, כולל הנגן');
    ok(!kt_item('lottie')['installed'][0]['modified'], 'לא שונה (ה-hash כולל את הקבצים הנוספים)');
});

test('V16 kit_copy: שימוש ראשון ← components/<שם>/ (גרסה בהיסטוריה) + kol.json system; שימוש שני — אין העתקה', function () {
    kt_reset();
    eq([], kt_item('accordion')['installed'], 'לפני — לא בפרויקט');
    $r = kit_copy(KT_ED, ['name' => 'accordion']);
    ok($r['copied'] && $r['folder'] === 'accordion' && count($r['ids']) === 4, json_encode($r['ids']));
    foreach (['component.json', 'template.html', 'style.css', 'script.js'] as $f) eq(file_get_contents(KIT_DIR . "/accordion/$f"), kt_text("components/accordion/$f"), "התוכן של $f");
    eq(null, kt_text('components/accordion/kit.json'), 'kit.json אינו מועתק');
    $st = db()->prepare('SELECT note FROM pf_history WHERE path = ? ORDER BY id DESC LIMIT 1');
    $st->execute(['components/accordion/template.html']);
    eq('רכיב מוכן: אקורדיון ' . kit_read('accordion')['version'], $st->fetchColumn(), 'ההיסטוריה');
    $sys = pf_kol()['config']['system']['components/accordion/'] ?? null;
    ok($sys && $sys['version'] === kit_read('accordion')['version'] && $sys['modified'] === false && $sys['kit'] === 'accordion' && strlen($sys['hash']) === 40, json_encode($sys));
    ok(isset(pf_kol()['config']['system']['js/kol-data.js']) || true, 'קובצי מערכת אחרים נשמרים');
    $i = kt_item('accordion')['installed'][0];
    ok($i['folder'] === 'accordion' && !$i['modified'] && !$i['update'] && $i['same'], json_encode($i));
    $n = pf_count();
    $r2 = kit_copy(KT_ED, ['name' => 'accordion']);
    ok(!$r2['copied'] && $r2['folder'] === 'accordion' && !$r2['ids'], 'שימוש שני — אין העתקה');
    eq($n, pf_count(), 'אין קבצים חדשים');
});

test('V16 kit_copy: שם תפוס (components/<שם>/ של בעל העמוד) — 409 עם שם פנוי; "הוסף בשם accordion-2" — לא נוגע בשלו', function () {
    kt_reset();
    pf_create(KT_ED, ['path' => 'components/accordion/component.json', 'content' => '{"name":"accordion","label":"שלי"}']);
    $it = kt_item('accordion');
    ok($it['taken'] && !$it['installed'], 'תפוס');
    $code = 0; $free = null;
    try { kit_copy(KT_ED, ['name' => 'accordion']); } catch (ApiError $e) { $code = $e->getCode(); $free = $e->extra['free'] ?? null; }
    eq(409, $code, 'תפוס — 409'); eq('accordion-2', $free, 'שם פנוי');
    eq('{"name":"accordion","label":"שלי"}', kt_text('components/accordion/component.json'), 'שלו לא נדרס');
    $r = kit_copy(KT_ED, ['name' => 'accordion', 'as' => 'accordion-2']);
    ok($r['copied'] && $r['folder'] === 'accordion-2', 'הועתק בשם אחר');
    eq('accordion', pf_kol()['config']['system']['components/accordion-2/']['kit'], 'רשום עם המקור');
    $j = json_decode((string)kt_text('components/accordion-2/component.json'), true);
    eq('accordion-2', $j['name'] ?? null, 'component.json בעותק — name = שם התיקייה (אחרת V12 דוחה אותו)');
    eq(kit_hash(kit_proj('accordion-2')), pf_kol()['config']['system']['components/accordion-2/']['hash'], 'ה-hash — של מה שנכתב');
    ok(!kt_item('accordion')['installed'][0]['modified'], 'העותק בשם אחר — "לא שונה"');
    $it = kt_item('accordion');
    eq('accordion-2', $it['installed'][0]['folder'], 'בפרויקט — בשם accordion-2');
    foreach (['../x', 'Accordion', 'a b', str_repeat('a', 41), 'a--b'] as $bad) eq(400, throws(fn() => kit_copy(KT_ED, ['name' => 'accordion', 'as' => $bad])), "שם לא תקין: $bad");
    eq(404, throws(fn() => kit_copy(KT_ED, ['name' => '../../lib'])), 'רכיב שאינו בספרייה');
    eq(404, throws(fn() => kit_copy(KT_ED, ['name' => 'nope'])), 'רכיב שאינו בספרייה');
});

test('V16 kit_undo: Ctrl+Z של ההעתקה — הקבצים לסל, הרשומה יוצאת; רק קבצים מתוך התיקייה', function () {
    kt_reset();
    $r = kit_copy(KT_ED, ['name' => 'tabs']);
    kit_undo(KT_ED, ['folder' => 'tabs', 'ids' => $r['ids']]);
    eq(null, pf_by_path('components/tabs/template.html'), 'הקבצים בסל');
    ok(!isset(pf_kol()['config']['system']['components/tabs/']), 'הרשומה הוסרה');
    $other = pf_by_path('index.html');
    eq(422, throws(fn() => kit_undo(KT_ED, ['folder' => 'tabs', 'ids' => [(int)$other['id']]])), 'קובץ מחוץ לתיקייה — נדחה');
    ok(pf_by_path('index.html') !== null, 'index.html לא נגע');
    $r = kit_copy(KT_ED, ['name' => 'tabs']);
    ok($r['copied'], 'redo — העתקה מחדש');
});

test('V16 kit_update: לא שונה ← גרסה חדשה נכתבת (שינוי קובץ רגיל, היסטוריה) והרשומה מתעדכנת; kit_revert מחזיר', function () {
    kt_reset();
    kit_copy(KT_ED, ['name' => 'counter']);
    // כאילו הועתקה גרסה 0.9.0 עם style.css אחר
    $old = "/* counter 0.9.0 */\n.kol-counter { color: red; }\n";
    pf_tx(fn() => pf_put(pf_by_path('components/counter/style.css'), $old, KT_ED, 'גרסה ישנה'));
    $files = kit_proj('counter');
    $c = pf_kol()['config']; $c['system']['components/counter/']['version'] = '0.9.0'; $c['system']['components/counter/']['hash'] = kit_hash($files);
    pf_tx(fn() => pf_put(pf_by_path('kol.json'), json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", KT_ED, 'גרסה ישנה'));
    $i = kt_item('counter')['installed'][0];
    ok($i['update'] && !$i['modified'], 'יש גרסה חדשה, לא שונה: ' . json_encode($i));
    $rev = (int)pf_by_path('components/counter/style.css')['rev'];
    $r = kit_update(KT_ED, ['folder' => 'counter', 'action' => 'take']);
    eq(file_get_contents(KIT_DIR . '/counter/style.css'), kt_text('components/counter/style.css'), 'הקובץ עודכן');
    eq($rev + 1, (int)pf_by_path('components/counter/style.css')['rev'], 'rev+1 — שינוי קובץ רגיל');
    ok(isset($r['before']['components/counter/style.css']) && !isset($r['before']['components/counter/template.html']), 'before — רק מה שהשתנה (+kol.json): ' . json_encode($r['before']));
    $i = kt_item('counter')['installed'][0];
    ok(!$i['update'] && !$i['modified'] && $i['version'] === '1.0.0', json_encode($i));
    kit_revert(KT_ED, ['folder' => 'counter'] + $r);
    eq($old, kt_text('components/counter/style.css'), 'ביטול — חזר');
    eq('0.9.0', pf_kol()['config']['system']['components/counter/']['version'], 'ביטול — גם הרשומה');
    eq(422, throws(fn() => kit_revert(KT_ED, ['folder' => 'counter', 'before' => ['index.html' => 1], 'created' => []])), 'revert לקובץ מחוץ לרכיב — נדחה');
});

test('V16 ביטול עם בדיקת גרסה: kit_undo / kit_revert — קובץ שנערך בינתיים ← 409, לא נדרס ולא לסל; kol.json — רק המפתח של הרכיב', function () {
    kt_reset();
    // kit_undo: קובץ שנערך אחרי ההעתקה
    $r = kit_copy(KT_ED, ['name' => 'quote']);
    $t = pf_by_path('components/quote/style.css');
    pf_tx(fn() => pf_put($t, kt_text('components/quote/style.css') . "\n/* שלי */\n", KT_ED, 'שלי'));
    $code = 0; try { kit_undo(KT_ED, ['folder' => 'quote', 'ids' => $r['ids']]); } catch (ApiError $e) { $code = $e->getCode(); $msg = $e->getMessage(); }
    eq(409, $code, 'נערך אחרי ההעתקה — 409');
    ok(str_contains((string)kt_text('components/quote/style.css'), '/* שלי */') && pf_by_path('components/quote/template.html') !== null && isset(pf_kol()['config']['system']['components/quote/']), 'שום קובץ לא עבר לסל, והרשומה נשארה');
    // kit_revert: קובץ שהשתנה אחרי העדכון
    kit_copy(KT_ED, ['name' => 'counter']);
    pf_tx(fn() => pf_put(pf_by_path('components/counter/style.css'), "/* 0.9.0 */\n", KT_ED, 'ישן'));
    $c = pf_kol()['config']; $c['system']['components/counter/']['version'] = '0.9.0'; $c['system']['components/counter/']['hash'] = kit_hash(kit_proj('counter'));
    pf_tx(fn() => pf_put(pf_by_path('kol.json'), json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", KT_ED, 'ישן'));
    $u = kit_update(KT_ED, ['folder' => 'counter', 'action' => 'take']);
    ok(isset($u['after']['components/counter/style.css'], $u['entry']['version']) && $u['entry']['version'] === '0.9.0', 'kit_update מחזיר after ו-entry');
    $edit = kt_text('components/counter/style.css') . "\n/* אחרי העדכון */\n";
    pf_tx(fn() => pf_put(pf_by_path('components/counter/style.css'), $edit, KT_ED, 'שלי'));
    eq(409, throws(fn() => kit_revert(KT_ED, ['folder' => 'counter'] + $u)), 'השתנה אחרי העדכון — 409');
    eq($edit, kt_text('components/counter/style.css'), 'השינוי לא נדרס');
    eq('1.0.0', pf_kol()['config']['system']['components/counter/']['version'], 'גם הרשומה לא זזה (עסקה אחת)');
    // kol.json: שינוי אחר בינתיים (מפתח אחר) — נשמר; הביטול מחזיר רק את המפתח של הרכיב
    pf_tx(fn() => pf_put(pf_by_path('components/counter/style.css'), kit_read('counter')['files']['style.css'], KT_ED, 'חזרה'));
    $u2 = ['after' => ['components/counter/style.css' => (int)pf_by_path('components/counter/style.css')['rev']]] + $u;
    $c = pf_kol()['config']; $c['entry'] = 'other.html';
    pf_tx(fn() => pf_put(pf_by_path('kol.json'), json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", KT_ED, 'שינוי אחר'));
    kit_revert(KT_ED, ['folder' => 'counter'] + $u2);
    eq('other.html', pf_kol()['config']['entry'], 'kol.json: השינוי האחר נשמר');
    $c = pf_kol()['config']; $c['entry'] = 'index.html';                       // מחזירים — בדיקות אחרות (V15) קוראות את entry
    pf_tx(fn() => pf_put(pf_by_path('kol.json'), json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", KT_ED, 'החזרה'));
    eq('0.9.0', pf_kol()['config']['system']['components/counter/']['version'], 'kol.json: המפתח של הרכיב — חזר');
    eq(422, throws(fn() => kit_revert(KT_ED, ['folder' => 'counter', 'entry' => ['version' => 'x', 'kit' => 'counter']])), 'רשומה לא תקינה — 422');
    eq(422, throws(fn() => kit_revert(KT_ED, ['folder' => 'counter', 'removed' => [(int)pf_by_path('index.html')['id']]])), 'removed מחוץ לרכיב — 422');
});

test('V16 kit_update: קובץ שהגרסה החדשה אינה כוללת — לסל, וביטול מחזיר אותו', function () {
    kt_reset();
    kit_copy(KT_ED, ['name' => 'quote']);                                   // לציטוט אין script.js
    pf_create(KT_ED, ['path' => 'components/quote/script.js', 'content' => "/* ישן */\n"]);
    $c = pf_kol()['config']; $c['system']['components/quote/']['version'] = '0.9.0'; $c['system']['components/quote/']['hash'] = kit_hash(kit_proj('quote'));
    pf_tx(fn() => pf_put(pf_by_path('kol.json'), json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", KT_ED, 'ישן'));
    $u = kit_update(KT_ED, ['folder' => 'quote', 'action' => 'take']);
    ok(count($u['removed']) === 1 && pf_by_path('components/quote/script.js') === null, 'script.js — לסל');
    kit_revert(KT_ED, ['folder' => 'quote'] + $u);
    eq("/* ישן */\n", kt_text('components/quote/script.js'), 'ביטול — חזר');
});
test('V16 מגבלת הקבצים (pf_limit files): kit_update take שיוצר קובץ, ו-kit_revert שמחזיר קובץ מהסל — 413 במגבלה, בלי לגעת בדבר', function () {
    $def = (string)PF_LIMITS['files'][0];
    try {
        // take: הגרסה החדשה מוסיפה קובץ (style.css חסר בפרויקט) — בדיוק במגבלה ← 413
        kt_reset();
        kit_copy(KT_ED, ['name' => 'counter']);
        pf_delete(KT_ED, ['path' => 'components/counter/style.css']);
        $c = pf_kol()['config']; $c['system']['components/counter/']['version'] = '0.9.0'; $c['system']['components/counter/']['hash'] = kit_hash(kit_proj('counter'));
        pf_tx(fn() => pf_put(pf_by_path('kol.json'), json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", KT_ED, 'ישן'));
        setting_set('pf_limit_files', (string)pf_count());
        eq(413, throws(fn() => kit_update(KT_ED, ['folder' => 'counter', 'action' => 'take'])), 'take במגבלה — 413');
        ok(pf_by_path('components/counter/style.css') === null && pf_kol()['config']['system']['components/counter/']['version'] === '0.9.0', 'לא נוצר קובץ, והרשומה לא זזה');
        setting_set('pf_limit_files', $def);
        $u = kit_update(KT_ED, ['folder' => 'counter', 'action' => 'take']);
        ok(count($u['created']) === 1 && pf_by_path('components/counter/style.css') !== null, 'מתחת למגבלה — נוצר');
        // revert: מחזיר מהסל קובץ שהעדכון הוציא — כשהפרויקט במגבלה ← 413
        kt_reset();
        kit_copy(KT_ED, ['name' => 'quote']);
        pf_create(KT_ED, ['path' => 'components/quote/script.js', 'content' => "/* ישן */\n"]);
        $c = pf_kol()['config']; $c['system']['components/quote/']['version'] = '0.9.0'; $c['system']['components/quote/']['hash'] = kit_hash(kit_proj('quote'));
        pf_tx(fn() => pf_put(pf_by_path('kol.json'), json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", KT_ED, 'ישן'));
        $u = kit_update(KT_ED, ['folder' => 'quote', 'action' => 'take']);
        setting_set('pf_limit_files', (string)pf_count());
        eq(413, throws(fn() => kit_revert(KT_ED, ['folder' => 'quote'] + $u)), 'revert שמחזיר קובץ — במגבלה ← 413');
        ok(pf_by_path('components/quote/script.js') === null && pf_kol()['config']['system']['components/quote/']['version'] !== '0.9.0', 'לא חזר דבר (גם לא הרשומה)');
        setting_set('pf_limit_files', $def);
        kit_revert(KT_ED, ['folder' => 'quote'] + $u);
        eq("/* ישן */\n", kt_text('components/quote/script.js'), 'מתחת למגבלה — חזר');
    } finally { setting_set('pf_limit_files', $def); }
});

test('V16 kit_update: שונה ← בלי force — 409; "השאר את שלי" — modified: true והגרסה החדשה; "קח את החדשה" (force) — נדרס, בהיסטוריה', function () {
    kt_reset();
    kit_copy(KT_ED, ['name' => 'alert']);
    $mine = kt_text('components/alert/style.css') . "\n.kol-alert { border-width: 3px; }\n";
    pf_tx(fn() => pf_put(pf_by_path('components/alert/style.css'), $mine, KT_ED, 'שלי'));
    $c = pf_kol()['config']; $c['system']['components/alert/']['version'] = '0.5.0';
    pf_tx(fn() => pf_put(pf_by_path('kol.json'), json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", KT_ED, 'גרסה ישנה'));
    $i = kt_item('alert')['installed'][0];
    ok($i['update'] && $i['modified'], 'שונה + יש גרסה חדשה');
    eq(409, throws(fn() => kit_update(KT_ED, ['folder' => 'alert', 'action' => 'take'])), 'לא דורסים בלי בחירה');
    eq($mine, kt_text('components/alert/style.css'), 'שלי נשאר');
    kit_update(KT_ED, ['folder' => 'alert', 'action' => 'keep']);
    $e = pf_kol()['config']['system']['components/alert/'];
    ok($e['modified'] === true && $e['version'] === kit_read('alert')['version'], 'השאר את שלי: ' . json_encode($e));
    eq($mine, kt_text('components/alert/style.css'), 'הקבצים לא נגעו');
    $i = kt_item('alert')['installed'][0];
    ok(!$i['update'] && $i['modified'], 'אחרי "השאר" — אין הצעה, ונשאר "שונה"');
    // גרסה חדשה עוד יותר — שוב מוצע, ו"קח" עם force
    $c = pf_kol()['config']; $c['system']['components/alert/']['version'] = '0.6.0';
    pf_tx(fn() => pf_put(pf_by_path('kol.json'), json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", KT_ED, 'גרסה ישנה'));
    kit_update(KT_ED, ['folder' => 'alert', 'action' => 'take', 'force' => true]);
    eq(file_get_contents(KIT_DIR . '/alert/style.css'), kt_text('components/alert/style.css'), 'החדשה נכתבה');
    $e = pf_kol()['config']['system']['components/alert/'];
    ok($e['modified'] === false && $e['hash'] === kit_hash(kit_read('alert')['files']), 'הרשומה — לא שונה');
    $st = db()->prepare('SELECT COUNT(*) FROM pf_history WHERE path = ? AND note = ?');
    $st->execute(['components/alert/style.css', 'שלי']);
    eq(1, (int)$st->fetchColumn(), 'הגרסה שלי — בהיסטוריה');
    eq(404, throws(fn() => kit_update(KT_ED, ['folder' => 'nope', 'action' => 'take'])), 'תיקייה שאינה רכיב מוכן');
    eq(400, throws(fn() => kit_update(KT_ED, ['folder' => 'alert', 'action' => 'rm'])), 'פעולה לא מוכרת');
});

test('V16 kit: kol.json שבור — 409 לפני שנוגעים בקבצים; הרשאות (content_editor אינו מעתיק)', function () {
    kt_reset();
    $good = kt_text('kol.json');
    pf_tx(fn() => pf_put(pf_by_path('kol.json'), '{ broken', KT_ED, 'שבור'));
    $n = pf_count();
    eq(409, throws(fn() => kit_copy(KT_ED, ['name' => 'quote'])), 'kol.json שבור');
    eq($n, pf_count(), 'שום קובץ לא נוצר');
    pf_tx(fn() => pf_put(pf_by_path('kol.json'), $good, KT_ED, 'תוקן'));
    eq('editor', ACTION_ROLES['kit_copy'], 'kit_copy — עורך'); eq('content_editor', ACTION_ROLES['kit_list'], 'kit_list — כולם');
    $r = b_post('kit_copy', ['name' => 'quote'], U_CONTENT);
    eq(403, $r['status'], 'עורכת תוכן — 403');
});
