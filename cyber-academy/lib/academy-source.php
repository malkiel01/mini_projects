<?php
/**
 * שכבת האינטגרציה — מאיפה מגיע ה-snapshot של האקדמיה.
 *
 * ממשק אחד, שני מימושים:
 * - local  — snapshot דוגמה מתוך הקוד (samples/). פעיל. תמיד isSample = true.
 * - github — Contents API של מאגר האקדמיה, צד שרת בלבד, אסימון fine-grained מ-data/config.json,
 *            מטמון קצר ב-data/cache/. **כבוי**: נדלק רק כששלושה תנאים מתקיימים —
 *            github_enabled + privacy_confirmed בקונפיג, וגם ACADEMY_GITHUB_PHASE_ALLOWED בקוד.
 *            האחרון false בשלב הזה (owner-brief סעיף 1.7: אין נתונים אישיים לפני שהמאגר פרטי
 *            ואבטחת הגישה אומתה), כך ששום עריכת קונפיג בשרת לא מדליקה אותו בטעות.
 *
 * isSample נקבע רק כאן, לפי סוג המקור — שום פרמטר בבקשה לא משנה אותו.
 * האסימון לעולם לא יוצא מהקובץ הזה: לא בתשובה, לא בלוג ולא בהודעת שגיאה (academy_redact).
 * אין כאן שום פעולת כתיבה למאגר — רק GET.
 */

declare(strict_types=1);

const ACADEMY_GITHUB_PHASE_ALLOWED = false;
const ACADEMY_DIR = __DIR__ . '/..';

/**
 * כשל במקור, עם קוד יציב שה-UI מציג לפיו מסך משלו:
 * source_disabled · source_unauthorized · source_not_found · source_unavailable · contract_invalid
 */
final class AcademySourceError extends RuntimeException {
    public function __construct(string $message, public readonly string $errorCode = 'source_unavailable') {
        parent::__construct($message);
    }
}

interface AcademySource {
    /** שם המקור כפי שמוצג ל-API: "local" / "github". */
    public function name(): string;
    /** האם הנתונים הם דוגמה — ה-UI מציג פס קבוע כשכן. */
    public function isSample(): bool;
    /** ה-snapshot כמבנה PHP (אחרי json_decode), לפני אימות. */
    public function fetch(): mixed;
    /** מטא של הקריאה האחרונה: commit (או null), collectedAt. */
    public function info(): array;
}

/** קונפיג ברירת מחדל — גם כשאין data/config.json בכלל. */
function academy_default_config(): array {
    return [
        'source'             => 'local',
        'github_enabled'     => false,
        'privacy_confirmed'  => false,
        // מקור github שאינו מותר: true = מציגים דוגמה (מסומנת), false = מסך "המקור כבוי"
        'fallback_to_sample' => true,
        // איזה חוזה לקרוא ממאגר האקדמיה: v1 (github.path) או v2 (github.v2_path — ריק = כבוי, fail-closed)
        'contract'           => 'v1',
        'github'             => [
            'owner' => 'malkiel01',
            'repo'  => 'offensive-cyber-academy',
            'ref'   => 'main',
            'path'  => 'dashboard/data/snapshot.json',
            'v2_path' => '',
            'token' => '',
        ],
        'cache_ttl' => 120,
    ];
}

/** קורא את data/config.json (אם קיים) על גבי ברירת המחדל. קונפיג פגום = ברירת מחדל, לא קריסה. */
function academy_load_config(?string $file = null): array {
    $cfg  = academy_default_config();
    $file ??= ACADEMY_DIR . '/data/config.json';
    if (!is_file($file)) return $cfg;
    $raw = json_decode((string) file_get_contents($file), true);
    if (!is_array($raw)) {
        error_log('cyber-academy: קובץ הקונפיג אינו JSON תקין — נטען קונפיג ברירת מחדל');
        return $cfg;
    }
    $github = is_array($raw['github'] ?? null) ? $raw['github'] + $cfg['github'] : $cfg['github'];
    return array_merge($cfg, array_intersect_key($raw, $cfg), ['github' => $github]);
}

/**
 * האם מקור github מותר לפעול. שלושה תנאים — ראו בראש הקובץ.
 * $phase — ברירת מחדל הקבוע; פרמטר רק כדי שהבדיקות יוכלו לבדוק את הלוגיקה שמעבר למנעול.
 */
function academy_github_allowed(array $config, bool $phase = ACADEMY_GITHUB_PHASE_ALLOWED): bool {
    return $phase
        && ($config['github_enabled'] ?? false) === true
        && ($config['privacy_confirmed'] ?? false) === true;
}

/**
 * בוחר את המקור. הבחירה היא רק מהקונפיג שבשרת — $demo משפיע רק על איזה קובץ דוגמה, ורק במקור local.
 * @throws AcademySourceError source_disabled — github מבוקש, אסור, ו-fallback_to_sample כבוי.
 */
function academy_source(array $config, string $demo = '', bool $phase = ACADEMY_GITHUB_PHASE_ALLOWED): AcademySource {
    if (($config['source'] ?? 'local') === 'github') {
        if (academy_github_allowed($config, $phase)) {
            $gh = $config['github'];
            if (($config['contract'] ?? 'v1') === 'v2') {
                // נתיב v2 לא ידוע עדיין — לא מנחשים. ריק = לא קוראים.
                if (trim((string) ($gh['v2_path'] ?? '')) === '') throw new AcademySourceError('נתיב קובץ v2 במאגר האקדמיה לא הוגדר', 'source_disabled');
                $gh['path'] = $gh['v2_path'];
            }
            return new GithubAcademySource($gh, ACADEMY_DIR . '/data/cache', (int) ($config['cache_ttl'] ?? 120));
        }
        if (($config['fallback_to_sample'] ?? true) !== true) {
            throw new AcademySourceError('החיבור למאגר האקדמיה כבוי', 'source_disabled');
        }
    }
    return new LocalAcademySource($demo === 'empty' ? 'empty-snapshot.json' : 'demo-snapshot.json');
}

/** מסיר את האסימון מכל טקסט שעלול לצאת החוצה. */
function academy_redact(string $text, string $token): string {
    if ($token !== '' && strlen($token) >= 4) $text = str_replace($token, '[הוסתר]', $text);
    // ליתר ביטחון — גם תבניות אסימון של GitHub שלא הגיעו מהקונפיג
    return (string) preg_replace('/\b(gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})\b/', '[הוסתר]', $text);
}

final class LocalAcademySource implements AcademySource {
    private string $file;
    private ?string $collectedAt = null;

    public function __construct(string $file = 'demo-snapshot.json') {
        // רק שם קובץ בתוך samples/ — בלי נתיבים
        $this->file = ACADEMY_DIR . '/samples/' . basename($file);
    }
    public function name(): string { return 'local'; }
    public function isSample(): bool { return true; }
    public function info(): array { return ['commit' => null, 'collectedAt' => $this->collectedAt]; }
    public function fetch(): mixed {
        $this->collectedAt = gmdate('Y-m-d\TH:i:s\Z');
        if (!is_file($this->file)) throw new AcademySourceError('קובץ הדוגמה לא נמצא', 'source_not_found');
        $data = json_decode((string) file_get_contents($this->file), true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new AcademySourceError('קובץ הדוגמה אינו JSON תקין', 'contract_invalid');
        }
        return $data;
    }
}

final class GithubAcademySource implements AcademySource {
    /** @var callable|null fn(string $url, array $headers): array{status:int, body:string, headers?:array} */
    private $transport;
    private array $info = ['commit' => null, 'collectedAt' => null];

    public function __construct(private array $gh, private string $cacheDir, private int $ttl = 120,
                                ?callable $transport = null) {
        $this->transport = $transport;
    }
    public function name(): string { return 'github'; }
    public function isSample(): bool { return false; }
    public function info(): array { return $this->info; }

    public function fetch(): mixed {
        $token = (string) ($this->gh['token'] ?? '');
        try {
            if ($token === '') throw new AcademySourceError('לא הוגדר אסימון גישה למאגר האקדמיה', 'source_unauthorized');
            foreach (['owner', 'repo', 'path', 'ref'] as $k) {
                $v = (string) ($this->gh[$k] ?? '');
                if (!preg_match('~^[A-Za-z0-9._/-]+$~', $v) || str_contains($v, '..')) {
                    throw new AcademySourceError("הגדרת github.$k אינה תקינה", 'source_not_found');
                }
            }
            $cached = $this->readCache();
            if ($cached !== null) {
                $this->info = ['commit' => $cached['commit'], 'collectedAt' => $cached['collectedAt']];
                return $this->decode($cached['body']);
            }

            $repo = '/repos/' . rawurlencode($this->gh['owner']) . '/' . rawurlencode($this->gh['repo']);
            // 1. ה-ref → קומיט אחד, כדי שהקובץ והקומיט שמוצג יהיו מאותה נקודה
            $res = $this->get("$repo/commits/" . rawurlencode($this->gh['ref']), 'application/vnd.github.sha', $token);
            $commit = trim($res['body']);
            if (!preg_match('/^[0-9a-f]{40}$/', $commit)) throw new AcademySourceError('מאגר האקדמיה החזיר מזהה קומיט לא צפוי', 'source_unavailable');
            // 2. הקובץ באותו קומיט
            $path = implode('/', array_map('rawurlencode', explode('/', $this->gh['path'])));
            $res = $this->get("$repo/contents/$path?ref=$commit", 'application/vnd.github.raw+json', $token);

            $this->info = ['commit' => $commit, 'collectedAt' => gmdate('Y-m-d\TH:i:s\Z')];
            $data = $this->decode($res['body']);
            $this->writeCache($res['body']);
            return $data;
        } catch (AcademySourceError $e) {
            throw new AcademySourceError(academy_redact($e->getMessage(), $token), $e->errorCode);
        } catch (Throwable $e) {
            // כל הודעה שיוצאת מכאן — בלי האסימון
            throw new AcademySourceError('הפנייה למאגר האקדמיה נכשלה: ' . academy_redact($e->getMessage(), $token), 'source_unavailable');
        }
    }

    /** GET אחד, ומיפוי הסטטוס לקוד יציב. */
    private function get(string $path, string $accept, string $token): array {
        $res = ($this->transport ?? [$this, 'curl'])('https://api.github.com' . $path, [
            'Accept: ' . $accept,
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: cyber-academy-dashboard',
            'Authorization: Bearer ' . $token,
        ]);
        $status = (int) ($res['status'] ?? 0);
        if ($status === 200) return ['body' => (string) ($res['body'] ?? '')];
        throw academy_github_error($status, (string) ($res['body'] ?? ''), (array) ($res['headers'] ?? []));
    }

    private function decode(string $body): mixed {
        $data = json_decode($body, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new AcademySourceError('ה-snapshot במאגר אינו JSON תקין', 'contract_invalid');
        }
        return $data;
    }

    private function cacheFile(): string {
        return $this->cacheDir . '/snapshot-' . substr(hash('sha256', json_encode([
            $this->gh['owner'], $this->gh['repo'], $this->gh['ref'] ?? '', $this->gh['path']])), 0, 16) . '.json';
    }
    private function readCache(): ?array {
        $f = $this->cacheFile();
        if ($this->ttl <= 0 || !is_file($f) || filemtime($f) < time() - $this->ttl) return null;
        $c = json_decode((string) file_get_contents($f), true);
        return is_array($c) && isset($c['body'], $c['commit'], $c['collectedAt']) ? $c : null;
    }
    private function writeCache(string $body): void {
        if ($this->ttl <= 0) return;
        if (!is_dir($this->cacheDir)) @mkdir($this->cacheDir, 0750, true);
        @file_put_contents($this->cacheFile(), json_encode($this->info + ['body' => $body]), LOCK_EX);
    }

    /** @return array{status:int, body:string, headers:array} */
    private function curl(string $url, array $headers): array {
        if (!function_exists('curl_init')) throw new AcademySourceError('cURL אינו זמין בשרת', 'source_unavailable');
        $h = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$h) {
                $p = explode(':', $line, 2);
                if (count($p) === 2) $h[strtolower(trim($p[0]))] = trim($p[1]);
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) throw new AcademySourceError('אין חיבור למאגר האקדמיה', 'source_unavailable');
        return ['status' => $status, 'body' => (string) $body, 'headers' => $h];
    }
}

/**
 * סטטוס HTTP של GitHub → קוד יציב + הודעה בעברית. בלי גוף התשובה בהודעה (עלול להכיל כל דבר).
 * 403 של מכסה (rate limit) הוא "לא זמין", לא "אין הרשאה".
 */
function academy_github_error(int $status, string $body = '', array $headers = []): AcademySourceError {
    $headers = array_change_key_case($headers, CASE_LOWER);
    $rateLimited = $status === 429
        || ($status === 403 && (($headers['x-ratelimit-remaining'] ?? '') === '0' || stripos($body, 'rate limit') !== false));
    if ($rateLimited) return new AcademySourceError('חרגנו ממכסת הפניות של GitHub — נסו שוב בעוד כמה דקות', 'source_unavailable');
    return match (true) {
        $status === 401 => new AcademySourceError('האסימון של מאגר האקדמיה חסר או לא תקף (פג או בוטל)', 'source_unauthorized'),
        $status === 403 => new AcademySourceError('לאסימון אין הרשאת קריאה למאגר האקדמיה', 'source_unauthorized'),
        $status === 404 => new AcademySourceError('המאגר או קובץ ה-snapshot לא נמצאו (או שהם מוסתרים מהאסימון)', 'source_not_found'),
        $status >= 500  => new AcademySourceError("GitHub לא זמין כרגע ($status)", 'source_unavailable'),
        default         => new AcademySourceError("מאגר האקדמיה החזיר תשובה לא צפויה ($status)", 'source_unavailable'),
    };
}
