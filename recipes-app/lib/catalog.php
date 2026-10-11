<?php
/**
 * קטגוריות — דפדוף, סינון ושיוך אוטומטי (SPEC 15.1).
 *
 *   נושא מהשם — "עוגת גבינה" → עוגות. אמין: שם המתכון אומר מה הוא. משמש בייבוא,
 *   בסריקה, בשמירה מהרשת, ובסינון החיפוש ברשת (שם יש רק שמות).
 *
 *   כשרות מהרכיבים — **הצעה בלבד**, לעולם לא נקבעת בלי אישור: טעות בכשרות היא
 *   לא טעות תצוגה. בעורך מוצג "נראה חלבי — לסמן?"; בשמירה בלי עורך (מהרשת,
 *   מסריקה) — לא משויך.
 *
 *   מונים — כמה מתכונים גלויים לי בכל קטגוריה (ציבוריים, שלי, ששותפו איתי; בלי
 *   חסימות) — לשורת הנושאים ולגיליון הסינון.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** מילים בשם המתכון לכל נושא (שם התג כפי שנזרע; מנהל ששינה שם — המילים לא יחולו). */
const CATALOG_TOPIC_WORDS = [
    'עוגות'      => ['עוגה', 'עוגת', 'עוגות', "צ'יזקייק", 'צ׳יזקייק', 'בראוניז', 'בראוני', 'מאפינס', 'מאפין', 'קאפקייק', 'קאפקייקס', 'רולדה', 'בבקה', 'באבקה'],
    'עוגיות'     => ['עוגיה', 'עוגיות', 'עוגייה', 'עוגיית', 'ביסקוויט', 'ביסקוויטים', 'רוגלך', 'מקרון', 'מקרונים', 'כעכים', 'כעך'],
    'מאפים'      => ['מאפה', 'מאפים', 'פשטידה', 'פשטידת', 'פשטידות', 'קיש', 'בורקס', "פוקאצ'ה", "פוקצ'ה", 'לחם', 'לחמניה', 'לחמניות', 'לחמנייה', 'חלה', 'חלות', 'פיצה', 'פיתה', 'פיתות', 'בייגל', 'קרואסון', 'שטרודל', 'סמבוסק', 'ג׳חנון', "ג'חנון", 'מלאווח', 'פריקסה'],
    'בצקים'      => ['בצק', 'בצקים'],
    'מנה עיקרית' => ['עוף', 'שניצל', 'קציצות', 'קציצה', 'קציצת', 'סטייק', 'צלי', 'דג', 'דגים', 'סלמון', 'פסטה', 'לזניה', 'ריזוטו', 'תבשיל', 'חמין', "צ'ולנט", 'פרגית', 'פרגיות', 'שווארמה', 'המבורגר', 'מוקפץ', 'קדרה', 'מקלובה', 'מג׳דרה', "מג'דרה", 'פאייה', 'ניוקי', 'רביולי', 'אסאדו'],
    'מנת פתיחה'  => ['מטבל', 'מטבלים', 'ממרח', 'ממרחים', "סביצ'ה", "קרפצ'יו", 'חומוס', 'מזטים', 'ברוסקטה'],
    'מרקים'      => ['מרק', 'מרקים'],
    'סלטים'      => ['סלט', 'סלטים', 'טאבולה', 'קולסלאו'],
    'תוספות'     => ['תוספת', 'תוספות', 'פירה', 'אורז', 'קוסקוס', 'פתיתים', "צ'יפס", 'קינואה', 'בורגול', 'תפוחי אדמה', 'ירקות צלויים'],
    'קינוחים'    => ['קינוח', 'קינוחים', 'מוס', 'גלידה', 'סורבה', 'פודינג', 'פאי', 'טארט', 'טירמיסו', 'פנקוטה', 'סופגניות', 'סופגניה', 'מלבי', 'קרמבו', 'טראפלס', 'כדורי שוקולד'],
    'שתייה'      => ['שייק', 'לימונדה', 'קוקטייל', 'משקה', 'סמוטי', 'ליקר'],
];

/** סמל לכל נושא — לשורת הנושאים. */
const CATALOG_TOPIC_ICONS = ['עוגות' => '🎂', 'עוגיות' => '🍪', 'מאפים' => '🥐', 'בצקים' => '🥟', 'מנה עיקרית' => '🍗', 'מנת פתיחה' => '🥙',
                             'מרקים' => '🍲', 'סלטים' => '🥗', 'תוספות' => '🍚', 'קינוחים' => '🍮', 'שתייה' => '🥤'];

/** מילה שלמה בטקסט, עם תחילית אפשרית (ו/ה/ב/ל/מ/ש/כ). מרכאות וגרש — כחלק מהמילה. */
function catalogHasWord(string $text, string $word): bool {
    $w = preg_quote($word, '/');
    return (bool) preg_match('/(?<![\p{L}\'׳])(?:[והבלמשכ]|וה|שה|וב|ול|ומ)?' . $w . '(?![\p{L}])/u', $text);
}

/** נושאים מהשם → מזהי תגים. */
function catalogGuessTopics(string $title): array {
    $ids = [];
    $byName = catalogTagIds('topic');
    foreach (CATALOG_TOPIC_WORDS as $name => $words) {
        if (!isset($byName[$name])) continue;
        foreach ($words as $w) if (catalogHasWord($title, $w)) { $ids[] = $byName[$name]; break; }
    }
    return $ids;
}

/** שם תג → מזהה, בציר אחד. */
function catalogTagIds(string $axis): array {
    static $cache = [];
    if (!isset($cache[$axis])) {
        $st = db()->prepare('SELECT name, id FROM tags WHERE axis = ?');
        $st->execute([$axis]);
        $cache[$axis] = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    }
    return $cache[$axis];
}

/**
 * הצעת כשרות מהרכיבים: 'בשרי' / 'חלבי', או null. גם וגם — לא מנחשים; לא זה ולא
 * זה — גם לא "פרווה": היעדר מילה מוכרת אינו ראיה (רכיב שלא ברשימה). חלופות
 * צמחיות ("חלב סויה", "חמאת בוטנים", "שמנת צמחית") אינן חלב.
 */
function catalogGuessKosher(array $ingredients): ?string {
    $text = ' ' . mb_strtolower(implode(' | ', array_map('strval', $ingredients))) . ' ';
    if (count(array_filter($ingredients, fn($i) => trim((string) $i) !== '')) < 2) return null;
    // תחליפים פרווה — יוצאים לפני הבדיקה
    $text = preg_replace('/חלב\s+(?:קוקוס|סויה|שקדים|אורז|שיבולת(?:\s+שועל)?|צמחי)|חמאת\s+(?:בוטנים|שקדים|קשיו|קקאו)|(?:שמנת|חמאה|גבינה)\s+צמחית|מרגרינה|חלבונ\p{L}*/u', ' ', $text) ?? $text;
    $meatWords = ['בשר', 'בקר', 'עוף', 'עופות', 'הודו', 'כבד', 'כבדי', 'נקניק', 'נקניקיות', 'שניצל', 'קציצות', 'פרגית', 'פרגיות', 'אסאדו', 'סטייק', 'טחון',
                  'כרעיים', 'שוקיים', 'כנפיים', 'צלעות', 'סלמי', 'פסטרמה', 'קבב', 'שווארמה', 'אנטריקוט', 'אווז', 'ברווז', 'כבש', 'טלה'];
    $dairyWords = ['חלב', 'חמאה', 'גבינה', 'גבינת', 'גבינות', 'שמנת', 'יוגורט', 'מוצרלה', 'פרמזן', 'לבנה', "קוטג'", 'ריקוטה', 'מסקרפונה', 'גאודה', 'פטה',
                   'צפתית', 'בולגרית', 'רוקפור'];
    $meat = false; $dairy = false;
    foreach ($meatWords as $w) if (catalogHasWord($text, $w)) { $meat = true; break; }
    foreach ($dairyWords as $w) if (catalogHasWord($text, $w)) { $dairy = true; break; }
    if ($meat && $dairy) return null;
    return $meat ? 'בשרי' : ($dairy ? 'חלבי' : null);
}

/** כמה מתכונים גלויים לי בכל תג — לשורת הנושאים ולסינון. */
function catalogCounts(?array $user): array {
    $uid = $user ? (int) $user['id'] : 0;
    $vis = $user
        ? "(r.visibility = 'public' OR r.owner_id = $uid OR EXISTS (SELECT 1 FROM recipe_shares sh WHERE sh.recipe_id = r.id AND sh.user_id = $uid))
           AND r.owner_id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = $uid UNION SELECT blocker_id FROM blocks WHERE blocked_id = $uid)
           AND r.id NOT IN (SELECT target_id FROM share_optouts WHERE kind = 'recipe' AND user_id = $uid)"
        : "r.visibility = 'public'";
    $rows = db()->query("SELECT rt.tag_id, COUNT(*) n FROM recipe_tags rt JOIN recipes r ON r.id = rt.recipe_id WHERE $vis GROUP BY rt.tag_id")->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[(int) $r['tag_id']] = (int) $r['n'];
    return $out;
}

/**
 * catalog_topic(title, topic) ב-SQL — לסינון האינדקס לפי נושא. פונקציה ולא LIKE:
 * "%קיש%" תופס גם "קישואים", "%דג%" גם "דגנים". כאן — מילה שלמה עם תחילית.
 */
function catalogRegisterSql(): void {
    static $done = false;
    if ($done) return;
    db()->sqliteCreateFunction('catalog_topic', function ($title, $topic): int {
        foreach (CATALOG_TOPIC_WORDS[(string) $topic] ?? [] as $w) if (catalogHasWord((string) $title, $w)) return 1;
        return 0;
    }, 2, PDO::SQLITE_DETERMINISTIC);
    $done = true;
}

/**
 * חד־פעמי (runOnce ב-db.php): נושא לפי השם למתכונים שאין להם אף נושא. עובד על
 * ה-PDO שמתקבל — רץ בתוך db() עצמו, לפני שהוא מוכן. מחזיר כמה מתכונים שויכו.
 */
function catalogBackfillTopics(PDO $pdo): int {
    $byName = array_map('intval', $pdo->query("SELECT name, id FROM tags WHERE axis = 'topic'")->fetchAll(PDO::FETCH_KEY_PAIR));
    $rows = $pdo->query("SELECT r.id, r.title FROM recipes r
                          WHERE NOT EXISTS (SELECT 1 FROM recipe_tags rt JOIN tags t ON t.id = rt.tag_id WHERE rt.recipe_id = r.id AND t.axis = 'topic')")->fetchAll();
    $ins = $pdo->prepare('INSERT OR IGNORE INTO recipe_tags (recipe_id, tag_id) VALUES (?, ?)');
    $n = 0;
    foreach ($rows as $r) {
        $hit = false;
        foreach (CATALOG_TOPIC_WORDS as $name => $words) {
            if (!isset($byName[$name])) continue;
            foreach ($words as $w) if (catalogHasWord((string) $r['title'], $w)) { $ins->execute([(int) $r['id'], $byName[$name]]); $hit = true; break; }
        }
        $n += (int) $hit;
    }
    return $n;
}
