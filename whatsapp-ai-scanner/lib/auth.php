<?php
/**
 * הרשאות. שני ערוצים נפרדים, בכוונה:
 *
 *   הבעלים  — סשן עם עוגייה, נפתח בכניסה עם סיסמה. דרכו נשאלות שאלות
 *             ומוגדרים חשבונות.
 *   הגשר    — אפליקציית האנדרואיד שסורקת את הוואטסאפ. מזדהה באסימון
 *             צימוד בכותרת, לא בעוגייה: היא אינה דפדפן. הפרדת הערוצים
 *             מבטיחה שאסימון צימוד שדלף יכול רק להזרים הודעות — לא
 *             לקרוא את ההיסטוריה ולא לשנות הגדרות.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function sessionStart(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('WASCANNER');
    session_start();
}

function isOwner(): bool {
    sessionStart();
    return !empty($_SESSION['owner']);
}

function requireOwner(): void {
    if (!isOwner()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'נדרשת כניסה'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/** קובע סיסמה בהתקנה הראשונה. */
function setupOwner(string $password): void {
    if (ownerConfigured()) {
        throw new InvalidArgumentException('הבעלים כבר הוגדר');
    }
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('הסיסמה חייבת להיות באורך 8 תווים לפחות');
    }
    updateOwner([
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        // אסימון צימוד נוצר כבר עכשיו, כדי שיהיה מה להראות לאפליקציה.
        'pair_token'    => bin2hex(random_bytes(24)),
        'log_token'     => bin2hex(random_bytes(24)),
    ]);
}

/** אסימון קריאת היומן. נוצר בעצלתיים למסד שקדם לפיצ'ר. */
function ownerLogToken(): string {
    $t = owner()['log_token'] ?? '';
    if ($t === '') {
        $t = bin2hex(random_bytes(24));
        updateOwner(['log_token' => $t]);
    }
    return $t;
}

function logTokenValid(string $t): bool {
    $real = ownerLogToken();
    return $t !== '' && hash_equals($real, $t);
}

function rotateLogToken(): string {
    $t = bin2hex(random_bytes(24));
    updateOwner(['log_token' => $t]);
    return $t;
}

function login(string $password): bool {
    $hash = owner()['password_hash'] ?? '';
    if ($hash === '' || !password_verify($password, $hash)) return false;
    sessionStart();
    session_regenerate_id(true);
    $_SESSION['owner'] = true;
    return true;
}

function logout(): void {
    sessionStart();
    $_SESSION = [];
    session_destroy();
}

/**
 * מזהה את הגשר לפי אסימון הצימוד. השוואה בזמן קבוע כדי שלא תדלוף
 * מידע על תקינות האסימון דרך זמן התגובה.
 */
function bridgeAuthorized(): bool {
    $token = bridgeTokenFromHeader();
    $real  = owner()['pair_token'] ?? '';
    return $token !== '' && $real !== '' && hash_equals($real, $token);
}

function bridgeTokenFromHeader(): string {
    // כותרת ייעודית קודם — היא החסינה ביותר. שרתי Apache/cPanel רבים
    // משמיטים את Authorization לפני שהיא מגיעה ל-PHP, ולכן הגשר שולח
    // גם X-Pair-Token, וכאן בודקים אותה ראשונה.
    $x = (string) ($_SERVER['HTTP_X_PAIR_TOKEN'] ?? '');
    if ($x !== '') return trim($x);

    // Authorization — קודם מ-$_SERVER, ואם הושמטה, מ-getallheaders()
    // (שם היא לעיתים כן נשמרת), ולבסוף מהמשתנה שהוספנו ב-.htaccess.
    $auth = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $auth = $v; break; }
            if (strcasecmp($k, 'X-Pair-Token') === 0 && $v !== '') return trim($v);
        }
    }
    if (stripos($auth, 'Bearer ') === 0) return trim(substr($auth, 7));
    return '';
}

function requireBridge(): void {
    if (!bridgeAuthorized()) {
        // רושם *אילו* כותרות הגיעו — כך מתגלה מיד אם Apache השמיט את
        // Authorization, בלי לחשוף את האסימון עצמו.
        logEvent('bridge', 'auth', '401', [
            'has_authorization'          => isset($_SERVER['HTTP_AUTHORIZATION']),
            'has_redirect_authorization' => isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']),
            'has_x_pair_token'           => isset($_SERVER['HTTP_X_PAIR_TOKEN']),
        ]);
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'אסימון צימוד שגוי'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/** מגלגל אסימון צימוד חדש — כשמכשיר אבד או האסימון נחשד כדלף. */
function rotatePairToken(): string {
    $t = bin2hex(random_bytes(24));
    updateOwner(['pair_token' => $t]);
    return $t;
}
