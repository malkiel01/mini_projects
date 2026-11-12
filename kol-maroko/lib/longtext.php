<?php
/**
 * טקסטים ארוכים — בעורך התמלילים (platform/docs/embed.md, word-processor/docs/integration.md).
 *
 * המסמך עצמו בעורך; אצלנו — רק המזהה (ברכיב longtext) והעותק האחרון של ה-HTML וה-CSS (טבלה longtexts), שנשלפים
 * מהעורך בשרת (doc_export) אחרי כל שמירה. הדפדפן לא שולח לנו HTML או CSS של מסמך — כך מה שנכנס לעמוד מגיע רק
 * מהעורך, ועובר גם כאן סינון: בלי סקריפטים, בלי on*, בלי javascript:, ו-CSS בלי url() / @import.
 *
 * WordProcessorClient.php — עותק של word-processor/client/ (לא require מבחוץ). המפתח הפרטי — בטבלה wp_embed_keys
 * במסד שלנו (מוחרג בפריסה).
 */

declare(strict_types=1);

require_once __DIR__ . '/draft.php';
require_once __DIR__ . '/WordProcessorClient.php';
require_once __DIR__ . '/project.php';

if (!defined('KOL_WP_URL')) define('KOL_WP_URL', WordProcessorClient::EDITOR);
const KOL_SELF = 'https://mbe-plus.com/mini_projects/kol-maroko/';

function wp(): WordProcessorClient
{
    static $wp = null;
    return $wp ??= new WordProcessorClient(db(), 'kol-maroko', 'קול מרוקו', KOL_SELF . 'api.php?action=embed_key',
        ['https://mbe-plus.com'], KOL_WP_URL);
}

/** עורך התמלילים. בבדיקות — $GLOBALS['kol_wp_fake'] (אובייקט עם embedSession / export / import), בלי רשת. */
function lt_wp(): object
{
    return $GLOBALS['kol_wp_fake'] ?? wp();
}

function wp_try(callable $fn): mixed
{
    try {
        return $fn();
    } catch (WordProcessorError $e) {
        $msg = match ($e->codeName) {
            'pending' => 'קול מרוקו נרשם בעורך התמלילים וממתין לאישור המנהל שלו',
            'offline' => 'עורך התמלילים לא זמין כרגע. נסו שוב בעוד רגע',
            default => 'עורך התמלילים: ' . $e->getMessage(),
        };
        throw new ApiError($msg, $e->status >= 400 && $e->status < 600 ? ($e->status === 401 ? 502 : $e->status) : 502, ['code' => $e->codeName]);
    }
}

function doc_id(mixed $v): string
{
    return is_string($v) && preg_match('/^[A-Za-z0-9:_-]{1,40}$/', $v) ? $v : '';
}

/**
 * כתובת ל-iframe. עורך שמחזיק בנעילת העמוד — עריכה; אחרים — צפייה. $doc ריק — מסמך חדש (המזהה חוזר, והדפדפן
 * שם אותו ברכיב).
 */
function editor_session(array $user, array $b): array
{
    $doc = doc_id($b['doc'] ?? '');
    $l = lock_state();
    $can = $l && $l['sub'] === $user['sub'] ? 'edit' : 'view';
    if ($doc === '' && $can !== 'edit') throw new ApiError('בעריכה אצל מישהו אחר', 423);
    $s = wp_try(fn() => wp()->embedSession($doc ?: null, ['id' => $user['sub'], 'name' => $user['name'] ?: 'עורך'], $can,
        'document', null, 'קול מרוקו — טקסט'));
    return ['embed_url' => $s['embed_url'], 'doc' => $s['doc'], 'can' => $can, 'host_js' => KOL_WP_URL . 'assets/js/host.js'];
}

/** אחרי שמירה בעורך: מושכים את ה-HTML וה-CSS, מסננים ושומרים. עם path — לאזור בקובץ (X1); בלי — רכיב הטיוטה הישנה (עד M6). */
function longtext_sync(array $user, array $b): array
{
    if (isset($b['path'])) return lt_sync_file($user, $b);
    $doc = doc_id($b['doc'] ?? '');
    if ($doc === '') throw new ApiError('מזהה מסמך לא תקין');
    $h = wp_try(fn() => lt_wp()->export($doc, 'html'));
    $html = sanitize_doc((string)($h['html'] ?? ''));
    $css = sanitize_css((string)($h['css'] ?? ''));
    db()->prepare('INSERT INTO longtexts (doc, html, css, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)
                   ON CONFLICT(doc) DO UPDATE SET html = excluded.html, css = excluded.css, updated_at = excluded.updated_at, updated_by = excluded.updated_by')
        ->execute([$doc, $html, $css, now_iso(), $user['sub']]);
    return ['doc' => $doc, 'chars' => mb_strlen(strip_tags($html))];
}

function longtexts_get(array $docs): array
{
    if (!$docs) return [];
    $st = db()->prepare('SELECT * FROM longtexts WHERE doc IN (' . implode(',', array_fill(0, count($docs), '?')) . ')');
    $st->execute(array_values($docs));
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['doc']] = $r;
    return $out;
}

/**
 * HTML של מסמך מהעורך — ניקוי לפי **רשימה מותרת** (X1 §2.3): רק התגיות והתכונות שכאן נשארות. תגית שאינה ברשימה — נפרקת (התוכן
 * נשאר כטקסט / כילדים); תגית מסוכנת — נמחקת עם התוכן שלה. הערות, data-*, id, on*, style עם url / expression — לא נשארים;
 * href רק https / mailto / tel / #, src רק https. התוצאה נכתבת כמו שהיא ל-HTML של הפרויקט.
 */
const LT_TAGS = ['p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'small', 'mark', 'span', 'div',
    'section', 'article', 'header', 'footer', 'aside', 'blockquote', 'q', 'cite', 'code', 'pre', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'a', 'img',
    'figure', 'figcaption', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col', 'abbr', 'bdi', 'bdo', 'time'];
const LT_DROP = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'textarea', 'select', 'option',
    'link', 'meta', 'base', 'svg', 'math', 'template', 'noscript', 'title', 'head', 'audio', 'video', 'source', 'track', 'canvas', 'portal', 'xmp', 'plaintext', 'noembed', 'noframes'];
const LT_ATTRS = ['*' => ['class', 'style', 'dir', 'lang', 'title'], 'a' => ['href', 'rel'], 'img' => ['src', 'alt', 'width', 'height'],
    'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan', 'scope'], 'ol' => ['start', 'type', 'reversed'], 'col' => ['span'], 'colgroup' => ['span'],
    'time' => ['datetime'], 'abbr' => [], 'bdo' => []];

function sanitize_doc(string $html): string
{
    if (trim($html) === '') return '';
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    // בלי מכל עוטף: </div> עודף בקלט לא "יוצא" ממנו — הוא פשוט מתעלם ב-<body>
    $doc->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementsByTagName('body')->item(0);
    if (!$root) return '';
    $walk = function (DOMNode $n) use (&$walk) {
        foreach (iterator_to_array($n->childNodes) as $c) {
            if ($c instanceof DOMText && !$c instanceof DOMCdataSection) continue;
            if (!$c instanceof DOMElement) { $n->removeChild($c); continue; }
            $tag = strtolower($c->tagName);
            if (in_array($tag, LT_DROP, true)) { $n->removeChild($c); continue; }
            $walk($c);
            if (!in_array($tag, LT_TAGS, true)) {                // לא ברשימה — נפרקת: הילדים (כבר נקיים) נשארים במקומה
                while ($c->firstChild) $n->insertBefore($c->firstChild, $c);
                $n->removeChild($c);
                continue;
            }
            $ok = array_merge(LT_ATTRS['*'], LT_ATTRS[$tag] ?? []);
            foreach (iterator_to_array($c->attributes) as $a) {
                $name = strtolower($a->name);
                $v = trim($a->value);
                $keep = in_array($name, $ok, true)
                    && !($name === 'href' && !preg_match('~^(https://|mailto:|tel:|#)~i', $v))
                    && !($name === 'src' && !preg_match('~^https://~i', $v))
                    && !($name === 'style' && preg_match('/url\s*\(|expression|@import|javascript:|behavior\s*:|-moz-binding|[<>]/i', $v))
                    && !($name === 'class' && !preg_match('/^[\w\s-]*$/u', $v));
                if (!$keep) $c->removeAttribute($a->name);
            }
            if ($tag === 'a' && $c->hasAttribute('href') && preg_match('~^https://~i', $c->getAttribute('href'))) $c->setAttribute('rel', 'noopener noreferrer');
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return $out;
}

/** CSS של מסמך: חוקים על .wp-doc בלבד, בלי url(), @import ובלי יציאה מתגית style. */
function sanitize_css(string $css): string
{
    $css = str_ireplace(['</style', '<!--', '-->'], '', $css);
    if (preg_match('/url\s*\(|@import|expression\s*\(|javascript:|behavior\s*:|-moz-binding/i', $css)) {
        $css = preg_replace('/[^{}]*\{[^{}]*(url\s*\(|@import|expression\s*\(|javascript:|behavior\s*:|-moz-binding)[^{}]*\}/i', '', $css);
    }
    // X1: @import ברמה העליונה (מחוץ לסוגריים מסולסלים) — גם הוא, ושארית כלשהי של המילים האסורות
    $css = preg_replace('/@import\b[^;{}]*;?/i', '', $css);
    if (preg_match('/url\s*\(|@import|expression\s*\(|javascript:|behavior\s*:|-moz-binding/i', $css)) $css = '';
    return mb_substr($css, 0, 200_000);
}


// ── X1 §2.3: אזור מקושר בקובץ של הפרויקט ─────────────────
//
// <div data-kol-longtext="<doc>">…</div> — התוכן שבין התגיות הוא ה-HTML של המסמך, כתוב בקובץ. ה-CSS — css/longtext/<doc>.css, עם
// <link> ב-<head> של אותו עמוד. מה שנכתב בסנכרון האחרון — hash של תוכן האזור, ב-settings (lt_link_<doc>; בלי מיגרציה).
// אזור שנערך ביד מאז — לעולם לא נדרס בשקט: 409 עם שני הצדדים, ושלוש אפשרויות (דרוס / ייבא לעורך / בטל).

function lt_hash(string $inner): string
{
    return hash('sha256', $inner);
}

function lt_link(string $doc): ?array
{
    $l = json_decode((string)setting('lt_link_' . $doc, ''), true);
    return is_array($l) ? $l : null;
}

function lt_link_set(string $doc, string $path, string $inner): void
{
    setting_set('lt_link_' . $doc, (string)json_encode(['path' => $path, 'hash' => lt_hash($inner), 'at' => now_iso()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * האזור בקובץ: [תחילת התגית, תחילת התוכן, סוף התוכן, סוף התגית הסוגרת] או null. הסגירה — לפי ספירה של <div> / </div> מקוננים.
 */
function lt_region(string $html, string $doc): ?array
{
    $q = preg_quote($doc, '~');
    if (!preg_match('~<div\b[^>]*?\sdata-kol-longtext\s*=\s*(["\'])' . $q . '\1[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) return null;
    $open = $m[0][1];
    $in = $open + strlen($m[0][0]);
    $depth = 1;
    $pos = $in;
    while ($depth > 0 && preg_match('~<div\b[^>]*>|</div\s*>~i', $html, $t, PREG_OFFSET_CAPTURE, $pos)) {
        $depth += $t[0][0][1] === '/' ? -1 : 1;
        $pos = $t[0][1] + strlen($t[0][0]);
        if ($depth === 0) return [$open, $in, $t[0][1], $pos];
    }
    return null;
}

function lt_file(string $path): array
{
    $r = pf_by_path(pf_path($path));
    if (!$r || $r['kind'] !== 'file') throw new ApiError("\"$path\" לא נמצא בפרויקט", 404);
    return [$r, pf_blob_get($r['sha'])];
}

/** ה-CSS של המסמך: css/longtext/<doc>.css, ו-<link> אליו ב-<head> של העמוד — פעם אחת. מחזיר את ה-HTML (אולי עם link). */
function lt_css_link(string $page, string $html, string $doc): string
{
    $href = pf_rel($page, 'css/longtext/' . $doc . '.css');
    if (preg_match('~<link\b[^>]*href\s*=\s*(["\'])' . preg_quote($href, '~') . '\1~i', $html)) return $html;
    $tag = '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES) . '">';
    if (preg_match('~</head\s*>~i', $html, $m, PREG_OFFSET_CAPTURE)) return substr_replace($html, "  $tag\n", $m[0][1], 0);
    return $html;
}

/** כתיבת האזור + ה-CSS בעסקה אחת. גרסה לכל קובץ, "$note". */
function lt_write(array $user, array $r, string $html, string $doc, string $css, string $note): array
{
    return pf_tx(function () use ($user, $r, $html, $doc, $css, $note) {
        $cur = pf_row((int)$r['id']);
        if ((int)$cur['rev'] !== (int)$r['rev']) throw new ApiError("\"{$r['path']}\" השתנה בינתיים — שום דבר לא נכתב. נסו שוב", 409);
        $page = pf_put($cur, lt_css_link($r['path'], $html, $doc), $user, $note);
        $cp = 'css/longtext/' . $doc . '.css';
        $c = pf_by_path($cp);
        if ($c && $c['kind'] === 'file') pf_put($c, $css, $user, $note);
        elseif (!$c) { pf_free($cp); pf_insert($cp, 'file', $css, $user, null, $note); }
        return $page;
    });
}

/** "🔗 חבר למסמך קיים": רשימת המסמכים (שם, תאריך, 200 תווים ראשונים) ולאיזה קובץ כבר חוברו. */
function longtexts_list(): array
{
    $out = [];
    foreach (db()->query('SELECT doc, html, updated_at FROM longtexts ORDER BY updated_at DESC')->fetchAll() as $r) {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($r['html']), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $l = lt_link($r['doc']);
        $out[] = ['doc' => $r['doc'], 'name' => mb_substr($text, 0, 40) ?: $r['doc'], 'at' => $r['updated_at'], 'preview' => mb_substr($text, 0, 200), 'path' => $l['path'] ?? null];
    }
    return ['docs' => $out];
}

/**
 * חיבור אלמנט קיים למסמך: {path, rev, old (ה-HTML של האלמנט, כמו בקובץ — בדיוק פעם אחת), doc}. עוטף ב-data-kol-longtext ושומר hash.
 * כך תוכן שההסבה (F3) כתבה כ-HTML רגיל נקשר שוב למסמך שלו, ושום מסמך אינו אובד.
 */
function longtext_link(array $user, array $b): array
{
    pf_guard($user);
    $doc = doc_id($b['doc'] ?? '');
    if ($doc === '' || !longtexts_get([$doc])) throw new ApiError('המסמך לא נמצא ברשימה', 404);
    [$r, $html] = lt_file((string)($b['path'] ?? ''));
    if ((int)($b['rev'] ?? 0) !== (int)$r['rev']) throw new ApiError('הקובץ השתנה בינתיים', 409, ['conflict' => ['file' => pf_public($r), 'content' => $html]]);
    if (lt_region($html, $doc)) throw new ApiError('המסמך כבר מחובר לאזור בקובץ הזה', 409);
    $old = (string)($b['old'] ?? '');
    $n = $old === '' ? 0 : substr_count($html, $old);
    if ($n !== 1) throw new ApiError($n === 0 ? 'סמנו בקובץ את האלמנט לחיבור' : "הקטע שסומן מופיע $n פעמים — סמנו קטע ייחודי", 422);
    $html = substr_replace($html, '<div data-kol-longtext="' . $doc . '">' . $old . '</div>', strpos($html, $old), strlen($old));
    $css = (string)(longtexts_get([$doc])[$doc]['css'] ?? '');
    $page = lt_write($user, $r, $html, $doc, $css, '🔗 חובר למסמך ' . $doc);
    lt_link_set($doc, $r['path'], $old);
    audit_as($user, 'longtext_link', "{$r['path']} ← $doc");
    return ['file' => pf_public($page), 'doc' => $doc];
}

/**
 * "📝 ערוך בעורך התמלילים": {path, doc} — אזור קיים; {path} בלי doc — מסמך חדש, ואזור ריק בסוף <main> (או לפני </body>).
 * מחזיר את כתובת ה-iframe (embedSession, כמו היום).
 */
function longtext_open(array $user, array $b): array
{
    pf_guard($user);
    [$r, $html] = lt_file((string)($b['path'] ?? ''));
    $doc = doc_id($b['doc'] ?? '');
    if ($doc !== '' && !lt_region($html, $doc)) throw new ApiError("אין בקובץ אזור data-kol-longtext=\"$doc\"", 404);
    $s = wp_try(fn() => lt_wp()->embedSession($doc ?: null, ['id' => $user['sub'], 'name' => $user['name'] ?: 'עורך'], 'edit', 'document', null, 'קול מרוקו — טקסט'));
    $out = ['embed_url' => $s['embed_url'], 'doc' => $s['doc'], 'can' => 'edit', 'host_js' => KOL_WP_URL . 'assets/js/host.js', 'path' => $r['path']];
    if ($doc === '') {
        $doc = doc_id($s['doc'] ?? '') ?: throw new ApiError('עורך התמלילים לא החזיר מזהה מסמך', 502);
        $tag = '<div data-kol-longtext="' . $doc . '"></div>';
        if (preg_match_all('~</main\s*>~i', $html, $m, PREG_OFFSET_CAPTURE)) $at = end($m[0])[1];
        elseif (preg_match('~</body\s*>~i', $html, $m, PREG_OFFSET_CAPTURE)) $at = $m[0][1];
        else $at = strlen($html);
        // בשורה משלו, מוזח פנימה ביחס לתגית הסוגרת
        $ls = strrpos(substr($html, 0, $at), "\n");
        $ls = $ls === false ? 0 : $ls + 1;
        $indent = trim(substr($html, $ls, $at - $ls)) === '' ? substr($html, $ls, $at - $ls) : '';
        $html = $indent !== '' || $ls === $at ? substr_replace($html, "$indent  $tag\n", $ls, 0) : substr_replace($html, "\n  $tag\n", $at, 0);
        $page = lt_write($user, $r, $html, $doc, '', '📝 אזור חדש לעורך התמלילים');
        lt_link_set($doc, $r['path'], '');
        $out += ['created' => true, 'file' => pf_public($page)];
        $out['doc'] = $doc;
        audit_as($user, 'longtext_new', "{$r['path']} ← $doc");
    }
    return $out;
}

/**
 * סנכרון לקובץ: {doc, path, mode?}. מושך (export), מסנן, ובודק אם האזור נערך ביד מאז הסנכרון האחרון. אם כן ובלי mode — 409 עם
 * שני הצדדים. mode: "overwrite" (גרסת עורך התמלילים נכתבת) · "import" (התוכן שבקובץ נשלח לעורך התמלילים; הקובץ לא משתנה).
 */
function lt_sync_file(array $user, array $b): array
{
    pf_guard($user);
    $doc = doc_id($b['doc'] ?? '');
    if ($doc === '') throw new ApiError('מזהה מסמך לא תקין');
    [$r, $html] = lt_file((string)$b['path']);
    $reg = lt_region($html, $doc) ?? throw new ApiError("אין בקובץ אזור data-kol-longtext=\"$doc\" — אולי נמחק ביד", 404);
    $inner = substr($html, $reg[1], $reg[2] - $reg[1]);
    $link = lt_link($doc);
    $manual = $link !== null && !hash_equals((string)$link['hash'], lt_hash($inner));
    $mode = (string)($b['mode'] ?? '');
    if ($mode === 'import') {
        wp_try(fn() => lt_wp()->import($doc, 'html', sanitize_doc($inner)));
        lt_link_set($doc, $r['path'], $inner);
        audit_as($user, 'longtext_import', "{$r['path']} → $doc");
        return ['doc' => $doc, 'imported' => true, 'reopen' => true];
    }
    $h = wp_try(fn() => lt_wp()->export($doc, 'html'));
    $new = sanitize_doc((string)($h['html'] ?? ''));
    $css = sanitize_css((string)($h['css'] ?? ''));
    if ($manual && $mode !== 'overwrite') {
        audit_as($user, 'longtext_conflict', "{$r['path']} · $doc");
        throw new ApiError('האזור נערך ביד מאז הסנכרון האחרון — בחרו מה לעשות', 409, ['conflict' => ['doc' => $doc, 'path' => $r['path'], 'rev' => (int)$r['rev'],
            'file' => $inner, 'editor' => $new, 'options' => ['overwrite', 'import', 'cancel']]]);
    }
    $title = pf_label((string)($h['title'] ?? $doc), 60);
    $html = substr_replace($html, $new, $reg[1], $reg[2] - $reg[1]);
    $page = lt_write($user, $r, $html, $doc, $css, '📝 ' . $title);
    lt_link_set($doc, $r['path'], $new);
    db()->prepare('INSERT INTO longtexts (doc, html, css, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)
                   ON CONFLICT(doc) DO UPDATE SET html = excluded.html, css = excluded.css, updated_at = excluded.updated_at, updated_by = excluded.updated_by')
        ->execute([$doc, $new, $css, now_iso(), $user['sub']]);
    audit_as($user, 'longtext_sync', "{$r['path']} ← $doc" . ($manual ? ' (דריסה של עריכה ביד, באישור)' : ''));
    return ['doc' => $doc, 'file' => pf_public($page), 'chars' => mb_strlen(strip_tags($new)), 'overwrote' => $manual];
}
