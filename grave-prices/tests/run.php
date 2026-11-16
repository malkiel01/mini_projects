<?php
/**
 * בדיקות CLI — php grave-prices/tests/run.php. מסד זמני, לא נוגע ב-data/.
 * מיגרציה על מסד ריק ועל מסד של פרוסה 01, נתוני ההתחלה (מול ה-PDF), פענוח התוויות, הזהות החתומה של הכניסה
 * המנותקת, העריכה של פרוסה 02 (02-editing.md סעיף 4), ופרוסה 03 — מיגרציה 4 על מסד חי ומחירונים לכל קבוצה.
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

// --- מיגרציה ונתוני ההתחלה (מסד ריק — 03 קריטריון 3 + תוספת הבקרה)
$pdo = db();
check('גרסת המסד', (int)$pdo->query('PRAGMA user_version')->fetchColumn() === count(gp_migrations()));
$all = gp_editions()['editions'];
$list = array_values(array_filter($all, fn($e) => $e['audience'] === 'il'));
$abr = array_values(array_filter($all, fn($e) => $e['audience'] === 'abroad'));
check('ישראל ×3, חו"ל ×1', count($list) === 3 && count($abr) === 1, count($list) . '/' . count($abr));
check('ישראל — מהחדש לישן', array_column($list, 'effective_from') === ['2026-06-15', '2025-03-15', '2024-01-02']);
check('חו"ל — 15/06/2026', $abr[0]['effective_from'] === '2026-06-15');
check('"בתוקף היום" — לכל קבוצה', array_column(array_filter($all, fn($e) => $e['in_effect']), 'audience') === ['il', 'abroad']
    && $list[0]['in_effect'] && $abr[0]['in_effect'], json_encode($all, JSON_UNESCAPED_UNICODE));
check('?audience=abroad', array_column(gp_editions('abroad')['editions'], 'audience') === ['abroad']);
check('pid — 12 תווים', (bool)preg_match('/^[a-z0-9]{12}$/', $list[0]['pid']));
check('foreign_key_check ריק', $pdo->query('PRAGMA foreign_key_check')->fetchAll() === []);

$sum = fn(array $e) => array_sum(array_map(fn($r) => (int)($r['price'] ?? 0), $e['rows']));
$e26 = gp_edition($list[0]['pid']);
$e25 = gp_edition($list[1]['pid']);
$e24 = gp_edition($list[2]['pid']);
$ab = gp_edition($abr[0]['pid']);
check('28 שורות ב-2025', count($e25['rows']) === 28, (string)count($e25['rows']));
check('סכום ישראל 2025 = 584,979', $sum($e25) === 584979, (string)$sum($e25));
check('סכום ישראל 2024 = 574,099', $sum($e24) === 574099, (string)$sum($e24));
check('ישראל 2026: 28 שורות, סכום 599,979', count($e26['rows']) === 28 && $sum($e26) === 599979, $sum($e26) . '');
$olive = array_values(array_filter($e26['rows'], fn($r) => str_contains($r['label'], 'בכל החלקות')));
check('ישראל 2026: הר הזיתים 60,000 / 80,000', array_column($olive, 'price') === [60000, 80000]);
check('ישראל 2026: אותן שורות ואותן חתימות כמו 2025', array_column($e26['rows'], 'label') === array_column($e25['rows'], 'label')
    && $e26['signatures'] === $e25['signatures']);
check('חו"ל: 32 שורות, סכום 1,501,400', count($ab['rows']) === 32 && $sum($ab) === 1501400, count($ab['rows']) . '/' . $sum($ab));
check('חו"ל: ארבעה פסים', count(array_filter($ab['rows'], fn($r) => $r['kind'] === 'band')) === 4);
check('חו"ל: "יו"ר המועצה"', $ab['signatures'][2]['role'] === 'יו"ר המועצה');
check('כותרות משנה', $ab['subtitle'] === 'רכישת קבר בחיים לתושבי חו"ל' && $e25['subtitle'] === 'רכישת אחוזת קבר לתושבי ישראל');
check('השורה הראשונה במחיר', $e25['rows'][2]['price'] === 16947 && $e25['rows'][2]['priced'] === true);
check('כותרת בלי מחיר', $e25['rows'][0]['kind'] === 'section' && $e25['rows'][0]['price'] === null && !$e25['rows'][0]['priced']);
check('שלוש חתימות', count($e25['signatures']) === 3 && $e25['signatures'][0]['name'] === 'אבשלום מלכה');
// seed/israel-2026-06-15.json = 2025 מ-editions.json, חוץ משתי השורות של הר הזיתים
$s25 = array_values(array_filter(json_decode(file_get_contents(__DIR__ . '/../lib/seed/editions.json'), true)['editions'], fn($e) => $e['effective_from'] === '2025-03-15'))[0];
$s26 = gp_seed_file('israel-2026-06-15.json');
$diff = [];
foreach ($s25['rows'] as $k => $r) {
    $x = $s26['rows'][$k];
    if ([$r['kind'], $r['indent'], $r['label']] !== [$x['kind'], $x['indent'], $x['label']]) $diff[] = "row $k";
    elseif (($r['prices']['il'] ?? null) !== ($x['price'] ?? null)) $diff[] = ($r['prices']['il'] ?? 'null') . '→' . ($x['price'] ?? 'null');
}
check('seed ישראל 2026 = 2025 חוץ מהר הזיתים', count($s25['rows']) === count($s26['rows']) && $diff === ['50000→60000', '75000→80000']
    && $s26['signatures'] === $s25['signatures'], implode(', ', $diff));
check('ביומן: seed', (int)$pdo->query("SELECT COUNT(*) FROM audit WHERE action IN ('seed-il-2026-06-15', 'seed-abroad-2026-06-15')")->fetchColumn() === 2);
check('מסד ריק — אין גיבוי', glob("$tmp/*.bak") === []);

// מיגרציה שנייה (פריסה חוזרת) לא טוענת שוב, ולא נוגעת בשינוי
$pdo->exec("UPDATE prices SET amount = 1 WHERE audience = 'il' AND row_id = (SELECT MIN(row_id) FROM prices)");
$pdo->exec('PRAGMA user_version = 1');   // כאילו המיגרציה של נתוני ההתחלה טרם רצה
gp_migrate($pdo);
check('נתוני ההתחלה לא נטענים שוב', (int)$pdo->query('SELECT COUNT(*) FROM editions')->fetchColumn() === 4);
check('פריסה שנייה — אין seed נוסף', (int)$pdo->query("SELECT COUNT(*) FROM audit WHERE action LIKE 'seed-%'")->fetchColumn() === 2);
check('לפני מיגרציה על מסד קיים — גיבוי', count(glob("$tmp/app.sqlite.v1-*.bak")) === 1);
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

// --- מסד ישן (נתונים חיים) → המיגרציות עד הסוף. $upTo — הגרסה שבשרת; $edit — מה שהבעלים עשה בו לפני הפריסה.
$opened = [];
$legacy = function (string $name, int $upTo, callable $edit) use ($tmp, &$opened): array {
    $dir = "$tmp-$name";
    mkdir($dir);
    $opened[] = $dir;
    $p = new PDO("sqlite:$dir/app.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec('PRAGMA foreign_keys = ON');
    for ($k = 1; $k <= $upTo; $k++) gp_migrations()[$k]($p);
    $p->exec("PRAGMA user_version = $upTo");
    $edit($p);
    return [$p, $dir];
};
// כל מה שהיה — שורות, כל רשומות המחיר (גם של חו"ל), המחירונים, היומן — בדיוק כמו שהיה
$dump = fn(PDO $p, int $maxId) => $p->query("SELECT r.id, r.edition_id, r.sort, r.kind, r.indent, r.label, p.audience, p.amount FROM rows r
    LEFT JOIN prices p ON p.row_id = r.id WHERE r.edition_id <= $maxId ORDER BY r.id, p.audience")->fetchAll();
$eds = fn(PDO $p, int $maxId) => $p->query("SELECT id, pid, effective_from, signatures, created_by, created_at, updated_at FROM editions
    WHERE id <= $maxId ORDER BY id")->fetchAll();
$audit = fn(PDO $p) => $p->query("SELECT * FROM audit WHERE action NOT LIKE 'seed-%' AND action <> 'migrate-abroad' ORDER BY id")->fetchAll();
$byAud = fn(PDO $p, string $a) => $p->query("SELECT id, pid, effective_from, subtitle, signatures FROM editions WHERE audience = '$a' ORDER BY effective_from")->fetchAll();
$priceOf = fn(PDO $p, int $eid, string $a) => $p->query("SELECT r.label, p.amount FROM rows r LEFT JOIN prices p ON p.row_id = r.id AND p.audience = '$a'
    WHERE r.edition_id = $eid ORDER BY r.sort, r.id")->fetchAll(PDO::FETCH_NUM);

// 01 (גרסה 2) → 4: pid לשורות, ומחירוני 03
[$old, $dir] = $legacy('01', 2, fn(PDO $p) => $p->exec("UPDATE prices SET amount = 12345 WHERE row_id = 3 AND audience = 'il'"));
$before = [$dump($old, 2), $eds($old, 2)];
gp_migrate($old);
check('01→: גרסת המסד', (int)$old->query('PRAGMA user_version')->fetchColumn() === count(gp_migrations()));
check('01→: אותן שורות, מחירים ומחירונים', [$dump($old, 2), $eds($old, 2)] === $before);
check('01→: השינוי נשמר', (int)$old->query("SELECT amount FROM prices WHERE row_id = 3 AND audience = 'il'")->fetchColumn() === 12345);
check('01→: לכל שורה pid ייחודי', (int)$old->query("SELECT COUNT(DISTINCT pid) FROM rows WHERE pid GLOB '[a-z0-9]*' AND length(pid) = 12")->fetchColumn()
    === (int)$old->query('SELECT COUNT(*) FROM rows')->fetchColumn());
gp_migrate($old);
check('01→: מיגרציה חוזרת לא משנה', [$dump($old, 2), $eds($old, 2)] === $before);

// 03 קריטריון 1 — מסד של 02 עם עריכות, בלי מחירי חו"ל
[$old, $dir] = $legacy('02a', 3, function (PDO $p) {
    $p->exec("UPDATE prices SET amount = 17000 WHERE row_id = 3 AND audience = 'il'");
    $p->exec("INSERT INTO audit (at, user_id, action, edition_id, details) VALUES ('2026-11-16T15:00:00Z', 1, 'price', 2, '{\"old\":16947,\"new\":17000}')");
    $p->exec("UPDATE rows SET label = 'שורה שתוקנה' WHERE id = 40");
    $p->exec("DELETE FROM prices WHERE row_id = 41");
    $p->exec("DELETE FROM rows WHERE id = 41");
    $p->exec("INSERT INTO rows (edition_id, sort, kind, indent, label, pid) VALUES (2, 285, 'item', 1, 'שורה חדשה', 'newrow000001')");
    $p->exec("INSERT INTO prices (row_id, audience, amount) VALUES (last_insert_rowid(), 'il', 4321)");
    $p->exec("UPDATE editions SET signatures = '[{\"name\":\"א\",\"role\":\"ב\"},{\"name\":\"ג\",\"role\":\"ד\"},{\"name\":\"ה\",\"role\":\"ו\"}]' WHERE id = 1");
    $p->exec("INSERT INTO editions (id, pid, effective_from, signatures, created_at, updated_at) VALUES (5, 'future000001', '2027-01-01', '[]', 'x', 'x')");
});
$before = [$dump($old, 5), $eds($old, 5), $audit($old)];
gp_migrate($old);
check('02→03: הכול נשמר — שורות, מחירים, מחירונים, יומן', [$dump($old, 5), $eds($old, 5), $audit($old)] === $before);
check('02→03: foreign_key_check ריק', $old->query('PRAGMA foreign_key_check')->fetchAll() === []);
$il = $byAud($old, 'il');
check('02→03: ישראל — 2024, 2025, 2026 שנוסף, 2027', array_column($il, 'effective_from') === ['2024-01-02', '2025-03-15', '2026-06-15', '2027-01-01']);
check('02→03: subtitle של ישראל', array_unique(array_column($il, 'subtitle')) === [GP_SUBTITLES['il']]);
$ab = $byAud($old, 'abroad');
check('02→03: חו"ל — רק 15/06/2026', array_column($ab, 'effective_from') === ['2026-06-15']);
check('02→03: חו"ל — 32 שורות, 1,501,400', array_sum(array_column($priceOf($old, (int)$ab[0]['id'], 'abroad'), 1)) === 1501400
    && count($priceOf($old, (int)$ab[0]['id'], 'abroad')) === 32);
$il26 = $priceOf($old, (int)$il[2]['id'], 'il');
check('02→03: ישראל 2026 — 28 שורות, 599,979', count($il26) === 28 && array_sum(array_column($il26, 1)) === 599979);
check('02→03: מזהים חדשים לא מתנגשים', (int)$ab[0]['id'] > 5 && (int)$il[2]['id'] > 5);
check('02→03: גיבוי לפני המיגרציה', count(glob("$dir/app.sqlite.v3-*.bak")) === 1);
$bk = new PDO('sqlite:' . glob("$dir/app.sqlite.v3-*.bak")[0], null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
check('02→03: הגיבוי — המסד כפי שהיה', (int)$bk->query('PRAGMA user_version')->fetchColumn() === 3 && $dump($bk, 5) === $before[0]);
$bk = null;
$n0 = (int)$old->query('SELECT COUNT(*) FROM editions')->fetchColumn();
$old->exec('PRAGMA user_version = 3');
gp_migrate($old);
check('02→03: פריסה שנייה — אין כפילויות', (int)$old->query('SELECT COUNT(*) FROM editions')->fetchColumn() === $n0
    && (int)$old->query("SELECT COUNT(*) FROM audit WHERE action LIKE 'seed-%'")->fetchColumn() === 2);

// 03 קריטריון 2 — מחירי חו"ל שהוקלדו ידנית ב-15/03/2025; ותוספת הבקרה (ב) — ישראל 2026-06-15 שהבעלים כבר יצר
[$old, $dir] = $legacy('02b', 3, function (PDO $p) {
    $p->exec("UPDATE prices SET amount = 20000 WHERE row_id = 3 AND audience = 'abroad'");
    $p->exec("UPDATE prices SET amount = 30000 WHERE row_id = 4 AND audience = 'abroad'");
    $p->exec("UPDATE prices SET amount = 999 WHERE row_id = 5 AND audience = 'il'");
    $p->exec("INSERT INTO editions (id, pid, effective_from, signatures, created_at, updated_at) VALUES (3, 'owner2026001', '2026-06-15', '[]', 'x', 'x')");
    $p->exec("INSERT INTO rows (edition_id, sort, kind, indent, label, pid) VALUES (3, 10, 'item', 0, 'של הבעלים', 'ownerrow0001')");
    $p->exec("INSERT INTO prices (row_id, audience, amount) VALUES (last_insert_rowid(), 'il', 111)");
});
// השורות 3–4 — של המחירון הראשון בנתוני ההתחלה
$eid = (int)$old->query('SELECT edition_id FROM rows WHERE id = 3')->fetchColumn();
$date = (string)$old->query("SELECT effective_from FROM editions WHERE id = $eid")->fetchColumn();
$before = [$dump($old, 3), $eds($old, 3), $audit($old)];
$ilBefore = $priceOf($old, $eid, 'il');
gp_migrate($old);
check('02→03 (חו"ל ידני): הכול נשמר', [$dump($old, 3), $eds($old, 3), $audit($old)] === $before);
$ab = $byAud($old, 'abroad');
check('02→03 (חו"ל ידני): חו"ל ' . $date . ' + 15/06/2026', array_column($ab, 'effective_from') === [$date, '2026-06-15']);
$abp = $priceOf($old, (int)$ab[0]['id'], 'abroad');
check('02→03 (חו"ל ידני): אותן שורות, ובדיוק המחירים שהוקלדו', array_column($abp, 0) === array_column($ilBefore, 0)
    && array_values(array_filter(array_column($abp, 1), fn($v) => $v !== null)) === [20000, 30000]);
check('02→03 (חו"ל ידני): ישראל לא השתנה', $priceOf($old, $eid, 'il') === $ilBefore);
check('02→03 (חו"ל ידני): subtitle וחתימות', $ab[0]['subtitle'] === GP_SUBTITLES['abroad']
    && $ab[0]['signatures'] === $old->query("SELECT signatures FROM editions WHERE id = $eid")->fetchColumn());
$m = $old->query("SELECT edition_id, details FROM audit WHERE action = 'migrate-abroad'")->fetchAll();
$pidIl = (string)$old->query("SELECT pid FROM editions WHERE id = $eid")->fetchColumn();
check('02→03 (חו"ל ידני): ביומן migrate-abroad', count($m) === 1 && (int)$m[0]['edition_id'] === (int)$ab[0]['id']
    && json_decode($m[0]['details'], true) === ['from' => $pidIl, 'rows' => 28], json_encode($m));
check('02→03 (ישראל 2026 של הבעלים): לא נגעו', $priceOf($old, 3, 'il') === [['של הבעלים', 111]]
    && (int)$old->query("SELECT COUNT(*) FROM audit WHERE action = 'seed-il-2026-06-15'")->fetchColumn() === 0
    && count($byAud($old, 'il')) === 3);
check('02→03 (חו"ל ידני): foreign_key_check ריק', $old->query('PRAGMA foreign_key_check')->fetchAll() === []);
$old->exec('PRAGMA user_version = 3');
gp_migrate($old);
check('02→03 (חו"ל ידני): פריסה שנייה — אין migrate-abroad נוסף', count($byAud($old, 'abroad')) === 2
    && (int)$old->query("SELECT COUNT(*) FROM audit WHERE action = 'migrate-abroad'")->fetchColumn() === 1);

// מיגרציה שנכשלה באמצע — הכול חוזר, והמסד נשאר בגרסה הקודמת
[$old, $dir] = $legacy('fail', 3, fn(PDO $p) => $p->exec("INSERT INTO editions (id, pid, effective_from, signatures, created_at, updated_at)
    VALUES (7, 'x', '2030-01-01', '[]', 'x', 'x')"));
$old->exec("CREATE TRIGGER boom BEFORE INSERT ON audit WHEN NEW.action = 'seed-il-2026-06-15' BEGIN SELECT RAISE(ABORT, 'boom'); END");
$before = [$dump($old, 9), $eds($old, 9)];
try { gp_migrate($old); $threw = false; } catch (Throwable $e) { $threw = true; }
check('כשל באמצע: נזרקה שגיאה', $threw);
check('כשל באמצע: גרסה 3, editions במבנה הישן, הכול כמו שהיה', (int)$old->query('PRAGMA user_version')->fetchColumn() === 3
    && !in_array('audience', array_column($old->query('PRAGMA table_info(editions)')->fetchAll(), 'name'), true)
    && [$dump($old, 9), $eds($old, 9)] === $before && (int)$old->query('SELECT COUNT(*) FROM editions')->fetchColumn() === 3);
check('כשל באמצע: foreign_keys חזר לפעול', (int)$old->query('PRAGMA foreign_keys')->fetchColumn() === 1);
$old = null;
foreach ($opened as $d) { foreach (glob("$d/*") ?: [] as $f) @unlink($f); @rmdir($d); }

// --- העריכה (פרוסה 02)
$pdo->exec("UPDATE prices SET amount = 16008 WHERE amount = 1");   // מחזירים את מה שהבדיקה של המיגרציה שינתה
$uid = (int)gp_user_upsert('99', 'עורך', '', false)['id'];
$err = function (callable $fn): array {
    try { $fn(); return [0, '', []]; } catch (ApiError $e) { return [$e->getCode(), $e->getMessage(), $e->extra]; }
};
$find = fn(array $e, string $needle, int $nth = 0) => array_values(array_filter($e['rows'], fn($r) => str_contains($r['label'], $needle)))[$nth];
[$p26, $p25, $p24, $pab] = [$list[0]['pid'], $list[1]['pid'], $list[2]['pid'], $abr[0]['pid']];
$jer = $find(gp_edition($p25), 'לתושב ירושלים')['pid'];
$future = (new DateTimeImmutable(gp_today()))->modify('+1 year')->format('Y-m-d');

// 3 — בלי confirm_in_effect על מחירון בתוקף: 409, ולא השתנה
$r = $err(fn() => gp_price_set($uid, ['pid' => $p25, 'row' => $jer, 'audience' => 'il', 'amount' => 17000]));
check('409 על מחירון בתוקף', $r[0] === 409 && ($r[2]['need_confirm'] ?? false) === true, json_encode($r, JSON_UNESCAPED_UNICODE));
check('409 — המחיר לא השתנה', $find(gp_edition($p25), 'לתושב ירושלים')['price'] === 16947);
// 12 — קלט לא תקין
foreach ([-5, 'abc', '1.5', 1.5, '-5', 10000001, '1,000', true] as $bad) {
    $r = $err(fn() => gp_price_set($uid, ['pid' => $p25, 'row' => $jer, 'audience' => 'il', 'amount' => $bad, 'confirm_in_effect' => true]));
    check('400 על ' . json_encode($bad), $r[0] === 400 && $r[1] !== '', json_encode($r, JSON_UNESCAPED_UNICODE));
}
check('אחרי קלט לא תקין — לא נשמר', $find(gp_edition($p25), 'לתושב ירושלים')['price'] === 16947);

// 4 — מחירון חדש עם אחוז על "חלקות פטורות" (קודם, כשהבסיס עדיין 16,947)
$sec = $find(gp_edition($p25), 'חלקות פטורות')['pid'];
$new = gp_edition_create($uid, ['effective_from' => $future, 'base_pid' => $p25, 'raise' => [$sec => 5.866, 'fee' => 0]]);
$en = gp_edition($new['pid']);
$e25 = gp_edition($p25);
check('חדש: לתושב ירושלים = 17,941', $find($en, 'לתושב ירושלים')['price'] === 17941, (string)$find($en, 'לתושב ירושלים')['price']);
check('חדש: בגוש 26 נשאר 70,000', $find($en, 'בגוש 26')['price'] === 70000);
check('חדש: תשלומים כמו קודם', $find($en, 'העברת בעלות')['price'] === 3000);
check('חדש: ישראל, עם כותרת המשנה', gp_edition_row($new['pid'])['audience'] === 'il' && $en['subtitle'] === GP_SUBTITLES['il']);
check('חדש: החתימות הועתקו', $en['signatures'] === $e25['signatures']);
check('חדש: אותן שורות', array_column($en['rows'], 'label') === array_column($e25['rows'], 'label'));
check('חדש: pid חדש לשורות', !array_intersect(array_column($en['rows'], 'pid'), array_column($e25['rows'], 'pid')));
check('חדש: עתידי', $en['future'] === true);
check('הבסיס לא השתנה', $find($e25, 'לתושב ירושלים')['price'] === 16947);

// 6 — תאריך שכבר קיים
$r = $err(fn() => gp_edition_create($uid, ['effective_from' => $future, 'base_pid' => $p25]));
check('תאריך קיים — שגיאה', $r[1] === 'כבר יש מחירון בתאריך הזה');
check('תאריך קיים — לא נוצר דבר', count(gp_editions()['editions']) === 5);
check('תאריך לא תקין', $err(fn() => gp_edition_create($uid, ['effective_from' => '2026-02-30', 'base_pid' => $p25]))[0] === 400);
check('אחוז לא תקין', $err(fn() => gp_edition_create($uid, ['effective_from' => '2031-01-01', 'base_pid' => $p25, 'raise' => [$sec => 'x']]))[0] === 400);
check('אחוז לשורה זרה', $err(fn() => gp_edition_create($uid, ['effective_from' => '2031-01-01', 'base_pid' => $p25, 'raise' => [$jer => 5]]))[0] === 400);

// 5 — העתק בלי אחוזים זהה, ועריכה בעתידי בלי אישור
$copy = gp_edition_create($uid, ['effective_from' => '2031-01-01', 'base_pid' => $p25]);
$ec = gp_edition($copy['pid']);
$strip = fn(array $e) => array_map(fn($r) => [$r['kind'], $r['indent'], $r['label'], $r['price']], $e['rows']);
check('העתק זהה', $strip($ec) === $strip($e25));
$fj = $find($en, 'לתושב ירושלים')['pid'];
check('עתידי — בלי אישור', gp_price_set($uid, ['pid' => $new['pid'], 'row' => $fj, 'amount' => '25000'])['amount'] === 25000);

// 1 + 2 — המבחן: שינוי עם אישור, 2024 לא נפגע, והיומן
gp_price_set($uid, ['pid' => $p25, 'row' => $jer, 'audience' => 'il', 'amount' => '17000', 'confirm_in_effect' => true]);
check('המחיר נשמר', $find(gp_edition($p25), 'לתושב ירושלים')['price'] === 17000);
check('2024 לא נפגע', $find(gp_edition($p24), 'לתושב ירושלים')['price'] === 16008);
check('העתיד לא נפגע', $find(gp_edition($new['pid']), 'לתושב ירושלים')['price'] === 25000);
$h = gp_history($p25)['history'][0];
check('ביומן: ישן → חדש', $h['action'] === 'price' && $h['details']['old'] === 16947 && $h['details']['new'] === 17000
    && $h['details']['audience'] === 'il' && $h['user'] === 'עורך', json_encode($h, JSON_UNESCAPED_UNICODE));
// 13 — שני חלונות: האחרון גובר, ושניהם ביומן
gp_price_set($uid, ['pid' => $p25, 'row' => $jer, 'audience' => 'il', 'amount' => 17100, 'confirm_in_effect' => true]);
gp_price_set($uid, ['pid' => $p25, 'row' => $jer, 'audience' => 'il', 'amount' => 17000, 'confirm_in_effect' => true]);
$h = array_slice(gp_history($p25)['history'], 0, 2);
check('האחרון גובר, שניהם ביומן', $find(gp_edition($p25), 'לתושב ירושלים')['price'] === 17000
    && $h[0]['details']['old'] === 17100 && $h[1]['details']['new'] === 17100);
check('ריק = אין מחיר', gp_price_set($uid, ['pid' => $new['pid'], 'row' => $fj, 'amount' => ''])['amount'] === null);
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
check('מחיקת עתידי', count(gp_editions()['editions']) === 5 && $err(fn() => gp_edition($copy['pid']))[0] === 404);
check('מחיקה — ביומן עם עותק', (bool)$pdo->query("SELECT 1 FROM audit WHERE action = 'edition-delete' AND json_extract(details, '$.copy.rows[2].price') = 16947")->fetchColumn());
check('מחיקה — לא נשארו שורות יתומות', (int)$pdo->query("SELECT COUNT(*) FROM rows WHERE edition_id = $delId")->fetchColumn() === 0);
$again = gp_edition_create($uid, ['effective_from' => '2031-01-01', 'base_pid' => $p25]);
check('מזהה של מחירון שנמחק לא חוזר', (int)gp_edition_row($again['pid'])['id'] !== $delId && count(gp_history($again['pid'])['history']) === 1);
check('"בתוקף היום" — 2026 לכל קבוצה, לא העתידי', array_column(array_filter(gp_editions()['editions'], fn($e) => $e['in_effect']), 'pid') === [$p26, $pab]);

// --- פרוסה 03: לכל קבוצה מחירונים משלה (03-per-audience.md סעיף 8)
// 12 — price-set עם קבוצה אחרת: 400, לא נשמר
$abRows = gp_edition($pab)['rows'];
$olv = array_values(array_filter($abRows, fn($r) => $r['kind'] === 'band' && $r['label'] === 'הר הזיתים'))[0]['pid'];
$abFirst = $find(gp_edition($pab), 'חלקה צפונית')['pid'];
$r = $err(fn() => gp_price_set($uid, ['pid' => $pab, 'row' => $abFirst, 'audience' => 'il', 'amount' => 1, 'confirm_in_effect' => true]));
check('price-set חו"ל עם audience=il — 400', $r[0] === 400, json_encode($r, JSON_UNESCAPED_UNICODE));
check('— לא נשמר', $find(gp_edition($pab), 'חלקה צפונית')['price'] === 130000);
check('price-set עם audience נכון', gp_price_set($uid, ['pid' => $pab, 'row' => $abFirst, 'audience' => 'abroad', 'amount' => 130000, 'confirm_in_effect' => true])['ok']);
// 9 — מחירון חו"ל חדש, "הר הזיתים" 10%
$ab9 = gp_edition_create($uid, ['effective_from' => '2026-07-01', 'base_pid' => $pab, 'raise' => [$olv => '10']]);
$e9 = gp_edition($ab9['pid']);
$fields = array_values(array_filter($e9['rows'], fn($r) => $r['label'] === '**כל החלקות** ((שדה))'));
check('חו"ל חדש: הר הזיתים שדה = 66,000', $fields[0]['price'] === 66000, (string)$fields[0]['price']);
check('חו"ל חדש: הר המנוחות מערב בודד = 60,000', $find($e9, '((בודד))')['price'] === 60000);
check('חו"ל חדש: קבוצה, כותרת משנה וחתימות', gp_edition_row($ab9['pid'])['audience'] === 'abroad' && $e9['subtitle'] === GP_SUBTITLES['abroad']
    && $e9['signatures'][2]['role'] === 'יו"ר המועצה');
check('חו"ל חדש: ישראל לא השתנה', $sum(gp_edition($p26)) === 599979);
check('חו"ל חדש: הבסיס לא השתנה', $sum(gp_edition($pab)) === 1501400);
check('חו"ל חדש: אין רשומות מחיר של ישראל', (int)$pdo->query("SELECT COUNT(*) FROM prices p JOIN rows r ON r.id = p.row_id
    WHERE r.edition_id = " . (int)gp_edition_row($ab9['pid'])['id'] . " AND p.audience <> 'abroad'")->fetchColumn() === 0);
// 10, 11 — ייחודיות לפי קבוצה
check('ישראל 01/07/2026 — מותר (תפוס רק בחו"ל)', gp_edition_create($uid, ['effective_from' => '2026-07-01', 'base_pid' => $p26])['ok']);
$r = $err(fn() => gp_edition_create($uid, ['effective_from' => '2026-06-15', 'base_pid' => $pab]));
check('חו"ל 15/06/2026 — "כבר יש מחירון בתאריך הזה"', $r[1] === 'כבר יש מחירון בתאריך הזה');
check('אחוז ל-band של מחירון אחר — 400', $err(fn() => gp_edition_create($uid, ['effective_from' => '2031-05-05', 'base_pid' => $p26, 'raise' => [$olv => 5]]))[0] === 400);
check('אחוז במבנה הישן (il/abroad) — 400', $err(fn() => gp_edition_create($uid, ['effective_from' => '2031-05-05', 'base_pid' => $pab, 'raise' => [$olv => ['il' => 5]]]))[0] === 400);
check('בלי בסיס — 404', $err(fn() => gp_edition_create($uid, ['effective_from' => '2031-05-05']))[0] === 404);
$abF = gp_edition_create($uid, ['effective_from' => '2032-03-03', 'base_pid' => $pab])['pid'];
check('תאריך — התנגשות בתוך הקבוצה', $err(fn() => gp_edition_update($uid, ['pid' => $abF, 'effective_from' => '2026-06-15']))[0] === 409);
check('תאריך — של קבוצה אחרת מותר', gp_edition_update($uid, ['pid' => $abF, 'effective_from' => $future])['ok']);
// 13 — שורת band חדשה
$band = gp_row_save($uid, ['pid' => $ab9['pid'], 'kind' => 'band', 'indent' => 0, 'label' => 'בית עלמין חדש', 'confirm_in_effect' => true])['row'];
check('band חדש', $find(gp_edition($ab9['pid']), 'בית עלמין חדש')['kind'] === 'band' && !$find(gp_edition($ab9['pid']), 'בית עלמין חדש')['priced']);
check('band — אין לו מחיר', $err(fn() => gp_price_set($uid, ['pid' => $ab9['pid'], 'row' => $band, 'amount' => 1, 'confirm_in_effect' => true]))[0] === 400);
// subtitle — כמו החתימות: במחירון בתוקף רק עם אישור
$r = $err(fn() => gp_edition_update($uid, ['pid' => $pab, 'subtitle' => 'כותרת אחרת']));
check('subtitle במחירון בתוקף — 409', $r[0] === 409 && ($r[2]['need_confirm'] ?? false) && gp_edition($pab)['subtitle'] === GP_SUBTITLES['abroad']);
gp_edition_update($uid, ['pid' => $pab, 'subtitle' => 'כותרת אחרת', 'confirm_in_effect' => true]);
check('subtitle — נשמר, וביומן', gp_edition($pab)['subtitle'] === 'כותרת אחרת' && gp_history($pab)['history'][0]['action'] === 'subtitle');
check('subtitle ריק — 400', $err(fn() => gp_edition_update($uid, ['pid' => $pab, 'subtitle' => ' ', 'confirm_in_effect' => true]))[0] === 400);
check('subtitle — עובר למחירון חדש', gp_edition(gp_edition_create($uid, ['effective_from' => '2031-06-06', 'base_pid' => $pab])['pid'])['subtitle'] === 'כותרת אחרת');
check('תאריך במחירון בתוקף — 403', $err(fn() => gp_edition_update($uid, ['pid' => $pab, 'effective_from' => '2031-01-01']))[0] === 403);
// מחיקת שורה ממחירון ישראל — היומן שומר גם רשומת חו"ל ישנה (מלפני 03)
$zr = $find(gp_edition($new['pid']), 'צמוד לבן/בת זוג')['pid'];
$pdo->exec("INSERT INTO prices (row_id, audience, amount) SELECT id, 'abroad', 777 FROM rows WHERE pid = " . $pdo->quote($zr)
    . " ON CONFLICT (row_id, audience) DO UPDATE SET amount = 777");
gp_row_delete($uid, ['pid' => $new['pid'], 'row' => $zr]);
$d = gp_history($new['pid'])['history'][0]['details'];
check('מחיקת שורה — ביומן price ו-prices', $d['price'] === $d['prices']['il'] && $d['prices']['abroad'] === 777, json_encode($d, JSON_UNESCAPED_UNICODE));
$cur = array_values(array_filter(gp_editions()['editions'], fn($e) => $e['in_effect']));
check('"בתוקף היום" — לכל קבוצה בנפרד (01/07/2026 שנוצרו עכשיו)', array_column($cur, 'audience') === ['il', 'abroad']
    && array_column($cur, 'effective_from') === ['2026-07-01', '2026-07-01'] && $cur[1]['pid'] === $ab9['pid']);
check('foreign_key_check ריק בסוף', $pdo->query('PRAGMA foreign_key_check')->fetchAll() === []);

// ניקוי
foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
@rmdir($tmp);

echo $fails ? "\n$fails מתוך $n בדיקות נכשלו\n" : "✓ $n בדיקות עברו\n";
exit($fails ? 1 : 0);
