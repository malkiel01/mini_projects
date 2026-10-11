<?php
/**
 * פרטיות ושליטה (SPEC פרק 15.7) — לפני שהרשת גדלה, וגם דרישה של גוגל פליי.
 *
 *   העדפות   — מי מוצא אותי בחיפוש אנשים; מי יכול לשתף איתי ולהמליץ לי
 *              (כולם / רק מי שאני עוקב אחריו / אף אחד); התאמה אישית (להמלצות, 15.5).
 *   חסימה    — בשני הכיוונים: לא בחיפוש, לא בפרופיל, לא במתכונים ברשימות, לא משתף,
 *              לא ממליץ, לא עוקב, לא מגיב אצלי. מה שהיה בינינו (מעקב, שיתוף, המלצה
 *              ממתינה) — נמחק ברגע החסימה.
 *   דיווח    — על מתכון, תגובה או משתמש → תור אצל המפתח: דחייה, הסתרה (פרטי),
 *              מחיקה, חסימת המשתמש באתר.
 *   מחיקת חשבון — הכול: מתכונים, מדיה בדיסק, סריקות, תגובות, שיתופים, מעקבים.
 *              ביומנים (שנשמרים 30–60 יום לאבטחה) — שם המשתמש נמחק.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/log.php';

const PREF_SHARE_FROM = ['all', 'following', 'none'];
const REPORT_REASONS  = ['spam' => 'ספאם / פרסומת', 'offensive' => 'פוגעני', 'copyright' => 'הפרת זכויות יוצרים', 'unsafe' => 'מסוכן / לא בטוח', 'other' => 'אחר'];
const REPORT_DAILY_MAX = 20;

// ─────────────────────────────────────────────────────────────
// העדפות
// ─────────────────────────────────────────────────────────────

function userPrefs(int $userId): array {
    $st = db()->prepare('SELECT discoverable, share_from, personalize FROM user_prefs WHERE user_id = ?');
    $st->execute([$userId]);
    $r = $st->fetch() ?: [];
    $st->closeCursor();
    return ['discoverable' => (bool) ($r['discoverable'] ?? true), 'share_from' => $r['share_from'] ?? 'all',
            'personalize' => (bool) ($r['personalize'] ?? true)];
}

function setUserPrefs(array $in, array $user): array {
    $p = userPrefs((int) $user['id']);
    if (array_key_exists('discoverable', $in)) $p['discoverable'] = (bool) $in['discoverable'];
    if (array_key_exists('personalize', $in)) $p['personalize'] = (bool) $in['personalize'];
    if (array_key_exists('share_from', $in)) {
        if (!in_array($in['share_from'], PREF_SHARE_FROM, true)) throw new AppError('ערך לא מוכר');
        $p['share_from'] = $in['share_from'];
    }
    db()->prepare('INSERT INTO user_prefs (user_id, discoverable, share_from, personalize, updated_at) VALUES (?,?,?,?,?)
                   ON CONFLICT(user_id) DO UPDATE SET discoverable = excluded.discoverable, share_from = excluded.share_from,
                                                     personalize = excluded.personalize, updated_at = excluded.updated_at')
        ->execute([(int) $user['id'], $p['discoverable'] ? 1 : 0, $p['share_from'], $p['personalize'] ? 1 : 0, nowIso()]);
    // כיבוי ההתאמה האישית מוחק את מה שנאסף: יומן השימוש והטעם מהניתוח היומי
    if (!$p['personalize']) {
        db()->prepare('DELETE FROM user_events WHERE user_id = ?')->execute([(int) $user['id']]);
        db()->prepare('DELETE FROM ai_taste WHERE user_id = ?')->execute([(int) $user['id']]);
    }
    return $p;
}

// ─────────────────────────────────────────────────────────────
// חסימה
// ─────────────────────────────────────────────────────────────

/** יש חסימה בין השניים — בכל כיוון. */
function blockedBetween(int $a, int $b): bool {
    $st = db()->prepare('SELECT 1 FROM blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)');
    $st->execute([$a, $b, $b, $a]);
    $x = (bool) $st->fetchColumn();
    $st->closeCursor();
    return $x;
}

/** תנאי SQL: העמודה $col אינה משתמש שבחסימה עם $uid (בכל כיוון). שני פרמטרים: $uid, $uid. */
function sqlNotBlocked(string $col): string {
    return "$col NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = ? UNION SELECT blocker_id FROM blocks WHERE blocked_id = ?)";
}

/**
 * $from רשאי לשתף עם $toId / להמליץ לו? לא — אם יש חסימה, או ש-$toId הגביל
 * ("רק מי שאני עוקב אחריו" / "אף אחד"). ההודעה אחידה: לא מגלה מה בדיוק.
 */
/** האם $fromId יכול לשתף ולהמליץ ל-$toId: לא חסום, ולפי "מי משתף איתי" של הנמען. */
function canReach(int $fromId, int $toId): bool {
    if (blockedBetween($fromId, $toId)) return false;
    $mode = userPrefs($toId)['share_from'];
    if ($mode === 'none') return false;
    if ($mode === 'following') {
        $st = db()->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND followee_id = ?');
        $st->execute([$toId, $fromId]);
        $ok = (bool) $st->fetchColumn();
        $st->closeCursor();
        return $ok;
    }
    return true;
}

function requireReachable(array $from, int $toId): void {
    if (!canReach((int) $from['id'], $toId)) throw new AppError('המשתמש הזה לא מקבל שיתופים והמלצות ממך', 403);
}

function blockUser(int $targetId, array $user): void {
    $uid = (int) $user['id'];
    if ($targetId === $uid) throw new AppError('אי אפשר לחסום את עצמך');
    $st = db()->prepare('SELECT 1 FROM users WHERE id = ?');
    $st->execute([$targetId]);
    if (!$st->fetchColumn()) throw new AppError('המשתמש אינו קיים', 404);
    $st->closeCursor();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT OR IGNORE INTO blocks (blocker_id, blocked_id, created_at) VALUES (?,?,?)')->execute([$uid, $targetId, nowIso()]);
        // מה שהיה בינינו — נמחק, בשני הכיוונים
        $pdo->prepare('DELETE FROM follows WHERE (follower_id = ? AND followee_id = ?) OR (follower_id = ? AND followee_id = ?)')->execute([$uid, $targetId, $targetId, $uid]);
        $pdo->prepare('DELETE FROM recipe_shares WHERE (user_id = ? AND recipe_id IN (SELECT id FROM recipes WHERE owner_id = ?))
                                                  OR (user_id = ? AND recipe_id IN (SELECT id FROM recipes WHERE owner_id = ?))')->execute([$targetId, $uid, $uid, $targetId]);
        $pdo->prepare('DELETE FROM recommendations WHERE (from_user = ? AND to_user = ?) OR (from_user = ? AND to_user = ?)')->execute([$uid, $targetId, $targetId, $uid]);
        // ומהמעגלים — אחרת הסנכרון של המעגל היה מחזיר את השיתוף (lib/circles.php)
        $pdo->prepare('DELETE FROM circle_members WHERE (user_id = ? AND circle_id IN (SELECT id FROM circles WHERE owner_id = ?))
                                                   OR (user_id = ? AND circle_id IN (SELECT id FROM circles WHERE owner_id = ?))')->execute([$targetId, $uid, $uid, $targetId]);
        $pdo->prepare('DELETE FROM collection_shares WHERE (user_id = ? AND collection_id IN (SELECT id FROM collections WHERE owner_id = ?))
                                                      OR (user_id = ? AND collection_id IN (SELECT id FROM collections WHERE owner_id = ?))')->execute([$targetId, $uid, $uid, $targetId]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    logEvent('info', 'user-block-self', '', ['target' => $targetId], $user);
}

function unblockUser(int $targetId, array $user): void {
    $uid = (int) $user['id'];
    db()->prepare('DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?')->execute([$uid, $targetId]);
    // קבוצה משותפת (lib/communities.php): מה ששני הצדדים הוסיפו לה — שוב משותף ביניהם
    $st = db()->prepare("SELECT DISTINCT cr.recipe_id FROM community_recipes cr
                           JOIN community_members a ON a.community_id = cr.community_id AND a.user_id = ? AND a.status = 'active'
                           JOIN community_members b ON b.community_id = cr.community_id AND b.user_id = ? AND b.status = 'active'
                          WHERE cr.added_by IN (?, ?)");
    $st->execute([$uid, $targetId, $uid, $targetId]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) { require_once __DIR__ . '/circles.php'; foreach ($ids as $rid) circleSync('recipe', (int) $rid); }
}

/** מי שחסמתי. */
function myBlocks(array $user): array {
    $st = db()->prepare('SELECT u.id, u.username, u.display_name, b.created_at FROM blocks b JOIN users u ON u.id = b.blocked_id WHERE b.blocker_id = ? ORDER BY b.created_at DESC');
    $st->execute([(int) $user['id']]);
    return array_map(fn($r) => ['id' => (int) $r['id'], 'username' => $r['username'], 'display_name' => $r['display_name'] ?: $r['username'], 'blocked_at' => $r['created_at']], $st->fetchAll());
}

// ─────────────────────────────────────────────────────────────
// דיווח
// ─────────────────────────────────────────────────────────────

/** מה מדווחים עליו — לבדיקה שקיים, ולתיעוד כפי שהיה ברגע הדיווח (גם אם יימחק או ישתנה). */
function reportTarget(string $kind, int $id, array $user): array {
    $q = function (string $sql) use ($id) { $st = db()->prepare($sql); $st->execute([$id]); $r = $st->fetch(); $st->closeCursor(); return $r; };
    if ($kind === 'recipe') {
        $r = $q('SELECT * FROM recipes WHERE id = ?');
        if (!$r || !canView($r, $user)) throw new AppError('המתכון אינו קיים', 404);
        return ['owner' => (int) $r['owner_id'], 'snapshot' => $r['title']];
    }
    if ($kind === 'comment') {
        $r = $q("SELECT c.user_id, c.text, c.recipe_id FROM comments c WHERE c.id = ? AND c.visibility = 'public'");
        if (!$r) throw new AppError('התגובה אינה קיימת', 404);
        return ['owner' => (int) $r['user_id'], 'snapshot' => mb_substr($r['text'], 0, 500), 'recipe_id' => (int) $r['recipe_id']];
    }
    if ($kind === 'user') {
        $r = $q('SELECT id, username, display_name FROM users WHERE id = ?');
        if (!$r) throw new AppError('המשתמש אינו קיים', 404);
        return ['owner' => (int) $r['id'], 'snapshot' => ($r['display_name'] ?: $r['username']) . ' (@' . $r['username'] . ')'];
    }
    throw new AppError('על מה הדיווח?');
}

function reportContent(string $kind, int $id, string $reason, string $note, array $user): void {
    if (!isset(REPORT_REASONS[$reason])) throw new AppError('בחר סיבה');
    $t = reportTarget($kind, $id, $user);
    if ($t['owner'] === (int) $user['id']) throw new AppError('אי אפשר לדווח על תוכן שלך');
    $st = db()->prepare('SELECT COUNT(*) FROM reports WHERE reporter_id = ? AND created_at > ?');
    $st->execute([(int) $user['id'], gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]);
    if ((int) $st->fetchColumn() >= REPORT_DAILY_MAX) throw new AppError('הגעת למספר הדיווחים ליום. תודה — אפשר שוב מחר', 429);
    $st = db()->prepare("SELECT 1 FROM reports WHERE reporter_id = ? AND kind = ? AND target_id = ? AND status = 'open'");
    $st->execute([(int) $user['id'], $kind, $id]);
    if ($st->fetchColumn()) return;   // כבר דווח וממתין — לא שוב
    db()->prepare('INSERT INTO reports (reporter_id, kind, target_id, target_owner, reason, note, snapshot, created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([(int) $user['id'], $kind, $id, $t['owner'], $reason, mb_substr(trim($note), 0, 500) ?: null, $t['snapshot'], nowIso()]);
    logEvent('warn', 'report', REPORT_REASONS[$reason] . ': ' . mb_substr($t['snapshot'], 0, 80), ['kind' => $kind, 'target' => $id], $user);
}

function reportsList(array $developer, string $status = 'open'): array {
    requireDeveloper($developer);
    $open = $status === 'open';
    $rows = db()->query("SELECT r.*, ru.username AS reporter, ou.username AS owner_username, ou.display_name AS owner_name, ou.blocked AS owner_blocked
                           FROM reports r LEFT JOIN users ru ON ru.id = r.reporter_id LEFT JOIN users ou ON ou.id = r.target_owner
                          WHERE r.status " . ($open ? "= 'open' ORDER BY r.id DESC LIMIT 200" : "!= 'open' ORDER BY r.resolved_at DESC LIMIT 100"))->fetchAll();
    return array_map(fn($r) => [
        'id' => (int) $r['id'], 'kind' => $r['kind'], 'target_id' => (int) $r['target_id'], 'reason' => $r['reason'],
        'reason_label' => REPORT_REASONS[$r['reason']] ?? $r['reason'], 'note' => $r['note'], 'snapshot' => $r['snapshot'],
        'reporter' => $r['reporter'], 'owner' => ['id' => $r['target_owner'] !== null ? (int) $r['target_owner'] : null, 'username' => $r['owner_username'],
        'display_name' => $r['owner_name'], 'blocked' => (bool) $r['owner_blocked']],
        'status' => $r['status'], 'resolution' => $r['resolution'], 'created_at' => $r['created_at'], 'resolved_at' => $r['resolved_at'],
    ], $rows);
}

/**
 * טיפול בדיווח. $action: dismiss | hide (מתכון → פרטי) | delete (מתכון/תגובה) | block (חסימת הבעלים באתר).
 * כל הדיווחים הפתוחים על אותו יעד נסגרים יחד.
 */
function reportResolve(int $id, string $action, array $developer): array {
    requireDeveloper($developer);
    $st = db()->prepare('SELECT * FROM reports WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    $st->closeCursor();
    if (!$r) throw new AppError('הדיווח אינו קיים', 404);
    $kind = $r['kind'];
    $target = (int) $r['target_id'];
    switch ($action) {
        case 'dismiss': $res = 'נדחה — אין הפרה'; break;
        case 'hide':
            if ($kind !== 'recipe') throw new AppError('הסתרה — רק למתכון');
            db()->prepare("UPDATE recipes SET visibility = 'private' WHERE id = ?")->execute([$target]);
            $res = 'המתכון הוסתר (פרטי)';
            break;
        case 'delete':
            if ($kind === 'recipe') { db()->prepare('DELETE FROM recipes WHERE id = ?')->execute([$target]); $res = 'המתכון נמחק'; }
            elseif ($kind === 'comment') { db()->prepare('DELETE FROM comments WHERE id = ?')->execute([$target]); $res = 'התגובה נמחקה'; }
            else throw new AppError('משתמש — חוסמים, לא מוחקים');
            break;
        case 'block':
            if ($r['target_owner'] === null) throw new AppError('אין משתמש לחסום');
            setUserBlocked((int) $r['target_owner'], true, $developer);
            $res = 'המשתמש נחסם באתר';
            break;
        default: throw new AppError('פעולה לא מוכרת');
    }
    db()->prepare("UPDATE reports SET status = ?, resolution = ?, resolved_at = ?, resolved_by = ? WHERE kind = ? AND target_id = ? AND status = 'open'")
        ->execute([$action === 'dismiss' ? 'dismissed' : 'handled', $res, nowIso(), (int) $developer['id'], $kind, $target]);
    logEvent('info', 'report-resolve', $res, ['report' => $id, 'kind' => $kind, 'target' => $target], $developer);
    return reportsList($developer);
}

function reportsOpenCount(): int {
    return (int) db()->query("SELECT COUNT(*) FROM reports WHERE status = 'open'")->fetchColumn();
}

// ─────────────────────────────────────────────────────────────
// מחיקת חשבון
// ─────────────────────────────────────────────────────────────

function deleteAccount(array $user, string $password): void {
    $uid = (int) $user['id'];
    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$uid]);
    $hash = (string) $st->fetchColumn();
    $st->closeCursor();
    if ($hash === '' || !password_verify($password, $hash)) throw new AppError('הסיסמה שגויה', 403);

    // קבצים בדיסק — לפני שהשורות שמצביעות עליהם נמחקות
    $files = [];
    $st = db()->prepare("SELECT path_or_url FROM media WHERE source = 'upload' AND (uploader_id = ? OR recipe_id IN (SELECT id FROM recipes WHERE owner_id = ?))");
    $st->execute([$uid, $uid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) $files[] = MEDIA_DIR . '/' . basename((string) $p);
    $st = db()->prepare("SELECT images FROM scans WHERE user_id = ? AND status = 'pending'");
    $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $j) foreach (json_decode((string) $j, true) ?: [] as $n) $files[] = dirname(DB_FILE) . '/scans/' . basename((string) $n);

    // קהילות שהוא יצר נמחקות איתו — מתכונים שאחרים הוסיפו להן יפסיקו להיות משותפים דרכן (אחרי המחיקה)
    $st = db()->prepare('SELECT cr.recipe_id FROM community_recipes cr JOIN communities c ON c.id = cr.community_id WHERE c.owner_id = ? AND cr.added_by != ?');
    $st->execute([$uid, $uid]);
    $resync = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // יומנים: נשמרים לאבטחה (בלי FK בכוונה), אבל בלי שם המשתמש
        $pdo->prepare('UPDATE app_log SET username = NULL WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('UPDATE import_log SET username = NULL WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);   // כל השאר — בשרשור (ON DELETE CASCADE)
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    if ($resync) { require_once __DIR__ . '/circles.php'; foreach (array_unique($resync) as $rid) circleSync('recipe', $rid); }
    foreach ($files as $f) @unlink($f);
    logEvent('info', 'account-delete', '', ['user_id' => $uid, 'files' => count($files)]);
}
