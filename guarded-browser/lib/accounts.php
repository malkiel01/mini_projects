<?php
/**
 * חשבונות, הורים ומפתחים — הקומות שמעל הילד (SPEC.md, סעיפים 3–4, 11).
 *
 * הילד הוא שורה ב-users (עם account_id). ההורה מנהל רק את החשבון שלו;
 * המפתח רואה את כל החשבונות, אבל רק נתוני ניהול — לא כתובות, לא היסטוריה.
 * לכן אין כאן אף שאילתה על audit, usage או traces.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/* ── עזרים ─────────────────────────────────────────────────────── */

/** סיסמה זמנית קריאה: בלי 0/O ו-1/l, כדי שאפשר יהיה להכתיב אותה בטלפון. */
function tempPassword(int $len = 10): string {
    $abc = 'abcdefghjkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $abc[random_int(0, strlen($abc) - 1)];
    return $out;
}

/** שם כניסה להורה — דוא"ל או שם משתמש. מוחזרת שגיאה אחת, או ''. */
function loginProblem(string $login): string {
    if (!preg_match('/^[a-z0-9._@+\-]{3,100}$/', $login)) {
        return 'שם הכניסה: 3–100 תווים באנגלית — דוא"ל או שם משתמש, בלי רווחים';
    }
    return '';
}

function cleanLogin(string $login): string {
    return strtolower(trim($login));
}

/** תאריך YYYY-MM-DD תקין, או ''. */
function cleanDate(string $d): string {
    $d = trim($d);
    if ($d === '') return '';
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d, new DateTimeZone('UTC'));
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
}

/**
 * מצב התוקף לתצוגה: none · ok · soon (אזהרה) · grace (חסד, התוקף עבר אך האכיפה נמשכת) ·
 * released (משוחרר). השלבים עצמם ב-expiryStage() שב-policy.php — מקור אמת אחד.
 */
function expiryState(string $expiresAt, ?DateTimeImmutable $now = null): string {
    return expiryStage($expiresAt, $now);
}

function moduleList(string $csv): array {
    return array_values(array_intersect(ACCOUNT_MODULES, array_filter(explode(',', $csv))));
}

/* ── חשבונות ──────────────────────────────────────────────────── */

function accountRow(int $id): ?array {
    return one('SELECT * FROM accounts WHERE id = ?', [$id]);
}

/** החשבון עם המונים — מה שהמפתח רואה. */
function accountView(array $a): array {
    $id = (int) $a['id'];
    return [
        'id'           => $id,
        'name'         => $a['name'],
        'status'       => $a['status'],
        'expires_at'   => $a['expires_at'],
        'expiry'       => expiryState((string) $a['expires_at']),
        'level_max'    => $a['level_max'],
        'modules'      => moduleList((string) $a['modules']),
        'max_children' => (int) $a['max_children'],
        'max_devices'  => (int) $a['max_devices'],
        'note'         => $a['note'],
        'created_at'   => $a['created_at'],
        'parents'      => (int) one('SELECT COUNT(*) n FROM parents WHERE account_id = ?', [$id])['n'],
        'children'     => (int) one('SELECT COUNT(*) n FROM users WHERE account_id = ? AND is_admin = 0', [$id])['n'],
        'devices'      => accountDeviceCount($id),
        'last_seen_at' => (string) (one('SELECT MAX(d.last_seen_at) t FROM devices d JOIN users u ON u.id = d.user_id
                                         WHERE u.account_id = ?', [$id])['t'] ?? ''),
    ];
}

function accountDeviceCount(int $accountId): int {
    return (int) one('SELECT COUNT(*) n FROM devices d JOIN users u ON u.id = d.user_id
                      WHERE u.account_id = ?', [$accountId])['n'];
}

/** כל החשבונות, החדשים קודם; ממתינים תמיד למעלה. */
function accountsList(): array {
    $rows = all("SELECT * FROM accounts
                 ORDER BY CASE status WHEN 'pending' THEN 0 ELSE 1 END, id DESC");
    return array_map('accountView', $rows);
}

/** הפרטים המלאים: החשבון, ההורים, והילדים — שמות ומספרים בלבד. */
function accountDetail(int $id): ?array {
    $a = accountRow($id);
    if (!$a) return null;
    $view = accountView($a);
    $view['parent_list'] = array_map(fn($p) => [
        'id' => (int) $p['id'], 'login' => $p['login'], 'name' => $p['name'],
        'status' => $p['status'], 'must_change_pw' => (bool) $p['must_change_pw'],
        'last_login_at' => $p['last_login_at'], 'platform' => (string) $p['platform_sub'] !== '',
    ], all('SELECT * FROM parents WHERE account_id = ? ORDER BY id', [$id]));
    $view['child_list'] = array_map(fn($u) => [
        'id' => (int) $u['id'], 'name' => $u['display_name'] ?: $u['username'],
        'status' => $u['status'], 'last_seen_at' => $u['last_seen_at'],
        'devices' => (int) one('SELECT COUNT(*) n FROM devices WHERE user_id = ?', [$u['id']])['n'],
    ], all('SELECT * FROM users WHERE account_id = ? AND is_admin = 0 ORDER BY id', [$id]));
    return $view;
}

/**
 * מאמת ומנרמל את מה שהמפתח שלח. מוחזר [ערכים, שגיאה].
 * רק שדות שנשלחו נכנסים — כך עדכון חלקי לא מאפס את השאר.
 */
function accountInput(array $in): array {
    $out = [];
    if (array_key_exists('name', $in)) {
        $name = trim((string) $in['name']);
        if ($name === '' || mb_strlen($name) > 80) return [[], 'שם החשבון: 1–80 תווים'];
        $out['name'] = $name;
    }
    if (array_key_exists('level_max', $in)) {
        if (!in_array($in['level_max'], ACCOUNT_LEVELS, true)) return [[], 'רמת אכיפה לא מוכרת'];
        $out['level_max'] = $in['level_max'];
    }
    if (array_key_exists('modules', $in)) {
        $mods = is_array($in['modules']) ? $in['modules'] : [];
        $out['modules'] = implode(',', array_values(array_intersect(ACCOUNT_MODULES, $mods)));
    }
    foreach (['max_children', 'max_devices'] as $k) {
        if (!array_key_exists($k, $in)) continue;
        $n = filter_var($in[$k], FILTER_VALIDATE_INT);
        if ($n === false || $n < 0 || $n > 999) return [[], 'מכסה: מספר בין 0 ל-999 (0 = בלי הגבלה)'];
        $out[$k] = $n;
    }
    if (array_key_exists('expires_at', $in)) {
        $raw = (string) $in['expires_at'];
        $d = cleanDate($raw);
        if ($raw !== '' && $d === '') return [[], 'תאריך תוקף לא תקין'];
        $out['expires_at'] = $d;
    }
    if (array_key_exists('note', $in)) {
        $out['note'] = mb_substr(trim((string) $in['note']), 0, 500);
    }
    return [$out, ''];
}

/**
 * חשבון חדש עם ההורה הראשון. מוחזר [account_id, סיסמה זמנית, שגיאה].
 * חשבון שהמפתח פותח — פעיל מיד; אין סיבה שיחכה לאישור של מי שפתח אותו.
 */
function accountCreate(array $in, string $parentLogin, string $parentName): array {
    [$vals, $err] = accountInput($in + ['name' => '']);
    if ($err) return [0, '', $err];

    $login = cleanLogin($parentLogin);
    if ($e = loginProblem($login)) return [0, '', $e];
    if (one('SELECT id FROM parents WHERE login = ?', [$login])) return [0, '', 'שם הכניסה הזה כבר תפוס'];

    $vals += ['level_max' => 'browser', 'modules' => 'yt_filtered', 'max_children' => 3,
              'max_devices' => 3, 'expires_at' => '', 'note' => ''];

    db()->beginTransaction();
    q('INSERT INTO accounts (name, status, expires_at, level_max, modules, max_children, max_devices,
                             note, created_at, approved_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [$vals['name'], 'active', $vals['expires_at'], $vals['level_max'], $vals['modules'],
       $vals['max_children'], $vals['max_devices'], $vals['note'], nowIso(), nowIso()]);
    $id = (int) db()->lastInsertId();
    $pw = tempPassword();
    q('INSERT INTO parents (account_id, login, name, password_hash, must_change_pw, created_at)
       VALUES (?, ?, ?, ?, 1, ?)',
      [$id, $login, mb_substr(trim($parentName), 0, 80), hashPassword($pw), nowIso()]);
    db()->commit();
    return [$id, $pw, ''];
}

function accountUpdate(int $id, array $in): string {
    if (!accountRow($id)) return 'החשבון לא נמצא';
    [$vals, $err] = accountInput($in);
    if ($err) return $err;
    if (!$vals) return '';
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($vals)));
    q("UPDATE accounts SET $sets WHERE id = ?", array_merge(array_values($vals), [$id]));
    return '';
}

/**
 * מצב החשבון. השעיה מנתקת מיד: אסימוני המכשירים של כל הילדים נמחקים,
 * והפעימה הבאה בכל מכשיר נדחית. ההורים מתנתקים גם הם.
 */
function accountSetStatus(int $id, string $status): string {
    $a = accountRow($id);
    if (!$a) return 'החשבון לא נמצא';
    if (!in_array($status, ACCOUNT_STATUSES, true)) return 'מצב לא מוכר';

    q('UPDATE accounts SET status = ?, approved_at = CASE WHEN ? = ? AND approved_at = ? THEN ? ELSE approved_at END
       WHERE id = ?', [$status, $status, 'active', '', nowIso(), $id]);
    if ($status === 'suspended') {
        q('DELETE FROM devices WHERE user_id IN (SELECT id FROM users WHERE account_id = ?)', [$id]);
        foreach (all('SELECT id FROM parents WHERE account_id = ?', [$id]) as $p) {
            endAllSessions('parent', (int) $p['id']);
        }
    }
    return '';
}

/**
 * הארכה בחודשים. מתחילים מהתוקף הנוכחי אם הוא עוד בעתיד, ואחרת מהיום —
 * כך הארכה מוקדמת לא "שורפת" ימים, ומאוחרת לא מתחילה מתאריך שעבר.
 */
function accountExtend(int $id, int $months, ?DateTimeImmutable $now = null): string {
    $a = accountRow($id);
    if (!$a) return 'החשבון לא נמצא';
    if ($months < 1 || $months > 60) return 'הארכה: 1–60 חודשים';
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $today = $now->format('Y-m-d');
    $from = ($a['expires_at'] !== '' && $a['expires_at'] > $today)
        ? new DateTimeImmutable($a['expires_at'], new DateTimeZone('UTC'))
        : new DateTimeImmutable($today, new DateTimeZone('UTC'));
    q('UPDATE accounts SET expires_at = ? WHERE id = ?', [$from->modify("+$months months")->format('Y-m-d'), $id]);
    return '';
}

/**
 * מה שחוסם מכשיר של ילד בגלל החשבון (לא בגלל הילד עצמו), או null.
 * תוקף שעבר — שלב 10 (התראה, חסד ושחרור חתום); כאן רק מצב החשבון.
 */
function accountBlock(array $user): ?array {
    $accountId = (int) ($user['account_id'] ?? 0);
    if ($accountId <= 0) return null;
    $a = accountRow($accountId);
    if (!$a) return null;
    if ($a['status'] === 'suspended') return ['account_suspended', 'החשבון של המשפחה מושעה. פנו להורים.'];
    if ($a['status'] === 'pending')   return ['account_pending', 'החשבון של המשפחה ממתין לאישור.'];
    return null;
}

/** מכשיר חדש יחרוג ממכסת המכשירים של החשבון? מכשיר שכבר רשום — לא נספר שוב. */
function accountDeviceQuotaFull(int $accountId, string $deviceId): bool {
    $a = accountRow($accountId);
    if (!$a || (int) $a['max_devices'] <= 0) return false;
    if ($deviceId !== '' && one('SELECT d.id FROM devices d JOIN users u ON u.id = d.user_id
                                 WHERE u.account_id = ? AND d.device_id = ?', [$accountId, $deviceId])) {
        return false;
    }
    return accountDeviceCount($accountId) >= (int) $a['max_devices'];
}

/* ── הורים ─────────────────────────────────────────────────────── */

/** הורה נוסף לחשבון. מוחזר [סיסמה זמנית, שגיאה]. */
function parentAdd(int $accountId, string $login, string $name): array {
    if (!accountRow($accountId)) return ['', 'החשבון לא נמצא'];
    $login = cleanLogin($login);
    if ($e = loginProblem($login)) return ['', $e];
    if (one('SELECT id FROM parents WHERE login = ?', [$login])) return ['', 'שם הכניסה הזה כבר תפוס'];
    $pw = tempPassword();
    q('INSERT INTO parents (account_id, login, name, password_hash, must_change_pw, created_at)
       VALUES (?, ?, ?, ?, 1, ?)', [$accountId, $login, mb_substr(trim($name), 0, 80), hashPassword($pw), nowIso()]);
    return [$pw, ''];
}

/** סיסמה זמנית להורה; חובה להחליף בכניסה הבאה, וכל המכשירים שלו מתנתקים. */
function parentReset(int $parentId): array {
    if (!one('SELECT id FROM parents WHERE id = ?', [$parentId])) return ['', 'ההורה לא נמצא'];
    $pw = tempPassword();
    q('UPDATE parents SET password_hash = ?, must_change_pw = 1 WHERE id = ?', [hashPassword($pw), $parentId]);
    endAllSessions('parent', $parentId);
    return [$pw, ''];
}

function parentRemove(int $parentId): string {
    $p = one('SELECT * FROM parents WHERE id = ?', [$parentId]);
    if (!$p) return 'ההורה לא נמצא';
    $left = (int) one('SELECT COUNT(*) n FROM parents WHERE account_id = ?', [$p['account_id']])['n'];
    if ($left <= 1) return 'לחשבון חייב להישאר לפחות הורה אחד';
    endAllSessions('parent', $parentId);
    q('DELETE FROM parents WHERE id = ?', [$parentId]);
    return '';
}

/* ── כניסת הורה ────────────────────────────────────────────────── */

function currentParent(): ?array {
    // כל ההורים נכנסים דרך הפלטפורמה (lib/platform.php): הזהות מהשער קובעת מי ההורה, לא העוגייה.
    if (($id = platformIdentity()) && !platformSession('parent', $id)) return null;
    $s = sessionRow('parent');
    if (!$s) return null;
    $p = one("SELECT * FROM parents WHERE id = ? AND status = 'active'", [(int) $s['user_id']]);
    return $p ?: null;
}

/** כניסת הורה. מוחזר [parent, שגיאה]. אותה הודעה לשם שגוי ולסיסמה שגויה. */
function parentLogin(string $login, string $password): array {
    if (platformIdentity()) return [null, 'הכניסה היא דרך הכלים הדיגיטליים'];
    if (!passwordLoginOpen()) return [null, 'הכניסה בסיסמה כבויה — היכנסו דרך הכלים הדיגיטליים'];
    $login = cleanLogin($login);
    $key = 'parent:' . $login;
    if (tooManyFailures($key)) return [null, 'יותר מדי ניסיונות. נסו שוב בעוד רבע שעה.'];
    $p = one('SELECT * FROM parents WHERE login = ?', [$login]);
    if (!$p || !password_verify($password, $p['password_hash'])) {
        audit(0, 'login_fail', false, '', 'parent', $key);
        return [null, 'שם כניסה או סיסמה שגויים'];
    }
    if ($p['status'] !== 'active') return [null, 'החשבון הזה חסום. פנו למנהל המערכת.'];
    q('UPDATE parents SET last_login_at = ? WHERE id = ?', [nowIso(), $p['id']]);
    startSession('parent', (int) $p['id']);
    return [$p, ''];
}

/**
 * החלפת סיסמה. אחרי סיסמה זמנית — חובה, ומבטל את הדגל.
 * שאר המכשירים של ההורה מתנתקים; זה שהחליף — נשאר מחובר.
 */
function parentChangePassword(int $id, string $current, string $next): string {
    $p = one('SELECT * FROM parents WHERE id = ?', [$id]);
    if (!$p || !password_verify($current, $p['password_hash'])) return 'הסיסמה הנוכחית שגויה';
    if ($e = passwordProblem($next)) return $e;
    if (hash_equals($current, $next)) return 'הסיסמה החדשה זהה לישנה';
    q('UPDATE parents SET password_hash = ?, must_change_pw = 0 WHERE id = ?', [hashPassword($next), $id]);
    $keep = hash('sha256', (string) ($_COOKIE[SESSION_COOKIES['parent']] ?? ''));
    q("DELETE FROM admin_sessions WHERE kind = 'parent' AND user_id = ? AND token_hash <> ?", [$id, $keep]);
    return '';
}

/* ── המפתח ─────────────────────────────────────────────────────── */

function developerCount(): int {
    return (int) one('SELECT COUNT(*) n FROM developers')['n'];
}

function currentDeveloper(): ?array {
    // מחוברת לפלטפורמה: הזהות מהשער קובעת מי המפתח (lib/platform.php), לא העוגייה שהדפדפן שלח.
    if (($id = platformIdentity()) && !platformSession('dev', $id)) return null;
    $s = sessionRow('dev');
    if (!$s) return null;
    $d = one('SELECT id, username, name, created_at, last_login_at FROM developers WHERE id = ?',
             [(int) $s['user_id']]);
    if ($d) $GLOBALS['GB_ADMIN_ID'] = -(int) $d['id'];   // מאפשר הצגת שגיאות למפתח
    return $d ?: null;
}

/** כניסת מפתח. מוחזר [developer, שגיאה]. */
function developerLogin(string $username, string $password): array {
    if (platformIdentity()) return [null, 'הכניסה היא דרך הכלים הדיגיטליים'];
    if (!passwordLoginOpen()) return [null, 'הכניסה בסיסמה כבויה — היכנסו דרך הכלים הדיגיטליים'];
    $key = 'dev:' . $username;
    if (tooManyFailures($key)) return [null, 'יותר מדי ניסיונות. נסו שוב בעוד רבע שעה.'];
    $d = one('SELECT * FROM developers WHERE username = ?', [$username]);
    if (!$d || !password_verify($password, $d['password_hash'])) {
        audit(0, 'login_fail', false, '', 'dev', $key);
        return [null, 'שם משתמש או סיסמה שגויים'];
    }
    q('UPDATE developers SET last_login_at = ? WHERE id = ?', [nowIso(), $d['id']]);
    startSession('dev', (int) $d['id']);
    return [$d, ''];
}

/** המפתח הראשון — רק כשאין אף אחד. אחר כך המסך נסגר לתמיד. */
function developerSetup(string $username, string $name, string $password): array {
    if (developerCount() > 0) return [0, 'כבר יש מנהל מערכת'];
    if (platformIdentity()) return [0, 'הכניסה היא דרך הכלים הדיגיטליים'];
    if ($e = usernameProblem($username)) return [0, $e];
    if ($e = passwordProblem($password)) return [0, $e];
    q('INSERT INTO developers (username, name, password_hash, created_at, last_login_at) VALUES (?, ?, ?, ?, ?)',
      [$username, mb_substr(trim($name), 0, 80), hashPassword($password), nowIso(), nowIso()]);
    $id = (int) db()->lastInsertId();
    startSession('dev', $id);
    return [$id, ''];
}

function developerChangePassword(int $id, string $current, string $next): string {
    $d = one('SELECT * FROM developers WHERE id = ?', [$id]);
    if (!$d || !password_verify($current, $d['password_hash'])) return 'הסיסמה הנוכחית שגויה';
    if ($e = passwordProblem($next)) return $e;
    q('UPDATE developers SET password_hash = ? WHERE id = ?', [hashPassword($next), $id]);
    return '';
}

/* ── מבט מערכת ─────────────────────────────────────────────────── */

/** מספרים ומכשירים שנעלמו — בלי שום תוכן גלישה. */
function systemOverview(?DateTimeImmutable $now = null): array {
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $dayAgo = $now->modify('-1 day')->format('Y-m-d\TH:i:s\Z');
    return [
        'accounts' => (int) one('SELECT COUNT(*) n FROM accounts')['n'],
        'parents'  => (int) one('SELECT COUNT(*) n FROM parents')['n'],
        'children' => (int) one('SELECT COUNT(*) n FROM users WHERE is_admin = 0')['n'],
        'devices'  => (int) one('SELECT COUNT(*) n FROM devices')['n'],
        'online'   => (int) one('SELECT COUNT(*) n FROM devices WHERE last_seen_at > ?', [$dayAgo])['n'],
        'silent'   => all('SELECT d.device_name, d.last_seen_at, u.display_name, u.username, a.name AS account
                           FROM devices d JOIN users u ON u.id = d.user_id
                           LEFT JOIN accounts a ON a.id = u.account_id
                           WHERE d.last_seen_at <= ? ORDER BY d.last_seen_at DESC LIMIT 50', [$dayAgo]),
    ];
}
