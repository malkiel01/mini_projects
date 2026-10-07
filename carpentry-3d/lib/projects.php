<?php
/**
 * פרויקטים: המופעים שהנגר יוצר. נגר רואה את שלו; מנהל רואה את כולם.
 * הערכים (values_json) הם מה שהטופס מחזיק — המודל נבנה מהם בדפדפן, והשרת
 * לא מפרש אותם. קישור הלקוח הוא אסימון אקראי שפותח צפייה בלי כניסה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/clients.php';

function projectRow(array $p, bool $full = false): array {
    $out = [
        'id' => (int) $p['id'], 'owner_id' => (int) $p['owner_id'], 'owner_name' => $p['owner_name'] ?? null,
        'product_type_id' => $p['product_type_id'] !== null ? (int) $p['product_type_id'] : null,
        'template_key' => $p['template_key'], 'name' => $p['name'], 'client' => $p['client'],
        'client_id' => $p['client_id'] !== null ? (int) $p['client_id'] : null,
        'status' => $p['status'], 'shared' => $p['share_token'] !== null,
        'created_at' => $p['created_at'], 'updated_at' => $p['updated_at'],
    ];
    if ($full) {
        $out['values'] = jsonArr($p['values_json']);
        $out['share_token'] = $p['share_token'];
    }
    return $out;
}

function projectsList(array $user, ?int $clientId = null): array {
    $sql = 'SELECT p.*, u.name AS owner_name FROM projects p JOIN users u ON u.id = p.owner_id';
    $where = []; $args = [];
    if ($user['role'] !== 'admin') { $where[] = 'p.owner_id = ?'; $args[] = $user['id']; }
    if ($clientId) { $where[] = 'p.client_id = ?'; $args[] = $clientId; }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY p.updated_at DESC';
    $st = db()->prepare($sql);
    $st->execute($args);
    return array_map(fn ($p) => projectRow($p), $st->fetchAll());
}

/** הפרויקט, אם הוא של המשתמש (או שהמשתמש מנהל). */
function projectGet(array $user, int $id): array {
    $st = db()->prepare('SELECT p.*, u.name AS owner_name FROM projects p JOIN users u ON u.id = p.owner_id WHERE p.id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p || ($user['role'] !== 'admin' && (int) $p['owner_id'] !== (int) $user['id'])) throw new AppError('הפרויקט לא נמצא', 404);
    return $p;
}

function projectSave(array $user, array $in): array {
    $id = (int) ($in['id'] ?? 0);
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80);
    if ($name === '') $name = 'פרויקט ללא שם';
    // הלקוח: לפי client_id (רשומה), או לפי שם חופשי — שמקבל רשומה (קיימת או חדשה).
    $clientId = null;
    if (array_key_exists('client_id', $in)) $clientId = $in['client_id'] !== null && (int) $in['client_id'] > 0 ? (int) $in['client_id'] : null;
    elseif (isset($in['client']) && is_string($in['client'])) $clientId = clientByName($in['client'], (int) $user['id']);
    elseif ($id) { $cur = projectGet($user, $id); $clientId = $cur['client_id'] !== null ? (int) $cur['client_id'] : null; }
    if ($clientId) clientGet($clientId);
    $client = $clientId ? clientGet($clientId)['name'] : '';
    $values = is_array($in['values'] ?? null) ? $in['values'] : [];
    $status = in_array($in['status'] ?? '', ['draft', 'quoted', 'approved', 'done'], true) ? $in['status'] : 'draft';
    $typeId = isset($in['product_type_id']) && $in['product_type_id'] !== null ? (int) $in['product_type_id'] : null;
    $key = is_string($in['template_key'] ?? null) && preg_match('/^[a-z][a-z0-9-]{1,30}$/', $in['template_key']) ? $in['template_key'] : 'bookcase';
    $now = nowIso();
    if ($id) {
        projectGet($user, $id);   // בעלות
        db()->prepare('UPDATE projects SET name=?, client=?, client_id=?, values_json=?, status=?, product_type_id=?, template_key=?, updated_at=? WHERE id=?')
            ->execute([$name, $client, $clientId, jsonStr($values), $status, $typeId, $key, $now, $id]);
    } else {
        db()->prepare('INSERT INTO projects (owner_id, product_type_id, template_key, name, client, client_id, values_json, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$user['id'], $typeId, $key, $name, $client, $clientId, jsonStr($values), $status, $now, $now]);
        $id = (int) db()->lastInsertId();
    }
    return projectRow(projectGet($user, $id), true);
}

function projectDelete(array $user, int $id): void {
    projectGet($user, $id);
    db()->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
}

function projectDuplicate(array $user, int $id): array {
    $p = projectGet($user, $id);
    $now = nowIso();
    db()->prepare('INSERT INTO projects (owner_id, product_type_id, template_key, name, client, client_id, values_json, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$user['id'], $p['product_type_id'], $p['template_key'], $p['name'] . ' (עותק)', $p['client'], $p['client_id'], $p['values_json'], 'draft', $now, $now]);
    return projectRow(projectGet($user, (int) db()->lastInsertId()), true);
}

/** יוצר (או מבטל) קישור צפייה ללקוח. */
function projectShare(array $user, int $id, bool $on): array {
    projectGet($user, $id);
    $token = $on ? bin2hex(random_bytes(16)) : null;
    db()->prepare('UPDATE projects SET share_token = ? WHERE id = ?')->execute([$token, $id]);
    return projectRow(projectGet($user, $id), true);
}

/** צפיית לקוח: לפי אסימון בלבד, בלי כניסה, בלי מחירים. */
function projectByToken(string $token): array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) throw new AppError('הקישור אינו תקין', 404);
    $st = db()->prepare('SELECT p.*, u.name AS owner_name FROM projects p JOIN users u ON u.id = p.owner_id WHERE share_token = ?');
    $st->execute([$token]);
    $p = $st->fetch();
    if (!$p) throw new AppError('הקישור אינו תקף יותר', 404);
    return ['id' => (int) $p['id'], 'name' => $p['name'], 'client' => $p['client'], 'owner_name' => $p['owner_name'],
            'template_key' => $p['template_key'], 'values' => jsonArr($p['values_json']), 'updated_at' => $p['updated_at']];
}
