<?php
/**
 * עובד — הכניסה בלי חשבון פלטפורמה (האפיון: E1; שלב 2). הכתובות האלה ב-"public" של app.json.
 *
 * הקישור האישי (e/#t=…) מפעיל את המכשיר: העובד בוחר קוד בן 4 ספרות (ישמש גם בעמדה), והדפדפן מקבל
 * עוגייה `tc_session` ל-180 יום. בעוגייה אסימון לכל חברוּת (עובד בכמה חברות — כמה אסימונים), ובמסד
 * נשמר רק ה-hash. אין כאן PLATFORM_USER: הזהות היא האסימון בלבד.
 */

declare(strict_types=1);

require_once __DIR__ . '/employees.php';

const SESSION_COOKIE = 'tc_session';
const SESSION_DAYS = 180;
const SESSION_MAX = 8;

function inviteByToken(string $token): array {
    if (!preg_match('/^[A-Za-z0-9_-]{30,60}$/', $token)) throw new AppError('הקישור לא תקין', 404);
    $st = db()->prepare("SELECT i.*, m.company_id, m.status, p.name, c.name AS company_name FROM invites i
        JOIN members m ON m.id = i.member_id JOIN people p ON p.id = m.person_id JOIN companies c ON c.id = m.company_id
        WHERE i.token_hash = ?");
    $st->execute([tokenHash($token)]);
    $i = $st->fetch();
    if (!$i || $i['revoked_at'] !== null || $i['status'] !== 'active') throw new AppError('הקישור בוטל. בקשו מהמנהל קישור חדש.', 410);
    if ($i['used_at'] !== null) throw new AppError('הקישור כבר נוצל. בקשו מהמנהל קישור חדש.', 410);
    if ($i['expires_at'] <= nowIso()) throw new AppError('תוקף הקישור פג. בקשו מהמנהל קישור חדש.', 410);
    return $i;
}

function empInviteInfo(array $in): array {
    $i = inviteByToken(str($in, 'token', 80));
    return ['name' => $i['name'], 'company' => $i['company_name']];
}

/** קוד בן 4 ספרות — לא 0000/1111, לא 1234/4321. */
function validPin(string $pin): string {
    if (!preg_match('/^\d{4}$/', $pin)) throw new AppError('הקוד — 4 ספרות');
    $d = array_map('intval', str_split($pin));
    $same = count(array_unique($d)) === 1;
    $seq = ($d[1] - $d[0]) === ($d[2] - $d[1]) && ($d[2] - $d[1]) === ($d[3] - $d[2]) && abs($d[1] - $d[0]) === 1;
    if ($same || $seq) throw new AppError('קוד קל מדי לניחוש. בחרו קוד אחר.');
    return $pin;
}

/** ההפעלה: הקוד נשמר (hash), הקישור נוצל, ונפתח מכשיר. מחזיר את האסימון לעוגייה. */
function empActivate(array $in): string {
    $i = inviteByToken(str($in, 'token', 80));
    $pin = validPin(str($in, 'pin', 4));
    $pdo = db();
    $pdo->beginTransaction();
    $now = nowIso();
    // ניצול אטומי: שני דפדפנים עם אותו קישור — רק אחד מצליח.
    $st = $pdo->prepare('UPDATE invites SET used_at = ? WHERE id = ? AND used_at IS NULL AND revoked_at IS NULL');
    $st->execute([$now, $i['id']]);
    if ($st->rowCount() !== 1) throw new AppError('הקישור כבר נוצל. בקשו מהמנהל קישור חדש.', 410);
    $pdo->prepare('UPDATE members SET pin_hash = ? WHERE id = ?')->execute([password_hash($pin, PASSWORD_DEFAULT), $i['member_id']]);
    $token = newToken();
    $pdo->prepare('INSERT INTO devices (member_id, token_hash, user_agent, created_at, last_seen) VALUES (?,?,?,?,?)')
        ->execute([$i['member_id'], tokenHash($token), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), $now, $now]);
    audit((int) $i['company_id'], null, 'employee.activate', 'members:' . $i['member_id'], null, null);
    $pdo->commit();
    return $token;
}

/** האסימונים שבעוגייה. */
function sessionTokens(): array {
    $raw = (string) ($_COOKIE[SESSION_COOKIE] ?? '');
    return array_values(array_filter(explode('.', $raw), fn($t) => preg_match('/^[A-Za-z0-9_-]{30,60}$/', $t)));
}

/** החברויות הפעילות שהמכשיר הזה מחובר אליהן, עם האסימונים התקפים בלבד. */
function sessionMembers(): array {
    $out = [];
    foreach (sessionTokens() as $t) {
        $st = db()->prepare("SELECT d.id AS device_id, m.id, m.company_id, m.site_id, m.employee_no, p.name, c.name AS company_name, c.tz, s.name AS site_name
            FROM devices d JOIN members m ON m.id = d.member_id JOIN people p ON p.id = m.person_id
            JOIN companies c ON c.id = m.company_id LEFT JOIN sites s ON s.id = m.site_id
            WHERE d.token_hash = ? AND d.revoked_at IS NULL AND m.status = 'active'");
        $st->execute([tokenHash($t)]);
        if ($r = $st->fetch()) { $r['token'] = $t; $out[] = $r; }
    }
    return $out;
}

/** כותב את העוגייה מחדש: האסימונים התקפים, והחדש (אם יש) בראש. */
function writeSession(array $tokens): void {
    $tokens = array_slice(array_values(array_unique($tokens)), 0, SESSION_MAX);
    // גם בבקשה הזו: empState שאחרי ההפעלה קורא את $_COOKIE, ובלי זה העובד היה רואה "לא מחובר".
    $_COOKIE[SESSION_COOKIE] = implode('.', $tokens);
    if (PHP_SAPI === 'cli') return;
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
    setcookie(SESSION_COOKIE, implode('.', $tokens), [
        'expires'  => $tokens ? time() + SESSION_DAYS * 86400 : time() - 3600,
        'path'     => $path,
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function empActivateAndLogin(array $in): array {
    $token = empActivate($in);
    $valid = array_column(sessionMembers(), 'token');
    writeSession(array_merge([$token], $valid));
    return empState();
}

/** מה שהעובד רואה: מי הוא בכל חברה. אסימונים שבוטלו יוצאים מהעוגייה. */
function empState(): array {
    $members = sessionMembers();
    if (count($members) !== count(sessionTokens())) writeSession(array_column($members, 'token'));
    if (!$members) throw new AppError('המכשיר הזה לא מחובר. פתחו את הקישור שהמנהל שלח.', 401);
    $ids = array_column($members, 'device_id');
    db()->prepare('UPDATE devices SET last_seen = ? WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
        ->execute(array_merge([nowIso()], $ids));
    return ['memberships' => array_map(function ($m) {
        $t = termsAt((int) $m['id'], todayIn($m['tz']));
        return ['id' => (int) $m['id'], 'name' => $m['name'], 'company' => $m['company_name'], 'site' => $m['site_name'],
                'employee_no' => $m['employee_no'], 'terms' => termsOut($t)];
    }, $members)];
}
