<?php
/**
 * כותרות האבטחה של האפליקציה (פרוסה C1 — docs/spec-v2/slices/C1-studio-csp.md) ודוחות ההפרה ליומן (F1).
 *
 * שלוש מדיניות, תמיד ככותרת שרת (לא <meta>):
 *  - הסטודיו (index.php → studio.html) — שלב א: נאכף רק object-src / base-uri / form-action, והמדיניות המלאה
 *    ב-Report-Only. התצוגה הישנה היא srcdoc ויורשת את המדיניות — לכן המלאה אינה נאכפת כאן (§1).
 *  - העורך (editor.php) — שלב ב: המדיניות המלאה נאכפת (§2, §2א).
 *  - תשובות API (api.php, agent.php, csp.php) — default-src 'none'; frame-ancestors 'none'; sandbox. JSON אינו מסמך.
 *
 * ⚠️ למה לא .htaccess: השער של הפלטפורמה מעביר כל קובץ סטטי בהפניה פנימית ל-platform/gate.php, ו-Header set של
 * התיקייה הזו אינו חל אחרי הפניה כזו. לכן הסטודיו מוגש מ-index.php; המעטפת (studio.html) חסומה לגישה ישירה, ו-index.html
 * (הכתובת הישנה) מופנית ל-index.php (.htaccess) — כך אין כתובת שמגישה את הסטודיו בלי הכותרות.
 *
 * דוחות: report-uri לנקודה csp.php?p=<מדיניות>. נצברים בטבלה csp_reports — שורה לכל (מדיניות, אכיפה, הנחיה, מקור
 * חסום, מסמך) עם מונה — ונכתבים ליומן המפתח כשורה מקופלת (fold) ליממה. חסימה צפויה (משאב חיצוני בקנבס של העורך,
 * §2א) נספרת בטבלה בלבד — אינה שגיאה ביומן.
 */

declare(strict_types=1);

require_once __DIR__ . '/devlog.php';

const CSP_PHASE_A = "object-src 'none'; base-uri 'self'; form-action 'self'";
const CSP_API = "default-src 'none'; frame-ancestors 'none'; sandbox";
const CSP_POLICIES = ['studio', 'editor', 'live'];
const CSP_MAX_GROUPS = 500;                // כמה קבוצות לכל היותר בטבלה; מעבר לזה — קבוצת "*" אחת
const CSP_MAX_BODY = 65536;
const CSP_MAX_PER_REQUEST = 20;
// חסימה צפויה בעורך (§2א): משאב חיצוני של העמוד בקנבס. לא שגיאה — נספרת בנפרד.
const CSP_EXPECTED_DIRECTIVES = ['img-src', 'media-src', 'font-src', 'style-src', 'style-src-elem'];

/** נתיב התיקייה של האפליקציה בכתובת (בלי סכמה ומארח), מתוך הסקריפט שרץ. */
function csp_dir(): string
{
    return rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
}

/** המארח של הבקשה, מסונן לתווים של שם מארח. */
function csp_host(): string
{
    return (string)preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
}

function csp_report_uri(string $policy): string
{
    return csp_dir() . '/csp.php?p=' . $policy;
}

/** מקור האתר המחובר (למדיה "באתר"), מההגדרות; בלי מסד או בלי הגדרה — ''. */
function csp_site(): string
{
    try {
        require_once __DIR__ . '/publish.php';
        $u = parse_url(site_url_setting());
        if (is_array($u) && ($u['scheme'] ?? '') === 'https' && preg_match('/^[A-Za-z0-9.-]+$/', (string)($u['host'] ?? ''))) return 'https://' . $u['host'];
    } catch (Throwable $e) {}
    return '';
}

/**
 * X1 (באישור הבקרה): עורך התמלילים ב-iframe של 📝 — הכתובת המלאה של התיקייה (סכמה + מארח + נתיב עם / בסוף), לא מארח ולא wildcard.
 * KOL_WP_URL אם הוגדר (בדיקות), אחרת WordProcessorClient::EDITOR. כתובת שאינה בצורה הזו — לא נוספת כלום.
 */
function csp_wp_frame(): string
{
    if (!defined('KOL_WP_URL') && !class_exists('WordProcessorClient', false)) require_once __DIR__ . '/WordProcessorClient.php';
    $u = defined('KOL_WP_URL') ? (string)KOL_WP_URL : WordProcessorClient::EDITOR;
    return preg_match('#^https://[a-z0-9.-]+(:\d+)?/[A-Za-z0-9._~/-]*/$#', $u) ? ' ' . $u : '';
}

/** המדיניות המלאה (C1 §2, שלב ב; ההבדלים של §2א). $self — כתובת התיקייה בלי סכמה; $site — מקור האתר המחובר, או ''. */
function editor_csp(string $self, string $site): string
{
    $media = "'self' blob: data:" . ($site !== '' ? ' ' . $site : '');
    return implode('; ', [
        "default-src 'none'", "script-src 'self'", "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com", 'img-src ' . $media, 'media-src ' . $media,
        "font-src 'self' data: https://fonts.gstatic.com", "connect-src 'self'", "worker-src 'self' blob:",
        'frame-src ' . $self . '/live.php ' . $self . '/preview/' . csp_wp_frame(), "frame-ancestors 'self'", "base-uri 'self'", "object-src 'none'", "form-action 'self'",
    ]);
}

/** הכותרות המשותפות לכל תשובה. */
function csp_common_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

/** הסטודיו — שלב א: שלוש הנחיות נאכפות, והמדיניות המלאה בדיווח בלבד. */
function csp_studio_headers(): void
{
    csp_common_headers();
    header('Content-Security-Policy: ' . CSP_PHASE_A . '; report-uri ' . csp_report_uri('studio'));
    header('Content-Security-Policy-Report-Only: ' . editor_csp(csp_host() . csp_dir(), csp_site()) . '; report-uri ' . csp_report_uri('studio'));
}

/** תשובת API: לעולם אינה מסמך. */
function csp_api_headers(): void
{
    csp_common_headers();
    header('Content-Security-Policy: ' . CSP_API);
}

// ─────────────────────────── דוחות ההפרה ───────────────────────────

function csp_table(): PDO
{
    $pdo = db();
    static $done = false;
    if (!$done) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS csp_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT, policy TEXT NOT NULL, disposition TEXT NOT NULL, directive TEXT NOT NULL,
            blocked TEXT NOT NULL, doc TEXT NOT NULL, expected INTEGER NOT NULL DEFAULT 0, count INTEGER NOT NULL DEFAULT 0,
            first_at TEXT NOT NULL, last_at TEXT NOT NULL, source TEXT NOT NULL DEFAULT \'\', sample TEXT NOT NULL DEFAULT \'\',
            UNIQUE (policy, disposition, directive, blocked, doc))');
        $done = true;
    }
    return $pdo;
}

/** כתובת מהדוח → צורה מקובצת: מקור זר — רק scheme://host[:port]; אותו מקור — הנתיב בלי שאילתה; מילת מפתח — כמו שהיא. */
function csp_norm_url(string $u): string
{
    $u = trim($u);
    if ($u === '') return '';
    if (preg_match('/^[a-z-]{1,20}$/', $u)) return $u;                       // inline · eval · wasm-eval · trusted-types-sink · data · blob
    if (preg_match('~^(about|data|blob|filesystem):~i', $u, $m)) return strtolower($m[1]) . ($m[1] === 'about' ? ':' . preg_replace('/[^a-z]/', '', strtolower(substr($u, 6, 20))) : '');
    $p = parse_url($u);
    if (!is_array($p) || !isset($p['scheme'], $p['host'])) return 'other';
    $origin = strtolower($p['scheme']) . '://' . strtolower(preg_replace('/[^A-Za-z0-9.\[\]:-]/', '', $p['host'])) . (isset($p['port']) ? ':' . (int)$p['port'] : '');
    $host = strtolower(csp_host());
    if ($host !== '' && strtolower(preg_replace('/[^A-Za-z0-9.\[\]:-]/', '', $p['host']) . (isset($p['port']) ? ':' . (int)$p['port'] : '')) === $host) {
        $path = (string)($p['path'] ?? '/');
        $dir = csp_dir() . '/';
        if (str_starts_with($path, $dir)) $path = substr($path, strlen($dir));
        if ($path === '') $path = './';
        return "'self':" . mb_substr(preg_replace('~[^\w./%-]~u', '', $path), 0, 120);
    }
    return mb_substr($origin, 0, 120);
}

/** דוח אחד, בשני הפורמטים (report-uri: מפתחות עם מקף; Reporting API: camelCase). */
function csp_parse_one(array $r): ?array
{
    $g = fn(string ...$k) => (string)array_reduce($k, fn($c, $x) => $c ?? (is_scalar($r[$x] ?? null) ? $r[$x] : null));
    $dir = strtolower($g('effective-directive', 'effectiveDirective', 'violated-directive', 'violatedDirective'));
    $dir = preg_match('/^([a-z-]{1,40})/', $dir, $m) ? $m[1] : '';
    if ($dir === '') return null;
    $disp = strtolower($g('disposition'));
    return [
        'directive' => $dir,
        'disposition' => $disp === 'report' ? 'report' : 'enforce',
        'blocked' => csp_norm_url($g('blocked-uri', 'blockedURL')) ?: 'none',
        'doc' => csp_norm_url($g('document-uri', 'documentURL')) ?: 'none',
        'source' => mb_substr(csp_norm_url($g('source-file', 'sourceFile')) . ':' . (int)$g('line-number', 'lineNumber'), 0, 160),
        'sample' => mb_substr((string)devlog_scrub($g('script-sample', 'sample')), 0, 80),
    ];
}

/** גוף הבקשה → רשימת דוחות. */
function csp_parse(string $raw): array
{
    $j = json_decode($raw, true);
    if (!is_array($j)) return [];
    $list = isset($j['csp-report']) ? [$j['csp-report']] : (array_is_list($j) ? array_map(fn($x) => is_array($x) ? ($x['body'] ?? $x) : null, $j) : [$j]);
    $out = [];
    foreach (array_slice($list, 0, CSP_MAX_PER_REQUEST) as $r) if (is_array($r) && ($p = csp_parse_one($r))) $out[] = $p;
    return $out;
}

/** חסימה צפויה: משאב חיצוני (http/https זר) של העמוד בקנבס של העורך — §2א, "אין דיווח הפרות CSP על החסימות האלה". */
function csp_expected(string $policy, array $r): bool
{
    return $policy === 'editor' && $r['disposition'] === 'enforce' && in_array($r['directive'], CSP_EXPECTED_DIRECTIVES, true)
        && (bool)preg_match('~^https?://~', $r['blocked']);
}

/** קבלת דוחות: נצברים בטבלה, ומה שאינו צפוי — גם ליומן המפתח (שורה מקופלת ליממה). מחזיר כמה נקלטו. */
function csp_report_accept(?array $user, string $policy, string $raw): int
{
    if (!$user || !in_array($policy, CSP_POLICIES, true) || strlen($raw) > CSP_MAX_BODY) return 0;
    $reports = csp_parse($raw);
    if (!$reports) return 0;
    $pdo = csp_table();
    $now = now_iso();
    $n = 0;
    foreach ($reports as $r) {
        $exp = csp_expected($policy, $r);
        $key = [$policy, $r['disposition'], $r['directive'], $r['blocked'], $r['doc']];
        $up = $pdo->prepare('UPDATE csp_reports SET count = count + 1, last_at = ?, source = ?, sample = ? WHERE policy = ? AND disposition = ? AND directive = ? AND blocked = ? AND doc = ?');
        $up->execute(array_merge([$now, $r['source'], $r['sample']], $key));
        if (!$up->rowCount()) {
            if ((int)$pdo->query('SELECT COUNT(*) FROM csp_reports')->fetchColumn() >= CSP_MAX_GROUPS) $key = [$policy, $r['disposition'], '*', '*', '*'];
            $pdo->prepare('INSERT INTO csp_reports (policy, disposition, directive, blocked, doc, expected, count, first_at, last_at, source, sample) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)
                           ON CONFLICT (policy, disposition, directive, blocked, doc) DO UPDATE SET count = count + 1, last_at = excluded.last_at')
                ->execute(array_merge($key, [$exp ? 1 : 0, $now, $now, $r['source'], $r['sample']]));
        }
        if (!$exp) {
            devlog_write($r['disposition'] === 'report' ? 'warn' : 'error', 'csp', 'csp.' . $r['directive'], [
                'message' => 'CSP ' . ($r['disposition'] === 'report' ? '(דיווח בלבד) ' : '') . $r['directive'] . ' · ' . $r['blocked'] . ' · ' . $r['doc'] . ' · ' . $policy,
                'user' => $user, 'fold' => true, 'method' => 'CLIENT', 'action' => 'csp-report',
                'data' => ['policy' => $policy, 'disposition' => $r['disposition'], 'directive' => $r['directive'], 'blocked' => $r['blocked'],
                           'doc' => $r['doc'], 'at' => $r['source'], 'sample' => $r['sample']],
            ]);
        }
        $n++;
    }
    return $n;
}

/** הרשימה המקובצת (למפתח): הכי תכופות קודם. ?expected=0|1 לסינון. */
function csp_reports(array $q = []): array
{
    $w = isset($q['expected']) && in_array((string)$q['expected'], ['0', '1'], true) ? ' WHERE expected = ' . (int)$q['expected'] : '';
    $rows = csp_table()->query('SELECT policy, disposition, directive, blocked, doc, expected, count, first_at, last_at, source, sample FROM csp_reports' . $w . ' ORDER BY count DESC, last_at DESC LIMIT 500')->fetchAll();
    foreach ($rows as &$r) { $r['expected'] = (bool)$r['expected']; $r['count'] = (int)$r['count']; }
    return ['reports' => $rows, 'total' => array_sum(array_column($rows, 'count'))];
}
