<?php
/**
 * ייבוא העמוד הקיים מהאתר (החלטת בעל המערכת, 2026-10-25: "כל התוכן, ההקלטות והעיצוב — מה שהיה נשאר, רק
 * ניתן לשינוי"). לכלי אין גישה לאתר, ולכן הייבוא הוא קובץ: התוסף בוורדפרס מייצא ZIP (תוכן, CSS וכל הקבצים),
 * והבעלים מעלה אותו כאן.
 *
 * ההמרה שומרת את העיצוב כך: סביב כל פס, עמודה ורכיב נשמרות העטיפות המקוריות (המחלקות של אלמנטור / וורדפרס —
 * lib/legacy.php), ה-CSS של העמוד נשמר (imported_css), והתוכן בפנים הופך לרכיבים רגילים: כותרות, טקסט, תמונות,
 * הקלטות, כפתורים, וידאו, גלריות. מה שאין לו רכיב מתאים — "קטע מקורי" (raw), שמוצג בדיוק כמו באתר, ונערך כ-HTML.
 * קוד מוטמע (ווידג'ט HTML של אלמנטור) — רכיב קוד במסגרת מבודדת. הקבצים נכנסים לספרייה, וכל כתובת אליהם בתוכן
 * וב-CSS הופכת ל-kol-file:<מזהה>.
 */

declare(strict_types=1);

require_once __DIR__ . '/publish.php';

final class Importer
{
    private ZipArchive $zip;
    private array $meta;
    private string $origin;            // https://maghreb.co.il
    private array $uploadBases;        // כתובות הבסיס של uploads (עם ובלי www / http)
    private array $media = [];         // rel → id
    public array $stats = ['sections' => 0, 'widgets' => [], 'files' => 0, 'raw' => 0, 'missing' => []];
    private array $inlineCss = [];

    public function __construct(private array $user, string $zipPath)
    {
        $this->zip = new ZipArchive();
        if ($this->zip->open($zipPath) !== true) throw new ApiError('הקובץ אינו ZIP תקין');
        $meta = json_decode((string)$this->zip->getFromName('meta.json'), true);
        if (!is_array($meta) || !in_array((int)($meta['format'] ?? 0), [1, 2], true) || $this->zip->locateName('content.html') === false) {
            throw new ApiError('זה לא קובץ ייצוא של קול מרוקו (חסרים meta.json / content.html)');
        }
        $this->meta = $meta;
        $u = parse_url((string)($meta['site'] ?? ''));
        $this->origin = 'https://' . ($u['host'] ?? site_host());
        $up = preg_replace('~^https?://(www\.)?~i', '', rtrim((string)($meta['uploads'] ?? ''), '/'));
        $this->uploadBases = $up ? [$up] : [];
    }

    // ── קבצים ─────────────────────────────────────────────

    /** כתובת → kol-file:<id> אם היא קובץ מתוך ה-ZIP; אחרת null. */
    public function file(string $url): ?string
    {
        $id = $this->fileId($url);
        return $id ? "kol-file:$id" : null;
    }

    public function fileId(string $url): ?string
    {
        $u = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5);
        $u = preg_replace('/[?#].*$/', '', $u);
        $bare = preg_replace('~^(https?:)?//(www\.)?~i', '', $u);
        $rel = null;
        foreach ($this->uploadBases as $b) if (stripos($bare, $b . '/') === 0) $rel = rawurldecode(substr($bare, strlen($b) + 1));
        if ($rel === null || str_contains($rel, '..')) return null;
        if (array_key_exists($rel, $this->media)) return $this->media[$rel];
        $entry = 'media/' . $rel;
        $st = $this->zip->statName($entry);
        if (!$st || $st['size'] > IMPORT_FILE_MAX) { $this->media[$rel] = null; $this->stats['missing'][] = $rel; return null; }
        $dir = files_dir() . '/tmp';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $tmp = $dir . '/imp-' . bin2hex(random_bytes(8));
        $in = $this->zip->getStream($entry);
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        try {
            $id = file_store($tmp, basename($rel), $this->user);
            $this->stats['files']++;
        } catch (ApiError $e) {
            $id = null;
            $this->stats['missing'][] = $rel . ' (' . $e->getMessage() . ')';
        }
        return $this->media[$rel] = $id;
    }

    /** כל כתובת לקובץ ב-HTML / CSS → kol-file. קישורים יחסיים → מלאים לאתר. */
    public function rewriteHtmlUrls(DOMElement $root): void
    {
        foreach ([$root, ...iterator_to_array($root->getElementsByTagName('*'))] as $el) {
            /** @var DOMElement $el */
            foreach (['data-src', 'data-lazy-src', 'data-original'] as $lazy) {
                if ($el->hasAttribute($lazy) && in_array(strtolower($el->tagName), ['img', 'source', 'iframe'], true)) {
                    $el->setAttribute('src', $el->getAttribute($lazy));
                    $el->removeAttribute($lazy);
                }
            }
            foreach (['src', 'href', 'poster'] as $attr) {
                if (!$el->hasAttribute($attr)) continue;
                $v = trim($el->getAttribute($attr));
                if ($v === '' || $v[0] === '#' || preg_match('~^(mailto|tel):~i', $v)) continue;
                if (str_starts_with($v, '//')) $v = 'https:' . $v;
                elseif (str_starts_with($v, '/')) $v = $this->origin . $v;
                elseif (str_starts_with($v, 'http://')) $v = 'https://' . substr($v, 7);
                // תמונה, שמע ווידאו — לספרייה. קישור (href) לקובץ — נשאר לאתר (הקובץ קיים שם)
                if ($attr !== 'href' && ($f = $this->file($v))) $v = $f;
                $el->setAttribute($attr, $v);
            }
            if ($el->hasAttribute('style')) {
                $el->setAttribute('style', $this->rewriteCssUrls($el->getAttribute('style')));
            }
            if ($el->hasAttribute('class')) {
                // אנימציות כניסה של אלמנטור מסתירות עד שה-JS שלו רץ — אצלנו הוא לא רץ
                $c = preg_replace('/\b(elementor-invisible|animated|e-lazyload)\b/', '', $el->getAttribute('class'));
                $el->setAttribute('class', trim(preg_replace('/\s+/', ' ', $c)));
            }
            $el->removeAttribute('srcset');
            $el->removeAttribute('sizes');
        }
    }

    public function rewriteCssUrls(string $css): string
    {
        return preg_replace_callback('~url\(\s*(["\']?)([^)"\']+)\1\s*\)~i', function ($m) {
            $u = trim($m[2]);
            if (str_starts_with($u, '//')) $u = 'https:' . $u;
            elseif (str_starts_with($u, '/')) $u = $this->origin . $u;
            $f = $this->file($u);
            return 'url("' . ($f ?? $u) . '")';
        }, $css);
    }

    // ── ההמרה ─────────────────────────────────────────────

    public function run(): array
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body><div id="kol-import-root">' . $this->zip->getFromName('content.html') . '</div></body>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('kol-import-root');
        // <style> בתוך התוכן (CSS של ווידג'טים באלמנטור) — לעיצוב שיובא. הערות — החוצה
        foreach (iterator_to_array($root->getElementsByTagName('style')) as $st) { $this->inlineCss[] = $st->textContent; $st->parentNode->removeChild($st); }
        $xp = new DOMXPath($doc);
        foreach (iterator_to_array($xp->query('//comment()')) as $c) $c->parentNode->removeChild($c);

        $settings = ['title' => (string)($this->meta['title'] ?? 'קול מרוקו'), 'font' => 'theme', 'width' => 'boxed'];
        // מסמכי אלמנטור עליונים (תבנית של העמוד, העמוד עצמו…) — כל אחד עוטף את הפסים שלו, כדי שהבוררים של
        // ה-CSS (.elementor-123 .elementor-element-…) ימשיכו לחול
        $docs = [];
        foreach ($xp->query('.//*[@data-elementor-type]', $root) as $d) {
            for ($p = $d->parentNode; $p instanceof DOMElement && $p !== $root; $p = $p->parentNode) if ($p->hasAttribute('data-elementor-type')) continue 2;
            $docs[] = $d;
        }
        if ($docs) {
            $this->rewriteHtmlUrls($root);
            $sections = [];
            foreach ($docs as $d) {
                foreach ($this->elementorSections($d) as $s) {
                    $s['legacy'] = [$this->tag($d), ...$s['legacy']];
                    $sections[] = $s;
                }
            }
        } else {
            $this->rewriteHtmlUrls($root);
            $sections = $this->classicSections($root);
        }
        $this->stats['sections'] = count($sections);
        $this->stats['entries'] = (int)($this->meta['entries'] ?? 0);
        $this->stats['entry_types'] = (array)($this->meta['entry_types'] ?? []);
        $this->stats['source'] = (string)($this->meta['source'] ?? 'page');
        return ['settings' => $settings, 'sections' => $sections];
    }

    /** CSS: של העמוד (נשלח גם בפרסום) ושל התצוגה בלבד (הבסיס של אלמנטור — באתר הוא כבר נטען). */
    public function css(): array
    {
        $page = $preview = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = (string)$this->zip->getNameIndex($i);
            if (!preg_match('~^css/([a-z0-9_-]+)\.css$~', $name, $m)) continue;
            $css = sanitize_imported_css($this->rewriteCssUrls((string)$this->zip->getFromIndex($i)), $this->origin);
            if (str_starts_with($m[1], 'preview')) $preview[] = $css; else $page[] = $css;
        }
        foreach ($this->inlineCss as $c) $page[] = sanitize_imported_css($this->rewriteCssUrls($c), $this->origin);
        return [implode("\n", $page), implode("\n", $preview)];
    }

    private function tag(DOMElement $el): array
    {
        $a = [];
        foreach ($el->attributes as $at) $a[$at->name] = $at->value;
        $t = strtolower($el->tagName);
        return ['t' => in_array($t, LEGACY_TAGS, true) ? $t : 'div', 'a' => $a];
    }

    private static function hasClass(DOMNode $el, string $c): bool
    {
        return $el instanceof DOMElement && preg_match('/(^|\s)' . preg_quote($c, '/') . '(\s|$)/', $el->getAttribute('class'));
    }

    private static function children(DOMElement $el): array
    {
        return array_values(array_filter(iterator_to_array($el->childNodes), fn($n) => $n instanceof DOMElement));
    }

    private static function childWithClass(DOMElement $el, string $c): ?DOMElement
    {
        foreach (self::children($el) as $ch) if (self::hasClass($ch, $c)) return $ch;
        return null;
    }

    private static function outer(DOMNode $n): string
    {
        return $n->ownerDocument->saveHTML($n);
    }

    private static function inner(DOMNode $n): string
    {
        $h = '';
        foreach ($n->childNodes as $c) $h .= $n->ownerDocument->saveHTML($c);
        return $h;
    }

    private static function layoutFor(int $n): string
    {
        return [1 => '100', 2 => '50-50', 3 => '33-33-33', 4 => '25-25-25-25', 5 => '20-20-20-20-20', 6 => '16-16-16-16-16-16'][max(1, min(6, $n))];
    }

    private function section(array $legacy, array $columns): array
    {
        // יותר מ-6 עמודות — העודפות מתמזגות לאחרונה (נדיר)
        while (count($columns) > 6) { $last = array_pop($columns); $columns[5]['widgets'] = [...$columns[5]['widgets'], ...$last['widgets']]; }
        if (!$columns) $columns = [['legacy' => [], 'widgets' => []]];
        return ['layout' => self::layoutFor(count($columns)), 'pad' => '', 'bg' => '', 'full' => false, 'legacy' => $legacy, 'columns' => $columns];
    }

    // ── אלמנטור ───────────────────────────────────────────

    private function elementorSections(DOMElement $root): array
    {
        $out = [];
        foreach (self::children($root) as $top) {
            if (self::hasClass($top, 'elementor-section')) {
                $box = self::childWithClass($top, 'elementor-container') ?? $top;
                $cols = [];
                foreach (self::children($box) as $col) {
                    if (!self::hasClass($col, 'elementor-column')) { $cols[] = ['legacy' => [], 'widgets' => [$this->raw($col)]]; continue; }
                    $wrap = self::childWithClass($col, 'elementor-widget-wrap') ?? $col;
                    $cols[] = ['legacy' => array_values(array_filter([$this->tag($col), $wrap !== $col ? $this->tag($wrap) : null])), 'widgets' => $this->elementorWidgets($wrap)];
                }
                $out[] = $this->section(array_values(array_filter([$this->tag($top), $box !== $top ? $this->tag($box) : null])), $cols);
            } elseif (self::hasClass($top, 'e-con')) {
                $box = self::childWithClass($top, 'e-con-inner') ?? $top;
                $kids = self::children($box);
                $hasCon = (bool)array_filter($kids, fn($k) => self::hasClass($k, 'e-con'));
                $cols = [];
                if ($hasCon) {
                    $loose = [];
                    foreach ($kids as $k) {
                        if (self::hasClass($k, 'e-con')) {
                            if ($loose) { $cols[] = ['legacy' => [], 'widgets' => $loose]; $loose = []; }
                            $kin = self::childWithClass($k, 'e-con-inner') ?? $k;
                            $cols[] = ['legacy' => array_values(array_filter([$this->tag($k), $kin !== $k ? $this->tag($kin) : null])), 'widgets' => $this->elementorWidgets($kin)];
                        } else {
                            array_push($loose, ...$this->elementorWidget($k));
                        }
                    }
                    if ($loose) $cols[] = ['legacy' => [], 'widgets' => $loose];
                } else {
                    $cols[] = ['legacy' => [], 'widgets' => $this->elementorWidgets($box)];
                }
                $out[] = $this->section(array_values(array_filter([$this->tag($top), $box !== $top ? $this->tag($box) : null])), $cols);
            } else {
                $out[] = $this->section([], [['legacy' => [], 'widgets' => [$this->raw($top)]]]);
            }
        }
        return $out;
    }

    private function elementorWidgets(DOMElement $wrap): array
    {
        $out = [];
        foreach (self::children($wrap) as $el) array_push($out, ...$this->elementorWidget($el));
        return $out;
    }

    /** ווידג'ט אחד של אלמנטור → רכיב אחד או יותר (טקסט עם הקלטה בתוכו — מתפצל). */
    private function elementorWidget(DOMElement $el): array
    {
        if (!self::hasClass($el, 'elementor-widget')) return [$this->raw($el)];          // פס פנימי / מכל מקונן — כמו שהוא
        $type = preg_match('/^([a-z0-9_-]+)\./i', $el->getAttribute('data-widget_type'), $m) ? $m[1]
            : (preg_match('/elementor-widget-([a-z0-9_-]+)/', $el->getAttribute('class'), $m) ? $m[1] : '');
        $settings = json_decode(html_entity_decode($el->getAttribute('data-settings')), true) ?: [];
        $box = self::childWithClass($el, 'elementor-widget-container');
        $content = $box ?? $el;
        $wrap = array_values(array_filter([$this->tag($el), $box ? $this->tag($box) : null]));

        switch ($type) {
            case 'heading':
                $h = self::children($content)[0] ?? null;
                if (!$h || $h->getElementsByTagName('a')->length) return [$this->raw($el)];
                $tag = strtolower($h->tagName);
                $lvl = preg_match('/^h([1-6])$/', $tag, $mm) ? (int)$mm[1] : 2;
                return [$this->w('heading', ['text' => trim($h->textContent), 'level' => $lvl], ['wrap' => $wrap, 'cls' => $h->getAttribute('class'), 'tag' => $tag])];

            case 'text-editor':
                $te = self::childWithClass($content, 'elementor-text-editor');
                if ($te) { $wrap[] = $this->tag($te); $content = $te; }
                return $this->splitText($content, $wrap);

            case 'image':
                $img = $content->getElementsByTagName('img')->item(0);
                $file = $img ? $this->fileOf($img->getAttribute('src')) : null;
                if (!$file) return [$this->raw($el)];
                $fig = $content->getElementsByTagName('figure')->item(0);
                $cap = $content->getElementsByTagName('figcaption')->item(0);
                $a = $img->parentNode instanceof DOMElement && strtolower($img->parentNode->tagName) === 'a' ? $img->parentNode : null;
                if ($fig) $wrap[] = $this->tag($fig);
                return [$this->w('image', ['file' => $file, 'alt' => $img->getAttribute('alt'), 'caption' => $cap ? trim($cap->textContent) : '',
                    'href' => $a ? $a->getAttribute('href') : '', 'width' => 'auto'], ['wrap' => $wrap, 'cls' => $img->getAttribute('class'), 'cap' => $cap?->getAttribute('class') ?? ''])];

            case 'button':
                $a = $content->getElementsByTagName('a')->item(0);
                if (!$a) return [$this->raw($el)];
                $bw = self::childWithClass($content, 'elementor-button-wrapper');
                if ($bw) $wrap[] = $this->tag($bw);
                $label = $a->getElementsByTagName('span')->length ? trim((self::spanText($a, 'elementor-button-text')) ?? $a->textContent) : trim($a->textContent);
                // כפתור עם אייקון — האייקון לא נשמר ברכיב רגיל; אז קטע מקורי
                if ($a->getElementsByTagName('svg')->length || $a->getElementsByTagName('i')->length) return [$this->raw($el)];
                return [$this->w('button', ['label' => $label, 'href' => $a->getAttribute('href'), 'variant' => 'solid'],
                    ['wrap' => $wrap, 'cls' => $a->getAttribute('class'), 'kind' => 'elementor-button'])];

            case 'divider':
            case 'spacer':
                return [$this->w($type, $type === 'spacer' ? ['height' => 32] : [], ['wrap' => $wrap, 'inner' => self::inner($content)])];

            case 'video':
                $url = (string)($settings['youtube_url'] ?? $settings['vimeo_url'] ?? '');
                if ($url !== '' && ($emb = video_embed($url))) return [$this->w('video', ['embed' => $emb, 'file' => ''], null)];
                $hosted = (string)($settings['hosted_url']['url'] ?? '');
                $v = $content->getElementsByTagName('video')->item(0);
                $file = $this->fileOf($hosted ?: ($v ? $v->getAttribute('src') : ''));
                return $file ? [$this->w('video', ['file' => $file, 'embed' => ''], null)] : [$this->raw($el)];

            case 'image-gallery':
            case 'gallery':
            case 'image-carousel':
            case 'media-carousel':
                $ids = [];
                foreach ($content->getElementsByTagName('img') as $img) if ($f = $this->fileOf($img->getAttribute('src'))) $ids[$f] = true;
                return $ids ? [$this->w('gallery', ['files' => array_keys($ids), 'cols' => min(4, max(2, (int)($settings['gallery_columns'] ?? 3)))], null)] : [$this->raw($el)];

            case 'html':
                // קוד מוטמע — רכיב קוד, במסגרת מבודדת (הסקריפטים שלו ממשיכים לעבוד, ולא יכולים לגעת בעמוד)
                $code = self::inner($content);
                return trim($code) === '' ? [] : [$this->w('code', ['html' => $code, 'title' => 'קוד מוטמע מהעמוד המקורי', 'height' => 300, 'auto' => true], null)];

            default:
                return [$this->raw($el)];
        }
    }

    private static function spanText(DOMElement $a, string $cls): ?string
    {
        foreach ($a->getElementsByTagName('span') as $s) if (self::hasClass($s, $cls)) return $s->textContent;
        return null;
    }

    /** טקסט עם הקלטות / וידאו בתוכו: מתפצל — טקסט (עם העטיפה המקורית), שמע, טקסט… */
    private function splitText(DOMElement $content, array $wrap): array
    {
        $out = [];
        $buf = '';
        $flush = function () use (&$buf, &$out, $wrap) {
            if (trim(strip_tags($buf, '<img>')) !== '') $out[] = $this->w('text', ['html' => $buf], ['wrap' => $wrap]);
            $buf = '';
        };
        foreach (iterator_to_array($content->childNodes) as $n) {
            if ($n instanceof DOMElement && ($media = $this->mediaIn($n))) {
                $flush();
                array_push($out, ...$media);
            } else {
                $buf .= $content->ownerDocument->saveHTML($n);
            }
        }
        $flush();
        return $out;
    }

    /** הקלטה / וידאו / הטמעה בתוך אלמנט → רכיבים. אחרת — []. */
    private function mediaIn(DOMElement $n): array
    {
        $out = [];
        $audios = strtolower($n->tagName) === 'audio' ? [$n] : iterator_to_array($n->getElementsByTagName('audio'));
        foreach ($audios as $au) {
            $src = $au->getAttribute('src') ?: ($au->getElementsByTagName('source')->item(0)?->getAttribute('src') ?? '');
            if (!$src) foreach ($n->getElementsByTagName('a') as $a) { $src = $a->getAttribute('href'); break; }
            $file = $this->fileOf($src);
            if ($file) {
                $cap = $n->getElementsByTagName('figcaption')->item(0);
                $out[] = $this->w('audio', ['file' => $file, 'title' => $cap ? trim($cap->textContent) : ''], null);
            } else {
                $out[] = $this->raw($n);
                return $out;
            }
        }
        if ($out) return $out;
        $videos = strtolower($n->tagName) === 'video' ? [$n] : iterator_to_array($n->getElementsByTagName('video'));
        foreach ($videos as $v) {
            $src = $v->getAttribute('src') ?: ($v->getElementsByTagName('source')->item(0)?->getAttribute('src') ?? '');
            $file = $this->fileOf($src);
            $out[] = $file ? $this->w('video', ['file' => $file, 'embed' => ''], null) : $this->raw($n);
        }
        if ($out) return $out;
        foreach ((strtolower($n->tagName) === 'iframe' ? [$n] : iterator_to_array($n->getElementsByTagName('iframe'))) as $f) {
            $emb = video_embed(preg_replace('~/embed/([A-Za-z0-9_-]{11}).*$~', '/watch?v=$1', $f->getAttribute('src')));
            $out[] = $emb ? $this->w('video', ['embed' => $emb, 'file' => ''], null) : $this->raw($n);
        }
        return $out;
    }

    private function fileOf(string $src): ?string
    {
        if (preg_match('/^kol-file:([0-9a-f]{32})$/', $src, $m)) return $m[1];
        return $src !== '' ? $this->fileId($src) : null;
    }

    // ── עמוד רגיל (בלי אלמנטור) ───────────────────────────

    private function classicSections(DOMElement $root): array
    {
        $out = [];
        $widgets = [];
        $text = '';
        $flushText = function () use (&$text, &$widgets) {
            if (trim(strip_tags($text, '<img>')) !== '') $widgets[] = $this->w('text', ['html' => $text], ['kind' => 'flow']);
            $text = '';
        };
        $flushSec = function () use (&$widgets, &$out, $flushText) {
            $flushText();
            if ($widgets) $out[] = ['layout' => '100', 'pad' => '', 'columns' => [['widgets' => $widgets]]];
            $widgets = [];
        };
        foreach (iterator_to_array($root->childNodes) as $n) {
            if (!$n instanceof DOMElement) { if (trim($n->textContent) !== '') $text .= htmlspecialchars($n->textContent); continue; }
            $tag = strtolower($n->tagName);
            $cls = $n->getAttribute('class');
            if (preg_match('/^h([1-6])$/', $tag, $m) && !$n->getElementsByTagName('a')->length) {
                $flushText();
                $widgets[] = $this->w('heading', ['text' => trim($n->textContent), 'level' => (int)$m[1]], ['cls' => $cls, 'tag' => $tag, 'kind' => 'flow']);
            } elseif (str_contains($cls, 'wp-block-columns')) {
                $flushSec();
                $cols = [];
                foreach (self::children($n) as $c) {
                    $sub = $this->classicSections($c);
                    $cols[] = ['legacy' => [$this->tag($c)], 'widgets' => array_merge(...array_map(fn($s) => $s['columns'][0]['widgets'], $sub ?: [['columns' => [['widgets' => []]]]]))];
                }
                $out[] = $this->section([$this->tag($n)], $cols);
            } elseif ($media = $this->mediaIn($n)) {
                $flushText();
                array_push($widgets, ...$media);
            } elseif (($img = ($tag === 'img' ? $n : null) ?? ($tag === 'figure' || $tag === 'p' ? $n->getElementsByTagName('img')->item(0) : null))
                && ($file = $this->fileOf($img->getAttribute('src'))) && trim($n->textContent) === trim($n->getElementsByTagName('figcaption')->item(0)?->textContent ?? '')) {
                $flushText();
                $cap = $n->getElementsByTagName('figcaption')->item(0);
                $a = $img->parentNode instanceof DOMElement && strtolower($img->parentNode->tagName) === 'a' ? $img->parentNode : null;
                $widgets[] = $this->w('image', ['file' => $file, 'alt' => $img->getAttribute('alt'), 'caption' => $cap ? trim($cap->textContent) : '',
                    'href' => $a ? $a->getAttribute('href') : '', 'width' => 'auto'],
                    ['wrap' => $tag === 'figure' ? [$this->tag($n)] : [], 'cls' => $img->getAttribute('class'), 'cap' => $cap?->getAttribute('class') ?? '', 'kind' => 'flow']);
            } elseif ($tag === 'hr') {
                $flushText();
                $widgets[] = $this->w('divider', [], ['inner' => self::outer($n)]);
            } elseif (in_array($tag, ['p', 'ul', 'ol', 'blockquote', 'table', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                $text .= self::outer($n);
            } else {
                $flushText();
                $widgets[] = $this->raw($n);
            }
        }
        $flushSec();
        return $out;
    }

    // ── בניית רכיבים ──────────────────────────────────────

    private function w(string $type, array $props, ?array $legacy): array
    {
        $this->stats['widgets'][$type] = ($this->stats['widgets'][$type] ?? 0) + 1;
        $w = ['type' => $type, 'props' => $props, 'style' => []];
        if ($legacy !== null) $w['legacy'] = $legacy;
        return $w;
    }

    private function raw(DOMElement $el): array
    {
        $this->stats['raw']++;
        return $this->w('raw', ['html' => self::outer($el)], null);
    }

    public function meta(): array
    {
        return $this->meta;
    }

    /** נתוני המילון (תוסף 1.2): ההגדרות, המבוא, המילים והנושאים. null — העמוד אינו המילון של התבנית. */
    public function lexicon(): ?array
    {
        $j = json_decode((string)$this->zip->getFromName('data/lexicon.json'), true);
        return is_array($j) && is_array($j['words'] ?? null) ? $j : null;
    }

    /** קובץ CSS אחד מהייצוא (כמו שהוא, עם ניקוי) — למשל ה-CSS של המילון לקבצי הקוד. */
    public function themeCss(string $name): ?string
    {
        $c = $this->zip->getFromName("css/$name.css");
        return $c === false ? null : sanitize_imported_css($this->rewriteCssUrls($c), $this->origin);
    }

    /** ה-JS של התבנית — לתצוגה בסטודיו בלבד (באתר הוא נטען ממילא). */
    public function js(): string
    {
        return (string)($this->zip->getFromName('js/lexicon.js') ?: '');
    }

    /** הרשומות (מילים, הקלטות, שדות) והאבחון — נשמרים לשלב הבא; לא נכנסים לעמוד עצמו. */
    public function keepData(): void
    {
        $dir = files_dir() . '/import';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $entries = $this->zip->getFromName('data/entries.json');
        if ($entries !== false) file_put_contents("$dir/entries.json", $entries);
        file_put_contents("$dir/meta.json", json_encode($this->meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

/** הייבוא: ZIP שהועלה (purpose=import) → הטיוטה. הטיוטה הקודמת נשמרת בהיסטוריה ("לפני ייבוא"). */
function import_run(array $owner, array $b): array
{
    @set_time_limit(1800);
    $u = upload_get($owner, (string)($b['id'] ?? ''));
    if (($u['purpose'] ?? '') !== 'import') throw new ApiError('ההעלאה הזו אינה ייבוא');
    $zip = tmp_path($u['id']);
    clearstatcache(true, $zip);
    if ((int)@filesize($zip) !== (int)$u['size']) throw new ApiError('ההעלאה לא הושלמה', 409);

    $imp = new Importer($owner, $zip);
    // עמוד המילון של התבנית: נבנה מהנתונים (רכיב מילון אחד), לא מה-HTML
    $lex = $imp->lexicon();
    $page = page_normalize($lex ? lexicon_page($lex, (string)($imp->meta()['title'] ?? '')) : $imp->run());
    $imp->keepData();
    [$css, $preview] = $imp->css();
    // במילון כל העיצוב שייך לאתר (ערכת הנושא ואלמנטור טוענים אותו לעמוד ממילא) — לתצוגה בסטודיו בלבד
    if ($lex) { $preview = trim($css . "\n" . $preview); $css = ''; }
    $lexCss = $lex ? $imp->themeCss('preview-kadisha-lexicon') : null;
    $js = $imp->js();
    db()->prepare('DELETE FROM uploads WHERE id = ?')->execute([$u['id']]);
    @unlink($zip);

    // הנעילה — לבעלים עכשיו (הייבוא מחליף את הטיוטה), ושמירה של מה שהיה
    $l = lock_state();
    if ($l && $l['sub'] !== $owner['sub']) throw new ApiError("הטיוטה בעריכה אצל {$l['name']}. אחרי שיסיים — שוב", 423);
    lock_op($owner, ['op' => 'take']);
    $d = draft_row();
    revision_add($owner, $d['json'], (int)$d['rev'], 'לפני ייבוא');
    if ($lex) {
        $n = words_replace($lex['words'], is_array($lex['topics'] ?? null) ? $lex['topics'] : []);
        $imp->stats['lexicon'] = ['words' => $n, 'audio' => count(array_filter($lex['words'], fn($w) => !empty($w['audio_url']))),
            'topics' => count((array)($lex['topics'] ?? []))];
        $imp->stats['source'] = 'lexicon';
    }
    setting_set('imported_css', $css);
    setting_set('preview_css', $preview);
    setting_set('preview_js', $js);
    if ($lexCss !== null) setting_set('theme_lexicon_css', $lexCss);     // לקבצי הקוד (lib/code.php)
    setting_set('imported_from', json_encode(['at' => now_iso(), 'meta' => array_intersect_key($imp->meta(), array_flip(['url', 'title', 'builder', 'exported_at']))], JSON_UNESCAPED_UNICODE));
    $res = draft_write($owner, $page, (int)$d['rev'], 'ייבוא מהאתר');
    audit_as($owner, 'import', ($imp->meta()['url'] ?? '') . ' · ' . $imp->stats['files'] . ' קבצים');
    return ['stats' => $imp->stats, 'rev' => $res['rev']];
}
