<?php
// פרוסה 02 — הזמנה דרך המעטפת (docs/spec/02-invites-detached.md §4): 02-A1 … 02-A6, 02-C2, ומקרי הקצה 1–4.
// הכתובות לא נגזרות מהבקשה: גם עם Host זר — הכול לפי הכתובת הקבועה (tests/lib.php: example.test)
$_SERVER['SERVER_NAME'] = 'evil.example';
$_SERVER['HTTP_HOST'] = 'evil.example';
$_SERVER['SCRIPT_NAME'] = '/x/api.php';
require __DIR__ . '/lib.php';

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
$dana = ident('2', 'דנה', 'dana@example.com');
$A = office_for($owner, 'משרד א');

/** מה המעטפת המדומה עונה לבקשת provision: מערך (JSON), או null — לא עונה. */
function platform_answers(?array $answer): void
{
    $GLOBALS['apr_http_post'] = function (string $url, string $body, array $headers) use ($answer) {
        if (str_contains($url, 'action=grant_status')) return t_grant_answer($body);
        $GLOBALS['t_provision'][] = ['url' => $url, 'body' => json_decode($body, true), 'headers' => $headers, 'raw' => $body];
        return $answer === null ? null : [200, json_encode($answer)];
    };
}
$mails = fn() => count($GLOBALS['t_mail']);
$asked = fn() => count($GLOBALS['t_provision']);
$inv = fn(string $pid) => apr_row('SELECT * FROM invitations WHERE pid = ?', [$pid]);

// ── 02-A1: platform_key, והמפתח הפרטי לא יוצא ──
[$st, $k] = call('GET', 'platform_key');
eq([$st, $k['client'] ?? null, count($k['keys'] ?? []), $k['keys'][0]['alg'] ?? null], [200, 'appraisers', 1, 'Ed25519'], '02-A1 platform_key ציבורי: client ומפתח אחד, Ed25519');
$pub = base64_decode($k['keys'][0]['key'], true);
check('02-A1 המפתח הציבורי: 32 בתים ב-base64', is_string($pub) && strlen($pub) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
eq(call('GET', 'platform_key')[1]['keys'][0]['kid'], $k['keys'][0]['kid'], '02-A1 המפתח נוצר פעם אחת ונשאר');
$secret = apr_pkey()['secret'];
$forms = [base64_encode($secret), bin2hex($secret), base64_encode(substr($secret, 0, 32)), bin2hex(substr($secret, 0, 32))];
$leaks = function (mixed $body) use ($forms): bool {
    $j = json_encode($body, JSON_UNESCAPED_SLASHES);
    foreach ($forms as $f) if (str_contains($j, $f)) return true;
    return false;
};
foreach ([['platform_key', null], ['me', $owner], ['dev-health', $owner], ['dev-mail', $owner], ['office', $owner]] as [$action, $as]) {
    $r = call('GET', $action, [], $as);
    check("02-A1 $action: עונה, והמפתח הפרטי אינו בתשובה", $r[0] === 200 && !$leaks($r[1]));
}
check('02-A1 בדיקת הדליפה תופסת מפתח גלוי', $leaks(['x' => base64_encode($secret)]));
$stored = (string)apr_val('SELECT private_key FROM platform_keys');
check('02-A1 במסד המפתח הפרטי מוצפן', !str_contains($stored, $secret) && !str_contains($stored, substr($secret, 0, 32)));
// בדיקה סטטית: אין מחרוזת מפתח בקוד (base64 / hex ארוך בתוך מירכאות), ואין קובץ מפתח בגיט
$root = dirname(__DIR__);
foreach (array_merge(glob("$root/*.php"), glob("$root/lib/*.php"), glob("$root/*.json"), glob("$root/assets/js/*.js"), glob("$root/assets/js/screens/*.js")) as $f) {
    if (preg_match('#lib/devloop\.php$|js/devloop\.js$#', $f)) continue;
    check('02-A1 אין מחרוזת מפתח ב-' . basename($f), !preg_match('/[\'"](?:[A-Za-z0-9+\/]{43,}={0,2}|[a-f0-9]{64,})[\'"]/', file_get_contents($f)));
}
exec('git -C ' . escapeshellarg($root) . ' ls-files 2>/dev/null', $tracked);
eq(array_values(preg_grep('/\.(key|pem)$|secret/i', $tracked)), [], '02-A1 אין קובץ מפתח בגיט');

// ── 02-A2: invited → invitee_sub נשמר; אותו sub עם מייל לא מאומת רואה ומצטרף ──
platform_answers(['status' => 'invited', 'sub' => '777']);
[$st, $r] = call('POST', 'invite-create', ['email' => 'noa@example.com', 'name' => 'נועה כהן', 'role' => 'staff'], $owner);
eq([$st, $r['provision'] ?? null, $r['mail'] ?? null], [200, 'invited', 'sent'], '02-A2 הזמנה: החשבון נפתח במעטפת, והמייל שלנו נשלח');
eq([$inv($r['invite'])['invitee_sub'], $inv($r['invite'])['provision_status']], ['777', 'invited'], '02-A2 invitee_sub נשמר');
$req = end($GLOBALS['t_provision']);
eq([$req['url'], $req['body']], ['https://example.test/mini_projects/platform/provision.php',
    ['email' => 'noa@example.com', 'name' => 'נועה כהן', 'grant' => true, 'next' => '/mini_projects/appraisers/']], '02-A2 הבקשה: provision.php, עם grant ו-next בתוך האפליקציה');
$h = [];
foreach ($req['headers'] as $line) { [$n, $v] = explode(': ', $line, 2); $h[$n] = $v; }
[$kid, $sig] = explode('.', $h['X-Embed-Signature'], 2);
check('02-A2 הבקשה חתומה במפתח שב-platform_key (embed.md §2)', $h['X-Embed-Client'] === 'appraisers' && $kid === $k['keys'][0]['kid']
    && preg_match('/^[a-f0-9]{32}$/', $h['X-Embed-Nonce']) === 1 && abs(time() - (int)$h['X-Embed-Time']) < 5
    && sodium_crypto_sign_verify_detached(base64_decode($sig), "provision\n{$h['X-Embed-Time']}\n{$h['X-Embed-Nonce']}\n" . hash('sha256', $req['raw']), $pub));
$mail = end($GLOBALS['t_mail']);
check('02-A2 המייל: למוזמן, עם קישור ההזמנה', $mail['to'] === 'noa@example.com' && str_contains($mail['text'], 'https://example.test/mini_projects/appraisers/#/invite/' . $r['token']) && str_contains($mail['subject'], 'משרד א'));
// המוזמנת נכנסת עם ה-sub שהמעטפת החזירה, ובמייל אחר שלא אומת
$noa = ident('777', 'נועה', 'noa.private@example.com', ['email_verified' => false]);
[, $mine] = call('GET', 'my-invites', [], $noa);
eq([count($mine['invites']), $mine['invites'][0]['office_name'] ?? null, $mine['verified']], [1, 'משרד א', false], '02-A2 אותו sub, מייל לא מאומת — רואה את ההזמנה');
eq(call('GET', 'my-invites', [], ident('778', 'אחר', 'noa@example.com', ['email_verified' => false]))[1]['invites'], [], '02-A2 sub אחר עם אותו מייל לא מאומת — לא רואה');
eq(code(call('POST', 'invite-accept', ['invite' => $r['invite']], ident('778', 'אחר', 'noa@example.com', ['email_verified' => false]))), 'invite_invalid', '02-A2 ואינו יכול להצטרף');
eq(call('POST', 'invite-info', ['invite' => $r['invite']], $noa)[0], 200, '02-A2 פרטי ההזמנה — לפי ה-sub');
[$st, $acc] = call('POST', 'invite-accept', ['invite' => $r['invite']], $noa);
eq([$st, $acc['office'] ?? null], [200, $A], '02-A2 ומצטרפת — בלחיצה מפורשת');
eq(call('GET', 'me', [], $noa)[1]['office'], $A, '02-A2 חברה במשרד');
// גם דרך הקישור: sub תואם, מייל אחר
platform_answers(['status' => 'granted', 'sub' => '779']);
[, $r2] = call('POST', 'invite-create', ['email' => 'gil@example.com', 'role' => 'staff'], $owner);
eq([$r2['provision'], $inv($r2['invite'])['invitee_sub']], ['granted', '779'], 'granted: למוזמן יש כבר חשבון — ה-sub נשמר');
eq(end($GLOBALS['t_provision'])['body']['name'], 'gil', 'בלי שם — החלק שלפני ה-@');
eq(call('POST', 'invite-accept', ['token' => $r2['token']], ident('779', 'גיל', 'gil.other@example.com', ['email_verified' => false]))[0], 200, 'הקישור: sub תואם מספיק, גם כשהמייל אחר');

// ── 02-A3: blocked → בלי מייל, עם הנוסח המתאים ──
platform_answers(['status' => 'blocked']);
[$m0, $l0] = [$mails(), (int)apr_val('SELECT COUNT(*) FROM mail_log')];
[$st, $r] = call('POST', 'invite-create', ['email' => 'blocked@example.com', 'role' => 'staff'], $owner);
eq([$st, $r['provision'], $r['mail']], [200, 'blocked', null], '02-A3 blocked: ההזמנה נשמרת, בלי מייל');
eq([$mails(), (int)apr_val('SELECT COUNT(*) FROM mail_log')], [$m0, $l0], '02-A3 לא נשלח מייל, ואין שורה ב-mail_log');
eq([$inv($r['invite'])['status'], $inv($r['invite'])['provision_status'], $inv($r['invite'])['invitee_sub'], $inv($r['invite'])['email_status']], ['pending', 'blocked', null, null], '02-A3 ההזמנה נשארת, מסומנת blocked');
$strings = file_get_contents("$root/assets/js/strings.js");
check('02-A3 הנוסח למנהל: "אי אפשר להזמין את הכתובת הזו. פנו למנהל המערכת."', preg_match('/blocked:\s*\'אי אפשר להזמין את הכתובת הזו\. פנו למנהל המערכת\.\'/u', $strings) === 1);
foreach (['invited', 'granted', 'failed'] as $s) check("יש נוסח למנהל למצב $s", preg_match("/\\b$s:\\s*'[^']*[א-ת]/u", $strings) === 1);
eq(apr_val("SELECT COUNT(*) FROM jobs WHERE kind = 'invite.provision'"), 0, '02-A3 blocked אינו כישלון — אין ניסיון חוזר');

// ── 02-A4: המעטפת לא עונה → ההזמנה נשמרת, משימת רקע, בלי invitee_sub ──
platform_answers(null);
[$st, $r] = call('POST', 'invite-create', ['email' => 'late@example.com', 'role' => 'trainee'], $owner);
eq([$st, $r['provision'], $r['mail']], [200, 'failed', 'sent'], '02-A4 המעטפת לא עונה: ההזמנה נשמרת, והמייל שלנו יוצא');
eq([$inv($r['invite'])['status'], $inv($r['invite'])['provision_status'], $inv($r['invite'])['invitee_sub']], ['pending', 'failed', null], '02-A4 נכשלים סגור: לא נרשם invitee_sub');
$job = apr_row("SELECT * FROM jobs WHERE kind = 'invite.provision'");
eq([$job['status'] ?? null, json_decode($job['payload'] ?? '{}', true), (int)($job['max_attempts'] ?? 0)], ['pending', ['invite' => $r['invite']], 3], '02-A4 נוצרה משימת רקע לניסיון חוזר (3 ניסיונות), בלי מייל ב-payload');
foreach ([[500, '{}'], [200, 'not json'], [200, '{"status":"invited"}'], [200, '{"status":"invited","sub":"abc"}'], [200, '{"status":"weird","sub":"5"}']] as $i => $bad) {
    $GLOBALS['apr_http_post'] = fn() => $bad;
    eq(apr_provision('x@example.com', 'x'), null, "02-A4 תשובה לא מובנת ($i) אינה \"החשבון נפתח\"");
}
// מקרה 4: הניסיון החוזר מצליח — invitee_sub מתעדכן, ולא נשלח מייל שני
$m0 = $mails();
$t = time();
platform_answers(null);
$GLOBALS['apr_time'] = $t + 61;
apr_jobs_run(5.0);
eq([apr_val('SELECT status FROM jobs WHERE id = ?', [$job['id']]), apr_val('SELECT attempts FROM jobs WHERE id = ?', [$job['id']]), $inv($r['invite'])['invitee_sub']], ['pending', 1, null], 'ניסיון חוזר שנכשל — המשימה חוזרת לתור');
platform_answers(['status' => 'invited', 'sub' => '880']);
$GLOBALS['apr_time'] = $t + 61 + 301;
apr_jobs_run(5.0);
eq([apr_val('SELECT status FROM jobs WHERE id = ?', [$job['id']]), $inv($r['invite'])['invitee_sub'], $inv($r['invite'])['provision_status'], $mails()], ['done', '880', 'invited', $m0], 'מקרה 4: הניסיון החוזר הצליח — ה-sub נרשם, ולא נשלח מייל שני');
unset($GLOBALS['apr_time']);
// שלושה כישלונות → המשימה נכשלת, וההזמנה נשארת בלי sub
platform_answers(null);
[, $r3] = call('POST', 'invite-create', ['email' => 'never@example.com', 'role' => 'staff'], $owner);
foreach ([61, 400, 2300] as $dt) { $GLOBALS['apr_time'] = $t + $dt; apr_jobs_run(5.0); }
unset($GLOBALS['apr_time']);
eq([apr_val("SELECT status FROM jobs WHERE dedupe_key LIKE ?", ['invite.provision:' . $r3['invite'] . ':%']), $inv($r3['invite'])['invitee_sub']], ['failed', null], '02-A4 אחרי 3 ניסיונות — נכשלה; ה-sub לא הומצא');

// ── 02-A5: אותה הזמנה פעמיים עם אותו מזהה פעולה ──
platform_answers(['status' => 'invited', 'sub' => '881']);
[$p0, $l0] = [$asked(), (int)apr_val("SELECT COUNT(*) FROM mail_log WHERE kind = 'invite'")];
[, $one] = call('POST', 'invite-create', ['email' => 'twice@example.com', 'role' => 'staff', 'op' => 'op-12345678'], $owner);
[$st, $two] = call('POST', 'invite-create', ['email' => 'twice@example.com', 'role' => 'staff', 'op' => 'op-12345678'], $owner);
eq([$st, $two['invite'], $two['repeat'] ?? null, isset($two['token'])], [200, $one['invite'], true, false], '02-A5 שליחה שנייה: אותה הזמנה, בלי אסימון חדש');
eq([$asked() - $p0, (int)apr_val("SELECT COUNT(*) FROM mail_log WHERE kind = 'invite'") - $l0], [1, 1], '02-A5 בקשת provision אחת ושורה אחת ב-mail_log');
eq((int)apr_val("SELECT COUNT(*) FROM invitations WHERE email = 'twice@example.com'"), 1, '02-A5 הזמנה אחת');
eq(apr_mail_send('invite', 'twice@example.com', 'x', 'y', 'invite:' . $one['invite']), 'sent', 'מפתח אידמפוטנטיות: אותו מייל לא יוצא פעמיים');
eq((int)apr_val("SELECT COUNT(*) FROM mail_log WHERE kind = 'invite'") - $l0, 1, 'ואין שורה שנייה');

// ── 02-A6: מי שאינו מנהל אינו יכול לגרום לבקשת provision ──
$danaPid = join_office($owner, $dana, 'dana@example.com');
$p0 = $asked();
eq(code(call('POST', 'invite-create', ['email' => 'x1@example.com', 'role' => 'staff'], $dana)), 'forbidden', '02-A6 חבר שאינו מנהל: invite-create → 403');
eq(code(call('POST', 'invite-resend', ['invite' => $one['invite']], $dana)), 'forbidden', '02-A6 חבר שאינו מנהל: invite-resend → 403');
eq(code(call('POST', 'invite-create', ['email' => 'x1@example.com', 'role' => 'staff'], ident('60', 'זר', 'zar@example.com'))), 'no_office', '02-A6 מי שאינו חבר בשום משרד');
// בידוד: מנהל של משרד ב׳ על הזמנה של משרד א׳
$boaz = ident('3', 'בועז', 'boaz@example.com');
office_for($boaz, 'משרד ב');
eq(code(call('POST', 'invite-resend', ['invite' => $one['invite']], $boaz)), 'not_found', '02-A6 בידוד: מנהל משרד ב׳ לא שולח שוב הזמנה של משרד א׳');
eq(code(call('POST', 'invite-resend', ['invite' => 'aaaaaaaaaaaaaaaa'], $boaz)), 'not_found', 'והזמנה שלא קיימת — אותה תשובה');
call('POST', 'member-update', ['user' => $danaPid, 'status' => 'suspended'], $owner);
eq(call('POST', 'invite-create', ['email' => 'x1@example.com', 'role' => 'staff'], $dana)[0], 403, '02-A6 חבר מושעה');
call('POST', 'member-update', ['user' => $danaPid, 'status' => 'active'], $owner);
// מקרה 1: המוזמן כבר חבר — לא נשלחת בקשה
eq(code(call('POST', 'invite-create', ['email' => 'dana@example.com', 'role' => 'staff'], $owner)), 'already_member', 'מקרה 1: כבר חבר/ה במשרד');
eq(code(call('POST', 'invite-create', ['email' => 'לא-מייל', 'role' => 'staff'], $owner)), 'email_invalid', 'מייל לא תקין');
eq($asked(), $p0, '02-A6 בכל המקרים האלה — אף בקשת provision');
// מנהל המערכת שמקים משרד אינו "מנהל משרד פעיל" — ההזמנה שלו בלי provision
call('POST', 'sys-office-create', ['name' => 'משרד ג', 'admin_email' => 'new-admin@example.com'], $owner);
eq([$asked(), apr_val("SELECT provision_status FROM invitations WHERE email = 'new-admin@example.com'")], [$p0, null], '§4.3 provision רק בהזמנה של מנהל משרד');

// ── מקרה 3: provision הצליח, המייל שלנו נכשל ──
$GLOBALS['apr_mail'] = fn() => false;
[, $r] = call('POST', 'invite-create', ['email' => 'nomail@example.com', 'role' => 'staff'], $owner);
eq([$r['provision'], $r['mail'], $inv($r['invite'])['email_status'], $inv($r['invite'])['email_sent_at'], $inv($r['invite'])['status']], ['invited', 'failed', 'failed', null, 'pending'], 'מקרה 3: המייל נכשל — לא מוצג כנשלח, וההזמנה תקפה');
eq(apr_row("SELECT status, error FROM mail_log ORDER BY id DESC LIMIT 1")['status'], 'failed', 'ונרשם ב-mail_log כנכשל');
$GLOBALS['apr_mail'] = function () { throw new RuntimeException('smtp: nomail@example.com rejected'); };
[, $rr] = call('POST', 'invite-resend', ['invite' => $r['invite']], $owner);
eq($rr['mail'], 'failed', 'שליחה שוב שנכשלה בחריגה');
$GLOBALS['apr_mail'] = function (string $to, string $subject, string $text) { $GLOBALS['t_mail'][] = compact('to', 'subject', 'text'); return true; };
[$st, $view] = call('GET', 'office', [], $owner);
$row = array_values(array_filter($view['invites'], fn($i) => $i['email'] === 'nomail@example.com'))[0];
eq([$row['provision'], $row['mail']], ['invited', 'failed'], 'מסך המשרד: מצב פתיחת החשבון ומצב המייל בשורת ההזמנה');
eq(array_values(array_filter($view['invites'], fn($i) => $i['email'] === 'blocked@example.com'))[0]['provision'], 'blocked', 'וגם blocked');

// ── שליחה שוב ──
platform_answers(['status' => 'invited', 'sub' => '882']);
[, $r] = call('POST', 'invite-create', ['email' => 'again@example.com', 'role' => 'staff'], $owner);
eq(code(call('POST', 'invite-resend', ['invite' => $r['invite']], $owner)), 'too_soon', 'שליחה שוב מיד — "המתינו דקה"');
$GLOBALS['apr_time'] = time() + 61;
[$p0, $m0] = [$asked(), $mails()];
[$st, $again] = call('POST', 'invite-resend', ['invite' => $r['invite']], $owner);
eq([$st, $again['mail'], $asked() - $p0, $mails() - $m0], [200, 'sent', 0, 1], 'שליחה שוב: מייל חדש; החשבון כבר נפתח — בלי בקשה נוספת');
$again2 = ident('882', 'שוב', 'again@example.com');
eq(code(call('POST', 'invite-info', ['token' => $r['token']], $again2)), 'invite_invalid', 'הקישור הקודם מפסיק לעבוד');
eq(call('POST', 'invite-info', ['token' => $again['token']], $again2)[0], 200, 'והחדש עובד');
unset($GLOBALS['apr_time']);
// שליחה שוב להזמנה שפתיחת החשבון שלה נכשלה — מנסה שוב
platform_answers(['status' => 'granted', 'sub' => '883']);
$GLOBALS['apr_time'] = time() + 200;
[, $x] = call('POST', 'invite-resend', ['invite' => $r3['invite']], $owner);
eq([$x['provision'], $inv($r3['invite'])['invitee_sub']], ['granted', '883'], 'שליחה שוב אחרי כישלון: פתיחת החשבון מנוסה שוב');
unset($GLOBALS['apr_time']);

// ── §4.3: עד 20 הזמנות למשרד ביום ──
$carmel = ident('4', 'כרמל', 'carmel@example.com');
office_for($carmel, 'משרד ד');
for ($i = 1; $i <= 20; $i++) $last = call('POST', 'invite-create', ['email' => "u$i@example.com", 'role' => 'staff'], $carmel);
eq($last[0], 200, '§4.3 ההזמנה ה-20 עוברת');
$p0 = $asked();
eq([code(call('POST', 'invite-create', ['email' => 'u21@example.com', 'role' => 'staff'], $carmel)), $asked()], ['invite_limit', $p0], '§4.3 ה-21 נדחית — "הגעתם למגבלת ההזמנות היומית", בלי בקשה למעטפת');
$GLOBALS['apr_time'] = time() + 86401;
eq(call('POST', 'invite-create', ['email' => 'u21@example.com', 'role' => 'staff'], $carmel)[0], 200, '§4.3 למחרת — שוב אפשר');
unset($GLOBALS['apr_time']);

// ── 02-C2: ב-mail_log אין אף כתובת מייל בטקסט גלוי ──
$log = apr_rows('SELECT * FROM mail_log');
check('02-C2 יש שורות לבדוק, כולל כישלון', count($log) > 20 && in_array('failed', array_column($log, 'status'), true));
check('02-C2 mail_log: בלי @ ובלי אף כתובת, בשום עמודה', !str_contains(json_encode($log), '@') && !str_contains(json_encode($log), 'example.com'));
check('02-C2 to_hash — hash של 64 תווים, אותו נמען = אותו hash', preg_match('/^[a-f0-9]{64}$/', $log[0]['to_hash']) === 1 && apr_mail_hash('NOA@example.com ') === apr_mail_hash('noa@example.com'));
[$st, $dm] = call('GET', 'dev-mail', [], $owner);
check('02-C2 dev-mail למפתח: סוג, מצב ושגיאה — בלי כתובת', $st === 200 && count($dm['rows']) > 20 && !str_contains(json_encode($dm), '@') && array_keys($dm['rows'][0]) === ['at', 'kind', 'to', 'status', 'error']);
eq(call('GET', 'dev-mail', [], $dana)[0], 404, 'dev-mail — רק למפתח');
$audit = json_encode(apr_rows("SELECT meta FROM audit_log WHERE action LIKE 'invite.%'"));
check('יומן הפעולות של ההזמנות: בלי כתובות', !str_contains($audit, '@'));
check('שורת משימת הרקע: בלי כתובת', !str_contains(json_encode(apr_rows('SELECT payload, last_error FROM jobs')), '@'));

// ══ ביקורת סבב 1 (team/reviews/02-invites-detached.md) ══
platform_answers(['status' => 'invited', 'sub' => '900']);
$eli = ident('5', 'אלי', 'eli@example.com');
office_for($eli, 'משרד ה');

// 🟡9 שליחה שוב: מרווח של דקה גם כשהמייל נכשל, וכישלון אינו דורס חותמת של שליחה שהצליחה
[, $r] = call('POST', 'invite-create', ['email' => 'stamp@example.com', 'role' => 'staff'], $eli);
$sentAt = $inv($r['invite'])['email_sent_at'];
$GLOBALS['apr_time'] = time() + 61;
$GLOBALS['apr_mail'] = fn() => false;
[$st, $x] = call('POST', 'invite-resend', ['invite' => $r['invite']], $eli);
eq([$st, $x['mail'], $inv($r['invite'])['email_status'], $inv($r['invite'])['email_sent_at']], [200, 'failed', 'failed', $sentAt], '🟡9 שליחה שוב שנכשלה: מוצג "לא נשלח", והחותמת של השליחה שהצליחה נשארת');
eq(code(call('POST', 'invite-resend', ['invite' => $r['invite']], $eli)), 'too_soon', '🟡9 המרווח חל גם אחרי מייל שנכשל');
$GLOBALS['apr_time'] = time() + 61 + 61;
eq(call('POST', 'invite-resend', ['invite' => $r['invite']], $eli)[0], 200, 'ואחרי דקה — אפשר שוב');
unset($GLOBALS['apr_time']);
$GLOBALS['apr_mail'] = function (string $to, string $subject, string $text) { $GLOBALS['t_mail'][] = compact('to', 'subject', 'text'); return true; };

// 🟡9 op חוזר אחרי קריסה (ההזמנה נשמרה, המשלוח לא התחיל): הבקשה החוזרת משלימה — provision, מייל וקישור
$eliOffice = (int)apr_val("SELECT id FROM offices WHERE name = 'משרד ה'");
$eliId = (int)apr_val("SELECT id FROM users WHERE platform_sub = '5'");
$crashed = apr_tx(fn() => apr_invite_insert($eliOffice, 'crash@example.com', 'staff', 0, $eliId, '', 'op-crash-0001'));
[$p0, $m0] = [$asked(), $mails()];
[$st, $x] = call('POST', 'invite-create', ['email' => 'crash@example.com', 'role' => 'staff', 'op' => 'op-crash-0001'], $eli);
eq([$st, $x['invite'], $x['repeat'] ?? null, $x['provision'], $x['mail'], $asked() - $p0, $mails() - $m0, (int)apr_val("SELECT COUNT(*) FROM invitations WHERE email = 'crash@example.com'")],
    [200, $crashed['invite'], null, 'invited', 'sent', 1, 1, 1], '🟡9 op חוזר אחרי קריסה: אותה הזמנה, והמשלוח מושלם');
eq([call('POST', 'invite-info', ['token' => $x['token']], ident('900', 'קראש', 'crash@example.com'))[0], code(call('POST', 'invite-info', ['token' => $crashed['token']], ident('900', 'קראש', 'crash@example.com')))], [200, 'invite_invalid'], 'עם קישור חדש; הקודם לא עובד');
[$p0, $m0] = [$asked(), $mails()];
eq([call('POST', 'invite-create', ['email' => 'crash@example.com', 'role' => 'staff', 'op' => 'op-crash-0001'], $eli)[1]['repeat'] ?? null, $asked() - $p0, $mails() - $m0], [true, 0, 0], 'ופעם שלישית — repeat, בלי כלום');

// 🟡9 מפתח ה-dedupe: אחרי סדרת ניסיונות שמוצתה, "שליחה שוב" שנכשלת פותחת סדרה חדשה
platform_answers(null);
$GLOBALS['apr_time'] = time() + 5000;
$jobs = fn(string $status) => (int)apr_val("SELECT COUNT(*) FROM jobs WHERE kind = 'invite.provision' AND status = ? AND dedupe_key LIKE ?", [$status, 'invite.provision:' . $r3['invite'] . ':%']);
apr_exec("UPDATE invitations SET provision_status = 'failed', invitee_sub = NULL WHERE pid = ?", [$r3['invite']]);
[$f0, $p0] = [$jobs('failed'), $jobs('pending')];
[, $x] = call('POST', 'invite-resend', ['invite' => $r3['invite']], $owner);
eq([$x['provision'], $f0, $jobs('pending') - $p0], ['failed', 1, 1], '🟡9 אחרי 3 כישלונות, שליחה שוב שנכשלת יוצרת משימת רקע חדשה');
unset($GLOBALS['apr_time']);

// 🟡6 תקרת ההזמנות: גם למשתמש המזמין (בכל המשרדים שלו), וגם למערכת כולה
platform_answers(['status' => 'invited', 'sub' => '901']);
$fay = ident('6', 'פיי', 'fay@example.com');
$F1 = office_for($fay, 'משרד ו1');
for ($i = 1; $i <= 12; $i++) call('POST', 'invite-create', ['email' => "f1-$i@example.com", 'role' => 'staff'], $fay);
$F2 = office_for($fay, 'משרד ו2');   // משרד חדש — המשרד הפעיל
for ($i = 1; $i <= 8; $i++) $last = call('POST', 'invite-create', ['email' => "f2-$i@example.com", 'role' => 'staff'], $fay);
$p0 = $asked();
eq([$last[0], code(call('POST', 'invite-create', ['email' => 'f2-9@example.com', 'role' => 'staff'], $fay)), $asked() - $p0], [200, 'invite_limit', 0], '🟡6 20 הזמנות ליום למשתמש המזמין — הקמת משרד נוסף לא עוקפת');
$gil = ident('7', 'גיל', 'gil7@example.com');
office_for($gil, 'משרד ז');
$total = (int)apr_val('SELECT COUNT(*) FROM invitations WHERE created_at > ?', [apr_iso(time() - 86400)]);
eq(call('POST', 'sys-setting-set', ['name' => 'invites_per_day', 'value' => $total + 1], $owner)[0], 200, '🟡6 התקרה הכללית היא הגדרת מערכת');
eq([call('POST', 'invite-create', ['email' => 'g1@example.com', 'role' => 'staff'], $gil)[0], code(call('POST', 'invite-create', ['email' => 'g2@example.com', 'role' => 'staff'], $gil))], [200, 'invite_limit'], '🟡6 התקרה הכללית: מעבר לה — נדחה, גם למשרד ולמשתמש חדשים');
eq([code(call('POST', 'sys-setting-set', ['name' => 'invites_per_day', 'value' => true], $owner)), code(call('POST', 'sys-setting-set', ['name' => 'invites_per_day', 'value' => -1], $owner)),
    code(call('POST', 'sys-setting-set', ['name' => 'office_self_create', 'value' => 5], $owner)), apr_setting('invites_per_day')], ['setting_invalid', 'setting_invalid', 'setting_invalid', $total + 1], 'ערך מהסוג הלא נכון — נדחה');
call('POST', 'sys-setting-set', ['name' => 'invites_per_day', 'value' => 200], $owner);

done();
