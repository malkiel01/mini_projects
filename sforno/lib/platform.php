<?php
/**
 * הזהות מהמעטפת (הפלטפורמה) — **הנקודה היחידה שקוראת PLATFORM_USER** (אפיון 01 §5.1, platform/docs/auth.md).
 *
 * מחוברת ("gate": true): השער כותב $_SERVER['PLATFORM_USER'] (JSON) לכל בקשת PHP. המפתח אינו HTTP_*, ולכן לא ניתן
 * לזיוף מהבקשה. אין זהות → שואלים את המעטפת אם ספורנו מחוברת (sso.php?action=config&client=sforno). התשובה נשמרת
 * 60 שניות, כישלון — 15.
 *
 * בפרוסה הזו: מנותקת או לא זמינה → כל פעולה שאינה ציבורית מחזירה 503. נכשל סגור. המצב המנותק (סאב-דומיין,
 * sso.php) — פרוסה 11, ויתווסף כאן בלי לגעת בשאר.
 *
 * כתובת המעטפת — ערך אחד ב-meta.platform_base. ברירת המחדל: התיקייה platform/ שליד ספורנו
 * ("/<השורש>/platform/") — נגזרת בזמן ריצה, לא כתובה בקוד.
 *
 * CLI ובדיקות בלבד: SF_PLATFORM קובע את המצב, SF_TEST_IDENTITY מזריק זהות.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';

const SF_CLIENT_ID = 'sforno';

/** ה-JSON של הזהות כפי שהגיע, או null. */
function sf_platform_raw(?array $server = null, ?string $sapi = null): ?string
{
    $server ??= $_SERVER;
    $raw = $server['PLATFORM_USER'] ?? null;
    if (is_string($raw) && $raw !== '') return $raw;
    if (($sapi ?? PHP_SAPI) === 'cli') {
        $t = getenv('SF_TEST_IDENTITY');
        if (is_string($t) && $t !== '') return $t;
    }
    return null;
}

/** הזהות, מנוקה: sub, name, email (קטנות או null), email_verified, platform_admin, granted. null — לא תקינה. */
function sf_platform_identity(?string $raw): ?array
{
    $id = json_decode((string)$raw, true);
    if (!is_array($id) || !is_scalar($id['sub'] ?? null) || !preg_match('/^[0-9]{1,12}$/', (string)$id['sub'])) return null;
    $email = is_string($id['email'] ?? null) && mb_strlen($id['email']) <= 200 && filter_var($id['email'], FILTER_VALIDATE_EMAIL)
        ? mb_strtolower($id['email']) : null;
    return [
        'sub' => (string)$id['sub'],
        'name' => mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)($id['name'] ?? ''))), 0, 120),
        'email' => $email,
        'email_verified' => $email !== null && ($id['email_verified'] ?? false) === true,
        'platform_admin' => ($id['platform_admin'] ?? false) === true,
        'granted' => ($id['granted'] ?? null) === true,   // לא סומכים על כך שהשער כבר חסם
    ];
}

/** connected · detached · unavailable. */
function sf_platform_mode(?string $raw, ?string $sapi = null): string
{
    if (sf_platform_identity($raw)) return 'connected';
    if (($sapi ?? PHP_SAPI) === 'cli') {
        $m = getenv('SF_PLATFORM');
        if (in_array($m, ['connected', 'detached', 'unavailable'], true)) return $m;
    }
    $cfg = sf_platform_config();
    if ($cfg === null) return 'unavailable';
    return $cfg['connected'] ? 'connected' : 'detached';
}

/** נתיב המעטפת באתר ("/…/platform/"): meta.platform_base, או התיקייה platform/ שליד ספורנו. */
function sf_platform_base(): string
{
    $v = sf_meta('platform_base');
    if (is_string($v) && preg_match('~^/[A-Za-z0-9._/\-]*/$~', $v)) return $v;
    $parent = rtrim(dirname(rtrim(sf_base_path(), '/')), '/');
    return ($parent === '' || $parent === '.' ? '' : str_replace('\\', '/', $parent)) . '/platform/';
}

function sf_platform_url(string $file, string $query = ''): string
{
    return sf_origin() . sf_platform_base() . $file . ($query !== '' ? '?' . $query : '');
}

/** {connected: bool, login: array} או null — המעטפת לא עונה. מטמון: 60 שניות, כישלון — 15. */
function sf_platform_config(bool $fresh = false): ?array
{
    $now = sf_time();
    $cached = json_decode((string)sf_meta('platform_config_cache', ''), true);
    if (!$fresh && is_array($cached) && (int)($cached['until'] ?? 0) > $now) return $cached['config'];
    $res = sf_http_get(sf_platform_url('sso.php', 'action=config&client=' . SF_CLIENT_ID), 3);
    $cfg = $res && $res[0] === 200 ? json_decode($res[1], true) : null;
    $cfg = is_array($cfg) && array_key_exists('connected', $cfg)
        ? ['connected' => $cfg['connected'] === true, 'login' => (array)($cfg['login'] ?? [])] : null;
    sf_meta_set('platform_config_cache', (string)json_encode(['until' => $now + ($cfg ? 60 : 15), 'config' => $cfg]));
    return $cfg;
}
