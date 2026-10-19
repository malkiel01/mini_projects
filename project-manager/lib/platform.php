<?php
/**
 * כניסה דרך הפלטפורמה ("הכלים הדיגיטליים") — platform/docs/auth.md, platform/docs/sso.md סעיף 0.
 *
 * מחוברת (השער פעיל): השער כותב $_SERVER['PLATFORM_USER'] — JSON {sub, name, email, email_verified} —
 * רק למשתמש מחובר לפלטפורמה עם הרשאה לאפליקציה. המפתח אינו HTTP_*, ולכן אי אפשר לזייף אותו בבקשה.
 * הוא קובע מי המשתמש, בכל בקשה, בלי מסך:
 *   1. החשבון המקושר ל-sub;
 *   2. אין — חשבון שעוד לא מקושר באותו מייל (איחוד סמוי) — מקושר עכשיו;
 *   3. אין — חשבון חדש, פעיל, כמו מי שנרשם לבד (מנהל פרויקט), בלי אישור נוסף: ההרשאה בפלטפורמה היא
 *      האישור. מסומן platform_auto, ומקבל פעם אחת את מסך החיבור ("יש לי כבר חשבון" / "אין לי חשבון").
 * בלי זהות — session שנפתח דרך הפלטפורמה נמחק (יציאה מהפלטפורמה = יציאה מהאפליקציה).
 *
 * הכניסה בסיסמה של האפליקציה (וההרשמה, השחזור והחלפת הסיסמה) — חסומה כל עוד מחוברת. הסיסמאות לא
 * נמחקות: כשהאפליקציה מנותקת, הכניסה הישנה חוזרת לעבוד (הכרעת בעל המערכת, rollout.md).
 *
 * משתתפים מבחוץ (פורטל הלקוח / הספק / העו"ד, קישורים אישיים) — לא נוגעים: אין להם חשבון.
 * הקובץ לא נוגע בשום דבר מחוץ לתיקיית האפליקציה — רק קורא את ההגדרה מהפלטפורמה ב-HTTP.
 */

declare(strict_types=1);

const PM_PLATFORM_CONFIG_URL = 'https://mbe-plus.com/mini_projects/platform/sso.php?action=config&client=project-manager';
const PM_PLATFORM_CONFIG_TTL = 60; // שניות — ההגדרה בניהול הפלטפורמה (מחוברת / מנותקת)

/** הזהות שהשער כתב, או null. */
function pm_platform_identity(): ?array
{
    $id = json_decode((string)($_SERVER['PLATFORM_USER'] ?? ''), true);
    return is_array($id) && (string)($id['sub'] ?? '') !== '' ? $id : null;
}

/**
 * האם האפליקציה מחוברת לפלטפורמה — ואז הכניסה בסיסמה חסומה. יש זהות → מחוברת. אין (דף ציבורי, משתמש
 * שלא נכנס) → לפי ההגדרה בניהול הפלטפורמה (sso.php?action=config: login.password), שנשמרת לדקה.
 * הפלטפורמה לא זמינה — ההגדרה האחרונה; אף פעם לא נקראה — פתוחה, כדי שאפליקציה שיצאה לדרך עצמאית
 * (דומיין משלה, בלי הפלטפורמה) לא תינעל.
 */
function pm_platform_connected(): bool
{
    if (pm_platform_identity()) return true;
    static $connected = null; // ההגדרה — פעם אחת לבקשה
    $login = $connected === null ? (pm_platform_config()['login'] ?? null) : null;
    return $connected ??= is_array($login) && empty($login['password']);
}

/** ההגדרה מהפלטפורמה (במטמון לדקה), או [] אם לא נקראה אף פעם. */
function pm_platform_config(): array
{
    $cache = dirname(PM_DB) . '/platform-config.json';
    $cached = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
    if (is_array($cached) && time() - (int)($cached['at'] ?? 0) <= PM_PLATFORM_CONFIG_TTL) return $cached['config'] ?? [];
    $url = getenv('PM_PLATFORM_CONFIG_URL') ?: PM_PLATFORM_CONFIG_URL; // בדיקות: שרת מקומי
    $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    $config = $raw ? json_decode($raw, true) : null;
    if (is_array($config) && is_array($config['login'] ?? null)) {
        @file_put_contents($cache, json_encode(['at' => time(), 'config' => $config]));
        return $config;
    }
    return is_array($cached) ? ($cached['config'] ?? []) : [];
}

/** כניסה בסיסמה וכל מה שסביבה — חסומים כשמחוברת. */
function pm_platform_block_password(string $what): void
{
    if (!pm_platform_connected()) return;
    pm_log('info', 'auth', 'password.blocked', ['message' => "$what — חסום: האפליקציה מחוברת לפלטפורמה"]);
    throw new PmError('הכניסה היא דרך הכלים הדיגיטליים', 403);
}

/**
 * המשתמש באפליקציה של זהות מהפלטפורמה — לפי sub, ואז לפי מייל, ואז חשבון חדש. מחזיר את ה-id.
 * לא נוגע בחשבון חסום (נשאר חסום — השער כבר אישר את ההרשאה, אבל מנהל האפליקציה חסם אותו כאן).
 */
function pm_platform_user(array $identity): int
{
    $db = pm_db();
    $sub = (string)$identity['sub'];
    $email = mb_strtolower(trim((string)($identity['email'] ?? '')));
    $name = mb_substr(trim((string)($identity['name'] ?? '')), 0, 60);

    $st = $db->prepare('SELECT id FROM users WHERE platform_sub = ?');
    $st->execute([$sub]);
    if ($id = $st->fetchColumn()) return (int)$id;

    // איחוד סמוי: אותו מייל = אותו אדם (sso.md, סעיף 4). מייל שלא אומת (משתמש ותיק בפלטפורמה) — לא.
    if ($email !== '' && ($identity['email_verified'] ?? true) !== false) {
        $st = $db->prepare("SELECT id, status FROM users WHERE platform_sub IS NULL
                              AND (email = ? COLLATE NOCASE OR username = ? COLLATE NOCASE) ORDER BY email = ? COLLATE NOCASE DESC, id LIMIT 1");
        $st->execute([$email, $email, $email]);
        if ($u = $st->fetch()) {
            pm_platform_attach((int)$u['id'], $sub, $u['status']);
            pm_log('info', 'auth', 'platform.linked', ['user' => ['id' => (int)$u['id']], 'message' => 'חשבון קיים קושר לפלטפורמה לפי המייל',
                'data' => ['sub' => $sub, 'by' => 'email']]);
            return (int)$u['id'];
        }
    }

    // חשבון חדש — כמו הרשמה רגילה (מנהל פרויקט), פעיל. האפליקציה בלי אף משתמש — הראשון הוא המפתח.
    $first = !pm_users_exist();
    $username = $email !== '' && !pm_platform_taken('username', $email) ? $email : 'platform-' . $sub;
    $mail = $email !== '' && !pm_platform_taken('email', $email) ? $email : '';
    try {
        $db->prepare('INSERT INTO users (' . ($first ? 'id, ' : '') . 'username, password_hash, display_name, email, role, status, created_at, approved_at, platform_sub, platform_auto)
                      VALUES (' . ($first ? '?, ' : '') . '?,?,?,?,?,?,?,?,?,1)')
           ->execute(array_merge($first ? [PM_FIRST_USER_ID] : [], [$username, '', $name !== '' ? $name : ($email ?: 'משתמש'), $mail, // i18n-none — שם ברירת מחדל במסד
                     $first ? 'developer' : 'manager', 'active', pm_now(), pm_now(), $sub]));
    } catch (PDOException $e) {
        // שתי בקשות ראשונות במקביל — השנייה נתקלת באינדקס הייחודי על sub, והראשונה כבר יצרה
        $st = $db->prepare('SELECT id FROM users WHERE platform_sub = ?');
        $st->execute([$sub]);
        if ($id = $st->fetchColumn()) return (int)$id;
        throw $e;
    }
    $id = (int)$db->lastInsertId();
    pm_audit("user:$id", 'user.platform', 'user', $id, ['sub' => $sub]);
    pm_log('info', 'auth', 'platform.created', ['user' => ['id' => $id, 'username' => $username],
        'message' => 'נפתח חשבון דרך הפלטפורמה — פעיל', 'data' => ['sub' => $sub, 'email' => $email]]);
    return $id;
}

function pm_platform_taken(string $col, string $value): bool
{
    $st = pm_db()->prepare("SELECT 1 FROM users WHERE $col = ? COLLATE NOCASE");
    $st->execute([$value]);
    return (bool)$st->fetchColumn();
}

/** מקשר חשבון ל-sub. ממתין לאישור — מאושר עכשיו (ההרשאה בפלטפורמה היא האישור); חסום — נשאר חסום. */
function pm_platform_attach(int $userId, string $sub, string $status): void
{
    pm_db()->prepare("UPDATE users SET platform_sub = ?, platform_auto = 0,
                        status = CASE WHEN status = 'pending' THEN 'active' ELSE status END,
                        approved_at = CASE WHEN status = 'pending' THEN ? ELSE approved_at END WHERE id = ?")
       ->execute([$sub, pm_now(), $userId]);
    pm_audit("user:$userId", 'user.platform_link', 'user', $userId, ['sub' => $sub, 'was' => $status]);
}

/**
 * בראש api.php: הזהות מהפלטפורמה קובעת את ה-session. יש זהות → ה-session שלה (נפתח מחדש אם שייך למישהו
 * אחר, או לכניסה בסיסמה). אין זהות → session שנפתח דרך הפלטפורמה נמחק; של כניסה בסיסמה (מנותקת) נשאר.
 */
function pm_platform_resolve(): void
{
    $identity = pm_platform_identity();
    $s = pm_session_user();
    if (!$identity) {
        if ($s && $s['via'] === 'platform') {
            pm_end_session();
            pm_session_user(true);
        }
        return;
    }
    $uid = pm_platform_user($identity);
    if ($s && $s['id'] === $uid && $s['via'] === 'platform') return;
    if ($s) pm_end_session();
    pm_start_session($uid, 'platform');
    pm_session_user(true);
}

/** חשבון שנפתח דרך הפלטפורמה וכבר עבדו בו — אי אפשר לחבר אליו חשבון ישן בלי לפצל את הנתונים. */
function pm_platform_has_data(int $userId): bool
{
    $st = pm_db()->prepare('SELECT (SELECT COUNT(*) FROM projects WHERE owner_id = :u) + (SELECT COUNT(*) FROM contacts WHERE owner_id = :u)');
    $st->execute([':u' => $userId]);
    return (int)$st->fetchColumn() > 0;
}

/** יש בכלל חשבון ישן (עם סיסמה) שעוד לא קושר — אחרת אין מה להציע. */
function pm_platform_old_accounts(int $exceptId): bool
{
    $st = pm_db()->prepare("SELECT 1 FROM users WHERE platform_sub IS NULL AND password_hash != '' AND status != 'blocked' AND id != ? LIMIT 1");
    $st->execute([$exceptId]);
    return (bool)$st->fetchColumn();
}

/** למסך: מחוברת? מקושר? להציג את מסך החיבור (פעם אחת)? אפשר לחבר מהתפריט? */
function pm_platform_state(array $user): array
{
    $st = pm_db()->prepare('SELECT platform_sub, platform_auto, link_dismissed FROM users WHERE id = ?');
    $st->execute([$user['id']]);
    $r = $st->fetch() ?: [];
    $can = pm_platform_identity() !== null && (int)($r['platform_auto'] ?? 0) === 1
        && pm_platform_old_accounts((int)$user['id']) && !pm_platform_has_data((int)$user['id']);
    return [
        'connected' => pm_platform_connected(),
        'linked'    => ($r['platform_sub'] ?? null) !== null,
        'can_link'  => $can,
        'link_offer' => $can && (int)($r['link_dismissed'] ?? 0) === 0,
    ];
}

/**
 * "יש לי כבר חשבון": שם המשתמש (או המייל) והסיסמה הישנים, פעם אחת — החשבון הישן מתקשר ל-sub, והחשבון
 * שנפתח לבד נמחק (רק אם עוד לא עבדו בו). 5 ניסיונות שגויים → נעילה של רבע שעה. חשבון שכבר מקושר — לא.
 * הסיסמה הישנה לא נמחקת — רק חסומה כל עוד האפליקציה מחוברת.
 */
function pm_platform_link(array $user, array $in): array
{
    $identity = pm_platform_identity();
    if (!$identity) throw new PmError('החיבור זמין רק בכניסה דרך הכלים הדיגיטליים', 403);
    $state = pm_platform_state($user);
    if (!$state['can_link']) throw new PmError(pm_platform_has_data((int)$user['id'])
        ? 'כבר עבדת בחשבון הזה — את החשבון הישן מחבר המפתח' : 'אין חשבון ישן לחבר', 409);
    $sub = (string)$identity['sub'];
    $keys = ['link:' . $sub];
    if (($wait = pm_lock_remaining($keys)) > 0) throw new PmError('יותר מדי ניסיונות. נסו שוב בעוד {n} דקות', 429, ['n' => (int)ceil($wait / 60)]);
    $login = trim((string)($in['username'] ?? ''));
    $password = (string)($in['password'] ?? '');
    $st = pm_db()->prepare("SELECT * FROM users WHERE platform_sub IS NULL AND password_hash != '' AND id != ?
                              AND (username = ? COLLATE NOCASE OR (email != '' AND email = ? COLLATE NOCASE)) LIMIT 1");
    $st->execute([$user['id'], $login, $login]);
    $old = $st->fetch();
    $hash = $old['password_hash'] ?? '$2y$10$rxAGOCUktx7c3KoNsrn5quXX6u4.mqw4TkeXZk1yylgcrXjz.UZFa';
    if (!password_verify($password, $hash) || !$old) {
        pm_record_failure($keys);
        pm_log('warn', 'auth', 'platform.link_fail', ['user' => $user, 'data' => ['login' => $login, 'exists' => (bool)$old, 'failures' => pm_failures($keys[0])]]);
        throw new PmError('שם משתמש או סיסמה שגויים', 422); // לא 401 — זה היה מחזיר את הדפדפן למסך הכניסה
    }
    if ($old['status'] === 'blocked') throw new PmError('החשבון הישן חסום — פנה למפתח', 403);
    pm_clear_failures($keys);
    $db = pm_db();
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]); // החשבון שנפתח לבד — ריק
        pm_platform_attach((int)$old['id'], $sub, $old['status']);
        $email = mb_strtolower(trim((string)($identity['email'] ?? '')));
        if ($old['email'] === '' && $email !== '' && !pm_platform_taken('email', $email)) {
            $db->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $old['id']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    pm_log('info', 'auth', 'platform.linked', ['user' => ['id' => (int)$old['id'], 'username' => $old['username']],
        'message' => 'חשבון ישן חובר לפלטפורמה במסך החיבור', 'data' => ['sub' => $sub, 'by' => 'password', 'removed' => (int)$user['id']]]);
    pm_start_session((int)$old['id'], 'platform'); // ה-session הקודם נמחק עם החשבון
    return ['ok' => true];
}

/** "אין לי חשבון" — ההצעה לא חוזרת; נשארת בחשבון שלי למי שנזכר אחר כך. */
function pm_platform_dismiss(array $user): array
{
    pm_db()->prepare('UPDATE users SET link_dismissed = 1 WHERE id = ?')->execute([$user['id']]);
    return ['ok' => true];
}
