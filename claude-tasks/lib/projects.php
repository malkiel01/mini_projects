<?php
/**
 * פרויקטים, חברות והרשאות.
 *
 * פרויקט הוא הקשר בין מטלות לריפו. מסביבו נבנית ההרשאה: מי רשאי רק
 * לראות, מי רשאי לתת לקלוד לכתוב, ומי רשאי לצרף אנשים.
 *
 * שני כללים שכל הקובץ נשען עליהם:
 *
 *   1. ההרשאה נבדקת בשרת, בכל פעולה, ולא נגזרת ממה שהממשק הציג.
 *   2. פעולה מול גיטהאב יוצאת תמיד עם הטוקן של מי שביקש אותה. גם חבר
 *      פרויקט בדרגת כתיבה אינו כותב "דרך" הטוקן של הבעלים — אם אין לו
 *      גישה משלו לריפו, הפעולה נכשלת, וכך צריך להיות.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/config.php';

/** הסדר הוא ההיררכיה: כל דרגה כוללת את שלפניה. */
const LEVELS = ['read', 'write', 'admin'];

function levelRank(string $level): int {
    $i = array_search($level, LEVELS, true);
    return $i === false ? -1 : $i;
}

/* ── יומן ─────────────────────────────────────────────────────── */

/**
 * רושם פעולה. זה מה שמאפשר למנהל לענות על "מה קרה כאן" בלי לנחש,
 * ולכן נרשמות גם פעולות שנכשלו — דווקא הן המעניינות.
 */
function logEvent(?array $user, string $action, string $target = '',
                  bool $ok = true, string $detail = ''): void {
    db()->prepare('INSERT INTO events (user_id, actor, action, target, ok, detail, ip, created_at)
                   VALUES (?,?,?,?,?,?,?,?)')
        ->execute([
            $user['id'] ?? null,
            $user['username'] ?? 'system',
            mb_substr($action, 0, 60),
            mb_substr($target, 0, 200),
            $ok ? 1 : 0,
            mb_substr($detail, 0, 1000),
            mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            nowTs(),
        ]);
}

/* ── סודות של משתמש ───────────────────────────────────────────── */

function setUserSecret(int $userId, string $column, string $plain): void {
    if (!in_array($column, ['github_token'], true)) {
        throw new InvalidArgumentException('שדה סוד לא מוכר');
    }
    db()->prepare("UPDATE users SET $column = ? WHERE id = ?")
        ->execute([encryptSecret($plain), $userId]);
}

function userSecret(int $userId, string $column): string {
    if (!in_array($column, ['github_token'], true)) return '';
    $st = db()->prepare("SELECT $column v FROM users WHERE id = ?");
    $st->execute([$userId]);
    $row = $st->fetch();
    return $row ? decryptSecret((string) $row['v']) : '';
}

/**
 * הטוקן של המשתמש הנוכחי, או חריגה עם הסבר.
 *
 * מקבל את רשומת המשתמש ולא מזהה, כדי שלא תיווצר פונקציה שמחזירה טוקן
 * של מישהו לפי מספר — קריאה כזו הייתה הופכת בטעות למסלול התחזות.
 */
function requireGithubToken(array $user): string {
    $token = userSecret((int) $user['id'], 'github_token');
    if ($token === '') {
        throw new RuntimeException('לא חובר חשבון גיטהאב. הגדרות ← חיבור לגיטהאב');
    }
    return $token;
}

/* ── פרויקטים ─────────────────────────────────────────────────── */

/**
 * דרגת ההרשאה של משתמש בפרויקט, או null אם אינו חבר בו.
 *
 * מנהל־על מקבל admin בכל פרויקט: הוא ממילא יכול לצרף את עצמו, ועדיף
 * שהדבר יהיה גלוי בקוד מאשר יעקוף אותו.
 */
function projectLevel(int $projectId, array $user): ?string {
    if (($user['role'] ?? '') === 'admin') return 'admin';

    $st = db()->prepare('SELECT level FROM project_members WHERE project_id = ? AND user_id = ?');
    $st->execute([$projectId, $user['id']]);
    $row = $st->fetch();
    return $row ? (string) $row['level'] : null;
}

function requireProject(int $projectId, array $user, string $minLevel): array {
    $st = db()->prepare('SELECT * FROM projects WHERE id = ?');
    $st->execute([$projectId]);
    $project = $st->fetch();
    if (!$project) throw new RuntimeException('הפרויקט לא נמצא');

    $level = projectLevel($projectId, $user);
    if ($level === null || levelRank($level) < levelRank($minLevel)) {
        logEvent($user, 'deny', "project:$projectId", false, "נדרש $minLevel, יש " . ($level ?? 'כלום'));
        throw new RuntimeException('אין לך הרשאה מספקת בפרויקט הזה');
    }
    $project['my_level'] = $level;
    return $project;
}

/** הפרויקטים שהמשתמש רשאי לראות. מנהל־על רואה הכול. */
function listProjects(array $user): array {
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $sql = "SELECT p.*, u.display_name owner_name,
                   (SELECT COUNT(*) FROM project_members m WHERE m.project_id = p.id) member_count,
                   (SELECT COUNT(*) FROM tasks t
                     WHERE t.project_id = p.id AND t.status NOT IN ('done','cancelled')) open_tasks";
    $sql .= $isAdmin ? ", 'admin' my_level" : ", COALESCE(me.level, '') my_level";
    $sql .= ' FROM projects p LEFT JOIN users u ON u.id = p.created_by';
    $args = [];

    if (!$isAdmin) {
        $sql .= ' JOIN project_members me ON me.project_id = p.id AND me.user_id = ?';
        $args[] = $user['id'];
    }
    $sql .= ' WHERE p.archived = 0 ORDER BY p.name';

    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** יוצר פרויקט. היוצר נעשה מנהל שלו — אחרת אין מי שיצרף אליו אנשים. */
function createProject(array $user, string $name, string $owner, string $repo,
                       string $branch = 'main', string $description = ''): int {
    if (trim($name) === '') throw new InvalidArgumentException('חסר שם לפרויקט');
    foreach ([$owner, $repo] as $part) {
        if ($part !== '' && !preg_match('/^[A-Za-z0-9._-]{1,100}$/', $part)) {
            throw new InvalidArgumentException('שם הריפו או הבעלים אינו תקין');
        }
    }

    db()->prepare('INSERT INTO projects (name, repo_owner, repo_name, default_branch, description,
                                         created_by, created_at)
                   VALUES (?,?,?,?,?,?,?)')
        ->execute([mb_substr(trim($name), 0, 80), $owner, $repo,
                   $branch !== '' ? mb_substr($branch, 0, 100) : 'main',
                   mb_substr($description, 0, 2000), $user['id'], nowTs()]);

    $id = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO project_members (project_id, user_id, level, added_at) VALUES (?,?,?,?)')
        ->execute([$id, $user['id'], 'admin', nowTs()]);

    logEvent($user, 'project.create', "$owner/$repo", true, $name);
    return $id;
}

function projectMembers(int $projectId): array {
    $st = db()->prepare(
        'SELECT m.user_id, m.level, m.added_at, u.username, u.display_name, u.github_login,
                (u.github_token <> "") has_github
           FROM project_members m JOIN users u ON u.id = m.user_id
          WHERE m.project_id = ? ORDER BY m.level DESC, u.display_name'
    );
    $st->execute([$projectId]);
    return array_map(fn($r) => ['user_id' => (int) $r['user_id'], 'level' => $r['level'],
        'username' => $r['username'], 'display_name' => $r['display_name'],
        'github_login' => $r['github_login'], 'has_github' => (bool) $r['has_github'],
        'added_at' => (int) $r['added_at']], $st->fetchAll());
}

function setMember(array $actor, int $projectId, int $userId, string $level): void {
    if (!in_array($level, LEVELS, true)) throw new InvalidArgumentException('דרגת הרשאה לא מוכרת');

    $st = db()->prepare('SELECT id FROM users WHERE id = ?');
    $st->execute([$userId]);
    if (!$st->fetch()) throw new InvalidArgumentException('המשתמש לא נמצא');

    db()->prepare('INSERT INTO project_members (project_id, user_id, level, added_at) VALUES (?,?,?,?)
                   ON CONFLICT(project_id, user_id) DO UPDATE SET level = excluded.level')
        ->execute([$projectId, $userId, $level, nowTs()]);

    logEvent($actor, 'member.set', "project:$projectId", true, "user:$userId → $level");
}

/**
 * מסיר חבר. פרויקט לא נשאר בלי אף מנהל: אחרת רק מנהל־על יוכל לגעת בו,
 * וזו דרך קלה לנעול פרויקט בטעות.
 */
function removeMember(array $actor, int $projectId, int $userId): void {
    $st = db()->prepare("SELECT user_id FROM project_members WHERE project_id = ? AND level = 'admin'");
    $st->execute([$projectId]);
    $admins = array_column($st->fetchAll(), 'user_id');

    if (count($admins) === 1 && (int) $admins[0] === $userId) {
        throw new InvalidArgumentException('אי אפשר להסיר את המנהל האחרון של הפרויקט');
    }

    db()->prepare('DELETE FROM project_members WHERE project_id = ? AND user_id = ?')
        ->execute([$projectId, $userId]);
    logEvent($actor, 'member.remove', "project:$projectId", true, "user:$userId");
}

/* ── ספקי בינה מלאכותית של המשתמש ─────────────────────────────────
 *
 * כל משתמש מחבר את הספקים שיש לו, ובוחר לכל מטלה מי יבצע אותה. המפתחות
 * מוצפנים כמו כל סוד אחר, ואינם יוצאים מכאן — רק "מחובר" וזנב.
 */

function userProviders(int $userId): array {
    $st = db()->prepare('SELECT provider, api_key, model, added_at FROM user_providers WHERE user_id = ?');
    $st->execute([$userId]);

    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $key = decryptSecret((string) $r['api_key']);
        $rows[$r['provider']] = [
            'provider'  => $r['provider'],
            'label'     => PROVIDERS[$r['provider']]['label'] ?? $r['provider'],
            'model'     => (string) $r['model'],
            'connected' => $key !== '',
            'tail'      => secretTail($key),
            'added_at'  => (int) $r['added_at'],
        ];
    }
    return $rows;
}

function setUserProvider(array $user, string $provider, string $key, string $model): void {
    if (!providerExists($provider)) throw new InvalidArgumentException('ספק לא מוכר');
    if ($key !== '') checkProviderKey($provider, $key);

    $model = trim($model) !== '' ? mb_substr(trim($model), 0, 80) : (PROVIDERS[$provider]['default'] ?? '');
    if ($model === '') {
        throw new InvalidArgumentException(
            'צריך לציין שם מודל עבור ' . PROVIDERS[$provider]['label'] . ' — כפי שהוא מופיע אצל הספק'
        );
    }

    db()->prepare('INSERT INTO user_providers (user_id, provider, api_key, model, added_at) VALUES (?,?,?,?,?)
                   ON CONFLICT(user_id, provider) DO UPDATE SET api_key = excluded.api_key,
                                                                model   = excluded.model')
        ->execute([$user['id'], $provider, encryptSecret($key), $model, nowTs()]);

    // הספק הראשון שחובר נעשה ברירת המחדל, אחרת אין למה לשייך מטלה.
    if (trim((string) ($user['default_provider'] ?? '')) === '') {
        db()->prepare('UPDATE users SET default_provider = ? WHERE id = ?')->execute([$provider, $user['id']]);
    }
    logEvent($user, 'provider.connect', $provider, true, $model);
}

function removeUserProvider(array $user, string $provider): void {
    db()->prepare('DELETE FROM user_providers WHERE user_id = ? AND provider = ?')
        ->execute([$user['id'], $provider]);

    if ((string) ($user['default_provider'] ?? '') === $provider) {
        $st = db()->prepare('SELECT provider FROM user_providers WHERE user_id = ? LIMIT 1');
        $st->execute([$user['id']]);
        $next = $st->fetch();
        db()->prepare('UPDATE users SET default_provider = ? WHERE id = ?')
            ->execute([$next ? $next['provider'] : '', $user['id']]);
    }
    logEvent($user, 'provider.disconnect', $provider);
}

function setDefaultProvider(array $user, string $provider): void {
    if ($provider !== '' && !isset(userProviders((int) $user['id'])[$provider])) {
        throw new InvalidArgumentException('הספק אינו מחובר לחשבון שלך');
    }
    db()->prepare('UPDATE users SET default_provider = ? WHERE id = ?')->execute([$provider, $user['id']]);
    logEvent($user, 'provider.default', $provider);
}

/**
 * החיבור שבו תרוץ פעולה של המשתמש.
 *
 * סדר העדיפות: הספק שהמטלה ביקשה במפורש ← ברירת המחדל של המשתמש ←
 * החיבור הכללי של המערכת. כך משתמש בלי מפתח משלו עדיין יכול לעבוד, אם
 * המנהל הגדיר מפתח כללי, ומי שיש לו מפתח משלו נחסם על חשבונו בלבד.
 */
function userConn(array $user, string $provider = '', string $model = ''): array {
    $mine = userProviders((int) $user['id']);
    $pick = $provider !== '' ? $provider : (string) ($user['default_provider'] ?? '');

    if ($pick !== '' && isset($mine[$pick])) {
        $st = db()->prepare('SELECT api_key, model FROM user_providers WHERE user_id = ? AND provider = ?');
        $st->execute([$user['id'], $pick]);
        $row = $st->fetch();
        if ($row) {
            $key = decryptSecret((string) $row['api_key']);
            if ($key !== '') {
                return ['provider' => $pick, 'key' => $key,
                        'model' => $model !== '' ? $model : (string) $row['model'], 'source' => 'user'];
            }
        }
    }

    // ספק שהתבקש במפורש ואינו מחובר — לא נופלים בשקט לספק אחר.
    if ($provider !== '' && $provider !== (string) (aiConn()['provider'] ?? '')) {
        throw new RuntimeException('הספק "' . $provider . '" אינו מחובר לחשבון שלך');
    }

    $global = aiConn();
    return $global['key'] !== '' ? $global + ['source' => 'system']
                                 : ['provider' => '', 'key' => '', 'model' => '', 'source' => 'none'];
}
