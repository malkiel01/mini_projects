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
    
    /*
     * סריקת HTML שהמשתמש הדביק.
     *
     * כשירות הגנה חוסם את השרת, הדפדפן של המשתמש הוא היחיד שרואה את הדף
     * האמיתי — הוא כבר עבר את האתגר. הוא מדביק את מקור הדף, וכאן מחלצים
     * ממנו את המסגרות הפנימיות ובודקים כל אחת. הנגן יושב לרוב בדומיין
     * אחר, שאינו מוגן כלל.
     */
    if (($_GET['scan'] ?? '') === '1') {
        $html = file_get_contents('php://input');
        if ($html === false || trim($html) === '') fail('לא הודבק תוכן', 'no_html');
        if (strlen($html) > 3_000_000) $html = substr($html, 0, 3_000_000);

        $candidates = [];
        foreach (findFrameCandidates($html, $url) as $cand) {
            $candidates[] = checkCandidate($cand, $origin);
        }

        $open   = count(array_filter($candidates, fn($c) => $c['framable'] === true));
        $unsure = count(array_filter($candidates, fn($c) => $c['framable'] === null));
        $total  = count($candidates);

        if (!$total) {
            $conclusion = 'לא נמצאו מסגרות פנימיות בקוד שהודבק.';
        } elseif ($open) {
            $conclusion = "נמצאו $open מסגרות פתוחות מתוך $total. נסו אותן.";
        } elseif ($unsure) {
            // ההבחנה שעולה כאן כסף: מועמד שהבדיקה שלנו נחסמה עליו אינו
            // מועמד חסום, ואם נציג אותו ככזה נסתיר בדיוק את הנגן המבוקש.
            $conclusion = "מתוך $total מסגרות, $unsure לא ניתנות לבדיקה מהשרת — "
                        . 'לרוב כי שירות הגנה חוסם גם אותן. נסו אותן בכל זאת; '
                        . 'הדפדפן שלכם הוא זה שמכריע.';
        } else {
            $conclusion = 'נמצאו מסגרות, וכולן אמרו במפורש שאינן ניתנות להטמעה.';
        }

        out(['success' => true, 'candidates' => $candidates, 'bytes' => strlen($html),
             'conclusion' => $conclusion]);
    }

    // אבחון מעמיק: מנסה כמה שיטות, וסורק את הדף אחרי מסגרות פנימיות.
    // יקר יותר בזמן ובפניות, ולכן רץ רק כשמבקשים אותו במפורש.
    if (($_GET['deep'] ?? '') === '1') {
        out(['success' => true, 'url' => $url] + deepProbe($url, $origin));
    }
    
    $res = fetchHead($url);
    
    /*
     * דף http בתוך אתר https נחסם על ידי הדפדפן כתוכן מעורב, עוד לפני שהאתר
     * המרוחק מספיק לומר את דעתו. זו חסימה נפרדת, ולכן היא נבדקת בנפרד.
     */
    if ($ourScheme === 'https' && str_starts_with(strtolower($res['url']), 'http://')) {
        out(['success' => true, 'url' => $res['url'], 'status' => $res['status'],
             'verdict' => 'blocked', 'framable' => false,
             'reason' => 'הדף מוגש ב-http, והדפדפן חוסם תוכן כזה בתוך אתר מאובטח',
             'title' => pageTitle($res['body']), 'mixed_content' => true]);
    }
    
    [$allowed, $reason] = framingVerdict($res, $origin);

    /*
     * שתי שאלות נפרדות, ועד עכשיו ערבבתי אותן:
     *
     *   1. האם האתר אוסר הטמעה — נקבע בכותרות בלבד, וגם תשובת שגיאה
     *      נושאת אותן.
     *   2. האם הבדיקה שלנו הצליחה בכלל.
     *
     * ‏403 מ-Cloudflare הוא כישלון של השנייה, לא תשובה לראשונה. להסיק
     * ממנו "האתר חוסם" זו טעות שמונעת מהמשתמש לראות דף שהיה נפתח אצלו
     * בסדר גמור. כשאיננו יודעים — אומרים זאת, ונותנים לדפדפן לנסות.
     */
    $challenge = looksLikeChallenge($res);
    $advisory  = '';

    /*
     * מסך אתגר נבדק ראשון, ולא הכותרות.
     *
     * זו הייתה טעות: כשהתשובה היא מסך "Just a moment", הכותרות שבה שייכות
     * לשירות ההגנה ולא לאתר — Cloudflare שולח SAMEORIGIN משלו. להסיק
     * מהן שהאתר אוסר הטמעה זה לייחס לו עמדה שמעולם לא הביע.
     */
    if ($challenge) {
        $verdict = 'unsure';
        $advisory = 'שירות הגנה חסם את הבדיקה מהשרת, ולכן מה שקיבלנו אינו הדף האמיתי. '
                  . 'בדפדפן שלכם ייתכן שייפתח.';
    } elseif (!$allowed) {
        $verdict = 'blocked';                    // הכותרות של הדף עצמו; אין ספק
    } elseif ($res['status'] >= 400) {
        $verdict = 'unsure';
        $advisory = "האתר החזיר {$res['status']} לבדיקה שלנו. ייתכן שהדף עצמו נפתח בכל זאת.";
    } else {
        $verdict = 'ok';
    }

    out([
        'success'    => true,
        'url'        => $res['url'],
        'status'     => $res['status'],
        'verdict'    => $verdict,
        'framable'   => $verdict !== 'blocked',
        'reason'     => $reason,
        'advisory'   => $advisory,
        'challenge'  => $challenge,
        'title'      => pageTitle($res['body']),
        'redirected' => $res['url'] !== $url,
    ]);
} catch (InspectError $e) {
    out(['success' => false, 'error' => $e->getMessage(), 'code' => $e->kind]);
} catch (Throwable $e) {
    error_log('frame-browser: ' . $e->getMessage());
    out(['success' => false, 'error' => 'שגיאת שרת', 'code' => 'server']);
}
