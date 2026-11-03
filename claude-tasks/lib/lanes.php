<?php
/**
 * שני מסלולים, שיחה אחת — docs/spec.md §15. שלב 15: Platform נעצר (§15.3).
 *
 * מפסק לכל משלם (platform_state): כששרשרת החיבורים שלו נכשלת כולה בכשל שאינו "בקשה שגויה" — המפסק נפתח.
 * כל עוד הוא פתוח, ☁️ מחושב כ-💬 (כמו כלל המכסה, lib/routes.php) — אם המשתמש בחר "להעביר לצ'אט אוטומטית"
 * (ההגדרה שלו, §15.11; ברירת המחדל: כן). אחרת ההודעות ממתינות, ו-Platform לא נקרא עד שהמפסק נסגר.
 *
 * ניסיון חוזר: עומס — אחרי 5 דק', תקלה — אחרי 30 דק'. קרדיט / מפתח — לא לבד: רק כשהמשתמש מתקן חיבור,
 * או לוחץ "נסה שוב" (קריאת בדיקה אחת). הצ'אט שומע על זה פעם אחת בפתיחה ופעם אחת בחזרה — לא על כל הודעה.
 */
declare(strict_types=1);

/** ‏0 = רק ידנית ("נסה שוב" / שינוי בחיבור). */
const BREAKER_RETRY = ['rate_limited' => 300, 'down' => 1800, 'credit_exhausted' => 0, 'key_rejected' => 0];
const LANE_PREFS_DEFAULT = ['platform_stop' => 'handover'];   // handover | wait — שלבים 16–18 יוסיפו
const LANE_PREFS_VALUES = ['platform_stop' => ['handover', 'wait']];

function migrateLanes(PDO $pdo): void {
    addColumn($pdo, 'users', 'lane_prefs', "TEXT NOT NULL DEFAULT ''");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS platform_state (
            user_id   INTEGER PRIMARY KEY,
            state     TEXT NOT NULL DEFAULT 'ok',
            since     INTEGER NOT NULL DEFAULT 0,
            retry_at  INTEGER NOT NULL DEFAULT 0,
            reason    TEXT NOT NULL DEFAULT '',
            provider  TEXT NOT NULL DEFAULT ''
        );
    ");
}

/* ── ההגדרה של המשתמש (§15.11) ───────────────────────────── */

function lanePrefs(int $userId): array {
    $st = db()->prepare('SELECT lane_prefs FROM users WHERE id = ?');
    $st->execute([$userId]);
    $p = json_decode((string) $st->fetchColumn(), true);
    $out = LANE_PREFS_DEFAULT;
    foreach (is_array($p) ? $p : [] as $k => $v) {
        if (isset(LANE_PREFS_VALUES[$k]) && in_array($v, LANE_PREFS_VALUES[$k], true)) $out[$k] = $v;
    }
    return $out;
}

/** כל משתמש — רק את ההגדרה של עצמו. נאכף כאן, לא רק בממשק. */
function setLanePrefs(array $user, array $in): array {
    $cur = lanePrefs((int) $user['id']);
    foreach ($in as $k => $v) {
        if (!isset(LANE_PREFS_VALUES[$k])) continue;
        if (!in_array($v, LANE_PREFS_VALUES[$k], true)) throw new InvalidArgumentException("ערך לא מוכר ל-$k");
        $cur[$k] = $v;
    }
    db()->prepare('UPDATE users SET lane_prefs = ? WHERE id = ?')->execute([json_encode($cur), $user['id']]);
    logEvent($user, 'lanes.prefs', 'user:' . $user['id'], true, json_encode($cur));
    return $cur;
}

/* ── המפסק ─────────────────────────────────────────────── */

function platformBreaker(int $userId): array {
    $st = db()->prepare('SELECT * FROM platform_state WHERE user_id = ?');
    $st->execute([$userId]);
    $r = $st->fetch() ?: ['state' => 'ok', 'since' => 0, 'retry_at' => 0, 'reason' => '', 'provider' => ''];
    $open = $r['state'] !== 'ok';
    $retry = (int) $r['retry_at'];
    return ['state' => (string) $r['state'], 'since' => (int) $r['since'], 'retry_at' => $retry, 'reason' => (string) $r['reason'],
            'open' => $open, 'manual' => $open && BREAKER_RETRY[$r['state']] === 0 && $retry === 0,
            // הגיע הזמן לנסות שוב: ההודעה הבאה עוברת ל-Platform כבדיקה (half-open)
            'probe_due' => $open && $retry > 0 && nowTs() >= $retry,
            'handover' => lanePrefs($userId)['platform_stop'] === 'handover'];
}

/** פתוח ועוד לא הגיע זמן לנסות — Platform לא נקרא. */
function breakerBlocks(int $userId): bool {
    $b = platformBreaker($userId);
    return $b['open'] && !$b['probe_due'];
}

/** ‏☁️ מחושב כ-💬: המפסק חוסם, והמשתמש בחר להעביר (lib/routes.php, lib/usage.php platformAvailable). */
function platformHandsOver(int $userId): bool {
    return breakerBlocks($userId) && lanePrefs($userId)['platform_stop'] === 'handover';
}

/**
 * כל השרשרת נכשלה. ‏bad_request — לא פותח (הבקשה עצמה שגויה). מעבר מ-ok לפתוח: הודעה אחת לכל ערוץ,
 * והעברה במעבר אחד של כל מה שממתין ל-Platform. פתוח כבר (בדיקה שנכשלה) — רק זמן הניסיון הבא מתעדכן.
 */
function breakerTrip(int $userId, string $class, string $reason, string $provider = ''): bool {
    if (!isset(BREAKER_RETRY[$class])) return false;
    $before = platformBreaker($userId);
    $retry = BREAKER_RETRY[$class] > 0 ? nowTs() + BREAKER_RETRY[$class] : 0;
    db()->prepare('INSERT INTO platform_state (user_id, state, since, retry_at, reason, provider) VALUES (?,?,?,?,?,?)
                   ON CONFLICT(user_id) DO UPDATE SET state = excluded.state, retry_at = excluded.retry_at,
                     reason = excluded.reason, provider = excluded.provider,
                     since = CASE WHEN platform_state.state = \'ok\' THEN excluded.since ELSE platform_state.since END')
        ->execute([$userId, $class, nowTs(), $retry, mb_substr($reason, 0, 500), $provider]);
    logEvent(null, 'platform.breaker', "user:$userId", false, "$class — " . mb_substr($reason, 0, 300));
    if ($before['open']) return false;
    $handover = lanePrefs($userId)['platform_stop'] === 'handover';
    $short = breakerShort($class, $reason);
    foreach (platformChannels($userId) as $ch) {
        channelInsertIn((int) $ch['id'], 'system', null, $handover
            ? "☁️ Platform נעצר — $short. מכאן הערוץ אצלך."
            : "☁️ Platform נעצר — $short. לפי ההגדרה של בעל החשבון ההודעות ממתינות עד שיחזור.", 'status', null, null, false);
    }
    if ($handover) handOverWaiting($userId, $short);
    return true;
}

/** סגירה (בדיקה שהצליחה). הודעה אחת לכל ערוץ: חזר. */
function breakerReset(int $userId): bool {
    $st = db()->prepare("UPDATE platform_state SET state = 'ok', retry_at = 0, reason = '' WHERE user_id = ? AND state != 'ok'");
    $st->execute([$userId]);
    if ($st->rowCount() === 0) return false;
    logEvent(null, 'platform.breaker', "user:$userId", true, 'recovered');
    foreach (platformChannels($userId) as $ch) {
        channelInsertIn((int) $ch['id'], 'system', null, '☁️ Platform חזר — הערוץ שוב עונה ב-☁️.', 'status', null, null, false);
    }
    return true;
}

/** המשתמש שינה חיבור: קרדיט / מפתח נבדקים שוב בהודעה הבאה (לא נסגר "על עיוור"). */
function breakerArm(int $userId): void {
    db()->prepare("UPDATE platform_state SET retry_at = ? WHERE user_id = ? AND state != 'ok'")->execute([nowTs(), $userId]);
}

function breakerShort(string $class, string $reason): string {
    $base = ['credit_exhausted' => 'נגמר הקרדיט אצל הספק', 'key_rejected' => 'המפתח נדחה', 'rate_limited' => 'הספק עמוס',
             'down' => 'הספק לא זמין'][$class] ?? 'כשל אצל הספק';
    return $base . ($class === 'rate_limited' ? ' (ניסיון חוזר בעוד 5 דק\')' : ($class === 'down' ? ' (ניסיון חוזר בעוד 30 דק\')' : ''));
}

/** הערוצים שהמשתמש משלם עליהם ושהמסלול שנבחר להם הוא ☁️ — מי שצריך לשמוע. */
function platformChannels(int $userId): array {
    $wanted = projectRoutes(true);
    return array_values(array_filter(userChannels($userId), fn ($ch) =>
        (in_array($ch['route'] ?? '', ROUTES, true) ? $ch['route'] : ($wanted[(int) $ch['project_id']] ?? '')) === 'platform'));
}

/** מעבר אחד: כל הודעה שממתינה ל-Platform בערוצים של המשלם — לצ'אט (פעמון). מחזיר כמה. */
function handOverWaiting(int $userId, string $why): int {
    $ids = array_map(fn ($c) => (int) $c['id'], userChannels($userId));
    if (!$ids) return 0;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT id, channel_id FROM messages WHERE direction = 'in' AND delivered_at IS NULL
                           AND (answered_by = 'platform' OR answered_by LIKE 'platform-busy:%') AND channel_id IN ($in)");
    $st->execute($ids);
    $n = 0; $rung = [];
    foreach ($st->fetchAll() as $m) {
        db()->prepare("UPDATE messages SET answered_by = '' WHERE id = ?")->execute([$m['id']]);
        $rung[(int) $m['channel_id']] = true; $n++;
    }
    foreach (array_keys($rung) as $cid) ringBell($cid);
    if ($n) logEvent(null, 'platform.handover', "user:$userId", true, "$n — $why");
    return $n;
}

/** "נסה שוב": קריאת בדיקה אחת על השרשרת של המשתמש. מחזיר את מצב המפסק אחריה. */
function platformRetry(array $user, ?callable $transport = null): array {
    $uid = (int) $user['id'];
    if (!platformBreaker($uid)['open']) return platformBreaker($uid);
    $chain = aiChain($user);
    if (!$chain) throw new AppError('אין חיבור לספק בינה — הגדרות ← חיבורים ← "+ חיבור"');
    try {
        $conn = $chain[0] + ['chain' => $chain, 'user' => $user, 'usage' => ['kind' => 'answer', 'enforce' => false]];
        aiComplete($conn, 'Reply with the single word: ok', 'ping', 16, $transport);
        breakerReset($uid);
    } catch (Throwable $e) {
        $class = $e instanceof AppError ? $e->aiClass : '';
        if ($class !== '' && $class !== 'bad_request') breakerTrip($uid, $class, $e->getMessage(), (string) ($chain[0]['provider'] ?? ''));
        return platformBreaker($uid) + ['error' => $e->getMessage()];
    }
    return platformBreaker($uid);
}
