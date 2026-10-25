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

/** המילים שהרינדור משתמש בהן: מהטבלה, או — בגרסה שמורה — מהעותק שלה. */
function words_rows(): array
{
    if (isset($GLOBALS['kol_words'])) return $GLOBALS['kol_words'];
    $rows = db()->query('SELECT id, wp_id, word, meaning, topics, audio, external_id FROM words')->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['wp_id'] = $r['wp_id'] === null ? null : (int)$r['wp_id'];
        $r['topics'] = json_decode($r['topics'], true) ?: [];
    }
    unset($r);
    return lex_sort($rows, 'word');
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
    $pdo = db();
    $st = $pdo->prepare('UPDATE words SET topics = ?, updated_at = ?, updated_by = ? WHERE id = ?');
    $n = 0;
    $pdo->beginTransaction();
    foreach ($pdo->query('SELECT id, topics FROM words')->fetchAll() as $r) {
        $t = json_decode($r['topics'], true) ?: [];
        if (!in_array($name, $t, true)) continue;
        $t = array_values(array_unique(array_filter(array_map(fn($x) => $x === $name ? ($op === 'rename' ? $to : '') : $x, $t))));
        $st->execute([json_encode($t, JSON_UNESCAPED_UNICODE), now_iso(), $user['name'], $r['id']]);
        $n++;
    }
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
    $st = db()->prepare('SELECT word FROM words WHERE id = ?');
    $st->execute([(int)($b['id'] ?? 0)]);
    $word = $st->fetchColumn();
    if ($word === false) throw new ApiError('המילה לא נמצאה', 404);
    db()->prepare('DELETE FROM words WHERE id = ?')->execute([(int)$b['id']]);
    audit_as($user, 'word_delete', (string)$word);
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

    $sub = $p['subtitle_size'];
    $main = $p['subtitle_main_size'] > 0 ? $p['subtitle_main_size'] : max(14, (int)round($p['title_size'] * 0.6));
    $L = '.kadisha-lexicon-page';
    $css = "$L .kadisha-hero{" . ($p['hero_min_height'] > 0 ? "min-height:{$p['hero_min_height']}px;display:flex;align-items:center;justify-content:center;" : '') . '}'
        . "$L .kadisha-hero-title,$L .kadisha-hero h1{font-size:{$p['title_size']}px!important;font-weight:{$p['title_weight']}!important;line-height:1.15;margin-bottom:0!important}"
        . "$L .kadisha-hero-subtitle{font-size:{$sub}px!important;font-weight:{$p['title_weight']}!important}"
        . "$L .kadisha-hero-credit{font-size:" . max(11, (int)round($sub * 0.75)) . 'px!important;margin-top:0!important;line-height:1.25!important;opacity:.92}'
        . "$L .kadisha-hero-subtitle:has(+ .kadisha-hero-credit){margin-bottom:0!important;line-height:1.25!important}"
        . "@supports not selector(:has(*)){{$L} .kadisha-hero-subtitle{margin-bottom:0!important}}"
        . "$L .kadisha-hero-subtitle-main{font-family:'Frank Ruhl Libre','David Libre',serif!important;font-size:{$main}px!important;font-weight:{$p['title_weight']}!important;"
        . 'line-height:1.1!important;color:var(--ka-cream,#f5ebd6)!important;text-shadow:0 2px 8px rgba(0,0,0,.3);margin:-.1em 0 1.1rem!important;padding:0!important;letter-spacing:.01em}'
        . "$L .kadisha-hero-eyebrow--under{font-size:{$p['title_en_size']}px!important;margin-top:0;margin-bottom:.6rem;padding:.15rem 1.5rem}"
        . '@media (max-width:600px){'
        . "$L .kadisha-hero-title,$L .kadisha-hero h1{font-size:" . max(20, (int)round($p['title_size'] * 0.7)) . 'px!important}'
        . "$L .kadisha-hero-subtitle-main{font-size:" . max(14, (int)round($main * 0.8)) . 'px!important}'
        . "$L .kadisha-hero-subtitle{font-size:" . max(12, (int)round($sub * 0.85)) . 'px!important}'
        . "$L .kadisha-hero-eyebrow--under{font-size:" . max(10, (int)round($p['title_en_size'] * 0.8)) . 'px!important}'
        . "$L .kadisha-hero-credit{font-size:" . max(10, (int)round($sub * 0.85 * 0.75)) . 'px!important}}'
        . ".kol-page .kol-w-{$w['id']}{margin:0}";

    $gates = [];
    if ($p['intro_before'] !== '') $gates[] = ['label' => $p['gate_before'], 'html' => $p['intro_before']];
    if ($p['intro_after'] !== '') $gates[] = ['label' => $p['gate_after'], 'html' => $p['intro_after']];
    if (count($gates) === 1 && $p['intro_after'] === '') $gates[0]['label'] = $p['gate_after'];

    $o = "<div class=\"$cls kadisha-page kadisha-lexicon-page\" dir=\"rtl\" lang=\"he\"$k>";
    $o .= '<section class="kadisha-hero' . ($hero ? ' kadisha-hero--has-image' : '') . '" aria-labelledby="lexicon-hero-title"'
        . ($hero ? ' style="' . e("background-image:url('$hero'); --ka-hero-overlay:{$p['hero_overlay']};") . '"' : '') . '>'
        . '<div class="kadisha-container"><div class="kadisha-hero-inner">'
        . '<h1 id="lexicon-hero-title" class="kadisha-hero-title">' . e($p['title_he']) . '</h1>';
    if ($p['subtitle_main_he'] !== '') $o .= '<p class="kadisha-hero-subtitle-main">' . e($p['subtitle_main_he']) . '</p>';
    if ($p['title_en'] !== '' || $p['title_fr'] !== '') {
        $o .= '<span class="kadisha-hero-eyebrow kadisha-hero-eyebrow--under" dir="ltr">'
            . ($p['title_fr'] !== '' ? '<span class="kadisha-hero-eyebrow-line">' . e($p['title_fr']) . '</span>' : '')
            . ($p['title_en'] !== '' ? '<span class="kadisha-hero-eyebrow-line">' . e($p['title_en']) . '</span>' : '') . '</span>';
    }
    if ($p['subtitle_he'] !== '') $o .= '<p class="kadisha-hero-subtitle">' . e($p['subtitle_he']) . '</p>';
    if ($p['credit_he'] !== '') $o .= '<p class="kadisha-hero-subtitle kadisha-hero-credit">' . e($p['credit_he']) . '</p>';
    if ($gates) {
        $o .= '<div class="kadisha-lex-hero-actions">';
        foreach ($gates as $i => $g) {
            $o .= "<button type=\"button\" class=\"kadisha-lex-hero-btn kadisha-lex-gate\" data-panel=\"kadisha-lex-panel-$i\" aria-expanded=\"false\" aria-controls=\"kadisha-lex-panel-$i\">"
                . '<span class="kadisha-lex-hero-btn-icon" aria-hidden="true">📖</span><span class="kadisha-lex-hero-btn-text">' . e($g['label']) . '</span></button>';
        }
        $o .= '</div>';
    }
    $o .= '</div></div></section>';

    $o .= '<section class="kadisha-section" id="lexicon-main"><div class="kadisha-container">';
    $iw = $p['intro_max_width'] > 0 ? "max-width:{$p['intro_max_width']}px;" : '';
    foreach ($gates as $i => $g) {
        $o .= "<div class=\"kadisha-lexicon-intro kadisha-lex-section-panel\" id=\"kadisha-lex-panel-$i\" style=\"$iw\" hidden><div class=\"kadisha-lexicon-intro-teaser\">" . $g['html'] . '</div>';
        if ($i === 0 && isset($gates[1])) {
            $o .= '<button type="button" class="kadisha-lexicon-intro-toggle kadisha-lex-gate" data-panel="kadisha-lex-panel-1" aria-expanded="false" aria-controls="kadisha-lex-panel-1"'
                . ' data-more-text="' . e($p['more_label']) . '" data-less-text="' . e($p['less_label']) . '">' . e($p['more_label']) . ' <span aria-hidden="true">↓</span></button>';
        }
        $o .= '</div>';
    }
    if ($p['feedback']) $o .= LEX_FEEDBACK_MODAL;

    $o .= '<div class="kadisha-lexicon-controls" id="kadisha-lex-panel-search" role="search"><label for="kadisha-lex-search" class="screen-reader-text">חיפוש מילה</label>'
        . '<div class="kadisha-lexicon-search-wrap"><input id="kadisha-lex-search" type="search" autocomplete="off" placeholder="חפש מילה..." class="kadisha-lexicon-search">'
        . '<span class="kadisha-lexicon-search-icon" aria-hidden="true">🔎</span></div>'
        . '<label class="kadisha-lex-scope"><input type="checkbox" id="kadisha-lex-scope-full"><span>הרחב את החיפוש גם לפירוש ולנושאים</span></label>';
    if ($topics) {
        $o .= '<div class="kadisha-lexicon-topic-filter" role="group" aria-label="סינון לפי נושא"><button type="button" class="kadisha-lex-topic-btn is-active" data-topic="">הכל</button>';
        foreach ($topics as $t) {
            $o .= '<button type="button" class="kadisha-lex-topic-btn" data-topic="' . e($t['slug']) . '">' . e($t['name']) . ' <span class="kadisha-lex-topic-count">' . $t['count'] . '</span></button>';
        }
        $o .= '</div>';
    }
    $n = count($words);
    $o .= "<div class=\"kadisha-lexicon-count\" aria-live=\"polite\"><span id=\"kadisha-lex-shown\">$n</span> מתוך <span id=\"kadisha-lex-total\">$n</span> מילים</div></div>";
    if (!$words) $o .= '<p class="kadisha-lexicon-empty">המאגר עוד ריק. מילים יתווספו בהקדם.</p>';

    $o .= '<div class="kadisha-lexicon-grid" id="kadisha-lex-grid">';
    foreach ($editor ? array_slice($words, 0, LEX_PREVIEW_CARDS) : $words as $x) {
        $o .= lexicon_card($x, $map, $p, $x['audio'] === '' ? '' : (file_id($x['audio']) ? $audioUrl($x['audio']) : $x['audio']));
    }
    $o .= '</div>';
    if ($editor && $n > LEX_PREVIEW_CARDS) {
        $o .= '<p class="kol-missing">כאן בתצוגה — ' . LEX_PREVIEW_CARDS . ' המילים הראשונות. באתר יופיעו כל ' . $n . ' המילים. עריכת המילים: 📚 מילים</p>';
    }
    $o .= '<p class="kadisha-lexicon-empty" id="kadisha-lex-no-matches" style="display:none;">לא נמצאו מילים להתאמת החיפוש.</p></div></section></div>';
    return [$o, $css];
}

function lexicon_card(array $w, array &$map, array $p, string $audio): string
{
    $low = fn(string $t) => mb_strtolower(html_entity_decode($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'UTF-8');
    $plain = trim(preg_replace('/\s+/u', ' ', strip_tags(str_replace(['</p>', '<br>'], [' </p>', ' <br>'], $w['meaning']))));
    $slugs = array_map(fn($t) => topic_slug($t, $map), $w['topics']);
    $id = $w['wp_id'] ?? 'k' . $w['id'];
    $o = '<article class="kadisha-word-card" data-id="' . e((string)$id) . '" data-search="' . e($low($w['word'])) . '" data-search-full="'
        . e($low($w['word'] . ' ' . $plain . ' ' . implode(' ', $w['topics']))) . '" data-topics="' . e(implode(' ', $slugs)) . '">'
        . '<header class="kadisha-word-head"><h3 class="kadisha-word-title">' . e($w['word']) . '</h3>';
    if ($p['show_ids'] && $w['external_id'] !== '') $o .= '<span class="kadisha-word-extid" title="ID מהאקסל (זמני לפיתוח)">#' . e($w['external_id']) . '</span>';
    $o .= '<div class="kadisha-word-actions">';
    if ($audio !== '') {
        $o .= '<button type="button" class="kadisha-word-audio-btn" data-audio-src="' . e($audio) . '" aria-label="השמע את המילה ' . e($w['word']) . '" title="השמע">'
            . '<span class="kadisha-word-audio-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span></button>';
    }
    $o .= '</div></header>';
    if ($w['topics']) {
        $o .= '<div class="kadisha-word-topics' . (count($w['topics']) === 1 ? ' is-single' : '') . '">';
        foreach ($w['topics'] as $t) $o .= '<span class="kadisha-word-topic">' . e($t) . '</span>';
        $o .= '</div>';
    }
    $o .= '<div class="kadisha-word-meaning">' . $w['meaning'] . '</div>';
    // משוב — נשלח למייל דרך האתר, ומזוהה שם לפי מספר הרשומה בוורדפרס; למילה שנוספה כאן אין כזה
    if ($p['feedback'] && $w['wp_id']) {
        $o .= '<footer class="kadisha-word-footer"><button type="button" class="kadisha-word-feedback-btn" data-word-id="' . (int)$w['wp_id'] . '" data-word-title="' . e($w['word'])
            . '" aria-label="שלח משוב על המילה ' . e($w['word']) . '"><span aria-hidden="true">💬</span><span class="kadisha-word-feedback-label">שלח משוב</span></button></footer>';
    }
    return $o . '</article>';
}

const LEX_FEEDBACK_MODAL = '<div class="kadisha-lex-feedback-modal" id="kadisha-lex-feedback-modal" hidden role="dialog" aria-modal="true" aria-labelledby="kadisha-lex-feedback-title">'
    . '<div class="kadisha-lex-feedback-backdrop" data-klf-close="1"></div><div class="kadisha-lex-feedback-window"><header class="kadisha-lex-feedback-head">'
    . '<h2 id="kadisha-lex-feedback-title">שלח משוב על המילה</h2><button type="button" class="kadisha-lex-feedback-close" data-klf-close="1" aria-label="סגור">×</button></header>'
    . '<form class="kadisha-lex-feedback-form" id="kadisha-lex-feedback-form" novalidate><div class="kadisha-lex-feedback-word"><span class="kadisha-lex-feedback-word-label">המילה:</span>'
    . '<strong id="kadisha-lex-feedback-word-name"></strong></div>'
    . '<label class="kadisha-lex-feedback-field"><span>שם (אופציונלי)</span><input type="text" name="name" id="kadisha-lex-feedback-name" autocomplete="name" maxlength="120"></label>'
    . '<label class="kadisha-lex-feedback-field"><span>אימייל (אופציונלי — אם תרצו תשובה)</span><input type="email" name="email" id="kadisha-lex-feedback-email" autocomplete="email" dir="ltr" maxlength="160"></label>'
    . '<label class="kadisha-lex-feedback-field"><span>ההערה / התיקון / התוספת <em>*</em></span><textarea name="message" id="kadisha-lex-feedback-message" rows="5" required minlength="3" maxlength="3000"'
    . ' placeholder="כתבו כאן הערה לתיקון או תוספת על המילה..."></textarea></label>'
    . '<div class="kadisha-lex-feedback-honeypot" aria-hidden="true"><label>אל תמלאו שדה זה<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
    . '<footer class="kadisha-lex-feedback-foot"><button type="button" class="kadisha-lex-feedback-cancel" data-klf-close="1">ביטול</button>'
    . '<button type="submit" class="kadisha-lex-feedback-send" id="kadisha-lex-feedback-send"><span aria-hidden="true">📨</span> שלח משוב</button>'
    . '<span class="kadisha-lex-feedback-status" id="kadisha-lex-feedback-status" aria-live="polite"></span></footer></form></div></div>';

// ── ההקלטות מהאתר → עותק בכלי ─────────────────────────────

/**
 * קובץ הקלטות מהתוסף (1.3, "ייצוא ההקלטות", חלק אחד מכמה): audio.json + הקבצים. כל הקלטה נכנסת לספרייה (עם origin —
 * הכתובת שלה באתר), ונקשרת לכל מילה שהצביעה על הכתובת הזו. קובץ שכבר הועתק — לא נכנס פעמיים.
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
    $pdo = db();
    $st = ['part' => (int)($j['part'] ?? 0), 'parts' => (int)($j['parts'] ?? 0), 'linked' => 0, 'files' => 0, 'already' => 0, 'unmatched' => 0, 'failed' => []];
    $words = $pdo->prepare('SELECT word FROM words WHERE audio = ?');
    $copy = $pdo->prepare('SELECT id FROM files WHERE origin = ?');
    $link = $pdo->prepare('UPDATE words SET audio = ? WHERE audio = ?');
    $dir = files_dir() . '/tmp';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    foreach ($j['files'] as $f) {
        $url = lex_url($f['url'] ?? '');
        $entry = (string)($f['file'] ?? '');
        if ($url === '') { $st['failed'][] = (string)($f['word'] ?? '?') . ' — כתובת לא מהאתר'; continue; }
        $words->execute([$url]);
        $ws = $words->fetchAll(PDO::FETCH_COLUMN);
        $copy->execute([$url]);
        $fid = $copy->fetchColumn();
        if (!$ws) { $fid ? $st['already']++ : $st['unmatched']++; continue; }
        if (!$fid) {
            $stat = $zip->statName($entry);
            if (!str_starts_with($entry, 'audio/') || str_contains($entry, '..') || !$stat || $stat['size'] > FILE_MAX) {
                $st['failed'][] = $ws[0] . ' — הקובץ חסר או גדול מ-50MB';
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
                $fid = file_store($tmp, mb_substr($ws[0], 0, 80) . '.' . $ext, $owner, 'audio/');
            } catch (ApiError $e) {
                $st['failed'][] = $ws[0] . ' — ' . $e->getMessage();
                continue;
            }
            $pdo->prepare('UPDATE files SET origin = ? WHERE id = ?')->execute([$url, $fid]);
            $st['files']++;
        }
        $link->execute([$fid, $url]);
        $st['linked'] += count($ws);
    }
    $zip->close();
    $pdo->prepare('DELETE FROM uploads WHERE id = ?')->execute([$u['id']]);
    @unlink($path);
    $st['remaining'] = (int)$pdo->query("SELECT COUNT(*) FROM words WHERE audio LIKE 'https://%'")->fetchColumn();
    audit_as($owner, 'audio_import', "חלק {$st['part']} מתוך {$st['parts']} · {$st['files']} הקלטות");
    return ['stats' => $st];
}

// ── אקסל (CSV) ────────────────────────────────────────────

const CSV_COLS = ['id' => 'מזהה', 'word' => 'מילה', 'meaning' => 'פירוש', 'topics' => 'נושאים', 'audio' => 'הקלטה', 'external_id' => 'מספר מהאקסל'];

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

function words_csv(): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kol-maroko-words-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                    // אקסל מזהה כך שזה UTF-8 (אחרת — ג'יבריש)
    fputcsv($out, array_values(CSV_COLS), ',', '"', '');
    foreach (words_rows() as $w) {
        fputcsv($out, [$w['id'], $w['word'], lex_plain($w['meaning']), implode('; ', $w['topics']), $w['audio'] === '' ? '' : 'יש', $w['external_id']], ',', '"', '');
    }
    fclose($out);
    exit;
}

/**
 * חזרה מאקסל: שורה עם מזהה — עדכון המילה (רק מה שהשתנה; פירוש שלא שונה שומר את העיצוב שלו), בלי מזהה — מילה
 * חדשה. שורה שנמחקה מהקובץ — לא נמחקת כאן (מחיקה רק במסך). ההקלטות לא משתנות מהקובץ.
 */
function words_csv_import(array $user, array $b): array
{
    $csv = (string)($b['csv'] ?? '');
    if (strlen($csv) > 20 * 1024 * 1024) throw new ApiError('הקובץ גדול מדי');
    if (!mb_check_encoding($csv, 'UTF-8')) $csv = (string)mb_convert_encoding($csv, 'UTF-8', 'Windows-1255');   // אקסל שומר "CSV" רגיל ב-ANSI
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $first = strtok($csv, "\n");
    $sep = array_search(max($c = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")]), $c, true);
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, $csv);
    rewind($fh);
    $head = array_map(fn($h) => trim((string)$h), fgetcsv($fh, null, $sep, '"', '') ?: []);
    $col = [];
    foreach (CSV_COLS as $k => $label) if (($i = array_search($label, $head, true)) !== false) $col[$k] = $i;
    if (!isset($col['word'])) throw new ApiError('לא מצאתי עמודה "מילה" בשורה הראשונה. כדאי להתחיל מקובץ שיוצא מכאן (⬇️ אקסל)');
    $cur = [];
    foreach (words_rows() as $w) $cur[$w['id']] = $w;
    $st = ['updated' => 0, 'added' => 0, 'same' => 0, 'errors' => []];
    $pdo = db();
    $upd = $pdo->prepare('UPDATE words SET word = ?, meaning = ?, topics = ?, external_id = ?, updated_at = ?, updated_by = ? WHERE id = ?');
    $ins = $pdo->prepare('INSERT INTO words (word, meaning, topics, external_id, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?)');
    $addTopic = $pdo->prepare('INSERT OR IGNORE INTO topics (slug, name) VALUES (?, ?)');
    $map = topic_slugs();
    $pdo->beginTransaction();
    for ($n = 2; ($r = fgetcsv($fh, null, $sep, '"', '')) !== false; $n++) {
        $get = fn($k) => isset($col[$k]) ? trim((string)($r[$col[$k]] ?? '')) : null;
        if (trim(implode('', $r)) === '') continue;
        $id = (int)$get('id');
        $old = $cur[$id] ?? null;
        $plain = $get('meaning');
        $meaning = $plain === null ? ($old['meaning'] ?? '') : ($old && lex_plain($old['meaning']) === lex_plain(lex_from_plain($plain)) ? $old['meaning'] : lex_from_plain($plain));
        $topics = $get('topics') === null ? ($old['topics'] ?? []) : preg_split('/\s*[;,،]\s*/u', $get('topics'), -1, PREG_SPLIT_NO_EMPTY);
        try {
            $in = word_input(['word' => $get('word'), 'meaning' => $meaning, 'topics' => $topics, 'external_id' => $get('external_id') ?? ($old['external_id'] ?? '')]);
        } catch (ApiError $e) {
            $st['errors'][] = "שורה $n: " . $e->getMessage();
            continue;
        }
        $json = json_encode($in['topics'], JSON_UNESCAPED_UNICODE);
        foreach ($in['topics'] as $t) $addTopic->execute([topic_slug($t, $map), $t]);
        if ($old) {
            if ($old['word'] === $in['word'] && $old['meaning'] === $in['meaning'] && $old['topics'] === $in['topics'] && $old['external_id'] === $in['external_id']) { $st['same']++; continue; }
            $upd->execute([$in['word'], $in['meaning'], $json, $in['external_id'], now_iso(), $user['name'], $id]);
            $st['updated']++;
        } else {
            $ins->execute([$in['word'], $in['meaning'], $json, $in['external_id'], now_iso(), $user['name']]);
            $st['added']++;
        }
    }
    $pdo->commit();
    audit_as($user, 'words_csv', "{$st['updated']} עודכנו · {$st['added']} נוספו");
    return ['stats' => $st] + words_list();
}
