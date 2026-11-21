<?php
/**
 * בינה — הניהול והמסכים (אפיון 11 §10, §11). הליבה והשער: lib/ai.php.
 *
 * מי רשאי (lib/access.php): מנהל המערכת — הקטלוג, המחירון, השער, חיבורי המערכת, ההקצאות ומכסות המערכת (sys-ai-…);
 * מנהל משרד — המדיניות, החיבור האישי ומכסות הרשות של משרדו (office-ai-…); כל חבר — "הבינה שלי" (my-ai);
 * השמאי האחראי — "בלי בינה" בתיק (case-ai-set).
 *
 * **המפתח אינו יוצא מכאן:** apr_ai_conn_out מחזיר רק את 4 התווים האחרונים. אין פעולה שמציגה מפתח, ואין שדה ביומן שנושא אותו.
 * **מה כל אחד רואה בשימוש:** משתמש — את שלו; מנהל משרד — לפי משתמש ותיק, במשרדו; מנהל המערכת — מצרפי בלבד (משרד, משתמש, מודל,
 * יום): בלי מזהה תיק, בלי מטרה. בכל מקום שמוצג סכום — "משוער" או "לפי דיווח הספק"; לעולם לא "חיוב".
 */

declare(strict_types=1);

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/cases.php';

// ── פלט ───────────────────────────────────────────────────────

/** חיבור כפי שהוא יוצא ב-API: בלי key_enc — רק "מוגדר" ו-4 תווים אחרונים. */
function apr_ai_conn_out(array $c): array
{
    return ['pid' => $c['pid'], 'track' => $c['track'], 'provider' => $c['provider_key'], 'label' => $c['label'], 'status' => $c['status'],
        'key_hint' => $c['status'] === 'revoked' ? null : $c['key_hint'], 'default_model' => $c['default_model'],
        'last_ok_at' => $c['last_ok_at'], 'last_error' => $c['last_error'], 'created_at' => $c['created_at'], 'revoked_at' => $c['revoked_at']];
}

function apr_ai_model_out(array $m): array
{
    return ['provider' => $m['provider_key'], 'model_id' => $m['model_id'], 'label' => $m['label'], 'capabilities' => json_decode((string)$m['capabilities'], true) ?: (object)[],
        'price_in' => apr_ai_dec_out($m['price_in'] === null ? null : (int)$m['price_in'], 6), 'price_out' => apr_ai_dec_out($m['price_out'] === null ? null : (int)$m['price_out'], 6),
        'currency' => $m['currency'], 'price_checked_at' => $m['price_checked_at'], 'priced' => apr_ai_model_priced($m),
        'context_tokens' => (int)$m['context_tokens'], 'max_output_tokens' => (int)$m['max_output_tokens'], 'status' => $m['status']];
}

function apr_ai_provider_out(array $p): array
{
    return ['key' => $p['key'], 'name' => $p['name'], 'status' => $p['status'], 'privacy_note' => $p['privacy_note'], 'privacy_url' => $p['privacy_url'],
        'privacy_checked_at' => $p['privacy_checked_at'], 'rev' => (int)$p['privacy_rev']];
}

/** נוסח המדיניות שמנהל המשרד מאשר (§8): הגרסה, ההצהרות הקבועות (המסך מציג אותן לפי המפתח), ומה ידוע על כל ספק פעיל. */
function apr_ai_policy_text(): array
{
    return ['version' => APR_AI_POLICY_VERSION, 'statements' => ['what_sent', 'no_redaction', 'no_tools', 'not_stored_here', 'quota_estimate'],
        'providers' => array_map('apr_ai_provider_out', apr_rows("SELECT * FROM ai_providers WHERE status = 'active' ORDER BY key"))];
}

/** שורת שימוש מצרפית מתוך GROUP BY. טוקנים — לפי דיווח כשיש, אחרת האומדן; שני הסכומים בנפרד. */
const APR_AI_USAGE_SUMS = "COUNT(*) AS runs,
    SUM(CASE WHEN r.status = 'blocked' THEN 1 ELSE 0 END) AS blocked,
    SUM(CASE WHEN r.cost_basis = 'reported' THEN r.in_tokens + r.out_tokens WHEN r.cost_basis = 'estimated' THEN r.est_in_tokens + r.est_out_tokens ELSE 0 END) AS tokens,
    SUM(CASE WHEN r.cost_basis = 'reported' THEN COALESCE(r.reported_cost, 0) ELSE 0 END) AS cost_reported,
    SUM(CASE WHEN r.cost_basis = 'estimated' THEN COALESCE(r.est_cost, 0) ELSE 0 END) AS cost_estimated";

function apr_ai_usage_row(array $r): array
{
    return ['runs' => (int)$r['runs'], 'blocked' => (int)$r['blocked'], 'tokens' => (int)$r['tokens'], 'cost_reported' => (int)$r['cost_reported'], 'cost_estimated' => (int)$r['cost_estimated']];
}

/** תחילת החודש הנוכחי (שעון ישראל), ב-UTC. */
function apr_ai_month_start(): string
{
    return apr_iso(apr_ai_local()->modify('first day of this month')->setTime(0, 0)->getTimestamp());
}

// ── קלט משותף ────────────────────────────────────────────────

function apr_ai_date(mixed $v): ?string
{
    if ($v === null || $v === '') return null;
    return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}\z/', $v) === 1 && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : apr_fail('ai_number');
}

function apr_ai_provider_row(mixed $key): array
{
    return (is_string($key) ? apr_row('SELECT * FROM ai_providers WHERE key = ?', [$key]) : null) ?? apr_fail('not_found');
}

/** החיבור לפי pid — של המשרד ($office), או של המערכת (null). חיבור של משרד אחר או של המסלול האחר: לא נמצא. */
function apr_ai_conn_row(?int $office, mixed $pid): array
{
    $c = !apr_is_pid($pid) ? null : ($office === null ? apr_row("SELECT * FROM ai_connections WHERE pid = ? AND track = 'managed'", [$pid])
        : apr_row("SELECT * FROM ai_connections WHERE pid = ? AND track = 'personal' AND office_id = ?", [$pid, $office]));
    return $c ?? apr_fail('not_found');
}

// ── חיבורים (כספת) ───────────────────────────────────────────

/**
 * יצירה, עדכון או החלפת מפתח. $office — החיבור האישי של המשרד; null — חיבור של המערכת. {connection?, provider, label, key?, default_model?}.
 * פעולה רגישה (lib/confirm.php). ביומן: מה השתנה — לעולם לא המפתח ולא חלק ממנו.
 */
function apr_ai_conn_save(?int $office, int $uid, #[\SensitiveParameter] array $in): array
{
    $label = apr_clean_name($in['label'] ?? null, 80);
    $key = ($in['key'] ?? null) === null || $in['key'] === '' ? null : apr_ai_key_clean($in['key']);
    return apr_tx(function () use ($office, $uid, $in, $label, $key) {
        $old = ($in['connection'] ?? null) !== null ? apr_ai_conn_row($office, $in['connection']) : null;
        if ($old && $old['status'] === 'revoked') apr_fail('ai_conn_state');
        $provider = apr_ai_provider_row($old['provider_key'] ?? ($in['provider'] ?? null));
        if (!$old && $provider['status'] !== 'active') apr_fail('not_found');
        $default = ($in['default_model'] ?? null) === null || $in['default_model'] === '' ? null
            : (is_string($in['default_model']) && apr_ai_model_row($provider['key'], $in['default_model']) ? $in['default_model'] : apr_fail('ai_model_unknown'));
        if ($old) {
            apr_exec('UPDATE ai_connections SET label = ?, default_model = ? WHERE id = ?', [$label, $default, $old['id']]);
            if ($key !== null) {
                apr_exec("UPDATE ai_connections SET key_enc = ?, key_hint = ?, status = 'active', fail_count = 0, last_error = NULL WHERE id = ?",
                    [apr_ai_key_box($old['pid'], $key), substr($key, -4), $old['id']]);
            }
            $pid = $old['pid'];
        } else {
            if ($key === null) apr_fail('ai_key_invalid');
            if ($office !== null && apr_ai_personal_conn($office)) apr_fail('ai_conn_exists');
            $pid = apr_pid();
            $st = apr_db()->prepare('INSERT INTO ai_connections (pid, track, office_id, provider_key, label, key_enc, key_hint, default_model, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ([$pid, $office === null ? 'managed' : 'personal', $office, $provider['key'], $label, null, substr($key, -4), $default, $uid, apr_now()] as $i => $v) {
                $st->bindValue($i + 1, $v, $v === null ? PDO::PARAM_NULL : (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR));
            }
            $st->bindValue(6, apr_ai_key_box($pid, $key), PDO::PARAM_LOB);
            $st->execute();
        }
        apr_audit($old ? ($key !== null ? 'ai.connection.key' : 'ai.connection.update') : 'ai.connection.create', 'ai_connection', $pid,
            ['track' => $office === null ? 'managed' : 'personal', 'provider' => $provider['key']], null, $office);
        return ['connection' => apr_ai_conn_out(apr_row('SELECT * FROM ai_connections WHERE pid = ?', [$pid]))];
    });
}

function apr_ai_conn_test_action(?int $office, array $in): array
{
    $c = apr_ai_conn_row($office, $in['connection'] ?? null);
    // עד 10 בדיקות לחיבור בשעה — הבדיקה יוצאת לספק
    if (!apr_limit('ai-test:' . $c['id'], 10, 3600)) apr_fail('too_soon');
    $r = apr_ai_conn_test($c);
    return $r + ['connection' => apr_ai_conn_out(apr_row('SELECT * FROM ai_connections WHERE id = ?', [$c['id']]))];
}

/** ביטול: המצב revoked, **והמפתח נמחק מהמסד** (גם 4 התווים). אין דרך חזרה — מחברים חיבור חדש. */
function apr_ai_conn_revoke(?int $office, array $in): array
{
    return apr_tx(function () use ($office, $in) {
        $c = apr_ai_conn_row($office, $in['connection'] ?? null);
        if ($c['status'] !== 'revoked') {
            apr_exec("UPDATE ai_connections SET status = 'revoked', key_enc = NULL, key_hint = NULL, revoked_at = ? WHERE id = ?", [apr_now(), $c['id']]);
            apr_audit('ai.connection.revoke', 'ai_connection', $c['pid'], ['track' => $c['track']], null, $office);
        }
        return ['connection' => apr_ai_conn_out(apr_row('SELECT * FROM ai_connections WHERE id = ?', [$c['id']]))];
    });
}

// ── מכסות ─────────────────────────────────────────────────────

/** היעד של מכסה, כפי שהוא מוצג: pid ושם (לתיק — המספר). */
function apr_ai_quota_target(array $q): array
{
    $r = match ($q['scope']) {
        'user' => apr_row('SELECT pid, name FROM users WHERE id = ?', [$q['scope_id']]),
        'office' => apr_row('SELECT pid, name FROM offices WHERE id = ?', [$q['scope_id']]),
        default => apr_row('SELECT pid, number AS name FROM cases WHERE id = ?', [$q['scope_id']]),
    };
    $o = $q['office_id'] === null ? null : apr_row('SELECT pid, name FROM offices WHERE id = ?', [$q['office_id']]);
    return ['target' => $r['pid'] ?? null, 'target_name' => $r['name'] ?? '', 'office' => $o['pid'] ?? null, 'office_name' => $o['name'] ?? null];
}

/**
 * קביעת מכסה. $office = null — מנהל המערכת (owner = system; המסלול מהבקשה; יעד: משרד או משתמש).
 * $office = id — מנהל המשרד (owner = office; מסלול אישי בלבד; יעד: המשרד, חבר בו, או תיק שלו).
 * {quota?} לעדכון תקרה קיימת, או {track?, scope, target, office?, period} לחדשה; ותמיד {limit_money?, limit_tokens?, hard}.
 * מכסה בכסף דורשת שער המרה (מקרה קצה 10). limit_money — בש"ח, עד שתי ספרות אחרי הנקודה.
 */
function apr_ai_quota_save(?int $office, int $uid, array $in): array
{
    $money = apr_ai_dec($in['limit_money'] ?? null, 2, 9);
    $tokens = ($in['limit_tokens'] ?? null) === null || $in['limit_tokens'] === '' ? null
        : (is_int($in['limit_tokens']) && $in['limit_tokens'] >= 0 && $in['limit_tokens'] <= 1_000_000_000_000 ? $in['limit_tokens'] : apr_fail('ai_number'));
    if ($money === null && $tokens === null) apr_fail('ai_quota_empty');
    $hard = ($in['hard'] ?? true) === false ? 0 : 1;
    $owner = $office === null ? 'system' : 'office';
    return apr_tx(function () use ($office, $uid, $in, $money, $tokens, $hard, $owner) {
        if ($money !== null && apr_ai_fx() === null) apr_fail('ai_no_fx');
        if (($in['quota'] ?? null) !== null) {
            $q = apr_ai_quota_row($office, $in['quota']);
        } else {
            $track = $office !== null ? 'personal' : (in_array($in['track'] ?? null, APR_AI_TRACKS, true) ? $in['track'] : apr_fail('ai_request'));
            $scope = in_array($in['scope'] ?? null, $office === null ? ['user', 'office'] : ['user', 'office', 'case'], true) ? $in['scope'] : apr_fail('ai_request');
            $period = in_array($in['period'] ?? null, ['month', 'day', 'total'], true) ? $in['period'] : apr_fail('ai_request');
            $target = $in['target'] ?? null;
            if ($office !== null) {
                // מנהל משרד: רק בתוך משרדו
                $qOffice = $office;
                $scopeId = match ($scope) {
                    'office' => $office,
                    'user' => (int)apr_case_office_member($office, $target)['id'],
                    'case' => (int)(apr_is_pid($target) ? apr_val('SELECT id FROM cases WHERE pid = ? AND office_id = ? AND deleted_at IS NULL', [$target, $office]) : 0) ?: apr_fail('not_found'),
                };
            } else {
                $o = ($in['office'] ?? null) === null || $in['office'] === '' ? null : (int)apr_sys_office_row($in['office'])['id'];
                if ($scope === 'office') {
                    $scopeId = $qOffice = (int)apr_sys_office_row($target)['id'];
                } else {
                    $scopeId = (int)apr_sys_user_row($target)['id'];
                    // במסלול האישי מכסת משתמש שייכת למשרד מסוים; במנוהל — אפשר לכל המשרדים
                    if ($track === 'personal' && $o === null) apr_fail('ai_request');
                    $qOffice = $o;
                }
            }
            $q = apr_row('SELECT * FROM ai_quotas WHERE deleted_at IS NULL AND track = ? AND owner = ? AND scope = ? AND scope_id = ? AND period = ? AND COALESCE(office_id, 0) = CAST(? AS INTEGER)',   // CAST: לביטוי אין זיקת טיפוס, ו-PDO קושר מחרוזת
                [$track, $owner, $scope, $scopeId, $period, $qOffice ?? 0]);
            if (!$q) {
                $pid = apr_pid();
                apr_insert('ai_quotas', ['pid' => $pid, 'track' => $track, 'owner' => $owner, 'office_id' => $qOffice, 'scope' => $scope, 'scope_id' => $scopeId, 'period' => $period,
                    'limit_money' => $money, 'limit_tokens' => $tokens, 'hard' => $hard, 'set_by' => $uid, 'created_at' => apr_now(), 'updated_at' => apr_now()]);
                $q = apr_row('SELECT * FROM ai_quotas WHERE pid = ?', [$pid]);
                apr_audit('ai.quota.create', 'ai_quota', $pid, ['track' => $track, 'scope' => $scope, 'period' => $period, 'money' => $money, 'tokens' => $tokens, 'hard' => (bool)$hard], null, $q['office_id'] === null ? null : (int)$q['office_id']);
                return ['quota' => apr_ai_quota_out($q) + apr_ai_quota_target($q)];
            }
        }
        apr_exec('UPDATE ai_quotas SET limit_money = ?, limit_tokens = ?, hard = ?, set_by = ?, updated_at = ? WHERE id = ?', [$money, $tokens, $hard, $uid, apr_now(), $q['id']]);
        apr_audit('ai.quota.update', 'ai_quota', $q['pid'], ['money' => $money, 'tokens' => $tokens, 'hard' => (bool)$hard,
            'was' => ['money' => $q['limit_money'], 'tokens' => $q['limit_tokens'], 'hard' => (bool)$q['hard']]], null, $q['office_id'] === null ? null : (int)$q['office_id']);
        $q = apr_row('SELECT * FROM ai_quotas WHERE id = ?', [$q['id']]);
        return ['quota' => apr_ai_quota_out($q) + apr_ai_quota_target($q)];
    });
}

/** מכסה לפי pid, רק של מי שקבע אותה: מנהל המערכת — את שלו; מנהל משרד — את של משרדו. כל השאר: לא נמצא. */
function apr_ai_quota_row(?int $office, mixed $pid): array
{
    $q = !apr_is_pid($pid) ? null : ($office === null ? apr_row("SELECT * FROM ai_quotas WHERE pid = ? AND owner = 'system' AND deleted_at IS NULL", [$pid])
        : apr_row("SELECT * FROM ai_quotas WHERE pid = ? AND owner = 'office' AND office_id = ? AND deleted_at IS NULL", [$pid, $office]));
    return $q ?? apr_fail('not_found');
}

function apr_ai_quota_delete(?int $office, array $in): array
{
    return apr_tx(function () use ($office, $in) {
        $q = apr_ai_quota_row($office, $in['quota'] ?? null);
        apr_exec('UPDATE ai_quotas SET deleted_at = ? WHERE id = ?', [apr_now(), $q['id']]);
        apr_audit('ai.quota.delete', 'ai_quota', $q['pid'], ['track' => $q['track'], 'scope' => $q['scope'], 'period' => $q['period']], null, $q['office_id'] === null ? null : (int)$q['office_id']);
        return [];
    });
}

/** "בקשת הגדלה" (§6.4): נרשמת ביומן, ומוצגת למי שרשאי להגדיל — מנהל המערכת (מכסת מערכת) או מנהל המשרד. פעם ביום למכסה. */
function apr_ai_quota_request(array $ctx, array $in): array
{
    $office = apr_office_id($ctx);
    $q = (apr_is_pid($in['quota'] ?? null) ? apr_row('SELECT * FROM ai_quotas WHERE pid = ? AND deleted_at IS NULL', [$in['quota']]) : null) ?? apr_fail('not_found');
    $mine = match ($q['scope']) {
        'user' => (int)$q['scope_id'] === (int)$ctx['user']['id'] && ($q['office_id'] === null || (int)$q['office_id'] === $office),
        'office' => (int)$q['scope_id'] === $office,
        default => (bool)apr_val('SELECT 1 FROM case_members m JOIN cases c ON c.id = m.case_id WHERE m.case_id = ? AND m.user_id = ? AND m.revoked_at IS NULL AND c.office_id = ?', [$q['scope_id'], $ctx['user']['id'], $office]),
    };
    if (!$mine) apr_fail('not_found');
    if (!apr_limit('ai-quota-req:' . $ctx['user']['id'] . ':' . $q['id'], 1, 86400)) apr_fail('too_soon');
    apr_audit('ai.quota.request', 'ai_quota', $q['pid'], ['owner' => $q['owner'], 'track' => $q['track'], 'scope' => $q['scope']], null, $office);
    return ['to' => $q['owner'] === 'system' ? 'sysadmin' : 'office_admin'];
}

/** בקשות ההגדלה האחרונות שממתינות למי שרשאי: $office = null — של מכסות המערכת; אחרת — של מכסות המשרד. */
function apr_ai_quota_requests(?int $office): array
{
    $rows = apr_rows("SELECT a.at, a.entity_pid, u.pid AS user_pid, u.name AS user_name, o.pid AS office_pid, o.name AS office_name FROM audit_log a
        LEFT JOIN users u ON u.id = a.actor_user_id LEFT JOIN offices o ON o.id = a.office_id
        WHERE a.action = 'ai.quota.request' AND json_extract(a.meta, '$.owner') = ? AND a.at >= ?" . ($office === null ? '' : ' AND a.office_id = ' . $office) . ' ORDER BY a.id DESC LIMIT 30',
        [$office === null ? 'system' : 'office', apr_iso(apr_time() - 30 * 86400)]);
    return array_map(fn($r) => ['at' => $r['at'], 'quota' => $r['entity_pid'], 'user' => $r['user_pid'], 'user_name' => $r['user_name'], 'office' => $r['office_pid'], 'office_name' => $r['office_name']], $rows);
}

// ── מנהל המערכת ──────────────────────────────────────────────

function apr_sys_ai(): array
{
    $since = apr_iso(apr_time() - 30 * 86400);
    $fx = apr_row("SELECT * FROM fx_rates WHERE currency = 'USD'");
    $conns = apr_rows("SELECT * FROM ai_connections WHERE track = 'managed' ORDER BY (status = 'revoked'), id");
    $connPid = array_column($conns, 'pid', 'id');
    $assign = array_map(function ($a) use ($connPid) {
        $t = $a['scope'] === 'user' ? apr_row('SELECT pid, name FROM users WHERE id = ?', [$a['scope_id']]) : apr_row('SELECT pid, name FROM offices WHERE id = ?', [$a['scope_id']]);
        return ['connection' => $connPid[$a['connection_id']] ?? null, 'scope' => $a['scope'], 'target' => $t['pid'] ?? null, 'target_name' => $t['name'] ?? '',
            'models' => json_decode((string)$a['models'], true) ?: [], 'default_model' => $a['default_model']];
    }, apr_rows('SELECT * FROM ai_assignments ORDER BY scope, id'));
    // שימוש מצרפי בלבד (11-E1): משרד, משתמש, מודל, מסלול ויום. **בלי תיק ובלי מטרה** — העמודות האלה אינן נשלפות כלל
    $usage = array_map(fn($r) => ['day' => $r['day'], 'office' => $r['office_pid'], 'office_name' => $r['office_name'], 'user' => $r['user_pid'], 'user_name' => $r['user_name'],
            'model' => $r['model_id'], 'track' => $r['track']] + apr_ai_usage_row($r),
        apr_rows('SELECT substr(r.created_at, 1, 10) AS day, o.pid AS office_pid, o.name AS office_name, u.pid AS user_pid, u.name AS user_name, r.model_id, r.track, ' . APR_AI_USAGE_SUMS . '
            FROM ai_runs r JOIN offices o ON o.id = r.office_id LEFT JOIN users u ON u.id = r.user_id WHERE r.created_at >= ?
            GROUP BY day, r.office_id, r.user_id, r.model_id, r.track ORDER BY day DESC, runs DESC LIMIT 500', [$since]));
    $blocks = array_map(fn($r) => ['office' => $r['pid'], 'office_name' => $r['name'], 'reason' => $r['block_reason'], 'count' => (int)$r['n'], 'last_at' => $r['last_at']],
        apr_rows("SELECT o.pid, o.name, r.block_reason, COUNT(*) AS n, MAX(r.created_at) AS last_at FROM ai_runs r JOIN offices o ON o.id = r.office_id
            WHERE r.status = 'blocked' AND r.created_at >= ? GROUP BY r.office_id, r.block_reason ORDER BY last_at DESC LIMIT 100", [$since]));
    $quotas = array_map(fn($q) => apr_ai_quota_out($q) + apr_ai_quota_target($q), apr_rows("SELECT * FROM ai_quotas WHERE owner = 'system' AND deleted_at IS NULL ORDER BY track, scope, id"));
    return [
        'providers' => array_map('apr_ai_provider_out', apr_rows('SELECT * FROM ai_providers ORDER BY key')),
        'models' => array_map('apr_ai_model_out', apr_rows('SELECT * FROM ai_models ORDER BY provider_key, id')),
        'fx' => $fx ? ['currency' => 'USD', 'rate' => apr_ai_dec_out((int)$fx['rate_to_ils'], 6), 'set_at' => $fx['set_at']] : null,
        'connections' => array_map('apr_ai_conn_out', $conns),
        'assignments' => $assign,
        'quotas' => $quotas,
        'usage' => $usage,
        'blocks' => $blocks,
        'alerts' => ['invalid_connections' => (int)apr_val("SELECT COUNT(*) FROM ai_connections WHERE status = 'invalid'"),
            'over' => array_values(array_filter($quotas, fn($q) => !empty($q['money']['over']) || !empty($q['tokens']['over']))),
            'requests' => apr_ai_quota_requests(null)],
        'policy_version' => APR_AI_POLICY_VERSION,
    ];
}

/** עדכון ספק בקטלוג: מצב ותיאור הפרטיות. material = שינוי מהותי — כל משרד יידרש לאשר מחדש (§8). ספק חדש דורש מתאם בקוד. */
function apr_sys_ai_provider_save(array $in): array
{
    return apr_tx(function () use ($in) {
        $p = apr_ai_provider_row($in['provider'] ?? null);
        $status = in_array($in['status'] ?? $p['status'], ['active', 'hidden'], true) ? ($in['status'] ?? $p['status']) : apr_fail('status_invalid');
        $note = apr_clean_text($in['privacy_note'] ?? null, 2000);
        $url = apr_src_url($in['privacy_url'] ?? null);
        $checked = apr_ai_date($in['privacy_checked_at'] ?? null);
        $material = ($in['material'] ?? false) === true;
        apr_exec('UPDATE ai_providers SET status = ?, privacy_note = ?, privacy_url = ?, privacy_checked_at = ?, privacy_rev = privacy_rev + ? WHERE key = ?',
            [$status, $note, $url, $checked, $material ? 1 : 0, $p['key']]);
        apr_audit('ai.provider.update', 'ai_provider', $p['key'], ['status' => $status, 'material' => $material]);
        return ['provider' => apr_ai_provider_out(apr_ai_provider_row($p['key']))];
    });
}

/** הוספה או עדכון של מודל בקטלוג (לפי ספק + מזהה). המחיר — בדולר למיליון טוקנים; שני המחירים יחד, ועם תאריך בדיקה. */
function apr_sys_ai_model_save(array $in): array
{
    $modelId = is_string($in['model_id'] ?? null) && preg_match('/^[A-Za-z0-9_.:@-]{1,100}\z/', $in['model_id']) === 1 ? $in['model_id'] : apr_fail('ai_model_unknown');
    $label = apr_clean_name($in['label'] ?? null, 80);
    $pin = apr_ai_dec($in['price_in'] ?? null, 6, 3);
    $pout = apr_ai_dec($in['price_out'] ?? null, 6, 3);
    $checked = apr_ai_date($in['price_checked_at'] ?? null);
    if (($pin === null) !== ($pout === null) || ($pin !== null && $checked === null)) apr_fail('ai_price');
    $int = fn(string $k, int $max) => is_int($in[$k] ?? null) && $in[$k] >= 1 && $in[$k] <= $max ? $in[$k] : apr_fail('ai_number');
    $ctxTokens = $int('context_tokens', 10_000_000);
    $maxOut = $int('max_output_tokens', 200_000);
    $status = in_array($in['status'] ?? 'active', ['active', 'hidden'], true) ? ($in['status'] ?? 'active') : apr_fail('status_invalid');
    $caps = ['text' => true];
    foreach (['vision', 'pdf', 'json_output'] as $c) $caps[$c] = (is_array($in['capabilities'] ?? null) ? ($in['capabilities'][$c] ?? false) : false) === true;
    return apr_tx(function () use ($in, $modelId, $label, $pin, $pout, $checked, $ctxTokens, $maxOut, $status, $caps) {
        $p = apr_ai_provider_row($in['provider'] ?? null);
        $old = apr_ai_model_row($p['key'], $modelId);
        $row = ['label' => $label, 'capabilities' => json_encode($caps), 'price_in' => $pin, 'price_out' => $pout, 'price_checked_at' => $pin === null ? null : $checked,
            'context_tokens' => $ctxTokens, 'max_output_tokens' => $maxOut, 'status' => $status];
        if ($old) {
            apr_exec('UPDATE ai_models SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($row))) . ' WHERE id = ?', [...array_values($row), $old['id']]);
        } else {
            apr_insert('ai_models', $row + ['provider_key' => $p['key'], 'model_id' => $modelId]);
        }
        apr_audit($old ? 'ai.model.update' : 'ai.model.create', 'ai_model', mb_substr($p['key'] . '/' . $modelId, 0, 120), ['status' => $status, 'priced' => $pin !== null]);
        return ['model' => apr_ai_model_out(apr_ai_model_row($p['key'], $modelId))];
    });
}

/** שער ההמרה — ידני. {rate}: ש"ח לדולר. */
function apr_sys_ai_fx_set(array $ctx, array $in): array
{
    $rate = apr_ai_dec($in['rate'] ?? null, 6, 3);
    if ($rate === null || $rate < 1) apr_fail('ai_number');
    return apr_tx(function () use ($ctx, $rate) {
        apr_exec("INSERT INTO fx_rates (currency, rate_to_ils, set_by, set_at) VALUES ('USD', ?, ?, ?)
            ON CONFLICT(currency) DO UPDATE SET rate_to_ils = excluded.rate_to_ils, set_by = excluded.set_by, set_at = excluded.set_at", [$rate, $ctx['user']['id'], apr_now()]);
        apr_audit('ai.fx.set', 'fx', 'USD', ['rate' => apr_ai_dec_out($rate, 6)]);
        return ['fx' => ['currency' => 'USD', 'rate' => apr_ai_dec_out($rate, 6), 'set_at' => apr_now()]];
    });
}

/** הקצאה במסלול המנוהל: {connection, scope: office | user, target, models[], default_model?}. אחת לכל משרד ולכל משתמש — שמירה חוזרת מעדכנת. */
function apr_sys_ai_assign_save(array $ctx, array $in): array
{
    return apr_tx(function () use ($ctx, $in) {
        $c = apr_ai_conn_row(null, $in['connection'] ?? null);
        if ($c['status'] === 'revoked') apr_fail('ai_conn_state');
        $scope = in_array($in['scope'] ?? null, ['office', 'user'], true) ? $in['scope'] : apr_fail('ai_request');
        $t = $scope === 'office' ? apr_sys_office_row($in['target'] ?? null) : apr_sys_user_row($in['target'] ?? null);
        $models = [];
        foreach (is_array($in['models'] ?? null) ? $in['models'] : [] as $m) {
            if (!is_string($m) || !apr_ai_model_row($c['provider_key'], $m)) apr_fail('ai_model_unknown');
            $models[] = $m;
        }
        $models = array_values(array_unique($models));
        $default = ($in['default_model'] ?? null) === null || $in['default_model'] === '' ? null : $in['default_model'];
        if ($default !== null && (!is_string($default) || !apr_ai_model_row($c['provider_key'], $default) || ($models && !in_array($default, $models, true)))) apr_fail('ai_model_unknown');
        apr_exec('INSERT INTO ai_assignments (connection_id, scope, scope_id, models, default_model, set_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(scope, scope_id) DO UPDATE SET connection_id = excluded.connection_id, models = excluded.models, default_model = excluded.default_model, set_by = excluded.set_by',
            [$c['id'], $scope, $t['id'], json_encode($models), $default, $ctx['user']['id'], apr_now()]);
        apr_audit('ai.assign.save', $scope, $t['pid'], ['connection' => $c['pid'], 'models' => count($models)], null, $scope === 'office' ? (int)$t['id'] : null);
        return [];
    });
}

function apr_sys_ai_assign_delete(array $in): array
{
    return apr_tx(function () use ($in) {
        $scope = in_array($in['scope'] ?? null, ['office', 'user'], true) ? $in['scope'] : apr_fail('ai_request');
        $t = $scope === 'office' ? apr_sys_office_row($in['target'] ?? null) : apr_sys_user_row($in['target'] ?? null);
        if (apr_exec('DELETE FROM ai_assignments WHERE scope = ? AND scope_id = ?', [$scope, $t['id']]) === 0) apr_fail('not_found');
        apr_audit('ai.assign.delete', $scope, $t['pid'], [], null, $scope === 'office' ? (int)$t['id'] : null);
        return [];
    });
}

// ── מנהל המשרד ───────────────────────────────────────────────

/** מה יקרה לבקשה של המשתמש הזה עכשיו: המסלול, הספק והמודל — או למה אין (בלי לרשום דבר). */
function apr_ai_preview(int $office, int $user): array
{
    $policy = apr_ai_policy($office);
    if (!apr_ai_policy_on($policy)) return ['available' => false, 'reason' => 'policy', 'track' => null, 'provider' => null, 'model' => null, 'models' => []];
    $route = apr_ai_route($office, $user, $policy);
    $provider = isset($route['conn']) && $route['conn'] ? apr_row('SELECT * FROM ai_providers WHERE key = ?', [$route['conn']['provider_key']]) : null;
    $base = ['track' => $route['track'] ?? null, 'provider' => $provider['name'] ?? null];
    if (isset($route['block'])) return ['available' => false, 'reason' => $route['block'], 'model' => null, 'models' => []] + $base;
    if (!$provider || $provider['status'] !== 'active' || !apr_ai_policy_covers($policy, $provider['key'])) return ['available' => false, 'reason' => 'policy', 'model' => null, 'models' => []] + $base;
    $list = apr_ai_models_for($route);
    $model = apr_ai_model_pick($route, null);
    $out = fn($m) => ['model_id' => $m['model_id'], 'label' => $m['label']];
    if (!$model) return ['available' => false, 'reason' => 'model_not_allowed', 'model' => null, 'models' => []] + $base;
    // במנוהל בלי מכסה קשיחה אין שימוש
    if ($route['track'] === 'managed' && !array_filter(apr_ai_quotas_for('managed', $office, $user, null), fn($q) => (int)$q['hard'] === 1)) {
        return ['available' => false, 'reason' => 'quota', 'model' => $out($model), 'models' => array_map($out, $list)] + $base;
    }
    return ['available' => true, 'reason' => null, 'model' => $out($model), 'models' => array_map($out, $list)] + $base;
}

function apr_office_ai(array $ctx): array
{
    $office = apr_office_id($ctx);
    $p = apr_ai_policy($office);
    $text = apr_ai_policy_text();
    $conn = apr_row("SELECT * FROM ai_connections WHERE track = 'personal' AND office_id = ? ORDER BY (status = 'revoked'), id DESC LIMIT 1", [$office]);
    $approvedBy = $p['approved_by'] === null ? null : apr_val('SELECT name FROM users WHERE id = ?', [$p['approved_by']]);
    $since = apr_ai_month_start();
    $covered = true;
    foreach ($text['providers'] as $pr) $covered = $covered && apr_ai_policy_covers($p, $pr['key']);
    return [
        'policy' => ['enabled' => (bool)$p['enabled'], 'on' => apr_ai_policy_on($p), 'approved_at' => $p['approved_at'], 'approved_by' => $approvedBy === false ? null : $approvedBy,
            'approved_version' => $p['approved_text_version'] === null ? null : (int)$p['approved_text_version'],
            // נוסח או ספק שהשתנו מאז האישור — נדרש אישור מחדש
            'needs_reapproval' => (bool)$p['enabled'] && (!apr_ai_policy_on($p) || !$covered),
            'prefer_managed' => (bool)$p['prefer_managed']],
        'policy_text' => $text,
        'connection' => $conn ? apr_ai_conn_out($conn) : null,
        'models' => array_map(fn($m) => ['provider' => $m['provider_key'], 'model_id' => $m['model_id'], 'label' => $m['label']], apr_rows("SELECT * FROM ai_models WHERE status = 'active' ORDER BY provider_key, id")),
        'managed_assigned' => (bool)apr_val("SELECT 1 FROM ai_assignments WHERE scope = 'office' AND scope_id = ?", [$office]),
        'preview' => apr_ai_preview($office, (int)$ctx['user']['id']),
        'has_fx' => apr_ai_fx() !== null,
        // מכסות המסלול האישי במשרד: שלי (owner = office, ניתנות לעריכה) ושל מנהל המערכת (לצפייה)
        'quotas' => array_map(fn($q) => apr_ai_quota_out($q) + apr_ai_quota_target($q) + ['editable' => $q['owner'] === 'office'],
            apr_rows("SELECT * FROM ai_quotas WHERE deleted_at IS NULL AND office_id = ? ORDER BY track, scope, id", [$office])),
        'members' => array_map(fn($m) => ['pid' => $m['pid'], 'name' => $m['name']],
            apr_rows("SELECT u.pid, u.name FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.office_id = ? AND m.deleted_at IS NULL AND m.status = 'active' ORDER BY u.name", [$office])),
        'usage' => [
            'since' => $since,
            'by_user' => array_map(fn($r) => ['user' => $r['pid'], 'user_name' => $r['name'] ?? ''] + apr_ai_usage_row($r),
                apr_rows('SELECT u.pid, u.name, ' . APR_AI_USAGE_SUMS . ' FROM ai_runs r LEFT JOIN users u ON u.id = r.user_id WHERE r.office_id = ? AND r.created_at >= ? GROUP BY r.user_id ORDER BY runs DESC', [$office, $since])),
            'by_case' => array_map(fn($r) => ['case' => $r['pid'], 'number' => $r['number']] + apr_ai_usage_row($r),
                apr_rows('SELECT c.pid, c.number, ' . APR_AI_USAGE_SUMS . ' FROM ai_runs r JOIN cases c ON c.id = r.case_id WHERE r.office_id = ? AND r.created_at >= ? GROUP BY r.case_id ORDER BY runs DESC LIMIT 100', [$office, $since])),
        ],
        'blocks' => array_map(fn($r) => ['at' => $r['created_at'], 'reason' => $r['block_reason'], 'user_name' => $r['name'] ?? '', 'model' => $r['model_id']],
            apr_rows("SELECT r.created_at, r.block_reason, r.model_id, u.name FROM ai_runs r LEFT JOIN users u ON u.id = r.user_id WHERE r.office_id = ? AND r.status = 'blocked' ORDER BY r.id DESC LIMIT 30", [$office])),
        'requests' => apr_ai_quota_requests($office),
    ];
}

/**
 * המדיניות של המשרד. {enabled?, text_version, providers{ספק: גרסה}, prefer_managed?}.
 * הדלקה (enabled = true) היא פעולה רגישה, ומאשרת את הנוסח **שהוצג**: הגרסה והספקים שבבקשה חייבים להיות הנוכחיים —
 * אחרת 409, והמסך טוען מחדש. האישור נרשם עם גרסת הנוסח.
 */
function apr_office_ai_policy(array $ctx, array $in): array
{
    $office = apr_office_id($ctx);
    return apr_tx(function () use ($ctx, $in, $office) {
        $p = apr_ai_policy($office);
        $set = ['enabled' => (int)$p['enabled'], 'approved_by' => $p['approved_by'], 'approved_at' => $p['approved_at'], 'approved_text_version' => $p['approved_text_version'],
            'approved_providers' => $p['approved_providers'], 'allowed_tracks' => $p['allowed_tracks'], 'prefer_managed' => (int)$p['prefer_managed'], 'updated_at' => apr_now()];
        if (array_key_exists('prefer_managed', $in)) $set['prefer_managed'] = $in['prefer_managed'] === true ? 1 : 0;
        if (($in['enabled'] ?? null) === true) {
            $now = [];
            foreach (apr_ai_policy_text()['providers'] as $pr) $now[$pr['key']] = $pr['rev'];
            $seen = is_array($in['providers'] ?? null) ? $in['providers'] : [];
            ksort($now);
            ksort($seen);
            if (($in['text_version'] ?? null) !== APR_AI_POLICY_VERSION || $seen !== $now) apr_fail('ai_policy_changed');
            $set = ['enabled' => 1, 'approved_by' => (int)$ctx['user']['id'], 'approved_at' => apr_now(), 'approved_text_version' => APR_AI_POLICY_VERSION,
                'approved_providers' => json_encode((object)$now)] + $set;
            apr_audit('ai.policy.approve', 'office', $ctx['office']['pid'], ['text_version' => APR_AI_POLICY_VERSION, 'providers' => $now], null, $office);
        } elseif (($in['enabled'] ?? null) === false && (int)$p['enabled'] === 1) {
            $set['enabled'] = 0;
            apr_audit('ai.policy.disable', 'office', $ctx['office']['pid'], [], null, $office);
        }
        if ($set['prefer_managed'] !== (int)$p['prefer_managed']) apr_audit('ai.policy.track', 'office', $ctx['office']['pid'], ['prefer_managed' => (bool)$set['prefer_managed']], null, $office);
        $cols = array_keys($set);
        apr_exec('INSERT INTO ai_policies (office_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')
            ON CONFLICT(office_id) DO UPDATE SET ' . implode(', ', array_map(fn($c) => "$c = excluded.$c", $cols)), [$office, ...array_values($set)]);
        return [];
    });
}

// ── כל משתמש ─────────────────────────────────────────────────

function apr_my_ai(array $ctx): array
{
    $office = apr_office_id($ctx);
    $uid = (int)$ctx['user']['id'];
    $pre = apr_ai_preview($office, $uid);
    $since = apr_ai_month_start();
    // המכסות שחלות עליי במסלול שלי: שלי ושל המשרד (מכסת תיק מוצגת בכרטיס התיק)
    $quotas = $pre['track'] === null ? [] : array_map('apr_ai_quota_out', apr_ai_quotas_for($pre['track'], $office, $uid, null));
    $sum = apr_row('SELECT ' . APR_AI_USAGE_SUMS . ' FROM ai_runs r WHERE r.office_id = ? AND r.user_id = ? AND r.created_at >= ?', [$office, $uid, $since]);
    return ['ai' => $pre, 'quotas' => $quotas,
        'usage' => ['since' => $since] + apr_ai_usage_row($sum + ['runs' => 0]),
        'blocks' => array_map(fn($r) => ['at' => $r['created_at'], 'reason' => $r['block_reason'], 'purpose' => $r['purpose'], 'model' => $r['model_id']],
            apr_rows("SELECT created_at, block_reason, purpose, model_id FROM ai_runs WHERE office_id = ? AND user_id = ? AND status = 'blocked' ORDER BY id DESC LIMIT 10", [$office, $uid]))];
}

// ── התיק ──────────────────────────────────────────────────────

/** "בלי בינה" בתיק, ומי רשאי לשנות. השימוש של הבינה בתיק ומכסת התיק — לאחראי בלבד (§10). */
function apr_case_ai(array $ctx, array $q): array
{
    $c = apr_case_get($ctx, $q['case'] ?? null);
    $mine = $c['responsible_user_id'] !== null && (int)$c['responsible_user_id'] === (int)$ctx['user']['id'];
    $out = ['no_ai' => (int)apr_val('SELECT no_ai FROM case_ai WHERE case_id = ?', [$c['id']]) === 1, 'can_set' => $mine && $c['status'] !== 'closed', 'usage' => null, 'quotas' => []];
    if ($mine) {
        $sum = apr_row('SELECT ' . APR_AI_USAGE_SUMS . ' FROM ai_runs r WHERE r.case_id = ?', [$c['id']]);
        $out['usage'] = apr_ai_usage_row($sum + ['runs' => 0]);
        $out['quotas'] = array_map('apr_ai_quota_out', apr_rows("SELECT * FROM ai_quotas WHERE deleted_at IS NULL AND scope = 'case' AND scope_id = ? ORDER BY id", [$c['id']]));
    }
    return $out;
}

/** המתג "בלי בינה" — השמאי האחראי בלבד. חוסם כל ai_run על התיק (11-D2). */
function apr_case_ai_set(array $ctx, array $in): array
{
    $on = is_bool($in['no_ai'] ?? null) ? $in['no_ai'] : apr_fail('ai_request');
    return apr_tx(function () use ($ctx, $in, $on) {
        $c = apr_case_get($ctx, $in['case'] ?? null);
        if ($c['responsible_user_id'] === null || (int)$c['responsible_user_id'] !== (int)$ctx['user']['id']) apr_fail('forbidden');
        apr_case_writable($c);
        $was = (int)apr_val('SELECT no_ai FROM case_ai WHERE case_id = ?', [$c['id']]) === 1;
        if ($was !== $on) {
            apr_exec('INSERT INTO case_ai (case_id, no_ai, set_by, set_at) VALUES (?, ?, ?, ?)
                ON CONFLICT(case_id) DO UPDATE SET no_ai = excluded.no_ai, set_by = excluded.set_by, set_at = excluded.set_at', [$c['id'], (int)$on, $ctx['user']['id'], apr_now()]);
            apr_case_audit($c, 'case.ai', 'case', null, ['no_ai' => $on]);
            apr_case_touch($c);
        }
        return ['no_ai' => $on];
    });
}
