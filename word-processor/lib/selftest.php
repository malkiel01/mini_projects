<?php
/**
 * בדיקה חיה של ההטמעה מול השרת עצמו — api.php?action=selftest (ציבורי, פעם ב-20 שניות).
 * העורך נרשם כלקוח של עצמו ('word-processor', המפתח ב-embed_key) ועובר את כל המסלול **ב-HTTP אמיתי**
 * (דרך השרת והשער, כמו מארחת): client_register → embed_session חתום → embed.php (CSP) → embed_open →
 * embed_save → doc_export (html/text/ooxml/docx/pdf) → בקשה חוזרת (replay) → מסמך של לקוח אחר (404) → doc_delete.
 * מחזיר רק כן/לא לכל שלב — בלי תוכן ובלי סודות.
 */

function selftest(): array
{
    $last = (int)(setting('selftest_at') ?? 0);
    if (time() - $last < 20) throw new ApiError('בדיקה רצה לפני רגע — נסו שוב בעוד 20 שניות', 'rate', 429);
    set_setting('selftest_at', (string)time());

    $base = base_url();
    $origin = own_origin();
    $k = own_keypair();
    $checks = [];
    $ok = function (string $name, bool $pass, string $detail = '') use (&$checks) {
        $checks[] = ['check' => $name, 'ok' => $pass] + ($detail !== '' ? ['detail' => $detail] : []);
        return $pass;
    };
    $http = function (string $method, string $url, ?string $body = null, array $headers = []) {
        $ch = curl_init($url);
        $h = ['Cookie: humans_21909=1'];
        foreach ($headers as $n => $v) $h[] = "$n: $v";
        if ($body !== null) $h[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $h, CURLOPT_USERAGENT => 'word-processor-selftest/1'] + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []));
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($res === false) return [0, '', ''];
        return [$code, substr($res, 0, $hs), substr($res, $hs)];
    };
    $signed = function (string $action, array $payload) use ($http, $base, $k) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $hdr = sign_headers(APP_ID, $k['kid'], $k['secret'], $action, $body);
        return [$http('POST', $base . 'api.php?action=' . $action, $body, $hdr), $hdr, $body];
    };

    // 1. רישום
    [$c, , $b] = $http('POST', $base . 'api.php?action=client_register', json_encode([
        'client' => APP_ID, 'name' => 'עורך התמלילים (בדיקה עצמית)', 'key_url' => $base . 'api.php?action=embed_key', 'origins' => [$origin]]));
    $j = json_decode($b, true);
    if (!$ok('client_register → active', $c === 200 && ($j['status'] ?? '') === 'active', "$c " . ($j['status'] ?? $j['code'] ?? substr($b, 0, 80)))) return ['ok' => false, 'checks' => $checks];

    // 2. embed_session חתום — מסמך חדש
    [[$c, , $b]] = $signed('embed_session', ['doc' => '', 'title' => 'בדיקה עצמית', 'user' => ['id' => 'selftest', 'name' => 'בדיקה'],
        'can' => 'edit', 'mode' => 'section', 'page' => ['size' => 'A4', 'numbering' => ['format' => 'n_of_m']], 'return_origin' => $origin]);
    $j = json_decode($b, true);
    $doc = $j['doc'] ?? '';
    $url = $j['embed_url'] ?? '';
    if (!$ok('embed_session (חתום)', $c === 200 && $doc !== '' && str_contains($url, 'embed.php?t='), "$c " . ($j['code'] ?? ''))) return ['ok' => false, 'checks' => $checks];

    // 3. embed.php — CSP frame-ancestors של הלקוח
    [$c, $hdrs] = $http('GET', $url);
    $ok('embed.php → 200 + frame-ancestors', $c === 200 && stripos($hdrs, 'frame-ancestors ' . $origin) !== false, (string)$c);

    // 4. embed_open — הטוקן נצרך פעם אחת
    $t = substr($url, strpos($url, '?t=') + 3);
    [$c, , $b] = $http('POST', $base . 'api.php?action=embed_open', json_encode(['t' => $t]));
    $j = json_decode($b, true);
    $session = $j['session'] ?? '';
    $ok('embed_open → סשן ועריכה', $c === 200 && $session !== '' && ($j['can'] ?? '') === 'edit', "$c " . ($j['code'] ?? ''));
    [$c] = $http('POST', $base . 'api.php?action=embed_open', json_encode(['t' => $t]));
    $ok('טוקן חד-פעמי (פתיחה שנייה נדחית)', $c === 401, (string)$c);

    // 5. embed_save ב-Bearer
    $json = ['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'תכנית עבודה 2027']]],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'סעיף']]]]]]],
    ]];
    [$c, , $b] = $http('POST', $base . 'api.php?action=embed_save', json_encode(['json' => $json, 'base_version' => $j['version'] ?? 1]),
        ['Authorization' => 'Bearer ' . $session, 'X-Embed-Session' => $session]);
    $j = json_decode($b, true);
    $ok('embed_save (Bearer)', $c === 200 && ($j['version'] ?? 0) >= 2, "$c " . ($j['code'] ?? ''));

    // 6. הייצוא
    foreach (['html' => 'html', 'text' => 'text', 'ooxml' => 'body'] as $fmt => $key) {
        [[$c, , $b]] = $signed('doc_export', ['doc' => $doc, 'format' => $fmt]);
        $j = json_decode($b, true);
        $ok("doc_export $fmt", $c === 200 && str_contains((string)($j[$key] ?? ''), $fmt === 'ooxml' ? '<w:p>' : 'תכנית עבודה 2027'), (string)$c);
    }
    [[$c, $h, $b]] = $signed('doc_export', ['doc' => $doc, 'format' => 'docx']);
    $ok('doc_export docx', $c === 200 && str_starts_with($b, 'PK') && stripos($h, 'wordprocessingml') !== false, (string)$c);
    [[$c, $h, $b]] = $signed('doc_export', ['doc' => $doc, 'format' => 'pdf']);
    $ok('doc_export pdf', $c === 200 && str_starts_with($b, '%PDF-') && str_contains($b, '%%EOF') && stripos($h, 'application/pdf') !== false, $c . ' ' . strlen($b) . 'B');

    // 7. replay — אותה בקשה חתומה פעמיים
    [[$c], $hdr, $body] = $signed('doc_get', ['doc' => $doc]);
    [$c2, , $b2] = $http('POST', $base . 'api.php?action=doc_get', $body, $hdr);
    $ok('doc_get, וחזרה עליה נדחית (replay)', $c === 200 && $c2 === 401 && (json_decode($b2, true)['code'] ?? '') === 'replay', "$c/$c2");

    // 8. חתימה שגויה
    $bad = sign_headers(APP_ID, $k['kid'], sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()), 'doc_get', json_encode(['doc' => $doc]));
    [$c, , $b] = $http('POST', $base . 'api.php?action=doc_get', json_encode(['doc' => $doc]), $bad);
    $ok('חתימה במפתח זר נדחית', $c === 401 && (json_decode($b, true)['code'] ?? '') === 'bad_signature', (string)$c);

    // 9. מסמך שאינו של הלקוח → 404
    [[$c]] = $signed('doc_get', ['doc' => 'zzzzzzzzzzzzzzzzzzzz']);
    $ok('מסמך שאינו במרחב → 404', $c === 404, (string)$c);

    // 10. מחיקה
    [[$c]] = $signed('doc_delete', ['doc' => $doc]);
    $ok('doc_delete', $c === 200, (string)$c);

    $all = !in_array(false, array_column($checks, 'ok'), true);
    wp_log('selftest', $all ? 'ok' : json_encode($checks, JSON_UNESCAPED_UNICODE));
    return ['ok' => $all, 'checks' => $checks];
}
