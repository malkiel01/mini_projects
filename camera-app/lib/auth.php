<?php
/**
 * חשבונות וכניסה.
 *
 * אין הרשמה חופשית — זו מערכת שמראה את הבית של מישהו. המשתמש הראשון
 * נוצר במסך ההתקנה ונעשה מנהל; את כל השאר יוצר המנהל.
 *
 * שלושה תפקידים: admin (הכול), operator (צופה, מקליט, מסובב, מקטלג),
 * viewer (רק צופה). המחיקה, הגדרות המצלמות והמשתמשים — למנהל בלבד.
 *
 * כשהאפליקציה מחוברת לפלטפורמה — הכניסה רק דרכה (ראו "כניסה דרך הפלטפורמה" למטה):
 * הסיסמאות לא נמחקות, רק לא נבדקות, וחוזרות לעבוד כשהאפליקציה מנותקת.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function sessionStart(): void {
    // ב-CLI אין עוגיות; סשן בזיכרון מספיק כדי שהבדיקות יריצו את אותו קוד.
    if (PHP_SAPI === 'cli') {
        if (!isset($_SESSION)) $_SESSION = [];
        return;
    }
    if (session_status() !== PHP_SESSION_NONE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('CAMERAS');
    session_start();
}

function userCount(): int {
    return (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

function currentUser(): ?array {
    // מחוברת לפלטפורמה: הזהות מהשער קובעת, והסשן של האפליקציה לא נחשב — כך אי אפשר
    // להישאר בחשבון אחר, ויציאה מהפלטפורמה היא יציאה מכאן.
    if (platformIdentity()) return platformState()['user'];
    sessionStart();
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT id, username, display_name, role, blocked FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $user = $st->fetch();
    // חסימה מנתקת סשן קיים, לא רק מונעת כניסה חדשה.
    if (!$user || (int) $user['blocked'] === 1) {
        logout();
        return null;
    }
    return $user;
}

function requireUser(): array {
    $user = currentUser();
    if (!$user) throw new AppError('נדרשת התחברות', 401);
    return $user;
}

function requireRole(string ...$roles): array {
    $user = requireUser();
    if (!in_array($user['role'], $roles, true)) throw new AppError('אין לך הרשאה לפעולה הזו', 403);
    return $user;
}

function requireAdmin(): array {
    return requireRole('admin');
}

/** צופה, מפעיל או מנהל — כל מי שרשאי לגעת במצלמה (לא רק להסתכל). */
function requireOperator(): array {
    return requireRole('admin', 'operator');
}

function validUsername(string $u): bool {
    return (bool) preg_match('/^[a-zA-Z0-9_.\-]{3,32}$/', $u);
}

function createUser(string $username, string $password, string $displayName, string $role = 'viewer'): int {
    $username = trim($username);
    if (!validUsername($username)) throw new AppError('שם משתמש: 3–32 תווים, אותיות לטיניות, ספרות, נקודה, קו');
    if (strlen($password) < 8) throw new AppError('הסיסמה חייבת להיות לפחות 8 תווים');
    if (!in_array($role, ['admin', 'operator', 'viewer'], true)) throw new AppError('תפקיד לא מוכר');
    $displayName = trim($displayName) !== '' ? trim($displayName) : $username;

    $st = db()->prepare('SELECT 1 FROM users WHERE username = ? COLLATE NOCASE');
    $st->execute([$username]);
    if ($st->fetch()) throw new AppError('שם המשתמש תפוס');

    db()->prepare('INSERT INTO users (username, password_hash, display_name, role, created_at)
                   VALUES (?,?,?,?,?)')
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $displayName, $role, nowIso()]);
    return (int) db()->lastInsertId();
}

/** מסך ההתקנה: המשתמש הראשון, ורק כשאין אף אחד. */
function setupFirstAdmin(string $username, string $password, string $displayName): int {
    if (userCount() > 0) throw new AppError('ההתקנה כבר בוצעה', 409);
    $id = createUser($username, $password, $displayName, 'admin');
    logEvent('setup', 'נוצר המנהל הראשון', ['username' => $username], 'info', null, null, $id);
    return $id;
}

function login(string $username, string $password): array {
    $st = db()->prepare('SELECT * FROM users WHERE username = ? COLLATE NOCASE');
    $st->execute([trim($username)]);
    $user = $st->fetch();
    // אותה הודעה לשם לא קיים ולסיסמה שגויה — לא לגלות אילו שמות קיימים.
    if (!$user || !password_verify($password, $user['password_hash'])) {
        throw new AppError('שם משתמש או סיסמה שגויים', 401);
    }
    if ((int) $user['blocked'] === 1) throw new AppError('החשבון חסום', 403);

    sessionStart();
    if (PHP_SAPI !== 'cli') session_regenerate_id(true);   // מונע קיבוע סשן
    $_SESSION['uid'] = (int) $user['id'];
    db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([nowIso(), $user['id']]);
    logEvent('login', '', [], 'info', null, null, (int) $user['id']);
    unset($user['password_hash']);
    return $user;
}

function logout(): void {
    sessionStart();
    $_SESSION = [];
    if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'],
                  (bool) $p['secure'], (bool) $p['httponly']);
    }
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

function changePassword(int $userId, string $current, string $new): void {
    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$userId]);
    $hash = $st->fetchColumn();
    if (!$hash || !password_verify($current, (string) $hash)) throw new AppError('הסיסמה הנוכחית שגויה');
    if (strlen($new) < 8) throw new AppError('הסיסמה החדשה חייבת להיות לפחות 8 תווים');
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
}

function listUsers(): array {
    return db()->query('SELECT id, username, display_name, role, blocked, created_at, last_login_at, email,
                               platform_sub IS NOT NULL AS linked, platform_created
                        FROM users ORDER BY id')->fetchAll();
}

/* ───────── כניסה דרך הפלטפורמה ─────────
 *
 * החוק: platform/docs/auth.md; המדריך: platform/docs/sso.md סעיף 0.
 * כשהאפליקציה מחוברת, השער כותב לכל בקשה את $_SERVER['PLATFORM_USER'] — JSON עם sub, name,
 * email — ורק למי שיש לו הרשאה אליה. המפתח אינו HTTP_*, ולכן לא ניתן לזייף אותו בכותרת.
 * אין PLATFORM_USER (מנותקת, או בקשה של הגשר) → הכניסה הרגילה בסיסמה, כמו קודם.
 */

/** התפקיד של חשבון חדש מהפלטפורמה. אין כאן הרשמה עצמית, ולכן — הנמוך. */
const PLATFORM_NEW_ROLE = 'viewer';

function platformIdentity(): ?array {
    $raw = $_SERVER['PLATFORM_USER'] ?? '';
    if (!is_string($raw) || $raw === '') return null;
    $id = json_decode($raw, true);
    if (!is_array($id) || (string) ($id['sub'] ?? '') === '') return null;
    return [
        'sub'   => (string) $id['sub'],
        'name'  => trim((string) ($id['name'] ?? '')),
        'email' => strtolower(trim((string) ($id['email'] ?? ''))),
    ];
}

function platformMode(): bool {
    return platformIdentity() !== null;
}

/**
 * מי המשתמש, לפי הזהות מהפלטפורמה. state:
 *   ok      — user הוא החשבון (מקושר ל-sub, או קושר עכשיו לפי מייל)
 *   pending — אין חשבון תואם: מסך החיבור החד-פעמי ("יש לי כבר חשבון" / "אין לי חשבון")
 *   blocked — החשבון המקושר חסום אצלנו
 */
function platformState(bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $id = platformIdentity();
    if (!$id) return $cache = ['state' => 'none', 'user' => null];

    $user = platformUserBySub($id['sub']);
    if (!$user && $id['email'] !== '') {
        // איחוד סמוי לפי מייל (sso.md סעיף 4): חשבון ותיק באותו מייל, שעוד לא מקושר.
        $st = db()->prepare('SELECT id FROM users WHERE platform_sub IS NULL AND email = ? COLLATE NOCASE LIMIT 1');
        $st->execute([$id['email']]);
        $uid = $st->fetchColumn();
        if ($uid) {
            linkPlatformSub((int) $uid, $id, 'לפי מייל');
            $user = platformUserBySub($id['sub']);
        }
    }
    if (!$user && userCount() === 0) {
        // מערכת ריקה: מי שנכנס ראשון מהפלטפורמה הוא המנהל — במקום מסך ההתקנה, שחסום כשמחוברת.
        createPlatformUser($id, 'admin');
        $user = platformUserBySub($id['sub']);
    }
    if (!$user) return $cache = ['state' => 'pending', 'user' => null];
    if ((int) $user['blocked'] === 1) return $cache = ['state' => 'blocked', 'user' => null];

    // "כניסה אחרונה" — לא בכל בקשה (הדף שואל כל 10 שניות בזמן צפייה), אחת לשעה מספיק.
    if (($user['last_login_at'] ?? '') < isoFromTs(time() - 3600)) {
        db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([nowIso(), $user['id']]);
    }
    unset($user['last_login_at']);
    return $cache = ['state' => 'ok', 'user' => $user];
}

function platformUserBySub(string $sub): ?array {
    $st = db()->prepare('SELECT id, username, display_name, role, blocked, platform_created, last_login_at
                         FROM users WHERE platform_sub = ?');
    $st->execute([$sub]);
    return $st->fetch() ?: null;
}

function linkPlatformSub(int $userId, array $id, string $how): void {
    db()->prepare('UPDATE users SET platform_sub = ?, linked_at = ?, email = COALESCE(NULLIF(email, \'\'), NULLIF(?, \'\'))
                   WHERE id = ?')
        ->execute([$id['sub'], nowIso(), $id['email'], $userId]);
    logEvent('platform.link', 'החשבון קושר לחשבון בכלים הדיגיטליים — ' . $how,
             ['sub' => $id['sub'], 'email' => $id['email']], 'info', null, null, $userId);
}

/** חשבון חדש, פעיל — ההרשאה בפלטפורמה היא האישור. בלי סיסמה: הכניסה רק דרך הפלטפורמה. */
function createPlatformUser(array $id, string $role = PLATFORM_NEW_ROLE): int {
    $base = preg_replace('/[^a-zA-Z0-9_.\-]/', '', explode('@', $id['email'])[0]);
    if (strlen($base) < 3) $base = 'user' . preg_replace('/\D/', '', $id['sub']);
    $base = substr($base, 0, 28);
    $username = $base;
    $st = db()->prepare('SELECT 1 FROM users WHERE username = ? COLLATE NOCASE');
    for ($n = 2; $st->execute([$username]) && $st->fetch(); $n++) $username = $base . '-' . $n;

    db()->prepare('INSERT INTO users (username, password_hash, display_name, role, created_at, email,
                                      platform_sub, platform_created, linked_at)
                   VALUES (?,?,?,?,?,?,?,1,?)')
        ->execute([$username, '', $id['name'] !== '' ? $id['name'] : $username, $role, nowIso(),
                   $id['email'] !== '' ? $id['email'] : null, $id['sub'], nowIso()]);
    $uid = (int) db()->lastInsertId();
    logEvent('platform.new', 'נפתח חשבון דרך הכלים הדיגיטליים', ['sub' => $id['sub'], 'role' => $role],
             'info', null, null, $uid);
    return $uid;
}

/** "אין לי חשבון" במסך החד-פעמי. */
function platformCreateAccount(): array {
    $id = platformIdentity();
    if (!$id) throw new AppError('זמין רק בכניסה דרך הכלים הדיגיטליים');
    if (platformState()['state'] !== 'pending') throw new AppError('כבר יש לך חשבון כאן', 409);
    createPlatformUser($id);
    return platformState(true)['user'];
}

/**
 * "יש לי כבר חשבון": שם המשתמש והסיסמה הישנים, פעם אחת, והחשבון הישן מתקשר ל-sub.
 * 5 ניסיונות → נעילה של רבע שעה (auth.md סעיף 4). עובד גם מתוך חשבון שנפתח דרך הפלטפורמה
 * ("נזכרתי אחר כך") — אז החשבון הריק נמחק והישן תופס את מקומו.
 * הסיסמה הישנה לא נמחקת: היא רק לא נבדקת כל עוד האפליקציה מחוברת.
 */
function platformLinkOldAccount(string $username, string $password): array {
    $id = platformIdentity();
    if (!$id) throw new AppError('זמין רק בכניסה דרך הכלים הדיגיטליים');
    $now = platformState();
    $auto = $now['user'];
    if ($now['state'] === 'blocked') throw new AppError('החשבון חסום', 403);
    if ($auto && (int) $auto['platform_created'] !== 1) throw new AppError('החשבון כבר מקושר', 409);

    $lockKey = 'link_lock:' . $id['sub'];
    $lock = json_decode(settingGet($lockKey, '{}'), true) ?: [];
    if (($lock['until'] ?? 0) > time()) {
        throw new AppError('יותר מדי ניסיונות. נסו שוב בעוד ' . ceil(($lock['until'] - time()) / 60) . ' דקות', 429);
    }

    $st = db()->prepare('SELECT * FROM users WHERE username = ? COLLATE NOCASE');
    $st->execute([trim($username)]);
    $old = $st->fetch();
    $ok = $old && $old['password_hash'] !== '' && password_verify($password, $old['password_hash']);
    if (!$ok) {
        $fails = (int) ($lock['fails'] ?? 0) + 1;
        settingSet($lockKey, json_encode($fails >= 5 ? ['fails' => 0, 'until' => time() + 15 * 60] : ['fails' => $fails]));
        throw new AppError('שם משתמש או סיסמה שגויים', 401);
    }
    if ($old['platform_sub'] !== null) throw new AppError('החשבון הזה כבר מקושר לחשבון אחר בכלים הדיגיטליים', 409);
    if ((int) $old['blocked'] === 1) throw new AppError('החשבון חסום', 403);

    settingSet($lockKey, '{}');
    db()->beginTransaction();
    if ($auto) {
        // ההקלטות והסימניות שלו נשארות (created_by → NULL); מה שחשוב הוא החשבון הישן.
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$auto['id']]);
    }
    linkPlatformSub((int) $old['id'], $id, 'מסך החיבור, בשם המשתמש והסיסמה הישנים');
    db()->commit();
    return platformState(true)['user'];
}

/** מה me מחזיר על הפלטפורמה, כדי שהדף ידע מה להציג. */
function platformInfo(): ?array {
    $id = platformIdentity();
    if (!$id) return null;
    $s = platformState();
    return ['state' => $s['state'], 'name' => $id['name'], 'email' => $id['email'],
            'can_link' => $s['state'] === 'pending' || ($s['user'] && (int) $s['user']['platform_created'] === 1)];
}

/** נקודות של סיסמה — חסומות כשמחוברת (auth.md סעיף 2). */
function denyInPlatformMode(): void {
    if (platformMode()) throw new AppError('הכניסה היא דרך הכלים הדיגיטליים', 403);
}

function updateUser(int $adminId, int $id, array $in): void {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) throw new AppError('אין משתמש כזה', 404);

    if (isset($in['display_name'])) {
        $n = trim((string) $in['display_name']);
        if ($n !== '') db()->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([$n, $id]);
    }
    if (isset($in['role'])) {
        $r = (string) $in['role'];
        if (!in_array($r, ['admin', 'operator', 'viewer'], true)) throw new AppError('תפקיד לא מוכר');
        if ($id === $adminId && $r !== 'admin') throw new AppError('אי אפשר להוריד את עצמך מניהול');
        db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$r, $id]);
    }
    if (isset($in['blocked'])) {
        if ($id === $adminId) throw new AppError('אי אפשר לחסום את עצמך');
        db()->prepare('UPDATE users SET blocked = ? WHERE id = ?')->execute([$in['blocked'] ? 1 : 0, $id]);
    }
    if (array_key_exists('email', $in)) {
        // המייל שלפיו החשבון יתקשר לבד בכניסה הראשונה דרך הכלים הדיגיטליים.
        $e = strtolower(trim((string) $in['email']));
        if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) throw new AppError('כתובת מייל לא תקינה');
        if ($e !== '') {
            $st = db()->prepare('SELECT 1 FROM users WHERE email = ? COLLATE NOCASE AND id <> ?');
            $st->execute([$e, $id]);
            if ($st->fetch()) throw new AppError('המייל הזה כבר שייך למשתמש אחר');
        }
        db()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$e !== '' ? $e : null, $id]);
    }
    // מחוברת: אין איפוס סיסמה — הכניסה רק דרך הפלטפורמה.
    if (!empty($in['password']) && !platformMode()) {
        $p = (string) $in['password'];
        if (strlen($p) < 8) throw new AppError('הסיסמה חייבת להיות לפחות 8 תווים');
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($p, PASSWORD_DEFAULT), $id]);
    }
}

function deleteUser(int $adminId, int $id): void {
    if ($id === $adminId) throw new AppError('אי אפשר למחוק את עצמך');
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
}
