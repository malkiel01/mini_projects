<?php
/**
 * שלב 7 (docs/spec.md §8) — מכסה ושימוש.
 *
 * כל קריאה לספק בינה נרשמת (usage) לפי מה שהספק מחזיר, ומתומחרת לפי טבלה
 * בקוד. מי משלם — בעל החיבור שהמפתח שלו שימש (§10.1). מכסה חודשית למשתמש
 * (users.quota_usd), 0 = ☁️ כבוי. ‏monitor (קטלוג, סידור) — עד 20% מהמכסה.
 *
 *   80%  — פס צהוב, שורת אזהרה, והודעת system בערוצים של המשתמש (פעם בחודש)
 *   100% — ☁️ נחשב chat בפרויקטים שלו (מחושב — חוזר לבד ב-1 לחודש),
 *          run-task → 402, ושרשרת החיבורים לא מנוסה בכלל
 */
declare(strict_types=1);

const USAGE_KINDS   = ['develop', 'answer', 'monitor'];
const MONITOR_SHARE = 0.20;
const WARN_SHARE    = 0.80;

/**
 * מחיר לפי מודל: [קלט, פלט] בדולר למיליון טוקנים. ההתאמה היא לפי התחילית
 * הארוכה ביותר, כך שגרסה עם תאריך (claude-haiku-4-5-20251001) נתפסת.
 * מקור — מחירוני הספקים (Anthropic: 2026-10). מודל שלא כאן — המחיר הגבוה של
 * אותו ספק, ואירוע ביומן: עדיף להעריך ביותר מאשר לא לספור.
 */
const PRICES = [
    'anthropic' => [
        'claude-fable-5-1'  => [10.00, 50.00], 'claude-fable-5'   => [10.00, 50.00],
        'claude-mythos-5'   => [10.00, 50.00],
        'claude-opus-5-5'   => [4.00, 20.00],  'claude-opus-5'    => [5.00, 25.00],
        'claude-opus-4'     => [5.00, 25.00],
        'claude-sonnet-5-5' => [2.00, 10.00],  'claude-sonnet-5'  => [2.00, 10.00],
        'claude-sonnet-4'   => [3.00, 15.00],
        'claude-haiku-5-5'  => [0.10, 0.50],   'claude-haiku-4-5' => [1.00, 5.00],
    ],
    'openai' => [
        'gpt-5-nano' => [0.05, 0.40], 'gpt-5-mini' => [0.25, 2.00], 'gpt-5' => [1.25, 10.00],
        'gpt-4.1-nano' => [0.10, 0.40], 'gpt-4.1-mini' => [0.40, 1.60], 'gpt-4.1' => [2.00, 8.00],
        'gpt-4o-mini' => [0.15, 0.60], 'gpt-4o' => [2.50, 10.00],
        'o4-mini' => [1.10, 4.40], 'o3' => [2.00, 8.00],
    ],
    'google' => [
        'gemini-2.5-flash-lite' => [0.10, 0.40], 'gemini-2.5-flash' => [0.30, 2.50], 'gemini-2.5-pro' => [1.25, 10.00],
        'gemini-2.0-flash' => [0.10, 0.40],
    ],
];

function migrateUsage(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS usage (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            at            INTEGER NOT NULL,
            user_id       INTEGER NOT NULL,
            project_id    INTEGER,
            task_id       INTEGER,
            channel_id    INTEGER,
            connection_id INTEGER,
            kind          TEXT NOT NULL,
            provider      TEXT NOT NULL,
            model         TEXT NOT NULL,
            tokens_in     INTEGER NOT NULL DEFAULT 0,
            tokens_out    INTEGER NOT NULL DEFAULT 0,
            cost_usd      REAL NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_usage_user_at ON usage(user_id, at);
        CREATE TABLE IF NOT EXISTS usage_alerts (
            user_id INTEGER NOT NULL,
            month   TEXT NOT NULL,
            level   INTEGER NOT NULL,
            at      INTEGER NOT NULL,
            PRIMARY KEY (user_id, month, level)
        );
    ");
    addColumn($pdo, 'users', 'quota_usd', 'REAL NOT NULL DEFAULT 0');
    // מי הריץ את המטלה במנוע, ובאיזה חיבור — כדי שהדיווח מהמנוע ייזקף לו
    addColumn($pdo, 'tasks', 'run_user_id', 'INTEGER');
    addColumn($pdo, 'tasks', 'run_connection_id', 'INTEGER');
}

/** [קלט, פלט, ידוע?] */
function modelPrice(string $provider, string $model): array {
    $table = PRICES[$provider] ?? [];
    $best = null; $len = -1;
    foreach ($table as $prefix => $p) {
        if (str_starts_with($model, $prefix) && strlen($prefix) > $len) { $best = $p; $len = strlen($prefix); }
    }
    if ($best) return [$best[0], $best[1], true];
    $in = 0.0; $out = 0.0;
    foreach ($table as $p) { $in = max($in, $p[0]); $out = max($out, $p[1]); }
    if (!$table) { $in = 10.0; $out = 50.0; }   // ספק לא מוכר — המחיר הגבוה ביותר שיש
    return [$in, $out, false];
}

function usageCost(string $provider, string $model, int $in, int $out): float {
    [$pi, $po] = modelPrice($provider, $model);
    return round(($in * $pi + $out * $po) / 1_000_000, 6);
}

/** הטוקנים מתוך תשובת הספק: [קלט, פלט]. קריאה ומטמון — בקלט; חשיבה — בפלט. */
function usageFromResponse(string $provider, array $data): array {
    switch ($provider) {
        case 'anthropic':
            $u = $data['usage'] ?? [];
            return [(int) ($u['input_tokens'] ?? 0) + (int) ($u['cache_creation_input_tokens'] ?? 0)
                    + (int) ($u['cache_read_input_tokens'] ?? 0), (int) ($u['output_tokens'] ?? 0)];
        case 'openai':
            $u = $data['usage'] ?? [];
            return [(int) ($u['prompt_tokens'] ?? $u['input_tokens'] ?? 0), (int) ($u['completion_tokens'] ?? $u['output_tokens'] ?? 0)];
        case 'google':
            $u = $data['usageMetadata'] ?? [];
            return [(int) ($u['promptTokenCount'] ?? 0), (int) ($u['candidatesTokenCount'] ?? 0) + (int) ($u['thoughtsTokenCount'] ?? 0)];
    }
    return [0, 0];
}

function monthStart(?int $now = null): int {
    $now ??= nowTs();
    return gmmktime(0, 0, 0, (int) gmdate('n', $now), 1, (int) gmdate('Y', $now));
}
function monthKey(?int $now = null): string { return gmdate('Y-m', $now ?? nowTs()); }

/**
 * רושם קריאה. ‏$ctx: user_id (חובה — מי ששילם), project_id, task_id,
 * channel_id, connection_id, kind. מחזיר את העלות.
 */
function recordUsage(array $ctx, string $provider, string $model, int $in, int $out): float {
    $uid = (int) ($ctx['user_id'] ?? 0);
    if ($uid <= 0) return 0.0;
    $kind = in_array($ctx['kind'] ?? '', USAGE_KINDS, true) ? $ctx['kind'] : 'monitor';
    [, , $known] = modelPrice($provider, $model);
    $cost = usageCost($provider, $model, max(0, $in), max(0, $out));
    db()->prepare('INSERT INTO usage (at, user_id, project_id, task_id, channel_id, connection_id, kind, provider, model,
                                      tokens_in, tokens_out, cost_usd) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([nowTs(), $uid, $ctx['project_id'] ?? null, $ctx['task_id'] ?? null, $ctx['channel_id'] ?? null,
                   $ctx['connection_id'] ?? null, $kind, $provider, mb_substr($model, 0, 120), $in, $out, $cost]);
    if (!$known) logEvent(null, 'usage.unknown_model', "$provider:$model", false, 'תומחר לפי המחיר הגבוה של הספק');
    usageAlerts($uid);
    return $cost;
}

/** המכסה והשימוש החודש. ‏state: off (אין מכסה) | ok | warn (80%) | over (100%). */
function quotaState(int $userId): array {
    $st = db()->prepare('SELECT quota_usd FROM users WHERE id = ?');
    $st->execute([$userId]);
    $quota = (float) ($st->fetchColumn() ?: 0);
    $s = db()->prepare("SELECT COALESCE(SUM(cost_usd),0) total, COALESCE(SUM(CASE WHEN kind='monitor' THEN cost_usd END),0) monitor
                          FROM usage WHERE user_id = ? AND at >= ?");
    $s->execute([$userId, monthStart()]);
    $r = $s->fetch();
    $used = round((float) $r['total'], 4);
    $monitor = round((float) $r['monitor'], 4);
    $state = $quota <= 0 ? 'off' : ($used >= $quota ? 'over' : ($used >= $quota * WARN_SHARE ? 'warn' : 'ok'));
    return ['quota' => $quota, 'used' => $used, 'left' => max(0, round($quota - $used, 4)), 'monitor' => $monitor,
            'monitor_cap' => round($quota * MONITOR_SHARE, 4), 'pct' => $quota > 0 ? round($used / $quota * 100, 1) : 0,
            'state' => $state];
}

/**
 * לפני קריאה: מותר? ‏develop/answer — עד המכסה; monitor — עד 20% ממנה.
 * אין מכסה (0) — ☁️ כבוי: אסור. 402 עם הסבר.
 */
function requireQuota(int $userId, string $kind = 'develop'): void {
    $q = quotaState($userId);
    if ($q['state'] === 'off') {
        throw new AppError('☁️ Platform כבוי לחשבון הזה: לא הוגדרה מכסה חודשית. ניהול ← משתמשים ← "מכסה חודשית ($)"', 402);
    }
    if ($q['state'] === 'over') {
        throw new AppError(sprintf('המכסה החודשית נגמרה ($%.2f מתוך $%.2f). היא מתחדשת ב-1 לחודש, או שמנהל מגדיל אותה.',
                                   $q['used'], $q['quota']), 402);
    }
    if ($kind === 'monitor' && $q['monitor'] >= $q['monitor_cap']) {
        throw new AppError(sprintf('המעקב (קטלוג, סידור) הגיע ל-20%% מהמכסה ($%.2f) — ממשיכים בלי מודל עד החודש הבא.', $q['monitor_cap']), 402);
    }
}

/** ☁️ זמין למשתמש? (יש מכסה, ולא נגמרה) — מזין את המסלול המחושב. */
function platformAvailable(int $userId): bool {
    return in_array(quotaState($userId)['state'], ['ok', 'warn'], true);
}

/** 80%: הודעת system בערוצים של המשתמש — פעם אחת בחודש. */
function usageAlerts(int $userId): void {
    $q = quotaState($userId);
    if (!in_array($q['state'], ['warn', 'over'], true)) return;
    $level = $q['state'] === 'over' ? 100 : 80;
    $ins = db()->prepare('INSERT OR IGNORE INTO usage_alerts (user_id, month, level, at) VALUES (?,?,?,?)');
    $ins->execute([$userId, monthKey(), $level, nowTs()]);
    if ($ins->rowCount() === 0) return;   // כבר נשלחה החודש
    $text = $level === 100
        ? sprintf('☁️ המכסה החודשית נגמרה ($%.2f מתוך $%.2f). עד 1 לחודש — הצ\'אטים עונים במקום Platform.', $q['used'], $q['quota'])
        : sprintf('☁️ נוצלו %d%% מהמכסה החודשית ($%.2f מתוך $%.2f).', (int) $q['pct'], $q['used'], $q['quota']);
    foreach (userChannels($userId) as $ch) {
        try { channelPost($ch, 'system', $text); } catch (Throwable) { /* ערוץ אחד לא עוצר את השאר */ }
    }
}

/** הערוצים הפעילים בפרויקטים שהמשתמש משלם עליהם: הענפים שהוא בעל השורש שלהם. */
function userChannels(int $userId): array {
    $roots = db()->prepare('SELECT id FROM projects WHERE parent_id IS NULL AND created_by = ? AND archived = 0');
    $roots->execute([$userId]);
    $ids = [];
    foreach ($roots->fetchAll(PDO::FETCH_COLUMN) as $r) $ids = array_merge($ids, projectSubtree((int) $r));
    if (!$ids) return [];
    $st = db()->prepare("SELECT * FROM channels WHERE status = 'active' AND project_id IN ("
                        . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    return $st->fetchAll();
}

/** מי משלם על פרויקט: בעל השורש (§8). */
function projectPayer(int $projectId): ?int {
    $st = db()->prepare('SELECT parent_id, created_by FROM projects WHERE id = ?');
    for ($id = $projectId, $i = 0; $id && $i < 16; $i++) {
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) return null;
        if (!$r['parent_id']) return $r['created_by'] === null ? null : (int) $r['created_by'];
        $id = (int) $r['parent_id'];
    }
    return null;
}

/** לגרף: מכסה, עמודות יומיות לפי סוג, ושורה לפרויקט. */
function usageSummary(int $userId): array {
    $from = monthStart();
    $st = db()->prepare("SELECT strftime('%d', at, 'unixepoch') d, kind, SUM(cost_usd) c FROM usage
                          WHERE user_id = ? AND at >= ? GROUP BY d, kind");
    $st->execute([$userId, $from]);
    $days = (int) gmdate('t', $from);
    $daily = [];
    for ($d = 1; $d <= $days; $d++) $daily[] = ['day' => $d, 'develop' => 0.0, 'answer' => 0.0, 'monitor' => 0.0];
    foreach ($st->fetchAll() as $r) {
        $i = (int) $r['d'] - 1;
        if (isset($daily[$i]) && in_array($r['kind'], USAGE_KINDS, true)) $daily[$i][$r['kind']] = round((float) $r['c'], 4);
    }
    $p = db()->prepare("SELECT u.project_id, p.name, p.icon, SUM(u.cost_usd) c, SUM(u.tokens_in + u.tokens_out) t FROM usage u
                          LEFT JOIN projects p ON p.id = u.project_id
                         WHERE u.user_id = ? AND u.at >= ? GROUP BY u.project_id ORDER BY c DESC");
    $p->execute([$userId, $from]);
    $projects = array_map(fn ($r) => ['project_id' => $r['project_id'] === null ? null : (int) $r['project_id'],
                                       'name' => $r['name'] ?? 'בלי פרויקט', 'icon' => $r['icon'] ?? '',
                                       'cost' => round((float) $r['c'], 4), 'tokens' => (int) $r['t']], $p->fetchAll());
    return quotaState($userId) + ['user_id' => $userId, 'month' => monthKey(), 'today' => (int) gmdate('j'),
                                  'daily' => $daily, 'projects' => $projects];
}

function setQuota(array $admin, int $userId, float $usd): void {
    if (($admin['role'] ?? '') !== 'admin') throw new AppError('מכסה — מנהל בלבד', 403);
    if ($usd < 0 || $usd > 100000) throw new InvalidArgumentException('מכסה: 0 עד 100000 דולר');
    db()->prepare('UPDATE users SET quota_usd = ? WHERE id = ?')->execute([round($usd, 2), $userId]);
    logEvent($admin, 'quota.set', "user:$userId", true, (string) round($usd, 2));
}
