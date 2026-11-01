<?php
/**
 * 🔑 החשבון בתפריט המשתמש (ui-core/php/account): "ייצוא כל הנתונים שלי" ו"מחיקת החשבון" של ענן החבילה.
 * החיבור — api.php, דרך uic_account_api (הגדרות אישיות בטבלה uic_prefs במסד שלנו).
 */

declare(strict_types=1);

require_once __DIR__ . '/drive.php';
require_once __DIR__ . '/fonts.php';
require_once __DIR__ . '/clients.php';
require_once __DIR__ . '/ai.php';

/** מה שיש לחשבון בענן, כ-JSON: העץ (בלי התוכן — הקבצים עצמם יורדים מהכונן), הגופנים, החיבורים והשימוש ב-AI. */
function accountExport(array $a): array {
    $q = function (string $sql) use ($a): array {
        $st = db()->prepare($sql);
        $st->execute([$a['id']]);
        return $st->fetchAll();
    };
    return [
        'account' => publicAccount($a) + ['created_at' => $a['created_at'] ?? null],
        'quota' => quotaInfo($a),
        'nodes' => array_map(fn($n) => array_intersect_key($n, array_flip(['id', 'parent', 'kind', 'name', 'size', 'sha256', 'mime', 'rev',
                                                                        'created_at', 'updated_at', 'trashed_at'])),
                             $q('SELECT * FROM nodes WHERE account = ? ORDER BY parent, name')),
        'fonts' => fontsList($a)['fonts'],
        'connections' => accountConnections($a),
        'ai_usage' => $q('SELECT * FROM ai_usage WHERE account = ? ORDER BY id'),
    ];
}

/**
 * מוחק את כל מה שלחשבון בענן: קבצים, סל, העלאות פתוחות, גופנים, חיבורי אפליקציות, הרשאות ושימוש ב-AI, והחשבון.
 * התוכן נמחק מהדיסק כשאף חשבון אחר לא מחזיק אותו. כניסה הבאה דרך השער — חשבון חדש, ריק (נפח לפי ברירת המחדל).
 */
function accountDelete(array $a): void {
    $pdo = db();
    $id = $a['id'];
    $shas = $pdo->prepare("SELECT sha256 FROM nodes WHERE account = ? AND kind = 'file' UNION SELECT sha256 FROM lib_items WHERE account = ?");
    $shas->execute([$id, $id]);
    $shas = array_unique(array_filter($shas->fetchAll(PDO::FETCH_COLUMN)));
    $uploads = $pdo->prepare('SELECT id FROM uploads WHERE account = ?');
    $uploads->execute([$id]);
    $uploads = $uploads->fetchAll(PDO::FETCH_COLUMN);

    $own = !$pdo->inTransaction();   // ui-core פותח טרנזקציה סביב ה-hook — מצטרפים אליה
    if ($own) $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM codes WHERE connection IN (SELECT id FROM connections WHERE account = ?)')->execute([$id]);
        foreach (['connections', 'nodes', 'uploads', 'lib_items', 'ai_users', 'ai_usage'] as $t) {
            $pdo->prepare("DELETE FROM $t WHERE account = ?")->execute([$id]);
        }
        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    foreach ($uploads as $u) @unlink(partFile($u));
    foreach ($shas as $sha) dropBlobIfUnused($sha);
}
