<?php
/**
 * פרוסה 07 — לוח עברי, זמני היום והלכה יומית (docs/spec/07-calendar-zmanim-halacha.md §5, "K<מספר> …").
 *
 *   השרת: lib/calendar.php (K1 מול intl, K6 מול tests/zmanim-cases.json), השיטות (K5, ניהול, גרסאות), ההלכה (K2, K4, K7),
 *   והמיקום בסנכרון (K10). המכשיר — tests/calendar-core.mjs ב-node, על fixture שנבנה כאן: אותם תאריכים, מקומות ושיטות,
 *   וה-JS חייב לתת **בדיוק** את מה שה-PHP נתן (K1, זמנים במילישנייה, בדיקת שיטה, "היום"), ו-K3 K4 K5 K8 במכשיר.
 *   👁 K8 (מצב טיסה), K9 (סירוב למיקום) — אצל הבעלים; tests/browser/daily.cjs בודק בכרומיום (לא רץ ב-run.php).
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$root = dirname(__DIR__);
$A = ident('701', 'מנהלת', 'a@example.test');
call('GET', 'me', [], $A);
$ok = function (array $r, string $name): array {
    eq($r[0], 200, $name . ($r[0] !== 200 ? ' — ' . json_encode($r[1]['error'] ?? $GLOBALS['t_last_500'] ?? null, JSON_UNESCAPED_UNICODE) : ''));
    return $r[1];
};
$app = json_decode((string)file_get_contents("$root/app.json"), true);
$at = function (string $iso) { $GLOBALS['sf_time'] = strtotime($iso); };

// ── הרשאות ───────────────────────────────────────────────────
foreach (['read-zmanim-methods', 'read-halacha', 'read-halacha-archive'] as $a) {
    eq(SF_ACTIONS[$a] ?? null, ['m' => 'GET', 'who' => 'reader'], "$a — reader, GET (בלי עוגייה, בלי מידע אישי)");
    check("$a לא ב-\"public\" של app.json עד ההשקה (11)", !str_contains(implode(' ', $app['public']), $a));
}
foreach (['zmanim-method-list', 'zmanim-method-save', 'zmanim-method-status', 'zmanim-method-default', 'halacha-list', 'halacha-get', 'halacha-save', 'halacha-publish', 'halacha-unpublish'] as $a) {
    eq(SF_ACTIONS[$a]['who'] ?? null, 'staff', "$a — מנהלים בלבד");
}
eq(code(call('POST', 'halacha-save', ['month' => 9, 'day' => 1, 'html' => '<p>x</p>', 'base' => 0], null)), 'login_required', 'קורא לא כותב הלכה');
eq(code(rcall('POST', 'zmanim-method-save', ['name' => 'x', 'def' => [], 'base' => 0], 'R')), 'login_required', 'גם לא עם עוגיית קורא');

// ── K1: 1,000 תאריכים אקראיים (PHP; ה-JS ב-node משווה) ────────
mt_srand(7007);
$dates = $php = [];
$f0 = sf_cal_fixed_from_greg(1900, 1, 1);
$f1 = sf_cal_fixed_from_greg(2150, 1, 1);
for ($i = 0; $i < 1000; $i++) {
    $g = sf_cal_greg_from_fixed(mt_rand($f0, $f1));
    $h = sf_cal_to_hebrew($g['y'], $g['m'], $g['d']);
    $dates[] = [$g['y'], $g['m'], $g['d']];
    $php[] = $h + ['text' => sf_cal_heb_text($h)];
}
$rt = 0;
foreach ($dates as $i => [$y, $m, $d]) { $b = sf_cal_from_hebrew($php[$i]['y'], $php[$i]['m'], $php[$i]['d']); if ($b !== ['y' => $y, 'm' => $m, 'd' => $d]) $rt++; }
eq($rt, 0, 'K1 PHP: עברי ← לועזי ← עברי חוזר לאותו יום');
if (extension_loaded('intl')) {
    $cal = IntlCalendar::createInstance('UTC', 'he_IL@calendar=hebrew');
    $icu = [0 => 7, 1 => 8, 2 => 9, 3 => 10, 4 => 11, 5 => 12, 6 => 13, 7 => 1, 8 => 2, 9 => 3, 10 => 4, 11 => 5, 12 => 6];
    // ICU לפני 75 טועה ביום אחד בכל שנת ה'תתו (2045–2046) — באג ידוע שתוקן; ה-ICU של node (חדש) בודק גם אותה (calendar-core.mjs)
    $old = version_compare(INTL_ICU_VERSION, '75', '<');
    $bad = $known = 0;
    foreach ($dates as $i => [$y, $m, $d]) {
        $cal->setTime((sf_cal_fixed_from_greg($y, $m, $d) - 719163) * 86400000.0);
        if ($cal->get(IntlCalendar::FIELD_YEAR) !== $php[$i]['y'] || $icu[$cal->get(IntlCalendar::FIELD_MONTH)] !== $php[$i]['m'] || $cal->get(IntlCalendar::FIELD_DAY_OF_MONTH) !== $php[$i]['d']) {
            if ($old && in_array($php[$i]['y'], [5806, 5807], true)) $known++; else $bad++;
        }
    }
    eq($bad, 0, 'K1 PHP = intl (IntlCalendar, לוח עברי) ב-1,000 התאריכים' . ($known ? " (חוץ מ-$known בשנת ה'תתו — באג של ICU " . INTL_ICU_VERSION . ')' : ''));
} else {
    check('K1 (intl לא מותקן כאן — ההשוואה ל-ICU רצה ב-node בלבד)', true);
}
eq(sf_cal_heb_text(sf_cal_to_hebrew(2026, 11, 22)), 'י"ב כסלו תשפ"ז', 'K1 22.11.2026 = י"ב כסלו תשפ"ז');
eq(sf_cal_heb_text(sf_cal_to_hebrew(2026, 3, 3)), 'י"ד אדר תשפ"ו', 'K1 פורים תשפ"ו — "אדר" (שנה פשוטה)');
eq(sf_cal_heb_text(sf_cal_to_hebrew(2027, 3, 23)), 'י"ד אדר ב\' תשפ"ז', 'K1 פורים תשפ"ז — אדר ב\' (מעוברת)');

// ── K6: זמני היום מול הטבלאות (PHP) ───────────────────────────
$zc = json_decode((string)file_get_contents(__DIR__ . '/zmanim-cases.json'), true);
$k6 = [];
$k6n = 0;
foreach ($zc['cases'] as $c) {
    [$y, $m, $d] = array_map('intval', explode('-', $c['date']));
    $z = array_column(sf_cal_zmanim($zc['method'], $c, $y, $m, $d), 'ms', 'key');
    foreach ($c['expect'] as $k => $want) {
        $k6n++;
        $got = $z[$k];
        if ($want === null || $got === null) { if ($want !== $got) $k6[] = "{$c['place']} {$c['date']} $k"; continue; }
        [$wd, $wt] = explode('T', $want);
        [$Y, $M, $D] = array_map('intval', explode('-', $wd));
        [$hh, $mm] = array_map('intval', explode(':', $wt));
        $diff = (sf_cal_round($got / 60000) * 60000 - sf_cal_zoned_to_utc($c['tz'], $Y, $M, $D, $hh, $mm)) / 60000;
        if (abs($diff) > 1) $k6[] = "{$c['place']} {$c['date']} $k: $diff";
    }
}
eq($k6, [], "K6 PHP: כל $k6n הזמנים ב-zmanim-cases.json — עד דקה");
check('K6 הטבלה כוללת מעבר שעון בישראל ובחו"ל, ריקיאוויק ביוני ומקום בלי שקיעה', (bool)array_filter($zc['cases'], fn($c) => $c['date'] === '2026-03-27' && $c['tz'] === 'Asia/Jerusalem')
    && (bool)array_filter($zc['cases'], fn($c) => $c['date'] === '2026-03-08' && $c['tz'] === 'America/New_York')
    && (bool)array_filter($zc['cases'], fn($c) => $c['tz'] === 'Atlantic/Reykjavik' && str_starts_with($c['date'], '2026-06'))
    && (bool)array_filter($zc['cases'], fn($c) => $c['expect']['sunset'] === null));

// ── השיטות: שיטת הבדיקה, רשימה לקוראים, K5 ───────────────────
$pub = $ok(call('GET', 'read-zmanim-methods', [], null), 'read-zmanim-methods בלי זיהוי');
eq([$pub['methods'], $pub['default']], [[], null], 'K5 בהתחלה: אין שיטה מאושרת ואין ברירת מחדל — הקורא חייב לבחור, ואין שעות');
$adm = $ok(call('GET', 'zmanim-method-list', [], $A), 'zmanim-method-list')['methods'];
$test = $adm[0] ?? [];
check('שיטת הבדיקה קיימת, מסומנת "לבדיקה בלבד", ולא מאושרת', count($adm) === 1 && $test['test'] === true && $test['status'] === 'draft' && str_contains($test['name'], 'לבדיקה בלבד'));
eq(code(call('POST', 'zmanim-method-status', ['method' => $test['pid'], 'status' => 'approved'], $A)), 'state', 'K5 שיטת הבדיקה לא ניתנת לאישור לציבור');
eq(code(call('POST', 'zmanim-method-default', ['method' => $test['pid']], $A)), 'state', 'K5 …ולא לברירת מחדל');
eq(sf_cal_method_errors($test['def']), [], 'הגדרת שיטת הבדיקה תקינה');

$def = ['day_end' => 'tz', 'zmanim' => [
    ['key' => 'sr', 'name' => 'זריחה', 'type' => 'angle', 'deg' => 0.833, 'dir' => 'am'],
    ['key' => 'shma', 'name' => 'סוף זמן קריאת שמע', 'type' => 'hours', 'from' => 'sr', 'to' => 'ss', 'hours' => 3],
    ['key' => 'ss', 'name' => 'שקיעה', 'type' => 'angle', 'deg' => 0.833, 'dir' => 'pm'],
    ['key' => 'tz', 'name' => 'צאת הכוכבים', 'type' => 'offset', 'base' => 'ss', 'min' => 18],
]];
$r = call('POST', 'zmanim-method-save', ['name' => 'א', 'def' => ['day_end' => 'x', 'zmanim' => [['key' => 'A', 'name' => '', 'type' => 'angle', 'deg' => 99]]], 'base' => 0], $A);
eq([$r[0], count($r[1]['error']['details']['errors'] ?? [])], [422, 5], 'הגדרה לא תקינה — 422 עם כל השגיאות');
$m1 = $ok(call('POST', 'zmanim-method-save', ['name' => 'שיטה א', 'note' => 'הערה', 'def' => $def, 'base' => 0], $A), 'שיטה חדשה')['method'];
eq([$m1['status'], $m1['version'], $m1['test']], ['draft', 1, false], 'שיטה חדשה — טיוטה, גרסה 1');
eq($ok(call('GET', 'read-zmanim-methods', [], null), '…')['methods'], [], 'טיוטה לא מגיעה לקוראים');
$ok(call('POST', 'zmanim-method-status', ['method' => $m1['pid'], 'status' => 'approved'], $A), 'אישור');
$pub = $ok(call('GET', 'read-zmanim-methods', [], null), '…');
eq([array_column($pub['methods'], 'pid'), $pub['default'], $pub['methods'][0]['def'] ?? null], [[$m1['pid']], null, $def], 'K5 מאושרת — ברשימה, עם ההגדרה; עדיין בלי ברירת מחדל');
check('לקוראים — בלי status / test / מי עדכן', !array_key_exists('status', $pub['methods'][0]) && !array_key_exists('updated_at', $pub['methods'][0]));
$ok(call('POST', 'zmanim-method-default', ['method' => $m1['pid']], $A), 'ברירת מחדל');
eq($ok(call('GET', 'read-zmanim-methods', [], null), '…')['default'], $m1['pid'], 'K5 ברירת המחדל של המנהל — יוצאת לקוראים');
eq(code(call('POST', 'zmanim-method-save', ['method' => $m1['pid'], 'name' => 'שיטה א', 'def' => $def, 'base' => 7], $A)), 'conflict', 'שמירה על גרסה ישנה — conflict');
$same = $ok(call('POST', 'zmanim-method-save', ['method' => $m1['pid'], 'name' => 'שיטה א׳', 'def' => $def, 'base' => 1], $A), 'שינוי שם בלבד');
eq([$same['method']['version'], $same['method']['status'], $same['method']['is_default'], $same['unapproved']], [1, 'approved', true, false], 'שינוי שם — אותה גרסה, נשארת מאושרת');
$def2 = $def;
$def2['zmanim'][3]['min'] = 20;
$ch = $ok(call('POST', 'zmanim-method-save', ['method' => $m1['pid'], 'name' => 'שיטה א׳', 'def' => $def2, 'base' => 1], $A), 'שינוי בהגדרה');
eq([$ch['method']['version'], $ch['method']['status'], $ch['method']['is_default'], $ch['unapproved'], $ch['method']['versions']], [2, 'draft', false, true, 2],
    'שינוי בהגדרה — גרסה 2, חוזרת לטיוטה (צריך לאשר שוב), ויוצאת מברירת המחדל');
eq($ok(call('GET', 'read-zmanim-methods', [], null), '…'), ['ok' => true, 'methods' => [], 'default' => null], 'K5 …ושוב אין לקוראים שעות עד אישור');
$mid = (int)sf_val('SELECT id FROM zmanim_methods WHERE pid = ?', [$m1['pid']]);
$blocked = function (string $sql) { try { sf_exec($sql); return false; } catch (Throwable $e) { return true; } };
check('גרסאות שיטה — רק מתווספות (טריגר חוסם עדכון ומחיקה)', $blocked("UPDATE zmanim_method_versions SET def = '{}' WHERE method_id = $mid") && $blocked("DELETE FROM zmanim_method_versions WHERE method_id = $mid"));
$ok(call('POST', 'zmanim-method-status', ['method' => $m1['pid'], 'status' => 'approved'], $A), 'אישור מחדש');

// ── fixture ל-node: אותם תאריכים / מקומות / שיטות ─────────────
$cities = json_decode((string)file_get_contents("$root/assets/data/cities.json"), true)['cities'];
check('רשימת הערים: מקור וייחוס (GeoNames, CC BY)', str_contains((string)file_get_contents("$root/assets/data/cities.json"), 'CC BY 4.0'));
$badTz = array_filter($cities, fn($c) => !sf_cal_valid_tz($c['tz']));
eq(array_values($badTz), [], 'רשימת הערים: אזורי זמן תקינים (IANA)');
$ids = array_column($cities, 'id');
eq(count($ids), count(array_unique($ids)), 'רשימת הערים: מזהים ייחודיים');
check('רשימת הערים: ישראל וקהילות בחו"ל', count(array_filter($cities, fn($c) => $c['cc'] === 'IL')) >= 50 && count(array_filter($cities, fn($c) => $c['cc'] !== 'IL' && $c['cc'] !== 'PS')) >= 50);
$methods = [$test['def'], $def, $zc['method'], ['day_end' => 'e', 'zmanim' => [['key' => 'a', 'name' => 'a', 'type' => 'angle', 'deg' => 16.1, 'dir' => 'am'],
    ['key' => 'b', 'name' => 'b', 'type' => 'offset', 'base' => 'a', 'min' => -72.5], ['key' => 'e', 'name' => 'e', 'type' => 'hours', 'from' => 'b', 'to' => 'a', 'hours' => 13 / 2]]]];
$zx = [];
mt_srand(77);
for ($i = 0; $i < 240; $i++) {
    $c = $cities[mt_rand(0, count($cities) - 1)];
    $g = sf_cal_greg_from_fixed(mt_rand(sf_cal_fixed_from_greg(2024, 1, 1), sf_cal_fixed_from_greg(2032, 12, 31)));
    $dd = $methods[$i % count($methods)];
    $pl = ['lat' => $c['lat'], 'lon' => $c['lon'], 'tz' => $c['tz']];
    $zx[] = ['place' => $pl, 'def' => $dd, 'y' => $g['y'], 'm' => $g['m'], 'd' => $g['d'], 'ms' => array_column(sf_cal_zmanim($dd, $pl, $g['y'], $g['m'], $g['d']), 'ms')];
}
foreach ($zc['cases'] as $c) foreach ([$zc['method'], $test['def']] as $dd) {
    [$y, $m, $d] = array_map('intval', explode('-', $c['date']));
    $pl = ['lat' => $c['lat'], 'lon' => $c['lon'], 'tz' => $c['tz']];
    $zx[] = ['place' => $pl, 'def' => $dd, 'y' => $y, 'm' => $m, 'd' => $d, 'ms' => array_column(sf_cal_zmanim($dd, $pl, $y, $m, $d), 'ms')];
}
$badDefs = [null, [], ['zmanim' => []], ['day_end' => 'a', 'zmanim' => [['key' => 'a', 'name' => 'x', 'type' => 'angle', 'deg' => 31, 'dir' => 'noon']]],
    ['day_end' => 'a', 'zmanim' => [['key' => 'a', 'name' => 'x', 'type' => 'offset', 'base' => 'b', 'min' => 5], ['key' => 'b', 'name' => 'y', 'type' => 'offset', 'base' => 'a', 'min' => 5]]],
    ['day_end' => 'a', 'zmanim' => [['key' => 'a', 'name' => 'x', 'type' => 'offset', 'base' => 'a', 'min' => 5]]],
    ['day_end' => 'q', 'zmanim' => [['key' => 'a', 'name' => 'x', 'type' => 'hours', 'from' => 'a', 'to' => 'zz', 'hours' => 13]]],
    ['day_end' => 'a', 'zmanim' => [['key' => 'a', 'name' => 'x', 'type' => 'angle', 'deg' => 5, 'dir' => 'am'], ['key' => 'a', 'name' => str_repeat('ש', 61), 'type' => 'magic']]],
    ['day_end' => 'a', 'zmanim' => [['key' => '9a', 'name' => 'x', 'type' => 'angle', 'deg' => '5', 'dir' => 'am']]],
    $def, $test['def']];
$today = [];
foreach ([[31.769, 35.2163, 'Asia/Jerusalem'], [40.7143, -74.006, 'America/New_York'], [69.649, 18.955, 'Europe/Oslo'], [-37.814, 144.963, 'Australia/Melbourne']] as [$la, $lo, $tz]) {
    foreach (['2026-11-22T15:00:00Z', '2026-11-22T23:30:00Z', '2026-06-21T22:00:00Z', '2026-03-27T16:45:00Z', '2026-10-25T00:30:00Z'] as $t) {
        $pl = ['lat' => $la, 'lon' => $lo, 'tz' => $tz];
        $r = sf_cal_today(strtotime($t) * 1000, $pl, $test['def']);
        $today[] = ['now' => strtotime($t) * 1000, 'place' => $pl, 'def' => $test['def'], 'want' => [$r['fixed'], $r['after'], $r['approx'], $r['end']]];
    }
}
$fx = ['dates' => $dates, 'php' => $php, 'zmanim' => $zx, 'methodErrors' => array_map(fn($d) => ['def' => $d, 'errors' => sf_cal_method_errors($d)], $badDefs),
    'today' => $today, 'testMethod' => $test['def'], 'publicMethods' => [], 'publicDefault' => null, 'cities' => $cities];
check('בדיקת שיטה: מעגל ותלות בעצמו — נתפסים', in_array('יש זמנים שתלויים זה בזה במעגל', sf_cal_method_errors($badDefs[4]), true) && sf_cal_method_errors($badDefs[5]) !== []);

// ── K2 / K4 / K7: ההלכה היומית ────────────────────────────────
$save = function (int $m, int $d, string $html) use ($ok, $A) {
    $base = $ok(call('GET', 'halacha-get', [], $A, ['month' => (string)$m, 'day' => (string)$d]), "halacha-get $m-$d")['item']['v'];
    $s = $ok(call('POST', 'halacha-save', ['month' => $m, 'day' => $d, 'html' => $html, 'base' => $base], $A), "halacha-save $m-$d")['item'];
    return $ok(call('POST', 'halacha-publish', ['month' => $m, 'day' => $d, 'base' => $s['v']], $A), "halacha-publish $m-$d")['item'];
};
$read = fn(int $y, int $m, int $d) => call('GET', 'read-halacha', [], null, ['y' => (string)$y, 'm' => (string)$m, 'd' => (string)$d]);
// הניקוי של 02 נותן sid לכל פסקה — משווים את הטקסט בלעדיו
$html = fn(array $r) => isset($r[1]['item']['html']) ? preg_replace('/ sid="[a-z0-9]{8}"/', '', $r[1]['item']['html']) : null;

$at('2026-03-03T08:00:00Z');   // י"ד אדר תשפ"ו — שנה פשוטה
$x = $save(13, 14, '<p>הלכות פורים — אדר ב\'</p>');
eq([$x['state'], $x['greg']], ['published', '2026-03-03'], 'הניהול: התאריך העברי והלועזי שהוא נופל בו השנה');
$save(12, 14, '<p>פורים קטן — אדר א\'</p>');
eq($html($read(5786, 13, 14)), '<p>הלכות פורים — אדר ב\'</p>', 'K2 שנה פשוטה: התוכן של אדר ב\' מוצג באדר');
eq(code($read(5786, 12, 14)), 'bad_input', 'K2 שנה פשוטה: אין אדר א\' (התוכן שלו לא מוצג בה)');
eq($ok(call('GET', 'halacha-get', [], $A, ['month' => '12', 'day' => '14']), '…')['item']['greg'], null, 'K2 הניהול: אדר א\' — "לא חל השנה" (בשנה פשוטה)');
$at('2027-03-23T08:00:00Z');   // י"ד אדר ב' תשפ"ז — מעוברת
eq([$html($read(5787, 12, 14)), $html($read(5787, 13, 14))], ['<p>פורים קטן — אדר א\'</p>', '<p>הלכות פורים — אדר ב\'</p>'],
    'K2 שנה מעוברת: אדר א\' ואדר ב\' — תוכן נפרד');

$at('2026-11-22T10:00:00Z');   // י"ב כסלו תשפ"ז (UTC)
eq($ok($read(5787, 9, 12), '…'), ['ok' => true, 'item' => null], 'K4 יום בלי הלכה — item: null (בלי הודעה ובלי חלופה)');
$save(9, 12, '<p>היום</p>');
$save(9, 13, '<p>מחר</p>');
$save(9, 14, '<p>מחרתיים</p>');
$save(9, 19, '<p>בעוד שבוע</p>');
eq($html($read(5787, 9, 12)), '<p>היום</p>', 'K7 היום — מוחזר');
eq($html($read(5787, 9, 13)), '<p>מחר</p>', 'K7 מחר — מוחזר (מרווח של יום לאזורי הזמן, הדפדפן מחליט בצאת הכוכבים)');
eq($ok($read(5787, 9, 14), '…')['item'], null, 'K7 מחרתיים — לא מוחזר');
eq($ok($read(5787, 9, 19), '…')['item'], null, 'K7 תוכן של בעוד שבוע — לא מוחזר מה-API');
eq($ok($read(5786, 9, 19), '…')['item'], null, 'K7 …גם כשמבקשים אותו תאריך בשנה שעברה');
$arch = $ok(call('GET', 'read-halacha-archive', [], null), 'read-halacha-archive')['items'];
$keys = array_map(fn($i) => $i['month'] . '-' . $i['day'], $arch);
check('K7 הארכיון: מה שהגיע, בלי העתידי', in_array('9-12', $keys, true) && in_array('9-13', $keys, true) && !in_array('9-14', $keys, true) && !in_array('9-19', $keys, true), $keys);
check('K7 הארכיון: פורים של השנה (אדר א\' / ב\' תשפ"ז) עוד לא הגיע — לא בארכיון', !in_array('12-14', $keys, true) && !in_array('13-14', $keys, true), $keys);
eq($ok(call('GET', 'read-halacha-archive', [], null, ['q' => 'שבוע']), '…')['items'], [], 'ארכיון: חיפוש לא מוצא תוכן עתידי');
eq($ok(call('GET', 'read-halacha-archive', [], null, ['q' => '100%_']), '…')['items'], [], 'ארכיון: % ו-_ בחיפוש — אותיות, לא תבנית');
$at('2026-11-29T10:00:00Z');
eq($html($read(5787, 9, 19)), '<p>בעוד שבוע</p>', 'K7 כשהמועד מגיע — מוחזר');
$at('2027-04-01T10:00:00Z');   // אחרי אדר ב' תשפ"ז
$keys = array_map(fn($i) => $i['month'] . '-' . $i['day'], $ok(call('GET', 'read-halacha-archive', [], null), '…')['items']);
eq($keys, ['9-12', '9-13', '9-14', '9-19', '12-14', '13-14'], 'הארכיון: לפי סדר השנה (כסלו … אדר א\' לפני אדר ב\')');
eq(array_map(fn($i) => $i['month'] . '-' . $i['day'], $ok(call('GET', 'read-halacha-archive', [], null, ['q' => 'פּוּרִים']), '…')['items']), ['12-14', '13-14'], 'ארכיון: חיפוש מתעלם מניקוד');
eq(array_map(fn($i) => $i['month'] . '-' . $i['day'], $ok(call('GET', 'read-halacha-archive', [], null, ['month' => '13']), '…')['items']), ['13-14'], 'ארכיון: לפי חודש');
$at('2026-11-22T10:00:00Z');

// טיוטה, ניקוי, פרסום
$base = $ok(call('GET', 'halacha-get', [], $A, ['month' => '9', 'day' => '12']), '…')['item']['v'];
$d1 = $ok(call('POST', 'halacha-save', ['month' => 9, 'day' => 12, 'html' => '<p onclick="x()">טיוטה <script>alert(1)</script>חדשה</p>', 'base' => $base], $A), 'טיוטה על הלכה שפורסמה')['item'];
check('הניקוי של 02: בלי סקריפט ובלי on*', !str_contains((string)$d1['draft_html'], 'script') && !str_contains((string)$d1['draft_html'], 'onclick'));
eq([$d1['state'], $html($read(5787, 9, 12))], ['changed', '<p>היום</p>'], 'טיוטה לא מגיעה לקורא — הוא רואה את מה שפורסם');
eq(code(call('POST', 'halacha-save', ['month' => 9, 'day' => 12, 'html' => '<p>x</p>', 'base' => $base], $A)), 'conflict', 'שמירה על בסיס ישן — conflict');
eq(code(call('POST', 'halacha-save', ['month' => 9, 'day' => 12, 'html' => '<p>לא נסגר', 'base' => $d1['v']], $A)), 'invalid', 'שגיאת מבנה — 422, לא נשמר');
eq(code(call('POST', 'halacha-save', ['month' => 14, 'day' => 1, 'html' => '<p>x</p>', 'base' => 0], $A)), 'bad_input', 'חודש 14 — לא תקין');
eq(code(call('POST', 'halacha-publish', ['month' => 9, 'day' => 20, 'base' => 0], $A)), 'state', 'אין טיוטה — אין פרסום');
$ok(call('POST', 'halacha-unpublish', ['month' => 9, 'day' => 13], $A), 'הסרת פרסום');
eq($ok($read(5787, 9, 13), '…')['item'], null, 'הסרת פרסום — הקורא לא רואה');
eq(preg_replace('/ sid="[a-z0-9]{8}"/', '', $ok(call('GET', 'halacha-get', [], $A, ['month' => '9', 'day' => '13']), '…')['item']['draft_html']), '<p>מחר</p>', '…והתוכן נשאר כטיוטה');
$list = $ok(call('GET', 'halacha-list', [], $A), 'halacha-list');
eq([$list['year'], $list['today']], [5787, ['month' => 9, 'day' => 12]], 'הלוח בניהול: השנה העברית והיום');
check('הלוח בניהול: בלי גוף ההלכה (רק מצב)', !array_key_exists('draft_html', $list['items'][0]));
check('יומן: שמירה ופרסום נרשמו, בלי התוכן', audit_count('halacha.publish') >= 6 && !str_contains((string)sf_val("SELECT GROUP_CONCAT(meta) FROM audit_log WHERE action LIKE 'halacha.%'"), 'היום'));

// ── K10: רק המיקום הפעיל נשמר ─────────────────────────────────
$GLOBALS['sf_mail_out'] = [];
rcall('POST', 'account-register', ['email' => 'loc@example.com', 'password' => 'loc-loc-loc-1'], 'L');
$locs = [['kind' => 'city', 'id' => 281184], ['kind' => 'city', 'id' => 295514], ['kind' => 'device', 'lat' => 32.08, 'lon' => 34.83, 'tz' => 'Asia/Jerusalem', 'near' => 'בני ברק']];
$rev = null;
foreach ($locs as $i => $l) {
    $it = ['pid' => 'pref:loc', 'kind' => 'pref', 'data' => $l] + ($rev !== null ? ['base' => $rev] : []);
    $res = rcall('POST', 'sync-push', ['items' => [$it]], 'L');
    eq($res[0], 200, 'K10 שינוי מיקום ' . ($i + 1) . ' נשלח');
    $rev = $res[1]['results'][0]['rev'] ?? null;
}
$rid = (int)sf_val("SELECT id FROM readers WHERE email = 'loc@example.com'");
$rows = sf_rows("SELECT pid, data FROM personal_items WHERE reader_id = ? AND pid = 'pref:loc'", [$rid]);
eq([count($rows), json_decode((string)($rows[0]['data'] ?? ''), true)], [1, $locs[2]], 'K10 אחרי שלושה שינויים — שורה אחת במסד, עם המיקום האחרון בלבד');
$dump = json_encode(sf_rows('SELECT * FROM audit_log'), JSON_UNESCAPED_UNICODE) . json_encode(sf_rows('SELECT * FROM jobs'));
check('K10 המיקום לא ביומן הפעולות ולא בתור המשימות', !str_contains($dump, '32.08') && !str_contains($dump, '281184') && !str_contains($dump, 'בני ברק'));
$tables = array_column(sf_rows("SELECT name FROM sqlite_master WHERE type = 'table'"), 'name');
check('K10 אין טבלה של מיקומים / היסטוריה', !preg_grep('/loc|geo|position_hist/i', $tables), $tables);

// ── node: אותו חישוב במכשיר ───────────────────────────────────
$pub = $ok(call('GET', 'read-zmanim-methods', [], null), '…');
$fx['publicMethods'] = $pub['methods'];
$fx['publicDefault'] = $pub['default'];
file_put_contents(SF_DATA_DIR . '/cal-fx.json', json_encode($fx, JSON_UNESCAPED_UNICODE));
exec('command -v node 2>/dev/null', $_n, $nodeCode);
check('node מותקן (בדיקות הלוח במכשיר)', $nodeCode === 0);
if ($nodeCode === 0) {
    exec('node ' . escapeshellarg(__DIR__ . '/calendar-core.mjs') . ' ' . escapeshellarg(SF_DATA_DIR . '/cal-fx.json') . ' 2>&1', $out, $code);
    $res = json_decode((string)end($out), true);
    eq($code, 0, 'calendar-core.mjs רץ' . ($code ? ': ' . implode(' | ', array_slice($res['fails'] ?? $out, 0, 6)) : ''));
    echo '  (calendar-core.mjs: ' . ($res['n'] ?? 0) . " בדיקות)\n";
}

// ── המסכים: שום דבר לא יורד מעצמו; המעטפת ─────────────────────
$daily = (string)file_get_contents("$root/assets/js/daily.js");
$js = '';
foreach (glob("$root/assets/js/{,screens/}*.js", GLOB_BRACE) as $f) $js .= file_get_contents($f);
preg_match_all('/SF\.daily\.download\(/', $js, $dl);
$zm = substr_count((string)file_get_contents("$root/assets/js/screens/zmanim.js"), 'SF.daily.download(') + substr_count((string)file_get_contents("$root/assets/js/screens/downloads.js"), 'SF.daily.download(');
eq(count($dl[0]), $zm, 'K8 הורדת החודש רק בלחיצה — במסך הזמנים ובמסך ההורדות');
check('K8 הורדת החודש: נשמרת במכשיר בלבד (setRaw, לא מסתנכרנת)', str_contains($daily, "SF.store.setRaw('zdl'") && !str_contains($daily, "SF.store.set('zdl'"));
$zmjs = (string)file_get_contents("$root/assets/js/screens/zmanim.js");
check('K9 מיקום המכשיר — מקום אחד בקוד, ונקרא רק מכפתור במסך הזמנים', substr_count($js, 'getCurrentPosition(') === 1 && str_contains($daily, 'getCurrentPosition(')
    && substr_count($js, 'SF.daily.devicePosition(') === 1 && str_contains($zmjs, 'SF.daily.devicePosition('));
preg_match('/if \(!r\.ok\) \{(.*?)return;/su', $zmjs, $deny);
check('K9 סירוב למיקום ← הודעה רגילה ורשימת הערים (לא toast של שגיאה)', str_contains($deny[1] ?? '', 'לא אושרה גישה למיקום. בחרו עיר מהרשימה.') && !str_contains($deny[1] ?? '', 'SF.toast') && str_contains($deny[1] ?? '', 'q.focus()'));
check('המיקום לא נשלח לשרת (רק דרך הסנכרון של 05)', !preg_match("/SF\\.api\\([^)]*(loc|lat)/", $js));
// 08 ⚙️: החריג היחיד — אורח שהפעיל Push שולח את ההגדרות שלו (כולל המיקום) ל-reminders-guest-sync, מוצפן בשרת (l-reminders)
check('08: המיקום יוצא רק ב-reminders-guest-sync של אורח', substr_count($js, 'loc: where') === 1 && str_contains((string)file_get_contents("$root/assets/js/reminders.js"), 'loc: where'));

done();
