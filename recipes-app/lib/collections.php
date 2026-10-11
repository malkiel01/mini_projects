<?php
/**
 * אוספים — קבוצות מתכונים (SPEC 15.3).
 *
 *   אוסף = רשימה בעלת שם, סמל ותיאור, ממתכונים מכל מקור: שלי, של אחר שגלוי לי
 *   (הפניה — לא העתק), ומתכון מהחיפוש ברשת בלי לייבא (קישור + שם + תמונה).
 *
 *   "שמורים" — האוסף הראשון של כל משתמש, לא נמחק ולא משנה שם; ♡ מוסיף אליו.
 *   המועדפים הישנים עברו אליו (מיגרציה ב-db.php), והפונקציות של המועדפים
 *   (social.php) עובדות עכשיו מעליו.
 *
 *   פרטיות: פרטי (ברירת מחדל) או ציבורי (בפרופיל). שיתוף עם אנשים — בנפרד.
 *   מי שרואה אוסף רואה בו רק פריטים שגלויים לו, עם חריג אחד (החלטה 3):
 *   **אוסף ששותף איתי** חושף לי את המתכונים הפרטיים של *בעל האוסף* שבתוכו —
 *   "ספר מתכונים לאמא". מתכון פרטי של אחר — לעולם לא. אוסף *ציבורי* אינו
 *   חושף פרטיים: ציבורי הוא לכולם, ומתכון מהרשת חייב להישאר פרטי.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/recipes.php';
require_once __DIR__ . '/network.php';
require_once __DIR__ . '/privacy.php';

const COLLECTION_MAX        = 100;   // אוספים למשתמש
const COLLECTION_ITEMS_MAX  = 500;   // פריטים לאוסף
const COLLECTION_SHARE_MAX  = 50;    // אנשים לאוסף
const COLLECTION_NAME_MAX   = 60;
const COLLECTION_DESC_MAX   = 300;

// ─────────────────────────────────────────────────────────────
// גישה
// ─────────────────────────────────────────────────────────────

function collectionRow(int $id): ?array {
    $st = db()->prepare('SELECT * FROM collections WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    $st->closeCursor();
    return $c ?: null;
}

function collectionSharedWith(int $collectionId, int $userId): bool {
    $st = db()->prepare('SELECT 1 FROM collection_shares WHERE collection_id = ? AND user_id = ?');
    $st->execute([$collectionId, $userId]);
    $ok = (bool) $st->fetchColumn();
    $st->closeCursor();
    return $ok;
}

/** הבעלים; ציבורי — כולם; משותף — הנמענים. חסימה בכל כיוון — לא. */
function canSeeCollection(array $c, array $user): bool {
    $uid = (int) $user['id'];
    if ((int) $c['owner_id'] === $uid) return true;
    if (blockedBetween($uid, (int) $c['owner_id'])) return false;
    return $c['visibility'] === 'public' || collectionSharedWith((int) $c['id'], $uid);
}

/** אותה תשובה לאוסף שאינו קיים ולאוסף שאינו גלוי לי. */
function requireCollection(int $id, array $user): array {
    $c = collectionRow($id);
    if (!$c || !canSeeCollection($c, $user)) throw new AppError('האוסף אינו קיים או שאינו זמין לך', 404);
    return $c;
}

function requireOwnCollection(int $id, array $user): array {
    $c = requireCollection($id, $user);
    if ((int) $c['owner_id'] !== (int) $user['id']) throw new AppError('האוסף אינו שלך', 403);
    return $c;
}

/**
 * תנאי SQL: המתכון r גלוי לצופה. כמו canView, כולל החריג של אוסף משותף —
 * מתכון של בעל אוסף ששותף עם הצופה — ובלי מי שחסם או נחסם.
 */
function collectionRecipeVisibleSql(int $uid): string {
    return "(r.visibility = 'public' OR r.owner_id = $uid
             OR EXISTS (SELECT 1 FROM recipe_shares rs WHERE rs.recipe_id = r.id AND rs.user_id = $uid)
             OR EXISTS (SELECT 1 FROM collection_items ci2 JOIN collections c2 ON c2.id = ci2.collection_id
                          JOIN collection_shares cs2 ON cs2.collection_id = c2.id
                         WHERE ci2.recipe_id = r.id AND c2.owner_id = r.owner_id AND cs2.user_id = $uid))
            AND r.owner_id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = $uid UNION SELECT blocker_id FROM blocks WHERE blocked_id = $uid)";
}

// ─────────────────────────────────────────────────────────────
// פריטים
// ─────────────────────────────────────────────────────────────

/**
 * הפריטים של אוסף, כפי שהצופה רואה אותם. לכל פריט status:
 *   ok     — זמין (מתכון: שדות הרשימה הרגילים; מהרשת: קישור לפתיחה)
 *   hidden — המתכון קיים אך כבר לא גלוי ("אינו זמין כרגע") — רק לבעלים
 *   gone   — המתכון נמחק ("הוסר"), נשאר השם — רק לבעלים
 * מי שאינו הבעלים רואה רק ok.
 */
function collectionItems(array $c, array $viewer, ?int $limit = null): array {
    $uid = (int) $viewer['id'];
    $isOwner = (int) $c['owner_id'] === $uid;
    $vis = collectionRecipeVisibleSql($uid);
    $st = db()->prepare("SELECT ci.*, r.id AS rid, r.title AS r_title, r.owner_id AS r_owner, r.difficulty, r.work_minutes, r.wait_minutes,
                                r.source_url AS r_source, u.display_name AS owner_name, m.path_or_url AS main_path, m.source AS main_source,
                                CASE WHEN r.id IS NULL THEN 0 WHEN $vis THEN 1 ELSE 0 END AS visible,
                                EXISTS (SELECT 1 FROM index_entries e WHERE e.id = ci.entry_id AND e.skip = 0) AS in_index
                           FROM collection_items ci
                           LEFT JOIN recipes r ON r.id = ci.recipe_id
                           LEFT JOIN users u ON u.id = r.owner_id
                           LEFT JOIN media m ON m.id = r.main_media_id AND m.kind = 'image'
                          WHERE ci.collection_id = ?
                          ORDER BY ci.position, ci.id");
    $st->execute([(int) $c['id']]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        if ($row['kind'] === 'web') {
            $out[] = ['item_id' => (int) $row['id'], 'kind' => 'web', 'status' => 'ok', 'title' => $row['title'],
                      'thumb' => $row['image'] ?: null, 'site' => $row['site'], 'url' => $row['url'],
                      'open' => $row['in_index'] ? '#/web/' . (int) $row['entry_id'] : '#/import?url=' . rawurlencode((string) $row['url'])];
        } else {
            $status = $row['rid'] === null ? 'gone' : ($row['visible'] ? 'ok' : 'hidden');
            if ($status !== 'ok' && !$isOwner) continue;
            $ok = $status === 'ok';
            $out[] = ['item_id' => (int) $row['id'], 'kind' => 'recipe', 'status' => $status,
                      'id' => $row['rid'] !== null ? (int) $row['rid'] : null,
                      'title' => $ok ? $row['r_title'] : $row['title'],
                      'owner_name' => $ok ? $row['owner_name'] : null,
                      'is_mine' => $ok && (int) $row['r_owner'] === $uid,
                      'difficulty' => $ok ? $row['difficulty'] : null,
                      'work_minutes' => $ok && $row['work_minutes'] !== null ? (int) $row['work_minutes'] : null,
                      'wait_minutes' => $ok && $row['wait_minutes'] !== null ? (int) $row['wait_minutes'] : null,
                      'thumb' => $ok ? mediaThumbUrl($row['main_path'], $row['main_source']) : null,
                      'from_web' => $ok && !empty($row['r_source']),
                      'saved_at' => $row['added_at']];
        }
        if ($limit !== null && count($out) >= $limit) break;
    }
    return $out;
}

/** אוסף לרשימה: שדות, מספר פריטים גלויים, ותמונת שער מארבעת הראשונים. */
function collectionCard(array $c, array $viewer): array {
    $items = collectionItems($c, $viewer);
    $uid = (int) $viewer['id'];
    $card = [
        'id' => (int) $c['id'], 'name' => $c['name'], 'icon' => $c['icon'], 'description' => $c['description'],
        'visibility' => $c['visibility'], 'is_saved' => (bool) $c['is_saved'], 'is_mine' => (int) $c['owner_id'] === $uid,
        'count' => count(array_filter($items, fn($i) => $i['status'] === 'ok')),
        'cover' => array_slice(array_values(array_filter(array_column(array_filter($items, fn($i) => $i['status'] === 'ok'), 'thumb'))), 0, 4),
        'updated_at' => $c['updated_at'],
    ];
    if ($card['is_mine']) {
        $st = db()->prepare('SELECT COUNT(*) FROM collection_shares WHERE collection_id = ?');
        $st->execute([(int) $c['id']]);
        $card['share_count'] = (int) $st->fetchColumn();
    }
    return $card;
}

/** "שמורים" שלי (נוצר אם אין) — המזהה. */
function mySavedCollection(array $user): int {
    return savedCollectionId(db(), (int) $user['id']);
}

/** האוספים שלי ("שמורים" ראשון) והאוספים ששותפו איתי. */
function collectionsMine(array $user): array {
    mySavedCollection($user);
    $uid = (int) $user['id'];
    $st = db()->prepare('SELECT * FROM collections WHERE owner_id = ? ORDER BY is_saved DESC, updated_at DESC');
    $st->execute([$uid]);
    $mine = array_map(fn($c) => collectionCard($c, $user), $st->fetchAll());
    $st = db()->prepare('SELECT c.*, cs.seen_at AS share_seen, u.display_name AS owner_name, u.username AS owner_username, u.id AS owner_uid
                           FROM collection_shares cs JOIN collections c ON c.id = cs.collection_id JOIN users u ON u.id = c.owner_id
                          WHERE cs.user_id = ? AND u.blocked = 0 ORDER BY cs.created_at DESC');
    $st->execute([$uid]);
    $shared = array_map(fn($c) => collectionCard($c, $user) + [
        'owner' => personPublic(['id' => $c['owner_uid'], 'username' => $c['owner_username'], 'display_name' => $c['owner_name']]),
        'new' => $c['share_seen'] === null,
    ], $st->fetchAll());
    return ['mine' => $mine, 'shared' => $shared];
}

/** אוסף אחד לצפייה. מי ששותף איתו — מסומן "נראה". */
function collectionView(int $id, array $user): array {
    $c = requireCollection($id, $user);
    $uid = (int) $user['id'];
    $isOwner = (int) $c['owner_id'] === $uid;
    $sharedWithMe = !$isOwner && collectionSharedWith($id, $uid);
    if ($sharedWithMe) db()->prepare('UPDATE collection_shares SET seen_at = ? WHERE collection_id = ? AND user_id = ? AND seen_at IS NULL')->execute([nowIso(), $id, $uid]);
    $owner = activePersonOrAny((int) $c['owner_id']);
    return [
        'collection' => collectionCard($c, $user) + ['owner' => $owner, 'shared_with_me' => $sharedWithMe],
        'items' => collectionItems($c, $user),
    ];
}

/**
 * ספר מתכונים מאוסף — להדפסה או לשמירה כ-PDF (מהדפדפן). המתכונים המלאים שגלויים לצופה,
 * בסדר של האוסף; מהרשת — שם, אתר וקישור (המתכון עצמו שייך לאתר). עד COLLECTION_BOOK_MAX.
 */
const COLLECTION_BOOK_MAX = 150;
function collectionBook(int $id, array $user): array {
    $v = collectionView($id, $user);
    $recipes = []; $web = [];
    foreach ($v['items'] as $it) {
        if ($it['status'] !== 'ok') continue;
        if ($it['kind'] === 'web') { $web[] = ['title' => $it['title'], 'site' => $it['site'], 'url' => $it['url']]; continue; }
        if (count($recipes) >= COLLECTION_BOOK_MAX) continue;
        $r = loadRecipe((int) $it['id'], $user);
        if ($r) $recipes[] = $r;
    }
    return ['collection' => $v['collection'], 'recipes' => $recipes, 'web' => $web];
}

function activePersonOrAny(int $id): array {
    $st = db()->prepare('SELECT id, username, display_name FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    $st->closeCursor();
    return $u ? personPublic($u) : ['id' => $id, 'username' => '', 'display_name' => ''];
}

// ─────────────────────────────────────────────────────────────
// יצירה, עריכה, מחיקה
// ─────────────────────────────────────────────────────────────

/** שדות מהטופס, מנוקים. */
function collectionFields(array $in, bool $partial): array {
    $f = [];
    if (!$partial || array_key_exists('name', $in)) {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')));
        if ($name === '') throw new AppError('לאוסף צריך שם');
        if (mb_strlen($name) > COLLECTION_NAME_MAX) throw new AppError('השם ארוך מדי — עד ' . COLLECTION_NAME_MAX . ' תווים');
        $f['name'] = $name;
    }
    if (!$partial || array_key_exists('icon', $in)) {
        $icon = trim((string) ($in['icon'] ?? ''));
        // סמל אחד: עד 4 נקודות קוד (אימוג'י עם צירופים). בלי תוכן — ברירת המחדל
        $f['icon'] = $icon === '' ? '📁' : mb_substr($icon, 0, 4);
    }
    if (!$partial || array_key_exists('description', $in)) {
        $f['description'] = mb_substr(trim((string) ($in['description'] ?? '')), 0, COLLECTION_DESC_MAX);
    }
    if (!$partial || array_key_exists('visibility', $in)) {
        $v = $in['visibility'] ?? 'private';
        if (!in_array($v, ['private', 'public'], true)) throw new AppError('פרטיות לא מוכרת');
        $f['visibility'] = $v;
    }
    return $f;
}

function collectionCreate(array $in, array $user): array {
    $uid = (int) $user['id'];
    $st = db()->prepare('SELECT COUNT(*) FROM collections WHERE owner_id = ?');
    $st->execute([$uid]);
    if ((int) $st->fetchColumn() >= COLLECTION_MAX) throw new AppError('עד ' . COLLECTION_MAX . ' אוספים');
    $f = collectionFields($in, false);
    $now = nowIso();
    db()->prepare('INSERT INTO collections (owner_id, name, icon, description, visibility, created_at, updated_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$uid, $f['name'], $f['icon'], $f['description'], $f['visibility'], $now, $now]);
    $id = (int) db()->lastInsertId();
    return collectionCard(collectionRow($id), $user);
}

function collectionUpdate(int $id, array $in, array $user): array {
    $c = requireOwnCollection($id, $user);
    $f = collectionFields($in, true);
    if ($c['is_saved']) unset($f['name']);   // "שמורים" נשאר "שמורים"
    if ($f) {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
        db()->prepare("UPDATE collections SET $set, updated_at = ? WHERE id = ?")->execute([...array_values($f), nowIso(), $id]);
    }
    return collectionCard(collectionRow($id), $user);
}

function collectionDelete(int $id, array $user): void {
    $c = requireOwnCollection($id, $user);
    if ($c['is_saved']) throw new AppError('את "שמורים" אי אפשר למחוק — אפשר לרוקן');
    db()->prepare('DELETE FROM collections WHERE id = ?')->execute([$id]);
}

// ─────────────────────────────────────────────────────────────
// הוספה, הסרה, סדר
// ─────────────────────────────────────────────────────────────

/**
 * מה מוסיפים: ['recipe_id' => N] — מתכון שגלוי לי; ['entry_id' => N] — מתכון
 * מהחיפוש ברשת, בלי לייבא. מחזיר את שורת הפריט (בלי collection_id).
 */
function collectionTarget(array $target, array $user): array {
    if (!empty($target['recipe_id'])) {
        $st = db()->prepare('SELECT * FROM recipes WHERE id = ?');
        $st->execute([(int) $target['recipe_id']]);
        $r = $st->fetch();
        $st->closeCursor();
        if (!$r || !canView($r, $user)) throw new AppError('המתכון אינו קיים או שאינו זמין לך', 404);
        return ['kind' => 'recipe', 'recipe_id' => (int) $r['id'], 'entry_id' => null, 'url' => null, 'title' => $r['title'], 'image' => null, 'site' => null];
    }
    if (!empty($target['entry_id'])) {
        require_once __DIR__ . '/web_index.php';
        $e = webEntry((int) $target['entry_id']);
        return ['kind' => 'web', 'recipe_id' => null, 'entry_id' => (int) $e['id'], 'url' => $e['url'], 'title' => $e['title'] ?: $e['url'],
                'image' => $e['image'] ?: null, 'site' => $e['site']];
    }
    throw new AppError('מה להוסיף?');
}

/** תנאי ופרמטר לאיתור הפריט באוסף. */
function collectionItemMatch(array $t): array {
    return $t['kind'] === 'recipe' ? ['recipe_id = ?', $t['recipe_id']] : ["kind = 'web' AND url = ?", $t['url']];
}

function collectionHas(int $collectionId, array $t): ?int {
    [$cond, $arg] = collectionItemMatch($t);
    $st = db()->prepare("SELECT id FROM collection_items WHERE collection_id = ? AND $cond");
    $st->execute([$collectionId, $arg]);
    $id = $st->fetchColumn();
    $st->closeCursor();
    return $id ? (int) $id : null;
}

/** מוסיף לסוף האוסף; כבר שם — לא כלום. מחזיר אם נוסף. */
function collectionAddTarget(int $collectionId, array $t): bool {
    if (collectionHas($collectionId, $t)) return false;
    $st = db()->prepare('SELECT COUNT(*), COALESCE(MAX(position), 0) FROM collection_items WHERE collection_id = ?');
    $st->execute([$collectionId]);
    [$n, $max] = $st->fetch(PDO::FETCH_NUM);
    $st->closeCursor();
    if ((int) $n >= COLLECTION_ITEMS_MAX) throw new AppError('עד ' . COLLECTION_ITEMS_MAX . ' פריטים באוסף');
    db()->prepare('INSERT INTO collection_items (collection_id, kind, recipe_id, entry_id, url, title, image, site, position, added_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$collectionId, $t['kind'], $t['recipe_id'], $t['entry_id'], $t['url'], mb_substr($t['title'], 0, 200), $t['image'], $t['site'], (int) $max + 1, nowIso()]);
    db()->prepare('UPDATE collections SET updated_at = ? WHERE id = ?')->execute([nowIso(), $collectionId]);
    return true;
}

/** לגיליון "➕ לאוסף": האוספים שלי, ובאילו הפריט כבר נמצא. */
function collectionsFor(array $target, array $user): array {
    $t = collectionTarget($target, $user);
    mySavedCollection($user);
    $st = db()->prepare('SELECT id, name, icon, is_saved FROM collections WHERE owner_id = ? ORDER BY is_saved DESC, updated_at DESC');
    $st->execute([(int) $user['id']]);
    return array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'icon' => $c['icon'], 'is_saved' => (bool) $c['is_saved'],
                                'has' => collectionHas((int) $c['id'], $t) !== null], $st->fetchAll());
}

/** מסמן/מבטל פריט באוסף שלי. מחזיר את המצב החדש. */
function collectionToggle(int $collectionId, array $target, bool $on, array $user): bool {
    requireOwnCollection($collectionId, $user);
    $t = collectionTarget($target, $user);
    require_once __DIR__ . '/foryou.php';
    $name = (string) collectionRow($collectionId)['name'];
    if ($on) {
        if (collectionAddTarget($collectionId, $t)) fyTrack($user, 'save', $t['recipe_id'], $t['entry_id'], ['to' => $name, 'title' => $t['title']]);
        return true;
    }
    [$cond, $arg] = collectionItemMatch($t);
    $st = db()->prepare("DELETE FROM collection_items WHERE collection_id = ? AND $cond");
    $st->execute([$collectionId, $arg]);
    if ($st->rowCount()) fyTrack($user, 'unsave', $t['recipe_id'], $t['entry_id'], ['from' => $name, 'title' => $t['title']]);
    return false;
}

/** פריט של אוסף שלי, או 404. */
function ownCollectionItem(int $itemId, array $user): array {
    $st = db()->prepare('SELECT ci.* FROM collection_items ci JOIN collections c ON c.id = ci.collection_id WHERE ci.id = ? AND c.owner_id = ?');
    $st->execute([$itemId, (int) $user['id']]);
    $it = $st->fetch();
    $st->closeCursor();
    if (!$it) throw new AppError('הפריט אינו קיים', 404);
    return $it;
}

function collectionRemoveItem(int $itemId, array $user): void {
    $it = ownCollectionItem($itemId, $user);
    db()->prepare('DELETE FROM collection_items WHERE id = ?')->execute([$itemId]);
    require_once __DIR__ . '/foryou.php';
    fyTrack($user, 'unsave', $it['recipe_id'] !== null ? (int) $it['recipe_id'] : null, $it['entry_id'] !== null ? (int) $it['entry_id'] : null,
            ['from' => (string) collectionRow((int) $it['collection_id'])['name'], 'title' => $it['title']]);
}

/** הזזה מעלה/מטה: החלפת מקום עם השכן. */
function collectionMoveItem(int $itemId, int $dir, array $user): void {
    $it = ownCollectionItem($itemId, $user);
    $op = $dir < 0 ? '<' : '>';
    $ord = $dir < 0 ? 'DESC' : 'ASC';
    $st = db()->prepare("SELECT id, position FROM collection_items WHERE collection_id = ? AND (position $op ? OR (position = ? AND id $op ?)) ORDER BY position $ord, id $ord LIMIT 1");
    $st->execute([(int) $it['collection_id'], (int) $it['position'], (int) $it['position'], $itemId]);
    $nb = $st->fetch();
    $st->closeCursor();
    if (!$nb) return;   // כבר בקצה
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // מיקומים זהים (פריטים ישנים) — מקבלים מספרים שונים לפני ההחלפה
        $a = (int) $it['position']; $b = (int) $nb['position'];
        if ($a === $b) $b = $a + ($dir < 0 ? -1 : 1);
        $pdo->prepare('UPDATE collection_items SET position = ? WHERE id = ?')->execute([$b, $itemId]);
        $pdo->prepare('UPDATE collection_items SET position = ? WHERE id = ?')->execute([$a, (int) $nb['id']]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

// ─────────────────────────────────────────────────────────────
// שיתוף אוסף
// ─────────────────────────────────────────────────────────────

function collectionShares(int $id, array $owner): array {
    requireOwnCollection($id, $owner);
    $st = db()->prepare('SELECT u.id, u.username, u.display_name, s.created_at, s.seen_at
                           FROM collection_shares s JOIN users u ON u.id = s.user_id WHERE s.collection_id = ? AND s.direct = 1 ORDER BY s.created_at');
    $st->execute([$id]);
    return array_map(fn($r) => personPublic($r) + ['shared_at' => $r['created_at'], 'seen' => $r['seen_at'] !== null], $st->fetchAll());
}

function shareCollection(int $id, array $userIds, array $owner): array {
    $c = requireOwnCollection($id, $owner);
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($x) => $x > 0 && $x !== (int) $owner['id'])));
    if (!$ids) throw new AppError('לא נבחרו אנשים');
    if (count(collectionShares($id, $owner)) + count($ids) > COLLECTION_SHARE_MAX) throw new AppError('עד ' . COLLECTION_SHARE_MAX . ' אנשים לאוסף. לכולם — להפוך אותו לציבורי');
    require_once __DIR__ . '/circles.php';
    $ins = db()->prepare('INSERT OR IGNORE INTO collection_shares (collection_id, user_id, created_at) VALUES (?,?,?)');
    $direct = db()->prepare('UPDATE collection_shares SET direct = 1 WHERE collection_id = ? AND user_id = ?');
    $added = 0;
    foreach ($ids as $uid) {
        activePerson($uid);
        requireReachable($owner, $uid);
        $ins->execute([$id, $uid, nowIso()]);
        $added += $ins->rowCount();
        $direct->execute([$id, $uid]);
        shareOptIn('collection', $id, $uid);
    }
    logEvent('info', 'collection-share', $c['name'], ['collection_id' => $id, 'added' => $added], $owner);
    return collectionShares($id, $owner);
}

function unshareCollection(int $id, int $userId, array $owner): array {
    requireOwnCollection($id, $owner);
    require_once __DIR__ . '/circles.php';
    db()->prepare('UPDATE collection_shares SET direct = 0 WHERE collection_id = ? AND user_id = ?')->execute([$id, $userId]);
    circleSync('collection', $id);
    return collectionShares($id, $owner);
}

/** הנמען מסיר את עצמו. */
function leaveCollectionShare(int $id, array $user): void {
    require_once __DIR__ . '/circles.php';
    $st = db()->prepare('DELETE FROM collection_shares WHERE collection_id = ? AND user_id = ?');
    $st->execute([$id, (int) $user['id']]);
    if ($st->rowCount() === 0) throw new AppError('האוסף לא שותף איתך', 404);
    shareOptOut('collection', $id, (int) $user['id']);
}

/** אוספים חדשים ששותפו איתי ועוד לא נפתחו — לתג בתפריט. */
function collectionSharesUnseen(array $user): int {
    $st = db()->prepare('SELECT COUNT(*) FROM collection_shares WHERE user_id = ? AND seen_at IS NULL');
    $st->execute([(int) $user['id']]);
    return (int) $st->fetchColumn();
}

/** בפרופיל: האוספים הציבוריים של האדם, ומה ששיתף איתי. "שמורים" — רק אם הפך לציבורי. */
function personCollections(int $personId, array $viewer): array {
    $vid = (int) $viewer['id'];
    if ($personId !== $vid && blockedBetween($vid, $personId)) return [];
    $st = db()->prepare("SELECT c.* FROM collections c WHERE c.owner_id = ?
                            AND (c.visibility = 'public' OR c.owner_id = ? OR EXISTS (SELECT 1 FROM collection_shares s WHERE s.collection_id = c.id AND s.user_id = ?))
                          ORDER BY c.is_saved DESC, c.updated_at DESC");
    $st->execute([$personId, $personId === $vid ? -1 : $vid, $vid]);
    return array_values(array_filter(array_map(fn($c) => collectionCard($c, $viewer), $st->fetchAll()),
                                     fn($c) => $c['count'] > 0 || $personId === $vid));
}
