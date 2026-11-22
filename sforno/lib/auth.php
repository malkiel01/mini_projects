<?php
/**
 * מנהלים ו-session (אפיון 01 §5.2). הזהות מגיעה מהמעטפת (lib/platform.php); החשבונות כאן הם של ספורנו:
 *
 *   1. יש מנהל עם ה-sub → הוא.
 *   2. אין, והמייל אומת במעטפת → מנהל באותו מייל שעוד לא קושר מתקשר עכשיו (staff.link).
 *   3. אחרת → מנהל חדש, פעיל, מיד (staff.create). ההרשאה ל-sforno במעטפת היא האישור — אין אישור כפול.
 *   is_developer = platform_admin שבזהות, לשני הכיוונים, בכל כניסה (staff.developer_on / _off, המבצע: המערכת).
 *
 * העוגייה sf_staff: אסימון אקראי (במסד רק ה-hash), HttpOnly, Secure, SameSite=Lax, בנתיב של ספורנו. 30 יום מהשימוש
 * האחרון. **לא session של PHP.** session ששייך ל-sub אחר — נמחק ונפתח חדש. בקשה בלי זהות — ה-session נמחק.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/platform.php';

const SF_SESSION_TTL = 30 * 86400;

/** המנהל לפי הזהות — מקושר, מתאחד לפי מייל מאומת, או נפתח. */
function sf_staff_for(array $id): array
{
    try {
        return sf_tx(fn() => sf_staff_resolve($id));
    } catch (PDOException $e) {
        // שתי בקשות ראשונות במקביל מאותו אדם: platform_sub ייחודי — המפסיד קורא מחדש
        if (!str_contains($e->getMessage(), 'UNIQUE') && !str_contains($e->getMessage(), 'Duplicate')) throw $e;
        return sf_tx(fn() => sf_staff_resolve($id));
    }
}

function sf_staff_resolve(array $id): array
{
    $now = sf_now();
    $s = sf_row('SELECT * FROM staff WHERE platform_sub = ?', [$id['sub']]);
    if (!$s && $id['email'] !== null && $id['email_verified']) {
        $s = sf_row('SELECT * FROM staff WHERE email = ? AND platform_sub IS NULL', [$id['email']]);
        if ($s) {
            sf_exec('UPDATE staff SET platform_sub = ?, email_verified = 1, updated_at = ? WHERE id = ?', [$id['sub'], $now, $s['id']]);
            sf_audit('staff.link', 'staff', $s['pid'], ['by' => 'email'], actor: null);
            $s = sf_row('SELECT * FROM staff WHERE id = ?', [$s['id']]);
        }
    }
    if (!$s) {
        // המייל נשמר רק אם אינו תפוס בידי מנהל אחר (ייחודי); אחרת — בלי מייל, עד שיתברר
        $email = $id['email'] !== null && !sf_val('SELECT 1 FROM staff WHERE email = ?', [$id['email']]) ? $id['email'] : null;
        $sid = sf_insert('staff', ['pid' => sf_pid(), 'platform_sub' => $id['sub'], 'email' => $email,
            'email_verified' => $email !== null && $id['email_verified'] ? 1 : 0, 'name' => $id['name'] !== '' ? $id['name'] : 'מנהל',
            'is_developer' => 0, 'status' => 'active', 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $s = sf_row('SELECT * FROM staff WHERE id = ?', [$sid]);
        sf_audit('staff.create', 'staff', $s['pid'], ['via' => 'platform'], actor: null);
    }

    $set = [];
    if ($id['name'] !== '' && $id['name'] !== $s['name']) $set['name'] = $id['name'];
    if ($id['email'] !== null && $id['email'] !== $s['email'] && !sf_val('SELECT 1 FROM staff WHERE email = ? AND id <> ?', [$id['email'], $s['id']])) {
        $set['email'] = $id['email'];
    }
    $verified = ($set['email'] ?? $s['email']) === $id['email'] && $id['email_verified'] ? 1 : 0;
    if ($verified !== (int)$s['email_verified']) $set['email_verified'] = $verified;
    if ((int)$s['is_developer'] !== (int)$id['platform_admin']) {
        $set['is_developer'] = (int)$id['platform_admin'];
        sf_audit($id['platform_admin'] ? 'staff.developer_on' : 'staff.developer_off', 'staff', $s['pid'], [], actor: null);
    }
    // לא כותבים בכל בקשה: רק כשמשהו השתנה, או פעם ב-10 דקות (נראה לאחרונה)
    if ($set || $s['last_seen_at'] === null || strtotime($s['last_seen_at']) < sf_time() - 600) {
        $set['last_seen_at'] = $now;
        if (count($set) > 1) $set['updated_at'] = $now;
        sf_exec('UPDATE staff SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ?', [...array_values($set), $s['id']]);
        $s = sf_row('SELECT * FROM staff WHERE id = ?', [$s['id']]);
    }
    return $s;
}

/** ה-session של הבקשה. מחזיר את השורה; כשצריך לכתוב עוגייה — sf_set_cookie נקרא מכאן. */
function sf_session_for(array $staff, string $cookie, string $ua = ''): array
{
    $now = sf_time();
    if (preg_match('/^[a-f0-9]{64}$/', $cookie) === 1) {
        $s = sf_row('SELECT * FROM staff_sessions WHERE token_hash = ?', [hash('sha256', $cookie)]);
        if ($s && $s['platform_sub'] === $staff['platform_sub'] && (int)$s['staff_id'] === (int)$staff['id'] && $s['expires_at'] > sf_iso($now)) {
            if (strtotime($s['last_used_at']) < $now - 3600) {
                sf_exec('UPDATE staff_sessions SET last_used_at = ?, expires_at = ? WHERE id = ?', [sf_iso($now), sf_iso($now + SF_SESSION_TTL), $s['id']]);
                sf_set_cookie($cookie, SF_SESSION_TTL);
            }
            return $s;
        }
        // פג, או של sub אחר (מישהו אחר נכנס למעטפת באותו דפדפן) — נמחק
        if ($s) sf_exec('DELETE FROM staff_sessions WHERE id = ?', [$s['id']]);
    }
    $token = bin2hex(random_bytes(32));
    $id = sf_insert('staff_sessions', ['staff_id' => $staff['id'], 'token_hash' => hash('sha256', $token), 'csrf' => bin2hex(random_bytes(32)),
        'platform_sub' => (string)$staff['platform_sub'], 'ua' => mb_substr($ua, 0, 300),
        'created_at' => sf_iso($now), 'last_used_at' => sf_iso($now), 'expires_at' => sf_iso($now + SF_SESSION_TTL)]);
    sf_set_cookie($token, SF_SESSION_TTL);
    return sf_row('SELECT * FROM staff_sessions WHERE id = ?', [$id]);
}

/** מוחק את ה-session של העוגייה (יציאה, או בקשה בלי זהות). */
function sf_session_drop(string $cookie): void
{
    if (preg_match('/^[a-f0-9]{64}$/', $cookie) !== 1) return;
    try {
        sf_exec('DELETE FROM staff_sessions WHERE token_hash = ?', [hash('sha256', $cookie)]);
    } catch (SfError) {
        // המסד לא זמין — העוגייה נמחקת בכל זאת
    }
    sf_set_cookie('', 0);
}

/**
 * המנהל של הבקשה, לפי §5.2. זורק 401 / 403 / 503. מחזיר ['staff' => שורה, 'session' => שורה].
 * $env: platform_user, cookie, ua.
 */
function sf_authenticate(array $env): array
{
    $raw = $env['platform_user'] ?? null;
    $cookie = (string)($env['cookie'] ?? '');
    $id = sf_platform_identity($raw);
    if ($id === null) {
        // בלי זהות מהשער: יציאה מהפלטפורמה = יציאה מכאן
        sf_session_drop($cookie);
        if (sf_platform_mode($raw) !== 'connected') sf_fail('login_unavailable');
        sf_fail('login_required');
    }
    if (!$id['granted']) {
        sf_session_drop($cookie);
        sf_fail('login_required');
    }
    $staff = sf_staff_for($id);
    if ($staff['status'] !== 'active') {
        sf_session_drop($cookie);
        sf_fail('suspended');
    }
    $session = sf_session_for($staff, $cookie, (string)($env['ua'] ?? ''));
    sf_actor((int)$staff['id'], true);
    return ['staff' => $staff, 'session' => $session];
}

function sf_staff_public(array $s, ?int $me = null): array
{
    return ['pid' => $s['pid'], 'name' => $s['name'], 'email' => $s['email'], 'status' => $s['status'],
        'is_developer' => (bool)$s['is_developer'], 'last_seen_at' => $s['last_seen_at'], 'me' => $me !== null && (int)$s['id'] === $me];
}
