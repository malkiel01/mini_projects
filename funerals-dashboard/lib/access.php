<?php
declare(strict_types=1);

/**
 * מי המשתמש, באיזה ארגון הוא עובד ומה מותר לו — בשכבה אחת (אפיון 01 §2.2–2.3).
 * can() — ההרשאה היחידה; scope() — הארגון שכל שאילתה מסוננת לפיו. אין בדיקת תפקיד מחוץ לקובץ הזה.
 * מפתח = platform_admin מהשער, בכל בקשה (לא נשמר כתפקיד).
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

const ROLES = ['admin' => 'מנהל', 'office' => 'משרד', 'field' => 'שטח'];
/**
 * ההרשאות לפי תפקיד לפני פרוסה 11 — היום רק רשימת השמות של ההרשאות, ונפילה כשאין טבלת roles.
 * ההרשאות בפועל — מטבלת roles (lib/roles.php, ctxPerms). מפתח — הכול, בכל הארגונים.
 */
const PERMS = [
    'case.view'      => ['field', 'office', 'admin'],
    'case.track'     => ['field', 'office', 'admin'],   // בדיקה, הערה, מיקום קבר
    'case.edit'      => ['office', 'admin'],            // פרטי נפטר, מסמכים, תשלומים, תיק חדש
    'case.import'    => ['office', 'admin'],
    'case.export'    => ['office', 'admin'],
    'members.manage' => ['admin'],
    'token.manage'   => ['admin'],                      // אסימון ingest.php (של ארגון 1)
    'dropbox.manage' => ['admin'],                      // פרוסה 03: חיבור Dropbox, תיקייה ראשית, סריקה ושיוך תיקיות
    'files.upload'   => ['office', 'admin'],            // פרוסה 03: העלאה לתיקיית התיק (שטח — פרוסה 04)
    'case.view_assigned' => [],                         // פרוסה 11: צפייה רק בתיקים שהוקצו (לתפקיד מותאם)
    'case.approve'   => ['field', 'office', 'admin'],   // פרוסה 11: אישור בדיקה (מאומת)
    'tasks.manage'   => ['admin'],                      // פרוסה 11: הקצאת משימות
    'settings.manage' => ['admin'],                     // פרוסה 11: בדיקות, תפקידים (ו-dropbox.manage / token.manage)
    'orgs.manage'    => [],
    'dev'            => [],
];
/** הרשאות שפועלות על תיקים — למשתמש "רק תיקים שהוקצו לי" הן חלות רק עם תיק מסוים (can($perm, $case)). */
const CASE_PERMS = ['case.view', 'case.track', 'case.edit', 'case.approve', 'files.upload', 'case.import', 'case.export'];
/** השדות שעובד שטח רשאי לשנות בתיק. */
const TRACK_FIELDS = ['checks', 'notes', 'grave'];

/** ההקשר של הבקשה. נקבע פעם אחת ב-resolveAccess(). */
function ctx(): array { return $GLOBALS['fd_ctx'] ?? throw new LogicException('ctx() לפני resolveAccess()'); }

/**
 * משתמש מהשער → משתמש באפליקציה (לפי sub, אחר כך לפי מייל, אחרת חדש), הזמנות פתוחות, מפתח ראשון → admin של ארגון 1,
 * והארגון הפעיל מה-session (נבדק מול members בכל בקשה).
 */
function resolveAccess(array $pu): array {
    $pdo = db();
    $now = gmdate('c');
    $st = $pdo->prepare('SELECT * FROM users WHERE sub = ?');
    $st->execute([$pu['sub']]);
    $user = $st->fetch() ?: null;
    // כל סמן נסגר מיד: סמן פתוח מחזיק נעילת קריאה, וה-UPDATE שאחריו (שדרוג לכתיבה) נכשל מיד ב-"database is locked"
    // כשבקשה אחרת כותבת באותו רגע — SQLite לא ממתין (busy_timeout) במצב שעלול להיות קיפאון. נמצא בבדיקת העלאות מקבילות.
    $st->closeCursor();
    $actorSys = ['id' => null, 'actor' => 'system'];
    $verified = $pu['email'] !== '' && $pu['email_verified'];
    if ($user === null && $verified) {
        $st = $pdo->prepare('SELECT * FROM users WHERE email = ? AND sub IS NULL');
        $st->execute([$pu['email']]);
        $user = $st->fetch() ?: null;
        $st->closeCursor();
        if ($user !== null) {
            $pdo->prepare('UPDATE users SET sub = ? WHERE id = ?')->execute([$pu['sub'], $user['id']]);
            recordEvent(null, null, ['id' => (int)$user['id'], 'actor' => $pu['actor']], 'system', 'user_linked', ['sub' => [null, $pu['sub']]]);
        }
    }
    if ($user === null) {
        $email = $verified ? $pu['email'] : null;   // מייל לא מאומת לא נשמר — הוא לא יקשר ולא יממש הזמנה
        if ($email !== null) {
            $st = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
            $st->execute([$email]);
            $taken = $st->fetchColumn();
            $st->closeCursor();
            if ($taken) $email = null;   // המייל כבר מקושר ל-sub אחר — חשבון חדש בלי מייל, לא דורסים
        }
        $pdo->prepare('INSERT INTO users(sub, email, name, created_at, last_seen_at, active) VALUES (?,?,?,?,?,1)')
            ->execute([$pu['sub'], $email, $pu['name'], $now, $now]);
        $id = (int)$pdo->lastInsertId();
        recordEvent(null, null, ['id' => $id, 'actor' => $pu['actor']], 'system', 'user_created', ['email' => [null, $email]]);
        $user = ['id' => $id, 'sub' => $pu['sub'], 'email' => $email, 'name' => $pu['name'], 'active' => 1];
    }
    $uid = (int)$user['id'];
    $isDev = $pu['platform_admin'];
    $pdo->prepare('UPDATE users SET last_seen_at = ?, name = CASE WHEN ? <> \'\' THEN ? ELSE name END, platform_admin = ? WHERE id = ?')
        ->execute([$now, $pu['name'], $pu['name'], $isDev ? 1 : 0, $uid]);
    // מייל שאומת עכשיו, לחשבון שנוצר בלי מייל — נשמר (אם אינו של חשבון אחר)
    if (empty($user['email']) && $verified) {
        $st = $pdo->prepare('UPDATE users SET email = ? WHERE id = ? AND NOT EXISTS (SELECT 1 FROM users WHERE email = ?)');
        $st->execute([$pu['email'], $uid, $pu['email']]);
        if ($st->rowCount()) $user['email'] = $pu['email'];
    }

    // הזמנות פתוחות במייל של המשתמש → חברות (מזמינים, לא מאשרים)
    if (!empty($user['email']) && $verified && $user['email'] === $pu['email']) acceptInvites($uid, (string)$user['email'], $pu['actor']);
    // המפתח הראשון אחרי המיגרציה — admin של ארגון 1, כדי שהבעלים לא יינעל מחוץ לתיקים שלו
    if ($isDev && !(int)$pdo->query("SELECT COUNT(*) FROM members WHERE org_id = 1 AND role = 'admin'")->fetchColumn()) {
        $pdo->prepare("INSERT OR REPLACE INTO members(org_id, user_id, role, created_at, created_by) VALUES (1, ?, 'admin', ?, NULL)")->execute([$uid, $now]);
        rolesSeed($pdo, 1);   // פרוסה 11: role_id = המנהל המובנה
        recordEvent(1, null, $actorSys, 'system', 'member_added', ['user_id' => [null, $uid], 'role' => [null, 'admin']]);
    }

    $active = (int)$user['active'] === 1 || $isDev;   // מפתח לא נחסם (אחרת הבעלים ננעל בחוץ)
    $orgs = $active ? userOrgs($uid, $isDev) : [];
    $want = (int)($_SESSION['fd_org'] ?? 0);
    $org = null;
    foreach ($orgs as $o) if ((int)$o['id'] === $want) $org = $o;
    if ($org === null && $orgs) {
        // ברירת מחדל: הארגון הראשון שהמשתמש חבר בו; מפתח בלי חברות — ארגון 1
        foreach ($orgs as $o) if ($o['role'] !== null) { $org = $o; break; }
        $org ??= $orgs[0];
    }
    if ($org !== null) $_SESSION['fd_org'] = (int)$org['id'];
    return $GLOBALS['fd_ctx'] = [
        'user' => ['id' => $uid, 'name' => $pu['name'] !== '' ? $pu['name'] : (string)$user['name'], 'email' => (string)($user['email'] ?? '') ?: $pu['email']],
        'actor' => ['id' => $uid, 'actor' => $pu['actor']],
        'dev' => $isDev, 'active' => $active, 'orgs' => $orgs, 'org' => $org,
        'role' => $org['role'] ?? null,
    ];
}

/**
 * 09: השבתה (בארגון — $orgId, או גלובלית — null) מבטלת את חיבורי ה-OAuth (oauth_grants) שלו, כדי שהפעלה מחדש לא תחייה
 * אסימון רענון ישן. הטבלה נוצרת רק בשימוש הראשון ב-MCP — לפני כן אין מה לבטל.
 */
function revokeOauthGrantsOf(PDO $pdo, int $uid, ?int $orgId, ?int $by): void {
    $st = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='oauth_grants'");
    $has = $st->fetchColumn();
    $st->closeCursor();
    if (!$has) return;
    $pdo->prepare('UPDATE oauth_grants SET revoked_at=?, revoked_by=?, revoked_reason=? WHERE user_id=? AND revoked_at IS NULL'
        . ($orgId === null ? '' : ' AND org_id=' . (int)$orgId))->execute([gmdate('c'), $by, 'member_inactive', $uid]);
}

/** הארגונים שהמשתמש רשאי לעבוד בהם. מפתח — כולם (role = התפקיד שלו בארגון, אם יש). */
function userOrgs(int $uid, bool $isDev): array {
    $sql = $isDev
        ? 'SELECT o.id, o.name, m.role FROM orgs o LEFT JOIN members m ON m.org_id = o.id AND m.user_id = ? ORDER BY o.id'
        : 'SELECT o.id, o.name, m.role FROM orgs o JOIN members m ON m.org_id = o.id AND m.user_id = ? AND m.active = 1 ORDER BY o.id';
    $st = db()->prepare($sql);
    $st->execute([$uid]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'role' => $r['role']], $st->fetchAll());
}

function acceptInvites(int $uid, string $email, string $actor): void {
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM invites WHERE email = ? AND used_at IS NULL ORDER BY id');
    $st->execute([mb_strtolower($email)]);
    $rows = $st->fetchAll();
    foreach ($rows as $inv) {
        $now = gmdate('c');
        // role_id (פרוסה 11) — התפקיד הגמיש מההזמנה; הזמנה ישנה בלי role_id — rolesSeed() למטה ממלא לפי role
        $ins = $pdo->prepare('INSERT OR IGNORE INTO members(org_id, user_id, role, role_id, created_at, created_by) VALUES (?,?,?,?,?,?)');
        $ins->execute([$inv['org_id'], $uid, $inv['role'], $inv['role_id'] ?? null, $now, $inv['created_by']]);
        $pdo->prepare('UPDATE invites SET used_at = ? WHERE id = ?')->execute([$now, $inv['id']]);
        if ($ins->rowCount()) recordEvent((int)$inv['org_id'], null, ['id' => $uid, 'actor' => $actor], 'system', 'invite_used',
            ['user_id' => [null, $uid], 'role' => [null, $inv['role']]]);
    }
    foreach (array_unique(array_column($rows, 'org_id')) as $o) rolesSeed($pdo, (int)$o);
}

/**
 * ההרשאה היחידה. $case — כשהפעולה על תיק: התיק חייב להיות בארגון הפעיל.
 * מפתח — הכול; משתמש מושבת או בלי ארגון — כלום.
 */
function can(string $perm, ?array $case = null): bool {
    $c = ctx();
    if (!isset(PERMS[$perm])) throw new LogicException('הרשאה לא מוכרת: ' . $perm);
    if (!$c['active'] || $c['org'] === null) return false;
    if ($case !== null && (int)($case['org_id'] ?? 0) !== scope()) return false;
    if ($c['dev']) return true;
    if ($perm === 'orgs.manage' || $perm === 'dev') return false;
    $perms = ctxPerms();
    // פרוסה 11: "רק תיקים שהוקצו לי" — כל פעולה על תיק מסוים דורשת שהתיק הוקצה (משימה לי או לתפקיד שלי)
    if ($case !== null && !in_array('case.view', $perms, true)) {
        if (!in_array('case.view_assigned', $perms, true) || !caseAssigned(db(), scope(), (string)($case['id'] ?? ''), (int)$c['user']['id'], ctxRole()['id'] ?? null)) return false;
        if ($perm === 'case.view') return true;
    } elseif ($case === null && !in_array('case.view', $perms, true) && in_array($perm, CASE_PERMS, true)) {
        // בלי תיק מסוים (רשימות, API חיצוני, MCP, שטח, בינה) — רק מי שרואה את כל התיקים. "רק שהוקצו" עובר דרך הפעולות של 11
        // (list, save, history, case_checks, my_tasks), שבודקות את התיק עצמו.
        return false;
    }
    return in_array(PERM_FROM[$perm] ?? $perm, $perms, true);
}

/** רואה תיקים: בכל התיקים, או רק באלה שהוקצו לו. */
function canSeeCases(): bool { return can('case.view') || can('case.view_assigned'); }

/** הארגון הפעיל — כל שאילתה על תיקים מסוננת לפיו. אין ארגון → 403 (נבדק לפני כל פעולה). */
function scope(): int {
    $org = ctx()['org'];
    if ($org === null) reply(403, noOrgBody());
    return (int)$org['id'];
}

/** מסך "עדיין לא שויכת" — בלי שום נתון מלבד המייל של המשתמש עצמו. */
function noOrgBody(): array {
    $c = ctx();
    return ['error' => 'עדיין לא שויכת לחברה קדישא. בקש ממנהל הארגון להזמין אותך במייל ' . ($c['user']['email'] ?: 'שלך') . '.',
        'code' => 'no_org', 'email' => $c['user']['email']];
}

/** חסר → 403 (ובלי ארגון — הודעת "לא שויכת"). */
function need(string $perm, ?array $case = null): void {
    if (ctx()['org'] === null) reply(403, noOrgBody());
    if (!can($perm, $case)) reply(403, ['error' => 'אין לך הרשאה לפעולה הזו']);
}

/** איזו הרשאה השינוי דורש: תיק חדש או שינוי בפרטים/תשלומים/מסמכים — case.edit; רק בדיקות, הערות, מיקום קבר — case.track. */
function caseChangeNeeds(?array $old, array $new): string {
    if ($old === null) return 'case.edit';
    foreach ($new as $k => $v) {
        if (in_array($k, TRACK_FIELDS, true) || $k === 'manual_fields' || $k === 'id') continue;
        $before = $old[$k] ?? (is_array($v) ? [] : '');
        // השוואה מחמירה מול התיק הקודם אחרי normalize() — אותם טיפוסים, כך ש-null→0 או "100"→"1e2" נתפסים כשינוי
        if ($before !== $v) return 'case.edit';
    }
    return 'case.track';
}
