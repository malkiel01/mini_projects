<?php
/**
 * M4-BC — אופן ההצבה מהכלי (lib/build.php mount_set): בעלים בלבד, פעולה חתומה לתוסף, ביומן. מול tests/fake-wp.php:
 * התוסף האמיתי 2.1, המקבל המדומה (kol_v2_mock — החוזה), ותוסף 1.6.0 קפוא (בלי mount).
 * המעטפת עצמה — tests/plugin/60-mount_test.php; הגשר בדפדפן — tests/browser/m4bc.cjs.
 */

declare(strict_types=1);

require_once KOL_ROOT . '/lib/build.php';

function m4_post(array $json, array $user = U_OWNER): array
{
    return http('POST', '/api.php?action=mount_set', ['user' => $user, 'json' => $json]);
}

function m4_wp(string $k, mixed $v = 'GET'): mixed
{
    $f = $GLOBALS['WP_DIR'] . '/options.json';
    $o = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    if ($v === 'GET') return $o[$k] ?? null;
    if ($v === null) unset($o[$k]); else $o[$k] = $v;
    file_put_contents($f, json_encode($o));
    return null;
}

test('M4-BC mount_set: בעלים בלבד (עורך / מפרסם — 403, בלי CSRF — 403); ערך לא מוכר — 422; המצב והאפשרויות עם "מה מפסידים"', function () {
    wp_site_ready();
    m4_wp('kol_v2_mock', null);
    m4_wp('kol_wp_plugin_v1', null);
    foreach ([U_EDITOR, U_PUBLISHER] as $u) eq(403, m4_post(['mount' => 'framed'], $u)['status'], $u['name']);
    eq(403, http('POST', '/api.php?action=mount_set', ['user' => U_OWNER, 'json' => ['mount' => 'framed'], 'csrf' => false])['status'], 'בלי CSRF');
    eq(403, http('GET', '/api.php?action=mount', ['user' => U_EDITOR])['status']);
    eq(null, m4_wp('kol_maroko_v2_mount'), 'שום בקשה שנדחתה לא הגיעה לאתר');
    foreach (['', 'redirect', 'FRAMED'] as $bad) eq(422, m4_post(['mount' => $bad])['status'], json_encode($bad));
    $g = http('GET', '/api.php?action=mount', ['user' => U_OWNER]);
    eq(200, $g['status'], $g['body']);
    eq(['full', 'framed', 'bar'], array_keys($g['json']['options']));
    ok(count($g['json']['options']['framed']['loses']) >= 2 && str_contains(implode(' ', $g['json']['options']['framed']['loses']), 'גוגל'), 'framed — מה מפסידים, כולל גוגל');
});

test('M4-BC mount_set מול התוסף האמיתי 2.1: נשמר באתר מיד, ping מחזיר אותו, ביומן של הכלי ושל התוסף', function () {
    wp_site_ready();
    m4_wp('kol_v2_mock', null);
    m4_wp('kol_wp_plugin_v1', null);
    foreach (['framed', 'bar', 'full', 'framed'] as $m) {
        $r = m4_post(['mount' => $m]);
        eq(200, $r['status'], $r['body']);
        eq([$m, $m, $m], [$r['json']['mount'], m4_wp('kol_maroko_v2_mount'), $r['json']['site']['v2_mount']]);
    }
    eq('framed', setting('site_mount'));
    $ping = http('POST', '/api.php?action=site_ping', ['user' => U_OWNER, 'json' => []]);
    eq('framed', $ping['json']['site']['v2_mount'] ?? null, 'ping דרך הכלי');
    ok((int)db()->query("SELECT COUNT(*) FROM audit WHERE action = 'mount_set'")->fetchColumn() >= 4, 'ביומן הכלי');
    ok(in_array('mount bar → full', array_column((array)m4_wp('kol_maroko_v2_log'), 'op'), true), 'ביומן התוסף');
});

test('M4-BC mount_set מול המקבל המדומה (החוזה) — ומול תוסף ישן בלי mount: 409, והאתר לא נגע', function () {
    wp_site_ready();
    m4_wp('kol_v2_mock', true);
    $r = m4_post(['mount' => 'bar']);
    eq(200, $r['status'], $r['body']);
    eq('bar', m4_wp('v2_mount'), 'החוזה: v2_mount (גם ב-KOL_V2_REAL — מהתוסף האמיתי)');
    m4_wp('kol_v2_mock', null);
    m4_wp('kol_wp_plugin_v1', true);
    $before = m4_wp('kol_maroko_v2_mount');
    $r = m4_post(['mount' => 'full']);
    eq([409, 'plugin_old'], [$r['status'], $r['json']['code'] ?? null], $r['body']);
    ok(str_contains($r['json']['error'] ?? '', '2.1'), 'ההודעה אומרת מה להתקין: ' . ($r['json']['error'] ?? ''));
    eq($before, m4_wp('kol_maroko_v2_mount'));
    m4_wp('kol_wp_plugin_v1', null);
});
