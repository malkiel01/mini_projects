<?php
/**
 * פרוסה 08 — תזכורות, ערוצים, ושבת וחג (docs/spec/08-reminders.md §6, "L<מספר> …").
 *
 *   השרת: התכנון (48 שעות, ביטול לפני יצירה, dedupe), השליחה (Push מוצפן ו-VAPID — מפוענחים כאן כמו שדפדפן מפענח; מייל),
 *   ההשתקה בשבת, ברצף שבת וחג וביום טוב שני בחו"ל — **לכל ערוץ בנפרד**, מכשיר שבוטל, אורח, הרשאות, ולוג בלי תוכן.
 *   המכשיר — tests/reminders-core.mjs ב-node, על fixture שנבנה כאן: אותם מקרים, וה-JS חייב לתת **בדיוק** את מה שה-PHP נתן
 *   (המופעים במילישנייה, "מושתק", חלונות ההשתקה), וגם .ics (L6) ומה שבתוך האפליקציה מושתק (L5).
 *   👁 L1 (המכשיר עצמו), L2 (לחיצה), L6 (המסך), L7 (אורח), L9 (dev/push-report.md) — אצל הבעלים (SF-008).
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$root = dirname(__DIR__);
$app = json_decode((string)file_get_contents("$root/app.json"), true);
$ok = function (array $r, string $name): array {
    eq($r[0], 200, $name . ($r[0] !== 200 ? ' — ' . json_encode($r[1]['error'] ?? $GLOBALS['t_last_500'] ?? null, JSON_UNESCAPED_UNICODE) : ''));
    return $r[1];
};
$at = function (string $iso) { $GLOBALS['sf_time'] = strtotime($iso); };
$ED = 'abcdefghijklmnop';
$GLOBALS['sf_mail_out'] = [];   // בלי שליחת מיילים אמיתית

// ── ספק Push מדומה: כל POST נרשם; הדפדפן (UA) — זוג מפתחות אמיתי, כדי לפענח כמו דפדפן ──
$GLOBALS['t_push'] = [];
$GLOBALS['t_push_status'] = [];
$GLOBALS['sf_push_http'] = function (string $url, array $headers, string $body) {
    $GLOBALS['t_push'][] = compact('url', 'headers', 'body');
    return $GLOBALS['t_push_status'][$url] ?? 201;
};
$ua = function (): array {
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    return ['key' => $k, 'pub' => sf_ec_point($k), 'auth' => random_bytes(16)];
};
$sub = fn(string $ep, array $u) => ['endpoint' => $ep, 'keys' => ['p256dh' => sf_b64u($u['pub']), 'auth' => sf_b64u($u['auth'])]];
/** RFC 8291 בצד הדפדפן: מפענח את מה שהשרת שלח */
$decrypt = function (array $u, string $body): ?array {
    $salt = substr($body, 0, 16);
    $idlen = ord($body[20]);
    $asPub = substr($body, 21, $idlen);
    $ct = substr($body, 21 + $idlen);
    $ecdh = openssl_pkey_derive(sf_ec_pub($asPub), $u['key'], 32);
    $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $u['pub'] . $asPub, $u['auth']);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $plain = openssl_decrypt(substr($ct, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($ct, -16));
    if ($plain === false || !str_ends_with($plain, "\x02")) return null;
    return json_decode(substr($plain, 0, -1), true);
};
/** VAPID: החתימה (ES256) תקפה מול המפתח שבכותרת */
$vapidOk = function (string $auth, string $ep): bool {
    if (!preg_match('/^vapid t=([^.]+)\.([^.]+)\.([^,]+), k=(.+)$/', $auth, $m)) return false;
    $claims = json_decode((string)sf_b64u_dec($m[2]), true);
    $u = parse_url($ep);
    if (($claims['aud'] ?? '') !== $u['scheme'] . '://' . $u['host'] || ($claims['exp'] ?? 0) <= sf_time()) return false;
    $raw = (string)sf_b64u_dec($m[3]);
    $int = fn($v) => "\x02" . chr(strlen($v = (ord($v[0]) & 0x80 ? "\0" : '') . ltrim($v, "\0") ?: "\0")) . $v;
    $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
    $der = "\x30" . chr(strlen($seq)) . $seq;
    $pub = sf_ec_pub((string)sf_b64u_dec($m[4]));
    return $pub && openssl_verify($m[1] . '.' . $m[2], $der, $pub, OPENSSL_ALGO_SHA256) === 1;
};
$pushesTo = fn(string $ep) => array_values(array_filter($GLOBALS['t_push'], fn($p) => $p['url'] === $ep));
$occ = fn(string $owner, ?string $status = 'pending') => sf_rows('SELECT * FROM reminder_occurrences WHERE owner = ?' . ($status ? ' AND status = ?' : '') . ' ORDER BY fire_at, id',
    $status ? [$owner, $status] : [$owner]);

// ── הרשאות ───────────────────────────────────────────────────
foreach (['push-key', 'push-subscribe', 'push-unsubscribe', 'push-test', 'reminders-guest-sync', 'reminders-status'] as $a) {
    eq(SF_ACTIONS[$a]['who'] ?? null, 'guest', "$a — עולם הקוראים (אורח או חשבון), לא מנהלים");
    check("$a לא ב-\"public\" של app.json עד ההשקה (11)", !str_contains(implode(' ', $app['public']), $a));
}
eq(SF_ACTIONS['dev-reminders']['who'] ?? null, 'dev', 'dev-reminders — מפתח בלבד');
foreach (['push-subscribe', 'push-unsubscribe', 'push-test', 'reminders-guest-sync'] as $a) check("$a — גוף מוגבל", (SF_ACTIONS[$a]['max'] ?? 0) > 0 && SF_ACTIONS[$a]['max'] <= 80_000);
eq(code(call('GET', 'dev-reminders', [], null)), 'login_required', 'dev-reminders בלי מנהל — 401');

// ── שיטה מאושרת (כדי שתזכורת יחסית תחושב) ──────────────────
$A = ident('801', 'מנהלת', 'a@example.test');
call('GET', 'me', [], $A);
$def = ['day_end' => 'tz', 'zmanim' => [
    ['key' => 'sr', 'name' => 'זריחה', 'type' => 'angle', 'deg' => 0.833, 'dir' => 'am'],
    ['key' => 'shma', 'name' => 'סוף זמן קריאת שמע', 'type' => 'hours', 'from' => 'sr', 'to' => 'ss', 'hours' => 3],
    ['key' => 'ss', 'name' => 'שקיעה', 'type' => 'angle', 'deg' => 0.833, 'dir' => 'pm'],
    ['key' => 'tz', 'name' => 'צאת הכוכבים', 'type' => 'offset', 'base' => 'ss', 'min' => 18],
]];
$m1 = $ok(call('POST', 'zmanim-method-save', ['name' => 'שיטה לבדיקת תזכורות', 'def' => $def, 'base' => 0], $A), 'שיטה')['method'];
$ok(call('POST', 'zmanim-method-status', ['method' => $m1['pid'], 'status' => 'approved'], $A), 'אישור');
$ok(call('POST', 'zmanim-method-default', ['method' => $m1['pid']], $A), 'ברירת מחדל');

// ── קורא עם שני מכשירים, Push בשניהם ─────────────────────────
$at('2026-11-23T03:00:00Z');   // יום שני, 05:00 בירושלים
$ok(rcall('POST', 'account-register', ['email' => 'dina@example.com', 'password' => 'dina-dina-1'], 'A'), 'הרשמה (מכשיר א)');
$ok(rcall('POST', 'account-login', ['email' => 'dina@example.com', 'password' => 'dina-dina-1'], 'B'), 'כניסה (מכשיר ב)');
$rid = (int)sf_val("SELECT id FROM readers WHERE email = 'dina@example.com'");
$OWN = "r:$rid";
$uaA = $ua(); $uaB = $ua();
$EPA = 'https://fcm.googleapis.com/fcm/send/device-a';
$EPB = 'https://web.push.apple.com/device-b';
$key = $ok(rcall('GET', 'push-key', [], 'A'), 'push-key');
check('push-key: מפתח VAPID ציבורי (65 בתים)', strlen((string)sf_b64u_dec($key['key'] ?? '')) === 65);
check('VAPID — בקובץ data/vapid.json (0600), לא בקוד', is_file(SF_DATA_DIR . '/vapid.json') && (fileperms(SF_DATA_DIR . '/vapid.json') & 0077) === 0);
$devA = $ok(rcall('POST', 'push-subscribe', ['sub' => $sub($EPA, $uaA)], 'A'), 'Push במכשיר א')['device'];
$devB = $ok(rcall('POST', 'push-subscribe', ['sub' => $sub($EPB, $uaB)], 'B'), 'Push במכשיר ב')['device'];
eq(count($ok(rcall('GET', 'push-key', [], 'B'), 'מכשירים')['devices']), 2, 'push-key: שני המכשירים של הקורא');
$enc = (string)sf_val('SELECT sub FROM push_devices WHERE pid = ?', [$devA]);
check('ה-endpoint והמפתחות במסד — מוצפנים', str_starts_with($enc, 's1:') && !str_contains($enc, 'device-a'));

// אבטחה של הרישום
eq(code(rcall('POST', 'push-subscribe', ['sub' => $sub('https://evil.example.com/x', $uaA)], 'A')), 'bad_input', 'endpoint שאינו של ספק Push — נדחה (לא SSRF)');
eq(code(rcall('POST', 'push-subscribe', ['sub' => $sub('http://fcm.googleapis.com/x', $uaA)], 'A')), 'bad_input', '…וגם http');
eq(code(rcall('POST', 'push-subscribe', ['sub' => $sub('https://fcm.googleapis.com:8443/x', $uaA)], 'A')), 'bad_input', '…וגם פורט אחר');
eq(code(rcall('POST', 'push-subscribe', ['sub' => ['endpoint' => $EPA, 'keys' => ['p256dh' => 'AAAA', 'auth' => 'BBBB']]], 'A')), 'bad_input', 'מפתחות לא תקינים — נדחה');
eq(code(rcall('POST', 'push-subscribe', ['sub' => $sub($EPA, $uaA)], 'A', [], ['content_type' => 'text/plain'])), 'content_type', 'POST בלי application/json — 415');
eq(code(rcall('POST', 'push-subscribe', ['sub' => $sub($EPA, $uaA)], 'A', [], ['sec_fetch_site' => 'cross-site'])), 'csrf', 'POST מאתר אחר — 403');
eq(code(rcall('POST', 'push-unsubscribe', ['device' => $devA], 'A', [], ['csrf' => 'x'])), 'csrf', 'קורא מחובר בלי X-Reader-CSRF — 403');

// ── L1: Push למכשיר א בלבד, בלי מייל ────────────────────────
sf_exec('UPDATE readers SET email_verified_at = ? WHERE id = ?', [sf_now(), $rid]);
$rem1 = ['id' => 'r1', 'name' => 'שחרית-סודי-L10', 'type' => 'fixed', 'time' => '06:30', 'days' => [0, 1, 2, 3, 4], 'active' => true,
    'prayer' => ['edition' => $ED, 'sid' => 'shacharit', 'name' => 'תפילה-סודית-L10'], 'channels' => ['push' => true, 'inapp' => true, 'email' => false], 'devices' => [$devA]];
$items = [['pid' => 'rem:r1', 'kind' => 'reminder', 'data' => $rem1],
    ['pid' => 'pref:remprefs', 'kind' => 'pref', 'data' => ['channels' => ['push' => true, 'inapp' => true, 'email' => true], 'extend' => ['before' => 0, 'after' => 0], 'tz' => 'Asia/Jerusalem']],
    ['pid' => 'pref:loc', 'kind' => 'pref', 'data' => ['kind' => 'city', 'id' => 281184]]];
$ok(rcall('POST', 'sync-push', ['items' => $items], 'A'), 'התזכורת בסנכרון');
$p = $occ($OWN);
eq(array_map(fn($o) => [$o['channel'], $o['fire_at'], (int)$o['push_device_id']], $p),
    [['push', '2026-11-23T04:30:00Z', (int)sf_val('SELECT id FROM push_devices WHERE pid = ?', [$devA])],
     ['push', '2026-11-24T04:30:00Z', (int)sf_val('SELECT id FROM push_devices WHERE pid = ?', [$devA])]],
    'L1 מופעים ל-48 שעות: 06:30 בירושלים, רק למכשיר א, בלי מייל');
$st = $ok(rcall('GET', 'reminders-status', [], 'B'), 'reminders-status');
eq([count($st['upcoming']), $st['upcoming'][0]['device'] ?? null, $st['email']['verified'] ?? null, $st['cron']['ok'] ?? null], [2, $devA, true, false], 'reminders-status: הקרובות (בלי שמות), מייל מאומת, המתזמן לא רץ');
check('reminders-status: בלי שם התזכורת', !str_contains(json_encode($st, JSON_UNESCAPED_UNICODE), 'סודי'));

$GLOBALS['sf_mail_out'] = [];
$at('2026-11-23T04:29:00Z');
eq(sf_rem_send_due()['sent'], 0, 'לפני הזמן — לא נשלח');
$at('2026-11-23T04:30:20Z');
$n = sf_rem_send_due();
eq([$n['sent'], count($pushesTo($EPA)), count($pushesTo($EPB)), count($GLOBALS['sf_mail_out'])], [1, 1, 0, 0], 'L1 רק מכשיר א קיבל; מכשיר ב לא; מייל לא נשלח');
$pa = $pushesTo($EPA)[0];
$msg = $decrypt($uaA, $pa['body']);
eq([$msg['t'] ?? null, $msg['u'] ?? null], ['שחרית-סודי-L10', "#/r/$ED/shacharit"], 'L2 ההתראה (מפוענחת כמו בדפדפן): השם, והקישור לתפילה המקושרת');
check('L2 תוכן ההתראה מוצפן בדרך (aes128gcm) — השם לא גלוי', !str_contains($pa['body'], 'שחרית') && ($pa['headers']['Content-Encoding'] ?? '') === 'aes128gcm');
check('VAPID — חתימה ES256 תקפה, aud של הספק', $vapidOk($pa['headers']['Authorization'] ?? '', $EPA));
check('התראה עם TTL ו-Urgency', (int)($pa['headers']['TTL'] ?? 0) > 0 && isset($pa['headers']['Urgency']));
eq(sf_val('SELECT status FROM reminder_occurrences WHERE owner = ? AND fire_at = ?', [$OWN, '2026-11-23T04:30:00Z']), 'sent', 'נרשם "נשלח" (הספק קיבל) — לא "התקבל"');

// ── L4: אותו מופע, המתזמן מריץ פעמיים ← פעם אחת ─────────────
$before = count($GLOBALS['t_push']);
sf_rem_send_due();
sf_rem_send_due();
eq(count($GLOBALS['t_push']), $before, 'L4 הרצה שנייה ושלישית — לא שולחות שוב');
sf_rem_schedule($OWN);   // האופק זז (48 שעות מעכשיו) — אולי מופע חדש; מכאן — אותו דבר
$cnt = (int)sf_val('SELECT COUNT(*) FROM reminder_occurrences WHERE owner = ?', [$OWN]);
sf_rem_schedule($OWN);
sf_rem_schedule($OWN);
eq((int)sf_val('SELECT COUNT(*) FROM reminder_occurrences WHERE owner = ?', [$OWN]), $cnt, 'L4 תכנון חוזר — אותם מופעים (dedupe_key), לא כפולים');
// מופע שכבר "נלקח" (sending) ע"י הרצה אחרת — לא נשלח שוב
$at('2026-11-24T04:30:10Z');
$o2 = sf_row("SELECT * FROM reminder_occurrences WHERE owner = ? AND status = 'pending' ORDER BY fire_at LIMIT 1", [$OWN]);
sf_exec("UPDATE reminder_occurrences SET status = 'sending' WHERE id = ?", [$o2['id']]);
$before = count($GLOBALS['t_push']);
sf_rem_send_due();
eq(count($GLOBALS['t_push']), $before, 'L4 מופע שהרצה מקבילה כבר לקחה (sending) — לא נשלח פעמיים');
// דרך cron.php: שתי הרצות באותה דקה — משימת שליחה אחת, ומופע אחד
sf_exec("UPDATE reminder_occurrences SET status = 'pending' WHERE id = ?", [$o2['id']]);
$r1 = sf_cron_handle(sf_cron_token());
$r2 = sf_cron_handle(sf_cron_token());
eq([$r1[0], count($pushesTo($EPA))], [200, 2], 'L4 cron.php שולח את המופע שהגיע זמנו');
eq((int)sf_val("SELECT COUNT(*) FROM jobs WHERE kind = 'reminders.send'"), 1, 'L4 שתי הרצות באותה דקה — משימת שליחה אחת (dedupe לפי דקה)');
eq(count($pushesTo($EPA)), 2, 'L4 …וההרצה השנייה לא שלחה שוב');

// ── L8: מכשיר שבוטל (05) לא מקבל Push ───────────────────────
$at('2026-11-24T05:00:00Z');
$rem8 = ['id' => 'r8', 'name' => 'שמונה', 'type' => 'fixed', 'time' => '07:30', 'days' => [0, 1, 2, 3, 4, 5, 6], 'active' => true, 'channels' => ['push' => true, 'inapp' => false, 'email' => false]];
$ok(rcall('POST', 'sync-push', ['items' => [['pid' => 'rem:r8', 'kind' => 'reminder', 'data' => $rem8]]], 'A'), 'תזכורת לכל המכשירים');
$byDev = fn() => array_count_values(array_map(fn($o) => (string)$o['push_device_id'], array_filter($occ($OWN), fn($o) => $o['reminder'] === 'r8')));
eq(count($byDev()), 2, 'L8 לפני הביטול — מופעים לשני המכשירים');
$devs = $ok(rcall('GET', 'account-devices', [], 'B'), 'מכשירים')['devices'];
$aDev = array_values(array_filter($devs, fn($d) => !$d['current']))[0]['pid'];
$ok(rcall('POST', 'account-device-revoke', ['device' => $aDev], 'B'), 'ביטול מכשיר א (05)');
$idA = (int)sf_val('SELECT id FROM push_devices WHERE pid = ?', [$devA]);
eq(array_keys($byDev()), [(int)sf_val('SELECT id FROM push_devices WHERE pid = ?', [$devB])], 'L8 אחרי הביטול — אין מופע ממתין למכשיר א');
$at('2026-11-24T05:30:30Z');
$beforeA = count($pushesTo($EPA));
sf_rem_send_due();
eq([count($pushesTo($EPA)), count($pushesTo($EPB)) > 0], [$beforeA, true], 'L8 בזמן המופע — מכשיר א לא קיבל, מכשיר ב קיבל');
// גם אם מופע ישן נשאר (מרוץ) — השליחה בודקת שוב
sf_insert('reminder_occurrences', ['owner' => $OWN, 'reminder' => 'r8', 'fire_at' => sf_now(), 'channel' => 'push', 'push_device_id' => $idA, 'dedupe_key' => 'stale-a',
    'status' => 'pending', 'attempts' => 0, 'created_at' => sf_now(), 'updated_at' => sf_now()]);
sf_rem_send_due();
eq([count($pushesTo($EPA)), sf_val("SELECT status FROM reminder_occurrences WHERE dedupe_key = 'stale-a'")], [$beforeA, 'cancelled'], 'L8 מופע שנשאר למכשיר שבוטל — לא נשלח (נבדק שוב בשליחה)');
eq(array_column($ok(rcall('GET', 'push-key', [], 'B'), 'מכשירים')['devices'], 'pid'), [$devB], 'L8 המכשיר שבוטל לא ברשימה');

// ── L3: ביטול / שינוי מיקום ← המופעים הישנים מבוטלים ולא נשלחים ──
$at('2026-11-24T06:00:00Z');
$rem3 = ['id' => 'r3', 'name' => 'מנחה', 'type' => 'relative', 'base' => 'ss', 'offset' => -20, 'days' => [0, 1, 2, 3, 4, 5, 6], 'active' => true, 'channels' => ['push' => true, 'inapp' => true, 'email' => true]];
$ok(rcall('POST', 'sync-push', ['items' => [['pid' => 'rem:r3', 'kind' => 'reminder', 'data' => $rem3]]], 'B'), 'תזכורת יחסית');
$jer = array_values(array_filter($occ($OWN), fn($o) => $o['reminder'] === 'r3'));
$ss = sf_cal_zmanim($def, ['lat' => 31.769, 'lon' => 35.2163, 'tz' => 'Asia/Jerusalem'], 2026, 11, 24);
$ssMs = array_column($ss, 'ms', 'key')['ss'];
eq($jer[0]['fire_at'] ?? null, sf_iso(intdiv($ssMs - 20 * 60000, 1000)), 'L3 20 דקות לפני השקיעה בירושלים (אותו חישוב כמו מסך הזמנים)');
eq(count(array_filter($jer, fn($o) => $o['channel'] === 'email')), 2, 'מייל מאומת + ערוץ מייל — מופעי מייל');
$ok(rcall('POST', 'sync-push', ['items' => [['pid' => 'pref:loc', 'kind' => 'pref', 'data' => ['kind' => 'city', 'id' => 5128581]]]], 'B'), 'מיקום חדש: ניו יורק');
$old = array_map(fn($o) => sf_val('SELECT status FROM reminder_occurrences WHERE id = ?', [$o['id']]), $jer);
eq(array_unique($old), ['cancelled'], 'L3 שינוי מיקום — כל המופעים של ירושלים בוטלו');
$ny = array_values(array_filter($occ($OWN), fn($o) => $o['reminder'] === 'r3'));
check('L3 …ונוצרו חדשים לפי ניו יורק', $ny && !in_array($ny[0]['fire_at'], array_column($jer, 'fire_at'), true));
$at(gmdate('Y-m-d\TH:i:s\Z', strtotime($jer[0]['fire_at']) + 20));
$before = count($GLOBALS['t_push']);
$GLOBALS['sf_mail_out'] = [];
sf_rem_send_due();
eq([count($GLOBALS['t_push']), count($GLOBALS['sf_mail_out'])], [$before, 0], 'L3 בזמן של ירושלים — לא נשלח כלום (Push ומייל)');
// ביטול התזכורת
$ok(rcall('POST', 'sync-push', ['items' => [['pid' => 'rem:r3', 'kind' => 'reminder', 'deleted' => true, 'base' => 99]]], 'B'), 'מחיקת התזכורת');
eq(count(array_filter($occ($OWN), fn($o) => $o['reminder'] === 'r3')), 0, 'L3 מחיקה — אין מופע ממתין');
$at(gmdate('Y-m-d\TH:i:s\Z', strtotime($ny[0]['fire_at']) + 20));
$before = count($GLOBALS['t_push']);
sf_rem_send_due();
eq([count($GLOBALS['t_push']), count($GLOBALS['sf_mail_out'])], [$before, 0], 'L3 בזמן של המופע שבוטל — לא נשלח');
// השבתה (active: false) — כמו ביטול
$at('2026-11-25T03:00:00Z');
$ok(rcall('POST', 'sync-push', ['items' => [['pid' => 'rem:r8', 'kind' => 'reminder', 'data' => ['active' => false] + $rem8, 'base' => 99]]], 'B'), 'השבתת תזכורת');
eq(count(array_filter($occ($OWN), fn($o) => $o['reminder'] === 'r8')), 0, 'L3 השבתה — המופעים בוטלו');

// מייל: נשלח רק בערוץ מייל, לכתובת מאומתת, בלי השם החופשי
$at('2026-11-25T03:00:00Z');
$remM = ['id' => 'rm', 'name' => 'שם-חופשי-L10', 'type' => 'fixed', 'time' => '06:00', 'days' => [0, 1, 2, 3, 4, 5, 6], 'active' => true,
    'prayer' => ['edition' => $ED, 'sid' => 'x1', 'name' => 'מנחה'], 'channels' => ['push' => false, 'inapp' => false, 'email' => true]];
$ok(rcall('POST', 'sync-push', ['items' => [['pid' => 'rem:rm', 'kind' => 'reminder', 'data' => $remM], ['pid' => 'pref:loc', 'kind' => 'pref', 'data' => ['kind' => 'city', 'id' => 281184], 'base' => 99]]], 'B'), 'תזכורת במייל');
$at('2026-11-25T04:00:30Z');
$GLOBALS['sf_mail_out'] = [];
$before = count($GLOBALS['t_push']);
sf_rem_send_due();
$mail = $GLOBALS['sf_mail_out'][0] ?? [];
eq([count($GLOBALS['sf_mail_out']), $mail['to'] ?? null, count($GLOBALS['t_push'])], [1, 'dina@example.com', $before], 'ערוץ מייל בלבד — מייל אחד לכתובת המאומתת, בלי Push');
check('המייל: התפילה, השעה והקישור — בלי השם החופשי של המשתמש', str_contains($mail['body'] ?? '', 'מנחה') && str_contains($mail['body'] ?? '', '06:00')
    && str_contains($mail['body'] ?? '', "#/r/$ED/x1") && !str_contains($mail['body'] ?? '', 'שם-חופשי'));
sf_exec('UPDATE readers SET email_verified_at = NULL WHERE id = ?', [$rid]);
sf_rem_schedule($OWN);
eq(count(array_filter($occ($OWN), fn($o) => $o['channel'] === 'email')), 0, 'מייל שלא אומת — אין מופעי מייל ("דורש אימות")');
sf_exec('UPDATE readers SET email_verified_at = ? WHERE id = ?', [sf_now(), $rid]);
$ok(rcall('POST', 'sync-push', ['items' => [['pid' => 'rem:rm', 'kind' => 'reminder', 'deleted' => true, 'base' => 99]]], 'B'), 'ניקוי');

// ── L5: שבת, שבת+חג רצופים, יום טוב שני בחו"ל — אף ערוץ לא שולח ──
/** מחפש מהתאריך: יום שבו kodesh שונה בין ישראל לחו"ל, ורצף של 3 ימים בחו"ל */
$f0 = sf_cal_fixed_from_greg(2026, 12, 1);
$sheni = $run3 = null;
for ($f = $f0; $f < $f0 + 900 && (!$sheni || !$run3); $f++) {
    if (!$sheni && sf_rem_kodesh($f, false) && !sf_rem_kodesh($f, true) && sf_cal_weekday($f) !== 6) $sheni = $f;
    if (!$run3 && sf_rem_kodesh($f, false) && sf_rem_kodesh($f + 1, false) && sf_rem_kodesh($f + 2, false) && !sf_rem_kodesh($f - 1, false) && (sf_cal_weekday($f) === 6 || sf_cal_weekday($f + 1) === 6 || sf_cal_weekday($f + 2) === 6)) $run3 = $f;
}
check('נמצאו: יום טוב שני בחו"ל ורצף שבת+חג של 3 ימים', $sheni !== null && $run3 !== null);
$iso = function (int $f) { $g = sf_cal_greg_from_fixed($f); return sprintf('%04d-%02d-%02d', $g['y'], $g['m'], $g['d']); };
$all7 = [0, 1, 2, 3, 4, 5, 6];
$remsS = [
    ['id' => 's1', 'name' => 'בוקר', 'type' => 'fixed', 'time' => '10:00', 'days' => $all7, 'active' => true],
    ['id' => 's2', 'name' => 'ערב', 'type' => 'fixed', 'time' => '21:00', 'days' => $all7, 'active' => true],
    ['id' => 's3', 'name' => 'שקיעה', 'type' => 'relative', 'base' => 'ss', 'offset' => -10, 'days' => $all7, 'active' => true],
    ['id' => 's4', 'name' => 'שמע', 'type' => 'before_end', 'base' => 'shma', 'before' => 15, 'days' => $all7, 'active' => true],
];
/** מקרה השתקה: התכנון בשרת לכל ערוץ — Push, מייל — ובמכשיר (node) לבתוך האפליקציה ול-.ics */
$fx = ['cases' => []];
$muteCase = function (string $label, array $loc, int $fStart, int $days, ?array $ext = null) use (&$fx, $remsS, $def, $iso, $occ, $rid, $OWN) {
    $tz = sf_rem_place($loc)['tz'];
    $from = sf_cal_zoned_to_utc($tz, ...array_values(array_slice(sf_cal_greg_from_fixed($fStart - 1), 0, 3)) + [3 => 8, 4 => 0]);
    $GLOBALS['sf_time'] = intdiv($from, 1000);
    $raw = ['reminders' => array_map(fn($r) => $r + ['channels' => ['push' => true, 'inapp' => true, 'email' => true]], $remsS),
        'prefs' => ['extend' => $ext ?? ['before' => 0, 'after' => 0], 'tz' => $tz], 'loc' => $loc, 'zmethod' => null];
    $items = array_map(fn($r) => ['pid' => 'rem:' . $r['id'], 'kind' => 'reminder', 'data' => $r, 'base' => 999], $raw['reminders']);
    $items[] = ['pid' => 'pref:remprefs', 'kind' => 'pref', 'data' => $raw['prefs'], 'base' => 999];
    $items[] = ['pid' => 'pref:loc', 'kind' => 'pref', 'data' => $loc, 'base' => 999];
    rcall('POST', 'sync-push', ['items' => $items], 'B');
    $ctx = sf_rem_owner_ctx($OWN);
    $hours = 24 * ($days + 2);
    $plan = sf_rem_plan($ctx, $from, $from + $hours * 3600000);
    // השרת: 48 שעות בכל פעם, לאורך כל התקופה (כמו המשימה השעתית)
    $bad = [];
    for ($t = $from; $t < $from + $hours * 3600000; $t += 6 * 3600000) {
        $GLOBALS['sf_time'] = intdiv($t, 1000);
        sf_rem_schedule($OWN);
    }
    foreach (sf_rows("SELECT * FROM reminder_occurrences WHERE owner = ? AND status = 'pending'", [$OWN]) as $o) {
        $ms = strtotime($o['fire_at']) * 1000;
        foreach ($plan['mute'] as $w) if ($ms >= $w['start'] && $ms < $w['end']) $bad[] = $o['channel'] . '@' . $o['fire_at'];
    }
    $muted = array_values(array_filter($plan['occ'], fn($o) => $o['muted']));
    $fx['cases'][] = ['label' => $label, 'ctx' => array_diff_key($ctx, ['byId' => 1, 'push' => 1, 'email' => 1, 'prefs' => 1]), 'from' => $from, 'to' => $from + $hours * 3600000, 'plan' => $plan];
    return ['plan' => $plan, 'bad' => $bad, 'muted' => $muted, 'from' => $from, 'tz' => $tz];
};
// שבת רגילה בירושלים
$sat = $f0 + ((6 - sf_cal_weekday($f0)) % 7 + 7) % 7;
while (sf_rem_kodesh($sat - 1, true) || sf_rem_kodesh($sat + 1, true)) $sat += 7;
$c1 = $muteCase('שבת, ירושלים', ['kind' => 'city', 'id' => 281184], $sat, 1);
eq([count($c1['plan']['mute']), $c1['bad']], [1, []], 'L5 שבת: חלון השתקה אחד; אין מופע Push או מייל בתוכו');
check('L5 שבת: 4 התזכורות של השבת עצמה מושתקות (10:00, 21:00 של ליל שבת, שקיעה, שמע)', count($c1['muted']) >= 4);
$w = $c1['plan']['mute'][0];
$ssFri = sf_rem_sunset(sf_rem_place(['kind' => 'city', 'id' => 281184]), $sat - 1);
$ssSat = sf_rem_sunset(sf_rem_place(['kind' => 'city', 'id' => 281184]), $sat);
eq([$w['start'], $w['end']], [$ssFri - 40 * 60000, $ssSat + 72 * 60000], 'L5 החלון: 40 דקות לפני השקיעה בשישי עד 72 אחרי השקיעה בשבת (שיטת בדיקה)');
// ביציאה לא נשלח מה שהוחמץ — לכל ערוץ
$GLOBALS['sf_time'] = intdiv($w['end'], 1000) + 60;
$GLOBALS['sf_mail_out'] = [];
$before = count($GLOBALS['t_push']);
sf_rem_send_due();
$sentIn = (int)sf_val("SELECT COUNT(*) FROM reminder_occurrences WHERE owner = ? AND status = 'sent' AND fire_at >= ? AND fire_at < ?", [$OWN, sf_iso(intdiv($w['start'], 1000)), sf_iso(intdiv($w['end'], 1000))]);
eq([count($GLOBALS['t_push']) - $before, count($GLOBALS['sf_mail_out']), $sentIn], [0, 0, 0], 'L5 במוצאי שבת — לא נשלח שום Push ושום מייל ממה שהוחמץ');
// הארכה של המשתמש — רק מאריכה
$c1x = $muteCase('שבת, ירושלים, הארכה', ['kind' => 'city', 'id' => 281184], $sat, 1, ['before' => 30, 'after' => 120]);
$wx = $c1x['plan']['mute'][0];
eq([$wx['start'], $wx['end']], [$w['start'] - 30 * 60000, $w['end'] + 120 * 60000], 'L5 הארכה: 30 דקות לפני ושעתיים אחרי');
eq(sf_rem_prefs(['extend' => ['before' => -60, 'after' => 999]])['extend'], ['before' => 0, 'after' => 0], 'L5 אי אפשר לקצר (שלילי) או מעבר לתקרה');
// רצף שבת וחג (חו"ל: ניו יורק)
$c2 = $muteCase('רצף 3 ימים, ניו יורק', ['kind' => 'city', 'id' => 5128581], $run3, 3);
eq([count($c2['plan']['mute']), $c2['bad']], [1, []], 'L5 רצף שבת+חג: חלון אחד רציף, אין מופע בתוכו');
$w2 = $c2['plan']['mute'][0];
check('L5 הרצף: מערב היום הראשון עד מוצאי השלישי', $w2['end'] - $w2['start'] > 2 * 86400000);
// יום טוב שני בחו"ל: מושתק בניו יורק, לא בירושלים
$c3 = $muteCase('יום טוב שני, ניו יורק', ['kind' => 'city', 'id' => 5128581], $sheni, 1);
$c4 = $muteCase('אותו יום, ירושלים', ['kind' => 'city', 'id' => 281184], $sheni, 1);
$onDay = function (array $c, int $f) {
    $tz = $c['tz'];
    return array_values(array_filter($c['plan']['occ'], fn($o) => sf_rem_local_fixed($tz, $o['ms']) === $f && $o['ms'] > sf_cal_zoned_to_utc($tz, ...array_values(array_slice(sf_cal_greg_from_fixed($f), 0, 3)) + [3 => 9, 4 => 0])
        && $o['ms'] < sf_cal_zoned_to_utc($tz, ...array_values(array_slice(sf_cal_greg_from_fixed($f), 0, 3)) + [3 => 12, 4 => 0])));
};
$nyDay = $onDay($c3, $sheni);
$ilDay = $onDay($c4, $sheni);
check('L5 יום טוב שני (' . $iso($sheni) . ') בניו יורק — מושתק', $nyDay && !array_filter($nyDay, fn($o) => !$o['muted']));
check('L5 …ובירושלים — יום חול, לא מושתק', $ilDay && !array_filter($ilDay, fn($o) => $o['muted']));
eq($c3['bad'], [], 'L5 יום טוב שני בחו"ל — אין מופע Push או מייל בהשתקה');
// בלי מיקום: לפי אזור הזמן, רחב
$ctxN = sf_rem_ctx(['reminders' => $remsS, 'prefs' => ['tz' => 'Europe/London'], 'loc' => null]);
$fromN = sf_cal_zoned_to_utc('Europe/London', ...array_values(array_slice(sf_cal_greg_from_fixed($sat - 1), 0, 3)) + [3 => 8, 4 => 0]);
$pN = sf_rem_plan($ctxN, $fromN, $fromN + 72 * 3600000);
$gN = sf_cal_greg_from_fixed($sat - 1);
$gA = sf_cal_greg_from_fixed($sat + 1);
eq([count($pN['mute']), $pN['mute'][0]['start'] ?? null, $pN['mute'][0]['end'] ?? null, $pN['mute'][0]['approx'] ?? null],
    [1, sf_cal_zoned_to_utc('Europe/London', $gN['y'], $gN['m'], $gN['d'], 12, 0), sf_cal_zoned_to_utc('Europe/London', $gA['y'], $gA['m'], $gA['d'], 1, 0), true],
    'L5 בלי מיקום — מ-12:00 בשישי עד 01:00 במוצאי שבת, לפי אזור הזמן');
eq([$pN['need']['place'], ctype_digit((string)count($pN['occ']))], [true, true], 'בלי מיקום — תזכורת יחסית לא מתוכננת (need.place)');
$fx['cases'][] = ['label' => 'בלי מיקום, לונדון', 'ctx' => array_diff_key($ctxN, ['byId' => 1, 'prefs' => 1]), 'from' => $fromN, 'to' => $fromN + 72 * 3600000, 'plan' => $pN];
// בלי שיטה מאושרת: need.method
$ctxM = sf_rem_ctx(['reminders' => $remsS, 'loc' => ['kind' => 'city', 'id' => 281184]]);
$ctxM['def'] = null;
eq(sf_rem_plan($ctxM, $fromN, $fromN + 3600000)['need']['method'], true, 'בלי שיטה — need.method (לא מחשבים שעה)');
$ok(rcall('POST', 'sync-push', ['items' => array_map(fn($r) => ['pid' => 'rem:' . $r['id'], 'kind' => 'reminder', 'deleted' => true, 'base' => 999], $remsS)], 'B'), 'ניקוי');

// ── L7: אורח — בלי מייל ובלי חשבון ──────────────────────────
$at('2026-11-23T03:00:00Z');
$uaG = $ua();
$EPG = 'https://updates.push.services.mozilla.com/wpush/v2/guest';
eq(code(rcall('POST', 'reminders-guest-sync', ['reminders' => []], 'G')), 'not_found', 'אורח בלי מכשיר Push — אין מה לסנכרן (שום דבר לא נשמר בשרת)');
$devG = $ok(rcall('POST', 'push-subscribe', ['sub' => $sub($EPG, $uaG)], 'G'), 'L7 אורח מפעיל Push')['device'];
$g = sf_row('SELECT * FROM push_devices WHERE pid = ?', [$devG]);
eq([$g['reader_id'], $g['device_id'], strlen((string)$g['guest_hash'])], [null, null, 64], 'L7 מכשיר אנונימי: בלי קורא, בלי מכשיר של 05 — רק hash של אסימון');
$remG = ['id' => 'g1', 'name' => 'אורח-סודי-L10', 'type' => 'minyan', 'time' => '05:45', 'days' => [0, 1, 2, 3, 4, 5, 6], 'active' => true, 'channels' => ['push' => true, 'inapp' => true, 'email' => true]];
$gs = $ok(rcall('POST', 'reminders-guest-sync', ['reminders' => [$remG], 'prefs' => ['tz' => 'Asia/Jerusalem'], 'loc' => ['kind' => 'city', 'id' => 281184]], 'G'), 'L7 ההגדרות של האורח');
$gOcc = $occ('g:' . $g['id']);
eq([count($gOcc), array_unique(array_column($gOcc, 'channel'))], [2, ['push']], 'L7 מופעי Push לאורח — ואין מייל גם כשביקש (אין לו מייל)');
check('L7 ההגדרות של האורח במסד — מוצפנות', str_starts_with((string)sf_val('SELECT guest_data FROM push_devices WHERE id = ?', [$g['id']]), 's1:')
    && !str_contains((string)sf_val('SELECT guest_data FROM push_devices WHERE id = ?', [$g['id']]), 'אורח-סודי'));
check('L7 אין שורת קורא לאורח', (int)sf_val('SELECT COUNT(*) FROM readers') === 1);
$at('2026-11-23T03:45:10Z');
sf_rem_send_due();
eq([count($pushesTo($EPG)), ($decrypt($uaG, $pushesTo($EPG)[0]['body'] ?? '') ?? [])['t'] ?? null], [1, 'אורח-סודי-L10'], 'L7 האורח קיבל את ה"מניין" שלו ב-Push');
// אורח אחר לא נוגע במכשיר של האורח הזה
eq(code(rcall('POST', 'push-unsubscribe', ['device' => $devG], 'H')), 'not_found', 'אורח אחר — לא יכול לבטל מכשיר שאינו שלו');
eq(code(rcall('POST', 'push-test', ['device' => $devG], 'B')), 'not_found', 'קורא — לא יכול לשלוח למכשיר של אורח');
eq(code(rcall('POST', 'push-unsubscribe', ['device' => $devB], 'G')), 'not_found', 'אורח — לא יכול לבטל מכשיר של קורא');
eq(rcall('GET', 'push-key', [], 'H')[1]['devices'] ?? null, [], 'אורח בלי מכשיר — לא רואה מכשירים של אחרים');
eq(code(rcall('POST', 'reminders-guest-sync', ['reminders' => []], 'B')), 'state', 'קורא מחובר — לא דרך guest-sync (רק הסנכרון)');
eq(code(rcall('POST', 'reminders-guest-sync', ['reminders' => array_fill(0, 101, $remG)], 'G')), 'bad_input', 'מגבלה: עד 100 תזכורות');
eq(code(rcall('POST', 'reminders-guest-sync', ['reminders' => [$remG], 'loc' => ['x' => str_repeat('א', 40000)]], 'G')), 'too_large', 'מגבלה: עד 64KB');
// התראת ניסיון — גם בלי cron
$t = $ok(rcall('POST', 'push-test', ['device' => $devG], 'G'), 'התראת ניסיון');
eq([$t['sent'], ($decrypt($uaG, end($GLOBALS['t_push'])['body']) ?? [])['u'] ?? null], [true, '#/reminders'], 'push-test: נשלחה מיד, בלי מתזמן');
for ($i = 0; $i < 10; $i++) rcall('POST', 'push-test', ['device' => $devG], 'G');
eq(code(rcall('POST', 'push-test', ['device' => $devG], 'G')), 'rate', 'push-test: עד 10 בשעה');
// אורח שנכנס לחשבון: אותו מנוי עובר לחשבון, וההגדרות של האורח לא נשארות
$ok(rcall('POST', 'account-login', ['email' => 'dina@example.com', 'password' => 'dina-dina-1'], 'G'), 'האורח נכנס לחשבון');
$ok(rcall('POST', 'push-subscribe', ['sub' => $sub($EPG, $uaG)], 'G'), 'אותו מנוי — לחשבון');
$g2 = sf_row('SELECT * FROM push_devices WHERE pid = ?', [$devG]);
eq([(int)$g2['reader_id'], $g2['guest_hash'], $g2['guest_data']], [$rid, null, null], 'המנוי עבר לחשבון; מה שהיה של האורח נמחק ממנו');
eq(count($occ('g:' . $g['id'])), 0, '…והמופעים של האורח בוטלו');
// ספק שאומר 410 — המנוי מסומן gone
$GLOBALS['t_push_status'][$EPB] = 410;
$at('2026-11-23T06:00:00Z');
$ok(rcall('POST', 'sync-push', ['items' => [['pid' => 'rem:rz', 'kind' => 'reminder', 'data' => ['id' => 'rz', 'name' => 'z', 'type' => 'fixed', 'time' => '08:05', 'days' => [1], 'active' => true, 'devices' => [$devB], 'channels' => ['push' => true, 'inapp' => false, 'email' => false]]]]], 'B'), 'תזכורת');
$at('2026-11-23T06:05:10Z');
sf_rem_send_due();
check('ספק שהחזיר 410 — המנוי מסומן gone, ולא יקבל מופעים', sf_val('SELECT gone_at FROM push_devices WHERE pid = ?', [$devB]) !== null);
// מופע שעבר זמנו (cron לא רץ) — missed, לא נשלח באיחור
$at('2026-11-23T06:30:00Z');
sf_insert('reminder_occurrences', ['owner' => $OWN, 'reminder' => 'rz', 'fire_at' => '2026-11-23T06:00:00Z', 'channel' => 'push', 'push_device_id' => (int)$g2['id'], 'dedupe_key' => 'late-1',
    'status' => 'pending', 'attempts' => 0, 'created_at' => sf_now(), 'updated_at' => sf_now()]);
$before = count($GLOBALS['t_push']);
sf_rem_send_due();
eq([sf_val("SELECT status FROM reminder_occurrences WHERE dedupe_key = 'late-1'"), count($GLOBALS['t_push'])], ['missed', $before], 'מופע שאיחר יותר מ-15 דקות — missed, לא נשלח');

// ── L10: לוג השליחה (וכל מה שנשמר בשרת) בלי שם תזכורת ובלי שם תפילה ──
$dump = json_encode([sf_rows('SELECT * FROM reminder_send_log'), sf_rows('SELECT * FROM reminder_occurrences'), sf_rows('SELECT * FROM jobs'), sf_rows('SELECT * FROM audit_log'),
    sf_rows('SELECT * FROM push_devices')], JSON_UNESCAPED_UNICODE);
check('L10 בלוג, במופעים, בתור, ביומן ובמכשירים — אין שם תזכורת ואין שם תפילה', !preg_match('/סודי|סודית|שם-חופשי|L10/u', $dump));
eq(array_keys(sf_rows('SELECT * FROM reminder_send_log LIMIT 1')[0] ?? []), ['id', 'occurrence_id', 'channel', 'result', 'http', 'at'], 'L10 הלוג: מזהה מופע, ערוץ, תוצאה, קוד — וזהו');
$DEV = ident('802', 'מפתח', 'dev@example.test', ['platform_admin' => true]);
call('GET', 'me', [], $DEV);
$dv = $ok(call('GET', 'dev-reminders', [], $DEV), 'dev-reminders');
check('L10 dev-reminders: מונים ולוג — בלי שמות', count($dv['log']) > 0 && !preg_match('/סודי|סודית|שם-חופשי/u', json_encode($dv, JSON_UNESCAPED_UNICODE)));
$logs = '';
foreach (glob(SF_DATA_DIR . '/{,*/,*/*/}*.{jsonl,log,json}', GLOB_BRACE) ?: [] as $f) if (!str_ends_with($f, 'vapid.json')) $logs .= (string)file_get_contents($f);
check('L10 היומן הטכני (DevLoop) — בלי שמות ובלי מיקום של אורח', !preg_match('/סודי|סודית|שם-חופשי/u', $logs));
$src = (string)file_get_contents("$root/lib/reminders.php");
check('L10 הלוג נכתב רק דרך sf_rem_log (מזהה, ערוץ, תוצאה, קוד)', substr_count($src, "sf_insert('reminder_send_log'") === 1);

// ── בדיקות תקינות, מסך, מעטפת ────────────────────────────────
$hl = $ok(call('GET', 'dev-health', [], $DEV), 'dev-health');
$ids = array_column($hl['checks'], 'state', 'id');
eq([$ids['push'] ?? null, isset($ids['reminders'])], ['ok', true], 'בדיקות התקינות: push ו-reminders');
$scr = (string)file_get_contents("$root/assets/js/screens/reminders.js");
check('L6 המסך מציג את מגבלת היומן החיצוני', str_contains($scr, 'אירוע שנוסף ליומן לא יתעדכן ולא יבוטל מספורנו'));
check('L6 "ליומן" — רק למופע שאינו מושתק', str_contains($scr, "o.muted ? null : h('button'"));
$sw = (string)file_get_contents("$root/sw.js");
check('L2 sw.js: push + notificationclick, ופותח רק כתובת בתוך ה-scope', str_contains($sw, "addEventListener('push'") && str_contains($sw, "addEventListener('notificationclick'")
    && str_contains($sw, "new URL('index.html' + u, self.registration.scope)") && str_contains($sw, '/^#\\/[A-Za-z0-9/_-]*$/'));
$jsAll = (string)file_get_contents("$root/assets/js/reminders.js");
check('Push מופעל רק מלחיצה (requestPermission במקום אחד)', substr_count($jsAll . $scr, 'requestPermission(') === 1 && str_contains($scr, 'SF.rem.enablePush()'));
check('אין מפתח VAPID פרטי בקוד', !preg_match('/BEGIN (EC )?PRIVATE KEY/', $src . $jsAll . $scr . $sw));
$deploy = (string)@file_get_contents(dirname($root) . '/.github/workflows/deploy.yml');
check('data/ מוחרגת בפריסה (vapid.json שם)', $deploy === '' || str_contains($deploy, 'sforno/data'));

// ── ביקורת 08 סבב 1: תקרות, ניקוי אורחים, מחיקת חשבון, השתלטות endpoint ──
$at('2026-11-26T03:00:00Z');
$many = [];
for ($i = 0; $i < 150; $i++) $many[] = ['pid' => "rem:m$i", 'kind' => 'reminder', 'data' => ['id' => "m$i", 'name' => '', 'type' => 'fixed', 'time' => '12:00', 'days' => [3], 'active' => true]];
$ok(rcall('POST', 'sync-push', ['items' => $many], 'B'), '150 תזכורות לקורא');
eq(count(sf_rem_owner_ctx($OWN)['reminders']), SF_REM_MAX, 'תקרה: קורא עם 150 תזכורות — מתוכננות 100 בלבד');
$ok(rcall('POST', 'sync-push', ['items' => array_map(fn($x) => ['pid' => $x['pid'], 'kind' => 'reminder', 'deleted' => true, 'base' => 9999], $many)], 'B'), 'ניקוי');
$bad = $ua();
$fake = $sub('https://fcm.googleapis.com/fcm/send/not-on-curve', $bad);
$fake['keys']['p256dh'] = sf_b64u("\x04" . str_repeat("\x01", 64));
eq(code(rcall('POST', 'push-subscribe', ['sub' => $fake], 'B')), 'bad_input', 'p256dh שאינה נקודה על P-256 — נדחה');
eq(code(rcall('POST', 'push-subscribe', ['sub' => $sub($EPG, $ua())], 'H')), 'bad_input', 'השתלטות: endpoint רשום עם מפתחות אחרים (עוגייה אחרת) — נדחה');
eq((int)sf_val('SELECT reader_id FROM push_devices WHERE pid = ?', [$devG]), $rid, '…והמנוי נשאר של בעליו');
for ($i = 0; $i < 9; $i++) {
    sf_insert('push_devices', ['pid' => sf_pid(), 'reader_id' => $rid, 'device_id' => (int)sf_val('SELECT device_id FROM push_devices WHERE pid = ?', [$devG]), 'guest_hash' => null,
        'endpoint_hash' => "cap$i", 'sub' => 's1:x', 'label' => 'x', 'created_at' => sf_now(), 'last_seen_at' => sf_now()]);
}
eq(code(rcall('POST', 'push-subscribe', ['sub' => $sub('https://fcm.googleapis.com/fcm/send/eleventh', $ua())], 'B', [], ['ip' => '203.0.113.99'])), 'state', 'תקרה: מכשיר Push פעיל 11 לקורא — נדחה');
$ok(rcall('POST', 'push-subscribe', ['sub' => $sub($EPG, $uaG)], 'G', [], ['ip' => '203.0.113.99']), 'רישום מחדש של מכשיר קיים — לא נספר פעמיים');
sf_exec("DELETE FROM push_devices WHERE endpoint_hash LIKE 'cap%'");
// ניקוי אורחים
$uaK = $ua();
$devK = $ok(rcall('POST', 'push-subscribe', ['sub' => $sub('https://fcm.googleapis.com/fcm/send/guest-k', $uaK)], 'K', [], ['ip' => '203.0.113.98']), 'אורח K')['device'];
$ok(rcall('POST', 'reminders-guest-sync', ['reminders' => [$remG], 'prefs' => ['tz' => 'Asia/Jerusalem'], 'loc' => ['kind' => 'city', 'id' => 281184]], 'K'), 'הגדרות K');
$kid = (int)sf_val('SELECT id FROM push_devices WHERE pid = ?', [$devK]);
$devL = $ok(rcall('POST', 'push-subscribe', ['sub' => $sub('https://fcm.googleapis.com/fcm/send/guest-l', $ua())], 'L', [], ['ip' => '203.0.113.98']), 'אורח L')['device'];
$ok(rcall('POST', 'reminders-guest-sync', ['reminders' => [$remG]], 'L'), 'הגדרות L');
$lid = (int)sf_val('SELECT id FROM push_devices WHERE pid = ?', [$devL]);
check('לאורח K יש מופעים', count($occ("g:$kid")) > 0);
$ok(rcall('POST', 'push-unsubscribe', ['device' => $devK], 'K'), 'K מכבה');
sf_exec('UPDATE push_devices SET last_seen_at = ? WHERE id = ?', [sf_iso(sf_time() - SF_PUSH_TTL - 86400), $lid]);
$liveG = (int)sf_val('SELECT COUNT(*) FROM push_devices WHERE reader_id IS NULL AND revoked_at IS NULL AND gone_at IS NULL AND last_seen_at >= ?', [sf_iso(sf_time() - SF_PUSH_TTL)]);
sf_rem_guest_cleanup();
eq([(int)sf_val('SELECT COUNT(*) FROM push_devices WHERE id IN (?, ?)', [$kid, $lid]), (int)sf_val('SELECT COUNT(*) FROM reminder_occurrences WHERE owner IN (?, ?)', ["g:$kid", "g:$lid"])], [0, 0],
    'ניקוי אורחים: שבוטל ושלא נראה מעבר לתוקף — נמחקו עם ההגדרות והמופעים');
eq((int)sf_val('SELECT COUNT(*) FROM push_devices WHERE reader_id IS NULL'), $liveG, '…ואורח פעיל — נשאר');
check('הניקוי רץ במשימה השעתית', str_contains((string)file_get_contents("$root/lib/reminders.php"), "    sf_rem_guest_cleanup();\n}, every: 3600);"));
// מחיקת חשבון
$at('2026-11-26T03:00:00Z');
$ok(rcall('POST', 'sync-push', ['items' => [['pid' => 'rem:d1', 'kind' => 'reminder', 'data' => ['id' => 'd1', 'name' => 'd', 'type' => 'fixed', 'time' => '09:00', 'days' => [0, 1, 2, 3, 4, 5, 6], 'active' => true]]]], 'G'), 'תזכורת לפני מחיקה');
check('לפני המחיקה: מנויים ומופעים ממתינים', count($occ($OWN)) > 0 && (int)sf_val('SELECT COUNT(*) FROM push_devices WHERE reader_id = ?', [$rid]) > 0);
$ok(rcall('POST', 'account-delete', ['confirm' => true], 'G'), 'מחיקת חשבון');
eq([(int)sf_val('SELECT COUNT(*) FROM push_devices WHERE reader_id = ?', [$rid]), count($occ($OWN))], [0, 0], 'מחיקת חשבון: מנויי ה-Push והמופעים הממתינים — נמחקו');

// ── node: אותו חישוב במכשיר ───────────────────────────────────
$fx['clean'] = [];
foreach ([$rem1, $rem3, $remG, ['id' => 'x', 'type' => 'fixed', 'time' => '25:00', 'days' => [1]], ['id' => 'y', 'type' => 'minyan', 'time' => '05:00', 'days' => [1], 'name' => ' '],
    ['id' => 'z', 'type' => 'relative', 'base' => 'ss', 'offset' => 301, 'days' => [1]], ['id' => 'w', 'type' => 'before_end', 'base' => 'shma', 'before' => 10, 'days' => [9, 3, 3, 1]],
    ['id' => 'v', 'type' => 'fixed', 'time' => '06:00', 'days' => [], 'name' => 'x']] as $r) $fx['clean'][] = ['in' => $r, 'out' => sf_rem_clean($r)];
$fx['prefs'] = [];
foreach ([null, ['channels' => ['push' => false, 'email' => true], 'extend' => ['before' => 30, 'after' => 500], 'tz' => 'Europe/Paris']] as $p) $fx['prefs'][] = ['in' => $p, 'out' => sf_rem_prefs($p)];
file_put_contents(SF_DATA_DIR . '/rem-fx.json', json_encode($fx, JSON_UNESCAPED_UNICODE));
exec('command -v node 2>/dev/null', $_n, $nodeCode);
check('node מותקן (בדיקות התזכורות במכשיר)', $nodeCode === 0);
if ($nodeCode === 0) {
    exec('node ' . escapeshellarg(__DIR__ . '/reminders-core.mjs') . ' ' . escapeshellarg(SF_DATA_DIR . '/rem-fx.json') . ' 2>&1', $out, $code);
    $res = json_decode((string)end($out), true);
    eq($code, 0, 'reminders-core.mjs רץ' . ($code ? ': ' . implode(' | ', array_slice($res['fails'] ?? $out, 0, 6)) : ''));
    echo '  (reminders-core.mjs: ' . ($res['n'] ?? 0) . " בדיקות)\n";
}

done();
