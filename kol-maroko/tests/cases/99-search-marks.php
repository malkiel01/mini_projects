<?php
/**
 * S1 — "חפש מילה…" ב-/lexicon/ מוצא מילים מנוקדות. הבדיקה עצמה ב-JS (tests/editor/search-marks.mjs), כי זה קוד שרץ אצל
 * הגולש; כאן היא רצה כחלק מחבילת ה-PHP, כדי שתרוץ גם בשער הפריסה.
 */

declare(strict_types=1);

test('S1 חיפוש בלי ניקוד: kol-data.js ו-bind.js מוצאים מנוקד↔לא מנוקד, תווי כיוון לא מפריעים, התצוגה לא משתנה', function () {
    $node = trim((string)shell_exec('command -v node 2>/dev/null'));
    ok($node !== '', 'node לא נמצא — הבדיקה צריכה אותו');
    exec(escapeshellarg($node) . ' ' . escapeshellarg(KOL_ROOT . '/tests/editor/search-marks.mjs') . ' 2>&1', $out, $code);
    eq(0, $code, implode("\n", array_slice($out, -20)));
});

test('S1 הזרע של kol-data.js (1.6.0 מאז S2) מסיר ניקוד ב-plain()', function () {
    $js = file_get_contents(KOL_ROOT . '/assets/project-seed/kol-data.js');
    eq('1.6.0', build_kd_version($js));
    ok(str_contains($js, "text.replace(MARKS, '')"), 'plain() מסיר ניקוד');
});

test('S1 kol-data.js 1.5.0 (כבר עם prerender) — older=true, כדי ש"🔄 עדכן" יופיע; העדכון מביא 1.5.1; שינוי מקומי באותה גרסה — לא older', function () {
    $old = str_replace("version: '1.6.0'", "version: '1.5.0'", (string)file_get_contents(BUILD_SEED_KD));
    $put = function (string $content): void {
        $r = pf_by_path(BUILD_KD);
        if (!$r) m1_post('pf_create', ['path' => BUILD_KD, 'content' => $content]);
        else m1_post('pf_write', ['id' => (int)$r['id'], 'rev' => (int)$r['rev'], 'content' => $content]);
    };
    $put($old);
    $st = builds_list(['sub' => U_OWNER['sub'], 'role' => 'site_owner'])['kd'];
    eq(['1.5.0', '1.6.0', true, false, true], [$st['version'], $st['seed_version'], $st['prerender'], $st['same'], $st['older']]);
    $u = m1_post('kd_update', ['rev' => $st['rev']], U_OWNER);
    eq(200, $u['status'], $u['body']);
    eq(['1.6.0', true, false], [$u['json']['kd']['version'], $u['json']['kd']['same'], $u['json']['kd']['older']]);
    $put(file_get_contents(BUILD_SEED_KD) . "\n// שינוי של הבעלים\n");
    $st = builds_list(['sub' => U_OWNER['sub'], 'role' => 'site_owner'])['kd'];
    eq([false, false], [$st['same'], $st['older']], 'אותה גרסה עם שינוי מקומי — לא מציעים לדרוס');
    $put((string)file_get_contents(BUILD_SEED_KD));
});
