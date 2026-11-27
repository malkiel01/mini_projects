<?php
/**
 * מנוע התוכן (אפיון 02): כותרים, מהדורות, גרסאות, ומחזור הפרסום — טיוטה → פרסום → שחזור.
 *
 *   - **גרסה רק מתווספת.** טיוטה היא גרסה במצב draft, ולכל מהדורה לכל היותר טיוטה פתוחה אחת. פרסום הופך אותה
 *     ל-published ואת הקודמת ל-superseded; שחזור יוצר טיוטה חדשה מתוכן ישן. טיוטה שבוטלה (או שהוחלפה בשחזור) —
 *     discarded, ונשארת. שום דבר לא נמחק (§16) — וטריגר במסד מוודא.
 *   - **עריכה מקבילה** (§16): כל שמירה נושאת את draft_v שהעורך טען. מנהל אחר שמר בינתיים → 409 conflict, בלי דריסה.
 *   - **ציבורי** = public_allowed + גרסה published + access = free. הפעולות read-* (who = reader) לא מחזירות לעולם
 *     טיוטה, כותר כבוי או future_paid — גם כשמנהל קורא להן.
 * כל שינוי והשורה שלו ביומן הפעולות — באותה טרנזקציה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/markup.php';

const SF_TITLE_KINDS = ['siddur', 'tehillim', 'book'];
/** שלב א': רק סידור ותהילים יכולים להיות ציבוריים (§2). */
const SF_PUBLIC_KINDS = ['siddur', 'tehillim'];
const SF_ACCESS = ['free', 'future_paid'];
/** הגודל המרבי של מקור גרסה (בתים). api.php מקבל גוף גדול יותר רק לפעולות התוכן. */
const SF_CONTENT_MAX = 6_000_000;

function sf_str_in(array $in, string $k, int $max, bool $required = false): ?string
{
    $v = $in[$k] ?? null;
    if ($v === null || $v === '') { if ($required) sf_fail('bad_input', "חסר: $k"); return null; }
    if (!is_string($v)) sf_fail('bad_input');
    $v = sf_mk_attr_text($v, $max + 1);
    if (mb_strlen($v) > $max) sf_fail('bad_input', "ארוך מדי: $k");
    if ($required && $v === '') sf_fail('bad_input', "חסר: $k");
    return $v === '' ? null : $v;
}

function sf_title_by_pid(mixed $pid): array
{
    if (!sf_is_pid($pid)) sf_fail('bad_input');
    return sf_row('SELECT * FROM titles WHERE pid = ? AND deleted_at IS NULL', [$pid]) ?? sf_fail('not_found');
}

function sf_edition_by_pid(mixed $pid): array
{
    if (!sf_is_pid($pid)) sf_fail('bad_input');
    return sf_row('SELECT e.*, t.pid AS title_pid FROM editions e JOIN titles t ON t.id = e.title_id WHERE e.pid = ? AND t.deleted_at IS NULL', [$pid]) ?? sf_fail('not_found');
}

function sf_title_out(array $t): array
{
    return ['pid' => $t['pid'], 'name' => $t['name'], 'kind' => $t['kind'], 'author' => $t['author'], 'description' => $t['description'],
        'public_allowed' => (bool)$t['public_allowed'], 'access' => $t['access'], 'can_public' => in_array($t['kind'], SF_PUBLIC_KINDS, true),
        'updated_at' => $t['updated_at']];
}

/** שם המנהל לפי id, לתצוגה בלבד. */
function sf_staff_name(mixed $id): ?string
{
    return $id === null ? null : (sf_val('SELECT name FROM staff WHERE id = ?', [$id]) ?? null);
}

function sf_draft(int $editionId): ?array
{
    return sf_row("SELECT * FROM revisions WHERE edition_id = ? AND status = 'draft'", [$editionId]);
}

function sf_published(int $editionId): ?array
{
    return sf_row("SELECT * FROM revisions WHERE edition_id = ? AND status = 'published'", [$editionId]);
}

function sf_rev_out(array $r, bool $html = false): array
{
    $out = ['pid' => $r['pid'], 'n' => (int)$r['n'], 'status' => $r['status'], 'v' => (int)$r['draft_v'],
        'based_on_n' => $r['based_on_revision_id'] !== null ? (int)sf_val('SELECT n FROM revisions WHERE id = ?', [$r['based_on_revision_id']]) : null,
        'created_at' => $r['created_at'], 'created_by' => sf_staff_name($r['created_by']),
        'updated_at' => $r['updated_at'], 'updated_by' => sf_staff_name($r['updated_by']),
        'published_at' => $r['published_at'], 'published_by' => sf_staff_name($r['published_by'])];
    if ($html) $out['html'] = $r['source_html'];
    return $out;
}

// ── כותרים ────────────────────────────────────────────────────

function sf_content_titles(): array
{
    $titles = sf_rows('SELECT * FROM titles WHERE deleted_at IS NULL ORDER BY name');
    $eds = sf_rows("SELECT e.id, e.pid, e.title_id, e.label,
            (SELECT n FROM revisions r WHERE r.edition_id = e.id AND r.status = 'published') AS pub_n,
            (SELECT n FROM revisions r WHERE r.edition_id = e.id AND r.status = 'draft') AS draft_n
        FROM editions e ORDER BY e.id");
    $by = [];
    foreach ($eds as $e) $by[$e['title_id']][] = ['pid' => $e['pid'], 'label' => $e['label'],
        'published_n' => $e['pub_n'] !== null ? (int)$e['pub_n'] : null, 'draft_n' => $e['draft_n'] !== null ? (int)$e['draft_n'] : null];
    return ['titles' => array_map(fn($t) => sf_title_out($t) + ['editions' => $by[$t['id']] ?? []], $titles),
        'kinds' => SF_TITLE_KINDS, 'public_kinds' => SF_PUBLIC_KINDS];
}

function sf_title_view(array $q): array
{
    $t = sf_title_by_pid($q['title'] ?? null);
    $list = sf_content_titles()['titles'];
    foreach ($list as $x) if ($x['pid'] === $t['pid']) return ['title' => $x];
    sf_fail('not_found');
}

/** יצירה (בלי pid) או עדכון. */
function sf_title_save(array $in): array
{
    $name = sf_str_in($in, 'name', 120, true);
    $kind = $in['kind'] ?? null;
    if (!in_array($kind, SF_TITLE_KINDS, true)) sf_fail('bad_input', 'סוג הכותר אינו תקין');
    $access = $in['access'] ?? 'free';
    if (!in_array($access, SF_ACCESS, true)) sf_fail('bad_input');
    $author = sf_str_in($in, 'author', 120);
    $desc = sf_str_in($in, 'description', 1000);
    return sf_tx(function () use ($in, $name, $kind, $access, $author, $desc) {
        $now = sf_now();
        if (($in['title'] ?? null) === null) {
            $pid = sf_pid();
            sf_insert('titles', ['pid' => $pid, 'name' => $name, 'kind' => $kind, 'author' => $author, 'description' => $desc,
                'public_allowed' => 0, 'access' => $access, 'created_at' => $now, 'updated_at' => $now]);
            sf_audit('title.create', 'title', $pid, ['kind' => $kind, 'access' => $access]);
        } else {
            $t = sf_title_by_pid($in['title']);
            $pid = $t['pid'];
            if ((int)$t['public_allowed'] && (!in_array($kind, SF_PUBLIC_KINDS, true) || $access !== 'free')) {
                sf_fail('state', 'הכותר ציבורי. כדי לשנות את הסוג או את הגישה — קודם מכבים את "ציבורי"');
            }
            sf_exec('UPDATE titles SET name = ?, kind = ?, author = ?, description = ?, access = ?, updated_at = ? WHERE id = ?',
                [$name, $kind, $author, $desc, $access, $now, $t['id']]);
            sf_audit('title.update', 'title', $pid, ['kind' => ['from' => $t['kind'], 'to' => $kind], 'access' => ['from' => $t['access'], 'to' => $access]]);
        }
        return ['title' => sf_title_out(sf_row('SELECT * FROM titles WHERE pid = ?', [$pid]))];
    });
}

/** הדלקה וכיבוי של "ציבורי". רק מנהל, רק סידור / תהילים, ורק גישה חופשית. */
function sf_title_public(array $in): array
{
    $on = $in['on'] ?? null;
    if (!is_bool($on)) sf_fail('bad_input');
    return sf_tx(function () use ($in, $on) {
        $t = sf_title_by_pid($in['title'] ?? null);
        if ((bool)$t['public_allowed'] === $on) sf_fail('state', $on ? 'הכותר כבר ציבורי' : 'הכותר כבר אינו ציבורי');
        if ($on && !in_array($t['kind'], SF_PUBLIC_KINDS, true)) sf_fail('state', "בשלב א' אפשר לפתוח לציבור רק סידור ותהילים");
        if ($on && $t['access'] !== 'free') sf_fail('state', 'כותר בתשלום עתידי אינו מוגש לציבור בשלב א\'');
        sf_exec('UPDATE titles SET public_allowed = ?, updated_at = ? WHERE id = ?', [$on ? 1 : 0, sf_now(), $t['id']]);
        sf_audit($on ? 'title.public_on' : 'title.public_off', 'title', $t['pid'], ['kind' => $t['kind']]);
        return ['title' => sf_title_out(sf_row('SELECT * FROM titles WHERE id = ?', [$t['id']]))];
    });
}

// ── מהדורות ───────────────────────────────────────────────────

function sf_edition_save(array $in): array
{
    $label = sf_str_in($in, 'label', 120, true);
    return sf_tx(function () use ($in, $label) {
        $now = sf_now();
        if (($in['edition'] ?? null) === null) {
            $t = sf_title_by_pid($in['title'] ?? null);
            $pid = sf_pid();
            sf_insert('editions', ['pid' => $pid, 'title_id' => $t['id'], 'label' => $label, 'created_at' => $now, 'updated_at' => $now]);
            sf_audit('edition.create', 'edition', $pid, ['title' => $t['pid']]);
        } else {
            $e = sf_edition_by_pid($in['edition']);
            $pid = $e['pid'];
            sf_exec('UPDATE editions SET label = ?, updated_at = ? WHERE id = ?', [$label, $now, $e['id']]);
            sf_audit('edition.update', 'edition', $pid);
        }
        return ['edition' => ['pid' => $pid, 'label' => $label]];
    });
}

/** ההקשר של הניקוי למהדורה: הכללים, המזהים שהיו בה, ובדיקת קישור למהדורה אחרת. */
function sf_mk_ctx(int $editionId): array
{
    $reserved = [];
    foreach (sf_rows('SELECT DISTINCT s.sid FROM segments s JOIN revisions r ON r.id = s.revision_id WHERE r.edition_id = ?', [$editionId]) as $r) $reserved[$r['sid']] = true;
    return [
        'customs' => array_column(sf_rows('SELECT rule, grp FROM customs'), 'grp', 'rule'),
        'reserved' => $reserved,
        'link' => fn(string $ep, string $sid) => sf_val("SELECT 1 FROM segments s JOIN revisions r ON r.id = s.revision_id JOIN editions e ON e.id = r.edition_id
            WHERE e.pid = ? AND r.status = 'published' AND s.sid = ?", [$ep, $sid]) !== null,
    ];
}

/** מסך העורך: המהדורה, הטיוטה (אם יש), הגרסה שפורסמה, ההיסטוריה, והרשימות לעורך. */
function sf_edition_view(array $q): array
{
    $e = sf_edition_by_pid($q['edition'] ?? null);
    $t = sf_row('SELECT * FROM titles WHERE id = ?', [$e['title_id']]);
    $draft = sf_draft((int)$e['id']);
    $pub = sf_published((int)$e['id']);
    $out = [
        'title' => sf_title_out($t),
        'edition' => ['pid' => $e['pid'], 'label' => $e['label']],
        'draft' => $draft ? sf_rev_out($draft, true) : null,
        'published' => $pub ? sf_rev_out($pub, true) : null,
        'revisions' => array_map(fn($r) => sf_rev_out($r), sf_rows('SELECT * FROM revisions WHERE edition_id = ? ORDER BY n DESC', [$e['id']])),
        'customs' => sf_rows('SELECT rule, name, grp, sample FROM customs WHERE available = 1 ORDER BY grp, rule'),
        'styles' => SF_MK_STYLES,
    ];
    if ($draft) {
        $chk = sf_mk_clean($draft['source_html'], sf_mk_ctx((int)$e['id']));
        $out['draft']['warnings'] = $chk['warnings'];
    }
    return $out;
}

/** גרסה אחת, בכל מצב — למנהל בלבד (תצוגה מקדימה של היסטוריה). */
function sf_revision_view(array $q): array
{
    $pid = $q['revision'] ?? null;
    if (!sf_is_pid($pid)) sf_fail('bad_input');
    $r = sf_row('SELECT * FROM revisions WHERE pid = ?', [$pid]) ?? sf_fail('not_found');
    return ['revision' => sf_rev_out($r, true)];
}

function sf_html_in(array $in): string
{
    $html = $in['html'] ?? null;
    if (!is_string($html)) sf_fail('bad_input');
    if (strlen($html) > SF_CONTENT_MAX) sf_fail('too_large', 'התוכן גדול מדי לגרסה אחת');
    return $html;
}

/** בדיקה בלי שמירה: מה היה נשמר, ומה היה נמצא. (מעבר מ-HTML לעורך החזותי, ותצוגה מקדימה של שינויים שלא נשמרו.) */
function sf_content_check(array $in): array
{
    $e = sf_edition_by_pid($in['edition'] ?? null);
    $r = sf_mk_clean(sf_html_in($in), sf_mk_ctx((int)$e['id']));
    return ['html' => $r['html'], 'errors' => $r['errors'], 'warnings' => $r['warnings']];
}

function sf_conflict(array $draft): never
{
    $who = sf_staff_name($draft['updated_by']) ?? 'מנהל אחר';
    sf_fail('conflict', "$who שינה את הטיוטה בינתיים — לטעון את השינויים?", ['by' => $who, 'at' => $draft['updated_at'], 'v' => (int)$draft['draft_v'], 'n' => (int)$draft['n']]);
}

/**
 * שמירת טיוטה. $in: edition, html, base (draft_v שהעורך טען; 0 = אין טיוטה), from (n של הגרסה שפורסמה כשנפתח העורך; 0 = אין).
 * שגיאת מבנה → 422 invalid, עם הרשימה ב-details. התנגשות → 409 conflict.
 */
function sf_content_save(array $in, int $me): array
{
    $html = sf_html_in($in);
    $base = $in['base'] ?? null;
    $from = $in['from'] ?? 0;
    if (!is_int($base) || $base < 0 || !is_int($from) || $from < 0) sf_fail('bad_input');
    return sf_tx(function () use ($in, $html, $base, $from, $me) {
        $e = sf_edition_by_pid($in['edition'] ?? null);
        $eid = (int)$e['id'];
        $draft = sf_draft($eid);
        $pub = sf_published($eid);
        if ($draft) {
            if ($base !== (int)$draft['draft_v']) sf_conflict($draft);
        } else {
            if ($base !== 0) sf_fail('conflict', 'הטיוטה פורסמה או בוטלה בינתיים — לטעון את המצב החדש?', ['n' => null]);
            if ($from !== ($pub ? (int)$pub['n'] : 0)) sf_fail('conflict', 'מנהל אחר פרסם גרסה חדשה בינתיים — לטעון אותה?', ['n' => $pub ? (int)$pub['n'] : null]);
        }
        $r = sf_mk_clean($html, sf_mk_ctx($eid));
        if ($r['errors']) sf_fail('invalid', null, ['errors' => $r['errors']]);
        $now = sf_now();
        $hash = hash('sha256', $r['html']);
        if ($draft) {
            $n = sf_exec("UPDATE revisions SET source_html = ?, source_hash = ?, draft_v = draft_v + 1, updated_by = ?, updated_at = ?
                WHERE id = ? AND status = 'draft' AND draft_v = ?", [$r['html'], $hash, $me, $now, $draft['id'], $base]);
            if ($n !== 1) sf_conflict(sf_draft($eid) ?? $draft);
            $id = (int)$draft['id'];
        } else {
            $id = sf_rev_insert($eid, $pub ? (int)$pub['id'] : null, $r['html'], $me);
            sf_audit('content.draft', 'revision', (string)sf_val('SELECT pid FROM revisions WHERE id = ?', [$id]), ['edition' => $e['pid'], 'from_n' => $pub ? (int)$pub['n'] : null]);
        }
        return ['draft' => sf_rev_out(sf_row('SELECT * FROM revisions WHERE id = ?', [$id]), true), 'warnings' => $r['warnings']];
    });
}

/** גרסה חדשה במצב draft, עם המספר הבא במהדורה. */
function sf_rev_insert(int $eid, ?int $basedOn, string $html, int $me): int
{
    $now = sf_now();
    $n = (int)sf_val('SELECT COALESCE(MAX(n), 0) FROM revisions WHERE edition_id = ?', [$eid]) + 1;
    return sf_insert('revisions', ['pid' => sf_pid(), 'edition_id' => $eid, 'n' => $n, 'based_on_revision_id' => $basedOn, 'status' => 'draft',
        'source_html' => $html, 'source_hash' => hash('sha256', $html), 'draft_v' => 1, 'created_by' => $me, 'created_at' => $now,
        'updated_by' => $me, 'updated_at' => $now]);
}

/** פרסום הטיוטה. $in: edition, base (draft_v — מפרסמים בדיוק את מה שנראה). חוסם: שגיאה, או אזהרה שחוסמת פרסום. */
function sf_content_publish(array $in, int $me): array
{
    $base = $in['base'] ?? null;
    if (!is_int($base)) sf_fail('bad_input');
    return sf_tx(function () use ($in, $base, $me) {
        $e = sf_edition_by_pid($in['edition'] ?? null);
        $eid = (int)$e['id'];
        $draft = sf_draft($eid) ?? sf_fail('state', 'אין טיוטה לפרסום');
        if ($base !== (int)$draft['draft_v']) sf_conflict($draft);
        $r = sf_mk_clean($draft['source_html'], sf_mk_ctx($eid));
        $blocking = array_values(array_filter($r['warnings'], fn($w) => $w['blocks']));
        if ($r['errors'] || $blocking) sf_fail('invalid', 'אי אפשר לפרסם: יש בעיות שצריך לתקן קודם', ['errors' => $r['errors'], 'warnings' => $blocking]);
        if ($r['html'] !== $draft['source_html']) {   // לא אמור לקרות (הניקוי אידמפוטנטי) — אבל מפרסמים רק HTML נקי
            sf_exec("UPDATE revisions SET source_html = ?, source_hash = ? WHERE id = ? AND status = 'draft'", [$r['html'], hash('sha256', $r['html']), $draft['id']]);
        }
        $prev = sf_published($eid);
        $now = sf_now();
        if ($prev) sf_exec("UPDATE revisions SET status = 'superseded' WHERE id = ? AND status = 'published'", [$prev['id']]);
        sf_exec("UPDATE revisions SET status = 'published', published_by = ?, published_at = ? WHERE id = ? AND status = 'draft'", [$me, $now, $draft['id']]);
        $segs = sf_mk_segments($r['html']);
        foreach ($segs as $s) sf_insert('segments', ['revision_id' => $draft['id']] + $s);
        sf_audit('content.publish', 'revision', $draft['pid'], ['edition' => $e['pid'], 'n' => (int)$draft['n'], 'prev_n' => $prev ? (int)$prev['n'] : null, 'segments' => count($segs)]);
        return ['published' => sf_rev_out(sf_row('SELECT * FROM revisions WHERE id = ?', [$draft['id']])), 'segments' => count($segs)];
    });
}

/**
 * שחזור: טיוטה חדשה מתוכן של גרסה קודמת (§16 — לא מוחק היסטוריה). $in: revision, base (draft_v של הטיוטה הפתוחה; 0 = אין).
 * טיוטה פתוחה — עוברת ל-discarded (נשמרת, ואפשר לשחזר גם אותה).
 */
function sf_content_restore(array $in, int $me): array
{
    $base = $in['base'] ?? null;
    if (!is_int($base)) sf_fail('bad_input');
    $pid = $in['revision'] ?? null;
    if (!sf_is_pid($pid)) sf_fail('bad_input');
    return sf_tx(function () use ($pid, $base, $me) {
        $src = sf_row('SELECT * FROM revisions WHERE pid = ?', [$pid]) ?? sf_fail('not_found');
        if ($src['status'] === 'draft') sf_fail('state', 'זו הטיוטה הפתוחה — אין מה לשחזר');
        $eid = (int)$src['edition_id'];
        $draft = sf_draft($eid);
        if ($draft && $base !== (int)$draft['draft_v']) sf_conflict($draft);
        if (!$draft && $base !== 0) sf_fail('conflict', 'המצב השתנה בינתיים — לטעון אותו?');
        if ($draft) sf_exec("UPDATE revisions SET status = 'discarded', updated_by = ?, updated_at = ? WHERE id = ? AND status = 'draft'", [$me, sf_now(), $draft['id']]);
        $id = sf_rev_insert($eid, (int)$src['id'], $src['source_html'], $me);
        $new = sf_row('SELECT * FROM revisions WHERE id = ?', [$id]);
        sf_audit('content.restore', 'revision', $new['pid'], ['edition' => sf_val('SELECT pid FROM editions WHERE id = ?', [$eid]),
            'from_n' => (int)$src['n'], 'n' => (int)$new['n'], 'discarded_n' => $draft ? (int)$draft['n'] : null]);
        return ['draft' => sf_rev_out($new, true)];
    });
}

/** ביטול הטיוטה הפתוחה (נשמרת כ-discarded). $in: edition, base. */
function sf_content_discard(array $in, int $me): array
{
    $base = $in['base'] ?? null;
    if (!is_int($base)) sf_fail('bad_input');
    return sf_tx(function () use ($in, $base, $me) {
        $e = sf_edition_by_pid($in['edition'] ?? null);
        $draft = sf_draft((int)$e['id']) ?? sf_fail('state', 'אין טיוטה פתוחה');
        if ($base !== (int)$draft['draft_v']) sf_conflict($draft);
        sf_exec("UPDATE revisions SET status = 'discarded', updated_by = ?, updated_at = ? WHERE id = ? AND status = 'draft'", [$me, sf_now(), $draft['id']]);
        sf_audit('content.discard', 'revision', $draft['pid'], ['edition' => $e['pid'], 'n' => (int)$draft['n']]);
        return [];
    });
}

// ── מילון החיפוש (§8, 02 §9) ──────────────────────────────────

function sf_aliases(): array
{
    return ['aliases' => sf_rows('SELECT term, alias, created_at FROM search_aliases ORDER BY term, alias')];
}

/** term ו-alias נשמרים מנורמלים — כמו text_norm. */
function sf_alias_add(array $in, int $me): array
{
    $term = sf_norm((string)sf_str_in($in, 'term', 60, true));
    $alias = sf_norm((string)sf_str_in($in, 'alias', 60, true));
    if ($term === '' || $alias === '' || $term === $alias) sf_fail('bad_input', 'צריך שתי צורות שונות');
    return sf_tx(function () use ($term, $alias, $me) {
        if (sf_val('SELECT 1 FROM search_aliases WHERE term = ? AND alias = ?', [$term, $alias])) sf_fail('state', 'הצמד כבר קיים');
        sf_insert('search_aliases', ['term' => $term, 'alias' => $alias, 'created_by' => $me, 'created_at' => sf_now()]);
        sf_audit('alias.add', 'alias', null, ['term' => $term, 'alias' => $alias]);
        return sf_aliases();
    });
}

function sf_alias_remove(array $in): array
{
    $term = is_string($in['term'] ?? null) ? $in['term'] : '';
    $alias = is_string($in['alias'] ?? null) ? $in['alias'] : '';
    return sf_tx(function () use ($term, $alias) {
        if (sf_exec('DELETE FROM search_aliases WHERE term = ? AND alias = ?', [$term, $alias]) !== 1) sf_fail('not_found');
        sf_audit('alias.remove', 'alias', null, ['term' => $term, 'alias' => $alias]);
        return sf_aliases();
    });
}

// ── הקריאה הציבורית (who = reader) ────────────────────────────
// רק: כותר לא מחוק + public_allowed + access = free + סוג שמותר לציבור + גרסה published. כל השאר — כאילו לא קיים.

const SF_READER_WHERE = "t.deleted_at IS NULL AND t.public_allowed = 1 AND t.access = 'free' AND t.kind IN ('siddur', 'tehillim') AND r.status = 'published'";

function sf_read_titles(): array
{
    $rows = sf_rows('SELECT t.pid AS t_pid, t.name, t.kind, t.author, t.description, e.pid AS e_pid, e.label, r.n, r.published_at
        FROM titles t JOIN editions e ON e.title_id = t.id JOIN revisions r ON r.edition_id = e.id
        WHERE ' . SF_READER_WHERE . ' ORDER BY t.name, e.id');
    $out = [];
    foreach ($rows as $r) {
        $out[$r['t_pid']] ??= ['pid' => $r['t_pid'], 'name' => $r['name'], 'kind' => $r['kind'], 'author' => $r['author'], 'description' => $r['description'], 'editions' => []];
        $out[$r['t_pid']]['editions'][] = ['pid' => $r['e_pid'], 'label' => $r['label'], 'n' => (int)$r['n'], 'published_at' => $r['published_at']];
    }
    return ['titles' => array_values($out)];
}

function sf_read_edition(array $q): array
{
    $pid = $q['edition'] ?? null;
    if (!sf_is_pid($pid)) sf_fail('bad_input');
    $r = sf_row('SELECT t.pid AS t_pid, t.name, t.kind, e.pid AS e_pid, e.label, r.id, r.pid, r.n, r.published_at, r.source_html
        FROM titles t JOIN editions e ON e.title_id = t.id JOIN revisions r ON r.edition_id = e.id
        WHERE e.pid = ? AND ' . SF_READER_WHERE, [$pid]) ?? sf_fail('not_found');
    return [
        'title' => ['pid' => $r['t_pid'], 'name' => $r['name'], 'kind' => $r['kind']],
        'edition' => ['pid' => $r['e_pid'], 'label' => $r['label']],
        'revision' => ['pid' => $r['pid'], 'n' => (int)$r['n'], 'published_at' => $r['published_at']],
        'html' => $r['source_html'],
        'toc' => sf_rows("SELECT sid, kind, parent_sid, title FROM segments WHERE revision_id = ? AND kind IN ('section', 'anchor') ORDER BY ord", [$r['id']]),
    ];
}
