<?php
/**
 * תזכורות למנהל: לפני כל אירוע, סיכום יומי, והזמנות שממתינות לאישור.
 *
 * אין כאן תהליך שרץ ברקע — כל "תקתוק" (action=tick) בודק מה הגיע זמנו ושולח.
 * תקתוק קורה כשממשק המנהל פתוח (כל 30 שניות), כשלקוח פותח את התפריט, ומ-cron
 * של cPanel אם הוגדר (הדרך האמינה — בלעדיו תזכורת יכולה לאחר עד הביקור הבא).
 *
 * מה כבר נשלח — ב-data/reminders.json (מוחרג מהפריסה), כדי שתזכורת לא תצא פעמיים.
 * השעות תמיד לפי שעון ישראל, בלי קשר לאזור הזמן של השרת.
 *
 * הקובץ נטען מ-api.php בלבד; פנייה ישירה אליו לא עושה כלום.
 */

declare(strict_types=1);

if (!defined('DATA_DIR')) {         // נטען רק מתוך api.php
    http_response_code(404);
    exit;
}

const REMINDERS_FILE = DATA_DIR . '/reminders.json';
const REMINDER_GRACE = 12 * 3600;    // תזכורת שאיחרה ביותר מזה (שרת שלא תוקתק) — מוותרים
const REMINDER_MAX_RULES = 6;

function tz(): DateTimeZone {
    static $tz = null;
    return $tz ??= new DateTimeZone('Asia/Jerusalem');
}

/** חותמת זמן של תאריך ושעה בשעון ישראל. */
function ilTime(string $date, string $time): int {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$date $time", tz());
    return $d ? $d->getTimestamp() : 0;
}

function defaultReminders(): array {
    return [
        'on'    => true,
        'rules' => [
            ['type' => 'days', 'days' => 2, 'time' => '09:00'],
            ['type' => 'days', 'days' => 1, 'time' => '20:00'],
            ['type' => 'hours', 'hours' => 3],
        ],
        'who'      => 'approved',   // approved — רק מאושרות; all — גם ממתינות לאישור
        'skipDone' => true,         // אירוע שכל המנות בו מוכנות — לא מזכירים
        'mail'     => false,        // גם מייל למנהל (לכתובת שבלשונית המיילים)
        'digest'   => ['on' => true, 'time' => '08:00'],
        'nag'      => ['on' => true, 'hours' => 3],
        'quiet'    => ['on' => false, 'from' => '22:00', 'to' => '07:00'],
    ];
}

function cleanReminders(mixed $in): array {
    $def = defaultReminders();
    if (!is_array($in)) return $def;
    $bool = fn(array $a, string $k, bool $d) => array_key_exists($k, $a) ? (bool) $a[$k] : $d;
    $int  = fn($v, int $min, int $max, int $d) => is_numeric($v) ? max($min, min($max, (int) $v)) : $d;
    $time = fn($v, string $d) => (is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) ? $v : $d;

    $rules = [];
    $seen = [];
    foreach (is_array($in['rules'] ?? null) ? $in['rules'] : [] as $r) {
        if (!is_array($r)) continue;
        $rule = ($r['type'] ?? '') === 'hours'
            ? ['type' => 'hours', 'hours' => $int($r['hours'] ?? null, 1, 72, 3)]
            : ['type' => 'days', 'days' => $int($r['days'] ?? null, 0, 30, 1), 'time' => $time($r['time'] ?? null, '09:00')];
        $key = ruleKey($rule);
        if (isset($seen[$key])) continue;            // אותה תזכורת פעמיים — פעם אחת מספיק
        $seen[$key] = true;
        $rules[] = $rule;
        if (count($rules) >= REMINDER_MAX_RULES) break;
    }
    $digest = is_array($in['digest'] ?? null) ? $in['digest'] : [];
    $nag    = is_array($in['nag'] ?? null) ? $in['nag'] : [];
    $quiet  = is_array($in['quiet'] ?? null) ? $in['quiet'] : [];

    return [
        'on'       => $bool($in, 'on', true),
        'rules'    => $rules,
        'who'      => ($in['who'] ?? '') === 'all' ? 'all' : 'approved',
        'skipDone' => $bool($in, 'skipDone', true),
        'mail'     => $bool($in, 'mail', false),
        'digest'   => ['on' => $bool($digest, 'on', true), 'time' => $time($digest['time'] ?? null, '08:00')],
        'nag'      => ['on' => $bool($nag, 'on', true), 'hours' => $int($nag['hours'] ?? null, 1, 72, 3)],
        'quiet'    => [
            'on'   => $bool($quiet, 'on', false),
            'from' => $time($quiet['from'] ?? null, '22:00'),
            'to'   => $time($quiet['to'] ?? null, '07:00'),
        ],
    ];
}

function ruleKey(array $rule): string {
    return $rule['type'] === 'hours' ? 'h' . $rule['hours'] : 'd' . $rule['days'] . '@' . $rule['time'];
}

/** האם השעה (HH:MM) בתוך שעות השקט — כולל טווח שחוצה חצות (22:00–07:00). */
function inQuiet(array $quiet, string $hm): bool {
    if (empty($quiet['on']) || $quiet['from'] === $quiet['to']) return false;
    return $quiet['from'] < $quiet['to']
        ? ($hm >= $quiet['from'] && $hm < $quiet['to'])
        : ($hm >= $quiet['from'] || $hm < $quiet['to']);
}

/** "היום ב-19:00", "מחר", "בעוד 3 ימים" — מתי האירוע, ביחס לרגע השליחה. */
function whenLabel(array $order, int $now): string {
    $c = $order['customer'];
    $today = (new DateTimeImmutable('@' . $now))->setTimezone(tz())->setTime(0, 0);
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $c['deliveryDate'], tz());
    $diff = (int) round(($day->getTimestamp() - $today->getTimestamp()) / 86400);
    $at = $c['deliveryTime'] ? ' ב-' . $c['deliveryTime'] : '';
    return match (true) {
        $diff <= 0 => 'היום' . $at,
        $diff === 1 => 'מחר' . $at,
        $diff === 2 => 'מחרתיים' . $at,
        default => "בעוד $diff ימים" . $at,
    };
}

function eventsCount(int $n): string {
    return match ($n) { 0 => 'אין אירועים', 1 => 'אירוע אחד', 2 => 'שני אירועים', default => "$n אירועים" };
}

/** האם ההזמנה בכלל מקבלת תזכורות לפני האירוע. */
function remindable(array $order, array $rem): bool {
    if (!empty($order['remindersOff'])) return false;
    if (empty($order['customer']['deliveryDate'])) return false;
    $approval = $order['approval'] ?? 'new';
    if ($approval === 'rejected') return false;
    if ($approval === 'new' && $rem['who'] !== 'all') return false;
    if ($rem['skipDone'] && ($order['status'] ?? '') === 'done') return false;
    return true;
}

/**
 * כל התזכורות של אירוע: [מפתח, מתי]. המפתח כולל את מועד האירוע — כך שאם
 * המועד הוזז, התזכורות נדלקות מחדש לפי המועד החדש.
 */
function orderDues(array $order, array $rem): array {
    $c = $order['customer'];
    $time = $c['deliveryTime'] ?? '';
    $event = ilTime($c['deliveryDate'], $time ?: '23:59');
    $out = [];
    foreach ($rem['rules'] as $rule) {
        if ($rule['type'] === 'hours') {
            if (!$time) continue;                    // בלי שעת אירוע אין "3 שעות לפני"
            $at = $event - $rule['hours'] * 3600;
        } else {
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $c['deliveryDate'], tz())->modify('-' . $rule['days'] . ' days');
            $at = ilTime($day->format('Y-m-d'), $rule['time']);
            if ($time && $at >= $event) continue;     // "ביום האירוע ב-09:00" לאירוע של 08:00
        }
        $out[] = [$order['id'] . '|' . ruleKey($rule) . '|' . $c['deliveryDate'] . $time, $at, $event];
    }
    return $out;
}

function allOrders(): array {
    $orders = [];
    foreach (glob(ORDERS_DIR . '/*.json') ?: [] as $file) {
        $o = readJson($file);
        if ($o) $orders[] = $o;
    }
    return $orders;
}

function readyLine(array $order): string {
    $n = count($order['items']);
    $r = count(array_filter($order['items'], fn($i) => !empty($i['ready'])));
    return $r === $n ? '✓ כל המנות מוכנות' : "מוכנות $r מתוך $n מנות";
}

/** שליחה לכל הטלפונים, ואם נבחר — גם מייל למנהל. */
function remind(array $rem, string $title, string $body, string $url, string $tag): void {
    try {
        pushToAll(['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $tag]);
    } catch (Throwable $e) { /* תזכורת שנכשלה לא עוצרת את השאר */ }
    $to = loadSettings()['email']['adminNotify'];
    if ($rem['mail'] && $to) {
        sendMail($to, $title, '<h2>' . h($title) . '</h2><p>' . nl2br(h($body)) . '</p>');
    }
}

/**
 * תקתוק: שולח את כל מה שהגיע זמנו. מוגן בנעילה — שני תקתוקים במקביל לא
 * ישלחו את אותה תזכורת פעמיים. מחזיר כמה נשלחו.
 */
function runReminders(string $via, ?int $now = null): int {
    $now ??= time();
    $lock = fopen(REMINDERS_FILE . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 0;     // תקתוק אחר כבר רץ

    $state = readJson(REMINDERS_FILE) ?? [];
    $sent = is_array($state['sent'] ?? null) ? $state['sent'] : [];
    $state['lastTick'] = date('c', $now);
    $state['lastVia'] = $via;
    $count = 0;

    $rem = loadSettings()['reminders'];
    $nowDt = (new DateTimeImmutable('@' . $now))->setTimezone(tz());
    $quiet = inQuiet($rem['quiet'], $nowDt->format('H:i'));

    if ($rem['on'] && !$quiet) {
        $orders = allOrders();

        // 1. לפני כל אירוע — רק התזכורת האחרונה שהגיע זמנה (אם כמה הצטברו, אחת מספיקה)
        foreach ($orders as $o) {
            if (!remindable($o, $rem)) continue;
            $created = strtotime($o['createdAt'] ?? '') ?: 0;
            $due = [];
            foreach (orderDues($o, $rem) as [$key, $at, $event]) {
                // תזכורת שזמנה עבר לפני שההזמנה בכלל הגיעה — לא שולחים (הזמנה של מחר לא תקבל מיד "בעוד יומיים")
                if (isset($sent[$key]) || $at > $now || $now - $at > REMINDER_GRACE || $at < $created || $event < $now) continue;
                $due[$key] = $at;
            }
            if (!$due) continue;
            arsort($due);
            foreach ($due as $key => $_) $sent[$key] = $now;
            $c = $o['customer'];
            remind($rem,
                '⏰ ' . $c['name'] . ' — ' . whenLabel($o, $now),
                implode(' · ', array_filter([
                    count($o['items']) . ' מנות',
                    $c['guests'] ? $c['guests'] . ' סועדים' : '',
                    readyLine($o),
                    ($o['approval'] ?? '') === 'new' ? '⚠️ עדיין לא אושרה' : '',
                ])),
                'admin/?order=' . $o['id'], 'remind-' . $o['id']);
            $count++;
        }

        // 2. סיכום יומי — פעם ביום, בשעה שנקבעה
        $today = $nowDt->format('Y-m-d');
        $digestAt = ilTime($today, $rem['digest']['time']);
        if ($rem['digest']['on'] && !isset($sent["digest|$today"]) && $now >= $digestAt && $now - $digestAt <= REMINDER_GRACE) {
            $sent["digest|$today"] = $now;
            $tomorrow = $nowDt->modify('+1 day')->format('Y-m-d');
            $list = ['today' => [], 'tomorrow' => []];
            foreach ($orders as $o) {
                $d = $o['customer']['deliveryDate'] ?? '';
                if (($o['approval'] ?? 'new') === 'rejected' || ($d !== $today && $d !== $tomorrow)) continue;
                $list[$d === $today ? 'today' : 'tomorrow'][] = $o['customer']['name']
                    . ($o['customer']['deliveryTime'] ? ' ' . $o['customer']['deliveryTime'] : '')
                    . (($o['approval'] ?? 'new') === 'new' ? ' (ממתינה לאישור)' : '');
            }
            if ($list['today'] || $list['tomorrow']) {   // יום בלי אירועים — שקט
                $lines = [];
                if ($list['today']) $lines[] = 'היום: ' . implode(', ', $list['today']);
                if ($list['tomorrow']) $lines[] = 'מחר: ' . implode(', ', $list['tomorrow']);
                remind($rem,
                    '📋 בוקר טוב — היום ' . eventsCount(count($list['today'])) . ', מחר ' . eventsCount(count($list['tomorrow'])),
                    implode("\n", $lines), 'admin/', 'digest');
                $count++;
            }
        }

        // 3. הזמנות שממתינות לאישור יותר מדי זמן — התראה אחת מרוכזת
        if ($rem['nag']['on']) {
            $waiting = [];
            foreach ($orders as $o) {
                if (($o['approval'] ?? 'new') !== 'new' || isset($sent['nag|' . $o['id']])) continue;
                $created = strtotime($o['createdAt'] ?? '') ?: $now;
                if ($now - $created >= $rem['nag']['hours'] * 3600) $waiting[] = $o;
            }
            if ($waiting) {
                foreach ($waiting as $o) $sent['nag|' . $o['id']] = $now;
                $one = count($waiting) === 1;
                remind($rem,
                    $one ? '⏳ הזמנה של ' . $waiting[0]['customer']['name'] . ' ממתינה לאישור'
                         : '⏳ ' . count($waiting) . ' הזמנות ממתינות לאישור',
                    $one ? 'עברו יותר מ-' . $rem['nag']['hours'] . ' שעות מאז שהגיעה'
                         : implode(', ', array_map(fn($o) => $o['customer']['name'], $waiting)),
                    $one ? 'admin/?order=' . $waiting[0]['id'] : 'admin/', 'nag');
                $count++;
            }
        }
    }

    // ניקוי: מה שנשלח לפני יותר מ-60 יום כבר לא יחזור
    $state['sent'] = array_filter($sent, fn($t) => $now - (int) $t < 60 * 86400);
    writeJson(REMINDERS_FILE, $state);
    flock($lock, LOCK_UN);
    fclose($lock);
    return $count;
}

/** תקתוק אחרי שהתשובה כבר יצאה — אבל לא יותר מפעם בדקה (ל-cron: פעם ב-10 שניות). */
function maybeTick(string $via): void {
    if ($via === 'cron') @touch(REMINDERS_FILE . '.cron');   // ה-cron חי — גם אם התקתוק עצמו מדולג
    clearstatcache();
    $last = @filemtime(REMINDERS_FILE);
    // ה-cron רץ גם אם הממשק תקתק לפני רגע — כדי שיירשם שהוא עובד
    if ($last && time() - $last < ($via === 'cron' ? 10 : 60)) return;
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // לא לעכב את הבקשה הבאה של המנהל
    try { runReminders($via); } catch (Throwable $e) { /* תקלה בתזכורות לא מפילה את הבקשה */ }
}

/** התזכורות הקרובות (לתצוגה בהגדרות): מה ייצא ומתי, ב-14 הימים הבאים. */
function upcomingReminders(int $now): array {
    $rem = loadSettings()['reminders'];
    $state = readJson(REMINDERS_FILE) ?? [];
    $sent = $state['sent'] ?? [];
    $list = [];
    if ($rem['on']) {
        foreach (allOrders() as $o) {
            if (!remindable($o, $rem)) continue;
            $created = strtotime($o['createdAt'] ?? '') ?: 0;
            foreach (orderDues($o, $rem) as [$key, $at, $event]) {
                if (isset($sent[$key]) || $at < $now || $at < $created || $at > $now + 14 * 86400) continue;
                $list[] = ['at' => date('c', $at), 'order' => $o['id'], 'name' => $o['customer']['name'],
                           'event' => date('c', $event), 'quiet' => inQuiet($rem['quiet'], (new DateTimeImmutable('@' . $at))->setTimezone(tz())->format('H:i'))];
            }
        }
    }
    usort($list, fn($a, $b) => strcmp($a['at'], $b['at']));
    return [
        'list'     => array_slice($list, 0, 20),
        'lastTick' => $state['lastTick'] ?? null,
        'lastVia'  => $state['lastVia'] ?? null,
        'lastCron' => ($t = @filemtime(REMINDERS_FILE . '.cron')) ? date('c', $t) : null,
    ];
}
