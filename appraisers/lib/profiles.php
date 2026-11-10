<?php
/**
 * פרופילים (אפיון 03): ספרייה בשלוש רמות, טיוטה וגרסאות מפורסמות, שכפול, ארכוב, שיתוף, ותצוגת ניסיון.
 *
 * ההרשאה לפרופיל מוכרעת במקום אחד — apr_profile_level(): null (לא קיים בשבילי → 404) · view · duplicate · edit · owner.
 *   מומלץ:  כל משתמש פעיל — duplicate; מנהל המערכת — owner.
 *   משרדי:  חבר פעיל במשרד (כשהוא המשרד הפעיל) — view; מנהל המשרד — owner.
 *   אישי:   הבעלים, כשהמשרד של הפרופיל הוא המשרד הפעיל שלו — owner.
 *   שיתוף:  למשתמש, או למשרד הפעיל שלי — הרמה שניתנה (view ← duplicate ← edit). edit אינו כולל שיתוף וארכוב.
 * גרסה מפורסמת אינה נערכת ואינה נמחקת (גם טריגר במסד). החוצה יוצאים רק pid, פרטי הפרופיל ו-content.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/profile_content.php';
require_once __DIR__ . '/profile_seed.php';

const APR_PROFILE_LEVELS = ['view' => 1, 'duplicate' => 2, 'edit' => 3, 'owner' => 4];

/** המשרד הפעיל של הבקשה, אם אפשר לפעול בו (משרד פעיל, חברות פעילה) — אחרת null. לא זורק. */
function apr_profile_office(array $ctx): ?array
{
    $o = $ctx['office'] ?? null;
    return $o && $o['office_status'] === 'active' && $o['status'] === 'active' ? $o : null;
}

function apr_profile_level(array $ctx, array $p): ?string
{
    if ($p['deleted_at'] !== null) return null;
    $u = $ctx['user'];
    $o = apr_profile_office($ctx);
    $level = 0;
    if ($p['scope'] === 'recommended') {
        $level = (int)$u['is_sysadmin'] ? 4 : 2;
    } elseif ($o && (int)$o['office_id'] === (int)$p['office_id']) {
        if ($p['scope'] === 'office') $level = (int)$o['is_admin'] ? 4 : 1;
        elseif ((int)$p['owner_user_id'] === (int)$u['id']) $level = 4;
    }
    if ($p['scope'] !== 'recommended') {
        foreach (apr_rows("SELECT can FROM profile_shares WHERE profile_id = ? AND revoked_at IS NULL
            AND ((grantee_kind = 'user' AND grantee_id = ?) OR (grantee_kind = 'office' AND grantee_id = ?))", [$p['id'], $u['id'], $o ? (int)$o['office_id'] : 0]) as $s) {
            $level = max($level, APR_PROFILE_LEVELS[$s['can']]);
        }
    }
    return $level ? array_search($level, APR_PROFILE_LEVELS, true) : null;
}

/** הפרופיל לפי pid, אם יש לי לפחות $need. אין הרשאה ולא קיים — אותה תשובה (404); יש צפייה ואין מספיק — 403. */
function apr_profile_get(array $ctx, mixed $pid, string $need = 'view'): array
{
    $p = apr_is_pid($pid) ? apr_row('SELECT * FROM profiles WHERE pid = ?', [$pid]) : null;
    $level = $p ? apr_profile_level($ctx, $p) : null;
    if ($level === null) apr_fail('not_found');
    if (APR_PROFILE_LEVELS[$level] < APR_PROFILE_LEVELS[$need]) apr_fail('forbidden');
    return $p + ['level' => $level];
}

function apr_profile_content_read(?string $json): array
{
    $c = json_decode((string)$json, true);
    // schema ישן, או מקטע שעוד לא היה: משלימים את החסר; מקטע שאיננו מכירים — מתעלמים ממנו
    $empty = apr_profile_empty_content();
    return ['schema' => APR_PROFILE_SCHEMA] + (is_array($c) ? array_merge($empty, array_intersect_key($c, $empty)) : $empty);
}

function apr_doc_type_keys(): array
{
    return array_column(apr_rows('SELECT key FROM doc_types'), 'key');
}

/** פרטי הפרופיל כפי שיוצאים מהשרת — רשימה סגורה של שדות. */
function apr_profile_out(array $p, ?string $level = null): array
{
    $cur = $p['current_version_id'] !== null ? apr_row('SELECT pid, number, published_at FROM profile_versions WHERE id = ?', [$p['current_version_id']]) : null;
    $out = ['pid' => $p['pid'], 'scope' => $p['scope'], 'name' => $p['name'], 'description' => $p['description'],
        'purposes' => json_decode($p['purposes'], true) ?: [], 'property_types' => json_decode($p['property_types'], true) ?: [],
        'status' => $p['status'], 'is_sample' => (bool)$p['is_sample'],
        'owner' => $p['owner_user_id'] !== null ? (string)apr_val('SELECT name FROM users WHERE id = ?', [$p['owner_user_id']]) : null,
        'version' => $cur ? ['pid' => $cur['pid'], 'number' => (int)$cur['number'], 'published_at' => $cur['published_at']] : null,
        'has_draft' => (bool)apr_val("SELECT 1 FROM profile_versions WHERE profile_id = ? AND state = 'draft'", [$p['id']]),
        'updated_at' => $p['updated_at'], 'source' => null];
    if ($p['source_version_id'] !== null) {
        $s = apr_row('SELECT v.number, p.name, p.status, p.deleted_at, cv.number AS latest FROM profile_versions v JOIN profiles p ON p.id = v.profile_id
            LEFT JOIN profile_versions cv ON cv.id = p.current_version_id WHERE v.id = ?', [$p['source_version_id']]);
        // "שוכפל מ: <שם> גרסה N" — מידע בלבד, בלי מזהה של המקור
        if ($s) $out['source'] = ['name' => $s['name'], 'number' => (int)$s['number'], 'archived' => $s['status'] === 'archived' || $s['deleted_at'] !== null,
            'newer' => $s['deleted_at'] === null && (int)$s['latest'] > (int)$s['number']];
    }
    if ($level !== null) {
        $n = APR_PROFILE_LEVELS[$level];
        $out['can'] = ['duplicate' => $n >= 2 && $cur !== null, 'edit' => $n >= 3, 'manage' => $n >= 4, 'share' => $n >= 4 && $p['scope'] !== 'recommended'];
    }
    return $out;
}

function apr_version_out(array $v): array
{
    return ['pid' => $v['pid'], 'number' => $v['number'] === null ? null : (int)$v['number'], 'state' => $v['state'], 'change_note' => $v['change_note'],
        'published_at' => $v['published_at'], 'published_by' => $v['published_by'] !== null ? (string)apr_val('SELECT name FROM users WHERE id = ?', [$v['published_by']]) : null,
        'revision' => (int)($v['revision'] ?? 1), 'updated_at' => $v['updated_at'], 'updated_by' => ($v['updated_by'] ?? null) !== null ? (string)apr_val('SELECT name FROM users WHERE id = ?', [$v['updated_by']]) : null];
}

// ── רשימות ───────────────────────────────────────────────────

/** הרשימות הסגורות שהעורך צריך. סוגי מסמך מוסתרים לא מוצעים לבחירה. */
function apr_profile_lists(): array
{
    return ['purposes' => APR_PURPOSES, 'property_types' => APR_PROPERTY_TYPES, 'priorities' => APR_PRIORITIES, 'party_kinds' => APR_PARTY_KINDS,
        'due_modes' => APR_DUE_MODES, 'assignees' => APR_ASSIGNEES, 'ops' => APR_COND_OPS, 'limits' => APR_PROFILE_LIMITS,
        'doc_types' => apr_rows('SELECT key, name, description FROM doc_types WHERE hidden = 0 ORDER BY sort, name')];
}

/**
 * הספרייה: מומלצים · של המשרד · שלי · שותפו איתי — של המשרד הפעיל בלבד. q — חיפוש בשם ובתיאור.
 * pick=1 — "פרופילים לבחירה" לתיק חדש: רק פעילים עם גרסה מפורסמת (בלי ארכיון ובלי טיוטות).
 */
function apr_profiles_list(array $ctx, array $q): array
{
    apr_profiles_seed();
    $u = $ctx['user'];
    $o = apr_profile_office($ctx);
    $oid = $o ? (int)$o['office_id'] : 0;
    $pick = ($q['pick'] ?? '') === '1';
    $rows = apr_rows("SELECT DISTINCT p.* FROM profiles p
        LEFT JOIN profile_shares s ON s.profile_id = p.id AND s.revoked_at IS NULL
            AND ((s.grantee_kind = 'user' AND s.grantee_id = :u) OR (s.grantee_kind = 'office' AND s.grantee_id = :o))
        WHERE p.deleted_at IS NULL AND (p.scope = 'recommended' OR (p.office_id = :o AND (p.scope = 'office' OR p.owner_user_id = :u)) OR s.id IS NOT NULL)
        ORDER BY p.name, p.id", [':u' => $u['id'], ':o' => $oid]);
    $needle = mb_strtolower(trim(is_string($q['q'] ?? null) ? $q['q'] : ''));
    $out = ['recommended' => [], 'office' => [], 'mine' => [], 'shared' => []];
    foreach ($rows as $p) {
        $level = apr_profile_level($ctx, $p);
        if ($level === null) continue;
        if ($pick && ($p['status'] !== 'active' || $p['current_version_id'] === null)) continue;
        // טיוטה שעוד לא פורסמה נראית רק למי שיכול לערוך אותה
        if ($p['current_version_id'] === null && APR_PROFILE_LEVELS[$level] < 3) continue;
        if ($needle !== '' && !str_contains(mb_strtolower($p['name'] . ' ' . $p['description']), $needle)) continue;
        $mineHere = $oid && (int)$p['office_id'] === $oid;
        $tab = $p['scope'] === 'recommended' ? 'recommended' : ($mineHere && $p['scope'] === 'office' ? 'office' : ($mineHere && (int)$p['owner_user_id'] === (int)$u['id'] ? 'mine' : 'shared'));
        $out[$tab][] = apr_profile_out($p, $level);
    }
    $settings = $o ? json_decode((string)apr_val('SELECT settings FROM offices WHERE id = ?', [$oid]), true) : [];
    return $out + ['required_profile' => $settings['required_profile'] ?? null,
        'can_create' => ['personal' => (bool)$o, 'office' => $o && (int)$o['is_admin'], 'recommended' => (bool)$u['is_sysadmin']]];
}

/** צפייה: פרטי הפרופיל והגרסה המפורסמת האחרונה (או הטיוטה, למי שעורך פרופיל שעוד לא פורסם). */
function apr_profile_view(array $ctx, array $q): array
{
    $p = apr_profile_get($ctx, $q['profile'] ?? null);
    $v = $p['current_version_id'] !== null ? apr_row('SELECT * FROM profile_versions WHERE id = ?', [$p['current_version_id']]) : null;
    if (!$v && APR_PROFILE_LEVELS[$p['level']] < 3) apr_fail('not_found');
    return ['profile' => apr_profile_out($p, $p['level']), 'version' => $v ? apr_version_out($v) : null, 'content' => $v ? apr_profile_content_read($v['content']) : null];
}

/** גרסה מפורסמת לפי המזהה שלה — נקראת גם כשהפרופיל בארכיון. */
function apr_profile_version(array $ctx, array $q): array
{
    $v = apr_is_pid($q['version'] ?? null) ? apr_row("SELECT * FROM profile_versions WHERE pid = ? AND state = 'published'", [$q['version']]) : null;
    $p = $v ? apr_row('SELECT * FROM profiles WHERE id = ?', [$v['profile_id']]) : null;
    if (!$p || apr_profile_level($ctx, $p) === null) apr_fail('not_found');
    return ['version' => apr_version_out($v), 'content' => apr_profile_content_read($v['content']), 'profile' => ['pid' => $p['pid'], 'name' => $p['name'], 'status' => $p['status']]];
}

function apr_profile_versions(array $ctx, array $q): array
{
    $p = apr_profile_get($ctx, $q['profile'] ?? null);
    return ['versions' => array_map('apr_version_out', apr_rows("SELECT * FROM profile_versions WHERE profile_id = ? AND state = 'published' ORDER BY number DESC", [$p['id']]))];
}

/** השוואה בין שתי גרסאות מפורסמות של אותו פרופיל (או: בין גרסה לטיוטה, כש-b ריק ויש הרשאת עריכה). */
function apr_profile_compare(array $ctx, array $q): array
{
    $p = apr_profile_get($ctx, $q['profile'] ?? null);
    $pub = fn(mixed $pid) => (apr_is_pid($pid) ? apr_row("SELECT * FROM profile_versions WHERE pid = ? AND profile_id = ? AND state = 'published'", [$pid, $p['id']]) : null) ?? apr_fail('not_found');
    $a = $pub($q['a'] ?? null);
    $b = $pub($q['b'] ?? null);
    return ['a' => (int)$a['number'], 'b' => (int)$b['number'], 'diff' => apr_profile_diff(apr_profile_content_read($a['content']), apr_profile_content_read($b['content']))];
}

// ── יצירה, פרטים, טיוטה, פרסום ────────────────────────────────

function apr_profile_meta_clean(array $in, array $base = []): array
{
    $list = function (string $key, array $allowed) use ($in, $base): string {
        if (!array_key_exists($key, $in)) return $base[$key] ?? '[]';
        $v = $in[$key];
        if (!is_array($v) || !array_is_list($v) || array_filter($v, fn($x) => !is_string($x) || !in_array($x, $allowed, true))) apr_fail('content_invalid');
        return json_encode(array_values(array_unique($v)));
    };
    return ['name' => array_key_exists('name', $in) || !$base ? apr_clean_name($in['name'] ?? null) : $base['name'],
        'description' => array_key_exists('description', $in) ? (apr_clean_text($in['description'], 500) ?? '') : ($base['description'] ?? ''),
        'purposes' => $list('purposes', APR_PURPOSES), 'property_types' => $list('property_types', APR_PROPERTY_TYPES)];
}

/** מי רשאי ליצור פרופיל ברמה הזו. מחזיר את מזהה המשרד (null למומלץ). */
function apr_profile_can_create(array $ctx, mixed $scope): ?int
{
    if ($scope === 'recommended') {
        if (!(int)$ctx['user']['is_sysadmin']) apr_fail('forbidden');
        return null;
    }
    if ($scope !== 'office' && $scope !== 'personal') apr_fail('content_invalid');
    $o = apr_office($ctx);
    if ($scope === 'office' && !(int)$o['is_admin']) apr_fail('forbidden');
    return (int)$o['office_id'];
}

function apr_profile_insert(string $scope, ?int $office, ?int $owner, array $meta, array $content, ?int $sourceVersion = null, bool $sample = false): array
{
    $now = apr_now();
    $pid = apr_pid();
    $id = apr_insert('profiles', ['pid' => $pid, 'scope' => $scope, 'office_id' => $office, 'owner_user_id' => $owner] + $meta
        + ['source_version_id' => $sourceVersion, 'is_sample' => (int)$sample, 'created_at' => $now, 'updated_at' => $now]);
    apr_insert('profile_versions', ['pid' => apr_pid(), 'profile_id' => $id, 'content' => json_encode($content, JSON_UNESCAPED_UNICODE),
        'updated_by' => $owner, 'created_at' => $now, 'updated_at' => $now]);
    return ['id' => $id, 'pid' => $pid];
}

function apr_profile_create(array $ctx, array $in): array
{
    $scope = $in['scope'] ?? 'personal';
    $office = apr_profile_can_create($ctx, $scope);
    $meta = apr_profile_meta_clean($in);
    return apr_tx(function () use ($ctx, $scope, $office, $meta) {
        $r = apr_profile_insert($scope, $office, (int)$ctx['user']['id'], $meta, apr_profile_empty_content());
        apr_audit('profile.create', 'profile', $r['pid'], ['scope' => $scope], office: $office);
        return ['profile' => $r['pid']];
    });
}

function apr_profile_update(array $ctx, array $in): array
{
    $p = apr_profile_get($ctx, $in['profile'] ?? null, 'edit');
    $meta = apr_profile_meta_clean($in, $p);
    // "דוגמה — טעון אישור מקצועי": מנהל המערכת מנקה (או מחזיר) בפרופיל מומלץ, אחרי שאושר מקצועית
    $sample = $p['scope'] === 'recommended' && $p['level'] === 'owner' && is_bool($in['is_sample'] ?? null) ? (int)$in['is_sample'] : (int)$p['is_sample'];
    return apr_tx(function () use ($p, $meta, $sample) {
        apr_exec('UPDATE profiles SET name = ?, description = ?, purposes = ?, property_types = ?, is_sample = ?, updated_at = ? WHERE id = ?', [...array_values($meta), $sample, apr_now(), $p['id']]);
        apr_audit('profile.update', 'profile', $p['pid'], [], office: $p['office_id'] === null ? null : (int)$p['office_id']);
        return [];
    });
}

function apr_profile_draft_row(int $profileId): ?array
{
    return apr_row("SELECT * FROM profile_versions WHERE profile_id = ? AND state = 'draft'", [$profileId]);
}

/** פותח את הטיוטה לעריכה. אין טיוטה — נוצרת אחת, כהעתק של הגרסה המפורסמת האחרונה. */
function apr_profile_draft_open(array $ctx, array $in): array
{
    $p = apr_profile_get($ctx, $in['profile'] ?? null, 'edit');
    $d = apr_tx(function () use ($ctx, $p) {
        $d = apr_profile_draft_row((int)$p['id']);
        if ($d) return $d;
        $cur = $p['current_version_id'] !== null ? apr_val('SELECT content FROM profile_versions WHERE id = ?', [$p['current_version_id']]) : null;
        $now = apr_now();
        $id = apr_insert('profile_versions', ['pid' => apr_pid(), 'profile_id' => $p['id'],
            'content' => json_encode(apr_profile_content_read(is_string($cur) ? $cur : null), JSON_UNESCAPED_UNICODE), 'updated_by' => $ctx['user']['id'], 'created_at' => $now, 'updated_at' => $now]);
        return apr_row('SELECT * FROM profile_versions WHERE id = ?', [$id]);
    });
    $content = apr_profile_content_read($d['content']);
    return ['profile' => apr_profile_out($p, $p['level']), 'draft' => apr_version_out($d), 'content' => $content, 'checks' => apr_profile_checks($content, apr_doc_type_keys())];
}

/** האסימון של הטיוטה: revision שהעורך קרא. חסר, לא מספר, או לא העדכני → 409 draft_changed. */
function apr_profile_draft_token(array $d, array $in): void
{
    if (!is_int($in['revision'] ?? null) || $in['revision'] !== (int)$d['revision']) apr_fail('draft_changed');
}

/**
 * שמירת הטיוטה. revision — מה שהעורך קרא: אם הטיוטה נשמרה בינתיים → 409 draft_changed, בלי דריסה. המספר עולה
 * ב-1 בכל שמירה, באותה פקודת UPDATE שבודקת אותו.
 * המבנה מאומת (apr_profile_content_clean); בדיקות המשמעות חוזרות בתשובה ואינן חוסמות שמירה.
 */
function apr_profile_draft_save(array $ctx, array $in): array
{
    $p = apr_profile_get($ctx, $in['profile'] ?? null, 'edit');
    $content = apr_profile_content_clean($in['content'] ?? null);
    return apr_tx(function () use ($ctx, $p, $in, $content) {
        $d = apr_profile_draft_row((int)$p['id']) ?? apr_fail('not_found');
        apr_profile_draft_token($d, $in);
        $n = apr_exec("UPDATE profile_versions SET content = ?, updated_by = ?, updated_at = ?, revision = revision + 1 WHERE id = ? AND state = 'draft' AND revision = ?",
            [json_encode($content, JSON_UNESCAPED_UNICODE), $ctx['user']['id'], apr_now(), $d['id'], $in['revision']]);
        if ($n !== 1) apr_fail('draft_changed');
        return ['revision' => (int)$d['revision'] + 1, 'checks' => apr_profile_checks($content, apr_doc_type_keys())];
    });
}

/** מי שמר אחרון — בשביל "הטיוטה עודכנה בידי <שם>". */
function apr_profile_draft_info(array $ctx, array $q): array
{
    $p = apr_profile_get($ctx, $q['profile'] ?? null, 'edit');
    $d = apr_profile_draft_row((int)$p['id']) ?? apr_fail('not_found');
    return ['draft' => apr_version_out($d)];
}

/** ביטול הטיוטה — הרסני, ולכן: לבעלים בלבד, עם אסימון הטיוטה (מה שהוא רואה הוא מה שנמחק), ונרשם ביומן. */
function apr_profile_draft_discard(array $ctx, array $in): array
{
    $p = apr_profile_get($ctx, $in['profile'] ?? null, 'owner');
    if ($p['current_version_id'] === null) apr_fail('profile_state');   // פרופיל שלא פורסם — מוחקים אותו, לא את הטיוטה
    return apr_tx(function () use ($p, $in) {
        $d = apr_profile_draft_row((int)$p['id']) ?? apr_fail('not_found');
        apr_profile_draft_token($d, $in);
        apr_exec("DELETE FROM profile_versions WHERE id = ? AND state = 'draft'", [$d['id']]);
        apr_audit('profile.draft_discard', 'profile', $p['pid'], [], office: $p['office_id'] === null ? null : (int)$p['office_id']);
        return [];
    });
}

/** פרסום: הבדיקות של §8 (❌ חוסם), מספר עוקב, והטיוטה הופכת לגרסה. מחזיר גם סיכום שינויים מול הקודמת. */
function apr_profile_publish(array $ctx, array $in): array
{
    $p = apr_profile_get($ctx, $in['profile'] ?? null, 'edit');
    $note = apr_clean_text($in['note'] ?? '', 500);
    return apr_tx(function () use ($ctx, $p, $note, $in) {
        $d = apr_profile_draft_row((int)$p['id']) ?? apr_fail('not_found');
        apr_profile_draft_token($d, $in);   // מפרסמים את מה שהעורך רואה — לא טיוטה שמישהו שינה בינתיים
        $content = apr_profile_content_read($d['content']);
        $checks = apr_profile_checks($content, apr_doc_type_keys());
        if ($checks['errors']) throw new AprPublishBlocked($checks);
        $number = (int)apr_val('SELECT COALESCE(MAX(number), 0) FROM profile_versions WHERE profile_id = ?', [$p['id']]) + 1;
        $now = apr_now();
        apr_exec("UPDATE profile_versions SET state = 'published', number = ?, change_note = ?, published_by = ?, published_at = ?, updated_at = ? WHERE id = ? AND state = 'draft'",
            [$number, $note, $ctx['user']['id'], $now, $now, $d['id']]);
        // עותק של דוגמה שהמשתמש פרסם בו גרסה משלו — כבר אינו "דוגמה". במומלץ — מנהל המערכת מנקה במפורש (profile-update).
        apr_exec("UPDATE profiles SET current_version_id = ?, status = CASE WHEN status = 'draft' THEN 'active' ELSE status END,
            is_sample = CASE WHEN scope = 'recommended' THEN is_sample ELSE 0 END, updated_at = ? WHERE id = ?", [$d['id'], $now, $p['id']]);
        apr_audit('profile.publish', 'profile', $p['pid'], ['number' => $number], office: $p['office_id'] === null ? null : (int)$p['office_id']);
        return ['version' => $d['pid'], 'number' => $number, 'warnings' => $checks['warnings']];
    });
}

/** הפרסום נחסם בבדיקות: 422 עם הרשימה, כדי שהעורך יציג אותה. */
final class AprPublishBlocked extends AprError
{
    public function __construct(public readonly array $checks)
    {
        parent::__construct('publish_blocked');
    }
}

/** הבדיקות וסיכום השינויים — לפני שמאשרים פרסום. */
function apr_profile_check(array $ctx, array $q): array
{
    $p = apr_profile_get($ctx, $q['profile'] ?? null, 'edit');
    $d = apr_profile_draft_row((int)$p['id']) ?? apr_fail('not_found');
    $content = apr_profile_content_read($d['content']);
    $prev = $p['current_version_id'] !== null ? apr_row('SELECT number, content FROM profile_versions WHERE id = ?', [$p['current_version_id']]) : null;
    return ['checks' => apr_profile_checks($content, apr_doc_type_keys()), 'previous' => $prev ? (int)$prev['number'] : null,
        'diff' => apr_profile_diff($prev ? apr_profile_content_read($prev['content']) : apr_profile_empty_content(), $content)];
}

// ── ארכוב, מחיקה, שכפול ───────────────────────────────────────

function apr_profile_archive(array $ctx, array $in): array
{
    $p = apr_profile_get($ctx, $in['profile'] ?? null, 'owner');
    $archive = ($in['archived'] ?? true) === true;
    if ($p['current_version_id'] === null) apr_fail('profile_state');
    return apr_tx(function () use ($p, $archive) {
        $to = $archive ? 'archived' : 'active';
        if ($p['status'] !== $to) {
            apr_exec('UPDATE profiles SET status = ?, updated_at = ? WHERE id = ?', [$to, apr_now(), $p['id']]);
            if ($archive) apr_required_profile_clear($p);
            apr_audit($archive ? 'profile.archive' : 'profile.unarchive', 'profile', $p['pid'], [], office: $p['office_id'] === null ? null : (int)$p['office_id']);
        }
        return [];
    });
}

/** מחיקה אמיתית — רק לפרופיל שלא פורסם לעולם. כל השאר: ארכוב. */
function apr_profile_delete(array $ctx, array $in): array
{
    $p = apr_profile_get($ctx, $in['profile'] ?? null, 'owner');
    return apr_tx(function () use ($p) {
        if (apr_val("SELECT 1 FROM profile_versions WHERE profile_id = ? AND state = 'published'", [$p['id']])) apr_fail('profile_state');
        apr_exec("DELETE FROM profile_versions WHERE profile_id = ? AND state = 'draft'", [$p['id']]);
        apr_exec('UPDATE profiles SET deleted_at = ?, updated_at = ? WHERE id = ?', [apr_now(), apr_now(), $p['id']]);
        apr_audit('profile.delete', 'profile', $p['pid'], [], office: $p['office_id'] === null ? null : (int)$p['office_id']);
        return [];
    });
}

/**
 * שכפול: פרופיל חדש — אישי, או משרדי למנהל — במשרד הפעיל שלי, עם source_version_id. מועתק רק content ופרטי
 * הפרופיל; העותק מנותק מהמקור, ומתחיל כטיוטה.
 */
function apr_profile_duplicate(array $ctx, array $in): array
{
    $p = apr_profile_get($ctx, $in['profile'] ?? null, 'duplicate');
    $scope = $in['scope'] ?? 'personal';
    if ($scope === 'recommended') apr_fail('content_invalid');
    $office = apr_profile_can_create($ctx, $scope);
    $src = $p['current_version_id'] !== null ? apr_row('SELECT * FROM profile_versions WHERE id = ?', [$p['current_version_id']]) : apr_fail('profile_state');
    $name = array_key_exists('name', $in) ? apr_clean_name($in['name']) : mb_substr($p['name'] . ' — עותק', 0, 120);
    return apr_tx(function () use ($ctx, $p, $src, $scope, $office, $name) {
        $r = apr_profile_insert($scope, $office, (int)$ctx['user']['id'], ['name' => $name, 'description' => $p['description'], 'purposes' => $p['purposes'],
            'property_types' => $p['property_types']], apr_profile_content_read($src['content']), (int)$src['id'], (bool)$p['is_sample']);
        apr_audit('profile.duplicate', 'profile', $r['pid'], ['scope' => $scope, 'from_number' => (int)$src['number']], office: $office);
        return ['profile' => $r['pid']];
    });
}

// ── תצוגת ניסיון ──────────────────────────────────────────────

/** מה ייווצר לתיק לדוגמה — מהטיוטה (draft: true, למי שעורך) או מהגרסה המפורסמת. קריאה בלבד: שום שורה לא נכתבת. */
function apr_profile_try_action(array $ctx, array $in): array
{
    $draft = ($in['draft'] ?? false) === true;
    $p = apr_profile_get($ctx, $in['profile'] ?? null, $draft ? 'edit' : 'view');
    $v = $draft ? apr_profile_draft_row((int)$p['id']) : ($p['current_version_id'] !== null ? apr_row('SELECT * FROM profile_versions WHERE id = ?', [$p['current_version_id']]) : null);
    if (!$v) apr_fail('not_found');
    $content = apr_profile_content_read($v['content']);
    return ['flags' => $content['flags'], 'result' => apr_profile_try($content, is_array($in['input'] ?? null) ? $in['input'] : [])];
}

// ── שיתוף ─────────────────────────────────────────────────────

/** הנמען: משתמש קיים לפי מייל מאומת, או המשרד הפעיל שלי. */
function apr_profile_grantee(array $ctx, array $in): array
{
    if (($in['office'] ?? null) === true) {
        $o = apr_office($ctx);
        return ['office', (int)$o['office_id'], $o['name'], $o['pid']];
    }
    $email = apr_clean_email($in['email'] ?? null);
    $u = apr_row("SELECT id, pid, name FROM users WHERE email = ? AND email_verified = 1 AND status = 'active'", [$email]) ?? apr_fail('share_no_user');
    if ((int)$u['id'] === (int)$ctx['user']['id']) apr_fail('self_action');
    return ['user', (int)$u['id'], $u['name'], $u['pid']];
}

function apr_profile_share_args(array $ctx, array $in): array
{
    $p = apr_profile_get($ctx, $in['profile'] ?? null, 'owner');
    if ($p['scope'] === 'recommended') apr_fail('profile_state');   // מומלץ גלוי לכולם ממילא
    // התצוגה המקדימה הופכת מייל לשם — מוגבלת בקצב, למשתמש: 30 ב-15 דקות (preview ו-share יחד)
    if (!apr_limit('pshare:u:' . $ctx['user']['id'], 30, 900)) apr_fail('too_many_requests');
    $can = is_string($in['can'] ?? null) && in_array($in['can'], ['view', 'duplicate', 'edit'], true) ? $in['can'] : apr_fail('content_invalid');
    return [$p, $can, apr_profile_grantee($ctx, $in)];
}

/** תצוגה מקדימה לפני אישור: מי הנמען ומה יוכל לעשות. מה שלא עובר — נוסח קבוע במסך. */
function apr_profile_share_preview(array $ctx, array $in): array
{
    [$p, $can, [$kind, , $name]] = apr_profile_share_args($ctx, $in);
    return ['grantee' => ['kind' => $kind, 'name' => $name], 'can' => $can, 'profile' => $p['name']];
}

function apr_profile_share(array $ctx, array $in): array
{
    [$p, $can, [$kind, $gid, , $gpid]] = apr_profile_share_args($ctx, $in);
    return apr_tx(function () use ($ctx, $p, $can, $kind, $gid, $gpid) {
        $prev = apr_row('SELECT * FROM profile_shares WHERE profile_id = ? AND grantee_kind = ? AND grantee_id = ? AND revoked_at IS NULL', [$p['id'], $kind, $gid]);
        if ($prev) {
            if ($prev['can'] !== $can) {
                apr_exec('UPDATE profile_shares SET can = ? WHERE id = ?', [$can, $prev['id']]);
                apr_audit('profile.share', 'profile', $p['pid'], ['kind' => $kind, 'can' => $can, 'grantee' => $gpid, 'change' => true], office: (int)$p['office_id']);
            }
            return ['share' => $prev['pid']];
        }
        $pid = apr_pid();
        apr_insert('profile_shares', ['pid' => $pid, 'profile_id' => $p['id'], 'grantee_kind' => $kind, 'grantee_id' => $gid, 'can' => $can,
            'granted_by' => $ctx['user']['id'], 'created_at' => apr_now()]);
        apr_audit('profile.share', 'profile', $p['pid'], ['kind' => $kind, 'can' => $can, 'grantee' => $gpid], office: (int)$p['office_id']);
        return ['share' => $pid];
    });
}

function apr_profile_shares(array $ctx, array $q): array
{
    $p = apr_profile_get($ctx, $q['profile'] ?? null, 'owner');
    return ['shares' => array_map(fn($s) => ['pid' => $s['pid'], 'kind' => $s['grantee_kind'], 'can' => $s['can'], 'created_at' => $s['created_at'],
        'name' => (string)apr_val($s['grantee_kind'] === 'user' ? 'SELECT name FROM users WHERE id = ?' : 'SELECT name FROM offices WHERE id = ?', [$s['grantee_id']]),
        'email' => $s['grantee_kind'] === 'user' ? apr_val('SELECT email FROM users WHERE id = ?', [$s['grantee_id']]) ?: null : null],
        apr_rows('SELECT * FROM profile_shares WHERE profile_id = ? AND revoked_at IS NULL ORDER BY id', [$p['id']]))];
}

/** ביטול שיתוף: חוסם גישה מכאן והלאה. עותק שכבר שוכפל נשאר אצל המשכפל. */
function apr_profile_share_revoke(array $ctx, array $in): array
{
    $s = apr_is_pid($in['share'] ?? null) ? apr_row('SELECT s.*, p.pid AS profile_pid FROM profile_shares s JOIN profiles p ON p.id = s.profile_id WHERE s.pid = ? AND s.revoked_at IS NULL', [$in['share']]) : null;
    $p = apr_profile_get($ctx, $s['profile_pid'] ?? null, 'owner');
    return apr_tx(function () use ($ctx, $s, $p) {
        apr_exec('UPDATE profile_shares SET revoked_at = ?, revoked_by = ? WHERE id = ?', [apr_now(), $ctx['user']['id'], $s['id']]);
        apr_audit('profile.unshare', 'profile', $p['pid'], ['kind' => $s['grantee_kind'], 'grantee' => (string)apr_val($s['grantee_kind'] === 'user' ? 'SELECT pid FROM users WHERE id = ?' : 'SELECT pid FROM offices WHERE id = ?', [$s['grantee_id']])], office: (int)$p['office_id']);
        return [];
    });
}

/**
 * פרופיל שאי אפשר עוד לבחור (אורכב) אינו נשאר "הפרופיל המחייב" של המשרד — אחרת תיקים חדשים ננעלים על פרופיל
 * שאינו זמין. מנקים באותה טרנזקציה, עם רישום ביומן.
 */
function apr_required_profile_clear(array $p): void
{
    if ($p['office_id'] === null) return;
    $s = json_decode((string)apr_val('SELECT settings FROM offices WHERE id = ?', [$p['office_id']]), true) ?: [];
    if (($s['required_profile'] ?? null) !== $p['pid']) return;
    unset($s['required_profile']);
    apr_exec('UPDATE offices SET settings = ?, updated_at = ? WHERE id = ?', [json_encode((object)$s), apr_now(), $p['office_id']]);
    apr_audit('office.required_profile', 'office', (string)apr_val('SELECT pid FROM offices WHERE id = ?', [$p['office_id']]), ['set' => false, 'by' => 'archive'], office: (int)$p['office_id'], actor: null, kind: 'system');
}

// ── הגדרת משרד: פרופיל משרדי מחייב ────────────────────────────

/** "פרופיל משרדי מחייב" — ההגדרה נשמרת כאן; האכיפה בפתיחת תיק (פרוסה 04). null — כבוי. */
function apr_office_required_profile(array $ctx, array $in): array
{
    $office = apr_office_id($ctx);
    $pid = $in['profile'] ?? null;
    if ($pid !== null) {
        $p = apr_profile_get($ctx, $pid);
        if ($p['scope'] !== 'office' || (int)$p['office_id'] !== $office || $p['status'] !== 'active') apr_fail('profile_state');
    }
    return apr_tx(function () use ($office, $pid) {
        $s = json_decode((string)apr_val('SELECT settings FROM offices WHERE id = ?', [$office]), true) ?: [];
        if ($pid === null) unset($s['required_profile']); else $s['required_profile'] = $pid;
        apr_exec('UPDATE offices SET settings = ?, updated_at = ? WHERE id = ?', [json_encode((object)$s), apr_now(), $office]);
        apr_audit('office.required_profile', 'office', (string)apr_val('SELECT pid FROM offices WHERE id = ?', [$office]), ['set' => $pid !== null], office: $office);
        return [];
    });
}

/**
 * חבר עוזב את המשרד (או מוסר ממנו): הפרופילים האישיים שלו במשרד נשארים בו — כפרופיל משרדי בארכיון.
 * נקרא מתוך הטרנזקציה של העזיבה.
 */
function apr_profiles_on_leave(int $office, int $user): void
{
    foreach (apr_rows("SELECT id, pid, office_id FROM profiles WHERE office_id = ? AND owner_user_id = ? AND scope = 'personal' AND deleted_at IS NULL", [$office, $user]) as $p) {
        apr_required_profile_clear($p);
        apr_exec("UPDATE profiles SET scope = 'office', status = CASE WHEN current_version_id IS NULL THEN status ELSE 'archived' END, updated_at = ? WHERE id = ?", [apr_now(), $p['id']]);
        apr_audit('profile.to_office', 'profile', $p['pid'], [], office: $office, actor: null, kind: 'system');
    }
}

// ── סוגי מסמך (מנהל המערכת) ───────────────────────────────────

function apr_doc_types(): array
{
    return ['doc_types' => array_map(fn($r) => ['key' => $r['key'], 'name' => $r['name'], 'description' => $r['description'], 'hidden' => (bool)$r['hidden'], 'sort' => (int)$r['sort']],
        apr_rows('SELECT * FROM doc_types ORDER BY sort, name'))];
}

/** הוספה ועריכה. אין מחיקה: סוג שאינו רצוי — מוסתר (hidden), והפרופילים שמשתמשים בו ממשיכים לעבוד. */
function apr_doc_type_save(array $in): array
{
    $key = is_string($in['key'] ?? null) && preg_match('/^[a-z0-9_]{1,40}\z/', $in['key']) ? $in['key'] : apr_fail('content_invalid');
    $name = apr_clean_name($in['name'] ?? null);
    $desc = apr_clean_text($in['description'] ?? '', 300) ?? '';
    $hidden = (int)(($in['hidden'] ?? false) === true);
    return apr_tx(function () use ($key, $name, $desc, $hidden) {
        $new = !apr_val('SELECT 1 FROM doc_types WHERE key = ?', [$key]);
        apr_exec('INSERT INTO doc_types (key, name, description, hidden, sort) VALUES (?, ?, ?, ?, (SELECT COALESCE(MAX(sort), 0) + 1 FROM doc_types WHERE sort < 99))
            ON CONFLICT(key) DO UPDATE SET name = excluded.name, description = excluded.description, hidden = excluded.hidden', [$key, $name, $desc, $hidden]);
        apr_audit($new ? 'doctype.create' : 'doctype.update', 'doc_type', $key, ['hidden' => (bool)$hidden]);
        return [];
    });
}
