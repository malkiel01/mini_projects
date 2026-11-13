<?php
/** T2 (V13 🟡A): pf_zip_import עם scope — הגבול של ייבוא תבנית נאכף בשרת, לא רק ב-checkZip שבדפדפן. */

test('T2 pf_zip_import scope: קובץ / תיקייה / קובץ מערכת מחוץ לקידומות — כל הייבוא נדחה, ודבר אינו נכתב; בתוכן — עובר', function () {
    $tpl = [['templates/t2s/template.html', '<section class="t2s">x</section>'], ['templates/t2s/template.json', '{"name":"t2s"}'], ['media/t2s.txt', 'm']];
    $scope = '&scope=' . rawurlencode('templates/t2s/,media/');
    $before = b_tree_bytes();
    foreach ([
        'index.html מחוץ ל-scope' => [['index.html', '<h1>pwned</h1>']],
        'kol.json'               => [['kol.json', '{}']],
        'תבנית אחרת'             => [['templates/other/template.html', 'x']],
        'קידומת דומה'            => [['templates/t2sx/a.html', 'x']],
        'תיקייה מחוץ'            => [['evil/', '']],
    ] as $what => $extra) {
        foreach (['', '&apply=1&token=x'] as $ap) {
            $r = b_zip_post(b_zip(array_merge($tpl, $extra)), $scope . $ap);
            eq(400, $r['status'], "$what ($ap): " . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
            ok(str_contains((string)($r['json']['error'] ?? ''), 'מחוץ למותר'), "$what: ההודעה אומרת מה מחוץ: " . ($r['json']['error'] ?? ''));
        }
    }
    eq($before, b_tree_bytes(), 'דבר לא נכתב');
    foreach (['templates/t2s', 'templates/t2s/,../', '/abs/', 'a//b/', 'templates/t2s/,'] as $badScope)
        eq(400, b_zip_post(b_zip($tpl), '&scope=' . rawurlencode($badScope))['status'], 'scope לא תקין נדחה: ' . $badScope);

    // בתוך הגבול (כולל תיקיות הורה ותיקייה בתוך התבנית) — תוכנית, ואז החלה
    $zip = b_zip(array_merge([['templates/', ''], ['templates/t2s/', ''], ['templates/t2s/img/', '']], $tpl));
    $plan = b_zip_post($zip, $scope);
    eq(200, $plan['status'], json_encode($plan['json'], JSON_UNESCAPED_UNICODE));
    eq(3, $plan['json']['plan']['counts']['add'], 'שלושה קבצים יתווספו');
    $r = b_zip_post($zip, $scope . '&apply=1&token=' . $plan['json']['plan']['token']);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    ok(isset(pf_paths()['templates/t2s/template.html']) && isset(pf_paths()['media/t2s.txt']), 'נכתבו');
    // בלי scope — ההתנהגות הקיימת (ייבוא של כל הפרויקט, F2b) לא השתנתה
    eq(200, b_zip_post(b_zip([['notes-t2.txt', 'x']]))['status'], 'בלי scope — כמו קודם');
});
