<?php
/** מסך "חברים" — לבעלים בלבד (docs/spec.md, פרק 5). */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/** @param array|null $owner — רק כדי שהקריאה תעבור דרך require_role */
function members_state(?array $owner = null): array
{
    $pdo = db();
    return [
        'members' => $pdo->query('SELECT id, sub IS NOT NULL AS joined, email, name, role, added_at, last_seen FROM members ORDER BY name, email')->fetchAll(),
        'requests' => $pdo->query('SELECT sub, name, email, at FROM access_requests ORDER BY at DESC LIMIT 50')->fetchAll(),
        'roles' => ROLES,
    ];
}

function member_role(mixed $r): string
{
    if (!is_string($r) || !isset(ROLES[$r])) throw new ApiError('תפקיד לא תקין');
    return $r;
}

/** הוספה: ממי שביקש גישה (sub), או לפי מייל — מתקשר בכניסה הראשונה. */
function member_add(array $owner, array $b): array
{
    $role = member_role($b['role'] ?? null);
    $pdo = db();
    if (is_string($b['sub'] ?? null) && $b['sub'] !== '') {
        $st = $pdo->prepare('SELECT * FROM access_requests WHERE sub = ?');
        $st->execute([$b['sub']]);
        $r = $st->fetch();
        if (!$r) throw new ApiError('הבקשה לא נמצאה', 404);
        if (member_by_sub($r['sub'])) throw new ApiError('כבר חבר');
        $pdo->prepare('INSERT INTO members (sub, email, name, role, added_by, added_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$r['sub'], $r['email'], $r['name'], $role, $owner['sub'], now_iso()]);
        $pdo->prepare('DELETE FROM access_requests WHERE sub = ?')->execute([$r['sub']]);
        audit_as($owner, 'member_add', ($r['name'] ?: $r['email'] ?: $r['sub']) . ' · ' . ROLES[$role]);
    } else {
        $email = mb_strtolower(trim((string)($b['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError('מייל לא תקין');
        $st = $pdo->prepare('SELECT 1 FROM members WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetchColumn()) throw new ApiError('המייל כבר ברשימה');
        $pdo->prepare('INSERT INTO members (email, role, added_by, added_at) VALUES (?, ?, ?, ?)')
            ->execute([$email, $role, $owner['sub'], now_iso()]);
        audit_as($owner, 'member_add', $email . ' · ' . ROLES[$role]);
    }
    return members_state();
}

function member_get(int $id): array
{
    $st = db()->prepare('SELECT * FROM members WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: throw new ApiError('החבר לא נמצא', 404);
}

function member_set(array $owner, array $b): array
{
    $m = member_get((int)($b['id'] ?? 0));
    $role = member_role($b['role'] ?? null);
    db()->prepare('UPDATE members SET role = ? WHERE id = ?')->execute([$role, $m['id']]);
    audit_as($owner, 'member_role', ($m['name'] ?: $m['email']) . ': ' . ROLES[$m['role']] . ' ← ' . ROLES[$role]);
    return members_state();
}

function member_remove(array $owner, array $b): array
{
    $m = member_get((int)($b['id'] ?? 0));
    db()->prepare('DELETE FROM members WHERE id = ?')->execute([$m['id']]);
    audit_as($owner, 'member_remove', $m['name'] ?: (string)$m['email']);
    return members_state();
}

function request_dismiss(array $owner, array $b): array
{
    db()->prepare('DELETE FROM access_requests WHERE sub = ?')->execute([(string)($b['sub'] ?? '')]);
    return members_state();
}

function audit_list(array $owner, array $b): array
{
    $before = (int)($b['before'] ?? 0);
    $st = db()->prepare('SELECT id, at, name, action, detail FROM audit WHERE id < :b ORDER BY id DESC LIMIT 100');
    $st->bindValue(':b', $before > 0 ? $before : PHP_INT_MAX, PDO::PARAM_INT);
    $st->execute();
    return ['audit' => $st->fetchAll()];
}
