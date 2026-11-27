<?php
/**
 * שפת הסימון של ספורנו — בדיקת המבנה, הניקוי לפי רשימת היתרים, והאינדקס המנורמל (אפיון 02 §4, §5, §9; האפיון §6).
 *
 * שלושה שלבים, בכל שמירה:
 *   1. sf_mk_structure — האם ה-HTML סגור ומקונן נכון (תגית לא סגורה, סגירה בסדר הפוך, בלוק בתוך פסקה, טבלה שבורה).
 *      זו בדיקה בלבד, עם שורה ועמודה, ולא פירוש: היא לא מחליטה מה נשמר.
 *   2. הפירוש — DOMDocument (לא regex). את המסמך הנקי **בונים מחדש** מהעץ: לכל צומת מותר נוצר צומת חדש, ונעתקות
 *      אליו רק התכונות שברשימה, אחרי בדיקת הערך. מה שאינו ברשימה לא מגיע למסמך החדש בכלל — אין כאן "מחיקה של הרע",
 *      רק "העתקה של הטוב".
 *   3. הבדיקות על המסמך הנקי: sid כפול, sf-link ליעד חסר, sf-cond לא מוכר או סותר.
 *
 * הניקוי **לא נוגע בטקסט**: ניקוד וטעמים נשמרים כמו שהם (§6). ההסתרה — פעולת תצוגה (04).
 * הפלט של sf_mk_clean על הפלט של עצמו — זהה (אידמפוטנטי), וסדר התכונות קבוע.
 */

declare(strict_types=1);

/** סגנונות מותרים ב-sf-style v= — שמות בלבד, לא CSS. */
const SF_MK_STYLES = ['large', 'small', 'bold', 'red', 'gray', 'center'];

/** הרכיבים המותרים, והתכונות של כל אחד (מלבד dir ו-lang, שמותרות בכולם). הסדר כאן = סדר התכונות בפלט. */
const SF_MK_TAGS = [
    'sf-section' => ['sid', 'title'],
    'sf-anchor'  => ['sid', 'title'],
    'sf-style'   => ['v'],
    'sf-cond'    => ['rule'],
    'sf-note'    => ['kind'],
    'sf-link'    => ['to'],
    'sf-audio'   => ['sid'],
    'p' => ['sid'], 'h2' => ['sid'], 'h3' => ['sid'], 'h4' => ['sid'], 'li' => ['sid'],
    'br' => [], 'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'small' => [], 'span' => [],
    'ul' => [], 'ol' => [],
    'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan'],
    'img' => ['src', 'alt'],
];

/** רכיבים שחייבים sid (נקבע לבד אם חסר), ורכיבים שמקבלים sid לבד — "קטע" (§6). */
const SF_MK_SID_TAGS = ['sf-section', 'sf-anchor', 'sf-audio', 'p', 'h2', 'h3', 'h4', 'li'];

/** רכיבים שנזרקים יחד עם התוכן שלהם. כל רכיב אחר שאינו מותר — נזרק, והתוכן שלו נשאר. */
const SF_MK_DROP = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'frame', 'frameset', 'template', 'noscript', 'noembed',
    'noframes', 'xmp', 'plaintext', 'svg', 'math', 'textarea', 'select', 'option', 'button', 'input', 'form', 'title', 'head', 'link',
    'meta', 'base', 'video', 'audio', 'canvas', 'picture', 'source', 'track', 'param', 'map', 'area', 'dialog', 'portal'];

const SF_MK_VOID = ['br', 'img', 'hr', 'wbr', 'input', 'meta', 'link', 'area', 'base', 'col', 'embed', 'param', 'source', 'track', 'keygen'];
const SF_MK_RAWTEXT = ['script', 'style', 'textarea', 'title', 'iframe', 'noscript', 'noembed', 'noframes', 'xmp'];
/** רכיבי בלוק: לא יכולים להופיע בתוך פסקה, כותרת או רכיב מוטבע (הדפדפן היה סוגר את הפסקה ומשנה את המבנה). */
const SF_MK_BLOCK = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'table', 'div', 'blockquote', 'pre', 'section', 'article',
    'aside', 'header', 'footer', 'nav', 'figure', 'figcaption', 'form', 'dl', 'dt', 'dd', 'address', 'fieldset', 'details', 'summary',
    'main', 'hgroup', 'menu', 'hr', 'sf-section', 'thead', 'tbody', 'tr', 'td', 'th'];
/** רכיבים שבתוכם רק טקסט ורכיבים מוטבעים. */
const SF_MK_PHRASING = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'b', 'strong', 'i', 'em', 'small', 'span', 'a', 'u', 's', 'sub', 'sup',
    'font', 'label', 'code', 'q', 'cite', 'abbr', 'mark', 'big', 'tt', 'sf-anchor', 'sf-link'];
const SF_MK_TABLE_KIDS = ['table' => ['thead', 'tbody', 'tr'], 'thead' => ['tr'], 'tbody' => ['tr'], 'tr' => ['td', 'th']];
const SF_MK_TABLE_PARENT = ['thead' => ['table'], 'tbody' => ['table'], 'tr' => ['table', 'thead', 'tbody'], 'td' => ['tr'], 'th' => ['tr']];

const SF_MK_MAX_DEPTH = 120;
const SF_MK_MAX_ERRORS = 20;

/** "בָּרוּךְ" → "ברוך": בלי ניקוד וטעמים, אותיות סופיות מאוחדות (סופית בסוף מילה בלבד), רווחים מאוחדים (§9). render.js — אותו דבר. */
function sf_norm(string $s): string
{
    $s = (string)preg_replace('/[\x{05BE}\x{05C0}\x{05C3}\x{05C6}]/u', ' ', $s);        // מקף, פסק, סוף פסוק, נון הפוכה — מפרידים
    $s = (string)preg_replace('/[\x{0591}-\x{05C7}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $s);
    $s = strtr($s, ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ']);   // סופיות: צורה אחת — סופית רק בסוף מילה
    $s = (string)preg_replace_callback('/[כמנפצ](?![\x{05D0}-\x{05EA}])/u', fn($m) => ['כ' => 'ך', 'מ' => 'ם', 'נ' => 'ן', 'פ' => 'ף', 'צ' => 'ץ'][$m[0]], $s);
    $s = (string)preg_replace('/[\s\x{00A0}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u', ' ', $s);
    return mb_strtolower(trim($s));
}

/** sid חדש: 8 תווים [a-z0-9], שלא נמצא ב-$taken. */
function sf_mk_new_sid(array &$taken): string
{
    $abc = 'abcdefghijklmnopqrstuvwxyz0123456789';
    do {
        $s = '';
        for ($i = 0; $i < 8; $i++) $s .= $abc[random_int(0, 35)];
    } while (isset($taken[$s]));
    $taken[$s] = true;
    return $s;
}

function sf_mk_is_sid(mixed $v): bool
{
    return is_string($v) && preg_match('/^[a-z0-9]{8}\z/', $v) === 1;
}

/** [שורה, עמודה] של מקום בטקסט (עמודה בתווים, מ-1). */
function sf_mk_pos(string $html, int $off): array
{
    $before = substr($html, 0, $off);
    $nl = strrpos($before, "\n");
    return [substr_count($before, "\n") + 1, mb_strlen($nl === false ? $before : substr($before, $nl + 1)) + 1];
}

/**
 * שלב 1 — המבנה. מחזיר רשימת שגיאות [{line, col, msg}] (ריקה = תקין). לא מפרש תכונות מעבר לגבולות שלהן.
 */
function sf_mk_structure(string $html): array
{
    $errs = [];
    $err = function (int $off, string $msg) use (&$errs, $html): bool {
        [$l, $c] = sf_mk_pos($html, $off);
        $errs[] = ['line' => $l, 'col' => $c, 'msg' => $msg];
        return count($errs) >= SF_MK_MAX_ERRORS;
    };
    $stack = [];   // [name, off, phrasing]
    $len = strlen($html);
    $i = 0;
    $text = function (int $from, int $to) use (&$stack, $html, $err): bool {
        if ($to <= $from || !$stack) return false;
        $top = end($stack);
        if (isset(SF_MK_TABLE_KIDS[$top[0]]) && trim(html_entity_decode(substr($html, $from, $to - $from), ENT_HTML5, 'UTF-8'), " \t\r\n\f\u{A0}") !== '') {
            return $err($from, "טקסט ישירות בתוך ‹{$top[0]}› — טקסט בטבלה נכתב בתוך ‹td›");
        }
        return false;
    };
    while ($i < $len) {
        $lt = strpos($html, '<', $i);
        if ($lt === false) { $text($i, $len); break; }
        if ($text($i, $lt)) return $errs;
        $i = $lt;
        if (substr_compare($html, '<!--', $i, 4) === 0) {
            $e = strpos($html, '-->', $i + 4);
            if ($e === false) { $err($i, 'הערה (‹!--›) שלא נסגרה'); return $errs; }
            $i = $e + 3;
            continue;
        }
        if (preg_match('~\G</([A-Za-z][A-Za-z0-9-]*)[^>]*>~A', $html, $m, 0, $i)) {
            $name = strtolower($m[1]);
            $at = $i;
            $i += strlen($m[0]);
            if (in_array($name, SF_MK_VOID, true)) { if ($err($at, "תגית סגירה ‹/{$name}› מיותרת — ‹{$name}› לא נסגרת")) return $errs; continue; }
            $top = end($stack);
            if ($top && $top[0] === $name) { array_pop($stack); continue; }
            $depth = null;
            for ($k = count($stack) - 1; $k >= 0; $k--) if ($stack[$k][0] === $name) { $depth = $k; break; }
            if ($depth === null) { if ($err($at, "תגית סגירה ‹/{$name}› בלי פתיחה")) return $errs; continue; }
            [$tl] = sf_mk_pos($html, $top[1]);
            if ($err($at, "קינון שבור: ‹/{$name}› נסגרת לפני ‹{$top[0]}› שנפתחה בשורה {$tl}")) return $errs;
            array_splice($stack, $depth);
            continue;
        }
        if (preg_match('~\G<([A-Za-z][A-Za-z0-9-]*)~A', $html, $m, 0, $i)) {
            $name = strtolower($m[1]);
            $at = $i;
            $j = $i + strlen($m[0]);
            $self = false;
            $closed = false;
            while ($j < $len) {   // התכונות — רק כדי למצוא את סוף התגית
                $c = $html[$j];
                if ($c === '>') { $closed = true; $j++; break; }
                if ($c === '/' && ($html[$j + 1] ?? '') === '>') { $self = true; $closed = true; $j += 2; break; }
                if ($c === '"' || $c === "'") {
                    $q = strpos($html, $c, $j + 1);
                    if ($q === false) { $err($j, "מרכאות שלא נסגרו בתגית ‹{$name}›"); return $errs; }
                    $j = $q + 1;
                    continue;
                }
                if ($c === '<') break;
                $j++;
            }
            if (!$closed) { $err($at, "התגית ‹{$name}› לא נסגרה ב-‹>›"); return $errs; }
            $i = $j;
            if (in_array($name, SF_MK_RAWTEXT, true)) {   // התוכן שלהן אינו HTML; ממילא נזרקות בניקוי
                if (!preg_match('~</' . preg_quote($name, '~') . '[\s>/]~i', $html, $mm, PREG_OFFSET_CAPTURE, $i)) { $err($at, "‹{$name}› שלא נסגרה"); return $errs; }
                $i = strpos($html, '>', (int)$mm[0][1]) + 1;
                continue;
            }
            $top = end($stack) ?: null;
            $phr = $top ? $top[2] : false;
            if (in_array($name, SF_MK_BLOCK, true) && $phr) {
                $host = '';
                for ($k = count($stack) - 1; $k >= 0; $k--) if (in_array($stack[$k][0], SF_MK_PHRASING, true)) { $host = $stack[$k][0]; break; }
                if ($err($at, "קינון שבור: ‹{$name}› לא יכול להיות בתוך ‹{$host}›")) return $errs;
            }
            // טבלה: רק ההורים הישירים הנכונים (אחרת הדפדפן מזיז את התוכן אל מחוץ לטבלה)
            $parentName = $top[0] ?? '';
            if (isset(SF_MK_TABLE_KIDS[$parentName]) && !in_array($name, SF_MK_TABLE_KIDS[$parentName], true)) {
                if ($err($at, "‹{$name}› לא יכול להיות ישירות בתוך ‹{$parentName}›")) return $errs;
            }
            $need = SF_MK_TABLE_PARENT[$name] ?? null;
            if ($need && !in_array($parentName, $need, true)) {
                if ($err($at, "‹{$name}› חייב להיות ישירות בתוך " . implode(' / ', array_map(fn($p) => "‹{$p}›", $need)))) return $errs;
            }
            if ($name === 'li') {
                $host = '';
                for ($k = count($stack) - 1; $k >= 0; $k--) if (!in_array($stack[$k][0], ['sf-cond', 'sf-style', 'sf-note'], true)) { $host = $stack[$k][0]; break; }
                if (!in_array($host, ['ul', 'ol'], true) && $err($at, '‹li› חייב להיות בתוך ‹ul› או ‹ol›')) return $errs;
            }
            if (in_array($name, SF_MK_VOID, true)) continue;
            if ($self) { if ($err($at, "‹{$name}› צריכה תגית סגירה ‹/{$name}› (‹/>› לא סוגר ב-HTML)")) return $errs; continue; }
            if (count($stack) >= SF_MK_MAX_DEPTH) { $err($at, 'קינון עמוק מדי'); return $errs; }
            $phrasing = in_array($name, ['li', 'td', 'th'], true) ? false : ($phr || in_array($name, SF_MK_PHRASING, true));
            $stack[] = [$name, $at, $phrasing];
            continue;
        }
        if (preg_match('~\G<[!?]~A', $html, $m, 0, $i)) {   // doctype / הוראת עיבוד — מדלגים; נזרקות בניקוי
            $e = strpos($html, '>', $i);
            if ($e === false) { $err($i, 'תגית שלא נסגרה ב-‹>›'); return $errs; }
            $i = $e + 1;
            continue;
        }
        $i++;   // '<' שאינו תגית — טקסט
    }
    foreach (array_reverse($stack) as [$name, $off]) {
        if ($err($off, "‹{$name}› שנפתחה כאן לא נסגרה")) break;
    }
    return $errs;
}

/** הפירוש — DOMDocument, בלי רשת ובלי ישויות חיצוניות. מחזיר את הרכיב שעוטף את התוכן. השורות = השורות של המקור. */
function sf_mk_parse(string $html): DOMElement
{
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div>' . $html . '</div></body></html>',
        LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $body = $doc->getElementsByTagName('body')->item(0);
    $root = $body?->firstChild;
    if (!$root instanceof DOMElement) throw new SfError('invalid', 'לא ניתן לקרוא את התוכן');
    return $root;
}

/** מסמך ריק לבנייה, עם רכיב שורש. */
function sf_mk_blank(): DOMElement
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML('<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div></div></body></html>', LIBXML_NONET);
    return $doc->getElementsByTagName('div')->item(0);
}

/**
 * הניקוי המלא. $ctx:
 *   customs  — [rule => grp] הכללים הקיימים
 *   reserved — [sid => true] מזהים שהיו במהדורה בעבר (sid חדש לא יחזור על מזהה ישן)
 *   link     — fn(string $editionPid, string $sid): bool — האם יעד חיצוני קיים
 *
 * מחזיר: html (הנקי), errors [{line, col?, msg}] (חוסמות שמירה), warnings [{line?, msg, blocks}] (blocks = חוסם פרסום).
 * כשיש errors — html הוא null.
 */
function sf_mk_clean(string $html, array $ctx = []): array
{
    if (!mb_check_encoding($html, 'UTF-8')) return ['html' => null, 'errors' => [['line' => 1, 'col' => 1, 'msg' => 'הטקסט אינו בקידוד UTF-8 תקין']], 'warnings' => []];
    $html = str_replace(["\r\n", "\r"], "\n", $html);
    $errors = sf_mk_structure($html);
    if ($errors) return ['html' => null, 'errors' => $errors, 'warnings' => []];

    $src = sf_mk_parse($html);
    $out = sf_mk_blank();
    $doc = $out->ownerDocument;
    $warn = [];
    $removed = ['el' => [], 'unwrap' => [], 'attr' => []];
    $count = function (string $k, string $what) use (&$removed) { $removed[$k][$what] = ($removed[$k][$what] ?? 0) + 1; };

    // כל ה-sid התקינים שבמקור — כדי ש-sid חדש לא יתנגש במזהה שמופיע בהמשך
    $taken = $ctx['reserved'] ?? [];
    $seen = [];   // sid => line
    foreach ((new DOMXPath($src->ownerDocument))->query('.//*[@sid]', $src) as $e) {
        $v = $e->getAttribute('sid');
        if (!sf_mk_is_sid($v) || !in_array(strtolower($e->localName ?? ''), SF_MK_SID_TAGS, true)) continue;
        if (isset($seen[$v])) {
            $errors[] = ['line' => $e->getLineNo(), 'msg' => "מזהה כפול sid=\"$v\" ב‹{$e->localName}› (הופיע כבר בשורה {$seen[$v]}). רכיב חדש — בלי sid, והוא יקבל מזהה לבד"];
            if (count($errors) >= SF_MK_MAX_ERRORS) break;
            continue;
        }
        $seen[$v] = $e->getLineNo();
        $taken[$v] = true;
    }
    if ($errors) return ['html' => null, 'errors' => $errors, 'warnings' => []];

    $customs = $ctx['customs'] ?? [];
    $sids = [];
    $links = [];   // [to, line]
    $walk = function (DOMNode $from, DOMNode $to, array $conds, int $depth) use (&$walk, $doc, &$warn, $count, &$taken, $customs, &$sids, &$links): void {
        foreach ($from->childNodes as $n) {
            if ($n instanceof DOMText) {   // כולל CDATA
                $t = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $n->data);
                if ($t !== '') $to->appendChild($doc->createTextNode($t));
                continue;
            }
            if (!$n instanceof DOMElement) continue;   // הערות, הוראות עיבוד
            $tag = strtolower($n->localName ?? '');
            $line = $n->getLineNo();
            if (in_array($tag, SF_MK_DROP, true)) { $count('el', $tag); continue; }
            if (!isset(SF_MK_TAGS[$tag])) { $count('unwrap', $tag); $walk($n, $to, $conds, $depth); continue; }
            foreach ($n->attributes as $a) {
                $an = strtolower($a->nodeName);
                if (!in_array($an, SF_MK_TAGS[$tag], true) && $an !== 'dir' && $an !== 'lang') $count('attr', $an);
            }
            $get = fn(string $k) => $n->hasAttribute($k) ? trim($n->getAttribute($k)) : null;
            $attrs = [];
            $childConds = $conds;
            switch ($tag) {
                case 'sf-style':
                    $v = $get('v');
                    if (!in_array($v, SF_MK_STYLES, true)) {
                        $warn[] = ['line' => $line, 'msg' => 'סגנון לא מוכר' . ($v ? " \"" . mb_substr($v, 0, 30) . "\"" : '') . ' — הסימון הוסר והטקסט נשאר', 'blocks' => false];
                        $walk($n, $to, $conds, $depth);
                        continue 2;
                    }
                    $attrs['v'] = $v;
                    break;
                case 'sf-cond':
                    $r = (string)$get('rule');
                    $r = preg_match('/^[a-z][a-z0-9_-]{0,39}\z/', $r) ? $r : '';
                    $attrs['rule'] = $r;
                    if ($r === '' || !isset($customs[$r])) {
                        $warn[] = ['line' => $line, 'msg' => $r === '' ? 'תנאי בלי כלל' : "תנאי לפי כלל שלא קיים: \"$r\"", 'blocks' => true];
                    } else {
                        foreach ($conds as $o) {
                            if ($o !== $r && isset($customs[$o]) && $customs[$o] === $customs[$r]) {
                                $warn[] = ['line' => $line, 'msg' => "תנאים סותרים על אותו קטע: \"$o\" ו-\"$r\"", 'blocks' => true];
                                break;
                            }
                        }
                        $childConds[] = $r;
                    }
                    break;
                case 'sf-note':
                    $k = $get('kind');
                    if (!in_array($k, ['instruction', 'commentary'], true)) {
                        $warn[] = ['line' => $line, 'msg' => 'הערה בלי סוג תקין — נקבעה "הוראה"', 'blocks' => false];
                        $k = 'instruction';
                    }
                    $attrs['kind'] = $k;
                    break;
                case 'sf-link':
                    $t = (string)$get('to');
                    if (preg_match('/^([a-z0-9]{16}:)?[a-z0-9]{8}\z/', $t)) { $attrs['to'] = $t; $links[] = [$t, $line]; }
                    else $warn[] = ['line' => $line, 'msg' => 'קישור בלי יעד תקין', 'blocks' => true];
                    break;
                case 'td': case 'th':
                    foreach (['colspan', 'rowspan'] as $k) {
                        $v = $get($k);
                        if ($v !== null && preg_match('/^[1-9][0-9]?\z/', $v)) $attrs[$k] = $v;
                    }
                    break;
                case 'img':
                    $s = (string)$get('src');
                    if (!preg_match('~^files/[A-Za-z0-9_-][A-Za-z0-9._/-]{0,200}\.(png|jpe?g|gif|webp)\z~i', $s) || str_contains($s, '..') || str_contains($s, '//')) {
                        $warn[] = ['line' => $line, 'msg' => 'תמונה שאינה מקובצי הספר הוסרה', 'blocks' => false];
                        continue 2;
                    }
                    $attrs['src'] = $s;
                    $alt = sf_mk_attr_text((string)$get('alt'), 300);
                    if ($alt !== '') $attrs['alt'] = $alt;
                    break;
            }
            if (in_array($tag, SF_MK_SID_TAGS, true)) {
                $sid = $get('sid');
                if (!sf_mk_is_sid($sid)) {
                    if ($sid !== null && $sid !== '') $warn[] = ['line' => $line, 'msg' => "מזהה לא תקין \"" . mb_substr($sid, 0, 20) . "\" הוחלף במזהה חדש", 'blocks' => false];
                    $sid = sf_mk_new_sid($taken);
                }
                $attrs = ['sid' => $sid] + $attrs;
                $sids[$sid] = $tag;
            }
            if (in_array('title', SF_MK_TAGS[$tag], true)) {
                $tt = sf_mk_attr_text((string)$get('title'), 200);
                if ($tt !== '') $attrs['title'] = $tt;
            }
            $dir = $get('dir');
            if (in_array($dir, ['rtl', 'ltr', 'auto'], true)) $attrs['dir'] = $dir;
            $lang = $get('lang');
            if ($lang !== null && preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8}){0,3}\z/', $lang)) $attrs['lang'] = $lang;

            $el = $doc->createElement($tag);
            foreach ($attrs as $k => $v) $el->setAttribute($k, $v);
            $to->appendChild($el);
            if ($tag !== 'br' && $tag !== 'img' && $depth < SF_MK_MAX_DEPTH) $walk($n, $el, $childConds, $depth + 1);
        }
    };
    $walk($src, $out, [], 0);

    foreach ($links as [$t, $line]) {
        if (!str_contains($t, ':')) $ok = isset($sids[$t]);
        else { [$ep, $s] = explode(':', $t, 2); $ok = isset($ctx['link']) && ($ctx['link'])($ep, $s); }
        if (!$ok) $warn[] = ['line' => $line, 'msg' => "קישור ליעד שלא קיים: \"$t\"", 'blocks' => true];
    }
    $label = ['el' => 'הוסרו רכיבים אסורים (יחד עם התוכן שלהם)', 'unwrap' => 'הוסר סימון שאינו מותר (הטקסט נשאר)', 'attr' => 'הוסרו תכונות אסורות'];
    foreach ($removed as $k => $list) {
        if (!$list) continue;
        $warn[] = ['line' => null, 'msg' => $label[$k] . ': ' . implode(', ', array_map(fn($n, $c) => $c > 1 ? "$n ×$c" : $n, array_keys($list), $list)), 'blocks' => false, 'removed' => array_keys($list)];
    }
    usort($warn, fn($a, $b) => [$a['line'] === null, $a['line'] ?? 0] <=> [$b['line'] === null, $b['line'] ?? 0]);

    $clean = '';
    foreach ($out->childNodes as $c) $clean .= $doc->saveHTML($c);
    return ['html' => $clean, 'errors' => [], 'warnings' => $warn, 'sids' => array_keys($sids)];
}

/** ערך של תכונה טקסטואלית: בלי תווי בקרה, רווחים מאוחדים, עד $max תווים. */
function sf_mk_attr_text(string $v, int $max): string
{
    $v = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
    $v = trim((string)preg_replace('/\s+/u', ' ', $v));
    return mb_substr($v, 0, $max);
}

/**
 * המקטעים של גרסה (02 §3): section · anchor · block, לפי סדר המסמך, עם parent_sid (המקטע שמעל) ו-text_norm.
 * בלוק שמכיל בלוקים אחרים (li עם p) — הטקסט שלו בלי הטקסט של הבלוקים שבתוכו; בלוק בלי טקסט משלו — לא נכנס.
 */
function sf_mk_segments(string $cleanHtml): array
{
    $root = sf_mk_parse($cleanHtml);
    $segs = [];
    $blocks = ['p', 'h2', 'h3', 'h4', 'li'];
    $own = function (DOMNode $n) use (&$own, $blocks): string {
        $t = '';
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMText) $t .= $c->data;
            elseif ($c instanceof DOMElement && !(in_array($c->localName, $blocks, true) && $c->hasAttribute('sid'))) $t .= ($c->localName === 'br' ? ' ' : '') . $own($c);
        }
        return $t;
    };
    $walk = function (DOMNode $n, ?string $parent) use (&$walk, &$segs, $own, $blocks): void {
        foreach ($n->childNodes as $c) {
            if (!$c instanceof DOMElement) continue;
            $tag = $c->localName;
            $sid = $c->getAttribute('sid');
            $next = $parent;
            if ($tag === 'sf-section' && sf_mk_is_sid($sid)) {
                $title = $c->getAttribute('title');
                $segs[] = ['sid' => $sid, 'kind' => 'section', 'parent_sid' => $parent, 'title' => $title !== '' ? $title : null, 'text_norm' => sf_norm($title)];
                $next = $sid;
            } elseif ($tag === 'sf-anchor' && sf_mk_is_sid($sid)) {
                $title = $c->getAttribute('title') ?: sf_mk_attr_text($c->textContent, 200);
                $segs[] = ['sid' => $sid, 'kind' => 'anchor', 'parent_sid' => $parent, 'title' => $title !== '' ? $title : null, 'text_norm' => sf_norm($title)];
            } elseif (in_array($tag, $blocks, true) && sf_mk_is_sid($sid)) {
                $t = sf_norm($own($c));
                if ($t !== '') $segs[] = ['sid' => $sid, 'kind' => 'block', 'parent_sid' => $parent, 'title' => null, 'text_norm' => $t];
            }
            $walk($c, $next);
        }
    };
    $walk($root, null);
    foreach ($segs as $i => &$s) $s['ord'] = $i;
    return $segs;
}
