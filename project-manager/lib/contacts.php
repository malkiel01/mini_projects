<?php
/**
 * כרטיסים: לקוחות, ספקים ועורכי דין.
 *
 * כל כרטיס שייך למנהל שיצר אותו (owner_id). מנהל רואה ועורך רק את שלו; המפתח
 * רואה ועורך הכול. אין מאגר משותף (הוכרע 2026-10-12).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const PM_KINDS = ['client', 'supplier', 'lawyer'];
const PM_CHANNELS = ['email', 'whatsapp', 'sms'];
const PM_BANK_FIELDS = ['bank', 'branch', 'account', 'holder'];

final class PmError extends RuntimeException {}

function pm_str(array $in, string $key, int $max, bool $required = false, string $label = ''): string
{
    $v = $in[$key] ?? '';
    $v = is_scalar($v) ? trim((string)$v) : '';
    if ($required && $v === '') throw new PmError(($label ?: $key) . ': שדה חובה', 422);
    if (mb_strlen($v) > $max) throw new PmError(($label ?: $key) . ": עד $max תווים", 422);
    return $v;
}

function pm_phone(array $in, string $key, string $label): string
{
    $v = pm_str($in, $key, 30, false, $label);
    if ($v !== '' && !preg_match('/^\+?[0-9][0-9 \-]{5,25}$/', $v)) throw new PmError("$label: מספר לא תקין", 422);
    return $v;
}

/** תנאי ה-SQL שמגביל לכרטיסים שהמשתמש רשאי לראות. */
function pm_owner_clause(array $user, string $alias = 'c'): array
{
    return $user['role'] === 'developer' ? ['1 = 1', []] : ["$alias.owner_id = ?", [$user['id']]];
}

/** כרטיס שהמשתמש רשאי לראות — או חריגה 404. */
function pm_contact_row(array $user, int $id): array
{
    [$where, $args] = pm_owner_clause($user);
    $st = pm_db()->prepare("SELECT c.* FROM contacts c WHERE c.id = ? AND $where");
    $st->execute(array_merge([$id], $args));
    $row = $st->fetch();
    if (!$row) throw new PmError('הכרטיס לא נמצא', 404);
    return $row;
}

function pm_contact_out(array $row, array $tradeIds = [], ?array $settings = null): array
{
    $out = [
        'id'         => (int)$row['id'],
        'owner_id'   => (int)$row['owner_id'],
        'kind'       => $row['kind'],
        'name'       => $row['name'],
        'company'    => $row['company'],
        'tax_id'     => $row['tax_id'],
        'phone'      => $row['phone'],
        'whatsapp'   => $row['whatsapp'],
        'email'      => $row['email'],
        'address'    => $row['address'],
        'bank'       => json_decode($row['bank_json'], true) ?: (object)[],
        'rating'     => (int)$row['rating'],
        'notes'      => $row['notes'],
        'archived'   => (bool)$row['archived'],
        'trades'     => array_map('intval', $tradeIds),
        'updated_at' => $row['updated_at'],
    ];
    if (isset($row['owner_name'])) $out['owner_name'] = $row['owner_name'];
    if (isset($row['lawyer_name'])) $out['lawyer_name'] = $row['lawyer_name'];
    if ($settings !== null) $out['settings'] = $settings;
    return $out;
}

function pm_contacts_list(array $user, string $kind, string $q, bool $archived): array
{
    if (!in_array($kind, PM_KINDS, true)) throw new PmError('סוג כרטיס לא מוכר', 422);
    [$where, $args] = pm_owner_clause($user);
    $sql = "SELECT c.*, m.display_name AS owner_name, l.name AS lawyer_name
              FROM contacts c
              LEFT JOIN managers m ON m.platform_user_id = c.owner_id
              LEFT JOIN client_settings cs ON cs.contact_id = c.id
              LEFT JOIN contacts l ON l.id = cs.lawyer_id
             WHERE $where AND c.kind = ? AND c.archived = ?";
    $args = array_merge($args, [$kind, $archived ? 1 : 0]);
    if ($q !== '') {
        $sql .= ' AND (c.name LIKE ? OR c.company LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)';
        $like = '%' . strtr($q, ['%' => '', '_' => '']) . '%';
        array_push($args, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY c.name COLLATE NOCASE';
    $st = pm_db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();

    $trades = [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $t = pm_db()->prepare("SELECT contact_id, trade_id FROM contact_trades WHERE contact_id IN ($in)");
        $t->execute($ids);
        foreach ($t->fetchAll() as $r) $trades[$r['contact_id']][] = $r['trade_id'];
    }
    return array_map(fn($r) => pm_contact_out($r, $trades[$r['id']] ?? []), $rows);
}

function pm_contact_get(array $user, int $id): array
{
    $row = pm_contact_row($user, $id);
    $t = pm_db()->prepare('SELECT trade_id FROM contact_trades WHERE contact_id = ?');
    $t->execute([$id]);
    $settings = null;
    if ($row['kind'] === 'client') $settings = pm_client_settings($id);
    return pm_contact_out($row, $t->fetchAll(PDO::FETCH_COLUMN), $settings);
}

function pm_client_settings(int $id): array
{
    $s = pm_db()->prepare('SELECT * FROM client_settings WHERE contact_id = ?');
    $s->execute([$id]);
    $r = $s->fetch() ?: [];
    return [
        'lawyer_id'       => isset($r['lawyer_id']) ? (int)$r['lawyer_id'] : null,
        'approval_method' => $r['approval_method'] ?? 'click',
        'channels'        => array_values(array_filter(explode(',', $r['channels'] ?? 'email,whatsapp'))),
        'default_payer'   => $r['default_payer'] ?? 'client',
    ];
}

/** יצירה או עדכון. מחזיר את הכרטיס המלא. */
function pm_contact_save(array $user, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    if ($id) {
        $existing = pm_contact_row($user, $id);
        $kind = $existing['kind'];         // סוג כרטיס לא משתנה אחרי יצירה
        $ownerId = (int)$existing['owner_id'];
    } else {
        $kind = (string)($in['kind'] ?? '');
        if (!in_array($kind, PM_KINDS, true)) throw new PmError('סוג כרטיס לא מוכר', 422);
        $ownerId = $user['id'];
    }

    $email = pm_str($in, 'email', 120, false, 'אימייל');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new PmError('אימייל לא תקין', 422);
    $bankIn = is_array($in['bank'] ?? null) ? $in['bank'] : [];
    $bank = [];
    foreach (PM_BANK_FIELDS as $f) $bank[$f] = pm_str($bankIn, $f, 60, false, 'פרטי בנק');
    $rating = (int)($in['rating'] ?? 0);

    $fields = [
        'name'      => pm_str($in, 'name', 100, true, 'שם'),
        'company'   => pm_str($in, 'company', 100, false, 'שם העסק'),
        'tax_id'    => pm_str($in, 'tax_id', 20, false, 'ח.פ. / ת"ז'),
        'phone'     => pm_phone($in, 'phone', 'טלפון'),
        'whatsapp'  => pm_phone($in, 'whatsapp', 'וואטסאפ'),
        'email'     => $email,
        'address'   => pm_str($in, 'address', 200, false, 'כתובת'),
        'bank_json' => json_encode(array_filter($bank, 'strlen'), JSON_UNESCAPED_UNICODE),
        'rating'    => max(0, min(5, $rating)),
        'notes'     => pm_str($in, 'notes', 4000, false, 'הערות'),
    ];

    $tradeIds = [];
    if ($kind === 'supplier') {
        $raw = is_array($in['trades'] ?? null) ? $in['trades'] : [];
        $tradeIds = array_values(array_unique(array_map('intval', $raw)));
        if ($tradeIds) {
            $inQ = implode(',', array_fill(0, count($tradeIds), '?'));
            $c = pm_db()->prepare("SELECT COUNT(*) FROM trades WHERE id IN ($inQ)");
            $c->execute($tradeIds);
            if ((int)$c->fetchColumn() !== count($tradeIds)) throw new PmError('תחום לא מוכר', 422);
        }
    }

    $settings = null;
    if ($kind === 'client') $settings = pm_validate_client_settings($ownerId, $in['settings'] ?? []);

    $db = pm_db();
    $db->beginTransaction();
    try {
        $now = pm_now();
        if ($id) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            $db->prepare("UPDATE contacts SET $set, updated_at = ? WHERE id = ?")
               ->execute(array_merge(array_values($fields), [$now, $id]));
        } else {
            $cols = implode(', ', array_keys($fields));
            $qs = implode(', ', array_fill(0, count($fields), '?'));
            $db->prepare("INSERT INTO contacts (owner_id, kind, $cols, created_at, updated_at) VALUES (?, ?, $qs, ?, ?)")
               ->execute(array_merge([$ownerId, $kind], array_values($fields), [$now, $now]));
            $id = (int)$db->lastInsertId();
        }
        if ($kind === 'supplier') {
            $db->prepare('DELETE FROM contact_trades WHERE contact_id = ?')->execute([$id]);
            $ins = $db->prepare('INSERT INTO contact_trades (contact_id, trade_id) VALUES (?, ?)');
            foreach ($tradeIds as $t) $ins->execute([$id, $t]);
        }
        if ($settings !== null) {
            $db->prepare(
                'INSERT INTO client_settings (contact_id, lawyer_id, approval_method, channels, default_payer)
                 VALUES (?,?,?,?,?)
                 ON CONFLICT(contact_id) DO UPDATE SET lawyer_id = excluded.lawyer_id,
                    approval_method = excluded.approval_method, channels = excluded.channels,
                    default_payer = excluded.default_payer'
            )->execute([$id, $settings['lawyer_id'], $settings['approval_method'],
                        implode(',', $settings['channels']), $settings['default_payer']]);
        }
        pm_audit("user:{$user['id']}", isset($existing) ? 'contact.update' : 'contact.create', 'contact', $id,
                 ['kind' => $kind, 'name' => $fields['name']]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return pm_contact_get($user, $id);
}

function pm_validate_client_settings(int $ownerId, $raw): array
{
    $raw = is_array($raw) ? $raw : [];
    $lawyerId = (int)($raw['lawyer_id'] ?? 0) ?: null;
    if ($lawyerId) {
        // עו"ד של אותו מנהל בלבד — אי אפשר לקשר לכרטיס של מנהל אחר.
        $st = pm_db()->prepare("SELECT 1 FROM contacts WHERE id = ? AND kind = 'lawyer' AND owner_id = ?");
        $st->execute([$lawyerId, $ownerId]);
        if (!$st->fetchColumn()) throw new PmError('עורך הדין לא נמצא', 422);
    }
    $method = (string)($raw['approval_method'] ?? 'click');
    if (!in_array($method, ['click', 'signature'], true)) throw new PmError('אופן אישור לא מוכר', 422);
    $payer = (string)($raw['default_payer'] ?? 'client');
    if (!in_array($payer, ['client', 'lawyer'], true)) throw new PmError('משלם לא מוכר', 422);
    if ($payer === 'lawyer' && !$lawyerId) throw new PmError('כדי שהעו"ד ישלם — צריך לבחור עו"ד', 422);
    $channels = is_array($raw['channels'] ?? null) ? $raw['channels'] : [];
    $channels = array_values(array_intersect(PM_CHANNELS, array_map('strval', $channels)));
    return ['lawyer_id' => $lawyerId, 'approval_method' => $method, 'channels' => $channels, 'default_payer' => $payer];
}

function pm_contact_archive(array $user, int $id, bool $archived): array
{
    pm_contact_row($user, $id);
    pm_db()->prepare('UPDATE contacts SET archived = ?, updated_at = ? WHERE id = ?')
        ->execute([$archived ? 1 : 0, pm_now(), $id]);
    pm_audit("user:{$user['id']}", $archived ? 'contact.archive' : 'contact.restore', 'contact', $id);
    return pm_contact_get($user, $id);
}

// ---- תחומים (המפתח עורך) ----

function pm_trades(): array
{
    $rows = pm_db()->query(
        'SELECT t.id, t.name, t.sort, COUNT(ct.contact_id) AS used
           FROM trades t LEFT JOIN contact_trades ct ON ct.trade_id = t.id
          GROUP BY t.id ORDER BY t.sort, t.name'
    )->fetchAll();
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'sort' => (int)$r['sort'], 'used' => (int)$r['used']], $rows);
}

function pm_trade_save(array $user, array $in): array
{
    $name = pm_str($in, 'name', 40, true, 'שם התחום');
    $id = (int)($in['id'] ?? 0);
    $db = pm_db();
    $dup = $db->prepare('SELECT id FROM trades WHERE name = ? AND id != ?');
    $dup->execute([$name, $id]);
    if ($dup->fetchColumn()) throw new PmError('תחום בשם הזה כבר קיים', 409);
    if ($id) {
        $db->prepare('UPDATE trades SET name = ? WHERE id = ?')->execute([$name, $id]);
    } else {
        $sort = (int)$db->query('SELECT COALESCE(MAX(sort), 0) + 10 FROM trades')->fetchColumn();
        $db->prepare('INSERT INTO trades (name, sort) VALUES (?, ?)')->execute([$name, $sort]);
        $id = (int)$db->lastInsertId();
    }
    pm_audit("user:{$user['id']}", 'trade.save', 'trade', $id, ['name' => $name]);
    return pm_trades();
}

function pm_trade_delete(array $user, int $id): array
{
    $u = pm_db()->prepare('SELECT COUNT(*) FROM contact_trades WHERE trade_id = ?');
    $u->execute([$id]);
    if ((int)$u->fetchColumn() > 0) throw new PmError('התחום משויך לספקים — הסר אותו מהם קודם', 409);
    pm_db()->prepare('DELETE FROM trades WHERE id = ?')->execute([$id]);
    pm_audit("user:{$user['id']}", 'trade.delete', 'trade', $id);
    return pm_trades();
}
