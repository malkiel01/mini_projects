<?php
declare(strict_types=1);

/**
 * פרוסה 09 — שרת MCP מרוחק (mcp.php) ושרת ההרשאה OAuth 2.1 (oauth/*.php), בתוך התיקייה.
 *
 * נשען על 05: הכלים = extActions(), הביצוע = extDo() (אותן הרשאות, אותו "לאימות", אותו יומן), extMember() ו-extSetContext().
 * על 01: users / members / events / req_log. האסימונים נשמרים רק כ-SHA-256.
 *
 * הקובץ אינו נקודת קצה: גישה ישירה אליו מחזירה 404. נטען אחרי lib.php ו-ext-lib.php.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

const MCP_BASE = EXT_PUBLIC_BASE;                       // ה-issuer
const MCP_URL = MCP_BASE . '/mcp';                      // ה-resource
const MCP_PRM_URL = MCP_BASE . '/.well-known/oauth-protected-resource';
const MCP_PROTOCOLS = ['2025-11-25', '2025-06-18'];     // החדשה ראשונה
const MCP_ORIGINS = ['https://chatgpt.com', 'https://claude.ai', 'https://claude.com', 'https://mbe-plus.com'];
const MCP_SCOPE = 'cases';
const OAUTH_CODE_TTL = 60;
const OAUTH_ACCESS_TTL = 3600;
const OAUTH_REFRESH_TTL = 30 * 86400;
const OAUTH_REG_PER_HOUR = 20;
/** תקרה כוללת לרישומי DCR בשעה (מכל הכתובות) — מול הצפה מכתובות רבות. */
const OAUTH_REG_TOTAL_PER_HOUR = 200;
/** בקשות בתוך batch אחד של JSON-RPC. */
const MCP_BATCH_MAX = 20;
const OAUTH_CIMD_TTL = 3600;
const OAUTH_CIMD_MAX = 65536;

/** ההנחיות לצ'אט — ≤ 512 תווים (ChatGPT קורא רק אותם). הגרסה המלאה — הכלי schema וה-extPrompt() של 05. */
const MCP_INSTRUCTIONS = 'ניהול תיקי לוויות של חברה קדישא. כל תיק הוא מסלול בדיקות: מה חסר, מה לאימות ומה הושלם. '
    . 'תמיד find לפני עדכון; יותר מתיק אחד — לשאול את המשתמש איזה, לא לבחור לבד. לא לנחש: לכתוב רק מה שהמשתמש אמר או מה שבמסמך. '
    . 'לפני כתיבה ראשונה — schema. "מאומת" נשמר "לאימות": רק אדם במשרד מאשר סופית, וזה תקין. הערה חופשית — case_update עם note. '
    . 'אחרי עדכון — לסכם מתוך התשובה (saved, converted). אין מחיקה. לענות בעברית, קצר.';

// ───────────────────────── מסד ─────────────────────────

/** הטבלאות של 09 — לפי שם ואידמפוטנטיות (CREATE IF NOT EXISTS), כמו extEnsureSchema של 05. אין מחיקה של נתוני משתמשים. */
function oauthEnsureSchema(PDO $pdo): void {
    static $ok = false;
    if ($ok) return;
    extEnsureSchema($pdo);
    $pdo->exec('CREATE TABLE IF NOT EXISTS oauth_clients (client_id TEXT PRIMARY KEY, kind TEXT NOT NULL, client_name TEXT NOT NULL,
        redirect_uris TEXT NOT NULL, created_at TEXT NOT NULL, fetched_at TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS oauth_codes (hash TEXT PRIMARY KEY, client_id TEXT NOT NULL, client_name TEXT NOT NULL,
        redirect_uri TEXT NOT NULL, user_id INTEGER NOT NULL, org_id INTEGER NOT NULL, challenge TEXT NOT NULL, resource TEXT NOT NULL,
        expires_at INTEGER NOT NULL, used_at TEXT, grant_id INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS oauth_grants (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, org_id INTEGER NOT NULL,
        client_id TEXT NOT NULL, client_name TEXT NOT NULL, created_at TEXT NOT NULL, last_used_at TEXT, revoked_at TEXT, revoked_by INTEGER,
        revoked_reason TEXT, rl_min INTEGER NOT NULL DEFAULT 0, rl_n INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_oauth_grants_org ON oauth_grants(org_id, user_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS oauth_tokens (hash TEXT PRIMARY KEY, kind TEXT NOT NULL, grant_id INTEGER NOT NULL,
        expires_at INTEGER NOT NULL, used_at TEXT)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_oauth_tokens_grant ON oauth_tokens(grant_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS oauth_reg (ip TEXT PRIMARY KEY, hour INTEGER NOT NULL, n INTEGER NOT NULL)');
    $ok = true;
}

/** שורה אחת, והסמן נסגר מיד — סמן פתוח מחזיק נעילת קריאה, והכתיבה שאחריו נכשלת ב-"database is locked" (ראו resolveAccess). */
function oauthRow(PDO $pdo, string $sql, array $args): ?array {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    return $r ?: null;
}
function oauthHash(string $raw): string { return hash('sha256', $raw); }
function oauthIpKey(string $tag): string { return hash('sha256', $tag . '|' . (string)($_SERVER['REMOTE_ADDR'] ?? '')); }
/** מפתח הספירה של DCR: IPv4 — הכתובת; IPv6 — ה-/64 (לקוח אחד מחזיק /64 שלם). */
function oauthRegKey(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $bin = @inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16) $ip = bin2hex(substr($bin, 0, 8)) . '::/64';
    return hash('sha256', 'oauth-reg|' . $ip);
}
/**
 * טרנזקציית כתיבה שתופסת את נעילת הכתיבה מיד (BEGIN IMMEDIATE) — כך בקשה מקבילה ממתינה (busy_timeout) במקום לקבל
 * BUSY באמצע (שדרוג נעילה מקריאה לכתיבה לא ממתין ב-SQLite). PDO לא יודע עליה, ולכן נסגרת תמיד דרך oauthCommit/oauthRollback.
 */
function oauthBegin(PDO $pdo): void { $pdo->exec('BEGIN IMMEDIATE'); }
function oauthCommit(PDO $pdo): void { $pdo->exec('COMMIT'); }
function oauthRollback(PDO $pdo): void { try { $pdo->exec('ROLLBACK'); } catch (Throwable) {} }
/** שם לקוח להצגה: בלי תווי בקרה ותווי עיצוב (bidi, zero-width), עד 80 תווים. ריק → ''. */
function oauthName(mixed $v): string {
    if (!is_string($v)) return '';
    $v = (string)preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', '', $v);
    $v = trim((string)preg_replace('/\s+/u', ' ', $v));
    return mb_substr($v, 0, 80);
}

// ───────────────────────── תשובות ─────────────────────────

function oauthJson(int $status, array $data, array $headers = []): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($headers as $h) header($h);
    if ($status >= 400) $GLOBALS['fd_err'] = (string)($data['error_description'] ?? $data['error'] ?? '');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
/** שגיאת OAuth (RFC 6749 §5.2) — בלי שום אסימון או קוד בגוף. */
function oauthError(int $status, string $error, string $desc): never {
    oauthJson($status, ['error' => $error, 'error_description' => $desc]);
}
/** CORS לנקודות הקצה הציבוריות של OAuth (בלי עוגיות) — MCP Inspector עושה את הגילוי וההחלפה מהדפדפן. */
function oauthCors(string $methods): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: ' . $methods . ', OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}
/** גוף טופס (application/x-www-form-urlencoded) או JSON. */
function oauthParams(): array {
    if (str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
        $j = json_decode((string)file_get_contents('php://input', false, null, 0, 65536), true);
        return is_array($j) ? $j : [];
    }
    return $_POST;
}
function oauthStr(array $p, string $k, int $max = 2048): string {
    $v = $p[$k] ?? '';
    return is_string($v) && strlen($v) <= $max ? $v : '';
}

// ───────────────────────── מטא-נתונים ─────────────────────────

/** RFC 9728 */
function oauthProtectedResource(): array {
    return ['resource' => MCP_URL, 'authorization_servers' => [MCP_BASE], 'scopes_supported' => [MCP_SCOPE],
        'bearer_methods_supported' => ['header'], 'resource_name' => 'ניהול לוויות חברה קדישא'];
}
/** RFC 8414 (וגם openid-configuration — אותו מסמך; אין OIDC, אין id_token). */
function oauthServerMetadata(): array {
    return ['issuer' => MCP_BASE,
        'authorization_endpoint' => MCP_BASE . '/oauth/authorize.php',
        'token_endpoint' => MCP_BASE . '/oauth/token.php',
        'registration_endpoint' => MCP_BASE . '/oauth/register.php',
        'revocation_endpoint' => MCP_BASE . '/oauth/revoke.php',
        'response_types_supported' => ['code'],
        'response_modes_supported' => ['query'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none'],
        'revocation_endpoint_auth_methods_supported' => ['none'],
        'scopes_supported' => [MCP_SCOPE],
        'client_id_metadata_document_supported' => true,
        'authorization_response_iss_parameter_supported' => true];
}
/** resource שהלקוח שלח — רק כתובת ה-MCP (בלי / בסוף, mcp או mcp.php). ריק = לא נשלח. */
function oauthResourceOk(string $r): bool {
    return $r === '' || in_array(rtrim($r, '/'), [MCP_URL, MCP_BASE . '/mcp.php'], true);
}

// ───────────────────────── לקוחות ─────────────────────────

/**
 * כתובת חזרה: https עם host, בלי fragment ובלי userinfo. גם loopback ב-http (RFC 8252 §7.3) — הדפדפן חוזר למחשב של
 * המשתמש עצמו; בלי זה MCP Inspector (http://localhost:6274) לא יכול להירשם (K16).
 */
function oauthRedirectOk(mixed $u): bool {
    if (!is_string($u) || strlen($u) > 500 || preg_match('/[\s\x00-\x1f]/', $u)) return false;
    $p = parse_url($u);
    if (!$p || empty($p['host']) || isset($p['fragment']) || isset($p['user']) || isset($p['pass'])) return false;
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    if ($scheme === 'https') return true;
    return $scheme === 'http' && in_array(strtolower($p['host']), ['localhost', '127.0.0.1', '[::1]'], true);
}
/** DCR (RFC 7591): לקוח ציבורי בלבד. מחזיר את מסמך הרישום. */
function oauthRegister(PDO $pdo, array $p): array {
    $hour = intdiv(time(), 3600);
    $ip = oauthRegKey();
    foreach ([[$ip, OAUTH_REG_PER_HOUR, 'מהכתובת הזו'], ['*', OAUTH_REG_TOTAL_PER_HOUR, 'בשעה האחרונה']] as [$key, $max, $what]) {
        $r = oauthRow($pdo, 'SELECT hour, n FROM oauth_reg WHERE ip=?', [$key]);
        if ($r && (int)$r['hour'] === $hour && (int)$r['n'] >= $max) {
            oauthJson(429, ['error' => 'invalid_client_metadata', 'error_description' => 'יותר מדי רישומים ' . $what . '. לנסות בעוד שעה.'], ['Retry-After: ' . (3600 - time() % 3600)]);
        }
    }
    $uris = $p['redirect_uris'] ?? null;
    if (!is_array($uris) || !$uris || count($uris) > 10 || !array_is_list($uris)) oauthError(400, 'invalid_redirect_uri', 'redirect_uris — רשימה של 1–10 כתובות');
    foreach ($uris as $u) if (!oauthRedirectOk($u)) oauthError(400, 'invalid_redirect_uri', 'כתובת חזרה חייבת להיות https (או loopback)');
    $auth = $p['token_endpoint_auth_method'] ?? 'none';
    if ($auth !== 'none') oauthError(400, 'invalid_client_metadata', 'רק לקוח ציבורי: token_endpoint_auth_method = none');
    foreach ((array)($p['grant_types'] ?? []) as $g) {
        if (!in_array($g, ['authorization_code', 'refresh_token'], true)) oauthError(400, 'invalid_client_metadata', 'grant_types: authorization_code, refresh_token');
    }
    $name = oauthName($p['client_name'] ?? '');
    if ($name === '') $name = (string)parse_url($uris[0], PHP_URL_HOST);
    $id = 'fdc_' . bin2hex(random_bytes(16));
    // לקוחות DCR שלא הגיעו ל-grant תוך 24 שעות — נמחקים (רישום בלי שימוש; אין בהם נתוני משתמש)
    $pdo->prepare("DELETE FROM oauth_clients WHERE kind='dcr' AND created_at < ? AND NOT EXISTS (SELECT 1 FROM oauth_grants g WHERE g.client_id=oauth_clients.client_id)")
        ->execute([gmdate('c', time() - 86400)]);
    $now = gmdate('c');
    $pdo->prepare('INSERT INTO oauth_clients(client_id, kind, client_name, redirect_uris, created_at) VALUES(?,?,?,?,?)')
        ->execute([$id, 'dcr', $name, json_encode(array_values($uris), JSON_UNESCAPED_SLASHES), $now]);
    $cnt = $pdo->prepare('INSERT INTO oauth_reg(ip, hour, n) VALUES(?,?,1) ON CONFLICT(ip) DO UPDATE SET n = CASE WHEN hour=excluded.hour THEN n+1 ELSE 1 END, hour=excluded.hour');
    $cnt->execute([$ip, $hour]);
    $cnt->execute(['*', $hour]);
    return ['client_id' => $id, 'client_id_issued_at' => time(), 'client_name' => $name, 'redirect_uris' => array_values($uris),
        'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'token_endpoint_auth_method' => 'none'];
}

/** כתובת IP ציבורית בלבד (לא פרטית, לא שמורה, לא loopback / link-local). */
function oauthPublicIp(string $ip): bool {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
    $bin = (string)inet_pton($ip);
    if (strlen($bin) === 4) {
        $n = unpack('N', $bin)[1];
        return ($n & 0xFFC00000) !== 0x64400000;   // 100.64.0.0/10 (CGNAT)
    }
    // IPv6 שמכיל IPv4 — ::ffff:0:0/96 (mapped), ::/96 (compat), 64:ff9b::/96 ו-64:ff9b:1::/48 (NAT64), 2002::/16 (6to4): נדחים
    if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($bin, str_repeat("\0", 12))) return false;
    if (str_starts_with($bin, "\x00\x64\xff\x9b") || str_starts_with($bin, "\x20\x02")) return false;
    return true;
}
/** בדיקות מקומיות בלבד (שרת הפיתוח של PHP): תיקיית מסמכי CIMD מדומים במשתנה הסביבה של התהליך — לא מהבקשה. */
function oauthTestDir(): ?string {
    if (PHP_SAPI !== 'cli-server') return null;
    $d = getenv('FD_TEST_CIMD_DIR');
    return is_string($d) && $d !== '' && is_dir($d) ? $d : null;
}
/** host → כתובות IP. בבדיקות — מ-hosts.json של התיקייה המדומה, ואם אינו שם — DNS רגיל. */
function oauthResolve(string $host): array {
    if (($d = oauthTestDir()) !== null && is_file("$d/hosts.json")) {
        $map = json_decode((string)file_get_contents("$d/hosts.json"), true);
        if (isset($map[$host])) return (array)$map[$host];
    }
    $ips = [];
    foreach ((@dns_get_record($host, DNS_A | DNS_AAAA) ?: []) as $r) $ips[] = $r['ip'] ?? $r['ipv6'] ?? '';
    if (!$ips) $ips = @gethostbynamel($host) ?: [];
    return array_values(array_filter($ips));
}
/**
 * client_id שהוא כתובת → בדיקה לפני כל בקשה יוצאת: https בלבד, פורט 443, בלי userinfo / fragment, נתיב קיים,
 * ו-host שאינו כתובת IP פרטית או מקומית. מחזיר [host, ip] או null.
 */
function oauthCimdTarget(string $url): ?array {
    if (strlen($url) > 500) return null;
    $p = parse_url($url);
    if (!$p || strtolower((string)($p['scheme'] ?? '')) !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])
        || isset($p['fragment']) || (isset($p['port']) && (int)$p['port'] !== 443) || ($p['path'] ?? '') === '' || ($p['path'] ?? '') === '/') return null;
    $host = strtolower(trim($p['host'], '[]'));
    if (filter_var($host, FILTER_VALIDATE_IP)) return oauthPublicIp($host) ? [$host, $host] : null;
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || !str_contains($host, '.')) return null;
    $ips = oauthResolve($host);
    if (!$ips) return null;
    foreach ($ips as $ip) if (!oauthPublicIp($ip)) return null;   // כתובת פרטית אחת — נדחה (DNS rebinding)
    return [$host, $ips[0]];
}
/** הורדת מסמך CIMD: 5 שניות, 64KB, בלי הפניות, מוצמד ל-IP שנבדק. מחזיר את הגוף או null. */
function oauthCimdFetch(string $url, string $host, string $ip): ?string {
    if (($d = oauthTestDir()) !== null) {
        file_put_contents("$d/fetch.log", $url . "\n", FILE_APPEND);
        $f = "$d/" . sha1($url) . '.json';
        return is_file($f) ? (string)file_get_contents($f) : null;
    }
    if (!function_exists('curl_init')) return null;
    $buf = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_RESOLVE => [$host . ':443:' . (str_contains($ip, ':') ? "[$ip]" : $ip)],
        CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'funerals-dashboard-oauth',
        CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$buf): int {
            $buf .= $chunk;
            return strlen($buf) > OAUTH_CIMD_MAX ? 0 : strlen($chunk);   // מעבר ל-64KB — עוצר
        }]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $ok !== false && $code === 200 && strlen($buf) <= OAUTH_CIMD_MAX ? $buf : null;
}
/**
 * הלקוח לפי client_id: DCR (fdc_…) מהטבלה; כתובת https — מסמך CIMD (מטמון שעה). מחזיר
 * ['client_id', 'client_name', 'redirect_uris' => [...]] או null.
 */
function oauthClient(PDO $pdo, string $clientId): ?array {
    if (preg_match('/^fdc_[a-f0-9]{32}$/D', $clientId)) {
        $c = oauthRow($pdo, "SELECT * FROM oauth_clients WHERE client_id=? AND kind='dcr'", [$clientId]);
        return $c ? ['client_id' => $clientId, 'client_name' => $c['client_name'], 'redirect_uris' => json_decode($c['redirect_uris'], true) ?: []] : null;
    }
    if (!str_starts_with(strtolower($clientId), 'https://')) return null;
    $c = oauthRow($pdo, "SELECT * FROM oauth_clients WHERE client_id=? AND kind='cimd'", [$clientId]);
    if ($c && strtotime((string)$c['fetched_at']) > time() - OAUTH_CIMD_TTL) {
        return ['client_id' => $clientId, 'client_name' => $c['client_name'], 'redirect_uris' => json_decode($c['redirect_uris'], true) ?: []];
    }
    $t = oauthCimdTarget($clientId);
    if ($t === null) return null;                     // נדחה בלי בקשה יוצאת
    $raw = oauthCimdFetch($clientId, $t[0], $t[1]);
    $doc = $raw === null ? null : json_decode($raw, true);
    if (!is_array($doc) || ($doc['client_id'] ?? null) !== $clientId) return null;
    $uris = $doc['redirect_uris'] ?? null;
    if (!is_array($uris) || !$uris || count($uris) > 20 || !array_is_list($uris)) return null;
    foreach ($uris as $u) if (!oauthRedirectOk($u)) return null;
    if (($doc['token_endpoint_auth_method'] ?? 'none') !== 'none') return null;
    $name = oauthName($doc['client_name'] ?? '');
    if ($name === '') $name = $t[0];
    $now = gmdate('c');
    $pdo->prepare('INSERT INTO oauth_clients(client_id, kind, client_name, redirect_uris, created_at, fetched_at) VALUES(?,?,?,?,?,?)
        ON CONFLICT(client_id) DO UPDATE SET client_name=excluded.client_name, redirect_uris=excluded.redirect_uris, fetched_at=excluded.fetched_at')
        ->execute([$clientId, 'cimd', $name, json_encode($uris, JSON_UNESCAPED_SLASHES), $now, $now]);
    return ['client_id' => $clientId, 'client_name' => $name, 'redirect_uris' => $uris];
}

// ───────────────────────── קודים ואסימונים ─────────────────────────

function oauthB64url(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function oauthPkceOk(string $verifier, string $challenge): bool {
    if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)) return false;
    return hash_equals($challenge, oauthB64url(hash('sha256', $verifier, true)));
}
/** קוד חד-פעמי (60 שניות), קשור ללקוח, ל-redirect_uri, למשתמש, לארגון, ל-code_challenge ול-resource. */
function oauthIssueCode(PDO $pdo, array $client, string $redirectUri, int $userId, int $orgId, string $challenge): string {
    $code = 'fdk_' . bin2hex(random_bytes(24));
    $pdo->prepare('INSERT INTO oauth_codes(hash, client_id, client_name, redirect_uri, user_id, org_id, challenge, resource, expires_at) VALUES(?,?,?,?,?,?,?,?,?)')
        ->execute([oauthHash($code), $client['client_id'], $client['client_name'], $redirectUri, $userId, $orgId, $challenge, MCP_URL, time() + OAUTH_CODE_TTL]);
    if (random_int(1, 20) === 1) $pdo->prepare('DELETE FROM oauth_codes WHERE expires_at < ?')->execute([time() - 86400]);
    return $code;
}
/** זוג אסימונים חדש ל-grant: גישה (fdo_, שעה) ורענון (fdr_, 30 יום). */
function oauthIssueTokens(PDO $pdo, int $grantId): array {
    $access = 'fdo_' . bin2hex(random_bytes(24));
    $refresh = 'fdr_' . bin2hex(random_bytes(24));
    $ins = $pdo->prepare('INSERT INTO oauth_tokens(hash, kind, grant_id, expires_at) VALUES(?,?,?,?)');
    $ins->execute([oauthHash($access), 'access', $grantId, time() + OAUTH_ACCESS_TTL]);
    $ins->execute([oauthHash($refresh), 'refresh', $grantId, time() + OAUTH_REFRESH_TTL]);
    if (random_int(1, 20) === 1) $pdo->prepare('DELETE FROM oauth_tokens WHERE expires_at < ?')->execute([time() - 86400]);
    return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => OAUTH_ACCESS_TTL, 'refresh_token' => $refresh, 'scope' => MCP_SCOPE];
}
function oauthRevokeGrant(PDO $pdo, int $grantId, ?int $by, string $reason): void {
    $pdo->prepare('UPDATE oauth_grants SET revoked_at=?, revoked_by=?, revoked_reason=? WHERE id=? AND revoked_at IS NULL')
        ->execute([gmdate('c'), $by, $reason, $grantId]);
}

/** /token — authorization_code. כל כשל → invalid_grant (בלי לפרט איזה). קוד בשימוש שני → גם ה-grant שנוצר ממנו מבוטל. */
function oauthTokenFromCode(PDO $pdo, array $p): array {
    $code = oauthStr($p, 'code', 200);
    $verifier = oauthStr($p, 'code_verifier', 200);
    $clientId = oauthStr($p, 'client_id', 500);
    $redirect = oauthStr($p, 'redirect_uri', 500);
    if ($code === '' || $verifier === '' || $clientId === '' || $redirect === '') oauthError(400, 'invalid_request', 'חסרים code, code_verifier, client_id או redirect_uri');
    if (!oauthResourceOk(oauthStr($p, 'resource', 500))) oauthError(400, 'invalid_target', 'resource לא מוכר');
    oauthBegin($pdo);
    try {
    $c = oauthRow($pdo, 'SELECT * FROM oauth_codes WHERE hash=?', [oauthHash($code)]);
    $bad = static function (string $why) use ($pdo): never {
        oauthCommit($pdo);   // סימון הקוד כשרוף, וביטול ה-grant (שימוש שני) — נשמרים
        oauthError(400, 'invalid_grant', $why);
    };
    if ($c === null) $bad('קוד לא תקין');
    if ($c['used_at'] !== null) {
        if ($c['grant_id'] !== null) oauthRevokeGrant($pdo, (int)$c['grant_id'], null, 'code_reuse');
        $bad('הקוד כבר נוצל');
    }
    // מסמנים מיד — כל ניסיון שני נכשל, גם אם הראשון נכשל (שגוי = שרוף)
    $pdo->prepare('UPDATE oauth_codes SET used_at=? WHERE hash=?')->execute([gmdate('c'), $c['hash']]);
    if ((int)$c['expires_at'] < time()) $bad('הקוד פג');
    if (!hash_equals((string)$c['client_id'], $clientId) || !hash_equals((string)$c['redirect_uri'], $redirect)) $bad('הקוד לא שייך ללקוח או לכתובת החזרה');
    if (!oauthPkceOk($verifier, (string)$c['challenge'])) $bad('code_verifier שגוי');
    if (extMember($pdo, (int)$c['user_id'], (int)$c['org_id']) === null) $bad('המשתמש כבר לא חבר פעיל בארגון');
    $pdo->prepare('INSERT INTO oauth_grants(user_id, org_id, client_id, client_name, created_at) VALUES(?,?,?,?,?)')
        ->execute([(int)$c['user_id'], (int)$c['org_id'], $c['client_id'], $c['client_name'], gmdate('c')]);
    $gid = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE oauth_codes SET grant_id=? WHERE hash=?')->execute([$gid, $c['hash']]);
    $t = oauthIssueTokens($pdo, $gid);
    oauthCommit($pdo);
    return $t;
    } catch (Throwable $e) { oauthRollback($pdo); throw $e; }
}
/** /token — refresh_token: מתחלף בכל שימוש; שימוש חוזר בישן → כל ה-grant מבוטל. */
function oauthTokenFromRefresh(PDO $pdo, array $p): array {
    $raw = oauthStr($p, 'refresh_token', 200);
    $clientId = oauthStr($p, 'client_id', 500);
    if ($raw === '' || $clientId === '') oauthError(400, 'invalid_request', 'חסרים refresh_token או client_id');
    oauthBegin($pdo);
    try {
    $t = oauthRow($pdo, "SELECT t.*, g.client_id, g.user_id, g.org_id, g.revoked_at FROM oauth_tokens t JOIN oauth_grants g ON g.id=t.grant_id
        WHERE t.hash=? AND t.kind='refresh'", [oauthHash($raw)]);
    $bad = static function (string $why) use ($pdo): never { oauthCommit($pdo); oauthError(400, 'invalid_grant', $why); };
    if ($t === null || $t['revoked_at'] !== null) $bad('אסימון רענון לא תקין');
    if (!hash_equals((string)$t['client_id'], $clientId)) $bad('אסימון רענון לא תקין');
    if ($t['used_at'] !== null) {
        oauthRevokeGrant($pdo, (int)$t['grant_id'], null, 'refresh_reuse');
        $bad('אסימון הרענון כבר נוצל — החיבור בוטל. להתחבר מחדש.');
    }
    if ((int)$t['expires_at'] < time()) $bad('אסימון הרענון פג');
    if (extMember($pdo, (int)$t['user_id'], (int)$t['org_id']) === null) {
        oauthRevokeGrant($pdo, (int)$t['grant_id'], null, 'member_inactive');   // הפעלה מחדש לא מחייה חיבור ישן
        $bad('המשתמש כבר לא חבר פעיל בארגון');
    }
    $pdo->prepare('UPDATE oauth_tokens SET used_at=? WHERE hash=?')->execute([gmdate('c'), $t['hash']]);
    $out = oauthIssueTokens($pdo, (int)$t['grant_id']);
    oauthCommit($pdo);
    return $out;
    } catch (Throwable $e) { oauthRollback($pdo); throw $e; }
}
/** RFC 7009: תמיד 200. רענון → כל ה-grant; גישה → האסימון הזה. רק של הלקוח ששלח. */
function oauthRevoke(PDO $pdo, array $p): void {
    $raw = oauthStr($p, 'token', 200);
    $clientId = oauthStr($p, 'client_id', 500);
    if ($raw === '') return;
    $t = oauthRow($pdo, 'SELECT t.hash, t.kind, t.grant_id, g.client_id FROM oauth_tokens t JOIN oauth_grants g ON g.id=t.grant_id WHERE t.hash=?', [oauthHash($raw)]);
    if ($t === null || ($clientId !== '' && !hash_equals((string)$t['client_id'], $clientId))) return;
    if ($t['kind'] === 'refresh') oauthRevokeGrant($pdo, (int)$t['grant_id'], null, 'client_revoke');
    else $pdo->prepare('UPDATE oauth_tokens SET expires_at=? WHERE hash=?')->execute([time() - 1, $t['hash']]);
}

// ───────────────────────── החיבורים (מסך 🤖) ─────────────────────────

/** החיבורים הפעילים: שלי, ולמנהל — של כל הארגון. בלי hash ובלי client_id. */
function oauthListGrants(PDO $pdo, int $orgId, int $userId, bool $all): array {
    $st = $pdo->prepare('SELECT g.id, g.client_name, g.created_at, g.last_used_at, g.user_id, COALESCE(NULLIF(u.name, \'\'), u.email) AS owner
        FROM oauth_grants g LEFT JOIN users u ON u.id=g.user_id
        WHERE g.org_id=? AND g.revoked_at IS NULL ' . ($all ? '' : 'AND g.user_id=? ') . 'ORDER BY COALESCE(g.last_used_at, g.created_at) DESC LIMIT 200');
    $st->execute($all ? [$orgId] : [$orgId, $userId]);
    return array_map(static fn($g) => $g + ['mine' => (int)$g['user_id'] === $userId], $st->fetchAll(PDO::FETCH_ASSOC));
}
/** "ניתוק": שלי, או כל חיבור בארגון למנהל. של ארגון אחר → 404. */
function oauthDisconnect(PDO $pdo, int $orgId, int $userId, bool $manager, int $id): void {
    $g = oauthRow($pdo, 'SELECT id, user_id, client_name, revoked_at FROM oauth_grants WHERE id=? AND org_id=?', [$id, $orgId]);
    if (!$g || ((int)$g['user_id'] !== $userId && !$manager)) reply(404, ['error' => 'חיבור לא נמצא']);
    if ($g['revoked_at'] !== null) return;
    oauthRevokeGrant($pdo, $id, $userId, 'disconnect');
    recordEvent($orgId, null, ctx()['actor'], 'ui', 'oauth_revoked', ['client' => [$g['client_name'] . ' #' . $id, null]]);
}

// ───────────────────────── MCP ─────────────────────────

/** הכלים = extActions() חוץ מ-prompt (הוא ה-instructions). */
function mcpTools(): array {
    $tools = [];
    foreach (extActions() as $name => $a) {
        if ($name === 'prompt') continue;
        if ($a['method'] === 'POST') {
            $schema = $a['body'] ?? ['type' => 'object', 'properties' => new stdClass()];
        } else {
            $props = [];
            $req = [];
            foreach ($a['query'] as $q) {
                $props[$q['name']] = ['type' => 'string', 'description' => $q['description']] + (isset($q['enum']) ? ['enum' => $q['enum']] : []);
                if ($q['required']) $req[] = $q['name'];
            }
            $schema = ['type' => 'object', 'properties' => $props ?: new stdClass()] + ($req ? ['required' => $req] : []);
        }
        $read = $a['method'] === 'GET';
        $tools[] = ['name' => $name, 'title' => $a['he'], 'description' => $a['en'] . ' / ' . $a['he'], 'inputSchema' => $schema,
            'annotations' => $read ? ['title' => $a['he'], 'readOnlyHint' => true, 'openWorldHint' => false]
                : ['title' => $a['he'], 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false]];
    }
    return $tools;
}

/** Authorization: Bearer — fdo_ (OAuth) או fdx_ (05). אחר → ''. */
function mcpBearer(): string {
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    return preg_match('/^Bearer (fd[ox]_[a-f0-9]{48})$/D', $h, $m) ? $m[1] : '';
}
/**
 * מזהה את בעל האסימון ובונה את ctx() של 01 (extSetContext + שם הלקוח לשם ביומן). null = אין / לא תקין.
 * כל בקשה בודקת מחדש: grant לא בוטל, אסימון לא פג, המשתמש עדיין חבר פעיל בארגון (השבתה → 401 מיד).
 * מחזיר ['m' => החבר, 'rl' => [טבלה, מזהה]] לספירת הקריאות.
 */
function mcpAuthenticate(PDO $pdo): ?array {
    $raw = mcpBearer();
    if ($raw === '') return null;
    if (str_starts_with($raw, 'fdo_')) {
        $t = oauthRow($pdo, "SELECT t.expires_at, g.id AS grant_id, g.user_id, g.org_id, g.client_name, g.revoked_at
            FROM oauth_tokens t JOIN oauth_grants g ON g.id=t.grant_id WHERE t.hash=? AND t.kind='access'", [oauthHash($raw)]);
        if ($t === null || $t['revoked_at'] !== null || (int)$t['expires_at'] < time()) return null;
        $m = extMember($pdo, (int)$t['user_id'], (int)$t['org_id']);
        if ($m === null) { oauthRevokeGrant($pdo, (int)$t['grant_id'], null, 'member_inactive'); return null; }
        extSetContext($m);
        $GLOBALS['fd_ctx']['client'] = (string)$t['client_name'];
        return ['m' => $m, 'rl' => ['oauth_grants', (int)$t['grant_id']]];
    }
    $t = oauthRow($pdo, 'SELECT * FROM tokens WHERE hash=?', [oauthHash($raw)]);   // fdx_ של 05 — ל-Inspector ולבדיקות
    if ($t === null || $t['revoked_at'] !== null || strcmp((string)$t['expires_at'], gmdate('c')) <= 0) return null;
    $m = extMember($pdo, (int)$t['user_id'], (int)$t['org_id']);
    if ($m === null) return null;
    extSetContext($m);
    return ['m' => $m, 'rl' => ['tokens', (int)$t['id']]];
}
/** ספירת קריאות לחיבור (60 לדקה), ושימוש אחרון. true = מותר. */
function mcpRate(PDO $pdo, array $rl): bool {
    [$table, $id] = $rl;
    if (!in_array($table, ['oauth_grants', 'tokens'], true)) throw new LogicException('mcpRate');
    $minute = intdiv(time(), 60);
    $pdo->prepare("UPDATE $table SET rl_n = CASE WHEN rl_min=? THEN rl_n+1 ELSE 1 END, rl_min=?, last_used_at=? WHERE id=?")
        ->execute([$minute, $minute, gmdate('c'), $id]);
    $r = oauthRow($pdo, "SELECT rl_n FROM $table WHERE id=?", [$id]);
    return (int)($r['rl_n'] ?? 0) <= EXT_RATE_PER_MINUTE;
}
/** תוצאת כלי — structuredContent + אותו JSON כטקסט. */
function mcpToolResult(array $data, bool $isError): array {
    $obj = $data === [] ? new stdClass() : $data;
    return ['content' => [['type' => 'text', 'text' => json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]],
        'structuredContent' => $obj, 'isError' => $isError];
}
/** tools/call → extDo(..., 'api'). שגיאת פעולה (ExtError, או reply() של הליבה במצב לכידה) → isError עם ההודעה בעברית. */
function mcpCall(PDO $pdo, string $name, array $args): array {
    $GLOBALS['fd_reply_capture'] = true;
    try {
        return mcpToolResult(extDo($pdo, $name, $args, 'api'), false);
    } catch (ExtError $e) {
        return mcpToolResult(['error' => $e->getMessage(), 'status' => $e->getCode() ?: 400], true);
    } catch (FdReply $r) {
        return mcpToolResult(['error' => (string)($r->data['error'] ?? 'שגיאה'), 'status' => $r->status], true);
    } catch (Throwable $e) {
        error_log('funerals-dashboard mcp tool ' . $name . ': ' . $e->getMessage());
        return mcpToolResult(['error' => 'הכלי נכשל', 'status' => 500], true);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        unset($GLOBALS['fd_reply_capture']);
    }
}
