<?php
// פרסום חתום: החתימה שלנו מול קוד התוסף האמיתי (tests/fake-wp.php טוען את wordpress-plugin/kol-maroko/kol-maroko.php).

declare(strict_types=1);

/** מכוון את הכלי אל האתר המדומה, ומדביק בו את המפתח הציבורי — כמו שהבעלים עושה בוורדפרס. */
function wp_site_ready(): void
{
    setting_set('site_url', $GLOBALS['WP']);
    $o = is_file($GLOBALS['WP_DIR'] . '/options.json') ? (json_decode((string)file_get_contents($GLOBALS['WP_DIR'] . '/options.json'), true) ?: []) : [];
    $o['kol_maroko_keys'] = pub_key_line(pub_key());
    file_put_contents($GLOBALS['WP_DIR'] . '/options.json', json_encode($o));
}

/** בקשה חתומה ביד — כדי לשלוח אותה פעמיים, או לשנות אותה אחרי החתימה. */
function wp_signed(string $action, array $payload, ?string $tamper = null, ?array $key = null): array
{
    $k = $key ?? pub_key();
    $body = json_encode($payload + ['action' => $action, 'site' => site_host(), 'page' => PUB_PAGE, 'time' => time(), 'nonce' => bin2hex(random_bytes(16))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sig = base64_encode(sodium_crypto_sign_detached($body, base64_decode($k['secret'])));
    return ['body' => $tamper ?? $body, 'headers' => ['Content-Type: application/json', 'X-Kol-Kid: ' . $k['kid'], 'X-Kol-Signature: ' . $sig]];
}

function wp_send(string $action, array $signed): array
{
    $save = $GLOBALS['BASE'];
    $GLOBALS['BASE'] = $GLOBALS['WP'];
    try { return http('POST', PUB_ROUTE . $action, ['raw' => $signed['body'], 'headers' => $signed['headers']]); } finally { $GLOBALS['BASE'] = $save; }
}

test('פרסום: בקשה חתומה מהכלי מתקבלת בתוסף (ping)', function () {
    wp_site_ready();
    $r = site_call('ping', []);
    eq([true, KOL_PLUGIN_EXPECTED], [$r['ok'], $r['plugin']]);
});

test('פרסום: חתימה שגויה, גוף ששונה אחרי החתימה, ומפתח לא מוכר — נדחים', function () {
    wp_site_ready();
    $s = wp_signed('ping', []);
    eq(200, wp_send('ping', $s)['status'], 'בקשה תקינה');
    $t = wp_signed('ping', []);
    $t['body'] = str_replace('"page":"lexicon"', '"page":"lexicon" ', $t['body']);
    $r = wp_send('ping', $t);
    eq([401, 'bad_signature'], [$r['status'], $r['json']['code']], 'גוף ששונה');
    $kp = sodium_crypto_sign_keypair();
    $other = ['kid' => pub_key()['kid'], 'secret' => base64_encode(sodium_crypto_sign_secretkey($kp))];
    eq('bad_signature', wp_send('ping', wp_signed('ping', [], null, $other))['json']['code'], 'מפתח אחר באותו מזהה');
    eq('bad_signature', wp_send('ping', wp_signed('ping', [], null, ['kid' => 'unknown-kid'] + pub_key()))['json']['code'], 'מזהה מפתח לא מוכר');
    $r = wp_send('publish', wp_signed('ping', []));
    eq(400, $r['status'], 'חתימה של פעולה אחת לא עוברת לפעולה אחרת');
});

test('פרסום: nonce חוזר נדחה; זמן רחוק נדחה', function () {
    wp_site_ready();
    $s = wp_signed('ping', []);
    eq(200, wp_send('ping', $s)['status']);
    $again = wp_send('ping', $s);
    eq([401, 'replay'], [$again['status'], $again['json']['code']], 'אותה בקשה פעם שנייה');
    $old = wp_signed('ping', ['time' => time() - 3600]);
    eq('stale', wp_send('ping', $old)['json']['code'], 'בקשה מלפני שעה');
});

test('פרסום: מספר גרסה יורד או חוזר נדחה; עולה מתקבל', function () {
    wp_site_ready();
    $pub = fn(int $n) => wp_send('publish', wp_signed('publish', ['version' => $n, 'html' => "<p>גרסה $n</p>", 'css' => '', 'assets' => [], 'published_by' => 'בדיקה']));
    $a = $pub(5);
    eq([200, 5], [$a['status'], $a['json']['version'] ?? null], $a['body']);
    $same = $pub(5);
    eq([409, 'old_version'], [$same['status'], $same['json']['code']], 'אותו מספר');
    $down = $pub(4);
    eq([409, 'old_version', 5], [$down['status'], $down['json']['code'], $down['json']['version']], 'מספר יורד');
    eq(200, $pub(6)['status'], 'מספר עולה');
    eq(6, site_call('ping', [])['version'], 'האתר מדווח על הגרסה האחרונה');
});

test('פרסום: בלי מפתח ציבורי באתר — נדחה, והכלי מחזיר שגיאה מובנת', function () {
    wp_site_ready();
    file_put_contents($GLOBALS['WP_DIR'] . '/options.json', json_encode(['kol_maroko_keys' => '']));
    $code = throws(fn() => site_call('ping', []), 'ping בלי מפתח');
    ok($code >= 400, "קוד $code");
    wp_site_ready();
});
