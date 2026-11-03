<?php
/**
 * שלב 16 (docs/spec.md §15.4, §15.5, §15.12.1) — הצ'אט לא עונה.
 *
 * שני סימנים שהשרת רואה:
 *   שתיקה  — הודעה במסלול הצ'אט שלא סומנה (ack) silence_minutes דקות, והמאזין לא פעיל.
 *   מגבלה  — ה-hook של StopFailure (שלב 0: הוא מקבל error = rate_limit / billing_error / …), דרך channel-limit.
 * אז — ההגדרה של המשלם "כשהשיטה הנוכחית נעצרת" (§15.11, אותה הגדרה כמו כש-Platform נעצר):
 *   auto — עוברים ל-☁️ (בתוך התקרה והמכסה, ורק כשהמפסק סגור) · ask — כרטיס אחד לאפיזודה · wait — שורה אפורה אחת.
 * אפיזודה = מ-unresponsive_since ועד שהצ'אט חוזר (register / health / pending). אין cron: בודקים בפתיחת הלוח
 * ובשליחת הודעה. אף שלב כאן לא קורא למודל — רק התשובות של Platform עצמן (שהלוח מפעיל, lane-run).
 *
 * channel_state — "מה קרה בלעדיך" לכל ערוץ, נכתב באותה טרנזקציה של האירוע שמשנה אותו, אף פעם לא על ידי מודל.
 * ממנו בנוי התקציר (channel-digest) שהצ'אט מקבל כשהוא חוזר, לפני ההודעות.
 */
declare(strict_types=1);

const SILENCE_MINUTES_VALUES = ['5', '10', '20', '30'];
const PLATFORM_LEASE_SECONDS = 600;
/** הערכה לתשובה אחת של Platform — מעבר אוטומטי שיעבור את התקרה הקרובה הופך לכרטיס. */
const AUTO_USE_ESTIMATE = 0.05;
const DIGEST_MAX = 1500;
const DIGEST_LAST_ANSWER = 300;
/** ה-error של StopFailure שפירושו "הצ'אט נעצר" (ולא תקלה רגעית). */
const LIMIT_ERRORS = ['rate_limit', 'billing_error', 'account_on_hold', 'authentication_failed', 'oauth_org_not_allowed'];

function migrateSilence(PDO $pdo): void {
    addColumn($pdo, 'channels', 'unresponsive_since', 'INTEGER NOT NULL DEFAULT 0');
    addColumn($pdo, 'channels', 'unresponsive_reason', "TEXT NOT NULL DEFAULT ''");   // silence | limit
    addColumn($pdo, 'channels', 'limit_status', "TEXT NOT NULL DEFAULT ''");          // ה-error של StopFailure
    addColumn($pdo, 'channels', 'limit_resets_at', 'INTEGER NOT NULL DEFAULT 0');
    addColumn($pdo, 'channels', 'silence_card_id', 'INTEGER');
    addColumn($pdo, 'channels', 'lane_decision', "TEXT NOT NULL DEFAULT ''");          // '' | platform | wait
    addColumn($pdo, 'messages', 'handled_by', "TEXT NOT NULL DEFAULT ''");              // chat | platform | engine
    addColumn($pdo, 'messages', 'lease_until', 'INTEGER NOT NULL DEFAULT 0');
    // התקרה החודשית למעברים אוטומטיים (§15.11a) — חלק מ-quota_usd. ההגדרה עצמה בשלב 18; 0 = אין תקרה משלה
    addColumn($pdo, 'users', 'auto_ceiling_usd', 'REAL NOT NULL DEFAULT 0');
    addColumn($pdo, 'projects', 'auto_ceiling_usd', 'REAL NOT NULL DEFAULT 0');
    addColumn($pdo, 'usage', 'auto', 'INTEGER NOT NULL DEFAULT 0');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS channel_state (
            channel_id          INTEGER PRIMARY KEY,
            lane                TEXT NOT NULL DEFAULT 'chat',
            since               INTEGER NOT NULL DEFAULT 0,
            counts_json         TEXT NOT NULL DEFAULT '{}',
            waiting_json        TEXT NOT NULL DEFAULT '[]',
            last_by_thread_json TEXT NOT NULL DEFAULT '[]',
            updated_at          INTEGER NOT NULL DEFAULT 0
        );
    ");
}

/* ── טרנזקציה אחת: האירוע והמצב ──────────────────────────── */

/** מריץ בתוך טרנזקציה — או בתוך זו שכבר פתוחה (SQLite לא מקנן). */
function inTx(callable $fn) {
    $pdo = db();
    if ($pdo->inTransaction()) return $fn();
    $pdo->beginTransaction();
    try { $r = $fn(); $pdo->commit(); return $r; }
    catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

/** מאז מתי "בלעדיך": האפיזודה, או ה-ack האחרון של הצ'אט. */
function channelStateSince(array $c): int {
    if ((int) ($c['unresponsive_since'] ?? 0) > 0) return (int) $c['unresponsive_since'];
    // ה-ack האחרון של הצ'אט עצמו. גם הודעות מלפני שלב 16 (handled_by ריק) — אחרת התקציר הראשון אחרי העדכון היה סופר
    // את כל ההיסטוריה. מה ש-Platform ענה (answered_by platform…) מסומן delivered בלי שהצ'אט קרא — לא נחשב
    $st = db()->prepare("SELECT MAX(delivered_at) FROM messages WHERE channel_id = ? AND direction = 'in'
                           AND handled_by IN ('chat', '') AND answered_by NOT LIKE 'platform%' AND delivered_at IS NOT NULL");
    $st->execute([$c['id']]);
    return (int) ($st->fetchColumn() ?: 0);
}

/**
 * מחשב מחדש את המצב מהמסד עצמו (מקור האמת), ושומר. נקרא בתוך הטרנזקציה של האירוע — כך אי אפשר שיהיו
 * הודעה בלי מצב, או מצב בלי הודעה.
 */
function channelStateRefresh(int $channelId): void {
    if (!empty($GLOBALS['CT_STATE_FAIL'])) throw new RuntimeException('channel_state: כשל מדומה (בדיקה)');
    $c = getChannel($channelId);
    if (!$c) return;
    $since = channelStateSince($c);
    $q = fn (string $sql, array $a = []) => (function () use ($sql, $a) { $s = db()->prepare($sql); $s->execute($a); return $s; })();
    $counts = [
        'platform_answered' => (int) $q("SELECT COUNT(*) FROM messages WHERE channel_id = ? AND author_role = 'platform' AND kind = 'answer' AND created_at >= ?", [$channelId, $since])->fetchColumn(),
        'platform_asked'    => (int) $q("SELECT COUNT(*) FROM messages WHERE channel_id = ? AND author_role = 'platform' AND kind = 'choice' AND status = 'open'", [$channelId])->fetchColumn(),
        'engine_done'       => (int) $q("SELECT COUNT(*) FROM tasks WHERE asked_channel_id = ? AND status = 'done' AND updated_at >= ?", [$channelId, $since])->fetchColumn(),
        'owner_waiting'     => (int) $q("SELECT COUNT(*) FROM messages WHERE channel_id = ? AND kind IN ('choice', 'lane_card') AND status = 'open'", [$channelId])->fetchColumn(),
        'permissions_decided' => (int) $q("SELECT COUNT(*) FROM messages WHERE channel_id = ? AND kind = 'permission' AND status <> 'open' AND created_at >= ?", [$channelId, $since])->fetchColumn(),
    ];
    $waiting = array_map('intval', $q("SELECT id FROM messages WHERE channel_id = ? AND direction = 'in' AND delivered_at IS NULL
                                        AND answered_by NOT LIKE 'platform%' AND kind <> 'status' ORDER BY id LIMIT 50", [$channelId])->fetchAll(PDO::FETCH_COLUMN));
    // התשובה האחרונה לכל שרשור (reply_to) — מה שנענה בלי הצ'אט
    $last = [];
    foreach ($q("SELECT id, reply_to, body FROM messages WHERE channel_id = ? AND author_role = 'platform' AND kind = 'answer' AND created_at >= ?
                  ORDER BY id DESC LIMIT 40", [$channelId, $since])->fetchAll() as $m) {
        $t = (int) ($m['reply_to'] ?? 0);
        if (isset($last[$t])) continue;
        $last[$t] = ['thread' => $t, 'id' => (int) $m['id'], 'text' => mb_substr((string) $m['body'], 0, DIGEST_LAST_ANSWER)];
    }
    $lane = channelRoute($c);
    db()->prepare('INSERT INTO channel_state (channel_id, lane, since, counts_json, waiting_json, last_by_thread_json, updated_at)
                   VALUES (?,?,?,?,?,?,?) ON CONFLICT(channel_id) DO UPDATE SET lane = excluded.lane, since = excluded.since,
                   counts_json = excluded.counts_json, waiting_json = excluded.waiting_json,
                   last_by_thread_json = excluded.last_by_thread_json, updated_at = excluded.updated_at')
        ->execute([$channelId, $lane, $since, json_encode($counts), json_encode($waiting), json_encode(array_values($last), JSON_UNESCAPED_UNICODE), nowTs()]);
}

function channelState(int $channelId): ?array {
    $st = db()->prepare('SELECT * FROM channel_state WHERE channel_id = ?');
    $st->execute([$channelId]);
    $r = $st->fetch();
    if (!$r) return null;
    return ['lane' => $r['lane'], 'since' => (int) $r['since'], 'counts' => json_decode($r['counts_json'], true) ?: [],
            'waiting' => json_decode($r['waiting_json'], true) ?: [], 'last_by_thread' => json_decode($r['last_by_thread_json'], true) ?: [],
            'updated_at' => (int) $r['updated_at']];
}

/**
 * "מה קרה בלעדיך" — ≤ 1,500 תווים, בלי מודל. '' כשלא קרה כלום במסלול אחר.
 */
function channelDigest(array $channel): string {
    $cid = (int) $channel['id'];
    channelStateRefresh($cid);
    $s = channelState($cid);
    $c = $s['counts'];
    $parts = [];
    if ($c['platform_answered'] ?? 0) $parts[] = "☁️ Platform ענה על {$c['platform_answered']} הודעות";
    if ($c['engine_done'] ?? 0) $parts[] = "🤖 המנוע סגר {$c['engine_done']} מטלות";
    if ($c['owner_waiting'] ?? 0) $parts[] = "❓ ממתינות לבעל הלוח: {$c['owner_waiting']}";
    if ($c['permissions_decided'] ?? 0) $parts[] = "🔐 הוחלט על {$c['permissions_decided']} בקשות";
    if (!$parts) return '';
    $out = "📋 מה קרה בלעדיך" . ($s['since'] ? ' (מאז ' . gmdate('H:i', $s['since']) . ' UTC)' : '') . ":\n" . implode(' · ', $parts);
    if ($s['waiting']) $out .= "\nממתינות לך: " . count($s['waiting']) . ' (מטה)';
    $more = 0;
    foreach ($s['last_by_thread'] as $t) {
        $line = "\n— #{$t['id']}" . ($t['thread'] ? " (על #{$t['thread']})" : '') . ': ' . preg_replace('/\s+/u', ' ', $t['text']);
        if (mb_strlen($out . $line) > DIGEST_MAX - 80) { $more++; continue; }
        $out .= $line;
    }
    if ($more) $out .= "\nועוד $more — link.sh show <id>";
    $out .= "\nמה ש-Platform כבר ענה — לא מטפלים שוב. הטקסט המלא: link.sh show <id>";
    return mb_substr($out, 0, DIGEST_MAX);
}

/* ── התקרה (§15.11a) — הקרובה מבין: הפרויקט, ההורים, המשתמש; ותמיד בתוך המכסה ── */

function autoSpent(int $userId, ?array $projectIds = null): float {
    $sql = 'SELECT COALESCE(SUM(cost_usd),0) FROM usage WHERE user_id = ? AND auto = 1 AND at >= ?';
    $a = [$userId, monthStart()];
    if ($projectIds !== null) {
        if (!$projectIds) return 0.0;
        $sql .= ' AND project_id IN (' . implode(',', array_fill(0, count($projectIds), '?')) . ')';
        $a = array_merge($a, $projectIds);
    }
    $st = db()->prepare($sql);
    $st->execute($a);
    return (float) $st->fetchColumn();
}

/**
 * כמה נשאר למעברים אוטומטיים: ‏left, ומי חוסם (שם הפרויקט / "התקרה שלך" / "המכסה החודשית").
 * §15.11: בלי שום תקרה (לא של המשתמש ולא בשרשרת הפרויקטים) — אין מעבר אוטומטי שעולה כסף: left = 0.
 */
function autoCeilingLeft(int $payer, int $projectId): array {
    $q = quotaState($payer);
    $best = ['left' => $q['state'] === 'off' ? 0.0 : (float) $q['left'], 'by' => 'המכסה החודשית', 'ceiling' => (float) $q['quota']];
    $any = false;
    $u = db()->prepare('SELECT auto_ceiling_usd FROM users WHERE id = ?');
    $u->execute([$payer]);
    if (($uc = (float) $u->fetchColumn()) > 0) {
        $any = true;
        $left = max(0.0, $uc - autoSpent($payer));
        if ($left < $best['left']) $best = ['left' => $left, 'by' => 'התקרה שלך', 'ceiling' => $uc];
    }
    $id = $projectId;
    $st = db()->prepare('SELECT id, name, parent_id, auto_ceiling_usd FROM projects WHERE id = ?');
    for ($i = 0; $id && $i < 20; $i++) {
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) break;
        if (($pc = (float) $p['auto_ceiling_usd']) > 0) {
            $any = true;
            $left = max(0.0, $pc - autoSpent($payer, projectSubtree((int) $p['id'])));
            if ($left < $best['left']) $best = ['left' => $left, 'by' => (string) $p['name'], 'ceiling' => $pc];
        }
        $id = (int) ($p['parent_id'] ?? 0);
    }
    if (!$any) return ['left' => 0.0, 'by' => 'לא הוגדרה תקרה', 'ceiling' => 0.0, 'none' => true];
    $best['left'] = round($best['left'], 4);
    return $best;
}

/** האם Platform יכול לקחת עכשיו: ‏ok, ואם לא — למה (בעברית). ‏$auto — מעבר אוטומטי (התקרה חלה רק עליו). */
function platformCanTakeOver(int $payer, int $projectId, bool $auto): array {
    if (breakerBlocks($payer)) {
        $b = platformBreaker($payer);
        return ['ok' => false, 'why' => '☁️ Platform נעצר גם הוא — ' . breakerShort($b['state'], $b['reason']) . '. אי אפשר להעביר אליו.'];
    }
    $q = quotaState($payer);
    if ($q['state'] === 'off') return ['ok' => false, 'why' => '☁️ Platform כבוי לחשבון הזה — לא הוגדרה מכסה חודשית.'];
    if ($q['state'] === 'over') return ['ok' => false, 'why' => 'המכסה נגמרה — Platform לא יכול לענות עד שהיא מתחדשת.'];
    if (!aiChain(getUserById($payer) ?? ['id' => $payer])) return ['ok' => false, 'why' => 'למשלם אין חיבור לספק בינה.'];
    if ($auto) {
        $c = autoCeilingLeft($payer, $projectId);
        if (!empty($c['none'])) return ['ok' => false, 'why' => 'לא הוגדרה תקרה חודשית למעברים אוטומטיים — עד שמגדירים (הגדרות ← 🔀 מסלולים), שואלים.', 'ceiling' => true];
        if ($c['left'] < AUTO_USE_ESTIMATE) return ['ok' => false, 'why' => "מעבר אוטומטי היה עובר את התקרה ({$c['by']}) — לכן שואלים.", 'ceiling' => true];
    }
    return ['ok' => true, 'why' => ''];
}

function getUserById(int $id): ?array {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/* ── האפיזודה ─────────────────────────────────────────────── */

function silenceMinutes(int $payer, ?int $projectId = null): int {
    $m = (string) (laneEffectivePrefs($payer, $projectId)['silence_minutes'] ?? '10');
    return in_array($m, SILENCE_MINUTES_VALUES, true) ? (int) $m : 10;
}

/** ההודעות שממתינות לצ'אט (לא status, לא של Platform). */
function chatWaiting(int $channelId): array {
    $st = db()->prepare("SELECT id, created_at FROM messages WHERE channel_id = ? AND direction = 'in' AND delivered_at IS NULL
                           AND answered_by NOT LIKE 'platform%' AND kind <> 'status' ORDER BY id");
    $st->execute([$channelId]);
    return $st->fetchAll();
}

/**
 * הגלאי (עצל): נקרא בפתיחת הלוח (channels) ובשליחת הודעה. מחזיר מה נעשה: '' | card | moved | waiting.
 * ‏$force — סימן המגבלה: לא מחכים לדקות השתיקה.
 */
function silenceCheck(array $channel, bool $force = false): string {
    if (($channel['status'] ?? '') !== 'active' || (int) ($channel['unresponsive_since'] ?? 0) > 0) return '';
    $pid = (int) $channel['project_id'];
    $payer = projectPayer($pid);
    if (!$payer) return '';
    if (channelRoute($channel) !== 'chat') return '';
    $waiting = chatWaiting((int) $channel['id']);
    if (!$force) {
        if (!$waiting) return '';
        if ((int) $channel['listening_until'] > nowTs()) return '';
        if ((int) $waiting[0]['created_at'] > nowTs() - silenceMinutes($payer, $pid) * 60) return '';
    }
    return inTx(function () use ($channel, $payer, $pid, $force, $waiting) {
        $cid = (int) $channel['id'];
        // תפיסה: רק בקשה אחת פותחת את האפיזודה
        $st = db()->prepare("UPDATE channels SET unresponsive_since = ?, unresponsive_reason = ?, lane_decision = '' WHERE id = ? AND unresponsive_since = 0");
        $st->execute([nowTs(), $force ? 'limit' : 'silence', $cid]);
        if ($st->rowCount() === 0) return '';
        $onStop = laneEffectivePrefs($payer, $pid)['on_stop'];
        $why = $force ? 'הצ\'אט נעצר — ' . limitText((string) ($channel['limit_status'] ?? '')) : 'הצ\'אט לא עונה ' . silenceMinutes($payer, $pid) . ' דק\'';
        $res = 'waiting';
        if ($onStop === 'auto') {
            $can = platformCanTakeOver($payer, $pid, true);
            if ($can['ok']) {
                $n = laneMoveToPlatform($cid);
                laneLogAdd($cid, 'chat', 'platform', $force ? 'chat_limit' : 'chat_silent',
                           "💬 $why — ☁️ Platform עונה במקומו עד שיחזור (ההגדרה: אוטומטי). הועברו: $n.", null, 'auto', $n);
                $res = 'moved';
            } else {
                laneCardPost($channel, $waiting, $why, $can['why']);
                // מעבר אוטומטי שנעצר בתקרה / במכסה — נרשם (cap_reached), והכרטיס שואל
                if (!empty($can['ceiling']) || str_contains($can['why'], 'המכסה')) {
                    laneLogAdd($cid, 'chat', 'chat', 'cap_reached', "💬 $why — מעבר אוטומטי ל-☁️ נעצר: {$can['why']} ההחלטה בכרטיס.", null, 'auto');
                }
                $res = 'card';
            }
        } elseif ($onStop === 'ask') {
            $can = platformCanTakeOver($payer, $pid, false);
            laneCardPost($channel, $waiting, $why, $can['ok'] ? '' : $can['why']);
            $res = 'card';
        } else {
            channelInsertIn($cid, 'system', null, "💬 $why — ההודעות ממתינות לו (ההגדרה: להמתין)." . limitResetLine(getChannel($cid)), 'status', null, null, false);
        }
        channelStateRefresh($cid);
        logEvent(null, 'lanes.silence', "channel:$cid", true, "$res — $why");
        return $res;
    });
}

function limitText(string $error): string {
    return ['rate_limit' => 'הגיע למגבלת השימוש', 'billing_error' => 'בעיית חיוב בחשבון', 'account_on_hold' => 'החשבון מושהה',
            'authentication_failed' => 'ההתחברות שלו נכשלה', 'oauth_org_not_allowed' => 'הארגון לא מורשה'][$error] ?? 'נעצר';
}

function limitResetLine(?array $c): string {
    $at = (int) ($c['limit_resets_at'] ?? 0);
    return $at > nowTs() ? ' המגבלה מתאפסת ב-' . gmdate('d.m H:i', $at) . ' UTC.' : '';
}

/** ההודעות שממתינות לצ'אט עוברות ל-☁️: answered_by=platform (הלוח עונה עליהן — lane-run), עם חכירה. */
function laneMoveToPlatform(int $channelId): int {
    db()->prepare("UPDATE channels SET lane_decision = 'platform' WHERE id = ?")->execute([$channelId]);
    $st = db()->prepare("UPDATE messages SET answered_by = 'platform', handled_by = 'platform', lease_until = ?
                          WHERE channel_id = ? AND direction = 'in' AND delivered_at IS NULL AND answered_by NOT LIKE 'platform%'
                            AND kind <> 'status' AND author_role <> 'system'");
    $st->execute([nowTs() + PLATFORM_LEASE_SECONDS, $channelId]);
    return $st->rowCount();
}

/** כרטיס אחד לאפיזודה (out, system, lane_card, open) — לבעל הלוח, לא לצ'אט. */
function laneCardPost(array $channel, array $waiting, string $why, string $blocked): int {
    $cid = (int) $channel['id'];
    $payer = (int) projectPayer((int) $channel['project_id']);
    $ceil = autoCeilingLeft($payer, (int) $channel['project_id']);
    $meta = ['waiting' => count($waiting), 'oldest_at' => $waiting ? (int) $waiting[0]['created_at'] : 0, 'why' => $why,
             'platform_ok' => $blocked === '', 'blocked' => $blocked, 'ceiling_left' => $ceil['left'], 'ceiling_by' => $ceil['by'],
             'resets_at' => (int) (getChannel($cid)['limit_resets_at'] ?? 0)];
    db()->prepare("INSERT INTO messages (channel_id, direction, author_role, kind, body, answered_by, meta, status, created_at)
                   VALUES (?, 'out', 'system', 'lane_card', ?, '', ?, 'open', ?)")
        ->execute([$cid, "💬 הצ'אט לא עונה — {$channel['name']}", json_encode($meta, JSON_UNESCAPED_UNICODE), nowTs()]);
    $id = (int) db()->lastInsertId();
    db()->prepare('UPDATE channels SET silence_card_id = ? WHERE id = ?')->execute([$id, $cid]);
    return $id;
}

/** התשובה לכרטיס: platform | wait. בעל הלוח בלבד. המכסה והמפסק נבדקים כאן (התקרה — רק למעבר אוטומטי). */
function laneCardAnswer(array $user, int $cardId, string $choice): array {
    if (authorRole($user) !== 'owner') throw new AppError('בעל הלוח בלבד', 403);
    if (!in_array($choice, ['platform', 'wait'], true)) throw new InvalidArgumentException('choice: platform או wait');
    $st = db()->prepare("SELECT * FROM messages WHERE id = ? AND kind = 'lane_card'");
    $st->execute([$cardId]);
    $card = $st->fetch();
    if (!$card) throw new AppError('הכרטיס לא נמצא', 404);
    $channel = getChannel((int) $card['channel_id']);
    requireProject((int) $channel['project_id'], $user, 'read');
    if ($card['status'] !== 'open') throw new AppError('הכרטיס כבר נסגר', 409);
    $cid = (int) $channel['id'];
    $payer = (int) projectPayer((int) $channel['project_id']);
    if ($choice === 'platform') {
        $can = platformCanTakeOver($payer, (int) $channel['project_id'], false);
        if (!$can['ok']) throw new AppError($can['why'], 409);
    }
    return inTx(function () use ($card, $cid, $choice, $user) {
        $meta = json_decode((string) $card['meta'], true) ?: [];
        $meta['decision'] = $choice;
        $meta['decided_by'] = (int) $user['id'];
        db()->prepare("UPDATE messages SET status = 'answered', meta = ? WHERE id = ?")->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $card['id']]);
        $moved = 0;
        if ($choice === 'platform') {
            $moved = laneMoveToPlatform($cid);
            laneLogAdd($cid, 'chat', 'platform', getChannel($cid)['unresponsive_reason'] === 'limit' ? 'chat_limit' : 'chat_silent',
                       "💬 בעל הלוח החליט: ☁️ Platform עונה עד שהצ'אט יחזור. הועברו: $moved.", (int) $user['id'], 'ask', $moved);
        } else {
            db()->prepare("UPDATE channels SET lane_decision = 'wait' WHERE id = ?")->execute([$cid]);
            channelInsertIn($cid, 'system', null, '💬 בעל הלוח החליט: מחכים לצ\'אט.' . limitResetLine(getChannel($cid)), 'status', null, null, false);
        }
        channelStateRefresh($cid);
        logEvent($user, 'lanes.card', "channel:$cid", true, "$choice ($moved)");
        return ['decision' => $choice, 'moved' => $moved, 'run' => $choice === 'platform' ? platformQueue($cid) : []];
    });
}

/** ההודעות שממתינות לתשובה של Platform בערוץ (הלוח עונה עליהן אחת אחת). */
function platformQueue(int $channelId): array {
    $st = db()->prepare("SELECT id FROM messages WHERE channel_id = ? AND direction = 'in' AND delivered_at IS NULL AND answered_by = 'platform' ORDER BY id");
    $st->execute([$channelId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** ‏lane-run: הלוח מבקש לענות על מה שעבר ל-☁️ בערוץ — כל הודעה דרך platform-reply (אחת אחת, עם המכסה). */
function laneRun(array $user, int $channelId, ?callable $transport = null): array {
    $c = getChannel($channelId);
    if (!$c) throw new AppError('הערוץ לא נמצא', 404);
    requireProject((int) $c['project_id'], $user, 'read');
    $out = [];
    foreach (array_slice(platformQueue($channelId), 0, 5) as $id) {
        try { $r = platformReply($user, $id, $transport); $out[] = ['id' => $id, 'answered' => (bool) ($r['answered'] ?? false)]; }
        catch (AppError $e) { $out[] = ['id' => $id, 'answered' => false, 'error' => $e->getMessage()]; }
    }
    return ['ran' => $out, 'left' => count(platformQueue($channelId))];
}

/**
 * הצ'אט חזר (register / health / pending): האפיזודה נסגרת, הכרטיס הפתוח נסגר, שורת system אחת.
 * "לחזור לשיטה המועדפת באמצע" (§15.11) — כן: הערוץ חוזר ל-💬; לא: נשאר ב-☁️ (בעל הלוח מחזיר ב-⚙ ← מי עונה).
 * מחזיר את התקציר ('' כשלא הייתה אפיזודה ולא קרה כלום).
 */
function laneReturn(array $channel): string {
    $cid = (int) $channel['id'];
    if ((int) ($channel['unresponsive_since'] ?? 0) === 0) return channelDigest($channel);
    return inTx(function () use ($channel, $cid) {
        $st = db()->prepare("UPDATE channels SET unresponsive_since = 0, unresponsive_reason = '', limit_status = '', limit_resets_at = 0
                              WHERE id = ? AND unresponsive_since > 0");
        $st->execute([$cid]);
        if ($st->rowCount() === 0) return channelDigest(getChannel($cid));
        $digest = channelDigest($channel);   // המצב של האפיזודה — לפני שה-since מתאפס
        db()->prepare("UPDATE messages SET status = 'resolved' WHERE channel_id = ? AND kind = 'lane_card' AND status = 'open'")->execute([$cid]);
        $payer = (int) projectPayer((int) $channel['project_id']);
        $p = laneEffectivePrefs($payer, (int) $channel['project_id']);
        $was = (string) $channel['lane_decision'];
        $back = $was !== 'platform' || ($p['return_preferred'] === 'yes' && $p['preferred'] !== 'platform');
        if ($back) db()->prepare("UPDATE channels SET lane_decision = '' WHERE id = ?")->execute([$cid]);
        $answered = (int) (channelState($cid)['counts']['platform_answered'] ?? 0);
        $text = '💬 הצ\'אט חזר' . ($answered ? " — Platform ענה בינתיים על $answered הודעות, והצ'אט קיבל תקציר" : '')
              . ($was === 'platform' ? ($back ? '. הערוץ חזר ל-💬.' : '. הערוץ נשאר ב-☁️ (ההגדרה: לא לעבור באמצע שיחה) — אפשר להחזיר ב-⚙ ← מי עונה.') : '.');
        // חזרה ל-💬 היא מעבר (יומן + שורה); נשאר ב-☁️ או לא היה מעבר — רק השורה
        if ($was === 'platform' && $back) laneLogAdd($cid, 'platform', 'chat', 'recovered', $text, null, 'return_preferred');
        else channelInsertIn($cid, 'system', null, $text, 'status', null, null, false);
        channelStateRefresh($cid);
        logEvent(null, 'lanes.return', "channel:$cid", true, $back ? 'chat' : 'platform');
        return $digest;
    });
}

/**
 * ה-hook של StopFailure: הצ'אט נעצר בגלל מגבלה / חיוב (לא בגלל תקלה רגעית). פותח אפיזודה מיד.
 * ‏details — טקסט חופשי; אם יש בו זמן איפוס (ISO או unix) — נשמר ומוצג.
 */
function channelLimit(array $channel, string $error, string $details = ''): array {
    if (!in_array($error, LIMIT_ERRORS, true)) return ['recorded' => false];
    $resets = 0;
    if (preg_match('/\b(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?)/', $details, $m)) $resets = (int) strtotime($m[1]);
    elseif (preg_match('/\b(1[7-9]\d{8})\b/', $details, $m)) $resets = (int) $m[1];
    db()->prepare('UPDATE channels SET limit_status = ?, limit_resets_at = ?, listening_until = 0 WHERE id = ?')
        ->execute([$error, $resets > nowTs() ? $resets : 0, $channel['id']]);
    $action = silenceCheck(getChannel((int) $channel['id']), true);
    return ['recorded' => true, 'action' => $action];
}

/** ‏channel-ack מפורט: מה שכבר טופל במסלול אחר (Platform) — already_handled, ולא מסומן שוב. */
function channelAckDetailed(array $channel, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return ['acked' => 0, 'already_handled' => []];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT id FROM messages WHERE channel_id = ? AND id IN ($in) AND (handled_by IN ('platform', 'engine') OR answered_by LIKE 'platform%')");
    $st->execute(array_merge([$channel['id']], $ids));
    $handled = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $rest = array_values(array_diff($ids, $handled));
    $n = channelAck($channel, $rest);
    return ['acked' => $n, 'already_handled' => $handled];
}

/** ‏link.sh show <id>: הטקסט המלא של הודעה בערוץ (לתקציר). */
function channelShow(array $channel, int $id): array {
    $rows = messageRows('m.id = ? AND m.channel_id = ?', [$id, $channel['id']]);
    if (!$rows) throw new AppError('ההודעה לא נמצאה בערוץ', 404);
    $m = $rows[0];
    return ['id' => $m['id'], 'author_role' => $m['author_role'], 'kind' => $m['kind'], 'reply_to' => $m['reply_to'],
            'created_at' => $m['created_at'], 'body' => $m['body']];
}

/* ── 📝 סיכום חכם (§15.5, החלטה 5) ─────────────────────────── */

const SUMMARY_PREFIX = '📝 סיכום חכם של הערוץ';

/** הסיכום האחרון, אם אין אחריו הודעות חדשות (חוץ מ-status) — כדי שלא ישולם פעמיים על אותו דבר. */
function latestSummary(int $channelId): ?array {
    $st = db()->prepare("SELECT * FROM messages WHERE channel_id = ? AND author_role = 'system' AND kind = 'answer' AND body LIKE ? ORDER BY id DESC LIMIT 1");
    $st->execute([$channelId, SUMMARY_PREFIX . '%']);
    return $st->fetch() ?: null;
}

/**
 * סיכום לפי דרישה של בעל הלוח: קריאה אחת למודל, על חשבון המשלם (kind answer), נבדק במכסה (100% → נדחה).
 * נשמר כהודעת system מוצמדת (לכולם). שוב בלי הודעות חדשות — אותו סיכום, בלי קריאה.
 */
function channelSummary(array $user, int $channelId, ?callable $transport = null): array {
    if (authorRole($user) !== 'owner') throw new AppError('סיכום חכם — בעל הלוח בלבד', 403);
    $c = getChannel($channelId);
    if (!$c) throw new AppError('הערוץ לא נמצא', 404);
    $project = requireProject((int) $c['project_id'], $user, 'read');
    if ($s = latestSummary($channelId)) {
        $newer = db()->prepare("SELECT COUNT(*) FROM messages WHERE channel_id = ? AND id > ? AND kind NOT IN ('status', 'lane_card')");
        $newer->execute([$channelId, $s['id']]);
        if ((int) $newer->fetchColumn() === 0) return ['message' => messageRows('m.id = ?', [(int) $s['id']])[0], 'cached' => true];
    }
    $payer = payerUser((int) $project['id']);
    if (!$payer) throw new AppError('אין משלם לענף', 409);
    requireQuota((int) $payer['id'], 'answer');
    $chain = aiChain($payer);
    if (!$chain) throw new AppError('למשלם אין חיבור לספק בינה');
    $rows = array_slice(messageRows("m.channel_id = ? AND m.kind NOT IN ('status', 'lane_card', 'handoff_request')", [$channelId], 500), -120);
    $who = ['owner' => 'בעל הלוח', 'chat' => 'הצ\'אט', 'system' => 'מערכת', 'platform' => 'Platform', 'member' => 'חבר צוות'];
    $log = implode("\n", array_map(fn ($m) => '[#' . $m['id'] . ' ' . ($who[$m['author_role']] ?? $m['author_role']) . '] '
                                              . mb_substr(preg_replace('/\s+/u', ' ', (string) $m['body']), 0, 600), $rows));
    $system = 'אתה מסכם שיחה בין בעל לוח מטלות לצ\'אט פיתוח. כתוב בעברית, קצר ומדויק: מצב, החלטות שהתקבלו, מה פתוח, '
            . 'מה הצעד הבא. אל תמציא דבר שלא כתוב בשיחה. עד 2,000 תווים, בלי הקדמה.';
    $conn = $chain[0] + ['chain' => $chain, 'user' => $payer,
                         'usage' => ['kind' => 'answer', 'project_id' => (int) $project['id'], 'channel_id' => $channelId, 'enforce' => false]];
    $text = trim(aiComplete($conn, $system, "השיחה (מהישנה לחדשה):\n" . $log, 1200, $transport));
    if ($text === '') throw new AppError('המודל החזיר סיכום ריק');
    $id = inTx(function () use ($channelId, $text, $user) {
        db()->prepare("INSERT INTO messages (channel_id, direction, author_role, kind, body, answered_by, created_at)
                       VALUES (?, 'out', 'system', 'answer', ?, '', ?)")
            ->execute([$channelId, cleanBody(SUMMARY_PREFIX . ' (' . gmdate('d.m H:i') . " UTC):\n\n" . mb_substr($text, 0, 4000)), nowTs()]);
        $id = (int) db()->lastInsertId();
        db()->prepare('INSERT OR IGNORE INTO message_pins (message_id, visibility, user_id, pinned_by, pinned_at) VALUES (?,?,?,?,?)')
            ->execute([$id, 'shared', 0, (int) $user['id'], nowTs()]);
        channelStateRefresh($channelId);
        return $id;
    });
    return ['message' => messageRows('m.id = ?', [$id])[0], 'cached' => false];
}

/** מה שהלוח מציג על הערוץ: לא עונה / מגבלה, הכרטיס הפתוח, וכמה ממתינות ל-Platform. */
function channelLaneInfo(array $c): array {
    $since = (int) ($c['unresponsive_since'] ?? 0);
    return ['unresponsive_since' => $since, 'unresponsive_reason' => (string) ($c['unresponsive_reason'] ?? ''),
            'limit_status' => (string) ($c['limit_status'] ?? ''), 'limit_resets_at' => (int) ($c['limit_resets_at'] ?? 0),
            'lane_decision' => (string) ($c['lane_decision'] ?? ''),
            'platform_queue' => $since > 0 || ($c['lane_decision'] ?? '') === 'platform' ? count(platformQueue((int) $c['id'])) : 0];
}
