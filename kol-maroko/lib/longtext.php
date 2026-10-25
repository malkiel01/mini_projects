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

if (!defined('KOL_WP_URL')) define('KOL_WP_URL', WordProcessorClient::EDITOR);
const KOL_SELF = 'https://mbe-plus.com/mini_projects/kol-maroko/';

function wp(): WordProcessorClient
{
    static $wp = null;
    return $wp ??= new WordProcessorClient(db(), 'kol-maroko', 'קול מרוקו', KOL_SELF . 'api.php?action=embed_key',
        ['https://mbe-plus.com'], KOL_WP_URL);
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

/** אחרי שמירה בעורך: מושכים את ה-HTML וה-CSS, מסננים ושומרים. */
function longtext_sync(array $user, array $b): array
{
    $doc = doc_id($b['doc'] ?? '');
    if ($doc === '') throw new ApiError('מזהה מסמך לא תקין');
    $h = wp_try(fn() => wp()->export($doc, 'html'));
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

/** HTML של מסמך מהעורך: נשאר כמעט כמו שהוא (מחלקות וסגנונות — העימוד של המסמך), רק בלי מה שמריץ קוד. */
function sanitize_doc(string $html): string
{
    if (trim($html) === '') return '';
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><body><div id="kol-root">' . $html . '</div></body>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('kol-root');
    if (!$root) return '';
    $drop = ['script', 'style', 'iframe', 'frame', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'base', 'svg', 'math', 'template', 'noscript'];
    $walk = function (DOMNode $n) use (&$walk, $drop) {
        foreach (iterator_to_array($n->childNodes) as $c) {
            if (!$c instanceof DOMElement) { if (!$c instanceof DOMText) $n->removeChild($c); continue; }
            if (in_array(strtolower($c->tagName), $drop, true)) { $n->removeChild($c); continue; }
            foreach (iterator_to_array($c->attributes) as $a) {
                $name = strtolower($a->name);
                $v = $a->value;
                $bad = str_starts_with($name, 'on') || in_array($name, ['srcdoc', 'formaction', 'xlink:href'], true)
                    || (in_array($name, ['href', 'src'], true) && !preg_match('~^(https://|mailto:|tel:|#)~i', trim($v)))
                    || ($name === 'style' && preg_match('/url\s*\(|expression|@import|javascript:/i', $v));
                if ($bad) $c->removeAttribute($a->name);
            }
            $walk($c);
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
    return mb_substr($css, 0, 200_000);
}
