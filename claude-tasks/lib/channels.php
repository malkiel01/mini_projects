<?php
/**
 * הגשר החי — ערוצים והודעות (docs/spec.md, 5.2–5.5).
 *
 * ערוץ = שיחה חיה עם צ'אט אחד, בתוך פרויקט. מטלה היא יחידת עבודה; ערוץ הוא
 * השיחה. הודעה יכולה לשאת task_id, ואז היא מקשרת ביניהם.
 *
 *   in  — לוח ← צ'אט. ממתינה למסירה עד ש-channel-ack מסמן אותה.
 *   out — צ'אט ← לוח. שיקוף של מה שנאמר בצ'אט; לעולם לא הוראה.
 *
 * שלושה סוגי אסימון נוגעים כאן:
 *   הרשמה — אסימון הפרויקט (agent_token) או הכללי. רק רושם ערוצים בענף שלו.
 *   ערוץ  — "ch_…", נוצר ב-channel-register ומוחזר פעם אחת. נשמר כגיבוב בלבד.
 *            רישום חוזר עם אותו session_id נותן אסימון חדש ופוסל את הישן.
 *   אדם   — עוגייה, דרך בדיקת ה-Origin של api.php.
 *
 * ההמתנה (channel-wait) לא שואלת את המסד בכל שנייה: היא קוראת קובץ "פעמון"
 * (data/bell/<id>) שכל הודעה נכנסת כותבת אליו. כל בקשה ממתינה מחזיקה תהליך
 * PHP, ובאחסון משותף התהליכים מוגבלים — ולכן גם התקרה long_poll_max.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/projects.php';
require_once __DIR__ . '/config.php';

if (!defined('BELL_DIR')) define('BELL_DIR', __DIR__ . '/../data/bell');

const MESSAGE_KINDS   = ['text', 'question', 'answer', 'approval', 'decision', 'status'];
/** מי כותב בכיוון out (שיקוף מהצ'אט). owner = "בעל המערכת כתב בצ'אט" — מידע, לא הוראה. */
const OUT_ROLES       = ['owner', 'chat', 'system'];
const MAX_MESSAGE     = 20000;

/* ── אסימון ופעמון ─────────────────────────────────────────────── */

function channelTokenHash(string $token): string { return hash('sha256', $token); }

/** הערוץ של אסימון "ch_…", או null. ערוץ בארכיון — אסימונו פסול. */
function channelByToken(string $token): ?array {
    if (!str_starts_with($token, 'ch_') || strlen($token) < 20) return null;
    $st = db()->prepare("SELECT * FROM channels WHERE token_hash = ? AND status = 'active'");
    $st->execute([channelTokenHash($token)]);
    return $st->fetch() ?: null;
}

function bellFile(int $channelId): string { return BELL_DIR . '/' . $channelId; }

/** מצלצל: כותב ערך חדש לקובץ. ההמתנה משווה תוכן, לא mtime — שנייה אחת זהה לא תבלע צלצול. */
function ringBell(int $channelId): void {
    ensureDataDir(BELL_DIR);
    @file_put_contents(bellFile($channelId), uniqid('', true), LOCK_EX);
}

function bellValue(int $channelId): string {
    clearstatcache(true, bellFile($channelId));
    return (string) @file_get_contents(bellFile($channelId));
}

/* ── קריאה ─────────────────────────────────────────────────────── */

function getChannel(int $id): ?array {
    $st = db()->prepare('SELECT * FROM channels WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** ערוץ כפי שהוא יוצא החוצה — בלי הגיבוב. */
function publicChannel(array $c): array {
    unset($c['token_hash']);
    foreach (['id', 'project_id', 'last_seen_at', 'listening_until', 'created_at'] as $k) $c[$k] = (int) $c[$k];
    return $c;
}

function messageRows(string $where, array $args, int $limit = 200): array {
    $st = db()->prepare("SELECT m.*, u.display_name author_name FROM messages m
                           LEFT JOIN users u ON u.id = m.author_user_id
                          WHERE $where ORDER BY m.id LIMIT " . max(1, min(500, $limit)));
    $st->execute($args);
    return array_map(function ($m) {
        foreach (['id', 'channel_id'] as $k) $m[$k] = (int) $m[$k];
        foreach (['task_id', 'reply_to', 'author_user_id', 'delivered_at'] as $k) {
            $m[$k] = $m[$k] === null ? null : (int) $m[$k];
        }
        $m['created_at'] = (int) $m['created_at'];
        return $m;
    }, $st->fetchAll());
}

/* ── הרשמה ─────────────────────────────────────────────────────── */

/**
 * צ'אט נרשם. ‏$scope — תחום אסימון ההרשמה (workerScope): project_id = null
 * הוא האסימון הכללי. אסימון של ערוץ אינו רושם ערוצים.
 * אותו session_id → אותו ערוץ, אסימון חדש; הישן נפסל מיד.
 */
function registerChannel(array $scope, int $projectId, string $name, string $role,
                         string $sessionId, string $sessionUrl): array {
    if (!empty($scope['channel_id'])) throw new AppError('אסימון של ערוץ אינו רושם ערוצים — נדרש אסימון הרשמה', 403);
    $p = db()->prepare('SELECT id FROM projects WHERE id = ? AND archived = 0');
    $p->execute([$projectId]);
    if (!$p->fetch()) throw new AppError('הפרויקט לא נמצא', 404);
    if (($scope['project_id'] ?? null) !== null && !in_array($projectId, projectSubtree((int) $scope['project_id']), true)) {
        throw new AppError('הפרויקט אינו בתחום של אסימון ההרשמה', 403);
    }
    $sessionId = trim($sessionId);
    if (!preg_match('/^[A-Za-z0-9_.:-]{6,120}$/', $sessionId)) throw new InvalidArgumentException('session_id אינו תקין');
    $name = mb_substr(trim($name), 0, 80);
    if ($name === '') throw new InvalidArgumentException('חסר שם לערוץ');
    $role = mb_substr(trim($role), 0, 40);

    $token = 'ch_' . bin2hex(random_bytes(24));
    $hash  = channelTokenHash($token);
    $now   = nowTs();

    $st = db()->prepare('SELECT * FROM channels WHERE session_id = ?');
    $st->execute([$sessionId]);
    $old = $st->fetch();
    if ($old) {
        // צ'אט שעבר פרויקט — רק אם גם הפרויקט הקודם בתחום. אחרת אסימון של ענף אחד היה "גונב" ערוץ של ענף אחר.
        if (($scope['project_id'] ?? null) !== null
            && !in_array((int) $old['project_id'], projectSubtree((int) $scope['project_id']), true)) {
            throw new AppError('הסשן הזה רשום בפרויקט מחוץ לתחום של אסימון ההרשמה', 403);
        }
        db()->prepare("UPDATE channels SET project_id = ?, name = ?, role = ?, session_url = ?, token_hash = ?,
                              status = 'active', last_seen_at = ? WHERE id = ?")
            ->execute([$projectId, $name, $role, $sessionUrl, $hash, $now, $old['id']]);
        $id = (int) $old['id'];
    } else {
        db()->prepare('INSERT INTO channels (project_id, name, role, session_id, session_url, token_hash,
                                             last_seen_at, created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$projectId, $name, $role, $sessionId, $sessionUrl, $hash, $now, $now]);
        $id = (int) db()->lastInsertId();
    }
    logEvent(null, 'channel.register', "channel:$id", true, "$name · project:$projectId" . ($old ? ' · חוזר' : ''));
    return ['channel_id' => $id, 'channel_token' => $token, 'renewed' => (bool) $old];
}

/* ── צ'אט ← לוח ────────────────────────────────────────────────── */

function touchChannel(int $id, ?int $listeningUntil = null): void {
    if ($listeningUntil === null) {
        db()->prepare('UPDATE channels SET last_seen_at = ? WHERE id = ?')->execute([nowTs(), $id]);
    } else {
        db()->prepare('UPDATE channels SET last_seen_at = ?, listening_until = ? WHERE id = ?')
            ->execute([nowTs(), $listeningUntil, $id]);
    }
}

/** בדיקת task_id להודעה: המטלה קיימת ובענף של הערוץ. */
function channelTaskOk(array $channel, ?int $taskId): ?int {
    if ($taskId === null) return null;
    $t = getTask(db(), $taskId);
    if (!$t || $t['project_id'] === null
        || !in_array((int) $t['project_id'], projectSubtree((int) $channel['project_id']), true)) {
        throw new AppError('המטלה אינה בענף של הערוץ', 403);
    }
    return $taskId;
}

function cleanBody(string $body): string {
    $body = trim($body);
    if ($body === '') throw new InvalidArgumentException('הודעה ריקה');
    return mb_strlen($body) > MAX_MESSAGE ? mb_substr($body, 0, MAX_MESSAGE) . '…(truncated)' : $body;
}

/** שיקוף מהצ'אט (out). */
function channelPost(array $channel, string $role, string $body, ?int $replyTo = null,
                     ?int $taskId = null, string $kind = 'text'): int {
    if (!in_array($role, OUT_ROLES, true)) throw new InvalidArgumentException('author_role: owner, chat או system');
    if (!in_array($kind, MESSAGE_KINDS, true)) $kind = 'text';
    $taskId = channelTaskOk($channel, $taskId);
    if ($replyTo !== null) {
        $r = db()->prepare('SELECT id FROM messages WHERE id = ? AND channel_id = ?');
        $r->execute([$replyTo, $channel['id']]);
        if (!$r->fetch()) $replyTo = null;   // תשובה להודעה שלא קיימת בערוץ — נשמרת בלי הקישור
    }
    db()->prepare("INSERT INTO messages (channel_id, task_id, direction, author_role, kind, body, reply_to,
                                         answered_by, created_at) VALUES (?,?, 'out', ?,?,?,?,?,?)")
        ->execute([$channel['id'], $taskId, $role, $kind, cleanBody($body), $replyTo, '', nowTs()]);
    $id = (int) db()->lastInsertId();
    // ההודעה שהצ'אט עונה עליה — נענתה בצ'אט
    if ($replyTo !== null && $role === 'chat') {
        db()->prepare("UPDATE messages SET answered_by = 'chat' WHERE id = ? AND answered_by = ''")->execute([$replyTo]);
    }
    touchChannel((int) $channel['id']);
    return $id;
}

/* ── לוח ← צ'אט ────────────────────────────────────────────────── */

/** האם המשתמש רשאי לכתוב לצ'אט: בעל הלוח, או write בענף שאינו owner_only. */
function canSendToChannel(array $user, array $channel): bool {
    if (authorRole($user) === 'owner') return true;
    $pid = (int) $channel['project_id'];
    if (projectOwnerOnly($pid)) return false;
    $level = projectLevel($pid, $user);
    return $level !== null && levelRank($level) >= levelRank('write');
}

/** הודעה נכנסת (in) — מאדם בלוח, או מהמערכת עצמה (תשובה על מטלה). מצלצלת. */
function channelInsertIn(int $channelId, string $role, ?int $userId, string $body, string $kind = 'text',
                         ?int $taskId = null, ?int $replyTo = null): int {
    db()->prepare("INSERT INTO messages (channel_id, task_id, direction, author_role, author_user_id, kind, body,
                                         reply_to, created_at) VALUES (?,?, 'in', ?,?,?,?,?,?)")
        ->execute([$channelId, $taskId, $role, $userId, in_array($kind, MESSAGE_KINDS, true) ? $kind : 'text',
                   cleanBody($body), $replyTo, nowTs()]);
    $id = (int) db()->lastInsertId();
    ringBell($channelId);
    return $id;
}

function channelSend(array $user, int $channelId, string $body, ?int $taskId = null, ?int $replyTo = null): int {
    $channel = getChannel($channelId);
    if (!$channel) throw new AppError('הערוץ לא נמצא', 404);
    requireProject((int) $channel['project_id'], $user, 'read');
    if (!canSendToChannel($user, $channel)) {
        throw new AppError(projectOwnerOnly((int) $channel['project_id'])
            ? 'בענף הזה רק בעל הלוח כותב לצ\'אטים' : 'נדרשת הרשאת כתיבה בפרויקט', 403);
    }
    if ($channel['status'] !== 'active') throw new AppError('הערוץ מנותק', 409);
    $taskId = channelTaskOk($channel, $taskId);
    return channelInsertIn($channelId, authorRole($user), (int) $user['id'], $body, 'text', $taskId, $replyTo);
}

/** ההודעות שעוד לא נמסרו לצ'אט. לא מסמן — רק channelAck מסמן. */
function channelInbox(array $channel): array {
    touchChannel((int) $channel['id']);
    return messageRows("m.channel_id = ? AND m.direction = 'in' AND m.delivered_at IS NULL", [$channel['id']]);
}

function channelAck(array $channel, array $ids): int {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return 0;
    $st = db()->prepare("UPDATE messages SET delivered_at = ?
                          WHERE channel_id = ? AND direction = 'in' AND delivered_at IS NULL
                            AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute(array_merge([nowTs(), $channel['id']], $ids));
    return $st->rowCount();
}

/**
 * כמו inbox, אבל מחכה עד $wait שניות כשאין כלום. חוזר מיד כשיש הודעה.
 * ‏$sleep מוזרק בבדיקות.
 */
function channelWait(array $channel, int $wait, ?callable $sleep = null): array {
    $max  = (int) (config()['long_poll_max'] ?? 20);
    $wait = max(0, min($wait, $max));
    $id   = (int) $channel['id'];
    touchChannel($id, nowTs() + $wait + 30);
    $sleep ??= fn () => sleep(1);

    $bell = bellValue($id);                 // לפני הבדיקה — צלצול שבא אחריה לא ייבלע
    $msgs = channelInbox($channel);
    if ($msgs || $wait === 0) return $msgs;
    if (function_exists('set_time_limit')) @set_time_limit($wait + 15);

    for ($i = 0; $i < $wait; $i++) {
        $sleep();
        if (connection_aborted()) return [];
        $now = bellValue($id);
        if ($now !== $bell) {
            $bell = $now;
            $msgs = messageRows("m.channel_id = ? AND m.direction = 'in' AND m.delivered_at IS NULL", [$id]);
            if ($msgs) return $msgs;
        }
    }
    return [];
}

/* ── ממשק ──────────────────────────────────────────────────────── */

/** הערוצים בענף של פרויקט, עם מונים: ממתינות למסירה, ושאלות פתוחות (מטלות חסומות שנשאלו בו). */
function channelsFor(array $user, int $projectId): array {
    requireProject($projectId, $user, 'read');
    $ids = projectSubtree($projectId);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = db()->prepare("SELECT c.*,
                (SELECT COUNT(*) FROM messages m WHERE m.channel_id = c.id AND m.direction = 'in' AND m.delivered_at IS NULL) pending_in,
                (SELECT COUNT(*) FROM tasks t WHERE t.asked_channel_id = c.id AND t.status = 'blocked') open_questions,
                (SELECT MAX(m.created_at) FROM messages m WHERE m.channel_id = c.id) last_message_at
           FROM channels c WHERE c.project_id IN ($in) ORDER BY c.status = 'archived', c.last_seen_at DESC");
    $st->execute($ids);
    return array_map(function ($c) {
        $out = publicChannel($c);
        foreach (['pending_in', 'open_questions'] as $k) $out[$k] = (int) $c[$k];
        $out['last_message_at'] = $c['last_message_at'] === null ? null : (int) $c['last_message_at'];
        $out['listening'] = $out['status'] === 'active' && $out['listening_until'] > nowTs();
        return $out;
    }, $st->fetchAll());
}

function channelMessages(array $user, int $channelId, int $after = 0): array {
    $channel = getChannel($channelId);
    if (!$channel) throw new AppError('הערוץ לא נמצא', 404);
    requireProject((int) $channel['project_id'], $user, 'read');
    $messages = messageRows('m.channel_id = ? AND m.id > ?', [$channelId, $after]);
    // בועת 🔐: הבקשה עצמה, האם היא עוד ממתינה, והאם הצופה רשאי להחליט
    $tasks = [];
    foreach ($messages as &$m) {
        if ($m['kind'] !== 'approval' || $m['task_id'] === null) continue;
        $t = $tasks[$m['task_id']] ??= getTask(db(), $m['task_id']);
        if (!$t) continue;
        $m['approval'] = (lastApproval($m['task_id']) ?? []) + [
            'pending'    => $t['status'] === 'awaiting_approval',
            'can_decide' => $t['status'] === 'awaiting_approval' && canAnswer($user, $t),
        ];
    }
    unset($m);
    return ['channel' => publicChannel($channel) + ['can_send' => canSendToChannel($user, $channel)],
            'messages' => $messages];
}

/** ניתוק: הערוץ עצמו, או בעל הלוח. האסימון נפסל. */
function archiveChannel(int $channelId, ?array $user = null, ?array $selfChannel = null): void {
    $channel = getChannel($channelId);
    if (!$channel) throw new AppError('הערוץ לא נמצא', 404);
    $self = $selfChannel !== null && (int) $selfChannel['id'] === $channelId;
    if (!$self && !($user && authorRole($user) === 'owner')) throw new AppError('ניתוק — הערוץ עצמו או בעל הלוח', 403);
    db()->prepare("UPDATE channels SET status = 'archived', token_hash = '', listening_until = 0 WHERE id = ?")
        ->execute([$channelId]);
    ringBell($channelId);   // מאזין שממתין עכשיו — שיתעורר ויגלה שנותק
    logEvent($user, 'channel.archive', "channel:$channelId", true, $self ? 'הערוץ עצמו' : '');
}

/* ── מטלה ↔ ערוץ ───────────────────────────────────────────────── */

/** ‏block מערוץ: המטלה זוכרת איפה נשאלה, והשאלה מופיעה גם בערוץ. */
function channelAsked(array $channel, int $taskId, string $question): void {
    db()->prepare('UPDATE tasks SET asked_channel_id = ? WHERE id = ?')->execute([$channel['id'], $taskId]);
    channelPost($channel, 'chat', $question, null, $taskId, 'question');
}

/**
 * תשובה על מטלה שנשאלה מערוץ → גם הודעה נכנסת לאותו ערוץ, עם התפקיד של
 * מי שענה (הסקיל פועל רק לפי owner). ערוץ מנותק — לא נשלח; המטלה ממילא
 * חזרה לראש התור.
 */
function channelAnswered(array $task, array $user, string $body): ?int {
    $cid = $task['asked_channel_id'] ?? null;
    if ($cid === null) return null;
    $channel = getChannel((int) $cid);
    if (!$channel || $channel['status'] !== 'active') return null;
    return channelInsertIn((int) $cid, authorRole($user), (int) $user['id'], $body, 'answer', (int) $task['id']);
}
