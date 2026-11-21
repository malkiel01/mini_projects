<?php
/**
 * בינה — הליבה (אפיון 11): הכספת, בחירת המסלול, המכסות והשריון, **השער ai_run()**, היישוב ורישום השימוש.
 * מה הבינה *עושה* — פרוסות 12 ו-17; כאן רק התשתית. המסכים והניהול: lib/ai_admin.php. המתאמים: lib/ai_adapter.php.
 *
 * **ai_run() היא הדרך היחידה לקרוא למודל** — שום קובץ אחר אינו נוגע במתאם (בדיקה סטטית). הקריאה האחרת היחידה לספק היא
 * "בדיקת חיבור" (apr_ai_conn_test), שאינה מפעילה מודל ואינה שולחת תוכן.
 *
 * **אין נפילה בין מסלולים (§4):** המסלול נקבע לפני הבקשה לפי מצב ההגדרות — לא לפי תוצאה של בקשה. משרד עם חיבור אישי
 * שאינו מבוטל הוא במסלול האישי גם כשהחיבור "לא תקין": הבקשה נחסמת (no_connection), ואינה עוברת למנוהל.
 *
 * **המפתח (§7):** מוצפן ב-secretbox במפתח שנגזר מ-data/secret.key; מפוענח רק כאן, רגע לפני הקריאה למתאם; אינו נכנס לשום
 * תשובה, יומן, חריגה או שורה במסד. כל פרמטר שנושא אותו מסומן #[SensitiveParameter].
 *
 * **ב-ai_runs אין תוכן (§3):** לא הבקשה, לא התשובה, לא הודעת השגיאה של הספק. הטקסט חוזר לקורא של ai_run בלבד, והוא שומר
 * אותו בישות שלו, תחת ההרשאות של התיק.
 *
 * **כסף בלי נקודה צפה:** מחיר במיליוניות דולר למיליון טוקנים, שער במיליוניות ש"ח לדולר, עלות באגורות — תמיד מעוגלת כלפי מעלה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/ai_adapter.php';

const APR_AI_POLICY_VERSION = 1;             // גרסת נוסח המדיניות; שינוי מהותי בנוסח = מספר חדש = אישור מחדש בכל משרד
const APR_AI_STALE = 900;                    // שריון שלא יושב 15 דקות — מיושב כשימוש משוער (§6.2)
const APR_AI_AUTH_STRIKES = 3;               // כשלי אימות רצופים עד "לא תקין" (§7)
const APR_AI_MAX_PARTS = 20;
const APR_AI_MAX_TEXT = 2_000_000;           // בתים של טקסט בבקשה אחת
const APR_AI_MAX_FILES = 22_000_000;         // בתים לכל הקבצים של בקשה אחת יחד (הספק מגביל בקשה ל-32MB, ו-base64 מנפח בשליש) — נבדק לפני שקובץ נקרא
const APR_AI_MAX_EST = 4_000_000;            // טוקני קלט משוערים — מעבר לזה הבקשה נדחית לפני כל דבר
const APR_AI_TOKENS_IMAGE = 5000;            // אומדן לתמונה — בכוונה גבוה
const APR_AI_TOKENS_PAGE = 3000;             // אומדן לעמוד PDF
const APR_AI_PAGE_BYTES = 5000;              // PDF: לפחות עמוד לכל 5KB — מספר העמודים שבקובץ נגזר מתוכן שהמשתמש העלה, ואינו נאמן לבדו
const APR_AI_MAX_REPORT_IN = 5_000_000;      // דיווח טוקנים מהספק מעל זה אינו סביר — נחשב "לא דווח" (וגם שומר על שלם של 64 סיביות בחישוב העלות)
const APR_AI_MAX_REPORT_OUT = 1_000_000;
const APR_AI_BLOCKS = ['quota', 'policy', 'no_connection', 'no_assignment', 'case_no_ai', 'model_not_allowed'];
const APR_AI_TRACKS = ['managed', 'personal'];

// ── מספרים ────────────────────────────────────────────────────

/** מספר עשרוני מהמשתמש ← שלם מוגדל ב-10^scale. בלי float. null / '' ← null. */
function apr_ai_dec(mixed $v, int $scale, int $digits): ?int
{
    if ($v === null || $v === '') return null;
    $s = is_int($v) ? (string)$v : (is_string($v) ? trim($v) : apr_fail('ai_number'));
    if (preg_match('/^(\d{1,' . $digits . '})(?:\.(\d{0,' . $scale . '}))?\z/', $s, $m) !== 1) apr_fail('ai_number');
    return (int)$m[1] * 10 ** $scale + (int)str_pad($m[2] ?? '', $scale, '0');
}

function apr_ai_dec_out(?int $n, int $scale): ?string
{
    if ($n === null) return null;
    $s = str_pad((string)$n, $scale + 1, '0', STR_PAD_LEFT);
    $frac = rtrim(substr($s, -$scale), '0');
    return substr($s, 0, -$scale) . ($frac === '' ? '' : '.' . $frac);
}

/** חלוקה שלמה עם עיגול כלפי מעלה (לשלמים אי-שליליים). */
function apr_ai_ceil_div(int $a, int $b): int
{
    return intdiv($a + $b - 1, $b);
}

/**
 * עלות באגורות לפי טוקנים, מחירון (מיליוניות דולר למיליון טוקנים) ושער (מיליוניות ש"ח לדולר). null כשחסר מחיר או שער.
 * כל שלב מעוגל כלפי מעלה — האומדן שמרני. הגבולות (4M טוקנים, 1000$ למיליון, שער עד 1000) שומרים על שלם של 64 סיביות.
 */
function apr_ai_cost(int $in, int $out, ?int $priceIn, ?int $priceOut, ?int $fx): ?int
{
    if ($priceIn === null || $priceOut === null || $fx === null) return null;
    $micro = apr_ai_ceil_div($in * $priceIn, 1_000_000) + apr_ai_ceil_div($out * $priceOut, 1_000_000);   // מיליוניות דולר
    return apr_ai_ceil_div($micro * $fx, 10_000_000_000);
}

function apr_ai_fx(): ?int
{
    $v = apr_val("SELECT rate_to_ils FROM fx_rates WHERE currency = 'USD'");
    return $v === false ? null : (int)$v;
}

// ── הכספת ─────────────────────────────────────────────────────

function apr_ai_vault_key(): string
{
    return hash_hkdf('sha256', apr_secret(), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'apr:ai-key');
}

/** מפתח נפרד לגיבוב הבקשה (request_hash) — לא מפתח הכספת. */
function apr_ai_hash_key(): string
{
    return hash_hkdf('sha256', apr_secret(), 32, 'apr:ai-request');
}

/** מפתח תקין: 20–300 תווי ASCII מודפסים, בלי רווח. */
function apr_ai_key_clean(#[\SensitiveParameter] mixed $v): string
{
    $s = is_string($v) ? trim($v) : '';
    if (preg_match('/^[\x21-\x7E]{20,300}\z/', $s) !== 1) apr_fail('ai_key_invalid');
    return $s;
}

/** מצפין מפתח לחיבור. ה-pid של החיבור נכנס לתוכן המוצפן — תיבה של חיבור אחד אינה נפתחת כחיבור אחר. */
function apr_ai_key_box(string $connPid, #[\SensitiveParameter] string $key): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return $nonce . sodium_crypto_secretbox($connPid . "\n" . $key, $nonce, apr_ai_vault_key());
}

/** המפתח המפוענח — לשימוש ai_run ובדיקת החיבור בלבד. null כשאין, או כשאינו נפתח. */
function apr_ai_key_open(array $conn): ?string
{
    $blob = $conn['key_enc'] ?? null;
    $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    if (!is_string($blob) || strlen($blob) <= $n) return null;
    $plain = sodium_crypto_secretbox_open(substr($blob, $n), substr($blob, 0, $n), apr_ai_vault_key());
    if ($plain === false || !str_starts_with($plain, $conn['pid'] . "\n")) return null;
    return substr($plain, strlen($conn['pid']) + 1);
}

// ── תקופות ────────────────────────────────────────────────────

function apr_ai_local(?int $ts = null): DateTimeImmutable
{
    return (new DateTimeImmutable('@' . ($ts ?? apr_time())))->setTimezone(new DateTimeZone('Asia/Jerusalem'));
}

/** מפתח התקופה של עכשיו, בשעון ישראל. */
function apr_ai_period_key(string $period): string
{
    return match ($period) { 'month' => apr_ai_local()->format('Y-m'), 'day' => apr_ai_local()->format('Y-m-d'), default => 'total' };
}

/** מתי המכסה מתאפסת (UTC), או null למכסה כוללת. */
function apr_ai_period_reset(string $period): ?string
{
    $d = apr_ai_local();
    $next = match ($period) { 'month' => $d->modify('first day of next month')->setTime(0, 0), 'day' => $d->modify('+1 day')->setTime(0, 0), default => null };
    return $next ? apr_iso($next->getTimestamp()) : null;
}

// ── אומדן ─────────────────────────────────────────────────────

/** אומדן מקומי של טוקני טקסט: בית אחד לשליש טוקן (אנגלית ≈ 4 בתים לטוקן, עברית ≈ 2–3) — בכוונה גבוה. */
function apr_ai_text_tokens(int $bytes): int
{
    return apr_ai_ceil_div($bytes, 3);
}

/** @param array $parts החלקים אחרי בדיקה: text / image / pdf עם bytes ו-pages. מחזיר [טוקני קלט × 1.2, תמונות, עמודים]. */
function apr_ai_estimate(array $parts, ?string $system): array
{
    $tokens = apr_ai_text_tokens(strlen((string)$system)) + 50;
    $images = $pages = 0;
    foreach ($parts as $p) {
        if ($p['type'] === 'text') $tokens += apr_ai_text_tokens(strlen($p['text']));
        elseif ($p['type'] === 'image') { $tokens += APR_AI_TOKENS_IMAGE; $images++; }
        else {
            // PDF: הגדול מבין מספר העמודים שנרשם לבין עמוד לכל 5KB — שמרני, ואינו נשען על מספר שנגזר מהקובץ עצמו
            $n = max(1, (int)($p['pages'] ?? 0), apr_ai_ceil_div((int)$p['bytes'], APR_AI_PAGE_BYTES));
            $tokens += $n * APR_AI_TOKENS_PAGE;
            $pages += $n;
        }
    }
    return [apr_ai_ceil_div($tokens * 12, 10), $images, $pages];
}

// ── קטלוג ─────────────────────────────────────────────────────

function apr_ai_model_row(string $provider, string $modelId): ?array
{
    return apr_row('SELECT * FROM ai_models WHERE provider_key = ? AND model_id = ?', [$provider, $modelId]);
}

function apr_ai_model_priced(array $m): bool
{
    return $m['price_in'] !== null && $m['price_out'] !== null && $m['price_checked_at'] !== null;
}

/** המודלים שמותרים במסלול הזה, לפי הסדר: פעילים; במנוהל — רק מתוך ההקצאה, ורק מי שיש לו מחיר ותאריך בדיקה (11-E3). */
function apr_ai_models_for(array $route): array
{
    $rows = apr_rows("SELECT * FROM ai_models WHERE provider_key = ? AND status = 'active' ORDER BY id", [$route['conn']['provider_key']]);
    if ($route['track'] !== 'managed') return $rows;
    $allowed = json_decode((string)$route['assignment']['models'], true) ?: [];
    return array_values(array_filter($rows, fn($m) => apr_ai_model_priced($m) && (!$allowed || in_array($m['model_id'], $allowed, true))));
}

/** המודל של הבקשה: המבוקש אם הוא מותר; בלי מבוקש — ברירת המחדל אם היא מותרת, אחרת המותר הראשון (מקרה קצה 8). null = אין. */
function apr_ai_model_pick(array $route, ?string $requested): ?array
{
    $list = apr_ai_models_for($route);
    $by = array_column($list, null, 'model_id');
    if ($requested !== null) return $by[$requested] ?? null;
    $default = $route['track'] === 'managed' ? $route['assignment']['default_model'] : $route['conn']['default_model'];
    return ($default !== null ? ($by[$default] ?? null) : null) ?? ($list[0] ?? null);
}

// ── מדיניות ───────────────────────────────────────────────────

function apr_ai_policy(int $office): array
{
    $p = apr_row('SELECT * FROM ai_policies WHERE office_id = ?', [$office]);
    return $p ?? ['office_id' => $office, 'enabled' => 0, 'approved_by' => null, 'approved_at' => null, 'approved_text_version' => null,
        'approved_providers' => '{}', 'allowed_tracks' => '["managed","personal"]', 'prefer_managed' => 0];
}

/** הבינה דלוקה למשרד: אושרה, ובגרסת הנוסח הנוכחית. */
function apr_ai_policy_on(array $p): bool
{
    return (int)$p['enabled'] === 1 && (int)$p['approved_text_version'] === APR_AI_POLICY_VERSION;
}

/** הספק אושר בידי המשרד בגרסת תיאור הפרטיות הנוכחית שלו (שינוי מהותי או ספק חדש ← אישור מחדש, §8). */
function apr_ai_policy_covers(array $p, string $provider): bool
{
    $ok = json_decode((string)$p['approved_providers'], true) ?: [];
    $rev = apr_val('SELECT privacy_rev FROM ai_providers WHERE key = ?', [$provider]);
    return $rev !== false && (int)($ok[$provider] ?? 0) === (int)$rev;
}

// ── המסלול ────────────────────────────────────────────────────

function apr_ai_personal_conn(int $office): ?array
{
    return apr_row("SELECT * FROM ai_connections WHERE track = 'personal' AND office_id = ? AND status <> 'revoked'", [$office]);
}

/** ההקצאה במסלול המנוהל: של המשתמש, ואם אין — של המשרד. */
function apr_ai_assignment(int $office, ?int $user): ?array
{
    $a = $user !== null ? apr_row("SELECT * FROM ai_assignments WHERE scope = 'user' AND scope_id = ?", [$user]) : null;
    return $a ?? apr_row("SELECT * FROM ai_assignments WHERE scope = 'office' AND scope_id = ?", [$office]);
}

/**
 * בחירת המסלול והחיבור (§4) — דטרמיניסטית, לפי ההגדרות בלבד:
 *   1. למשרד חיבור אישי שאינו מבוטל, והמשרד לא בחר "מנוהל" ← אישי (גם כשהחיבור "לא תקין" — אז הבקשה תיחסם, לא תעבור מסלול).
 *   2. אחרת, למשתמש או למשרד הקצאה ← מנוהל.
 *   3. אחרת ← no_connection.
 * מחזיר ['track', 'conn', 'assignment'] או ['block' => סיבה, 'track' => ?].
 */
function apr_ai_route(int $office, ?int $user, array $policy): array
{
    $personal = apr_ai_personal_conn($office);
    if ($personal && !(int)$policy['prefer_managed']) {
        $route = ['track' => 'personal', 'conn' => $personal, 'assignment' => null];
    } else {
        $a = apr_ai_assignment($office, $user);
        if (!$a) return ['block' => 'no_connection', 'track' => null];
        $route = ['track' => 'managed', 'conn' => apr_row('SELECT * FROM ai_connections WHERE id = ?', [$a['connection_id']]), 'assignment' => $a];
    }
    if (!in_array($route['track'], json_decode((string)$policy['allowed_tracks'], true) ?: [], true)) return ['block' => 'policy'] + $route;
    if (!$route['conn'] || $route['conn']['status'] !== 'active') return ['block' => 'no_connection'] + $route;
    return $route;
}

// ── מכסות ─────────────────────────────────────────────────────

/** כל המכסות שחלות על בקשה במסלול הזה: של המשתמש, של המשרד ושל התיק (§6.1). */
function apr_ai_quotas_for(string $track, int $office, ?int $user, ?int $case): array
{
    return apr_rows("SELECT * FROM ai_quotas WHERE deleted_at IS NULL AND track = ? AND (
            (scope = 'user' AND scope_id = ? AND (office_id IS NULL OR office_id = ?)) OR (scope = 'office' AND scope_id = ?) OR (scope = 'case' AND scope_id = ?))
        ORDER BY id", [$track, $user ?? 0, $office, $office, $case ?? 0]);
}

function apr_ai_counter(int $quota, string $periodKey): array
{
    return apr_row('SELECT * FROM ai_usage_counters WHERE quota_id = ? AND period_key = ?', [$quota, $periodKey])
        ?? ['quota_id' => $quota, 'period_key' => $periodKey, 'used_money' => 0, 'used_tokens' => 0, 'reserved_money' => 0, 'reserved_tokens' => 0];
}

/** מכסה כפי שהיא מוצגת: התקרה, הניצול (כולל מה ששמור כרגע), היתרה, ומתי מתאפסת. סכומים — באגורות, ותמיד "משוער". */
function apr_ai_quota_out(array $q): array
{
    $c = apr_ai_counter((int)$q['id'], apr_ai_period_key($q['period']));
    $side = function (?int $limit, int $used, int $reserved): ?array {
        if ($limit === null) return null;
        $taken = $used + $reserved;
        return ['limit' => $limit, 'used' => $used, 'reserved' => $reserved, 'left' => max(0, $limit - $taken), 'warn' => $limit > 0 ? $taken * 5 >= $limit * 4 : true, 'over' => $taken > $limit];
    };
    return ['pid' => $q['pid'], 'track' => $q['track'], 'owner' => $q['owner'], 'scope' => $q['scope'], 'period' => $q['period'], 'hard' => (bool)$q['hard'],
        'money' => $side($q['limit_money'] === null ? null : (int)$q['limit_money'], (int)$c['used_money'], (int)$c['reserved_money']),
        'tokens' => $side($q['limit_tokens'] === null ? null : (int)$q['limit_tokens'], (int)$c['used_tokens'], (int)$c['reserved_tokens']),
        'resets_at' => apr_ai_period_reset($q['period'])];
}

/**
 * שריון (§6.2) — **נקרא רק בתוך טרנזקציה** (BEGIN IMMEDIATE): בודק את כל המכסות, ורק אם כולן מאפשרות — מוסיף לשמור.
 * מחזיר [] כשעבר (והשריון נרשם לבקשה $runId אחרי שנוצרה — ראו apr_ai_reserve_write), או את המכסה שחסמה.
 */
function apr_ai_reserve_check(array $quotas, int $tokens, ?int $money): ?array
{
    foreach ($quotas as $q) {
        if (!(int)$q['hard']) continue;
        $c = apr_ai_counter((int)$q['id'], apr_ai_period_key($q['period']));
        if ($q['limit_tokens'] !== null && (int)$c['used_tokens'] + (int)$c['reserved_tokens'] + $tokens > (int)$q['limit_tokens']) return $q;
        // מכסה בכסף בלי מחיר או בלי שער — אי אפשר להעריך, ולכן אי אפשר לאשר
        if ($q['limit_money'] !== null && ($money === null || (int)$c['used_money'] + (int)$c['reserved_money'] + $money > (int)$q['limit_money'])) return $q;
    }
    return null;
}

function apr_ai_reserve_write(int $runId, array $quotas, int $tokens, ?int $money): void
{
    foreach ($quotas as $q) {
        $key = apr_ai_period_key($q['period']);
        apr_exec('INSERT INTO ai_usage_counters (quota_id, period_key, reserved_money, reserved_tokens) VALUES (?, ?, ?, ?)
            ON CONFLICT(quota_id, period_key) DO UPDATE SET reserved_money = reserved_money + excluded.reserved_money, reserved_tokens = reserved_tokens + excluded.reserved_tokens',
            [$q['id'], $key, $money ?? 0, $tokens]);
        apr_insert('ai_run_reservations', ['run_id' => $runId, 'quota_id' => $q['id'], 'period_key' => $key, 'money' => $money ?? 0, 'tokens' => $tokens]);
    }
}

// ── יישוב ─────────────────────────────────────────────────────

/**
 * סוגר בקשה (§6.2, שלבים 4–6): משחרר את השריון ורושם שימוש — באותה תקופה שבה שוריין.
 *   done    + הספק דיווח טוקנים ← לפי הדיווח (גם אם חרג מהאומדן ומהתקרה); cost_basis = reported.
 *   done    בלי דיווח, או unknown ← האומדן הופך לשימוש; cost_basis = estimated.
 *   failed  ← השריון משתחרר, השימוש לא משתנה.
 * בטוח להרצה כפולה: בקשה שכבר נסגרה אינה נסגרת שוב (גם לא בידי שירות הרקע).
 */
function apr_ai_settle(int $runId, string $status, array $r = []): void
{
    apr_tx(function () use ($runId, $status, $r) {
        $run = apr_row('SELECT * FROM ai_runs WHERE id = ?', [$runId]);
        if (!$run || !in_array($run['status'], ['reserved', 'running'], true)) return;
        $in = is_int($r['in_tokens'] ?? null) && $r['in_tokens'] >= 0 ? $r['in_tokens'] : null;
        $out = is_int($r['out_tokens'] ?? null) && $r['out_tokens'] >= 0 ? $r['out_tokens'] : null;
        // דיווח שאינו סביר (מספר עצום) אינו נכנס למונים: נחשב "לא דווח" — האומדן הופך לשימוש, והחריגה נרשמת בקוד טכני
        if (($in !== null && $in > APR_AI_MAX_REPORT_IN) || ($out !== null && $out > APR_AI_MAX_REPORT_OUT)) {
            $in = $out = null;
            $r['error_code'] = 'usage_implausible';
        }
        $reported = $status === 'done' && $in !== null && $out !== null;
        $basis = $status === 'failed' ? null : ($reported ? 'reported' : 'estimated');
        $price = fn(?string $k) => $run[$k] === null ? null : (int)$run[$k];
        $reportedCost = $reported ? apr_ai_cost($in, $out, $price('price_in'), $price('price_out'), $price('fx_rate')) : null;
        $useTokens = $basis === null ? 0 : ($reported ? $in + $out : (int)$run['est_in_tokens'] + (int)$run['est_out_tokens']);
        $useMoney = $basis === null ? 0 : ($reported ? ($reportedCost ?? 0) : (int)($run['est_cost'] ?? 0));
        foreach (apr_rows('SELECT * FROM ai_run_reservations WHERE run_id = ? AND settled = 0', [$runId]) as $res) {
            apr_exec('UPDATE ai_usage_counters SET reserved_money = MAX(0, reserved_money - ?), reserved_tokens = MAX(0, reserved_tokens - ?),
                    used_money = used_money + ?, used_tokens = used_tokens + ? WHERE quota_id = ? AND period_key = ?',
                [$res['money'], $res['tokens'], $useMoney, $useTokens, $res['quota_id'], $res['period_key']]);
        }
        apr_exec('UPDATE ai_run_reservations SET settled = 1 WHERE run_id = ?', [$runId]);
        apr_exec('UPDATE ai_runs SET status = ?, in_tokens = ?, out_tokens = ?, reported_cost = ?, cost_basis = ?, stop_reason = ?, error_code = ?, finished_at = ? WHERE id = ?',
            [$status, $in, $out, $reportedCost, $basis, is_string($r['stop_reason'] ?? null) ? mb_substr($r['stop_reason'], 0, 40) : null,
                is_string($r['error_code'] ?? null) ? mb_substr($r['error_code'], 0, 40) : null, apr_now(), $runId]);
    });
}

/** שירות הרקע (§6.2, שלב 7): שריון שנשאר פתוח מעל 15 דקות — התהליך מת באמצע; מיושב כשימוש משוער. מחזיר כמה יושבו. */
function apr_ai_sweep(): int
{
    $n = 0;
    foreach (apr_rows("SELECT id FROM ai_runs WHERE status IN ('reserved', 'running') AND created_at < ? ORDER BY id LIMIT 200", [apr_iso(apr_time() - APR_AI_STALE)]) as $run) {
        apr_ai_settle((int)$run['id'], 'unknown', ['error_code' => 'stale']);
        $n++;
    }
    return $n;
}

apr_job_register('ai.reservations', 'בינה: יישוב שריוני מכסה שנשארו פתוחים מעל 15 דקות (כשימוש משוער)', function (): string {
    apr_ai_sweep();
    return 'done';
}, 300);

// ── תוצאת החיבור ─────────────────────────────────────────────

/** רושם על החיבור הצלחה או כשל אימות. 3 כשלי אימות רצופים ← "לא תקין", עם רשומת יומן שמנהל רואה (§7). */
function apr_ai_conn_result(int $connId, bool $ok, ?string $tech = null, bool $auth = false, bool $now = false): void
{
    apr_tx(function () use ($connId, $ok, $tech, $auth, $now) {
        $c = apr_row('SELECT * FROM ai_connections WHERE id = ?', [$connId]);
        if (!$c || $c['status'] === 'revoked') return;
        if ($ok) {
            apr_exec("UPDATE ai_connections SET fail_count = 0, last_ok_at = ?, last_error = NULL, status = 'active' WHERE id = ?", [apr_now(), $connId]);
            return;
        }
        if (!$auth) {
            apr_exec('UPDATE ai_connections SET last_error = ? WHERE id = ?', [$tech, $connId]);
            return;
        }
        $fails = (int)$c['fail_count'] + 1;
        $invalid = $now || $fails >= APR_AI_AUTH_STRIKES;
        apr_exec('UPDATE ai_connections SET fail_count = ?, last_error = ?, status = ? WHERE id = ?', [$fails, $tech, $invalid ? 'invalid' : $c['status'], $connId]);
        if ($invalid && $c['status'] !== 'invalid') {
            apr_audit('ai.connection.invalid', 'ai_connection', $c['pid'], ['track' => $c['track'], 'error' => $tech], null, $c['office_id'] === null ? null : (int)$c['office_id'], null, 'system');
        }
    });
}

// ── השער ──────────────────────────────────────────────────────

/** שורה של ai_runs כפי שהיא חוזרת לקורא — בלי מזהים פנימיים, ובלי תוכן (אין כזה בשורה). */
function apr_ai_run_out(array $run): array
{
    $int = fn(string $k) => $run[$k] === null ? null : (int)$run[$k];
    return ['run' => $run['pid'], 'status' => $run['status'], 'block_reason' => $run['block_reason'], 'track' => $run['track'], 'provider' => $run['provider_key'],
        'model' => $run['model_id'], 'purpose' => $run['purpose'], 'stop_reason' => $run['stop_reason'], 'error_code' => $run['error_code'],
        'usage' => ['in_tokens' => $int('in_tokens'), 'out_tokens' => $int('out_tokens'), 'est_in_tokens' => $int('est_in_tokens'), 'est_out_tokens' => $int('est_out_tokens'),
            'images' => $int('images'), 'pages' => $int('pages')],
        // שני הסכומים בנפרד (11-B3): האומדן שלפני הבקשה, והעלות לפי דיווח הספק. באגורות. אף אחד מהם אינו "חיוב".
        'cost' => ['estimated' => $int('est_cost'), 'reported' => $int('reported_cost'), 'basis' => $run['cost_basis']],
        'created_at' => $run['created_at'], 'finished_at' => $run['finished_at']];
}

/** בודק ומנרמל את התוכן. קובץ — רק מהתיק, ורק אם המשתמש רואה אותו (שלב 2). מחזיר [חלקים, חתימה-לגיבוב]. */
function apr_ai_parts(?array $ctx, ?array $case, mixed $content): array
{
    if (!is_array($content) || !$content || count($content) > APR_AI_MAX_PARTS) apr_fail('ai_content');
    $parts = $sig = [];
    $bytes = $fileBytes = 0;
    foreach ($content as $p) {
        if (!is_array($p)) apr_fail('ai_content');
        if (($p['type'] ?? null) === 'text' && is_string($p['text'] ?? null) && $p['text'] !== '') {
            $bytes += strlen($p['text']);
            $parts[] = ['type' => 'text', 'text' => $p['text']];
            $sig[] = ['t', hash('sha256', $p['text'])];
        } elseif (($p['type'] ?? null) === 'file') {
            if (!$case) apr_fail('not_found');
            $f = apr_file_row($case, $p['file'] ?? null);
            if ($ctx !== null && !apr_file_visible($ctx, $case, $f)) apr_fail('not_found');
            $kind = $f['mime'] === 'application/pdf' ? 'pdf' : (in_array($f['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) ? 'image' : apr_fail('ai_content'));
            // קובץ שהסורק דחה, או PDF מוגן בסיסמה — אינו נשלח
            if ($f['scan_state'] === 'rejected' || (int)$f['is_encrypted'] === 1) apr_fail('ai_content');
            $fileBytes += (int)$f['size'];
            if ($fileBytes > APR_AI_MAX_FILES) apr_fail('ai_too_large');
            $parts[] = ['type' => $kind, 'mime' => $f['mime'], 'bytes' => (int)$f['size'], 'pages' => $f['pages'] === null ? null : (int)$f['pages'], 'storage_key' => $f['storage_key']];
            $sig[] = ['f', $f['pid']];
        } else {
            apr_fail('ai_content');
        }
    }
    if ($bytes > APR_AI_MAX_TEXT) apr_fail('ai_too_large');
    return [$parts, $sig];
}

/**
 * **השער** (אפיון 11 §5). $req:
 *   ctx        ההקשר של המשתמש (apr_ctx), או null לשירות רקע.
 *   case       pid של התיק, או null. ברקע: case_id (מזהה פנימי) + office_id + on_behalf (על שם מי נספרת מכסת המשתמש — השמאי האחראי).
 *   purpose    'extract.fields', 'report.draft' … · model — מזהה מודל מבוקש, או null לברירת המחדל · system — הנחיה, או null
 *   content    [['type' => 'text', 'text' => …] | ['type' => 'file', 'file' => pid]] — **רק מה שהפעולה צריכה** (§8)
 *   max_output טוקני הפלט המרביים · op — מזהה הפעולה (אותו מזהה = אותה פעולה; אין קריאה שנייה).
 *
 * הסדר קבוע: 1 מזהה פעולה · 2 הרשאות (404) · 3 מדיניות · 4 "בלי בינה" · 5 מסלול וחיבור · 6 מודל · 7 יכולות · 8 שריון · 9 המתאם · 10 יישוב.
 * מחזיר את apr_ai_run_out() + 'text' (רק בבקשה שהושלמה עכשיו) + 'replayed' + 'quota' (המכסה שחסמה). חסימה אינה חריגה — היא תוצאה.
 * אין ניסיון חוזר אוטומטי, ואין מעבר למודל, למסלול או לחשבון אחר — בשום כשל.
 */
function ai_run(#[\SensitiveParameter] array $req): array
{
    $ctx = $req['ctx'] ?? null;
    $purpose = is_string($req['purpose'] ?? null) && preg_match('/^[a-z][a-z0-9_.]{2,60}\z/', $req['purpose']) === 1 ? $req['purpose'] : apr_fail('ai_request');
    $op = is_string($req['op'] ?? null) && preg_match('/^[A-Za-z0-9_.:-]{8,80}\z/', $req['op']) === 1 ? $req['op'] : apr_fail('ai_request');
    $requested = ($req['model'] ?? null) === null ? null : (is_string($req['model']) && preg_match('/^[A-Za-z0-9_.:@-]{1,100}\z/', $req['model']) === 1 ? $req['model'] : apr_fail('ai_request'));
    $system = ($req['system'] ?? null) === null ? null : (is_string($req['system']) && strlen($req['system']) <= 100_000 ? $req['system'] : apr_fail('ai_request'));
    $maxOut = is_int($req['max_output'] ?? null) && $req['max_output'] >= 1 && $req['max_output'] <= 200_000 ? $req['max_output'] : apr_fail('ai_request');

    if ($ctx !== null) {
        $office = apr_office_id($ctx);
        $user = (int)$ctx['user']['id'];
    } else {
        $office = is_int($req['office_id'] ?? null) ? $req['office_id'] : apr_fail('ai_request');
        $user = null;
    }
    $idem = $office . ':' . ($user ?? 0) . ':' . $op;

    // ── 2 (הרשאות) — לפני כל דבר שנרשם: בלי view על התיק ועל כל קובץ ← 404, ואין שורה ב-ai_runs (11-D3) ──
    $case = null;
    if ($ctx !== null && ($req['case'] ?? null) !== null) {
        $case = apr_case_get($ctx, $req['case']);
    } elseif ($ctx === null && ($req['case_id'] ?? null) !== null) {
        $case = apr_row('SELECT * FROM cases WHERE id = ? AND office_id = ? AND deleted_at IS NULL', [(int)$req['case_id'], $office]) ?? apr_fail('not_found');
    }
    [$parts, $sig] = apr_ai_parts($ctx, $case, $req['content'] ?? null);
    $quotaUser = $user ?? ($case !== null && $case['responsible_user_id'] !== null ? (int)$case['responsible_user_id'] : (is_int($req['on_behalf'] ?? null) ? $req['on_behalf'] : null));
    $hash = hash_hmac('sha256', (string)json_encode([$purpose, $requested, $system === null ? null : hash('sha256', $system), $sig, $maxOut, $case['pid'] ?? null]), apr_ai_hash_key());
    [$estIn, $images, $pages] = apr_ai_estimate($parts, $system);
    if ($estIn > APR_AI_MAX_EST) apr_fail('ai_too_large');

    // ── 1, 3–8 — בטרנזקציה אחת (נעילת כתיבה): בקשות מקבילות נבדקות אחת אחרי השנייה, ולכן אינן עוקפות תקרה ──
    $plan = apr_tx(function () use ($idem, $hash, $office, $user, $quotaUser, $case, $purpose, $requested, $parts, $estIn, $maxOut, $images, $pages) {
        // 1 — מזהה הפעולה כבר קיים: התוצאה הקודמת, בלי קריאה. אותו מזהה עם בקשה אחרת — נדחה (מקרה קצה 9)
        $prev = apr_row('SELECT * FROM ai_runs WHERE idempotency_key = ?', [$idem]);
        if ($prev) {
            if (!hash_equals((string)$prev['request_hash'], $hash)) apr_fail('ai_op_conflict');
            return ['replay' => $prev];
        }
        $base = ['office_id' => $office, 'case_id' => $case['id'] ?? null, 'user_id' => $user, 'quota_user_id' => $quotaUser, 'purpose' => $purpose,
            'est_in_tokens' => $estIn, 'est_out_tokens' => $maxOut, 'images' => $images ?: null, 'pages' => $pages ?: null, 'created_at' => apr_now()];
        $block = function (string $reason, array $more = [], ?array $quota = null) use ($base) {
            $pid = apr_pid();
            apr_insert('ai_runs', $more + $base + ['pid' => $pid, 'status' => 'blocked', 'block_reason' => $reason, 'finished_at' => apr_now()]);
            return ['blocked' => apr_row('SELECT * FROM ai_runs WHERE pid = ?', [$pid]), 'quota' => $quota];
        };

        // שותף ממשרד אחר אינו מפעיל בינה על חשבון המשרד הבעלים (מקרה קצה 7; פרוסה 05 תוסיף את ההיתר המפורש)
        if ($case !== null && (int)$case['office_id'] !== $office) return $block('no_assignment');
        // 3 — המדיניות של המשרד
        $policy = apr_ai_policy($office);
        if (!apr_ai_policy_on($policy)) return $block('policy');
        // 4 — תיק "בלי בינה"
        if ($case !== null && (int)apr_val('SELECT no_ai FROM case_ai WHERE case_id = ?', [$case['id']]) === 1) return $block('case_no_ai');
        // 5 — מסלול וחיבור
        $route = apr_ai_route($office, $quotaUser, $policy);
        $where = ['track' => $route['track'] ?? null, 'connection_id' => $route['conn']['id'] ?? null, 'provider_key' => $route['conn']['provider_key'] ?? null];
        if (isset($route['block'])) return $block($route['block'], $where);
        $provider = apr_row('SELECT * FROM ai_providers WHERE key = ?', [$route['conn']['provider_key']]);
        if (!$provider || $provider['status'] !== 'active' || !apr_ai_policy_covers($policy, $provider['key'])) return $block('policy', $where);
        // 6 — המודל
        $model = apr_ai_model_pick($route, $requested);
        if (!$model) return $block('model_not_allowed', $where + ['model_id' => $requested]);
        $where['model_id'] = $model['model_id'];
        if ($maxOut > (int)$model['max_output_tokens'] || $estIn + $maxOut > (int)$model['context_tokens']) apr_fail('ai_too_large');
        // 7 — סוג התוכן: המודל והמתאם יודעים לקבל אותו
        $caps = (json_decode((string)$model['capabilities'], true) ?: []);
        $adapterCaps = apr_ai_adapter($provider['adapter'])->capabilities($model['model_id']);
        foreach ($parts as $p) {
            $need = ['text' => 'text', 'image' => 'vision', 'pdf' => 'pdf'][$p['type']];
            if (empty($caps[$need]) || empty($adapterCaps[$need])) return $block('policy', $where);
        }
        // 8 — שריון מכסה. במסלול המנוהל מכסה קשיחה היא חובה — בלעדיה אין שימוש (§4)
        $fx = apr_ai_fx();
        $pin = $model['price_in'] === null ? null : (int)$model['price_in'];
        $pout = $model['price_out'] === null ? null : (int)$model['price_out'];
        $estCost = apr_ai_cost($estIn, $maxOut, $pin, $pout, $fx);
        $quotas = apr_ai_quotas_for($route['track'], $office, $quotaUser, $case['id'] ?? null);
        if ($route['track'] === 'managed' && !array_filter($quotas, fn($q) => (int)$q['hard'] === 1)) return $block('quota', $where + ['est_cost' => $estCost]);
        $stop = apr_ai_reserve_check($quotas, $estIn + $maxOut, $estCost);
        if ($stop) return $block('quota', $where + ['est_cost' => $estCost], apr_ai_quota_out($stop));
        $pid = apr_pid();
        $id = apr_insert('ai_runs', $where + $base + ['pid' => $pid, 'status' => 'reserved', 'est_cost' => $estCost, 'price_in' => $pin, 'price_out' => $pout,
            'fx_rate' => $fx, 'idempotency_key' => $idem, 'request_hash' => $hash]);
        apr_ai_reserve_write($id, $quotas, $estIn + $maxOut, $estCost);
        return ['run' => $id, 'conn' => $route['conn'], 'adapter' => $provider['adapter'], 'model' => $model['model_id']];
    });

    if (isset($plan['replay'])) return apr_ai_run_out($plan['replay']) + ['text' => null, 'replayed' => true, 'quota' => null];
    if (isset($plan['blocked'])) return apr_ai_run_out($plan['blocked']) + ['text' => null, 'replayed' => false, 'quota' => $plan['quota']];

    // ── 9 — הקריאה למתאם, **מחוץ לטרנזקציה**. המפתח מפוענח כאן, ואינו יוצא מהפונקציה ──
    $runId = (int)$plan['run'];
    $text = null;
    try {
        apr_exec("UPDATE ai_runs SET status = 'running', started_at = ? WHERE id = ? AND status = 'reserved'", [apr_now(), $runId]);
        $key = apr_ai_key_open($plan['conn']) ?? throw new AprAiError('auth', 'key_unreadable');
        $send = [];
        foreach ($parts as $p) {
            if ($p['type'] === 'text') { $send[] = $p; continue; }
            $data = @file_get_contents(apr_file_path($p['storage_key']));
            if (!is_string($data)) throw new AprAiError('rejected', 'file_unreadable');
            $send[] = ['type' => $p['type'], 'mime' => $p['mime'], 'data' => $data];
        }
        $r = apr_ai_adapter($plan['adapter'])->complete($key, ['model' => $plan['model'], 'max_tokens' => $maxOut, 'system' => $system, 'parts' => $send]);
        if (!is_array($r) || !is_string($r['text'] ?? null)) throw new AprAiError('unknown', 'bad_result');
        $text = $r['text'];
        // ── 10 — יישוב: שימוש מדווח מול השריון ──
        apr_ai_settle($runId, 'done', $r);
        apr_ai_conn_result((int)$plan['conn']['id'], true);
    } catch (AprAiError $e) {
        // דחייה ברורה או שלא נשלחה ← failed, והשריון משתחרר. לא ידוע ← השריון הופך לשימוש משוער. בשני המקרים — בלי ניסיון נוסף
        apr_ai_settle($runId, $e->kind === 'unknown' ? 'unknown' : 'failed', ['error_code' => $e->tech]);
        if ($e->kind !== 'unknown') apr_ai_conn_result((int)$plan['conn']['id'], false, $e->tech, $e->kind === 'auth');
    } catch (Throwable $e) {
        // כל חריגה אחרת מהמתאם: לא ידוע מה קרה אצל הספק. רק סוג החריגה נרשם — ההודעה עלולה לשאת תוכן
        apr_ai_settle($runId, 'unknown', ['error_code' => 'exception']);
        if (function_exists('devloop_log_write')) devloop_log_write('error', 'ai', 'ai.adapter', ['message' => get_class($e), 'data' => ['line' => $e->getLine()]]);
    }
    $final = apr_row('SELECT * FROM ai_runs WHERE id = ?', [$runId]);
    // טקסט חוזר רק מבקשה שנסגרה כ-done (למשל: שירות הרקע כבר סגר אותה כ-unknown בזמן שהספק ענה — אין טקסט)
    return apr_ai_run_out($final) + ['text' => $final['status'] === 'done' ? $text : null, 'replayed' => false, 'quota' => null];
}

// ── בדיקת חיבור ──────────────────────────────────────────────

/**
 * "בדיקת חיבור" (§7): קריאה מינימלית לספק, בלי מודל ובלי תוכן. עברה ← active. נדחתה באימות ← invalid (מיד).
 * כל תוצאה אחרת משאירה את המצב כפי שהיה. מחזיר ['result' => ok | invalid | unreachable | error].
 */
function apr_ai_conn_test(array $conn): array
{
    if ($conn['status'] === 'revoked') apr_fail('ai_conn_state');
    $provider = apr_row('SELECT * FROM ai_providers WHERE key = ?', [$conn['provider_key']]) ?? apr_fail('not_found');
    try {
        $key = apr_ai_key_open($conn) ?? throw new AprAiError('auth', 'key_unreadable');
        apr_ai_adapter($provider['adapter'])->health($key);
        apr_ai_conn_result((int)$conn['id'], true);
        return ['result' => 'ok'];
    } catch (AprAiError $e) {
        apr_ai_conn_result((int)$conn['id'], false, $e->tech, $e->kind === 'auth', true);
        return ['result' => $e->kind === 'auth' ? 'invalid' : ($e->kind === 'unreachable' ? 'unreachable' : 'error')];
    } catch (Throwable $e) {
        apr_ai_conn_result((int)$conn['id'], false, 'exception');
        return ['result' => 'error'];
    }
}

apr_health_register('ai', 'בינה: חיבורים וכספת', function () {
    if (!function_exists('sodium_crypto_secretbox')) return ['bad', 'sodium אינו זמין — אי אפשר להצפין מפתחות'];
    if (!function_exists('curl_init')) return ['bad', 'cURL אינו זמין — אי אפשר לקרוא לספק'];
    $n = array_column(apr_rows('SELECT status, COUNT(*) AS n FROM ai_connections GROUP BY status'), 'n', 'status');
    $open = (int)apr_val("SELECT COUNT(*) FROM ai_runs WHERE status IN ('reserved', 'running') AND created_at < ?", [apr_iso(apr_time() - APR_AI_STALE)]);
    $text = 'חיבורים פעילים: ' . (int)($n['active'] ?? 0) . ' · לא תקינים: ' . (int)($n['invalid'] ?? 0) . ' · שריונים שלא יושבו: ' . $open;
    return [($n['invalid'] ?? 0) || $open ? 'warn' : 'ok', $text];
});
