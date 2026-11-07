<?php
/**
 * Finnhub — מניות ארה"ב, מט"ח, קריפטו. דורש מפתח (יש חינמי). ספק משלי (א) בשלב א'.
 *
 * המפתח בכותרת X-Finnhub-Token — לא בכתובת. אין מסגרת של 4 שעות.
 * ⚠️ בתוכנית החינמית נרות היסטוריים (candle) חסומים — הספק מחזיר 403 "You don't have access to this resource",
 * וזה מוצג למשתמש כמו שהוא. חיפוש ומחיר אחרון — כן בחינמי.
 * סימולים: מניה "AAPL"; מט"ח "OANDA:EUR_USD"; קריפטו "BINANCE:BTCUSDT" — מזה נגזרת נקודת הקצה.
 * זמנים: הספק נותן t בשניות UTC של פתיחת הנר.
 */

declare(strict_types=1);

require_once __DIR__ . '/base.php';

final class TsProvFinnhub extends TsProvider
{
    private const BASE = 'https://finnhub.io/api/v1';
    private const FX = ['OANDA', 'FXCM', 'FOREX', 'FXPRO', 'IC MARKETS', 'ICMTRADER', 'OCTAFX', 'PEPPERSTONE'];

    public function meta(): array
    {
        return [
            'id' => 'finnhub',
            'name' => 'Finnhub',
            'markets' => ['stocks', 'forex', 'crypto'],
            'tfs' => ['m1' => '1', 'm5' => '5', 'm15' => '15', 'm30' => '30', 'h1' => '60', 'd1' => 'D', 'w1' => 'W', 'mn1' => 'M'],
            'needs_key' => true,
            'key_help' => ['label' => 'API key', 'url' => 'https://finnhub.io/dashboard',
                'note' => 'נרשמים ב-finnhub.io ומעתיקים את ה-API key. בתוכנית החינמית אין נרות היסטוריים — רק חיפוש ומחיר אחרון.'],
            'limits' => ['per_min' => 60, 'per_day' => 50000],
            'delay' => 'realtime',
            'share' => false,
            'personal_copy' => true,
            'terms' => 'שימוש אישי בתוכנית החינמית. נתונים במפתח של משתמש מוגשים רק לו (cred_scope).',
            'bounds' => 'כמו שהספק נותן (t של פתיחת הנר ב-UTC)',
            'max_per_call' => 5000,
        ];
    }

    private function call(string $path, array $q, ?string $secret): mixed
    {
        $j = $this->getJson(self::BASE . $path . '?' . http_build_query($q), $secret !== null ? ['X-Finnhub-Token: ' . $secret] : [], $secret);
        if (is_array($j) && isset($j['error'])) throw new TsProviderError($this->clean('Finnhub: ' . mb_substr((string)$j['error'], 0, 200), $secret));
        return $j;
    }

    private static function kind(string $symbol): string
    {
        if (!str_contains($symbol, ':')) return 'stock';
        $ex = strtoupper(strstr($symbol, ':', true));
        return in_array($ex, self::FX, true) ? 'forex' : 'crypto';
    }

    public function search(string $q, ?string $secret): array
    {
        $j = $this->call('/search', ['q' => $q], $secret);
        $out = [];
        foreach (array_slice($j['result'] ?? [], 0, 20) as $r) {
            $sym = (string)($r['symbol'] ?? '');
            if ($sym === '') continue;
            $kind = self::kind($sym);
            $out[] = ['symbol' => $sym, 'name' => (string)($r['description'] ?? $sym),
                'market' => $kind === 'stock' ? 'stocks' : $kind, 'currency' => $kind === 'stock' ? 'USD' : '',
                'exchange' => $kind === 'stock' ? 'US' : strstr($sym, ':', true), 'tz' => $kind === 'stock' ? 'America/New_York' : 'UTC'];
        }
        return $out;
    }

    public function candles(string $symbol, string $tf, int $from, int $to, ?string $secret): array
    {
        $j = $this->call('/' . self::kind($symbol) . '/candle', ['symbol' => $symbol, 'resolution' => $this->tfName($tf), 'from' => $from, 'to' => $to], $secret);
        if (($j['s'] ?? '') === 'no_data') return [];
        if (($j['s'] ?? '') !== 'ok') throw new TsProviderError('Finnhub: תשובה לא צפויה');
        $out = [];
        foreach ($j['t'] ?? [] as $i => $t) {
            $out[] = ['t' => (int)$t, 'o' => self::num($j['o'][$i] ?? 0), 'h' => self::num($j['h'][$i] ?? 0), 'l' => self::num($j['l'][$i] ?? 0),
                'c' => self::num($j['c'][$i] ?? 0), 'v' => self::num($j['v'][$i] ?? 0)];
        }
        return self::tidy($out, $from, $to);
    }

    public function quote(string $symbol, ?string $secret): array
    {
        $j = $this->call('/quote', ['symbol' => $symbol], $secret);
        return ['price' => self::num($j['c'] ?? 0), 't' => (int)($j['t'] ?? time())];
    }

    public function test(string $secret): bool
    {
        $j = $this->call('/quote', ['symbol' => 'AAPL'], $secret);
        if (!is_array($j) || !isset($j['c'])) throw new TsProviderError('Finnhub: תשובה לא צפויה מבדיקת החיבור');
        return true;
    }
}
