<?php
/**
 * הכניסה דרך הכלים הדיגיטליים, כשהאפליקציה מחוברת לשער (platform/docs/auth.md, sso.md סעיף 0).
 *
 * השער כותב לכל בקשת PHP את $_SERVER['PLATFORM_USER'] — JSON של {sub, name, email, email_verified} —
 * ורק למשתמש מחובר לפלטפורמה שיש לו הרשאה לאפליקציה. המפתח אינו HTTP_*, ולכן לקוח לא יכול לזייף אותו.
 * כשהוא קיים, הוא קובע מי המשתמש — בלי מסך ובלי סיסמה:
 *
 *   1. חשבון שמקושר ל-sub.
 *   2. אין — חשבון באותו דוא"ל שעוד לא מקושר (רק כשהפלטפורמה אימתה את הדוא"ל): מתקשר מעצמו.
 *   3. אין — מסך חיבור חד-פעמי (auth.md, סעיף 4): "יש לי כבר חשבון" (שם משתמש וסיסמה ישנים, פעם אחת)
 *      או "אין לי חשבון" (חשבון חדש, פעיל — ההרשאה בפלטפורמה היא האישור).
 *
 * הסיסמה הישנה לא נמחקת: כל עוד האפליקציה מחוברת הכניסה בסיסמה חסומה (api.php), וכשהיא מנותקת היא
 * עובדת שוב. בלי PLATFORM_USER — הכול כמו קודם, חוץ מסשן שנפתח דרך הפלטפורמה: הוא נסגר (יציאה
 * מהפלטפורמה היא יציאה מהאפליקציה).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/errors.php';

const PLATFORM_LINK_MAX_FAILS   = 5;
const PLATFORM_LINK_LOCK_SECONDS = 900;

/** הזהות מהשער, או null כשאין (אפליקציה מנותקת, משתמש לא מחובר, CLI). */
function platformIdentity(): ?array {
    $raw = $_SERVER['PLATFORM_USER'] ?? null;
    if (!is_string($raw) || $raw === '') return null;
    $id = json_decode($raw, true);
    if (!is_array($id) || !is_string($id['sub'] ?? null) || $id['sub'] === '') return null;
    return [
        'sub'            => $id['sub'],
        'name'           => is_string($id['name'] ?? null) ? $id['name'] : '',
        'email'          => is_string($id['email'] ?? null) ? $id['email'] : '',
        'email_verified' => !empty($id['email_verified']),
    ];
}

/**
 * החשבון של הזהות: לפי sub, ואחר כך לפי דוא"ל מאומת (ומקשר אותו). null — אין חשבון, ומוצג מסך החיבור.
 * מחזיר גם חשבון חסום; החסימה נבדקת אצל הקורא.
 */
function platformAccount(array $id): ?array {
    $st = db()->prepare('SELECT * FROM users WHERE platform_sub = ?');
    $st->execute([$id['sub']]);
    if ($u = $st->fetch()) return $u;

    if ($id['email_verified'] && $id['email'] !== '') {
        $st = db()->prepare('SELECT * FROM users WHERE lower(email) = lower(?) AND platform_sub IS NULL');
        $st->execute([$id['email']]);
        if ($u = $st->fetch()) {
            db()->prepare('UPDATE users SET platform_sub = ? WHERE id = ?')->execute([$id['sub'], $u['id']]);
            logEvent('info', 'platform-link', 'חשבון קושר לפי דוא"ל', ['user_id' => (int) $u['id']]);
            $u['platform_sub'] = $id['sub'];
            return $u;
        }
    }
    return null;
}

/** "אין לי חשבון" — חשבון חדש, פעיל ומאומת. שם המשתמש נגזר מהדוא"ל; הסיסמה אקראית ולא ידועה לאיש. */
function platformCreateAccount(array $id): array {
    if ($u = platformAccount($id)) return $u;

    $base = strtolower(preg_replace('/[^A-Za-z0-9._-]/', '', explode('@', $id['email'])[0] ?? ''));
    if (strlen($base) < 3) $base = 'user';
    $base = substr($base, 0, 26);
    $username = $base;
    $st = db()->prepare('SELECT 1 FROM users WHERE username = ?');
    for ($i = 2; ; $i++) {
        $st->execute([$username]);
        if (!$st->fetchColumn()) break;
        $username = $base . $i;
    }
    // בלי דוא"ל מהפלטפורמה — כתובת שלא תתנגש (השדה חובה וייחודי); איפוס סיסמה לא יגיע אליה
    $email = $id['email'] !== '' ? $id['email'] : 'platform-' . preg_replace('/[^A-Za-z0-9]/', '', $id['sub']) . '@invalid';
    $role = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0 ? 'admin' : 'user';
    try {
        db()->prepare('INSERT INTO users (username, email, password_hash, display_name, role, email_verified, created_at, platform_sub)
                       VALUES (?,?,?,?,?,1,?,?)')
            ->execute([$username, $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                       trim($id['name']) !== '' ? mb_substr(trim($id['name']), 0, 60) : $username, $role, nowIso(), $id['sub']]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            // הדוא"ל שייך לחשבון שכבר מקושר לחשבון פלטפורמה אחר
            throw new AppError('כתובת הדוא"ל שלך כבר שייכת לחשבון אחר באפליקציה. אם הוא שלך — "יש לי כבר חשבון".', 409);
        }
        throw $e;
    }
    logEvent('info', 'platform-link', 'נפתח חשבון חדש דרך הכלים הדיגיטליים', ['user_id' => (int) db()->lastInsertId()]);
    return platformAccount($id);
}

/**
 * "יש לי כבר חשבון" — שם משתמש (או דוא"ל) וסיסמה ישנים, פעם אחת. החשבון הישן מקבל את ה-sub; חשבון
 * שנפתח קודם דרך "אין לי חשבון" מתנתק ממנו (ונשאר כמו שהוא). חשבון שכבר מקושר — לא.
 * הסיסמה לא נמחקת.
 */
function platformLinkOld(array $id, string $usernameOrEmail, string $password): array {
    $now = time();
    $st = db()->prepare('SELECT fails, locked_until FROM platform_link_attempts WHERE sub = ?');
    $st->execute([$id['sub']]);
    $att = $st->fetch() ?: ['fails' => 0, 'locked_until' => 0];
    if ((int) $att['locked_until'] > $now) {
        $min = (int) ceil(((int) $att['locked_until'] - $now) / 60);
        throw new AppError("יותר מדי ניסיונות. אפשר לנסות שוב בעוד $min דקות.", 429);
    }

    $who = trim($usernameOrEmail);
    $st = db()->prepare('SELECT * FROM users WHERE username = ? OR lower(email) = lower(?)');
    $st->execute([$who, $who]);
    $u = $st->fetch();
    // password_verify גם כשאין משתמש — שהזמן לא יגלה מי רשום (כמו login)
    $hash = $u['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin';
    if (!password_verify($password, $hash) || !$u) {
        $fails = (int) $att['fails'] + 1;
        $lock  = $fails >= PLATFORM_LINK_MAX_FAILS ? $now + PLATFORM_LINK_LOCK_SECONDS : 0;
        db()->prepare('INSERT INTO platform_link_attempts (sub, fails, locked_until) VALUES (?,?,?)
                       ON CONFLICT(sub) DO UPDATE SET fails = excluded.fails, locked_until = excluded.locked_until')
            ->execute([$id['sub'], $lock ? 0 : $fails, $lock]);
        logEvent('warn', 'platform-link', 'חיבור חשבון ישן: שם משתמש או סיסמה שגויים', ['fails' => $fails]);
        throw new AppError($lock ? 'יותר מדי ניסיונות. אפשר לנסות שוב בעוד רבע שעה.' : 'שם משתמש או סיסמה שגויים', $lock ? 429 : 401);
    }
    if ((int) $u['blocked'] === 1) throw new AppError('החשבון חסום', 403);
    if ($u['platform_sub'] !== null && $u['platform_sub'] !== $id['sub']) {
        throw new AppError('החשבון הזה כבר מחובר לחשבון אחר של הכלים הדיגיטליים', 409);
    }

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET platform_sub = NULL WHERE platform_sub = ? AND id != ?')->execute([$id['sub'], $u['id']]);
    $pdo->prepare('UPDATE users SET platform_sub = ?, email_verified = 1 WHERE id = ?')->execute([$id['sub'], $u['id']]);
    $pdo->prepare('DELETE FROM platform_link_attempts WHERE sub = ?')->execute([$id['sub']]);
    $pdo->commit();
    logEvent('info', 'platform-link', 'חשבון ישן חובר לכלים הדיגיטליים', ['user_id' => (int) $u['id']]);
    return platformAccount($id);
}
