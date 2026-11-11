<?php
/**
 * מנוע התזכורות (אפיון 08 §5). רץ מתוך שירותי הרקע (reminders.tick, כל 5 דקות), ולכל תוכנית שמועדה הגיע:
 *
 *  א. **בטרנזקציה אחת** — כל הבדיקות של §5 מחדש, לפי הסדר, ומיד אחריהן שורת ההודעה (planned) עם מזהה הפעולה
 *     reminder:<בקשה>:<מספר> (UNIQUE). דרישה שאושרה, בקשה שבוטלה או תיק שנסגר רגע קודם — נתפסים כאן, ולא נשלח דבר.
 *  ב. **אחרי ה-commit** — הקריאה למתאם. לא בתוך טרנזקציה: ספק איטי אינו מחזיק את המסד.
 *  ג. טרנזקציה קצרה: התוצאה נרשמת רק אם ההודעה עדיין planned (UPDATE … WHERE).
 *
 * הרצה חוזרת אחרי sent / unknown — אפס קריאות למתאם (08-B2, 08-B3). failed — עד 3 ניסיונות, באותה שורה ובאותו מזהה (08-B4).
 * תזכורת אינה שולחת אסימון ישן (במסד רק ה-hash שלו): נוצר קישור חדש, והקודם מבוטל **רק אחרי** שהשליחה הצליחה.
 */

declare(strict_types=1);

require_once __DIR__ . '/channels.php';
require_once __DIR__ . '/contact_content.php';
require_once __DIR__ . '/requests.php';

const APR_REMINDER_RETRY = [300, 1800, 7200];       // השהיה גדלה בין ניסיונות אחרי failed
const APR_REMINDER_TRIES = 3;
const APR_REMINDER_GAP = 20 * 3600;                 // בין שתי תזכורות של אותה בקשה — גם כשהמתזמן לא רץ ימים (מקרה קצה 3)
const APR_REMINDER_STUCK = 600;                     // planned מעל 10 דקות: ההרצה נהרגה באמצע — לא ידוע אם נשלחה

/** מקטע "קשר ותזכורות" של גרסת הפרופיל של התיק. */
function apr_case_contact(array $c): array
{
    $v = (int)apr_val('SELECT COALESCE(profile_version_id, 0) FROM cases WHERE id = ?', [$c['id']]);
    return $v ? apr_contact_clean(apr_case_version_content($v)['contact'] ?? null) : apr_contact_defaults();
}

function apr_addr_hash(string $channel, string $addr): string
{
    return $channel === 'email' ? apr_mail_hash($addr) : hash_hmac('sha256', 'tel:' . preg_replace('/\D+/', '', $addr), apr_secret());
}

/** היעד של איש קשר בערוץ: מייל, או טלפון. */
function apr_contact_addr(array $row, string $channel, string $prefix = 'contact_'): ?string
{
    $v = $channel === 'email' ? ($row[$prefix . 'email'] ?? null) : ($row[$prefix . 'phone'] ?? null);
    return is_string($v) && trim($v) !== '' ? trim($v) : null;
}

// ── חלון השעות (בדיקות 7 ו-8) ─────────────────────────────────

function apr_holidays(): array
{
    static $h = null;
    return $h ??= array_column(apr_rows('SELECT day, eve FROM holidays'), 'eve', 'day');
}

/**
 * הרגע הקרוב ביותר (≥ $ts) שבתוך החלון: יום מותר, בין השעות, לא שבת ולא חג; ערב חג ויום שישי — עד 13:00.
 * לפי השעון של $tz, כולל מעבר לשעון קיץ וחורף (החישוב על התאריך המקומי, לא על 24 שעות).
 */
function apr_reminder_window(int $ts, array $rules, string $tz): int
{
    try {
        $zone = new DateTimeZone($tz);
    } catch (Throwable) {
        $zone = new DateTimeZone(APR_CASE_TZ);
    }
    $hm = fn(string $v): array => array_map('intval', explode(':', $v));
    [$from, $to] = [$hm($rules['hours']['from'] ?? '09:00'), $hm($rules['hours']['to'] ?? '18:00')];
    $start = (new DateTimeImmutable('@' . $ts))->setTimezone($zone);
    $holidays = apr_holidays();
    for ($i = 0; $i < 400; $i++) {
        $day = $start->modify("+$i day");
        $w = APR_WEEK[(int)$day->format('w')];
        $eve = $holidays[$day->format('Y-m-d')] ?? null;
        if ($w === 'sat' || !in_array($w, $rules['days'] ?? [], true) || ($eve !== null && !(int)$eve)) continue;
        $a = $day->setTime($from[0], $from[1])->getTimestamp();
        $b = $day->setTime($to[0], $to[1])->getTimestamp();
        if ($eve !== null || $w === 'fri') $b = min($b, $day->setTime(13, 0)->getTimestamp());
        if ($a >= $b) continue;
        if ($i > 0 || $ts < $a) return $a;
        if ($ts < $b) return $ts;
    }
    return $ts + 400 * 86400;
}

/** מועד התזכורת ה-($n+1) (n = כמה כבר נשלחו), או null כשאין עוד. $after — לא לפני (המרווח מהתזכורת הקודמת). */
function apr_reminder_next(array $rules, int $base, int $n, int $after, string $tz): ?string
{
    $days = $rules['after_days'] ?? [];
    if ($n >= min((int)($rules['max'] ?? 0), count($days))) return null;
    return apr_iso(apr_reminder_window(max($base + $days[$n] * 86400, $after), $rules, $tz));
}

// ── תוכנית ────────────────────────────────────────────────────

/** עם שליחת בקשה (בתוך הטרנזקציה של רישום התוצאה): צילום הכללים והנוסח, והמועד הראשון. לבקשה תוכנית אחת — שליחה מחדש אינה פותחת שנייה. */
function apr_reminder_plan_start(array $c, int $requestId, ?int $connId): void
{
    $contact = apr_case_contact($c);
    $r = $contact['reminders'];
    if (!$r['enabled'] || $connId === null) return;
    $q = apr_row('SELECT q.created_at, o.timezone FROM requests q JOIN offices o ON o.id = q.office_id WHERE q.id = ?', [$requestId]);
    $rules = $r + ['template' => $contact['templates']['reminder']];
    $next = apr_reminder_next($rules, (int)strtotime($q['created_at']), 0, apr_time(), $q['timezone']);
    if ($next === null) return;
    apr_exec('INSERT OR IGNORE INTO reminder_plans (request_id, connection_id, rules, state, next_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$requestId, $connId, json_encode($rules, JSON_UNESCAPED_UNICODE), 'active', $next, apr_now()]);
}

function apr_plan_stop(int $planId, string $why): bool
{
    return apr_exec("UPDATE reminder_plans SET state = 'stopped', stopped_reason = ?, next_at = NULL WHERE id = ? AND state = 'active'", [$why, $planId]) === 1;
}

/** משימת מערכת לאחראי על התיק — פעם אחת לכל $key. */
function apr_case_system_task(array $c, string $title, string $key, ?string $linkType = null, ?string $linkPid = null): void
{
    if (apr_val("SELECT 1 FROM tasks WHERE case_id = ? AND origin_key = ? AND status IN ('open', 'in_progress')", [$c['id'], $key])) return;
    $assignee = apr_val('SELECT c.responsible_user_id FROM cases c JOIN case_members m ON m.case_id = c.id AND m.user_id = c.responsible_user_id AND m.revoked_at IS NULL WHERE c.id = ?', [$c['id']]);
    apr_insert('tasks', ['pid' => apr_pid(), 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'kind' => 'system', 'title' => $title, 'assignee_user_id' => $assignee ?: null,
        'origin_key' => $key, 'link_type' => $linkType, 'link_pid' => $linkPid, 'created_by' => null, 'created_at' => apr_now(), 'updated_at' => apr_now()]);
}

/** חיבור הושבת או נשלל ממשרד: התוכניות שנשענות עליו נעצרות, והאחראי על כל תיק מקבל משימה (מקרה קצה 8). בתוך טרנזקציה. */
function apr_plans_stop_for_conn(int $connId, ?int $office, string $why): void
{
    $rows = apr_rows("SELECT p.id, q.pid AS request_pid, c.id AS case_id, c.pid AS case_pid, c.office_id, c.status FROM reminder_plans p JOIN requests q ON q.id = p.request_id JOIN cases c ON c.id = q.case_id
        WHERE p.connection_id = ? AND p.state = 'active'" . ($office !== null ? ' AND q.office_id = ' . (int)$office : ''), [$connId]);
    foreach ($rows as $r) {
        if (!apr_plan_stop((int)$r['id'], $why)) continue;
        $c = ['id' => (int)$r['case_id'], 'pid' => $r['case_pid'], 'office_id' => (int)$r['office_id']];
        if ($r['status'] !== 'closed') apr_case_system_task($c, 'התזכורות נעצרו — הערוץ אינו זמין. לבדוק את הבקשה ולשלוח ידנית', 'reminders-stopped:' . $r['request_pid'], 'request', $r['request_pid']);
        apr_case_audit($c, 'reminder.stop', 'request', $r['request_pid'], ['why' => $why], null, null, 'system');
    }
}

/** הנמען עשה משהו (העלאה, תשובה, הודעה נכנסת): ההודעה היוצאת האחרונה שנשלחה — "נענתה" (§5, שלושת המצבים). בתוך טרנזקציה. */
function apr_request_answered(int $requestId): void
{
    apr_exec("UPDATE messages SET status = 'answered' WHERE id = (SELECT MAX(id) FROM messages WHERE request_id = ? AND direction = 'out' AND channel <> 'link_copy' AND status IN ('sent', 'delivered'))", [$requestId]);
}

// ── ההרצה ─────────────────────────────────────────────────────

/** קישור נוסף לבקשה, **בלי** לבטל את הקודם — הקודם מבוטל רק אחרי שהתזכורת נשלחה (apr_reminder_finish). */
function apr_portal_link_add(int $requestId, string $expires): array
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    return [apr_insert('portal_links', ['request_id' => $requestId, 'token_hash' => apr_portal_token_hash($token), 'created_at' => apr_now(), 'expires_at' => $expires]), $token];
}

/**
 * תזכורת אחת של תוכנית אחת. מחזיר מה קרה: sent / failed / unknown / skipped:<סיבה> / stopped:<סיבה> / deferred / noop.
 */
function apr_reminder_run(int $planId): string
{
    $prep = apr_tx(fn() => apr_reminder_prepare($planId));
    if (!is_array($prep)) return $prep;
    if (isset($GLOBALS['apr_before_reminder_send'])) ($GLOBALS['apr_before_reminder_send'])($prep);     // בדיקות בלבד
    $res = apr_channel_send($prep['conn'], $prep['msg']);
    return apr_tx(fn() => apr_reminder_finish($prep, $res));
}

/** שלב א — בתוך טרנזקציה. מחזיר מחרוזת (לא נשלח דבר), או את מה שנשלח. */
function apr_reminder_prepare(int $planId): string|array
{
    $p = apr_row("SELECT * FROM reminder_plans WHERE id = ? AND state = 'active'", [$planId]);
    if (!$p) return 'noop';
    $rules = json_decode($p['rules'], true) ?: [];
    $q = apr_row('SELECT q.*, c.pid AS case_pid, c.status AS case_status, c.deleted_at AS case_deleted, o.name AS office_name, o.status AS office_status, o.timezone,
            cp.pid AS party_pid, cp.removed_at AS party_removed, ct.name AS contact_name, ct.email AS contact_email, ct.phone AS contact_phone, ct.deleted_at AS contact_deleted,
            u.name AS sender_name, u.email AS sender_email
        FROM requests q JOIN cases c ON c.id = q.case_id JOIN offices o ON o.id = q.office_id JOIN case_parties cp ON cp.id = q.party_id JOIN contacts ct ON ct.id = cp.contact_id
        LEFT JOIN users u ON u.id = q.created_by WHERE q.id = ?', [$p['request_id']]);
    $c = ['id' => (int)$q['case_id'], 'pid' => $q['case_pid'], 'office_id' => (int)$q['office_id'], 'status' => $q['case_status']];
    $conn = $p['connection_id'] !== null ? apr_row('SELECT * FROM channel_connections WHERE id = ?', [$p['connection_id']]) : null;
    $channel = $conn['channel'] ?? 'email';
    $n = (int)$p['sent_count'] + 1;
    $key = 'reminder:' . $q['id'] . ':' . $n;
    $now = apr_now();
    $advance = function () use ($p, $rules, $q, $n): void {
        $next = apr_reminder_next($rules, (int)strtotime($q['created_at']), $n, apr_time() + APR_REMINDER_GAP, $q['timezone']);
        if ($next === null) apr_exec("UPDATE reminder_plans SET sent_count = ?, state = 'stopped', stopped_reason = 'max_reached', next_at = NULL WHERE id = ?", [$n, $p['id']]);
        else apr_exec('UPDATE reminder_plans SET sent_count = ?, next_at = ? WHERE id = ?', [$n, $next, $p['id']]);
    };

    // המספר הזה כבר טופל? הרצה חוזרת אינה שולחת שוב.
    $m = apr_row('SELECT * FROM messages WHERE idempotency_key = ?', [$key]);
    if ($m && in_array($m['status'], ['sent', 'delivered', 'answered', 'unknown', 'skipped'], true)) {
        $advance();
        return 'noop';
    }
    if ($m && $m['status'] === 'planned') {
        if (($m['retry_at'] ?? $m['created_at']) > apr_iso(apr_time() - APR_REMINDER_STUCK)) return 'noop';     // הרצה אחרת באמצע
        // נהרגה בין הרישום לתשובת הספק: לא ידוע אם ההודעה יצאה — בלי ניסיון חוזר אוטומטי
        apr_exec("UPDATE messages SET status = 'unknown', error = 'interrupted' WHERE id = ? AND status = 'planned'", [$m['id']]);
        $advance();
        return 'unknown';
    }
    if ($m && (int)$m['attempts'] >= APR_REMINDER_TRIES) {
        apr_plan_stop((int)$p['id'], 'send_failed');
        return 'stopped:send_failed';
    }
    if ($m && $m['retry_at'] !== null && $m['retry_at'] > $now) {
        apr_exec('UPDATE reminder_plans SET next_at = ? WHERE id = ?', [$m['retry_at'], $p['id']]);
        return 'deferred';
    }

    // ── הבדיקות של §5, לפי הסדר ──
    $open = apr_rows("SELECT r.* FROM request_items ri JOIN requirements r ON r.id = ri.requirement_id
        WHERE ri.request_id = ? AND r.split_at IS NULL AND r.status IN ('requested', 'returned') ORDER BY r.sort, r.id", [$q['id']]);
    $why = null;
    $task = null;
    $addr = apr_contact_addr($q, $channel);
    if ($q['status'] === 'revoked') $why = 'request_revoked';                                                                        // 1
    elseif (!$open) $why = 'requirement_closed';                                                                                     // 2
    elseif ($q['status'] !== 'active' || $q['expires_at'] <= $now
        || !apr_val('SELECT 1 FROM portal_links WHERE request_id = ? AND revoked_at IS NULL AND expires_at > ?', [$q['id'], $now])) $why = 'request_inactive';   // 1
    elseif (in_array('recipient_answered', $rules['stop_when'] ?? [], true) && apr_reminder_answered((int)$q['id'])) $why = 'recipient_answered';              // 3
    elseif ($q['party_removed'] !== null || $q['contact_deleted'] !== null) $why = 'party_removed';                                  // 4
    elseif ($q['case_status'] === 'closed' || $q['case_deleted'] !== null || $q['office_status'] !== 'active') $why = 'case_closed'; // 4
    elseif (!apr_conn_can_send($conn, (int)$q['office_id'])) [$why, $task] = ['connection_unavailable', 'התזכורות נעצרו — הערוץ אינו זמין. לבדוק את הבקשה ולשלוח ידנית'];   // 5
    elseif ($addr === null) [$why, $task] = ['no_address', 'התזכורות נעצרו — לנמען אין כתובת בערוץ הזה'];
    elseif (apr_val('SELECT 1 FROM channel_optouts WHERE office_id = ? AND channel = ? AND to_hash = ?', [$q['office_id'], $channel, apr_addr_hash($channel, $addr)])) $why = 'recipient_opted_out';
    if ($why !== null) {
        // נרשמת הודעה "דולגה" עם הסיבה — במספר הזה לא תישלח תזכורת — והתוכנית נעצרת
        if ($m) apr_exec("UPDATE messages SET status = 'skipped', skip_reason = ? WHERE id = ?", [$why, $m['id']]);
        else apr_insert('messages', ['pid' => apr_pid(), 'office_id' => $q['office_id'], 'case_id' => $q['case_id'], 'request_id' => $q['id'], 'party_id' => $q['party_id'], 'channel' => $channel,
            'connection_id' => $conn['id'] ?? null, 'kind' => 'reminder', 'reminder_no' => $n, 'status' => 'skipped', 'skip_reason' => $why, 'idempotency_key' => $key, 'created_at' => $now]);
        apr_plan_stop((int)$p['id'], $why);
        if ($task !== null && $q['case_status'] !== 'closed' && $q['case_deleted'] === null) apr_case_system_task($c, $task, 'reminders-stopped:' . $q['pid'], 'request', $q['pid']);
        apr_case_audit($c, 'reminder.skip', 'request', $q['pid'], ['why' => $why, 'no' => $n], null, null, 'system');
        return 'skipped:' . $why;
    }
    $limit = min((int)($rules['max'] ?? 0), count($rules['after_days'] ?? []));
    if ((int)$p['sent_count'] >= $limit) {                                                                                           // 6
        apr_plan_stop((int)$p['id'], 'max_reached');
        return 'stopped:max_reached';
    }
    $at = apr_reminder_window(apr_time(), $rules, $q['timezone']);                                                                   // 7, 8
    if ($at > apr_time()) {
        apr_exec('UPDATE reminder_plans SET next_at = ? WHERE id = ?', [apr_iso($at), $p['id']]);     // נדחית לתחילת החלון הבא — לא מדלגים
        return 'deferred';
    }

    // ── הנוסח: רק הדרישות שעדיין פתוחות ברגע הזה ──
    [$linkId, $token] = apr_portal_link_add((int)$q['id'], $q['expires_at']);
    $link = apr_portal_url($token);
    $due = array_filter(array_column($open, 'due_at'));
    $vars = ['שם_הנמען' => apr_tpl_val($q['contact_name']), 'שם_המשרד' => apr_tpl_val($q['office_name']), 'שם_השמאי' => apr_tpl_val($q['sender_name'] ?? ''),
        'רשימת_הדרישות' => implode("\n", array_map(fn($r) => '• ' . apr_tpl_val($r['title']), $open)),
        'תאריך_יעד' => $due ? (new DateTimeImmutable(min($due)))->setTimezone(new DateTimeZone(APR_CASE_TZ))->format('d.m.Y') : ''];
    $tpl = $rules['template'] ?? apr_contact_defaults()['templates']['reminder'];
    $subject = apr_tpl_subject($tpl['subject'], $vars + ['קישור' => '']);
    $stored = apr_tpl_render($tpl['body'], $vars + ['קישור' => '[קישור אישי]']);                     // כפי שנשלח — בלי האסימון
    $text = apr_tpl_render($tpl['body'], $vars + ['קישור' => $link]);
    if (!str_contains($text, $link)) $text .= "\n\n" . $link;
    $ref = apr_request_ref_set($q);
    $caps = apr_conn_caps($conn);
    if (empty($caps['free_text']) || !empty($caps['max_len'])) $text = apr_channel_short_text($text, $link, (int)($caps['max_len'] ?? 134));
    else $text .= "\n\nמזהה פנייה: $ref";
    if ($m) {
        // ניסיון חוזר אחרי failed: אותה שורה, אותו מזהה פעולה
        if (apr_exec("UPDATE messages SET status = 'planned', retry_at = ?, subject = ?, body = ? WHERE id = ? AND status = 'failed'", [$now, $subject, $stored, $m['id']]) !== 1) return 'noop';
        [$msgId, $msgPid, $attempt] = [(int)$m['id'], $m['pid'], (int)$m['attempts'] + 1];
    } else {
        $msgPid = apr_pid();
        try {
            $msgId = apr_insert('messages', ['pid' => $msgPid, 'office_id' => $q['office_id'], 'case_id' => $q['case_id'], 'request_id' => $q['id'], 'party_id' => $q['party_id'], 'channel' => $channel,
                'connection_id' => $conn['id'], 'kind' => 'reminder', 'reminder_no' => $n, 'to_hash' => apr_addr_hash($channel, $addr), 'status' => 'planned', 'subject' => $subject, 'body' => $stored,
                'idempotency_key' => $key, 'created_at' => $now]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) return 'noop';        // הרצה מקבילה הקדימה (מקרה קצה 4)
            throw $e;
        }
        $attempt = 1;
    }
    return ['plan' => $p, 'rules' => $rules, 'q' => $q, 'c' => $c, 'conn' => $conn, 'n' => $n, 'msg_id' => $msgId, 'link_id' => $linkId, 'attempt' => $attempt,
        'msg' => ['kind' => 'reminder', 'to' => $addr, 'subject' => $subject, 'text' => $text, 'idem' => "msg:$msgPid:$attempt", 'office' => (int)$q['office_id'],
            'from_name' => $q['office_name'], 'reply_to' => $q['sender_email']]];
}

/** הנמען ענה מאז ההודעה היוצאת האחרונה: העלאה, תשובה בפורטל, או הודעה נכנסת. */
function apr_reminder_answered(int $requestId): bool
{
    $since = apr_val("SELECT MAX(COALESCE(sent_at, created_at)) FROM messages WHERE request_id = ? AND direction = 'out' AND status IN ('sent', 'delivered', 'answered', 'unknown')", [$requestId]);
    if (!$since) return false;
    return (bool)(apr_val('SELECT 1 FROM files WHERE request_id = ? AND received_at >= ? LIMIT 1', [$requestId, $since])
        ?: apr_val('SELECT 1 FROM requirement_responses WHERE request_id = ? AND created_at >= ? LIMIT 1', [$requestId, $since])
        ?: apr_val("SELECT 1 FROM messages WHERE request_id = ? AND direction = 'in' AND created_at >= ? LIMIT 1", [$requestId, $since]));
}

/** שלב ג — בתוך טרנזקציה: התוצאה נרשמת רק אם ההודעה עדיין planned. */
function apr_reminder_finish(array $prep, array $res): string
{
    $st = $res['status'];
    $failed = $st === 'failed';
    $retry = $failed ? apr_iso(apr_time() + (APR_REMINDER_RETRY[$prep['attempt'] - 1] ?? 7200)) : null;
    if (apr_exec("UPDATE messages SET status = ?, sent_at = ?, error = ?, provider_ref = ?, attempts = ?, retry_at = ? WHERE id = ? AND status = 'planned'",
        [$st, $failed ? null : apr_now(), $res['error'], $res['ref'], $prep['attempt'], $retry, $prep['msg_id']]) !== 1) return 'noop';
    $q = $prep['q'];
    if ($failed) {
        // הקישור שלא יצא מבוטל; הקישור שכבר אצל הנמען נשאר בתוקף
        apr_exec('UPDATE portal_links SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [apr_now(), $prep['link_id']]);
        if ($prep['attempt'] >= APR_REMINDER_TRIES) apr_plan_stop((int)$prep['plan']['id'], 'send_failed');
        else apr_exec("UPDATE reminder_plans SET next_at = ? WHERE id = ? AND state = 'active'", [$retry, $prep['plan']['id']]);
    } else {
        if ($st === 'sent') {
            // התזכורת יצאה עם קישור חדש — הקודמים מבוטלים, כמו ב"שליחה מחדש" (לבקשה קישור חי אחד). unknown: שניהם נשארים.
            $old = array_column(apr_rows('SELECT id FROM portal_links WHERE request_id = ? AND id < ? AND revoked_at IS NULL', [$q['id'], $prep['link_id']]), 'id');
            if ($old) {
                $in = implode(',', array_map('intval', $old));
                apr_exec("DELETE FROM portal_devices WHERE link_id IN ($in)");
                apr_exec("DELETE FROM portal_otps WHERE link_id IN ($in)");
                apr_exec("UPDATE portal_links SET revoked_at = ? WHERE id IN ($in)", [apr_now()]);
            }
        }
        $next = apr_reminder_next($prep['rules'], (int)strtotime($q['created_at']), $prep['n'], apr_time() + APR_REMINDER_GAP, $q['timezone']);
        if ($next === null) apr_exec("UPDATE reminder_plans SET sent_count = ?, state = 'stopped', stopped_reason = 'max_reached', next_at = NULL WHERE id = ? AND state = 'active'", [$prep['n'], $prep['plan']['id']]);
        else apr_exec("UPDATE reminder_plans SET sent_count = ?, next_at = ? WHERE id = ? AND state = 'active'", [$prep['n'], $next, $prep['plan']['id']]);
    }
    apr_case_audit($prep['c'], 'reminder.send', 'request', $q['pid'], ['no' => $prep['n'], 'message' => $st, 'attempt' => $prep['attempt']], null, null, 'system');
    apr_case_touch($prep['c']);
    return $st;
}

apr_job_register('reminders.tick', 'תזכורות: שולח תזכורות שמועדן הגיע, אחרי בדיקה מחודשת של כל בקשה', function (): string {
    $ids = array_column(apr_rows("SELECT id FROM reminder_plans WHERE state = 'active' AND next_at IS NOT NULL AND next_at <= ? ORDER BY next_at, id LIMIT 40", [apr_now()]), 'id');
    foreach ($ids as $id) {
        try {
            apr_reminder_run((int)$id);
        } catch (Throwable $e) {
            // תוכנית אחת שנכשלה אינה עוצרת את האחרות; ביומן — סוג התקלה והמיקום, בלי תוכן
            if (function_exists('devloop_log_write')) devloop_log_write('error', 'job', 'reminder.failed', ['message' => get_class($e) . ' ב-' . basename($e->getFile()) . ':' . $e->getLine(), 'data' => ['plan' => (int)$id]]);
        }
    }
    return count($ids) === 40 ? 'partial' : 'done';
}, 300);

// ── פעולות של המשתמש (contact בתיק) ───────────────────────────

/** "עצירת תזכורות". */
function apr_reminders_stop(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, apr_doc_case_pid('requests', $in['request'] ?? null), 'contact');
    return apr_tx(function () use ($c, $in) {
        apr_case_still_open($c);
        $req = apr_request_row($c, $in['request'] ?? null);
        $p = apr_row("SELECT id FROM reminder_plans WHERE request_id = ? AND state = 'active'", [$req['id']]);
        if (!$p || !apr_plan_stop((int)$p['id'], 'manual')) apr_fail('request_state');
        apr_case_audit($c, 'reminder.stop', 'request', $req['pid'], ['why' => 'manual']);
        return [];
    });
}

/**
 * "בדיקה" של הודעה שלא ידוע אם נשלחה (או שנשלחה, ורוצים לדעת אם נמסרה): שואלים את הספק. delivered נקבע **רק** כשהערוץ
 * מדווח מסירה (delivery_reports) — ספק שאינו מדווח נשאר sent (08-B5).
 */
function apr_message_check(array $ctx, array $in): array
{
    $pid = $in['message'] ?? null;
    $c = apr_case_get($ctx, apr_doc_case_pid('messages', $pid), 'contact');
    $m = apr_row("SELECT * FROM messages WHERE pid = ? AND case_id = ? AND direction = 'out'", [$pid, $c['id']]) ?? apr_fail('not_found');
    $conn = $m['connection_id'] !== null ? apr_row('SELECT * FROM channel_connections WHERE id = ?', [$m['connection_id']]) : null;
    if (!in_array($m['status'], ['unknown', 'sent'], true) || !$conn || $m['provider_ref'] === null) return ['status' => $m['status'], 'checked' => false];
    try {
        $got = apr_channel_adapter($conn)->status($m['provider_ref']);
    } catch (Throwable) {
        $got = null;
    }
    $to = match (true) {
        $got === 'delivered' && !empty(apr_conn_caps($conn)['delivery_reports']) => 'delivered',
        $got === 'delivered', $got === 'sent' => 'sent',
        $got === 'failed' && $m['status'] === 'unknown' => 'failed',
        default => $m['status'],
    };
    if ($to === $m['status']) return ['status' => $to, 'checked' => $got !== null];
    return apr_tx(function () use ($c, $m, $to) {
        if (apr_exec('UPDATE messages SET status = ? WHERE id = ? AND status = ?', [$to, $m['id'], $m['status']]) !== 1) apr_fail('request_state');
        apr_case_audit($c, 'message.check', 'message', $m['pid'], ['from' => $m['status'], 'to' => $to]);
        return ['status' => $to, 'checked' => true];
    });
}
