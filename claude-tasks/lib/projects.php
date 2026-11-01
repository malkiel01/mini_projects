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
require_once __DIR__ . '/errors.php';
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
        throw new AppError('לא חובר חשבון גיטהאב. הגדרות ← חיבור לגיטהאב');
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

    // חבר בפרויקט האב — חבר גם בתתי-הפרויקטים שלו, באותה דרגה. דרגה ישירה
    // בתת-פרויקט גוברת. עד 8 רמות, כדי שנתון שבור לא יתקע את הבקשה.
    $st = db()->prepare('SELECT level FROM project_members WHERE project_id = ? AND user_id = ?');
    $up = db()->prepare('SELECT parent_id FROM projects WHERE id = ?');
    for ($id = $projectId, $i = 0; $id && $i < 8; $i++) {
        $st->execute([$id, $user['id']]);
        $row = $st->fetch();
        if ($row) return (string) $row['level'];
        $up->execute([$id]);
        $id = (int) ($up->fetch()['parent_id'] ?? 0);
    }
    return null;
}

/** הפרויקט וכל צאצאיו — לסינון מטלות ונושאים לפי ענף בעץ. */
function projectSubtree(int $projectId): array {
    $ids = [$projectId];
    $st = db()->prepare('SELECT id FROM projects WHERE parent_id = ? AND archived = 0');
    for ($i = 0; $i < count($ids) && $i < 500; $i++) {
        $st->execute([$ids[$i]]);
        foreach ($st->fetchAll() as $row) {
            if (!in_array((int) $row['id'], $ids, true)) $ids[] = (int) $row['id'];
        }
    }
    return $ids;
}

/* ── ענף של בעל הלוח (owner_only) ──────────────────────────────────
 *
 * פרויקט שסומן owner_only — הוא וכל הענף שמתחתיו: רק בעל הלוח עונה בו
 * ומחליט בו, ועובד עם האסימון הכללי לא מושך ממנו בלי topic_id מפורש
 * (docs/spec.md, 2.2). הירושה חד-כיוונית: תת-פרויקט לא יכול "לבטל" סימון
 * של אב — אחרת מי שמנהל תת-פרויקט היה פותח לעצמו את מה שננעל מעליו.
 */

/** האם הפרויקט, או אחד מאבותיו, מסומן owner_only. עד 8 רמות, כמו projectLevel. */
function projectOwnerOnly(?int $projectId): bool {
    if (!$projectId) return false;
    $st = db()->prepare('SELECT parent_id, owner_only FROM projects WHERE id = ?');
    for ($id = $projectId, $i = 0; $id && $i < 8; $i++) {
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) return false;
        if ((int) $row['owner_only'] === 1) return true;
        $id = (int) ($row['parent_id'] ?? 0);
    }
    return false;
}

/** כל הפרויקטים שבתוך ענף owner_only — מה שהאסימון הכללי מדלג עליו ב-claim. */
function ownerOnlyProjectIds(): array {
    $ids = [];
    foreach (db()->query('SELECT id FROM projects WHERE owner_only = 1')->fetchAll() as $row) {
        foreach (projectSubtree((int) $row['id']) as $id) $ids[$id] = true;
    }
    return array_keys($ids);
}

/** תפקיד הכותב, כפי שהשרת קובע אותו. אין שדה כזה שמגיע מהלקוח. */
function authorRole(?array $user): string {
    if (!$user) return 'worker';
    return ($user['role'] ?? '') === 'admin' ? 'owner' : 'member';
}

/**
 * האם הערה של המשתמש על מטלה חסומה היא "תשובה" — כלומר מחזירה אותה לתור.
 *
 * בעל הלוח — תמיד. מטלה בלי פרויקט (הלוח האישי) — כל משתמש מחובר, כמו
 * לפני השלב הזה. בפרויקט — רק write ומעלה, ולא בענף owner_only.
 */
function canAnswer(?array $user, array $task): bool {
    if (!$user) return false;
    if (authorRole($user) === 'owner') return true;
    $pid = $task['project_id'] === null ? null : (int) $task['project_id'];
    if ($pid === null) return true;
    if (projectOwnerOnly($pid)) return false;
    $level = projectLevel($pid, $user);
    return $level !== null && levelRank($level) >= levelRank('write');
}

/**
 * סוג ההערה שנשמר בפועל. "תשובה" היא מה שמחזיר מטלה חסומה לתור (addNote),
 * ולכן היא שמורה למי שרשאי לענות: עובד — לעולם לא; חבר בלי write, או בענף
 * owner_only — ההערה נשמרת, אבל כ-comment, והמטלה ממשיכה לחכות לבעל הלוח.
 * הערה רגילה של מי שרשאי, על מטלה חסומה — היא התשובה.
 */
function noteKind(?array $user, array $task, string $requested): string {
    $may = canAnswer($user, $task);
    if ($requested === 'answer' && !$may) return 'comment';
    if ($user && $may && $requested === 'comment' && $task['status'] === 'blocked') return 'answer';
    return $requested;
}

/**
 * הפרויקטים ש-tasks?project_id= מחזיר. ברירת המחדל — כל הענף (הממשק נשען על
 * זה); subtree=0 — רק הפרויקט עצמו.
 */
function taskScopeIds(int $projectId, $subtree = null): array {
    $exact = $subtree === 0 || $subtree === '0' || $subtree === false;
    return $exact ? [$projectId] : projectSubtree($projectId);
}

/**
 * מטלה שמשתמש רשאי לגעת בה. מטלה בלי פרויקט — כל מחובר, כמו היום; מטלה
 * בפרויקט — הדרגה שנדרשה בפרויקט שלה.
 */
function requireTaskAccess(array $user, array $task, string $minLevel): void {
    if ($task['project_id'] === null) return;
    requireProject((int) $task['project_id'], $user, $minLevel);
}

/**
 * תת-פרויקט: תיקייה בתוך הריפו של האב. הריפו והענף עוברים מהאב, כך שגם
 * מנוע ההרצה ועיון בקבצים עובדים בו כמו בכל פרויקט.
 */
function createSubproject(array $user, int $parentId, string $name, string $path, string $icon = ''): int {
    $parent = requireProject($parentId, $user, 'admin');
    $path = trim(str_replace('\\', '/', $path), '/ ');
    if ($path === '' || !preg_match('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$#', $path) || str_contains($path, '..')) {
        throw new InvalidArgumentException('התיקייה אינה תקינה — למשל recipes-app');
    }
    $dup = db()->prepare('SELECT id FROM projects WHERE parent_id = ? AND path = ? AND archived = 0');
    $dup->execute([$parentId, $path]);
    if ($dup->fetch()) throw new InvalidArgumentException("כבר יש תת-פרויקט לתיקייה $path");

    $name = trim($name) !== '' ? mb_substr(trim($name), 0, 80) : $path;
    db()->prepare('INSERT INTO projects (name, repo_owner, repo_name, default_branch, description, created_by,
                                         created_at, parent_id, path, icon)
                   VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$name, $parent['repo_owner'], $parent['repo_name'], $parent['default_branch'], '',
                   $user['id'], nowTs(), $parentId, $path, mb_substr(trim($icon), 0, 8)]);
    $id = (int) db()->lastInsertId();
    logEvent($user, 'project.create', "project:$parentId/$path", true, $name);
    return $id;
}

/**
 * תתי-פרויקטים מתוך התיקיות של הריפו: כל תיקייה עם app.json (הרישום של
 * הפלטפורמה) — שם וסמל מתוכו. עובד בלי גיטהאב כשהלוח עצמו פרוס מאותו
 * ריפו (התיקיות יושבות לידו בשרת). קיים כבר — מדלגים.
 */
function importSubprojects(array $user, int $parentId): array {
    $parent = requireProject($parentId, $user, 'admin');
    if (!empty($parent['parent_id'])) throw new AppError('ייבוא — רק לפרויקט אב (ריפו), לא לתת-פרויקט');
    $root = dirname(__DIR__, 2);
    if ($parent['repo_name'] === '' || basename($root) !== $parent['repo_name']) {
        throw new AppError('ייבוא אוטומטי זמין לריפו שהלוח עצמו פרוס ממנו (' . basename($root) . '). '
            . 'לריפו אחר — "+ תת-פרויקט" ידנית');
    }
    $have = db()->prepare('SELECT path FROM projects WHERE parent_id = ? AND archived = 0');
    $have->execute([$parentId]);
    $existing = array_column($have->fetchAll(), 'path');

    $created = []; $skipped = 0;
    foreach (glob($root . '/*/app.json') ?: [] as $file) {
        $dir = basename(dirname($file));
        if (in_array($dir, $existing, true)) { $skipped++; continue; }
        $app = json_decode((string) file_get_contents($file), true);
        if (!is_array($app)) continue;
        createSubproject($user, $parentId, (string) ($app['name'] ?? $dir), $dir, (string) ($app['icon'] ?? ''));
        $created[] = $dir;
    }
    return ['created' => $created, 'skipped' => $skipped];
}

function requireProject(int $projectId, array $user, string $minLevel): array {
    $st = db()->prepare('SELECT * FROM projects WHERE id = ?');
    $st->execute([$projectId]);
    $project = $st->fetch();
    if (!$project) throw new AppError('הפרויקט לא נמצא', 404);

    $level = projectLevel($projectId, $user);
    if ($level === null || levelRank($level) < levelRank($minLevel)) {
        logEvent($user, 'deny', "project:$projectId", false, "נדרש $minLevel, יש " . ($level ?? 'כלום'));
        throw new AppError('אין לך הרשאה מספקת בפרויקט הזה', 403);
    }
    $project['my_level'] = $level;
    return $project;
}

/** הפרויקטים שהמשתמש רשאי לראות. מנהל־על רואה הכול. */
function listProjects(array $user, ?int $rootId = null): array {
    $isAdmin = ($user['role'] ?? '') === 'admin';

    // המונים — של המטלות של הפרויקט עצמו; הממשק מסכם לאב את כל הענף.
    $sql = "SELECT p.id, p.name, p.repo_owner, p.repo_name, p.default_branch, p.description, p.parent_id,
                   p.path, p.icon, p.created_at, p.owner_only, u.display_name owner_name,
                   (SELECT COUNT(*) FROM project_members m WHERE m.project_id = p.id) member_count,
                   (SELECT COUNT(*) FROM tasks t
                     WHERE t.project_id = p.id AND t.status NOT IN ('done','cancelled')) open_tasks,
                   (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status = 'blocked') n_blocked,
                   (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status = 'in_progress') n_progress,
                   (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status IN ('open','answered')) n_queue,
                   (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status = 'done') n_done
              FROM projects p LEFT JOIN users u ON u.id = p.created_by
             WHERE p.archived = 0 ORDER BY p.name";
    $rows = db()->query($sql)->fetchAll();

    // ‏root_id — רק הענף של פרויקט אחד (למשל הדשבורד של חבילת היצירה).
    $only = $rootId !== null ? array_flip(projectSubtree($rootId)) : null;

    // מי שאינו מנהל־על רואה את מה שהוא חבר בו, וגם את הענף שמתחתיו.
    $out = [];
    foreach ($rows as $p) {
        if ($only !== null && !isset($only[(int) $p['id']])) continue;
        $level = $isAdmin ? 'admin' : projectLevel((int) $p['id'], $user);
        if ($level === null) continue;
        $p['my_level'] = $level;
        $p['owner_only'] = (int) $p['owner_only'];
        // הסימון האפקטיבי — כולל ירושה מאב. זה מה שהשרת אוכף.
        $p['owner_only_effective'] = projectOwnerOnly((int) $p['id']);
        $out[] = $p;
    }
    return $out;
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
    $st = db()->prepare(<<<SQL
        SELECT m.user_id, m.level, m.added_at, u.username, u.display_name, u.github_login,
               (u.github_token <> '') has_github
          FROM project_members m JOIN users u ON u.id = m.user_id
         WHERE m.project_id = ? ORDER BY m.level DESC, u.display_name
        SQL);
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
        throw new AppError('הספק "' . $provider . '" אינו מחובר לחשבון שלך');
    }

    $global = aiConn();
    return $global['key'] !== '' ? $global + ['source' => 'system']
                                 : ['provider' => '', 'key' => '', 'model' => '', 'source' => 'none'];
}

/* ── שיוך מטלות ישנות לפרויקט (docs/spec.md, 4 — U1) ───────────────
 *
 * מטלות שנפתחו לפני עץ הפרויקטים יושבות בלי פרויקט. כאן בעל הלוח בוחר
 * נושאים ומטלות ומעביר אותם לפרויקט: נושא כללי שעובר נעשה שלב של הפרויקט,
 * והמטלות שלו עוברות איתו.
 */

/** המטלות בלי פרויקט, לפי הנושא הכללי שלהן. נושא של פרויקט אחר לא מוצע להעברה. */
function orphanTasks(): array {
    $rows = db()->query(<<<SQL
        SELECT k.id, k.title, k.status, k.topic_id, t.name topic_name, t.project_id topic_project
          FROM tasks k LEFT JOIN topics t ON t.id = k.topic_id
         WHERE k.project_id IS NULL
         ORDER BY t.name IS NULL, t.name, k.id DESC
        SQL)->fetchAll();

    $groups = [];
    foreach ($rows as $r) {
        $movable = $r['topic_id'] !== null && $r['topic_project'] === null;
        $key = $movable ? (string) $r['topic_id'] : 'none';
        $groups[$key] ??= ['topic_id' => $movable ? (int) $r['topic_id'] : null,
                           'topic_name' => $movable ? (string) $r['topic_name'] : '', 'tasks' => []];
        $groups[$key]['tasks'][] = ['id' => (int) $r['id'], 'title' => $r['title'], 'status' => $r['status']];
    }
    // "ללא נושא" בסוף
    if (isset($groups['none'])) { $none = $groups['none']; unset($groups['none']); $groups['none'] = $none; }
    return ['groups' => array_values($groups), 'count' => count($rows)];
}

/**
 * מעביר נושאים ומטלות לפרויקט. רק מה שעדיין בלי פרויקט זז — מטלה ששייכת
 * לפרויקט אחר לא נגררת דרך נושא משותף. מחזיר כמה זזו.
 */
function assignToProject(array $user, int $projectId, array $topicIds, array $taskIds): array {
    if (authorRole($user) !== 'owner') throw new AppError('שיוך מטלות — לבעל הלוח בלבד', 403);
    requireProject($projectId, $user, 'admin');

    $topicIds = array_values(array_unique(array_filter(array_map('intval', $topicIds))));
    $taskIds  = array_values(array_unique(array_filter(array_map('intval', $taskIds))));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $topics = 0; $tasks = 0;
        $t = $pdo->prepare('UPDATE topics SET project_id = ? WHERE id = ? AND project_id IS NULL');
        $k = $pdo->prepare('UPDATE tasks SET project_id = ?, updated_at = ? WHERE topic_id = ? AND project_id IS NULL');
        foreach ($topicIds as $tid) {
            $t->execute([$projectId, $tid]);
            if ($t->rowCount() === 0) continue;   // נושא של פרויקט אחר, או שלא קיים
            $topics++;
            $k->execute([$projectId, nowTs(), $tid]);
            $tasks += $k->rowCount();
        }
        $one = $pdo->prepare('UPDATE tasks SET project_id = ?, updated_at = ? WHERE id = ? AND project_id IS NULL');
        foreach ($taskIds as $id) {
            $one->execute([$projectId, nowTs(), $id]);
            $tasks += $one->rowCount();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    logEvent($user, 'tasks.assign', "project:$projectId", true, "topics:$topics tasks:$tasks");
    return ['topics' => $topics, 'tasks' => $tasks];
}

/**
 * ⚠ זמני — לסידור הפרויקטים שנוצרו לפני עץ הפרויקטים (בבקשת בעל הלוח, 2026-11-01).
 * יוסר אחרי שהסידור יסתיים.
 *
 * מעביר פרויקט לתוך פרויקט אחר ($parentId), או לרמה העליונה (null). המטלות,
 * הנושאים והחברים נשארים על הפרויקט — הם זזים איתו. בעל הלוח בלבד.
 */
function moveProject(array $user, int $projectId, ?int $parentId): void {
    if (authorRole($user) !== 'owner') throw new AppError('העברת פרויקט — לבעל הלוח בלבד', 403);
    requireProject($projectId, $user, 'admin');
    if ($parentId !== null) {
        requireProject($parentId, $user, 'admin');
        // לא לתוך עצמו ולא לתוך צאצא שלו — אחרת נוצר מעגל, והענף נעלם מהעץ
        if (in_array($parentId, projectSubtree($projectId), true)) {
            throw new InvalidArgumentException('אי אפשר להעביר פרויקט לתוך עצמו או לתוך תת-פרויקט שלו');
        }
    }
    db()->prepare('UPDATE projects SET parent_id = ? WHERE id = ?')->execute([$parentId, $projectId]);
    logEvent($user, 'project.move', "project:$projectId", true, 'parent:' . ($parentId ?? 'none'));
}

/* ── עריכה ומחיקה של פרויקט ───────────────────────────────────────── */

/** תיקייה בריפו — אותם כללים כמו ביצירת תת-פרויקט. */
function cleanProjectPath(string $path): string {
    $path = trim(str_replace('\\', '/', $path), '/ ');
    if ($path === '' || !preg_match('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$#', $path) || str_contains($path, '..')) {
        throw new InvalidArgumentException('התיקייה אינה תקינה — למשל recipes-app');
    }
    return $path;
}

/** מחליף את התיקייה של תת-פרויקט. אותה תיקייה פעמיים תחת אותו אב — נדחה. */
function setProjectPath(array $project, string $path): string {
    if (empty($project['parent_id'])) throw new InvalidArgumentException('לפרויקט אב אין תיקייה — יש לו ריפו');
    $path = cleanProjectPath($path);
    $dup = db()->prepare('SELECT id FROM projects WHERE parent_id = ? AND path = ? AND archived = 0 AND id <> ?');
    $dup->execute([$project['parent_id'], $path, $project['id']]);
    if ($dup->fetch()) throw new InvalidArgumentException("כבר יש תת-פרויקט לתיקייה $path");
    return $path;
}

/**
 * מוחק פרויקט — רק ריק: בלי מטלות (גם לא סגורות) ובלי תתי-פרויקטים. פרויקט
 * עם תוכן לא נמחק לעולם מכאן: קודם מעבירים את מה שבו. המחיקה היא הסתרה
 * (archived = 1), והאסימון שלו נפסל. שלבים ריקים שלו מוסתרים איתו.
 */
function deleteProject(array $user, int $projectId): void {
    $project = requireProject($projectId, $user, 'admin');
    $n = db()->prepare('SELECT COUNT(*) c FROM tasks WHERE project_id = ?');
    $n->execute([$projectId]);
    $tasks = (int) $n->fetch()['c'];
    $k = db()->prepare('SELECT COUNT(*) c FROM projects WHERE parent_id = ? AND archived = 0');
    $k->execute([$projectId]);
    $kids = (int) $k->fetch()['c'];
    if ($tasks || $kids) {
        throw new AppError('אפשר למחוק רק פרויקט ריק. ב"' . $project['name'] . '" יש '
            . ($tasks ? "$tasks מטלות" : '') . ($tasks && $kids ? ' ו-' : '') . ($kids ? "$kids תתי-פרויקטים" : '')
            . ' — קודם להעביר אותם', 409);
    }
    db()->prepare("UPDATE projects SET archived = 1, agent_token = '' WHERE id = ?")->execute([$projectId]);
    db()->prepare('UPDATE topics SET archived = 1 WHERE project_id = ?')->execute([$projectId]);
    logEvent($user, 'project.delete', "project:$projectId", true, (string) $project['name']);
}
