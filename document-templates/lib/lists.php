<?php
/**
 * רשימות ערכים — ערכים קבועים מראש לשדות, שטוחים או מקוננים (README: "רשימות ערכים").
 *
 * רשימה = שמות רמות (בית עלמין › גוש › חלקה › שורה) + שורות, כל שורה מסלול מלא.
 * שדה (בחירה או טקסט) או עמודה בטבלה נקשרים לרשימה ולרמה: {list: {id, level}}.
 * שדות מאותה רשימה ברמות קודמות מסננים אותו — בית עלמין שנבחר מצמצם את הגושים, וכן הלאה.
 * בחירה — רק מהרשימה; טקסט — הרשימה היא הצעות, ומותר לכתוב גם אחר.
 */

declare(strict_types=1);

const LIST_MAX_ROWS   = 20000;
const LIST_MAX_LEVELS = 8;
const LIST_MAX_MB     = 5;
/** שפות לתרגום ערכים (השם שנבחר בעברית — ובפלט בשפה שנקבעה לשדה). */
const LIST_LANGS = ['en' => 'English', 'fr' => 'Français', 'ar' => 'العربية', 'ru' => 'Русский', 'es' => 'Español', 'de' => 'Deutsch'];

function lists_migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS value_lists (
            id          INTEGER PRIMARY KEY,
            owner_id    INTEGER NOT NULL REFERENCES users(id),
            name_enc    TEXT NOT NULL,
            levels_enc  TEXT NOT NULL,      -- ["בית עלמין", "גוש", …]
            rows_enc    TEXT NOT NULL,      -- [["הר המנוחות", "51", …], …]
            row_count   INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT NOT NULL,
            updated_at  TEXT NOT NULL,
            deleted_at  TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_lists_owner ON value_lists(owner_id);
        SQL);
    // תרגומים: {"<רמה>": {"<ערך>": {"en": "…", "fr": "…"}}}
    $cols = array_column($pdo->query('PRAGMA table_info(value_lists)')->fetchAll(), 'name');
    if (!in_array('tr_enc', $cols, true)) $pdo->exec('ALTER TABLE value_lists ADD COLUMN tr_enc TEXT');
}

/**
 * שורות נקיות: באורך הרמות, בלי ריקות ובלי כפולות. תא ריק לפני תא מלא — כמו בשורה שמעליו
 * (כך נראית היררכיה באקסל: בית העלמין כתוב פעם אחת, והשורות שמתחתיו ריקות בעמודה הזו).
 */
function list_rows_clean(array $rows, int $levels): array
{
    $out = [];
    $seen = [];
    $prev = array_fill(0, $levels, '');
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $row = array_map(fn($i) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($r[$i] ?? ''))), 0, 200), range(0, $levels - 1));
        $last = -1;
        foreach ($row as $i => $v) if ($v !== '') $last = $i;
        if ($last < 0) continue;
        for ($i = 0; $i < $last; $i++) if ($row[$i] === '') $row[$i] = $prev[$i];
        if ($row[0] === '') continue;
        $prev = $row;
        $k = implode("\x1f", $row);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $row;
        if (count($out) > LIST_MAX_ROWS) throw new ApiError('עד ' . LIST_MAX_ROWS . ' שורות ברשימה', 422);
    }
    return $out;
}

function list_levels_clean($levels): array
{
    $out = [];
    foreach (array_slice((array)$levels, 0, LIST_MAX_LEVELS) as $l) {
        $l = mb_substr(trim((string)$l), 0, 40);
        $out[] = $l !== '' ? $l : 'רמה ' . (count($out) + 1);
    }
    return $out ?: ['ערך'];
}

function list_row(array $user, int $id): array
{
    $st = db()->prepare('SELECT * FROM value_lists WHERE id = ? AND owner_id = ? AND deleted_at IS NULL');
    $st->execute([$id, $user['id']]);
    return $st->fetch() ?: throw new ApiError('הרשימה לא נמצאה', 404);
}

function list_view(array $r, bool $rows = false): array
{
    $tr = !empty($r['tr_enc']) ? (json_decode((string)unseal($r['tr_enc']), true) ?: []) : [];
    return ['id' => (int)$r['id'], 'name' => (string)unseal($r['name_enc']),
        'levels' => json_decode((string)unseal($r['levels_enc']), true) ?: [], 'row_count' => (int)$r['row_count'],
        'langs' => list_tr_langs($tr), 'updated_at' => $r['updated_at']]
        + ($rows ? ['rows' => json_decode((string)unseal($r['rows_enc']), true) ?: [], 'translations' => (object)$tr] : []);
}

/** אילו שפות יש בתרגומים. */
function list_tr_langs(array $tr): array
{
    $l = [];
    foreach ($tr as $vals) foreach ((array)$vals as $t) foreach ((array)$t as $lang => $x) if ($x !== '') $l[$lang] = true;
    return array_values(array_intersect(array_keys(LIST_LANGS), array_keys($l)));
}

/** תרגומים נקיים: רק רמות שקיימות, ערכים שקיימים ברמה, ושפות מוכרות. */
function list_tr_clean($tr, array $rows, int $levels): array
{
    $out = [];
    foreach ((array)$tr as $lv => $vals) {
        $lv = (int)$lv;
        if ($lv < 0 || $lv >= $levels || !is_array($vals)) continue;
        $have = array_flip(array_map('strval', array_column($rows, $lv)));
        foreach ($vals as $v => $t) {
            if (!isset($have[(string)$v]) || !is_array($t)) continue;
            foreach ($t as $lang => $x) {
                $x = mb_substr(trim((string)$x), 0, 200);
                if (isset(LIST_LANGS[$lang]) && $x !== '') $out[(string)$lv][(string)$v][$lang] = $x;
            }
        }
    }
    return $out;
}

function list_index(array $user): array
{
    $st = db()->prepare('SELECT * FROM value_lists WHERE owner_id = ? AND deleted_at IS NULL ORDER BY id DESC');
    $st->execute([$user['id']]);
    return ['ok' => true, 'lists' => array_map('list_view', $st->fetchAll())];
}

function list_get(array $user, array $q): array
{
    return ['ok' => true, 'list' => list_view(list_row($user, (int)($q['id'] ?? 0)), true)];
}

/** {id?, name, levels: [...], rows: [[...], ...], translations?: {"<רמה>": {"<ערך>": {"en": "…"}}}} */
function list_save(array $user, array $b): array
{
    $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 80);
    if ($name === '') throw new ApiError('לרשימה צריך שם', 422);
    $levels = list_levels_clean($b['levels'] ?? []);
    $rows = list_rows_clean((array)($b['rows'] ?? []), count($levels));
    $tr = list_tr_clean($b['translations'] ?? [], $rows, count($levels));
    $now = now_iso();
    $enc = [seal($name), seal(json_encode($levels, JSON_UNESCAPED_UNICODE)), seal(json_encode($rows, JSON_UNESCAPED_UNICODE)), count($rows),
        seal(json_encode((object)$tr, JSON_UNESCAPED_UNICODE))];
    if (!empty($b['id'])) {
        $r = list_row($user, (int)$b['id']);
        db()->prepare('UPDATE value_lists SET name_enc = ?, levels_enc = ?, rows_enc = ?, row_count = ?, tr_enc = ?, updated_at = ? WHERE id = ?')
            ->execute([...$enc, $now, $r['id']]);
        $id = (int)$r['id'];
    } else {
        db()->prepare('INSERT INTO value_lists (owner_id, name_enc, levels_enc, rows_enc, row_count, tr_enc, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$user['id'], ...$enc, $now, $now]);
        $id = (int)db()->lastInsertId();
    }
    return ['ok' => true, 'list' => list_view(list_row($user, $id))];
}

function list_delete(array $user, array $b): array
{
    $r = list_row($user, (int)($b['id'] ?? 0));
    db()->prepare('UPDATE value_lists SET deleted_at = ? WHERE id = ?')->execute([now_iso(), $r['id']]);
    return ['ok' => true];
}

/** קובץ Excel / CSV → רמות (השורה הראשונה) ושורות. לא נשמר — המשתמש רואה, מתקן ושומר. */
function list_import(array $user, array $files): array
{
    $f = $files['file'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== 0) throw new ApiError('לא התקבל קובץ', 422);
    if ($f['size'] > LIST_MAX_MB * 1024 * 1024) throw new ApiError('הקובץ גדול מדי (עד ' . LIST_MAX_MB . 'MB)', 413);
    $bytes = (string)file_get_contents($f['tmp_name']);
    if ($bytes === '') throw new ApiError('הקובץ ריק', 422);
    $rows = str_starts_with($bytes, "PK\x03\x04") ? xlsx_read($bytes, LIST_MAX_ROWS) : csv_read($bytes, LIST_MAX_ROWS);
    if (count($rows) > LIST_MAX_ROWS + 1) throw new ApiError('יותר מדי שורות (עד ' . LIST_MAX_ROWS . ')', 422);
    $rows = array_values(array_filter($rows, fn($r) => array_filter($r, fn($v) => trim((string)$v) !== '')));
    if (count($rows) < 2) throw new ApiError('צריך שורת כותרת (שמות הרמות) ולפחות שורה אחת', 422);
    $head = array_map(fn($v) => trim((string)$v), array_shift($rows));
    while ($head && end($head) === '') array_pop($head);
    // עמודת תרגום: "בית עלמין (en)" / "בית עלמין [fr]" — תרגום של הרמה בשם הזה, לא רמה בפני עצמה
    $lvCols = []; $trCols = [];
    foreach ($head as $i => $h) {
        if (preg_match('/^(.*?)\s*[\(\[]\s*([a-z]{2})\s*[\)\]]$/u', $h, $m) && isset(LIST_LANGS[strtolower($m[2])])) $trCols[$i] = [trim($m[1]), strtolower($m[2])];
        else $lvCols[$i] = $h;
    }
    $levels = list_levels_clean(array_values($lvCols));
    $pos = array_flip(array_keys($lvCols));   // עמודה בקובץ ← רמה
    $plain = array_map(fn($r) => array_map(fn($i) => $r[$i] ?? '', array_keys($lvCols)), $rows);
    $clean = list_rows_clean($plain, count($levels));
    // התרגומים: לכל שורה — הערך ברמה (אחרי "כמו בשורה שמעל") ← התרגום שבעמודה
    $tr = [];
    $prev = [];
    foreach ($rows as $r) {
        foreach ($lvCols as $i => $name) { $v = trim((string)($r[$i] ?? '')); if ($v !== '') $prev[$pos[$i]] = $v; }
        foreach ($trCols as $i => [$lvName, $lang]) {
            $x = trim((string)($r[$i] ?? ''));
            $lv = array_search($lvName, $levels, true);
            if ($x === '' || $lv === false || !isset($prev[$lv])) continue;
            $tr[(string)$lv][$prev[$lv]][$lang] = $x;
        }
    }
    return ['ok' => true, 'levels' => $levels, 'rows' => $clean, 'translations' => (object)list_tr_clean($tr, $clean, count($levels))];
}

/** הרשימות שמותר לקשור בתבנית: של המשתמש, ומה שכבר קשור בה (שותף שעורך תבנית של אחר). */
function allowed_lists(array $user, array $t): array
{
    $st = db()->prepare('SELECT id FROM value_lists WHERE owner_id = ? AND deleted_at IS NULL');
    $st->execute([$user['id']]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_unique(array_merge($ids, schema_lists(json_decode((string)unseal($t['schema_enc']), true) ?: []))));
}

/** מזהי הרשימות שהמבנה משתמש בהן — בשדות ובעמודות. */
function schema_lists(array $schema): array
{
    $ids = [];
    foreach ($schema['fields'] ?? [] as $f) {
        if (!empty($f['list']['id'])) $ids[] = (int)$f['list']['id'];
        foreach ($f['columns'] ?? [] as $c) if (!empty($c['list']['id'])) $ids[] = (int)$c['list']['id'];
    }
    return array_values(array_unique($ids));
}

/** קישור לרשימה, בדוק: רשימה מותרת ורמה שקיימת בה. */
function clean_list_ref($ref, array $allowed): ?array
{
    if (!is_array($ref) || empty($ref['id'])) return null;
    $id = (int)$ref['id'];
    if (!in_array($id, $allowed, true)) return null;
    $st = db()->prepare('SELECT levels_enc FROM value_lists WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$id]);
    $levels = ($l = $st->fetchColumn()) ? (json_decode((string)unseal($l), true) ?: []) : [];
    $level = (int)($ref['level'] ?? 0);
    if (!$levels || $level < 0 || $level >= count($levels)) return null;
    // שפת הפלט: בוחרים בעברית, ובמסמך שיוצא — התרגום (אם יש), אחרת הערך כמו שהוא
    return ['id' => $id, 'level' => $level] + (isset(LIST_LANGS[$ref['lang'] ?? '']) ? ['lang' => $ref['lang']] : []);
}

/** הרשימות שהמסמך צריך (לסינון המקונן בדפדפן): {id: {name, levels, rows}}. */
function lists_for(array $schema): array
{
    $out = [];
    foreach (schema_lists($schema) as $id) {
        $st = db()->prepare('SELECT * FROM value_lists WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]);
        if ($r = $st->fetch()) { $v = list_view($r, true); $out[(string)$id] = ['name' => $v['name'], 'levels' => $v['levels'], 'rows' => $v['rows'], 'translations' => $v['translations']]; }
    }
    return $out;
}

/** הערכים ברמה (בלי כפולים, לפי הסדר) — לאפשרויות של שדה בחירה ולבדיקה בשרת. */
function list_level_values(array $ref): array
{
    static $cache = [];
    $id = (int)$ref['id'];
    if (!isset($cache[$id])) {
        $st = db()->prepare('SELECT rows_enc FROM value_lists WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]);
        $cache[$id] = ($r = $st->fetchColumn()) ? (json_decode((string)unseal($r), true) ?: []) : null;
    }
    if ($cache[$id] === null) return [];
    $vals = [];
    foreach ($cache[$id] as $row) if (($row[$ref['level']] ?? '') !== '') $vals[$row[$ref['level']]] = true;
    return array_map('strval', array_keys($vals));
}

/** הערך כפי שהוא נכתב במסמך שיוצא: תרגום לשפה שנקבעה לשדה (אם יש), אחרת הערך עצמו. */
function list_translate(?array $ref, string $v): string
{
    if (!$ref || empty($ref['lang']) || $v === '') return $v;
    static $cache = [];
    $id = (int)$ref['id'];
    if (!array_key_exists($id, $cache)) {
        $st = db()->prepare('SELECT tr_enc FROM value_lists WHERE id = ?');
        $st->execute([$id]);
        $t = $st->fetchColumn();
        $cache[$id] = $t ? (json_decode((string)unseal($t), true) ?: []) : [];
    }
    return (string)($cache[$id][(string)$ref['level']][$v][$ref['lang']] ?? $v);
}
