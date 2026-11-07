<?php
/**
 * ECB דרך Frankfurter — שערי הייחוס של הבנק המרכזי האירופי, פעם ביום (ימי עסקים), בלי מפתח (spec-stage-a 3.2).
 *
 * api.frankfurter.dev/v1 (הכתובת הישנה api.frankfurter.app מפנה אליה — נבדק 2026-11-07). קוד פתוח, בלי מפתח,
 * הנתונים פומביים של ה-ECB. נכס = זוג מטבעות "USDILS" (בסיס + ציטוט). יש רק נר יומי, והוא שער אחד: o=h=l=c, v=0.
 * גבול הנר: תאריך השער, 00:00 UTC (ה-ECB מפרסם סביב 16:00 שעון מרכז אירופה; הנר "נפתח" בתחילת היום).
 */

declare(strict_types=1);

require_once __DIR__ . '/base.php';

final class TsProvEcb extends TsProvider
{
    private const BASE = 'https://api.frankfurter.dev/v1';
    private const COMMON = ['USD', 'EUR', 'ILS', 'GBP', 'JPY', 'CHF'];

    public function meta(): array
    {
        return [
            'id' => 'ecb',
            'name' => 'ECB (Frankfurter)',
            'markets' => ['forex'],
            'tfs' => ['d1' => '1d'],
            'needs_key' => false,
            'key_help' => null,
            'limits' => ['per_min' => 60, 'per_day' => 20000],
            'delay' => 'eod',
            'share' => true,
            'personal_copy' => true,
            'terms' => 'שערי ייחוס פומביים של ה-ECB, דרך Frankfurter (קוד פתוח, בלי מפתח). מאגר משותף (✅2, ✅4).',
            'bounds' => 'יום — תאריך השער, 00:00 UTC. ימי עסקים בלבד',
            'max_per_call' => 5000,
        ];
    }

    private function currencies(): array
    {
        require_once dirname(__DIR__) . '/cache.php';
        $db = ts_cache();
        $st = $db->prepare("SELECT result, fetched_at FROM search WHERE provider = 'ecb' AND cred_scope = '' AND q = '__currencies__'");
        $st->execute();
        $row = $st->fetch();
        if ($row && (int)$row['fetched_at'] > time() - 86400) return json_decode($row['result'], true) ?: [];
        $cur = $this->getJson(self::BASE . '/currencies');
        if (!is_array($cur)) $cur = [];
        $db->prepare("INSERT OR REPLACE INTO search (provider, cred_scope, q, result, fetched_at) VALUES ('ecb', '', '__currencies__', ?, ?)")
            ->execute([json_encode($cur), time()]);
        return $cur;
    }

    private static function split(string $symbol): array
    {
        $s = strtoupper(preg_replace('/[^A-Za-z]/', '', $symbol));
        if (strlen($s) !== 6) throw new TsProviderError('ECB: זוג מטבעות לא תקין (למשל USDILS)');
        return [substr($s, 0, 3), substr($s, 3)];
    }

    public function search(string $q, ?string $secret): array
    {
        $cur = $this->currencies();
        $q = trim($q);
        $Q = strtoupper(preg_replace('/[^A-Za-z]/', '', $q));
        $pairs = [];
        if (strlen($Q) === 6 && isset($cur[substr($Q, 0, 3)], $cur[substr($Q, 3)])) $pairs[] = [substr($Q, 0, 3), substr($Q, 3)];
        $codes = [];
        foreach ($cur as $code => $name) {
            if ($Q === $code || ($q !== '' && mb_stripos((string)$name, $q) !== false)) $codes[] = $code;
        }
        foreach (array_slice($codes, 0, 5) as $code) {
            foreach (self::COMMON as $c) {
                if ($c === $code) continue;
                $pairs[] = [$code, $c];
                if (count($pairs) >= 20) break 2;
            }
        }
        $out = [];
        foreach ($pairs as [$a, $b]) {
            $out["$a$b"] = ['symbol' => "$a$b", 'name' => ($cur[$a] ?? $a) . ' → ' . ($cur[$b] ?? $b), 'market' => 'forex',
                'currency' => $b, 'exchange' => 'ECB', 'tz' => 'UTC'];
        }
        return array_values($out);
    }

    public function candles(string $symbol, string $tf, int $from, int $to, ?string $secret): array
    {
        $this->tfName($tf);
        [$base, $quote] = self::split($symbol);
        $d1 = gmdate('Y-m-d', $from);
        $d2 = gmdate('Y-m-d', $to);
        $j = $this->getJson(self::BASE . "/$d1..$d2?" . http_build_query(['from' => $base, 'to' => $quote]));
        $out = [];
        foreach ($j['rates'] ?? [] as $date => $rates) {
            if (!isset($rates[$quote]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) continue;
            $p = self::num($rates[$quote]);
            $out[] = ['t' => (int)gmmktime(0, 0, 0, (int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4)),
                'o' => $p, 'h' => $p, 'l' => $p, 'c' => $p, 'v' => '0'];
        }
        return self::tidy($out, $from, $to);
    }

    public function quote(string $symbol, ?string $secret): array
    {
        [$base, $quote] = self::split($symbol);
        $j = $this->getJson(self::BASE . '/latest?' . http_build_query(['from' => $base, 'to' => $quote]));
        $date = (string)($j['date'] ?? gmdate('Y-m-d'));
        return ['price' => self::num($j['rates'][$quote] ?? '0'), 't' => (int)strtotime($date . ' 00:00:00 UTC')];
    }

    public function test(string $secret): bool
    {
        return true;
    }
}
