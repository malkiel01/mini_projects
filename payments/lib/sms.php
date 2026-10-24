<?php
/**
 * מסרונים — ההודעות שהחוק מחייב (21 יום לפני סוף מנוי לתקופה קצובה / סוף מבצע, §13א). ספק לכל מנוי (tenant),
 * הקודים מוצפנים. שני ספקים, לפי התיעוד הרשמי שלהם:
 *  - אינפורו (‏InforUMobile): ‏POST capi.inforu.co.il/api/v2/SMS/SendSms, ‏Basic (שם משתמש:טוקן),
 *    ‏{Data:{Message, Recipients:[{Phone}], Settings:{Sender}}} → ‏StatusId 1 = הצלחה.
 *  - טוויליו: ‏POST api.twilio.com/2010-04-01/Accounts/{Sid}/Messages.json, ‏Basic (‏Sid:Token), טופס ‏To, From, Body.
 */

declare(strict_types=1);

require_once __DIR__ . '/clients.php';

const SMS_PROVIDERS = [
    'inforu' => ['name' => 'אינפורו', 'fields' => ['username' => ['שם משתמש', false], 'token' => ['טוקן ממשק', true], 'sender' => ['שם השולח — עד 11 תווים באנגלית', false]]],
    'twilio' => ['name' => 'טוויליו', 'fields' => ['account_sid' => ['מזהה חשבון', false], 'auth_token' => ['טוקן', true], 'from' => ['השולח — מספר או שם מאושר', false]]],
];

function sms_config(string $tenantId): ?array
{
    $raw = setting('sms:' . $tenantId);
    $j = $raw ? json_decode(unseal($raw), true) : null;
    return is_array($j) && isset(SMS_PROVIDERS[$j['provider'] ?? '']) ? $j : null;
}

/** מה מוצג במסך: הספק, והשדות — גלויים מקוצרים, סודיים רק "שמור". */
function sms_public(string $tenantId): array
{
    $c = sms_config($tenantId);
    if (!$c) return ['provider' => '', 'fields' => [], 'providers' => array_map(fn($p) => ['name' => $p['name'], 'fields' => array_map(fn($f) => ['label' => $f[0], 'secret' => $f[1]], $p['fields'])], SMS_PROVIDERS)];
    $fields = [];
    foreach (SMS_PROVIDERS[$c['provider']]['fields'] as $k => [, $secret]) {
        $v = (string)($c[$k] ?? '');
        $fields[$k] = $secret ? $v !== '' : ($k === 'sender' || $k === 'from' ? $v : ($v !== '' ? '…' . substr($v, -4) : ''));
    }
    return ['provider' => $c['provider'], 'fields' => $fields, 'checked_at' => $c['checked_at'] ?? null, 'check_error' => $c['check_error'] ?? '',
        'providers' => array_map(fn($p) => ['name' => $p['name'], 'fields' => array_map(fn($f) => ['label' => $f[0], 'secret' => $f[1]], $p['fields'])], SMS_PROVIDERS)];
}

function sms_save(array $t, array $in): array
{
    $provider = (string)($in['provider'] ?? '');
    if ($provider === '') { // כיבוי
        db_run('DELETE FROM setting WHERE key = ?', ['sms:' . $t['id']]);
        audit('admin', $t['id'], 'sms.removed', 'sms', $t['id'], [], $t['id']);
        return sms_public($t['id']);
    }
    if (!isset(SMS_PROVIDERS[$provider])) throw new ApiError('ספק מסרונים לא מוכר', 'bad_provider');
    $old = sms_config($t['id']);
    $c = $old && $old['provider'] === $provider ? $old : ['provider' => $provider];
    foreach (SMS_PROVIDERS[$provider]['fields'] as $k => [$label]) {
        $v = trim((string)($in[$k] ?? ''));
        if ($v !== '') $c[$k] = mb_substr($v, 0, 200);
        if (empty($c[$k])) throw new ApiError("חסר: $label", 'missing_credentials');
    }
    $sender = (string)($c['sender'] ?? $c['from']);
    if (!preg_match('/^(\+?\d{3,14}|[A-Za-z0-9*][A-Za-z0-9]{0,10})$/', $sender)) throw new ApiError('השולח: עד 11 אותיות/ספרות באנגלית בלי רווחים, או מספר טלפון', 'bad_sender');
    unset($c['checked_at'], $c['check_error']);
    set_setting('sms:' . $t['id'], seal(json_encode($c)));
    audit('admin', $t['id'], 'sms.saved', 'sms', $t['id'], ['provider' => $provider], $t['id']);
    // הודעות שלא יצאו כי לא היה ספק (בשבוע האחרון) — חוזרות לתור
    db_run("UPDATE notification SET status = 'pending' WHERE channel = 'sms' AND status = 'no_provider' AND created_at > ?
        AND account IN (SELECT a.id FROM account a JOIN merchant m ON m.id = a.merchant WHERE m.tenant = ?)", [now() - 7 * 86400, $t['id']]);
    return sms_public($t['id']);
}

/** מספר ישראלי / בינלאומי: מקומי (05…) לאינפורו, ‏E.164 (‏+9725…) לטוויליו. null — לא מספר. */
function sms_phone(string $raw, bool $e164): ?string
{
    $d = preg_replace('/[^\d+]/', '', $raw);
    if (str_starts_with($d, '+')) $intl = substr($d, 1);
    elseif (str_starts_with($d, '00')) $intl = substr($d, 2);
    elseif (str_starts_with($d, '0')) $intl = '972' . substr($d, 1);
    else $intl = $d;
    if (!preg_match('/^\d{9,15}$/', $intl)) return null;
    if ($e164) return '+' . $intl;
    return str_starts_with($intl, '972') ? '0' . substr($intl, 3) : $intl;
}

/** שליחה. מחזיר ‏[ok, error]. */
function sms_send(array $c, string $to, string $body): array
{
    $body = mb_substr($body, 0, 670); // עד 10 מקטעים בעברית
    if ($c['provider'] === 'inforu') {
        $phone = sms_phone($to, false);
        if (!$phone) return [false, 'מספר טלפון לא תקין'];
        $res = http_request('https://capi.inforu.co.il/api/v2/SMS/SendSms', json_encode(['Data' => [
            'Message' => $body, 'Recipients' => [['Phone' => $phone]], 'Settings' => ['Sender' => (string)$c['sender']],
        ]], JSON_UNESCAPED_UNICODE), ['Content-Type: application/json; charset=utf-8', 'Authorization: Basic ' . base64_encode($c['username'] . ':' . $c['token'])], 20);
        $j = json_decode($res['body'], true);
        if ((int)($j['StatusId'] ?? 0) === 1) return [true, ''];
        return [false, trim((string)($j['StatusDescription'] ?? '') . ' ' . (string)($j['DetailedDescription'] ?? '')) ?: 'קוד ' . $res['status']];
    }
    $phone = sms_phone($to, true);
    if (!$phone) return [false, 'מספר טלפון לא תקין'];
    $res = http_request('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode((string)$c['account_sid']) . '/Messages.json',
        http_build_query(['To' => $phone, 'From' => (string)$c['from'], 'Body' => $body]),
        ['Content-Type: application/x-www-form-urlencoded', 'Authorization: Basic ' . base64_encode($c['account_sid'] . ':' . $c['auth_token'])], 20);
    $j = json_decode($res['body'], true);
    if ($res['status'] >= 200 && $res['status'] < 300 && !empty($j['sid'])) return [true, ''];
    return [false, (string)($j['message'] ?? 'קוד ' . $res['status'])];
}

/** הודעת בדיקה מהמסך — וגם שומרת אם החיבור עובד. */
function sms_test(array $t, string $to): array
{
    $c = sms_config($t['id']);
    if (!$c) throw new ApiError('עוד לא הוגדר ספק מסרונים', 'no_provider', 409);
    [$ok, $err] = sms_send($c, $to, 'בדיקה מתוסף התשלומים — המסרונים עובדים.');
    $c['checked_at'] = now();
    $c['check_error'] = $ok ? '' : $err;
    set_setting('sms:' . $t['id'], seal(json_encode($c)));
    audit('admin', $t['id'], 'sms.tested', 'sms', $t['id'], ['ok' => $ok], $t['id']);
    return sms_public($t['id']);
}
