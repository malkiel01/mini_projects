<?php
/**
 * אפליקציות שנסגרו (platform/retired.json) — רץ בשרת אחרי כל פריסה (deploy.yml).
 *
 *   php platform/tools/retire-apps.php
 *
 * הפריסה (rsync בלי --delete) לא מוחקת תיקייה שנמחקה מהריפו — היא הייתה נשארת בשרת, והרישום היה ממשיך
 * להציג אותה. כאן, לכל אפליקציה ברשימה שעוד קיימת בשרת:
 *   1. הגישה עוברת: מי שרשאי לפתוח אותה ואינו רשאי לפתוח את merged_into — מקבל הרשאה ישירה ל-merged_into
 *      (גם אם היא נחסמה לו במפורש — הוכרע בכל מקרה לגופו לפני שנכנס לרשימה).
 *   2. ההרשאות, ההגדרות ורישומי ההתקנה שלה נמחקים.
 *   3. התיקייה שלה ותיקיית החנות שלה (platform/downloads/<id>) נמחקות.
 * חוזר על עצמו בלי נזק: אפליקציה שכבר נמחקה — רק ניקוי שאריות במסד.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/registry.php';
require __DIR__ . '/../lib/access.php';

$root = realpath(__DIR__ . '/../..');
$list = json_decode((string)@file_get_contents(__DIR__ . '/../retired.json'), true);
if (!is_array($list) || !$root) exit(0);

function rm_tree(string $path): void
{
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $f) if ($f !== '.' && $f !== '..') rm_tree("$path/$f");
    @rmdir($path);
}

foreach ($list as $r) {
    $id = (string)($r['id'] ?? '');
    $into = (string)($r['merged_into'] ?? '');
    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $id) || $id === 'platform') continue;
    $dir = "$root/$id";

    if (is_dir($dir) && $into !== '') {
        $apps = [];
        foreach (apps_effective() as $a) $apps[$a['id']] = $a;
        if (isset($apps[$id], $apps[$into])) {
            $users = db()->query("SELECT id, display_name FROM users WHERE role <> 'admin' AND status = 'active'")->fetchAll();
            $grant = db()->prepare("INSERT INTO grants (user_id, kind, target, allowed) VALUES (?, 'app', ?, 1)
                                    ON CONFLICT(user_id, kind, target) DO UPDATE SET allowed = 1");
            foreach ($users as $u) {
                $g = grants_of((int)$u['id']);
                if (can_see($apps[$id], $g) && !can_see($apps[$into], $g)) {
                    $grant->execute([(int)$u['id'], $into]);
                    echo "$id → $into: הרשאה ל-{$u['display_name']} (#{$u['id']})\n";
                }
            }
        }
    }

    foreach (["DELETE FROM grants WHERE kind = 'app' AND target = ?", 'DELETE FROM app_overrides WHERE app_id = ?',
              'DELETE FROM app_detached WHERE app_id = ?', 'DELETE FROM install_hidden WHERE app_id = ?',
              'DELETE FROM app_login WHERE app_id = ?', 'DELETE FROM device_apps WHERE app_id = ?'] as $sql) {
        try { db()->prepare($sql)->execute([$id]); } catch (PDOException) { /* הטבלה עוד לא קיימת */ }
    }
    if (is_dir($dir)) { rm_tree($dir); echo "$id: התיקייה נמחקה\n"; }
    if (is_dir("$root/platform/downloads/$id")) { rm_tree("$root/platform/downloads/$id"); echo "$id: תיקיית החנות נמחקה\n"; }
}
