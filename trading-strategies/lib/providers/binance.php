<?php
/**
 * Binance — נתוני שוק ציבוריים, בלי מפתח (spec-stage-a 3.2).
 *
 * הכתובת: data-api.binance.vision — נקודת הקצה של Binance לנתוני שוק בלבד. api.binance.com מחזיר 451 לבקשות
 * מארה"ב (שם השרת שלנו), ונבדק ב-2026-11-07. זמן אמת: wss://data-stream.binance.vision, ישירות מהדפדפן.
 * גבולות נר: יום — 00:00 UTC, שבוע — מיום שני 00:00 UTC, חודש — ה-1 בחודש 00:00 UTC (כמו ש-Binance נותנת).
 */

declare(strict_types=1);

require_once __DIR__ . '/base.php';

final class TsProvBinance extends TsProvider
{
    private const BASE = 'https://data-api.binance.vision/api/v3';
    private const PER_CALL = 1000;
    private const QUOTES = ['USDT', 'USDC', 'FDUSD', 'BTC', 'EUR', 'ETH', 'BNB', 'TRY'];   // סדר בחיפוש

    public function meta(): array
    {
        return [
            'id' => 'binance',
            'name' => 'Binance',
            'markets' => ['crypto'],
            'tfs' => ['m1' => '1m', 'm5' => '5m', 'm15' => '15m', 'm30' => '30m', 'h1' => '1h', 'h4' => '4h',
                'd1' => '1d', 'w1' => '1w', 'mn1' => '1M'],
            'needs_key' => false,
            'key_help' => null,
            'limits' => ['per_min' => 600, 'per_day' => 200000],
            'delay' => 'realtime',
            'share' => true,
            'personal_copy' => true,
            'terms' => 'נתוני שוק ציבוריים בלי מפתח. מאגר משותף לכל המשתמשים — לפי הכרעת בעל המערכת (✅2, ✅4).',
            'bounds' => 'יום — 00:00 UTC; שבוע — מיום שני 00:00 UTC; חודש — ה-1 בחודש',
            'align' => 'utc',   // יישור נרות בעותק (3.7): הגבולות של bounds, ב-UTC
            'max_per_call' => self::PER_CALL,
        ];
    }

    /** אינדקס הסימולים (exchangeInfo, כ-6MB) — נמשך פעם ב-24 שעות ונשמר מקוצר; החיפוש עצמו מקומי עליו */
    private function index(): array
    {
        require_once dirname(__DIR__) . '/cache.php';
        $db = ts_cache();
        $st = $db->prepare("SELECT result, fetched_at FROM search WHERE provider = 'binance' AND cred_scope = '' AND q = '__index__'");
        $st->execute();
        $row = $st->fetch();
        if ($row && (int)$row['fetched_at'] > time() - 86400) return json_decode($row['result'], true) ?: [];
        $json = $this->getJson(self::BASE . '/exchangeInfo?permissions=SPOT&symbolStatus=TRADING');
        $index = [];
        foreach ($json['symbols'] ?? [] as $s) {
            if (($s['status'] ?? '') !== 'TRADING') continue;
            $index[] = [$s['symbol'], $s['baseAsset'] ?? '', $s['quoteAsset'] ?? ''];
        }
        $db->prepare("INSERT OR REPLACE INTO search (provider, cred_scope, q, result, fetched_at) VALUES ('binance', '', '__index__', ?, ?)")
            ->execute([json_encode($index), time()]);
        return $index;
    }

    public function search(string $q, ?string $secret): array
    {
        $q = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $q));
        if ($q === '') return [];
        $hits = [];
        foreach ($this->index() as [$sym, $base, $quote]) {
            $score = $sym === $q ? 0 : ($base === $q ? 1 : (str_starts_with($sym, $q) ? 2 : (str_contains($sym, $q) ? 3 : null)));
            if ($score === null) continue;
            $qp = array_search($quote, self::QUOTES, true);
            $hits[] = [$score, $qp === false ? 99 : $qp, ['symbol' => $sym, 'name' => "$base / $quote", 'market' => 'crypto',
                'currency' => $quote, 'exchange' => 'Binance', 'tz' => 'UTC']];
        }
        usort($hits, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_column(array_slice($hits, 0, 20), 2);
    }

    public function candles(string $symbol, string $tf, int $from, int $to, ?string $secret): array
    {
        $interval = $this->tfName($tf);
        $out = [];
        $start = $from;
        for ($page = 0; $page < 10 && $start <= $to; $page++) {
            $rows = $this->getJson(self::BASE . '/klines?' . http_build_query([
                'symbol' => $symbol, 'interval' => $interval, 'startTime' => $start * 1000, 'endTime' => $to * 1000, 'limit' => self::PER_CALL,
            ]));
            if (!is_array($rows) || !$rows) break;
            foreach ($rows as $k) {
                // [openTime ms, open, high, low, close, volume, closeTime, …]
                $out[] = ['t' => intdiv((int)$k[0], 1000), 'o' => self::num($k[1]), 'h' => self::num($k[2]),
                    'l' => self::num($k[3]), 'c' => self::num($k[4]), 'v' => self::num($k[5])];
            }
            if (count($rows) < self::PER_CALL) break;
            $start = intdiv((int)end($rows)[0], 1000) + 1;
        }
        return self::tidy($out, $from, $to);
    }

    public function quote(string $symbol, ?string $secret): array
    {
        $j = $this->getJson(self::BASE . '/ticker/price?' . http_build_query(['symbol' => $symbol]));
        return ['price' => self::num($j['price'] ?? '0'), 't' => time()];
    }

    public function stream(): ?array
    {
        // הדפדפן מחליף {symbol} באותיות קטנות ו-{interval} בשם של Binance
        return ['kind' => 'binance-kline', 'url' => 'wss://data-stream.binance.vision/ws/{symbol}@kline_{interval}'];
    }

    public function test(string $secret): bool
    {
        return true;   // אין מפתח
    }
}
