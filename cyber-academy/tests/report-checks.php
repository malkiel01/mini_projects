<?php
/**
 * בדיקות של מאמת דיווח המורה (lib/validate-report.php). נכלל מתוך tests/run.php — משתמש ב-check() שם.
 * כל ה-fixtures סינתטיים ("לומד דוגמה"). בלי רשת, בלי קבצי ריצה.
 */

require_once __DIR__ . '/../lib/validate-report.php';

$reportDir = __DIR__ . '/fixtures/reports';
$reportFixture = static fn(string $rel): mixed => json_decode((string) file_get_contents("$reportDir/$rel"), true);

// ---------- תקין ----------
$goodReport = $reportFixture('valid.json');
check('דיווח תקין עובר', academy_validate_report($goodReport) === [], implode(' | ', academy_validate_report($goodReport)));
check('דיווח תקין עובר גם מול קטלוג שמכיל את המזהים',
    academy_validate_report($goodReport, ['lessonIds' => ['lesson-demo-01', 'lesson-demo-02'], 'skillIds' => ['skill-demo-a']]) === []);
check('מזהה שיעור שלא בקטלוג נדחה',
    count(array_filter(academy_validate_report($goodReport, ['lessonIds' => ['lesson-demo-01'], 'skillIds' => ['skill-demo-a']]),
        fn($e) => str_contains($e, 'lesson-demo-02') && str_contains($e, 'קוריקולום'))) === 1);
check('מזהה מיומנות שלא בקטלוג נדחה',
    count(array_filter(academy_validate_report($goodReport, ['lessonIds' => ['lesson-demo-01', 'lesson-demo-02'], 'skillIds' => []]),
        fn($e) => str_contains($e, 'skill-demo-a'))) === 1);

// ---------- שבורים: כל fixture חייב להיכשל, והודעה חייבת להזכיר את הבעיה ----------
$expect = [
    'missing-evidence-limits.json' => 'evidenceLimits: שדה חובה חסר',
    'empty-evidence-limits.json' => '$.evidenceLimits: חייב לכלול לפחות פריט אחד',
    'empty-findings.json' => '$.findings: חייב לכלול לפחות פריט אחד',
    'bad-assistance.json' => 'assistanceLevel',
    'has-status.json' => 'status',
    'evidence-dotdot.json' => 'evidenceRefs[0]: הפניה לא בטוחה — מכילה ..',
    'evidence-absolute.json' => 'evidenceRefs[0]: הפניה לא בטוחה — נתיב מוחלט',
    'secret-in-text.json' => 'נראה כמו סוד',
    'bad-teacher.json' => 'teacherId',
    'bad-task-id.json' => 'taskId',
    'bad-date.json' => 'date',
    'unknown-field-score.json' => 'score',
    'duplicate-lesson.json' => 'מזהה כפול',
    'empty-lessons.json' => '$.lessonIds: חייב לכלול לפחות שיעור אחד',
    'evidence-https-not-allowed.json' => 'הפניית https למארח "example.com" אינה ברשימה המאושרת',
];
$brokenFiles = glob("$reportDir/broken/*.json") ?: [];
check('יש fixtures שבורים של דיווח', count($brokenFiles) === count($expect), 'נמצאו ' . count($brokenFiles));
foreach ($expect as $file => $needle) {
    $errs = academy_validate_report($reportFixture("broken/$file"));
    check("דיווח שבור נכשל: $file", $errs !== []);
    check("ההודעה ל-$file מזכירה את הבעיה", implode(' | ', $errs) !== '' && str_contains(implode(' | ', $errs), $needle),
        implode(' | ', $errs));
}

// ---------- מקרים שלא נמצאים כקובץ ----------
check('מערך במקום אובייקט נדחה', academy_validate_report([1, 2]) !== []);
check('null נדחה', academy_validate_report(null) !== []);
check('reportVersion שגוי נדחה', academy_validate_report(array_merge($goodReport, ['reportVersion' => '2.0.0'])) !== []);
// ---------- https: רק מארחים ברשימה המאושרת מהקונפיג (ברירת מחדל ריקה) ----------
$httpsRef = fn(string $ref) => array_merge($goodReport, ['evidenceRefs' => [$ref]]);
check('https בלי רשימה מאושרת נדחה', academy_validate_report($httpsRef('https://example.com/x')) !== []);
check('https למארח ברשימה המאושרת עובר',
    academy_validate_report($httpsRef('https://example.com/x'), null, ['example.com']) === []);
check('https למארח שלא ברשימה נדחה',
    academy_validate_report($httpsRef('https://example.com/x'), null, ['other.example']) !== []);
check('https עם פרטי התחברות בכתובת נדחה גם אם המארח ברשימה',
    academy_validate_report($httpsRef('https://user:pw@example.com/x'), null, ['example.com']) !== []);
check('נתיב יחסי לא מושפע מהרשימה', academy_validate_report($goodReport, null, []) === []);

check('הפניית ראיה עם טקסט מוטמע (לא נתיב) נדחית כשהיא מכילה ?token=',
    academy_validate_report(array_merge($goodReport, ['evidenceRefs' => ['https://example.com/x?token=demo']])) !== []);
check('תאריך 29 בפברואר בשנה לא מעוברת נדחה', !report_is_date('2026-02-29'));
check('תאריך 29 בפברואר בשנה מעוברת עובר', report_is_date('2028-02-29'));
check('טקסט חופשי עם מילה "סיסמה" בלי ערך לא מסומן כסוד', report_secret_hit('הסיסמה לא נמסרה') === '');
check('סוד בשדה המלצה נתפס', academy_validate_report(array_merge($goodReport, ['recommendation' => 'api_key: demo-value'])) !== []);
