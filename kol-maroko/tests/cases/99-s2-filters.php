<?php
/**
 * S2 — סינון לפי נושאים ולפי א-ב ב-/lexicon/ (kol-data.js 1.6.0). הבדיקה עצמה ב-JS (tests/editor/letter-filter.mjs), כי זה
 * קוד שרץ אצל הגולש; כאן היא רצה כחלק מחבילת ה-PHP, כדי שתרוץ גם בשער הפריסה. ובנוסף: לבעלים שבעמוד שלו 1.5.1 (S1) — מוצע
 * "🔄 עדכן את kol-data.js לגרסה 1.6.0", והעדכון מביא את הזרע.
 */

declare(strict_types=1);

test('S2 סינון א-ב ונושאים: kol-data.js ו-bind.js — האות הראשונה, הכפתורים, מצטבר עם החיפוש, prerender, והבלוק של העורך', function () {
    $node = trim((string)shell_exec('command -v node 2>/dev/null'));
    ok($node !== '', 'node לא נמצא — הבדיקה צריכה אותו');
    exec(escapeshellarg($node) . ' ' . escapeshellarg(KOL_ROOT . '/tests/editor/letter-filter.mjs') . ' 2>&1', $out, $code);
    eq(0, $code, implode("\n", array_slice($out, -20)));
});

test('S2 הזרע 1.6.0 מתעד את word@letter; בעמוד עם 1.5.1 — older=true ("🔄 עדכן" מופיע), והעדכון מביא 1.6.0', function () {
    $js = (string)file_get_contents(BUILD_SEED_KD);
    eq('1.6.0', build_kd_version($js));
    ok(str_contains($js, 'data-kol-filter="words:word@letter"') && str_contains($js, 'function firstLetter('), 'מתועד בראש הקובץ וממומש');
    $put = function (string $content): void {
        $r = pf_by_path(BUILD_KD);
        if (!$r) m1_post('pf_create', ['path' => BUILD_KD, 'content' => $content]);
        else m1_post('pf_write', ['id' => (int)$r['id'], 'rev' => (int)$r['rev'], 'content' => $content]);
    };
    $put(str_replace("version: '1.6.0'", "version: '1.5.1'", $js));
    $st = builds_list(['sub' => U_OWNER['sub'], 'role' => 'site_owner'])['kd'];
    eq(['1.5.1', '1.6.0', true, false, true], [$st['version'], $st['seed_version'], $st['prerender'], $st['same'], $st['older']]);
    $u = m1_post('kd_update', ['rev' => $st['rev']], U_OWNER);
    eq(200, $u['status'], $u['body']);
    eq(['1.6.0', true, false], [$u['json']['kd']['version'], $u['json']['kd']['same'], $u['json']['kd']['older']]);
    eq($js, pf_blob_get(pf_by_path(BUILD_KD)['sha']), 'התוכן — הזרע');
});
