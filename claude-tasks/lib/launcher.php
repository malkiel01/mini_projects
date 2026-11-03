<?php
/**
 * שלב 23 (docs/spec.md §19) — "➕ צ'אט חדש" מהלוח: משיק (משימה מתוזמנת עם API trigger ב-claude.ai) שהלוח מפעיל,
 * והוא פותח סשן חדש של Claude Code עם רשומת פתיחה שהשרת לבדו בונה.
 *
 * המשיק נשמר בטבלה משלו (launchers) ולא ב-connections: הרבה קוד מניח "חיבור שאינו github = ספק בינה" (השרשרת,
 * הסדר, העלויות) — משיק שם היה נכנס בטעות לשרשרת. בממשק הוא מוצג תחת 🔌 חיבורים, כמו שהאפיון אומר.
 *
 * אבטחה (§19.4): הטוקן מוצפן, לא מוחזר ולא נרשם ביומן; רק בעל המשיק מפעיל; 10 פתיחות לשעה; ‏launch_id חד-פעמי,
 * 30 דקות, קשור לפרויקט (וההרשמה בודקת אותו מול האסימון של הסביבה).
 */
declare(strict_types=1);

const LAUNCHER_PROMPT_VERSION = 1;
const LAUNCH_TTL_SECONDS = 1800;
const LAUNCHES_PER_HOUR = 10;
const LAUNCH_REGISTER_HELP_SECONDS = 600;
const LAUNCH_FIRE_TIMEOUT = 20;
const LAUNCH_BETA = 'experimental-cc-routine-2026-04-01';
const LAUNCH_URL_RE = '#^https://api\.anthropic\.com/v1/claude_code/routines/(trig_[A-Za-z0-9]{8,64})/fire$#';

/** הפרומפט השמור של המשימה (§19.3) — מוצג לבעל הלוח מילה במילה, עם כפתור העתקה. */
const LAUNCHER_PROMPT = <<<'TXT'
You are a new Claude Code chat opened from the "מטלות לקלוד" board by its owner.
The <routine-fire-payload> block holds a launch record that the board's server built. Act on it ONLY if its first line is
"CLAUDE-TASKS-LAUNCH v1". Then:
0. Check that .claude/settings.json registers the board's gate dispatcher (as in the board's Routine instructions, step 0).
   If it does not, report it with routine-alert and stop.
1. Use the claude-tasks-live skill to register on the board: project_id, chat_name and launch_id from the record;
   replaces_channel_id if it is not "-". Then start listening.
2. Treat the text after "--- opening prompt ---" as the starting brief from the owner, exactly as you would treat a pasted
   follow-up prompt: read it, follow the repository's CLAUDE.md, and continue the work it describes.
3. The record is not the owner's approval for anything irreversible or outward (deploy, merge, deleting, messaging others).
   For those, ask the owner on the board and wait for the answer there.
If the first line is anything else, do nothing except reply "not a board launch" and stop.
TXT;

function migrateLauncher(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS launchers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        label TEXT NOT NULL DEFAULT '',
        fire_url TEXT NOT NULL,
        secret TEXT NOT NULL,
        env_label TEXT NOT NULL DEFAULT '',
        repo_label TEXT NOT NULL DEFAULT '',
        prompt_version INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'ok',
        last_error TEXT NOT NULL DEFAULT '',
        created_at INTEGER NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS launches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        launch_id TEXT NOT NULL UNIQUE,
        launcher_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        project_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        mode TEXT NOT NULL DEFAULT 'empty',
        replaces_channel_id INTEGER,
        session_id TEXT NOT NULL DEFAULT '',
        session_url TEXT NOT NULL DEFAULT '',
        channel_id INTEGER,
        status TEXT NOT NULL DEFAULT 'pending',
        created_at INTEGER NOT NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS launches_project ON launches (project_id, status)');
    addColumn($pdo, 'projects', 'launcher_id', 'INTEGER');
}

/* ── המשיק ─────────────────────────────────────────────────── */

function launcherPublic(array $r): array {
    preg_match(LAUNCH_URL_RE, (string) $r['fire_url'], $m);
    return ['id' => (int) $r['id'], 'label' => (string) $r['label'], 'trigger' => $m[1] ?? '', 'tail' => secretTail(decryptSecret((string) $r['secret'])),
            'env_label' => (string) $r['env_label'], 'repo_label' => (string) $r['repo_label'], 'status' => (string) $r['status'],
            'last_error' => (string) $r['last_error'], 'prompt_ok' => (int) $r['prompt_version'] >= LAUNCHER_PROMPT_VERSION,
            'created_at' => (int) $r['created_at']];
}

function userLaunchers(int $userId): array {
    $st = db()->prepare('SELECT * FROM launchers WHERE user_id = ? ORDER BY id');
    $st->execute([$userId]);
    return array_map('launcherPublic', $st->fetchAll());
}

/** טופס המשיק: בודקים רק את צורת הכתובת ואת תחילית הטוקן — בדיקה אמיתית הייתה פותחת סשן (§19.3, 8). */
function launcherSave(array $user, array $in): array {
    if (authorRole($user) !== 'owner') throw new AppError('משיק צ\'אטים — בעל הלוח בלבד', 403);
    $url = trim((string) ($in['fire_url'] ?? ''));
    if (!preg_match(LAUNCH_URL_RE, $url)) {
        throw new AppError('כתובת ה-Fire לא בצורה הנכונה — היא נראית כך: https://api.anthropic.com/v1/claude_code/routines/trig_…/fire', 422);
    }
    $token = trim((string) ($in['token'] ?? ''));
    $id = (int) ($in['id'] ?? 0);
    if ($token !== '' && !str_starts_with($token, 'sk-ant-')) throw new AppError('הטוקן של המשימה מתחיל ב-sk-ant-… — כנראה הועתק משהו אחר', 422);
    $fields = [mb_substr(trim((string) ($in['label'] ?? '')), 0, 40) ?: 'משיק צ\'אטים', $url,
               mb_substr(trim((string) ($in['env_label'] ?? '')), 0, 80), mb_substr(trim((string) ($in['repo_label'] ?? '')), 0, 120)];
    if ($id) {
        $old = ownLauncher($user, $id);
        db()->prepare("UPDATE launchers SET label = ?, fire_url = ?, env_label = ?, repo_label = ?, status = 'ok', last_error = ''"
                      . ($token !== '' ? ', secret = ?' : '') . ' WHERE id = ?')
            ->execute(array_merge($fields, $token !== '' ? [encryptSecret($token)] : [], [$old['id']]));
    } else {
        if ($token === '') throw new AppError('חסר הטוקן של המשימה (מוצג פעם אחת ב-claude.ai, אחרי Generate token)', 422);
        db()->prepare('INSERT INTO launchers (user_id, label, fire_url, env_label, repo_label, secret, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute(array_merge([(int) $user['id']], $fields, [encryptSecret($token), nowTs()]));
        $id = (int) db()->lastInsertId();
    }
    if (!empty($in['prompt_confirmed'])) db()->prepare('UPDATE launchers SET prompt_version = ? WHERE id = ?')->execute([LAUNCHER_PROMPT_VERSION, $id]);
    logEvent($user, 'launcher.save', "launcher:$id", true, $fields[0]);   // בלי הטוקן
    return launcherPublic(ownLauncher($user, $id));
}

function ownLauncher(array $user, int $id): array {
    $st = db()->prepare('SELECT * FROM launchers WHERE id = ? AND user_id = ?');
    $st->execute([$id, (int) $user['id']]);
    $r = $st->fetch();
    if (!$r) throw new AppError('המשיק לא נמצא', 404);
    return $r;
}

function launcherRemove(array $user, int $id): void {
    ownLauncher($user, $id);
    db()->prepare('UPDATE projects SET launcher_id = NULL WHERE launcher_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM launchers WHERE id = ?')->execute([$id]);
    logEvent($user, 'launcher.remove', "launcher:$id", true, '');
}

/** המשיק של הפרויקט: הבחירה בו או באב הקרוב, אחרת הראשון של המשתמש. רק משיק של המשתמש עצמו. */
function launcherFor(array $user, int $projectId): ?array {
    $st = db()->prepare('SELECT launcher_id FROM projects WHERE id = ?');
    foreach (projectChain($projectId) as [$pid]) {
        $st->execute([(int) $pid]);
        $lid = (int) $st->fetchColumn();
        if ($lid) {
            $l = db()->prepare('SELECT * FROM launchers WHERE id = ?');
            $l->execute([$lid]);
            $row = $l->fetch();
            if ($row && (int) $row['user_id'] === (int) $user['id']) return $row;
            if ($row) return null;   // משיק של משתמש אחר — לא מפעילים בשמו (§19.1, 4)
        }
    }
    $st = db()->prepare('SELECT * FROM launchers WHERE user_id = ? ORDER BY id LIMIT 1');
    $st->execute([(int) $user['id']]);
    return $st->fetch() ?: null;
}

function setProjectLauncher(array $user, int $projectId, ?int $launcherId): void {
    requireProject($projectId, $user, 'admin');
    if ($launcherId) ownLauncher($user, $launcherId);
    db()->prepare('UPDATE projects SET launcher_id = ? WHERE id = ?')->execute([$launcherId ?: null, $projectId]);
}

/* ── הפתיחה (§19.2) ───────────────────────────────────────── */

function launchName(string $name): string {
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) throw new AppError('שם הצ\'אט — בין 2 ל-60 תווים', 422);
    // "CLAUDE-TASKS-LAUNCH" בתוך שם — מצוטט, כדי שאף שורה מלבד הראשונה לא תיראה כרשומה
    return noQuotes(str_replace('CLAUDE-TASKS-LAUNCH', 'CLAUDE-TASKS-LAUNCH (quoted)', $name));
}

/** הפרומפט הפותח, לפי נקודת ההתחלה. בלי שורות הרישום — הרשומה עצמה אומרת איך נרשמים. */
function launchBrief(array $user, int $projectId, string $mode, ?int $replaces, string $name): string {
    if ($mode === 'empty') return '(empty)';
    if ($mode === 'template') {
        $roles = templateResolved($projectId)['roles'];
        $text = chatPrompt($projectId, $roles[0] ?? defaultRole(), null, '', $name);
    } else {
        if (!$replaces) throw new AppError('המשך מצ\'אט — צריך לבחור את הצ\'אט שממשיכים', 422);
        $h = handoffPrompt($user, $replaces, true);
        if (!$h['ready']) throw new AppError('קודם "📝 לסכם את הצ\'אט למעבר לצ\'אט חדש" בגיליון צ\'אט ההמשך — ואז 🚀', 409);
        $text = $h['prompt'];
    }
    // הרישום — לפי הרשומה (launch_id); שורות ה-register של הפרומפט הידני היו רושמות פעמיים
    $lines = array_filter(explode("\n", $text), fn ($l) => !str_contains($l, 'link.sh register') && !str_contains($l, 'אם הרישום נכשל'));
    return trim(str_replace('הפעל את הסקיל claude-tasks-live והירשם:', 'הרישום ללוח — לפי רשומת הפתיחה (כבר בוצע בצעד 1).', implode("\n", $lines)));
}

/** הרשומה — השרת לבדו בונה אותה. שדות מהדפדפן עוברים ניקוי: אין שורה חדשה ואין רשומה שנייה. */
function launchPayload(int $projectId, string $name, ?int $replaces, string $launchId, string $brief): string {
    $brief = str_replace("CLAUDE-TASKS-LAUNCH", "CLAUDE-TASKS-LAUNCH (quoted)", $brief);
    return implode("\n", [
        'CLAUDE-TASKS-LAUNCH v1',
        'board: ' . boardUrl() . '/',
        "project_id: $projectId",
        "chat_name: $name",
        'replaces_channel_id: ' . ($replaces ?: '-'),
        "launch_id: $launchId",
        '--- opening prompt ---',
        $brief,
    ]);
}

function launchFireHttp(string $url, array $headers, string $json): array {
    if (!function_exists('curl_init')) throw new AppError('cURL אינו זמין בשרת');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json,
                            CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => LAUNCH_FIRE_TIMEOUT, CURLOPT_CONNECTTIMEOUT => 10]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false) throw new AppError('הפנייה ל-claude.ai נכשלה: ' . $err, 502);
    return ['status' => $status, 'body' => (string) $body];
}

function launchExpire(): void {
    db()->prepare("UPDATE launches SET status = 'expired' WHERE status = 'pending' AND created_at < ?")->execute([nowTs() - LAUNCH_TTL_SECONDS]);
}

/** ‏launch-chat: {project_id, name, mode, replaces_channel_id?} → session_url. ‏$transport(url, headers, json) — לבדיקות. */
function launchChat(array $user, int $projectId, string $name, string $mode, ?int $replaces, ?callable $transport = null): array {
    if (authorRole($user) !== 'owner') throw new AppError('פתיחת צ\'אט מהלוח — בעל הלוח בלבד', 403);
    requireProject($projectId, $user, 'read');
    if (!in_array($mode, ['empty', 'template', 'followup'], true)) throw new InvalidArgumentException('mode: empty / template / followup');
    $name = launchName($name);
    $l = launcherFor($user, $projectId);
    if (!$l) throw new AppError('אין משיק צ\'אטים — הגדרות ← 🔌 חיבורים ← + חיבור ← 🚀 משיק צ\'אטים (3 דק\')', 409, 'no_launcher');
    if ($replaces) {
        $c = getChannel($replaces);
        if (!$c || !in_array((int) $c['project_id'], projectSubtree($projectId), true) && (int) $c['project_id'] !== $projectId) {
            throw new AppError('הצ\'אט שממשיכים לא בפרויקט הזה', 422);
        }
    }
    launchExpire();
    $n = db()->prepare('SELECT COUNT(*) FROM launches WHERE launcher_id = ? AND created_at > ?');
    $n->execute([(int) $l['id'], nowTs() - 3600]);
    if ((int) $n->fetchColumn() >= LAUNCHES_PER_HOUR) throw new AppError('הגעת ל-' . LAUNCHES_PER_HOUR . ' פתיחות בשעה האחרונה — נסה שוב מאוחר יותר', 429);
    if ($replaces) {
        $p = db()->prepare("SELECT COUNT(*) FROM launches WHERE replaces_channel_id = ? AND status = 'pending'");
        $p->execute([$replaces]);
        if ((int) $p->fetchColumn()) throw new AppError('כבר נפתח צ\'אט המשך לצ\'אט הזה — מחכים שיירשם (עד 30 דק\')', 409);
    }
    $brief = launchBrief($user, $projectId, $mode, $replaces, $name);
    $launchId = 'L-' . bin2hex(random_bytes(12));
    $json = json_encode(['text' => launchPayload($projectId, $name, $replaces, $launchId, $brief)], JSON_UNESCAPED_UNICODE);
    $headers = ['Authorization: Bearer ' . decryptSecret((string) $l['secret']), 'anthropic-beta: ' . LAUNCH_BETA,
                'anthropic-version: 2023-06-01', 'Content-Type: application/json'];
    $res = $transport ? $transport((string) $l['fire_url'], $headers, $json) : launchFireHttp((string) $l['fire_url'], $headers, $json);
    $data = json_decode((string) ($res['body'] ?? ''), true) ?: [];
    $status = (int) ($res['status'] ?? 0);
    if ($status !== 200 || empty($data['claude_code_session_url'])) {
        $msg = (string) ($data['error']['message'] ?? '');
        $why = match (true) {
            $status === 401, $status === 403 => 'הטוקן של המשיק לא תקף — הפקה מחדש ב-claude.ai/code/routines (Edit ← API ← Regenerate), והדבקה כאן',
            $status === 429 => 'הגעת למגבלת 30 פתיחות בשעה של המשימה ב-claude.ai — נסה שוב בעוד כמה דקות',
            $status === 404 => 'המשימה לא נמצאה ב-claude.ai — אולי נמחקה. כתובת ה-Fire צריכה להיות של משימה קיימת',
            default => "claude.ai החזיר שגיאה ($status)" . ($msg !== '' ? ": $msg" : ''),
        };
        db()->prepare('UPDATE launchers SET status = ?, last_error = ? WHERE id = ?')
            ->execute([$status === 401 || $status === 403 ? 'failing' : $l['status'], mb_substr($why, 0, 300), $l['id']]);
        logEvent($user, 'launch.fire', "project:$projectId", false, "$status");
        throw new AppError($why, $status === 429 ? 429 : 502);
    }
    db()->prepare("UPDATE launchers SET status = 'ok', last_error = '' WHERE id = ?")->execute([$l['id']]);
    db()->prepare('INSERT INTO launches (launch_id, launcher_id, user_id, project_id, name, mode, replaces_channel_id, session_id, session_url, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$launchId, (int) $l['id'], (int) $user['id'], $projectId, $name, $mode, $replaces, (string) ($data['claude_code_session_id'] ?? ''),
                   (string) $data['claude_code_session_url'], nowTs()]);
    logEvent($user, 'launch.fire', "project:$projectId", true, "$launchId · " . ($data['claude_code_session_id'] ?? ''));
    return ['launch_id' => $launchId, 'session_url' => (string) $data['claude_code_session_url'], 'name' => $name];
}

/** השורות הממתינות ברשימת הערוצים: "🚀 <שם> · נפתח… · פתח". */
function pendingLaunches(array $user, int $projectId): array {
    launchExpire();
    $st = db()->prepare("SELECT * FROM launches WHERE project_id = ? AND user_id = ? AND (status = 'pending' OR (status = 'expired' AND created_at > ?))
                          ORDER BY id DESC LIMIT 10");
    $st->execute([$projectId, (int) $user['id'], nowTs() - 2 * LAUNCH_TTL_SECONDS]);
    return array_map(fn ($r) => ['launch_id' => $r['launch_id'], 'name' => $r['name'], 'session_url' => $r['session_url'], 'status' => $r['status'],
                                 'age' => nowTs() - (int) $r['created_at'], 'help' => nowTs() - (int) $r['created_at'] >= LAUNCH_REGISTER_HELP_SECONDS,
                                 'replaces' => $r['replaces_channel_id'] === null ? null : (int) $r['replaces_channel_id']], $st->fetchAll());
}

/**
 * ההרשמה עם --launch (§19.2, 5): חד-פעמי, עד 30 דקות, בענף של האסימון. מחזיר את מה שהרשומה קובעת — הפרויקט, השם
 * וההחלפה — כך שהצ'אט לא יכול להירשם בשם אחר או בפרויקט אחר.
 */
function launchClaim(array $scope, string $launchId): array {
    launchExpire();
    $st = db()->prepare('SELECT * FROM launches WHERE launch_id = ?');
    $st->execute([$launchId]);
    $l = $st->fetch();
    if (!$l) throw new AppError('launch_id לא מוכר — הצ\'אט לא נפתח מהלוח', 403, 'launch_unknown');
    if ($l['status'] === 'registered') throw new AppError('ה-launch_id הזה כבר שימש — כל פתיחה נרשמת פעם אחת', 409, 'launch_used');
    if ($l['status'] !== 'pending') throw new AppError('עברו יותר מ-30 דקות מהפתיחה — פתח צ\'אט חדש מהלוח', 410, 'launch_expired');
    if (!empty($scope['project_id']) && !in_array((int) $l['project_id'], projectSubtree((int) $scope['project_id']), true)) {
        throw new AppError('הפתיחה שייכת לפרויקט אחר מהאסימון של הסביבה', 403, 'token_scope');
    }
    return ['id' => (int) $l['id'], 'project_id' => (int) $l['project_id'], 'name' => (string) $l['name'],
            'replaces' => $l['replaces_channel_id'] === null ? null : (int) $l['replaces_channel_id']];
}

function launchRegistered(int $launchRow, int $channelId): void {
    db()->prepare("UPDATE launches SET status = 'registered', channel_id = ? WHERE id = ? AND status = 'pending'")->execute([$channelId, $launchRow]);
}

/** המדריך (§19.3): הפרומפט השמור, הגרסה, הריפו והסביבה. */
function launcherGuide(array $user, int $projectId): array {
    $repo = $projectId ? projectRepo($projectId) : [];
    return ['prompt' => LAUNCHER_PROMPT, 'version' => LAUNCHER_PROMPT_VERSION, 'repo' => $repo, 'environment' => chatEnvironmentName(),
            'routines_url' => 'https://claude.ai/code/routines', 'task_name' => 'מטלות לקלוד — צ\'אט חדש'];
}
