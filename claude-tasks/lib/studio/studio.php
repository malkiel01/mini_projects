<?php
/**
 * סטודיו — צ'אט פיתוח מלא מול Claude Platform (Managed Agents), בתוך הלוח.
 *
 * העיקרון (docs/studio.md): השיחה היא רשומה אצלנו, והיומן שלנו (studio_events)
 * הוא מה שהממשק מציג. הפלטפורמה מריצה את הסוכן; אנחנו שולחים אליה הודעה פעם
 * אחת, ומושכים ממנה אירועים רק כשיש ריצה פעילה. צ'אט שקט = אפס פניות.
 *
 * מודול עצמאי: לא נוגע בגשר, במאזינים, במנהל או בשער הישנים, ולא נשען עליהם.
 * מהלוח הוא לוקח רק את המשתמש ואת החיבורים המוצפנים (מפתח Anthropic, אסימון GitHub).
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../crypto.php';
require_once __DIR__ . '/../errors.php';
require_once __DIR__ . '/ma.php';

/** גרסת הגדרת הסוכן. שינוי בכלים או בהנחיית הבסיס — להעלות, והסוכן יתעדכן לבד בשימוש הבא. */
const STUDIO_AGENT_REV = 2;
/** שרת ה-MCP של GitHub: דרכו הסוכן פותח PR וממזג. האסימון יושב בכספת של הפלטפורמה, לא בסביבה. */
const STUDIO_GITHUB_MCP = 'https://api.githubcopilot.com/mcp/';
/** מרווח מינימלי בין שתי משיכות אירועים לאותה שיחה, גם אם כמה לשוניות פתוחות. */
const STUDIO_SYNC_GAP = 4;
/** תקרת עמודים במשיכה אחת — כדי שבקשה אחת לא תחרוג מזמן הריצה של PHP. */
const STUDIO_SYNC_PAGES = 8;
const STUDIO_STATUSES = ['new', 'running', 'idle', 'needs_action', 'budget', 'terminated'];

function studioMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("
        CREATE TABLE IF NOT EXISTS studio_settings (
            user_id    INTEGER PRIMARY KEY,
            data       TEXT NOT NULL DEFAULT '{}',
            updated_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS studio_convs (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id       INTEGER NOT NULL,
            title         TEXT NOT NULL,
            repo          TEXT NOT NULL,
            base_branch   TEXT NOT NULL DEFAULT 'main',
            model         TEXT NOT NULL,
            allow_merge   INTEGER NOT NULL DEFAULT 1,
            budget_cents  INTEGER NOT NULL DEFAULT 0,
            session_id    TEXT NOT NULL DEFAULT '',
            status        TEXT NOT NULL DEFAULT 'new',
            status_detail TEXT NOT NULL DEFAULT '',
            pending_ids   TEXT NOT NULL DEFAULT '[]',
            usage         TEXT NOT NULL DEFAULT '{}',
            last_error    TEXT NOT NULL DEFAULT '',
            page_cursor   TEXT NOT NULL DEFAULT '',
            order_mode    TEXT NOT NULL DEFAULT '',
            more          INTEGER NOT NULL DEFAULT 0,
            synced_at     INTEGER NOT NULL DEFAULT 0,
            last_event_at INTEGER NOT NULL DEFAULT 0,
            archived      INTEGER NOT NULL DEFAULT 0,
            created_at    INTEGER NOT NULL,
            updated_at    INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_studio_convs_user ON studio_convs(user_id, archived, updated_at);
        CREATE INDEX IF NOT EXISTS idx_studio_convs_session ON studio_convs(session_id);
        CREATE INDEX IF NOT EXISTS idx_studio_convs_status ON studio_convs(status, synced_at);
        CREATE TABLE IF NOT EXISTS studio_events (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            conv_id INTEGER NOT NULL,
            seq     INTEGER NOT NULL,
            ev_id   TEXT NOT NULL,
            type    TEXT NOT NULL,
            payload TEXT NOT NULL,
            at      INTEGER NOT NULL,
            UNIQUE (conv_id, ev_id)
        );
        CREATE INDEX IF NOT EXISTS idx_studio_events_seq ON studio_events(conv_id, seq);
        CREATE TABLE IF NOT EXISTS studio_webhooks (
            id TEXT PRIMARY KEY,
            at INTEGER NOT NULL
        );
    ");
    // שיחת צוות (lib/studio/team.php): מנהל + אפיון, קוד ובקרה באותו סשן
    addColumn(db(), 'studio_convs', 'team', 'INTEGER NOT NULL DEFAULT 0');
}

/* ── הרשאה ─────────────────────────────────────────────────────── */

/** הסטודיו נותן לסוכן הרשאות מלאות על הריפו — ולכן הוא פתוח רק לבעל הלוח. */
function studioRequireOwner(?array $user): array {
    if (!$user) throw new AppError('נדרשת התחברות', 401);
    if (($user['role'] ?? '') !== 'admin') throw new AppError('הסטודיו פתוח רק לבעל הלוח', 403);
    return $user;
}

/* ── הגדרות וחיבורים ───────────────────────────────────────────── */

function studioSettings(int $userId): array {
    studioMigrate();
    $st = db()->prepare('SELECT data FROM studio_settings WHERE user_id = ?');
    $st->execute([$userId]);
    $d = json_decode((string) ($st->fetch()['data'] ?? '{}'), true);
    return is_array($d) ? $d : [];
}

function studioSaveSettings(int $userId, array $patch): array {
    $s = array_merge(studioSettings($userId), $patch);
    db()->prepare('INSERT INTO studio_settings (user_id, data, updated_at) VALUES (?,?,?)
                   ON CONFLICT(user_id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at')
        ->execute([$userId, json_encode($s, JSON_UNESCAPED_UNICODE), nowTs()]);
    return $s;
}

/** שורת חיבור של המשתמש לפי סוג (anthropic / github), הראשונה בסדר העדיפות, או null. */
function studioConnection(int $userId, string $kind): ?array {
    try {
        $st = db()->prepare('SELECT * FROM connections WHERE user_id = ? AND kind = ? ORDER BY priority, id');
        $st->execute([$userId, $kind]);
    } catch (Throwable $e) {
        return null;   // טבלת החיבורים עוד לא נוצרה — אין חיבור
    }
    foreach ($st->fetchAll() as $r) {
        if (decryptSecret((string) $r['secret']) !== '') return $r;
    }
    return null;
}

/** מפתח ה-API של המשתמש — מהחיבור המוצפן שלו, לעולם לא המפתח הגלובלי שב-config.json. */
function studioKey(int $userId): string {
    $c = studioConnection($userId, 'anthropic');
    return $c ? decryptSecret((string) $c['secret']) : '';
}

function studioGithubToken(int $userId): string {
    $c = studioConnection($userId, 'github');
    return $c ? decryptSecret((string) $c['secret']) : '';
}

/** המודל שייבחר כשלא צוין אחר: מההגדרות, ואם אין — המודל של חיבור ה-Anthropic. */
function studioDefaultModel(int $userId): string {
    $m = (string) (studioSettings($userId)['model'] ?? '');
    if ($m !== '') return $m;
    return (string) (studioConnection($userId, 'anthropic')['model'] ?? '');
}

function studioValidModel(string $m): bool { return (bool) preg_match('/^[a-z0-9][a-z0-9.\-]{2,79}$/', $m); }
function studioValidRepo(string $r): bool  { return (bool) preg_match('~^[A-Za-z0-9_.\-]{1,100}/[A-Za-z0-9_.\-]{1,100}$~', $r) && !str_ends_with($r, '.git'); }
function studioValidBranch(string $b): bool { return (bool) preg_match('~^[A-Za-z0-9_.\-/]{1,120}$~', $b) && !str_contains($b, '..'); }

/* ── הסוכן והסביבה (פעם אחת לכל משתמש) ─────────────────────────── */

/** הנחיית הבסיס של הסוכן. ההנחיה המלאה לכל שיחה — studioSystemPrompt. */
function studioBaseSystem(): string {
    return "אתה סוכן פיתוח תוכנה שעובד בתוך \"מטלות לקלוד — סטודיו\". אתה עונה בעברית, ועובד על הריפו שמחובר לסביבה שלך.";
}

/**
 * ההנחיה המלאה לשיחה: הריפו, שיטת העבודה, ומדיניות המיזוג של השיחה.
 * אין כאן שום סוד — האסימון של GitHub מוזרק מחוץ לארגז החול.
 */
function studioSystemPrompt(array $conv): string {
    $repo = (string) $conv['repo'];
    $name = substr($repo, (int) strrpos($repo, '/') + 1);
    $base = (string) $conv['base_branch'];
    $merge = (int) $conv['allow_merge'] === 1
        ? "מיזוג: יש לך הרשאה למזג בעצמך. אחרי פתיחת ה-PR בדוק ב-GitHub שאין קונפליקטים ושהבדיקות עברו; אם כן — מזג ל-$base (בכלי GitHub, או PUT /repos/$repo/pulls/<מספר>/merge), ואז בדוק את תוצאת הפריסה (ריצות ה-Actions על $base) ודווח אם conclusion הוא success. יש קונפליקט, בדיקה נכשלה, או ש-GitHub חוסם — אל תעקוף ואל תפתור לבד: עצור, הסבר מה קרה ושאל."
        : "מיזוג: אין לך הרשאה למזג. אחרי פתיחת ה-PR תן את הקישור אליו ועצור. בעל הלוח ימזג בעצמו.";
    return <<<TXT
אתה סוכן פיתוח תוכנה שעובד בתוך "מטלות לקלוד — סטודיו", עבור בעל הלוח.

הריפו: $repo, מחובר ב-/workspace/$name (ענף הבסיס: $base). זה סביבת ענן משלך — יש לך bash, קבצים ורשת, בלי אישור לכל פעולה.

איך עובדים:
1. לפני כל שינוי קרא את CLAUDE.md שבשורש הריפו (אם קיים) ואת ה-README של הפרויקט שביקשו ממך לעבוד עליו, ופעל לפיהם.
2. עבוד רק על הפרויקט שהוגדר לך. אל תשנה פרויקטים אחרים או קוד משותף בלי שביקשו.
3. לכל שינוי קוד: ענף חדש מ-$base המעודכן, commit, push לענף, ו-PR אל $base.
4. את ה-PR פותחים בכלי GitHub שמחוברים אליך (שרת ה-MCP בשם github: יצירת PR, קריאת מצב, מיזוג). אם הכלים האלה אינם זמינים — דרך ה-API של GitHub ב-curl (https://api.github.com/repos/$repo/pulls). ההזדהות מוזרקת לבד — אל תחפש אסימון ואל תבקש אותו. קיבלת 401 או 403 בשתי הדרכים — דווח על כך ואל תנסה לעקוף.
5. הרץ את הבדיקות הרלוונטיות לפני פתיחת ה-PR, ודווח מה רץ ומה התוצאה.
6. אסור: push ישיר ל-$base, force push, מחיקת ענפים או היסטוריה.
7. $merge
8. אל תכתוב סודות, מפתחות או אסימונים בהודעות.

התשובות בעברית, קצרות וענייניות. כל תשובה מסתיימת בשורת סיכום אחת של מה נעשה ומה נשאר.
TXT;
}

function studioAgentDef(string $model): array {
    return [
        'name'   => 'מטלות לקלוד — סטודיו',
        'model'  => $model,
        'system' => studioBaseSystem(),
        // חיבור הריפו לסשן נותן git בלבד; פתיחת PR ומיזוג עוברים דרך שרת ה-MCP של GitHub.
        'mcp_servers' => [['type' => 'url', 'name' => 'github', 'url' => STUDIO_GITHUB_MCP]],
        'tools'  => [[
            'type' => 'agent_toolset_20260401',
            // "הרשאות מלאות": כל כלי רץ בלי לעצור לאישור. הבלם הוא התקציב של הסשן.
            'default_config' => ['enabled' => true, 'permission_policy' => ['type' => 'always_allow']],
        ], [
            'type' => 'mcp_toolset',
            'mcp_server_name' => 'github',
            // ברירת המחדל של הפלטפורמה לכלי MCP היא לעצור לאישור בכל קריאה — כאן, כמו בשאר הכלים, בלי.
            'default_config' => ['permission_policy' => ['type' => 'always_allow']],
        ]],
    ];
}

/**
 * כספת בפלטפורמה עם אסימון ה-GitHub, כדי ששרת ה-MCP של GitHub יזדהה. נוצרת פעם אחת לכל משתמש;
 * האסימון הוחלף בחיבורים — האישור בכספת מוחלף. מחזיר את מזהה הכספת, או '' אם ההקמה נכשלה:
 * אז הסשן נפתח בלי הכספת, והסוכן נשען על ה-API של GitHub דרך curl.
 */
function studioEnsureVault(int $uid, string $key, string $token): string {
    $s = studioSettings($uid);
    $v = is_array($s['vault'] ?? null) ? $s['vault'] : [];
    $fp = hash('sha256', $token);
    if (($v['id'] ?? '') !== '' && ($v['cred'] ?? '') !== '' && ($v['fp'] ?? '') === $fp) return (string) $v['id'];
    try {
        if (($v['id'] ?? '') === '') {
            $made = maCreateVault($key, ['display_name' => 'claude-tasks-studio']);
            if (($made['id'] ?? '') === '') throw new MaError('הפלטפורמה לא החזירה מזהה כספת');
            $v = ['id' => (string) $made['id']];
            studioSaveSettings($uid, ['vault' => $v]);
        }
        // כתובת שרת ה-MCP ייחודית בכספת — מוחקים את האישור הישן לפני שיוצרים חדש
        if (($v['cred'] ?? '') !== '') {
            try { maDeleteCredential($key, (string) $v['id'], (string) $v['cred']); } catch (MaError $e) { if ($e->http !== 404) throw $e; }
            $v['cred'] = '';
            studioSaveSettings($uid, ['vault' => $v]);
        }
        $c = maCreateCredential($key, (string) $v['id'], [
            'display_name' => 'GitHub',
            'auth' => ['type' => 'static_bearer', 'mcp_server_url' => STUDIO_GITHUB_MCP, 'token' => $token],
        ]);
        if (($c['id'] ?? '') === '') throw new MaError('הפלטפורמה לא החזירה מזהה אישור');
        $v['cred'] = (string) $c['id'];
        $v['fp'] = $fp;
        studioSaveSettings($uid, ['vault' => $v, 'vault_error' => '']);
        return (string) $v['id'];
    } catch (AppError $e) {
        studioSaveSettings($uid, ['vault_error' => mb_substr($e->getMessage(), 0, 300)]);
        error_log('[studio] vault: ' . $e->getMessage());
        return '';
    }
}

/**
 * מוודא שיש סוכן וסביבה בפלטפורמה, ויוצר אותם פעם אחת. מחזיר את ההגדרות.
 * הסוכן מתעדכן (גרסה חדשה) רק כשההגדרה שלו השתנתה — לא נוצר מחדש בכל שיחה.
 */
function studioEnsureSetup(array $user, string $key, string $model): array {
    $uid = (int) $user['id'];
    $s = studioSettings($uid);

    if (($s['env_id'] ?? '') === '') {
        $envName = 'claude-tasks-studio';
        try {
            $env = maCreateEnvironment($key, [
                'name'   => $envName,
                'config' => ['type' => 'cloud', 'networking' => ['type' => 'unrestricted']],
            ]);
        } catch (MaError $e) {
            // שם סביבה ייחודי בפלטפורמה: אם היא כבר קיימת (הקמה קודמת שנקטעה) — משתמשים בה
            if ($e->http !== 409) throw $e;
            $env = [];
            foreach ((array) (maListEnvironments($key)['data'] ?? []) as $row) {
                if (is_array($row) && ($row['name'] ?? '') === $envName && empty($row['archived_at'])) { $env = $row; break; }
            }
        }
        if (($env['id'] ?? '') === '') throw new MaError('הפלטפורמה לא החזירה מזהה סביבה');
        $s = studioSaveSettings($uid, ['env_id' => (string) $env['id']]);
    }

    $hash = sha1(STUDIO_AGENT_REV . '|' . json_encode(studioAgentDef('')));
    if (($s['agent_id'] ?? '') === '') {
        $a = maCreateAgent($key, studioAgentDef($model));
        if (($a['id'] ?? '') === '') throw new MaError('הפלטפורמה לא החזירה מזהה סוכן');
        $s = studioSaveSettings($uid, ['agent_id' => (string) $a['id'], 'agent_version' => (int) ($a['version'] ?? 1), 'agent_hash' => $hash]);
    } elseif (($s['agent_hash'] ?? '') !== $hash) {
        $a = maUpdateAgent($key, (string) $s['agent_id'], studioAgentDef($model));
        $s = studioSaveSettings($uid, ['agent_version' => (int) ($a['version'] ?? ($s['agent_version'] ?? 1)), 'agent_hash' => $hash]);
    }
    return $s;
}

/* ── שיחות ─────────────────────────────────────────────────────── */

function studioConv(int $userId, int $id): array {
    studioMigrate();
    $st = db()->prepare('SELECT * FROM studio_convs WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    $c = $st->fetch();
    if (!$c) throw new AppError('השיחה לא נמצאה', 404);
    return $c;
}

function studioConvs(int $userId, bool $archived = false): array {
    studioMigrate();
    $st = db()->prepare('SELECT * FROM studio_convs WHERE user_id = ? AND archived = ? ORDER BY updated_at DESC, id DESC LIMIT 200');
    $st->execute([$userId, $archived ? 1 : 0]);
    return $st->fetchAll();
}

function studioCreateConv(array $user, array $in): array {
    studioMigrate();
    $uid = (int) $user['id'];
    $s = studioSettings($uid);
    $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 120);
    if ($title === '') $title = 'שיחה חדשה';
    $repo = trim((string) ($in['repo'] ?? ($s['repo'] ?? '')));
    if (!studioValidRepo($repo)) throw new AppError('ריפו לא תקין. הצורה: owner/name');
    $branch = trim((string) ($in['base_branch'] ?? 'main')) ?: 'main';
    if (!studioValidBranch($branch)) throw new AppError('שם ענף לא תקין');
    $model = trim((string) ($in['model'] ?? '')) ?: studioDefaultModel($uid);
    if (!studioValidModel($model)) throw new AppError('צריך לבחור מודל (למשל מזהה המודל של חיבור ה-Anthropic)');
    $budget = max(0, min(100000, (int) ($in['budget_cents'] ?? ($s['budget_cents'] ?? 2000))));
    $merge = array_key_exists('allow_merge', $in) ? (int) (bool) $in['allow_merge'] : (int) ($s['allow_merge'] ?? 1);
    $team = (int) (bool) ($in['team'] ?? false);

    db()->prepare('INSERT INTO studio_convs (user_id, title, repo, base_branch, model, allow_merge, budget_cents, team, created_at, updated_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$uid, $title, $repo, $branch, $model, $merge, $budget, $team, nowTs(), nowTs()]);
    return studioConv($uid, (int) db()->lastInsertId());
}

function studioUpdateConv(int $id, array $patch): void {
    if (!$patch) return;
    $patch['updated_at'] = nowTs();
    $set = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($patch)));
    db()->prepare("UPDATE studio_convs SET $set WHERE id = ?")->execute([...array_values($patch), $id]);
}

/** יוצר את הסשן בפלטפורמה לשיחה שעוד אין לה. האסימון של GitHub עובר רק כמשאב של הסשן. */
function studioEnsureSession(array $user, array $conv, string $key): array {
    if ((string) $conv['session_id'] !== '') return $conv;
    $uid = (int) $user['id'];
    $token = studioGithubToken($uid);
    if ($token === '') throw new AppError('לא חובר חשבון GitHub. הגדרות הלוח ← חיבורים ← "+ חיבור" ← GitHub');
    $s = studioEnsureSetup($user, $key, (string) $conv['model']);

    // שיחת צוות: הסשן רץ על המנהל, שרשימת חברי הצוות שמורה בהגדרה שלו. המודל וההנחיה של השיחה חלים
    // רק על המנהל; חברי הצוות רצים על מודל ברירת המחדל, כדי שהחלפת מודל בשיחה אחת לא תשנה אותם לכולן.
    $agentId = (string) $s['agent_id'];
    $agentVersion = (int) ($s['agent_version'] ?? 1);
    $system = studioSystemPrompt($conv);
    if ((int) ($conv['team'] ?? 0) === 1) {
        $team = studioEnsureTeam($user, $key, studioDefaultModel($uid) ?: (string) $conv['model']);
        $agentId = (string) $team['lead']['id'];
        $agentVersion = (int) $team['lead']['version'];
        $system .= studioTeamPrompt($conv);
    }

    $def = [
        'agent' => [
            'type'    => 'agent_with_overrides',
            'id'      => $agentId,
            'version' => $agentVersion,
            'model'   => ['id' => (string) $conv['model']],
            'system'  => $system,
        ],
        'environment_id' => (string) $s['env_id'],
        'title'     => mb_substr((string) $conv['title'], 0, 120),
        'resources' => [[
            'type' => 'github_repository',
            'url'  => 'https://github.com/' . $conv['repo'],
            'authorization_token' => $token,
            'checkout' => ['type' => 'branch', 'name' => (string) $conv['base_branch']],
        ]],
        'metadata' => ['app' => 'claude-tasks-studio', 'conv_id' => (string) $conv['id']],
    ];
    // כלי GitHub (MCP) הם תוספת, לא תנאי: בלי כספת הסשן נפתח בלעדיהם — בדיוק כמו סוכן עם git בלבד —
    // כדי ששרת MCP בלי הזדהות לא יפיל או יתקע שיחה. אותו דבר אם הפלטפורמה דוחה את הסשן כשהם מצורפים.
    $plain = $def;
    $plain['agent']['mcp_servers'] = [];
    $plain['agent']['tools'] = [studioAgentDef('')['tools'][0]];
    $vaultId = studioEnsureVault($uid, $key, $token);
    if ($vaultId === '') {
        $def = $plain;
    } else {
        $def['vault_ids'] = [$vaultId];
    }
    // תקציב אפשר לצרף רק ביצירה (אחר כך רק לשנות או להסיר) — ולכן הוא נקבע כאן.
    if ((int) $conv['budget_cents'] > 0) {
        $def['budget'] = ['type' => 'limit', 'max_list_cost' => ['amount' => (string) (int) $conv['budget_cents'], 'currency' => 'USD']];
    }
    try {
        $sess = maCreateSession($key, $def);
    } catch (MaError $e) {
        if ($e->http !== 400 || $vaultId === '') throw $e;
        $sess = maCreateSession($key, $plain);   // נכשל גם כך — השגיאה של הניסיון הזה היא שנזרקת
        studioSaveSettings($uid, ['vault_error' => mb_substr('הסשן נפתח בלי כלי GitHub: ' . $e->getMessage(), 0, 300)]);
    }
    if (($sess['id'] ?? '') === '') throw new MaError('הפלטפורמה לא החזירה מזהה סשן');
    studioUpdateConv((int) $conv['id'], ['session_id' => (string) $sess['id']]);
    return studioConv($uid, (int) $conv['id']);
}

/* ── יומן האירועים ─────────────────────────────────────────────── */

/** מוסיף אירועים ליומן לפי הסדר שניתן. אירוע שכבר קיים (לפי המזהה שלו) — מדולג. מחזיר כמה נוספו. */
function studioAppendEvents(int $convId, array $events): int {
    if (!$events) return 0;
    $pdo = db();
    // נעילת כתיבה מראש: שני סנכרונים של אותה שיחה לא מקצים את אותו מספר סידורי
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $seq = (int) ($pdo->query('SELECT COALESCE(MAX(seq), 0) m FROM studio_events WHERE conv_id = ' . $convId)->fetch()['m']);
        $ins = $pdo->prepare('INSERT OR IGNORE INTO studio_events (conv_id, seq, ev_id, type, payload, at) VALUES (?,?,?,?,?,?)');
        $added = 0;
        foreach ($events as $e) {
            $id = (string) ($e['id'] ?? '');
            $type = (string) ($e['type'] ?? '');
            if ($id === '' || $type === '') continue;
            $at = strtotime((string) ($e['processed_at'] ?? '')) ?: nowTs();
            $ins->execute([$convId, $seq + 1, $id, $type, json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $at]);
            if ($ins->rowCount() > 0) { $seq++; $added++; }
        }
        $pdo->exec('COMMIT');
        return $added;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/** סמן מקומי ביומן (לא אירוע של הפלטפורמה): "נשלח משהו" או "הותאם למצב הסשן". */
function studioLocalMarker(int $convId, string $type, array $extra = []): void {
    studioAppendEvents($convId, [['id' => 'local-' . bin2hex(random_bytes(8)), 'type' => $type] + $extra]);
}

/**
 * מחשב את מצב השיחה מהיומן — לא מסדר ההגעה של webhook ולא מניחוש.
 *
 * אירוע המצב האחרון קובע; אבל אם אחריו יש הודעה או תשובה של המשתמש, העבודה
 * בתור והשיחה "רצה" גם אם אירוע ה-running עוד לא הגיע.
 */
function studioDeriveStatus(int $convId): array {
    $pdo = db();
    $st = $pdo->prepare("SELECT seq, type, payload FROM studio_events WHERE conv_id = ? AND type IN
        ('session.status_running','session.status_rescheduled','session.status_idle','session.status_terminated','local.idle')
        ORDER BY seq DESC LIMIT 1");
    $st->execute([$convId]);
    $last = $st->fetch();

    $st = $pdo->prepare("SELECT COALESCE(MAX(seq), 0) m FROM studio_events WHERE conv_id = ? AND type IN
        ('user.message','user.tool_confirmation','user.custom_tool_result','local.sent')");
    $st->execute([$convId]);
    $userSeq = (int) $st->fetch()['m'];

    if (!$last) return ['status' => $userSeq > 0 ? 'running' : 'new', 'detail' => '', 'pending' => []];
    if ($last['type'] === 'session.status_terminated') return ['status' => 'terminated', 'detail' => '', 'pending' => []];
    if ($last['type'] !== 'session.status_idle' && $last['type'] !== 'local.idle') return ['status' => 'running', 'detail' => $last['type'] === 'session.status_rescheduled' ? 'retrying' : '', 'pending' => []];
    if ($userSeq > (int) $last['seq']) return ['status' => 'running', 'detail' => 'queued', 'pending' => []];

    $p = json_decode((string) $last['payload'], true) ?: [];
    $reason = (string) ($p['stop_reason']['type'] ?? 'end_turn');
    return match ($reason) {
        'requires_action' => ['status' => 'needs_action', 'detail' => $reason, 'pending' => array_values(array_filter((array) ($p['stop_reason']['event_ids'] ?? []), 'is_string'))],
        'budget_reached'  => ['status' => 'budget', 'detail' => $reason, 'pending' => []],
        default           => ['status' => 'idle', 'detail' => $reason, 'pending' => []],
    };
}

/** מעדכן את שורת השיחה לפי היומן: מצב, שימוש, שגיאה אחרונה. */
function studioRefreshConv(int $convId): void {
    $d = studioDeriveStatus($convId);
    $patch = ['status' => $d['status'], 'status_detail' => $d['detail'], 'pending_ids' => json_encode($d['pending'])];

    $st = db()->prepare("SELECT payload FROM studio_events WHERE conv_id = ? AND type = 'session.usage' ORDER BY seq DESC LIMIT 1");
    $st->execute([$convId]);
    if ($u = $st->fetch()) {
        $p = json_decode((string) $u['payload'], true) ?: [];
        unset($p['id'], $p['type'], $p['processed_at']);
        $patch['usage'] = json_encode($p, JSON_UNESCAPED_UNICODE);
    }
    $st = db()->prepare("SELECT seq, payload FROM studio_events WHERE conv_id = ? AND type = 'session.error' ORDER BY seq DESC LIMIT 1");
    $st->execute([$convId]);
    if ($e = $st->fetch()) {
        $p = json_decode((string) $e['payload'], true) ?: [];
        $patch['last_error'] = mb_substr((string) ($p['error']['message'] ?? ($p['error']['type'] ?? 'שגיאה')), 0, 500);
    }
    $st = db()->prepare('SELECT COALESCE(MAX(at), 0) m FROM studio_events WHERE conv_id = ?');
    $st->execute([$convId]);
    $patch['last_event_at'] = (int) $st->fetch()['m'];
    studioUpdateConv($convId, $patch);
}

/* ── סנכרון מול הפלטפורמה ──────────────────────────────────────── */

/**
 * מושך אירועים חדשים של הסשן ליומן שלנו ומעדכן את המצב.
 *
 * סדר העמודים של הפלטפורמה לא מובטח לנו מראש, ולכן הוא מזוהה מהנתונים:
 *   ישן→חדש (asc): שומרים את הסמן של העמוד האחרון וממשיכים ממנו בפעם הבאה.
 *   חדש→ישן (desc): קוראים מההתחלה עד עמוד שכולו כבר מוכר.
 * כפילויות נפסלות לפי מזהה האירוע.
 */
function studioSync(array $conv, string $key): array {
    $cid = (int) $conv['id'];
    $sid = (string) $conv['session_id'];
    if ($sid === '') return $conv;

    $mode = (string) $conv['order_mode'];
    $page = $mode === 'asc' ? (string) $conv['page_cursor'] : '';
    $known = db()->prepare('SELECT 1 FROM studio_events WHERE conv_id = ? AND ev_id = ?');
    $fresh = [];
    $seen = [];
    $lastCursor = $page;
    $more = 0;

    for ($i = 0; $i < STUDIO_SYNC_PAGES; $i++) {
        try {
            $res = maListEvents($key, $sid, $page);
        } catch (MaError $e) {
            // סמן שמור שהפלטפורמה כבר לא מקבלת — מתחילים מההתחלה פעם אחת
            if ($e->http === 400 && $page !== '' && $i === 0) { $page = ''; $lastCursor = ''; $i--; $conv['page_cursor'] = ''; continue; }
            throw $e;
        }
        $data = array_values(array_filter((array) ($res['data'] ?? []), 'is_array'));
        $pageNew = 0;
        foreach ($data as $ev) {
            $id = (string) ($ev['id'] ?? '');
            if ($id === '' || isset($seen[$id])) continue;
            $seen[$id] = true;
            $known->execute([$cid, $id]);
            if ($known->fetch()) continue;
            $fresh[] = $ev;
            $pageNew++;
        }
        if ($mode === '') {
            $ts = array_values(array_filter(array_map(fn ($ev) => strtotime((string) ($ev['processed_at'] ?? '')) ?: 0, $data)));
            if (count($ts) >= 2 && $ts[0] !== $ts[count($ts) - 1]) $mode = $ts[0] > $ts[count($ts) - 1] ? 'desc' : 'asc';
        }
        $next = (string) ($res['next_page'] ?? '');
        $lastCursor = $page;
        if ($mode === 'desc' && $pageNew === 0) break;
        if ($next === '') break;
        $page = $next;
        // נעצרנו בתקרת העמודים — בפעם הבאה ממשיכים מהעמוד שעוד לא נקרא
        if ($i === STUDIO_SYNC_PAGES - 1) { $more = 1; $lastCursor = $next; }
    }

    if ($mode === 'desc') $fresh = array_reverse($fresh);
    studioAppendEvents($cid, $fresh);
    studioUpdateConv($cid, [
        'order_mode'  => $mode,
        'page_cursor' => $mode === 'asc' ? $lastCursor : '',
        'more'        => $more,
        'synced_at'   => nowTs(),
    ]);
    studioRefreshConv($cid);
    return studioConv((int) $conv['user_id'], $cid);
}

/**
 * סנכרון רק אם עבר המרווח המינימלי — תפיסה אטומית, כך שכמה לשוניות או הקורן
 * לא פונים לפלטפורמה במקביל על אותה שיחה.
 */
function studioSyncIfDue(array $conv, string $key, int $gap = STUDIO_SYNC_GAP): array {
    if ((string) $conv['session_id'] === '') return $conv;
    $now = nowTs();
    $st = db()->prepare('UPDATE studio_convs SET synced_at = ? WHERE id = ? AND synced_at <= ?');
    $st->execute([$now, (int) $conv['id'], $now - $gap]);
    if ($st->rowCount() === 0) return $conv;
    return studioSync($conv, $key);
}

/* ── פעולות המשתמש ─────────────────────────────────────────────── */

/** שולח אירועים לסשן ורושם ביומן את מה שהפלטפורמה החזירה (או סמן מקומי אם לא החזירה). */
function studioPush(array $conv, string $key, array $events, bool $marksWork = true): void {
    $res = maSendEvents($key, (string) $conv['session_id'], $events);
    $data = array_values(array_filter((array) ($res['data'] ?? []), fn ($e) => is_array($e) && ($e['id'] ?? '') !== ''));
    $added = studioAppendEvents((int) $conv['id'], $data);
    if ($added === 0 && $marksWork) studioLocalMarker((int) $conv['id'], 'local.sent');
}

/** הודעה מהמשתמש. יוצר סשן אם צריך, מסנכרן קודם (כדי שהסדר ביומן יהיה נכון), ושולח פעם אחת. */
function studioSend(array $user, array $conv, string $text): array {
    $text = trim($text);
    if ($text === '') throw new AppError('ההודעה ריקה');
    if (mb_strlen($text) > 100000) throw new AppError('ההודעה ארוכה מדי');
    if ($conv['status'] === 'terminated') throw new AppError('הסשן הסתיים ואי אפשר להמשיך בו. פתחו שיחה חדשה', 409);
    if ($conv['status'] === 'budget') throw new AppError('השיחה נעצרה בתקרת התקציב. העלו את התקציב כדי להמשיך', 409);
    if ($conv['status'] === 'needs_action') throw new AppError('הסוכן מחכה לאישור פעולה. אשרו או דחו לפני הודעה חדשה', 409);

    $key = studioKey((int) $user['id']);
    if ($key === '') throw new AppError('לא חובר מפתח API של Anthropic. הגדרות הלוח ← חיבורים ← "+ חיבור" ← Anthropic');
    $conv = studioEnsureSession($user, $conv, $key);
    if ((int) $conv['synced_at'] > 0) $conv = studioSync($conv, $key);
    studioPush($conv, $key, [['type' => 'user.message', 'content' => [['type' => 'text', 'text' => $text]]]]);
    studioRefreshConv((int) $conv['id']);
    if ((string) $conv['title'] === 'שיחה חדשה') studioUpdateConv((int) $conv['id'], ['title' => mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 60)]);
    return studioConv((int) $user['id'], (int) $conv['id']);
}

function studioInterrupt(array $user, array $conv): array {
    if ((string) $conv['session_id'] === '') return $conv;
    $key = studioKey((int) $user['id']);
    studioPush($conv, $key, [['type' => 'user.interrupt']], false);
    return studioSync($conv, $key);
}

/** תשובה לכל הפעולות שממתינות לאישור: allow או deny. */
function studioConfirm(array $user, array $conv, string $result, string $denyMessage = ''): array {
    if (!in_array($result, ['allow', 'deny'], true)) throw new AppError('תשובה לא מוכרת');
    $ids = json_decode((string) $conv['pending_ids'], true) ?: [];
    if (!$ids) throw new AppError('אין פעולה שממתינה לאישור', 409);
    $key = studioKey((int) $user['id']);
    $events = array_map(function ($id) use ($result, $denyMessage) {
        $e = ['type' => 'user.tool_confirmation', 'tool_use_id' => $id, 'result' => $result];
        if ($result === 'deny' && $denyMessage !== '') $e['deny_message'] = mb_substr($denyMessage, 0, 2000);
        return $e;
    }, $ids);
    studioPush($conv, $key, $events);
    studioRefreshConv((int) $conv['id']);
    return studioConv((int) $user['id'], (int) $conv['id']);
}

/** שינוי תקרת התקציב של סשן קיים (0 = הסרה, חד-כיוונית). שיחה שעוד אין לה סשן — רק נשמר. */
function studioSetBudget(array $user, array $conv, int $cents): array {
    $cents = max(0, min(100000, $cents));
    $cid = (int) $conv['id'];
    if ((string) $conv['session_id'] === '') {
        studioUpdateConv($cid, ['budget_cents' => $cents]);
        return studioConv((int) $user['id'], $cid);
    }
    if ((int) $conv['budget_cents'] === 0) throw new AppError('לשיחה הזו אין תקציב, ואי אפשר להוסיף תקציב לסשן קיים', 409);
    $key = studioKey((int) $user['id']);
    maUpdateSession($key, (string) $conv['session_id'],
        ['budget' => $cents > 0 ? ['type' => 'limit', 'max_list_cost' => ['amount' => (string) $cents, 'currency' => 'USD']] : null]);
    studioUpdateConv($cid, ['budget_cents' => $cents]);
    if ($conv['status'] === 'budget') studioLocalMarker($cid, 'local.sent');   // העבודה שנעצרה ממשיכה לבד
    studioRefreshConv($cid);
    return studioConv((int) $user['id'], $cid);
}

/**
 * התאמה למצב האמיתי של הסשן, כששיחה "רצה" אצלנו זמן רב בלי אירוע חדש
 * (webhook שאבד, אירוע שלא הגיע). נקרא מהקורן בלבד.
 */
function studioReconcile(array $conv, string $key): array {
    $conv = studioSync($conv, $key);
    if ($conv['status'] !== 'running') return $conv;
    $sess = maGetSession($key, (string) $conv['session_id']);
    $real = (string) ($sess['status'] ?? '');
    if ($real === 'idle') studioLocalMarker((int) $conv['id'], 'local.idle', ['stop_reason' => ['type' => 'end_turn'], 'reconciled' => true]);
    if ($real === 'terminated') studioAppendEvents((int) $conv['id'], [['id' => 'local-term-' . $conv['id'], 'type' => 'session.status_terminated']]);
    studioRefreshConv((int) $conv['id']);
    return studioConv((int) $conv['user_id'], (int) $conv['id']);
}

/* ── מה שהממשק רואה ────────────────────────────────────────────── */

function studioPublicConv(array $c): array {
    $usage = json_decode((string) $c['usage'], true) ?: [];
    return [
        'id' => (int) $c['id'], 'title' => (string) $c['title'], 'repo' => (string) $c['repo'],
        'base_branch' => (string) $c['base_branch'], 'model' => (string) $c['model'],
        'allow_merge' => (int) $c['allow_merge'] === 1, 'budget_cents' => (int) $c['budget_cents'],
        'team' => (int) ($c['team'] ?? 0) === 1,
        'engine' => 'platform', 'has_session' => (string) $c['session_id'] !== '',
        'status' => (string) $c['status'], 'status_detail' => (string) $c['status_detail'],
        'pending' => count(json_decode((string) $c['pending_ids'], true) ?: []),
        'cost_cents' => (int) ($usage['list_cost']['amount'] ?? 0),
        'active_seconds' => (int) ($usage['active_seconds'] ?? 0),
        'last_error' => (string) $c['last_error'], 'more' => (int) $c['more'] === 1,
        'archived' => (int) $c['archived'] === 1,
        'updated_at' => (int) $c['updated_at'], 'last_event_at' => (int) $c['last_event_at'],
    ];
}

function studioBlocksText($content, int $max): string {
    if (is_string($content)) return mb_substr($content, 0, $max);
    $out = [];
    foreach ((array) $content as $b) {
        if (!is_array($b)) continue;
        if (($b['type'] ?? '') === 'text') $out[] = (string) ($b['text'] ?? '');
        elseif (($b['type'] ?? '') !== '') $out[] = '[' . $b['type'] . ']';
    }
    $t = implode("\n", $out);
    return mb_strlen($t) > $max ? mb_substr($t, 0, $max) . "\n… (קוצר)" : $t;
}

function studioToolSummary(string $name, $input): string {
    $in = is_array($input) ? $input : [];
    foreach (['command', 'file_path', 'path', 'pattern', 'url', 'query'] as $k) {
        if (isset($in[$k]) && is_string($in[$k]) && $in[$k] !== '') return mb_substr($in[$k], 0, 600);
    }
    $j = json_encode($in, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $j === '[]' || $j === false ? '' : mb_substr($j, 0, 600);
}

/** אירוע בצורה שהממשק מציג, או null לאירוע שאין מה להראות בו. */
function studioPublicEvent(array $row): ?array {
    $p = json_decode((string) $row['payload'], true) ?: [];
    $base = ['seq' => (int) $row['seq'], 'at' => (int) $row['at']];
    switch ((string) $row['type']) {
        case 'user.message':
            return $base + ['kind' => 'user', 'text' => studioBlocksText($p['content'] ?? [], 100000)];
        case 'agent.message':
            $t = studioBlocksText($p['content'] ?? [], 200000);
            return $t === '' ? null : $base + ['kind' => 'agent', 'text' => $t];
        case 'agent.tool_use': case 'agent.mcp_tool_use': case 'agent.custom_tool_use':
            $name = (string) ($p['name'] ?? 'tool');
            return $base + ['kind' => 'tool', 'id' => (string) ($p['id'] ?? ''), 'name' => $name,
                            'summary' => studioToolSummary($name, $p['input'] ?? []),
                            'ask' => ($p['evaluated_permission'] ?? '') === 'ask'];
        case 'agent.tool_result': case 'agent.mcp_tool_result':
            return $base + ['kind' => 'result', 'for' => (string) ($p['tool_use_id'] ?? ($p['mcp_tool_use_id'] ?? '')),
                            'error' => (bool) ($p['is_error'] ?? false), 'text' => studioBlocksText($p['content'] ?? [], 3000)];
        // צוות: מה שהמנהל מסר לחבר צוות, ומה שחבר הצוות החזיר לו
        case 'agent.thread_message_sent':
            return $base + ['kind' => 'team_out', 'who' => (string) ($p['to_agent_name'] ?? ''), 'text' => studioBlocksText($p['content'] ?? [], 20000)];
        case 'agent.thread_message_received':
            return $base + ['kind' => 'team_in', 'who' => (string) ($p['from_agent_name'] ?? ''), 'text' => studioBlocksText($p['content'] ?? [], 50000)];
        case 'session.error':
            return $base + ['kind' => 'error', 'text' => (string) ($p['error']['message'] ?? ($p['error']['type'] ?? 'שגיאה')),
                            'retry' => (string) ($p['error']['retry_status']['type'] ?? '')];
        case 'session.status_idle': case 'local.idle':
            return $base + ['kind' => 'idle', 'reason' => (string) ($p['stop_reason']['type'] ?? 'end_turn')];
        case 'session.status_terminated':
            return $base + ['kind' => 'end'];
        case 'user.interrupt':
            return $base + ['kind' => 'note', 'text' => '⏹ עצירה'];
        case 'agent.thread_context_compacted':
            return $base + ['kind' => 'note', 'text' => 'ההקשר סוכם כדי לפנות מקום'];
    }
    return null;
}

function studioEventsSince(int $convId, int $seq, int $limit = 500): array {
    $st = db()->prepare('SELECT seq, type, payload, at FROM studio_events WHERE conv_id = ? AND seq > ? ORDER BY seq LIMIT ' . $limit);
    $st->execute([$convId, $seq]);
    $rows = $st->fetchAll();
    $last = $rows ? (int) end($rows)['seq'] : $seq;
    return ['events' => array_values(array_filter(array_map('studioPublicEvent', $rows))), 'last_seq' => $last];
}

require_once __DIR__ . '/team.php';
