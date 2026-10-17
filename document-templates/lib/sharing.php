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
    $shares = array_map(fn($s) => ['user_id' => (int)$s['user_id'], 'name' => $s['display_name'], 'email' => $s['email'],
        'level' => $s['level'], 'since' => $s['created_at']], $st->fetchAll());
    // הזמנות בוואטסאפ שעוד לא נוצלו — מופיעות ברשימה כ"ממתין", עם ביטול ושליחה חוזרת
    $st = db()->prepare('SELECT * FROM share_invites WHERE object_type = ? AND object_id = ? AND used_at IS NULL
        AND revoked_at IS NULL AND expires_at > ? ORDER BY created_at');
    $st->execute([$type, $row['id'], now_iso()]);
    $invites = array_map(fn($i) => ['id' => (int)$i['id'], 'name' => (string)unseal($i['name_enc']),
        'phone' => (string)unseal($i['phone_enc']), 'level' => $i['level'], 'since' => $i['created_at']], $st->fetchAll());
    return ['ok' => true, 'owner' => user_name((int)$row['owner_id']), 'my_level' => $row['access'],
        'shares' => $shares, 'invites' => $invites];
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
    $text = ($name !== '' ? "שלום $name,\n" : '') . ($user['display_name'] ?? '') . ' שיתף/ה איתך את ' .
        ($type === 'template' ? 'התבנית' : 'המסמך') . " \"$title\" (" . LEVEL_NAMES[$inv['level']] . ").\n" .
        "לפתיחה — נכנסים או נרשמים דרך הקישור:\n$link";
    $phone = (string)unseal($inv['phone_enc']);
    return ['link' => $link, 'text' => $text,
        'whatsapp' => 'https://wa.me/' . ($phone !== '' ? wa_phone($phone) : '') . '?text=' . rawurlencode($text)];
}

function share_invite(array $user, array $b): array
{
    [$type, $row] = share_target($user, $b);
    $level = (string)($b['level'] ?? '');
    if (!isset(LEVELS[$level]) || $level === 'owner') throw new ApiError('רמת שיתוף לא תקינה', 422);
    if (LEVELS[$level] > LEVELS[$row['access']]) throw new ApiError('אי אפשר לתת הרשאה גבוהה משלך', 403);
    $phone = invite_phone((string)($b['phone'] ?? ''));
    $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 80);

    $token = bin2hex(random_bytes(24));
    $inv = ['level' => $level, 'name_enc' => seal($name), 'phone_enc' => seal($phone)];
    db()->prepare('INSERT INTO share_invites (object_type, object_id, level, token_hash, name_enc, phone_enc, created_by, created_at, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$type, $row['id'], $level, token_hash($token), $inv['name_enc'], $inv['phone_enc'], $user['id'], now_iso(), iso_in(INVITE_DAYS * 86400)]);
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

/** בלי כניסה: מה ההזמנה (למסך שמזמין להתחבר). מי שמחזיק את הקישור הוא המוזמן. */
function invite_info(string $token): array
{
    $inv = invite_row($token);
    $obj = $inv ? invite_target($inv) : null;
    if (!$inv || !$obj || $inv['revoked_at'] || ($inv['used_at'] === null && $inv['expires_at'] <= now_iso())) {
        throw new ApiError('ההזמנה אינה בתוקף. בקשו מהשולח הזמנה חדשה', 410);
    }
    $type = $inv['object_type'];
    return ['ok' => true, 'type' => $type, 'from' => user_name((int)$inv['created_by']),
        'title' => (string)unseal($type === 'template' ? $obj['name_enc'] : $obj['title_enc']),
        'level' => $inv['level'], 'level_name' => LEVEL_NAMES[$inv['level']], 'used' => $inv['used_at'] !== null];
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
        if (!$inv || !$obj || $inv['revoked_at']) throw new ApiError('ההזמנה אינה בתוקף. בקשו מהשולח הזמנה חדשה', 410);
        $type = $inv['object_type'];
        if ($inv['used_at'] !== null) {
            if ((int)$inv['used_by'] !== $userId) throw new ApiError('ההזמנה כבר נוצלה בחשבון אחר. בקשו מהשולח הזמנה חדשה', 409);
        } else {
            if ($inv['expires_at'] <= now_iso()) throw new ApiError('ההזמנה פגה. בקשו מהשולח הזמנה חדשה', 410);
            if ((int)$obj['owner_id'] !== $userId) {
                // שיתוף קיים ברמה גבוהה יותר — לא מורידים אותו
                $st = $pdo->prepare('SELECT level FROM shares WHERE object_type = ? AND object_id = ? AND user_id = ?');
                $st->execute([$type, $obj['id'], $userId]);
                $cur = $st->fetchColumn();
                if (!$cur || LEVELS[$cur] < LEVELS[$inv['level']]) {
                    $pdo->prepare('INSERT INTO shares (object_type, object_id, user_id, level, granted_by, created_at) VALUES (?, ?, ?, ?, ?, ?)
                        ON CONFLICT(object_type, object_id, user_id) DO UPDATE SET level = excluded.level, granted_by = excluded.granted_by')
                        ->execute([$type, $obj['id'], $userId, $inv['level'], $inv['created_by'], now_iso()]);
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
