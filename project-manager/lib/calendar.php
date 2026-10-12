<?php
/**
 * יומן ותזכורות — שלב 10 (README, "יומן ותזכורות").
 *
 * יומן אחד לכל הפרויקטים של המנהל: שלבים (התחלה / סיום מתוכננים), תאריכי יעד של
 * תשלומים, תוקף הצעות מחיר, ופגישות / ביקורים (appointments).
 *
 * "דורש טיפול" — מחושב מהנתונים בכל פעם, לא נשמר:
 *   - תשלום שתאריך היעד שלו עבר, או מגיע בשלושת הימים הקרובים
 *   - הצעה או תשלום שממתינים ללקוח / לעו"ד יותר מ-PM_WAIT_DAYS ימים
 *   - שלב שהיה אמור להתחיל ולא התחיל, או להסתיים ולא הסתיים
 *   - הצעת מחיר שתוקפה פג (ועוד לא הוחלט עליה)
 *   - אסמכתא שממתינה לאישור קבלה; פגישה היום / מחר
 *
 * סנכרון לטלפון: api.php?action=calendar-feed&t=<אסימון> — קובץ iCalendar שיומן
 * הטלפון (גוגל / אפל) מתעדכן ממנו. האסימון: HMAC של המשתמש ו-nonce (users.cal_nonce)
 * בסוד השרת; "קישור חדש" מחליף את ה-nonce והישן מפסיק לעבוד.
 *
 * מייל יומי: בבקשה הראשונה לאפליקציה אחרי 07:00 (שעון ישראל) — לכל מנהל שיש לו
 * מה לטפל, פעם ביום (users.digest_sent). אפשר לכבות בחשבון.
 */

declare(strict_types=1);

require_once __DIR__ . '/portal.php';

const PM_TZ = 'Asia/Jerusalem';
const PM_WAIT_DAYS = 3;

function pm_today(int $plusDays = 0): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(PM_TZ)))->modify("$plusDays day")->format('Y-m-d');
}

/** ימים מאז תאריך UTC (ISO) — לפי שעון ישראל. */
function pm_days_since(?string $iso): int
{
    if (!$iso) return 0;
    $d = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone(PM_TZ));
    return (int)$d->diff(new DateTimeImmutable('now', new DateTimeZone(PM_TZ)))->days;
}

/** הפרויקטים הפעילים של המשתמש: id => name */
function pm_cal_projects(array $user): array
{
    $st = pm_db()->prepare("SELECT id, name FROM projects WHERE owner_id = ? AND status IN ('active','paused')");
    $st->execute([$user['id']]);
    return array_column($st->fetchAll(), 'name', 'id');
}

/** כל מה שביומן בין שני תאריכים (כולל). */
function pm_calendar(array $user, string $from, string $to): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) throw new PmError('טווח תאריכים לא תקין', 422);
    $projects = pm_cal_projects($user);
    if (!$projects) return ['items' => [], 'today' => pm_today()];
    $ids = implode(',', array_map('intval', array_keys($projects)));
    $db = pm_db();
    $today = pm_today();
    $items = [];
    $add = function (string $date, array $x) use (&$items, $from, $to, $projects) {
        if ($date === '' || $date < $from || $date > $to) return;
        $items[] = ['date' => $date, 'time' => '', 'project_name' => $projects[$x['project_id']] ?? ''] + $x;
    };
    foreach ($db->query("SELECT id, project_id, name, status, plan_start, plan_end FROM stages WHERE project_id IN ($ids)") as $s) {
        $done = in_array($s['status'], ['done', 'paid'], true);
        $add($s['plan_start'], ['kind' => 'stage_start', 'title' => "התחלה: {$s['name']}", 'project_id' => (int)$s['project_id'], 'ref' => (int)$s['id'],
            'pane' => 'stages', 'late' => !$done && in_array($s['status'], ['planned', 'quotes', 'client_approval', 'lawyer_approval', 'approved'], true) && $s['plan_start'] < $today]);
        if ($s['plan_end'] !== $s['plan_start']) {
            $add($s['plan_end'], ['kind' => 'stage_end', 'title' => "סיום: {$s['name']}", 'project_id' => (int)$s['project_id'], 'ref' => (int)$s['id'],
                'pane' => 'stages', 'late' => !$done && $s['plan_end'] < $today]);
        }
    }
    foreach ($db->query("SELECT p.id, p.project_id, p.title, p.amount, p.due_date, p.status, c.name AS supplier FROM payments p JOIN contacts c ON c.id = p.supplier_id
                          WHERE p.project_id IN ($ids) AND p.status != 'cancelled' AND p.due_date != ''") as $p) {
        $add($p['due_date'], ['kind' => 'payment', 'title' => "תשלום: {$p['title']} — {$p['supplier']}, " . pm_money((int)$p['amount']),
            'project_id' => (int)$p['project_id'], 'ref' => (int)$p['id'], 'pane' => 'payments', 'done' => $p['status'] === 'received',
            'late' => $p['status'] !== 'received' && $p['due_date'] < $today]);
    }
    foreach ($db->query("SELECT q.id, q.project_id, q.valid_until, q.amount, c.name AS supplier FROM quotes q JOIN contacts c ON c.id = q.supplier_id
                          WHERE q.project_id IN ($ids) AND q.valid_until != '' AND q.status IN ('open','sent','client_approved')") as $q) {
        $add($q['valid_until'], ['kind' => 'quote', 'title' => "תוקף הצעה: {$q['supplier']}, " . pm_money((int)$q['amount']),
            'project_id' => (int)$q['project_id'], 'ref' => (int)$q['id'], 'pane' => 'quotes', 'late' => $q['valid_until'] < $today]);
    }
    foreach (pm_appointments_rows($ids, $from, $to) as $a) {
        $items[] = pm_appointment_out($a, $projects);
    }
    usort($items, fn($a, $b) => [$a['date'], $a['time'] ?: '99', $a['kind']] <=> [$b['date'], $b['time'] ?: '99', $b['kind']]);
    return ['items' => $items, 'today' => $today];
}

function pm_appointments_rows(string $projectIds, string $from, string $to): array
{
    $st = pm_db()->prepare("SELECT a.*, c.name AS contact_name, c.phone AS contact_phone FROM appointments a LEFT JOIN contacts c ON c.id = a.contact_id
                             WHERE a.project_id IN ($projectIds) AND a.cancelled_at IS NULL AND a.date BETWEEN ? AND ? ORDER BY a.date, a.time");
    $st->execute([$from, $to]);
    return $st->fetchAll();
}

function pm_appointment_out(array $a, array $projects): array
{
    return ['date' => $a['date'], 'time' => $a['time'], 'kind' => 'appointment', 'title' => $a['title'], 'project_id' => (int)$a['project_id'],
        'project_name' => $projects[$a['project_id']] ?? '', 'ref' => (int)$a['id'], 'pane' => 'timeline', 'late' => false,
        'duration' => (int)$a['duration'], 'location' => $a['location'], 'contact_id' => $a['contact_id'] !== null ? (int)$a['contact_id'] : null,
        'contact_name' => $a['contact_name'], 'contact_phone' => $a['contact_phone'], 'notes' => $a['notes']];
}

/** "דורש טיפול" — מהדחוף לפחות דחוף. */
function pm_reminders(array $user): array
{
    $projects = pm_cal_projects($user);
    if (!$projects) return [];
    $ids = implode(',', array_map('intval', array_keys($projects)));
    $db = pm_db();
    $today = pm_today();
    $soon = pm_today(3);
    $out = [];
    $r = function (string $level, string $text, int $pid, string $pane, string $sort) use (&$out, $projects) {
        $out[] = ['level' => $level, 'text' => $text, 'project_id' => $pid, 'project_name' => $projects[$pid] ?? '', 'pane' => $pane, 'sort' => $sort];
    };
    // תשלומים: יעד עבר / מתקרב
    foreach ($db->query("SELECT p.*, c.name AS supplier FROM payments p JOIN contacts c ON c.id = p.supplier_id
                          WHERE p.project_id IN ($ids) AND p.status NOT IN ('received','cancelled') AND p.due_date != '' AND p.due_date <= '$soon'") as $p) {
        $late = $p['due_date'] < $today;
        $r($late ? 'late' : 'soon', ($late ? 'תשלום באיחור' : 'תשלום מתקרב') . ": {$p['title']} — {$p['supplier']}, " . pm_money((int)$p['amount'])
            . ' (' . pm_short_date($p['due_date']) . ')', (int)$p['project_id'], 'payments', $p['due_date']);
    }
    // אסמכתאות שממתינות לאישור קבלה
    foreach ($db->query("SELECT p.project_id, p.title, x.amount, x.created_at FROM payment_proofs x JOIN payments p ON p.id = x.payment_id
                          WHERE p.project_id IN ($ids) AND x.status = 'pending'") as $x) {
        $r('wait', "דווח תשלום — לאשר שהתקבל: {$x['title']}, " . pm_money((int)$x['amount']), (int)$x['project_id'], 'payments', substr($x['created_at'], 0, 10));
    }
    // ממתין ללקוח / לעו"ד יותר מ-X ימים
    foreach ($db->query("SELECT q.project_id, q.status, q.sent_at, q.updated_at, s.name AS stage FROM quotes q JOIN stages s ON s.id = q.stage_id
                          WHERE q.project_id IN ($ids) AND q.status IN ('sent','client_approved') GROUP BY q.stage_id, q.status") as $q) {
        $days = pm_days_since($q['status'] === 'sent' ? $q['sent_at'] : $q['updated_at']);
        if ($days >= PM_WAIT_DAYS) $r('wait', ($q['status'] === 'sent' ? 'הצעות ממתינות ללקוח' : 'הצעה ממתינה לעו"ד') . " $days ימים: {$q['stage']}",
            (int)$q['project_id'], 'quotes', substr((string)$q['sent_at'], 0, 10));
    }
    foreach ($db->query("SELECT project_id, title, needs_lawyer, lawyer_approved_at, requested_at FROM payments
                          WHERE project_id IN ($ids) AND status = 'requested'") as $p) {
        $days = pm_days_since($p['requested_at']);
        if ($days < PM_WAIT_DAYS) continue;
        $who = $p['needs_lawyer'] && !$p['lawyer_approved_at'] ? 'לאישור עו"ד' : 'לתשלום';
        $r('wait', "דרישת תשלום ממתינה $who $days ימים: {$p['title']}", (int)$p['project_id'], 'payments', substr((string)$p['requested_at'], 0, 10));
    }
    // שלבים: היו אמורים להתחיל / להסתיים
    foreach ($db->query("SELECT project_id, name, status, plan_start, plan_end FROM stages WHERE project_id IN ($ids) AND status NOT IN ('done','paid')") as $s) {
        if ($s['plan_end'] !== '' && $s['plan_end'] < $today) {
            $r('late', "שלב שהיה אמור להסתיים: {$s['name']} (" . pm_short_date($s['plan_end']) . ')', (int)$s['project_id'], 'stages', $s['plan_end']);
        } elseif ($s['plan_start'] !== '' && $s['plan_start'] < $today && $s['status'] !== 'in_progress') {
            $r('late', "שלב שהיה אמור להתחיל: {$s['name']} (" . pm_short_date($s['plan_start']) . ')', (int)$s['project_id'], 'stages', $s['plan_start']);
        }
    }
    // תוקף הצעה פג
    foreach ($db->query("SELECT q.project_id, q.valid_until, c.name AS supplier, s.name AS stage FROM quotes q JOIN contacts c ON c.id = q.supplier_id JOIN stages s ON s.id = q.stage_id
                          WHERE q.project_id IN ($ids) AND q.status IN ('open','sent') AND q.valid_until != '' AND q.valid_until < '$today'") as $q) {
        $r('wait', "תוקף הצעה פג: {$q['supplier']}, {$q['stage']} (" . pm_short_date($q['valid_until']) . ')', (int)$q['project_id'], 'quotes', $q['valid_until']);
    }
    // פגישות היום ומחר
    foreach (pm_appointments_rows($ids, $today, pm_today(1)) as $a) {
        $r('soon', ($a['date'] === $today ? 'היום' : 'מחר') . ($a['time'] ? " {$a['time']}" : '') . ": {$a['title']}" . ($a['location'] ? " — {$a['location']}" : ''),
            (int)$a['project_id'], 'calendar', $a['date']);
    }
    $rank = ['late' => 0, 'soon' => 1, 'wait' => 2];
    usort($out, fn($a, $b) => [$rank[$a['level']], $a['sort']] <=> [$rank[$b['level']], $b['sort']]);
    return array_map(fn($x) => array_diff_key($x, ['sort' => 1]), $out);
}

function pm_appointment_save(array $user, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    if ($id) {
        $st = pm_db()->prepare('SELECT * FROM appointments WHERE id = ? AND cancelled_at IS NULL');
        $st->execute([$id]);
        $old = $st->fetch();
        if (!$old) throw new PmError('הפגישה לא נמצאה', 404);
        pm_project_row($user, (int)$old['project_id']);
    }
    $projectId = $id ? (int)$old['project_id'] : (int)($in['project_id'] ?? 0);
    $p = pm_project_row($user, $projectId);
    $time = pm_str($in, 'time', 5, false, 'שעה');
    if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) throw new PmError('שעה לא תקינה', 422);
    $contactId = (int)($in['contact_id'] ?? 0) ?: null;
    if ($contactId) {
        $c = pm_db()->prepare('SELECT 1 FROM contacts WHERE id = ? AND owner_id = ?');
        $c->execute([$contactId, $p['owner_id']]);
        if (!$c->fetchColumn()) throw new PmError('איש הקשר לא נמצא', 422);
    }
    $f = [
        'title'      => pm_str($in, 'title', 100, true, 'מה'),
        'date'       => pm_date($in, 'date', 'תאריך') ?: throw new PmError('תאריך: שדה חובה', 422),
        'time'       => $time,
        'duration'   => max(15, min(720, (int)($in['duration'] ?? 60))),
        'location'   => pm_str($in, 'location', 200, false, 'מקום'),
        'contact_id' => $contactId,
        'notes'      => pm_str($in, 'notes', 2000, false, 'הערות'),
    ];
    $db = pm_db();
    $when = pm_short_date($f['date']) . ($f['time'] ? " {$f['time']}" : '');
    if ($id) {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
        $db->prepare("UPDATE appointments SET $set WHERE id = ?")->execute(array_merge(array_values($f), [$id]));
        if ($old['date'] !== $f['date'] || $old['time'] !== $f['time']) pm_event($user, $projectId, 'appointment', "📅 הפגישה \"{$f['title']}\" עברה ל-$when", ['appointment' => $id]);
    } else {
        $cols = implode(', ', array_keys($f));
        $db->prepare("INSERT INTO appointments ($cols, project_id, created_by, created_at) VALUES (" . implode(',', array_fill(0, count($f), '?')) . ', ?, ?, ?)')
           ->execute(array_merge(array_values($f), [$projectId, $user['id'], pm_now()]));
        $id = (int)$db->lastInsertId();
        pm_event($user, $projectId, 'appointment', "📅 נקבעה פגישה: {$f['title']} — $when" . ($f['location'] ? ", {$f['location']}" : ''), ['appointment' => $id]);
    }
    return ['saved' => $id];
}

function pm_appointment_cancel(array $user, int $id): array
{
    $st = pm_db()->prepare('SELECT * FROM appointments WHERE id = ? AND cancelled_at IS NULL');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) throw new PmError('הפגישה לא נמצאה', 404);
    pm_project_row($user, (int)$a['project_id']);
    pm_db()->prepare('UPDATE appointments SET cancelled_at = ? WHERE id = ?')->execute([pm_now(), $id]);
    pm_event($user, (int)$a['project_id'], 'appointment', "📅 בוטלה הפגישה: {$a['title']} (" . pm_short_date($a['date']) . ')', ['appointment' => $id]);
    return ['ok' => true];
}

// ================= סנכרון לטלפון (iCalendar) =================

function pm_cal_token(int $userId, string $nonce): string
{
    return $userId . '.' . rtrim(strtr(base64_encode(substr(hash_hmac('sha256', "cal:$userId:$nonce", pm_portal_secret(), true), 0, 18)), '+/', '-_'), '=');
}

/** הקישור לסנכרון. $renew — nonce חדש, הקודם מפסיק לעבוד. */
function pm_cal_link(array $user, bool $renew): array
{
    $st = pm_db()->prepare('SELECT cal_nonce FROM users WHERE id = ?');
    $st->execute([$user['id']]);
    $nonce = (string)$st->fetchColumn();
    if ($nonce === '' || $renew) {
        $nonce = bin2hex(random_bytes(8));
        pm_db()->prepare('UPDATE users SET cal_nonce = ? WHERE id = ?')->execute([$nonce, $user['id']]);
        pm_log('info', 'auth', 'calendar.link', ['user' => $user, 'data' => ['renew' => $renew]]);
    }
    $url = pm_app_url() . '/api.php?action=calendar-feed&t=' . pm_cal_token((int)$user['id'], $nonce);
    return ['url' => $url, 'webcal' => preg_replace('#^https?://#', 'webcal://', $url)];
}

function pm_ics_escape(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $s);
}

/** שורה ב-iCalendar: עד 75 בתים, המשך מתחיל ברווח (בלי לחתוך תו UTF-8 באמצע). */
function pm_ics_line(string $line): string
{
    $out = '';
    while (strlen($line) > 75) {
        $cut = 75;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line . "\r\n";
}

/** הקובץ שיומן הטלפון מושך — מחודש אחורה עד שנה קדימה. אסימון שגוי — 404. */
function pm_calendar_feed(string $token): never
{
    if (!preg_match('/^(\d+)\.[A-Za-z0-9_-]{20,30}$/', $token, $m)) throw new PmError('לא נמצא', 404);
    $st = pm_db()->prepare("SELECT id, display_name, role, status, cal_nonce FROM users WHERE id = ? AND status = 'active'");
    $st->execute([(int)$m[1]]);
    $u = $st->fetch();
    if (!$u || $u['cal_nonce'] === '' || !hash_equals(pm_cal_token((int)$u['id'], $u['cal_nonce']), $token)) {
        pm_log('warn', 'auth', 'calendar.denied', ['data' => ['user' => (int)$m[1]]]);
        throw new PmError('לא נמצא', 404);
    }
    $user = ['id' => (int)$u['id'], 'display_name' => $u['display_name'], 'role' => $u['role']];
    $cal = pm_calendar($user, pm_today(-30), pm_today(365));
    $base = pm_app_url();
    $host = parse_url($base, PHP_URL_HOST) ?: 'project-manager';
    $now = gmdate('Ymd\THis\Z');
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//mbe-plus//project-manager//HE\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n"
         . pm_ics_line('X-WR-CALNAME:' . pm_ics_escape('ניהול פרויקטים — ' . $u['display_name']))
         . "X-WR-TIMEZONE:Asia/Jerusalem\r\nREFRESH-INTERVAL;VALUE=DURATION:PT1H\r\nX-PUBLISHED-TTL:PT1H\r\n";
    foreach ($cal['items'] as $x) {
        $uid = "{$x['kind']}-{$x['ref']}-{$x['date']}@$host";
        $ics .= "BEGIN:VEVENT\r\n" . pm_ics_line("UID:$uid") . "DTSTAMP:$now\r\n";
        $day = str_replace('-', '', $x['date']);
        if ($x['kind'] === 'appointment' && $x['time'] !== '') {
            $start = new DateTimeImmutable("{$x['date']} {$x['time']}", new DateTimeZone(PM_TZ));
            $end = $start->modify("+{$x['duration']} minutes");
            $ics .= 'DTSTART:' . $start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z') . "\r\n"
                  . 'DTEND:' . $end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z') . "\r\n";
        } else {
            $next = (new DateTimeImmutable($x['date']))->modify('+1 day')->format('Ymd');
            $ics .= "DTSTART;VALUE=DATE:$day\r\nDTEND;VALUE=DATE:$next\r\nTRANSP:TRANSPARENT\r\n";
        }
        $ics .= pm_ics_line('SUMMARY:' . pm_ics_escape(($x['late'] ? '⚠️ ' : '') . $x['title'] . ' · ' . $x['project_name']));
        if (!empty($x['location'])) $ics .= pm_ics_line('LOCATION:' . pm_ics_escape($x['location']));
        $desc = trim(($x['contact_name'] ?? '') . (!empty($x['contact_phone']) ? " {$x['contact_phone']}" : '') . "\n" . ($x['notes'] ?? ''));
        if ($desc !== '') $ics .= pm_ics_line('DESCRIPTION:' . pm_ics_escape($desc));
        $ics .= pm_ics_line("URL:$base/#/p/{$x['project_id']}/{$x['pane']}") . "END:VEVENT\r\n";
    }
    $ics .= "END:VCALENDAR\r\n";
    header_remove('Content-Type');
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="project-manager.ics"');
    header('Cache-Control: private, max-age=900');
    pm_log('info', 'api', 'calendar.feed', ['user' => $user, 'data' => ['events' => count($cal['items'])]]);
    echo $ics;
    exit;
}

// ================= מייל תזכורות יומי =================

/**
 * נקרא מ-api.php בכל בקשה. אחרי 07:00 (שעון ישראל), פעם ביום, לכל מנהל פעיל עם מייל
 * ועם "מייל יומי" דלוק — אם יש לו מה לטפל. זול: בדיקה אחת של הגדרה כשכבר נשלח היום.
 */
function pm_digest_tick(): void
{
    $now = new DateTimeImmutable('now', new DateTimeZone(PM_TZ));
    if ((int)$now->format('G') < 7) return;
    $today = $now->format('Y-m-d');
    if (pm_setting('digest_day') === $today) return;
    pm_setting_set('digest_day', $today); // קודם מסמנים — כדי ששתי בקשות במקביל לא ישלחו פעמיים
    $st = pm_db()->prepare("SELECT id, username, display_name, email, role FROM users WHERE status = 'active' AND digest = 1 AND digest_sent != ?");
    $st->execute([$today]);
    $sent = 0;
    foreach ($st->fetchAll() as $u) {
        $user = ['id' => (int)$u['id'], 'display_name' => $u['display_name'], 'role' => $u['role']];
        $to = pm_user_email($u);
        $list = pm_reminders($user);
        pm_db()->prepare('UPDATE users SET digest_sent = ? WHERE id = ?')->execute([$today, $u['id']]);
        if ($to === '' || !$list) continue;
        $lines = [];
        $byProject = [];
        foreach ($list as $x) $byProject[$x['project_name']][] = ($x['level'] === 'late' ? '⚠️ ' : '• ') . $x['text'];
        foreach ($byProject as $name => $items) $lines[] = "$name\n" . implode("\n", $items);
        $ok = pm_send_mail($to, 'דורש טיפול היום (' . count($list) . ') — ניהול פרויקטים',
            "בוקר טוב {$u['display_name']},\n\n" . implode("\n\n", $lines) . "\n\nביומן: " . pm_app_url() . "/#/calendar\n\n(אפשר לכבות את המייל הזה ב\"החשבון שלי\".)\n");
        pm_log($ok ? 'info' : 'warn', 'mail', 'mail.digest', ['data' => ['to' => $to, 'items' => count($list), 'sent' => $ok]]);
        $sent++;
    }
}
