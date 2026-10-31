<?php
/**
 * שכבת ספקי הבינה המלאכותית — המפתחות, ההרשאות, המכסה, העלות והיומן (docs/spec-3-ai.md).
 *
 * - המפתחות: בעל המערכת מכניס בממשק; נשמרים מוצפנים (secretbox, data/secret.key — נוצר בשרת, 0600). לא נקראים בחזרה.
 * - ברירת המחדל סגורה: משתמש בלי ספקים מותרים — capabilities הכול false, והאפליקציה מציגה "דרך הצ'אט (סקיל)".
 * - המכסה החודשית בדולר משוער: נבדקת לפני הקריאה (לפי העלות המשוערת), ונרשמת אחריה. התמונות לא נשמרות.
 */

declare(strict_types=1);

require_once __DIR__ . '/clients.php';
require_once __DIR__ . '/ai/providers.php';

function migrateAi(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ai_providers (
            id         TEXT PRIMARY KEY,
            key_enc    TEXT,
            key_tail   TEXT,
            enabled    INTEGER NOT NULL DEFAULT 1,
            models     TEXT NOT NULL DEFAULT '{}',
            updated_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS ai_defaults (
            task     TEXT PRIMARY KEY,
            provider TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS ai_apps (
            client TEXT PRIMARY KEY,
            tasks  TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS ai_users (
            account   TEXT PRIMARY KEY REFERENCES accounts(id),
            providers TEXT NOT NULL DEFAULT '[]',
            monthly   REAL NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS ai_usage (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            account  TEXT NOT NULL,
            app      TEXT NOT NULL,
            task     TEXT NOT NULL,
            provider TEXT NOT NULL,
            cost     REAL NOT NULL,
            ms       INTEGER NOT NULL,
            ok       INTEGER NOT NULL,
            error    TEXT,
            at       TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS ai_usage_acc ON ai_usage(account, at);
    ");
}

// ─── המפתחות ──────────────────────────────────────────────────────────────

function aiSecret(): string {
    static $k = null;
    if ($k !== null) return $k;
    $file = DATA_DIR . '/secret.key';
    if (!is_file($file)) {
        if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) @unlink($tmp);
    }
    $k = base64_decode(trim((string) file_get_contents($file)), true);
    if ($k === false || strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new AppError('מפתח ההצפנה בשרת פגום', 500, 'server');
    return $k;
}

function aiProviderRow(string $id): ?array {
    $st = db()->prepare('SELECT * FROM ai_providers WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function aiCleanProvider(mixed $id): string {
    if (!is_string($id) || !isset(AI_PROVIDERS[$id])) throw new AppError('ספק לא מוכר', 400, 'bad_request');
    return $id;
}

/** שומר מפתח (מוצפן), או מוחק כש-$key ריק. */
function aiSetKey(mixed $provider, mixed $key): void {
    $p = aiCleanProvider($provider);
    $key = trim((string) $key);
    if ($key !== '' && (strlen($key) > 500 || preg_match('/\s/', $key))) throw new AppError('המפתח לא נראה תקין', 400, 'bad_request');
    $enc = null;
    if ($key !== '') {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $enc = base64_encode($nonce . sodium_crypto_secretbox($key, $nonce, aiSecret()));
    }
    db()->prepare("INSERT INTO ai_providers (id, key_enc, key_tail, updated_at) VALUES (?,?,?,?)
                   ON CONFLICT(id) DO UPDATE SET key_enc = excluded.key_enc, key_tail = excluded.key_tail, updated_at = excluded.updated_at")
        ->execute([$p, $enc, $key !== '' ? substr($key, -4) : null, nowIso()]);
}

function aiKey(string $provider): ?string {
    $r = aiProviderRow($provider);
    if (!$r || !$r['key_enc']) return null;
    $raw = base64_decode($r['key_enc'], true);
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), aiSecret());
    return $plain === false ? null : $plain;
}

/** ספק זמין = יש מפתח והוא דלוק. */
function aiAvailable(string $provider): bool {
    $r = aiProviderRow($provider);
    return $r && $r['key_enc'] && (int) $r['enabled'] === 1;
}

function aiModel(string $provider, string $task): string {
    $r = aiProviderRow($provider);
    $m = $r ? (json_decode($r['models'], true) ?: []) : [];
    return (string) ($m[$task] ?? AI_PROVIDERS[$provider]['models'][$task]);
}

// ─── הרשאות ומכסה ─────────────────────────────────────────────────────────

function aiUser(string $account): array {
    $st = db()->prepare('SELECT * FROM ai_users WHERE account = ?');
    $st->execute([$account]);
    $r = $st->fetch();
    return ['providers' => $r ? (json_decode($r['providers'], true) ?: []) : [], 'monthly' => $r ? (float) $r['monthly'] : 0.0];
}

/** המשימות שמותרות לאפליקציה (null בטבלה = כולן). הדף של הענן ('') — כולן. */
function aiAppTasks(string $app): array {
    if ($app === '') return AI_TASKS;
    $st = db()->prepare('SELECT tasks FROM ai_apps WHERE client = ?');
    $st->execute([$app]);
    $t = $st->fetchColumn();
    return $t === false ? AI_TASKS : array_values(array_intersect(AI_TASKS, json_decode($t, true) ?: []));
}

function aiSpent(string $account): float {
    $st = db()->prepare('SELECT COALESCE(SUM(cost), 0) FROM ai_usage WHERE account = ? AND at >= ? AND ok = 1');
    $st->execute([$account, gmdate('Y-m-01\T00:00:00\Z')]);
    return round((float) $st->fetchColumn(), 4);
}

/** הספקים שהמשתמש רשאי להשתמש בהם וזמינים למשימה, לפי סדר: ברירת המחדל קודם. */
function aiProvidersFor(array $account, string $task): array {
    $allowed = aiUser($account['id'])['providers'];
    $list = array_values(array_filter(array_keys(AI_PROVIDERS), fn($p) => in_array($task, AI_PROVIDERS[$p]['tasks'], true)
        && in_array($p, $allowed, true) && aiAvailable($p)));
    $st = db()->prepare('SELECT provider FROM ai_defaults WHERE task = ?');
    $st->execute([$task]);
    $def = $st->fetchColumn();
    if ($def !== false && ($i = array_search($def, $list, true)) !== false) {
        array_splice($list, $i, 1);
        array_unshift($list, $def);
    }
    return $list;
}

/** ai_capabilities — מה זמין למשתמש הזה, באפליקציה הזו (סעיף 4). */
function aiCapabilities(array $account, string $app): array {
    $appTasks = aiAppTasks($app);
    $tasks = [];
    $default = [];
    $providers = [];
    foreach (AI_TASKS as $t) {
        $ps = in_array($t, $appTasks, true) ? aiProvidersFor($account, $t) : [];
        $tasks[$t] = (bool) $ps;
        if ($ps) $default[$t] = $ps[0];
        foreach ($ps as $p) $providers[$p][] = $t;
    }
    $u = aiUser($account['id']);
    return [
        'tasks' => $tasks,
        'providers' => array_map(fn($p) => ['id' => $p, 'name' => AI_PROVIDERS[$p]['name'], 'tasks' => $providers[$p]], array_keys($providers)),
        'default' => (object) $default,
        'quota' => ['used' => aiSpent($account['id']), 'limit' => $u['monthly'], 'unit' => 'usd'],
    ];
}

// ─── הרצה ─────────────────────────────────────────────────────────────────

/**
 * ai_run. $in: task, provider?, prompt?, system?, schema?, size?, transparent?, image? (bytes), mask? (bytes), images? ([bytes]).
 * מחזיר: ['png' => bytes, 'size' => [w,h], 'transparent' => bool] | ['masks' => [[png, label, box]]] | ['json'] | ['text'].
 */
function aiRun(array $account, string $app, array $in): array {
    $task = (string) ($in['task'] ?? '');
    if (!in_array($task, AI_TASKS, true)) throw new AppError('משימה לא מוכרת', 400, 'bad_request');
    if (!in_array($task, aiAppTasks($app), true)) throw new AppError('המשימה לא מותרת לאפליקציה הזו', 403, 'not-allowed');

    $candidates = aiProvidersFor($account, $task);
    $any = array_filter(array_keys(AI_PROVIDERS), fn($p) => in_array($task, AI_PROVIDERS[$p]['tasks'], true) && aiAvailable($p));
    if (!$any) throw new AppError('אין ספק מוגדר למשימה הזו', 503, 'no-provider');
    $provider = isset($in['provider']) && $in['provider'] !== '' ? (string) $in['provider'] : ($candidates[0] ?? null);
    if ($provider === null || !in_array($provider, $candidates, true)) throw new AppError('אין לך הרשאה לספק הזה — פנו למנהל הענן', 403, 'not-allowed');

    // הקלט
    $need = ['remove-background' => ['image'], 'segment' => ['image'], 'generate-image' => ['prompt'], 'inpaint' => ['image', 'mask'], 'complete' => ['prompt']][$task];
    foreach ($need as $f) if (($in[$f] ?? '') === '' || ($in[$f] ?? null) === null) throw new AppError("חסר $f", 400, 'bad_request');
    if (isset($in['prompt']) && mb_strlen((string) $in['prompt']) > 32000) throw new AppError('הטקסט ארוך מדי', 413, 'too-big');
    $input = null;
    if (isset($in['image'])) { $im = aiImage($in['image']); $input = [imagesx($im), imagesy($im)]; }
    if (isset($in['mask'])) aiImage($in['mask']);
    foreach ($in['images'] ?? [] as $x) aiImage($x);
    if (count($in['images'] ?? []) > 4) throw new AppError('עד 4 תמונות', 413, 'too-big');

    // מכסה: לפני הקריאה, לפי העלות המשוערת
    $cost = AI_PROVIDERS[$provider]['cost'][$task];
    $u = aiUser($account['id']);
    if (aiSpent($account['id']) + $cost > $u['monthly']) throw new AppError('המכסה החודשית לבינה מלאכותית נגמרה', 429, 'quota');

    $key = aiKey($provider);
    if ($key === null) throw new AppError('אין ספק מוגדר למשימה הזו', 503, 'no-provider');
    @set_time_limit(130);
    $t0 = microtime(true);
    try {
        $out = aiProviderRun($provider, $task, $in, $key, aiModel($provider, $task));
        if ($task === 'complete' && isset($in['schema']) && !array_key_exists('json', $out)) throw new AppError('המודל לא החזיר JSON', 502, 'provider');
        $result = aiNormalize($task, $out, $input);
    } catch (AppError $e) {
        // complete עם סכמה — עוד נסיון אחד אם ה-JSON לא תקין (סעיף 2)
        if ($task === 'complete' && isset($in['schema']) && $e->codeName === 'provider' && str_contains($e->getMessage(), 'JSON') && empty($in['_retry'])) {
            return aiRun($account, $app, ['_retry' => true] + $in);
        }
        aiLog($account, $app, $task, $provider, 0, $t0, false, $e->getMessage());
        throw $e;
    }
    aiLog($account, $app, $task, $provider, $cost, $t0, true, null);
    return $result + ['provider' => $provider];
}

/** הפלט כמו שהובטח: PNG בגודל הקלט (או זה שיצא, ביצירה), מסכות עם תיבה. */
function aiNormalize(string $task, array $out, ?array $input): array {
    if ($task === 'complete') return array_key_exists('json', $out) ? ['json' => $out['json']] : ['text' => (string) ($out['text'] ?? '')];
    if ($task === 'segment') {
        [$w, $h] = $input;
        $masks = [];
        foreach ($out['masks'] as $i => $m) {
            $mb = aiMaskBox($m, $w, $h);
            if ($mb) $masks[] = ['png' => $mb['png'], 'label' => 'עצם ' . (count($masks) + 1), 'box' => $mb['box']];
        }
        return ['masks' => $masks];
    }
    $size = $input ?? aiSize($out['png']);
    $png = aiFit($out['png'], $size[0], $size[1]);
    return ['png' => $png, 'size' => $size, 'transparent' => $task === 'remove-background' || !empty($out['transparent'])];
}

function aiLog(array $account, string $app, string $task, string $provider, float $cost, float $t0, bool $ok, ?string $error): void {
    db()->prepare('INSERT INTO ai_usage (account, app, task, provider, cost, ms, ok, error, at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$account['id'], $app, $task, $provider, $cost, (int) round((microtime(true) - $t0) * 1000), $ok ? 1 : 0,
                   $error !== null ? mb_substr($error, 0, 300) : null, nowIso()]);
}

// ─── ניהול ────────────────────────────────────────────────────────────────

function aiAdminState(): array {
    $providers = [];
    foreach (AI_PROVIDERS as $id => $p) {
        $r = aiProviderRow($id);
        $providers[] = ['id' => $id, 'name' => $p['name'], 'tasks' => $p['tasks'], 'has_key' => (bool) ($r['key_enc'] ?? null),
            'key_tail' => $r['key_tail'] ?? null, 'enabled' => $r ? (int) $r['enabled'] === 1 : true,
            'models' => array_merge($p['models'], $r ? (json_decode($r['models'], true) ?: []) : [])];
    }
    $defaults = db()->query('SELECT task, provider FROM ai_defaults')->fetchAll(PDO::FETCH_KEY_PAIR);
    $since = gmdate('Y-m-01\T00:00:00\Z');
    $st = db()->prepare("SELECT u.app, u.account, a.name, COUNT(*) AS calls, SUM(u.ok) AS ok, ROUND(SUM(CASE WHEN u.ok = 1 THEN u.cost ELSE 0 END), 4) AS cost
                         FROM ai_usage u LEFT JOIN accounts a ON a.id = u.account WHERE u.at >= ? GROUP BY u.app, u.account ORDER BY cost DESC");
    $st->execute([$since]);
    $rows = $st->fetchAll();
    return [
        'providers' => $providers, 'tasks' => AI_TASKS, 'defaults' => (object) $defaults,
        'apps' => db()->query('SELECT client, tasks FROM ai_apps')->fetchAll(PDO::FETCH_KEY_PAIR),
        'usage' => ['month' => substr($since, 0, 7), 'cost' => round(array_sum(array_column($rows, 'cost')), 2),
                    'calls' => (int) array_sum(array_column($rows, 'calls')), 'rows' => $rows],
    ];
}

function aiAdminProvider(array $in): array {
    $p = aiCleanProvider($in['provider'] ?? null);
    if (array_key_exists('key', $in)) aiSetKey($p, $in['key']);
    if (!aiProviderRow($p)) aiSetKey($p, '');
    if (array_key_exists('enabled', $in)) db()->prepare('UPDATE ai_providers SET enabled = ? WHERE id = ?')->execute([$in['enabled'] ? 1 : 0, $p]);
    if (is_array($in['models'] ?? null)) {
        $models = [];
        foreach ($in['models'] as $task => $m) {
            if (!in_array($task, AI_PROVIDERS[$p]['tasks'], true)) continue;
            $m = trim((string) $m);
            if ($m !== '' && !preg_match('#^[A-Za-z0-9._\-/:]{1,120}$#', $m)) throw new AppError('שם מודל לא תקין', 400, 'bad_request');
            if ($m !== '' && $m !== AI_PROVIDERS[$p]['models'][$task]) $models[$task] = $m;
        }
        db()->prepare('UPDATE ai_providers SET models = ? WHERE id = ?')->execute([json_encode($models), $p]);
    }
    return aiAdminState();
}

function aiAdminTest(mixed $provider): array {
    $p = aiCleanProvider($provider);
    $key = aiKey($p);
    if ($key === null) throw new AppError('אין מפתח לספק הזה', 400, 'no-provider');
    aiTestKey($p, $key);
    return ['ok' => true];
}

function aiAdminDefault(array $in): array {
    $task = (string) ($in['task'] ?? '');
    if (!in_array($task, AI_TASKS, true)) throw new AppError('משימה לא מוכרת', 400, 'bad_request');
    $p = aiCleanProvider($in['provider'] ?? null);
    if (!in_array($task, AI_PROVIDERS[$p]['tasks'], true)) throw new AppError('הספק לא יודע את המשימה הזו', 400, 'bad_request');
    db()->prepare('INSERT INTO ai_defaults (task, provider) VALUES (?, ?) ON CONFLICT(task) DO UPDATE SET provider = excluded.provider')->execute([$task, $p]);
    return aiAdminState();
}

function aiAdminApp(array $in): array {
    $client = cleanClientId($in['client'] ?? null);
    $tasks = $in['tasks'] ?? null;
    if ($tasks === null) {
        db()->prepare('DELETE FROM ai_apps WHERE client = ?')->execute([$client]);
    } else {
        if (!is_array($tasks) || array_diff($tasks, AI_TASKS)) throw new AppError('משימה לא מוכרת', 400, 'bad_request');
        db()->prepare('INSERT INTO ai_apps (client, tasks) VALUES (?, ?) ON CONFLICT(client) DO UPDATE SET tasks = excluded.tasks')
            ->execute([$client, json_encode(array_values($tasks))]);
    }
    return aiAdminState();
}

/** הרשאה למשתמש: ספקים מותרים ומכסה חודשית בדולר. */
function aiAdminUser(array $in): array {
    $a = getAccount((string) ($in['account'] ?? ''));
    $providers = $in['providers'] ?? [];
    if (!is_array($providers) || array_diff($providers, array_keys(AI_PROVIDERS))) throw new AppError('ספק לא מוכר', 400, 'bad_request');
    $monthly = $in['monthly'] ?? 0;
    if (!is_numeric($monthly) || $monthly < 0 || $monthly > 100000) throw new AppError('מכסה לא תקינה', 400, 'bad_request');
    db()->prepare('INSERT INTO ai_users (account, providers, monthly) VALUES (?,?,?)
                   ON CONFLICT(account) DO UPDATE SET providers = excluded.providers, monthly = excluded.monthly')
        ->execute([$a['id'], json_encode(array_values(array_unique($providers))), (float) $monthly]);
    return aiUser($a['id']) + ['spent' => aiSpent($a['id'])];
}
