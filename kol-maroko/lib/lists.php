<?php
/**
 * N3 — רשימות נוספות ("פתגמים"): טבלה, קובץ data/<key>.json, שדות, אקסל, סדר, סל — לכל רשימה (06 §2.5–§2.6).
 *
 * המודל (מיגרציה 20): lists (key, fid, label, item_label, next_id), list_fields (כמו word_fields + list), list_items
 * (list, id, data JSON), list_trash (item / field / list). המילים **אינן** שורה כאן — הן נשארות בטבלאות שלהן.
 *
 * מנוע אחד, שני מתאמים: list_adapter('words') מעביר לפונקציות words_* של lib/lexicon.php (כך data/words.json נשאר זהה
 * בבייטים, וכל בדיקות N1/N2 עוברות כמו שהן); list_adapter('<key>') — המנוע שבקובץ הזה, על list_items. החלקים המשותפים
 * נקראים מאותו מקום: wj_scan (JSON עם מיקומים), field_parse / field_input / field_key_error (השדות), words_order_assign
 * (הסדר), words_csv_read / csv_cell (אקסל), sanitize_rich.
 *
 * כלל העל: מפתח רשימה ומפתח שדה נכתבים ל-HTML ("➕ הוסף לעמוד") — רק [a-z][a-z0-9_] (בדיקה בשרת), וכל טקסט אחר (שם,
 * כיתוב, ערך) עובר escape לתכונה ולטקסט.
 */

declare(strict_types=1);

const LIST_KEY_RX = '/^[a-z][a-z0-9_]{0,29}$/';
/** words — המילים; השאר — שמות שהעמוד / הכלי כבר משתמשים בהם (קבצי מערכת, תיקיות, הקובץ של kol-data.js) */
const LIST_RESERVED = ['words', 'topics', 'items', 'kol', 'kol_data', 'data', 'media', 'css', 'js', 'index', 'page', 'preview', 'list', 'lists', 'fields', 'forms'];
const LIST_FILE_BASE = 1000;                  // המזהה בעץ של הקובץ הווירטואלי: -(1000 + fid). PF_WORDS_ID = -1
const LIST_FIELD_RESERVED = ['items'];        // בנוסף ל-FIELD_RESERVED: "items" הוא מפתח בראש הקובץ
const LIST_AUDIT = ['list_add', 'list_edit', 'list_key', 'list_delete', 'list_file', 'list_csv', 'list_item_add', 'list_item_edit', 'list_item_delete', 'list_item_move',
    'list_field_add', 'list_field_edit', 'list_field_key', 'list_field_delete', 'trash_restore', 'snapshot_restore'];
const LIST_PLACE_WHERE = ['main', 'body', 'after'];

// ── המתאם ─────────────────────────────────────────────────

/**
 * מנוע אחד, שני מתאמים. אותן פעולות לכל רשימה: json (הקובץ), rev, file_save (קובץ ← טבלה), csv / csv_import, fields.
 * 'words' — הפונקציות של היום (בלי שינוי התנהגות); כל רשימה אחרת — list_items.
 */
function list_adapter(string $key): array
{
    if ($key === 'words') return [
        'key' => 'words', 'json' => fn(?string $app = null) => words_json($app), 'rev' => fn(?string $j = null) => words_rev($j),
        'file_save' => fn(array $user, string $bytes, array $b) => words_file_save($user, $bytes, $b),
        'csv' => fn() => words_csv(), 'csv_import' => fn(array $user, array $b) => words_csv_import($user, $b), 'fields' => fn() => fields_all(),
    ];
    list_get($key);
    return [
        'key' => $key, 'json' => fn(?string $app = null) => list_json($key, $app), 'rev' => fn(?string $j = null) => list_rev($key, $j),
        'file_save' => fn(array $user, string $bytes, array $b) => list_file_save($user, $key, $bytes, $b),
        'csv' => fn() => list_csv($key), 'csv_import' => fn(array $user, array $b) => list_csv_import($user, $key, $b), 'fields' => fn() => list_fields($key),
    ];
}

// ── קריאה ─────────────────────────────────────────────────

function lists_all(): array
{
    $out = [];
    foreach (db()->query('SELECT * FROM lists ORDER BY sort_order, key')->fetchAll() as $r) $out[$r['key']] = list_norm($r);
    return $out;
}

function list_norm(array $r): array
{
    return ['key' => (string)$r['key'], 'fid' => (int)$r['fid'], 'label' => (string)$r['label'], 'item_label' => (string)$r['item_label'],
        'next_id' => (int)$r['next_id'], 'sort_order' => (int)$r['sort_order'], 'created_at' => $r['created_at'], 'created_by' => $r['created_by']];
}

function list_get(string $key): array
{
    $st = db()->prepare('SELECT * FROM lists WHERE key = ?');
    $st->execute([$key]);
    $r = $st->fetch();
    if (!$r) throw new ApiError('הרשימה לא נמצאה', 404);
    return list_norm($r);
}

/** הפרמטר "list" מהבקשה: null / '' / "words" — המילים; אחרת — רשימה קיימת (404 אם אין). */
function list_param(array $q): ?string
{
    $k = $q['list'] ?? null;
    if ($k === null || $k === '' || $k === 'words') return null;
    if (!is_string($k) || !preg_match(LIST_KEY_RX, $k)) throw new ApiError('שם רשימה לא תקין');
    list_get($k);
    return $k;
}

function list_fields(string $key): array
{
    $st = db()->prepare('SELECT * FROM list_fields WHERE list = ? ORDER BY sort_order, key');
    $st->execute([$key]);
    return array_map(fn($r) => field_norm($r + ['col' => null]), $st->fetchAll());
}

/** הפריטים לפי הסדר: [id, data (מערך), sort_order, updated_at, updated_by]. */
function list_rows(string $key): array
{
    $st = db()->prepare('SELECT id, data, sort_order, updated_at, updated_by FROM list_items WHERE list = ? ORDER BY sort_order, id');
    $st->execute([$key]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['sort_order'] = (int)$r['sort_order'];
        $x = json_decode((string)$r['data'], true);
        $r['data'] = is_array($x) && !array_is_list($x) ? $x : [];
    }
    unset($r);
    return $rows;
}

function list_file_ids(array $rows, array $fields): array
{
    $ids = [];
    foreach ($fields as $f) if (in_array($f['type'], ['image', 'audio'], true)) foreach ($rows as $r) if (file_id($r['data'][$f['key']] ?? null)) $ids[] = $r['data'][$f['key']];
    return array_values(array_unique($ids));
}

/** הערך כפי שהוא בקובץ. image — media/images/<id>.<ext>; audio — media/audio/<list>-<item>-<key>.<ext> (06 §2.3); $app — כתובת להשמעה. */
function list_out(string $list, int $id, array $f, mixed $v, array $meta, ?string $app): mixed
{
    if (!in_array($f['type'], ['image', 'audio'], true) || !is_string($v) || !file_id($v)) return $v;
    if ($app !== null) return $app . media_url($v);
    $m = $meta[$v] ?? null;
    if (!$m) return $v;
    return $f['type'] === 'image' ? "media/images/$v.{$m['ext']}" : "media/audio/$list-$id-{$f['key']}.{$m['ext']}";
}

/**
 * data/<key>.json — מהטבלה, ברגע הקריאה (כמו words_json). $app: null — הקובץ בעץ (כל השדות); מחרוזת — התצוגה החיה והבנייה
 * (רק "מתפרסם", קבצים — כתובת). בראש הקובץ: לכל שדה בחירה — [{id, label}] (טבלת תוויות של kol-data.js), ואז items.
 */
function list_json(string $key, ?string $app = null): string
{
    $fields = list_fields($key);
    $use = array_values(array_filter($fields, fn($f) => $app === null || $f['published']));
    $rows = list_rows($key);
    $meta = $app === null ? files_meta(list_file_ids($rows, $use)) : [];
    $j = [];
    foreach ($use as $f) if ($f['type'] === 'choice') $j[$f['key']] = array_map(fn($o) => ['id' => (string)$o['value'], 'label' => (string)$o['label']], $f['options']);
    $items = [];
    foreach ($rows as $r) {
        $item = ['id' => $r['id']];
        foreach ($use as $f) if (array_key_exists($f['key'], $r['data'])) $item[$f['key']] = list_out($key, $r['id'], $f, $r['data'][$f['key']], $meta, $app);
        $items[] = $item;
    }
    return words_json_encode($j + ['items' => $items]);
}

function list_rev(string $key, ?string $json = null): string
{
    return 'l' . substr(hash('sha256', ($json ?? list_json($key)) . '|' . json_encode(list_fields($key), JSON_UNESCAPED_UNICODE)), 0, 16);
}

/** הרשימה כפי שהממשק רואה אותה. */
function list_public(array $l): array
{
    $st = db()->prepare('SELECT COUNT(*) FROM list_items WHERE list = ?');
    $st->execute([$l['key']]);
    $path = array_search($l['key'], pf_list_paths(), true);
    return ['key' => $l['key'], 'label' => $l['label'], 'item_label' => $l['item_label'], 'count' => (int)$st->fetchColumn(),
        'file' => $path === false ? null : $path, 'file_id' => -(LIST_FILE_BASE + $l['fid'])];
}

/** בורר הרשימות (📚 נתונים): המילים ראשונות, ואחריהן כל רשימה. */
function lists_state(array $user): array
{
    $words = (int)db()->query('SELECT COUNT(*) FROM words')->fetchColumn();
    return ['lists' => array_merge([['key' => 'words', 'label' => 'מילים', 'item_label' => 'מילה', 'count' => $words, 'file' => pf_seeded() ? pf_words_path() : null, 'file_id' => -1, 'words' => true]],
        array_values(array_map('list_public', lists_all()))), 'can_delete_full' => ROLE_RANK[$user['role'] ?? 'editor'] >= ROLE_RANK['publisher']];
}

/** הטבלה של רשימה אחת: השדות, הפריטים (עם כתובת לכל קובץ — לתצוגה בטבלה) ו-rev. */
function list_items_state(string $key): array
{
    $l = list_get($key);
    $fields = list_fields($key);
    $rows = list_rows($key);
    $items = [];
    foreach ($rows as $r) {
        $src = [];
        foreach ($fields as $f) if (in_array($f['type'], ['image', 'audio'], true) && file_id($r['data'][$f['key']] ?? null)) $src[$f['key']] = media_url($r['data'][$f['key']]);
        $items[] = ['id' => $r['id'], 'data' => (object)$r['data'], 'src' => (object)$src, 'sort_order' => $r['sort_order'], 'updated_at' => $r['updated_at'], 'updated_by' => $r['updated_by']];
    }
    return ['list' => list_public($l), 'fields' => list_fields_public($key, $rows), 'items' => $items, 'rev' => list_rev($key)];
}

function list_fields_public(string $key, ?array $rows = null): array
{
    $rows ??= list_rows($key);
    $out = [];
    foreach (list_fields($key) as $f) {
        $n = 0;
        foreach ($rows as $r) { $v = $r['data'][$f['key']] ?? null; if ($v !== null && $v !== '' && $v !== []) $n++; }
        $out[] = $f + ['filled' => $n, 'builtin' => false];
    }
    return $out;
}

// ── יצירה, שינוי, מחיקה של רשימה ──────────────────────────

/** מפתח חדש של רשימה: התבנית, השמורים, הקיימים. */
function list_key_error(string $key, ?string $self = null): ?string
{
    if (!preg_match(LIST_KEY_RX, $key)) return 'מפתח לא תקין "' . mb_substr($key, 0, 40) . '" — אותיות לטיניות קטנות, ספרות ו-_ , מתחיל באות, עד 30';
    if (in_array($key, LIST_RESERVED, true)) return "המפתח \"$key\" שמור למערכת";
    if ($key !== $self) {
        $st = db()->prepare('SELECT 1 FROM lists WHERE key = ?');
        $st->execute([$key]);
        if ($st->fetchColumn()) return "כבר יש רשימה עם המפתח \"$key\"";
    }
    return null;
}

function list_field_key_error(string $key, array $fields, ?string $self = null): ?string
{
    if (in_array($key, LIST_FIELD_RESERVED, true)) return "המפתח \"$key\" שמור למערכת";
    return field_key_error($key, $fields, $self);
}

/** תעתיק לטיני פשוט — מפתח מוצע מהשם (כמו keyFrom ב-fields.js). */
function list_key_from(string $label, array $taken, string $prefix = 'field', int $max = 40): string
{
    static $tr = ['א' => 'a', 'ב' => 'b', 'ג' => 'g', 'ד' => 'd', 'ה' => 'h', 'ו' => 'v', 'ז' => 'z', 'ח' => 'ch', 'ט' => 't', 'י' => 'y', 'כ' => 'k', 'ך' => 'k', 'ל' => 'l',
        'מ' => 'm', 'ם' => 'm', 'נ' => 'n', 'ן' => 'n', 'ס' => 's', 'ע' => 'a', 'פ' => 'p', 'ף' => 'f', 'צ' => 'tz', 'ץ' => 'tz', 'ק' => 'k', 'ר' => 'r', 'ש' => 'sh', 'ת' => 't'];
    $k = strtr(mb_strtolower($label), $tr);
    $k = trim((string)preg_replace('/[^a-z0-9]+/', '_', $k), '_');
    $k = substr((string)preg_replace('/^[^a-z]+/', '', $k), 0, $max);
    $k = rtrim($k, '_');
    if ($k === '' || in_array($k, $taken, true) || in_array($k, FIELD_RESERVED, true) || in_array($k, LIST_FIELD_RESERVED, true)) {
        $base = $k !== '' ? substr($k, 0, $max - 4) : $prefix;
        for ($n = 2; in_array("{$base}_$n", $taken, true); $n++);
        $k = "{$base}_$n";
    }
    return $k;
}

/** הנתיב של קובץ הרשימה בעץ: .json, נתיב תקין, ואינו תפוס (קובץ רגיל, המילים, רשימה אחרת). */
function list_path_check(string $file, ?string $self = null): string
{
    $path = pf_path($file);
    if (!preg_match('/\.json$/', $path)) throw new ApiError('הקובץ של רשימה — .json');
    foreach (pf_list_paths() as $p => $k) if (pf_key($p) === pf_key($path) && $k !== $self) throw new ApiError("\"$path\" כבר מקושר לרשימה \"$k\"", 409);
    if (pf_by_path($path)) throw new ApiError("כבר יש קובץ \"$path\" בפרויקט — בחרו נתיב אחר, או מחקו אותו", 409);
    return $path;
}

/** kol.json ← data: שינוי ב-config (הפונקציה מקבלת ומחזירה את data), נשמר דרך מנוע הקבצים (גרסה בהיסטוריה). בתוך pf_tx. */
function list_kol_data(array $user, callable $fn, string $note): void
{
    $kol = pf_kol();
    $r = pf_by_path('kol.json');
    if (!$kol['valid'] || !$r) throw new ApiError('kol.json אינו תקין — תקנו אותו קודם (📁 קבצים)', 409);
    $c = $kol['config'];
    $data = is_array($c['data'] ?? null) ? $c['data'] : [];
    $data = $fn($data);
    if ($data) $c['data'] = $data; else unset($c['data']);
    $bytes = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    pf_put(pf_row((int)$r['id']), $bytes, $user, $note);
}

/**
 * ➕ רשימה חדשה. label, item_label, key (נגזר בממשק), file (ברירת מחדל data/<key>.json). נפתחת עם שדה אחד, title (טקסט קצר,
 * חובה, מתפרסם) — או, ב"מאקסל", עם השדות מהגיליון והפריטים, באותה עסקה (csv_b64 + columns; בלי apply — תצוגה מקדימה).
 */
function list_create(array $user, array $b): array
{
    pf_seed($user);
    pf_guard($user);
    $label = str(preg_replace('/\s+/u', ' ', (string)($b['label'] ?? '')), 80);
    if ($label === '') throw new ApiError('חסר שם לרשימה');
    $item = str(preg_replace('/\s+/u', ' ', (string)($b['item_label'] ?? '')), 80);
    $key = (string)($b['key'] ?? '');
    if ($e = list_key_error($key)) throw new ApiError($e);
    $file = list_path_check((string)($b['file'] ?? "data/$key.json"));
    $csv = null;
    if (isset($b['csv_b64'])) {
        $raw = base64_decode((string)$b['csv_b64'], true);
        if ($raw === false) throw new ApiError('הקובץ (base64) לא תקין');
        $csv = list_csv_new($raw, is_array($b['columns'] ?? null) ? $b['columns'] : null);
        if (empty($b['apply'])) return ['preview' => $csv['preview'], 'fields' => $csv['fields'], 'errors' => $csv['errors'], 'encoding' => $csv['encoding'], 'count' => count($csv['items'])];
        if ($csv['errors']) throw new ApiError('הגיליון לא יובא — ' . count($csv['errors']) . ' שגיאות', 422, ['errors' => array_slice($csv['errors'], 0, 200)]);
    }
    $snap = $csv && count($csv['items']) > WORDS_BULK ? snapshot_create($user, "לפני יצירת הרשימה \"$label\" מאקסל") : null;
    pf_tx(function () use ($user, $key, $label, $item, $file, $csv) {
        $pdo = db();
        $fid = (int)$pdo->query('SELECT COALESCE(MAX(fid), 0) + 1 FROM lists')->fetchColumn();
        $fid = max($fid, (int)(setting('lists_fid') ?? 0) + 1);         // מזהה בעץ אינו חוזר גם אחרי מחיקת רשימה
        setting_set('lists_fid', (string)$fid);
        $so = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM lists')->fetchColumn();
        $pdo->prepare('INSERT INTO lists (key, fid, label, item_label, next_id, sort_order, created_at, created_by) VALUES (?, ?, ?, ?, 1, ?, ?, ?)')
            ->execute([$key, $fid, $label, $item, $so, now_iso(), $user['name'] ?? '']);
        $fields = $csv ? $csv['fields'] : [['key' => 'title', 'label' => $item !== '' ? $item : 'כותרת', 'type' => 'text', 'required' => true]];
        $ins = $pdo->prepare('INSERT INTO list_fields (list, key, label, type, required, published, sort_order) VALUES (?, ?, ?, ?, ?, 1, ?)');
        foreach ($fields as $i => $f) $ins->execute([$key, $f['key'], $f['label'], $f['type'], (int)!empty($f['required']), ($i + 1) * 10]);
        if ($csv) {
            $st = $pdo->prepare('INSERT INTO list_items (list, id, data, sort_order, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($csv['items'] as $i => $d) $st->execute([$key, $i + 1, list_data_json($d), ($i + 1) * WORDS_ORDER_STEP, now_iso(), $user['name'] ?? '']);
            $pdo->prepare('UPDATE lists SET next_id = ? WHERE key = ?')->execute([count($csv['items']) + 1, $key]);
        }
        list_kol_data($user, fn(array $d) => $d + [$key => ['file' => $file, 'source' => "list:$key"]], "הוספת הרשימה $label ($key) — data.$key ב-kol.json");
    });
    audit_as($user, 'list_add', "$key ($label) · $file" . ($csv ? ' · מאקסל: ' . count($csv['items']) . ' פריטים, ' . count($csv['fields']) . ' שדות' : ''));
    return lists_state($user) + ['created' => $key, 'snapshot' => $snap];
}

/** שם, שם לפריט, סדר הרשימות (order). המפתח — דרך list_key_apply. */
function list_save(array $user, array $b): array
{
    if (isset($b['order'])) {
        $keys = array_values(array_filter(is_array($b['order']) ? $b['order'] : [], 'is_string'));
        $have = array_keys(lists_all());
        if (count($keys) !== count($have) || array_diff($have, $keys)) throw new ApiError('הסדר חייב לכלול כל רשימה פעם אחת');
        pf_tx(function () use ($keys) { $st = db()->prepare('UPDATE lists SET sort_order = ? WHERE key = ?'); foreach ($keys as $i => $k) $st->execute([($i + 1) * 10, $k]); });
        audit_as($user, 'list_edit', 'סדר הרשימות: ' . implode(', ', $keys));
        return lists_state($user);
    }
    $l = list_get((string)($b['key'] ?? ''));
    $label = array_key_exists('label', $b) ? str(preg_replace('/\s+/u', ' ', (string)$b['label']), 80) : $l['label'];
    if ($label === '') throw new ApiError('חסר שם לרשימה');
    $item = array_key_exists('item_label', $b) ? str(preg_replace('/\s+/u', ' ', (string)$b['item_label']), 80) : $l['item_label'];
    db()->prepare('UPDATE lists SET label = ?, item_label = ? WHERE key = ?')->execute([$label, $item, $l['key']]);
    audit_as($user, 'list_edit', "{$l['key']}: $label" . ($item !== '' ? " / $item" : ''));
    return lists_state($user);
}

/**
 * מחיקת רשימה — לסל, כולה (השורה, השדות, הפריטים), עם נקודת שחזור; הרישום ב-kol.json מוסר. dry — מה יימחק, וכמה קשירות
 * בקבצי העמוד יישארו בלי נתונים. רשימה עם פריטים — מפרסם.
 */
function list_delete(array $user, array $b): array
{
    $l = list_get((string)($b['key'] ?? ''));
    $key = $l['key'];
    $items = list_rows($key);
    $binds = count(array_filter(lists_bind_scan($key), fn($h) => !$h['manual']));
    $needs = $items ? 'publisher' : 'editor';
    if (!empty($b['dry'])) return ['items' => count($items), 'fields' => count(list_fields($key)), 'bindings' => $binds, 'needs' => $needs];
    if (ROLE_RANK[$user['role'] ?? 'editor'] < ROLE_RANK[$needs]) throw new ApiError('מחיקת רשימה שיש בה פריטים (' . count($items) . ') — רק למפרסם', 403);
    pf_guard($user);
    $snap = snapshot_create($user, "לפני מחיקת הרשימה \"{$l['label']}\" ($key)");
    pf_tx(function () use ($user, $key, $l) {
        $pdo = db();
        $q = fn(string $sql) => (function () use ($pdo, $sql, $key) { $st = $pdo->prepare($sql); $st->execute([$key]); return $st->fetchAll(); })();
        $row = ['list' => $q('SELECT * FROM lists WHERE key = ?')[0], 'fields' => $q('SELECT * FROM list_fields WHERE list = ?'), 'items' => $q('SELECT * FROM list_items WHERE list = ?'),
            'kol' => (pf_kol()['config']['data'] ?? [])[$key] ?? null];
        $pdo->prepare('INSERT INTO list_trash (list, kind, item_id, row, at, by_name) VALUES (?, ?, NULL, ?, ?, ?)')
            ->execute([$key, 'list', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now_iso(), $user['name'] ?? '']);
        foreach (['list_items', 'list_fields'] as $t) $pdo->prepare("DELETE FROM $t WHERE list = ?")->execute([$key]);
        $pdo->prepare('DELETE FROM lists WHERE key = ?')->execute([$key]);
        // הסל של הפריטים / השדות שלה נשאר (שחזור הרשימה מחזיר אותם למקום)
        list_kol_data($user, function (array $d) use ($key) {
            foreach ($d as $k => $v) if ($k === $key || (is_array($v) && ($v['source'] ?? '') === "list:$key")) unset($d[$k]);
            return $d;
        }, "הסרת הרשימה {$l['label']} ($key) — data.$key הוסר מ-kol.json");
    });
    audit_as($user, 'list_delete', "$key ({$l['label']}) · " . count($items) . ' פריטים → לסל');
    return lists_state($user) + ['snapshot' => $snap, 'bindings' => $binds];
}

// ── פריטים בטבלה ──────────────────────────────────────────

function list_data_json(array $d): string
{
    ksort($d);
    return $d ? json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';
}

/**
 * ערכים מהטבלה (JSON — הצורה הקנונית) ← data. רק המפתחות שנשלחו משתנים. שגיאה — ApiError. בחירה שאינה ברשימה — שגיאה
 * (הוספת אפשרות — ב"מבנה"). image / audio — מזהה קובץ בספרייה (Media.pick).
 */
function list_values_in(array $fields, mixed $sent, array $cur, bool $new): array
{
    if ($sent !== null && (!is_array($sent) || ($sent && array_is_list($sent)))) throw new ApiError('ערכים — אובייקט {מפתח: ערך}');
    $by = array_column($fields, null, 'key');
    $out = $cur;
    if ($new) foreach ($fields as $f) if ($f['def'] !== null) $out[$f['key']] = $f['def'];
    foreach ((array)$sent as $k => $v) {
        $f = $by[(string)$k] ?? throw new ApiError("אין שדה \"" . mb_substr((string)$k, 0, 40) . '"');
        [$val, $err, $warn, $propose] = field_parse($f, $v);
        if ($propose !== null) throw new ApiError("\"{$f['label']}\": \"$propose\" אינו אחת האפשרויות — הוסיפו אותה ב-🧩 מבנה");
        if ($err) throw new ApiError($err);
        if ($warn && in_array($f['type'], ['image', 'audio'], true)) throw new ApiError($warn);
        if ($val === null) unset($out[$f['key']]); else $out[$f['key']] = $val;
    }
    foreach ($fields as $f) if ($f['required'] && (($out[$f['key']] ?? null) === null || ($out[$f['key']] ?? null) === '' || ($out[$f['key']] ?? null) === [])) throw new ApiError("\"{$f['label']}\" הוא שדה חובה");
    ksort($out);
    return $out;
}

/** הוספה / עריכה של פריט מהטבלה (עורך). id — עריכה; בלי — פריט חדש בסוף, עם המזהה הבא של הרשימה. */
function list_item_save(array $user, array $b): array
{
    $l = list_get((string)($b['list'] ?? ''));
    $key = $l['key'];
    $fields = list_fields($key);
    $id = isset($b['id']) && $b['id'] !== null ? (int)$b['id'] : null;
    $out = pf_tx(function () use ($user, $key, $fields, $id, $b) {
        $pdo = db();
        $now = now_iso();
        if ($id !== null) {
            $st = $pdo->prepare('SELECT data FROM list_items WHERE list = ? AND id = ?');
            $st->execute([$key, $id]);
            $cur = $st->fetchColumn();
            if ($cur === false) throw new ApiError('הפריט לא נמצא', 404);
            $cur = json_decode((string)$cur, true) ?: [];
            $d = list_values_in($fields, $b['data'] ?? [], $cur, false);
            if ($d === $cur) return [$id, false];
            $pdo->prepare('UPDATE list_items SET data = ?, updated_at = ?, updated_by = ? WHERE list = ? AND id = ?')->execute([list_data_json($d), $now, $user['name'] ?? '', $key, $id]);
            return [$id, true];
        }
        $d = list_values_in($fields, $b['data'] ?? [], [], true);
        $st = $pdo->prepare('SELECT next_id FROM lists WHERE key = ?');
        $st->execute([$key]);
        $nid = (int)$st->fetchColumn();
        $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + ? FROM list_items WHERE list = ?');
        $st->execute([WORDS_ORDER_STEP, $key]);
        $pdo->prepare('INSERT INTO list_items (list, id, data, sort_order, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$key, $nid, list_data_json($d), (int)$st->fetchColumn(), $now, $user['name'] ?? '']);
        $pdo->prepare('UPDATE lists SET next_id = ? WHERE key = ?')->execute([$nid + 1, $key]);
        return [$nid, true];
    });
    [$iid, $changed] = $out;
    if ($changed) audit_as($user, $id === null ? 'list_item_add' : 'list_item_edit', "{$l['label']} ($key) #$iid");
    return list_items_state($key) + ['id' => $iid];
}

/** פריט לסל (עורך). */
function list_item_delete(array $user, array $b): array
{
    $l = list_get((string)($b['list'] ?? ''));
    $ids = array_values(array_unique(array_filter(array_map('intval', is_array($b['ids'] ?? null) ? $b['ids'] : [$b['id'] ?? 0]), fn($x) => $x > 0)));
    if (!$ids) throw new ApiError('לא נבחר פריט');
    if (count($ids) > WORDS_BULK && ROLE_RANK[$user['role'] ?? 'editor'] < ROLE_RANK['publisher']) throw new ApiError('מחיקה של יותר מ-' . WORDS_BULK . ' — רק למפרסם', 403);
    $snap = count($ids) > 1 ? snapshot_create($user, 'לפני מחיקת ' . count($ids) . " פריטים מ\"{$l['label']}\"") : null;
    pf_tx(function () use ($user, $l, $ids) {
        $pdo = db();
        $get = $pdo->prepare('SELECT * FROM list_items WHERE list = ? AND id = ?');
        foreach ($ids as $id) {
            $get->execute([$l['key'], $id]);
            $row = $get->fetch() ?: throw new ApiError("הפריט $id לא נמצא", 404);
            $pdo->prepare('INSERT INTO list_trash (list, kind, item_id, row, at, by_name) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$l['key'], 'item', $id, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now_iso(), $user['name'] ?? '']);
            $pdo->prepare('DELETE FROM list_items WHERE list = ? AND id = ?')->execute([$l['key'], $id]);
        }
    });
    audit_as($user, 'list_item_delete', "{$l['label']} ({$l['key']}) · " . implode(', ', array_map(fn($i) => "#$i", $ids)) . ' → לסל');
    return list_items_state($l['key']) + ['snapshot' => $snap];
}

/** גרירה בטבלה / "העבר ל…" — כמו word_move: ids לפני before, או למקום to (1 = ראשון). נכתב רק מה שזז. */
function list_item_move(array $user, array $b): array
{
    $l = list_get((string)($b['list'] ?? ''));
    $key = $l['key'];
    $ids = array_values(array_unique(array_filter(array_map('intval', is_array($b['ids'] ?? null) ? array_slice($b['ids'], 0, 5000) : []), fn($x) => $x > 0)));
    if (!$ids) throw new ApiError('לא נבחר פריט');
    [$set, $moved, $at] = pf_tx(function () use ($key, $ids, $b) {
        $cur = [];
        foreach (list_rows($key) as $r) $cur[$r['id']] = $r['sort_order'];
        foreach ($ids as $id) if (!isset($cur[$id])) throw new ApiError("הפריט $id לא נמצא", 404);
        $pick = array_flip($ids);
        $rest = array_values(array_filter(array_keys($cur), fn($id) => !isset($pick[$id])));
        usort($ids, fn($x, $y) => $cur[$x] <=> $cur[$y] ?: $x <=> $y);
        if (isset($b['before']) && $b['before'] !== null && $b['before'] !== '') {
            $at = array_search((int)$b['before'], $rest, true);
            if ($at === false) throw new ApiError('מקום היעד לא נמצא', 404);
        } elseif (isset($b['to'])) $at = max(0, min(count($rest), (int)$b['to'] - 1));
        else $at = count($rest);
        array_splice($rest, $at, 0, $ids);
        [$set, $moved] = words_order_assign($rest, $cur);
        $st = db()->prepare('UPDATE list_items SET sort_order = ? WHERE list = ? AND id = ?');
        foreach ($set as $id => $v) $st->execute([$v, $key, $id]);
        return [$set, $moved, $at];
    });
    if ($moved) audit_as($user, 'list_item_move', "{$l['label']} ($key) · " . count($ids) . ' → מקום ' . ($at + 1));
    return ['moved' => $moved, 'sort' => (object)$set, 'rev' => list_rev($key)];
}

// ── הקובץ: data/<key>.json ← הטבלה ────────────────────────

/** המצב שהמנוע משווה אליו. by — [id => [id, data, ref (הערכים כפי שהם בקובץ), sort_order]]. */
function list_state(string $key): array
{
    $l = list_get($key);
    $fields = list_fields($key);
    $rows = list_rows($key);
    $meta = files_meta(list_file_ids($rows, $fields));
    $by = [];
    foreach ($rows as $r) {
        $r['ref'] = [];
        foreach ($fields as $f) if (array_key_exists($f['key'], $r['data'])) $r['ref'][$f['key']] = list_out($key, $r['id'], $f, $r['data'][$f['key']], $meta, null);
        $by[$r['id']] = $r;
    }
    return ['list' => $l, 'by' => $by, 'order' => array_column($rows, 'id'), 'fields' => $fields, 'meta' => $meta];
}

function list_state_hash(array $state): string
{
    return substr(hash('sha256', json_encode([$state['list']['key'], $state['list']['next_id'], $state['order'], array_map(fn($r) => [$r['data'], $r['sort_order']], $state['by']), $state['fields']],
        JSON_UNESCAPED_UNICODE)), 0, 32);
}

/** image / audio מהקובץ: גם media/audio/<list>-<id>-<key>.* (הצורה שהקובץ עצמו כותב) ← הערך של אותו פריט. '' — ריק; null — לא נמצא. */
function list_file_resolve(string $v, array $f, array $state): ?string
{
    if (preg_match('~^(?:\./)?media/audio/([a-z][a-z0-9_]{0,29})-(\d{1,10})-([a-z][a-z0-9_]{0,39})\.[A-Za-z0-9]{1,8}$~', trim($v), $m) && $m[1] === $state['list']['key']) {
        $x = $state['by'][(int)$m[2]]['data'][$m[3]] ?? null;
        return is_string($x) && file_id($x) ? $x : null;
    }
    return field_file_resolve($v, $f['type']);
}

/**
 * הקובץ ← פריטים מנורמלים, או שגיאות עם שורה ועמודה (כמו words_file_items). ערך שזהה לערך בטבלה נשאר בדיוק כמו בטבלה.
 * מפתח לא מוכר — "צור שדה" ($opt['fields']); בחירה שאינה ברשימה — "הוסף אפשרות" ($opt['options'], "key:value").
 * בראש הקובץ: items, ולכל שדה בחירה — טבלת התוויות שלו (לקריאה; האפשרויות נערכות ב-🧩 מבנה).
 */
function list_file_items(string $bytes, array $state, array $opt = []): array
{
    $key = $state['list']['key'];
    $scan = wj_scan($bytes);
    $errors = []; $warnings = []; $fieldProps = []; $optionProps = [];
    $fields = $state['fields'];
    $by = array_column($fields, null, 'key');
    $at = function (string $path) use ($scan, $bytes): array {
        $o = $scan['pos'][$path] ?? $scan['pos'][preg_replace('~/[^/]*$~', '', $path)] ?? 0;
        return wj_linecol($bytes, $o);
    };
    $add = function (array &$list, string $path, string $msg, array $more = []) use ($at) { [$l, $c] = $at($path); $list[] = ['line' => $l, 'col' => $c, 'msg' => $msg] + $more; };
    if ($scan['error']) { [$l, $c] = wj_linecol($bytes, $scan['error'][1]); return ['errors' => [['line' => $l, 'col' => $c, 'msg' => 'JSON לא תקין: ' . $scan['error'][0]]]]; }
    $j = $scan['value'];
    $is = fn(string $path, string $ch) => ($bytes[$scan['pos'][$path] ?? -1] ?? '') === $ch;
    if (!is_array($j) || !$is('', '{')) return ['errors' => [['line' => 1, 'col' => 1, 'msg' => 'הקובץ חייב להיות אובייקט: { "items": [...] }']]];
    $choice = array_column(array_filter($fields, fn($f) => $f['type'] === 'choice'), null, 'key');
    foreach (array_keys($j) as $k) {
        if ($k === 'items') continue;
        if (!isset($choice[$k])) { $add($errors, "/$k#key", "שדה לא מוכר \"" . mb_substr((string)$k, 0, 60) . "\" בראש הקובץ — רק \"items\"" . ($choice ? ' וטבלאות הבחירה (' . implode(', ', array_keys($choice)) . ')' : '') . '. הסירו אותו כדי לשמור'); continue; }
        $want = array_map(fn($o) => ['id' => (string)$o['value'], 'label' => (string)$o['label']], $choice[$k]['options']);
        if ($j[$k] !== $want) $add($warnings, "/$k", "האפשרויות של \"$k\" נערכות ב-🧩 מבנה — השינוי כאן לא נשמר");
    }
    if (!isset($j['items']) || !is_array($j['items']) || !array_is_list($j['items']) || !$is('/items', '[')) {
        $add($errors, isset($j['items']) ? '/items' : '', '"items" חסר, או שאינו רשימה');
        return ['errors' => $errors];
    }
    $unknown = [];
    foreach ($j['items'] as $x => $it) if (is_array($it)) foreach ($it as $k => $v) {
        $k = (string)$k;
        if ($k === 'id' || isset($by[$k])) continue;
        $unknown[$k]['values'][] = $v;
        $unknown[$k]['x'] ??= $x;
    }
    foreach ($unknown as $k => $u) {
        $p = "/items/{$u['x']}/$k#key";
        if ($e = list_field_key_error((string)$k, $fields)) { $add($errors, $p, "שדה לא מוכר \"" . mb_substr((string)$k, 0, 60) . "\" — $e"); continue; }
        $type = field_guess($u['values']);
        $fieldProps[$k] = ['key' => $k, 'type' => $type, 'line' => $at($p)[0], 'count' => count(array_filter($u['values'], fn($v) => $v !== null && $v !== ''))];
        if (in_array($k, $opt['fields'] ?? [], true)) $by[$k] = field_proposed((string)$k, $type);
        else $add($errors, $p, "שדה לא מוכר \"$k\" — אשרו \"צור שדה $k\" (סוג: $type), או הסירו אותו", ['propose_field' => $k]);
    }
    $items = []; $seen = [];
    $next = $state['list']['next_id'];
    foreach ($j['items'] as $x => $raw) {
        $p = "/items/$x";
        if (!is_array($raw) || !$is($p, '{')) { $add($errors, $p, 'פריט חייב להיות אובייקט'); continue; }
        $id = $raw['id'] ?? null;
        if ($id !== null && (!is_int($id) || $id < 1 || $id > 2147483647)) { $add($errors, "$p/id", '"id" חייב להיות מספר שלם חיובי (או להיעדר — פריט חדש)'); continue; }
        if ($id !== null && isset($seen[$id])) { $add($errors, "$p/id", "\"id\": $id מופיע פעמיים (גם בפריט " . ($seen[$id] + 1) . ')'); continue; }
        if ($id !== null) $seen[$id] = $x;
        $cur = $id !== null ? ($state['by'][$id] ?? null) : null;
        if ($id !== null && !$cur) {
            if ($id < $next) { $add($errors, "$p/id", "\"id\": $id כבר היה בשימוש ברשימה (פריט שנמחק — שחזור מהסל). לפריט חדש — הסירו את \"id\""); continue; }
            $add($warnings, "$p/id", "אין פריט עם \"id\": $id — יתווסף פריט חדש עם המזהה הזה");
        }
        $data = [];
        foreach ($by as $k => $f) {
            if (!array_key_exists($k, $raw)) { if (!$cur && $f['def'] !== null) $data[$k] = $f['def']; continue; }
            $v = $raw[$k];
            if ($cur && array_key_exists($k, $cur['ref']) && $v === $cur['ref'][$k]) { $data[$k] = $cur['data'][$k]; continue; }
            if (in_array($f['type'], ['image', 'audio'], true) && is_string($v)) {
                $r = list_file_resolve($v, $f, $state);
                if ($r === null) { $add($warnings, "$p/$k", "\"$k\": הקובץ \"" . mb_substr($v, 0, 80) . '" לא נמצא בספרייה — נשאר כמו שהיה'); if (isset($cur['data'][$k])) $data[$k] = $cur['data'][$k]; continue; }
                if ($r !== '') $data[$k] = $r;
                continue;
            }
            [$val, $err, $warn, $propose] = field_parse($f, $v);
            if ($propose !== null) $optionProps["$k:$propose"] ??= ['key' => $k, 'value' => $propose, 'line' => $at("$p/$k")[0]];
            if ($propose !== null && in_array("$k:$propose", $opt['options'] ?? [], true)) { $data[$k] = $propose; continue; }
            if ($propose !== null) { $add($errors, "$p/$k", "$err — אשרו \"הוסף אפשרות\", או תקנו", ['propose_option' => "$k:$propose"]); continue 2; }
            if ($err) { $add($errors, "$p/$k", $err); continue 2; }
            if ($warn) $add($warnings, "$p/$k", $warn);
            if ($val !== null) $data[$k] = $val;
        }
        ksort($data);
        foreach ($by as $f) if ($f['required'] && (($data[$f['key']] ?? null) === null || $data[$f['key']] === '' || $data[$f['key']] === [])) { $add($errors, $p, "\"{$f['key']}\" ({$f['label']}) הוא שדה חובה — חסר ערך"); continue 2; }
        $items[] = ['x' => $x, 'id' => $id, 'line' => $at($p)[0], 'data' => $data];
    }
    return ['errors' => $errors, 'warnings' => $warnings, 'items' => $items, 'full' => true, 'seq' => true,
        'field_proposals' => array_values($fieldProps), 'option_proposals' => array_values($optionProps),
        'create_fields' => array_values(array_filter(array_map(fn($k) => isset($fieldProps[$k]) ? ['key' => $k, 'type' => $fieldProps[$k]['type']] : null, $opt['fields'] ?? []))),
        'create_options' => array_values(array_map(fn($s) => $optionProps[$s], array_filter($opt['options'] ?? [], fn($s) => is_string($s) && isset($optionProps[$s]))))];
}

/** התוכנית — מה ישתנה לפי id (כמו words_plan), ואסימון שמזהה אותה. */
function list_plan(array $in, array $state): array
{
    $changed = []; $added = []; $trashed = []; $keep = []; $byField = [];
    $first = $state['fields'][0]['key'] ?? null;
    $name = fn(array $d, $id) => $first !== null && is_scalar($d[$first] ?? null) ? mb_substr((string)$d[$first], 0, 80) : '#' . $id;
    $short = fn($v) => $v === null ? '' : (is_array($v) ? implode(', ', array_map(fn($x) => is_scalar($x) ? (string)$x : json_encode($x), $v)) : (is_bool($v) ? ($v ? 'true' : 'false')
        : (mb_strlen((string)$v) > 300 ? mb_substr((string)$v, 0, 300) . '…' : (string)$v)));
    foreach ($in['items'] as $o) {
        $cur = $o['id'] !== null ? ($state['by'][$o['id']] ?? null) : null;
        if (!$cur) { $added[] = ['id' => $o['id'], 'word' => $name($o['data'], $o['id'] ?? 'חדש'), 'line' => $o['line'] ?? null]; continue; }
        $keep[$o['id']] = true;
        if ($o['data'] === $cur['data']) continue;
        $diff = [];
        foreach (array_unique(array_merge(array_keys($o['data']), array_keys($cur['data']))) as $k) {
            if (($o['data'][$k] ?? null) === ($cur['data'][$k] ?? null)) continue;
            $diff[$k] = [$short($cur['data'][$k] ?? null), $short($o['data'][$k] ?? null)];
            $byField[$k] = ($byField[$k] ?? 0) + 1;
        }
        $changed[] = ['id' => $o['id'], 'word' => $name($cur['data'], $o['id']), 'fields' => $diff];
    }
    if (!empty($in['full'])) foreach ($state['by'] as $id => $r) if (!isset($keep[$id])) $trashed[] = ['id' => $id, 'word' => $name($r['data'], $id)];
    $cur = array_map(fn($r) => $r['sort_order'], $state['by']);
    $set = []; $moved = 0;
    if (!empty($in['seq'])) {
        $seq = []; $nn = 0;
        foreach ($in['items'] as $o) $seq[] = $o['id'] !== null && isset($state['by'][$o['id']]) ? $o['id'] : 'new:' . $nn++;
        [$set, $moved] = words_order_assign($seq, array_intersect_key($cur, $keep));
    } elseif (!empty($in['order'])) {
        foreach ($in['order'] as $id => $v) if (isset($cur[$id]) && $cur[$id] !== $v) { $set[$id] = $v; $moved++; }
    }
    $counts = ['changed' => count($changed), 'added' => count($added), 'trashed' => count($trashed), 'moved' => $moved];
    foreach (['new_fields' => count($in['create_fields'] ?? []), 'new_options' => count($in['create_options'] ?? [])] as $k => $n) if ($n) $counts[$k] = $n;
    $plan = ['counts' => $counts, 'changed' => array_slice($changed, 0, 500), 'added' => array_slice($added, 0, 500), 'trashed' => array_slice($trashed, 0, 500),
        'by_field' => $byField, 'new_fields' => $in['create_fields'] ?? [], 'new_options' => $in['create_options'] ?? [], 'new_topics' => [], 'proposals' => [],
        'field_proposals' => $in['field_proposals'] ?? [], 'option_proposals' => $in['option_proposals'] ?? [], 'warnings' => $in['warnings'] ?? [], 'topic_updates' => [],
        'snapshot' => $counts['trashed'] > 0 || $counts['changed'] + $counts['added'] + $moved > WORDS_BULK || !empty($counts['new_fields']),
        'needs' => $counts['trashed'] > WORDS_BULK || !empty($in['replace']) ? 'publisher' : 'editor', 'list' => $state['list']['key']];
    $parts = [$counts['changed'] === 1 ? 'ישתנה פריט אחד' : "ישתנו {$counts['changed']} פריטים", $counts['added'] === 1 ? 'יתווסף 1' : "יתווספו {$counts['added']}",
        "יעברו לסל {$counts['trashed']}", "ישתנה הסדר של {$counts['moved']}"];
    if (!empty($counts['new_fields'])) $parts[] = "שדות חדשים: {$counts['new_fields']}";
    if (!empty($counts['new_options'])) $parts[] = "אפשרויות חדשות: {$counts['new_options']}";
    foreach (array_slice($byField, 0, 8, true) as $k => $n) $parts[] = "ישתנה $k " . ($n === 1 ? 'בפריט אחד' : "ב-$n פריטים");
    $plan['summary'] = implode(' · ', $parts);
    $plan['token'] = list_state_hash($state) . '.' . substr(hash('sha256', json_encode([array_map(fn($o) => [$o['id'], $o['data']], $in['items']), $set, array_column($trashed, 'id'),
        $in['create_fields'] ?? [], $in['create_options'] ?? []], JSON_UNESCAPED_UNICODE)), 0, 32);
    return [$plan, $set];
}

/** ההחלה — עסקה אחת, בדיוק כמו words_apply: התוכנית מחושבת שוב, ואם אינה זהה — 409 ושום דבר לא נכתב. */
function list_apply(array $user, string $key, callable $build, string $token, string $source): array
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $state = list_state($key);
        $msg = "הרשימה \"{$state['list']['label']}\" השתנתה מאז התצוגה — הציגו שוב את \"מה ישתנה\"";
        if (!hash_equals(list_state_hash($state), explode('.', $token)[0])) throw new ApiError($msg, 409);
        $in = $build($state);
        if (!empty($in['errors'])) throw new ApiError('יש שגיאות — דבר לא נשמר', 422, ['errors' => $in['errors']]);
        [$plan, $set] = list_plan($in, $state);
        if (!hash_equals($plan['token'], $token)) throw new ApiError($msg, 409, ['plan' => $plan]);
        if (ROLE_RANK[$user['role'] ?? 'editor'] < ROLE_RANK[$plan['needs']]) throw new ApiError('"החלף הכול", ומחיקה של יותר מ-' . WORDS_BULK . ' פריטים — רק למפרסם', 403, ['plan' => $plan]);
        $snap = $plan['snapshot'] ? snapshot_create($user, "לפני שמירת $source: " . $plan['summary']) : null;
        $now = now_iso();
        foreach ($in['create_fields'] ?? [] as $cf) {
            if (list_field_key_error($cf['key'], list_fields($key))) throw new ApiError("כבר יש שדה \"{$cf['key']}\"", 409);
            $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM list_fields WHERE list = ?');
            $st->execute([$key]);
            $pdo->prepare('INSERT INTO list_fields (list, key, label, type, sort_order) VALUES (?, ?, ?, ?, ?)')->execute([$key, $cf['key'], $cf['key'], $cf['type'], (int)$st->fetchColumn()]);
            audit_as($user, 'list_field_add', "$key.{$cf['key']} ({$cf['type']}) · מתוך $source");
        }
        foreach ($in['create_options'] ?? [] as $co) {
            $st = $pdo->prepare('SELECT options FROM list_fields WHERE list = ? AND key = ?');
            $st->execute([$key, $co['key']]);
            $opts = json_decode((string)$st->fetchColumn(), true) ?: [];
            if (!in_array($co['value'], array_column($opts, 'value'), true)) $opts[] = ['value' => $co['value'], 'label' => $co['value']];
            $pdo->prepare('UPDATE list_fields SET options = ? WHERE list = ? AND key = ?')->execute([json_encode($opts, JSON_UNESCAPED_UNICODE), $key, $co['key']]);
            audit_as($user, 'list_field_edit', "$key.{$co['key']}: אפשרות חדשה \"{$co['value']}\" · מתוך $source");
        }
        $next = $state['list']['next_id'];
        $changedIds = array_flip(array_column($plan['changed'], 'id'));
        $upd = $pdo->prepare('UPDATE list_items SET data = ?, updated_at = ?, updated_by = ? WHERE list = ? AND id = ?');
        $ins = $pdo->prepare('INSERT INTO list_items (list, id, data, sort_order, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?)');
        $maxSo = $state['by'] ? max(array_map(fn($r) => $r['sort_order'], $state['by'])) : 0;
        $nn = 0; $newIds = [];
        foreach ($in['items'] as $o) {
            $cur = $o['id'] !== null ? ($state['by'][$o['id']] ?? null) : null;
            if ($cur) { if (isset($changedIds[$o['id']])) $upd->execute([list_data_json($o['data']), $now, $user['name'] ?? '', $key, $o['id']]); continue; }
            $id = $o['id'] ?? $next;
            $next = max($next, $id + 1);
            $so = !empty($in['seq']) ? ($set['new:' . $nn++] ?? null) : null;
            $ins->execute([$key, $id, list_data_json($o['data']), $so ?? ($maxSo += WORDS_ORDER_STEP), $now, $user['name'] ?? '']);
            $newIds[] = $id;
        }
        $get = $pdo->prepare('SELECT * FROM list_items WHERE list = ? AND id = ?');
        $trash = $pdo->prepare('INSERT INTO list_trash (list, kind, item_id, row, at, by_name) VALUES (?, ?, ?, ?, ?, ?)');
        $del = $pdo->prepare('DELETE FROM list_items WHERE list = ? AND id = ?');
        foreach ($plan['trashed'] as $t) {
            $get->execute([$key, $t['id']]);
            $trash->execute([$key, 'item', $t['id'], json_encode($get->fetch(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $user['name'] ?? '']);
            $del->execute([$key, $t['id']]);
        }
        $so = $pdo->prepare('UPDATE list_items SET sort_order = ? WHERE list = ? AND id = ?');
        foreach ($set as $id => $v) if (is_int($id)) $so->execute([$v, $key, $id]);
        $pdo->prepare('UPDATE lists SET next_id = ? WHERE key = ?')->execute([$next, $key]);
        $c = $plan['counts'];
        $detail = "{$state['list']['label']} ($key) · $source · {$c['changed']} שונו, {$c['added']} נוספו, {$c['trashed']} לסל, {$c['moved']} בסדר";
        audit_as($user, $source === 'אקסל' ? 'list_csv' : 'list_file', $detail);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    return ['plan' => $plan, 'done' => $plan['counts'], 'snapshot' => $snap, 'new_ids' => $newIds];
}

/** שמירת data/<key>.json (עורך הקוד, העלאה, ZIP). בלי apply — התוכנית; עם apply + token — ההחלה. */
function list_file_save(array $user, string $key, string $bytes, array $b): array
{
    $opt = ['fields' => array_values(array_filter(is_array($b['create_fields'] ?? null) ? $b['create_fields'] : [], 'is_string')),
        'options' => array_values(array_filter(is_array($b['create_options'] ?? null) ? $b['create_options'] : [], 'is_string'))];
    $build = fn(array $state) => list_file_items($bytes, $state, $opt);
    if (empty($b['apply'])) {
        $state = list_state($key);
        $in = $build($state);
        if (!empty($in['errors'])) throw new ApiError('הקובץ לא נשמר — ' . count($in['errors']) . ' שגיאות. הטבלה לא השתנתה', 422, ['errors' => array_slice($in['errors'], 0, 200),
            'proposals' => [], 'field_proposals' => $in['field_proposals'] ?? [], 'option_proposals' => $in['option_proposals'] ?? [], 'warnings' => $in['warnings'] ?? []]);
        return ['plan' => list_plan($in, $state)[0]];
    }
    return list_apply($user, $key, $build, (string)($b['token'] ?? ''), basename((string)(array_search($key, pf_list_paths(), true) ?: "$key.json")));
}

// ── אקסל ──────────────────────────────────────────────────

function list_csv(string $key): never
{
    $fields = list_fields($key);
    $rows = list_rows($key);
    $meta = files_meta(list_file_ids($rows, $fields));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kol-maroko-' . $key . '-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map('csv_cell', ['מזהה', ...array_column($fields, 'label'), 'סדר']), ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, array_map('csv_cell', [$r['id'], ...array_map(fn($f) => list_cell($key, $r['id'], $f, $r['data'][$f['key']] ?? null, $meta), $fields), $r['sort_order']]), ',', '"', '');
    }
    fclose($out);
    exit;
}

function list_cell(string $key, int $id, array $f, mixed $v, array $meta): string
{
    if (in_array($f['type'], ['image', 'audio'], true)) return $v === null ? '' : (string)list_out($key, $id, $f, $v, $meta, null);
    return field_cell($f, $v, $meta);
}

/** הגיליון ← פריטים (כמו words_csv_items). עמודה לפי שם השדה או המפתח; "מזהה" / id; "סדר" / sort_order. */
function list_csv_items(array $rows, array $state, array $opt = []): array
{
    $key = $state['list']['key'];
    $head = array_map(fn($h) => trim((string)$h), $rows[0] ?? []);
    $fields = $state['fields'];
    $col = []; $fcol = []; $errors = []; $warnings = []; $items = []; $order = []; $seen = []; $create = []; $fieldProps = [];
    foreach (['id' => ['מזהה', 'id'], 'sort_order' => ['סדר', 'sort_order']] as $k => $names) foreach ($names as $n) if (!isset($col[$k]) && ($i = array_search($n, $head, true)) !== false) $col[$k] = $i;
    foreach ($fields as $f) if (($i = array_search($f['label'], $head, true)) !== false || ($i = array_search($f['key'], $head, true)) !== false) $fcol[$f['key']] = [$i, $f];
    $used = array_merge(array_values($col), array_column($fcol, 0));
    foreach ($head as $i => $h) {
        if ($h === '' || in_array($i, $used, true)) continue;
        $vals = array_map(fn($r) => csv_uncell(trim((string)($r[$i] ?? ''))), array_slice($rows, 1));
        $k = list_field_key_error($h, $fields) === null ? $h : null;
        $type = field_guess($vals, true);
        if ($k !== null && in_array($k, $opt['fields'] ?? [], true)) { $fcol[$k] = [$i, field_proposed($k, $type)]; $create[] = ['key' => $k, 'type' => $type]; continue; }
        $warnings[] = ['line' => 1, 'col' => $i + 1, 'msg' => "העמודה \"" . mb_substr($h, 0, 60) . '" לא תיובא — אין שדה כזה' . ($k !== null ? " (אפשר ליצור שדה \"$k\", סוג $type)" : ' (שם עמודה לשדה חדש: מפתח באנגלית)')];
        if ($k !== null) $fieldProps[] = ['key' => $k, 'type' => $type, 'line' => 1, 'count' => count(array_filter($vals, fn($v) => $v !== ''))];
    }
    if (!$fcol) return ['errors' => [['line' => 1, 'col' => 1, 'msg' => 'אף עמודה בשורה הראשונה אינה שדה של הרשימה. כדאי להתחיל מקובץ שיוצא מכאן (⬇️ לאקסל)']]];
    $all = array_column($fcol, 1, 0);
    $err = function (int $n, string $m) use (&$errors) { $errors[] = ['line' => $n, 'col' => 1, 'msg' => "שורה $n: $m"]; };
    $next = $state['list']['next_id'];
    for ($x = 1; $x < count($rows); $x++) {
        $r = $rows[$x];
        $n = $x + 1;
        if (trim(implode('', array_map('strval', $r))) === '') continue;
        $get = fn(int $i) => csv_uncell(trim((string)($r[$i] ?? '')));
        $id = null;
        if (isset($col['id']) && ($raw = $get($col['id'])) !== '') {
            if (!preg_match('/^[1-9]\d{0,9}$/', $raw) || (int)$raw > 2147483647) { $err($n, "מזהה לא תקין \"$raw\" (מספר שלם חיובי, או ריק — פריט חדש)"); continue; }
            $id = (int)$raw;
            if (isset($seen[$id])) { $err($n, "המזהה $id מופיע פעמיים (גם בשורה {$seen[$id]})"); continue; }
            $seen[$id] = $n;
        }
        $cur = $id !== null ? ($state['by'][$id] ?? null) : null;
        if ($id !== null && !$cur && $id < $next) { $err($n, "המזהה $id כבר היה בשימוש ברשימה (פריט שנמחק). לפריט חדש — השאירו את המזהה ריק"); continue; }
        $data = $cur['data'] ?? [];
        if (!$cur) foreach ($fields as $f) if ($f['def'] !== null) $data[$f['key']] = $f['def'];
        foreach ($fcol as $k => [$i, $f]) {
            if (in_array($f['type'], ['image', 'audio'], true)) continue;            // קבצים — לקריאה בלבד מאקסל
            $cell = $get($i);
            if ($cur && $cell === field_cell($f, $cur['data'][$k] ?? null, [])) continue;
            [$val, $e2, , $propose] = field_parse($f, $cell, true);
            if ($e2) { $err($n, $e2 . ($propose !== null ? ' (הוסיפו אותה ב-🧩 מבנה)' : '')); continue 2; }
            if ($val === null) unset($data[$k]); else $data[$k] = $val;
        }
        ksort($data);
        foreach (array_merge($fields, array_values(array_filter($all, fn($f) => !in_array($f['key'], array_column($fields, 'key'), true)))) as $f) {
            if ($f['required'] && (($data[$f['key']] ?? null) === null || $data[$f['key']] === '' || $data[$f['key']] === [])) { $err($n, "\"{$f['label']}\" הוא שדה חובה — חסר ערך"); continue 2; }
        }
        if (isset($col['sort_order']) && ($so = $get($col['sort_order'])) !== '') {
            if (!preg_match('/^-?\d{1,12}$/', $so)) { $err($n, "סדר לא תקין \"$so\" (מספר שלם)"); continue; }
            if ($cur) $order[$id] = (int)$so;
        }
        if ($id !== null && !$cur) $warnings[] = ['line' => $n, 'col' => 1, 'msg' => "שורה $n: אין פריט עם המזהה $id — יתווסף פריט חדש עם המזהה הזה"];
        $items[] = ['x' => $x, 'id' => $id, 'line' => $n, 'data' => $data];
    }
    return ['errors' => $errors, 'warnings' => $warnings, 'items' => $items, 'order' => $order, 'full' => false, 'field_proposals' => $fieldProps, 'create_fields' => $create];
}

/** ייבוא מאקסל לרשימה קיימת — כמו words_csv_import (בלי apply — התוכנית; replace_all — מפרסם). */
function list_csv_import(array $user, string $key, array $b): array
{
    $raw = isset($b['csv_b64']) ? base64_decode((string)$b['csv_b64'], true) : (string)($b['csv'] ?? '');
    if ($raw === false) throw new ApiError('הקובץ (base64) לא תקין');
    [$rows, $enc] = words_csv_read($raw);
    $replace = ($b['replace_all'] ?? false) === true;
    $opt = ['fields' => array_values(array_filter(is_array($b['create_fields'] ?? null) ? $b['create_fields'] : [], 'is_string'))];
    $build = function (array $state) use ($rows, $replace, $opt) {
        $in = list_csv_items($rows, $state, $opt);
        if ($replace) { $in['full'] = true; $in['replace'] = true; $in['order'] = []; }
        return $in;
    };
    if (empty($b['apply'])) {
        $state = list_state($key);
        $in = $build($state);
        if (!empty($in['errors'])) throw new ApiError('הגיליון לא יובא — ' . count($in['errors']) . ' שגיאות. הטבלה לא השתנתה', 422, ['errors' => array_slice($in['errors'], 0, 200), 'encoding' => $enc]);
        return ['plan' => list_plan($in, $state)[0], 'encoding' => $enc];
    }
    return list_apply($user, $key, $build, (string)($b['token'] ?? ''), 'אקסל') + ['encoding' => $enc] + list_items_state($key);
}

/**
 * "➕ רשימה חדשה ← מאקסל": השורה הראשונה — הכותרות ← שדה לכל עמודה (סוג מנוחש, מפתח בתעתיק). $columns — מה שהמשתמש שינה
 * בתצוגה: [{label, key, type, skip}] לפי סדר העמודות. מחזיר fields, items (data), preview (5 שורות), errors.
 */
function list_csv_new(string $raw, ?array $columns): array
{
    [$rows, $enc] = words_csv_read($raw);
    $head = array_map(fn($h) => trim((string)$h), $rows[0] ?? []);
    if (!array_filter($head, fn($h) => $h !== '')) throw new ApiError('השורה הראשונה ריקה — היא צריכה להכיל את שמות העמודות');
    if (count($head) > 60) throw new ApiError('יותר מ-60 עמודות');
    $fields = []; $taken = []; $errors = [];
    foreach ($head as $i => $h) {
        $c = is_array($columns[$i] ?? null) ? $columns[$i] : [];
        if ($h === '' || !empty($c['skip'])) continue;
        if (in_array(mb_strtolower($h), ['מזהה', 'id', 'סדר', 'sort_order'], true)) continue;
        $vals = array_map(fn($r) => csv_uncell(trim((string)($r[$i] ?? ''))), array_slice($rows, 1));
        $label = str(preg_replace('/\s+/u', ' ', (string)($c['label'] ?? $h)), 80) ?: $h;
        $key = isset($c['key']) && is_string($c['key']) ? $c['key'] : list_key_from($h, $taken);
        $type = isset($c['type']) && in_array($c['type'], ['text', 'longtext', 'number', 'bool', 'date', 'url', 'list'], true) ? $c['type'] : field_guess($vals, true);
        if ($type === 'text' && array_filter($vals, fn($v) => mb_strlen($v) > FIELD_TEXT_MAX || str_contains($v, "\n"))) $type = 'longtext';
        if ($e = list_field_key_error($key, array_map(fn($k) => ['key' => $k], $taken))) { $errors[] = ['line' => 1, 'col' => $i + 1, 'msg' => "עמודה \"$h\": $e"]; continue; }
        $taken[] = $key;
        $fields[] = ['col' => $i, 'key' => $key, 'label' => $label, 'type' => $type, 'required' => false];
    }
    if (!$fields) throw new ApiError('אין עמודה לייבוא');
    $items = [];
    for ($x = 1; $x < count($rows) && !$errors; $x++) {
        $r = $rows[$x];
        if (trim(implode('', array_map('strval', $r))) === '') continue;
        if (count($items) >= 20000) { $errors[] = ['line' => $x + 1, 'col' => 1, 'msg' => 'יותר מ-20,000 שורות']; break; }
        $d = [];
        foreach ($fields as $f) {
            [$val, $e] = field_parse(field_norm(['key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'], 'col' => null, 'required' => 0, 'options' => '[]']), csv_uncell(trim((string)($r[$f['col']] ?? ''))), true);
            if ($e) { $errors[] = ['line' => $x + 1, 'col' => $f['col'] + 1, 'msg' => 'שורה ' . ($x + 1) . ": $e"]; continue 2; }
            if ($val !== null) $d[$f['key']] = $val;
        }
        $items[] = $d;
    }
    $out = array_map(fn($f) => array_diff_key($f, ['col' => 1]), $fields);
    return ['fields' => $out, 'items' => $items, 'errors' => array_slice($errors, 0, 200), 'encoding' => $enc,
        'preview' => array_map(fn($d) => array_map(fn($f) => $d[$f['key']] ?? null, $out), array_slice($items, 0, 5))];
}

// ── מבנה הרשימה (השדות) — אותו ממשק כמו "מבנה המילה" ──────

function list_fields_state(array $user, string $key): array
{
    return ['fields' => list_fields_public($key), 'types' => FIELD_TYPES, 'col_types' => (object)[], 'reserved' => array_merge(FIELD_RESERVED, LIST_FIELD_RESERVED),
        'can_structure' => true, 'can_purge' => ROLE_RANK[$user['role'] ?? 'editor'] >= ROLE_RANK['publisher'], 'rev' => list_rev($key), 'list' => list_public(list_get($key))];
}

function list_values_write(string $key, string $fkey, array $values): void
{
    $pdo = db();
    $get = $pdo->prepare('SELECT data FROM list_items WHERE list = ? AND id = ?');
    $put = $pdo->prepare('UPDATE list_items SET data = ? WHERE list = ? AND id = ?');
    foreach ($values as $id => $v) {
        $get->execute([$key, $id]);
        $x = json_decode((string)$get->fetchColumn(), true);
        $x = is_array($x) && !array_is_list($x) ? $x : [];
        if ($v === null) unset($x[$fkey]); else $x[$fkey] = $v;
        $put->execute([list_data_json($x), $key, $id]);
    }
}

/** הוספה / עריכה / סדר של שדה ברשימה — כמו field_save (המרת סוג: dry / 422 / clear_failed — מפרסם). */
function list_field_save(array $user, string $key, array $b): array
{
    $pdo = db();
    $fields = list_fields($key);
    if (isset($b['order'])) {
        $keys = array_values(array_filter(is_array($b['order']) ? $b['order'] : [], 'is_string'));
        $have = array_column($fields, 'key');
        if (count($keys) !== count($have) || array_diff($have, $keys)) throw new ApiError('הסדר חייב לכלול כל שדה פעם אחת');
        pf_tx(function () use ($pdo, $keys, $key) { $st = $pdo->prepare('UPDATE list_fields SET sort_order = ? WHERE list = ? AND key = ?'); foreach ($keys as $i => $k) $st->execute([($i + 1) * 10, $key, $k]); });
        audit_as($user, 'list_field_edit', "$key · סדר השדות: " . implode(', ', $keys));
        return list_fields_state($user, $key);
    }
    $origKey = (string)($b['orig'] ?? '');
    $cur = $origKey !== '' ? (array_column($fields, null, 'key')[$origKey] ?? throw new ApiError('השדה לא נמצא', 404)) : null;
    if ($cur && isset($b['key']) && $b['key'] !== $cur['key']) throw new ApiError('שינוי מפתח — דרך "שנה מפתח" (עם עדכון הקשירות בקבצים)');
    $in = field_input($b, $cur);
    $fk = $cur ? $cur['key'] : (string)($b['key'] ?? '');
    if (!$cur && ($e = list_field_key_error($fk, $fields))) throw new ApiError($e);
    $conv = null;
    if ($cur && ($cur['type'] !== $in['type'] || ($in['type'] === 'choice' && $in['options'] !== $cur['options']))) {
        $to = ['key' => $fk] + $in;
        $values = []; $failed = []; $n = 0;
        foreach (list_rows($key) as $r) {
            if (!array_key_exists($fk, $r['data'])) continue;
            $v = $r['data'][$fk];
            [$o, $err] = field_convert($cur, $to, $v);
            if ($err !== null || $o === null) { $failed[] = ['id' => $r['id'], 'word' => '#' . $r['id'], 'value' => mb_substr(is_array($v) ? implode('; ', $v) : (string)json_encode($v, JSON_UNESCAPED_UNICODE), 0, 120)]; $values[$r['id']] = null; continue; }
            if ($o !== $v) $values[$r['id']] = $o;
            $n++;
        }
        $conv = ['converted' => $n, 'failed_count' => count($failed), 'failed' => array_slice($failed, 0, 500), 'values' => $values];
        $pub = array_diff_key($conv, ['values' => 1]);
        if (!empty($b['dry'])) return ['convert' => $pub];
        if ($failed && !empty($b['clear_failed']) && ROLE_RANK[$user['role'] ?? 'editor'] < ROLE_RANK['publisher']) throw new ApiError('"רוקן את הנכשלים" — רק למפרסם', 403, ['convert' => $pub]);
        if ($failed && empty($b['clear_failed'])) throw new ApiError("ההמרה נעצרה: " . count($failed) . " ערכים לא מומרים. תקנו אותם, או בחרו \"רוקן את הנכשלים\"", 422, ['convert' => $pub]);
    } elseif (!empty($b['dry'])) return ['convert' => null];
    $snap = $conv && ($conv['failed_count'] || $conv['converted'] > WORDS_BULK) ? snapshot_create($user, "לפני שינוי הסוג של השדה \"$key.$fk\": {$cur['type']} → {$in['type']}") : null;
    pf_tx(function () use ($pdo, $cur, $key, $fk, $in, $conv) {
        $row = [$in['label'], $in['type'], (int)$in['required'], $in['def'] === null ? null : json_encode($in['def'], JSON_UNESCAPED_UNICODE),
            $in['help'], json_encode($in['options'], JSON_UNESCAPED_UNICODE), (int)$in['published'], $in['dir']];
        if ($cur) $pdo->prepare('UPDATE list_fields SET label = ?, type = ?, required = ?, def = ?, help = ?, options = ?, published = ?, dir = ? WHERE list = ? AND key = ?')->execute([...$row, $key, $fk]);
        else {
            $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM list_fields WHERE list = ?');
            $st->execute([$key]);
            $pdo->prepare('INSERT INTO list_fields (label, type, required, def, help, options, published, dir, list, key, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$row, $key, $fk, (int)$st->fetchColumn()]);
        }
        if ($conv) list_values_write($key, $fk, $conv['values']);
    });
    audit_as($user, $cur ? 'list_field_edit' : 'list_field_add', "$key.$fk ({$in['label']}, {$in['type']})" . ($conv ? " · {$conv['converted']} הומרו, {$conv['failed_count']} רוקנו" : ''));
    return list_fields_state($user, $key) + ['snapshot' => $snap, 'convert' => $conv ? array_diff_key($conv, ['values' => 1]) : null];
}

/** מחיקת שדה ברשימה — לסל עם הערכים (שדה שיש לו ערכים — מפרסם). השדה האחרון אינו נמחק. */
function list_field_delete(array $user, string $key, array $b): array
{
    $fk = (string)($b['key'] ?? '');
    $fields = list_fields($key);
    $f = array_column($fields, null, 'key')[$fk] ?? throw new ApiError('השדה לא נמצא', 404);
    if (count($fields) === 1) throw new ApiError('זה השדה היחיד ברשימה — אי אפשר למחוק אותו (אפשר לשנות לו שם וסוג)');
    $vals = [];
    foreach (list_rows($key) as $r) if (array_key_exists($fk, $r['data'])) $vals[$r['id']] = $r['data'][$fk];
    list_bind_load();
    $binds = count(array_filter(fields_bind_scan('field', $fk), fn($h) => !$h['manual']));
    if (!empty($b['dry'])) return ['values' => count($vals), 'bindings' => $binds, 'needs' => $vals ? 'publisher' : 'editor'];
    if ($vals && ROLE_RANK[$user['role'] ?? 'editor'] < ROLE_RANK['publisher']) throw new ApiError('מחיקת שדה שיש לו ערכים (' . count($vals) . ') — רק למפרסם', 403);
    $snap = snapshot_create($user, "לפני מחיקת השדה \"{$f['label']}\" ($key.$fk)");
    pf_tx(function () use ($user, $key, $fk, $vals) {
        $st = db()->prepare('SELECT * FROM list_fields WHERE list = ? AND key = ?');
        $st->execute([$key, $fk]);
        db()->prepare('INSERT INTO list_trash (list, kind, item_id, row, at, by_name) VALUES (?, ?, NULL, ?, ?, ?)')
            ->execute([$key, 'field', json_encode(['field' => $st->fetch(), 'vals' => (object)$vals], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now_iso(), $user['name'] ?? '']);
        list_values_write($key, $fk, array_fill_keys(array_keys($vals), null));
        db()->prepare('DELETE FROM list_fields WHERE list = ? AND key = ?')->execute([$key, $fk]);
    });
    audit_as($user, 'list_field_delete', "$key.$fk ({$f['label']}) · " . count($vals) . ' ערכים → לסל');
    return list_fields_state($user, $key) + ['snapshot' => $snap, 'bindings' => $binds];
}

/** כותב את הקשירות שנבחרו (לפי מיקום, מהסוף) — דרך pf_put, גרסה בהיסטוריה. בתוך pf_tx. */
function lists_bind_write(array $hits, string $old, string $new, array $user, string $note, array $revs): array
{
    $stale = [];
    foreach ($hits as $h) if ((int)($revs[$h['file']] ?? $revs[(string)$h['file']] ?? -1) !== $h['rev']) $stale[$h['path']] = true;
    if ($stale) throw new ApiError('קבצים השתנו מאז התצוגה: ' . implode(', ', array_slice(array_keys($stale), 0, 10)) . '. הציגו שוב', 409, ['stale' => array_keys($stale)]);
    $by = [];
    foreach ($hits as $h) $by[$h['file']][] = $h;
    foreach ($by as $id => $hs) {
        $r = pf_row((int)$id);
        if ($r['sha'] !== $hs[0]['sha']) throw new ApiError("\"{$r['path']}\" השתנה בינתיים", 409);
        $s = pf_blob_get($r['sha']);
        usort($hs, fn($a, $c) => $c['off'] <=> $a['off']);
        foreach ($hs as $h) {
            if (substr($s, $h['off'], $h['len']) !== $h['old']) throw new ApiError("\"{$r['path']}\" השתנה בינתיים", 409);
            $s = substr_replace($s, $h['new'], $h['off'], $h['len']);
        }
        pf_put($r, $s, $user, $note);
    }
    return ['replaced' => count($hits), 'files' => count($by)];
}

/** "שנה מפתח" של שדה ברשימה: תצוגה (בלי to_apply) / החלה — כמו field_key_preview / field_key_apply. */
function list_field_key(array $user, string $key, array $b, bool $apply): array
{
    $old = (string)($b['key'] ?? '');
    $new = (string)($b['to'] ?? '');
    $fields = list_fields($key);
    array_column($fields, null, 'key')[$old] ?? throw new ApiError('השדה לא נמצא', 404);
    if ($new === $old) throw new ApiError('המפתח לא השתנה');
    if ($e = list_field_key_error($new, $fields, $old)) throw new ApiError($e);
    list_bind_load();
    if (!$apply) {
        $hits = fields_bind_scan('field', $old, $new);
        $values = 0;
        foreach (list_rows($key) as $r) if (array_key_exists($old, $r['data'])) $values++;
        return ['kind' => 'field', 'from' => $old, 'to' => $new, 'hits' => array_map(fn($h) => array_diff_key($h, ['sha' => 1]), $hits), 'values' => $values, 'words' => null,
            'files' => count(array_unique(array_column($hits, 'file')))];
    }
    pf_guard($user);
    $skip = array_flip(array_map('strval', is_array($b['skip'] ?? null) ? $b['skip'] : []));
    $note = "שינוי מפתח `$key.$old` → `$new`";
    $snap = snapshot_create($user, "לפני $note");
    $done = pf_tx(function () use ($key, $old, $new, $skip, $user, $note, $b) {
        db()->prepare('UPDATE list_fields SET key = ? WHERE list = ? AND key = ?')->execute([$new, $key, $old]);
        $put = db()->prepare('UPDATE list_items SET data = ? WHERE list = ? AND id = ?');
        foreach (list_rows($key) as $r) {
            if (!array_key_exists($old, $r['data'])) continue;
            $d = $r['data'];
            $d[$new] = $d[$old];
            unset($d[$old]);
            $put->execute([list_data_json($d), $key, $r['id']]);
        }
        $hits = array_values(array_filter(fields_bind_scan('field', $old), fn($h) => !$h['manual'] && !isset($skip[$h['id']])));
        return lists_bind_write(array_map(fn($h) => $h + ['old' => $old, 'new' => $new], $hits), $old, $new, $user, $note, is_array($b['revs'] ?? null) ? $b['revs'] : []);
    });
    audit_as($user, 'list_field_key', "$key: $old → $new · {$done['replaced']} קשירות ב-{$done['files']} קבצים");
    return $done + ['snapshot' => $snap, 'from' => $old, 'to' => $new, 'rev' => list_rev($key)];
}

// ── שינוי מפתח של רשימה: הקשירות (data-kol-list / -search / -filter / -count / -more / -empty / -src) ──

/**
 * כל המקומות בקבצי העמוד שבהם המפתח של הרשימה הוא שם הרשימה: data-kol-list|search|count|more|empty = "<key>",
 * data-kol-filter = "<key>:…" (החלק שלפני ":"), ו-data-kol-src שמסתיים ב-data/<key>.json. בקבצי JS — "לבדיקה ידנית".
 */
function lists_bind_scan(string $old, ?string $new = null): array
{
    list_bind_load();
    if (!function_exists('pf_texts') || !pf_seeded() || $old === '') return [];
    $hits = [];
    foreach (pf_texts() as [$r, $s]) {
        if (str_starts_with($r['path'], 'media/')) continue;
        $js = (bool)preg_match('/\.(?:m?js|cjs|jsx|ts|tsx)$/i', $r['path']);
        $found = [];
        preg_match_all(BIND_ATTR_RX, $s, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $x) {
            $g = isset($x[3]) && $x[3][1] >= 0 ? $x[3] : $x[2];
            $attr = strtolower($x[1][0]);
            $v = $g[0];
            $rel = null;
            if (in_array($attr, ['list', 'search', 'count', 'more', 'empty', 'search-scope'], true) && trim($v) === $old) $rel = strpos($v, $old);
            elseif ($attr === 'filter' && ($c = strpos($v, ':')) !== false && trim(substr($v, 0, $c)) === $old) $rel = strpos($v, $old);
            elseif ($attr === 'src' && preg_match('~(?:^|/)data/(' . preg_quote($old, '~') . ')\.json(?:[?#]|$)~', $v, $mm, PREG_OFFSET_CAPTURE)) $rel = $mm[1][1];
            if ($rel !== null && $rel !== false) $found[$g[1] + $rel] = [$g[1] + $rel, $js, 'data-kol-' . $attr];
        }
        if ($js) {
            preg_match_all('/(?<=[\'"])' . preg_quote($old, '/') . '(?=[\'"])|data\/' . preg_quote($old, '/') . '\.json/', $s, $mm, PREG_OFFSET_CAPTURE);
            foreach ($mm[0] as [$t, $off]) { $o = str_starts_with($t, 'data/') ? $off + 5 : $off; $found[$o] ??= [$o, true, 'JS']; }
        }
        if (!$found) continue;
        ksort($found);
        $nl = pf_lines($s);
        foreach ($found as [$off, $manual, $what]) {
            [$line, , $start] = pf_pos($nl, $s, $off);
            $end = strpos($s, "\n", $start);
            $ln = rtrim(substr($s, $start, ($end === false ? strlen($s) : $end) - $start), "\r");
            $h = ['id' => $r['id'] . ':' . $off, 'file' => (int)$r['id'], 'path' => $r['path'], 'rev' => (int)$r['rev'], 'sha' => $r['sha'], 'off' => $off, 'len' => strlen($old),
                'line' => $line, 'before' => pf_label($ln, 300), 'manual' => $manual, 'what' => $what];
            if ($new !== null) $h['after'] = pf_label(substr_replace($ln, $new, $off - $start, strlen($old)), 300);
            $hits[] = $h;
        }
    }
    return $hits;
}

function list_key_target(array $b): array
{
    $l = list_get((string)($b['key'] ?? ''));
    $new = (string)($b['to'] ?? '');
    if ($new === $l['key']) throw new ApiError('המפתח לא השתנה');
    if ($e = list_key_error($new)) throw new ApiError($e);
    $path = array_search($l['key'], pf_list_paths(), true);
    $newPath = $path === "data/{$l['key']}.json" ? "data/$new.json" : ($path === false ? null : $path);
    if ($newPath !== null && $newPath !== $path) list_path_check($newPath);
    return [$l, $new, $path === false ? null : $path, $newPath];
}

/** שינוי מפתח של רשימה — תצוגה: הקשירות לפני ← אחרי, ו-kol.json (הנתיב משתנה רק אם הוא ברירת המחדל data/<key>.json). */
function list_key_preview(array $user, array $b): array
{
    [$l, $new, $path, $newPath] = list_key_target($b);
    $hits = lists_bind_scan($l['key'], $new);
    return ['kind' => 'list', 'from' => $l['key'], 'to' => $new, 'file' => ['from' => $path, 'to' => $newPath], 'items' => list_public($l)['count'],
        'hits' => array_map(fn($h) => array_diff_key($h, ['sha' => 1]), $hits), 'files' => count(array_unique(array_column($hits, 'file')))];
}

/** החלה — עסקה אחת: הטבלאות, kol.json והקבצים. revs מהתצוגה; קובץ שהשתנה — 409. */
function list_key_apply(array $user, array $b): array
{
    [$l, $new, $path, $newPath] = list_key_target($b);
    pf_guard($user);
    $old = $l['key'];
    $skip = array_flip(array_map('strval', is_array($b['skip'] ?? null) ? $b['skip'] : []));
    $note = "שינוי מפתח הרשימה `$old` → `$new`";
    $snap = snapshot_create($user, "לפני $note");
    $done = pf_tx(function () use ($old, $new, $path, $newPath, $skip, $user, $note, $b) {
        $pdo = db();
        foreach (['list_items' => 'list', 'list_fields' => 'list', 'list_trash' => 'list', 'lists' => 'key'] as $t => $c) $pdo->prepare("UPDATE $t SET $c = ? WHERE $c = ?")->execute([$new, $old]);
        $hits = array_values(array_filter(lists_bind_scan($old), fn($h) => !$h['manual'] && !isset($skip[$h['id']])));
        $hits = array_map(function ($h) use ($old, $new) {
            return $h + ['old' => $old, 'new' => $new];
        }, $hits);
        $done = lists_bind_write($hits, $old, $new, $user, $note, is_array($b['revs'] ?? null) ? $b['revs'] : []);
        list_kol_data($user, function (array $d) use ($old, $new, $path, $newPath) {
            $out = [];
            foreach ($d as $k => $v) {
                if (is_array($v) && ($v['source'] ?? '') === "list:$old") { $out[$k === $old ? $new : $k] = ['file' => $newPath ?? $v['file'] ?? "data/$new.json", 'source' => "list:$new"] + $v; continue; }
                $out[$k] = $v;
            }
            return $out;
        }, $note);
        return $done;
    });
    audit_as($user, 'list_key', "$old → $new · {$done['replaced']} קשירות ב-{$done['files']} קבצים" . ($newPath !== $path ? " · $path → $newPath" : ''));
    return $done + ['snapshot' => $snap, 'from' => $old, 'to' => $new] + lists_state($user);
}

function list_bind_load(): void
{
    if (!function_exists('fields_bind_scan')) require_once __DIR__ . '/fields_bind.php';
}

// ── הסל ───────────────────────────────────────────────────

function list_trash_list(): array
{
    $out = [];
    foreach (db()->query('SELECT * FROM list_trash ORDER BY id DESC LIMIT 500')->fetchAll() as $t) {
        $row = json_decode((string)$t['row'], true) ?: [];
        $what = match ($t['kind']) {
            'list' => 'רשימה · ' . ($row['list']['label'] ?? $t['list']) . ' (' . count($row['items'] ?? []) . ' פריטים)',
            'field' => 'שדה ברשימה · ' . ($row['field']['label'] ?? '?') . ' (' . count((array)($row['vals'] ?? [])) . ' ערכים)',
            default => 'פריט · ' . list_trash_name($t['list'], $row) . ' #' . $t['item_id'],
        };
        $out[] = ['id' => (int)$t['id'], 'at' => $t['at'], 'by' => $t['by_name'], 'kind' => $t['kind'], 'list' => $t['list'], 'name' => $what];
    }
    return $out;
}

function list_trash_name(string $list, array $row): string
{
    $d = json_decode((string)($row['data'] ?? '{}'), true) ?: [];
    foreach ($d as $v) if (is_string($v) && $v !== '') return mb_substr(strip_tags($v), 0, 60);
    return $list;
}

/** שחזור מהסל (בתוך העסקה של trash_restore). רשימה — הכול חוזר, ו-data ב-kol.json; פריט — לרשימה שלו, עם אותו id. */
function list_trash_restore(array $user, int $id): string
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM list_trash WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch() ?: throw new ApiError('לא נמצא בסל', 404);
    $row = json_decode((string)$t['row'], true) ?: [];
    $insert = function (string $table, array $r) use ($pdo) {
        $cols = array_keys($r);
        $pdo->prepare("INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($r));
    };
    $exists = fn(string $k) => (function () use ($pdo, $k) { $s = $pdo->prepare('SELECT 1 FROM lists WHERE key = ?'); $s->execute([$k]); return (bool)$s->fetchColumn(); })();
    if ($t['kind'] === 'list') {
        $l = $row['list'];
        if ($exists($l['key'])) throw new ApiError("כבר יש רשימה עם המפתח \"{$l['key']}\" — שנו את המפתח שלה, ואז שחזרו", 409);
        $fid = $pdo->prepare('SELECT 1 FROM lists WHERE fid = ?');
        $fid->execute([$l['fid']]);
        if ($fid->fetchColumn()) { $l['fid'] = (int)$pdo->query('SELECT COALESCE(MAX(fid), 0) + 1 FROM lists')->fetchColumn(); }
        $insert('lists', $l);
        foreach ($row['fields'] ?? [] as $f) $insert('list_fields', $f);
        foreach ($row['items'] ?? [] as $i) $insert('list_items', $i);
        $kol = is_array($row['kol'] ?? null) ? $row['kol'] : ['file' => "data/{$l['key']}.json", 'source' => "list:{$l['key']}"];
        $file = $kol['file'] ?? "data/{$l['key']}.json";
        try { list_path_check((string)$file, $l['key']); } catch (ApiError) { $file = null; }
        if ($file !== null && pf_seeded()) list_kol_data($user, fn(array $d) => $d + [$l['key'] => ['file' => $file, 'source' => "list:{$l['key']}"] + $kol], "שחזור הרשימה {$l['label']} ({$l['key']}) — data.{$l['key']} ב-kol.json");
        $msg = "רשימה · {$l['label']} ({$l['key']}) · " . count($row['items'] ?? []) . ' פריטים';
    } elseif ($t['kind'] === 'field') {
        if (!$exists($t['list'])) throw new ApiError("הרשימה \"{$t['list']}\" אינה קיימת — שחזרו אותה קודם", 409);
        $f = $row['field'];
        $chk = $pdo->prepare('SELECT 1 FROM list_fields WHERE list = ? AND key = ?');
        $chk->execute([$t['list'], $f['key']]);
        if ($chk->fetchColumn()) throw new ApiError("כבר יש שדה עם המפתח \"{$f['key']}\" — שנו את המפתח שלו, ואז שחזרו", 409);
        $f['list'] = $t['list'];
        $insert('list_fields', $f);
        $have = array_flip(array_column(list_rows($t['list']), 'id'));
        list_values_write($t['list'], $f['key'], array_filter((array)($row['vals'] ?? []), fn($k) => isset($have[(int)$k]), ARRAY_FILTER_USE_KEY));
        $msg = "שדה · {$t['list']}.{$f['key']}";
    } else {
        if (!$exists($t['list'])) throw new ApiError("הרשימה \"{$t['list']}\" אינה קיימת — שחזרו אותה קודם", 409);
        $chk = $pdo->prepare('SELECT 1 FROM list_items WHERE list = ? AND id = ?');
        $chk->execute([$t['list'], $t['item_id']]);
        if ($chk->fetchColumn()) throw new ApiError("המזהה {$t['item_id']} תפוס ברשימה", 409);
        $row['list'] = $t['list'];
        $insert('list_items', $row);
        $msg = "פריט · {$t['list']} #{$t['item_id']}";
    }
    $pdo->prepare('DELETE FROM list_trash WHERE id = ?')->execute([$id]);
    return $msg;
}

// ── "➕ הוסף לעמוד" — קטע HTML רגיל, דרך מנוע הקבצים ─────────

function list_attr(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** הקטע: כותרת, חיפוש, סינון (שדה הבחירה הראשון), מונה, המכל + <template>, "טען עוד", "אין תוצאות". כל כיתוב — טקסט באלמנט. */
function list_snippet(array $l, array $fields): string
{
    $k = $l['key'];
    if (!preg_match(LIST_KEY_RX, $k)) throw new ApiError('מפתח לא תקין');
    $c = "kol-list-$k";
    $pub = array_values(array_filter($fields, fn($f) => $f['published'] && preg_match(FIELD_KEY_RX, $f['key'])));
    $search = array_column(array_filter($pub, fn($f) => in_array($f['type'], ['text', 'longtext', 'rich', 'list', 'choice'], true)), 'key');
    $choice = array_values(array_filter($pub, fn($f) => $f['type'] === 'choice'))[0] ?? null;
    $label = list_attr($l['label']);
    $tpl = [];
    $first = true;
    foreach ($pub as $f) {
        $fk = $f['key'];
        $cls = "{$c}__$fk";
        $tpl[] = match ($f['type']) {
            'rich' => "        <div class=\"$cls\" data-kol-html=\"$fk\"></div>",
            'longtext' => "        <p class=\"$cls\" data-kol-text=\"$fk\"></p>",
            'image' => "        <img class=\"$cls\" data-kol-if=\"$fk\" data-kol-attr-src=\"$fk\" alt=\"\">",
            'audio' => "        <button type=\"button\" class=\"$cls\" data-kol-if=\"$fk\" data-kol-play=\"$fk\">▶ השמעה</button>",
            'url' => "        <a class=\"$cls\" data-kol-if=\"$fk\" data-kol-attr-href=\"$fk\" data-kol-text=\"$fk\"></a>",
            'list' => "        <ul class=\"$cls\" data-kol-if=\"$fk\"><li data-kol-each=\"$fk\" data-kol-text=\".\"></li></ul>",
            'bool' => "        <span class=\"$cls\" data-kol-if=\"$fk\">✓ " . list_attr($f['label']) . '</span>',
            default => $first ? "        <h3 class=\"$cls\" data-kol-text=\"$fk\"></h3>" : "        <p class=\"$cls\" data-kol-if=\"$fk\" data-kol-text=\"$fk\"></p>",
        };
        if (in_array($f['type'], ['text', 'number', 'date', 'choice', 'longtext', 'rich'], true)) $first = false;
    }
    $s = "<section class=\"$c\" id=\"list-$k\">\n"
        . "  <h2 class=\"{$c}__title\">$label</h2>\n"
        . ($search ? "  <input type=\"search\" class=\"{$c}__search\" data-kol-search=\"$k\" data-kol-search-fields=\"" . implode(' ', $search) . "\" placeholder=\"חיפוש…\" aria-label=\"חיפוש ב$label\">\n" : '')
        . ($choice ? "  <div class=\"{$c}__filter\" data-kol-filter=\"$k:{$choice['key']}\" data-kol-active-class=\"is-active\">\n    <button type=\"button\" data-kol-filter-value=\"\">הכול</button>\n    <template><button type=\"button\">{label}</button></template>\n  </div>\n" : '')
        . "  <p class=\"{$c}__count\" data-kol-count=\"$k\" data-kol-count-text=\"{shown} מתוך {total}\"></p>\n"
        . "  <div class=\"{$c}__items\" data-kol-list=\"$k\" data-kol-sort=\"manual\" data-kol-page=\"30\">\n    <template>\n      <article class=\"{$c}__item\">\n"
        . implode("\n", $tpl) . "\n      </article>\n    </template>\n  </div>\n"
        . "  <p class=\"{$c}__empty\" data-kol-empty=\"$k\">אין תוצאות</p>\n"
        . "  <button type=\"button\" class=\"{$c}__more\" data-kol-more=\"$k\">טען עוד</button>\n"
        . "</section>\n";
    return $s;
}

function list_snippet_css(string $k): string
{
    $c = "kol-list-$k";
    return "\n/* הרשימה $k — נוסף ב\"➕ הוסף לעמוד\". אפשר לשנות הכול */\n"
        . ".$c { margin: 32px 0; }\n"
        . ".{$c}__search { width: 100%; max-width: 420px; padding: 8px 12px; font: inherit; border: 1px solid #c9c9d1; border-radius: 8px; }\n"
        . ".{$c}__filter { display: flex; flex-wrap: wrap; gap: 6px; margin: 12px 0; }\n"
        . ".{$c}__filter button { padding: 4px 12px; border: 1px solid #c9c9d1; border-radius: 999px; background: none; font: inherit; cursor: pointer; }\n"
        . ".{$c}__filter .is-active { background: var(--color-accent, #a3322a); border-color: transparent; color: #fff; }\n"
        . ".{$c}__count { color: #666; font-size: .9em; }\n"
        . ".{$c}__items { display: grid; gap: 12px; }\n"
        . ".{$c}__item { padding: 12px 16px; border: 1px solid #e3e3ea; border-radius: 10px; }\n"
        . ".{$c}__item h3 { margin: 0 0 4px; }\n"
        . ".{$c}__item img { max-width: 100%; border-radius: 8px; }\n"
        . ".{$c}__more { margin-top: 12px; }\n";
}

/** איפה להכניס בעמוד: לפני </main>, לפני </body>, או אחרי סגירת האלמנט עם id. מחזיר offset או זורק. */
function list_place_at(string $html, string $where, string $id): int
{
    if ($where === 'after') {
        if (!preg_match('/^[A-Za-z][\w:.-]{0,80}$/', $id)) throw new ApiError('id לא תקין');
        if (!function_exists('build_tags')) require_once __DIR__ . '/build.php';
        $tags = build_tags($html);
        foreach ($tags as $k => $t) {
            if ($t['type'] !== 'open' || !preg_match('/(?:^|\s)id\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $t['attrs'], $m)) continue;
            if (($m[1] ?? '') . ($m[2] ?? '') . ($m[3] ?? '') !== $id) continue;
            if (in_array(strtolower($t['name']), ['img', 'input', 'br', 'hr', 'meta', 'link', 'source', 'area', 'base', 'col', 'embed', 'track', 'wbr'], true) || str_ends_with(rtrim($t['attrs']), '/')) return $t['end'];
            $depth = 0;
            for ($j = $k + 1, $c = count($tags); $j < $c; $j++) {
                if ($tags[$j]['name'] !== $t['name']) continue;
                if ($tags[$j]['type'] === 'open') { $depth++; continue; }
                if ($depth === 0) return $tags[$j]['end'];
                $depth--;
            }
            throw new ApiError("לאלמנט עם id=\"$id\" אין תגית סוגרת", 422);
        }
        throw new ApiError("אין בעמוד אלמנט עם id=\"$id\"", 404);
    }
    $tag = $where === 'main' ? 'main' : 'body';
    if (!preg_match_all('~</' . $tag . '\s*>~i', $html, $m, PREG_OFFSET_CAPTURE)) throw new ApiError("אין בעמוד <$tag> — בחרו מקום אחר", 422);
    return end($m[0])[1];
}

/** הנתיב היחסי מהעמוד אל קובץ בשורש הפרויקט (js/kol-data.js ← "../js/kol-data.js" לעמוד בתיקייה). */
function list_rel(string $page, string $target): string
{
    return str_repeat('../', substr_count($page, '/')) . $target;
}

/**
 * ➕ הוסף לעמוד: הקטע נכנס לעמוד, ובלוק CSS ל-css/page.css (kol.json ← styles.page). אם אין בעמוד js/kol-data.js — נוסף
 * <script> לפני </body> (והקובץ, אם חסר בפרויקט — מהזריעה). הכול דרך pf_put: גרסה בהיסטוריה לכל קובץ, "הוסף את הרשימה …".
 */
function list_place(array $user, array $b): array
{
    $l = list_get((string)($b['list'] ?? ''));
    pf_guard($user);
    $where = (string)($b['where'] ?? 'main');
    if (!in_array($where, LIST_PLACE_WHERE, true)) throw new ApiError('מקום לא תקין');
    $fields = list_fields($l['key']);
    $note = "הוסף את הרשימה {$l['label']}";
    $out = pf_tx(function () use ($user, $b, $l, $fields, $where, $note) {
        $page = pf_by_path(pf_path((string)($b['path'] ?? ''))) ?? throw new ApiError('העמוד לא נמצא', 404);
        if ($page['kind'] !== 'file' || !preg_match('/\.html?$/i', $page['path'])) throw new ApiError('בחרו קובץ עמוד (.html)');
        if (isset($b['rev']) && (int)$b['rev'] !== (int)$page['rev']) throw new ApiError('העמוד השתנה בינתיים — פתחו שוב', 409);
        $html = pf_blob_get($page['sha']);
        $snippet = list_snippet($l, $fields);
        $at = list_place_at($html, $where, (string)($b['id'] ?? ''));
        $indent = $where === 'after' ? "\n" : '';
        $html = substr($html, 0, $at) . $indent . $snippet . substr($html, $at);
        $kd = defined('BUILD_KD') ? BUILD_KD : 'js/kol-data.js';
        $added = [];
        if (!preg_match('~<script\b[^>]*\bsrc\s*=\s*["\']?[^"\'>]*kol-data\.js~i', $html)) {
            $tag = '  <script src="' . list_attr(list_rel($page['path'], $kd)) . '"></script>' . "\n";
            if (preg_match_all('~</body\s*>~i', $html, $m, PREG_OFFSET_CAPTURE)) { $p = end($m[0])[1]; $html = substr($html, 0, $p) . $tag . substr($html, $p); }
            else $html .= $tag;
            $added[] = 'script';
            if (!pf_by_path($kd)) { pf_insert($kd, 'file', (string)file_get_contents(dirname(__DIR__) . '/assets/project-seed/kol-data.js'), $user, null, $note); $added[] = $kd; }
        }
        $r = pf_put($page, $html, $user, $note);
        $cssPath = (string)(pf_kol()['config']['styles']['page'] ?? 'css/page.css');
        try { $cssPath = pf_path($cssPath); } catch (ApiError) { $cssPath = 'css/page.css'; }
        $css = pf_by_path($cssPath);
        $block = list_snippet_css($l['key']);
        if ($css && $css['kind'] === 'file') {
            $cur = pf_blob_get($css['sha']);
            if (!str_contains($cur, '.kol-list-' . $l['key'] . ' {')) pf_put($css, rtrim($cur, "\n") . "\n" . $block, $user, $note);
        } elseif (!$css) {
            pf_insert($cssPath, 'file', ltrim($block), $user, null, $note);
            $added[] = $cssPath;
        }
        return ['file' => pf_public($r), 'css' => $cssPath, 'added' => $added, 'html' => $snippet];
    });
    audit_as($user, 'pf_write', "{$out['file']['path']} · $note ({$l['key']}) · $where");
    return $out;
}

// ── מסך המפתח (08 §5) ─────────────────────────────────────

function lists_dev_line(): array
{
    $rows = db()->query('SELECT l.key, l.label, COUNT(i.id) AS n, COALESCE(SUM(LENGTH(i.data)), 0) AS bytes FROM lists l LEFT JOIN list_items i ON i.list = l.key GROUP BY l.key ORDER BY l.sort_order, l.key')->fetchAll();
    $total = array_sum(array_map(fn($r) => (int)$r['bytes'], $rows));
    $txt = count($rows) . ' רשימות' . ($rows ? ' · ' . implode(' · ', array_map(fn($r) => "{$r['label']} ({$r['key']}): {$r['n']}", $rows)) : '') . ' · ' . round($total / 1024, 1) . 'KB';
    return ['lists' => count($rows), 'items' => array_map(fn($r) => ['key' => $r['key'], 'label' => $r['label'], 'items' => (int)$r['n'], 'bytes' => (int)$r['bytes']], $rows),
        'bytes' => $total, 'text' => $txt];
}
