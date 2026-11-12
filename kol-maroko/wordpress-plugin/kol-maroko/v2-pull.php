<?php
/**
 * קול מרוקו 2.2 — משיכה במקום דחיפה (פרוסה M7; docs/spec-v2/slices/M7-pull-not-push.md).
 *
 * התוסף **יוצא** אל הכלי (pull.php שבשורת החיבור), מושך את הפקודות שאחרי האחרונה שהחיל, מאמת כל אחת בדיוק כמו בדחיפה
 * (חתימה מול kid ידוע · action · site · page · nonce), ובמקום חלון הזמן ±5 דקות: **seq גדול מהאחרון שהוחל, ו-expires בעתיד**.
 * פקודה שהוחלה לעולם אינה מוחלת שוב. מניפסט: הגושים החסרים יורדים מ-pull.php?blob, כל אחד נבדק ב-sha256 ובגודל, ורק כשכולם
 * כאן — אותה החלפת מצביע של היום (kol_maroko_v2_manifest). switch / activate / mount — אותן פונקציות של היום.
 *
 * מתי מושכים: wp-cron (כל 5 דקות, 1–60 בהגדרה) · "🔄 עדכן עכשיו" · דפיקה (GET ?kol-maroko-wake=1 — מתזמנת משיכה, לכל היותר
 * אחת לדקה; אין בה תוכן). משיכה אחת בכל רגע (flock); תקציב 20 שניות לריצה; גוש שנקטע ממשיך מאותו בייט (Range).
 *
 * הכתובת היחידה שהקובץ הזה פונה אליה — זו שבשורת החיבור, https בלבד, בלי הפניות (redirection 0), עם בדיקת תעודה.
 * כל כתיבה לדיסק — בתוך uploads/kol-maroko/v2 בלבד, בשמות שנגזרים מ-sha256 (64 תווי hex) — אין בהם `..` ואין `/`.
 */

if (!defined('ABSPATH')) exit;

const KOL_MAROKO_PULL_ACTIONS = ['manifest', 'switch', 'activate', 'mount'];
const KOL_MAROKO_PULL_MAX_TTL = 8 * 86400;
const KOL_MAROKO_PULL_TRIES = 3;
const KOL_MAROKO_PULL_CHUNK = 8 * 1024 * 1024;
const KOL_MAROKO_PULL_BUDGET = 20;
const KOL_MAROKO_PULL_HOOK = 'kol_maroko_pull_cron';

// ── ההגדרות: שורת החיבור ──────────────────────────────────

/** כתובת משיכה חוקית: https, בלי משתמש/סיסמה/fragment. http — רק בסביבת הבדיקות, ורק ל-127.0.0.1 / localhost. */
function kol_maroko_pull_url_ok($u)
{
    if (!is_string($u) || strlen($u) > 300 || preg_match('/[\s"\'<>\\\\]/', $u)) return false;
    $p = wp_parse_url($u);
    if (!is_array($p) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || isset($p['query'])) return false;
    if (!preg_match('/^[a-z0-9.-]+$/i', $p['host'])) return false;
    $scheme = strtolower($p['scheme'] ?? '');
    if ($scheme === 'https') return true;
    return $scheme === 'http' && defined('KOL_MAROKO_PULL_TEST_HTTP') && in_array($p['host'], ['127.0.0.1', 'localhost'], true);
}

/** "kol-maroko:2:<kid>:<מפתח>:<כתובת>:<אסימון>" ← מערך, או null. השורה הישנה (מפתח בלבד) — אינה שורת חיבור (ערוץ דחיפה בלבד). */
function kol_maroko_pull_parse_line($line)
{
    $line = trim((string)$line);
    if (!preg_match('~^kol-maroko:2:([A-Za-z0-9._-]{1,40}):([A-Za-z0-9+/=]{40,64}):(https?://[^\s]+):([A-Za-z0-9_-]{20,128})$~', $line, $m)) return null;
    $pub = base64_decode($m[2], true);
    if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !kol_maroko_pull_url_ok($m[3])) return null;
    return ['kid' => $m[1], 'public' => $m[2], 'url' => $m[3], 'token' => $m[4]];
}

/** שמירת שורת החיבור: המפתח נוסף לרשימת המפתחות (עד 2 — הישן נשמר בזמן החלפה), הכתובת והאסימון נשמרים. */
function kol_maroko_pull_set_line($line)
{
    $p = kol_maroko_pull_parse_line($line);
    if (!$p) return false;
    $keys = [];
    foreach (preg_split('/\s+/', (string)kol_maroko_opt('keys', '')) as $k) if (trim($k) !== '') $keys[] = trim($k);
    $mine = $p['kid'] . ':' . $p['public'];
    $keys = array_values(array_unique(array_merge([$mine], array_filter($keys, function ($k) use ($mine) { return $k !== $mine; }))));
    kol_maroko_set('keys', implode("\n", array_slice($keys, 0, 2)));
    kol_maroko_set('pull_url', $p['url']);
    kol_maroko_set('pull_token', $p['token']);
    kol_maroko_pull_schedule(true);
    return true;
}

function kol_maroko_pull_ready()
{
    return kol_maroko_pull_url_ok(kol_maroko_opt('pull_url', '')) && preg_match('/^[A-Za-z0-9_-]{20,128}$/', (string)kol_maroko_opt('pull_token', ''));
}

function kol_maroko_pull_interval()
{
    $m = (int)kol_maroko_opt('pull_interval', 5);
    return $m >= 1 && $m <= 60 ? $m : 5;
}

// ── wp-cron והדפיקה ───────────────────────────────────────

add_filter('cron_schedules', function ($s) {
    $s['kol_maroko_pull'] = ['interval' => 60 * kol_maroko_pull_interval(), 'display' => 'קול מרוקו — משיכה'];
    return $s;
});
add_action(KOL_MAROKO_PULL_HOOK, function () { kol_maroko_pull_run('cron'); });
add_action('kol_maroko_pull_now', function () { kol_maroko_pull_run('wake'); });
add_action('init', function () { kol_maroko_pull_schedule(false); });

/** האירוע החוזר של wp-cron — רק כשיש שורת חיבור. $reset — אחרי שינוי מרווח / שורה. */
function kol_maroko_pull_schedule($reset)
{
    if (!function_exists('wp_next_scheduled')) return;
    $next = wp_next_scheduled(KOL_MAROKO_PULL_HOOK);
    if ($next && ($reset || !kol_maroko_pull_ready())) { wp_clear_scheduled_hook(KOL_MAROKO_PULL_HOOK); $next = false; }
    if (!$next && kol_maroko_pull_ready()) wp_schedule_event(time() + 30, 'kol_maroko_pull', KOL_MAROKO_PULL_HOOK);
}

/**
 * הדפיקה: GET עם ?kol-maroko-wake=1 מתזמן משיכה מיידית — לכל היותר אחת לדקה. אין בה תוכן, ולכן אין מה לזייף: היא רק מקדימה
 * את מה שה-cron היה עושה בכל מקרה. הבקשה עצמה ממשיכה כרגיל (העמוד מוגש כמו תמיד).
 */
function kol_maroko_pull_wake()
{
    if (!kol_maroko_pull_ready()) return false;
    $last = (int)kol_maroko_opt('pull_wake_at', 0);
    if ($last > time() - 60) return false;
    kol_maroko_set('pull_wake_at', time());
    if (function_exists('wp_schedule_single_event')) wp_schedule_single_event(time(), 'kol_maroko_pull_now');
    return true;
}

// ── HTTP — רק לכתובת שבשורת החיבור ────────────────────────

/** בקשה ל-pull.php. מחזיר ['status', 'headers', 'body'] או ['error' => …]. הפניה — שגיאה, לא נעקבת (H23). */
function kol_maroko_pull_http($method, $query, $body = null, array $headers = [])
{
    $base = (string)kol_maroko_opt('pull_url', '');
    if (!kol_maroko_pull_url_ok($base)) return ['error' => 'כתובת המשיכה אינה https תקינה', 'code' => 'bad_url'];
    $token = (string)kol_maroko_opt('pull_token', '');
    $args = ['method' => $method, 'timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'user-agent' => 'KolMaroko-Plugin/' . KOL_MAROKO_VERSION,
        'headers' => $headers + ['Authorization' => 'Bearer ' . $token, 'X-Kol-Pull-Token' => $token, 'Accept' => 'application/json'], 'limit_response_size' => KOL_MAROKO_PULL_CHUNK + 65536];
    if ($body !== null) { $args['body'] = $body; $args['headers']['Content-Type'] = 'application/json'; }
    $r = wp_remote_request($base . '?' . $query, $args);
    if (is_wp_error($r)) return ['error' => substr((string)$r->get_error_message(), 0, 300), 'code' => 'network'];
    $h = [];
    // M7b: בלי (array) — בוורדפרס הכותרות הן אובייקט (CaseInsensitiveDictionary), וה-cast מחזיר את המאפיין הפנימי שלו, לא את
    // הכותרות. ב-2.2.0 זה איבד את Content-Range, וכל גוש נכשל ב"טווח לא צפוי מהכלי"
    $hdrs = wp_remote_retrieve_headers($r);
    if (is_array($hdrs) || $hdrs instanceof Traversable) foreach ($hdrs as $k => $v) $h[strtolower((string)$k)] = is_array($v) ? (string)reset($v) : (string)$v;
    $st = (int)wp_remote_retrieve_response_code($r);
    $out = ['status' => $st, 'headers' => $h, 'body' => (string)wp_remote_retrieve_body($r)];
    if ($st >= 300 && $st < 400) $out += ['error' => 'הפניה (' . $st . ') ל-' . substr((string)($h['location'] ?? '?'), 0, 200) . ' — לא נעקבת', 'code' => 'redirect'];
    return $out;
}

function kol_maroko_pull_json($method, $query, $body = null)
{
    $r = kol_maroko_pull_http($method, $query, $body);
    if (isset($r['error'])) return $r;
    $j = json_decode($r['body'], true);
    if ($r['status'] === 401) return $r + ['error' => 'הכלי דחה את אסימון המשיכה (401) — הדביקו שורת חיבור חדשה', 'code' => 'bad_token'];
    if ($r['status'] !== 200 || !is_array($j) || ($j['ok'] ?? false) !== true) {
        $cf = isset($r['headers']['cf-ray']) ? ' (Cloudflare)' : '';
        return $r + ['error' => 'תשובה לא צפויה מהכלי: ' . $r['status'] . $cf, 'code' => 'http_' . $r['status']];
    }
    return $r + ['json' => $j];
}

// ── האימות — כמו kol_maroko_verify, עם seq ו-expires במקום חלון הזמן ──

/**
 * פקודה שנמשכה ← הגוף המפוענח, או ['fail' => קוד, 'message', 'advance' => האם לקדם את הסמן]. advance רק כשהחתימה תקינה —
 * שרת משיכה זדוני אינו יכול "לקפוץ" את הסמן קדימה בפקודה לא חתומה ולחסום את הפקודות האמיתיות.
 */
function kol_maroko_pull_verify($item, $cursor)
{
    $fail = function ($code, $msg, $advance = false) { return ['fail' => $code, 'message' => $msg, 'advance' => $advance]; };
    if (!is_array($item)) return $fail('bad_request', 'פריט לא תקין');
    $body = base64_decode((string)($item['body'] ?? ''), true);
    $sig = base64_decode((string)($item['sig'] ?? ''), true);
    $kid = (string)($item['kid'] ?? '');
    $keys = kol_maroko_keys();
    if ($body === false || strlen($body) > 8 * 1024 * 1024) return $fail('too_large', 'פקודה גדולה מדי');
    if (!$keys) return $fail('no_key', 'לא הודבק מפתח ציבורי');
    if (!isset($keys[$kid]) || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($sig, $body, $keys[$kid])) {
        return $fail('bad_signature', 'החתימה לא תקינה');
    }
    $b = json_decode($body, true);
    if (!is_array($b) || !in_array($b['action'] ?? '', KOL_MAROKO_PULL_ACTIONS, true) || ($b['action'] ?? '') !== ($item['action'] ?? '')) return $fail('bad_request', 'פעולה לא תקינה');
    $seq = $b['seq'] ?? null;
    if (!is_int($seq) || $seq < 1 || ($item['seq'] ?? null) !== $seq) return $fail('bad_request', 'seq לא תקין');
    if ($seq <= $cursor) return $fail('replay', 'הפקודה כבר הוחלה (seq ' . $seq . ' ≤ ' . $cursor . ')');
    if (($b['site'] ?? '') !== kol_maroko_host()) return $fail('wrong_site', 'האתר לא תואם', true);
    if (($b['page'] ?? '') !== KOL_MAROKO_PAGE) return $fail('wrong_page', 'העמוד לא תואם', true);
    $exp = $b['expires'] ?? null;
    if (!is_int($exp) || $exp > time() + KOL_MAROKO_PULL_MAX_TTL) return $fail('bad_request', 'expires לא תקין', true);
    if ($exp <= time()) return $fail('expired', 'פג התוקף', true);
    if (!preg_match('/^[a-f0-9]{32}$/', (string)($b['nonce'] ?? ''))) return $fail('bad_request', 'nonce לא תקין', true);
    return $b;
}

// ── ההורדה של גוש — לפי hash, עם המשך (Range) ─────────────

/**
 * מוריד גוש אחד ל-parts/<sha>.pull, ממשיך מהבייט שכבר כאן. true — הגוש שמור ב-blobs; 'pending' — נגמר התקציב (ממשיכים בריצה
 * הבאה מאותו בייט); מערך — כשל (blob_mismatch אחרי 3; blob_http / redirect — kol_maroko_pull_apply מחליטה: עד 3 ריצות — pending).
 */
function kol_maroko_pull_blob($sha, $size, $type, $seq, $t0)
{
    if (!kol_maroko_v2_sha_ok($sha) || !is_int($size) || $size < 0) return ['code' => 'bad_request', 'message' => 'גוש לא תקין'];
    if (kol_maroko_v2_blob($sha) !== null) return true;
    $root = kol_maroko_v2_ensure();
    $part = "$root/parts/$sha.pull";                      // sha — 64 תווי hex (נבדק למעלה): אין נתיב מחוץ לתיקייה
    $chunk = max(65536, min(KOL_MAROKO_PULL_CHUNK, (int)kol_maroko_opt('pull_chunk', KOL_MAROKO_PULL_CHUNK)));
    $budget = (float)kol_maroko_opt('pull_budget', KOL_MAROKO_PULL_BUDGET);
    while (true) {
        clearstatcache(false, $part);
        $have = is_file($part) ? (int)filesize($part) : 0;
        if ($have > $size) { @unlink($part); $have = 0; }
        if ($have === $size) {
            if ($size === 0 && !is_file($part)) @file_put_contents($part, '');
            if (hash_file('sha256', $part) === $sha) {
                $ext = KOL_MAROKO_V2_MIME_EXT[strtolower(trim(explode(';', (string)$type)[0]))] ?? '';
                if (!@rename($part, "$root/blobs/$sha" . ($ext !== '' ? ".$ext" : ''))) return ['code' => 'write_failed', 'message' => 'השמירה נכשלה'];
                return true;
            }
            @unlink($part);                                // H20: ה-hash לא תאם — נמחק, ונמשך שוב; אחרי 3 — failed
            $tries = (array)kol_maroko_opt('pull_tries', []);
            $tries[$seq . ':' . $sha] = (int)($tries[$seq . ':' . $sha] ?? 0) + 1;
            kol_maroko_set('pull_tries', array_slice($tries, -50, null, true));
            if ($tries[$seq . ':' . $sha] >= KOL_MAROKO_PULL_TRIES) return ['code' => 'blob_mismatch', 'message' => 'הגוש ' . substr($sha, 0, 12) . '… לא תאם ל-sha256 שלוש פעמים'];
            continue;
        }
        // התקציב — אבל לפחות חלק אחד בכל ריצה (אחרת תקציב קטן לא היה מתקדם לעולם)
        if (!empty($GLOBALS['kol_maroko_pull_chunks']) && microtime(true) - $t0 > $budget) return 'pending';
        $GLOBALS['kol_maroko_pull_chunks'] = (int)($GLOBALS['kol_maroko_pull_chunks'] ?? 0) + 1;
        $r = kol_maroko_pull_http('GET', 'blob=' . $sha, null, ['Range' => 'bytes=' . $have . '-' . ($have + $chunk - 1), 'Accept' => 'application/octet-stream']);
        if (isset($r['error'])) return ['code' => $r['code'] === 'redirect' ? 'redirect' : 'blob_http', 'message' => $r['error']];
        if ($r['status'] === 429) { $GLOBALS['kol_maroko_pull_rate'] = true; return 'pending'; }   // 60 לדקה בכלי — ממשיכים בעוד דקה, לא נכשלים
        if ($r['status'] === 206) {
            if (!preg_match('~^bytes (\d+)-(\d+)/(\d+)$~', (string)($r['headers']['content-range'] ?? ''), $m) || (int)$m[1] !== $have || (int)$m[3] !== $size
                || strlen($r['body']) !== (int)$m[2] - (int)$m[1] + 1) {
                return ['code' => 'blob_http', 'message' => 'טווח לא צפוי מהכלי'];
            }
        } elseif ($r['status'] === 200) {
            if ($have > 0) { @unlink($part); $have = 0; }   // השרת התעלם מה-Range — מתחילים מהתחלה
        } else {
            return ['code' => 'blob_http', 'message' => 'הכלי החזיר ' . $r['status'] . ' לגוש ' . substr($sha, 0, 12) . '…'];
        }
        if ($r['body'] === '' && $size > 0) return ['code' => 'blob_http', 'message' => 'גוש ריק'];
        if (strlen($r['body']) + $have > $size) { @unlink($part); continue; }   // יותר ממה שהוכרז — מתחילים שוב (ונספר כאי-התאמה בבדיקה)
        if (@file_put_contents($part, $r['body'], FILE_APPEND | LOCK_EX) !== strlen($r['body'])) return ['code' => 'write_failed', 'message' => 'הכתיבה נכשלה'];
    }
}

// ── ההחלה ─────────────────────────────────────────────────

/** כמה ריצות רצופות נכשלה ההורדה של הפקודה הזו בתקלת רשת (כולל הנוכחית). */
function kol_maroko_pull_net_try($seq)
{
    $t = (array)kol_maroko_opt('pull_net_tries', []);
    $t[(string)$seq] = (int)($t[(string)$seq] ?? 0) + 1;
    kol_maroko_set('pull_net_tries', array_slice($t, -20, null, true));
    return $t[(string)$seq];
}

function kol_maroko_pull_net_clear($seq)
{
    $t = (array)kol_maroko_opt('pull_net_tries', []);
    if (!isset($t[(string)$seq])) return;
    unset($t[(string)$seq]);
    kol_maroko_set('pull_net_tries', $t);
}

/** פקודה מאומתת ← ['state' => applied|failed, code, message] או 'pending' (נמשיך בריצה הבאה). */
function kol_maroko_pull_apply(array $b, $t0)
{
    $done = function ($r) {
        if ($r === true || ($r instanceof WP_REST_Response && $r->status === 200 && !empty($r->data['ok']))) return ['state' => 'applied', 'code' => '', 'message' => ''];
        $d = $r instanceof WP_REST_Response ? (array)$r->data : [];
        return ['state' => 'failed', 'code' => (string)($d['code'] ?? 'failed'), 'message' => (string)($d['message'] ?? '')];
    };
    $action = $b['action'];
    if ($action === 'manifest') {
        $pre = kol_maroko_v2_manifest_parse($b, $b['seq']);
        if ($pre instanceof WP_REST_Response) return $done($pre);
        $man = $pre['man'];
        // אותה גרסה כבר באוויר (הדיווח הקודם אבד) — הוחל
        if ((int)$man['version'] === (int)kol_maroko_opt('v2_live', 0) && (int)$man['version'] === kol_maroko_v2_last_any()) return ['state' => 'applied', 'code' => '', 'message' => 'כבר באוויר'];
        $bad = kol_maroko_v2_check_files($man, false);
        if ($bad) return $done($bad);
        $seen = [];
        foreach ($man['files'] as $f) {
            if (isset($seen[$f['sha256']])) continue;
            $seen[$f['sha256']] = true;
            $r = kol_maroko_pull_blob($f['sha256'], $f['size'], $f['type'] ?? '', $b['seq'], $t0);
            if ($r === 'pending') return 'pending';
            if ($r !== true) {
                // M7b: תקלת רשת (blob_http, הפניה, timeout) — זמנית בדרך כלל: "ממשיך בריצה הבאה", עד 3 ריצות רצופות; רק אז failed.
                // blob_mismatch (שלוש פעמים hash שגוי) — failed מיד, כמו קודם
                if (in_array($r['code'] ?? '', ['blob_http', 'redirect'], true) && kol_maroko_pull_net_try($b['seq']) < KOL_MAROKO_PULL_TRIES) return 'pending';
                kol_maroko_pull_net_clear($b['seq']);
                return ['state' => 'failed'] + $r;
            }
        }
        kol_maroko_pull_net_clear($b['seq']);
        return $done(kol_maroko_v2_manifest($b, $b['seq']));
    }
    if ($action === 'mount') return $done(kol_maroko_v2_mount_set($b));
    if ($action === 'activate') return $done(kol_maroko_v2_activate($b));
    return $done(kol_maroko_switch($b));
}

/** מה התוסף מדווח על עצמו — אותם שמות של ping, ועוד הסמן והמשיכה האחרונה. אין כאן שום מידע על האתר מעבר לתוסף (07 §5.3). */
function kol_maroko_pull_state()
{
    $last = (array)kol_maroko_opt('pull_last', []);
    return kol_maroko_ping_data() + ['cursor' => (int)kol_maroko_opt('pull_cursor', 0), 'interval' => kol_maroko_pull_interval(),
        'cron' => function_exists('wp_next_scheduled') && (bool)wp_next_scheduled(KOL_MAROKO_PULL_HOOK), 'last_run' => (int)($last['at'] ?? 0),
        'last_result' => (string)($last['text'] ?? ''), 'at' => time()];
}

/**
 * ריצה אחת: משיכה ← אימות ← החלה ← דיווח. משיכה אחת בכל רגע (flock, LOCK_NB — השנייה יוצאת מיד: H28). התקציב — 20 שניות;
 * פקודה שלא הסתיימה (גוש באמצע) עוצרת את הריצה, והבאה ממשיכה ממנה — הסדר נשמר (mount אחרי manifest).
 */
function kol_maroko_pull_run($why = 'manual')
{
    if (!kol_maroko_pull_ready()) return ['ok' => false, 'code' => 'not_set', 'text' => 'אין שורת חיבור'];
    $root = kol_maroko_v2_ensure();
    $lock = @fopen("$root/pull.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); return ['ok' => false, 'code' => 'busy', 'text' => 'משיכה אחרת רצה עכשיו']; }
    $t0 = microtime(true);
    $GLOBALS['kol_maroko_pull_chunks'] = 0;
    $GLOBALS['kol_maroko_pull_rate'] = false;
    $results = array_values((array)kol_maroko_opt('pull_unreported', []));
    $applied = $failed = 0;
    $pending = false;
    $error = null;
    try {
        for ($page = 0; $page < 20; $page++) {
            $cursor = (int)kol_maroko_opt('pull_cursor', 0);
            $r = kol_maroko_pull_json('GET', 'after=' . $cursor);
            if (($r['status'] ?? 0) === 429) { $GLOBALS['kol_maroko_pull_rate'] = true; $pending = true; break; }
            if (isset($r['error'])) { $error = $r; break; }
            $items = is_array($r['json']['items'] ?? null) ? $r['json']['items'] : [];
            foreach ($items as $item) {
                $cursor = (int)kol_maroko_opt('pull_cursor', 0);
                $b = kol_maroko_pull_verify($item, $cursor);
                $seq = is_array($item) && is_int($item['seq'] ?? null) ? $item['seq'] : 0;
                if (isset($b['fail'])) {
                    if ($b['fail'] !== 'replay') { $results[] = ['seq' => $seq, 'state' => 'failed', 'code' => $b['fail'], 'message' => $b['message']]; $failed++; }
                    if ($b['advance'] && $seq > $cursor) kol_maroko_set('pull_cursor', $seq);
                    continue;
                }
                $out = kol_maroko_pull_apply($b, $t0);
                if ($out === 'pending') { $pending = true; break 2; }
                kol_maroko_set('pull_cursor', $b['seq']);          // הוחל או נכשל סופית — לעולם לא שוב
                kol_maroko_v2_log('pull_' . $b['action'] . '_' . $out['state'], (int)($b['seq']));
                $results[] = ['seq' => $b['seq'], 'state' => $out['state'], 'code' => $out['code'], 'message' => substr((string)$out['message'], 0, 300)];
                $out['state'] === 'applied' ? $applied++ : $failed++;
                if (microtime(true) - $t0 > (float)kol_maroko_opt('pull_budget', KOL_MAROKO_PULL_BUDGET)) { $pending = true; break 2; }
            }
            if (empty($r['json']['more'])) break;
        }
        // הדיווח — גם כשאין מה לדווח (הדופק). מה שלא נמסר נשמר לריצה הבאה
        $rep = kol_maroko_pull_json('POST', 'report', kol_maroko_pull_enc(['results' => array_slice($results, -100), 'state' => kol_maroko_pull_state()]));
        kol_maroko_set('pull_unreported', isset($rep['error']) ? array_slice($results, -100) : []);
        // הדיווח לא נמסר (429 — מגבלת 60 לדקה אחרי הורדה של הרבה גושים; או תקלת רשת): הוא נשמר, ונשלח שוב בעוד דקה —
        // לא ב-cron הבא, אחרת הכלי מראה "📥 נמשך" עוד 5 דקות אחרי שהפקודה כבר הוחלה
        $reportLate = isset($rep['error']) && $results;
        if ($reportLate) $pending = true;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    // לא הסתיים (תקציב / קצב) — ההמשך לא מחכה ל-cron הבא: משיכה נוספת בעוד ~דקה
    if ($pending && function_exists('wp_schedule_single_event')) wp_schedule_single_event(time() + 65, 'kol_maroko_pull_now');
    $text = $error ? '✗ ' . $error['error'] : ('✓ ' . ($applied || $failed ? "הוחלו $applied" . ($failed ? ", נכשלו $failed" : '') : 'אין חדש')
        . (!empty($reportLate) ? ' · הדיווח לכלי יישלח שוב בעוד דקה' : ($pending ? ' · ממשיך בריצה הבאה' : '')));
    $last = ['at' => time(), 'why' => (string)$why, 'ok' => !$error, 'applied' => $applied, 'failed' => $failed, 'pending' => $pending, 'text' => $text,
        'code' => $error['code'] ?? '', 'cursor' => (int)kol_maroko_opt('pull_cursor', 0)];
    kol_maroko_set('pull_last', $last);
    return $last + ['results' => $results];
}

function kol_maroko_pull_enc($v)
{
    return function_exists('wp_json_encode') ? wp_json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * "בדוק חיבור" — משיכה ריקה (pull.php?ping). מחזיר את התוצאה המדויקת: סטטוס, server / cf-ray, או שגיאת TLS / DNS / הפניה —
 * מה שנדרש כדי לדעת אם שרת האתר יוצא אל שרת הכלי (§2.7).
 */
function kol_maroko_pull_check()
{
    $r = kol_maroko_pull_http('GET', 'ping');
    $out = ['at' => time(), 'status' => (int)($r['status'] ?? 0), 'server' => substr((string)($r['headers']['server'] ?? ''), 0, 60),
        'cf_ray' => substr((string)($r['headers']['cf-ray'] ?? ''), 0, 60), 'error' => (string)($r['error'] ?? '')];
    $j = isset($r['body']) ? json_decode($r['body'], true) : null;
    if (!$out['error'] && $out['status'] === 401) $out['error'] = 'הכלי דחה את האסימון (401) — שורת חיבור חדשה מהכלי';
    elseif (!$out['error'] && ($out['status'] !== 200 || !is_array($j) || empty($j['ok']))) $out['error'] = 'תשובה לא צפויה: ' . $out['status'] . ($out['cf_ray'] ? ' (Cloudflare)' : '');
    $out['ok'] = $out['error'] === '';
    $out['waiting'] = is_array($j) ? (int)($j['waiting'] ?? 0) : 0;
    kol_maroko_set('pull_check', $out);
    return $out;
}

/** הפעולות מעמוד התוסף (admin_post_kol_maroko_v2). מחזיר הודעה. */
function kol_maroko_pull_admin_op($op)
{
    if ($op === 'pull_line') {
        $ok = kol_maroko_pull_set_line(wp_unslash($_POST['line'] ?? ''));
        if (!$ok) return 'השורה לא תקינה. מעתיקים אותה בשלמותה מהכלי: ⚙️ הגדרות ← 🔗 חיבור לאתר ← 📋 שורת חיבור לתוסף (מתחילה ב-kol-maroko:2:).';
        $c = kol_maroko_pull_check();
        return 'שורת החיבור נשמרה. ' . ($c['ok'] ? '✓ הכלי ענה.' : '✗ ' . $c['error']);
    }
    if ($op === 'pull_now') return 'עדכן עכשיו: ' . kol_maroko_pull_run('manual')['text'];
    if ($op === 'pull_check') { $c = kol_maroko_pull_check(); return $c['ok'] ? '✓ הכלי ענה (' . $c['status'] . ($c['server'] ? ', ' . $c['server'] : '') . ').' : '✗ ' . $c['error']; }
    if ($op === 'pull_interval') {
        $m = (int)($_POST['minutes'] ?? 5);
        kol_maroko_set('pull_interval', $m >= 1 && $m <= 60 ? $m : 5);
        kol_maroko_pull_schedule(true);
        return 'המשיכה — כל ' . kol_maroko_pull_interval() . ' דקות.';
    }
    if ($op === 'pull_off') {
        delete_option('kol_maroko_pull_token');
        delete_option('kol_maroko_pull_url');
        kol_maroko_pull_schedule(true);
        return 'המשיכה נותקה. הערוץ — דחיפה בלבד.';
    }
    return null;
}

/** הקטע בעמוד התוסף: שורת החיבור, המשיכה האחרונה, ה-seq האחרון, "עדכן עכשיו", "בדוק חיבור", המרווח, והוראת ה-cron. */
function kol_maroko_pull_admin_html($post, $nonce)
{
    $ready = kol_maroko_pull_ready();
    $last = (array)kol_maroko_opt('pull_last', []);
    $chk = (array)kol_maroko_opt('pull_check', []);
    $when = function ($t) { return $t ? esc_html(wp_date('d/m/Y H:i:s', (int)$t)) : '—'; };
    $op = function ($name, $label, $extra = '') use ($post, $nonce) {
        return '<form method="post" action="' . $post . '" style="display:inline-block;margin:0 0 0 8px">' . $nonce . '<input type="hidden" name="op" value="' . $name . '">' . $extra
            . '<button type="submit" class="button">' . esc_html($label) . '</button></form>';
    };
    $h = '<h3 id="kol-pull">📥 משיכה מהכלי (2.2)</h3>'
        . '<p>האתר <b>יוצא</b> אל הכלי ומושך ממנו את הפרסומים — אין שום בקשה נכנסת לאתר, ולכן Cloudflare אינו בדרך. כל פקודה נבדקת בחתימה, '
        . 'ומוחלת פעם אחת בדיוק.</p>'
        . '<form method="post" action="' . $post . '">' . $nonce . '<input type="hidden" name="op" value="pull_line">'
        . '<p><label>שורת חיבור <input name="line" dir="ltr" size="80" autocomplete="off" placeholder="kol-maroko:2:…"></label> '
        . '<button type="submit" class="button button-primary">שמירה ובדיקה</button></p></form>';
    if (!$ready) return $h . '<p><b>לא מחובר</b> — בלי שורת חיבור התוסף מקבל רק דחיפה (POST לאתר).</p>';
    $u = wp_parse_url((string)kol_maroko_opt('pull_url', ''));
    $h .= '<table class="widefat striped" style="max-width:900px"><tbody>'
        . '<tr><th>הכלי</th><td dir="ltr">' . esc_html(($u['host'] ?? '') . ($u['path'] ?? '')) . '</td></tr>'
        . '<tr><th>המשיכה האחרונה</th><td>' . $when($last['at'] ?? 0) . ' · ' . esc_html((string)($last['text'] ?? 'עוד לא')) . '</td></tr>'
        . '<tr><th>ה-seq האחרון שהוחל</th><td>' . (int)kol_maroko_opt('pull_cursor', 0) . '</td></tr>'
        . '<tr><th>בדיקת חיבור</th><td>' . ($chk ? $when($chk['at'] ?? 0) . ' · ' . ($chk['ok'] ? '✓ ' . (int)$chk['status'] : '✗ ' . esc_html((string)$chk['error']))
            . ($chk['server'] ? ' · server: ' . esc_html($chk['server']) : '') . ($chk['cf_ray'] ? ' · cf-ray: ' . esc_html($chk['cf_ray']) : '') : '—') . '</td></tr>'
        . '<tr><th>כל כמה זמן</th><td>' . kol_maroko_pull_interval() . ' דקות (wp-cron — רץ רק כשיש ביקורים באתר)</td></tr>'
        . '</tbody></table>'
        . '<p>' . $op('pull_now', '🔄 עדכן עכשיו') . $op('pull_check', 'בדוק חיבור')
        . $op('pull_interval', 'שמירת מרווח', '<input type="number" name="minutes" min="1" max="60" value="' . kol_maroko_pull_interval() . '" style="width:4em"> דקות ')
        . $op('pull_off', 'ניתוק המשיכה') . '</p>'
        . '<p class="description">כדי שהמשיכה תרוץ גם בלי ביקורים: ב-Bluehost ← Cron Jobs, כל 5 דקות — <code dir="ltr">wget -q -O - '
        . esc_html(home_url('/wp-cron.php?doing_wp_cron')) . ' &gt;/dev/null 2&gt;&amp;1</code></p>';
    return $h;
}
