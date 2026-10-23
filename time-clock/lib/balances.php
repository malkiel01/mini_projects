<?php
/**
 * יתרות חופשה ומחלה (גרסה 3, שלב 2; האפיון: docs/v3-absences.md פרקים 1–2).
 *
 * - נקודת המוצא: יתרת הפתיחה האחרונה שהמנהל הזין (היתרה בתחילת התאריך). בלי יתרת פתיחה — 0 מתאריך
 *   ההתחלה של העובד.
 * - צבירה בסוף כל חודש עבודה מלא: חודש קלנדרי שמתחיל בנקודת המוצא או אחריה, שהעובד הועסק בו מתחילתו ועד
 *   סופו. חופשה — 1/12 מהזכאות השנתית לפי שנת הוותק וימי העבודה בשבוע; מחלה — sick_monthly, עד sick_max.
 * - הורדה: היעדרות מאושרת מסוג שמוריד מהיתרה. כל יום עבודה בה — החלק מהתקן שנזקף (יום מלא 1, חצי 0.5,
 *   שעות — שעות חלקי התקן). ימים שאחרי היום — "מתוכננות", ולא יורדות עדיין מהיתרה.
 * - יום מחלה ברצף: ימי מחלה קלנדריים רצופים (כולל ימים בלי תקן בתוך הרצף), לייצוא לשכר.
 */

declare(strict_types=1);

require_once __DIR__ . '/absences.php';

/** ימי חופשה בשנה לפי שנת ותק (1…14+). מקור: האפיון, פרק 2. */
const VAC_LAW5 = [12, 12, 12, 12, 12, 14, 15, 16, 17, 18, 19, 20, 20, 20];
const VAC_EXT5 = [12, 12, 12, 12, 12, 17, 17, 17, 23, 23, 23, 23, 23, 23];
const VAC_LAW6 = [14, 14, 14, 14, 14, 16, 18, 19, 20, 21, 22, 23, 24, 24];
const BAL_KINDS = ['vacation' => 'חופשה', 'sick' => 'מחלה'];

/** הזכאות השנתית. בשבוע של 5 ימים עם "צו הרחבה" — הגבוה מבין החוק והצו. */
function vacationEntitlement(int $year, int $daysPerWeek, string $table): int {
    $i = min(max($year, 1), 14) - 1;
    if ($daysPerWeek === 6) return VAC_LAW6[$i];
    return $table === 'extension' ? max(VAC_LAW5[$i], VAC_EXT5[$i]) : VAC_LAW5[$i];
}

function monthsBetween(string $from, string $to): int {
    $a = new DateTimeImmutable($from); $b = new DateTimeImmutable($to);
    $m = ((int) $b->format('Y') - (int) $a->format('Y')) * 12 + (int) $b->format('n') - (int) $a->format('n');
    return (int) $b->format('j') < (int) $a->format('j') ? $m - 1 : $m;
}

/** יתרת הפתיחה האחרונה עד התאריך, או null. */
function openingBalance(int $memberId, string $kind, string $until): ?array {
    $st = db()->prepare('SELECT * FROM absence_balances WHERE member_id = ? AND kind = ? AND as_of <= ? ORDER BY as_of DESC, id DESC LIMIT 1');
    $st->execute([$memberId, $kind, $until]);
    return $st->fetch() ?: null;
}

/** כמה יום מהיתרה יורד ביום הזה, לפי ההיעדרות המאושרת. */
function absDeduction(array $a, array $member, string $date): float {
    $credit = absCredit($a, $member, $date);
    if (!$credit) return 0.0;
    return $a['part'] === 'full' ? 1.0 : $credit / standardMinutes((int) $member['id'], $date);
}

/** ההורדות מהיתרה לפי ימים: [date => days]. */
function balanceUsage(array $member, string $kind, string $from): array {
    $deducting = array_keys(array_filter(absTypes((int) $member['company_id'], true), fn($t) => $t['deducts'] === $kind));
    if (!$deducting) return [];
    $in = implode(',', array_fill(0, count($deducting), '?'));
    $st = db()->prepare("SELECT * FROM absences WHERE member_id = ? AND status = 'approved' AND to_date >= ? AND type IN ($in)");
    $st->execute(array_merge([$member['id'], $from], $deducting));
    $out = [];
    foreach ($st->fetchAll() as $a) {
        for ($d = new DateTimeImmutable(max($a['from_date'], $from)); $d->format('Y-m-d') <= $a['to_date']; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            if ($x = absDeduction($a, $member, $k)) $out[$k] = ($out[$k] ?? 0) + $x;
        }
    }
    return $out;
}

/**
 * היתרה של עובד ביום $at (כולל הימים עד אליו). מחשבים חודש אחר חודש — כך התקרה של המחלה נכונה גם
 * כשהיעדרות באמצע מורידה ממנה.
 */
function memberBalance(array $member, string $kind, string $at): array {
    $s = absSettings((int) $member['company_id']);
    $open = openingBalance((int) $member['id'], $kind, $at);
    $start = $member['start_date'] ?: null;
    $from = $open['as_of'] ?? $start;
    $out = ['kind' => $kind, 'name' => BAL_KINDS[$kind], 'opening' => $open ? (float) $open['days'] : 0.0, 'as_of' => $from,
            'opening_set' => (bool) $open, 'accrued' => 0.0, 'used' => 0.0, 'planned' => 0.0, 'balance' => 0.0, 'monthly' => 0.0];
    if (!$from) return $out;   // אין יתרת פתיחה ואין תאריך התחלה — אין ממה לחשב
    $usage = balanceUsage($member, $kind, $from);
    foreach ($usage as $d => $x) if ($d > $at) $out['planned'] += $x;
    $bal = $out['opening'];
    $cap = $kind === 'sick' ? (float) $s['sick_max'] : INF;
    $end = $member['end_date'] ?: null;
    for ($m = new DateTimeImmutable(substr($from, 0, 7) . '-01'); $m->format('Y-m-d') <= $at; $m = $m->modify('+1 month')) {
        $first = $m->format('Y-m-d'); $last = $m->modify('last day of this month')->format('Y-m-d');
        foreach ($usage as $d => $x) if ($d >= $first && $d <= $last && $d <= $at) { $bal -= $x; $out['used'] += $x; }
        $full = $first >= $from && $last <= $at && (!$start || $start <= $first) && (!$end || $end >= $last);
        $rate = $kind === 'sick' ? (float) $s['sick_monthly']
            : vacationEntitlement($start ? intdiv(monthsBetween($start, $first), 12) + 1 : 1, (int) (termsAt((int) $member['id'], $first)['days_per_week'] ?? 5), $s['vacation_table']) / 12;
        $rate = $kind === 'sick' ? $rate : round($rate, 4);
        if ($full) { $add = max(0.0, min($rate, $cap - $bal)); $bal += $add; $out['accrued'] += $add; }
        $out['monthly'] = $rate;
    }
    foreach (['opening', 'accrued', 'used', 'planned'] as $k) $out[$k] = round($out[$k], 2);
    $out['balance'] = round($bal, 2);
    return $out;
}

function memberBalances(array $member, string $at): array {
    return array_map(fn($k) => memberBalance($member, $k, $at), array_keys(BAL_KINDS));
}

/** היתרות של עובד היום — לעובד, לכרטיס העובד ולאישורים. */
function balancesToday(int $memberId): array {
    $m = memberRow($memberId);
    return memberBalances($m, todayIn(companyRow((int) $m['company_id'])['tz']));
}

/** יתרת פתיחה: היתרה בתחילת התאריך. נשמרת כגרסה חדשה; הקודמת נשארת ביומן ובטבלה. */
function absOpeningSave(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [, $site] = empScope($person, $companyId, CORR_EDIT);
    $e = empRow($companyId, intIn($in, 'member_id'), $site);
    $kind = str($in, 'kind', 10);
    if (!isset(BAL_KINDS[$kind])) throw new AppError('איזו יתרה?');
    $asOf = validDate(str($in, 'as_of', 10), 'לתאריך');
    if (!is_numeric($in['days'] ?? null) || abs((float) $in['days']) > 400) throw new AppError('כמה ימים? מספר, למשל 7.5');
    $days = round((float) $in['days'], 2);
    $note = str($in, 'note', 200);
    $before = memberBalance($e, $kind, $asOf);
    db()->prepare('INSERT INTO absence_balances (member_id, kind, as_of, days, note, by_person, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$e['id'], $kind, $asOf, $days, $note ?: null, $person['id'], nowIso()]);
    audit($companyId, (int) $person['id'], 'balance.opening', 'members:' . $e['id'], ['kind' => $kind, 'balance' => $before['balance']],
          ['kind' => $kind, 'as_of' => $asOf, 'days' => $days, 'note' => $note]);
    return absHistory($person, $in);
}

/** היסטוריית יתרות הפתיחה — לכרטיס העובד. */
function openingHistory(int $memberId): array {
    $st = db()->prepare('SELECT b.*, p.name AS by_name FROM absence_balances b LEFT JOIN people p ON p.id = b.by_person WHERE b.member_id = ? ORDER BY b.as_of DESC, b.id DESC');
    $st->execute([$memberId]);
    return array_map(fn($b) => ['kind' => $b['kind'], 'as_of' => $b['as_of'], 'days' => (float) $b['days'], 'note' => $b['note'],
                                'by_name' => $b['by_name'], 'created_at' => $b['created_at']], $st->fetchAll());
}

/**
 * המיקום של כל יום מחלה ברצף, לחודש: [date => n]. רצף — ימים קלנדריים שמכוסים במחלה מאושרת; ימים בלי תקן
 * (מנוחה, חג) בין שתי מחלות לא שוברים אותו. מסתכלים עד 120 ימים אחורה, לרצף שהתחיל בחודש קודם.
 */
function sickRuns(int $memberId, string $first, string $last): array {
    $from = (new DateTimeImmutable($first))->modify('-120 days')->format('Y-m-d');
    $st = db()->prepare("SELECT from_date, to_date FROM absences WHERE member_id = ? AND status = 'approved' AND type = 'sick' AND to_date >= ? AND from_date <= ?");
    $st->execute([$memberId, $from, $last]);
    $sick = [];
    foreach ($st->fetchAll() as $a)
        for ($d = new DateTimeImmutable($a['from_date']); $d->format('Y-m-d') <= $a['to_date']; $d = $d->modify('+1 day')) $sick[$d->format('Y-m-d')] = true;
    $out = []; $n = 0; $gap = 0;
    for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $last; $d = $d->modify('+1 day')) {
        $k = $d->format('Y-m-d');
        if (isset($sick[$k])) { $n = $n ? $n + $gap + 1 : 1; $gap = 0; if ($k >= $first) $out[$k] = $n; continue; }
        if ($n && !standardMinutes($memberId, $k)) { $gap++; continue; }   // סוף שבוע בתוך מחלה — הרצף ממתין
        $n = 0; $gap = 0;
    }
    return $out;
}

function sickRunLabel(int $n): string {
    return $n === 1 ? 'יום 1' : ($n <= 3 ? 'יום 2–3' : 'יום 4+');
}
