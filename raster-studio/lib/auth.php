<?php
// מי המשתמש — לפי platform/docs/sso.md, סעיף 0 (אפליקציה מחוברת).
// השער כותב לכל בקשת PHP את $_SERVER['PLATFORM_USER'] (JSON: sub, name, email), ורק למי שיש לו הרשאה.
// אין לנו סיסמה ולא עוגייה: כל בקשה מזוהה מחדש מהשער, וכך יציאה מהפלטפורמה היא גם יציאה מכאן.

require_once __DIR__ . '/db.php';

/** הזהות מהשער, או null. */
function rs_platform_user(): ?array
{
    $raw = $_SERVER['PLATFORM_USER'] ?? '';
    if (!is_string($raw) || $raw === '') return null;
    $u = json_decode($raw, true);
    if (!is_array($u) || !isset($u['sub']) || (string)$u['sub'] === '') return null;
    return [
        'sub' => (string)$u['sub'],
        'name' => trim((string)($u['name'] ?? '')),
        'email' => strtolower(trim((string)($u['email'] ?? ''))),
    ];
}

/**
 * החשבון שלנו של המשתמש הנוכחי: מקושר ל-sub, או חשבון לא-מקושר באותו מייל (איחוד), או חדש.
 * ההרשאה בפלטפורמה היא האישור — חשבון חדש נפתח מיד, פעיל. החשבון הראשון — מנהל.
 */
function rs_account(): ?array
{
    $pu = rs_platform_user();
    if (!$pu) return null;
    $db = rs_db();
    $now = rs_now();

    $q = $db->prepare('SELECT * FROM accounts WHERE sub = ?');
    $q->execute([$pu['sub']]);
    $acc = $q->fetch();

    if (!$acc && $pu['email'] !== '') {
        $q = $db->prepare('SELECT * FROM accounts WHERE sub IS NULL AND email = ? ORDER BY id LIMIT 1');
        $q->execute([$pu['email']]);
        $acc = $q->fetch();
        if ($acc) {
            $db->prepare('UPDATE accounts SET sub = ? WHERE id = ?')->execute([$pu['sub'], $acc['id']]);
            $acc['sub'] = $pu['sub'];
        }
    }

    if (!$acc) {
        $db->beginTransaction();
        $first = (int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn() === 0;
        $db->prepare('INSERT INTO accounts (sub, email, name, role, created_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$pu['sub'], $pu['email'], $pu['name'], $first ? 'admin' : 'user', $now, $now]);
        $id = (int)$db->lastInsertId();
        $db->commit();
        $q = $db->prepare('SELECT * FROM accounts WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch();
    }

    // שם ומייל מתעדכנים מהפלטפורמה; זמן אחרון — לכל היותר פעם בדקה.
    if ($acc['name'] !== $pu['name'] || $acc['email'] !== $pu['email'] || ($acc['last_seen_at'] ?? '') < gmdate('Y-m-d\TH:i:s\Z', time() - 60)) {
        $db->prepare('UPDATE accounts SET name = ?, email = ?, last_seen_at = ? WHERE id = ?')
            ->execute([$pu['name'], $pu['email'], $now, $acc['id']]);
        $acc['name'] = $pu['name'];
        $acc['email'] = $pu['email'];
        $acc['last_seen_at'] = $now;
    }
    return $acc;
}
