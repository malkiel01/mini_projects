<?php
/**
 * בדיקות CLI — php grave-prices/tests/run.php. מסד זמני, לא נוגע ב-data/.
 * מיגרציה על מסד ריק ועל מסד של פרוסה 01, נתוני ההתחלה (מול ה-PDF), פענוח התוויות, הזהות החתומה של הכניסה
 * המנותקת, והעריכה של פרוסה 02 (02-editing.md סעיף 4).
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/gp-test-' . bin2hex(random_bytes(4));
putenv("GP_DATA_DIR=$tmp");
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/editions.php';
require __DIR__ . '/../lib/edit.php';

$fails = 0;
$n = 0;
function check(string $name, bool $ok, string $got = ''): void
{
    global $fails, $n;
    $n++;
    if ($ok) return;
    $fails++;
    echo "✗ $name" . ($got !== '' ? " — קיבלנו: $got" : '') . "\n";
}

// --- מיגרציה ונתוני ההתחלה
$pdo = db();
check('גרסת המסד', (int)$pdo->query('PRAGMA user_version')->fetchColumn() === count(gp_migrations()));
$list = gp_editions()['editions'];
check('שני מחירונים', count($list) === 2, (string)count($list));
check('מהחדש לישן', array_column($list, 'effective_from') === ['2025-03-15', '2024-01-02']);
check('"בתוקף היום" — רק החדש', $list[0]['in_effect'] === true && $list[1]['in_effect'] === false);
check('pid — 12 תווים', (bool)preg_match('/^[a-z0-9]{12}$/', $list[0]['pid']));

$sum = fn(array $e, string $a) => array_sum(array_map(fn($r) => (int)($r['prices'][$a] ?? 0), $e['rows']));
$e25 = gp_edition($list[0]['pid']);
$e24 = gp_edition($list[1]['pid']);
check('28 שורות ב-2025', count($e25['rows']) === 28, (string)count($e25['rows']));
check('סכום מחירי ישראל 2025 = 584,979', $sum($e25, 'il') === 584979, (string)$sum($e25, 'il'));
check('סכום מחירי ישראל 2024 = 574,099', $sum($e24, 'il') === 574099, (string)$sum($e24, 'il'));
check('חו"ל ריק', $sum($e25, 'abroad') === 0 && $e25['rows'][2]['prices']['abroad'] === null);
check('השורה הראשונה במחיר', $e25['rows'][2]['prices']['il'] === 16947);
check('כותרת בלי מחירים', $e25['rows'][0]['kind'] === 'section' && $e25['rows'][0]['prices'] === null);
check('שלוש חתימות', count($e25['signatures']) === 3 && $e25['signatures'][0]['name'] === 'אבשלום מלכה');

// מיגרציה שנייה (פריסה חוזרת) לא טוענת שוב, ולא נוגעת בשינוי
$pdo->exec("UPDATE prices SET amount = 1 WHERE audience = 'il' AND row_id = (SELECT MIN(row_id) FROM prices)");
$pdo->exec('PRAGMA user_version = 1');   // כאילו המיגרציה של נתוני ההתחלה טרם רצה
gp_migrate($pdo);
check('נתוני ההתחלה לא נטענים שוב', (int)$pdo->query('SELECT COUNT(*) FROM editions')->fetchColumn() === 2);
check('שינוי נשמר', (int)$pdo->query("SELECT amount FROM prices WHERE row_id = (SELECT MIN(row_id) FROM prices) AND audience = 'il'")->fetchColumn() === 1);

// --- הסימון בתוויות
$cases = [
    '**__לתושב ירושלים__**' => '<b><u>לתושב ירושלים</u></b>',
    '**__לתושבי ירושלים__** ((2 בודדים מחיים))' => '<b><u>לתושבי ירושלים</u></b> <span class="plain">(2 בודדים מחיים)</span>',
    '**בגוש 26** (חלקות א-ב)' => '<b>בגוש 26</b> (חלקות א-ב)',
    '** תשלום עבור העברת בעלות' => '** תשלום עבור העברת בעלות',
    '** המחירון מתייחס לרכישת אחוזת הקבר __בלבד__' => '** המחירון מתייחס לרכישת אחוזת הקבר <u>בלבד</u>',
    '<b>x</b> & "y"' => '&lt;b&gt;x&lt;/b&gt; &amp; &quot;y&quot;',
    '**<script>alert(1)</script>**' => '<b>&lt;script&gt;alert(1)&lt;/script&gt;</b>',
    'יו"ר' => 'יו&quot;ר',
];
foreach ($cases as $in => $want) {
    $got = gp_label_html($in);
    check("תווית: $in", $got === $want, $got);
}

// --- הזהות החתומה (כניסה מנותקת, sso.md סעיף 2)
$kp = sodium_crypto_sign_keypair();
$b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$pub = $b64(sodium_crypto_sign_publickey($kp));
$sign = function (array $p) use ($kp, $b64) {
    $payload = $b64(json_encode($p));
    return $payload . '.' . $b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($kp)));
};
$good = ['aud' => 'grave-prices', 'sub' => '17', 'name' => 'דנה', 'state' => 'abc12345', 'return' => gp_sso_return(), 'exp' => time() + 60];
check('כתובת החזרה מ-app.json', gp_sso_return() === 'https://mbe-plus.com/mini_projects/grave-prices/api.php?action=sso_callback');
check('כתובת sso.php', gp_sso_url() === 'https://mbe-plus.com/mini_projects/platform/sso.php', gp_sso_url());
check('זהות תקינה', is_array(gp_sso_verify($sign($good), 'abc12345', $pub)));
check('state אחר', gp_sso_verify($sign($good), 'zzz12345', $pub) === 'זהות לא תקפה');
check('aud אחר', gp_sso_verify($sign(['aud' => 'other'] + $good), 'abc12345', $pub) === 'זהות לא תקפה');
check('פג תוקף', gp_sso_verify($sign(['exp' => time() - 1] + $good), 'abc12345', $pub) === 'זהות לא תקפה');
check('חתימה מזויפת', gp_sso_verify($sign($good) . 'x', 'abc12345', $pub) === 'חתימה לא תקינה');

// --- המשתמשים: חשבון נפתח פעם אחת, is_dev לפי platform_admin, וזהות מ-sso לא משנה אותו
$u = gp_user_upsert('17', 'דנה', 'd@example.com', true);
$u2 = gp_user_upsert('17', 'דנה לוי', '', null);
check('חשבון אחד ל-sub', (int)$u['id'] === (int)$u2['id'] && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1);
check('is_dev נשמר בכניסה בלי platform_admin', (int)$u2['is_dev'] === 1 && $u2['email'] === 'd@example.com');
check('is_dev יורד', (int)gp_user_upsert('17', 'דנה', '', false)['is_dev'] === 0);

// --- מיגרציה 3 על מסד של פרוסה 01 (נתונים חיים): מוסיפה pid לשורות, לא מוחקת ולא טוענת מחדש
$tmp01 = "$tmp-01";
mkdir($tmp01);
$old = new PDO("sqlite:$tmp01/app.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
gp_migrations()[1]($old);
gp_migrations()[2]($old);
$old->exec('PRAGMA user_version = 2');
$old->exec("UPDATE prices SET amount = 12345 WHERE row_id = 3 AND audience = 'il'");   // שינוי שנעשה בשרת
$dump = fn(PDO $p) => $p->query('SELECT r.id, r.edition_id, r.sort, r.kind, r.indent, r.label, p.audience, p.amount FROM rows r
    LEFT JOIN prices p ON p.row_id = r.id ORDER BY r.id, p.audience')->fetchAll();
$before = $dump($old);
$edsBefore = $old->query('SELECT * FROM editions ORDER BY id')->fetchAll();
gp_migrate($old);
check('01→02: גרסת המסד', (int)$old->query('PRAGMA user_version')->fetchColumn() === count(gp_migrations()));
check('01→02: אותן שורות ואותם מחירים', $dump($old) === $before);
check('01→02: אותם מחירונים', $old->query('SELECT * FROM editions ORDER BY id')->fetchAll() === $edsBefore);
check('01→02: השינוי נשמר', (int)$old->query("SELECT amount FROM prices WHERE row_id = 3 AND audience = 'il'")->fetchColumn() === 12345);
check('01→02: לכל שורה pid ייחודי', (int)$old->query("SELECT COUNT(DISTINCT pid) FROM rows WHERE pid GLOB '[a-z0-9]*' AND length(pid) = 12")->fetchColumn()
    === (int)$old->query('SELECT COUNT(*) FROM rows')->fetchColumn());
gp_migrate($old);
check('01→02: מיגרציה חוזרת לא משנה', $dump($old) === $before);
$old = null;
foreach (glob("$tmp01/*") ?: [] as $f) @unlink($f);
@rmdir($tmp01);

// --- העריכה (פרוסה 02)
$pdo->exec("UPDATE prices SET amount = 16008 WHERE amount = 1");   // מחזירים את מה שהבדיקה של המיגרציה שינתה
$uid = (int)gp_user_upsert('99', 'עורך', '', false)['id'];
$err = function (callable $fn): array {
    try { $fn(); return [0, '', []]; } catch (ApiError $e) { return [$e->getCode(), $e->getMessage(), $e->extra]; }
};
$find = fn(array $e, string $needle, int $nth = 0) => array_values(array_filter($e['rows'], fn($r) => str_contains($r['label'], $needle)))[$nth];
[$p25, $p24] = [$list[0]['pid'], $list[1]['pid']];
$jer = $find(gp_edition($p25), 'לתושב ירושלים')['pid'];
$future = (new DateTimeImmutable(gp_today()))->modify('+1 year')->format('Y-m-d');

// 3 — בלי confirm_in_effect על מחירון בתוקף: 409, ולא השתנה
$r = $err(fn() => gp_price_set($uid, ['pid' => $p25, 'row' => $jer, 'audience' => 'il', 'amount' => 17000]));
check('409 על מחירון בתוקף', $r[0] === 409 && ($r[2]['need_confirm'] ?? false) === true, json_encode($r, JSON_UNESCAPED_UNICODE));
check('409 — המחיר לא השתנה', $find(gp_edition($p25), 'לתושב ירושלים')['prices']['il'] === 16947);
// 12 — קלט לא תקין
foreach ([-5, 'abc', '1.5', 1.5, '-5', 10000001, '1,000', true] as $bad) {
    $r = $err(fn() => gp_price_set($uid, ['pid' => $p25, 'row' => $jer, 'audience' => 'il', 'amount' => $bad, 'confirm_in_effect' => true]));
    check('400 על ' . json_encode($bad), $r[0] === 400 && $r[1] !== '', json_encode($r, JSON_UNESCAPED_UNICODE));
}
check('אחרי קלט לא תקין — לא נשמר', $find(gp_edition($p25), 'לתושב ירושלים')['prices']['il'] === 16947);

// 4 — מחירון חדש עם אחוז על "חלקות פטורות" (קודם, כשהבסיס עדיין 16,947)
$sec = $find(gp_edition($p25), 'חלקות פטורות')['pid'];
$new = gp_edition_create($uid, ['effective_from' => $future, 'base_pid' => $p25, 'raise' => [$sec => ['il' => 5.866, 'abroad' => 5.866], 'fee' => ['il' => 0, 'abroad' => 0]]]);
$en = gp_edition($new['pid']);
$e25 = gp_edition($p25);
check('חדש: לתושב ירושלים = 17,941', $find($en, 'לתושב ירושלים')['prices']['il'] === 17941, (string)$find($en, 'לתושב ירושלים')['prices']['il']);
check('חדש: בגוש 26 נשאר 70,000', $find($en, 'בגוש 26')['prices']['il'] === 70000);
check('חדש: תשלומים כמו קודם', $find($en, 'העברת בעלות')['prices']['il'] === 3000);
check('חדש: חו"ל ריק נשאר ריק', $find($en, 'לתושב ירושלים')['prices']['abroad'] === null);
check('חדש: החתימות הועתקו', $en['signatures'] === $e25['signatures']);
check('חדש: אותן שורות', array_column($en['rows'], 'label') === array_column($e25['rows'], 'label'));
check('חדש: pid חדש לשורות', !array_intersect(array_column($en['rows'], 'pid'), array_column($e25['rows'], 'pid')));
check('חדש: עתידי', $en['future'] === true);
check('הבסיס לא השתנה', $find($e25, 'לתושב ירושלים')['prices']['il'] === 16947);

// 6 — תאריך שכבר קיים
$r = $err(fn() => gp_edition_create($uid, ['effective_from' => $future, 'base_pid' => $p25]));
check('תאריך קיים — שגיאה', $r[1] === 'כבר יש מחירון בתאריך הזה');
check('תאריך קיים — לא נוצר דבר', count(gp_editions()['editions']) === 3);
check('תאריך לא תקין', $err(fn() => gp_edition_create($uid, ['effective_from' => '2026-02-30', 'base_pid' => $p25]))[0] === 400);
check('אחוז לא תקין', $err(fn() => gp_edition_create($uid, ['effective_from' => '2031-01-01', 'base_pid' => $p25, 'raise' => [$sec => ['il' => 'x']]]))[0] === 400);
check('אחוז לשורה זרה', $err(fn() => gp_edition_create($uid, ['effective_from' => '2031-01-01', 'base_pid' => $p25, 'raise' => [$jer => ['il' => 5]]]))[0] === 400);

// 5 — העתק בלי אחוזים זהה, ועריכה בעתידי בלי אישור
$copy = gp_edition_create($uid, ['effective_from' => '2031-01-01', 'base_pid' => $p25]);
$ec = gp_edition($copy['pid']);
$strip = fn(array $e) => array_map(fn($r) => [$r['kind'], $r['indent'], $r['label'], $r['prices']], $e['rows']);
check('העתק זהה', $strip($ec) === $strip($e25));
$fj = $find($en, 'לתושב ירושלים')['pid'];
check('עתידי — בלי אישור', gp_price_set($uid, ['pid' => $new['pid'], 'row' => $fj, 'audience' => 'abroad', 'amount' => '25000'])['amount'] === 25000);

// 1 + 2 — המבחן: שינוי עם אישור, 2024 לא נפגע, והיומן
gp_price_set($uid, ['pid' => $p25, 'row' => $jer, 'audience' => 'il', 'amount' => '17000', 'confirm_in_effect' => true]);
check('המחיר נשמר', $find(gp_edition($p25), 'לתושב ירושלים')['prices']['il'] === 17000);
check('2024 לא נפגע', $find(gp_edition($p24), 'לתושב ירושלים')['prices']['il'] === 16008);
check('העתיד לא נפגע', $find(gp_edition($new['pid']), 'לתושב ירושלים')['prices']['il'] === 17941);
$h = gp_history($p25)['history'][0];
check('ביומן: ישן → חדש', $h['action'] === 'price' && $h['details']['old'] === 16947 && $h['details']['new'] === 17000
    && $h['details']['audience'] === 'il' && $h['user'] === 'עורך', json_encode($h, JSON_UNESCAPED_UNICODE));
// 13 — שני חלונות: האחרון גובר, ושניהם ביומן
gp_price_set($uid, ['pid' => $p25, 'row' => $jer, 'audience' => 'il', 'amount' => 17100, 'confirm_in_effect' => true]);
gp_price_set($uid, ['pid' => $p25, 'row' => $jer, 'audience' => 'il', 'amount' => 17000, 'confirm_in_effect' => true]);
$h = array_slice(gp_history($p25)['history'], 0, 2);
check('האחרון גובר, שניהם ביומן', $find(gp_edition($p25), 'לתושב ירושלים')['prices']['il'] === 17000
    && $h[0]['details']['old'] === 17100 && $h[1]['details']['new'] === 17100);
check('ריק = אין מחיר', gp_price_set($uid, ['pid' => $new['pid'], 'row' => $fj, 'audience' => 'abroad', 'amount' => ''])['amount'] === null);
check('שורה בלי מחיר', $err(fn() => gp_price_set($uid, ['pid' => $new['pid'], 'row' => $find($en, 'קבורת שדה')['pid'], 'audience' => 'il', 'amount' => 1]))[0] === 400);
check('שורה ממחירון אחר', $err(fn() => gp_price_set($uid, ['pid' => $new['pid'], 'row' => $jer, 'audience' => 'il', 'amount' => 1]))[0] === 404);

// 8, 9 — שורות: רק במחירון הזה
$gush = $find($en, 'בגוש 26')['pid'];
gp_row_delete($uid, ['pid' => $new['pid'], 'row' => $gush]);
check('מחיקת שורה — נעלמה ממנו', count(gp_edition($new['pid'])['rows']) === 27);
check('מחיקת שורה — ב-2025 קיימת', count(gp_edition($p25)['rows']) === 28);
check('מחיקת שורה ב-2025 — 409', $err(fn() => gp_row_delete($uid, ['pid' => $p25, 'row' => $jer]))[0] === 409);
$added = gp_row_save($uid, ['pid' => $new['pid'], 'after' => $fj, 'kind' => 'item', 'indent' => 1, 'label' => '**חדש** __בדיקה__'])['row'];
$en = gp_edition($new['pid']);
$ix = array_search($added, array_column($en['rows'], 'pid'), true);
check('שורה חדשה מתחת', $en['rows'][$ix - 1]['pid'] === $fj && $en['rows'][$ix]['indent'] === 1);
check('תווית: מודגש וקו', $en['rows'][$ix]['html'] === '<b>חדש</b> <u>בדיקה</u>', $en['rows'][$ix]['html']);
gp_row_move($uid, ['pid' => $new['pid'], 'row' => $added, 'dir' => 'up']);
check('הזזה למעלה', array_search($added, array_column(gp_edition($new['pid'])['rows'], 'pid'), true) === $ix - 1);
gp_row_save($uid, ['pid' => $new['pid'], 'row' => $added, 'kind' => 'note', 'indent' => 0, 'label' => 'הערה']);
check('עריכת שורה', $find(gp_edition($new['pid']), 'הערה')['kind'] === 'note');
check('תווית ריקה', $err(fn() => gp_row_save($uid, ['pid' => $new['pid'], 'kind' => 'item', 'indent' => 0, 'label' => '  ']))[0] === 400);
check('סוג לא מוכר', $err(fn() => gp_row_save($uid, ['pid' => $new['pid'], 'kind' => 'x', 'indent' => 0, 'label' => 'a']))[0] === 400);
check('תווית ארוכה', $err(fn() => gp_row_save($uid, ['pid' => $new['pid'], 'kind' => 'item', 'indent' => 0, 'label' => str_repeat('א', 201)]))[0] === 400);

// חתימות
$sig = [['name' => 'א', 'role' => 'ב'], ['name' => 'ג', 'role' => 'ד'], ['name' => 'ה', 'role' => 'ו']];
gp_signatures_set($uid, ['pid' => $new['pid'], 'signatures' => $sig]);
check('חתימות — למחירון הזה בלבד', gp_edition($new['pid'])['signatures'] === $sig && gp_edition($p25)['signatures'] !== $sig);
check('חתימות — בדיוק שלוש', $err(fn() => gp_signatures_set($uid, ['pid' => $new['pid'], 'signatures' => [$sig[0]]]))[0] === 400);

// תאריך — רק עתידי, בלי התנגשות
check('תאריך — התנגשות', $err(fn() => gp_edition_update($uid, ['pid' => $new['pid'], 'effective_from' => '2031-01-01']))[0] === 409);
gp_edition_update($uid, ['pid' => $new['pid'], 'effective_from' => '2032-02-02']);
check('תאריך — שונה', gp_edition($new['pid'])['effective_from'] === '2032-02-02');
check('תאריך — לא למחירון בתוקף', $err(fn() => gp_edition_update($uid, ['pid' => $p25, 'effective_from' => '2033-01-01']))[0] === 403);

// 10, 11 — מחיקה: רק עתידי
check('מחיקת 2025 — 403', $err(fn() => gp_edition_delete($uid, ['pid' => $p25]))[0] === 403);
check('מחיקת 2024 — 403', $err(fn() => gp_edition_delete($uid, ['pid' => $p24]))[0] === 403);
$delId = (int)gp_edition_row($copy['pid'])['id'];
gp_edition_delete($uid, ['pid' => $copy['pid']]);
check('מחיקת עתידי', count(gp_editions()['editions']) === 3 && $err(fn() => gp_edition($copy['pid']))[0] === 404);
check('מחיקה — ביומן עם עותק', (bool)$pdo->query("SELECT 1 FROM audit WHERE action = 'edition-delete' AND json_extract(details, '$.copy.rows[2].prices.il') = 16947")->fetchColumn());
check('מחיקה — לא נשארו שורות יתומות', (int)$pdo->query("SELECT COUNT(*) FROM rows WHERE edition_id = $delId")->fetchColumn() === 0);
$again = gp_edition_create($uid, ['effective_from' => '2031-01-01', 'base_pid' => $p25]);
check('מזהה של מחירון שנמחק לא חוזר', (int)gp_edition_row($again['pid'])['id'] !== $delId && count(gp_history($again['pid'])['history']) === 1);
check('"בתוקף היום" — 2025, לא העתידי', array_column(array_filter(gp_editions()['editions'], fn($e) => $e['in_effect']), 'pid') === [$p25]);

// ניקוי
foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
@rmdir($tmp);

echo $fails ? "\n$fails מתוך $n בדיקות נכשלו\n" : "✓ $n בדיקות עברו\n";
exit($fails ? 1 : 0);
