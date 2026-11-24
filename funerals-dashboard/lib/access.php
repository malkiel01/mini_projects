<?php
declare(strict_types=1);

/**
 * מי המשתמש, באיזה ארגון הוא עובד ומה מותר לו — בשכבה אחת (אפיון 01 §2.2–2.3).
 * can() — ההרשאה היחידה; scope() — הארגון שכל שאילתה מסוננת לפיו. אין בדיקת תפקיד מחוץ לקובץ הזה.
 * מפתח = platform_admin מהשער, בכל בקשה (לא נשמר כתפקיד).
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

const ROLES = ['admin' => 'מנהל', 'office' => 'משרד', 'field' => 'שטח'];
/** ההרשאות לפי תפקיד. מפתח — הכול, בכל הארגונים. */
const PERMS = [
    'case.view'      => ['field', 'office', 'admin'],
    'case.track'     => ['field', 'office', 'admin'],   // בדיקה, הערה, מיקום קבר
    'case.edit'      => ['office', 'admin'],            // פרטי נפטר, מסמכים, תשלומים, תיק חדש
    'case.import'    => ['office', 'admin'],
    'case.export'    => ['office', 'admin'],
    'members.manage' => ['admin'],
    'token.manage'   => ['admin'],                      // אסימון ingest.php (של ארגון 1)
    'orgs.manage'    => [],
    'dev'            => [],
];
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
    $actorSys = ['id' => null, 'actor' => 'system'];
    if ($user === null && $pu['email'] !== '') {
        $st = $pdo->prepare('SELECT * FROM users WHERE email = ? AND sub IS NULL');
        $st->execute([$pu['email']]);
        $user = $st->fetch() ?: null;
        if ($user !== null) {
            $pdo->prepare('UPDATE users SET sub = ? WHERE id = ?')->execute([$pu['sub'], $user['id']]);
            recordEvent(null, null, ['id' => (int)$user['id'], 'actor' => $pu['actor']], 'system', 'user_linked', ['sub' => [null, $pu['sub']]]);
        }
    }
    if ($user === null) {
        $email = $pu['email'] !== '' ? $pu['email'] : null;
        if ($email !== null) {
            $st = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
            $st->execute([$email]);
            if ($st->fetchColumn()) $email = null;   // המייל כבר מקושר ל-sub אחר — חשבון חדש בלי מייל, לא דורסים
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

    // הזמנות פתוחות במייל של המשתמש → חברות (מזמינים, לא מאשרים)
    if (!empty($user['email'])) acceptInvites($uid, (string)$user['email'], $pu['actor']);
    // המפתח הראשון אחרי המיגרציה — admin של ארגון 1, כדי שהבעלים לא יינעל מחוץ לתיקים שלו
    if ($isDev && !(int)$pdo->query("SELECT COUNT(*) FROM members WHERE org_id = 1 AND role = 'admin'")->fetchColumn()) {
        $pdo->prepare("INSERT OR REPLACE INTO members(org_id, user_id, role, created_at, created_by) VALUES (1, ?, 'admin', ?, NULL)")->execute([$uid, $now]);
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
        'user' => ['id' => $uid, 'name' => $pu['name'] !== '' ? $pu['name'] : (string)$user['name'], 'email' => (string)($user['email'] ?? $pu['email'])],
        'actor' => ['id' => $uid, 'actor' => $pu['actor']],
        'dev' => $isDev, 'active' => $active, 'orgs' => $orgs, 'org' => $org,
        'role' => $org['role'] ?? null,
    ];
}

/** הארגונים שהמשתמש רשאי לעבוד בהם. מפתח — כולם (role = התפקיד שלו בארגון, אם יש). */
function userOrgs(int $uid, bool $isDev): array {
    $sql = $isDev
        ? 'SELECT o.id, o.name, m.role FROM orgs o LEFT JOIN members m ON m.org_id = o.id AND m.user_id = ? ORDER BY o.id'
        : 'SELECT o.id, o.name, m.role FROM orgs o JOIN members m ON m.org_id = o.id AND m.user_id = ? ORDER BY o.id';
    $st = db()->prepare($sql);
    $st->execute([$uid]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'role' => $r['role']], $st->fetchAll());
}

function acceptInvites(int $uid, string $email, string $actor): void {
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM invites WHERE email = ? AND used_at IS NULL ORDER BY id');
    $st->execute([mb_strtolower($email)]);
    foreach ($st->fetchAll() as $inv) {
        $now = gmdate('c');
        $ins = $pdo->prepare('INSERT OR IGNORE INTO members(org_id, user_id, role, created_at, created_by) VALUES (?,?,?,?,?)');
        $ins->execute([$inv['org_id'], $uid, $inv['role'], $now, $inv['created_by']]);
        $pdo->prepare('UPDATE invites SET used_at = ? WHERE id = ?')->execute([$now, $inv['id']]);
        if ($ins->rowCount()) recordEvent((int)$inv['org_id'], null, ['id' => $uid, 'actor' => $actor], 'system', 'invite_used',
            ['user_id' => [null, $uid], 'role' => [null, $inv['role']]]);
    }
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
    return in_array($c['role'], PERMS[$perm], true);
}

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
