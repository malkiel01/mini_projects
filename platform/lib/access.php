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
        CREATE TABLE IF NOT EXISTS install_approvals (
            app_id TEXT PRIMARY KEY  -- המנהל אישר להציג התקנה/הורדה לאפליקציה הזו
        );
        CREATE TABLE IF NOT EXISTS grants (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            kind    TEXT NOT NULL CHECK (kind IN ('app','category')),
            target  TEXT NOT NULL,
            allowed INTEGER NOT NULL,
            PRIMARY KEY (user_id, kind, target)
        );
        -- קטגוריות מ-categories.json שכבר נזרעו פעם אחת. כך קטגוריה שהמנהל
        -- מחק לא חוזרת בבקשה הבאה, וקטגוריה חדשה בקובץ כן נכנסת.
        CREATE TABLE IF NOT EXISTS seeded_categories (id TEXT PRIMARY KEY);
        SQL);

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
    $approved = array_flip(db()->query('SELECT app_id FROM install_approvals')->fetchAll(PDO::FETCH_COLUMN));

    $apps = [];
    foreach (registry_load()['apps'] as $app) {
        $o = $overrides[$app['id']] ?? null;
        $app['default_category'] = $app['category'];
        if ($o && $o['category'] && in_array($o['category'], $known, true)) $app['category'] = $o['category'];
        // קטגוריה שנמחקה — האפליקציה לא נעלמת, היא עוברת ל"אחר".
        if (!in_array($app['category'], $known, true)) $app['category'] = 'other';
        $app['hidden'] = (bool)($o['hidden'] ?? false);
        $app['install_ok'] = isset($approved[$app['id']]);
        $apps[] = $app;
    }
    return $apps;
}

/** @return array{apps: array<string,bool>, categories: array<string,bool>} */
function grants_of(int $userId): array
{
    access_migrate();
    $out = ['apps' => [], 'categories' => []];
    $st = db()->prepare('SELECT kind, target, allowed FROM grants WHERE user_id = ?');
    $st->execute([$userId]);
    foreach ($st as $g) {
        $out[$g['kind'] === 'app' ? 'apps' : 'categories'][$g['target']] = (bool)$g['allowed'];
    }
    return $out;
}

function can_see(array $app, array $grants): bool
{
    if ($app['hidden']) return false;
    if (array_key_exists($app['id'], $grants['apps'])) return $grants['apps'][$app['id']];
    // restricted: הרשאה לקטגוריה לא פותחת אותה — רק אישור ישיר לאפליקציה.
    if ($app['restricted']) return false;
    return $grants['categories'][$app['category']] ?? false;
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
    if (array_filter($apps, fn($a) => $a['category'] === 'other')) {
        $categories[] = ['id' => 'other', 'name' => 'אחר', 'icon' => '📦'];
    }
    return ['categories' => $categories, 'apps' => array_map('app_public', $apps)];
}

function app_public(array $a): array
{
    return [
        'id' => $a['id'], 'name' => $a['name'], 'icon' => $a['icon'],
        'description' => $a['description'], 'category' => $a['category'],
        'href' => $a['href'], 'links' => $a['links'],
        // מה שהמנהל לא אישר — לא נשלח לדפדפן בכלל. "הורדת המשפחה" אינה התקנה
        // של אפליקציה נפרדת, ולכן offline לא תלוי באישור.
        'install' => [
            'pwa'     => $a['install_ok'] && $a['install']['pwa'],
            'apk'     => $a['install_ok'] ? $a['install']['apk'] : null,
            'store'   => $a['install_ok'] ? $a['install']['store'] : null,
            'offline' => $a['install']['offline'],
        ],
    ];
}
