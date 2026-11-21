<?php
/**
 * סטודיו — לקוח HTTP ל-Claude Managed Agents (ה"פלטפורמה").
 *
 * רק מה שהסטודיו צריך: סוכן, סביבה, סשן, אירועים. בלי SDK — באחסון אין composer.
 * כל קריאה נושאת את כותרת הבטא managed-agents-2026-04-01.
 *
 * ההעברה ניתנת להחלפה ($GLOBALS['STUDIO_HTTP']) כדי שהבדיקות ירוצו בלי רשת
 * ובלי מפתח: callable(string $method, string $url, array $headers, ?string $body)
 * שמחזיר ['status' => int, 'body' => string, 'headers' => array].
 */

declare(strict_types=1);

require_once __DIR__ . '/../errors.php';

const MA_BASE    = 'https://api.anthropic.com';
const MA_BETA    = 'managed-agents-2026-04-01';
const MA_VERSION = '2023-06-01';
const MA_TIMEOUT = 25;

/** שגיאה מהפלטפורמה: קוד ה-HTTP, סוג השגיאה, ו-retry-after כשנשלח. */
class MaError extends AppError {
    public string $maType = '';
    public int $retryAfter = 0;
    public function __construct(string $message, public int $http = 0, string $type = '', int $retryAfter = 0) {
        parent::__construct($message, 502);
        $this->maType = $type;
        $this->retryAfter = $retryAfter;
    }
}

function maTransport(string $method, string $url, array $headers, ?string $body): array {
    if (isset($GLOBALS['STUDIO_HTTP']) && is_callable($GLOBALS['STUDIO_HTTP'])) {
        return ($GLOBALS['STUDIO_HTTP'])($method, $url, $headers, $body);
    }
    if (!function_exists('curl_init')) throw new AppError('cURL אינו זמין בשרת');
    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => MA_TIMEOUT,
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$respHeaders) {
            $p = strpos($line, ':');
            if ($p !== false) $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            return strlen($line);
        },
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $out = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($out === false) throw new MaError('אין תשובה מהפלטפורמה: ' . $err, 0, 'network');
    return ['status' => $status, 'body' => (string) $out, 'headers' => $respHeaders];
}

/**
 * קריאה אחת. מחזיר את ה-JSON המפוענח; זורק MaError על כל תשובה שאינה 2xx.
 * בכוונה בלי ניסיון חוזר: 429 ו-402 לא מנסים שוב בלולאה — מי שקרא מחליט.
 */
function maCall(string $key, string $method, string $path, ?array $body = null, array $query = []): array {
    if ($key === '') throw new AppError('לא חובר מפתח API של Anthropic. הגדרות הלוח ← חיבורים ← "+ חיבור" ← Anthropic');
    $url = MA_BASE . $path . ($query ? '?' . http_build_query($query) : '');
    $headers = [
        'x-api-key: ' . $key,
        'anthropic-version: ' . MA_VERSION,
        'anthropic-beta: ' . MA_BETA,
        'content-type: application/json',
    ];
    $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $res = maTransport($method, $url, $headers, $json);
    $data = json_decode($res['body'], true);
    if ($res['status'] >= 200 && $res['status'] < 300) return is_array($data) ? $data : [];

    $type = (string) ($data['error']['type'] ?? '');
    $msg  = (string) ($data['error']['message'] ?? '');
    $human = match (true) {
        $res['status'] === 401 => 'המפתח של Anthropic נדחה (401)',
        $res['status'] === 402 || $type === 'billing_error' => 'נגמר הקרדיט בפלטפורמה, או שהגעתם לתקרת ההוצאה',
        $res['status'] === 429 => 'הפלטפורמה מגבילה את קצב הבקשות (429)',
        $res['status'] >= 500  => 'תקלה בצד הפלטפורמה (' . $res['status'] . ')',
        default                => 'הפלטפורמה דחתה את הבקשה (' . $res['status'] . ')',
    };
    throw new MaError($human . ($msg !== '' ? ': ' . mb_substr($msg, 0, 400) : ''), $res['status'], $type,
                      (int) ($res['headers']['retry-after'] ?? 0));
}

/* ── משאבים ────────────────────────────────────────────────────── */

function maCreateAgent(string $key, array $def): array   { return maCall($key, 'POST', '/v1/agents', $def); }
function maUpdateAgent(string $key, string $id, array $def): array { return maCall($key, 'POST', '/v1/agents/' . rawurlencode($id), $def); }
function maCreateEnvironment(string $key, array $def): array { return maCall($key, 'POST', '/v1/environments', $def); }
function maListEnvironments(string $key): array { return maCall($key, 'GET', '/v1/environments', null, ['limit' => 100]); }
function maCreateVault(string $key, array $def): array { return maCall($key, 'POST', '/v1/vaults', $def); }
function maCreateCredential(string $key, string $vaultId, array $def): array { return maCall($key, 'POST', '/v1/vaults/' . rawurlencode($vaultId) . '/credentials', $def); }
function maDeleteCredential(string $key, string $vaultId, string $credId): array { return maCall($key, 'DELETE', '/v1/vaults/' . rawurlencode($vaultId) . '/credentials/' . rawurlencode($credId)); }
function maCreateSession(string $key, array $def): array { return maCall($key, 'POST', '/v1/sessions', $def); }
function maGetSession(string $key, string $id): array    { return maCall($key, 'GET', '/v1/sessions/' . rawurlencode($id)); }
function maUpdateSession(string $key, string $id, array $patch): array { return maCall($key, 'POST', '/v1/sessions/' . rawurlencode($id), $patch); }

function maSendEvents(string $key, string $sessionId, array $events): array {
    return maCall($key, 'POST', '/v1/sessions/' . rawurlencode($sessionId) . '/events', ['events' => $events]);
}

/** עמוד אחד של אירועים. ‏$page — הסמן מ-next_page של העמוד הקודם, או '' להתחלה. */
function maListEvents(string $key, string $sessionId, string $page = '', int $limit = 100): array {
    $q = ['limit' => $limit];
    if ($page !== '') $q['page'] = $page;
    return maCall($key, 'GET', '/v1/sessions/' . rawurlencode($sessionId) . '/events', null, $q);
}
