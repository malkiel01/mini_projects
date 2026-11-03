<?php
/**
 * ⚙ הגדרות הצ'אט — docs/spec.md §5.11, שלב 12a.
 *
 * מחליף את 🔌 בראש מסך השיחה: גיליון כמו ⚙ של הפרויקט — מידע על הצ'אט, שם/תפקיד/צבע,
 * השתקה, מי עונה (💬/☁️), שאלות בחירה, אורך וצ'אט המשך, ובתחתית — אזור אדום: 🔌 ניתוק
 * ו-🗄 ארכיון (בעל הלוח בלבד).
 *
 * ניתוק — האסימון נפסל והצ'אט מפסיק (נשאר ברשימה, "מנותק"). ארכיון — יורד מהרשימה
 * לקבוצה "בארכיון" (צ'אט פעיל מתנתק קודם). אפשר להחזיר מהארכיון.
 * השתקה — לכל משתמש: הצ'אט לא נספר ב"ממתין לך" ובמונים, ומסומן 🔕.
 */
declare(strict_types=1);

/** 8 צבעים קבועים לריבוע — גוון בלבד; הבהירות והרוויה ב-CSS, נגישים בבהיר ובכהה. */
const CHANNEL_COLORS = ['blue' => 215, 'teal' => 180, 'green' => 140, 'olive' => 85,
                        'amber' => 45, 'orange' => 22, 'red' => 355, 'violet' => 270];

function migrateChatSettings(PDO $pdo): void {
    addColumn($pdo, 'channels', 'color', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'channels', 'hidden', 'INTEGER NOT NULL DEFAULT 0');
    // הסמל בריבוע — '' = לפי התפקיד; צ'אט חדש מקבל את הסמל של התפקיד בתבנית
    addColumn($pdo, 'channels', 'icon', "TEXT NOT NULL DEFAULT ''");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS channel_mutes (
            user_id    INTEGER NOT NULL,
            channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
            created_at INTEGER NOT NULL,
            PRIMARY KEY (user_id, channel_id)
        );
    ");
}

function channelForUser(array $user, int $channelId): array {
    $channel = getChannel($channelId);
    if (!$channel) throw new AppError('הערוץ לא נמצא', 404);
    requireProject((int) $channel['project_id'], $user, 'read');
    return $channel;
}

function mutedChannelIds(int $userId): array {
    $st = db()->prepare('SELECT channel_id FROM channel_mutes WHERE user_id = ?');
    $st->execute([$userId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** ℹ️ על הצ'אט — כל מה שבגיליון, במקום אחד. */
function channelInfo(array $user, int $channelId): array {
    $c = channelForUser($user, $channelId);
    $st = db()->prepare('SELECT name FROM channels WHERE id = ?');
    $replacedBy = null;
    if (!empty($c['replaced_by'])) { $st->execute([$c['replaced_by']]); $replacedBy = $st->fetchColumn() ?: null; }
    $owner = authorRole($user) === 'owner';
    return publicChannel($c) + [
        'project_path'     => projectPathName((int) $c['project_id']),
        'route_own'        => (string) $c['route'],
        'route_effective'  => channelRoute($c),
        'questions'        => channelQuestionsMode($c),
        'permissions'      => channelPermissionsMode($c),
        'muted'            => in_array((int) $c['id'], mutedChannelIds((int) $user['id']), true),
        'listening'        => $c['status'] === 'active' && (int) $c['listening_until'] > nowTs(),
        'replaced_by_name' => $replacedBy,
        'can_edit'         => canSendToChannel($user, $c),
        'is_owner'         => $owner,
        'colors'           => array_keys(CHANNEL_COLORS),
    ];
}

/**
 * ✏️ שם, תפקיד וצבע — מי שרשאי לכתוב לצ'אט. מי עונה (route) — בעל הלוח (כמו בפרויקט, §7).
 * שם ריק נדחה; צבע — רק מ-8 הקבועים (או '' — לפי התפקיד).
 */
function channelUpdate(array $user, int $channelId, array $fields): array {
    $c = channelForUser($user, $channelId);
    $set = []; $args = [];
    if (array_key_exists('name', $fields) || array_key_exists('role', $fields) || array_key_exists('color', $fields)
        || array_key_exists('icon', $fields)) {
        if (!canSendToChannel($user, $c)) throw new AppError('אין לך הרשאה לשנות את הצ\'אט הזה', 403);
    }
    if (array_key_exists('name', $fields)) {
        $name = mb_substr(trim((string) $fields['name']), 0, 80);
        if ($name === '') throw new InvalidArgumentException('לצ\'אט חייב להיות שם');
        $set[] = 'name = ?'; $args[] = $name;
    }
    if (array_key_exists('role', $fields)) { $set[] = 'role = ?'; $args[] = mb_substr(trim((string) $fields['role']), 0, 40); }
    if (array_key_exists('color', $fields)) {
        $color = (string) $fields['color'];
        if ($color !== '' && !isset(CHANNEL_COLORS[$color])) throw new InvalidArgumentException('צבע: אחד מ-8 הקבועים');
        $set[] = 'color = ?'; $args[] = $color;
    }
    if (array_key_exists('icon', $fields)) {
        // סמל אחד: עד 8 תווים (אימוג'י עם מתאמים), בלי אותיות ומספרים רגילים
        $icon = trim((string) $fields['icon']);
        if ($icon !== '' && (mb_strlen($icon) > 8 || preg_match('/[\p{L}\p{N}<>"\'&]/u', $icon))) {
            throw new InvalidArgumentException('סמל: אימוג\'י אחד');
        }
        $set[] = 'icon = ?'; $args[] = $icon;
    }
    if (array_key_exists('route', $fields)) {
        if (authorRole($user) !== 'owner') throw new AppError('מי עונה — בעל הלוח בלבד', 403);
        $set[] = 'route = ?'; $args[] = (string) $fields['route'] === '' ? '' : normalizeRoute((string) $fields['route']);
    }
    if ($set) {
        $args[] = $channelId;
        db()->prepare('UPDATE channels SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
        logEvent($user, 'channel.update', "channel:$channelId", true, implode(',', array_keys($fields)));
    }
    return channelInfo($user, $channelId);
}

/** 🔔 השתקה — לכל משתמש, לעצמו. כל מי שרואה את הצ'אט. */
function channelMute(array $user, int $channelId, bool $muted): bool {
    channelForUser($user, $channelId);
    if ($muted) {
        db()->prepare('INSERT OR IGNORE INTO channel_mutes (user_id, channel_id, created_at) VALUES (?,?,?)')
            ->execute([(int) $user['id'], $channelId, nowTs()]);
    } else {
        db()->prepare('DELETE FROM channel_mutes WHERE user_id = ? AND channel_id = ?')->execute([(int) $user['id'], $channelId]);
    }
    return $muted;
}

/** כמה בארכיון בענף — לשורה "🗄 N בארכיון" בתחתית רשימת הצ'אטים. */
function channelsHiddenCount(array $user, int $projectId): int {
    requireProject($projectId, $user, 'read');
    $ids = projectSubtree($projectId);
    $st = db()->prepare('SELECT COUNT(*) FROM channels WHERE hidden = 1 AND project_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    return (int) $st->fetchColumn();
}

/** 🗄 ארכיון — בעל הלוח. צ'אט פעיל מתנתק קודם (האסימון נפסל). ‏hidden=false מחזיר לרשימה. */
function channelSetHidden(array $user, int $channelId, bool $hidden): void {
    $c = channelForUser($user, $channelId);
    if (authorRole($user) !== 'owner') throw new AppError('ארכיון — בעל הלוח בלבד', 403);
    if ($hidden && $c['status'] === 'active') archiveChannel($channelId, $user);
    db()->prepare('UPDATE channels SET hidden = ? WHERE id = ?')->execute([$hidden ? 1 : 0, $channelId]);
    logEvent($user, $hidden ? 'channel.hide' : 'channel.unhide', "channel:$channelId");
}
