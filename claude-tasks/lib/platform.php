<?php
/**
 * כניסה דרך הפלטפורמה ("הכלים הדיגיטליים").
 *
 * החוק: platform/docs/auth.md; המדריך: platform/docs/sso.md, סעיף 0.
 *
 *   מחוברת — השער כותב את הזהות ל-$_SERVER['PLATFORM_USER'] (רק למי שיש לו
 *            הרשאה). היא לבדה קובעת מי המשתמש: קישור לפי sub, ואז לפי מייל.
 *            אין התאמה — מסך חד-פעמי: "יש לי כבר חשבון" / "אין לי חשבון".
 *            הכניסה בסיסמה חסומה, אבל הסיסמה עצמה לא נמחקת.
 *   מנותקת — "רק בסיס": הכניסה הישנה בסיסמה חוזרת לעבוד, כמו לפני החיבור.
 *
 * אסימוני העובד אינם עוברים כאן בכלל — הם בכותרת, לא בעוגייה.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/projects.php';

/** ניסיונות כושלים בחיבור חשבון ישן, ומשך הנעילה אחריהם (auth.md, סעיף 4). */
const LINK_MAX_FAILS   = 5;
const LINK_LOCK_SECS   = 15 * 60;
/** כמה זמן נשמרת התשובה של sso.php?action=config. */
const PLATFORM_CFG_TTL = 60;

if (!defined('PLATFORM_CFG_FILE')) define('PLATFORM_CFG_FILE', __DIR__ . '/../data/platform-config.json');

/**
 * הזהות שהשער העביר, או null. המפתח אינו HTTP_* — לקוח לא יכול לזייף אותו
 * בכותרת.
 */
function platformIdentity(): ?array {
    $id = json_decode((string) ($_SERVER['PLATFORM_USER'] ?? ''), true);
    if (!is_array($id) || (string) ($id['sub'] ?? '') === '') return null;
    return [
        'sub'   => (string) $id['sub'],
        'name'  => trim((string) ($id['name'] ?? '')),
        'email' => strtolower(trim((string) ($id['email'] ?? ''))),
    ];
}

/**
 * האם האפליקציה מחוברת לפלטפורמה, לפי sso.php?action=config.
 *
 * מחזיר 'connected', 'detached' או 'unknown'. נשמר לדקה. הפלטפורמה לא
 * עונה — התשובה האחרונה שנקראה; אף פעם לא נקראה — 'unknown', והמסך אומר
 * "הכניסה לא זמינה כרגע" (הכרעת בעל המערכת 5, rollout.md).
 */
function platformMode(): array {
    static $memo = null;
    if ($memo !== null) return $memo;

    $cached = is_file(PLATFORM_CFG_FILE) ? json_decode((string) file_get_contents(PLATFORM_CFG_FILE), true) : null;
    if (is_array($cached) && time() - (int) ($cached['at'] ?? 0) <= PLATFORM_CFG_TTL) {
        return $memo = $cached['mode'];
    }

    $fresh = platformFetchConfig();
    if ($fresh !== null) {
        ensureDataDir(dirname(PLATFORM_CFG_FILE));
        file_put_contents(PLATFORM_CFG_FILE, json_encode(['at' => time(), 'mode' => $fresh]), LOCK_EX);
        return $memo = $fresh;
    }
    if (is_array($cached['mode'] ?? null)) return $memo = $cached['mode'];
    return $memo = ['state' => 'unknown', 'password' => false];
}

/** פנייה אחת ל-config. null כשהפלטפורמה לא ענתה תשובה תקינה. */
function platformFetchConfig(): ?array {
    $url = (string) (config()['platform_config_url']
        ?? 'https://mbe-plus.com/mini_projects/platform/sso.php?action=config&client=claude-tasks');
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4,
                                CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3]);
        $raw = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
    }
    $c = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($c) || !array_key_exists('connected', $c)) return null;
    return [
        'state'    => $c['connected'] ? 'connected' : 'detached',
        // מנותקת: "סיסמה משלה" לפי ההגדרה בניהול; בלי הגדרה — פתוחה.
        'password' => !$c['connected'] && (($c['login']['password'] ?? true) !== false),
    ];
}

/**
 * הכניסה בסיסמה של האפליקציה — רק כשהיא מנותקת והמנהל השאיר אותה פתוחה.
 * מחזיר null כשמותר, אחרת את ההודעה למשתמש.
 */
function passwordLoginBlocked(): ?string {
    if (platformIdentity()) return 'הכניסה היא דרך הכלים הדיגיטליים';
    $mode = platformMode();
    if ($mode['state'] === 'unknown')   return 'הכניסה לא זמינה כרגע — נסו שוב בעוד כמה דקות';
    if ($mode['state'] === 'connected') return 'הכניסה היא דרך הכלים הדיגיטליים';
    if (!$mode['password'])             return 'הכניסה בסיסמה כבויה — היכנסו דרך הכלים הדיגיטליים';
    return null;
}

/**
 * המשתמש של הבקשה, לפי הזהות שהשער העביר.
 *
 * מחזיר ['user' => ?array, 'pending' => ?array]. pending = זהות בלי חשבון —
 * המסך החד-פעמי. ה-session תמיד מיושר לזהות: מישהו אחר נכנס לפלטפורמה —
 * מחליפים; אין חשבון — אין session.
 */
function platformResolve(array $identity): array {
    $user = platformFindAccount($identity);
    sessionStart();
    if (!$user) {
        unset($_SESSION['uid']);
        return ['user' => null, 'pending' => $identity];
    }
    if ((int) ($_SESSION['uid'] ?? 0) !== (int) $user['id']) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
    }
    return ['user' => currentUser(), 'pending' => null];
}

/**
 * קישור לפי sub, ואם אין — לפי מייל (רק חשבון שעוד לא מקושר: אותו מייל =
 * אותו אדם, sso.md סעיף 4). מייל שהשתנה בפלטפורמה מתעדכן כאן.
 */
function platformFindAccount(array $identity): ?array {
    $st = db()->prepare('SELECT id, email FROM users WHERE platform_sub = ?');
    $st->execute([$identity['sub']]);
    $row = $st->fetch();
    if ($row) {
        if ($identity['email'] !== '' && $row['email'] !== $identity['email']) {
            db()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$identity['email'], $row['id']]);
        }
        return $row;
    }
    if ($identity['email'] === '') return null;

    $st = db()->prepare("SELECT id FROM users WHERE platform_sub = '' AND lower(email) = ? ORDER BY id LIMIT 1");
    $st->execute([$identity['email']]);
    $row = $st->fetch();
    if (!$row) return null;
    db()->prepare('UPDATE users SET platform_sub = ? WHERE id = ?')->execute([$identity['sub'], $row['id']]);
    auditPlatform((int) $row['id'], 'platform-link', 'לפי מייל');
    return $row;
}

/**
 * "אין לי חשבון" — חשבון חדש, פעיל, כמו משתמש שמנהל פותח: member (admin רק
 * למשתמש הראשון — אין באפליקציה הרשמה עצמית). בלי סיסמה: הכניסה היא דרך
 * הפלטפורמה. link_offer = ההצעה לחבר חשבון ישן נשארת בהגדרות.
 */
function platformCreateAccount(array $identity): array {
    $base = preg_replace('/[^A-Za-z0-9._-]/', '', explode('@', $identity['email'])[0] ?? '');
    if (strlen($base) < 3) $base = 'user';
    $base = substr($base, 0, 26);
    $username = $base;
    for ($i = 2; usernameTaken($username); $i++) $username = $base . '-' . $i;

    $role = userCount() === 0 ? 'admin' : 'member';
    db()->prepare('INSERT INTO users (username, password_hash, display_name, role, created_at,
                                      platform_sub, email, link_offer)
                   VALUES (?,?,?,?,?,?,?,1)')
        ->execute([$username, '', $identity['name'] !== '' ? mb_substr($identity['name'], 0, 60) : $username,
                   $role, nowTs(), $identity['sub'], $identity['email']]);
    $id = (int) db()->lastInsertId();
    auditPlatform($id, 'platform-new', $role);
    return platformResolve($identity);
}

function usernameTaken(string $username): bool {
    $st = db()->prepare('SELECT 1 FROM users WHERE username = ?');
    $st->execute([$username]);
    return (bool) $st->fetch();
}

/**
 * "יש לי כבר חשבון" — שם משתמש וסיסמה ישנים, פעם אחת. החשבון הישן מתקשר
 * ל-sub. אם כבר נפתח חשבון חדש דרך הפלטפורמה (חזר מהתפריט), מה שנוצר בו
 * עובר לחשבון הישן, והחדש נמחק. הסיסמה הישנה לא נמחקת — רק חסומה כל עוד
 * האפליקציה מחוברת.
 *
 * מחזיר את המשתמש, או זורק InvalidArgumentException עם ההודעה למשתמש.
 */
function platformLinkOld(array $identity, string $username, string $password): array {
    $key = 'link:' . $identity['sub'];
    $lock = lockState($key);
    if ($lock['until'] > nowTs()) {
        throw new InvalidArgumentException('יותר מדי ניסיונות — נסו שוב בעוד ' . ceil(($lock['until'] - nowTs()) / 60) . ' דקות');
    }

    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([trim($username)]);
    $old = $st->fetch();
    $hash = ($old['password_hash'] ?? '') ?: '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin';
    if (!$old || !password_verify($password, $hash)) {
        lockFail($key, $lock['fails'] + 1);
        auditPlatform(null, 'platform-link-old', 'סיסמה שגויה: ' . mb_substr(trim($username), 0, 32), false);
        throw new InvalidArgumentException('שם משתמש או סיסמה שגויים');
    }
    if ($old['platform_sub'] !== '') {
        if ($old['platform_sub'] === $identity['sub']) return platformResolve($identity);
        throw new InvalidArgumentException('החשבון הזה כבר מקושר לחשבון אחר בכלים הדיגיטליים');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM users WHERE platform_sub = ?');
        $st->execute([$identity['sub']]);
        $auto = $st->fetch();
        if ($auto) mergeInto($pdo, (int) $auto['id'], (int) $old['id']);

        $pdo->prepare('UPDATE users SET platform_sub = ?, email = ?, link_offer = 0 WHERE id = ?')
            ->execute([$identity['sub'], $identity['email'] !== '' ? $identity['email'] : $old['email'], $old['id']]);
        if ($auto && $auto['role'] === 'admin' && $old['role'] !== 'admin') {
            $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$old['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    lockClear($key);
    auditPlatform((int) $old['id'], 'platform-link-old', $auto ? 'אוחד עם חשבון שנפתח דרך הפלטפורמה' : '');
    return platformResolve($identity);
}

/**
 * מעביר את כל מה שנוצר בחשבון $from אל $to, ומוחק את $from. כפילות (אותה
 * חברות בפרויקט, אותו ספק) — של החשבון הישן נשארת.
 */
function mergeInto(PDO $pdo, int $from, int $to): void {
    foreach ([['tasks', 'created_by'], ['topics', 'created_by'], ['projects', 'created_by'],
              ['skills', 'created_by'], ['events', 'user_id']] as [$table, $col]) {
        $pdo->prepare("UPDATE $table SET $col = ? WHERE $col = ?")->execute([$to, $from]);
    }
    // חיבורים עוברים כמו שהם; בחירת גיטהאב לפרויקט — רק אם לישן אין בחירה משלו
    $pdo->prepare('UPDATE connections SET user_id = ? WHERE user_id = ?')->execute([$to, $from]);
    foreach (['project_members', 'user_providers', 'project_connections'] as $table) {
        $pdo->prepare("UPDATE OR IGNORE $table SET user_id = ? WHERE user_id = ?")->execute([$to, $from]);
        $pdo->prepare("DELETE FROM $table WHERE user_id = ?")->execute([$from]);
    }
    // חיבור גיטהאב וספק ברירת מחדל — רק אם לישן אין.
    $pdo->prepare("UPDATE users SET
            github_token  = CASE WHEN github_token  = '' THEN (SELECT github_token  FROM users WHERE id = :f) ELSE github_token  END,
            github_login  = CASE WHEN github_login  = '' THEN (SELECT github_login  FROM users WHERE id = :f) ELSE github_login  END,
            github_scopes = CASE WHEN github_scopes = '' THEN (SELECT github_scopes FROM users WHERE id = :f) ELSE github_scopes END,
            default_provider = CASE WHEN default_provider = '' THEN (SELECT default_provider FROM users WHERE id = :f) ELSE default_provider END
          WHERE id = :t")->execute([':f' => $from, ':t' => $to]);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$from]);
}

/* ── נעילה אחרי ניסיונות כושלים ─────────────────────────────────── */

function lockState(string $key): array {
    $st = db()->prepare('SELECT fails, locked_until AS until FROM login_locks WHERE lock_key = ?');
    $st->execute([$key]);
    $row = $st->fetch();
    if (!$row) return ['fails' => 0, 'until' => 0];
    // נעילה שפגה — מתחילים מחדש.
    if ((int) $row['until'] > 0 && (int) $row['until'] <= nowTs()) return ['fails' => 0, 'until' => 0];
    return ['fails' => (int) $row['fails'], 'until' => (int) $row['until']];
}

function lockFail(string $key, int $fails): void {
    $until = $fails >= LINK_MAX_FAILS ? nowTs() + LINK_LOCK_SECS : 0;
    db()->prepare('INSERT OR REPLACE INTO login_locks (lock_key, fails, locked_until) VALUES (?,?,?)')
        ->execute([$key, $until ? 0 : $fails, $until]);
}

function lockClear(string $key): void {
    db()->prepare('DELETE FROM login_locks WHERE lock_key = ?')->execute([$key]);
}

/** רישום ביומן הניהול — גם כישלון. */
function auditPlatform(?int $userId, string $action, string $detail, bool $ok = true): void {
    logEvent($userId === null ? null : ['id' => $userId, 'username' => 'platform'], $action, '', $ok, $detail);
}

/**
 * מנהל מוחק חשבון נוסף, ומה שנוצר בו עובר אליו (mergeInto). נועד לחשבון
 * שנפתח בטעות — למשל "משתמש חדש" שנוצר במקום לשנות שם.
 *
 * לא מוחקים: את עצמך; חשבון שמקושר לכלים הדיגיטליים (הכניסה הבאה דרכם הייתה
 * מאבדת את החשבון); ואת המנהל האחרון.
 */
function adminRemoveUser(array $actor, int $userId): void {
    if (($actor['role'] ?? '') !== 'admin') throw new AppError('למנהל בלבד', 403);
    if ($userId === (int) $actor['id']) throw new InvalidArgumentException('אי אפשר למחוק את החשבון שלך');
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$userId]);
    $u = $st->fetch();
    if (!$u) throw new AppError('המשתמש לא נמצא', 404);
    if ($u['platform_sub'] !== '') {
        throw new InvalidArgumentException('החשבון מקושר לכלים הדיגיטליים — מחיקה תנתק את מי שנכנס דרכו. '
            . 'אם זה אתה, השתמש ב"יש לי כבר חשבון ישן" בהגדרות');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        mergeInto($pdo, $userId, (int) $actor['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    logEvent($actor, 'user.remove', "user:$userId", true, $u['username'] . ' → ' . $actor['username']);
}

/** שם התצוגה של המשתמש עצמו. */
function setDisplayName(array $user, string $name): string {
    $name = trim($name);
    if ($name === '') throw new InvalidArgumentException('חסר שם');
    $name = mb_substr($name, 0, 60);
    db()->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([$name, $user['id']]);
    return $name;
}
