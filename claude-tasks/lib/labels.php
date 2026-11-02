<?php
/**
 * הצמדה ותוויות — docs/spec.md §5.11, שלב 12c.
 *
 * התווית קובעת (הכרעת בעל המערכת): לכל תווית יש **נראות** — shared (כל מי שרואה את
 * הערוץ רואה את הסימון) או private (רק מי שסימן) — ו**היקף**: כל הלוח (project_id NULL)
 * או פרויקט וענף שלו. ההצמדה — אותם שני סוגים: "הצמד לכולם" ו"הצמד לי".
 *
 * הרשאות (§5.11.2): סימון / הצמדה משותפים — מי שרשאי ל-channel-send; פרטיים — כל צופה,
 * לעצמו; יצירת תווית משותפת או של כל הלוח — בעל הלוח. 8 צבעים קבועים (CHANNEL_COLORS).
 */
declare(strict_types=1);

const LABEL_VISIBILITY = ['shared', 'private'];

function migrateLabels(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS labels (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id  INTEGER REFERENCES projects(id) ON DELETE CASCADE,
            name        TEXT NOT NULL,
            color       TEXT NOT NULL,
            visibility  TEXT NOT NULL DEFAULT 'shared',
            created_by  INTEGER NOT NULL,
            created_at  INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS message_labels (
            message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
            label_id   INTEGER NOT NULL REFERENCES labels(id) ON DELETE CASCADE,
            user_id    INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            PRIMARY KEY (message_id, label_id)
        );
        CREATE TABLE IF NOT EXISTS message_pins (
            message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
            visibility TEXT NOT NULL,
            user_id    INTEGER NOT NULL DEFAULT 0,   -- 0 = משותפת; אחרת — של מי ההצמדה הפרטית
            pinned_by  INTEGER NOT NULL,
            pinned_at  INTEGER NOT NULL,
            PRIMARY KEY (message_id, visibility, user_id)
        );
        CREATE INDEX IF NOT EXISTS idx_message_labels_label ON message_labels(label_id);
    ");
}

/** תווית שהמשתמש רואה: בהיקף (כל הלוח, או הפרויקט / אחד מאבותיו), ומשותפת או שלו. */
function labelVisibleTo(array $label, array $user, int $projectId): bool {
    if ($label['visibility'] === 'private' && (int) $label['created_by'] !== (int) $user['id']) return false;
    if ($label['project_id'] === null) return true;
    return in_array((int) $label['project_id'], array_map(fn ($p) => (int) $p[0], projectChain($projectId)), true);
}

function publicLabel(array $l): array {
    return ['id' => (int) $l['id'], 'name' => $l['name'], 'color' => $l['color'], 'visibility' => $l['visibility'],
            'project_id' => $l['project_id'] === null ? null : (int) $l['project_id'], 'created_by' => (int) $l['created_by']];
}

/** התוויות שאפשר להשתמש בהן בערוץ — של כל הלוח ושל הפרויקט ואבותיו; פרטיות — רק שלי. */
function labelsForChannel(array $user, int $channelId): array {
    $c = channelForUser($user, $channelId);
    $rows = db()->query('SELECT l.*, (SELECT COUNT(*) FROM message_labels ml WHERE ml.label_id = l.id) uses
                           FROM labels l ORDER BY l.project_id IS NOT NULL, l.name')->fetchAll();
    $out = [];
    foreach ($rows as $l) {
        if (!labelVisibleTo($l, $user, (int) $c['project_id'])) continue;
        $out[] = publicLabel($l) + ['uses' => (int) $l['uses'], 'can_edit' => canEditLabel($user, $l)];
    }
    return $out;
}

function canEditLabel(array $user, array $label): bool {
    if ($label['visibility'] === 'private') return (int) $label['created_by'] === (int) $user['id'];
    return authorRole($user) === 'owner';
}

/**
 * יצירה / עריכה. משותפת או של כל הלוח — בעל הלוח; פרטית בפרויקט — כל מי שרואה אותו.
 * בעריכה אפשר לשנות שם וצבע; הסוג (נראות והיקף) נקבע ביצירה.
 */
function labelSave(array $user, array $in): array {
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 30);
    if ($name === '') throw new InvalidArgumentException('לתווית חייב להיות שם');
    $color = (string) ($in['color'] ?? '');
    if (!isset(CHANNEL_COLORS[$color])) throw new InvalidArgumentException('צבע: אחד מ-8 הקבועים');
    if (!empty($in['id'])) {
        $l = getLabel((int) $in['id']);
        if (!canEditLabel($user, $l)) throw new AppError('אין לך הרשאה לערוך את התווית הזו', 403);
        db()->prepare('UPDATE labels SET name = ?, color = ? WHERE id = ?')->execute([$name, $color, $l['id']]);
        return publicLabel(getLabel((int) $l['id']));
    }
    $visibility = (string) ($in['visibility'] ?? 'shared');
    if (!in_array($visibility, LABEL_VISIBILITY, true)) throw new InvalidArgumentException('נראות: shared או private');
    $projectId = isset($in['project_id']) && $in['project_id'] !== null && $in['project_id'] !== '' ? (int) $in['project_id'] : null;
    if ($visibility === 'shared' || $projectId === null) {
        if (authorRole($user) !== 'owner') throw new AppError('תווית משותפת או של כל הלוח — בעל הלוח בלבד', 403);
    }
    if ($projectId !== null) requireProject($projectId, $user, 'read');
    db()->prepare('INSERT INTO labels (project_id, name, color, visibility, created_by, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$projectId, $name, $color, $visibility, (int) $user['id'], nowTs()]);
    return publicLabel(getLabel((int) db()->lastInsertId()));
}

function getLabel(int $id): array {
    $st = db()->prepare('SELECT * FROM labels WHERE id = ?');
    $st->execute([$id]);
    $l = $st->fetch();
    if (!$l) throw new AppError('התווית לא נמצאה', 404);
    return $l;
}

/** מחיקה — מוחקת גם את הסימונים. מחזיר כמה הודעות היו מסומנות. */
function labelDelete(array $user, int $id): int {
    $l = getLabel($id);
    if (!canEditLabel($user, $l)) throw new AppError('אין לך הרשאה למחוק את התווית הזו', 403);
    $n = db()->prepare('SELECT COUNT(*) FROM message_labels WHERE label_id = ?');
    $n->execute([$id]);
    $count = (int) $n->fetchColumn();
    db()->prepare('DELETE FROM message_labels WHERE label_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM labels WHERE id = ?')->execute([$id]);
    return $count;
}

/** ההודעה והערוץ שלה — ובדיקה שהמשתמש רואה אותם. */
function messageForUser(array $user, int $messageId): array {
    $st = db()->prepare('SELECT * FROM messages WHERE id = ?');
    $st->execute([$messageId]);
    $m = $st->fetch();
    if (!$m) throw new AppError('ההודעה לא נמצאה', 404);
    return [$m, channelForUser($user, (int) $m['channel_id'])];
}

/** סימון בתווית / הסרה. משותפת — מי שרשאי לכתוב לצ'אט; פרטית — רק מי שיצר אותה. */
function messageLabel(array $user, int $messageId, int $labelId, bool $on): void {
    [$m, $c] = messageForUser($user, $messageId);
    $l = getLabel($labelId);
    if (!labelVisibleTo($l, $user, (int) $c['project_id'])) throw new AppError('התווית לא זמינה בצ\'אט הזה', 403);
    if ($l['visibility'] === 'shared' && !canSendToChannel($user, $c)) throw new AppError('תווית משותפת — מי שרשאי לכתוב לצ\'אט', 403);
    if ($on) {
        db()->prepare('INSERT OR IGNORE INTO message_labels (message_id, label_id, user_id, created_at) VALUES (?,?,?,?)')
            ->execute([$messageId, $labelId, (int) $user['id'], nowTs()]);
    } else {
        db()->prepare('DELETE FROM message_labels WHERE message_id = ? AND label_id = ?')->execute([$messageId, $labelId]);
    }
}

/** 📌 לכולם (מי שרשאי לכתוב) / לי (כל צופה, לעצמו). */
function messagePin(array $user, int $messageId, string $visibility, bool $pinned): void {
    [$m, $c] = messageForUser($user, $messageId);
    if (!in_array($visibility, LABEL_VISIBILITY, true)) throw new InvalidArgumentException('נראות: shared או private');
    if ($visibility === 'shared' && !canSendToChannel($user, $c)) throw new AppError('הצמדה לכולם — מי שרשאי לכתוב לצ\'אט', 403);
    $owner = $visibility === 'shared' ? 0 : (int) $user['id'];
    if ($pinned) {
        db()->prepare('INSERT OR IGNORE INTO message_pins (message_id, visibility, user_id, pinned_by, pinned_at) VALUES (?,?,?,?,?)')
            ->execute([$messageId, $visibility, $owner, (int) $user['id'], nowTs()]);
    } else {
        db()->prepare('DELETE FROM message_pins WHERE message_id = ? AND visibility = ? AND user_id = ?')->execute([$messageId, $visibility, $owner]);
    }
}

/**
 * מוסיף לכל הודעה: pinned {shared, mine} ו-labels[] — פרטיים רק לצופה עצמו.
 * ‏$messages — כפי שיוצאות מ-messageRows.
 */
function decorateMessages(array $user, array $messages, int $projectId): array {
    if (!$messages) return $messages;
    $ids = array_column($messages, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $pins = db()->prepare("SELECT message_id, visibility, user_id FROM message_pins WHERE message_id IN ($in)");
    $pins->execute($ids);
    $pinned = [];
    foreach ($pins->fetchAll() as $p) {
        if ($p['visibility'] === 'shared') $pinned[(int) $p['message_id']]['shared'] = true;
        elseif ((int) $p['user_id'] === (int) $user['id']) $pinned[(int) $p['message_id']]['mine'] = true;
    }
    $ml = db()->prepare("SELECT ml.message_id, l.* FROM message_labels ml JOIN labels l ON l.id = ml.label_id WHERE ml.message_id IN ($in) ORDER BY l.name");
    $ml->execute($ids);
    $labels = [];
    foreach ($ml->fetchAll() as $r) {
        if (!labelVisibleTo($r, $user, $projectId)) continue;
        $labels[(int) $r['message_id']][] = publicLabel($r);
    }
    foreach ($messages as &$m) {
        $m['pinned'] = ['shared' => !empty($pinned[$m['id']]['shared']), 'mine' => !empty($pinned[$m['id']]['mine'])];
        $m['labels'] = $labels[$m['id']] ?? [];
    }
    unset($m);
    return $messages;
}

/** 📌 מוצמדות בערוץ — לפס בראש השיחה ולרשימה ב-⚙, גם הודעות שלא נטענו. */
function channelPins(array $user, int $channelId): array {
    channelForUser($user, $channelId);
    $st = db()->prepare("SELECT p.message_id, p.visibility, MAX(p.pinned_at) pinned_at FROM message_pins p
                           JOIN messages m ON m.id = p.message_id
                          WHERE m.channel_id = ? AND (p.visibility = 'shared' OR p.user_id = ?)
                          GROUP BY p.message_id, p.visibility ORDER BY pinned_at DESC, p.message_id DESC LIMIT 100");
    $st->execute([$channelId, (int) $user['id']]);
    $pins = $st->fetchAll();
    if (!$pins) return [];
    $rows = messageRows('m.id IN (' . implode(',', array_fill(0, count($pins), '?')) . ')', array_column($pins, 'message_id'));
    $byId = array_column($rows, null, 'id');
    return array_values(array_filter(array_map(fn ($p) => isset($byId[(int) $p['message_id']]) ? [
        'message_id' => (int) $p['message_id'], 'visibility' => $p['visibility'], 'pinned_at' => (int) $p['pinned_at'],
        'body' => mb_substr((string) $byId[(int) $p['message_id']]['body'], 0, 300),
        'author_role' => $byId[(int) $p['message_id']]['author_role'], 'created_at' => $byId[(int) $p['message_id']]['created_at'],
    ] : null, $pins)));
}
