<?php
/**
 * מסמכים — שלב 4: הנפקה מתבנית, מילוי עצמי, שמירה אוטומטית, יומן ומספור.
 *
 * מסמך נקשר לגרסה של התבנית שהייתה נוכחית ברגע ההנפקה, ונשאר עליה לתמיד.
 * ההנפקה הראשונה מסמנת את התבנית "בשימוש" (used = 1): מעתה עריכה של התבנית
 * יוצרת גרסה חדשה, ואת התבנית אי אפשר עוד למחוק — רק להעביר לארכיון.
 *
 * היומן (events) — הוספה בלבד, וכל רשומה נושאת hash של הקודמת לה במסמך.
 * מחיקה או שינוי של רשומה באמצע נראים בבדיקת השרשרת (doc_verify_chain).
 */

declare(strict_types=1);

require_once __DIR__ . '/templates.php';

const DOC_MAX_VALUE     = 200 * 1024;   // חתימה (תמונה) היא הערך הגדול ביותר
const DOC_EDITABLE      = ['draft', 'sent', 'in_progress'];
const NUMBERING_SCHEMES = ['prefix_year_seq', 'seq', 'year_month_seq', 'yearly', 'pattern', 'none'];

// ── מספור ────────────────────────────────────────────────────

function clean_numbering($n): array
{
    $n = is_array($n) ? $n : [];
    $scheme = in_array($n['scheme'] ?? '', NUMBERING_SCHEMES, true) ? $n['scheme'] : 'prefix_year_seq';
    return [
        'scheme'  => $scheme,
        'prefix'  => mb_substr(trim((string)($n['prefix'] ?? '')), 0, 20),
        'digits'  => max(1, min(8, (int)($n['digits'] ?? 4))),
        'pattern' => mb_substr(trim((string)($n['pattern'] ?? '{prefix}-{yyyy}-{seq}')), 0, 60),
    ];
}

/** המונה מתאפס לפי התקופה: שנתי — כל שנה, חודשי — כל חודש, אחרת לעולם לא. */
function numbering_period(array $n, int $ts): string
{
    return match ($n['scheme']) {
        'yearly'         => gmdate('Y', $ts),
        'year_month_seq' => gmdate('Y-m', $ts),
        default          => 'all',
    };
}

function format_number(array $n, int $seq, int $ts): ?string
{
    $pad = str_pad((string)$seq, $n['digits'], '0', STR_PAD_LEFT);
    $y = gmdate('Y', $ts);
    $m = gmdate('m', $ts);
    $join = fn(...$parts) => implode('-', array_filter($parts, fn($p) => $p !== ''));
    return match ($n['scheme']) {
        'prefix_year_seq' => $join($n['prefix'], $y, $pad),
        'seq'             => $join($n['prefix'], $pad),
        'year_month_seq'  => $join($n['prefix'], "$y/$m") . '-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT),
        'yearly'          => $join($n['prefix'], $y, $pad),
        'pattern'         => strtr($n['pattern'], ['{prefix}' => $n['prefix'], '{yyyy}' => $y, '{mm}' => $m, '{seq}' => $pad]),
        'none'            => null,
    };
}

/** חייב לרוץ בתוך טרנזקציה: שתי הנפקות במקביל לא יקבלו אותו מספר. */
function next_number(int $templateId, array $n): ?string
{
    if ($n['scheme'] === 'none') return null;
    $ts = time();
    $period = numbering_period($n, $ts);
    db()->prepare(
        'INSERT INTO counters (template_id, period, last_number) VALUES (?, ?, 1)
         ON CONFLICT(template_id, period) DO UPDATE SET last_number = last_number + 1'
    )->execute([$templateId, $period]);
    $st = db()->prepare('SELECT last_number FROM counters WHERE template_id = ? AND period = ?');
    $st->execute([$templateId, $period]);
    return format_number($n, (int)$st->fetchColumn(), $ts);
}

// ── יומן ─────────────────────────────────────────────────────

function log_event(int $docId, string $actor, string $type, ?array $detail = null): void
{
    $st = db()->prepare('SELECT hash FROM events WHERE document_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$docId]);
    $prev = $st->fetchColumn() ?: '';
    $at = now_iso();
    $detailJson = $detail === null ? '' : json_encode($detail, JSON_UNESCAPED_UNICODE);
    $hash = hash('sha256', implode("\n", [$prev, $docId, $actor, $type, $detailJson, $at]));
    db()->prepare(
        'INSERT INTO events (document_id, actor, type, detail_enc, ip, user_agent, prev_hash, hash, at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$docId, $actor, $type, $detail === null ? null : seal($detailJson), client_ip(),
        mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), $prev ?: null, $hash, $at]);
}

/** true אם השרשרת שלמה: כל רשומה מצביעה על הקודמת, וה-hash שלה תואם לתוכן. */
function doc_verify_chain(int $docId): bool
{
    $st = db()->prepare('SELECT * FROM events WHERE document_id = ? ORDER BY id');
    $st->execute([$docId]);
    $prev = '';
    foreach ($st->fetchAll() as $e) {
        $detail = $e['detail_enc'] === null ? '' : (string)unseal($e['detail_enc']);
        if ((string)$e['prev_hash'] !== $prev) return false;
        if (!hash_equals($e['hash'], hash('sha256', implode("\n", [$prev, $docId, $e['actor'], $e['type'], $detail, $e['at']])))) return false;
        $prev = $e['hash'];
    }
    return true;
}

// ── גישה ─────────────────────────────────────────────────────

/** מסמך שיש למשתמש גישה אליו ברמה $min לפחות (שלב 7: גם משותף). מוסיף 'access'. */
function doc_owned(int $id, array $user, string $min = 'owner'): array
{
    $st = db()->prepare(
        'SELECT d.*, v.schema_enc, v.template_id, v.version AS template_version, t.name_enc AS template_name_enc
         FROM documents d
         JOIN template_versions v ON v.id = d.template_version_id
         JOIN templates t ON t.id = v.template_id
         WHERE d.id = ? AND d.deleted_at IS NULL'
    );
    $st->execute([$id]);
    $d = $st->fetch() ?: null;
    $d['access'] = require_level($d ? access_level('document', $id, (int)$d['owner_id'], $user) : null, $min, 'המסמך לא נמצא');
    return $d;
}

/** שלב 11: הערכים שהשרת מחשב (שדות מחושבים וטבלאות) — מוחזרים לדפדפן אחרי שמירה. */
function computed_values(array $schema, array $values): array
{
    $out = [];
    foreach ($schema['fields'] ?? [] as $f) {
        if (in_array($f['type'], ['calc', 'table', 'system'], true)) $out[$f['key']] = $values[$f['key']] ?? '';
    }
    return $out;
}

function doc_values(int $docId): array
{
    $st = db()->prepare('SELECT field_key, value_enc FROM field_values WHERE document_id = ? AND row_index = 0');
    $st->execute([$docId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['field_key']] = (string)unseal($r['value_enc']);
    return $out;
}

/** כמה שדות חובה מולאו — לרשימה ולסיום. */
function doc_progress(array $schema, array $values): array
{
    // שלב 11: שדה מוסתר (תנאי הצגה) אינו חובה
    $req = array_filter(visible_fields($schema, $values), fn($f) => $f['required'] && !in_array($f['type'], ['calc', 'system'], true));
    $done = array_filter($req, fn($f) => trim((string)($values[$f['key']] ?? '')) !== '');
    return ['required' => count($req), 'filled' => count($done),
        'missing' => array_values(array_map(fn($f) => $f['label'], array_diff_key($req, $done)))];
}

// ── פעולות ───────────────────────────────────────────────────

/** $copy: ערכים התחלתיים במקום ברירות המחדל (שכפול). מחזיר גם את המזהה — לשכפול שממשיך מכאן. */
function doc_create(array $user, array $b, ?array $copy = null): array
{
    $t = tpl_owned((int)($b['template_id'] ?? 0), $user, 'fill');
    if ($t['archived_at']) throw new ApiError('התבנית בארכיון. שחזרו אותה כדי להנפיק', 409);
    $schema = json_decode((string)unseal($t['schema_enc']), true) ?: [];
    if (empty($schema['fields'])) throw new ApiError('לתבנית אין שדות עדיין. הוסיפו שדות בעורך', 409);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $number = next_number((int)$t['id'], clean_numbering(json_decode((string)$t['numbering_json'], true)));
        $title = trim((string)($b['title'] ?? '')) ?: (string)unseal($t['name_enc']);
        $now = now_iso();
        $pdo->prepare(
            'INSERT INTO documents (owner_id, template_version_id, number, title_enc, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$user['id'], $t['version_id'], $number, seal(mb_substr($title, 0, 200)), 'draft', $now]);
        $docId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE templates SET used = 1 WHERE id = ?')->execute([$t['id']]);

        $ins = $pdo->prepare(
            'INSERT INTO field_values (document_id, field_key, row_index, value_enc, updated_by, updated_at) VALUES (?, ?, 0, ?, ?, ?)'
        );
        foreach ($schema['fields'] as $f) {
            if (in_array($f['type'], ['calc', 'system'], true)) continue;
            $v = $copy !== null && array_key_exists($f['key'], $copy['values']) ? $copy['values'][$f['key']] : field_default($f);
            if ($v !== '') $ins->execute([$docId, $f['key'], seal($v), 'user:' . $user['id'], $now]);
        }
        doc_recalc($docId, $schema, 'user:' . $user['id']);
        create_participants($docId, $schema, $user);
        // אישור רק דרך בעל התבנית: הוא רואה את המסמך ומאשר אותו (המנפיק לא נועל לבד)
        if (!empty($schema['approval_owner']) && (int)$t['owner_id'] !== (int)$user['id']) {
            $pdo->prepare("INSERT OR IGNORE INTO shares (object_type, object_id, user_id, level, granted_by, created_at) VALUES ('document', ?, ?, 'co_owner', ?, ?)")
                ->execute([$docId, $t['owner_id'], $user['id'], $now]);
        }
        if ($copy !== null) doc_copy_extras($docId, $copy, $user);
        log_event($docId, 'user:' . $user['id'], 'created', ['number' => $number, 'template_version' => (int)$t['current_version']]
            + ($copy !== null ? ['copy_of' => $copy['number']] : []));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'id' => $docId, 'number' => $number];
}

/**
 * שכפול: מסמך חדש מאותה תבנית (בגרסה הנוכחית), עם הערכים של המסמך הזה — להדפסה חוזרת או לשינוי קטן.
 * לא עוברים: חתימות וקבצים (שייכים למסמך שבו נחתמו / הועלו), שדות מחושבים (מחושבים מחדש), וערך
 * ששדה בתבנית כבר לא מקבל (שדה שנמחק, סוג שהשתנה, אפשרות שהוסרה). איש הקשר והממלאים — רק במסמך שלי.
 */
function doc_duplicate(array $user, array $b): array
{
    $src = doc_owned((int)($b['id'] ?? 0), $user, 'view');
    $old = array_column(json_decode((string)unseal($src['schema_enc']), true)['fields'] ?? [], null, 'key');
    $t = tpl_owned((int)$src['template_id'], $user, 'fill');
    $schema = json_decode((string)unseal($t['schema_enc']), true) ?: [];
    $vals = doc_values((int)$src['id']);
    $values = [];
    foreach ($schema['fields'] ?? [] as $f) {
        if (in_array($f['type'], ['calc', 'system', 'signature', 'file'], true)) continue;
        if (!isset($vals[$f['key']]) || ($old[$f['key']]['type'] ?? '') !== $f['type']) continue;   // שדה שרוקן במקור — נשאר ריק
        try { $values[$f['key']] = clean_value($f, $vals[$f['key']]); } catch (ApiError) { /* ערך שהשדה כבר לא מקבל */ }
    }
    $mine = (int)$src['owner_id'] === (int)$user['id'];
    $r = doc_create($user, ['template_id' => $t['id'], 'title' => (string)unseal($src['title_enc'])], [
        'values' => $values, 'number' => $src['number'] ?: '#' . $src['id'], 'src' => (int)$src['id'], 'mine' => $mine,
        'contact_id' => $mine ? $src['contact_id'] : null, 'tags_enc' => $src['tags_enc'],
    ]);
    $skipped = array_values(array_map(fn($f) => $f['label'], array_filter($schema['fields'] ?? [],
        fn($f) => in_array($f['type'], ['signature', 'file'], true) && ($vals[$f['key']] ?? '') !== '')));
    return $r + ['copied' => count($values), 'skipped' => $skipped];
}

/** בשכפול: איש הקשר, התגיות, ומי ממלא כל תפקיד (בלי הקישורים — ממלא מקבל קישור חדש). */
function doc_copy_extras(int $docId, array $copy, array $user): void
{
    db()->prepare('UPDATE documents SET contact_id = ?, tags_enc = ? WHERE id = ?')->execute([$copy['contact_id'], $copy['tags_enc'], $docId]);
    if (!$copy['mine']) return;
    $st = db()->prepare('SELECT role_key, contact_id, contact_enc FROM participants WHERE document_id = ?');
    $st->execute([$copy['src']]);
    $up = db()->prepare('UPDATE participants SET contact_id = ?, contact_enc = ? WHERE document_id = ? AND role_key = ?');
    foreach ($st->fetchAll() as $p) {
        if ($p['contact_enc'] !== null || $p['contact_id'] !== null) $up->execute([$p['contact_id'], $p['contact_enc'], $docId, $p['role_key']]);
    }
}

function doc_list(array $user, array $q): array
{
    $sql = 'SELECT d.*, v.schema_enc, v.template_id, t.name_enc AS template_name_enc
            FROM documents d
            JOIN template_versions v ON v.id = d.template_version_id
            JOIN templates t ON t.id = v.template_id
            WHERE ' . mine_or_shared_sql('d', 'document') . ' AND d.deleted_at IS NULL';
    $args = [':uid' => $user['id']];
    if (!empty($q['template_id'])) { $sql .= ' AND v.template_id = :tid'; $args[':tid'] = (int)$q['template_id']; }
    if (!empty($q['status'])) { $sql .= ' AND d.status = :st'; $args[':st'] = (string)$q['status']; }
    if (empty($q['archived'])) $sql .= ' AND d.archived_at IS NULL';
    $st = db()->prepare($sql . ' ORDER BY d.created_at DESC, d.id DESC LIMIT 2000');
    $st->execute($args);

    $out = [];
    $tags = [];
    foreach ($st->fetchAll() as $d) {
        $schema = json_decode((string)unseal($d['schema_enc']), true) ?: [];
        $values = doc_values((int)$d['id']);
        $p = doc_progress($schema, $values);
        $view = [
            'id' => (int)$d['id'], 'number' => $d['number'], 'title' => unseal($d['title_enc']),
            'template_id' => (int)$d['template_id'], 'template' => unseal($d['template_name_enc']),
            'status' => $d['status'], 'created_at' => $d['created_at'], 'completed_at' => $d['completed_at'],
            'archived' => $d['archived_at'] !== null, 'required' => $p['required'], 'filled' => $p['filled'],
            'access' => access_level('document', (int)$d['id'], (int)$d['owner_id'], $user),
            'owner_name' => (int)$d['owner_id'] !== (int)$user['id'] ? user_name((int)$d['owner_id']) : null,
            'tags' => tags_of($d['tags_enc']),
            'contact_id' => $d['contact_id'] ? (int)$d['contact_id'] : null,
            'contact' => doc_contact_name($d, $user),
        ];
        foreach ($view['tags'] as $t) $tags[$t] = true;
        if (doc_matches($d, $view, $q, $values)) $out[] = $view;
        if (count($out) >= 500) break;
    }
    $all = array_keys($tags);
    sort($all);
    return ['ok' => true, 'documents' => $out, 'tags' => $all];
}

/** שם איש הקשר — רק אם הכרטיס של המשתמש. במסמך משותף, כרטיס של הבעלים לא נחשף. */
function doc_contact_name(array $d, array $user): ?string
{
    if (!$d['contact_id'] || (int)$d['owner_id'] !== (int)$user['id']) return null;
    $st = db()->prepare('SELECT * FROM contacts WHERE id = ? AND owner_id = ?');
    $st->execute([$d['contact_id'], $user['id']]);
    $c = $st->fetch();
    return $c ? contact_view($c)['name'] : null;
}

function doc_get(array $user, int $id): array
{
    $d = doc_owned($id, $user, 'view');
    $schema = doc_schema($d);   // כולל מי ממלא מה בשיתוף הזה
    $values = doc_values($id);
    $a = tpl_asset_row((int)$d['template_version_id']);

    $st = db()->prepare('SELECT * FROM events WHERE document_id = ? ORDER BY id');
    $st->execute([$id]);
    $manage = LEVELS[$d['access']] >= LEVELS['edit'];   // כתובות IP ופרטי קשר של לקוחות — רק למי שמנהל את המסמך
    $events = array_map(fn($e) => [
        'type' => $e['type'], 'actor' => $e['actor'], 'by' => actor_name($e['actor'], $d), 'at' => $e['at'], 'ip' => $manage ? $e['ip'] : null,
        'detail' => $e['detail_enc'] === null ? null : json_decode((string)unseal($e['detail_enc']), true),
    ], $st->fetchAll());

    return ['ok' => true, 'document' => [
        'id' => $id, 'number' => $d['number'], 'title' => unseal($d['title_enc']), 'status' => $d['status'],
        'access' => $d['access'], 'owner_name' => $d['access'] === 'owner' ? null : user_name((int)$d['owner_id']),
        'tags' => tags_of($d['tags_enc']), 'contact_id' => $d['contact_id'] ? (int)$d['contact_id'] : null,
        'contact' => doc_contact_name($d, $user),
        'template_id' => (int)$d['template_id'], 'template' => unseal($d['template_name_enc']),
        'template_version' => (int)$d['template_version'], 'mime' => $a['mime'],
        'created_at' => $d['created_at'], 'completed_at' => $d['completed_at'], 'final_hash' => $d['final_hash'],
        'archived' => $d['archived_at'] !== null,
        'schema' => attach_options($schema), 'values' => $values, 'progress' => doc_progress($schema, $values),
        'ref' => $d['external_ref'], 'events' => $events, 'fonts' => fonts_info($schema), 'chain_ok' => doc_verify_chain($id),
        'lists' => lists_for($schema),
        'participants' => array_map(fn($p) => $manage ? $p : ['email' => '', 'phone' => ''] + $p, participants_view($d, doc_schema($d))),
        'awaiting_approval' => (!empty($schema['approval']) || doc_approver($d)) && doc_awaiting($d, doc_schema($d)),
        'approver' => ($ap = doc_approver($d)) ? ['name' => user_name($ap), 'me' => $ap === (int)$user['id']] : null,
        'attachments' => attachments_of($id),
    ]];
}

/** מי עשה: שם המשתמש, או "<תפקיד> (<שם>)" למשתתף מקישור. */
function actor_name(string $actor, array $d): string
{
    [$kind, $id] = array_pad(explode(':', $actor, 2), 2, '0');
    if ($kind === 'user') return user_name((int)$id);
    if ($kind === 'dev') return 'מפתח המערכת (' . user_name((int)$id) . ')';
    if ($kind === 'system') return 'המערכת';
    static $cache = [];
    if (!isset($cache[$actor])) {
        $st = db()->prepare('SELECT * FROM participants WHERE id = ?');
        $st->execute([(int)$id]);
        $p = $st->fetch();
        $cache[$actor] = $p ? role_of(doc_schema($d), $p['role_key'])['label'] . (($n = contact_of($p)['name']) ? " ($n)" : '') : 'משתתף';
    }
    return $cache[$actor];
}

/** קובץ הרקע של הגרסה שהמסמך נקשר אליה — לא של הגרסה הנוכחית של התבנית. */
function doc_asset(array $user, int $id): never
{
    $d = doc_owned($id, $user, 'view');
    $a = tpl_asset_row((int)$d['template_version_id']);
    $bytes = asset_bytes($a);
    header('Content-Type: ' . $a['mime']);
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
}

function clean_value(array $f, $v, int $docId = 0): string
{
    if (is_bool($v)) $v = $v ? '1' : '';
    $v = (string)$v;
    if (strlen($v) > DOC_MAX_VALUE) throw new ApiError('הערך בשדה "' . $f['label'] . '" גדול מדי', 413);
    switch ($f['type']) {
        case 'checkbox':
            return $v === '' || $v === '0' ? '' : '1';
        case 'number':
            $v = trim($v);
            if ($v !== '' && !is_numeric($v)) throw new ApiError('"' . $f['label'] . '": נדרש מספר', 422);
            return $v;
        case 'date':
            $v = trim($v);
            if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) throw new ApiError('"' . $f['label'] . '": תאריך לא תקין', 422);
            return $v;
        case 'signature':
            if ($v !== '' && !preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $v)) {
                throw new ApiError('חתימה לא תקינה', 422);
            }
            return $v;
        case 'file':
            // הערך: רשימת מזהי קבצים שהועלו לשדה הזה במסמך הזה. הסרה = שמירה בלי המזהה.
            $ids = $v === '' ? [] : json_decode($v, true);
            if (!is_array($ids)) throw new ApiError('ערך קבצים לא תקין', 422);
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $st = db()->prepare("SELECT COUNT(*) FROM attachments WHERE document_id = ? AND field_key = ? AND id IN ($in)");
                $st->execute(array_merge([$docId, $f['key']], $ids));
                if ((int)$st->fetchColumn() !== count($ids)) throw new ApiError('קובץ לא שייך לשדה', 422);
            }
            return $ids ? json_encode($ids) : '';
        case 'select':
            return clean_select_value($f, $v);
        case 'text':
            return mb_substr(preg_replace('/[\r\n]+/', ' ', $v), 0, 2000);
        case 'table':
            // שלב 11: שורות — רק העמודות של הטבלה; עמודה מחושבת מחושבת בשרת (doc_recalc)
            $rows = $v === '' ? [] : json_decode($v, true);
            if (!is_array($rows)) throw new ApiError('"' . $f['label'] . '": טבלה לא תקינה', 422);
            if (count($rows) > 200) throw new ApiError('"' . $f['label'] . '": עד 200 שורות', 422);
            $out = [];
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $row = [];
                foreach ($f['columns'] ?? [] as $c) {
                    if ($c['type'] === 'calc') continue;
                    $cell = mb_substr(trim(preg_replace('/[\r\n]+/', ' ', (string)($r[$c['key']] ?? ''))), 0, 1000);
                    if ($c['type'] === 'select' && $cell !== '' && !in_array($cell, !empty($c['list']) ? list_level_values($c['list']) : ($c['options'] ?? []), true)) {
                        throw new ApiError('"' . $f['label'] . ' ← ' . $c['label'] . '": בחרו מהרשימה', 422);
                    }
                    if ($c['type'] === 'number' && $cell !== '' && !is_numeric($cell)) {
                        throw new ApiError('"' . $f['label'] . ' ← ' . $c['label'] . '": נדרש מספר', 422);
                    }
                    $row[$c['key']] = $cell;
                }
                $out[] = $row;
            }
            return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : '';
        default:
            return mb_substr($v, 0, 20000);
    }
}

/**
 * שלב 11: מחשב מחדש עמודות מחושבות בטבלאות ושדות מחושבים, ושומר מה שהשתנה.
 * נגזר מהערכים — לא נרשם ביומן בנפרד (היומן מתעד את מה שהוקלד).
 */
function doc_recalc(int $docId, array $schema, string $actor): void
{
    $fields = $schema['fields'] ?? [];
    $hasCalc = false;
    foreach ($fields as $f) if (in_array($f['type'], ['calc', 'system'], true) || ($f['type'] === 'table' && in_array('calc', array_column($f['columns'] ?? [], 'type'), true))) $hasCalc = true;
    if (!$hasCalc) return;
    $values = doc_values($docId);
    $set = [];
    // שדות שמתמלאים לבד — מהמסמך עצמו (לפני החישובים, כדי שנוסחה תוכל להשתמש בהם)
    $sys = array_filter($fields, fn($f) => $f['type'] === 'system');
    if ($sys) {
        $d = db()->query('SELECT * FROM documents WHERE id = ' . $docId)->fetch();
        foreach ($sys as $f) {
            $v = match ($f['source'] ?? 'number') {
                'number' => (string)$d['number'],
                'issued' => bulk_now('Y-m-d', $d['created_at']),
                'owner' => user_name((int)$d['owner_id']),
                'title' => (string)unseal($d['title_enc']),
                'completed' => $d['completed_at'] ? bulk_now('Y-m-d', $d['completed_at']) : '',
                default => '',
            };
            if ($v !== ($values[$f['key']] ?? '')) $set[$f['key']] = $values[$f['key']] = $v;
        }
    }
    foreach ($fields as $f) {
        if ($f['type'] !== 'table') continue;
        $rows = table_rows($values[$f['key']] ?? '');
        if (!$rows) continue;
        $v = json_encode(table_compute($f, $fields, $values, $rows), JSON_UNESCAPED_UNICODE);
        if ($v !== ($values[$f['key']] ?? '')) $set[$f['key']] = $values[$f['key']] = $v;
    }
    $set += calc_all($schema, $values);
    if (!$set) return;
    $up = db()->prepare(
        'INSERT INTO field_values (document_id, field_key, row_index, value_enc, updated_by, updated_at) VALUES (?, ?, 0, ?, ?, ?)
         ON CONFLICT(document_id, field_key, row_index) DO UPDATE SET
           value_enc = excluded.value_enc, updated_by = excluded.updated_by, updated_at = excluded.updated_at'
    );
    $now = now_iso();
    foreach ($set as $k => $v) $up->execute([$docId, $k, seal($v), $actor, $now]);
}

/**
 * שמירה אוטומטית: רק השדות שהשתנו. ביומן — רשומה אחת לכל שמירה, עם הערך הקודם
 * והחדש של כל שדה (מוצפן). חתימה ביומן נרשמת כ"נחתם", לא כתמונה.
 */
function doc_save(array $user, array $b): array
{
    $d = doc_owned((int)($b['id'] ?? 0), $user, 'fill');
    if (!in_array($d['status'], DOC_EDITABLE, true)) throw new ApiError('המסמך נעול', 409);
    $schema = doc_schema($d);   // כולל מי ממלא מה בשיתוף
    $byKey = array_column($schema['fields'] ?? [], null, 'key');
    $old = doc_values((int)$d['id']);
    $actor = 'user:' . $user['id'];
    $now = now_iso();

    $changes = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare(
            'INSERT INTO field_values (document_id, field_key, row_index, value_enc, updated_by, updated_at)
             VALUES (?, ?, 0, ?, ?, ?)
             ON CONFLICT(document_id, field_key, row_index) DO UPDATE SET
               value_enc = excluded.value_enc, updated_by = excluded.updated_by, updated_at = excluded.updated_at'
        );
        // אחרי שנשלח קישור — שדות של משתתף שייכים לו. לפני כן המנפיק יכול למלא מראש.
        $sentTo = db()->prepare("SELECT role_key FROM participants WHERE document_id = ? AND notified_at IS NOT NULL");
        $sentTo->execute([$d['id']]);
        $sentTo = $sentTo->fetchAll(PDO::FETCH_COLUMN);
        foreach ((array)($b['values'] ?? []) as $key => $v) {
            $f = $byKey[$key] ?? null;
            if (!$f || in_array($f['type'], ['calc', 'system'], true)) continue;   // מחושב / מתמלא לבד — רק השרת כותב
            // נשלח לממלא — המנפיק כבר לא כותב, אלא אם השדה משותף גם לו
            if (!in_array('owner', field_roles($f), true) && array_intersect(field_roles($f), $sentTo)) {
                throw new ApiError('השדה "' . $f['label'] . '" כבר נשלח למילוי של המשתתף', 409);
            }
            $v = clean_value($f, $v, (int)$d['id']);
            $prev = $old[$key] ?? '';
            if ($v === $prev) continue;
            $up->execute([$d['id'], $key, seal($v), $actor, $now]);
            $changes[] = $f['type'] === 'signature'
                ? ['key' => $key, 'label' => $f['label'], 'signed' => $v !== '']
                : ['key' => $key, 'label' => $f['label'], 'from' => $prev, 'to' => $v];
        }
        if (array_key_exists('tags', $b)) {
            $new = clean_tags($b['tags']);
            $old = tags_of($d['tags_enc']);
            if ($new !== $old) {
                $pdo->prepare('UPDATE documents SET tags_enc = ? WHERE id = ?')->execute([seal(json_encode($new, JSON_UNESCAPED_UNICODE)), $d['id']]);
                $changes[] = ['key' => '_tags', 'label' => 'תגיות', 'from' => implode(', ', $old), 'to' => implode(', ', $new)];
            }
        }
        if (array_key_exists('title', $b)) {
            $title = mb_substr(trim((string)$b['title']), 0, 200);
            if ($title !== '' && $title !== unseal($d['title_enc'])) {
                $pdo->prepare('UPDATE documents SET title_enc = ? WHERE id = ?')->execute([seal($title), $d['id']]);
                $changes[] = ['key' => '_title', 'label' => 'כותרת', 'from' => unseal($d['title_enc']), 'to' => $title];
            }
        }
        if ($changes) doc_recalc((int)$d['id'], $schema, $actor);
        if ($changes) {
            if ($d['status'] === 'draft') $pdo->prepare("UPDATE documents SET status = 'in_progress' WHERE id = ?")->execute([$d['id']]);
            log_event((int)$d['id'], $actor, 'values_saved', ['changes' => $changes]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    if (function_exists('purge_attachments')) purge_attachments((int)$d['id']);   // קובץ שהוסר משדה — נמחק
    $values = doc_values((int)$d['id']);
    return ['ok' => true, 'saved' => count($changes), 'saved_at' => $now,
        'progress' => doc_progress($schema, $values), 'computed' => computed_values($schema, $values)];
}

/**
 * סיום: כל שדות החובה מלאים → המסמך ננעל, ונשמרת טביעת אצבע של הערכים.
 * ה-PDF נבנה מהתבנית ומהערכים, ולכן כל הפקה שלו מאותו מסמך זהה בתוכן.
 */
function doc_complete(array $user, array $b): array
{
    $d = doc_owned((int)($b['id'] ?? 0), $user, 'edit');
    if (!in_array($d['status'], DOC_EDITABLE, true)) throw new ApiError('המסמך כבר הושלם', 409);
    $schema = doc_schema($d);
    $values = doc_values((int)$d['id']);
    $p = doc_progress($schema, $values);
    if ($p['missing']) throw new ApiError('חסרים שדות חובה: ' . implode(', ', $p['missing']), 422);

    $approver = doc_approver($d);
    if ($approver && $approver !== (int)$user['id']) {
        throw new ApiError('המסמך הזה ננעל רק באישור של ' . user_name($approver) . ' — "📨 שליחה לאישור"', 403);
    }
    $approving = ($schema['approval'] ?? false) || $approver;
    doc_finalize($d, 'user:' . $user['id'], $approving ? 'approved' : 'completed');
    if ($approver && (int)$d['owner_id'] !== $approver) {
        $st = db()->prepare('SELECT email, display_name FROM users WHERE id = ?');
        $st->execute([$d['owner_id']]);
        if ($o = $st->fetch()) send_mail($o['email'], 'המסמך אושר: ' . unseal($d['title_enc']),
            "שלום {$o['display_name']},\n\n" . user_name($approver) . ' אישר/ה ונעל/ה את המסמך "' . unseal($d['title_enc']) . "\".\n\n" . app_base_url() . '#doc=' . $d['id']);
    }
    if (!empty($schema['fill_download'])) notify_ready($d, $schema);
    return ['ok' => true, 'hash' => db()->query('SELECT final_hash FROM documents WHERE id = ' . (int)$d['id'])->fetchColumn()];
}

/** נעילה וטביעת אצבע. נקרא מ"סיום" של המנפיק, או כשהמשתתף האחרון סיים. */
function doc_finalize(array $d, string $actor, string $event = 'completed'): void
{
    // קודם תאריך ההשלמה, ואז החישובים — כך ששדה "תאריך השלמה" נכלל בטביעת האצבע
    $now = now_iso();
    db()->prepare('UPDATE documents SET completed_at = ? WHERE id = ?')->execute([$now, $d['id']]);
    doc_recalc((int)$d['id'], doc_schema($d), $actor);   // הערכים המחושבים — סופיים לפני טביעת האצבע
    $values = doc_values((int)$d['id']);
    ksort($values);
    $hash = hash('sha256', json_encode(['template_version_id' => (int)$d['template_version_id'], 'values' => $values],
        JSON_UNESCAPED_UNICODE));
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE documents SET status = 'completed', completed_at = ?, final_hash = ? WHERE id = ?")
        ->execute([$now, $hash, $d['id']]);
    log_event((int)$d['id'], $actor, $event, ['hash' => $hash]);
    $pdo->commit();
    webhook_fire((int)$d['id'], 'document.completed', ['hash' => $hash]);
}

function doc_archive(array $user, array $b): array
{
    $d = doc_owned((int)($b['id'] ?? 0), $user, 'co_owner');
    $restore = !empty($b['restore']);
    db()->prepare('UPDATE documents SET archived_at = ? WHERE id = ?')->execute([$restore ? null : now_iso(), $d['id']]);
    log_event((int)$d['id'], 'user:' . $user['id'], $restore ? 'restored' : 'archived');
    return ['ok' => true];
}

/** רישום הורדה ביומן — מי הוריד ומתי. */
function doc_downloaded(array $user, array $b): array
{
    $d = doc_owned((int)($b['id'] ?? 0), $user, 'view');
    log_event((int)$d['id'], 'user:' . $user['id'], 'downloaded');
    return ['ok' => true];
}

/** מחיקה (רכה) — רק לטיוטה שלא מולא בה דבר. כל השאר: ארכיון. */
function doc_delete(array $user, array $b): array
{
    $d = doc_owned((int)($b['id'] ?? 0), $user, 'owner');
    if ($d['status'] !== 'draft') throw new ApiError('במסמך כבר מולאו פרטים — אפשר רק להעביר לארכיון', 409);
    db()->prepare('UPDATE documents SET deleted_at = ? WHERE id = ?')->execute([now_iso(), $d['id']]);
    db()->prepare("UPDATE share_invites SET revoked_at = ? WHERE object_type = 'document' AND object_id = ? AND used_at IS NULL AND revoked_at IS NULL")
        ->execute([now_iso(), $d['id']]);
    log_event((int)$d['id'], 'user:' . $user['id'], 'deleted');
    return ['ok' => true];
}

require_once __DIR__ . '/participants.php';
require_once __DIR__ . '/bulk.php';
require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/devzone.php';

/**
 * מי מאשר את המסמך, כשהתבנית מחייבת "אישור רק דרכי": בעל התבנית — אם מישהו אחר הנפיק. אחרת null.
 */
function doc_approver(array $d): ?int
{
    $schema = doc_schema($d);
    if (empty($schema['approval_owner'])) return null;
    static $owners = [];
    $tid = (int)$d['template_id'];
    if (!isset($owners[$tid])) {
        $st = db()->prepare('SELECT owner_id FROM templates WHERE id = ?');
        $st->execute([$tid]);
        $owners[$tid] = (int)$st->fetchColumn();
    }
    return $owners[$tid] && $owners[$tid] !== (int)$d['owner_id'] ? $owners[$tid] : null;
}

/** המנפיק שולח לאישור של בעל התבנית (כשהתבנית מחייבת "אישור רק דרכי"). */
function doc_submit(array $user, array $b): array
{
    $d = doc_owned((int)($b['id'] ?? 0), $user, 'edit');
    if (!in_array($d['status'], DOC_EDITABLE, true)) throw new ApiError('המסמך כבר נעול', 409);
    $approver = doc_approver($d);
    if (!$approver) throw new ApiError('במסמך הזה אין צורך באישור — אפשר לסיים', 409);
    $schema = doc_schema($d);
    $p = doc_progress($schema, doc_values((int)$d['id']));
    if ($p['missing']) throw new ApiError('חסרים שדות חובה: ' . implode(', ', $p['missing']), 422);
    log_event((int)$d['id'], 'user:' . $user['id'], 'submitted', ['to' => user_name($approver)]);
    $st = db()->prepare('SELECT email FROM users WHERE id = ?');
    $st->execute([$approver]);
    $title = (string)unseal($d['title_enc']);
    if ($mail = $st->fetchColumn()) send_mail($mail, 'ממתין לאישורך: ' . $title,
        ($user['display_name'] ?? '') . " שלח/ה לאישורך את המסמך:\n$title" . ($d['number'] ? " ({$d['number']})" : '') .
        "\n\nלבדיקה ולאישור (או להחזרה לתיקון):\n" . app_base_url() . '#doc=' . $d['id']);
    return ['ok' => true, 'approver' => user_name($approver)];
}
