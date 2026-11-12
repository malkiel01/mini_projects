<?php
/**
 * גופנים (פרוסה V9; docs/spec-v2/slices/V9-globals-fonts.md): העלאת קובץ גופן, Google Fonts, "הבא לפרויקט".
 *
 * העלאה: הסוג נקבע **מהחתימה** של הקובץ (לא מהשם ולא מהסיומת), השם מנוקה, והקובץ נשמר כמו שהוא תחת media/fonts/
 * של מאגר הפרויקט (pf_insert — אותו מאגר, אותה היסטוריה ואותו סל). המשפחה, המשקל והסגנון נקראים מטבלאות name / OS/2
 * (ttf, otf, woff); ב-woff2 (דחוס ב-Brotli) — ניחוש מהשם, והמשתמש מאשר.
 *
 * בקשות יוצאות — רק כאן, ורק דרך font_http: https://fonts.googleapis.com/css2 (הכתובת נבנית כאן משם משפחה שעבר תבנית)
 * ו-https://fonts.gstatic.com/… (מתוך ה-CSS של Google, נבדקת). בלי מעקב הפניות, https בלבד, זמן וגודל מוגבלים, והכתובת
 * הנפתרת של המארח נבדקת (לא פרטית / שמורה) וננעלת לחיבור. כתובת שהמשתמש נותן — לעולם לא נשלפת.
 *
 * אין מטמון ואין קובץ נתונים חדש: רשימת Google Fonts קבועה בקוד (FONT_GOOGLE), המשקלים נבדקים מול css2 בזמן ההוספה.
 */

declare(strict_types=1);

require_once __DIR__ . '/project.php';

const FONT_DIR = 'media/fonts/';
const FONT_MAX = 2 * 1024 * 1024;           // קובץ גופן בודד (העלאה והורדה)
const FONT_WARN = 500 * 1024;               // מעל זה — הערה ("גופן כבד")
const FONT_CSS_MAX = 256 * 1024;            // תשובת css2
const FONT_FETCH_MAX = 60;                  // קבצים ב"הבא לפרויקט" אחד (משקלים × תת-קבוצות)
const FONT_FETCH_TOTAL = 12 * 1024 * 1024;
const FONT_TIMEOUT = 10;
const FONT_HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com'];
// css2 מגיש woff2 רק לדפדפן מודרני; בלי זה — ttf
const FONT_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
const FONT_EXT = ['woff2' => 'woff2', 'woff' => 'woff', 'ttf' => 'ttf', 'otf' => 'otf'];
const FONT_FORMAT = ['woff2' => 'woff2', 'woff' => 'woff', 'ttf' => 'truetype', 'otf' => 'opentype'];

/**
 * Google Fonts שמוצגים בחיפוש: [שם, תומך עברית, משקלים, נטוי, סוג]. משקלים: רשימה, או [min, max] לגופן משתנה ('var').
 * הנתונים כאן הם הצעה בלבד — המשקלים נבדקים מול css2 בזמן ההוספה (font_google_add).
 */
const FONT_GOOGLE = [
    ['Heebo', true, [100, 900], false, 'sans-serif', 'var'], ['Assistant', true, [200, 800], false, 'sans-serif', 'var'],
    ['Rubik', true, [300, 900], true, 'sans-serif', 'var'], ['Frank Ruhl Libre', true, [300, 900], false, 'serif', 'var'],
    ['Noto Sans Hebrew', true, [100, 900], false, 'sans-serif', 'var'], ['Noto Serif Hebrew', true, [100, 900], false, 'serif', 'var'],
    ['Noto Rashi Hebrew', true, [100, 900], false, 'serif', 'var'], ['IBM Plex Sans Hebrew', true, [100, 200, 300, 400, 500, 600, 700], false, 'sans-serif', ''],
    ['Open Sans', true, [300, 800], true, 'sans-serif', 'var'], ['Arimo', true, [400, 700], true, 'sans-serif', 'var'],
    ['Tinos', true, [400, 700], true, 'serif', ''], ['Cousine', true, [400, 700], true, 'monospace', ''],
    ['Secular One', true, [400], false, 'sans-serif', ''], ['Suez One', true, [400], false, 'serif', ''],
    ['Varela Round', true, [400], false, 'sans-serif', ''], ['Alef', true, [400, 700], false, 'sans-serif', ''],
    ['Amatic SC', true, [400, 700], false, 'cursive', ''], ['David Libre', true, [400, 500, 700], false, 'serif', ''],
    ['Miriam Libre', true, [400, 700], false, 'sans-serif', ''], ['Bellefair', true, [400], false, 'serif', ''],
    ['Karantina', true, [300, 400, 700], false, 'display', ''], ['Bona Nova', true, [400, 700], true, 'serif', ''],
    ['Solitreo', true, [400], false, 'cursive', ''], ['Fredoka', true, [300, 700], false, 'sans-serif', 'var'],
    ['Playpen Sans Hebrew', true, [100, 800], false, 'cursive', 'var'], ['Rubik Mono One', false, [400], false, 'monospace', ''],
    ['Roboto', false, [100, 900], true, 'sans-serif', 'var'], ['Inter', false, [100, 900], false, 'sans-serif', 'var'],
    ['Lato', false, [100, 300, 400, 700, 900], true, 'sans-serif', ''], ['Montserrat', false, [100, 900], true, 'sans-serif', 'var'],
    ['Poppins', false, [100, 200, 300, 400, 500, 600, 700, 800, 900], true, 'sans-serif', ''], ['Playfair Display', false, [400, 900], true, 'serif', 'var'],
    ['Merriweather', false, [300, 400, 700, 900], true, 'serif', ''], ['Source Sans 3', false, [200, 900], true, 'sans-serif', 'var'],
    ['Nunito', false, [200, 1000], true, 'sans-serif', 'var'], ['Oswald', false, [200, 700], false, 'sans-serif', 'var'],
];

// ── בדיקות קלט ────────────────────────────────────────────

/** הסוג לפי החתימה (4 הבייטים הראשונים), או null — לא קובץ גופן. */
function font_kind(string $bytes): ?string
{
    $sig = substr($bytes, 0, 4);
    return match (true) {
        $sig === 'wOF2' => 'woff2',
        $sig === 'wOFF' => 'woff',
        $sig === "\x00\x01\x00\x00", $sig === 'true' => 'ttf',
        $sig === 'OTTO' => 'otf',
        default => null,
    };
}

/** שם משפחה שמותר לכתוב ב-CSS ובכתובת: אותיות (כל שפה), ספרות, רווח, מקף, קו תחתון. בלי מרכאות, < ; { } ( ). */
function font_family_ok(mixed $s): string
{
    if (!is_string($s)) throw new ApiError('חסר שם גופן');
    if (preg_match('/\p{Cc}/u', $s)) throw new ApiError('שם גופן: בלי תווי בקרה ושורות');
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    if ($s === '' || mb_strlen($s) > 60 || !preg_match('/^[\p{L}\p{N} _-]+$/u', $s)) throw new ApiError('שם גופן: אותיות, ספרות, רווח ומקף בלבד (עד 60 תווים)');
    return $s;
}

/** משקלים: מספרים שלמים 1–1000, ממוינים, בלי כפילות. */
function font_weights(mixed $w): array
{
    $out = [];
    foreach ((array)$w as $x) {
        if (!is_int($x) && !(is_string($x) && ctype_digit($x))) throw new ApiError('משקל גופן — מספר בלבד');
        $x = (int)$x;
        if ($x < 1 || $x > 1000) throw new ApiError('משקל גופן: בין 1 ל-1000');
        $out[$x] = true;
    }
    if (!$out) $out[400] = true;
    if (count($out) > 18) throw new ApiError('יותר מדי משקלים');
    $out = array_keys($out);
    sort($out);
    return $out;
}

/** שם קובץ מנוקה: [a-z0-9-], בלי נקודות ובלי לוכסנים. מה שאינו לטיני — נופל; ריק ← "font". */
function font_slug(string $s): string
{
    $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s) ?? '');
    $s = trim(substr($s, 0, 60), '-');
    return $s === '' ? 'font' : $s;
}

// ── קריאת הקובץ: name / OS/2 / fvar ──────────────────────

/** הטבלאות של sfnt / woff: tag ← בייטים (לא דחוסים). woff2 — [] (Brotli). */
function font_tables(string $b, string $kind): array
{
    $out = [];
    $u16 = fn(int $o) => $o + 2 <= strlen($b) ? unpack('n', $b, $o)[1] : 0;
    $u32 = fn(int $o) => $o + 4 <= strlen($b) ? unpack('N', $b, $o)[1] : 0;
    if ($kind === 'ttf' || $kind === 'otf') {
        $n = min($u16(4), 100);
        for ($i = 0; $i < $n; $i++) {
            $r = 12 + $i * 16;
            $tag = substr($b, $r, 4); $off = $u32($r + 8); $len = $u32($r + 12);
            if ($off + $len <= strlen($b) && in_array($tag, ['name', 'OS/2', 'fvar', 'cmap'], true)) $out[$tag] = substr($b, $off, $len);
        }
    } elseif ($kind === 'woff') {
        $n = min($u16(12), 100);
        for ($i = 0; $i < $n; $i++) {
            $r = 44 + $i * 20;
            $tag = substr($b, $r, 4); $off = $u32($r + 4); $comp = $u32($r + 8); $orig = $u32($r + 12);
            if ($off + $comp > strlen($b) || $orig > 4 * 1024 * 1024 || !in_array($tag, ['name', 'OS/2', 'fvar', 'cmap'], true)) continue;
            $raw = substr($b, $off, $comp);
            if ($comp < $orig) { $raw = @gzuncompress($raw, $orig); if ($raw === false) continue; }
            $out[$tag] = $raw;
        }
    }
    return $out;
}

/** מה שבקובץ: family, sub (תת-משפחה), weight, style, hebrew (true / false / null — לא ידוע), variable ([min, max] | null). */
function font_info(string $b, string $kind, string $fileName = ''): array
{
    $t = font_tables($b, $kind);
    $info = ['family' => '', 'sub' => '', 'weight' => null, 'style' => 'normal', 'hebrew' => null, 'variable' => null, 'read' => (bool)$t];
    if (isset($t['name'])) {
        $n = $t['name']; $L = strlen($n);
        $u16 = fn(int $o) => $o + 2 <= $L ? unpack('n', $n, $o)[1] : 0;
        $count = min($u16(2), 2000); $base = $u16(4); $best = [];
        for ($i = 0; $i < $count; $i++) {
            $r = 6 + $i * 12;
            [$pid, $eid, $lid, $nid, $len, $off] = [$u16($r), $u16($r + 2), $u16($r + 4), $u16($r + 6), $u16($r + 8), $u16($r + 10)];
            if (!in_array($nid, [1, 2, 16, 17], true) || $base + $off + $len > $L) continue;
            $raw = substr($n, $base + $off, $len);
            $s = $pid === 3 || $pid === 0 ? @mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE') : ($pid === 1 ? preg_replace('/[^\x20-\x7e]/', '', $raw) : '');
            $s = trim((string)$s);
            if ($s === '') continue;
            $score = ($pid === 3 && $lid === 0x409 ? 3 : ($pid === 3 ? 2 : 1));
            if (!isset($best[$nid]) || $best[$nid][0] < $score) $best[$nid] = [$score, $s];
        }
        $info['family'] = $best[16][1] ?? $best[1][1] ?? '';
        $info['sub'] = $best[17][1] ?? $best[2][1] ?? '';
    }
    if (isset($t['OS/2']) && strlen($t['OS/2']) >= 64) {
        $o = $t['OS/2'];
        $info['weight'] = unpack('n', $o, 4)[1] ?: null;
        $info['hebrew'] = (bool)(unpack('N', $o, 42)[1] & (1 << 11));      // ulUnicodeRange1, ביט 11 — עברית
        if (unpack('n', $o, 62)[1] & 1) $info['style'] = 'italic';         // fsSelection, ביט 0
    }
    if (isset($t['fvar']) && strlen($t['fvar']) >= 16) {
        $f = $t['fvar']; $ao = unpack('n', $f, 4)[1]; $cnt = min(unpack('n', $f, 8)[1], 20); $sz = unpack('n', $f, 10)[1];
        for ($i = 0; $i < $cnt && $ao + $i * $sz + 20 <= strlen($f); $i++) {
            $r = $ao + $i * $sz;
            if (substr($f, $r, 4) !== 'wght') continue;
            $fx = fn(int $o) => (($v = unpack('N', $f, $o)[1]) >= 0x80000000 ? $v - 0x100000000 : $v) / 65536;
            $info['variable'] = [(int)round($fx($r + 4)), (int)round($fx($r + 12))];
        }
    }
    // woff2 / שם חסר — ניחוש מהשם: "Heebo-Bold.woff2" ← Heebo, 700
    $stem = preg_replace('/\.[^.]*$/', '', basename($fileName)) ?? '';
    $parts = preg_split('/[-_ ]+/', $stem) ?: [];
    $W = ['thin' => 100, 'hairline' => 100, 'extralight' => 200, 'ultralight' => 200, 'light' => 300, 'regular' => 400, 'normal' => 400, 'book' => 400,
        'medium' => 500, 'semibold' => 600, 'demibold' => 600, 'bold' => 700, 'extrabold' => 800, 'ultrabold' => 800, 'black' => 900, 'heavy' => 900];
    $styleWords = '';
    foreach ($parts as $p) { if (isset($W[strtolower($p)]) || preg_match('/^(italic|oblique|\d{3})$/i', $p) || preg_match('/^(\w+?)(italic)$/i', $p)) $styleWords .= ' ' . $p; }
    $sub = strtolower(str_replace(' ', '', $info['sub'] ?: $styleWords));
    if ($info['weight'] === null) {
        $info['weight'] = 400;
        foreach ($W as $k => $v) if (str_contains($sub, $k)) { $info['weight'] = $v; if (strlen($k) > 4) break; }
        if (preg_match('/\b([1-9]00)\b/', $styleWords, $m)) $info['weight'] = (int)$m[1];
    }
    if (preg_match('/italic|oblique/i', $sub)) $info['style'] = 'italic';
    if ($info['family'] === '') {
        $fam = [];
        foreach ($parts as $p) { if (isset($W[strtolower($p)]) || preg_match('/^(italic|oblique|\d{3}|variable|vf|webfont|var|wght)$/i', $p) || preg_match('/^\w+italic$/i', $p)) break; $fam[] = $p; }
        $info['family'] = implode(' ', $fam) ?: $stem;
    }
    // שם שאינו עובר את התבנית (מרכאות, סוגריים…) — מנוקה; המשתמש מתקן באישור
    $info['family'] = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N} _-]+/u', ' ', $info['family']) ?? '') ?? '') ?: 'Font';
    $info['family'] = mb_substr($info['family'], 0, 60);
    return $info;
}

// ── שמירה במאגר הפרויקט ───────────────────────────────────

/** נתיב פנוי: media/fonts/<slug>.<ext>, ואם תפוס — <slug>-2… */
function font_free_path(string $slug, string $ext): string
{
    for ($i = 1; $i < 500; $i++) {
        $p = FONT_DIR . $slug . ($i > 1 ? "-$i" : '') . '.' . $ext;
        if (!pf_by_path($p) && !pf_by_path(strtolower($p))) return $p;
    }
    throw new ApiError('אין שם פנוי לקובץ הגופן', 409);
}

function font_store(array $user, string $slug, string $kind, string $bytes, string $note): array
{
    $ext = FONT_EXT[$kind];
    return pf_tx(function () use ($user, $slug, $ext, $bytes, $note) {
        $path = font_free_path(font_slug($slug), $ext);
        pf_free($path);
        if (($n = pf_count()) >= ($max = pf_limit('files'))) pf_over('יותר מדי קבצים בפרויקט', $n + 1, $max);
        return pf_insert($path, 'file', $bytes, $user, null, $note);
    });
}

/** העלאת קובץ גופן אחד: {name, content_b64}. מחזיר את הקובץ, מה שנקרא ממנו, והערות. */
function font_upload(array $user, array $b): array
{
    pf_seed($user);
    pf_guard($user);
    $bytes = pf_bytes(['content_b64' => (string)($b['content_b64'] ?? '')]);
    $name = is_string($b['name'] ?? null) ? (string)$b['name'] : '';
    if ($bytes === '') throw new ApiError('הקובץ ריק');
    if (strlen($bytes) > FONT_MAX) throw new ApiError('קובץ הגופן גדול מ-2MB', 413);
    $kind = font_kind($bytes);
    if ($kind === null) throw new ApiError('"' . mb_substr(basename($name), 0, 80) . '" אינו קובץ גופן (woff2, woff, ttf, otf) — לפי התוכן שלו, לא לפי השם', 415);
    $info = font_info($bytes, $kind, $name);
    $slug = font_slug($info['family'] . '-' . ($info['variable'] ? 'variable' : (string)$info['weight']) . ($info['style'] === 'italic' ? '-italic' : ''));
    if ($slug === 'font-' . $info['weight']) $slug = font_slug(preg_replace('/\.[^.]*$/', '', basename($name)) ?? '');
    $r = font_store($user, $slug, $kind, $bytes, 'גופן הועלה');
    audit_as($user, 'font_upload', $r['path'] . ' · ' . $info['family']);
    $notes = [];
    if (strlen($bytes) > FONT_WARN) $notes[] = 'הקובץ כבד (' . round(strlen($bytes) / 1024) . ' ק"ב) — הגולש יטען אותו בכל כניסה. woff2 קטן בדרך כלל פי 2–3.';
    if ($info['hebrew'] === false) $notes[] = 'בקובץ אין אותיות עבריות — הטקסט העברי יוצג בגופן הגיבוי.';
    return ['file' => pf_public($r), 'kind' => $kind, 'format' => FONT_FORMAT[$kind], 'info' => $info, 'notes' => $notes];
}

// ── בקשות יוצאות ──────────────────────────────────────────

/** כתובת שמותר לשלוף: https, מארח מהרשימה בדיוק, בלי פורט / משתמש / סיסמה, נתיב ושאילתה בתווים מותרים. מחזיר את המארח. */
function font_url_ok(string $url): string
{
    $p = parse_url($url);
    if (!is_array($p) || ($p['scheme'] ?? '') !== 'https') throw new ApiError('כתובת גופן: https בלבד', 400);
    $host = strtolower((string)($p['host'] ?? ''));
    if (!in_array($host, FONT_HOSTS, true)) throw new ApiError('כתובת גופן: רק fonts.googleapis.com / fonts.gstatic.com', 400);
    if (isset($p['port']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) throw new ApiError('כתובת גופן לא תקינה', 400);
    if (!preg_match('~^/[A-Za-z0-9/_.\-]*$~', (string)($p['path'] ?? '')) || str_contains((string)$p['path'], '..')) throw new ApiError('כתובת גופן: נתיב לא תקין', 400);
    if (isset($p['query']) && !preg_match('~^[A-Za-z0-9+:;@,.&=%_\-]*$~', $p['query'])) throw new ApiError('כתובת גופן: שאילתה לא תקינה', 400);
    if (!preg_match('~^https://' . preg_quote($host, '~') . '/~', $url)) throw new ApiError('כתובת גופן לא תקינה', 400);    // אין "\" או "@" שמבלבלים מפרשים
    return $host;
}

/** כתובת IP ציבורית בלבד (לא loopback, פרטית, link-local, שמורה, multicast). */
function font_ip_ok(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
        && !preg_match('/^(0\.|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|192\.0\.0\.|198\.1[89]\.|2(2[4-9]|[3-5]\d)\.)/', $ip)
        && !preg_match('/^(::|::1|f[cd]|fe[89ab]|ff|::ffff:)/i', $ip);
}

/**
 * GET לכתובת שעברה font_url_ok. $resolve — לבדיקות בלבד (מארח ← IP במקום DNS). בלי הפניות, https בלבד, $max בייטים.
 * $GLOBALS['kol_font_fake'] (בדיקות) — מקבל [url, ip] אחרי כל הבדיקות, ומחזיר [status, body].
 */
function font_http(string $url, int $max, ?array $resolve = null): string
{
    $host = font_url_ok($url);
    $resolve ??= $GLOBALS['kol_font_resolve'] ?? null;                     // בדיקות בלבד
    $ips = $resolve !== null ? (array)($resolve[$host] ?? []) : (gethostbynamel($host) ?: []);
    if (!$ips) throw new ApiError("לא הצלחתי לפנות אל $host", 502);
    foreach ($ips as $ip) if (!font_ip_ok($ip)) throw new ApiError("$host נפתר לכתובת פנימית ($ip) — הבקשה נחסמה", 502);
    $ip = $ips[0];
    if (isset($GLOBALS['kol_font_fake'])) {
        [$status, $body] = ($GLOBALS['kol_font_fake'])($url, $ip);
    } else {
        if (!function_exists('curl_init')) throw new ApiError('אין curl בשרת', 500);
        $body = ''; $over = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => ["$host:443:$ip"], CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => FONT_TIMEOUT, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_USERAGENT => FONT_UA, CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: text/css,*/*;q=0.1'],
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body, &$over, $max) {
                if (strlen($body) + strlen($chunk) > $max) { $over = true; return 0; }
                $body .= $chunk; return strlen($chunk);
            },
        ]);
        $okRun = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($over) throw new ApiError('התשובה מ-' . $host . ' גדולה מהמותר (' . round($max / 1024) . ' ק"ב)', 413);
        if ($okRun === false && $status === 0) throw new ApiError("לא הצלחתי לפנות אל $host: $err", 502);
    }
    if ($status >= 300 && $status < 400) throw new ApiError("$host ענה בהפניה ($status) — הפניות אינן נעקבות", 502);
    if ($status === 400 || $status === 404) throw new ApiError('Google Fonts: אין גופן כזה, או שהמשקלים שנבחרו אינם בו', 404);
    if ($status !== 200) throw new ApiError("$host ענה $status", 502);
    if (strlen((string)$body) > $max) throw new ApiError('התשובה מ-' . $host . ' גדולה מהמותר', 413);
    return (string)$body;
}

/** כתובת css2 ל-<link>: family=Name:ital,wght@0,400;0,700… — נבנית כאן בלבד. */
function font_css2_url(string $family, array $weights, bool $italic = false, ?array $range = null): string
{
    $fam = str_replace(' ', '+', font_family_ok($family));
    if (!preg_match('/^[A-Za-z0-9+]+$/', $fam)) throw new ApiError('Google Fonts: שם משפחה באותיות לטיניות בלבד');
    if ($range) $axes = ($italic ? 'ital,wght@0,' . $range[0] . '..' . $range[1] . ';1,' . $range[0] . '..' . $range[1] : 'wght@' . $range[0] . '..' . $range[1]);
    else {
        $w = font_weights($weights);
        $axes = $italic ? 'ital,wght@' . implode(';', array_merge(array_map(fn($x) => "0,$x", $w), array_map(fn($x) => "1,$x", $w))) : 'wght@' . implode(';', $w);
    }
    return 'https://fonts.googleapis.com/css2?family=' . $fam . ':' . $axes . '&display=swap';
}

/** @font-face של css2 ← [{family, style, weight, src, range, subset}]. src נבדק כאן (font_url_ok). */
function font_css2_parse(string $css): array
{
    $out = [];
    if (!preg_match_all('~(?:/\*\s*([\w-]+)\s*\*/\s*)?@font-face\s*\{([^{}]*)\}~', $css, $m, PREG_SET_ORDER)) return [];
    foreach ($m as $x) {
        $d = [];
        foreach (explode(';', $x[2]) as $decl) { $kv = explode(':', $decl, 2); if (count($kv) === 2) $d[strtolower(trim($kv[0]))] = trim($kv[1]); }
        if (!preg_match("~url\\(\s*['\"]?(https://[^'\")\s]+)['\"]?\s*\\)~", $d['src'] ?? '', $u)) continue;
        font_url_ok($u[1]);
        if (parse_url($u[1], PHP_URL_HOST) !== 'fonts.gstatic.com') throw new ApiError('Google Fonts: קובץ ממארח לא צפוי', 502);
        $w = trim($d['font-weight'] ?? '400');
        if (!preg_match('/^\d{1,4}( \d{1,4})?$/', $w)) continue;
        $range = trim($d['unicode-range'] ?? '');
        if ($range !== '' && !preg_match('/^[Uu]\+[0-9A-Fa-f?\-, Uu+]+$/', $range)) $range = '';
        $out[] = ['family' => trim($d['font-family'] ?? '', " '\""), 'style' => ($d['font-style'] ?? 'normal') === 'italic' ? 'italic' : 'normal',
            'weight' => $w, 'src' => $u[1], 'range' => $range, 'subset' => $x[1] ?? '', 'format' => preg_match("~format\\(['\"]?(woff2|woff|truetype|opentype)~", $d['src'], $f) ? $f[1] : 'woff2'];
    }
    return $out;
}

function font_google_list(): array
{
    return ['fonts' => array_map(fn($f) => ['family' => $f[0], 'hebrew' => $f[1], 'weights' => $f[5] === 'var' ? null : $f[2], 'range' => $f[5] === 'var' ? $f[2] : null,
        'italic' => $f[3], 'category' => $f[4]], FONT_GOOGLE)];
}

/**
 * המשקלים שגופן מ-Google באמת מספק. css2 משמיט משקל שאינו בגופן (ולא נכשל) — לכן המשקלים בפועל = מה שחזר.
 * מחזיר [המשקלים שנבחרו ונמצאו, כל המשקלים שבגופן, ה-@font-face של הנבחרים].
 */
function font_google_probe(string $family, array $weights, bool $italic): array
{
    $ws = fn(array $faces) => array_values(array_unique(array_map(fn($f) => (int)explode(' ', $f['weight'])[0], $faces)));
    $all = $ws(font_css2_parse(font_http(font_css2_url($family, [100, 200, 300, 400, 500, 600, 700, 800, 900], false), FONT_CSS_MAX)));
    sort($all);
    if (!$all) throw new ApiError("Google Fonts: אין גופן בשם \"$family\"", 404);
    $ok = array_values(array_intersect($weights, $all)) ?: [in_array(400, $all, true) ? 400 : $all[0]];
    return [$ok, $all, font_css2_parse(font_http(font_css2_url($family, $ok, $italic), FONT_CSS_MAX))];
}

/** Google Fonts ← <link>: בודק שהמשפחה והמשקלים קיימים, ומחזיר את ה-href ואת המשקלים בפועל. לא כותב דבר. */
function font_google_add(array $user, array $b): array
{
    $family = font_family_ok($b['family'] ?? null);
    $italic = !empty($b['italic']);
    [$weights, $all] = font_google_probe($family, font_weights($b['weights'] ?? [400]), $italic);
    return ['family' => $family, 'weights' => $weights, 'available' => $all, 'italic' => $italic, 'href' => font_css2_url($family, $weights, $italic)];
}

/**
 * "הבא לפרויקט": מוריד את קובצי ה-woff2 של משפחה מ-Google ושומר אותם ב-media/fonts/. מחזיר את הקבצים ואת ה-@font-face
 * (נתיב יחסי ל-tokens.css — "../media/fonts/…"). הכתיבה ל-CSS — בעורך, כעסקה עם שם.
 */
function font_fetch(array $user, array $b): array
{
    pf_seed($user);
    pf_guard($user);
    $family = font_family_ok($b['family'] ?? null);
    $italic = !empty($b['italic']);
    $range = isset($b['range']) && is_array($b['range']) && count($b['range']) === 2 ? font_weights($b['range']) : null;
    $faces = $range ? font_css2_parse(font_http(font_css2_url($family, [], $italic, [$range[0], $range[count($range) - 1]]), FONT_CSS_MAX))
        : font_google_probe($family, font_weights($b['weights'] ?? [400]), $italic)[2];
    if (!$faces) throw new ApiError('Google Fonts לא החזיר קבצים', 502);
    if (count($faces) > FONT_FETCH_MAX) throw new ApiError('יותר מדי קבצים (' . count($faces) . ') — בחרו פחות משקלים', 413);
    $total = 0; $files = []; $saved = [];
    try {
        foreach ($faces as $f) {
            $bytes = font_http($f['src'], FONT_MAX);
            if (($kind = font_kind($bytes)) === null) throw new ApiError('Google Fonts החזיר קובץ שאינו גופן', 502);
            if (($total += strlen($bytes)) > FONT_FETCH_TOTAL) throw new ApiError('הגופן כבד מדי להורדה (מעל 12MB)', 413);
            $slug = $family . '-' . str_replace(' ', '-', $f['weight']) . ($f['style'] === 'italic' ? '-italic' : '') . ($f['subset'] !== '' ? '-' . $f['subset'] : '');
            $r = font_store($user, $slug, $kind, $bytes, 'גופן הובא מ-Google Fonts');
            $saved[] = (int)$r['id'];
            $files[] = ['file' => pf_public($r), 'weight' => $f['weight'], 'style' => $f['style'], 'range' => $f['range'], 'format' => FONT_FORMAT[$kind]];
        }
    } catch (Throwable $e) {
        // חצי הורדה לא נשארת בפרויקט: מה שכבר נשמר — לסל
        if ($saved) pf_tx(function () use ($saved, $user) { $st = db()->prepare('UPDATE pf_files SET deleted_at = ?, deleted_by = ?, deleted_name = ? WHERE id = ?'); foreach ($saved as $id) $st->execute([now_iso(), $user['sub'], $user['name'] ?? '', $id]); });
        throw $e;
    }
    audit_as($user, 'font_fetch', $family . ' · ' . count($files) . ' קבצים · ' . round($total / 1024) . ' ק"ב');
    return ['family' => $family, 'files' => $files, 'bytes' => $total];
}
