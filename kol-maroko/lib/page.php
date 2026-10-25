<?php
/**
 * מודל העמוד (docs/spec.md, פרק 3): ניקוי, סינון טקסט עשיר, ורינדור ל-HTML + CSS.
 *
 * מקור אחד: אותו רינדור משמש לתצוגה בסטודיו (עם data-kid לבחירה) ולפרסום (בלעדיו). כל מה שמגיע מהדפדפן
 * עובר page_normalize — מבנה, סוגים וגבולות — ולכן הרינדור סומך רק על מה שיצא משם.
 */

declare(strict_types=1);

require_once __DIR__ . '/files.php';

const PAGE_MAX_BYTES = 1_000_000;
const PAGE_MAX_SECTIONS = 120;
const PAGE_MAX_WIDGETS = 120;          // בעמודה
const TEXT_MAX = 60_000;
const WIDGET_TYPES = ['heading', 'text', 'longtext', 'button', 'divider', 'spacer', 'image', 'gallery', 'audio', 'video', 'file'];
const COL_LAYOUTS = ['100', '50-50', '33-67', '67-33', '33-33-33', '25-25-25-25'];
const SIZES = ['sm' => '.875em', 'md' => '1em', 'lg' => '1.25em', 'xl' => '1.6em', 'xxl' => '2.2em'];
const PADS = ['0' => '0', 's' => '8px', 'm' => '16px', 'l' => '32px', 'xl' => '64px'];
const FONTS = ['theme' => 'inherit', 'sans' => 'system-ui, "Segoe UI", Arial, sans-serif', 'serif' => '"Frank Ruhl Libre", "David", Georgia, serif'];

// ── עזרים ──────────────────────────────────────────────────

function kid(): string
{
    return substr(bin2hex(random_bytes(5)), 0, 8);
}

function pick(mixed $v, array $allowed, string $default): string
{
    return is_string($v) && in_array($v, $allowed, true) ? $v : $default;
}

function color(mixed $v): string
{
    return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : '';
}

function str(mixed $v, int $max): string
{
    return is_scalar($v) ? mb_substr(trim((string)$v), 0, $max) : '';
}

/** קישור מותר: https, mailto, tel, או עוגן בעמוד. אחרת — ריק. */
function safe_href(mixed $v): string
{
    $v = str($v, 2000);
    if ($v === '') return '';
    if (preg_match('~^(https://[^\s<>"]+|mailto:[^\s<>"]+|tel:[+0-9\-\s]+|#[A-Za-z0-9_-]+)$~i', $v)) return $v;
    return '';
}

function file_id(mixed $v): string
{
    return is_string($v) && preg_match('/^[0-9a-f]{32}$/', $v) ? $v : '';
}

/** קישור יוטיוב / וימאו → "youtube:<id>" / "vimeo:<id>". אחרת — ריק. */
function video_embed(mixed $v): string
{
    $v = str($v, 500);
    if (preg_match('/^(youtube:[A-Za-z0-9_-]{11}|vimeo:\d{5,12})$/', $v)) return $v;
    if (preg_match('~^https://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $v, $m)) return 'youtube:' . $m[1];
    if (preg_match('~^https://(?:www\.|player\.)?vimeo\.com/(?:video/)?(\d{5,12})~', $v, $m)) return 'vimeo:' . $m[1];
    return '';
}

// ── טקסט עשיר: allowlist ───────────────────────────────────

const RICH_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'a', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'span', 'div'];
const RICH_DROP = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript', 'form', 'input', 'textarea', 'select', 'button', 'link', 'meta', 'head', 'title'];

/** מסנן HTML לטקסט עשיר: תגיות מהרשימה, href בטוח, dir. כל השאר — נפרש (הטקסט נשאר) או נמחק. */
function sanitize_rich(string $html): string
{
    $html = mb_substr($html, 0, TEXT_MAX);
    if (trim($html) === '') return '';
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><body><div id="kol-root">' . $html . '</div></body>', LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('kol-root');
    if (!$root) return '';
    rich_clean($root);
    $out = '';
    foreach ($root->childNodes as $n) $out .= $doc->saveHTML($n);
    return trim($out);
}

function rich_clean(DOMNode $node): void
{
    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child instanceof DOMText) continue;
        if (!$child instanceof DOMElement) { $node->removeChild($child); continue; }  // הערות, PI
        $tag = strtolower($child->tagName);
        if (in_array($tag, RICH_DROP, true)) { $node->removeChild($child); continue; }
        rich_clean($child);
        if (!in_array($tag, RICH_TAGS, true)) {                                      // לא מוכר — הטקסט נשאר
            while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
            $node->removeChild($child);
            continue;
        }
        $href = $tag === 'a' ? safe_href($child->getAttribute('href')) : '';
        $dir = pick($child->getAttribute('dir'), ['rtl', 'ltr', 'auto'], '');
        foreach (iterator_to_array($child->attributes) as $a) $child->removeAttribute($a->name);
        if ($dir) $child->setAttribute('dir', $dir);
        if ($tag === 'a') {
            if ($href === '') {
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            $child->setAttribute('href', $href);
            if (str_starts_with($href, 'https://')) $child->setAttribute('rel', 'noopener');
        }
    }
}

// ── ניקוי המבנה ───────────────────────────────────────────

function style_norm(mixed $s): array
{
    $s = is_array($s) ? $s : [];
    return array_filter([
        'align' => pick($s['align'] ?? '', ['start', 'center', 'end'], ''),
        'color' => color($s['color'] ?? ''),
        'bg' => color($s['bg'] ?? ''),
        'size' => pick($s['size'] ?? '', array_keys(SIZES), ''),
        'pad' => pick($s['pad'] ?? '', array_map('strval', array_keys(PADS)), ''),
        'hide_mobile' => ($s['hide_mobile'] ?? false) === true,
        'hide_desktop' => ($s['hide_desktop'] ?? false) === true,
    ], fn($v) => $v !== '' && $v !== false);
}

function id_norm(mixed $id, array &$seen): string
{
    $id = is_string($id) && preg_match('/^[a-z0-9]{8}$/', $id) && !isset($seen[$id]) ? $id : kid();
    $seen[$id] = true;
    return $id;
}

function widget_norm(mixed $w, array &$seen): ?array
{
    if (!is_array($w) || !in_array($w['type'] ?? null, WIDGET_TYPES, true)) return null;
    $p = is_array($w['props'] ?? null) ? $w['props'] : [];
    $props = match ($w['type']) {
        'heading' => ['text' => str($p['text'] ?? '', 500), 'level' => (int)pick((string)($p['level'] ?? '2'), ['1', '2', '3', '4'], '2')],
        'text' => ['html' => sanitize_rich((string)($p['html'] ?? ''))],
        'button' => ['label' => str($p['label'] ?? '', 120), 'href' => safe_href($p['href'] ?? ''), 'variant' => pick($p['variant'] ?? '', ['solid', 'outline'], 'solid')],
        'divider' => [],
        'spacer' => ['height' => max(4, min(400, (int)($p['height'] ?? 32)))],
        'image' => ['file' => file_id($p['file'] ?? ''), 'alt' => str($p['alt'] ?? '', 300), 'caption' => str($p['caption'] ?? '', 500),
                    'href' => safe_href($p['href'] ?? ''), 'width' => pick($p['width'] ?? '', ['auto', 'full'], 'auto')],
        'gallery' => ['files' => array_values(array_filter(array_map('file_id', array_slice(is_array($p['files'] ?? null) ? $p['files'] : [], 0, 60)))),
                      'cols' => (int)pick((string)($p['cols'] ?? '3'), ['2', '3', '4'], '3')],
        'audio' => ['file' => file_id($p['file'] ?? ''), 'title' => str($p['title'] ?? '', 200)],
        'video' => ['file' => file_id($p['file'] ?? ''), 'embed' => video_embed($p['embed'] ?? '')],
        'file' => ['file' => file_id($p['file'] ?? ''), 'label' => str($p['label'] ?? '', 200)],
        'longtext' => ['doc' => is_string($p['doc'] ?? null) && preg_match('/^[A-Za-z0-9:_-]{1,40}$/', $p['doc']) ? $p['doc'] : ''],
    };
    return ['id' => id_norm($w['id'] ?? null, $seen), 'type' => $w['type'], 'props' => $props, 'style' => style_norm($w['style'] ?? [])];
}

function page_normalize(mixed $page): array
{
    if (!is_array($page)) throw new ApiError('מבנה העמוד לא תקין');
    if (strlen((string)json_encode($page)) > PAGE_MAX_BYTES) throw new ApiError('העמוד גדול מדי');
    $seen = [];
    $st = is_array($page['settings'] ?? null) ? $page['settings'] : [];
    $settings = [
        'title' => str($st['title'] ?? '', 200),
        'width' => pick($st['width'] ?? '', ['boxed', 'full'], 'boxed'),
        'max_width' => max(600, min(1600, (int)($st['max_width'] ?? 1140))),
        'font' => pick($st['font'] ?? '', array_keys(FONTS), 'theme'),
        'text' => color($st['text'] ?? ''),
        'bg' => color($st['bg'] ?? ''),
        'accent' => color($st['accent'] ?? '') ?: '#a3322a',
    ];
    $sections = [];
    foreach (array_slice(is_array($page['sections'] ?? null) ? $page['sections'] : [], 0, PAGE_MAX_SECTIONS) as $s) {
        if (!is_array($s)) continue;
        $layout = pick($s['layout'] ?? '', COL_LAYOUTS, '100');
        $n = count(explode('-', $layout));
        $cols = [];
        $in = is_array($s['columns'] ?? null) ? array_values($s['columns']) : [];
        for ($i = 0; $i < $n; $i++) {
            $c = is_array($in[$i] ?? null) ? $in[$i] : [];
            $ws = [];
            foreach (array_slice(is_array($c['widgets'] ?? null) ? $c['widgets'] : [], 0, PAGE_MAX_WIDGETS) as $w) {
                if ($nw = widget_norm($w, $seen)) $ws[] = $nw;
            }
            $cols[] = ['id' => id_norm($c['id'] ?? null, $seen), 'widgets' => $ws];
        }
        // עמודות שנעלמו בהקטנת הפריסה — הרכיבים שלהן עוברים לעמודה האחרונה, לא נמחקים
        for ($i = $n; $i < count($in); $i++) {
            foreach (is_array($in[$i]['widgets'] ?? null) ? $in[$i]['widgets'] : [] as $w) {
                if ($nw = widget_norm($w, $seen)) $cols[$n - 1]['widgets'][] = $nw;
            }
        }
        $sections[] = [
            'id' => id_norm($s['id'] ?? null, $seen),
            'layout' => $layout,
            'bg' => color($s['bg'] ?? ''),
            'pad' => pick($s['pad'] ?? '', array_map('strval', array_keys(PADS)), 'l'),
            'full' => ($s['full'] ?? false) === true,
            'columns' => $cols,
        ];
    }
    return ['settings' => $settings, 'sections' => $sections];
}

function page_template(): array
{
    return page_normalize([
        'settings' => ['title' => 'קול מרוקו'],
        'sections' => [
            ['layout' => '100', 'pad' => 'xl', 'columns' => [['widgets' => [
                ['type' => 'heading', 'props' => ['text' => 'קול מרוקו', 'level' => 1], 'style' => ['align' => 'center', 'size' => 'xxl']],
                ['type' => 'text', 'props' => ['html' => '<p>כאן יבוא משפט פתיחה על הלקסיקון.</p>'], 'style' => ['align' => 'center', 'size' => 'lg']],
            ]]]],
            ['layout' => '50-50', 'pad' => 'l', 'columns' => [
                ['widgets' => [['type' => 'heading', 'props' => ['text' => 'כותרת משנה', 'level' => 2]], ['type' => 'text', 'props' => ['html' => '<p>טקסט לדוגמה. לחיצה עליו פותחת את העריכה.</p>']]]],
                ['widgets' => [['type' => 'heading', 'props' => ['text' => 'עוד כותרת', 'level' => 2]], ['type' => 'text', 'props' => ['html' => '<p>עוד טקסט לדוגמה.</p>']]]],
            ]],
        ],
    ]);
}

// ── רינדור ────────────────────────────────────────────────

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * HTML + CSS של העמוד. $editor — עם data-kid (הבחירה בסטודיו). כל בורר ב-CSS מתחיל ב-.kol-page, כדי
 * לא לדרוס את ערכת הנושא של האתר ולהפך.
 */
function page_render(array $page, bool $editor = false, ?callable $url = null): array
{
    preg_match_all('/"([0-9a-f]{32})"/', json_encode($page), $m);
    $GLOBALS['kol_files'] = files_meta(array_unique($m[1]));
    $GLOBALS['kol_url'] = $url ?? fn(array $f) => media_url($f['id']);
    $docs = [];
    foreach ($page['sections'] as $s) foreach ($s['columns'] as $c) foreach ($c['widgets'] as $w) {
        if ($w['type'] === 'longtext' && $w['props']['doc'] !== '') $docs[] = $w['props']['doc'];
    }
    $GLOBALS['kol_long'] = function_exists('longtexts_get') ? longtexts_get(array_unique($docs)) : [];
    $st = $page['settings'];
    $css = [];
    $P = '.kol-page';
    $css[] = "$P{direction:rtl;font-family:" . FONTS[$st['font']] . ';line-height:1.6;' . ($st['text'] ? "color:{$st['text']};" : '') . ($st['bg'] ? "background:{$st['bg']};" : '') . '}';
    $css[] = "$P *{box-sizing:border-box}";
    $css[] = "$P .kol-inner{max-width:{$st['max_width']}px;margin:0 auto;padding:0 16px}";
    $css[] = "$P .kol-row{display:flex;gap:24px}";
    $css[] = "$P .kol-col{min-width:0}";
    $css[] = "$P .kol-w{margin:0 0 16px}";
    $css[] = "$P .kol-w:last-child{margin-bottom:0}";
    $css[] = "$P h1.kol-w,$P h2.kol-w,$P h3.kol-w,$P h4.kol-w{line-height:1.25}";
    $css[] = "$P .kol-text>:first-child{margin-top:0}$P .kol-text>:last-child{margin-bottom:0}";
    $css[] = "$P .kol-text a{color:{$st['accent']}}";
    $css[] = "$P .kol-btn{display:inline-block;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:600;border:2px solid {$st['accent']}}";
    $css[] = "$P .kol-btn-solid{background:{$st['accent']};color:#fff}";
    $css[] = "$P .kol-btn-outline{background:transparent;color:{$st['accent']}}";
    $css[] = "$P hr.kol-w{border:0;border-top:1px solid currentColor;opacity:.25}";
    $css[] = "$P .kol-img{margin-inline:0}$P .kol-img img{display:inline-block;max-width:100%;height:auto;vertical-align:top;border-radius:4px}";
    $css[] = "$P .kol-img.kol-full img{width:100%}$P figcaption{font-size:.875em;opacity:.75;margin-top:6px}";
    $css[] = "$P .kol-gal{display:grid;grid-template-columns:repeat(var(--cols,3),minmax(0,1fr));gap:8px}";
    $css[] = "$P .kol-gal img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:4px;display:block}";
    $css[] = "$P .kol-audio audio,$P .kol-video video{width:100%;display:block}$P .kol-audio-t{font-weight:600;margin-bottom:6px}";
    $css[] = "$P .kol-video video{border-radius:4px;background:#000;max-height:80vh}";
    $css[] = "$P .kol-embed{position:relative;aspect-ratio:16/9;border-radius:4px;overflow:hidden;background:#000}$P .kol-embed iframe,$P .kol-embed img{position:absolute;inset:0;width:100%;height:100%;border:0;object-fit:cover}";
    $css[] = "$P .kol-play{position:absolute;inset:0;display:grid;place-items:center;font-size:48px;color:#fff;text-shadow:0 2px 8px #000}";
    $css[] = "$P .kol-filelink{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid currentColor;border-radius:8px;text-decoration:none;color:inherit}";
    $css[] = "$P .kol-filelink small{opacity:.7}$P .kol-missing{padding:24px;border:1px dashed #aaa;border-radius:6px;text-align:center;opacity:.6}";
    $css[] = "@media (max-width:767px){{$P} .kol-gal{grid-template-columns:repeat(2,minmax(0,1fr))}}";
    $css[] = "@media (max-width:767px){{$P} .kol-row{flex-direction:column;gap:16px}{$P} .kol-col{flex-basis:auto!important}{$P} .kol-hm{display:none!important}}";
    $css[] = "@media (min-width:768px){{$P} .kol-hd{display:none!important}}";

    $kid = fn(string $id) => $editor ? ' data-kid="' . $id . '"' : '';
    $html = '<div class="kol-page">';
    foreach ($page['sections'] as $s) {
        $cls = "kol-s-{$s['id']}";
        $css[] = "$P .$cls{padding:" . PADS[$s['pad']] . ' 0;' . ($s['bg'] ? "background:{$s['bg']};" : '') . '}';
        $html .= "<section class=\"kol-sec $cls\"{$kid($s['id'])}><div class=\"" . ($s['full'] ? 'kol-full' : 'kol-inner') . '"><div class="kol-row">';
        $widths = explode('-', $s['layout']);
        foreach ($s['columns'] as $i => $c) {
            $html .= "<div class=\"kol-col\" style=\"flex:0 0 calc({$widths[$i]}% - " . (24 * (count($widths) - 1) / count($widths)) . "px)\"{$kid($c['id'])}>";
            foreach ($c['widgets'] as $w) {
                [$wh, $wc] = widget_render($w, $editor);
                $html .= $wh;
                if ($wc) $css[] = $wc;
            }
            $html .= '</div>';
        }
        $html .= '</div></div></section>';
    }
    $html .= '</div>';
    // ה-CSS של כל מסמך מהעורך — ממוקד ב-.kol-lt-<מזהה> (שני מסמכים עם הגדרות שונות לא מתנגשים)
    foreach ($GLOBALS['kol_long'] as $doc => $lt) {
        $scope = "$P .kol-lt-" . substr(md5($doc), 0, 10) . ' ';
        $css[] = preg_replace('/(^|[},])\s*\.wp-doc/', '$1' . $scope . '.wp-doc', $lt['css']);
    }
    $css[] = "$P .kol-long .wp-doc{color:inherit;max-width:100%}$P .kol-long img{max-width:100%}";
    return ['html' => $html, 'css' => implode("\n", $css)];
}

function widget_render(array $w, bool $editor): array
{
    $cls = "kol-w kol-w-{$w['id']}";
    $s = $w['style'];
    if (!empty($s['hide_mobile'])) $cls .= ' kol-hm';
    if (!empty($s['hide_desktop'])) $cls .= ' kol-hd';
    $rules = '';
    if (!empty($s['align'])) $rules .= 'text-align:' . ['start' => 'right', 'center' => 'center', 'end' => 'left'][$s['align']] . ';';
    if (!empty($s['color'])) $rules .= "color:{$s['color']};";
    if (!empty($s['bg'])) $rules .= "background:{$s['bg']};";
    if (!empty($s['size'])) $rules .= 'font-size:' . SIZES[$s['size']] . ';';
    if (isset($s['pad'])) $rules .= 'padding:' . PADS[$s['pad']] . ';';
    if ($w['type'] === 'spacer') $rules .= "height:{$w['props']['height']}px;";
    $css = $rules ? ".kol-page .kol-w-{$w['id']}{" . $rules . '}' : '';
    $k = $editor ? ' data-kid="' . $w['id'] . '"' : '';
    $p = $w['props'];
    $f = fn(string $id) => $GLOBALS['kol_files'][$id] ?? null;
    $src = fn(array $file) => e(($GLOBALS['kol_url'])($file));
    $missing = fn(string $what) => $editor ? "<div class=\"$cls\"$k><div class=\"kol-missing\">$what</div></div>" : '';
    if ($w['type'] === 'gallery' && $p['cols'] !== 3) $css .= ".kol-page .kol-w-{$w['id']}{--cols:{$p['cols']}}";
    $html = match ($w['type']) {
        'heading' => "<h{$p['level']} class=\"$cls\"$k>" . (e($p['text']) ?: ($editor ? '<span style="opacity:.4">כותרת</span>' : '')) . "</h{$p['level']}>",
        'text' => "<div class=\"$cls kol-text\"$k>" . ($p['html'] ?: ($editor ? '<p style="opacity:.4">טקסט</p>' : '')) . '</div>',
        'button' => "<div class=\"$cls\"$k><a class=\"kol-btn kol-btn-{$p['variant']}\"" . ($p['href'] ? ' href="' . e($p['href']) . '"' : '') . '>' . e($p['label'] ?: 'כפתור') . '</a></div>',
        'divider' => "<hr class=\"$cls\"$k>",
        'spacer' => "<div class=\"$cls\"$k aria-hidden=\"true\"></div>",
        'image' => ($img = $f($p['file']))
            ? "<figure class=\"$cls kol-img" . ($p['width'] === 'full' ? ' kol-full' : '') . "\"$k>"
              . ($p['href'] ? '<a href="' . e($p['href']) . '">' : '')
              . '<img src="' . $src($img) . '" alt="' . e($p['alt']) . '" width="' . (int)$img['w'] . '" height="' . (int)$img['h'] . '" loading="lazy" decoding="async">'
              . ($p['href'] ? '</a>' : '') . ($p['caption'] !== '' ? '<figcaption>' . e($p['caption']) . '</figcaption>' : '') . '</figure>'
            : $missing('🖼️ בחרו תמונה'),
        'gallery' => ($imgs = array_filter(array_map($f, $p['files'])))
            ? "<div class=\"$cls kol-gal\"$k>" . implode('', array_map(fn($i) => '<img src="' . $src($i) . '" alt="" loading="lazy" decoding="async">', $imgs)) . '</div>'
            : $missing('🖼️ בחרו תמונות לגלריה'),
        'audio' => ($a = $f($p['file']))
            ? "<div class=\"$cls kol-audio\"$k>" . ($p['title'] !== '' ? '<div class="kol-audio-t">' . e($p['title']) . '</div>' : '')
              . '<audio controls preload="metadata" src="' . $src($a) . '"></audio></div>'
            : $missing('🎙️ בחרו הקלטה'),
        'video' => video_render($p, $cls, $k, $f, $src, $editor) ?: $missing('🎬 בחרו וידאו או הדביקו קישור'),
        'longtext' => ($lt = $GLOBALS['kol_long'][$p['doc']] ?? null) && $lt['html'] !== ''
            ? "<div class=\"$cls kol-long kol-lt-" . substr(md5($p['doc']), 0, 10) . "\"$k>" . $lt['html'] . '</div>'
            : $missing($p['doc'] ? '✍️ הטקסט עוד לא נשמר בעורך' : '✍️ פתחו את עורך התמלילים כדי לכתוב'),
        'file' => ($d = $f($p['file']))
            ? "<div class=\"$cls\"$k><a class=\"kol-filelink\" href=\"" . $src($d) . '" download>📄 <span>' . e($p['label'] ?: $d['name']) . '</span> <small>'
              . strtoupper($d['ext']) . ' · ' . size_label((int)$d['size']) . '</small></a></div>'
            : $missing('📄 בחרו קובץ'),
    };
    return [$html, $css];
}

function video_render(array $p, string $cls, string $k, callable $f, callable $src, bool $editor): string
{
    if ($v = $f($p['file'])) {
        return "<div class=\"$cls kol-video\"$k><video controls playsinline preload=\"metadata\" src=\"" . $src($v) . '"></video></div>';
    }
    if ($p['embed'] === '') return '';
    [$prov, $id] = explode(':', $p['embed'], 2);
    // בסטודיו — תמונה במקום נגן: הנגנים לא רצים בתוך התצוגה המבודדת
    if ($editor) {
        $thumb = $prov === 'youtube' ? '<img src="https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg" alt="">' : '';
        return "<div class=\"$cls\"$k><div class=\"kol-embed\">$thumb<span class=\"kol-play\">▶</span></div></div>";
    }
    $url = $prov === 'youtube' ? "https://www.youtube-nocookie.com/embed/$id" : "https://player.vimeo.com/video/$id";
    return "<div class=\"$cls\"><div class=\"kol-embed\"><iframe src=\"$url\" title=\"וידאו\" loading=\"lazy\" allow=\"fullscreen; picture-in-picture\" allowfullscreen referrerpolicy=\"strict-origin-when-cross-origin\"></iframe></div></div>";
}

function size_label(int $b): string
{
    return $b >= 1 << 20 ? round($b / (1 << 20), 1) . 'MB' : max(1, round($b / 1024)) . 'KB';
}
