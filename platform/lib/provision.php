<?php
/**
 * הזמנת משתמשים מאפליקציה — אפליקציה פותחת לאנשים שלה חשבון בפלטפורמה עם הרשאה אליה בלבד.
 *
 * הצרכן הראשון: מגדלור — כל ההורים נכנסים דרך הפלטפורמה (החלטת בעל המערכת, 2026-10-20).
 * המדריך: docs/sso.md, סעיף 6. נקודת הקצה: provision.php.
 *
 * ההזדהות של האפליקציה — בלי סוד להעתיק, כמו docs/embed.md סעיף 1–2: ב-app.json
 * "provision": {"key_url": "https://…"} — כתובת שבה האפליקציה מפרסמת מפתח Ed25519 ציבורי.
 * כל בקשה חתומה במפתח הפרטי שלה (שנשאר אצלה), והפלטפורמה מורידה את הציבורי בעצמה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/access.php';

const PROVISION_SKEW = 300;         // שניות — סטייה מותרת בין השעונים
const PROVISION_KEY_CACHE = 3600;   // המפתח הציבורי של אפליקציה נשמר שעה
const INVITE_DAYS = 7;

function provision_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS provision_keys (
            client     TEXT PRIMARY KEY,
            key_url    TEXT NOT NULL,
            keys       TEXT NOT NULL,
            fetched_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS provision_nonces (
            nonce TEXT PRIMARY KEY,
            at    INTEGER NOT NULL
        );
        -- הזמנה שעוד לא מומשה: בעל החשבון עוד לא בחר סיסמה.
        CREATE TABLE IF NOT EXISTS invite_tokens (
            token_hash TEXT PRIMARY KEY,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            app        TEXT NOT NULL,
            next       TEXT NOT NULL,
            expires_at TEXT NOT NULL
        );
        SQL);
}

/** האפליקציות שמותר להן להזמין: {id: {id, name, key_url, href}}. */
function provision_clients(): array
{
    $out = [];
    foreach (registry_load()['apps'] as $app) {
        if (!$app['provision_key_url']) continue;
        $out[$app['id']] = ['id' => $app['id'], 'name' => $app['name'],
            'key_url' => $app['provision_key_url'], 'href' => $app['href']];
    }
    return $out;
}

/** המפתחות הציבוריים של אפליקציה (kid => בינארי), מהמטמון או מ-key_url. */
function provision_keys(array $client, bool $refresh = false): array
{
    provision_migrate();
    $st = db()->prepare('SELECT * FROM provision_keys WHERE client = ?');
    $st->execute([$client['id']]);
    $row = $st->fetch();
    if (!$refresh && $row && $row['key_url'] === $client['key_url'] && $row['fetched_at'] > time() - PROVISION_KEY_CACHE) {
        $raw = json_decode($row['keys'], true);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"]]);
        $json = json_decode((string)@file_get_contents($client['key_url'], false, $ctx), true);
        $raw = [];
        foreach ((array)($json['keys'] ?? []) as $k) {
            if (($json['client'] ?? '') !== $client['id'] || ($k['alg'] ?? '') !== 'Ed25519') continue;
            $bin = base64_decode((string)($k['key'] ?? ''), true);
            if (is_string($bin) && strlen($bin) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && is_string($k['kid'] ?? null)) {
                $raw[$k['kid']] = $k['key'];
            }
        }
        if (!$raw) throw new ApiError('המפתח הציבורי של האפליקציה לא נמצא ב-key_url', 502);
        db()->prepare('INSERT INTO provision_keys (client, key_url, keys, fetched_at) VALUES (?,?,?,?)
                       ON CONFLICT(client) DO UPDATE SET key_url = excluded.key_url, keys = excluded.keys, fetched_at = excluded.fetched_at')
            ->execute([$client['id'], $client['key_url'], json_encode($raw), time()]);
    }
    return array_map(fn($k) => base64_decode($k, true), (array)$raw);
}

/**
 * בודק בקשה חתומה (docs/embed.md, סעיף 2: X-Embed-Client / Time / Nonce / Signature, על
 * "<action>\n<time>\n<nonce>\n<sha256 של הגוף>"). מחזיר את האפליקציה; אחרת — ApiError 401.
 */
function provision_verify(string $action, string $body, array $h): array
{
    provision_migrate();
    $client = provision_clients()[(string)($h['client'] ?? '')] ?? null;
    if (!$client) throw new ApiError('האפליקציה לא רשומה להזמנת משתמשים', 401);
    $time = (string)($h['time'] ?? '');
    $nonce = (string)($h['nonce'] ?? '');
    if (!ctype_digit($time) || abs(time() - (int)$time) > PROVISION_SKEW) throw new ApiError('השעה בבקשה לא תקינה', 401);
    if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) throw new ApiError('nonce לא תקין', 401);
    if (!preg_match('/^([A-Za-z0-9._-]{1,40})\.([A-Za-z0-9+\/=]+)$/', (string)($h['signature'] ?? ''), $m)) {
        throw new ApiError('חתימה חסרה', 401);
    }
    $sig = base64_decode($m[2], true);
    $msg = "$action\n$time\n$nonce\n" . hash('sha256', $body);
    $ok = false;
    foreach ([false, true] as $refresh) {                     // חתימה שנכשלת — הורדה אחת מחדש (החלפת מפתח)
        try {
            $key = provision_keys($client, $refresh)[$m[1]] ?? null;
        } catch (ApiError $e) {
            if (!$refresh) throw $e;
            break;                                            // ההורדה מחדש נכשלה — החתימה נשארת לא תקינה
        }
        if ($key && is_string($sig) && strlen($sig) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($sig, $msg, $key)) { $ok = true; break; }
    }
    if (!$ok) throw new ApiError('החתימה לא תקינה', 401);

    db()->prepare('DELETE FROM provision_nonces WHERE at < ?')->execute([time() - 2 * PROVISION_SKEW]);
    try {
        db()->prepare('INSERT INTO provision_nonces (nonce, at) VALUES (?,?)')->execute([$nonce, time()]);
    } catch (PDOException) {
        throw new ApiError('הבקשה כבר התקבלה', 401);
    }
    return $client;
}

/** הרשאה ישירה לאפליקציה אחת (מותר / חסום) — בלי לגעת בשאר ההרשאות של המשתמש. */
function provision_set_grant(int $userId, string $app, bool $allowed): void
{
    access_migrate();
    db()->prepare("INSERT INTO grants (user_id, kind, target, allowed) VALUES (?, 'app', ?, ?)
                   ON CONFLICT(user_id, kind, target) DO UPDATE SET allowed = excluded.allowed")
        ->execute([$userId, $app, $allowed ? 1 : 0]);
}

/**
 * {email, name, grant, next?} — grant=true: חשבון (קיים, או חדש עם הזמנה במייל) והרשאה לאפליקציה.
 * grant=false: ההרשאה לאפליקציה מוסרת (החשבון נשאר — אולי יש לו אפליקציות אחרות).
 */
function provision_user(array $client, array $body): array
{
    require_once __DIR__ . '/email.php';
    require_once __DIR__ . '/auth.php';
    provision_migrate();
    $email = validate_email($body['email'] ?? null);
    $st = db()->prepare('SELECT * FROM users WHERE email = ? OR username = ?');
    $st->execute([$email, $email]);
    $user = $st->fetch() ?: null;

    if (($body['grant'] ?? true) === false) {
        if ($user) provision_set_grant((int)$user['id'], $client['id'], false);
        return ['status' => $user ? 'revoked' : 'unknown'];
    }
    // הכתובת שאליה המוזמן מגיע אחרי שבחר סיסמה — רק בתוך האפליקציה.
    $base = cookie_path();                                   // /mini_projects/
    $next = is_string($body['next'] ?? null) && $body['next'] !== '' ? $body['next'] : $base . ltrim($client['href'], '/');
    if (!str_starts_with($next, $base . $client['id'] . '/') || preg_match('#\.\.|[\s\\\\]#', $next)) {
        throw new ApiError('next חייב להיות כתובת בתוך האפליקציה', 400);
    }

    if ($user) {
        if ($user['status'] === 'blocked') return ['status' => 'blocked', 'sub' => (string)$user['id']];
        provision_set_grant((int)$user['id'], $client['id'], true);
        // חשבון שהוזמן ועוד לא נכנס — הזמנה חדשה (הקודמת אולי אבדה).
        $pending = db()->prepare('SELECT 1 FROM invite_tokens WHERE user_id = ?');
        $pending->execute([(int)$user['id']]);
        if (!$pending->fetch()) return ['status' => 'granted', 'sub' => (string)$user['id']];
        $id = (int)$user['id'];
        $name = $user['display_name'];
    } else {
        $name = validate_display_name($body['name'] ?? null);
        // בלי סיסמה שמישהו יודע — נכנסים רק דרך קישור ההזמנה.
        db()->prepare('INSERT INTO users (username, email, password_hash, display_name, role, status, created_at)
                       VALUES (?,?,?,?,?,?,?)')
            ->execute([$email, $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $name, 'user', 'active', now_iso()]);
        $id = (int)db()->lastInsertId();
        provision_set_grant($id, $client['id'], true);
    }

    $token = bin2hex(random_bytes(24));
    db()->prepare('DELETE FROM invite_tokens WHERE user_id = ?')->execute([$id]);
    db()->prepare('INSERT INTO invite_tokens (token_hash, user_id, app, next, expires_at) VALUES (?,?,?,?,?)')
        ->execute([hash('sha256', $token), $id, $client['id'], $next, gmdate('Y-m-d\TH:i:s\Z', time() + INVITE_DAYS * 86400)]);
    $sent = send_mail($email, "הזמנה ל{$client['name']}",
        "שלום $name,\n\nנפתח לך חשבון ב{$client['name']}. כדי להיכנס, בחרו סיסמה בקישור:\n" . site_url() . "#invite=$token\n\n" .
        "הקישור בתוקף " . INVITE_DAYS . " ימים. אחר כך נכנסים במייל הזה ובסיסמה שבחרתם.\n");
    return ['status' => 'invited', 'sub' => (string)$id, 'mail' => $sent];
}

/** מימוש ההזמנה: סיסמה חדשה, המייל אומת, וכניסה. מחזיר {user_id, next}. */
function invite_accept(string $token, string $password): array
{
    provision_migrate();
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) throw new ApiError('הקישור לא תקין', 400);
    $st = db()->prepare('SELECT * FROM invite_tokens WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch();
    if (!$row) throw new ApiError('הקישור כבר נוצל או שאינו תקין — נכנסים במייל ובסיסמה', 410);
    if ($row['expires_at'] < now_iso()) throw new ApiError('תוקף הקישור פג — בקשו הזמנה חדשה ממי ששלח אותה', 410);
    $u = db()->prepare('SELECT * FROM users WHERE id = ?');
    $u->execute([(int)$row['user_id']]);
    $user = $u->fetch();
    if (!$user || $user['status'] === 'blocked') throw new ApiError('החשבון חסום', 403);
    db()->prepare('UPDATE users SET password_hash = ?, must_change_pw = 0, email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), now_iso(), (int)$user['id']]);
    db()->prepare('DELETE FROM invite_tokens WHERE user_id = ?')->execute([(int)$user['id']]);
    return ['user_id' => (int)$user['id'], 'next' => $row['next']];
}
