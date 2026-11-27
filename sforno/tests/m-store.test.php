<?php
/**
 * פרוסה 09 — החנות: קטלוג WooCommerce (docs/spec/09-store-woocommerce.md §5, "M<מספר> …").
 *
 *   "שרת בדיקה מדומה עם נתוני דוגמה" (M1): WooCommerce REST v3 מדומה כאן, מעל lib/store-sample.json — עם עימוד אמיתי
 *   (X-WP-TotalPages, עד 5 לעמוד — כדי שהסנכרון יעבור כמה עמודים), modified_after, מוצר בודד, ובדיקה שהמפתח הגיע בכותרת.
 *   כל בקשה נרשמת (שיטה וכתובת) — M6. "לא זמין" — status 0.
 *   👁 M2 (הכפתור במסך), M8 (מול החנות האמיתית) — אצל הבעלים (SF-009).
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$root = dirname(__DIR__);
$ok = function (array $r, string $name): array {
    eq($r[0], 200, $name . ($r[0] !== 200 ? ' — ' . json_encode($r[1]['error'] ?? $GLOBALS['t_last_500'] ?? null, JSON_UNESCAPED_UNICODE) : ''));
    return $r[1];
};
$GLOBALS['sf_time'] = strtotime('2026-11-22T12:00:00Z');
$GLOBALS['sf_mail_out'] = [];   // בלי שליחת מיילים אמיתית
$later = function (int $sec) { $GLOBALS['sf_time'] += $sec; };

$dev = ident('901', 'מפתח', 'dev@example.test', ['platform_admin' => true]);
$staff = ident('902', 'מנהלת', 'staff@example.test');
call('GET', 'me', [], $dev);
call('GET', 'me', [], $staff);

const KEY = 'ck_0123456789abcdef0123456789abcdef01234567';
const SECRET = 'cs_fedcba9876543210fedcba9876543210fedcba98';
const HOOK = 'whsec-Test-0123456789-abcdef';

// ── WooCommerce מדומה ──────────────────────────────────────
$sample = json_decode((string)file_get_contents("$root/lib/store-sample.json"), true);
$W = ['products' => $sample['products'], 'categories' => $sample['categories'], 'reviews' => $sample['reviews'], 'down' => false, 'cap' => 5];
// 40 ספרים נוספים — לעימוד ולסינון
for ($i = 1; $i <= 40; $i++) {
    $W['products'][] = ['id' => 1000 + $i, 'name' => "ספר בדיקה $i", 'status' => 'publish', 'type' => 'simple', 'permalink' => "https://shop.test/product/t$i/",
        'price' => (string)(10 * $i), 'regular_price' => (string)(10 * $i), 'sale_price' => '', 'on_sale' => false, 'stock_status' => 'instock',
        'date_modified_gmt' => '2026-11-01T00:00:00', 'categories' => [['id' => 13]], 'tags' => [], 'images' => [['src' => "https://cdn.shop.test/t$i.jpg", 'alt' => '']],
        'attributes' => [['name' => 'מחבר', 'options' => [$i % 2 ? 'מחבר אי-זוגי' : 'מחבר זוגי']]], 'meta_data' => [], 'related_ids' => []];
}
// המוצרים של הדוגמה — במארח של החנות המדומה
foreach ($W['products'] as &$p) $p['permalink'] = str_replace('https://store.example/', 'https://shop.test/', $p['permalink']);
unset($p);
$GLOBALS['W'] = &$W;
$GLOBALS['t_woo'] = [];
$GLOBALS['sf_woo_http'] = function (string $method, string $url, array $headers) {
    $W = &$GLOBALS['W'];
    $GLOBALS['t_woo'][] = ['method' => $method, 'url' => $url, 'auth' => $headers['Authorization'] ?? ''];
    if ($W['down']) return [0, '', []];
    if (($headers['Authorization'] ?? '') !== 'Basic ' . base64_encode(KEY . ':' . SECRET)) return [401, '{"code":"woocommerce_rest_cannot_view"}', []];
    $u = parse_url($url);
    if (($u['host'] ?? '') !== 'shop.test' || !str_starts_with($u['path'], '/wp-json/wc/v3/')) return [404, '', []];
    parse_str($u['query'] ?? '', $q);
    $path = substr($u['path'], strlen('/wp-json/wc/v3/'));
    if (preg_match('~^products/(\d+)$~', $path, $m)) {
        foreach ($W['products'] as $p) if ($p['id'] === (int)$m[1]) return [200, json_encode($p), []];
        return [404, '{}', []];
    }
    $list = match ($path) {
        'products' => array_values(array_filter($W['products'], fn($p) => ($p['status'] ?? '') === ($q['status'] ?? 'any')
            && (!isset($q['modified_after']) || strcmp($p['date_modified_gmt'], rtrim($q['modified_after'], 'Z')) > 0))),
        'products/categories' => $W['categories'],
        'products/reviews' => $W['reviews'],
        'system_status' => null,
        default => false,
    };
    if ($list === null) return [200, json_encode(['environment' => ['version' => '9.4.1']]), []];
    if ($list === false) return [404, '', []];
    $per = min((int)($q['per_page'] ?? 10), $W['cap']);
    $page = (int)($q['page'] ?? 1);
    $pages = max(1, (int)ceil(count($list) / $per));
    return [200, json_encode(array_slice($list, ($page - 1) * $per, $per)), ['x-wp-totalpages' => (string)$pages, 'x-wp-total' => (string)count($list)]];
};
/** כל המשימות שבתור, עד שאין */
$cron = function () { for ($i = 0; $i < 50; $i++) { $r = sf_jobs_run(20); if (($r['ran'] ?? 0) + ($r['failed'] ?? 0) === 0) break; } };

// ── הרשאות ─────────────────────────────────────────────────
eq(code(call('GET', 'store-admin')), 'login_required', 'store-admin בלי מנהל — 401');
eq(code(call('POST', 'dev-store-set', ['url' => 'https://shop.test'], $staff)), 'forbidden', 'dev-store-set — רק מפתח');
eq(code(call('POST', 'store-sync-now', [], $staff)), 'state', 'סנכרון בלי חיבור — 409 עם הסבר');
eq(code(call('POST', 'dev-store-set', ['url' => 'http://shop.test'], $dev)), 'bad_input', 'כתובת בלי https — נדחית');
foreach (['https://127.0.0.1', 'https://localhost', 'https://shop.test:8443', 'https://u:p@shop.test', 'https://shop.test/?x=1'] as $bad) {
    check("כתובת חנות לא מותרת: $bad", sf_woo_url($bad) === null);
}
eq(sf_woo_url('https://Shop.test/store/'), 'https://shop.test/store', 'WordPress בתת-תיקייה — מותר, מנורמל');
eq(code(call('POST', 'dev-store-set', ['url' => 'https://shop.test', 'key' => 'abc'], $dev)), 'bad_input', 'מפתח שאינו ck_… — נדחה');

// ── נתוני דוגמה (לפני שיש חיבור) ───────────────────────────
$s = $ok(call('POST', 'store-sample', ['on' => true], $staff), 'נתוני דוגמה נטענים');
$cat = $ok(call('GET', 'read-store-catalog'), 'read-store-catalog (דוגמה)');
check('דוגמה: מסומן sample, 12 מוצרים גלויים', $cat['status']['sample'] === true && $cat['total'] === 12, [$cat['status'], $cat['total']]);
eq((int)sf_val("SELECT COUNT(*) FROM store_products WHERE source = 'sample' AND removed_at IS NOT NULL"), 1, 'דוגמה: המוסתר — נשמר כהוסר (הטיוטה לא חוזרת מהמקור בכלל)');

// ── החיבור ─────────────────────────────────────────────────
$set = $ok(call('POST', 'dev-store-set', ['url' => 'https://shop.test', 'key' => KEY, 'secret' => SECRET, 'webhook_secret' => HOOK], $dev), 'dev-store-set');
check('M3 dev-store-set מחזיר רק "יש מפתח", בלי המפתח', $set['connection']['has_key'] === true && !str_contains(json_encode($set), KEY) && !str_contains(json_encode($set), SECRET));
eq(substr(sprintf('%o', fileperms(SF_DATA_DIR . '/woo.json')), -4), '0600', 'M3 data/woo.json — 0600');
$t = $ok(call('POST', 'dev-store-test', [], $dev), 'dev-store-test');
check('בדיקת חיבור: ok, ספירה וגרסה', $t['ok'] === true && $t['products'] === 53 && $t['version'] === '9.4.1', $t);
// שמירה בלי מפתח (רק כתובת) — המפתח נשאר
$ok(call('POST', 'dev-store-set', ['url' => 'https://shop.test'], $dev), 'שמירה שנייה בלי מפתחות');
check('שדה ריק = המפתח נשאר', sf_woo_ready() && sf_woo_conf()['key'] === KEY);

// ── M1: סנכרון מלא ← הקטלוג כמו בחנות ─────────────────────
$GLOBALS['t_woo'] = [];
$r = $ok(call('POST', 'store-sync-now', ['kind' => 'full'], $staff), 'store-sync-now full');
$cron();
$run = sf_row('SELECT * FROM store_sync_runs WHERE pid = ?', [$r['run']['pid']]);
check('M1 הריצה המלאה הצליחה', $run['status'] === 'ok' && (int)$run['products'] === 53, $run);
check('M1 כמה עמודים (עד 5 לעמוד בשרת המדומה)', (int)$run['requests'] >= 13, (int)$run['requests']);
eq((int)sf_val("SELECT COUNT(*) FROM store_products WHERE source = 'sample'"), 0, 'הסנכרון האמיתי הראשון מחק את נתוני הדוגמה');
$cat = $ok(call('GET', 'read-store-catalog', [], null, ['sort' => 'name']), 'read-store-catalog');
eq($cat['total'], 52, 'M1 52 מוצרים (טיוטה ומוסתר — לא)');
$names = array_column(sf_rows('SELECT name FROM store_products WHERE removed_at IS NULL'), 'name');
check('M1 טיוטה ומוסתר — לא בקטלוג', !in_array('טיוטה — לא מוצג', $names, true) && !in_array('מוסתר — לא מוצג', $names, true));
$p = $ok(call('GET', 'read-store-product', [], null, ['id' => '101']), 'read-store-product 101');
eq($p['name'], 'סידור ספורנו — עדות המזרח', 'M1 שם');
eq($p['price']['amount'], 8900, 'M1 מחיר 89.00 = 8900 אגורות');
eq($p['author'] ?? null, 'ספורנו', 'M1 מחבר (ממופה מנתוני הדוגמה)');
eq($p['publisher'] ?? null, 'ספורנו הוצאה לאור', 'M1 הוצאה');
eq($p['edition'] ?? null, 'מהדורה שנייה, תשפ״ו', 'M1 מהדורה');
eq($p['toc'] ?? null, ['שחרית', 'מנחה', 'ערבית', 'קריאת שמע על המיטה'], 'M1 תוכן עניינים — פסקאות');
eq(count($p['samples'] ?? []), 2, 'M1 דפי דוגמה');
eq(array_column($p['categories'], 'name'), ['סידורים'], 'M1 קטגוריה');
eq(array_column($p['related'], 'id'), [102, 103], 'M1 ספרים קשורים — רק מה שבקטלוג');
eq(count($p['reviews']), 3, 'M1 ביקורות — רק מאושרות (hold לא)');
eq($p['rating'], ['avg' => 4.67, 'count' => 3], 'M1 דירוג קיים');
eq($p['stock'] ?? null, 'instock', 'M1 מלאי (מאומת)');
eq($p['description'], ['סידור מלא לימות החול, שבת ומועדים, בנוסח עדות המזרח.', 'אותיות מאירות עיניים & הוראות ברורות.'], 'M1 תיאור — טקסט פשוט, בלי <script>');
check('M1 אין HTML בתשובה', !preg_match('/<[a-z]/i', json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
$p2 = $ok(call('GET', 'read-store-product', [], null, ['id' => '102']), 'read-store-product 102');
check('M1 מבצע קיים: 39 במקום 49', $p2['price']['amount'] === 3900 && $p2['price']['regular'] === 4900 && $p2['price']['on_sale'] === true, $p2['price']);
check('M1 meta פרטי (_…) לא נשמר', !str_contains((string)sf_val('SELECT raw FROM store_products WHERE woo_id = 101'), '_wc_private_cost'));
check('M1 מייל המבקר לא נשמר', !sf_val("SELECT 1 FROM store_reviews WHERE body LIKE '%@example.com%' OR reviewer LIKE '%@%'")
    && !str_contains(json_encode(sf_rows('SELECT * FROM store_reviews'), JSON_UNESCAPED_UNICODE), 'david@example.com'));
eq(code(call('GET', 'read-store-product', [], null, ['id' => '113'])), 'not_found', 'M1 טיוטה — 404');
$v = $ok(call('GET', 'read-store-product', [], null, ['id' => '112']), 'מוצר עם וריאציות');
check('וריאציות: "החל מ-"', $v['price']['from'] === true);

// ── M2: לרכישה = כתובת המוצר; אין סל / כמות / תשלום ───────
eq($p['buy_url'], 'https://shop.test/product/p101/', 'M2 buy_url = עמוד המוצר בחנות');
check('M2 אין בספורנו פעולה של סל / תשלום / הזמנה', !preg_grep('/cart|checkout|order|pay|coupon|quantity/i', array_keys(SF_ACTIONS)));
$W['products'][0]['permalink'] = 'https://evil.test/p101';
sf_store_refresh_product(101);
eq($ok(call('GET', 'read-store-product', [], null, ['id' => '101']), 'אחרי שינוי כתובת')['buy_url'], null, 'M2 כתובת מוצר במארח אחר — אין כפתור רכישה');
$W['products'][0]['permalink'] = 'https://shop.test/product/p101/';
sf_store_refresh_product(101);
// store-out: נספר, מחזיר את הכתובת מהמטמון
$o = $ok(rcall('POST', 'store-out', ['id' => 101], 'G'), 'store-out');
eq($o['url'], 'https://shop.test/product/p101/', 'store-out מחזיר את הכתובת מהמטמון');
eq((int)sf_val("SELECT n FROM store_stats WHERE kind = 'out' AND product_woo_id = 101"), 1, 'מעבר לחנות נספר (מצרפי, בלי קורא)');
eq(code(rcall('POST', 'store-out', ['id' => 101], 'G', [], ['content_type' => 'text/plain; x=application/json'])), 'content_type', 'store-out: text/plain — 415');
eq(code(rcall('POST', 'store-out', ['id' => 101], 'G', [], ['sec_fetch_site' => 'cross-site'])), 'csrf', 'store-out: מאתר אחר — 403');
eq(code(rcall('POST', 'store-out', ['id' => 99999], 'G')), 'not_found', 'store-out: מוצר לא קיים — 404');
for ($i = 0; $i < 60; $i++) rcall('POST', 'store-out', ['id' => 102], 'G', [], ['ip' => '198.51.100.9']);
eq(code(rcall('POST', 'store-out', ['id' => 102], 'G', [], ['ip' => '198.51.100.9'])), 'rate', 'store-out: 60 לדקה — אחר כך 429');
check('צפייה במוצר נספרה', (int)sf_val("SELECT n FROM store_stats WHERE kind = 'view' AND product_woo_id = 101") >= 2);

// ── M5: שדה שלא מופה ← לא מוצג ─────────────────────────────
$map = (array)$ok(call('GET', 'store-admin', [], $staff), 'store-admin')['map'];
unset($map['publisher']);
$ok(call('POST', 'store-map-save', ['map' => $map], $staff), 'store-map-save בלי הוצאה');
$p = $ok(call('GET', 'read-store-product', [], null, ['id' => '101']), 'אחרי שינוי המיפוי');
check('M5 הוצאה שלא מופתה — אין מפתח publisher בכלל', !array_key_exists('publisher', $p), array_keys($p));
check('M5 אין "לא ידוע" בתשובה', !str_contains(json_encode($p, JSON_UNESCAPED_UNICODE), 'לא ידוע'));
$p11 = $ok(call('GET', 'read-store-product', [], null, ['id' => '111']), 'מוצר בלי מחבר');
check('M5 שדה ממופה בלי ערך (מחבר) — לא מוצג', !array_key_exists('author', $p11) && !array_key_exists('edition', $p11) && !array_key_exists('toc', $p11));
$ok(call('POST', 'store-map-save', ['map' => []], $staff), 'מיפוי ריק');
$c0 = $ok(call('GET', 'read-store-catalog'), 'קטלוג בלי מיפוי');
check('M5 בלי מיפוי מחבר — אין רשימת מחברים ואין author בכרטיסים', !isset($c0['authors']) && !array_filter($c0['products'], fn($x) => isset($x['author'])));
$ok(call('POST', 'store-map-save', ['map' => $sample['map']], $staff), 'המיפוי חוזר');
eq(code(call('POST', 'store-map-save', ['map' => ['price' => ['type' => 'meta', 'key' => 'x']]], $staff)), 'bad_input', 'מיפוי לשדה לא מוכר — 400');
eq(code(call('POST', 'store-map-save', ['map' => ['author' => ['type' => 'sql', 'key' => 'x']]], $staff)), 'bad_input', 'סוג מיפוי לא מוכר — 400');

// ── M7: סינון, חיפוש ועימוד ───────────────────────────────
$c = $ok(call('GET', 'read-store-catalog', [], null, ['author' => 'מחבר זוגי']), 'סינון לפי מחבר');
check('M7 מחבר: 20 ספרים, כולם שלו', $c['total'] === 20 && !array_filter($c['products'], fn($x) => $x['author'] !== 'מחבר זוגי'), $c['total']);
check('M7 רשימת המחברים', in_array('מחבר זוגי', $c['authors'], true) && in_array('ספורנו', $c['authors'], true));
$c = $ok(call('GET', 'read-store-catalog', [], null, ['min' => '100', 'max' => '200', 'sort' => 'price_asc']), 'סינון מחיר');
$prices = array_map(fn($x) => $x['price']['amount'], $c['products']);
check('M7 מחיר 100–200: נכון, וממוין', $prices && min($prices) >= 10000 && max($prices) <= 20000 && $prices === array_values(array_unique($prices)) && $prices == (function ($a) { sort($a); return $a; })($prices), $prices);
eq($c['total'], (int)sf_val('SELECT COUNT(*) FROM store_products WHERE removed_at IS NULL AND price BETWEEN 10000 AND 20000'), 'M7 הספירה = במסד');
$c = $ok(call('GET', 'read-store-catalog', [], null, ['q' => 'הלכה יומית']), 'חיפוש');
eq(array_column($c['products'], 'id'), [105, 106], 'M7 חיפוש "הלכה יומית" — שני הכרכים');
$c = $ok(call('GET', 'read-store-catalog', [], null, ['q' => 'החפץ']), 'חיפוש לפי מחבר');
eq(array_column($c['products'], 'id'), [104], 'M7 חיפוש לפי שם המחבר');
eq($ok(call('GET', 'read-store-catalog', [], null, ['q' => '100%']), 'חיפוש עם %')['total'], 0, 'M7 % בחיפוש — תו רגיל, לא wildcard');
$c = $ok(call('GET', 'read-store-catalog', [], null, ['cat' => '13', 'author' => 'מחבר אי-זוגי', 'sort' => 'price_desc', 'page' => '1']), 'עמוד 1');
check('M7 קטגוריה + מחבר: 20, עמוד 1 מתוך 1', $c['total'] === 20 && $c['pages'] === 1 && count($c['products']) === 20, [$c['total'], $c['pages']]);
$all = [];
$c1 = $ok(call('GET', 'read-store-catalog', [], null, ['page' => '1']), 'עמוד 1');
$c2 = $ok(call('GET', 'read-store-catalog', [], null, ['page' => '2']), 'עמוד 2');
$c3 = $ok(call('GET', 'read-store-catalog', [], null, ['page' => '3']), 'עמוד 3');
$ids = array_merge(array_column($c1['products'], 'id'), array_column($c2['products'], 'id'), array_column($c3['products'], 'id'));
check('M7 עימוד: 24 + 24 + 4, בלי כפילות, הכול', $c1['pages'] === 3 && count($c1['products']) === 24 && count($c3['products']) === 4 && count(array_unique($ids)) === 52, [$c1['pages'], count($c3['products']), count(array_unique($ids))]);
eq($ok(call('GET', 'read-store-catalog', [], null, ['page' => '99']), 'עמוד מעבר לסוף')['page'], 3, 'M7 עמוד מעבר לסוף — האחרון');
$cats = array_column($c1['categories'], 'n', 'name');
eq($cats['מחשבה ומוסר'] ?? null, 43, 'M7 ספירה לקטגוריה — רק מוצרים גלויים');

// ── שינויים בלבד (delta) והסרה במלא ────────────────────────
$later(3600);
foreach ($W['products'] as &$p) if ($p['id'] === 105) { $p['price'] = '69.90'; $p['stock_status'] = 'instock'; $p['date_modified_gmt'] = gmdate('Y-m-d\\TH:i:s', $GLOBALS['sf_time']); }
unset($p);
$GLOBALS['t_woo'] = [];
sf_store_tick();
$cron();
$urls = array_column($GLOBALS['t_woo'], 'url');
check('delta: modified_after נשלח, בלי קטגוריות', (bool)preg_grep('/products\?.*modified_after=/', $urls) && !preg_grep('~/products/categories~', $urls), $urls);
eq((int)sf_val('SELECT price FROM store_products WHERE woo_id = 105'), 6990, 'delta: המחיר החדש');
eq(sf_row("SELECT kind, status FROM store_sync_runs ORDER BY id DESC LIMIT 1"), ['kind' => 'delta', 'status' => 'ok'], 'delta: הריצה הצליחה');
$W['products'] = array_values(array_filter($W['products'], fn($p) => $p['id'] !== 1040));
sf_store_run_start('full');
$cron();
eq(code(call('GET', 'read-store-product', [], null, ['id' => '1040'])), 'not_found', 'מלא: מוצר שנמחק בחנות — לא מוצג');
check('…אבל השורה נשארת במטמון (removed_at), לא נמחקת', (bool)sf_val('SELECT removed_at FROM store_products WHERE woo_id = 1040'));

// ── M6: רק GET ─────────────────────────────────────────────
check('M6 כל הבקשות לחנות — GET', $GLOBALS['t_woo'] && !array_filter($GLOBALS['t_woo'], fn($x) => $x['method'] !== 'GET'));
check('M6 כל הבקשות — רק למארח שהוגדר, https', !array_filter($GLOBALS['t_woo'], fn($x) => !str_starts_with($x['url'], 'https://shop.test/wp-json/wc/v3/')));
check('M6 המפתח בכותרת, לא בכתובת', !array_filter($GLOBALS['t_woo'], fn($x) => str_contains($x['url'], 'consumer_key') || str_contains($x['url'], KEY)));
$src = (string)file_get_contents("$root/lib/store.php");
check('M6 בקוד: אין CURLOPT_POST / CUSTOMREQUEST / PUT / UPLOAD', !preg_match('/CURLOPT_(POST|CUSTOMREQUEST|PUT|UPLOAD|POSTFIELDS)\b/', $src));
$thrown = false;
try { sf_woo_http('POST', 'https://shop.test/wp-json/wc/v3/products', []); } catch (LogicException $e) { $thrown = true; }
check('M6 sf_woo_http("POST") — נזרק', $thrown);

// ── M4: החנות לא זמינה ← קטלוג שמור עם חיווי ─────────────────
$W['down'] = true;
$later(3600);
sf_store_tick();
$cron();
eq(sf_val("SELECT status FROM store_sync_runs ORDER BY id DESC LIMIT 1"), 'failed', 'M4 החנות לא זמינה — הריצה נכשלה');
$c = $ok(call('GET', 'read-store-catalog'), 'M4 קטלוג כשהחנות לא זמינה');
check('M4 הקטלוג השמור מוצג', $c['total'] === 51);
check('M4 עד 24 שעות — עדיין מאומת', $c['status']['stale'] === false && $c['products'][0]['fresh'] === true);
$health = array_column($ok(call('GET', 'dev-health', [], $dev), 'dev-health')['checks'], 'state', 'id');
eq($health['store'] ?? null, 'bad', 'M4 בדיקת התקינות: הסנכרון נכשל ← ❌');
$later(25 * 3600);
$c = $ok(call('GET', 'read-store-catalog'), 'M4 אחרי 25 שעות');
check('M4 אחרי 24 שעות: stale, ומתי עודכן', $c['status']['stale'] === true && $c['status']['verified_at'] !== null);
check('M4 מחיר ישן לא מסומן מאומת, ובלי מלאי', !array_filter($c['products'], fn($x) => $x['fresh'] || isset($x['stock'])));
$p = $ok(call('GET', 'read-store-product', [], null, ['id' => '104']), 'M4 מוצר ישן');
check('M4 עמוד מוצר ישן: fresh=false, אין stock, המחיר האחרון נשאר', $p['fresh'] === false && !isset($p['stock']) && $p['price']['amount'] === 72000);
$W['down'] = false;

// ── webhook ────────────────────────────────────────────────
$hook = function (string $body, array $env) {
    try { return [200, sf_dispatch('POST', 'store-webhook', ['action' => 'store-webhook'], [], ['platform_user' => null, 'cookie' => '', 'csrf' => '', 'content_type' => 'application/json', 'raw_body' => $body] + $env)]; }
    catch (Throwable $e) { return sf_error_response($e); }
};
$sig = fn(string $b, string $k = HOOK) => base64_encode(hash_hmac('sha256', $b, $k, true));
foreach ($W['products'] as &$p) if ($p['id'] === 106) $p['price'] = '80.00';
unset($p);
$body = json_encode(['id' => 106, 'name' => 'זיוף', 'price' => '1.00']);
eq($hook($body, ['wc_signature' => $sig($body, 'wrong-secret-xxxxxxxx'), 'wc_delivery' => 'd1', 'wc_topic' => 'product.updated'])[0], 403, 'webhook: חתימה שגויה — 403');
eq($hook($body, ['wc_signature' => '', 'wc_delivery' => 'd1', 'wc_topic' => 'product.updated'])[0], 403, 'webhook: בלי חתימה — 403');
eq($hook('webhook_id=7', [])[1], ['ok' => true, 'ping' => true], 'webhook: בדיקת החיבור של WooCommerce — 200 בלי פעולה');
$r = $hook($body, ['wc_signature' => $sig($body), 'wc_delivery' => 'd1', 'wc_topic' => 'product.updated']);
eq($r, [200, ['ok' => true]], 'webhook: חתימה נכונה — 200');
eq($hook($body, ['wc_signature' => $sig($body), 'wc_delivery' => 'd1', 'wc_topic' => 'product.updated'])[1]['duplicate'] ?? null, true, 'webhook: אותו delivery — לא מטופל פעמיים');
eq((int)sf_val("SELECT COUNT(*) FROM jobs WHERE kind = 'store.product' AND status = 'pending'"), 1, 'webhook: משימת רענון אחת בתור');
$later(60);
$cron();
eq((int)sf_val('SELECT price FROM store_products WHERE woo_id = 106'), 8000, 'webhook: המוצר נקרא מחדש מה-API (80.00) — לא מהגוף (1.00)');
eq($hook(str_repeat('x', SF_STORE_HOOK_MAX + 1), ['wc_signature' => 'x'])[0], 413, 'webhook: גוף מעל 2MB — 413');
$b2 = json_encode(['id' => 1]);
eq($hook($b2, ['wc_signature' => $sig($b2), 'wc_delivery' => 'd2', 'wc_topic' => 'order.created'])[0], 200, 'webhook: נושא אחר — 200 בלי פעולה');
eq((int)sf_val("SELECT COUNT(*) FROM jobs WHERE kind = 'store.product' AND status = 'pending'"), 0, '…ובלי משימה');
// מוצר שנמחק בחנות
$W['products'] = array_values(array_filter($W['products'], fn($p) => $p['id'] !== 1039));
$b3 = json_encode(['id' => 1039]);
$hook($b3, ['wc_signature' => $sig($b3), 'wc_delivery' => 'd3', 'wc_topic' => 'product.deleted']);
$later(60);
$cron();
eq(code(call('GET', 'read-store-product', [], null, ['id' => '1039'])), 'not_found', 'webhook product.deleted ← לא מוצג');

// ── קישור כותר ↔ מוצר ─────────────────────────────────────
$smp = $ok(call('POST', 'content-sample', [], $staff), 'content-sample');
$tSid = sf_val('SELECT t.pid FROM titles t WHERE t.kind = ?', ['siddur']);
$ok(call('POST', 'store-link-set', ['title' => $tSid, 'product' => 101], $staff), 'store-link-set');
$p = $ok(call('GET', 'read-store-product', [], null, ['id' => '101']), 'מוצר עם ספר דיגיטלי');
check('"יש גם ספר דיגיטלי" — הכותר והמהדורה', count($p['digital']) === 1 && $p['digital'][0]['title'] === 'סידור (דוגמה)' && sf_is_pid($p['digital'][0]['edition']), $p['digital']);
call('POST', 'content-title-public', ['title' => $tSid, 'on' => false], $staff);
eq($ok(call('GET', 'read-store-product', [], null, ['id' => '101']), 'כותר שאינו ציבורי')['digital'], [], 'כותר שלא פורסם לקוראים — לא מוצג');
eq(code(call('POST', 'store-link-set', ['title' => $tSid, 'product' => 424242], $staff)), 'not_found', 'קישור למוצר שאינו בקטלוג — 404');
$ok(call('POST', 'store-link-set', ['title' => $tSid, 'product' => 101, 'on' => false], $staff), 'הסרת קישור');
eq((int)sf_val('SELECT COUNT(*) FROM title_products'), 0, 'הקישור הוסר');

// ── מועדפים: סוג מידע אישי מסונכרן (05) ───────────────────
rcall('POST', 'account-register', ['email' => 'fav@example.com', 'password' => 'fav-fav-fav-1'], 'F');
$pr = $ok(rcall('POST', 'sync-push', ['items' => [['pid' => 'sp:101', 'kind' => 'store_favorite', 'data' => ['id' => '101', 'name' => 'סידור', 'created_at' => '2026-11-22T00:00:00Z'], 'base' => 0]]], 'F'), 'sync-push store_favorite');
eq($pr['results'][0]['status'] ?? null, 'new', 'מועדף בחנות — נשמר בחשבון');
eq((int)sf_val("SELECT COUNT(*) FROM personal_items WHERE kind = 'store_favorite' AND reader_id = (SELECT id FROM readers WHERE email = ?)", ['fav@example.com']), 1, 'store_favorite — personal_items של הקורא (נמחק עם החשבון, 05/10)');

// ── M3: המפתח לא בשום תשובה ולא בשום יומן ────────────────
$W['down'] = true;
sf_store_run_start('delta');
$cron();
$W['down'] = false;
$resp = '';
foreach ([call('GET', 'store-admin', [], $staff), call('GET', 'dev-health', [], $dev), call('GET', 'dev-jobs', [], $dev), call('GET', 'dev-audit', [], $dev),
    call('POST', 'dev-store-test', [], $dev), call('GET', 'read-store-catalog'), call('GET', 'read-store-product', [], null, ['id' => '101']),
    call('GET', 'dev-log', [], $dev), call('GET', 'health')] as $x) $resp .= json_encode($x, JSON_UNESCAPED_SLASHES);
check('M3 המפתח והסוד — לא בשום תשובה (ניהול, בדיקות, רקע, יומן פעולות, יומן טכני, קטלוג)', !str_contains($resp, KEY) && !str_contains($resp, SECRET) && !str_contains($resp, HOOK)
    && !str_contains($resp, base64_encode(KEY . ':' . SECRET)));
$leak = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SF_DATA_DIR, FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->getFilename() === 'woo.json') continue;
    $raw = (string)@file_get_contents($f->getPathname());
    if (str_contains($raw, KEY) || str_contains($raw, SECRET) || str_contains($raw, base64_encode(KEY . ':' . SECRET))) $leak[] = $f->getFilename();
}
eq($leak, [], 'M3 המפתח לא בשום קובץ ב-data/ מלבד woo.json (מסד, יומנים)');
check('M3 בבקשה: המפתח רק בכותרת Authorization', str_starts_with($GLOBALS['t_woo'][0]['auth'] ?? '', 'Basic '));

// ── ניתוק ──────────────────────────────────────────────────
$ok(call('POST', 'dev-store-set', ['clear' => true], $dev), 'ניתוק');
check('ניתוק: woo.json נמחק, הקטלוג השמור נשאר', !is_file(SF_DATA_DIR . '/woo.json') && (int)sf_val('SELECT COUNT(*) FROM store_products WHERE removed_at IS NULL') > 0);
eq($hook($body, ['wc_signature' => $sig($body), 'wc_delivery' => 'd9', 'wc_topic' => 'product.updated'])[0], 404, 'webhook בלי סוד מוגדר — 404');
check('store.tick בלי חיבור — לא עושה כלום', sf_store_tick() === null);

// ── אין קריאה לקובץ מחוץ ל-sforno, וכל require — __DIR__ ──
check('store.php: require רק __DIR__', !preg_match('/require(_once)?\s+(?!__DIR__)/', $src));
check('store.js: בלי innerHTML', !str_contains((string)file_get_contents("$root/assets/js/screens/store.js"), 'innerHTML') && !str_contains((string)file_get_contents("$root/assets/js/screens/store-admin.js"), 'innerHTML'));

done();
