<?php
/**
 * חיבורים — שדה "בחירה מרשימה", מקורות נתונים חיצוניים, ו-API למערכות חיצוניות.
 *
 * מקור נתונים: כתובת (GET) שמחזירה JSON — רשימת רשומות. השרת מושך אותה (לא
 * הדפדפן: כך כותרות ההרשאה לא יוצאות לממלא), שומר עותק מוצפן, ומרענן לפי
 * זמן התוקף או בלחיצה. שדה בחירה מציג ממנו את הרשימה, ויכול למלא שדות
 * נוספים מהרשומה שנבחרה.
 *
 * מפתח API: מערכת חיצונית מנפיקה מסמכים מתבנית, קוראת את מצבם והערכים,
 * ומורידה קובץ Word. הממלא מקבל קישור רגיל — בלי שם המערכת. כשמסמך שהונפק
 * דרך מפתח מתקדם (ממלא סיים / הושלם) — נשלחת הודעה לכתובת ה-webhook של המפתח,
 * חתומה ב-HMAC.
 *
 * כל זה שייך לאפליקציה בלבד: הטבלאות במסד שלה, המפתחות של המשתמשים שלה.
 */

declare(strict_types=1);

const SRC_MAX_BYTES = 2 * 1024 * 1024;
const SRC_MAX_ROWS  = 2000;
const SELECT_MAX_OPTIONS = 300;
const API_KEY_PREFIX = 'dtk_';

function integrations_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS data_sources (
            id           INTEGER PRIMARY KEY,
            owner_id     INTEGER NOT NULL REFERENCES users(id),
            name_enc     TEXT NOT NULL,
            config_enc   TEXT NOT NULL,          -- כתובת, כותרות, נתיב לרשימה (מוצפן: יש בו סודות)
            ttl_min      INTEGER NOT NULL DEFAULT 60,
            rows_enc     TEXT,                   -- העותק האחרון
            row_count    INTEGER NOT NULL DEFAULT 0,
            fetched_at   TEXT,
            last_error   TEXT,
            created_at   TEXT NOT NULL,
            archived_at  TEXT
        );
        CREATE TABLE IF NOT EXISTS api_keys (
            id             INTEGER PRIMARY KEY,
            owner_id       INTEGER NOT NULL REFERENCES users(id),
            name_enc       TEXT NOT NULL,
            key_hash       TEXT NOT NULL UNIQUE,
            prefix         TEXT NOT NULL,        -- תחילת המפתח — לזיהוי ברשימה
            webhook_enc    TEXT,                 -- כתובת ה-webhook
            secret_enc     TEXT NOT NULL,        -- לחתימת ההודעות
            created_at     TEXT NOT NULL,
            last_used_at   TEXT,
            revoked_at     TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_sources_owner ON data_sources(owner_id);
        CREATE INDEX IF NOT EXISTS idx_keys_owner ON api_keys(owner_id);
        SQL);
    $cols = array_column($pdo->query('PRAGMA table_info(documents)')->fetchAll(), 'name');
    foreach (['api_key_id INTEGER', 'external_ref TEXT', 'sender_enc TEXT'] as $def) {
        if (!in_array(strtok($def, ' '), $cols, true)) $pdo->exec("ALTER TABLE documents ADD COLUMN $def");
    }
}

// ── משיכה מכתובת חיצונית ─────────────────────────────────────

/**
 * בודק שהכתובת היא http(s) לשרת ציבורי — לא לשרת עצמו ולא לרשת פנימית — ומחזיר
 * את ה-IP שנבדק, כדי שהחיבור ילך בדיוק אליו (בלי החלפה בין הבדיקה לחיבור).
 */
function safe_target(string $url): array
{
    $p = parse_url($url);
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    $host = (string)($p['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($p['user'])) {
        throw new ApiError('כתובת לא תקינה — נדרש http:// או https://', 422);
    }
    $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) throw new ApiError("השרת $host לא נמצא", 422);
    $local = defined('DT_ALLOW_LOCAL_FETCH') && DT_ALLOW_LOCAL_FETCH;   // בבדיקות בלבד
    foreach ($ips as $ip) {
        if (!$local && !public_ip($ip)) throw new ApiError('כתובת ברשת פנימית אינה מותרת', 422);
    }
    return [$host, $port, $ips[0]];
}

/** כתובת באינטרנט — לא השרת עצמו, לא רשת פרטית, לא כתובות שמורות (כמו 169.254.x). */
function public_ip(string $ip): bool
{
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/** בקשת HTTP יוצאת: עם תקרת זמן וגודל, בלי מעקב אחרי הפניות. */
function http_call(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 10): array
{
    [$host, $port, $ip] = safe_target($url);
    if (!function_exists('curl_init')) throw new ApiError('בשרת אין cURL', 500);
    $ch = curl_init($url);
    $buf = '';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RESOLVE => ["$host:$port:$ip"],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'User-Agent: DocumentTemplates/1'], $headers),
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf) {
            $buf .= $chunk;
            return strlen($buf) > SRC_MAX_BYTES ? 0 : strlen($chunk);   // 0 — עוצר
        },
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (strlen($buf) > SRC_MAX_BYTES) throw new ApiError('התשובה גדולה מ-2MB', 502);
    if ($ok === false) throw new ApiError('החיבור נכשל: ' . $err, 502);
    return [$code, $buf];
}

/** כותרות שהמשתמש כתב, שורה לכל כותרת: "Name: value". */
function parse_headers(string $text): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', $text) as $line) {
        if (!preg_match('/^\s*([A-Za-z0-9-]{1,60})\s*:\s*(.{1,1000}?)\s*$/', $line, $m)) continue;
        if (in_array(strtolower($m[1]), ['host', 'content-length', 'transfer-encoding'], true)) continue;
        $out[] = $m[1] . ': ' . $m[2];
    }
    return array_slice($out, 0, 10);
}

/** הולך בנתיב "data.items" בתוך התשובה. ריק — התשובה עצמה. */
function json_path($data, string $path)
{
    foreach (array_filter(explode('.', $path), 'strlen') as $k) {
        if (!is_array($data) || !array_key_exists($k, $data)) return null;
        $data = $data[$k];
    }
    return $data;
}

/** רשומה → מפתחות שטוחים עם ערכים פשוטים ("address.city"), שתי רמות. */
function flat_row($row, string $pre = '', int $depth = 0): array
{
    if (!is_array($row)) return $pre === '' ? ['value' => is_scalar($row) ? (string)$row : ''] : [];
    $out = [];
    foreach ($row as $k => $v) {
        $key = $pre . $k;
        if (is_array($v)) { if ($depth < 1) $out += flat_row($v, $key . '.', $depth + 1); continue; }
        $out[mb_substr((string)$key, 0, 80)] = is_bool($v) ? ($v ? '1' : '') : mb_substr((string)$v, 0, 1000);
    }
    return $out;
}

// ── מקורות נתונים ────────────────────────────────────────────

function clean_source(array $b): array
{
    $url = trim((string)($b['url'] ?? ''));
    if (strlen($url) > 1000) throw new ApiError('הכתובת ארוכה מדי', 422);
    safe_target($url);
    return [
        'url' => $url,
        'headers' => mb_substr((string)($b['headers'] ?? ''), 0, 4000),
        'path' => mb_substr(trim((string)($b['path'] ?? '')), 0, 120),
        'value_key' => mb_substr(trim((string)($b['value_key'] ?? '')), 0, 80),
        'label_key' => mb_substr(trim((string)($b['label_key'] ?? '')), 0, 80),
    ];
}

function src_row(int $id, array $user): array
{
    $st = db()->prepare('SELECT * FROM data_sources WHERE id = ? AND owner_id = ? AND archived_at IS NULL');
    $st->execute([$id, $user['id']]);
    return $st->fetch() ?: throw new ApiError('המקור לא נמצא', 404);
}

function src_view(array $s, bool $withSecrets = false): array
{
    $c = json_decode((string)unseal($s['config_enc']), true) ?: [];
    $rows = $s['rows_enc'] ? (json_decode((string)unseal($s['rows_enc']), true) ?: []) : [];
    $keys = [];
    foreach (array_slice($rows, 0, 50) as $r) $keys += array_flip(array_keys($r));
    return [
        'id' => (int)$s['id'], 'name' => unseal($s['name_enc']), 'url' => $c['url'] ?? '', 'path' => $c['path'] ?? '',
        'value_key' => $c['value_key'] ?? '', 'label_key' => $c['label_key'] ?? '',
        // הכותרות (סיסמאות, אסימונים) — רק למי שעורך את המקור; בשאר המקומות רק "יש"
        'headers' => $withSecrets ? ($c['headers'] ?? '') : null, 'has_headers' => trim($c['headers'] ?? '') !== '',
        'ttl_min' => (int)$s['ttl_min'], 'row_count' => (int)$s['row_count'], 'fetched_at' => $s['fetched_at'],
        'last_error' => $s['last_error'], 'keys' => array_keys($keys), 'sample' => array_slice($rows, 0, 5),
    ];
}

function src_list(array $user): array
{
    $st = db()->prepare('SELECT * FROM data_sources WHERE owner_id = ? AND archived_at IS NULL ORDER BY id');
    $st->execute([$user['id']]);
    return ['ok' => true, 'sources' => array_map(fn($s) => src_view($s, true), $st->fetchAll())];
}

function src_save(array $user, array $b): array
{
    $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 80);
    if ($name === '') throw new ApiError('חסר שם למקור', 422);
    $c = clean_source($b);
    $ttl = max(1, min(10080, (int)($b['ttl_min'] ?? 60)));
    if (!empty($b['id'])) {
        $s = src_row((int)$b['id'], $user);
        db()->prepare('UPDATE data_sources SET name_enc = ?, config_enc = ?, ttl_min = ? WHERE id = ?')
            ->execute([seal($name), seal(json_encode($c, JSON_UNESCAPED_UNICODE)), $ttl, $s['id']]);
        $id = (int)$s['id'];
    } else {
        $n = db()->prepare('SELECT COUNT(*) FROM data_sources WHERE owner_id = ? AND archived_at IS NULL');
        $n->execute([$user['id']]);
        if ((int)$n->fetchColumn() >= 50) throw new ApiError('עד 50 מקורות', 409);
        db()->prepare('INSERT INTO data_sources (owner_id, name_enc, config_enc, ttl_min, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$user['id'], seal($name), seal(json_encode($c, JSON_UNESCAPED_UNICODE)), $ttl, now_iso()]);
        $id = (int)db()->lastInsertId();
    }
    // משיכה מיד — כך רואים אם זה עובד, ואילו שדות יש ברשומות
    try { src_refresh(src_row($id, $user)); } catch (ApiError) { /* השגיאה נשמרה ב-last_error */ }
    return ['ok' => true, 'source' => src_view(src_row($id, $user), true)];
}

function src_delete(array $user, array $b): array
{
    $s = src_row((int)($b['id'] ?? 0), $user);
    db()->prepare('UPDATE data_sources SET archived_at = ?, rows_enc = NULL WHERE id = ?')->execute([now_iso(), $s['id']]);
    return ['ok' => true];
}

function src_sync(array $user, array $b): array
{
    $s = src_row((int)($b['id'] ?? 0), $user);
    src_refresh($s);
    return ['ok' => true, 'source' => src_view(src_row((int)$s['id'], $user), true)];
}

/** מושך את המקור עכשיו ושומר. שגיאה — נשמרת, והעותק הקודם נשאר. */
function src_refresh(array $s): array
{
    $c = json_decode((string)unseal($s['config_enc']), true) ?: [];
    try {
        [$code, $body] = http_call('GET', (string)$c['url'], parse_headers((string)($c['headers'] ?? '')));
        if ($code < 200 || $code >= 300) throw new ApiError("המקור החזיר HTTP $code", 502);
        $data = json_decode($body, true);
        if ($data === null) throw new ApiError('התשובה אינה JSON', 502);
        $list = json_path($data, (string)($c['path'] ?? ''));
        if (!is_array($list) || !array_is_list($list)) {
            throw new ApiError('לא נמצאה רשימה' . (($c['path'] ?? '') !== '' ? ' בנתיב "' . $c['path'] . '"' : ' — כתבו נתיב (למשל data.items)'), 422);
        }
        $rows = array_map('flat_row', array_slice($list, 0, SRC_MAX_ROWS));
    } catch (ApiError $e) {
        db()->prepare('UPDATE data_sources SET last_error = ? WHERE id = ?')->execute([mb_substr($e->getMessage(), 0, 300), $s['id']]);
        throw $e;
    }
    db()->prepare('UPDATE data_sources SET rows_enc = ?, row_count = ?, fetched_at = ?, last_error = NULL WHERE id = ?')
        ->execute([seal(json_encode($rows, JSON_UNESCAPED_UNICODE)), count($rows), now_iso(), $s['id']]);
    return $rows;
}

/** הרשומות של מקור — מהעותק, ומרענן אם עבר זמן התוקף. כשל רענון — העותק הקודם. */
function source_rows(int $id): array
{
    static $memo = [];   // בתוך בקשה אחת: לפענח פעם אחת
    $st = db()->prepare('SELECT * FROM data_sources WHERE id = ? AND archived_at IS NULL');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) return [];
    $stale = !$s['fetched_at'] || strtotime($s['fetched_at']) < time() - 60 * (int)$s['ttl_min'];
    if ($stale) {
        try { return $memo[$id . '@' . now_iso()] = src_refresh($s); } catch (ApiError) { /* נופלים לעותק */ }
    }
    return $memo[$id . '@' . $s['fetched_at']] ??= $s['rows_enc'] ? (json_decode((string)unseal($s['rows_enc']), true) ?: []) : [];
}

// ── שדה "בחירה מרשימה" ───────────────────────────────────────

/** ניקוי ההגדרות של שדה בחירה (נקרא מ-clean_schema). */
/**
 * מקורות שמותר לתבנית להפנות אליהם: של מי ששומר, ומה שכבר היה בה (שותף שעורך
 * תבנית לא מאבד את המקור של הבעלים — אבל גם לא יכול להפנות למקור של מישהו אחר).
 */
function allowed_sources(array $user, array $t): array
{
    $st = db()->prepare('SELECT id FROM data_sources WHERE owner_id = ? AND archived_at IS NULL');
    $st->execute([$user['id']]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    foreach ((json_decode((string)unseal($t['schema_enc']), true) ?: [])['fields'] ?? [] as $f) {
        if (!empty($f['source']['id'])) $ids[] = (int)$f['source']['id'];
    }
    return array_values(array_unique($ids));
}

function clean_select(array $f, callable $str, array $allowed): array
{
    $opts = [];
    foreach (array_slice((array)($f['options'] ?? []), 0, SELECT_MAX_OPTIONS) as $o) {
        $v = $str(is_array($o) ? ($o['value'] ?? '') : $o, 200);
        if ($v === '' || in_array($v, array_column($opts, 'value'), true)) continue;
        $opts[] = ['value' => $v, 'label' => $str(is_array($o) ? ($o['label'] ?? '') : '', 200) ?: $v];
    }
    $src = null;
    if (is_array($f['source'] ?? null)) {
        // מקור — רק מהמותרים; מקור שעוד לא נבחר (0) נשמר כ"ממערכת חיצונית" בלי מקור
        $id = (int)($f['source']['id'] ?? 0);
        if ($id === 0 || in_array($id, $allowed, true)) {
            $fills = [];
            foreach (array_slice((array)($f['source']['fills'] ?? []), 0, 30) as $m) {
                $k = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($m['key'] ?? '')));
                $from = $str($m['from'] ?? '', 80);
                if ($k !== '' && $from !== '') $fills[] = ['key' => $k, 'from' => $from];
            }
            $src = ['id' => $id, 'value_key' => $str($f['source']['value_key'] ?? '', 80),
                'label_key' => $str($f['source']['label_key'] ?? '', 80), 'fills' => $fills];
        }
    }
    return ['options' => $opts, 'source' => $src];
}

/** האפשרויות של שדה בחירה: [{value, label, data?}]. data — רק מה שממלא שדות נוספים. */
function select_options(array $f, ?array $allowedKeys = null): array
{
    if (!empty($f['list'])) return array_map(fn($v) => ['value' => $v, 'label' => $v], list_level_values($f['list']));
    if (empty($f['source'])) return $f['options'] ?? [];
    $src = $f['source'];
    $st = db()->prepare('SELECT config_enc FROM data_sources WHERE id = ?');
    $st->execute([$src['id']]);
    $c = json_decode((string)unseal((string)$st->fetchColumn()), true) ?: [];
    $vk = $src['value_key'] ?: ($c['value_key'] ?? '') ?: 'id';
    $lk = $src['label_key'] ?: ($c['label_key'] ?? '') ?: $vk;
    $fills = array_filter($src['fills'] ?? [], fn($m) => $allowedKeys === null || in_array($m['key'], $allowedKeys, true));
    $out = [];
    foreach (source_rows((int)$src['id']) as $r) {
        $v = (string)($r[$vk] ?? '');
        if ($v === '') continue;
        $o = ['value' => $v, 'label' => (string)($r[$lk] ?? '') ?: $v];
        if ($fills) $o['data'] = array_combine(array_column($fills, 'key'), array_map(fn($m) => (string)($r[$m['from']] ?? ''), $fills));
        $out[] = $o;
    }
    return $out;
}

/** מצרף לשדות הבחירה את האפשרויות (לתצוגה). $allowedKeys — השדות שהצופה רשאי לראות. */
function attach_options(array $schema, ?array $allowedKeys = null): array
{
    foreach ($schema['fields'] ?? [] as $i => $f) {
        if ($f['type'] !== 'select') continue;
        $schema['fields'][$i]['options'] = select_options($f, $allowedKeys);
        if (!empty($f['source'])) $schema['fields'][$i]['source'] = ['id' => $f['source']['id'], 'external' => true];
    }
    return $schema;
}

/** מה להציג לערך שנבחר: התווית. */
function select_label(array $f, string $v): string
{
    if ($v === '') return '';
    foreach (select_options($f) as $o) if ($o['value'] === $v) return $o['label'];
    return $v;
}

/** בדיקת ערך: חייב להיות ברשימה. מקור שלא זמין — לא חוסמים את הממלא. */
function clean_select_value(array $f, string $v): string
{
    $v = trim($v);
    if ($v === '') return '';
    $opts = select_options($f);
    if (!$opts && !empty($f['source'])) return mb_substr($v, 0, 200);
    if (!in_array($v, array_column($opts, 'value'), true)) throw new ApiError('"' . $f['label'] . '": הערך אינו ברשימה', 422);
    return $v;
}

/** בחירה ממקור שממלאת שדות נוספים — בצד השרת (הנפקה דרך API). לא דורסת ערך שנמסר. */
function apply_select_fills(array $schema, array $values, array $given): array
{
    $byKey = array_column($schema['fields'] ?? [], null, 'key');
    foreach ($schema['fields'] ?? [] as $f) {
        if ($f['type'] !== 'select' || empty($f['source']['fills']) || ($values[$f['key']] ?? '') === '') continue;
        foreach (select_options($f) as $o) {
            if ($o['value'] !== $values[$f['key']]) continue;
            foreach ($o['data'] ?? [] as $k => $v) {
                if (isset($byKey[$k]) && !array_key_exists($k, $given) && !in_array($byKey[$k]['type'], ['calc', 'system', 'table', 'file', 'signature'], true)) $values[$k] = $v;
            }
        }
    }
    return $values;
}

// ── מפתחות API ───────────────────────────────────────────────

function key_view(array $k): array
{
    return ['id' => (int)$k['id'], 'name' => unseal($k['name_enc']), 'prefix' => $k['prefix'],
        'webhook' => $k['webhook_enc'] ? unseal($k['webhook_enc']) : '', 'created_at' => $k['created_at'],
        'last_used_at' => $k['last_used_at'], 'revoked' => $k['revoked_at'] !== null];
}

function key_list(array $user): array
{
    $st = db()->prepare('SELECT * FROM api_keys WHERE owner_id = ? ORDER BY revoked_at IS NOT NULL, id DESC');
    $st->execute([$user['id']]);
    return ['ok' => true, 'keys' => array_map('key_view', $st->fetchAll()), 'base' => app_base_url()];
}

function clean_webhook($url): ?string
{
    $url = trim((string)$url);
    if ($url === '') return null;
    if (strlen($url) > 1000) throw new ApiError('כתובת ארוכה מדי', 422);
    safe_target($url);
    return $url;
}

/** מפתח חדש. המפתח עצמו מוצג פעם אחת — נשמר רק ה-hash שלו. */
function key_create(array $user, array $b): array
{
    $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 80) ?: 'מערכת חיצונית';
    $n = db()->prepare('SELECT COUNT(*) FROM api_keys WHERE owner_id = ? AND revoked_at IS NULL');
    $n->execute([$user['id']]);
    if ((int)$n->fetchColumn() >= 20) throw new ApiError('עד 20 מפתחות פעילים', 409);
    $key = API_KEY_PREFIX . bin2hex(random_bytes(24));
    $secret = 'whsec_' . bin2hex(random_bytes(20));
    $hook = clean_webhook($b['webhook'] ?? '');
    db()->prepare('INSERT INTO api_keys (owner_id, name_enc, key_hash, prefix, webhook_enc, secret_enc, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$user['id'], seal($name), token_hash($key), substr($key, 0, 10), $hook ? seal($hook) : null, seal($secret), now_iso()]);
    return ['ok' => true, 'key' => $key, 'secret' => $secret, 'id' => (int)db()->lastInsertId()];
}

function key_row(array $user, int $id): array
{
    $st = db()->prepare('SELECT * FROM api_keys WHERE id = ? AND owner_id = ?');
    $st->execute([$id, $user['id']]);
    return $st->fetch() ?: throw new ApiError('המפתח לא נמצא', 404);
}

function key_update(array $user, array $b): array
{
    $k = key_row($user, (int)($b['id'] ?? 0));
    $hook = clean_webhook($b['webhook'] ?? '');
    $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 80) ?: unseal($k['name_enc']);
    db()->prepare('UPDATE api_keys SET name_enc = ?, webhook_enc = ? WHERE id = ?')->execute([seal($name), $hook ? seal($hook) : null, $k['id']]);
    return ['ok' => true];
}

function key_secret(array $user, array $b): array
{
    return ['ok' => true, 'secret' => unseal(key_row($user, (int)($b['id'] ?? 0))['secret_enc'])];
}

function key_revoke(array $user, array $b): array
{
    $k = key_row($user, (int)($b['id'] ?? 0));
    db()->prepare('UPDATE api_keys SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL')->execute([now_iso(), $k['id']]);
    return ['ok' => true];
}

/** בדיקת webhook: שולח הודעת ניסיון ומחזיר את קוד התשובה. */
function key_test(array $user, array $b): array
{
    $k = key_row($user, (int)($b['id'] ?? 0));
    if (!$k['webhook_enc']) throw new ApiError('אין כתובת webhook למפתח', 422);
    $code = webhook_send($k, ['event' => 'test', 'at' => now_iso()]);
    return ['ok' => true, 'status' => $code];
}

/** המשתמש שמאחורי המפתח שבכותרת Authorization. */
function api_auth(): array
{
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '');
    $key = preg_replace('/^Bearer\s+/i', '', trim($h));
    if (!preg_match('/^' . API_KEY_PREFIX . '[0-9a-f]{48}$/', $key)) throw new ApiError('חסר מפתח API (Authorization: Bearer ...)', 401);
    $st = db()->prepare('SELECT k.*, u.status AS user_status FROM api_keys k JOIN users u ON u.id = k.owner_id WHERE k.key_hash = ?');
    $st->execute([token_hash($key)]);
    $k = $st->fetch();
    if (!$k || $k['revoked_at'] || $k['user_status'] !== 'active') throw new ApiError('המפתח אינו תקף', 401);
    // הגבלת קצב: עד 600 בקשות בשעה למפתח
    $win = 'api:' . $k['id'] . ':' . gmdate('YmdH');
    db()->prepare('INSERT INTO login_attempts (key, failures, locked_until) VALUES (?, 1, NULL) ON CONFLICT(key) DO UPDATE SET failures = failures + 1')->execute([$win]);
    $n = db()->prepare('SELECT failures FROM login_attempts WHERE key = ?');
    $n->execute([$win]);
    if ((int)$n->fetchColumn() > 600) throw new ApiError('יותר מדי בקשות — נסו בעוד שעה', 429);
    db()->prepare('UPDATE api_keys SET last_used_at = ? WHERE id = ?')->execute([now_iso(), $k['id']]);
    $u = db()->prepare('SELECT * FROM users WHERE id = ?');
    $u->execute([$k['owner_id']]);
    return [$u->fetch(), $k];
}

// ── ה-API החיצוני ────────────────────────────────────────────

/** השדות של תבנית — כמו שמערכת חיצונית צריכה אותם. */
function ext_fields(array $schema): array
{
    return array_values(array_map(fn($f) => array_filter([
        'key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'], 'required' => $f['required'],
        'role' => $f['role'] ?? 'owner', 'roles' => field_roles($f), 'placeholder' => $f['placeholder'] ?? '' ?: null,
        'auto' => in_array($f['type'], ['calc', 'system'], true) ?: null,
        'options' => $f['type'] === 'select' ? array_map(fn($o) => ['value' => $o['value'], 'label' => $o['label']], select_options($f)) : null,
        'columns' => $f['type'] === 'table' ? array_map(fn($c) => ['key' => $c['key'], 'label' => $c['label'], 'type' => $c['type']], $f['columns']) : null,
    ], fn($v) => $v !== null), $schema['fields'] ?? []));
}

function ext_templates(array $user): array
{
    $st = db()->prepare('SELECT t.id, t.name_enc, t.current_version, t.source, v.schema_enc
        FROM templates t JOIN template_versions v ON v.template_id = t.id AND v.version = t.current_version
        WHERE t.owner_id = ? AND t.archived_at IS NULL AND t.deleted_at IS NULL ORDER BY t.id');
    $st->execute([$user['id']]);
    return ['ok' => true, 'templates' => array_map(function ($t) {
        $s = json_decode((string)unseal($t['schema_enc']), true) ?: [];
        return ['id' => (int)$t['id'], 'name' => unseal($t['name_enc']), 'version' => (int)$t['current_version'],
            'kind' => KINDS[$t['source']] ?? 'pdf', 'fields' => count($s['fields'] ?? [])];
    }, $st->fetchAll())];
}

const KINDS = ['docx' => 'word', 'authored' => 'authored', 'pdf_parsed' => 'pdf', 'pdf_locked' => 'pdf', 'image' => 'image'];

function kind_of(string $mime): string
{
    return $mime === DOCX_MIME ? 'word' : ($mime === AUTHORED_MIME ? 'authored' : ($mime === 'application/pdf' ? 'pdf' : 'image'));
}

function ext_template(array $user, array $q): array
{
    $t = tpl_owned((int)($q['id'] ?? 0), $user, 'view');
    $s = json_decode((string)unseal($t['schema_enc']), true) ?: [];
    return ['ok' => true, 'template' => ['id' => (int)$t['id'], 'name' => unseal($t['name_enc']), 'version' => (int)$t['current_version'],
        'kind' => KINDS[$t['source']] ?? 'pdf',
        'roles' => array_map(fn($r) => ['key' => $r['key'], 'label' => $r['label']], $s['roles'] ?? []),
        'fields' => ext_fields($s)]];
}

/** ערכים שמערכת חיצונית שלחה: לפי מפתח, סימון ({{...}}) או תווית. */
function ext_map_values(array $schema, array $in): array
{
    $out = [];
    foreach ($schema['fields'] ?? [] as $f) {
        foreach ([$f['key'], $f['placeholder'] ?? '', $f['label']] as $name) {
            if ($name !== '' && array_key_exists($name, $in)) {
                $v = $in[$name];
                $out[$f['key']] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
                break;
            }
        }
    }
    return $out;
}

/** הנפקה: מסמך מתבנית, עם ערכים, ואופציונלית קישורים לממלאים. */
function ext_issue(array $user, array $key, array $b): array
{
    $ref = mb_substr(trim((string)($b['ref'] ?? '')), 0, 120);
    if ($ref !== '') {
        // אותו מזהה מהמערכת החיצונית — אותו מסמך (בקשה כפולה לא יוצרת שניים)
        $st = db()->prepare('SELECT id FROM documents WHERE owner_id = ? AND external_ref = ? AND deleted_at IS NULL');
        $st->execute([$user['id'], $ref]);
        if ($id = $st->fetchColumn()) return ['ok' => true, 'existing' => true] + ext_document($user, ['id' => $id])['document'];
    }
    $r = doc_create($user, ['template_id' => $b['template_id'] ?? 0, 'title' => $b['title'] ?? '']);
    $id = (int)$r['id'];
    $sender = mb_substr(trim((string)($b['sender_name'] ?? '')), 0, 80);
    db()->prepare('UPDATE documents SET api_key_id = ?, external_ref = ?, sender_enc = ? WHERE id = ?')
        ->execute([$key['id'], $ref !== '' ? $ref : null, $sender !== '' ? seal($sender) : null, $id]);
    log_event($id, 'user:' . $user['id'], 'api_issued', ['key' => unseal($key['name_enc']), 'ref' => $ref]);

    $d = doc_owned($id, $user, 'owner');
    $schema = doc_schema($d);
    $given = ext_map_values($schema, (array)($b['values'] ?? []));
    $values = apply_select_fills($schema, $given, $given);
    if ($values) doc_save($user, ['id' => $id, 'values' => $values]);
    if (!empty($b['tag'])) doc_tags($user, ['id' => $id, 'tags' => [(string)$b['tag']]]);

    $links = [];
    foreach ((array)($b['participants'] ?? []) as $p) {
        $role = (string)($p['role'] ?? '');
        $st = db()->prepare('SELECT id FROM participants WHERE document_id = ? AND role_key = ?');
        $st->execute([$id, $role]);
        $pid = (int)$st->fetchColumn();
        if (!$pid) throw new ApiError("אין בתבנית ממלא בשם \"$role\"", 422);
        part_contact($user, ['id' => $id, 'participant_id' => $pid] + array_intersect_key($p, array_flip(['name', 'email', 'phone'])));
        $send = $p['send'] ?? 'link';
        if ($send === false || $send === 'none') continue;
        $links[$role] = part_link($user, ['id' => $id, 'participant_id' => $pid, 'via' => $send === 'email' ? 'email' : 'link'])['url'];
    }
    return ['ok' => true, 'existing' => false, 'links' => $links] + ext_document($user, ['id' => $id])['document'];
}

function ext_find(array $user, array $q): array
{
    if (!empty($q['ref'])) {
        $st = db()->prepare('SELECT id FROM documents WHERE owner_id = ? AND external_ref = ? AND deleted_at IS NULL');
        $st->execute([$user['id'], (string)$q['ref']]);
        $q['id'] = (int)$st->fetchColumn();
    }
    return doc_owned((int)($q['id'] ?? 0), $user, 'view');
}

function ext_document(array $user, array $q): array
{
    $d = ext_find($user, $q);
    $schema = doc_schema($d);
    $values = doc_values((int)$d['id']);
    $by = array_column($schema['fields'] ?? [], null, 'key');
    $labels = [];
    foreach ($values as $k => $v) if (isset($by[$k]) && $by[$k]['type'] === 'select') $labels[$k] = select_label($by[$k], $v);
    $a = tpl_asset_row((int)$d['template_version_id']);
    return ['ok' => true, 'document' => [
        'id' => (int)$d['id'], 'number' => $d['number'], 'title' => unseal($d['title_enc']), 'status' => $d['status'],
        'ref' => $d['external_ref'], 'template_id' => (int)$d['template_id'], 'kind' => kind_of((string)$a['mime']),
        'created_at' => $d['created_at'], 'completed_at' => $d['completed_at'], 'final_hash' => $d['final_hash'],
        'values' => $values, 'labels' => (object)$labels, 'progress' => doc_progress($schema, $values),
        'participants' => array_map(fn($p) => ['role' => $p['role_key'], 'label' => role_of($schema, $p['role_key'])['label'],
            'status' => $p['status'], 'opened_at' => $p['opened_at'], 'completed_at' => $p['completed_at']], participants_of((int)$d['id'])),
        'url' => app_base_url() . '#doc=' . $d['id'],
    ]];
}

/** עדכון ערכים במסמך שעוד לא הושלם (סנכרון מהמערכת החיצונית). */
function ext_update(array $user, array $b): array
{
    $d = ext_find($user, $b);
    $schema = doc_schema($d);
    $given = ext_map_values($schema, (array)($b['values'] ?? []));
    if ($given) doc_save($user, ['id' => (int)$d['id'], 'values' => apply_select_fills($schema, $given + doc_values((int)$d['id']), $given)]);
    return ext_document($user, ['id' => $d['id']]);
}

/** קובץ ממולא: Word (תבנית Word או שנכתבה בכלי). */
function ext_file(array $user, array $q): never
{
    $d = ext_find($user, $q);
    $a = tpl_asset_row((int)$d['template_version_id']);
    if ($a['mime'] !== DOCX_MIME && $a['mime'] !== AUTHORED_MIME) {
        throw new ApiError('קובץ ממולא דרך ה-API — לתבנית Word או שנכתבה בכלי. מתבנית PDF: הערכים ב-document, והקובץ — מהמסמך במערכת', 409);
    }
    doc_docx($user, (int)$d['id']);
}

// ── webhook ──────────────────────────────────────────────────

/** שולח הודעה חתומה לכתובת של המפתח. מחזיר את קוד התשובה (0 — נכשל). */
function webhook_send(array $key, array $payload): int
{
    $url = (string)unseal((string)$key['webhook_enc']);
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $sig = 'sha256=' . hash_hmac('sha256', $body, (string)unseal($key['secret_enc']));
    try {
        [$code] = http_call('POST', $url, ['Content-Type: application/json', 'X-DT-Event: ' . $payload['event'], 'X-DT-Signature: ' . $sig], $body, 5);
        return $code;
    } catch (ApiError) {
        return 0;
    }
}

/** אירוע במסמך שהונפק דרך מפתח → הודעה למערכת שהנפיקה. */
function webhook_fire(int $docId, string $event, array $extra = []): void
{
    try {
        $st = db()->prepare('SELECT k.*, d.external_ref, d.number, d.status FROM documents d JOIN api_keys k ON k.id = d.api_key_id
            WHERE d.id = ? AND k.revoked_at IS NULL AND k.webhook_enc IS NOT NULL');
        $st->execute([$docId]);
        $k = $st->fetch();
        if (!$k) return;
        $code = webhook_send($k, ['event' => $event, 'at' => now_iso(), 'document' => [
            'id' => $docId, 'ref' => $k['external_ref'], 'number' => $k['number'], 'status' => $k['status']]] + $extra);
        log_event($docId, 'system', 'webhook', ['event' => $event, 'status' => $code]);
    } catch (Throwable) {
        // הודעה שנכשלה לא מפילה את הפעולה שגרמה לה
    }
}
