<?php
/**
 * פרוסה V16c — הטופס המוכן ו-webhook (lib/forms.php): הרישום נגזר מה-HTML של הרכיב (שדה חדש ← נשלח), webhook — כתובת https
 * בלבד, פרטית / IP / פנימית — נדחית (גם כשה-DNS מחזיר כתובת פנימית), נשלח (JSON) אחרי השמירה בתיבה, ניסיון חוזר, ובכישלון —
 * ביומן, והפנייה נשמרת. בלי רשת: $GLOBALS['kol_hook_fake'] / ['kol_hook_resolve'].
 */

declare(strict_types=1);

const KF_PUB = ['sub' => 'u-publisher', 'name' => 'מפרסם בדיקה', 'role' => 'publisher'];
const KF_ED = ['sub' => 'u-editor', 'name' => 'עורך בדיקה', 'role' => 'editor'];

function kf_reset(): void
{
    setting_set('forms_webhooks', '{}');
    setting_set('forms_hook_queue', '[]');
    $GLOBALS['kol_hook_resolve'] = ['hooks.example.com' => ['93.184.216.34'], 'evil.example.com' => ['127.0.0.1'], 'mixed.example.com' => ['93.184.216.34', '10.0.0.5']];
    unset($GLOBALS['kol_hook_fake']);
}

test('V16c טופס מוכן: הרישום (M5) נגזר מה-HTML — השדות, החובה, המלכודת; שדה שנוסף בבונה — נרשם', function () {
    $tpl = (string)file_get_contents(KOL_ROOT . '/assets/kit/components/form/template.html');
    $f = forms_scan('<!doctype html><body>' . $tpl . '</body>')['contact'] ?? null;
    ok($f !== null, 'הטופס contact נרשם');
    eq(['name', 'email', 'message'], $f['fields'], 'השדות');
    eq(['name', 'email', 'message'], $f['required'], 'חובה');
    ok($f['trap'] && !$f['external'] && !$f['files'], 'מלכודת, בלי action, בלי קובץ');
    // שדה חדש (כמו ש"➕ שדה" כותב) ← ברישום
    $more = str_replace('</textarea>' . "\n    </div>", "</textarea>\n    </div>\n    <div class=\"kol-form__field kol-form__field--full\" data-kol-field>\n      <label class=\"kol-form__label\" for=\"kf-field-1\">שדה חדש</label>\n      <input class=\"kol-form__input\" id=\"kf-field-1\" name=\"field-1\" type=\"text\">\n    </div>", $tpl);
    eq(['name', 'email', 'message', 'field-1'], forms_scan('<body>' . $more . '</body>')['contact']['fields'], 'השדה החדש נרשם — בלי צעד נוסף');
    // שלבים (fieldset) ובחירה מרובה
    $steps = '<form data-kol-form="s"><fieldset class="kol-form__step" data-kol-step><input name="a" required></fieldset><fieldset class="kol-form__step" data-kol-step><label><input type="checkbox" name="t" value="x"></label><label><input type="checkbox" name="t" value="y"></label></fieldset></form>';
    eq(['a', 't'], forms_scan($steps)['s']['fields'], 'שדות מכל השלבים');
});

test('V16c webhook: הכתובת — רק https, פורט 443, שם מתחם ציבורי (אותה בדיקה של rec_url_check)', function () {
    foreach (['https://hooks.example.com/x' => '', 'https://hooks.example.com:443/x?a=1' => ''] as $u => $want) eq($want, forms_hook_url_check($u), $u);
    foreach (['http://hooks.example.com/x', 'https://127.0.0.1/x', 'https://[::1]/x', 'https://10.0.0.1/', 'https://0x7f000001/', 'https://localhost/x', 'https://a.localhost/', 'https://printer.local/', 'https://db.internal/',
        'https://intranet/', 'https://user:pw@hooks.example.com/', 'https://hooks.example.com:8443/', 'javascript:alert(1)', 'https://hooks.example.com/a b', "https://hooks.example.com/\n", 'ftp://hooks.example.com/', ''] as $bad) {
        ok(forms_hook_url_check($bad) !== '', "נדחית: " . json_encode($bad));
    }
});

test('V16c webhook: הגדרה — מפרסם בלבד; כתובת פנימית נדחית בשמירה', function () {
    kf_reset();
    eq(403, throws(fn() => forms_hook_set(KF_ED, ['form' => 'contact', 'url' => 'https://hooks.example.com/x'])), 'עורך — 403');
    eq(400, throws(fn() => forms_hook_set(KF_PUB, ['form' => 'contact', 'url' => 'https://192.168.1.5/x'])), 'IP פרטי — נדחה');
    eq(400, throws(fn() => forms_hook_set(KF_PUB, ['form' => '"><x', 'url' => 'https://hooks.example.com/x'])), 'שם טופס לא תקין');
    $r = forms_hook_set(KF_PUB, ['form' => 'contact', 'url' => 'https://hooks.example.com/in']);
    eq('https://hooks.example.com/in', $r['url'], 'נשמר');
    eq('https://hooks.example.com/in', forms_hooks()['contact'] ?? null, 'בהגדרות');
    forms_hook_set(KF_PUB, ['form' => 'contact', 'url' => '']);
    ok(!isset(forms_hooks()['contact']), 'ריק — הוסר');
    eq('publisher', ACTION_ROLES['forms_hook_set'], 'ACTION_ROLES');
});

test('V16c webhook: פנייה ← בתיבה, ואז POST (JSON) לכתובת; DNS פנימי — נחסם; כישלון — ניסיון חוזר, ובסוף "נכשל" ביומן — הפנייה בתיבה', function () {
    kf_reset();
    m5_live(['contact' => ['fields' => ['name', 'email', 'message'], 'required' => ['message'], 'external' => false, 'trap' => true, 'files' => false]]);
    forms_hook_set(KF_PUB, ['form' => 'contact', 'url' => 'https://hooks.example.com/in']);
    $seen = [];
    $GLOBALS['kol_hook_fake'] = function (string $url, string $ip, string $json) use (&$seen) { $seen[] = [$url, $ip, json_decode($json, true)]; return [200, '']; };
    [$code] = m5_recv(['_form' => 'contact', 'name' => 'דנה', 'email' => 'd@example.test', 'message' => 'שלום "x" <b>']);
    eq(200, $code, 'הפנייה התקבלה');
    $e = m5_last();
    ok(count($seen) === 1 && $seen[0][0] === 'https://hooks.example.com/in' && $seen[0][1] === '93.184.216.34', 'נשלח אחרי השמירה — לכתובת, ל-IP שנבדק');
    eq(['form' => 'contact', 'entry' => (int)$e['id'], 'fields' => ['name' => 'דנה', 'email' => 'd@example.test', 'message' => 'שלום "x" <b>']], ['form' => $seen[0][2]['form'], 'entry' => $seen[0][2]['entry'], 'fields' => $seen[0][2]['fields']], 'ה-JSON: הטופס, מספר הפנייה והשדות');
    eq('sent', forms_hook_queue()[0]['state'] ?? null, 'בתור — נשלח');
    // DNS שמחזיר כתובת פנימית (DNS rebinding) — נחסם בשליחה, לא רק בשמירה
    setting_set('forms_webhooks', json_encode(['contact' => 'https://evil.example.com/in']));
    $seen = [];
    m5_recv(['_form' => 'contact', 'message' => 'שנייה']);
    ok(!$seen, 'evil.example.com ← 127.0.0.1: לא נשלח');
    $q = forms_hook_queue(); $last = end($q);
    ok($last['state'] === 'pending' && str_contains($last['last'], 'כתובת פנימית') && $last['next'] > time(), 'נרשם הכשל, וניסיון חוזר מתוזמן: ' . json_encode($last, JSON_UNESCAPED_UNICODE));
    setting_set('forms_webhooks', json_encode(['contact' => 'https://mixed.example.com/in']));
    m5_recv(['_form' => 'contact', 'message' => 'שלישית']);
    $q = forms_hook_queue(); ok(str_contains(end($q)['last'], '10.0.0.5'), 'אחת מכתובות ה-DNS פנימית — נחסם');
    // כישלון רציף ← 6 ניסיונות ← failed, ביומן; הפנייה בתיבה
    kf_reset();
    forms_hook_set(KF_PUB, ['form' => 'contact', 'url' => 'https://hooks.example.com/in']);
    $n = 0;
    $GLOBALS['kol_hook_fake'] = function () use (&$n) { $n++; return [500, 'hooks.example.com ענה 500']; };
    m5_recv(['_form' => 'contact', 'message' => 'תיכשל']);
    $entry = (int)m5_last()['id'];
    for ($i = 0; $i < 8; $i++) { $q = forms_hook_queue(); foreach ($q as &$x) $x['next'] = 0; unset($x); forms_hook_save($q); forms_hook_tick(5); }
    $x = forms_hook_queue()[0];
    ok($n === 6 && $x['state'] === 'failed' && $x['tries'] === 6, '6 ניסיונות (1 + 5 חוזרים), ואז "נכשל": ' . json_encode($x, JSON_UNESCAPED_UNICODE));
    ok((bool)db()->query("SELECT 1 FROM form_entries WHERE id = $entry")->fetchColumn(), 'הפנייה — בתיבה');
    $log = devlog_rows(['q' => 'נכשל סופית', 'limit' => 20]);
    ok(count($log) >= 1, 'ביומן המפתח: "נכשל סופית; הפנייה שמורה בתיבה"');
    ok(isset(forms_dev_state()['webhooks']['failed']) && count(forms_dev_state()['webhooks']['failed']) === 1, 'במסך המפתח (forms_dev_state): הכישלון');
    // פנייה מהטיוטה (▶️ תצוגה חיה, test) — לא נשלחת החוצה
    kf_reset(); forms_hook_set(KF_PUB, ['form' => 'contact', 'url' => 'https://hooks.example.com/in']);
    $sent = 0; $GLOBALS['kol_hook_fake'] = function () use (&$sent) { $sent++; return [200, '']; };
    m5_recv(['_form' => 'contact', 'message' => 'מהטיוטה'], null, true);
    eq(0, $sent, 'פנייה מהתצוגה החיה (test) — לא נשלחת ל-webhook');
    unset($GLOBALS['kol_hook_fake']); kf_reset();
});
