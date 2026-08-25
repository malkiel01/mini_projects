<?php
/**
 * נקודת הקצה. הלוגיקה כולה ב-lib/inspect.php, כדי שאפשר יהיה לבדוק
 * אותה בלי לזייף בקשת HTTP.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/inspect.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function out(array $payload) {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    
    
    $url = trim((string) ($_GET['url'] ?? ''));
    if ($url === '') fail('חסרה כתובת', 'no_url');

    /*
     * השלמת "https://" נעשית רק כשאין סכימה כלל. בלי ההבחנה,
     * "file:///etc/passwd" היה הופך ל-"https://file:///etc/passwd"
     * ונופל בהמשך על "לא נמצא שרת" — הודעה שמסתירה את הסיבה האמיתית.
     */
    if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $url, $m)) {
        if (!in_array(strtolower($m[1]), ['http', 'https'], true)) {
            fail('רק כתובות http ו-https נתמכות', 'bad_scheme');
        }
    } else {
        $url = 'https://' . $url;
    }
    if (strlen($url) > 2000) fail('הכתובת ארוכה מדי', 'too_long');
    
    $ourScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $origin    = $ourScheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    
    $res = fetchHead($url);
    
    /*
     * דף http בתוך אתר https נחסם על ידי הדפדפן כתוכן מעורב, עוד לפני שהאתר
     * המרוחק מספיק לומר את דעתו. זו חסימה נפרדת, ולכן היא נבדקת בנפרד.
     */
    if ($ourScheme === 'https' && str_starts_with(strtolower($res['url']), 'http://')) {
        out(['success' => true, 'url' => $res['url'], 'status' => $res['status'],
             'framable' => false, 'reason' => 'הדף מוגש ב-http, והדפדפן חוסם תוכן כזה בתוך אתר מאובטח',
             'title' => pageTitle($res['body']), 'mixed_content' => true]);
    }
    
    [$framable, $reason] = framingVerdict($res, $origin);
    
    out([
        'success'   => true,
        'url'       => $res['url'],
        'status'    => $res['status'],
        'framable'  => $framable && $res['status'] < 400,
        'reason'    => $res['status'] >= 400 ? "האתר החזיר שגיאה {$res['status']}" : $reason,
        'title'     => pageTitle($res['body']),
        'redirected' => $res['url'] !== $url,
    ]);
} catch (InspectError $e) {
    out(['success' => false, 'error' => $e->getMessage(), 'code' => $e->kind]);
} catch (Throwable $e) {
    error_log('frame-browser: ' . $e->getMessage());
    out(['success' => false, 'error' => 'שגיאת שרת', 'code' => 'server']);
}
