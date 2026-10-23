<?php
/**
 * מרקט האפליקציות (שלב 2 של דף הבית ברובריקות, 2026-10-22): מלאי של אפליקציות, וההורה משחרר לכל ילד מה שהוא
 * מסכים. אצל הילד — רובריקת "📲 אפליקציות" עם "התקנה" (במכשיר: Google Play לזמן קצר, ובהמשך התקנה רובוטית).
 *
 *   המלאי (market_apps) — ארבעה מקורות:
 *     seed   — מלאי בסיס (lib/market_seed.json): שמות החבילות אומתו מול Google Play.
 *     admin  — המנהל הוסיף מקישור Play (marketFromPlay — השם והאייקון נמשכים מהדף).
 *     admin  — מתוך "מה ילדים מתקינים" (marketPopular — ספירה בלבד, רק מה שנמצא אצל 2 ילדים לפחות).
 *     parent — אפליקציה פרטית של הורה (account_id) — רק לילדים שלו.
 *   השחרור (market_releases) — לכל ילד. משוחררת = מותרת (appsPayload), גם כשאפליקציה חדשה נחסמת.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/safefetch.php';

const MARKET_CATEGORIES = [
    'learn'     => ['📚', 'לימוד'],
    'school'    => ['🎒', 'בית ספר ועבודה'],
    'tools'     => ['🧰', 'כלים'],
    'transport' => ['🚌', 'תחבורה ומפות'],
    'music'     => ['🎵', 'מוזיקה ושמע'],
    'video'     => ['🎬', 'וידאו'],
    'social'    => ['💬', 'תקשורת ורשתות'],
    'games'     => ['🎮', 'משחקים'],
    'other'     => ['📦', 'אחר'],
];
/** מה שנמצא אצל פחות ילדים מזה — לא מוצג ב"מה ילדים מתקינים" (לא מזהים משפחה לפי אפליקציה). */
const MARKET_POPULAR_MIN = 2;

/** ‏package name תקין (כמו cleanPackage, בלי תלות ב-appcontrol). */
function marketPackage(string $p): string {
    $p = trim($p);
    return preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/i', $p) && strlen($p) <= 120 ? $p : '';
}

/** מלאי הבסיס נטען פעם אחת (ומשלים חסרות כשנוספות לקובץ). מה שהמנהל מחק — לא חוזר (active=0 נשאר). */
function marketEnsureSeed(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $seed = json_decode((string) @file_get_contents(__DIR__ . '/market_seed.json'), true);
    $apps = (array) ($seed['apps'] ?? []);
    $have = (int) one("SELECT COUNT(*) n FROM market_apps WHERE source = 'seed'")['n'];
    if ($have >= count($apps)) return;
    $now = nowIso();
    foreach ($apps as $a) {
        $pkg = marketPackage((string) ($a['package'] ?? ''));
        if ($pkg === '') continue;
        q("INSERT OR IGNORE INTO market_apps (package, account_id, name, play_title, category, min_age, note, icon, source, active, created_at)
           VALUES (?, 0, ?, ?, ?, ?, ?, ?, 'seed', 1, ?)",
          [$pkg, (string) $a['name'], (string) ($a['play_title'] ?? ''), marketCategory((string) ($a['category'] ?? '')),
           (int) ($a['min_age'] ?? 0), (string) ($a['note'] ?? ''), (string) ($a['icon'] ?? ''), $now]);
    }
}

function marketCategory(string $c): string {
    return isset(MARKET_CATEGORIES[$c]) ? $c : 'other';
}

/** אייקון בגודל קטן (כתובות של Google Play מקבלות גודל בסוף). */
function marketIcon(string $url, int $px = 96): string {
    if ($url === '') return '';
    return preg_match('#^https://play-lh\.googleusercontent\.com/#', $url) ? preg_replace('/=[^=\/]*$/', '', $url) . "=s$px" : $url;
}

/** שורה ← מה שמוצג (להורה ולמכשיר). */
function marketRow(array $r): array {
    return ['id' => (int) $r['id'], 'package' => $r['package'], 'name' => $r['name'] ?: $r['play_title'] ?: $r['package'],
            'play_title' => (string) $r['play_title'], 'category' => $r['category'], 'min_age' => (int) $r['min_age'],
            'note' => (string) $r['note'], 'icon' => marketIcon((string) $r['icon']), 'private' => (int) $r['account_id'] > 0,
            'source' => (string) $r['source']];
}

/** המלאי שחשבון רואה: הכללי + הפרטי שלו. */
function marketCatalog(int $accountId): array {
    marketEnsureSeed();
    $seen = [];
    $out = [];
    // פרטית קודם: אם אותה אפליקציה גם בכללי — מוצגת פעם אחת, בשם שההורה נתן.
    foreach (all('SELECT * FROM market_apps WHERE active = 1 AND (account_id = 0 OR account_id = ?)
                  ORDER BY account_id DESC, category, name', [$accountId]) as $r) {
        if (isset($seen[$r['package']])) continue;
        $seen[$r['package']] = true;
        $out[] = marketRow($r);
    }
    return $out;
}

/**
 * קישור של Google Play (או שם חבילה) ← [שם החבילה, שם, אייקון] מדף האפליקציה. ‏$get — לבדיקות.
 * מוחזר [נתונים, שגיאה].
 */
function marketFromPlay(string $input, ?callable $get = null): array {
    $input = trim($input);
    if (preg_match('#[?&]id=([A-Za-z0-9_.]+)#', $input, $m)) $input = $m[1];
    $pkg = marketPackage($input);
    if ($pkg === '') return [null, 'זה לא קישור של Google Play ולא שם חבילה (למשל com.duolingo)'];
    $get ??= fn(string $u) => safeHttpGet($u, 900000);
    $r = $get("https://play.google.com/store/apps/details?id=$pkg&hl=iw");
    if (($r['status'] ?? 0) !== 200) return [null, "האפליקציה $pkg לא נמצאה ב-Google Play"];
    $body = (string) ($r['body'] ?? '');
    $meta = function (string $prop) use ($body): string {
        return preg_match('/<meta property="' . preg_quote($prop, '/') . '" content="([^"]*)"/', $body, $m)
            ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    };
    $title = trim((string) preg_replace('/\s*[-–]\s*(Apps on Google Play|אפליקציות ב-Google Play)\s*$/u', '', $meta('og:title')));
    if ($title === '') return [null, "האפליקציה $pkg לא נמצאה ב-Google Play"];
    return [['package' => $pkg, 'play_title' => $title, 'icon' => $meta('og:image')], ''];
}

/**
 * הוספה למלאי. ‏$accountId 0 — כללי (מנהל); אחרת — פרטי לחשבון (הורה). מוחזר [מזהה, שגיאה].
 * אפליקציה שכבר במלאי שהחשבון רואה — לא מתווספת שוב (מוחזר המזהה הקיים).
 */
function marketAdd(int $accountId, string $input, string $category, int $minAge, string $note, string $name = '', ?callable $get = null): array {
    marketEnsureSeed();
    [$info, $err] = marketFromPlay($input, $get);
    if ($err) return [0, $err];
    $pkg = $info['package'];
    $dup = one('SELECT id, active FROM market_apps WHERE package = ? AND account_id IN (0, ?) ORDER BY account_id LIMIT 1', [$pkg, $accountId]);
    if ($dup && (int) $dup['active']) return [(int) $dup['id'], ''];
    if ($dup) { q('UPDATE market_apps SET active = 1 WHERE id = ?', [(int) $dup['id']]); return [(int) $dup['id'], '']; }
    q("INSERT INTO market_apps (package, account_id, name, play_title, category, min_age, note, icon, source, active, created_at)
       VALUES (?,?,?,?,?,?,?,?,?,1,?)",
      [$pkg, $accountId, mb_substr(trim($name), 0, 80), mb_substr($info['play_title'], 0, 120), marketCategory($category),
       max(0, min(18, $minAge)), mb_substr(trim($note), 0, 120), mb_substr($info['icon'], 0, 400), $accountId ? 'parent' : 'admin', nowIso()]);
    return [(int) db()->lastInsertId(), ''];
}

/** הסרה מהמלאי (מנהל — כללי; הורה — רק הפרטי שלו). השחרורים שלה מתבטלים. */
function marketRemove(int $id, int $accountId): bool {
    $r = one('SELECT package, account_id FROM market_apps WHERE id = ?', [$id]);
    if (!$r || (int) $r['account_id'] !== $accountId) return false;
    q('UPDATE market_apps SET active = 0 WHERE id = ?', [$id]);
    // השחרורים — רק אם האפליקציה כבר לא במלאי שהילדים רואים (הורה שהסיר פרטית שקיימת גם בכללי — נשארת).
    $children = $accountId ? all('SELECT id FROM users WHERE account_id = ?', [$accountId]) : all('SELECT DISTINCT child_id AS id FROM market_releases WHERE package = ?', [$r['package']]);
    foreach ($children as $c) {
        $acc = (int) (one('SELECT account_id FROM users WHERE id = ?', [(int) $c['id']])['account_id'] ?? 0);
        if (!one('SELECT 1 FROM market_apps WHERE package = ? AND active = 1 AND account_id IN (0, ?)', [$r['package'], $acc])) {
            q('DELETE FROM market_releases WHERE child_id = ? AND package = ?', [(int) $c['id'], $r['package']]);
        }
    }
    return true;
}

/** "מה ילדים מתקינים": מה שדווח מהמכשירים ואינו במלאי — ספירה בלבד, ולפחות MARKET_POPULAR_MIN ילדים. */
function marketPopular(int $limit = 40): array {
    marketEnsureSeed();
    return array_map(fn($r) => ['package' => $r['package'], 'label' => $r['label'], 'children' => (int) $r['n']],
        all("SELECT d.package, MAX(d.label) label, COUNT(DISTINCT d.child_id) n FROM device_apps d
             WHERE d.is_system = 0 AND d.package NOT IN (SELECT package FROM market_apps WHERE account_id = 0 AND active = 1)
             GROUP BY d.package HAVING n >= CAST(? AS INTEGER) ORDER BY n DESC, d.package LIMIT ?", [MARKET_POPULAR_MIN, $limit]));
}

/* ── שחרור לילד ─────────────────────────────────────────────────── */

/** החבילות ששוחררו לילד (ועדיין במלאי שהחשבון שלו רואה). */
function marketReleased(int $childId): array {
    $acc = (int) (one('SELECT account_id FROM users WHERE id = ?', [$childId])['account_id'] ?? 0);
    return array_column(all('SELECT r.package FROM market_releases r WHERE r.child_id = ? AND EXISTS
        (SELECT 1 FROM market_apps m WHERE m.package = r.package AND m.active = 1 AND m.account_id IN (0, ?)) ORDER BY r.package',
        [$childId, $acc]), 'package');
}

/** שחרור / ביטול. רק אפליקציה מהמלאי שהחשבון של הילד רואה. */
function marketRelease(int $childId, string $pkg, bool $on): string {
    $pkg = marketPackage($pkg);
    $acc = (int) (one('SELECT account_id FROM users WHERE id = ?', [$childId])['account_id'] ?? 0);
    if ($pkg === '' || !one('SELECT 1 FROM market_apps WHERE package = ? AND active = 1 AND account_id IN (0, ?)', [$pkg, $acc])) {
        return 'האפליקציה לא במלאי';
    }
    if ($on) q('INSERT OR IGNORE INTO market_releases (child_id, package, created_at) VALUES (?,?,?)', [$childId, $pkg, nowIso()]);
    else q('DELETE FROM market_releases WHERE child_id = ? AND package = ?', [$childId, $pkg]);
    return '';
}

/** להורה: המלאי, מה שוחרר לילד, ומה כבר מותקן אצלו. */
function marketForParent(int $accountId, int $childId): array {
    $released = array_flip(marketReleased($childId));
    $installed = array_flip(array_column(all('SELECT package FROM device_apps WHERE child_id = ?', [$childId]), 'package'));
    return [
        'categories' => MARKET_CATEGORIES,
        'apps' => array_map(fn($a) => $a + ['released' => isset($released[$a['package']]), 'installed' => isset($installed[$a['package']])],
                            marketCatalog($accountId)),
    ];
}

/** למכשיר: מה ששוחרר — לרובריקת האפליקציות (מרקט). */
function marketPayload(int $childId): array {
    $acc = (int) (one('SELECT account_id FROM users WHERE id = ?', [$childId])['account_id'] ?? 0);
    $released = array_flip(marketReleased($childId));
    if (!$released) return [];
    return array_values(array_map(fn($a) => ['p' => $a['package'], 'n' => $a['name'], 'c' => $a['category'], 'icon' => $a['icon'], 'note' => $a['note']],
        array_filter(marketCatalog($acc), fn($a) => isset($released[$a['package']]))));
}

/** מה הילד יכול לבקש: המלאי שהחשבון רואה, בלי מה שכבר שוחרר — [{p, n, c, note}]. */
function marketRequestable(int $childId): array {
    $acc = (int) (one('SELECT account_id FROM users WHERE id = ?', [$childId])['account_id'] ?? 0);
    $released = array_flip(marketReleased($childId));
    return array_values(array_map(fn($a) => ['p' => $a['package'], 'n' => $a['name'], 'c' => $a['category'], 'note' => $a['note']],
        array_filter(marketCatalog($acc), fn($a) => !isset($released[$a['package']]))));
}
