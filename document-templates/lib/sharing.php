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
 * שהונפקו ממנה, ולהפך. מסמך שמנפיקים מתבנית משותפת שייך למי שהנפיק.
 *
 * מי שאין לו גישה בכלל מקבל 404 — כמו לפני השיתוף, לא מגלים שהדבר קיים.
 * מי שיש לו גישה, אבל ברמה נמוכה מדי, מקבל 403 עם הסבר.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const LEVELS = ['view' => 1, 'fill' => 2, 'edit' => 3, 'co_owner' => 4, 'owner' => 5];
const LEVEL_NAMES = ['view' => 'צופה', 'fill' => 'ממלא', 'edit' => 'עורך', 'co_owner' => 'מנהל-שותף', 'owner' => 'בעלים'];

/** הרמה של המשתמש בדבר, או null. $ownerId — של הדבר עצמו. */
function access_level(string $type, int $id, int $ownerId, array $user): ?string
{
    if ($ownerId === (int)$user['id']) return 'owner';
    $st = db()->prepare('SELECT level FROM shares WHERE object_type = ? AND object_id = ? AND user_id = ?');
    $st->execute([$type, $id, $user['id']]);
    $l = $st->fetchColumn();
    return $l ?: null;
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

/** תנאי SQL: הדבר שלי, או משותף איתי. */
function mine_or_shared_sql(string $alias, string $type): string
{
    return "($alias.owner_id = :uid OR $alias.id IN (SELECT object_id FROM shares WHERE object_type = '$type' AND user_id = :uid))";
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
    $row = $type === 'template'
        ? tpl_owned((int)($b['id'] ?? 0), $user, 'co_owner')
        : doc_owned((int)($b['id'] ?? 0), $user, 'co_owner');
    return [$type, $row];
}

function share_list(array $user, array $q): array
{
    [$type, $row] = share_target($user, $q);
    $st = db()->prepare(
        'SELECT s.user_id, s.level, s.created_at, u.display_name, u.email
         FROM shares s JOIN users u ON u.id = s.user_id
         WHERE s.object_type = ? AND s.object_id = ? ORDER BY s.created_at'
    );
    $st->execute([$type, $row['id']]);
    return ['ok' => true, 'owner' => user_name((int)$row['owner_id']), 'my_level' => $row['access'],
        'shares' => array_map(fn($s) => ['user_id' => (int)$s['user_id'], 'name' => $s['display_name'], 'email' => $s['email'],
            'level' => $s['level'], 'since' => $s['created_at']], $st->fetchAll())];
}

/**
 * שיתוף לפי מייל של משתמש פעיל. אותה פעולה גם משנה רמה של שיתוף קיים.
 * מנהל-שותף לא נותן רמה גבוהה משלו, ולא נוגע בבעלים.
 */
function share_set(array $user, array $b): array
{
    [$type, $row] = share_target($user, $b);
    $level = (string)($b['level'] ?? '');
    if (!isset(LEVELS[$level]) || $level === 'owner') throw new ApiError('רמת שיתוף לא תקינה', 422);
    if (LEVELS[$level] > LEVELS[$row['access']]) throw new ApiError('אי אפשר לתת הרשאה גבוהה משלך', 403);

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
    db()->prepare(
        'INSERT INTO shares (object_type, object_id, user_id, level, granted_by, created_at) VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT(object_type, object_id, user_id) DO UPDATE SET level = excluded.level, granted_by = excluded.granted_by'
    )->execute([$type, $row['id'], $target['id'], $level, $user['id'], now_iso()]);

    $title = (string)unseal($type === 'template' ? $row['name_enc'] : $row['title_enc']);
    if ($type === 'document') {
        log_event((int)$row['id'], 'user:' . $user['id'], 'shared', ['with' => $target['display_name'], 'level' => LEVEL_NAMES[$level]]);
    }
    if (!$existing) {
        send_mail($target['email'], ($user['display_name'] ?? '') . ' שיתף/ה איתך ' . ($type === 'template' ? 'תבנית' : 'מסמך'),
            "שלום {$target['display_name']},\n\n" . ($user['display_name'] ?? '') . ' שיתף/ה איתך את ' .
            ($type === 'template' ? 'התבנית' : 'המסמך') . " \"$title\" — הרשאה: " . LEVEL_NAMES[$level] . ".\n\n" .
            app_base_url() . ($type === 'template' ? '#tpl=' : '#doc=') . $row['id']);
    }
    return ['ok' => true];
}

function share_remove(array $user, array $b): array
{
    [$type, $row] = share_target($user, $b);
    $uid = (int)($b['user_id'] ?? 0);
    $st = db()->prepare('SELECT level FROM shares WHERE object_type = ? AND object_id = ? AND user_id = ?');
    $st->execute([$type, $row['id'], $uid]);
    $lvl = $st->fetchColumn();
    if (!$lvl) throw new ApiError('השיתוף לא נמצא', 404);
    // מנהל-שותף לא מסיר מנהל-שותף אחר — רק הבעלים. לעצמו — מותר (עזיבה).
    if ($lvl === 'co_owner' && $row['access'] !== 'owner' && $uid !== (int)$user['id']) {
        throw new ApiError('רק הבעלים מסיר מנהל-שותף', 403);
    }
    db()->prepare('DELETE FROM shares WHERE object_type = ? AND object_id = ? AND user_id = ?')->execute([$type, $row['id'], $uid]);
    if ($type === 'document') log_event((int)$row['id'], 'user:' . $user['id'], 'unshared', ['with' => user_name($uid)]);
    return ['ok' => true];
}

/** עזיבה: משתמש מסיר את עצמו מדבר ששותף איתו. */
function share_leave(array $user, array $b): array
{
    $type = ($b['type'] ?? '') === 'document' ? 'document' : 'template';
    $st = db()->prepare('DELETE FROM shares WHERE object_type = ? AND object_id = ? AND user_id = ?');
    $st->execute([$type, (int)($b['id'] ?? 0), $user['id']]);
    if (!$st->rowCount()) throw new ApiError('השיתוף לא נמצא', 404);
    if ($type === 'document') log_event((int)$b['id'], 'user:' . $user['id'], 'unshared', ['with' => $user['display_name'] ?? '']);
    return ['ok' => true];
}
