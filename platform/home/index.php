<?php
/**
 * הבית של אפליקציית הפלטפורמה המותקנת (start_url ו-scope ב-manifest.webmanifest).
 *
 * למה תיקייה משלה: הטווח (scope) של אפליקציה מותקנת הוא תיקייה, וכל מה שבתוכה נפתח בתוך החלון שלה.
 * כשהטווח היה השורש — כל הכלים היו בתוכו, כלי שנפתח "תפס" את חלון הפלטפורמה, ופתיחה חוזרת של
 * הכלים הדיגיטליים החזירה את הכלי האחרון. כאן הטווח הוא רק התיקייה הזו: כלי נפתח מחוץ לה (בדפדפן
 * או באפליקציה המותקנת שלו), והפלטפורמה תמיד נפתחת על רשימת האפליקציות.
 *
 * אותו דף כמו index.html שבשורש — לא עותק: נקרא משם, עם <base> כדי שהנתיבים היחסיים בו יעבדו.
 */

declare(strict_types=1);

// איש קשר ששותף לאפליקציה (share_target במניפסט): מאנשי הקשר של אנדרואיד ← שיתוף ← "כלים דיגיטליים".
// כאן רק מפרקים את ה-vCard ומפנים לחלון "הזמנת לקוח" ממולא. הפרטים עוברים אחרי # — לא נשמרים ולא נרשמים
// ביומני השרת. ההזמנה עצמה דורשת מנהל מחובר כרגיל.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['share'] ?? '') === 'contact') {
    $raw = '';
    $f = $_FILES['contact'] ?? null;
    foreach ((array)($f['tmp_name'] ?? []) as $tmp) if (is_string($tmp) && is_uploaded_file($tmp)) { $raw .= file_get_contents($tmp, false, null, 0, 200000) . "\n"; }
    $raw .= "\n" . (string)($_POST['text'] ?? '') . "\n" . (string)($_POST['title'] ?? '');
    $c = share_contact($raw);
    $hash = rtrim(strtr(base64_encode(json_encode($c, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    header('Location: ./#invite-contact=' . $hash, true, 303);
    exit;
}

/** {name, tel, email} מתוך vCard — או מטקסט חופשי (יש אפליקציות ששולחות "שם\nטלפון"). */
function share_contact(string $raw): array
{
    $raw = preg_replace("/\r?\n[ \t]/", '', $raw);   // שורות מקופלות של vCard
    $c = ['name' => '', 'tel' => '', 'email' => ''];
    $tels = [];
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        if (!preg_match('/^(?:item\d+\.)?([A-Z-]+)((?:;[^:]*)?):(.*)$/i', trim($line), $m)) continue;
        [$key, $params, $val] = [strtoupper($m[1]), strtoupper($m[2]), trim($m[3])];
        if (str_contains($params, 'QUOTED-PRINTABLE')) $val = quoted_printable_decode($val);
        if ($key === 'FN' && $c['name'] === '') $c['name'] = $val;
        if ($key === 'N' && $c['name'] === '') $c['name'] = trim(implode(' ', array_reverse(array_slice(explode(';', $val), 0, 2))));
        if ($key === 'TEL') $tels[] = $val;
        if ($key === 'EMAIL' && $c['email'] === '') $c['email'] = $val;
    }
    if (!$tels && preg_match_all('/\+?\d[\d\s().-]{7,}\d/', $raw, $mm)) $tels = $mm[0];
    if ($c['email'] === '' && preg_match('/[^\s<>"]+@[^\s<>"]+\.[a-z]{2,}/i', $raw, $mm)) $c['email'] = $mm[0];
    if ($c['name'] === '' && !str_contains($raw, 'BEGIN:VCARD')) {
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $line = trim($line);
            if ($line !== '' && !preg_match('/\d{5}|@/', $line)) { $c['name'] = $line; break; }
        }
    }
    foreach ($tels as $t) if (preg_match('/^\+?972|^05/', preg_replace('/[\s().-]/', '', $t))) { $c['tel'] = $t; break; }
    if ($c['tel'] === '' && $tels) $c['tel'] = $tels[0];
    return array_map(fn($v) => mb_substr($v, 0, 120), $c);
}

$html = @file_get_contents(dirname(__DIR__, 2) . '/index.html');
if ($html === false) {
    http_response_code(500);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo preg_replace('/<head>/i', '<head><base href="../../">', $html, 1);
