<?php
/**
 * בקשת פתיחה לאפליקציה חסומה (אפיון, 2026-10-22).
 *
 *   הילד פותח אפליקציה חסומה → מסך החסימה (או "אפליקציות חסומות" במגדלור, כשההשעיה של "בעל מכשיר" לא נותנת
 *   לה לעלות כלל) → "בקש פתיחה מההורה" → ההורה מקבל התראה → קבוע / זמני / סירוב.
 *
 *   הבקשה יושבת באותה טבלה כמו בקשות האתרים (access_requests, ‏kind='app') — אותה התראה, אותו מסך אצל ההורה,
 *   אותן מגבלות הצפה. ההבדל: אישור אינו נגמר בשרת. הוא נשלח למכשיר (appsPayload: ‏temp, ‏decisions), המכשיר
 *   מחיל ומחזיר אישור ביצוע (appRequestApplied), ורק אז:
 *     • הילד מקבל הודעה שהאפליקציה נפתחה — המכשיר עצמו, אחרי שההחלה הצליחה אצלו.
 *     • ההורה רואה "נפתח במכשיר" (או "נכשל" עם הסיבה).
 *
 *   היתר זמני: חלון זמן מרגע ההחלה במכשיר (לא מכסת שימוש). המכשיר מחשב את הסוף בשעון האמין שלו ואוכף אותו
 *   גם בלי שרת; השרת שומר את הסוף שדווח, כדי להציג אותו להורה ולהפסיק לשלוח את ההיתר אחריו.
 *
 *   החלטה ישנה לא דורסת חדשה: מכריעים רק על בקשה ממתינה; אישור ביצוע מתקבל רק לבקשה מאושרת שלא בוטלה;
 *   כלל חדש של ההורה על אותה אפליקציה מבטל היתר זמני פעיל (revoked) — והמכשיר מסיר היתר שלא מופיע עוד.
 */

declare(strict_types=1);

require_once __DIR__ . '/requests.php';
require_once __DIR__ . '/appcontrol.php';

/** משכי היתר זמני שההורה בוחר (דקות). */
const APP_GRANT_MINUTES = [15, 30, 60, 120, 240];
/** היתר שאושר ועוד לא הוחל במכשיר — עד מתי עוד שולחים אותו (מכשיר כבוי). */
const APP_GRANT_APPLY_WITHIN = 86400;

/** הילד מבקש לפתוח אפליקציה. מוחזר [מזהה, הודעה, שגיאה]. */
function appRequestCreate(array $user, string $pkg, string $note = ''): array {
    $uid = (int) $user['id'];
    $accountId = (int) $user['account_id'];
    if (!accountAppsAllowed($accountId)) return [0, '', 'ניהול אפליקציות אינו פעיל בחשבון'];
    $pkg = cleanPackage($pkg);
    if ($pkg === '') return [0, '', 'שם חבילה לא תקין'];
    if (alwaysOpen($pkg) !== '') return [0, '', 'האפליקציה הזו תמיד פתוחה'];

    $rules = appRules($uid);
    $action = $rules[$pkg] ?? '';
    if (!appBlocked(appPosture($uid), $action) || appTempActive($uid, $pkg)) {
        return [0, '', 'האפליקציה כבר פתוחה — נסו לפתוח אותה שוב'];
    }

    // ההורה כבר אישר, והמכשיר עוד לא החיל — לא בקשה חדשה.
    $approved = one("SELECT id FROM access_requests WHERE child_id = ? AND kind = 'app' AND package = ? AND status = 'approved'
                     AND apply_state = 'pending'", [$uid, $pkg]);
    if ($approved) return [(int) $approved['id'], 'ההורה כבר אישר — האפליקציה תיפתח עם הסנכרון הבא', ''];

    // אותה אפליקציה כבר ממתינה — לא בקשה שנייה ולא התראה שנייה.
    $same = one("SELECT id FROM access_requests WHERE child_id = ? AND kind = 'app' AND package = ? AND status = 'pending'", [$uid, $pkg]);
    if ($same) return [(int) $same['id'], 'הבקשה כבר אצל ההורה — נודיע כשיענה', ''];

    $pending = (int) one("SELECT COUNT(*) n FROM access_requests WHERE child_id = ? AND status = 'pending'", [$uid])['n'];
    if ($pending >= REQUEST_MAX_PENDING) return [0, '', 'יש כבר הרבה בקשות שמחכות להורה. חכו שיענה עליהן.'];
    $day = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
    if ((int) one('SELECT COUNT(*) n FROM access_requests WHERE child_id = ? AND created_at > ?', [$uid, $day])['n'] >= REQUEST_MAX_DAY) {
        return [0, '', 'הגעתם למספר הבקשות המרבי להיום'];
    }

    $label = (string) (one('SELECT label FROM device_apps WHERE child_id = ? AND package = ?', [$uid, $pkg])['label'] ?? '') ?: $pkg;
    $code = $action === 'block' ? 'app_blocked' : 'app_not_listed';
    q("INSERT INTO access_requests (account_id, child_id, kind, url, host, package, device_id, note, code, created_at)
       VALUES (?, ?, 'app', ?, ?, ?, ?, ?, ?, ?)",
      [$accountId, $uid, 'app:' . $pkg, mb_substr($label, 0, 80), $pkg, (int) ($user['device_row_id'] ?? 0),
       mb_substr(trim($note), 0, 200), $code, nowIso()]);
    $id = (int) db()->lastInsertId();
    audit($uid, 'app_request', false, 'app:' . $pkg, 'pending', mb_substr(trim($note), 0, 200));
    return [$id, 'הבקשה נשלחה להורה. כשיאשר — נודיע לך כאן.', ''];
}

/**
 * ההורה מכריע: permanent / temporary (+ דקות) / deny. מוחזר [הודעה, שגיאה].
 * רק בקשה ממתינה, ורק של ילד בחשבון של ההורה.
 */
function appRequestDecide(int $accountId, int $parentId, int $id, string $decision, int $minutes = 0): array {
    $r = one("SELECT * FROM access_requests WHERE id = ? AND account_id = ? AND kind = 'app'", [$id, $accountId]);
    if (!$r) return ['', 'הבקשה לא נמצאה'];
    if ($r['status'] !== 'pending') return ['', 'כבר טיפלו בבקשה הזו'];
    $child = childOf($accountId, (int) $r['child_id']);
    if (!$child) return ['', 'הבקשה לא נמצאה'];
    $cid = (int) $child['id'];
    $name = $child['display_name'] ?: $child['username'];
    $now = nowIso();

    if ($decision === 'deny') {
        q("UPDATE access_requests SET status = 'denied', decided_by = ?, decided_at = ? WHERE id = ?", [$parentId, $now, $id]);
        audit($cid, 'app_request_denied', false, $r['url'], 'denied');
        return ['הבקשה נדחתה', ''];
    }
    if ($decision === 'permanent') {
        if ($err = appsSaveRule($cid, (string) $r['package'], 'allow')) return ['', $err];
        q("UPDATE access_requests SET status = 'approved', scope = 'permanent', decided_by = ?, decided_at = ?, apply_state = 'pending'
           WHERE id = ?", [$parentId, $now, $id]);
        audit($cid, 'app_request_approved', true, $r['url'], 'permanent');
        return ["{$r['host']} נפתחה ל{$name} — עד שתשנו. נעדכן כשהמכשיר יאשר שהיא נפתחה", ''];
    }
    if ($decision === 'temporary') {
        if (!in_array($minutes, APP_GRANT_MINUTES, true)) return ['', 'משך לא מוכר'];
        q("UPDATE access_requests SET status = 'approved', scope = 'temporary', minutes = ?, decided_by = ?, decided_at = ?,
           apply_state = 'pending' WHERE id = ?", [$minutes, $parentId, $now, $id]);
        audit($cid, 'app_request_approved', true, $r['url'], "temporary:$minutes");
        return ["{$r['host']} תיפתח ל{$name} ל-" . appMinutesLabel($minutes) . ' מרגע שהמכשיר יקבל את האישור', ''];
    }
    return ['', 'בחירה לא מוכרת'];
}

function appMinutesLabel(int $m): string {
    return $m < 60 ? "$m דקות" : ($m === 60 ? 'שעה' : ($m === 120 ? 'שעתיים' : ($m / 60) . ' שעות'));
}

/** היתרים זמניים שהמכשיר צריך להחזיק עכשיו: אושרו, לא בוטלו, ולא הסתיימו (או עוד לא הוחלו). */
function appTempGrants(int $childId, ?int $now = null): array {
    $now ??= time();
    $out = [];
    foreach (all("SELECT * FROM access_requests WHERE child_id = ? AND kind = 'app' AND status = 'approved' AND scope = 'temporary'
                  AND apply_state IN ('pending', 'applied') ORDER BY id", [$childId]) as $r) {
        $ends = (string) $r['ends_at'];
        if ($ends !== '' && strtotime($ends) <= $now) continue;                                   // הסתיים
        if ($ends === '' && strtotime((string) $r['decided_at']) < $now - APP_GRANT_APPLY_WITHIN) continue;  // לא הוחל ביממה
        $out[] = ['id' => (int) $r['id'], 'package' => $r['package'], 'minutes' => (int) $r['minutes'], 'ends_at' => $ends];
    }
    return $out;
}

function appTempActive(int $childId, string $pkg): bool {
    foreach (appTempGrants($childId) as $g) if ($g['package'] === $pkg && $g['ends_at'] !== '') return true;
    return false;
}

/** החלטות מהיממה האחרונה — המכשיר מאשר ביצוע של קבוע, ומודיע לילד על סירוב. */
function appDecisions(int $childId): array {
    return array_map(fn($r) => ['id' => (int) $r['id'], 'package' => $r['package'], 'status' => $r['status'],
                                'scope' => $r['scope'], 'minutes' => (int) $r['minutes'], 'apply_state' => $r['apply_state']],
        all("SELECT * FROM access_requests WHERE child_id = ? AND kind = 'app' AND status != 'pending' AND decided_at > ?
             ORDER BY id DESC LIMIT 30", [$childId, gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]));
}

/**
 * אישור ביצוע מהמכשיר. מתקבל רק לבקשה של הילד הזה, שאושרה ולא בוטלה (בקשה שבוטלה או נדחתה — לא נדרסת
 * מתשובת רשת מאוחרת). היתר זמני: הסוף שהמכשיר חישב, חסום ל"עכשיו + המשך" — המכשיר לא מאריך לעצמו.
 */
function appRequestApplied(array $user, int $id, bool $ok, string $error = '', int $endsMs = 0): string {
    $r = one("SELECT * FROM access_requests WHERE id = ? AND child_id = ? AND kind = 'app'", [$id, (int) $user['id']]);
    if (!$r) return 'הבקשה לא נמצאה';
    if ($r['status'] !== 'approved' || !in_array($r['apply_state'], ['pending', 'applied', 'failed'], true)) return 'הבקשה כבר לא פעילה';
    if (!$ok) {
        q("UPDATE access_requests SET apply_state = 'failed', apply_error = ?, applied_at = ? WHERE id = ?",
          [mb_substr(trim($error), 0, 160) ?: 'ההחלה נכשלה', nowIso(), $id]);
        return '';
    }
    $ends = '';
    if ($r['scope'] === 'temporary') {
        if ($r['ends_at'] !== '') $ends = $r['ends_at'];                       // הוחל כבר — לא מאריכים
        else {
            $max = time() + (int) $r['minutes'] * 60 + 120;
            $sec = $endsMs > 0 ? min(intdiv($endsMs, 1000), $max) : time() + (int) $r['minutes'] * 60;
            $ends = gmdate('Y-m-d\TH:i:s\Z', $sec);
        }
    }
    q("UPDATE access_requests SET apply_state = 'applied', apply_error = '', applied_at = ?, ends_at = ? WHERE id = ?",
      [nowIso(), $ends, $id]);
    return '';
}
