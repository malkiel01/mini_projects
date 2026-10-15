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

const LIST_MAX_ROWS   = 100000;
/** עד כמה שורות הרשימה נשלחת כולה לדפדפן עם המסמך; מעל — "רשימה גדולה": הטופס שואל את השרת רמה אחרי רמה (list_opts). */
const LIST_INLINE     = 3000;
/** עד כמה שורות עורכים את הרשימה בטבלה; מעל — עורכים בקובץ ומייבאים מחדש (בעורך: חיפוש ותצוגה). */
const LIST_EDIT_MAX   = 5000;
/** בייבוא בחלקים (קובץ גדול ממגבלת ההעלאה של השרת) — הגודל הכולל המותר. */
const LIST_MAX_TOTAL_MB = 30;
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
        'langs' => list_tr_langs($tr), 'names' => (object)($tr['names'] ?? []), 'updated_at' => $r['updated_at']]
        + (($mis = list_misplaced(json_decode((string)unseal($r['levels_enc']), true) ?: [])) ? ['misplaced' => $mis] : [])
        + ($rows ? ['rows' => json_decode((string)unseal($r['rows_enc']), true) ?: [], 'translations' => (object)$tr] : []);
}

/** אילו שפות יש בתרגומים. */
function list_tr_langs(array $tr): array
{
    $l = [];
    foreach ($tr as $lv => $vals) if ($lv !== 'names') foreach ((array)$vals as $t) foreach ((array)$t as $lang => $x) if ($x !== '') $l[$lang] = true;
    return array_values(array_intersect(array_keys(LIST_LANGS), array_keys($l)));
}

/** תרגומים נקיים: רק רמות שקיימות, ערכים שקיימים ברמה, ושפות מוכרות. */
function list_tr_clean($tr, array $rows, int $levels): array
{
    $out = [];
    // שמות העמודות לפלט: {"names": {"<רמה>": {"en": "Cemetery"}}}
    foreach ((array)(((array)$tr)['names'] ?? []) as $lv => $ns) foreach ((array)$ns as $lang => $n) {
        $n = mb_substr(trim((string)$n), 0, 80);
        if ((int)$lv >= 0 && (int)$lv < $levels && isset(LIST_LANGS[$lang]) && $n !== '') $out['names'][(string)(int)$lv][$lang] = $n;
    }
    foreach ((array)$tr as $lv => $vals) {
        if ($lv === 'names') continue;
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
    $v = list_view(list_row($user, (int)($q['id'] ?? 0)), true);
    if ($v['row_count'] > LIST_EDIT_MAX) {
        // רשימה גדולה: לא כל השורות לדפדפן — חיפוש (עד 200 תוצאות), והערכים בכל רמה (לתרגומים)
        $needle = trim((string)($q['q'] ?? ''));
        $match = $needle === '' ? $v['rows'] : array_values(array_filter($v['rows'], function ($r) use ($needle) {
            foreach ($r as $x) if (mb_stripos($x, $needle) !== false) return true;
            return false;
        }));
        $vals = []; $more = [];
        foreach ($v['levels'] as $i => $_) {
            $u = [];
            foreach ($v['rows'] as $r) if (($r[$i] ?? '') !== '') $u[$r[$i]] = true;
            $more[$i] = count($u) > 3000;
            $vals[$i] = array_slice(array_map('strval', array_keys($u)), 0, 3000);
        }
        $v = ['big' => true, 'matches' => count($match), 'rows' => array_slice($match, 0, 200), 'values' => $vals, 'values_more' => $more] + $v;
    }
    return ['ok' => true, 'list' => $v];
}

/** {id?, name, levels: [...], rows: [[...], ...], translations?: {"<רמה>": {"<ערך>": {"en": "…"}}}} */
function list_save(array $user, array $b): array
{
    $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 80);
    if ($name === '') throw new ApiError('לרשימה צריך שם', 422);
    $levels = list_levels_clean($b['levels'] ?? []);
    $rows = list_rows_clean((array)($b['rows'] ?? []), count($levels));
    if (!empty($b['keep_rows']) && !empty($b['id'])) {
        // רשימה גדולה: משנים שם ותרגומים — השורות והרמות נשארות כמו שהן בשרת
        $cur = list_view(list_row($user, (int)$b['id']), true);
        $levels = $cur['levels']; $rows = $cur['rows'];
    }
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
function list_import(array $user, array $files, array $post = []): array
{
    $f = $files['file'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== 0) {
        throw new ApiError(($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'הקובץ גדול מהמותר בשרת (' . ini_get('upload_max_filesize') . ')' : 'לא התקבל קובץ', 422);
    }
    $stage = (string)($post['stage'] ?? '');
    if ($stage !== '') {
        // קובץ CSV גדול — בחלקים (כל חלק קטן ממגבלת ההעלאה של השרת), ומחברים בשרת
        if (!preg_match('/^[0-9a-f]{16,32}$/', $stage)) throw new ApiError('ייבוא לא תקין', 422);
        $dir = DT_DATA_DIR . '/tmp';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $path = "$dir/list-{$user['id']}-$stage.csv";
        if ((int)($post['part'] ?? 0) === 0 && is_file($path)) unlink($path);
        foreach (glob("$dir/list-*.csv") ?: [] as $old) if (filemtime($old) < time() - 3600) @unlink($old);   // ייבוא שנזנח
        file_put_contents($path, (string)file_get_contents($f['tmp_name']), FILE_APPEND);
        if (filesize($path) > LIST_MAX_TOTAL_MB * 1024 * 1024) { unlink($path); throw new ApiError('הקובץ גדול מדי (עד ' . LIST_MAX_TOTAL_MB . 'MB)', 413); }
        if (empty($post['last'])) return ['ok' => true, 'received' => true];
        $bytes = (string)file_get_contents($path);
        unlink($path);
    } else {
        if ($f['size'] > LIST_MAX_MB * 1024 * 1024) throw new ApiError('הקובץ גדול מדי (עד ' . LIST_MAX_MB . 'MB)', 413);
        $bytes = (string)file_get_contents($f['tmp_name']);
    }
    if ($bytes === '') throw new ApiError('הקובץ ריק', 422);
    $rows = str_starts_with($bytes, "PK\x03\x04") ? xlsx_read($bytes, LIST_MAX_ROWS) : csv_read($bytes, LIST_MAX_ROWS);
    if (count($rows) > LIST_MAX_ROWS + 1) throw new ApiError('יותר מדי שורות (עד ' . LIST_MAX_ROWS . ')', 422);
    $rows = array_values(array_filter($rows, fn($r) => array_filter($r, fn($v) => trim((string)$v) !== '')));
    if (count($rows) < 2) throw new ApiError('צריך שורת כותרת (שמות הרמות) ולפחות שורה אחת', 422);
    $head = array_map(fn($v) => trim((string)$v), array_shift($rows));
    while ($head && end($head) === '') array_pop($head);
    [$levels, $clean, $tr, $outputs] = list_parse_table($head, $rows);
    if (!empty($post['apply'])) {
        // שמירה ישירה בשרת (רשימה גדולה — בלי לשלוח את כל השורות לדפדפן ובחזרה)
        $id = (int)($post['id'] ?? 0);
        $name = $id ? list_view(list_row($user, $id))['name'] : trim((string)($post['name'] ?? ''));
        $saved = list_save($user, ['id' => $id ?: null, 'name' => $name !== '' ? $name : 'רשימה', 'levels' => $levels, 'rows' => $clean, 'translations' => $tr])['list'];
        return ['ok' => true, 'saved' => true, 'list' => $saved, 'levels' => $levels, 'count' => count($clean), 'translated' => list_tr_langs($tr), 'outputs' => $outputs];
    }
    return ['ok' => true, 'levels' => $levels, 'rows' => $clean, 'translations' => (object)$tr, 'outputs' => $outputs];
}

/**
 * כותרת + שורות → רמות, שורות נקיות, ועמודות לפלט (במסמך במקום הערך שנבחר) — מקובץ, או מתיקון רשימה קיימת.
 * @return array{0: array, 1: array, 2: array, 3: array} [levels, rows, translations, outputs]
 */
function list_parse_table(array $head, array $rows): array
{
    // עמודה לפלט (בשפה אחרת) — לא רמה בפני עצמה, אלא מה שנכתב במסמך במקום הערך שנבחר:
    //   "בית עלמין (en)" / "בית עלמין [fr]" — של הרמה בשם הזה;
    //   כותרת בלי עברית ("Cemetery", "Cemetery (en)") — של הרמה שמימינה (עמודה צמודה),
    //   או לפי הסדר (כל העמודות באנגלית בסוף: הראשונה — לרמה הראשונה, וכן הלאה).
    $isHe = fn($h) => (bool)preg_match('/\p{Hebrew}/u', $h);
    $anyHe = (bool)array_filter($head, $isHe);
    $lvCols = []; $alt = [];
    // מה יש בכל עמודה: ערכים בעברית / ערכים באותיות לטיניות
    $heVal = []; $latVal = [];
    foreach (array_slice($rows, 0, 2000) as $r) foreach ($head as $i => $_) {
        $v = (string)($r[$i] ?? '');
        if (preg_match('/\p{Hebrew}/u', $v)) $heVal[$i] = true;
        elseif (preg_match('/[A-Za-z]/', $v)) $latVal[$i] = true;
    }
    foreach ($head as $i => $h) {
        [$base, $lang] = list_col_lang($h);
        $latinHead = $anyHe && !$isHe($h) && preg_match('/\p{L}/u', $h);
        // כותרת בעברית, אבל הערכים באנגלית — ליד עמודה שהערכים שלה בעברית: גם זו עמודה לפלט
        $latinVals = $lang === null && !$latinHead && !empty($latVal[$i]) && empty($heVal[$i]) && !empty($heVal[$i - 1]);
        if ($lang !== null || $latinHead || $latinVals) $alt[$i] = [$base, $lang ?? 'en', $h];
        else $lvCols[$i] = $h;
    }
    $levels = list_levels_clean(array_values($lvCols));
    $lvIdx = array_flip(array_keys($lvCols));   // עמודה בקובץ ← רמה
    $trCols = []; $taken = [];
    foreach ($alt as $i => [$base, $lang, $h]) {
        $lv = array_search($base, $levels, true);
        // צמודה לרמה (עברית, אנגלית, עברית, אנגלית…) — לא כשהעמודה הבאה גם באנגלית (כולן בסוף, בגוש אחד)
        if ($lv === false && isset($lvIdx[$i - 1]) && !isset($alt[$i + 1]) && !isset($taken[$lang][$lvIdx[$i - 1]])) $lv = $lvIdx[$i - 1];
        if ($lv === false) for ($k = 0; $k < count($levels); $k++) if (!isset($taken[$lang][$k])) { $lv = $k; break; }   // לפי הסדר
        if ($lv === false || isset($taken[$lang][$lv])) continue;
        $taken[$lang][$lv] = true;
        $trCols[$i] = [$lv, $lang, $base];
    }
    $pos = array_flip(array_keys($lvCols));   // עמודה בקובץ ← רמה
    $plain = array_map(fn($r) => array_map(fn($i) => $r[$i] ?? '', array_keys($lvCols)), $rows);
    $clean = list_rows_clean($plain, count($levels));
    // התרגומים: לכל שורה — הערך ברמה (אחרי "כמו בשורה שמעל") ← התרגום שבעמודה
    $tr = [];
    $prev = [];
    foreach ($rows as $r) {
        foreach ($lvCols as $i => $name) { $v = trim((string)($r[$i] ?? '')); if ($v !== '') $prev[$pos[$i]] = $v; }
        foreach ($trCols as $i => [$lv, $lang]) {
            $x = trim((string)($r[$i] ?? ''));
            if ($x === '' || !isset($prev[$lv]) || $x === $prev[$lv]) continue;   // זהה (מספר גוש) — אין מה לתרגם
            $tr[(string)$lv][$prev[$lv]][$lang] = $x;
        }
    }
    // שם העמודה לפלט ("Cemetery") — כך היא מוצגת בבחירה "מה ייכתב במסמך"
    foreach ($trCols as $i => [$lv, $lang]) $tr['names'][(string)$lv][$lang] = $head[$i];   // כמו בקובץ
    $tr = list_tr_clean($tr, $clean, count($levels));
    // לדיווח: איזו עמודה בקובץ נכתבת במסמך במקום איזו רמה ("Cemetery ← בית עלמין")
    $outputs = array_values(array_map(fn($i) => ['column' => $head[$i], 'level' => $levels[$trCols[$i][0]], 'lang' => $trCols[$i][1]], array_keys($trCols)));
    // מעמודה בקובץ לרמה: עמודת רמה ← הרמה שלה; עמודה לפלט ← הרמה שלידה (ושפתה)
    $map = [];
    foreach ($lvIdx as $i => $lv) $map[$i] = ['level' => $lv];
    foreach ($trCols as $i => [$lv, $lang]) $map[$i] = ['level' => $lv, 'lang' => $lang];
    return [$levels, $clean, $tr, $outputs, $map];
}

/** עמודות ברשימה שנראות כמו עמודה לפלט ולא כמו רמה (נקלטו לפני שהזיהוי ידע אותן): "Cemetery" ליד "בית עלמין". */
function list_misplaced(array $levels): array
{
    $anyHe = (bool)preg_grep('/\p{Hebrew}/u', $levels);
    return array_values(array_filter($levels, fn($h) => list_col_lang($h)[1] !== null || ($anyHe && !preg_match('/\p{Hebrew}/u', $h) && preg_match('/\p{L}/u', $h))));
}

/** תיקון רשימה: עמודות באנגלית שנקלטו כרמות — הופכות לעמודות למסמך של הרמה שלידן. */
function list_repair(array $user, array $b): array
{
    $v = list_view(list_row($user, (int)($b['id'] ?? 0)), true);
    if (!list_misplaced($v['levels'])) throw new ApiError('אין עמודות לתקן', 422);
    [$levels, $clean, $tr, $outputs, $map] = list_parse_table($v['levels'], $v['rows']);
    $saved = list_save($user, ['id' => $v['id'], 'name' => $v['name'], 'levels' => $levels, 'rows' => $clean, 'translations' => $tr])['list'];
    // map: הרמה הישנה ← החדשה (לעדכון שדות שכבר קשורים לרשימה)
    return ['ok' => true, 'list' => $saved, 'levels' => $levels, 'outputs' => $outputs, 'map' => $map];
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
    // show — מה הממלא רואה ובוחר; lang — מה נכתב במסמך. ריק = הערך עצמו (העמודה הראשית, בעברית)
    return ['id' => $id, 'level' => $level] + (isset(LIST_LANGS[$ref['show'] ?? '']) ? ['show' => $ref['show']] : [])
        + (isset(LIST_LANGS[$ref['lang'] ?? '']) ? ['lang' => $ref['lang']] : []);
}

/** הרשימות שהמסמך צריך (לסינון המקונן בדפדפן): {id: {name, levels, rows}}. */
function lists_for(array $schema, bool $full = false): array
{
    $out = [];
    foreach (schema_lists($schema) as $id) {
        $st = db()->prepare('SELECT * FROM value_lists WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]);
        if (!$r = $st->fetch()) continue;
        // רשימה גדולה — בלי השורות: הטופס מבקש מהשרת רק את האפשרויות של הרמה (list_opts)
        if (!$full && (int)$r['row_count'] > LIST_INLINE) { $v = list_view($r); $out[(string)$id] = ['name' => $v['name'], 'levels' => $v['levels'], 'big' => true, 'translations' => list_view($r, true)['translations']]; continue; }
        $v = list_view($r, true);
        $out[(string)$id] = ['name' => $v['name'], 'levels' => $v['levels'], 'rows' => $v['rows'], 'translations' => $v['translations']];
    }
    return $out;
}

/** השורות של רשימה (מפוענחות, פעם אחת לבקשה). */
function list_rows_by_id(int $id): ?array
{
    static $cache = [];
    if (!array_key_exists($id, $cache)) {
        $st = db()->prepare('SELECT rows_enc FROM value_lists WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]);
        $cache[$id] = ($r = $st->fetchColumn()) ? (json_decode((string)unseal($r), true) ?: []) : null;
    }
    return $cache[$id];
}

/**
 * האפשרויות ברמה, מסוננות לפי מה שנבחר ברמות שמעל — לרשימה גדולה (שלא נשלחת כולה לדפדפן).
 * מותר רק לרשימה שהמסמך משתמש בה: למנהל המסמך (doc), או לממלא בקישור (X-Fill-Token).
 * {doc, list, level, known: {"<רמה>": "<ערך>"}}
 */
function list_opts(?array $session, array $b): array
{
    if (!empty($_SERVER['HTTP_X_FILL_TOKEN'])) {
        [, , $schema] = fill_context();
    } else {
        if (!$session) throw new ApiError('נדרשת כניסה', 401);
        $d = doc_owned((int)($b['doc'] ?? 0), $session, 'view');
        $schema = json_decode((string)unseal($d['schema_enc']), true) ?: [];
    }
    $id = (int)($b['list'] ?? 0);
    if (!in_array($id, schema_lists($schema), true)) throw new ApiError('הרשימה לא נמצאה', 404);
    $rows = list_rows_by_id($id) ?? [];
    $level = (int)($b['level'] ?? 0);
    $known = [];
    foreach ((array)($b['known'] ?? []) as $lv => $v) if ((int)$lv < $level && (string)$v !== '') $known[(int)$lv] = (string)$v;
    $vals = [];
    foreach ($rows as $r) {
        if (($r[$level] ?? '') === '') continue;
        foreach ($known as $lv => $v) if (($r[$lv] ?? '') !== $v) continue 2;
        $vals[$r[$level]] = true;
    }
    $vals = array_map('strval', array_keys($vals));
    return ['ok' => true, 'values' => array_slice($vals, 0, 5000), 'more' => count($vals) > 5000];
}

/** הערכים ברמה (בלי כפולים, לפי הסדר) — לאפשרויות של שדה בחירה ולבדיקה בשרת. */
function list_level_values(array $ref): array
{
    $rows = list_rows_by_id((int)$ref['id']);
    if ($rows === null) return [];
    $vals = [];
    foreach ($rows as $row) if (($row[$ref['level']] ?? '') !== '') $vals[$row[$ref['level']]] = true;
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

/**
 * כותרת של עמודה לפלט בשפה אחרת: "בית עלמין (en)", "בית עלמין (אנגלית)", "בית עלמין באנגלית", "בית עלמין - English".
 * מחזיר [שם הרמה, שפה] — או [הכותרת, null] כשזו לא עמודה לפלט.
 */
function list_col_lang(string $h): array
{
    static $words = ['en' => 'en|eng|english|אנגלית|אנגלי', 'fr' => 'fr|french|français|francais|צרפתית', 'ar' => 'ar|arabic|ערבית',
        'ru' => 'ru|russian|רוסית', 'es' => 'es|spanish|español|espanol|ספרדית', 'de' => 'de|german|deutsch|גרמנית'];
    foreach ($words as $lang => $w) {
        if (preg_match('/^(.+?)[\s\-–—_\/|:,]*[\(\[]\s*(?:ב)?(?:' . $w . ')\s*[\)\]]$/iu', $h, $m)
            || preg_match('/^(.+?)[\s\-–—_\/|:,]+(?:ב)?(?:' . $w . ')$/iu', $h, $m)) {
            $base = trim($m[1]);
            if ($base !== '') return [$base, $lang];
        }
    }
    return [$h, null];
}
