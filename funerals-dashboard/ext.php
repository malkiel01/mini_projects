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
$pdo = db();   // מריץ את המיגרציות של 01
// יומן הבקשות של 01 — פעולה, סטטוס, זמן ומשתמש. אף פעם לא האסימון או הכותרת.
// 401 ו-429 של הצפה לא נרשמים (ext_nolog). logRequest מבטל טרנזקציה פתוחה לפני הרישום — כך דחייה באמצע כתיבה נרשמת.
register_shutdown_function(static function () use ($method, &$action, $t0): void {
    if (!empty($GLOBALS['ext_nolog'])) return;
    logRequest($method, 'ext:' . substr((string)$action, 0, 40), (int)(http_response_code() ?: 200),
        (int)round((microtime(true) - $t0) * 1000), $GLOBALS['fd_ctx']['user']['id'] ?? null, (string)($GLOBALS['fd_err'] ?? ''));
});

extEnsureSchema($pdo);
$m = extAuthenticate($pdo);
$orgId = (int)$m['org_id'];

$actions = extActions();
if (!isset($actions[$action])) { $action = 'unknown'; reply(404, ['error' => 'פעולה לא מוכרת. הפעולות: ' . implode(', ', array_keys($actions))]); }
$def = $actions[$action];
if ($method !== $def['method']) reply(405, ['error' => 'שיטה לא מורשית: ' . $action . ' היא ' . $def['method']]);
if (!extCan($def['perm'])) reply(403, ['error' => 'אין לך הרשאה לפעולה הזו (התפקיד שלך: ' . (ROLES[$m['role']] ?? $m['role']) . ')']);

$now = gmdate('c');
switch ($action) {
    case 'whoami':
        $allowed = array_keys(array_filter($actions, static fn($a) => extCan($a['perm'])));
        $fields = array_keys(array_filter(EXT_FIELDS, static fn($p) => extCan($p)));
        reply(200, ['user' => ['name' => $m['name'], 'email' => $m['email']], 'org' => ['id' => $orgId, 'name' => $m['org_name']],
            'role' => $m['role'], 'role_name' => ROLES[$m['role']], 'actions' => $allowed, 'editable_fields' => $fields,
            'can_update_checks' => extCan('update'), 'can_delete' => false]);

    case 'schema':
        reply(200, ['checks' => array_map(static fn($k, $v) => ['key' => $k, 'name' => $v], array_keys(EXT_CHECKS), EXT_CHECKS),
            'statuses' => array_map(static fn($k, $v) => ['key' => $k, 'name' => $v, 'human_only' => !finalAllowed('api') && in_array($k, FORM_FINAL_STATUSES, true)],
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
        // הסטטוס מחושב מהבדיקות (JSON), ולכן מסננים את כל תיקי הארגון שעברו את סינון ה-SQL — ורק אז חותכים
        $st = $pdo->prepare('SELECT body FROM funerals WHERE ' . implode(' AND ', $where) . ' ORDER BY month DESC, name');
        $st->execute($args);
        $list = [];
        foreach ($st as $r) {
            $c = extSummary(json_decode($r['body'], true));
            if ($status === '' || $c['status'] === $status) $list[] = $c;
        }
        reply(200, ['count' => count($list), 'more' => count($list) > 200, 'cases' => array_slice($list, 0, 200)]);

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
        // הכתיבה — formSaveCase() של 02 עם via: api: "הושלם" רק באדם, ובדיקה שאדם כבר סגר לא נפתחת מחדש.
        // ההרשאה — need(caseChangeNeeds()) כמו ב-api.php; לפניה בדיקה לפי שדה, כדי לומר לצ'אט בדיוק מה אסור.
        $p = body();
        $old = extCase($pdo, $orgId, cleanText($p['id'] ?? '', 100));
        $fields = $p['fields'] ?? [];
        $checksIn = $p['checks'] ?? [];
        $note = cleanText($p['note'] ?? '', 800);
        if (!is_array($fields) || !is_array($checksIn)) reply(400, ['error' => 'fields ו-checks — אובייקטים']);
        if (!$fields && !$checksIn && $note === '') reply(400, ['error' => 'אין מה לעדכן: fields, checks או note']);
        foreach ($fields as $k => $v) {
            if (!is_string($k) || !isset(EXT_FIELDS[$k])) reply(400, ['error' => 'שדה לא מוכר: ' . $k . '. השדות: ' . implode(', ', array_keys(EXT_FIELDS))]);
            $okType = $k === 'payments' ? is_array($v) && array_is_list($v) : (is_string($v) || is_int($v) || is_float($v));
            if (!$okType) reply(400, ['error' => 'שדה ' . $k . ': ' . ($k === 'payments' ? 'נדרשת רשימה' : 'נדרש טקסט') . '. שום דבר לא נשמר.']);
            if (!extCan(EXT_FIELDS[$k])) reply(403, ['error' => 'אין לך הרשאה לשנות ' . $k . ' (התפקיד שלך: ' . ROLES[$m['role']] . '). שום דבר לא נשמר.']);
        }
        $raw = $old;
        $closed = [];
        foreach ($fields as $k => $v) $raw[$k] = $v;
        foreach ($checksIn as $k => $v) {
            if (!is_string($k) || !isset(EXT_CHECKS[$k])) reply(400, ['error' => 'בדיקה לא מוכרת: ' . $k . '. הרשימה ב-schema.']);
            if (!is_array($v)) reply(400, ['error' => 'בדיקה ' . $k . ': נדרש אובייקט {status, value, note}']);
            foreach (['status', 'value', 'note'] as $f) {
                if (array_key_exists($f, $v) && !is_string($v[$f]) && !is_int($v[$f]) && !is_float($v[$f])) reply(400, ['error' => 'בדיקה ' . $k . ': ' . $f . ' — נדרש טקסט. שום דבר לא נשמר.']);
            }
            $prev = $old['checks'][$k] ?? ['status' => 'unknown', 'value' => '', 'note' => '', 'source' => ''];
            $status = cleanText($v['status'] ?? ($prev['status'] ?? 'unknown'), 30);
            if (!isset(EXT_STATUSES[$status])) reply(400, ['error' => 'סטטוס לא מוכר: ' . $status]);
            // בדיקה שאדם כבר סגר (סטטוס סופי) — הצ'אט לא משנה אותה בכלל, גם לא ל"חסר"/"טרם נבדק"; ההצעה נכתבת בהערות התיק
            if (!finalAllowed('api') && in_array($prev['status'] ?? '', FORM_FINAL_STATUSES, true)) {
                $val = array_key_exists('value', $v) ? cleanText($v['value'], 500) : (string)($prev['value'] ?? '');
                if ($status !== $prev['status'] || $val !== (string)($prev['value'] ?? '')) {
                    $closed[$k] = 'הצעה ל"' . EXT_CHECKS[$k] . '": ' . EXT_STATUSES[$status] . ($val !== '' ? ' — ' . $val : '');
                }
                continue;
            }
            $raw['checks'][$k] = ['status' => $status,
                'value' => array_key_exists('value', $v) ? cleanText($v['value'], 500) : (string)($prev['value'] ?? ''),
                'note' => array_key_exists('note', $v) ? cleanText($v['note'], 800) : (string)($prev['note'] ?? ''),
                'source' => (string)($prev['source'] ?? '')];
        }
        $line = null;
        if ($closed) $note = trim(implode('; ', $closed) . ($note !== '' ? '. ' . $note : ''));
        if ($note !== '') {
            $line = '[' . date('d.m.Y') . '] ' . extActor($m) . ': ' . $note;
            $raw['notes'] = trim(((string)($raw['notes'] ?? '')) . "\n" . $line);
            if (mb_strlen($raw['notes']) > 1500) reply(400, ['error' => 'ההערות של התיק מלאות (1500 תווים). לקצר במסך התיק.']);
        }
        $pdo->beginTransaction();   // של PDO — כדי ש-logRequest יבטל אותה אם הכתיבה נדחתה
        $saved = formSaveCase($pdo, $raw, 'api', ctx()['actor'], $orgId,
            fn(?array $o, array $item) => need(caseChangeNeeds($o === null ? null : normalize($o, $orgId), $item)));
        $pdo->commit();
        $out = ['fields' => [], 'checks' => [], 'note' => $line];
        $oldN = normalize($old, $orgId);   // אותם טיפוסים (100 מול 100.0) — כדי לא לדווח על שדה שלא השתנה
        foreach (array_keys(EXT_FIELDS) as $f) {
            if (($saved[$f] ?? null) !== ($oldN[$f] ?? null) && ($f !== 'notes' || isset($fields['notes']))) $out['fields'][$f] = $saved[$f];
        }
        $converted = [];
        foreach (array_keys($checksIn) as $k) {
            $c = $saved['checks'][$k] ?? ['status' => 'unknown', 'value' => '', 'note' => ''];
            $out['checks'][$k] = ['name' => EXT_CHECKS[$k], 'status' => $c['status'], 'status_name' => EXT_STATUSES[$c['status']] ?? '',
                'value' => $c['value'] ?? '', 'note' => $c['note'] ?? '', 'by' => $c['by'] ?? null, 'via' => $c['via'] ?? null];
            $asked = (string)($checksIn[$k]['status'] ?? $c['status']);
            if (isset($closed[$k])) {
                $converted[] = ['check' => $k, 'name' => EXT_CHECKS[$k], 'requested' => $asked, 'saved' => $c['status'],
                    'reason' => 'אדם כבר סגר את הבדיקה הזו — הצ\'אט לא משנה אותה. ההצעה נכתבה בהערות התיק.'];
            } elseif (in_array($asked, FORM_FINAL_STATUSES, true) && !finalAllowed('api')) {
                $converted[] = ['check' => $k, 'name' => EXT_CHECKS[$k], 'requested' => $asked, 'saved' => $c['status'],
                    'reason' => 'רק אדם במשרד מאשר סופית. נשמר "לאימות".'];
            }
        }
        $msg = $converted ? 'נשמר. ' . count($converted) . ' בדיקות לא נשמרו כפי שביקשת: "מאומת" נשמר "לאימות", ובדיקה שאדם כבר סגר לא משתנה — רק אדם במשרד מאשר (פירוט ב-converted).' : 'נשמר.';
        reply(200, ['ok' => true, 'message' => $msg, 'saved' => $out, 'converted' => $converted, 'summary' => extSummary($saved)]);

    case 'case_create':
        $p = body();
        $date = cleanText($p['date'] ?? '', 10);
        $month = cleanText($p['month'] ?? '', 7);
        if ($month === '') $month = preg_match('/^\d{4}-\d\d/', $date) ? substr($date, 0, 7) : date('Y-m');
        $note = cleanText($p['note'] ?? '', 800);
        $raw = ['id' => 'fd_' . bin2hex(random_bytes(12)), 'month' => $month, 'name' => $p['name'] ?? '', 'date' => $date,
            'time' => $p['time'] ?? '', 'origin' => $p['origin'] ?? '', 'team' => $p['team'] ?? '', 'grave' => $p['grave'] ?? '',
            'notes' => $note === '' ? '' : '[' . date('d.m.Y') . '] ' . extActor($m) . ': ' . $note];
        $pdo->beginTransaction();   // של PDO — כדי ש-logRequest יבטל אותה אם הכתיבה נדחתה
        $saved = formSaveCase($pdo, $raw, 'api', ctx()['actor'], $orgId,
            fn(?array $o, array $item) => need(caseChangeNeeds($o === null ? null : normalize($o, $orgId), $item)));
        $pdo->commit();
        reply(200, ['ok' => true, 'message' => 'התיק נפתח.', 'case' => $saved, 'summary' => extSummary($saved)]);
}
reply(404, ['error' => 'פעולה לא מוכרת']);
