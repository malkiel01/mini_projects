<?php
declare(strict_types=1);

/**
 * שכבה דקה מול הממשק של פרוסה 01 (docs/spec/01-infrastructure.md): סודות, אירועים, הרשאות וארגון פעיל.
 * כל פונקציה כאן היא עטיפה: כשהפונקציה של 01 קיימת — קוראים לה; כשלא — מימוש זמני מינימלי.
 * ביישור מול 01 הענפים הזמניים יוסרו, והעטיפות יישארו נקודת החיבור היחידה של Dropbox לתשתית.
 * הקובץ אינו נקודת קצה.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

/** מזהה הארגון של "סודות כלליים" (App key / App secret של אפליקציית ה-Dropbox) — לא ארגון אמיתי. */
const DBX_GLOBAL_ORG = 0;

function dbxSecretKey(): string {
    static $key;
    if (is_string($key)) return $key;
    $path = __DIR__ . '/../data/secret.key';
    if (!is_file($path)) {
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $old = umask(0077);
        $fh = @fopen($path, 'x');
        if ($fh !== false) { fwrite($fh, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)); fclose($fh); }
        umask($old);
        @chmod($path, 0600);
    }
    $key = (string)@file_get_contents($path);
    if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('secret.key אינו תקין');
    return $key;
}
function dbxSecretsTable(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS secrets (name TEXT NOT NULL, org_id INTEGER NOT NULL, value_enc TEXT NOT NULL, updated_at TEXT NOT NULL, PRIMARY KEY(name, org_id))');
}
function dbxSecretPut(string $name, int $orgId, string $value): void {
    if (function_exists('secretPut')) { secretPut($name, $orgId, $value); return; }
    $pdo = db();
    dbxSecretsTable($pdo);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $enc = base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, dbxSecretKey()));
    $pdo->prepare('INSERT INTO secrets(name,org_id,value_enc,updated_at) VALUES(?,?,?,?)
        ON CONFLICT(name,org_id) DO UPDATE SET value_enc=excluded.value_enc, updated_at=excluded.updated_at')
        ->execute([$name, $orgId, $enc, gmdate('c')]);
}
function dbxSecretGet(string $name, int $orgId): ?string {
    if (function_exists('secretGet')) { $v = secretGet($name, $orgId); return is_string($v) && $v !== '' ? $v : null; }
    $pdo = db();
    dbxSecretsTable($pdo);
    $st = $pdo->prepare('SELECT value_enc FROM secrets WHERE name=? AND org_id=?');
    $st->execute([$name, $orgId]);
    $raw = base64_decode((string)$st->fetchColumn(), true);
    if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), dbxSecretKey());
    return $plain === false || $plain === '' ? null : $plain;
}
/** ניתוק: הסוד נמחק מהמסד (01 מגדירה רק put/get — מחיקה ישירה מהטבלה המשותפת). */
function dbxSecretDelete(string $name, int $orgId): void {
    $pdo = db();
    dbxSecretsTable($pdo);
    $pdo->prepare('DELETE FROM secrets WHERE name=? AND org_id=?')->execute([$name, $orgId]);
}

/** שורה ב-events. $source: ui / system; $diff — בלי סודות, לעולם. */
function dbxEvent(int $orgId, ?string $caseId, string $source, string $action, array $diff, ?array $actor = null): void {
    if (function_exists('recordEvent')) {
        recordEvent(['org_id' => $orgId, 'case_id' => $caseId, 'source' => $source, 'action' => $action, 'diff' => $diff]);
        return;
    }
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER, case_id TEXT, at TEXT NOT NULL,
        actor_user_id INTEGER, actor TEXT, source TEXT NOT NULL, action TEXT NOT NULL, diff TEXT)');
    $pdo->prepare('INSERT INTO events(org_id,case_id,at,actor,source,action,diff) VALUES(?,?,?,?,?,?,?)')
        ->execute([$orgId, $caseId, gmdate('c'), $actor['actor'] ?? ($source === 'system' ? 'system' : ''), $source, $action,
            json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}

/**
 * מי המבקש: ['org_id'=>int, 'role'=>admin|office|field, 'developer'=>bool, 'actor'=>string] או null (אין ארגון).
 * זמני: בלי 01 — כל משתמש מהשער הוא מנהל של ארגון 1. בשרת הפיתוח של הבדיקות בלבד אפשר לקבוע ארגון ותפקיד
 * בשדות test_org / test_role של הזהות המדומה (השער האמיתי לא כותב אותם, והם נקראים רק תחת cli-server).
 */
function dbxWho(array $user): ?array {
    if (function_exists('currentMember')) {
        $m = currentMember();
        if (!is_array($m)) return null;
        return ['org_id' => (int)$m['org_id'], 'role' => (string)$m['role'], 'developer' => !empty($m['developer']),
            'actor' => (string)($m['actor'] ?? $user['actor'])];
    }
    $raw = json_decode((string)($_SERVER['PLATFORM_USER'] ?? ''), true);
    $org = 1; $role = 'admin';
    if (PHP_SAPI === 'cli-server' && is_array($raw)) {
        if (isset($raw['test_org'])) $org = (int)$raw['test_org'];
        if (isset($raw['test_role'])) $role = (string)$raw['test_role'];
    }
    return ['org_id' => $org, 'role' => $role, 'developer' => is_array($raw) && ($raw['platform_admin'] ?? null) === true,
        'actor' => $user['actor']];
}
/**
 * ההרשאות של Dropbox. dropbox_admin — חיבור, תיקייה ראשית, סריקה ושיוך; files_upload — העלאה ופתיחת תיקייה;
 * files_view — רשימת הקבצים ותצוגה; developer — מסך המפתח.
 */
function dbxCan(array $who, string $perm): bool {
    if (function_exists('can')) {
        $map = ['dropbox_admin' => 'manage_members', 'files_upload' => 'edit_details', 'files_view' => 'view_cases', 'developer' => 'developer'];
        return can($map[$perm] ?? $perm);
    }
    if ($who['developer']) return true;
    return match ($perm) {
        'dropbox_admin' => $who['role'] === 'admin',
        'files_upload' => in_array($who['role'], ['admin', 'office'], true),
        'files_view' => in_array($who['role'], ['admin', 'office', 'field'], true),
        default => false,
    };
}
/** התיק בארגון (או null — כמו "לא קיים"). בלי עמודת org_id (לפני 01) — כל התיקים בארגון 1. */
function dbxCase(PDO $pdo, int $orgId, string $caseId): ?array {
    static $hasOrg;
    $hasOrg ??= (bool)array_filter($pdo->query('PRAGMA table_info(funerals)')->fetchAll(), static fn($c) => $c['name'] === 'org_id');
    if (!$hasOrg && $orgId !== 1) return null;
    $st = $hasOrg ? $pdo->prepare('SELECT body FROM funerals WHERE id=? AND org_id=?') : $pdo->prepare('SELECT body FROM funerals WHERE id=?');
    $st->execute($hasOrg ? [$caseId, $orgId] : [$caseId]);
    $b = $st->fetchColumn();
    return $b === false ? null : json_decode((string)$b, true);
}
/** כל התיקים של הארגון (לסריקת ההתאמה): [id, name, date, month]. */
function dbxCases(PDO $pdo, int $orgId): array {
    $hasOrg = (bool)array_filter($pdo->query('PRAGMA table_info(funerals)')->fetchAll(), static fn($c) => $c['name'] === 'org_id');
    if (!$hasOrg && $orgId !== 1) return [];
    $st = $hasOrg ? $pdo->prepare('SELECT body FROM funerals WHERE org_id=?') : $pdo->prepare('SELECT body FROM funerals');
    $st->execute($hasOrg ? [$orgId] : []);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $b) {
        $c = json_decode((string)$b, true);
        if (is_array($c)) $out[] = ['id' => $c['id'], 'name' => (string)($c['name'] ?? ''), 'date' => (string)($c['date'] ?? ''), 'month' => (string)($c['month'] ?? '')];
    }
    return $out;
}
