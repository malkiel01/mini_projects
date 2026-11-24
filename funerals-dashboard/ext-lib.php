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
            'query' => [['name' => 'file', 'required' => true, 'description' => 'מזהה הקובץ (מ-files) / File id']],
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
/** קבצי תיק וטקסט של קובץ — רק כשהפונקציות של 03 קיימות. */
function extDropboxReady(string $fn): bool { return function_exists($fn); }

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
    $raw = extBearer();
    $deny = static fn() => reply(401, ['error' => 'נדרש אסימון תקין (Authorization: Bearer fdx_…). אסימון שבוטל או שפג — יוצרים חדש במסך "חיבור ל-ChatGPT".']);
    if ($raw === '') $deny();
    $st = $pdo->prepare('SELECT * FROM tokens WHERE hash=?');
    $st->execute([hash('sha256', $raw)]);
    $t = $st->fetch();
    if (!$t || $t['revoked_at'] !== null || strcmp((string)$t['expires_at'], gmdate('c')) <= 0) $deny();
    $m = extMember($pdo, (int)$t['user_id'], (int)$t['org_id']);
    if ($m === null) $deny();
    extSetContext($m);
    $minute = intdiv(time(), 60);
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
/** מונים ו"הבא בתור": הראשונה בקבוצת "חסר", אחריה "לאימות", אחריה "טרם נבדק" (02 §2.2). */
function extSummary(array $c): array {
    $n = ['missing' => 0, 'verify' => 0, 'unchecked' => 0, 'done' => 0];
    $first = [];
    foreach (EXT_CHECKS as $k => $label) {
        $g = extGroupOf((string)($c['checks'][$k]['status'] ?? 'unknown'));
        $n[$g]++;
        $first[$g] ??= ['check' => $k, 'label' => $label];
    }
    $next = $first['missing'] ?? $first['verify'] ?? $first['unchecked'] ?? null;
    $status = $n['done'] === count(EXT_CHECKS) ? 'done' : ($n['missing'] ? 'missing' : ($n['verify'] ? 'verify' : 'unchecked'));
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
function extJson(array $v): string {
    return json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}
