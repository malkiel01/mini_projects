<?php
/**
 * M6 — הסטודיו הישן, לקריאה בלבד (docs/spec-v2/slices/M6-remove-old-studio.md §2.1, §2.3).
 *
 * הסטודיו הישן (מודל רכיבים בטיוטה, `</> קוד`, פרסום v1, הייבוא וההסבה) הוסר מהממשק ומה-API. הנתונים שלו לא נמחקו:
 * הטבלאות draft, revisions, edit_lock, versions, code_files, code_history ו-longtexts נשארות במסד כמו שהן — אין כאן
 * DROP, אין DELETE ואין מיגרציה. מה שנשאר בקובץ הזה הוא רק מה שצריך כדי לקרוא אותן:
 *   - draft_row() — snap_state() ממשיך לצלם את הטיוטה, כדי שנקודה חדשה תושווה נכון לישנה, ושחזור נקודה ישנה כותב אותה חזרה.
 *   - lock_state() / lock_view() — הנעילה של הסטודיו הישן. אחרי M6 אין מי שלוקח אותה (lock_op נמחק); נעילה שנשארה פגה לבד.
 *   - page_normalize() / page_render() והרכיבים — הרינדור של עמוד בפורמט הישן, בשביל "🗄️ ארכיון" (archive_*).
 *   - העטיפות של עמוד שיובא (לשעבר lib/legacy.php) — חלק מהרינדור.
 * שום פונקציה כאן אינה נקראת מפעולה שכותבת (חוץ מ-draft_row, שיוצרת את השורה אם אין — כמו תמיד).
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/page.php';
require_once __DIR__ . '/code.php';

const LOCK_TTL = 120;
const PAGE_MAX_BYTES = 1_000_000;
const PAGE_MAX_SECTIONS = 120;
const PAGE_MAX_WIDGETS = 120;          // בעמודה
const WIDGET_TYPES = ['heading', 'text', 'longtext', 'button', 'divider', 'spacer', 'image', 'gallery', 'audio', 'video', 'file', 'code', 'raw', 'lexicon'];
const CODE_MAX = 100_000;
const COL_LAYOUTS = ['100', '50-50', '33-67', '67-33', '33-33-33', '25-25-25-25', '20-20-20-20-20', '16-16-16-16-16-16'];
const SIZES = ['sm' => '.875em', 'md' => '1em', 'lg' => '1.25em', 'xl' => '1.6em', 'xxl' => '2.2em'];
const PADS = ['0' => '0', 's' => '8px', 'm' => '16px', 'l' => '32px', 'xl' => '64px'];
const FONTS = ['theme' => 'inherit', 'sans' => 'system-ui, "Segoe UI", Arial, sans-serif', 'serif' => '"Frank Ruhl Libre", "David", Georgia, serif'];

function draft_row(): array
{
    $row = db()->query('SELECT * FROM draft WHERE id = 1')->fetch();
    if ($row) return $row;
    $json = page_json(page_template());
    db()->prepare("INSERT INTO draft (id, json, rev, updated_by, updated_at) VALUES (1, ?, 1, '', ?)")->execute([$json, now_iso()]);
    return db()->query('SELECT * FROM draft WHERE id = 1')->fetch();
}

/** מצב הנעילה של הסטודיו הישן: null — פנויה; אחרת {sub, name, since}. אחרי M6 אין מי שלוקח אותה. */
function lock_state(): ?array
{
    $l = db()->query('SELECT * FROM edit_lock WHERE id = 1')->fetch();
    if (!$l || $l['beat'] < time() - LOCK_TTL) return null;
    return ['sub' => $l['sub'], 'name' => $l['name'], 'since' => gmdate('Y-m-d\TH:i:s\Z', (int)$l['since'])];
}

function lock_view(array $user): array
{
    $l = lock_state();
    return ['mine' => $l && $l['sub'] === $user['sub'], 'by' => $l && $l['sub'] !== $user['sub'] ? $l['name'] : null, 'since' => $l['since'] ?? null];
}

// ── 🗄️ ארכיון: הגרסאות שפורסמו בפורמט הישן (לקריאה בלבד) ──────

/** הרשימה: מתי, מי, הערה, מצב. בלי שום פעולה. */
function archive_list(array $q = []): array
{
    $off = max(0, (int)($q['offset'] ?? 0));
    $rows = db()->query('SELECT n, at, name, note, status FROM versions ORDER BY n DESC LIMIT 50 OFFSET ' . $off)->fetchAll();
    $last = db()->query("SELECT n FROM versions WHERE status = 'live' ORDER BY n DESC LIMIT 1")->fetchColumn();
    return ['versions' => array_map(fn($r) => ['n' => (int)$r['n'], 'at' => $r['at'], 'name' => $r['name'], 'note' => $r['note'], 'status' => $r['status']], $rows),
        'last_live' => $last === false ? null : (int)$last, 'total' => (int)db()->query('SELECT COUNT(*) FROM versions')->fetchColumn(), 'offset' => $off];
}

/** הרינדור של גרסה ישנה: page_render על ה-JSON שלה, עם המילים וקבצי הקוד שנשמרו איתה. */
function archive_render(int $n): array
{
    $st = db()->prepare('SELECT n, at, name, json, words, code FROM versions WHERE n = ?');
    $st->execute([$n]);
    $v = $st->fetch() ?: throw new ApiError('הגרסה לא נמצאה', 404);
    if (($v['words'] ?? null) !== null) {
        $GLOBALS['kol_words'] = json_decode($v['words'], true) ?: [];
        $GLOBALS['kol_code'] = ($v['code'] ?? null) === null ? null : (json_decode($v['code'], true) ?: null);
    }
    try { return ['n' => (int)$v['n'], 'at' => $v['at'], 'name' => $v['name']] + page_render(page_normalize(json_decode($v['json'], true)), true); }
    finally { unset($GLOBALS['kol_words'], $GLOBALS['kol_code']); }
}

/** תצוגה בתוך הכלי (iframe בארגז חול): {html, css, fonts?}. */
function archive_view(array $q): array
{
    return archive_render((int)($q['n'] ?? 0));
}

/** "⬇️ הורד כ-HTML": מסמך אחד, עצמאי, כקובץ להורדה. הקבצים — בכתובות החתומות של הכלי (כמו בתצוגה). */
function archive_html(array $q): never
{
    $r = archive_render((int)($q['n'] ?? 0));
    $doc = "<!doctype html>\n<html lang=\"he\" dir=\"rtl\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . '<title>' . e("קול מרוקו — גרסה {$r['n']} (הפורמט הישן)") . "</title>\n"
        . (!empty($r['fonts']) ? '<link rel="stylesheet" href="' . e($r['fonts']) . "\">\n" : '')
        . '<style>body{margin:0;font-family:system-ui,Arial,sans-serif}' . str_ireplace('</style', '', (string)$r['css']) . "</style>\n</head>\n<body>\n"
        . "<!-- קול מרוקו · גרסה {$r['n']} · " . e((string)$r['at']) . ' · ' . e((string)$r['name']) . " · לקריאה בלבד (🗄️ ארכיון, M6) -->\n"
        . $r['html'] . "\n</body>\n</html>\n";
    header_remove('Content-Type');
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="kol-maroko-v' . $r['n'] . '.html"');
    header('Content-Length: ' . strlen($doc));
    echo $doc;
    exit;
}

// ── מודל העמוד הישן (לשעבר lib/page.php) ─────────────────────

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
        'heading' => ['text' => str($p['text'] ?? '', 500), 'level' => (int)pick((string)($p['level'] ?? '2'), ['1', '2', '3', '4', '5', '6'], '2')],
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
        'code' => ['html' => is_string($p['html'] ?? null) ? mb_substr($p['html'], 0, CODE_MAX) : '', 'title' => str($p['title'] ?? '', 100),
                   'height' => max(40, min(3000, (int)($p['height'] ?? 300))), 'auto' => ($p['auto'] ?? false) === true],
        'raw' => ['html' => sanitize_raw((string)($p['html'] ?? ''))],
        'lexicon' => lexicon_props($p),
        'longtext' => ['doc' => is_string($p['doc'] ?? null) && preg_match('/^[A-Za-z0-9:_-]{1,40}$/', $p['doc']) ? $p['doc'] : ''],
    };
    $out = ['id' => id_norm($w['id'] ?? null, $seen), 'type' => $w['type'], 'props' => $props, 'style' => style_norm($w['style'] ?? [])];
    if ($lg = legacy_widget($w['legacy'] ?? null)) $out['legacy'] = $lg;
    return $out;
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
    if ($lg = legacy_tags($st['legacy'] ?? null)) $settings['legacy'] = $lg;
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
                // מילון אחד בעמוד: המזהים שלו (חיפוש, רשימה) קבועים, כמו בתבנית של האתר
                if (($w['type'] ?? '') === 'lexicon' && isset($seen['lexicon'])) continue;
                if ($nw = widget_norm($w, $seen)) $ws[] = $nw;
                if ($nw && $nw['type'] === 'lexicon') $seen['lexicon'] = true;
            }
            $col = ['id' => id_norm($c['id'] ?? null, $seen), 'widgets' => $ws];
            if (isset($c['legacy']) && is_array($c['legacy'])) $col['legacy'] = legacy_tags($c['legacy']);   // [] — עמודה מדומה (display:contents)
            $cols[] = $col;
        }
        // עמודות שנעלמו בהקטנת הפריסה — הרכיבים שלהן עוברים לעמודה האחרונה, לא נמחקים
        for ($i = $n; $i < count($in); $i++) {
            foreach (is_array($in[$i]['widgets'] ?? null) ? $in[$i]['widgets'] : [] as $w) {
                if ($nw = widget_norm($w, $seen)) $cols[$n - 1]['widgets'][] = $nw;
            }
        }
        $sec = [
            'id' => id_norm($s['id'] ?? null, $seen),
            'layout' => $layout,
            'bg' => color($s['bg'] ?? ''),
            // '' — בלי ריפוד משלנו (פס שיובא: הריפוד מה-CSS של האתר)
            'pad' => pick($s['pad'] ?? 'l', ['', ...array_map('strval', array_keys(PADS))], 'l'),
            'full' => ($s['full'] ?? false) === true,
            'columns' => $cols,
        ];
        if ($lg = legacy_tags($s['legacy'] ?? null)) $sec['legacy'] = $lg;
        $sections[] = $sec;
    }
    return ['settings' => $settings, 'sections' => $sections];
}

/**
 * JSON של עמוד: style ו-props ריקים — אובייקט ({}), לא מערך ([]). בלי זה הדפדפן מקבל מערך, ומאפיינים שנכתבים
 * עליו (style.color…) נעלמים ב-JSON.stringify — שינוי עיצוב לא היה נשמר.
 */
function page_json(array $page): string
{
    foreach ($page['sections'] as &$s) foreach ($s['columns'] as &$c) foreach ($c['widgets'] as &$w) {
        if (!$w['style']) $w['style'] = new stdClass();
        if (!$w['props']) $w['props'] = new stdClass();
    }
    unset($s, $c, $w);
    return json_encode($page, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

/**
 * HTML + CSS של העמוד. $editor — עם data-kid (הבחירה בסטודיו). כל בורר ב-CSS מתחיל ב-.kol-page, כדי
 * לא לדרוס את ערכת הנושא של האתר ולהפך.
 */
function page_render(array $page, bool $editor = false, ?callable $url = null): array
{
    preg_match_all('/(?:"|kol-file:)([0-9a-f]{32})/', json_encode($page) . imported_css(), $m);
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

    // עמודה מיובאת היא display:contents — הרכיבים שלה הם פריטי flex של השורה, ובלי flex-grow מתכווצים לרוחב התוכן
    // (המילון: 717–778px מתוך 1920). כאן הם ממלאים את השורה — בסטודיו ובפרסום (L1, L1b)
    $css[] = "$P .kol-legacy{margin:0}$P .kol-contents{display:contents}$P .kol-row>.kol-contents>*{flex:1 1 auto;min-width:0}$P .kol-raw>:first-child{margin-top:0}";

    $kid = fn(string $id) => $editor ? ' data-kid="' . $id . '"' : '';
    $html = '<div class="kol-page">' . legacy_open($st['legacy'] ?? []);
    foreach ($page['sections'] as $s) {
        $cls = "kol-s-{$s['id']}";
        $rule = ($s['pad'] !== '' ? 'padding:' . PADS[$s['pad']] . ' 0;' : '') . ($s['bg'] ? "background:{$s['bg']};" : '');
        if ($rule) $css[] = "$P .$cls{" . $rule . '}';
        $legacy = isset($s['legacy']);
        // פס שיובא: העטיפות המקוריות (המחלקות של אלמנטור / וורדפרס), כדי שה-CSS של האתר ימשיך לחול
        $html .= $legacy ? legacy_open($s['legacy'], "kol-sec $cls", $kid($s['id']))
            : "<section class=\"kol-sec $cls\"{$kid($s['id'])}><div class=\"" . ($s['full'] ? 'kol-full' : 'kol-inner') . '"><div class="kol-row">';
        $widths = explode('-', $s['layout']);
        foreach ($s['columns'] as $i => $c) {
            if (isset($c['legacy'])) {
                $html .= $c['legacy'] ? legacy_open($c['legacy'], 'kol-col', $kid($c['id'])) : "<div class=\"kol-col kol-contents\"{$kid($c['id'])}>";
            } else {
                $html .= "<div class=\"kol-col\" style=\"flex:0 0 calc({$widths[$i]}% - " . (24 * (count($widths) - 1) / count($widths)) . "px)\"{$kid($c['id'])}>";
            }
            foreach ($c['widgets'] as $w) {
                [$wh, $wc] = widget_render($w, $editor);
                $html .= $wh;
                if ($wc) $css[] = $wc;
            }
            $html .= !empty($c['legacy']) ? legacy_close($c['legacy']) : '</div>';
        }
        $html .= $legacy ? legacy_close($s['legacy']) : '</div></div></section>';
    }
    $html .= legacy_close($st['legacy'] ?? []) . '</div>';
    // ה-CSS של כל מסמך מהעורך — ממוקד ב-.kol-lt-<מזהה> (שני מסמכים עם הגדרות שונות לא מתנגשים)
    foreach ($GLOBALS['kol_long'] as $doc => $lt) {
        $scope = "$P .kol-lt-" . substr(md5($doc), 0, 10) . ' ';
        $css[] = preg_replace('/(^|[},])\s*\.wp-doc/', '$1' . $scope . '.wp-doc', $lt['css']);
    }
    $css[] = "$P .kol-long .wp-doc{color:inherit;max-width:100%}$P .kol-long img{max-width:100%}";
    $css[] = "$P iframe.kol-code{display:block;width:100%;border:0;background:transparent}";
    // גובה אוטומטי לקטעי קוד: המסגרת מדווחת את הגובה שלה. בסטודיו — התצוגה מקשיבה (studio.js); באתר — התוסף (קוד
    // קבוע שלו; הגרסה עצמה בלי שום <script>)
    // העיצוב שיובא מהאתר (ה-CSS של העמוד באלמנטור); בסטודיו — גם ה-CSS הבסיסי של אלמנטור, שבאתר כבר נטען
    if ($ic = imported_css()) $css[] = $ic;
    if ($editor && ($pc = setting('preview_css'))) $css[] = $pc;
    $out = ['html' => $html, 'css' => implode("\n", $css)];
    // ה-JS של המילון בסטודיו: מקבצי הקוד (אם יש) או של ערכת הנושא — רץ בתוך התצוגה המבודדת
    if (str_contains($html, 'kadisha-lexicon-page')) {
        $js = $GLOBALS['kol_page_js'] ?? null;
        if ($editor) {
            if ($js ??= setting('preview_js')) $out['js'] = $js;
            $out['fonts'] = LEX_FONTS;
        }
        // באתר — אף פעם לא JS מהקבצים בדף עצמו (רק במסגרת המבודדת, lexicon_frame)
    }
    unset($GLOBALS['kol_page_js']);
    // kol-file:<מזהה> (בתוכן שיובא ובסגנונות) → הכתובת של הקובץ: בסטודיו חתומה, בפרסום — {{KOL_ASSETS}}
    foreach ($out as $k => $v) {
        $out[$k] = preg_replace_callback('/kol-file:([0-9a-f]{32})/', function ($m) {
            $f = $GLOBALS['kol_files'][$m[1]] ?? null;
            return $f ? ($GLOBALS['kol_url'])($f) : '#';
        }, $v);
    }
    return $out;
}

function imported_css(): string
{
    return function_exists('setting') ? (string)setting('imported_css', '') : '';
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
    if (isset($w['legacy']) && ($lh = legacy_widget_render($w, $cls, $k, $f, $src, $editor)) !== null) return [$lh, $css];
    if ($w['type'] === 'raw') return [raw_render($p['html'], "$cls kol-raw", $k), $css];
    if ($w['type'] === 'lexicon') { [$lh, $lc] = lexicon_render($w, $editor, $cls, $k); return [$lh, $css . $lc]; }
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
        'code' => trim($p['html']) !== '' ? "<div class=\"$cls\"$k>" . code_frame($w['id'], $p) . '</div>' : $missing('&lt;/&gt; הדביקו קוד להטמעה'),
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

/**
 * קטע קוד — תמיד במסגרת מבודדת (docs/spec.md, הכרעה 4): sandbox בלי allow-same-origin, ולכן אין לו גישה לעוגיות,
 * ל-DOM או לאחסון של הדף שמכיל אותו — לא בסטודיו ולא באתר. גובה אוטומטי — סקריפט קטן בתוך המסגרת מדווח את הגובה.
 */
function code_frame(string $id, array $p): string
{
    $doc = '<!doctype html><html dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<style>html,body{margin:0}body{font-family:system-ui,Arial,sans-serif}</style></head><body>' . $p['html']
        . ($p['auto'] ? '<script>(function(){var s=function(){parent.postMessage({kolCode:"' . $id . '",h:document.documentElement.scrollHeight},"*")};'
            . 'addEventListener("load",s);if(window.ResizeObserver)new ResizeObserver(s).observe(document.documentElement);s();})();</script>' : '')
        . '</body></html>';
    return '<iframe class="kol-code" data-kol-code="' . $id . '"' . ($p['auto'] ? ' data-kol-auto' : '')
        . ' sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox allow-forms" referrerpolicy="no-referrer" loading="lazy"'
        . ' title="' . e($p['title'] ?: 'תוכן מוטמע') . '" style="height:' . $p['height'] . 'px" srcdoc="' . e($doc) . '"></iframe>';
}

/**
 * רכיב שיובא עם העטיפות המקוריות: העטיפות (עם kol-w ו-data-kid על החיצונית), ובפנים — התוכן הנוכחי של הרכיב
 * באותו אלמנט ובאותן מחלקות כמו באתר. null — אין לסוג הזה צורה מקורית; מרונדר כרגיל.
 */
function legacy_widget_render(array $w, string $cls, string $k, callable $f, callable $src, bool $editor): ?string
{
    $lg = $w['legacy'];
    $p = $w['props'];
    $c = fn(string $x) => $x !== '' ? ' class="' . e($x) . '"' : '';
    $inner = match ($w['type']) {
        'heading' => ($t = $lg['tag'] ?? "h{$p['level']}") ? "<$t" . $c($lg['cls'] ?? '') . '>' . e($p['text']) . "</$t>" : '',
        'text' => $p['html'],
        'image' => ($img = $f($p['file']))
            ? ($p['href'] ? '<a href="' . e($p['href']) . '">' : '') . '<img' . $c($lg['cls'] ?? '') . ' src="' . $src($img) . '" alt="' . e($p['alt'])
              . '" width="' . (int)$img['w'] . '" height="' . (int)$img['h'] . '" loading="lazy" decoding="async">' . ($p['href'] ? '</a>' : '')
              . ($p['caption'] !== '' ? '<figcaption' . $c($lg['cap'] ?? '') . '>' . e($p['caption']) . '</figcaption>' : '')
            : null,
        'button' => ($lg['kind'] ?? '') === 'elementor-button'
            ? '<a' . $c($lg['cls'] ?? 'elementor-button') . ($p['href'] ? ' href="' . e($p['href']) . '"' : '') . '><span class="elementor-button-content-wrapper"><span class="elementor-button-text">'
              . e($p['label']) . '</span></span></a>'
            : '<a' . $c($lg['cls'] ?? '') . ($p['href'] ? ' href="' . e($p['href']) . '"' : '') . '>' . e($p['label']) . '</a>',
        'divider', 'spacer' => $lg['inner'] ?? '',
        'raw' => $p['html'],
        default => null,
    };
    if ($inner === null) return null;
    $wrap = $lg['wrap'] ?? [];
    if (!$wrap) return raw_render($inner, "$cls kol-legacy", $k);
    return legacy_open($wrap, "$cls kol-legacy", $k) . $inner . legacy_close($wrap);
}

/** HTML חופשי: אם יש לו שורש אחד — המחלקות שלנו עליו (בלי עטיפה שמשנה פריסה); אחרת — עטיפה. */
function raw_render(string $html, string $cls, string $k): string
{
    $t = ltrim($html);
    if (preg_match('/^<([a-z][a-z0-9]*)\b([^>]*)>/i', $t, $m) && raw_single_root($t, $m[1])) {
        $attrs = $m[2];
        $attrs = preg_match('/\sclass="([^"]*)"/i', $attrs)
            ? preg_replace('/\sclass="([^"]*)"/i', ' class="$1 ' . $cls . '"', $attrs, 1)
            : ' class="' . $cls . '"' . $attrs;
        return '<' . $m[1] . $attrs . $k . '>' . substr($t, strlen($m[0]));
    }
    return "<div class=\"$cls\"$k>$html</div>";
}

function raw_single_root(string $html, string $tag): bool
{
    $d = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $body = $d->getElementsByTagName('body')->item(0);
    $n = 0;
    foreach ($body?->childNodes ?? [] as $c) {
        if ($c instanceof DOMElement) $n++;
        elseif ($c instanceof DOMText && trim($c->data) !== '') return false;
    }
    return $n === 1;
}

// ── העטיפות של עמוד שיובא (לשעבר lib/legacy.php) ─────────────

const LEGACY_TAGS = ['div', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav', 'figure', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li'];
const STYLE_PROPS = '/^(color|background(-color)?|font(-family|-size|-weight|-style|-variant)?|text-(align|decoration|transform|indent|shadow)|line-height|letter-spacing|word-spacing|'
    . 'margin(-(top|right|bottom|left|inline|block)(-(start|end))?)?|padding(-(top|right|bottom|left|inline|block)(-(start|end))?)?|'
    . 'width|height|max-width|min-width|max-height|min-height|display|vertical-align|float|clear|border(-[a-z-]+)?|border-radius|direction|'
    . 'flex(-[a-z]+)?|gap|justify-content|align-(items|self|content)|order|grid(-[a-z-]+)?|opacity|white-space|list-style(-type)?|'
    . 'counter-(reset|increment)|object-fit|aspect-ratio|overflow|--[a-z0-9-]+)$/i';

function legacy_cls(mixed $v): string
{
    return is_string($v) ? trim(preg_replace('/[^A-Za-z0-9_\- :]/', '', mb_substr($v, 0, 600))) : '';
}

/** style בטוח: מאפיינים מהרשימה, בלי url() (חוץ מקובץ שלנו), expression, javascript:, תווי סגירה. */
function sanitize_style(mixed $v): string
{
    if (!is_string($v)) return '';
    $out = [];
    foreach (explode(';', mb_substr($v, 0, 2000)) as $decl) {
        if (!str_contains($decl, ':')) continue;
        [$p, $val] = array_map('trim', explode(':', $decl, 2));
        if (!preg_match(STYLE_PROPS, $p) || preg_match('/[<>{}\\\\]|expression|javascript:|@import|behavior/i', $val)) continue;
        if (preg_match('/url\s*\(/i', $val) && !preg_match('/^[^(]*url\(\s*["\']?kol-file:[0-9a-f]{32}["\']?\s*\)[^(]*$/i', $val)) continue;
        $out[] = strtolower($p) . ':' . $val;
    }
    return implode(';', $out);
}

/** [{t, a}] — עטיפות. */
function legacy_tags(mixed $v): array
{
    $out = [];
    foreach (array_slice(is_array($v) ? $v : [], 0, 6) as $tag) {
        if (!is_array($tag) || !in_array($tag['t'] ?? '', LEGACY_TAGS, true)) continue;
        $a = [];
        foreach (is_array($tag['a'] ?? null) ? $tag['a'] : [] as $k => $val) {
            $k = strtolower((string)$k);
            if ($k === 'class') { if ($c = legacy_cls($val)) $a['class'] = $c; }
            elseif ($k === 'style') { if ($st = sanitize_style($val)) $a['style'] = $st; }
            elseif (($k === 'id' || $k === 'role' || preg_match('/^(data|aria)-[a-z0-9_-]{1,40}$/', $k)) && is_scalar($val) && $k !== 'data-settings') {
                $a[$k] = mb_substr((string)$val, 0, 200);
            }
        }
        $out[] = ['t' => $tag['t'], 'a' => $a];
    }
    return $out;
}

/** פתיחה של העטיפות; $add — מחלקות ותכונות שלנו על העטיפה החיצונית (kol-w, data-kid). */
function legacy_open(array $tags, string $addClass = '', string $addAttrs = ''): string
{
    $html = '';
    foreach ($tags as $i => $tag) {
        $a = $tag['a'];
        if ($i === 0 && $addClass !== '') $a['class'] = trim(($a['class'] ?? '') . ' ' . $addClass);
        $attrs = '';
        foreach ($a as $k => $v) $attrs .= ' ' . $k . '="' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '"';
        $html .= '<' . $tag['t'] . $attrs . ($i === 0 ? $addAttrs : '') . '>';
    }
    return $html;
}

function legacy_close(array $tags): string
{
    return implode('', array_map(fn($t) => '</' . $t['t'] . '>', array_reverse($tags)));
}

/** ה-legacy של רכיב: עטיפות, מחלקת האלמנט הפנימי, ותגית (לכותרת שאינה h1–h6 באתר). */
function legacy_widget(mixed $v): ?array
{
    if (!is_array($v)) return null;
    $wrap = legacy_tags($v['wrap'] ?? []);
    $tag = in_array($v['tag'] ?? '', ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'span'], true) ? $v['tag'] : '';
    $out = array_filter(['wrap' => $wrap, 'cls' => legacy_cls($v['cls'] ?? ''), 'tag' => $tag, 'cap' => legacy_cls($v['cap'] ?? ''),
        'kind' => in_array($v['kind'] ?? '', ['elementor-button', 'flow'], true) ? $v['kind'] : '',
        'inner' => isset($v['inner']) ? sanitize_raw((string)$v['inner']) : ''], fn($x) => $x !== '' && $x !== []);
    return $out ?: null;
}

/**
 * HTML מקורי ("קטע מקורי"): כמעט כמו שהוא — מחלקות, סגנונות, תמונות ושמע שלנו (kol-file:), ו-iframe רק משירותי
 * הטמעה מוכרים. בלי סקריפטים, on*, javascript:, טפסים.
 */
const RAW_IFRAME_HOSTS = '~^https://(www\.)?(youtube\.com|youtube-nocookie\.com|player\.vimeo\.com|w\.soundcloud\.com|open\.spotify\.com|www\.google\.com/maps|maps\.google\.com|docs\.google\.com|drive\.google\.com|www\.facebook\.com/plugins)/~i';

function sanitize_raw(string $html): string
{
    $html = mb_substr($html, 0, 300_000);
    if (trim($html) === '') return '';
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><body><div id="kol-root">' . $html . '</div></body>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('kol-root');
    if (!$root) return '';
    $drop = ['script', 'style', 'frame', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'base', 'math', 'template', 'noscript', 'svg'];
    $walk = function (DOMNode $n) use (&$walk, $drop) {
        foreach (iterator_to_array($n->childNodes) as $c) {
            if (!$c instanceof DOMElement) { if (!$c instanceof DOMText) $n->removeChild($c); continue; }
            $tag = strtolower($c->tagName);
            if (in_array($tag, $drop, true) || ($tag === 'iframe' && !preg_match(RAW_IFRAME_HOSTS, $c->getAttribute('src')))) { $n->removeChild($c); continue; }
            foreach (iterator_to_array($c->attributes) as $a) {
                $name = strtolower($a->name);
                $v = trim($a->value);
                $ok = match (true) {
                    str_starts_with($name, 'on'), in_array($name, ['srcdoc', 'formaction', 'xlink:href', 'srcset', 'data-settings'], true) => false,
                    $name === 'style' => ($a->value = sanitize_style($v)) !== '',
                    in_array($name, ['href', 'src', 'poster'], true) => (bool)preg_match('~^(https://|mailto:|tel:|#|kol-file:[0-9a-f]{32}$)~i', $v),
                    default => true,
                };
                if (!$ok) $c->removeAttribute($a->name);
            }
            if ($tag === 'iframe') { $c->setAttribute('loading', 'lazy'); $c->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin'); }
            $walk($c);
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return $out;
}
