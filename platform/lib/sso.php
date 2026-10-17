<?php
/**
 * כניסה מאוחדת ("כניסה עם חשבון mbe-plus") — הצד של הספק.
 *
 * חשבון הפלטפורמה הוא **זהות**; לכל אפליקציה חשבון משלה, מקושר לזהות. הפלטפורמה רק מאשרת
 * "זה משתמש X" — לא משתפת משתמשים, מסד, עוגייה או הרשאות (CLAUDE.md: "אפליקציה פנימית
 * עצמאית מהמעטפת"). הדרישות: guarded-browser/docs/sso-request.md. המדריך לאפליקציות:
 * platform/docs/sso.md.
 *
 *   אפליקציה ── redirect ──▶ platform/sso.php?client=…&state=…&return=…
 *                            (לא מחובר → כניסה רגילה וחזרה; פעם ראשונה → מסך אישור)
 *   אפליקציה ◀── redirect ── return?code=…&state=…
 *   אפליקציה ── POST sso.php?action=token {client, client_secret, code, return} ──▶ {sub, name, …}
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

const SSO_CODE_SECONDS = 60;
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

function sso_client(string $id): ?array
{
    sso_migrate();
    $st = db()->prepare('SELECT * FROM sso_clients WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) return null;
    $c['returns'] = json_decode($c['returns'], true) ?: [];
    return $c;
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

/** מנפיק code חד-פעמי, ומעדכן את "בשימוש לאחרונה". */
function sso_issue_code(array $client, array $user, string $return): string
{
    $code = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM sso_codes WHERE expires_at <= ?')->execute([now_iso()]);
    db()->prepare('INSERT INTO sso_codes (code_hash, client_id, user_id, return_url, expires_at) VALUES (?,?,?,?,?)')
        ->execute([hash('sha256', $code), $client['id'], $user['id'], $return, gmdate('Y-m-d\TH:i:s\Z', time() + SSO_CODE_SECONDS)]);
    db()->prepare('UPDATE sso_consents SET last_used_at = ? WHERE user_id = ? AND client_id = ?')
        ->execute([now_iso(), $user['id'], $client['id']]);
    sso_log($client['id'], (int)$user['id'], 'code');
    return $code;
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
    db()->prepare('DELETE FROM sso_codes WHERE user_id = ? AND client_id = ?')->execute([$userId, $clientId]);
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

/**
 * החלפת code בזהות — בקשת שרת-לשרת מהאפליקציה.
 * @return array{sub: string, name: string, iat: int, exp: int}
 */
function sso_exchange(array $body): array
{
    sso_migrate();
    $keys = ['sso:' . client_ip()];
    if (lock_remaining($keys) > 0) throw new ApiError('יותר מדי ניסיונות', 429);

    $clientId = is_string($body['client'] ?? null) ? $body['client'] : '';
    $secret   = is_string($body['client_secret'] ?? null) ? $body['client_secret'] : '';
    $code     = is_string($body['code'] ?? null) ? $body['code'] : '';
    $return   = is_string($body['return'] ?? null) ? $body['return'] : '';

    $client = sso_client($clientId);
    // גם ללקוח שאינו קיים מריצים password_verify — שזמן התגובה לא יסגיר אילו לקוחות קיימים.
    $okSecret = password_verify($secret, $client['secret_hash'] ?? '$2y$10$rxAGOCUktx7c3KoNsrn5quXX6u4.mqw4TkeXZk1yylgcrXjz.UZFa');
    if (!$client || !$okSecret || $client['disabled']) {
        record_failure($keys);
        sso_log($clientId, null, 'token:bad-client');
        throw new ApiError('לקוח או סוד שגויים', 401);
    }

    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    $st = $pdo->prepare('SELECT * FROM sso_codes WHERE code_hash = ?');
    $st->execute([hash('sha256', $code)]);
    $row = $st->fetch();
    // code חד-פעמי: נמחק כבר עכשיו, גם אם משהו אחר בבקשה שגוי.
    if ($row) $pdo->prepare('DELETE FROM sso_codes WHERE code_hash = ?')->execute([$row['code_hash']]);
    $pdo->exec('COMMIT');

    if (!$row || $row['client_id'] !== $client['id'] || !hash_equals($row['return_url'], $return)
        || $row['expires_at'] <= now_iso()) {
        record_failure($keys);
        sso_log($client['id'], $row ? (int)$row['user_id'] : null, 'token:bad-code');
        throw new ApiError('קוד לא תקין, פג או כבר נוצל', 400);
    }

    $st = db()->prepare('SELECT id, display_name, status FROM users WHERE id = ?');
    $st->execute([$row['user_id']]);
    $user = $st->fetch();
    if (!$user || $user['status'] !== 'active' || !sso_has_consent((int)$user['id'], $client['id'])) {
        sso_log($client['id'], (int)$row['user_id'], 'token:user-inactive');
        throw new ApiError('המשתמש אינו פעיל או ביטל את החיבור', 403);
    }

    clear_failures($keys);
    sso_log($client['id'], (int)$user['id'], 'token');
    $now = time();
    // רק מזהה ושם — לא סיסמה, לא הרשאות ולא רשימת אפליקציות.
    return ['sub' => (string)$user['id'], 'name' => $user['display_name'], 'iat' => $now, 'exp' => $now + 300];
}

// ---------- ניהול הלקוחות (ממשק המנהל) ----------

function sso_clients_state(): array
{
    sso_migrate();
    $rows = db()->query(
        'SELECT c.id, c.name, c.returns, c.disabled, c.created_at,
                (SELECT COUNT(*) FROM sso_consents s WHERE s.client_id = c.id) AS users,
                (SELECT MAX(last_used_at) FROM sso_consents s WHERE s.client_id = c.id) AS last_used
           FROM sso_clients c ORDER BY c.name'
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['returns'] = json_decode($r['returns'], true) ?: [];
        $r['disabled'] = (bool)$r['disabled'];
        $r['users'] = (int)$r['users'];
    }
    return $rows;
}

function sso_new_secret(): string
{
    return 'mps_' . bin2hex(random_bytes(24));
}

/** {id, name, returns: [url…]} — יוצר (ומחזיר סוד חדש, פעם אחת) או מעדכן. */
function sso_client_save(array $body): ?string
{
    sso_migrate();
    $id = is_string($body['id'] ?? null) ? strtolower(trim($body['id'])) : '';
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $id)) throw new ApiError('מזהה: 2–40 תווים — אותיות קטנות באנגלית, ספרות ומקף', 422);
    $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
    if ($name === '' || mb_strlen($name) > 60) throw new ApiError('שם: 1–60 תווים', 422);
    $returns = array_values(array_unique(array_filter(array_map(
        fn($u) => is_string($u) ? trim($u) : '', (array)($body['returns'] ?? [])
    ))));
    if (!$returns || count($returns) > SSO_MAX_RETURNS) throw new ApiError('צריך כתובת חזרה אחת לפחות (עד ' . SSO_MAX_RETURNS . ')', 422);
    foreach ($returns as $u) if (!sso_valid_return($u)) throw new ApiError("כתובת חזרה לא תקינה: $u — https מלאה, בלי #", 422);

    if (sso_client($id)) {
        db()->prepare('UPDATE sso_clients SET name = ?, returns = ? WHERE id = ?')->execute([$name, json_encode($returns), $id]);
        return null;
    }
    $secret = sso_new_secret();
    db()->prepare('INSERT INTO sso_clients (id, name, secret_hash, returns, created_at) VALUES (?,?,?,?,?)')
        ->execute([$id, $name, password_hash($secret, PASSWORD_DEFAULT), json_encode($returns), now_iso()]);
    return $secret;
}

function sso_client_rotate(string $id): string
{
    if (!sso_client($id)) throw new ApiError('הלקוח לא נמצא', 404);
    $secret = sso_new_secret();
    db()->prepare('UPDATE sso_clients SET secret_hash = ? WHERE id = ?')->execute([password_hash($secret, PASSWORD_DEFAULT), $id]);
    sso_log($id, null, 'secret-rotated');
    return $secret;
}
