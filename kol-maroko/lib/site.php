<?php
/**
 * החיבור לאתר (M6 — מה שנשאר מ-lib/publish.php אחרי שפרסום 1.x נמחק; docs/spec-v2/slices/M6-remove-old-studio.md §2.1).
 *
 * חוק הברזל: לכלי אין גישה לאתר. כל מה שיוצא אליו חתום ב-Ed25519 במפתח שנמצא רק במסד שלנו; התוסף מחזיק רק את המפתח
 * הציבורי. כאן: המפתחות (pub_key, key_rotate, key_activate), כתובת האתר (site_set), השליחה החתומה (site_post / site_call —
 * דחיפה, או התור של M7 במשיכה), בדיקת החיבור (site_ping) והמפסק (site_switch). הפרסום עצמו — lib/build.php (M1).
 *
 * כל בקשה חתומה על הגוף כולו, ובגוף: action, site, page, time, nonce — אי אפשר להעביר בקשה לנקודה אחרת, לאתר
 * אחר או לעמוד אחר, ולא לשלוח אותה פעמיים.
 */

declare(strict_types=1);

require_once __DIR__ . '/longtext.php';
require_once __DIR__ . '/outbox.php';             // M7 — התור היוצא וערוץ המשיכה

const PUB_PAGE = 'lexicon';
const PUB_SITE_DEFAULT = 'https://maghreb.co.il';
const PUB_ROUTE = '/wp-json/kol-maroko/v1/';
const PUB_PART = 2 * 1024 * 1024;               // חלק של קובץ (לפני base64, כ-2.7MB בבקשה) — מתחת לגבולות שמרניים של וורדפרס
const PUB_MAX_TOTAL = 400 * 1024 * 1024;

function site_url_setting(): string
{
    return rtrim(setting('site_url') ?? PUB_SITE_DEFAULT, '/');
}

function site_host(): string
{
    return preg_replace('/^www\./', '', (string)parse_url(site_url_setting(), PHP_URL_HOST));
}

// ── מפתחות ────────────────────────────────────────────────

function pub_key(): array
{
    $row = db()->query('SELECT * FROM pub_keys WHERE retired = 0 ORDER BY created_at DESC LIMIT 1')->fetch();
    return $row ?: pub_key_new();
}

function pub_key_new(): array
{
    $kp = sodium_crypto_sign_keypair();
    $row = ['kid' => gmdate('Y-m') . '-' . bin2hex(random_bytes(2)), 'public' => base64_encode(sodium_crypto_sign_publickey($kp)),
        'secret' => base64_encode(sodium_crypto_sign_secretkey($kp)), 'created_at' => time()];
    db()->prepare('INSERT INTO pub_keys (kid, public, secret, created_at, retired) VALUES (?, ?, ?, ?, 0)')
        ->execute([$row['kid'], $row['public'], $row['secret'], $row['created_at']]);
    return $row + ['retired' => 0];
}

/** מה שמדביקים בתוסף: "<kid>:<base64>". */
function pub_key_line(array $k): string
{
    return $k['kid'] . ':' . $k['public'];
}

/** מסך "🔗 חיבור לאתר" (⚙️ בעל האתר): המפתח הציבורי, מפתחות ישנים שעוד פעילים, כתובת האתר, והערוץ (M7). */
function site_settings(): array
{
    $k = pub_key();
    $old = db()->query('SELECT kid, public FROM pub_keys WHERE retired = 0 AND kid <> ' . db()->quote($k['kid']))->fetchAll();
    return [
        'key' => pub_key_line($k), 'kid' => $k['kid'], 'older' => array_map('pub_key_line', $old),
        'site' => site_url_setting(), 'page' => PUB_PAGE,
    ] + pull_settings();
}

/**
 * החלפת מפתח: נוצר מפתח חדש, אבל הפרסום ממשיך לחתום בישן עד שהבעלים מאשר שהדביק את החדש בתוסף
 * (key_activate) — כך אין רגע שבו האתר דוחה הכול.
 */
function key_rotate(array $owner): array
{
    $pending = setting('key_pending');
    if (!$pending) {
        $kp = sodium_crypto_sign_keypair();
        $pending = json_encode(['kid' => gmdate('Y-m') . '-' . bin2hex(random_bytes(2)), 'public' => base64_encode(sodium_crypto_sign_publickey($kp)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($kp))]);
        setting_set('key_pending', $pending);
        audit_as($owner, 'key_rotate', '');
    }
    $p = json_decode($pending, true);
    return site_settings() + ['pending' => $p['kid'] . ':' . $p['public']];
}

function key_activate(array $owner): array
{
    $p = json_decode((string)setting('key_pending'), true) ?: throw new ApiError('אין מפתח חדש שממתין');
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec('UPDATE pub_keys SET retired = 1');
    $pdo->prepare('INSERT INTO pub_keys (kid, public, secret, created_at, retired) VALUES (?, ?, ?, ?, 0)')->execute([$p['kid'], $p['public'], $p['secret'], time()]);
    $pdo->prepare("DELETE FROM settings WHERE k = 'key_pending'")->execute();
    $pdo->commit();
    audit_as($owner, 'key_activate', $p['kid']);
    return site_settings();
}

function site_set(array $owner, array $b): array
{
    $u = rtrim(trim((string)($b['site'] ?? '')), '/');
    if (!preg_match('~^https://[a-z0-9.-]+\.[a-z]{2,}$~i', $u)) throw new ApiError('כתובת אתר לא תקינה (https://דומיין, בלי נתיב)');
    setting_set('site_url', $u);
    audit_as($owner, 'site_set', $u);
    return site_settings();
}

// ── שליחה חתומה ───────────────────────────────────────────

/** POST חתום לתוסף. מחזיר [סטטוס, JSON | null, כותרות]. */
function site_post(string $action, array $payload): array
{
    $k = pub_key();
    $payload = ['action' => $action, 'site' => site_host(), 'page' => PUB_PAGE, 'time' => time(), 'nonce' => bin2hex(random_bytes(16))] + $payload;
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sig = base64_encode(sodium_crypto_sign_detached($body, base64_decode($k['secret'])));
    $headers = [];
    $ch = curl_init(site_url_setting() . PUB_ROUTE . $action);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Kol-Kid: ' . $k['kid'], 'X-Kol-Signature: ' . $sig],
        CURLOPT_USERAGENT => 'KolMaroko/1.0 (+https://mbe-plus.com/mini_projects/kol-maroko/)',
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) {
            if (str_contains($line, ':')) { [$n, $v] = explode(':', $line, 2); $headers[strtolower(trim($n))] = trim($v); }
            return strlen($line);
        },
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new ApiError('האתר לא ענה: ' . $err, 502, ['code' => 'network']);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, json_decode((string)$res, true), $headers, (string)$res];
}

/** הסבר בעברית לתשובה שאינה הצלחה. */
function site_error(int $status, ?array $j, array $headers, string $raw): ApiError
{
    if (is_array($j) && isset($j['code'])) {
        $map = [
            'bad_signature' => 'האתר דחה את החתימה. בדקו שהמפתח הציבורי בתוסף זהה למפתח שבמסך ההגדרות',
            'no_key' => 'בתוסף באתר עוד לא הודבק המפתח הציבורי',
            'wrong_site' => 'האתר לא מזהה את עצמו בכתובת שבהגדרות',
            'wrong_page' => 'העמוד בתוסף לא תואם',
            'stale' => 'השעון באתר רחוק מהשעון שלנו ביותר מ-5 דקות',
            'replay' => 'הבקשה כבר התקבלה (nonce כפול)',
            'old_version' => 'באתר כבר יש גרסה חדשה יותר',
            'asset_missing' => 'חסר באתר קובץ שהגרסה מפנה אליו',
            'too_large' => 'הבקשה גדולה מדי לאתר',
            'disabled' => 'התוסף באתר כבוי',
        ];
        return new ApiError($map[$j['code']] ?? ('האתר דחה: ' . ($j['message'] ?? $j['code'])), 502, ['code' => $j['code'], 'site' => $j]);
    }
    if (isset($headers['cf-ray']) && in_array($status, [403, 503, 429], true)) {
        return new ApiError("Cloudflare חסם את הבקשה ($status). צריך את הכלל ב-Cloudflare — ההוראות במסך ההגדרות", 502, ['code' => 'cloudflare']);
    }
    if ($status === 404) return new ApiError('באתר אין את נקודת הקבלה — התוסף לא מותקן או לא פעיל', 502, ['code' => 'no_plugin']);
    if ($status === 413) return new ApiError('השרת של האתר דחה בקשה גדולה (413)', 502, ['code' => 'too_large']);
    return new ApiError("תשובה לא צפויה מהאתר ($status)", 502, ['code' => 'http_' . $status, 'body' => mb_substr(strip_tags($raw), 0, 300)]);
}

/**
 * המקור האחד לכל מה שיוצא לאתר. הערוץ (pub_channel) מחליט: push — POST חתום לאתר, ותשובה מיידית; pull (M7) — manifest, switch,
 * activate ו-mount נכתבים לתור (outbox_add) ומוחזר {queued, seq}; ping מחזיר את הדופק — מה שהתוסף דיווח בפעם האחרונה.
 * $payload — מערך, או Closure(seq, expires) לפקודה שצריכה את ה-seq בתוכה (המניפסט). שאר הפעולות (ping בדחיפה, asset_has / asset_put
 * של 2.0) — push. M6: פרסום 1.x (publish / asset) נמחק — אין בכלי שום קריאה ל-site_call('publish').
 */
function site_call(string $action, array|Closure $payload, ?array $user = null, array $meta = []): array
{
    if (pub_channel() === 'pull') {
        if ($action === 'ping') return outbox_ping();
        if (in_array($action, OUTBOX_ACTIONS, true)) return outbox_add($action, $payload, $user, $meta);
        if (in_array($action, ['asset_has', 'asset_put'], true)) throw new ApiError('בערוץ המשיכה התוסף מוריד את הקבצים בעצמו', 500);
        // M6: במשיכה אין שום POST לאתר (Cloudflare חוסם אותו ב-403). פעולה שאינה בתור — שגיאה, לא דחיפה בשקט
        throw new ApiError("הפעולה \"$action\" אינה עוברת בערוץ המשיכה", 500, ['code' => 'pull_only']);
    }
    if ($payload instanceof Closure) $payload = $payload(0, 0);
    [$status, $j, $headers, $raw] = site_post($action, $payload);
    if ($status === 200 && is_array($j) && ($j['ok'] ?? false) === true) return $j;
    throw site_error($status, $j, $headers, $raw);
}

function site_ping(array $owner): array
{
    try {
        $r = site_call('ping', []);
    } catch (ApiError $e) {
        // לכלל IP Access ב-Cloudflare: הכתובת שממנה השרת שלנו יוצא (לרוב — של הדומיין)
        throw new ApiError($e->getMessage(), $e->getCode(), $e->extra + ['server_ip' => gethostbyname('mbe-plus.com')]);
    }
    return ['ok' => true, 'site' => $r];
}

/**
 * המפסק — מה האתר מציג: העמוד מהכלי (enabled) או העמוד המקורי. "חזרה לעמוד המקורי" — התוסף מכבה את ההחלפה, והאתר
 * מציג שוב את התבנית שלו ואת המילים שבוורדפרס (הם לא נמחקו ולא שונו). אפשר להחליף שוב בכל רגע.
 */
function site_switch(array $user, array $b): array
{
    $on = ($b['enabled'] ?? null) === true;
    $r = site_call('switch', ['enabled' => $on], $user);
    $what = $on ? 'האתר מציג את הגרסה מהסטודיו' : 'האתר חזר לעמוד המקורי';
    audit_as($user, 'site_switch', !empty($r['queued']) ? "בתור (#{$r['seq']}): $what" : $what);
    return ['site' => $r];
}

// ── מספור ──────────────────────────────────────────────────

/** מספר הגרסה הבא: אחרי כל גרסה ישנה (versions, לקריאה בלבד) וכל בנייה (pkg_builds) — המספרים לא חוזרים. */
function pub_next_n(): int
{
    $a = (int)db()->query('SELECT COALESCE(MAX(n), 0) FROM versions')->fetchColumn();
    $b = (int)db()->query('SELECT COALESCE(MAX(version), 0) FROM pkg_builds')->fetchColumn();
    return max($a, $b) + 1;
}
