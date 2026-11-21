<?php
/**
 * המתאמים של ספקי הבינה (אפיון 11 §9). ממשק אחיד; מתאם אחד בנוי — Anthropic, ב-cURL, בלי ספרייה.
 *
 * **רק lib/ai.php קורא למתאם** (ai_run ובדיקת החיבור) — בדיקה סטטית אוכפת זאת.
 * **כתובת הספק קבועה בקוד** — היא אינה מגיעה מהמסד, מהקטלוג או מהמשתמש (אין SSRF). TLS מאומת; אין הפניות.
 * **המתאם אינו שולח tools לעולם** (11-D5): טקסט נכנס, טקסט יוצא. טקסט ממסמך הוא נתון ולא הוראה — אין למודל במה לפעול.
 * **המפתח** מגיע כפרמטר מסומן #[SensitiveParameter], נשלח בכותרת בלבד, ואינו נכנס לשום חריגה, יומן או תשובה.
 * **שגיאת ספק אינה יוצאת מכאן גולמית:** AprAiError נושאת קוד טכני קצר בלבד (auth, http_429, timeout …) — לא את גוף התשובה.
 *
 * ספק נוסף = מחלקה שמממשת AprAiAdapter + שורה ב-APR_AI_ADAPTERS + שורה ב-ai_providers. בלי שינוי ב-ai_run.
 * בבדיקות: $GLOBALS['apr_ai_adapters'][<adapter>] = מופע מדומה — שום בדיקה אינה יוצאת לרשת.
 */

declare(strict_types=1);

const APR_AI_TIMEOUT = 120;                  // שניות (§9). פעולה ארוכה רצה כמשימת רקע, לא בבקשת המשתמש
const APR_AI_MAX_RESPONSE = 8_000_000;       // בתים — תשובה ארוכה מזה נחשבת קטועה

/**
 * מה קרה לבקשה אצל הספק:
 *   auth      — האימות נדחה (401 / 403). לא עובדה.
 *   rejected  — הספק דחה בבירור (4xx, 5xx עם גוף שגיאה). לא עובדה, לא חויבה.
 *   unreachable — לא נוצר חיבור. לא נשלחה.
 *   unknown   — פסק זמן, תשובה קטועה או לא מובנת: ייתכן שעובדה וחויבה.
 */
final class AprAiError extends RuntimeException
{
    public function __construct(public readonly string $kind, public readonly string $tech)
    {
        parent::__construct('ai: ' . $kind . ' (' . $tech . ')');
    }
}

interface AprAiAdapter
{
    /**
     * $request: model, max_tokens, system (?string), parts [['type' => 'text', 'text'] | ['type' => 'image' | 'pdf', 'mime', 'data' (בינארי)]].
     * מחזיר ['text' => string, 'in_tokens' => ?int, 'out_tokens' => ?int, 'stop_reason' => ?string]. כשל — AprAiError.
     */
    public function complete(#[\SensitiveParameter] string $key, #[\SensitiveParameter] array $request): array;

    /** ספירת טוקנים אצל הספק (קריאת רשת). ai_run אינו משתמש בה — האומדן שלפני השריון מקומי (§6.2). */
    public function count_tokens(#[\SensitiveParameter] string $key, #[\SensitiveParameter] array $request): int;

    /** "בדיקת חיבור": קריאה מינימלית. מחזיר 'ok', או זורק AprAiError. */
    public function health(#[\SensitiveParameter] string $key): string;

    /** מה המתאם עצמו יודע לשלוח — בנוסף ליכולות של המודל שבקטלוג. */
    public function capabilities(string $model): array;
}

const APR_AI_ADAPTERS = ['anthropic' => 'AprAnthropicAdapter'];

function apr_ai_adapter(string $name): AprAiAdapter
{
    $fake = $GLOBALS['apr_ai_adapters'][$name] ?? null;
    if ($fake instanceof AprAiAdapter) return $fake;
    $class = APR_AI_ADAPTERS[$name] ?? throw new AprAiError('rejected', 'no_adapter');
    return new $class();
}

final class AprAnthropicAdapter implements AprAiAdapter
{
    // קבועים בקוד — לא מהמסד ולא מהמשתמש
    private const BASE = 'https://api.anthropic.com';
    private const VERSION = '2026-10-21';

    public function capabilities(string $model): array
    {
        return ['text' => true, 'vision' => true, 'pdf' => true];
    }

    /** גוף הבקשה ל-/v1/messages. בלי tools, בלי tool_choice, בלי temperature (נדחה בגרסה הזו של ה-API). */
    public static function body(#[\SensitiveParameter] array $request, bool $forCount = false): array
    {
        $content = [];
        foreach ($request['parts'] as $p) {
            $content[] = match ($p['type']) {
                'text' => ['type' => 'text', 'text' => (string)$p['text']],
                'image' => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => (string)$p['mime'], 'data' => base64_encode((string)$p['data'])]],
                'pdf' => ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode((string)$p['data'])]],
            };
        }
        // מסמכים ותמונות לפני הטקסט
        usort($content, fn($a, $b) => ($a['type'] === 'text') <=> ($b['type'] === 'text'));
        $body = ['model' => (string)$request['model'], 'messages' => [['role' => 'user', 'content' => $content]]];
        if (!$forCount) $body['max_tokens'] = (int)$request['max_tokens'];
        if (is_string($request['system'] ?? null) && $request['system'] !== '') $body['system'] = $request['system'];
        return $body;
    }

    public function complete(#[\SensitiveParameter] string $key, #[\SensitiveParameter] array $request): array
    {
        $j = $this->call($key, 'POST', '/v1/messages', self::body($request));
        if (($j['type'] ?? null) !== 'message' || !is_array($j['content'] ?? null)) throw new AprAiError('unknown', 'bad_body');
        $text = '';
        foreach ($j['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) $text .= $block['text'];
        }
        $u = is_array($j['usage'] ?? null) ? $j['usage'] : [];
        $in = null;
        foreach (['input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'] as $k) {
            if (is_int($u[$k] ?? null)) $in = ($in ?? 0) + $u[$k];
        }
        return ['text' => $text, 'in_tokens' => $in, 'out_tokens' => is_int($u['output_tokens'] ?? null) ? $u['output_tokens'] : null,
            'stop_reason' => is_string($j['stop_reason'] ?? null) ? $j['stop_reason'] : null];
    }

    public function count_tokens(#[\SensitiveParameter] string $key, #[\SensitiveParameter] array $request): int
    {
        $j = $this->call($key, 'POST', '/v1/messages/count_tokens', self::body($request, true));
        return is_int($j['input_tokens'] ?? null) ? $j['input_tokens'] : throw new AprAiError('unknown', 'bad_body');
    }

    public function health(#[\SensitiveParameter] string $key): string
    {
        // רשימת המודלים: מאמתת את המפתח, ואינה מפעילה מודל
        $this->call($key, 'GET', '/v1/models?limit=1', null, 20);
        return 'ok';
    }

    private function call(#[\SensitiveParameter] string $key, string $method, string $path, #[\SensitiveParameter] ?array $body, int $timeout = APR_AI_TIMEOUT): array
    {
        if (!function_exists('curl_init')) throw new AprAiError('unreachable', 'no_curl');
        $ch = curl_init(self::BASE . $path);
        $opt = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['x-api-key: ' . $key, 'anthropic-version: ' . self::VERSION, 'content-type: application/json', 'accept: application/json'],
        ];
        if ($method === 'POST') {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) throw new AprAiError('rejected', 'encode');
            $opt += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json];
        }
        curl_setopt_array($ch, $opt);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return self::interpret($raw, $errno, $status);
    }

    /** תשובת cURL ← JSON של הצלחה, או AprAiError עם קוד טכני. גוף התשובה של שגיאה אינו נשמר ואינו מועבר הלאה. */
    public static function interpret(string|bool $raw, int $errno, int $status): array
    {
        if ($raw === false || $errno !== 0) {
            // לא נוצר חיבור — הבקשה לא נשלחה. כל כשל אחר (פסק זמן, ניתוק באמצע) — לא ידוע אם עובדה
            $notSent = in_array($errno, [5, 6, 7, 35, 60], true);   // proxy / host לא נמצא, אין חיבור, לחיצת TLS נכשלה, תעודה לא אומתה
            throw new AprAiError($notSent ? 'unreachable' : 'unknown', $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'curl_' . $errno);
        }
        if (strlen($raw) > APR_AI_MAX_RESPONSE) throw new AprAiError('unknown', 'too_long');
        $j = json_decode($raw, true);
        if ($status >= 200 && $status < 300) {
            return is_array($j) ? $j : throw new AprAiError('unknown', 'bad_body');
        }
        if ($status === 401 || $status === 403) throw new AprAiError('auth', 'http_' . $status);
        // שגיאה מובנית מהספק — דחייה ברורה. 5xx בלי גוף שגיאה מובן — לא ידוע
        $clear = is_array($j) && ($j['type'] ?? null) === 'error';
        if ($status >= 400 && $status < 500) throw new AprAiError('rejected', 'http_' . $status);
        throw new AprAiError($clear ? 'rejected' : 'unknown', 'http_' . $status);
    }
}
