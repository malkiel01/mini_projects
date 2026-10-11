<?php
/**
 * בשבילך — מתכונים מומלצים (SPEC 15.5). בלי בינה ובלי עלות: חישוב על הנתונים שלנו.
 *
 *   מועמדים: מתכונים באפליקציה שגלויים לי (ציבוריים, ששותפו איתי) ולא שלי, ומהאינדקס
 *   ברשת — רק עם תמונה, ורק כאלה שנבדק שיש בהם מתכון (או מאתר נקי).
 *
 *   אותות (ניקוד משוקלל, FY_W): הנושאים שאני אוהב — ממה שבבעלותי, שמרתי, בישלתי, הוספתי
 *   לקניות, פתחתי, ומה שהצהרתי; מי שאני עוקב אחריו; מה שחברים שמרו; שיתוף שלא פתחתי;
 *   עונה וחגים (לוח עברי); "יש לי כמעט הכול" (מה יש לי בבית); פופולרי. שלילי: "לא מעניין"
 *   (יוצא), "פחות כאלה" (נושא), מה שכבר פתחתי.
 *
 *   מגוון: לכל היותר 2 מאותו נושא ו-2 מאותו אתר/כותב. הסיבה המוצגת — האות הגדול.
 *   המלצות מחברים (📨) — תמיד ראשונות.
 *
 *   פרטיות: user_events נרשם רק כשהתאמה אישית דלוקה (העדפות), נמחק אחרי 180 יום ועם
 *   החשבון. כבויה — רק עונה ופופולרי (החלטה 7). תזונה מוצהרת — מסננת (החלטה 6).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/recipes.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/privacy.php';

const FY_EVENT_DAYS = 180;
const FY_EVENT_KINDS = ['open', 'cook', 'shop', 'foryou_click', 'not_interested', 'less_topic'];
const FY_MAX_PER_TOPIC = 2;
const FY_MAX_PER_SOURCE = 2;

/** משקלות — במקום אחד, כדי לכוונן לפי המדידה (foryouStats). */
const FY_W = [
    'declared' => 3.0, 'own' => 1.0, 'saved' => 2.0, 'cook' => 3.0, 'shop' => 2.0, 'open' => 0.5, 'less' => -6.0,
    'topic_cap' => 8.0,      // נושא אהוב — עד כמה הוא יכול לתרום
    'followed' => 3.0, 'shared_unseen' => 6.0, 'friend_saved' => 2.0, 'season' => 4.0,
    'pantry_full' => 4.0, 'pantry_one' => 3.0, 'popular_cap' => 3.0, 'seen' => -2.0, 'fresh' => 0.3,
];

/** תזונה מוצהרת — מילים בשם, למתכון מהרשת (אין לו תגים). */
const FY_DIET_WORDS = [
    'טבעוני' => ['טבעוני', 'טבעונית', 'טבעוניים', 'טבעוניות', 'vegan'],
    'צמחוני' => ['צמחוני', 'צמחונית', 'צמחוניים', 'טבעוני', 'טבעונית'],
    'ללא גלוטן' => ['ללא גלוטן', 'בלי גלוטן', 'נטול גלוטן'],
    'ללא לקטוז' => ['ללא לקטוז', 'בלי לקטוז', 'פרווה', 'טבעוני', 'טבעונית'],
    'פסח' => ['פסח', 'לפסח', 'כשר לפסח'],
];

// ─────────────────────────────────────────────────────────────
// אירועים
// ─────────────────────────────────────────────────────────────

/** רושם מה המשתמש עשה — רק כשהתאמה אישית דלוקה. 'open' — פעם ביום לכל מתכון. */
function fyEvent(array $user, string $kind, ?int $recipeId = null, ?int $entryId = null, ?string $value = null): void {
    if (!in_array($kind, FY_EVENT_KINDS, true)) throw new AppError('אירוע לא מוכר');
    $uid = (int) $user['id'];
    if (!userPrefs($uid)['personalize']) return;
    if ($kind === 'open') {
        $st = db()->prepare("SELECT 1 FROM user_events WHERE user_id = ? AND kind = 'open' AND recipe_id = ? AND created_at > ?");
        $st->execute([$uid, $recipeId, gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]);
        $dup = $st->fetchColumn();
        $st->closeCursor();
        if ($dup) return;
    }
    db()->prepare('INSERT INTO user_events (user_id, kind, recipe_id, entry_id, value, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$uid, $kind, $recipeId, $entryId, $value, nowIso()]);
    if (random_int(1, 200) === 1) {
        db()->prepare('DELETE FROM user_events WHERE created_at < ?')->execute([gmdate('Y-m-d\TH:i:s\Z', time() - FY_EVENT_DAYS * 86400)]);
    }
}

/** מהממשק: לחיצה על המלצה, "לא מעניין", "פחות כאלה", בישול. */
function fyClientEvent(array $in, array $user): void {
    $kind = (string) ($in['kind'] ?? '');
    if (!in_array($kind, ['foryou_click', 'not_interested', 'less_topic', 'cook'], true)) throw new AppError('אירוע לא מוכר');
    $rid = (int) ($in['recipe_id'] ?? 0) ?: null;
    $eid = (int) ($in['entry_id'] ?? 0) ?: null;
    if ($kind === 'less_topic') {
        $tid = (int) ($in['tag_id'] ?? 0);
        if (!in_array($tid, catalogTagIds('topic'), true)) throw new AppError('נושא לא מוכר');
        fyEvent($user, $kind, null, null, (string) $tid);
        return;
    }
    if (!$rid && !$eid) throw new AppError('על מה?');
    fyEvent($user, $kind, $rid, $eid);
}

// ─────────────────────────────────────────────────────────────
// טעם מוצהר
// ─────────────────────────────────────────────────────────────

function fyTaste(array $user): ?array {
    $st = db()->prepare('SELECT topics, diet FROM user_taste WHERE user_id = ?');
    $st->execute([(int) $user['id']]);
    $r = $st->fetch();
    $st->closeCursor();
    return $r ? ['topics' => array_map('intval', json_decode($r['topics'], true) ?: []), 'diet' => array_map('intval', json_decode($r['diet'], true) ?: [])] : null;
}

function fySetTaste(array $in, array $user): array {
    $topicIds = catalogTagIds('topic');
    $dietIds = catalogTagIds('suitable');
    $topics = array_values(array_intersect(array_map('intval', (array) ($in['topics'] ?? [])), $topicIds));
    $diet = array_values(array_intersect(array_map('intval', (array) ($in['diet'] ?? [])), $dietIds));
    db()->prepare('INSERT INTO user_taste (user_id, topics, diet, updated_at) VALUES (?,?,?,?)
                   ON CONFLICT(user_id) DO UPDATE SET topics = excluded.topics, diet = excluded.diet, updated_at = excluded.updated_at')
        ->execute([(int) $user['id'], json_encode($topics), json_encode($diet), nowIso()]);
    return fyTaste($user);
}

// ─────────────────────────────────────────────────────────────
// עונה וחגים
// ─────────────────────────────────────────────────────────────

/** חגים בלוח העברי: [חודש, יום, ימים, תווית, תגים [ציר => שם], מילים בשם]. אדר (וגם אדר ב׳) — 7. */
const FY_HOLIDAYS = [
    [1, 1, 2, 'לראש השנה', [], ['דבש', 'תפוח', 'תפוחים', 'רימון', 'רימונים', 'ראש השנה']],
    [1, 15, 8, 'לסוכות', [], ['סוכות', 'ממולא', 'ממולאים']],
    [3, 25, 8, 'לחנוכה', ['method' => 'טיגון'], ['לביבות', 'לביבה', 'סופגניות', 'סופגניה', 'חנוכה']],
    [5, 15, 1, 'לט״ו בשבט', [], ['פירות יבשים', 'תמרים', 'בשבט']],
    [7, 14, 2, 'לפורים', [], ['אוזני המן', 'פורים', 'משלוח מנות']],
    [8, 15, 8, 'לפסח', ['suitable' => 'פסח'], ['פסח', 'מצה', 'מצות']],
    [10, 6, 2, 'לשבועות', ['kosher' => 'חלבי'], ['גבינה', 'גבינות', 'שבועות', 'בלינצס', "בלינצ'ס"]],
];
const FY_HOLIDAY_LEAD_DAYS = 10;   // מתחילים להציע כמה ימים לפני

/** מה העונה עכשיו (שעון ישראל): [['label', 'tag_ids', 'words'], ...]. */
function fySeason(?int $now = null): array {
    $tz = new DateTimeZone('Asia/Jerusalem');
    $d = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz);
    $out = [];
    if (function_exists('gregoriantojd') && function_exists('jdtojewish') && function_exists('jewishtojd')) {
        $jd = gregoriantojd((int) $d->format('n'), (int) $d->format('j'), (int) $d->format('Y'));
        $year = (int) explode('/', jdtojewish($jd))[2];
        foreach (FY_HOLIDAYS as [$m, $day, $len, $label, $tags, $words]) {
            foreach ([$year, $year + 1] as $y) {
                $start = jewishtojd($m, $day, $y);
                if ($jd >= $start - FY_HOLIDAY_LEAD_DAYS && $jd < $start + $len) {
                    $ids = [];
                    foreach ($tags as $axis => $name) if (isset(catalogTagIds($axis)[$name])) $ids[] = catalogTagIds($axis)[$name];
                    $out[] = ['label' => $label, 'tag_ids' => $ids, 'words' => $words];
                }
            }
        }
    }
    // חמישי–שישי — לשבת
    if (in_array((int) $d->format('N'), [4, 5], true)) {
        $out[] = ['label' => 'לשבת', 'tag_ids' => [], 'words' => ['שבת', 'חלה', 'חלות', 'חמין', "צ'ולנט", 'צ׳ולנט']];
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────
// מה אני אוהב — זיקה לנושאים
// ─────────────────────────────────────────────────────────────

/** תג נושא → ניקוד זיקה, מכל מה שעשיתי ומה שהצהרתי. */
function fyTopicAffinity(array $user, ?array $taste): array {
    $uid = (int) $user['id'];
    $aff = [];
    $add = function (int $tagId, float $w) use (&$aff) { $aff[$tagId] = ($aff[$tagId] ?? 0) + $w; };
    $topicIds = array_flip(catalogTagIds('topic'));   // id => name
    foreach ($taste['topics'] ?? [] as $t) $add($t, FY_W['declared']);
    $tagsOf = function (string $sql, array $args) use ($topicIds): array {
        $st = db()->prepare("SELECT rt.tag_id, COUNT(*) n FROM recipe_tags rt WHERE rt.recipe_id IN ($sql) GROUP BY rt.tag_id");
        $st->execute($args);
        return array_filter($st->fetchAll(PDO::FETCH_KEY_PAIR), fn($id) => isset($topicIds[$id]), ARRAY_FILTER_USE_KEY);
    };
    foreach ($tagsOf('SELECT id FROM recipes WHERE owner_id = ?', [$uid]) as $t => $n) $add((int) $t, FY_W['own'] * min(3, (int) $n));
    foreach ($tagsOf('SELECT ci.recipe_id FROM collection_items ci JOIN collections c ON c.id = ci.collection_id WHERE c.owner_id = ? AND ci.recipe_id IS NOT NULL', [$uid]) as $t => $n)
        $add((int) $t, FY_W['saved'] * min(3, (int) $n));
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - 90 * 86400);
    foreach (['cook', 'shop', 'open'] as $k) {
        foreach ($tagsOf("SELECT recipe_id FROM user_events WHERE user_id = ? AND kind = '$k' AND recipe_id IS NOT NULL AND created_at > ?", [$uid, $since]) as $t => $n)
            $add((int) $t, FY_W[$k] * min(3, (int) $n));
    }
    // מהרשת: מה ששמרתי באוספים כקישור — נושא לפי השם
    $st = db()->prepare("SELECT ci.title FROM collection_items ci JOIN collections c ON c.id = ci.collection_id WHERE c.owner_id = ? AND ci.kind = 'web'");
    $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $title) foreach (catalogGuessTopics((string) $title) as $t) $add($t, FY_W['saved']);
    // "פחות כאלה"
    $st = db()->prepare("SELECT value FROM user_events WHERE user_id = ? AND kind = 'less_topic'");
    $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) $add((int) $t, FY_W['less']);
    return $aff;
}

// ─────────────────────────────────────────────────────────────
// ההמלצות
// ─────────────────────────────────────────────────────────────

/**
 * בשבילך: ['items' => [...], 'personalize', 'needs_taste', 'taste', 'season' => [תוויות]].
 * פריט: kind (recipe / web / reco), id / entry_id, title, thumb, by (כותב/אתר), reason,
 * open (כתובת בממשק), topic_id + topic (לכפתור "פחות כאלה").
 */
function forYou(array $user, int $limit = 10): array {
    $uid = (int) $user['id'];
    $personal = userPrefs($uid)['personalize'];
    $taste = fyTaste($user);
    $aff = $personal ? fyTopicAffinity($user, $taste) : [];
    $topicName = array_flip(catalogTagIds('topic'));
    $season = fySeason();
    $dietIds = $taste['diet'] ?? [];
    $dietNames = array_values(array_intersect_key(array_flip(catalogTagIds('suitable')), array_flip($dietIds)));

    // מה לא להציע: שלי, שמור אצלי, "לא מעניין"
    $q = fn(string $sql, array $a = []) => (function () use ($sql, $a) { $st = db()->prepare($sql); $st->execute($a); return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)); })();
    $savedR = array_flip($q('SELECT ci.recipe_id FROM collection_items ci JOIN collections c ON c.id = ci.collection_id WHERE c.owner_id = ? AND ci.recipe_id IS NOT NULL', [$uid]));
    $savedUrls = array_flip(array_merge(
        (function () use ($uid) { $st = db()->prepare("SELECT ci.url FROM collection_items ci JOIN collections c ON c.id = ci.collection_id WHERE c.owner_id = ? AND ci.kind = 'web'"); $st->execute([$uid]); return $st->fetchAll(PDO::FETCH_COLUMN); })(),
        (function () use ($uid) { $st = db()->prepare('SELECT source_url FROM recipes WHERE owner_id = ? AND source_url IS NOT NULL'); $st->execute([$uid]); return $st->fetchAll(PDO::FETCH_COLUMN); })()));
    $notR = array_flip($q("SELECT recipe_id FROM user_events WHERE user_id = ? AND kind = 'not_interested' AND recipe_id IS NOT NULL", [$uid]));
    $notE = array_flip($q("SELECT entry_id FROM user_events WHERE user_id = ? AND kind = 'not_interested' AND entry_id IS NOT NULL", [$uid]));
    $opened = array_flip($q("SELECT DISTINCT recipe_id FROM user_events WHERE user_id = ? AND kind = 'open' AND recipe_id IS NOT NULL", [$uid]));
    $following = array_flip($q('SELECT followee_id FROM follows WHERE follower_id = ?', [$uid]));

    $cands = [];
    $seasonOf = function (string $title, array $tagIds) use ($season): ?string {
        foreach ($season as $s) {
            if (array_intersect($s['tag_ids'], $tagIds)) return $s['label'];
            foreach ($s['words'] as $w) if (catalogHasWord($title, $w)) return $s['label'];
        }
        return null;
    };
    $dietOk = function (string $title, ?array $tagIds) use ($dietIds, $dietNames): bool {
        if (!$dietIds) return true;
        if ($tagIds !== null) return !array_diff($dietIds, $tagIds);          // מתכון באפליקציה — כל התגים המוצהרים
        foreach ($dietNames as $n) {                                           // מהרשת — המילה בשם
            $hit = false;
            foreach (FY_DIET_WORDS[$n] ?? [$n] as $w) if (catalogHasWord($title, $w)) { $hit = true; break; }
            if (!$hit) return false;
        }
        return true;
    };

    // ── מתכונים באפליקציה ──
    $vis = "(r.visibility = 'public' OR EXISTS (SELECT 1 FROM recipe_shares sh WHERE sh.recipe_id = r.id AND sh.user_id = $uid))";
    $rows = db()->query("SELECT r.id, r.title, r.owner_id, r.updated_at, u.display_name AS owner_name, m.path_or_url AS main_path, m.source AS main_source,
                                (SELECT CASE WHEN s3.seen_at IS NULL THEN 1 ELSE 0 END FROM recipe_shares s3 WHERE s3.recipe_id = r.id AND s3.user_id = $uid) AS shared_unseen,
                                (SELECT COUNT(DISTINCT c.owner_id) FROM collection_items ci JOIN collections c ON c.id = ci.collection_id WHERE ci.recipe_id = r.id) AS saves
                           FROM recipes r JOIN users u ON u.id = r.owner_id AND u.blocked = 0
                           LEFT JOIN media m ON m.id = r.main_media_id AND m.kind = 'image'
                          WHERE $vis AND r.owner_id != $uid AND " . sqlNotBlockedInline($uid) . "
                          ORDER BY r.updated_at DESC LIMIT 500")->fetchAll();
    $ids = array_column($rows, 'id');
    $tags = [];
    if ($ids) {
        foreach (db()->query('SELECT recipe_id, tag_id FROM recipe_tags WHERE recipe_id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchAll() as $t)
            $tags[(int) $t['recipe_id']][] = (int) $t['tag_id'];
    }
    $friendSaves = [];
    if ($following && $ids) {
        $st = db()->query('SELECT ci.recipe_id, COUNT(DISTINCT c.owner_id) n FROM collection_items ci JOIN collections c ON c.id = ci.collection_id
                            WHERE c.owner_id IN (' . implode(',', array_keys($following)) . ') AND ci.recipe_id IN (' . implode(',', array_map('intval', $ids)) . ') GROUP BY ci.recipe_id');
        foreach ($st->fetchAll() as $f) $friendSaves[(int) $f['recipe_id']] = (int) $f['n'];
    }
    $pantry = [];
    if ($personal) {
        require_once __DIR__ . '/pantry.php';
        foreach (pantryMatch($user, 1, 60)['recipes'] as $p) $pantry[$p['id']] = $p['missing'];
    }
    foreach ($rows as $r) {
        $rid = (int) $r['id'];
        if (isset($savedR[$rid]) || isset($notR[$rid])) continue;
        $tg = $tags[$rid] ?? [];
        if (!$dietOk($r['title'], $tg)) continue;
        $topics = array_values(array_filter($tg, fn($t) => isset($topicName[$t])));
        $sig = [];
        if ($personal) {
            if ($r['shared_unseen'] !== null && (int) $r['shared_unseen'] === 1) $sig[] = [FY_W['shared_unseen'], $r['owner_name'] . ' שיתף/ה איתך'];
            if (isset($following[(int) $r['owner_id']])) $sig[] = [FY_W['followed'], 'של ' . $r['owner_name'], 'followed'];
            if ($n = $friendSaves[$rid] ?? 0) $sig[] = [FY_W['friend_saved'] * $n, $n === 1 ? 'מישהו שאתה עוקב אחריו שמר' : "$n שאתה עוקב אחריהם שמרו"];
            if (isset($pantry[$rid])) $sig[] = $pantry[$rid] ? [FY_W['pantry_one'], 'חסר לך רק ' . $pantry[$rid][0]] : [FY_W['pantry_full'], 'יש לך את כל הרכיבים'];
            if (isset($opened[$rid])) $sig[] = [FY_W['seen'], null];
        }
        $cands[] = ['kind' => 'recipe', 'id' => $rid, 'title' => $r['title'], 'thumb' => mediaThumbUrl($r['main_path'], $r['main_source']),
                    'by' => $r['owner_name'], 'source' => 'u' . $r['owner_id'], 'topics' => $topics, 'tag_ids' => $tg,
                    'saves' => (int) $r['saves'], 'fresh' => $r['updated_at'] > gmdate('Y-m-d\TH:i:s\Z', time() - 14 * 86400), 'sig' => $sig, 'open' => "#/r/$rid"];
    }

    // ── מהרשת: לפי הנושאים שאני אוהב, העונה, והפופולרי ──
    require_once __DIR__ . '/web_index.php';
    catalogRegisterSql();
    $mixed = indexMixedSites();
    $base = "FROM index_entries e JOIN index_sites s ON s.id = e.site_id
             WHERE s.enabled = 1 AND e.skip = 0 AND e.title != '' AND e.image IS NOT NULL AND e.image != ''"
          . ($mixed ? ' AND (e.verified = 1 OR e.site_id NOT IN (' . implode(',', $mixed) . '))' : '');
    $seed = (int) (time() / 86400) % 997 + 1;   // מגוון שמתחלף פעם ביום, ולא בכל רענון
    $pick = function (string $where, array $args, int $n) use ($base, $seed): array {
        $st = db()->prepare("SELECT e.id, e.title, e.url, e.image, e.opens, s.name AS site, s.host,
                                    (SELECT COUNT(DISTINCT owner_id) FROM recipes WHERE source_url = e.url) AS saves
                               $base AND $where ORDER BY e.opens DESC, (e.id * $seed) % 1009 LIMIT $n");
        $st->execute($args);
        return $st->fetchAll();
    };
    arsort($aff);
    $webRows = [];
    foreach (array_slice(array_keys(array_filter($aff, fn($v) => $v > 0)), 0, 4) as $t) {
        if (isset($topicName[$t])) foreach ($pick('catalog_topic(e.title, ?)', [$topicName[$t]], 12) as $e) $webRows[$e['id']] = $e;
    }
    foreach ($season as $s) {
        $or = implode(' OR ', array_fill(0, count($s['words']), 'e.title LIKE ?'));
        foreach ($pick("($or)", array_map(fn($w) => "%$w%", $s['words']), 12) as $e) $webRows[$e['id']] = $e;
    }
    foreach ($pick('(e.opens > 0 OR EXISTS (SELECT 1 FROM recipes rr WHERE rr.source_url = e.url))', [], 12) as $e) $webRows[$e['id']] = $e;
    foreach ($webRows as $e) {
        if (isset($notE[(int) $e['id']]) || isset($savedUrls[$e['url']])) continue;
        if (!$dietOk($e['title'], null)) continue;
        $cands[] = ['kind' => 'web', 'entry_id' => (int) $e['id'], 'title' => $e['title'], 'thumb' => $e['image'], 'by' => $e['site'],
                    'source' => 'h' . $e['host'], 'topics' => catalogGuessTopics($e['title']), 'tag_ids' => [],
                    'saves' => (int) $e['saves'] + min(3, (int) ((int) $e['opens'] / 3)), 'fresh' => false, 'sig' => [], 'open' => '#/web/' . (int) $e['id']];
    }

    // ── ניקוד ──
    foreach ($cands as &$c) {
        $sig = $c['sig'];
        if ($personal) {
            $best = null; $sum = 0.0;
            foreach ($c['topics'] as $t) if (($aff[$t] ?? 0) != 0) { $sum += $aff[$t]; if ($aff[$t] > 0 && (!$best || $aff[$t] > $aff[$best])) $best = $t; }
            if ($sum != 0) $sig[] = [max(-FY_W['topic_cap'] * 2, min(FY_W['topic_cap'], $sum)), $best ? 'כי אתה אוהב ' . $topicName[$best] : null];
        }
        if ($label = $seasonOf($c['title'], $c['tag_ids'])) $sig[] = [FY_W['season'], $label];
        if ($c['saves'] > 0) $sig[] = [min(FY_W['popular_cap'], log(1 + $c['saves'], 2)), 'פופולרי אצלנו'];
        if ($c['fresh']) $sig[] = [FY_W['fresh'], null];
        $c['score'] = array_sum(array_column($sig, 0));
        $reasons = array_filter($sig, fn($s) => $s[1] !== null && $s[0] > 0);
        usort($reasons, fn($a, $b) => $b[0] <=> $a[0]);
        $c['reason'] = $reasons ? $reasons[0][1] : ($c['kind'] === 'web' ? 'מהרשת' : 'חדש באפליקציה');
        $c['topic_id'] = $c['topics'][0] ?? null;
        foreach ($c['topics'] as $t) if (($aff[$t] ?? 0) > ($aff[$c['topic_id']] ?? -INF)) $c['topic_id'] = $t;
    }
    unset($c);
    usort($cands, fn($a, $b) => [$b['score'], $a['kind'] === 'recipe' ? 0 : 1] <=> [$a['score'], $b['kind'] === 'recipe' ? 0 : 1]);

    // ── מגוון ──
    $out = [];
    $perTopic = []; $perSource = [];
    foreach ($cands as $c) {
        if ($c['score'] <= 0 && count($out) >= 4) break;   // אחרי כמה — רק מה שיש לו סיבה
        $t = $c['topic_id'] ?? 0;
        if ($t && ($perTopic[$t] ?? 0) >= FY_MAX_PER_TOPIC) continue;
        if (($perSource[$c['source']] ?? 0) >= FY_MAX_PER_SOURCE) continue;
        $perTopic[$t] = ($perTopic[$t] ?? 0) + 1;
        $perSource[$c['source']] = ($perSource[$c['source']] ?? 0) + 1;
        $out[] = ['kind' => $c['kind'], 'id' => $c['id'] ?? null, 'entry_id' => $c['entry_id'] ?? null, 'title' => $c['title'], 'thumb' => $c['thumb'],
                  'by' => $c['by'], 'reason' => $c['reason'], 'open' => $c['open'], 'score' => round($c['score'], 1),
                  'topic_id' => $c['topic_id'], 'topic' => $c['topic_id'] ? $topicName[$c['topic_id']] : null];
        if (count($out) >= $limit) break;
    }

    // ── המלצות מחברים (📨) — תמיד ראשונות ──
    require_once __DIR__ . '/network.php';
    $recos = array_values(array_filter(recommendationsInbox($user)['items'] ?? [], fn($i) => !empty($i['new'])));
    $recoItems = array_map(fn($i) => ['kind' => 'reco', 'reco_id' => $i['id'], 'title' => $i['title'], 'thumb' => $i['image'] ?? null,
                                      'by' => $i['site'] ?? '', 'reason' => '📨 ' . $i['from']['display_name'] . ' ממליץ/ה', 'open' => $i['open'],
                                      'topic_id' => null, 'topic' => null], array_slice($recos, 0, 3));

    $signals = count(array_filter($aff, fn($v) => $v > 0));
    return [
        'items' => array_slice(array_merge($recoItems, $out), 0, $limit),
        'personalize' => $personal,
        'needs_taste' => $personal && $taste === null && $signals < 3,
        'taste' => $taste ?? ['topics' => [], 'diet' => []],
        'season' => array_column($season, 'label'),
    ];
}

/** חסימה בשני הכיוונים, בשאילתה עם מזהה קבוע (sqlNotBlocked מקבל פרמטרים). */
function sqlNotBlockedInline(int $uid): string {
    return "r.owner_id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = $uid UNION SELECT blocker_id FROM blocks WHERE blocked_id = $uid)";
}

// ─────────────────────────────────────────────────────────────
// מדידה
// ─────────────────────────────────────────────────────────────

/**
 * כדי לכוונן משקלות לפי עובדות: ב-30 הימים האחרונים — כמה לחיצות על המלצות, וכמה
 * מהמתכונים שנלחצו נשמרו / בושלו / נוספו לקניות אחר כך. למפתח.
 */
function foryouStats(): array {
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - 30 * 86400);
    $one = function (string $sql, array $a = []): int { $st = db()->prepare($sql); $st->execute($a); return (int) $st->fetchColumn(); };
    $clicks = $one("SELECT COUNT(*) FROM user_events WHERE kind = 'foryou_click' AND created_at > ?", [$since]);
    $conv = fn(string $kind) => $one("SELECT COUNT(DISTINCT c.id) FROM user_events c JOIN user_events x ON x.user_id = c.user_id AND x.recipe_id = c.recipe_id
                                       AND x.kind = '$kind' AND x.created_at >= c.created_at WHERE c.kind = 'foryou_click' AND c.created_at > ?", [$since]);
    $saved = $one("SELECT COUNT(DISTINCT c.id) FROM user_events c JOIN collection_items ci ON ci.recipe_id = c.recipe_id AND ci.added_at >= c.created_at
                    JOIN collections co ON co.id = ci.collection_id AND co.owner_id = c.user_id WHERE c.kind = 'foryou_click' AND c.created_at > ?", [$since]);
    return [
        'since' => $since, 'clicks' => $clicks, 'then_saved' => $saved, 'then_cooked' => $conv('cook'), 'then_shopped' => $conv('shop'),
        'not_interested' => $one("SELECT COUNT(*) FROM user_events WHERE kind = 'not_interested' AND created_at > ?", [$since]),
        'less_topic' => $one("SELECT COUNT(*) FROM user_events WHERE kind = 'less_topic' AND created_at > ?", [$since]),
        'users_with_taste' => $one('SELECT COUNT(*) FROM user_taste'),
        'weights' => FY_W,
    ];
}
