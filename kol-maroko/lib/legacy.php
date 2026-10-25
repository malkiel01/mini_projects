<?php
/**
 * "המבנה המקורי" של עמוד שיובא מהאתר (lib/import.php): העטיפות של אלמנטור / וורדפרס סביב כל פס, עמודה ורכיב,
 * כדי שהעיצוב המקורי (ה-CSS של האתר) ימשיך לחול בדיוק כמו היום — והתוכן בפנים נשאר רכיבים רגילים שעורכים.
 *
 * העטיפות שמורות כמבנה ולא כטקסט: [{t: תגית, a: {תכונה: ערך}}]. כך הדפדפן (שחוזר עם הטיוטה) לא יכול להבריח
 * סקריפט: תגיות מרשימה, תכונות class / id / style / data-* / role / aria-*, וסגנון בלי url() חיצוני.
 */

declare(strict_types=1);

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

/** CSS שיובא מהאתר: בלי </style, @import, expression; url() — רק לקובץ שלנו או לאתר עצמו (גופנים של הערכה). */
function sanitize_imported_css(string $css, string $siteOrigin): string
{
    $css = str_ireplace(['</style', '<!--', '-->'], '', $css);
    $css = preg_replace('/@import[^;]*;/i', '', $css);
    $css = preg_replace('/expression\s*\(|javascript:|behavior\s*:|-moz-binding/i', '', $css);
    $site = preg_quote($siteOrigin, '~');
    return preg_replace_callback('~url\(\s*(["\']?)([^)"\']*)\1\s*\)~i', function ($m) use ($site) {
        $u = trim($m[2]);
        if (preg_match('~^kol-file:[0-9a-f]{32}$~', $u) || preg_match('~^' . $site . '/~i', $u) || str_starts_with($u, 'data:font/') || str_starts_with($u, 'data:image/')) {
            return 'url("' . $u . '")';
        }
        return 'none';
    }, $css);
}
