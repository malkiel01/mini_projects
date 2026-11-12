<?php
/**
 * המילון "קול מרוקו" — העמוד שהאתר מציג היום (תבנית page-kadisha-lexicon.php של ערכת הנושא), כרכיב אחד בסטודיו.
 *
 * החלטת בעל המערכת (2026-10-25): "להחליף את העמוד כולו", והמילים מנוהלות רק כאן. לכן:
 *  - המילים, הנושאים וההקלטות — בטבלאות words / topics (ייבוא מהאתר: data/lexicon.json מתוסף 1.2).
 *  - הרכיב lexicon מרנדר את אותו HTML כמו התבנית — אותן מחלקות ואותם מזהים — ולכן באתר חלים עליו ה-CSS וה-JS
 *    של ערכת הנושא (הם נטענים לעמוד הזה ממילא), והמראה וההתנהגות (חיפוש, נושאים, שמע, מבוא, משוב) זהים.
 *  - הקלטה שהגיעה מהאתר נשארת בכתובת שלה שם; הקלטה חדשה היא קובץ שלנו (kol-file), ונשלחת בפרסום כמו כל קובץ.
 *  - כל גרסה שומרת עותק של המילים — "פרסום מחדש" של גרסה ישנה מחזיר גם את המילים שהיו בה.
 *
 * הדרך חזרה: התוסף מכבה את ההחלפה (פעולת switch חתומה) — והאתר מציג שוב את התבנית המקורית והמילים שבוורדפרס,
 * שלא נגעו בהם.
 */

declare(strict_types=1);

require_once __DIR__ . '/code.php';
require_once __DIR__ . '/restore.php';

const LEX_TEXTS = ['title_he' => 200, 'subtitle_main_he' => 300, 'title_fr' => 200, 'title_en' => 200, 'subtitle_he' => 500, 'credit_he' => 300,
    'gate_before' => 40, 'gate_after' => 40, 'more_label' => 60, 'less_label' => 60];
const LEX_NUMS = ['hero_overlay' => [0, 100, 0], 'hero_min_height' => [0, 1500, 0], 'title_size' => [10, 200, 56], 'subtitle_size' => [8, 120, 22],
    'title_en_size' => [8, 120, 24], 'title_weight' => [100, 900, 700], 'subtitle_main_size' => [0, 200, 0], 'intro_max_width' => [0, 2400, 780]];
const LEX_DEFAULTS = ['gate_before' => 'הקדמה', 'gate_after' => 'מבוא', 'more_label' => 'קרא עוד', 'less_label' => 'הסתר'];
const LEX_PREVIEW_CARDS = 80;           // בסטודיו — רק הראשונות (התצוגה מתרעננת בכל שינוי)
const WORD_MAX = 200;
// הגופנים שערכת הנושא טוענת לעמודי הקדישא — לתצוגה בסטודיו
const LEX_FONTS = 'https://fonts.googleapis.com/css2?family=Frank+Ruhl+Libre:wght@300;400;500;700;900&family=Assistant:wght@300;400;600;700&display=swap';
const MEANING_MAX = 20_000;

// ── ערכים ─────────────────────────────────────────────────

function lex_host(): string
{
    if (function_exists('site_host')) return site_host();
    return preg_replace('/^www\./', '', (string)parse_url((string)(setting('site_url') ?: 'https://maghreb.co.il'), PHP_URL_HOST));
}

/** כתובת מלאה לקובץ באתר עצמו (https, אותו דומיין). אחרת — ריק. */
function lex_url(mixed $v): string
{
    $v = is_string($v) ? trim($v) : '';
    if (!preg_match('~^https://([^/\s"\'<>()]+)/[^\s"\'<>()]*$~i', $v, $m)) return '';
    return preg_replace('/^www\./i', '', strtolower($m[1])) === lex_host() ? $v : '';
}

/** הקלטה: קובץ שלנו (מזהה), כתובת באתר, או ריק. */
function lex_audio(mixed $v): string
{
    return file_id($v) ?: lex_url($v);
}

function lexicon_props(array $p): array
{
    $o = [];
    foreach (LEX_TEXTS as $k => $max) $o[$k] = str($p[$k] ?? '', $max) ?: (LEX_DEFAULTS[$k] ?? '');
    // "קרא עוד" / "הסתר" — ה-JS של ערכת הנושא מכניס אותם ב-innerHTML (מתוך data-more-text). רק טקסט
    foreach (['more_label', 'less_label', 'gate_before', 'gate_after'] as $k) $o[$k] = trim(str_replace(['<', '>', '&', '"', "'", '`'], '', $o[$k])) ?: LEX_DEFAULTS[$k];
    foreach (LEX_NUMS as $k => [$min, $max, $def]) $o[$k] = isset($p[$k]) && is_numeric($p[$k]) ? max($min, min($max, (int)$p[$k])) : $def;
    $o['hero_file'] = file_id($p['hero_file'] ?? '');
    $o['hero_url'] = lex_url($p['hero_url'] ?? '');
    $o['intro_before'] = sanitize_rich((string)($p['intro_before'] ?? ''));
    $o['intro_after'] = sanitize_rich((string)($p['intro_after'] ?? ''));
    $o['feedback'] = ($p['feedback'] ?? false) === true;
    $o['show_ids'] = ($p['show_ids'] ?? false) === true;
    return $o;
}

// ── המילים ────────────────────────────────────────────────

/**
 * המילים שהרינדור משתמש בהן: מהטבלה, או — בגרסה שמורה — מהעותק שלה. N1: בסדר התצוגה (sort_order, ואחריו id) — הסדר
 * הידני. המילוי הראשון של sort_order משחזר את הסדר של לפני N1 (lex_sort) — words_order_fill.
 */
function words_rows(): array
{
    if (isset($GLOBALS['kol_words'])) return $GLOBALS['kol_words'];
    $rows = db()->query('SELECT id, wp_id, word, meaning, topics, audio, external_id, sort_order, extra FROM words ORDER BY id')->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['wp_id'] = $r['wp_id'] === null ? null : (int)$r['wp_id'];
        $r['topics'] = json_decode($r['topics'], true) ?: [];
        $x = json_decode((string)$r['extra'], true);              // N2: ערכי השדות המותאמים
        $r['extra'] = is_array($x) && !array_is_list($x) ? $x : [];
    }
    unset($r);
    return words_order_effective($rows);
}

/** כמו באתר (orderby title): לפי הא"ב העברי. */
function lex_sort(array $rows, string $key): array
{
    $col = class_exists('Collator') ? new Collator('he_IL') : null;
    usort($rows, fn($a, $b) => $col ? $col->compare($a[$key], $b[$key]) : strcmp(mb_strtolower($a[$key]), mb_strtolower($b[$key])));
    return $rows;
}

/** שם נושא → slug. נושא שעוד לא בטבלה (נוסף כאן) — slug קבוע לפי השם. */
function topic_slugs(): array
{
    $map = [];
    foreach (db()->query('SELECT slug, name FROM topics')->fetchAll() as $t) $map[$t['name']] = $t['slug'];
    return $map;
}

function topic_slug(string $name, array &$map): string
{
    return $map[$name] ??= 'kt-' . substr(md5($name), 0, 10);
}

/** הנושאים שיש להם מילה, עם מספר המילים — כמו get_terms(hide_empty). N2: בסדר של הנושאים (בלי סדר שנקבע — לפי השם, כמו קודם). */
function topics_used(array $words, array &$map): array
{
    $out = [];
    foreach (topics_all($words) as $t) {
        $map[$t['name']] ??= $t['slug'];
        if ($t['count']) $out[] = ['name' => $t['name'], 'slug' => $t['slug'], 'count' => $t['count'], 'color' => $t['color'], 'hidden' => $t['hidden']];
    }
    return $out;
}

function words_snapshot(): string
{
    return json_encode(words_rows(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function audio_src(string $a): string
{
    return $a === '' ? '' : (file_id($a) ? media_url($a) : $a);
}

function words_list(): array
{
    $rows = words_rows();
    $map = topic_slugs();
    $files = files_meta(array_values(array_unique(array_filter(array_map(fn($w) => file_id($w['audio']), $rows)))));
    $fileFields = array_column(array_filter(fields_custom(), fn($f) => in_array($f['type'], ['image', 'audio'], true)), 'key');
    foreach ($rows as &$w) {
        $w['audio_src'] = audio_src($w['audio']);
        $f = file_id($w['audio']) ? ($files[$w['audio']] ?? null) : null;
        $w['audio_kind'] = $w['audio'] === '' ? '' : (!file_id($w['audio']) ? 'site' : (!$f ? 'missing' : (!empty($f['origin']) ? 'copy' : 'file')));
        // N2: לתצוגה בטבלה — כתובת לכל ערך של שדה image / audio
        $w['extra_src'] = [];
        foreach ($fileFields as $k) if (file_id($w['extra'][$k] ?? null)) $w['extra_src'][$k] = media_url($w['extra'][$k]);
        $w['extra_src'] = (object)$w['extra_src'];
        $w['extra'] = (object)$w['extra'];
    }
    unset($w);
    return ['words' => $rows, 'topics' => topics_used($rows, $map), 'fields' => fields_public(words_rows())];
}

function word_input(array $b): array
{
    $word = str($b['word'] ?? '', WORD_MAX);
    if ($word === '') throw new ApiError('חסרה המילה');
    $meaning = sanitize_rich(mb_substr((string)($b['meaning'] ?? ''), 0, MEANING_MAX));
    $topics = [];
    foreach (array_slice(is_array($b['topics'] ?? null) ? $b['topics'] : [], 0, 20) as $t) {
        $t = str(preg_replace('/\s+/u', ' ', (string)$t), 80);
        if ($t !== '' && !in_array($t, $topics, true)) $topics[] = $t;
    }
    $audio = lex_audio($b['audio'] ?? '');
    if (file_id($audio) && !files_meta([$audio])) throw new ApiError('ההקלטה לא נמצאה בספרייה');
    return ['word' => $word, 'meaning' => $meaning, 'topics' => $topics, 'audio' => $audio, 'external_id' => str($b['external_id'] ?? '', 40)];
}

function word_save(array $user, array $b): array
{
    $in = word_input($b);
    $pdo = db();
    $id = (int)($b['id'] ?? 0);
    $json = json_encode($in['topics'], JSON_UNESCAPED_UNICODE);
    // N2: ערכי השדות המותאמים — רק המפתחות שנשלחו משתנים; מילה חדשה מקבלת את ברירות המחדל
    $cur = [];
    if ($id) {
        $st = $pdo->prepare('SELECT extra FROM words WHERE id = ?');
        $st->execute([$id]);
        $x = $st->fetchColumn();
        if ($x === false) throw new ApiError('המילה לא נמצאה', 404);
        $cur = json_decode((string)$x, true) ?: [];
    }
    $extra = word_extra_input(fields_all(), $b['extra'] ?? null, $cur, !$id, $in);
    $ej = $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';
    if ($id) {
        $st = $pdo->prepare('UPDATE words SET word = ?, meaning = ?, topics = ?, audio = ?, external_id = ?, extra = ?, updated_at = ?, updated_by = ? WHERE id = ?');
        $st->execute([$in['word'], $in['meaning'], $json, $in['audio'], $in['external_id'], $ej, now_iso(), $user['name'], $id]);
        if (!$st->rowCount()) throw new ApiError('המילה לא נמצאה', 404);
    } else {
        $pdo->prepare('INSERT INTO words (word, meaning, topics, audio, external_id, extra, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$in['word'], $in['meaning'], $json, $in['audio'], $in['external_id'], $ej, now_iso(), $user['name']]);
        $id = (int)$pdo->lastInsertId();
    }
    $map = topic_slugs();
    $add = $pdo->prepare('INSERT OR IGNORE INTO topics (slug, name) VALUES (?, ?)');
    foreach ($in['topics'] as $t) $add->execute([topic_slug($t, $map), $t]);
    audit_as($user, empty($b['id']) ? 'word_add' : 'word_edit', $in['word']);
    if (!empty($b['light'])) {                       // עריכה בתא בטבלה — רק השורה, לא כל 1,500 המילים
        foreach (words_list()['words'] as $w) if ($w['id'] === $id) return ['id' => $id, 'word' => $w];
    }
    return ['id' => $id] + words_list();
}

/**
 * הערכים מהטבלה (עריכה בתא) ← extra: כל מפתח שנשלח נבדק לפי הסוג שלו (field_parse); null / '' — מוחק. מילה חדשה — ברירות
 * המחדל לשדות שלא נשלחו. חובה — נבדק לכל שדה (גם מובנה). שגיאה — ApiError עם שם השדה.
 */
function word_extra_input(array $fields, mixed $sent, array $cur, bool $new, array $builtin): array
{
    $by = array_column(fields_custom($fields), null, 'key');
    $out = array_intersect_key($cur, $by);                     // ערך של שדה שכבר אינו קיים — לא נשמר
    if ($sent !== null) {
        if (!is_array($sent) || ($sent && array_is_list($sent))) throw new ApiError('ערכי השדות חייבים להיות אובייקט');
        foreach ($sent as $k => $v) {
            $f = $by[$k] ?? throw new ApiError('שדה לא מוכר "' . mb_substr((string)$k, 0, 60) . '"');
            [$val, $err, $warn] = field_parse($f, $v);
            if ($err) throw new ApiError("{$f['label']}: $err");
            if ($warn && in_array($f['type'], ['image', 'audio'], true)) throw new ApiError("{$f['label']}: הקובץ לא נמצא בספרייה");
            if ($val === null) unset($out[$k]); else $out[$k] = $val;
        }
    }
    if ($new) foreach ($by as $k => $f) if (!array_key_exists($k, $out) && $f['def'] !== null && !array_key_exists($k, (array)$sent)) $out[$k] = $f['def'];
    foreach ($fields as $f) {
        if (!$f['required']) continue;
        $v = $f['col'] === null ? ($out[$f['key']] ?? null) : ($builtin[$f['col']] ?? null);
        if ($v === null || $v === '' || $v === []) throw new ApiError("\"{$f['label']}\" הוא שדה חובה");
    }
    ksort($out);
    return $out;
}

/**
 * נושא (הפעולה של לפני N2, נשארה לממשק הקיים): שינוי שם, או הסרה מכל המילים. N2: שינוי שם — ה-slug לא זז (topics_rename);
 * לשם שכבר קיים — מיזוג (topic_merge). הסרה — גם שורת הנושא, לסל עם ה-slug והמאפיינים שלו.
 */
function topic_op(array $user, array $b): array
{
    $name = str($b['name'] ?? '', 80);
    $to = str(preg_replace('/\s+/u', ' ', (string)($b['to'] ?? '')), 80);
    $op = (string)($b['op'] ?? '');
    if ($name === '' || !in_array($op, ['rename', 'delete'], true) || ($op === 'rename' && $to === '')) throw new ApiError('בקשה לא תקינה');
    $all = topics_all();
    $slug = array_column($all, 'slug', 'name')[$name] ?? throw new ApiError('הנושא לא נמצא', 404);
    if ($op === 'rename') {
        if ($to === $name) return words_list();
        $other = array_column($all, 'slug', 'name')[$to] ?? null;
        if ($other !== null) { topic_merge($user, ['from' => $slug, 'to' => $other]); return words_list(); }
        topic_save($user, ['slug' => $slug, 'name' => $to]);
        return words_list();
    }
    topic_remove($user, $slug);
    return words_list();
}

/** מסך הנושאים: כל הנושאים — גם בלי מילים — בסדר שלהם, עם תמונה לתצוגה. */
function topics_state(array $user): array
{
    $all = topics_all();
    $meta = files_meta(array_values(array_filter(array_column($all, 'image'), 'file_id')));
    return ['topics' => array_values(array_map(fn($t) => array_diff_key($t, ['row' => 1]) + ['image_src' => $t['image'] !== '' && isset($meta[$t['image']]) ? media_url($t['image']) : ''], $all)),
        'can_slug' => true, 'rev' => words_rev()];
}

/**
 * נושא: שם (ה-slug לא זז), צבע (#rrggbb), תמונה (מזהה קובץ image), מוסתר; או סדר (order: כל ה-slugs). שינוי slug — לא כאן
 * (topic_slug_preview / apply: עם עדכון הקשירות בקבצי הפרויקט).
 */
function topic_save(array $user, array $b): array
{
    $pdo = db();
    if (isset($b['order'])) {
        $all = topics_all();
        $seq = array_values(array_filter(is_array($b['order']) ? $b['order'] : [], 'is_string'));
        if (count($seq) !== count($all) || array_diff(array_keys($all), $seq)) throw new ApiError('הסדר חייב לכלול כל נושא פעם אחת');
        pf_tx(function () use ($seq) {
            topics_fill();
            $st = db()->prepare('UPDATE topics SET sort_order = ? WHERE slug = ?');
            foreach ($seq as $i => $s) $st->execute([($i + 1) * WORDS_ORDER_STEP, $s]);
        });
        audit_as($user, 'topic_edit', 'סדר הנושאים');
        return topics_state($user);
    }
    $slug = (string)($b['slug'] ?? '');
    $all = topics_all();
    $t = $all[$slug] ?? throw new ApiError('הנושא לא נמצא', 404);
    $u = [];
    if (array_key_exists('name', $b)) {
        $name = str(preg_replace('/\s+/u', ' ', (string)$b['name']), 80);
        if ($name === '') throw new ApiError('שם הנושא ריק');
        if ($name !== $t['name']) {
            foreach ($all as $o) if ($o['name'] === $name) throw new ApiError("כבר יש נושא בשם \"$name\" — \"מזג אל…\"", 409);
            $u['name'] = $name;
        }
    }
    if (array_key_exists('color', $b)) {
        $c = (string)$b['color'];
        if ($c !== '' && !preg_match(TOPIC_COLOR_RX, $c)) throw new ApiError('צבע — #rrggbb');
        $u['color'] = $c;
    }
    if (array_key_exists('image', $b)) {
        $img = (string)$b['image'];
        if ($img !== '' && (!file_id($img) || field_file_resolve($img, 'image') !== $img)) throw new ApiError('התמונה לא נמצאה בספרייה');
        $u['image'] = $img;
    }
    if (array_key_exists('hidden', $b)) $u['hidden'] = $b['hidden'] === true;
    if (!$u) return topics_state($user);
    $n = pf_tx(function () use ($user, $slug, $t, $u) {
        topics_fill();
        $n = isset($u['name']) ? topics_rename($user, [$slug => [$t['name'], $u['name']]]) : 0;
        topic_attrs($slug, $u);
        return $n;
    });
    audit_as($user, isset($u['name']) ? 'topic_rename' : 'topic_edit', isset($u['name']) ? "{$t['name']} → {$u['name']} ($n מילים, המפתח $slug נשאר)" : "{$t['name']}: " . implode(', ', array_keys($u)));
    return topics_state($user);
}

/** הסרה: מכל המילים, ושורת הנושא — לסל (עם הנושאים של כל מילה לפני, וה-slug והמאפיינים — "שחזור" מחזיר בדיוק). */
function topic_remove(array $user, string $slug): void
{
    $t = topics_all()[$slug] ?? throw new ApiError('הנושא לא נמצא', 404);
    snapshot_create($user, "לפני הסרת הנושא \"{$t['name']}\"");
    $n = pf_tx(function () use ($user, $t) {
        topics_fill();
        $before = topics_strip($user, $t['name'], null);
        $row = db()->prepare('SELECT * FROM topics WHERE slug = ?');
        $row->execute([$t['slug']]);
        db()->prepare('INSERT INTO topic_trash (at, by_name, op, name, to_name, before, row) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([now_iso(), $user['name'] ?? '', 'delete', $t['name'], null, json_encode((object)$before, JSON_UNESCAPED_UNICODE), json_encode($row->fetch(), JSON_UNESCAPED_UNICODE)]);
        db()->prepare('DELETE FROM topics WHERE slug = ?')->execute([$t['slug']]);
        return count($before);
    });
    audit_as($user, 'topic_delete', "{$t['name']} ($n מילים) → לסל");
}

/** מוציא את הנושא $name מכל המילים (ומוסיף $to במקומו, בלי כפילות). מחזיר את הנושאים של כל מילה לפני. בתוך עסקה. */
function topics_strip(array $user, string $name, ?string $to): array
{
    $pdo = db();
    $st = $pdo->prepare('UPDATE words SET topics = ?, updated_at = ?, updated_by = ? WHERE id = ?');
    $before = [];
    foreach ($pdo->query('SELECT id, topics FROM words')->fetchAll() as $r) {
        $t = json_decode($r['topics'], true) ?: [];
        if (!in_array($name, $t, true)) continue;
        $before[$r['id']] = $t;
        $t = array_values(array_unique(array_filter(array_map(fn($x) => $x === $name ? ($to ?? '') : $x, $t), fn($x) => $x !== '')));
        $st->execute([json_encode($t, JSON_UNESCAPED_UNICODE), now_iso(), $user['name'] ?? '', $r['id']]);
    }
    return $before;
}

/**
 * מיזוג א ← ב: כל מילה עם א מקבלת ב (בלי כפילות), שורת א — לסל (op=merge, עם הנושאים של כל מילה לפני). dry — כמה מילים,
 * וכמה קשירות ל-slug של א בקבצי הפרויקט (לעדכון — דרך topic_slug_apply, אחרי). שחזור מהסל מחזיר את שני הנושאים והשיוכים.
 */
function topic_merge(array $user, array $b): array
{
    $all = topics_all();
    $from = $all[(string)($b['from'] ?? '')] ?? throw new ApiError('הנושא לא נמצא', 404);
    $to = $all[(string)($b['to'] ?? '')] ?? throw new ApiError('נושא היעד לא נמצא', 404);
    if ($from['slug'] === $to['slug']) throw new ApiError('אי אפשר למזג נושא אל עצמו');
    $binds = function_exists('fields_bind_scan') ? fields_bind_scan('topic', $from['slug']) : [];
    if (!empty($b['dry'])) return ['words' => $from['count'], 'bindings' => array_values($binds)];
    $snap = snapshot_create($user, "לפני מיזוג הנושא \"{$from['name']}\" אל \"{$to['name']}\"");
    $n = pf_tx(function () use ($user, $from, $to) {
        topics_fill();
        $before = topics_strip($user, $from['name'], $to['name']);
        $row = db()->prepare('SELECT * FROM topics WHERE slug = ?');
        $row->execute([$from['slug']]);
        db()->prepare('INSERT INTO topic_trash (at, by_name, op, name, to_name, before, row) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([now_iso(), $user['name'] ?? '', 'merge', $from['name'], $to['name'], json_encode((object)$before, JSON_UNESCAPED_UNICODE), json_encode($row->fetch(), JSON_UNESCAPED_UNICODE)]);
        db()->prepare('DELETE FROM topics WHERE slug = ?')->execute([$from['slug']]);
        return count($before);
    });
    audit_as($user, 'topic_merge', "{$from['name']} ({$from['slug']}) → {$to['name']} ({$to['slug']}) · $n מילים");
    return topics_state($user) + ['snapshot' => $snap, 'merged' => $n, 'bindings' => array_values($binds)];
}

function word_delete(array $user, array $b): array
{
    $st = db()->prepare('SELECT * FROM words WHERE id = ?');
    $st->execute([(int)($b['id'] ?? 0)]);
    $row = $st->fetch() ?: throw new ApiError('המילה לא נמצאה', 404);
    // לסל המחזור (lib/restore.php) — השורה כולה, כדי שתחזור בדיוק
    db()->prepare('INSERT INTO words_trash (word_id, row, at, by_name) VALUES (?, ?, ?, ?)')
        ->execute([$row['id'], json_encode($row, JSON_UNESCAPED_UNICODE), now_iso(), $user['name']]);
    db()->prepare('DELETE FROM words WHERE id = ?')->execute([(int)$b['id']]);
    $word = $row['word'];
    audit_as($user, 'word_delete', (string)$word . ' → לסל');
    return words_list();
}

/** ייבוא: מחליף את כל המילים והנושאים. מה שהיה — נשמר לקובץ בתיקיית הייבוא (אם בכל זאת יהיה צורך). */
function words_replace(array $words, array $topics): int
{
    $pdo = db();
    $old = $pdo->query('SELECT COUNT(*) FROM words')->fetchColumn();
    if ((int)$old > 0) {
        $dir = files_dir() . '/import';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        file_put_contents("$dir/words-before-" . gmdate('Ymd-His') . '.json', words_snapshot());
    }
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM words');
    $pdo->exec('DELETE FROM topics');
    $t = $pdo->prepare('INSERT OR IGNORE INTO topics (slug, name) VALUES (?, ?)');
    foreach ($topics as $x) {
        $slug = is_string($x['slug'] ?? null) && preg_match('/^[\p{L}\p{N}%_-]{1,200}$/u', $x['slug']) ? $x['slug'] : '';
        $name = str($x['name'] ?? '', 80);
        if ($slug !== '' && $name !== '') $t->execute([$slug, $name]);
    }
    $ins = $pdo->prepare('INSERT INTO words (wp_id, word, meaning, topics, audio, external_id, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $n = 0;
    foreach ($words as $w) {
        if (!is_array($w)) continue;
        try { $in = word_input(['word' => $w['word'] ?? '', 'meaning' => $w['meaning'] ?? '', 'topics' => $w['topics'] ?? [], 'audio' => $w['audio_url'] ?? '', 'external_id' => $w['external_id'] ?? '']); }
        catch (ApiError) { continue; }
        $ins->execute([(int)($w['id'] ?? 0) ?: null, $in['word'], $in['meaning'], json_encode($in['topics'], JSON_UNESCAPED_UNICODE), $in['audio'], $in['external_id'], now_iso(), 'ייבוא']);
        $n++;
    }
    $pdo->commit();
    return $n;
}

/** עמוד חדש מנתוני המילון: פס אחד, בלי ריפוד משלנו, ובו רכיב המילון — עם ההגדרות והמבוא מהאתר. */
function lexicon_page(array $lex, string $title): array
{
    $s = is_array($lex['settings'] ?? null) ? $lex['settings'] : [];
    $intro = (string)($lex['intro_html'] ?? '');
    $parts = preg_split('/<!--\s*more\s*(.*?)-->/i', $intro, 2);
    $props = [
        'title_he' => $s['page_title_he'] ?? '', 'subtitle_main_he' => $s['page_subtitle_main_he'] ?? '', 'title_fr' => $s['page_title_fr'] ?? '',
        'title_en' => $s['page_title_en'] ?? '', 'subtitle_he' => $s['page_subtitle_he'] ?? '', 'credit_he' => $s['page_credit_he'] ?? '',
        'more_label' => $s['intro_more_label'] ?? '', 'less_label' => $s['intro_less_label'] ?? '',
        'hero_url' => $lex['hero_image'] ?? '', 'hero_overlay' => $lex['hero_overlay'] ?? 0, 'hero_min_height' => $s['hero_min_height'] ?? 0,
        'title_size' => $s['title_size_px'] ?? 56, 'subtitle_size' => $s['subtitle_size_px'] ?? 22, 'title_en_size' => $s['title_en_size_px'] ?? 24,
        'title_weight' => $s['title_weight'] ?? 700, 'subtitle_main_size' => $s['subtitle_main_size_px'] ?? 0, 'intro_max_width' => $s['intro_max_width_px'] ?? 780,
        'intro_before' => lex_autop(trim($parts[0] ?? '')), 'intro_after' => lex_autop(trim($parts[1] ?? '')),
        'feedback' => ($lex['feedback'] ?? false) === true, 'show_ids' => ($lex['show_external_id'] ?? false) === true,
    ];
    return ['settings' => ['title' => $title ?: 'קול מרוקו', 'font' => 'theme', 'width' => 'full'],
        'sections' => [['layout' => '100', 'pad' => '', 'full' => true, 'columns' => [['legacy' => [], 'widgets' => [['type' => 'lexicon', 'props' => $props]]]]]]];
}

/** כמו wpautop של וורדפרס, בפשטות: טקסט בלי פסקאות → <p> לכל קטע, <br> לשורה. HTML עם פסקאות — כמו שהוא. */
function lex_autop(string $html): string
{
    if ($html === '' || preg_match('/<(p|div|ul|ol|table|blockquote)\b/i', $html)) return $html;
    $out = '';
    foreach (preg_split('/\n\s*\n/', str_replace("\r", '', $html)) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') continue;
        $out .= preg_match('/^<h[1-6]\b/i', $chunk) && preg_match('/<\/h[1-6]>$/i', $chunk) ? $chunk : '<p>' . nl2br($chunk, false) . '</p>';
    }
    return $out;
}

// ── רינדור: אותו HTML כמו התבנית ───────────────────────────

function lexicon_render(array $w, bool $editor, string $cls, string $k): array
{
    $p = $w['props'];
    $words = words_rows();
    $map = topic_slugs();
    $topics = topics_used($words, $map);
    $ids = array_values(array_unique(array_filter(array_map(fn($x) => file_id($x['audio']), $words))));
    $GLOBALS['kol_files'] += files_meta(array_diff($ids, array_keys($GLOBALS['kol_files'])));
    $url = fn(string $id) => ($f = $GLOBALS['kol_files'][$id] ?? null) ? ($GLOBALS['kol_url'])($f) : '';
    // הקלטה שהועתקה מהאתר ולא הוחלפה — באתר מפנים לקובץ שכבר שם (לא נשלח שוב); בסטודיו — העותק שלנו
    $audioUrl = fn(string $id) => !$editor && ($o = $GLOBALS['kol_files'][$id]['origin'] ?? '') ? $o : $url($id);
    $hero = $p['hero_file'] ? $url($p['hero_file']) : $p['hero_url'];
    $ctx = lexicon_context($p, $words, $topics, $map, $audioUrl, $hero, $editor);
    // קבצי הקוד (לשונית </> קוד) — או התבנית המובנית. עם קבצים: ה-CSS כולו שלנו, וה-JS רץ במקום של ערכת הנושא
    $code = code_files();
    $tail = ".kol-page .kol-w-{$w['id']}{margin:0}";
    if ($code === null) {
        // התבנית המובנית: הנתונים מנוקים/מוגנים, וה-CSS וה-JS — של ערכת הנושא באתר
        return ["<div class=\"$cls\"$k>" . tpl_render(LEX_TPL, $ctx) . '</div>', tpl_render(LEX_TPL_SIZES, $ctx) . $tail];
    }
    // קבצי קוד: HTML מנוקה, CSS ממוקד במילון. JS שנערך — המילון כולו במסגרת מבודדת (באתר בלבד; הסטודיו כבר מבודד)
    $html = tpl_clean_html(tpl_render($code['lexicon.html'] ?? LEX_TPL, $ctx));
    $rawCss = tpl_render($code['lexicon.css'] ?? LEX_TPL_SIZES, $ctx);
    $js = (string)($code['lexicon.js'] ?? '');
    $jsEdited = trim($js) !== '' && trim($js) !== trim((string)setting('preview_js', ''));
    if ($editor) {
        $GLOBALS['kol_page_js'] = $js;
        return ["<div class=\"$cls\"$k>$html</div>", css_scope($rawCss) . $tail];
    }
    if ($jsEdited) return ["<div class=\"$cls\">" . lexicon_frame($w['id'], $html, $rawCss, $js, $p) . '</div>', $tail];
    // JS כמו של ערכת הנושא — היא מריצה את שלה; ה-CSS שלה מוחלף בשלנו (data-kol-css — התוסף לא טוען את שלה)
    return ["<div class=\"$cls\" data-kol-css=\"kadisha-lexicon\">$html</div>", css_scope($rawCss) . $tail];
}

// ── ההקלטות מהאתר → עותק בכלי ─────────────────────────────

/**
 * קובץ הקלטות מהתוסף (1.3, "ייצוא ההקלטות", חלק אחד מכמה): audio.json + הקבצים. כל הקלטה נכנסת לספרייה (עם origin —
 * הכתובת שלה באתר), ונקשרת לכל מילה שהצביעה על הכתובת הזו. קובץ שכבר הועתק — לא נכנס פעמיים.
 * A2: ההתאמה קודם לפי מזהה המילה (word_id בייצוא = wp_id, או מספר בשם הקובץ), ורק אחר כך לפי הכתובת. מה שלא הותאם —
 * ב-unmatched_list. ההקלטות נכנסות למודל (lib/recordings.php) עם המקור של המילה.
 */
function audio_import(array $owner, array $b): array
{
    @set_time_limit(1800);
    $u = upload_get($owner, (string)($b['id'] ?? ''));
    if (($u['purpose'] ?? '') !== 'import') throw new ApiError('ההעלאה הזו אינה ייבוא');
    $path = tmp_path($u['id']);
    clearstatcache(true, $path);
    if ((int)@filesize($path) !== (int)$u['size']) throw new ApiError('ההעלאה לא הושלמה', 409);
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new ApiError('הקובץ אינו ZIP תקין');
    $j = json_decode((string)$zip->getFromName('audio.json'), true);
    if (!is_array($j) || ($j['format'] ?? '') !== 'kol-audio' || !is_array($j['files'] ?? null)) {
        $zip->close();
        throw new ApiError('זה לא קובץ הקלטות של קול מרוקו (חסר audio.json). בוורדפרס: הגדרות ← קול מרוקו ← "ייצוא ההקלטות"');
    }
    snapshot_create($owner, 'לפני ייבוא הקלטות');
    require_once __DIR__ . '/recordings.php';
    rec_sync();                                         // הרשומות קיימות לפני הקישור — כך המקור נשאר הכתובת של המילה
    $pdo = db();
    $st = ['part' => (int)($j['part'] ?? 0), 'parts' => (int)($j['parts'] ?? 0), 'linked' => 0, 'files' => 0, 'already' => 0, 'unmatched' => 0, 'failed' => [], 'unmatched_list' => []];
    $byWp = $pdo->prepare('SELECT id, word, audio FROM words WHERE wp_id = ?');
    $linkId = $pdo->prepare('UPDATE words SET audio = ? WHERE id = ?');
    $imported = [];
    $words = $pdo->prepare('SELECT word FROM words WHERE audio = ?');
    $copy = $pdo->prepare('SELECT id FROM files WHERE origin = ?');
    $link = $pdo->prepare('UPDATE words SET audio = ? WHERE audio = ?');
    $dir = files_dir() . '/tmp';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    foreach ($j['files'] as $f) {
        $url = lex_url($f['url'] ?? '');
        $entry = (string)($f['file'] ?? '');
        if ($url === '') { $st['failed'][] = (string)($f['word'] ?? '?') . ' — כתובת לא מהאתר'; continue; }
        // לפי מזהה: word_id של הייצוא (= wp_id), ואם אין — מספר בשם הקובץ ("6.mp3"). מילה שכבר יש לה קובץ בכלי — לא נדרסת
        $wpid = (int)($f['word_id'] ?? 0);
        if (!$wpid && preg_match('~(?:^|/)(\d{1,9})\.[a-z0-9]{1,5}$~i', $entry, $m)) $wpid = (int)$m[1];
        $idWords = [];
        if ($wpid) { $byWp->execute([$wpid]); $idWords = array_filter($byWp->fetchAll(), fn($w) => !file_id($w['audio'])); }
        $words->execute([$url]);
        $ws = $words->fetchAll(PDO::FETCH_COLUMN);
        $copy->execute([$url]);
        $fid = $copy->fetchColumn();
        if (!$ws && !$idWords) {
            $fid ? $st['already']++ : $st['unmatched']++;
            if (!$fid && count($st['unmatched_list']) < 200) $st['unmatched_list'][] = ['word' => mb_substr((string)($f['word'] ?? ''), 0, 200), 'word_id' => $wpid ?: null, 'url' => $url, 'file' => mb_substr($entry, 0, 300)];
            continue;
        }
        $first = $idWords ? reset($idWords)['word'] : $ws[0];
        if (!$fid) {
            $stat = $zip->statName($entry);
            if (!str_starts_with($entry, 'audio/') || str_contains($entry, '..') || !$stat || $stat['size'] > FILE_MAX) {
                $st['failed'][] = $first . ' — הקובץ חסר או גדול מ-50MB';
                continue;
            }
            $tmp = $dir . '/aud-' . bin2hex(random_bytes(8));
            $in = $zip->getStream($entry);
            $out = fopen($tmp, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION)) ?: 'mp3';
            try {
                $fid = file_store($tmp, mb_substr($first, 0, 80) . '.' . $ext, $owner, 'audio/');
            } catch (ApiError $e) {
                $st['failed'][] = $first . ' — ' . $e->getMessage();
                continue;
            }
            $pdo->prepare('UPDATE files SET origin = ? WHERE id = ?')->execute([$url, $fid]);
            $st['files']++;
        }
        $n = 0;
        foreach ($idWords as $w) { $linkId->execute([$fid, $w['id']]); $n += $linkId->rowCount(); }
        $link->execute([$fid, $url]);
        $st['linked'] += $n + $link->rowCount();
        $imported[] = $fid;
    }
    $zip->close();
    $pdo->prepare('DELETE FROM uploads WHERE id = ?')->execute([$u['id']]);
    @unlink($path);
    $st['remaining'] = (int)$pdo->query("SELECT COUNT(*) FROM words WHERE audio LIKE 'https://%'")->fetchColumn();
    rec_sync();
    if ($imported) {
        $mark = rec_db()->prepare("UPDATE recordings SET how = 'imported' WHERE file_id = ?");
        foreach (array_unique($imported) as $id) $mark->execute([$id]);
    }
    audit_as($owner, 'audio_import', "חלק {$st['part']} מתוך {$st['parts']} · {$st['files']} הקלטות");
    return ['stats' => $st];
}

// ── אקסל (CSV) ────────────────────────────────────────────

// N1: הסדר (sort_order) והמזהה באתר (wp_id, לקריאה בלבד) — עמודות נוספות. ההקלטה — אותו ערך כמו ב-data/words.json
const CSV_COLS = ['id' => 'מזהה', 'word' => 'מילה', 'meaning' => 'פירוש', 'topics' => 'נושאים', 'audio' => 'הקלטה', 'external_id' => 'מספר מהאקסל',
    'sort_order' => 'סדר', 'wp_id' => 'מזהה באתר'];

/** פירוש (HTML) → טקסט: פסקה — שורה ריקה, שבירת שורה — שורה. */
function lex_plain(string $html): string
{
    $t = preg_replace(['~</p>\s*~i', '~<br\s*/?>~i', '~</li>\s*~i'], ["\n\n", "\n", "\n"], $html);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace("/[ \t]+\n/", "\n", str_replace("\r", '', $t));
    return trim(preg_replace("/\n{3,}/", "\n\n", $t));
}

function lex_from_plain(string $t): string
{
    $out = '';
    foreach (preg_split("/\n\s*\n/", trim(str_replace("\r", '', $t))) as $p) {
        if (trim($p) !== '') $out .= '<p>' . nl2br(e(trim($p)), false) . '</p>';
    }
    return $out;
}


// ── N1: הסדר הידני ────────────────────────────────────────

const WORDS_ORDER_STEP = 10;
const WORDS_BULK = 20;                       // מעל זה (או מחיקה כלשהי) — נקודת שחזור; מחיקה של יותר — publisher

/**
 * הסדר בפועל. sort_order ריק (אחרי מיגרציה 17, או מילה שנוספה בלי סדר): כשכולן ריקות — הסדר של לפני N1 בדיוק
 * (lex_sort על השורות בסדר ה-id — כמו ה-SELECT של אז), בקפיצות של 10; אחרת — הריקות לסוף, לפי id. קריאה אינה כותבת
 * דבר; הערכים נכתבים (words_order_fill) רק בפעולה שמשנה סדר. $rows — בסדר id.
 */
function words_order_effective(array $rows): array
{
    $set = $empty = [];
    foreach ($rows as $r) { if ($r['sort_order'] === null) $empty[] = $r; else { $r['sort_order'] = (int)$r['sort_order']; $set[] = $r; } }
    if (!$set) { $empty = lex_sort($empty, 'word'); $n = 0; }
    else {
        usort($set, fn($a, $b) => $a['sort_order'] <=> $b['sort_order'] ?: $a['id'] <=> $b['id']);
        $n = max(array_column($set, 'sort_order'));
    }
    foreach ($empty as &$r) $r['sort_order'] = $n += WORDS_ORDER_STEP;
    unset($r);
    return array_merge($set, $empty);
}

/** כותב לטבלה את הסדר שבפועל לשורות שעוד ריקות — לפני כל פעולה שמשנה סדר (בתוך העסקה שלה). שום מילה אינה משתנה. */
function words_order_fill(bool $inTx = false): void
{
    $pdo = db();
    if (!$pdo->query('SELECT 1 FROM words WHERE sort_order IS NULL LIMIT 1')->fetchColumn()) return;
    $empty = array_flip(array_map('intval', $pdo->query('SELECT id FROM words WHERE sort_order IS NULL')->fetchAll(PDO::FETCH_COLUMN)));
    $rows = $pdo->query('SELECT id, word, sort_order FROM words ORDER BY id')->fetchAll();
    foreach ($rows as &$r) $r['id'] = (int)$r['id'];
    unset($r);
    $st = $pdo->prepare('UPDATE words SET sort_order = ? WHERE id = ?');
    $run = function () use ($rows, $empty, $st) { foreach (words_order_effective($rows) as $r) if (isset($empty[$r['id']])) $st->execute([$r['sort_order'], $r['id']]); };
    if ($inTx || $pdo->inTransaction()) { $run(); return; }        // BEGIN IMMEDIATE ב-exec אינו נראה ב-inTransaction()
    $pdo->exec('BEGIN IMMEDIATE');
    try { $run(); $pdo->exec('COMMIT'); } catch (Throwable $e) { $pdo->exec('ROLLBACK'); throw $e; }
}

/**
 * הסדר החדש $seq (רשימת מזהים; מילה חדשה — מחרוזת "new:<n>") ← ערכי sort_order. נשארים במקומם הרוב: התת-סדרה העולה
 * הארוכה ביותר של הערכים הנוכחיים. רק השאר מקבלים ערך בין השכנים; אין מקום — מספור מחדש של הכול (בקפיצות של 10).
 * מחזיר [מזהה => ערך] רק למה שמשתנה, ואת מספר המילים הקיימות שזזו.
 */
function words_order_assign(array $seq, array $cur): array
{
    $vals = [];                                    // אינדקס ב-$seq → ערך נוכחי (רק מילים קיימות)
    foreach ($seq as $i => $id) if (is_int($id) && isset($cur[$id])) $vals[$i] = (int)$cur[$id];
    // LIS (עולה ממש) — O(n log n), עם שחזור
    $tails = []; $tailIdx = []; $prev = [];
    foreach ($vals as $i => $v) {
        $lo = 0; $hi = count($tails);
        while ($lo < $hi) { $m = ($lo + $hi) >> 1; if ($tails[$m] < $v) $lo = $m + 1; else $hi = $m; }
        $tails[$lo] = $v; $tailIdx[$lo] = $i; $prev[$i] = $lo > 0 ? $tailIdx[$lo - 1] : null;
    }
    $keep = [];
    for ($i = $tails ? $tailIdx[count($tails) - 1] : null; $i !== null; $i = $prev[$i]) $keep[$i] = true;
    $out = [];
    $n = count($seq);
    for ($i = 0; $i < $n; ) {
        if (isset($keep[$i])) { $i++; continue; }
        $j = $i;
        while ($j < $n && !isset($keep[$j])) $j++;
        $k = $j - $i;
        $lo = $i > 0 ? $vals[$i - 1] : null;
        $hi = $j < $n ? $vals[$j] : null;
        if ($lo === null && $hi === null) { $lo = 0; $step = WORDS_ORDER_STEP; }
        elseif ($hi === null) $step = WORDS_ORDER_STEP;
        elseif ($lo === null) { $lo = $hi - WORDS_ORDER_STEP * ($k + 1); $step = WORDS_ORDER_STEP; }
        else {
            $step = intdiv($hi - $lo, $k + 1);
            if ($step < 1) {                       // אין מקום בין השכנים — מספור מחדש
                $out = [];
                foreach ($seq as $x => $id) if (!is_int($id) || !isset($cur[$id]) || (int)$cur[$id] !== ($x + 1) * WORDS_ORDER_STEP) $out[$id] = ($x + 1) * WORDS_ORDER_STEP;
                $moved = count(array_filter(array_keys($out), fn($id) => is_int($id) && isset($cur[$id])));
                return [$out, $moved];
            }
        }
        for ($x = $i; $x < $j; $x++) $out[$seq[$x]] = $lo + $step * ($x - $i + 1);
        $i = $j;
    }
    $moved = 0;
    foreach ($out as $id => $v) {
        if (is_int($id) && isset($cur[$id])) { if ((int)$cur[$id] === $v) unset($out[$id]); else $moved++; }
    }
    return [$out, $moved];
}

/** גרירה בטבלה / "העבר ל…": המילים $ids (בסדר שלהן) לפני המילה before, או למקום to (1 = ראשון). נכתב רק מה שזז. */
function word_move(array $user, array $b): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', is_array($b['ids'] ?? null) ? array_slice($b['ids'], 0, 5000) : []), fn($x) => $x > 0)));
    if (!$ids) throw new ApiError('לא נבחרה מילה');
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        words_order_fill(true);
        $cur = [];
        foreach ($pdo->query('SELECT id, sort_order FROM words ORDER BY sort_order, id')->fetchAll() as $r) $cur[(int)$r['id']] = (int)$r['sort_order'];
        foreach ($ids as $id) if (!isset($cur[$id])) throw new ApiError("המילה $id לא נמצאה", 404);
        $pick = array_flip($ids);
        $rest = array_values(array_filter(array_keys($cur), fn($id) => !isset($pick[$id])));
        // הסדר של המילים הנגררות — כמו בטבלה (בחירה מרובה זזה יחד)
        usort($ids, fn($x, $y) => $cur[$x] <=> $cur[$y] ?: $x <=> $y);
        if (isset($b['before']) && $b['before'] !== null && $b['before'] !== '') {
            $at = array_search((int)$b['before'], $rest, true);
            if ($at === false) throw new ApiError('מקום היעד לא נמצא', 404);
        } elseif (isset($b['to'])) {
            $at = max(0, min(count($rest), (int)$b['to'] - 1));
        } else $at = count($rest);
        array_splice($rest, $at, 0, $ids);
        [$set, $moved] = words_order_assign($rest, $cur);
        $st = $pdo->prepare('UPDATE words SET sort_order = ? WHERE id = ?');
        foreach ($set as $id => $v) $st->execute([$v, $id]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) { $pdo->exec('ROLLBACK'); throw $e; }
    if ($moved) audit_as($user, 'word_move', count($ids) . ' מילים → מקום ' . ($at + 1));
    return ['moved' => $moved, 'sort' => (object)$set, 'rev' => words_rev()];
}

// ── N1: data/words.json — הפנים השניות של הטבלה ──────────

/**
 * הקובץ, מהטבלה, ברגע הקריאה — פונקציה אחת לתצוגה החיה (pv_words), לבנייה (M1, דרך pv_words) ולעורך הקוד.
 * $app: null — הקובץ כפי שהוא בעץ (הקלטה שלנו = media/audio/<מזהה>.<סיומת>, כמו בחבילה; יציב, בלי חתימה מתחלפת);
 * מחרוזת — כתובת האפליקציה, לתצוגה החיה (הקלטה = כתובת חתומה להשמעה). שני רווחים, UTF-8 בלי escape.
 */
function words_json(?string $app = null): string
{
    $rows = words_rows();
    $fields = fields_all();
    $all = topics_all($rows);
    $map = array_column($all, 'slug', 'name');
    // N2: לתצוגה החיה ולבנייה ($app) — רק שדות "מתפרסם"; לעורך — הכול
    $use = array_values(array_filter($fields, fn($f) => $app === null || $f['published']));
    $ids = array_merge(array_map(fn($w) => file_id($w['audio']), $rows), fields_file_ids($rows, $use), array_column($all, 'image'));
    $meta = $app === null ? files_meta(array_values(array_unique(array_filter($ids, 'file_id')))) : [];
    $items = [];
    $hasTopics = in_array('topics', array_column($use, 'col'), true);
    foreach ($rows as $w) {
        $item = ['id' => $w['id']];
        foreach ($use as $f) {
            if ($f['col'] === null) continue;
            $item[$f['key']] = match ($f['col']) {
                'topics' => array_map(fn($t) => topic_slug((string)$t, $map), $w['topics']),
                'audio' => $app === null ? words_audio_ref((string)$w['audio'], $meta) : (($a = (string)$w['audio']) === '' ? '' : (file_id($a) ? $app . media_url($a) : $a)),
                default => $w[$f['col']],
            };
        }
        // המזהה באתר (למשוב) והמזהה מהאקסל — רק למילה שיש לה (F3: הכרטיס המוסב נקשר אליהם)
        if (($w['wp_id'] ?? null) !== null) $item['wp_id'] = $w['wp_id'];
        if ((string)($w['external_id'] ?? '') !== '') $item['external_id'] = $w['external_id'];
        // N2: השדות המותאמים — אחרי המובנים, בסדר שלהם; ערך ריק — המפתח חסר
        foreach ($use as $f) if ($f['col'] === null && array_key_exists($f['key'], $w['extra'])) $item[$f['key']] = field_out($f, $w['extra'][$f['key']], $meta, $app);
        $items[] = $item;
    }
    $j = $hasTopics ? ['topics' => topics_out($all, $app, $meta)] : [];          // "נושאים" שאינו מתפרסם — גם בלי הרשימה
    return words_json_encode($j + ['items' => $items]);
}

function words_json_encode(array $j): string
{
    $s = json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    // ארבעה רווחים ← שניים. בתוך מחרוזת JSON אין ירידת שורה גולמית, ולכן רווחים בתחילת שורה הם תמיד הזחה
    return preg_replace_callback('/^( +)/m', fn($m) => str_repeat(' ', intdiv(strlen($m[1]), 2)), $s) . "\n";
}

/** ההקלטה כפי שהיא בקובץ: שלנו — media/audio/<מזהה>.<סיומת> (כמו בחבילה של M1); כתובת באתר — כמו שהיא. */
function words_audio_ref(string $a, array $meta): string
{
    if ($a === '' || !file_id($a)) return $a;
    $f = $meta[$a] ?? null;
    return $f ? 'media/audio/' . $a . '.' . $f['ext'] : $a;
}

/** ה-rev של הקובץ: חותמת של התוכן שלו (הטבלה + הנושאים + הסדר). כל שינוי בטבלה — rev אחר, ועורך פתוח יודע. */
function words_rev(?string $json = null): string
{
    return 'w' . substr(hash('sha256', ($json ?? words_json()) . '|' . fields_sig()), 0, 16);       // N2: גם המבנה
}

/** offset בבייטים → [שורה, עמודה] (עמודה בתווים, כמו ב-Monaco). */
function wj_linecol(string $s, int $off): array
{
    $off = max(0, min(strlen($s), $off));
    $before = substr($s, 0, $off);
    $nl = strrpos($before, "\n");
    $line = substr_count($before, "\n") + 1;
    $col = mb_strlen($nl === false ? $before : substr($before, $nl + 1), 'UTF-8') + 1;
    return [$line, $col];
}

/**
 * מנתח JSON שמחזיר גם את המיקום של כל ערך ("items/3/word" → offset), ושגיאה עם מיקום. דוחה מפתח כפול באותו אובייקט
 * (json_decode לוקח בשקט את האחרון) ועומק מעל 64. הערכים עצמם — מ-json_decode (אותה סמנטיקה בדיוק).
 */
function wj_scan(string $s): array
{
    $pos = [];
    $i = 0;
    $n = strlen($s);
    $err = function (string $m, int $at) { throw new RangeException($m, $at); };
    $ws = function () use ($s, &$i) { $i += strspn($s, " \t\r\n", $i); };
    $str = function () use ($s, &$i, $err): string {
        if (!preg_match('/\G"(?:[^"\\\\\x00-\x1F]++|\\\\(?:["\\\\\/bfnrt]|u[0-9a-fA-F]{4}))*+"/', $s, $m, 0, $i)) $err('מחרוזת לא תקינה (מירכאות חסרות, או תו בקרה בתוכה)', $i);
        $i += strlen($m[0]);
        return $m[0];
    };
    $val = function (string $path, int $depth) use (&$val, $s, &$i, $n, &$pos, $err, $ws, $str): void {
        if ($depth > 64) $err('עומק גדול מדי', $i);
        $ws();
        if ($i >= $n) $err('הקובץ נגמר באמצע', $i);
        if ($depth <= 4) $pos[$path] = $i;
        $c = $s[$i];
        if ($c === '{') {
            $i++; $ws();
            if (($s[$i] ?? '') === '}') { $i++; return; }
            $seen = [];
            while (true) {
                $ws();
                if (($s[$i] ?? '') !== '"') $err('ציפיתי לשם שדה במירכאות', $i);
                $kAt = $i;
                $k = json_decode($str());
                if (isset($seen[$k])) $err("השדה \"$k\" מופיע פעמיים באותו אובייקט", $kAt);
                $seen[$k] = true;
                $ws();
                if (($s[$i] ?? '') !== ':') $err('ציפיתי לנקודתיים אחרי שם השדה', $i);
                $i++;
                if ($depth <= 3) $pos[$path . '/' . $k . '#key'] = $kAt;
                $val($path . '/' . $k, $depth + 1);
                $ws();
                $c = $s[$i] ?? '';
                if ($c === ',') { $i++; continue; }
                if ($c === '}') { $i++; return; }
                $err('ציפיתי לפסיק או ל-}', $i);
            }
        }
        if ($c === '[') {
            $i++; $ws();
            if (($s[$i] ?? '') === ']') { $i++; return; }
            for ($k = 0; ; $k++) {
                $val($path . '/' . $k, $depth + 1);
                $ws();
                $c = $s[$i] ?? '';
                if ($c === ',') { $i++; continue; }
                if ($c === ']') { $i++; return; }
                $err('ציפיתי לפסיק או ל-]', $i);
            }
        }
        if ($c === '"') { $str(); return; }
        if (preg_match('/\G(?:-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?|true|false|null)/', $s, $m, 0, $i)) { $i += strlen($m[0]); return; }
        $err('ערך לא תקין', $i);
    };
    try {
        if (!mb_check_encoding($s, 'UTF-8')) {
            $bad = 0;
            foreach (explode("\n", $s) as $line) { if (!mb_check_encoding($line, 'UTF-8')) break; $bad += strlen($line) + 1; }
            $err('הקובץ אינו UTF-8 תקין', $bad);
        }
        $val('', 0);
        $ws();
        if ($i < $n) $err('יש תוכן אחרי סוף ה-JSON', $i);
    } catch (RangeException $e) {
        return ['error' => [$e->getMessage(), $e->getCode()], 'pos' => $pos, 'value' => null];
    }
    $v = json_decode($s, true, 512, JSON_BIGINT_AS_STRING);
    if (json_last_error()) return ['error' => [json_last_error_msg(), 0], 'pos' => $pos, 'value' => null];
    return ['error' => null, 'pos' => $pos, 'value' => $v];
}

/** הנתונים הנוכחיים שהמנוע משווה אליהם: השורות לפי id, הסדר, מפת הנושאים (שם ↔ מפתח), וההקלטות כפי שהן בקובץ. */
function words_state(): array
{
    $rows = words_rows();
    $fields = fields_all();
    $all = topics_all($rows);
    $map = array_column($all, 'slug', 'name');                  // גם מפתחות של שמות שאינם בטבלת הנושאים
    $meta = files_meta(array_values(array_unique(array_filter(array_merge(array_map(fn($w) => file_id($w['audio']), $rows), fields_file_ids($rows, $fields), array_column($all, 'image')), 'file_id'))));
    $by = [];
    foreach ($rows as $w) {
        $w['slugs'] = array_map(fn($t) => topic_slug((string)$t, $map), $w['topics']);
        $w['audio_ref'] = words_audio_ref((string)$w['audio'], $meta);
        $w['extra_ref'] = [];                                   // N2: הערכים כפי שהם בקובץ (image/audio — נתיב)
        foreach (fields_custom($fields) as $f) if (array_key_exists($f['key'], $w['extra'])) $w['extra_ref'][$f['key']] = field_out($f, $w['extra'][$f['key']], $meta, null);
        $by[$w['id']] = $w;
    }
    return ['by' => $by, 'order' => array_column($rows, 'id'), 'slug_name' => array_flip($map), 'name_slug' => $map,
        'fields' => $fields, 'topics' => $all, 'meta' => $meta];
}

/** הקלטה מהקובץ ← ערך לטבלה: מזהה בספרייה, media/audio/<מזהה>.*, קובץ מדיה בעץ הפרויקט, או כתובת https באתר. אחרת — null. */
function words_audio_resolve(string $v): ?string
{
    $v = trim($v);
    if ($v === '') return '';
    if (preg_match('~^(?:\./)?media/audio/([0-9a-f]{32})\.[A-Za-z0-9]{1,8}$~', $v, $m)) $v = $m[1];
    if (file_id($v)) { $f = files_meta([$v])[$v] ?? null; return $f && ($f['deleted_at'] ?? null) === null ? $v : null; }
    if (lex_url($v) !== '') return lex_url($v);
    if (function_exists('pf_by_path')) {
        try { $r = pf_by_path(pf_path($v)); } catch (ApiError) { $r = null; }
        if ($r && $r['kind'] === 'media' && file_id((string)$r['file_id'])) return (string)$r['file_id'];
    }
    return null;
}

const WJ_ITEM_KEYS = ['id', 'wp_id', 'external_id'];          // N2: ועוד המפתחות של השדות (fields_all) — הרשימה דינמית

/** סוג משוער לשדה חדש מהערכים שבקובץ / בגיליון: מספרים → number, true/false → bool, רשימות → list, אחרת text. */
function field_guess(array $values, bool $csv = false): string
{
    $values = array_values(array_filter($values, fn($v) => $v !== null && $v !== ''));
    if (!$values) return 'text';
    if ($csv) {
        if (!array_filter($values, fn($v) => !preg_match('/^-?\d{1,15}(?:[.,]\d{1,10})?$/', trim((string)$v)))) return 'number';
        if (!array_filter($values, fn($v) => !in_array(mb_strtolower(trim((string)$v)), ['כן', 'לא', 'true', 'false'], true))) return 'bool';
        return 'text';
    }
    if (!array_filter($values, fn($v) => is_bool($v) || (!is_int($v) && !is_float($v)))) return 'number';
    if (!array_filter($values, fn($v) => !is_bool($v))) return 'bool';
    if (!array_filter($values, fn($v) => !is_array($v) || !array_is_list($v))) return 'list';
    return 'text';
}

/** שדה חדש שאושר מתוך קובץ / גיליון (עורך) — ההגדרה שנכנסת ל-word_fields בהחלה. */
function field_proposed(string $key, string $type): array
{
    return field_norm(['key' => $key, 'label' => $key, 'type' => $type, 'col' => null, 'required' => 0, 'def' => null, 'help' => '', 'options' => '[]', 'published' => 1, 'dir' => 'auto', 'sort_order' => 0]);
}

/** חובה: לכל שדה required — יש ערך (מובנה: בעמודה; מותאם: ב-extra). מחזיר את השדה החסר הראשון, או null. */
function fields_required_missing(array $fields, array $o, ?array $cur): ?array
{
    foreach ($fields as $f) {
        if (!$f['required']) continue;
        $v = $f['col'] === null ? (($o['extra'] ?? $cur['extra'] ?? [])[$f['key']] ?? null) : ($o[$f['col']] ?? $cur[$f['col']] ?? null);
        if ($v === null || $v === '' || $v === []) return $f;
    }
    return null;
}

/**
 * הקובץ ← רשימת פריטים מנורמלים, או שגיאות עם שורה ועמודה. ערך שזהה לערך שבטבלה נשאר בדיוק כמו בטבלה (בלי ניקוי
 * חוזר) — כך קובץ שלא נערך לא "משנה" אף מילה. ערך שהשתנה עובר את אותו ניקוי כמו עריכה בטבלה (word_input).
 * N2: המפתחות — לפי "מבנה המילה" (מובנה ששמו שונה, ושדות מותאמים, לפי סוג); מפתח לא מוכר — הצעה "צור שדה" ($opt['fields']);
 * בחירה שאינה ברשימה — הצעה "הוסף אפשרות" ($opt['options'], "key:value"); נושא קיים — שם, צבע, תמונה, מוסתר וסדר נשמרים.
 */
function words_file_items(string $bytes, array $state, array $create, array $opt = []): array
{
    $scan = wj_scan($bytes);
    $errors = []; $warnings = []; $proposals = []; $fieldProps = []; $optionProps = [];
    $fields = $state['fields'] ?? fields_all();
    $c2k = fields_colkeys($fields);
    $k2c = array_flip(array_intersect_key($c2k, array_flip(array_column(array_filter($fields, fn($f) => $f['col'] !== null), 'col'))));
    $custom = array_column(fields_custom($fields), null, 'key');
    // נתיב לפי שם העמודה (/items/3/meaning) ← הנתיב בקובץ (/items/3/<המפתח של "פירוש">). "!" — מפתח של שדה מותאם, כמו שהוא
    $at = function (string $path) use ($scan, $bytes, $c2k): array {
        if (str_contains($path, '/!')) $path = str_replace('/!', '/', $path);
        else $path = preg_replace_callback('~^(/items/\d+/)(word|meaning|topics|audio)(?=$|#|/)~', fn($m) => $m[1] . $c2k[$m[2]], $path);
        $o = $scan['pos'][$path] ?? $scan['pos'][preg_replace('~/[^/]*$~', '', $path)] ?? 0;
        return wj_linecol($bytes, $o);
    };
    $add = function (array &$list, string $path, string $msg, array $more = []) use ($at) { [$l, $c] = $at($path); $list[] = ['line' => $l, 'col' => $c, 'msg' => $msg] + $more; };
    if ($scan['error']) { [$l, $c] = wj_linecol($bytes, $scan['error'][1]); return ['errors' => [['line' => $l, 'col' => $c, 'msg' => 'JSON לא תקין: ' . $scan['error'][0]]]]; }
    $j = $scan['value'];
    // {} ו-[] מתפענחים שניהם למערך ריק — ההבחנה לפי התו בקובץ. "items": {} אינו "רשימה ריקה" (שהייתה מעבירה הכול לסל)
    $is = fn(string $path, string $ch) => ($bytes[$scan['pos'][$path] ?? -1] ?? '') === $ch;
    if (!is_array($j) || !$is('', '{')) return ['errors' => [['line' => 1, 'col' => 1, 'msg' => 'הקובץ חייב להיות אובייקט: { "topics": [...], "items": [...] }']]];
    foreach (array_keys($j) as $k) if (!in_array($k, ['topics', 'items'], true)) $add($errors, "/$k#key", "שדה לא מוכר \"$k\" בראש הקובץ — רק \"topics\" ו-\"items\". הסירו אותו כדי לשמור");
    if (!isset($j['items']) || !is_array($j['items']) || !array_is_list($j['items']) || !$is('/items', '[')) {
        $add($errors, isset($j['items']) ? '/items' : '', '"items" חסר, או שאינו רשימה');
        return ['errors' => $errors];
    }
    // הנושאים שבראש הקובץ: שם לנושא חדש, ו-N2 — שם / צבע / תמונה / מוסתר / סדר של נושא קיים
    $labels = []; $topicUpd = []; $topicSeq = [];
    $topics = $state['topics'] ?? [];
    if (isset($j['topics'])) {
        if (!is_array($j['topics']) || !array_is_list($j['topics']) || !$is('/topics', '[')) $add($errors, '/topics', '"topics" חייב להיות רשימה של { "id", "label" }');
        else {
            $names = [];
            foreach ($j['topics'] as $ti => $t) {
                $tp = "/topics/$ti";
                if (!is_array($t) || !$is($tp, '{') || !is_string($t['id'] ?? null) || !is_string($t['label'] ?? null) || array_diff(array_keys($t), ['id', 'label', 'color', 'image', 'hidden'])) {
                    $add($errors, $tp, 'נושא חייב להיות { "id", "label", "color"?, "image"?, "hidden"? } (id ו-label — מחרוזות)');
                    continue;
                }
                $labels[$t['id']] = $t['label'];
                $cur = $topics[$t['id']] ?? null;
                if (!$cur) continue;
                $topicSeq[] = $t['id'];
                $u = [];
                $label = str(preg_replace('/\s+/u', ' ', $t['label']), 80);
                if ($label === '') { $add($errors, "$tp/label", 'שם הנושא ריק'); continue; }
                if ($label !== $cur['name']) $u['name'] = $label;
                $color = $t['color'] ?? '';
                if (!is_string($color) || ($color !== '' && !preg_match(TOPIC_COLOR_RX, $color))) { $add($errors, "$tp/color", 'צבע — "#rrggbb" (שש ספרות הקסדצימליות, אותיות קטנות)'); continue; }
                if ($color !== $cur['color']) $u['color'] = $color;
                $img = $t['image'] ?? '';
                if (!is_string($img)) { $add($errors, "$tp/image", 'תמונה — נתיב media/images/… או מזהה קובץ'); continue; }
                $curRef = $cur['image'] === '' ? '' : (string)field_out(['type' => 'image', 'key' => 'image'], $cur['image'], $state['meta'] ?? [], null);
                if ($img !== $curRef) {
                    $r = field_file_resolve($img, 'image');
                    if ($r === null) $add($warnings, "$tp/image", "התמונה \"" . mb_substr($img, 0, 80) . '" לא נמצאה בספרייה — נשארת כמו שהייתה');
                    elseif ($r !== $cur['image']) $u['image'] = $r;
                }
                $hidden = $t['hidden'] ?? false;
                if (!is_bool($hidden)) { $add($errors, "$tp/hidden", '"hidden" — true או false'); continue; }
                if ($hidden !== $cur['hidden']) $u['hidden'] = $hidden;
                $newName = $u['name'] ?? $cur['name'];
                if (isset($names[$newName])) { $add($errors, "$tp/label", "שני נושאים בשם \"$newName\""); continue; }
                $names[$newName] = true;
                if (isset($u['name']) && isset($state['name_slug'][$u['name']]) && $state['name_slug'][$u['name']] !== $t['id'] && !isset($labels[$state['name_slug'][$u['name']]])) {
                    $add($errors, "$tp/label", "כבר יש נושא בשם \"{$u['name']}\" — למיזוג: מסך הנושאים");
                    continue;
                }
                if ($u) $topicUpd[$t['id']] = $u + ['from' => $cur['name']];
            }
        }
    }
    // מפתחות לא מוכרים: שדה חדש מוצע (עורך). הסוג — לפי כל הערכים שלו בקובץ
    $unknown = [];
    foreach ($j['items'] as $x => $it) if (is_array($it)) foreach ($it as $k => $v) {
        $k = (string)$k;
        if (in_array($k, WJ_ITEM_KEYS, true) || isset($k2c[$k]) || isset($custom[$k])) continue;
        $unknown[$k]['values'][] = $v;
        $unknown[$k]['x'] ??= $x;
    }
    foreach ($unknown as $k => $u) {
        $p = "/items/{$u['x']}/!$k#key";
        if ($e = field_key_error($k, $fields)) { $add($errors, $p, "שדה לא מוכר \"" . mb_substr($k, 0, 60) . "\" — $e"); continue; }
        $type = field_guess($u['values']);
        $fieldProps[$k] = ['key' => $k, 'type' => $type, 'line' => $at($p)[0], 'count' => count(array_filter($u['values'], fn($v) => $v !== null && $v !== ''))];
        if (in_array($k, $opt['fields'] ?? [], true)) $custom[$k] = field_proposed($k, $type);
        else $add($errors, $p, "שדה לא מוכר \"$k\" — אשרו \"צור שדה $k\" (סוג: $type), או הסירו אותו", ['propose_field' => $k]);
    }
    $allFields = array_merge(array_values(array_filter($fields, fn($f) => $f['col'] !== null)), array_values($custom));
    $items = []; $seen = [];
    foreach ($j['items'] as $x => $raw) {
        $p = "/items/$x";
        if (!is_array($raw) || !$is($p, '{')) { $add($errors, $p, 'פריט חייב להיות אובייקט'); continue; }
        // המפתחות בקובץ ← שמות העמודות (word, meaning…) ושדות מותאמים
        $it = []; $ex = [];
        foreach ($raw as $k => $v) {
            if (isset($k2c[$k])) $it[$k2c[$k]] = $v;
            elseif (isset($custom[$k])) $ex[$k] = $v;
            elseif (in_array($k, WJ_ITEM_KEYS, true)) $it[$k] = $v;
        }
        $bad = false;
        $id = $it['id'] ?? null;
        if ($id !== null && (!is_int($id) || $id < 1 || $id > 2147483647)) { $add($errors, "$p/id", '"id" חייב להיות מספר שלם חיובי (או להיעדר — מילה חדשה)'); continue; }
        if ($id !== null && isset($seen[$id])) { $add($errors, "$p/id", "\"id\": $id מופיע פעמיים (גם בפריט " . ($seen[$id] + 1) . ')'); continue; }
        if ($id !== null) $seen[$id] = $x;
        $cur = $id !== null ? ($state['by'][$id] ?? null) : null;
        foreach (['word', 'meaning', 'audio', 'external_id'] as $k) {
            if (array_key_exists($k, $it) && !is_string($it[$k])) { $add($errors, "$p/$k", '"' . ($c2k[$k] ?? $k) . '" חייב להיות מחרוזת'); $bad = true; }
        }
        $topicsIn = $it['topics'] ?? [];
        if (!is_array($topicsIn) || !array_is_list($topicsIn) || (array_key_exists('topics', $it) && !$is("$p/{$c2k['topics']}", '[')) || array_filter($topicsIn, fn($t) => !is_string($t))) { $add($errors, "$p/topics", '"' . $c2k['topics'] . '" חייב להיות רשימה של מפתחות נושא (מחרוזות)'); $bad = true; }
        if ($bad) continue;
        $o = ['x' => $x, 'id' => $id, 'line' => $at($p)[0]];
        // מילה
        $w = $it['word'] ?? '';
        if ($cur && $w === $cur['word']) $o['word'] = $cur['word'];
        elseif (trim($w) === '') { $add($errors, "$p/word", '"' . $c2k['word'] . '" ריק'); continue; }
        elseif (mb_strlen(trim($w)) > WORD_MAX) { $add($errors, "$p/word", '"' . $c2k['word'] . '" ארוך מ-' . WORD_MAX . ' תווים'); continue; }
        else $o['word'] = str($w, WORD_MAX);
        // פירוש
        $m = $it['meaning'] ?? '';
        if ($cur && $m === $cur['meaning']) $o['meaning'] = $cur['meaning'];
        elseif (mb_strlen($m) > MEANING_MAX) { $add($errors, "$p/meaning", '"' . $c2k['meaning'] . '" ארוך מ-' . MEANING_MAX . ' תווים'); continue; }
        else $o['meaning'] = sanitize_rich($m);
        // נושאים: מפתחות ← שמות
        $topicsIn = array_values(array_unique($topicsIn));
        if ($cur && $topicsIn === $cur['slugs']) $o['topics'] = $cur['topics'];
        else {
            $names = [];
            foreach ($topicsIn as $slug) {
                $name = $state['slug_name'][$slug] ?? null;
                if ($name === null) {
                    if (!preg_match('/^[\p{L}\p{N}%_-]{1,200}$/u', $slug)) { $add($errors, "$p/topics", "מפתח נושא לא תקין: \"$slug\" (אותיות, ספרות, - ו-_)"); continue 2; }
                    $label = str(preg_replace('/\s+/u', ' ', (string)($labels[$slug] ?? $slug)), 80) ?: $slug;
                    $proposals[$slug] ??= ['slug' => $slug, 'name' => $label, 'line' => $at("$p/topics")[0]];
                    if (!in_array($slug, $create, true)) continue;
                    $name = $label;
                }
                if (!in_array($name, $names, true)) $names[] = $name;
            }
            $o['topics'] = array_slice($names, 0, 20);
        }
        // הקלטה
        $a = $it['audio'] ?? '';
        if ($cur && $a === $cur['audio_ref']) $o['audio'] = $cur['audio'];
        else {
            $r = words_audio_resolve($a);
            if ($r === null) { $add($warnings, "$p/audio", "ההקלטה \"" . mb_substr($a, 0, 80) . '" לא נמצאה (לא בספרייה, לא בפרויקט ולא באתר המחובר) — נשארת כמו שהייתה'); $o['audio'] = $cur['audio'] ?? ''; }
            else $o['audio'] = $r;
        }
        // מזהה באתר — לקריאה בלבד
        if (array_key_exists('wp_id', $it)) {
            if ($cur && $it['wp_id'] !== $cur['wp_id']) { $add($errors, "$p/wp_id", '"wp_id" (המזהה באתר) אינו נערך — החזירו את הערך ' . json_encode($cur['wp_id'])); continue; }
            if (!$cur && $it['wp_id'] !== null) $add($warnings, "$p/wp_id", '"wp_id" במילה חדשה — לא נשמר (נקבע רק בייבוא מהאתר)');
        }
        $e = $it['external_id'] ?? '';
        $o['external_id'] = $cur && $e === $cur['external_id'] ? $cur['external_id'] : str($e, 40);
        // N2: השדות המותאמים — הקובץ שלם: מפתח שחסר = ריק; מילה חדשה — ברירת המחדל
        $extra = [];
        foreach ($custom as $k => $f) {
            if (!array_key_exists($k, $ex)) { if (!$cur && $f['def'] !== null) $extra[$k] = $f['def']; continue; }
            $v = $ex[$k];
            if ($cur && array_key_exists($k, $cur['extra_ref']) && $v === $cur['extra_ref'][$k]) { $extra[$k] = $cur['extra'][$k]; continue; }
            [$val, $err, $warn, $propose] = field_parse($f, $v);
            if ($propose !== null) $optionProps["$k:$propose"] ??= ['key' => $k, 'value' => $propose, 'line' => $at("$p/!$k")[0]];
            if ($propose !== null && in_array("$k:$propose", $opt['options'] ?? [], true)) { $extra[$k] = $propose; continue; }
            if ($propose !== null) { $add($errors, "$p/!$k", "$err — אשרו \"הוסף אפשרות\", או תקנו", ['propose_option' => "$k:$propose"]); continue 2; }
            if ($err) { $add($errors, "$p/!$k", $err); continue 2; }
            if ($warn) $add($warnings, "$p/!$k", $warn);
            if ($warn && in_array($f['type'], ['image', 'audio'], true)) { if (isset($cur['extra'][$k])) $extra[$k] = $cur['extra'][$k]; continue; }
            if ($val !== null) $extra[$k] = $val;
        }
        ksort($extra);
        $o['extra'] = $extra;
        if ($miss = fields_required_missing($allFields, $o, $cur)) { $add($errors, $p, "\"{$miss['key']}\" ({$miss['label']}) הוא שדה חובה — חסר ערך"); continue; }
        if ($cur === null && $id !== null) $add($warnings, "$p/id", "אין מילה עם \"id\": $id — תתווסף מילה חדשה עם המזהה הזה. המזהה הוא המפתח של ההקלטה ושל קישורים למילה");
        $items[] = $o;
    }
    // הסדר של הנושאים: הסדר שבקובץ, למה שמופיע בו; השאר נשארים במקומם
    $topicOrder = [];
    if ($topicSeq) {
        $curOrder = array_keys($topics);
        $listed = array_flip($topicSeq);
        $full = []; $q = $topicSeq;
        foreach ($curOrder as $s) $full[] = isset($listed[$s]) ? array_shift($q) : $s;
        if ($full !== $curOrder) foreach ($full as $i => $s) if ($topics[$s]['sort_order'] !== ($i + 1) * WORDS_ORDER_STEP) $topicOrder[$s] = ($i + 1) * WORDS_ORDER_STEP;
    }
    return ['errors' => $errors, 'warnings' => $warnings, 'items' => $items, 'proposals' => array_values($proposals), 'full' => true, 'seq' => true,
        'field_proposals' => array_values($fieldProps), 'option_proposals' => array_values($optionProps),
        'create_fields' => array_values(array_filter(array_map(fn($k) => isset($fieldProps[$k]) ? ['key' => $k, 'type' => $fieldProps[$k]['type']] : null, $opt['fields'] ?? []))),
        'create_options' => array_values(array_map(fn($s) => $optionProps[$s], array_filter($opt['options'] ?? [], fn($s) => is_string($s) && isset($optionProps[$s])))),
        'topic_updates' => $topicUpd, 'topic_order' => $topicOrder];
}

/**
 * מנוע השמירה — אחד לקובץ ולאקסל. $in: items (מנורמלים), full (קובץ שלם: מה שאינו בו — לסל; והסדר — סדר הפריטים),
 * order (אקסל: [id => sort_order] מפורש). מחזיר את התוכנית: מה ישתנה, לפי id, ואסימון שמזהה אותה.
 */
function words_plan(array $in, array $state): array
{
    $changed = []; $added = []; $trashed = []; $keepIds = []; $byField = [];
    $fields = ['word', 'meaning', 'topics', 'audio', 'external_id'];
    $c2k = fields_colkeys($state['fields'] ?? null);
    $short = fn($v) => $v === null ? '' : (is_array($v) ? implode(', ', array_map(fn($x) => is_scalar($x) ? (string)$x : json_encode($x), $v)) : (is_bool($v) ? ($v ? 'true' : 'false')
        : (mb_strlen((string)$v) > 300 ? mb_substr((string)$v, 0, 300) . '…' : (string)$v)));
    foreach ($in['items'] as $o) {
        $cur = $o['id'] !== null ? ($state['by'][$o['id']] ?? null) : null;
        if (!$cur) { $added[] = ['id' => $o['id'], 'word' => $o['word'], 'line' => $o['line'] ?? null]; continue; }
        $keepIds[$o['id']] = true;
        $diff = [];
        foreach ($fields as $f) if (array_key_exists($f, $o) && $o[$f] !== $cur[$f]) { $diff[$c2k[$f] ?? $f] = [$short($cur[$f]), $short($o[$f])]; $byField[$c2k[$f] ?? $f] = ($byField[$c2k[$f] ?? $f] ?? 0) + 1; }
        // N2: השדות המותאמים — מפתח מפתח
        if (array_key_exists('extra', $o) && $o['extra'] !== $cur['extra']) {
            foreach (array_unique(array_merge(array_keys($o['extra']), array_keys($cur['extra']))) as $k) {
                if (($o['extra'][$k] ?? null) === ($cur['extra'][$k] ?? null)) continue;
                $diff[$k] = [$short($cur['extra'][$k] ?? null), $short($o['extra'][$k] ?? null)];
                $byField[$k] = ($byField[$k] ?? 0) + 1;
            }
        }
        if ($diff) $changed[] = ['id' => $o['id'], 'word' => $cur['word'], 'fields' => $diff];
    }
    if (!empty($in['full'])) foreach ($state['by'] as $id => $w) if (!isset($keepIds[$id])) $trashed[] = ['id' => $id, 'word' => $w['word'], 'audio' => $w['audio'] !== ''];
    // הסדר
    $cur = array_map(fn($w) => $w['sort_order'], $state['by']);
    $set = []; $moved = 0;
    if (!empty($in['seq'])) {
        $seq = []; $nn = 0;
        foreach ($in['items'] as $o) $seq[] = $o['id'] !== null && isset($state['by'][$o['id']]) ? $o['id'] : 'new:' . $nn++;
        [$set, $moved] = words_order_assign($seq, array_intersect_key($cur, $keepIds));
    } elseif (!empty($in['order'])) {
        foreach ($in['order'] as $id => $v) if (isset($cur[$id]) && $cur[$id] !== $v) { $set[$id] = $v; $moved++; }
    }
    $topicChanges = array_unique(array_merge(array_keys($in['topic_updates'] ?? []), array_keys($in['topic_order'] ?? [])));
    $counts = ['changed' => count($changed), 'added' => count($added), 'trashed' => count($trashed), 'moved' => $moved,
        'topics' => count($in['create'] ?? [])];
    // N2 — רק כשיש (הצורה של N1 נשמרת)
    foreach (['topic_changes' => count($topicChanges), 'new_fields' => count($in['create_fields'] ?? []), 'new_options' => count($in['create_options'] ?? [])] as $k => $n) if ($n) $counts[$k] = $n;
    $plan = ['counts' => $counts, 'changed' => array_slice($changed, 0, 500), 'added' => array_slice($added, 0, 500), 'trashed' => array_slice($trashed, 0, 500),
        'by_field' => $byField, 'topic_updates' => $in['topic_updates'] ?? [], 'new_fields' => $in['create_fields'] ?? [], 'new_options' => $in['create_options'] ?? [],
        'new_topics' => $in['create'] ?? [], 'proposals' => $in['proposals'] ?? [], 'field_proposals' => $in['field_proposals'] ?? [],
        'option_proposals' => $in['option_proposals'] ?? [], 'warnings' => $in['warnings'] ?? [],
        'snapshot' => $counts['trashed'] > 0 || $counts['changed'] + $counts['added'] + $moved > WORDS_BULK || !empty($counts['new_fields']),
        'needs' => $counts['trashed'] > WORDS_BULK || !empty($in['replace']) ? 'publisher' : 'editor'];      // N2: "צור שדה" / "הוסף אפשרות" — עורך (08 §3)
    $plan['summary'] = words_plan_text($counts, array_diff_key($byField, array_flip(array_values($c2k))));    // המובנים — כמו ב-N1
    // האסימון: <חותמת הטבלה>.<חותמת התוכנית> — כך ההחלה יודעת קודם כול אם הטבלה השתנתה מאז התצוגה
    $plan['token'] = words_state_hash($state) . '.' . substr(hash('sha256', json_encode([$in['items'], $set, array_column($trashed, 'id'), $in['create'] ?? [],
        $in['topic_updates'] ?? [], $in['topic_order'] ?? [], $in['create_fields'] ?? [], $in['create_options'] ?? []], JSON_UNESCAPED_UNICODE)), 0, 32);
    return [$plan, $set];
}

function words_state_hash(array $state): string
{
    return substr(hash('sha256', json_encode([$state['order'], array_map(fn($w) => [$w['word'], $w['meaning'], $w['topics'], $w['audio'], $w['external_id'], $w['wp_id'], $w['sort_order'], $w['extra']], $state['by']),
        $state['name_slug'], $state['fields'] ?? [], array_map(fn($t) => array_diff_key($t, ['count' => 1]), $state['topics'] ?? [])], JSON_UNESCAPED_UNICODE)), 0, 32);
}

function words_plan_text(array $c, array $byField = []): string
{
    $parts = [];
    $parts[] = $c['changed'] === 1 ? 'תשתנה מילה אחת' : "ישתנו {$c['changed']} מילים";
    $parts[] = $c['added'] === 1 ? 'תתווסף 1' : "יתווספו {$c['added']}";
    $parts[] = "יעברו לסל {$c['trashed']}";
    $parts[] = "ישתנה הסדר של {$c['moved']}";
    if (!empty($c['topics'])) $parts[] = "נושאים חדשים: {$c['topics']}";
    if (!empty($c['topic_changes'])) $parts[] = $c['topic_changes'] === 1 ? 'ישתנה נושא אחד' : "ישתנו {$c['topic_changes']} נושאים";
    if (!empty($c['new_fields'])) $parts[] = "שדות חדשים: {$c['new_fields']}";
    if (!empty($c['new_options'])) $parts[] = "אפשרויות חדשות: {$c['new_options']}";
    // N2: "ישתנה translit ב-40 מילים"
    foreach (array_slice($byField, 0, 8, true) as $k => $n) $parts[] = "ישתנה $k " . ($n === 1 ? 'במילה אחת' : "ב-$n מילים");
    return implode(' · ', $parts);
}

/**
 * ההחלה: עסקה אחת (BEGIN IMMEDIATE … COMMIT). בתוכה — התוכנית מחושבת שוב מול הטבלה ברגע הזה, ואם אינה זהה לזו שהוצגה
 * (מישהו שינה בינתיים) — 409, ושום דבר לא נכתב. לפני מחיקה כלשהי או שינוי של יותר מ-20 — נקודת שחזור. מחיקה = לסל.
 * $build: fn(array $state) → $in (הקלט מנורמל מול המצב הנוכחי).
 */
function words_apply(array $user, callable $build, string $token, string $source): array
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        words_order_fill(true);
        $state = words_state();
        if (!hash_equals(words_state_hash($state), explode('.', $token)[0])) throw new ApiError('טבלת המילים השתנתה מאז התצוגה — הציגו שוב את "מה ישתנה"', 409);
        $in = $build($state);
        if (!empty($in['errors'])) throw new ApiError('יש שגיאות — דבר לא נשמר', 422, ['errors' => $in['errors']]);
        [$plan, $set] = words_plan($in, $state);
        if (!hash_equals($plan['token'], $token)) throw new ApiError('טבלת המילים השתנתה מאז התצוגה — הציגו שוב את "מה ישתנה"', 409, ['plan' => $plan]);
        if (ROLE_RANK[$user['role'] ?? 'editor'] < ROLE_RANK[$plan['needs']]) throw new ApiError('"החלף הכול", ומחיקה של יותר מ-' . WORDS_BULK . ' מילים — רק למפרסם', 403, ['plan' => $plan]);
        $snap = $plan['snapshot'] ? snapshot_create($user, "לפני שמירת $source: " . $plan['summary']) : null;
        $now = now_iso();
        // N2: שדות חדשים ואפשרויות חדשות שאושרו בתצוגה
        if (!empty($in['create_fields']) || !empty($in['create_options'])) fields_fill();
        foreach ($in['create_fields'] ?? [] as $cf) {
            if (field_key_error($cf['key'], fields_all())) throw new ApiError("כבר יש שדה \"{$cf['key']}\"", 409);
            $max = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM word_fields')->fetchColumn();
            $pdo->prepare('INSERT INTO word_fields (key, label, type, col, sort_order) VALUES (?, ?, ?, NULL, ?)')->execute([$cf['key'], $cf['key'], $cf['type'], $max + 10]);
            audit_as($user, 'field_add', "{$cf['key']} ({$cf['type']}) · מתוך $source");
        }
        foreach ($in['create_options'] ?? [] as $co) {
            $st = $pdo->prepare('SELECT options FROM word_fields WHERE key = ?');
            $st->execute([$co['key']]);
            $opts = json_decode((string)$st->fetchColumn(), true) ?: [];
            if (!in_array($co['value'], array_column($opts, 'value'), true)) $opts[] = ['value' => $co['value'], 'label' => $co['value']];
            $pdo->prepare('UPDATE word_fields SET options = ? WHERE key = ?')->execute([json_encode($opts, JSON_UNESCAPED_UNICODE), $co['key']]);
            audit_as($user, 'field_edit', "{$co['key']}: אפשרות חדשה \"{$co['value']}\" · מתוך $source");
        }
        $addTopic = $pdo->prepare('INSERT INTO topics (slug, name) VALUES (?, ?)');
        foreach ($in['create'] ?? [] as $t) {
            $st = $pdo->prepare('SELECT slug FROM topics WHERE name = ?');
            $st->execute([$t['name']]);
            if ($st->fetchColumn()) throw new ApiError("כבר יש נושא בשם \"{$t['name']}\" — השתמשו במפתח שלו", 409);
            $addTopic->execute([$t['slug'], $t['name']]);
        }
        $createdNames = array_column($in['create'] ?? [], 'name');
        $known = $pdo->prepare('INSERT OR IGNORE INTO topics (slug, name) VALUES (?, ?)');
        $map = $state['name_slug'];
        $upd = $pdo->prepare('UPDATE words SET word = ?, meaning = ?, topics = ?, audio = ?, external_id = ?, extra = ?, updated_at = ?, updated_by = ? WHERE id = ?');
        $insId = $pdo->prepare('INSERT INTO words (id, word, meaning, topics, audio, external_id, extra, sort_order, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins = $pdo->prepare('INSERT INTO words (word, meaning, topics, audio, external_id, extra, sort_order, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $defs = [];
        foreach (fields_custom() as $f) if ($f['def'] !== null) $defs[$f['key']] = $f['def'];
        $ej = fn(array $x) => $x ? json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';
        $changedIds = array_flip(array_column($plan['changed'], 'id'));
        $nn = 0; $newIds = [];
        foreach ($in['items'] as $o) {
            $cur = $o['id'] !== null ? ($state['by'][$o['id']] ?? null) : null;
            $v = [];
            foreach (['word', 'meaning', 'topics', 'audio', 'external_id'] as $f) $v[$f] = array_key_exists($f, $o) ? $o[$f] : ($cur[$f] ?? ($f === 'topics' ? [] : ''));
            if (array_key_exists('extra', $o)) $v['extra'] = $o['extra'];
            else { $v['extra'] = $cur['extra'] ?? $defs; ksort($v['extra']); }
            if ($cur && !isset($changedIds[$o['id']])) continue;
            foreach ($v['topics'] as $t) if (!in_array($t, $createdNames, true)) $known->execute([topic_slug($t, $map), $t]);
            $tj = json_encode($v['topics'], JSON_UNESCAPED_UNICODE);
            if ($cur) { $upd->execute([$v['word'], $v['meaning'], $tj, $v['audio'], $v['external_id'], $ej($v['extra']), $now, $user['name'], $o['id']]); continue; }
            $so = !empty($in['seq']) ? ($set['new:' . $nn++] ?? null) : null;
            if ($o['id'] !== null) {
                $chk = $pdo->prepare('SELECT 1 FROM words WHERE id = ?');
                $chk->execute([$o['id']]);
                if ($chk->fetchColumn()) throw new ApiError("המזהה {$o['id']} תפוס", 409);
                $insId->execute([$o['id'], $v['word'], $v['meaning'], $tj, $v['audio'], $v['external_id'], $ej($v['extra']), $so, $now, $user['name']]);
                $newIds[] = $o['id'];
            } else {
                $ins->execute([$v['word'], $v['meaning'], $tj, $v['audio'], $v['external_id'], $ej($v['extra']), $so, $now, $user['name']]);
                $newIds[] = (int)$pdo->lastInsertId();
            }
        }
        // לסל — השורה כולה (כמו word_delete), עם ההקלטה המקושרת שלה. הספרייה אינה נוגעת
        $get = $pdo->prepare('SELECT * FROM words WHERE id = ?');
        $trash = $pdo->prepare('INSERT INTO words_trash (word_id, row, at, by_name) VALUES (?, ?, ?, ?)');
        $del = $pdo->prepare('DELETE FROM words WHERE id = ?');
        foreach ($plan['counts']['trashed'] ? array_keys(array_diff_key($state['by'], array_flip(array_filter(array_column($in['items'], 'id'))))) : [] as $id) {
            $get->execute([$id]);
            $row = $get->fetch();
            $trash->execute([$id, json_encode($row, JSON_UNESCAPED_UNICODE), $now, $user['name']]);
            $del->execute([$id]);
        }
        $so = $pdo->prepare('UPDATE words SET sort_order = ? WHERE id = ?');
        foreach ($set as $id => $v) if (is_int($id)) $so->execute([$v, $id]);
        words_order_fill(true);                                 // מילה חדשה מאקסל (בלי סדר) — לסוף
        // N2: נושאים קיימים — שם (ה-slug נשאר), צבע, תמונה, מוסתר, סדר
        if (!empty($in['topic_updates']) || !empty($in['topic_order'])) {
            topics_fill();
            topics_rename($user, array_filter(array_map(fn($u) => isset($u['name']) ? [$u['from'], $u['name']] : null, $in['topic_updates'] ?? [])));
            foreach ($in['topic_updates'] ?? [] as $slug => $u) topic_attrs($slug, $u);
            $so = $pdo->prepare('UPDATE topics SET sort_order = ? WHERE slug = ?');
            foreach ($in['topic_order'] ?? [] as $slug => $v) $so->execute([$v, $slug]);
        }
        $c = $plan['counts'];
        $detail = "$source · {$c['changed']} שונו, {$c['added']} נוספו, {$c['trashed']} לסל, {$c['moved']} בסדר";
        $names = array_merge(array_column($plan['changed'], 'word'), array_column($plan['added'], 'word'), array_map(fn($t) => '🗑 ' . $t['word'], $plan['trashed']));
        if ($names) $detail .= ' — ' . implode(', ', array_slice($names, 0, 12)) . (count($names) > 12 ? ' ועוד ' . (count($names) - 12) : '');
        audit_as($user, $source === 'words.json' ? 'words_file' : 'words_csv', $detail);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    return ['plan' => $plan, 'done' => $plan['counts'], 'snapshot' => $snap, 'new_ids' => $newIds];
}

/**
 * שמירת data/words.json (מהעורך, מהעלאה באותו נתיב, מ-ZIP). בלי apply — רק התוכנית (שום דבר לא נכתב). עם apply ו-token
 * של התוכנית שהוצגה — ההחלה. create_topics — המפתחות שאושר ליצור עבורם נושא.
 */
function words_file_save(array $user, string $bytes, array $b): array
{
    $create = array_values(array_filter(is_array($b['create_topics'] ?? null) ? $b['create_topics'] : [], 'is_string'));
    // N2: "צור שדה" ו"הוסף אפשרות" שאושרו בתצוגה
    $opt = ['fields' => array_values(array_filter(is_array($b['create_fields'] ?? null) ? $b['create_fields'] : [], 'is_string')),
        'options' => array_values(array_filter(is_array($b['create_options'] ?? null) ? $b['create_options'] : [], 'is_string'))];
    $build = function (array $state) use ($bytes, $create, $opt) {
        $in = words_file_items($bytes, $state, $create, $opt);
        $in['create'] = array_values(array_filter($in['proposals'] ?? [], fn($p) => in_array($p['slug'], $create, true)));
        $pending = array_values(array_filter($in['proposals'] ?? [], fn($p) => !in_array($p['slug'], $create, true)));
        if ($pending && empty($in['errors'])) $in['errors'] = array_map(fn($p) => ['line' => $p['line'], 'col' => 1, 'msg' => "אין נושא \"{$p['slug']}\" — אשרו \"צור נושא {$p['slug']}\", או תקנו את המפתח", 'propose' => $p['slug']], $pending);
        return $in;
    };
    if (empty($b['apply'])) {
        $state = words_state();
        $in = $build($state);
        if (!empty($in['errors'])) throw new ApiError('הקובץ לא נשמר — ' . count($in['errors']) . ' שגיאות. הטבלה לא השתנתה', 422, ['errors' => array_slice($in['errors'], 0, 200), 'proposals' => $in['proposals'] ?? [],
            'field_proposals' => $in['field_proposals'] ?? [], 'option_proposals' => $in['option_proposals'] ?? [], 'warnings' => $in['warnings'] ?? []]);
        return ['plan' => words_plan($in, $state)[0]];
    }
    return words_apply($user, $build, (string)($b['token'] ?? ''), 'words.json');
}

// ── N1: אקסל (CSV) — אותו מנוע ─────────────────────────────

/** תא שמתחיל ב-= + - @ (או טאב / CR) — אקסל מריץ אותו כנוסחה. בייצוא מקבל ' לפניו; בייבוא ה-' הזה מוסר. */
function csv_cell(mixed $v): string
{
    $v = (string)$v;
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

function csv_uncell(string $v): string
{
    return preg_match("/^'[=+\\-@\\t\\r]/", $v) ? substr($v, 1) : $v;
}

function words_csv(): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kol-maroko-words-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                    // אקסל מזהה כך שזה UTF-8 (אחרת — ג'יבריש)
    $custom = fields_custom();
    fputcsv($out, array_map('csv_cell', [...array_values(CSV_COLS), ...array_column($custom, 'label')]), ',', '"', '');     // N2: עמודה לכל שדה, אחרי של N1
    $rows = words_rows();
    $meta = files_meta(array_values(array_unique(array_filter(array_merge(array_map(fn($w) => file_id($w['audio']), $rows), fields_file_ids($rows, $custom))))));
    foreach ($rows as $w) {
        fputcsv($out, array_map('csv_cell', [$w['id'], $w['word'], lex_plain($w['meaning']), implode('; ', $w['topics']), words_audio_ref((string)$w['audio'], $meta),
            $w['external_id'], $w['sort_order'], $w['wp_id'] ?? '', ...array_map(fn($f) => field_cell($f, $w['extra'][$f['key']] ?? null, $meta), $custom)]), ',', '"', '');
    }
    fclose($out);
    exit;
}

/** CSV גולמי ← [שורות, קידוד שזוהה]. UTF-8 עם / בלי BOM, ו-Windows-1255 (אקסל עברי ששומר "CSV" רגיל). */
function words_csv_read(string $csv): array
{
    if (strlen($csv) > 20 * 1024 * 1024) throw new ApiError('הקובץ גדול מדי');
    if (str_starts_with($csv, "\xEF\xBB\xBF")) { $enc = 'UTF-8 (עם BOM)'; $csv = substr($csv, 3); }
    elseif (mb_check_encoding($csv, 'UTF-8')) $enc = 'UTF-8';
    else { $enc = 'Windows-1255 (אקסל עברי ישן)'; $csv = cp1255_utf8($csv); }
    $first = (string)strtok($csv, "\n");
    $c = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    $sep = array_search(max($c), $c, true);
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, $csv);
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, null, $sep, '"', '')) !== false) $rows[] = $r;
    fclose($fh);
    return [$rows, $enc];
}

/** Windows-1255 ← UTF-8. mbstring אינו מכיר את הקידוד הזה (ValueError) — iconv, ואם אין — טבלה (0x80–0xFF). */
function cp1255_utf8(string $s): string
{
    if (function_exists('iconv') && ($u = @iconv('WINDOWS-1255', 'UTF-8//IGNORE', $s)) !== false && $u !== '') return $u;
    static $t = null;
    $t ??= mb_str_split("€�‚ƒ„…†‡ˆ‰�‹�����‘’“”•–—˜™�›����\u{a0}¡¢£₪¥¦§¨©×«¬\u{ad}®¯°±²³´µ¶·¸¹÷»¼½¾¿\u{5b0}\u{5b1}\u{5b2}\u{5b3}\u{5b4}\u{5b5}\u{5b6}\u{5b7}\u{5b8}\u{5b9}�\u{5bb}\u{5bc}\u{5bd}\u{5be}\u{5bf}\u{5c0}\u{5c1}\u{5c2}\u{5c3}װױײ׳״�������אבגדהוזחטיךכלםמןנסעףפץצקרשת��\u{200e}\u{200f}�");
    return preg_replace_callback('/[\x80-\xFF]/', fn($m) => $t[ord($m[0]) - 0x80], $s);
}

/** שורות הגיליון ← פריטים למנוע. עמודה שאינה בגיליון — הערך שבטבלה נשמר. שגיאה — עם מספר השורה בגיליון. */
function words_csv_items(array $rows, array $state, array $opt = []): array
{
    $head = array_map(fn($h) => trim((string)$h), $rows[0] ?? []);
    $col = [];
    foreach (CSV_COLS as $k => $label) {
        if (($i = array_search($label, $head, true)) !== false || ($i = array_search($k, $head, true)) !== false) $col[$k] = $i;
    }
    if (!isset($col['word'])) return ['errors' => [['line' => 1, 'col' => 1, 'msg' => 'לא מצאתי עמודה "מילה" בשורה הראשונה. כדאי להתחיל מקובץ שיוצא מכאן (⬇️ אקסל)']]];
    $errors = []; $warnings = []; $items = []; $order = []; $seen = []; $newTopics = [];
    // N2: עמודות של שדות מותאמים — לפי השם (label) או המפתח; עמודה לא מוכרת — "לא תיובא", עם הצעה ליצור שדה (עורך)
    $fields = $state['fields'] ?? fields_all();
    $fcol = []; $fieldProps = []; $create = [];
    $known = array_merge(array_values(CSV_COLS), array_keys(CSV_COLS));
    foreach (fields_custom($fields) as $f) {
        if (($i = array_search($f['label'], $head, true)) !== false || ($i = array_search($f['key'], $head, true)) !== false) $fcol[$f['key']] = [$i, $f];
    }
    $used = array_merge(array_values($col), array_column($fcol, 0));
    foreach ($head as $i => $h) {
        if ($h === '' || in_array($i, $used, true) || in_array($h, $known, true)) continue;
        $vals = array_map(fn($r) => csv_uncell(trim((string)($r[$i] ?? ''))), array_slice($rows, 1));
        $k = field_key_error($h, $fields) === null ? $h : null;
        $type = field_guess($vals, true);
        if ($k !== null && in_array($k, $opt['fields'] ?? [], true)) { $f = field_proposed($k, $type); $fcol[$k] = [$i, $f]; $create[] = ['key' => $k, 'type' => $type]; continue; }
        $warnings[] = ['line' => 1, 'col' => $i + 1, 'msg' => "העמודה \"" . mb_substr($h, 0, 60) . '" לא תיובא — אין שדה כזה' . ($k !== null ? " (אפשר ליצור שדה \"$k\", סוג $type)" : ' (שם עמודה לשדה חדש: מפתח באנגלית, למשל translit)')];
        if ($k !== null) $fieldProps[] = ['key' => $k, 'type' => $type, 'line' => 1, 'count' => count(array_filter($vals, fn($v) => $v !== ''))];
    }
    $allFields = array_merge(array_values(array_filter($fields, fn($f) => $f['col'] !== null)), array_column($fcol, 1));
    $err = function (int $n, string $m) use (&$errors) { $errors[] = ['line' => $n, 'col' => 1, 'msg' => "שורה $n: $m"]; };
    for ($x = 1; $x < count($rows); $x++) {
        $r = $rows[$x];
        $n = $x + 1;
        if (trim(implode('', array_map('strval', $r))) === '') continue;
        $get = fn($k) => isset($col[$k]) ? csv_uncell(trim((string)($r[$col[$k]] ?? ''))) : null;
        $rawId = $get('id');
        $id = null;
        if ($rawId !== null && $rawId !== '') {
            if (!preg_match('/^[1-9]\d{0,9}$/', $rawId) || (int)$rawId > 2147483647) { $err($n, "מזהה לא תקין \"$rawId\" (מספר שלם חיובי, או ריק — מילה חדשה)"); continue; }
            $id = (int)$rawId;
            if (isset($seen[$id])) { $err($n, "המזהה $id מופיע פעמיים (גם בשורה {$seen[$id]})"); continue; }
            $seen[$id] = $n;
        }
        $cur = $id !== null ? ($state['by'][$id] ?? null) : null;
        if ($id !== null && !$cur) $warnings[] = ['line' => $n, 'col' => 1, 'msg' => "שורה $n: אין מילה עם המזהה $id — תתווסף מילה חדשה עם המזהה הזה"];
        $o = ['x' => $x, 'id' => $id, 'line' => $n];
        $w = $get('word');
        if ($cur && $w === $cur['word']) $o['word'] = $cur['word'];
        elseif (trim((string)$w) === '') { $err($n, 'המילה ריקה'); continue; }
        elseif (mb_strlen($w) > WORD_MAX) { $err($n, 'המילה ארוכה מ-' . WORD_MAX . ' תווים'); continue; }
        else $o['word'] = str($w, WORD_MAX);
        $plain = $get('meaning');
        if ($plain !== null) {
            if (mb_strlen($plain) > MEANING_MAX) { $err($n, 'הפירוש ארוך מדי'); continue; }
            // פירוש שלא שונה (כטקסט) שומר את העיצוב שלו
            $o['meaning'] = $cur && lex_plain($cur['meaning']) === lex_plain(lex_from_plain($plain)) ? $cur['meaning'] : lex_from_plain($plain);
        } elseif (!$cur) $o['meaning'] = '';
        $tc = $get('topics');
        if ($tc !== null) {
            if ($cur && $tc === implode('; ', $cur['topics'])) $o['topics'] = $cur['topics'];
            else {
                $names = [];
                foreach (preg_split(str_contains($tc, ';') ? '/\s*;\s*/u' : '/\s*[,،]\s*/u', $tc, -1, PREG_SPLIT_NO_EMPTY) as $t) {
                    $t = str(preg_replace('/\s+/u', ' ', $t), 80);
                    if ($t === '' || in_array($t, $names, true)) continue;
                    $names[] = $t;
                    if (!isset($state['name_slug'][$t])) $newTopics[$t] = true;
                }
                $o['topics'] = array_slice($names, 0, 20);
            }
        } elseif (!$cur) $o['topics'] = [];
        // ההקלטה אינה משתנה מאקסל (כמו לפני N1) — רק נקראת. מילה חדשה — בלי הקלטה
        if (!$cur) $o['audio'] = '';
        $e = $get('external_id');
        if ($e !== null) $o['external_id'] = $cur && $e === $cur['external_id'] ? $cur['external_id'] : str($e, 40);
        elseif (!$cur) $o['external_id'] = '';
        $so = $get('sort_order');
        if ($so !== null && $so !== '') {
            if (!preg_match('/^-?\d{1,12}$/', $so)) { $err($n, "סדר לא תקין \"$so\" (מספר שלם)"); continue; }
            if ($cur) $order[$id] = (int)$so;
        }
        // N2: ערכי השדות. עמודה שאינה בגיליון — הערך שבטבלה נשמר; image / audio — לקריאה בלבד (כמו ההקלטה)
        if ($fcol || !$cur) {
            $extra = $cur['extra'] ?? [];
            if (!$cur) foreach (fields_custom($fields) as $f) if ($f['def'] !== null) $extra[$f['key']] = $f['def'];
            foreach ($fcol as $k => [$i, $f]) {
                if (in_array($f['type'], ['image', 'audio'], true)) continue;
                $cell = csv_uncell(trim((string)($r[$i] ?? '')));
                if ($cur && $cell === field_cell($f, $cur['extra'][$k] ?? null, [])) continue;
                [$val, $e2, , $propose] = field_parse($f, $cell, true);
                if ($e2) { $err($n, $e2 . ($propose !== null ? ' (הוסיפו אותה ב"מבנה המילה")' : '')); continue 2; }
                if ($val === null) unset($extra[$k]); else $extra[$k] = $val;
            }
            ksort($extra);
            $o['extra'] = $extra;
        }
        if ($miss = fields_required_missing($allFields, $o, $cur)) { $err($n, "\"{$miss['label']}\" הוא שדה חובה — חסר ערך"); continue; }
        $items[] = $o;
    }
    // נושא חדש מאקסל נוצר לפי השם (כמו לפני N1) — ומוצג בתוכנית
    return ['errors' => $errors, 'warnings' => $warnings, 'items' => $items, 'order' => $order, 'full' => false, 'new_topic_names' => array_keys($newTopics),
        'field_proposals' => $fieldProps, 'create_fields' => $create];
}

/**
 * ייבוא מאקסל דרך מנוע השמירה: בלי apply — התוכנית (מה ישתנה לפי id, וקידוד שזוהה). שורה בלי id = מילה חדשה. מילה
 * שאינה בגיליון אינה נמחקת — אלא ב"החלף הכול" (replace_all, publisher): אז היא עוברת לסל, עם נקודת שחזור.
 */
function words_csv_import(array $user, array $b): array
{
    // csv_b64 — הבייטים כמו שהם (כך הקידוד מזוהה כאן); csv — טקסט שכבר פוענח
    $raw = isset($b['csv_b64']) ? base64_decode((string)$b['csv_b64'], true) : (string)($b['csv'] ?? '');
    if ($raw === false) throw new ApiError('הקובץ (base64) לא תקין');
    [$rows, $enc] = words_csv_read($raw);
    $replace = ($b['replace_all'] ?? false) === true;
    $opt = ['fields' => array_values(array_filter(is_array($b['create_fields'] ?? null) ? $b['create_fields'] : [], 'is_string'))];
    $build = function (array $state) use ($rows, $replace, $opt) {
        $in = words_csv_items($rows, $state, $opt);
        if ($replace) { $in['full'] = true; $in['replace'] = true; $in['order'] = []; }
        $in['create'] = [];
        foreach ($in['new_topic_names'] ?? [] as $name) $in['create'][] = ['slug' => topic_slug($name, $state['name_slug']), 'name' => $name];
        return $in;
    };
    if (empty($b['apply'])) {
        $state = words_state();
        $in = $build($state);
        if (!empty($in['errors'])) throw new ApiError('הגיליון לא יובא — ' . count($in['errors']) . ' שגיאות. הטבלה לא השתנתה', 422, ['errors' => array_slice($in['errors'], 0, 200), 'encoding' => $enc]);
        return ['plan' => words_plan($in, $state)[0], 'encoding' => $enc];
    }
    $r = words_apply($user, $build, (string)($b['token'] ?? ''), 'אקסל');
    $c = $r['done'];
    return $r + ['encoding' => $enc, 'stats' => ['updated' => $c['changed'], 'added' => $c['added'], 'trashed' => $c['trashed'], 'moved' => $c['moved'], 'errors' => []]] + words_list();
}

// ── N2: שדות מותאמים למילה ────────────────────────────────

const FIELD_TYPES = ['text', 'longtext', 'rich', 'number', 'bool', 'choice', 'date', 'url', 'image', 'audio', 'list'];
const FIELD_KEY_RX = '/^[a-z][a-z0-9_]{0,39}$/';
/** מפתחות שאסור לשדה: המזהים של הקובץ, ומה ש-kol-data.js קורא בעצמו ({count}, {shown}, {total}, {label}). וגם topics_* */
const FIELD_RESERVED = ['id', 'sort_order', 'wp_id', 'external_id', 'count', 'shown', 'total', 'label', 'value', 'extra'];
const FIELD_DIRS = ['auto', 'rtl', 'ltr'];
const FIELD_TEXT_MAX = 500;
const FIELD_LIST_MAX = [50, 200];
/** ארבעת השדות המובנים — הערך בעמודה במסד (col). הסוג — רק בתוך המשפחה (FIELD_COL_TYPES) */
const FIELD_BUILTIN = [
    ['key' => 'word', 'label' => 'מילה', 'type' => 'text', 'col' => 'word', 'required' => 1],
    ['key' => 'meaning', 'label' => 'פירוש', 'type' => 'rich', 'col' => 'meaning', 'required' => 0],
    ['key' => 'topics', 'label' => 'נושאים', 'type' => 'topics', 'col' => 'topics', 'required' => 0],
    ['key' => 'audio', 'label' => 'הקלטה', 'type' => 'audio', 'col' => 'audio', 'required' => 0],
];
const FIELD_COL_TYPES = ['word' => ['text'], 'meaning' => ['text', 'longtext', 'rich'], 'topics' => ['topics'], 'audio' => ['audio']];

/** שורה מהטבלה ← שדה מנורמל. */
function field_norm(array $r): array
{
    return ['key' => (string)$r['key'], 'label' => (string)$r['label'], 'type' => (string)$r['type'], 'col' => $r['col'] === null || $r['col'] === '' ? null : (string)$r['col'],
        'required' => (bool)(int)$r['required'], 'def' => isset($r['def']) && $r['def'] !== null && $r['def'] !== '' ? json_decode((string)$r['def'], true) : null,
        'help' => (string)($r['help'] ?? ''), 'options' => json_decode((string)($r['options'] ?? '[]'), true) ?: [], 'published' => (bool)(int)($r['published'] ?? 1),
        'dir' => (string)($r['dir'] ?? 'auto'), 'sort_order' => (int)($r['sort_order'] ?? 0)];
}

/**
 * כל השדות, לפי הסדר. טבלה ריקה (אחרי מיגרציה 18, לפני פעולת מבנה ראשונה) — ארבעת המובנים בזיכרון; קריאה אינה כותבת
 * (כמו sort_order של N1). fields_fill כותב אותם — רק בפעולה שמשנה מבנה.
 */
function fields_all(): array
{
    $rows = db()->query('SELECT * FROM word_fields ORDER BY sort_order, key')->fetchAll();
    if (!$rows) foreach (FIELD_BUILTIN as $i => $f) $rows[] = $f + ['def' => null, 'help' => '', 'options' => '[]', 'published' => 1, 'dir' => 'auto', 'sort_order' => ($i + 1) * 10];
    return array_map('field_norm', $rows);
}

function fields_fill(): void
{
    $pdo = db();
    if ($pdo->query('SELECT 1 FROM word_fields LIMIT 1')->fetchColumn()) return;
    $st = $pdo->prepare('INSERT INTO word_fields (key, label, type, col, required, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
    foreach (FIELD_BUILTIN as $i => $f) $st->execute([$f['key'], $f['label'], $f['type'], $f['col'], $f['required'], ($i + 1) * 10]);
}

/** עמודה מובנית ← המפתח שלה בקובץ (word → word, אלא אם שונה). */
function fields_colkeys(?array $fields = null): array
{
    $o = [];
    foreach ($fields ?? fields_all() as $f) if ($f['col'] !== null) $o[$f['col']] = $f['key'];
    return $o + ['word' => 'word', 'meaning' => 'meaning', 'topics' => 'topics', 'audio' => 'audio'];
}

function fields_custom(?array $fields = null): array
{
    return array_values(array_filter($fields ?? fields_all(), fn($f) => $f['col'] === null));
}

/** חותמת המבנה — נכנסת ל-rev של הקובץ: שינוי שם, "מתפרסם" או סוג — הקובץ הפתוח בעורך יודע. */
function fields_sig(): string
{
    return substr(hash('sha256', json_encode(fields_all(), JSON_UNESCAPED_UNICODE)), 0, 16);
}

/** מפתח חדש: התבנית, השמורים, והקיימים. מחזיר שגיאה (טקסט) או null. */
function field_key_error(string $key, array $fields, ?string $self = null): ?string
{
    if (!preg_match(FIELD_KEY_RX, $key)) return "מפתח לא תקין \"" . mb_substr($key, 0, 60) . "\" — אותיות לטיניות קטנות, ספרות ו-_ , מתחיל באות, עד 40";
    if (in_array($key, FIELD_RESERVED, true) || str_starts_with($key, 'topics_')) return "המפתח \"$key\" שמור למערכת";
    foreach ($fields as $f) if ($f['key'] === $key && $f['key'] !== $self) return "כבר יש שדה עם המפתח \"$key\"";
    return null;
}

/**
 * ערך לשדה מותאם ← הצורה הקנונית (06 §2.2). $csv — הערך הגיע כמחרוזת מגיליון. מחזיר [ערך|null, שגיאה|null, אזהרה|null,
 * אפשרות-להצעה|null]. null = ריק (המפתח חסר). image/audio — מזהה קובץ, או הפניה (media/…); לא נמצא — אזהרה והערך הקודם.
 */
function field_parse(array $f, mixed $v, bool $csv = false): array
{
    if ($v === null || $v === '' || ($v === [] && $f['type'] === 'list')) return [null, null, null, null];
    $t = $f['type'];
    $need = fn(string $what) => [null, "\"{$f['key']}\" חייב להיות $what", null, null];
    switch ($t) {
        case 'text':
            if (!is_string($v)) return $need('טקסט');
            $v = trim($v);
            if (preg_match('/[\r\n]/', $v)) return [null, "\"{$f['key']}\": טקסט בשורה אחת (בלי ירידת שורה). לטקסט ארוך — סוג \"טקסט ארוך\"", null, null];
            if (mb_strlen($v) > FIELD_TEXT_MAX) return [null, "\"{$f['key']}\" ארוך מ-" . FIELD_TEXT_MAX . ' תווים', null, null];
            return [$v === '' ? null : $v, null, null, null];
        case 'longtext':
            if (!is_string($v)) return $need('טקסט');
            if (mb_strlen($v) > MEANING_MAX) return [null, "\"{$f['key']}\" ארוך מ-" . MEANING_MAX . ' תווים', null, null];
            return [trim($v) === '' ? null : $v, null, null, null];
        case 'rich':
            if (!is_string($v)) return $need('טקסט (HTML)');
            if (mb_strlen($v) > MEANING_MAX) return [null, "\"{$f['key']}\" ארוך מ-" . MEANING_MAX . ' תווים', null, null];
            $c = sanitize_rich($csv ? lex_from_plain($v) : $v);
            return [$c === '' ? null : $c, null, !$csv && $c !== $v ? "\"{$f['key']}\": חלק מה-HTML הוסר בניקוי (תגיות או תכונות שאינן מותרות)" : null, null];
        case 'number':
            if ($csv || is_string($v)) {
                if (!$csv) return $need('מספר (בלי מירכאות)');
                $s = str_replace(',', '.', trim((string)$v));
                if (!preg_match('/^-?\d{1,15}(?:\.\d{1,10})?$/', $s)) return [null, "\"{$f['key']}\": \"" . mb_substr((string)$v, 0, 40) . '" אינו מספר', null, null];
                return [str_contains($s, '.') ? (float)$s : (int)$s, null, null, null];
            }
            if (is_bool($v) || (!is_int($v) && !is_float($v)) || !is_finite((float)$v) || abs((float)$v) > 1e15) return $need('מספר');
            return [$v, null, null, null];
        case 'bool':
            if ($csv) {
                $s = mb_strtolower(trim((string)$v));
                if (in_array($s, ['1', 'כן', 'true', 'v', '✓'], true)) return [true, null, null, null];
                if (in_array($s, ['0', 'לא', 'false'], true)) return [false, null, null, null];
                return [null, "\"{$f['key']}\": \"" . mb_substr((string)$v, 0, 40) . '" — כן/לא, 1/0 או true/false', null, null];
            }
            return is_bool($v) ? [$v, null, null, null] : $need('true או false');
        case 'choice':
            if (!is_string($v)) return $need('טקסט — אחת האפשרויות');
            $v = trim($v);
            if ($v === '') return [null, null, null, null];
            foreach ($f['options'] as $o) if ((string)$o['value'] === $v) return [$v, null, null, null];
            if (mb_strlen($v) > 200 || preg_match('/[\r\n]/', $v)) return [null, "\"{$f['key']}\": אפשרות לא תקינה", null, null];
            return [null, "\"{$f['key']}\": \"$v\" אינו אחת האפשרויות", null, $v];
        case 'date':
            if (!is_string($v)) return $need('תאריך YYYY-MM-DD');
            $v = trim($v);
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return [null, "\"{$f['key']}\": \"" . mb_substr($v, 0, 40) . '" אינו תאריך קיים (YYYY-MM-DD)', null, null];
            return [$v, null, null, null];
        case 'url':
            if (!is_string($v)) return $need('כתובת');
            $v = trim($v);
            if (mb_strlen($v) > 2000 || !preg_match('~^https?://[^\s"\'<>`\\\\]+$~i', $v)) return [null, "\"{$f['key']}\": כתובת חייבת להתחיל ב-https:// או http:// (בלי רווחים)", null, null];
            return [$v, null, null, null];
        case 'image':
        case 'audio':
            if (!is_string($v)) return $need('מזהה קובץ או נתיב media/…');
            $id = field_file_resolve($v, $t);
            if ($id === null) return [null, null, "\"{$f['key']}\": הקובץ \"" . mb_substr($v, 0, 80) . '" לא נמצא בספרייה — נשאר כמו שהיה', null];
            return [$id === '' ? null : $id, null, null, null];
        case 'list':
            if ($csv) $v = preg_split('/\s*;\s*/u', (string)$v, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($v) || !array_is_list($v)) return $need('רשימה של טקסטים');
            $out = [];
            foreach ($v as $x) {
                if (!is_string($x)) return [null, "\"{$f['key']}\": כל איבר ברשימה חייב להיות טקסט", null, null];
                $x = trim($x);
                if ($x === '') continue;
                if (mb_strlen($x) > FIELD_LIST_MAX[1] || preg_match('/[\r\n]/', $x)) return [null, "\"{$f['key']}\": איבר ארוך מ-" . FIELD_LIST_MAX[1] . ' תווים, או עם ירידת שורה', null, null];
                $out[] = $x;
            }
            if (count($out) > FIELD_LIST_MAX[0]) return [null, "\"{$f['key']}\": יותר מ-" . FIELD_LIST_MAX[0] . ' איברים', null, null];
            return [$out ?: null, null, null, null];
    }
    return [null, "סוג לא מוכר \"$t\"", null, null];
}

/** קובץ לשדה image/audio: מזהה, media/images/<id>.*, media/audio/<id>(-<key>).*, או קובץ מדיה בעץ. '' — ריק; null — לא נמצא. */
function field_file_resolve(string $v, string $kind): ?string
{
    $v = trim($v);
    if ($v === '') return '';
    if (preg_match('~^(?:\./)?media/(?:images|audio)/([0-9a-f]{32})(?:-[a-z][a-z0-9_]{0,39})?\.[A-Za-z0-9]{1,8}$~', $v, $m)) $v = $m[1];
    if (!file_id($v) && function_exists('pf_by_path')) {
        try { $r = pf_by_path(pf_path($v)); } catch (ApiError) { $r = null; }
        if ($r && $r['kind'] === 'media' && file_id((string)$r['file_id'])) $v = (string)$r['file_id'];
    }
    if (!file_id($v)) return null;
    $f = files_meta([$v])[$v] ?? null;
    return $f && ($f['deleted_at'] ?? null) === null && $f['kind'] === $kind ? $v : null;
}

/** הערך כפי שהוא בקובץ: image/audio — הנתיב בחבילה (06 §2.3); $app — כתובת להשמעה בתצוגה החיה. */
function field_out(array $f, mixed $v, array $meta, ?string $app): mixed
{
    if (!in_array($f['type'], ['image', 'audio'], true) || !is_string($v) || !file_id($v)) return $v;
    if ($app !== null) return $app . media_url($v);
    $m = $meta[$v] ?? null;
    if (!$m) return $v;
    return $f['type'] === 'image' ? "media/images/$v.{$m['ext']}" : "media/audio/$v-{$f['key']}.{$m['ext']}";
}

/** הערך בתא של אקסל. */
function field_cell(array $f, mixed $v, array $meta): string
{
    if ($v === null) return '';
    return match ($f['type']) {
        'rich' => lex_plain((string)$v),
        'bool' => $v ? 'כן' : 'לא',
        'list' => implode('; ', (array)$v),
        'number' => (string)json_encode($v),
        'image', 'audio' => (string)field_out($f, $v, $meta, null),
        default => (string)$v,
    };
}

/** כל מזהי הקבצים בשדות image/audio של המילים (ושל תמונות הנושאים) — ל-files_meta אחד. */
function fields_file_ids(array $rows, array $fields): array
{
    $ids = [];
    foreach (fields_custom($fields) as $f) if (in_array($f['type'], ['image', 'audio'], true)) {
        foreach ($rows as $w) if (file_id($w['extra'][$f['key']] ?? null)) $ids[] = $w['extra'][$f['key']];
    }
    return $ids;
}

// ── N2: נושאים מורחבים ────────────────────────────────────

const TOPIC_SLUG_RX = '/^[a-z][a-z0-9_]{0,39}$/';          // מפתח חדש של נושא — כמו מפתח של שדה (נכתב לקבצי הפרויקט)
const TOPIC_COLOR_RX = '/^#[0-9a-f]{6}$/';

/**
 * כל הנושאים, בסדר שלהם: השורות בטבלה, ועוד כל שם שבמילים ואין לו שורה (ה-slug שהוא מקבל היום, kt-<md5>). sort_order ריק:
 * כשכולם ריקים — לפי השם (lex_sort, הסדר של לפני N2), בקפיצות של 10; אחרת — הריקים לסוף, לפי השם. קריאה אינה כותבת.
 * מחזיר [slug => {slug, name, sort_order, color, image, hidden, count, row}].
 */
function topics_all(?array $words = null): array
{
    $words ??= words_rows();
    $count = [];
    foreach ($words as $w) foreach ($w['topics'] as $t) $count[(string)$t] = ($count[(string)$t] ?? 0) + 1;
    $by = []; $names = [];
    foreach (db()->query('SELECT slug, name, sort_order, color, image, hidden FROM topics')->fetchAll() as $t) {
        $by[$t['slug']] = ['slug' => (string)$t['slug'], 'name' => (string)$t['name'], 'sort_order' => $t['sort_order'] === null ? null : (int)$t['sort_order'],
            'color' => (string)$t['color'], 'image' => (string)$t['image'], 'hidden' => (bool)(int)$t['hidden'], 'row' => true];
        $names[$t['name']] = $t['slug'];
    }
    foreach ($count as $name => $n) {
        if (isset($names[$name])) continue;
        $map = $names;
        $slug = topic_slug($name, $map);
        $by[$slug] = ['slug' => $slug, 'name' => $name, 'sort_order' => null, 'color' => '', 'image' => '', 'hidden' => false, 'row' => false];
        $names[$name] = $slug;
    }
    foreach ($by as &$t) $t['count'] = $count[$t['name']] ?? 0;
    unset($t);
    $set = array_values(array_filter($by, fn($t) => $t['sort_order'] !== null));
    $empty = lex_sort(array_values(array_filter($by, fn($t) => $t['sort_order'] === null)), 'name');
    usort($set, fn($a, $b) => $a['sort_order'] <=> $b['sort_order'] ?: strcmp($a['slug'], $b['slug']));
    $n = $set ? max(array_column($set, 'sort_order')) : 0;
    foreach ($empty as &$t) $t['sort_order'] = $n += WORDS_ORDER_STEP;
    unset($t);
    $out = [];
    foreach (array_merge($set, $empty) as $t) $out[$t['slug']] = $t;
    return $out;
}

/** כותב את מה ש-topics_all מחשב בזיכרון: שורה לכל שם בלי שורה, וסדר לכל שורה בלי סדר. לפני כל פעולה על נושאים. */
function topics_fill(): void
{
    $pdo = db();
    $ins = $pdo->prepare('INSERT OR IGNORE INTO topics (slug, name, sort_order) VALUES (?, ?, ?)');
    $upd = $pdo->prepare('UPDATE topics SET sort_order = ? WHERE slug = ? AND sort_order IS NULL');
    foreach (topics_all() as $t) { if (!$t['row']) $ins->execute([$t['slug'], $t['name'], $t['sort_order']]); else $upd->execute([$t['sort_order'], $t['slug']]); }
}

/** רשימת הנושאים בקובץ: רק מה שיש לו מילה, לפי הסדר. מפתחות ריקים נשמטים — בלי צבע/תמונה הקובץ זהה ל-N1. */
function topics_out(array $all, ?string $app, array $meta): array
{
    $out = [];
    foreach ($all as $t) {
        if (!$t['count'] || ($app !== null && $t['hidden'])) continue;
        $o = ['id' => $t['slug'], 'label' => $t['name']];
        if ($t['color'] !== '') $o['color'] = $t['color'];
        if ($t['image'] !== '') $o['image'] = field_out(['type' => 'image', 'key' => 'image'], $t['image'], $meta, $app);
        if ($t['hidden']) $o['hidden'] = true;
        $out[] = $o;
    }
    return $out;
}

// ── N2: "מבנה המילה" — קריאה, עריכה, מחיקה לסל ───────────

/** השדות לממשק: עם מספר המילים שיש להן ערך. */
function fields_public(?array $rows = null): array
{
    $rows ??= words_rows();
    $out = [];
    foreach (fields_all() as $f) {
        $n = 0;
        foreach ($rows as $w) {
            $v = $f['col'] === null ? ($w['extra'][$f['key']] ?? null) : $w[$f['col']];
            if ($v !== null && $v !== '' && $v !== []) $n++;
        }
        $out[] = $f + ['filled' => $n, 'builtin' => $f['col'] !== null];
    }
    return $out;
}

function fields_state(array $user): array
{
    // N2 (08 §3): המבנה — עורך; "רוקן נכשלים" ומחיקת שדה שיש לו ערכים — מפרסם (כמו מחיקה של יותר מ-20 ב-N1)
    return ['fields' => fields_public(), 'types' => FIELD_TYPES, 'col_types' => FIELD_COL_TYPES, 'reserved' => FIELD_RESERVED,
        'can_structure' => true, 'can_purge' => ROLE_RANK[$user['role'] ?? 'editor'] >= ROLE_RANK['publisher'], 'rev' => words_rev()];
}

/** הגדרת שדה מהבקשה ← ערכים לשורה (בלי המפתח). שגיאה — ApiError. $cur — השדה הקיים (עריכה). */
function field_input(array $b, ?array $cur): array
{
    $label = str(preg_replace('/\s+/u', ' ', (string)($b['label'] ?? ($cur['label'] ?? ''))), 80);
    if ($label === '') throw new ApiError('חסר שם לשדה');
    $type = (string)($b['type'] ?? ($cur['type'] ?? 'text'));
    if ($cur && $cur['col'] !== null) {
        if (!in_array($type, FIELD_COL_TYPES[$cur['col']], true)) throw new ApiError("לשדה המובנה \"{$cur['label']}\" אפשר רק: " . implode(' / ', FIELD_COL_TYPES[$cur['col']]));
    } elseif (!in_array($type, FIELD_TYPES, true)) throw new ApiError('סוג לא מוכר');
    $options = $cur['options'] ?? [];
    if (array_key_exists('options', $b)) {
        if (!is_array($b['options']) || !array_is_list($b['options']) || count($b['options']) > 200) throw new ApiError('אפשרויות: רשימה של עד 200');
        $options = []; $seen = [];
        foreach ($b['options'] as $o) {
            $v = is_array($o) ? ($o['value'] ?? '') : $o;
            if (!is_string($v) || ($v = trim($v)) === '' || mb_strlen($v) > 200 || preg_match('/[\r\n]/', $v)) throw new ApiError('אפשרות לא תקינה (טקסט בשורה אחת, עד 200)');
            if (isset($seen[$v])) throw new ApiError("האפשרות \"$v\" מופיעה פעמיים");
            $seen[$v] = true;
            $options[] = ['value' => $v, 'label' => str(is_array($o) ? ($o['label'] ?? $v) : $v, 200) ?: $v];
        }
    }
    if ($type !== 'choice') $options = [];
    $dir = (string)($b['dir'] ?? ($cur['dir'] ?? 'auto'));
    if (!in_array($dir, FIELD_DIRS, true)) throw new ApiError('כיוון לא תקין');
    $o = ['label' => $label, 'type' => $type, 'options' => $options, 'dir' => $dir,
        'required' => array_key_exists('required', $b) ? ($b['required'] === true) : ($cur['required'] ?? false),
        'published' => array_key_exists('published', $b) ? ($b['published'] === true) : ($cur['published'] ?? true),
        'help' => array_key_exists('help', $b) ? str($b['help'], 300) : ($cur['help'] ?? '')];
    if ($cur && $cur['col'] === 'word') $o['required'] = true;                // המילה — תמיד חובה
    // ברירת מחדל — רק לשדה מותאם; נבדקת כמו כל ערך
    $def = array_key_exists('def', $b) ? $b['def'] : ($cur['def'] ?? null);
    $o['def'] = null;
    if (($cur['col'] ?? null) === null && $def !== null && $def !== '') {
        [$v, $err] = field_parse(['key' => 'def', 'options' => $options, 'type' => $type], $def);
        if ($err) throw new ApiError('ברירת המחדל: ' . $err);
        $o['def'] = $v;
    }
    return $o;
}

/**
 * הוספה / עריכה של שדה (עורך; "רוקן נכשלים" — מפרסם), או סדר השדות (order). שינוי סוג של שדה מותאם: כל ערך קיים מומר (field_convert);
 * dry — רק הדוח ("יומרו 340, ייכשלו 3"); נכשלים — 422 עם הרשימה, אלא אם clear_failed (ואז נקודת שחזור לפני, והם מתרוקנים).
 */
function field_save(array $user, array $b): array
{
    $pdo = db();
    if (isset($b['order'])) {
        $keys = array_values(array_filter(is_array($b['order']) ? $b['order'] : [], 'is_string'));
        $have = array_column(fields_all(), 'key');
        if (count($keys) !== count($have) || array_diff($have, $keys)) throw new ApiError('הסדר חייב לכלול כל שדה פעם אחת');
        pf_tx(function () use ($pdo, $keys) {
            fields_fill();
            $st = $pdo->prepare('UPDATE word_fields SET sort_order = ? WHERE key = ?');
            foreach ($keys as $i => $k) $st->execute([($i + 1) * 10, $k]);
        });
        audit_as($user, 'field_edit', 'סדר השדות: ' . implode(', ', $keys));
        return fields_state($user);
    }
    $fields = fields_all();
    $origKey = (string)($b['orig'] ?? '');
    $cur = null;
    foreach ($fields as $f) if ($f['key'] === $origKey) $cur = $f;
    if ($origKey !== '' && !$cur) throw new ApiError('השדה לא נמצא', 404);
    if ($cur && isset($b['key']) && $b['key'] !== $cur['key']) throw new ApiError('שינוי מפתח — דרך "שנה מפתח" (עם עדכון הקשירות בקבצים)');
    $in = field_input($b, $cur);
    $key = $cur ? $cur['key'] : (string)($b['key'] ?? '');
    if (!$cur && ($e = field_key_error($key, $fields))) throw new ApiError($e);
    // שינוי סוג של שדה מותאם — המרה של כל ערך קיים
    $conv = null;
    if ($cur && $cur['col'] === null && ($cur['type'] !== $in['type'] || ($in['type'] === 'choice' && $in['options'] !== $cur['options']))) {
        $conv = field_convert_plan($cur, ['key' => $key] + $in);
        if (!empty($b['dry'])) return ['convert' => array_diff_key($conv, ['values' => 1])];
        if ($conv['failed'] && !empty($b['clear_failed']) && ROLE_RANK[$user['role'] ?? 'editor'] < ROLE_RANK['publisher']) throw new ApiError('"רוקן את הנכשלים" — רק למפרסם', 403, ['convert' => array_diff_key($conv, ['values' => 1])]);
        if ($conv['failed'] && empty($b['clear_failed'])) throw new ApiError("ההמרה נעצרה: {$conv['failed_count']} ערכים לא מומרים. תקנו אותם, או בחרו \"רוקן את הנכשלים\"", 422, ['convert' => array_diff_key($conv, ['values' => 1])]);
    } elseif (!empty($b['dry'])) return ['convert' => null];
    $snap = $conv && ($conv['failed_count'] || $conv['converted'] > WORDS_BULK) ? snapshot_create($user, "לפני שינוי הסוג של השדה \"$key\": {$cur['type']} → {$in['type']}") : null;
    pf_tx(function () use ($pdo, $cur, $key, $in, $conv, $fields) {
        fields_fill();
        $row = [$in['label'], $in['type'], (int)$in['required'], $in['def'] === null ? null : json_encode($in['def'], JSON_UNESCAPED_UNICODE),
            $in['help'], json_encode($in['options'], JSON_UNESCAPED_UNICODE), (int)$in['published'], $in['dir']];
        if ($cur) $pdo->prepare('UPDATE word_fields SET label = ?, type = ?, required = ?, def = ?, help = ?, options = ?, published = ?, dir = ? WHERE key = ?')->execute([...$row, $key]);
        else {
            $max = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM word_fields')->fetchColumn();
            $pdo->prepare('INSERT INTO word_fields (label, type, required, def, help, options, published, dir, key, col, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)')->execute([...$row, $key, $max + 10]);
        }
        if ($conv) words_extra_write($key, $conv['values']);
    });
    $detail = $cur ? "$key ({$in['label']})" . ($conv ? " · סוג {$cur['type']} → {$in['type']}: {$conv['converted']} הומרו, {$conv['failed_count']} רוקנו" : '') : "$key ({$in['label']}, {$in['type']})";
    audit_as($user, $cur ? 'field_edit' : 'field_add', $detail);
    return fields_state($user) + ['snapshot' => $snap, 'convert' => $conv ? array_diff_key($conv, ['values' => 1]) : null];
}

/** כותב לשדה $key את הערכים [id => ערך|null] (null — המפתח יוצא). בתוך עסקה. */
function words_extra_write(string $key, array $values): void
{
    $pdo = db();
    $get = $pdo->prepare('SELECT extra FROM words WHERE id = ?');
    $put = $pdo->prepare('UPDATE words SET extra = ? WHERE id = ?');
    foreach ($values as $id => $v) {
        $get->execute([$id]);
        $x = json_decode((string)$get->fetchColumn(), true);
        $x = is_array($x) && !array_is_list($x) ? $x : [];
        if ($v === null) unset($x[$key]); else $x[$key] = $v;
        ksort($x);
        $put->execute([$x ? json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}', $id]);
    }
}

/** המרת ערך לסוג אחר: הערך כטקסט (כמו בתא של אקסל) ← נקרא בסוג החדש. מחזיר [ערך|null, שגיאה|null]. */
function field_convert(array $from, array $to, mixed $v): array
{
    if ($v === null) return [null, null];
    $s = in_array($from['type'], ['image', 'audio'], true) ? (string)$v : field_cell($from, $v, []);
    [$out, $err, , $opt] = field_parse($to, $s, true);
    if (in_array($to['type'], ['image', 'audio'], true)) [$out, $err] = file_id($s) && field_file_resolve($s, $to['type']) ? [$s, null] : [null, 'אינו קובץ מהסוג הזה'];
    return [$out, $err ?? ($opt !== null ? 'אינו אחת האפשרויות' : null)];
}

/** תוכנית ההמרה: כמה יומרו, כמה ייכשלו (עם הרשימה), והערכים החדשים. */
function field_convert_plan(array $from, array $to): array
{
    $values = []; $failed = []; $n = 0;
    foreach (words_rows() as $w) {
        if (!array_key_exists($from['key'], $w['extra'])) continue;
        $v = $w['extra'][$from['key']];
        [$out, $err] = field_convert($from, $to, $v);
        if ($err !== null || $out === null) { $failed[] = ['id' => $w['id'], 'word' => $w['word'], 'value' => mb_substr(is_array($v) ? implode('; ', $v) : (string)json_encode($v, JSON_UNESCAPED_UNICODE), 0, 120)]; $values[$w['id']] = null; continue; }
        if ($out !== $v) $values[$w['id']] = $out;
        $n++;
    }
    return ['converted' => $n, 'failed_count' => count($failed), 'failed' => array_slice($failed, 0, 500), 'values' => $values];
}

/**
 * מחיקת שדה מותאם — לסל: השורה וכל הערכים ({id: value}) ב-field_trash, ושחזור מהסל מחזיר את שניהם. לפני — נקודת שחזור.
 * dry — רק מה יימחק: כמה ערכים, וכמה קשירות בקבצי הפרויקט יישארו בלי ערך.
 */
function field_delete(array $user, array $b): array
{
    $key = (string)($b['key'] ?? '');
    $f = null;
    foreach (fields_all() as $x) if ($x['key'] === $key) $f = $x;
    if (!$f) throw new ApiError('השדה לא נמצא', 404);
    if ($f['col'] !== null) throw new ApiError("\"{$f['label']}\" הוא שדה מובנה — אינו נמחק. אפשר להסתיר אותו מהעמוד (\"מתפרסם\")");
    $vals = [];
    foreach (words_rows() as $w) if (array_key_exists($key, $w['extra'])) $vals[$w['id']] = $w['extra'][$key];
    $binds = function_exists('fields_bind_scan') ? count(array_filter(fields_bind_scan('field', $key), fn($h) => !$h['manual'])) : 0;
    if (!empty($b['dry'])) return ['values' => count($vals), 'bindings' => $binds, 'needs' => $vals ? 'publisher' : 'editor'];
    if ($vals && ROLE_RANK[$user['role'] ?? 'editor'] < ROLE_RANK['publisher']) throw new ApiError('מחיקת שדה שיש לו ערכים (' . count($vals) . ') — רק למפרסם', 403);
    $snap = snapshot_create($user, "לפני מחיקת השדה \"{$f['label']}\" ($key)");
    pf_tx(function () use ($user, $key, $vals) {
        $st = db()->prepare('SELECT * FROM word_fields WHERE key = ?');
        $st->execute([$key]);
        $row = $st->fetch();
        db()->prepare('INSERT INTO field_trash (at, by_name, field, vals) VALUES (?, ?, ?, ?)')
            ->execute([now_iso(), $user['name'] ?? '', json_encode($row, JSON_UNESCAPED_UNICODE), json_encode((object)$vals, JSON_UNESCAPED_UNICODE)]);
        words_extra_write($key, array_fill_keys(array_keys($vals), null));
        db()->prepare('DELETE FROM word_fields WHERE key = ?')->execute([$key]);
    });
    audit_as($user, 'field_delete', "$key ({$f['label']}) · " . count($vals) . ' ערכים → לסל');
    return fields_state($user) + ['snapshot' => $snap, 'bindings' => $binds];
}

/** מהסל: השדה כמו שהיה, והערכים — למילים שעדיין קיימות. מפתח שנתפס בינתיים — 409. בתוך העסקה של trash_restore. */
function field_trash_restore(array $user, int $id): string
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM field_trash WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch() ?: throw new ApiError('לא נמצא בסל', 404);
    $row = json_decode($t['field'], true);
    $chk = $pdo->prepare('SELECT 1 FROM word_fields WHERE key = ?');
    $chk->execute([$row['key']]);
    if ($chk->fetchColumn()) throw new ApiError("כבר יש שדה עם המפתח \"{$row['key']}\" — שנו את המפתח שלו, ואז שחזרו", 409);
    fields_fill();
    $cols = array_keys($row);
    $pdo->prepare('INSERT INTO word_fields (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
    $exists = array_flip(array_map('intval', $pdo->query('SELECT id FROM words')->fetchAll(PDO::FETCH_COLUMN)));
    $vals = array_filter(json_decode($t['vals'], true) ?: [], fn($k) => isset($exists[(int)$k]), ARRAY_FILTER_USE_KEY);
    words_extra_write($row['key'], $vals);
    $pdo->prepare('DELETE FROM field_trash WHERE id = ?')->execute([$id]);
    return "שדה · {$row['label']} ({$row['key']}) · " . count($vals) . ' ערכים';
}

/**
 * שינוי שם של נושאים — ה-slug נשאר (זה התיקון של הפגם: הכפתור, הקישור וה-CSS בעמוד קשורים ל-slug). [slug => [שם ישן, שם חדש]].
 * כמה בבת אחת (גם החלפה הדדית): השורות דרך שם זמני, והמילים — מעבר אחד עם המפה. בתוך עסקה. מחזיר כמה מילים השתנו.
 */
function topics_rename(array $user, array $map): int
{
    if (!$map) return 0;
    $pdo = db();
    $tmp = $pdo->prepare('UPDATE topics SET name = ? WHERE slug = ?');
    foreach ($map as $slug => [$from, $to]) $tmp->execute(["\x01$slug", $slug]);
    foreach ($map as $slug => [$from, $to]) {
        $chk = $pdo->prepare('SELECT slug FROM topics WHERE name = ?');
        $chk->execute([$to]);
        if (($other = $chk->fetchColumn()) !== false) throw new ApiError("כבר יש נושא בשם \"$to\" — למיזוג: \"מזג אל…\"", 409);
        $tmp->execute([$to, $slug]);
    }
    $names = [];
    foreach ($map as [$from, $to]) $names[$from] = $to;
    $upd = $pdo->prepare('UPDATE words SET topics = ?, updated_at = ?, updated_by = ? WHERE id = ?');
    $n = 0;
    foreach ($pdo->query('SELECT id, topics FROM words')->fetchAll() as $r) {
        $t = json_decode($r['topics'], true) ?: [];
        if (!array_intersect($t, array_keys($names))) continue;
        $t = array_values(array_unique(array_map(fn($x) => $names[$x] ?? $x, $t)));
        $upd->execute([json_encode($t, JSON_UNESCAPED_UNICODE), now_iso(), $user['name'] ?? '', $r['id']]);
        $n++;
    }
    return $n;
}

/** צבע, תמונה, מוסתר — לנושא שכבר יש לו שורה (topics_fill). הערכים כבר נבדקו. */
function topic_attrs(string $slug, array $u): void
{
    $set = []; $vals = [];
    foreach (['color' => 'color', 'image' => 'image', 'hidden' => 'hidden', 'sort_order' => 'sort_order'] as $k => $col) if (array_key_exists($k, $u)) { $set[] = "$col = ?"; $vals[] = is_bool($u[$k]) ? (int)$u[$k] : $u[$k]; }
    if ($set) db()->prepare('UPDATE topics SET ' . implode(', ', $set) . ' WHERE slug = ?')->execute([...$vals, $slug]);
}
