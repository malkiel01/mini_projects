<?php
/**
 * עזרים משותפים: ניקוי ערכים (pick, color, str, safe_href, file_id), סינון טקסט עשיר (sanitize_rich), e() ו-size_label.
 *
 * M6: מודל העמוד הישן (רכיבים בטיוטה, page_normalize, page_render) יצא מכאן ל-lib/legacy_read.php — לקריאה בלבד, בשביל
 * נקודות שחזור ישנות ו"🗄️ ארכיון". מה שנשאר כאן אינו תלוי בטיוטה.
 */

declare(strict_types=1);

require_once __DIR__ . '/files.php';
require_once __DIR__ . '/lexicon.php';

const TEXT_MAX = 60_000;

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

const RICH_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'a', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'span', 'div',
    'sup', 'sub', 'mark', 'small', 'hr', 'img', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'pre', 'code'];
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

/* T2: lang= בטקסט עשיר — רק ערך BCP47 תקין בקירוב (שפה 2–3 אותיות, ותגיות משנה אלפאנומריות); אחרת התכונה יורדת */
function rich_lang(string $v): string
{
    $v = trim($v);
    return strlen($v) <= 35 && preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8}){0,4}$/D', $v) ? $v : '';
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
        $lang = rich_lang($child->getAttribute('lang'));                            // T2: ar / he / en-US — נשמר
        // עיצוב שבא מהאתר (ייבוא) או מהעורך: style מסונן ומחלקות — נשמרים
        $keep = array_filter(['style' => sanitize_style($child->getAttribute('style')), 'class' => legacy_cls($child->getAttribute('class'))]);
        $img = $tag === 'img' && preg_match('/^kol-file:[0-9a-f]{32}$/', $child->getAttribute('src'))
            ? array_filter(['src' => $child->getAttribute('src'), 'alt' => $child->getAttribute('alt'),
                'width' => (string)(int)$child->getAttribute('width') ?: '', 'height' => (string)(int)$child->getAttribute('height') ?: '']) : null;
        $span = in_array($tag, ['td', 'th'], true) ? array_filter(['colspan' => (string)(int)$child->getAttribute('colspan') ?: '', 'rowspan' => (string)(int)$child->getAttribute('rowspan') ?: '']) : [];
        foreach (iterator_to_array($child->attributes) as $a) $child->removeAttribute($a->name);
        if ($tag === 'img' && !$img) { $node->removeChild($child); continue; }
        foreach (($img ?? []) + $span + $keep as $k => $v) if ($v !== '0') $child->setAttribute($k, $v);
        if ($dir) $child->setAttribute('dir', $dir);
        if ($lang) $child->setAttribute('lang', $lang);
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

// ── רינדור ────────────────────────────────────────────────

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function size_label(int $b): string
{
    return $b >= 1 << 20 ? round($b / (1 << 20), 1) . 'MB' : max(1, round($b / 1024)) . 'KB';
}
