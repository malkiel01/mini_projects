<?php
declare(strict_types=1);

/**
 * API חיצוני לצ'אט (GPT Actions) — פרוסה 05. Bearer בלבד (אסימון אישי fdx_…), בלי עוגייה ובלי session.
 * רשום ב-"public" של app.json: מכונה ניגשת בלי השער. הפעולות — מטבלה אחת (extActions() ב-ext-lib.php),
 * ממנה נוצר גם openapi.json. הנתיב: ext.php/<action> (או ext.php?action=<action>). אין פעולת מחיקה.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/ext-lib.php';

$t0 = microtime(true);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
if ($action === '') $action = (string)($_GET['action'] ?? '');
$pdo = db();
$logUser = null;
register_shutdown_function(static function () use ($pdo, $method, &$action, $t0, &$logUser): void {
    extLogRequest($pdo, $method, (string)$action, (int)http_response_code(), $t0, $logUser);
});

extRequireInfra($pdo);
$m = extAuthenticate($pdo);
$logUser = (int)$m['user_id'];
$orgId = (int)$m['org_id'];

$actions = extActions();
if (!isset($actions[$action])) { $action = 'unknown'; reply(404, ['error' => 'פעולה לא מוכרת. הפעולות: ' . implode(', ', array_keys($actions))]); }
$def = $actions[$action];
if ($method !== $def['method']) reply(405, ['error' => 'שיטה לא מורשית: ' . $action . ' היא ' . $def['method']]);
if (!extCan($m, $def['perm'])) reply(403, ['error' => 'אין לך הרשאה לפעולה הזו (התפקיד שלך: ' . (EXT_ROLE_NAMES[$m['role']] ?? $m['role']) . ')']);

$now = gmdate('c');
switch ($action) {
    case 'whoami':
        $allowed = array_keys(array_filter($actions, static fn($a) => extCan($m, $a['perm'])));
        $fields = array_keys(array_filter(EXT_FIELDS, static fn($p) => extCan($m, $p)));
        reply(200, ['user' => ['name' => $m['name'], 'email' => $m['email']], 'org' => ['id' => $orgId, 'name' => $m['org_name']],
            'role' => $m['role'], 'role_name' => EXT_ROLE_NAMES[$m['role']], 'actions' => $allowed, 'editable_fields' => $fields,
            'can_update_checks' => extCan($m, 'update'), 'can_delete' => false]);

    case 'schema':
        reply(200, ['checks' => array_map(static fn($k, $v) => ['key' => $k, 'name' => $v], array_keys(EXT_CHECKS), EXT_CHECKS),
            'statuses' => array_map(static fn($k, $v) => ['key' => $k, 'name' => $v, 'human_only' => !extFinalAllowed('api') && in_array($k, ['verified', 'reported', 'not_applicable'], true)],
                array_keys(EXT_STATUSES), EXT_STATUSES),
            'fields' => array_keys(EXT_FIELDS),
            'rule' => 'סטטוס "מאומת" / "עודכן ידנית" / "לא רלוונטי" שנכתב דרך הצ\'אט נשמר כ"מסמך קיים — לאימות". רק אדם במשרד מאשר סופית.',
            'document_kinds' => array_values(EXT_CHECKS)]);

    case 'prompt':
        reply(200, ['prompt' => extPrompt()]);

    case 'find':
        $q = cleanText($_GET['q'] ?? '', 80);
        if (mb_strlen($q) < 2) reply(400, ['error' => 'נדרשים 2 תווים לפחות לחיפוש']);
        $st = $pdo->prepare("SELECT body FROM funerals WHERE org_id=? AND name LIKE ? ESCAPE '\\' ORDER BY month DESC, name LIMIT 11");
        $st->execute([$orgId, '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%']);
        $rows = array_map(static fn($r) => extSummary(json_decode($r['body'], true)), $st->fetchAll());
        $more = count($rows) > 10;
        reply(200, ['query' => $q, 'count' => min(count($rows), 10), 'more' => $more, 'results' => array_slice($rows, 0, 10),
            'hint' => count($rows) > 1 ? 'יותר מתיק אחד — לשאול את המשתמש איזה, לפי תאריך ומקום.' : null]);

    case 'cases':
        $where = ['org_id=?']; $args = [$orgId];
        if (($_GET['month'] ?? '') !== '') { $where[] = 'month=?'; $args[] = validMonth((string)$_GET['month']); }
        $q = cleanText($_GET['q'] ?? '', 80);
        if ($q !== '') { $where[] = "name LIKE ? ESCAPE '\\'"; $args[] = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%'; }
        $status = (string)($_GET['status'] ?? '');
        if ($status !== '' && !in_array($status, ['missing', 'verify', 'unchecked', 'done'], true)) reply(400, ['error' => 'status: missing | verify | unchecked | done']);
        $st = $pdo->prepare('SELECT body FROM funerals WHERE ' . implode(' AND ', $where) . ' ORDER BY month DESC, name LIMIT 500');
        $st->execute($args);
        $list = array_map(static fn($r) => extSummary(json_decode($r['body'], true)), $st->fetchAll());
        if ($status !== '') $list = array_values(array_filter($list, static fn($c) => $c['status'] === $status));
        reply(200, ['count' => count($list), 'cases' => array_slice($list, 0, 200)]);

    case 'case':
        $c = extCase($pdo, $orgId, (string)($_GET['id'] ?? ''));
        $st = $pdo->prepare('SELECT at, actor, source, action, diff FROM events WHERE org_id=? AND case_id=? ORDER BY id DESC LIMIT 10');
        $st->execute([$orgId, $c['id']]);
        $events = array_map(static fn($e) => ['diff' => json_decode((string)$e['diff'], true)] + $e, $st->fetchAll());
        $checks = [];
        foreach (EXT_CHECKS as $k => $label) {
            $ch = $c['checks'][$k] ?? ['status' => 'unknown', 'value' => '', 'note' => ''];
            $checks[] = ['key' => $k, 'name' => $label, 'status_name' => EXT_STATUSES[$ch['status'] ?? 'unknown'] ?? ''] + $ch;
        }
        $files = extDropboxReady('dropboxCaseFiles') ? dropboxCaseFiles($pdo, $c) : null;
        reply(200, ['case' => $c, 'summary' => extSummary($c), 'checks' => $checks, 'files' => $files,
            'files_note' => $files === null ? 'Dropbox עוד לא חובר' : null, 'events' => $events]);

    case 'files':
        if (!extDropboxReady('dropboxCaseFiles')) reply(501, ['error' => 'Dropbox עוד לא חובר']);
        $c = extCase($pdo, $orgId, (string)($_GET['id'] ?? ''));
        reply(200, ['id' => $c['id'], 'files' => dropboxCaseFiles($pdo, $c)]);

    case 'file_text':
        if (!extDropboxReady('dropboxFileText')) reply(501, ['error' => 'Dropbox עוד לא חובר']);
        $text = dropboxFileText($pdo, $orgId, cleanText($_GET['file'] ?? '', 300));
        reply(200, ['file' => $_GET['file'] ?? '', 'text' => mb_strcut((string)$text, 0, 200 * 1024)]);

    case 'case_update':
        $p = body();
        $pdo->exec('BEGIN IMMEDIATE');
        $old = extCase($pdo, $orgId, cleanText($p['id'] ?? '', 100));
        $new = $old;
        $fields = $p['fields'] ?? [];
        $checksIn = $p['checks'] ?? [];
        $note = cleanText($p['note'] ?? '', 800);
        if (!is_array($fields) || !is_array($checksIn)) reply(400, ['error' => 'fields ו-checks — אובייקטים']);
        if (!$fields && !$checksIn && $note === '') reply(400, ['error' => 'אין מה לעדכן: fields, checks או note']);
        // קודם כל ההרשאות — שום דבר לא נשמר אם שדה אחד אסור
        foreach ($fields as $k => $v) {
            if (!is_string($k) || !isset(EXT_FIELDS[$k])) reply(400, ['error' => 'שדה לא מוכר: ' . $k . '. השדות: ' . implode(', ', array_keys(EXT_FIELDS))]);
            if (!extCan($m, EXT_FIELDS[$k])) reply(403, ['error' => 'אין לך הרשאה לשנות ' . $k . ' (התפקיד שלך: ' . EXT_ROLE_NAMES[$m['role']] . '). שום דבר לא נשמר.']);
        }
        $saved = ['fields' => [], 'checks' => [], 'note' => null];
        $converted = [];
        $meta = ['by' => (string)($m['name'] ?: $m['email']), 'via' => 'api', 'at' => $now];
        foreach ($fields as $k => $v) $new[$k] = $v;
        $changedChecks = [];
        foreach ($checksIn as $k => $v) {
            if (!is_string($k) || !isset(EXT_CHECKS[$k])) reply(400, ['error' => 'בדיקה לא מוכרת: ' . $k . '. הרשימה ב-schema.']);
            if (!is_array($v)) reply(400, ['error' => 'בדיקה ' . $k . ': נדרש אובייקט {status, value, note}']);
            $prev = $old['checks'][$k] ?? ['status' => 'unknown', 'value' => '', 'note' => '', 'source' => ''];
            $status = cleanText($v['status'] ?? ($prev['status'] ?? 'unknown'), 30);
            if (!isset(EXT_STATUSES[$status])) reply(400, ['error' => 'סטטוס לא מוכר: ' . $status]);
            $value = array_key_exists('value', $v) ? cleanText($v['value'], 500) : (string)($prev['value'] ?? '');
            $cnote = array_key_exists('note', $v) ? cleanText($v['note'], 800) : (string)($prev['note'] ?? '');
            if (in_array($status, ['verified', 'reported', 'not_applicable'], true) && !extFinalAllowed('api')) {
                $converted[] = ['check' => $k, 'name' => EXT_CHECKS[$k], 'requested' => $status, 'saved' => 'document_present',
                    'reason' => 'רק אדם במשרד מאשר סופית. נשמר "לאימות".'];
                $cnote = mb_substr(trim("הוצע בצ'אט: " . EXT_STATUSES[$status] . ($value !== '' ? ' (' . $value . ')' : '') . '. ' . $cnote), 0, 800);
                $status = 'document_present';
            }
            $new['checks'][$k] = ['status' => $status, 'value' => $value, 'source' => 'עדכון ידני', 'note' => $cnote];
            $changedChecks[$k] = true;
        }
        if ($note !== '') {
            $line = '[' . date('d.m.Y') . '] ' . extActor($m) . ': ' . $note;
            $new['notes'] = trim(((string)($new['notes'] ?? '')) . "\n" . $line);
            if (mb_strlen($new['notes']) > 1500) reply(400, ['error' => 'ההערות של התיק מלאות (1500 תווים). לקצר במסך התיק.']);
            $saved['note'] = $line;
        }
        $norm = normalize($new);
        $norm['id'] = $old['id'];
        foreach ($norm['checks'] as $k => &$c) {
            if (isset($changedChecks[$k])) $c += $meta;
            else foreach (['by', 'via', 'at'] as $f) if (isset($old['checks'][$k][$f])) $c[$f] = $old['checks'][$k][$f];
        }
        unset($c);
        $diff = [];
        foreach (array_keys(EXT_FIELDS) as $f) {
            if (($norm[$f] ?? null) !== ($old[$f] ?? null)) {
                $diff[$f] = ['from' => $old[$f] ?? null, 'to' => $norm[$f]];
                $norm['manual_fields'][] = $f;
                if ($f !== 'notes' || isset($fields['notes'])) $saved['fields'][$f] = $norm[$f];
            }
        }
        $norm['manual_fields'] = array_values(array_unique($norm['manual_fields']));
        foreach (array_keys($changedChecks) as $k) {
            $before = $old['checks'][$k] ?? null;
            $after = $norm['checks'][$k];
            $diff['checks.' . $k] = ['from' => $before === null ? null : ['status' => $before['status'] ?? '', 'value' => $before['value'] ?? ''],
                'to' => ['status' => $after['status'], 'value' => $after['value']]];
            $saved['checks'][$k] = ['name' => EXT_CHECKS[$k], 'status' => $after['status'], 'status_name' => EXT_STATUSES[$after['status']],
                'value' => $after['value'], 'note' => $after['note']];
        }
        store($pdo, $norm, extActor($m), 'api_update');
        extRecordEvent($pdo, $m, $norm['id'], 'case_update', $diff);
        $pdo->exec('COMMIT');
        $msg = $converted ? 'נשמר. ' . count($converted) . ' בדיקות נשמרו "לאימות" (לא "מאומת") — אדם במשרד יאשר.' : 'נשמר.';
        reply(200, ['ok' => true, 'message' => $msg, 'saved' => $saved, 'converted' => $converted, 'summary' => extSummary($norm)]);

    case 'case_create':
        $p = body();
        $date = cleanText($p['date'] ?? '', 10);
        $month = cleanText($p['month'] ?? '', 7);
        if ($month === '') $month = preg_match('/^\d{4}-\d\d/', $date) ? substr($date, 0, 7) : date('Y-m');
        $note = cleanText($p['note'] ?? '', 800);
        $rec = ['id' => 'fd_' . bin2hex(random_bytes(12)), 'month' => $month, 'name' => $p['name'] ?? '', 'date' => $date,
            'time' => $p['time'] ?? '', 'origin' => $p['origin'] ?? '', 'team' => $p['team'] ?? '', 'grave' => $p['grave'] ?? '',
            'notes' => $note === '' ? '' : '[' . date('d.m.Y') . '] ' . extActor($m) . ': ' . $note];
        $norm = normalize($rec);
        $norm['manual_fields'] = array_keys(EXT_FIELDS);
        $pdo->exec('BEGIN IMMEDIATE');
        store($pdo, $norm, extActor($m), 'api_create');
        $pdo->prepare('UPDATE funerals SET org_id=? WHERE id=?')->execute([$orgId, $norm['id']]);
        extRecordEvent($pdo, $m, $norm['id'], 'case_create', ['name' => $norm['name'], 'date' => $norm['date'], 'month' => $norm['month']]);
        $pdo->exec('COMMIT');
        reply(200, ['ok' => true, 'message' => 'התיק נפתח.', 'case' => $norm, 'summary' => extSummary($norm)]);
}
reply(404, ['error' => 'פעולה לא מוכרת']);
