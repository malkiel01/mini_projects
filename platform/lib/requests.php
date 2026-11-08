<?php
/**
 * בקשות לאפליקציות בחנות (PF-001, platform/spec/store-requests.html).
 *
 * לכל אפליקציה בחנות יש מצב (app_catalog.mode): instant — לחיצה מוסיפה מיד (get_app, כמו קודם); request —
 * לחיצה שולחת בקשה, והמנהל מאשר או דוחה. בקשה היא רק תור להחלטה, לא הרשאה: ההרשאה תמיד ב-grants, ומה שפותח
 * אותה (אישור, מתג "רואה", הזמנה, קטגוריה) סוגר את הבקשה.
 *
 * הסגירה נעשית בקריאה, לא בכתיבה (requests_settle): יש כמה מקומות שמשנים את מה שמשתמש רואה בלי לעבור דרך
 * grants_write, ובמקום לחבר כל אחד מהם — כל בקשה ממתינה נבדקת מול המצב הנוכחי כשקוראים אותה.
 *
 * הטבלאות נוצרות ב-access_migrate (lib/access.php).
 */

declare(strict_types=1);

const REQUEST_RETRY_DAYS = 7;       // אחרי דחייה — מותר לבקש שוב (הכרעה 9.2)
const REQUEST_MAX_PENDING = 10;     // בקשות ממתינות למשתמש
const REQUEST_QUIET_SECONDS = 600;  // בקשה חוזרת אחרי ביטול — בלי התראה נוספת

/**
 * סוגר בקשות ממתינות שכבר הוכרעו בפועל: נפתחה לו → אושרה; חסימה מפורשת → נדחתה; האפליקציה לא בחנות /
 * מוסתרת / תוסף / restricted, או שהחשבון נחסם → בוטלה. $userIds = null — לכל המשתמשים (המנהל).
 * $seen — לסמן את ההחלטות כנראו (הזמנה: הוא כבר ראה מה נפתח לו, אין צורך בטוסט).
 */
function requests_settle(?array $userIds = null, bool $seen = false): void
{
    access_migrate();
    $sql = "SELECT r.id, r.user_id, r.app_id, u.role, u.status
              FROM app_requests r JOIN users u ON u.id = r.user_id
             WHERE r.status = 'pending'";
    if ($userIds !== null) {
        if (!$userIds) return;
        $sql .= ' AND r.user_id IN (' . implode(',', array_map('intval', $userIds)) . ')';
    }
    $rows = db()->query($sql)->fetchAll();
    if (!$rows) return;

    $apps = array_column(apps_effective(), null, 'id');
    $grants = [];
    $now = now_iso();
    $upd = db()->prepare("UPDATE app_requests SET status = ?, decided_at = ?, decided_by = NULL, via_block = ?, seen_at = ?
                           WHERE id = ? AND status = 'pending'");
    foreach ($rows as $r) {
        $uid = (int)$r['user_id'];
        $app = $apps[$r['app_id']] ?? null;
        $g = $grants[$uid] ??= grants_of($uid);
        if ($r['status'] === 'blocked') $to = ['cancelled', 0];
        elseif ($app && ($r['role'] === 'admin' ? !$app['hidden'] && !$app['plugin'] : can_see($app, $g))) $to = ['approved', 0];
        elseif (($g['apps'][$r['app_id']] ?? null) === false) $to = ['rejected', 1];
        elseif (!$app || !in_catalog($app)) $to = ['cancelled', 0];
        else continue;
        $upd->execute([$to[0], $now, $to[1], $seen || $to[0] === 'cancelled' ? $now : null, $r['id']]);
    }
}

/** מתי מותר לבקש שוב אחרי הדחייה הזו. דחייה שנסגרה לבד (decided_by NULL) או בחסימה — אין המתנה. */
function request_retry_at(array $r): ?string
{
    if ($r['status'] !== 'rejected' || $r['decided_by'] === null || (int)$r['via_block'] === 1 || !$r['decided_at']) return null;
    return gmdate('Y-m-d\TH:i:s\Z', strtotime($r['decided_at']) + REQUEST_RETRY_DAYS * 86400);
}

/** הבקשה האחרונה של המשתמש לכל אפליקציה: [app_id => row]. */
function requests_latest(int $userId): array
{
    $st = db()->prepare('SELECT * FROM app_requests r WHERE user_id = ?
                            AND id = (SELECT MAX(id) FROM app_requests r2 WHERE r2.user_id = r.user_id AND r2.app_id = r.app_id)');
    $st->execute([$userId]);
    return array_column($st->fetchAll(), null, 'app_id');
}

/** מה מוצג בשורת החנות (4.1): {status: pending|rejected, at, retry_at?} — או null, אפשר לבקש. */
function request_view(?array $r): ?array
{
    if (!$r) return null;
    if ($r['status'] === 'pending') return ['status' => 'pending', 'at' => $r['created_at']];
    $retry = request_retry_at($r);
    return $retry ? ['status' => 'rejected', 'at' => $r['decided_at'], 'retry_at' => $retry] : null;
}

/** החלטות שהמשתמש עוד לא ראה (4.3) — טוסט אחד בטעינה הבאה, ואז requests_seen. */
function requests_decisions(int $userId, array $apps): array
{
    $st = db()->prepare("SELECT id, app_id, status FROM app_requests
                          WHERE user_id = ? AND status IN ('approved','rejected') AND seen_at IS NULL ORDER BY id");
    $st->execute([$userId]);
    $names = array_column($apps, 'name', 'id');
    return array_map(fn($r) => ['id' => (int)$r['id'], 'app' => $r['app_id'], 'name' => $names[$r['app_id']] ?? $r['app_id'],
        'status' => $r['status']], $st->fetchAll());
}

/**
 * בקשה חדשה (get_app באפליקציה במצב בקשה). מחזיר {created, notify}. כבר ממתינה — created=false, בלי כפילות.
 * נדחתה ועוד בהמתנה — 409; יותר מדי ממתינות — 429.
 */
function request_create(int $userId, string $appId): array
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $latest = requests_latest($userId)[$appId] ?? null;
        if ($latest && $latest['status'] === 'pending') { $pdo->exec('COMMIT'); return ['created' => false, 'notify' => false]; }
        $retry = $latest ? request_retry_at($latest) : null;
        if ($retry && $retry > now_iso()) {
            $d = (new DateTimeImmutable($retry))->setTimezone(new DateTimeZone('Asia/Jerusalem'))->format('j.n.Y');
            throw new ApiError("המנהל לא אישר את הבקשה. אפשר לבקש שוב מ-$d", 409);
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM app_requests WHERE user_id = ? AND status = 'pending'");
        $st->execute([$userId]);
        if ((int)$st->fetchColumn() >= REQUEST_MAX_PENDING) {
            throw new ApiError('יש לך כבר ' . REQUEST_MAX_PENDING . ' בקשות ממתינות — חכו לתשובה', 429);
        }
        // ביטל וביקש שוב תוך 10 דקות — המנהל כבר קיבל התראה על הבקשה הזו
        $notify = !($latest && $latest['status'] === 'cancelled' && $latest['decided_at']
            && strtotime($latest['decided_at']) > time() - REQUEST_QUIET_SECONDS);
        $pdo->prepare("INSERT INTO app_requests (user_id, app_id, status, created_at) VALUES (?, ?, 'pending', ?)")
            ->execute([$userId, $appId, now_iso()]);
        $pdo->exec('COMMIT');
        return ['created' => true, 'notify' => $notify];
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/**
 * סוגר בקשה ממתינה של המשתמש לאפליקציה. approved — הוא הוריד בעצמו (מצב מיידי); cancelled — ביטל.
 * מסומנת כנראתה: הוא עשה את זה בעצמו, אין צורך בטוסט.
 */
function request_close_own(int $userId, string $appId, string $status): void
{
    $now = now_iso();
    db()->prepare("UPDATE app_requests SET status = ?, decided_at = ?, decided_by = NULL, seen_at = ?
                    WHERE user_id = ? AND app_id = ? AND status = 'pending'")->execute([$status, $now, $now, $userId, $appId]);
}

/**
 * הבקשות למנהל: הממתינות (אחרי סגירה בקריאה), רק לאפליקציות שבמצב בקשה — במצב מיידי הן מוסתרות ולא נספרות
 * (השורות נשארות וחוזרות אם האפליקציה תחזור למצב בקשה). $historyDays — גם החלטות מהימים האחרונים.
 */
function requests_for_admin(int $historyDays = 0): array
{
    requests_settle();
    $apps = array_column(apps_effective(), null, 'id');
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - $historyDays * 86400);
    $st = db()->prepare("SELECT r.id, r.user_id, r.app_id, r.status, r.created_at, r.decided_at, r.decided_by, r.via_block,
                                u.display_name AS user_name, u.username, d.display_name AS decided_name
                           FROM app_requests r JOIN users u ON u.id = r.user_id LEFT JOIN users d ON d.id = r.decided_by
                          WHERE r.status = 'pending'" . ($historyDays > 0 ? ' OR r.decided_at >= ?' : '') . "
                          ORDER BY r.status = 'pending' DESC, COALESCE(r.decided_at, r.created_at) DESC");
    $st->execute($historyDays > 0 ? [$since] : []);
    $out = [];
    foreach ($st as $r) {
        $app = $apps[$r['app_id']] ?? null;
        if ($r['status'] === 'pending' && !($app && in_catalog($app) && $app['store_mode'] === 'request')) continue;
        $out[] = [
            'id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'user_name' => $r['user_name'], 'username' => $r['username'],
            'app' => $r['app_id'], 'app_name' => $app['name'] ?? $r['app_id'], 'status' => $r['status'],
            'created_at' => $r['created_at'], 'decided_at' => $r['decided_at'],
            'decided_by' => $r['decided_name'], 'blocked' => (bool)$r['via_block'],
        ];
    }
    return $out;
}

/** {id, decision: approve|reject|block} — שורה שכבר לא ממתינה → 409. */
function request_decide(array $body, array $admin): void
{
    $decision = $body['decision'] ?? '';
    if (!in_array($decision, ['approve', 'reject', 'block'], true)) throw new ApiError('החלטה לא מוכרת', 422);
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $st = $pdo->prepare('SELECT * FROM app_requests WHERE id = ?');
        $st->execute([(int)($body['id'] ?? 0)]);
        $r = $st->fetch();
        if (!$r) throw new ApiError('הבקשה לא נמצאה', 404);
        if ($r['status'] !== 'pending') throw new ApiError('הבקשה כבר טופלה', 409);
        $uid = (int)$r['user_id'];
        if ($decision !== 'reject') {
            grants_write($uid, ['categories' => [], 'groups' => [], 'apps' => [$r['app_id'] => $decision === 'approve']], false);
        }
        $pdo->prepare('UPDATE app_requests SET status = ?, decided_at = ?, decided_by = ?, via_block = ? WHERE id = ?')
            ->execute([$decision === 'approve' ? 'approved' : 'rejected', now_iso(), (int)$admin['id'], $decision === 'block' ? 1 : 0, $r['id']]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}
