<?php
/**
 * המרת מטבע לסטטיסטיקה (spec-stage-a 12 #8, ✅12): הרווח של כל עסקה סגורה מומר למטבע הבסיס שבפרופיל, **בשער ECB
 * של יום הסגירה** (היום המקומי של המשתמש). אין שער לאותו יום (סוף שבוע, חג, או שעוד לא פורסם) — האחרון שלפניו,
 * ומסומן. השערים נשמרים ב-fx_rates: שער של יום שעבר לא משתנה, ולכן פונים ל-ECB רק על ימים שעוד לא נמשכו.
 *
 * הכסף כאן — עשרוני (עיקרון 6): השער נשמר כמחרוזת כמו ש-ECB נתן, והמכפלה בחשבון העשרוני.
 * מטבעות יציבים (USDT, USDC…) — ה-ECB לא מפרסם להם שער. הם מחושבים כשווי-ערך לדולר, ומסומנים ככאלה (הכרעה של
 * כותב הקוד). מטבע אחר בלי שער — לא מומר, ומוצג בנפרד.
 */

declare(strict_types=1);

require_once __DIR__ . '/market.php';

/** מטבעות יציבים שנחשבים כדולר — מסומנים בתצוגה */
const TS_USD_PEGGED = ['USDT', 'USDC', 'FDUSD', 'BUSD', 'TUSD', 'DAI', 'USDP'];
const TS_FX_LOOKBACK_DAYS = 10;   // כמה ימים אחורה למשוך, כדי שיהיה "האחרון שלפניו" גם אחרי סוף שבוע ארוך

/** [מטבע אמיתי לשער, האם הנחת שווי-ערך] */
function ts_fx_real(string $cur): array
{
    return in_array($cur, TS_USD_PEGGED, true) ? ['USD', true] : [$cur, false];
}

/** מוודא שיש שערים לזוג בטווח הימים (פנייה אחת ל-ECB לכל היותר). זורק TsProviderError */
function ts_fx_ensure(int $uid, string $from, string $to, string $dayMin, string $dayMax): void
{
    $db = ts_db();
    $need0 = gmdate('Y-m-d', strtotime("$dayMin UTC") - TS_FX_LOOKBACK_DAYS * 86400);
    $today = gmdate('Y-m-d');
    $need1 = min($dayMax, $today);
    $st = $db->prepare('SELECT from_day, to_day, fetched_at FROM fx_cover WHERE base = ? AND quote = ?');
    $st->execute([$from, $to]);
    $cov = $st->fetch() ?: null;
    // היום (שאולי עוד לא פורסם) — נמשך שוב אחרי שעה
    $fresh = $cov && ($cov['to_day'] < $today || (int)$cov['fetched_at'] > time() - 3600);
    if ($cov && $cov['from_day'] <= $need0 && $cov['to_day'] >= $need1 && $fresh) return;
    $a = $cov ? min($cov['from_day'], $need0) : $need0;
    $b = $cov ? max($cov['to_day'], $need1) : $need1;
    $acc = ts_access($uid, 'ecb') ?? throw new TsProviderError('ECB כבוי — אין המרת מטבע');
    $rows = ts_provider_call($uid, 'ecb', $acc, fn($p, $s) => $p->candles("$from$to", 'd1', (int)strtotime("$a UTC"), (int)strtotime("$b 23:59:59 UTC"), $s));
    $ins = $db->prepare('INSERT OR REPLACE INTO fx_rates (base, quote, day, rate) VALUES (?, ?, ?, ?)');
    $db->beginTransaction();
    foreach ($rows as $r) {
        $rate = dec_parse($r['c'], 10);
        if ($rate !== null && dec_sign($rate) > 0) $ins->execute([$from, $to, gmdate('Y-m-d', $r['t']), $rate]);
    }
    $db->prepare('INSERT OR REPLACE INTO fx_cover (base, quote, from_day, to_day, fetched_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$from, $to, $a, $b, time()]);
    $db->commit();
}

/** השער של יום, או האחרון שלפניו: ['rate', 'day', 'exact'] — או null */
function ts_fx_lookup(string $from, string $to, string $day): ?array
{
    $st = ts_db()->prepare('SELECT day, rate FROM fx_rates WHERE base = ? AND quote = ? AND day <= ? ORDER BY day DESC LIMIT 1');
    $st->execute([$from, $to, $day]);
    $r = $st->fetch();
    return $r ? ['rate' => $r['rate'], 'day' => $r['day'], 'exact' => $r['day'] === $day] : null;
}

/**
 * הסטטיסטיקה של כל העסקאות הסגורות, במטבע הבסיס. מחזיר את ts_stats_one על הרווחים המומרים, ועוד: כמה הומרו, כמה
 * בשער של יום קודם, כמה כשווי-ערך לדולר, ואילו מטבעות לא הומרו. שגיאה מ-ECB — error, והסטטיסטיקה לפי מטבע נשארת.
 */
function ts_stats_in_base(int $uid, array $trades, string $base, string $tz): array
{
    $zone = new DateTimeZone($tz);
    $need = [];
    foreach ($trades as $i => $t) {
        $day = (new DateTimeImmutable($t['calc']['closed_at']))->setTimezone($zone)->format('Y-m-d');
        $trades[$i]['_day'] = $day;
        [$real] = ts_fx_real($t['currency']);
        [$baseReal] = ts_fx_real($base);
        if ($real === $baseReal || !preg_match('/^[A-Z]{3}$/', $real) || !preg_match('/^[A-Z]{3}$/', $baseReal)) continue;
        $k = "$real|$baseReal";
        $need[$k] = [min($need[$k][0] ?? $day, $day), max($need[$k][1] ?? $day, $day)];
    }
    $errors = [];
    foreach ($need as $k => [$d0, $d1]) {
        [$a, $b] = explode('|', $k);
        try {
            ts_fx_ensure($uid, $a, $b, $d0, $d1);
        } catch (TsProviderError $e) {
            $errors[] = $e->getMessage();
        }
    }
    usort($trades, fn($x, $y) => strcmp($x["calc"]["closed_at"], $y["calc"]["closed_at"]) ?: ($x["id"] <=> $y["id"]));
    $out = []; $approx = 0; $pegged = 0; $skipped = []; $rates = [];
    foreach ($trades as $t) {
        [$real, $peg] = ts_fx_real($t['currency']);
        [$baseReal, $basePeg] = ts_fx_real($base);
        if ($real === $baseReal) {
            $rate = ['rate' => '1', 'day' => $t['_day'], 'exact' => true];
        } else {
            $rate = preg_match('/^[A-Z]{3}$/', $real) && preg_match('/^[A-Z]{3}$/', $baseReal) ? ts_fx_lookup($real, $baseReal, $t['_day']) : null;
        }
        if (!$rate) { $skipped[$t['currency']] = ($skipped[$t['currency']] ?? 0) + 1; continue; }
        if (!$rate['exact']) $approx++;
        if ($peg || $basePeg) { if ($t['currency'] !== $base) $pegged++; }
        $t['calc']['net'] = dec_round(dec_mul($t['calc']['net'], $rate['rate']), 8);
        $rates[] = ['id' => (int)$t['id'], 'currency' => $t['currency'], 'rate' => $rate['rate'], 'rate_day' => $rate['day'],
            'closed_day' => $t['_day'], 'exact' => $rate['exact']];
        unset($t['_day']);
        $out[] = $t;
    }
    if (!$out) return ['currency' => $base, 'count' => 0, 'skipped' => $skipped, 'errors' => $errors];
    return ['currency' => $base] + ts_stats_one($out, $tz) + ['converted' => count($out), 'approx' => $approx, 'pegged' => $pegged,
        'skipped' => $skipped, 'errors' => $errors, 'rates' => $rates];
}
