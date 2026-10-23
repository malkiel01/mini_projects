<?php
/**
 * בקש גישה (SPEC.md, "בקש גישה" · שלב 6).
 *
 *   הילד נחסם → מבקש (עם "למה?" אופציונלי) → ההורה מקבל צלצול לטלפון → מאשר או דוחה.
 *   אישור הוא כלל רגיל (או פריט יוטיוב), ולכן אין כאן מנוע שני: מה שנוצר נאכף בדיוק כמו
 *   כל כלל שההורה כותב ביד — במכשיר ובשרת. המכשיר מגלה את השינוי בפעימה הבאה (policyRev).
 *
 *   אפשר לבקש רק את מה שנחסם בגלל האתר עצמו (REQUESTABLE). חסימה בגלל זמן, או בגלל
 *   איסור מפורש של ההורה, אינה "לא ידענו" — ולכן אין עליה בקשה.
 */

declare(strict_types=1);

require_once __DIR__ . '/children.php';

/** קודי חסימה שעליהם אפשר לבקש: "עוד לא החלטתם", לא "החלטתם שלא". */
const REQUESTABLE = ['not_listed', 'category_deny', 'yt_not_approved'];
const REQUEST_SCOPES = ['page' => 'הדף הזה', 'site' => 'כל האתר', 'hour' => 'לשעה'];
const REQUEST_MAX_PENDING = 10;     // ממתינות לילד בבת אחת
const REQUEST_MAX_DAY = 30;         // בקשות לילד ביממה
const REQUEST_KEEP_DAYS = 90;

/**
 * הילד מבקש. מוחזר [מזהה, הודעה, שגיאה].
 * ההכרעה נעשית מחדש כאן ולא נלקחת מהמכשיר: בקשה על משהו שכבר פתוח, או על
 * חסימה שאינה ניתנת לבקשה, נעצרת לפני שהיא מטרידה את ההורה.
 */
function requestCreate(array $user, string $url, string $note = '', ?array $set = null): array {
    $uid = (int) $user['id'];
    $url = mb_substr(trim($url), 0, 500);
    $parsed = normalizeUrl($url);
    if (!$parsed || !preg_match('/^[a-z0-9.-]+\.[a-z0-9-]+$/i', $parsed['host'])) return [0, '', 'הכתובת אינה תקינה'];

    $policy = policyFor($uid);
    $tz = (string) $policy['timezone'];
    $d = evaluate($user, $policy, $set ?? ruleSetFor($uid), nowIn($tz),
                  ['url' => $url, 'main_frame' => true, 'used_today' => usedTodaySeconds($uid, $tz)]);
    if ($d['allow']) return [0, '', 'הכתובת כבר פתוחה לך — נסו לפתוח אותה שוב'];
    if (!in_array($d['code'], REQUESTABLE, true)) {
        return [0, '', in_array($d['code'], ['day_blocked', 'outside_window', 'quota_spent', 'session_over'], true)
            ? 'החסימה עכשיו היא בגלל הזמן, לא בגלל האתר — אין על מה לבקש'
            : 'ההורה חסם את זה במפורש, ולכן אי אפשר לבקש'];
    }

    $same = one("SELECT id FROM access_requests WHERE child_id = ? AND url = ? AND status = 'pending'", [$uid, $url]);
    if ($same) return [(int) $same['id'], 'הבקשה כבר אצל ההורה — נודיע כשיענה', ''];

    $pending = (int) one("SELECT COUNT(*) n FROM access_requests WHERE child_id = ? AND status = 'pending'", [$uid])['n'];
    if ($pending >= REQUEST_MAX_PENDING) return [0, '', 'יש כבר הרבה בקשות שמחכות להורה. חכו שיענה עליהן.'];
    $day = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
    $today = (int) one('SELECT COUNT(*) n FROM access_requests WHERE child_id = ? AND created_at > ?', [$uid, $day])['n'];
    if ($today >= REQUEST_MAX_DAY) return [0, '', 'הגעתם למספר הבקשות המרבי להיום'];

    q('INSERT INTO access_requests (account_id, child_id, url, host, note, code, created_at) VALUES (?,?,?,?,?,?,?)',
      [(int) $user['account_id'], $uid, $url, $parsed['host'], mb_substr(trim($note), 0, 200), $d['code'], nowIso()]);
    $id = (int) db()->lastInsertId();
    audit($uid, 'request', false, $url, 'pending', mb_substr(trim($note), 0, 200));
    return [$id, 'הבקשה נשלחה להורה. כשיאשר — הדף ייפתח לבד.', ''];
}

/** הבקשות של הילד מהיממה האחרונה — המכשיר בודק כאן אם נענה. */
function requestsOfChild(int $childId): array {
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
    return array_map(fn($r) => ['id' => (int) $r['id'], 'url' => $r['url'], 'status' => $r['status'], 'scope' => $r['scope'],
                                'kind' => $r['kind'], 'package' => $r['package']],
        all('SELECT * FROM access_requests WHERE child_id = ? AND created_at > ? ORDER BY id DESC LIMIT 50', [$childId, $since]));
}

/** להורה: כל הממתינות, ומה שטופל בשבוע האחרון. */
function requestList(int $accountId): array {
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - 7 * 86400);
    $rows = all("SELECT r.*, u.display_name, u.username, d.device_name FROM access_requests r JOIN users u ON u.id = r.child_id
                 LEFT JOIN devices d ON d.id = r.device_id
                 WHERE r.account_id = ? AND (r.status = 'pending' OR r.decided_at > ?)
                 ORDER BY CASE r.status WHEN 'pending' THEN 0 ELSE 1 END, r.id DESC LIMIT 60", [$accountId, $since]);
    return array_map(fn($r) => [
        'id' => (int) $r['id'], 'child_id' => (int) $r['child_id'], 'child' => $r['display_name'] ?: $r['username'],
        'url' => $r['url'], 'host' => $r['host'], 'note' => $r['note'], 'status' => $r['status'], 'scope' => $r['scope'],
        'created_at' => $r['created_at'], 'decided_at' => $r['decided_at'],
        'scopes' => $r['kind'] === 'app' ? [] : requestScopesFor($r['host']),
        // בקשת אפליקציה (lib/apprequests.php): מה החליטו, והאם המכשיר באמת פתח.
        'kind' => $r['kind'], 'package' => $r['package'], 'device' => (string) ($r['device_name'] ?? ''),
        'code' => (string) ($r['code'] ?? ''),
        'minutes' => (int) $r['minutes'], 'apply_state' => $r['apply_state'], 'apply_error' => $r['apply_error'],
        'applied_at' => $r['applied_at'], 'ends_at' => $r['ends_at'],
    ], $rows);
}

function requestPendingCount(int $accountId, int $childId = 0): int {
    return (int) one("SELECT COUNT(*) n FROM access_requests WHERE account_id = ? AND status = 'pending'"
                     . ($childId ? ' AND child_id = ' . $childId : ''), [$accountId])['n'];
}

/** ביוטיוב מאשרים פריט (סרטון / הערוץ שלו) ולא כתובת — ולכן אין שם "לשעה". */
function requestScopesFor(string $host): array {
    return platformOf($host) === PLATFORM_YOUTUBE ? ['page', 'site'] : array_keys(REQUEST_SCOPES);
}

/**
 * ההורה מכריע. מוחזר [הודעה, שגיאה].
 *   page — הכתובת המדויקת · site — כל האתר (ומופיע כאריח) · hour — כל האתר, לשעה.
 *   ביוטיוב: page — הסרטון (או הערוץ, אם זה מה שנחסם) · site — הערוץ של הסרטון.
 */
function requestDecide(int $accountId, int $parentId, int $id, string $decision, string $scope = 'page'): array {
    $r = one("SELECT * FROM access_requests WHERE id = ? AND account_id = ? AND kind = 'site'", [$id, $accountId]);
    if (!$r) return ['', 'הבקשה לא נמצאה'];
    if ($r['status'] !== 'pending') return ['', 'כבר טיפלו בבקשה הזו'];
    $child = childOf($accountId, (int) $r['child_id']);
    if (!$child) return ['', 'הבקשה לא נמצאה'];

    if ($decision === 'deny') {
        q("UPDATE access_requests SET status = 'denied', decided_by = ?, decided_at = ? WHERE id = ?", [$parentId, nowIso(), $id]);
        audit((int) $child['id'], 'request_denied', false, $r['url'], 'denied');
        return ['הבקשה נדחתה', ''];
    }
    if ($decision !== 'approve') return ['', 'בחירה לא מוכרת'];
    if (!in_array($scope, requestScopesFor($r['host']), true)) return ['', 'אי אפשר לאשר כך את הכתובת הזו'];

    $err = platformOf($r['host']) === PLATFORM_YOUTUBE
        ? requestGrantYouTube((int) $child['id'], $r['url'], $scope)
        : requestGrantRule((int) $child['id'], $r['url'], $r['host'], $scope);
    if ($err) return ['', $err];

    $now = nowIso();
    q("UPDATE access_requests SET status = 'approved', scope = ?, decided_by = ?, decided_at = ? WHERE id = ?",
      [$scope, $parentId, $now, $id]);
    audit((int) $child['id'], 'request_approved', true, $r['url'], $scope);

    // בקשות נוספות של אותו ילד שהאישור הזה כבר פותח — נסגרות יחד איתו.
    $set = ruleSetFor((int) $child['id']);
    $policy = policyFor((int) $child['id']);
    $more = 0;
    foreach (all("SELECT id, url FROM access_requests WHERE child_id = ? AND status = 'pending'", [$child['id']]) as $o) {
        $d = evaluate($child, $policy, $set, nowIn((string) $policy['timezone']), ['url' => $o['url'], 'main_frame' => true, 'used_today' => 0]);
        if (!$d['allow'] && !in_array($d['code'], ['day_blocked', 'outside_window', 'quota_spent', 'session_over'], true)) continue;
        q("UPDATE access_requests SET status = 'approved', scope = ?, decided_by = ?, decided_at = ? WHERE id = ?",
          [$scope, $parentId, $now, (int) $o['id']]);
        $more++;
    }
    $name = $child['display_name'] ?: $child['username'];
    $msg = $scope === 'hour' ? "אושר ל{$name} לשעה" : "אושר ל{$name}";
    return [$msg . ($more ? " (וגם עוד $more בקשות שזה פותח)" : '') . ' — המכשיר יתעדכן תוך חצי דקה', ''];
}

function requestGrantRule(int $childId, string $url, string $host, string $scope): string {
    $exact = $scope === 'page';
    q('INSERT INTO rules (user_id, label, pattern, scope, action, show_tile, sort_order, created_at, expires_at)
       VALUES (?,?,?,?,?,?,?,?,?)',
      [$childId, '🙋 ' . $host, $exact ? $url : $host, $exact ? 'exact' : 'domain', 'allow',
       $scope === 'site' ? 1 : 0, 0, nowIso(), $scope === 'hour' ? gmdate('Y-m-d\TH:i:s\Z', time() + 3600) : '']);
    return '';
}

function requestGrantYouTube(int $childId, string $url, string $scope): string {
    $p = parseYouTube(preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url) ? $url : 'https://' . $url);
    if ($p['kind'] === 'shorts') $p['kind'] = 'video';
    $input = $url;
    if ($scope === 'site' && $p['kind'] === 'video') {
        $owner = youTubeOwner($p['id']);
        if ($owner['handle'] !== '') $input = '@' . $owner['handle'];
        elseif ($owner['channel'] !== '') $input = $owner['channel'];
        else return 'לא הצלחנו לברר של איזה ערוץ הסרטון. אשרו את הסרטון עצמו.';
    }
    [, $err] = childYouTubeAdd($childId, $input, '', 'allow');
    return $err ? 'זו כתובת יוטיוב שאי אפשר לאשר כפריט. אפשר להוסיף ערוץ בלשונית יוטיוב.' : '';
}

/** ניקוי: בקשות ישנות, וכללי "לשעה" שפג תוקפם (יום אחרי — כדי שהמכשיר יספיק לראות). */
function requestsCleanup(): void {
    q('DELETE FROM access_requests WHERE created_at < ?', [gmdate('Y-m-d\TH:i:s\Z', time() - REQUEST_KEEP_DAYS * 86400)]);
    q("DELETE FROM rules WHERE expires_at != '' AND expires_at < ?", [gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]);
}
