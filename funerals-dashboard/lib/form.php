<?php
declare(strict_types=1);

/**
 * פרוסה 02 — הטופס החכם: מקור ושעה לכל בדיקה, "הושלם" רק באדם, ויומן התיק.
 * נטען מסוף lib.php. לא נקודת קצה: גישה ישירה → 404.
 *
 * הכלל המרכזי — finalAllowed($via): רק אדם (ממשק, שטח) או הייבוא הקיים מסמנים בדיקה כ"הושלמה".
 * מקור אוטומטי (Dropbox, צ'אט חיצוני, AI) שמנסה — נשמר "מסמך קיים" (לאימות), וההצעה שלו נכתבת בהערה.
 * פרוסות 05 ו-06 קוראות ל-formSaveCase() עם ה-via שלהן ומקבלות את הכלל בלי קוד נוסף.
 *
 * לא משנים כאן את רשימת הבדיקות, את השדות או את הסטטוסים — הם של הבעלים. מוסיפים ל-checks.<key> רק
 * by (שם), via (מקור), at (שעה) — נכתבים בשרת בלבד; normalize() כבר זורק אותם מהקלט.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

/** כל המקורות שמותר לכתוב ב-via. */
const FORM_VIAS = ['ui', 'field', 'dropbox', 'api', 'ai', 'import'];
/** סטטוסים שפירושם "הושלם / נקבע בידי אדם". */
const FORM_FINAL_STATUSES = ['verified', 'reported', 'not_applicable'];
/** שמות הסטטוסים לתצוגה (זהים ל-index.html) — להערה שנכתבת כשהצעה אוטומטית נדחית ל"לאימות". */
const FORM_STATUS_LABELS = ['unknown' => 'טרם נבדק', 'document_present' => 'מסמך קיים', 'verified' => 'מאומת',
    'unreadable' => 'לא קריא', 'missing' => 'חסר', 'reported' => 'עודכן ידנית', 'not_applicable' => 'לא רלוונטי'];
const FORM_VIA_LABELS = ['ui' => 'ממשק', 'field' => 'שטח', 'dropbox' => 'Dropbox', 'api' => 'צ\'אט חיצוני',
    'ai' => 'AI', 'import' => 'ייבוא'];
/** השדות שעדכון ידני שלהם מוגן מפני ייבוא חוזר (כמו ב-api.php לפני הפרוסה). */
const FORM_MANUAL_FIELDS = ['name', 'date', 'time', 'origin', 'team', 'grave', 'notes', 'folder_url', 'payments'];

/** האם המקור רשאי לסמן בדיקה "הושלמה" (מאומת / עודכן ידנית / לא רלוונטי). הכרעה ה7. */
function finalAllowed(string $via): bool {
    return in_array($via, ['ui', 'field', 'import'], true);
}

function formViaValid(string $via): string {
    if (!in_array($via, FORM_VIAS, true)) throw new InvalidArgumentException('via לא מוכר: ' . $via);
    return $via;
}

/**
 * מחיל מקור ושעה על הבדיקות ששונו, ואת כלל "הושלם רק באדם".
 * $new — הבדיקות אחרי normalize() (בלי by/via/at מהקלט). $old — הבדיקות השמורות (או null לתיק חדש).
 * בדיקה שלא השתנתה (סטטוס, ערך, הערה) — נשארת בדיוק כמו שהייתה, כולל המקור הישן שלה.
 * בדיקה ריקה חדשה ("טרם נבדק" בלי ערך והערה) — בלי מקור: אף אחד לא אמר עליה כלום.
 */
function formApplyChecks(array $new, ?array $old, string $via, string $by, ?string $at = null): array {
    formViaValid($via);
    $at ??= gmdate('c');
    $by = mb_substr(trim($by), 0, 200);
    foreach ($new as $key => &$check) {
        $prev = is_array($old[$key] ?? null) ? $old[$key] : null;
        if ($prev !== null && ($prev['status'] ?? 'unknown') === $check['status']
            && (string)($prev['value'] ?? '') === $check['value'] && (string)($prev['note'] ?? '') === $check['note']) {
            $check = $prev;
            continue;
        }
        if ($prev === null && $check['status'] === 'unknown' && $check['value'] === '' && $check['note'] === '') continue;
        if (!finalAllowed($via) && in_array($check['status'], FORM_FINAL_STATUSES, true)) {
            $proposal = 'הוצע (' . FORM_VIA_LABELS[$via] . '): ' . FORM_STATUS_LABELS[$check['status']]
                . ($check['value'] !== '' ? ' — ' . $check['value'] : '');
            if ($prev !== null && in_array($prev['status'] ?? '', FORM_FINAL_STATUSES, true)) {
                // אדם כבר סגר את הבדיקה. מקור אוטומטי לא פותח אותה מחדש ולא מחליף את הערך — ההצעה נשמרת בהערה בלבד,
                // ו"מי אמר שזה בסדר" נשאר האדם.
                if ($check['value'] !== (string)($prev['value'] ?? '') && !str_contains((string)($prev['note'] ?? ''), $proposal)) {
                    $prev['note'] = mb_substr(trim((string)($prev['note'] ?? '') . ' · ' . $proposal, ' ·'), 0, 800);
                }
                $check = $prev;
                continue;
            }
            $check['note'] = mb_substr($check['note'] !== '' ? $proposal . ' · ' . $check['note'] : $proposal, 0, 800);
            $check['status'] = 'document_present';
        }
        // התאימות לייבוא הקיים: importRecords() לא דורס בדיקה שהמקור שלה "עדכון ידני"
        if (($via === 'ui' || $via === 'field') && ($check['status'] !== 'unknown' || $check['value'] !== '' || $check['note'] !== '')) {
            $check['source'] = 'עדכון ידני';
        }
        $check['by'] = $by;
        $check['via'] = $via;
        $check['at'] = $at;
    }
    unset($check);
    return $new;
}

/**
 * מקור הבדיקה (via) → מקור האירוע ב-events (EVENT_SOURCES של 01: ui / import / api / system).
 * שטח הוא אדם בממשק; Dropbox — סנכרון של המערכת; צ'אט חיצוני ו-AI — דרך ה-API.
 */
function formEventSource(string $via): string {
    return ['ui' => 'ui', 'field' => 'ui', 'import' => 'import', 'api' => 'api', 'ai' => 'api', 'dropbox' => 'system'][formViaValid($via)];
}

/**
 * שמירת תיק — הדרך היחידה לכתוב תיק בודד, לכל מקור (ממשק, שטח, API, AI).
 * עושה מה ש-api.php עשה עד הפרוסה הזו (manual_fields) ומוסיפה את המקור לבדיקות; store() של 01 שומר ורושם ב-events.
 * $actor — ['id' => user id או null, 'actor' => מחרוזת] (ctx()['actor'] של 01). תיק של ארגון אחר → 404 (existing()).
 * $authorize($old, $item) — בדיקת ההרשאה של הקורא (need(...)), לפני שנשמר דבר.
 */
function formSaveCase(PDO $pdo, array $raw, string $via, array $actor, int $orgId, ?callable $authorize = null): array {
    formViaValid($via);
    $item = normalize($raw, $orgId);
    $old = existing($pdo, $item['id'], $orgId);
    if ($authorize !== null) $authorize($old, $item);
    // פרוסה 11: "מאומת" בידי אדם — רק למי שיש לו הרשאת אישור בדיקה (תפקיד גמיש). מקור אוטומטי ממילא לא מאשר (finalAllowed).
    if (($via === 'ui' || $via === 'field') && isset($GLOBALS['fd_ctx'])) {
        foreach ($item['checks'] as $k => $c) {
            if ($c['status'] === 'verified' && ($old['checks'][$k]['status'] ?? '') !== 'verified') {
                need('case.approve', ['org_id' => $orgId, 'id' => $item['id']]);
                break;
            }
        }
    }
    if ($old !== null) {
        foreach (FORM_MANUAL_FIELDS as $field) {
            if (($item[$field] ?? null) !== ($old[$field] ?? null)) $item['manual_fields'][] = $field;
        }
        $item['manual_fields'] = array_values(array_unique(array_merge($old['manual_fields'] ?? [], $item['manual_fields'])));
    } else {
        $item['manual_fields'] = FORM_MANUAL_FIELDS;
    }
    $item['checks'] = formApplyChecks($item['checks'], $old['checks'] ?? null, $via, (string)($actor['actor'] ?? ''));
    store($pdo, $item, $old, $orgId, $actor, formEventSource($via), 'save');
    return $item;
}

function formTableExists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $st->execute([$table]);
    return $st->fetchColumn() !== false;
}
function formColumnExists(PDO $pdo, string $table, string $column): bool {
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (($c['name'] ?? '') === $column) return true;
    }
    return false;
}

/**
 * מפרק diff של אירוע לרשימת שינויים {field, before, after}. סובלני לכמה צורות:
 * {"f":[לפני,אחרי]}, {"f":{"from":..,"to":..}} (או before/after, old/new), ומקונן ({"checks":{"x":{"status":[..]}}})
 * — שדה מקונן מקבל שם עם נקודות ("checks.x.status").
 */
function formDiffChanges(mixed $diff, string $prefix = ''): array {
    if (is_string($diff)) $diff = json_decode($diff, true);
    if (!is_array($diff)) return [];
    $out = [];
    foreach ($diff as $field => $v) {
        $name = $prefix === '' ? (string)$field : $prefix . '.' . $field;
        if (is_array($v) && array_is_list($v) && count($v) === 2) {
            [$b, $a] = $v;
            // בדיקה (caseDiff של 01): [{status,value,note}, {status,value,note}] → שורה לכל תת-שדה שהשתנה
            if ((is_array($b) || $b === null) && is_array($a) && !array_is_list($a)) {
                foreach (array_unique(array_merge(array_keys($b ?? []), array_keys($a))) as $sub) {
                    // בדיקה שלא הייתה בגוף התיק ונשמרה ריקה ("טרם נבדק") — לא שינוי
                    $bv = $b[$sub] ?? ($sub === 'status' ? 'unknown' : '');
                    if ($bv !== ($a[$sub] ?? '')) $out[] = ['field' => $name . '.' . $sub, 'before' => $b[$sub] ?? null, 'after' => $a[$sub] ?? null];
                }
                continue;
            }
            // השוואה רופפת: 402 ו-402.0 (סכום אחרי JSON), '' ו-null — אותו ערך
            if ($b == $a) continue;
            $out[] = ['field' => $name, 'before' => $b, 'after' => $a];
            continue;
        }
        if (is_array($v)) {
            foreach ([['from', 'to'], ['before', 'after'], ['old', 'new']] as [$b, $a]) {
                if (array_key_exists($b, $v) || array_key_exists($a, $v)) {
                    $out[] = ['field' => $name, 'before' => $v[$b] ?? null, 'after' => $v[$a] ?? null];
                    continue 2;
                }
            }
            array_push($out, ...formDiffChanges($v, $name));
            continue;
        }
        $out[] = ['field' => $name, 'before' => null, 'after' => $v];
    }
    return $out;
}

/**
 * יומן התיק — 50 האחרונים, החדש קודם. null = אין תיק כזה (או שהוא של ארגון אחר) → 404 אצל הקורא.
 * $orgId — הארגון הפעיל (scope() של 01); null = בלי סינון ארגון (בדיקות יחידה בלבד).
 * המקור: טבלת events של 01. כשאין אותה — טבלת audit הישנה (מי ומתי, בלי פרטי השינוי).
 */
function formHistory(PDO $pdo, string $caseId, ?int $orgId, int $limit = 50): ?array {
    $limit = max(1, min(50, $limit));
    $byOrg = $orgId !== null && formColumnExists($pdo, 'funerals', 'org_id');
    $st = $pdo->prepare('SELECT id FROM funerals WHERE id=?' . ($byOrg ? ' AND org_id=?' : ''));
    $st->execute($byOrg ? [$caseId, $orgId] : [$caseId]);
    if ($st->fetchColumn() === false) return null;
    if (formTableExists($pdo, 'events')) {
        $sql = 'SELECT id, at, actor, source, action, diff FROM events WHERE case_id=?'
            . ($orgId !== null ? ' AND org_id=?' : '') . ' ORDER BY id DESC LIMIT ' . $limit;
        $st = $pdo->prepare($sql);
        $st->execute($orgId !== null ? [$caseId, $orgId] : [$caseId]);
        return array_map(static fn(array $r) => ['at' => (string)$r['at'], 'actor' => (string)($r['actor'] ?? ''),
            'source' => (string)($r['source'] ?? ''), 'action' => (string)($r['action'] ?? ''),
            'changes' => formDiffChanges($r['diff'] ?? null)], $st->fetchAll(PDO::FETCH_ASSOC));
    }
    $st = $pdo->prepare('SELECT at, actor, action FROM audit WHERE funeral_id=? ORDER BY id DESC LIMIT ' . $limit);
    $st->execute([$caseId]);
    return array_map(static fn(array $r) => ['at' => (string)$r['at'], 'actor' => (string)$r['actor'], 'source' => '',
        'action' => (string)$r['action'], 'changes' => []], $st->fetchAll(PDO::FETCH_ASSOC));
}

/** נקודת הקצה history (GET ?action=history&id=…). תיק שאינו קיים או של ארגון אחר — 404, לא 403 (ה5). */
function formHistoryAction(PDO $pdo, string $method, ?int $orgId): never {
    if ($method !== 'GET') reply(405, ['error' => 'שיטה לא מורשית']);
    $id = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $id)) reply(400, ['error' => 'מזהה תיק אינו תקין']);
    $rows = formHistory($pdo, $id, $orgId);
    if ($rows === null) reply(404, ['error' => 'התיק לא נמצא']);
    reply(200, ['id' => $id, 'events' => $rows]);
}
