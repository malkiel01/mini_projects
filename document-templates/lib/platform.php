<?php
/**
 * התחברות דרך הפלטפורמה ("הכלים הדיגיטליים") — platform/docs/auth.md, ‏sso.md סעיף 0, ‏rollout.md.
 *
 * מחוברת (השער פעיל): מי המשתמש — רק $_SERVER['PLATFORM_USER'], שהשער כותב למי שיש לו הרשאה. כניסה
 * אוטומטית: חשבון מקושר ל-sub ← אחרת אותו מייל (מתקשר) ← אחרת מסך חיבור חד-פעמי ("יש לי חשבון" /
 * "אין לי חשבון" ← חשבון חדש, פעיל). הרשמה, כניסה בסיסמה ו"שכחתי סיסמה" חסומות. הסיסמה הישנה נשארת —
 * רק חסומה, וחוזרת לעבוד כשהאפליקציה מנותקת.
 *
 * מנותקת: הכול כמו קודם (כניסה בסיסמה). לא זמינה (אין תשובה מהפלטפורמה): "הכניסה לא זמינה כרגע".
 * משתתפים מבחוץ (fill.html, קוד אימות) וגישה במפתח API — לא עוברים כאן בכלל.
 */

declare(strict_types=1);

const PLATFORM_CLIENT     = 'document-templates';
const PLATFORM_CONFIG_TTL = 60;    // שניות — כמו ה-Cache-Control של sso.php?action=config
const PLATFORM_RETRY_TTL  = 15;    // אחרי כישלון — לא מכבידים על הפלטפורמה בכל בקשה

/** הזהות שהשער העביר, או null. */
function platform_identity(): ?array
{
    $j = json_decode((string)($_SERVER['PLATFORM_USER'] ?? ''), true);
    return is_array($j) && (string)($j['sub'] ?? '') !== '' ? $j : null;
}

/** 'connected' | 'detached' | 'unavailable'. */
function platform_mode(): string
{
    if (platform_identity()) return 'connected';   // השער כתב זהות — מחוברת, בלי לשאול
    $env = getenv('DT_PLATFORM');                  // בדיקות ושרת פיתוח מקומי
    if (in_array($env, ['connected', 'detached', 'unavailable'], true)) return $env;
    if (PHP_SAPI === 'cli') return 'detached';
    static $mode = null;
    if ($mode !== null) return $mode;
    $cache = DT_DATA_DIR . '/platform-config.json';
    $c = is_file($cache) ? json_decode((string)@file_get_contents($cache), true) : null;
    if (is_array($c) && time() - (int)($c['at'] ?? 0) < ($c['mode'] === 'unavailable' ? PLATFORM_RETRY_TTL : PLATFORM_CONFIG_TTL)) {
        return $mode = (string)$c['mode'];
    }
    $mode = platform_fetch_mode();
    @file_put_contents($cache, json_encode(['at' => time(), 'mode' => $mode]));
    return $mode;
}

/** sso.php?action=config — הפלטפורמה שולטת, האפליקציה מקיימת. */
function platform_fetch_mode(): string
{
    if (!function_exists('curl_init')) return 'unavailable';
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string)($_SERVER['SERVER_NAME'] ?? '');   // לא Host מהבקשה — הלקוח לא בוחר לאן השרת פונה
    if ($host === '') return 'unavailable';
    $url = ($https ? 'https' : 'http') . '://' . $host . platform_root() . 'platform/sso.php?action=config&client=' . PLATFORM_CLIENT;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = is_string($raw) && $code === 200 ? json_decode($raw, true) : null;
    if (!is_array($j) || !array_key_exists('connected', $j)) return 'unavailable';
    return $j['connected'] ? 'connected' : 'detached';
}

/** /mini_projects/ — התיקייה שמעל האפליקציה (שם הפלטפורמה). */
function platform_root(): string
{
    $app = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return rtrim(str_replace('\\', '/', dirname($app ?: '/')), '/') . '/';
}

/** מסך הכניסה של הפלטפורמה, וחזרה לאפליקציה. הדפדפן מוסיף את ה-# (הזמנה, מסמך). */
function platform_login_url(): string
{
    return platform_root() . '?next=' . rawurlencode(cookie_path());
}

/** חשבון פעיל או ממתין — לפי sub, ואחר כך לפי מייל (ומקשר). null: צריך מסך חיבור. */
function platform_find_user(array $id): ?array
{
    $st = db()->prepare("SELECT * FROM users WHERE platform_sub = ? AND status != 'deleted'");
    $st->execute([(string)$id['sub']]);
    if ($u = $st->fetch()) return $u;
    $email = mb_strtolower(trim((string)($id['email'] ?? '')));
    if ($email === '') return null;
    // המייל קובע (sso.md סעיף 4). גם חשבון שנדחה — ההרשאה בפלטפורמה היא האישור
    $st = db()->prepare('SELECT * FROM users WHERE email = ? AND platform_sub IS NULL');
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u) return null;
    platform_bind((int)$u['id'], (string)$id['sub'], 'email');
    return platform_user((int)$u['id']);
}

function platform_user(int $id): array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

/** קישור חשבון ל-sub. ההרשאה בפלטפורמה היא האישור — חשבון שחיכה לאישור (או לאימות) נעשה פעיל. */
function platform_bind(int $userId, string $sub, string $how): void
{
    db()->prepare("UPDATE users SET platform_sub = ?, platform_linked_at = ?,
        status = CASE WHEN status IN ('pending', 'unverified', 'deleted') THEN 'active' ELSE status END WHERE id = ?")
        ->execute([$sub, now_iso(), $userId]);
    error_log("document-templates: user $userId linked to platform sub $sub ($how)");
}

/**
 * מסנכרן את ה-session של האפליקציה עם הפלטפורמה, בכל בקשה. מחזיר [session או null, מידע ל-me].
 * מנותקת — לא נוגעים: העוגייה של האפליקציה כמו קודם.
 */
function platform_sync(): array
{
    $mode = platform_mode();
    if ($mode === 'detached') return [current_session(), ['mode' => 'detached']];
    $info = ['mode' => $mode, 'login_url' => platform_login_url()];
    $cookie = current_session();
    $id = platform_identity();
    $drop = function () use (&$cookie) {
        if ($cookie) db()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([$cookie['token_hash']]);
        if ($cookie || isset($_COOKIE[DT_COOKIE])) set_session_cookie('', time() - 3600);
        $cookie = null;
    };
    // אין זהות (דף ציבורי, או שיצא מהפלטפורמה) — אין משתמש. יציאה מהפלטפורמה = יציאה מכאן
    if (!$id) { $drop(); return [null, $info]; }

    $u = platform_find_user($id);
    if (!$u) {
        $drop();
        return [null, $info + ['link' => true, 'name' => (string)($id['name'] ?? ''), 'email' => (string)($id['email'] ?? '')]];
    }
    if ($u['status'] === 'blocked') { $drop(); return [null, $info + ['blocked' => 'החשבון שלך באפליקציה חסום. פנו למנהל המערכת']]; }
    if ($u['status'] !== 'active') { platform_bind((int)$u['id'], (string)$id['sub'], 'activate'); $u = platform_user((int)$u['id']); }

    if ($cookie && (int)$cookie['id'] === (int)$u['id']) return [$cookie, $info];
    $drop();   // session של מישהו אחר (מישהו אחר נכנס לפלטפורמה במכשיר הזה) — מחליפים
    return [platform_open_session($u), $info];
}

/** session חדש לחשבון — כמו כניסה בסיסמה, בלי הסיסמה. */
function platform_open_session(array $u): ?array
{
    $token = bin2hex(random_bytes(32));
    $now = now_iso();
    db()->prepare(
        'INSERT INTO sessions (token_hash, user_id, csrf, device, ip, created_at, last_seen_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([token_hash($token), $u['id'], bin2hex(random_bytes(16)),
        mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), client_ip(), $now, $now, iso_in(SESSION_DAYS * 86400)]);
    db()->prepare('UPDATE users SET last_login_at = ?, last_seen_at = ? WHERE id = ?')->execute([$now, $now, $u['id']]);
    set_session_cookie($token, time() + SESSION_DAYS * 86400);
    $_COOKIE[DT_COOKIE] = $token;
    return current_session($token);
}

/** החשבון נפתח דרך הפלטפורמה ועוד ריק — אפשר עדיין להחליף אותו בחשבון ישן (מתפריט המשתמש). */
function platform_can_relink(array $s): bool
{
    if (($s['created_via'] ?? '') !== 'platform') return false;
    foreach (['templates', 'documents', 'contacts'] as $t) {
        $st = db()->prepare("SELECT 1 FROM $t WHERE owner_id = ? LIMIT 1");
        $st->execute([$s['id']]);
        if ($st->fetchColumn()) return false;
    }
    return true;
}

/** פעולות מסך החיבור — רק עם זהות מהשער, ורק מהאפליקציה עצמה (כותרת שדפדפן לא שולח מאתר אחר בלי אישור). */
function platform_require(): array
{
    $id = platform_identity();
    if (!$id) throw new ApiError('החיבור זמין רק בכניסה דרך הכלים הדיגיטליים', 403);
    if (($_SERVER['HTTP_X_DT_PLATFORM'] ?? '') !== '1') throw new ApiError('הבקשה נדחתה', 403);
    return $id;
}

/**
 * "יש לי כבר חשבון": מייל וסיסמה ישנים — פעם אחת. 5 ניסיונות ← נעילה של רבע שעה. החשבון הישן מתקשר
 * ל-sub; חשבון שכבר מקושר לא ניתן לקשר שוב. הסיסמה לא נמחקת.
 */
function platform_link(?array $session, array $b): array
{
    $id = platform_require();
    $sub = (string)$id['sub'];
    $current = $session ? platform_user((int)$session['id']) : null;
    if ($current && !platform_can_relink($current)) throw new ApiError('החשבון כבר מחובר, ויש בו נתונים — אי אפשר להחליף', 409);
    if (!$current && platform_find_user($id)) throw new ApiError('החשבון כבר מחובר. רעננו את הדף', 409);

    $keys = ['link:' . $sub];
    if (($wait = lock_remaining($keys)) > 0) throw new ApiError('יותר מדי ניסיונות. נסו שוב בעוד ' . (int)ceil($wait / 60) . ' דקות', 429);
    $st = db()->prepare("SELECT * FROM users WHERE email = ? AND status != 'deleted'");
    $st->execute([mb_strtolower(trim((string)($b['email'] ?? '')))]);
    $old = $st->fetch();
    $real = $old && str_starts_with((string)$old['password_hash'], '$');
    $ok = password_verify((string)($b['password'] ?? ''), $real ? $old['password_hash'] : password_hash('-', PASSWORD_DEFAULT)) && $real;
    if (!$ok || $old['platform_sub'] !== null || ($current && (int)$old['id'] === (int)$current['id'])) {
        record_failure($keys, LOGIN_MAX_FAILS);
        throw new ApiError($ok ? 'החשבון הזה כבר מחובר לחשבון אחר בכלים הדיגיטליים' : 'מייל או סיסמה שגויים', $ok ? 409 : 401);
    }
    if ($old['status'] === 'blocked') throw new ApiError('החשבון הזה חסום. פנו למנהל המערכת', 403);
    clear_failures($keys);

    $pdo = db();
    $pdo->beginTransaction();
    if ($current) {
        // החשבון שנפתח לבד (ריק) — כבר לא מקושר לאיש
        $pdo->prepare("UPDATE users SET platform_sub = NULL, status = 'deleted' WHERE id = ?")->execute([$current['id']]);
        $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$current['id']]);
    }
    platform_bind((int)$old['id'], $sub, 'password');
    $pdo->commit();
    return ['ok' => true, 'name' => $old['display_name']];
}

/** "אין לי חשבון": חשבון חדש, פעיל — כמו הרשמה רגילה, בלי אישור נוסף (rollout.md, הכרעה 2). */
function platform_new(array $b): array
{
    $id = platform_require();
    if (platform_find_user($id)) throw new ApiError('כבר יש לך חשבון. רעננו את הדף', 409);
    $email = mb_strtolower(trim((string)($id['email'] ?? '')));
    if ($email === '') $email = 'platform-' . preg_replace('/[^a-z0-9_-]/i', '_', (string)$id['sub']) . '@users.invalid';
    $name = trim((string)($id['name'] ?? '')) ?: strtok($email, '@');
    $pdo = db();
    // כמו בהרשמה: החשבון הראשון במערכת הוא חשבון המפתח
    $first = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_developer = 1')->fetchColumn() === 0;
    $pdo->prepare("INSERT INTO users (email, password_hash, display_name, is_developer, status, email_verified_at, created_at, created_via)
        VALUES (?, '!', ?, ?, 'active', ?, ?, 'platform')")
        ->execute([$email, mb_substr($name, 0, 60), $first ? 1 : 0, now_iso(), now_iso()]);
    platform_bind((int)$pdo->lastInsertId(), (string)$id['sub'], 'new');
    return ['ok' => true];
}

/** פעולות שאינן קיימות כשהכניסה דרך הפלטפורמה. */
function platform_guard(string $action): void
{
    if (!in_array($action, ['register', 'verify', 'login', 'forgot', 'reset', 'me_password'], true)) return;
    $mode = platform_mode();
    if ($mode === 'connected') throw new ApiError('הכניסה היא דרך הכלים הדיגיטליים', 409);
    if ($mode === 'unavailable') throw new ApiError('הכניסה לא זמינה כרגע. נסו שוב בעוד כמה דקות', 503);
}
