<?php
/**
 * אנשי קשר ותגיות — שלב 8.
 *
 * כרטיס איש קשר שייך לבעליו בלבד (לא משותף), וכל תוכנו מוצפן בעמודה אחת.
 * מסמך מקושר לאיש קשר (documents.contact_id), ושדה בתבנית יכול להצהיר
 * "מתמלא מאיש הקשר" (contactField) — כשבוחרים איש קשר במסמך, השדות האלה
 * מתמלאים לבד.
 *
 * ממלא בקישור הוא תמיד איש קשר (participants.contact_id): בוחרים מהרשימה, או כותבים פרטים
 * ונוצר כרטיס (או נמצא קיים לפי מייל/טלפון). הסנכרון דו-כיווני — עדכון בכרטיס מגיע לממלאים
 * שעוד לא סיימו, ועדכון אצל הממלא מעדכן את הכרטיס. ממלא שסיים — נשאר כמו שהיה (תיעוד).
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

function contacts_migrate(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(participants)')->fetchAll(), 'name');
    if (!in_array('contact_id', $cols, true)) $pdo->exec('ALTER TABLE participants ADD COLUMN contact_id INTEGER REFERENCES contacts(id)');
}

/** טלפון להשוואה: ספרות בלבד, 972 → 0. */
function phone_key(string $p): string
{
    $d = preg_replace('/\D+/', '', $p);
    return str_starts_with($d, '972') ? '0' . substr($d, 3) : $d;
}

/** איש קשר קיים (לא בארכיון) לפי מייל או טלפון — כדי לא ליצור כפילות. */
function contact_find(array $user, string $email, string $phone): ?array
{
    if ($email === '' && phone_key($phone) === '') return null;
    $st = db()->prepare('SELECT * FROM contacts WHERE owner_id = ? AND archived_at IS NULL ORDER BY id');
    $st->execute([$user['id']]);
    foreach ($st->fetchAll() as $row) {
        $c = contact_view($row);
        if ($email !== '' && strcasecmp($c['email'], $email) === 0) return $c;
        if (phone_key($phone) !== '' && phone_key($c['phone']) === phone_key($phone)) return $c;
    }
    return null;
}

/** אנשי קשר שתבנית רשאית להפנות אליהם (ממלא קבוע): של מי ששומר, ומה שכבר היה בה. */
function allowed_contacts(array $user, array $t): array
{
    $st = db()->prepare('SELECT id FROM contacts WHERE owner_id = ? AND archived_at IS NULL');
    $st->execute([$user['id']]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $schema = json_decode((string)unseal($t['schema_enc']), true) ?: [];
    foreach ($schema['roles'] ?? [] as $r) if (!empty($r['contact'])) $ids[] = (int)$r['contact'];
    return array_values(array_unique($ids));
}

/** מה שנשמר אצל הממלא: שם, מייל, טלפון. */
function contact_snap(array $c): string
{
    return seal(json_encode(['name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone']], JSON_UNESCAPED_UNICODE));
}

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
    // ובכמה מסמכים הוא ממלא בקישור
    $st = db()->prepare('SELECT p.contact_id, COUNT(DISTINCT p.document_id) n FROM participants p
        JOIN documents d ON d.id = p.document_id JOIN contacts c ON c.id = p.contact_id
        WHERE c.owner_id = ? AND d.deleted_at IS NULL GROUP BY p.contact_id');
    $st->execute([$user['id']]);
    $fills = array_column($st->fetchAll(), 'n', 'contact_id');
    foreach ($list as &$c) { $c['documents'] = (int)($counts[$c['id']] ?? 0); $c['fills'] = (int)($fills[$c['id']] ?? 0); }
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
        // סנכרון: ממלאים שמקושרים לכרטיס ועוד לא סיימו — מקבלים את הפרטים החדשים
        db()->prepare('UPDATE participants SET contact_enc = ? WHERE contact_id = ? AND completed_at IS NULL')
            ->execute([contact_snap($data), $c['id']]);
        return ['ok' => true, 'id' => (int)$c['id']];
    }
    db()->prepare('INSERT INTO contacts (owner_id, data_enc, created_at) VALUES (?, ?, ?)')->execute([$user['id'], $enc, now_iso()]);
    return ['ok' => true, 'id' => (int)db()->lastInsertId()];
}

/**
 * ייבוא מהטלפון: רשימת {name, phone, email} (מבורר אנשי הקשר של הדפדפן, או מקובץ vCard).
 * מי שכבר קיים (אותו מייל או טלפון) — מדולג; מייל לא תקין — נזרק, השאר נשמר.
 */
function contact_import(array $user, array $b): array
{
    $list = array_slice((array)($b['contacts'] ?? []), 0, 2000);
    $st = db()->prepare('SELECT * FROM contacts WHERE owner_id = ? AND archived_at IS NULL');
    $st->execute([$user['id']]);
    $emails = $phones = [];
    foreach ($st->fetchAll() as $row) {
        $c = contact_view($row);
        if ($c['email'] !== '') $emails[strtolower($c['email'])] = true;
        if (phone_key($c['phone']) !== '') $phones[phone_key($c['phone'])] = true;
    }
    $added = $skipped = 0;
    foreach ($list as $x) {
        $name = mb_substr(trim((string)($x['name'] ?? '')), 0, 200);
        $phone = mb_substr(preg_replace('/[^\d+\- ]/', '', (string)($x['phone'] ?? '')), 0, 20);
        $email = trim((string)($x['email'] ?? ''));
        try { $email = $email !== '' ? valid_email($email) : ''; } catch (ApiError $e) { $email = ''; }
        if ($name === '') $name = $email ?: $phone;
        if ($name === '') { $skipped++; continue; }
        if (($email !== '' && isset($emails[strtolower($email)])) || (phone_key($phone) !== '' && isset($phones[phone_key($phone)]))) { $skipped++; continue; }
        contact_save($user, ['name' => $name, 'phone' => $phone, 'email' => $email, 'tags' => ['מהטלפון']]);
        if ($email !== '') $emails[strtolower($email)] = true;
        if (phone_key($phone) !== '') $phones[phone_key($phone)] = true;
        $added++;
    }
    return ['ok' => true, 'added' => $added, 'skipped' => $skipped];
}

/** ארכיון בלבד — כרטיס שמקושר למסמכים הוא חלק מהתיעוד שלהם. */
function contact_archive(array $user, array $b): array
{
    $c = contact_row($user, (int)($b['id'] ?? 0));
    db()->prepare('UPDATE contacts SET archived_at = ? WHERE id = ?')->execute([empty($b['restore']) ? now_iso() : null, $c['id']]);
    return ['ok' => true];
}

/**
 * הממלא ← איש קשר. contact_id — כרטיס קיים; בלי — לפי הפרטים: כרטיס קיים עם אותו מייל/טלפון,
 * או כרטיס חדש. פרטים שהוקלדו כאן מעדכנים את הכרטיס (ומשם — את שאר הממלאים שמקושרים אליו).
 * בלי פרטים בכלל — הממלא מתנתק.
 */
function part_contact(array $user, array $b): array
{
    [$d, $p] = part_owned($user, $b);
    if ($p['completed_at']) throw new ApiError('הממלא כבר סיים — הפרטים שלו נשארים כמו שהיו', 409);
    $email = trim((string)($b['email'] ?? ''));
    if ($email !== '') $email = valid_email($email);
    $in = ['name' => mb_substr(trim((string)($b['name'] ?? '')), 0, 80), 'email' => $email,
        'phone' => mb_substr(preg_replace('/[^\d+\- ]/', '', (string)($b['phone'] ?? '')), 0, 20)];
    $cid = (int)($b['contact_id'] ?? 0);
    $c = $cid ? contact_view(contact_row($user, $cid))
        : ($in['name'] . $in['email'] . $in['phone'] !== '' ? contact_find($user, $in['email'], $in['phone']) : null);
    if ($c) {
        // מה שהוקלד גובר; שדה ריק — נלקח מהכרטיס
        $changed = false;
        foreach (['name', 'email', 'phone'] as $k) {
            if ($in[$k] !== '' && $in[$k] !== $c[$k]) { $c[$k] = $in[$k]; $changed = true; }
        }
        if ($changed) contact_save($user, $c);
    } elseif ($in['name'] . $in['email'] . $in['phone'] !== '') {
        $c = $in + ['id' => 0];
        $c['name'] = $c['name'] ?: ($c['email'] ?: $c['phone']);
        $c['id'] = contact_save($user, ['name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone']])['id'];
    }
    db()->prepare('UPDATE participants SET contact_enc = ?, contact_id = ? WHERE id = ?')
        ->execute([$c ? contact_snap($c) : null, $c ? (int)$c['id'] : null, $p['id']]);
    // נמען אחר — הקישור הקודם (והאימות שעבר) לא שלו: מתבטל, והחדש יקבל קישור משלו
    $revoked = false;
    if ($p['token_hash'] && $p['contact_id'] && (int)$p['contact_id'] !== (int)($c['id'] ?? 0)) {
        db()->prepare("UPDATE participants SET token_hash = NULL, token_enc = NULL, verified_at = NULL, link_via = NULL,
            status = CASE WHEN status IN ('sent','opened') THEN 'waiting' ELSE status END WHERE id = ?")->execute([$p['id']]);
        log_event((int)$d['id'], 'user:' . $user['id'], 'link_revoked', ['reason' => 'נמען אחר']);
        $revoked = true;
    }
    return ['ok' => true, 'revoked' => $revoked, 'contact_id' => $c ? (int)$c['id'] : null, 'contact' => $c ? ['name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone']] : null];
}

/** איפה איש הקשר ממלא: מסמך, תפקיד, מצב — ומה אפשר לעשות משם (שליחת קישור). */
function contact_fills(array $user, array $q): array
{
    $c = contact_row($user, (int)($q['id'] ?? 0));
    $st = db()->prepare('SELECT p.id, p.document_id FROM participants p JOIN documents d ON d.id = p.document_id
        WHERE p.contact_id = ? AND d.deleted_at IS NULL ORDER BY p.id DESC LIMIT 100');
    $st->execute([$c['id']]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        try { $d = doc_owned((int)$r['document_id'], $user, 'view'); } catch (ApiError $e) { continue; }
        $v = array_values(array_filter(participants_view($d, doc_schema($d)), fn($x) => $x['id'] === (int)$r['id']))[0] ?? null;
        if (!$v) continue;
        $out[] = ['document_id' => (int)$d['id'], 'participant_id' => $v['id'], 'number' => $d['number'],
            'title' => (string)unseal($d['title_enc']), 'doc_status' => $d['status'], 'role' => $v['role'],
            'status' => $v['status'], 'completed_at' => $v['completed_at'], 'blocked' => $v['blocked'],
            'can_send' => !$v['completed_at'] && !$v['blocked'] && in_array($d['status'], ['draft', 'sent', 'in_progress'], true)
                && LEVELS[$d['access']] >= LEVELS['edit']];
    }
    return ['ok' => true, 'fills' => $out];
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
