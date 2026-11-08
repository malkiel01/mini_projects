<?php
// ענן MBE מדומה (בדיקות בלבד): רישום לקוח, ואסימון לחיבור. האסימון נגזר מהחיבור — כך רואים למי הוא שייך.
return function (string $url, array $headers): array {
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    $a = $q['action'] ?? '';
    $body = [];
    foreach ($headers as $h) if (stripos($h, 'X-Test-Body:') === 0) $body = json_decode(trim(substr($h, 12)), true) ?: [];
    $conn = (string)($body['connection'] ?? '');
    // רק אותו חשבון (api-guide 2): ה-sub שנשלח חייב להיות של מי שהחיבור שלו — כאן: c_<sub>
    if ($a === 'access_token' && $conn !== 'c_' . ($body['sub'] ?? '')) return [403, json_encode(['error' => 'החשבון בענן שונה', 'code' => 'account_mismatch'])];
    if ($a === 'client_register') return [200, json_encode(['status' => 'active'])];
    // בלי scope בתשובה — המודול שומר את ההיקף שבמסד (כך הבדיקה קובעת files או files.app)
    if ($a === 'access_token') return [200, json_encode(['token' => 'tok-for-' . $conn, 'expires_in' => 900])];
    if ($a === 'disconnect') return [200, json_encode(['ok' => true])];
    // spec-6: המנהל הדליק 📁 לאפליקציה הזו (לכל המשתמשים)
    if ($a === 'client_policy') return [200, json_encode(['layers' => ['files']])];
    return [404, json_encode(['error' => 'לא מוכר', 'code' => 'not_found'])];
};
