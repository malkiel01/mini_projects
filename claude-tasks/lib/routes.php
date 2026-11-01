<?php
/**
 * שלב 6 (docs/spec.md §7) — מסלול אחד: מי עובד על המטלות ומי עונה בערוץ.
 *
 *   chat     — הצ'אטים (Claude Code), על החשבון של בעל הלוח.
 *   platform — Platform, מתוך מכסה (שלבים 7–8). עד אז אף אחד לא עונה שם.
 *
 * ‏projects.route ריק = יורש מהאב; בשורש = config.default_route (ברירת מחדל chat).
 * ‏channels.route גובר על הפרויקט לערוץ אחד. מטלה בלי פרויקט — chat.
 * ההחלפה חלה על עבודה חדשה בלבד: מטלה שכבר נלקחה (tasks.route) נשארת במסלול שלה.
 */
declare(strict_types=1);

const ROUTES = ['chat', 'platform'];

function migrateRoutes(PDO $pdo): void {
    addColumn($pdo, 'projects', 'route', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'tasks', 'route', "TEXT NOT NULL DEFAULT ''");
}

/** ‏code — השם הישן של chat. ריק = chat. אחר → חריגה. */
function normalizeRoute(?string $r, bool $allowEmpty = false): string {
    $r = strtolower(trim((string) $r));
    if ($r === 'code') return 'chat';
    if ($r === '' && $allowEmpty) return '';
    if ($r === '') return 'chat';
    if (!in_array($r, ROUTES, true)) throw new InvalidArgumentException('route: chat או platform');
    return $r;
}

function defaultRoute(): string {
    $r = (string) (config()['default_route'] ?? 'chat');
    return in_array($r, ROUTES, true) ? $r : 'chat';
}

/**
 * המסלול של כל הפרויקטים — חישוב אחד לכל העץ (חוסך שאילתה לכל פרויקט).
 * ‏$wanted = true — מה שנבחר, בלי המכסה. אחרת (§8): ☁️ של מי שאין לו מכסה, או
 * שהמכסה שלו נגמרה, נחשב chat — מחושב ולא נכתב, ולכן חוזר לבד ב-1 לחודש.
 * המשלם — בעל הפרויקט שבשורש הענף.
 */
function projectRoutes(bool $wanted = false): array {
    $rows = [];
    foreach (db()->query('SELECT id, parent_id, route, created_by FROM projects')->fetchAll() as $r) $rows[(int) $r['id']] = $r;
    $memo = [];
    $resolve = function (int $id, int $depth = 0) use (&$resolve, &$memo, $rows): string {
        if (isset($memo[$id])) return $memo[$id];
        $p = $rows[$id] ?? null;
        if (!$p || $depth > 16) return defaultRoute();
        if (in_array($p['route'], ROUTES, true)) return $memo[$id] = $p['route'];
        $parent = (int) ($p['parent_id'] ?? 0);
        return $memo[$id] = $parent ? $resolve($parent, $depth + 1) : defaultRoute();
    };
    $out = [];
    foreach (array_keys($rows) as $id) $out[$id] = $resolve($id);
    if ($wanted) return $out;
    $payerOk = [];
    foreach ($out as $id => $r) {
        if ($r !== 'platform') continue;
        $root = $id;
        for ($i = 0; $i < 16 && !empty($rows[$root]['parent_id']) && isset($rows[(int) $rows[$root]['parent_id']]); $i++) {
            $root = (int) $rows[$root]['parent_id'];
        }
        $payer = (int) ($rows[$root]['created_by'] ?? 0);
        $payerOk[$payer] ??= $payer > 0 && platformAvailable($payer);
        if (!$payerOk[$payer]) $out[$id] = 'chat';
    }
    return $out;
}

function projectRoute(?int $projectId): string {
    if (!$projectId) return 'chat';
    return projectRoutes()[$projectId] ?? defaultRoute();
}

function channelRoute(array $channel): string {
    return in_array($channel['route'] ?? '', ROUTES, true) ? $channel['route'] : projectRoute((int) $channel['project_id']);
}

/** המסלול של מטלה: שנלקחה — שלה; אחרת לפי הפרויקט; בלי פרויקט — chat. */
function taskRoute(array $task): string {
    if ($task['status'] === 'in_progress' && in_array($task['route'] ?? '', ROUTES, true)) return $task['route'];
    return $task['project_id'] === null ? 'chat' : projectRoute((int) $task['project_id']);
}

/** הפרויקטים שהמסלול האפקטיבי שלהם הוא $route — המסנן של claim. */
function projectIdsOnRoute(string $route): array {
    return array_keys(array_filter(projectRoutes(), fn ($r) => $r === $route));
}

/** המסלול של העובד מהכותרת X-Worker-Route. בלי כותרת — chat. */
function workerRoute(): string {
    return normalizeRoute((string) ($_SERVER['HTTP_X_WORKER_ROUTE'] ?? ''));
}

function setProjectRoute(array $user, int $projectId, string $route): void {
    if (authorRole($user) !== 'owner') throw new AppError('מי עונה — בעל הלוח בלבד (זה קובע על חשבון מי)', 403);
    $route = normalizeRoute($route, true);
    db()->prepare('UPDATE projects SET route = ? WHERE id = ?')->execute([$route, $projectId]);
    logEvent($user, 'project.route', "project:$projectId", true, $route === '' ? 'inherit' : $route);
}

function setDefaultRoute(array $user, string $route): string {
    if (authorRole($user) !== 'owner') throw new AppError('ברירת המחדל — בעל הלוח בלבד', 403);
    $route = normalizeRoute($route);
    configSet(['default_route' => $route]);
    logEvent($user, 'route.default', '', true, $route);
    return $route;
}
