<?php
/**
 * סיכום ההתקדמות מול התוכנית המאושרת של האקדמיה.
 *
 * מקור התוכנית: dashboard/data/curriculum-v2-approved.json בריפו האקדמיה (קריאה בלבד, דרך השרת).
 * כל רשומת תרגול יכולה לכלול moduleId (למשל "03.02"). רשומה בלי מזהה, או עם מזהה שלא קיים בתוכנית, נספרת כ"לא ממופה".
 *
 * כללי הספירה (ברירת מחדל שאושרה):
 * - מודול "נלמד" כשיש לו לפחות תרגול אחד בסטטוס done או verified.
 * - מודול "אומת" כשיש לו לפחות תרגול אחד בסטטוס verified. זה מוצג בנפרד ולא מסכם את הנלמד.
 * - אחוז מסלול = נלמדו / סה"כ מודולים במסלול, מעוגל לעשירית.
 * - קצב = מספר מודולים נלמדים לשבוע, מאז התאריך הראשון ברשומות.
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';

const LP_CURRICULUM_PATH = 'dashboard/data/curriculum-v2-approved.json';

/** מסלולים ומודולים מהתוכנית. מחזיר null אם הצורה לא תקינה. */
function lp_read_curriculum(mixed $data): ?array {
    if (!is_array($data) || !isset($data['tracks'], $data['modules']) || !is_array($data['tracks']) || !is_array($data['modules'])) return null;
    $tracks = [];
    foreach ($data['tracks'] as $t) {
        if (!is_array($t) || !is_string($t['id'] ?? null)) return null;
        $tracks[$t['id']] = (string) ($t['title'] ?? $t['name'] ?? $t['id']);
    }
    $modules = [];
    foreach ($data['modules'] as $m) {
        if (!is_array($m) || !is_string($m['id'] ?? null) || !preg_match(LP_MODULE_ID, $m['id'])) continue;
        $tid = (string) ($m['trackId'] ?? '');
        if (!isset($tracks[$tid])) continue;
        $modules[$m['id']] = ['trackId' => $tid, 'title' => (string) ($m['title'] ?? $m['id'])];
    }
    return ['tracks' => $tracks, 'modules' => $modules];
}

/**
 * הסיכום עצמו. $items: הרשומות שכבר עברו lp_validate (עם moduleId אופציונלי).
 * $today: מוזרק לבדיקות.
 */
function lp_summary(array $curriculum, array $items, DateTimeImmutable $today): array {
    $done = [];       // moduleId => true (נלמד)
    $verified = [];  // moduleId => true (אומת)
    $unmapped = 0;
    $first = null;
    foreach ($items as $it) {
        $d = (string) ($it['date'] ?? '');
        if ($first === null || $d < $first) $first = $d;
        $mid = $it['moduleId'] ?? null;
        if (!is_string($mid) || !isset($curriculum['modules'][$mid])) {
            $unmapped++;
            continue;
        }
        $done[$mid] = true;
        if (($it['status'] ?? '') === 'verified') $verified[$mid] = true;
    }

    $tracks = [];
    $totalAll = 0; $doneAll = 0; $verAll = 0;
    foreach ($curriculum['tracks'] as $tid => $title) {
        $mods = array_filter($curriculum['modules'], fn($m) => $m['trackId'] === $tid);
        $total = count($mods);
        if ($total === 0) continue;
        $d = 0; $v = 0; $remaining = [];
        foreach ($mods as $mid => $m) {
            if (isset($done[$mid])) $d++; else $remaining[] = ['id' => $mid, 'title' => $m['title']];
            if (isset($verified[$mid])) $v++;
        }
        $tracks[] = [
            'id' => $tid,
            'title' => $title,
            'total' => $total,
            'practiced' => $d,
            'verified' => $v,
            'percent' => round($d / $total * 100, 1),
            'remaining' => $remaining,
        ];
        $totalAll += $total; $doneAll += $d; $verAll += $v;
    }

    $pace = null;
    if ($first !== null && $first !== '') {
        $start = DateTimeImmutable::createFromFormat('Y-m-d', $first);
        if ($start !== false) {
            $days = max(0, (int) $start->diff($today)->format('%a'));
            $weeks = max(1, (int) ceil(($days + 1) / 7));
            $pace = ['since' => $first, 'weeks' => $weeks, 'modulesPerWeek' => round(count($done) / $weeks, 1)];
        }
    }

    return [
        'overall' => [
            'total' => $totalAll,
            'practiced' => $doneAll,
            'verified' => $verAll,
            'percent' => $totalAll > 0 ? round($doneAll / $totalAll * 100, 1) : 0.0,
        ],
        'tracks' => $tracks,
        'pace' => $pace,
        'unmapped' => $unmapped,
    ];
}

/** קריאת התוכנית מהריפו דרך השרת. $fetch — להזרקה בבדיקות. מחזיר [status, curriculum|null]. */
function lp_fetch_curriculum(?string $token, ?callable $fetch = null): array {
    if ($token === null) return ['no_token', null];
    $url = 'https://api.github.com/repos/' . LP_REPO . '/contents/' . LP_CURRICULUM_PATH;
    [$code, $raw] = ($fetch ?? 'lp_http_get')($url, $token);
    if ($code !== 200 || !is_string($raw)) return ['unavailable', null];
    $cur = lp_read_curriculum(json_decode($raw, true));
    return $cur === null ? ['invalid', null] : ['ok', $cur];
}
