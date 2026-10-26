<?php
/**
 * סינון HTML של הודעה — השכבה הראשונה מתוך שתיים.
 *
 * רשימה לבנה: תגית שלא ברשימה נפתחת (התוכן נשאר, התגית לא), ותגית מסוכנת נמחקת עם התוכן
 * (script, iframe, object, form…). תכונות — רק מהרשימה; on* תמיד נמחקות. קישור — רק http(s),
 * mailto ו-tel, ונפתח בלשונית חדשה עם rel=noopener. תמונה — https/http או cid: (מוחלף ב-data:).
 * CSS — בלי @import, expression(), url() חיצוני לסקריפט, behavior ו-binding.
 *
 * השכבה השנייה בדפדפן: <iframe sandbox> בלי allow-scripts, ו-CSP שאוסר כל סקריפט.
 */

declare(strict_types=1);

const MB_DROP = ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button',
    'textarea', 'select', 'option', 'link', 'meta', 'base', 'noscript', 'template', 'svg', 'math', 'audio', 'video',
    'source', 'track', 'canvas', 'portal', 'dialog', 'title', 'head', 'xml', 'param'];

const MB_ALLOW = ['a', 'abbr', 'address', 'article', 'aside', 'b', 'bdi', 'bdo', 'big', 'blockquote', 'br', 'caption',
    'center', 'cite', 'code', 'col', 'colgroup', 'dd', 'del', 'details', 'dfn', 'div', 'dl', 'dt', 'em', 'figcaption',
    'figure', 'font', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'i', 'img', 'ins', 'kbd', 'label',
    'li', 'main', 'mark', 'nav', 'ol', 'p', 'pre', 'q', 's', 'samp', 'section', 'small', 'span', 'strike', 'strong',
    'style', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'time', 'tr', 'tt', 'u', 'ul',
    'var', 'wbr'];

const MB_ATTRS = ['align', 'alt', 'bgcolor', 'border', 'cellpadding', 'cellspacing', 'class', 'color', 'colspan',
    'dir', 'face', 'height', 'href', 'hspace', 'lang', 'role', 'rowspan', 'size', 'src', 'start', 'style', 'summary',
    'title', 'type', 'valign', 'vspace', 'width', 'scope', 'nowrap', 'background'];

function mb_sanitize_html(string $html, array $cids = []): string
{
    if (trim($html) === '') return '';
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    // ה-meta מכריח UTF-8; בלעדיו libxml מפענח כ-Latin-1
    $doc->loadHTML('<?xml encoding="UTF-8"><meta charset="utf-8">' . $html, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $out = new DOMDocument("1.0", "UTF-8");
    $out->encoding = "UTF-8";   // אחרת saveHTML כותב כל אות עברית כ-&#x…;
    $root = $out->createElement('div');
    $out->appendChild($root);

    // <style> שב-head — נשמר (עיצוב של מיילים יושב שם), ועובר את אותו סינון
    foreach (iterator_to_array($doc->getElementsByTagName('style')) as $st) {
        $css = mb_clean_css($st->textContent);
        if ($css !== '') $root->appendChild($out->createElement('style'))->appendChild($out->createTextNode($css));
        $st->parentNode?->removeChild($st);
    }

    $body = $doc->getElementsByTagName('body')->item(0);
    if ($body) {
        $dir = $body->getAttribute('dir');
        if (in_array(strtolower($dir), ['rtl', 'ltr', 'auto'], true)) $root->setAttribute('dir', strtolower($dir));
        $bg = $body->getAttribute('bgcolor');
        if ($bg !== '' && preg_match('/^#?[0-9a-z]{3,20}$/i', $bg)) $root->setAttribute('style', 'background-color:' . $bg);
        foreach (iterator_to_array($body->childNodes) as $child) mb_copy_node($child, $root, $out, $cids);
    }
    return (string)$out->saveHTML($root);
}

function mb_copy_node(DOMNode $node, DOMNode $parent, DOMDocument $out, array $cids): void
{
    if ($node instanceof DOMText) {
        $parent->appendChild($out->createTextNode($node->data));
        return;
    }
    if (!$node instanceof DOMElement) return;     // הערות, הוראות עיבוד — נמחקות
    $tag = strtolower($node->tagName);
    if (in_array($tag, MB_DROP, true)) return;
    if (!in_array($tag, MB_ALLOW, true)) {         // לא מוכרת — רק התוכן
        foreach (iterator_to_array($node->childNodes) as $c) mb_copy_node($c, $parent, $out, $cids);
        return;
    }
    $el = $out->createElement($tag);
    if ($tag === 'style') {
        $el->appendChild($out->createTextNode(mb_clean_css($node->textContent)));
        $parent->appendChild($el);
        return;
    }
    foreach (iterator_to_array($node->attributes) as $attr) {
        $name = strtolower($attr->name);
        $val = $attr->value;
        if (!in_array($name, MB_ATTRS, true)) continue;
        if ($name === 'href') {
            if ($tag !== 'a' || !preg_match('#^(https?:|mailto:|tel:)#i', trim($val))) continue;
        } elseif ($name === 'src') {
            if ($tag !== 'img') continue;
            $v = trim($val);
            if (stripos($v, 'cid:') === 0) {
                $v = $cids[strtolower(trim(substr($v, 4), '<> '))] ?? '';
                if ($v === '') continue;
            } elseif (!preg_match('#^https?://#i', $v) && !preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $v)) {
                continue;
            }
            $val = $v;
        } elseif ($name === 'background') {
            if (!preg_match('#^https?://#i', trim($val))) continue;
        } elseif ($name === 'style') {
            $val = mb_clean_css($val);
            if ($val === '') continue;
        }
        $el->setAttribute($name, $val);
    }
    if ($tag === 'a' && $el->hasAttribute('href')) {
        $el->setAttribute('target', '_blank');
        $el->setAttribute('rel', 'noopener noreferrer');
    }
    $parent->appendChild($el);
    foreach (iterator_to_array($node->childNodes) as $c) mb_copy_node($c, $el, $out, $cids);
}

function mb_clean_css(string $css): string
{
    // פענוח escape של CSS (\6a = j) לפני הבדיקה, כדי ש-"\6a avascript:" לא יעבור
    $plain = preg_replace_callback('/\\\\([0-9a-f]{1,6})\s?/i', fn($m) => mb_chr_safe(hexdec($m[1])), $css) ?? '';
    $plain = str_replace('\\', '', $plain);
    if (preg_match('/expression\s*\(|javascript:|vbscript:|behavior\s*:|-moz-binding|@import|<\/?\s*style/i', $plain)) {
        // ניקוי עדין לא שווה את הסיכון — כל הבלוק נמחק
        return '';
    }
    // url(...) — רק https/http ו-data:image
    $plain = preg_replace_callback('/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/i', function ($m) {
        $u = trim($m[2]);
        return preg_match('#^(https?://|data:image/(png|jpe?g|gif|webp);base64,)#i', $u) ? 'url("' . str_replace('"', '', $u) . '")' : 'none';
    }, $plain) ?? '';
    // position:fixed יכול לכסות את כל המסך מעל הממשק — לא קורה ב-iframe, אבל ממילא אין צורך
    return trim(preg_replace('/position\s*:\s*fixed/i', 'position:static', $plain) ?? '');
}

function mb_chr_safe(int|float $cp): string
{
    $cp = (int)$cp;
    if ($cp <= 0 || $cp > 0x10FFFF || ($cp >= 0xD800 && $cp <= 0xDFFF)) return '';
    return function_exists('mb_chr') ? (string)mb_chr($cp, 'UTF-8') : html_entity_decode('&#' . $cp . ';', ENT_QUOTES, 'UTF-8');
}
