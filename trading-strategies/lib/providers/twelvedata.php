<?php
/**
 * Twelve Data — מניות, מט"ח, קריפטו ומדדים. דורש מפתח (יש חינמי). ספק משלי (א) בשלב א'.
 *
 * המפתח בכותרת "Authorization: apikey …" — לא בכתובת (נבדק מול ה-API, 2026-11-07).
 * שגיאה לפעמים חוזרת ב-HTTP 200 עם status: "error" — נבדק כאן.
 * זמנים: מבקשים timezone=UTC. תוך-יומי — datetime הוא זמן הפתיחה ב-UTC. יום/שבוע/חודש — datetime הוא תאריך
 * המסחר; t = חצות של התאריך באזור הזמן של הבורסה (meta.exchange_timezone), ב-UTC.
 * מאגר משותף (spec-stage-a 21.3, ✅31): הבעלים הכריע "משותף" — share=true, וכל מה שנמשך, בכל מפתח, נשמר ב-''.
 * הסייג של ✅2 (מפתח אישי — רק לבעליו) בוטל ל-Twelve Data בלבד.
 */

declare(strict_types=1);

require_once __DIR__ . '/base.php';

final class TsProvTwelveData extends TsProvider
{
    private const BASE = 'https://api.twelvedata.com';

    public function meta(): array
    {
        return [
            'id' => 'twelvedata',
            'name' => 'Twelve Data',
            'markets' => ['stocks', 'forex', 'crypto', 'indices'],
            'tfs' => ['m1' => '1min', 'm5' => '5min', 'm15' => '15min', 'm30' => '30min', 'h1' => '1h', 'h4' => '4h',
                'd1' => '1day', 'w1' => '1week', 'mn1' => '1month'],
            'needs_key' => true,
            'key_help' => ['label' => 'API key', 'url' => 'https://twelvedata.com/account/api-keys',
                'note' => 'נרשמים בחינם ב-twelvedata.com, ומעתיקים את ה-API key מהחשבון.'],
            'limits' => ['per_min' => 8, 'per_day' => 800],   // התוכנית החינמית; המנהל יכול לדרוס
            'delay' => 'realtime',
            'share' => true,   // 21.3 (✅31): מאגר אחד, '' — בכל מפתח
            'terms' => 'מאגר משותף (הכרעה ✅31): מה שנמשך — במפתח האפליקציה או במפתח אישי — נשמר לכולם. פונים לספק רק על החסר.',
            'bounds' => 'יום/שבוע/חודש — לפי מושב המסחר, חצות באזור הזמן של הבורסה',
            'max_per_call' => 5000,
        ];
    }

    private function headers(?string $secret): array
    {
        return $secret !== null ? ['Authorization: apikey ' . $secret] : [];
    }

    private function call(string $path, array $q, ?string $secret): array
    {
        $j = $this->getJson(self::BASE . $path . '?' . http_build_query($q), $this->headers($secret), $secret);
        if (!is_array($j)) throw new TsProviderError('Twelve Data: תשובה לא צפויה');
        if (($j['status'] ?? '') === 'error') {
            $code = (int)($j['code'] ?? 0);
            $msg = $code === 401 || $code === 403 ? 'המפתח נדחה' : ($code === 429 ? 'הגעתם לתקרת הבקשות של הספק' : 'שגיאה מהספק');
            $text = $this->clean("Twelve Data: $msg — " . mb_substr((string)($j['message'] ?? ''), 0, 200), $secret);
            throw $code === 429 ? new TsRateError($text) : new TsProviderError($text);
        }
        return $j;
    }

    private static function market(string $type): string
    {
        $t = strtolower($type);
        return match (true) {
            str_contains($t, 'digital') || str_contains($t, 'crypto') => 'crypto',
            str_contains($t, 'physical currency') || str_contains($t, 'forex') => 'forex',
            str_contains($t, 'index') => 'indices',
            default => 'stocks',
        };
    }

    public function search(string $q, ?string $secret): array
    {
        $j = $this->call('/symbol_search', ['symbol' => $q, 'outputsize' => 20], $secret);
        $out = [];
        foreach ($j['data'] ?? [] as $d) {
            $out[] = ['symbol' => (string)$d['symbol'], 'name' => (string)($d['instrument_name'] ?? $d['symbol']),
                'market' => self::market((string)($d['instrument_type'] ?? '')), 'currency' => (string)($d['currency'] ?? ''),
                'exchange' => (string)($d['exchange'] ?? ''), 'tz' => (string)($d['exchange_timezone'] ?? 'UTC')];
        }
        return $out;
    }

    public function candles(string $symbol, string $tf, int $from, int $to, ?string $secret): array
    {
        $j = $this->call('/time_series', [
            'symbol' => $symbol, 'interval' => $this->tfName($tf), 'timezone' => 'UTC', 'order' => 'ASC', 'outputsize' => 5000,
            'start_date' => gmdate('Y-m-d H:i:s', $from), 'end_date' => gmdate('Y-m-d H:i:s', $to),
        ], $secret);
        $daily = in_array($tf, ['d1', 'w1', 'mn1'], true);
        try {
            $zone = new DateTimeZone((string)($j['meta']['exchange_timezone'] ?? 'UTC'));
        } catch (Exception) {
            $zone = new DateTimeZone('UTC');
        }
        $out = [];
        foreach ($j['values'] ?? [] as $v) {
            $dt = (string)$v['datetime'];
            $t = $daily
                ? (new DateTimeImmutable(substr($dt, 0, 10) . ' 00:00:00', $zone))->getTimestamp()
                : (new DateTimeImmutable($dt, new DateTimeZone('UTC')))->getTimestamp();
            $out[] = ['t' => $t, 'o' => self::num($v['open']), 'h' => self::num($v['high']), 'l' => self::num($v['low']),
                'c' => self::num($v['close']), 'v' => self::num($v['volume'] ?? '0')];
        }
        // ביום/שבוע/חודש הנר מתחיל בחצות של הבורסה — מוקדם מ-from שביקשו בשעות; לא לחתוך אותו
        return self::tidy($out, $daily ? $from - 86400 : $from, $to);
    }

    public function quote(string $symbol, ?string $secret): array
    {
        $j = $this->call('/price', ['symbol' => $symbol], $secret);
        return ['price' => self::num($j['price'] ?? '0'), 't' => time()];
    }

    public function test(string $secret): bool
    {
        $j = $this->call('/api_usage', [], $secret);
        if (!isset($j['plan_limit']) && !isset($j['current_usage'])) throw new TsProviderError('Twelve Data: תשובה לא צפויה מבדיקת החיבור');
        return true;
    }
}
