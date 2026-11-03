<?php
/**
 * שני מסלולים, שיחה אחת — docs/spec.md §15. שלב 15: Platform נעצר (§15.3).
 *
 * מפסק לכל משלם (platform_state): כששרשרת החיבורים שלו נכשלת כולה בכשל שאינו "בקשה שגויה" — המפסק נפתח.
 * מה קורה אז — ההגדרה של המשתמש (בעל הלוח, 2026-11-03), לא כלל קבוע:
 *   on_stop  auto — עובר מיד לשיטה השנייה · ask (ברירת מחדל) — שאלה בלוח, וממתין עד שעונים · wait — ממתין.
 *   preferred chat | platform — השיטה המועדפת: שיח חדש נפתח בה.
 *   return_preferred yes — גם שיח באמצע עובר לשיטה המועדפת כשהיא זמינה.
 * אותן הגדרות לשני הכיוונים. בשלב 15 — כש-Platform נעצר; צ'אט שנעצר מזוהה בשלב 16.
 *
 * ניסיון חוזר: עומס — אחרי 5 דק', תקלה — אחרי 30 דק'. קרדיט / מפתח — לא לבד: רק כשהמשתמש מתקן חיבור,
 * או לוחץ "נסה שוב" (קריאת בדיקה אחת). הצ'אט שומע על זה פעם אחת בפתיחה ופעם אחת בחזרה — לא על כל הודעה.
 */
declare(strict_types=1);

/** ‏0 = רק ידנית ("נסה שוב" / שינוי בחיבור). */
const BREAKER_RETRY = ['rate_limited' => 300, 'down' => 1800, 'credit_exhausted' => 0, 'key_rejected' => 0];
const LANE_PREFS_DEFAULT = ['preferred' => '', 'on_stop' => 'ask', 'return_preferred' => 'no', 'silence_minutes' => '10'];
const LANE_PREFS_VALUES = ['preferred' => ['', 'chat', 'platform'], 'on_stop' => ['auto', 'ask', 'wait'],
                           'return_preferred' => ['yes', 'no'], 'silence_minutes' => ['5', '10', '20', '30']];

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
    // ההחלטה של המשתמש באפיזודה הזו, כשההגדרה היא "לשאול": '' — עוד לא ענה · handover · wait
    addColumn($pdo, 'platform_state', 'decision', "TEXT NOT NULL DEFAULT ''");
}

/* ── ההגדרה של המשתמש (§15.11) ───────────────────────────── */

function lanePrefs(int $userId): array {
    $st = db()->prepare('SELECT lane_prefs FROM users WHERE id = ?');
    $st->execute([$userId]);
    $p = json_decode((string) $st->fetchColumn(), true);
    $out = LANE_PREFS_DEFAULT;
    $p = is_array($p) ? $p : [];
    // הגרסה הראשונה של שלב 15 שמרה platform_stop — handover = אוטומטי, wait = ממתין
    if (isset($p['platform_stop']) && !isset($p['on_stop'])) $p['on_stop'] = $p['platform_stop'] === 'handover' ? 'auto' : 'wait';
    foreach ($p as $k => $v) {
        if (is_int($v)) $v = (string) $v;
        if (isset(LANE_PREFS_VALUES[$k]) && in_array($v, LANE_PREFS_VALUES[$k], true)) $out[$k] = $v;
    }
    return $out;
}

/** כל משתמש — רק את ההגדרה של עצמו. נאכף כאן, לא רק בממשק. */
function setLanePrefs(array $user, array $in): array {
    $cur = lanePrefs((int) $user['id']);
    unset($cur['platform_stop']);
    foreach ($in as $k => $v) {
        if (!isset(LANE_PREFS_VALUES[$k])) continue;
        if (is_int($v)) $v = (string) $v;
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
    $onStop = lanePrefs($userId)['on_stop'];
    $decision = (string) ($r['decision'] ?? '');
    return ['state' => (string) $r['state'], 'since' => (int) $r['since'], 'retry_at' => $retry, 'reason' => (string) $r['reason'],
            'open' => $open, 'manual' => $open && BREAKER_RETRY[$r['state']] === 0 && $retry === 0,
            // הגיע הזמן לנסות שוב: ההודעה הבאה עוברת ל-Platform כבדיקה (half-open)
            'probe_due' => $open && $retry > 0 && nowTs() >= $retry,
            'on_stop' => $onStop,
            'handover' => breakerDecisionFor($onStop, $decision) === 'handover',
            // "לשאול": המשתמש עוד לא החליט — כרטיס בלוח, וההודעות ממתינות
            'ask_pending' => $open && $onStop === 'ask' && $decision === ''];
}

/** handover | wait — לפי ההגדרה, ובהגדרה "לשאול" — לפי מה שהמשתמש ענה ('' = עוד לא → ממתין). */
function breakerDecisionFor(string $onStop, string $decision): string {
    if ($onStop === 'auto') return 'handover';
    if ($onStop === 'ask') return $decision === 'handover' ? 'handover' : 'wait';
    return 'wait';
}

/** פתוח ועוד לא הגיע זמן לנסות — Platform לא נקרא. */
function breakerBlocks(int $userId): bool {
    $b = platformBreaker($userId);
    return $b['open'] && !$b['probe_due'];
}

/** ‏☁️ מחושב כ-💬: המפסק חוסם, והמשתמש בחר להעביר (lib/routes.php, lib/usage.php platformAvailable). */
function platformHandsOver(int $userId): bool {
    return breakerBlocks($userId) && platformBreaker($userId)['handover'];
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
                     decision = CASE WHEN platform_state.state = \'ok\' THEN \'\' ELSE platform_state.decision END,
                     reason = excluded.reason, provider = excluded.provider,
                     since = CASE WHEN platform_state.state = \'ok\' THEN excluded.since ELSE platform_state.since END')
        ->execute([$userId, $class, nowTs(), $retry, mb_substr($reason, 0, 500), $provider]);
    logEvent(null, 'platform.breaker', "user:$userId", false, "$class — " . mb_substr($reason, 0, 300));
    if ($before['open']) return false;
    $onStop = lanePrefs($userId)['on_stop'];
    $short = breakerShort($class, $reason);
    $text = match ($onStop) {
        'auto'  => "☁️ Platform נעצר — $short. מכאן הערוץ אצלך.",
        'ask'   => "☁️ Platform נעצר — $short. ההודעות ממתינות להחלטה של בעל החשבון בלוח: לעבור לצ'אט או להמתין.",
        default => "☁️ Platform נעצר — $short. לפי ההגדרה של בעל החשבון ההודעות ממתינות עד שיחזור.",
    };
    // שלב 17: שורה אחת ביומן ואחת בשיחה, לכל ערוץ
    foreach (platformChannels($userId) as $ch) laneLogAdd((int) $ch['id'], 'platform', $onStop === 'auto' ? 'chat' : 'platform',
                                                          laneReasonForBreaker($class), $text, null, $onStop);
    if ($onStop === 'auto') handOverWaiting($userId, $short);
    return true;
}

/**
 * התשובה לשאלה "לעבור לצ'אט?" (ההגדרה "לשאול"): handover — מה שממתין עובר עכשיו, והחדשות ישר לצ'אט,
 * עד ש-Platform חוזר; wait — ממתינות. רק המשלם עצמו, ורק כשהמפסק פתוח.
 */
function breakerDecide(array $user, string $decision): array {
    if (!in_array($decision, ['handover', 'wait'], true)) throw new InvalidArgumentException('decision: handover או wait');
    $uid = (int) $user['id'];
    $b = platformBreaker($uid);
    if (!$b['open']) throw new AppError('☁️ Platform עובד — אין מה להחליט', 409);
    db()->prepare('UPDATE platform_state SET decision = ? WHERE user_id = ?')->execute([$decision, $uid]);
    logEvent($user, 'lanes.decide', "user:$uid", true, $decision);
    if ($decision === 'handover' && $b['on_stop'] === 'ask') {
        $short = breakerShort($b['state'], $b['reason']);
        foreach (platformChannels($uid) as $ch) {
            laneLogAdd((int) $ch['id'], 'platform', 'chat', laneReasonForBreaker($b['state']),
                       "☁️ בעל החשבון החליט: עוברים לצ'אט עד ש-Platform יחזור ($short). מכאן הערוץ אצלך.", $uid, 'ask');
        }
        handOverWaiting($uid, $short);
    }
    return platformBreaker($uid);
}

/** סגירה (בדיקה שהצליחה). הודעה אחת לכל ערוץ: חזר. */
function breakerReset(int $userId): bool {
    $st = db()->prepare("UPDATE platform_state SET state = 'ok', retry_at = 0, reason = '', decision = '' WHERE user_id = ? AND state != 'ok'");
    $st->execute([$userId]);
    if ($st->rowCount() === 0) return false;
    logEvent(null, 'platform.breaker', "user:$userId", true, 'recovered');
    foreach (platformChannels($userId) as $ch) {
        laneLogAdd((int) $ch['id'], 'chat', 'platform', 'recovered', '☁️ Platform חזר — הערוץ שוב עונה ב-☁️.');
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

/* ── השיטה המועדפת ─────────────────────────────────────── */

/** השיטה זמינה למשלם? ☁️ — מכסה ומפסק סגור. 💬 — כן (צ'אט שנעצר — לכל ערוץ לחוד, channelRoute / lib/silence.php). */
function laneAvailable(string $lane, int $userId): bool {
    if ($lane === 'platform') return platformAvailable($userId) && !breakerBlocks($userId);
    return $lane === 'chat';
}

/** שיח חדש נפתח בשיטה המועדפת של המשלם ('' — לפי הפרויקט). */
function preferredLane(?int $projectId): string {
    $payer = $projectId ? projectPayer($projectId) : null;
    return $payer ? lanePrefs($payer)['preferred'] : '';
}

/**
 * המסלול של שיח אחרי ההגדרות של המשלם: "לחזור לשיטה המועדפת" — גם באמצע, כשהיא זמינה. ואחר כך המפסק:
 * ☁️ שנעצר עובר ל-💬 רק אם המשתמש בחר (אוטומטי, או ענה "לעבור").
 */
function laneResolve(string $route, ?int $projectId): string {
    $payer = $projectId ? projectPayer($projectId) : null;
    if (!$payer) return $route;
    $p = lanePrefs($payer);
    if ($p['return_preferred'] === 'yes' && $p['preferred'] !== '' && $p['preferred'] !== $route && laneAvailable($p['preferred'], $payer)) {
        $route = $p['preferred'];
    }
    if ($route === 'platform' && platformHandsOver($payer)) $route = 'chat';
    return $route;
}
