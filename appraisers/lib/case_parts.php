<?php
/**
 * חלקי התיק (אפיון 04): מזהי נכס, ספר הכתובות וגורמי התיק, משימות, דרישות (הבסיס — הטיפול המלא בפרוסה 06),
 * כרטיס התיק, רשימה וחיפוש, ולשונית הפעילות. ההרשאה לכל פעולה — apr_case_get() (lib/cases.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/cases.php';

const APR_IDENT_KINDS = ['address', 'parcel', 'apartment', 'building_file', 'other'];
const APR_IDENT_SOURCES = ['user', 'document', 'source_check', 'visit'];
const APR_IDENT_STATES = ['entered', 'needs_check', 'verified', 'conflicting'];
const APR_CASE_PARTY_KINDS = ['client', 'lawyer', 'bank', 'architect', 'surveyor', 'other'];
const APR_TASK_STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

// ── מזהי נכס ──────────────────────────────────────────────────

function apr_ident_norm(?string $s): string
{
    return mb_strtolower(trim(preg_replace('/[\s"\'׳״.,\-]+/u', ' ', (string)$s) ?? ''));
}

/** מפתח מנורמל לאיתור "מזהה דומה": אותם גוש, חלקה ותת-חלקה — או אותה כתובת ודירה. NULL כשאין מספיק פרטים. */
function apr_ident_match_key(array $i): ?string
{
    if ($i['kind'] === 'parcel' && $i['gush'] !== null && $i['helka'] !== null) {
        return 'p:' . apr_ident_norm($i['gush']) . '/' . apr_ident_norm($i['helka']) . '/' . apr_ident_norm($i['tat_helka']);
    }
    if (in_array($i['kind'], ['address', 'apartment'], true) && $i['locality'] !== null && $i['street'] !== null && $i['house_no'] !== null) {
        return 'a:' . apr_ident_norm($i['locality']) . '|' . apr_ident_norm($i['street']) . '|' . apr_ident_norm($i['house_no']) . '|' . apr_ident_norm($i['apartment_no']);
    }
    return null;
}

function apr_ident_out(array $i): array
{
    return array_intersect_key($i, array_flip(['pid', 'kind', 'locality', 'municipality', 'street', 'house_no', 'apartment_no', 'gush', 'helka', 'tat_helka', 'value_text',
        'source_kind', 'source_ref', 'source_note', 'state', 'note', 'created_at']));
}

function apr_ident_fields(array $in, string $kind): array
{
    $t = fn(string $k, int $max = 80) => apr_clean_text($in[$k] ?? '', $max);   // ריק = NULL; מספר דירה אינו תת-חלקה — שני שדות, בלי המרה
    $f = array_fill_keys(['locality', 'municipality', 'street', 'house_no', 'apartment_no', 'gush', 'helka', 'tat_helka', 'value_text'], null);
    if ($kind === 'address' || $kind === 'apartment') {
        foreach (['locality', 'municipality', 'street', 'house_no', 'apartment_no'] as $k) $f[$k] = $t($k);
        if ($f['locality'] === null && $f['street'] === null) apr_fail('identifier_empty');
    } elseif ($kind === 'parcel') {
        foreach (['gush', 'helka', 'tat_helka'] as $k) {
            $f[$k] = $t($k, 12);
            if ($f[$k] !== null && !preg_match('/^[0-9]{1,8}$/', $f[$k])) apr_fail('identifier_invalid');
        }
        if ($f['gush'] === null || $f['helka'] === null) apr_fail('identifier_empty');
    } else {
        $f['value_text'] = $t('value_text', 200) ?? apr_fail('identifier_empty');
    }
    return $f;
}

/**
 * הוספת מזהה. התשובה כוללת similar: האם קיים במשרד תיק אחר עם מזהה דומה — ומספרו **רק אם יש לי גישה אליו**.
 * אין איחוד, ואפשר להמשיך.
 */
function apr_case_identifier_add(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'edit');
    $kind = apr_clean_choice($in['kind'] ?? null, APR_IDENT_KINDS) ?? apr_fail('content_invalid');
    $row = apr_ident_fields($in, $kind) + ['kind' => $kind, 'source_kind' => apr_clean_choice($in['source_kind'] ?? 'user', APR_IDENT_SOURCES) ?? 'user',
        'source_ref' => null, 'source_note' => apr_clean_text($in['source_note'] ?? '', 120), 'note' => apr_clean_text($in['note'] ?? '', 300)];
    // 04-C3: מקור מסוג מסמך הוא קובץ אמיתי של התיק הזה — לא הצהרה
    if ($row['source_kind'] === 'document') $row['source_ref'] = apr_source_file($c, $in['source_ref'] ?? null);
    $row['match_key'] = apr_ident_match_key($row);
    return apr_tx(function () use ($ctx, $c, $row) {
        if ((int)apr_val('SELECT COUNT(*) FROM case_identifiers WHERE case_id = ? AND removed_at IS NULL', [$c['id']]) >= 50) apr_fail('content_too_large');
        $pid = apr_pid();
        apr_insert('case_identifiers', ['pid' => $pid, 'case_id' => $c['id'], 'created_by' => $ctx['user']['id'], 'created_at' => apr_now()] + $row);
        apr_case_audit($c, 'case.identifier_add', 'identifier', $pid, ['kind' => $row['kind'], 'source' => $row['source_kind']]);
        apr_case_touch($c);
        return ['identifier' => $pid, 'similar' => apr_ident_similar($ctx, $c, $row['match_key'])];
    });
}

/** תיקים אחרים במשרד עם אותו מפתח. למי שאין גישה לתיק ההוא — רק "קיים", בלי מספר ובלי pid. */
function apr_ident_similar(array $ctx, array $c, ?string $key): ?array
{
    if ($key === null) return null;
    $others = apr_rows('SELECT DISTINCT c.id, c.pid, c.number FROM case_identifiers i JOIN cases c ON c.id = i.case_id
        WHERE i.match_key = ? AND i.removed_at IS NULL AND c.office_id = ? AND c.deleted_at IS NULL AND c.id <> ? ORDER BY c.id', [$key, $c['office_id'], $c['id']]);
    if (!$others) return null;
    $mine = [];
    foreach ($others as $o) {
        if (apr_val('SELECT 1 FROM case_members WHERE case_id = ? AND user_id = ? AND revoked_at IS NULL', [$o['id'], $ctx['user']['id']])) $mine[] = ['pid' => $o['pid'], 'number' => $o['number']];
    }
    return ['exists' => true, 'cases' => $mine];
}

function apr_ident_row(array $c, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row('SELECT * FROM case_identifiers WHERE pid = ? AND case_id = ? AND removed_at IS NULL', [$pid, $c['id']]) : null) ?? apr_fail('not_found');
}

/**
 * עדכון מצב / מקור / הערה של מזהה. **כתובת לבדה אינה זיהוי ודאי**: מזהה מסוג כתובת מסומן verified רק מול **מסמך אמיתי
 * של התיק** (source_kind = document, ו-source_ref הוא קובץ קיים בתיק שלא נמחק). מקור "מסמך" בכל מזהה — תמיד קובץ כזה.
 * בדיקת מקור וביקור (source_check, visit) עוד אינם ישויות — עד הפרוסות שלהם הם אינם מאמתים כתובת.
 */
function apr_case_identifier_update(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'edit');
    return apr_tx(function () use ($c, $in) {
        $i = apr_ident_row($c, $in['identifier'] ?? null);
        $state = array_key_exists('state', $in) ? (apr_clean_choice($in['state'], APR_IDENT_STATES) ?? apr_fail('status_invalid')) : $i['state'];
        $source = array_key_exists('source_kind', $in) ? (apr_clean_choice($in['source_kind'], APR_IDENT_SOURCES) ?? apr_fail('content_invalid')) : $i['source_kind'];
        if ($state === 'verified' && $i['kind'] === 'address' && $source !== 'document') apr_fail('address_not_verifiable');
        $ref = $source === 'document' ? apr_source_file($c, array_key_exists('source_ref', $in) ? $in['source_ref'] : $i['source_ref']) : null;
        apr_exec('UPDATE case_identifiers SET state = ?, source_kind = ?, source_ref = ?, source_note = ?, note = ? WHERE id = ?', [$state, $source, $ref,
            array_key_exists('source_note', $in) ? apr_clean_text($in['source_note'], 120) : $i['source_note'],
            array_key_exists('note', $in) ? apr_clean_text($in['note'], 300) : $i['note'], $i['id']]);
        apr_case_audit($c, 'case.identifier_update', 'identifier', $i['pid'], ['state' => $state, 'source' => $source]);
        apr_case_touch($c);
        return [];
    });
}

function apr_case_identifier_remove(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'edit');
    return apr_tx(function () use ($c, $in) {
        $i = apr_ident_row($c, $in['identifier'] ?? null);
        apr_exec('UPDATE case_identifiers SET removed_at = ? WHERE id = ?', [apr_now(), $i['id']]);
        apr_case_audit($c, 'case.identifier_remove', 'identifier', $i['pid']);
        apr_case_touch($c);
        return [];
    });
}

/** "המזהים לא מתיישבים": כל המזהים מסומנים conflicting, ונוצרת משימת בירור לאחראי. */
function apr_case_identifiers_conflict(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'edit');
    return apr_tx(function () use ($c) {
        apr_exec("UPDATE case_identifiers SET state = 'conflicting' WHERE case_id = ? AND removed_at IS NULL", [$c['id']]);
        $task = null;
        if (!apr_val("SELECT 1 FROM tasks WHERE case_id = ? AND kind = 'clarification' AND link_type = 'identifiers' AND status IN ('open', 'in_progress')", [$c['id']])) {
            $task = apr_pid();
            apr_insert('tasks', ['pid' => $task, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'kind' => 'clarification', 'title' => 'בירור: מזהי הנכס אינם מתיישבים',
                'assignee_user_id' => $c['responsible_user_id'], 'link_type' => 'identifiers', 'created_by' => null, 'created_at' => apr_now(), 'updated_at' => apr_now()]);
        }
        apr_case_audit($c, 'case.identifiers_conflict', 'case', null, ['task' => $task !== null]);
        apr_case_touch($c);
        return ['task' => $task];
    });
}

// ── ספר הכתובות וגורמי התיק ───────────────────────────────────

function apr_contact_out(array $r): array
{
    return ['pid' => $r['pid'], 'name' => $r['name'], 'org' => $r['org'], 'email' => $r['email'], 'phone' => $r['phone'], 'note' => $r['note']];
}

function apr_contact_fields(array $in): array
{
    $email = apr_clean_text($in['email'] ?? '', 200);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) apr_fail('email_invalid');
    return ['name' => apr_clean_name($in['name'] ?? null), 'org' => apr_clean_text($in['org'] ?? '', 120), 'email' => $email === null ? null : mb_strtolower($email),
        'phone' => apr_clean_text($in['phone'] ?? '', 40), 'note' => apr_clean_text($in['note'] ?? '', 300)];
}

/** ספר הכתובות של המשרד — לכל חבר פעיל. q — חיפוש בשם, בארגון, במייל ובטלפון. */
function apr_contacts(array $ctx, array $q): array
{
    $office = apr_office_id($ctx);
    $needle = mb_strtolower(trim(is_string($q['q'] ?? null) ? $q['q'] : ''));
    $rows = apr_rows('SELECT * FROM contacts WHERE office_id = ? AND deleted_at IS NULL ORDER BY name, id', [$office]);
    if ($needle !== '') $rows = array_values(array_filter($rows, fn($r) => str_contains(mb_strtolower(implode(' ', [$r['name'], $r['org'], $r['email'], $r['phone']])), $needle)));
    return ['contacts' => array_map('apr_contact_out', array_slice($rows, 0, 300))];
}

function apr_contact_row(int $office, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row('SELECT * FROM contacts WHERE pid = ? AND office_id = ? AND deleted_at IS NULL', [$pid, $office]) : null) ?? apr_fail('not_found');
}

/** יצירה או עדכון. עדכון מתעדכן בכל התיקים שאיש הקשר בהם — זה אותו אדם. */
function apr_contact_save(array $ctx, array $in): array
{
    $office = apr_office_id($ctx);
    $f = apr_contact_fields($in);
    return apr_tx(function () use ($office, $in, $f) {
        $now = apr_now();
        if (($in['contact'] ?? null) !== null) {
            $r = apr_contact_row($office, $in['contact']);
            apr_exec('UPDATE contacts SET name = ?, org = ?, email = ?, phone = ?, note = ?, updated_at = ? WHERE id = ?', [...array_values($f), $now, $r['id']]);
            // המייל של הנמען השתנה — הקישורים שנשלחו לכתובת הקודמת מבוטלים, ונדרשת שליחה מחדש (07 §7)
            if (mb_strtolower((string)$r['email']) !== (string)$f['email']) apr_requests_on_email_change((int)$r['id']);
            apr_audit('contact.update', 'contact', $r['pid'], [], office: $office);
            return ['contact' => $r['pid']];
        }
        $pid = apr_pid();
        apr_insert('contacts', ['pid' => $pid, 'office_id' => $office, 'created_at' => $now, 'updated_at' => $now] + $f);
        apr_audit('contact.create', 'contact', $pid, [], office: $office);
        return ['contact' => $pid];
    });
}

/** מחיקה — נחסמת כשאיש הקשר משויך לתיק; קודם מסירים אותו מהתיק. */
function apr_contact_delete(array $ctx, array $in): array
{
    $office = apr_office_id($ctx);
    return apr_tx(function () use ($office, $in) {
        $r = apr_contact_row($office, $in['contact'] ?? null);
        if (apr_val('SELECT 1 FROM case_parties cp JOIN cases c ON c.id = cp.case_id WHERE cp.contact_id = ? AND cp.removed_at IS NULL AND c.deleted_at IS NULL', [$r['id']])) apr_fail('contact_in_use');
        apr_exec('UPDATE contacts SET deleted_at = ?, updated_at = ? WHERE id = ?', [apr_now(), apr_now(), $r['id']]);
        apr_audit('contact.delete', 'contact', $r['pid'], [], office: $office);
        return [];
    });
}

function apr_party_row(array $c, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row('SELECT * FROM case_parties WHERE pid = ? AND case_id = ? AND removed_at IS NULL', [$pid, $c['id']]) : null) ?? apr_fail('not_found');
}

/**
 * גורם בתיק: איש קשר מספר הכתובות (contact), או חדש (name …). אותו אדם באותו סוג — שורה אחת; is_orderer מסמן את
 * המזמין, ויש מזמין אחד בתיק.
 */
function apr_case_party_add(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'edit');
    $kind = apr_clean_choice($in['kind'] ?? null, APR_CASE_PARTY_KINDS) ?? apr_fail('content_invalid');
    $orderer = ($in['is_orderer'] ?? false) === true;
    $new = ($in['contact'] ?? null) === null ? apr_contact_fields($in) : null;
    return apr_tx(function () use ($c, $in, $kind, $orderer, $new) {
        $now = apr_now();
        if ($new) {
            $contactPid = apr_pid();
            $contactId = apr_insert('contacts', ['pid' => $contactPid, 'office_id' => $c['office_id'], 'created_at' => $now, 'updated_at' => $now] + $new);
            apr_audit('contact.create', 'contact', $contactPid, [], office: (int)$c['office_id']);
        } else {
            $contactId = (int)apr_contact_row((int)$c['office_id'], $in['contact'])['id'];
        }
        if ($orderer) apr_exec('UPDATE case_parties SET is_orderer = 0 WHERE case_id = ? AND removed_at IS NULL', [$c['id']]);
        $same = apr_row('SELECT * FROM case_parties WHERE case_id = ? AND contact_id = ? AND kind = ? AND removed_at IS NULL', [$c['id'], $contactId, $kind]);
        if ($same) {
            if ($orderer) apr_exec('UPDATE case_parties SET is_orderer = 1 WHERE id = ?', [$same['id']]);
            $pid = $same['pid'];
        } else {
            $pid = apr_pid();
            $id = apr_insert('case_parties', ['pid' => $pid, 'case_id' => $c['id'], 'contact_id' => $contactId, 'kind' => $kind, 'is_orderer' => (int)$orderer,
                'note' => apr_clean_text($in['note'] ?? '', 300), 'created_at' => $now]);
            // דרישות שמחכות לגורם מהסוג הזה — מוקצות לו
            apr_exec("UPDATE requirements SET party_id = ?, updated_at = ? WHERE case_id = ? AND party_id IS NULL AND party_kind = ? AND status = 'required'", [$id, $now, $c['id'], $kind]);
        }
        apr_case_audit($c, 'case.party_add', 'party', $pid, ['kind' => $kind, 'orderer' => $orderer]);
        apr_case_touch($c);
        return ['party' => $pid];
    });
}

function apr_case_party_update(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'edit');
    return apr_tx(function () use ($c, $in) {
        $p = apr_party_row($c, $in['party'] ?? null);
        $orderer = array_key_exists('is_orderer', $in) ? $in['is_orderer'] === true : (bool)$p['is_orderer'];
        if ($orderer) apr_exec('UPDATE case_parties SET is_orderer = 0 WHERE case_id = ? AND removed_at IS NULL', [$c['id']]);
        apr_exec('UPDATE case_parties SET is_orderer = ?, note = ? WHERE id = ?', [(int)$orderer, array_key_exists('note', $in) ? apr_clean_text($in['note'], 300) : $p['note'], $p['id']]);
        apr_case_audit($c, 'case.party_update', 'party', $p['pid'], ['orderer' => $orderer]);
        apr_case_touch($c);
        return [];
    });
}

function apr_case_party_remove(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'edit');
    return apr_tx(function () use ($ctx, $c, $in) {
        $p = apr_party_row($c, $in['party'] ?? null);
        apr_requests_on_party_remove($c, (int)$p['id'], (int)$ctx['user']['id']);     // כל הבקשות שלו מבוטלות (07 §7) — לפני ההסרה
        apr_exec('UPDATE case_parties SET removed_at = ?, is_orderer = 0 WHERE id = ?', [apr_now(), $p['id']]);
        apr_exec("UPDATE requirements SET party_id = NULL, updated_at = ? WHERE case_id = ? AND party_id = ? AND status = 'required'", [apr_now(), $c['id'], $p['id']]);
        apr_case_audit($c, 'case.party_remove', 'party', $p['pid']);
        apr_case_touch($c);
        return [];
    });
}

// ── משימות ────────────────────────────────────────────────────

function apr_task_out(array $t): array
{
    return ['pid' => $t['pid'], 'kind' => $t['kind'], 'title' => $t['title'], 'detail' => $t['detail'], 'status' => $t['status'], 'due_at' => $t['due_at'],
        'assignee' => $t['assignee_user_id'] === null ? null : ['pid' => $t['assignee_pid'] ?? null, 'name' => $t['assignee_name'] ?? null],
        'link_type' => $t['link_type'], 'link_pid' => $t['link_pid'], 'cancel_reason' => $t['cancel_reason'], 'done_at' => $t['done_at']];
}

const APR_TASK_SELECT = 'SELECT t.*, u.pid AS assignee_pid, u.name AS assignee_name FROM tasks t LEFT JOIN users u ON u.id = t.assignee_user_id';

/** מי שיש לו tasks מלא — כל משימה; 'own' — רק משימה שמוקצית לו. */
function apr_task_allowed(array $ctx, array $c, ?array $task): void
{
    $p = $c['perms']['tasks'];
    if ($p === true) return;
    if ($p === 'own' && $task && (int)$task['assignee_user_id'] === (int)$ctx['user']['id']) return;
    apr_fail('forbidden');
}

/** משתתף פעיל בתיק לפי pid — משימה מוקצית רק למי שבתיק. */
function apr_task_assignee(array $c, mixed $userPid): ?int
{
    if ($userPid === null || $userPid === '') return null;
    $id = apr_is_pid($userPid) ? apr_val('SELECT cm.user_id FROM case_members cm JOIN users u ON u.id = cm.user_id WHERE cm.case_id = ? AND u.pid = ? AND cm.revoked_at IS NULL', [$c['id'], $userPid]) : false;
    if ($id === false) apr_fail('assignee_not_member');
    return (int)$id;
}

function apr_clean_due(mixed $v): ?string
{
    $d = apr_clean_date(is_string($v) ? substr($v, 0, 10) : $v);
    return $d === null ? null : $d . 'T00:00:00Z';
}

function apr_task_create(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null);
    if ($c['perms']['tasks'] !== true) apr_fail('forbidden');
    apr_case_writable($c);
    $title = apr_clean_name($in['title'] ?? null, 160);
    $assignee = apr_task_assignee($c, $in['assignee'] ?? null);
    $link = is_string($in['link_type'] ?? null) && in_array($in['link_type'], ['requirement', 'identifier', 'identifiers'], true) ? $in['link_type'] : null;
    $linkPid = null;
    if ($link === 'requirement' || $link === 'identifier') {
        $linkPid = apr_is_pid($in['link_pid'] ?? null) ? $in['link_pid'] : apr_fail('link_invalid');
        if (!apr_val($link === 'requirement' ? 'SELECT 1 FROM requirements WHERE pid = ? AND case_id = ?' : 'SELECT 1 FROM case_identifiers WHERE pid = ? AND case_id = ? AND removed_at IS NULL', [$linkPid, $c['id']])) apr_fail('link_invalid');
    }
    return apr_tx(function () use ($ctx, $c, $in, $title, $assignee, $link, $linkPid) {
        $pid = apr_pid();
        apr_insert('tasks', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'kind' => 'manual', 'title' => $title, 'detail' => apr_clean_text($in['detail'] ?? '', 1000),
            'assignee_user_id' => $assignee, 'due_at' => apr_clean_due($in['due_at'] ?? null), 'link_type' => $link, 'link_pid' => $linkPid,
            'created_by' => $ctx['user']['id'], 'created_at' => apr_now(), 'updated_at' => apr_now()]);
        apr_case_audit($c, 'task.create', 'task', $pid);
        apr_case_touch($c);
        return ['task' => $pid];
    });
}

/** עדכון משימה: כותרת, פירוט, אחראי, יעד, מצב. **ביטול דורש נימוק**; "בוצע" — לא. */
function apr_task_update(array $ctx, array $in): array
{
    $t = apr_is_pid($in['task'] ?? null) ? apr_row('SELECT t.*, c.pid AS case_pid FROM tasks t JOIN cases c ON c.id = t.case_id WHERE t.pid = ?', [$in['task']]) : null;
    $c = apr_case_get($ctx, $t['case_pid'] ?? null);
    apr_task_allowed($ctx, $c, $t);
    apr_case_writable($c);
    $full = $c['perms']['tasks'] === true;
    return apr_tx(function () use ($c, $t, $in, $full) {
        $set = [];
        if ($full) {
            if (array_key_exists('title', $in)) $set['title'] = apr_clean_name($in['title'], 160);
            if (array_key_exists('detail', $in)) $set['detail'] = apr_clean_text($in['detail'], 1000);
            if (array_key_exists('assignee', $in)) $set['assignee_user_id'] = apr_task_assignee($c, $in['assignee']);
            if (array_key_exists('due_at', $in)) $set['due_at'] = apr_clean_due($in['due_at']);
        }
        $meta = [];
        $reason = null;
        if (array_key_exists('status', $in) && $in['status'] !== $t['status']) {
            $to = apr_clean_choice($in['status'], APR_TASK_STATUSES) ?? apr_fail('status_invalid');
            if ($to === 'cancelled') $reason = apr_reason($in['reason'] ?? null);
            $set += ['status' => $to, 'cancel_reason' => $reason, 'done_at' => $to === 'done' ? apr_now() : null];
            $meta = ['from' => $t['status'], 'to' => $to];
        }
        if (!$set) return [];
        apr_exec('UPDATE tasks SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ', updated_at = ? WHERE id = ?', [...array_values($set), apr_now(), $t['id']]);
        apr_case_audit($c, $meta ? 'task.status' : 'task.update', 'task', $t['pid'], $meta, $reason);
        apr_case_touch($c);
        return [];
    });
}

/** "המשימות שלי": הפתוחות, מכל התיקים שאני משתתף בהם במשרד הפעיל, לפי יעד. */
function apr_my_tasks(array $ctx): array
{
    $office = apr_office_id($ctx);
    $rows = apr_rows(APR_TASK_SELECT . " JOIN cases c ON c.id = t.case_id JOIN case_members cm ON cm.case_id = c.id AND cm.user_id = ? AND cm.revoked_at IS NULL
        WHERE t.office_id = ? AND t.assignee_user_id = ? AND t.status IN ('open', 'in_progress') AND c.deleted_at IS NULL ORDER BY (t.due_at IS NULL), t.due_at, t.id",
        [$ctx['user']['id'], $office, $ctx['user']['id']]);
    $cases = array_column(apr_rows('SELECT id, pid, number, title FROM cases WHERE office_id = ?', [$office]), null, 'id');
    return ['tasks' => array_map(fn($t) => apr_task_out($t) + ['case' => ['pid' => $cases[$t['case_id']]['pid'], 'number' => $cases[$t['case_id']]['number'], 'title' => $cases[$t['case_id']]['title']]], $rows)];
}

// ── דרישות (הוספה ועריכה; מצבים, קבצים ובדיקה — lib/documents.php) ──

function apr_requirement_out(array $r): array
{
    return ['pid' => $r['pid'], 'origin' => $r['origin'], 'group' => $r['group_key'], 'doc_type' => $r['doc_type'], 'title' => $r['title'], 'explain_recipient' => $r['explain_recipient'],
        'explain_internal' => $r['explain_internal'], 'priority' => $r['priority'], 'mandatory' => (bool)$r['mandatory'], 'party_kind' => $r['party_kind'],
        'party' => $r['party_pid'] ?? null, 'due_at' => $r['due_at'], 'status' => $r['status'], 'in_profile' => $r['in_profile'] ?? true, 'split' => ($r['split_at'] ?? null) !== null];
}

/** דרישה ידנית בתיק. התאמה בתיק לעולם אינה משנה את הפרופיל. */
function apr_requirement_add(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'documents');
    if ($c['status'] === 'draft') apr_fail('case_state');
    $r = ['title' => apr_clean_name($in['title'] ?? null, 160), 'doc_type' => is_string($in['doc_type'] ?? null) && in_array($in['doc_type'], apr_doc_type_keys(), true) ? $in['doc_type'] : 'other',
        'party_kind' => apr_clean_choice($in['party_kind'] ?? null, APR_PARTY_KINDS) ?? 'client', 'priority' => apr_clean_choice($in['priority'] ?? null, APR_PRIORITIES) ?? 'normal',
        'mandatory' => ($in['mandatory'] ?? false) === true, 'explain_recipient' => apr_clean_text($in['explain_recipient'] ?? '', 600) ?? '', 'sort' => 999];
    return apr_tx(function () use ($c, $r) {
        $pid = apr_case_requirement_insert($c, $r, 'manual');
        apr_case_audit($c, 'requirement.add', 'requirement', $pid, ['mandatory' => $r['mandatory']]);
        apr_case_touch($c);
        return ['requirement' => $pid];
    });
}

/** עריכת דרישה (documents). **הורדת "חובה"** — רק לבעל review_docs, ועם נימוק. דרישה שפוצלה אינה נערכת. */
function apr_requirement_update(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, apr_doc_case_pid('requirements', $in['requirement'] ?? null), 'documents');
    return apr_tx(function () use ($c, $in) {
        apr_case_still_open($c);
        $r = apr_req_row($c, $in['requirement'] ?? null);
        if ($r['split_at'] !== null) apr_fail('req_state');
        $set = [];
        $reason = null;
        if ((int)$r['mandatory'] === 1 && array_key_exists('mandatory', $in) && $in['mandatory'] !== true) {
            if (empty($c['perms']['review_docs'])) apr_fail('forbidden');
            $reason = apr_reason($in['reason'] ?? null);
        }
        if (array_key_exists('title', $in)) $set['title'] = apr_clean_name($in['title'], 160);
        if (array_key_exists('priority', $in)) $set['priority'] = apr_clean_choice($in['priority'], APR_PRIORITIES) ?? apr_fail('content_invalid');
        if (array_key_exists('mandatory', $in)) $set['mandatory'] = (int)($in['mandatory'] === true);
        if (array_key_exists('explain_recipient', $in)) $set['explain_recipient'] = apr_clean_text($in['explain_recipient'], 600) ?? '';
        if (array_key_exists('explain_internal', $in)) $set['explain_internal'] = apr_clean_text($in['explain_internal'], 600) ?? '';
        if (array_key_exists('due_at', $in)) $set['due_at'] = apr_clean_due($in['due_at']);
        if (array_key_exists('party', $in)) $set['party_id'] = $in['party'] === null ? null : (int)apr_party_row($c, $in['party'])['id'];
        if (!$set) return [];
        apr_exec('UPDATE requirements SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ', updated_at = ? WHERE id = ?', [...array_values($set), apr_now(), $r['id']]);
        apr_case_audit($c, 'requirement.update', 'requirement', $r['pid'], ['fields' => array_keys($set)], $reason);
        apr_case_touch($c);
        return [];
    });
}

// ── כרטיס התיק, רשימה, חיפוש ופעילות ──────────────────────────

function apr_case_head(array $c): array
{
    return ['pid' => $c['pid'], 'number' => $c['number'], 'status' => $c['status'], 'title' => $c['title'], 'purpose' => $c['purpose'], 'purpose_note' => $c['purpose_note'],
        'property_type' => $c['property_type'], 'key_date' => $c['key_date'], 'due_date' => $c['due_date'], 'updated_at' => $c['updated_at'], 'created_at' => $c['created_at']];
}

function apr_case_view(array $ctx, array $q): array
{
    $c = apr_case_get($ctx, $q['case'] ?? null);
    $p = apr_row('SELECT p.pid, p.name, p.status, p.deleted_at, cv.number AS latest FROM profiles p LEFT JOIN profile_versions cv ON cv.id = p.current_version_id WHERE p.id = ?', [$c['profile_id']]);
    $vnum = (int)apr_val('SELECT number FROM profile_versions WHERE id = ?', [$c['profile_version_id']]);
    $content = apr_case_version_content((int)$c['profile_version_id']);
    $planKeys = array_flip(array_merge(array_column($content['requirements']['items'], 'key'), ...array_map(fn($r) => array_map(fn($a) => $a['item']['key'] ?? '', $r['then']), $content['rules'])));
    $resp = $c['responsible_user_id'] !== null ? apr_row('SELECT pid, name FROM users WHERE id = ?', [$c['responsible_user_id']]) : null;
    $snap = json_decode($c['plan_snapshot'], true) ?: [];
    // מקרה 9: הכללים רצו פעם אחת, ביצירה. אם היום (דגל ששונה, גורם שנוסף, גרסה חדשה) הפרופיל היה יוצר עוד — המסך מציע לבדוק
    $pending = 0;
    if ($c['status'] !== 'draft' && $p['deleted_at'] === null) {
        $d = apr_case_profile_diff_calc($c);
        $pending = count($d['requirements']['added']) + count($d['tasks']['added']);
    }
    return [
        'case' => apr_case_head($c) + ['flags' => json_decode($c['flags'], true) ?: (object)[], 'responsible' => $resp, 'closed_at' => $c['closed_at'], 'close_reason' => $c['close_reason']],
        'me' => ['role' => $c['my_role'], 'perms' => $c['perms']],
        'todo' => apr_case_todo($c), 'missing' => apr_case_missing($c),
        'open_conflicts' => (int)apr_val("SELECT COUNT(*) FROM conflicts WHERE case_id = ? AND status = 'open'", [$c['id']]),
        'profile' => ['pid' => apr_profile_level($ctx, apr_row('SELECT * FROM profiles WHERE id = ?', [$c['profile_id']])) !== null ? $p['pid'] : null, 'name' => $p['name'],
            'version' => $vnum, 'newer' => $c['status'] !== 'draft' && $p['deleted_at'] === null && (int)$p['latest'] > $vnum ? (int)$p['latest'] : null, 'pending' => $pending,
            'flags' => $content['flags'], 'groups' => $content['requirements']['groups']],
        'plan' => ['alerts' => $snap['alerts'] ?? [], 'skipped' => $snap['skipped'] ?? [], 'applications' => array_map(fn($a) => array_diff_key($a, ['by' => 0]), $snap['applications'] ?? [])],
        'identifiers' => array_map('apr_ident_out', apr_rows('SELECT * FROM case_identifiers WHERE case_id = ? AND removed_at IS NULL ORDER BY id', [$c['id']])),
        'parties' => array_map(fn($r) => ['pid' => $r['pid'], 'kind' => $r['kind'], 'is_orderer' => (bool)$r['is_orderer'], 'note' => $r['note'], 'contact' => apr_contact_out(['pid' => $r['contact_pid']] + $r)],
            apr_rows('SELECT cp.pid, cp.kind, cp.is_orderer, cp.note AS note, ct.pid AS contact_pid, ct.name, ct.org, ct.email, ct.phone FROM case_parties cp JOIN contacts ct ON ct.id = cp.contact_id
                WHERE cp.case_id = ? AND cp.removed_at IS NULL ORDER BY cp.is_orderer DESC, cp.id', [$c['id']])),
        'members' => array_map(fn($r) => ['pid' => $r['pid'], 'name' => $r['name'], 'role' => $r['role'], 'office_role' => $r['office_role'], 'perms' => json_decode($r['perms'], true) ?: (object)[], 'me' => (int)$r['user_id'] === (int)$ctx['user']['id']],
            apr_rows("SELECT cm.user_id, cm.role, cm.perms, u.pid, u.name, m.role AS office_role FROM case_members cm JOIN users u ON u.id = cm.user_id
                LEFT JOIN memberships m ON m.user_id = cm.user_id AND m.office_id = ? WHERE cm.case_id = ? AND cm.revoked_at IS NULL ORDER BY (cm.role = 'responsible') DESC, u.name", [$c['office_id'], $c['id']])),
        'requirements' => array_map(fn($r) => apr_requirement_out($r + ['in_profile' => $r['origin'] === 'manual' || isset($planKeys[$r['origin_key']])]),
            apr_rows('SELECT r.*, cp.pid AS party_pid FROM requirements r LEFT JOIN case_parties cp ON cp.id = r.party_id WHERE r.case_id = ? ORDER BY r.sort, r.id', [$c['id']])),
        'tasks' => array_map('apr_task_out', apr_rows(APR_TASK_SELECT . " WHERE t.case_id = ? ORDER BY (t.status IN ('done', 'cancelled')), (t.due_at IS NULL), t.due_at, t.id", [$c['id']])),
    ];
}

/**
 * רשימת התיקים — **רק תיקים שאני משתתף בהם** (view), במשרד הפעיל. q — חיפוש: מספר, כותרת, כתובת, גוש / חלקה, שם גורם.
 * מסננים: status, responsible (pid), property_type, orderer (pid של איש קשר), due_before, open_mandatory=1.
 */
function apr_cases_list(array $ctx, array $q): array
{
    $office = apr_office_id($ctx);
    $uid = (int)$ctx['user']['id'];
    $where = ['c.office_id = ?', 'c.deleted_at IS NULL'];
    $args = [$uid, $office];
    if (is_string($q['status'] ?? null) && in_array($q['status'], APR_CASE_STATUSES, true)) { $where[] = 'c.status = ?'; $args[] = $q['status']; }
    elseif (($q['closed'] ?? '') !== '1') $where[] = "c.status <> 'closed'";
    if (is_string($q['property_type'] ?? null) && in_array($q['property_type'], APR_PROPERTY_TYPES, true)) { $where[] = 'c.property_type = ?'; $args[] = $q['property_type']; }
    if (apr_is_pid($q['responsible'] ?? null)) { $where[] = 'c.responsible_user_id = (SELECT id FROM users WHERE pid = ?)'; $args[] = $q['responsible']; }
    if (apr_is_pid($q['orderer'] ?? null)) {
        $where[] = 'EXISTS (SELECT 1 FROM case_parties cp JOIN contacts ct ON ct.id = cp.contact_id WHERE cp.case_id = c.id AND cp.removed_at IS NULL AND cp.is_orderer = 1 AND ct.pid = ?)';
        $args[] = $q['orderer'];
    }
    if (is_string($q['due_before'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $q['due_before'])) { $where[] = 'c.due_date IS NOT NULL AND c.due_date <= ?'; $args[] = $q['due_before']; }
    if (($q['open_mandatory'] ?? '') === '1') $where[] = 'EXISTS (SELECT 1 FROM requirements r WHERE r.case_id = c.id AND ' . apr_req_open_sql('r.') . ')';
    $needle = trim(is_string($q['q'] ?? null) ? mb_substr($q['q'], 0, 100) : '');
    if ($needle !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($needle)) . '%';
        $parts = ["lower(c.number) LIKE ? ESCAPE '\\'", "lower(c.title) LIKE ? ESCAPE '\\'",
            "EXISTS (SELECT 1 FROM case_identifiers i WHERE i.case_id = c.id AND i.removed_at IS NULL AND lower(coalesce(i.locality, '') || ' ' || coalesce(i.street, '') || ' ' || coalesce(i.house_no, '') || ' ' || coalesce(i.value_text, '')) LIKE ? ESCAPE '\\')",
            "EXISTS (SELECT 1 FROM case_parties cp JOIN contacts ct ON ct.id = cp.contact_id WHERE cp.case_id = c.id AND cp.removed_at IS NULL AND lower(ct.name || ' ' || coalesce(ct.org, '')) LIKE ? ESCAPE '\\')"];
        array_push($args, $like, $like, $like, $like);
        // "גוש 6158 חלקה 12", "6158/12", "6158 12" — מספרים בלבד: הראשון גוש, השני חלקה
        if (preg_match_all('/\d+/', $needle, $nums) && !preg_match('/^\d{4}-\d+$/', $needle)) {
            $parts[] = 'EXISTS (SELECT 1 FROM case_identifiers i WHERE i.case_id = c.id AND i.removed_at IS NULL AND i.gush = ?' . (isset($nums[0][1]) ? ' AND i.helka = ?' : '') . ')';
            $args[] = $nums[0][0];
            if (isset($nums[0][1])) $args[] = $nums[0][1];
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    $rows = apr_rows('SELECT c.*, u.name AS responsible_name, u.pid AS responsible_pid,
            (SELECT COUNT(*) FROM requirements r WHERE r.case_id = c.id AND ' . apr_req_open_sql('r.') . ') AS open_mandatory,
            (SELECT COUNT(*) FROM tasks t WHERE t.case_id = c.id AND t.status IN (\'open\', \'in_progress\') AND t.due_at IS NOT NULL AND t.due_at < ?) AS overdue_tasks,
            (SELECT COUNT(*) FROM conflicts x WHERE x.case_id = c.id AND x.status = \'open\') AS open_conflicts
        FROM cases c JOIN case_members cm ON cm.case_id = c.id AND cm.user_id = ? AND cm.revoked_at IS NULL LEFT JOIN users u ON u.id = c.responsible_user_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY c.updated_at DESC, c.id DESC LIMIT 200', [apr_now(), ...$args]);
    return ['cases' => array_map(fn($c) => apr_case_head($c) + ['responsible' => $c['responsible_pid'] === null ? null : ['pid' => $c['responsible_pid'], 'name' => $c['responsible_name']],
        'open_mandatory' => (int)$c['open_mandatory'], 'overdue_tasks' => (int)$c['overdue_tasks'], 'open_conflicts' => (int)$c['open_conflicts']], $rows)];
}

/** לשונית הפעילות: השורות של התיק ביומן הפעולות, מהחדשה לישנה — עם שם המבצע. */
function apr_case_activity(array $ctx, array $q): array
{
    $c = apr_case_get($ctx, $q['case'] ?? null);
    return ['rows' => array_map(fn($r) => ['at' => $r['at'], 'action' => $r['action'], 'entity_type' => $r['entity_type'], 'meta' => json_decode($r['meta'], true) ?: (object)[], 'reason' => $r['reason'],
        'actor' => $r['name'], 'actor_kind' => $r['actor_kind'], 'entity' => in_array($r['entity_type'], ['requirement', 'file'], true) ? $r['entity_pid'] : null],
        apr_rows('SELECT a.*, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id WHERE a.case_id = ? ORDER BY a.id DESC LIMIT 300', [$c['id']]))];
}
