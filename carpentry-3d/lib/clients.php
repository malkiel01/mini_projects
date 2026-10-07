<?php
/**
 * לקוחות והרכבות.
 *
 * לקוח הוא רשומה (שם, טלפון, דוא"ל, כתובת, הערות) שפרויקטים מצביעים עליה —
 * לא רק טקסט של שם. הרשימה משותפת לכל הנגרים (אותו בית מלאכה).
 *
 * הרכבה: כמה אלמנטים (פרויקטים) של אותו לקוח יחד בסצנה אחת — כל פריט עם
 * מיקום וסיבוב. השרת שומר את הרשימה; הדפדפן בונה את המודלים ומניח אותם.
 * "מאוחד" = נחשב מוצר אחד בפלט הייצור.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

// ---- לקוחות ----

function clientRow(array $c): array {
    return [
        'id' => (int) $c['id'], 'name' => $c['name'], 'phone' => $c['phone'], 'email' => $c['email'],
        'address' => $c['address'], 'notes' => $c['notes'],
        'projects' => (int) ($c['projects'] ?? 0), 'assemblies' => (int) ($c['assemblies'] ?? 0),
        'created_at' => $c['created_at'], 'updated_at' => $c['updated_at'],
    ];
}

function clientsList(): array {
    $rows = db()->query('SELECT c.*,
        (SELECT COUNT(*) FROM projects p WHERE p.client_id = c.id) AS projects,
        (SELECT COUNT(*) FROM assemblies a WHERE a.client_id = c.id) AS assemblies
        FROM clients c ORDER BY c.name COLLATE NOCASE')->fetchAll();
    return array_map('clientRow', $rows);
}

function clientGet(int $id): array {
    $st = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) throw new AppError('הלקוח לא נמצא', 404);
    return $c;
}

function clientSave(array $user, array $in): array {
    $id = (int) ($in['id'] ?? 0);
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80);
    if ($name === '') throw new AppError('ללקוח צריך שם');
    $f = fn (string $k, int $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
    $now = nowIso();
    if ($id) {
        clientGet($id);
        db()->prepare('UPDATE clients SET name=?, phone=?, email=?, address=?, notes=?, updated_at=? WHERE id=?')
            ->execute([$name, $f('phone', 40), $f('email', 120), $f('address', 200), $f('notes', 2000), $now, $id]);
        // שם הלקוח משוכפל בפרויקטים (תצוגה, וצפיית לקוח) — מעדכנים
        db()->prepare('UPDATE projects SET client = ? WHERE client_id = ?')->execute([$name, $id]);
    } else {
        db()->prepare('INSERT INTO clients (name, phone, email, address, notes, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$name, $f('phone', 40), $f('email', 120), $f('address', 200), $f('notes', 2000), $user['id'], $now, $now]);
        $id = (int) db()->lastInsertId();
    }
    return clientRow(clientWithCounts($id));
}

function clientWithCounts(int $id): array {
    $st = db()->prepare('SELECT c.*,
        (SELECT COUNT(*) FROM projects p WHERE p.client_id = c.id) AS projects,
        (SELECT COUNT(*) FROM assemblies a WHERE a.client_id = c.id) AS assemblies
        FROM clients c WHERE c.id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) throw new AppError('הלקוח לא נמצא', 404);
    return $c;
}

/** מוחק לקוח. הפרויקטים נשארים בלי שיוך, ההרכבות שלו נמחקות. */
function clientDelete(int $id): void {
    clientGet($id);
    $pdo = db();
    // גם הטקסט מתרוקן — אחרת ההסבה בטעינה הבאה הייתה יוצרת את הלקוח מחדש מהשם
    $pdo->prepare("UPDATE projects SET client_id = NULL, client = '' WHERE client_id = ?")->execute([$id]);
    $pdo->prepare('DELETE FROM assemblies WHERE client_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
}

/** לקוח לפי שם — קיים או חדש. להסבת פרויקטים ישנים ולטופס "לקוח חדש" מהיר. */
function clientByName(string $name, ?int $createdBy): ?int {
    $name = mb_substr(trim($name), 0, 80);
    if ($name === '') return null;
    $st = db()->prepare('SELECT id FROM clients WHERE name = ? COLLATE NOCASE');
    $st->execute([$name]);
    $id = $st->fetchColumn();
    if ($id) return (int) $id;
    $now = nowIso();
    db()->prepare('INSERT INTO clients (name, created_by, created_at, updated_at) VALUES (?,?,?,?)')->execute([$name, $createdBy, $now, $now]);
    return (int) db()->lastInsertId();
}

// ---- הרכבות ----

function assemblyRow(array $a, bool $full = false): array {
    $out = [
        'id' => (int) $a['id'], 'owner_id' => (int) $a['owner_id'], 'owner_name' => $a['owner_name'] ?? null,
        'client_id' => $a['client_id'] !== null ? (int) $a['client_id'] : null, 'client_name' => $a['client_name'] ?? null,
        'name' => $a['name'], 'joined' => (int) $a['joined'] === 1, 'shared' => $a['share_token'] !== null,
        'created_at' => $a['created_at'], 'updated_at' => $a['updated_at'],
    ];
    if ($full) {
        $out['items'] = array_values(array_filter(jsonArr($a['items_json']), 'is_array'));
        $out['share_token'] = $a['share_token'];
    }
    return $out;
}

const ASSEMBLY_SQL = 'SELECT a.*, u.name AS owner_name, c.name AS client_name FROM assemblies a JOIN users u ON u.id = a.owner_id LEFT JOIN clients c ON c.id = a.client_id';

function assembliesList(array $user, ?int $clientId = null): array {
    $sql = ASSEMBLY_SQL; $where = []; $args = [];
    if ($user['role'] !== 'admin') { $where[] = 'a.owner_id = ?'; $args[] = $user['id']; }
    if ($clientId) { $where[] = 'a.client_id = ?'; $args[] = $clientId; }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY a.updated_at DESC';
    $st = db()->prepare($sql);
    $st->execute($args);
    return array_map(fn ($a) => assemblyRow($a), $st->fetchAll());
}

function assemblyGet(array $user, int $id): array {
    $st = db()->prepare(ASSEMBLY_SQL . ' WHERE a.id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a || ($user['role'] !== 'admin' && (int) $a['owner_id'] !== (int) $user['id'])) throw new AppError('ההרכבה לא נמצאה', 404);
    return $a;
}

/** פריטי ההרכבה: project_id, pos [x,y,z], rot (0/90/180/270), visible. */
function cleanItems(array $items): array {
    $out = [];
    foreach ($items as $it) {
        if (!is_array($it) || !isset($it['project_id'])) continue;
        $pos = is_array($it['pos'] ?? null) ? array_values($it['pos']) : [0, 0, 0];
        $pos = [ (float) ($pos[0] ?? 0), (float) ($pos[1] ?? 0), (float) ($pos[2] ?? 0) ];
        $rot = (int) ($it['rot'] ?? 0);
        $out[] = ['project_id' => (int) $it['project_id'], 'pos' => $pos, 'rot' => in_array($rot, [0, 90, 180, 270], true) ? $rot : 0, 'visible' => !isset($it['visible']) || (bool) $it['visible']];
        if (count($out) >= 40) break;
    }
    return $out;
}

/** ההרכבה עם הפרויקטים שלה (מלאים — הדפדפן בונה מהם את המודלים). */
function assemblyFull(array $user, array $a): array {
    $row = assemblyRow($a, true);
    $ids = array_values(array_unique(array_map(fn ($it) => (int) $it['project_id'], $row['items'])));
    $row['projects'] = [];
    if ($ids) {
        $st = db()->prepare('SELECT p.*, u.name AS owner_name FROM projects p JOIN users u ON u.id = p.owner_id WHERE p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        foreach ($st->fetchAll() as $p) $row['projects'][] = projectRow($p, true);
    }
    return $row;
}

function assemblySave(array $user, array $in): array {
    $id = (int) ($in['id'] ?? 0);
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80);
    if ($name === '') $name = 'הרכבה';
    $clientId = isset($in['client_id']) && $in['client_id'] !== null && (int) $in['client_id'] > 0 ? (int) $in['client_id'] : null;
    if ($clientId) clientGet($clientId);
    $items = cleanItems(is_array($in['items'] ?? null) ? $in['items'] : []);
    $joined = !empty($in['joined']) ? 1 : 0;
    $now = nowIso();
    if ($id) {
        assemblyGet($user, $id);
        db()->prepare('UPDATE assemblies SET name=?, client_id=?, items_json=?, joined=?, updated_at=? WHERE id=?')
            ->execute([$name, $clientId, jsonStr($items), $joined, $now, $id]);
    } else {
        db()->prepare('INSERT INTO assemblies (owner_id, client_id, name, items_json, joined, created_at, updated_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$user['id'], $clientId, $name, jsonStr($items), $joined, $now, $now]);
        $id = (int) db()->lastInsertId();
    }
    return assemblyFull($user, assemblyGet($user, $id));
}

function assemblyDelete(array $user, int $id): void {
    assemblyGet($user, $id);
    db()->prepare('DELETE FROM assemblies WHERE id = ?')->execute([$id]);
}

function assemblyShare(array $user, int $id, bool $on): array {
    assemblyGet($user, $id);
    $token = $on ? bin2hex(random_bytes(16)) : null;
    db()->prepare('UPDATE assemblies SET share_token = ? WHERE id = ?')->execute([$token, $id]);
    return assemblyFull($user, assemblyGet($user, $id));
}

/** צפיית לקוח בהרכבה: לפי אסימון, בלי כניסה, בלי מחירים. */
function assemblyByToken(string $token): array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) throw new AppError('הקישור אינו תקין', 404);
    $st = db()->prepare(ASSEMBLY_SQL . ' WHERE a.share_token = ?');
    $st->execute([$token]);
    $a = $st->fetch();
    if (!$a) throw new AppError('הקישור אינו תקף יותר', 404);
    $row = assemblyFull(['role' => 'admin', 'id' => 0], $a);
    $row['projects'] = array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'template_key' => $p['template_key'], 'values' => $p['values']], $row['projects']);
    unset($row['share_token'], $row['owner_id']);
    return $row;
}
