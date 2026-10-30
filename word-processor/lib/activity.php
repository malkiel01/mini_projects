<?php
/**
 * יומן פעילות לכל מסמך (שלב 5, החלטה 24.10): מי פתח, ערך, שינה שם, שיתף, יצר קישור, שחזר גרסה, העביר לסל, הוריד —
 * ומתי. רואים אותו הבעלים ומי שיש לו הרשאת עריכה (בתפריט ⋯ ← "פעילות").
 *  - פתיחה / עריכה של אותו אדם בתוך ACTIVITY_MERGE מהפעם הקודמת — מעדכנת את השורה הקודמת (והיא עולה למעלה), גם כשיש
 *    ביניהן פעולות אחרות; אחרת שמירה כל שנייה, ופתיחה-עריכה-פתיחה, היו ממלאות את היומן.
 *  - נשמרות ACTIVITY_KEEP השורות האחרונות למסמך; מחיקה לצמיתות מוחקת את היומן.
 *  - הערות (activity_comments, מ-doc_save): הערה חדשה, תשובה, "נפתר" / פתיחה מחדש ומחיקה — עם תחילת הטקסט. שמירה
 *    שרק הערות השתנו בה אינה "עריכה".
 *  - רישום שנכשל לא מכשיל את הפעולה עצמה.
 */

const ACTIVITY_MERGE = ['open' => 1800, 'edit' => 600];   // שניות
const ACTIVITY_KEEP = 500;
const ACTIVITY_TEXT = [
    'create' => 'יצירה', 'open' => 'פתיחה', 'edit' => 'עריכה', 'rename' => 'שינוי שם', 'restore' => 'שחזור גרסה',
    'share' => 'שיתוף', 'unshare' => 'הסרת שיתוף', 'link' => 'בקשת קישור עריכה לזר', 'link_ok' => 'אישור קישור לזר',
    'unlink' => 'ביטול קישור לזר', 'trash' => 'העברה לסל', 'untrash' => 'שחזור מהסל', 'export' => 'הורדה', 'copy' => 'שכפול',
    'comment' => 'הערה', 'reply' => 'תשובה להערה', 'resolve' => 'הערה סומנה כפתורה', 'reopen' => 'הערה נפתחה מחדש', 'uncomment' => 'מחיקת הערה',
    'merge' => 'עדכון מ-Word',
];

function activity(string $doc, string $uid, string $uname, string $what, string $detail = ''): void
{
    try {
        $now = time();
        if (isset(ACTIVITY_MERGE[$what])) {
            $st = db()->prepare('SELECT id FROM activity WHERE doc = ? AND who_id = ? AND what = ? AND t > ? ORDER BY t DESC LIMIT 1');
            $st->execute([$doc, $uid, $what, $now - ACTIVITY_MERGE[$what]]);
            $last = $st->fetch();
            $st->closeCursor();
            if ($last) {
                db()->prepare('UPDATE activity SET t = ?, who = ? WHERE id = ?')->execute([$now, $uname, $last['id']]);
                return;
            }
        }
        db()->prepare('INSERT INTO activity (doc, t, who_id, who, what, detail) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$doc, $now, $uid, mb_substr($uname, 0, 120), $what, mb_substr($detail, 0, 200)]);
        if (random_int(1, 20) === 1) {
            db()->prepare('DELETE FROM activity WHERE doc = ? AND id <= (SELECT id FROM activity WHERE doc = ? ORDER BY id DESC LIMIT 1 OFFSET ?)')
                ->execute([$doc, $doc, ACTIVITY_KEEP]);
        }
    } catch (Throwable $e) {
        error_log('word-processor activity: ' . $e->getMessage());
    }
}

/**
 * הערות שהשתנו בשמירה (attrs.comments לפני / אחרי): לכל הערה חדשה, תשובה חדשה, שינוי "נפתר" והערה שנמחקה — שורה ביומן,
 * על שם מי ששמר. מחזיר true אם רק ההערות השתנו (ואז השמירה אינה נרשמת כ"עריכה").
 */
function activity_comments(string $doc, string $uid, string $uname, array $old, array $new): bool
{
    $oc = is_array($old['attrs']['comments'] ?? null) ? $old['attrs']['comments'] : [];
    $nc = is_array($new['attrs']['comments'] ?? null) ? $new['attrs']['comments'] : [];
    if ($oc === $nc) return false;
    $snip = function ($c): string {
        $t = trim(preg_replace('/\s+/u', ' ', (string)(is_array($c) ? ($c['text'] ?? '') : '')));
        return mb_strlen($t) > 60 ? mb_substr($t, 0, 59) . '…' : $t;
    };
    foreach ($nc as $id => $c) {
        if (!is_array($c)) continue;
        $o = $oc[$id] ?? null;
        $replies = is_array($c['replies'] ?? null) ? array_values(array_filter($c['replies'], 'is_array')) : [];
        if (!is_array($o)) { activity($doc, $uid, $uname, 'comment', $snip($c)); continue; }
        $before = is_array($o['replies'] ?? null) ? count(array_filter($o['replies'], 'is_array')) : 0;
        foreach (array_slice($replies, $before) as $r) activity($doc, $uid, $uname, 'reply', $snip($r));
        if (!empty($c['done']) !== !empty($o['done'])) activity($doc, $uid, $uname, empty($c['done']) ? 'reopen' : 'resolve', $snip($c));
    }
    foreach ($oc as $id => $c) if (is_array($c) && !is_array($nc[$id] ?? null)) activity($doc, $uid, $uname, 'uncomment', $snip($c));
    return function_exists('only_comments_changed') && only_comments_changed($old, $new);
}

/** [{t, who, what, text, detail}] — מהחדש לישן. */
function activity_of(string $doc, int $limit = 200): array
{
    $st = db()->prepare('SELECT t, who, what, detail FROM activity WHERE doc = ? ORDER BY t DESC, id DESC LIMIT ?');
    $st->execute([$doc, max(1, min(ACTIVITY_KEEP, $limit))]);
    return array_map(fn($r) => ['t' => (int)$r['t'], 'who' => $r['who'], 'what' => $r['what'], 'text' => ACTIVITY_TEXT[$r['what']] ?? $r['what'],
        'detail' => $r['detail']], $st->fetchAll());
}
