<?php
/**
 * "מה לבשל הערב?" — שלוש הצעות לארוחה, בלחיצה אחת. בלי בינה בזמן אמת (בלי קרדיט).
 *
 *   מועמדים: מתכונים באפליקציה שגלויים לי (שלי, ששמרתי, ששותפו איתי, ציבוריים), עם
 *   רכיבים ושלבים — ארוחה, לא קינוח: מתכון שכל הנושאים שלו מתוקים או שתייה — לא.
 *   מסננים: זמן (עד X דקות), כשרות (בשרי / חלבי / פרווה), התזונה המוצהרת, "הסר מהרשימה
 *   שלי", "לא מעניין", חסימות.
 *
 *   ניקוד: מה שיש בבית ("מה יש לי בבית") — הכי חזק; מוכר (שלי / שמרתי); הטעם (הזיקה
 *   לנושאים, והניתוח היומי — lib/taste_job.php); בישלתי השבוע — לא שוב; מזמן לא הכנתי —
 *   קצת למעלה. ועוד ערבוב קטן שמתחלף בכל "עוד רעיונות" ובכל יום.
 *
 *   מגוון: לכל היותר שתיים מאותו נושא (ערב = בדרך כלל מנה עיקרית, אבל לא שלוש פסטות).
 *   ללקוח — רק עובדות (זמן, מה חסר), בלי "למה".
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/recipes.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/privacy.php';
require_once __DIR__ . '/foryou.php';
require_once __DIR__ . '/pantry.php';

const TONIGHT_COUNT = 3;
const TONIGHT_SWEET = ['עוגות', 'עוגיות', 'קינוחים', 'שתייה'];   // לא ארוחה
const TONIGHT_KOSHER = ['בשרי', 'חלבי', 'פרווה'];
const TONIGHT_W = [
    'pantry_0' => 6.0, 'pantry_1' => 4.0, 'pantry_2' => 2.0, 'pantry_more' => -1.0,   // לכל רכיב חסר מעבר לשניים
    'mine' => 2.0, 'saved' => 3.0, 'affinity_cap' => 6.0, 'ai_tag' => 1.5, 'ai_keyword' => 2.0, 'ai_avoid' => -6.0,
    'cooked_week' => -8.0, 'cooked_long_ago' => 2.0, 'main' => 4.0, 'soup' => 2.0, 'sweet_word' => -5.0, 'unknown_time' => -1.0, 'jitter' => 2.0,
];
/** בשם — סימן למתוק גם בלי תג (חלה מתוקה, פשטידה מתוקה). */
const TONIGHT_SWEET_WORDS = ['מתוק', 'מתוקה', 'מתוקים', 'שוקולד', 'וניל', 'קינמון', 'ריבה', 'דבש'];

/**
 * opt: minutes (0 = כל זמן), kosher ('' / בשרי / חלבי / פרווה), seed (ל"עוד רעיונות"), exclude (מזהים שכבר הוצגו).
 * מחזיר: items [{id, title, thumb, minutes, difficulty, owner_name, is_mine, missing (null = לא ידוע מה יש בבית)}],
 *        pantry (כמה מוצרים בבית), more (יש עוד).
 */
function tonight(array $user, array $opt = []): array {
    $uid = (int) $user['id'];
    $minutes = max(0, min(600, (int) ($opt['minutes'] ?? 0)));
    $kosher = in_array($opt['kosher'] ?? '', TONIGHT_KOSHER, true) ? $opt['kosher'] : '';
    $seed = (int) ($opt['seed'] ?? 0);
    $exclude = array_flip(array_map('intval', is_array($opt['exclude'] ?? null) ? array_slice($opt['exclude'], 0, 60) : []));

    $topicName = array_flip(catalogTagIds('topic'));
    $sweet = array_values(array_filter(array_map(fn($n) => catalogTagIds('topic')[$n] ?? null, TONIGHT_SWEET)));
    $main = catalogTagIds('topic')['מנה עיקרית'] ?? 0;
    $soup = catalogTagIds('topic')['מרקים'] ?? 0;
    $kosherId = $kosher ? (catalogTagIds('kosher')[$kosher] ?? 0) : 0;
    $personal = userPrefs($uid)['personalize'];
    $taste = fyTaste($user);
    $diet = $taste['diet'] ?? [];
    $aff = $personal ? fyTopicAffinity($user, $taste) : [];
    $ai = $personal ? aiTaste($uid) : null;
    $aiTag = [];
    if ($ai) {
        foreach (db()->query('SELECT id, name FROM tags')->fetchAll() as $t)
            if (isset($ai['profile']['tags'][$t['name']])) $aiTag[(int) $t['id']] = (float) $ai['profile']['tags'][$t['name']];
    }

    $q = function (string $sql, array $a = []): array { $st = db()->prepare($sql); $st->execute($a); return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)); };
    $saved = array_flip($q('SELECT ci.recipe_id FROM collection_items ci JOIN collections c ON c.id = ci.collection_id WHERE c.owner_id = ? AND ci.recipe_id IS NOT NULL', [$uid]));
    $notR = array_flip($q("SELECT recipe_id FROM user_events WHERE user_id = ? AND kind = 'not_interested' AND recipe_id IS NOT NULL", [$uid]));
    $cookedWeek = array_flip($q("SELECT recipe_id FROM user_events WHERE user_id = ? AND kind = 'cook' AND recipe_id IS NOT NULL AND created_at > ?", [$uid, gmdate('Y-m-d\TH:i:s\Z', time() - 7 * 86400)]));
    $cookedEver = array_flip($q("SELECT recipe_id FROM user_events WHERE user_id = ? AND kind = 'cook' AND recipe_id IS NOT NULL", [$uid]));

    // מה יש בבית: לכל מתכון — מה חסר (רק כשיש מוצרים ברשימה)
    $pm = pantryMatch($user, 99, 5000);
    $missing = [];
    foreach ($pm['recipes'] as $p) $missing[$p['id']] = $p['missing'];
    $hasPantry = $pm['pantry_count'] > 0;

    $rows = db()->query("SELECT r.id, r.title, r.owner_id, r.difficulty, r.work_minutes, r.wait_minutes, u.display_name AS owner_name,
                                m.path_or_url AS main_path, m.source AS main_source
                           FROM recipes r JOIN users u ON u.id = r.owner_id AND u.blocked = 0
                           LEFT JOIN media m ON m.id = r.main_media_id AND m.kind = 'image'
                          WHERE (r.visibility = 'public' OR r.owner_id = $uid OR EXISTS (SELECT 1 FROM recipe_shares sh WHERE sh.recipe_id = r.id AND sh.user_id = $uid))
                            AND " . sqlNotBlockedInline($uid) . "
                            AND r.id NOT IN (SELECT target_id FROM share_optouts WHERE kind = 'recipe' AND user_id = $uid)
                            AND EXISTS (SELECT 1 FROM sections s JOIN ingredients i ON i.section_id = s.id WHERE s.recipe_id = r.id)
                            AND EXISTS (SELECT 1 FROM sections s JOIN steps st ON st.section_id = s.id WHERE s.recipe_id = r.id)
                          ORDER BY r.updated_at DESC LIMIT 2000")->fetchAll();
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $tags = [];
    if ($ids) foreach (db()->query('SELECT recipe_id, tag_id FROM recipe_tags WHERE recipe_id IN (' . implode(',', $ids) . ')')->fetchAll() as $t)
        $tags[(int) $t['recipe_id']][] = (int) $t['tag_id'];

    $day = (int) (time() / 86400);
    $cands = [];
    foreach ($rows as $r) {
        $rid = (int) $r['id'];
        if (isset($exclude[$rid]) || isset($notR[$rid])) continue;
        $tg = $tags[$rid] ?? [];
        $topics = array_values(array_filter($tg, fn($t) => isset($topicName[$t])));
        if ($topics && !array_diff($topics, $sweet)) continue;                  // רק מתוק / שתייה — לא ארוחה
        if ($kosherId && !in_array($kosherId, $tg, true)) continue;
        if ($diet && array_diff($diet, $tg)) continue;                          // התזונה המוצהרת — מסננת
        $total = ($r['work_minutes'] !== null || $r['wait_minutes'] !== null) ? (int) $r['work_minutes'] + (int) $r['wait_minutes'] : null;
        if ($minutes) {
            if ($total !== null && $total > $minutes) continue;
            if ($total === null && $minutes <= 30) continue;                    // זמן לא ידוע — לא כשאין זמן
        }
        $s = 0.0;
        $miss = $hasPantry ? ($missing[$rid] ?? null) : null;
        if ($hasPantry) {
            $n = $miss === null ? 99 : count($miss);
            $s += $n === 0 ? TONIGHT_W['pantry_0'] : ($n === 1 ? TONIGHT_W['pantry_1'] : ($n === 2 ? TONIGHT_W['pantry_2'] : TONIGHT_W['pantry_more'] * min(6, $n - 2)));
        }
        if ((int) $r['owner_id'] === $uid) $s += TONIGHT_W['mine'];
        if (isset($saved[$rid])) $s += TONIGHT_W['saved'];
        if ($main && in_array($main, $tg, true)) $s += TONIGHT_W['main'];
        if ($soup && in_array($soup, $tg, true)) $s += TONIGHT_W['soup'];
        foreach (TONIGHT_SWEET_WORDS as $w) if (catalogHasWord($r['title'], $w)) { $s += TONIGHT_W['sweet_word']; break; }
        $a = 0.0;
        foreach ($topics as $t) $a += $aff[$t] ?? 0;
        $s += max(-TONIGHT_W['affinity_cap'], min(TONIGHT_W['affinity_cap'], $a / 2));
        foreach ($tg as $t) $s += ($aiTag[$t] ?? 0) * TONIGHT_W['ai_tag'];
        if ($ai) {
            foreach ($ai['profile']['avoid'] ?? [] as $w) if (mb_stripos($r['title'], $w) !== false) { $s += TONIGHT_W['ai_avoid']; break; }
            foreach ($ai['profile']['keywords'] ?? [] as $w) if (mb_stripos($r['title'], $w) !== false) { $s += TONIGHT_W['ai_keyword']; break; }
        }
        if (isset($cookedWeek[$rid])) $s += TONIGHT_W['cooked_week'];
        elseif (isset($cookedEver[$rid])) $s += TONIGHT_W['cooked_long_ago'];
        if ($total === null) $s += TONIGHT_W['unknown_time'];
        $s += (crc32("$rid:$seed:$day") % 1000) / 1000 * TONIGHT_W['jitter'];   // ערבוב — מתחלף ב"עוד" ובכל יום
        $cands[] = ['s' => $s, 'topic' => $topics[0] ?? 0, 'row' => $r, 'total' => $total, 'missing' => $miss];
    }
    usort($cands, fn($a, $b) => $b['s'] <=> $a['s']);

    // מגוון: עד שתיים מאותו נושא; ואם אין — מה שנשאר, לפי הסדר
    $out = []; $used = [];
    foreach ([true, false] as $strict) {
        foreach ($cands as $c) {
            if (count($out) >= TONIGHT_COUNT) break 2;
            $rid = (int) $c['row']['id'];
            if (isset($out[$rid]) || ($strict && $c['topic'] && ($used[$c['topic']] ?? 0) >= 2)) continue;
            $used[$c['topic']] = ($used[$c['topic']] ?? 0) + 1;
            $r = $c['row'];
            $out[$rid] = ['id' => $rid, 'title' => $r['title'], 'thumb' => mediaThumbUrl($r['main_path'], $r['main_source']),
                          'minutes' => $c['total'], 'difficulty' => $r['difficulty'], 'owner_name' => $r['owner_name'],
                          'is_mine' => (int) $r['owner_id'] === $uid, 'missing' => $hasPantry ? ($c['missing'] ?? null) : null];
        }
    }
    return ['items' => array_values($out), 'pantry' => $pm['pantry_count'], 'more' => count($cands) > count($out)];
}
