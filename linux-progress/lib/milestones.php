<?php
/**
 * אבני דרך מקצועיות — מדדי ניהול בלבד (החלטה 00B סעיף 5). לא מדד מומחיות, ולא שינוי תוכנית.
 * כיסוי מודולים מחושב מהתוכנית. תנאי נוסף (תוצר הערכה) נשאר "ממתין לאישור מנהל" עד שיוגדר במסמך ההחלטה.
 */

declare(strict_types=1);

const LP_MILESTONES = [
    ['id' => 'fundamentals', 'title' => 'יסודות מחשוב', 'coverage' => '01', 'extra' => 'אבחון עצמאי ופתרון תקלה מתועד'],
    ['id' => 'linux-networks', 'title' => 'Linux ורשתות', 'coverage' => '02; 03.01–03.03; 03.05–03.06', 'extra' => 'מעבדת מערכת ורשת עצמאית; 03.04 נדרש אם מוצגת שליטה גם ב-Windows'],
    ['id' => 'web-api', 'title' => 'אבטחת Web/API', 'coverage' => '05; 07; 06.01–06.03; 06.05–06.06', 'extra' => 'בדיקה מורשית, תיקון, אימות ודוח'],
    ['id' => 'basic-pentest', 'title' => 'בדיקות חדירה בסיסיות', 'coverage' => '08.01–08.05; 06.06', 'extra' => 'מעבדה בהיקף מורשה, ראיות ומזעור השפעה'],
    ['id' => 'independent-reports', 'title' => 'בדיקות עצמאיות ודוחות', 'coverage' => '08; 07.07; 11.03', 'extra' => 'הערכה עצמאית מקצה לקצה, דוח ו-retest'],
    ['id' => 'entry-readiness', 'title' => 'מוכנות ראשונית להשתלבות', 'coverage' => '03.01–03.03; 03.05–03.06; 05; 07; 08; 11.02–11.06', 'extra' => 'פרויקט אינטגרטיבי, תיק עבודות והגנה בעל פה; לא הבטחת עבודה'],
    ['id' => 'advanced', 'title' => 'יכולת מקצועית מתקדמת', 'coverage' => '04; 08; 09', 'extra' => 'תרגיל ארגוני רב-שלבי מורשה עם דוח ובקרת איכות'],
    ['id' => 'independent-research', 'title' => 'מחקר אבטחה עצמאי', 'coverage' => '04.06; 10; 11.01', 'extra' => 'מחקר שחזור עצמאי, root cause ודיווח אחראי'],
];

/**
 * מרחיב טווח מודולים לרשימת מזהים לפי התוכנית. "NN" = כל מודולי המסלול, "NN.NN" = מודול, "A–B" = טווח.
 * מזהה שלא קיים בתוכנית מתעלמים ממנו (לא ממציאים).
 */
function lp_expand_coverage(string $spec, array $modules): array {
    $ids = array_keys($modules);
    $out = [];
    foreach (array_map('trim', explode(';', $spec)) as $part) {
        if ($part === '') continue;
        if (str_contains($part, '–')) {
            [$a, $b] = array_map('trim', explode('–', $part, 2));
            foreach ($ids as $id) if ($id >= $a && $id <= $b) $out[$id] = true;
        } elseif (strlen($part) === 2) {
            foreach ($ids as $id) if (str_starts_with($id, $part . '.')) $out[$id] = true;
        } elseif (isset($modules[$part])) {
            $out[$part] = true;
        }
    }
    return array_keys($out);
}

/** כיסוי אבני הדרך מול המצבים הנוכחיים של המודולים. $moduleStates: moduleId => state. */
function lp_milestones(array $modules, array $moduleStates): array {
    $out = [];
    foreach (LP_MILESTONES as $m) {
        $req = lp_expand_coverage($m['coverage'], $modules);
        $verified = 0; $learned = 0; $touched = 0;
        foreach ($req as $id) {
            $s = $moduleStates[$id] ?? 'not_started';
            if ($s === 'verified') $verified++;
            if ($s === 'verified' || $s === 'learned_pending_verification') $learned++;
            if ($s !== 'not_started') $touched++;
        }
        $total = count($req);
        $coverageVerified = $total > 0 && $verified === $total;
        $out[] = [
            'id' => $m['id'],
            'title' => $m['title'],
            'requiredModules' => $total,
            'verifiedModules' => $verified,
            'learnedModules' => $learned,
            'state' => $total === 0 ? 'no_coverage' : ($coverageVerified ? 'coverage_verified' : ($touched > 0 ? 'in_progress' : 'not_started')),
            'extraCondition' => $m['extra'],
            'extraStatus' => $coverageVerified ? 'ממתין לאישור מנהל' : 'טרם הושלם כיסוי המודולים',
        ];
    }
    return $out;
}
