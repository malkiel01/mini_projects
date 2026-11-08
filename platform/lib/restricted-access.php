<?php
/**
 * 🔒 גישות לאפליקציות חסומות — מסך מעקב בממשק המנהל (⚙️ מערכת ← הגדרות).
 *
 * כל הרשאה ישירה (grants: kind='app', allowed=1) לאפליקציה restricted. restricted נפתחת רק באישור ישיר של המנהל,
 * ולכן כל שורה כאן היא החלטה של המנהל — או, לפני #1277, הרשאה שנכתבה דרך החנות (get_app) בלי שהתכוון. המנהל
 * עובר על השורות: "להשאיר" (נשמר כנבדקה, עם מי ומתי) או "לבטל" (ההרשאה הישירה נמחקת).
 *
 * שתי קבוצות: ממתינות להחלטה (לא נבדקו) — והן שנספרות במונה; ונבדקו. אין בטבלת grants עמודת מקור, ולכן
 * "בחנות לכולם" (שורה ב-app_catalog) הוא רק רמז שההרשאה נוצרה דרך הפרצה.
 *
 * הוחל כמסך זמני (#1279, tmp-restricted-audit); הפך לקבוע בבקשת בעל המערכת (2026-11-08). ההחלטות שנשמרו
 * בטבלה הישנה tmp_restricted_audit עוברות לכאן במיגרציה.
 */

declare(strict_types=1);

require_once __DIR__ . '/access.php';

function restricted_access_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS restricted_access_review (
        user_id    INTEGER NOT NULL,
        app_id     TEXT NOT NULL,
        decided_at TEXT NOT NULL,
        decided_by INTEGER NOT NULL,
        PRIMARY KEY (user_id, app_id)
    )');
    // מהמסך הזמני: ההחלטות עוברות, והטבלה הישנה נמחקת. בטרנזקציה — שתי בקשות במקביל לא יעבירו פעמיים.
    $old = fn() => (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'tmp_restricted_audit'")->fetchColumn();
    if ($old()) {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            if ($old()) {
                $pdo->exec('INSERT OR IGNORE INTO restricted_access_review (user_id, app_id, decided_at, decided_by)
                            SELECT user_id, app_id, decided_at, decided_by FROM tmp_restricted_audit');
                $pdo->exec('DROP TABLE tmp_restricted_audit');
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }
}

/** האפליקציות restricted (לא תוספים), לפי מזהה. */
function restricted_access_apps(): array
{
    $out = [];
    foreach (apps_effective() as $a) if ($a['restricted'] && !$a['plugin']) $out[$a['id']] = $a;
    return $out;
}

/** כל ההרשאות הישירות לאפליקציות restricted — קודם הממתינות, אחר כך מה שנבדק (האחרון קודם). */
function restricted_access_rows(): array
{
    restricted_access_migrate();
    // "נבדקה" שייכת להרשאה מסוימת. הרשאה שבוטלה ממקום אחר (מתג "רואה", הזמנה, גיליון המשתמש) — הסימון שלה נמחק,
    // כך שאם תינתן שוב היא חוזרת ל"⚠️ ממתינות" ולא מופיעה כנבדקה בלי שמישהו בדק אותה.
    db()->exec("DELETE FROM restricted_access_review WHERE NOT EXISTS (SELECT 1 FROM grants g
                 WHERE g.user_id = restricted_access_review.user_id AND g.kind = 'app'
                   AND g.target = restricted_access_review.app_id AND g.allowed = 1)");
    $apps = restricted_access_apps();
    if (!$apps) return [];
    $in = implode(',', array_fill(0, count($apps), '?'));
    $st = db()->prepare("SELECT g.user_id, g.target AS app, u.display_name, u.username, u.email, u.role, u.status,
                                k.decided_at, d.display_name AS decided_name
                           FROM grants g
                           JOIN users u ON u.id = g.user_id
                      LEFT JOIN restricted_access_review k ON k.user_id = g.user_id AND k.app_id = g.target
                      LEFT JOIN users d ON d.id = k.decided_by
                          WHERE g.kind = 'app' AND g.allowed = 1 AND g.target IN ($in)
                       ORDER BY k.decided_at IS NOT NULL, k.decided_at DESC, g.target, u.display_name");
    $st->execute(array_keys($apps));
    $rows = [];
    $grants = [];
    foreach ($st as $r) {
        $app = $apps[$r['app']];
        $groups = ($grants[$r['user_id']] ??= grants_of((int)$r['user_id']))['groups'];
        $rows[] = [
            'user_id' => (int)$r['user_id'],
            'display_name' => $r['display_name'],
            'username' => $r['username'],
            'email' => $r['email'],
            'role' => $r['role'],
            'status' => $r['status'],
            'app' => $app['id'],
            'app_name' => $app['name'],
            'app_icon' => $app['icon'],
            // רמז בלבד: רשומה בחנות — ההרשאה כנראה נוצרה ב-get_app לפני #1277
            'catalog' => (bool)$app['catalog'],
            // גם בלי ההרשאה הישירה הוא ייכנס — דרך קבוצה שלמה שהוקצתה לו (can_see)
            'via_group' => (bool)array_filter($app['placements'], fn($p) => $p['group'] !== null && !empty($groups[$p['group']])),
            'reviewed_at' => $r['decided_at'],
            'reviewed_by' => $r['decided_name'],
        ];
    }
    return $rows;
}

/** כמה ממתינות להחלטה — המונה על הכפתור שפותח את המסך. */
function restricted_access_pending(): int
{
    return count(array_filter(restricted_access_rows(), fn($r) => $r['reviewed_at'] === null));
}

/** [{user_id, app, decision: keep|revoke}] — הכול או כלום. רק אפליקציה restricted. */
function restricted_access_decide(array $list, array $admin): array
{
    restricted_access_migrate();
    if (!$list || count($list) > 1000) throw new ApiError('בקשה לא תקינה', 400);
    $apps = restricted_access_apps();
    $pdo = db();
    $pdo->beginTransaction();
    $done = ['keep' => 0, 'revoke' => 0];
    try {
        foreach ($list as $d) {
            $uid = is_array($d) && is_int($d['user_id'] ?? null) ? $d['user_id'] : 0;
            $app = is_array($d) && is_string($d['app'] ?? null) ? $d['app'] : '';
            $what = is_array($d) ? ($d['decision'] ?? '') : '';
            // המסך לא נוגע בשום הרשאה אחרת
            if ($uid <= 0 || !isset($apps[$app]) || !in_array($what, ['keep', 'revoke'], true)) throw new ApiError('בקשה לא תקינה', 400);
            if ($what === 'revoke') {
                $pdo->prepare("DELETE FROM grants WHERE user_id = ? AND kind = 'app' AND target = ? AND allowed = 1")->execute([$uid, $app]);
                $pdo->prepare('DELETE FROM restricted_access_review WHERE user_id = ? AND app_id = ?')->execute([$uid, $app]);
            } else {
                $pdo->prepare('INSERT INTO restricted_access_review (user_id, app_id, decided_at, decided_by) VALUES (?,?,?,?)
                               ON CONFLICT(user_id, app_id) DO UPDATE SET decided_at = excluded.decided_at, decided_by = excluded.decided_by')
                    ->execute([$uid, $app, now_iso(), (int)$admin['id']]);
            }
            $done[$what]++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $done;
}
