<?php
/**
 * "שלח לעורך" מ-ChatGPT (spec/04-ai-chat-skill.md, מסלול ב').
 *
 * ה-GPT שולח לכאן את התמונות שנוצרו בשיחה (openaiFileIdRefs — קישורים שתקפים 5 דקות, ולכן מורידים מיד)
 * ואת ה-design.json. השיוך למשתמש — בשתי דרכים (הוחלט: "גם וגם"):
 * - **קוד חד-פעמי:** בלי התחברות ב-ChatGPT. נשמר "ייבוא ממתין" עם קוד, וה-GPT מחזיר קישור. מי שפותח אותו
 *   (מחובר לסטודיו) — מקבל את העיצוב. תקף 24 שעות.
 * - **OAuth:** מי שחיבר את ה-GPT לחשבון — הייבוא משויך אליו מיד, ומופיע במסך הבית גם בלי ללחוץ על הקישור.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/store.php';

const IMPORT_TTL = 86400;
const IMPORT_MAX_FILES = 10;
const IMPORT_PER_HOUR = 20;      // לכתובת IP, בלי התחברות

// מאיפה מותר להוריד קבצים: רק מ-OpenAI. ניתן לדריסה לפני הטעינה — כך הבדיקות מורידות משרת מקומי.
if (!defined('CHAT_FILE_URL_RE')) define('CHAT_FILE_URL_RE', '#^https://([a-z0-9-]+\.)*(oaiusercontent\.com|openai\.com|chatgpt\.com)(/|$)#i');

function migrateChat(): void {
    db()->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS chat_imports (
            code       TEXT PRIMARY KEY,
            user_id    INTEGER REFERENCES users(id) ON DELETE CASCADE,
            design     TEXT,
            files      TEXT NOT NULL,          -- [{name, path, mime}]
            ip         TEXT,
            created_at INTEGER NOT NULL,
            claimed_at INTEGER
        );
        CREATE TABLE IF NOT EXISTS oauth_codes (
            code_hash   TEXT PRIMARY KEY,
            user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            redirect_uri TEXT NOT NULL,
            expires_at  INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS oauth_tokens (
            token_hash TEXT PRIMARY KEY,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL
        );
    SQL);
}

function importsDir(string $code): string {
    $dir = STUDIO_DATA . '/imports/' . $code;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new ApiError('לא ניתן ליצור תיקייה', 500);
    return $dir;
}

/** קוד קריא: בלי 0/O ו-1/I/L, בצורה ABCD-EFGH. */
function newImportCode(): string {
    $abc = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 8; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
    return substr($s, 0, 4) . '-' . substr($s, 4);
}

/**
 * הקבצים מ-ChatGPT מגיעים כקישורי הורדה. מורידים רק מהדומיינים של OpenAI — אחרת זו דלת להורדה מכל
 * כתובת שהיא (SSRF), כולל רשת פנימית.
 */
function fetchChatFile(string $url): string {
    if (!preg_match(CHAT_FILE_URL_RE, $url) || preg_match('/[@\s\\]/', $url)) throw new ApiError('קישור קובץ לא מוכר', 422);
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'follow_location' => 1, 'max_redirects' => 3, 'ignore_errors' => false],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $data = @file_get_contents($url, false, $ctx, 0, MAX_UPLOAD + 1);
    if ($data === false || $data === '') throw new ApiError('הורדת הקובץ מ-ChatGPT נכשלה — אולי פג תוקף הקישור (5 דקות)', 502);
    if (strlen($data) > MAX_UPLOAD) throw new ApiError('קובץ גדול מ-25MB', 413);
    return $data;
}

/**
 * ייבוא חדש. ‏$refs — openaiFileIdRefs (מערך של {name, id, mime_type, download_link}); ‏$design — ה-JSON
 * (אובייקט או מחרוזת). ‏$userId — מ-OAuth, או null.
 */
function createChatImport(array $refs, mixed $design, ?int $userId): array {
    migrateChat();
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($userId === null) {
        $st = db()->prepare('SELECT COUNT(*) FROM chat_imports WHERE ip = ? AND created_at > ?');
        $st->execute([$ip, time() - 3600]);
        if ((int)$st->fetchColumn() >= IMPORT_PER_HOUR) throw new ApiError('יותר מדי שליחות. נסו שוב בעוד שעה', 429);
    }
    if (is_string($design)) $design = json_decode($design, true);
    if ($design !== null && !is_array($design)) throw new ApiError('design אינו JSON תקין', 422);
    $designJson = $design ? json_encode($design, JSON_UNESCAPED_UNICODE) : null;
    if ($designJson && strlen($designJson) > 1024 * 1024) throw new ApiError('העיצוב גדול מדי', 413);
    if (!$refs && !$design) throw new ApiError('לא נשלחו תמונות ולא עיצוב', 422);
    if (count($refs) > IMPORT_MAX_FILES) throw new ApiError('עד 10 תמונות בשליחה', 422);

    $code = newImportCode();
    $dir = importsDir($code);
    $files = [];
    try {
        foreach (array_values($refs) as $i => $ref) {
            if (is_string($ref)) $ref = json_decode($ref, true);   // התיעוד מתאר מחרוזות; בפועל מגיעים אובייקטים
            if (!is_array($ref) || empty($ref['download_link'])) continue;
            $bin = fetchChatFile((string)$ref['download_link']);
            $info = @getimagesizefromstring($bin);
            if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) continue;
            $path = "$dir/$i.bin";
            file_put_contents($path, $bin);
            $name = mb_substr(basename((string)($ref['name'] ?? "image-$i.png")), 0, 100);
            $files[] = ['name' => $name, 'path' => "$i.bin"];
        }
    } catch (Throwable $e) {
        array_map('unlink', glob("$dir/*") ?: []);
        @rmdir($dir);
        throw $e;
    }
    if (!$files && !$design) throw new ApiError('לא התקבלו תמונות תקינות', 422);

    db()->prepare('INSERT INTO chat_imports (code, user_id, design, files, ip, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$code, $userId, $designJson, json_encode($files, JSON_UNESCAPED_UNICODE), $ip, time()]);
    audit($userId, 'chat.import', ['code' => $code, 'files' => count($files)]);
    return ['code' => $code, 'files' => count($files)];
}

/**
 * מימוש ייבוא: התמונות עוברות לחשבון (כמו כל העלאה — פענוח מחדש, כפילויות), והייבוא מסומן כמומש.
 * ייבוא שכבר שויך למשתמש אחר (OAuth) — לא נמסר לאחר.
 */
function claimChatImport(int $userId, string $code): array {
    migrateChat();
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code)) throw new ApiError('קוד לא תקין', 422);
    $st = db()->prepare('SELECT * FROM chat_imports WHERE code = ?');
    $st->execute([$code]);
    $row = $st->fetch();
    if (!$row || $row['created_at'] < time() - IMPORT_TTL) throw new ApiError('הקוד פג או לא קיים — שלחו שוב מ-ChatGPT', 404);
    if ($row['user_id'] !== null && (int)$row['user_id'] !== $userId) throw new ApiError('הייבוא שייך לחשבון אחר', 403);
    if ($row['claimed_at'] !== null && $row['user_id'] === null) throw new ApiError('הקוד כבר מומש', 410);

    $dir = STUDIO_DATA . '/imports/' . $code;
    $out = [];
    foreach (json_decode($row['files'], true) ?: [] as $f) {
        $path = $dir . '/' . basename($f['path']);
        if (!is_file($path)) continue;
        $out[$f['name']] = storeImage($userId, (string)file_get_contents($path));
    }
    db()->prepare('UPDATE chat_imports SET user_id = ?, claimed_at = COALESCE(claimed_at, ?) WHERE code = ?')->execute([$userId, time(), $code]);
    return ['design' => $row['design'] ? json_decode($row['design'], true) : null, 'files' => $out];
}

/** ייבואים שמחכים למשתמש (שויכו ב-OAuth ועוד לא נפתחו). */
function pendingChatImports(int $userId): array {
    migrateChat();
    $st = db()->prepare('SELECT code, design, files, created_at FROM chat_imports WHERE user_id = ? AND claimed_at IS NULL AND created_at > ? ORDER BY created_at DESC');
    $st->execute([$userId, time() - IMPORT_TTL]);
    return array_map(fn($r) => [
        'code' => $r['code'],
        'title' => (json_decode((string)$r['design'], true)['title'] ?? null) ?: 'עיצוב מ-ChatGPT',
        'files' => count(json_decode($r['files'], true) ?: []),
        'createdAt' => (int)$r['created_at'] * 1000,
    ], $st->fetchAll());
}

/** ניקוי: ייבואים שפג תוקפם — הקבצים נמחקים. רץ מדי פעם, כחלק מייבוא חדש. */
function cleanupChatImports(): void {
    if (random_int(1, 20) !== 1) return;
    $st = db()->prepare('SELECT code FROM chat_imports WHERE created_at < ?');
    $st->execute([time() - IMPORT_TTL]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $code) {
        $dir = STUDIO_DATA . '/imports/' . $code;
        array_map('unlink', glob("$dir/*") ?: []);
        @rmdir($dir);
    }
    db()->prepare('DELETE FROM chat_imports WHERE created_at < ?')->execute([time() - IMPORT_TTL * 7]);
}

// ─── OAuth (לחיבור ה-GPT לחשבון) ───────────────────────────────────────────

function gptConfigFile(): string { return STUDIO_DATA . '/gpt.json'; }

function gptConfig(): array {
    $f = gptConfigFile();
    return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
}

/** פרטי הלקוח ל-GPT Builder. הסוד נשמר כ-hash ומוצג פעם אחת — ביצירה. */
function regenerateGptClient(): array {
    $id = 'gpt-' . bin2hex(random_bytes(6));
    $secret = bin2hex(random_bytes(24));
    file_put_contents(gptConfigFile(), json_encode(['client_id' => $id, 'secret_hash' => password_hash($secret, PASSWORD_DEFAULT), 'created_at' => nowIso()], JSON_PRETTY_PRINT));
    return ['client_id' => $id, 'client_secret' => $secret];
}

/** ‏redirect_uri מותר: רק ה-callback של ChatGPT. */
function validRedirect(string $uri): bool {
    return (bool)preg_match('#^https://(chat\.openai\.com|chatgpt\.com)/aip/[A-Za-z0-9_-]+/oauth/callback$#', $uri);
}

function issueOAuthCode(int $userId, string $redirect): string {
    migrateChat();
    $code = bin2hex(random_bytes(24));
    db()->prepare('INSERT INTO oauth_codes (code_hash, user_id, redirect_uri, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([hash('sha256', $code), $userId, $redirect, time() + 300]);
    return $code;
}

function exchangeOAuthCode(string $clientId, string $secret, string $code, string $redirect): array {
    migrateChat();
    $cfg = gptConfig();
    if (empty($cfg['client_id']) || !hash_equals($cfg['client_id'], $clientId) || !password_verify($secret, $cfg['secret_hash'] ?? '')) {
        throw new ApiError('invalid_client', 401);
    }
    $st = db()->prepare('SELECT * FROM oauth_codes WHERE code_hash = ?');
    $st->execute([hash('sha256', $code)]);
    $row = $st->fetch();
    db()->prepare('DELETE FROM oauth_codes WHERE code_hash = ?')->execute([hash('sha256', $code)]);
    if (!$row || (int)$row['expires_at'] < time() || $row['redirect_uri'] !== $redirect) throw new ApiError('invalid_grant', 400);
    $token = bin2hex(random_bytes(32));
    $ttl = 365 * 86400;
    db()->prepare('INSERT INTO oauth_tokens (token_hash, user_id, created_at, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([hash('sha256', $token), (int)$row['user_id'], time(), time() + $ttl]);
    audit((int)$row['user_id'], 'gpt.connected');
    return ['access_token' => $token, 'token_type' => 'bearer', 'expires_in' => $ttl];
}

/** המשתמש לפי Bearer, או null (בלי כותרת — מסלול הקוד). טוקן לא תקף — שגיאה, כדי ש-ChatGPT יבקש חיבור מחדש. */
function bearerUser(): ?int {
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $h, $m)) return null;
    migrateChat();
    $st = db()->prepare('SELECT user_id FROM oauth_tokens WHERE token_hash = ? AND expires_at > ?');
    $st->execute([hash('sha256', $m[1]), time()]);
    $id = $st->fetchColumn();
    if (!$id) throw new ApiError('החיבור פג — התחברו מחדש', 401);
    return (int)$id;
}
