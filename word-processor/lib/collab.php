<?php
/**
 * עריכה משותפת (שלב 5, החלטה 2 — על השרת הקיים, בעיכוב של כשנייה). השיטה של prosemirror-collab: השרת הוא הסמכות
 * על סדר השינויים. כל לקוח שולח את הצעדים (ProseMirror steps) שלו מול מספר הגרסה שהוא מכיר (v); השרת מקבל רק כשהוא
 * מעודכן, ואחרת הלקוח מושך את מה שחסר לו, מעביר את שלו מעליו ושולח שוב. כך כולם מגיעים לאותו מסמך.
 *
 *  - עם כל שליחה — גם המסמך המלא אחרי הצעדים (json): נשמר ב-docs כרגיל (doc_save), כך שהמסמך שבשרת תמיד עדכני, גם
 *    כשכולם סוגרים. הצעדים נשמרים רק כדי שהאחרים ימשכו אותם (COLLAB_KEEP האחרונים).
 *  - epoch: שמירה מחוץ לעריכה המשותפת (שחזור גרסה, הטמעה, קישור לזר, לשונית ישנה) משנה את docs.version — ואז מתחיל
 *    "עידן" חדש: הצעדים הישנים נמחקים, והלקוחות טוענים את המסמך מחדש.
 *  - מעיר (comment): רק צעדי הערות (סימון comment, attrs.comments) — ובנוסף המסמך שהוא שולח נבדק כמו ב-my_save.
 *  - נוכחות (presence): כל משיכה מעדכנת את מי במסמך ואיפה הבחירה שלו. כותבים בשיטה הישנה (נעילה) לא נכנסים כשיש
 *    עורכים כאן (lock_take), ועורכים כאן לא כותבים כשמישהו מחזיק נעילה ישנה — הנעילה היא הגיבוי.
 */

const COLLAB_KEEP = 500;     // צעדים שנשמרים למשיכה; מי שמאחור יותר — טוען מחדש
const COLLAB_MAX = 2000;     // צעדים בשליחה אחת

/**
 * המצב של המסמך. עידן חדש רק כשהמסמך נשמר מבחוץ (docs.version שונה ממה שהשליחה האחרונה רשמה). הבדיקה — על שתי
 * הטבלאות מהמסד, ואיפוס — רק בתוך עסקה נעולה: שורת מסמך שנקראה בתחילת הבקשה כבר ישנה כששליחה של מישהו אחר נגמרה
 * בינתיים, ואיפוס בגללה היה מוחק את הצעדים של כולם. $locked — כבר בתוך BEGIN IMMEDIATE (collab_push).
 */
function collab_state(array $row, bool $locked = false): array
{
    $read = function () use ($row) {
        $st = db()->prepare('SELECT c.*, d.version AS cur FROM docs d LEFT JOIN collab c ON c.doc = d.id WHERE d.id = ?');
        $st->execute([$row['id']]);
        $c = $st->fetch();
        $st->closeCursor();
        return $c;
    };
    $c = $read();
    if ($c && $c['epoch'] !== null && (int)$c['doc_version'] === (int)$c['cur']) return $c;
    $pdo = db();
    if (!$locked) $pdo->exec('BEGIN IMMEDIATE');
    try {
        $c = $read();
        if (!$c || $c['epoch'] === null || (int)$c['doc_version'] !== (int)$c['cur']) {
            // מסמך חדש לעריכה משותפת, או שנשמר מבחוץ — עידן חדש
            $c = ['doc' => $row['id'], 'epoch' => rand_id(12), 'v' => 0, 'doc_version' => (int)($c['cur'] ?? $row['version'])];
            $pdo->prepare('DELETE FROM collab_steps WHERE doc = ?')->execute([$row['id']]);
            $pdo->prepare('INSERT OR REPLACE INTO collab (doc, epoch, v, doc_version) VALUES (?, ?, ?, ?)')->execute([$c['doc'], $c['epoch'], 0, $c['doc_version']]);
        }
        if (!$locked) $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        if (!$locked) $pdo->exec('ROLLBACK');
        throw $e;
    }
    return $c;
}

/** נעילה ישנה של מישהו אחר (הטמעה / קישור לזר / לשונית ישנה) — או null. */
function collab_blocked(string $doc, string $uid): ?array
{
    $l = lock_get($doc);
    return $l && $l['user_id'] !== $uid ? lock_public($l) : null;
}

/** צעד שמעיר רשאי לשלוח: הוספה / הסרה של סימון הערה, ושינוי השרשורים (attrs.comments). */
function collab_comment_step(array $s): bool
{
    $t = $s['stepType'] ?? '';
    if ($t === 'addMark' || $t === 'removeMark') return ($s['mark']['type'] ?? '') === 'comment';
    return $t === 'docAttr' && ($s['attr'] ?? '') === 'comments';
}

/**
 * שליחה: {epoch, v, client, steps[], json}. מחזיר {ok: true, v} — או {ok: false} כשמישהו הקדים (מושכים ושולחים
 * שוב), או {reload: true} בעידן אחר.
 */
function collab_push(array $row, string $role, array $in, string $uid, string $uname): array
{
    $client = (string)($in['client'] ?? '');
    $steps = $in['steps'] ?? null;
    if (!preg_match('/^[a-z0-9]{6,40}$/', $client) || !is_array($steps) || !$steps || count($steps) > COLLAB_MAX || !array_is_list($steps)
        || !is_array($in['json'] ?? null)) throw new ApiError('בקשה לא תקינה', 'bad_request');
    foreach ($steps as $s) {
        if (!is_array($s) || !is_string($s['stepType'] ?? null)) throw new ApiError('בקשה לא תקינה', 'bad_request');
        if ($role === 'comment' && !collab_comment_step($s)) throw new ApiError('ההרשאה שלך במסמך: הערות בלבד — אפשר להוסיף הערות, לא לשנות את הטקסט', 'forbidden', 403);
    }
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');   // שליחה אחת בכל רגע — הסדר של הצעדים
    try {
        $st = $pdo->prepare('SELECT * FROM docs WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$row['id']]);
        $row = $st->fetch() ?: throw new ApiError('המסמך לא נמצא', 'not_found', 404);
        $st->closeCursor();
        if ($l = collab_blocked($row['id'], $uid)) throw new ApiError('המסמך פתוח לעריכה אצל ' . $l['by'], 'locked', 423);
        $c = collab_state($row, true);
        if ((string)($in['epoch'] ?? '') !== $c['epoch']) { $pdo->exec('COMMIT'); return ['ok' => false, 'reload' => true]; }
        if ((int)($in['v'] ?? -1) !== (int)$c['v']) { $pdo->exec('COMMIT'); return ['ok' => false, 'v' => (int)$c['v']]; }
        $json = $in['json'];
        if ($role === 'comment') {
            unset($json['attrs']['page']);
            if (!only_comments_changed(doc_json($row), $json)) throw new ApiError('ההרשאה שלך במסמך: הערות בלבד — אפשר להוסיף הערות, לא לשנות את הטקסט', 'forbidden', 403);
        }
        $saved = doc_save($row, $json, null, $uid, $uname, $row['version'], null, true);
        $v = (int)$c['v'];
        $ins = $pdo->prepare('INSERT INTO collab_steps (doc, v, step, client) VALUES (?, ?, ?, ?)');
        foreach ($steps as $s) $ins->execute([$row['id'], ++$v, json_encode($s, JSON_UNESCAPED_UNICODE), $client]);
        $pdo->prepare('UPDATE collab SET v = ?, doc_version = ? WHERE doc = ?')->execute([$v, (int)$saved['version'], $row['id']]);
        $pdo->prepare('DELETE FROM collab_steps WHERE doc = ? AND v <= ?')->execute([$row['id'], $v - COLLAB_KEEP]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    return ['ok' => true, 'v' => $v, 'version' => (int)$saved['version'], 'updated_at' => (int)$saved['updated_at']];
}

/**
 * משיכה: {epoch, since, client, sel} → {v, steps: [[צעד, לקוח]…], others, lock} — או {reload: true} (עידן אחר, או
 * מאחור יותר ממה שנשמר). גם הדופק של הנוכחות.
 */
function collab_pull(array $row, string $role, array $in, string $uid, string $uname): array
{
    $client = (string)($in['client'] ?? '');
    if (!preg_match('/^[a-z0-9]{6,40}$/', $client)) throw new ApiError('בקשה לא תקינה', 'bad_request');
    $now = time();
    // הבחירה: [from, to, הגרסה שאליה המיקומים שייכים]; בלי — נשארת הקודמת (ללקוח יש צעדים שלא אושרו)
    $sel = is_array($in['sel'] ?? null) && count($in['sel']) === 3 ? json_encode(array_map('intval', array_values($in['sel']))) : null;
    db()->prepare('INSERT INTO presence (doc, client, user_id, user_name, role, sel, beat) VALUES (?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (doc, client) DO UPDATE SET user_id = excluded.user_id, user_name = excluded.user_name, role = excluded.role,
        sel = CASE WHEN ? IS NULL THEN presence.sel ELSE excluded.sel END, beat = excluded.beat')
        ->execute([$row['id'], $client, $uid, $uname, $role, $sel ?? '', $now, $sel]);
    if (random_int(1, 100) === 1) db()->prepare('DELETE FROM presence WHERE beat < ?')->execute([$now - 3600]);
    $c = collab_state($row);
    $since = (int)($in['since'] ?? -1);
    if ((string)($in['epoch'] ?? '') !== $c['epoch'] || $since > (int)$c['v'] || $since < 0) return ['reload' => true];
    $steps = [];
    if ($since < (int)$c['v']) {
        $st = db()->prepare('SELECT step, client FROM collab_steps WHERE doc = ? AND v > ? ORDER BY v');
        $st->execute([$row['id'], $since]);
        $steps = array_map(fn($r) => [json_decode($r['step'], true), $r['client']], $st->fetchAll());
        if (count($steps) !== (int)$c['v'] - $since) return ['reload' => true];   // נמחקו כבר (COLLAB_KEEP)
    }
    $st = db()->prepare('SELECT client, user_id, user_name, role, sel FROM presence WHERE doc = ? AND client != ? AND beat > ? ORDER BY user_name');
    $st->execute([$row['id'], $client, $now - PRESENCE_SECONDS]);
    $others = array_map(fn($r) => ['client' => $r['client'], 'name' => $r['user_name'], 'role' => $r['role'], 'me' => $r['user_id'] === $uid,
        'sel' => $r['sel'] !== '' ? json_decode($r['sel'], true) : null], $st->fetchAll());
    return ['v' => (int)$c['v'], 'steps' => $steps, 'others' => $others, 'lock' => collab_blocked($row['id'], $uid)];
}

function collab_leave(string $doc, $client): void
{
    db()->prepare('DELETE FROM presence WHERE doc = ? AND client = ?')->execute([$doc, (string)$client]);
}
