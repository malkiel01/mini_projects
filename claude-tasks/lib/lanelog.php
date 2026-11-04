<?php
/**
 * שלב 17 (docs/spec.md §15.6, §15.12.2) — היומן, התגים, והמעבר הידני.
 *
 * כל מעבר בין מסלולים — שורה אחת ב-lane_log ושורת system אחת בשיחה (לא יותר: גם כשכמה דרכים מגיעות לאותו מעבר).
 * הסיבות: manual · platform_credit · platform_key · platform_rate · platform_down · chat_silent · chat_limit ·
 * recovered · cap_reached. התגים (💬 / ☁️ / 🤖) נגזרים מ-handled_by / answered_by / tasks.route — אף פעם לא מהטקסט.
 * מעבר לא משכתב היסטוריה, ולא מזיז מטלה שכבר in_progress.
 */
declare(strict_types=1);

const LANE_REASONS = ['manual', 'platform_credit', 'platform_key', 'platform_rate', 'platform_down', 'chat_silent', 'chat_limit',
                      'recovered', 'cap_reached'];
const LANE_ICON = ['chat' => '💬', 'platform' => '☁️', 'engine' => '🤖'];

function migrateLaneLog(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lane_log (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            at          INTEGER NOT NULL,
            channel_id  INTEGER NOT NULL,
            project_id  INTEGER NOT NULL,
            from_lane   TEXT NOT NULL,
            to_lane     TEXT NOT NULL,
            reason      TEXT NOT NULL,
            setting     TEXT NOT NULL DEFAULT '',
            by_user     INTEGER,
            moved       INTEGER NOT NULL DEFAULT 0,
            message_id  INTEGER
        );
        CREATE INDEX IF NOT EXISTS idx_lane_log_channel ON lane_log(channel_id, id);
    ");
}

/** סיבה של המפסק (§15.3) → סיבה ביומן. */
function laneReasonForBreaker(string $class): string {
    return ['credit_exhausted' => 'platform_credit', 'key_rejected' => 'platform_key', 'rate_limited' => 'platform_rate'][$class] ?? 'platform_down';
}

function laneReasonText(string $reason): string {
    return ['manual' => 'מעבר ידני', 'platform_credit' => 'הקרדיט של Platform נגמר', 'platform_key' => 'המפתח של Platform נדחה',
            'platform_rate' => 'הספק של Platform עמוס', 'platform_down' => 'הספק של Platform לא זמין', 'chat_silent' => 'הצ\'אט לא עונה',
            'chat_limit' => 'הצ\'אט הגיע למגבלה', 'recovered' => 'חזר', 'cap_reached' => 'התקרה / המכסה'][$reason] ?? $reason;
}

/**
 * רושם מעבר: שורה ב-lane_log, ושורת system אחת בשיחה. ‏$text — מה שכתוב בשיחה; '' — נבנה מהסיבה.
 * ‏$withLine=false — כשהקורא כבר כתב את השורה בשיחה (אז רק היומן, עם מזהה השורה).
 */
function laneLogAdd(int $channelId, string $from, string $to, string $reason, string $text = '', ?int $by = null,
                    string $setting = '', int $moved = 0, ?int $lineId = null): int {
    if (!in_array($reason, LANE_REASONS, true)) throw new InvalidArgumentException("סיבת מעבר לא מוכרת: $reason");
    $c = getChannel($channelId);
    if (!$c) return 0;
    if ($lineId === null) {
        // בעברית (מימין לשמאל) החץ ← מצביע מהמסלול הקודם (מימין) לחדש (משמאל)
        $text = $text !== '' ? $text : '🔀 ' . (LANE_ICON[$from] ?? $from) . ' ← ' . (LANE_ICON[$to] ?? $to) . ' · ' . laneReasonText($reason)
                                    . ($moved ? " · הועברו: $moved" : '');
        $lineId = channelInsertIn($channelId, 'system', null, $text, 'status', null, null, false);
    }
    db()->prepare('INSERT INTO lane_log (at, channel_id, project_id, from_lane, to_lane, reason, setting, by_user, moved, message_id)
                   VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([nowTs(), $channelId, (int) $c['project_id'], $from, $to, $reason, $setting, $by, $moved, $lineId]);
    return (int) db()->lastInsertId();
}

function laneLogFor(array $user, int $channelId, int $limit = 100): array {
    $c = getChannel($channelId);
    if (!$c) throw new AppError('הערוץ לא נמצא', 404);
    requireProject((int) $c['project_id'], $user, 'read');
    $st = db()->prepare('SELECT l.*, u.display_name by_name FROM lane_log l LEFT JOIN users u ON u.id = l.by_user
                          WHERE l.channel_id = ? ORDER BY l.id DESC LIMIT ' . max(1, min(500, $limit)));
    $st->execute([$channelId]);
    return array_map(fn ($r) => ['id' => (int) $r['id'], 'at' => (int) $r['at'], 'from' => $r['from_lane'], 'to' => $r['to_lane'],
        'reason' => $r['reason'], 'reason_text' => laneReasonText($r['reason']), 'setting' => $r['setting'],
        'setting_text' => ['auto' => 'אוטומטי', 'ask' => 'לשאול', 'wait' => 'להמתין', 'return_preferred' => 'לחזור לשיטה המועדפת'][$r['setting']] ?? '',
        'by' => $r['by_name'],
        'moved' => (int) $r['moved'], 'message_id' => $r['message_id'] === null ? null : (int) $r['message_id']], $st->fetchAll());
}

/* ── התגים: מסלול ומצב לכל הודעה ולכל מטלה ─────────────────── */

/**
 * ‏lane — מי טיפל / יטפל: chat | platform | engine. ‏lane_state — owner (ממתין לבעל הלוח) · chat (ממתין לצ'אט) ·
 * platform (ממתין ל-Platform) · done (טופל) · '' (הודעה שאין בה מה לטפל: שיקוף, status).
 */
function messageLane(array $m): array {
    $ab = (string) ($m['answered_by'] ?? '');
    $hb = (string) ($m['handled_by'] ?? '');
    if (in_array($m['kind'] ?? '', ['choice', 'lane_card', 'permission'], true)) {
        $lane = ($m['author_role'] ?? '') === 'platform' ? 'platform' : 'chat';
        return ['lane' => $lane, 'lane_state' => ($m['status'] ?? '') === 'open' ? 'owner' : 'done'];
    }
    if (($m['direction'] ?? '') === 'out') {
        $lane = ($m['author_role'] ?? '') === 'platform' ? 'platform' : (($m['author_role'] ?? '') === 'system' ? '' : 'chat');
        return ['lane' => $lane, 'lane_state' => ''];
    }
    if (($m['kind'] ?? '') === 'status') return ['lane' => '', 'lane_state' => ''];
    if (in_array($hb, ['platform', 'engine', 'chat'], true)) $lane = $hb;
    else $lane = str_starts_with($ab, 'platform') ? 'platform' : 'chat';
    $done = $m['delivered_at'] !== null;
    return ['lane' => $lane, 'lane_state' => $done ? 'done' : $lane];
}

/** המסלול של מטלה — מה שכבר נלקח נשאר שלו (taskRoute); מנוע = ☁️ שמריץ קוד → 🤖. */
function taskLane(array $task): string {
    $r = taskRoute($task);
    return $r === 'platform' ? 'engine' : 'chat';
}

/* ── "העבר ל…" (בעל הלוח): הקבוצה שממתינה עכשיו בלבד ──────────── */

/**
 * ‏to = platform: ההודעות שממתינות לצ'אט עוברות ל-☁️ (הלוח עונה עליהן — lane-run). נדחה כשהמפסק פתוח / אין מכסה.
 * ‏to = chat: מה שממתין ל-Platform עובר לצ'אט (פעמון).
 * לא משנה את המסלול של הפרויקט או של הערוץ, לא נוגע במטלות, ולא בהודעות שכבר טופלו.
 */
function laneSwitchManual(array $user, int $channelId, string $to): array {
    if (authorRole($user) !== 'owner') throw new AppError('העבר ל… — בעל הלוח בלבד', 403);
    if (!in_array($to, ['chat', 'platform'], true)) throw new InvalidArgumentException('to: chat או platform');
    $c = getChannel($channelId);
    if (!$c) throw new AppError('הערוץ לא נמצא', 404);
    requireProject((int) $c['project_id'], $user, 'read');
    if ($c['status'] !== 'active') throw new AppError('הערוץ אינו פעיל', 409);
    $payer = (int) projectPayer((int) $c['project_id']);
    if ($to === 'platform') {
        $can = platformCanTakeOver($payer, (int) $c['project_id'], false);
        if (!$can['ok']) throw new AppError($can['why'], 409);
    }
    return inTx(function () use ($c, $to, $user, $channelId) {
        if ($to === 'platform') {
            $st = db()->prepare("UPDATE messages SET answered_by = 'platform', handled_by = 'platform', lease_until = ?
                                  WHERE channel_id = ? AND direction = 'in' AND delivered_at IS NULL AND answered_by NOT LIKE 'platform%'
                                    AND kind <> 'status' AND author_role <> 'system'" . CHAT_ONLY_SQL);
            $st->execute([nowTs() + PLATFORM_LEASE_SECONDS, $channelId]);
        } else {
            $st = db()->prepare("UPDATE messages SET answered_by = '', handled_by = '', lease_until = 0
                                  WHERE channel_id = ? AND direction = 'in' AND delivered_at IS NULL AND answered_by = 'platform'");
            $st->execute([$channelId]);
        }
        $n = $st->rowCount();
        if ($n === 0) throw new AppError($to === 'platform' ? 'אין הודעות שממתינות לצ\'אט — אין מה להעביר' : 'אין הודעות שממתינות ל-Platform — אין מה להעביר', 409);
        if ($to === 'chat') ringBell($channelId);
        laneLogAdd($channelId, $to === 'platform' ? 'chat' : 'platform', $to, 'manual',
                   '🔀 בעל הלוח העביר ' . ($to === 'platform' ? "ל-☁️ Platform $n הודעות" : "לצ'אט $n הודעות") . ' — רק הקבוצה הזו; ההודעות הבאות לפי המסלול הרגיל.',
                   (int) $user['id'], '', $n);
        channelStateRefresh($channelId);
        return ['moved' => $n, 'run' => $to === 'platform' ? platformQueue($channelId) : []];
    });
}
