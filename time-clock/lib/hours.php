<?php
/**
 * דיווחים וחישוב שעות (האפיון: פרק 6; שלב 3).
 *
 * הכללים של גרסה 1:
 * - הזמן נשמר ב-UTC ומחושב לפי שעון החברה (Asia/Jerusalem), כך ששעון קיץ לא שובר דבר.
 * - זוג = כניסה ואחריה יציאה, עד MAX_SHIFT_H שעות. המשמרת שייכת ליום שבו התחילה (גם כשחצתה חצות).
 * - כניסה בלי יציאה — "חסר יציאה", לא נספרת. יציאה בלי כניסה — "חסר כניסה". התיקון — בקשת תיקון (שלב 5).
 * - אין עיגול: דקות שלמות, כלפי מטה.
 * - החישוב עובד על הדיווחים "בפועל": המקוריים, אחרי תיקונים שאושרו (lib/corrections.php). המקור לא משתנה.
 * - תקן יומי מהתנאים התקפים באותו יום: 5 ימים = ראשון–חמישי, 6 ימים = ראשון–שישי. שבת — 0.
 * - "מעל התקן" הוא רק ההפרש מהתקן — לא שעות נוספות לפי דין.
 */

declare(strict_types=1);

require_once __DIR__ . '/employees.php';

const MAX_SHIFT_H = 20;      // כניסה שאין אחריה יציאה תוך 20 שעות — "חסר יציאה"
const DUP_SECONDS = 60;      // אותו דיווח שוב בתוך דקה — נחסם
const OPEN_HOURS = 16;       // כניסה פתוחה ותיקה מזה — הכפתור הבא הוא "כניסה", והישנה מסומנת "חסר יציאה"

function utc(string $iso): DateTimeImmutable {
    return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
}

function localDate(string $isoUtc, string $tz): string {
    return utc($isoUtc)->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
}

/** גבולות יום מקומי ב-UTC: [התחלה, התחלת היום הבא). */
function dayBoundsUtc(string $date, string $tz): array {
    $z = new DateTimeZone($tz);
    $a = new DateTimeImmutable($date . ' 00:00:00', $z);
    return [$a->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            $a->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')];
}

/**
 * הדיווחים בפועל בטווח: המקוריים, בלי אלה שבוטלו בתיקון, עם השעה המתוקנת לאלה שהוזזו, ועם הדיווחים
 * שנוספו בתיקון. תיקון מזיז דיווח עד יום אחד לכל כיוון — ולכן מושכים יום לפני ואחרי.
 */
function punchesBetween(int $memberId, string $fromUtc, string $toUtc): array {
    $wideFrom = utc($fromUtc)->modify('-1 day')->format('Y-m-d\TH:i:s\Z');
    $wideTo = $toUtc >= '9999' ? $toUtc : utc($toUtc)->modify('+1 day')->format('Y-m-d\TH:i:s\Z');
    $st = db()->prepare('SELECT id, kind, at_utc, source FROM punches WHERE member_id = ? AND at_utc >= ? AND at_utc < ? ORDER BY at_utc, id');
    $st->execute([$memberId, $wideFrom, $wideTo]);
    $raw = $st->fetchAll();
    $st = db()->prepare("SELECT id, op, punch_id, new_kind, new_at FROM corrections WHERE member_id = ? AND status = 'approved' ORDER BY id");
    $st->execute([$memberId]);
    $fix = [];
    $out = [];
    foreach ($st->fetchAll() as $c) {
        if ($c['op'] === 'add') {
            $out[] = ['id' => null, 'correction_id' => (int) $c['id'], 'kind' => $c['new_kind'], 'at_utc' => $c['new_at'], 'source' => 'correction', 'corrected' => true];
        } else {
            $fix[(int) $c['punch_id']] = $c;   // האחרון קובע
        }
    }
    foreach ($raw as $p) {
        $c = $fix[(int) $p['id']] ?? null;
        if ($c && $c['op'] === 'void') continue;
        $out[] = ['id' => (int) $p['id'], 'correction_id' => null, 'kind' => $p['kind'], 'at_utc' => $c ? $c['new_at'] : $p['at_utc'],
                  'source' => $p['source'], 'corrected' => $c !== null];
    }
    $out = array_values(array_filter($out, fn($p) => $p['at_utc'] >= $fromUtc && $p['at_utc'] < $toUtc));
    usort($out, fn($a, $b) => [$a['at_utc'], $a['id'] ?? PHP_INT_MAX] <=> [$b['at_utc'], $b['id'] ?? PHP_INT_MAX]);
    return $out;
}

function lastPunch(int $memberId): ?array {
    $all = punchesBetween($memberId, utc(nowIso())->modify('-3 days')->format('Y-m-d\TH:i:s\Z'), '9999-12-31T00:00:00Z');
    if ($all) return end($all);
    // אין כלום בשלושת הימים האחרונים — האחרון בכלל (בלי תיקונים: הוא ממילא ישן מכדי לפתוח משמרת).
    $st = db()->prepare('SELECT id, kind, at_utc FROM punches WHERE member_id = ? ORDER BY at_utc DESC, id DESC LIMIT 1');
    $st->execute([$memberId]);
    return $st->fetch() ?: null;
}

/** תיקונים שממתינים, לפי היום (המקומי) שהם נוגעים בו. */
function pendingByDay(int $memberId, string $tz): array {
    $st = db()->prepare("SELECT c.new_at, p.at_utc FROM corrections c LEFT JOIN punches p ON p.id = c.punch_id
        WHERE c.member_id = ? AND c.status = 'pending'");
    $st->execute([$memberId]);
    $by = [];
    foreach ($st->fetchAll() as $c) {
        foreach (array_unique(array_filter([$c['new_at'], $c['at_utc']])) as $at) $by[localDate($at, $tz)] = ($by[localDate($at, $tz)] ?? 0) + 1;
    }
    return $by;
}

/** התקן לתאריך מסוים, בדקות. */
function standardMinutes(int $memberId, string $date): int {
    $t = termsAt($memberId, $date);
    if (!$t) return 0;
    $dow = (int) (new DateTimeImmutable($date))->format('w');   // 0 = ראשון … 6 = שבת
    $workDays = (int) $t['days_per_week'] === 6 ? [0, 1, 2, 3, 4, 5] : [0, 1, 2, 3, 4];
    return in_array($dow, $workDays, true) ? (int) $t['daily_minutes'] : 0;
}

/**
 * ימים מ-$from עד $to (כולל), עם הזוגות, הדקות, הסימונים והתקן. $now — לבדיקות.
 * מושכים דיווחים מעט לפני ואחרי הטווח: משמרת שהתחילה ביום האחרון יוצאת אחרי חצות.
 */
function daysReport(int $memberId, string $from, string $to, string $tz, ?string $now = null): array {
    $now = $now ?? nowIso();
    [$fromUtc] = dayBoundsUtc($from, $tz);
    [, $toUtc] = dayBoundsUtc($to, $tz);
    $pull = utc($toUtc)->modify('+' . MAX_SHIFT_H . ' hours')->format('Y-m-d\TH:i:s\Z');
    $punches = punchesBetween($memberId, utc($fromUtc)->modify('-' . MAX_SHIFT_H . ' hours')->format('Y-m-d\TH:i:s\Z'), $pull);

    $days = [];
    for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
        $k = $d->format('Y-m-d');
        $days[$k] = ['date' => $k, 'pairs' => [], 'minutes' => 0, 'missing_out' => 0, 'missing_in' => 0,
                     'open' => null, 'standard' => standardMinutes($memberId, $k), 'punches' => [], 'corrected' => false,
                     'pending' => 0];
    }
    foreach (pendingByDay($memberId, $tz) as $k => $n) if (isset($days[$k])) $days[$k]['pending'] = $n;
    // הדיווחים של היום — לפי יום המשמרת: יציאה שסוגרת משמרת מאתמול מוצגת באתמול, יחד עם הכניסה שלה.
    $show = function (array $p, string $k) use (&$days) {
        if (!isset($days[$k])) return;
        $days[$k]['punches'][] = ['id' => $p['id'], 'kind' => $p['kind'], 'at' => $p['at_utc'], 'source' => $p['source'], 'corrected' => $p['corrected']];
        if ($p['corrected']) $days[$k]['corrected'] = true;
    };

    $open = null;   // הכניסה האחרונה שעוד לא נסגרה
    $add = function (string $date, string $key, $value = null) use (&$days) {
        if (!isset($days[$date])) return;
        if ($key === 'pair') { $days[$date]['pairs'][] = $value; $days[$date]['minutes'] += $value['minutes']; }
        else $days[$date][$key] = $key === 'open' ? $value : $days[$date][$key] + 1;
    };
    foreach ($punches as $p) {
        if ($p['kind'] === 'in') {
            if ($open) $add(localDate($open['at_utc'], $tz), 'missing_out');
            $open = $p;
            $show($p, localDate($p['at_utc'], $tz));
            continue;
        }
        if ($open && utc($p['at_utc'])->getTimestamp() - utc($open['at_utc'])->getTimestamp() <= MAX_SHIFT_H * 3600) {
            $min = intdiv(utc($p['at_utc'])->getTimestamp() - utc($open['at_utc'])->getTimestamp(), 60);
            $add(localDate($open['at_utc'], $tz), 'pair', ['in' => $open['at_utc'], 'out' => $p['at_utc'], 'minutes' => $min]);
            $show($p, localDate($open['at_utc'], $tz));
        } else {
            if ($open) $add(localDate($open['at_utc'], $tz), 'missing_out');
            $add(localDate($p['at_utc'], $tz), 'missing_in');
            $show($p, localDate($p['at_utc'], $tz));
        }
        $open = null;
    }
    if ($open) {
        // עדיין בעבודה — אם לא עברו 20 שעות; אחרת שכח לצאת.
        $age = utc($now)->getTimestamp() - utc($open['at_utc'])->getTimestamp();
        $add(localDate($open['at_utc'], $tz), $age <= MAX_SHIFT_H * 3600 ? 'open' : 'missing_out', $open['at_utc']);
    }
    return array_values($days);
}

function monthBounds(string $month): array {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) throw new AppError('חודש לא תקין');
    $first = $month . '-01';
    return [$first, (new DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d')];
}

/** החודש של עובד: הימים, וסיכום. התקן נספר רק עד היום — כדי שההפרש באמצע החודש יהיה בעל משמעות. */
function monthReport(int $memberId, string $month, string $tz, ?string $now = null): array {
    [$first, $last] = monthBounds($month);
    $today = utc($now ?? nowIso())->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
    $days = daysReport($memberId, $first, $last, $tz, $now);
    // היעדרויות (גרסה 3): מאושרת זוקפת את התקן של היום; ממתינה — רק סימון
    $abs = function_exists('absenceDays') ? absenceDays($memberId, $first, $last) : [];
    $sum = ['days' => 0, 'minutes' => 0, 'standard' => 0, 'missing' => 0, 'absence' => 0];
    foreach ($days as &$d) {
        $d['absence'] = $abs[$d['date']] ?? null;
        if ($d['pairs'] || $d['open']) $sum['days']++;
        $sum['minutes'] += $d['minutes'];
        if ($d['date'] <= $today) { $sum['standard'] += $d['standard']; $sum['absence'] += $d['absence']['credit'] ?? 0; }
        $sum['missing'] += $d['missing_out'] + $d['missing_in'];
    }
    unset($d);
    $sum['diff'] = $sum['minutes'] + $sum['absence'] - $sum['standard'];
    return ['month' => $month, 'today' => $today, 'days' => $days, 'summary' => $sum];
}

/** המצב עכשיו: בעבודה מאז, הדיווחים של היום, והכפתור הבא. */
function nowStatus(int $memberId, string $tz, ?string $now = null): array {
    $now = $now ?? nowIso();
    $today = utc($now)->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
    $last = lastPunch($memberId);
    $openSince = null;
    if ($last && $last['kind'] === 'in' && utc($now)->getTimestamp() - utc($last['at_utc'])->getTimestamp() <= OPEN_HOURS * 3600) {
        $openSince = $last['at_utc'];
    }
    // היום — כולל משמרת פתוחה שהתחילה אתמול, שהדקות שלה נספרות עד עכשיו.
    $day = daysReport($memberId, $today, $today, $tz, $now)[0];
    $minutes = $day['minutes'];
    // משמרת שהתחילה היום — הדקות עד עכשיו. שהתחילה אתמול — שייכת לאתמול (כלל היום של ההתחלה).
    if ($openSince && localDate($openSince, $tz) === $today) $minutes += intdiv(utc($now)->getTimestamp() - utc($openSince)->getTimestamp(), 60);
    [$a, $b] = dayBoundsUtc($today, $tz);
    return ['today' => $today, 'open_since' => $openSince, 'next' => $openSince ? 'out' : 'in', 'minutes_today' => $minutes,
            'standard_today' => standardMinutes($memberId, $today),
            'punches' => array_map(fn($p) => ['kind' => $p['kind'], 'at' => $p['at_utc'], 'source' => $p['source'], 'corrected' => $p['corrected']], punchesBetween($memberId, $a, $b))];
}

/** רישום דיווח. אותו סוג כמו האחרון בתוך דקה — נחסם (לחיצה כפולה). */
function recordPunch(int $memberId, string $kind, string $source, ?int $deviceId = null, ?int $stationId = null, ?string $now = null): array {
    if (!in_array($kind, ['in', 'out'], true)) throw new AppError('דיווח לא מוכר');
    $now = $now ?? nowIso();
    $last = lastPunch($memberId);
    if ($last && $last['kind'] === $kind && abs(utc($now)->getTimestamp() - utc($last['at_utc'])->getTimestamp()) < DUP_SECONDS) {
        throw new AppError($kind === 'in' ? 'הכניסה כבר נרשמה' : 'היציאה כבר נרשמה', 409);
    }
    db()->prepare('INSERT INTO punches (member_id, kind, at_utc, source, station_id, device_id, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$memberId, $kind, $now, $source, $stationId, $deviceId, nowIso()]);
    return ['kind' => $kind, 'at' => $now];
}
