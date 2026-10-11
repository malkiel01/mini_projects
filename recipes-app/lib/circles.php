<?php
/**
 * מעגלים — קבוצות אנשים לשיתוף (SPEC 15.4).
 *
 *   מעגל = רשימת אנשים בעלת שם ("👪 משפחה"), **שרק הבעלים רואה**. חוסך לבחור
 *   שבעה אנשים בכל שיתוף. מי שבמעגל לא יודע את שמו ולא מי עוד בו — רק שמשהו
 *   שותף איתו, כמו היום.
 *
 *   חברות דינמית (החלטה 4): שיתוף עם מעגל = עם מי שבו *עכשיו*. הוספתי את שרה —
 *   היא רואה כל מה ששותף עם המעגל; הוצאתי — כבר לא.
 *
 *   איך: הקישור נשמר ב-recipe_circles / collection_circles, ולכל חבר נכתבת שורת
 *   שיתוף רגילה ב-recipe_shares / collection_shares עם direct = 0. circleSync()
 *   מחשב מחדש את השורות האלה בכל שינוי (חבר נוסף/יצא, מעגל שותף/בוטל/נמחק).
 *   כל מה שקורא שיתופים — הרשאות, הרשימות, "חדש", חסימה — לא השתנה.
 *
 *   שורה ישירה (direct = 1) — מי שבחרתי בשמו — לא נוגעים בה. מי שהסיר את עצמו
 *   (share_optouts) — לא יחזור דרך מעגל. מי שלא מקבל שיתופים ממני (חסימה, "מי
 *   משתף איתי") — נשאר במעגל, אבל לא מקבל.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/network.php';
require_once __DIR__ . '/privacy.php';

const CIRCLE_MAX         = 30;    // מעגלים למשתמש
const CIRCLE_MEMBERS_MAX = 200;   // אנשים במעגל
const CIRCLE_NAME_MAX    = 40;

/** מה אפשר לשתף עם מעגל, ואיפה כל דבר יושב. */
const CIRCLE_TARGETS = [
    'recipe'     => ['shares' => 'recipe_shares', 'col' => 'recipe_id', 'links' => 'recipe_circles', 'table' => 'recipes'],
    'collection' => ['shares' => 'collection_shares', 'col' => 'collection_id', 'links' => 'collection_circles', 'table' => 'collections'],
];

// ─────────────────────────────────────────────────────────────
// סנכרון: שורות השיתוף של המעגלים
// ─────────────────────────────────────────────────────────────

/**
 * מחשב מחדש מי מקבל את המתכון/האוסף דרך מעגלים: מוסיף מי שחסר, מוריד מי שכבר
 * לא (ושאין לו שיתוף ישיר). מחזיר כמה מקבלים דרך מעגל.
 */
function circleSync(string $kind, int $targetId): int {
    $t = CIRCLE_TARGETS[$kind];
    $pdo = db();
    $st = $pdo->prepare("SELECT owner_id FROM {$t['table']} WHERE id = ?");
    $st->execute([$targetId]);
    $owner = $st->fetchColumn();
    $st->closeCursor();
    if ($owner === false) return 0;
    $owner = (int) $owner;

    $st = $pdo->prepare("SELECT DISTINCT cm.user_id
                           FROM {$t['links']} l
                           JOIN circles c ON c.id = l.circle_id AND c.owner_id = ?
                           JOIN circle_members cm ON cm.circle_id = l.circle_id
                           JOIN users u ON u.id = cm.user_id AND u.email_verified = 1 AND u.blocked = 0
                          WHERE l.{$t['col']} = ? AND cm.user_id != ?
                            AND NOT EXISTS (SELECT 1 FROM share_optouts o WHERE o.kind = ? AND o.target_id = ? AND o.user_id = cm.user_id)");
    $st->execute([$owner, $targetId, $owner, $kind, $targetId]);
    $want = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), fn($u) => canReach($owner, $u)));
    // קהילות (lib/communities.php): מתכון שהבעלים הוסיף לקבוצה — לכל החברים הפעילים. הם הצטרפו
    // בהסכמה, ולכן בלי "ממי אני מקבל שיתופים" — רק חסימה ו"הסר מהרשימה שלי" מכובדות.
    if ($kind === 'recipe') {
        $st = $pdo->prepare("SELECT DISTINCT m.user_id FROM community_recipes cr
                               JOIN community_members m ON m.community_id = cr.community_id AND m.status = 'active'
                               JOIN users u ON u.id = m.user_id AND u.email_verified = 1 AND u.blocked = 0
                              WHERE cr.recipe_id = ? AND cr.added_by = ? AND m.user_id != ?
                                AND NOT EXISTS (SELECT 1 FROM share_optouts o WHERE o.kind = 'recipe' AND o.target_id = ? AND o.user_id = m.user_id)");
        $st->execute([$targetId, $owner, $owner, $targetId]);
        foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $u) if (!in_array($u, $want, true) && !blockedBetween($owner, $u)) $want[] = $u;
    }

    $st = $pdo->prepare("SELECT user_id FROM {$t['shares']} WHERE {$t['col']} = ? AND direct = 0");
    $st->execute([$targetId]);
    $have = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    $del = $pdo->prepare("DELETE FROM {$t['shares']} WHERE {$t['col']} = ? AND user_id = ? AND direct = 0");
    foreach (array_diff($have, $want) as $u) $del->execute([$targetId, $u]);
    // מי שכבר יש לו שורה ישירה — נשאר ישיר (OR IGNORE)
    $ins = $pdo->prepare("INSERT OR IGNORE INTO {$t['shares']} ({$t['col']}, user_id, created_at, direct) VALUES (?, ?, ?, 0)");
    foreach (array_diff($want, $have) as $u) $ins->execute([$targetId, $u, nowIso()]);
    return count($want);
}

/** כל מה שמשותף עם המעגל — מסונכרן מחדש (אחרי שינוי בחברים). */
function circleSyncCircle(int $circleId): void {
    foreach (circleTargets($circleId) as [$kind, $id]) circleSync($kind, $id);
}

/** [kind, id] של כל מה שמשותף עם המעגל. */
function circleTargets(int $circleId): array {
    $out = [];
    foreach (CIRCLE_TARGETS as $kind => $t) {
        $st = db()->prepare("SELECT {$t['col']} FROM {$t['links']} WHERE circle_id = ?");
        $st->execute([$circleId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $out[] = [$kind, (int) $id];
    }
    return $out;
}

/** מי שהסיר את עצמו משיתוף — לא יחזור דרך מעגל. */
function shareOptOut(string $kind, int $targetId, int $userId): void {
    db()->prepare('INSERT OR IGNORE INTO share_optouts (kind, target_id, user_id, created_at) VALUES (?,?,?,?)')->execute([$kind, $targetId, $userId, nowIso()]);
}

/** שיתוף ישיר חדש — הבעלים בחר בו שוב, וזה מבטל את ההסרה. */
function shareOptIn(string $kind, int $targetId, int $userId): void {
    db()->prepare('DELETE FROM share_optouts WHERE kind = ? AND target_id = ? AND user_id = ?')->execute([$kind, $targetId, $userId]);
}

// ─────────────────────────────────────────────────────────────
// מעגלים
// ─────────────────────────────────────────────────────────────

/** מעגל שלי, או 404 — אותה תשובה למעגל של אחר ולשאינו קיים. */
function requireOwnCircle(int $id, array $user): array {
    $st = db()->prepare('SELECT * FROM circles WHERE id = ? AND owner_id = ?');
    $st->execute([$id, (int) $user['id']]);
    $c = $st->fetch();
    $st->closeCursor();
    if (!$c) throw new AppError('המעגל אינו קיים', 404);
    return $c;
}

function circleCard(array $c): array {
    $st = db()->prepare('SELECT COUNT(*) FROM circle_members WHERE circle_id = ?');
    $st->execute([(int) $c['id']]);
    return ['id' => (int) $c['id'], 'name' => $c['name'], 'icon' => $c['icon'], 'n' => (int) $st->fetchColumn()];
}

function circlesMine(array $user): array {
    $st = db()->prepare('SELECT * FROM circles WHERE owner_id = ? ORDER BY name');
    $st->execute([(int) $user['id']]);
    return array_map('circleCard', $st->fetchAll());
}

/** מעגל לעריכה: החברים (ומי מהם לא מקבל ממני), ובמה הוא משמש. */
function circleView(int $id, array $user): array {
    $c = requireOwnCircle($id, $user);
    $uid = (int) $user['id'];
    $st = db()->prepare('SELECT u.id, u.username, u.display_name FROM circle_members m JOIN users u ON u.id = m.user_id
                          WHERE m.circle_id = ? AND u.email_verified = 1 AND u.blocked = 0 ORDER BY u.display_name');
    $st->execute([$id]);
    $members = array_map(fn($u) => personPublic($u) + ['reachable' => canReach($uid, (int) $u['id'])], $st->fetchAll());
    $used = ['recipe' => 0, 'collection' => 0];
    foreach (circleTargets($id) as [$kind]) $used[$kind]++;
    return ['circle' => circleCard($c), 'members' => $members, 'used' => $used];
}

function circleFields(array $in, bool $partial): array {
    $f = [];
    if (!$partial || array_key_exists('name', $in)) {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')));
        if ($name === '') throw new AppError('למעגל צריך שם');
        if (mb_strlen($name) > CIRCLE_NAME_MAX) throw new AppError('השם ארוך מדי — עד ' . CIRCLE_NAME_MAX . ' תווים');
        $f['name'] = $name;
    }
    if (!$partial || array_key_exists('icon', $in)) {
        $icon = trim((string) ($in['icon'] ?? ''));
        $f['icon'] = $icon === '' ? '👥' : mb_substr($icon, 0, 4);
    }
    return $f;
}

function circleCreate(array $in, array $user): array {
    $uid = (int) $user['id'];
    $st = db()->prepare('SELECT COUNT(*) FROM circles WHERE owner_id = ?');
    $st->execute([$uid]);
    if ((int) $st->fetchColumn() >= CIRCLE_MAX) throw new AppError('עד ' . CIRCLE_MAX . ' מעגלים');
    $f = circleFields($in, false);
    $now = nowIso();
    db()->prepare('INSERT INTO circles (owner_id, name, icon, created_at, updated_at) VALUES (?,?,?,?,?)')->execute([$uid, $f['name'], $f['icon'], $now, $now]);
    $id = (int) db()->lastInsertId();
    if (!empty($in['user_ids'])) circleAddMembers($id, (array) $in['user_ids'], $user);
    return circleView($id, $user);
}

function circleUpdate(int $id, array $in, array $user): array {
    requireOwnCircle($id, $user);
    $f = circleFields($in, true);
    if ($f) {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
        db()->prepare("UPDATE circles SET $set, updated_at = ? WHERE id = ?")->execute([...array_values($f), nowIso(), $id]);
    }
    return circleView($id, $user);
}

/** מחיקה: מי שקיבל רק דרך המעגל — מפסיק לראות. */
function circleDelete(int $id, array $user): void {
    requireOwnCircle($id, $user);
    $targets = circleTargets($id);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM circles WHERE id = ?')->execute([$id]);
        foreach ($targets as [$kind, $tid]) circleSync($kind, $tid);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

function circleAddMembers(int $id, array $userIds, array $user): array {
    requireOwnCircle($id, $user);
    $uid = (int) $user['id'];
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($x) => $x > 0 && $x !== $uid)));
    if (!$ids) throw new AppError('לא נבחרו אנשים');
    $st = db()->prepare('SELECT COUNT(*) FROM circle_members WHERE circle_id = ?');
    $st->execute([$id]);
    if ((int) $st->fetchColumn() + count($ids) > CIRCLE_MEMBERS_MAX) throw new AppError('עד ' . CIRCLE_MEMBERS_MAX . ' אנשים במעגל');
    $ins = db()->prepare('INSERT OR IGNORE INTO circle_members (circle_id, user_id, added_at) VALUES (?,?,?)');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($ids as $m) {
            activePerson($m);
            if (blockedBetween($uid, $m)) throw new AppError('המשתמש אינו קיים', 404);
            $ins->execute([$id, $m, nowIso()]);
        }
        circleSyncCircle($id);
        db()->prepare('UPDATE circles SET updated_at = ? WHERE id = ?')->execute([nowIso(), $id]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return circleView($id, $user);
}

function circleRemoveMember(int $id, int $memberId, array $user): array {
    requireOwnCircle($id, $user);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM circle_members WHERE circle_id = ? AND user_id = ?')->execute([$id, $memberId]);
        circleSyncCircle($id);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return circleView($id, $user);
}

// ─────────────────────────────────────────────────────────────
// שיתוף עם מעגל
// ─────────────────────────────────────────────────────────────

/** המתכון/האוסף שלי, או שגיאה — דרך הפונקציות של כל אחד (אותן הודעות). */
function circleRequireOwnTarget(string $kind, int $targetId, array $user): void {
    if (!isset(CIRCLE_TARGETS[$kind])) throw new AppError('מה לשתף?');
    if ($kind === 'recipe') { requireOwnRecipe($targetId, $user); return; }
    require_once __DIR__ . '/collections.php';
    requireOwnCollection($targetId, $user);
}

/** המעגלים שהמתכון/האוסף משותף איתם — לבעלים. */
function targetCircles(string $kind, int $targetId, array $user): array {
    circleRequireOwnTarget($kind, $targetId, $user);
    $t = CIRCLE_TARGETS[$kind];
    $st = db()->prepare("SELECT c.* FROM {$t['links']} l JOIN circles c ON c.id = l.circle_id WHERE l.{$t['col']} = ? AND c.owner_id = ? ORDER BY c.name");
    $st->execute([$targetId, (int) $user['id']]);
    return array_map('circleCard', $st->fetchAll());
}

function shareWithCircle(string $kind, int $targetId, int $circleId, array $user): array {
    circleRequireOwnTarget($kind, $targetId, $user);
    $c = requireOwnCircle($circleId, $user);
    $t = CIRCLE_TARGETS[$kind];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT OR IGNORE INTO {$t['links']} ({$t['col']}, circle_id, created_at) VALUES (?,?,?)")->execute([$targetId, $circleId, nowIso()]);
        $n = circleSync($kind, $targetId);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    logEvent('info', 'circle-share', $c['name'], ['kind' => $kind, 'id' => $targetId, 'circle_id' => $circleId, 'reach' => $n], $user);
    return targetCircles($kind, $targetId, $user);
}

function unshareWithCircle(string $kind, int $targetId, int $circleId, array $user): array {
    circleRequireOwnTarget($kind, $targetId, $user);
    $t = CIRCLE_TARGETS[$kind];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM {$t['links']} WHERE {$t['col']} = ? AND circle_id = ?")->execute([$targetId, $circleId]);
        circleSync($kind, $targetId);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return targetCircles($kind, $targetId, $user);
}

/**
 * החברים במעגלים שלי שאפשר להגיע אליהם — להמלצה (📨). המלצה היא הודעה, לא
 * גישה: נשלחת למי שבמעגל ברגע השליחה. מי שלא מקבל ממני — מדולג בשקט.
 */
function circleMemberIds(array $circleIds, array $user): array {
    $uid = (int) $user['id'];
    $out = [];
    foreach (array_unique(array_map('intval', $circleIds)) as $cid) {
        requireOwnCircle($cid, $user);
        $st = db()->prepare('SELECT m.user_id FROM circle_members m JOIN users u ON u.id = m.user_id
                              WHERE m.circle_id = ? AND u.email_verified = 1 AND u.blocked = 0');
        $st->execute([$cid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $m) if (canReach($uid, (int) $m)) $out[(int) $m] = true;
    }
    return array_keys($out);
}
