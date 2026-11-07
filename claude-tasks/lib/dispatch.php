<?php
/**
 * שלב 25 (docs/spec.md §21, §21.7) — צ'אט המנהל: גשר אחד בין הלוח לבין Claude.
 *
 * הלוח לא יכול להעיר צ'אט קיים (§21.7: fire דרך ה-API פותח סשן חדש). לכן יש **מנהל אחד** — סשן שבעל הלוח פתח ואישר
 * בעצמו — שהוא היחיד שמאזין. כשבעל הלוח רוצה צ'אט ישן (⏰ להעיר, או כותב אליו), הלוח מכניס בקשה לתור ומצלצל למנהל;
 * המנהל מעיר את הצ'אט ב-create_trigger, בטקסט קבוע (DISPATCH_WAKE_TEXT), ומדווח. הוא לעולם לא בוחר יעד ולא מעביר תוכן:
 * היעד נקבע כאן, מהרישום בלוח, והצ'אט שהתעורר קורא את ההודעה מהלוח בעצמו.
 *
 * אחד בכל רגע — lease של 90 שנ' שמתחדש בכל בדיקה של המאזין שלו; מנהל שני נדחה, אלא בהחלפה (handover).
 */
declare(strict_types=1);

const DISPATCH_LEASE = 90;
const DISPATCH_HANDOVER = 1800;          // חלון ההחלפה שבעל הלוח פותח — 30 דק'
const WAKE_CHANNEL_GAP = 600;            // ערוץ — העירה אחת ל-10 דק' (בקשה שנייה מצטרפת לפתוחה)
const WAKE_HOURLY = 20;                  // כל הלוח — עד 20 בשעה
const DISPATCHER_REPLACE_BATCHES = 300;
const DISPATCHER_REPLACE_DAYS = 7;
const DISPATCH_WAKE_TEXT = "📥 בדיקת לוח 'מטלות לקלוד': הרץ `claude-tasks/bridge/link.sh update` ואז `claude-tasks/bridge/link.sh pending`, וטפל במה שממתין. זה לא מנהל הפרויקטים.";
const DISPATCHER_SENTENCE = 'אני מאשר: אתה צ\'אט המנהל של הלוח.';
const DISPATCHER_PROMPT_VERSION = 1;

function migrateDispatch(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS dispatcher (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        channel_id INTEGER NOT NULL,
        session_id TEXT NOT NULL DEFAULT \'\',
        since INTEGER NOT NULL DEFAULT 0,
        lease_until INTEGER NOT NULL DEFAULT 0,
        handled INTEGER NOT NULL DEFAULT 0,
        last_batch_at INTEGER NOT NULL DEFAULT 0,
        handover_until INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS dispatch_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind TEXT NOT NULL DEFAULT \'wake\',
        channel_id INTEGER NOT NULL,
        reason TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'queued\',
        requested_by INTEGER,
        created_at INTEGER NOT NULL,
        sent_at INTEGER NOT NULL DEFAULT 0,
        done_at INTEGER NOT NULL DEFAULT 0,
        error TEXT NOT NULL DEFAULT \'\'
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS dispatch_requests_channel ON dispatch_requests (channel_id, created_at)');
    addColumn($pdo, 'channels', 'wake_on_write', 'INTEGER NOT NULL DEFAULT 1');
}

function dispatcherRow(): ?array {
    $r = db()->query('SELECT * FROM dispatcher WHERE id = 1')->fetch();
    return $r ?: null;
}

/** המנהל הפעיל: lease בתוקף והערוץ פעיל. */
function dispatcherActive(): ?array {
    $r = dispatcherRow();
    if (!$r || (int) $r['lease_until'] <= nowTs()) return null;
    $c = getChannel((int) $r['channel_id']);
    return $c && $c['status'] === 'active' ? $r : null;
}

/**
 * אפשר להכניס לתור: המנהל נראה ב-15 הדקות האחרונות. בין סוף המאזין שלו (timeout) להפעלה מחדש עובר תור קצר —
 * בקשה שנכנסה אז מחכה לו, והמאזין החדש יוצא מיד כשהוא רואה אותה.
 */
const DISPATCH_GRACE = 900;
function dispatcherReachable(): ?array {
    $r = dispatcherRow();
    if (!$r || (int) $r['lease_until'] <= nowTs() - DISPATCH_GRACE) return null;
    $c = getChannel((int) $r['channel_id']);
    return $c && $c['status'] === 'active' ? $r : null;
}

function isDispatcherChannel(array $c): bool { return ($c['role'] ?? '') === 'dispatcher'; }

/**
 * לפני הרישום של סשן בתפקיד dispatcher: יש מנהל אחר פעיל — נדחה, אלא אם בעל הלוח פתח החלפה והסשן ביקש להחליף.
 */
function dispatcherPrecheck(string $sessionId, bool $replaces): void {
    $r = dispatcherRow();
    if (!$r || (int) $r['lease_until'] <= nowTs() || $r['session_id'] === trim($sessionId)) return;
    $c = getChannel((int) $r['channel_id']);
    if (!$c || $c['status'] !== 'active') return;
    if ($replaces && (int) $r['handover_until'] > nowTs()) return;
    throw new AppError($replaces
        ? 'אין החלפה פתוחה — בעל הלוח לוחץ "🔁 החלפת מנהל" בלוח, ואז נרשמים שוב'
        : 'כבר יש מנהל פעיל (' . $c['name'] . '). כדי להחליף: "🔁 החלפת מנהל" בלוח, ואז link.sh register … --replaces-dispatcher', 409, 'dispatcher_exists');
}

/** אחרי הרישום: הערוץ הוא המנהל. ההחלפה אטומית — הישן יקבל retired בבדיקה הבאה שלו. */
function dispatcherClaim(array $channel): void {
    $now = nowTs();
    $r = dispatcherRow();
    if ($r && (int) $r['channel_id'] === (int) $channel['id']) {
        db()->prepare('UPDATE dispatcher SET lease_until = ?, session_id = ? WHERE id = 1')->execute([$now + DISPATCH_LEASE, $channel['session_id']]);
        return;
    }
    db()->prepare('INSERT INTO dispatcher (id, channel_id, session_id, since, lease_until, handled, last_batch_at, handover_until)
                   VALUES (1, ?, ?, ?, ?, 0, 0, 0)
                   ON CONFLICT(id) DO UPDATE SET channel_id = excluded.channel_id, session_id = excluded.session_id, since = excluded.since,
                     lease_until = excluded.lease_until, handled = 0, last_batch_at = 0, handover_until = 0')
        ->execute([(int) $channel['id'], (string) $channel['session_id'], $now, $now + DISPATCH_LEASE]);
    logEvent(null, 'dispatcher.claim', "channel:{$channel['id']}", true, (string) $channel['name']);
}

/** כל בדיקה של המנהל מחדשת את ה-lease. ‏'active' / 'retired' (מנהל אחר תפס את המקום). */
function dispatcherRenew(array $channel): string {
    $r = dispatcherRow();
    if (!$r || (int) $r['channel_id'] !== (int) $channel['id']) {
        // אין מנהל בכלל (ה-lease פג והשורה נמחקה) — הוא חוזר להיות המנהל; מנהל אחר — הוא פרש
        if (!$r) { dispatcherClaim($channel); return 'active'; }
        return 'retired';
    }
    db()->prepare('UPDATE dispatcher SET lease_until = ? WHERE id = 1')->execute([nowTs() + DISPATCH_LEASE]);
    return 'active';
}

function dispatcherStartHandover(array $user): array {
    if (authorRole($user) !== 'owner') throw new AppError('החלפת מנהל — בעל הלוח בלבד', 403);
    db()->prepare('UPDATE dispatcher SET handover_until = ? WHERE id = 1')->execute([nowTs() + DISPATCH_HANDOVER]);
    logEvent($user, 'dispatcher.handover', 'dispatcher', true, '');
    return dispatcherInfo();
}

/* ── התור ──────────────────────────────────────────────────────── */

/**
 * ⏰ העירה לערוץ. ‏$auto — כשבעל הלוח כותב לצ'אט ישן (בלי שגיאה: אם אי אפשר, פשוט לא מעירים).
 * מחזיר את הבקשה (חדשה, או הפתוחה שהבקשה הצטרפה אליה).
 */
function queueWake(?array $user, int $channelId, string $reason = '', bool $auto = false): array {
    if ($user && authorRole($user) !== 'owner') throw new AppError('להעיר צ\'אט — בעל הלוח בלבד', 403);
    $c = getChannel($channelId);
    if (!$c) throw new AppError('הערוץ לא נמצא', 404);
    if ($c['status'] !== 'active') throw new AppError('הערוץ אינו פעיל — אין את מי להעיר', 409, 'not_active');
    if (isDispatcherChannel($c)) throw new AppError('זה צ\'אט המנהל עצמו', 409, 'is_dispatcher');
    if ((int) $c['listening_until'] > nowTs()) throw new AppError('הצ\'אט מאזין — ההודעה תגיע אליו לבד', 409, 'listening');
    if (trim((string) $c['session_id']) === '') throw new AppError('לצ\'אט אין session ידוע', 409, 'no_session');
    if (!dispatcherReachable()) throw new AppError('🛰 אין מנהל פעיל — לא ניתן להעיר. ⚙ ← מערכת ← צ\'אט מנהל', 409, 'no_dispatcher');
    $now = nowTs();
    // בקשה פתוחה, או העירה ב-10 הדקות האחרונות — מצטרפים אליה
    $st = db()->prepare("SELECT * FROM dispatch_requests WHERE channel_id = ? AND (status IN ('queued', 'sent') OR created_at > ?)
                          ORDER BY id DESC LIMIT 1");
    $st->execute([$channelId, $now - WAKE_CHANNEL_GAP]);
    if ($open = $st->fetch()) return dispatchRequestPublic($open) + ['joined' => true];
    $n = db()->prepare('SELECT COUNT(*) FROM dispatch_requests WHERE created_at > ?');
    $n->execute([$now - 3600]);
    if ((int) $n->fetchColumn() >= WAKE_HOURLY) throw new AppError('הגענו ל-' . WAKE_HOURLY . ' העירות בשעה — נסה שוב בעוד כמה דקות', 429, 'wake_limit');
    db()->prepare('INSERT INTO dispatch_requests (kind, channel_id, reason, status, requested_by, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['wake', $channelId, mb_substr(trim($reason), 0, 80) ?: ($auto ? 'כתבת לצ\'אט' : '⏰ להעיר'), 'queued', $user['id'] ?? null, $now]);
    $id = (int) db()->lastInsertId();
    ringBell((int) dispatcherReachable()['channel_id']);
    logEvent($user, 'dispatch.queue', "channel:$channelId", true, "#$id" . ($auto ? ' · auto' : ''));
    return dispatchRequestPublic(dispatchRequest($id)) + ['joined' => false];
}

/** כשבעל הלוח כותב לצ'אט שלא מאזין (הגדרת הערוץ "להעיר כשאני כותב", ברירת מחדל דולקת). שקט אם אי אפשר. */
function autoWake(array $user, array $channel): ?array {
    if (authorRole($user) !== 'owner' || !(int) ($channel['wake_on_write'] ?? 1)) return null;
    if ((int) $channel['listening_until'] > nowTs() || isDispatcherChannel($channel)) return null;
    try { return queueWake($user, (int) $channel['id'], '', true); } catch (AppError $e) { return null; }
}

/** ⏰ להעיר את כל הישנים — בענף של הפרויקט. מחזיר כמה נכנסו לתור, ולמה האחרים לא. */
function wakeAllSleeping(array $user, int $projectId): array {
    if (authorRole($user) !== 'owner') throw new AppError('בעל הלוח בלבד', 403);
    $out = ['queued' => 0, 'skipped' => []];
    foreach (wontWake($user, $projectId) as $c) {
        try { $r = queueWake($user, (int) $c['id'], '⏰ להעיר את כל הישנים'); if (!$r['joined']) $out['queued']++; }
        catch (AppError $e) { $out['skipped'][] = ['id' => (int) $c['id'], 'name' => $c['name'], 'why' => $e->getMessage()]; if ($e->errorCode === 'wake_limit' || $e->errorCode === 'no_dispatcher') break; }
    }
    return $out;
}

function dispatchRequest(int $id): ?array {
    $st = db()->prepare('SELECT * FROM dispatch_requests WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function dispatchRequestPublic(array $r): array {
    return ['id' => (int) $r['id'], 'channel_id' => (int) $r['channel_id'], 'status' => (string) $r['status'], 'reason' => (string) $r['reason'],
            'created_at' => (int) $r['created_at'], 'sent_at' => (int) $r['sent_at'], 'error' => (string) $r['error']];
}

/** כמה בקשות ממתינות למנהל — ל-channel-wait שלו (המאזין יוצא כשיש). */
function dispatchQueuedCount(): int {
    return (int) db()->query("SELECT COUNT(*) FROM dispatch_requests WHERE status = 'queued'")->fetchColumn();
}

/**
 * ‏link.sh dispatch-pending — המנהל בלבד. היעד (session_id) נקבע כאן, מהרישום העדכני בלוח; ערוץ שבינתיים מאזין או
 * נסגר — לא מעירים (failed עם הסיבה). מה שחוזר מסומן sent; האצווה נספרת.
 */
function dispatchPending(array $channel): array {
    if (!isDispatcherChannel($channel)) throw new AppError('רק צ\'אט המנהל', 403, 'not_dispatcher');
    if (dispatcherRenew($channel) === 'retired') return ['retired' => true, 'requests' => [], 'text' => DISPATCH_WAKE_TEXT];
    $now = nowTs();
    $rows = db()->query("SELECT * FROM dispatch_requests WHERE status = 'queued' ORDER BY id LIMIT " . WAKE_HOURLY)->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $c = getChannel((int) $r['channel_id']);
        $why = !$c || $c['status'] !== 'active' ? 'הערוץ נסגר' : ((int) $c['listening_until'] > $now ? 'הצ\'אט כבר מאזין' : (trim((string) $c['session_id']) === '' ? 'אין session' : ''));
        if ($why !== '') {
            db()->prepare("UPDATE dispatch_requests SET status = 'failed', error = ?, done_at = ? WHERE id = ?")->execute([$why, $now, $r['id']]);
            continue;
        }
        db()->prepare("UPDATE dispatch_requests SET status = 'sent', sent_at = ? WHERE id = ?")->execute([$now, $r['id']]);
        $out[] = ['id' => (int) $r['id'], 'kind' => 'wake', 'session_id' => (string) $c['session_id'], 'channel' => (string) $c['name']];
    }
    if ($out) db()->prepare('UPDATE dispatcher SET handled = handled + 1, last_batch_at = ? WHERE id = 1')->execute([$now]);
    return ['retired' => false, 'requests' => $out, 'text' => DISPATCH_WAKE_TEXT];
}

/** ‏link.sh dispatch-done <id> ok|<error> — המנהל מדווח. רק על בקשה שנמסרה לו. */
function dispatchDone(array $channel, int $id, bool $ok, string $error = ''): array {
    if (!isDispatcherChannel($channel)) throw new AppError('רק צ\'אט המנהל', 403, 'not_dispatcher');
    $r = dispatchRequest($id);
    if (!$r || $r['status'] !== 'sent') throw new AppError("בקשה #$id אינה ממתינה לדיווח", 409);
    db()->prepare('UPDATE dispatch_requests SET status = ?, error = ?, done_at = ? WHERE id = ?')
        ->execute([$ok ? 'done' : 'failed', $ok ? '' : mb_substr(trim($error), 0, 200), nowTs(), $id]);
    return dispatchRequestPublic(dispatchRequest($id));
}

/* ── מה השורה אומרת (§21.3) ────────────────────────────────────── */

/**
 * ‏{state} לערוץ: dispatcher · sent (נשלחה העירה, ממתין שיענה) · failed (+reason) · no_dispatcher · can_wake.
 * ‏null — הערוץ מאזין / לא פעיל (השורה אומרת את זה בעצמה).
 */
function wakeInfo(array $c): ?array {
    if (($c['status'] ?? '') !== 'active') return null;
    if (isDispatcherChannel($c)) {
        $d = dispatcherRow();
        $mine = $d && (int) $d['channel_id'] === (int) $c['id'];
        return ['state' => $mine && (int) $d['lease_until'] > nowTs() ? 'dispatcher' : ($mine ? 'dispatcher_down' : 'dispatcher_retired'),
                'last_batch_at' => $mine ? (int) $d['last_batch_at'] : 0];
    }
    if ((int) $c['listening_until'] > nowTs()) return null;
    $st = db()->prepare('SELECT * FROM dispatch_requests WHERE channel_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([(int) $c['id']]);
    $r = $st->fetch();
    if ($r && (int) $r['created_at'] > nowTs() - 86400) {
        if ($r['status'] === 'failed') return ['state' => 'failed', 'reason' => (string) $r['error'], 'at' => (int) $r['created_at']];
        // ממתין שיענה — עד שהצ'אט נראה בלוח אחרי ההעירה
        if ((int) $c['last_seen_at'] <= (int) $r['created_at']) return ['state' => 'sent', 'at' => (int) ($r['sent_at'] ?: $r['created_at']), 'status' => (string) $r['status']];
    }
    return ['state' => dispatcherReachable() ? 'can_wake' : 'no_dispatcher'];
}

/** כרטיס "🛰 צ'אט מנהל" (⚙ ← מערכת). */
function dispatcherInfo(): array {
    $r = dispatcherRow();
    $c = $r ? getChannel((int) $r['channel_id']) : null;
    $active = (bool) dispatcherActive();
    $hour = db()->prepare('SELECT COUNT(*) FROM dispatch_requests WHERE created_at > ?');
    $hour->execute([nowTs() - 3600]);
    return [
        'active' => $active,
        'channel_id' => $c ? (int) $c['id'] : null, 'name' => $c['name'] ?? '', 'session_url' => $c['session_url'] ?? '',
        'since' => $r ? (int) $r['since'] : 0, 'lease_until' => $r ? (int) $r['lease_until'] : 0,
        'last_batch_at' => $r ? (int) $r['last_batch_at'] : 0, 'handled' => $r ? (int) $r['handled'] : 0,
        'handover_until' => $r ? (int) $r['handover_until'] : 0,
        'replace_due' => $r && $active && ((int) $r['handled'] >= DISPATCHER_REPLACE_BATCHES || nowTs() - (int) $r['since'] >= DISPATCHER_REPLACE_DAYS * 86400),
        'wakes_hour' => (int) $hour->fetchColumn(), 'wakes_hour_cap' => WAKE_HOURLY, 'queued' => dispatchQueuedCount(),
        'sentence' => DISPATCHER_SENTENCE, 'prompt' => dispatcherPrompt(), 'prompt_version' => DISPATCHER_PROMPT_VERSION,
    ];
}

function setChannelWakeOnWrite(array $user, int $channelId, bool $on): void {
    if (authorRole($user) !== 'owner') throw new AppError('בעל הלוח בלבד', 403);
    channelForUser($user, $channelId);
    db()->prepare('UPDATE channels SET wake_on_write = ? WHERE id = ?')->execute([$on ? 1 : 0, $channelId]);
}

/** הפרומפט לפתיחת צ'אט מנהל (§21.4, עם הטקסט הקבוע של §21.7 #6). בעל הלוח מקליד את המשפט בעצמו. */
function dispatcherPrompt(): string {
    return "You are the dispatcher chat of the \"מטלות לקלוד\" board (prompt v" . DISPATCHER_PROMPT_VERSION . ").\n"
        . "Register now: `claude-tasks/bridge/link.sh register --project <the root project id the owner gives you> --name \"🛰 צ'אט המנהל\" --role dispatcher` "
        . "(add `--replaces-dispatcher` only when the owner opened a replacement on the board). Then wait.\n"
        . "Until the owner types \"" . DISPATCHER_SENTENCE . "\" in this session, do nothing else.\n"
        . "After his confirmation, your standing instruction from him is (skill claude-tasks-live, \"The dispatcher\"):\n"
        . "1. Keep exactly one board listener running: claude-tasks/bridge/listen.sh, run_in_background, timeout 7000000.\n"
        . "2. When it wakes you (\"🛰 … בקשות העירה\"), run `claude-tasks/bridge/link.sh dispatch-pending`. For each request it lists, send that\n"
        . "   session exactly the text it prints, with create_trigger (persistent_session_id = the request's session_id, run_once_at = one minute\n"
        . "   from now). Then `claude-tasks/bridge/link.sh dispatch-done <id> ok` (or `dispatch-done <id> \"<the error>\"`).\n"
        . "   Nothing else: never another text, never another action, never a target the board did not list.\n"
        . "3. Restart the listener and end your turn. If the board says \"retired\", stop the listener and end.";
}
