<?php
/**
 * מרכז פעילות (SPEC 15.6) — 🔔 אחד עם מספר, ודף אחד, במקום תגים פזורים.
 *
 *   מה נכנס: שותף איתי מתכון או אוסף, המלצה, עוקב חדש, מי שאני עוקב אחריו פרסם
 *   מתכון ציבורי, תגובה על מתכון שלי, תשובה לתגובה שלי. 60 הימים האחרונים.
 *
 *   הפעילות נגזרת מהטבלאות הקיימות — אין טבלת אירועים נוספת לכתוב אליה ולשכוח.
 *   activity_seen זוכר מתי פתחתי את הדף; מה שאחרי — "חדש", והוא המספר על הפעמון.
 *   מי שחסמתי או שחסם אותי, ומשתמש שנחסם באתר — לא מופיעים. (התראות לטלפון — אחרי
 *   החנות; פרק 12.)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const ACTIVITY_DAYS  = 60;
const ACTIVITY_MAX   = 50;
const ACTIVITY_FIRST = 7;    // בפעם הראשונה (אין activity_seen) — "חדש" רק מהשבוע האחרון

/** מתי פתחתי את מרכז הפעילות לאחרונה. */
function activitySeenAt(int $uid): string {
    $st = db()->prepare('SELECT seen_at FROM activity_seen WHERE user_id = ?');
    $st->execute([$uid]);
    $s = $st->fetchColumn();
    $st->closeCursor();
    return $s ?: gmdate('Y-m-d\TH:i:s\Z', time() - ACTIVITY_FIRST * 86400);
}

function activityMarkSeen(array $user): void {
    db()->prepare('INSERT INTO activity_seen (user_id, seen_at) VALUES (?,?) ON CONFLICT(user_id) DO UPDATE SET seen_at = excluded.seen_at')
        ->execute([(int) $user['id'], nowIso()]);
}

/**
 * הפעילות שלי, החדשה ראשונה. פריט: kind, actor {id, name}, text, title, snippet, link, at, new.
 */
function activityFeed(array $user): array {
    $uid = (int) $user['id'];
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - ACTIVITY_DAYS * 86400);
    $seen = activitySeenAt($uid);
    // מי שנמצא אצלי בחסימה — לא; וגם לא משתמש שנחסם באתר
    $ok = fn(string $col) => "$col NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = $uid UNION SELECT blocker_id FROM blocks WHERE blocked_id = $uid)
                              AND $col != $uid AND $col IN (SELECT id FROM users WHERE blocked = 0)";
    $q = function (string $sql) use ($since): array {
        $st = db()->prepare($sql . ' LIMIT 30');
        $st->execute([$since]);
        return $st->fetchAll();
    };
    $items = [];
    $add = function (string $kind, array $r, string $text, string $link, ?string $title = null, ?string $snippet = null) use (&$items, $seen) {
        $items[] = ['kind' => $kind, 'actor' => ['id' => (int) $r['actor_id'], 'name' => $r['actor_name'] ?: $r['actor_username']],
                    'text' => $text, 'title' => $title, 'snippet' => $snippet, 'link' => $link, 'at' => $r['at'], 'new' => $r['at'] > $seen];
    };
    $actor = 'u.id AS actor_id, u.display_name AS actor_name, u.username AS actor_username';

    // שותף איתי מתכון (בשם או דרך מעגל) — מה שהגיע דרך קבוצה מופיע למטה, כ"הוסיף/ה לקבוצה"
    foreach ($q("SELECT $actor, r.id, r.title, s.created_at AS at FROM recipe_shares s JOIN recipes r ON r.id = s.recipe_id JOIN users u ON u.id = r.owner_id
                  WHERE s.user_id = $uid AND s.created_at > ? AND " . $ok('u.id') . "
                    AND (s.direct = 1 OR NOT EXISTS (SELECT 1 FROM community_recipes cr JOIN community_members cm ON cm.community_id = cr.community_id
                                                      AND cm.user_id = $uid AND cm.status = 'active' WHERE cr.recipe_id = r.id AND cr.added_by = r.owner_id))
                  ORDER BY s.created_at DESC") as $r)
        $add('share', $r, 'שיתף/ה איתך מתכון', '#/r/' . (int) $r['id'], $r['title']);
    // קהילות (lib/communities.php): הזמנה שמחכה לי, ומתכון חדש בקבוצה שלי
    foreach ($q("SELECT $actor, c.id, c.name AS title, m.created_at AS at FROM community_members m JOIN communities c ON c.id = m.community_id
                   JOIN users u ON u.id = m.invited_by
                  WHERE m.user_id = $uid AND m.status = 'invited' AND m.created_at > ? AND " . $ok('u.id') . ' ORDER BY m.created_at DESC') as $r)
        $add('group_invite', $r, 'הזמין/ה אותך לקבוצה', '#/g/' . (int) $r['id'], $r['title']);
    foreach ($q("SELECT $actor, r.id, r.title, c.name AS cname, cr.added_at AS at FROM community_recipes cr
                   JOIN community_members m ON m.community_id = cr.community_id AND m.user_id = $uid AND m.status = 'active'
                   JOIN communities c ON c.id = cr.community_id JOIN recipes r ON r.id = cr.recipe_id JOIN users u ON u.id = cr.added_by
                  WHERE cr.added_at > ? AND (r.visibility = 'public' OR EXISTS (SELECT 1 FROM recipe_shares s WHERE s.recipe_id = r.id AND s.user_id = $uid))
                    AND r.id NOT IN (SELECT target_id FROM share_optouts WHERE kind = 'recipe' AND user_id = $uid) AND " . $ok('u.id') . '
                  ORDER BY cr.added_at DESC') as $r)
        $add('group_add', $r, 'הוסיף/ה לקבוצה "' . $r['cname'] . '"', '#/r/' . (int) $r['id'], $r['title']);
    // ואוסף
    foreach ($q("SELECT $actor, c.id, c.name AS title, s.created_at AS at FROM collection_shares s JOIN collections c ON c.id = s.collection_id JOIN users u ON u.id = c.owner_id
                  WHERE s.user_id = $uid AND s.created_at > ? AND " . $ok('u.id') . ' ORDER BY s.created_at DESC') as $r)
        $add('collection', $r, 'שיתף/ה איתך אוסף', '#/c/' . (int) $r['id'], $r['title']);
    // המלצה
    foreach ($q("SELECT $actor, rc.title, rc.note, rc.url, rc.entry_id, rc.created_at AS at,
                        EXISTS (SELECT 1 FROM index_entries e WHERE e.id = rc.entry_id AND e.skip = 0) AS in_index
                   FROM recommendations rc JOIN users u ON u.id = rc.from_user
                  WHERE rc.to_user = $uid AND rc.created_at > ? AND " . $ok('u.id') . ' ORDER BY rc.created_at DESC') as $r)
        $add('reco', $r, 'ממליץ/ה לך על', $r['in_index'] ? '#/web/' . (int) $r['entry_id'] : '#/import?url=' . rawurlencode($r['url']), $r['title'], $r['note']);
    // עוקב חדש
    foreach ($q("SELECT $actor, f.created_at AS at FROM follows f JOIN users u ON u.id = f.follower_id
                  WHERE f.followee_id = $uid AND f.created_at > ? AND " . $ok('u.id') . ' ORDER BY f.created_at DESC') as $r)
        $add('follow', $r, 'התחיל/ה לעקוב אחריך', '#/u/' . (int) $r['actor_id']);
    // מי שאני עוקב אחריו פרסם
    foreach ($q("SELECT $actor, r.id, r.title, r.created_at AS at FROM recipes r JOIN users u ON u.id = r.owner_id
                  WHERE r.visibility = 'public' AND r.owner_id IN (SELECT followee_id FROM follows WHERE follower_id = $uid)
                    AND r.id NOT IN (SELECT target_id FROM share_optouts WHERE kind = 'recipe' AND user_id = $uid)
                    AND r.created_at > ? AND " . $ok('u.id') . ' ORDER BY r.created_at DESC') as $r)
        $add('published', $r, 'פרסם/ה מתכון חדש', '#/r/' . (int) $r['id'], $r['title']);
    // תגובה על מתכון שלי
    foreach ($q("SELECT $actor, r.id, r.title, c.text, c.created_at AS at FROM comments c JOIN recipes r ON r.id = c.recipe_id JOIN users u ON u.id = c.user_id
                  WHERE r.owner_id = $uid AND c.visibility = 'public' AND c.created_at > ? AND " . $ok('u.id') . ' ORDER BY c.created_at DESC') as $r)
        $add('comment', $r, 'הגיב/ה על המתכון שלך', '#/r/' . (int) $r['id'], $r['title'], mb_substr($r['text'], 0, 100));
    // תשובה לתגובה שלי — במתכון של אחר (במתכון שלי היא כבר "תגובה על מתכון שלי")
    foreach ($q("SELECT $actor, r.id, r.title, c.text, c.created_at AS at FROM comments c JOIN comments p ON p.id = c.parent_id
                   JOIN recipes r ON r.id = c.recipe_id JOIN users u ON u.id = c.user_id
                  WHERE p.user_id = $uid AND r.owner_id != $uid AND r.visibility = 'public' AND c.visibility = 'public' AND c.created_at > ? AND " . $ok('u.id') . '
                  ORDER BY c.created_at DESC') as $r)
        $add('reply', $r, 'ענה/תה לתגובה שלך', '#/r/' . (int) $r['id'], $r['title'], mb_substr($r['text'], 0, 100));

    usort($items, fn($a, $b) => strcmp($b['at'], $a['at']));
    $items = array_slice($items, 0, ACTIVITY_MAX);
    return ['items' => $items, 'unread' => count(array_filter($items, fn($i) => $i['new']))];
}

/** המספר על הפעמון. */
function activityUnread(array $user): int {
    return activityFeed($user)['unread'];
}
