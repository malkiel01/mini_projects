<?php
declare(strict_types=1);

/**
 * נקודת החיבור היחידה של Dropbox לתשתית (פרוסה 01): סודות (lib/secrets.php), אירועים (lib/events.php),
 * הרשאות וארגון פעיל (lib/access.php — ctx, can, scope). כל השאר ב-dropbox/ לא קורא לפונקציות של 01 ישירות.
 * הקובץ אינו נקודת קצה.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

/** מזהה הארגון של "סודות כלליים" (App key / App secret של אפליקציית ה-Dropbox) — העמודה secrets.org_id, ברירת מחדל 0. */
const DBX_GLOBAL_ORG = 0;
/** המבצע של פעולות רקע (webhook, cron, סנכרון) ביומן. */
const DBX_SYSTEM_ACTOR = ['id' => null, 'actor' => 'system'];

function dbxSecretPut(string $name, int $orgId, string $value): void { secretPut($name, $orgId, $value); }
function dbxSecretGet(string $name, int $orgId): ?string {
    $v = secretGet($name, $orgId);
    return is_string($v) && $v !== '' ? $v : null;
}
/** ניתוק: הסוד נמחק מהמסד (ל-01 יש put/get בלבד — מחיקה ישירה מהטבלה המשותפת). */
function dbxSecretDelete(string $name, int $orgId): void {
    db()->prepare('DELETE FROM secrets WHERE name = ? AND org_id = ?')->execute([$name, $orgId]);
}

/**
 * שורה ב-events. $source: ui / system. $diff — שדה => [לפני, אחרי], כמו בשאר האפליקציה; לעולם בלי סודות.
 * $actor — ['id','actor'] (ctx()['actor']); null → system.
 */
function dbxEvent(int $orgId, ?string $caseId, string $source, string $action, array $diff, ?array $actor = null): void {
    recordEvent($orgId === DBX_GLOBAL_ORG ? null : $orgId, $caseId, $actor ?? DBX_SYSTEM_ACTOR, $source, $action, $diff);
}

/** מי המבקש (אחרי resolveAccess): ארגון פעיל, תפקיד, מפתח, ומבצע ליומן. בלי ארגון — null. */
function dbxWho(): ?array {
    $c = ctx();
    if ($c['org'] === null || !$c['active']) return null;
    return ['org_id' => scope(), 'role' => $c['role'], 'developer' => (bool)$c['dev'], 'actor' => $c['actor'],
        'actor_label' => (string)$c['actor']['actor']];
}
/**
 * ההרשאות של Dropbox — דרך can() בלבד (PERMS ב-lib/access.php):
 * dropbox_admin → dropbox.manage (חיבור, ראשית, סריקה ושיוך); files_upload → files.upload (העלאה ופתיחת תיקייה);
 * files_view → case.view; developer → dev.
 */
function dbxCan(array $who, string $perm): bool {
    return can(['dropbox_admin' => 'dropbox.manage', 'files_upload' => 'files.upload', 'files_view' => 'case.view', 'developer' => 'dev'][$perm] ?? $perm);
}
/** התיק בארגון (או null — כמו "לא קיים", הכרעה ה5). */
function dbxCase(PDO $pdo, int $orgId, string $caseId): ?array {
    $st = $pdo->prepare('SELECT body FROM funerals WHERE id = ? AND org_id = ?');
    $st->execute([$caseId, $orgId]);
    $b = $st->fetchColumn();
    return $b === false ? null : json_decode((string)$b, true);
}
/** כל התיקים של הארגון (לסריקת ההתאמה): [id, name, date, month]. */
function dbxCases(PDO $pdo, int $orgId): array {
    $st = $pdo->prepare('SELECT body FROM funerals WHERE org_id = ?');
    $st->execute([$orgId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $b) {
        $c = json_decode((string)$b, true);
        if (is_array($c)) $out[] = ['id' => $c['id'], 'name' => (string)($c['name'] ?? ''), 'date' => (string)($c['date'] ?? ''), 'month' => (string)($c['month'] ?? '')];
    }
    return $out;
}
