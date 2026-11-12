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
    $rows = db()->query('SELECT id, wp_id, word, meaning, topics, audio, external_id, sort_order FROM words ORDER BY id')->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['wp_id'] = $r['wp_id'] === null ? null : (int)$r['wp_id'];
        $r['topics'] = json_decode($r['topics'], true) ?: [];
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

/** הנושאים שיש להם מילה, עם מספר המילים — כמו get_terms(hide_empty). */
function topics_used(array $words, array &$map): array
{
    $count = [];
    foreach ($words as $w) foreach ($w['topics'] as $t) $count[$t] = ($count[$t] ?? 0) + 1;
    $out = [];
    foreach ($count as $name => $n) $out[] = ['name' => (string)$name, 'slug' => topic_slug((string)$name, $map), 'count' => $n];
    return lex_sort($out, 'name');
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
    foreach ($rows as &$w) {
        $w['audio_src'] = audio_src($w['audio']);
        $f = file_id($w['audio']) ? ($files[$w['audio']] ?? null) : null;
        $w['audio_kind'] = $w['audio'] === '' ? '' : (!file_id($w['audio']) ? 'site' : (!$f ? 'missing' : (!empty($f['origin']) ? 'copy' : 'file')));
    }
    unset($w);
    return ['words' => $rows, 'topics' => topics_used($rows, $map)];
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
    if ($id) {
        $st = $pdo->prepare('UPDATE words SET word = ?, meaning = ?, topics = ?, audio = ?, external_id = ?, updated_at = ?, updated_by = ? WHERE id = ?');
        $st->execute([$in['word'], $in['meaning'], $json, $in['audio'], $in['external_id'], now_iso(), $user['name'], $id]);
        if (!$st->rowCount()) throw new ApiError('המילה לא נמצאה', 404);
    } else {
        $pdo->prepare('INSERT INTO words (word, meaning, topics, audio, external_id, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$in['word'], $in['meaning'], $json, $in['audio'], $in['external_id'], now_iso(), $user['name']]);
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

/** נושא: שינוי שם (גם איחוד — אם השם החדש כבר קיים) או הסרה מכל המילים. */
function topic_op(array $user, array $b): array
{
    $name = str($b['name'] ?? '', 80);
    $to = str(preg_replace('/\s+/u', ' ', (string)($b['to'] ?? '')), 80);
    $op = (string)($b['op'] ?? '');
    if ($name === '' || !in_array($op, ['rename', 'delete'], true) || ($op === 'rename' && $to === '')) throw new ApiError('בקשה לא תקינה');
    snapshot_create($user, 'לפני ' . ($op === 'rename' ? "שינוי שם הנושא \"$name\"" : "הסרת הנושא \"$name\""));
    $pdo = db();
    $st = $pdo->prepare('UPDATE words SET topics = ?, updated_at = ?, updated_by = ? WHERE id = ?');
    $n = 0;
    $before = [];
    $pdo->beginTransaction();
    foreach ($pdo->query('SELECT id, topics FROM words')->fetchAll() as $r) {
        $t = json_decode($r['topics'], true) ?: [];
        if (!in_array($name, $t, true)) continue;
        $before[$r['id']] = $t;
        $t = array_values(array_unique(array_filter(array_map(fn($x) => $x === $name ? ($op === 'rename' ? $to : '') : $x, $t))));
        $st->execute([json_encode($t, JSON_UNESCAPED_UNICODE), now_iso(), $user['name'], $r['id']]);
        $n++;
    }
    // לסל המחזור: הנושאים של כל מילה לפני הפעולה — "שחזור" מחזיר אותם
    if ($before) $pdo->prepare('INSERT INTO topic_trash (at, by_name, op, name, to_name, before) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([now_iso(), $user['name'], $op, $name, $op === 'rename' ? $to : null, json_encode($before, JSON_UNESCAPED_UNICODE)]);
    if ($op === 'rename') {
        $map = topic_slugs();
        $pdo->prepare('INSERT OR IGNORE INTO topics (slug, name) VALUES (?, ?)')->execute([topic_slug($to, $map), $to]);
    }
    $pdo->commit();
    audit_as($user, 'topic_' . $op, $op === 'rename' ? "$name → $to ($n מילים)" : "$name ($n מילים)");
    return words_list();
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
    $map = topic_slugs();
    $topics = array_map(fn($t) => ['id' => $t['slug'], 'label' => $t['name']], topics_used($rows, $map));
    $meta = $app === null ? files_meta(array_values(array_unique(array_filter(array_map(fn($w) => file_id($w['audio']), $rows))))) : [];
    $items = [];
    foreach ($rows as $w) {
        $item = [
            'id' => $w['id'], 'word' => $w['word'], 'meaning' => $w['meaning'],
            'topics' => array_map(fn($t) => topic_slug((string)$t, $map), $w['topics']),
            'audio' => $app === null ? words_audio_ref((string)$w['audio'], $meta) : (($a = (string)$w['audio']) === '' ? '' : (file_id($a) ? $app . media_url($a) : $a)),
        ];
        // המזהה באתר (למשוב) והמזהה מהאקסל — רק למילה שיש לה (F3: הכרטיס המוסב נקשר אליהם)
        if (($w['wp_id'] ?? null) !== null) $item['wp_id'] = $w['wp_id'];
        if ((string)($w['external_id'] ?? '') !== '') $item['external_id'] = $w['external_id'];
        $items[] = $item;
    }
    return words_json_encode(['topics' => $topics, 'items' => $items]);
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
    return 'w' . substr(hash('sha256', $json ?? words_json()), 0, 16);
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
    $map = topic_slugs();
    topics_used($rows, $map);                                   // גם מפתחות של שמות שאינם בטבלת הנושאים
    $meta = files_meta(array_values(array_unique(array_filter(array_map(fn($w) => file_id($w['audio']), $rows)))));
    $by = [];
    foreach ($rows as $w) {
        $w['slugs'] = array_map(fn($t) => topic_slug((string)$t, $map), $w['topics']);
        $w['audio_ref'] = words_audio_ref((string)$w['audio'], $meta);
        $by[$w['id']] = $w;
    }
    return ['by' => $by, 'order' => array_column($rows, 'id'), 'slug_name' => array_flip($map), 'name_slug' => $map];
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

const WJ_ITEM_KEYS = ['id', 'word', 'meaning', 'topics', 'audio', 'wp_id', 'external_id'];

/**
 * הקובץ ← רשימת פריטים מנורמלים, או שגיאות עם שורה ועמודה. ערך שזהה לערך שבטבלה נשאר בדיוק כמו בטבלה (בלי ניקוי
 * חוזר) — כך קובץ שלא נערך לא "משנה" אף מילה. ערך שהשתנה עובר את אותו ניקוי כמו עריכה בטבלה (word_input).
 */
function words_file_items(string $bytes, array $state, array $create): array
{
    $scan = wj_scan($bytes);
    $errors = []; $warnings = []; $proposals = [];
    $at = function (string $path) use ($scan, $bytes): array {
        $o = $scan['pos'][$path] ?? $scan['pos'][preg_replace('~/[^/]*$~', '', $path)] ?? 0;
        return wj_linecol($bytes, $o);
    };
    $add = function (array &$list, string $path, string $msg) use ($at) { [$l, $c] = $at($path); $list[] = ['line' => $l, 'col' => $c, 'msg' => $msg]; };
    if ($scan['error']) { [$l, $c] = wj_linecol($bytes, $scan['error'][1]); return ['errors' => [['line' => $l, 'col' => $c, 'msg' => 'JSON לא תקין: ' . $scan['error'][0]]]]; }
    $j = $scan['value'];
    // {} ו-[] מתפענחים שניהם למערך ריק — ההבחנה לפי התו בקובץ. "items": {} אינו "רשימה ריקה" (שהייתה מעבירה הכול לסל)
    $is = fn(string $path, string $ch) => ($bytes[$scan['pos'][$path] ?? -1] ?? '') === $ch;
    if (!is_array($j) || !$is('', '{')) return ['errors' => [['line' => 1, 'col' => 1, 'msg' => 'הקובץ חייב להיות אובייקט: { "topics": [...], "items": [...] }']]];
    foreach (array_keys($j) as $k) if (!in_array($k, ['topics', 'items'], true)) $add($errors, "/$k#key", "שדה לא מוכר \"$k\" — שדות חדשים יתווספו ב-N2. הסירו אותו כדי לשמור");
    if (!isset($j['items']) || !is_array($j['items']) || !array_is_list($j['items']) || !$is('/items', '[')) {
        $add($errors, isset($j['items']) ? '/items' : '', '"items" חסר, או שאינו רשימה');
        return ['errors' => $errors];
    }
    // הנושאים שבראש הקובץ — רק כדי לתת שם לנושא חדש. שינוי שם של נושא קיים — במסך הנושאים (לא ב-N1)
    $labels = [];
    if (isset($j['topics'])) {
        if (!is_array($j['topics']) || !array_is_list($j['topics']) || !$is('/topics', '[')) $add($errors, '/topics', '"topics" חייב להיות רשימה של { "id", "label" }');
        else foreach ($j['topics'] as $ti => $t) {
            if (!is_array($t) || !$is("/topics/$ti", '{') || !is_string($t['id'] ?? null) || !is_string($t['label'] ?? null) || array_diff(array_keys($t), ['id', 'label'])) { $add($errors, "/topics/$ti", 'נושא חייב להיות { "id": "…", "label": "…" } (מחרוזות)'); continue; }
            $labels[$t['id']] = $t['label'];
            $name = $state['slug_name'][$t['id']] ?? null;
            if ($name !== null && $name !== $t['label']) $add($warnings, "/topics/$ti/label", "שינוי שם הנושא \"$name\" נעשה במסך הנושאים — כאן הוא לא נשמר");
        }
    }
    $items = []; $seen = [];
    foreach ($j['items'] as $x => $it) {
        $p = "/items/$x";
        if (!is_array($it) || !$is($p, '{')) { $add($errors, $p, 'פריט חייב להיות אובייקט'); continue; }
        $bad = false;
        foreach (array_keys($it) as $k) if (!in_array($k, WJ_ITEM_KEYS, true)) { $add($errors, "$p/$k#key", "שדה לא מוכר \"$k\" — יתווסף כשדה ב-N2. הסירו אותו כדי לשמור"); $bad = true; }
        $id = $it['id'] ?? null;
        if ($id !== null && (!is_int($id) || $id < 1 || $id > 2147483647)) { $add($errors, "$p/id", '"id" חייב להיות מספר שלם חיובי (או להיעדר — מילה חדשה)'); continue; }
        if ($id !== null && isset($seen[$id])) { $add($errors, "$p/id", "\"id\": $id מופיע פעמיים (גם בפריט " . ($seen[$id] + 1) . ')'); continue; }
        if ($id !== null) $seen[$id] = $x;
        $cur = $id !== null ? ($state['by'][$id] ?? null) : null;
        foreach (['word', 'meaning', 'audio', 'external_id'] as $k) {
            if (array_key_exists($k, $it) && !is_string($it[$k])) { $add($errors, "$p/$k", "\"$k\" חייב להיות מחרוזת"); $bad = true; }
        }
        $topicsIn = $it['topics'] ?? [];
        if (!is_array($topicsIn) || !array_is_list($topicsIn) || (array_key_exists('topics', $it) && !$is("$p/topics", '[')) || array_filter($topicsIn, fn($t) => !is_string($t))) { $add($errors, "$p/topics", '"topics" חייב להיות רשימה של מפתחות נושא (מחרוזות)'); $bad = true; }
        if ($bad) continue;
        $o = ['x' => $x, 'id' => $id, 'line' => $at($p)[0]];
        // מילה
        $w = $it['word'] ?? '';
        if ($cur && $w === $cur['word']) $o['word'] = $cur['word'];
        elseif (trim($w) === '') { $add($errors, "$p/word", '"word" ריק'); continue; }
        elseif (mb_strlen(trim($w)) > WORD_MAX) { $add($errors, "$p/word", '"word" ארוך מ-' . WORD_MAX . ' תווים'); continue; }
        else $o['word'] = str($w, WORD_MAX);
        // פירוש
        $m = $it['meaning'] ?? '';
        if ($cur && $m === $cur['meaning']) $o['meaning'] = $cur['meaning'];
        elseif (mb_strlen($m) > MEANING_MAX) { $add($errors, "$p/meaning", '"meaning" ארוך מ-' . MEANING_MAX . ' תווים'); continue; }
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
        if ($cur === null && $id !== null) $add($warnings, "$p/id", "אין מילה עם \"id\": $id — תתווסף מילה חדשה עם המזהה הזה. המזהה הוא המפתח של ההקלטה ושל קישורים למילה");
        $items[] = $o;
    }
    // id שהשתנה = מילה שנמחקה ואחרת שנוספה. אזהרה אחת, כשיש גם מילה שנעלמת וגם מזהה שאינו קיים
    return ['errors' => $errors, 'warnings' => $warnings, 'items' => $items, 'proposals' => array_values($proposals), 'full' => true, 'seq' => true];
}

/**
 * מנוע השמירה — אחד לקובץ ולאקסל. $in: items (מנורמלים), full (קובץ שלם: מה שאינו בו — לסל; והסדר — סדר הפריטים),
 * order (אקסל: [id => sort_order] מפורש). מחזיר את התוכנית: מה ישתנה, לפי id, ואסימון שמזהה אותה.
 */
function words_plan(array $in, array $state): array
{
    $changed = []; $added = []; $trashed = []; $keepIds = [];
    $fields = ['word', 'meaning', 'topics', 'audio', 'external_id'];
    $short = fn($v) => is_array($v) ? implode(', ', $v) : (mb_strlen((string)$v) > 300 ? mb_substr((string)$v, 0, 300) . '…' : (string)$v);
    foreach ($in['items'] as $o) {
        $cur = $o['id'] !== null ? ($state['by'][$o['id']] ?? null) : null;
        if (!$cur) { $added[] = ['id' => $o['id'], 'word' => $o['word'], 'line' => $o['line'] ?? null]; continue; }
        $keepIds[$o['id']] = true;
        $diff = [];
        foreach ($fields as $f) if (array_key_exists($f, $o) && $o[$f] !== $cur[$f]) $diff[$f] = [$short($cur[$f]), $short($o[$f])];
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
    $counts = ['changed' => count($changed), 'added' => count($added), 'trashed' => count($trashed), 'moved' => $moved,
        'topics' => count($in['create'] ?? [])];
    $plan = ['counts' => $counts, 'changed' => array_slice($changed, 0, 500), 'added' => array_slice($added, 0, 500), 'trashed' => array_slice($trashed, 0, 500),
        'new_topics' => $in['create'] ?? [], 'proposals' => $in['proposals'] ?? [], 'warnings' => $in['warnings'] ?? [],
        'snapshot' => $counts['trashed'] > 0 || $counts['changed'] + $counts['added'] + $moved > WORDS_BULK,
        'needs' => $counts['trashed'] > WORDS_BULK || !empty($in['replace']) ? 'publisher' : 'editor'];
    $plan['summary'] = words_plan_text($counts);
    // האסימון: <חותמת הטבלה>.<חותמת התוכנית> — כך ההחלה יודעת קודם כול אם הטבלה השתנתה מאז התצוגה
    $plan['token'] = words_state_hash($state) . '.' . substr(hash('sha256', json_encode([$in['items'], $set, array_column($trashed, 'id'), $in['create'] ?? []], JSON_UNESCAPED_UNICODE)), 0, 32);
    return [$plan, $set];
}

function words_state_hash(array $state): string
{
    return substr(hash('sha256', json_encode([$state['order'], array_map(fn($w) => [$w['word'], $w['meaning'], $w['topics'], $w['audio'], $w['external_id'], $w['wp_id'], $w['sort_order']], $state['by']),
        $state['name_slug']], JSON_UNESCAPED_UNICODE)), 0, 32);
}

function words_plan_text(array $c): string
{
    $parts = [];
    $parts[] = $c['changed'] === 1 ? 'תשתנה מילה אחת' : "ישתנו {$c['changed']} מילים";
    $parts[] = $c['added'] === 1 ? 'תתווסף 1' : "יתווספו {$c['added']}";
    $parts[] = "יעברו לסל {$c['trashed']}";
    $parts[] = "ישתנה הסדר של {$c['moved']}";
    if (!empty($c['topics'])) $parts[] = "נושאים חדשים: {$c['topics']}";
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
        $upd = $pdo->prepare('UPDATE words SET word = ?, meaning = ?, topics = ?, audio = ?, external_id = ?, updated_at = ?, updated_by = ? WHERE id = ?');
        $insId = $pdo->prepare('INSERT INTO words (id, word, meaning, topics, audio, external_id, sort_order, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins = $pdo->prepare('INSERT INTO words (word, meaning, topics, audio, external_id, sort_order, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $changedIds = array_flip(array_column($plan['changed'], 'id'));
        $nn = 0; $newIds = [];
        foreach ($in['items'] as $o) {
            $cur = $o['id'] !== null ? ($state['by'][$o['id']] ?? null) : null;
            $v = [];
            foreach (['word', 'meaning', 'topics', 'audio', 'external_id'] as $f) $v[$f] = array_key_exists($f, $o) ? $o[$f] : ($cur[$f] ?? ($f === 'topics' ? [] : ''));
            if ($cur && !isset($changedIds[$o['id']])) continue;
            foreach ($v['topics'] as $t) if (!in_array($t, $createdNames, true)) $known->execute([topic_slug($t, $map), $t]);
            $tj = json_encode($v['topics'], JSON_UNESCAPED_UNICODE);
            if ($cur) { $upd->execute([$v['word'], $v['meaning'], $tj, $v['audio'], $v['external_id'], $now, $user['name'], $o['id']]); continue; }
            $so = !empty($in['seq']) ? ($set['new:' . $nn++] ?? null) : null;
            if ($o['id'] !== null) {
                $chk = $pdo->prepare('SELECT 1 FROM words WHERE id = ?');
                $chk->execute([$o['id']]);
                if ($chk->fetchColumn()) throw new ApiError("המזהה {$o['id']} תפוס", 409);
                $insId->execute([$o['id'], $v['word'], $v['meaning'], $tj, $v['audio'], $v['external_id'], $so, $now, $user['name']]);
                $newIds[] = $o['id'];
            } else {
                $ins->execute([$v['word'], $v['meaning'], $tj, $v['audio'], $v['external_id'], $so, $now, $user['name']]);
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
    $build = function (array $state) use ($bytes, $create) {
        $in = words_file_items($bytes, $state, $create);
        $in['create'] = array_values(array_filter($in['proposals'] ?? [], fn($p) => in_array($p['slug'], $create, true)));
        $pending = array_values(array_filter($in['proposals'] ?? [], fn($p) => !in_array($p['slug'], $create, true)));
        if ($pending && empty($in['errors'])) $in['errors'] = array_map(fn($p) => ['line' => $p['line'], 'col' => 1, 'msg' => "אין נושא \"{$p['slug']}\" — אשרו \"צור נושא {$p['slug']}\", או תקנו את המפתח", 'propose' => $p['slug']], $pending);
        return $in;
    };
    if (empty($b['apply'])) {
        $state = words_state();
        $in = $build($state);
        if (!empty($in['errors'])) throw new ApiError('הקובץ לא נשמר — ' . count($in['errors']) . ' שגיאות. הטבלה לא השתנתה', 422, ['errors' => array_slice($in['errors'], 0, 200), 'proposals' => $in['proposals'] ?? [], 'warnings' => $in['warnings'] ?? []]);
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
    fputcsv($out, array_values(CSV_COLS), ',', '"', '');
    $rows = words_rows();
    $meta = files_meta(array_values(array_unique(array_filter(array_map(fn($w) => file_id($w['audio']), $rows)))));
    foreach ($rows as $w) {
        fputcsv($out, array_map('csv_cell', [$w['id'], $w['word'], lex_plain($w['meaning']), implode('; ', $w['topics']), words_audio_ref((string)$w['audio'], $meta),
            $w['external_id'], $w['sort_order'], $w['wp_id'] ?? '']), ',', '"', '');
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
function words_csv_items(array $rows, array $state): array
{
    $head = array_map(fn($h) => trim((string)$h), $rows[0] ?? []);
    $col = [];
    foreach (CSV_COLS as $k => $label) {
        if (($i = array_search($label, $head, true)) !== false || ($i = array_search($k, $head, true)) !== false) $col[$k] = $i;
    }
    if (!isset($col['word'])) return ['errors' => [['line' => 1, 'col' => 1, 'msg' => 'לא מצאתי עמודה "מילה" בשורה הראשונה. כדאי להתחיל מקובץ שיוצא מכאן (⬇️ אקסל)']]];
    $errors = []; $warnings = []; $items = []; $order = []; $seen = []; $newTopics = [];
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
        $items[] = $o;
    }
    // נושא חדש מאקסל נוצר לפי השם (כמו לפני N1) — ומוצג בתוכנית
    return ['errors' => $errors, 'warnings' => $warnings, 'items' => $items, 'order' => $order, 'full' => false, 'new_topic_names' => array_keys($newTopics)];
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
    $build = function (array $state) use ($rows, $replace) {
        $in = words_csv_items($rows, $state);
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
