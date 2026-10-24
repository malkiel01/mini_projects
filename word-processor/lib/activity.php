<?php
/**
 * יומן פעילות לכל מסמך (שלב 5, החלטה 24.10): מי פתח, ערך, שינה שם, שיתף, יצר קישור, שחזר גרסה, העביר לסל, הוריד —
 * ומתי. רואים אותו הבעלים ומי שיש לו הרשאת עריכה (בתפריט ⋯ ← "פעילות").
 *  - פתיחה / עריכה של אותו אדם בתוך ACTIVITY_MERGE מהפעם הקודמת — מעדכנת את השורה הקודמת (והיא עולה למעלה), גם כשיש
 *    ביניהן פעולות אחרות; אחרת שמירה כל שנייה, ופתיחה-עריכה-פתיחה, היו ממלאות את היומן.
 *  - נשמרות ACTIVITY_KEEP השורות האחרונות למסמך; מחיקה לצמיתות מוחקת את היומן.
 *  - רישום שנכשל לא מכשיל את הפעולה עצמה.
 */

const ACTIVITY_MERGE = ['open' => 1800, 'edit' => 600];   // שניות
const ACTIVITY_KEEP = 500;
const ACTIVITY_TEXT = [
    'create' => 'יצירה', 'open' => 'פתיחה', 'edit' => 'עריכה', 'rename' => 'שינוי שם', 'restore' => 'שחזור גרסה',
    'share' => 'שיתוף', 'unshare' => 'הסרת שיתוף', 'link' => 'בקשת קישור עריכה לזר', 'link_ok' => 'אישור קישור לזר',
    'unlink' => 'ביטול קישור לזר', 'trash' => 'העברה לסל', 'untrash' => 'שחזור מהסל', 'export' => 'הורדה', 'copy' => 'שכפול',
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

/** [{t, who, what, text, detail}] — מהחדש לישן. */
function activity_of(string $doc, int $limit = 200): array
{
    $st = db()->prepare('SELECT t, who, what, detail FROM activity WHERE doc = ? ORDER BY t DESC, id DESC LIMIT ?');
    $st->execute([$doc, max(1, min(ACTIVITY_KEEP, $limit))]);
    return array_map(fn($r) => ['t' => (int)$r['t'], 'who' => $r['who'], 'what' => $r['what'], 'text' => ACTIVITY_TEXT[$r['what']] ?? $r['what'],
        'detail' => $r['detail']], $st->fetchAll());
}
