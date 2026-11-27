<?php
/**
 * מודל המצבים לפי החלטת מנהל 00B (ACA-20261125-001).
 *
 * עקרונות:
 * - רשומת תרגול (done) = תרגול בוצע. לא משלימה יחידה ולא מודול.
 * - רשומת verified = יכולת נקודתית שאומתה. לא מאמתת מודול.
 * - השלמת יחידה ואימות מודול נקבעים אך ורק בהחלטת מנהל מפורשת (progress/learning-decisions.json).
 * - חסר = לא ידוע. לא ממירים לאפס, ולא ממציאים שיוך או זמן.
 * - אם קובץ ההחלטות לא תקין — לא נספר דבר מכוחו (שגיאה מוצגת, ללא אישורי דמה).
 */

declare(strict_types=1);

const LP_DECISIONS_PATH = 'progress/learning-decisions.json';
const LP_UNIT_DECISION_STATUSES = ['completed', 'verified'];
const LP_MODULE_DECISION_STATUSES = ['learned_pending_verification', 'verified'];
const LP_DECISION_ID = '/^[A-Za-z0-9_.:-]{1,64}$/';

/**
 * קריאה ואימות של קובץ ההחלטות. מחזיר [ok:bool, decisions:list, error:?string].
 * חוזה: schemaVersion=1, items: decisionId (ייחודי), date, targetType lesson|module, targetId,
 * status, approvedBy, reason (עד 500), evidenceRefs (רשימת מחרוזות, אופציונלי).
 */
function lp_read_decisions(mixed $data): array {
    if (!is_array($data) || ($data['schemaVersion'] ?? null) !== 1 || !isset($data['items']) || !is_array($data['items'])) {
        return [false, [], 'קובץ ההחלטות אינו בחוזה הנתמך (schemaVersion=1)'];
    }
    $out = [];
    $seen = [];
    foreach ($data['items'] as $d) {
        if (!is_array($d)) return [false, [], 'החלטה אינה אובייקט'];
        $id = $d['decisionId'] ?? null;
        $date = $d['date'] ?? null;
        $type = $d['targetType'] ?? null;
        $target = $d['targetId'] ?? null;
        $status = $d['status'] ?? null;
        if (!is_string($id) || !preg_match(LP_DECISION_ID, $id) || isset($seen[$id])) return [false, [], 'decisionId חסר, לא תקין או כפול'];
        $seen[$id] = true;
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [false, [], "תאריך לא תקין בהחלטה $id"];
        if (!is_string($target) || $target === '') return [false, [], "targetId חסר בהחלטה $id"];
        if ($type === 'lesson') {
            if (!preg_match(LP_LESSON_ID, $target) || !in_array($status, LP_UNIT_DECISION_STATUSES, true)) {
                return [false, [], "החלטת יחידה לא תקינה: $id"];
            }
        } elseif ($type === 'module') {
            if (!preg_match(LP_MODULE_ID, $target) || !in_array($status, LP_MODULE_DECISION_STATUSES, true)) {
                return [false, [], "החלטת מודול לא תקינה: $id"];
            }
        } else {
            return [false, [], "targetType לא מוכר בהחלטה $id"];
        }
        $approvedBy = $d['approvedBy'] ?? null;
        if (!is_string($approvedBy) || $approvedBy === '') return [false, [], "approvedBy חסר בהחלטה $id"];
        $reason = $d['reason'] ?? '';
        if (!is_string($reason) || mb_strlen($reason) > 500) return [false, [], "reason לא תקין בהחלטה $id"];
        $refs = $d['evidenceRefs'] ?? [];
        if (!is_array($refs) || count(array_filter($refs, 'is_string')) !== count($refs)) return [false, [], "evidenceRefs לא תקין בהחלטה $id"];
        $out[] = ['decisionId' => $id, 'date' => $date, 'targetType' => $type, 'targetId' => $target,
                  'status' => $status, 'approvedBy' => $approvedBy, 'evidenceRefs' => array_values($refs)];
    }
    // סדר: לפי תאריך ואז לפי סדר הופעה. ההחלטה האחרונה לכל יעד קובעת.
    usort($out, fn($a, $b) => $a['date'] <=> $b['date']);
    return [true, $out, null];
}

/** מצב אפקטיבי לכל יעד: ההחלטה האחרונה לכל targetType+targetId. */
function lp_effective_decisions(array $decisions): array {
    $last = [];
    foreach ($decisions as $d) $last[$d['targetType'] . ':' . $d['targetId']] = $d;
    return $last;
}

/**
 * מצב יחידה: completed/verified רק בהחלטת מנהל. יחידת מעבדה מחייבת ראיה מעשית (evidenceRefs).
 * מחזיר 'completed' | 'verified' | null.
 */
function lp_lesson_state(array $effective, string $lessonId, string $kind): ?string {
    $d = $effective['lesson:' . $lessonId] ?? null;
    if ($d === null) return null;
    if ($kind === 'lab' && $d['evidenceRefs'] === []) return null;   // מעבדה בלי ראיה לא נספרת
    return $d['status'];
}

/** מצב מודול: 'verified' | 'learned_pending_verification' | 'in_progress' | 'not_started'. */
function lp_module_state(array $effective, string $moduleId, bool $hasActivity): string {
    $d = $effective['module:' . $moduleId] ?? null;
    if ($d !== null && $d['status'] === 'verified') return 'verified';
    if ($d !== null && $d['status'] === 'learned_pending_verification') return 'learned_pending_verification';
    return $hasActivity ? 'in_progress' : 'not_started';
}

/** קריאת קובץ ההחלטות מהריפו דרך השרת. קובץ חסר = אין החלטות (לא שגיאה). מחזיר [status, decisions, error]. */
function lp_fetch_decisions(?string $token, ?callable $fetch = null): array {
    if ($token === null) return ['no_token', [], null];
    $url = 'https://api.github.com/repos/' . LP_REPO . '/contents/' . LP_DECISIONS_PATH;
    [$code, $raw] = ($fetch ?? 'lp_http_get')($url, $token);
    if ($code === 404) return ['no_file', [], null];
    if ($code !== 200 || !is_string($raw)) return ['unavailable', [], null];
    [$ok, $items, $err] = lp_read_decisions(json_decode($raw, true));
    return $ok ? ['ok', $items, null] : ['invalid', [], $err];
}
