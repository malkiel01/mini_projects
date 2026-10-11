<?php
/**
 * קהילות (SPEC 15.4ב) — "ספר המתכונים של המשפחה", "חוג הבישול": קבוצה שכל החברים בה רואים
 * זה את זה, וכל אחד מוסיף לה מתכונים.
 *
 *   הצטרפות — רק בהזמנה ובאישור: מנהל מזמין (מי שמקבל ממנו שיתופים — canReach), המוזמן מאשר
 *   או מסרב. אי אפשר לצרף אדם לקבוצה בלי הסכמתו.
 *   תפקידים: owner (יצר; מוחק את הקבוצה; לא עוזב), admin (מזמין, מסיר חברים ומתכונים, עורך),
 *   member (מוסיף מתכונים, מסיר את שלו, עוזב).
 *   מה מוסיפים: מתכון שלי (גם פרטי — זה שיתוף עם הקבוצה), או ציבורי של אחר (הפניה; אם יהפוך
 *   לפרטי — ייעלם מהקבוצה).
 *   מי רואה: מתכון שלי שהוספתי — כל החברים הפעילים, דרך שיתופים ממומשים (recipe_shares,
 *   direct = 0) שמחושבים ב-circleSync (lib/circles.php) — כך כל מקום שבודק "גלוי לי" עובד
 *   בלי שינוי. "הסר מהרשימה שלי" וחסימה — מכובדים.
 *   עזיבה / הסרה: המתכונים שהחבר הוסיף יוצאים מהקבוצה, והשיתופים מתעדכנים.
 *   דיווח — ⚑ הקיים במתכון; מנהל מסיר מהקבוצה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/recipes.php';
require_once __DIR__ . '/privacy.php';
require_once __DIR__ . '/network.php';
require_once __DIR__ . '/circles.php';

const COMMUNITY_MAX_OWNED   = 20;
const COMMUNITY_MAX_MEMBERS = 200;
const COMMUNITY_MAX_RECIPES = 1000;
const COMMUNITY_ICONS = ['👨‍👩‍👧', '🏠', '🍲', '👩‍🍳', '🕍', '🎉', '🥗', '🎂', '🌿', '📖'];

// ─────────────────────────────────────────────────────────────
// עזר
// ─────────────────────────────────────────────────────────────

function communityRow(int $id): ?array {
    $st = db()->prepare('SELECT * FROM communities WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    $st->closeCursor();
    return $r ?: null;
}

/** החברות שלי: ['role', 'status'] או null. */
function communityMembership(int $id, int $uid): ?array {
    $st = db()->prepare('SELECT role, status, invited_by FROM community_members WHERE community_id = ? AND user_id = ?');
    $st->execute([$id, $uid]);
    $r = $st->fetch();
    $st->closeCursor();
    return $r ?: null;
}

/** קהילה שאני חבר פעיל בה (או 404 — אותה תשובה לקהילה שאינה קיימת). */
function requireCommunityMember(int $id, array $user, bool $admin = false): array {
    $c = communityRow($id);
    $m = $c ? communityMembership($id, (int) $user['id']) : null;
    if (!$c || !$m || $m['status'] !== 'active') throw new AppError('הקבוצה אינה קיימת', 404);
    if ($admin && $m['role'] === 'member') throw new AppError('רק מנהל/ת הקבוצה', 403);
    return $c + ['my_role' => $m['role']];
}

function communityFields(array $in): array {
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 60) throw new AppError('לקבוצה צריך שם (עד 60 תווים)');
    $icon = in_array($in['icon'] ?? '', COMMUNITY_ICONS, true) ? $in['icon'] : COMMUNITY_ICONS[0];
    return ['name' => $name, 'icon' => $icon, 'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 300)];
}

/** החברים הפעילים — מי שמקבל את המתכונים הפרטיים שנוספו (מלבד הבעלים של המתכון). */
function communityActiveIds(int $id): array {
    $st = db()->prepare("SELECT user_id FROM community_members WHERE community_id = ? AND status = 'active'");
    $st->execute([$id]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** אחרי כל שינוי בחברים או במתכונים — השיתופים של מתכוני הקבוצה מחושבים מחדש. */
function communityResync(int $id, array $recipeIds = []): void {
    if (!$recipeIds) {
        $st = db()->prepare('SELECT recipe_id FROM community_recipes WHERE community_id = ?');
        $st->execute([$id]);
        $recipeIds = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    foreach (array_unique(array_map('intval', $recipeIds)) as $rid) circleSync('recipe', $rid);
}

// ─────────────────────────────────────────────────────────────
// יצירה, עריכה, מחיקה
// ─────────────────────────────────────────────────────────────

function communityCreate(array $in, array $user): array {
    $uid = (int) $user['id'];
    $st = db()->prepare('SELECT COUNT(*) FROM communities WHERE owner_id = ?');
    $st->execute([$uid]);
    if ((int) $st->fetchColumn() >= COMMUNITY_MAX_OWNED) throw new AppError('עד ' . COMMUNITY_MAX_OWNED . ' קבוצות');
    $f = communityFields($in);
    $now = nowIso();
    db()->prepare('INSERT INTO communities (owner_id, name, icon, description, created_at, updated_at) VALUES (?,?,?,?,?,?)')
        ->execute([$uid, $f['name'], $f['icon'], $f['description'], $now, $now]);
    $id = (int) db()->lastInsertId();
    db()->prepare("INSERT INTO community_members (community_id, user_id, role, status, created_at, joined_at) VALUES (?, ?, 'owner', 'active', ?, ?)")
        ->execute([$id, $uid, $now, $now]);
    return communityView($id, $user);
}

function communityUpdate(int $id, array $in, array $user): array {
    requireCommunityMember($id, $user, true);
    $f = communityFields($in);
    db()->prepare('UPDATE communities SET name = ?, icon = ?, description = ?, updated_at = ? WHERE id = ?')
        ->execute([$f['name'], $f['icon'], $f['description'], nowIso(), $id]);
    return communityView($id, $user);
}

function communityDelete(int $id, array $user): void {
    $c = requireCommunityMember($id, $user);
    if ($c['my_role'] !== 'owner') throw new AppError('רק מי שיצר/ה את הקבוצה מוחק/ת אותה', 403);
    $st = db()->prepare('SELECT recipe_id FROM community_recipes WHERE community_id = ?');
    $st->execute([$id]);
    $recipes = $st->fetchAll(PDO::FETCH_COLUMN);
    db()->prepare('DELETE FROM communities WHERE id = ?')->execute([$id]);   // חברים ומתכונים — CASCADE
    communityResync($id, $recipes ?: [0]);
}

// ─────────────────────────────────────────────────────────────
// הזמנה, אישור, עזיבה, תפקידים
// ─────────────────────────────────────────────────────────────

/** מנהל מזמין. רק מי שמקבל ממנו שיתופים; מי שכבר חבר או מוזמן — מדלגים. מחזיר כמה הוזמנו. */
function communityInvite(int $id, array $userIds, array $user): int {
    requireCommunityMember($id, $user, true);
    $uid = (int) $user['id'];
    $st = db()->prepare('SELECT COUNT(*) FROM community_members WHERE community_id = ?');
    $st->execute([$id]);
    $n = (int) $st->fetchColumn();
    $added = 0;
    foreach (array_unique(array_map('intval', $userIds)) as $to) {
        if ($to === $uid || communityMembership($id, $to)) continue;
        activePerson($to);
        requireReachable($user, $to);
        if ($n + $added >= COMMUNITY_MAX_MEMBERS) throw new AppError('עד ' . COMMUNITY_MAX_MEMBERS . ' חברים בקבוצה');
        db()->prepare("INSERT INTO community_members (community_id, user_id, role, status, invited_by, created_at) VALUES (?, ?, 'member', 'invited', ?, ?)")
            ->execute([$id, $to, $uid, nowIso()]);
        $added++;
    }
    return $added;
}

/** המוזמן מאשר (נכנס, ורואה מיד את כל מה שבקבוצה) או מסרב. */
function communityRespond(int $id, bool $accept, array $user): void {
    $uid = (int) $user['id'];
    $m = communityMembership($id, $uid);
    if (!$m || $m['status'] !== 'invited') throw new AppError('אין הזמנה לקבוצה הזאת', 404);
    if (!$accept) { db()->prepare('DELETE FROM community_members WHERE community_id = ? AND user_id = ?')->execute([$id, $uid]); return; }
    db()->prepare("UPDATE community_members SET status = 'active', joined_at = ? WHERE community_id = ? AND user_id = ?")->execute([nowIso(), $id, $uid]);
    communityResync($id);
}

/** יציאה (עזיבה, או הסרה בידי מנהל): המתכונים שהחבר הוסיף יוצאים, והשיתופים מתעדכנים. */
function communityDropMember(int $id, int $memberId): void {
    $st = db()->prepare('SELECT recipe_id FROM community_recipes WHERE community_id = ?');
    $st->execute([$id]);
    $recipes = $st->fetchAll(PDO::FETCH_COLUMN);
    db()->prepare('DELETE FROM community_recipes WHERE community_id = ? AND added_by = ?')->execute([$id, $memberId]);
    db()->prepare('DELETE FROM community_members WHERE community_id = ? AND user_id = ?')->execute([$id, $memberId]);
    if ($recipes) communityResync($id, $recipes);
}

function communityLeave(int $id, array $user): void {
    $c = requireCommunityMember($id, $user);
    if ($c['my_role'] === 'owner') throw new AppError('מי שיצר/ה את הקבוצה לא עוזב/ת — אפשר למחוק אותה');
    communityDropMember($id, (int) $user['id']);
}

function communityRemoveMember(int $id, int $memberId, array $user): array {
    requireCommunityMember($id, $user, true);
    $m = communityMembership($id, $memberId);
    if (!$m) throw new AppError('לא בקבוצה', 404);
    if ($m['role'] === 'owner') throw new AppError('אי אפשר להסיר את מי שיצר/ה את הקבוצה');
    communityDropMember($id, $memberId);
    return communityView($id, $user);
}

function communitySetRole(int $id, int $memberId, string $role, array $user): array {
    requireCommunityMember($id, $user, true);
    if (!in_array($role, ['admin', 'member'], true)) throw new AppError('תפקיד לא מוכר');
    $m = communityMembership($id, $memberId);
    if (!$m || $m['status'] !== 'active') throw new AppError('לא בקבוצה', 404);
    if ($m['role'] === 'owner') throw new AppError('מי שיצר/ה את הקבוצה — תמיד מנהל/ת');
    db()->prepare('UPDATE community_members SET role = ? WHERE community_id = ? AND user_id = ?')->execute([$role, $id, $memberId]);
    return communityView($id, $user);
}

// ─────────────────────────────────────────────────────────────
// מתכונים בקבוצה
// ─────────────────────────────────────────────────────────────

/** חבר מוסיף: מתכון שלו (גם פרטי — זה שיתוף עם הקבוצה), או ציבורי של אחר. */
function communityAddRecipe(int $id, int $recipeId, array $user): array {
    requireCommunityMember($id, $user);
    $uid = (int) $user['id'];
    $st = db()->prepare('SELECT * FROM recipes WHERE id = ?');
    $st->execute([$recipeId]);
    $r = $st->fetch();
    $st->closeCursor();
    if (!$r || !canView($r, $user)) throw new AppError('המתכון אינו קיים או שאינו זמין לך', 404);
    if ((int) $r['owner_id'] !== $uid && $r['visibility'] !== 'public') throw new AppError('אפשר להוסיף מתכון שלך, או מתכון ציבורי');
    $st = db()->prepare('SELECT COUNT(*) FROM community_recipes WHERE community_id = ?');
    $st->execute([$id]);
    if ((int) $st->fetchColumn() >= COMMUNITY_MAX_RECIPES) throw new AppError('עד ' . COMMUNITY_MAX_RECIPES . ' מתכונים בקבוצה');
    db()->prepare('INSERT OR IGNORE INTO community_recipes (community_id, recipe_id, added_by, added_at) VALUES (?,?,?,?)')
        ->execute([$id, $recipeId, $uid, nowIso()]);
    db()->prepare('UPDATE communities SET updated_at = ? WHERE id = ?')->execute([nowIso(), $id]);
    circleSync('recipe', $recipeId);
    return communityView($id, $user);
}

/** מי שהוסיף, בעל המתכון, או מנהל. */
function communityRemoveRecipe(int $id, int $recipeId, array $user): array {
    $c = requireCommunityMember($id, $user);
    $st = db()->prepare('SELECT cr.added_by, r.owner_id FROM community_recipes cr JOIN recipes r ON r.id = cr.recipe_id WHERE cr.community_id = ? AND cr.recipe_id = ?');
    $st->execute([$id, $recipeId]);
    $row = $st->fetch();
    $st->closeCursor();
    if (!$row) throw new AppError('המתכון אינו בקבוצה', 404);
    $uid = (int) $user['id'];
    if ($c['my_role'] === 'member' && (int) $row['added_by'] !== $uid && (int) $row['owner_id'] !== $uid) throw new AppError('רק מי שהוסיף, או מנהל/ת', 403);
    db()->prepare('DELETE FROM community_recipes WHERE community_id = ? AND recipe_id = ?')->execute([$id, $recipeId]);
    circleSync('recipe', $recipeId);
    return communityView($id, $user);
}

// ─────────────────────────────────────────────────────────────
// תצוגה
// ─────────────────────────────────────────────────────────────

/** הקבוצות שלי (פעילות) וההזמנות שמחכות לי. */
function communitiesMine(array $user): array {
    $uid = (int) $user['id'];
    $st = db()->prepare("SELECT c.*, m.role, m.status, m.invited_by, u.display_name AS inviter_name, u.username AS inviter_username,
                                (SELECT COUNT(*) FROM community_members x WHERE x.community_id = c.id AND x.status = 'active') AS members,
                                (SELECT COUNT(*) FROM community_recipes x WHERE x.community_id = c.id) AS recipes
                           FROM community_members m JOIN communities c ON c.id = m.community_id
                           LEFT JOIN users u ON u.id = m.invited_by
                          WHERE m.user_id = ? ORDER BY c.updated_at DESC");
    $st->execute([$uid]);
    $mine = []; $invites = [];
    foreach ($st->fetchAll() as $r) {
        $card = ['id' => (int) $r['id'], 'name' => $r['name'], 'icon' => $r['icon'], 'description' => $r['description'],
                 'members' => (int) $r['members'], 'recipes' => (int) $r['recipes'], 'role' => $r['role']];
        if ($r['status'] === 'active') $mine[] = $card;
        elseif (!blockedBetween($uid, (int) $r['invited_by'])) $invites[] = $card + ['inviter' => $r['inviter_name'] ?: $r['inviter_username']];
    }
    return ['mine' => $mine, 'invites' => $invites, 'icons' => COMMUNITY_ICONS];
}

/** דף הקבוצה: פרטים, החברים (מוזמנים — רק למנהל), והמתכונים שגלויים לי. */
function communityView(int $id, array $user): array {
    $uid = (int) $user['id'];
    $c = communityRow($id);
    $m = $c ? communityMembership($id, $uid) : null;
    if (!$c || !$m) throw new AppError('הקבוצה אינה קיימת', 404);
    $base = ['id' => (int) $c['id'], 'name' => $c['name'], 'icon' => $c['icon'], 'description' => $c['description'], 'my_role' => $m['role'], 'my_status' => $m['status']];
    if ($m['status'] === 'invited') {
        $inv = db()->query('SELECT display_name, username FROM users WHERE id = ' . (int) $m['invited_by'])->fetch();
        return ['community' => $base + ['inviter' => $inv ? ($inv['display_name'] ?: $inv['username']) : ''], 'members' => [], 'recipes' => []];
    }
    $isAdmin = $m['role'] !== 'member';
    $st = db()->prepare("SELECT u.id, u.username, u.display_name, m.role, m.status FROM community_members m JOIN users u ON u.id = m.user_id
                          WHERE m.community_id = ? AND u.blocked = 0 ORDER BY (m.role = 'owner') DESC, (m.role = 'admin') DESC, m.status, u.display_name");
    $st->execute([$id]);
    $members = [];
    foreach ($st->fetchAll() as $p) {
        if ($p['status'] === 'invited' && !$isAdmin) continue;
        $members[] = personPublic($p) + ['role' => $p['role'], 'status' => $p['status'], 'is_me' => (int) $p['id'] === $uid];
    }
    $st = db()->prepare('SELECT cr.recipe_id, cr.added_by, cr.added_at, u.display_name AS adder FROM community_recipes cr JOIN users u ON u.id = cr.added_by
                          WHERE cr.community_id = ? ORDER BY cr.added_at DESC');
    $st->execute([$id]);
    $rows = $st->fetchAll();
    $byId = [];
    if ($rows) {
        $visible = searchRecipes($user, '', ['ids' => array_map(fn($r) => (int) $r['recipe_id'], $rows)]);
        foreach ($visible as $r) $byId[$r['id']] = $r;
    }
    $recipes = [];
    foreach ($rows as $r) {
        $rid = (int) $r['recipe_id'];
        if (!isset($byId[$rid])) continue;   // הפך לפרטי, נחסם, או שהסרתי מהרשימה שלי
        $recipes[] = $byId[$rid] + ['added_by' => (int) $r['added_by'], 'adder' => $r['adder'], 'added_at' => $r['added_at'],
                                    'can_remove' => $isAdmin || (int) $r['added_by'] === $uid || $byId[$rid]['is_mine']];
    }
    return ['community' => $base + ['members_n' => count(array_filter($members, fn($p) => $p['status'] === 'active')), 'recipes_n' => count($recipes)],
            'members' => $members, 'recipes' => $recipes, 'icons' => COMMUNITY_ICONS];
}
