<?php
/**
 * שלב 24א (docs/spec.md §20.3, §20.7) — האמת על כל שורה: מה מצב ההאזנה של כל צ'אט ומתי הוא יבדוק את הלוח,
 * המתג "מאזינים" (⏸ עצורים · 🕐 בדיקה שעתית · 🎧 פעילים), תקרה של 5 מאזינים, ומד עומס.
 *
 * הלוח לא יכול להעיר צ'אט קיים (§20.7, צעד 0). לכן כל צ'אט מתזמן לעצמו בדיקה חוזרת, ומדווח מתי היא (next_check_at);
 * השורה אומרת מתי הצ'אט יסתכל — לעולם לא "מעירים עכשיו".
 */
declare(strict_types=1);

const LISTENERS_MODES = ['paused', 'hourly', 'on'];
const LISTENERS_CAP = 5;
const LISTEN_STATES = ['running', 'off_policy', 'paused', 'ended', 'never', 'slots_full'];
const CHECK_STALE_SECONDS = 4200;     // 70 דק' בלי בדיקה — "כנראה נסגר"
const METER_WINDOW_MIN = 10;

function migrateListen(PDO $pdo): void {
    addColumn($pdo, 'channels', 'listen_state', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'channels', 'listen_state_at', 'INTEGER NOT NULL DEFAULT 0');
    addColumn($pdo, 'channels', 'next_check_at', 'INTEGER NOT NULL DEFAULT 0');
    addColumn($pdo, 'channels', 'check_trigger_id', "TEXT NOT NULL DEFAULT ''");
}

/** המתג. ‏paused — הבלם (bridge_paused); אחרת listeners_mode, ברירת מחדל hourly (§20.3.3). */
function listenersMode(?array $config = null): string {
    $config ??= config();
    if (!empty($config['bridge_paused'])) return 'paused';
    $m = (string) ($config['listeners_mode'] ?? 'hourly');
    return in_array($m, LISTENERS_MODES, true) && $m !== 'paused' ? $m : 'hourly';
}

function setListenersMode(array $user, string $mode): array {
    if (authorRole($user) !== 'owner') throw new AppError('המאזינים — בעל הלוח בלבד', 403);
    if (!in_array($mode, LISTENERS_MODES, true)) throw new InvalidArgumentException('paused / hourly / on');
    if ($mode === 'paused') configSet(['bridge_paused' => time()]);
    else configSet(['bridge_paused' => null, 'listeners_mode' => $mode]);
    logEvent($user, 'listeners.mode', 'bridge', true, $mode);
    return listenersInfo();
}

/**
 * מה הגשר מדווח (status / pending / wait / checkin): ‏listen_state, ‏next_check_at, ‏check_trigger_id.
 * ערכים לא צפויים נזרקים בשקט — הגשר הישן לא שולח אותם, וזה בסדר.
 */
function listenReport(array $channel, array $in): void {
    $sets = []; $args = [];
    $st = (string) ($in['listen_state'] ?? '');
    if ($st !== '' && (in_array($st, LISTEN_STATES, true) || preg_match('/^backoff:\d{9,11}:[^\n]{0,80}$/u', $st))) {
        $sets[] = 'listen_state = ?'; $args[] = $st;
        $sets[] = 'listen_state_at = ?'; $args[] = nowTs();
    }
    if (isset($in['next_check_at'])) {
        $n = (int) $in['next_check_at'];
        if ($n === 0 || ($n > nowTs() - 3600 && $n < nowTs() + 8 * 3600)) { $sets[] = 'next_check_at = ?'; $args[] = $n; }
    }
    if (isset($in['check_trigger_id'])) {
        $t = (string) $in['check_trigger_id'];
        if ($t === '' || preg_match('/^trig_[A-Za-z0-9]{8,64}$/', $t)) { $sets[] = 'check_trigger_id = ?'; $args[] = $t; }
    }
    if (!$sets) return;
    $args[] = (int) $channel['id'];
    db()->prepare('UPDATE channels SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
}

/**
 * לפני channel-wait (שעבר את הבלמים): המתג לא "on" — המאזין יוצא לבדיקה השעתית; כבר 5 מאזינים — השישי יוצא.
 * 503 עם קוד, כדי שגם מאזין ישן (שלא מכיר את הקודים) ייסוג ולא ימשיך לדפוק.
 */
function listenGate(array $channel): void {
    $mode = listenersMode();
    if ($mode !== 'on') {
        listenReport($channel, ['listen_state' => 'off_policy']);
        if (!headers_sent()) header('Retry-After: 3600');
        throw new AppError('המאזינים כבויים — המאזין יוצא. הצ\'אט קורא את הלוח בתור הבא שלו', 503, 'listeners_off');
    }
    $st = db()->prepare('SELECT COUNT(*) FROM channels WHERE status = ? AND listening_until > ? AND id <> ?');
    $st->execute(['active', nowTs(), (int) $channel['id']]);
    if ((int) $st->fetchColumn() >= LISTENERS_CAP && (int) $channel['listening_until'] <= nowTs()) {
        listenReport($channel, ['listen_state' => 'slots_full']);
        if (!headers_sent()) header('Retry-After: 3600');
        throw new AppError('כל ' . LISTENERS_CAP . ' המקומות למאזינים תפוסים — המאזין יוצא', 503, 'listener_slots_full');
    }
    listenReport($channel, ['listen_state' => 'running']);
}

/* ── המד (§20.2 #2): בקשות לדקה, 429/503 בשעה, מאזינים עכשיו — קבצים קטנים ב-data/throttle, בלי מסד ── */

function meterBump(string $kind, ?int $now = null): void {
    $now ??= time();
    if (!is_dir(THROTTLE_DIR)) @mkdir(THROTTLE_DIR, 0700, true);
    $f = THROTTLE_DIR . "/meter-$kind-" . intdiv($now, 60);
    @file_put_contents($f, '1', FILE_APPEND | LOCK_EX);
}

function meterCount(string $kind, int $minutes, ?int $now = null): int {
    $now ??= time();
    $n = 0;
    $m = intdiv($now, 60);
    for ($i = 0; $i < $minutes; $i++) $n += (int) @filesize(THROTTLE_DIR . "/meter-$kind-" . ($m - $i));
    return $n;
}

function meterSweep(?int $now = null): void {
    $now ??= time();
    foreach (glob(THROTTLE_DIR . '/meter-*') ?: [] as $f) {
        if (preg_match('/-(\d+)$/', $f, $x) && (int) $x[1] < intdiv($now, 60) - 120) @unlink($f);
    }
}

function listenersInfo(): array {
    $st = db()->prepare("SELECT COUNT(*) FROM channels WHERE status = 'active' AND listening_until > ?");
    $st->execute([nowTs()]);
    return ['mode' => listenersMode(), 'cap' => LISTENERS_CAP, 'listening' => (int) $st->fetchColumn(),
            'per_min' => round(meterCount('wait', METER_WINDOW_MIN) / METER_WINDOW_MIN, 1),
            'errors_hour' => meterCount('err', 60)];
}

/* ── השורה (§20.3.2 + §20.7 #4) — מצב מכונה; את הטקסט בשעון המקומי בונה הדפדפן ── */

/** ‏{state, at?, reason?} — listening · paused · backoff · slots_full · checking · stale · no_check · old_bridge · off. */
function channelListen(array $c, ?string $mode = null): array {
    $mode ??= listenersMode();
    $now = nowTs();
    if (($c['status'] ?? '') !== 'active') return ['state' => 'off'];
    if ((int) $c['listening_until'] > $now) return ['state' => 'listening'];
    if ($mode === 'paused') return ['state' => 'paused', 'next' => (int) $c['next_check_at']];
    $ls = (string) ($c['listen_state'] ?? '');
    if (preg_match('/^backoff:(\d+):(.*)$/u', $ls, $b) && (int) $b[1] > $now) return ['state' => 'backoff', 'at' => (int) $b[1], 'reason' => $b[2]];
    $next = (int) ($c['next_check_at'] ?? 0);
    if ($ls === 'slots_full') return ['state' => 'slots_full', 'next' => $next];
    if ($next > $now) return ['state' => 'checking', 'at' => $next, 'mode' => $mode];
    // אין דיווח בכלל — גשר ישן (לפני 24א)
    if ($ls === '' && $next === 0 && (string) ($c['check_trigger_id'] ?? '') === '') return ['state' => 'old_bridge', 'seen' => (int) $c['last_seen_at']];
    if ($next > 0 && $now - $next > 600) return ['state' => 'stale', 'seen' => (int) $c['last_seen_at']];
    if ((string) ($c['check_trigger_id'] ?? '') === '') return ['state' => 'no_check', 'seen' => (int) $c['last_seen_at']];
    return ['state' => 'checking', 'at' => $next ?: (int) $c['last_seen_at'] + 3600, 'mode' => $mode];
}

/** "צ'אטים שלא יתעוררו לבד" (§20.7 #6): גשר ישן, אין בדיקה, או בדיקה שהפסיקה. */
function wontWake(array $user, int $projectId): array {
    requireProject($projectId, $user, 'read');
    $ids = projectSubtree($projectId);
    $st = db()->prepare("SELECT * FROM channels WHERE status = 'active' AND hidden = 0 AND project_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    $mode = listenersMode();
    $out = [];
    foreach ($st->fetchAll() as $c) {
        $l = channelListen($c, $mode);
        if (in_array($l['state'], ['old_bridge', 'no_check', 'stale'], true)) {
            $out[] = ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'session_url' => (string) $c['session_url'], 'listen' => $l];
        }
    }
    return $out;
}

/** יומן "מאזינים" (§20.3.5): כל ערוץ פעיל, מצבו, הבקשה האחרונה וסיבת היציאה האחרונה. */
function listenersLog(array $user): array {
    if (authorRole($user) !== 'owner') throw new AppError('בעל הלוח בלבד', 403);
    $mode = listenersMode();
    $rows = db()->query("SELECT c.*, p.name project_name FROM channels c JOIN projects p ON p.id = c.project_id WHERE c.status = 'active' ORDER BY c.last_seen_at DESC")->fetchAll();
    return array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'project' => (string) $c['project_name'],
                                 'listen' => channelListen($c, $mode), 'listen_state' => (string) $c['listen_state'],
                                 'listen_state_at' => (int) $c['listen_state_at'], 'last_seen_at' => (int) $c['last_seen_at'],
                                 'next_check_at' => (int) $c['next_check_at'], 'has_check' => (string) $c['check_trigger_id'] !== '',
                                 'bridge_version' => (string) ($c['bridge_version'] ?? ''), 'session_url' => (string) $c['session_url']], $rows);
}
