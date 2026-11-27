<?php
/**
 * זמני היום (השיטות) והלכה יומית — השרת (אפיון 07 §3, §4). החישוב עצמו — lib/calendar.php (ובדפדפן calendar-core.js).
 *
 *   שיטות   — הקורא רואה רק מאושרות שאינן "לבדיקה בלבד" (zmanim-methods). ברירת מחדל — רק אם המנהל קבע; אחרת null,
 *             והמסך מבקש לבחור לפני שהוא מציג שעה (§11). שמירה = גרסה חדשה; שינוי בהגדרה של שיטה מאושרת מחזיר אותה
 *             ל"טיוטה" (צריך לאשר שוב — ⚙️ team/log/code.md), כדי שקורא לא יקבל שעות של הגדרה שלא אושרה.
 *   הלכה    — אחת לכל תאריך עברי בלי שנה (חודש ש6 + יום). הניקוי — sf_mk_clean של 02, אותו ניקוי בדיוק. טיוטה / פרסום,
 *             בלי מהדורות. **תוכן עתידי לא יוצא מהשרת** (§4): תאריך שיחול השנה אחרי מחר (UTC) — כאילו אין תוכן; מרווח
 *             של יום אחד לאזורי הזמן, והדפדפן מחליט לפי צאת הכוכבים המקומית איזה מהשניים להציג.
 *   מידע אישי — אין כאן: המיקום והשיטה של הקורא לא מגיעים לשרת, חוץ מהסנכרון של 05 (pref).
 */

declare(strict_types=1);

require_once __DIR__ . '/calendar.php';

const SF_HL_MAX = 200_000;

// ── שיטות ───────────────────────────────────────────────────
function sf_zm_def(int $methodId, int $version): array
{
    return json_decode((string)sf_val('SELECT def FROM zmanim_method_versions WHERE method_id = ? AND version = ?', [$methodId, $version]), true) ?: [];
}

function sf_zm_out(array $m, bool $admin): array
{
    $o = ['pid' => $m['pid'], 'name' => $m['name'], 'version' => (int)$m['version'], 'note' => $m['note'], 'is_default' => (bool)(int)$m['is_default'],
        'def' => sf_zm_def((int)$m['id'], (int)$m['version'])];
    if ($admin) $o += ['status' => $m['status'], 'test' => (bool)(int)$m['test'], 'updated_at' => $m['updated_at'],
        'versions' => (int)sf_val('SELECT COUNT(*) FROM zmanim_method_versions WHERE method_id = ?', [$m['id']])];
    return $o;
}

/** לקוראים: המאושרות בלבד, וברירת המחדל של המנהל (או null — חובת בחירה). */
function sf_zm_methods_public(): array
{
    $rows = sf_rows("SELECT * FROM zmanim_methods WHERE status = 'approved' AND test = 0 ORDER BY name");
    $def = null;
    foreach ($rows as $r) if ((int)$r['is_default']) $def = $r['pid'];
    return ['methods' => array_map(fn($r) => sf_zm_out($r, false), $rows), 'default' => $def];
}

function sf_zm_methods_admin(): array
{
    return ['methods' => array_map(fn($r) => sf_zm_out($r, true), sf_rows('SELECT * FROM zmanim_methods ORDER BY test DESC, name'))];
}

function sf_zm_by_pid(mixed $pid): array
{
    if (!sf_is_pid($pid)) sf_fail('bad_input');
    return sf_row('SELECT * FROM zmanim_methods WHERE pid = ?', [$pid]) ?? sf_fail('not_found');
}

/** שמירה: {method?, name, note?, def, base} — base = הגרסה שהעורך טען (0 לשיטה חדשה). */
function sf_zm_method_save(array $in, int $me): array
{
    $name = is_string($in['name'] ?? null) ? trim($in['name']) : '';
    if ($name === '' || mb_strlen($name) > 80) sf_fail('bad_input', 'שם השיטה — בין 1 ל-80 תווים');
    $note = is_string($in['note'] ?? null) ? trim($in['note']) : '';
    if (mb_strlen($note) > 500) sf_fail('bad_input', 'ההערה ארוכה מדי');
    $errs = sf_cal_method_errors($in['def'] ?? null);
    if ($errs) sf_fail('invalid', 'השיטה לא נשמרה: ' . $errs[0], ['errors' => $errs]);
    $def = sf_cal_method_clean($in['def']);
    $json = json_encode($def, JSON_UNESCAPED_UNICODE);
    $base = $in['base'] ?? null;
    if (!is_int($base)) sf_fail('bad_input');
    return sf_tx(function () use ($in, $name, $note, $def, $json, $base, $me) {
        $now = sf_now();
        if (($in['method'] ?? null) === null) {
            if ($base !== 0) sf_fail('bad_input');
            $pid = sf_pid();
            $id = sf_insert('zmanim_methods', ['pid' => $pid, 'name' => $name, 'status' => 'draft', 'test' => 0, 'is_default' => 0, 'version' => 1,
                'note' => $note ?: null, 'created_at' => $now, 'updated_at' => $now, 'updated_by' => $me]);
            sf_insert('zmanim_method_versions', ['method_id' => $id, 'version' => 1, 'def' => $json, 'created_by' => $me, 'created_at' => $now]);
            sf_audit('zmanim.method_create', 'zmanim_method', $pid, ['version' => 1]);
            return ['method' => sf_zm_out(sf_row('SELECT * FROM zmanim_methods WHERE id = ?', [$id]), true)];
        }
        $m = sf_zm_by_pid($in['method']);
        if ($base !== (int)$m['version']) sf_fail('conflict', 'מנהל אחר שמר את השיטה בינתיים — לטעון את הגרסה החדשה?');
        $changed = sf_zm_def((int)$m['id'], (int)$m['version']) !== $def;
        $v = (int)$m['version'] + ($changed ? 1 : 0);
        if ($changed) sf_insert('zmanim_method_versions', ['method_id' => $m['id'], 'version' => $v, 'def' => $json, 'created_by' => $me, 'created_at' => $now]);
        // הגדרה ששונתה — צריך לאשר שוב (קורא לא מקבל שעות של הגדרה שלא אושרה)
        $status = $changed && $m['status'] === 'approved' ? 'draft' : $m['status'];
        sf_exec('UPDATE zmanim_methods SET name = ?, note = ?, version = ?, status = ?, is_default = ?, updated_at = ?, updated_by = ? WHERE id = ?',
            [$name, $note ?: null, $v, $status, $status === 'approved' ? (int)$m['is_default'] : 0, $now, $me, $m['id']]);
        sf_audit('zmanim.method_save', 'zmanim_method', $m['pid'], ['version' => $v, 'changed' => $changed, 'status' => $status]);
        return ['method' => sf_zm_out(sf_row('SELECT * FROM zmanim_methods WHERE id = ?', [$m['id']]), true), 'unapproved' => $status !== $m['status']];
    });
}

/** {method, status: approved | draft | retired} — שיטת בדיקה לא ניתנת לאישור. */
function sf_zm_method_status(array $in): array
{
    $st = $in['status'] ?? null;
    if (!in_array($st, ['approved', 'draft', 'retired'], true)) sf_fail('bad_input');
    return sf_tx(function () use ($in, $st) {
        $m = sf_zm_by_pid($in['method'] ?? null);
        if ($st === 'approved' && (int)$m['test']) sf_fail('state', 'שיטת בדיקה לא ניתנת לאישור לציבור');
        sf_exec('UPDATE zmanim_methods SET status = ?, is_default = ?, updated_at = ? WHERE id = ?', [$st, $st === 'approved' ? (int)$m['is_default'] : 0, sf_now(), $m['id']]);
        sf_audit('zmanim.method_status', 'zmanim_method', $m['pid'], ['status' => $st, 'version' => (int)$m['version']]);
        return [];
    });
}

/** {method: pid | null} — ברירת המחדל של המנהל (רק מאושרת). null — אין ברירת מחדל: הקורא חייב לבחור. */
function sf_zm_method_default(array $in): array
{
    return sf_tx(function () use ($in) {
        $pid = $in['method'] ?? null;
        if ($pid !== null) {
            $m = sf_zm_by_pid($pid);
            if ($m['status'] !== 'approved' || (int)$m['test']) sf_fail('state', 'ברירת מחדל — רק שיטה מאושרת');
        }
        sf_exec('UPDATE zmanim_methods SET is_default = 0 WHERE is_default = 1');
        if ($pid !== null) sf_exec('UPDATE zmanim_methods SET is_default = 1 WHERE pid = ?', [$pid]);
        sf_audit('zmanim.method_default', 'zmanim_method', $pid, []);
        return [];
    });
}

// ── הלכה יומית ──────────────────────────────────────────────
function sf_hl_key(mixed $m, mixed $d): array
{
    $m = is_string($m) && ctype_digit($m) ? (int)$m : $m;
    $d = is_string($d) && ctype_digit($d) ? (int)$d : $d;
    if (!is_int($m) || $m < 1 || $m > 13 || !is_int($d) || $d < 1 || $d > 30) sf_fail('bad_input', 'תאריך עברי לא תקין');
    return [$m, $d];
}

/** "היום" של השרת: יום קבוע (UTC) + 1 — המאוחר ביותר שמותר להראות. */
function sf_hl_horizon(): int
{
    return sf_cal_server_fixed() + 1;
}

/** תאריך עברי (בלי שנה) שהגיע: הפעם הקרובה שלו השנה (שנת "מחר") כבר עברה או היום / מחר — או שאינו חל השנה. */
function sf_hl_visible(int $m, int $d): bool
{
    $hz = sf_hl_horizon();
    $f = sf_cal_fixed_from_heb(sf_cal_heb_from_fixed($hz)['y'], $m, $d);
    return $f === null || $f <= $hz;
}

function sf_hl_ctx(): array
{
    return [
        'customs' => array_column(sf_rows('SELECT rule, grp FROM customs'), 'grp', 'rule'),
        'reserved' => [],
        'link' => fn(string $ep, string $sid) => sf_val("SELECT 1 FROM segments s JOIN revisions r ON r.id = s.revision_id JOIN editions e ON e.id = r.edition_id
            WHERE e.pid = ? AND r.status = 'published' AND s.sid = ?", [$ep, $sid]) !== null,
    ];
}

function sf_hl_row(int $m, int $d): ?array
{
    return sf_row('SELECT * FROM halacha_days WHERE hmonth = ? AND hday = ?', [$m, $d]);
}

/** התאריך הלועזי שבו התאריך העברי חל בשנה העברית הנוכחית (של השרת); null — לא חל השנה */
function sf_hl_greg_this_year(int $m, int $d): ?string
{
    $g = sf_cal_from_hebrew(sf_cal_heb_from_fixed(sf_cal_server_fixed())['y'], $m, $d);
    return $g ? sprintf('%04d-%02d-%02d', $g['y'], $g['m'], $g['d']) : null;
}

function sf_hl_admin_out(?array $r, int $m, int $d): array
{
    return ['month' => $m, 'day' => $d, 'greg' => sf_hl_greg_this_year($m, $d),
        'draft_html' => $r['draft_html'] ?? null, 'published_html' => $r['published_html'] ?? null, 'v' => $r ? (int)$r['draft_v'] : 0,
        'updated_at' => $r['updated_at'] ?? null, 'published_at' => $r['published_at'] ?? null,
        'state' => !$r ? 'none' : ($r['published_html'] === null ? 'draft' : ($r['draft_html'] !== null && $r['draft_html'] !== $r['published_html'] ? 'changed' : 'published'))];
}

/** הלוח לניהול: כל התאריכים שיש להם משהו, והשנה העברית הנוכחית (לתאריך הלועזי) */
function sf_halacha_admin_list(): array
{
    $today = sf_cal_heb_from_fixed(sf_cal_server_fixed());
    $items = [];
    foreach (sf_rows('SELECT * FROM halacha_days ORDER BY hmonth, hday') as $r) {
        $o = sf_hl_admin_out($r, (int)$r['hmonth'], (int)$r['hday']);
        unset($o['draft_html'], $o['published_html']);
        $items[] = $o;
    }
    return ['year' => $today['y'], 'leap' => $today['leap'], 'today' => ['month' => $today['m'], 'day' => $today['d']], 'items' => $items];
}

function sf_halacha_get(array $q): array
{
    [$m, $d] = sf_hl_key($q['month'] ?? null, $q['day'] ?? null);
    return ['item' => sf_hl_admin_out(sf_hl_row($m, $d), $m, $d)];
}

function sf_hl_html(array $in): string
{
    $html = $in['html'] ?? null;
    if (!is_string($html)) sf_fail('bad_input');
    if (strlen($html) > SF_HL_MAX) sf_fail('too_large', 'ההלכה ארוכה מדי');
    return $html;
}

/** טיוטה: {month, day, html, base} — base = draft_v שנטען (0 — עוד אין כלום לתאריך) */
function sf_halacha_save(array $in, int $me): array
{
    [$m, $d] = sf_hl_key($in['month'] ?? null, $in['day'] ?? null);
    $html = sf_hl_html($in);
    $base = $in['base'] ?? null;
    if (!is_int($base)) sf_fail('bad_input');
    $r = sf_mk_clean($html, sf_hl_ctx());
    if ($r['errors']) sf_fail('invalid', null, ['errors' => $r['errors']]);
    return sf_tx(function () use ($m, $d, $r, $base, $me) {
        $row = sf_hl_row($m, $d);
        $now = sf_now();
        if (($row ? (int)$row['draft_v'] : 0) !== $base) sf_fail('conflict', 'מנהל אחר שמר את ההלכה לתאריך הזה בינתיים — לטעון את המצב החדש?');
        if ($row) {
            sf_exec('UPDATE halacha_days SET draft_html = ?, draft_v = draft_v + 1, updated_at = ?, updated_by = ? WHERE id = ?', [$r['html'], $now, $me, $row['id']]);
            $pid = $row['pid'];
        } else {
            $pid = sf_pid();
            sf_insert('halacha_days', ['pid' => $pid, 'hmonth' => $m, 'hday' => $d, 'draft_html' => $r['html'], 'draft_v' => 1, 'created_at' => $now, 'updated_at' => $now, 'updated_by' => $me]);
        }
        sf_audit('halacha.save', 'halacha', $pid, ['month' => $m, 'day' => $d]);
        return ['item' => sf_hl_admin_out(sf_hl_row($m, $d), $m, $d), 'warnings' => $r['warnings']];
    });
}

/** פרסום הטיוטה: {month, day, base}. אזהרה שחוסמת פרסום (02) — חוסמת גם כאן. */
function sf_halacha_publish(array $in, int $me): array
{
    [$m, $d] = sf_hl_key($in['month'] ?? null, $in['day'] ?? null);
    $base = $in['base'] ?? null;
    if (!is_int($base)) sf_fail('bad_input');
    return sf_tx(function () use ($m, $d, $base, $me) {
        $row = sf_hl_row($m, $d);
        if (!$row || $row['draft_html'] === null) sf_fail('state', 'אין טיוטה לפרסום');
        if ((int)$row['draft_v'] !== $base) sf_fail('conflict', 'מנהל אחר שמר את ההלכה לתאריך הזה בינתיים — לטעון את המצב החדש?');
        $r = sf_mk_clean($row['draft_html'], sf_hl_ctx());
        $block = array_values(array_filter($r['warnings'], fn($w) => !empty($w['blocks'])));
        if ($r['errors'] || $block) sf_fail('invalid', 'צריך לתקן לפני פרסום', ['errors' => $r['errors'], 'warnings' => $block]);
        $norm = trim(implode(' ', array_column(sf_mk_segments($r['html']), 'text_norm')));
        if ($norm === '') $norm = sf_norm(strip_tags($r['html']));
        $now = sf_now();
        sf_exec('UPDATE halacha_days SET published_html = ?, draft_html = ?, text_norm = ?, published_at = ?, published_by = ?, updated_at = ? WHERE id = ?',
            [$r['html'], $r['html'], $norm, $now, $me, $now, $row['id']]);
        sf_audit('halacha.publish', 'halacha', $row['pid'], ['month' => $m, 'day' => $d]);
        return ['item' => sf_hl_admin_out(sf_hl_row($m, $d), $m, $d)];
    });
}

function sf_halacha_unpublish(array $in): array
{
    [$m, $d] = sf_hl_key($in['month'] ?? null, $in['day'] ?? null);
    return sf_tx(function () use ($m, $d) {
        $row = sf_hl_row($m, $d);
        if (!$row || $row['published_html'] === null) sf_fail('state', 'ההלכה לתאריך הזה לא פורסמה');
        sf_exec('UPDATE halacha_days SET draft_html = COALESCE(draft_html, published_html), published_html = NULL, text_norm = NULL, published_at = NULL, updated_at = ? WHERE id = ?', [sf_now(), $row['id']]);
        sf_audit('halacha.unpublish', 'halacha', $row['pid'], ['month' => $m, 'day' => $d]);
        return ['item' => sf_hl_admin_out(sf_hl_row($m, $d), $m, $d)];
    });
}

// ── לקוראים ─────────────────────────────────────────────────
function sf_hl_public(array $r): array
{
    return ['month' => (int)$r['hmonth'], 'day' => (int)$r['hday'], 'html' => $r['published_html'], 'published_at' => $r['published_at']];
}

/**
 * ההלכה לתאריך עברי {y, m, d} (הדפדפן מחשב אותו לפי צאת הכוכבים במיקום שלו). תאריך שעוד לא הגיע (אחרי מחר) — כאילו
 * אין תוכן: {item: null}. אין תוכן — {item: null}, והמסך לא מציג דבר (§4).
 */
function sf_read_halacha(array $q): array
{
    $y = is_string($q['y'] ?? null) && ctype_digit($q['y']) ? (int)$q['y'] : null;
    [$m, $d] = sf_hl_key($q['m'] ?? null, $q['d'] ?? null);
    if ($y === null || $y < 5000 || $y > 6500) sf_fail('bad_input', 'תאריך עברי לא תקין');
    $f = sf_cal_fixed_from_heb($y, $m, $d);
    if ($f === null) sf_fail('bad_input', 'התאריך לא קיים בשנה הזו');
    if ($f > sf_hl_horizon() || !sf_hl_visible($m, $d)) return ['item' => null];
    $r = sf_row('SELECT * FROM halacha_days WHERE hmonth = ? AND hday = ? AND published_html IS NOT NULL', [$m, $d]);
    return ['item' => $r ? sf_hl_public($r) : null];
}

/** הארכיון: מה שפורסם ושכבר הגיע. q — חיפוש (כל המילים, אחרי sf_norm); month — רק החודש הזה. עד 60, לפי סדר השנה. */
function sf_read_halacha_archive(array $q): array
{
    $text = is_string($q['q'] ?? null) ? mb_substr(trim($q['q']), 0, 100) : '';
    $month = isset($q['month']) && $q['month'] !== '' ? sf_hl_key($q['month'], '1')[0] : null;
    $where = ['published_html IS NOT NULL'];
    $args = [];
    if ($month !== null) { $where[] = 'hmonth = ?'; $args[] = $month; }
    $words = array_slice(array_filter(explode(' ', sf_norm($text)), fn($w) => $w !== ''), 0, 8);
    // LIKE עם ESCAPE '!' — אותו תחביר ב-SQLite וב-MySQL
    foreach ($words as $w) { $where[] = "text_norm LIKE ? ESCAPE '!'"; $args[] = '%' . strtr($w, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%'; }
    $sql = 'SELECT * FROM halacha_days WHERE ' . implode(' AND ', $where);
    $order = array_flip(SF_CAL_ORDER);
    $rows = array_values(array_filter(sf_rows($sql, $args), fn($r) => sf_hl_visible((int)$r['hmonth'], (int)$r['hday'])));
    usort($rows, fn($a, $b) => [$order[(int)$a['hmonth']], (int)$a['hday']] <=> [$order[(int)$b['hmonth']], (int)$b['hday']]);
    $total = count($rows);
    $out = [];
    foreach (array_slice($rows, 0, 60) as $r) {
        $plain = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', (string)$r['published_html'])), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $out[] = ['month' => (int)$r['hmonth'], 'day' => (int)$r['hday'], 'excerpt' => mb_substr($plain, 0, 140)];
    }
    return ['items' => $out, 'total' => $total];
}
