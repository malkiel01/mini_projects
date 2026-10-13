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

// ── עזרים ────────────────────────────────────────────────────

function doc_schema(array $d): array
{
    $s = json_decode((string)unseal($d['schema_enc']), true) ?: [];
    $s['roles'] ??= [['key' => 'owner', 'label' => 'אני (המנפיק)', 'auth' => 'none']];
    $s['sequential'] ??= false;
    return $s;
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

/** שדות החובה של תפקיד אחד שעדיין ריקים. */
function missing_for(array $schema, array $values, string $role): array
{
    $out = [];
    foreach ($schema['fields'] ?? [] as $f) {
        if (($f['role'] ?? 'owner') === $role && $f['required'] && trim((string)($values[$f['key']] ?? '')) === '') $out[] = $f['label'];
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

/** נקרא מ-doc_create: שורה לכל תפקיד שאינו המנפיק, בסדר של התבנית. */
function create_participants(int $docId, array $schema): void
{
    $ins = db()->prepare('INSERT INTO participants (document_id, role_key, auth, status, sort_order) VALUES (?, ?, ?, ?, ?)');
    $i = 0;
    foreach ($schema['roles'] ?? [] as $r) {
        if ($r['key'] === 'owner') continue;
        $ins->execute([$docId, $r['key'], $r['auth'] === 'email' ? 'email' : 'none', 'waiting', ++$i]);
    }
}

/** לתצוגה אצל מנהל הטופס. */
function participants_view(array $d, array $schema): array
{
    $values = doc_values((int)$d['id']);
    $list = participants_of((int)$d['id']);
    $turn = $schema['sequential'] ? current_turn($list) : null;
    $ownerMissing = missing_for($schema, $values, 'owner');
    return array_map(function ($p) use ($schema, $values, $turn, $ownerMissing) {
        $c = contact_of($p);
        $role = role_of($schema, $p['role_key']);
        $blocked = null;
        if ($p['completed_at']) $blocked = null;
        elseif ($schema['sequential'] && $ownerMissing) $blocked = 'קודם צריך למלא את השדות שלך';
        elseif ($schema['sequential'] && $turn !== (int)$p['id']) $blocked = 'ממתין לתורו';
        return [
            'id' => (int)$p['id'], 'role_key' => $p['role_key'], 'role' => $role['label'], 'auth' => $p['auth'],
            'name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone'],
            'status' => $p['status'], 'notified_at' => $p['notified_at'], 'opened_at' => $p['opened_at'],
            'completed_at' => $p['completed_at'], 'has_link' => $p['token_hash'] !== null,
            'missing' => missing_for($schema, $values, $p['role_key']), 'blocked' => $blocked,
        ];
    }, $list);
}

function part_owned(array $user, array $b): array
{
    $d = doc_owned((int)($b['id'] ?? 0), $user);
    $st = db()->prepare('SELECT * FROM participants WHERE id = ? AND document_id = ?');
    $st->execute([(int)($b['participant_id'] ?? 0), $d['id']]);
    $p = $st->fetch();
    if (!$p) throw new ApiError('המשתתף לא נמצא', 404);
    return [$d, $p];
}

function part_contact(array $user, array $b): array
{
    [$d, $p] = part_owned($user, $b);
    $email = trim((string)($b['email'] ?? ''));
    if ($email !== '') $email = valid_email($email);
    $c = [
        'name' => mb_substr(trim((string)($b['name'] ?? '')), 0, 80),
        'email' => $email,
        'phone' => mb_substr(preg_replace('/[^\d+\- ]/', '', (string)($b['phone'] ?? '')), 0, 20),
    ];
    if ($p['auth'] === 'email' && $c['email'] === '') throw new ApiError('לתפקיד הזה נדרש אימות במייל — חובה כתובת מייל', 422);
    db()->prepare('UPDATE participants SET contact_enc = ? WHERE id = ?')->execute([seal(json_encode($c, JSON_UNESCAPED_UNICODE)), $p['id']]);
    return ['ok' => true];
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

    $via = ($b['via'] ?? 'link') === 'email' ? 'email' : 'link';
    $c = contact_of($p);
    if ($via === 'email' && $c['email'] === '') throw new ApiError('אין כתובת מייל למשתתף', 422);

    $token = bin2hex(random_bytes(24));
    $now = now_iso();
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE participants SET token_hash = ?, notified_at = ?, status = CASE status WHEN 'waiting' THEN 'sent' ELSE status END WHERE id = ?")
        ->execute([token_hash($token), $now, $p['id']]);
    if ($d['status'] === 'draft') $pdo->prepare("UPDATE documents SET status = 'sent' WHERE id = ?")->execute([$d['id']]);
    log_event((int)$d['id'], 'user:' . $user['id'], 'link_issued', ['role' => $view['role'], 'via' => $via]);
    $pdo->commit();

    $url = fill_url($token);
    $sent = null;
    if ($via === 'email') {
        $sent = mail_fill($c['email'], $c['name'], unseal($d['title_enc']), $user['display_name'] ?? '', $url);
        if (!$sent) throw new ApiError('שליחת המייל נכשלה. אפשר להעתיק את הקישור ולשלוח בעצמך', 502);
    }
    $text = ($c['name'] ? $c['name'] . ', ' : '') . 'מצורף מסמך למילוי ולחתימה: ' . unseal($d['title_enc']) . "\n" . $url;
    return ['ok' => true, 'url' => $url, 'emailed' => (bool)$sent,
        'whatsapp' => 'https://wa.me/' . ($c['phone'] ? wa_phone($c['phone']) : '') . '?text=' . rawurlencode($text)];
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
         WHERE p.token_hash = ? AND d.deleted_at IS NULL'
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

function needs_otp(array $p): bool
{
    return $p['auth'] === 'email' && !$p['verified_at'];
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
    if (missing_for($schema, doc_values((int)$d['id']), 'owner')) return 'המסמך עוד לא מוכן למילוי שלך';
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
    $locked = !in_array($d['status'], ['sent', 'in_progress'], true) || $p['completed_at'] !== null;
    return ['ok' => true, 'document' => [
        'id' => (int)$d['id'], 'number' => $d['number'], 'title' => unseal($d['title_enc']), 'status' => $d['status'],
        'mime' => $a['mime'], 'schema' => $schema, 'values' => $values,
        'attachments' => attachments_of((int)$d['id'], 'participant:' . $p['id']),
        'progress' => ['required' => 0, 'filled' => 0, 'missing' => missing_for($schema, $values, $p['role_key'])],
        'me' => [
            'role_key' => $p['role_key'], 'role' => role_of($schema, $p['role_key'])['label'], 'name' => $c['name'],
            'completed' => $p['completed_at'] !== null, 'locked' => $locked, 'blocked' => turn_block($p, $d, $schema),
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
    $code = (string)random_int(100000, 999999);
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
    $mine = array_column(array_filter($schema['fields'], fn($f) => ($f['role'] ?? 'owner') === $p['role_key']), null, 'key');
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
            if (!$f) continue;   // שדה של מישהו אחר — לא נוגעים, בשקט
            $v = clean_value($f, $v, (int)$d['id']);
            $prev = $old[$key] ?? '';
            if ($v === $prev) continue;
            $up->execute([$d['id'], $key, seal($v), $actor, $now]);
            $changes[] = $f['type'] === 'signature'
                ? ['key' => $key, 'label' => $f['label'], 'signed' => $v !== '']
                : ['key' => $key, 'label' => $f['label'], 'from' => $prev, 'to' => $v];
        }
        if ($changes) {
            $pdo->prepare("UPDATE documents SET status = 'in_progress' WHERE id = ? AND status = 'sent'")->execute([$d['id']]);
            log_event((int)$d['id'], $actor, 'values_saved', ['changes' => $changes]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'saved' => count($changes), 'saved_at' => $now,
        'progress' => ['required' => 0, 'filled' => 0, 'missing' => missing_for($schema, doc_values((int)$d['id']), $p['role_key'])]];
}

/**
 * הממלא סיים. ברצף — הבא בתור מקבל קישור במייל (אם יש לו מייל). כשכולם
 * סיימו וכל שדות החובה מלאים — המסמך ננעל, ומנהל הטופס מקבל מייל.
 */
function fill_complete(): array
{
    [$p, $d, $schema] = fill_guard();
    $values = doc_values((int)$d['id']);
    if ($m = missing_for($schema, $values, $p['role_key'])) throw new ApiError('חסרים שדות חובה: ' . implode(', ', $m), 422);

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE participants SET completed_at = ?, status = 'completed' WHERE id = ?")->execute([now_iso(), $p['id']]);
    log_event((int)$d['id'], 'participant:' . $p['id'], 'participant_completed', ['role' => role_of($schema, $p['role_key'])['label']]);
    $pdo->commit();

    $owner = db()->prepare('SELECT email, display_name FROM users WHERE id = ?');
    $owner->execute([$d['owner_id']]);
    $owner = $owner->fetch() ?: ['email' => '', 'display_name' => ''];
    $title = (string)unseal($d['title_enc']);
    $list = participants_of((int)$d['id']);
    $done = current_turn($list) === null && !doc_progress($schema, $values)['missing'];

    if ($done) {
        doc_finalize($d, 'participant:' . $p['id']);
        if ($owner['email']) send_mail($owner['email'], 'המסמך הושלם: ' . $title,
            "כל המשתתפים מילאו וחתמו על המסמך:\n$title" . ($d['number'] ? " ({$d['number']})" : '') . "\n\n" . app_base_url() . '#doc=' . $d['id']);
    } elseif ($schema['sequential']) {
        $next = null;
        foreach ($list as $x) if (!$x['completed_at']) { $next = $x; break; }
        $c = $next ? contact_of($next) : null;
        if ($next && $c['email']) {
            $token = bin2hex(random_bytes(24));
            db()->prepare("UPDATE participants SET token_hash = ?, notified_at = ?, status = CASE status WHEN 'waiting' THEN 'sent' ELSE status END WHERE id = ?")
                ->execute([token_hash($token), now_iso(), $next['id']]);
            log_event((int)$d['id'], 'participant:' . $p['id'], 'link_issued', ['role' => role_of($schema, $next['role_key'])['label'], 'via' => 'email', 'auto' => true]);
            mail_fill($c['email'], $c['name'], $title, $owner['display_name'], fill_url($token));
        } elseif ($owner['email']) {
            send_mail($owner['email'], 'משתתף סיים: ' . $title, role_of($schema, $p['role_key'])['label'] .
                " סיים/ה למלא. הבא בתור — אין לו מייל, שלחו לו קישור בעצמכם:\n" . app_base_url() . '#doc=' . $d['id']);
        }
    }
    return ['ok' => true, 'document_completed' => $done];
}

function fill_asset(): never
{
    [$p, $d] = fill_context();
    if (needs_otp($p)) throw new ApiError('נדרש אימות', 401);
    $a = tpl_asset_row((int)$d['template_version_id']);
    $bytes = load_file($a['path']);
    header('Content-Type: ' . $a['mime']);
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
}

require_once __DIR__ . '/attachments.php';
