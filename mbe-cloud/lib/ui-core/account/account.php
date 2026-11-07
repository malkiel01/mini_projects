<?php
/**
 * ui-core — צד השרת של תפריט המשתמש: הגדרות אישיות, ייצוא "כל הנתונים שלי", ומחיקת חשבון. אחד לכל האפליקציות.
 * ההכרעה: creative-suite/docs/requests/account-menu.md (עדכון 2026-10-31 — "לרשום את הקוד באזור של כולם").
 * לא לערוך את העותק שבתוך אפליקציה — המקור: creative-suite/ui-core/src/php/account/ (sync + שער בפריסה).
 *
 * **הנתונים נשארים של האפליקציה:** הטבלאות נוצרות במסד שלה (uic_prefs), ושום דבר לא יוצא ממנה. כך היא עצמאית.
 *
 * החיבור שהאפליקציה כותבת — פעם אחת, ב-api.php שלה:
 *
 *   require_once __DIR__ . '/lib/ui-core/account/account.php';
 *   $hooks = [
 *       'pdo'    => fn() => db(),                               // המסד של האפליקציה (SQLite)
 *       'user'   => fn() => currentUserId(),                     // המשתמש המחובר (מחרוזת / מספר), או null
 *       'export' => fn($uid) => ['docs' => myDocs($uid)],        // רשות — הנתונים של האפליקציה לייצוא
 *       'delete' => fn($uid) => deleteMyUser($uid),              // רשות — מחיקת המשתמש והנתונים שלו אצלה
 *   ];
 *   if (str_starts_with($action, 'account_')) reply(uic_account_api($action, $input, $hooks));
 *
 * הפעולות (POST JSON, אחרי בדיקת ה-CSRF של האפליקציה — היא לפני הקריאה):
 *   account_prefs          → { prefs: { <ns>: <obj>, … } }       כל ההגדרות של המשתמש
 *   account_prefs_set      { ns, value }  → { ok: true }          ns: [a-z0-9_.-]{1,40}; value: אובייקט JSON, עד 64KB
 *   account_export         → { app, user, exported, prefs, data } הכול כ-JSON (הדפדפן מוריד אותו)
 *   account_delete         { confirm: 'DELETE' } → { ok: true }   ה-hook של האפליקציה ואז ההגדרות, בטרנזקציה אחת
 *
 * שגיאה — UicAccountError עם status (401 לא מחובר, 400 בקשה פגומה, 404 פעולה לא ידועה, 501 אין hook).
 */

declare(strict_types=1);

final class UicAccountError extends RuntimeException {
    public function __construct(string $message, public readonly int $status = 400) { parent::__construct($message); }
}

/** הטבלה במסד של האפליקציה. IF NOT EXISTS — פריסה על מסד קיים לא דורסת. */
function uic_account_migrate(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS uic_prefs (
        user_id TEXT NOT NULL,
        ns TEXT NOT NULL,
        value TEXT NOT NULL,
        updated TEXT NOT NULL,
        PRIMARY KEY (user_id, ns)
    )');
}

function uic_account_api(string $action, array $input, array $hooks): array {
    $pdo = ($hooks['pdo'] ?? null) ? $hooks['pdo']() : null;
    if (!$pdo instanceof PDO) throw new UicAccountError('ui-core/account: חסר hook של pdo', 500);
    $uid = ($hooks['user'] ?? null) ? $hooks['user']() : null;
    if ($uid === null || $uid === '') throw new UicAccountError('לא מחובר', 401);
    $uid = (string) $uid;
    uic_account_migrate($pdo);

    switch ($action) {
        case 'account_prefs':
            return ['prefs' => uic_prefs_all($pdo, $uid)];

        case 'account_prefs_set':
            $ns = (string) ($input['ns'] ?? '');
            if (!preg_match('/^[a-z0-9_.-]{1,40}$/', $ns)) throw new UicAccountError('ns לא חוקי');
            $value = $input['value'] ?? null;
            if (!is_array($value)) throw new UicAccountError('value חייב להיות אובייקט');
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || strlen($json) > 65536) throw new UicAccountError('ההגדרות גדולות מדי');
            $pdo->prepare('INSERT INTO uic_prefs (user_id, ns, value, updated) VALUES (?, ?, ?, ?)
                           ON CONFLICT (user_id, ns) DO UPDATE SET value = excluded.value, updated = excluded.updated')
                ->execute([$uid, $ns, $json, gmdate('c')]);
            return ['ok' => true];

        case 'account_export':
            return [
                'app' => $hooks['app'] ?? null,
                'user' => $uid,
                'exported' => gmdate('c'),
                'prefs' => uic_prefs_all($pdo, $uid),
                'data' => ($hooks['export'] ?? null) ? $hooks['export']($uid) : null,
            ];

        case 'account_delete':
            if (($input['confirm'] ?? '') !== 'DELETE') throw new UicAccountError('חסר אישור מחיקה');
            if (!($hooks['delete'] ?? null)) throw new UicAccountError('מחיקת חשבון לא זמינה באפליקציה הזו', 501);
            // קודם האפליקציה, ואז ההגדרות — בטרנזקציה אחת: נכשל באמצע → שום דבר לא נמחק
            $own = !$pdo->inTransaction();
            if ($own) $pdo->beginTransaction();
            try {
                $hooks['delete']($uid);
                $pdo->prepare('DELETE FROM uic_prefs WHERE user_id = ?')->execute([$uid]);
                if ($own) $pdo->commit();
            } catch (Throwable $e) {
                if ($own && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            return ['ok' => true];
    }
    throw new UicAccountError('פעולה לא ידועה: ' . $action, 404);
}

function uic_prefs_all(PDO $pdo, string $uid): array {
    $st = $pdo->prepare('SELECT ns, value FROM uic_prefs WHERE user_id = ? ORDER BY ns');
    $st->execute([$uid]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['ns']] = json_decode($r['value'], true);
    return $out;
}
