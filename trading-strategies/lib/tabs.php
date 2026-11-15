<?php
/**
 * לשוניות במסך השוק (spec-stage-a 18.1.4, S1.2). הראשונה תמיד "⭐ מועדפים" (המעקב), ואחריה המובנות והלשוניות של המשתמש.
 *
 * - **נשמר בפרופיל** (פרק 5) כ-market_tabs: [{id, kind: favorites|builtin|custom, name?, assets?}], בסדר שהמשתמש קבע.
 *   [שלנו] העמודה יושבת ב-users.market_tabs (מיגרציה 9, ALTER בלבד), ו-ts_profile() מחזיר אותה. לא ב-user_profile:
 *   שורה שם מסמנת "הפרופיל נשמר" ודורסת את ברירות המחדל של הסיכון — ולשונית אינה החלטה על סיכון.
 *   null — עוד לא שמר: כל המובנות, בסדר של TS_TABS_BUILTIN.
 * - **שורה:** התווית של S1.1, ומחיר ושינוי יומי **מהמאגר בלבד** (ש6): פתיחת לשונית לא פונה לספק. אין במאגר — null ("—").
 * - **בלי גישה לספק** (ש7 — Twelve Data בלי מפתח): הלשונית מוצגת, עם `needs` (הספק שחסר), והשורות `locked`.
 */

declare(strict_types=1);

require_once __DIR__ . '/tab-lists.php';

/** המובנות, בסדר ברירת המחדל. provider — הספק שממנו הרשימה (להודעה של ש7) */
const TS_TABS_BUILTIN = [
    'crypto' => ['name' => '₿ קריפטו', 'provider' => 'binance'],
    'fx' => ['name' => '💱 מט"ח', 'provider' => 'ecb'],
    'nasdaq100' => ['name' => 'נאסד"ק 100', 'provider' => 'twelvedata'],
    'sp500' => ['name' => 'S&P 500', 'provider' => 'twelvedata'],
    'indices' => ['name' => '📊 מדדים', 'provider' => 'twelvedata'],
    'commodities' => ['name' => '🛢 סחורות', 'provider' => 'twelvedata'],
];
const TS_TABS_MAX = 12;           // [שלנו] 18.1.4
const TS_TAB_ASSETS_MAX = 100;    // [שלנו] בלשונית משלי
const TS_TAB_FAVORITES = ['id' => 'favorites', 'kind' => 'favorites'];

/** ברירת המחדל: מועדפים, ואחריהם כל המובנות */
function ts_tabs_default(): array
{
    return [TS_TAB_FAVORITES, ...array_map(fn($id) => ['id' => $id, 'kind' => 'builtin'], array_keys(TS_TABS_BUILTIN))];
}

function ts_market_tabs(int $uid): array
{
    $st = ts_db()->prepare('SELECT market_tabs FROM users WHERE id = ?');
    $st->execute([$uid]);
    $raw = $st->fetchColumn();
    if (!is_string($raw) || $raw === '') return ts_tabs_default();
    try {
        return ts_tabs_clean(json_decode($raw, true, 8, JSON_THROW_ON_ERROR));
    } catch (Throwable) {
        return ts_tabs_default();   // שמור פגום — לא מפיל את המסך
    }
}

/** תצוגה: לכל לשונית — השם, ומה שהמשתמש יכול להוסיף ("➕ לשונית": המובנות שהוסרו) */
function ts_market_tabs_out(int $uid): array
{
    $tabs = ts_market_tabs($uid);
    $in = array_column(array_filter($tabs, fn($t) => $t['kind'] === 'builtin'), 'id');
    return [
        'tabs' => array_map(fn($t) => $t + ['name' => $t['kind'] === 'builtin' ? TS_TABS_BUILTIN[$t['id']]['name'] : ($t['name'] ?? '⭐ מועדפים')], $tabs),
        'removed' => array_values(array_map(fn($id) => ['id' => $id, 'name' => TS_TABS_BUILTIN[$id]['name']],
            array_values(array_diff(array_keys(TS_TABS_BUILTIN), $in)))),
        'max' => TS_TABS_MAX, 'max_assets' => TS_TAB_ASSETS_MAX,
    ];
}

/**
 * בדיקה ונרמול של רשימה שמגיעה מהמשתמש. "⭐ מועדפים" — תמיד ראשונה ופעם אחת, גם אם לא נשלחה (קריטריון 4: לא זזה ולא נמחקת).
 * TsInvalid על כל דבר לא מוכר: מובנית שאינה ברשימה, כפילות, יותר מ-12, שם ריק, נכס לא תקין.
 */
function ts_tabs_clean(mixed $tabs): array
{
    if (!is_array($tabs) || !array_is_list($tabs)) throw new TsInvalid('לשוניות — רשימה לא תקינה');
    $out = [TS_TAB_FAVORITES];
    $seen = ['favorites' => true];
    foreach ($tabs as $t) {
        if (!is_array($t) || !is_string($t['id'] ?? null) || !is_string($t['kind'] ?? null)) throw new TsInvalid('לשונית לא תקינה');
        $id = $t['id'];
        if ($t['kind'] === 'favorites') continue;
        if (isset($seen[$id])) throw new TsInvalid('לשונית כפולה');
        $seen[$id] = true;
        if ($t['kind'] === 'builtin') {
            if (!isset(TS_TABS_BUILTIN[$id])) throw new TsInvalid('לשונית לא מוכרת');
            $out[] = ['id' => $id, 'kind' => 'builtin'];
        } elseif ($t['kind'] === 'custom') {
            if (!preg_match('/^c[a-z0-9]{1,15}$/', $id)) throw new TsInvalid('לשונית לא תקינה');
            $name = trim(is_string($t['name'] ?? null) ? $t['name'] : '');
            if ($name === '' || mb_strlen($name) > 30) throw new TsInvalid('שם לשונית — 1 עד 30 תווים');
            $assets = $t['assets'] ?? [];
            if (!is_array($assets) || !array_is_list($assets)) throw new TsInvalid('נכסים בלשונית — רשימה לא תקינה');
            if (count($assets) > TS_TAB_ASSETS_MAX) throw new TsInvalid('בלשונית משלי — עד ' . TS_TAB_ASSETS_MAX . ' נכסים');
            foreach ($assets as $a) ts_asset_split($a);
            $out[] = ['id' => $id, 'kind' => 'custom', 'name' => $name, 'assets' => array_values(array_unique($assets))];
        } else {
            throw new TsInvalid('לשונית לא תקינה');
        }
    }
    if (count($out) > TS_TABS_MAX) throw new TsInvalid('עד ' . TS_TABS_MAX . ' לשוניות');
    return $out;
}

function ts_market_tabs_save(int $uid, mixed $tabs): array
{
    $clean = ts_tabs_clean($tabs);
    ts_db()->prepare('UPDATE users SET market_tabs = ? WHERE id = ?')->execute([json_encode($clean, JSON_UNESCAPED_UNICODE), $uid]);
    return ts_market_tabs_out($uid);
}

// ── הרשימות של המובנות ─────────────────────────────────

/** מניה / מדד / סחורה מהרשימות: התווית בלי חיפוש (ts_pair_label נשען על זה) */
function ts_tab_list_label(string $asset): ?array
{
    if (!str_starts_with($asset, 'twelvedata:')) return null;
    $sym = substr($asset, 11);
    $usd = ['code' => 'USD', 'name' => ts_pair_side_name('USD')];
    if (isset(TS_TAB_COMMODITIES[$sym])) return ['base' => ['code' => explode('/', $sym)[0], 'name' => TS_TAB_COMMODITIES[$sym]], 'quote' => $usd];
    if (isset(TS_TAB_INDICES[$sym])) {
        [$name, $cur] = TS_TAB_INDICES[$sym];
        return ['base' => ['code' => $sym, 'name' => $name], 'quote' => ['code' => $cur, 'name' => ts_pair_side_name($cur)]];
    }
    $name = TS_TAB_SP500[$sym] ?? TS_TAB_NASDAQ100[$sym] ?? null;
    return $name === null ? null : ['base' => ['code' => $sym, 'name' => $name], 'quote' => $usd];
}

/** הנכסים של לשונית מובנית, בסדר שלה. זוג מהקטלוג — הנכס של המשתמש (ts_pair_asset), או null כשאין לו ספק */
function ts_tab_builtin_assets(int $uid, string $id): array
{
    return match ($id) {
        'crypto' => array_map(fn($c) => ['asset' => ts_pair_asset($uid, "$c/USDT"), 'pair' => "$c/USDT"], TS_TAB_CRYPTO),
        'fx' => array_map(fn($k) => ['asset' => ts_pair_asset($uid, $k), 'pair' => $k], TS_TAB_FX),
        'nasdaq100' => array_map(fn($s) => ['asset' => "twelvedata:$s"], array_keys(TS_TAB_NASDAQ100)),
        'sp500' => array_map(fn($s) => ['asset' => "twelvedata:$s"], array_keys(TS_TAB_SP500)),
        'indices' => array_map(fn($s) => ['asset' => "twelvedata:$s"], array_keys(TS_TAB_INDICES)),
        'commodities' => array_map(fn($s) => ['asset' => "twelvedata:$s"], array_keys(TS_TAB_COMMODITIES)),
        default => throw new TsNotFound('הלשונית לא נמצאה'),
    };
}

/**
 * השורות של לשונית — בלי שום פנייה לספק (קריטריון 3). לכל שורה: התווית, מחיר ושינוי יומי מהמאגר, locked כשאין גישה.
 * needs — הספק שחסר לכל הלשונית (ש7), או null.
 */
function ts_market_tab(int $uid, mixed $id): array
{
    $id = is_string($id) ? $id : '';
    $tabs = ts_market_tabs($uid);
    $tab = null;
    foreach ($tabs as $t) if ($t['id'] === $id) $tab = $t;
    // מובנית שהוסרה — עדיין אפשר לראות אותה (תצוגה מקדימה ב"➕ לשונית")
    if (!$tab && isset(TS_TABS_BUILTIN[$id])) $tab = ['id' => $id, 'kind' => 'builtin'];
    if (!$tab) throw new TsNotFound('הלשונית לא נמצאה');
    $items = match ($tab['kind']) {
        'favorites' => array_map(fn($w) => ['asset' => $w['asset']], ts_watchlist($uid)),
        'builtin' => ts_tab_builtin_assets($uid, $id),
        'custom' => array_map(fn($a) => ['asset' => $a], $tab['assets']),
    };
    $access = [];
    $can = function (string $p) use ($uid, &$access) { return $access[$p] ??= ts_access($uid, $p); };
    $rows = [];
    foreach ($items as $it) {
        $asset = $it['asset'];
        if ($asset === null) {   // זוג מהקטלוג שאין לו ספק למשתמש
            $rows[] = ['asset' => null, 'locked' => true, 'price' => null, 'change_pct' => null, 'computed' => false] + ts_pair_view($it['pair']);
            continue;
        }
        $prov = explode(':', $asset, 2)[0];
        $acc = $can($prov);
        $label = ts_pair_label($asset);
        $q = $acc ? ts_cached_quote($asset, $acc['scope']) : null;
        $rows[] = ['asset' => $asset, 'provider' => $prov, 'locked' => $acc === null, 'price' => $q['price'] ?? null,
            'change_pct' => $q['change_pct'] ?? null, 'market' => ts_asset_meta($asset)['market'] ?? null] + $label;
    }
    $needs = null;
    if ($tab['kind'] === 'builtin') {
        $p = TS_TABS_BUILTIN[$id]['provider'];
        if (!$can($p)) $needs = ['provider' => $p, 'name' => ts_provider($p)->meta()['name']];
    }
    return ['tab' => $tab, 'rows' => $rows, 'needs' => $needs];
}

/**
 * מחיר אחרון ושינוי יומי של נכס — מהמאגר בלבד (ש6). הנר האחרון מכל מסגרת, והשינוי מול הנר של אותה מסגרת לפני 24 שעות
 * לפחות. אין במאגר — null. זוג מחושב (1/x) — הופכים.
 */
function ts_cached_quote(string $asset, string $scope): ?array
{
    [$prov, $sym] = explode(':', $asset, 2);
    $inv = str_starts_with($sym, '1/');
    if ($inv) $sym = substr($sym, 2);
    $c = ts_cache();
    // MAX(t) לכל מסגרת — על המפתח הראשי, בלי לסרוק את כל הנרות
    $mx = $c->prepare('SELECT MAX(t) FROM candles WHERE provider = ? AND symbol = ? AND tf = ? AND cred_scope = ?');
    $best = null;
    foreach (array_keys(TS_TF_SECONDS) as $tf) {
        $mx->execute([$prov, $sym, $tf, $scope]);
        $t = $mx->fetchColumn();
        if ($t !== null && $t !== false && ($best === null || (int)$t > $best[1])) $best = [$tf, (int)$t];
    }
    if ($best === null) return null;
    [$tf, $t] = $best;
    $get = $c->prepare('SELECT c FROM candles WHERE provider = ? AND symbol = ? AND tf = ? AND cred_scope = ? AND t <= ? ORDER BY t DESC LIMIT 1');
    $get->execute([$prov, $sym, $tf, $scope, $t]);
    $last = (string)$get->fetchColumn();
    $get->execute([$prov, $sym, $tf, $scope, $t - 86400]);
    $prev = $get->fetchColumn();
    $prev = $prev === false ? null : (string)$prev;
    if ($inv) {
        $flip = fn($v) => dec_sign($v) > 0 ? dec_div('1', $v, max(8, strlen(ltrim(explode('.', $v)[0], '0')) + 8)) : '0';
        $last = $flip($last);
        $prev = $prev !== null ? $flip($prev) : null;
    }
    $chg = $prev !== null && dec_sign($prev) > 0 ? dec_fixed(dec_mul(dec_div(dec_sub($last, $prev), $prev, 10), '100'), 2) : null;
    return ['price' => dec_norm($last), 'change_pct' => $chg, 'at' => $t];
}
