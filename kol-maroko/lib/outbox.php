<?php
/**
 * M7 — משיכה במקום דחיפה (docs/spec-v2/slices/M7-pull-not-push.md).
 *
 * כל פעולה שהיום נדחפת לאתר (manifest, switch, activate, mount) נכתבת לתור יוצא חתום (site_outbox), והתוסף **יוצא** אל
 * הכלי (pull.php), מושך, מאמת בדיוק כמו בדחיפה, מחיל ומדווח. אין שום בקשה נכנסת לאתר — ולכן Cloudflare של האתר אינו בדרך.
 *
 * המעטפה — כמו site_post: {action, site, page, time, nonce, …} ועוד שני שדות **בתוך החתימה**: seq (המספר בתור) ו-expires.
 * התוסף מחיל רק seq גדול מהאחרון שהחיל, ורק לפני expires — פקודה לעולם אינה מוחלת פעמיים.
 *
 * אסימון המשיכה (32 בייט) מאפשר **רק** לקרוא את התור ולדווח: נשמר כ-sha256 (setting pull_token_hash), נבדק ב-hash_equals.
 * הפרסום עצמו דורש את החתימה, שהמפתח הפרטי שלה רק כאן.
 */

declare(strict_types=1);

const OUTBOX_ACTIONS = ['manifest', 'switch', 'activate', 'mount'];
const OUTBOX_TTL = ['manifest' => 7 * 86400, 'switch' => 86400, 'activate' => 86400, 'mount' => 86400];
const OUTBOX_LIVE = ['queued', 'delivered'];
const OUTBOX_LABELS = ['manifest' => 'פרסום גרסה', 'switch' => 'המפסק (העמוד המקורי / מהסטודיו)', 'activate' => 'חזרה לגרסה', 'mount' => 'אופן ההצבה'];
const PULL_PAGE = 20;
const PULL_RATE = 60;                       // בקשות לדקה לאסימון
const PULL_CHUNK = 8 * 1024 * 1024;         // גוש — בחלקים של עד 8MB (Range)
const PULL_REPORT_MAX = 65536;
const PULL_WARN = 1800;                     // דופק ישן מ-30 דקות — צהוב
const PULL_DEAD = 86400;                    // מ-24 שעות — אדום, ובבדיקות התקינות
const PULL_WAKE_PATH = '/?kol-maroko-wake=1';
/** מה שהתוסף מדווח על עצמו — רק המפתחות האלה נשמרים (רשימה סגורה; כל השאר נזרק). */
const PULL_STATE_KEYS = ['plugin' => 's', 'protocol' => 'i', 'version' => 'i', 'live' => 'i', 'enabled' => 'b', 'page_set' => 'b',
    'v2_version' => 'i', 'v2_live' => 'i', 'v2_on' => 'b', 'v2_has_live' => 'b', 'v2_preview' => 'b', 'v2_mount' => 's', 'v2_stats' => 'a',
    'cursor' => 'i', 'interval' => 'i', 'cron' => 'b', 'last_run' => 'i', 'last_result' => 's', 'at' => 'i'];

// ── הערוץ ─────────────────────────────────────────────────

/** push — היום (POST לאתר); pull — התור. ברירת המחדל: pull לאתר הזה (maghreb), push לכל אתר אחר (בדיקות, אתר בלי Cloudflare). */
function pub_channel(): string
{
    $c = setting('pub_channel');
    if ($c === 'push' || $c === 'pull') return $c;
    return site_host() === 'maghreb.co.il' ? 'pull' : 'push';
}

function pub_channel_set(array $owner, array $b): array
{
    $c = (string)($b['channel'] ?? '');
    if (!in_array($c, ['push', 'pull'], true)) throw new ApiError('ערוץ לא מוכר', 422);
    setting_set('pub_channel', $c);
    audit_as($owner, 'pub_channel', $c === 'pull' ? 'ערוץ: משיכה (האתר מושך מהכלי)' : 'ערוץ: דחיפה (הכלי שולח לאתר)');
    return publish_settings();
}

// ── התור ──────────────────────────────────────────────────

/**
 * פקודה לתור. $payload — מערך, או Closure(seq, expires): array (המניפסט צריך את ה-seq בתוכו). פקודה חדשה מאותו סוג מסמנת את
 * הקודמת שעוד לא הוחלה כ-superseded. מחזיר תשובה בצורה של site_call: {ok, queued, seq, expires, state}.
 */
function outbox_add(string $action, array|Closure $payload, ?array $user, array $meta = []): array
{
    if (!in_array($action, OUTBOX_ACTIONS, true)) throw new ApiError('פעולה שאינה בתור: ' . $action, 500);
    $pdo = db();
    $k = pub_key();
    $exp = time() + OUTBOX_TTL[$action];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO site_outbox (action, created_at, created_by, expires, ref, label) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$action, now_iso(), (string)($user['name'] ?? ''), $exp, isset($meta['ref']) ? (string)$meta['ref'] : null, mb_substr((string)($meta['label'] ?? ''), 0, 200)]);
        $seq = (int)$pdo->lastInsertId();
        $p = $payload instanceof Closure ? $payload($seq, $exp) : $payload;
        $env = ['action' => $action, 'site' => site_host(), 'page' => PUB_PAGE, 'time' => time(), 'nonce' => bin2hex(random_bytes(16)), 'seq' => $seq, 'expires' => $exp] + $p;
        $body = json_encode($env, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sig = base64_encode(sodium_crypto_sign_detached($body, base64_decode($k['secret'])));
        $pdo->prepare('UPDATE site_outbox SET body = ?, sig = ?, kid = ? WHERE seq = ?')->execute([$body, $sig, $k['kid'], $seq]);
        $old = $pdo->prepare("SELECT * FROM site_outbox WHERE action = ? AND seq < ? AND state IN ('queued','delivered')");
        $old->execute([$action, $seq]);
        $superseded = $old->fetchAll();
        $pdo->prepare("UPDATE site_outbox SET state = 'superseded', result = ? WHERE action = ? AND seq < ? AND state IN ('queued','delivered')")
            ->execute([json_encode(['by' => $seq], JSON_UNESCAPED_UNICODE), $action, $seq]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    foreach ($superseded as $row) outbox_effect($row, 'superseded', ['by' => $seq]);
    outbox_wake();
    return ['ok' => true, 'queued' => true, 'channel' => 'pull', 'seq' => $seq, 'expires' => $exp, 'state' => 'queued'];
}

/** פג תוקף ⇒ expired (ולא יוגש עוד). רץ בכל משיכה ובכל קריאה של התור. */
function outbox_expire(): void
{
    $st = db()->prepare("SELECT * FROM site_outbox WHERE state IN ('queued','delivered') AND expires < ?");
    $st->execute([time()]);
    foreach ($st->fetchAll() as $row) {
        $res = ['code' => 'expired', 'message' => 'פג התוקף לפני שהאתר משך'];
        db()->prepare("UPDATE site_outbox SET state = 'expired', result = ? WHERE seq = ? AND state IN ('queued','delivered')")
            ->execute([json_encode($res, JSON_UNESCAPED_UNICODE), $row['seq']]);
        outbox_effect($row, 'expired', $res);
    }
}

/**
 * מה שינוי מצב עושה בכלי: manifest שהוחל — הבנייה "באתר" (רק עכשיו, לא כשנכנסה לתור); שנכשל / פג — שגיאה בבנייה.
 * mount שהוחל — האופן נשמר. כל מעבר — ביומן הפעולות.
 */
function outbox_effect(array $row, string $state, array $res): void
{
    require_once __DIR__ . '/build.php';
    $site = ['sub' => 'site', 'name' => 'האתר', 'id' => null];
    $seq = (int)$row['seq'];
    $label = OUTBOX_LABELS[$row['action']] ?? $row['action'];
    $env = json_decode((string)$row['body'], true) ?: [];
    if ($row['action'] === 'manifest' && $row['ref'] !== null) {
        try { $b = build_row((int)$row['ref']); } catch (ApiError) { $b = null; }
        if ($b && $state === 'applied' && $b['status'] !== 'live') {
            $man = (string)($env['manifest'] ?? '');
            build_mark_live($b, ['sub' => $b['by_sub'], 'name' => (string)($row['created_by'] ?: $b['by_name']), 'role' => 'publisher'], $man, -1, null, $seq);
        } elseif ($b && in_array($state, ['failed', 'expired'], true) && $b['status'] === 'sending') {
            build_save((int)$b['id'], ['error' => build_json(['msg' => outbox_reason($res), 'code' => $res['code'] ?? $state, 'send' => true, 'seq' => $seq])]);
        }
    }
    if ($row['action'] === 'mount' && $state === 'applied' && isset(BUILD_MOUNTS[$env['mount'] ?? ''])) setting_set('site_mount', (string)$env['mount']);
    if ($state === 'superseded') return;                         // רק החלפה בתור — הפעולה החדשה היא שנרשמת
    $what = ['applied' => '✓ הוחל באתר', 'failed' => '✗ נכשל באתר', 'expired' => '⌛ פג תוקף'][$state] ?? $state;
    audit_write($site, 'outbox_' . $state, "#$seq · $label · $what" . ($state === 'applied' ? '' : ' · ' . outbox_reason($res)), 'system');
}

/** הסבר בעברית לקוד שהתוסף החזיר. */
function outbox_reason(array $res): string
{
    $code = (string)($res['code'] ?? '');
    $map = [
        'expired' => 'פג התוקף לפני שהאתר משך — "שלח שוב"', 'blob_mismatch' => 'קובץ הגיע פגום שלוש פעמים (ה-hash לא תאם). הגרסה שבאוויר לא נגעה',
        'bad_signature' => 'האתר דחה את החתימה — המפתח בתוסף אינו המפתח שכאן', 'wrong_site' => 'האתר לא מזהה את עצמו בכתובת שבהגדרות',
        'old_version' => 'באתר כבר יש גרסה חדשה יותר', 'asset_missing' => 'חסר באתר קובץ שהגרסה מפנה אליו', 'quota' => 'מעבר למכסה של התוסף',
        'blob_http' => 'האתר לא הצליח להוריד קובץ מהכלי', 'replay' => 'הפקודה כבר הוחלה', 'no_page' => 'לא נבחר עמוד בתוסף',
        'no_version' => 'עוד לא התקבלה גרסה להציג', 'not_found' => 'הגרסה אינה שמורה באתר',
    ];
    $msg = $map[$code] ?? ($code !== '' ? 'האתר: ' . $code : 'נכשל');
    $extra = trim((string)($res['message'] ?? ''));
    return $extra !== '' && !isset($map[$code]) ? $msg . ' — ' . mb_substr($extra, 0, 200) : $msg;
}

/** דפיקה לאתר — "יש משהו חדש". במאמץ בלבד: אין בה תוכן, ואם היא נחסמת המשיכה הבאה ב-cron מגיעה בכל מקרה. */
function outbox_wake(): array
{
    $ch = curl_init(site_url_setting() . PULL_WAKE_PATH);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => false, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'KolMaroko/1.0 wake (+https://mbe-plus.com/mini_projects/kol-maroko/)']);
    $ok = curl_exec($ch);
    $r = ['at' => time(), 'status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'error' => $ok === false ? mb_substr(curl_error($ch), 0, 200) : ''];
    curl_close($ch);
    setting_set('pull_wake', json_encode($r, JSON_UNESCAPED_UNICODE));
    return $r;
}

// ── הדופק ─────────────────────────────────────────────────

function pull_heartbeat(): ?array
{
    $h = json_decode((string)setting('site_heartbeat', ''), true);
    return is_array($h) && isset($h['at']) ? $h : null;
}

/** המצב שהתוסף דיווח — מסונן לרשימה הסגורה ולטיפוסים. */
function pull_state_clean(mixed $s): array
{
    if (!is_array($s)) return [];
    $out = [];
    foreach (PULL_STATE_KEYS as $k => $t) {
        if (!array_key_exists($k, $s)) continue;
        $v = $s[$k];
        $out[$k] = match ($t) {
            'i' => is_int($v) || (is_string($v) && ctype_digit($v)) ? min((int)$v, PHP_INT_MAX) : 0,
            'b' => (bool)$v,
            's' => mb_substr(preg_replace('/[\x00-\x1f\x7f<>]/u', '', is_scalar($v) ? (string)$v : ''), 0, 120),
            'a' => is_array($v) && strlen((string)json_encode($v)) <= 4096 ? $v : null,
        };
    }
    if (isset($out['v2_mount']) && !isset(BUILD_MOUNTS[$out['v2_mount']])) unset($out['v2_mount']);
    return $out;
}

function pull_heartbeat_touch(string $kind, string $ip, ?array $state = null, array $more = []): void
{
    $h = pull_heartbeat() ?? [];
    $h = ['at' => time(), 'kind' => $kind, 'ip' => mb_substr($ip, 0, 64), 'state' => $state ?? ($h['state'] ?? []), 'state_at' => $state !== null ? time() : ($h['state_at'] ?? null)] + $more + $h;
    setting_set('site_heartbeat', json_encode($h, JSON_UNESCAPED_UNICODE));
}

/** "דופק האתר" — לממשק ולבדיקת התקינות. level: ok · warn (מעל 30 דקות) · bad (מעל 24 שעות, או שלא משך מעולם). */
function pull_pulse(): array
{
    $h = pull_heartbeat();
    if (!$h) return ['level' => 'bad', 'never' => true, 'text' => 'האתר עוד לא משך מהכלי אף פעם', 'age' => null];
    $age = max(0, time() - (int)$h['at']);
    $s = $h['state'] ?? [];
    $mins = intdiv($age, 60);
    $ago = $age < 90 ? 'לפני פחות מדקה' : ($mins < 120 ? "לפני $mins דקות" : ($age < 2 * 86400 ? 'לפני ' . intdiv($age, 3600) . ' שעות' : 'לפני ' . intdiv($age, 86400) . ' ימים'));
    $mount = isset($s['v2_mount']) ? (BUILD_MOUNTS[$s['v2_mount']]['name'] ?? $s['v2_mount']) : null;
    $parts = array_filter(["האתר משך לאחרונה $ago", isset($s['plugin']) ? 'תוסף ' . $s['plugin'] : null,
        !empty($s['v2_live']) ? 'באוויר: גרסה ' . $s['v2_live'] : null, array_key_exists('v2_on', $s) ? 'המפסק: ' . ($s['v2_on'] ? 'דולק' : 'כבוי') : null,
        $mount ? 'אופן: ' . $mount : null]);
    return ['level' => $age > PULL_DEAD ? 'bad' : ($age > PULL_WARN ? 'warn' : 'ok'), 'never' => false, 'age' => $age, 'at' => (int)$h['at'],
        'text' => implode(' · ', $parts), 'state' => $s, 'ip' => $h['ip'] ?? '', 'kind' => $h['kind'] ?? ''];
}

/** ping בערוץ המשיכה: הכלי אינו שואל — הוא מחזיר מה שהתוסף דיווח (באותם שמות של ping). */
function outbox_ping(): array
{
    $p = pull_pulse();
    if ($p['never']) throw new ApiError('האתר עוד לא משך מהכלי. בתוסף: הדביקו את "שורת החיבור" ולחצו "בדוק חיבור"', 502, ['code' => 'no_heartbeat']);
    return ['ok' => true, 'channel' => 'pull', 'pulled_at' => $p['at'], 'age' => $p['age'], 'pulse' => $p] + $p['state'];
}

// ── אסימון המשיכה ושורת החיבור ────────────────────────────

/** הכתובת של pull.php — כפי שהתוסף יפנה אליה. */
function pull_url(): string
{
    $set = setting('pull_url');
    if ($set) return $set;
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'mbe-plus.com');
    if (!preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/i', $host)) $host = 'mbe-plus.com';
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/mini_projects/kol-maroko/api.php'))), '/');
    return ($https || !preg_match('/^(127\.0\.0\.1|localhost)(:|$)/', $host) ? 'https' : 'http') . "://$host$dir/pull.php";
}

/**
 * "📋 שורת חיבור לתוסף": kol-maroko:2:<kid>:<מפתח ציבורי>:<כתובת pull.php>:<אסימון>. האסימון נוצר כאן, מוצג **פעם אחת**, ונשמר
 * רק כ-sha256. כל יצירה מבטלת את הקודם ("החלף אסימון").
 */
function pull_line(array $owner): array
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    setting_set('pull_token_hash', hash('sha256', $token));
    setting_set('pull_token_at', now_iso());
    $k = pub_key();
    $line = 'kol-maroko:2:' . $k['kid'] . ':' . $k['public'] . ':' . pull_url() . ':' . $token;
    audit_as($owner, 'pull_token', 'אסימון משיכה חדש — הקודם בוטל');
    return ['line' => $line] + publish_settings();
}

function pull_settings(): array
{
    $bad = json_decode((string)setting('pull_bad', ''), true) ?: ['count' => 0];
    return ['channel' => pub_channel(), 'channel_set' => setting('pub_channel') !== null, 'pull_url' => pull_url(),
        'token_at' => setting('pull_token_at'), 'bad' => $bad, 'pulse' => pull_pulse(), 'wake' => json_decode((string)setting('pull_wake', ''), true)];
}

/** האסימון מהבקשה: Authorization: Bearer, או X-Kol-Pull-Token (שרתים שמסירים Authorization לפני PHP). */
function pull_auth(string $ip): void
{
    $hdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $t = preg_match('/^Bearer\s+([A-Za-z0-9_-]{20,128})$/', trim($hdr), $m) ? $m[1] : (string)($_SERVER['HTTP_X_KOL_PULL_TOKEN'] ?? '');
    $want = (string)setting('pull_token_hash', '');
    if ($want !== '' && $t !== '' && strlen($t) <= 128 && hash_equals($want, hash('sha256', $t))) return;
    $bad = json_decode((string)setting('pull_bad', ''), true) ?: ['count' => 0];
    setting_set('pull_bad', json_encode(['count' => (int)$bad['count'] + 1, 'at' => now_iso(), 'ip' => mb_substr($ip, 0, 64), 'had' => $t !== ''], JSON_UNESCAPED_UNICODE));
    throw new ApiError($want === '' ? 'עוד לא נוצר אסימון משיכה' : 'אסימון משיכה שגוי', 401, ['code' => 'bad_token']);
}

/** 60 בקשות לדקה לאסימון (יש אסימון אחד — המונה כללי). */
function pull_rate(): void
{
    $m = intdiv(time(), 60);
    $r = json_decode((string)setting('pull_rate', ''), true);
    $c = is_array($r) && ($r['m'] ?? 0) === $m ? (int)$r['c'] + 1 : 1;
    setting_set('pull_rate', json_encode(['m' => $m, 'c' => $c]));
    if ($c > PULL_RATE) throw new ApiError('יותר מדי בקשות בדקה', 429, ['code' => 'rate']);
}

// ── נקודת המשיכה (pull.php) ───────────────────────────────

/**
 * GET ?after=<seq>: עד 20 פקודות queued / delivered אחרי seq, לפי הסדר. הן מסומנות delivered. התוסף שולח את ה-seq האחרון שהחיל;
 * אם הוא גבוה מכל מה שיש כאן (המסד שוחזר מגיבוי) — המונה קופץ מעליו, ופקודות שממתינות מתחתיו נכנסות שוב במספר חדש.
 */
function pull_items(int $after, string $ip): array
{
    outbox_expire();
    outbox_resync($after);
    $st = db()->prepare("SELECT seq, action, body, sig, kid FROM site_outbox WHERE seq > ? AND state IN ('queued','delivered') AND body <> '' ORDER BY seq LIMIT " . (PULL_PAGE + 1));
    $st->execute([$after]);
    $rows = $st->fetchAll();
    $more = count($rows) > PULL_PAGE;
    $rows = array_slice($rows, 0, PULL_PAGE);
    $mark = db()->prepare("UPDATE site_outbox SET state = 'delivered', delivered_at = ? WHERE seq = ? AND state = 'queued'");
    foreach ($rows as $r) $mark->execute([now_iso(), $r['seq']]);
    pull_heartbeat_touch('pull', $ip, null, ['after' => $after]);
    devlog_write('info', 'pull', 'pull.items', ['message' => 'משיכה', 'data' => ['after' => $after, 'seqs' => array_map(fn($r) => (int)$r['seq'], $rows), 'ip' => $ip]]);
    return ['ok' => true, 'items' => array_map(fn($r) => ['seq' => (int)$r['seq'], 'action' => $r['action'], 'body' => base64_encode($r['body']),
        'sig' => $r['sig'], 'kid' => $r['kid']], $rows), 'more' => $more, 'server_time' => time()];
}

/** גיבוי ששוחזר: התוסף כבר החיל seq גבוה משלנו. המונה עולה, ומה שממתין מתחתיו — נחתם מחדש במספר חדש. */
function outbox_resync(int $after): void
{
    $max = (int)db()->query("SELECT COALESCE(MAX(seq), 0) FROM site_outbox")->fetchColumn();
    $seqRow = db()->query("SELECT seq FROM sqlite_sequence WHERE name = 'site_outbox'")->fetchColumn();
    if ($after <= max($max, (int)$seqRow)) {
        $st = db()->prepare("SELECT * FROM site_outbox WHERE seq <= ? AND state = 'queued' ORDER BY seq");
        $st->execute([$after]);
        $stuck = $st->fetchAll();
        if (!$stuck) return;
    } else {
        if ($seqRow === false) db()->prepare("INSERT INTO sqlite_sequence (name, seq) VALUES ('site_outbox', ?)")->execute([$after]);
        else db()->prepare("UPDATE sqlite_sequence SET seq = ? WHERE name = 'site_outbox'")->execute([$after]);
        $st = db()->prepare("SELECT * FROM site_outbox WHERE state = 'queued' ORDER BY seq");
        $st->execute();
        $stuck = $st->fetchAll();
    }
    foreach ($stuck as $row) {
        $env = json_decode((string)$row['body'], true);
        if (!is_array($env)) continue;
        $payload = array_diff_key($env, array_flip(['action', 'site', 'page', 'time', 'nonce', 'seq', 'expires']));
        $action = (string)$row['action'];
        $fn = $action === 'manifest'
            ? function (int $seq, int $exp) use ($payload) {
                $man = json_decode((string)$payload['manifest'], true);
                $man['seq'] = $seq;
                $man['expires'] = $exp;
                $man['nonce'] = bin2hex(random_bytes(16));
                $bytes = build_json($man);
                $k = pub_key();
                return ['manifest' => $bytes, 'manifest_sig' => base64_encode(sodium_crypto_sign_detached($bytes, base64_decode($k['secret']))), 'kid' => $k['kid']];
            }
            : $payload;
        outbox_add($action, $fn, ['name' => (string)$row['created_by']], ['ref' => $row['ref'], 'label' => $row['label']]);
    }
}

/**
 * GET ?blob=<sha256>: הבייטים של גוש — **רק** sha256 שמופיע במניפסט שבתור (queued / delivered). אחרת 404: אי אפשר להשתמש
 * ב-pull.php כאחסון. Range: bytes=a-b / a- — עד 8MB לתשובה (206). מחזיר [path, size, start, end] לשליחה.
 */
function pull_blob_find(string $sha): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $sha)) return null;
    require_once __DIR__ . '/build.php';
    $st = db()->query("SELECT body, ref FROM site_outbox WHERE action = 'manifest' AND state IN ('queued','delivered') ORDER BY seq DESC");
    foreach ($st->fetchAll() as $row) {
        $env = json_decode((string)$row['body'], true);
        $man = json_decode((string)($env['manifest'] ?? ''), true);
        $listed = false;
        foreach ((array)($man['files'] ?? []) as $f) if (($f['sha256'] ?? '') === $sha) { $listed = true; break; }
        if (!$listed || $row['ref'] === null) continue;
        try { $b = build_row((int)$row['ref']); } catch (ApiError) { continue; }
        foreach ((array)json_decode((string)$b['files'], true) as $p => $f) {
            if (($f['sha'] ?? '') !== $sha) continue;
            $path = build_blob_path($f + ['path' => $p]);
            if (is_file($path) && hash_equals($sha, (string)hash_file('sha256', $path))) return ['path' => $path, 'size' => (int)filesize($path), 'type' => (string)($f['type'] ?? '')];
        }
    }
    return null;
}

/** Range → [start, end] (כולל), עד PULL_CHUNK. null — טווח לא חוקי (416). */
function pull_range(?string $h, int $size): ?array
{
    if ($h === null || $h === '') return [0, min($size, PULL_CHUNK) - 1];
    if (!preg_match('/^bytes=(\d{1,15})-(\d{0,15})$/', trim($h), $m)) return null;
    $a = (int)$m[1];
    $b = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
    if ($a >= $size || $b < $a) return null;
    return [$a, min($b, $a + PULL_CHUNK - 1)];
}

/**
 * POST ?report: {results: [{seq, state: applied|failed, code?, message?}], state: {...}}. רק שורות שבתור משתנות (applied — גם אם
 * הוחלפה בינתיים: זו האמת באתר). הדיווח אינו יכול לשנות את העמוד — רק את מה שהכלי יודע על האתר.
 */
function pull_report(array $b, string $ip): array
{
    $results = $b['results'] ?? [];
    if (!is_array($results) || count($results) > 100) throw new ApiError('דיווח לא תקין', 400);
    $done = [];
    foreach ($results as $r) {
        if (!is_array($r) || !is_int($r['seq'] ?? null) || !in_array($r['state'] ?? '', ['applied', 'failed'], true)) continue;
        $code = preg_match('/^[a-z0-9_]{1,40}$/', (string)($r['code'] ?? '')) ? (string)$r['code'] : '';
        $res = ['code' => $code, 'message' => mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string)($r['message'] ?? '')), 0, 300), 'at' => time()];
        $state = $r['state'] === 'failed' && $code === 'expired' ? 'expired' : $r['state'];
        $st = db()->prepare('SELECT * FROM site_outbox WHERE seq = ?');
        $st->execute([$r['seq']]);
        $row = $st->fetch();
        if (!$row || in_array($row['state'], ['applied', 'failed', 'expired'], true)) continue;
        if ($state !== 'applied' && $row['state'] === 'superseded') continue;
        db()->prepare('UPDATE site_outbox SET state = ?, result = ?, applied_at = ? WHERE seq = ?')
            ->execute([$state, json_encode($res, JSON_UNESCAPED_UNICODE), $state === 'applied' ? now_iso() : null, $row['seq']]);
        outbox_effect($row, $state, $res);
        $done[] = (int)$row['seq'];
    }
    $state = pull_state_clean($b['state'] ?? null);
    pull_heartbeat_touch('report', $ip, $state);
    devlog_write('info', 'pull', 'pull.report', ['message' => 'דיווח', 'data' => ['seqs' => $done, 'ip' => $ip, 'plugin' => $state['plugin'] ?? '', 'cursor' => $state['cursor'] ?? null]]);
    return ['ok' => true, 'recorded' => $done, 'server_time' => time()];
}

// ── התור בממשק ────────────────────────────────────────────

function outbox_public(array $r): array
{
    $res = json_decode((string)$r['result'], true) ?: [];
    $env = json_decode((string)$r['body'], true) ?: [];
    $what = match ($r['action']) {
        'manifest' => 'גרסה ' . (json_decode((string)($env['manifest'] ?? ''), true)['version'] ?? '?'),
        'mount' => BUILD_MOUNTS[$env['mount'] ?? '']['name'] ?? '',
        'switch' => !empty($env['enabled']) ? 'להציג את הגרסה מהסטודיו' : 'חזרה לעמוד המקורי',
        'activate' => 'גרסה ' . (int)($env['version'] ?? 0),
        default => '',
    };
    return ['seq' => (int)$r['seq'], 'action' => $r['action'], 'label' => $r['label'] ?: (OUTBOX_LABELS[$r['action']] ?? $r['action']), 'what' => $what,
        'state' => $r['state'], 'created_at' => $r['created_at'], 'created_by' => $r['created_by'], 'expires' => (int)$r['expires'],
        'delivered_at' => $r['delivered_at'], 'applied_at' => $r['applied_at'], 'code' => $res['code'] ?? null,
        'reason' => in_array($r['state'], ['failed', 'expired'], true) ? outbox_reason($res) : null, 'ref' => $r['ref']];
}

function outbox_list(array $user): array
{
    outbox_expire();
    $rows = db()->query('SELECT * FROM site_outbox ORDER BY seq DESC LIMIT 30')->fetchAll();
    $waiting = (int)db()->query("SELECT COUNT(*) FROM site_outbox WHERE state IN ('queued','delivered')")->fetchColumn();
    return ['outbox' => array_map('outbox_public', $rows), 'waiting' => $waiting, 'channel' => pub_channel(), 'pulse' => pull_pulse(),
        'apply_now' => 'כדי להחיל מיד — וורדפרס ← קול מרוקו ← 🔄 עדכן עכשיו',
        'cron' => 'ב-Bluehost ← Cron Jobs: כל 5 דקות — wget -q -O - ' . site_url_setting() . '/wp-cron.php?doing_wp_cron >/dev/null 2>&1'];
}

/** "שלח שוב" לפקודה שנכשלה / פג תוקפה — דרך אותה פונקציה שיצרה אותה (ואותן בדיקות הרשאה). */
function outbox_resend(array $user, array $b): array
{
    $st = db()->prepare('SELECT * FROM site_outbox WHERE seq = ?');
    $st->execute([(int)($b['seq'] ?? 0)]);
    $row = $st->fetch() ?: throw new ApiError('הפקודה לא נמצאה', 404);
    if (!in_array($row['state'], ['failed', 'expired'], true)) throw new ApiError('רק פקודה שנכשלה או שפג תוקפה נשלחת שוב', 409);
    $env = json_decode((string)$row['body'], true) ?: [];
    require_once __DIR__ . '/build.php';
    match ($row['action']) {
        'manifest' => build_send($user, ['id' => (int)$row['ref']]),
        'mount' => mount_set(require_role($user, 'site_owner'), ['mount' => (string)($env['mount'] ?? '')]),
        'switch' => site_switch($user, ['enabled' => !empty($env['enabled'])]),
        default => outbox_add((string)$row['action'], array_diff_key($env, array_flip(['action', 'site', 'page', 'time', 'nonce', 'seq', 'expires'])), $user, ['label' => $row['label']]),
    };
    return outbox_list($user);
}

/** "בדוק עכשיו": דפיקה, והתור כמו שהוא — הממשק מחכה עד 60 שניות לדיווח חדש. */
function site_wake(array $user): array
{
    $w = outbox_wake();
    return ['wake' => $w] + outbox_list($user);
}
