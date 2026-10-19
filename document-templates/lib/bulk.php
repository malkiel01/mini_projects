<?php
/**
 * שלב 12: הנפקה מאקסל, וייצוא התשובות לאקסל.
 *
 * הנפקה: מעלים גיליון (xlsx או CSV), ממפים כל עמודה לשדה / לכותרת / לאיש קשר /
 * למשתתף — המערכת מציעה מיפוי לפי שמות העמודות — ונוצר מסמך לכל שורה. בלי
 * מצב בשרת בין התצוגה המקדימה להנפקה: הדפדפן שולח את הקובץ שוב עם המיפוי.
 * כל שורה נבדקת לפני שהמסמך נוצר; שורה שגויה לא נוצרת, ומדווחת עם הסיבה.
 *
 * ייצוא: כל המסמכים של תבנית שהמשתמש רשאי לראות (לפי הסינון ברשימה), שורה
 * לכל מסמך ועמודה לכל שדה. נרשם ביומן של כל מסמך.
 */

declare(strict_types=1);

require_once __DIR__ . '/sheets.php';

const BULK_MAX = 500;
const DOC_STATUS_HE = ['draft' => 'טיוטה', 'sent' => 'נשלח', 'in_progress' => 'בתהליך', 'completed' => 'הושלם'];

/** השוואת שמות: בלי רווחים, קווים, גרשיים וסימני פיסוק; אותיות קטנות. */
function bulk_norm(string $s): string
{
    return mb_strtolower(preg_replace('/[\s_\-–—"\'״׳.,:()\/]+/u', '', $s));
}

/** לאן אפשר למפות עמודה: [value => [label, group]]. */
function bulk_targets(array $schema): array
{
    $out = [];
    foreach ($schema['fields'] ?? [] as $f) {
        if (in_array($f['type'], ['signature', 'file', 'calc', 'table', 'system'], true)) continue;
        $out['f:' . $f['key']] = [$f['label'], 'שדות'];
    }
    $out['_title'] = ['כותרת המסמך', 'מסמך'];
    $out['_tags'] = ['תגיות (מופרדות בפסיק)', 'מסמך'];
    foreach (CONTACT_FIELDS as $k) $out['_c:' . $k] = ['איש קשר — ' . CONTACT_LABELS[$k], 'איש קשר'];
    foreach ($schema['roles'] ?? [] as $r) {
        if ($r['key'] === 'owner') continue;
        foreach (['name' => 'שם', 'email' => 'מייל', 'phone' => 'טלפון'] as $k => $l) {
            $out['_p:' . $r['key'] . ':' . $k] = [$r['label'] . ' — ' . $l, 'משתתף: ' . $r['label']];
        }
    }
    return $out;
}

/** הצעת מיפוי לפי שמות העמודות. כל יעד — לעמודה אחת בלבד (הראשונה). */
function bulk_suggest(array $headers, array $schema): array
{
    $targets = bulk_targets($schema);
    $byName = [];
    // קודם השמות המדויקים: תווית השדה, הסימון, המפתח, והתווית של כל יעד
    foreach ($schema['fields'] ?? [] as $f) {
        if (!isset($targets['f:' . $f['key']])) continue;
        foreach ([$f['label'], $f['placeholder'] ?? '', $f['key']] as $n) if ($n !== '') $byName[bulk_norm($n)] ??= 'f:' . $f['key'];
    }
    foreach ($targets as $v => [$label]) $byName[bulk_norm($label)] ??= $v;
    // ואז מילים נפוצות
    $words = [
        '_title' => ['כותרת', 'title', 'נושא'], '_tags' => ['תגיות', 'תגית', 'tags'],
        '_c:name' => ['שם', 'שםמלא', 'לקוח', 'שםהלקוח', 'name', 'fullname'],
        '_c:phone' => ['טלפון', 'נייד', 'פלאפון', 'טל', 'phone', 'mobile'],
        '_c:email' => ['מייל', 'אימייל', 'דואל', 'דואראלקטרוני', 'email', 'mail'],
        '_c:id_number' => ['תז', 'תעודתזהות', 'חפ', 'id'], '_c:address' => ['כתובת', 'address'],
    ];
    foreach ($words as $v => $list) foreach ($list as $w) $byName[$w] ??= $v;
    $out = [];
    $used = [];
    foreach ($headers as $i => $h) {
        $v = $byName[bulk_norm((string)$h)] ?? null;
        if ($v && !isset($used[$v])) { $out[$i] = $v; $used[$v] = true; }
    }
    return $out;
}

/** הקובץ שהועלה → [כותרות, שורות]. */
function bulk_sheet(array $files): array
{
    $f = $files['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new ApiError('לא נבחר קובץ', 422);
    if ($f['error'] !== UPLOAD_ERR_OK || (!is_uploaded_file($f['tmp_name']) && PHP_SAPI !== 'cli')) throw new ApiError('ההעלאה נכשלה', 400);
    if ($f['size'] > SHEET_MAX_MB * 1024 * 1024) throw new ApiError('הקובץ גדול מ-' . SHEET_MAX_MB . 'MB', 413);
    $rows = sheet_read((string)file_get_contents($f['tmp_name']));
    $headers = array_shift($rows);
    if (!array_filter($headers, fn($h) => $h !== '')) throw new ApiError('בשורה הראשונה צריכות להיות כותרות העמודות', 422);
    return [$headers, $rows];
}

function bulk_preview(array $user, array $post, array $files): array
{
    $t = tpl_owned((int)($post['template_id'] ?? 0), $user, 'fill');
    $schema = json_decode((string)unseal($t['schema_enc']), true) ?: [];
    [$headers, $rows] = bulk_sheet($files);
    $rows = array_values(array_filter($rows, fn($r) => array_filter($r, fn($v) => $v !== '')));
    $targets = [];
    foreach (bulk_targets($schema) as $v => [$label, $group]) $targets[] = ['value' => $v, 'label' => $label, 'group' => $group];
    return ['ok' => true, 'headers' => $headers, 'sample' => array_slice($rows, 0, 5), 'rows' => count($rows),
        'targets' => $targets, 'mapping' => (object)bulk_suggest($headers, $schema),
        'roles' => count(array_filter($schema['roles'] ?? [], fn($r) => $r['key'] !== 'owner')),
        'tag' => 'ייבוא ' . bulk_now('d/m H:i'), 'max' => BULK_MAX];
}

function bulk_now(string $fmt, ?string $iso = null): string
{
    $d = new DateTime($iso ?? 'now');
    $d->setTimezone(new DateTimeZone('Asia/Jerusalem'));
    return $d->format($fmt);
}

/** ערך מתא בגיליון → ערך לשדה: תאריך, מספר, סימון — כמו שאנשים כותבים אותם. */
function bulk_cell(array $f, string $raw): string
{
    $v = trim($raw);
    switch ($f['type']) {
        case 'date':
            if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})$#', $v, $m)) [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            elseif (is_numeric($v) && (float)$v > 20000) { $v = excel_date((float)$v); break; }
            else throw new ApiError('תאריך לא מוכר: ' . $v . ' (למשל 14/10/2026)', 422);
            if ($y < 100) $y += 2000;
            if (!checkdate($mo, $d, $y)) throw new ApiError('תאריך לא קיים: ' . $v, 422);
            $v = sprintf('%04d-%02d-%02d', $y, $mo, $d);
            break;
        case 'number':
            $v = str_replace([',', '₪', ' ', "\u{00A0}"], '', $v);
            if ($v !== '' && !is_numeric($v)) throw new ApiError('לא מספר: ' . $raw, 422);
            break;
        case 'checkbox':
            $v = in_array(mb_strtolower($v), ['1', 'כן', 'v', '✓', '✔', 'x', 'true', 'yes', 'מסומן'], true) ? '1' : '';
            break;
    }
    return clean_value($f, $v);
}

/** איש קשר קיים (לפי מייל, טלפון או ת"ז) — או חדש. */
function bulk_contact(array $user, array $c, array &$cache): int
{
    $digits = fn($s) => preg_replace('/\D/', '', (string)$s);
    foreach ($cache as $x) {
        if (($c['email'] ?? '') !== '' && mb_strtolower($x['email']) === mb_strtolower($c['email'])) return $x['id'];
        if (($c['phone'] ?? '') !== '' && $digits($x['phone']) !== '' && $digits($x['phone']) === $digits($c['phone'])) return $x['id'];
        if (($c['id_number'] ?? '') !== '' && $digits($x['id_number']) === $digits($c['id_number'])) return $x['id'];
    }
    $c['name'] = ($c['name'] ?? '') !== '' ? $c['name'] : ($c['email'] ?? $c['phone'] ?? $c['id_number'] ?? 'איש קשר');
    $id = contact_save($user, $c)['id'];
    $cache[] = ['id' => $id] + $c + array_fill_keys(CONTACT_FIELDS, '');
    return $id;
}

function bulk_issue(array $user, array $post, array $files): array
{
    $t = tpl_owned((int)($post['template_id'] ?? 0), $user, 'fill');
    $schema = json_decode((string)unseal($t['schema_enc']), true) ?: [];
    $byKey = array_column($schema['fields'] ?? [], null, 'key');
    $targets = bulk_targets($schema);
    [$headers, $rows] = bulk_sheet($files);

    $map = [];
    foreach ((array)json_decode((string)($post['mapping'] ?? '{}'), true) as $col => $v) {
        if ($v !== '' && isset($targets[$v]) && isset($headers[(int)$col]) && !in_array($v, $map, true)) $map[(int)$col] = $v;
    }
    if (!$map) throw new ApiError('לא נבחרה אף עמודה לייבוא', 422);
    $tag = clean_tags([trim((string)($post['tag'] ?? '')) ?: 'ייבוא ' . bulk_now('d/m H:i')])[0];
    $send = !empty($post['send']) && $post['send'] !== '0';
    $nonEmpty = array_filter($rows, fn($r) => array_filter(array_intersect_key($r, $map), fn($v) => $v !== ''));
    if (count($nonEmpty) > BULK_MAX) throw new ApiError('עד ' . BULK_MAX . ' שורות בכל פעם — פצלו את הקובץ', 422);
    if (!$nonEmpty) throw new ApiError('אין שורות עם ערכים בעמודות שנבחרו', 422);

    $cache = contact_list($user, ['archived' => 1])['contacts'];
    $created = $failed = $warnings = [];
    foreach ($nonEmpty as $i => $row) {
        $line = $i + 2;   // מספר השורה כמו ב-Excel (1 = כותרות)
        try {
            // קודם בודקים הכול — שורה שגויה לא יוצרת מסמך חלקי
            $values = $contact = $parts = [];
            $title = '';
            $tags = [$tag];
            foreach ($map as $col => $target) {
                $raw = trim((string)($row[$col] ?? ''));
                if ($raw === '') continue;
                try {
                    if (str_starts_with($target, 'f:')) {
                        $values[substr($target, 2)] = bulk_cell($byKey[substr($target, 2)], $raw);
                    } elseif ($target === '_title') {
                        $title = mb_substr($raw, 0, 200);
                    } elseif ($target === '_tags') {
                        $tags = clean_tags(array_merge($tags, preg_split('/[,،]/u', $raw)));
                    } elseif (str_starts_with($target, '_c:')) {
                        $k = substr($target, 3);
                        $contact[$k] = $k === 'email' ? valid_email($raw) : mb_substr($raw, 0, 200);
                    } elseif (str_starts_with($target, '_p:')) {
                        [, $role, $k] = explode(':', $target, 3);
                        $parts[$role][$k] = $k === 'email' ? valid_email($raw) : $raw;
                    }
                } catch (ApiError $e) {
                    throw new ApiError('"' . $headers[$col] . '": ' . $e->getMessage(), 422);
                }
            }
            $id = doc_create($user, ['template_id' => $t['id'], 'title' => $title])['id'];
            doc_save($user, ['id' => $id, 'values' => $values, 'tags' => $tags]);
            if (array_filter($contact)) {
                // בלי עמודת שם לאיש הקשר — השם מהשדה שמסומן "שם", או מהשדה הראשון בשורה
                if (($contact['name'] ?? '') === '') {
                    foreach ($map as $col => $target) {
                        $f = str_starts_with($target, 'f:') ? $byKey[substr($target, 2)] : null;
                        if ($f && ($f['contactField'] ?? '') === 'name' && ($values[$f['key']] ?? '') !== '') { $contact['name'] = $values[$f['key']]; break; }
                    }
                    foreach ($map as $col => $target) {
                        if (($contact['name'] ?? '') !== '') break;
                        $f = str_starts_with($target, 'f:') ? $byKey[substr($target, 2)] : null;
                        if ($f && $f['type'] === 'text' && ($values[$f['key']] ?? '') !== '') $contact['name'] = $values[$f['key']];
                    }
                }
                doc_contact($user, ['id' => $id, 'contact_id' => bulk_contact($user, $contact, $cache)]);
            }
            foreach ($parts as $role => $c) {
                $st = db()->prepare('SELECT id FROM participants WHERE document_id = ? AND role_key = ?');
                $st->execute([$id, $role]);
                $pid = (int)$st->fetchColumn();
                if (!$pid) continue;
                part_contact($user, ['id' => $id, 'participant_id' => $pid] + $c);
                if ($send && ($c['email'] ?? '') !== '') {
                    try { part_link($user, ['id' => $id, 'participant_id' => $pid, 'via' => 'email']); }
                    catch (ApiError $e) { $warnings[] = ['row' => $line, 'error' => $e->getMessage()]; }
                }
            }
            log_event($id, 'user:' . $user['id'], 'imported', ['row' => $line, 'tag' => $tag]);
            $created[] = ['row' => $line, 'id' => $id, 'number' => db()->query("SELECT number FROM documents WHERE id = $id")->fetchColumn()];
        } catch (ApiError $e) {
            $failed[] = ['row' => $line, 'error' => $e->getMessage()];
        }
    }
    return ['ok' => true, 'created' => $created, 'failed' => $failed, 'warnings' => $warnings, 'tag' => $tag];
}

/** קובץ דוגמה להנפקה: שורת כותרות שהמיפוי מזהה לבד, ושורה לדוגמה. */
function bulk_sample(array $user, int $tid): never
{
    $t = tpl_owned($tid, $user, 'fill');
    send_xlsx(xlsx_write(bulk_sample_rows($t), 'הנפקה'), unseal($t['name_enc']) . ' — קובץ להנפקה');
}

function bulk_sample_rows(array $t): array
{
    $schema = json_decode((string)unseal($t['schema_enc']), true) ?: [];
    $head = ['כותרת המסמך'];
    $ex = [unseal($t['name_enc']) . ' — לדוגמה'];
    foreach ($schema['fields'] ?? [] as $f) {
        if (in_array($f['type'], ['signature', 'file', 'calc', 'table', 'system'], true)) continue;
        $head[] = $f['label'];
        $ex[] = match ($f['type']) { 'date' => bulk_now('d/m/Y'), 'number' => 100, 'checkbox' => 'כן', default => '' };
    }
    foreach (['name', 'phone', 'email'] as $k) { $head[] = 'איש קשר — ' . CONTACT_LABELS[$k]; $ex[] = ''; }
    foreach ($schema['roles'] ?? [] as $r) {
        if ($r['key'] === 'owner') continue;
        $head[] = $r['label'] . ' — שם';
        $head[] = $r['label'] . ' — מייל';
        array_push($ex, '', '');
    }
    return [$head, $ex];
}

/** ערך לתא בייצוא: מספרים כמספר, תאריך כ-14/10/2026, טבלה — שורה לכל פריט. */
function export_value(array $f, string $v, array $att)
{
    if ($v === '') return '';
    $date = fn($s) => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) ? "$m[3]/$m[2]/$m[1]" : $s;
    switch ($f['type']) {
        case 'number': return is_numeric($v) ? $v + 0 : $v;
        case 'calc': return ($f['format'] ?? '') === 'date' ? $date($v) : (is_numeric($v) ? $v + 0 : $v);
        case 'date': return $date($v);
        case 'system': return in_array($f['source'] ?? '', ['issued', 'completed'], true) ? $date($v) : $v;
        case 'checkbox': return $v === '1' ? 'כן' : '';
        case 'select': return select_label($f, $v);
        case 'signature': return 'נחתם';
        case 'file': return implode(', ', array_map(fn($id) => $att[$id] ?? 'קובץ', json_decode($v, true) ?: []));
        case 'table':
            return implode("\n", array_map(fn($r) => implode(' · ', array_filter(array_map(
                fn($c) => cell_display($c, (string)($r[$c['key']] ?? '')), $f['columns'] ?? []), 'strlen')), table_rows($v)));
    }
    return $v;
}

/** ייצוא התשובות של תבנית — לפי הסינון ברשימת המסמכים — לקובץ Excel. */
function doc_export(array $user, array $q): never
{
    $t = tpl_owned((int)($q['template_id'] ?? 0), $user, 'view');
    send_xlsx(xlsx_write(export_rows($user, $t, $q), 'תשובות'), unseal($t['name_enc']) . ' — תשובות ' . bulk_now('d-m-Y'));
}

/** השורות לייצוא (ורישום ביומן של כל מסמך). */
function export_rows(array $user, array $t, array $q): array
{
    $q['template_id'] = $t['id'];
    $list = doc_list($user, $q)['documents'];
    $schema = json_decode((string)unseal($t['schema_enc']), true) ?: [];
    $fields = array_column($schema['fields'] ?? [], null, 'key');
    $docs = [];
    foreach ($list as $view) {
        $d = doc_owned($view['id'], $user, 'view');
        // ננעל רק באישור של בעל התבנית — התשובות לא יוצאות לאקסל לפני שאישר
        $ap = function_exists('doc_approver') ? doc_approver($d) : null;
        if ($ap && $ap !== (int)$user['id'] && $d['status'] !== 'completed') continue;
        $s = json_decode((string)unseal($d['schema_enc']), true) ?: [];
        foreach ($s['fields'] ?? [] as $f) $fields[$f['key']] ??= $f;   // שדות של גרסאות קודמות — בסוף
        $docs[] = [$view, $d, doc_values($view['id'])];
    }
    $head = ['מספר', 'כותרת', 'מצב', 'איש קשר', 'תגיות', 'נוצר', 'הושלם'];
    foreach ($fields as $f) $head[] = $f['label'];
    $out = [$head];
    foreach ($docs as [$view, $d, $values]) {
        $att = array_column(attachments_of($view['id']), 'name', 'id');
        $row = [(string)$view['number'], $view['title'], DOC_STATUS_HE[$view['status']] ?? $view['status'], (string)($view['contact'] ?? ''),
            implode(', ', $view['tags']), bulk_now('d/m/Y H:i', $view['created_at']),
            $view['completed_at'] ? bulk_now('d/m/Y H:i', $view['completed_at']) : ''];
        foreach ($fields as $k => $f) $row[] = export_value($f, (string)($values[$k] ?? ''), $att);
        $out[] = $row;
        log_event($view['id'], 'user:' . $user['id'], 'exported', ['format' => 'xlsx']);
    }
    return $out;
}
