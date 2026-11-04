<?php
/**
 * פתיחת צ'אטים — docs/spec.md §5.9 (שלב 11).
 *
 * הכלל: בעל המערכת לא ממלא שום דבר ביד. הלוח מרכיב לכל פרויקט ותפקיד פרומפט
 * גמור (מספר הפרויקט, השם, קובצי ההקשר, ההיררכיה, למי מדווחים) מתוך תבנית
 * שנשמרת בלוח ועוברת בירושה שדה-שדה מהאבות — מגדירים פעם אחת על "חבילת היצירה"
 * וכל תת-פרויקט מקבל.
 *
 * כאן גם: "הצ'אט מתארך" (channel-health מה-hook של Stop), וצ'אט המשך בקליק
 * (handoff_request ← תקציר מהצ'אט ← פרומפט המשך עם --replaces).
 */
declare(strict_types=1);

const TEMPLATE_FIELDS = ['name_pattern', 'roles', 'context_files', 'hierarchy', 'notify_session', 'extra'];
/** ברירת המחדל להודעת ההצטרפות: צ'אט האפיון של "מטלות לקלוד" (§5.9.5). */
/** ש1 (audit-2026-11): הודעת הצטרפות — רק בלוח. ריק = אין הודעה לשום צ'אט; הערך הישן (צ'אט האפיון) נחשב ריק. */
const NOTIFY_SESSION_DEFAULT = '';
const NOTIFY_SESSION_OLD_DEFAULT = 'session_018g4NYGkLv7LXXa1DqxwnRS';
const DEFAULT_NAME_PATTERN = '{project} - {role}';
const HANDOFF_WAIT_SECONDS = 120;
const HANDOFF_FALLBACK_MESSAGES = 30;
/** §15.13: בקשת תקציר נשארת פתוחה שעה, ואז נסגרת בשורה אפורה. ההערכה — מ-20 ההעברות האחרונות של המשלם, מ-3 ומעלה. */
const HANDOFF_EXPIRE_SECONDS = 3600;
const HANDOFF_STATS_KEEP = 20;
const HANDOFF_STATS_MIN = 3;

function migrateTemplates(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS handoff_stats (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
                                                           seconds INTEGER NOT NULL, at INTEGER NOT NULL)');
    // שדה NULL — יורש מהאב הקרוב שמגדיר אותו
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chat_templates (
            project_id     INTEGER PRIMARY KEY REFERENCES projects(id) ON DELETE CASCADE,
            name_pattern   TEXT,
            roles          TEXT,
            context_files  TEXT,
            hierarchy      TEXT,
            notify_session TEXT,
            extra          TEXT,
            updated_at     INTEGER NOT NULL DEFAULT 0
        );
    ");
    // "הצ'אט מתארך" — נמדד בצד הצ'אט (hook-stop.sh), נשמר על הערוץ ולא כהודעה
    addColumn($pdo, 'channels', 'health_bytes', 'INTEGER NOT NULL DEFAULT 0');
    addColumn($pdo, 'channels', 'health_turns', 'INTEGER NOT NULL DEFAULT 0');
    addColumn($pdo, 'channels', 'health_compactions', 'INTEGER NOT NULL DEFAULT 0');
    addColumn($pdo, 'channels', 'health_at', 'INTEGER NOT NULL DEFAULT 0');
    // צ'אט המשך: הערוץ הישן מצביע על מי שהחליף אותו
    addColumn($pdo, 'channels', 'replaced_by', 'INTEGER');
}

/* ── התבנית ──────────────────────────────────────────────────────── */

/**
 * התבנית האפקטיבית של פרויקט: כל שדה — מהפרויקט, או מהאב הקרוב שמגדיר אותו.
 * ‏sources[field] = {project_id, name} של מי שקבע, או null (ברירת מחדל).
 */
function templateResolved(int $projectId): array {
    $chain = projectChain($projectId);   // מהקרוב לרחוק
    $st = db()->prepare('SELECT * FROM chat_templates WHERE project_id = ?');
    $values = array_fill_keys(TEMPLATE_FIELDS, null);
    $sources = array_fill_keys(TEMPLATE_FIELDS, null);
    $own = array_fill_keys(TEMPLATE_FIELDS, null);
    foreach ($chain as $i => [$pid, $pname]) {
        $st->execute([$pid]);
        $row = $st->fetch();
        if (!$row) continue;
        foreach (TEMPLATE_FIELDS as $f) {
            $v = $row[$f];
            if ($v === null || $v === '') continue;
            if ($i === 0) $own[$f] = $v;
            if ($values[$f] === null) { $values[$f] = $v; $sources[$f] = ['project_id' => $pid, 'name' => $pname]; }
        }
    }
    $roles = json_decode((string) ($values['roles'] ?? ''), true);
    return [
        'name_pattern'   => (string) ($values['name_pattern'] ?? DEFAULT_NAME_PATTERN),
        'roles'          => is_array($roles) && $roles ? $roles : [defaultRole()],
        'context_files'  => (string) ($values['context_files'] ?? ''),
        'hierarchy'      => (string) ($values['hierarchy'] ?? ''),
        'notify_session' => (fn ($s) => $s === NOTIFY_SESSION_OLD_DEFAULT ? '' : $s)((string) ($values['notify_session'] ?? (config()['chat_notify_session_default'] ?? NOTIFY_SESSION_DEFAULT))),
        'extra'          => (string) ($values['extra'] ?? ''),
        'sources'        => $sources,
        'own'            => array_map(fn ($v) => $v === null ? null : ($v), $own),
    ];
}

function defaultRole(): array {
    return ['key' => 'chat', 'label' => 'צ\'אט', 'icon' => '💬', 'reports_to' => '', 'brief' => ''];
}

/** תפקידים מהעורך — מנוקים. מפתח באנגלית (kebab), עד 20 תפקידים. */
function normalizeRoles($raw): array {
    if (!is_array($raw) || !array_is_list($raw)) throw new InvalidArgumentException('roles: רשימה של תפקידים');
    $out = []; $keys = [];
    foreach (array_slice($raw, 0, 20) as $r) {
        $key = strtolower(trim((string) ($r['key'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,29}$/', $key)) throw new InvalidArgumentException("מפתח תפקיד לא תקין: \"$key\" (אנגלית, אותיות קטנות ומקף)");
        if (isset($keys[$key])) throw new InvalidArgumentException("אותו מפתח פעמיים: $key");
        $keys[$key] = true;
        $label = noQuotes(mb_substr(trim((string) ($r['label'] ?? '')), 0, 40));
        if ($label === '') throw new InvalidArgumentException("לתפקיד $key חסר שם");
        $out[] = ['key' => $key, 'label' => $label, 'icon' => mb_substr(trim((string) ($r['icon'] ?? '')), 0, 8) ?: '💬',
                  'reports_to' => mb_substr(trim((string) ($r['reports_to'] ?? '')), 0, 80),
                  'brief' => mb_substr(trim((string) ($r['brief'] ?? '')), 0, 2000)];
    }
    return $out;
}

/** מירכאות כפולות בשם היו שוברות את --name "…" בשורת הפקודה. */
function noQuotes(string $s): string { return str_replace(['"', '`', '$', '\\'], ['״', '', '', ''], $s); }

/**
 * שמירה — בעל הלוח בלבד (התבנית מעצבת כל צ'אט בענף). שדה שלא נשלח — לא משתנה;
 * שדה ריק / null — חוזר לירושה.
 */
function saveTemplate(array $user, int $projectId, array $fields): array {
    if (authorRole($user) !== 'owner') throw new AppError('תבנית הצ\'אטים — בעל הלוח בלבד', 403);
    requireProject($projectId, $user, 'read');
    $st = db()->prepare('SELECT * FROM chat_templates WHERE project_id = ?');
    $st->execute([$projectId]);
    $row = $st->fetch() ?: array_fill_keys(TEMPLATE_FIELDS, null);
    foreach (TEMPLATE_FIELDS as $f) {
        if (!array_key_exists($f, $fields)) continue;
        $v = $fields[$f];
        if ($v === null || $v === '' || $v === []) { $row[$f] = null; continue; }
        $row[$f] = match ($f) {
            'roles'          => json_encode(normalizeRoles($v), JSON_UNESCAPED_UNICODE),
            'name_pattern'   => (function ($p) {
                $p = noQuotes(mb_substr(trim((string) $p), 0, 80));
                if (!str_contains($p, '{role}')) throw new InvalidArgumentException('תבנית השם חייבת לכלול {role}');
                return $p;
            })($v),
            'notify_session' => (function ($s) {
                $s = trim((string) $s);
                if ($s !== '' && !preg_match('/^session_[A-Za-z0-9]{10,60}$/', $s)) throw new InvalidArgumentException('notify_session: מזהה session_…');
                return $s;
            })($v),
            'context_files'  => implode("\n", array_slice(array_values(array_filter(array_map(
                                    fn ($l) => trim($l), explode("\n", (string) $v)), fn ($l) => $l !== '')), 0, 30)),
            default          => mb_substr(trim((string) $v), 0, 4000),
        };
    }
    db()->prepare('DELETE FROM chat_templates WHERE project_id = ?')->execute([$projectId]);
    db()->prepare('INSERT INTO chat_templates (project_id, name_pattern, roles, context_files, hierarchy, notify_session, extra, updated_at)
                   VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$projectId, $row['name_pattern'], $row['roles'], $row['context_files'], $row['hierarchy'],
                   $row['notify_session'], $row['extra'], nowTs()]);
    logEvent($user, 'template.save', "project:$projectId");
    return templateResolved($projectId);
}

function setChatEnvironment(array $user, string $name): string {
    if (authorRole($user) !== 'owner') throw new AppError('שם הסביבה — בעל הלוח בלבד', 403);
    $name = mb_substr(trim($name), 0, 80);
    configSet(['chat_environment_name' => $name === '' ? null : $name]);
    return $name;
}

function chatEnvironmentName(): string { return (string) (config()['chat_environment_name'] ?? ''); }

/* ── הפרומפט ─────────────────────────────────────────────────────── */

/** "מיני › חבילת היצירה › סטודיו העיצוב" — מהשורש לפרויקט. */
function projectPathName(int $projectId): string {
    return implode(' › ', array_map(fn ($p) => $p[1], array_reverse(projectChain($projectId))));
}

/** הריפו של הפרויקט — שלו, או של האב הקרוב שהגדיר: {url, branch}. ריק — לא הוגדר. */
function projectRepo(int $projectId): array {
    $st = db()->prepare('SELECT repo_owner, repo_name, default_branch FROM projects WHERE id = ?');
    foreach (projectChain($projectId) as [$pid]) {
        $st->execute([$pid]);
        $r = $st->fetch();
        if ($r && $r['repo_owner'] !== '' && $r['repo_name'] !== '') {
            return ['url' => "https://github.com/{$r['repo_owner']}/{$r['repo_name']}", 'branch' => (string) ($r['default_branch'] ?: 'main')];
        }
    }
    return ['url' => '', 'branch' => ''];
}

/** השורש — האסימון היחיד שצריך בסביבה (§5.9.1). */
function projectRoot(int $projectId): array {
    $chain = projectChain($projectId);
    $last = end($chain) ?: [$projectId, ''];
    return ['id' => (int) $last[0], 'name' => (string) $last[1]];
}

function chatName(string $pattern, array $role, string $projectName): string {
    return noQuotes(trim(strtr($pattern, ['{role}' => $role['label'], '{key}' => $role['key'], '{project}' => $projectName])));
}

/**
 * הפרומפט הגמור — אין בו אף ערך למילוי. ‏$replaces/$handoff — לצ'אט המשך בלבד (§5.9.4).
 * ‏$name — שם מפורש (צ'אט המשך שומר את שם הקודם כשהתפקיד אינו בתבנית).
 */
function chatPrompt(int $projectId, array $role, ?int $replaces = null, string $handoff = '', ?string $name = null): string {
    $t = templateResolved($projectId);
    $st = db()->prepare('SELECT name FROM projects WHERE id = ?');
    $st->execute([$projectId]);
    $pname = (string) $st->fetchColumn();
    $name ??= chatName($t['name_pattern'], $role, $pname);
    $cmd = "claude-tasks/bridge/link.sh register --project $projectId --name \"$name\" --role \"{$role['key']}\""
         . ($replaces ? " --replaces $replaces" : '');
    $files = array_values(array_filter(array_map('trim', explode("\n", $t['context_files']))));
    $files = array_values(array_diff($files, ['CLAUDE.md']));
    $lines = [
        "אתה \"$name\" — צ'אט {$role['label']} בפרויקט \"" . projectPathName($projectId) . "\" (#$projectId) בלוח \"מטלות לקלוד\".",
        "1. קבע לסשן את השם \"$name\" (set_session_title). הפעל את הסקיל claude-tasks-live והירשם:",
        "   $cmd",
        '   אם הרישום נכשל — עצור ודווח לבעל המערכת את ההודעה כפי שהיא (היא אומרת מה לתקן).',
        '2. קרא: ' . implode(', ', array_merge(['CLAUDE.md'], $files)),
    ];
    $n = 3;
    $reports = trim((string) ($role['reports_to'] ?? ''));
    if (trim($t['hierarchy']) !== '' || $reports !== '') {
        $lines[] = $n++ . '. ' . trim(($t['hierarchy'] !== '' ? 'ההיררכיה: ' . trim($t['hierarchy']) . '. ' : '')
                 . ($reports !== '' ? "אתה מדווח ל: $reports." : ''));
    }
    if ($t['notify_session'] !== '') $lines[] = $n . ". בסיום הרישום — שלח הודעת הצטרפות (create_trigger, persistent_session_id) ל-{$t['notify_session']}: מי אתה, הפרויקט, ומספר הערוץ.";
    if (trim((string) ($role['brief'] ?? '')) !== '') { $lines[] = ''; $lines[] = trim($role['brief']); }
    if (trim($t['extra']) !== '') { $lines[] = ''; $lines[] = trim($t['extra']); }
    if ($handoff !== '') { $lines[] = ''; $lines[] = $handoff; }
    return implode("\n", $lines);
}

/**
 * ⚡ גרסת Routine (§5.12.5): משימה מתוזמנת שפותחת סשן חדש בכל הפעלה. הסשן נרשם בתחילת ההרצה
 * (--role routine), ולכן בקשות ההרשאה שלו מגיעות ללוח כמו של כל צ'אט. בסוף — מתנתק.
 * ‏{מה לעשות} — בעל הלוח ממלא לפני שהוא שומר את המשימה.
 */
function routinePrompt(int $projectId, array $role): string {
    $t = templateResolved($projectId);
    $st = db()->prepare('SELECT name FROM projects WHERE id = ?');
    $st->execute([$projectId]);
    $name = chatName($t['name_pattern'], ['label' => 'Routine'] + $role, (string) $st->fetchColumn());
    $files = array_values(array_diff(array_filter(array_map('trim', explode("\n", $t['context_files']))), ['CLAUDE.md']));
    return implode("\n", [
        "אתה \"$name\" — הרצה של משימה מתוזמנת (Routine) בפרויקט \"" . projectPathName($projectId) . "\" (#$projectId) בלוח \"מטלות לקלוד\".",
        // §5.13.3: בלי הריפו אין hooks — ובלי hooks אין שער. בודקים לפני כל דבר, ולא עובדים בלעדיו
        '0. לפני הכול — שער הלוח קיים? הרץ: grep -qF \'bash \"$f\" gate\' .claude/settings.json && echo GATE-OK',
        '   לא הודפס GATE-OK — אל תעשה שום דבר אחר. דווח ללוח והסתיים:',
        '   curl -s -X POST -H "X-Worker-Token: $CLAUDE_TASKS_TOKEN" -H \'Content-Type: application/json\' -b humans_21909=1 '
            . '--data \'{"text":"Routine נפתח בלי הריפו — לא בוצע כלום"}\' "' . boardUrl() . '?action=routine-alert"',
        '1. הפעל את הסקיל claude-tasks-live והירשם — לפני כל דבר אחר, כדי שבקשות הרשאה יגיעו ללוח:',
        "   claude-tasks/bridge/link.sh register --project $projectId --name \"$name\" --role \"routine\"",
        '   אין claude-tasks/bridge/link.sh בתיקייה — המשימה נוצרה בלי הריפו mini_projects: עצור ודווח.',
        '   הרישום נכשל — עצור ודווח את ההודעה כפי שהיא.',
        '2. קרא: ' . implode(', ', array_merge(['CLAUDE.md'], $files)),
        '3. המשימה: {מה לעשות בכל הרצה} — רק בתיקייה {התיקייה}, ורק המשימה הזו. שום דבר מעבר לה.',
        '   משנה קוד — ענף משלך ו-PR בלבד. לעולם לא מיזוג, לא push ל-main, לא הפעלת workflows.',
        '   שער הלוח בודק כל פעולה רגישה: "🔐 … חסם" או "לא אושר בזמן" — אל תנסה דרך עוקפת; רשום אותה ב"נחסם" והמשך.',
        '4. בסוף — דוח בערוץ, כהודעת system: claude-tasks/bridge/link.sh post system "<מה נעשה, עם קישור ל-PR אם יש> · נחסם: <כל פעולה שלא רצה — שמה והסיבה, או: כלום>"',
        '   ואז: claude-tasks/bridge/link.sh archive',
    ]);
}

/** הגיליון "🆕 צ'אט חדש": התפקידים והפרומפט המוכן לכל אחד, הסביבה, ומה עובר בירושה. */
function chatSheet(array $user, int $projectId): array {
    $project = requireProject($projectId, $user, 'read');
    $t = templateResolved($projectId);
    $roles = array_map(fn ($r) => $r + ['name' => chatName($t['name_pattern'], $r, (string) $project['name']),
                                        'prompt' => chatPrompt($projectId, $r),
                                        'routine_prompt' => routinePrompt($projectId, $r)], $t['roles']);
    return [
        'project'     => ['id' => $projectId, 'name' => $project['name'], 'path' => projectPathName($projectId)],
        'repo'        => projectRepo($projectId),   // §5.13.3: הוראות יצירה מלאות ל-Routine
        'root'        => projectRoot($projectId),
        'environment' => chatEnvironmentName(),
        'roles'       => $roles,
        'template'    => $t,
        'can_edit'    => authorRole($user) === 'owner',
    ];
}

/* ── "הצ'אט מתארך" ────────────────────────────────────────────────── */

function healthThresholds(): array {
    $c = config();
    return [
        'warn_bytes'        => (int) ($c['chat_warn_bytes'] ?? 1_500_000),
        'warn_compactions'  => (int) ($c['chat_warn_compactions'] ?? 1),
        'alert_bytes'       => (int) ($c['chat_alert_bytes'] ?? 3_000_000),
        'alert_compactions' => (int) ($c['chat_alert_compactions'] ?? 2),
    ];
}

/** ‏'' — לא דיווח (סקיל ישן) או תקין; warn — 🟡 מתארך; alert — 🔴 ארוך. */
function healthLevel(array $c): string {
    if ((int) ($c['health_at'] ?? 0) === 0) return '';
    $t = healthThresholds();
    $b = (int) $c['health_bytes']; $k = (int) $c['health_compactions'];
    if ($b >= $t['alert_bytes'] || $k >= $t['alert_compactions']) return 'alert';
    if ($b >= $t['warn_bytes'] || $k >= $t['warn_compactions']) return 'warn';
    return '';
}

function channelHealth(array $channel, int $bytes, int $turns, int $compactions, string $version = ''): string {
    channelSetVersion($channel, $version);   // §5.10.1
    db()->prepare('UPDATE channels SET health_bytes = ?, health_turns = ?, health_compactions = ?, health_at = ? WHERE id = ?')
        ->execute([max(0, $bytes), max(0, $turns), max(0, $compactions), nowTs(), $channel['id']]);
    touchChannel((int) $channel['id']);
    return healthLevel(getChannel((int) $channel['id']));
}

/* ── צ'אט המשך (§5.9.4) ──────────────────────────────────────────── */

const HANDOFF_ASK = "בקשת צ'אט המשך: הצ'אט הזה מתארך, ובעל המערכת פותח צ'אט חדש שימשיך אותו.\n"
    . "כתוב תקציר העברה במבנה הקבוע (מצב וערכים עיקריים · החלטות ולמה · מה פתוח · קבצים שנגעת בהם · "
    . "sessions וענף · מה הצ'אט הבא צריך לעשות קודם), ושלח אותו: claude-tasks/bridge/link.sh handoff <קובץ>.\n"
    . 'אחר כך המשך לעבוד רק עד שתקבל הודעה שהוחלפת.';

function handoffRequest(array $user, int $channelId): array {
    if (authorRole($user) !== 'owner') throw new AppError('צ\'אט המשך — בעל הלוח בלבד', 403);
    $channel = getChannel($channelId);
    if (!$channel) throw new AppError('הערוץ לא נמצא', 404);
    requireProject((int) $channel['project_id'], $user, 'read');
    if ($channel['status'] !== 'active') throw new AppError('הערוץ אינו פעיל', 409);
    // בקשה שעוד ממתינה — ממשיכים אותה; הצ'אט הישן לא מקבל בקשה שנייה (§15.13)
    $cur = handoffState($channel);
    if ($cur['state'] === 'waiting') return ['id' => $cur['request_id'], 'existing' => true] + $cur;
    $id = channelInsertIn($channelId, 'owner', (int) $user['id'], HANDOFF_ASK, 'handoff_request');
    db()->prepare("UPDATE messages SET status = 'waiting' WHERE id = ?")->execute([$id]);
    return ['id' => $id, 'existing' => false] + handoffState(getChannel($channelId));
}

/** המשלם של הערוץ — אליו נרשמים זמני ההעברה (ההערכה היא לכל משלם). */
function handoffPayer(array $channel): int {
    return projectPayer((int) $channel['project_id']) ?: 0;
}

/** כמה זמן לוקחת העברה בדרך כלל: חציון של 20 האחרונות, או null כשיש פחות מ-3. */
function handoffExpected(int $payer): array {
    $st = db()->prepare('SELECT seconds FROM handoff_stats WHERE user_id = ? ORDER BY id DESC LIMIT ' . HANDOFF_STATS_KEEP);
    $st->execute([$payer]);
    $s = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (count($s) < HANDOFF_STATS_MIN) return ['expected_seconds' => null, 'samples' => count($s)];
    sort($s);
    $n = count($s);
    $median = $n % 2 ? $s[intdiv($n, 2)] : intdiv($s[$n / 2 - 1] + $s[$n / 2], 2);
    return ['expected_seconds' => max(1, $median), 'samples' => $n];
}

/**
 * מצב הבקשה האחרונה לצ'אט המשך (§15.13), בלי לשנות דבר מלבד מעברים טבעיים: תקציר הגיע → ready (ונרשם הזמן);
 * שעה בלי תקציר → expired (שורה אפורה בערוץ). ‏state: none | waiting | ready | used | fallback | cancelled | expired.
 */
function handoffState(array $channel): array {
    $cid = (int) $channel['id'];
    $req = db()->prepare("SELECT id, created_at, status FROM messages WHERE channel_id = ? AND kind = 'handoff_request' ORDER BY id DESC LIMIT 1");
    $req->execute([$cid]);
    $r = $req->fetch();
    $listening = ($channel['status'] ?? '') === 'active' && (int) ($channel['listening_until'] ?? 0) > nowTs();
    if (!$r) return ['state' => 'none', 'listening' => $listening];
    // בקשה מלפני §15.13 (status ריק — לפני שהיה "waiting") נחשבת סגורה: אחרת כל ערוץ ישן היה מקבל שבב "התקציר מוכן"
    $state = in_array($r['status'], ['ready', 'used', 'fallback', 'cancelled', 'expired'], true) ? $r['status']
           : ($r['status'] === 'waiting' ? 'waiting' : 'used');
    $at = (int) $r['created_at'];
    if ($state === 'waiting') {
        $h = db()->prepare("SELECT created_at FROM messages WHERE channel_id = ? AND kind = 'handoff' AND id > ? ORDER BY id LIMIT 1");
        $h->execute([$cid, $r['id']]);
        $got = $h->fetchColumn();
        if ($got !== false) {
            $state = 'ready';
            $upd = db()->prepare("UPDATE messages SET status = 'ready' WHERE id = ? AND status IN ('', 'waiting')");
            $upd->execute([$r['id']]);
            if ($upd->rowCount() === 1 && ($payer = handoffPayer($channel))) {
                db()->prepare('INSERT INTO handoff_stats (user_id, seconds, at) VALUES (?,?,?)')->execute([$payer, max(1, (int) $got - $at), nowTs()]);
            }
        } elseif (nowTs() - $at >= HANDOFF_EXPIRE_SECONDS) {
            $state = 'expired';
            $upd = db()->prepare("UPDATE messages SET status = 'expired' WHERE id = ? AND status IN ('', 'waiting')");
            $upd->execute([$r['id']]);
            // שורה אפורה — רק לבקשה מהיממה האחרונה (בקשות ישנות מלפני השלב הזה נסגרות בשקט)
            if ($upd->rowCount() === 1 && nowTs() - $at < 86400) {
                channelInsertIn($cid, 'system', null, '🔁 בקשת התקציר לצ\'אט המשך נסגרה — שעה בלי תשובה.', 'status', null, (int) $r['id'], false);
            }
        }
    }
    return ['state' => $state, 'request_id' => (int) $r['id'], 'requested_at' => $at, 'waited' => nowTs() - $at,
            'listening' => $listening] + handoffExpected(handoffPayer($channel));
}

function handoffChannel(array $user, int $channelId): array {
    if (authorRole($user) !== 'owner') throw new AppError('צ\'אט המשך — בעל הלוח בלבד', 403);
    $channel = getChannel($channelId);
    if (!$channel) throw new AppError('הערוץ לא נמצא', 404);
    requireProject((int) $channel['project_id'], $user, 'read');
    return $channel;
}

/** ‏handoff-status — מה שהגיליון והשורה צריכים. לא שולח כלום לצ'אט. */
function handoffStatus(array $user, int $channelId): array {
    return handoffState(handoffChannel($user, $channelId));
}

/** ‏handoff-cancel — ביטול מפורש (✕ לעולם לא מבטל). */
function handoffCancel(array $user, int $channelId): array {
    $channel = handoffChannel($user, $channelId);
    $s = handoffState($channel);
    if ($s['state'] !== 'waiting') throw new AppError('אין בקשה שממתינה', 409);
    db()->prepare("UPDATE messages SET status = 'cancelled' WHERE id = ?")->execute([$s['request_id']]);
    logEvent($user, 'handoff.cancel', "channel:$channelId", true, '');
    return handoffState($channel);
}

/** התפקיד של ערוץ קיים בתבנית — או תפקיד שנבנה מהערוץ עצמו (נרשם לפני התבניות). */
function channelRoleFor(array $channel): array {
    foreach (templateResolved((int) $channel['project_id'])['roles'] as $r) {
        if ($r['key'] === $channel['role']) return $r;
    }
    $key = preg_match('/^[a-z0-9][a-z0-9-]{0,29}$/', (string) $channel['role']) ? $channel['role'] : 'chat';
    return ['key' => $key, 'label' => $channel['role'] ?: 'צ\'אט', 'icon' => '💬', 'reports_to' => '', 'brief' => '', 'own_name' => true];
}

/**
 * מצב צ'אט ההמשך: ‏ready + prompt כשיש תקציר (או ‏fallback אחרי 2 דקות בלי תקציר —
 * מ-30 ההודעות האחרונות). אחרת ‏ready=false ו-waited (שניות מאז הבקשה).
 */
function handoffPrompt(array $user, int $channelId, bool $fallback = false): array {
    if (authorRole($user) !== 'owner') throw new AppError('צ\'אט המשך — בעל הלוח בלבד', 403);
    $channel = getChannel($channelId);
    if (!$channel) throw new AppError('הערוץ לא נמצא', 404);
    $req = db()->prepare("SELECT id, created_at FROM messages WHERE channel_id = ? AND kind = 'handoff_request' ORDER BY id DESC LIMIT 1");
    $req->execute([$channelId]);
    $r = $req->fetch();
    if (!$r) return ['ready' => false, 'requested' => false, 'waited' => 0, 'can_fallback' => false];
    $waited = nowTs() - (int) $r['created_at'];
    $h = db()->prepare("SELECT body FROM messages WHERE channel_id = ? AND kind = 'handoff' AND id > ? ORDER BY id DESC LIMIT 1");
    $h->execute([$channelId, $r['id']]);
    $body = $h->fetchColumn();
    $source = 'handoff';
    // צ'אט שלא מאזין לא יקבל את הבקשה עד הבדיקה השעתית שלו — אין טעם לחכות 2 דקות; "בלי תקציר" מיד
    $listening = $channel['status'] === 'active' && (int) $channel['listening_until'] > nowTs();
    $canFallback = $waited >= HANDOFF_WAIT_SECONDS || !$listening;
    if ($body === false) {
        if (!$fallback) return ['ready' => false, 'requested' => true, 'waited' => $waited, 'can_fallback' => $canFallback,
                                'listening' => $listening];
        // §15.13: "בלי תקציר" תמיד זמין — בעל הלוח לא מחכה בכוח (ב-120 שנ' הכפתור רק נעשה הראשי)
        db()->prepare("UPDATE messages SET status = 'fallback' WHERE id = ? AND status IN ('', 'waiting', 'ready')")->execute([$r['id']]);
        // שלב 16: סיכום חכם קיים — הוא הבסיס, ואחריו רק מה שנכתב מאז (במקום 30 הודעות "עיוורות")
        $sum = latestSummary($channelId);
        $rows = messageRows("m.channel_id = ? AND m.kind NOT IN ('handoff_request', 'status', 'lane_card')" . ($sum ? ' AND m.id > ' . (int) $sum['id'] : ''), [$channelId], 1000);
        $rows = array_slice($rows, -HANDOFF_FALLBACK_MESSAGES);
        $who = ['owner' => 'בעל המערכת', 'chat' => 'הצ\'אט', 'system' => 'מערכת', 'platform' => 'Platform'];
        $body = ($sum ? trim((string) $sum['body']) . "\n\n" : '')
              . "(הצ'אט הקודם לא החזיר תקציר — אלה " . count($rows) . ($sum ? ' ההודעות שאחרי הסיכום' : ' ההודעות האחרונות בערוץ') . ", מהישנה לחדשה)\n\n"
              . implode("\n\n", array_map(fn ($m) => '[' . ($who[$m['author_role']] ?? $m['author_role']) . '] '
                  . mb_substr((string) $m['body'], 0, 800), $rows));
        $source = 'messages';
    }
    if ($source === 'handoff') {
        handoffState($channel);   // ready + זמן ההעברה, אם עוד לא נרשמו
        db()->prepare("UPDATE messages SET status = 'used' WHERE id = ? AND status IN ('', 'waiting', 'ready')")->execute([$r['id']]);
    }
    $role = channelRoleFor($channel);
    $block = "## העברה מהצ'אט הקודם — \"{$channel['name']}\" (ערוץ #{$channel['id']})\n"
           . "אתה ממשיך את עבודתו. קרא את התקציר, ובצע קודם את \"מה הצ'אט הבא צריך לעשות קודם\".\n\n" . trim((string) $body);
    $prompt = chatPrompt((int) $channel['project_id'], $role, (int) $channel['id'], $block,
                         !empty($role['own_name']) ? noQuotes((string) $channel['name']) : null);
    return ['ready' => true, 'source' => $source, 'prompt' => $prompt, 'environment' => chatEnvironmentName(),
            'name' => $channel['name'], 'waited' => $waited];
}

/**
 * הרישום של צ'אט ההמשך (channel-register עם replaces): הישן — replaced + replaced_by,
 * מקבל הודעת system שהוחלף, והמטלות שנשאלו בו עוברות לחדש.
 */
/** נבדק לפני שנוצר משהו — רישום שנדחה לא משאיר ערוץ חדש מאחוריו. */
function assertReplaceable(array $scope, int $oldId, int $projectId): array {
    $old = getChannel($oldId);
    if (!$old) throw new AppError("הערוץ שמוחלף (#$oldId) לא נמצא", 404);
    $root = projectRoot($projectId)['id'];
    if (!in_array((int) $old['project_id'], projectSubtree($root), true)
        || (($scope['project_id'] ?? null) !== null && !in_array((int) $old['project_id'], projectSubtree((int) $scope['project_id']), true))) {
        throw new AppError('הערוץ שמוחלף אינו בתחום של האסימון', 403, 'token_scope');
    }
    return $old;
}

function replaceChannel(array $scope, int $oldId, int $newId): void {
    $new = getChannel($newId);
    if (!$new || $oldId === $newId) throw new AppError('הערוץ שמוחלף לא נמצא', 404);
    assertReplaceable($scope, $oldId, (int) $new['project_id']);
    db()->prepare("UPDATE channels SET status = 'replaced', replaced_by = ? WHERE id = ?")->execute([$newId, $oldId]);
    db()->prepare('UPDATE tasks SET asked_channel_id = ? WHERE asked_channel_id = ?')->execute([$newId, $oldId]);
    channelInsertIn($oldId, 'system', null, "הוחלפת על ידי \"{$new['name']}\" (ערוץ #$newId). סיים כאן, אל תמשיך לעבוד ואל תפעיל מחדש את המאזין.");
    logEvent(null, 'channel.replaced', "channel:$oldId", true, "→ channel:$newId");
}

/** הודעת system בערוץ החדש — כדי שהצטרפות לא תיראה רק בהודעה לצ'אט אחר (§5.9.5). */
function channelJoined(int $channelId, ?array $replaced): void {
    $c = getChannel($channelId);
    db()->prepare("INSERT INTO messages (channel_id, direction, author_role, kind, body, answered_by, created_at)
                   VALUES (?, 'out', 'system', 'text', ?, '', ?)")
        ->execute([$channelId, "\"{$c['name']}\" הצטרף" . ($replaced ? " — ממשיך את \"{$replaced['name']}\" (ערוץ #{$replaced['id']})" : ''), nowTs()]);
}
