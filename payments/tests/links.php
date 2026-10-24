<?php
/**
 * בדיקות שלב 6: php payments/tests/links.php
 * קישורי תשלום, כללים, וספריות הלקוח (PHP ו-JS) מול שרת מקומי אמיתי.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/pay-links-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("PAY_DATA_DIR=$tmp");
putenv('PAY_ALLOW_HTTP=1');
$sock = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock); // פורט פנוי
$base = "http://127.0.0.1:$port/";
putenv("PAY_BASE_URL=$base");
putenv('PAY_DEV_USER=' . json_encode(['sub' => '1', 'name' => 'המנהל']));

require_once __DIR__ . '/../lib/pages.php';
require_once __DIR__ . '/../sdk/php/PaymentsClient.php';

$fail = 0;
$pass = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail, $pass;
    if ($ok) { $pass++; echo "  ✓ $name\n"; }
    else { $fail++; echo "  ✗ $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function throws(callable $fn, string $code): bool
{
    try { $fn(); } catch (ApiError $e) { return $e->codeName === $code; } catch (PaymentsError $e) { return $e->codeName === $code; }
    return false;
}
function section(string $s): void { echo "\n$s\n"; }
$GLOBALS['PAY_MAIL_HOOK'] = fn() => true;
$GLOBALS['PAY_HTTP_HOOK'] = fn() => ['status' => 200, 'body' => 'ok'];

$owner = current_tenant();
$mer = merchant_save($owner, ['name' => 'עמותה', 'tax_status' => 'exempt']);
$app = client_create($owner, ['id' => 'shul', 'name' => 'בית הכנסת', 'kind' => 'static', 'merchant' => $mer['id']]);
$don = product_save($owner, 'shul', ['code' => 'donation', 'name' => 'תרומה', 'type' => 'unit']);
$tkt = product_save($owner, 'shul', ['code' => 'ticket', 'name' => 'כרטיס לאירוע', 'type' => 'unit']);
$plan = product_save($owner, 'shul', ['code' => 'member', 'name' => 'חבר', 'type' => 'plan']);
price_create($owner, $don['id'], ['amount' => 0]);
price_create($owner, $tkt['id'], ['amount' => 5000]);
price_create($owner, $plan['id'], ['amount' => 2000, 'interval' => 'month']);

section('קישורי תשלום');
check('מוצר מנוי — לא לקישור', throws(fn() => link_save($owner, ['client' => 'shul', 'title' => 'x', 'product' => 'member']), 'bad_product'));
check('מחיר 0 בסכום קבוע — נדחה', throws(fn() => link_save($owner, ['client' => 'shul', 'title' => 'x', 'product' => 'donation']), 'bad_amount'));
check('כסף אמיתי לפני אישור המערכת — נדחה', throws(fn() => link_save($owner, ['client' => 'shul', 'title' => 'x', 'product' => 'ticket', 'env' => 'live']), 'live_not_approved'));
$L1 = link_save($owner, ['client' => 'shul', 'title' => 'כרטיס לערב', 'product' => 'ticket', 'quantity' => 2, 'fields' => ['name'], 'max_uses' => 1]);
check('קישור נוצר, עם מייל (חובה לקבלה)', strlen($L1['code']) === 10 && in_array('email', $L1['fields'], true) && str_contains($L1['url'], 'pay/link.php?k='));
$L2 = link_save($owner, ['client' => 'shul', 'title' => 'תרומה', 'product' => 'donation', 'amount_mode' => 'custom', 'min_amount' => 1800, 'max_amount' => 100000, 'fields' => ['name', 'email', 'phone']]);
check('חסר שדה — נדחה', throws(fn() => link_checkout($L1['code'], ['email' => 'a@b.co']), 'missing_field'));
check('מייל לא תקין — נדחה', throws(fn() => link_checkout($L1['code'], ['name' => 'דנה', 'email' => 'x']), 'bad_email'));
$url = link_checkout($L1['code'], ['name' => 'דנה', 'email' => 'Dana@Example.com']);
$chk = checkout_load(explode('c=', $url)[1]);
check('כרטיסים × 2 = ₪100 (המחיר שלנו)', (int)$chk['amount'] === 10000);
check('סכום לבחירה מתחת למינימום — נדחה', throws(fn() => link_checkout($L2['code'], ['name' => 'א', 'email' => 'a@b.co', 'phone' => '050-1234567', 'amount' => '5']), 'bad_amount'));
check('סכום לבחירה מעל המקסימום — נדחה', throws(fn() => link_checkout($L2['code'], ['name' => 'א', 'email' => 'a@b.co', 'phone' => '050-1234567', 'amount' => '5000']), 'bad_amount'));
$url2 = link_checkout($L2['code'], ['name' => 'א', 'email' => 'a@b.co', 'phone' => '050-1234567', 'amount' => '180']);
$chk2 = checkout_load(explode('c=', $url2)[1]);
check('תרומה של ₪180', (int)$chk2['amount'] === 18000 && checkout_view($chk2)['lines'][0]['amount'] === 18000);
// משלמים ב-L1 (סולק מדומה)
checkout_start($chk, 'card');
(new FakeProvider())->payFromPage(checkout_load($chk['id'])['provider_ref'], 'card', '4242424242424242');
checkout_sync(checkout_load($chk['id']));
check('שולם → שימוש נספר', (int)db_one('SELECT uses FROM payment_link WHERE id = ?', [$L1['id']])['uses'] === 1);
check('מגבלת שימוש — הקישור נוצל', throws(fn() => link_open($L1['code']), 'used_up'));
$inv = db_one("SELECT * FROM invoice WHERE checkout = ?", [$chk['id']]);
check('בחשבונית: הסכום ששולם', (int)$inv['total'] === 10000);
checkout_start($chk2, 'card');
(new FakeProvider())->payFromPage(checkout_load($chk2['id'])['provider_ref'], 'card', '4242424242424242');
checkout_sync(checkout_load($chk2['id']));
check('תרומה: חשבונית ₪180, פטור — בלי מע"מ', (int)db_one("SELECT total, vat FROM invoice WHERE checkout = ?", [$chk2['id']])['total'] === 18000
    && (int)db_one("SELECT vat FROM invoice WHERE checkout = ?", [$chk2['id']])['vat'] === 0);
check('הטופס נשמר בקנייה ונשלח בהודעה', json_obj(checkout_load($chk2['id'])['meta'])['form']['phone'] === '050-1234567');
// טופס ציבורי לא מוכיח בעלות על מייל: מי שמקליד מייל של אחר לא נוגע בחשבון שלו
$accDana = db_one("SELECT a.* FROM account a JOIN customer c ON c.account = a.id WHERE c.id = ?", [$chk['customer']]);
$url3 = link_checkout($L2['code'], ['name' => '=HYPERLINK("x")', 'email' => 'dana@example.com', 'phone' => '050-9999999', 'amount' => '20']);
$chk3 = checkout_load(explode('c=', $url3)[1]);
check('אותו מייל מטופס — לקוח נפרד, החשבון הקיים לא השתנה', $chk3['account'] !== $accDana['id']
    && db_one('SELECT name FROM account WHERE id = ?', [$accDana['id']])['name'] === $accDana['name']);
check('שימוש נספר כשנפתחת קנייה, ומשתחרר כשהיא פגה', (int)db_one('SELECT uses FROM payment_link WHERE id = ?', [$L2['id']])['uses'] === 2
    && (db_run('UPDATE checkout SET expires_at = 1 WHERE id = ?', [$chk3['id']]) || true) && checkout_load($chk3['id'])['status'] === 'expired'
    && (int)db_one('SELECT uses FROM payment_link WHERE id = ?', [$L2['id']])['uses'] === 1);
link_save($owner, ['id' => $L2['id'], 'client' => 'shul', 'title' => 'תרומה', 'product' => 'donation', 'amount_mode' => 'custom', 'min_amount' => 1800, 'max_amount' => 100000, 'active' => false]);
check('קישור כבוי — לא נפתח', throws(fn() => link_open($L2['code']), 'not_found'));
check('קוד לא קיים — לא נפתח', throws(fn() => link_open('AAAAAAAAAA'), 'not_found'));

section('כללים');
check('כלל בלי פעולה — נדחה', throws(fn() => rule_save($owner, ['name' => 'x', 'cond' => ['min_amount' => 1]]), 'no_action'));
$r1 = rule_save($owner, ['name' => 'ביט רק עד 500', 'cond' => ['min_amount' => 50001], 'act' => ['hide_methods' => ['bit']]]);
$L3 = link_save($owner, ['client' => 'shul', 'title' => 'תרומה גדולה', 'product' => 'donation', 'amount_mode' => 'custom', 'min_amount' => 100, 'max_amount' => 2000000, 'fields' => ['name', 'email', 'tax_id']]);
$small = checkout_load(explode('c=', link_checkout($L3['code'], ['name' => 'א', 'email' => 'x@y.co', 'amount' => '100']))[1]);
$big = checkout_load(explode('c=', link_checkout($L3['code'], ['name' => 'א', 'email' => 'x@y.co', 'amount' => '1000']))[1]);
check('₪100 — כרטיס וביט', checkout_view($small)['methods'] === ['card', 'bit']);
check('₪1,000 — בלי ביט', checkout_view($big)['methods'] === ['card']);
rule_save($owner, ['name' => 'מעל 10,000 — לפנות', 'priority' => 5, 'cond' => ['min_amount' => 1000001], 'act' => ['block' => 'לתרומה כזו — צרו קשר ונשמח']]);
check('מעל ₪10,000 — חסימה עם ההודעה', (function () use ($L3) {
    try { link_checkout($L3['code'], ['name' => 'א', 'email' => 'x@y.co', 'amount' => '15000']); } catch (ApiError $e) { return $e->codeName === 'blocked_by_rule' && str_contains($e->getMessage(), 'צרו קשר'); }
    return false;
})());
rule_save($owner, ['name' => 'עסקים — רק כרטיס', 'cond' => ['customer_type' => 'business'], 'act' => ['only_methods' => ['card']]]);
$biz = checkout_load(explode('c=', link_checkout($L3['code'], ['name' => 'חברה', 'email' => 'c@d.co', 'tax_id' => '515555555', 'amount' => '100']))[1]);
check('לקוח עסקי (ח"פ) — רק כרטיס', checkout_view($biz)['methods'] === ['card']);
$other = client_create($owner, ['id' => 'other', 'name' => 'אחרת', 'merchant' => $mer['id']]);
rule_save($owner, ['name' => 'רק לאחרת', 'scope_client' => 'other', 'cond' => [], 'act' => ['block' => 'סגור']]);
check('כלל של מערכת אחרת — לא חל כאן', checkout_view($small)['methods'] === ['card', 'bit']);
$t = rules_eval($owner['id'], ['client' => 'shul', 'env' => 'test', 'mode' => 'payment', 'amount' => 60000, 'customer_type' => 'private', 'products' => []]);
check('בדיקת כללים: מה חל ומה התוצאה', count($t['matched']) === 1 && $t['hide'] === ['bit'] && $t['block'] === null);
rule_save($owner, ['id' => $r1['id'], 'name' => 'ביט רק עד 500', 'cond' => ['min_amount' => 50001], 'act' => ['hide_methods' => ['bit']], 'active' => false]);
check('כלל כבוי — לא חל', checkout_view($big)['methods'] === ['card', 'bit']);
check('שינוי כלל — גרסה ויומן', (int)db_one('SELECT version FROM rule WHERE id = ?', [$r1['id']])['version'] === 2 && (bool)db_one("SELECT 1 FROM audit WHERE action = 'rule.updated'"));

// ---------------------------------------------------------------
section('ספריית הלקוח — PHP, מול שרת אמיתי');
$app2 = client_create($owner, ['id' => 'recipes', 'name' => 'מתכונים', 'merchant' => $mer['id']]);
client_update($owner, 'recipes', ['webhook_url' => 'https://example.com/hook']);
$ai = product_save($owner, 'recipes', ['code' => 'ai', 'name' => 'AI', 'type' => 'metered']);
price_create($owner, $ai['id'], ['amount_micros' => 4000, 'package_size' => 1000]);
$sk = key_create($owner, 'recipes', 'test', 'sk');
$wh = key_create($owner, 'recipes', 'test', 'whsec');
$srv = proc_open("exec env PAY_DATA_DIR=$tmp PAY_ALLOW_HTTP=1 PAY_BASE_URL=$base php -S 127.0.0.1:$port -t " . escapeshellarg(dirname(__DIR__)), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(400_000);
$cache = new PDO('sqlite::memory:');
$pay = new PaymentsClient(['base_url' => $base, 'key' => $sk['key'], 'webhook_secret' => $wh['key'], 'cache' => $cache]);
check('ping', ($pay->ping()['client'] ?? '') === 'recipes');
$c = $pay->customer('user:7', ['name' => 'דנה', 'email' => 'dana@example.com']);
check('customer', $c['customer']['external_id'] === 'user:7');
db_tx(fn() => wallet_post($c['account']['id'], 2_000_000, 'grant', '', '', 'test'));
$r = $pay->reserve('user:7', 'ai', 100_000);
check('reserve — ₪0.40', $r['allowed'] && $r['balance'] === 160);
$cm = $pay->commit($r['reservation'], 50_000, 'log:1');
check('commit — ההפרש חזר', $cm['balance'] === 180 && $cm['cost_micros'] === 200_000);
check('usage עם אותו מפתח — כפול', $pay->usage('user:7', 'ai', 50_000, 'log:1')['duplicate']);
$e1 = $pay->entitlement('user:7');
check('entitlement', $e1['balance'] === 180 && empty($e1['stale']));
$co = $pay->checkout('user:7', 'topup', [], 'https://example.com/b', 'https://example.com/c', ['amount' => 1000]);
check('checkout — כתובת דף תשלום', str_contains($co['url'], '/pay/?c=chk_'));
check('checkoutStatus', $pay->checkoutStatus($co['checkout'])['status'] === 'open');
check('portalUrl', str_contains($pay->portalUrl('user:7', 'https://example.com/app'), '/portal/?s='));
check('שגיאה מהתוסף — PaymentsError עם קוד', throws(fn() => $pay->usage('nobody', 'ai', 1, 'x'), 'not_found'));
// webhook — HMAC
$evtId = 'evt_' . str_repeat('a', 20);
$body = json_encode(['id' => $evtId, 'type' => 'ping', 'app' => 'recipes', 'env' => 'test', 'external_id' => 'user:7', 'data' => []]);
$hdr = webhook_headers(client_get($owner, 'recipes'), 'test', $evtId, $body);
$hh = [];
foreach ($hdr as $line) { [$k, $v] = explode(': ', $line, 2); $hh[strtolower($k)] = $v; }
check('webhook HMAC — מאומת', $pay->webhook($body, $hh)['id'] === $evtId);
check('webhook — גוף ששונה נדחה', throws(fn() => $pay->webhook($body . ' ', $hh), 'bad_signature'));
$old = $hh; $old['x-payments-time'] = (string)(time() - 1000);
check('webhook — ישן נדחה', throws(fn() => $pay->webhook($body, $old), 'stale'));
$sign = function (array $b) use ($owner, $evtId) {
    $raw = json_encode($b + ['id' => $evtId, 'type' => 'ping', 'external_id' => 'user:7', 'data' => []]);
    $h = [];
    foreach (webhook_headers(client_get($owner, 'recipes'), 'test', $evtId, $raw) as $line) { [$k, $v] = explode(': ', $line, 2); $h[strtolower($k)] = $v; }
    return [$raw, $h];
};
[$rawLive, $hLive] = $sign(['app' => 'recipes', 'env' => 'live']);
check('webhook — סביבה אחרת (חי לקוד בדיקות) נדחה', throws(fn() => $pay->webhook($rawLive, $hLive), 'wrong_env'));
[$rawOther, $hOther] = $sign(['app' => 'other-site', 'env' => 'test']);
$payApp = new PaymentsClient(['base_url' => $base, 'key' => $sk['key'], 'webhook_secret' => $wh['key'], 'app' => 'recipes']);
check('webhook — מערכת אחרת נדחית', throws(fn() => $payApp->webhook($rawOther, $hOther), 'wrong_app'));
// webhook — Ed25519 (מערכת עם key_url)
client_update($owner, 'recipes', ['key_url' => 'https://example.com/key']);
$hdr2 = webhook_headers(client_get($owner, 'recipes'), 'test', $evtId, $body);
$h2 = [];
foreach ($hdr2 as $line) { [$k, $v] = explode(': ', $line, 2); $h2[strtolower($k)] = $v; }
check('webhook Ed25519 — מאומת במפתח הציבורי של התוסף', $pay->webhook($body, $h2)['id'] === $evtId);
// JS
$js = sys_get_temp_dir() . "/pay-js-test-" . getmypid() . '.mjs';
file_put_contents($js, "import { PaymentsClient } from " . json_encode(realpath(__DIR__ . '/../sdk/js/payments-client.mjs')) . ";\n"
    . "const pay = new PaymentsClient({ baseUrl: " . json_encode($base) . ", key: " . json_encode($sk['key']) . ", webhookSecret: " . json_encode($wh['key']) . ", app: 'recipes' });\n"
    . "const p = await pay.ping(); const e = await pay.entitlement('user:7');\n"
    . "const ev = await pay.webhook(Buffer.from(" . json_encode($body) . "), " . json_encode($h2) . ");\n"
    . "let bad = false; try { await pay.webhook(Buffer.from(" . json_encode($body . 'x') . "), " . json_encode($h2) . "); } catch (x) { bad = x.code === 'bad_signature'; }\n"
    . "let other = false; try { await pay.webhook(Buffer.from(" . json_encode($rawOther) . "), " . json_encode($hOther) . "); } catch (x) { other = x.code === 'wrong_app' || x.code === 'bad_signature'; }\n"
    . "console.log(JSON.stringify({ client: p.client, balance: e.balance, ev: ev.id, bad, other }));\n");
$out = json_decode((string)shell_exec('node ' . escapeshellarg($js) . ' 2>&1'), true);
check('ספריית JS: חתימה, זכאות, אימות Ed25519 ודחיית זיוף', ($out['client'] ?? '') === 'recipes' && ($out['balance'] ?? null) === 180 && ($out['ev'] ?? '') === $evtId && ($out['bad'] ?? false) === true && ($out['other'] ?? false) === true, json_encode($out));
@unlink($js);
// התוסף לא זמין — fail-open, ומטמון (ההודעות למעלה ניקו את המטמון של user:7 — כמתוכנן; קוראים שוב)
$pay->entitlement('user:7', true);
proc_terminate($srv);
usleep(200_000);
$e2 = $pay->entitlement('user:7', true);
check('התוסף לא עונה — הערך האחרון מהמטמון (stale)', !empty($e2['stale']) && $e2['balance'] === 180, json_encode($e2));
$pay2 = new PaymentsClient(['base_url' => $base, 'key' => $sk['key']]);
check('בלי מטמון — fail-open: מותר', $pay2->entitlement('user:7')['active'] === true);
$pay3 = new PaymentsClient(['base_url' => $base, 'key' => $sk['key'], 'fail' => 'closed']);
check('fail-closed — חסום', $pay3->entitlement('user:7')['active'] === false);

section('גיבוי וייצוא');
$name = backup_daily(true);
check('גיבוי נוצר, מוצפן', $name !== null && !str_contains((string)file_get_contents(pay_data_dir() . '/backups/' . $name), 'SQLite format'));
$plain = backup_read($name);
check('ונפתח במפתח למסד תקין', str_starts_with($plain, 'SQLite format 3'));
check('פעם ביום — השני מדלג', backup_daily() === null);
check('שם קובץ זר — נדחה', throws(fn() => backup_read('../payments.sqlite'), 'not_found'));
$csv = export_month($owner, gmdate('Y-m', now()), 'test');
check('ייצוא לרואה החשבון: כותרות, ושורה לכל תשלום', str_contains($csv, 'סכום') && str_contains($csv, 'כרטיס לאירוע') && substr_count($csv, "\n") >= 3, substr($csv, 0, 300));

section('גיבוי מחוץ לשרת');
require_once __DIR__ . '/../lib/offsite.php';
check('סיסמה קצרה — נדחתה', throws(fn() => offsite_save($owner, ['email' => 'me@example.com', 'passphrase' => 'short', 'enabled' => true]), 'weak_passphrase'));
check('מייל לא תקין — נדחה', throws(fn() => offsite_save($owner, ['email' => 'x', 'passphrase' => 'correct horse battery', 'enabled' => true]), 'bad_email'));
$o = offsite_save($owner, ['email' => 'me@example.com', 'passphrase' => 'correct horse battery', 'enabled' => true]);
check('נשמר — והסיסמה לא נשמרת בשום מקום', $o['enabled'] && !str_contains(json_encode(db_all('SELECT * FROM setting')), 'correct horse'));
$sent = [];
$GLOBALS['PAY_OFFSITE_HOOK'] = function ($to, $subj, $body, $file, $data) use (&$sent) { $sent[] = [$to, $file, $data]; return true; };
check('שבועי: נשלח פעם אחת, לא שוב באותו שבוע', offsite_weekly() && !offsite_weekly() && count($sent) === 1 && $sent[0][0] === 'me@example.com' && str_ends_with($sent[0][1], '.mbepay'));
check('הקובץ לא קריא בלי הסיסמה', !str_contains($sent[0][2], 'SQLite format') && offsite_decrypt($sent[0][2], 'wrong passphrase!!') === null);
check('ונפתח בסיסמה — למסד תקין', str_starts_with((string)offsite_decrypt($sent[0][2], 'correct horse battery'), 'SQLite format 3'));
$f = "$tmp/test.mbepay";
file_put_contents($f, $sent[0][2]);
$outDb = "$tmp/restored.sqlite";
exec('BACKUP_PASSPHRASE=' . escapeshellarg('correct horse battery') . ' php ' . escapeshellarg(__DIR__ . '/../tools/decrypt-backup.php') . ' ' . escapeshellarg($f) . ' ' . escapeshellarg($outDb) . ' 2>&1', $o2, $rc);
$restored = $rc === 0 ? (new PDO('sqlite:' . $outDb))->query("SELECT COUNT(*) FROM payment")->fetchColumn() : -1;
check('כלי השחזור — בלי השרת, פותח למסד עם התשלומים', $rc === 0 && (int)$restored === (int)db_one('SELECT COUNT(*) AS n FROM payment')['n'], implode(' ', $o2));
exec('BACKUP_PASSPHRASE=wrongwrongwrong php ' . escapeshellarg(__DIR__ . '/../tools/decrypt-backup.php') . ' ' . escapeshellarg($f) . ' ' . escapeshellarg("$tmp/x.sqlite") . ' 2>&1', $o3, $rc3);
check('…ובסיסמה שגויה — נכשל בלי לכתוב קובץ', $rc3 === 1 && !file_exists("$tmp/x.sqlite"));

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n" . ($fail ? "✗ $fail נכשלו, $pass עברו\n" : "✓ כל $pass הבדיקות עברו\n");
exit($fail ? 1 : 0);
