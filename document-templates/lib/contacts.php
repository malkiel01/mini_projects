<?php
/**
 * אנשי קשר ותגיות — שלב 8.
 *
 * כרטיס איש קשר שייך לבעליו בלבד (לא משותף), וכל תוכנו מוצפן בעמודה אחת.
 * מסמך מקושר לאיש קשר (documents.contact_id), ושדה בתבנית יכול להצהיר
 * "מתמלא מאיש הקשר" (contactField) — כשבוחרים איש קשר במסמך, השדות האלה
 * מתמלאים לבד.
 *
 * תגיות נשמרות מוצפנות (tags_enc) בתבנית ובמסמך. לכן הסינון לפי תגית, איש קשר
 * וחיפוש חופשי קורה אחרי פענוח, ב-PHP — לא ב-SQL. במסדי הגודל שלנו (מאות
 * מסמכים לחשבון) זה מהיר מספיק, וזה המחיר של "גם המפתח לא רואה".
 */

declare(strict_types=1);

require_once __DIR__ . '/sharing.php';

const CONTACT_FIELDS = ['name', 'id_number', 'phone', 'email', 'address', 'notes'];
const CONTACT_LABELS = ['name' => 'שם', 'id_number' => 'ת"ז / ח"פ', 'phone' => 'טלפון', 'email' => 'מייל',
    'address' => 'כתובת', 'notes' => 'הערות'];

function clean_tags($tags): array
{
    $out = [];
    foreach ((array)$tags as $t) {
        $t = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$t)), 0, 40);
        if ($t !== '' && !in_array($t, $out, true)) $out[] = $t;
        if (count($out) >= 20) break;
    }
    return $out;
}

function tags_of(?string $enc): array
{
    return $enc === null ? [] : (json_decode((string)unseal($enc), true) ?: []);
}

// ── אנשי קשר ─────────────────────────────────────────────────

function contact_row(array $user, int $id): array
{
    $st = db()->prepare('SELECT * FROM contacts WHERE id = ? AND owner_id = ?');
    $st->execute([$id, $user['id']]);
    $c = $st->fetch();
    if (!$c) throw new ApiError('איש הקשר לא נמצא', 404);
    return $c;
}

function contact_view(array $c): array
{
    $data = json_decode((string)unseal($c['data_enc']), true) ?: [];
    $out = ['id' => (int)$c['id'], 'archived' => $c['archived_at'] !== null, 'created_at' => $c['created_at']];
    foreach (CONTACT_FIELDS as $k) $out[$k] = (string)($data[$k] ?? '');
    $out['tags'] = clean_tags($data['tags'] ?? []);
    return $out;
}

function contact_list(array $user, array $q): array
{
    $st = db()->prepare('SELECT * FROM contacts WHERE owner_id = ?' . (empty($q['archived']) ? ' AND archived_at IS NULL' : '') . ' ORDER BY id DESC');
    $st->execute([$user['id']]);
    $list = array_map('contact_view', $st->fetchAll());
    usort($list, fn($a, $b) => strcmp($a['name'], $b['name']));

    // כמה מסמכים מקושרים לכל איש קשר — מסמכים של המשתמש בלבד.
    $st = db()->prepare('SELECT contact_id, COUNT(*) n FROM documents WHERE owner_id = ? AND deleted_at IS NULL AND contact_id IS NOT NULL GROUP BY contact_id');
    $st->execute([$user['id']]);
    $counts = array_column($st->fetchAll(), 'n', 'contact_id');
    foreach ($list as &$c) $c['documents'] = (int)($counts[$c['id']] ?? 0);
    return ['ok' => true, 'contacts' => $list];
}

function contact_save(array $user, array $b): array
{
    $data = [];
    foreach (CONTACT_FIELDS as $k) $data[$k] = mb_substr(trim((string)($b[$k] ?? '')), 0, $k === 'notes' ? 2000 : 200);
    if ($data['name'] === '') throw new ApiError('שם הוא חובה', 422);
    if ($data['email'] !== '') $data['email'] = valid_email($data['email']);
    $data['tags'] = clean_tags($b['tags'] ?? []);
    $enc = seal(json_encode($data, JSON_UNESCAPED_UNICODE));

    if (!empty($b['id'])) {
        $c = contact_row($user, (int)$b['id']);
        db()->prepare('UPDATE contacts SET data_enc = ? WHERE id = ?')->execute([$enc, $c['id']]);
        return ['ok' => true, 'id' => (int)$c['id']];
    }
    db()->prepare('INSERT INTO contacts (owner_id, data_enc, created_at) VALUES (?, ?, ?)')->execute([$user['id'], $enc, now_iso()]);
    return ['ok' => true, 'id' => (int)db()->lastInsertId()];
}

/** ארכיון בלבד — כרטיס שמקושר למסמכים הוא חלק מהתיעוד שלהם. */
function contact_archive(array $user, array $b): array
{
    $c = contact_row($user, (int)($b['id'] ?? 0));
    db()->prepare('UPDATE contacts SET archived_at = ? WHERE id = ?')->execute([empty($b['restore']) ? now_iso() : null, $c['id']]);
    return ['ok' => true];
}

/**
 * מקשר איש קשר למסמך, וממלא את השדות שמסומנים "מאיש הקשר". שדה שכבר מלא
 * לא נדרס, אלא אם overwrite. שדות של משתתף שכבר נשלח אליו קישור — לא נוגעים.
 * איש הקשר חייב להיות של המשתמש עצמו (גם במסמך ששותף איתו).
 */
function doc_contact(array $user, array $b): array
{
    $d = doc_owned((int)($b['id'] ?? 0), $user, 'fill');
    if (!in_array($d['status'], DOC_EDITABLE, true)) throw new ApiError('המסמך נעול', 409);
    $cid = (int)($b['contact_id'] ?? 0);
    if ($cid === 0) {
        db()->prepare('UPDATE documents SET contact_id = NULL WHERE id = ?')->execute([$d['id']]);
        log_event((int)$d['id'], 'user:' . $user['id'], 'contact_set', ['name' => '']);
        return ['ok' => true, 'filled' => 0];
    }
    $c = contact_view(contact_row($user, $cid));
    $schema = doc_schema($d);
    $values = doc_values((int)$d['id']);
    $st = db()->prepare('SELECT role_key FROM participants WHERE document_id = ? AND notified_at IS NOT NULL');
    $st->execute([$d['id']]);
    $sent = $st->fetchAll(PDO::FETCH_COLUMN);

    $fill = [];
    foreach ($schema['fields'] as $f) {
        $src = $f['contactField'] ?? '';
        if ($src === '' || ($c[$src] ?? '') === '' || (!in_array('owner', field_roles($f), true) && array_intersect(field_roles($f), $sent))) continue;
        if (trim((string)($values[$f['key']] ?? '')) !== '' && empty($b['overwrite'])) continue;
        $fill[$f['key']] = $c[$src];
    }
    db()->prepare('UPDATE documents SET contact_id = ? WHERE id = ?')->execute([$cid, $d['id']]);
    log_event((int)$d['id'], 'user:' . $user['id'], 'contact_set', ['name' => $c['name']]);
    if ($fill) doc_save($user, ['id' => $d['id'], 'values' => $fill]);
    return ['ok' => true, 'filled' => count($fill), 'values' => $fill];
}

// ── חיפוש וסינון במסמכים ─────────────────────────────────────

/** האם המסמך עונה על הסינון. $row — שורת documents, $view — מה שחוזר לרשימה. */
function doc_matches(array $row, array $view, array $q, array $values): bool
{
    if (!empty($q['tag']) && !in_array($q['tag'], $view['tags'], true)) return false;
    if (!empty($q['contact_id']) && (int)$row['contact_id'] !== (int)$q['contact_id']) return false;
    if (!empty($q['from']) && substr($row['created_at'], 0, 10) < $q['from']) return false;
    if (!empty($q['to']) && substr($row['created_at'], 0, 10) > $q['to']) return false;
    if (($q['by'] ?? '') === 'me' && $view['owner_name'] !== null) return false;
    if (($q['by'] ?? '') === 'shared' && $view['owner_name'] === null) return false;
    $term = mb_strtolower(trim((string)($q['q'] ?? '')));
    if ($term === '') return true;
    // חיפוש חופשי: מספר, כותרת, תבנית, תגיות, איש קשר, וכל ערך טקסטואלי במסמך.
    $hay = [$view['number'] ?? '', $view['title'], $view['template'], implode(' ', $view['tags']), $view['contact'] ?? ''];
    foreach ($values as $v) if (!str_starts_with($v, 'data:') && !str_starts_with($v, '[')) $hay[] = $v;
    return str_contains(mb_strtolower(implode("\n", $hay)), $term);
}

// ── תגיות ────────────────────────────────────────────────────
// פעולה נפרדת מהשמירה: תגיות הן קטלוג, לא תוכן. אפשר לתייג גם מסמך שהושלם
// ונעול, וגם תבנית בלי ליצור לה גרסה חדשה.

function doc_tags(array $user, array $b): array
{
    $d = doc_owned((int)($b['id'] ?? 0), $user, 'fill');
    $new = clean_tags($b['tags'] ?? []);
    $old = tags_of($d['tags_enc']);
    if ($new !== $old) {
        db()->prepare('UPDATE documents SET tags_enc = ? WHERE id = ?')->execute([seal(json_encode($new, JSON_UNESCAPED_UNICODE)), $d['id']]);
        log_event((int)$d['id'], 'user:' . $user['id'], 'tags_set', ['from' => implode(', ', $old), 'to' => implode(', ', $new)]);
    }
    return ['ok' => true, 'tags' => $new];
}

function tpl_tags(array $user, array $b): array
{
    $t = tpl_owned((int)($b['id'] ?? 0), $user, 'edit');
    $new = clean_tags($b['tags'] ?? []);
    db()->prepare('UPDATE templates SET tags_enc = ? WHERE id = ?')->execute([seal(json_encode($new, JSON_UNESCAPED_UNICODE)), $t['id']]);
    return ['ok' => true, 'tags' => $new];
}
