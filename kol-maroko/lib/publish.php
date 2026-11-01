<?php
/**
 * פרסום לאתר (docs/spec.md, פרק 6) — חוק הברזל: לכלי אין גישה לאתר. הוא רק שולח "גרסה חדשה של עמוד קול מרוקו",
 * חתומה ב-Ed25519 במפתח שנמצא רק במסד שלו; התוסף באתר מחזיק רק את המפתח הציבורי.
 *
 * גרסה = עותק קפוא: ה-JSON של העמוד, ה-HTML וה-CSS שנגזרו (הקבצים ב-{{KOL_ASSETS}}/<sha256>.<ext> — התוסף מחליף
 * בכתובת שלו), ורשימת הקבצים. שליחה: קודם כל קובץ שהאתר עוד לא אישר (asset, בחלקים), ואז הגרסה (publish).
 * כל בקשה חתומה על הגוף כולו, ובגוף: action, site, page, time, nonce — אי אפשר להעביר בקשה לנקודה אחרת, לאתר
 * אחר או לעמוד אחר, ולא לשלוח אותה פעמיים.
 */

declare(strict_types=1);

require_once __DIR__ . '/longtext.php';

const PUB_PAGE = 'lexicon';
const PUB_SITE_DEFAULT = 'https://maghreb.co.il';
const PUB_ROUTE = '/wp-json/kol-maroko/v1/';
const PUB_PART = 2 * 1024 * 1024;               // חלק של קובץ (לפני base64, כ-2.7MB בבקשה) — מתחת לגבולות שמרניים של וורדפרס
const PUB_MAX_TOTAL = 400 * 1024 * 1024;
const PUB_ASSET_TOKEN = '{{KOL_ASSETS}}';

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

function publish_settings(): array
{
    $k = pub_key();
    $old = db()->query('SELECT kid, public FROM pub_keys WHERE retired = 0 AND kid <> ' . db()->quote($k['kid']))->fetchAll();
    return [
        'key' => pub_key_line($k), 'kid' => $k['kid'], 'older' => array_map('pub_key_line', $old),
        'site' => site_url_setting(), 'endpoint' => site_url_setting() . PUB_ROUTE . 'publish', 'page' => PUB_PAGE,
    ];
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
    return publish_settings() + ['pending' => $p['kid'] . ':' . $p['public']];
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
    return publish_settings();
}

function site_set(array $owner, array $b): array
{
    $u = rtrim(trim((string)($b['site'] ?? '')), '/');
    if (!preg_match('~^https://[a-z0-9.-]+\.[a-z]{2,}$~i', $u)) throw new ApiError('כתובת אתר לא תקינה (https://דומיין, בלי נתיב)');
    setting_set('site_url', $u);
    audit_as($owner, 'site_set', $u);
    return publish_settings();
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

function site_call(string $action, array $payload): array
{
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
 * מה האתר מציג: הגרסה מהסטודיו (enabled) או העמוד המקורי. "חזרה לעמוד המקורי" — התוסף מכבה את ההחלפה, והאתר
 * מציג שוב את התבנית שלו ואת המילים שבוורדפרס (הם לא נמחקו ולא שונו). אפשר להחליף שוב בכל רגע.
 */
function site_switch(array $user, array $b): array
{
    $on = ($b['enabled'] ?? null) === true;
    $r = site_call('switch', ['enabled' => $on]);
    audit_as($user, 'site_switch', $on ? 'האתר מציג את הגרסה מהסטודיו' : 'האתר חזר לעמוד המקורי');
    return ['site' => $r];
}

// ── בניית גרסה ────────────────────────────────────────────

/** בעיות שחוסמות פרסום (errors) ושכדאי לדעת (warnings). */
function publish_checks(array $page): array
{
    $errors = $warnings = [];
    $files = [];
    preg_match_all('/"([0-9a-f]{32})"/', json_encode($page), $m);
    $known = files_meta(array_unique($m[1]));
    $long = longtexts_get(array_values(array_filter(array_map(fn($w) => $w['type'] === 'longtext' ? $w['props']['doc'] : '', page_widgets($page)))));
    foreach (page_widgets($page) as $w) {
        $p = $w['props'];
        $need = match ($w['type']) { 'image', 'audio', 'file' => [$p['file']], 'gallery' => $p['files'], 'video' => [$p['file']], default => [] };
        foreach (array_filter($need) as $id) {
            if (!isset($known[$id])) $errors[] = ['id' => $w['id'], 'msg' => 'קובץ חסר — בחרו אותו מחדש'];
            else $files[$id] = $known[$id];
        }
        if ($w['type'] === 'image' && $p['file'] && trim($p['alt']) === '') $errors[] = ['id' => $w['id'], 'msg' => 'תמונה בלי טקסט חלופי'];
        if (in_array($w['type'], ['image', 'audio', 'file'], true) && !$p['file']) $warnings[] = ['id' => $w['id'], 'msg' => 'רכיב ריק — לא יוצג באתר'];
        if ($w['type'] === 'video' && !$p['file'] && !$p['embed']) $warnings[] = ['id' => $w['id'], 'msg' => 'וידאו ריק — לא יוצג באתר'];
        if ($w['type'] === 'button' && $p['href'] === '') $warnings[] = ['id' => $w['id'], 'msg' => 'כפתור בלי קישור'];
        if ($w['type'] === 'longtext' && (!$p['doc'] || !isset($long[$p['doc']]))) $errors[] = ['id' => $w['id'], 'msg' => 'טקסט ארוך שעוד לא נשמר בעורך'];
        if ($w['type'] === 'lexicon') {
            if ($p['hero_file'] && !isset($known[$p['hero_file']])) $errors[] = ['id' => $w['id'], 'msg' => 'תמונת הרקע של המילון חסרה — בחרו אותה מחדש'];
            $words = words_rows();
            $ids = array_values(array_unique(array_filter(array_map(fn($x) => file_id($x['audio']), $words))));
            $have = files_meta($ids);
            foreach ($words as $x) if (file_id($x['audio']) && !isset($have[$x['audio']])) $errors[] = ['id' => $w['id'], 'msg' => "ההקלטה של \"{$x['word']}\" חסרה — הקליטו או בחרו אותה מחדש ב-📚 מילים"];
            $files += array_filter($have, fn($f) => empty($f['origin']));   // עותקים של הקלטות מהאתר — לא נשלחים
            if (!$words) $warnings[] = ['id' => $w['id'], 'msg' => 'אין מילים במילון'];
        }
    }
    $total = array_sum(array_map(fn($f) => (int)$f['size'], $files));
    if ($total > PUB_MAX_TOTAL) $errors[] = ['id' => null, 'msg' => 'הקבצים בעמוד גדולים מ-400MB'];
    if (!page_widgets($page)) $errors[] = ['id' => null, 'msg' => 'העמוד ריק'];
    return ['errors' => $errors, 'warnings' => $warnings, 'files' => count($files), 'bytes' => $total];
}

function page_widgets(array $page): array
{
    $out = [];
    foreach ($page['sections'] as $s) foreach ($s['columns'] as $c) foreach ($c['widgets'] as $w) $out[] = $w;
    return $out;
}

/** "מה ישתנה" מול הגרסה החיה: רכיבים שנוספו / השתנו / נמחקו, ושינוי בסדר. */
function publish_diff(array $page): array
{
    $live = db()->query("SELECT json FROM versions WHERE status = 'live' ORDER BY n DESC LIMIT 1")->fetchColumn();
    if (!$live) return ['first' => true, 'words' => words_diff($page)];
    $old = json_decode($live, true);
    $a = []; foreach (page_widgets($old) as $w) $a[$w['id']] = $w;
    $b = []; foreach (page_widgets($page) as $w) $b[$w['id']] = $w;
    $label = fn($w) => ['id' => $w['id'], 'type' => $w['type']];
    $changed = [];
    foreach ($b as $id => $w) if (isset($a[$id]) && json_encode($a[$id]) !== json_encode($w)) $changed[] = $label($w);
    $ids = fn($p) => array_map(fn($s) => [$s['id'], $s['layout'], $s['bg'], $s['pad'], $s['full'], array_map(fn($c) => array_column($c['widgets'], 'id'), $s['columns'])], $p['sections']);
    return [
        'added' => array_values(array_map($label, array_diff_key($b, $a))),
        'removed' => array_values(array_map($label, array_diff_key($a, $b))),
        'changed' => $changed,
        'layout' => json_encode($ids($old)) !== json_encode($ids($page)),
        'settings' => json_encode($old['settings']) !== json_encode($page['settings']),
        'words' => words_diff($page),
        'code' => page_has_lexicon($page) && (string)db()->query("SELECT code FROM versions WHERE status = 'live' ORDER BY n DESC LIMIT 1")->fetchColumn() !== (string)code_snapshot(),
    ];
}

/** המילים מול הגרסה החיה: כמה נוספו, השתנו ונמחקו. null — אין מילון בעמוד. */
function words_diff(array $page): ?array
{
    if (!page_has_lexicon($page)) return null;
    $old = db()->query("SELECT words FROM versions WHERE status = 'live' ORDER BY n DESC LIMIT 1")->fetchColumn();
    $key = fn($w) => $w['wp_id'] ? 'wp' . $w['wp_id'] : 'k' . $w['id'];
    $a = []; foreach (json_decode((string)$old, true) ?: [] as $w) $a[$key($w)] = $w;
    $b = []; foreach (words_rows() as $w) $b[$key($w)] = $w;
    $changed = 0;
    foreach ($b as $k => $w) if (isset($a[$k]) && json_encode($a[$k]) !== json_encode($w)) $changed++;
    return ['total' => count($b), 'added' => count(array_diff_key($b, $a)), 'removed' => count(array_diff_key($a, $b)), 'changed' => $changed];
}

function page_has_lexicon(array $page): bool
{
    foreach (page_widgets($page) as $w) if ($w['type'] === 'lexicon') return true;
    return false;
}

/** גרסה שמורה: המילים וקבצי הקוד שלה (העותקים), ולא הנוכחיים. גרסה בלי מילון — כמו שהיא. */
function with_version(array $v, callable $fn): mixed
{
    if (($v['words'] ?? null) === null) return $fn();
    $GLOBALS['kol_words'] = json_decode($v['words'], true) ?: [];
    $GLOBALS['kol_code'] = ($v['code'] ?? null) === null ? null : (json_decode($v['code'], true) ?: null);
    try { return $fn(); } finally { unset($GLOBALS['kol_words'], $GLOBALS['kol_code']); }
}

function publish_preview(array $user): array
{
    $page = json_decode((string)draft_row()['json'], true);
    $page = page_normalize($page);
    return ['checks' => publish_checks($page), 'diff' => publish_diff($page), 'live' => live_version()];
}

function live_version(): ?array
{
    $r = db()->query("SELECT n, at, name FROM versions WHERE status = 'live' ORDER BY n DESC LIMIT 1")->fetch();
    return $r ?: null;
}

/**
 * הרינדור לפרסום: בלי data-kid, קבצים ב-{{KOL_ASSETS}}/<sha256>.<ext>. תמונות בתוך טקסט ארוך (כתובות חתומות של
 * עורך התמלילים) — יורדות לכאן ונכנסות לחבילה כמו כל קובץ, כדי שהאתר לא יהיה תלוי בעורך.
 */
function build_version(array $page): array
{
    $assets = [];
    $r = page_render($page, false, function (array $f) use (&$assets) {
        $assets[$f['sha256']] = ['sha' => $f['sha256'], 'ext' => $f['ext'], 'type' => $f['type'], 'size' => (int)$f['size'], 'path' => file_path($f)];
        return PUB_ASSET_TOKEN . '/' . $f['sha256'] . '.' . $f['ext'];
    });
    $html = preg_replace_callback('~(src|href)="(' . preg_quote(KOL_WP_URL, '~') . '[^"]+)"~', function ($m) use (&$assets) {
        $a = fetch_editor_asset(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5));
        $assets[$a['sha']] = $a;
        return $m[1] . '="' . PUB_ASSET_TOKEN . '/' . $a['sha'] . '.' . $a['ext'] . '"';
    }, $r['html']);
    return ['html' => $html, 'css' => $r['css'], 'assets' => array_values($assets)];
}

function fetch_editor_asset(string $url): array
{
    $dir = files_dir() . '/pub';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $tmp = $dir . '/dl-' . bin2hex(random_bytes(8));
    $fh = fopen($tmp, 'wb');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Cookie: humans_21909=1'],
        CURLOPT_NOPROGRESS => false, CURLOPT_PROGRESSFUNCTION => fn($c, $dt, $d) => $d > 20 * 1024 * 1024 ? 1 : 0]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($fh);
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'][$type] ?? null;
    if (!$ok || $status !== 200 || !$ext) {
        @unlink($tmp);
        throw new ApiError('לא הצלחתי להוריד תמונה מתוך טקסט ארוך (בעורך התמלילים). פתחו ושמרו אותו שוב', 502);
    }
    $sha = hash_file('sha256', $tmp);
    $path = "$dir/$sha.$ext";
    rename($tmp, $path);
    return ['sha' => $sha, 'ext' => $ext, 'type' => $type, 'size' => filesize($path), 'path' => $path];
}

/** פרסום הטיוטה כגרסה חדשה. */
function publish(array $user, array $b): array
{
    $page = page_normalize(json_decode((string)draft_row()['json'], true));
    $checks = publish_checks($page);
    if ($checks['errors']) throw new ApiError('יש בעיות שחוסמות פרסום', 422, ['checks' => $checks]);
    return version_send($user, version_create($user, $page, (string)($b['note'] ?? '')));
}

/**
 * חזרה לגרסה: גרסה חדשה עם **אותו תוכן בדיוק** — ה-HTML, ה-CSS והקבצים שנשמרו בה (לא בנייה מחדש), כך שהאתר נראה
 * בדיוק כמו אז. האתר לעולם לא מקבל מספר ישן, ומה שהיה אחריה לא נמחק. tool — גם הכלי חוזר אליה (הטיוטה, המילים
 * וקבצי הקוד), אחרי נקודת שחזור.
 */
function republish(array $user, array $b): array
{
    $st = db()->prepare('SELECT * FROM versions WHERE n = ?');
    $st->execute([(int)($b['n'] ?? 0)]);
    $v = $st->fetch() ?: throw new ApiError('הגרסה לא נמצאה', 404);
    if (!empty($b['tool'])) version_to_tool($user, $v);
    $pdo = db();
    $n = (int)$pdo->query('SELECT COALESCE(MAX(n), 0) + 1 FROM versions')->fetchColumn();
    $pdo->prepare("INSERT INTO versions (n, at, sub, name, note, json, html, css, assets, status, words, code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)")
        ->execute([$n, now_iso(), $user['sub'], $user['name'], 'חזרה לגרסה ' . (int)$v['n'], $v['json'], $v['html'], $v['css'], $v['assets'], $v['words'], $v['code']]);
    audit_as($user, 'version_create', "גרסה $n — חזרה לגרסה {$v['n']}");
    return version_send($user, $n);
}

/** הכלי חוזר למצב של גרסה: הטיוטה, המילים וקבצי הקוד שנשמרו בה. קודם — נקודת שחזור של המצב הנוכחי. */
function version_to_tool(array $user, array $v): void
{
    $l = lock_state();
    if ($l && $l['sub'] !== $user['sub']) throw new ApiError("הטיוטה בעריכה אצל {$l['name']}. אחרי שיסיים — שוב", 423);
    snapshot_create($user, 'לפני חזרה לגרסה ' . (int)$v['n']);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $d = draft_row();
        if ($v['json'] !== $d['json']) {
            revision_add($user, $d['json'], (int)$d['rev'], 'לפני חזרה לגרסה ' . (int)$v['n']);
            $pdo->prepare('UPDATE draft SET json = ?, rev = rev + 1, updated_by = ?, updated_at = ? WHERE id = 1')->execute([$v['json'], $user['sub'], now_iso()]);
        }
        if ($v['words'] !== null) {
            $pdo->exec('DELETE FROM words');
            $ins = $pdo->prepare('INSERT INTO words (id, wp_id, word, meaning, topics, audio, external_id, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $map = topic_slugs();
            $top = $pdo->prepare('INSERT OR IGNORE INTO topics (slug, name) VALUES (?, ?)');
            foreach (json_decode($v['words'], true) ?: [] as $w) {
                $ins->execute([$w['id'], $w['wp_id'], $w['word'], $w['meaning'], json_encode($w['topics'], JSON_UNESCAPED_UNICODE), $w['audio'], $w['external_id'], now_iso(), $user['name']]);
                foreach ($w['topics'] as $t) $top->execute([topic_slug($t, $map), $t]);
            }
        }
        $pdo->exec('DELETE FROM code_files');
        $ins = $pdo->prepare('INSERT INTO code_files (path, content, updated_at, updated_by) VALUES (?, ?, ?, ?)');
        foreach (json_decode((string)$v['code'], true) ?: [] as $path => $content) $ins->execute([$path, $content, now_iso(), $user['name']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit_as($user, 'version_to_tool', 'הכלי חזר לגרסה ' . (int)$v['n']);
}

function version_create(array $user, array $page, string $note): int
{
    $v = build_version($page);
    $pdo = db();
    $n = (int)$pdo->query('SELECT COALESCE(MAX(n), 0) + 1 FROM versions')->fetchColumn();
    $lex = page_has_lexicon($page);
    $pdo->prepare("INSERT INTO versions (n, at, sub, name, note, json, html, css, assets, status, words, code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)")
        ->execute([$n, now_iso(), $user['sub'], $user['name'], mb_substr($note, 0, 200), page_json($page),
            $v['html'], $v['css'], json_encode($v['assets'], JSON_UNESCAPED_SLASHES), $lex ? words_snapshot() : null, $lex ? code_snapshot() : null]);
    audit_as($user, 'version_create', "גרסה $n");
    return $n;
}

/** שולח (או שולח שוב) גרסה: קבצים שהאתר עוד לא אישר, ואז הגרסה עצמה. */
function version_send(array $user, int $n, bool $renumbered = false): array
{
    $st = db()->prepare('SELECT * FROM versions WHERE n = ?');
    $st->execute([$n]);
    $v = $st->fetch() ?: throw new ApiError('הגרסה לא נמצאה', 404);
    if ($v['status'] === 'live') throw new ApiError('הגרסה הזו כבר באתר');
    $newer = (int)db()->query("SELECT COALESCE(MAX(n), 0) FROM versions WHERE status = 'live'")->fetchColumn();
    if ($newer > $n) throw new ApiError("באתר כבר גרסה $newer. כדי לחזור לגרסה הזו — \"פרסום מחדש\"");
    @set_time_limit(600);
    $assets = json_decode($v['assets'], true);
    try {
        foreach ($assets as $a) asset_send($a);
        $r = site_call('publish', ['version' => $n, 'html' => $v['html'], 'css' => $v['css'],
            'assets' => array_map(fn($a) => $a['sha'] . '.' . $a['ext'], $assets), 'published_by' => $user['name']]);
    } catch (ApiError $e) {
        $siteN = (int)($e->extra['site']['version'] ?? 0);
        // נשלחה כבר בניסיון קודם (התשובה אבדה) — האתר מחזיק בדיוק אותה גרסה
        if (($e->extra['code'] ?? '') === 'old_version' && $siteN === $n) {
            $r = $e->extra['site'];
        } elseif (($e->extra['code'] ?? '') === 'old_version' && $siteN > $n && !$renumbered) {
            // באתר מספר גבוה משלנו (למשל המסד שלנו שוחזר מגיבוי) — הגרסה מקבלת מספר אחריו, ונשלחת שוב
            $to = max($siteN, (int)db()->query('SELECT MAX(n) FROM versions')->fetchColumn()) + 1;
            db()->prepare('UPDATE versions SET n = ? WHERE n = ?')->execute([$to, $n]);
            return version_send($user, $to, true);
        } else {
            db()->prepare("UPDATE versions SET status = 'failed', response = ? WHERE n = ?")->execute([$e->getMessage(), $n]);
            audit_as($user, 'publish_failed', "גרסה $n · " . $e->getMessage());
            throw $e;
        }
    }
    db()->prepare("UPDATE versions SET status = 'live', response = ?, sent_at = ? WHERE n = ?")
        ->execute([json_encode($r, JSON_UNESCAPED_UNICODE), now_iso(), $n]);
    db()->prepare("UPDATE versions SET status = 'superseded' WHERE status = 'live' AND n < ?")->execute([$n]);
    audit_as($user, 'publish', "גרסה $n");
    return ['n' => $n, 'site' => $r] + versions_list();
}

/** קובץ אחד לאתר, בחלקים. קובץ שהאתר כבר אישר (site_assets) — לא נשלח שוב. */
function asset_send(array $a): void
{
    $st = db()->prepare('SELECT 1 FROM site_assets WHERE sha = ? AND site = ?');
    $st->execute([$a['sha'], site_host()]);
    if ($st->fetchColumn()) return;
    $fh = @fopen($a['path'], 'rb') ?: throw new ApiError('קובץ חסר בשרת שלנו: ' . basename($a['path']), 500);
    $size = filesize($a['path']);
    $parts = max(1, (int)ceil($size / PUB_PART));
    for ($i = 0; $i < $parts; $i++) {
        $data = fread($fh, PUB_PART);
        $r = site_call('asset', ['sha256' => $a['sha'], 'ext' => $a['ext'], 'type' => $a['type'], 'size' => $size,
            'part' => $i, 'parts' => $parts, 'data' => base64_encode((string)$data)]);
        if (!empty($r['have'])) break;                       // האתר כבר מחזיק את הקובץ
    }
    fclose($fh);
    db()->prepare('INSERT OR IGNORE INTO site_assets (sha, site, at) VALUES (?, ?, ?)')->execute([$a['sha'], site_host(), now_iso()]);
}

/** כל הגרסאות — בלי הגבלה, בדפים של 50. */
function versions_list(array $q = []): array
{
    $off = max(0, (int)($q['offset'] ?? 0));
    $rows = db()->query('SELECT n, at, name, note, status, response, sent_at, length(assets) AS al, assets FROM versions ORDER BY n DESC LIMIT 50 OFFSET ' . $off)->fetchAll();
    return ['versions' => array_map(fn($r) => [
        'n' => (int)$r['n'], 'at' => $r['at'], 'name' => $r['name'], 'note' => $r['note'], 'status' => $r['status'],
        'error' => $r['status'] === 'failed' ? $r['response'] : null, 'sent_at' => $r['sent_at'], 'files' => count(json_decode($r['assets'], true)),
    ], $rows), 'live' => live_version(), 'total' => (int)db()->query('SELECT COUNT(*) FROM versions')->fetchColumn(), 'offset' => $off];
}

function version_preview(array $b): array
{
    $st = db()->prepare('SELECT json, words, code FROM versions WHERE n = ?');
    $st->execute([(int)($b['n'] ?? 0)]);
    $v = $st->fetch() ?: throw new ApiError('הגרסה לא נמצאה', 404);
    return with_version($v, fn() => page_render(page_normalize(json_decode($v['json'], true)), true));
}
