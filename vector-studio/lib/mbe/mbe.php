<?php
/**
 * ליבת ‎.mbe — קריאה, אימות וכתיבה בשרת. האפיון: creative-suite/docs/mbe-format.md.
 * אותו API ואותם קודי בעיות כמו src/js/mbe.js. עורכים רק במקור: creative-suite/mbe-core/src.
 *
 *   require_once __DIR__ . '/mbe/mbe.php';
 *   $f = Mbe::open($bytes);                 // לא זורק על קובץ פגום: $f->ok(), $f->issues
 *   $f->document['surfaces'][0]['layers'][] = [...];
 *   $f->setRendition('renditions/document.pdf', $pdf, ['gaps' => ['…']]);
 *   $out = Mbe::save($f, ['app' => 'word-processor', 'appVersion' => '2.3']);
 *
 * ייצוג ה-JSON: ראו src/php/canonical.php — אובייקט ריק נשאר stdClass כדי ש-{} לא יהפוך ל-[].
 */

declare(strict_types=1);

require_once __DIR__ . '/canonical.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/zip.php';

final class MbeError extends RuntimeException
{
    public function __construct(string $message, public readonly array $issues = []) { parent::__construct($message); }
}

final class MbeFile
{
    public ?array $manifest = null;   // כולל שדות לא מוכרים — נשמרים
    public mixed $document = null;    // המודל הקנוני; שדות לא מוכרים נשמרים
    /** @var array<string,string> כל שאר החלקים: media/, renditions/, apps/ ומה שלא מוכר — בית-לבית */
    public array $files = [];
    public array $issues = [];
    /** @var array<string,array{type:string,gaps:list<string>,by:?string}> ייצוגים שסופקו מאז הפתיחה */
    public array $fresh = [];

    public function ok(): bool
    {
        foreach ($this->issues as $i) if ($i['level'] === 'error') return false;
        return true;
    }

    public function kind(): ?string { return $this->manifest['kind'] ?? null; }

    /** מזהה המסמך — null עד השמירה הראשונה (או בקובץ 1.0). */
    public function id(): ?string
    {
        $v = $this->manifest['id'] ?? null;
        return is_string($v) ? $v : null;
    }
    public function documentSha256(): ?string
    {
        $d = $this->manifest['document'] ?? null;
        return is_array($d) && is_string($d['sha256'] ?? null) ? $d['sha256'] : null;
    }

    public function media(): array
    {
        $listed = [];
        foreach ($this->manifest['media'] ?? [] as $m) if (is_array($m) && isset($m['path'])) $listed[$m['path']] = $m;
        $out = [];
        foreach ($this->paths('media/') as $p) $out[] = array_merge($listed[$p] ?? [], ['path' => $p, 'data' => $this->files[$p]]);
        return $out;
    }

    public function getMedia(string $path): ?string { return $this->files[$path] ?? null; }

    /** מוסיף משאב בשם הגיבוב שלו; אותו תוכן פעמיים — נשמר פעם אחת. מחזיר את הנתיב, לשימוש ב-src. */
    public function addMedia(string $data, string $type): string
    {
        $ext = array_search($type, Mbe::TYPES, true) ?: 'bin';
        $path = 'media/' . hash('sha256', $data) . '.' . $ext;
        $this->files[$path] = $data;
        return $path;
    }

    public function renditions(): array
    {
        $listed = [];
        foreach ($this->manifest['renditions'] ?? [] as $r) if (is_array($r) && isset($r['path'])) $listed[$r['path']] = $r;
        $out = [];
        foreach ($this->paths('renditions/') as $p) {
            $r = array_merge($listed[$p] ?? [], ['path' => $p, 'data' => $this->files[$p]]);
            $r['stale'] = !isset($this->fresh[$p]) && ($r['sourceSha256'] ?? null) !== $this->documentSha256();
            $out[] = $r;
        }
        return $out;
    }

    /** האפליקציה מספקת bytes + gaps; בשמירה הליבה רושמת sourceSha256 של המסמך שנשמר. */
    public function setRendition(string $path, string $data, array $opts = []): void
    {
        if (!preg_match('~^renditions/[^/]{1,128}$~u', $path)) throw new MbeError("נתיב ייצוג לא חוקי: $path");
        $this->files[$path] = $data;
        $this->fresh[$path] = ['type' => $opts['type'] ?? Mbe::typeForPath($path), 'gaps' => array_values($opts['gaps'] ?? []), 'by' => $opts['by'] ?? null];
    }

    public function removeRendition(string $path): void
    {
        unset($this->files[$path], $this->fresh[$path]);
    }

    /** המידע הפרטי של אפליקציה: שם יחסי → bytes. של אחרים — לא נוגעים. */
    public function apps(string $id): array
    {
        $pre = "apps/$id/";
        $out = [];
        foreach ($this->paths($pre) as $p) $out[substr($p, strlen($pre))] = $this->files[$p];
        return $out;
    }

    public function setApp(string $id, string $name, string $data): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id)) throw new MbeError("מזהה אפליקציה לא חוקי: $id");
        $this->files["apps/$id/$name"] = $data;
    }

    private function paths(string $prefix): array
    {
        $out = array_values(array_filter(array_map('strval', array_keys($this->files)), fn($p) => str_starts_with($p, $prefix)));
        sort($out, SORT_STRING);
        return $out;
    }
}

final class Mbe
{
    public const FORMAT = 'mbe';
    public const VERSION = '1.1';
    public const ID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    public const CURRENT_MAJOR = 1;
    public const MIMETYPE = 'application/vnd.mbe+zip';
    public const PREVIEW = 'renditions/preview.png';
    public const PREVIEW_MAX_PX = 1024;
    public const MEDIA_RE = '~^media/([0-9a-f]{64})(\.[a-z0-9]{1,8})?$~';
    public const TYPES = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'ora' => 'image/openraster',
        'json' => 'application/json', 'txt' => 'text/plain', 'jpeg' => 'image/jpeg',
    ];
    private const RESERVED = ['mimetype', 'manifest.json', 'document.json'];

    private static ?array $schemas = null;

    public static function schemas(): array
    {
        return self::$schemas ??= require __DIR__ . '/schemas.php';
    }

    public static function typeForPath(string $path): string
    {
        return preg_match('/\.([a-z0-9]+)$/i', $path, $m) ? (self::TYPES[strtolower($m[1])] ?? 'application/octet-stream') : 'application/octet-stream';
    }

    public static function parseVersion(mixed $v): ?array
    {
        return is_string($v) && preg_match('/^(\d+)\.(\d+)$/', $v, $m) ? ['major' => (int)$m[1], 'minor' => (int)$m[2]] : null;
    }

    /**
     * ממירים בין majors, לפי ה-major שממנו ממירים. היום יש רק major 1, ולכן 1→2 הוא שלד: כשיוגדר 2.0 — כאן
     * נכתבת ההמרה, CURRENT_MAJOR עולה ל-2, וקבצי 1.x נפתחים דרכו.
     */
    public static function migrations(): array
    {
        return [
            1 => fn($document, array $manifest) => [$document, array_merge($manifest, ['version' => '2.0'])],
        ];
    }

    public static function migrate(mixed $document, array $manifest, int $toMajor = self::CURRENT_MAJOR): array
    {
        $major = self::parseVersion($manifest['version'] ?? null)['major'] ?? 0;
        $steps = self::migrations();
        while ($major < $toMajor) {
            if (!isset($steps[$major])) throw new MbeError("אין ממיר מגרסה $major");
            [$document, $manifest] = $steps[$major]($document, $manifest);
            $major++;
        }
        return [$document, $manifest];
    }

    /** מסמך חדש. preview עדיין חסר — חובה לספק לפני save. */
    public static function create(string $kind, mixed $document = null): MbeFile
    {
        $f = new MbeFile();
        $f->manifest = ['format' => self::FORMAT, 'version' => self::VERSION, 'kind' => $kind,
            'document' => ['path' => 'document.json', 'sha256' => str_repeat('0', 64)]];
        $f->document = $document ?? ['surfaces' => []];
        return $f;
    }

    public static function validate(string $bytes, array $limits = MbeZip::LIMITS): array
    {
        return self::open($bytes, $limits)->issues;
    }

    /** פותח קובץ. לעולם לא זורק על תוכן פגום — הבעיות ב-$f->issues, ומה שנקרא — זמין. */
    public static function open(string $bytes, array $limits = MbeZip::LIMITS): MbeFile
    {
        $f = new MbeFile();
        $issue = function (string $level, string $code, string $message, ?string $path = null) use ($f) {
            $f->issues[] = ['level' => $level, 'code' => $code, 'message' => $message] + ($path !== null ? ['path' => $path] : []);
        };
        $zip = MbeZip::readZip($bytes, $limits);
        $f->issues = $zip['issues'];
        $entries = $zip['entries'];
        if (!$entries) {
            if ($f->ok()) $issue('error', 'not-mbe', 'ה-ZIP ריק');
            return $f;
        }

        $mt = null;
        foreach ($entries as $e) if ($e['name'] === 'mimetype') { $mt = $e; break; }
        $raw = [];
        foreach ($entries as $e) {
            if ($e['dir']) continue;
            $data = MbeZip::read($bytes, $e, $f->issues);
            if ($data !== null) $raw[$e['name']] = $data;
        }
        $hasManifest = array_key_exists('manifest.json', $raw);
        if ($mt === null) {
            $issue('error', $hasManifest ? 'mimetype-invalid' : 'not-mbe',
                $hasManifest ? 'חסר הקובץ mimetype' : 'זה אינו קובץ ‎.mbe (אין mimetype ואין manifest.json)');
            if (!$hasManifest) return $f;
        } else {
            $text = $raw['mimetype'] ?? null;
            if ($text !== null && $text !== self::MIMETYPE) {
                $issue('error', 'mimetype-invalid', 'mimetype הוא "' . mb_substr(mb_scrub($text, 'UTF-8'), 0, 60) . '" ולא ' . self::MIMETYPE, 'mimetype');
            }
            if ($entries[0]['name'] !== 'mimetype') $issue('warning', 'mimetype-not-first', 'mimetype אינו הקובץ הראשון — זיהוי לפי הבתים הראשונים לא יעבוד', 'mimetype');
            elseif ($mt['method'] !== 0) $issue('warning', 'mimetype-compressed', 'mimetype דחוס — צריך להיות Stored', 'mimetype');
            elseif ($mt['localExtra'] !== 0) $issue('warning', 'mimetype-extra', 'לכותרת של mimetype יש שדה נוסף — החתימה לא בהיסט 38', 'mimetype');
        }

        if (!$hasManifest) { $issue('error', 'manifest-missing', 'חסר manifest.json'); return $f; }
        try { $manifest = MbeCanonical::decode($raw['manifest.json']); } catch (MbeCanonicalError $e) {
            $issue('error', str_contains($e->getMessage(), 'עומק') ? 'json-too-deep' : 'manifest-invalid', 'manifest.json: ' . $e->getMessage(), 'manifest.json');
            return $f;
        }
        if ($manifest instanceof stdClass) $manifest = (array)$manifest; // {} או אובייקט דמוי-רשימה
        elseif (!is_array($manifest) || array_is_list($manifest)) $manifest = null;
        if ($manifest === null) {
            $issue('error', 'manifest-invalid', 'manifest.json אינו אובייקט', 'manifest.json');
            return $f;
        }
        $f->manifest = $manifest;
        $ver = self::parseVersion($manifest['version'] ?? null);
        if ($ver && $ver['major'] > self::CURRENT_MAJOR) {
            $issue('error', 'version-unsupported', "הקובץ נוצר בגרסה חדשה של הפורמט ({$manifest['version']}) — עדכן את האפליקציה", 'manifest.json');
            return $f;
        }
        if ($ver && $ver['major'] < self::CURRENT_MAJOR) {
            $issue('error', 'version-unsupported', "גרסת פורמט {$manifest['version']} ישנה מדי ואין לה ממיר", 'manifest.json');
            return $f;
        }
        foreach (MbeSchema::validate($manifest, self::schemas()['manifest']) as $s) $issue('error', 'manifest-schema', $s['message'], 'manifest.json#' . $s['path']);
        $ours = self::parseVersion(self::VERSION);
        if ($ver && $ver['minor'] > $ours['minor']) {
            $issue('warning', 'version-newer-minor', "הקובץ בגרסה {$manifest['version']}, חדשה מ-" . self::VERSION . ' — מה שלא מוכר נשמר כמו שהוא', 'manifest.json');
        }
        // manifest.id (מ-1.1): קובץ 1.0 בלי id — תקין, ויקבל בשמירה הבאה. מזהה פגום — נשמר כמו שהוא (לא משנים זהות בשקט).
        if (array_key_exists('id', $manifest)) {
            if (!is_string($manifest['id']) || !preg_match(self::ID_RE, $manifest['id'])) {
                $issue('warning', 'manifest-id-invalid', 'manifest.id אינו UUID v4 באותיות קטנות — נשמר כמו שהוא', 'manifest.json#/id');
            }
        } elseif ($ver && $ver['minor'] >= 1) {
            $issue('warning', 'manifest-id-missing', "לקובץ בגרסה {$manifest['version']} חסר manifest.id — יתווסף בשמירה הבאה", 'manifest.json#/id');
        }
        $text = MbeCanonical::encode($manifest);
        if (preg_match('/[^\s@"]+@[^\s@"]+\.[a-z]{2,}/i', $text)) {
            $issue('warning', 'privacy', 'ב-manifest.json יש כתובת מייל — הוא לא אמור להכיל פרטים אישיים', 'manifest.json');
        }

        if (!array_key_exists('document.json', $raw)) {
            $issue('error', 'document-missing', 'חסר document.json');
        } else {
            $docBytes = $raw['document.json'];
            $parsed = false;
            try {
                $f->document = MbeCanonical::decode($docBytes);
                $parsed = true;
            } catch (MbeCanonicalError $e) {
                $issue('error', str_contains($e->getMessage(), 'עומק') ? 'json-too-deep' : 'document-invalid', 'document.json: ' . $e->getMessage(), 'document.json');
                $f->document = null;
            }
            if ($parsed && $f->document !== null) {
                if (MbeSchema::typeOf($f->document) !== 'object') {
                    $issue('error', 'document-invalid', 'document.json אינו אובייקט', 'document.json');
                } else {
                    foreach (MbeSchema::validate($f->document, self::schemas()['document']) as $s) $issue('error', 'document-schema', $s['message'], 'document.json#' . $s['path']);
                }
            }
            $want = $f->documentSha256();
            if ($want !== null && $want !== hash('sha256', $docBytes)) {
                $issue('error', 'hash-mismatch', 'הגיבוב של document.json לא תואם ל-manifest — הקובץ פגום חלקית', 'document.json');
            }
        }

        foreach ($raw as $p => $d) if (!in_array((string)$p, self::RESERVED, true)) $f->files[(string)$p] = $d;
        self::checkParts($f, $issue);
        if (MbeSchema::typeOf($f->document) === 'object') self::checkDocument($f, $issue);
        return $f;
    }

    private static function checkParts(MbeFile $f, callable $issue): void
    {
        $listedMedia = [];
        foreach (is_array($f->manifest['media'] ?? null) ? $f->manifest['media'] : [] as $m) {
            if (is_array($m) && is_string($m['path'] ?? null)) $listedMedia[$m['path']] = $m;
        }
        foreach ($listedMedia as $path => $m) {
            if (!isset($f->files[$path])) { $issue('error', 'media-missing', 'משאב שרשום ב-manifest חסר בקובץ', $path); continue; }
            if (($m['sha256'] ?? null) !== hash('sha256', $f->files[$path])) $issue('error', 'hash-mismatch', 'הגיבוב של המשאב לא תואם ל-manifest', $path);
        }
        foreach ($f->files as $path => $data) {
            $path = (string)$path;
            if (str_starts_with($path, 'media/')) {
                if (!preg_match(self::MEDIA_RE, $path, $m)) $issue('warning', 'media-unexpected-name', 'שם משאב שאינו sha256 — נשמר כמו שהוא', $path);
                elseif ($m[1] !== hash('sha256', $data)) $issue('error', 'media-name-mismatch', 'שם המשאב אינו הגיבוב של התוכן', $path);
                if (!isset($listedMedia[$path])) $issue('warning', 'media-unlisted', 'משאב שלא רשום ב-manifest — יירשם בשמירה הבאה', $path);
            }
            if (str_ends_with($path, '.svg') || ($listedMedia[$path]['type'] ?? null) === 'image/svg+xml') {
                $bad = self::svgProblem(mb_scrub($data, 'UTF-8'));
                if ($bad !== null) $issue('error', 'svg-unsafe', "SVG לא בטוח: $bad", $path);
            }
        }
        $listedR = [];
        foreach (is_array($f->manifest['renditions'] ?? null) ? $f->manifest['renditions'] : [] as $r) {
            if (is_array($r) && is_string($r['path'] ?? null)) $listedR[$r['path']] = $r;
        }
        foreach ($listedR as $path => $r) {
            if (!isset($f->files[$path])) $issue('error', 'rendition-missing', 'ייצוג שרשום ב-manifest חסר בקובץ', $path);
            elseif (($r['sourceSha256'] ?? null) !== $f->documentSha256()) $issue('warning', 'rendition-stale', 'הייצוג נגזר מגרסה קודמת של המסמך (מיושן)', $path);
        }
        foreach (array_keys($f->files) as $path) {
            if (str_starts_with((string)$path, 'renditions/') && !isset($listedR[$path])) $issue('warning', 'rendition-unlisted', 'ייצוג שלא רשום ב-manifest — מיושן', (string)$path);
        }
        if (!isset($f->files[self::PREVIEW])) {
            $issue('warning', 'preview-missing', 'חסר renditions/preview.png (חובה בכל שמירה)', self::PREVIEW);
        } else {
            $dims = self::pngSize($f->files[self::PREVIEW]);
            if ($dims === null) $issue('warning', 'preview-invalid', 'preview.png אינו PNG', self::PREVIEW);
            elseif (max($dims) > self::PREVIEW_MAX_PX) $issue('warning', 'preview-invalid', 'preview.png גדול מ-' . self::PREVIEW_MAX_PX . 'px', self::PREVIEW);
        }
    }

    private static function checkDocument(MbeFile $f, callable $issue): void
    {
        $doc = (array)$f->document;
        $flows = MbeSchema::typeOf($doc['flows'] ?? null) === 'object' ? (array)$doc['flows'] : [];
        $ids = [];
        $seeId = function (mixed $id, string $where) use (&$ids, $issue) {
            if (!is_string($id)) return;
            if (isset($ids[$id])) $issue('error', 'duplicate-id', "המזהה \"$id\" מופיע פעמיים", 'document.json#' . $where);
            $ids[$id] = true;
        };
        // תוכן שכבת vector (סעיף 3): נתיב — d חובה ותקין; קבוצה — בלי d ו-transform משלה; type לא מוכר — נשמר, לא נבדק.
        $walkPaths = function (mixed $items, string $where) use (&$walkPaths, $seeId, $issue) {
            if (MbeSchema::typeOf($items) !== 'array') return;
            foreach ($items as $i => $p) {
                if (MbeSchema::typeOf($p) !== 'object') continue;
                $p = (array)$p;
                $at = "$where/$i";
                $seeId($p['id'] ?? null, $at);
                $type = $p['type'] ?? 'path';
                if ($type === 'path') {
                    if (!is_string($p['d'] ?? null)) $issue('error', 'path-d-missing', 'לנתיב חסר d', "document.json#$at");
                    elseif (($bad = self::pathProblem($p['d'])) !== null) $issue('error', 'path-d-invalid', "d לא תקין: $bad", "document.json#$at/d");
                } elseif ($type === 'group') {
                    if (array_key_exists('d', $p) || array_key_exists('transform', $p)) {
                        $issue('error', 'group-geometry', 'לקבוצה אין d ו-transform משלה', "document.json#$at");
                    }
                    $walkPaths($p['children'] ?? null, "$at/children");
                }
            }
        };
        $layerById = [];
        $walkLayers = function (mixed $layers, string $where) use (&$walkLayers, &$layerById, $walkPaths, $seeId, $flows, $issue) {
            if (MbeSchema::typeOf($layers) !== 'array') return;
            foreach ($layers as $i => $l) {
                if (MbeSchema::typeOf($l) !== 'object') continue;
                $l = (array)$l;
                $at = "$where/$i";
                $seeId($l['id'] ?? null, $at);
                if (is_string($l['id'] ?? null) && !array_key_exists($l['id'], $layerById)) $layerById[$l['id']] = $l;
                if (($l['type'] ?? null) === 'frame' && is_string($l['flow'] ?? null) && !array_key_exists($l['flow'], $flows)) {
                    $issue('error', 'flow-missing', "המסגרת מפנה לשטף \"{$l['flow']}\" שאינו קיים", "document.json#$at/flow");
                }
                $walkLayers($l['children'] ?? null, "$at/children");
                $walkPaths($l['paths'] ?? null, "$at/paths");
            }
        };
        $surfaces = MbeSchema::typeOf($doc['surfaces'] ?? null) === 'array' ? $doc['surfaces'] : [];
        foreach ($surfaces as $i => $s) {
            if (MbeSchema::typeOf($s) !== 'object') continue;
            $s = (array)$s;
            $seeId($s['id'] ?? null, "/surfaces/$i");
            $walkLayers($s['layers'] ?? null, "/surfaces/$i/layers");
        }
        // סדר השרשור (סעיף 3, "מסגרות, שטפים"): כל מזהה ב-frames — שכבת frame קיימת שה-flow שלה הוא השטף הזה, פעם אחת.
        foreach (MbeCanonical::sortedKeys($flows) as $fid) {
            $flow = $flows[$fid];
            $list = MbeSchema::typeOf($flow) === 'object' ? (((array)$flow)['frames'] ?? null) : null;
            if (MbeSchema::typeOf($list) !== 'array') continue;
            $seen = [];
            foreach ($list as $j => $id) {
                if (!is_string($id)) continue;
                $at = "document.json#/flows/$fid/frames/$j";
                $l = $layerById[$id] ?? null;
                if (isset($seen[$id])) $issue('error', 'flow-frames', "המסגרת \"$id\" מופיעה פעמיים בשטף \"$fid\"", $at);
                elseif ($l === null) $issue('error', 'flow-frames', "בשטף \"$fid\" אין שכבה \"$id\"", $at);
                elseif (($l['type'] ?? null) !== 'frame') $issue('error', 'flow-frames', "\"$id\" בשטף \"$fid\" אינה מסגרת (" . json_encode($l['type'] ?? null) . ')', $at);
                elseif (($l['flow'] ?? null) !== $fid) $issue('error', 'flow-frames', "המסגרת \"$id\" שייכת לשטף \"" . (is_string($l['flow'] ?? null) ? $l['flow'] : '—') . "\" ולא ל-\"$fid\"", $at);
                $seen[$id] = true;
            }
        }
        // הפניות למשאבים: כל src / embedded — רק ל-media/ שבתוך הקובץ. קישורים בטקסט (href) — מותרים.
        $walk = function (mixed $v, string $where, int $depth) use (&$walk, $f, $issue) {
            $type = MbeSchema::typeOf($v);
            if ($depth > 300 || ($type !== 'object' && $type !== 'array')) return;
            $map = [];
            foreach ((array)$v as $k => $x) $map[(string)$k] = $x;
            foreach (MbeCanonical::sortedKeys($map) as $k) {
                $x = $map[$k];
                $at = "$where/$k";
                if (($k === 'src' || $k === 'embedded') && is_string($x) && $type === 'object') {
                    if (!preg_match(self::MEDIA_RE, $x)) $issue('error', 'external-ref', 'הפניה למשאב מחוץ לקובץ — כל משאב חייב להיות ב-media/', 'document.json#' . $at);
                    elseif (!isset($f->files[$x])) $issue('error', 'ref-missing', "המשאב $x לא נמצא בקובץ", 'document.json#' . $at);
                } else {
                    $walk($x, $at, $depth + 1);
                }
            }
        };
        $walk($f->document, '', 0);
    }

    private const PATH_ARGS = ['M' => 2, 'L' => 2, 'C' => 6, 'Q' => 4, 'Z' => 0];

    /**
     * null אם d תקין; אחרת — מה לא. זהה ל-pathProblem ב-JS. סורק ולא ביטוי רגולרי אחד: נתיב של מגה-בתים
     * מפיל את PCRE (JIT stack) בביטוי עם חזרות מקוננות.
     */
    public static function pathProblem(string $d): ?string
    {
        $i = 0;
        $n = strlen($d);
        $cmd = null;
        $count = 0;
        $close = function () use (&$cmd, &$count): ?string {
            if ($cmd === null) return null;
            $k = self::PATH_ARGS[$cmd];
            if ($k === 0 ? $count === 0 : ($count > 0 && $count % $k === 0)) return null;
            return "$cmd צריכה " . ($k ? "כפולות של $k מספרים" : 'בלי מספרים');
        };
        while (true) {
            while ($i < $n && strpos(" \t\n\r\f,", $d[$i]) !== false) $i++;
            if ($i >= $n) break;
            $c = $d[$i];
            if (isset(self::PATH_ARGS[$c])) {
                if ($cmd === null && $c !== 'M') return 'נתיב מתחיל ב-M';
                if (($bad = $close()) !== null) return $bad;
                $cmd = $c;
                $count = 0;
                $i++;
                continue;
            }
            if ($cmd === null || !preg_match('/\G[-+]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][-+]?[0-9]+)?/', $d, $m, 0, $i)) {
                return 'תו לא צפוי "' . mb_substr(mb_scrub(substr($d, $i, 4), 'UTF-8'), 0, 1, 'UTF-8') . "\" במקום $i";
            }
            $i += strlen($m[0]);
            $count++;
        }
        if ($cmd === null) return 'נתיב ריק';
        return $close();
    }

    /** null אם ה-SVG בטוח; אחרת — מה נמצא. ניקוי בפועל — באחריות האפליקציה שמייצרת את ה-SVG. */
    public static function svgProblem(string $text): ?string
    {
        $t = strtolower($text);
        if (preg_match('~<script[\s>/]~', $t)) return '<script>';
        if (preg_match('~<foreignobject[\s>/]~', $t)) return '<foreignObject>';
        if (str_contains($t, '<!entity')) return '<!ENTITY>';
        if (preg_match('~[\s"\'/]on[a-z]+\s*=~', $t)) return 'on*=';
        if (str_contains($t, 'javascript:')) return 'javascript:';
        if (str_contains($t, '@import')) return '@import';
        if (preg_match('~(?:href|src)\s*=\s*["\']\s*(?!#|data:image/(?:png|jpeg|gif|webp);)~', $t)) return 'הפניה חיצונית';
        if (preg_match('~url\(\s*["\']?\s*(?!#|data:image/(?:png|jpeg|gif|webp);)~', $t)) return 'url() חיצוני';
        return null;
    }

    private static function pngSize(string $b): ?array
    {
        if (strlen($b) < 24 || substr($b, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
        return [unpack('N', $b, 16)[1], unpack('N', $b, 20)[1]];
    }

    /** מזהה מסמך חדש — UUID v4 באותיות קטנות (mbe-format.md סעיף 3, manifest.id). */
    public static function newId(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    /**
     * כותב קובץ. זורק MbeError כשהתוצאה לא הייתה עוברת אימות — save לעולם לא מייצר קובץ פגום.
     * opts: app (חובה), appVersion, now (DateTimeInterface), lite (preview בלבד),
     * asCopy ("שמירה כעותק" — id חדש ו-created חדש).
     */
    public static function save(MbeFile $f, array $opts): string
    {
        $app = (string)($opts['app'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $app)) throw new MbeError('save צריך app — מזהה האפליקציה ששומרת');
        if (!isset($f->files[self::PREVIEW])) {
            throw new MbeError('חסר renditions/preview.png — חובה בכל שמירה',
                [['level' => 'error', 'code' => 'preview-missing', 'message' => 'חסר preview.png', 'path' => self::PREVIEW]]);
        }
        try { $docBytes = MbeCanonical::encode($f->document); } catch (MbeCanonicalError $e) {
            throw new MbeError('document אינו JSON תקין: ' . $e->getMessage(), [['level' => 'error', 'code' => 'document-invalid', 'message' => $e->getMessage()]]);
        }
        $docSha = hash('sha256', $docBytes);
        $now = DateTimeImmutable::createFromInterface($opts['now'] ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
        $stamp = ['app' => $app] + (($opts['appVersion'] ?? '') !== '' ? ['appVersion' => (string)$opts['appVersion']] : []) + ['at' => $now->format('Y-m-d\TH:i:s\Z')];
        $old = $f->manifest ?? [];
        $oldVer = self::parseVersion($old['version'] ?? null);
        $ours = self::parseVersion(self::VERSION);
        $version = ($oldVer && $oldVer['major'] === $ours['major'] && $oldVer['minor'] > $ours['minor']) ? $old['version'] : self::VERSION;

        $files = $f->files;
        if (!empty($opts['lite'])) {
            foreach (array_keys($files) as $p) if (str_starts_with((string)$p, 'renditions/') && $p !== self::PREVIEW) unset($files[$p]);
        }
        $names = array_map('strval', array_keys($files));
        sort($names, SORT_STRING);
        $oldMedia = [];
        foreach (is_array($old['media'] ?? null) ? $old['media'] : [] as $m) if (is_array($m) && isset($m['path'])) $oldMedia[$m['path']] = $m;
        $oldR = [];
        foreach (is_array($old['renditions'] ?? null) ? $old['renditions'] : [] as $r) if (is_array($r) && isset($r['path'])) $oldR[$r['path']] = $r;
        $media = [];
        foreach ($names as $p) {
            if (!str_starts_with($p, 'media/') || !preg_match(self::MEDIA_RE, $p)) continue;
            $prev = $oldMedia[$p] ?? [];
            $media[] = array_merge($prev, ['path' => $p, 'sha256' => hash('sha256', $files[$p]),
                'type' => $prev['type'] ?? self::typeForPath($p), 'bytes' => strlen($files[$p])]);
        }
        $renditions = [];
        foreach ($names as $p) {
            if (!str_starts_with($p, 'renditions/')) continue;
            $prev = $oldR[$p] ?? [];
            $fresh = $f->fresh[$p] ?? null;
            $r = array_merge($prev, ['path' => $p, 'type' => $fresh['type'] ?? $prev['type'] ?? self::typeForPath($p),
                'sourceSha256' => $fresh ? $docSha : ($prev['sourceSha256'] ?? str_repeat('0', 64)),
                'gaps' => $fresh ? $fresh['gaps'] : ($prev['gaps'] ?? [])]);
            $by = $fresh ? ($fresh['by'] ?? $app) : ($prev['by'] ?? null);
            if ($by !== null) $r['by'] = $by; else unset($r['by']);
            $renditions[] = $r;
        }
        $manifest = array_merge($old, [
            'format' => self::FORMAT, 'version' => $version, 'kind' => $old['kind'] ?? null,
            // id: נוצר בשמירה הראשונה ולא משתנה; asCopy — מסמך חדש: id חדש ו-created חדש
            'id' => empty($opts['asCopy']) && array_key_exists('id', $old) ? $old['id'] : self::newId(),
            'created' => (empty($opts['asCopy']) ? ($old['created'] ?? null) : null) ?? $stamp, 'modified' => $stamp,
            'document' => array_merge(is_array($old['document'] ?? null) ? $old['document'] : [], ['path' => 'document.json', 'sha256' => $docSha]),
            'media' => $media, 'renditions' => $renditions,
        ]);
        $parts = [
            ['name' => 'mimetype', 'data' => self::MIMETYPE, 'store' => true],
            ['name' => 'manifest.json', 'data' => MbeCanonical::encode($manifest)],
            ['name' => 'document.json', 'data' => $docBytes],
        ];
        foreach ($names as $n) $parts[] = ['name' => $n, 'data' => $files[$n]];
        $out = MbeZip::writeZip($parts, $now);

        $check = self::open($out);
        $errors = array_values(array_filter($check->issues, fn($i) => $i['level'] === 'error'));
        if ($errors) {
            throw new MbeError('השמירה נעצרה — הקובץ לא היה עובר אימות: ' . implode(', ', array_column($errors, 'code')), $errors);
        }
        $f->manifest = $check->manifest;
        $f->files = $check->files;
        $f->fresh = [];
        $f->issues = $check->issues;
        return $out;
    }
}
