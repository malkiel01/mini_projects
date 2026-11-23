<?php
/**
 * אימות דיווח מורה לפי הפורמט ב-docs/reports/teacher-report-format.md (reportVersion "1.0.0").
 *
 * הדיווח הוא ממצאים בלבד: אין בו סטטוס, ציון או אחוז. ראיות הן הפניות בלבד (לא תוכן).
 * ההפניות נבדקות באותם כללים כמו הראיות של חוזה v2 (academy_v2_unsafe_reference ב-validate-v2.php).
 *
 * הפונקציה לא נוגעת ברשת, לא בקבצי הריצה ולא במאגר האקדמיה.
 */

declare(strict_types=1);

require_once __DIR__ . '/validate-v2.php';

const REPORT_VERSION = '1.0.0';
const REPORT_TEACHERS = ['01', '02', '03', '04'];
const REPORT_ASSISTANCE = ['none', 'hint', 'guided', 'full', 'unknown'];
const REPORT_TASK_ID = '/^ACA-[A-Z0-9][A-Z0-9-]{1,39}$/';
const REPORT_FIELDS = ['reportVersion', 'taskId', 'teacherId', 'date', 'lessonIds', 'skillIds', 'assistanceLevel',
    'findings', 'evidenceRefs', 'evidenceLimits', 'recommendation'];
const REPORT_REQUIRED = ['reportVersion', 'taskId', 'teacherId', 'date', 'lessonIds', 'skillIds', 'assistanceLevel',
    'findings', 'evidenceRefs', 'evidenceLimits', 'recommendation'];

/**
 * בודק דיווח. $catalog אופציונלי: ['lessonIds' => [...], 'skillIds' => [...]] מתוך הקוריקולום המאושר.
 * בלי קטלוג — נבדק רק הפורמט של המזהים. מחזיר רשימת שגיאות (ריקה = תקין), כל אחת עם נתיב JSON.
 *
 * @return list<string>
 */
function academy_validate_report(mixed $r, ?array $catalog = null, array $httpsHosts = []): array {
    $err = [];
    if (!is_array($r) || ($r !== [] && array_is_list($r))) return ['$: הדיווח חייב להיות אובייקט JSON'];

    // שדות אסורים מפורשות — הודעה ברורה במקום "שדה לא מוכר"
    if (array_key_exists('status', $r)) $err[] = '$.status: שדה status אסור — הדיווח מכיל ממצאים בלבד, בלי סטטוס';

    foreach (array_keys($r) as $k) {
        if (!in_array($k, REPORT_FIELDS, true) && $k !== 'status') $err[] = "\$.$k: שדה \"$k\" אינו קיים בפורמט הדיווח v" . REPORT_VERSION;
    }
    foreach (REPORT_REQUIRED as $k) {
        if (!array_key_exists($k, $r)) $err[] = "\$.$k: שדה חובה חסר";
    }

    if (array_key_exists('reportVersion', $r) && $r['reportVersion'] !== REPORT_VERSION) {
        $err[] = '$.reportVersion: חייב להיות "' . REPORT_VERSION . '"';
    }
    if (array_key_exists('taskId', $r) && !(is_string($r['taskId']) && preg_match(REPORT_TASK_ID, $r['taskId']))) {
        $err[] = '$.taskId: מזהה משימה בצורת ACA-… (אותיות גדולות, ספרות ומקפים)';
    }
    if (array_key_exists('teacherId', $r) && !(is_string($r['teacherId']) && in_array($r['teacherId'], REPORT_TEACHERS, true))) {
        $err[] = '$.teacherId: חייב להיות אחד מ-01, 02, 03, 04';
    }
    if (array_key_exists('date', $r) && !report_is_date($r['date'])) {
        $err[] = '$.date: תאריך בצורת YYYY-MM-DD שקיים בלוח';
    }
    if (array_key_exists('assistanceLevel', $r) && !(is_string($r['assistanceLevel']) && in_array($r['assistanceLevel'], REPORT_ASSISTANCE, true))) {
        $err[] = '$.assistanceLevel: חייב להיות none | hint | guided | full | unknown';
    }

    foreach (['lessonIds' => 'lessonIds', 'skillIds' => 'skillIds'] as $field => $catKey) {
        if (!array_key_exists($field, $r)) continue;
        $list = $r[$field];
        if (!is_array($list) || ($list !== [] && !array_is_list($list))) {
            $err[] = "\$.$field: חייב להיות מערך";
            continue;
        }
        $seen = [];
        foreach ($list as $i => $id) {
            if (!is_string($id) || !preg_match(ACADEMY_V2_ID, $id)) {
                $err[] = "\$.$field[$i]: מזהה לא תקין (תווים בטוחים בלבד, בלי רווחים)";
                continue;
            }
            if (isset($seen[$id])) $err[] = "\$.$field[$i]: מזהה כפול \"$id\"";
            $seen[$id] = true;
            if ($catalog !== null && !in_array($id, $catalog[$catKey] ?? [], true)) {
                $err[] = "\$.$field[$i]: \"$id\" לא נמצא בקוריקולום המאושר";
            }
        }
    }
    if (array_key_exists('lessonIds', $r) && is_array($r['lessonIds']) && $r['lessonIds'] === []) {
        $err[] = '$.lessonIds: חייב לכלול לפחות שיעור אחד';
    }

    foreach (['findings', 'evidenceLimits'] as $field) {
        if (!array_key_exists($field, $r)) continue;
        $err = array_merge($err, report_check_text_list($r[$field], "\$.$field"));
    }
    if (array_key_exists('recommendation', $r) && !(is_string($r['recommendation']) && trim($r['recommendation']) !== '')) {
        $err[] = '$.recommendation: חייב להיות טקסט לא ריק';
    }

    if (array_key_exists('evidenceRefs', $r)) {
        $refs = $r['evidenceRefs'];
        if (!is_array($refs) || ($refs !== [] && !array_is_list($refs))) {
            $err[] = '$.evidenceRefs: חייב להיות מערך של הפניות';
        } else {
            foreach ($refs as $i => $ref) {
                if (!is_string($ref)) {
                    $err[] = "\$.evidenceRefs[$i]: הפניה חייבת להיות מחרוזת";
                    continue;
                }
                $why = academy_v2_unsafe_reference($ref);
                if ($why !== '') {
                    $err[] = "\$.evidenceRefs[$i]: הפניה לא בטוחה — $why";
                } elseif (preg_match('~^https://~i', $ref)) {
                    // https רק למארחים ברשימה המאושרת מהקונפיג. ברירת מחדל: רשימה ריקה — כל https נדחה.
                    $host = strtolower((string) parse_url($ref, PHP_URL_HOST));
                    if (parse_url($ref, PHP_URL_USER) !== null || parse_url($ref, PHP_URL_PASS) !== null) {
                        $err[] = "\$.evidenceRefs[$i]: הפניית https עם פרטי התחברות בכתובת — נדחית";
                    } elseif (!in_array($host, array_map('strtolower', $httpsHosts), true)) {
                        $err[] = "\$.evidenceRefs[$i]: הפניית https למארח \"$host\" אינה ברשימה המאושרת (כרגע ריקה — רק נתיבים יחסיים)";
                    }
                }
            }
        }
    }

    // סוד בכל טקסט חופשי (ממצאים, מגבלות, המלצה, מזהים) — כולל שדות שכבר נבדקו
    foreach (report_string_leaves($r) as [$path, $value]) {
        $hit = report_secret_hit($value);
        if ($hit !== '') $err[] = "$path: נראה כמו סוד ($hit) — להסיר לפני שליחה";
    }

    return array_values(array_unique($err));
}

/**
 * בונה את גוף הבקשה ל-progress-write.php action=report (ראו docs/reports/teacher-report-format.md, "מיפוי").
 * הדיווח חייב לעבור academy_validate_report קודם; אחרת נזרקת חריגה. הפונקציה לא שולחת כלום.
 * evidenceRefs לא נכנסים לגוף הזה — הם עוברים בנפרד דרך apply ב-evidence[].
 *
 * @return array{action: string, learnerId: string, report: array<string, mixed>}
 */
function academy_report_to_progress_payload(array $r, string $learnerId): array {
    if (academy_validate_report($r) !== []) throw new InvalidArgumentException('דיווח לא תקין — לא נבנה גוף לשרת');
    $lines = ['ממצאים:', ...$r['findings'], 'מגבלות ראיות:', ...$r['evidenceLimits'], 'המלצה:', $r['recommendation']];
    return [
        'action' => 'report',
        'learnerId' => $learnerId,
        'report' => [
            'id' => $r['taskId'] . '-T' . $r['teacherId'],
            'at' => $r['date'] . 'T00:00:00Z',
            'reporter' => $r['teacherId'],
            'skillIds' => $r['skillIds'],
            'lessonIds' => $r['lessonIds'],
            'assistanceLevel' => $r['assistanceLevel'],
            'summary' => implode("\n", $lines),
        ],
    ];
}

/** מחזיר סיבה אם המחרוזת נראית כמו סוד, אחרת ''. */
function report_secret_hit(string $s): string {
    if (preg_match('/(gh[pousr]_[A-Za-z0-9]{8,}|github_pat_|sk-[A-Za-z0-9]{16,}|AKIA[0-9A-Z]{12,})/', $s)) return 'מפתח או אסימון';
    if (str_contains($s, '-----BEGIN')) return 'מפתח פרטי';
    if (preg_match('/\b(password|passwd|pwd|api[_-]?key|secret|token|bearer)\b\s*[:=]\s*\S+/i', $s)) return 'סיסמה או מפתח בצורת מפתח=ערך';
    return '';
}

/** @return list<string> */
function report_check_text_list(mixed $v, string $path): array {
    if (!is_array($v) || ($v !== [] && !array_is_list($v))) return ["$path: חייב להיות מערך של מחרוזות"];
    if ($v === []) return ["$path: חייב לכלול לפחות פריט אחד"];
    $err = [];
    foreach ($v as $i => $s) {
        if (!is_string($s) || trim($s) === '') $err[] = "$path[$i]: חייב להיות טקסט לא ריק";
    }
    return $err;
}

function report_is_date(mixed $v): bool {
    if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return false;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/**
 * כל המחרוזות בעץ, עם נתיב JSON שלהן. מחזיר רשימת [נתיב, ערך].
 * @return list<array{0: string, 1: string}>
 */
function report_string_leaves(mixed $node, string $path = '$'): array {
    $out = [];
    if (is_string($node)) return [[$path, $node]];
    if (is_array($node)) {
        foreach ($node as $k => $child) {
            $out = array_merge($out, report_string_leaves($child, is_int($k) ? "$path[$k]" : "$path.$k"));
        }
    }
    return $out;
}
