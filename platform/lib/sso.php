<?php
/**
 * כניסה מאוחדת ("כניסה עם חשבון mbe-plus") — הצד של הספק.
 *
 * חשבון הפלטפורמה הוא **זהות**; לכל אפליקציה חשבון משלה, מקושר לזהות. הפלטפורמה רק מאשרת
 * "זה משתמש X" — לא משתפת משתמשים, מסד, עוגייה או הרשאות (CLAUDE.md: "אפליקציה פנימית
 * עצמאית מהמעטפת"). הדרישות: guarded-browser/docs/sso-request.md. המדריך לאפליקציות:
 * platform/docs/sso.md.
 *
 *   אפליקציה ── redirect ──▶ platform/sso.php?client=<id>&state=…&return=…
 *                            (לא מחובר → כניסה רגילה וחזרה; פעם ראשונה → מסך אישור)
 *   אפליקציה ◀── redirect ── return?token=<זהות חתומה>&state=…
 *   האפליקציה בודקת את החתימה במפתח הציבורי (sso.php?action=key) — בלי סוד, בלי העתקת קודים.
 *
 * "לקוח" = אפליקציה שה-app.json שלה מצהיר "sso": {"return": [...]}. היא נכנסת לכניסה המאוחדת
 * לבד, והמנהל רק יכול להשבית אותה (לחיצה אחת). sso_clients הוא שיקוף של הרישום + דגל השבתה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

const SSO_TOKEN_SECONDS = 120;
const SSO_LOG_DAYS     = 90;
const SSO_MAX_RETURNS  = 10;

function sso_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec(<<<SQL
        -- אפליקציה שמותר לה להשתמש בכניסה המאוחדת. הסוד נשמר כ-hash ומוצג פעם אחת.
        CREATE TABLE IF NOT EXISTS sso_clients (
            id          TEXT PRIMARY KEY,
            name        TEXT NOT NULL,
            secret_hash TEXT NOT NULL,
            returns     TEXT NOT NULL,          -- JSON: כתובות חזרה מותרות, התאמה מדויקת
            disabled    INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS sso_codes (
            code_hash  TEXT PRIMARY KEY,
            client_id  TEXT NOT NULL,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            return_url TEXT NOT NULL,
            expires_at TEXT NOT NULL
        );
        -- הסכמה: המשתמש אישר לאפליקציה לזהות אותו. נזכרת עד שמבטלים.
        CREATE TABLE IF NOT EXISTS sso_consents (
            user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            client_id    TEXT NOT NULL REFERENCES sso_clients(id) ON DELETE CASCADE,
            granted_at   TEXT NOT NULL,
            last_used_at TEXT,
            PRIMARY KEY (user_id, client_id)
        );
        -- זוג המפתחות של הפלטפורמה (Ed25519). הציבורי — לכל אפליקציה; הפרטי לא יוצא מהמסד.
        CREATE TABLE IF NOT EXISTS sso_keys (
            id         INTEGER PRIMARY KEY CHECK (id = 1),
            public_key TEXT NOT NULL,
            secret_key TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS sso_log (
            id        INTEGER PRIMARY KEY,
            at        TEXT NOT NULL,
            client_id TEXT NOT NULL,
            user_id   INTEGER,
            event     TEXT NOT NULL,
            ip        TEXT NOT NULL DEFAULT ''
        );
        SQL);
}

function sso_log(string $client, ?int $userId, string $event): void
{
    sso_migrate();
    db()->prepare('INSERT INTO sso_log (at, client_id, user_id, event, ip) VALUES (?,?,?,?,?)')
        ->execute([now_iso(), mb_substr($client, 0, 40), $userId, $event, client_ip()]);
    if (random_int(1, 100) === 1) {
        db()->prepare('DELETE FROM sso_log WHERE at < ?')->execute([gmdate('Y-m-d\TH:i:s\Z', time() - SSO_LOG_DAYS * 86400)]);
    }
}

/**
 * מסנכרן את sso_clients מהרישום (app.json עם "sso"), ומחזיר את הלקוחות הפעילים ברישום.
 * השם וכתובות החזרה תמיד מ-app.json; ההשבתה — מהמסד (המנהל).
 */
function sso_sync_clients(): array
{
    static $clients = null;
    if ($clients !== null) return $clients;
    sso_migrate();
    require_once __DIR__ . '/registry.php';
    $clients = [];
    $up = db()->prepare(
        "INSERT INTO sso_clients (id, name, secret_hash, returns, created_at) VALUES (?, ?, '', ?, ?)
         ON CONFLICT(id) DO UPDATE SET name = excluded.name, returns = excluded.returns"
    );
    $disabled = array_flip(db()->query('SELECT id FROM sso_clients WHERE disabled = 1')->fetchAll(PDO::FETCH_COLUMN));
    foreach (registry_load()['apps'] as $app) {
        if (!$app['sso_returns']) continue;
        $up->execute([$app['id'], $app['name'], json_encode($app['sso_returns']), now_iso()]);
        $clients[$app['id']] = ['id' => $app['id'], 'name' => $app['name'], 'icon' => $app['icon'],
            'returns' => $app['sso_returns'], 'disabled' => isset($disabled[$app['id']])];
    }
    return $clients;
}

function sso_client(string $id): ?array
{
    return sso_sync_clients()[$id] ?? null;
}

/** כתובת חזרה: https בלבד, בלי #, ובלי פרטי משתמש. */
function sso_valid_return($url): bool
{
    if (!is_string($url) || strlen($url) > 500 || !preg_match('#^https://[^/?\#@\s]+(/[^\#\s]*)?$#i', $url)) return false;
    $p = parse_url($url);
    return is_array($p) && ($p['scheme'] ?? '') === 'https' && !empty($p['host']) && !isset($p['user']);
}

/** מוסיף פרמטרים לכתובת החזרה, גם אם כבר יש בה "?". */
function sso_redirect_url(string $return, array $params): string
{
    return $return . (str_contains($return, '?') ? '&' : '?') . http_build_query($params);
}

/** זוג המפתחות — נוצר בפעם הראשונה. */
function sso_keys(): array
{
    sso_migrate();
    $row = db()->query('SELECT public_key, secret_key FROM sso_keys WHERE id = 1')->fetch();
    if ($row) return $row;
    $pair = sodium_crypto_sign_keypair();
    db()->prepare('INSERT OR IGNORE INTO sso_keys (id, public_key, secret_key, created_at) VALUES (1, ?, ?, ?)')
        ->execute([sso_b64u(sodium_crypto_sign_publickey($pair)), sso_b64u(sodium_crypto_sign_secretkey($pair)), now_iso()]);
    return db()->query('SELECT public_key, secret_key FROM sso_keys WHERE id = 1')->fetch();
}

function sso_b64u(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function sso_b64u_decode(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/'), true);
}

/** הכתובת של הפלטפורמה — "המנפיק" שבתוך כל זהות. */
function sso_issuer(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . cookie_path();
}

/**
 * זהות חתומה לאפליקציה אחת: <payload>.<חתימה>, שניהם base64url. החתימה (Ed25519) על מחרוזת
 * ה-payload כמו שהיא. קשורה לאפליקציה (aud), ל-state שלה ולכתובת החזרה, ותקפה שתי דקות.
 * רק מזהה, שם ומייל — לא סיסמה, לא הרשאות ולא רשימת אפליקציות.
 */
function sso_issue_token(array $client, array $user, string $return, string $state): string
{
    $st = db()->prepare('SELECT username, email, email_verified_at FROM users WHERE id = ?');
    $st->execute([$user['id']]);
    $row = $st->fetch();
    // מייל שאומת בהרשמה (lib/email.php) — email_verified: true. משתמש ותיק בלי מייל: שם המשתמש,
    // אם הוא כתובת מייל — לא אומת בקישור; את הזהות אישר המנהל כשאישר את ההרשמה.
    $verified = !empty($row['email']) && !empty($row['email_verified_at']);
    $now = time();
    $payload = sso_b64u(json_encode([
        'iss'   => sso_issuer(),
        'aud'   => $client['id'],
        'sub'   => (string)$user['id'],
        'name'  => $user['display_name'],
        // המייל הוא מה שמאחד חשבונות (docs/sso.md, "איחוד לפי מייל").
        'email' => $verified ? $row['email'] : sso_email_of((string)($row['username'] ?? '')),
        'email_verified' => $verified,
        'state' => $state,
        'return' => $return,
        'iat'   => $now,
        'exp'   => $now + SSO_TOKEN_SECONDS,
        'jti'   => bin2hex(random_bytes(12)),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $sig = sodium_crypto_sign_detached($payload, sso_b64u_decode(sso_keys()['secret_key']));
    db()->prepare('UPDATE sso_consents SET last_used_at = ? WHERE user_id = ? AND client_id = ?')
        ->execute([now_iso(), $user['id'], $client['id']]);
    sso_log($client['id'], (int)$user['id'], 'token');
    return $payload . '.' . sso_b64u($sig);
}

function sso_has_consent(int $userId, string $clientId): bool
{
    sso_migrate();
    $st = db()->prepare('SELECT 1 FROM sso_consents WHERE user_id = ? AND client_id = ?');
    $st->execute([$userId, $clientId]);
    return (bool)$st->fetchColumn();
}

function sso_grant(int $userId, string $clientId): void
{
    db()->prepare('INSERT OR IGNORE INTO sso_consents (user_id, client_id, granted_at) VALUES (?,?,?)')
        ->execute([$userId, $clientId, now_iso()]);
    sso_log($clientId, $userId, 'consent');
}

function sso_revoke(int $userId, string $clientId, string $by): void
{
    sso_migrate();
    db()->prepare('DELETE FROM sso_consents WHERE user_id = ? AND client_id = ?')->execute([$userId, $clientId]);
    sso_log($clientId, $userId, "revoke:$by");
}

/** ההסכמות של משתמש — לתפריט האישי ולגיליון המשתמש אצל המנהל. */
function sso_consents_of(int $userId): array
{
    sso_migrate();
    $st = db()->prepare(
        'SELECT c.id AS client_id, c.name, s.granted_at, s.last_used_at
           FROM sso_consents s JOIN sso_clients c ON c.id = s.client_id
          WHERE s.user_id = ? ORDER BY c.name'
    );
    $st->execute([$userId]);
    return $st->fetchAll();
}

/** המייל של המשתמש — שם המשתמש, כשהוא כתובת מייל. אחרת null. */
function sso_email_of(string $username): ?string
{
    $email = filter_var(trim($username), FILTER_VALIDATE_EMAIL);
    return $email === false ? null : mb_strtolower($email);
}

// ---------- ממשק המנהל ----------

/** האפליקציות שמצהירות על כניסה מאוחדת, עם מספר המשתמשים שאישרו. */
function sso_clients_state(): array
{
    $out = [];
    $st = db()->prepare('SELECT COUNT(*) AS users, MAX(last_used_at) AS last_used FROM sso_consents WHERE client_id = ?');
    foreach (sso_sync_clients() as $c) {
        $st->execute([$c['id']]);
        $out[] = $c + array_map(fn($v) => $v, $st->fetch());
    }
    foreach ($out as &$c) $c['users'] = (int)$c['users'];
    return $out;
}

function sso_set_disabled(string $id, bool $disabled): void
{
    if (!sso_client($id)) throw new ApiError('האפליקציה לא מצהירה על כניסה מאוחדת', 404);
    db()->prepare('UPDATE sso_clients SET disabled = ? WHERE id = ?')->execute([$disabled ? 1 : 0, $id]);
    sso_log($id, null, $disabled ? 'disabled' : 'enabled');
}
