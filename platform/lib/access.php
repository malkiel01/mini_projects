<?php
/**
 * קטגוריות, שיוך אפליקציות והרשאות — מי רואה מה.
 *
 * הקטגוריות במסד. בפעם הראשונה הן נזרעות מ-categories.json, ומאז המנהל
 * עורך אותן. app.json קובע קטגוריית ברירת מחדל; המנהל יכול להעביר אפליקציה
 * לקטגוריה אחרת או להסתיר אותה (app_overrides).
 *
 * הרשאה של משתמש רגיל:
 *   1. אפליקציה מוסתרת — אף אחד מלבד המנהל.
 *   2. הרשאה ישירה לאפליקציה (מותר / חסום) — גוברת.
 *   3. אחרת — הרשאה לקטגוריה שהאפליקציה נמצאת בה. חוץ מאפליקציה "restricted"
 *      ב-app.json: היא נפתחת רק בהרשאה ישירה (סעיף 2).
 *   4. אחרת — לא.
 * תוסף ("plugin" ב-app.json) — רק למנהל, תמיד; גם הרשאה ישירה לא פותחת אותו.
 *
 * מיקומים (placements): אפליקציה מונחת בקטגוריה אחת או יותר, ובכל אחת — בראשי או בתוך קבוצה. קבוצה היא
 * ישות משלה (groups) ויכולה להופיע בכמה קטגוריות; בכל קטגוריה — רק מה שהונח בה. הרשאה לקטגוריה פותחת
 * כל מה שמונח בה; הרשאה לקבוצה פותחת את כל מה שבקבוצה, גם restricted (זו בחירה מפורשת של המנהל במשתמש);
 * חסימה ישירה גוברת תמיד. אפליקציה שהמנהל עוד לא הניח — בקטגוריה של app.json (או זו שהועברה אליה), בראשי.
 * הרשאה לקטגוריה כוללת גם אפליקציות שיתווספו אליה בעתיד.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/registry.php';

function access_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS categories (
            id   TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            icon TEXT NOT NULL,
            sort INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS app_overrides (
            app_id   TEXT PRIMARY KEY,
            category TEXT,
            hidden   INTEGER NOT NULL DEFAULT 0
        );
        -- לשעבר: המנהל אישר התקנה לכל אפליקציה בנפרד. מאז 2026-10-18 — "חנות": מה שאפליקציה
        -- מסוגלת להתקין מוצג לכולם, והמנהל רק יכול להסתיר (install_hidden). הטבלה נשארת, לא בשימוש.
        CREATE TABLE IF NOT EXISTS install_approvals (app_id TEXT PRIMARY KEY);
        CREATE TABLE IF NOT EXISTS install_hidden (app_id TEXT PRIMARY KEY);
        -- בחנות: אפליקציה שכל משתמש רואה בדף הבית גם בלי הרשאה — אפורה עם חץ הורדה, ולחיצה מוסיפה לו
        -- אותה (get_app ב-api.php). מי שהמנהל חסם לו אותה במפורש — לא רואה.
        CREATE TABLE IF NOT EXISTS app_catalog (app_id TEXT PRIMARY KEY);
        -- אפליקציה שהמנהל ניתק מהפלטפורמה: השער לא חוסם אותה (lib/gate.php, is_detached).
        CREATE TABLE IF NOT EXISTS app_detached (app_id TEXT PRIMARY KEY, detached_at TEXT NOT NULL);
        -- שיטות הכניסה של אפליקציה כשהיא מנותקת (docs/auth.md, סעיף 6). מחוברת — רק דרך הפלטפורמה.
        -- אין שורה = ברירת המחדל: סיסמה משלה פתוחה, Google סגור.
        CREATE TABLE IF NOT EXISTS app_login (
            app_id   TEXT PRIMARY KEY,
            password INTEGER NOT NULL DEFAULT 1,
            google   INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS grants (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            kind    TEXT NOT NULL CHECK (kind IN ('app','category','group')),
            target  TEXT NOT NULL,
            allowed INTEGER NOT NULL,
            PRIMARY KEY (user_id, kind, target)
        );
        -- קטגוריות מ-categories.json שכבר נזרעו פעם אחת. כך קטגוריה שהמנהל
        -- מחק לא חוזרת בבקשה הבאה, וקטגוריה חדשה בקובץ כן נכנסת.
        CREATE TABLE IF NOT EXISTS seeded_categories (id TEXT PRIMARY KEY);
        CREATE TABLE IF NOT EXISTS groups (
            id   TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            icon TEXT NOT NULL,
            sort INTEGER NOT NULL
        );
        -- grp = '' — בראשי של הקטגוריה (לא בקבוצה)
        CREATE TABLE IF NOT EXISTS placements (
            app_id   TEXT NOT NULL,
            category TEXT NOT NULL,
            grp      TEXT NOT NULL DEFAULT '',
            sort     INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (app_id, category, grp)
        );
        -- אפליקציות שהמנהל קבע להן מיקומים. אפליקציה שלא כאן — לפי app.json.
        CREATE TABLE IF NOT EXISTS app_placed (app_id TEXT PRIMARY KEY);
        CREATE TABLE IF NOT EXISTS seeded_groups (id TEXT PRIMARY KEY);
        SQL);

    // grants לפני 'group' — ה-CHECK הישן לא מכיר אותו; SQLite לא משנה CHECK, ולכן בונים את הטבלה מחדש.
    // הבדיקה חוזרת בתוך הטרנזקציה — שתי בקשות במקביל לא יבנו פעמיים.
    $isOld = fn() => !str_contains((string)db()->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'grants'")->fetchColumn(), "'group'");
    if ($isOld()) {
        db()->exec('BEGIN IMMEDIATE');
        try {
            if ($isOld()) db()->exec(<<<SQL
                CREATE TABLE grants_v2 (
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    kind    TEXT NOT NULL CHECK (kind IN ('app','category','group')),
                    target  TEXT NOT NULL,
                    allowed INTEGER NOT NULL,
                    PRIMARY KEY (user_id, kind, target)
                );
                INSERT INTO grants_v2 SELECT user_id, kind, target, allowed FROM grants;
                DROP TABLE grants;
                ALTER TABLE grants_v2 RENAME TO grants;
                SQL);
            db()->exec('COMMIT');
        } catch (Throwable $e) {
            db()->exec('ROLLBACK');
            throw $e;
        }
    }
    // אייקון מצויר לקטגוריה / לקבוצה: האייקון של אפליקציה (icon_app) — במקום האימוג'י, כשיש לה icon_svg
    foreach (['categories', 'groups'] as $t) {
        $cols = array_column(db()->query("PRAGMA table_info($t)")->fetchAll(), 'name');
        if (!in_array('icon_app', $cols, true)) {
            db()->exec("ALTER TABLE $t ADD COLUMN icon_app TEXT");
            // קבוצה שנקראת כמו אפליקציה (חבילת היצירה) — האייקון שלה, כמו שהוצג עד עכשיו
            if ($t === 'groups') db()->exec("UPDATE groups SET icon_app = id WHERE icon_app IS NULL");
        }
    }
    groups_seed();

    $seeded = db()->query('SELECT id FROM seeded_categories')->fetchAll(PDO::FETCH_COLUMN);
    $new = array_filter(registry_categories(), fn($c) => !in_array($c['id'], $seeded, true));
    if (!$new) return;
    $max = (int)db()->query('SELECT COALESCE(MAX(sort), 0) FROM categories')->fetchColumn();
    $ins = db()->prepare('INSERT OR IGNORE INTO categories (id, name, icon, sort) VALUES (?,?,?,?)');
    $mark = db()->prepare('INSERT INTO seeded_categories (id) VALUES (?)');
    foreach ($new as $c) {
        $ins->execute([$c['id'], $c['name'], $c['icon'], ++$max]);
        $mark->execute([$c['id']]);
    }
}

/**
 * קבוצות מ-subcategories.json נזרעות פעם אחת למסד: הקבוצה, ומיקום לכל אפליקציה שבה (בקטגוריית האב). מאז —
 * הכול בניהול ("🗂️ אפליקציות"). קבוצה שהמנהל מחק לא חוזרת.
 */
function groups_seed(): void
{
    $seeded = db()->query('SELECT id FROM seeded_groups')->fetchAll(PDO::FETCH_COLUMN);
    $new = array_filter(registry_subcategories(), fn($s) => !in_array($s['id'], $seeded, true));
    if (!$new) return;
    $pdo = db();
    $pdo->beginTransaction();
    $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort), 0) FROM groups')->fetchColumn();
    foreach ($new as $s) {
        // קבוצה שנקראת כמו אפליקציה (חבילת היצירה) — האייקון המצויר שלה
        $pdo->prepare('INSERT OR IGNORE INTO groups (id, name, icon, sort, icon_app) VALUES (?,?,?,?,?)')
            ->execute([$s['id'], $s['name'], $s['icon'], ++$sort, in_array($s['id'], $s['apps'], true) ? $s['id'] : null]);
        foreach ($s['apps'] as $i => $app) {
            // אפליקציה שכבר הונחה — נוסיף לה את הקבוצה; אחרת — הקבוצה מחליפה את ברירת המחדל
            $pdo->prepare('INSERT OR IGNORE INTO placements (app_id, category, grp, sort) VALUES (?,?,?,?)')->execute([$app, $s['parent'], $s['id'], $i]);
            $pdo->prepare('INSERT OR IGNORE INTO app_placed (app_id) VALUES (?)')->execute([$app]);
        }
        $pdo->prepare('INSERT INTO seeded_groups (id) VALUES (?)')->execute([$s['id']]);
    }
    $pdo->commit();
}

function groups_list(): array
{
    access_migrate();
    return array_map('icon_resolve', db()->query('SELECT id, name, icon, icon_app FROM groups ORDER BY sort, id')->fetchAll());
}

function categories_list(): array
{
    access_migrate();
    return array_map('icon_resolve', db()->query('SELECT id, name, icon, icon_app FROM categories ORDER BY sort, id')->fetchAll());
}

/** icon_app → icon_svg: הנתיב לאייקון המצויר של האפליקציה. אפליקציה שאין / בלי אייקון מצויר — רק האימוג'י. */
function icon_resolve(array $row): array
{
    static $svg = null;
    $svg ??= array_column(array_filter(registry_load()['apps'], fn($a) => $a['icon_svg']), 'icon_svg', 'id');
    $row['icon_svg'] = $row['icon_app'] !== null ? ($svg[$row['icon_app']] ?? null) : null;
    return $row;
}

/** כל האפליקציות, עם הקטגוריה בפועל והאם הוסתרה. */
function apps_effective(): array
{
    access_migrate();
    $overrides = [];
    foreach (db()->query('SELECT * FROM app_overrides') as $o) $overrides[$o['app_id']] = $o;
    $known = array_column(categories_list(), 'id');
    $installHidden = array_flip(db()->query('SELECT app_id FROM install_hidden')->fetchAll(PDO::FETCH_COLUMN));
    $catalog = array_flip(db()->query('SELECT app_id FROM app_catalog')->fetchAll(PDO::FETCH_COLUMN));
    $detached = array_flip(db()->query('SELECT app_id FROM app_detached')->fetchAll(PDO::FETCH_COLUMN));
    $login = [];
    foreach (db()->query('SELECT * FROM app_login') as $l) $login[$l['app_id']] = $l;

    $groups = array_column(groups_list(), 'id');
    $placed = array_flip(db()->query('SELECT app_id FROM app_placed')->fetchAll(PDO::FETCH_COLUMN));
    $rows = [];
    foreach (db()->query('SELECT app_id, category, grp, sort FROM placements ORDER BY sort, category, grp') as $r) {
        $rows[$r['app_id']][] = $r;
    }

    $apps = [];
    foreach (registry_load()['apps'] as $app) {
        $o = $overrides[$app['id']] ?? null;
        $app['default_category'] = $app['category'];
        if ($o && $o['category'] && in_array($o['category'], $known, true)) $app['category'] = $o['category'];
        // המיקומים: מה שהמנהל קבע, או — אם לא קבע — הקטגוריה הנוכחית, בראשי. קטגוריה שנמחקה נשמטת;
        // קבוצה שנמחקה — המיקום חוזר לראשי. לא נשאר כלום — "אחר" (האפליקציה לא נעלמת).
        $list = [];
        if (isset($placed[$app['id']])) {
            foreach ($rows[$app['id']] ?? [] as $r) {
                if (!in_array($r['category'], $known, true)) continue;
                $g = in_array($r['grp'], $groups, true) ? $r['grp'] : null;
                $list[$r['category'] . '|' . $g] ??= ['category' => $r['category'], 'group' => $g, 'sort' => (int)$r['sort']];
            }
        } elseif (in_array($app['category'], $known, true)) {
            $list[] = ['category' => $app['category'], 'group' => null, 'sort' => 0];
        }
        $app['placements'] = array_values($list) ?: [['category' => 'other', 'group' => null, 'sort' => 0]];
        // קטגוריה ראשית — הראשונה; לתוסף — קטגוריה פנימית משלו, בלי מיקומים
        $app['category'] = $app['placements'][0]['category'];
        if ($app['plugin']) { $app['category'] = PLUGIN_CATEGORY; $app['placements'] = []; }
        $app['hidden'] = (bool)($o['hidden'] ?? false);
        $app['install_ok'] = !isset($installHidden[$app['id']]);
        $app['catalog'] = isset($catalog[$app['id']]);
        // מחוברת = השער פעיל: נכנסים רק עם חשבון בפלטפורמה והרשאה לאפליקציה.
        // מנותקת = עומדת לבד — אפליקציה חיצונית, בלי שער ב-app.json, או שהמנהל ניתק.
        $app['detached'] = isset($detached[$app['id']]);
        $app['connected'] = !empty($app['gate']) && !$app['detached'];
        // שיטות הכניסה (docs/auth.md): מחוברת — רק דרך הפלטפורמה; מנותקת — לפי הגדרת המנהל.
        $l = $login[$app['id']] ?? null;
        $app['login'] = $app['connected']
            ? ['platform' => true, 'password' => false, 'google' => false]
            : ['platform' => true, 'password' => (bool)($l['password'] ?? true), 'google' => (bool)($l['google'] ?? false)];
        // אכיפת PWA: כל אפליקציה מחוברת ניתנת להתקנה — אם אין לה manifest משלה, השער נותן לה
        // אחד (platform/pwa.php, lib/pwa.php).
        if ($app['connected']) $app['install']['pwa'] = true;
        $apps[] = $app;
    }
    return $apps;
}

/** @return array{apps: array<string,bool>, categories: array<string,bool>} */
function grants_of(int $userId): array
{
    access_migrate();
    $out = ['apps' => [], 'categories' => [], 'groups' => []];
    $st = db()->prepare('SELECT kind, target, allowed FROM grants WHERE user_id = ?');
    $st->execute([$userId]);
    foreach ($st as $g) {
        $out[['app' => 'apps', 'category' => 'categories', 'group' => 'groups'][$g['kind']]][$g['target']] = (bool)$g['allowed'];
    }
    return $out;
}

function can_see(array $app, array $grants): bool
{
    if ($app['hidden'] || $app['plugin']) return false;
    if (array_key_exists($app['id'], $grants['apps'])) return $grants['apps'][$app['id']];
    // קבוצה שלמה — בחירה מפורשת של המנהל במשתמש, ולכן פותחת גם restricted
    foreach ($app['placements'] as $p) if ($p['group'] !== null && !empty($grants['groups'][$p['group']])) return true;
    // restricted: הרשאה לקטגוריה לא פותחת אותה — רק אישור ישיר לאפליקציה (או לקבוצה).
    if ($app['restricted']) return false;
    foreach ($app['placements'] as $p) if (!empty($grants['categories'][$p['category']])) return true;
    return false;
}

/** האפליקציות שהמשתמש רואה, והקטגוריות שיש בהן משהו בשבילו. */
function visible_for(array $user): array
{
    $apps = apps_effective();
    if ($user['role'] === 'admin') {
        $apps = array_values(array_filter($apps, fn($a) => !$a['hidden']));
    } elseif ($user['status'] !== 'active') {
        $apps = [];
    } else {
        $grants = grants_of((int)$user['id']);
        $owned = array_values(array_filter($apps, fn($a) => can_see($a, $grants)));
        $ids = array_column($owned, 'id');
        // בחנות: מה שעוד אין לו — מוצג אפור (owned=false). חסימה מפורשת של המנהל גוברת.
        $more = array_values(array_filter($apps, fn($a) => in_catalog($a) && !in_array($a['id'], $ids, true)
            && ($grants['apps'][$a['id']] ?? null) !== false));
        foreach ($more as &$m) $m['owned'] = false;
        unset($m);
        $apps = array_merge($owned, $more);
    }
    $categories = categories_list();
    if (array_filter($apps, fn($a) => in_array('other', array_column($a['placements'], 'category'), true))) {
        $categories[] = ['id' => 'other', 'name' => 'אחר', 'icon' => '📦'];
    }
    $admin = $user['role'] === 'admin';
    // תוספים לא ברשימת הכלים — הם באזור "🧩 תוספים" של המנהל
    $plugins = array_values(array_filter($apps, fn($a) => $a['plugin']));
    $apps = array_values(array_filter($apps, fn($a) => !$a['plugin']));
    // הקבוצות שיש בהן משהו בשביל המשתמש
    $used = [];
    foreach ($apps as $a) foreach ($a['placements'] as $p) if ($p['group'] !== null) $used[$p['group']] = true;
    $groups = array_values(array_filter(groups_list(), fn($g) => isset($used[$g['id']])));
    $out = ['categories' => $categories, 'groups' => $groups, 'apps' => array_map(fn($a) => app_public($a, $admin), $apps),
        'recommended' => recommended_for((int)$user['id'], array_column(array_filter($apps, fn($a) => $a['owned'] ?? true), 'id'))];
    if ($admin) $out['plugins'] = array_map(fn($a) => app_public($a, true), $plugins);
    return $out;
}

function app_public(array $a, bool $admin = false): array
{
    return [
        'id' => $a['id'], 'name' => $a['name'], 'icon' => $a['icon'], 'icon_svg' => $a['icon_svg'] ?? null,
        'description' => $a['description'], 'category' => $a['category'],
        // איפה היא מוצגת: [{category, group|null, sort}] — אותה אפליקציה יכולה להופיע בכמה מקומות
        'placements' => $a['placements'] ?? [],
        'href' => $a['href'], 'links' => $a['links'], 'external' => $a['external'],
        // false — בחנות ועוד לא אצלו: מוצגת אפורה עם חץ הורדה
        'owned' => $a['owned'] ?? true,
        // מה שהמנהל לא אישר — לא נשלח לדפדפן בכלל. "הורדת המשפחה" אינה התקנה
        // של אפליקציה נפרדת, ולכן offline לא תלוי באישור.
        'install' => [
            'pwa'     => $a['install_ok'] && $a['install']['pwa'],
            'apk'     => $a['install_ok'] ? $a['install']['apk'] : null,
            // חבילה וגרסה אחרונה — כדי להציג "פתיחה" / "עדכון" כשהאפליקציה כבר מותקנת
            'android' => $a['install_ok'] ? ($a['install']['android'] ?? null) : null,
            'store'   => $a['install_ok'] ? $a['install']['store'] : null,
            'offline' => $a['install']['offline'],
            // תיקיית החנות: AAB וקובץ ההגדרות ל-Play Console — רק למנהל (הקבצים עצמם ב-Release ציבורי;
            // אין בהם סוד — זה התוכן שממילא יופיע בחנות).
            'release' => $admin && $a['install_ok'] && $a['install']['release']
                ? ['aab' => $a['install']['release']['aab'], 'listing' => $a['install']['release']['listing']] : null,
        ],
    ];
}

/**
 * "מומלצות לך" בראש דף הבית (כמו חנות): קודם מה שהמשתמש פותח הכי הרבה ב-60 הימים האחרונים (visits,
 * עד 4), ואחריהן מה שהמנהל סימן כמומלצות (ההגדרה featured). רק מה שהמשתמש רואה, עד 8, בלי כפילויות.
 */
function recommended_for(int $userId, array $visible): array
{
    $out = [];
    try {
        $st = db()->prepare('SELECT app, COUNT(*) n FROM visits WHERE user_id = ? AND at > ? GROUP BY app ORDER BY n DESC LIMIT 12');
        $st->execute([$userId, gmdate('Y-m-d\TH:i:s\Z', time() - 60 * 86400)]);
        foreach ($st as $r) if (in_array($r['app'], $visible, true) && count($out) < 4) $out[] = $r['app'];
    } catch (Throwable) { /* אין עוד טבלת כניסות */ }
    foreach (featured_apps() as $id) if (in_array($id, $visible, true) && !in_array($id, $out, true)) $out[] = $id;
    return array_slice($out, 0, 8);
}

/** האפליקציות שהמנהל סימן כמומלצות — בסדר שבחר. */
function featured_apps(): array
{
    $st = db()->prepare("SELECT value FROM settings WHERE key = 'featured'");
    $st->execute();
    $v = json_decode((string)$st->fetchColumn(), true);
    return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
}

/** אפליקציה שמוצגת בחנות לכולם — לא מוסתרת ולא תוסף. */
function in_catalog(array $app): bool
{
    return $app['catalog'] && !$app['hidden'] && !$app['plugin'];
}

