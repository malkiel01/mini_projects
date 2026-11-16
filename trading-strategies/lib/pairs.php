<?php
/**
 * בחירת זוג בשם (spec-stage-a 18.1, S1; owner-brief 2ג.1). המשתמש רואה את שני הצדדים מופרדים, לא BTCUSDT.
 *
 * **התצוגה (18.1.1, S1.1):** כל תווית יוצאת עם base ו-quote ({code, name}) — הדפדפן מצייר שורה ראשית `BTC ⇄ USDT`
 * (אייקון SVG, משמאל לימין, הבסיס ראשון) ומתחתיה השמות בעברית. המילה "מול" לא מוצגת לעולם. לטקסט רגיל (פוש, משפט) —
 * 'label' = "BTC ⇄ USDT" ו-'name' = "ביטקוין ⇄ טתר"; הדפדפן מחליף את התו ⇄ באייקון.
 * בפנים הנכס נשאר provider:symbol (פרק 2) — הקטלוג רק ממפה שמות לנכסים.
 *
 * - **צד** — קוד (BTC, USD, ILS) עם שם עברי ואנגלי וסוג (crypto / currency).
 * - **זוג** — "BASE/QUOTE" ולו רשימת נכסים בסדר העדפה. למשתמש נבחר הראשון שיש לו אליו ספק (ts_access), ומי שבחר
 *   ספק אחר לאותו סימול ("מאיזה ספק?", symbol_prefs) — מקבל את שלו.
 * - **זוג מחושב** (18.1 קריטריון 2): כשלספק יש רק כיוון אחד — הכיוון ההפוך הוא 1/x של הראשון, והסימול שלו
 *   "1/<symbol>". ts_market_candles הופך את הנרות. [שלנו] זה המקרה היחיד של זוג מחושב: לא בונים זוג משני זוגות (✅28).
 * - **זוג בלי ספק** — אין לו נכס למשתמש: מוצג "אין ספק לזוג הזה", ולא נשלחת בקשה לספק.
 * - **מניות** — לא בקטלוג: מגיעות מהחיפוש של הספק (market_search), עם שם החברה ושם המטבע (ts_pair_label).
 *
 * [שלנו] "דולר" בקריפטו ב-Binance הוא USDC — מטבע דולרי; ל-Binance אין זוג מול דולר עצמו. Twelve Data (מפתח אישי) נותן
 * BTC/USD אמיתי, ולכן הוא הראשון ברשימה. טתר (USDT) — צד נפרד, כמו שהאפיון כותב ("מול דולר ומול טתר").
 */

declare(strict_types=1);

/** הצדדים: קוד → [עברית, אנגלית, סוג]. הסדר — הסדר ברשימה. ביטקוין ראשון: ברירת המחדל (18.1) */
const TS_PAIR_SIDES = [
    'BTC' => ['ביטקוין', 'Bitcoin', 'crypto'],
    'ETH' => ["את'ריום", 'Ethereum', 'crypto'],
    'SOL' => ['סולנה', 'Solana', 'crypto'],
    'XRP' => ['ריפל', 'XRP Ripple', 'crypto'],
    'BNB' => ['בינאנס קוין', 'BNB Binance Coin', 'crypto'],
    'ADA' => ['קרדנו', 'Cardano', 'crypto'],
    'DOGE' => ["דוג'קוין", 'Dogecoin', 'crypto'],
    'LTC' => ['לייטקוין', 'Litecoin', 'crypto'],
    // עוד 12 — כדי שבלשונית "₿ קריפטו" יהיו 20 הגדולים (18.1.4). רק מול טתר: לא לכולם יש זוג USDC ב-Binance
    'TRX' => ['טרון', 'TRON', 'crypto'],
    'AVAX' => ["אוולנץ'", 'Avalanche', 'crypto'],
    'LINK' => ["צ'יינלינק", 'Chainlink', 'crypto'],
    'DOT' => ['פולקדוט', 'Polkadot', 'crypto'],
    'TON' => ['טון', 'Toncoin', 'crypto'],
    'SHIB' => ['שיבה אינו', 'Shiba Inu', 'crypto'],
    'BCH' => ['ביטקוין קאש', 'Bitcoin Cash', 'crypto'],
    'NEAR' => ['ניר', 'NEAR Protocol', 'crypto'],
    'UNI' => ['יוניסוואפ', 'Uniswap', 'crypto'],
    'XLM' => ['סטלר', 'Stellar', 'crypto'],
    'ATOM' => ['קוסמוס', 'Cosmos', 'crypto'],
    'ETC' => ["את'ריום קלאסיק", 'Ethereum Classic', 'crypto'],
    'USD' => ['דולר', 'US Dollar', 'currency'],
    'EUR' => ['אירו', 'Euro', 'currency'],
    'ILS' => ['שקל', 'Israeli Shekel', 'currency'],
    'GBP' => ['לירה שטרלינג', 'British Pound', 'currency'],
    'JPY' => ['ין יפני', 'Japanese Yen', 'currency'],
    'CHF' => ['פרנק שוויצרי', 'Swiss Franc', 'currency'],
    'USDT' => ['טתר', 'Tether', 'crypto'],
];

/** שמות של מטבעות נוספים — לתווית של מניה או של זוג מהחיפוש (מחיר "מול" המטבע שלה) */
const TS_PAIR_EXTRA_NAMES = [
    'USDC' => 'דולר (USDC)', 'CAD' => 'דולר קנדי', 'AUD' => 'דולר אוסטרלי', 'CNY' => 'יואן', 'HKD' => 'דולר הונג קונגי',
    'INR' => 'רופי', 'TRY' => 'לירה טורקית', 'FDUSD' => 'דולר (FDUSD)',
];

/** המפריד בטקסט רגיל. בממשק — האייקון assets/icon-vs.svg במקומו (18.1.1) */
const TS_PAIR_SEP = ' ⇄ ';

/** הזוגות הקבועים: "BASE/QUOTE" → נכסים בסדר העדפה. נבנה פעם אחת לכל ריצה */
function ts_pairs_catalog(): array
{
    static $cat = null;
    if ($cat !== null) return $cat;
    $raw = [];
    // קריפטו מול דולר ומול טתר (Binance; Twelve Data ל-BTC/USD אמיתי עם מפתח)
    foreach (['BTC', 'ETH', 'SOL', 'XRP', 'BNB', 'ADA', 'DOGE', 'LTC'] as $c) {
        $raw["$c/USD"] = ["twelvedata:$c/USD", "binance:{$c}USDC"];
        $raw["$c/USDT"] = ["binance:{$c}USDT"];
    }
    foreach (['TRX', 'AVAX', 'LINK', 'DOT', 'TON', 'SHIB', 'BCH', 'NEAR', 'UNI', 'XLM', 'ATOM', 'ETC'] as $c) $raw["$c/USDT"] = ["binance:{$c}USDT"];
    // מט"ח עיקרי — כל כיוון, ECB (יומי, בלי מפתח) ו-Twelve Data (תוך-יומי, מפתח אישי)
    $fx = ['USD', 'EUR', 'ILS', 'GBP', 'JPY', 'CHF'];
    foreach ($fx as $a) foreach ($fx as $b) {
        if ($a !== $b) $raw["$a/$b"] = ["twelvedata:$a/$b", "ecb:$a$b"];
    }
    return $cat = ts_pairs_build($raw);
}

/**
 * מוסיף לכל זוג את הכיוון ההפוך כשאין לו נכס משלו: אותם נכסים, מחושבים (1/x). רק בין שני מטבעות (סוג currency) —
 * "דולר מול ביטקוין" אינו זוג שמישהו מבקש. מחזיר "BASE/QUOTE" → ['assets' => [...], 'computed' => bool].
 */
function ts_pairs_build(array $raw): array
{
    $out = [];
    foreach ($raw as $key => $assets) $out[$key] = ['assets' => $assets, 'computed' => false];
    foreach ($raw as $key => $assets) {
        [$b, $q] = explode('/', $key);
        if (isset($out["$q/$b"]) || (TS_PAIR_SIDES[$b][2] ?? '') !== 'currency' || (TS_PAIR_SIDES[$q][2] ?? '') !== 'currency') continue;
        $out["$q/$b"] = ['assets' => array_map(fn($a) => ts_pair_invert_asset($a), $assets), 'computed' => true];
    }
    return $out;
}

/** "ecb:USDILS" ↔ "ecb:1/USDILS" */
function ts_pair_invert_asset(string $asset): string
{
    [$p, $s] = explode(':', $asset, 2);
    return str_starts_with($s, '1/') ? "$p:" . substr($s, 2) : "$p:1/$s";
}

function ts_pair_side_name(string $code): string
{
    return TS_PAIR_SIDES[$code][0] ?? TS_PAIR_EXTRA_NAMES[$code] ?? $code;
}

function ts_pair_name(string $key): string
{
    [$b, $q] = explode('/', $key);
    return ts_pair_side_name($b) . TS_PAIR_SEP . ts_pair_side_name($q);
}

/** שני הצדדים של זוג מהקטלוג: {code, name} לכל אחד, ו-label/name לטקסט רגיל */
function ts_pair_view(string $key): array
{
    [$b, $q] = explode('/', $key);
    return ts_pair_view_of(['code' => $b, 'name' => ts_pair_side_name($b)], ['code' => $q, 'name' => ts_pair_side_name($q)]);
}

function ts_pair_view_of(array $base, array $quote): array
{
    return ['label' => $base['code'] . TS_PAIR_SEP . $quote['code'], 'name' => $base['name'] . TS_PAIR_SEP . $quote['name'],
        'base' => $base, 'quote' => $quote];
}

/** נכס → הזוג בקטלוג (או null). אותו נכס יכול להופיע רק בזוג אחד */
function ts_pair_of_asset(string $asset): ?string
{
    static $rev = null;
    if ($rev === null) {
        $rev = [];
        foreach (ts_pairs_catalog() as $key => $p) foreach ($p['assets'] as $a) $rev[$a] ??= $key;
    }
    return $rev[$asset] ?? null;
}

/**
 * הנכס של זוג למשתמש הזה: הראשון שיש לו אליו ספק, ומי שבחר ספק לאותו סימול — שלו קודם. null — אין ספק לזוג.
 * $access — לבדיקות: provider → bool. בלי פנייה לספק.
 */
function ts_pair_asset(int $uid, string $key, ?array $access = null): ?string
{
    $pair = ts_pairs_catalog()[$key] ?? null;
    if (!$pair) return null;
    $ok = array_values(array_filter($pair['assets'], fn($a) => $access !== null
        ? !empty($access[explode(':', $a, 2)[0]]) : ts_access($uid, explode(':', $a, 2)[0]) !== null));
    if (!$ok) return null;
    if ($access === null) {
        $st = ts_db()->prepare('SELECT provider_id FROM symbol_prefs WHERE user_id = ? AND norm = ?');
        $st->execute([$uid, ts_norm_symbol(explode(':', $ok[0], 2)[1])]);
        $pref = $st->fetchColumn();
        foreach ($ok as $a) if ($pref && str_starts_with($a, "$pref:")) return $a;
    }
    return $ok[0];
}

/**
 * מה המשתמש בוחר (18.1): "מה" — כל הצדדים שיש להם לפחות זוג אחד; לכל אחד "מול מה" — רק מה שיש לו ספק למשתמש.
 * זוג שאין לו ספק לא מופיע ב"מול מה". אין כאן סימולים — רק קוד הצד, שם ונכס פנימי.
 */
function ts_pairs_for(int $uid, ?array $access = null): array
{
    $bases = [];
    foreach (ts_pairs_catalog() as $key => $p) {
        [$b, $q] = explode('/', $key);
        $asset = ts_pair_asset($uid, $key, $access);
        $bases[$b] ??= ['code' => $b, 'name' => ts_pair_side_name($b), 'en' => TS_PAIR_SIDES[$b][1] ?? $b,
            'kind' => TS_PAIR_SIDES[$b][2] ?? 'currency', 'quotes' => []];
        if ($asset) $bases[$b]['quotes'][] = ['code' => $q, 'name' => ts_pair_side_name($q), 'en' => TS_PAIR_SIDES[$q][1] ?? $q,
            'pair' => $key, 'asset' => $asset, 'computed' => $p['computed']] + ts_pair_view($key);
    }
    $order = array_flip(array_keys(TS_PAIR_SIDES));
    uasort($bases, fn($x, $y) => ($order[$x['code']] ?? 99) <=> ($order[$y['code']] ?? 99));
    foreach ($bases as &$b) usort($b['quotes'], fn($x, $y) => ($order[$x['code']] ?? 99) <=> ($order[$y['code']] ?? 99));
    return ['bases' => array_values($bases), 'default' => 'BTC'];
}

/**
 * "ביטקוין מול ין", "btc/jpy", "Bitcoin vs Yen" → הזוג בקטלוג, או null אם אחד הצדדים לא מוכר. חיפוש בעברית ובאנגלית.
 * מחזיר ['pair' => key, 'known' => bool] כשמוכרים שני הצדדים: known=false — אין זוג כזה (הודעה, בלי ספק).
 */
function ts_pair_parse(string $text): ?array
{
    $parts = preg_split('/\s+(?:מול|vs\.?|versus|against)\s+|\s*\/\s*/iu', trim($text));
    if (count($parts) !== 2) return null;
    $codes = array_map('ts_pair_side_find', $parts);
    if (in_array(null, $codes, true) || $codes[0] === $codes[1]) return null;
    $key = $codes[0] . '/' . $codes[1];
    return ['pair' => $key, 'known' => isset(ts_pairs_catalog()[$key])] + ts_pair_view($key);
}

function ts_pair_side_find(string $s): ?string
{
    $s = mb_strtolower(trim($s));
    if ($s === '') return null;
    foreach (TS_PAIR_SIDES as $code => [$he, $en]) {
        if ($s === mb_strtolower($code) || $s === mb_strtolower($he) || $s === mb_strtolower($en)
            || in_array($s, array_map('mb_strtolower', explode(' ', $en)), true)) return $code;
    }
    // "ין" מול "ין יפני", "פרנק" מול "פרנק שוויצרי"
    foreach (TS_PAIR_SIDES as $code => [$he]) if (mb_strlen($s) >= 2 && str_starts_with(mb_strtolower($he), $s)) return $code;
    return null;
}

/**
 * התווית של נכס לתצוגה — בלי סימול מחובר (18.1 קריטריון 1, 18.1.1). מהקטלוג; אחרת מהחיפוש (asset_meta): מניה —
 * הסימול של החברה (AAPL) ושם החברה מול המטבע; קריפטו/מט"ח שלא בקטלוג — שני הצדדים מהשם של הספק ("ETH / BTC").
 * מחזיר label, name, base, quote ו-computed. בלי מידע על הנכס — 'נכס', בלי base/quote.
 */
function ts_pair_label(string $asset): array
{
    $key = ts_pair_of_asset($asset);
    if ($key !== null) return ts_pair_view($key) + ['computed' => ts_pairs_catalog()[$key]['computed']];
    $computed = false;
    if (preg_match('/^([a-z0-9]+):1\/(.+)$/', $asset, $m)) { $asset = "$m[1]:$m[2]"; $computed = true; }
    $meta = function_exists('ts_asset_meta') ? ts_asset_meta($asset) : null;
    // מניה, מדד או סחורה מהרשימות של הלשוניות (18.1.4) — גם בלי חיפוש קודם
    if (!$meta && function_exists('ts_tab_list_label') && ($l = ts_tab_list_label($asset))) {
        if ($computed) [$l['base'], $l['quote']] = [$l['quote'], $l['base']];
        return ts_pair_view_of($l['base'], $l['quote']) + ['computed' => $computed];
    }
    if (!$meta) return ['label' => 'נכס', 'name' => 'נכס', 'base' => null, 'quote' => null, 'computed' => $computed];
    $parts = preg_split('/\s*(?:\/|→)\s*/u', (string)$meta['name']);
    if (in_array($meta['market'], ['crypto', 'forex'], true) && count($parts) === 2) {
        [$b, $q] = array_map(fn($x) => strtoupper(trim($x)), $parts);
        $base = ['code' => $b, 'name' => ts_pair_side_name($b)];
        $quote = ['code' => $q, 'name' => ts_pair_side_name($q)];
    } else {
        $sym = explode(':', $asset, 2)[1] ?? '';
        $base = ['code' => strtoupper(explode('.', $sym)[0]), 'name' => (string)$meta['name']];
        $cur = strtoupper((string)$meta['currency']);
        $quote = ['code' => $cur !== '' ? $cur : '—', 'name' => $cur !== '' ? ts_pair_side_name($cur) : ''];
    }
    if ($computed) [$base, $quote] = [$quote, $base];
    return ts_pair_view_of($base, $quote) + ['computed' => $computed];
}

/** רק שדות התצוגה (label, name, base, quote) — לצירוף לתשובה שיש בה כבר name או computed משלה */
function ts_pair_pick(array $l): array
{
    return ['label' => $l['label'], 'pair_name' => $l['name'], 'base' => $l['base'], 'quote' => $l['quote']];
}

/** תוויות לכמה נכסים בבת אחת — לרשימות (מעקב, התראות) */
function ts_pair_labels(array $assets): array
{
    $out = [];
    foreach (array_unique($assets) as $a) if (is_string($a)) $out[$a] = ts_pair_label($a);
    return $out;
}

/** קטלוג מלא למנהל (18.1 קריטריון 4): שם הזוג ← כל הנכסים שלו, עם הסימולים */
function ts_pairs_admin(): array
{
    $out = [];
    foreach (ts_pairs_catalog() as $key => $p) $out[] = ['pair' => $key, 'assets' => $p['assets'], 'computed' => $p['computed']] + ts_pair_view($key);
    return $out;
}

/** הנרות של זוג מחושב: 1/x. גבוה ↔ נמוך מתחלפים. מחרוזות עשרוניות (lib/decimal.php) */
function ts_pair_invert_candles(array $rows): array
{
    $inv = function (string $v): string {
        if (dec_cmp($v, '0') <= 0) return '0';
        $int = strlen(ltrim(explode('.', $v)[0], '0'));
        return dec_div('1', $v, max(8, $int + 8));
    };
    return array_map(fn($r) => ['t' => $r['t'], 'o' => $inv($r['o']), 'h' => $inv($r['l']), 'l' => $inv($r['h']), 'c' => $inv($r['c']), 'v' => $r['v']], $rows);
}
