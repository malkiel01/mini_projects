<?php
/**
 * היסטוריית מדיניות, קבלות ושחזור (אפיון, שלב 12 — א + ז).
 *
 *   גרסה (policy_versions): בכל שינוי של הורה — תמונת מצב של כל מה שההורה קובע לילד, מי שינה, מתי, ומה השתנה
 *   לעומת הגרסה הקודמת. שינוי שלא שינה דבר — לא גרסה.
 *
 *   קבלה (policy_receipts): לכל גרסה ולכל מכשיר — מתי המכשיר קיבל אותה (משך את המדיניות), ומתי דיווח שהחיל
 *   (דוח האכיפה), עם התוצאה: הוחל / אומת / נכשל + מה נכשל. גרסה שמכשיר לא קיבל לפני שבאה חדשה ממנה — "הוחלפה".
 *
 *   שחזור: החזרת תמונת המצב של גרסה — כגרסה חדשה (עם קבלות משלה), לא מחיקת היסטוריה.
 *
 *   לא בתמונת המצב: היתרים זמניים (כללים עם תפוגה, היתרי אפליקציה זמניים) — הם לא "המדיניות", ושחזור לא נוגע בהם.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/devicesettings.php';

const HISTORY_KEEP = 100;   // גרסאות לילד

/** עמודות policies שאינן מדיניות (מטא-דאטה). */
const HISTORY_SKIP_POLICY = ['user_id', 'updated_at', 'migrated_posture'];

/** תמונת המצב של כל מה שההורה קובע לילד. */
function policySnapshot(int $childId): array {
    $p = one('SELECT * FROM policies WHERE user_id = ?', [$childId]) ?? [];
    foreach (HISTORY_SKIP_POLICY as $k) unset($p[$k]);
    ksort($p);
    return [
        'policy'     => $p,
        'rules'      => all("SELECT label, pattern, scope, action, show_tile, sort_order, enabled FROM rules
                             WHERE user_id = ? AND expires_at = '' ORDER BY pattern, scope, action", [$childId]),
        'categories' => categoryRulesFor($childId),
        'platforms'  => all('SELECT platform, mode, allow_search, allow_shorts FROM platform_rules WHERE user_id = ? ORDER BY platform', [$childId]),
        'items'      => all('SELECT platform, kind, item_id, label, action FROM platform_items WHERE user_id = ? ORDER BY platform, kind, item_id', [$childId]),
        'apps'       => all('SELECT package, action FROM app_rules WHERE child_id = ? ORDER BY package', [$childId]),
    // זמן מסך — רק כשיש מגבלה, כדי שילדים בלי מגבלות לא יקבלו גרסה "חדשה" בלי שינוי.
    ] + (($lim = all('SELECT package, daily_min FROM app_limits WHERE child_id = ? ORDER BY package', [$childId])) ? ['app_limits' => $lim] : []);
}

/** מה השתנה בין שתי תמונות מצב — שורות לתצוגה. */
function policyDiff(?array $old, array $new): array {
    if ($old === null) return ['מצב התחלתי'];
    $out = [];
    $labels = ['mode' => 'מצב', 'posture' => 'ברירת מחדל לאתרים', 'blocked_types' => 'סוגי תוכן חסומים', 'ad_block' => 'חסימת פרסומות',
               'timezone' => 'אזור זמן', 'days_mask' => 'ימים', 'window_start' => 'תחילת חלון', 'window_end' => 'סוף חלון',
               'daily_quota_min' => 'מכסה יומית (דק׳)', 'session_max_min' => 'רצף מרבי (דק׳)', 'max_devices' => 'מכשירים',
               'apps_posture' => 'ברירת מחדל לאפליקציות', 'new_apps' => 'אפליקציה חדשה שתותקן', 'report_level' => 'רמת דיווח', 'allow_pip' => 'תמונה בתוך תמונה',
               'allow_background' => 'שמע ברקע', 'allow_downloads' => 'הורדות', 'block_screenshots' => 'חסימת צילום מסך',
               'keep_history' => 'היסטוריה', 'chrome_managed' => 'כרום מנוהל', 'offline_grace_h' => 'שעות בלי חיבור', 'offline_content' => 'זמין בלי חיבור'];
    foreach ($new['policy'] as $k => $v) {
        $was = $old['policy'][$k] ?? null;
        if ((string) $was === (string) $v) continue;
        if ($k === 'device_settings') {
            $a = json_decode((string) $was, true) ?: []; $b = json_decode((string) $v, true) ?: [];
            // לפי הערך בפועל — כולל ברירת המחדל (הגדרה שלא נשמרה = ברירת המחדל שלה, לא "מותר").
            $eff = fn(array $m, string $s) => array_key_exists($s, $m) ? (bool) $m[$s] : (bool) (DEVICE_SETTINGS[$s][2] ?? false);
            foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $s) {
                if (!isset(DEVICE_SETTINGS[$s]) || in_array($s, DEVICE_NEVER_BLOCK, true) || $eff($a, $s) === $eff($b, $s)) continue;
                $out[] = DEVICE_SETTINGS[$s][0] . ': ' . ($eff($b, $s) ? 'חסום' : 'מותר');
            }
            continue;
        }
        $out[] = ($labels[$k] ?? $k) . ': ' . ($was === null || $was === '' ? '—' : $was) . ' ← ' . ($v === '' ? '—' : $v);
    }
    $key = fn(array $r, array $f) => implode('|', array_map(fn($x) => (string) ($r[$x] ?? ''), $f));
    $sets = [
        'rules' => [['pattern', 'scope', 'action', 'enabled'], fn($r) => ($r['action'] === 'deny' ? '🚫 ' : '✅ ') . $r['pattern'] . ($r['enabled'] ? '' : ' (כבוי)')],
        'items' => [['platform', 'kind', 'item_id', 'action'], fn($r) => '▶️ ' . ($r['label'] ?: $r['item_id']) . ($r['action'] === 'allow' ? '' : ' (חסום)')],
        'apps'  => [['package', 'action'], fn($r) => '📱 ' . $r['package'] . match ($r['action']) { 'block' => ' — חסומה', 'hide' => ' — מוסתרת', default => ' — מותרת' }],
        'app_limits' => [['package', 'daily_min'], fn($r) => '⏱️ ' . $r['package'] . " — {$r['daily_min']} דק׳ ביום"],
        'platforms' => [['platform', 'mode', 'allow_search', 'allow_shorts'], fn($r) => "יוטיוב: {$r['mode']}" . ($r['allow_search'] ? ' + חיפוש' : '') . ($r['allow_shorts'] ? ' + Shorts' : '')],
    ];
    foreach ($sets as $name => [$fields, $show]) {
        $a = []; foreach ($old[$name] ?? [] as $r) $a[$key($r, $fields)] = $r;
        $b = []; foreach ($new[$name] ?? [] as $r) $b[$key($r, $fields)] = $r;
        foreach (array_diff_key($b, $a) as $r) $out[] = '+ ' . $show($r);
        foreach (array_diff_key($a, $b) as $r) $out[] = '− ' . $show($r);
    }
    foreach (array_unique(array_merge(array_keys($old['categories'] ?? []), array_keys($new['categories']))) as $c) {
        if (($old['categories'][$c] ?? null) === ($new['categories'][$c] ?? null)) continue;
        $out[] = "קטגוריה $c: " . ($new['categories'][$c] ?? 'ברירת מחדל');
    }
    return $out;
}

/**
 * רישום גרסה אחרי שינוי. ‏$by — מזהה ההורה (0 — מערכת/מנהל); ‏$source — מה נעשה (שם הפעולה).
 * מחזיר את מזהה הגרסה החדשה, או 0 כשלא השתנה דבר.
 */
function policyVersionRecord(int $childId, int $by, string $source): int {
    $snap = policySnapshot($childId);
    $json = json_encode($snap, JSON_UNESCAPED_UNICODE);
    $hash = substr(hash('sha256', $json), 0, 16);
    $last = one('SELECT id, hash, snapshot FROM policy_versions WHERE child_id = ? ORDER BY id DESC LIMIT 1', [$childId]);
    if ($last && $last['hash'] === $hash) return 0;
    $diff = policyDiff($last ? json_decode((string) $last['snapshot'], true) : null, $snap);
    q('INSERT INTO policy_versions (child_id, by_parent, source, rev, hash, snapshot, summary, created_at) VALUES (?,?,?,?,?,?,?,?)',
      [$childId, $by, mb_substr($source, 0, 40), policyRev($childId), $hash, $json, json_encode($diff, JSON_UNESCAPED_UNICODE), nowIso()]);
    $id = (int) db()->lastInsertId();
    // שומרים HISTORY_KEEP אחרונות. הקבלות נמחקות יחד (ON DELETE CASCADE).
    q('DELETE FROM policy_versions WHERE child_id = ? AND id NOT IN (SELECT id FROM policy_versions WHERE child_id = ? ORDER BY id DESC LIMIT ?)',
      [$childId, $childId, HISTORY_KEEP]);
    return $id;
}

/** המכשיר משך את המדיניות: קבלה לגרסה האחרונה של הילד. */
function receiptReceived(int $childId, int $deviceRowId): void {
    $v = one('SELECT id FROM policy_versions WHERE child_id = ? ORDER BY id DESC LIMIT 1', [$childId]);
    if (!$v) return;
    q('UPDATE devices SET policy_version = ? WHERE id = ?', [(int) $v['id'], $deviceRowId]);
    q("INSERT OR IGNORE INTO policy_receipts (version_id, device_id, received_at) VALUES (?, ?, ?)",
      [(int) $v['id'], $deviceRowId, nowIso()]);
}

/**
 * דוח אכיפה מהמכשיר: אם הוא על המדיניות שהמכשיר קיבל — הקבלה של אותה גרסה מקבלת "הוחל", עם התוצאה.
 * דוח על מדיניות אחרת (ישנה) — לא נוגע בקבלה.
 */
function receiptApplied(int $deviceRowId, array $report): void {
    $d = one('SELECT policy_rev, policy_version FROM devices WHERE id = ?', [$deviceRowId]);
    if (!$d || (int) $d['policy_version'] <= 0 || (string) ($report['rev'] ?? '') !== (string) $d['policy_rev']) return;
    $failed = [];
    $verified = 0;
    foreach ((array) ($report['settings'] ?? []) as $k => $r) {
        if ($r === 'fail') $failed[] = DEVICE_SETTINGS[$k][0] ?? (string) $k;
        if ($r === 'verified') $verified++;
    }
    foreach ((array) ($report['suspend_failed'] ?? []) as $p) $failed[] = 'השעיה: ' . (string) $p;
    $state = $failed ? 'failed' : ($verified > 0 ? 'verified' : 'applied');
    $detail = $failed ? 'נכשל: ' . implode(', ', array_slice($failed, 0, 8)) : (empty($report['owner']) ? 'בלי "בעל מכשיר" — רק מה שמגדלור עצמו אוכף' : '');
    q('INSERT OR IGNORE INTO policy_receipts (version_id, device_id, received_at) VALUES (?, ?, ?)',
      [(int) $d['policy_version'], $deviceRowId, nowIso()]);
    q("UPDATE policy_receipts SET applied_at = ?, state = ?, detail = ? WHERE version_id = ? AND device_id = ?",
      [nowIso(), $state, mb_substr($detail, 0, 300), (int) $d['policy_version'], $deviceRowId]);
}

/** ההיסטוריה להורה: גרסאות מהחדשה, עם השינוי, מי ומתי, וקבלה לכל מכשיר. */
function policyHistory(int $childId, int $limit = 30): array {
    $devices = all('SELECT id, device_name FROM devices WHERE user_id = ? ORDER BY id', [$childId]);
    $rows = all('SELECT v.*, p.name AS parent_name, p.login AS parent_login FROM policy_versions v
                 LEFT JOIN parents p ON p.id = v.by_parent WHERE v.child_id = ? ORDER BY v.id DESC LIMIT ?', [$childId, $limit]);
    $out = [];
    $newerReceived = [];   // מכשיר → כבר קיבל גרסה חדשה יותר (כך גרסה שלא קיבל — "הוחלפה")
    foreach ($rows as $i => $v) {
        $rec = [];
        foreach (all('SELECT * FROM policy_receipts WHERE version_id = ?', [(int) $v['id']]) as $r) $rec[(int) $r['device_id']] = $r;
        $per = [];
        foreach ($devices as $d) {
            $r = $rec[(int) $d['id']] ?? null;
            $st = $r ? ($r['state'] !== '' ? $r['state'] : 'received') : ($i > 0 ? 'superseded' : 'pending');
            $per[] = ['device' => $d['device_name'], 'state' => $st, 'received_at' => $r['received_at'] ?? '',
                      'applied_at' => $r['applied_at'] ?? '', 'detail' => $r['detail'] ?? ''];
        }
        $out[] = ['id' => (int) $v['id'], 'at' => $v['created_at'], 'source' => $v['source'],
                  'by' => $v['parent_name'] ?: ($v['parent_login'] ?: ((int) $v['by_parent'] ? 'הורה'
                          : (str_starts_with((string) $v['source'], 'admin') ? 'מנהל המערכת' : 'מערכת'))),
                  'changes' => json_decode((string) $v['summary'], true) ?: [], 'devices' => $per, 'current' => $i === 0];
    }
    return $out;
}

/** השוואה בין גרסה לגרסה הנוכחית — מה ישתנה אם משחזרים. */
function policyCompare(int $childId, int $versionId): ?array {
    $v = one('SELECT snapshot FROM policy_versions WHERE id = ? AND child_id = ?', [$versionId, $childId]);
    if (!$v) return null;
    return policyDiff(policySnapshot($childId), json_decode((string) $v['snapshot'], true));
}

/**
 * שחזור: מחזיר את תמונת המצב של הגרסה, בטרנזקציה אחת, ורושם גרסה חדשה ("שחזור לגרסה N").
 * היתרים זמניים לא נגעים. מחזיר [מזהה הגרסה החדשה, שגיאה].
 */
function policyRestore(int $childId, int $versionId, int $by): array {
    $v = one('SELECT snapshot FROM policy_versions WHERE id = ? AND child_id = ?', [$versionId, $childId]);
    if (!$v) return [0, 'הגרסה לא נמצאה'];
    $s = json_decode((string) $v['snapshot'], true);
    if (!is_array($s)) return [0, 'הגרסה פגומה'];
    $cols = array_column(all('PRAGMA table_info(policies)'), 'name');
    $now = nowIso();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $set = []; $args = [];
        foreach ((array) $s['policy'] as $k => $val) {
            if (!in_array($k, $cols, true) || in_array($k, HISTORY_SKIP_POLICY, true)) continue;
            $set[] = "\"$k\" = ?"; $args[] = $val;
        }
        if ($set) q('UPDATE policies SET ' . implode(', ', $set) . ', updated_at = ? WHERE user_id = ?', [...$args, $now, $childId]);
        q("DELETE FROM rules WHERE user_id = ? AND expires_at = ''", [$childId]);
        foreach ((array) $s['rules'] as $r) {
            q("INSERT INTO rules (user_id, label, pattern, scope, action, show_tile, sort_order, enabled, created_at, expires_at)
               VALUES (?,?,?,?,?,?,?,?,?, '')",
              [$childId, $r['label'], $r['pattern'], $r['scope'], $r['action'], (int) $r['show_tile'], (int) $r['sort_order'], (int) $r['enabled'], $now]);
        }
        q('DELETE FROM category_rules WHERE user_id = ?', [$childId]);
        foreach ((array) $s['categories'] as $c => $a) q('INSERT INTO category_rules (user_id, category, action, created_at) VALUES (?,?,?,?)', [$childId, $c, $a, $now]);
        q('DELETE FROM platform_rules WHERE user_id = ?', [$childId]);
        foreach ((array) $s['platforms'] as $r) {
            q('INSERT INTO platform_rules (user_id, platform, mode, allow_search, allow_shorts, created_at) VALUES (?,?,?,?,?,?)',
              [$childId, $r['platform'], $r['mode'], (int) $r['allow_search'], (int) $r['allow_shorts'], $now]);
        }
        q('DELETE FROM platform_items WHERE user_id = ?', [$childId]);
        foreach ((array) $s['items'] as $r) {
            q('INSERT INTO platform_items (user_id, platform, kind, item_id, label, action, created_at) VALUES (?,?,?,?,?,?,?)',
              [$childId, $r['platform'], $r['kind'], $r['item_id'], $r['label'], $r['action'], $now]);
        }
        q('DELETE FROM app_rules WHERE child_id = ?', [$childId]);
        foreach ((array) $s['apps'] as $r) q('INSERT INTO app_rules (child_id, package, action, created_at) VALUES (?,?,?,?)', [$childId, $r['package'], $r['action'], $now]);
        // גרסה מלפני זמן המסך — בלי מגבלות.
        q('DELETE FROM app_limits WHERE child_id = ?', [$childId]);
        foreach ((array) ($s['app_limits'] ?? []) as $r) q('INSERT INTO app_limits (child_id, package, daily_min, created_at) VALUES (?,?,?,?)', [$childId, $r['package'], (int) $r['daily_min'], $now]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [0, 'השחזור נכשל — לא שונה דבר'];
    }
    $id = policyVersionRecord($childId, $by, "שחזור לגרסה $versionId");
    return [$id, ''];
}
