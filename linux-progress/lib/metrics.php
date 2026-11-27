<?php
/**
 * מדדי התקדמות, קצב ותחזית — חישוב טהור, בלי רשת.
 *
 * מה נמדד בפועל: תאריכי התרגולים שנרשמו, סטטוסים, ומודולים מול התוכנית המאושרת.
 * מה לא נמדד (אין נתונים): זמן לימוד, חלוקה בין תיאוריה לתרגול, וזמן לכל יחידה.
 * לכן התחזית מבוססת על הנחות עבודה של מנהל האקדמיה, מסומנות במפורש, ולא על מדידה.
 */

declare(strict_types=1);

/** הנחות עבודה של מנהל האקדמיה (24.11.2026): סה"כ שעות להשלמה לפי רמת הערכה. לא מדידה. */
const LP_HOURS_SCENARIOS = [
    'optimistic' => ['label' => 'אופטימית', 'totalHours' => 360],
    'likely' => ['label' => 'סבירה', 'totalHours' => 480],
    'conservative' => ['label' => 'שמרנית', 'totalHours' => 600],
];
/** הנחת עבודה: שיעור מהשעות שדורשות מחשב (מעבדה). לא נמדד. */
const LP_LAB_SHARE = 0.33;
const LP_DAYS_PER_WEEK_ASSUMPTION = 6;

/** קצב לפי חלונות זמן. $today מוזרק לבדיקות. */
function lp_pace(array $items, DateTimeImmutable $today): array {
    $dates = [];
    foreach ($items as $it) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($it['date'] ?? ''), $today->getTimezone());
        if ($d !== false) $dates[] = $d;
    }
    $activeDays = [];
    foreach ($dates as $d) $activeDays[$d->format('Y-m-d')] = true;
    $windows = [];
    foreach ([7, 14, 30] as $n) {
        $cut = $today->modify("-{$n} days");
        $count = 0;
        foreach ($dates as $d) if ($d > $cut && $d <= $today) $count++;
        $windows["d$n"] = ['days' => $n, 'items' => $count, 'perWeek' => round($count / ($n / 7), 1)];
    }
    $first = $dates ? min($dates) : null;
    $last = $dates ? max($dates) : null;
    $spanWeeks = $first ? max(1, (int) ceil(($first->diff($today)->days + 1) / 7)) : 0;
    return [
        'activeDays' => count($activeDays),
        'firstDate' => $first?->format('Y-m-d'),
        'lastDate' => $last?->format('Y-m-d'),
        'daysSinceLast' => $last ? (int) $last->diff($today)->days : null,
        'spanWeeks' => $spanWeeks,
        'windows' => $windows,
    ];
}

/** כמה תרגולים נרשמו בכל שבוע, לשמונה השבועות האחרונים (הישן ראשון). */
function lp_weekly(array $items, DateTimeImmutable $today, int $weeks = 8): array {
    $out = [];
    for ($i = $weeks - 1; $i >= 0; $i--) {
        $end = $today->modify('-' . ($i * 7) . ' days');
        $start = $end->modify('-7 days');
        $count = 0;
        foreach ($items as $it) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($it['date'] ?? ''), $today->getTimezone());
            if ($d !== false && $d > $start && $d <= $end) $count++;
        }
        $out[] = ['weekEnding' => $end->format('Y-m-d'), 'items' => $count];
    }
    return $out;
}

/**
 * רמת ביטחון בתחזית: נמוכה תמיד, עד שמנהל מאשר אומדני עבודה ליחידות (החלטה 00B סעיף 4).
 * נתוני תרגול או כיסוי זמן לבדם לא מעלים את הביטחון.
 */
function lp_confidence(): string {
    return 'נמוכה';
}

/**
 * מדדים מסכמים לדף, לפי החלטה 00B. $summary מ-lp_summary.
 * התחזית מחשבת מודולים שטרם נלמדו או אומתו (החלטת מנהל בלבד). תרגול בודד לא מפחית מודול.
 */
function lp_metrics(array $summary, array $items, DateTimeImmutable $today): array {
    $pace = lp_pace($items, $today);
    $o = $summary['overall'];
    $done = $o['modulesLearnedPending'] + $o['modulesVerified'];
    $remainingModules = $o['modulesTotal'] - $done;
    return [
        'measured' => [
            'modulesTotal' => $o['modulesTotal'],
            'modulesInProgress' => $o['modulesInProgress'],
            'modulesLearnedPending' => $o['modulesLearnedPending'],
            'modulesVerified' => $o['modulesVerified'],
            'modulesRemaining' => $remainingModules,
            'percentModulesVerified' => $o['percentModulesVerified'],
            'lessonsTotal' => $o['lessonsTotal'],
            'lessonsCompleted' => $o['lessonsCompleted'],
            'percentLessonsCompleted' => $o['percentLessonsCompleted'],
            'practiceRecords' => $summary['practice']['records'],
            'capabilityChecks' => $summary['practice']['capabilityChecks'],
            'activeDays' => $pace['activeDays'],
            'daysSinceLast' => $pace['daysSinceLast'],
            'windows' => $pace['windows'],
            'weekly' => lp_weekly($items, $today),
            'timeCoveragePercent' => $summary['time']['coveragePercent'],
            'minutesReported' => $summary['time']['minutesReported'],
        ],
        'missing' => [
            'studyTime' => $summary['time']['recordsWithDuration'] === 0
                ? 'אין עדיין נתוני זמן לימוד. לא מוצגות שעות.'
                : 'נתוני זמן חלקיים: ' . $summary['time']['recordsWithDuration'] . ' מתוך ' . $summary['time']['recordsTotal'] . ' רשומות. חסר אינו נחשב כאפס.',
            'estimates' => 'אין אומדני עבודה מאושרים ליחידות. התחזית כללית בלבד.',
            'verifiedAbilities' => 'אימותי יכולת נרשמים ידנית. אימות מודול נקבע רק בהחלטת מנהל.',
        ],
        'forecastBasis' => [
            'remainingModules' => $remainingModules,
            'totalModules' => $o['modulesTotal'],
            'confidence' => lp_confidence(),
            'scenarios' => LP_HOURS_SCENARIOS,
            'labShare' => LP_LAB_SHARE,
            'daysPerWeekAssumption' => LP_DAYS_PER_WEEK_ASSUMPTION,
        ],
    ];
}
