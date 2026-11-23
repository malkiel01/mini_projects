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
 * האסימון לעולם לא יוצא מהקובץ הזה: לא בתשובה, לא בלוג ולא בהודעת שגיאה (academy_redact).
 * אין כאן שום פעולת כתיבה למאגר — רק GET.
 */

declare(strict_types=1);

const ACADEMY_GITHUB_PHASE_ALLOWED = false;
const ACADEMY_DIR = __DIR__ . '/..';

final class AcademySourceError extends RuntimeException {}

interface AcademySource {
    /** שם המקור כפי שמוצג ל-API: "local" / "github". */
    public function name(): string;
    /** האם הנתונים הם דוגמה — ה-UI מציג פס קבוע כשכן. */
    public function isSample(): bool;
    /** ה-snapshot כמבנה PHP (אחרי json_decode), לפני אימות. */
    public function fetch(): mixed;
}

/** קונפיג ברירת מחדל — גם כשאין data/config.json בכלל. */
function academy_default_config(): array {
    return [
        'source'            => 'local',
        'github_enabled'    => false,
        'privacy_confirmed' => false,
        'github'            => [
            'owner' => 'malkiel01',
            'repo'  => 'offensive-cyber-academy',
            'ref'   => 'main',
            'path'  => 'dashboard/data/snapshot.json',
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
        error_log('cyber-academy: data/config.json אינו JSON תקין — נטען קונפיג ברירת מחדל');
        return $cfg;
    }
    $github = is_array($raw['github'] ?? null) ? $raw['github'] + $cfg['github'] : $cfg['github'];
    return array_merge($cfg, array_intersect_key($raw, $cfg), ['github' => $github]);
}

/** האם מקור github מותר לפעול. שלושה תנאים — ראו בראש הקובץ. */
function academy_github_allowed(array $config): bool {
    return ACADEMY_GITHUB_PHASE_ALLOWED
        && ($config['github_enabled'] ?? false) === true
        && ($config['privacy_confirmed'] ?? false) === true;
}

/** בוחר את המקור. כל מה שאינו github מותר — local. */
function academy_source(array $config, string $demo = ''): AcademySource {
    if (($config['source'] ?? 'local') === 'github' && academy_github_allowed($config)) {
        return new GithubAcademySource($config['github'], ACADEMY_DIR . '/data/cache', (int) ($config['cache_ttl'] ?? 120));
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

    public function __construct(string $file = 'demo-snapshot.json') {
        // רק שם קובץ בתוך samples/ — בלי נתיבים
        $this->file = ACADEMY_DIR . '/samples/' . basename($file);
    }
    public function name(): string { return 'local'; }
    public function isSample(): bool { return true; }
    public function fetch(): mixed {
        if (!is_file($this->file)) throw new AcademySourceError('קובץ הדוגמה לא נמצא');
        $data = json_decode((string) file_get_contents($this->file), true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new AcademySourceError('קובץ הדוגמה אינו JSON תקין: ' . json_last_error_msg());
        }
        return $data;
    }
}

final class GithubAcademySource implements AcademySource {
    /** @var callable|null fn(string $url, array $headers): array{status:int, body:string} */
    private $transport;

    public function __construct(private array $gh, private string $cacheDir, private int $ttl = 120,
                                ?callable $transport = null) {
        $this->transport = $transport;
    }
    public function name(): string { return 'github'; }
    public function isSample(): bool { return false; }

    public function fetch(): mixed {
        $token = (string) ($this->gh['token'] ?? '');
        try {
            if ($token === '') throw new AcademySourceError('לא הוגדר אסימון גישה למאגר האקדמיה');
            foreach (['owner', 'repo', 'path'] as $k) {
                if (!preg_match('~^[A-Za-z0-9._/-]+$~', (string) ($this->gh[$k] ?? '')) || str_contains((string) $this->gh[$k], '..')) {
                    throw new AcademySourceError("הגדרת github.$k אינה תקינה");
                }
            }
            $cached = $this->readCache();
            if ($cached !== null) return $cached;

            $url = sprintf('https://api.github.com/repos/%s/%s/contents/%s?ref=%s',
                rawurlencode($this->gh['owner']), rawurlencode($this->gh['repo']),
                implode('/', array_map('rawurlencode', explode('/', $this->gh['path']))),
                rawurlencode((string) ($this->gh['ref'] ?? 'main')));
            $res = ($this->transport ?? [$this, 'curl'])($url, [
                'Accept: application/vnd.github.raw+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: cyber-academy-dashboard',
                'Authorization: Bearer ' . $token,
            ]);
            $status = (int) ($res['status'] ?? 0);
            if ($status !== 200) throw new AcademySourceError(match ($status) {
                401 => 'מאגר האקדמיה דחה את האסימון — ייתכן שפג או בוטל',
                403 => 'אין לאסימון הרשאת קריאה לקובץ, או שנחרגה מכסת הפניות',
                404 => 'קובץ ה-snapshot לא נמצא במאגר, או שאין לאסימון גישה אליו',
                default => "מאגר האקדמיה החזיר שגיאה ($status)",
            });
            $body = (string) ($res['body'] ?? '');
            $data = json_decode($body, true);
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new AcademySourceError('ה-snapshot במאגר אינו JSON תקין');
            }
            $this->writeCache($body);
            return $data;
        } catch (Throwable $e) {
            // כל הודעה שיוצאת מכאן — בלי האסימון
            throw new AcademySourceError(academy_redact($e->getMessage(), $token));
        }
    }

    private function cacheFile(): string {
        return $this->cacheDir . '/snapshot-' . substr(hash('sha256', json_encode([
            $this->gh['owner'], $this->gh['repo'], $this->gh['ref'] ?? '', $this->gh['path']])), 0, 16) . '.json';
    }
    private function readCache(): mixed {
        $f = $this->cacheFile();
        if ($this->ttl <= 0 || !is_file($f) || filemtime($f) < time() - $this->ttl) return null;
        return json_decode((string) file_get_contents($f), true);
    }
    private function writeCache(string $body): void {
        if ($this->ttl <= 0) return;
        if (!is_dir($this->cacheDir)) @mkdir($this->cacheDir, 0750, true);
        @file_put_contents($this->cacheFile(), $body, LOCK_EX);
    }

    /** @return array{status:int, body:string} */
    private function curl(string $url, array $headers): array {
        if (!function_exists('curl_init')) throw new AcademySourceError('cURL אינו זמין בשרת');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) throw new AcademySourceError('הפנייה למאגר האקדמיה נכשלה');
        return ['status' => $status, 'body' => (string) $body];
    }
}
