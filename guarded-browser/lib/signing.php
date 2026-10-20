<?php
/**
 * חתימת הודעות של השרת (SPEC.md, סעיף 13) — Ed25519 דרך sodium.
 *
 *   הדברים ש*משחררים* מכשיר או מזיזים אותו — שחרור מינוי, ‏server_url חדש, ביטול שחרור —
 *   חייבים לבוא חתומים, אחרת מכשיר שמאבד חיבור או עובר MITM היה אפשר "לשחרר" בלי השרת.
 *   המכשיר מאמת את החתימה מול המפתח הציבורי (מוצמד בצימוד, או צרוב בבנייה) ודוחה כל השאר.
 *
 *   מקור המפתח הסודי, לפי סדר:
 *     1. data/signing.key — base64 של 64 בתים (סוד מ-GitHub שנכתב בפריסה, מוחרג מ-rsync).
 *     2. app_meta['sign_sk'] — נוצר אוטומטית בפעם הראשונה ונשמר (ה-SQLite שורד פריסות).
 *   כך זה עובד לבד מהרגע הראשון, ואפשר להחליף למפתח מנוהל בלי שינוי קוד.
 *
 *   אסימון: b64u(JSON של התביעות) . "." . b64u(חתימה). התביעות תמיד כוללות iat (זמן יצירה).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const SIGN_KEY_FILE = __DIR__ . '/../data/signing.key';

/** 64 בתים של מפתח Ed25519 סודי (כולל הציבורי בתוכו). נוצר ונשמר אם אין. */
function signingSecret(): string {
    if (is_file(SIGN_KEY_FILE)) {
        $sk = b64ud(trim((string) @file_get_contents(SIGN_KEY_FILE)));
        if (strlen($sk) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) return $sk;
    }
    $row = one("SELECT value FROM app_meta WHERE key = 'sign_sk'");
    if ($row && ($sk = b64ud((string) $row['value'])) && strlen($sk) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        return $sk;
    }
    $pair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($pair);
    upsert('app_meta', ['key' => 'sign_sk'], ['value' => b64u($sk), 'updated_at' => nowIso()]);
    return $sk;
}

/** המפתח הציבורי כ-base64url — מה שהאפליקציה מצמידה/צורבת, ומה שהמפתח רואה בקונסולה. */
function signingPublicKey(): string {
    return b64u(sodium_crypto_sign_publickey_from_secretkey(signingSecret()));
}

/** חותם תביעות ומחזיר אסימון קומפקטי. iat נוסף לבד אם אין. */
function signClaims(array $claims): string {
    $claims['iat'] ??= time();
    ksort($claims);
    $json = json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $sig = sodium_crypto_sign_detached($json, signingSecret());
    return b64u($json) . '.' . b64u($sig);
}

/** מאמת אסימון מול מפתח ציבורי (base64url). מחזיר את התביעות, או null. */
function verifyToken(string $token, ?string $pubB64u = null): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 2) return null;
    $json = b64ud($parts[0]);
    $sig = b64ud($parts[1]);
    $pub = $pubB64u === null ? sodium_crypto_sign_publickey_from_secretkey(signingSecret()) : b64ud($pubB64u);
    if ($json === '' || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return null;
    }
    if (!sodium_crypto_sign_verify_detached($sig, $json, $pub)) return null;
    $claims = json_decode($json, true);
    return is_array($claims) ? $claims : null;
}

/** אסימון שחרור לחשבון: אומר למכשיר "המינוי הסתיים — שחרר את כל המגבלות". */
function releaseToken(int $accountId): string {
    return signClaims(['typ' => 'release', 'acct' => $accountId]);
}

/** כמה זמן קוד ה-QR של "העברה בלי אינטרנט" תקף. */
const OFFLINE_TTL = 900;

/**
 * הגדרות בלי אינטרנט: ההורה מציג QR, ומכשיר הילד סורק ומפעיל — גם כשאין לו רשת (למשל אחרי שנחסמה).
 * ‏dev — הגדרות המכשיר ({מפתח: חסום?}); ‏apps — מדיניות האפליקציות (manage/posture/blocked/allowed).
 * המכשיר מקבל רק אם החתימה תקפה, acct ו-child שלו, exp עוד לא עבר, ו-iat חדש מהקוד האחרון שהפעיל
 * (כך אי אפשר להחזיר קוד ישן שצולם). כשהמכשיר חוזר לרשת — המדיניות מהשרת זהה, כי נבנתה מאותם נתונים.
 */
function offlineToken(int $accountId, int $childId, array $device, array $apps): string {
    $now = time();
    return signClaims(['typ' => 'offline', 'acct' => $accountId, 'child' => $childId,
                       'iat' => $now, 'exp' => $now + OFFLINE_TTL, 'dev' => $device, 'apps' => $apps]);
}

/** אסימון server_url חדש — מעתיק מכשירים נעולים לדומיין אחר בלי התקנה מחדש. */
function serverUrlToken(string $url): string {
    return signClaims(['typ' => 'server_url', 'url' => $url]);
}
