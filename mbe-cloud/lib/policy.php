<?php
/**
 * המנהל מחליט אילו שכבות דלוקות — לכל אפליקציה, לכל המשתמשים (docs/spec-6-admin-layers.md).
 *
 *   מה שמותר בקריאה = מדיניות הלקוח (clients.policy, כאן) ∩ מה שהאפליקציה הצהירה (apps.json) ∩ סוג המשתמש (spec-5, בהמשך)
 *
 * connections.scope כבר לא מקור סמכות: החיבור מחזיק את הקשירה ל-sub ואת מזהה החיבור של האסימון, וההיקף מחושב בכל קריאה.
 * policy: {"files": null | "files.app" | "files.readonly" | "files", "libs": bool, "appdata": bool, "ai": bool}. NULL — הכול כבוי.
 */

declare(strict_types=1);

require_once __DIR__ . '/clients.php';
require_once __DIR__ . '/grants.php';

/** היקפי הקבצים, מהצר לרחב. המנהל בוחר עד מה שהאפליקציה הצהירה (סעיף 2). */
const FILES_ORDER = ['files.app', 'files.readonly', 'files'];
const POLICY_KEYS = ['files', 'libs', 'appdata', 'ai'];

/**
 * clients.policy + הגירה חד-פעמית (סעיף 6, ש1): ללקוחות שקיימים בזמן ההגירה — כל מה שהוצהר דלוק.
 * 📁: files_full = 1 ← "files"; הצהרה צרה ← ההיקף שהוצהר; files המלא בלי files_full ← כבוי. אחרי ההגירה files_full לא נקרא.
 */
function migratePolicy(PDO $pdo): void {
    $cols = array_column($pdo->query('PRAGMA table_info(clients)')->fetchAll(), 'name');
    if (!in_array('policy', $cols, true)) $pdo->exec('ALTER TABLE clients ADD COLUMN policy TEXT');
    $done = $pdo->query("SELECT value FROM settings WHERE key = 'policy_migrated'")->fetchColumn();
    if ($done === '1') return;
    foreach ($pdo->query('SELECT id, files_full FROM clients')->fetchAll() as $r) {
        $p = ['files' => null, 'libs' => false, 'appdata' => false, 'ai' => false];
        foreach (clientDeclared($r['id'])['layers'] as $l) {
            if ($l !== 'files') { $p[$l] = true; continue; }
            $decl = clientFilesScope($r['id']);
            $p['files'] = $decl !== 'files' ? $decl : ((int) $r['files_full'] === 1 ? 'files' : null);
        }
        $pdo->prepare('UPDATE clients SET policy = ? WHERE id = ?')->execute([json_encode($p), $r['id']]);
    }
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('policy_migrated', '1') ON CONFLICT(key) DO UPDATE SET value = excluded.value");
}

/** מה שהאפליקציה הצהירה (התקרה): השכבות, והיקף הקבצים. */
function clientDeclared(string $clientId): array {
    $layers = [];
    foreach (clientScopes($clientId) as $sc) { $l = scopeLayer($sc); if (!in_array($l, $layers, true)) $layers[] = $l; }
    return ['layers' => $layers, 'files' => in_array('files', $layers, true) ? clientFilesScope($clientId) : null];
}

/** המדיניות של לקוח, חתוכה בתקרה (הצהרה שצומצמה בגיט חלה מיד). */
function clientPolicy(array $c): array {
    // תמיד מהמסד — שורת לקוח שנקראה לפני שינוי של המנהל לא תחזיר מדיניות ישנה
    $st = db()->prepare('SELECT policy FROM clients WHERE id = ?');
    $st->execute([$c['id']]);
    $raw = json_decode((string) ($st->fetchColumn() ?: ''), true);
    $raw = is_array($raw) ? $raw : [];
    $d = clientDeclared($c['id']);
    $p = ['files' => null, 'libs' => false, 'appdata' => false, 'ai' => false];
    foreach (['libs', 'appdata', 'ai'] as $l) $p[$l] = !empty($raw[$l]) && in_array($l, $d['layers'], true);
    $f = $raw['files'] ?? null;
    if (is_string($f) && $d['files'] !== null && in_array($f, FILES_ORDER, true)) {
        // עד מה שהוצהר: הצהרה שצומצמה אחרי הבחירה — חלה מיד
        $p['files'] = array_search($f, FILES_ORDER, true) <= array_search($d['files'], FILES_ORDER, true) ? $f : $d['files'];
    }
    return $p;
}

/** ההיקף בפועל של לקוח — הרשימה (ממוינת) שהאסימון, actor() ו-status מחזירים. */
function effectiveScope(array $c): array {
    $p = clientPolicy($c);
    $out = $p['files'] !== null ? [$p['files']] : [];
    foreach (['libs', 'appdata', 'ai'] as $l) if ($p[$l]) $out[] = $l;
    sort($out);
    return $out;
}

/** השכבות הדלוקות של לקוח (client_policy, סעיף 3.3). */
function effectiveLayers(array $c): array {
    return array_values(array_unique(array_map('scopeLayer', effectiveScope($c))));
}

/**
 * admin_client {client, policy}: עדכון חלקי — רק השדות שנשלחו. ערך מעבר לתקרה, או שכבה שלא הוצהרה — 400.
 * מחזיר את המדיניות החדשה.
 */
function setClientPolicy(array $c, mixed $in): array {
    if (!is_array($in)) throw new AppError('מדיניות לא תקינה', 400, 'bad_request');
    if (array_diff(array_keys($in), POLICY_KEYS)) throw new AppError('שכבה לא מוכרת', 400, 'bad_request');
    $d = clientDeclared($c['id']);
    $p = clientPolicy($c);
    foreach ($in as $k => $v) {
        if ($k === 'files') {
            if ($v === null || $v === false) { $p['files'] = null; continue; }
            if (!is_string($v) || !in_array($v, FILES_ORDER, true) || $d['files'] === null
                || array_search($v, FILES_ORDER, true) > array_search($d['files'], FILES_ORDER, true)) {
                throw new AppError('היקף קבצים מעבר למה שהאפליקציה הצהירה', 400, 'bad_request');
            }
            $p['files'] = $v;
            continue;
        }
        if (!is_bool($v)) throw new AppError('ערך לא תקין', 400, 'bad_request');
        if ($v && !in_array($k, $d['layers'], true)) throw new AppError('האפליקציה לא הצהירה על השכבה הזו', 400, 'bad_request');
        $p[$k] = $v;
    }
    db()->prepare('UPDATE clients SET policy = ?, updated_at = ? WHERE id = ?')->execute([json_encode($p), nowIso(), $c['id']]);
    return $p;
}

/** מצב השכבות של חיבור, לדף "🔗 חיבורים" (סעיף 4.1): on / off, לכל שכבה שהוצהרה. locked — עם spec-5. */
function connectionLayers(array $c): array {
    $p = clientPolicy($c);
    $out = [];
    foreach (clientDeclared($c['id'])['layers'] as $l) $out[$l] = ($l === 'files' ? $p['files'] !== null : $p[$l]) ? 'on' : 'off';
    return $out;
}

const ADMIN_MANAGED_MSG = 'השכבות נקבעות בידי מנהל הענן';

/** connection_scope, connection_layers, connection_revoke — מבוטלות (סעיף 5.1, ש2). */
function adminManaged(): never {
    throw new AppError(ADMIN_MANAGED_MSG, 403, 'admin_managed');
}
