<?php
/**
 * הרשאות. שני ערוצים נפרדים, בכוונה:
 *
 *   המשתמש  — בדפדפן. דרכו נשאלות שאלות ומוגדרים חשבונות. מי הוא:
 *             מחוברת לפלטפורמה — $_SERVER['PLATFORM_USER'] שהשער כותב
 *             (platform/docs/sso.md, סעיף 0): כניסה אוטומטית, בלי מסך.
 *             מנותקת — הכניסה הישנה בסיסמה (רק בסיס: הכרעת בעל המערכת).
 *   הגשר    — אפליקציית האנדרואיד שסורקת את הוואטסאפ. מזדהה באסימון
 *             צימוד בכותרת, לא בעוגייה: היא אינה דפדפן. האסימון הוא של
 *             משתמש אחד, ולכן הוא גם מזהה את המשתמש. הפרדת הערוצים
 *             מבטיחה שאסימון צימוד שדלף יכול רק להזרים הודעות — לא
 *             לקרוא את ההיסטוריה ולא לשנות הגדרות.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const PLATFORM_CONFIG_URL = 'https://mbe-plus.com/mini_projects/platform/sso.php?action=config&client=whatsapp-ai-scanner';

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

/* ── הזהות מהפלטפורמה ─────────────────────────────────────────── */

/**
 * הזהות שהשער כתב, או null. המפתח אינו HTTP_* — לקוח לא יכול לזייף אותו
 * בכותרת. יש בו ערך רק למשתמש מחובר לפלטפורמה עם הרשאה לאפליקציה.
 */
function platformIdentity(): ?array {
    $id = json_decode((string) ($_SERVER['PLATFORM_USER'] ?? ''), true);
    return is_array($id) && (string) ($id['sub'] ?? '') !== '' ? $id : null;
}

/**
 * המשתמש באפליקציה של זהות מהפלטפורמה: לפי sub, ואם אין — לפי מייל (חשבון
 * שעוד לא מקושר), ואם אין — משתמש חדש, פעיל, כמו בהרשמה רגילה (ההרשאה
 * בפלטפורמה היא האישור). החדש מסומן auto, ומקבל את מסך החיבור החד-פעמי.
 */
function userForPlatform(array $identity): array {
    $sub   = (string) $identity['sub'];
    $email = mb_strtolower(trim((string) ($identity['email'] ?? '')));
    $name  = mb_substr(trim((string) ($identity['name'] ?? '')), 0, 120);

    $st = db()->prepare('SELECT * FROM users WHERE platform_sub = ?');
    $st->execute([$sub]);
    $u = $st->fetch();

    if (!$u && $email !== '' && ($identity['email_verified'] ?? true) !== false) {
        $st = db()->prepare("SELECT * FROM users WHERE platform_sub = '' AND email <> '' AND lower(email) = ?
                              ORDER BY id LIMIT 1");
        $st->execute([$email]);
        $u = $st->fetch();
        if ($u) updateUser((int) $u['id'], ['platform_sub' => $sub]);
    }

    if (!$u) {
        try {
            return createUser(['platform_sub' => $sub, 'email' => $email, 'name' => $name, 'auto' => 1]);
        } catch (PDOException $e) {
            // שתי בקשות ראשונות במקביל: השנייה נתקלת באינדקס הייחודי על sub — והראשונה כבר יצרה.
            $st = db()->prepare('SELECT * FROM users WHERE platform_sub = ?');
            $st->execute([$sub]);
            $u = $st->fetch();
            if (!$u) throw $e;
        }
    }

    // השם והמייל מתעדכנים מהפלטפורמה — היא מקור האמת לזהות.
    $sync = [];
    if ($name !== '' && $name !== $u['name'])    $sync['name'] = $name;
    if ($email !== '' && $email !== $u['email']) $sync['email'] = $email;
    if ($sync) updateUser((int) $u['id'], $sync);
    return getUser((int) $u['id']);
}

/**
 * קובע את המשתמש של הבקשה. נקרא פעם אחת, בראש ה-API.
 *
 * יש זהות מהפלטפורמה → היא קובעת, גם אם ב-session יושב מישהו אחר (מישהו
 * אחר נכנס לפלטפורמה — מחליפים). אין זהות → session שנפתח דרך הפלטפורמה
 * נמחק (יציאה מהפלטפורמה = יציאה מהאפליקציה); session של כניסה בסיסמה
 * (אפליקציה מנותקת) נשאר.
 */
function resolveUser(): void {
    $identity = platformIdentity();
    // בקשת גשר (בלי עוגייה ובלי זהות) — לא פותחים לה session לחינם.
    if (!$identity && !isset($_COOKIE['WASCANNER'])) return;
    sessionStart();
    if ($identity) {
        $u = userForPlatform($identity);
        if ((int) ($_SESSION['uid'] ?? 0) !== (int) $u['id'] || ($_SESSION['via'] ?? '') !== 'platform') {
            session_regenerate_id(true);
            $_SESSION = ['uid' => (int) $u['id'], 'via' => 'platform'];
        }
        currentUserId((int) $u['id']);
        return;
    }
    if (($_SESSION['via'] ?? '') === 'platform') {
        $_SESSION = [];
        return;
    }
    $uid = (int) ($_SESSION['uid'] ?? 0);
    if ($uid > 0 && getUser($uid)) currentUserId($uid);
}

function isUser(): bool {
    return currentUserId() > 0;
}

function requireUser(): void {
    if (!isUser()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'נדרשת כניסה'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* ── הכניסה הישנה בסיסמה — רק כשהאפליקציה מנותקת ─────────────── */

/**
 * האם הכניסה בסיסמה פתוחה. מחוברת (יש זהות) — לעולם לא. מנותקת — לפי
 * ההגדרה בניהול הפלטפורמה (sso.php?action=config, login.password), שנשמרת
 * לדקה. הפלטפורמה לא זמינה — ההגדרה האחרונה; אף פעם לא נקראה — פתוח, כדי
 * שאפליקציה שיצאה לדרך עצמאית לא תינעל.
 */
function passwordLoginAllowed(): bool {
    if (platformIdentity()) return false;
    $cache = dirname(DB_FILE) . '/login-config.json';
    $cached = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
    $login = $cached['login'] ?? null;
    if (!is_array($cached) || time() - (int) ($cached['at'] ?? 0) > 60) {
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
        $raw = @file_get_contents(PLATFORM_CONFIG_URL, false, $ctx);
        $config = $raw ? json_decode($raw, true) : null;
        if (is_array($config['login'] ?? null)) {
            $login = $config['login'];
            @file_put_contents($cache, json_encode(['at' => time(), 'login' => $login]));
        }
    }
    return !(is_array($login) && empty($login['password']));
}

/** התקנה ראשונה (מנותקת, ואין עדיין אף משתמש): המשתמש הראשון והסיסמה שלו. */
function setupFirstUser(string $password): void {
    if (anyUser()) {
        throw new InvalidArgumentException('כבר הוגדר');
    }
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('הסיסמה חייבת להיות באורך 8 תווים לפחות');
    }
    createUser(['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
}

/** המשתמש שהסיסמה שלו זו, מבין אלה שיש להם סיסמה. */
function userByPassword(string $password, bool $unlinkedOnly = false): ?array {
    if ($password === '') return null;
    $sql = "SELECT * FROM users WHERE password_hash <> ''" . ($unlinkedOnly ? " AND platform_sub = ''" : '') . ' ORDER BY id';
    foreach (db()->query($sql)->fetchAll() as $u) {
        if (password_verify($password, $u['password_hash'])) return $u;
    }
    return null;
}

function login(string $password): bool {
    $u = userByPassword($password);
    if (!$u) return false;
    sessionStart();
    session_regenerate_id(true);
    $_SESSION = ['uid' => (int) $u['id'], 'via' => 'password'];
    currentUserId((int) $u['id']);
    return true;
}

function logout(): void {
    sessionStart();
    $_SESSION = [];
    session_destroy();
}

/* ── מסך החיבור החד-פעמי (platform/docs/auth.md, סעיף 4) ──────── */

/**
 * משתמש שנפתח לבד בכניסה דרך הפלטפורמה — אליו עוד אפשר לחבר חשבון ישן. רק
 * כשיש בכלל חשבון ישן שעוד לא חובר; אחרי שכולם חוברו, אף אחד לא נשאל.
 */
function canLinkOld(): bool {
    $me = me();
    if (platformIdentity() === null || (int) ($me['auto'] ?? 0) !== 1) return false;
    return (bool) db()->query("SELECT 1 FROM users WHERE password_hash <> '' AND platform_sub = '' LIMIT 1")->fetchColumn();
}

/**
 * "יש לי כבר חשבון": הסיסמה הישנה מחברת את החשבון הישן ל-sub, והנתונים של
 * החשבון שנפתח לבד (אם הספיק ליצור) עוברים אליו. 5 ניסיונות שגויים → נעילה
 * של רבע שעה. חשבון שכבר מקושר — לא ניתן לקשר שוב.
 *
 * הסיסמה הישנה לא נמחקת — רק חסומה כל עוד האפליקציה מחוברת.
 */
function linkOldAccount(string $password): array {
    $identity = platformIdentity();
    if (!$identity) throw new InvalidArgumentException('החיבור זמין רק בכניסה דרך הכלים הדיגיטליים');
    $auto = me();
    if ((int) ($auto['auto'] ?? 0) !== 1) throw new InvalidArgumentException('כבר חובר חשבון');

    $locked = (int) $auto['link_locked_until'];
    if ($locked > time()) {
        throw new InvalidArgumentException('יותר מדי ניסיונות. נסו שוב בעוד ' . (int) ceil(($locked - time()) / 60) . ' דקות');
    }

    $old = userByPassword($password, true);
    if (!$old || (int) $old['id'] === (int) $auto['id']) {
        $fails = (int) $auto['link_fails'] + 1;
        updateUser((int) $auto['id'], $fails >= 5
            ? ['link_fails' => 0, 'link_locked_until' => time() + 15 * 60]
            : ['link_fails' => $fails]);
        throw new InvalidArgumentException('הסיסמה שגויה');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $a = (int) $auto['id'];
        $o = (int) $old['id'];
        $pdo->prepare('UPDATE accounts SET user_id = ? WHERE user_id = ?')->execute([$o, $a]);
        $pdo->prepare('UPDATE logs SET user_id = ? WHERE user_id = ?')->execute([$o, $a]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$a]);
        $fields = [
            'platform_sub' => (string) $identity['sub'],
            'email'        => $old['email'] !== '' ? $old['email'] : $auto['email'],
            'name'         => $old['name'] !== '' ? $old['name'] : $auto['name'],
            'link_fails'   => 0,
        ];
        // מפתח AI שהוגדר בחשבון החדש לא הולך לאיבוד, אם לישן אין.
        if ($old['ai_key_enc'] === '' && $auto['ai_key_enc'] !== '') {
            $fields += ['ai_provider' => $auto['ai_provider'], 'ai_model' => $auto['ai_model'],
                        'ai_key_enc' => $auto['ai_key_enc']];
        }
        updateUser($o, $fields);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        $_SESSION = ['uid' => (int) $old['id'], 'via' => 'platform'];
    }
    currentUserId((int) $old['id']);
    return me();
}

/** "אין לי חשבון" — ההצעה לא חוזרת; נשארת בהגדרות למי שנזכר אחר כך. */
function dismissLink(): void {
    updateMe(['link_dismissed' => 1]);
}

/* ── אסימונים: גשר ויומן ──────────────────────────────────────── */

/** המשתמש שהאסימון שלו זה. השוואה בזמן קבוע אחרי השליפה. */
function userByToken(string $column, string $token): ?array {
    if ($token === '' || !in_array($column, ['pair_token', 'log_token'], true)) return null;
    $st = db()->prepare("SELECT * FROM users WHERE $column = ?");
    $st->execute([$token]);
    $u = $st->fetch();
    return $u && hash_equals((string) $u[$column], $token) ? $u : null;
}

/** אסימון קריאת היומן של המשתמש הנוכחי. נוצר בעצלתיים אם חסר. */
function myLogToken(): string {
    $t = me()['log_token'] ?? '';
    if ($t === '') $t = rotateLogToken();
    return $t;
}

/** אסימון יומן תקין → המשתמש שלו הופך לנוכחי (היומן שנקרא הוא שלו). */
function useLogToken(string $t): bool {
    $u = userByToken('log_token', $t);
    if (!$u) return false;
    currentUserId((int) $u['id']);
    return true;
}

function rotateLogToken(): string {
    $t = bin2hex(random_bytes(24));
    updateMe(['log_token' => $t]);
    return $t;
}

/**
 * מזהה את הגשר — ואת המשתמש — לפי אסימון הצימוד. מכאן והלאה הבקשה פועלת
 * בשם המשתמש של האסימון: הגשר רואה וקולט רק לחשבונות שלו.
 */
function bridgeAuthorized(): bool {
    $u = userByToken('pair_token', bridgeTokenFromHeader());
    if (!$u) return false;
    currentUserId((int) $u['id']);
    return true;
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
        // Authorization, בלי לחשוף את האסימון עצמו. בלי משתמש (user_id 0):
        // אסימון שגוי אינו שייך לאף אחד.
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
    updateMe(['pair_token' => $t]);
    return $t;
}
