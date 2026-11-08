<?php
/**
 * 🔍 מסך בדיקת הגישות החסומות — זמני (2026-11-08, אחרי #1277).
 *
 * עד #1277 אפליקציה restricted שסומנה "🛍️ בחנות לכולם" נפתחה לכל משתמש: get_app כתב לו הרשאה ישירה.
 * המסך מציג כל הרשאה ישירה (grants: kind='app', allowed=1) לאפליקציה restricted, והמנהל מחליט לכל אחת:
 * להשאיר (נשמר כ"נבדקה") או לבטל (ההרשאה הישירה נמחקת). אין בטבלה עמודת מקור — "בחנות לכולם היום"
 * הוא רק רמז שההרשאה כנראה נוצרה דרך הפרצה.
 *
 *   GET                         → {rows: [...]}
 *   POST {decisions: [{user_id, app, decision: keep|revoke}]}   (X-Platform + X-CSRF, מנהל בלבד)
 *
 * שומר רק במסד הפלטפורמה (מוחרג בפריסה): הטבלה tmp_restricted_audit.
 *
 * ── הסרה (כשבעל המערכת אומר "תסיר את המסך") ──
 *   1. למחוק את platform/admin/tmp-restricted-audit.js
 *   2. להחליף את הקובץ הזה בשורה: <?php http_response_code(410);
 *      (rsync רץ בלי --delete — קובץ שנמחק מהריפו נשאר בשרת; הקובץ הקצר מנטרל אותו)
 *   3. למחוק את השורה שמסומנת "tmp-restricted-audit" ב-platform/assets/admin.js (renderSystem)
 *   4. למחוק את הסעיף "מסך בדיקת הגישות החסומות" ב-platform/README.md
 *   5. רשות: DROP TABLE tmp_restricted_audit — הטבלה לא מפריעה לשום דבר אם נשארת.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/http.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/access.php';
require __DIR__ . '/../lib/admin.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') audit_list(require_admin());
    if ($method === 'POST') {
        $body = json_body();
        audit_decide($body, require_admin(true));
    }
    throw new ApiError('שיטה לא נתמכת', 405);
} catch (ApiError $e) {
    json_out(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('platform restricted audit: ' . $e);
    json_out(['error' => 'שגיאה בשרת'], 500);
}

function audit_migrate(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS tmp_restricted_audit (
        user_id    INTEGER NOT NULL,
        app_id     TEXT NOT NULL,
        decided_at TEXT NOT NULL,
        decided_by INTEGER NOT NULL,
        PRIMARY KEY (user_id, app_id)
    )');
}

/** האפליקציות restricted (לא תוספים), לפי מזהה. */
function audit_restricted_apps(): array
{
    $out = [];
    foreach (apps_effective() as $a) if ($a['restricted'] && !$a['plugin']) $out[$a['id']] = $a;
    return $out;
}

function audit_list(array $admin): never
{
    audit_migrate();
    $apps = audit_restricted_apps();
    if (!$apps) json_out(['rows' => []]);
    $in = implode(',', array_fill(0, count($apps), '?'));
    $st = db()->prepare("SELECT g.user_id, g.target AS app, u.display_name, u.username, u.email, u.role, u.status,
                                k.decided_at
                           FROM grants g
                           JOIN users u ON u.id = g.user_id
                      LEFT JOIN tmp_restricted_audit k ON k.user_id = g.user_id AND k.app_id = g.target
                          WHERE g.kind = 'app' AND g.allowed = 1 AND g.target IN ($in)
                       ORDER BY k.decided_at IS NOT NULL, g.target, u.display_name");
    $st->execute(array_keys($apps));
    $rows = [];
    foreach ($st as $r) {
        $app = $apps[$r['app']];
        $groups = grants_of((int)$r['user_id'])['groups'];
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
            // רמז בלבד: מסומנת היום "בחנות לכולם" — ההרשאה כנראה נוצרה ב-get_app דרך הפרצה
            'catalog' => (bool)$app['catalog'],
            // גם בלי ההרשאה הישירה הוא ייכנס — דרך קבוצה שלמה שהוקצתה לו (can_see)
            'via_group' => (bool)array_filter($app['placements'], fn($p) => $p['group'] !== null && !empty($groups[$p['group']])),
            'kept_at' => $r['decided_at'],
        ];
    }
    json_out(['rows' => $rows]);
}

function audit_decide(array $body, array $admin): never
{
    audit_migrate();
    $list = $body['decisions'] ?? null;
    if (!is_array($list) || !$list || count($list) > 1000) throw new ApiError('בקשה לא תקינה', 400);
    $apps = audit_restricted_apps();
    $pdo = db();
    $pdo->beginTransaction();
    $done = ['keep' => 0, 'revoke' => 0];
    try {
        foreach ($list as $d) {
            $uid = is_int($d['user_id'] ?? null) ? $d['user_id'] : 0;
            $app = is_string($d['app'] ?? null) ? $d['app'] : '';
            $what = $d['decision'] ?? '';
            // רק אפליקציה restricted — המסך לא נוגע בשום הרשאה אחרת
            if ($uid <= 0 || !isset($apps[$app]) || !in_array($what, ['keep', 'revoke'], true)) throw new ApiError('בקשה לא תקינה', 400);
            if ($what === 'revoke') {
                $pdo->prepare("DELETE FROM grants WHERE user_id = ? AND kind = 'app' AND target = ? AND allowed = 1")->execute([$uid, $app]);
                $pdo->prepare('DELETE FROM tmp_restricted_audit WHERE user_id = ? AND app_id = ?')->execute([$uid, $app]);
            } else {
                $pdo->prepare('INSERT INTO tmp_restricted_audit (user_id, app_id, decided_at, decided_by) VALUES (?,?,?,?)
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
    json_out(['ok' => true] + $done);
}
