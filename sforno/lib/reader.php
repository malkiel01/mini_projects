<?php
/**
 * הקורא, הסידור והתהילים (אפיון 04) — צד השרת.
 *
 *   הקריאה (who = reader, בלי עוגייה ובלי מידע אישי): read-catalog · read-toc · read-revision · read-search · read-file ·
 *   read-pages. מחזירות **רק** תוכן ציבורי: כותר לא מחוק + public_allowed + access = free + סידור / תהילים, וגרסה
 *   published (או superseded כשמבקשים אותה במפורש — מיקום שנשמר על גרסה קודמת). טקסט שחולץ מ-PDF ולא סומן "נבדק"
 *   (imports.text_checked) — לא מוגש כטקסט ולא נמצא בחיפוש; העמודים שלו כן.
 *   כל המידע האישי של הקורא (מיקום, סימניות, הדגשות, הערות, מנהגים, שמות לתפילה) — במכשיר בלבד (IndexedDB), ולא מגיע לכאן.
 *
 *   הניהול (staff): המנהגים (customs), רשימת הבסיס של הסידור (siddur_items), בדיקת שילובי מנהגים (custom_checks),
 *   עמודים לקוראים (editions.pages_import_id), ותוכן דוגמה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/markup.php';
require_once __DIR__ . '/content.php';

/** כותר שמותר לקוראים (בלי תנאי על הגרסה). */
const SF_READER_TITLE = "t.deleted_at IS NULL AND t.public_allowed = 1 AND t.access = 'free' AND t.kind IN ('siddur', 'tehillim')";
const SF_SEARCH_PAGE = 20;
const SF_SEARCH_SCAN = 4000;
const SF_SEARCH_VARIANTS = 16;
/** תחיליות שמתחברות למילה (ו, ה, ב, כ, ל, מ, ש) — עד שלוש: "ובשמע" = ו+ב+שמע. */
const SF_SEARCH_PREFIX = '/^[ובכלמשה]{1,3}$/u';
const SF_PAGES_KINDS = ['pdf', 'pdf_scan', 'image'];
const SF_SIDDUR_KINDS = ['prayer', 'shortcut'];
const SF_CUSTOM_RULE = '/^[a-z][a-z0-9_-]{0,39}\z/';
const SF_CUSTOM_GRP = '/^[a-z][a-z0-9_-]{0,39}\z/';

// ── עזרים ────────────────────────────────────────────────────

/**
 * הטקסט של הגרסה ראוי לקוראים? לא — אם הגרסה (או גרסה שהיא נשענת עליה) נוצרה מייבוא PDF שהטקסט שלו לא סומן "נבדק".
 */
function sf_read_text_ok(int $revisionId): bool
{
    $id = $revisionId;
    for ($i = 0; $id && $i < 200; $i++) {
        $im = sf_row("SELECT kind, text_checked FROM imports WHERE revision_id = ? AND kind = 'pdf' ORDER BY id DESC LIMIT 1", [$id]);
        if ($im) return (bool)$im['text_checked'];
        $id = (int)sf_val('SELECT based_on_revision_id FROM revisions WHERE id = ?', [$id]);
    }
    return true;
}

/** העמודים של המהדורה לקוראים: {kind, pages, src} או null. */
function sf_read_pages_of(array $e): ?array
{
    if (empty($e['pages_import_id'])) return null;
    $im = sf_row('SELECT * FROM imports WHERE id = ? AND edition_id = ?', [$e['pages_import_id'], $e['id']]);
    if (!$im || !in_array($im['kind'], SF_PAGES_KINDS, true) || !in_array($im['status'], ['ready', 'applied'], true)) return null;
    $out = ['import' => $im['pid'], 'kind' => $im['kind'] === 'image' ? 'image' : 'pdf', 'pages' => $im['pages'] !== null ? (int)$im['pages'] : null, 'src' => null];
    if ($im['kind'] === 'image') {
        $g = glob(sf_imp_dir($im['pid']) . '/files/page-1.*');
        $out['src'] = $g ? "files/{$im['pid']}/" . basename($g[0]) : null;
    }
    return $out;
}

/** מהדורה ציבורית לפי pid (בלי תנאי על הגרסה) — או 404. */
function sf_read_edition_row(mixed $pid): array
{
    if (!sf_is_pid($pid)) sf_fail('bad_input');
    return sf_row('SELECT e.*, t.pid AS t_pid, t.name AS t_name, t.kind AS t_kind FROM editions e JOIN titles t ON t.id = e.title_id
        WHERE e.pid = ? AND ' . SF_READER_TITLE, [$pid]) ?? sf_fail('not_found');
}

function sf_read_toc_of(int $revisionId): array
{
    return sf_rows("SELECT sid, kind, parent_sid, title FROM segments WHERE revision_id = ? AND kind IN ('section', 'anchor') ORDER BY ord", [$revisionId]);
}

// ── הקריאה ───────────────────────────────────────────────────

/** הקטלוג: הכותרים והמהדורות שאפשר לקרוא, המנהגים (לבחירה), ורשימת הבסיס של כל סידור. */
function sf_read_catalog(): array
{
    $rows = sf_rows('SELECT t.id AS t_id, t.pid AS t_pid, t.name, t.kind, t.author, t.description, e.id AS e_id, e.pid AS e_pid, e.label, e.pages_import_id,
            (SELECT r.id FROM revisions r WHERE r.edition_id = e.id AND r.status = \'published\') AS rev_id
        FROM titles t JOIN editions e ON e.title_id = t.id WHERE ' . SF_READER_TITLE . ' ORDER BY t.name, e.id');
    $titles = [];
    $eds = [];
    foreach ($rows as $r) {
        $rev = $r['rev_id'] !== null ? sf_row('SELECT pid, n, published_at FROM revisions WHERE id = ?', [$r['rev_id']]) : null;
        $text = $rev && sf_read_text_ok((int)$r['rev_id']);
        $pages = sf_read_pages_of(['id' => $r['e_id'], 'pages_import_id' => $r['pages_import_id']]);
        if (!$text && !$pages) continue;
        $titles[$r['t_pid']] ??= ['pid' => $r['t_pid'], 'name' => $r['name'], 'kind' => $r['kind'], 'author' => $r['author'], 'description' => $r['description'], 'editions' => []];
        $ed = ['pid' => $r['e_pid'], 'label' => $r['label'], 'text' => $text, 'pages' => (bool)$pages,
            'revision' => $text ? ['pid' => $rev['pid'], 'n' => (int)$rev['n'], 'published_at' => $rev['published_at']] : null];
        if ($r['kind'] === 'siddur') {
            $ed['items'] = array_map(fn($i) => ['pid' => $i['pid'], 'name' => $i['name'], 'sid' => $i['sid'], 'kind' => $i['kind']],
                sf_rows('SELECT pid, name, sid, kind FROM siddur_items WHERE edition_id = ? AND available = 1 ORDER BY ord, id', [$r['e_id']]));
        }
        $titles[$r['t_pid']]['editions'][] = $ed;
    }
    return ['titles' => array_values($titles), 'customs' => sf_customs_groups(true)];
}

/** תוכן העניינים של הגרסה שפורסמה. */
function sf_read_toc(array $q): array
{
    $e = sf_read_edition_row($q['edition'] ?? null);
    $rev = sf_published((int)$e['id']) ?? sf_fail('not_found');
    if (!sf_read_text_ok((int)$rev['id'])) sf_fail('not_found');
    return ['revision' => $rev['pid'], 'toc' => sf_read_toc_of((int)$rev['id'])];
}

/**
 * ה-source_html של הגרסה: זו שפורסמה, או — כשמבקשים revision — גרסה שהייתה ציבורית (published / superseded) באותה מהדורה.
 * בלי טקסט ראוי (PDF שלא נבדק) — html = null, והעמודים (אם יש) ב-pages.
 */
function sf_read_revision(array $q): array
{
    $e = sf_read_edition_row($q['edition'] ?? null);
    $want = $q['revision'] ?? null;
    if ($want !== null && $want !== '') {
        if (!sf_is_pid($want)) sf_fail('bad_input');
        $rev = sf_row("SELECT * FROM revisions WHERE pid = ? AND edition_id = ? AND status IN ('published', 'superseded')", [$want, $e['id']]);
    } else {
        $rev = sf_published((int)$e['id']);
    }
    $pages = sf_read_pages_of($e);
    $ok = $rev && sf_read_text_ok((int)$rev['id']);
    if (!$ok && !$pages) sf_fail('not_found');
    $cur = sf_published((int)$e['id']);
    return [
        'title' => ['pid' => $e['t_pid'], 'name' => $e['t_name'], 'kind' => $e['t_kind']],
        'edition' => ['pid' => $e['pid'], 'label' => $e['label']],
        'revision' => $ok ? ['pid' => $rev['pid'], 'n' => (int)$rev['n'], 'published_at' => $rev['published_at'], 'current' => $cur && (int)$cur['id'] === (int)$rev['id']] : null,
        'html' => $ok ? $rev['source_html'] : null,
        'toc' => $ok ? sf_read_toc_of((int)$rev['id']) : [],
        'pages' => $pages,
    ];
}

/** תמונה מתוך תוכן שפורסם (files/<pid>/N.ext), או עמוד-תמונה של מהדורה ציבורית. כל השאר — 404, כאילו לא קיים. */
function sf_read_file_path(array $q): array
{
    $p = (string)($q['path'] ?? '');
    [$f, $mime] = sf_content_file_path(['path' => $p]);
    preg_match('~^files/([a-z0-9]{16})/~', $p, $m);
    $like = '%src="' . $p . '"%';   // $p עבר את הבדיקה של sf_content_file_path: רק [a-z0-9/.-] — אין % ואין _
    $pub = sf_rows("SELECT r.id FROM revisions r JOIN editions e ON e.id = r.edition_id JOIN titles t ON t.id = e.title_id
        WHERE r.status = 'published' AND " . SF_READER_TITLE . ' AND r.source_html LIKE ?', [$like]);
    foreach ($pub as $r) if (sf_read_text_ok((int)$r['id'])) return [$f, $mime];
    $pg = sf_val("SELECT 1 FROM imports i JOIN editions e ON e.pages_import_id = i.id JOIN titles t ON t.id = e.title_id
        WHERE i.pid = ? AND i.kind = 'image' AND " . SF_READER_TITLE, [$m[1]]);
    if ($pg) return [$f, $mime];
    sf_fail('not_found');
}

/** המקור של העמודים (PDF) של מהדורה ציבורית — לתצוגת "עמודים" בקורא. */
function sf_read_pages_path(array $q): array
{
    $e = sf_read_edition_row($q['edition'] ?? null);
    $pg = sf_read_pages_of($e) ?? sf_fail('not_found');
    if ($pg['kind'] !== 'pdf') sf_fail('not_found');
    return sf_import_source_path(['import' => $pg['import']]);
}

// ── החיפוש (§4) ──────────────────────────────────────────────

/**
 * הצורות של השאילתה: המקור, ועוד כל צורה שמתקבלת מהחלפה לפי המילון (שני הכיוונים, מילים שלמות). עד 16.
 * כל צורה — רשימת מילים מנורמלות.
 */
function sf_search_variants(string $q): array
{
    $base = sf_norm($q);
    if ($base === '') return [];
    $pairs = [];
    foreach (sf_rows('SELECT term, alias FROM search_aliases') as $a) { $pairs[] = [$a['term'], $a['alias']]; $pairs[] = [$a['alias'], $a['term']]; }
    $set = [$base => true];
    $queue = [$base];
    while ($queue && count($set) < SF_SEARCH_VARIANTS) {
        $v = array_shift($queue);
        foreach ($pairs as [$from, $to]) {
            $re = '/(?<=^| )' . preg_quote($from, '/') . '(?= |$)/u';
            if (!preg_match($re, $v)) continue;
            $nv = (string)preg_replace($re, $to, $v);
            if (!isset($set[$nv])) { $set[$nv] = true; $queue[] = $nv; if (count($set) >= SF_SEARCH_VARIANTS) break; }
        }
    }
    return array_map(fn($v) => explode(' ', $v), array_keys($set));
}

/** מילה בשאילתה ↔ מילה בטקסט: זהה, או עם תחיליות (ו, ה, ב, כ, ל, מ, ש). בלי סיומות — "רשע" אינו "רשעים". */
function sf_search_word_hit(string $w, string $tok): bool
{
    if ($tok === $w) return true;
    $n = mb_strlen($tok) - mb_strlen($w);
    return $n >= 1 && $n <= 3 && str_ends_with($tok, $w) && preg_match(SF_SEARCH_PREFIX, mb_substr($tok, 0, $n)) === 1;
}

/** הטקסט מכיל את כל המילים של הצורה? מחזיר את המילים שנמצאו (בטקסט), או null. */
function sf_search_match(string $text, array $words): ?array
{
    $toks = preg_split('/[^\p{L}\p{N}\']+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $hit = [];
    foreach ($words as $w) {
        $found = null;
        foreach ($toks as $t) if (sf_search_word_hit($w, $t)) { $found = $t; break; }
        if ($found === null) return null;
        $hit[] = $found;
    }
    return $hit;
}

/** תקציר סביב ההתאמה הראשונה. */
function sf_search_snippet(string $text, string $tok): string
{
    $pos = mb_strpos(' ' . $text . ' ', ' ' . $tok . ' ');
    $pos = $pos === false ? 0 : $pos;
    $from = max(0, $pos - 50);
    $s = mb_substr($text, $from, 140);
    return ($from > 0 ? '…' : '') . trim($s) . ($from + 140 < mb_strlen($text) ? '…' : '');
}

/**
 * read-search: q, edition (לא חובה), offset. רק בלוקים של גרסאות שפורסמו בכותרים ציבוריים, עם טקסט שנבדק.
 * בהדרגה: 20 בכל פעם, more = יש עוד.
 */
function sf_read_search(array $q): array
{
    $query = is_string($q['q'] ?? null) ? mb_substr($q['q'], 0, 100) : '';
    $offset = max(0, min(10_000, (int)($q['offset'] ?? 0)));
    $variants = sf_search_variants($query);
    if (!$variants) return ['results' => [], 'more' => false, 'words' => []];
    $args = [];
    $or = [];
    foreach ($variants as $words) {
        $and = [];
        foreach ($words as $w) { $and[] = "s.text_norm LIKE ? ESCAPE '\\'"; $args[] = '%' . addcslashes($w, '%_\\') . '%'; }
        $or[] = '(' . implode(' AND ', $and) . ')';
    }
    $where = SF_READER_TITLE . " AND r.status = 'published' AND s.kind = 'block' AND (" . implode(' OR ', $or) . ')';
    if (($q['edition'] ?? '') !== '') {
        if (!sf_is_pid($q['edition'])) sf_fail('bad_input');
        $where .= ' AND e.pid = ?';
        $args[] = $q['edition'];
    }
    $rows = sf_rows('SELECT s.sid, s.parent_sid, s.text_norm, r.id AS rev_id, e.pid AS e_pid, e.label, t.name, t.kind
        FROM segments s JOIN revisions r ON r.id = s.revision_id JOIN editions e ON e.id = r.edition_id JOIN titles t ON t.id = e.title_id
        WHERE ' . $where . ' ORDER BY t.name, e.id, s.ord LIMIT ' . SF_SEARCH_SCAN, $args);
    $ok = [];
    $out = [];
    $n = 0;
    $more = false;
    $words = [];
    foreach ($rows as $r) {
        $ok[$r['rev_id']] ??= sf_read_text_ok((int)$r['rev_id']);
        if (!$ok[$r['rev_id']]) continue;
        $hit = null;
        foreach ($variants as $v) if ($hit = sf_search_match($r['text_norm'], $v)) break;
        if (!$hit) continue;
        if ($n++ < $offset) continue;
        if (count($out) >= SF_SEARCH_PAGE) { $more = true; break; }
        foreach ($hit as $w) $words[$w] = true;
        $sec = $r['parent_sid'] !== null ? sf_val('SELECT title FROM segments WHERE revision_id = ? AND sid = ?', [$r['rev_id'], $r['parent_sid']]) : null;
        $out[] = ['edition' => $r['e_pid'], 'title' => $r['name'], 'label' => $r['label'], 'kind' => $r['kind'], 'sid' => $r['sid'],
            'section' => $sec, 'snippet' => sf_search_snippet($r['text_norm'], $hit[0]), 'words' => $hit];
    }
    return ['results' => $out, 'more' => $more, 'offset' => $offset, 'words' => array_keys($words),
        'variants' => array_map(fn($v) => implode(' ', $v), $variants)];
}

// ── המנהגים (§2) ─────────────────────────────────────────────

/** הקבוצות: [{grp, customs: [{rule, name, default_on, available, sample}]}]. $public — רק הזמינים, בלי sample. */
function sf_customs_groups(bool $public = false): array
{
    $rows = sf_rows('SELECT rule, name, grp, default_on, available, sample FROM customs' . ($public ? ' WHERE available = 1' : '') . ' ORDER BY grp, id');
    $g = [];
    foreach ($rows as $r) {
        $c = ['rule' => $r['rule'], 'name' => $r['name'], 'default_on' => (bool)$r['default_on']];
        if (!$public) $c += ['available' => (bool)$r['available'], 'sample' => (bool)$r['sample']];
        $g[$r['grp']][] = $c;
    }
    return array_map(fn($k, $v) => ['grp' => $k, 'customs' => $v], array_keys($g), array_values($g));
}

function sf_customs_list(): array
{
    return ['groups' => sf_customs_groups()];
}

/**
 * יצירה (rule חדש) או עדכון. rule לא משתנה אחרי שנוצר (הסימון בתוכן מצביע עליו), וכך גם הקבוצה. ברירת מחדל — אחת לכל
 * קבוצה: הדלקה מכבה את האחרות. אין מחיקה: "לא זמין" (available = 0) מוריד אותו מהבחירה של הקורא.
 */
function sf_custom_save(array $in): array
{
    $rule = $in['rule'] ?? null;
    if (!is_string($rule) || !preg_match(SF_CUSTOM_RULE, $rule)) sf_fail('bad_input', 'מזהה: אותיות לטיניות קטנות, ספרות, - ו-_ (עד 40)');
    $name = sf_str_in($in, 'name', 60, true);
    $def = (bool)($in['default_on'] ?? false);
    $avail = (bool)($in['available'] ?? true);
    return sf_tx(function () use ($in, $rule, $name, $def, $avail) {
        $c = sf_row('SELECT * FROM customs WHERE rule = ?', [$rule]);
        if (!empty($in['create'])) {
            if ($c) sf_fail('state', 'המזהה כבר קיים');
            $grp = $in['grp'] ?? null;
            if (!is_string($grp) || !preg_match(SF_CUSTOM_GRP, $grp)) sf_fail('bad_input', 'קבוצה: אותיות לטיניות קטנות, ספרות, - ו-_ (עד 40)');
            sf_insert('customs', ['rule' => $rule, 'name' => $name, 'grp' => $grp, 'default_on' => 0, 'available' => $avail ? 1 : 0, 'sample' => 0, 'created_at' => sf_now()]);
            $c = sf_row('SELECT * FROM customs WHERE rule = ?', [$rule]);
            sf_audit('custom.create', 'custom', null, ['rule' => $rule, 'grp' => $grp]);
        } else {
            if (!$c) sf_fail('not_found');
            sf_exec('UPDATE customs SET name = ?, available = ? WHERE id = ?', [$name, $avail ? 1 : 0, $c['id']]);
            sf_audit('custom.update', 'custom', null, ['rule' => $rule, 'available' => $avail]);
        }
        if ($def && $avail) {
            sf_exec('UPDATE customs SET default_on = 0 WHERE grp = ? AND id <> ?', [$c['grp'], $c['id']]);
            sf_exec('UPDATE customs SET default_on = 1 WHERE id = ?', [$c['id']]);
        } elseif ((int)$c['default_on'] && (!$def || !$avail)) {
            sf_exec('UPDATE customs SET default_on = 0 WHERE id = ?', [$c['id']]);
        }
        return sf_customs_list();
    });
}

// ── רשימת הבסיס של הסידור (§2) ───────────────────────────────

function sf_siddur_edition(mixed $pid): array
{
    $e = sf_edition_by_pid($pid);
    $kind = sf_val('SELECT kind FROM titles WHERE id = ?', [$e['title_id']]);
    if ($kind !== 'siddur') sf_fail('state', 'רשימת תפילות — רק לסידור');
    return $e;
}

function sf_siddur_items(array $q): array
{
    $e = sf_siddur_edition($q['edition'] ?? null);
    $pub = sf_published((int)$e['id']);
    return [
        'edition' => ['pid' => $e['pid'], 'label' => $e['label']],
        'items' => array_map(fn($i) => ['pid' => $i['pid'], 'name' => $i['name'], 'sid' => $i['sid'], 'kind' => $i['kind'], 'available' => (bool)$i['available']],
            sf_rows('SELECT * FROM siddur_items WHERE edition_id = ? ORDER BY ord, id', [$e['id']])),
        'toc' => $pub ? sf_read_toc_of((int)$pub['id']) : [],
    ];
}

/** יצירה (בלי item) או עדכון: name, kind, sid (יעד בגרסה שפורסמה, או ריק = תחילת המהדורה). */
function sf_siddur_item_save(array $in): array
{
    $name = sf_str_in($in, 'name', 80, true);
    $kind = $in['kind'] ?? 'prayer';
    if (!in_array($kind, SF_SIDDUR_KINDS, true)) sf_fail('bad_input');
    $sid = $in['sid'] ?? null;
    if ($sid === '') $sid = null;
    if ($sid !== null && !sf_mk_is_sid($sid)) sf_fail('bad_input');
    return sf_tx(function () use ($in, $name, $kind, $sid) {
        $now = sf_now();
        if (($in['item'] ?? null) === null) {
            $e = sf_siddur_edition($in['edition'] ?? null);
            $pid = sf_pid();
            $ord = (int)sf_val('SELECT COALESCE(MAX(ord), 0) FROM siddur_items WHERE edition_id = ?', [$e['id']]) + 1;
            sf_insert('siddur_items', ['pid' => $pid, 'edition_id' => $e['id'], 'name' => $name, 'sid' => $sid, 'kind' => $kind, 'ord' => $ord,
                'available' => 1, 'created_at' => $now, 'updated_at' => $now]);
            sf_audit('siddur.item_add', 'siddur_item', $pid, ['edition' => $e['pid'], 'kind' => $kind]);
        } else {
            $it = sf_siddur_item($in['item']);
            $e = sf_row('SELECT * FROM editions WHERE id = ?', [$it['edition_id']]);
            sf_exec('UPDATE siddur_items SET name = ?, sid = ?, kind = ?, updated_at = ? WHERE id = ?', [$name, $sid, $kind, $now, $it['id']]);
            sf_audit('siddur.item_update', 'siddur_item', $it['pid'], ['kind' => $kind]);
        }
        if ($sid !== null) {
            $pub = sf_published((int)$e['id']);
            if (!$pub || !sf_val('SELECT 1 FROM segments WHERE revision_id = ? AND sid = ?', [$pub['id'], $sid])) sf_fail('bad_input', 'היעד לא נמצא בגרסה שפורסמה');
        }
        return sf_siddur_items(['edition' => $e['pid']]);
    });
}

function sf_siddur_item(mixed $pid): array
{
    if (!sf_is_pid($pid)) sf_fail('bad_input');
    return sf_row('SELECT * FROM siddur_items WHERE pid = ?', [$pid]) ?? sf_fail('not_found');
}

/** up / down — החלפה עם השכן. */
function sf_siddur_item_move(array $in): array
{
    $dir = $in['dir'] ?? null;
    if ($dir !== 'up' && $dir !== 'down') sf_fail('bad_input');
    return sf_tx(function () use ($in, $dir) {
        $it = sf_siddur_item($in['item'] ?? null);
        $list = sf_rows('SELECT id, pid FROM siddur_items WHERE edition_id = ? ORDER BY ord, id', [$it['edition_id']]);
        $i = array_search($it['pid'], array_column($list, 'pid'), true);
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if (!isset($list[$j])) sf_fail('state');
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        foreach ($list as $k => $row) sf_exec('UPDATE siddur_items SET ord = ? WHERE id = ?', [$k + 1, $row['id']]);
        sf_audit('siddur.item_move', 'siddur_item', $it['pid'], ['dir' => $dir]);
        return sf_siddur_items(['edition' => sf_val('SELECT pid FROM editions WHERE id = ?', [$it['edition_id']])]);
    });
}

/** "הסרה" = לא זמין (לא נמחק — הסדר האישי של הקוראים מסמן אותו "לא זמין"); והחזרה. */
function sf_siddur_item_available(array $in): array
{
    $on = $in['on'] ?? null;
    if (!is_bool($on)) sf_fail('bad_input');
    return sf_tx(function () use ($in, $on) {
        $it = sf_siddur_item($in['item'] ?? null);
        sf_exec('UPDATE siddur_items SET available = ?, updated_at = ? WHERE id = ?', [$on ? 1 : 0, sf_now(), $it['id']]);
        sf_audit($on ? 'siddur.item_on' : 'siddur.item_off', 'siddur_item', $it['pid']);
        return sf_siddur_items(['edition' => sf_val('SELECT pid FROM editions WHERE id = ?', [$it['edition_id']])]);
    });
}

// ── בדיקת שילובי מנהגים (§2, §21.4) ──────────────────────────

/** כל השילובים: אחד מכל קבוצה של מנהגים זמינים. עד 256 (מעבר לזה — הרשימה נחתכת, ומסומן). */
function sf_combos(array $groups): array
{
    $out = [[]];
    foreach ($groups as $g) {
        $next = [];
        foreach ($out as $c) foreach ($g['customs'] as $x) { $next[] = $c + [$g['grp'] => $x['rule']]; if (count($next) >= 256) break 2; }
        $out = $next;
    }
    return $groups ? $out : [];
}

function sf_combo_key(array $c): string
{
    ksort($c);
    return implode(';', array_map(fn($g, $r) => "$g=$r", array_keys($c), $c));
}

function sf_customs_checks(array $q): array
{
    $e = sf_siddur_edition($q['edition'] ?? null);
    $groups = sf_customs_groups(true);
    $pub = sf_published((int)$e['id']);
    $last = [];
    foreach (sf_rows('SELECT c.combo, c.approved_at, c.approved_by, r.n, r.pid AS rev FROM custom_checks c JOIN revisions r ON r.id = c.revision_id
        WHERE c.edition_id = ? ORDER BY c.id', [$e['id']]) as $c) $last[$c['combo']] = $c;
    $combos = array_map(function ($c) use ($last, $pub) {
        $k = sf_combo_key($c);
        $a = $last[$k] ?? null;
        return ['key' => $k, 'combo' => $c, 'approved' => $a ? ['n' => (int)$a['n'], 'at' => $a['approved_at'], 'by' => sf_staff_name($a['approved_by']),
            'current' => $pub !== null && $a['rev'] === $pub['pid']] : null];
    }, sf_combos($groups));
    return ['edition' => ['pid' => $e['pid'], 'label' => $e['label']], 'groups' => $groups, 'combos' => $combos,
        'published' => $pub ? ['pid' => $pub['pid'], 'n' => (int)$pub['n'], 'html' => $pub['source_html']] : null];
}

/** "אושר": השילוב (combo — {grp: rule}) מול הגרסה שפורסמה (revision — מה שהמנהל ראה). */
function sf_customs_check_approve(array $in, int $me): array
{
    return sf_tx(function () use ($in, $me) {
        $e = sf_siddur_edition($in['edition'] ?? null);
        $pub = sf_published((int)$e['id']) ?? sf_fail('state', 'אין גרסה שפורסמה');
        if (($in['revision'] ?? null) !== $pub['pid']) sf_fail('conflict', 'פורסמה גרסה חדשה בינתיים. לרענן ולבדוק שוב');
        $combo = $in['combo'] ?? null;
        if (!is_array($combo)) sf_fail('bad_input');
        $valid = array_map('sf_combo_key', sf_combos(sf_customs_groups(true)));
        $key = sf_combo_key(array_map('strval', $combo));
        if (!in_array($key, $valid, true)) sf_fail('bad_input', 'השילוב אינו תקין: צריך מנהג זמין אחד מכל קבוצה');
        sf_insert('custom_checks', ['edition_id' => $e['id'], 'combo' => $key, 'revision_id' => $pub['id'], 'approved_by' => $me, 'approved_at' => sf_now()]);
        sf_audit('customs.check', 'edition', $e['pid'], ['combo' => $key, 'n' => (int)$pub['n']]);
        return sf_customs_checks(['edition' => $e['pid']]);
    });
}

// ── עמודים לקוראים (PDF סרוק / תמונה / PDF) ───────────────────

/** edition, import (או null — בלי עמודים). הייבוא — של המהדורה הזו, מוכן, ומסוג שיש לו עמודים. */
function sf_content_pages_set(array $in): array
{
    return sf_tx(function () use ($in) {
        $e = sf_edition_by_pid($in['edition'] ?? null);
        $id = null;
        if (($in['import'] ?? null) !== null) {
            $im = sf_import_by_pid($in['import']);
            if ((int)$im['edition_id'] !== (int)$e['id']) sf_fail('bad_input');
            if (!in_array($im['kind'], SF_PAGES_KINDS, true) || !in_array($im['status'], ['ready', 'applied'], true)) sf_fail('state', 'רק ייבוא של PDF או תמונה שהעיבוד שלו הסתיים');
            $id = (int)$im['id'];
        }
        sf_exec('UPDATE editions SET pages_import_id = ?, updated_at = ? WHERE id = ?', [$id, sf_now(), $e['id']]);
        sf_audit($id ? 'edition.pages_on' : 'edition.pages_off', 'edition', $e['pid'], ['import' => $in['import'] ?? null]);
        return ['edition' => $e['pid'], 'pages_import' => $in['import'] ?? null];
    });
}

// ── תוכן דוגמה (📦 §24 — עד שיגיע התוכן המוגה) ───────────────

const SF_SAMPLE_SIDDUR = <<<'HTML'
<p><sf-style v="gray">דוגמה — טקסט קצר לבדיקת הקורא, לא נוסח מוגה.</sf-style></p>
<sf-section title="שחרית">
<sf-section title="ברכות השחר">
<p><sf-note kind="instruction">אומרים בבוקר, אחרי נטילת ידיים</sf-note></p>
<p>בָּרוּךְ אַתָּה ה' אֱלֹהֵינוּ מֶלֶךְ הָעוֹלָם, אֲשֶׁר נָתַן לַשֶּׂכְוִי בִינָה לְהַבְחִין בֵּין יוֹם וּבֵין לָיְלָה.<sf-anchor title="הנותן לשכוי בינה"></sf-anchor></p>
<p>בָּרוּךְ אַתָּה ה' אֱלֹהֵינוּ מֶלֶךְ הָעוֹלָם, פּוֹקֵחַ עִוְרִים.</p>
<p>בָּרוּךְ אַתָּה ה' אֱלֹהֵינוּ מֶלֶךְ הָעוֹלָם, מַלְבִּישׁ עֲרֻמִּים.</p>
</sf-section>
<sf-section title="קריאת שמע">
<p><sf-note kind="instruction">מכסים את העיניים ואומרים בכוונה</sf-note></p>
<p>שְׁמַע יִשְׂרָאֵל ה' אֱלֹהֵינוּ ה' אֶחָד.<sf-anchor title="שמע ישראל"></sf-anchor></p>
<p>בָּרוּךְ שֵׁם כְּבוֹד מַלְכוּתוֹ לְעוֹלָם וָעֶד. <sf-note kind="commentary">בלחש — דוגמה לפירוש שנפתח בלחיצה</sf-note></p>
</sf-section>
<sf-section title="עמידה">
<p><sf-note kind="instruction">תְּפִלָּה זו אומרים בעמידה</sf-note></p>
<sf-cond rule="eretz"><p>בארץ ישראל: מתחילים לשאול גשמים בז' בחשוון. (דוגמה)</p></sf-cond>
<sf-cond rule="chul"><p>בחוץ לארץ: מתחילים לשאול גשמים בתקופת טבת. (דוגמה)</p></sf-cond>
<p>אַתָּה גִבּוֹר לְעוֹלָם אֲדֹנָי, מְחַיֶּה מֵתִים אַתָּה, רַב לְהוֹשִׁיעַ.</p>
</sf-section>
<sf-section title="קדיש">
<sf-cond rule="minyan"><p>יִתְגַּדַּל וְיִתְקַדַּשׁ שְׁמֵהּ רַבָּא.</p></sf-cond>
<sf-cond rule="yachid"><p><sf-note kind="instruction">היחיד אינו אומר קדיש — ממשיכים הלאה</sf-note></p></sf-cond>
</sf-section>
</sf-section>
<sf-section title="מנחה">
<p>אַשְׁרֵי יוֹשְׁבֵי בֵיתֶךָ, עוֹד יְהַלְלוּךָ סֶּלָה.</p>
</sf-section>
<sf-section title="ערבית">
<p>וְהוּא רַחוּם יְכַפֵּר עָוֹן וְלֹא יַשְׁחִית.</p>
<p><sf-link to="%SHEMA%">לקריאת שמע של שחרית</sf-link></p>
</sf-section>
HTML;

const SF_SAMPLE_TEHILLIM = <<<'HTML'
<p><sf-style v="gray">דוגמה — חמישה פרקים ראשונים בקיצור, לבדיקת הקורא. לא נוסח מוגה.</sf-style></p>
<sf-section title="פרק א">
<p>אַשְׁרֵי הָאִישׁ אֲשֶׁר לֹא הָלַךְ בַּעֲצַת רְשָׁעִים וּבְדֶרֶךְ חַטָּאִים לֹא עָמָד וּבְמוֹשַׁב לֵצִים לֹא יָשָׁב.</p>
<p>כִּי אִם בְּתוֹרַת ה' חֶפְצוֹ וּבְתוֹרָתוֹ יֶהְגֶּה יוֹמָם וָלָיְלָה.</p>
<p>וְהָיָה כְּעֵץ שָׁתוּל עַל פַּלְגֵי מָיִם אֲשֶׁר פִּרְיוֹ יִתֵּן בְּעִתּוֹ וְעָלֵהוּ לֹא יִבּוֹל וְכֹל אֲשֶׁר יַעֲשֶׂה יַצְלִיחַ.</p>
<p>לֹא כֵן הָרְשָׁעִים כִּי אִם כַּמֹּץ אֲשֶׁר תִּדְּפֶנּוּ רוּחַ.</p>
<p>עַל כֵּן לֹא יָקֻמוּ רְשָׁעִים בַּמִּשְׁפָּט וְחַטָּאִים בַּעֲדַת צַדִּיקִים.</p>
<p>כִּי יוֹדֵעַ ה' דֶּרֶךְ צַדִּיקִים וְדֶרֶךְ רְשָׁעִים תֹּאבֵד.</p>
</sf-section>
<sf-section title="פרק ב"><p>לָמָּה רָגְשׁוּ גוֹיִם וּלְאֻמִּים יֶהְגּוּ רִיק.</p></sf-section>
<sf-section title="פרק ג"><p>מִזְמוֹר לְדָוִד בְּבָרְחוֹ מִפְּנֵי אַבְשָׁלוֹם בְּנוֹ.</p><p>ה' מָה רַבּוּ צָרָי, רַבִּים קָמִים עָלָי.</p></sf-section>
<sf-section title="פרק ד"><p>לַמְנַצֵּחַ בִּנְגִינוֹת מִזְמוֹר לְדָוִד.</p></sf-section>
<sf-section title="פרק ה"><p>לַמְנַצֵּחַ אֶל הַנְּחִילוֹת מִזְמוֹר לְדָוִד.</p></sf-section>
HTML;

/** מילון דוגמה לחיפוש (כתיב מלא / חסר, כינוי). */
const SF_SAMPLE_ALIASES = [['תפלה', 'תפילה'], ['מצוה', 'מצווה'], ["ה'", 'השם']];

/**
 * יוצר "סידור (דוגמה)" ו"תהילים (דוגמה)" — ציבוריים ומפורסמים — עם רשימת תפילות ומילון דוגמה. פעם אחת (meta.sample_content).
 * הכול בכלי הרגילים של 02, ונשאר ביומן. להסרה: לכבות "ציבורי" לכותר.
 */
function sf_content_sample(int $me): array
{
    return sf_tx(function () use ($me) {
        if (sf_meta('sample_content')) sf_fail('state', 'תוכן הדוגמה כבר נוצר');
        $make = function (string $name, string $kind, string $label, string $html) use ($me): array {
            $t = sf_title_save(['name' => $name, 'kind' => $kind, 'access' => 'free', 'description' => 'תוכן דוגמה לבדיקה — יוחלף בתוכן המוגה'])['title'];
            sf_title_public(['title' => $t['pid'], 'on' => true]);
            $e = sf_edition_save(['title' => $t['pid'], 'label' => $label])['edition'];
            $eid = (int)sf_val('SELECT id FROM editions WHERE pid = ?', [$e['pid']]);
            $r = sf_mk_clean($html, sf_mk_ctx($eid));
            if ($r['errors']) sf_fail('server', 'תוכן הדוגמה אינו תקין');
            sf_rev_insert($eid, null, $r['html'], $me);
            sf_content_publish(['edition' => $e['pid'], 'base' => 1], $me);
            return ['title' => $t['pid'], 'edition' => $e['pid'], 'id' => $eid];
        };
        // הקישור הפנימי בערבית מצביע ל"שמע ישראל" — מזהה שנקבע מראש, כדי שהקישור יהיה תקין בפרסום
        $taken = [];
        $shema = sf_mk_new_sid($taken);
        $html = str_replace(['<p>שְׁמַע יִשְׂרָאֵל', '%SHEMA%'], ["<p sid=\"$shema\">שְׁמַע יִשְׂרָאֵל", $shema], SF_SAMPLE_SIDDUR);
        $s = $make('סידור (דוגמה)', 'siddur', 'עדות המזרח — דוגמה', $html);
        $th = $make('תהילים (דוגמה)', 'tehillim', 'דוגמה', SF_SAMPLE_TEHILLIM);
        $pub = sf_published($s['id']);
        $now = sf_now();
        $ord = 0;
        foreach (sf_rows("SELECT sid, title, parent_sid FROM segments WHERE revision_id = ? AND kind = 'section' ORDER BY ord", [$pub['id']]) as $sec) {
            if ($sec['parent_sid'] !== null && !in_array($sec['title'], ['קריאת שמע'], true)) continue;
            sf_insert('siddur_items', ['pid' => sf_pid(), 'edition_id' => $s['id'], 'name' => (string)$sec['title'], 'sid' => $sec['sid'],
                'kind' => $sec['parent_sid'] === null ? 'prayer' : 'shortcut', 'ord' => ++$ord, 'available' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (SF_SAMPLE_ALIASES as [$a, $b]) {
            [$a, $b] = [sf_norm($a), sf_norm($b)];
            if (!sf_val('SELECT 1 FROM search_aliases WHERE term = ? AND alias = ?', [$a, $b])) {
                sf_insert('search_aliases', ['term' => $a, 'alias' => $b, 'created_by' => $me, 'created_at' => $now]);
            }
        }
        sf_meta_set('sample_content', $now);
        sf_audit('content.sample', null, null, ['siddur' => $s['edition'], 'tehillim' => $th['edition']]);
        return ['siddur' => $s['edition'], 'tehillim' => $th['edition']];
    });
}
