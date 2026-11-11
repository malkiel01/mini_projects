<?php
/**
 * פרוסה F3 — ההסבה: "צור פרויקט מהעמוד של היום" (docs/spec-v2/09-migration.md §1–§5, 02-project-model.md §2, §8).
 *
 * יוצרת פרויקט קבצים מהמצב הנוכחי של הכלי: התבנית (lexicon.html — של בעל העמוד אם שינה אותה, אחרת LEX_TPL), ה-CSS
 * של ערכת הנושא כמו שהוא, וערכי הפאנל של רכיב המילון שבטיוטה. התוצאה היא index.html רגיל ומלא, בלי Mustache:
 *   - מה שתלוי בפאנל (כותרות, מבוא, תמונה) — נכתב כטקסט וכ-CSS רגיל (בלי !important של הכלי).
 *   - מה שתלוי במילים (הרשימה, כפתורי הנושאים, המונה) — <template> ותכונות data-kol-*, ש-js/kol-data.js מממש.
 *
 * ההסבה רק מוסיפה: היא אינה נוגעת בטיוטה, בשלושת קובצי הקוד, בהגדרות, בגרסאות או במילים. לפניה — נקודת שחזור.
 * מה שלא הצליחה להמיר נשאר בקובץ כהערה (<!-- kol-migrate: … -->) ומדווח; דבר אינו נזרק בשקט.
 * הנתונים: במאגר של F2 (pf_files, data/files/_project/). אין נתיב חדש תחת data/.
 */

declare(strict_types=1);

require_once __DIR__ . '/lexicon.php';
require_once __DIR__ . '/project.php';
require_once __DIR__ . '/draft.php';

const MG_LIST = 'words';
const MG_SEED = __DIR__ . '/../assets/project-seed/';
const MG_FORM_DONE = 'תודה! המשוב יישלח לתיבת הפניות. השליחה עדיין אינה פעילה.';
// שדות התבנית הישנה ← שדות data/words.json. null — אין מקבילה: התכונה מוסרת (ומדווח)
const MG_WORD_FIELDS = ['word' => 'word', 'meaning' => 'meaning', 'audio' => 'audio', 'id' => 'id', 'wp_id' => 'wp_id', 'data_id' => 'id',
    'slugs' => 'topics', 'has_topics' => 'topics', 'single' => 'topics.count=1', 'search' => null, 'search_full' => null];
const MG_TOPIC_FIELDS = ['slug' => 'id', 'name' => 'label', 'count' => 'count'];
const MG_VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

// ── המקורות ───────────────────────────────────────────────

/** ערכי הפאנל של רכיב המילון שבטיוטה (הראשון). אין רכיב — ברירות המחדל. */
function mg_props(): array
{
    $page = json_decode((string)draft_row()['json'], true) ?: [];
    foreach ($page['sections'] ?? [] as $s) foreach ($s['columns'] ?? [] as $c) foreach ($c['widgets'] ?? [] as $w) {
        if (($w['type'] ?? '') === 'lexicon') return [lexicon_props($w['props'] ?? []), (string)($page['settings']['title'] ?? ''), true];
    }
    return [lexicon_props([]), (string)($page['settings']['title'] ?? ''), false];
}

/** התבנית, ה-CSS וה-JS שמהם יוצאים — של בעל העמוד (code_files) אם יש, אחרת ברירת המחדל. */
function mg_sources(): array
{
    $mine = code_files() ?? [];
    $src = [];
    $src['html'] = [$mine['lexicon.html'] ?? LEX_TPL, isset($mine['lexicon.html']) && trim($mine['lexicon.html']) !== trim(LEX_TPL)];
    $def = theme_lexicon_css() . "\n\n" . LEX_CSS_MARK . "\n" . LEX_TPL_SIZES;
    $src['css'] = [$mine['lexicon.css'] ?? $def, isset($mine['lexicon.css']) && trim($mine['lexicon.css']) !== trim($def)];
    $js = (string)setting('preview_js', '');
    $src['js'] = [$mine['lexicon.js'] ?? $js, isset($mine['lexicon.js']) && trim($mine['lexicon.js']) !== trim($js)];
    $src['has_files'] = (bool)$mine;
    return $src;
}

// ── ההמרה: Mustache ← HTML רגיל + data-kol-* ───────────────

/** האלמנט הפתוח הפנימי ביותר בסוף $html: [היסט של '<', שם התגית], או null. */
function mg_open_parent(string $html): ?array
{
    preg_match_all('~<!--[\s\S]*?-->|<(/?)([a-zA-Z][\w:-]*)\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*?(/?)>~', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    $stack = [];
    foreach ($m as $t) {
        if (!isset($t[2])) continue;                                   // הערה
        $name = strtolower($t[2][0]);
        if ($t[1][0] === '/') {
            for ($i = count($stack) - 1; $i >= 0; $i--) if ($stack[$i][1] === $name) { array_splice($stack, $i); break; }
        } elseif (($t[3][0] ?? '') !== '/' && !in_array($name, MG_VOID, true)) {
            $stack[] = [$t[0][1], $name];
        }
    }
    return $stack ? end($stack) : null;
}

/** מוסיף תכונות לתגית הפותחת שמתחילה ב-$at. */
function mg_add_attrs(string $html, int $at, string $attrs): string
{
    if (!preg_match('~<[a-zA-Z][\w:-]*~A', $html, $m, 0, $at)) return $html;
    $end = $at + strlen($m[0]);
    return substr($html, 0, $end) . ' ' . $attrs . substr($html, $end);
}

/** האם $s הוא אלמנט אחד בדיוק (עם רווחים מסביב)? */
function mg_single_el(string $s): bool
{
    $s = trim($s);
    if (!preg_match('~^<([a-zA-Z][\w:-]*)\b~', $s, $m)) return false;
    $name = strtolower($m[1]);
    preg_match_all('~<(/?)' . preg_quote($name, '~') . '\b[^>]*?(/?)>~i', $s, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    $depth = 0;
    foreach ($tags as $i => $t) {
        if ($t[1][0] === '/') $depth--;
        elseif ($t[2][0] !== '/' && !in_array($name, MG_VOID, true)) $depth++;
        if ($depth === 0) return $t[0][1] + strlen($t[0][0]) === strlen($s);
    }
    return false;
}

/**
 * גוף של קטע דינמי (פריט ברשימה) ← מקור עם סימונים: \x01V:שדה:raw\x02 למשתנה, \x01C:תנאי\x02…\x01/C\x02 לתנאי,
 * \x01E:שדה\x02…\x01/E\x02 לחזרה, \x01U:…\x02 למה שלא זוהה. ערכי פאנל שבתוך הגוף נכתבים כטקסט.
 */
function mg_dyn(array $nodes, array $map, array $ctx, array &$notes, bool $inEach = false): string
{
    $out = '';
    foreach ($nodes as $n) {
        if (is_string($n)) { $out .= $n; continue; }
        $name = $n[1];
        $known = array_key_exists($name, $map);
        $panel = !$known && array_key_exists($name, $ctx) && !is_array($ctx[$name]);
        if ($n[0] === 'v') {
            if ($known) $out .= "\x01V:" . ($map[$name] ?: '-') . ':' . ($n[2] ? '1' : '0') . "\x02";
            elseif ($panel) { $v = is_bool($ctx[$name]) ? ($ctx[$name] ? '1' : '') : (string)$ctx[$name]; $out .= $n[2] ? $v : e($v); }
            else $out .= "\x01U:" . ($n[2] ? '{{{' . $name . '}}}' : '{{' . $name . '}}') . "\x02";
            continue;
        }
        $open = ($n[2] ? '^' : '#') . $name;
        if ($known && $map[$name] === false) {                          // אין ערך לכל הפריטים (ההגדרה כבויה בפאנל)
            if ($n[2]) $out .= mg_dyn($n[3], $map, $ctx, $notes, $inEach);
            else $notes[] = ['dropped', "{{{$open}}} — ההגדרה כבויה בפאנל, ולכן החלק הזה של הכרטיס לא נכנס לקובץ"];
            continue;
        }
        if ($known && $name === 'topics' && !$inEach && !$n[2]) {
            $out .= "\x01E:topics\x02" . mg_dyn($n[3], ['name' => 'label', 'slug' => 'id'] + $map, $ctx, $notes, true) . "\x01/E\x02";
        } elseif ($known && $map[$name] !== null) {
            $out .= "\x01C:" . ($n[2] ? '!' : '') . $map[$name] . "\x02" . mg_dyn($n[3], $map, $ctx, $notes, $inEach) . "\x01/C\x02";
        } elseif ($panel) {
            $v = $ctx[$name];
            $empty = $v === null || $v === false || $v === '' || $v === 0;
            if ($empty === $n[2]) $out .= mg_dyn($n[3], $map, $ctx, $notes, $inEach);
        } else {
            $out .= "\x01U:{{{$open}}}\x02" . mg_dyn($n[3], $map, $ctx, $notes, $inEach) . "\x01U:{{/$name}}\x02";
        }
    }
    return $out;
}

/** הסימונים של mg_dyn ← תכונות data-kol-* על האלמנטים. מה שלא נפתר — הערה בקובץ ושורה בדוח. */
function mg_resolve(string $s, array &$notes): string
{
    // תנאי בתוך class="…" ← data-kol-class-<מחלקה>
    $s = preg_replace_callback('~\bclass="([^"]*)"~', function ($m) {
        $extra = '';
        $v = preg_replace_callback('~\x01C:([^\x02]*)\x02\s*([\w-]+)\s*\x01/C\x02~', function ($c) use (&$extra) {
            $extra .= ' data-kol-class-' . $c[2] . '="' . e($c[1]) . '"';
            return '';
        }, $m[1]);
        return 'class="' . $v . '"' . $extra;
    }, $s);
    // תנאי / חזרה שעוטפים אלמנט אחד ← תכונה על האלמנט (מבפנים החוצה)
    for ($guard = 0; $guard < 200; $guard++) {
        if (!preg_match_all('~\x01([EC]):([^\x02]*)\x02((?:(?!\x01[EC]:|\x01/[EC]\x02)[\s\S])*?)\x01/\1\x02~', $s, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) break;
        $done = false;
        foreach ($m as $x) {
            if (!mg_single_el($x[3][0])) continue;
            $attr = ($x[1][0] === 'E' ? 'data-kol-each' : 'data-kol-if') . '="' . e($x[2][0]) . '"';
            $inner = preg_replace('~^(\s*<[a-zA-Z][\w:-]*)~', '$1 ' . $attr, $x[3][0], 1);
            $s = substr($s, 0, $x[0][1]) . $inner . substr($s, $x[0][1] + strlen($x[0][0]));
            $done = true;
            break;
        }
        if (!$done) break;
    }
    // תכונה שיש בה משתנה ← data-kol-attr-<שם> (ערך בודד: שם השדה; טקסט מעורב: תבנית עם {שדה})
    $s = preg_replace_callback('~\s([a-zA-Z_:][\w:.-]*)="([^"]*\x01V:[^"]*)"~', function ($m) use (&$notes) {
        if (str_contains($m[2], "\x01V:-:")) { $notes[] = ['dropped', "התכונה {$m[1]} של הכרטיס הוסרה — היא שירתה את ה-JS הישן (החיפוש נעשה עכשיו מהנתונים)"]; return ''; }
        if (preg_match('~^\x01V:([^:\x02]*):[01]\x02$~', $m[2], $one)) return ' data-kol-attr-' . $m[1] . '="' . e($one[1]) . '"';
        $tpl = preg_replace('~\x01V:([^:\x02]*):[01]\x02~', '{$1}', $m[2]);
        return ' data-kol-attr-' . $m[1] . '="' . $tpl . '"';
    }, $s);
    // אלמנט שכל תוכנו משתנה אחד ← data-kol-text / data-kol-html
    $s = preg_replace_callback('~(<[a-zA-Z][^<>\x01]*)>\s*\x01V:([^:\x02-][^:\x02]*):([01])\x02\s*(?=</)~', fn($m) => $m[1] . ($m[3] === '1' ? ' data-kol-html="' : ' data-kol-text="') . e($m[2]) . '">', $s);
    // אלמנט שתוכנו טקסט עם משתנים, בלי אלמנטים ← data-kol-text עם תבנית
    $s = preg_replace_callback('~(<[a-zA-Z][^<>\x01]*)>([^<>]*\x01V:[^:\x02-][^<>]*)(?=</)~', function ($m) {
        if (str_contains($m[2], ":1\x02") || preg_match('~\x01[^V]~', $m[2])) return $m[0];
        return $m[1] . ' data-kol-text="' . trim(preg_replace('~\x01V:([^:\x02]*):0\x02~', '{$1}', $m[2])) . '">';
    }, $s);
    // משתנה שנשאר בין אלמנטים ← <span> משלו (נבחר ונערך כמו כל אלמנט)
    $s = preg_replace_callback('~\x01V:([^:\x02-][^:\x02]*):([01])\x02(?![^<>]*>)~', function ($m) use (&$notes) {
        $notes[] = ['changed', "נוסף <span> סביב הערך {$m[1]} — טקסט שמגיע מהנתונים חייב אלמנט משלו"];
        return '<span ' . ($m[2] === '1' ? 'data-kol-html' : 'data-kol-text') . '="' . e($m[1]) . '"></span>';
    }, $s);
    // מה שנשאר: בתוך תגית — מוסר; בין תגיות — הערה. שניהם מדווחים
    $name = function (string $tok): string {
        if (preg_match('~^\x01U:(.*)\x02$~s', $tok, $u)) return $u[1];
        if (preg_match('~^\x01([EC]):(.*)\x02$~s', $tok, $u)) return '{{#' . $u[2] . '}}';
        if (preg_match('~^\x01/[EC]\x02$~', $tok)) return '{{/…}}';
        return preg_match('~^\x01V:([^:]*):~', $tok, $u) ? '{{' . $u[1] . '}}' : '{{…}}';
    };
    $s = preg_replace_callback('~<[^<>]*\x01[^<>]*>~', function ($m) use (&$notes, $name) {
        return preg_replace_callback('~\x01[^\x02]*\x02~', function ($t) use (&$notes, $name) {
            $notes[] = ['failed', 'ביטוי בתוך תגית לא הומר והוסר: ' . $name($t[0])];
            return '';
        }, $m[0]);
    }, $s);
    return preg_replace_callback('~\x01[^\x02]*\x02~', function ($t) use (&$notes, $name) {
        $n = $name($t[0]);
        $notes[] = ['failed', "ביטוי לא הומר, ונשאר בקובץ כהערה: $n"];
        return '<!-- kol-migrate: לא הומר: ' . str_replace('--', '- -', $n) . ' -->';
    }, $s);
}

/** עובר על עץ התבנית: ערכי הפאנל נכתבים כטקסט; {{#words}} ו-{{#topics}} של העמוד הופכים ל-<template>. */
function mg_walk(array $nodes, array $stack, array $ctx, array &$notes, string &$out): void
{
    foreach ($nodes as $n) {
        if (is_string($n)) { $out .= $n; continue; }
        if ($n[0] === 's' && !$n[2] && ($n[1] === 'words' || $n[1] === 'topics') && tpl_get($n[1], $stack) === ($ctx[$n[1]] ?? null) && !empty($ctx[$n[1]])) {
            $isWords = $n[1] === 'words';
            $map = $isWords
                ? MG_WORD_FIELDS + ['topics' => 'topics', 'ext_id' => $ctx['show_ids'] ? 'external_id' : false, 'feedback_id' => $ctx['feedback'] ? 'wp_id' : false]
                : MG_TOPIC_FIELDS;
            $body = mg_resolve(mg_dyn($n[3], $map, $ctx, $notes), $notes);
            $attrs = $isWords ? 'data-kol-list="' . MG_LIST . '" data-kol-sort="manual"' : 'data-kol-filter="' . MG_LIST . ':topics" data-kol-active-class="is-active"';
            $parent = mg_open_parent($out);
            // המכל של הרשימה הוא האלמנט שעוטף את הלולאה. אם כבר יש לו קשירה אחרת — עוטפים ב-<div> חדש
            if ($parent && !str_contains(substr($out, $parent[0], (int)strpos($out . '>', '>', $parent[0]) - $parent[0]), 'data-kol-')) {
                $out = mg_add_attrs($out, $parent[0], $attrs);
                $out .= '<template>' . $body . '</template>';
            } else {
                $notes[] = ['changed', "נוסף <div> סביב הלולאה {{#{$n[1]}}} — לא נמצא אלמנט שעוטף רק אותה"];
                $out .= "<div $attrs><template>" . $body . '</template></div>';
            }
            continue;
        }
        $v = tpl_get($n[1], $stack);
        if ($n[0] === 'v') {
            $s = is_scalar($v) ? (is_bool($v) ? ($v ? '1' : '') : (string)$v) : '';
            $out .= $n[2] ? $s : e($s);
            continue;
        }
        $empty = $v === null || $v === false || $v === '' || $v === 0 || $v === [];
        if ($n[2]) { if ($empty) mg_walk($n[3], $stack, $ctx, $notes, $out); continue; }
        if ($empty) { $notes[] = ['dropped', "{{#{$n[1]}}} — ריק בפאנל, ולכן החלק הזה לא נכנס לקובץ"]; continue; }
        if (is_array($v) && array_is_list($v)) foreach ($v as $item) mg_walk($n[3], [...$stack, $item], $ctx, $notes, $out);
        else mg_walk($n[3], [...$stack, $v], $ctx, $notes, $out);
    }
}

/** התבנית ← גוף העמוד. $css — כללים שיצאו מ-style="" של הכלי (התמונה, רוחב המבוא). */
function mg_body(string $tpl, array $ctx, array &$notes, array &$css, string $heroRef): string
{
    // 1. style="" שהכלי כתב על הפס ועל המבוא ← כללים רגילים ב-css/page.css
    $heroFrag = "{{#hero}} style=\"background-image:url('{{hero}}'); --ka-hero-overlay:{{hero_overlay}};\"{{/hero}}";
    if (str_contains($tpl, $heroFrag)) {
        $tpl = str_replace($heroFrag, '', $tpl);
        if ($heroRef !== '') $css[] = ".kadisha-lexicon-page .kadisha-hero.kadisha-hero--has-image {\n    background-image: url('$heroRef');\n    --ka-hero-overlay: {$ctx['hero_overlay']};\n}";
    } elseif ($ctx['hero'] !== '') $notes[] = ['changed', 'ה-style של הפס שונה בתבנית שלך — נשאר כ-style על האלמנט, כפי שכתבת'];
    if (str_contains($tpl, ' style="{{intro_style}}"')) {
        $tpl = str_replace(' style="{{intro_style}}"', '', $tpl);
        if ($ctx['intro_max_width'] > 0) $css[] = ".kadisha-lexicon-page .kadisha-lexicon-intro {\n    max-width: {$ctx['intro_max_width']}px;\n}";
    }
    // 2. המונה: שני המספרים נקשרים לרשימה; המילים שביניהם נשארות טקסט רגיל
    foreach (['shown' => '{shown}', 'total' => '{total}'] as $id => $spec) {
        $tpl = preg_replace('~(<[a-zA-Z][^<>]*\bid="kadisha-lex-' . $id . '"[^<>]*)>\s*\{\{count\}\}~', '$1 data-kol-count="' . MG_LIST . '" data-kol-count-text="' . $spec . '">' . $ctx['count'], $tpl);
    }
    $out = '';
    mg_walk(tpl_tree($tpl), [$ctx], $ctx, $notes, $out);
    // 3. הקשירות שה-JS הישן מצא לפי id — עכשיו על האלמנט עצמו
    $add = function (string $re, string $attrs) use (&$out, &$notes): void {
        $n = 0;
        $out = preg_replace_callback($re, fn($m) => str_contains($m[0], preg_split('/[\s=]/', $attrs)[0]) ? $m[0] : $m[1] . ' ' . $attrs . $m[2], $out, -1, $n);
    };
    $add('~(<input\b[^<>]*\bid="kadisha-lex-search"[^<>]*?)(/?>)~', 'data-kol-search="' . MG_LIST . '" data-kol-search-fields="word"');
    $add('~(<input\b[^<>]*\bid="kadisha-lex-scope-full"[^<>]*?)(/?>)~', 'data-kol-search-scope="' . MG_LIST . '" data-kol-search-fields="word meaning topics"');
    $add('~(<[a-zA-Z][^<>]*\bdata-topic=""[^<>]*?)(>)~', 'data-kol-filter-value=""');
    $out = preg_replace('~(<[a-zA-Z][^<>]*\bid="kadisha-lex-no-matches"[^<>]*?)\s+style="display:\s*none;?"([^<>]*>)~', '$1 data-kol-empty="' . MG_LIST . '" hidden$2', $out);
    $add('~(<[a-zA-Z][^<>]*\bclass="[^"]*\bkadisha-word-audio-btn\b[^"]*"[^<>]*?)(>)~', 'data-kol-play data-kol-active-class="is-playing"');
    $add('~(<[a-zA-Z][^<>]*\bclass="[^"]*\bkadisha-word-feedback-btn\b[^"]*"[^<>]*?)(>)~', 'data-kol-open="feedback"');
    $add('~(<[a-zA-Z][^<>]*\bid="kadisha-lex-feedback-modal"[^<>]*?)(>)~', 'data-kol-dialog="feedback"');
    $add('~(<[a-zA-Z][^<>]*\bdata-klf-close="1"[^<>]*?)(>)~', 'data-kol-close');
    $add('~(<form\b[^<>]*\bid="kadisha-lex-feedback-form"[^<>]*?)(>)~', 'data-kol-form="feedback" data-kol-form-done="' . MG_FORM_DONE . '"');
    $add('~(<[a-zA-Z][^<>]*\bid="kadisha-lex-feedback-status"[^<>]*?)(>)~', 'data-kol-form-status');
    $add('~(<[a-zA-Z][^<>]*\bid="kadisha-lex-feedback-word-name"[^<>]*?)(>)~', 'data-kol-text="word"');
    if (str_contains($out, '{{')) $notes[] = ['failed', 'נשאר בקובץ תחביר {{…}} שלא הומר — חפשו "{{" ב-index.html'];
    return $out;
}

// ── ה-CSS ─────────────────────────────────────────────────

/** מה שהכלי כפה ב-!important (LEX_TPL_SIZES) ← כללים רגילים. מחזיר [css, כמה !important הוסרו]. */
function mg_plain_css(string $css): array
{
    $css = preg_replace('~\s*!important~i', '', $css, -1, $n);
    $css = preg_replace('~^[^{}\n]*\{\s*\}\s*\n?~m', '', $css);            // כלל שנשאר ריק (גובה פס שלא הוגדר)
    return [trim($css), $n];
}

/** משתני :root של ערכת הנושא ← css/tokens.css (אותם ערכים; מכאן משנים אותם במקום אחד). */
function mg_tokens(string ...$sheets): string
{
    $vars = [];
    foreach ($sheets as $css) {
        if (!preg_match_all('~(?:^|[}\s,])(?::root|html)\s*\{([^{}]*)\}~', $css, $m)) continue;
        foreach ($m[1] as $block) {
            if (preg_match_all('~(--[\w-]+)\s*:\s*([^;{}]+);?~', preg_replace('~/\*[\s\S]*?\*/~', '', $block), $d, PREG_SET_ORDER)) {
                foreach ($d as $x) $vars[$x[1]] = trim($x[2]);
            }
        }
    }
    $out = "/* משתנים: הצבעים, הגופנים והמידות של העמוד.\n   הערכים הועתקו מ-css/theme-style.css ומ-css/lexicon.css; הקובץ הזה נטען אחריהם, ולכן מה שמשנים כאן — קובע. */\n:root {\n";
    foreach ($vars as $k => $v) $out .= "  $k: $v;\n";
    return $out . ($vars ? '' : "  /* לא נמצאו משתנים ב-CSS של ערכת הנושא */\n") . "}\n";
}

/** כללים של ערכת הנושא שיש בהם !important על תכונה שגם הפאנל קובע — שם ה-CSS הרגיל שלנו עלול להפסיד. */
function mg_conflicts(string $ours, string $theme): array
{
    $out = [];
    if (!preg_match_all('~([^{}@]+)\{([^{}]*)\}~', $ours, $rules, PREG_SET_ORDER)) return [];
    preg_match_all('~([^{}@]+)\{([^{}]*!important[^{}]*)\}~i', $theme, $imp, PREG_SET_ORDER);
    foreach ($rules as $r) {
        if (!preg_match_all('~\.(kadisha-[\w-]+)~', $r[1], $cls) || !preg_match_all('~([\w-]+)\s*:~', $r[2], $props)) continue;
        foreach ($imp as $t) {
            foreach (array_unique($cls[1]) as $c) {
                if (!preg_match('~\.' . preg_quote($c, '~') . '(?![\w-])~', $t[1])) continue;
                foreach (array_unique($props[1]) as $p) {
                    if (preg_match('~(?:^|[;\s])' . preg_quote($p, '~') . '\s*:[^;]*!important~i', $t[2])) $out[".$c · $p"] = true;
                }
            }
        }
    }
    return array_keys($out);
}

// ── הבנייה ────────────────────────────────────────────────

/** כל קובצי הפרויקט המוסב + הדוח. אינה כותבת דבר. $hero — ['ref' => הנתיב שב-CSS, 'file_id' => מזהה בספרייה או '']. */
function mg_build(): array
{
    [$p, $title, $hasWidget] = mg_props();
    $src = mg_sources();
    $notes = [];
    $words = words_rows();
    $map = topic_slugs();
    $topics = topics_used($words, $map);
    $heroFile = $p['hero_file'] ? (files_meta([$p['hero_file']])[$p['hero_file']] ?? null) : null;
    $heroExt = $heroFile ? (strtolower(pathinfo((string)($heroFile['name'] ?? ''), PATHINFO_EXTENSION)) ?: 'jpg') : '';
    $heroRef = $heroFile ? "../media/hero.$heroExt" : $p['hero_url'];
    $ctx = lexicon_context($p, [], $topics, $map, fn(string $id) => '', $heroRef, false);
    $ctx['count'] = count($words);
    $ctx['empty'] = !$words;
    $ctx['words'] = $words ? [['x' => 1]] : [];                         // רק "יש / אין"; הכרטיסים באים מהנתונים
    $ctx['preview_note'] = '';

    $css = [];
    $body = mg_body($src['html'][0], $ctx, $notes, $css, $heroRef);

    // ה-CSS: עד הסימון — של ערכת הנושא, כמו שהוא; אחריו — הגדלים של הפאנל, בלי !important
    [$lexCss, $sizes] = array_pad(explode(LEX_CSS_MARK, $src['css'][0], 2), 2, null);
    if ($sizes === null) {
        $notes[] = ['changed', 'ב-lexicon.css שלך אין הסימון "הגדלים מהפאנל" — הקובץ כולו נכנס ל-css/lexicon.css, והגדלים של הפאנל נכתבו מחדש ב-css/page.css'];
        $sizes = LEX_TPL_SIZES;
    }
    $lexCss = rtrim($lexCss) . "\n";
    if (str_contains($lexCss, '{{')) { $lexCss = tpl_render($lexCss, $ctx); $notes[] = ['changed', 'ב-lexicon.css היו ביטויי {{…}} — הוחלפו בערכים הנוכחיים']; }
    [$sizeCss, $removed] = mg_plain_css(tpl_render($sizes, $ctx));
    $theme = theme_style_css();
    $pageCss = "/* סגנון לפי מחלקה — היעד הרגיל של העורך החזותי.\n   הכללים כאן הם ערכי הפאנל של היום (כותרות, גדלים, משקל, תמונה, רוחב המבוא), ככללים רגילים — בלי הכפייה שהכלי הוסיף להם קודם. */\n"
        . ($css ? implode("\n", $css) . "\n" : '') . $sizeCss . "\n";
    foreach (mg_conflicts($sizeCss . "\n" . implode("\n", $css), $theme . "\n" . $lexCss) as $c) {
        $notes[] = ['warn', "ב-CSS של ערכת הנושא יש !important על $c — הערך שב-css/page.css עלול לא לחול. לבדוק בתצוגה החיה"];
    }
    $baseCss = "/* סגנון לפי תגית — ברירות המחדל של העמוד.\n   באתר, את אלה נתנה ערכת הנושא לכל העמודים; כאן העמוד עומד לבדו, ולכן הם כתובים במפורש. */\n"
        . "*, *::before, *::after { box-sizing: border-box; }\nbody { margin: 0; line-height: 1.6; }\nimg { max-width: 100%; height: auto; }\n"
        . "/* אלמנט עם התכונה hidden אינו מוצג, גם אם למחלקה שלו יש display משלה */\n[hidden] { display: none !important; }\n"
        . "/* טקסט לקוראי מסך בלבד (המחלקה של וורדפרס) */\n.screen-reader-text { border: 0; clip: rect(1px, 1px, 1px, 1px); clip-path: inset(50%); height: 1px; margin: -1px; overflow: hidden; padding: 0; position: absolute; width: 1px; word-wrap: normal; }\n";

    $t = e($title !== '' ? $title : ($p['title_he'] ?: 'קול מרוקו'));
    $desc = e(trim($p['subtitle_main_he'] . ' ' . $p['subtitle_he']));
    $index = "<!doctype html>\n<html lang=\"he\" dir=\"rtl\">\n<head>\n  <meta charset=\"utf-8\">\n  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . "  <title>$t</title>\n" . ($desc !== '' ? "  <meta name=\"description\" content=\"$desc\">\n" : '')
        . "  <link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n  <link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n"
        . '  <link rel="stylesheet" href="' . e(LEX_FONTS) . "\">\n"
        . "  <link rel=\"stylesheet\" href=\"css/base.css\">\n  <link rel=\"stylesheet\" href=\"css/theme-style.css\">\n  <link rel=\"stylesheet\" href=\"css/lexicon.css\">\n"
        . "  <link rel=\"stylesheet\" href=\"css/tokens.css\">\n  <link rel=\"stylesheet\" href=\"css/page.css\">\n</head>\n<body>\n"
        . rtrim($body) . "\n<script src=\"js/kol-data.js\"></script>\n<script src=\"js/page.js\"></script>\n</body>\n</html>\n";

    $kolData = (string)file_get_contents(MG_SEED . 'kol-data.js');
    preg_match('~version:\s*\'([\d.]+)\'~', $kolData, $ver);
    $kol = [
        'version' => 1, 'entry' => 'index.html', 'pages' => 'auto',
        'breakpoints' => [['id' => 'tablet', 'label' => 'טאבלט', 'max' => 1024, 'preview' => 820], ['id' => 'mobile', 'label' => 'טלפון', 'max' => 767, 'preview' => 390]],
        'styles' => ['tokens' => 'css/tokens.css', 'base' => 'css/base.css', 'page' => 'css/page.css'],
        'data' => ['words' => ['file' => 'data/words.json', 'source' => 'words']],
        'components' => 'components/',
        'system' => ['js/kol-data.js' => ['version' => $ver[1] ?? '1.0.0', 'modified' => false]],
    ];
    $files = [
        'index.html' => $index,
        'css/base.css' => $baseCss,
        'css/theme-style.css' => $theme,
        'css/lexicon.css' => $lexCss,
        'css/tokens.css' => mg_tokens($theme, $lexCss),
        'css/page.css' => $pageCss,
        'js/kol-data.js' => $kolData,
        'js/page.js' => (string)file_get_contents(MG_SEED . 'page.js'),
        'js/lexicon.js' => "/* עותק לעיון בלבד: ה-JS של ערכת הנושא (lexicon.js). הקובץ הזה אינו נטען בעמוד —\n   ההתנהגות עברה ל-js/kol-data.js ול-js/page.js. אפשר למחוק אותו. */\n" . $src['js'][0],
        'kol.json' => json_encode($kol, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
    ];

    // מה נלקח מאיפה — מוצג לבעל העמוד
    $taken = [
        ['index.html', $src['html'][1] ? 'מהגרסה שלך של lexicon.html (לשונית </> קוד)' : 'מהתבנית המובנית של המילון — לא שינית את lexicon.html'],
        ['css/lexicon.css', $src['css'][1] ? 'מהגרסה שלך של lexicon.css' : 'מ-lexicon.css של ערכת הנושא, כמו שהגיע בייבוא'],
        ['css/theme-style.css', 'style.css של ערכת הנושא, כמו שהגיע בייבוא' . ($theme === '' ? ' — ריק: הייבוא מהאתר עוד לא נעשה' : '')],
        ['css/page.css', "ערכי הפאנל של היום, ככללים רגילים — הוסרו $removed מופעים של !important"],
        ['js/lexicon.js', ($src['js'][1] ? 'הגרסה שלך של lexicon.js' : 'lexicon.js של ערכת הנושא') . ' — עותק לעיון, לא נטען'],
        ['הטקסטים והגדלים', $hasWidget ? 'מרכיב המילון שבטיוטה' : 'ברירות המחדל — בטיוטה אין רכיב מילון'],
    ];
    if ($heroFile) $taken[] = ['media/hero.' . $heroExt, 'תמונת הרקע שהועלתה לכלי — הפניה לספרייה'];
    elseif ($p['hero_url'] !== '') $taken[] = ['תמונת הרקע', 'באתר (טרם הובאה): ' . $p['hero_url'] . ' — הכתובת כתובה ב-css/page.css'];
    $taken[] = ['data/words.json', count($words) . ' מילים, ' . count($topics) . ' נושאים — מוגש מטבלת המילים (לקריאה בלבד עד N1); ההקלטות בכתובות שלהן'];

    $notes = array_values(array_unique($notes, SORT_REGULAR));
    return ['files' => $files, 'media' => $heroFile ? ['media/hero.' . $heroExt => $heroFile['id']] : [], 'taken' => $taken, 'notes' => $notes,
        'counts' => ['words' => count($words), 'topics' => count($topics), 'important_removed' => $removed]];
}

// ── הפעולות ───────────────────────────────────────────────

/** כל מה שחי בפרויקט (חוץ מ-from-current/) עובר לסל, באצווה אחת. */
function mg_trash_all(array $user, string $batchNote): int
{
    $rows = db()->query("SELECT id FROM pf_files WHERE deleted_at IS NULL AND path_key NOT LIKE 'from-current/%' AND path_key != 'from-current'")->fetchAll();
    $batch = bin2hex(random_bytes(6));
    $st = db()->prepare('UPDATE pf_files SET deleted_at = ?, deleted_by = ?, deleted_name = ?, deleted_batch = ? WHERE id = ?');
    foreach ($rows as $r) $st->execute([now_iso(), $user['sub'], $batchNote, $batch, $r['id']]);
    return count($rows);
}

/** "צור פרויקט מהעמוד של היום". בעלים בלבד; נקודת שחזור לפני; הפרויקט הקודם — לסל. */
function pf_migrate(array $owner, array $b): array
{
    pf_seed($owner);
    pf_guard($owner);
    $built = mg_build();
    if (($b['dry'] ?? false) === true) return ['dry' => true] + mg_report($built, 0, 0);
    $snap = snapshot_create($owner, 'לפני "צור פרויקט מהעמוד של היום"');
    $trashed = pf_tx(function () use ($built, $owner) {
        $n = mg_trash_all($owner, 'הפרויקט הקודם — לפני ההסבה');
        foreach ($built['files'] as $path => $bytes) pf_insert($path, 'file', $bytes, $owner, null, 'נוצר בהסבה מהעמוד של היום');
        foreach ($built['media'] as $path => $fileId) pf_insert($path, 'media', '', $owner, $fileId, 'נוצר בהסבה מהעמוד של היום');
        return $n;
    });
    $report = mg_report($built, $snap, $trashed);
    setting_set('pf_migrate_last', (string)json_encode(['at' => now_iso(), 'by' => $owner['name'] ?? ''] + $report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    audit_as($owner, 'pf_migrate', count($built['files']) . ' קבצים · ' . count($built['notes']) . ' הערות');
    return $report;
}

function mg_report(array $built, int $snap, int $trashed): array
{
    return ['files' => array_merge(array_keys($built['files']), array_keys($built['media'])), 'taken' => $built['taken'], 'notes' => $built['notes'],
        'counts' => $built['counts'], 'snapshot' => $snap, 'trashed' => $trashed];
}

/** הדוח של ההסבה האחרונה (למסך), או null. */
function pf_migrate_last(): array
{
    return ['last' => json_decode((string)setting('pf_migrate_last', 'null'), true)];
}

/** "פרויקט ריק": הפרויקט הקודם לסל, ובמקומו שלד ברירת המחדל. בעלים בלבד; נקודת שחזור לפני. */
function pf_blank(array $owner): array
{
    pf_seed($owner);
    pf_guard($owner);
    $snap = snapshot_create($owner, 'לפני "פרויקט ריק"');
    $files = array_filter(pf_seed_files(), fn($p) => !str_starts_with($p, 'from-current/'), ARRAY_FILTER_USE_KEY);
    $trashed = pf_tx(function () use ($files, $owner) {
        $n = mg_trash_all($owner, 'הפרויקט הקודם — לפני "פרויקט ריק"');
        foreach ($files as $path => $bytes) pf_insert($path, 'file', $bytes, $owner, null, 'נוצר כפרויקט ריק');
        return $n;
    });
    audit_as($owner, 'pf_blank', count($files) . ' קבצים');
    return ['files' => array_keys($files), 'snapshot' => $snap, 'trashed' => $trashed];
}
