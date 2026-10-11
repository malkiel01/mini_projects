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
require_once __DIR__ . '/social.php';

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

// ─────────────────────────────────────────────────────────────
// המלצות — "תעיין במתכון הזה": קישור למתכון מהרשת, לא העתק
// ─────────────────────────────────────────────────────────────

const RECO_DAILY_MAX = 30;   // המלצות ששולח משתמש ביום — נגד הצפה

/**
 * ממליץ לאנשים על מתכון מהרשת. $target: ['entry_id' => N] (מהחיפוש ברשת) או
 * ['recipe_id' => N] (מתכון מיובא שגלוי לי — ההמלצה היא על המקור שלו, לא על העותק).
 */
function recommend(array $target, array $toIds, string $note, array $user): array {
    require_once __DIR__ . '/web_index.php';
    if (!empty($target['entry_id'])) {
        $e = webEntry((int) $target['entry_id']);
        $item = ['entry_id' => (int) $e['id'], 'url' => $e['url'], 'title' => $e['title'], 'site' => $e['site'], 'image' => $e['image'] ?: null];
    } elseif (!empty($target['recipe_id'])) {
        $r = viewableRecipeRow((int) $target['recipe_id'], $user);
        if (empty($r['source_url'])) throw new AppError('אפשר להמליץ רק על מתכון מהרשת. מתכון שלך — משתפים (👥)');
        $st = db()->prepare('SELECT id, image FROM index_entries WHERE url = ?');
        $st->execute([$r['source_url']]);
        $e = $st->fetch() ?: null;
        $st->closeCursor();
        $item = ['entry_id' => $e ? (int) $e['id'] : null, 'url' => $r['source_url'], 'title' => $r['title'],
                 'site' => $r['source_name'] ?: parse_url($r['source_url'], PHP_URL_HOST), 'image' => $e['image'] ?? null];
    } else {
        throw new AppError('על מה ההמלצה?');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $toIds), fn($id) => $id > 0 && $id !== (int) $user['id'])));
    if (!$ids) throw new AppError('לא נבחרו אנשים');
    $st = db()->prepare('SELECT COUNT(*) FROM recommendations WHERE from_user = ? AND created_at > ?');
    $st->execute([(int) $user['id'], gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]);
    if ((int) $st->fetchColumn() + count($ids) > RECO_DAILY_MAX) throw new AppError('הגעת למספר ההמלצות ליום (' . RECO_DAILY_MAX . '). אפשר שוב מחר', 429);
    $note = mb_substr(trim($note), 0, 300);
    $dup = db()->prepare('SELECT 1 FROM recommendations WHERE from_user = ? AND to_user = ? AND url = ? AND seen_at IS NULL');
    $ins = db()->prepare('INSERT INTO recommendations (from_user, to_user, entry_id, url, title, site, image, note, created_at) VALUES (?,?,?,?,?,?,?,?,?)');
    $sent = 0;
    foreach ($ids as $id) {
        activePerson($id);
        $dup->execute([(int) $user['id'], $id, $item['url']]);
        $exists = $dup->fetchColumn();
        $dup->closeCursor();
        if ($exists) continue;   // כבר ממתינה אצלו — לא שוב
        $ins->execute([(int) $user['id'], $id, $item['entry_id'], $item['url'], mb_substr($item['title'], 0, 140), $item['site'], $item['image'], $note !== '' ? $note : null, nowIso()]);
        $sent++;
    }
    logEvent('info', 'recommend', $item['title'], ['to' => count($ids), 'sent' => $sent], $user);
    return ['sent' => $sent, 'already' => count($ids) - $sent];
}

/** ההמלצות שקיבלתי — החדשות קודם. 'open' — לאן מובילה: #/web/<entry> כשבאינדקס, אחרת ייבוא מקישור. */
function recommendationsInbox(array $user): array {
    $st = db()->prepare("SELECT r.*, u.username, u.display_name,
                                EXISTS (SELECT 1 FROM index_entries e WHERE e.id = r.entry_id AND e.skip = 0) AS in_index
                           FROM recommendations r JOIN users u ON u.id = r.from_user
                          WHERE r.to_user = ? AND u.blocked = 0 ORDER BY (r.seen_at IS NULL) DESC, r.id DESC LIMIT 50");
    $st->execute([(int) $user['id']]);
    $items = array_map(fn($r) => [
        'id' => (int) $r['id'], 'from' => personPublic(['id' => $r['from_user'], 'username' => $r['username'], 'display_name' => $r['display_name']]),
        'title' => $r['title'], 'site' => $r['site'], 'image' => $r['image'], 'note' => $r['note'], 'url' => $r['url'],
        'open' => $r['in_index'] ? '#/web/' . (int) $r['entry_id'] : '#/import?url=' . rawurlencode($r['url']),
        'new' => $r['seen_at'] === null, 'created_at' => $r['created_at'],
    ], $st->fetchAll());
    return ['items' => $items, 'unseen' => count(array_filter($items, fn($i) => $i['new']))];
}

function recommendationSeen(int $id, array $user): void {
    db()->prepare('UPDATE recommendations SET seen_at = COALESCE(seen_at, ?) WHERE id = ? AND to_user = ?')->execute([nowIso(), $id, (int) $user['id']]);
}

function recommendationDismiss(int $id, array $user): void {
    $st = db()->prepare('DELETE FROM recommendations WHERE id = ? AND to_user = ?');
    $st->execute([$id, (int) $user['id']]);
    if ($st->rowCount() === 0) throw new AppError('ההמלצה אינה קיימת', 404);
}
