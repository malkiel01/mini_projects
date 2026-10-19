<?php
/**
 * משתתפים — שלב 5: קישור אישי למילוי ולחתימה, בלי חשבון.
 *
 * כל תפקיד בתבנית (חוץ מ"אני") הופך במסמך לשורת participants. מנהל הטופס
 * ממלא פרטי קשר ומנפיק קישור. הקישור נושא אסימון אקראי; בשרת נשמר רק ה-hash
 * שלו, ולכן קישור שאבד לא משוחזר — מנפיקים חדש, והקודם מפסיק לעבוד.
 *
 * האסימון עובר בכותרת X-Fill-Token ולא בכתובת: בכתובת הדף הוא יושב אחרי #,
 * שלא נשלח לשרת ולא נרשם ביומני הגישה.
 *
 * מה הממלא רואה: את כל המסמך (כמו בכל חוזה — חותמים על מסמך שלם), אבל
 * עורך רק את השדות של התפקיד שלו. יומן, פרטי משתתפים אחרים וכתובות IP —
 * רק אצל מנהל הטופס.
 *
 * ברצף (sequential): התור עובר לפי סדר התפקידים בתבנית. "אני" תמיד ראשון,
 * ולכן קישור ראשון יוצא רק אחרי ששדות החובה של המנפיק מלאים.
 */

declare(strict_types=1);

require_once __DIR__ . '/documents.php';

const OTP_MINUTES  = 10;
const OTP_MAX_TRY  = 5;
const OTP_COOLDOWN = 60;
const OTP_MAX_SENDS = 8;   // קודים ליום לממלא אחד

// ── עזרים ────────────────────────────────────────────────────

function doc_schema(array $d): array
{
    $s = json_decode((string)unseal($d['schema_enc']), true) ?: [];
    $s['roles'] ??= [['key' => 'owner', 'label' => 'אני (המנפיק)', 'auth' => 'none']];
    $s['sequential'] ??= false;
    // שיתוף למילוי: מי ממלא כל שדה במסמך הזה — גובר על התבנית (doc_assign)
    $a = !empty($d['assign_enc']) ? (json_decode((string)unseal($d['assign_enc']), true) ?: []) : [];
    if ($a) {
        if (!in_array('link', array_column($s['roles'], 'key'), true)) {
            $s['roles'][] = ['key' => 'link', 'label' => (string)($a['label'] ?? 'לקוח'), 'auth' => ($a['auth'] ?? '') === 'email' ? 'email' : 'none', 'contact' => 0];
        }
        $keys = array_column($s['roles'], 'key');
        foreach ($s['fields'] ?? [] as $i => $f) {
            if (!isset($a['fields'][$f['key']]) || !empty($f['no_link'])) continue;
            $r = array_values(array_intersect((array)$a['fields'][$f['key']], $keys)) ?: ['owner'];
            $s['fields'][$i]['role'] = $r[0];
            $s['fields'][$i]['roles'] = count($r) > 1 ? $r : [];
        }
    }
    return $s;
}

/**
 * שיתוף למילוי — לכל שדה: מי ממלא אותו במסמך הזה. {id, fields: {"<key>": ["owner"|"link"|<תפקיד>, ...]}, label?, auth?}
 * שדה "לעולם לא בקישור" — תמיד רק המנפיק. ממלא שמקבל שדות ועדיין אין לו שורה — נוצרת (וזה הקישור שלו).
 */
function doc_assign(array $user, array $b): array
{
    $d = doc_owned((int)($b['id'] ?? 0), $user, 'edit');
    if (!in_array($d['status'], DOC_EDITABLE, true)) throw new ApiError('המסמך כבר נעול', 409);
    $base = json_decode((string)unseal($d['schema_enc']), true) ?: [];
    $roleKeys = array_merge(array_column($base['roles'] ?? [], 'key'), ['owner', 'link']);
    $fields = [];
    foreach ($base['fields'] ?? [] as $f) {
        if (in_array($f['type'], ['calc', 'system'], true) || !isset($b['fields'][$f['key']])) continue;
        $r = array_values(array_unique(array_intersect((array)$b['fields'][$f['key']], $roleKeys)));
        if (!empty($f['no_link'])) $r = ['owner'];
        $fields[$f['key']] = $r ?: ['owner'];
    }
    $label = mb_substr(trim((string)($b['label'] ?? '')), 0, 60) ?: 'לקוח';
    $auth = ($b['auth'] ?? '') === 'email' ? 'email' : 'none';
    // קוד למייל אחרי שהקישור כבר נשלח (לא בוואטסאפ) — רק אם יש לו מייל; אחרת הוא נתקע באמצע
    $cur = array_column(participants_of((int)$d['id']), null, 'role_key')['link'] ?? null;
    if ($auth === 'email' && $cur && $cur['notified_at'] && ($cur['link_via'] ?? '') !== 'whatsapp' && !$cur['verified_at'] && contact_of($cur)['email'] === '') {
        throw new ApiError('הקישור כבר נשלח לממלא בלי מייל — אי אפשר לדרוש ממנו קוד למייל', 422);
    }
    db()->prepare('UPDATE documents SET assign_enc = ? WHERE id = ?')
        ->execute([seal(json_encode(['fields' => $fields, 'label' => $label, 'auth' => $auth], JSON_UNESCAPED_UNICODE)), $d['id']]);
    // ממלאים: לכל תפקיד שמקבל שדה — שורה (קישור); "לקוח" שכבר לא מקבל כלום ועוד לא נשלח — יורד
    $used = [];
    foreach ($fields as $r) foreach ($r as $k) if ($k !== 'owner') $used[$k] = true;
    $have = array_column(participants_of((int)$d['id']), null, 'role_key');
    foreach (array_keys($used) as $k) {
        if (isset($have[$k])) { if ($k === 'link') db()->prepare('UPDATE participants SET auth = ? WHERE id = ?')->execute([$auth, $have[$k]['id']]); continue; }
        db()->prepare('INSERT INTO participants (document_id, role_key, auth, status, sort_order) VALUES (?, ?, ?, ?, ?)')
            ->execute([$d['id'], $k, $k === 'link' ? $auth : 'none', 'waiting', count($have) + 1]);
    }
    // ממלא שלא מקבל אף שדה ועוד לא נשלח — יורד (גם תפקיד מהתבנית: אחרת "לקוח" כפול, והמסמך לא נסגר לעולם)
    foreach ($have as $k => $row) {
        if (!isset($used[$k]) && !$row['notified_at'] && !$row['completed_at']) db()->prepare('DELETE FROM participants WHERE id = ?')->execute([$row['id']]);
    }
    log_event((int)$d['id'], 'user:' . $user['id'], 'assigned', ['fields' => count(array_filter($fields, fn($r) => $r !== ['owner']))]);
    return ['ok' => true];
}

/** כל מה שנשלח למילוי בקישור: מסמך, תבנית, למי, ומה המצב. */
function fill_shares(array $user): array
{
    $st = db()->prepare(
        "SELECT p.*, d.id AS doc_id, d.number, d.title_enc, d.status AS doc_status, d.created_at AS doc_created,
                d.owner_id, d.assign_enc, v.schema_enc, v.template_id, t.name_enc AS template_name_enc
         FROM participants p JOIN documents d ON d.id = p.document_id
         JOIN template_versions v ON v.id = d.template_version_id JOIN templates t ON t.id = v.template_id
         WHERE d.deleted_at IS NULL AND d.archived_at IS NULL AND " . mine_or_shared_sql('d', 'document') . '
         ORDER BY COALESCE(p.completed_at, p.opened_at, p.notified_at, d.created_at) DESC LIMIT 300'
    );
    $st->execute([':uid' => $user['id']]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        if (!$r['notified_at'] && !$r['assign_enc']) continue;   // ממלא מתבנית במסמך שאף פעם לא נשלח — זה לא "שיתוף"
        $schema = doc_schema($r);
        $c = contact_of($r);
        // פרטי הקשר של הלקוח — רק למי שמנהל את המסמך (עורך ומעלה)
        $lvl = access_level('document', (int)$r['doc_id'], (int)$r['owner_id'], $user);
        if (LEVELS[$lvl ?? 'view'] < LEVELS['edit']) { $c['email'] = ''; $c['phone'] = ''; }
        $dd = array_merge($r, ['id' => (int)$r['doc_id'], 'status' => $r['doc_status']]);   // המסמך — לא השורה של הממלא
        $awaiting = $r['doc_status'] !== 'completed' && $r['completed_at'] && (!empty($schema['approval']) || doc_approver($dd)) && doc_awaiting($dd, $schema);
        $out[] = ['doc_id' => (int)$r['doc_id'], 'number' => $r['number'], 'title' => (string)unseal($r['title_enc']), 'template' => (string)unseal($r['template_name_enc']),
            'role' => role_of($schema, $r['role_key'])['label'], 'name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone'],
            'status' => $r['doc_status'] === 'completed' ? 'done' : ($awaiting ? 'awaiting' : ($r['completed_at'] ? 'filled' : ($r['opened_at'] ? 'opened' : ($r['notified_at'] ? 'sent' : 'waiting')))),
            'sent_at' => $r['notified_at'], 'opened_at' => $r['opened_at'], 'filled_at' => $r['completed_at'], 'created_at' => $r['doc_created']];
    }
    return ['ok' => true, 'shares' => $out];
}

function role_of(array $schema, string $key): array
{
    foreach ($schema['roles'] as $r) if ($r['key'] === $key) return $r;
    return ['key' => $key, 'label' => $key, 'auth' => 'none'];
}

function participants_of(int $docId): array
{
    $st = db()->prepare('SELECT * FROM participants WHERE document_id = ? ORDER BY sort_order, id');
    $st->execute([$docId]);
    return $st->fetchAll();
}

function contact_of(array $p): array
{
    $c = json_decode((string)unseal($p['contact_enc']), true);
    return is_array($c) ? $c + ['name' => '', 'email' => '', 'phone' => ''] : ['name' => '', 'email' => '', 'phone' => ''];
}

/** מי יכול למלא את השדה: אחד, או כמה ("או פלוני או אלמוני"). */
function field_roles(array $f): array
{
    return !empty($f['roles']) ? $f['roles'] : [$f['role'] ?? 'owner'];
}

/** תפקידים שעוד יכולים למלא: המנפיק (עד הסיום), וכל משתתף שעוד לא סיים. */
function open_roles(int $docId): array
{
    $open = ['owner'];
    foreach (participants_of($docId) as $p) if (!$p['completed_at']) $open[] = $p['role_key'];
    return $open;
}

/**
 * שדות החובה של תפקיד אחד שעדיין ריקים. שדה משותף לכמה ממלאים — לא חוסם את
 * מי שמסיים, כל עוד יש ממלא אחר שעוד יכול להשלים אותו ($open).
 */
function missing_for(array $schema, array $values, string $role, ?array $open = null): array
{
    $out = [];
    foreach (visible_fields($schema, $values) as $f) {
        $roles = field_roles($f);
        if (!in_array($role, $roles, true) || !$f['required'] || in_array($f['type'], ['calc', 'system'], true)) continue;
        if (trim((string)($values[$f['key']] ?? '')) !== '') continue;
        if (count($roles) > 1 && array_intersect(array_diff($roles, [$role]), $open ?? ['owner'])) continue;
        $out[] = $f['label'];
    }
    return $out;
}

/** תור מי עכשיו (ברצף): המשתתף הראשון שלא סיים. null = כולם סיימו. */
function current_turn(array $participants): ?int
{
    foreach ($participants as $p) if (!$p['completed_at']) return (int)$p['id'];
    return null;
}

function fill_url(string $token): string
{
    return app_base_url() . 'fill.html#t=' . $token;
}

/** 05X-XXXXXXX → 9725XXXXXXXX, לקישור וואטסאפ. */
function wa_phone(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone);
    if (str_starts_with($d, '0')) $d = '972' . substr($d, 1);
    return $d;
}

// ── מנהל הטופס ───────────────────────────────────────────────

/**
 * נקרא מ-doc_create: שורה לכל תפקיד שאינו המנפיק, בסדר של התבנית. ממלא שבתבנית יש לו
 * איש קשר קבוע — מקושר אליו מיד (אם הכרטיס של מי שמנפיק, ולא בארכיון).
 */
function create_participants(int $docId, array $schema, ?array $user = null): void
{
    $ins = db()->prepare('INSERT INTO participants (document_id, role_key, auth, status, sort_order, contact_id, contact_enc) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $i = 0;
    foreach ($schema['roles'] ?? [] as $r) {
        if ($r['key'] === 'owner') continue;
        $c = null;
        if (!empty($r['contact']) && $user) {
            try { $c = contact_view(contact_row($user, (int)$r['contact'])); } catch (ApiError $e) { $c = null; }
            if ($c && $c['archived']) $c = null;
        }
        $ins->execute([$docId, $r['key'], $r['auth'] === 'email' ? 'email' : 'none', 'waiting', ++$i, $c ? $c['id'] : null, $c ? contact_snap($c) : null]);
    }
}

/** לתצוגה אצל מנהל הטופס. */
function participants_view(array $d, array $schema): array
{
    $values = doc_values((int)$d['id']);
    $list = participants_of((int)$d['id']);
    $turn = $schema['sequential'] ? current_turn($list) : null;
    $open = open_roles((int)$d['id']);
    $ownerMissing = missing_for($schema, $values, 'owner', $open);
    return array_map(function ($p) use ($schema, $values, $turn, $ownerMissing, $open) {
        $c = contact_of($p);
        $role = role_of($schema, $p['role_key']);
        $blocked = null;
        if ($p['completed_at']) $blocked = null;
        elseif ($schema['sequential'] && $ownerMissing) $blocked = 'קודם צריך למלא את השדות שלך';
        elseif ($schema['sequential'] && $turn !== (int)$p['id']) $blocked = 'ממתין לתורו';
        return [
            'id' => (int)$p['id'], 'role_key' => $p['role_key'], 'role' => $role['label'], 'auth' => $p['auth'],
            'name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone'], 'contact_id' => $p['contact_id'] ? (int)$p['contact_id'] : null,
            'status' => $p['status'], 'notified_at' => $p['notified_at'], 'opened_at' => $p['opened_at'],
            'completed_at' => $p['completed_at'], 'has_link' => $p['token_hash'] !== null, 'link_via' => $p['link_via'] ?? null,
            'missing' => missing_for($schema, $values, $p['role_key'], $open), 'blocked' => $blocked,
        ];
    }, $list);
}

function part_owned(array $user, array $b): array
{
    $d = doc_owned((int)($b['id'] ?? 0), $user, 'edit');
    $st = db()->prepare('SELECT * FROM participants WHERE id = ? AND document_id = ?');
    $st->execute([(int)($b['participant_id'] ?? 0), $d['id']]);
    $p = $st->fetch();
    if (!$p) throw new ApiError('המשתתף לא נמצא', 404);
    return [$d, $p];
}

/**
 * מנפיק קישור (ומבטל את הקודם). via: email — שולח מכאן; link — רק מחזיר
 * את הקישור (להעתקה או לוואטסאפ).
 */
function part_link(array $user, array $b): array
{
    [$d, $p] = part_owned($user, $b);
    if (!in_array($d['status'], ['draft', 'sent', 'in_progress'], true)) throw new ApiError('המסמך נעול', 409);
    if ($p['completed_at']) throw new ApiError('המשתתף כבר סיים', 409);
    $schema = doc_schema($d);
    $view = array_values(array_filter(participants_view($d, $schema), fn($x) => $x['id'] === (int)$p['id']))[0];
    if ($view['blocked']) throw new ApiError($view['blocked'], 409);
    // ממלא בקישור הוא תמיד איש קשר — כך אפשר לעקוב ולעדכן אותו מהכרטיס
    if (!$p['contact_id']) throw new ApiError('קודם בוחרים לממלא איש קשר — או כותבים את פרטיו, והוא יישמר באנשי הקשר', 422);

    $via = in_array($b['via'] ?? '', ['email', 'whatsapp'], true) ? $b['via'] : 'link';
    $c = contact_of($p);
    if ($via === 'email' && $c['email'] === '') throw new ApiError('אין כתובת מייל למשתתף', 422);
    // קוד אימות למייל — לא בוואטסאפ. בשליחה אחרת צריך מייל לקוד
    if ($p['auth'] === 'email' && $via !== 'whatsapp' && $c['email'] === '' && !$p['verified_at']) {
        throw new ApiError('לממלא הזה נדרש קוד אימות במייל, ואין לו מייל. שלחו בוואטסאפ (בלי קוד), או הוסיפו מייל', 422);
    }

    $renew = !empty($b['renew']);
    $token = part_token($p, $renew);
    $url = fill_url($token);
    $sent = null;
    if ($via === 'email') {
        // מסמך מ-API עם שם שולח — המייל בשם המערכת החיצונית
        $from = $d['sender_enc'] ? (string)unseal($d['sender_enc']) : ($user['display_name'] ?? '');
        $sent = mail_fill($c['email'], $c['name'], unseal($d['title_enc']), $from, $url);
        if (!$sent) throw new ApiError('שליחת המייל נכשלה. אפשר להעתיק את הקישור ולשלוח בעצמך', 502);
    }
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE participants SET notified_at = ?, link_via = ?, status = CASE status WHEN 'waiting' THEN 'sent' ELSE status END WHERE id = ?")
        ->execute([now_iso(), $via, $p['id']]);
    if ($d['status'] === 'draft') $pdo->prepare("UPDATE documents SET status = 'sent' WHERE id = ?")->execute([$d['id']]);
    log_event((int)$d['id'], 'user:' . $user['id'], 'link_issued', ['role' => $view['role'], 'via' => $via, 'renew' => $renew]);
    $pdo->commit();
    $text = ($c['name'] ? $c['name'] . ', ' : '') . 'מצורף מסמך למילוי ולחתימה: ' . unseal($d['title_enc']) . "\n" . $url;
    return ['ok' => true, 'url' => $url, 'emailed' => (bool)$sent, 'renewed' => $renew,
        'whatsapp' => 'https://wa.me/' . ($c['phone'] ? wa_phone($c['phone']) : '') . '?text=' . rawurlencode($text)];
}

/** ביטול הקישור של ממלא — בלי לשלוח חדש. הקישור שאצלו מפסיק לעבוד. */
function part_revoke(array $user, array $b): array
{
    [$d, $p] = part_owned($user, $b);
    if ($p['completed_at']) throw new ApiError('המשתתף כבר סיים', 409);
    if (!$p['token_hash']) throw new ApiError('אין לו קישור פעיל', 409);
    db()->prepare("UPDATE participants SET token_hash = NULL, token_enc = NULL, link_via = NULL, verified_at = NULL,
        status = CASE WHEN status IN ('sent','opened') THEN 'waiting' ELSE status END WHERE id = ?")->execute([$p['id']]);
    log_event((int)$d['id'], 'user:' . $user['id'], 'link_revoked', ['role' => role_of(doc_schema($d), $p['role_key'])['label']]);
    return ['ok' => true];
}

function mail_fill(string $to, string $name, string $title, string $from, string $url): bool
{
    return send_mail($to, 'מסמך למילוי ולחתימה: ' . $title,
        "שלום" . ($name ? " $name" : '') . ",\n\n" . ($from ? "$from שלח/ה אליך" : 'נשלח אליך') .
        " מסמך למילוי ולחתימה:\n$title\n\nלפתיחה:\n$url\n\nהקישור אישי — אין להעביר אותו לאחרים.");
}

// ── הממלא (בלי חשבון) ────────────────────────────────────────

/** המשתתף והמסמך לפי האסימון. אסימון שבוטל או לא קיים — 404. */
function fill_context(?string $token = null): array
{
    $token ??= (string)($_SERVER['HTTP_X_FILL_TOKEN'] ?? '');
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) throw new ApiError('הקישור אינו תקין', 404);
    $st = db()->prepare(
        'SELECT p.*, d.id AS doc_id FROM participants p JOIN documents d ON d.id = p.document_id
         WHERE p.token_hash = ? AND d.deleted_at IS NULL AND d.archived_at IS NULL'
    );
    $st->execute([token_hash($token)]);
    $p = $st->fetch();
    if (!$p) throw new ApiError('הקישור אינו בתוקף. בקשו קישור חדש ממי ששלח אותו', 404);

    $st = db()->prepare(
        'SELECT d.*, v.schema_enc, v.template_id, v.version AS template_version, t.name_enc AS template_name_enc
         FROM documents d JOIN template_versions v ON v.id = d.template_version_id JOIN templates t ON t.id = v.template_id
         WHERE d.id = ?'
    );
    $st->execute([$p['doc_id']]);
    $d = $st->fetch();
    return [$p, $d, doc_schema($d)];
}

/** קוד למייל — רק כשנדרש, ולא בקישור שנשלח בוואטסאפ (עד שיהיה אימות בוואטסאפ). */
function needs_otp(array $p): bool
{
    return $p['auth'] === 'email' && !$p['verified_at'] && ($p['link_via'] ?? '') !== 'whatsapp';
}

/**
 * הקישור של הממלא: אותו קישור כל עוד לא ביקשו חדש — שליחה חוזרת (וואטסאפ, העתקה, מייל, אישור,
 * החזרה לתיקון) לא מבטלת את מה שכבר אצלו. $renew — קישור חדש, והקודם מפסיק לעבוד.
 */
function part_token(array $p, bool $renew = false): string
{
    if (!$renew && !empty($p['token_enc']) && $p['token_hash']) {
        $t = (string)unseal($p['token_enc']);
        if (preg_match('/^[0-9a-f]{48}$/', $t) && hash_equals((string)$p['token_hash'], token_hash($t))) return $t;
    }
    $t = bin2hex(random_bytes(24));
    db()->prepare('UPDATE participants SET token_hash = ?, token_enc = ? WHERE id = ?')->execute([token_hash($t), seal($t), $p['id']]);
    return $t;
}

function mask_email(string $e): string
{
    [$u, $h] = array_pad(explode('@', $e, 2), 2, '');
    return mb_substr($u, 0, 2) . str_repeat('•', max(1, mb_strlen($u) - 2)) . '@' . $h;
}

/** האם תורו (ברצף). מחזיר הודעה אם לא, או null. */
function turn_block(array $p, array $d, array $schema): ?string
{
    if (!$schema['sequential'] || $p['completed_at']) return null;
    if (missing_for($schema, doc_values((int)$d['id']), 'owner', open_roles((int)$d['id']))) return 'המסמך עוד לא מוכן למילוי שלך';
    return current_turn(participants_of((int)$d['id'])) === (int)$p['id'] ? null : 'עוד לא הגיע תורך. תקבלו הודעה כשיגיע';
}

function fill_get(): array
{
    [$p, $d, $schema] = fill_context();
    $c = contact_of($p);
    if (needs_otp($p)) {
        return ['ok' => true, 'need_otp' => true, 'email' => mask_email($c['email']), 'title' => unseal($d['title_enc'])];
    }
    if (!$p['opened_at']) {
        db()->prepare("UPDATE participants SET opened_at = ?, status = CASE status WHEN 'completed' THEN status ELSE 'opened' END WHERE id = ?")
            ->execute([now_iso(), $p['id']]);
        log_event((int)$d['id'], 'participant:' . $p['id'], 'opened', ['role' => role_of($schema, $p['role_key'])['label']]);
    }
    $a = tpl_asset_row((int)$d['template_version_id']);
    $values = doc_values((int)$d['id']);
    // שדה "מוסתר מהממלאים" — הממלא לא מקבל לא את השדה ולא את הערך (אלא אם הוא שלו)
    $schema['fields'] = array_values(array_filter($schema['fields'] ?? [],
        fn($f) => empty($f['hidden']) || in_array($p['role_key'], field_roles($f), true)));
    $values = array_intersect_key($values, array_flip(array_column($schema['fields'], 'key')));
    $schema = attach_options($schema, array_column($schema['fields'], 'key'));   // בחירה ממקור — רק מה שהממלא רשאי לראות
    $locked = !in_array($d['status'], ['sent', 'in_progress'], true) || $p['completed_at'] !== null;
    $final = in_array($d['status'], ['completed', 'locked'], true);
    return ['ok' => true, 'document' => [
        'id' => (int)$d['id'], 'number' => $d['number'], 'title' => unseal($d['title_enc']), 'status' => $d['status'],
        'mime' => $a['mime'], 'schema' => $schema, 'values' => $values, 'fonts' => fonts_info($schema), 'lists' => lists_for($schema),
        'attachments' => attachments_of((int)$d['id'], 'participant:' . $p['id']),
        'progress' => ['required' => 0, 'filled' => 0, 'missing' => missing_for($schema, $values, $p['role_key'], open_roles((int)$d['id']))],
        'me' => [
            'role_key' => $p['role_key'], 'role' => role_of($schema, $p['role_key'])['label'], 'name' => $c['name'],
            'completed' => $p['completed_at'] !== null, 'locked' => $locked, 'blocked' => turn_block($p, $d, $schema),
            // אחרי שסיים: ממתין לאישור / אושר — ואז (אם התבנית מאפשרת) הורדה
            'awaiting' => !$final && $schema['approval'] && doc_awaiting($d, $schema),
            'approved' => $final, 'can_download' => $final && $schema['fill_download'],
            'returned' => $p['completed_at'] === null ? doc_return_note((int)$d['id'], (int)$p['id']) : null,
        ],
    ]];
}

function fill_otp_send(): array
{
    [$p] = fill_context();
    if ($p['auth'] !== 'email') throw new ApiError('לא נדרש קוד', 409);
    if ($p['otp_expires_at'] && strtotime($p['otp_expires_at']) - OTP_MINUTES * 60 + OTP_COOLDOWN > time()) {
        throw new ApiError('קוד נשלח לפני רגע. אפשר לבקש שוב בעוד דקה', 429);
    }
    // תקרה ליום: כל שליחה מאפסת את מונה הניסיונות, ובלי תקרה אפשר היה לנחש בלי סוף
    $st = db()->prepare("SELECT COUNT(*) FROM events WHERE document_id = ? AND actor = ? AND type = 'otp_sent' AND at > ?");
    $st->execute([$p['doc_id'], 'participant:' . $p['id'], gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]);
    if ((int)$st->fetchColumn() >= OTP_MAX_SENDS) throw new ApiError('נשלחו יותר מדי קודים היום. נסו שוב מחר, או בקשו קישור חדש ממי ששלח', 429);
    $code = (string)random_int(100000, 999999);
    log_event((int)$p['doc_id'], 'participant:' . $p['id'], 'otp_sent');
    db()->prepare('UPDATE participants SET otp_hash = ?, otp_expires_at = ?, otp_attempts = 0 WHERE id = ?')
        ->execute([password_hash($code, PASSWORD_DEFAULT), iso_in(OTP_MINUTES * 60), $p['id']]);
    $c = contact_of($p);
    if (!send_mail($c['email'], 'קוד אימות: ' . $code, "קוד האימות שלך: $code\n\nבתוקף " . OTP_MINUTES . " דקות.")) {
        throw new ApiError('שליחת הקוד נכשלה', 502);
    }
    return ['ok' => true];
}

function fill_otp_verify(array $b): array
{
    [$p, $d] = fill_context();
    if (!$p['otp_hash'] || !$p['otp_expires_at'] || strtotime($p['otp_expires_at']) < time()) throw new ApiError('הקוד פג. בקשו קוד חדש', 410);
    if ((int)$p['otp_attempts'] >= OTP_MAX_TRY) throw new ApiError('יותר מדי ניסיונות. בקשו קוד חדש', 429);
    if (!password_verify(preg_replace('/\D/', '', (string)($b['code'] ?? '')), $p['otp_hash'])) {
        db()->prepare('UPDATE participants SET otp_attempts = otp_attempts + 1 WHERE id = ?')->execute([$p['id']]);
        throw new ApiError('קוד שגוי', 401);
    }
    db()->prepare('UPDATE participants SET verified_at = ?, otp_hash = NULL WHERE id = ?')->execute([now_iso(), $p['id']]);
    log_event((int)$d['id'], 'participant:' . $p['id'], 'verified', ['via' => 'email']);
    return ['ok' => true];
}

function fill_guard(): array
{
    [$p, $d, $schema] = fill_context();
    if (needs_otp($p)) throw new ApiError('נדרש אימות', 401);
    if ($p['completed_at']) throw new ApiError('כבר סיימת למלא את המסמך הזה', 409);
    if (!in_array($d['status'], ['sent', 'in_progress'], true)) throw new ApiError('המסמך נעול', 409);
    if ($msg = turn_block($p, $d, $schema)) throw new ApiError($msg, 409);
    return [$p, $d, $schema];
}

function fill_save(array $b): array
{
    [$p, $d, $schema] = fill_guard();
    $mine = array_column(array_filter($schema['fields'], fn($f) => in_array($p['role_key'], field_roles($f), true)), null, 'key');
    $old = doc_values((int)$d['id']);
    $actor = 'participant:' . $p['id'];
    $now = now_iso();
    $changes = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare(
            'INSERT INTO field_values (document_id, field_key, row_index, value_enc, updated_by, updated_at) VALUES (?, ?, 0, ?, ?, ?)
             ON CONFLICT(document_id, field_key, row_index) DO UPDATE SET
               value_enc = excluded.value_enc, updated_by = excluded.updated_by, updated_at = excluded.updated_at'
        );
        foreach ((array)($b['values'] ?? []) as $key => $v) {
            $f = $mine[$key] ?? null;
            if (!$f || in_array($f['type'], ['calc', 'system'], true)) continue;   // של מישהו אחר, או מתמלא לבד — לא נוגעים, בשקט
            $v = clean_value($f, $v, (int)$d['id']);
            $prev = $old[$key] ?? '';
            if ($v === $prev) continue;
            $up->execute([$d['id'], $key, seal($v), $actor, $now]);
            $changes[] = $f['type'] === 'signature'
                ? ['key' => $key, 'label' => $f['label'], 'signed' => $v !== '']
                : ['key' => $key, 'label' => $f['label'], 'from' => $prev, 'to' => $v];
        }
        if ($changes) doc_recalc((int)$d['id'], $schema, $actor);
        if ($changes) {
            $pdo->prepare("UPDATE documents SET status = 'in_progress' WHERE id = ? AND status = 'sent'")->execute([$d['id']]);
            log_event((int)$d['id'], $actor, 'values_saved', ['changes' => $changes]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $values = doc_values((int)$d['id']);
    // מחושב שמוסתר מהממלאים — לא חוזר אליו (כמו ב-fill_get)
    $seen = array_filter($schema['fields'] ?? [], fn($f) => empty($f['hidden']) || in_array($p['role_key'], field_roles($f), true));
    purge_attachments((int)$d['id']);
    return ['ok' => true, 'saved' => count($changes), 'saved_at' => $now, 'computed' => computed_values(['fields' => array_values($seen)], $values),
        'progress' => ['required' => 0, 'filled' => 0, 'missing' => missing_for($schema, $values, $p['role_key'], open_roles((int)$d['id']))]];
}

/**
 * הממלא סיים. ברצף — הבא בתור מקבל קישור במייל (אם יש לו מייל). כשכולם
 * סיימו וכל שדות החובה מלאים — המסמך ננעל, ומנהל הטופס מקבל מייל.
 */
function fill_complete(): array
{
    [$p, $d, $schema] = fill_guard();
    $values = doc_values((int)$d['id']);
    if ($m = missing_for($schema, $values, $p['role_key'], open_roles((int)$d['id']))) throw new ApiError('חסרים שדות חובה: ' . implode(', ', $m), 422);

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE participants SET completed_at = ?, status = 'completed' WHERE id = ?")->execute([now_iso(), $p['id']]);
    log_event((int)$d['id'], 'participant:' . $p['id'], 'participant_completed', ['role' => role_of($schema, $p['role_key'])['label']]);
    $pdo->commit();
    webhook_fire((int)$d['id'], 'participant.completed', ['role' => $p['role_key']]);

    // מי מקבל "ממתין לאישורך": בעל התבנית, כשהתבנית מחייבת אישור רק דרכו — אחרת מי שהנפיק
    $approver = doc_approver($d);
    $owner = db()->prepare('SELECT email, display_name FROM users WHERE id = ?');
    $owner->execute([$approver ?: $d['owner_id']]);
    $owner = $owner->fetch() ?: ['email' => '', 'display_name' => ''];
    $title = (string)unseal($d['title_enc']);
    $list = participants_of((int)$d['id']);
    $done = current_turn($list) === null && !doc_progress($schema, $values)['missing'];

    if ($done && ($schema['approval'] || $approver)) {
        // מסלול אישור: לא ננעל לבד — ממתין לאישור של מנהל הטופס
        log_event((int)$d['id'], 'participant:' . $p['id'], 'awaiting_approval');
        if ($owner['email']) send_mail($owner['email'], 'ממתין לאישורך: ' . $title,
            "כל הממלאים סיימו, והמסמך ממתין לאישורך:\n$title" . ($d['number'] ? " ({$d['number']})" : '') .
            "\n\nלבדיקה ולאישור (או להחזרה לתיקון):\n" . app_base_url() . '#doc=' . $d['id']);
        $done = false;
    } elseif ($done) {
        doc_finalize($d, 'participant:' . $p['id']);
        if ($owner['email']) send_mail($owner['email'], 'המסמך הושלם: ' . $title,
            "כל המשתתפים מילאו וחתמו על המסמך:\n$title" . ($d['number'] ? " ({$d['number']})" : '') . "\n\n" . app_base_url() . '#doc=' . $d['id']);
        if ($schema['fill_download']) notify_ready($d, $schema);
    } elseif ($schema['sequential']) {
        $next = null;
        foreach ($list as $x) if (!$x['completed_at']) { $next = $x; break; }
        $c = $next ? contact_of($next) : null;
        if ($next && $c['email']) {
            $token = part_token($next);
            db()->prepare("UPDATE participants SET notified_at = ?, link_via = 'email', status = CASE status WHEN 'waiting' THEN 'sent' ELSE status END WHERE id = ?")
                ->execute([now_iso(), $next['id']]);
            log_event((int)$d['id'], 'participant:' . $p['id'], 'link_issued', ['role' => role_of($schema, $next['role_key'])['label'], 'via' => 'email', 'auto' => true]);
            mail_fill($c['email'], $c['name'], $title, ($d['sender_enc'] ?? null) ? (string)unseal($d['sender_enc']) : $owner['display_name'], fill_url($token));
        } elseif ($owner['email']) {
            send_mail($owner['email'], 'משתתף סיים: ' . $title, role_of($schema, $p['role_key'])['label'] .
                " סיים/ה למלא. הבא בתור — אין לו מייל, שלחו לו קישור בעצמכם:\n" . app_base_url() . '#doc=' . $d['id']);
        }
    }
    return ['ok' => true, 'document_completed' => $done];
}

/** כל הממלאים סיימו וכל שדות החובה מלאים — במסלול אישור: ממתין לאישור של מנהל הטופס. */
function doc_awaiting(array $d, array $schema): bool
{
    if (!in_array($d['status'], DOC_EDITABLE, true)) return false;
    // נשלח לאישור של בעל התבנית, ולא הוחזר מאז
    if (doc_approver($d)) {
        $st = db()->prepare("SELECT type FROM events WHERE document_id = ? AND type IN ('submitted', 'returned') ORDER BY id DESC LIMIT 1");
        $st->execute([$d['id']]);
        if ($st->fetchColumn() === 'submitted') return true;
    }
    $list = participants_of((int)$d['id']);
    return $list && current_turn($list) === null && !doc_progress($schema, doc_values((int)$d['id']))['missing'];
}

/** ההערה האחרונה שהוחזרה לממלא הזה לתיקון (אם הוחזר ועוד לא סיים שוב). */
function doc_return_note(int $docId, int $partId): ?string
{
    $st = db()->prepare("SELECT detail_enc FROM events WHERE document_id = ? AND type = 'returned' ORDER BY id DESC LIMIT 1");
    $st->execute([$docId]);
    $e = $st->fetchColumn();
    $x = $e ? json_decode((string)unseal($e), true) : null;
    return $x && in_array($partId, $x['participants'] ?? [], true) ? (string)($x['note'] ?? '') : null;
}

/** המסמך הושלם (או אושר): מייל לכל ממלא עם מייל — להורדת העותק שלו, באותו קישור שכבר אצלו. */
function notify_ready(array $d, array $schema): void
{
    $title = (string)unseal($d['title_enc']);
    foreach (participants_of((int)$d['id']) as $p) {
        $c = contact_of($p);
        if (!$c['email'] || !$p['completed_at']) continue;
        $token = part_token($p);   // אותו קישור שכבר אצלו — גם אם קיבל אותו בוואטסאפ
        db()->prepare('UPDATE participants SET notified_at = ? WHERE id = ?')->execute([now_iso(), $p['id']]);
        send_mail($c['email'], ($schema['approval'] ? 'המסמך אושר: ' : 'המסמך הושלם: ') . $title,
            "שלום {$c['name']},\n\nהמסמך \"$title\"" . ($d['number'] ? " ({$d['number']})" : '') .
            ($schema['approval'] ? ' אושר.' : ' הושלם.') . "\nלצפייה ולהורדה:\n" . fill_url($token));
    }
}

/**
 * מסלול אישור — החזרה לתיקון: הממלא (או כולם) פותח שוב את השדות שלו, עם הערה. מקבל קישור חדש במייל.
 * $b: id, participant (מזהה, או 0 — כל מי שסיים), note.
 */
function doc_return(array $user, array $b): array
{
    $d = doc_owned((int)($b['id'] ?? 0), $user, 'edit');
    if (!in_array($d['status'], DOC_EDITABLE, true)) throw new ApiError('המסמך כבר נעול', 409);
    $note = mb_substr(trim((string)($b['note'] ?? '')), 0, 1000);
    $pid = (int)($b['participant'] ?? 0);
    $list = array_values(array_filter(participants_of((int)$d['id']), fn($p) => $p['completed_at'] && (!$pid || (int)$p['id'] === $pid)));
    $approver = doc_approver($d);
    if (!$list && $approver && (int)$user['id'] === $approver) {
        // אישור רק דרך בעל התבנית: מחזיר למי שהנפיק (בלי ממלאים בקישור)
        $st = db()->prepare('SELECT email, display_name FROM users WHERE id = ?');
        $st->execute([$d['owner_id']]);
        $title = (string)unseal($d['title_enc']);
        $mailed = false;
        if ($o = $st->fetch()) $mailed = (bool)send_mail($o['email'], 'הוחזר לתיקון: ' . $title, "שלום {$o['display_name']},\n\n" . user_name($approver) .
            " החזיר/ה אליך את המסמך \"$title\" לתיקון." . ($note !== '' ? "\n\nההערה:\n$note" : '') . "\n\n" . app_base_url() . '#doc=' . $d['id']);
        log_event((int)$d['id'], 'user:' . $user['id'], 'returned', ['note' => $note, 'participants' => [], 'roles' => [user_name((int)$d['owner_id'])]]);
        return ['ok' => true, 'returned' => user_name((int)$d['owner_id']), 'mailed' => $mailed];
    }
    if (!$list) throw new ApiError('אין ממלא שסיים להחזיר אליו', 409);
    $schema = doc_schema($d);
    $title = (string)unseal($d['title_enc']);
    $owner = user_name((int)$d['owner_id']);
    $sent = 0;
    foreach ($list as $p) {
        db()->prepare("UPDATE participants SET completed_at = NULL, status = 'opened' WHERE id = ?")->execute([$p['id']]);
        $c = contact_of($p);
        if (!$c['email']) continue;
        $token = part_token($p);
        db()->prepare('UPDATE participants SET notified_at = ? WHERE id = ?')->execute([now_iso(), $p['id']]);
        $sent += (int)send_mail($c['email'], 'הוחזר לתיקון: ' . $title, "שלום {$c['name']},\n\n$owner החזיר/ה אליך את המסמך \"$title\" לתיקון." .
            ($note !== '' ? "\n\nההערה:\n$note" : '') . "\n\nלמילוי:\n" . fill_url($token));
    }
    log_event((int)$d['id'], 'user:' . $user['id'], 'returned', ['note' => $note, 'participants' => array_map(fn($p) => (int)$p['id'], $list),
        'roles' => array_map(fn($p) => role_of($schema, $p['role_key'])['label'], $list)]);
    return ['ok' => true, 'returned' => count($list), 'mailed' => $sent];
}

/** הממלא מוריד את העותק שלו: רק אחרי שהמסמך הושלם (אושר), ורק אם התבנית מאפשרת. בלי שדות שמוסתרים ממנו. */
function fill_can_download(): array
{
    [$p, $d, $schema] = fill_context();
    if (needs_otp($p)) throw new ApiError('נדרש אימות', 401);
    if (!in_array($d['status'], ['completed', 'locked'], true) || !$schema['fill_download']) throw new ApiError('המסמך עוד לא זמין להורדה', 403);
    return [$p, $d, $schema];
}

/** ממלא: "הורדתי" — ביומן של מנהל הטופס. */
function fill_downloaded(array $b): array
{
    [$p, $d] = fill_can_download();
    log_event((int)$d['id'], 'participant:' . $p['id'], 'downloaded', ['format' => in_array($b['format'] ?? '', ['pdf', 'docx'], true) ? $b['format'] : 'pdf']);
    return ['ok' => true];
}

/** ממלא: מסמך Word ממולא — בלי דף האישור (יש בו את היומן וכתובות IP) ובלי שדות שמוסתרים ממנו. */
function fill_docx(): never
{
    [$p, $d, $schema] = fill_can_download();
    $a = tpl_asset_row((int)$d['template_version_id']);
    if ($a['mime'] !== DOCX_MIME && $a['mime'] !== AUTHORED_MIME) throw new ApiError('המסמך לא נוצר מתבנית Word', 409);
    $visible = array_column(array_filter($schema['fields'] ?? [],
        fn($f) => empty($f['hidden']) || in_array($p['role_key'], field_roles($f), true)), 'key');
    $values = array_intersect_key(doc_values((int)$d['id']), array_flip($visible));
    // רק השמות של הקבצים שהוא העלה — של אחרים: "קובץ" (כמו ב-PDF שלו)
    $names = array_column(attachments_of((int)$d['id'], 'participant:' . $p['id']), 'name', 'id');
    $src = $a['mime'] === AUTHORED_MIME ? authored_docx($schema, $values) : asset_bytes($a);
    $bytes = docx_fill($src, $schema, $values, $names, []);
    log_event((int)$d['id'], 'participant:' . $p['id'], 'downloaded', ['format' => 'docx']);
    $name = preg_replace('/[\\\/:*?"<>|]+/u', '_', trim(($d['number'] ? $d['number'] . ' ' : '') . unseal($d['title_enc'])) ?: 'מסמך') . '.docx';
    header('Content-Type: ' . DOCX_MIME);
    header('Content-Length: ' . strlen($bytes));
    header("Content-Disposition: attachment; filename=\"document.docx\"; filename*=UTF-8''" . rawurlencode($name));
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
}

function fill_asset(): never
{
    [$p, $d] = fill_context();
    if (needs_otp($p)) throw new ApiError('נדרש אימות', 401);
    $a = tpl_asset_row((int)$d['template_version_id']);
    // Word — הממלא רואה טופס, לא את קובץ המקור (שבו גם שמות של שדות נסתרים)
    if ($a['mime'] === DOCX_MIME) throw new ApiError('אין קובץ רקע למסמך הזה', 404);
    $bytes = asset_bytes($a);
    header('Content-Type: ' . $a['mime']);
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
}

require_once __DIR__ . '/attachments.php';
