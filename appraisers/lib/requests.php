<?php
/**
 * בקשות לגורם חיצוני (אפיון 07): הרכבה, תצוגה מקדימה, שליחה, שליחה מחדש וביטול — הצד של המשרד.
 * הפורטל עצמו (הצד של הנמען): lib/portal.php.
 *
 * - **אין שליחה בלי תצוגה מקדימה** (07-A1): request-preview מחזיר מזהה חתום של התוכן המדויק; request-send ו-request-resend
 *   מחשבים את התוכן מחדש ודורשים מזהה תואם. שינוי של תו אחד — המזהה אינו תקף.
 * - **האסימון של הקישור** — 32 בתים אקראיים; במסד רק sha256 שלו. הוא מופיע פעם אחת: במייל, או בתשובה של "קישור להעתקה".
 * - כל שינוי בטרנזקציה, עם שורה ביומן הפעולות של התיק. מצב דרישה משתנה רק ב-apr_req_move().
 */

declare(strict_types=1);

require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/mail.php';

const APR_REQUEST_PERMS = ['upload' => true, 'reply' => true, 'view_own' => true, 'download_own' => false];
const APR_REQUEST_DAYS = [7, 14, 30, 90];
const APR_REQUEST_PREVIEW_TTL = 1800;
const APR_REQUEST_MAX_ITEMS = 60;
/** דרישה שאפשר לבקש: עוד לא התקבל לה מסמך, או שהוחזרה להשלמה. */
const APR_REQUEST_ITEM_STATES = ['required', 'requested', 'returned'];

// ── אסימונים ──────────────────────────────────────────────────

function apr_portal_token_hash(string $token): string
{
    return hash('sha256', 'portal:' . $token);
}

/** אסימון חדש לבקשה: מבטל את הקישורים הקודמים, ושוכח מכשירים וקודים שלהם. מחזיר את האסימון — הפעם היחידה שהוא קיים בטקסט גלוי. */
function apr_portal_link_new(int $requestId, string $expires): string
{
    apr_request_links_kill($requestId);
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    apr_insert('portal_links', ['request_id' => $requestId, 'token_hash' => apr_portal_token_hash($token), 'created_at' => apr_now(), 'expires_at' => $expires]);
    return $token;
}

/** מבטל כל קישור חי של הבקשה. מחזיר כמה בוטלו. */
function apr_request_links_kill(int $requestId): int
{
    $ids = array_column(apr_rows('SELECT id FROM portal_links WHERE request_id = ?', [$requestId]), 'id');
    if (!$ids) return 0;
    $in = implode(',', array_map('intval', $ids));
    apr_exec("DELETE FROM portal_devices WHERE link_id IN ($in)");
    apr_exec("DELETE FROM portal_otps WHERE link_id IN ($in)");
    return apr_exec("UPDATE portal_links SET revoked_at = ? WHERE id IN ($in) AND revoked_at IS NULL", [apr_now()]);
}

function apr_portal_url(string $token): string
{
    return apr_app_url('portal/') . '#' . $token;
}

// ── התוכן של בקשה ─────────────────────────────────────────────

function apr_request_perms(mixed $in, bool $hasEmail): array
{
    $out = [];
    foreach (APR_REQUEST_PERMS as $k => $def) $out[$k] = is_array($in) && array_key_exists($k, $in) ? $in[$k] === true : $def;
    // צפייה והורדה דורשות קוד חד-פעמי למייל — בלי מייל הן אינן זמינות (§5.1)
    if (!$hasEmail) $out['view_own'] = $out['download_own'] = false;
    return $out;
}

/** הנוסח שהמערכת מציעה. בלי פרטי הנכס, בלי מספר התיק ובלי שמות של גורמים אחרים — רק מה שהשמאי יוסיף בעצמו. */
function apr_request_template(string $office, string $sender, string $recipient): array
{
    return ['בקשה למסמכים — ' . $office,
        "שלום $recipient,\n\nלצורך הכנת חוות דעת שמאית נבקש לקבל את המסמכים שברשימה.\nאפשר להעלות אותם בקישור האישי שבהודעה זו — בלי הרשמה ובלי סיסמה.\n\nתודה,\n$sender\n$office"];
}

/**
 * מאמת ומנרמל את התוכן של בקשה חדשה ($in: party, requirements [], perms {}, days, subject, body, channel, contact_ok),
 * או של שליחה מחדש ($req — הבקשה הקיימת; מ-$in רק channel, contact_ok ו-days). מחזיר את כל מה שנשלח, ואת ה-hash שלו.
 */
function apr_request_content(array $ctx, array $c, array $in, ?array $req = null): array
{
    $party = $req ? (apr_row('SELECT * FROM case_parties WHERE id = ? AND case_id = ? AND removed_at IS NULL', [$req['party_id'], $c['id']]) ?? apr_fail('request_state'))
        : apr_party_row($c, $in['party'] ?? null);
    $contact = apr_row('SELECT * FROM contacts WHERE id = ? AND office_id = ? AND deleted_at IS NULL', [$party['contact_id'], $c['office_id']]) ?? apr_fail('not_found');
    $email = $contact['email'] !== null && $contact['email'] !== '' ? (string)$contact['email'] : null;
    $channel = apr_clean_choice($in['channel'] ?? null, ['email', 'link_copy']) ?? apr_fail('content_invalid');
    if ($channel === 'email') {
        if ($email === null) apr_fail('recipient_no_email');
        if (($in['contact_ok'] ?? false) !== true) apr_fail('contact_unconfirmed');    // "הכתובת נכונה" — המשתמש מאשר לפני שליחה
    }
    $officeRow = apr_row('SELECT name, portal_always_otp FROM offices WHERE id = ?', [$c['office_id']]);
    if ($email === null && (int)$officeRow['portal_always_otp']) apr_fail('recipient_no_email');   // "תמיד לדרוש קוד" — ואין לאן לשלוח אותו
    if ($req) {
        $items = apr_rows('SELECT r.* FROM request_items ri JOIN requirements r ON r.id = ri.requirement_id WHERE ri.request_id = ? ORDER BY r.sort, r.id', [$req['id']]);
        $perms = apr_request_perms(json_decode($req['perms'], true), $email !== null);
        $days = in_array((int)($in['days'] ?? 0), APR_REQUEST_DAYS, true) ? (int)$in['days'] : (int)$req['days'];
        [$subject, $body] = [$req['subject'], $req['body']];
    } else {
        $pids = $in['requirements'] ?? null;
        if (!is_array($pids) || !$pids || count($pids) > APR_REQUEST_MAX_ITEMS || count(array_unique(array_filter($pids, 'is_string'))) !== count($pids)) apr_fail('request_items');
        $items = array_map(fn($pid) => apr_req_row($c, $pid), array_values($pids));
        foreach ($items as $r) {
            if ($r['split_at'] !== null || !in_array($r['status'], APR_REQUEST_ITEM_STATES, true)) apr_fail('req_state');
        }
        usort($items, fn($a, $b) => [$a['sort'], $a['id']] <=> [$b['sort'], $b['id']]);
        $perms = apr_request_perms($in['perms'] ?? null, $email !== null);
        $days = in_array((int)($in['days'] ?? 30), APR_REQUEST_DAYS, true) ? (int)($in['days'] ?? 30) : apr_fail('content_invalid');
        [$dSubject, $dBody] = apr_request_template($officeRow['name'], $ctx['user']['name'], $contact['name']);
        $subject = apr_clean_text($in['subject'] ?? '', 150) ?? $dSubject;
        $subject = (string)preg_replace('/\s+/u', ' ', $subject);
        $body = is_string($in['body'] ?? null) && trim($in['body']) !== '' ? trim(str_replace("\r", '', $in['body'])) : $dBody;
        if (mb_strlen($body) > 4000) apr_fail('text_too_long');
    }
    $out = ['party' => $party, 'contact' => $contact, 'email' => $email, 'channel' => $channel, 'items' => $items, 'perms' => $perms, 'days' => $days,
        'subject' => $subject, 'body' => $body, 'office_name' => $officeRow['name'], 'always_otp' => (bool)$officeRow['portal_always_otp']];
    $out['hash'] = hash('sha256', json_encode([$req['id'] ?? 0, (int)$c['id'], (int)$party['id'], $email, $channel, array_map(fn($r) => (int)$r['id'], $items), $perms, $days, $subject, $body], JSON_UNESCAPED_UNICODE));
    return $out;
}

/** מזהה התצוגה המקדימה: תוקף + חתימה על התוכן, על המשתמש ועל התוקף. אין לו שורה במסד — הוא מוכיח שהתוכן הזה הוצג. */
function apr_request_preview_id(int $uid, string $hash, ?int $exp = null): string
{
    $exp ??= apr_time() + APR_REQUEST_PREVIEW_TTL;
    return $exp . '.' . hash_hmac('sha256', "preview|$uid|$exp|$hash", apr_secret());
}

function apr_request_preview_check(int $uid, string $hash, mixed $id): void
{
    if (!is_string($id) || preg_match('/^(\d{9,11})\.[0-9a-f]{64}\z/', $id, $m) !== 1) apr_fail('preview_required');
    if (!hash_equals(apr_request_preview_id($uid, $hash, (int)$m[1]), $id)) apr_fail('preview_required');
    if ((int)$m[1] < apr_time()) apr_fail('preview_expired');
}

/** בדיוק מה שיישלח: השולח כפי שיופיע, הנמען, הנוסח, הדרישות עם ההסבר לנמען, מה יוכל לעשות ועד מתי. */
function apr_request_preview(array $ctx, array $in): array
{
    $req = null;
    if (($in['request'] ?? null) !== null) {
        $c = apr_case_get($ctx, apr_doc_case_pid('requests', $in['request']), 'contact');
        $req = apr_request_row($c, $in['request']);
        if ($req['status'] === 'revoked') apr_fail('request_state');
    } else {
        $c = apr_case_get($ctx, $in['case'] ?? null, 'contact');
    }
    $k = apr_request_content($ctx, $c, $in, $req);
    return ['preview' => apr_request_preview_id((int)$ctx['user']['id'], $k['hash']), 'valid_for' => APR_REQUEST_PREVIEW_TTL,
        'sender' => ['office' => $k['office_name'], 'name' => $ctx['user']['name'], 'reply_to' => $ctx['user']['email']],
        'recipient' => ['party' => $k['party']['pid'], 'name' => $k['contact']['name'], 'email' => $k['email']],
        'channel' => $k['channel'], 'subject' => $k['subject'], 'body' => $k['body'],
        'requirements' => array_map(fn($r) => ['pid' => $r['pid'], 'title' => $r['title'], 'explain' => $r['explain_recipient'], 'due_at' => $r['due_at'], 'status' => $r['status']], $k['items']),
        'perms' => $k['perms'], 'days' => $k['days'], 'expires_at' => apr_iso(apr_time() + $k['days'] * 86400),
        'otp' => ['available' => $k['email'] !== null, 'always' => $k['always_otp']]];
}

function apr_request_row(array $c, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row('SELECT * FROM requests WHERE pid = ? AND case_id = ?', [$pid, $c['id']]) : null) ?? apr_fail('not_found');
}

/** מזהה פעולה מהדפדפן, עם מזהה המשתמש. חובה בשליחה: לחיצה כפולה אינה שולחת פעמיים (07-A2). */
function apr_request_op(array $ctx, mixed $v): string
{
    if (!is_string($v) || preg_match('/^[A-Za-z0-9_-]{8,64}\z/', $v) !== 1) apr_fail('content_invalid');
    return 'req:' . $ctx['user']['id'] . ':' . $v;
}

/** גוף המייל: הנוסח, קישור אחד, ועד מתי. בלי קבצים, בלי פרטי הנכס ובלי שמות של אחרים. */
function apr_request_mail_text(array $k, string $token, string $expires): string
{
    $date = (new DateTimeImmutable($expires))->setTimezone(new DateTimeZone(APR_CASE_TZ))->format('d.m.Y');
    return $k['body'] . "\n\nלהעלאת המסמכים:\n" . apr_portal_url($token) . "\n\nהקישור אישי, ותקף עד $date. אין להעביר אותו לאחרים.";
}

/**
 * יוצר קישור ושולח הודעה — בתוך טרנזקציה. מחזיר ['message' => מצב, 'link' => כתובת (רק ב"קישור להעתקה")].
 * הודעה שנכשלה אינה מקדמת שום דרישה (§6): רק אחרי sent / unknown הדרישות שהיו required עוברות ל-requested.
 */
function apr_request_dispatch(array $ctx, array $c, array $req, array $k, string $op, string $expires): array
{
    $uid = (int)$ctx['user']['id'];
    $token = apr_portal_link_new((int)$req['id'], $expires);
    $msgPid = apr_pid();
    $msgId = apr_insert('messages', ['pid' => $msgPid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'request_id' => $req['id'], 'party_id' => $k['party']['id'], 'channel' => $k['channel'],
        'to_hash' => $k['email'] !== null && $k['channel'] === 'email' ? apr_mail_hash($k['email']) : null, 'status' => 'planned', 'idempotency_key' => $op, 'created_by' => $uid, 'created_at' => apr_now()]);
    if ($k['channel'] === 'email') {
        $status = apr_mail_send('request', $k['email'], $k['subject'], apr_request_mail_text($k, $token, $expires), 'request:' . $msgPid, (int)$c['office_id'],
            ['from_name' => $k['office_name'], 'reply_to' => $ctx['user']['email']]);
    } else {
        $status = 'unknown';                               // המערכת אינה יודעת אם הקישור נמסר
    }
    apr_exec('UPDATE messages SET status = ?, sent_at = ?, error = ? WHERE id = ?', [$status, $status === 'failed' ? null : apr_now(), $status === 'failed' ? 'mail' : null, $msgId]);
    if ($status !== 'failed') {
        foreach (apr_rows("SELECT r.* FROM request_items ri JOIN requirements r ON r.id = ri.requirement_id WHERE ri.request_id = ? AND r.status = 'required' AND r.split_at IS NULL", [$req['id']]) as $r) {
            apr_req_move($r, 'requested', null, $uid);
        }
    }
    return ['message' => $status, 'message_pid' => $msgPid, 'link' => $k['channel'] === 'link_copy' ? apr_portal_url($token) : null];
}

/** תשובה לבקשה חוזרת עם אותו מזהה פעולה: מה שנרשם אז — בלי הודעה שנייה, ובלי הקישור (הוא הוצג פעם אחת). */
function apr_request_repeat(array $c, string $op): ?array
{
    $m = apr_row('SELECT m.status, m.case_id, r.pid FROM messages m JOIN requests r ON r.id = m.request_id WHERE m.idempotency_key = ?', [$op]);
    if (!$m) return null;
    if ((int)$m['case_id'] !== (int)$c['id']) apr_fail('content_invalid');
    return ['request' => $m['pid'], 'message' => $m['status'], 'link' => null, 'repeat' => true];
}

function apr_request_send(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'contact');
    $op = apr_request_op($ctx, $in['op'] ?? null);
    if ($old = apr_request_repeat($c, $op)) return $old;
    $k = apr_request_content($ctx, $c, $in);
    apr_request_preview_check((int)$ctx['user']['id'], $k['hash'], $in['preview'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $k, $op) {
        apr_case_still_open($c);
        if ($old = apr_request_repeat($c, $op)) return $old;
        $k = apr_request_content($ctx, $c, $in);            // שוב, בתוך הטרנזקציה: הדרישות והנמען לא השתנו מאז התצוגה המקדימה
        apr_request_preview_check((int)$ctx['user']['id'], $k['hash'], $in['preview'] ?? null);
        $pid = apr_pid();
        $expires = apr_iso(apr_time() + $k['days'] * 86400);
        $id = apr_insert('requests', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'party_id' => $k['party']['id'],
            'perms' => json_encode($k['perms']), 'subject' => $k['subject'], 'body' => $k['body'], 'days' => $k['days'], 'expires_at' => $expires, 'status' => 'active',
            'created_by' => $ctx['user']['id'], 'created_at' => apr_now()]);
        foreach ($k['items'] as $r) apr_insert('request_items', ['request_id' => $id, 'requirement_id' => $r['id']]);
        $sent = apr_request_dispatch($ctx, $c, ['id' => $id], $k, $op, $expires);
        apr_case_audit($c, 'request.send', 'request', $pid, ['party' => $k['party']['pid'], 'items' => count($k['items']), 'channel' => $k['channel'], 'message' => $sent['message']]);
        apr_case_touch($c);
        return ['request' => $pid, 'message' => $sent['message'], 'link' => $sent['link']];
    });
}

/** שליחה מחדש: קישור חדש, והקודם מבוטל; מכשירים שאומתו נשכחים. בקשה שפגה מקבלת תוקף חדש. */
function apr_request_resend(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, apr_doc_case_pid('requests', $in['request'] ?? null), 'contact');
    $op = apr_request_op($ctx, $in['op'] ?? null);
    if ($old = apr_request_repeat($c, $op)) return $old;
    return apr_tx(function () use ($ctx, $c, $in, $op) {
        apr_case_still_open($c);
        if ($old = apr_request_repeat($c, $op)) return $old;
        $req = apr_request_row($c, $in['request'] ?? null);
        if ($req['status'] === 'revoked') apr_fail('request_state');
        $k = apr_request_content($ctx, $c, $in, $req);
        apr_request_preview_check((int)$ctx['user']['id'], $k['hash'], $in['preview'] ?? null);
        $expired = strtotime($req['expires_at']) <= apr_time();
        $expires = $expired || isset($in['days']) ? apr_iso(apr_time() + $k['days'] * 86400) : $req['expires_at'];
        if (apr_exec("UPDATE requests SET expires_at = ?, days = ?, perms = ? WHERE id = ? AND status <> 'revoked'", [$expires, $k['days'], json_encode($k['perms']), $req['id']]) !== 1) apr_fail('request_state');
        $sent = apr_request_dispatch($ctx, $c, $req, $k, $op, $expires);
        apr_case_audit($c, 'request.resend', 'request', $req['pid'], ['party' => $k['party']['pid'], 'channel' => $k['channel'], 'message' => $sent['message']]);
        apr_case_touch($c);
        return ['request' => $req['pid'], 'message' => $sent['message'], 'link' => $sent['link']];
    });
}

/**
 * ביטול בקשה — בתוך טרנזקציה. מיידי: הקישורים מבוטלים, והפורטל בודק זאת בכל בקשה. דרישה שעדיין "התבקשה" ושאין לה
 * בקשה פעילה אחרת חוזרת ל-required. $why — למה (ביוזמת משתמש: null). מחזיר false אם כבר בוטלה.
 */
function apr_request_revoke_row(array $c, array $req, ?int $by, ?string $why = null): bool
{
    if (apr_exec("UPDATE requests SET status = 'revoked', revoked_at = ?, revoked_by = ? WHERE id = ? AND status <> 'revoked'", [apr_now(), $by, $req['id']]) !== 1) return false;
    apr_request_links_kill((int)$req['id']);
    foreach (apr_rows("SELECT r.* FROM request_items ri JOIN requirements r ON r.id = ri.requirement_id WHERE ri.request_id = ? AND r.status = 'requested' AND r.split_at IS NULL", [$req['id']]) as $r) {
        $other = apr_val("SELECT 1 FROM request_items ri JOIN requests q ON q.id = ri.request_id WHERE ri.requirement_id = ? AND q.id <> ? AND q.status = 'active' AND q.expires_at > ?", [$r['id'], $req['id'], apr_now()]);
        if (!$other) apr_req_move($r, 'required', null, $by);
    }
    apr_case_audit($c, 'request.revoke', 'request', $req['pid'], $why !== null ? ['why' => $why] : [], null, $by, $by === null ? 'system' : 'user');
    return true;
}

function apr_request_revoke(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, apr_doc_case_pid('requests', $in['request'] ?? null), 'contact');
    return apr_tx(function () use ($ctx, $c, $in) {
        apr_case_still_open($c);
        $req = apr_request_row($c, $in['request'] ?? null);
        if (!apr_request_revoke_row($c, $req, (int)$ctx['user']['id'])) apr_fail('request_state');
        apr_case_touch($c);
        return [];
    });
}

/** גורם הוסר מהתיק — כל הבקשות שלו מבוטלות (§7). בתוך הטרנזקציה של ההסרה. */
function apr_requests_on_party_remove(array $c, int $partyId, int $by): void
{
    foreach (apr_rows("SELECT * FROM requests WHERE party_id = ? AND case_id = ? AND status <> 'revoked'", [$partyId, $c['id']]) as $req) apr_request_revoke_row($c, $req, $by, 'party_removed');
}

/** המייל של איש קשר השתנה — כל הקישורים הפעילים שלו מבוטלים, ונדרשת שליחה מחדש (§7). בתוך הטרנזקציה של העדכון. */
function apr_requests_on_email_change(int $contactId): void
{
    $rows = apr_rows("SELECT q.id, q.pid, c.id AS case_id, c.pid AS case_pid, c.office_id FROM requests q JOIN case_parties cp ON cp.id = q.party_id JOIN cases c ON c.id = q.case_id
        WHERE cp.contact_id = ? AND q.status <> 'revoked'", [$contactId]);
    foreach ($rows as $q) {
        if (apr_request_links_kill((int)$q['id']) > 0) apr_case_audit(['id' => $q['case_id'], 'pid' => $q['case_pid'], 'office_id' => $q['office_id']], 'request.link_revoked', 'request', $q['pid'], ['why' => 'email_changed']);
    }
}

/** אחרי שינוי מצב של דרישה: בקשה שכל הדרישות שלה אושרו או סומנו לא רלוונטי — "הושלמה"; ואם אחת נפתחה שוב — חוזרת לפעילה. */
function apr_requests_on_req_change(int $reqId): void
{
    foreach (apr_rows("SELECT q.id, q.status FROM request_items ri JOIN requests q ON q.id = ri.request_id WHERE ri.requirement_id = ? AND q.status IN ('active', 'completed')", [$reqId]) as $q) {
        $open = (int)apr_val("SELECT COUNT(*) FROM request_items ri JOIN requirements r ON r.id = ri.requirement_id WHERE ri.request_id = ? AND r.split_at IS NULL AND r.status NOT IN ('approved', 'not_relevant')", [$q['id']]);
        $to = $open === 0 ? 'completed' : 'active';
        if ($to !== $q['status']) apr_exec('UPDATE requests SET status = ? WHERE id = ? AND status = ?', [$to, $q['id'], $q['status']]);
    }
}

// ── תצוגה ─────────────────────────────────────────────────────

/** המצב שמוצג: מה שבמסד, ו"פג" כשהתוקף עבר. needs_resend — הבקשה חיה, ואין לה קישור חי (המייל של הנמען השתנה). */
function apr_request_out(array $q, array $link, array $items, array $messages, array $responses): array
{
    $expired = strtotime($q['expires_at']) <= apr_time();
    $live = $link && $link['revoked_at'] === null;
    return ['pid' => $q['pid'], 'party' => $q['party_pid'], 'party_name' => $q['party_name'], 'status' => $q['status'] === 'active' && $expired ? 'expired' : $q['status'],
        'needs_resend' => $q['status'] !== 'revoked' && !$expired && !$live, 'perms' => json_decode($q['perms'], true), 'subject' => $q['subject'], 'body' => $q['body'], 'days' => (int)$q['days'],
        'expires_at' => $q['expires_at'], 'created_at' => $q['created_at'], 'created_by' => $q['creator'], 'revoked_at' => $q['revoked_at'],
        'last_used_at' => $link['last_used_at'] ?? null, 'use_count' => (int)($link['use_count'] ?? 0),
        'requirements' => $items, 'messages' => $messages, 'responses' => $responses];
}

/** הבקשות של תיק, מהחדשה לישנה: מצב, תוקף, שימוש אחרון, ההודעות והתשובות. הרשאה: view; can_send — למי שיש contact. */
function apr_case_requests(array $ctx, array $q): array
{
    $c = apr_case_get($ctx, $q['case'] ?? null);
    $rows = apr_rows('SELECT q.*, cp.pid AS party_pid, ct.name AS party_name, u.name AS creator FROM requests q JOIN case_parties cp ON cp.id = q.party_id JOIN contacts ct ON ct.id = cp.contact_id
        LEFT JOIN users u ON u.id = q.created_by WHERE q.case_id = ? ORDER BY q.id DESC', [$c['id']]);
    $links = $items = $msgs = $resp = [];
    foreach (apr_rows('SELECT l.* FROM portal_links l JOIN requests q ON q.id = l.request_id WHERE q.case_id = ? ORDER BY l.id', [$c['id']]) as $l) $links[$l['request_id']] = $l;   // האחרון
    foreach (apr_rows('SELECT ri.request_id, r.pid FROM request_items ri JOIN requirements r ON r.id = ri.requirement_id JOIN requests q ON q.id = ri.request_id WHERE q.case_id = ? ORDER BY r.sort, r.id', [$c['id']]) as $i) $items[$i['request_id']][] = $i['pid'];
    foreach (apr_rows('SELECT m.*, u.name AS sender FROM messages m LEFT JOIN users u ON u.id = m.created_by WHERE m.case_id = ? ORDER BY m.id DESC', [$c['id']]) as $m) {
        $msgs[$m['request_id']][] = ['pid' => $m['pid'], 'channel' => $m['channel'], 'status' => $m['status'], 'created_at' => $m['created_at'], 'sent_at' => $m['sent_at'], 'by' => $m['sender']];
    }
    foreach (apr_rows('SELECT s.*, r.pid AS req_pid FROM requirement_responses s JOIN requirements r ON r.id = s.requirement_id WHERE r.case_id = ? ORDER BY s.id DESC', [$c['id']]) as $s) {
        $resp[$s['request_id']][] = ['pid' => $s['pid'], 'requirement' => $s['req_pid'], 'kind' => $s['kind'], 'text' => $s['text'], 'created_at' => $s['created_at']];
    }
    return ['case' => ['pid' => $c['pid'], 'status' => $c['status']], 'can_send' => !empty($c['perms']['contact']) && $c['status'] !== 'closed',
        'days' => APR_REQUEST_DAYS, 'perms_default' => APR_REQUEST_PERMS, 'item_states' => APR_REQUEST_ITEM_STATES,
        'always_otp' => (bool)apr_val('SELECT portal_always_otp FROM offices WHERE id = ?', [$c['office_id']]),
        'requests' => array_map(fn($r) => apr_request_out($r, $links[$r['id']] ?? [], $items[$r['id']] ?? [], $msgs[$r['id']] ?? [], $resp[$r['id']] ?? []), $rows)];
}

/** ללוח הראשי: בקשות שההודעה האחרונה שלהן נכשלה — בתיקים פתוחים שיש לי בהם contact. */
function apr_requests_failed(array $ctx): array
{
    $rows = apr_rows("SELECT q.pid, q.created_at, m.created_at AS failed_at, ct.name AS party_name, c.pid AS case_pid, c.number, c.title FROM requests q
        JOIN messages m ON m.id = (SELECT MAX(id) FROM messages WHERE request_id = q.id) JOIN case_parties cp ON cp.id = q.party_id JOIN contacts ct ON ct.id = cp.contact_id
        JOIN cases c ON c.id = q.case_id
        WHERE m.status = 'failed' AND q.status = 'active' AND c.status <> 'closed' AND q.case_id IN (SELECT c.id " . apr_my_cases_sql('contact') . ')
        ORDER BY m.id DESC LIMIT 100', [$ctx['user']['id'], apr_office_id($ctx)]);
    return ['failed' => array_map(fn($r) => ['request' => $r['pid'], 'party_name' => $r['party_name'], 'failed_at' => $r['failed_at'],
        'case' => ['pid' => $r['case_pid'], 'number' => $r['number'], 'title' => $r['title']]], $rows)];
}

/** הגדרת משרד: "תמיד לדרוש קוד" בפורטל. */
function apr_office_portal_otp(array $ctx, array $in): array
{
    $office = apr_office_id($ctx);
    $on = ($in['on'] ?? null) === true;
    return apr_tx(function () use ($office, $on, $ctx) {
        apr_exec('UPDATE offices SET portal_always_otp = ? WHERE id = ?', [(int)$on, $office]);
        apr_audit('office.portal_otp', 'office', $ctx['office']['pid'], ['on' => $on], office: $office);
        return ['always_otp' => $on];
    });
}
