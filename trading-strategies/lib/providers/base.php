<?php
/**
 * הממשק של מתאם ספק נתונים (spec-stage-a 3.1). כל ספק — קובץ אחד בתיקייה הזו, מחלקה שיורשת מ-TsProvider.
 * הוספת ספק = קובץ חדש + שורה ב-TS_PROVIDERS (lib/providers/registry.php). כל קוד הספקים — רק כאן.
 *
 * נר: ['t' => זמן פתיחה בשניות UTC, 'o','h','l','c','v' => מחרוזות עשרוניות כמו שהספק נתן]. ממוין לפי t, בלי כפילויות.
 * מסגרות זמן של האפליקציה: m1 m5 m15 m30 h1 h4 d1 w1 mn1. המתאם מצהיר ב-meta()['tfs'] אילו הוא תומך ומה שמן אצלו.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/http.php';
require_once dirname(__DIR__) . '/secret.php';

/** שגיאה מהספק — ההודעה כבר נקייה מהמפתח, ומוצגת למשתמש כמו שהיא */
final class TsProviderError extends RuntimeException {}

const TS_TF_SECONDS = ['m1' => 60, 'm5' => 300, 'm15' => 900, 'm30' => 1800, 'h1' => 3600, 'h4' => 14400,
    'd1' => 86400, 'w1' => 604800, 'mn1' => 2678400];   // חודש — 31 יום, לחישובי טווח בלבד

abstract class TsProvider
{
    /**
     * id, name, markets[], tfs{tf: שם אצל הספק}, needs_key, key_help{label, url, note}, limits{per_min, per_day},
     * delay (realtime|15min|eod), share (מותר להגיש לכל המשתמשים מאותו מאגר), personal_copy (מותר לשמור עותק אישי),
     * terms (מה בדקנו בתנאי השימוש), bounds (גבולות נר של יום/שבוע/חודש), max_per_call (נרות בקריאה אחת)
     */
    abstract public function meta(): array;

    /** @return list<array{symbol:string,name:string,market:string,currency:string,exchange:string,tz:string}> */
    abstract public function search(string $q, ?string $secret): array;

    /** נרות בטווח [from, to] (שניות UTC, כולל). המתאם מחלק לכמה קריאות לפי max_per_call. */
    abstract public function candles(string $symbol, string $tf, int $from, int $to, ?string $secret): array;

    /** @return array{price:string,t:int} */
    abstract public function quote(string $symbol, ?string $secret): array;

    /** WebSocket לזמן אמת ישירות מהדפדפן — רק כשאין מפתח (3.5). null — אין. */
    public function stream(): ?array { return null; }

    /** "בדוק חיבור": מחזיר true, או זורק TsProviderError עם הסבר */
    abstract public function test(string $secret): bool;

    // ── עזרים למתאמים ───────────────────────────────

    /** GET + JSON. שגיאה — TsProviderError בלי המפתח */
    protected function getJson(string $url, array $headers = [], ?string $secret = null): mixed
    {
        try {
            [$status, $body] = ts_http_get($url, $headers);
        } catch (TsHttpError $e) {
            throw new TsProviderError($this->clean($e->getMessage(), $secret));
        }
        $json = json_decode($body, true);
        if ($status >= 300 && $status < 400) {
            throw new TsProviderError($this->meta()['name'] . ': הספק הפנה לכתובת אחרת — הבקשה לא נשלחה הלאה, כדי שהמפתח לא יגיע למקום אחר.');
        }
        if ($status === 401 || $status === 403) throw new TsProviderError($this->clean($this->meta()['name'] . ': המפתח נדחה' . $this->detail($json), $secret));
        if ($status === 429) throw new TsProviderError($this->meta()['name'] . ': הספק הגביל את קצב הבקשות. נסו שוב בעוד דקה.');
        if ($status === 451) throw new TsProviderError($this->meta()['name'] . ': הספק חוסם בקשות מהאזור של השרת.');
        if ($status >= 400 || $json === null) {
            throw new TsProviderError($this->clean($this->meta()['name'] . ": שגיאה מהספק ($status)" . $this->detail($json), $secret));
        }
        return $json;
    }

    private function detail(mixed $json): string
    {
        $m = is_array($json) ? ($json['message'] ?? $json['msg'] ?? $json['error'] ?? null) : null;
        return is_string($m) && $m !== '' ? ' — ' . mb_substr($m, 0, 200) : '';
    }

    protected function clean(string $msg, ?string $secret): string
    {
        return $secret !== null ? ts_scrub($msg, [$secret]) : $msg;
    }

    /** מחרוזת עשרונית מהספק — מספר מ-JSON הופך למחרוזת בלי לאבד ספרות שהספק שלח כטקסט */
    protected static function num(mixed $v): string
    {
        if (is_string($v)) return trim($v);
        if (is_int($v)) return (string)$v;
        if (is_float($v)) return self::floatStr($v);
        return '0';
    }

    /**
     * float מ-JSON → המחרוזת הקצרה ביותר שחוזרת לאותו מספר (serialize_precision = -1), כלומר מה שהספק כתב
     * (3.1259 ולא 3.12590000000000012). מעריך (1.5e-5) נפרש לנקודה עשרונית רגילה.
     */
    public static function floatStr(float $v): string
    {
        if (!is_finite($v)) return '0';
        $s = strtolower((string)json_encode($v));
        if (!str_contains($s, 'e')) return str_ends_with($s, '.0') ? substr($s, 0, -2) : $s;
        [$m, $e] = explode('e', $s);
        $neg = str_starts_with($m, '-');
        $m = ltrim($m, '-');
        [$int, $frac] = explode('.', $m . (str_contains($m, '.') ? '' : '.'));
        $digits = $int . $frac;
        $point = strlen($int) + (int)$e;
        if ($point <= 0) $r = '0.' . str_repeat('0', -$point) . $digits;
        elseif ($point >= strlen($digits)) $r = $digits . str_repeat('0', $point - strlen($digits));
        else $r = substr($digits, 0, $point) . '.' . substr($digits, $point);
        $r = str_contains($r, '.') ? rtrim(rtrim($r, '0'), '.') : $r;
        $r = ltrim($r, '0');
        if ($r === '' || $r[0] === '.') $r = '0' . $r;
        return ($neg && $r !== '0' ? '-' : '') . $r;
    }

    protected function tfName(string $tf): string
    {
        $tfs = $this->meta()['tfs'];
        if (!isset($tfs[$tf])) throw new TsProviderError($this->meta()['name'] . ': מסגרת הזמן לא נתמכת אצל הספק');
        return $tfs[$tf];
    }

    /** ממיין, מסיר כפילויות ומסנן לטווח */
    protected static function tidy(array $rows, int $from, int $to): array
    {
        $by = [];
        foreach ($rows as $r) if ($r['t'] >= $from && $r['t'] <= $to) $by[$r['t']] = $r;
        ksort($by);
        return array_values($by);
    }
}
