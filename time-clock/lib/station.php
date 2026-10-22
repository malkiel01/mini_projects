<?php
/**
 * עמדה בעסק (האפיון: S1, S2; שלב 4).
 *
 * הצמדה: המנהל יוצר עמדה בהגדרות ומקבל קוד בן 6 ספרות (10 דקות). בטאבלט פותחים את s/ ומקלידים
 * אותו, והמכשיר מקבל עוגייה `tc_station` לשנה. ביטול עמדה — מיד.
 *
 * דיווח: העובד בוחר את שמו מרשימת עובדי הסניף, מקיש את הקוד האישי ובוחר כניסה או יציאה.
 * 5 קודים שגויים לאותו עובד — המתנה של דקה לעובד הזה. בעמדה מוצג רק השם.
 *
 * הכתובות האלה ב-"public" של app.json: אין PLATFORM_USER, הזהות היא העוגייה של העמדה.
 */

declare(strict_types=1);

require_once __DIR__ . '/hours.php';

const STATION_COOKIE = 'tc_station';
const PAIR_MINUTES = 10;
const PIN_FAILS = 5;
const PIN_LOCK_SECONDS = 60;
const PAIR_FAILS = 10;            // קודי הצמדה שגויים מאותה כתובת בתוך רבע שעה
const PAIR_WINDOW_SECONDS = 900;

/* ===== ניסיונות שגויים ===== */

function throttleCheck(string $key, string $msg): void {
    $st = db()->prepare('SELECT locked_until FROM throttle WHERE k = ?');
    $st->execute([$key]);
    $until = $st->fetchColumn();
    if ($until && $until > nowIso()) throw new AppError($msg, 429);
}

/** ניסיון שגוי. בהגעה ל-$max בתוך החלון — נעילה ל-$lockSeconds. */
function throttleFail(string $key, int $max, int $windowSeconds, int $lockSeconds): bool {
    $pdo = db();
    $now = nowIso();
    $st = $pdo->prepare('SELECT * FROM throttle WHERE k = ?');
    $st->execute([$key]);
    $r = $st->fetch();
    if (!$r || utc($now)->getTimestamp() - utc($r['first_at'])->getTimestamp() > $windowSeconds
        || ($r['locked_until'] && $r['locked_until'] <= $now)) {
        $pdo->prepare('INSERT INTO throttle (k, fails, first_at, locked_until) VALUES (?,1,?,NULL)
            ON CONFLICT (k) DO UPDATE SET fails = 1, first_at = excluded.first_at, locked_until = NULL')->execute([$key, $now]);
        return false;
    }
    $fails = (int) $r['fails'] + 1;
    $lock = $fails >= $max ? gmdate('Y-m-d\TH:i:s\Z', time() + $lockSeconds) : null;
    $pdo->prepare('UPDATE throttle SET fails = ?, locked_until = ? WHERE k = ?')->execute([$fails, $lock, $key]);
    return $lock !== null;
}

function throttleClear(string $key): void {
    db()->prepare('DELETE FROM throttle WHERE k = ?')->execute([$key]);
}

/* ===== ניהול העמדות (מאחורי השער) ===== */

function stationOut(array $s): array {
    return ['id' => (int) $s['id'], 'name' => $s['name'], 'site_id' => $s['site_id'] === null ? null : (int) $s['site_id'],
            'paired' => $s['token_hash'] !== null, 'pair_expires' => $s['token_hash'] === null ? $s['pair_expires'] : null,
            'last_seen' => $s['last_seen'], 'created_at' => $s['created_at']];
}

function stationList(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    $me = requireMember($person, $companyId, ['owner', 'manager', 'branch_manager']);
    $site = $me['role'] === 'branch_manager' ? (int) $me['site_id'] : null;
    $st = db()->prepare('SELECT * FROM stations WHERE company_id = ? AND revoked_at IS NULL' . ($site !== null ? ' AND site_id = ?' : '') . ' ORDER BY name');
    $st->execute($site !== null ? [$companyId, $site] : [$companyId]);
    return ['stations' => array_map('stationOut', $st->fetchAll()), 'site_only' => $site];
}

/** קוד הצמדה חדש — לעמדה חדשה, או מחדש לעמדה קיימת (טאבלט שהוחלף). מבטל את המכשיר הקודם. */
function stationCreate(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    $me = requireMember($person, $companyId, ['owner', 'manager', 'branch_manager']);
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $exp = gmdate('Y-m-d\TH:i:s\Z', time() + PAIR_MINUTES * 60);
    $id = intIn($in, 'id');
    if ($id) {
        $s = stationRow($companyId, $id, $me);
        db()->prepare('UPDATE stations SET token_hash = NULL, pair_hash = ?, pair_expires = ?, paired_at = NULL WHERE id = ?')
            ->execute([tokenHash($code), $exp, $id]);
        audit($companyId, (int) $person['id'], 'station.repair', 'stations:' . $id, null, null);
    } else {
        $name = str($in, 'name', 60);
        if ($name === '') throw new AppError('חסר שם לעמדה');
        $siteId = $me['role'] === 'branch_manager' ? (int) $me['site_id'] : (intIn($in, 'site_id') ?: null);
        if ($siteId !== null) siteOf($companyId, $siteId);
        db()->prepare('INSERT INTO stations (company_id, site_id, name, pair_hash, pair_expires, created_by, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$companyId, $siteId, $name, tokenHash($code), $exp, $person['id'], nowIso()]);
        $id = (int) db()->lastInsertId();
        audit($companyId, (int) $person['id'], 'station.create', 'stations:' . $id, null, ['name' => $name, 'site_id' => $siteId]);
    }
    return stationList($person, $in) + ['code' => $code, 'station_id' => $id, 'expires' => $exp];
}

function stationRow(int $companyId, int $id, array $me): array {
    $st = db()->prepare('SELECT * FROM stations WHERE id = ? AND company_id = ? AND revoked_at IS NULL');
    $st->execute([$id, $companyId]);
    $s = $st->fetch();
    if (!$s || ($me['role'] === 'branch_manager' && (int) $s['site_id'] !== (int) $me['site_id'])) throw new AppError('העמדה לא נמצאה', 404);
    return $s;
}

function stationRevoke(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    $me = requireMember($person, $companyId, ['owner', 'manager', 'branch_manager']);
    $s = stationRow($companyId, intIn($in, 'id'), $me);
    db()->prepare('UPDATE stations SET revoked_at = ?, token_hash = NULL, pair_hash = NULL WHERE id = ?')->execute([nowIso(), $s['id']]);
    audit($companyId, (int) $person['id'], 'station.revoke', 'stations:' . $s['id'], ['name' => $s['name']], null);
    return stationList($person, $in);
}

/* ===== הטאבלט (ציבורי) ===== */

function clientIp(): string {
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

/** הצמדה: קוד בן 6 ספרות ← אסימון לעוגייה. 10 קודים שגויים מאותה כתובת ברבע שעה — נעילה. */
function stationPair(array $in): string {
    $key = 'pair:' . clientIp();
    throttleCheck($key, 'יותר מדי ניסיונות. נסו שוב בעוד רבע שעה.');
    $code = preg_replace('/\D+/', '', str($in, 'code', 12));
    $st = db()->prepare('SELECT * FROM stations WHERE pair_hash = ? AND revoked_at IS NULL AND token_hash IS NULL');
    $st->execute([tokenHash($code)]);
    $s = strlen($code) === 6 ? $st->fetch() : false;
    if (!$s || $s['pair_expires'] <= nowIso()) {
        throttleFail($key, PAIR_FAILS, PAIR_WINDOW_SECONDS, PAIR_WINDOW_SECONDS);
        throw new AppError($s ? 'תוקף הקוד פג. צרו קוד חדש בהגדרות.' : 'הקוד שגוי');
    }
    throttleClear($key);
    $token = newToken();
    db()->prepare('UPDATE stations SET token_hash = ?, pair_hash = NULL, pair_expires = NULL, paired_at = ?, last_seen = ? WHERE id = ?')
        ->execute([tokenHash($token), nowIso(), nowIso(), $s['id']]);
    audit((int) $s['company_id'], null, 'station.pair', 'stations:' . $s['id'], null, null);
    return $token;
}

function writeStationCookie(string $token): void {
    $_COOKIE[STATION_COOKIE] = $token;
    if (PHP_SAPI === 'cli') return;
    setcookie(STATION_COOKIE, $token, [
        'expires'  => $token === '' ? time() - 3600 : time() + 365 * 86400,
        'path'     => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** העמדה של המכשיר הזה — או 401 (לא מוצמד, או שבוטל). */
function currentStation(): array {
    $t = (string) ($_COOKIE[STATION_COOKIE] ?? '');
    if (preg_match('/^[A-Za-z0-9_-]{30,60}$/', $t)) {
        $st = db()->prepare('SELECT s.*, c.name AS company_name, c.tz, si.name AS site_name FROM stations s
            JOIN companies c ON c.id = s.company_id LEFT JOIN sites si ON si.id = s.site_id
            WHERE s.token_hash = ? AND s.revoked_at IS NULL');
        $st->execute([tokenHash($t)]);
        if ($s = $st->fetch()) {
            db()->prepare('UPDATE stations SET last_seen = ? WHERE id = ?')->execute([nowIso(), $s['id']]);
            return $s;
        }
    }
    throw new AppError('המכשיר אינו מוצמד כעמדה', 401);
}

/** עובדי העמדה: פעילים, בסניף שלה (עמדה בלי סניף — כל החברה). שם בלבד. */
function stationEmployees(array $s): array {
    $sql = "SELECT m.id, p.name, m.pin_hash IS NOT NULL AS has_pin FROM members m JOIN people p ON p.id = m.person_id
            WHERE m.company_id = ? AND m.role = 'employee' AND m.status = 'active'" . ($s['site_id'] !== null ? ' AND m.site_id = ?' : '') . ' ORDER BY p.name';
    $st = db()->prepare($sql);
    $st->execute($s['site_id'] !== null ? [$s['company_id'], $s['site_id']] : [$s['company_id']]);
    return array_map(fn($e) => ['id' => (int) $e['id'], 'name' => $e['name'], 'has_pin' => (bool) $e['has_pin']], $st->fetchAll());
}

function stationState(): array {
    $s = currentStation();
    return ['station' => ['name' => $s['name'], 'company' => $s['company_name'], 'site' => $s['site_name']],
            'employees' => stationEmployees($s)];
}

/** בדיקת הקוד של עובד בעמדה הזו. מחזיר את החברוּת. */
function stationVerify(array $s, array $in): array {
    $id = intIn($in, 'member_id');
    $emp = array_values(array_filter(stationEmployees($s), fn($e) => $e['id'] === $id))[0] ?? null;
    if (!$emp) throw new AppError('העובד לא נמצא בעמדה הזו', 404);
    $key = 'pin:' . $id;
    throttleCheck($key, 'יותר מדי ניסיונות. נסו שוב בעוד דקה.');
    $st = db()->prepare('SELECT pin_hash FROM members WHERE id = ?');
    $st->execute([$id]);
    $hash = $st->fetchColumn();
    if (!$hash) throw new AppError('עוד אין לך קוד. פתחו קודם את הקישור מהמנהל.');
    if (!password_verify(str($in, 'pin', 4), (string) $hash)) {
        $locked = throttleFail($key, PIN_FAILS, 300, PIN_LOCK_SECONDS);
        throw new AppError($locked ? 'יותר מדי ניסיונות. נסו שוב בעוד דקה.' : 'הקוד שגוי', $locked ? 429 : 400);
    }
    throttleClear($key);
    return $emp;
}

/** שלב 1: מי אני ומה הצפוי. */
function stationCheck(array $in): array {
    $s = currentStation();
    $emp = stationVerify($s, $in);
    $now = nowStatus($emp['id'], $s['tz']);
    return ['name' => $emp['name'], 'next' => $now['next'], 'open_since' => $now['open_since']];
}

/** שלב 2: הדיווח (הקוד נבדק שוב — אין "אישור" שנשמר בין הבקשות). */
function stationPunch(array $in): array {
    $s = currentStation();
    $emp = stationVerify($s, $in);
    $p = recordPunch($emp['id'], str($in, 'kind', 3), 'station', null, (int) $s['id']);
    return ['name' => $emp['name'], 'punch' => $p];
}
