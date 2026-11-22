<?php
/**
 * שיתוף (אפיון 05): שמאי ממשרד אחר לתיק אחד, גישת תמיכה למנהל המערכת, וביטול.
 *
 * **ההכרעה במקום אחד.** apr_case_get() (lib/cases.php) שואל את apr_share_for() כשאין שורת משתתף פנימי; כל מי שמציג פריט
 * שואל את apr_can($c, אזור, מזהה). אין נקודת גישה שמחליטה לבד.
 *
 *  · השיתוף יושב ב-case_shares, **לא** ב-case_members: כל שאילתה על case_members (רשימות, לוח ראשי, "המשימות שלי", מבקרים,
 *    מקבלי משימות) ממשיכה לראות רק את אנשי המשרד — שותף חיצוני אינו נכנס אליהן גם אם קוד עתידי ישכח תנאי.
 *  · **שיתוף הוא קריאה בלבד**, ורק בפעולות שהכלל שלהן ב-APR_ACCESS הוא 'case'. פעולה אחרת — 404 לשותף, כברירת מחדל:
 *    נקודת גישה חדשה סגורה לשיתוף עד שמישהו פותח אותה במודע.
 *  · נבדק בכל בקשה: ביטול, תפוגה (לפי השעון, בלי שירות רקע), אישור ההזמנה, השעיית המשרד הבעלים. ההשעיה של המשתמש — ב-apr_ctx.
 *  · אין קישור ואין אסימון: הזמנה היא שורה למשתמש קיים, והוא מאשר אותה כשהוא מחובר. אין מה לנחש ואין מה להעביר הלאה.
 *  · תמיכה: רק הלקוח יוזם (בעל members בתיק, שאינו מנהל מערכת), רק פריטים נבחרים, רק view / download, תמיד עם תוקף ונימוק.
 *    כל בקשה של מנהל המערכת בתיק משותף נרשמת ביומן של המשרד (support.view).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/jobs.php';

const APR_SHARE_PERMS = ['view', 'download', 'see_internal', 'see_draft'];
const APR_SHARE_ITEM_TYPES = ['requirement', 'file', 'visit', 'source_check', 'comps', 'report'];
const APR_SHARE_TYPE_ONLY = ['comps', 'report'];                       // אין בהם פריט בודד — הסוג כולו או כלום
const APR_SHARE_DAYS = ['cross_office' => [0, 30, 90], 'support' => [1, 7, 30]];   // 0 = ללא הגבלה; לתמיכה אין
const APR_SHARE_PREVIEW_TTL = 900;
const APR_SHARE_WARN_DAYS = 3;
const APR_SHARE_ITEM_TABLES = ['requirement' => 'requirements', 'file' => 'files', 'visit' => 'visits', 'source_check' => 'source_checks'];

// ── ההכרעה ────────────────────────────────────────────────────

/**
 * השיתוף הפעיל שלי בתיק הזה, או null. נקרא רק מ-apr_case_get(). שיתוף "פעיל" = לא בוטל, אושר, לא פג (לפי השעון של הבקשה),
 * והמשרד הבעלים פעיל. שותף ממשרד אחר — השורה שלו; תמיכה — שורת התמיכה של התיק, ורק למי שמנהל מערכת עכשיו. שורה של אדם
 * גוברת על שורת תמיכה (§8.8): מנהל מערכת שהוזמן כשותף הוא שותף רגיל.
 */
function apr_share_for(array $ctx, array $c): ?array
{
    // ברירת המחדל סגורה: רק פעולה שהנתב סימן כפתוחה לשיתוף. קוד שקורא ל-apr_case_get בלי נתב (שירות רקע, בדיקה) — אין שיתוף.
    if ((APR_ACCESS[$GLOBALS['apr_action'] ?? ''] ?? '') !== 'case') return null;
    $s = apr_row("SELECT s.* FROM case_shares s JOIN offices o ON o.id = s.office_id
        WHERE s.case_id = ? AND s.revoked_at IS NULL AND s.invite_status = 'active' AND (s.expires_at IS NULL OR s.expires_at > ?) AND o.status = 'active'
            AND ((s.kind = 'cross_office' AND s.user_id = ?) OR (s.kind = 'support' AND CAST(? AS INTEGER) = 1))
        ORDER BY (s.kind = 'cross_office') DESC LIMIT 1", [$c['id'], apr_now(), $ctx['user']['id'], (int)$ctx['user']['is_sysadmin']]);
    if (!$s) return null;
    $stored = json_decode($s['perms'], true) ?: [];
    $allowed = $s['kind'] === 'support' ? ['view', 'download'] : APR_SHARE_PERMS;
    $perms = array_fill_keys(APR_CASE_PERMS, false);
    foreach ($allowed as $k) $perms[$k] = ($stored[$k] ?? false) === true;      // רק מה שמותר לסוג השיתוף — גם אם נשמר משהו אחר
    if (!$perms['view']) return null;
    return ['id' => (int)$s['id'], 'pid' => $s['pid'], 'kind' => $s['kind'], 'scope' => $s['kind'] === 'support' ? 'items' : $s['scope'], 'perms' => $perms, 'expires_at' => $s['expires_at'],
        'items' => apr_rows('SELECT item_type, item_pid FROM case_share_items WHERE share_id = ?', [$s['id']])];
}

/** האם השיתוף כולל את הפריט. $pid = null — "יש בשיתוף משהו מהסוג הזה". */
function apr_share_has(array $share, string $type, ?string $pid): bool
{
    foreach ($share['items'] as $i) {
        if ($i['item_type'] !== $type) continue;
        if ($pid === null || $i['item_pid'] === null || hash_equals((string)$i['item_pid'], $pid)) return true;
    }
    return false;
}

/**
 * can(user, case, action, item?) של האפיון (§6): $c הוא התיק כפי ש-apr_case_get החזיר אותו — כבר של המשתמש הזה.
 *   case — כותרת התיק · details — פרטי התיק, מזהי הנכס והגורמים (לא בשיתוף פריטים)
 *   requirement / file / visit / source_check — פריט ($pid), או "משהו מהסוג" ($pid = null)
 *   comps — עסקאות וחישובים: תחשיב פנימי (see_internal) · report — טיוטת הדוח (see_draft)
 *   download — הורדת קובץ (להבדיל מתצוגה) · internal — הערות פנימיות
 *   facts / conflicts / planning / tasks / ai — שכבות העבודה של המשרד: לשותף רק בשיתוף של כל התיק ועם see_internal
 *   team / activity / manage — צוות התיק, יומן הפעילות וניהול — למשתתפים פנימיים בלבד
 * משתתף פנימי: download, see_internal, see_draft דלוקות כברירת מחדל, והאחראי יכול לכבות אותן (04 §6).
 */
function apr_can(array $c, string $area, ?string $pid = null): bool
{
    $p = $c['perms'] ?? [];
    if (empty($p['view'])) return false;
    $sh = $c['share'] ?? null;
    $ext = $sh !== null || ($c['my_role'] ?? '') === 'external';
    $whole = $sh === null || $sh['scope'] === 'all';
    $in = fn(string $type) => $whole || apr_share_has($sh, $type, $pid);
    // הרשאה שלא נשמרה: דלוקה למשתתף פנימי (ברירת המחדל של 05 §2), כבויה לכל מי שאינו פנימי
    $has = fn(string $k) => array_key_exists($k, $p) ? !empty($p[$k]) : !$ext;
    return match ($area) {
        'case' => true,
        'details' => $whole,
        'requirement', 'file', 'visit', 'source_check' => $in($area),
        'comps' => $in('comps') && $has('see_internal'),
        'report' => $in('report') && $has('see_draft'),
        'download' => $has('download'),
        'internal' => $has('see_internal'),
        'facts', 'conflicts', 'planning', 'tasks', 'ai' => !$ext || ($whole && $has('see_internal')),
        'team', 'activity', 'manage' => !$ext,
        default => false,                                    // אזור שאיש לא הגדיר — סגור
    };
}

/** שער לאזור: מי שאינו רשאי מקבל את אותו 404 של תיק שלא קיים. */
function apr_can_or_404(array $c, string $area, ?string $pid = null): void
{
    if (!apr_can($c, $area, $pid)) apr_fail('not_found');
}

/** אילו אזורים פתוחים לי בתיק — המסך מציג רק לשונית שיש בה משהו (§6). */
function apr_share_areas(array $c): array
{
    $out = [];
    foreach (['details', 'requirement', 'file', 'visit', 'source_check', 'comps', 'report', 'facts', 'conflicts', 'planning', 'tasks', 'team', 'activity', 'download', 'internal'] as $a) $out[$a] = apr_can($c, $a);
    return $out;
}

// ── תיעוד צפיות של התמיכה (§4, 05-B5) ─────────────────────────

/** apr_case_get קורא כאן כשבקשה נשענת על שיתוף. לתמיכה — הבקשה תירשם ביומן של המשרד לפני שהתשובה יוצאת. */
function apr_share_used(array $c): void
{
    if ($c['share']['kind'] === 'support') $GLOBALS['apr_support_case'] ??= $c;
}

/** צפייה של התמיכה בפריט מסוים — נרשמת מיד, ביומן של המשרד הבעלים. למי שאינו תמיכה: לא עושה דבר. */
function apr_share_seen(array $c, string $type, string $pid): void
{
    if (($c['share']['kind'] ?? '') !== 'support') return;
    $GLOBALS['apr_support_logged'] = true;
    apr_case_audit($c, 'support.view', $type, $pid, ['action' => (string)($GLOBALS['apr_action'] ?? '')]);
}

/** סוף הבקשה (הנתב): בקשת תמיכה שלא נרשמה לפריט מסוים נרשמת על התיק — אין צפייה של התמיכה בלי שורה ביומן. */
function apr_share_seen_flush(): void
{
    $c = $GLOBALS['apr_support_case'] ?? null;
    $logged = !empty($GLOBALS['apr_support_logged']);
    unset($GLOBALS['apr_support_case'], $GLOBALS['apr_support_logged']);
    if ($c !== null && !$logged) apr_case_audit($c, 'support.view', 'case', $c['pid'], ['action' => (string)($GLOBALS['apr_action'] ?? '')]);
}

// ── קלט: מה משתפים, עם מי, ולכמה זמן ──────────────────────────

function apr_share_office_name(?int $officeId): ?string
{
    $n = $officeId === null ? false : apr_val('SELECT name FROM offices WHERE id = ?', [$officeId]);
    return $n === false ? null : (string)$n;
}

/** המשרד שמוצג ליד שותף: הראשון מבין המשרדים הפעילים שלו. */
function apr_share_home_office(int $userId): ?int
{
    $id = apr_val("SELECT m.office_id FROM memberships m JOIN offices o ON o.id = m.office_id WHERE m.user_id = ? AND m.deleted_at IS NULL AND m.status = 'active' AND o.status = 'active'
        ORDER BY o.name, o.id LIMIT 1", [$userId]);
    return $id === false ? null : (int)$id;
}

/** התווית של פריט לתצוגה המקדימה ולרשימת השיתופים — רק למי שמנהל את השיתוף (משתתף פנימי). */
function apr_share_item_label(array $c, string $type, ?string $pid): ?string
{
    if ($pid === null) return null;
    $v = match ($type) {
        'requirement' => apr_val('SELECT title FROM requirements WHERE pid = ? AND case_id = ?', [$pid, $c['id']]),
        'file' => apr_val('SELECT original_name FROM files WHERE pid = ? AND case_id = ?', [$pid, $c['id']]),
        'visit' => apr_val('SELECT seq FROM visits WHERE pid = ? AND case_id = ?', [$pid, $c['id']]),
        'source_check' => apr_val('SELECT s.name FROM source_checks k JOIN sources s ON s.id = k.source_id WHERE k.pid = ? AND k.case_id = ?', [$pid, $c['id']]),
        default => false,
    };
    return $v === false ? null : (string)$v;      // פריט שנמחק אחר כך: השורה נשארת, ואין לה תווית (§8.5)
}

/** הפריטים שבבקשה: סוג מוכר, פריט של התיק הזה שהמשתף עצמו רואה. סוג שלם בולע את הפריטים הבודדים שלו. */
function apr_share_items_clean(array $ctx, array $c, mixed $in): array
{
    if (!is_array($in) || count($in) > 200) apr_fail('share_invalid');
    $whole = [];
    $single = [];
    foreach ($in as $i) {
        if (!is_array($i) || !in_array($i['type'] ?? null, APR_SHARE_ITEM_TYPES, true)) apr_fail('share_invalid');
        $type = $i['type'];
        $pid = $i['pid'] ?? null;
        if ($pid === null) { $whole[$type] = true; continue; }
        if (in_array($type, APR_SHARE_TYPE_ONLY, true) || !apr_is_pid($pid)) apr_fail('share_invalid');
        $row = apr_row('SELECT * FROM ' . APR_SHARE_ITEM_TABLES[$type] . ' WHERE pid = ? AND case_id = ?', [$pid, $c['id']]) ?? apr_fail('share_invalid');
        if ($type === 'file' && ($row['deleted_at'] !== null || !apr_file_visible($ctx, $c, $row) || apr_val('SELECT 1 FROM visit_photos WHERE file_id = ?', [$row['id']]))) apr_fail('share_invalid');
        $single[$type][$pid] = true;
    }
    $out = [];
    foreach (APR_SHARE_ITEM_TYPES as $type) {
        if (isset($whole[$type])) { $out[] = ['type' => $type, 'pid' => null]; continue; }
        $pids = array_keys($single[$type] ?? []);
        sort($pids);
        foreach ($pids as $pid) $out[] = ['type' => $type, 'pid' => (string)$pid];
    }
    return $out;
}

/**
 * הבקשה לשיתוף, אחרי כל הבדיקות — אותה פונקציה לתצוגה המקדימה ולאישור, ולכן מה שאושר הוא בדיוק מה שהוצג.
 * $in: kind (cross_office | support), email (לשותף), scope (all | items), items [{type, pid?}], perms {download?, see_internal?, see_draft?},
 * days, reason. מחזיר את הערכים המנורמלים + hash של התוכן.
 */
function apr_share_params(array $ctx, array $c, array $in): array
{
    $kind = in_array($in['kind'] ?? null, ['cross_office', 'support'], true) ? $in['kind'] : apr_fail('share_invalid');
    // תמיכה: רק הלקוח יוזם. מנהל מערכת אינו יוצר ואינו מרחיב גישת תמיכה — גם לא בתיק שהוא עצמו משתתף בו (05-B2)
    if ($kind === 'support' && (int)$ctx['user']['is_sysadmin']) apr_fail('share_sysadmin');

    // הרשאות: רק מהרשימה של הסוג. כל הרשאה אחרת שנשלחה דלוקה (edit, members, approve_report, export…) — הבקשה נדחית כולה (05-A5, 05-B3)
    $grantable = $kind === 'support' ? ['download'] : ['download', 'see_internal', 'see_draft'];
    $req = $in['perms'] ?? [];
    if (!is_array($req)) apr_fail('share_invalid');
    $perms = array_fill_keys(APR_SHARE_PERMS, false);
    $perms['view'] = true;
    foreach ($req as $k => $v) {
        if ($v !== true && $v !== false) apr_fail('share_invalid');
        if ($k === 'view' || $v === false) continue;
        if (!in_array($k, $grantable, true)) apr_fail('share_perm');
        if (!apr_can($c, $k === 'download' ? 'download' : ($k === 'see_internal' ? 'internal' : 'report'))) apr_fail('share_perm_exceeds');   // לא מעניקים מה שאין (05-B4)
        $perms[$k] = true;
    }

    $scope = in_array($in['scope'] ?? null, ['all', 'items'], true) ? $in['scope'] : apr_fail('share_invalid');
    if ($kind === 'support' && $scope !== 'items') apr_fail('share_items_required');
    $items = $scope === 'items' ? apr_share_items_clean($ctx, $c, $in['items'] ?? []) : [];
    if ($scope === 'items' && !$items) apr_fail('share_items_required');

    $days = $in['days'] ?? null;
    if (!is_int($days) || !in_array($days, APR_SHARE_DAYS[$kind], true)) apr_fail('share_expiry');     // לתמיכה אין "ללא הגבלה" ואין ברירת מחדל
    $reason = $kind === 'support' ? apr_reason($in['reason'] ?? null) : apr_clean_text($in['reason'] ?? '', 500);

    $user = null;
    if ($kind === 'cross_office') {
        $email = apr_clean_email($in['email'] ?? null);
        if (!apr_limit('share-lookup:' . $ctx['user']['id'], 30, 3600)) apr_fail('share_rate');         // הכתובת היא שאלה על קיום חשבון — לא בלי הגבלה
        // רק "יש חשבון פעיל בכתובת הזו" — לא אם הוא חבר במשרד כלשהו (§3.1)
        $user = apr_row("SELECT id, pid, name, email FROM users WHERE lower(email) = ? AND status = 'active'", [$email]) ?? apr_fail('share_user_not_found');
        if ((int)$user['id'] === (int)$ctx['user']['id']) apr_fail('share_self');
        if (apr_val("SELECT 1 FROM memberships WHERE office_id = ? AND user_id = ? AND deleted_at IS NULL AND status = 'active'", [$c['office_id'], $user['id']])) apr_fail('share_same_office');
    }
    $hash = hash('sha256', (string)json_encode([$c['pid'], $kind, $user['pid'] ?? null, $scope, $items, $perms, $days, $reason]));
    return ['kind' => $kind, 'user' => $user, 'scope' => $scope, 'items' => $items, 'perms' => $perms, 'days' => $days, 'reason' => $reason, 'hash' => $hash];
}

/** השורה הפעילה שהבקשה תעדכן, אם יש (§8.7): אותו אדם בתיק, או שורת התמיכה של התיק. */
function apr_share_existing(array $c, array $k): ?array
{
    return $k['kind'] === 'support'
        ? apr_row("SELECT * FROM case_shares WHERE case_id = ? AND kind = 'support' AND revoked_at IS NULL", [$c['id']])
        : apr_row("SELECT * FROM case_shares WHERE case_id = ? AND kind = 'cross_office' AND user_id = ? AND revoked_at IS NULL", [$c['id'], $k['user']['id']]);
}

/** מה מוצג למשתף לפני האישור (§3.4): הנמען והמשרד שלו, מה יראה, מה יוכל לעשות, מה **לא** יראה, ומתי פג. */
function apr_share_summary(array $c, array $k): array
{
    $home = $k['user'] ? apr_share_home_office((int)$k['user']['id']) : null;
    $hidden = ['other_cases', 'profiles', 'team', 'contacts', 'ai', 'activity', 'writes'];
    if ($k['scope'] === 'items') $hidden[] = 'rest_of_case';
    if (!$k['perms']['see_internal']) $hidden[] = 'internal';
    if (!$k['perms']['see_draft']) $hidden[] = 'draft';
    if (!$k['perms']['download']) $hidden[] = 'download';
    return ['kind' => $k['kind'],
        'recipient' => $k['user'] ? ['name' => $k['user']['name'], 'email' => $k['user']['email'], 'office' => apr_share_office_name($home)] : null,
        'scope' => $k['scope'],
        'items' => array_map(fn($i) => $i + ['label' => apr_share_item_label($c, $i['type'], $i['pid'])], $k['items']),
        'future_items' => (bool)array_filter($k['items'], fn($i) => $i['pid'] === null && !in_array($i['type'], APR_SHARE_TYPE_ONLY, true)),      // §8.6
        'perms' => array_keys(array_filter($k['perms'])), 'hidden' => $hidden,
        'days' => $k['days'], 'expires_at' => $k['days'] > 0 ? apr_iso(apr_time() + 86400 * $k['days']) : null, 'reason' => $k['reason'],
        'needs_accept' => $k['kind'] === 'cross_office'];
}

// ── פעולות של המשרד הבעלים ────────────────────────────────────

/** share-preview: בעל members בתיק. מחזיר את מה שיוצג, ומזהה שרק איתו אפשר לאשר — בדיוק את התוכן הזה, פעם אחת. */
function apr_share_preview(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'members');
    if ($c['status'] === 'draft') apr_fail('case_state');
    $k = apr_share_params($ctx, $c, $in);
    $summary = apr_share_summary($c, $k);
    $existing = apr_share_existing($c, $k);
    $summary['update'] = $existing !== null;
    $pid = apr_pid();
    apr_insert('share_previews', ['pid' => $pid, 'case_id' => $c['id'], 'by_user_id' => $ctx['user']['id'], 'params_hash' => $k['hash'],
        'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE), 'created_at' => apr_now()]);
    return ['preview' => $pid, 'valid_for' => APR_SHARE_PREVIEW_TTL, 'summary' => $summary];
}

/**
 * share-create: אותו תוכן + preview. אין שיתוף בלי תצוגה מקדימה שהוצגה למשתמש הזה, לתיק הזה ולתוכן הזה — ושלא נוצלה.
 * שותף ממשרד אחר: ההזמנה ממתינה לאישור שלו (pending); עד אז אין לו גישה. תמיכה: פעילה מיד, ודורשת אישור חוזר (lib/confirm.php).
 * אותו אדם שוב (או תמיכה שוב) — השורה הפעילה מתעדכנת, לא נוצרת שנייה (§8.7); הזמנה שכבר אושרה נשארת מאושרת.
 */
function apr_share_create(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'members');
    if ($c['status'] === 'draft') apr_fail('case_state');
    $k = apr_share_params($ctx, $c, $in);
    return apr_tx(function () use ($ctx, $c, $in, $k) {
        $pv = apr_is_pid($in['preview'] ?? null) ? apr_row('SELECT * FROM share_previews WHERE pid = ? AND case_id = ? AND by_user_id = ? AND confirmed_at IS NULL', [$in['preview'], $c['id'], $ctx['user']['id']]) : null;
        if (!$pv || !hash_equals($pv['params_hash'], $k['hash'])) apr_fail('preview_required');
        if ($pv['created_at'] < apr_iso(apr_time() - APR_SHARE_PREVIEW_TTL)) apr_fail('preview_expired');
        apr_exec('UPDATE share_previews SET confirmed_at = ? WHERE id = ?', [apr_now(), $pv['id']]);

        $now = apr_now();
        $set = ['scope' => $k['scope'], 'perms' => json_encode($k['perms']), 'expires_at' => $k['days'] > 0 ? apr_iso(apr_time() + 86400 * $k['days']) : null,
            'reason' => $k['reason'], 'preview_id' => $pv['id'], 'updated_at' => $now, 'warned_at' => null];
        $old = apr_share_existing($c, $k);
        if ($old) {
            apr_exec('UPDATE case_shares SET ' . implode(', ', array_map(fn($f) => "$f = ?", array_keys($set))) . ' WHERE id = ?', [...array_values($set), $old['id']]);
            apr_exec('DELETE FROM case_share_items WHERE share_id = ?', [$old['id']]);
            $id = (int)$old['id'];
            $pid = $old['pid'];
        } else {
            $pid = apr_pid();
            $id = apr_insert('case_shares', $set + ['pid' => $pid, 'case_id' => $c['id'], 'office_id' => $c['office_id'], 'kind' => $k['kind'], 'user_id' => $k['user']['id'] ?? null,
                'home_office_id' => $k['user'] ? apr_share_home_office((int)$k['user']['id']) : null, 'invite_status' => $k['kind'] === 'support' ? 'active' : 'pending',
                'added_by' => $ctx['user']['id'], 'created_at' => $now]);
        }
        foreach ($k['items'] as $i) apr_insert('case_share_items', ['share_id' => $id, 'item_type' => $i['type'], 'item_pid' => $i['pid']]);
        // ביומן: סוג, היקף, הרשאות, ימים וספירה — לא שם של קובץ ולא שם של גורם
        apr_case_audit($c, $k['kind'] === 'support' ? ($old ? 'support.update' : 'support.share') : ($old ? 'share.update' : 'share.invite'), 'share', $pid,
            ['kind' => $k['kind'], 'scope' => $k['scope'], 'perms' => array_keys(array_filter($k['perms'])), 'days' => $k['days'], 'items' => count($k['items'])]
            + ($k['user'] ? ['user' => $k['user']['pid']] : []), $k['reason']);
        apr_case_touch($c);
        return ['share' => $pid, 'status' => $old['invite_status'] ?? ($k['kind'] === 'support' ? 'active' : 'pending'), 'updated' => $old !== null];
    });
}

/** share-revoke: בעל members. חוסם מעתה — הבקשה הבאה של השותף, גם בלשונית שכבר פתוחה, מקבלת 404. */
function apr_share_revoke(array $ctx, array $in): array
{
    $casePid = apr_is_pid($in['share'] ?? null) ? apr_val('SELECT c.pid FROM case_shares s JOIN cases c ON c.id = s.case_id WHERE s.pid = ?', [$in['share']]) : false;
    $c = apr_case_get($ctx, $casePid ?: null, 'members', true);
    return apr_tx(function () use ($ctx, $c, $in) {
        $s = apr_row('SELECT * FROM case_shares WHERE pid = ? AND case_id = ? AND revoked_at IS NULL', [$in['share'], $c['id']]) ?? apr_fail('not_found');
        apr_exec('UPDATE case_shares SET revoked_at = ?, revoked_by = ?, updated_at = ? WHERE id = ?', [apr_now(), $ctx['user']['id'], apr_now(), $s['id']]);
        apr_case_audit($c, $s['kind'] === 'support' ? 'support.revoke' : 'share.revoke', 'share', $s['pid'], ['kind' => $s['kind'], 'was' => $s['invite_status']],
            apr_clean_text($in['reason'] ?? '', 500));
        return [];
    });
}

function apr_share_out(array $c, array $s): array
{
    $user = $s['user_id'] !== null ? apr_row('SELECT pid, name, email FROM users WHERE id = ?', [$s['user_id']]) : null;
    $expired = $s['expires_at'] !== null && $s['expires_at'] <= apr_now();
    // §8.1: השיתוף הוא לאדם. אם הוא כבר אינו במשרד שהוצג בהזמנה — המשתף רואה זאת ויכול לבטל
    $left = $s['kind'] === 'cross_office' && $s['home_office_id'] !== null
        && !apr_val("SELECT 1 FROM memberships WHERE office_id = ? AND user_id = ? AND deleted_at IS NULL AND status = 'active'", [$s['home_office_id'], $s['user_id']]);
    return ['pid' => $s['pid'], 'kind' => $s['kind'], 'recipient' => $user ? ['name' => $user['name'], 'email' => $user['email']] : null,
        'office' => apr_share_office_name($s['home_office_id'] === null ? null : (int)$s['home_office_id']), 'left_office' => $left,
        'scope' => $s['scope'], 'perms' => array_keys(array_filter(json_decode($s['perms'], true) ?: [])),
        'items' => array_map(fn($i) => ['type' => $i['item_type'], 'pid' => $i['item_pid'], 'label' => apr_share_item_label($c, $i['item_type'], $i['item_pid'])],
            apr_rows('SELECT item_type, item_pid FROM case_share_items WHERE share_id = ? ORDER BY id', [$s['id']])),
        'status' => $expired ? 'expired' : $s['invite_status'], 'expires_at' => $s['expires_at'], 'reason' => $s['reason'],
        'added_by' => (string)apr_val('SELECT name FROM users WHERE id = ?', [$s['added_by']]), 'created_at' => $s['created_at'], 'updated_at' => $s['updated_at']];
}

/**
 * case-shares {case}: "גורמים והרשאות" — השיתופים של התיק, ומה שאפשר לבחור לשיתוף פריטים. משתתפים פנימיים בלבד; הניהול — לבעל members.
 * views — מי מהתמיכה צפה, במה ומתי (05-D3).
 */
function apr_case_shares(array $ctx, array $q): array
{
    $c = apr_case_get($ctx, $q['case'] ?? null);
    apr_can_or_404($c, 'manage');
    $rows = apr_rows('SELECT * FROM case_shares WHERE case_id = ? AND revoked_at IS NULL ORDER BY (kind = \'support\'), id', [$c['id']]);
    $files = array_values(array_filter(apr_rows('SELECT * FROM files WHERE case_id = ? AND deleted_at IS NULL AND id NOT IN (SELECT file_id FROM visit_photos) ORDER BY id DESC LIMIT 300', [$c['id']]),
        fn($f) => apr_file_visible($ctx, $c, $f)));
    return ['case' => ['pid' => $c['pid'], 'number' => $c['number'], 'status' => $c['status']],
        'can_manage' => !empty($c['perms']['members']) && $c['status'] !== 'draft', 'can_support' => !empty($c['perms']['members']) && !(int)$ctx['user']['is_sysadmin'],
        'grantable' => array_values(array_filter(['download', 'see_internal', 'see_draft'], fn($k) => !empty($c['perms'][$k]))),
        'days' => APR_SHARE_DAYS,
        'shares' => array_map(fn($s) => apr_share_out($c, $s), $rows),
        'options' => [
            'requirement' => array_map(fn($r) => ['pid' => $r['pid'], 'label' => $r['title']], apr_rows('SELECT pid, title FROM requirements WHERE case_id = ? AND split_at IS NULL ORDER BY sort, id', [$c['id']])),
            'file' => array_map(fn($f) => ['pid' => $f['pid'], 'label' => $f['original_name']], $files),
            'visit' => array_map(fn($v) => ['pid' => $v['pid'], 'label' => (string)$v['seq']], apr_rows('SELECT pid, seq FROM visits WHERE case_id = ? ORDER BY seq', [$c['id']])),
            'source_check' => array_map(fn($k) => ['pid' => $k['pid'], 'label' => $k['name']], apr_rows('SELECT k.pid, s.name FROM source_checks k JOIN sources s ON s.id = k.source_id WHERE k.case_id = ? ORDER BY k.id', [$c['id']])),
        ],
        'views' => array_map(fn($r) => ['at' => $r['at'], 'by' => $r['name'], 'entity_type' => $r['entity_type'], 'entity' => $r['entity_pid'],
                'label' => apr_share_item_label($c, $r['entity_type'], $r['entity_pid']), 'action' => (json_decode($r['meta'], true) ?: [])['action'] ?? null],
            apr_rows("SELECT a.at, a.entity_type, a.entity_pid, a.meta, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id WHERE a.case_id = ? AND a.action = 'support.view' ORDER BY a.id DESC LIMIT 100", [$c['id']]))];
}

// ── הצד של השותף ──────────────────────────────────────────────

/** שורת תיק משותף, כפי שהשותף רואה אותה: מספר, כותרת, מצב, המשרד הבעלים וההיקף — ושום דבר אחר. */
function apr_share_case_row(array $r): array
{
    return ['share' => $r['share_pid'], 'case' => ['pid' => $r['pid'], 'number' => $r['number'], 'title' => $r['title'], 'status' => $r['status'], 'updated_at' => $r['updated_at']],
        'office' => $r['office_name'], 'scope' => $r['scope'], 'perms' => array_keys(array_filter(json_decode($r['perms'], true) ?: [])), 'expires_at' => $r['expires_at']];
}

const APR_SHARE_MINE_SQL = "SELECT s.pid AS share_pid, s.scope, s.perms, s.expires_at, s.invite_status, s.reason, c.pid, c.number, c.title, c.status, c.updated_at, o.name AS office_name
    FROM case_shares s JOIN cases c ON c.id = s.case_id JOIN offices o ON o.id = s.office_id
    WHERE s.revoked_at IS NULL AND (s.expires_at IS NULL OR s.expires_at > ?) AND c.deleted_at IS NULL AND o.status = 'active'";

/**
 * my-shares: ההזמנות שממתינות לי ("הוזמנת לתיק <מספר> ממשרד <שם>"), והתיקים ששותפו איתי — בכל משרד פעיל שלי, בנפרד מתיקי
 * המשרד. הזמנה שעוד לא אושרה מציגה מספר ושם משרד בלבד — בלי כותרת התיק.
 */
function apr_my_shares(array $ctx): array
{
    $uid = (int)$ctx['user']['id'];
    $rows = apr_rows(APR_SHARE_MINE_SQL . " AND s.kind = 'cross_office' AND s.user_id = ? ORDER BY c.updated_at DESC, s.id DESC LIMIT 200", [apr_now(), $uid]);
    return ['invites' => array_values(array_map(fn($r) => ['share' => $r['share_pid'], 'number' => $r['number'], 'office' => $r['office_name'], 'scope' => $r['scope'],
            'perms' => array_keys(array_filter(json_decode($r['perms'], true) ?: [])), 'expires_at' => $r['expires_at']], array_filter($rows, fn($r) => $r['invite_status'] === 'pending'))),
        'shared' => array_values(array_map('apr_share_case_row', array_filter($rows, fn($r) => $r['invite_status'] === 'active')))];
}

/** share-respond {share, accept}: רק מי שהוזמן, ורק הזמנה שממתינה. כל השאר — 404. */
function apr_share_respond(array $ctx, array $in): array
{
    $accept = is_bool($in['accept'] ?? null) ? $in['accept'] : apr_fail('share_invalid');
    return apr_tx(function () use ($ctx, $in, $accept) {
        $s = apr_is_pid($in['share'] ?? null) ? apr_row("SELECT s.* FROM case_shares s JOIN offices o ON o.id = s.office_id JOIN cases c ON c.id = s.case_id
            WHERE s.pid = ? AND s.kind = 'cross_office' AND s.user_id = ? AND s.revoked_at IS NULL AND s.invite_status = 'pending' AND (s.expires_at IS NULL OR s.expires_at > ?)
                AND o.status = 'active' AND c.deleted_at IS NULL", [$in['share'], $ctx['user']['id'], apr_now()]) : null;
        if (!$s) apr_fail('not_found');
        $now = apr_now();
        if ($accept) {
            apr_exec("UPDATE case_shares SET invite_status = 'active', responded_at = ?, updated_at = ?, home_office_id = ? WHERE id = ?",
                [$now, $now, isset($ctx['office']['office_id']) && $ctx['office']['office_status'] === 'active' && $ctx['office']['status'] === 'active' ? (int)$ctx['office']['office_id'] : $s['home_office_id'], $s['id']]);
        } else {
            apr_exec("UPDATE case_shares SET invite_status = 'declined', responded_at = ?, updated_at = ?, revoked_at = ? WHERE id = ?", [$now, $now, $now, $s['id']]);
        }
        $c = apr_row('SELECT id, pid, office_id FROM cases WHERE id = ?', [$s['case_id']]);
        apr_case_audit($c, $accept ? 'share.accept' : 'share.decline', 'share', $s['pid']);
        return ['case' => $accept ? $c['pid'] : null];
    });
}

/** sys-support: התיקים ששותפו עם התמיכה, עם ההיקף והתוקף (§4.4). מנהל המערכת רואה כאן רק מה שלקוח שיתף — ואינו יכול לשנות דבר. */
function apr_sys_support(): array
{
    $rows = apr_rows(APR_SHARE_MINE_SQL . " AND s.kind = 'support' AND s.invite_status = 'active' ORDER BY s.expires_at, s.id LIMIT 200", [apr_now()]);
    return ['shares' => array_map(fn($r) => apr_share_case_row($r) + ['reason' => $r['reason'],
        'items' => (int)apr_val('SELECT COUNT(*) FROM case_share_items i JOIN case_shares s ON s.id = i.share_id WHERE s.pid = ?', [$r['share_pid']])], $rows)];
}

// ── כרטיס התיק לשותף ──────────────────────────────────────────

/**
 * מה ששותף רואה בכרטיס התיק: הכותרת, של מי התיק, ההיקף שלו, ואילו לשוניות פתוחות. פרטי התיק, מזהי הנכס והגורמים — רק בשיתוף של
 * כל התיק. **אין כאן**: צוות התיק, הפרופיל, תוכנית העבודה, משימות, "להשלמה", ושום ספירה על מה שלא שותף.
 */
function apr_case_view_shared(array $ctx, array $c): array
{
    $all = apr_can($c, 'details');
    $head = ['pid' => $c['pid'], 'number' => $c['number'], 'status' => $c['status'], 'title' => $c['title'], 'updated_at' => $c['updated_at']];
    if ($all) $head = apr_case_head($c) + ['closed_at' => $c['closed_at']];
    apr_share_seen($c, 'case', $c['pid']);
    return ['case' => $head,
        'me' => ['role' => 'external', 'perms' => array_intersect_key($c['perms'], array_flip(APR_SHARE_PERMS))],
        'shared' => ['kind' => $c['share']['kind'], 'office' => apr_share_office_name((int)$c['office_id']), 'scope' => $c['share']['scope'], 'expires_at' => $c['share']['expires_at'],
            'areas' => apr_share_areas($c)],
        'identifiers' => $all ? array_map('apr_ident_out', apr_rows('SELECT * FROM case_identifiers WHERE case_id = ? AND removed_at IS NULL ORDER BY id', [$c['id']])) : [],
        'parties' => $all ? array_map(fn($r) => ['pid' => $r['pid'], 'kind' => $r['kind'], 'is_orderer' => (bool)$r['is_orderer'], 'name' => $r['name'], 'org' => $r['org']],
            apr_rows('SELECT cp.pid, cp.kind, cp.is_orderer, ct.name, ct.org FROM case_parties cp JOIN contacts ct ON ct.id = cp.contact_id WHERE cp.case_id = ? AND cp.removed_at IS NULL ORDER BY cp.is_orderer DESC, cp.id', [$c['id']])) : []];
}

// ── תפוגה: סימון והתראה (§5) ──────────────────────────────────

/**
 * שירות רקע — **אינו** מה שחוסם: התפוגה נבדקת בכל בקשה (apr_share_for). כאן רק: התראה למשתף 3 ימים לפני, ורישום התפוגה ביומן.
 * ההתראה היא משימה בתיק למי שיצר את השיתוף (אם הוא עדיין משתתף), אחרת לאחראי. הנוסח אינו כולל שם של אדם, של קובץ או של גורם.
 */
function apr_shares_tick(): string
{
    $now = apr_now();
    $warned = 0;
    foreach (apr_rows("SELECT s.*, c.pid AS case_pid, c.responsible_user_id FROM case_shares s JOIN cases c ON c.id = s.case_id
        WHERE s.revoked_at IS NULL AND s.invite_status = 'active' AND s.warned_at IS NULL AND s.expires_at IS NOT NULL AND s.expires_at > ? AND s.expires_at <= ? AND c.deleted_at IS NULL AND c.status <> 'closed'",
        [$now, apr_iso(apr_time() + 86400 * APR_SHARE_WARN_DAYS)]) as $s) {
        apr_tx(function () use ($s, $now, &$warned) {
            if (apr_exec('UPDATE case_shares SET warned_at = ? WHERE id = ? AND warned_at IS NULL', [$now, $s['id']]) !== 1) return;
            $to = null;
            foreach ([$s['added_by'], $s['responsible_user_id']] as $u) {
                if ($u !== null && apr_val('SELECT 1 FROM case_members WHERE case_id = ? AND user_id = ? AND revoked_at IS NULL', [$s['case_id'], $u])) { $to = (int)$u; break; }
            }
            apr_insert('tasks', ['pid' => apr_pid(), 'office_id' => $s['office_id'], 'case_id' => $s['case_id'], 'kind' => 'system',
                'title' => $s['kind'] === 'support' ? 'גישת התמיכה לתיק תפוג בעוד 3 ימים' : 'שיתוף של התיק עם שמאי ממשרד אחר יפוג בעוד 3 ימים',
                'assignee_user_id' => $to, 'due_at' => $s['expires_at'], 'link_type' => 'share', 'link_pid' => $s['pid'], 'origin_key' => 'share-expiry:' . $s['pid'], 'created_by' => null,
                'created_at' => $now, 'updated_at' => $now]);
            $warned++;
        });
    }
    $expired = 0;
    foreach (apr_rows('SELECT s.*, c.pid AS case_pid FROM case_shares s JOIN cases c ON c.id = s.case_id WHERE s.revoked_at IS NULL AND s.expires_at IS NOT NULL AND s.expires_at <= ?', [$now]) as $s) {
        apr_tx(function () use ($s, $now, &$expired) {
            if (apr_exec('UPDATE case_shares SET revoked_at = ?, updated_at = ? WHERE id = ? AND revoked_at IS NULL', [$now, $now, $s['id']]) !== 1) return;
            apr_case_audit(['id' => (int)$s['case_id'], 'pid' => $s['case_pid'], 'office_id' => (int)$s['office_id']], $s['kind'] === 'support' ? 'support.expire' : 'share.expire', 'share', $s['pid'], [], null, null, 'system');
            $expired++;
        });
    }
    // תצוגות מקדימות שלא אושרו — אין בהן עוד צורך אחרי יום (מה שאושר נשאר: זה התיעוד של מה שהוצג למשתף)
    $stale = apr_exec('DELETE FROM share_previews WHERE confirmed_at IS NULL AND created_at < ?', [apr_iso(apr_time() - 86400)]);
    return "התראות: $warned · פגו: $expired · תצוגות מקדימות שנוקו: $stale";
}

apr_job_register('shares.tick', 'שיתוף: התראה 3 ימים לפני תפוגה, ורישום שיתופים שפגו (החסימה עצמה — בכל בקשה)', fn(): string => apr_shares_tick(), 3600);
