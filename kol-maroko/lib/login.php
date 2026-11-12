<?php
/**
 * P1 — הכניסה. **המקום היחיד בקוד שמכיר את הפלטפורמה** (sso.md §0, "מחוברת").
 *
 * בכל בקשה: יש $_SERVER['PLATFORM_USER'] ← המשתמש של האפליקציה לפי sub (או לפי מייל מאומת של מי שהוזמן ועוד לא
 * נכנס — ואז מקשרים); אין — **נפתח מיד** (active, בלי תפקיד). ה-session של האפליקציה (עוגייה kolm_sess) שייך ל-sub
 * אחר ← מוחלף. אין PLATFORM_USER ← ה-session נמחק (יציאה מהפלטפורמה = יציאה מכאן). granted:false ← אין משתמש.
 *
 * מכאן והלאה הקוד מכיר רק את המשתמש של האפליקציה: current_user() ← {id, sub, name, email, role, csrf}.
 * התפקיד נקרא מ-users בכל בקשה — שינוי תפקיד והסרה חלים מיד, גם על session פתוח.
 *
 * אם ש-25 / ש-2ב יכריעו "מנותקת" (סיסמה / Google של האפליקציה) — מוחלף הקובץ הזה בלבד.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const LOGIN_COOKIE = 'kolm_sess';
const LOGIN_IDLE_DAYS = 30;

/** הזהות שהשער העביר — מנוקה. null: אין, או שאינה תקינה. */
function platform_who(): ?array
{
    $id = json_decode((string)($_SERVER['PLATFORM_USER'] ?? ''), true);
    if (!is_array($id) || !is_scalar($id['sub'] ?? null) || (string)$id['sub'] === '') return null;
    $email = is_string($id['email'] ?? null) && mb_strlen($id['email']) <= 200 && filter_var($id['email'], FILTER_VALIDATE_EMAIL) ? mb_strtolower($id['email']) : null;
    return [
        'sub' => (string)$id['sub'],
        'name' => mb_substr(trim((string)($id['name'] ?? '')), 0, 80),
        'email' => $email,
        'email_verified' => $email !== null && ($id['email_verified'] ?? false) === true,
        'admin' => ($id['platform_admin'] ?? false) === true,
        'granted' => ($id['granted'] ?? true) !== false,          // signed_in בלי הרשאה (PF-002) — לא משתמש
    ];
}

function user_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** טבלאות ההיסטוריה שומרות by_sub — הקריאה שלהן עוברת כאן (decisions.md, P1). */
function user_by_sub(string $sub): ?array
{
    if ($sub === '') return null;
    $st = db()->prepare('SELECT * FROM users WHERE sub = ?');
    $st->execute([$sub]);
    return $st->fetch() ?: null;
}

/** התפקיד בפועל. בעל האתר — רק מי שהפלטפורמה אומרת שהוא מנהל; שורה שנשארה site_owner בלי זה — בלי תפקיד. */
function login_role(array $row, bool $admin): ?string
{
    if ($row['status'] !== 'active') return null;
    if ($admin) return 'site_owner';
    return $row['role'] === 'site_owner' ? null : ($row['role'] ?: null);
}

function login_cookie_path(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH))), '/');
    return $dir . '/';
}

function login_cookie(string $value, int $expires): void
{
    if (headers_sent() || PHP_SAPI === 'cli') return;
    setcookie(LOGIN_COOKIE, $value, [
        'expires' => $expires, 'path' => login_cookie_path(), 'httponly' => true, 'samesite' => 'Lax',
        // בשרת הבדיקות המובנה (http://127.0.0.1) אין HTTPS
        'secure' => PHP_SAPI !== 'cli-server',
    ]);
}

function login_sid_hash(string $raw): string
{
    return hash('sha256', 'kolm|' . $raw);
}

/** המשתמש של האפליקציה לפי הזהות מהשער — מוצא, מקשר או פותח. */
function login_row(array $id): array
{
    $pdo = db();
    $row = user_by_sub($id['sub']);
    if (!$row && $id['email'] && $id['email_verified']) {
        // הוזמן לפי מייל (או provision שנכשל) ועוד לא נכנס — מתקשר עכשיו
        $st = $pdo->prepare('UPDATE users SET sub = ? WHERE id = (SELECT id FROM users WHERE sub IS NULL AND agent IS NULL AND email = ? ORDER BY id LIMIT 1)');
        $st->execute([$id['sub'], $id['email']]);
        if ($st->rowCount()) {
            $row = user_by_sub($id['sub']);
            audit_write($row, 'member_linked', $id['email'], 'system');
        }
    }
    if (!$row) {
        $pdo->prepare("INSERT OR IGNORE INTO users (sub, name, email, role, status, created_at, last_seen) VALUES (?, ?, ?, ?, 'active', ?, ?)")
            ->execute([$id['sub'], $id['name'], $id['email'], $id['admin'] ? 'site_owner' : null, now_iso(), now_iso()]);
        $row = user_by_sub($id['sub']);
        if (!$id['admin'] && function_exists('people_request_notice')) people_request_notice($row);
    }
    // מי שהוסר ונכנס שוב (ההרשאה בפלטפורמה עדיין קיימת) — נשאר removed, בלי תפקיד. חזרה — רק "הוסף עוזר" של בעל העמוד
    $upd = [];
    if ($row['name'] !== $id['name'] && $id['name'] !== '') $upd['name'] = $id['name'];
    if ($id['email'] && $row['email'] !== $id['email']) $upd['email'] = $id['email'];
    if ($id['admin'] && ($row['role'] !== 'site_owner' || $row['status'] !== 'active')) { $upd['role'] = 'site_owner'; $upd['status'] = 'active'; }
    if (!$row['last_seen'] || strtotime($row['last_seen']) < time() - 60) $upd['last_seen'] = now_iso();
    if ($upd) {
        $pdo->prepare('UPDATE users SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($upd))) . ' WHERE id = ?')
            ->execute([...array_values($upd), $row['id']]);
        $row = array_merge($row, $upd);
    }
    return $row;
}

/** ה-session: מהעוגייה אם היא של המשתמש הזה וחיה; אחרת — חדש. מחזיר את אסימון ה-CSRF שלו. */
function login_session(array $row): string
{
    $pdo = db();
    $raw = (string)($_COOKIE[LOGIN_COOKIE] ?? '');
    $limit = gmdate('Y-m-d\TH:i:s\Z', time() - LOGIN_IDLE_DAYS * 86400);
    if (preg_match('/^[a-f0-9]{64}$/', $raw)) {
        $st = $pdo->prepare('SELECT * FROM sessions WHERE id = ?');
        $st->execute([login_sid_hash($raw)]);
        $s = $st->fetch();
        if ($s && (int)$s['user_id'] === (int)$row['id'] && $s['seen_at'] >= $limit) {
            if (strtotime($s['seen_at']) < time() - 60) $pdo->prepare('UPDATE sessions SET seen_at = ? WHERE id = ?')->execute([now_iso(), $s['id']]);
            return $s['csrf'];
        }
        if ($s) $pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$s['id']]);     // של sub אחר, או פג
    }
    $pdo->prepare('DELETE FROM sessions WHERE seen_at < ?')->execute([$limit]);
    $raw = bin2hex(random_bytes(32));
    $csrf = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO sessions (id, user_id, csrf, created_at, seen_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([login_sid_hash($raw), $row['id'], $csrf, now_iso(), now_iso()]);
    login_cookie($raw, time() + LOGIN_IDLE_DAYS * 86400);
    $_COOKIE[LOGIN_COOKIE] = $raw;
    return $csrf;
}

/** אין זהות מהשער — ה-session (אם יש) נמחק, והעוגייה מתבטלת. */
function login_end(): void
{
    $raw = (string)($_COOKIE[LOGIN_COOKIE] ?? '');
    if ($raw === '') return;
    if (preg_match('/^[a-f0-9]{64}$/', $raw)) db()->prepare('DELETE FROM sessions WHERE id = ?')->execute([login_sid_hash($raw)]);
    login_cookie('', time() - 3600);
    unset($_COOKIE[LOGIN_COOKIE]);
}

/** המשתמש הנוכחי: {id, sub, name, email, role|null, csrf}. null — אין כניסה. פעם אחת לבקשה. */
function current_user(): ?array
{
    static $memo = [];
    $key = (string)($_SERVER['PLATFORM_USER'] ?? '') . '|' . (string)($_COOKIE[LOGIN_COOKIE] ?? '');
    if (array_key_exists($key, $memo)) return $memo[$key];
    $id = platform_who();
    if (!$id || !$id['granted']) { login_end(); return $memo[$key] = null; }
    $row = login_row($id);
    $csrf = login_session($row);
    $u = ['id' => (int)$row['id'], 'sub' => $id['sub'], 'name' => $row['name'], 'email' => $row['email'], 'role' => login_role($row, $id['admin']), 'csrf' => $csrf];
    return $memo[(string)($_SERVER['PLATFORM_USER'] ?? '') . '|' . (string)($_COOKIE[LOGIN_COOKIE] ?? '')] = $memo[$key] = $u;
}

/** כל ה-sessions של משתמש — נמחקים (הסרה). */
function login_drop_sessions(int $userId): int
{
    $st = db()->prepare('DELETE FROM sessions WHERE user_id = ?');
    $st->execute([$userId]);
    return $st->rowCount();
}

function csrf_token(array $user): string
{
    return (string)($user['csrf'] ?? '');
}

/**
 * X-Kol + X-CSRF של ה-session. בטעינה הראשונה כמה בקשות מקבילות בלי עוגייה פותחות כל אחת session, ורק העוגייה של
 * האחרונה נשארת בדפדפן — ולכן מתקבל גם אסימון של session חי אחר **של אותו משתמש** (אסימון אקראי שרק הוא קיבל).
 */
function check_csrf(array $user): void
{
    $got = (string)($_SERVER['HTTP_X_CSRF'] ?? '');
    $t = csrf_token($user);
    if (($_SERVER['HTTP_X_KOL'] ?? '') !== '1' || $got === '') throw new ApiError('הבקשה נדחתה. רענן את הדף', 403);
    if ($t !== '' && hash_equals($t, $got)) return;
    if (!empty($user['id'])) {
        $st = db()->prepare('SELECT csrf FROM sessions WHERE user_id = ? AND seen_at >= ?');
        $st->execute([(int)$user['id'], gmdate('Y-m-d\TH:i:s\Z', time() - LOGIN_IDLE_DAYS * 86400)]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) if (hash_equals((string)$c, $got)) return;
    }
    throw new ApiError('הבקשה נדחתה. רענן את הדף', 403);
}
