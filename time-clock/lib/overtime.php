<?php
/**
 * סיווג שעות: רגילות, נוספות, מנוחה וחג (גרסה 2; האפיון: docs/v2-overtime-holidays.md).
 *
 * ⚠️ אינו ייעוץ משפטי. ברירות המחדל נגזרות מחוק שעות עבודה ומנוחה וטעונות אימות של רואה חשבון או
 * יועץ שכר. כל חברה משנה אותן (גרסאות עם תאריך תחולה), כי הסכם או חוזה יכולים לקבוע אחרת.
 *
 * רק סיווג — דקות בכל סל, בלי שקלים. הסכומים — באפליקציית השכר.
 *
 * הסלים: reg (רגילות), ot1 (נוספות ראשונות, 125%), ot2 (נוספות, 150%), rest (מנוחה/חג, 150%),
 * rest_ot1 (175%), rest_ot2 (200%), holiday_rest (חג שחל במנוחה — רק כשהחברה בחרה "מצטבר").
 *
 * הסדר:
 * 1. כל משמרת שייכת ליום שבו התחילה (כמו בגרסה 1). הדקות שלה נספרות לפי הסדר, ולכל דקה — היום
 *    הקלנדרי שבו היא (00:00–24:00): דקה ביום מנוחה או בחג נכנסת לסלי המנוחה.
 * 2. יומי: עד התקן היומי — רגילות; אחריו ot1_minutes ב-ot1; השאר ot2. התקן: 5 ימים / 6 ימים לפי תנאי
 *    העובד באותו יום, או לילה — כשיש ביום לפחות night_min דקות בין 22:00 ל-06:00.
 * 3. שבועי (ראשון–שבת): רגילות מעבר ל-weekly עוברות ל-ot1, מהיום האחרון בשבוע אחורה.
 */

declare(strict_types=1);

require_once __DIR__ . '/hours.php';

const OT_DEFAULTS = [
    'daily5'          => 516,    // 8:36 — שבוע של 5 ימים (לבדיקה מקצועית)
    'daily6'          => 480,    // 8:00 — שבוע של 6 ימים
    'night'           => 420,    // 7:00 — משמרת לילה
    'night_min'       => 120,    // לילה: לפחות שעתיים בין 22:00 ל-06:00
    'ot1_minutes'     => 120,    // השעות הנוספות הראשונות
    'weekly'          => 2520,   // 42 שעות
    'rate_ot1'        => 125, 'rate_ot2' => 150,
    'rate_rest'       => 150, 'rate_rest_ot1' => 175, 'rate_rest_ot2' => 200,
    'holiday_rest_cumulative' => false,
    'rate_holiday_rest' => 200,  // רק כשמצטבר — החברה קובעת
];
const OT_BUCKETS = ['reg', 'ot1', 'ot2', 'rest', 'rest_ot1', 'rest_ot2', 'holiday_rest'];
const RELIGIONS = ['jewish' => 'יהודי', 'muslim' => 'מוסלמי', 'christian' => 'נוצרי', 'druze' => 'דרוזי', 'other' => 'אחר'];
// יום מנוחה לפי הדת (0 = ראשון … 6 = שבת). דרוזי ו"אחר" — שבת כברירת מחדל, וניתן לשינוי לעובד.
const REST_BY_RELIGION = ['jewish' => 6, 'muslim' => 5, 'christian' => 0, 'druze' => 6, 'other' => 6];

/* ===== הכללים ===== */

function otRulesAt(int $companyId, string $date): array {
    $st = db()->prepare('SELECT rules FROM ot_rules WHERE company_id = ? AND effective_from <= ? ORDER BY effective_from DESC LIMIT 1');
    $st->execute([$companyId, $date]);
    $j = $st->fetchColumn();
    return $j ? array_merge(OT_DEFAULTS, json_decode($j, true)) : OT_DEFAULTS;
}

function otRulesGet(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    $me = requireMember($person, $companyId, MANAGE_ROLES);
    $st = db()->prepare('SELECT effective_from, rules, created_at FROM ot_rules WHERE company_id = ? ORDER BY effective_from DESC');
    $st->execute([$companyId]);
    $tz = companyRow($companyId)['tz'];
    return ['current' => otRulesAt($companyId, todayIn($tz)), 'defaults' => OT_DEFAULTS, 'can_edit' => $me['role'] === 'owner',
            'history' => array_map(fn($r) => ['effective_from' => $r['effective_from'], 'rules' => array_merge(OT_DEFAULTS, json_decode($r['rules'], true))], $st->fetchAll())];
}

/** גרסת כללים מתאריך. אותו תאריך — מחליף (והיומן שומר את הקודמת). */
function otRulesSave(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    requireMember($person, $companyId, ['owner']);
    $from = validDate(str($in, 'effective_from', 10), 'בתוקף מתאריך');
    $src = is_array($in['rules'] ?? null) ? $in['rules'] : [];
    $r = [];
    $range = ['daily5' => [60, 720], 'daily6' => [60, 720], 'night' => [60, 720], 'night_min' => [1, 480], 'ot1_minutes' => [0, 720],
              'weekly' => [600, 4200], 'rate_ot1' => [100, 400], 'rate_ot2' => [100, 400], 'rate_rest' => [100, 400],
              'rate_rest_ot1' => [100, 400], 'rate_rest_ot2' => [100, 400], 'rate_holiday_rest' => [100, 500]];
    foreach ($range as $k => [$lo, $hi]) {
        $v = $src[$k] ?? OT_DEFAULTS[$k];
        if (!is_numeric($v) || (int) $v < $lo || (int) $v > $hi) throw new AppError("ערך לא תקין: $k");
        $r[$k] = (int) $v;
    }
    $r['holiday_rest_cumulative'] = !empty($src['holiday_rest_cumulative']);
    $before = otRulesAt($companyId, $from);
    db()->prepare('INSERT INTO ot_rules (company_id, effective_from, rules, created_by, created_at) VALUES (?,?,?,?,?)
        ON CONFLICT (company_id, effective_from) DO UPDATE SET rules = excluded.rules, created_by = excluded.created_by, created_at = excluded.created_at')
        ->execute([$companyId, $from, json_encode($r), $person['id'], nowIso()]);
    audit($companyId, (int) $person['id'], 'ot_rules.save', 'companies:' . $companyId, $before, $r + ['effective_from' => $from]);
    return otRulesGet($person, $in);
}

/* ===== העובד: דת ויום מנוחה ===== */

function memberRestDay(array $m): int {
    if ($m['rest_day'] !== null && $m['rest_day'] !== '') return (int) $m['rest_day'];
    return REST_BY_RELIGION[$m['religion'] ?? 'jewish'] ?? 6;
}

/** חג באותו יום לעובד (שם), או null. הלוחות — שלב 2 (lib/holidays.php). */
function holidayName(int $companyId, ?string $religion, string $date): ?string {
    return function_exists('holidayOn') ? holidayOn($companyId, $religion ?? 'jewish', $date) : null;
}

/* ===== הסיווג ===== */

/** פירוק זוג כניסה–יציאה לקטעים לפי ימים קלנדריים (בשעון החברה), בשניות. */
function splitByDay(string $inUtc, string $outUtc, string $tz): array {
    $z = new DateTimeZone($tz);
    $t = utc($inUtc)->getTimestamp(); $end = utc($outUtc)->getTimestamp();
    $out = [];
    while ($t < $end) {
        $local = (new DateTimeImmutable('@' . $t))->setTimezone($z);
        $next = (new DateTimeImmutable($local->format('Y-m-d') . ' 00:00:00', $z))->modify('+1 day')->getTimestamp();
        $segEnd = min($end, $next);
        $out[] = ['date' => $local->format('Y-m-d'), 'from' => $t, 'to' => $segEnd];
        $t = $segEnd;
    }
    return $out;
}

/** שניות בתוך 22:00–06:00 (שעון החברה) של זוג אחד. */
function nightSeconds(string $inUtc, string $outUtc, string $tz): int {
    $z = new DateTimeZone($tz);
    $a = utc($inUtc)->getTimestamp(); $b = utc($outUtc)->getTimestamp();
    $sum = 0;
    // חלונות לילה מהערב שלפני הכניסה ועד היציאה
    $d = (new DateTimeImmutable('@' . $a))->setTimezone($z)->modify('-1 day')->format('Y-m-d');
    for ($i = 0; $i < 4; $i++) {
        $w0 = (new DateTimeImmutable("$d 22:00:00", $z))->getTimestamp();
        $w1 = (new DateTimeImmutable("$d 06:00:00", $z))->modify('+1 day')->getTimestamp();
        $sum += max(0, min($b, $w1) - max($a, $w0));
        $d = (new DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d');
        if ($w0 > $b) break;
    }
    return $sum;
}

/**
 * הסיווג של כל יום (יום המשמרת) בטווח. $days — מ-daysReport (חייב לכלול שבועות שלמים לחישוב השבועי;
 * classifyMonth דואג לזה). מחזיר [date => ['b' => סלים בדקות, 'rest' => bool, 'holiday' => שם|null, 'night' => bool]].
 */
function classifyDays(int $companyId, array $member, array $days, string $tz): array {
    $rest = memberRestDay($member);
    $special = [];   // date => ['rest' => bool, 'holiday' => ?string]
    $kindOf = function (string $date) use (&$special, $rest, $companyId, $member) {
        if (!isset($special[$date])) {
            $dow = (int) (new DateTimeImmutable($date))->format('w');
            $special[$date] = ['rest' => $dow === $rest, 'holiday' => holidayName($companyId, $member['religion'] ?? null, $date)];
        }
        return $special[$date];
    };
    $out = [];
    foreach ($days as $d) {
        $date = $d['date'];
        $rules = otRulesAt($companyId, $date);
        $terms = termsAt((int) $member['id'], $date);
        $night = array_sum(array_map(fn($p) => nightSeconds($p['in'], $p['out'], $tz), $d['pairs'])) >= $rules['night_min'] * 60;
        $limit = ($night ? $rules['night'] : ((int) ($terms['days_per_week'] ?? 5) === 6 ? $rules['daily6'] : $rules['daily5'])) * 60;
        $ot1 = $rules['ot1_minutes'] * 60;
        $sec = array_fill_keys(OT_BUCKETS, 0);
        $k = 0;   // שניות עבודה שכבר נספרו ביום הזה
        foreach ($d['pairs'] as $p) {
            foreach (splitByDay($p['in'], $p['out'], $tz) as $seg) {
                $kind = $kindOf($seg['date']);
                $isSpecial = $kind['rest'] || $kind['holiday'];
                $len = $seg['to'] - $seg['from'];
                // שלוש רצועות לפי המיקום ביום: עד התקן, ot1, מעבר
                foreach ([[0, $limit, 'base'], [$limit, $limit + $ot1, 'o1'], [$limit + $ot1, PHP_INT_MAX, 'o2']] as [$lo, $hi, $band]) {
                    $take = max(0, min($k + $len, $hi) - max($k, $lo));
                    if (!$take) continue;
                    if (!$isSpecial) $b = ['base' => 'reg', 'o1' => 'ot1', 'o2' => 'ot2'][$band];
                    elseif ($band === 'base') $b = ($kind['rest'] && $kind['holiday'] && $rules['holiday_rest_cumulative']) ? 'holiday_rest' : 'rest';
                    else $b = $band === 'o1' ? 'rest_ot1' : 'rest_ot2';
                    $sec[$b] += $take;
                }
                $k += $len;
            }
        }
        // לדקות שלמות, כך שהסכום יהיה בדיוק הדקות של היום (כמו בגרסה 1)
        $min = array_map(fn($s) => intdiv($s, 60), $sec);
        $diff = $d['minutes'] - array_sum($min);
        if ($diff && $k) { $big = array_keys($sec, max($sec))[0]; $min[$big] += $diff; }
        $kd = $kindOf($date);
        $out[$date] = ['b' => $min, 'rest' => $kd['rest'], 'holiday' => $kd['holiday'], 'night' => $night && $k > 0];
    }
    // שבועי: רגילות מעבר לתקן השבועי ← ot1, מהיום האחרון בשבוע אחורה
    $weeks = [];
    foreach ($out as $date => $_) {
        $dt = new DateTimeImmutable($date);
        $weeks[$dt->modify('-' . $dt->format('w') . ' days')->format('Y-m-d')][] = $date;
    }
    foreach ($weeks as $sunday => $dates) {
        $cap = otRulesAt($companyId, $sunday)['weekly'];
        $excess = array_sum(array_map(fn($x) => $out[$x]['b']['reg'], $dates)) - $cap;
        for ($i = count($dates) - 1; $excess > 0 && $i >= 0; $i--) {
            $move = min($excess, $out[$dates[$i]]['b']['reg']);
            $out[$dates[$i]]['b']['reg'] -= $move;
            $out[$dates[$i]]['b']['ot1'] += $move;
            $excess -= $move;
        }
    }
    return $out;
}

/** הסיווג לחודש: מחשבים על שבועות שלמים (גם הימים שגולשים לחודש הסמוך), ומחזירים רק את ימי החודש. */
function classifyMonth(int $memberId, string $month, string $tz): array {
    [$first, $last] = monthBounds($month);
    $a = new DateTimeImmutable($first); $b = new DateTimeImmutable($last);
    $from = $a->modify('-' . $a->format('w') . ' days')->format('Y-m-d');
    $to = $b->modify('+' . (6 - (int) $b->format('w')) . ' days')->format('Y-m-d');
    $st = db()->prepare('SELECT * FROM members WHERE id = ?');
    $st->execute([$memberId]);
    $m = $st->fetch();
    $cls = classifyDays((int) $m['company_id'], $m, daysReport($memberId, $from, $to, $tz), $tz);
    $days = array_filter($cls, fn($d) => $d >= $first && $d <= $last, ARRAY_FILTER_USE_KEY);
    $sum = array_fill_keys(OT_BUCKETS, 0);
    foreach ($days as $d) foreach ($d['b'] as $k => $v) $sum[$k] += $v;
    return ['days' => $days, 'summary' => $sum, 'rules' => otRulesAt((int) $m['company_id'], $last)];
}

/** monthReport ועוד הסיווג: לכל יום 'ot' (סלים, מנוחה, חג, לילה), ובסיכום 'ot'. משמש את המסכים ואת הייצוא. */
function monthReportOt(int $memberId, string $month, string $tz, ?string $now = null): array {
    $r = monthReport($memberId, $month, $tz, $now);
    $c = classifyMonth($memberId, $month, $tz);
    foreach ($r['days'] as &$d) $d['ot'] = $c['days'][$d['date']] ?? null;
    unset($d);
    $r['summary']['ot'] = $c['summary'];
    $r['ot_rules'] = ['rate_ot1' => $c['rules']['rate_ot1'], 'rate_ot2' => $c['rules']['rate_ot2'], 'rate_rest' => $c['rules']['rate_rest'],
                      'rate_rest_ot1' => $c['rules']['rate_rest_ot1'], 'rate_rest_ot2' => $c['rules']['rate_rest_ot2'],
                      'rate_holiday_rest' => $c['rules']['rate_holiday_rest'], 'holiday_rest_cumulative' => $c['rules']['holiday_rest_cumulative']];
    return $r;
}
