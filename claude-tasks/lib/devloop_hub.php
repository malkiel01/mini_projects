<?php
/**
 * DevLoop — הלוח המרכזי: כל האפליקציות שעובדות עם DevLoop (מנהל הפרויקטים, ובהמשך עוד) במקום אחד.
 *
 * כל משתמש מחבר "מקורות": כתובת ה-API של אפליקציה ואסימון DevLoop שלה (pmd_… — "🔑 גישה ל-Claude" באפליקציה).
 * הלוח קורא devapi-feed?all=1 מכל מקור ומראה: כמה ממתינות לאימות שלך, שאלות, "⚠️ לבירור", ומה אצל Claude —
 * עם קישור ישר למטלה באפליקציה. הלוח רק קורא; האימות עצמו נעשה באפליקציה (שם ההקשר, הצילומים והשיח).
 *
 * האסימון נשמר מוצפן (lib/crypto.php) ואינו חוזר מה-API. הכתובת — https בלבד, לכתובת ציבורית, בלי הפניות.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/errors.php';

const HUB_MAX_SOURCES = 20;
const HUB_FEED_MAX = 4 * 1048576;

function hubMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS devloop_sources (
            id          INTEGER PRIMARY KEY,
            user_id     INTEGER NOT NULL,
            name        TEXT NOT NULL,
            api_url     TEXT NOT NULL,
            token_enc   TEXT NOT NULL,
            token_tail  TEXT NOT NULL DEFAULT '',
            created_at  INTEGER NOT NULL,
            last_ok_at  INTEGER,
            last_error  TEXT NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_devloop_sources_user ON devloop_sources(user_id);
        SQL);
}

/**
 * כתובת של מקור: https, נתיב שמסתיים ב-api.php, ומארח שכתובתו ציבורית — כדי שהשרת לא יהפוך לפרוקסי לרשת הפנימית.
 * בפיתוח מקומי (HUB_ALLOW_LOCAL בסביבה) — גם http://127.0.0.1.
 */
function hubCheckUrl(string $url): string {
    $url = trim($url);
    $local = getenv('HUB_ALLOW_LOCAL') === '1';
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !preg_match('~/api\.php$~', $p['path'] ?? '')) {
        throw new AppError('כתובת ה-API של האפליקציה — עד api.php, למשל https://example.com/mini_projects/project-manager/api.php');
    }
    if (($p['scheme'] ?? '') !== 'https' && !($local && ($p['scheme'] ?? '') === 'http')) throw new AppError('רק https');
    if (isset($p['user']) || isset($p['query']) || isset($p['fragment'])) throw new AppError('כתובת בלי פרמטרים');
    if (!$local) {
        $ips = gethostbynamel($p['host']) ?: [];
        if (!$ips) throw new AppError('המארח לא נמצא');
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new AppError('כתובת פנימית — לא');
        }
    }
    return $url;
}

/** קריאה אחת ל-devapi-feed של מקור. זורק AppError עם סיבה קריאה. */
function hubFetch(string $apiUrl, string $token): array {
    if (!function_exists('curl_init')) throw new AppError('cURL אינו זמין בשרת');
    $ch = curl_init($apiUrl . '?action=devapi-feed&all=1');
    $buf = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | (getenv('HUB_ALLOW_LOCAL') === '1' ? CURLPROTO_HTTP : 0),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER     => ['X-Dev-Token: ' . $token, 'Accept: application/json', 'User-Agent: claude-tasks-devloop-hub'],
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buf) {
            $buf .= $chunk;
            return strlen($buf) > HUB_FEED_MAX ? 0 : strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($ok === false && $code === 0) throw new AppError('אין חיבור לאפליקציה: ' . $err);
    $data = json_decode($buf, true);
    if ($code === 401) throw new AppError('האסימון אינו תקף — צרו חדש באפליקציה ("🔑 גישה ל-Claude")');
    if ($code !== 200 || !is_array($data) || !isset($data['items'])) {
        throw new AppError('האפליקציה החזירה ' . $code . (is_array($data) && !empty($data['error']) ? ': ' . $data['error'] : ''));
    }
    return $data;
}

/** הכתובת של מסך המטלות באפליקציה — לפי מוסכמת DevLoop (#/settings/tasks/<קוד>). */
function hubItemUrl(string $apiUrl, string $code = ''): string {
    return preg_replace('~api\.php$~', '', $apiUrl) . '#/settings/tasks' . ($code !== '' ? '/' . rawurlencode($code) : '');
}

function hubSources(int $userId): array {
    hubMigrate();
    $st = db()->prepare('SELECT id, name, api_url, token_tail, created_at, last_ok_at, last_error FROM devloop_sources WHERE user_id = ? ORDER BY name');
    $st->execute([$userId]);
    return $st->fetchAll();
}

function hubAddSource(int $userId, string $name, string $url, string $token): array {
    hubMigrate();
    $name = mb_substr(trim($name), 0, 60);
    $token = trim($token);
    if ($name === '') throw new AppError('חסר שם (למשל: מנהל הפרויקטים)');
    if (!preg_match('/^[a-z]{2,8}_[0-9a-f]{48}$/', $token)) throw new AppError('האסימון — כמו שהאפליקציה הציגה אותו (למשל pmd_…)');
    $url = hubCheckUrl($url);
    $n = db()->prepare('SELECT COUNT(*) FROM devloop_sources WHERE user_id = ?');
    $n->execute([$userId]);
    if ((int) $n->fetchColumn() >= HUB_MAX_SOURCES) throw new AppError('עד ' . HUB_MAX_SOURCES . ' אפליקציות');
    hubFetch($url, $token); // נבדק לפני שנשמר — אסימון פסול מתגלה עכשיו, לא בלוח
    db()->prepare('INSERT INTO devloop_sources (user_id, name, api_url, token_enc, token_tail, created_at, last_ok_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$userId, $name, $url, encryptSecret($token), secretTail($token), nowTs(), nowTs()]);
    return ['id' => (int) db()->lastInsertId()];
}

function hubRemoveSource(int $userId, int $id): void {
    hubMigrate();
    $st = db()->prepare('DELETE FROM devloop_sources WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    if (!$st->rowCount()) throw new AppError('המקור לא נמצא', 404);
}

/**
 * הלוח: לכל מקור — מונים, ומה שמחכה לך (לאימות, שאלה, Claude ענה, לבירור), מהחדש לישן.
 * מקור שלא ענה — מוצג עם הסיבה, ולא מפיל את השאר.
 */
function hubBoard(int $userId): array {
    hubMigrate();
    $st = db()->prepare('SELECT * FROM devloop_sources WHERE user_id = ? ORDER BY name');
    $st->execute([$userId]);
    $apps = [];
    $mine = [];
    foreach ($st->fetchAll() as $s) {
        $app = ['id' => (int) $s['id'], 'name' => $s['name'], 'url' => hubItemUrl($s['api_url']), 'ok' => false, 'error' => '', 'counts' => []];
        try {
            $feed = hubFetch($s['api_url'], decryptSecret($s['token_enc']));
            $c = ['verify' => 0, 'question' => 0, 'triage' => 0, 'answered' => 0, 'claude' => 0, 'closed' => 0];
            foreach ($feed['items'] as $x) {
                $status = (string) ($x['status'] ?? '');
                $live = !in_array($status, ['closed', 'triage'], true);
                $why = $status === 'verify' ? 'verify' : ($status === 'question' ? 'question'
                     : ($status === 'triage' ? 'triage' : ($live && ($x['waiting'] ?? null) === 'user' ? 'answered' : '')));
                if ($status === 'closed') $c['closed']++;
                elseif ($why !== '') $c[$why]++;
                elseif ($live) $c['claude']++;
                if ($why === '') continue;
                $last = end($x['comments']) ?: null;
                $mine[] = ['app' => $s['name'], 'app_id' => (int) $s['id'], 'code' => (string) $x['code'], 'title' => (string) ($x['title'] ?? ''),
                    'why' => $why, 'kind' => (string) ($x['kind'] ?? 'task'), 'at' => (string) ($last['at'] ?? $x['opened'] ?? ''),
                    'last' => $last ? mb_substr((string) $last['body'], 0, 200) : '', 'count' => (int) ($x['occurrences']['count'] ?? 0),
                    'url' => hubItemUrl($s['api_url'], (string) $x['code'])];
            }
            $app['ok'] = true;
            $app['counts'] = $c;
            db()->prepare("UPDATE devloop_sources SET last_ok_at = ?, last_error = '' WHERE id = ?")->execute([nowTs(), $s['id']]);
        } catch (AppError $e) {
            $app['error'] = $e->getMessage();
            db()->prepare('UPDATE devloop_sources SET last_error = ? WHERE id = ?')->execute([mb_substr($e->getMessage(), 0, 300), $s['id']]);
        }
        $apps[] = $app;
    }
    // הסדר: מה שחוסם אותך קודם (אימות, שאלה), אחר כך תשובות של Claude, ואז שגיאות לבירור
    $rank = ['verify' => 0, 'question' => 1, 'answered' => 2, 'triage' => 3];
    usort($mine, fn($a, $b) => [$rank[$a['why']], $b['at']] <=> [$rank[$b['why']], $a['at']]);
    $waiting = count(array_filter($mine, fn($x) => $x['why'] !== 'triage'));
    return ['apps' => $apps, 'items' => $mine, 'waiting' => $waiting];
}
