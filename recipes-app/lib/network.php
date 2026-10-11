<?php
/**
 * רשת: שיתוף מתכונים עם אנשים מסוימים, ומעקב אחרי מי שמפרסם.
 *
 *   שיתוף — הבעלים בוחר משתמשים, והמתכון (גם פרטי) גלוי להם: ברשימה, בחיפוש,
 *   ב"מה יש לי בבית" ובמועדפים (canView ב-recipes.php). הנמען רואה "שותף איתך",
 *   ו"חדש" עד שפתח. הנמען יכול להסיר את עצמו; הבעלים — להסיר כל אחד.
 *   השיתוף אינו העתק: עריכה של הבעלים נראית לנמען מיד, ומחיקה מעלימה.
 *
 *   מעקב — "עוקב" אחרי משתמש: המתכונים הציבוריים שלו (ומה ששותף איתי) בלשונית
 *   "נעקבים". חד-צדדי, בלי אישור — כמו רשת ציבורית. מה שפרטי נשאר פרטי.
 *
 *   פרופיל — דף לכל משתמש: שם, כמה מתכונים ציבוריים, עוקבים ונעקבים, והמתכונים
 *   שגלויים לצופה.
 *
 * בחיפוש אנשים מופיעים רק משתמשים מאומתים ולא חסומים, ורק שם התצוגה ושם המשתמש —
 * לא דוא"ל.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/recipes.php';
require_once __DIR__ . '/log.php';

const SHARE_MAX_PER_RECIPE = 50;

function personPublic(array $u): array {
    return ['id' => (int) $u['id'], 'username' => $u['username'], 'display_name' => $u['display_name'] ?: $u['username']];
}

/** משתמש פעיל (מאומת, לא חסום), או 404. */
function activePerson(int $id): array {
    $st = db()->prepare('SELECT id, username, display_name FROM users WHERE id = ? AND email_verified = 1 AND blocked = 0');
    $st->execute([$id]);
    $u = $st->fetch();
    $st->closeCursor();
    if (!$u) throw new AppError('המשתמש אינו קיים', 404);
    return $u;
}

/** אותיות סופיות כרגילות: בזמן ההקלדה "דנ" עוד לא "דן" — ובלי זה "דן" לא נמצא. */
const HEB_FINALS = ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ'];
function sqlFoldFinals(string $col): string {
    foreach (HEB_FINALS as $f => $r) $col = "replace($col, '$f', '$r')";
    return $col;
}

/** חיפוש אנשים לפי שם תצוגה או שם משתמש (תחילת מילה). בלי המחפש עצמו. */
function peopleSearch(string $q, array $user): array {
    $q = trim(mb_substr($q, 0, 40));
    if (mb_strlen($q) < 2) return [];
    $like = str_replace(['%', '_'], ['\\%', '\\_'], strtr(mb_strtolower($q), HEB_FINALS));
    $name = sqlFoldFinals('lower(u.display_name)');
    $st = db()->prepare("SELECT u.id, u.username, u.display_name,
                                EXISTS (SELECT 1 FROM follows f WHERE f.follower_id = ? AND f.followee_id = u.id) AS following
                           FROM users u
                          WHERE u.id != ? AND u.email_verified = 1 AND u.blocked = 0
                            AND ($name LIKE ? ESCAPE '\\' OR $name LIKE ? ESCAPE '\\' OR lower(u.username) LIKE ? ESCAPE '\\')
                          ORDER BY ($name LIKE ? ESCAPE '\\') DESC, u.display_name LIMIT 12");
    $st->execute([(int) $user['id'], (int) $user['id'], $like . '%', '% ' . $like . '%', $like . '%', $like . '%']);
    return array_map(fn($r) => personPublic($r) + ['following' => (bool) $r['following']], $st->fetchAll());
}

// ─────────────────────────────────────────────────────────────
// שיתוף
// ─────────────────────────────────────────────────────────────

/** עם מי המתכון משותף — לבעלים בלבד. */
function recipeShares(int $recipeId, array $owner): array {
    requireOwnRecipe($recipeId, $owner);
    $st = db()->prepare('SELECT u.id, u.username, u.display_name, s.created_at, s.seen_at
                           FROM recipe_shares s JOIN users u ON u.id = s.user_id WHERE s.recipe_id = ? ORDER BY s.created_at');
    $st->execute([$recipeId]);
    return array_map(fn($r) => personPublic($r) + ['shared_at' => $r['created_at'], 'seen' => $r['seen_at'] !== null], $st->fetchAll());
}

function shareRecipe(int $recipeId, array $userIds, array $owner): array {
    $recipe = requireOwnRecipe($recipeId, $owner);
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($id) => $id > 0 && $id !== (int) $owner['id'])));
    if (!$ids) throw new AppError('לא נבחרו אנשים');
    $have = count(recipeShares($recipeId, $owner));
    if ($have + count($ids) > SHARE_MAX_PER_RECIPE) throw new AppError('עד ' . SHARE_MAX_PER_RECIPE . ' אנשים למתכון. למתכון לכולם — לפרסם אותו כציבורי');
    $ins = db()->prepare('INSERT OR IGNORE INTO recipe_shares (recipe_id, user_id, created_at) VALUES (?,?,?)');
    $added = 0;
    foreach ($ids as $id) {
        activePerson($id);
        $ins->execute([$recipeId, $id, nowIso()]);
        $added += $ins->rowCount();
    }
    logEvent('info', 'recipe-share', $recipe['title'], ['recipe_id' => $recipeId, 'added' => $added], $owner);
    return recipeShares($recipeId, $owner);
}

/** הבעלים מסיר נמען. */
function unshareRecipe(int $recipeId, int $userId, array $owner): array {
    requireOwnRecipe($recipeId, $owner);
    db()->prepare('DELETE FROM recipe_shares WHERE recipe_id = ? AND user_id = ?')->execute([$recipeId, $userId]);
    return recipeShares($recipeId, $owner);
}

/** הנמען מסיר את עצמו ("לא מעניין אותי"). */
function leaveShare(int $recipeId, array $user): void {
    $st = db()->prepare('DELETE FROM recipe_shares WHERE recipe_id = ? AND user_id = ?');
    $st->execute([$recipeId, (int) $user['id']]);
    if ($st->rowCount() === 0) throw new AppError('המתכון לא שותף איתך', 404);
}

/** הנמען פתח את המתכון — כבר לא "חדש". */
function markShareSeen(int $recipeId, array $user): void {
    db()->prepare('UPDATE recipe_shares SET seen_at = ? WHERE recipe_id = ? AND user_id = ? AND seen_at IS NULL')
        ->execute([nowIso(), $recipeId, (int) $user['id']]);
}

/** כמה מתכונים שותפו איתי ועוד לא פתחתי — לתג בלשונית. */
function sharesUnseen(array $user): int {
    $st = db()->prepare('SELECT COUNT(*) FROM recipe_shares WHERE user_id = ? AND seen_at IS NULL');
    $st->execute([(int) $user['id']]);
    return (int) $st->fetchColumn();
}

// ─────────────────────────────────────────────────────────────
// מעקב
// ─────────────────────────────────────────────────────────────

function followUser(int $followeeId, array $user): array {
    if ($followeeId === (int) $user['id']) throw new AppError('אי אפשר לעקוב אחרי עצמך');
    activePerson($followeeId);
    db()->prepare('INSERT OR IGNORE INTO follows (follower_id, followee_id, created_at) VALUES (?,?,?)')->execute([(int) $user['id'], $followeeId, nowIso()]);
    return personProfile($followeeId, $user)['person'];
}

function unfollowUser(int $followeeId, array $user): array {
    db()->prepare('DELETE FROM follows WHERE follower_id = ? AND followee_id = ?')->execute([(int) $user['id'], $followeeId]);
    return personProfile($followeeId, $user)['person'];
}

/** האנשים שלי: אחרי מי אני עוקב, ומי עוקב אחריי. */
function myNetwork(array $user): array {
    $uid = (int) $user['id'];
    $q = function (string $sql, array $args): array { $st = db()->prepare($sql); $st->execute($args); return $st->fetchAll(); };
    $following = $q("SELECT u.id, u.username, u.display_name, 1 AS following,
                            (SELECT COUNT(*) FROM recipes r WHERE r.owner_id = u.id AND r.visibility = 'public') AS public_n
                       FROM follows f JOIN users u ON u.id = f.followee_id
                      WHERE f.follower_id = ? AND u.blocked = 0 ORDER BY u.display_name", [$uid]);
    $followers = $q("SELECT u.id, u.username, u.display_name,
                            EXISTS (SELECT 1 FROM follows f2 WHERE f2.follower_id = ? AND f2.followee_id = u.id) AS following,
                            (SELECT COUNT(*) FROM recipes r WHERE r.owner_id = u.id AND r.visibility = 'public') AS public_n
                       FROM follows f JOIN users u ON u.id = f.follower_id
                      WHERE f.followee_id = ? AND u.blocked = 0 ORDER BY f.created_at DESC", [$uid, $uid]);
    $fmt = fn($r) => personPublic($r) + ['following' => (bool) $r['following'], 'public_n' => (int) $r['public_n']];
    return ['following' => array_map($fmt, $following), 'followers' => array_map($fmt, $followers), 'shares_unseen' => sharesUnseen($user)];
}

// ─────────────────────────────────────────────────────────────
// פרופיל
// ─────────────────────────────────────────────────────────────

/** דף משתמש: הפרטים, המונים, והמתכונים שגלויים לצופה (ציבוריים, ששותפו איתו, או כולם — אם זה הוא). */
function personProfile(int $personId, array $viewer): array {
    $p = activePerson($personId);
    $one = function (string $sql, array $args): int { $st = db()->prepare($sql); $st->execute($args); return (int) $st->fetchColumn(); };
    $vid = (int) $viewer['id'];
    return [
        'person' => personPublic($p) + [
            'is_me'      => $personId === $vid,
            'following'  => (bool) $one('SELECT COUNT(*) FROM follows WHERE follower_id = ? AND followee_id = ?', [$vid, $personId]),
            'follows_me' => (bool) $one('SELECT COUNT(*) FROM follows WHERE follower_id = ? AND followee_id = ?', [$personId, $vid]),
            'public_n'   => $one("SELECT COUNT(*) FROM recipes WHERE owner_id = ? AND visibility = 'public'", [$personId]),
            'followers_n' => $one('SELECT COUNT(*) FROM follows f JOIN users u ON u.id = f.follower_id WHERE f.followee_id = ? AND u.blocked = 0', [$personId]),
            'following_n' => $one('SELECT COUNT(*) FROM follows f JOIN users u ON u.id = f.followee_id WHERE f.follower_id = ? AND u.blocked = 0', [$personId]),
        ],
        'recipes' => searchRecipes($viewer, '', ['owner_id' => $personId]),
    ];
}
