<?php
/**
 * עזרים לבדיקות של התוסף 2.0 (tests/plugin/*_test.php). רצים בתוך tests/run.php (test, eq, ok, serve).
 * אתר מדומה משלהם (tests/plugin/wp.php) בתיקייה זמנית, עם זוג מפתחות משלהם — בלי תלות בכלי עצמו.
 */

declare(strict_types=1);

const KP_PAGE = 'lexicon';
const KP_CSP = 'sandbox allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals allow-downloads allow-presentation';

/** מעלה את האתר המדומה (פעם אחת), עם 8 תהליכים — לבדיקת האטומיות. */
function kp_site(): array
{
    if (isset($GLOBALS['KP'])) return $GLOBALS['KP'];
    $dir = $GLOBALS['TMP'] . '/wp-m2';
    @mkdir($dir . '/uploads', 0775, true);
    $kp = sodium_crypto_sign_keypair();
    $GLOBALS['KP'] = ['dir' => $dir, 'kid' => 'k-m2', 'secret' => sodium_crypto_sign_secretkey($kp), 'public' => sodium_crypto_sign_publickey($kp)];
    kp_reset();
    [$proc, $base] = serve(__DIR__ . '/wp.php', ['KOL_TEST_WP' => $dir, 'PHP_CLI_SERVER_WORKERS' => '8']);
    $GLOBALS['procs'][] = $proc;
    $GLOBALS['KP']['base'] = $base;
    $GLOBALS['KP']['host'] = (string)parse_url($base, PHP_URL_HOST);
    return $GLOBALS['KP'];
}

/** אתר נקי: בלי חבילות, עם המפתח הציבורי מודבק, ואפשרויות נוספות לפי הצורך. */
function kp_reset(array $opts = []): void
{
    $k = $GLOBALS['KP'];
    if (is_dir($k['dir'] . '/uploads')) rrmdir($k['dir'] . '/uploads');
    mkdir($k['dir'] . '/uploads', 0775, true);
    file_put_contents($k['dir'] . '/options.json', json_encode($opts + ['kol_maroko_keys' => $k['kid'] . ':' . base64_encode($k['public'])]));
}

function kp_opts(): array { return json_decode((string)file_get_contents(kp_site()['dir'] . '/options.json'), true) ?: []; }
function kp_opt(string $k, $v): void { $o = kp_opts(); if ($v === null) unset($o[$k]); else $o[$k] = $v; file_put_contents(kp_site()['dir'] . '/options.json', json_encode($o)); }
function kp_get(string $k) { return kp_opts()[$k] ?? null; }
function kp_v2(): string { return kp_site()['dir'] . '/uploads/kol-maroko/v2'; }

/** בקשת HTTP גולמית. מחזיר status, headers (שם ← [ערכים]), body. */
function kp_http(string $method, string $path, array $headers = [], ?string $body = null): array
{
    clearstatcache();
    $ch = curl_init(kp_site()['base'] . $path);
    $rh = [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30,
        CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_PATH_AS_IS => true,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$rh) { if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $rh[strtolower(trim($k))][] = trim($v); } return strlen($line); }]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res = curl_exec($ch);
    if ($res === false) throw new TestFail('הבקשה נכשלה: ' . curl_error($ch));
    $st = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $st, 'headers' => $rh, 'body' => (string)$res, 'h' => fn($n) => $rh[strtolower($n)][0] ?? null];
}

function kp_h(array $r, string $n): ?string { return $r['headers'][strtolower($n)][0] ?? null; }

/** בקשה חתומה לתוסף. $o: tamper (גוף אחר אחרי החתימה), secret / kid אחרים, ושדות שגוברים על המעטפת. */
function kp_signed(string $action, array $payload, array $o = []): array
{
    $k = kp_site();
    $body = json_encode($payload + ['action' => $action, 'site' => $k['host'], 'page' => KP_PAGE, 'time' => time(), 'nonce' => bin2hex(random_bytes(16))],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sig = base64_encode(sodium_crypto_sign_detached($body, $o['secret'] ?? $k['secret']));
    return ['body' => $o['tamper'] ?? $body, 'headers' => ['Content-Type: application/json', 'X-Kol-Kid: ' . ($o['kid'] ?? $k['kid']), 'X-Kol-Signature: ' . $sig]];
}

function kp_send(string $action, array $payload = [], array $o = []): array
{
    $s = kp_signed($action, $payload, $o);
    $r = kp_http('POST', '/wp-json/kol-maroko/v1/' . $action, $s['headers'], $s['body']);
    $r['json'] = json_decode($r['body'], true);
    return $r;
}

/** שולח גוש (בחלקים של $chunk בייטים). מחזיר את התשובה האחרונה. */
function kp_put(string $bytes, ?string $type = null, int $chunk = 0, ?string $sha = null): array
{
    $sha ??= hash('sha256', $bytes);
    $parts = $chunk > 0 ? max(1, (int)ceil(strlen($bytes) / $chunk)) : 1;
    $r = null;
    for ($i = 0; $i < $parts; $i++) {
        $data = $chunk > 0 ? substr($bytes, $i * $chunk, $chunk) : $bytes;
        $r = kp_send('asset_put', ['sha256' => $sha, 'size' => strlen($bytes), 'part' => $i, 'parts' => $parts, 'data' => base64_encode($data)] + ($type ? ['type' => $type] : []));
        if (!empty($r['json']['have'])) break;
    }
    return $r;
}

/** מניפסט מ-[נתיב => בייטים] (הגושים נשלחים קודם, אלא אם $send=false). */
function kp_manifest(array $files, int $version, array $over = [], bool $send = true): array
{
    $k = kp_site();
    $list = [];
    foreach ($files as $p => $bytes) {
        $list[$p] = ['sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'type' => 'x'];
        if ($send) kp_put($bytes, kp_mime($p));
    }
    return $over + ['site' => $k['host'], 'page' => KP_PAGE, 'version' => $version, 'time' => time(), 'nonce' => bin2hex(random_bytes(16)),
        'entry' => 'index.html', 'published_by' => 'בודק', 'files' => $list];
}

function kp_mime(string $p): string
{
    return ['mp3' => 'audio/mpeg', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'woff2' => 'font/woff2', 'svg' => 'image/svg+xml', 'css' => 'text/css',
        'js' => 'text/javascript', 'html' => 'text/html'][strtolower(pathinfo($p, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}

/** שולח מניפסט חתום. $o: bytes (בייטים אחרים מאלה שנחתמו), secret, kid. */
function kp_publish(array $man, array $o = []): array
{
    $k = kp_site();
    $bytes = json_encode($man, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sig = base64_encode(sodium_crypto_sign_detached($bytes, $o['secret'] ?? $k['secret']));
    return kp_send('manifest', ['manifest' => $o['bytes'] ?? $bytes, 'manifest_sig' => $o['sig'] ?? $sig, 'kid' => $o['kid'] ?? $k['kid']]);
}

/** החבילה הבסיסית לבדיקות ההגשה. */
function kp_bundle(string $tag = 'A'): array
{
    return [
        'index.html' => "<!doctype html><title>$tag</title><link rel=stylesheet href=a.css><p>VERSION-$tag</p>",
        'a.css' => "p{color:red}/*$tag*/",
        'x/index.html' => "<p>sub $tag</p>",
        'media/w.mp3' => "ID3" . str_repeat("\x01\x02\x03$tag", 400),
        'מילים/דף.html' => "<p>עברית $tag</p>",
    ];
}

/** כל הכותרות של I1–I7 בתשובה אחת. מחזיר רשימת חסרים (ריקה = תקין). */
function kp_iso(array $r): array
{
    $bad = [];
    if (kp_h($r, 'content-security-policy') !== KP_CSP) $bad[] = 'CSP=' . kp_h($r, 'content-security-policy');
    if (kp_h($r, 'x-content-type-options') !== 'nosniff') $bad[] = 'nosniff';
    if (kp_h($r, 'access-control-allow-origin') !== '*') $bad[] = 'ACAO';
    if (kp_h($r, 'referrer-policy') !== 'strict-origin-when-cross-origin') $bad[] = 'Referrer-Policy';
    if (kp_h($r, 'cross-origin-resource-policy') !== 'cross-origin') $bad[] = 'CORP';
    if (kp_h($r, 'cross-origin-opener-policy') !== 'same-origin') $bad[] = 'COOP';
    if (kp_h($r, 'content-type') === null) $bad[] = 'Content-Type';
    if (isset($r['headers']['set-cookie'])) $bad[] = 'Set-Cookie: ' . implode(' | ', $r['headers']['set-cookie']);
    if (str_contains($r['body'], 'OUTPUT-BEFORE-PLUGIN') || str_contains($r['body'], 'WP-SITE-PAGE')) $bad[] = 'פלט של האתר דלף';
    return $bad;
}

/** מפרסם חבילה ומדליק את המפסק. */
function kp_live(array $files, int $version, bool $on = true): array
{
    $r = kp_publish(kp_manifest($files, $version));
    if ($r['status'] !== 200) throw new TestFail('הפרסום נכשל: ' . $r['body']);
    kp_opt('kol_maroko_v2_on', $on);
    return $r;
}
