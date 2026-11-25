<?php
declare(strict_types=1);

/**
 * פרוסה 04 — עובד שטח בטלפון: מסך "היום", צילום קבר, שליחת קובץ ועדכון (אפיון 04).
 * נטען מ-api.php בשתי שורות (fieldHandles / fieldAction), אחרי הזהות, ה-CSRF והארגון הפעיל. לא נקודת קצה: גישה ישירה → 404.
 *
 * - הרשאות — רק דרך can()/need() של lib/access.php: צפייה case.view, עדכון והעלאה case.track (שטח, משרד, מנהל).
 * - עדכון — רק TRACK_FIELDS (בדיקות, הערה, מיקום קבר), ונשמר דרך formSaveCase(..., 'field', ...) של 02.
 * - העלאה — דרך Dropbox של 03 (fieldDropboxUploader). מזהה חד-פעמי לכל העלאה (field_uploads): אותו מזהה פעמיים → קובץ אחד.
 *   קובץ שסוגו מתאים לבדיקה → הבדיקה "לאימות" (document_present), via: field — לא מורידים בדיקה שאדם כבר סגר.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

const FIELD_ACTIONS = ['field_today', 'field_search', 'field_case', 'field_save', 'field_upload'];
const FIELD_TZ = 'Asia/Jerusalem';
const FIELD_MAX_FILE = 50 * 1024 * 1024;
/** סוגי הקבצים שעובד שטח בוחר. check — הבדיקה שקובץ מהסוג הזה מעביר ל"לאימות". 'license' — הסוג שכבר בתיקים. */
const FIELD_KINDS = [
    'grave_photo'        => ['label' => 'צילום קבר', 'check' => null],
    'license'            => ['label' => 'רישיון קבורה', 'check' => 'license_document'],
    'identity'           => ['label' => 'מסמך זיהוי נפטר', 'check' => 'identity_document'],
    'payment'            => ['label' => 'אסמכתת תשלום', 'check' => 'payment_evidence'],
    'partner_identity'   => ['label' => 'זיהוי בן/בת זוג', 'check' => 'partner_identity'],
    'grave_purchase'     => ['label' => 'רכישת קבר נוסף', 'check' => 'grave_purchase'],
    'national_insurance' => ['label' => 'דיווח לביטוח לאומי', 'check' => 'national_insurance'],
    'other'              => ['label' => 'אחר', 'check' => null],
];
/** ההודעה שהעובד רואה כש-Dropbox של הארגון לא מוכן (W7). */
const FIELD_DBX_DOWN = 'Dropbox של הארגון לא מחובר — פנה למנהל';

final class FieldUploadError extends RuntimeException {
    public function __construct(public readonly int $status, public readonly string $tag, string $msg) { parent::__construct($msg); }
}

function fieldHandles(string $action): bool { return in_array($action, FIELD_ACTIONS, true); }

// הטבלה field_uploads — מיגרציה v3 ב-lib/schema.php (migrateV3), עם גיבוי לפני הקפיצה.
/** חלון "בשליחה": בקשה שנייה עם אותו מזהה בזמן שהראשונה עוד מעלה (עד 50MB בקליטה חלשה) — 409 in_progress, לא העלאה מקבילה. */
const FIELD_PENDING_WINDOW = 900;

/* ---------- היום ומחר, חיפוש ---------- */

/** [היום, מחר] בשעון ישראל, YYYY-MM-DD. */
function fieldDays(?DateTimeImmutable $now = null): array {
    $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(FIELD_TZ));
    return [$now->format('Y-m-d'), $now->modify('+1 day')->format('Y-m-d')];
}

/** מספר הקבצים ומי מהם צילום קבר, לתיקים — מטבלת files של 03 (אם עוד אין — אפס). */
function fieldFileStats(PDO $pdo, int $orgId, array $caseIds): array {
    $out = [];
    foreach ($caseIds as $id) $out[$id] = ['files' => 0, 'grave_photo' => false];
    if (!$caseIds || !formTableExists($pdo, 'files')) return $out;
    $in = implode(',', array_fill(0, count($caseIds), '?'));
    $st = $pdo->prepare("SELECT case_id, COUNT(*) AS n, MAX(kind = 'grave_photo') AS g FROM files
        WHERE org_id = ? AND deleted = 0 AND case_id IN ($in) GROUP BY case_id");
    $st->execute([$orgId, ...$caseIds]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['case_id']] = ['files' => (int)$r['n'], 'grave_photo' => (bool)$r['g']];
    return $out;
}

/** "מה חסר מהשטח": צילום קבר, מיקום קבר. הבדיקות הפתוחות מחושבות בדפדפן (form.js), מאותו גוף תיק. */
function fieldNeeds(array $case, array $stats): array {
    $need = [];
    if (!$stats['grave_photo']) $need[] = 'grave_photo';
    if (trim((string)($case['grave'] ?? '')) === '') $need[] = 'grave';
    return $need;
}

/** תיק לרשימה: הגוף (בלי תשלומים — שטח לא צריך אותם במסך) + מה חסר. */
function fieldCard(array $case, array $stats): array {
    return ['id' => $case['id'], 'name' => (string)$case['name'], 'month' => (string)($case['month'] ?? ''),
        'date' => (string)($case['date'] ?? ''), 'time' => (string)($case['time'] ?? ''),
        'origin' => (string)($case['origin'] ?? ''), 'grave' => (string)($case['grave'] ?? ''),
        'checks' => $case['checks'] ?? [], 'files' => $stats['files'], 'needs' => fieldNeeds($case, $stats)];
}

/** תיקי היום ומחר של הארגון, לפי תאריך ושעה (תיק בלי שעה — בסוף היום שלו). */
function fieldToday(PDO $pdo, int $orgId, ?DateTimeImmutable $now = null): array {
    [$today, $tomorrow] = fieldDays($now);
    // תאריך הלוויה נמצא בגוף התיק (JSON). מסננים בשאילתה לפי החודשים הרלוונטיים, ובקוד לפי התאריך המדויק.
    $months = array_values(array_unique([substr($today, 0, 7), substr($tomorrow, 0, 7)]));
    $in = implode(',', array_fill(0, count($months), '?'));
    $st = $pdo->prepare("SELECT body FROM funerals WHERE org_id = ? AND (month IN ($in) OR body LIKE ? OR body LIKE ?)");
    $st->execute([$orgId, ...$months, '%"date":"' . $today . '"%', '%"date":"' . $tomorrow . '"%']);
    $cases = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $b) {
        $c = json_decode((string)$b, true);
        if (is_array($c) && in_array($c['date'] ?? '', [$today, $tomorrow], true)) $cases[$c['id']] = $c;
    }
    usort($cases, fn($a, $b) => [$a['date'], ($a['time'] ?? '') === '' ? '99:99' : $a['time'], $a['name']]
        <=> [$b['date'], ($b['time'] ?? '') === '' ? '99:99' : $b['time'], $b['name']]);
    $stats = fieldFileStats($pdo, $orgId, array_column($cases, 'id'));
    return ['today' => $today, 'tomorrow' => $tomorrow,
        'cases' => array_map(fn($c) => fieldCard($c, $stats[$c['id']]), $cases)];
}

/** חיפוש בשם, בכל החודשים של הארגון. עד 30, החדש קודם. */
function fieldSearch(PDO $pdo, int $orgId, string $q): array {
    $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
    if (mb_strlen($q) < 2) return [];
    $st = $pdo->prepare('SELECT body FROM funerals WHERE org_id = ?');
    $st->execute([$orgId]);
    $hits = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $b) {
        $c = json_decode((string)$b, true);
        if (is_array($c) && mb_stripos(preg_replace('/\s+/u', ' ', (string)($c['name'] ?? '')) ?? '', $q) !== false) $hits[] = $c;
    }
    usort($hits, fn($a, $b) => [$b['date'] ?? '', $b['time'] ?? ''] <=> [$a['date'] ?? '', $a['time'] ?? '']);
    $hits = array_slice($hits, 0, 30);
    $stats = fieldFileStats($pdo, $orgId, array_column($hits, 'id'));
    return array_map(fn($c) => fieldCard($c, $stats[$c['id']]), $hits);
}

/** סוגי הקבצים לבחירה: הקבועים, ואחריהם כל סוג שכבר בתיק (documents[].kind) ולא ברשימה. */
function fieldKinds(array $case): array {
    $out = [];
    foreach (FIELD_KINDS as $k => $d) $out[] = ['kind' => $k, 'label' => $d['label'], 'check' => $d['check']];
    $known = array_keys(FIELD_KINDS);
    foreach (($case['documents'] ?? []) as $doc) {
        $k = cleanText($doc['kind'] ?? '', 80);
        if ($k === '' || in_array($k, $known, true)) continue;
        $known[] = $k;
        array_splice($out, count($out) - 1, 0, [['kind' => $k, 'label' => $k, 'check' => null]]);   // לפני "אחר"
    }
    return $out;
}

/** הקבצים של התיק (מ-files של 03), החדש קודם. */
function fieldCaseFiles(PDO $pdo, int $orgId, string $caseId): array {
    if (!formTableExists($pdo, 'files')) return [];
    $st = $pdo->prepare('SELECT id, name, kind, added_via, added_by, created_at FROM files WHERE org_id = ? AND case_id = ? AND deleted = 0 ORDER BY id DESC LIMIT 50');
    $st->execute([$orgId, $caseId]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'kind' => $r['kind'], 'added_via' => $r['added_via'],
        'added_by' => $r['added_by'], 'at' => $r['created_at'], 'link' => 'api.php?action=file_view&id=' . (int)$r['id']], $st->fetchAll(PDO::FETCH_ASSOC));
}

/* ---------- עדכון מהשטח ---------- */

/**
 * עדכון של עובד שטח: רק id + TRACK_FIELDS. כל שדה אחר בקלט → 403 (W5), גם אם ערכו זהה — שטח לא שולח אותו.
 * השאר נלקח מהתיק השמור, והשמירה — formSaveCase(..., 'field', ...).
 */
function fieldSave(PDO $pdo, array $in, array $actor, int $orgId, ?callable $authorize = null): array {
    foreach (array_keys($in) as $k) {
        if ($k !== 'id' && !in_array($k, TRACK_FIELDS, true)) reply(403, ['error' => 'מהשטח אפשר לעדכן רק בדיקות, הערה ומיקום קבר']);
    }
    $id = (string)($in['id'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $id)) reply(400, ['error' => 'מזהה תיק אינו תקין']);
    $old = existing($pdo, $id, $orgId);
    if ($old === null) reply(404, ['error' => 'התיק לא נמצא']);
    $raw = $old;
    if (array_key_exists('notes', $in)) $raw['notes'] = $in['notes'];
    if (array_key_exists('grave', $in)) $raw['grave'] = $in['grave'];
    if (array_key_exists('checks', $in)) {
        if (!is_array($in['checks'])) reply(400, ['error' => 'בדיקות לא תקינות']);
        foreach ($in['checks'] as $key => $c) {
            if (!is_string($key) || !is_array($c)) reply(400, ['error' => 'שדה בדיקה אינו תקין']);
            $prev = is_array($old['checks'][$key] ?? null) ? $old['checks'][$key] : [];
            $raw['checks'][$key] = ['status' => $c['status'] ?? ($prev['status'] ?? 'unknown'),
                'value' => $c['value'] ?? ($prev['value'] ?? ''), 'note' => $c['note'] ?? ($prev['note'] ?? ''),
                'source' => $prev['source'] ?? ''];
        }
    }
    return formSaveCase($pdo, $raw, 'field', $actor, $orgId, $authorize);
}

/* ---------- העלאה ---------- */

/** שם הקובץ בתיקייה: צילום קבר — "קבר <שם> <YYYY-MM-DD HHMM>.jpg"; אחר — השם המקורי (נקי). */
function fieldFileName(string $kind, array $case, string $original, ?DateTimeImmutable $now = null): string {
    if ($kind === 'grave_photo') {
        $t = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(FIELD_TZ));
        $name = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '_', (string)$case['name']) ?? '');
        return 'קבר ' . $name . ' ' . $t->format('Y-m-d Hi') . '.jpg';
    }
    $n = cleanText(basename(str_replace('\\', '/', $original)), 200);
    return $n !== '' ? $n : 'קובץ.bin';
}

function fieldUploadIdValid(string $id): bool { return (bool)preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $id); }

/**
 * העלאה מהשטח, עם מזהה חד-פעמי. $uploader — fn(PDO, int org, array case, string tmp, string name, string kind, array actor, string uploadId): array
 * שמחזירה ['file_id' => int|null, 'name' => string, 'link' => string|null], וזורקת FieldUploadError.
 * מחזירה [status, body]. אותו upload_id שכבר הצליח → אותה תשובה, בלי לקרוא ל-uploader (W3).
 */
function fieldUpload(PDO $pdo, int $orgId, string $caseId, string $uploadId, string $kind, string $tmp, string $original,
                     array $actor, callable $uploader, ?DateTimeImmutable $now = null): array {
    if (!fieldUploadIdValid($uploadId)) return [400, ['error' => 'מזהה העלאה אינו תקין']];
    if (!preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $caseId)) return [400, ['error' => 'מזהה תיק אינו תקין']];
    $case = existing($pdo, $caseId, $orgId);
    if ($case === null) return [404, ['error' => 'התיק לא נמצא']];
    // סוג — רק מהרשימה של התיק (fieldKinds); אחר → "אחר"
    $kind = cleanText($kind, 80);
    if (!in_array($kind, array_column(fieldKinds($case), 'kind'), true)) $kind = 'other';
    $nowS = gmdate('c');

    // תופסים את המזהה בטרנזקציה קצרה: done → התשובה השמורה; pending טרי → "בשליחה"; אחרת — שלנו.
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $st = $pdo->prepare('SELECT * FROM field_uploads WHERE org_id = ? AND upload_id = ?');
        $st->execute([$orgId, $uploadId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row !== null && $row['case_id'] !== $caseId) { $pdo->exec('COMMIT'); return [409, ['error' => 'מזהה ההעלאה שייך לתיק אחר', 'code' => 'upload_id_conflict']]; }
        if ($row !== null && $row['status'] === 'done') {
            $pdo->exec('COMMIT');
            return [200, ['ok' => true, 'duplicate' => true] + (json_decode((string)$row['result'], true) ?: [])];
        }
        if ($row !== null && $row['status'] === 'pending' && strtotime((string)$row['updated_at']) > time() - FIELD_PENDING_WINDOW) {
            $pdo->exec('COMMIT');
            return [409, ['error' => 'הקובץ הזה כבר בשליחה', 'code' => 'in_progress']];
        }
        $pdo->prepare('INSERT INTO field_uploads(org_id, upload_id, case_id, kind, status, user_id, created_at, updated_at) VALUES (?,?,?,?,\'pending\',?,?,?)
            ON CONFLICT(org_id, upload_id) DO UPDATE SET status = \'pending\', kind = excluded.kind, user_id = excluded.user_id, error = NULL, updated_at = excluded.updated_at')
            ->execute([$orgId, $uploadId, $caseId, $kind, $actor['id'] ?? null, $nowS, $nowS]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->exec('ROLLBACK');
        throw $e;
    }

    $name = fieldFileName($kind, $case, $original, $now);
    try {
        $file = $uploader($pdo, $orgId, $case, $tmp, $name, $kind, $actor, $uploadId);
    } catch (FieldUploadError $e) {
        $pdo->prepare("UPDATE field_uploads SET status = 'failed', error = ?, updated_at = ? WHERE org_id = ? AND upload_id = ?")
            ->execute([$e->tag, gmdate('c'), $orgId, $uploadId]);
        return [$e->status, ['error' => $e->getMessage(), 'code' => $e->tag]];
    }

    // שיוך לבדיקה (W4), האירוע וסימון "הושלם" — בטרנזקציה אחת, על התיק כפי שהוא **עכשיו** (קורא מחדש אחרי ההעלאה):
    // עריכה של מישהו אחר בזמן ההעלאה (שם, תשלומים, הערה) נשמרת; משנים רק את הבדיקה המתאימה.
    $check = FIELD_KINDS[$kind]['check'] ?? null;
    $checkResult = null;
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $fresh = existing($pdo, $caseId, $orgId) ?? $case;
        if ($check !== null) {
            $prev = $fresh['checks'][$check] ?? [];
            $status = (string)($prev['status'] ?? 'unknown');
            if (in_array($status, ['unknown', 'missing', 'unreadable'], true)) {
                $raw = $fresh;
                $raw['checks'][$check] = ['status' => 'document_present', 'value' => (string)($prev['value'] ?? ''),
                    'note' => trim((string)($prev['note'] ?? '')) === '' ? 'נשלח מהשטח: ' . $file['name'] : (string)$prev['note'],
                    'source' => (string)($prev['source'] ?? '')];
                formSaveCase($pdo, $raw, 'field', $actor, $orgId);
                $checkResult = ['key' => $check, 'status' => 'document_present'];
            } else {
                $checkResult = ['key' => $check, 'status' => $status];
            }
        }
        // אירוע אחד לכל מזהה — ניסיון חוזר אחרי בקשה שנקטעה לא רושם שוב
        $seen = $pdo->prepare("SELECT 1 FROM events WHERE org_id = ? AND case_id = ? AND action = 'field_upload' AND diff LIKE ?");
        $seen->execute([$orgId, $caseId, '%"upload_id":[null,"' . $uploadId . '"]%']);
        if ($seen->fetchColumn() === false) {
            recordEvent($orgId, $caseId, $actor, 'ui', 'field_upload', ['file' => [null, $file['name']], 'kind' => [null, $kind],
                'via' => [null, 'field'], 'upload_id' => [null, $uploadId]]);
        }
        $result = ['file' => $file, 'check' => $checkResult];
        $pdo->prepare("UPDATE field_uploads SET status = 'done', result = ?, updated_at = ? WHERE org_id = ? AND upload_id = ?")
            ->execute([json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), gmdate('c'), $orgId, $uploadId]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->exec('ROLLBACK');
        throw $e;
    }
    return [200, ['ok' => true, 'duplicate' => false] + $result];
}

/**
 * ההעלאה ל-Dropbox דרך הממשק היציב של פרוסה 03: dropboxUploadToCase() — תיקיית התיק (נוצרת בהעלאה הראשונה), העלאה,
 * ושורה ב-files עם added_via = field. אותו upload_id גם שם, כך שגם אם הבקשה נקטעה אחרי ההעלאה — ניסיון חוזר לא מעלה שוב.
 * כל עוד 03 לא בקוד, או ש-Dropbox של הארגון לא מוכן — "Dropbox לא מחובר" (409), והקובץ נשאר בתור במכשיר.
 */
function fieldDropboxUploader(PDO $pdo, int $orgId, array $case, string $tmp, string $name, string $kind, array $actor, ?string $uploadId = null): array {
    if (!function_exists('dropboxUploadToCase')) throw new FieldUploadError(409, 'dropbox_not_connected', FIELD_DBX_DOWN);
    try {
        $f = dropboxUploadToCase($pdo, $orgId, $case, $tmp, $name, $actor, 'field', $uploadId);
    } catch (Throwable $e) {
        if (!is_a($e, 'DbxError')) throw $e;
        $tag = (string)$e->tag;
        if (in_array($tag, ['not_connected', 'no_root', 'reauth', 'no_app_key'], true)) throw new FieldUploadError(409, 'dropbox_not_connected', FIELD_DBX_DOWN);
        if ($tag === 'network') throw new FieldUploadError(503, 'network', 'Dropbox לא זמין כרגע — הקובץ יישלח שוב');
        if (str_contains($tag, 'insufficient_space')) throw new FieldUploadError(507, 'insufficient_space', 'אין מספיק מקום בחשבון ה-Dropbox — פנה למנהל');
        throw new FieldUploadError(502, 'dropbox_error', 'ההעלאה ל-Dropbox נכשלה — נסה שוב');
    }
    // סוג המסמך — של השטח (03 משאיר אותו ריק)
    $pdo->prepare("UPDATE files SET kind = ? WHERE id = ? AND org_id = ? AND kind = ''")->execute([$kind, (int)$f['id'], $orgId]);
    return ['file_id' => (int)$f['id'], 'name' => (string)$f['name'], 'link' => 'api.php?action=file_view&id=' . (int)$f['id']];
}

/** שעת הצילום מהדפדפן (ms מ-1970) — לשם "קבר <שם> <תאריך שעה>". מחוץ לשבוע אחורה / 10 דקות קדימה → שעת השרת. */
function fieldTakenAt(mixed $ms): ?DateTimeImmutable {
    if (!is_numeric($ms)) return null;
    $sec = (int)floor((float)$ms / 1000);
    if ($sec < time() - 7 * 86400 || $sec > time() + 600) return null;
    return (new DateTimeImmutable('@' . $sec));
}

/* ---------- נקודות הקצה ---------- */

/**
 * פרוסה 12 — "רק תיקים שהוקצו לי" (case.view_assigned) במסך השטח: היום/חיפוש מחזירים רק את התיקים שהוקצו, ותיק בודד — רק אם
 * הוקצה (אחרת 404, כמו ב-ext). null = רואה הכול; [] = רואה רק שהוקצו, ואין.
 */
function fieldAssignedIds(PDO $pdo, int $orgId): ?array {
    if (can('case.view') || !can('case.view_assigned')) return null;
    return assignedCaseIds($pdo, $orgId, (int)ctx()['user']['id'], ctxRole()['id'] ?? null);
}
/** כרטיסי השטח של התיקים שהוקצו — כל התאריכים, הקרוב קודם. */
function fieldAssignedCards(PDO $pdo, int $orgId, array $ids): array {
    $cases = [];
    foreach ($ids as $id) { $c = existing($pdo, (string)$id, $orgId); if ($c !== null) $cases[] = $c; }
    usort($cases, fn($a, $b) => [$a['date'] ?? '', $a['time'] ?? ''] <=> [$b['date'] ?? '', $b['time'] ?? '']);
    $stats = fieldFileStats($pdo, $orgId, array_column($cases, 'id'));
    return array_map(fn($c) => fieldCard($c, $stats[$c['id']]), $cases);
}

function fieldAction(PDO $pdo, string $action, string $method, int $orgId, array $actor): never {
    $id = (string)($_GET['id'] ?? '');
    $mine = in_array($action, ['field_today', 'field_search', 'field_case', 'field_save', 'field_upload'], true) ? fieldAssignedIds($pdo, $orgId) : null;
    switch ($action) {
        case 'field_today':
            if ($method !== 'GET') reply(405, ['error' => 'שיטה לא מורשית']);
            if ($mine !== null) { [$t, $tm] = fieldDays(); reply(200, ['ok' => true, 'assigned_only' => true, 'today' => $t, 'tomorrow' => $tm, 'cases' => fieldAssignedCards($pdo, $orgId, $mine)]); }
            need('case.view');
            reply(200, ['ok' => true] + fieldToday($pdo, $orgId));
        case 'field_search':
            if ($method !== 'GET') reply(405, ['error' => 'שיטה לא מורשית']);
            if ($mine !== null) {
                $q = cleanText($_GET['q'] ?? '', 80);
                reply(200, ['ok' => true, 'cases' => array_values(array_filter(fieldAssignedCards($pdo, $orgId, $mine),
                    fn($c) => mb_strlen($q) >= 2 && mb_stripos((string)($c['name'] ?? ''), $q) !== false))]);
            }
            need('case.view');
            reply(200, ['ok' => true, 'cases' => fieldSearch($pdo, $orgId, cleanText($_GET['q'] ?? '', 80))]);
        case 'field_case':
            if ($method !== 'GET') reply(405, ['error' => 'שיטה לא מורשית']);
            if ($mine === null) need('case.view');
            if (!preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $id)) reply(400, ['error' => 'מזהה תיק אינו תקין']);
            $case = existing($pdo, $id, $orgId);
            if ($case === null || ($mine !== null && !in_array($id, $mine, true))) reply(404, ['error' => 'התיק לא נמצא']);
            $stats = fieldFileStats($pdo, $orgId, [$id])[$id];
            unset($case['payments']);
            reply(200, ['ok' => true, 'case' => $case, 'needs' => fieldNeeds($case, $stats), 'kinds' => fieldKinds($case),
                'files' => fieldCaseFiles($pdo, $orgId, $id), 'can_track' => can('case.track', $case + ['org_id' => $orgId])]);
        case 'field_save':
            if ($method !== 'POST') reply(405, ['error' => 'שיטה לא מורשית']);
            if ($mine !== null) { $sid = (string)(body()['id'] ?? ''); if (!in_array($sid, $mine, true)) reply(404, ['error' => 'התיק לא נמצא']); need('case.track', (existing($pdo, $sid, $orgId) ?? []) + ['id' => $sid, 'org_id' => $orgId]); }
            else need('case.track');
            $item = fieldSave($pdo, body(), $actor, $orgId);
            unset($item['payments']);
            reply(200, ['ok' => true, 'record' => $item]);
        case 'field_upload':
            if ($method !== 'POST') reply(405, ['error' => 'שיטה לא מורשית']);
            if ($mine !== null) { $sid = (string)($_POST['case_id'] ?? ''); if (!in_array($sid, $mine, true)) reply(404, ['error' => 'התיק לא נמצא']); need('case.track', (existing($pdo, $sid, $orgId) ?? []) + ['id' => $sid, 'org_id' => $orgId]); }
            else need('case.track');
            $f = $_FILES['file'] ?? null;
            $tmp = is_array($f) && is_string($f['tmp_name'] ?? null) ? $f['tmp_name'] : '';
            if ($tmp !== '') register_shutdown_function(static function () use ($tmp) { if (is_file($tmp)) @unlink($tmp); });
            if (!is_array($f) || is_array($f['error'] ?? null)) reply(400, ['error' => 'נדרש קובץ אחד']);
            if (in_array((int)$f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) reply(413, ['error' => 'הקובץ גדול מהמותר בשרת']);
            if ((int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) reply(400, ['error' => 'ההעלאה נכשלה']);
            $size = (int)filesize($tmp);
            if ($size <= 0) reply(400, ['error' => 'קובץ ריק']);
            if ($size > FIELD_MAX_FILE) reply(413, ['error' => 'הקובץ גדול מ-50MB']);
            // הבדיקה של 03 (סיומת + תוכן, בלי SVG/HTML); צילום קבר — תמונה בלבד
            $orig = (string)($f['name'] ?? '');
            $kind = (string)($_POST['kind'] ?? '');
            if (!function_exists('dbxAllowedUpload') || !dbxAllowedUpload($orig, $tmp)) reply(415, ['error' => 'סוג קובץ לא נתמך (תמונה, PDF או מסמך Office)']);
            if ($kind === 'grave_photo' && !str_starts_with((string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp), 'image/')) reply(415, ['error' => 'צילום קבר חייב להיות תמונה']);
            [$status, $body] = fieldUpload($pdo, $orgId, (string)($_POST['case_id'] ?? ''), (string)($_POST['upload_id'] ?? ''),
                $kind, $tmp, $orig, $actor, 'fieldDropboxUploader', fieldTakenAt($_POST['taken_at'] ?? null));
            reply($status, $body);
    }
    reply(404, ['error' => 'פעולה לא מוכרת']);
}
