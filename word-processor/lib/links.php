<?php
/**
 * קישור עריכה לזר (שלב 5, החלטה 4): הבעלים מבקש קישור למסמך אחד; מי שמקבל אותו עורך בלי חשבון ובלי כניסה —
 * רק את המסמך הזה, דרך guest.html והפעולות guest_* (ב-"public" של app.json).
 *  - באישור המנהל: בעלים שאינו מנהל — הקישור "ממתין" עד שהמנהל מאשר (מסך המנהל); מנהל — פעיל מיד.
 *  - קישור אחד למסמך; בקשה חדשה מחליפה את הטוקן (הקישור הקודם מפסיק לעבוד). תוקף — LINK_DAYS מהאישור.
 *  - הטוקן בחלק שאחרי # בכתובת — לא מגיע לשרת ביומני הגישה ולא ב-Referer.
 *  - הזר: שם שהוא מקליד, ומזהה אקראי מהמכשיר שלו (gid) — הנעילה וההערות לפיהם. בלי שינוי שם המסמך, בלי גרסאות,
 *    בלי שיתוף ובלי מחיקה.
 */

const LINK_DAYS = 14;

function link_row(string $doc): ?array
{
    $st = db()->prepare('SELECT * FROM links WHERE doc = ?');
    $st->execute([$doc]);
    $r = $st->fetch();
    $st->closeCursor();
    return $r ?: null;
}

/** לבעלים: {status: pending | active | expired, url (רק כשפעיל), expires_at} — או null כשאין קישור. */
function link_public(?array $r): ?array
{
    if (!$r) return null;
    $status = $r['status'] === 'active' && (int)$r['expires_at'] <= time() ? 'expired' : $r['status'];
    return ['status' => $status, 'url' => $status === 'active' ? base_url() . 'guest.html#k=' . $r['token'] : null,
        'expires_at' => (int)$r['expires_at'], 'created_at' => (int)$r['created_at']];
}

/** בקשת קישור (או חידוש): טוקן חדש. מנהל — פעיל מיד; אחר — ממתין לאישור. */
function link_request(array $row, array $acc): ?array
{
    $admin = $acc['role'] === 'admin';
    $now = time();
    db()->prepare('INSERT INTO links (doc, token, status, created_by, created_at, approved_by, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (doc) DO UPDATE SET token = excluded.token, status = excluded.status, created_by = excluded.created_by,
        created_at = excluded.created_at, approved_by = excluded.approved_by, expires_at = excluded.expires_at')
        ->execute([$row['id'], rand_id(32), $admin ? 'active' : 'pending', (int)$acc['id'], $now, $admin ? (int)$acc['id'] : null,
            $admin ? $now + LINK_DAYS * 86400 : 0]);
    wp_log('link_request', "{$row['id']} ← {$acc['id']}" . ($admin ? ' (מנהל — פעיל)' : ''));
    return link_public(link_row($row['id']));
}

function link_revoke(string $doc): void
{
    db()->prepare('DELETE FROM links WHERE doc = ?')->execute([$doc]);
    wp_log('link_revoke', $doc);
}

/** המנהל: אישור (פעיל מעכשיו ל-LINK_DAYS) או דחייה (נמחק). */
function link_decide(string $doc, bool $approve, array $admin): void
{
    $r = link_row($doc);
    if (!$r) throw new ApiError('הקישור לא נמצא', 'not_found', 404);
    if (!$approve) {
        link_revoke($doc);
        return;
    }
    db()->prepare("UPDATE links SET status = 'active', approved_by = ?, expires_at = ? WHERE doc = ?")
        ->execute([(int)$admin['id'], time() + LINK_DAYS * 86400, $doc]);
    wp_log('link_approve', "$doc (על ידי {$admin['id']})");
}

/** למסך המנהל: כל הקישורים (הממתינים קודם), עם שם המסמך והבעלים. */
function links_all(): array
{
    $rows = db()->query("SELECT l.doc, l.status, l.created_at, l.expires_at, d.title, a.name, a.email FROM links l
        JOIN docs d ON d.id = l.doc AND d.deleted_at IS NULL LEFT JOIN accounts a ON a.id = l.created_by
        ORDER BY l.status = 'pending' DESC, l.created_at DESC LIMIT 500")->fetchAll();
    return array_map(fn($r) => ['doc' => $r['doc'], 'title' => $r['title'], 'owner' => (string)($r['name'] ?: $r['email'] ?: ''),
        'status' => link_public($r + ['token' => ''])['status'], 'created_at' => (int)$r['created_at'], 'expires_at' => (int)$r['expires_at']], $rows);
}

/**
 * הזר: הטוקן → המסמך, רק כשהקישור פעיל ובתוקף והמסמך לא בסל. ממתין — 403 עם הסבר; כל השאר — "לא בתוקף"
 * (לא מגלים אם היה פעם קישור כזה).
 */
function guest_doc($token): array
{
    $bad = new ApiError('הקישור לא בתוקף. בקשו מבעל המסמך קישור חדש.', 'link_invalid', 404);
    if (!is_string($token) || !preg_match('/^[a-z0-9]{32}$/', $token)) throw $bad;
    $st = db()->prepare('SELECT * FROM links WHERE token = ?');
    $st->execute([$token]);
    $l = $st->fetch();
    $st->closeCursor();
    if (!$l) throw $bad;
    if ($l['status'] === 'pending') throw new ApiError('הקישור ממתין לאישור מנהל העורך. נסו שוב אחר כך.', 'link_pending', 403);
    if ($l['status'] !== 'active' || (int)$l['expires_at'] <= time()) throw $bad;
    $st = db()->prepare('SELECT * FROM docs WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$l['doc']]);
    $row = $st->fetch();
    $st->closeCursor();
    if (!$row) throw $bad;
    return $row;
}

/** הזהות של הזר: [uid, שם לתצוגה]. השם — מה שהקליד (חובה); uid — מהטוקן ומהמזהה שבמכשיר שלו. */
function guest_identity(string $token, $gid, $name): array
{
    $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
    if (!is_string($gid) || !preg_match('/^[a-z0-9]{8,40}$/', $gid)) throw new ApiError('בקשה לא תקינה', 'bad_guest');
    if ($name === '' || mb_strlen($name) > 60) throw new ApiError('צריך שם (עד 60 תווים)', 'bad_name');
    return ['g:' . substr(hash('sha256', $token . '|' . $gid), 0, 20), $name . ' (אורח)'];
}
