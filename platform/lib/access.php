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
    $sql = (string)db()->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'grants'")->fetchColumn();
    if (!str_contains($sql, "'group'")) {
        db()->exec(<<<SQL
            BEGIN;
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
            COMMIT;
            SQL);
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
        $pdo->prepare('INSERT OR IGNORE INTO groups (id, name, icon, sort) VALUES (?,?,?,?)')->execute([$s['id'], $s['name'], $s['icon'], ++$sort]);
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
    return db()->query('SELECT id, name, icon FROM groups ORDER BY sort, id')->fetchAll();
}

function categories_list(): array
{
    access_migrate();
    return db()->query('SELECT id, name, icon FROM categories ORDER BY sort, id')->fetchAll();
}

/** כל האפליקציות, עם הקטגוריה בפועל והאם הוסתרה. */
function apps_effective(): array
{
    access_migrate();
    $overrides = [];
    foreach (db()->query('SELECT * FROM app_overrides') as $o) $overrides[$o['app_id']] = $o;
    $known = array_column(categories_list(), 'id');
    $installHidden = array_flip(db()->query('SELECT app_id FROM install_hidden')->fetchAll(PDO::FETCH_COLUMN));
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
        $apps = array_values(array_filter($apps, fn($a) => can_see($a, $grants)));
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
    $out = ['categories' => $categories, 'groups' => $groups, 'apps' => array_map(fn($a) => app_public($a, $admin), $apps)];
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
