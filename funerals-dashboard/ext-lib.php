<?php
declare(strict_types=1);

/**
 * פרוסה 05 — API חיצוני לצ'אט (GPT): טבלת הפעולות (המקור היחיד ל-ext.php, ל-openapi.json ולפרומפט),
 * אסימונים אישיים, הרשאות לפי תפקיד, והשכבה הדקה מול 01/02/03.
 *
 * נשען על 01: can() (ההקשר נבנה מהאסימון — extAuthenticate), existing()/store() (תיק + אירוע ב-events), logRequest().
 * על 02 — דרך finalAllowed() (finalAllowed() כשתמוזג); על 03 — רק כשהפונקציות שלה קיימות (extDropboxReady).
 *
 * הקובץ אינו נקודת קצה: גישה ישירה אליו מחזירה 404. נטען אחרי lib.php.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

const EXT_TOKEN_PREFIX = 'fdx_';
const EXT_MAX_ACTIVE_TOKENS = 10;
const EXT_RATE_PER_MINUTE = 60;
/** ניסיונות כושלים (401) לדקה מאותה כתובת IP — מעבר → 429, בלי לבדוק את האסימון. */
const EXT_FAILS_PER_MINUTE = 20;
const EXT_TTL_DAYS = [30, 90, 365];
const EXT_PUBLIC_BASE = 'https://mbe-plus.com/mini_projects/funerals-dashboard';

/** הבדיקות של התיק, בסדר המסלול. אותם מפתחות ושמות כמו ב-index.html — של הבעלים, לא משנים כאן. */
const EXT_CHECKS = [
    'license_document' => 'רישיון קבורה', 'license_number' => 'מספר רישיון קבורה',
    'identity_document' => 'מסמך זיהוי נפטר', 'identity_source' => 'מקור הזיהוי',
    'payment_evidence' => 'אסמכתת תשלום', 'hall_service' => 'שירות בית ההספדים',
    'grave_purchase' => 'רכישת קבר נוסף', 'partner_identity' => 'זיהוי בן/בת זוג',
    'national_insurance' => 'דיווח לביטוח לאומי',
];
const EXT_STATUSES = [
    'unknown' => 'טרם נבדק', 'document_present' => 'מסמך קיים — לאימות', 'verified' => 'מאומת',
    'unreadable' => 'לא קריא', 'missing' => 'חסר', 'reported' => 'עודכן ידנית', 'not_applicable' => 'לא רלוונטי',
];
/** שדות התיק שאפשר לשנות דרך case_update, וההרשאה שכל אחד דורש. */
const EXT_FIELDS = [
    'name' => 'details', 'date' => 'details', 'time' => 'details', 'origin' => 'details', 'team' => 'details',
    'notes' => 'details', 'folder_url' => 'details', 'payments' => 'payments', 'grave' => 'update',
];
/** ההרשאות של 05 → ההרשאות של 01 (lib/access.php PERMS). דרך אסימון אין "מפתח" — רק התפקיד בארגון. */
const EXT_PERM_MAP = [
    'view' => 'case.view', 'update' => 'case.track', 'details' => 'case.edit', 'payments' => 'case.edit',
    'create' => 'case.edit', 'manage_tokens' => 'members.manage',
];

/**
 * טבלת הפעולות — המקור היחיד. ext.php מנתב לפיה, tools/build-ext.php מייצר ממנה את openapi.json ואת gpt-prompt.md.
 * perm = ההרשאה הנדרשת; query = פרמטרים; body = סכמת JSON לגוף (POST).
 */
function extActions(): array {
    $checkProps = [];
    foreach (EXT_CHECKS as $k => $label) {
        $checkProps[$k] = ['type' => 'object', 'description' => $label, 'properties' => [
            'status' => ['type' => 'string', 'enum' => array_keys(EXT_STATUSES)],
            'value' => ['type' => 'string', 'maxLength' => 500], 'note' => ['type' => 'string', 'maxLength' => 800]]];
    }
    $fieldProps = [
        'name' => ['type' => 'string', 'description' => 'שם הנפטר / Deceased name'],
        'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'time' => ['type' => 'string', 'description' => 'HH:MM'],
        'origin' => ['type' => 'string'], 'team' => ['type' => 'string'], 'grave' => ['type' => 'string', 'description' => 'מיקום קבר / Grave location'],
        'notes' => ['type' => 'string', 'description' => 'מחליף את כל ההערות. להוספת הערה — note / Replaces all notes; use note to append'],
        'folder_url' => ['type' => 'string', 'description' => 'Dropbox https link'],
        'payments' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'amount' => ['type' => 'number'], 'purpose' => ['type' => 'string'], 'reference' => ['type' => 'string']]]],
    ];
    $id = ['name' => 'id', 'required' => true, 'description' => 'מזהה התיק (מ-find או cases) / Case id'];
    return [
        'whoami' => ['method' => 'GET', 'perm' => 'view', 'query' => [],
            'he' => 'מי אני: המשתמש, הארגון, התפקיד והפעולות המותרות', 'en' => 'Who am I: user, organization, role and allowed actions'],
        'schema' => ['method' => 'GET', 'perm' => 'view', 'query' => [],
            'he' => 'רשימת הבדיקות (מפתח ← שם), הסטטוסים והשדות — לפני כתיבה', 'en' => 'List of checks (key → Hebrew name), statuses and fields — read before writing'],
        'find' => ['method' => 'GET', 'perm' => 'view',
            'query' => [['name' => 'q', 'required' => true, 'description' => 'שם או חלק משם (2 תווים לפחות) / Name or part of it']],
            'he' => 'חיפוש תיק לפי שם — תמיד לפני עדכון', 'en' => 'Find a case by name — always before updating'],
        'cases' => ['method' => 'GET', 'perm' => 'view', 'query' => [
                ['name' => 'month', 'required' => false, 'description' => 'YYYY-MM'],
                ['name' => 'q', 'required' => false, 'description' => 'סינון לפי שם / Filter by name'],
                ['name' => 'status', 'required' => false, 'description' => 'missing | verify | unchecked | done', 'enum' => ['missing', 'verify', 'unchecked', 'done']]],
            'he' => 'רשימת תיקים מקוצרת: מזהה, שם, תאריך, הבא בתור ומונים', 'en' => 'Short case list: id, name, date, next step and counters'],
        'case' => ['method' => 'GET', 'perm' => 'view', 'query' => [$id],
            'he' => 'התיק המלא: שדות, בדיקות, קבצים ו-10 אירועים אחרונים', 'en' => 'Full case: fields, checks, files and last 10 events'],
        'case_update' => ['method' => 'POST', 'perm' => 'update', 'query' => [],
            'body' => ['type' => 'object', 'required' => ['id'], 'properties' => [
                'id' => ['type' => 'string'],
                'fields' => ['type' => 'object', 'properties' => $fieldProps, 'description' => 'שדות לשינוי (משרד ומעלה, חוץ מ-grave) / Fields to change'],
                'checks' => ['type' => 'object', 'properties' => $checkProps, 'description' => 'בדיקות לשינוי לפי מפתח / Checks to change by key'],
                'note' => ['type' => 'string', 'maxLength' => 800, 'description' => 'הערה שתתווסף לתיק / Note appended to the case']]],
            'he' => 'עדכון חלקי של תיק. מחזיר מה נשמר בפועל — "מאומת" נשמר "לאימות"', 'en' => 'Partial case update. Returns what was actually saved — "verified" is saved as "to verify"'],
        'case_create' => ['method' => 'POST', 'perm' => 'create', 'query' => [],
            'body' => ['type' => 'object', 'required' => ['name'], 'properties' => [
                'name' => $fieldProps['name'], 'date' => $fieldProps['date'], 'time' => $fieldProps['time'],
                'month' => ['type' => 'string', 'description' => 'YYYY-MM (ברירת מחדל: מהתאריך / default: from date)'],
                'origin' => $fieldProps['origin'], 'team' => $fieldProps['team'], 'grave' => $fieldProps['grave'],
                'note' => ['type' => 'string', 'maxLength' => 800]]],
            'he' => 'פתיחת תיק חדש (משרד ומעלה)', 'en' => 'Create a new case (office and above)'],
        'files' => ['method' => 'GET', 'perm' => 'view', 'query' => [$id],
            'he' => 'קבצי התיק מ-Dropbox', 'en' => 'Case files from Dropbox'],
        'file_text' => ['method' => 'GET', 'perm' => 'view',
            'query' => [['name' => 'file', 'required' => true, 'description' => 'מזהה הקובץ — מספר, מ-files / File id (integer) from files']],
            'he' => 'הטקסט של קובץ (PDF עם שכבת טקסט), עד 200KB', 'en' => 'Text of a file (PDF with a text layer), up to 200KB'],
        'prompt' => ['method' => 'GET', 'perm' => 'view', 'query' => [],
            'he' => 'ההנחיות לצ\'אט (טקסט)', 'en' => 'Instructions for the chat (text)'],
    ];
}

// ───────────────────────── מול 01 / 02 / 03 ─────────────────────────

/** טבלת האסימונים — לפי שם ואידמפוטנטית (CREATE IF NOT EXISTS), בלי מספר גרסה, כדי לא להתנגש במיגרציות של 03. */
function extEnsureSchema(PDO $pdo): void {
    static $ok = false;
    if ($ok) return;
    $pdo->exec('CREATE TABLE IF NOT EXISTS tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, org_id INTEGER NOT NULL,
        name TEXT NOT NULL, hash TEXT NOT NULL UNIQUE, hint TEXT NOT NULL, created_at TEXT NOT NULL, expires_at TEXT NOT NULL,
        last_used_at TEXT, revoked_at TEXT, revoked_by INTEGER, rl_min INTEGER NOT NULL DEFAULT 0, rl_n INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tokens_user ON tokens(user_id)');
    // ניסיונות כושלים לפי IP (גיבוב, לא הכתובת עצמה) — הגבלה זולה מול ניחוש אסימונים
    $pdo->exec('CREATE TABLE IF NOT EXISTS ext_fail (ip TEXT PRIMARY KEY, minute INTEGER NOT NULL, n INTEGER NOT NULL)');
    $ok = true;
}
/** החבר (משתמש × ארגון × תפקיד), ברגע הבקשה: users.active=1 וגם members.active=1 (השבתה היא לארגון). */
function extMember(PDO $pdo, int $userId, int $orgId): ?array {
    $st = $pdo->prepare('SELECT u.id AS user_id, u.name, u.email, m.org_id, m.role, o.name AS org_name
        FROM users u JOIN members m ON m.user_id=u.id JOIN orgs o ON o.id=m.org_id
        WHERE u.id=? AND m.org_id=? AND u.active=1 AND m.active=1');
    $st->execute([$userId, $orgId]);
    $m = $st->fetch();
    return $m && isset(ROLES[$m['role']]) ? $m : null;
}
/** ההקשר של 01 (ctx) מהאסימון — כדי ש-can() יעבוד ב-ext.php בלי השער. אף פעם לא מפתח. */
function extSetContext(array $m): void {
    $org = ['id' => (int)$m['org_id'], 'name' => $m['org_name'], 'role' => $m['role']];
    $GLOBALS['fd_ctx'] = ['user' => ['id' => (int)$m['user_id'], 'name' => (string)$m['name'], 'email' => (string)($m['email'] ?? '')],
        'actor' => ['id' => (int)$m['user_id'], 'actor' => extActor($m)], 'dev' => false, 'active' => true,
        'orgs' => [$org], 'org' => $org, 'role' => $m['role']];
}
/** קבצי תיק וטקסט של קובץ — רק כשהפונקציות של 03 (dropbox/actions.php) קיימות. */
function extDropboxReady(string $fn): bool {
    if (!function_exists($fn) && is_file(__DIR__ . '/dropbox/actions.php')) require_once __DIR__ . '/dropbox/actions.php';
    return function_exists($fn);
}
/** שגיאה של 03 (DbxError, לפי tag) → [סטטוס, הודעה בעברית לצ'אט]. */
function extDropboxError(Throwable $e): array {
    $tag = (string)($e->tag ?? '');
    return match ($tag) {
        'not_text' => [422, 'אין לקובץ הזה טקסט שאפשר לקרוא (PDF, תמונה או Office). לבקש מהמשתמש לבדוק אותו בעצמו.'],
        'not_connected', 'reauth', 'no_root' => [501, 'Dropbox לא מחובר לארגון (או שהחיבור פג). מנהל הארגון מחבר מחדש בהגדרות.'],
        'too_large' => [413, 'הקובץ גדול מדי לקריאה.'],
        default => [502, 'Dropbox לא ענה כרגע. לנסות שוב מאוחר יותר.'],
    };
}
/** קבצי התיק מ-03: [רשימה, null] או [null, הסבר, סטטוס]. ההרשאה (case.view + תיק של הארגון) נבדקה לפני. */
function extCaseFiles(PDO $pdo, array $case, int $orgId): array {
    if (!extDropboxReady('dropboxCaseFiles')) return [null, 'Dropbox עוד לא חובר'];
    try {
        return [dropboxCaseFiles($pdo, $case + ['org_id' => $orgId]), null];
    } catch (Throwable $e) {
        [$status, $msg] = extDropboxError($e);
        return [null, $msg, $status];
    }
}

// ───────────────────────── אסימונים ─────────────────────────

function extActor(array $m): string {
    $who = trim((string)($m['name'] ?? '')) !== '' ? $m['name'] : ((string)($m['email'] ?? '') !== '' ? $m['email'] : 'משתמש ' . $m['user_id']);
    return $who . " (צ'אט)";
}
/** הרשאה של 05 — דרך can() של 01, על ההקשר הנוכחי. */
function extCan(string $perm): bool { return can(EXT_PERM_MAP[$perm]); }

/** האסימון מהכותרת Authorization: Bearer בלבד. */
function extBearer(): string {
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if (!preg_match('/^Bearer (fdx_[a-f0-9]{48})$/D', $h, $mm)) return '';
    return $mm[1];
}
/**
 * מזהה את בעל האסימון: קיים, לא בוטל, לא פג, והמשתמש עדיין חבר פעיל בארגון. אחרת 401.
 * מעדכן "שימוש אחרון" וסופר בקשות לדקה (61 → 429).
 */
function extAuthenticate(PDO $pdo): array {
    $minute = intdiv(time(), 60);
    $ip = hash('sha256', 'fdx-ip|' . (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $st = $pdo->prepare('SELECT n FROM ext_fail WHERE ip=? AND minute=?');
    $st->execute([$ip, $minute]);
    if ((int)$st->fetchColumn() >= EXT_FAILS_PER_MINUTE) {
        $GLOBALS['ext_nolog'] = true;   // לא ממלאים את יומן הבקשות בהצפה
        header('Retry-After: ' . (60 - time() % 60));
        reply(429, ['error' => 'יותר מדי ניסיונות עם אסימון שגוי. לחכות דקה.']);
    }
    $deny = static function () use ($pdo, $ip, $minute): never {
        $pdo->prepare('INSERT INTO ext_fail(ip, minute, n) VALUES(?,?,1) ON CONFLICT(ip) DO UPDATE SET n = CASE WHEN minute=excluded.minute THEN n+1 ELSE 1 END, minute=excluded.minute')
            ->execute([$ip, $minute]);
        if (random_int(1, 50) === 1) $pdo->prepare('DELETE FROM ext_fail WHERE minute < ?')->execute([$minute - 1]);
        $GLOBALS['ext_nolog'] = true;   // 401 לא נרשם ב-req_log
        reply(401, ['error' => 'נדרש אסימון תקין (Authorization: Bearer fdx_…). אסימון שבוטל או שפג — יוצרים חדש במסך "חיבור ל-ChatGPT".']);
    };
    $raw = extBearer();
    if ($raw === '') $deny();
    $st = $pdo->prepare('SELECT * FROM tokens WHERE hash=?');
    $st->execute([hash('sha256', $raw)]);
    $t = $st->fetch();
    if (!$t || $t['revoked_at'] !== null || strcmp((string)$t['expires_at'], gmdate('c')) <= 0) $deny();
    $m = extMember($pdo, (int)$t['user_id'], (int)$t['org_id']);
    if ($m === null) $deny();
    extSetContext($m);
    $pdo->prepare('UPDATE tokens SET rl_n = CASE WHEN rl_min=? THEN rl_n+1 ELSE 1 END, rl_min=?, last_used_at=? WHERE id=?')
        ->execute([$minute, $minute, gmdate('c'), (int)$t['id']]);
    $n = (int)$pdo->query('SELECT rl_n FROM tokens WHERE id=' . (int)$t['id'])->fetchColumn();
    if ($n > EXT_RATE_PER_MINUTE) {
        header('Retry-After: ' . (60 - time() % 60));
        reply(429, ['error' => 'יותר מ-' . EXT_RATE_PER_MINUTE . ' בקשות בדקה. לחכות דקה ולנסות שוב.']);
    }
    $m['token_id'] = (int)$t['id'];
    return $m;
}
/** אסימון חדש: מוחזר פעם אחת; נשמר רק ה-SHA-256 ו-4 התווים האחרונים (לזיהוי ברשימה). */
function extCreateToken(PDO $pdo, array $m, string $name, int $days): array {
    if (!in_array($days, EXT_TTL_DAYS, true)) reply(400, ['error' => 'תוקף לא תקין (30 / 90 / 365 יום)']);
    $name = cleanText($name, 60);
    if ($name === '') reply(400, ['error' => 'נדרש שם לאסימון, למשל "GPT של משה"']);
    $st = $pdo->prepare('SELECT COUNT(*) FROM tokens WHERE user_id=? AND revoked_at IS NULL AND expires_at > ?');
    $st->execute([(int)$m['user_id'], gmdate('c')]);
    if ((int)$st->fetchColumn() >= EXT_MAX_ACTIVE_TOKENS) reply(400, ['error' => 'יש כבר ' . EXT_MAX_ACTIVE_TOKENS . ' אסימונים פעילים. לבטל אחד לפני יצירת חדש.']);
    $token = EXT_TOKEN_PREFIX . bin2hex(random_bytes(24));
    $expires = gmdate('c', time() + $days * 86400);
    $pdo->prepare('INSERT INTO tokens(user_id, org_id, name, hash, hint, created_at, expires_at) VALUES(?,?,?,?,?,?,?)')
        ->execute([(int)$m['user_id'], (int)$m['org_id'], $name, hash('sha256', $token), substr($token, -4), gmdate('c'), $expires]);
    $id = (int)$pdo->lastInsertId();
    recordEvent((int)$m['org_id'], null, ctx()['actor'], 'ui', 'token_created', ['token' => [null, $name . ' #' . $id], 'expires_at' => [null, $expires]]);
    return ['id' => $id, 'token' => $token, 'name' => $name, 'expires_at' => $expires];
}
/** הרשימה: שלי, ולמנהל — של כל הארגון. בלי hash. */
function extListTokens(PDO $pdo, int $orgId, int $userId): array {
    $all = extCan('manage_tokens');
    $st = $pdo->prepare('SELECT t.id, t.name, t.hint, t.created_at, t.expires_at, t.last_used_at, t.revoked_at, t.user_id,
        COALESCE(NULLIF(u.name, \'\'), u.email) AS owner FROM tokens t LEFT JOIN users u ON u.id=t.user_id
        WHERE t.org_id=? ' . ($all ? '' : 'AND t.user_id=? ') . 'ORDER BY t.revoked_at IS NOT NULL, t.created_at DESC LIMIT 200');
    $st->execute($all ? [$orgId] : [$orgId, $userId]);
    $now = gmdate('c');
    return array_map(static fn($t) => $t + ['mine' => (int)$t['user_id'] === $userId,
        'state' => $t['revoked_at'] !== null ? 'revoked' : (strcmp($t['expires_at'], $now) <= 0 ? 'expired' : 'active')], $st->fetchAll());
}
/** ביטול: שלי, או כל אסימון בארגון למנהל. אסימון של ארגון אחר → 404. אין מחיקה — השורה נשארת עם revoked_at. */
function extRevokeToken(PDO $pdo, int $orgId, int $userId, int $id): void {
    $st = $pdo->prepare('SELECT id, user_id, revoked_at FROM tokens WHERE id=? AND org_id=?');
    $st->execute([$id, $orgId]);
    $t = $st->fetch();
    if (!$t || ((int)$t['user_id'] !== $userId && !extCan('manage_tokens'))) reply(404, ['error' => 'אסימון לא נמצא']);
    if ($t['revoked_at'] !== null) return;
    $pdo->prepare('UPDATE tokens SET revoked_at=?, revoked_by=? WHERE id=?')->execute([gmdate('c'), $userId, $id]);
    recordEvent($orgId, null, ctx()['actor'], 'ui', 'token_revoked', ['token' => ['#' . $id, null]]);
}

// ───────────────────────── תיקים ─────────────────────────

function extGroupOf(string $s): string {
    return match ($s) { 'missing', 'unreadable' => 'missing', 'document_present', 'reported' => 'verify',
        'verified', 'not_applicable' => 'done', default => 'unchecked' };
}
/**
 * פרוסה 11: הבדיקות הפעילות של הארגון (מפתח → שם) — מטבלת ההגדרות (checkNames של lib/checks.php), לא מהרשימה הקבועה.
 * EXT_CHECKS נשאר לקבצים שנבנים מראש (openapi.json, ההנחיות ל-GPT) — שם הרשימה של ברירת המחדל.
 */
function extChecks(): array {
    $n = function_exists('checkNames') ? checkNames(db(), scope()) : [];
    return $n ?: EXT_CHECKS;
}
/** מונים ו"הבא בתור": הראשונה בקבוצת "חסר", אחריה "לאימות", אחריה "טרם נבדק" (02 §2.2). */
function extSummary(array $c): array {
    $n = ['missing' => 0, 'verify' => 0, 'unchecked' => 0, 'done' => 0];
    $first = [];
    foreach (extChecks() as $k => $label) {
        $g = extGroupOf((string)($c['checks'][$k]['status'] ?? 'unknown'));
        $n[$g]++;
        $first[$g] ??= ['check' => $k, 'label' => $label];
    }
    $next = $first['missing'] ?? $first['verify'] ?? $first['unchecked'] ?? null;
    $status = $n['done'] === count(extChecks()) ? 'done' : ($n['missing'] ? 'missing' : ($n['verify'] ? 'verify' : 'unchecked'));
    return ['id' => $c['id'], 'name' => $c['name'], 'date' => $c['date'] ?? '', 'time' => $c['time'] ?? '', 'month' => $c['month'],
        'origin' => $c['origin'] ?? '', 'status' => $status, 'next' => $next, 'counts' => $n];
}
/** תיק של הארגון בלבד; של ארגון אחר — כאילו לא קיים (404, existing() של 01). */
function extCase(PDO $pdo, int $orgId, string $id): array {
    if (!preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $id)) reply(404, ['error' => 'תיק לא נמצא']);
    return existing($pdo, $id, $orgId) ?? reply(404, ['error' => 'תיק לא נמצא. לחפש קודם עם find.']);
}

/** ההנחיות ל-GPT — נוצרות מכאן ל-gpt-prompt.md, ל-ext.php?action=prompt ולמסך החיבור. */
function extPrompt(): string {
    $checks = implode("\n", array_map(static fn($k, $v) => "- `$k` — $v", array_keys(EXT_CHECKS), EXT_CHECKS));
    $statuses = implode("\n", array_map(static fn($k, $v) => "- `$k` — $v", array_keys(EXT_STATUSES), EXT_STATUSES));
    return <<<MD
# הנחיות לצ'אט — ניהול לוויות חברה קדישא

אתה עוזר למשרד של חברה קדישא לנהל תיקי לוויות. כל תיק הוא מסלול של בדיקות: מה חסר, מה לאימות ומה הושלם.
אתה עובד רק דרך הפעולות של ה-API ("ניהול לוויות"). אתה לא ממציא נתונים ולא מוחק דבר — אין פעולת מחיקה.

## איך עובדים

1. **תמיד `find` לפני עדכון.** מחפשים לפי שם הנפטר. יותר מתוצאה אחת — שואלים את המשתמש איזה תיק (לפי תאריך ומקום),
   ולא בוחרים לבד. אין תוצאה — אומרים זאת, ולא פותחים תיק חדש בלי בקשה מפורשת.
2. **לא מנחשים.** כותבים רק ערך שהמשתמש אמר במפורש או שמופיע במסמך שקראת (`file_text`). לא בטוח — שואלים.
3. **"לאימות" אינו "מאומת".** כשאתה מסמן בדיקה כ-`verified`, השרת שומר אותה כ-`document_present` ("מסמך קיים —
   לאימות"), כי רק אדם במשרד מאשר סופית. זה תקין — אמור למשתמש שהבדיקה ממתינה לאימות במשרד.
4. **מציגים מה נשמר בפועל.** אחרי `case_update` — מסכמים למשתמש מתוך התשובה (`saved`, `converted`), לא מתוך מה
   שביקשת.
5. **הערה חופשית** (למשל "תוסיף לכהן: התקשרו מהמשפחה") — `case_update` עם `note`. היא מתווספת להערות התיק, לא מחליפה.
6. לפני כתיבה ראשונה בשיחה — `schema`, כדי לדעת את מפתחות הבדיקות. `whoami` אומר מה מותר לך; פעולה שאסורה תחזיר
   403 — אמור זאת למשתמש, ואל תנסה דרך אחרת.
7. "תסרוק את התיקייה ותגיד מה חסר" — `case` (הבדיקות ו"הבא בתור"), `files`, ו-`file_text` לקבצים הרלוונטיים;
   מציעים עדכונים ושואלים לפני כתיבה.
8. כותבים בעברית, קצר וברור.

## הבדיקות

$checks

## הסטטוסים

$statuses

MD;
}

/** הסטטוסים שה-GPT של הפרויקט רשאי להחזיר בייבוא. אף פעם לא verified — רק אדם במשרד מאשר. */
const EXT_PROJECT_STATUSES = ['document_present', 'unreadable', 'missing', 'not_applicable'];

/**
 * פרוסה 08 (P2) — ההנחיות לפרויקט ChatGPT: בלי Actions. ה-GPT קורא את קובץ הייצוא (📤) שהועלה לפרויקט ומחזיר JSON
 * לייבוא (📥). נוצרות מכאן ל-gpt-project-prompt.md ולמסך החיבור (📋) — אותו מקור כמו extPrompt(), בלי עותק ידני.
 * הכללים כאן משקפים את importRecords() שב-lib.php: שדה ריק לא דורס, בדיקה נשלחת שלמה, notes ו-payments מחליפים.
 * 08 המשך: מבוסס גם על הנוסח של מנהל הצוותים (טבלה לפני ה-JSON, source = שם הקובץ, JSON רק לפי בקשה).
 */
function extProjectPrompt(): string {
    $checks = implode("\n", array_map(static fn($k, $v) => "- `$k` — $v", array_keys(EXT_CHECKS), EXT_CHECKS));
    $statuses = implode("\n", array_map(static fn($k) => "- `$k` — " . EXT_STATUSES[$k], EXT_PROJECT_STATUSES));
    $example = '{"schema_version":1,"month":"2026-11","records":['
        . '{"id":"fd_…","month":"2026-11","name":"כהן משה","checks":{"license_number":{"status":"document_present","value":"12345","source":"רישיון.jpg","note":"מצילום הרישיון"}}},'
        . '{"month":"2026-11","name":"לוי שרה","date":"2026-11-20"}]}';
    return <<<MD
# הנחיות לפרויקט "ניהול לוויות" — חברה קדישא

אתה העוזר של "ניהול לוויות חברה קדישא". התפקיד שלך: לקרוא מסמכים של תיקי לוויה (רישיון קבורה, תעודת זהות, אסמכתאות
תשלום, צילומי קבר ועוד) ולהכין JSON שהמשתמש מייבא לאפליקציה בכפתור 📥 ("ייבוא ממצאים").
אין לך גישה ישירה לאפליקציה. כל מה שאתה מכין עובר דרך המשתמש.

## מאיפה המידע

- **קובץ הייצוא** בקבצי הפרויקט (`funerals-YYYY-MM.json`, מכפתור 📤): `{"schema_version":1,"month":"YYYY-MM","records":[…]}`.
  כל רשומה היא תיק: `id`, `month`, `name`, `date`, `time`, `origin`, `team`, `grave`, `notes`, `folder_url`, `documents`,
  `checks`, `payments`. זה מקור האמת לתיקים הקיימים ולמזהים שלהם — תמונה של רגע הייצוא.
- **המסמכים** של התיק — המשתמש מעלה לשיחה: תמונות, PDF או צילומי מסך.
- אין קובץ ייצוא, הוא של חודש אחר, או שהוא ישן — לבקש לייצא ולהעלות לפני שמכינים JSON לתיק קיים.

## איך עובדים

1. **מזהים את התיק** לפי שם הנפטר ותאריך הלוויה בקובץ הייצוא. יותר מתיק אחד מתאים, או ספק — שואלים, ולא בוחרים לבד.
2. **כותבים בקצרה** מה מצאת בכל מסמך, ומה עדיין חסר בתיק.
3. **JSON רק כשהמשתמש מבקש** ("תכין JSON", "קובץ לייבוא").
4. **לא ממציאים.** מה שלא כתוב במסמך, או שהמשתמש לא אמר במפורש — לא נכנס. מספר או שם לא קריא — `unreadable`, וב-`note` מה ראית.
5. **"לאימות" אינו "מאומת".** מסמך שנמצא — `document_present`. **אף פעם לא `verified`**: רק אדם באפליקציה מאשר.

## ה-JSON

לפני ה-JSON — טבלה קצרה: תיק | מה עודכן | מאיזה קובץ. אחריה בלוק קוד אחד, JSON תקין בלבד, עד 60 רשומות:

```json
$example
```

- **תיק קיים** — `id` בדיוק כמו בקובץ הייצוא, ואיתו `month` ו-`name` כמו בקובץ. רק השדות שמשתנים: שדה שלא שלחת,
  או ששלחת ריק, לא משתנה.
- **תיק חדש** — רק כשהמשתמש ביקש במפורש: **בלי `id`**, עם `month`, `name` ו-`date` (YYYY-MM-DD) אם ידוע.
  אם באפליקציה יש תיק דומה, המשתמש יבחר שם אם לעדכן אותו או ליצור חדש. כל תיק — רשומה אחת בלבד בקובץ.
- **`checks`** — רק הבדיקות שמצאת להן מידע. בדיקה שאתה שולח מחליפה את כולה, ולכן שולחים את כל ארבעת השדות:
  `status`, `value` (מה שכתוב במסמך — מספר, שם), `source` (שם הקובץ) ו-`note` (מה ראית, איזה עמוד).
  מה שלא משתנה בה — להעתיק מקובץ הייצוא. בדיקה שהמשרד כבר סגר או עדכן ידנית לא תשתנה בייבוא.
- **`notes` ו-`payments` — מלאים או בכלל לא.** הייבוא **מחליף** אותם, לא מוסיף:
  - `notes` — ההערות הקיימות מקובץ הייצוא, והתוספת בסוף.
  - `payments` — כל התשלומים הקיימים מקובץ הייצוא, ואיתם החדש:
    `{"amount": 1500, "purpose": "…", "reference": "מספר אסמכתה", "source": "שם הקובץ"}`.
  - אין שינוי בהם — לא שולחים את השדה. תשלום חדש לבד, או הערה חדשה לבד, **מוחקים** את הקיים.
- תאריך `YYYY-MM-DD`, שעה `HH:MM`.

אחרי הבלוק: "להדביק ב-📥 במסך התיקים". אם אפשר — גם קובץ להורדה `funerals-import-YYYY-MM.json`.

## כללים

- עברית, קצר וברור. פרטים אישיים נשארים בשיחה.
- אין מחיקה דרך ה-JSON — מחיקה רק באפליקציה.

## הבדיקות (מפתחות `checks`)

$checks

## הסטטוסים שמותר להחזיר

$statuses

MD;
}

/** OpenAPI 3.1 מטבלת הפעולות — נתיב לכל פעולה (ext.php/<action>), Bearer. */
function extOpenApi(): array {
    $paths = [];
    foreach (extActions() as $name => $a) {
        $op = ['operationId' => $name, 'summary' => $a['en'], 'description' => $a['he'] . ' / ' . $a['en'],
            'responses' => [
                '200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Result']]]],
                '4XX' => ['description' => 'שגיאה / Error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]]]];
        $params = [];
        foreach ($a['query'] as $q) {
            $schema = ['type' => 'string'] + (isset($q['enum']) ? ['enum' => $q['enum']] : []);
            $params[] = ['name' => $q['name'], 'in' => 'query', 'required' => $q['required'], 'description' => $q['description'], 'schema' => $schema];
        }
        if ($params) $op['parameters'] = $params;
        if (isset($a['body'])) $op['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => $a['body']]]];
        $paths['/ext.php/' . $name] = [strtolower($a['method']) => $op];
    }
    return [
        'openapi' => '3.1.0',
        'info' => ['title' => 'ניהול לוויות חברה קדישא — API', 'version' => '1.0.0',
            'description' => "פעולות קריאה ועדכון לתיקי לוויות, לצ'אט חיצוני. אין מחיקה. / Read and update funeral cases for an external chat. No delete."],
        'servers' => [['url' => EXT_PUBLIC_BASE]],
        'paths' => $paths,
        'components' => [
            'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'אסימון אישי fdx_… / Personal token fdx_…']],
            'schemas' => [
                'Result' => ['type' => 'object', 'additionalProperties' => true],
                'Error' => ['type' => 'object', 'properties' => ['error' => ['type' => 'string']], 'required' => ['error']]]],
        'security' => [['bearer' => []]],
    ];
}
// ───────────────────────── הפעולות — לכל מקור (ext.php, וה-AI של 06) ─────────────────────────

/** שגיאה של פעולה: הסטטוס (400/403/404/501) והודעה בעברית. ext.php מחזיר אותה כ-JSON; 06 יכול להחזיר אותה למודל. */
final class ExtError extends RuntimeException {}
function extFail(int $status, string $msg): never { throw new ExtError($msg, $status); }

/**
 * מריץ פעולה מטבלת הפעולות בהקשר של 01 (ctx(): משתמש, ארגון פעיל, תפקיד) — ext.php בונה אותו מהאסימון
 * (extSetContext), ו-06 מקבל אותו מ-resolveAccess() של המשתמש המחובר. $in — הפרמטרים והגוף יחד.
 * $via — 'api' (צ'אט חיצוני) או 'ai' (AI מובנה): נכתב בכל בדיקה, ובשם ביומן ("(צ'אט)" / "(AI)").
 * ההרשאה — can() של 01 לפי הפעולה, ובכתיבה need(caseChangeNeeds()). כתיבה — formSaveCase() של 02.
 * $guard (06, ✓ על הצעה) — נקרא בתוך הטרנזקציה, רגע לפני השמירה; זורק ExtError כדי לבטל (התיק השתנה מאז ההצעה).
 * $dry (06, כרטיס הצעה) — כתיבה מלאה (ולידציה, הרשאה, formSaveCase) ואז ROLLBACK: אותה תשובה, ושום דבר לא נשמר.
 * מחזיר את התשובה; שגיאה — ExtError. (פונקציות הליבה — normalize(), existing(), need() — עונות בעצמן עם reply().)
 */
function extDo(PDO $pdo, string $action, array $in, string $via = 'api', bool $dry = false, ?callable $guard = null): array {
    if (!in_array($via, ['api', 'ai'], true)) throw new LogicException('extDo: via לא מוכר');
    $actions = extActions();
    if (!isset($actions[$action])) extFail(404, 'פעולה לא מוכרת. הפעולות: ' . implode(', ', array_keys($actions)));
    $c0 = ctx();
    $orgId = scope();
    $roleName = ctxRole()['name'] ?? ($c0['role'] !== null ? ROLES[$c0['role']] : ($c0['dev'] ? 'מפתח' : ''));   // 11: שם התפקיד הגמיש
    if (!extCan($actions[$action]['perm'])) extFail(403, 'אין לך הרשאה לפעולה הזו (התפקיד שלך: ' . $roleName . ')');
    $u = $c0['user'];
    $actor = ['id' => (int)$u['id'], 'actor' => ((string)$u['name'] !== '' ? $u['name'] : ((string)$u['email'] !== '' ? $u['email'] : 'משתמש ' . $u['id']))
        . ($via === 'ai' ? ' (AI)' : ' (' . ($c0['client'] ?? "צ'אט") . ')')];   // 09: חיבור OAuth — שם הלקוח ("(ChatGPT)")
    switch ($action) {
        case 'whoami':
            $allowed = array_keys(array_filter(extActions(), static fn($a) => extCan($a['perm'])));
            $fields = array_keys(array_filter(EXT_FIELDS, static fn($p) => extCan($p)));
            return ['user' => ['name' => $c0['user']['name'], 'email' => $c0['user']['email']], 'org' => ['id' => $orgId, 'name' => $c0['org']['name']],
                'role' => $c0['role'], 'role_name' => $roleName, 'via' => $via, 'actions' => $allowed, 'editable_fields' => $fields,
                'can_update_checks' => extCan('update'), 'can_delete' => false];

        case 'schema':
            return ['checks' => array_map(static fn($k, $v) => ['key' => $k, 'name' => $v], array_keys(extChecks()), extChecks()),
                'statuses' => array_map(static fn($k, $v) => ['key' => $k, 'name' => $v, 'human_only' => !finalAllowed($via) && in_array($k, FORM_FINAL_STATUSES, true)],
                    array_keys(EXT_STATUSES), EXT_STATUSES),
                'fields' => array_keys(EXT_FIELDS),
                'rule' => 'סטטוס "מאומת" / "עודכן ידנית" / "לא רלוונטי" שנכתב דרך מקור אוטומטי (צ\'אט, AI) נשמר כ"מסמך קיים — לאימות". רק אדם במשרד מאשר סופית.',
                'document_kinds' => array_values(extChecks())];

        case 'prompt':
            return ['prompt' => extPrompt()];

        case 'find':
            $q = cleanText($in['q'] ?? '', 80);
            if (mb_strlen($q) < 2) extFail(400, 'נדרשים 2 תווים לפחות לחיפוש');
            $st = $pdo->prepare("SELECT body FROM funerals WHERE org_id=? AND name LIKE ? ESCAPE '\\' ORDER BY month DESC, name LIMIT 11");
            $st->execute([$orgId, '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%']);
            $rows = array_map(static fn($r) => extSummary(json_decode($r['body'], true)), $st->fetchAll());
            $more = count($rows) > 10;
            return ['query' => $q, 'count' => min(count($rows), 10), 'more' => $more, 'results' => array_slice($rows, 0, 10),
                'hint' => count($rows) > 1 ? 'יותר מתיק אחד — לשאול את המשתמש איזה, לפי תאריך ומקום.' : null];

        case 'cases':
            $where = ['org_id=?']; $args = [$orgId];
            if (($in['month'] ?? '') !== '') { $where[] = 'month=?'; $args[] = validMonth((string)$in['month']); }
            $q = cleanText($in['q'] ?? '', 80);
            if ($q !== '') { $where[] = "name LIKE ? ESCAPE '\\'"; $args[] = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%'; }
            $status = (string)($in['status'] ?? '');
            if ($status !== '' && !in_array($status, ['missing', 'verify', 'unchecked', 'done'], true)) extFail(400, 'status: missing | verify | unchecked | done');
            // הסטטוס מחושב מהבדיקות (JSON), ולכן מסננים את כל תיקי הארגון שעברו את סינון ה-SQL — ורק אז חותכים
            $st = $pdo->prepare('SELECT body FROM funerals WHERE ' . implode(' AND ', $where) . ' ORDER BY month DESC, name');
            $st->execute($args);
            $list = [];
            foreach ($st as $r) {
                $c = extSummary(json_decode($r['body'], true));
                if ($status === '' || $c['status'] === $status) $list[] = $c;
            }
            return ['count' => count($list), 'more' => count($list) > 200, 'cases' => array_slice($list, 0, 200)];

        case 'case':
            $c = extCase($pdo, $orgId, (string)($in['id'] ?? ''));
            $st = $pdo->prepare('SELECT at, actor, source, action, diff FROM events WHERE org_id=? AND case_id=? ORDER BY id DESC LIMIT 10');
            $st->execute([$orgId, $c['id']]);
            $events = array_map(static fn($e) => ['diff' => json_decode((string)$e['diff'], true)] + $e, $st->fetchAll());
            $checks = [];
            foreach (extChecks() as $k => $label) {
                $ch = $c['checks'][$k] ?? ['status' => 'unknown', 'value' => '', 'note' => ''];
                $checks[] = ['key' => $k, 'name' => $label, 'status_name' => EXT_STATUSES[$ch['status'] ?? 'unknown'] ?? ''] + $ch;
            }
            [$files, $filesNote] = extCaseFiles($pdo, $c, $orgId);
            return ['case' => $c, 'summary' => extSummary($c), 'checks' => $checks, 'files' => $files,
                'files_note' => $filesNote, 'events' => $events];

        case 'files':
            if (!extDropboxReady('dropboxCaseFiles')) extFail(501, 'Dropbox עוד לא חובר');
            $c = extCase($pdo, $orgId, (string)($in['id'] ?? ''));
            [$files, $filesNote, $filesStatus] = extCaseFiles($pdo, $c, $orgId) + [2 => 502];
            if ($files === null) extFail($filesStatus, (string)$filesNote);
            return ['id' => $c['id'], 'files' => $files];

        case 'file_text':
            if (!extDropboxReady('dropboxFileText')) extFail(501, 'Dropbox עוד לא חובר');
            $fileId = filter_var($in['file'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($fileId === false) extFail(400, 'file — מזהה הקובץ (מספר) מ-files');
            try {
                $text = dropboxFileText($pdo, $orgId, $fileId);   // 03: קובץ של ארגון אחר / לא קיים → null
            } catch (Throwable $e) {
                extFail(...extDropboxError($e));
            }
            if ($text === null) extFail(404, 'קובץ לא נמצא. מזהים — מ-files.');
            return ['file' => $fileId, 'text' => mb_strcut($text, 0, 200 * 1024)];

        case 'case_update':
            // הכתיבה — formSaveCase() של 02 עם via: api: "הושלם" רק באדם, ובדיקה שאדם כבר סגר לא נפתחת מחדש.
            // ההרשאה — need(caseChangeNeeds()) כמו ב-api.php; לפניה בדיקה לפי שדה, כדי לומר לצ'אט בדיוק מה אסור.
            $p = $in;
            $old = extCase($pdo, $orgId, cleanText($p['id'] ?? '', 100));
            $fields = $p['fields'] ?? [];
            $checksIn = $p['checks'] ?? [];
            $note = cleanText($p['note'] ?? '', 800);
            if (!is_array($fields) || !is_array($checksIn)) extFail(400, 'fields ו-checks — אובייקטים');
            if (!$fields && !$checksIn && $note === '') extFail(400, 'אין מה לעדכן: fields, checks או note');
            foreach ($fields as $k => $v) {
                if (!is_string($k) || !isset(EXT_FIELDS[$k])) extFail(400, 'שדה לא מוכר: ' . $k . '. השדות: ' . implode(', ', array_keys(EXT_FIELDS)));
                $okType = $k === 'payments' ? is_array($v) && array_is_list($v) : (is_string($v) || is_int($v) || is_float($v));
                if (!$okType) extFail(400, 'שדה ' . $k . ': ' . ($k === 'payments' ? 'נדרשת רשימה' : 'נדרש טקסט') . '. שום דבר לא נשמר.');
                if (!extCan(EXT_FIELDS[$k])) extFail(403, 'אין לך הרשאה לשנות ' . $k . ' (התפקיד שלך: ' . $roleName . '). שום דבר לא נשמר.');
            }
            $raw = $old;
            $closed = [];
            foreach ($fields as $k => $v) $raw[$k] = $v;
            foreach ($checksIn as $k => $v) {
                if (!is_string($k) || !isset(extChecks()[$k])) extFail(400, 'בדיקה לא מוכרת: ' . $k . '. הרשימה ב-schema.');
                if (!is_array($v)) extFail(400, 'בדיקה ' . $k . ': נדרש אובייקט {status, value, note}');
                foreach (['status', 'value', 'note'] as $f) {
                    if (array_key_exists($f, $v) && !is_string($v[$f]) && !is_int($v[$f]) && !is_float($v[$f])) extFail(400, 'בדיקה ' . $k . ': ' . $f . ' — נדרש טקסט. שום דבר לא נשמר.');
                }
                $prev = $old['checks'][$k] ?? ['status' => 'unknown', 'value' => '', 'note' => '', 'source' => ''];
                $status = cleanText($v['status'] ?? ($prev['status'] ?? 'unknown'), 30);
                if (!isset(EXT_STATUSES[$status])) extFail(400, 'סטטוס לא מוכר: ' . $status);
                // בדיקה שאדם כבר סגר (סטטוס סופי) — הצ'אט לא משנה אותה בכלל, גם לא ל"חסר"/"טרם נבדק"; ההצעה נכתבת בהערות התיק
                if (!finalAllowed($via) && in_array($prev['status'] ?? '', FORM_FINAL_STATUSES, true)) {
                    $val = array_key_exists('value', $v) ? cleanText($v['value'], 500) : (string)($prev['value'] ?? '');
                    if ($status !== $prev['status'] || $val !== (string)($prev['value'] ?? '')) {
                        $closed[$k] = 'הצעה ל"' . extChecks()[$k] . '": ' . EXT_STATUSES[$status] . ($val !== '' ? ' — ' . $val : '');
                    }
                    continue;
                }
                $raw['checks'][$k] = ['status' => $status,
                    'value' => array_key_exists('value', $v) ? cleanText($v['value'], 500) : (string)($prev['value'] ?? ''),
                    'note' => array_key_exists('note', $v) ? cleanText($v['note'], 800) : (string)($prev['note'] ?? ''),
                    'source' => (string)($prev['source'] ?? '')];
            }
            $line = null;
            if ($closed) $note = trim(implode('; ', $closed) . ($note !== '' ? '. ' . $note : ''));
            if ($note !== '') {
                $line = '[' . date('d.m.Y') . '] ' . $actor['actor'] . ': ' . $note;
                $raw['notes'] = trim(((string)($raw['notes'] ?? '')) . "\n" . $line);
                if (mb_strlen($raw['notes']) > 1500) extFail(400, 'ההערות של התיק מלאות (1500 תווים). לקצר במסך התיק.');
            }
            $pdo->beginTransaction();   // של PDO — כדי ש-logRequest יבטל אותה אם הכתיבה נדחתה
            if ($guard !== null) $guard();
            $saved = formSaveCase($pdo, $raw, $via, $actor, $orgId,
                fn(?array $o, array $item) => need(caseChangeNeeds($o === null ? null : normalize($o, $orgId), $item)));
            $dry ? $pdo->rollBack() : $pdo->commit();
            $out = ['fields' => [], 'checks' => [], 'note' => $line];
            $oldN = normalize($old, $orgId);   // אותם טיפוסים (100 מול 100.0) — כדי לא לדווח על שדה שלא השתנה
            foreach (array_keys(EXT_FIELDS) as $f) {
                if (($saved[$f] ?? null) !== ($oldN[$f] ?? null) && ($f !== 'notes' || isset($fields['notes']))) $out['fields'][$f] = $saved[$f];
            }
            $converted = [];
            foreach (array_keys($checksIn) as $k) {
                $c = $saved['checks'][$k] ?? ['status' => 'unknown', 'value' => '', 'note' => ''];
                $out['checks'][$k] = ['name' => extChecks()[$k], 'status' => $c['status'], 'status_name' => EXT_STATUSES[$c['status']] ?? '',
                    'value' => $c['value'] ?? '', 'note' => $c['note'] ?? '', 'by' => $c['by'] ?? null, 'via' => $c['via'] ?? null];
                $asked = (string)($checksIn[$k]['status'] ?? $c['status']);
                if (isset($closed[$k])) {
                    $converted[] = ['check' => $k, 'name' => extChecks()[$k], 'requested' => $asked, 'saved' => $c['status'],
                        'reason' => 'אדם כבר סגר את הבדיקה הזו — הצ\'אט לא משנה אותה. ההצעה נכתבה בהערות התיק.'];
                } elseif (in_array($asked, FORM_FINAL_STATUSES, true) && !finalAllowed($via)) {
                    $converted[] = ['check' => $k, 'name' => extChecks()[$k], 'requested' => $asked, 'saved' => $c['status'],
                        'reason' => 'רק אדם במשרד מאשר סופית. נשמר "לאימות".'];
                }
            }
            $msg = $converted ? 'נשמר. ' . count($converted) . ' בדיקות לא נשמרו כפי שביקשת: "מאומת" נשמר "לאימות", ובדיקה שאדם כבר סגר לא משתנה — רק אדם במשרד מאשר (פירוט ב-converted).' : 'נשמר.';
            return ['ok' => true, 'message' => $msg, 'saved' => $out, 'converted' => $converted, 'summary' => extSummary($saved)];

        case 'case_create':
            $p = $in;
            $date = cleanText($p['date'] ?? '', 10);
            $month = cleanText($p['month'] ?? '', 7);
            if ($month === '') $month = preg_match('/^\d{4}-\d\d/', $date) ? substr($date, 0, 7) : date('Y-m');
            $note = cleanText($p['note'] ?? '', 800);
            $raw = ['id' => 'fd_' . bin2hex(random_bytes(12)), 'month' => $month, 'name' => $p['name'] ?? '', 'date' => $date,
                'time' => $p['time'] ?? '', 'origin' => $p['origin'] ?? '', 'team' => $p['team'] ?? '', 'grave' => $p['grave'] ?? '',
                'notes' => $note === '' ? '' : '[' . date('d.m.Y') . '] ' . $actor['actor'] . ': ' . $note];
            $pdo->beginTransaction();   // של PDO — כדי ש-logRequest יבטל אותה אם הכתיבה נדחתה
            if ($guard !== null) $guard();
            $saved = formSaveCase($pdo, $raw, $via, $actor, $orgId,
                fn(?array $o, array $item) => need(caseChangeNeeds($o === null ? null : normalize($o, $orgId), $item)));
            $dry ? $pdo->rollBack() : $pdo->commit();
            return ['ok' => true, 'message' => 'התיק נפתח.', 'case' => $saved, 'summary' => extSummary($saved)];
    }
    extFail(404, 'פעולה לא מוכרת');
}

function extJson(array $v): string {
    return json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}
