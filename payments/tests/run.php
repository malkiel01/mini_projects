<?php
/**
 * בדיקות התוסף: php payments/tests/run.php
 * מסד זמני — לא נוגע ב-data/. בודק: מנויים, עוסקים, מערכות וקודים, חתימה (HMAC ו-Ed25519), קטלוג,
 * לקוחות, ארנק, שימוש ושריון, זכאות, webhooks, הפרדה בין מערכות ובין סביבות — ובסוף בקשות HTTP אמיתיות.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/pay-tests-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("PAY_DATA_DIR=$tmp");
putenv('PAY_ALLOW_HTTP=1');
putenv('PAY_DEV_USER=' . json_encode(['sub' => '1', 'name' => 'המנהל', 'email' => 'owner@example.com']));

require_once __DIR__ . '/../lib/billing.php';

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
    try { $fn(); } catch (ApiError $e) { return $e->codeName === $code; }
    return false;
}
function section(string $s): void { echo "\n$s\n"; }

/** כותרות חתומות בקוד סודי — כמו שה-SDK יחתום. */
function hmac_headers(string $full, string $action, string $body): array
{
    [$keyId, $secret] = explode('.', $full, 2);
    $time = (string)now();
    $nonce = bin2hex(random_bytes(16));
    return ['X-PAY-KEY' => $keyId, 'X-PAY-TIME' => $time, 'X-PAY-NONCE' => $nonce,
        'X-PAY-SIGNATURE' => hash_hmac('sha256', sign_string($action, $time, $nonce, $body), $secret)];
}

// ---------------------------------------------------------------
section('מנויים');
$owner = current_tenant();
check('הראשון שנכנס — בעלים פעיל', $owner['role'] === 'owner' && $owner['status'] === 'active');
putenv('PAY_DEV_USER=' . json_encode(['sub' => '2', 'name' => 'אחר', 'email' => 'other@example.com']));
$other = current_tenant();
check('השני — מנוי ממתין', $other['role'] === 'tenant' && $other['status'] === 'pending');
putenv('PAY_DEV_USER=' . json_encode(['sub' => '1']));
check('אותו sub — אותו מנוי', current_tenant()['id'] === $owner['id']);

section('עוסקים');
$mer = merchant_save($owner, ['name' => 'מלכיאל — עוסק פטור', 'tax_status' => 'exempt', 'tax_id' => '012-345-678', 'vat_rate' => 1800]);
check('עוסק פטור — מע"מ 0', (int)$mer['vat_rate'] === 0 && $mer['tax_id'] === '012345678');
check('סוג עוסק לא מוכר — נדחה', throws(fn() => merchant_save($owner, ['name' => 'x', 'tax_status' => 'big']), 'bad_tax_status'));
$mer2 = merchant_save($owner, ['name' => 'חברה בע"מ', 'tax_status' => 'company']);
check('חברה — מע"מ 18%', (int)$mer2['vat_rate'] === 1800);

section('מערכות וקודים');
$app = client_create($owner, ['id' => 'guarded-browser', 'name' => 'מגדלור', 'kind' => 'internal']);
check('מערכת נוצרה, בלי live', $app['status'] === 'active' && !$app['live_ok']);
check('מזהה תפוס — נדחה', throws(fn() => client_create($owner, ['id' => 'guarded-browser']), 'taken'));
check('מזהה לא תקין — נדחה', throws(fn() => client_create($owner, ['id' => 'מגדלור']), 'bad_id'));
check('עוסק של מנוי אחר — לא נמצא', throws(fn() => client_create($other, ['id' => 'x-other', 'merchant' => $mer['id']]), 'not_found'));
$sk = key_create($owner, 'guarded-browser', 'test', 'sk');
check('קוד סודי: <id>.<secret>', str_starts_with($sk['key'], 'sk_test_') && str_contains($sk['key'], '.'));
$listed = keys_list($owner, 'guarded-browser');
check('הקוד לא נשמר גלוי ברשימה', !str_contains(json_encode($listed), explode('.', $sk['key'])[1]) && $listed[0]['last4'] === substr($sk['key'], -4));
$raw = db_one('SELECT secret_enc FROM api_key WHERE id = ?', [$sk['id']])['secret_enc'];
check('במסד — מוצפן', !str_contains($raw, explode('.', $sk['key'])[1]) && unseal($raw) === explode('.', $sk['key'])[1]);

section('חתימה — קוד סודי');
$body = json_encode(['external_id' => 'family:12']);
$ctx = auth_request('entitlement', $body, hmac_headers($sk['key'], 'entitlement', $body));
check('חתימה תקינה — מזוהה', $ctx['client']['id'] === 'guarded-browser' && $ctx['env'] === 'test');
$h = hmac_headers($sk['key'], 'entitlement', $body);
auth_request('entitlement', $body, $h);
check('nonce חוזר — נדחה', throws(fn() => auth_request('entitlement', $body, $h), 'replay'));
check('גוף ששונה — נדחה', throws(fn() => auth_request('entitlement', '{"external_id":"family:13"}', hmac_headers($sk['key'], 'entitlement', $body)), 'bad_signature'));
check('פעולה אחרת על אותה חתימה — נדחה', throws(fn() => auth_request('usage_record', $body, hmac_headers($sk['key'], 'entitlement', $body)), 'bad_signature'));
$old = hmac_headers($sk['key'], 'entitlement', $body);
putenv('PAY_NOW=' . (time() + 400));
check('זמן ישן — נדחה', throws(fn() => auth_request('entitlement', $body, $old), 'stale'));
putenv('PAY_NOW');
check('בלי חתימה — נדחה', throws(fn() => auth_request('entitlement', $body, []), 'no_auth'));
$skLive = key_create($owner, 'guarded-browser', 'live', 'sk');
check('live לפני אישור — נדחה', throws(fn() => auth_request('entitlement', $body, hmac_headers($skLive['key'], 'entitlement', $body)), 'live_not_approved'));
check('אישור חי בלי עוסק ותנאים — נדחה', throws(fn() => client_update($owner, 'guarded-browser', ['live_ok' => true]), 'not_ready'));
check('כתובת תנאים לא https — נדחתה', throws(fn() => client_update($owner, 'guarded-browser', ['terms_url' => 'javascript:alert(1)']), 'bad_url'));
check('עוסק בלי כתובת — עוד חסר', throws(fn() => client_update($owner, 'guarded-browser', ['merchant' => $mer['id'], 'terms_url' => 'https://x.co/terms', 'live_ok' => true]), 'not_ready'));
$mer = merchant_save($owner, ['id' => $mer['id'], 'name' => $mer['name'], 'tax_status' => 'exempt', 'tax_id' => '012345678', 'address' => 'הרצל 1, ירושלים', 'phone' => '050-000 0000']);
check('טלפון נשמר נקי', $mer['phone'] === '050-0000000' && merchant_missing($mer) === []);
$okApp = client_update($owner, 'guarded-browser', ['merchant' => $mer['id'], 'terms_url' => 'https://x.co/terms', 'live_ok' => true]);
check('פרטים מלאים — אושר לחי', (int)$okApp['live_ok'] === 1 && $okApp['terms_url'] === 'https://x.co/terms');
client_update($owner, 'guarded-browser', ['merchant' => '']); // הבדיקות שאחרי — מערכת בלי עוסק
check('live אחרי אישור — עובד', auth_request('entitlement', $body, hmac_headers($skLive['key'], 'entitlement', $body))['env'] === 'live');
$sk2 = key_create($owner, 'guarded-browser', 'test', 'sk');
check('החלפה — הישן עובד בחפיפה', auth_request('entitlement', $body, hmac_headers($sk['key'], 'entitlement', $body))['env'] === 'test');
putenv('PAY_NOW=' . (time() + KEY_OVERLAP + 10));
check('אחרי החפיפה — הישן נדחה', throws(fn() => auth_request('entitlement', $body, hmac_headers($sk['key'], 'entitlement', $body)), 'unknown_key'));
check('אחרי החפיפה — החדש עובד', auth_request('entitlement', $body, hmac_headers($sk2['key'], 'entitlement', $body))['env'] === 'test');
putenv('PAY_NOW');
key_revoke($owner, $sk2['id']);
check('ביטול — מיד', throws(fn() => auth_request('entitlement', $body, hmac_headers($sk2['key'], 'entitlement', $body)), 'unknown_key'));
$sk = key_create($owner, 'guarded-browser', 'test', 'sk', true);
client_update($owner, 'guarded-browser', ['status' => 'disabled']);
check('מערכת מושבתת — נדחה', throws(fn() => auth_request('entitlement', $body, hmac_headers($sk['key'], 'entitlement', $body)), 'disabled'));
client_update($owner, 'guarded-browser', ['status' => 'active']);

section('חתימה — Ed25519 (כמו embed.md)');
$pair = sodium_crypto_sign_keypair();
$GLOBALS['PAY_HTTP_HOOK'] = fn($url) => ['status' => 200, 'body' => json_encode(['client' => 'recipes-app', 'keys' => [['kid' => 'k1', 'alg' => 'Ed25519', 'key' => base64_encode(sodium_crypto_sign_publickey($pair))]]])];
$ed = client_create($owner, ['id' => 'recipes-app', 'name' => 'מתכונים', 'kind' => 'internal', 'merchant' => $mer['id']]);
client_update($owner, 'recipes-app', ['key_url' => 'https://mbe-plus.com/mini_projects/recipes-app/api.php?action=embed_key']);
$edSign = function (string $action, string $body, string $client = 'recipes-app~test') use ($pair) {
    $time = (string)now();
    $nonce = bin2hex(random_bytes(16));
    return ['X-EMBED-CLIENT' => $client, 'X-EMBED-TIME' => $time, 'X-EMBED-NONCE' => $nonce,
        'X-EMBED-SIGNATURE' => 'k1.' . base64_encode(sodium_crypto_sign_detached(sign_string($action, $time, $nonce, $body), sodium_crypto_sign_secretkey($pair)))];
};
check('Ed25519 תקינה — מזוהה', auth_request('ping', '{}', $edSign('ping', '{}'))['client']['id'] === 'recipes-app');
check('בלי ~test — live, שלא אושר', throws(fn() => auth_request('ping', '{}', $edSign('ping', '{}', 'recipes-app')), 'live_not_approved'));
$bad = sodium_crypto_sign_keypair();
$h = $edSign('ping', '{}');
$h['X-EMBED-SIGNATURE'] = 'k1.' . base64_encode(sodium_crypto_sign_detached(sign_string('ping', $h['X-EMBED-TIME'], $h['X-EMBED-NONCE'], '{}'), sodium_crypto_sign_secretkey($bad)));
check('מפתח זר — נדחה', throws(fn() => auth_request('ping', '{}', $h), 'bad_signature'));

section('מנוי מושהה');
putenv('PAY_DEV_USER=' . json_encode(['sub' => '2']));
$other = current_tenant();
db_run("UPDATE tenant SET status = 'active' WHERE id = ?", [$other['id']]);
$other = current_tenant();
$om = merchant_save($other, ['name' => 'עסק של אחר', 'tax_status' => 'licensed']);
client_create($other, ['id' => 'other-site', 'name' => 'אתר', 'merchant' => $om['id']]);
$osk = key_create($other, 'other-site', 'test', 'sk');
check('מנוי לא רואה מערכת של אחר', throws(fn() => client_get($other, 'guarded-browser'), 'not_found'));
check('מנוי לא יוצר קוד למערכת של אחר', throws(fn() => key_create($other, 'guarded-browser', 'test', 'sk'), 'not_found'));
check('מנוי לא מאשר live לעצמו', !client_update($other, 'other-site', ['live_ok' => true])['live_ok']);
db_run("UPDATE tenant SET status = 'suspended' WHERE id = ?", [$other['id']]);
check('השהיה — כל הקודים שלו נעצרים', throws(fn() => auth_request('ping', '{}', hmac_headers($osk['key'], 'ping', '{}')), 'tenant_suspended'));
putenv('PAY_DEV_USER=' . json_encode(['sub' => '1']));

section('קטלוג');
check('לקוח בלי עוסק — נדחה', throws(fn() => customer_upsert(client_get($owner, 'guarded-browser'), 'test', ['external_id' => 'family:1']), 'no_merchant'));
$app = client_update($owner, 'guarded-browser', ['merchant' => $mer['id'], 'webhook_url' => 'https://mbe-plus.com/mini_projects/guarded-browser/api.php?action=payments_webhook']);
$plan = product_save($owner, 'guarded-browser', ['code' => 'migdalor_apps', 'name' => 'מגדלור — אפליקציות', 'type' => 'plan', 'grants' => ['limits' => ['devices' => 3, 'children' => 3], 'features' => ['apps' => true]]]);
$seat = product_save($owner, 'guarded-browser', ['code' => 'extra_device', 'name' => 'מכשיר נוסף', 'type' => 'seat', 'grants' => ['limits' => ['devices' => 1]]]);
$ai = product_save($owner, 'guarded-browser', ['code' => 'ai_tokens', 'name' => 'AI', 'type' => 'metered', 'unit_label' => 'טוקנים']);
$post = product_save($owner, 'guarded-browser', ['code' => 'active_kids', 'name' => 'ילדים פעילים', 'type' => 'metered']);
check('מגבלה לא מספרית — נדחה', throws(fn() => product_save($owner, 'guarded-browser', ['code' => 'x', 'name' => 'x', 'type' => 'plan', 'grants' => ['limits' => ['a' => 'b']]]), 'bad_grants'));
$p1 = price_create($owner, $plan['id'], ['amount' => 3900, 'interval' => 'month']);
$p2 = price_create($owner, $plan['id'], ['amount' => 4900, 'interval' => 'month']);
check('מחיר חדש = גרסה חדשה', (int)$p1['version'] === 1 && (int)$p2['version'] === 2);
check('המחיר הנוכחי — הגרסה האחרונה', current_price('guarded-browser', 'migdalor_apps')[1]['id'] === $p2['id']);
check('מנוי בלי מחזור — נדחה', throws(fn() => price_create($owner, $plan['id'], ['amount' => 1, 'interval' => 'none']), 'bad_price'));
price_create($owner, $seat['id'], ['amount' => 1000]);
// ‏0.4 אג' לכל 1,000 טוקנים = 4,000 מיליוניות ₪ לחבילה של 1,000
price_create($owner, $ai['id'], ['amount_micros' => 4000, 'package_size' => 1000, 'prepaid' => true]);
price_create($owner, $post['id'], ['amount' => 800, 'prepaid' => false, 'aggregate' => 'max', 'included' => 3]);
$cat = catalog($app);
check('קטלוג: 4 מוצרים, למנוי 2 מחירים פעילים', count($cat) === 4 && count($cat[0]['prices']) === 2);
check('מוצר לא מוכר — נדחה', throws(fn() => current_price('guarded-browser', 'nope'), 'unknown_product'));

section('לקוחות');
$v = customer_upsert($app, 'test', ['external_id' => 'family:12', 'name' => 'משפחת כהן', 'email' => 'Cohen@Example.com']);
check('לקוח + חשבון + ארנק 0', str_starts_with($v['customer']['id'], 'cus_') && $v['account']['balance'] === 0 && $v['customer']['email'] === 'cohen@example.com');
$v2 = customer_upsert($app, 'test', ['external_id' => 'family:12', 'name' => 'משפחת כהן-לוי']);
check('אותו external_id — אותו לקוח, מעודכן', $v2['customer']['id'] === $v['customer']['id'] && $v2['customer']['name'] === 'משפחת כהן-לוי' && $v2['customer']['email'] === 'cohen@example.com');
$vLive = customer_upsert($app, 'live', ['external_id' => 'family:12']);
check('test ו-live — לקוחות נפרדים', $vLive['customer']['id'] !== $v['customer']['id']);
$edc = client_get($owner, 'recipes-app');
check('מערכת אחרת לא רואה את הלקוח', customer_find($edc, 'test', 'family:12', false) === null);
check('מייל לא תקין — נדחה', throws(fn() => customer_upsert($app, 'test', ['external_id' => 'x', 'email' => 'not-mail']), 'bad_email'));

section('ארנק');
$acc = $v['account']['id'];
check('טעינה בלי סיבה — נדחה', throws(fn() => wallet_admin($owner, $acc, 1000, 'topup', ''), 'note_required'));
check('טעינה שלילית — נדחה', throws(fn() => wallet_admin($owner, $acc, -5, 'topup', 'x'), 'bad_amount'));
$w = wallet_admin($owner, $acc, 1000, 'topup', 'העברה בנקאית 123');
check('טעינת 10 ₪', $w['balance'] === 1000 && $w['balance_micros'] === 10_000_000);
check('מנוי אחר לא נוגע בארנק', throws(fn() => wallet_admin($other, $acc, 100, 'grant', 'x'), 'not_found'));
check('תנועות הארנק — רק הוספה', (function () use ($acc) { try { db_run('DELETE FROM wallet_tx WHERE account = ?', [$acc]); return false; } catch (PDOException) { return true; } })());

section('שימוש מדוד בתשלום מראש');
$r = usage_record($app, 'test', ['external_id' => 'family:12', 'product' => 'ai_tokens', 'quantity' => 250_000, 'idempotency_key' => 'ai:1']);
check('250,000 טוקנים = 1 ₪ (100 אג\')', $r['cost_micros'] === 1_000_000 && $r['balance'] === 900 && $r['allowed']);
$r2 = usage_record($app, 'test', ['external_id' => 'family:12', 'product' => 'ai_tokens', 'quantity' => 250_000, 'idempotency_key' => 'ai:1']);
check('אותו מפתח — בלי חיוב נוסף', $r2['duplicate'] && $r2['event'] === $r['event'] && $r2['balance'] === 900);
$r3 = usage_record($app, 'test', ['external_id' => 'family:12', 'product' => 'ai_tokens', 'quantity' => 7, 'idempotency_key' => 'ai:2']);
check('7 טוקנים — שבר אגורה, נרשם במיליוניות', $r3['cost_micros'] === 28 && $r3['balance_micros'] === 9_000_000 - 28);
check('כמות שלילית — נדחה', throws(fn() => usage_record($app, 'test', ['external_id' => 'family:12', 'product' => 'ai_tokens', 'quantity' => -1, 'idempotency_key' => 'ai:3']), 'bad_quantity'));
check('בלי מפתח — נדחה', throws(fn() => usage_record($app, 'test', ['external_id' => 'family:12', 'product' => 'ai_tokens', 'quantity' => 1]), 'bad_idempotency_key'));
check('לקוח לא קיים — נדחה', throws(fn() => usage_record($app, 'test', ['external_id' => 'nobody', 'product' => 'ai_tokens', 'quantity' => 1, 'idempotency_key' => 'z']), 'not_found'));

section('שריון');
$res = usage_reserve($app, 'test', ['external_id' => 'family:12', 'product' => 'ai_tokens', 'estimate' => 1_000_000]);
check('שריון 4 ₪ — יורד מיד', $res['allowed'] && $res['balance'] === 499);
$c = usage_commit($app, 'test', ['reservation' => $res['reservation'], 'quantity' => 500_000, 'idempotency_key' => 'ai:res1']);
check('סגירה ב-500,000 — ההפרש חוזר', $c['balance'] === 699 && $c['cost_micros'] === 2_000_000);
check('סגירה שנייה — אותו אירוע', usage_commit($app, 'test', ['reservation' => $res['reservation'], 'quantity' => 500_000, 'idempotency_key' => 'ai:res1'])['duplicate']);
check('שריון סגור — לא נסגר שוב במפתח אחר', throws(fn() => usage_commit($app, 'test', ['reservation' => $res['reservation'], 'quantity' => 1, 'idempotency_key' => 'ai:res1b']), 'reservation_closed'));
$big = usage_reserve($app, 'test', ['external_id' => 'family:12', 'product' => 'ai_tokens', 'estimate' => 10_000_000]);
check('שריון גדול מהיתרה — allowed:false, בלי חיוב', !$big['allowed'] && $big['reservation'] === null && $big['balance'] === 699);
$res2 = usage_reserve($app, 'test', ['external_id' => 'family:12', 'product' => 'ai_tokens', 'estimate' => 100_000, 'ttl' => 60]);
check('שחרור — חוזר לארנק', usage_release($app, 'test', ['reservation' => $res2['reservation']])['balance'] === 699);
$res3 = usage_reserve($app, 'test', ['external_id' => 'family:12', 'product' => 'ai_tokens', 'estimate' => 100_000, 'ttl' => 60]);
putenv('PAY_NOW=' . (time() + 120));
check('שריון שפג — חוזר בעבודה המתוזמנת', reservations_expire() === 1);
putenv('PAY_NOW');
check('יתרה אחרי פקיעה', micros_to_agorot(wallet_balance($acc)) === 699);
check('שריון של מערכת אחרת — לא נמצא', throws(fn() => usage_release($edc, 'test', ['reservation' => $res3['reservation']]), 'not_found'));
check('שריון למוצר בדיעבד — נדחה', throws(fn() => usage_reserve($app, 'test', ['external_id' => 'family:12', 'product' => 'active_kids', 'estimate' => 1]), 'not_prepaid'));
$drain = usage_record($app, 'test', ['external_id' => 'family:12', 'product' => 'ai_tokens', 'quantity' => 5_000_000, 'idempotency_key' => 'ai:drain']);
check('שימוש שכבר קרה — נרשם גם מתחת לאפס, allowed:false', !$drain['allowed'] && $drain['balance'] < 0);

section('שימוש בדיעבד וסיכום');
foreach ([2, 5, 4] as $i => $n) usage_record($app, 'test', ['external_id' => 'family:12', 'product' => 'active_kids', 'quantity' => $n, 'idempotency_key' => "kids:$i"]);
$sum = usage_summary($app, 'test', ['external_id' => 'family:12']);
$kids = array_values(array_filter($sum['data'], fn($d) => $d['product'] === 'active_kids'))[0];
check('בדיעבד — בלי חיוב בארנק, שיא (max) = 5', $kids['quantity'] == 5 && $kids['cost'] === 0 && $kids['included'] === 3);

section('זכאות');
$e = entitlement($app, 'test', ['external_id' => 'family:12']);
check('בלי מנוי — לא פעיל, יש שימוש', !$e['active'] && $e['state'] === 'none' && $e['metered']->active_kids['used'] == 5);
check('לקוח לא מוכר — none', entitlement($app, 'test', ['external_id' => 'nobody'])['state'] === 'none');
$sub = subscription_grant($owner, $v['customer']['id'], ['items' => [['product' => 'migdalor_apps'], ['product' => 'extra_device', 'quantity' => 2]]]);
$e = entitlement($app, 'test', ['external_id' => 'family:12']);
check('מנוי ידני — פעיל, תכנית', $e['active'] && $e['state'] === 'active' && $e['plans'] === ['migdalor_apps']);
check('מגבלות: 3 + 2×1 = 5 מכשירים, 3 ילדים', $e['limits']->devices == 5 && $e['limits']->children == 3);
check('features: apps, extra_device=2', $e['features']->apps === true && $e['features']->extra_device === 2);
check('live לא רואה את מנוי ה-test', !entitlement($app, 'live', ['external_id' => 'family:12'])['active']);
subscription_cancel_admin($owner, $sub['id']);
check('ביטול — לא פעיל', !entitlement($app, 'test', ['external_id' => 'family:12'])['active']);
$s2 = subscription_grant($owner, $v['customer']['id'], ['items' => [['product' => 'migdalor_apps']], 'period_end' => now() + 60]);
putenv('PAY_NOW=' . (time() + 120));
check('אחרי סוף התקופה — לא פעיל', !entitlement($app, 'test', ['external_id' => 'family:12'])['active']);
putenv('PAY_NOW');

section('webhooks');
$sent = [];
$GLOBALS['PAY_HTTP_HOOK'] = function ($url, $post, $headers) use (&$sent) { $sent[] = [$url, $post, $headers]; return ['status' => 200, 'body' => 'ok']; };
$queued = (int)db_one("SELECT COUNT(*) AS n FROM webhook_delivery WHERE client = 'guarded-browser' AND status = 'pending'")['n'];
check('אירועים בתור', $queued > 0);
$d = webhooks_deliver(100);
check('נשלחו כולם', $d['sent'] === $queued && $d['failed'] === 0);
$wh = key_create($owner, 'guarded-browser', 'test', 'whsec');
$id = webhook_emit('guarded-browser', 'test', 'ping', null, []);
webhooks_deliver();
[$url, $post, $hdrs] = end($sent);
$hv = [];
foreach ($hdrs as $line) { [$k, $val] = explode(': ', $line, 2); $hv[$k] = $val; }
[$whId, $whSecret] = explode('.', $wh['key'], 2);
$want = 'hmac.' . hash_hmac('sha256', "webhook\n{$hv['X-Payments-Time']}\n$id\n" . hash('sha256', $post), $whSecret);
check('חתימת webhook ב-HMAC — המערכת יכולה לאמת', hash_equals($want, $hv['X-Payments-Signature']) && $hv['X-Payments-Event'] === $id);
$id2 = webhook_emit('recipes-app', 'test', 'ping', null, []);
client_update($owner, 'recipes-app', ['webhook_url' => 'https://mbe-plus.com/mini_projects/recipes-app/api.php?action=payments_webhook']);
$id2 = webhook_emit('recipes-app', 'test', 'ping', null, []);
webhooks_deliver();
[, $post2, $hdrs2] = end($sent);
$hv2 = [];
foreach ($hdrs2 as $line) { [$k, $val] = explode(': ', $line, 2); $hv2[$k] = $val; }
[$kid, $b64] = explode('.', $hv2['X-Payments-Signature'], 2);
$sk_ = signing_key();
check('חתימת webhook ב-Ed25519 — מאומתת במפתח הציבורי', $kid === $sk_['kid'] && sodium_crypto_sign_verify_detached(base64_decode($b64), "webhook\n{$hv2['X-Payments-Time']}\n$id2\n" . hash('sha256', $post2), $sk_['public']));
$GLOBALS['PAY_HTTP_HOOK'] = fn() => ['status' => 500, 'body' => ''];
$id3 = webhook_emit('guarded-browser', 'test', 'ping', null, []);
webhooks_deliver();
$row = db_one('SELECT * FROM webhook_delivery WHERE id = ?', [$id3]);
check('כשל — ניסיון חוזר אחרי דקה', $row['status'] === 'pending' && (int)$row['attempt'] === 1 && (int)$row['next_at'] >= now() + 59);
for ($i = 0; $i < count(WEBHOOK_BACKOFF); $i++) { db_run('UPDATE webhook_delivery SET next_at = 0 WHERE id = ?', [$id3]); webhooks_deliver(); }
check('אחרי כל הניסיונות — failed', db_one('SELECT status FROM webhook_delivery WHERE id = ?', [$id3])['status'] === 'failed');
unset($GLOBALS['PAY_HTTP_HOOK']);

section('ביקורת');
check('יש רשומות ביקורת', (int)db_one('SELECT COUNT(*) AS n FROM audit')['n'] > 10);
check('הביקורת — רק הוספה', (function () { try { db_run('UPDATE audit SET action = ?', ['x']); return false; } catch (PDOException) { return true; } })());
check('בדיקת שלמות: יתרה = סכום התנועות', (function () {
    foreach (db_all('SELECT b.account, b.micros, (SELECT COALESCE(SUM(amount),0) FROM wallet_tx t WHERE t.account = b.account) AS s FROM wallet_balance b') as $r) if ((int)$r['micros'] !== (int)$r['s']) return false;
    return true;
})());

// ---------------------------------------------------------------
section('HTTP אמיתי (שרת PHP מקומי)');
$sock = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock); // פורט פנוי
$docroot = dirname(__DIR__);
$env = "PAY_DATA_DIR=$tmp PAY_ALLOW_HTTP=1";
$srv = proc_open("exec env $env php -S 127.0.0.1:$port -t " . escapeshellarg($docroot), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(400_000);
$call = function (string $action, array $payload, ?string $key, string $method = 'POST') use ($port) {
    $body = json_encode($payload);
    $hdr = ['Content-Type: application/json'];
    if ($key) foreach (hmac_headers($key, $action, $body) as $k => $v) $hdr[] = "$k: $v";
    $ch = curl_init("http://127.0.0.1:$port/api.php?action=$action");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hdr, CURLOPT_TIMEOUT => 5]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$out, true)];
};
[$code, $j] = $call('key', [], null, 'GET');
check('מפתח ציבורי פתוח', $code === 200 && ($j['keys'][0]['alg'] ?? '') === 'Ed25519');
[$code, $j] = $call('ping', [], $sk['key']);
check('ping חתום', $code === 200 && ($j['client'] ?? '') === 'guarded-browser');
[$code, $j] = $call('ping', [], null);
check('בלי חתימה — 401', $code === 401 && ($j['code'] ?? '') === 'no_auth');
[$code, $j] = $call('customer_upsert', ['external_id' => 'user:7', 'name' => 'דנה'], $sk['key']);
check('customer_upsert ב-HTTP', $code === 200 && ($j['customer']['external_id'] ?? '') === 'user:7');
[$code, $j] = $call('usage_record', ['external_id' => 'user:7', 'product' => 'ai_tokens', 'quantity' => 1000, 'idempotency_key' => 'h:1'], $sk['key']);
check('usage_record ב-HTTP — יתרה שלילית, allowed:false', $code === 200 && $j['allowed'] === false && $j['cost_micros'] === 4000);
[$code, $j] = $call('entitlement', ['external_id' => 'user:7'], $sk['key']);
check('entitlement ב-HTTP', $code === 200 && $j['state'] === 'none' && isset($j['ttl']));
[$code, $j] = $call('nope', [], $sk['key']);
check('פעולה לא מוכרת — 404', $code === 404);
[$code, $j] = $call('usage_batch', ['events' => [
    ['external_id' => 'user:7', 'product' => 'ai_tokens', 'quantity' => 10, 'idempotency_key' => 'b:1'],
    ['external_id' => 'user:7', 'product' => 'ai_tokens', 'quantity' => 10, 'idempotency_key' => 'h:1'],
    ['external_id' => 'user:7', 'product' => 'nope', 'quantity' => 10, 'idempotency_key' => 'b:2'],
]], $sk['key']);
check('usage_batch: 1 התקבל, 1 כפול, 1 נדחה', $code === 200 && $j['accepted'] === 1 && $j['duplicates'] === 1 && count($j['rejected']) === 1);
check('יומן קריאות נרשם', (int)db_one("SELECT COUNT(*) AS n FROM api_log WHERE client = 'guarded-browser'")['n'] >= 5);
proc_terminate($srv);

// ---------------------------------------------------------------
exec('rm -rf ' . escapeshellarg($tmp));
echo "\n" . ($fail ? "✗ $fail נכשלו, $pass עברו\n" : "✓ כל $pass הבדיקות עברו\n");
exit($fail ? 1 : 0);
