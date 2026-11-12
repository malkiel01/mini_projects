<?php
/**
 * סטודיו — אימות חתימה של webhook מ-Anthropic (פורמט standardwebhooks).
 *
 * כותרות: webhook-id, webhook-timestamp, webhook-signature.
 * החתימה: base64( HMAC-SHA256( מפתח, "<id>.<timestamp>.<הגוף הגולמי>" ) ),
 * כשהמפתח הוא פענוח ה-base64 של מה שאחרי "whsec_". הכותרת יכולה לשאת כמה
 * חתימות מופרדות ברווח, כל אחת "v1,<חתימה>".
 *
 * אימות ידני ולא דרך SDK: באחסון אין composer. חובה לאמת מול הגוף הגולמי —
 * JSON שפוענח ונבנה מחדש משנה בתים ושובר את החתימה.
 */

declare(strict_types=1);

const STUDIO_WEBHOOK_TOLERANCE = 300;

function studioWebhookVerify(string $secret, string $rawBody, string $id, string $timestamp, string $signatureHeader, ?int $now = null): bool {
    if ($secret === '' || $id === '' || $timestamp === '' || $signatureHeader === '') return false;
    if (!ctype_digit($timestamp)) return false;
    $now ??= time();
    if (abs($now - (int) $timestamp) > STUDIO_WEBHOOK_TOLERANCE) return false;

    $b64 = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
    $key = base64_decode($b64, true);
    if ($key === false || $key === '') return false;

    $expected = base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $rawBody, $key, true));
    foreach (preg_split('/\s+/', trim($signatureHeader)) as $part) {
        [$ver, $sig] = array_pad(explode(',', $part, 2), 2, '');
        if ($ver === 'v1' && $sig !== '' && hash_equals($expected, $sig)) return true;
    }
    return false;
}
