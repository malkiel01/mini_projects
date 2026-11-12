<?php
/**
 * P1 — "👥 אנשים": משתמשי האפליקציה, בקשות גישה, והזמנות. בידי בעל העמוד (אפיון 08 §3, sso.md §6).
 *
 * הוספת עוזר = מייל + שם + תפקיד. השרת קורא ל-platform/provision.php (חתום Ed25519, בזוג מפתחות נפרד מ-embed_key)
 * עם grant:true — והפלטפורמה פותחת חשבון ונותנת הרשאה **לאפליקציה הזו בלבד**. כך אין צעד של בעל האתר.
 * הסרה = provision עם grant:false, status=removed, וכל ה-sessions שלו נמחקים מיד.
 *
 * members ו-access_requests (לפני P1) — נשארות במסד לקריאה בלבד; מכאן לא כותבים אליהן.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const PEOPLE_ASSIGNABLE = ['content_editor', 'editor', 'publisher', 'page_owner'];

function people_public(array $u): array
{
    return [
        'id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'status' => $u['status'],
        'joined' => $u['sub'] !== null, 'created_at' => $u['created_at'], 'last_seen' => $u['last_seen'],
        'invited_by' => $u['inviter'] ?? null, 'invite' => $u['invite'], 'invite_note' => $u['invite_note'], 'invite_at' => $u['invite_at'],
        'agent' => $u['agent'] !== null, 'ai_quota' => $u['ai_quota'] === null ? null : (int)$u['ai_quota'],
    ];
}

function people_state(?array $actor = null): array
{
    $pdo = db();
    $rows = $pdo->query("SELECT u.*, i.name AS inviter FROM users u LEFT JOIN users i ON i.id = u.invited_by
                         WHERE u.agent IS NULL AND (u.role IS NOT NULL OR u.sub IS NULL) AND u.status = 'active'
                         ORDER BY CASE u.role WHEN 'site_owner' THEN 0 WHEN 'page_owner' THEN 1 WHEN 'publisher' THEN 2 WHEN 'editor' THEN 3 ELSE 4 END, u.name, u.email")->fetchAll();
    $req = $pdo->query("SELECT id, sub, name, email, created_at AS at, last_seen FROM users
                        WHERE agent IS NULL AND sub IS NOT NULL AND role IS NULL AND status = 'active' ORDER BY created_at DESC LIMIT 50")->fetchAll();
    return [
        'people' => array_map('people_public', $rows),
        'requests' => array_map(fn($r) => ['id' => (int)$r['id']] + $r, $req),
        'roles' => ROLES,
        'assignable' => array_values(array_filter(PEOPLE_ASSIGNABLE, fn($r) => $r !== 'page_owner' || role_at_least($actor, 'page_owner'))),
        'can_first_owner' => role_at_least($actor, 'site_owner'),
        'provision' => json_decode((string)setting('provision_last', 'null'), true),
    ];
}

function people_role(mixed $r): string
{
    if (!is_string($r) || !in_array($r, PEOPLE_ASSIGNABLE, true)) throw new ApiError('תפקיד לא תקין');
    return $r;
}

function people_get(int $id): array
{
    $u = user_get($id);
    if (!$u || $u['agent'] !== null) throw new ApiError('האדם לא נמצא', 404);
    return $u;
}

function people_owner_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'page_owner' AND status = 'active' AND agent IS NULL")->fetchColumn();
}

/** מינוי בעל עמוד: בעל עמוד או בעל האתר; **הראשון** — רק בעל האתר. */
function people_check_role_grant(array $actor, string $role): void
{
    if ($role !== 'page_owner') return;
    if (!role_at_least($actor, 'page_owner')) throw new ApiError('מינוי בעל העמוד — רק לבעל העמוד או לבעל האתר', 403);
    if (people_owner_count() === 0 && !role_at_least($actor, 'site_owner')) throw new ApiError('את בעל העמוד הראשון ממנה בעל האתר', 403);
}

/** "בלי בעל עמוד אחרון": הורדה או הסרה של בעל העמוד היחיד — נחסמת. */
function people_check_last_owner(array $u, ?string $newRole): void
{
    if ($u['role'] !== 'page_owner' || $newRole === 'page_owner') return;
    if (people_owner_count() <= 1) throw new ApiError('זה בעל העמוד האחרון. קודם מנו בעל עמוד נוסף — אחרת לאף אחד לא יהיה מי שמנהל את העוזרים', 409, ['code' => 'last_owner']);
}

/** הודעה לבעל העמוד: מישהו נכנס ואין לו תפקיד. */
function people_request_notice(array $row): void
{
    if (!function_exists('notice_add')) require_once __DIR__ . '/build.php';
    notice_add('page_owner', 'access', '🙋 ' . ($row['name'] ?: $row['email'] ?: 'מישהו') . ' נכנס/ה לכלי ומבקש/ת גישה. 👥 אנשים ← ביקשו גישה', null, ['name' => 'מערכת']);
}

// ── provision (sso.md §6) ─────────────────────────────────

/** הזוג של provision — נפרד מ-embed_key (דליפה של אחד לא נותנת את השני). המפתח הפרטי — במסד בלבד. */
function platform_keypair(): array
{
    $k = json_decode((string)setting('platform_key', ''), true);
    if (!is_array($k) || empty($k['secret'])) {
        $kp = sodium_crypto_sign_keypair();
        $k = ['kid' => 'kolp-' . gmdate('Ym') . '-' . bin2hex(random_bytes(2)), 'secret' => base64_encode(sodium_crypto_sign_secretkey($kp)),
            'public' => base64_encode(sodium_crypto_sign_publickey($kp)), 'at' => now_iso()];
        setting_set('platform_key', json_encode($k));
    }
    return $k;
}

/** api.php?action=platform_key — ציבורי (ב-"public" של app.json). אין בו סוד. */
function platform_key_public(): array
{
    $k = platform_keypair();
    return ['client' => 'kol-maroko', 'keys' => [['kid' => $k['kid'], 'alg' => 'Ed25519', 'key' => $k['public']]]];
}

/** כתובת האפליקציה — מ-app.json (provision.key_url), **לעולם לא מהבקשה** (Host מגיע מהלקוח). */
function platform_app_url(): string
{
    $app = json_decode((string)@file_get_contents(dirname(__DIR__) . '/app.json'), true);
    $key = is_array($app) ? (string)($app['provision']['key_url'] ?? '') : '';
    if (!preg_match('~^(https://[a-z0-9.\-]+(?:/[A-Za-z0-9._\-]+)*/)api\.php\?action=platform_key$~', $key, $m)) throw new ApiError('app.json: provision.key_url חסר', 500);
    return $m[1];
}

/** קריאה חתומה ל-platform/provision.php. מחזיר את ה-JSON, או זורק עם הסיבה. בבדיקות — $GLOBALS['kol_provision_url']. */
function provision_call(string $action, array $payload): array
{
    $url = $GLOBALS['kol_provision_url'] ?? (rtrim(dirname(rtrim(platform_app_url(), '/')), '/') . '/platform/provision.php');
    if ($action !== 'provision') $url .= '?action=' . $action;
    $k = platform_keypair();
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $time = (string)time();
    $nonce = bin2hex(random_bytes(16));
    $sig = sodium_crypto_sign_detached("$action\n$time\n$nonce\n" . hash('sha256', $body), base64_decode($k['secret']));
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Embed-Client: kol-maroko', "X-Embed-Time: $time",
            "X-Embed-Nonce: $nonce", 'X-Embed-Signature: ' . $k['kid'] . '.' . base64_encode($sig)]]);
    $res = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $j = is_string($res) ? json_decode($res, true) : null;
    $last = ['at' => now_iso(), 'action' => $action, 'http' => $status, 'status' => is_array($j) ? ($j['status'] ?? null) : null];
    if ($res === false || $status !== 200 || !is_array($j)) {
        $why = $res === false ? 'אין חיבור לפלטפורמה (' . $err . ')' : 'הפלטפורמה ענתה ' . $status . (is_array($j) && isset($j['error']) ? ' — ' . mb_substr((string)$j['error'], 0, 120) : '');
        setting_set('provision_last', json_encode($last + ['error' => $why], JSON_UNESCAPED_UNICODE));
        devlog_note('provision', $why);
        throw new ApiError($why, 502, ['code' => 'provision_failed']);
    }
    setting_set('provision_last', json_encode($last, JSON_UNESCAPED_UNICODE));
    return $j;
}

/** רישום ביומן המפתח בלי לשבור כשהיומן לא נטען (בדיקות יחידה). */
function devlog_note(string $what, string $msg): void
{
    if (function_exists('devlog_write')) { devlog_write('warn', 'people', $what, ['message' => $msg]); return; }
    error_log("kol-maroko $what: $msg");
}

/** מריץ provision עבור שורה, ושומר את התוצאה בה. מחזיר את ההודעה לממשק. */
function people_provision(array $u, array $actor): string
{
    $pdo = db();
    try {
        $r = provision_call('provision', ['email' => $u['email'], 'name' => $u['name'], 'grant' => true, 'next' => (string)parse_url(platform_app_url(), PHP_URL_PATH)]);
    } catch (ApiError $e) {
        $pdo->prepare("UPDATE users SET invite = 'pending', invite_note = ?, invite_at = ? WHERE id = ?")->execute([$e->getMessage(), now_iso(), $u['id']]);
        audit_as($actor, 'person_invite_failed', ($u['email'] ?? '') . ' · ' . $e->getMessage());
        return 'ההזמנה נשמרה וממתינה — ' . $e->getMessage() . '. "נסה שוב" בשורה שלו';
    }
    $status = (string)($r['status'] ?? '');
    $sub = is_scalar($r['sub'] ?? null) ? (string)$r['sub'] : '';
    if (!in_array($status, ['invited', 'granted', 'blocked'], true)) {
        $pdo->prepare("UPDATE users SET invite = 'pending', invite_note = ?, invite_at = ? WHERE id = ?")->execute(['תשובה לא צפויה מהפלטפורמה', now_iso(), $u['id']]);
        return 'ההזמנה נשמרה וממתינה — תשובה לא צפויה מהפלטפורמה. "נסה שוב" בשורה שלו';
    }
    if ($sub !== '' && $u['sub'] === null) {
        // כבר נכנס פעם (ביקש גישה) — השורה הקיימת מקבלת את התפקיד, וההזמנה מתמזגת בה
        $other = user_by_sub($sub);
        if ($other && (int)$other['id'] !== (int)$u['id']) {
            $pdo->prepare("UPDATE users SET role = ?, status = 'active', email = COALESCE(email, ?), invited_by = ?, invite = ?, invite_note = '', invite_at = ? WHERE id = ?")
                ->execute([$u['role'], $u['email'], $u['invited_by'], $status, now_iso(), $other['id']]);
            $pdo->prepare('DELETE FROM users WHERE id = ? AND sub IS NULL')->execute([$u['id']]);       // השורה הזמנית, שנוצרה רגע לפני
            $u = user_get((int)$other['id']);
        } else {
            $pdo->prepare('UPDATE users SET sub = ? WHERE id = ?')->execute([$sub, $u['id']]);
        }
    }
    $pdo->prepare("UPDATE users SET invite = ?, invite_note = '', invite_at = ? WHERE id = ?")->execute([$status, now_iso(), $u['id']]);
    audit_as($actor, 'person_invite', ($u['email'] ?? '') . ' · ' . ROLES[$u['role']] . ' · ' . $status);
    return match ($status) {
        'invited' => 'נשלחה הזמנה ל-' . $u['email'] . ', תקפה 7 ימים',
        'granted' => 'יש לו כבר חשבון בכלים הדיגיטליים; ניתנה לו גישה',
        'blocked' => 'החשבון חסום בפלטפורמה — רק בעל האתר יכול לשחרר',
    };
}

/** "הוסף עוזר": מייל + שם + תפקיד — או אישור של מי שביקש גישה (id). */
function person_invite(array $actor, array $b): array
{
    $role = people_role($b['role'] ?? null);
    people_check_role_grant($actor, $role);
    $pdo = db();
    if (isset($b['id'])) {                                    // מי שביקש גישה — כבר יש לו חשבון ו-sub
        $u = people_get((int)$b['id']);
        if ($u['role'] !== null) throw new ApiError('כבר יש לו תפקיד. שינוי — בשורה שלו');
        $pdo->prepare("UPDATE users SET role = ?, status = 'active', invited_by = ? WHERE id = ?")->execute([$role, $actor['id'] ?? null, $u['id']]);
        audit_as($actor, 'member_add', ($u['name'] ?: $u['email'] ?: $u['sub']) . ' · ' . ROLES[$role]);
        notice_user((int)$u['id'], 'access', '✅ קיבלת תפקיד בכלי: ' . ROLES[$role], $actor);
        return people_state($actor) + ['message' => 'נוסף: ' . ($u['name'] ?: $u['email']) . ' · ' . ROLES[$role]];
    }
    $email = mb_strtolower(trim((string)($b['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 200) throw new ApiError('מייל לא תקין');
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($b['name'] ?? '')) ?? ''), 0, 80);
    $st = $pdo->prepare('SELECT * FROM users WHERE agent IS NULL AND email = ? ORDER BY sub IS NULL, id LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch() ?: null;
    if ($u && $u['status'] === 'active' && $u['role'] !== null) throw new ApiError('המייל כבר ברשימה (' . ROLES[$u['role']] . ')');
    if ($u) {
        $pdo->prepare("UPDATE users SET role = ?, status = 'active', name = CASE WHEN name = '' THEN ? ELSE name END, invited_by = ? WHERE id = ?")
            ->execute([$role, $name, $actor['id'] ?? null, $u['id']]);
    } else {
        $pdo->prepare("INSERT INTO users (name, email, role, status, created_at, invited_by) VALUES (?, ?, ?, 'active', ?, ?)")
            ->execute([$name, $email, $role, now_iso(), $actor['id'] ?? null]);
        $u = ['id' => (int)$pdo->lastInsertId()];
    }
    $u = user_get((int)$u['id']);
    $msg = people_provision($u, $actor);
    return people_state($actor) + ['message' => $msg];
}

function person_retry(array $actor, array $b): array
{
    $u = people_get((int)($b['id'] ?? 0));
    if ($u['status'] !== 'active' || !$u['email']) throw new ApiError('אין מה לנסות שוב');
    return people_state($actor) + ['message' => people_provision($u, $actor)];
}

/** שינוי תפקיד — מיידי, גם ל-session פתוח (התפקיד נקרא מ-users בכל בקשה). */
function person_role(array $actor, array $b): array
{
    $u = people_get((int)($b['id'] ?? 0));
    $role = people_role($b['role'] ?? null);
    if ($u['role'] === 'site_owner') throw new ApiError('בעל האתר נקבע בפלטפורמה, לא כאן', 403);
    if ($u['status'] !== 'active') throw new ApiError('האדם הוסר. הוספה מחדש — "הוסף עוזר"');
    if ($u['role'] === 'page_owner' && !role_at_least($actor, 'page_owner')) throw new ApiError('רק בעל העמוד', 403);
    people_check_role_grant($actor, $role);
    people_check_last_owner($u, $role);
    db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $u['id']]);
    audit_as($actor, 'member_role', ($u['name'] ?: $u['email']) . ': ' . ($u['role'] ? ROLES[$u['role']] : 'בלי תפקיד') . ' ← ' . ROLES[$role]);
    return people_state($actor) + ['message' => 'נשמר: ' . ROLES[$role]];
}

/** הסרה: grant:false בפלטפורמה, status=removed, וכל ה-sessions שלו — נמחקים מיד. */
function person_remove(array $actor, array $b): array
{
    $u = people_get((int)($b['id'] ?? 0));
    if ($u['role'] === 'site_owner') throw new ApiError('את בעל האתר לא מסירים מכאן', 403);
    people_check_last_owner($u, null);
    $pdo = db();
    $pdo->prepare("UPDATE users SET status = 'removed' WHERE id = ?")->execute([$u['id']]);
    $n = login_drop_sessions((int)$u['id']);
    $plat = '';
    if ($u['email'] || $u['sub']) {
        try {
            $r = provision_call('provision', array_filter(['email' => $u['email'], 'sub' => $u['sub'], 'grant' => false], fn($v) => $v !== null));
            $plat = (string)($r['status'] ?? '');
            $pdo->prepare("UPDATE users SET invite = 'revoked', invite_note = '', invite_at = ? WHERE id = ?")->execute([now_iso(), $u['id']]);
        } catch (ApiError $e) {
            $plat = 'failed';
            $pdo->prepare('UPDATE users SET invite_note = ?, invite_at = ? WHERE id = ?')->execute(['ההרשאה בפלטפורמה לא בוטלה: ' . $e->getMessage(), now_iso(), $u['id']]);
        }
    }
    audit_as($actor, 'member_remove', ($u['name'] ?: (string)$u['email']) . " · sessions: $n · פלטפורמה: " . ($plat ?: '—'));
    return people_state($actor) + ['message' => 'הוסר' . ($plat === 'failed' ? ' מהכלי. ההרשאה בכלים הדיגיטליים לא בוטלה (הפלטפורמה לא ענתה) — הוא בכל זאת לא ייכנס לכאן' : '')];
}

/** "ביקשו גישה" ← התעלמות: השורה מסומנת removed ולא חוזרת לרשימה. */
function request_dismiss(array $actor, array $b): array
{
    $u = people_get((int)($b['id'] ?? 0));
    if ($u['role'] !== null) throw new ApiError('יש לו תפקיד. הסרה — בשורה שלו');
    db()->prepare("UPDATE users SET status = 'removed' WHERE id = ?")->execute([$u['id']]);
    login_drop_sessions((int)$u['id']);
    return people_state($actor);
}

// ── יומן הפעולות (08 §6) ──────────────────────────────────

/**
 * בעל העמוד — היומן של העמוד; בעל האתר — הכול (כולל החיבור והמכסות). סינון: person (users.id), kind (קידומת פעולה),
 * source (studio / agent / import / ai / system).
 */
const AUDIT_SITE_ONLY = ['key_rotate', 'key_activate', 'site_set', 'disk_cap', 'secret_set', 'ai_quota', 'pf_limits', 'mount_set', 'import', 'audio_import', 'rec_fetch', 'owner_set', 'cutover', 'pull_token', 'pub_channel'];

function audit_list(array $actor, array $b): array
{
    $before = (int)($b['before'] ?? 0);
    $where = ['a.id < :b'];
    $args = [':b' => $before > 0 ? $before : PHP_INT_MAX];
    if (!role_at_least($actor, 'site_owner')) {
        $parts = [];
        foreach (AUDIT_SITE_ONLY as $i => $p) { $parts[] = "a.action NOT LIKE :s$i"; $args[":s$i"] = $p . '%'; }
        $where[] = '(' . implode(' AND ', $parts) . ')';
    }
    if ((int)($b['person'] ?? 0) > 0) { $where[] = 'a.user_id = :p'; $args[':p'] = (int)$b['person']; }
    if (is_string($b['source'] ?? null) && in_array($b['source'], ['studio', 'agent', 'import', 'ai', 'system'], true)) { $where[] = 'a.source = :src'; $args[':src'] = $b['source']; }
    if (is_string($b['kind'] ?? null) && preg_match('/^[a-z_]{2,30}$/', $b['kind'])) { $where[] = 'a.action LIKE :k'; $args[':k'] = $b['kind'] . '%'; }
    $st = db()->prepare('SELECT a.id, a.at, a.name, a.action, a.detail, a.user_id, a.source FROM audit a WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id DESC LIMIT 100');
    foreach ($args as $k => $v) $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $st->execute();
    $people = db()->query("SELECT DISTINCT u.id, u.name, u.email FROM users u JOIN audit a ON a.user_id = u.id ORDER BY u.name LIMIT 200")->fetchAll();
    return ['audit' => $st->fetchAll(), 'people' => $people, 'all' => role_at_least($actor, 'site_owner')];
}
