<?php
/**
 * כניסה דרך הכלים הדיגיטליים
 * includes/platform.php
 *
 * החוק: platform/docs/auth.md. המדריך: platform/docs/sso.md, סעיפים 0, 4 ו-6.
 *
 * כשהאפליקציה מחוברת (מאחורי השער), השער כותב את הזהות ל-
 * $_SERVER['PLATFORM_USER'] בכל בקשת PHP - ורק למי שיש לו הרשאה.
 * המפתח אינו HTTP_*, ולכן לקוח לא יכול לזייף אותו בכותרת.
 *
 * הזהות קובעת מי המשתמש, בכל בקשה מחדש. ה-session של האפליקציה הוא
 * רק מטמון שלה: שייך ל-sub אחר (מישהו אחר נכנס לפלטפורמה) - מוחלף.
 * אין זהות - האפליקציה מנותקת מהשער, והכניסה הישנה (סיסמה / Google)
 * חוזרת לעבוד כמו שהייתה.
 *
 * הקישור לחשבון הקיים, לפי הסדר:
 *   1. users.platform_sub = sub
 *   2. מייל *מאומת* זהה (בלי תלות באותיות) לחשבון אחד בדיוק שעוד לא
 *      מקושר. מייל שלא אומת אינו הוכחת זהות - אחרת אפשר היה לתפוס
 *      חשבון בהרשמה לפלטפורמה עם המייל של מישהו אחר.
 *   3. אחרת - מסך חד-פעמי (auth/link.php): "יש לי כבר חשבון" (שם
 *      משתמש וסיסמה ישנים) או "אין לי חשבון" (חשבון חדש, פעיל).
 *
 * הסיסמה הישנה לא נמחקת. היא רק חסומה כל עוד האפליקציה מחוברת.
 */

const PLATFORM_CLIENT        = 'family-shopping';
const PLATFORM_HOME          = '/mini_projects/platform/home/';
const PLATFORM_PROVISION_URL = 'https://mbe-plus.com/mini_projects/platform/provision.php';
const PLATFORM_KID           = 'fs1';

/**
 * הזהות מהשער: sub, name, email (באותיות קטנות) ו-email_verified.
 * null - אין זהות, כלומר האפליקציה מנותקת מהשער.
 */
function platformIdentity() {
    $id  = json_decode((string)($_SERVER['PLATFORM_USER'] ?? ''), true);
    $sub = (string)($id['sub'] ?? '');

    if ($sub === '') {
        return null;
    }

    return [
        'sub'            => $sub,
        'name'           => trim((string)($id['name'] ?? '')),
        'email'          => mb_strtolower(trim((string)($id['email'] ?? ''))),
        'email_verified' => ($id['email_verified'] ?? false) === true,
    ];
}

/**
 * החשבון המקושר לזהות - לפי sub, ואם אין, לפי מייל מאומת (ואז הוא
 * מתקשר). null - אין התאמה אחת ברורה, וצריך את המסך החד-פעמי.
 *
 * מוחזר גם חשבון מושבת: מי שקורא מחליט מה לעשות בו, וחשבון מושבת
 * אסור שיגרום לפתיחת חשבון חדש באותו מייל.
 */
function platformLinkedUser(PDO $pdo, array $id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE platform_sub = ?");
    $stmt->execute([$id['sub']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        return $user;
    }

    if ($id['email'] === '' || !$id['email_verified']) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT * FROM users
        WHERE platform_sub IS NULL AND LOWER(TRIM(email)) = ?
        ORDER BY id
    ");
    $stmt->execute([$id['email']]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // שני חשבונות באותו מייל (למשל אחד בסיסמה ואחד ב-Google) - אין
    // דרך לדעת איזה מהם. המסך החד-פעמי נותן למשתמש לבחור בסיסמה.
    if (count($matches) !== 1) {
        return null;
    }

    platformLink($pdo, (int)$matches[0]['id'], $id['sub'], 'email');
    $matches[0]['platform_sub'] = $id['sub'];

    return $matches[0];
}

/**
 * מקשר חשבון ל-sub, ורושם ביומן. מי שהיה מקושר ל-sub הזה קודם
 * (חשבון שנפתח דרך הפלטפורמה לפני שנזכרו בישן) נשאר, לא מקושר לאיש.
 */
function platformLink(PDO $pdo, $userId, $sub, $how) {
    $pdo->prepare("UPDATE users SET platform_sub = NULL WHERE platform_sub = ? AND id != ?")
        ->execute([$sub, (int)$userId]);
    $pdo->prepare("UPDATE users SET platform_sub = ? WHERE id = ?")
        ->execute([$sub, (int)$userId]);

    platformLog($pdo, $userId, 'platform_link', "$how, sub $sub");
}

/** רישום ביומן הפעולות של ממשק הניהול. כישלון ברישום לא עוצר דבר */
function platformLog(PDO $pdo, $userId, $action, $details) {
    try {
        $pdo->prepare("
            INSERT INTO admin_actions (admin_id, action, target_type, target_id, details)
            VALUES (?, ?, 'user', ?, ?)
        ")->execute([(int)$userId, $action, (int)$userId, $details]);
    } catch (Exception $e) {
        error_log("platform log ($action): " . $e->getMessage());
    }
}

/** שם משתמש פנוי לחשבון חדש - מהמייל, ואם תפוס, עם סיומת */
function platformUsername(PDO $pdo, array $id) {
    $local = $id['email'] !== '' ? strstr($id['email'], '@', true) : '';
    $base  = substr(preg_replace('/[^a-zA-Z0-9._\-]/', '', (string)$local), 0, 40);

    if (strlen($base) < 3) {
        $base = 'user' . preg_replace('/\D/', '', $id['sub']);
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    for ($i = 2, $name = $base; ; $i++) {
        $stmt->execute([$name]);
        if (!$stmt->fetch()) {
            return $name;
        }
        $name = $base . $i;
    }
}

/**
 * "אין לי חשבון": חשבון חדש, פעיל - ההרשאה בפלטפורמה היא האישור
 * (auth.md, סעיף 3). בלי סיסמה: הכניסה היא דרך הפלטפורמה.
 */
function platformCreateUser(PDO $pdo, array $id) {
    $name = $id['name'] !== '' ? $id['name'] : ($id['email'] !== '' ? $id['email'] : 'משתמש');

    $pdo->prepare("
        INSERT INTO users (username, email, name, is_active, platform_sub, created_at, last_login)
        VALUES (?, ?, ?, 1, ?, NOW(), NOW())
    ")->execute([
        platformUsername($pdo, $id),
        $id['email'] !== '' ? $id['email'] : null,
        mb_substr($name, 0, 100),
        $id['sub'],
    ]);

    $userId = (int)$pdo->lastInsertId();
    platformLog($pdo, $userId, 'platform_create', 'sub ' . $id['sub']);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * "יש לי כבר חשבון": שם המשתמש (או המייל) והסיסמה הישנים, פעם אחת.
 * מחזיר מחרוזת שגיאה, או '' בהצלחה.
 *
 * הגבלת הקצב היא של מסך הכניסה הישן (login_attempts), לפי ה-sub:
 * חמישה ניסיונות ברבע שעה.
 */
function platformLinkOldAccount(PDO $pdo, array $id, $login, $password) {
    $login = trim((string)$login);
    $key   = 'link:' . $id['sub'];

    if (function_exists('loginBlockedFor') && ($wait = loginBlockedFor($pdo, $key)) > 0) {
        return loginBlockedMessage($wait);
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ? ORDER BY id LIMIT 1");
    $stmt->execute([$login, $login]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);

    $ok = $old && !empty($old['password']) && password_verify((string)$password, $old['password']);

    if (function_exists('recordLoginAttempt')) {
        recordLoginAttempt($pdo, $key, $ok);
    }

    if (!$ok) {
        return 'שם משתמש או סיסמה שגויים';
    }
    if (!empty($old['platform_sub'])) {
        return $old['platform_sub'] === $id['sub'] ? '' : 'החשבון הזה כבר מחובר לחשבון אחר בכלים הדיגיטליים';
    }
    if (empty($old['is_active'])) {
        return 'החשבון הזה מושבת. פנו למנהל המערכת';
    }

    platformLink($pdo, (int)$old['id'], $id['sub'], 'password');

    return '';
}

/** ממלא את ה-session מהחשבון - אותם מפתחות שהכניסה הישנה כתבה */
function platformLogin(array $user, $sub) {
    if ((int)($_SESSION['user_id'] ?? 0) !== (int)$user['id']) {
        regenerateSessionAfterLogin();
    }

    $_SESSION['user_id']         = $user['id'];
    $_SESSION['username']        = $user['username'];
    $_SESSION['name']            = $user['name'];
    $_SESSION['email']           = $user['email'];
    $_SESSION['profile_picture'] = $user['profile_picture'] ?? null;
    $_SESSION['auth_type']       = 'platform';
    $_SESSION['platform_sub']    = $sub;
}

/** מוחק את המשתמש מה-session, ומשאיר את ה-CSRF ואת יעד החזרה */
function platformForgetUser() {
    foreach (['user_id', 'username', 'name', 'email', 'profile_picture',
              'auth_type', 'platform_sub', 'login_time', 'last_activity', 'user_agent'] as $key) {
        unset($_SESSION[$key]);
    }
}

/**
 * מסנכרן את ה-session עם הזהות מהשער. רץ בכל בקשה (session.php).
 *
 * יש זהות וחשבון מקושר פעיל - הוא המשתמש. יש זהות ואין חשבון (או
 * שהוא מושבת) - אין משתמש, ומי שדורש משתמש מפנה למסך החיבור. אין
 * זהות - לא נוגעים: האפליקציה מנותקת, והכניסה הישנה עובדת.
 */
function platformSyncSession() {
    $id = platformIdentity();
    if (!$id || !function_exists('getDBConnection')) {
        return;
    }

    try {
        $user = platformLinkedUser(getDBConnection(), $id);
    } catch (Exception $e) {
        // עמודת platform_sub עוד לא קיימת (מיגרציה 015 לא רצה) - אין
        // משתמש, ולא כניסה בטעות לחשבון של מישהו אחר
        error_log('platform sync: ' . $e->getMessage());
        $user = null;
    }

    if ($user && !empty($user['is_active'])) {
        platformLogin($user, $id['sub']);
    } else {
        platformForgetUser();
    }
}

// ============================================================
// הזמנה לפלטפורמה (sso.md, סעיף 6)
// ============================================================
//
// האפליקציה פותחת למוזמן חשבון בפלטפורמה עם הרשאה אליה בלבד, והפלטפורמה
// שולחת לו מייל לבחירת סיסמה. הבקשה חתומה במפתח Ed25519 של האפליקציה;
// הסודי במסד (app_settings), והציבורי ב-api/platform-key.php.

/** המפתח הסודי - נוצר בפעם הראשונה ונשמר במסד */
function provisionSecret(PDO $pdo) {
    $stmt = $pdo->prepare("SELECT value FROM app_settings WHERE name = 'provision_sk'");
    $stmt->execute();
    $sk = base64_decode((string)$stmt->fetchColumn(), true);

    if (is_string($sk) && strlen($sk) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        return $sk;
    }

    $sk = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $pdo->prepare("DELETE FROM app_settings WHERE name = 'provision_sk'")->execute();
    $pdo->prepare("INSERT INTO app_settings (name, value) VALUES ('provision_sk', ?)")
        ->execute([base64_encode($sk)]);

    return $sk;
}

/** מה ש-key_url מחזיר לפלטפורמה */
function provisionKeyPayload(PDO $pdo) {
    return [
        'client' => PLATFORM_CLIENT,
        'keys'   => [[
            'kid' => PLATFORM_KID,
            'alg' => 'Ed25519',
            'key' => base64_encode(sodium_crypto_sign_publickey_from_secretkey(provisionSecret($pdo))),
        ]],
    ];
}

/**
 * הזמנה (grant=true) או הסרת ההרשאה (grant=false).
 *
 * @param string        $next לאן המוזמן מגיע אחרי בחירת הסיסמה - נתיב בתוך האפליקציה
 * @param callable|null $send להחלפה בבדיקות: function ($body, $headers): ['status', 'body']
 * @return array [status, sub, error] - status: invited / granted / blocked / revoked / unknown
 */
function platformProvision(PDO $pdo, $email, $name, $grant, $next, $send = null) {
    $email = mb_strtolower(trim((string)$email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['', '', 'כתובת המייל אינה תקינה'];
    }

    $name = trim((string)$name);
    $body = json_encode([
        'email' => $email,
        'name'  => mb_substr($name !== '' ? $name : $email, 0, 60),
        'grant' => (bool)$grant,
        'next'  => $next,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $time  = (string)time();
    $nonce = bin2hex(random_bytes(16));
    $sig   = sodium_crypto_sign_detached(
        "provision\n$time\n$nonce\n" . hash('sha256', $body), provisionSecret($pdo)
    );
    $headers = [
        'Content-Type: application/json',
        'X-Embed-Client: ' . PLATFORM_CLIENT,
        "X-Embed-Time: $time",
        "X-Embed-Nonce: $nonce",
        'X-Embed-Signature: ' . PLATFORM_KID . '.' . base64_encode($sig),
    ];

    $send = $send ?: function ($body, $headers) {
        $ch = curl_init(PLATFORM_PROVISION_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 6,
        ]);
        $out    = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => $out];
    };

    $response = $send($body, $headers);
    $json     = json_decode((string)($response['body'] ?? ''), true);

    if (($response['status'] ?? 0) !== 200 || !is_string($json['status'] ?? null)) {
        error_log('platform provision: HTTP ' . ($response['status'] ?? 0) . ' '
            . substr((string)($response['body'] ?? ''), 0, 300));

        return ['', '', 'הכלים הדיגיטליים לא ענו: ' . ((string)($json['error'] ?? '') ?: 'נסו שוב')];
    }

    return [$json['status'], (string)($json['sub'] ?? ''), ''];
}

/**
 * הזמנה לאירוע: למוזמן נפתח חשבון בפלטפורמה (או ניתנת הרשאה לחשבון
 * שיש לו), והוא מגיע אחרי בחירת הסיסמה ישר לקישור ההצטרפות.
 *
 * רק כשהאפליקציה מחוברת - מנותקת, ההזמנה עובדת כמו שעבדה תמיד.
 * ה-sub נשמר בחשבון באפליקציה, אם יש כזה באותו מייל ועוד לא מקושר.
 *
 * @return array [status, error] - status ריק כשלא נשלחה בקשה
 */
function platformInviteToEvent(PDO $pdo, $email, $name, $token, $send = null) {
    if (!platformIdentity()) {
        return ['', ''];
    }

    $next = APP_BASE_PATH . '/join.php?token=' . rawurlencode($token);

    try {
        [$status, $sub, $error] = platformProvision($pdo, $email, $name, true, $next, $send);
    } catch (Exception $e) {
        error_log('platform invite: ' . $e->getMessage());
        return ['', 'ההזמנה לכלים הדיגיטליים נכשלה'];
    }

    if ($error !== '') {
        return ['', $error];
    }

    if ($sub !== '' && $status !== 'blocked') {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE platform_sub = ?");
        $stmt->execute([$sub]);

        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("
                SELECT id FROM users WHERE platform_sub IS NULL AND LOWER(TRIM(email)) = ?
            ");
            $stmt->execute([mb_strtolower(trim($email))]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($rows) === 1) {
                platformLink($pdo, (int)$rows[0], $sub, 'invite');
            }
        }
    }

    return [$status, ''];
}
