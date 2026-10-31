<?php
/**
 * חיבור אפליקציה לענן — לקוחות, חיבורים, חתימות ואסימונים.
 *
 * העיקרון: אין סוד שעובר ביד או נשמר בדפדפן לאורך זמן (כמו platform/docs/embed.md).
 * - לאפליקציה (לקוח) זוג מפתחות Ed25519 משלה. היא מפרסמת את הציבורי ב-key_url, והענן מוריד אותו בעצמו.
 * - המשתמש מאשר פעם אחת ב-connect.php → קוד חד-פעמי → connect_exchange (חתום) → connection.
 * - connection הוא מזהה, לא סוד: לבדו הוא לא פותח כלום — רק עם חתימת הלקוח שקיבל אותו.
 * - לדפדפן: access_token — חתום במפתח של הענן, 15 דקות, ונבדק מול החיבור בכל קריאה (ניתוק — מיידי).
 * האפיון: docs/spec-1-skeleton.md, סעיף 7.
 */

declare(strict_types=1);

require_once __DIR__ . '/account.php';

const SCOPES = ['files', 'libs', 'ai'];
const SCOPE_NAMES = ['files' => 'הקבצים שלך בענן', 'libs' => 'הספריות שלך (גופנים, צבעים, סגנונות)', 'ai' => 'בינה מלאכותית בשמך, במכסה שלך'];
const TOKEN_TTL = 900;
const CODE_TTL = 120;
const SIG_SKEW = 300;
const NONCE_TTL = 600;
const KEY_CACHE = 3600;

function migrateClients(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS clients (
            id          TEXT PRIMARY KEY,
            name        TEXT NOT NULL,
            key_url     TEXT NOT NULL,
            origins     TEXT NOT NULL,
            returns     TEXT NOT NULL,
            status      TEXT NOT NULL CHECK (status IN ('active','pending','disabled')),
            keys        TEXT,
            keys_at     INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT NOT NULL,
            updated_at  TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS connections (
            id          TEXT PRIMARY KEY,
            account     TEXT NOT NULL REFERENCES accounts(id),
            client      TEXT NOT NULL REFERENCES clients(id),
            scope       TEXT NOT NULL,
            created_at  TEXT NOT NULL,
            used_at     TEXT,
            revoked_at  TEXT
        );
        CREATE INDEX IF NOT EXISTS connections_acc ON connections(account, client);
        CREATE TABLE IF NOT EXISTS codes (
            code        TEXT PRIMARY KEY,
            connection  TEXT NOT NULL,
            client      TEXT NOT NULL,
            return_url  TEXT NOT NULL,
            expires     INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS nonces (
            client  TEXT NOT NULL,
            nonce   TEXT NOT NULL,
            at      INTEGER NOT NULL,
            PRIMARY KEY (client, nonce)
        );
    ");
}

// ─── הכתובת של הענן ───────────────────────────────────────────────────────

/** ה-origin של הענן (https://host). ניתן לדריסה לבדיקות. */
function cloudOrigin(): string {
    if (defined('CLOUD_ORIGIN')) return CLOUD_ORIGIN;
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return 'https://' . preg_replace('/[^a-z0-9.\-:]/', '', $host);
}

function cloudIssuer(): string {
    if (defined('CLOUD_ISSUER')) return CLOUD_ISSUER;
    return cloudOrigin() . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/suite-cloud/api.php')), '/') . '/';
}

function originOf(string $url): ?string {
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return null;
    return 'https://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
}

// ─── base64url ────────────────────────────────────────────────────────────

function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function unb64u(string $s): string|false { return base64_decode(strtr($s, '-_', '+/'), true); }

// ─── מפתח החתימה של הענן ──────────────────────────────────────────────────

/** {kid, sk, pk} — נוצר בשרת בפעם הראשונה (data/sign.key, 0600), ומוחרג בפריסה. */
function cloudSigningKey(): array {
    static $k = null;
    if ($k) return $k;
    $file = DATA_DIR . '/sign.key';
    if (is_file($file)) {
        $j = json_decode((string) file_get_contents($file), true);
        if (is_array($j) && isset($j['kid'], $j['sk'], $j['pk'])) {
            return $k = ['kid' => $j['kid'], 'sk' => base64_decode($j['sk']), 'pk' => base64_decode($j['pk'])];
        }
    }
    $pair = sodium_crypto_sign_keypair();
    $j = ['kid' => gmdate('Y-m'), 'sk' => base64_encode(sodium_crypto_sign_secretkey($pair)), 'pk' => base64_encode(sodium_crypto_sign_publickey($pair))];
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
    $tmp = $file . '.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($j));
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) { @unlink($tmp); }   // מישהו אחר יצר במקביל — קוראים את שלו
    return cloudSigningKey();
}

function cloudKeyPublic(): array {
    $k = cloudSigningKey();
    return ['alg' => 'Ed25519', 'kid' => $k['kid'], 'key' => b64u($k['pk']), 'issuer' => cloudIssuer()];
}

// ─── לקוחות ───────────────────────────────────────────────────────────────

function cleanClientId(mixed $id): string {
    if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9\-]{1,63}$/', $id)) throw new AppError('מזהה לקוח לא תקין', 400, 'bad_request');
    return $id;
}

function getClient(string $id): ?array {
    $st = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * מוריד את המפתחות הציבוריים של לקוח מ-key_url: {"client","keys":[{kid,alg,key}]}.
 * בבדיקות — $GLOBALS['CLOUD_FETCH'] (callable url → string).
 */
function fetchClientKeys(string $clientId, string $url): array {
    $fetch = $GLOBALS['CLOUD_FETCH'] ?? null;
    if (is_callable($fetch)) {
        $body = $fetch($url);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'follow_location' => 0, 'ignore_errors' => false,
                                                 'header' => "Accept: application/json\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
    }
    $j = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($j) || ($j['client'] ?? '') !== $clientId || !is_array($j['keys'] ?? null)) {
        throw new AppError('לא הצלחנו לקרוא את המפתח הציבורי מ-key_url', 400, 'bad_key_url');
    }
    $keys = [];
    foreach ($j['keys'] as $k) {
        if (($k['alg'] ?? '') !== 'Ed25519' || !is_string($k['kid'] ?? null) || !is_string($k['key'] ?? null)) continue;
        $raw = base64_decode(strtr($k['key'], '-_', '+/'), true);
        if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) $keys[$k['kid']] = base64_encode($raw);
    }
    if (!$keys) throw new AppError('ב-key_url אין מפתח Ed25519 תקין', 400, 'bad_key_url');
    return $keys;
}

function cleanUrlList(mixed $v, string $what, int $max = 10): array {
    if (!is_array($v) || !$v || count($v) > $max) throw new AppError("$what חסר", 400, 'bad_request');
    $out = [];
    foreach ($v as $u) {
        if (!is_string($u) || strlen($u) > 500 || originOf($u) === null) throw new AppError("$what: רק https מלא", 400, 'bad_request');
        $out[] = $u;
    }
    return array_values(array_unique($out));
}

/**
 * רישום לקוח (embed.md סעיף 1): המפתח לא נשלח — הענן מוריד אותו. אותו origin כמו הענן → active, אחר → pending.
 * פרטים שהשתנו → חזרה ל-pending. אף פעם לא דריסה שקטה.
 */
function registerClient(array $in): array {
    $id = cleanClientId($in['client'] ?? null);
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 60);
    if ($name === '') throw new AppError('חסר שם', 400, 'bad_request');
    $keyUrl = (string) ($in['key_url'] ?? '');
    $origins = array_map(fn($o) => originOf($o), cleanUrlList($in['origins'] ?? null, 'origins'));
    $returns = cleanUrlList($in['return'] ?? null, 'return');
    $keyOrigin = originOf($keyUrl);
    if ($keyOrigin === null || !in_array($keyOrigin, $origins, true)) throw new AppError('key_url חייב להיות https תחת אחד ה-origins', 400, 'bad_request');
    foreach ($returns as $r) if (!in_array(originOf($r), $origins, true)) throw new AppError('כתובת חזרה מחוץ ל-origins', 400, 'bad_request');

    $keys = fetchClientKeys($id, $keyUrl);
    $originsJ = json_encode(array_values(array_unique($origins)));
    $returnsJ = json_encode($returns);
    $now = nowIso();
    $old = getClient($id);
    $same = $keyOrigin === cloudOrigin();
    if ($old) {
        if ($old['key_url'] === $keyUrl && $old['origins'] === $originsJ && $old['returns'] === $returnsJ) {
            db()->prepare('UPDATE clients SET keys = ?, keys_at = ?, name = ? WHERE id = ?')->execute([json_encode($keys), time(), $name, $id]);
            return ['status' => $old['status']];
        }
        $status = $old['status'] === 'disabled' ? 'disabled' : ($same ? 'active' : 'pending');
        db()->prepare('UPDATE clients SET name = ?, key_url = ?, origins = ?, returns = ?, status = ?, keys = ?, keys_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$name, $keyUrl, $originsJ, $returnsJ, $status, json_encode($keys), time(), $now, $id]);
        return ['status' => $status];
    }
    $status = $same ? 'active' : 'pending';
    db()->prepare('INSERT INTO clients (id, name, key_url, origins, returns, status, keys, keys_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $name, $keyUrl, $originsJ, $returnsJ, $status, json_encode($keys), time(), $now, $now]);
    return ['status' => $status];
}

function activeClient(string $id): array {
    $c = getClient($id);
    if (!$c) throw new AppError('לקוח לא רשום', 401, 'unknown_client');
    if ($c['status'] === 'pending') throw new AppError('הלקוח ממתין לאישור מנהל הענן', 401, 'pending');
    if ($c['status'] !== 'active') throw new AppError('הלקוח כבוי', 401, 'unknown_client');
    return $c;
}

// ─── חתימה שרת-לשרת ───────────────────────────────────────────────────────

/**
 * בודק קריאה חתומה של לקוח. $h — הכותרות (X-Cloud-*), $body — הגוף בדיוק כפי שנשלח.
 * המחרוזת: "<action>\n<time>\n<nonce>\n<sha256 hex של הגוף>". מחזיר את הלקוח.
 */
function verifySigned(string $action, array $h, string $body): array {
    $c = activeClient(cleanClientId($h['client'] ?? null));
    $time = (string) ($h['time'] ?? '');
    $nonce = (string) ($h['nonce'] ?? '');
    if (!ctype_digit($time) || abs(time() - (int) $time) > SIG_SKEW) throw new AppError('השעון לא מסונכרן או בקשה ישנה', 401, 'stale');
    if (!preg_match('/^[0-9a-f]{16,64}$/', $nonce)) throw new AppError('nonce לא תקין', 401, 'bad_signature');
    [$kid, $sigB64] = array_pad(explode('.', (string) ($h['signature'] ?? ''), 2), 2, '');
    $sig = base64_decode(strtr($sigB64, '-_', '+/'), true);
    if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) throw new AppError('חתימה לא תקינה', 401, 'bad_signature');
    $msg = "$action\n$time\n$nonce\n" . hash('sha256', $body);

    $keys = json_decode((string) $c['keys'], true) ?: [];
    $ok = isset($keys[$kid]) && sodium_crypto_sign_verify_detached($sig, $msg, base64_decode($keys[$kid]));
    if (!$ok && (int) $c['keys_at'] < time() - 60) {
        // החלפת מפתח אצל הלקוח — הורדה אחת מחדש, ואז דחייה (embed.md)
        try {
            $keys = fetchClientKeys($c['id'], $c['key_url']);
            db()->prepare('UPDATE clients SET keys = ?, keys_at = ? WHERE id = ?')->execute([json_encode($keys), time(), $c['id']]);
            $ok = isset($keys[$kid]) && sodium_crypto_sign_verify_detached($sig, $msg, base64_decode($keys[$kid]));
        } catch (AppError) { $ok = false; }
    }
    if (!$ok) throw new AppError('חתימה לא תקינה', 401, 'bad_signature');

    $pdo = db();
    $pdo->prepare('DELETE FROM nonces WHERE at < ?')->execute([time() - NONCE_TTL]);
    $st = $pdo->prepare('INSERT OR IGNORE INTO nonces (client, nonce, at) VALUES (?,?,?)');
    $st->execute([$c['id'], $nonce, time()]);
    if ($st->rowCount() === 0) throw new AppError('בקשה חוזרת', 401, 'replay');
    return $c;
}

/** הכותרות X-Cloud-* מהבקשה. */
function signedHeaders(): array {
    return [
        'client' => $_SERVER['HTTP_X_CLOUD_CLIENT'] ?? null, 'time' => $_SERVER['HTTP_X_CLOUD_TIME'] ?? null,
        'nonce' => $_SERVER['HTTP_X_CLOUD_NONCE'] ?? null, 'signature' => $_SERVER['HTTP_X_CLOUD_SIGNATURE'] ?? null,
        'connection' => $_SERVER['HTTP_X_CLOUD_CONNECTION'] ?? null,
    ];
}

// ─── חיבורים ──────────────────────────────────────────────────────────────

function cleanScope(mixed $s): array {
    $list = is_array($s) ? $s : preg_split('/[\s,]+/', (string) $s, -1, PREG_SPLIT_NO_EMPTY);
    $list = array_values(array_unique(array_map('strval', $list)));
    if (!$list || array_diff($list, SCOPES)) throw new AppError('הרשאה לא מוכרת', 400, 'bad_request');
    sort($list);
    return $list;
}

/** חיבור פעיל של חשבון ללקוח (אחד לכל זוג). */
function liveConnection(string $account, string $client): ?array {
    $st = db()->prepare('SELECT * FROM connections WHERE account = ? AND client = ? AND revoked_at IS NULL ORDER BY created_at DESC LIMIT 1');
    $st->execute([$account, $client]);
    return $st->fetch() ?: null;
}

/** בודק את בקשת connect.php: לקוח פעיל, כתובת חזרה רשומה, state, הרשאות. */
function connectRequest(array $q): array {
    $c = activeClient(cleanClientId($q['client'] ?? null));
    $return = (string) ($q['return'] ?? '');
    if (!in_array($return, json_decode($c['returns'], true) ?: [], true)) throw new AppError('כתובת החזרה לא רשומה ללקוח', 400, 'bad_request');
    $state = (string) ($q['state'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_\-.~]{8,200}$/', $state)) throw new AppError('state לא תקין', 400, 'bad_request');
    return ['client' => $c, 'return' => $return, 'state' => $state, 'scope' => cleanScope($q['scope'] ?? 'files')];
}

/** האם כבר אושר — חיבור פעיל שמכסה את ההרשאות שמבוקשות. */
function alreadyGranted(array $a, array $req): ?array {
    $conn = liveConnection($a['id'], $req['client']['id']);
    if ($conn && !array_diff($req['scope'], json_decode($conn['scope'], true) ?: [])) return $conn;
    return null;
}

/** אישור: חיבור (חדש, או הרחבת הקיים) + קוד חד-פעמי. מחזיר את כתובת החזרה. */
function approveConnect(array $a, array $req): string {
    $pdo = db();
    $conn = liveConnection($a['id'], $req['client']['id']);
    if ($conn) {
        $scope = array_values(array_unique(array_merge(json_decode($conn['scope'], true) ?: [], $req['scope'])));
        sort($scope);
        $pdo->prepare('UPDATE connections SET scope = ? WHERE id = ?')->execute([json_encode($scope), $conn['id']]);
        $cid = $conn['id'];
    } else {
        $cid = randomId('c_');
        $pdo->prepare('INSERT INTO connections (id, account, client, scope, created_at) VALUES (?,?,?,?,?)')
            ->execute([$cid, $a['id'], $req['client']['id'], json_encode($req['scope']), nowIso()]);
    }
    $code = b64u(random_bytes(24));
    $pdo->prepare('DELETE FROM codes WHERE expires < ?')->execute([time()]);
    $pdo->prepare('INSERT INTO codes (code, connection, client, return_url, expires) VALUES (?,?,?,?,?)')
        ->execute([$code, $cid, $req['client']['id'], $req['return'], time() + CODE_TTL]);
    return withQuery($req['return'], ['code' => $code, 'state' => $req['state']]);
}

function withQuery(string $url, array $q): string {
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($q);
}

/** connect_exchange (חתום): קוד → חיבור. הקוד חד-פעמי, של הלקוח הזה בלבד, 2 דקות. */
function exchangeCode(array $client, array $in): array {
    $code = (string) ($in['code'] ?? '');
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM codes WHERE code = ?');
    $st->execute([$code]);
    $row = $st->fetch();
    $pdo->prepare('DELETE FROM codes WHERE code = ?')->execute([$code]);
    if (!$row || $row['client'] !== $client['id'] || (int) $row['expires'] < time()) throw new AppError('קוד לא תקף', 400, 'bad_code');
    $conn = connectionRow((string) $row['connection'], $client['id']);
    $a = getAccount($conn['account']);
    return ['connection' => $conn['id'], 'account' => ['name' => $a['name']], 'scope' => json_decode($conn['scope'], true), 'cloud_id' => setting('cloud_id')];
}

function connectionRow(string $id, string $client): array {
    $st = db()->prepare('SELECT * FROM connections WHERE id = ? AND client = ?');
    $st->execute([$id, $client]);
    $c = $st->fetch();
    if (!$c || $c['revoked_at'] !== null) throw new AppError('החיבור נותק', 401, 'disconnected');
    return $c;
}

function revokeConnection(string $id): void {
    db()->prepare('UPDATE connections SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL')->execute([nowIso(), $id]);
}

/** החיבורים של חשבון — לדף "🔗 חיבורים". */
function accountConnections(array $a): array {
    $st = db()->prepare('SELECT c.*, k.name AS client_name FROM connections c JOIN clients k ON k.id = c.client
                         WHERE c.account = ? AND c.revoked_at IS NULL ORDER BY c.created_at DESC');
    $st->execute([$a['id']]);
    return array_map(fn($r) => ['id' => $r['id'], 'client' => $r['client'], 'name' => $r['client_name'],
        'scope' => json_decode($r['scope'], true), 'created_at' => $r['created_at'], 'used_at' => $r['used_at']], $st->fetchAll());
}

function revokeOwnConnection(array $a, mixed $id): void {
    $st = db()->prepare('SELECT id FROM connections WHERE id = ? AND account = ?');
    $st->execute([is_string($id) ? $id : '', $a['id']]);
    if (!$st->fetchColumn()) throw new AppError('לא נמצא', 404, 'not_found');
    revokeConnection((string) $id);
}

// ─── אסימון לדפדפן ────────────────────────────────────────────────────────

function issueToken(array $client, array $conn): array {
    $now = time();
    $payload = json_encode(['iss' => cloudIssuer(), 'aud' => $client['id'], 'conn' => $conn['id'], 'acc' => $conn['account'],
        'scope' => json_decode($conn['scope'], true), 'iat' => $now, 'exp' => $now + TOKEN_TTL, 'jti' => b64u(random_bytes(12))],
        JSON_UNESCAPED_SLASHES);
    $p = b64u($payload);
    $k = cloudSigningKey();
    return ['token' => $p . '.' . b64u(sodium_crypto_sign_detached($p, $k['sk'])), 'expires_in' => TOKEN_TTL];
}

/** בודק אסימון: חתימה, תוקף, והחיבור עדיין פעיל. מחזיר [חיבור, לקוח]. */
function verifyToken(string $token): array {
    [$p, $s] = array_pad(explode('.', $token, 2), 2, '');
    $sig = unb64u($s);
    $k = cloudSigningKey();
    if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($sig, $p, $k['pk'])) {
        throw new AppError('אסימון לא תקין', 401, 'bad_token');
    }
    $j = json_decode((string) unb64u($p), true);
    if (!is_array($j) || ($j['exp'] ?? 0) < time()) throw new AppError('האסימון פג', 401, 'expired');
    $client = activeClient((string) ($j['aud'] ?? ''));
    $conn = connectionRow((string) ($j['conn'] ?? ''), $client['id']);
    return [$conn, $client];
}

// ─── מי קורא: הדף של הענן, אסימון, או חתימה ───────────────────────────────

/**
 * הגורם שמבצע את הפעולה: ['account' => …, 'app' => '' | <client id>, 'client' => ?array].
 * Bearer → אסימון (ורק הוא). X-Cloud-Signature → חתימה + X-Cloud-Connection. אחרת — הדף של הענן (השער).
 * $scope — ההרשאה שהפעולה דורשת מאפליקציה מחוברת.
 */
function actor(string $action, string $scope, string $body): array {
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
        [$conn, $client] = verifyToken($m[1]);
        return actorFrom($conn, $client, $scope);
    }
    if (!empty($_SERVER['HTTP_X_CLOUD_SIGNATURE'])) {
        $h = signedHeaders();
        $client = verifySigned($action, $h, $body);
        return actorFrom(connectionRow((string) ($h['connection'] ?? ''), $client['id']), $client, $scope);
    }
    return ['account' => requireAccount(), 'app' => '', 'client' => null];
}

function actorFrom(array $conn, array $client, string $scope): array {
    if (!in_array($scope, json_decode($conn['scope'], true) ?: [], true)) throw new AppError('אין לאפליקציה הרשאה לזה', 403, 'forbidden');
    $a = getAccount($conn['account']);
    if ((int) $a['blocked'] === 1) throw new AppError('החשבון חסום', 403, 'forbidden');
    if ($conn['used_at'] === null || $conn['used_at'] < nowIso(time() - 600)) {
        db()->prepare('UPDATE connections SET used_at = ? WHERE id = ?')->execute([nowIso(), $conn['id']]);
    }
    return ['account' => $a, 'app' => $client['id'], 'client' => $client];
}

/** CORS לבקשה עם אסימון: רק ל-origins של לקוח פעיל, ובלי עוגיות (Allow-Credentials לא נשלח לעולם). */
function corsHeaders(): void {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || $origin === cloudOrigin()) return;
    $st = db()->query("SELECT origins FROM clients WHERE status = 'active'");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $o) {
        if (in_array($origin, json_decode($o, true) ?: [], true)) {
            header("Access-Control-Allow-Origin: $origin");
            header('Vary: Origin');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, Range');
            header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
            header('Access-Control-Expose-Headers: ETag, Content-Range, Content-Length');
            header('Access-Control-Max-Age: 600');
            return;
        }
    }
}

// ─── ניהול ────────────────────────────────────────────────────────────────

function adminClients(): array {
    $rows = db()->query("SELECT k.*, (SELECT COUNT(*) FROM connections c WHERE c.client = k.id AND c.revoked_at IS NULL) AS users
                         FROM clients k ORDER BY k.status = 'pending' DESC, k.name")->fetchAll();
    return array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'status' => $r['status'], 'origins' => json_decode($r['origins'], true),
        'key_url' => $r['key_url'], 'users' => (int) $r['users'], 'created_at' => $r['created_at']], $rows);
}

function adminClient(array $in): array {
    $c = getClient(cleanClientId($in['client'] ?? null));
    if (!$c) throw new AppError('לא נמצא', 404, 'not_found');
    $status = $in['status'] ?? null;
    if (!in_array($status, ['active', 'disabled'], true)) throw new AppError('מצב לא מוכר', 400, 'bad_request');
    db()->prepare('UPDATE clients SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, nowIso(), $c['id']]);
    return adminClients();
}
