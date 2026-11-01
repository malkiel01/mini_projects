<?php
/**
 * שיתוף בין חשבונות — שלב 7.
 *
 * אין "מנהל" של התוכן: מי שיצר הוא הבעלים, והוא מחליט עם מי לשתף. רמות:
 *
 *   view      צופה      — רואה. במסמך: גם יומן ו-PDF.
 *   fill      ממלא      — בתבנית: מנפיק ממנה מסמכים (שלו). במסמך: ממלא שדות.
 *   edit      עורך      — בתבנית: עורך אותה. במסמך: גם מסיים, ומנהל משתתפים.
 *   co_owner  מנהל-שותף — הכול, כולל שיתוף הלאה וארכיון. לא מוחק — רק הבעלים.
 *
 * תבנית ומסמך משותפים כל אחד בנפרד: שיתוף תבנית לא חושף את המסמכים
 * שהונפקו ממנה, ולהפך. מסמך שמנפיקים מתבנית משותפת שייך למי שהנפיק —
 * אבל בעל התבנית רואה כל מסמך שהונפק ממנה, ברמת "עורך" לפחות (מסיים, מחזיר לתיקון).
 *
 * מי שאין לו גישה בכלל מקבל 404 — כמו לפני השיתוף, לא מגלים שהדבר קיים.
 * מי שיש לו גישה, אבל ברמה נמוכה מדי, מקבל 403 עם הסבר.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const LEVELS = ['view' => 1, 'fill' => 2, 'edit' => 3, 'co_owner' => 4, 'owner' => 5];
const LEVEL_NAMES = ['view' => 'צופה', 'fill' => 'ממלא', 'edit' => 'עורך', 'co_owner' => 'מנהל-שותף', 'owner' => 'בעלים'];
/** הרמה שבעל התבנית מקבל בכל מסמך שהונפק ממנה (אם לא שותף איתו ביותר). */
const TPL_OWNER_DOC_LEVEL = 'edit';

/**
 * 15.4א — שיתוף תבנית: מנהל (co_owner — הכול, כמו הבעלים) או עורך (edit + מתגים, perms_json).
 * כל מתג פותח דבר אחד; הרמה "בפועל" של עורך נגזרת מהם, כדי שכל הבדיקות הקיימות (tpl_owned(..., 'edit') וכו') יעבדו.
 */
const SHARE_PERMS = [
    'structure' => 'עריכת מבנה התבנית',
    'fill'      => 'מילוי',
    'issue'     => 'הנפקת מסמכים',
    'share'     => 'שיתוף הלאה (לא מעל ההרשאות שלו)',
    'send'      => 'טופס חד-פעמי ללקוח',
    'approve'   => 'מאשר מסמכים',
    'all_docs'  => 'רואה את כל המסמכים מהתבנית',
];

/** המתגים של עורך (רשימה נקייה); null — אין שורה או לא עורך. NULL ישן במסד = הכול (לפני ההגירה). */
function clean_perms($p): array
{
    $p = is_string($p) ? (json_decode($p, true) ?: []) : (array)$p;
    return array_values(array_intersect(array_keys(SHARE_PERMS), array_map('strval', $p)));
}

function share_row(string $type, int $id, int $uid): ?array
{
    $st = db()->prepare('SELECT level, perms_json FROM shares WHERE object_type = ? AND object_id = ? AND user_id = ?');
    $st->execute([$type, $id, $uid]);
    return $st->fetch() ?: null;
}

/** עורך בתבנית: הרמה בפועל לפי המתגים. מבנה — עורך; הנפקה / מילוי — מנפיק; אחרת — רואה בלבד. */
function editor_level(?string $permsJson): string
{
    $p = $permsJson === null ? array_keys(SHARE_PERMS) : clean_perms($permsJson);
    return in_array('structure', $p, true) ? 'edit' : (array_intersect(['issue', 'fill'], $p) ? 'fill' : 'view');
}

/** הרמה של המשתמש בדבר, או null. $ownerId — של הדבר עצמו. */
function access_level(string $type, int $id, int $ownerId, array $user): ?string
{
    if ($ownerId === (int)$user['id']) return 'owner';
    $row = share_row($type, $id, (int)$user['id']);
    $l = $row['level'] ?? null;
    if ($type === 'template' && $l === 'edit') return editor_level($row['perms_json']);
    if ($type === 'document') {
        $t = doc_template_access($id, $user);
        if ($t !== null && (!$l || LEVELS[$l] < LEVELS[$t])) return $t;
    }
    return $l;
}

/**
 * הגישה למסמך דרך התבנית שממנה הונפק: בעל התבנית ומנהל בה — "עורך" (מסיים, מחזיר לתיקון);
 * עורך עם "רואה את כל המסמכים" — מעדכן (אם יש לו מילוי) או צופה. אחרת null.
 */
function doc_template_access(int $docId, array $user): ?string
{
    $st = db()->prepare('SELECT t.id, t.owner_id FROM documents d JOIN template_versions v ON v.id = d.template_version_id JOIN templates t ON t.id = v.template_id WHERE d.id = ?');
    $st->execute([$docId]);
    $t = $st->fetch();
    if (!$t) return null;
    if ((int)$t['owner_id'] === (int)$user['id']) return TPL_OWNER_DOC_LEVEL;
    $row = share_row('template', (int)$t['id'], (int)$user['id']);
    if (!$row) return null;
    if ($row['level'] === 'co_owner') return TPL_OWNER_DOC_LEVEL;
    if ($row['level'] !== 'edit') return null;
    $p = $row['perms_json'] === null ? array_keys(SHARE_PERMS) : clean_perms($row['perms_json']);
    if (!in_array('all_docs', $p, true)) return null;
    return in_array('fill', $p, true) ? 'fill' : 'view';
}

/** מתג אחד בתבנית: בעלים ומנהל — הכול; עורך — לפי המתגים. $t — מ-tpl_owned (עם access). */
function tpl_perm(array $t, array $user, string $perm): bool
{
    if (in_array($t['access'] ?? '', ['owner', 'co_owner'], true)) return true;
    $row = share_row('template', (int)$t['id'], (int)$user['id']);
    if (!$row || $row['level'] !== 'edit') return false;
    return in_array($perm, $row['perms_json'] === null ? array_keys(SHARE_PERMS) : clean_perms($row['perms_json']), true);
}

/**
 * מתג בתבנית שממנה הונפק המסמך — למשל "טופס חד-פעמי ללקוח" (send). מי שאינו עורך בתבנית (בעלים, מנהל, או
 * שאין לו שיתוף בה — מסמך ששותף איתו ישירות) — לא מוגבל כאן; ההרשאה במסמך עצמו נבדקת בנפרד.
 */
function doc_tpl_perm(array $d, array $user, string $perm): bool
{
    $st = db()->prepare('SELECT t.id, t.owner_id FROM template_versions v JOIN templates t ON t.id = v.template_id WHERE v.id = ?');
    $st->execute([(int)$d['template_version_id']]);
    $t = $st->fetch();
    if (!$t || (int)$t['owner_id'] === (int)$user['id']) return true;
    $row = share_row('template', (int)$t['id'], (int)$user['id']);
    if (!$row || $row['level'] !== 'edit') return true;
    return in_array($perm, $row['perms_json'] === null ? array_keys(SHARE_PERMS) : clean_perms($row['perms_json']), true);
}

/** המתגים של המשתמש בתבנית — ללקוח (מה להציג בתפריט). בעלים / מנהל — null (הכול). */
function tpl_my_perms(array $t, array $user): ?array
{
    if (in_array($t['access'] ?? '', ['owner', 'co_owner'], true)) return null;
    $row = share_row('template', (int)$t['id'], (int)$user['id']);
    return $row && $row['level'] === 'edit' ? ($row['perms_json'] === null ? array_keys(SHARE_PERMS) : clean_perms($row['perms_json'])) : [];
}

/** בעל התבנית שממנה הונפק המסמך. */
function doc_template_owner(int $docId): int
{
    $st = db()->prepare('SELECT t.owner_id FROM documents d JOIN template_versions v ON v.id = d.template_version_id JOIN templates t ON t.id = v.template_id WHERE d.id = ?');
    $st->execute([$docId]);
    return (int)$st->fetchColumn();
}

/** זורק 404 בלי גישה, 403 ברמה נמוכה. מחזיר את הרמה בפועל. */
function require_level(?string $have, string $min, string $notFound): string
{
    if ($have === null) throw new ApiError($notFound, 404);
    if (LEVELS[$have] < LEVELS[$min]) {
        throw new ApiError('ההרשאה שלך כאן היא "' . LEVEL_NAMES[$have] . '" — הפעולה דורשת "' . LEVEL_NAMES[$min] . '"', 403);
    }
    return $have;
}

/** תנאי SQL: הדבר שלי, או משותף איתי. מסמכים — גם מתבנית שלי, או שאני מנהל בה / עורך שרואה את כל המסמכים. */
function mine_or_shared_sql(string $alias, string $type): string
{
    $tpl = $type === 'document' ? " OR $alias.template_version_id IN (SELECT tv.id FROM template_versions tv JOIN templates tt ON tt.id = tv.template_id WHERE tt.owner_id = :uid)"
        . " OR $alias.template_version_id IN (SELECT tv.id FROM template_versions tv JOIN shares ts ON ts.object_type = 'template' AND ts.object_id = tv.template_id"
        . " WHERE ts.user_id = :uid AND (ts.level = 'co_owner' OR (ts.level = 'edit' AND (ts.perms_json IS NULL OR ts.perms_json LIKE '%\"all_docs\"%'))))" : '';
    return "($alias.owner_id = :uid OR $alias.id IN (SELECT object_id FROM shares WHERE object_type = '$type' AND user_id = :uid)$tpl)";
}

function user_name(int $id): string
{
    static $cache = [];
    if (!isset($cache[$id])) {
        $st = db()->prepare('SELECT display_name FROM users WHERE id = ?');
        $st->execute([$id]);
        $cache[$id] = (string)($st->fetchColumn() ?: '');
    }
    return $cache[$id];
}

// ── ניהול שיתופים ────────────────────────────────────────────

function share_target(array $user, array $b): array
{
    $type = ($b['type'] ?? '') === 'document' ? 'document' : 'template';
    if ($type === 'document') return [$type, doc_owned((int)($b['id'] ?? 0), $user, 'co_owner')];
    // 15.4א: תבנית — בעלים / מנהל, או עורך עם "שיתוף הלאה"
    $row = tpl_owned((int)($b['id'] ?? 0), $user, 'view');
    if (!in_array($row['access'], ['owner', 'co_owner'], true) && !tpl_perm($row, $user, 'share')) {
        throw new ApiError('אין לך הרשאה לשתף את התבנית הזו', 403);
    }
    return [$type, $row];
}

/**
 * רמה ומתגים לשיתוף תבנית: מנהל (co_owner) או עורך (edit + מתגים). עורך שמשתף — רק עורך, ורק מתגים שיש לו.
 * מחזיר [level, perms_json|null].
 */
function share_grant(array $user, array $row, array $b): array
{
    $level = (string)($b['level'] ?? '');
    // הרמות הישנות, כמו במיפוי (README 15.4): "עורך" בלי מתגים — כל המתגים; "ממלא" — עורך שמנפיק וממלא
    $default = $level === 'fill' ? ['issue', 'fill'] : array_keys(SHARE_PERMS);
    if ($level === 'manager') $level = 'co_owner';
    if ($level === 'editor' || $level === 'fill') $level = 'edit';
    if (!in_array($level, ['co_owner', 'edit'], true)) throw new ApiError('בתבנית: מנהל או עורך', 422);
    $mine = tpl_my_perms($row, $user);   // null — בעלים / מנהל
    if ($level === 'co_owner') {
        if ($mine !== null) throw new ApiError('אי אפשר לתת הרשאה גבוהה משלך', 403);
        return ['co_owner', null];
    }
    $perms = clean_perms($b['perms'] ?? ($mine ?? $default));   // בלי מתגים — ברירת המחדל, ואצל עורך שמשתף — מה שיש לו
    if ($mine !== null && array_diff($perms, $mine)) throw new ApiError('אי אפשר לתת מתג שאין לך: ' . implode(', ', array_map(fn($k) => SHARE_PERMS[$k], array_diff($perms, $mine))), 403);
    return ['edit', json_encode($perms)];
}

/** תיאור קצר של שיתוף תבנית (למייל ולהזמנה). */
function grant_name(string $type, string $level, ?string $perms): string
{
    if ($type !== 'template') return LEVEL_NAMES[$level];
    return $level === 'co_owner' ? 'מנהל' : 'עורך' . ($perms !== null ? ' (' . implode(', ', array_map(fn($k) => SHARE_PERMS[$k], clean_perms($perms))) . ')' : '');
}

function share_list(array $user, array $q): array
{
    [$type, $row] = share_target($user, $q);
    $st = db()->prepare(
        "SELECT s.user_id, s.level, s.perms_json, s.created_at, u.display_name, u.email, u.status
         FROM shares s JOIN users u ON u.id = s.user_id
         WHERE s.object_type = ? AND s.object_id = ? AND u.status != 'deleted' ORDER BY s.created_at"
    );
    $st->execute([$type, $row['id']]);
    $shares = array_map(fn($s) => ['user_id' => (int)$s['user_id'], 'name' => $s['display_name'], 'email' => $s['email'],
        'level' => $s['level'], 'perms' => $type === 'template' && $s['level'] === 'edit' ? ($s['perms_json'] === null ? array_keys(SHARE_PERMS) : clean_perms($s['perms_json'])) : null,
        'since' => $s['created_at'], 'pending' => $s['status'] === 'pending', 'blocked' => $s['status'] === 'blocked'], $st->fetchAll());
    // הזמנות בוואטסאפ שעוד לא נוצלו — מופיעות ברשימה כ"ממתין", עם ביטול ושליחה חוזרת
    $st = db()->prepare('SELECT * FROM share_invites WHERE object_type = ? AND object_id = ? AND used_at IS NULL
        AND revoked_at IS NULL AND expires_at > ? ORDER BY created_at');
    $st->execute([$type, $row['id'], now_iso()]);
    $invites = array_map(fn($i) => ['id' => (int)$i['id'], 'name' => (string)unseal($i['name_enc']),
        'phone' => (string)unseal($i['phone_enc']), 'level' => $i['level'], 'perms' => $i['perms_json'] === null ? null : clean_perms($i['perms_json']),
        'since' => $i['created_at']], $st->fetchAll());
    $approver = $type === 'document' && function_exists('doc_approver') ? doc_approver($row) : null;
    return ['ok' => true, 'owner' => user_name((int)$row['owner_id']), 'my_level' => $row['access'],
        'my_perms' => $type === 'template' ? tpl_my_perms($row, $user) : null, 'perm_names' => $type === 'template' ? SHARE_PERMS : null,
        'shares' => $shares, 'invites' => $invites, 'approver' => $approver];
}

/**
 * שיתוף לפי מייל של משתמש פעיל. אותה פעולה גם משנה רמה של שיתוף קיים.
 * מנהל-שותף לא נותן רמה גבוהה משלו, ולא נוגע בבעלים.
 */
function share_set(array $user, array $b): array
{
    [$type, $row] = share_target($user, $b);
    $perms = null;
    if ($type === 'template') {
        [$level, $perms] = share_grant($user, $row, $b);
    } else {
        $level = (string)($b['level'] ?? '');
        if (!isset(LEVELS[$level]) || $level === 'owner') throw new ApiError('רמת שיתוף לא תקינה', 422);
        if (LEVELS[$level] > LEVELS[$row['access']]) throw new ApiError('אי אפשר לתת הרשאה גבוהה משלך', 403);
    }

    if (isset($b['user_id'])) {
        $st = db()->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
        $st->execute([(int)$b['user_id']]);
    } else {
        $st = db()->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
        $st->execute([valid_email($b['email'] ?? '')]);
    }
    $target = $st->fetch();
    if (!$target) throw new ApiError('אין משתמש פעיל עם הכתובת הזו. הוא צריך להירשם ולקבל אישור קודם', 404);
    if ((int)$target['id'] === (int)$row['owner_id']) throw new ApiError('זה הבעלים', 409);
    if ((int)$target['id'] === (int)$user['id']) throw new ApiError('אי אפשר לשתף עם עצמך', 409);

    $st = db()->prepare('SELECT level FROM shares WHERE object_type = ? AND object_id = ? AND user_id = ?');
    $st->execute([$type, $row['id'], $target['id']]);
    $existing = $st->fetchColumn();
    // מסמך: מנהל-שותף לא משנה הרשאה של מנהל-שותף אחר — רק הבעלים. תבנית (15.4א): מנהל משנה גם מנהל; עורך — לא
    if ($existing === 'co_owner' && $row['access'] !== 'owner' && ($type === 'document' || $row['access'] !== 'co_owner')) {
        throw new ApiError($type === 'template' ? 'רק בעלים או מנהל משנים הרשאה של מנהל' : 'רק הבעלים משנה הרשאה של מנהל-שותף', 403);
    }
    share_guard_approver($type, $row, (int)$target['id']);
    db()->prepare(
        'INSERT INTO shares (object_type, object_id, user_id, level, perms_json, granted_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(object_type, object_id, user_id) DO UPDATE SET level = excluded.level, perms_json = excluded.perms_json, granted_by = excluded.granted_by'
    )->execute([$type, $row['id'], $target['id'], $level, $perms, $user['id'], now_iso()]);

    $title = (string)unseal($type === 'template' ? $row['name_enc'] : $row['title_enc']);
    if ($type === 'document') {
        log_event((int)$row['id'], 'user:' . $user['id'], 'shared', ['with' => $target['display_name'], 'level' => LEVEL_NAMES[$level]]);
    }
    if (!$existing) {
        send_mail($target['email'], ($user['display_name'] ?? '') . ' שיתף/ה איתך ' . ($type === 'template' ? 'תבנית' : 'מסמך'),
            "שלום {$target['display_name']},\n\n" . ($user['display_name'] ?? '') . ' שיתף/ה איתך את ' .
            ($type === 'template' ? 'התבנית' : 'המסמך') . " \"$title\" — הרשאה: " . grant_name($type, $level, $perms) . ".\n\n" .
            app_base_url() . ($type === 'template' ? '#tpl=' : '#doc=') . $row['id']);
    }
    return ['ok' => true];
}

/**
 * מסמך שננעל רק באישור של בעל התבנית: הגישה של המאשר היא שיתוף "מנהל-שותף" על מסמך של המנפיק.
 * אסור לנתק אותו או לשנות לו הרשאה (וגם הוא לא עוזב) — אחרת המסמך נתקע בלי מי שיאשר.
 */
function share_guard_approver(string $type, array $row, int $uid): void
{
    if ($type !== 'document' || !function_exists('doc_approver')) return;
    if (doc_approver($row) === $uid) throw new ApiError('זה המאשר של המסמך — אי אפשר לנתק אותו או לשנות לו הרשאה', 403);
}

function share_remove(array $user, array $b): array
{
    [$type, $row] = share_target($user, $b);
    $uid = (int)($b['user_id'] ?? 0);
    $st = db()->prepare('SELECT level FROM shares WHERE object_type = ? AND object_id = ? AND user_id = ?');
    $st->execute([$type, $row['id'], $uid]);
    $lvl = $st->fetchColumn();
    if (!$lvl) throw new ApiError('השיתוף לא נמצא', 404);
    // מסמך: מנהל-שותף לא מסיר מנהל-שותף אחר — רק הבעלים. תבנית (15.4א): מנהל מסיר גם מנהל (לא את היוצר — הוא לא שיתוף).
    // עורך שמשתף הלאה — מסיר רק עורכים. לעצמו — מותר (עזיבה).
    if ($lvl === 'co_owner' && $row['access'] !== 'owner' && $uid !== (int)$user['id'] && ($type === 'document' || $row['access'] !== 'co_owner')) {
        throw new ApiError($type === 'template' ? 'רק בעלים או מנהל מסירים מנהל' : 'רק הבעלים מסיר מנהל-שותף', 403);
    }
    share_guard_approver($type, $row, $uid);
    db()->prepare('DELETE FROM shares WHERE object_type = ? AND object_id = ? AND user_id = ?')->execute([$type, $row['id'], $uid]);
    if ($type === 'document') log_event((int)$row['id'], 'user:' . $user['id'], 'unshared', ['with' => user_name($uid)]);
    return ['ok' => true];
}

/** עזיבה: משתמש מסיר את עצמו מדבר ששותף איתו. */
function share_leave(array $user, array $b): array
{
    $type = ($b['type'] ?? '') === 'document' ? 'document' : 'template';
    if ($type === 'document') {
        try { $d = doc_owned((int)($b['id'] ?? 0), $user, 'view'); } catch (ApiError $e) { $d = null; }
        if ($d) share_guard_approver($type, $d, (int)$user['id']);
    }
    $st = db()->prepare('DELETE FROM shares WHERE object_type = ? AND object_id = ? AND user_id = ?');
    $st->execute([$type, (int)($b['id'] ?? 0), $user['id']]);
    if (!$st->rowCount()) throw new ApiError('השיתוף לא נמצא', 404);
    if ($type === 'document') log_event((int)$b['id'], 'user:' . $user['id'], 'unshared', ['with' => $user['display_name'] ?? '']);
    return ['ok' => true];
}

// ── שיתוף בטלפון: הזמנה בקישור, נשלחת בוואטסאפ ──────────────────
//
// אין חשבון למספר טלפון — לכן הטלפון מקבל קישור הזמנה חד-פעמי. מי שפותח אותו
// נכנס (או נרשם), והשיתוף נוצר לחשבון שלו. הקישור נשרף בשימוש הראשון, פג אחרי
// INVITE_DAYS, ואפשר לבטל אותו. נשמר רק ה-hash של האסימון.

const INVITE_DAYS = 14;

function invite_phone(string $phone): string
{
    $p = trim($phone);
    if (strlen(preg_replace('/\D+/', '', $p)) < 9) throw new ApiError('מספר טלפון לא תקין', 422);
    return mb_substr($p, 0, 30);
}

/** אסימון חדש להזמנה, והטקסט + קישור הוואטסאפ לשליחה. */
function invite_message(array $user, string $type, array $row, array $inv, string $token): array
{
    $title = (string)unseal($type === 'template' ? $row['name_enc'] : $row['title_enc']);
    $link = app_base_url() . '#invite=' . $token;
    $name = (string)unseal($inv['name_enc']);
    $from = user_name((int)($inv['created_by'] ?? $user['id'])) ?: ($user['display_name'] ?? '');   // מי שהזמין — גם בשליחה חוזרת
    $text = ($name !== '' ? "שלום $name,\n" : '') . $from . ' שיתף/ה איתך את ' .
        ($type === 'template' ? 'התבנית' : 'המסמך') . " \"$title\" (" . grant_name($type, $inv['level'], $inv['perms_json'] ?? null) . ").\n" .
        "לפתיחה — נכנסים או נרשמים דרך הקישור:\n$link";
    $phone = (string)unseal($inv['phone_enc']);
    return ['link' => $link, 'text' => $text,
        'whatsapp' => 'https://wa.me/' . ($phone !== '' ? wa_phone($phone) : '') . '?text=' . rawurlencode($text)];
}

function share_invite(array $user, array $b): array
{
    [$type, $row] = share_target($user, $b);
    $perms = null;
    if ($type === 'template') {
        [$level, $perms] = share_grant($user, $row, $b);
    } else {
        $level = (string)($b['level'] ?? '');
        if (!isset(LEVELS[$level]) || $level === 'owner') throw new ApiError('רמת שיתוף לא תקינה', 422);
        if (LEVELS[$level] > LEVELS[$row['access']]) throw new ApiError('אי אפשר לתת הרשאה גבוהה משלך', 403);
    }
    $phone = invite_phone((string)($b['phone'] ?? ''));
    $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 80);

    $token = bin2hex(random_bytes(24));
    $inv = ['level' => $level, 'perms_json' => $perms, 'name_enc' => seal($name), 'phone_enc' => seal($phone)];
    db()->prepare('INSERT INTO share_invites (object_type, object_id, level, perms_json, token_hash, name_enc, phone_enc, created_by, created_at, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$type, $row['id'], $level, $perms, token_hash($token), $inv['name_enc'], $inv['phone_enc'], $user['id'], now_iso(), iso_in(INVITE_DAYS * 86400)]);
    if ($type === 'document') {
        log_event((int)$row['id'], 'user:' . $user['id'], 'shared', ['with' => $name !== '' ? $name : $phone, 'level' => LEVEL_NAMES[$level], 'via' => 'וואטסאפ']);
    }
    return ['ok' => true] + invite_message($user, $type, $row, $inv, $token);
}

function share_invite_get(string $type, int $objectId, int $inviteId): array
{
    $st = db()->prepare('SELECT * FROM share_invites WHERE id = ? AND object_type = ? AND object_id = ? AND used_at IS NULL AND revoked_at IS NULL');
    $st->execute([$inviteId, $type, $objectId]);
    $inv = $st->fetch();
    if (!$inv) throw new ApiError('ההזמנה לא נמצאה, או שכבר נוצלה', 404);
    return $inv;
}

/** שליחה חוזרת: אסימון חדש (הישן נפסל), ותוקף מתחדש. */
function share_invite_resend(array $user, array $b): array
{
    [$type, $row] = share_target($user, $b);
    $inv = share_invite_get($type, (int)$row['id'], (int)($b['invite_id'] ?? 0));
    $token = bin2hex(random_bytes(24));
    db()->prepare('UPDATE share_invites SET token_hash = ?, expires_at = ? WHERE id = ?')
        ->execute([token_hash($token), iso_in(INVITE_DAYS * 86400), $inv['id']]);
    return ['ok' => true] + invite_message($user, $type, $row, $inv, $token);
}

function share_invite_cancel(array $user, array $b): array
{
    [$type, $row] = share_target($user, $b);
    $inv = share_invite_get($type, (int)$row['id'], (int)($b['invite_id'] ?? 0));
    db()->prepare('UPDATE share_invites SET revoked_at = ? WHERE id = ?')->execute([now_iso(), $inv['id']]);
    return ['ok' => true];
}

function invite_row(string $token): ?array
{
    if ($token === '') return null;
    $st = db()->prepare('SELECT * FROM share_invites WHERE token_hash = ?');
    $st->execute([token_hash($token)]);
    return $st->fetch() ?: null;
}

function invite_target(array $inv): ?array
{
    $t = $inv['object_type'] === 'template' ? 'templates' : 'documents';
    $st = db()->prepare("SELECT * FROM $t WHERE id = ?");
    $st->execute([$inv['object_id']]);
    return $st->fetch() ?: null;
}

/**
 * ההזמנה עדיין תקפה: הדבר קיים, ומי שיצר אותה עדיין מנהל אותו ברמה שהוא נתן. מי שנותק או הורד בדרגה —
 * ההזמנות שלו נופלות איתו; ותבנית שנמחקה ומספרה חזר לתבנית אחרת — לא שלו, ולכן לא תקפה.
 */
function invite_alive(array $inv, ?array $obj): bool
{
    if (!$obj || !empty($obj['deleted_at']) || $inv['revoked_at']) return false;
    $by = ['id' => (int)$inv['created_by']];
    $lvl = access_level($inv['object_type'], (int)$obj['id'], (int)$obj['owner_id'], $by);
    if ($lvl === null) return false;
    if ($inv['object_type'] === 'template' && !in_array($lvl, ['owner', 'co_owner'], true)) {
        // עורך שמשתף הלאה: עדיין יש לו "שיתוף", ועדיין יש לו כל מתג שנתן
        $t = ['id' => (int)$obj['id'], 'access' => $lvl];
        $mine = tpl_my_perms($t, $by) ?? [];
        return $inv['level'] === 'edit' && in_array('share', $mine, true) && !array_diff(clean_perms($inv['perms_json'] ?? '[]'), $mine);
    }
    return LEVELS[$lvl] >= LEVELS['co_owner'] && LEVELS[$lvl] >= LEVELS[$inv['level']];
}

/** בלי כניסה: מה ההזמנה (למסך שמזמין להתחבר). מי שמחזיק את הקישור הוא המוזמן. */
function invite_info(string $token): array
{
    $inv = invite_row($token);
    $obj = $inv ? invite_target($inv) : null;
    if (!$inv || !invite_alive($inv, $obj) || ($inv['used_at'] === null && $inv['expires_at'] <= now_iso())) {
        throw new ApiError('ההזמנה אינה בתוקף. בקשו מהשולח הזמנה חדשה', 410);
    }
    $type = $inv['object_type'];
    return ['ok' => true, 'type' => $type, 'from' => user_name((int)$inv['created_by']),
        'title' => (string)unseal($type === 'template' ? $obj['name_enc'] : $obj['title_enc']),
        'level' => $inv['level'], 'level_name' => grant_name($type, $inv['level'], $inv['perms_json'] ?? null), 'used' => $inv['used_at'] !== null];
}

/**
 * ההזמנה הופכת לשיתוף של החשבון. חד-פעמית: מי שכבר ניצל אותה מקבל שוב את
 * היעד (כניסה שנייה מאותו קישור), ואחר — "כבר נוצלה".
 */
function invite_redeem(int $userId, string $token): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $inv = invite_row($token);
        $obj = $inv ? invite_target($inv) : null;
        if (!$inv || !invite_alive($inv, $obj)) throw new ApiError('ההזמנה אינה בתוקף. בקשו מהשולח הזמנה חדשה', 410);
        $type = $inv['object_type'];
        if ($inv['used_at'] !== null) {
            if ((int)$inv['used_by'] !== $userId) throw new ApiError('ההזמנה כבר נוצלה בחשבון אחר. בקשו מהשולח הזמנה חדשה', 409);
        } else {
            if ($inv['expires_at'] <= now_iso()) throw new ApiError('ההזמנה פגה. בקשו מהשולח הזמנה חדשה', 410);
            if ((int)$obj['owner_id'] === $userId) {
                // הבעלים פתח את ההזמנה שלו (למשל כדי לבדוק אותה) — לא שורפים אותה למוזמן
                $pdo->commit();
                return ['ok' => true, 'type' => $type, 'id' => (int)$obj['id'], 'inviter' => (int)$inv['created_by']];
            }
            {
                // שיתוף קיים ברמה גבוהה יותר — לא מורידים אותו
                $st = $pdo->prepare('SELECT level FROM shares WHERE object_type = ? AND object_id = ? AND user_id = ?');
                $st->execute([$type, $obj['id'], $userId]);
                $cur = $st->fetchColumn();
                if (!$cur || LEVELS[$cur] < LEVELS[$inv['level']]) {
                    $pdo->prepare('INSERT INTO shares (object_type, object_id, user_id, level, perms_json, granted_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON CONFLICT(object_type, object_id, user_id) DO UPDATE SET level = excluded.level, perms_json = excluded.perms_json, granted_by = excluded.granted_by')
                        ->execute([$type, $obj['id'], $userId, $inv['level'], $inv['perms_json'] ?? null, $inv['created_by'], now_iso()]);
                } elseif ($type === 'template' && $cur === 'edit' && $inv['level'] === 'edit') {
                    // עורך שכבר שותף — מקבל גם את המתגים מההזמנה (איחוד, לא הורדה)
                    $st = $pdo->prepare('SELECT perms_json FROM shares WHERE object_type = ? AND object_id = ? AND user_id = ?');
                    $st->execute([$type, $obj['id'], $userId]);
                    $had = $st->fetchColumn();
                    $merged = $had === null ? null : json_encode(array_values(array_unique(array_merge(clean_perms($had), clean_perms($inv['perms_json'] ?? '[]')))));
                    $pdo->prepare('UPDATE shares SET perms_json = ? WHERE object_type = ? AND object_id = ? AND user_id = ?')->execute([$merged, $type, $obj['id'], $userId]);
                }
            }
            $pdo->prepare('UPDATE share_invites SET used_by = ?, used_at = ? WHERE id = ?')->execute([$userId, now_iso(), $inv['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'type' => $type, 'id' => (int)$obj['id'], 'inviter' => (int)$inv['created_by']];
}

function invite_accept(array $user, array $b): array
{
    return invite_redeem((int)$user['id'], (string)($b['token'] ?? ''));
}
