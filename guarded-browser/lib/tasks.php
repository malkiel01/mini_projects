<?php
/**
 * אישור לפי משימה (אפיון, שלב 12 — ד): במקום היתר רחב וקבוע — "פתח את הדף הזה לחצי שעה, לשיעורי בית".
 *
 *   ‏pages     — מה שהילד פותח: דף אחד (exact) או אתר (domain). כלל זמני עם אריח.
 *   ‏resources — מה שהדף צריך כדי לעבוד (תמונות, סקריפטים, סרטון משובץ משרת אחר). כלל זמני בהיקף 'resource':
 *               נטען *בתוך* דף, ואי אפשר לגלוש אליו (lib/policy.php, ruleMatches). השרת מציע אותם מתוך הדף
 *               עצמו (taskScan), וההורה מאשר.
 *   כל הכללים פוקעים יחד (expires_at) — גם במכשיר בלי רשת, בשעון האמין (Policy.kt). סיום מוקדם מוחק אותם.
 *
 * משימה לא מחלישה חסימה: איסור מפורש וסוג תוכן חסום גוברים עליה, כמו על כל היתר (evaluate).
 * יוטיוב — לא כאן: היתר לסרטון הוא קבוע ברשימת יוטיוב, ולכן נדחה עם הסבר.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/platforms.php';
require_once __DIR__ . '/safefetch.php';

const TASK_MIN_MINUTES = 5;
const TASK_MAX_MINUTES = 240;
const TASK_MAX_PAGES = 5;
const TASK_MAX_RESOURCES = 20;
/** משאבים של יוטיוב — לא דרך משימה: הסרטונים נאכפים בלשונית יוטיוב, ומשאב פתוח כאן היה עוקף אותה. */
const TASK_NO_RESOURCE = ['youtube.com', 'youtu.be', 'ytimg.com', 'googlevideo.com', 'youtube-nocookie.com', 'ggpht.com'];

/** שם שרת אמיתי (כמו childRuleAdd). */
function taskHost(string $h): string {
    $h = strtolower(trim($h));
    if (str_contains($h, '://')) $h = (string) (parse_url($h, PHP_URL_HOST) ?? '');
    $h = preg_replace('/^www\./', '', rtrim($h, '/.'));
    return preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $h) ? $h : '';
}

function taskNoResource(string $h): bool {
    foreach (TASK_NO_RESOURCE as $d) if (hostMatches($h, $d)) return true;
    return false;
}

/**
 * משימה חדשה. ‏$pages — [{url, scope: exact|domain}]. ‏$resources — שמות שרתים.
 * מוחזר [מזהה, שגיאה].
 */
function taskCreate(int $childId, string $title, array $pages, array $resources, int $minutes, int $by): array {
    $title = mb_substr(trim($title), 0, 60);
    if ($title === '') return [0, 'מה המשימה? (למשל: "שיעורי בית בהיסטוריה")'];
    if ($minutes < TASK_MIN_MINUTES || $minutes > TASK_MAX_MINUTES) {
        return [0, 'משך המשימה — בין ' . TASK_MIN_MINUTES . ' ל-' . TASK_MAX_MINUTES . ' דקות'];
    }
    $clean = [];
    foreach (array_slice($pages, 0, TASK_MAX_PAGES) as $p) {
        $url = trim((string) (is_array($p) ? ($p['url'] ?? '') : $p));
        if ($url === '') continue;
        if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) $url = 'https://' . $url;
        $u = normalizeUrl($url);
        if (!$u || !taskHost($u['host'])) return [0, "הכתובת אינה תקינה: $url"];
        if (platformOf($u['host']) === PLATFORM_YOUTUBE) return [0, 'סרטוני יוטיוב — דרך לשונית יוטיוב (שם הם נאכפים), לא במשימה'];
        $clean[] = [$url, (is_array($p) && ($p['scope'] ?? '') === 'exact') ? 'exact' : 'domain', $u['host']];
    }
    if (!$clean) return [0, 'לפחות דף אחד למשימה'];
    $hosts = [];
    foreach (array_slice($resources, 0, TASK_MAX_RESOURCES) as $h) {
        $h = taskHost((string) $h);
        if ($h === '' || platformOf($h) === PLATFORM_YOUTUBE || taskNoResource($h)) continue;
        $hosts[$h] = true;
    }
    foreach ($clean as [, , $host]) unset($hosts[$host], $hosts[preg_replace('/^www\./', '', $host)]);

    $now = time();
    $ends = gmdate('Y-m-d\TH:i:s\Z', $now + $minutes * 60);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO tasks (child_id, title, minutes, starts_at, ends_at, created_by, created_at) VALUES (?,?,?,?,?,?,?)',
          [$childId, $title, $minutes, gmdate('Y-m-d\TH:i:s\Z', $now), $ends, $by, nowIso()]);
        $id = (int) $pdo->lastInsertId();
        $ins = 'INSERT INTO rules (user_id, label, pattern, scope, action, show_tile, sort_order, created_at, expires_at, task_id)
                VALUES (?,?,?,?,?,?,?,?,?,?)';
        foreach ($clean as [$url, $scope]) q($ins, [$childId, "📝 $title", $url, $scope, 'allow', 1, -1, nowIso(), $ends, $id]);
        foreach (array_keys($hosts) as $h) q($ins, [$childId, "📝 $title — משאב", "https://$h/", 'resource', 'allow', 0, 0, nowIso(), $ends, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$id, ''];
}

/** סיום מוקדם — הכללים נמחקים, והמכשיר מאבד אותם במדיניות הבאה (policyRev). */
function taskEnd(int $childId, int $taskId): bool {
    $t = one("SELECT id FROM tasks WHERE id = ? AND child_id = ? AND ended_at = ''", [$taskId, $childId]);
    if (!$t) return false;
    q('DELETE FROM rules WHERE task_id = ? AND user_id = ?', [$taskId, $childId]);
    q('UPDATE tasks SET ended_at = ? WHERE id = ?', [nowIso(), $taskId]);
    return true;
}

/** משימות של הילד מהשבוע האחרון: פעילות קודם, עם הדפים והמשאבים. */
function tasksOf(int $childId, ?int $now = null): array {
    $now ??= time();
    $iso = gmdate('Y-m-d\TH:i:s\Z', $now);
    $out = [];
    foreach (all('SELECT * FROM tasks WHERE child_id = ? AND created_at > ? ORDER BY id DESC LIMIT 30',
                 [$childId, gmdate('Y-m-d\TH:i:s\Z', $now - 7 * 86400)]) as $t) {
        $active = $t['ended_at'] === '' && $t['ends_at'] > $iso;
        $rules = all('SELECT pattern, scope FROM rules WHERE task_id = ? ORDER BY id', [(int) $t['id']]);
        $out[] = [
            'id' => (int) $t['id'], 'title' => $t['title'], 'minutes' => (int) $t['minutes'],
            'starts_at' => $t['starts_at'], 'ends_at' => $t['ended_at'] ?: $t['ends_at'], 'active' => $active,
            'ended_early' => $t['ended_at'] !== '',
            'left_min' => $active ? (int) ceil((strtotime($t['ends_at']) - $now) / 60) : 0,
            'pages' => array_values(array_map(fn($r) => $r['pattern'], array_filter($rules, fn($r) => $r['scope'] !== 'resource'))),
            'resources' => array_values(array_map(fn($r) => (string) parse_url($r['pattern'], PHP_URL_HOST),
                                                  array_filter($rules, fn($r) => $r['scope'] === 'resource'))),
        ];
    }
    usort($out, fn($a, $b) => $b['active'] <=> $a['active']);
    return $out;
}

/**
 * המשאבים שדף צריך: שרתים אחרים שמהם הוא טוען סקריפטים, עיצוב, תמונות, מסגרות ומדיה. ההורה מאשר — אין כאן
 * היתר אוטומטי. ‏$get — לבדיקות (ברירת מחדל: safeHttpGet, שחוסם כתובות פנימיות).
 * מוחזר [שרתים, שגיאה].
 */
function taskScan(string $url, ?callable $get = null): array {
    $url = trim($url);
    if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) $url = 'https://' . $url;
    $u = normalizeUrl($url);
    if (!$u || !taskHost($u['host'])) return [[], 'הכתובת אינה תקינה'];
    $get ??= fn(string $x) => safeHttpGet($x, 600000);
    $r = $get($url);
    if (($r['status'] ?? 0) < 200 || ($r['status'] ?? 0) >= 400) return [[], 'לא הצלחתי לטעון את הדף' . (($r['error'] ?? '') ? ": {$r['error']}" : '')];
    $own = preg_replace('/^www\./', '', $u['host']);
    $hosts = [];
    preg_match_all('#<(?:script|link|img|iframe|source|video|audio|embed)\b[^>]*?\b(?:src|href|data-src)\s*=\s*["\']?((?:https?:)?//[^"\'\s>]+)#i',
                   (string) $r['body'], $m);
    foreach ($m[1] as $ref) {
        $h = taskHost((string) parse_url(str_starts_with($ref, '//') ? "https:$ref" : $ref, PHP_URL_HOST));
        if ($h === '' || $h === $own || str_ends_with($h, ".$own") || taskNoResource($h)) continue;
        $hosts[$h] = ($hosts[$h] ?? 0) + 1;
    }
    arsort($hosts);
    return [array_slice(array_keys($hosts), 0, TASK_MAX_RESOURCES), ''];
}
