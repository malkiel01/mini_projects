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
    /** @var list<array{kind:string,who:?string,note:?string}> שלבים מפורשים (stage / restore) — נרשמים ביומן בשמירה הבאה */
    public array $pending = [];
    /** הבתים של document.json כפי שנפתח — עותק הבסיס ב-history/ */
    public ?string $openedDoc = null;

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

    // ---- יומן שלבים וגרסאות (history/, מ-1.1 — mbe-format.md סעיף 3, docs/spec/hub-api.md 2.5) ----

    /** השלבים, מהישן לחדש. יומן פגום או חסר — רשימה ריקה (והאזהרה ב-issues). */
    public function journal(): array
    {
        return Mbe::readJournal($this->files)['stages'] ?? [];
    }

    /** המסמך של גרסה (לפי sha256), או null אם נגזמה / חסרה. */
    public function version(string $sha): mixed
    {
        $d = $this->files["history/$sha.json"] ?? null;
        if ($d === null) return null;
        try { return MbeCanonical::decode($d); } catch (MbeCanonicalError) { return null; }
    }

    /** שלב מפורש ("נשלח להגהה") — נרשם בשמירה הבאה, עם base / result של אותה שמירה. */
    public function stage(string $kind = 'edit', ?string $who = null, ?string $note = null): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/', $kind)) throw new MbeError("סוג שלב לא חוקי: $kind");
        $this->pending[] = ['kind' => $kind, 'who' => $who, 'note' => $note];
    }

    /** חזרה לשלב (לפי id) או לגרסה (לפי sha256): המסמך הופך לגרסה ההיא, ובשמירה נרשם שלב restore. לא מוחק כלום. */
    public function restore(string $ref, ?string $who = null): void
    {
        $st = null;
        foreach ($this->journal() as $s) if (is_array($s) && (($s['id'] ?? null) === $ref || ($s['result'] ?? null) === $ref)) { $st = $s; break; }
        $sha = $st ? (string)$st['result'] : $ref;
        $doc = $this->version($sha);
        if ($doc === null) throw new MbeError("הגרסה $ref לא נמצאה בקובץ (נגזמה או חסרה)");
        $this->document = $doc;
        $this->pending[] = ['kind' => 'restore', 'who' => $who, 'note' => 'שוחזר מ-' . ($st ? $st['id'] : substr($sha, 0, 12))];
    }

    /** גיזום: משאיר את $keep הגרסאות האחרונות (ואת הנוכחית); שלב שהגרסה שלו נמחקה — pruned: true. היומן עצמו לא נגזם. */
    public function prune(int $keep = 30): int
    {
        $j = Mbe::readJournal($this->files);
        if ($j === null) return 0;
        $order = [];
        foreach (array_reverse($j['stages']) as $s) {
            $r = is_array($s) ? ($s['result'] ?? null) : null;
            if (is_string($r) && !in_array($r, $order, true)) $order[] = $r;
        }
        $keepSet = array_flip(array_slice($order, 0, $keep));
        if ($this->documentSha256() !== null) $keepSet[$this->documentSha256()] = true;
        $n = 0;
        foreach (array_keys($this->files) as $p) {
            if (preg_match(Mbe::SNAP_RE, (string)$p, $m) && !isset($keepSet[$m[1]])) { unset($this->files[$p]); $n++; }
        }
        foreach ($j['stages'] as $i => $s) {
            if (is_array($s) && is_string($s['result'] ?? null) && !isset($keepSet[$s['result']])) $j['stages'][$i]['pruned'] = true;
        }
        $this->files[Mbe::JOURNAL] = MbeCanonical::encode($j);
        return $n;
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
    public const JOURNAL = 'history/journal.json';
    public const SNAP_RE = '~^history/([0-9a-f]{64})\.json$~';
    private const EMAIL_RE = '/[^\s@"]+@[^\s@"]+\.[a-z]{2,}/i';

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
        if (preg_match(self::EMAIL_RE, $text)) {
            $issue('warning', 'privacy', 'ב-manifest.json יש כתובת מייל — הוא לא אמור להכיל פרטים אישיים', 'manifest.json');
        }

        if (!array_key_exists('document.json', $raw)) {
            $issue('error', 'document-missing', 'חסר document.json');
        } else {
            $docBytes = $raw['document.json'];
            $f->openedDoc = $docBytes;
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
        self::checkHistory($f, $issue);
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

    /** היומן, או null אם אין או שהוא פגום. */
    public static function readJournal(array $files): ?array
    {
        $raw = $files[self::JOURNAL] ?? null;
        if ($raw === null) return null;
        try { $j = MbeCanonical::decode($raw); } catch (MbeCanonicalError) { return null; }
        if (!is_array($j) || array_is_list($j) || MbeSchema::typeOf($j['stages'] ?? null) !== 'array') return null;
        return $j;
    }

    // היסטוריה פגומה — אזהרות בלבד: היא לא מונעת פתיחה של המסמך עצמו.
    private static function checkHistory(MbeFile $f, callable $issue): void
    {
        foreach ($f->files as $p => $d) {
            $p = (string)$p;
            if (!str_starts_with($p, 'history/') || $p === self::JOURNAL) continue;
            if (preg_match(self::SNAP_RE, $p, $m) && $m[1] !== hash('sha256', $d)) $issue('warning', 'history-hash-mismatch', 'שם הגרסה אינו הגיבוב של התוכן', $p);
        }
        if (!isset($f->files[self::JOURNAL])) return;
        $j = self::readJournal($f->files);
        if ($j === null) { $issue('warning', 'history-invalid', 'history/journal.json אינו יומן תקין ({ stages: [] }) — נשמר כמו שהוא', self::JOURNAL); return; }
        foreach ($j['stages'] as $i => $s) {
            $at = self::JOURNAL . "#/stages/$i";
            if (!is_array($s) || array_is_list($s) || !is_string($s['kind'] ?? null) || !is_string($s['result'] ?? null)) {
                $issue('warning', 'history-invalid', 'שלב בלי kind או result', $at);
                continue;
            }
            if (empty($s['pruned']) && !isset($f->files["history/{$s['result']}.json"])) $issue('warning', 'history-missing', 'העותק של הגרסה ' . substr($s['result'], 0, 12) . ' חסר', $at);
            if (is_string($s['base'] ?? null) && !isset($f->files["history/{$s['base']}.json"])) {
                $prunedBase = false;
                foreach ($j['stages'] as $t) if (is_array($t) && ($t['result'] ?? null) === $s['base'] && !empty($t['pruned'])) $prunedBase = true;
                if (!$prunedBase) $issue('warning', 'history-base-missing', 'הבסיס ' . substr($s['base'], 0, 12) . ' לא נמצא בהיסטוריה', $at);
            }
            if (is_string($s['who'] ?? null) && preg_match(self::EMAIL_RE, $s['who'])) $issue('warning', 'privacy', 'ביומן יש כתובת מייל — who הוא כינוי, לא זהות', $at);
        }
    }

    /**
     * רושם את השמירה ביומן. שמירה בלי שינוי במסמך ובלי שלב מפורש — לא נוגעת ביומן. שמירות רצופות של אותה אפליקציה
     * ואותו who — מעדכנות את ה-result של השלב האחרון, והעותק הקודם נמחק אם אף שלב לא מפנה אליו. זהה ל-applyJournal ב-JS.
     */
    private static function applyJournal(MbeFile $f, array &$files, string $docSha, string $docBytes, string $app, ?string $who, string $at): void
    {
        $opened = $f->documentSha256();
        $base = ($opened !== null && $opened !== str_repeat('0', 64)) ? $opened : null;
        if (!$f->pending && $base === $docSha) return;
        if (isset($files[self::JOURNAL]) && self::readJournal($files) === null) return; // יומן פגום — נשמר כמו שהוא
        $j = self::readJournal($files) ?? ['stages' => []];
        if ($base !== null && $f->openedDoc !== null && !isset($files["history/$base.json"])) $files["history/$base.json"] = $f->openedDoc;
        $files["history/$docSha.json"] = $docBytes;
        $nextId = function () use (&$j): string {
            $max = 0;
            foreach ($j['stages'] as $s) if (is_array($s) && preg_match('/^s(\d+)$/', (string)($s['id'] ?? ''), $m)) $max = max($max, (int)$m[1]);
            return 's' . ($max + 1);
        };
        $entry = function (string $kind, ?string $w, ?string $note) use ($nextId, $at, $app, $base, $docSha): array {
            $e = ['id' => $nextId(), 'at' => $at, 'app' => $app, 'kind' => $kind, 'base' => $base, 'result' => $docSha];
            if ($w !== null && $w !== '') $e['who'] = $w;
            if ($note !== null && $note !== '') $e['note'] = $note;
            return $e;
        };
        if ($f->pending) {
            foreach ($f->pending as $p) {
                $e = $entry($p['kind'], ($p['who'] ?? '') !== '' ? $p['who'] : $who, $p['note'] ?? null);
                // שלב שהגרסה שלו אינה המסמך הנשמר (return במיזוג — מה שחזר מבחוץ): app / base / result משלו, והעותקים איתו
                foreach ($p['snapshots'] ?? [] as $sha => $b) $files["history/$sha.json"] ??= $b;
                if (!empty($p['app'])) $e['app'] = $p['app'];
                if (array_key_exists('base', $p)) $e['base'] = $p['base'];
                if (!empty($p['result'])) $e['result'] = $p['result'];
                $j['stages'][] = $e;
            }
        } else {
            $n = count($j['stages']);
            $last = $n ? $j['stages'][$n - 1] : null;
            if (is_array($last) && ($last['app'] ?? null) === $app && ($last['who'] ?? null) === $who
                && in_array($last['kind'] ?? null, ['edit', 'create'], true) && ($last['result'] ?? null) === $base && $base !== null) {
                $prev = $last['result'];
                $j['stages'][$n - 1]['result'] = $docSha;
                $j['stages'][$n - 1]['at'] = $at;
                $used = false;
                foreach ($j['stages'] as $s) if (is_array($s) && (($s['base'] ?? null) === $prev || ($s['result'] ?? null) === $prev)) $used = true;
                if (!$used) unset($files["history/$prev.json"]);
            } else {
                $j['stages'][] = $entry($base !== null ? 'edit' : 'create', $who, null);
            }
        }
        $files[self::JOURNAL] = MbeCanonical::encode($j);
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
                    // קבוצה בוליאנית חיה (union / subtract / intersect / exclude) נושאת d מחושב משלה — requests/spec-vector-g3.md
                    if (array_key_exists('boolean', $p)) {
                        if (is_string($p['d'] ?? null) && ($bad = self::pathProblem($p['d'])) !== null) $issue('error', 'path-d-invalid', "d לא תקין: $bad", "document.json#$at/d");
                    } elseif (array_key_exists('d', $p) || array_key_exists('transform', $p)) {
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
                // מסכה וקטורית של שכבת raster (requests/raster-studio-r2.md) — אותה בדיקה כמו נתיבי vector
                $vm = $l['vectorMask'] ?? null;
                if (is_array($vm) && is_string($vm['d'] ?? null) && ($bad = self::pathProblem($vm['d'])) !== null) {
                    $issue('error', 'path-d-invalid', "d לא תקין: $bad", "document.json#$at/vectorMask/d");
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
        // מזהה פסקה (מ-1.1): ייחודי בשטף, [A-Za-z0-9_]{1,32}. חסר — תקין (1.0), ו-save משלים. פגום או כפול — אזהרה, ו-save מחליף.
        foreach (MbeCanonical::sortedKeys($flows) as $fid) {
            $fl = MbeSchema::typeOf($flows[$fid]) === 'object' ? (array)$flows[$fid] : null;
            if (!$fl || ($fl['engine'] ?? null) !== 'prosemirror' || MbeSchema::typeOf($fl['doc'] ?? null) !== 'object') continue;
            $seen = [];
            self::walkBlocks($fl['doc'], "/flows/$fid/doc", function (array $node, string $at) use (&$seen, $issue, $fid) {
                $attrs = $node['attrs'] ?? null;
                $id = is_array($attrs) ? ($attrs['id'] ?? null) : null;
                if ($id === null) return;
                if (!is_string($id) || !preg_match(self::PARA_ID_RE, $id)) $issue('warning', 'para-id-invalid', 'מזהה פסקה לא תקין — יוחלף בשמירה', "document.json#$at/attrs/id");
                elseif (isset($seen[$id])) $issue('warning', 'para-id-duplicate', "המזהה \"$id\" מופיע פעמיים בשטף \"$fid\" — השני יוחלף בשמירה", "document.json#$at/attrs/id");
                if (is_string($id)) $seen[$id] = true;
            });
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

    // ---- מזהה פסקה (מ-1.1, mbe-format.md סעיף 3; requests/spec-para-id.md) ----

    public const PARA_ID_RE = '/^[A-Za-z0-9_]{1,32}$/';
    private const PARA_TYPES = ['paragraph', 'heading'];

    /** מזהה פסקה חדש: p + 10 תווים אלפאנומריים (בלי מקף — שם סימנייה ב-Word). */
    public static function paraId(): string
    {
        $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $out = 'p';
        while (strlen($out) < 11) {
            foreach (str_split(random_bytes(16)) as $c) {
                $b = ord($c);
                if ($b < 248 && strlen($out) < 11) $out .= $abc[$b % 62]; // 248 = 4×62 — בלי הטיה
            }
        }
        return $out;
    }

    /** שם הסימנייה הנסתרת של פסקה ב-docx, וחזרה ממנו. */
    public static function paragraphBookmark(string $id): string { return "_mbe_p$id"; }

    public static function bookmarkParaId(string $name): ?string
    {
        return preg_match('/^_mbe_p([A-Za-z0-9_]{1,32})$/', $name, $m) ? $m[1] : null;
    }

    /** עובר (לקריאה) על כל צומת בלוק של טקסט (paragraph, heading) — גם בתוך רשימות וטבלאות, בסדר המסמך. */
    private static function walkBlocks(mixed $node, string $where, callable $fn, int $depth = 0): void
    {
        if (!is_array($node) || array_is_list($node) || $depth > 200) return;
        if (in_array($node['type'] ?? null, self::PARA_TYPES, true)) $fn($node, $where);
        if (is_array($node['content'] ?? null) && array_is_list($node['content'])) {
            foreach ($node['content'] as $i => $c) self::walkBlocks($c, "$where/content/$i", $fn, $depth + 1);
        }
    }

    /** משלים מזהים בשטף אחד (ProseMirror): חסר, פגום או כפול — מקבל חדש. מזהה תקין לא משתנה. מחזיר כמה הושלמו. */
    public static function ensureIds(mixed &$doc): int
    {
        $seen = [];
        $n = 0;
        $walk = function (mixed &$node, int $depth) use (&$walk, &$seen, &$n): void {
            if (!is_array($node) || array_is_list($node) || $depth > 200) return;
            if (in_array($node['type'] ?? null, self::PARA_TYPES, true)) {
                $attrs = $node['attrs'] ?? null;
                $id = is_array($attrs) ? ($attrs['id'] ?? null) : null;
                if (is_string($id) && preg_match(self::PARA_ID_RE, $id) && !isset($seen[$id])) {
                    $seen[$id] = true;
                } else {
                    do { $id = self::paraId(); } while (isset($seen[$id]));
                    $seen[$id] = true;
                    if (!is_array($attrs) || array_is_list($attrs)) $attrs = []; // {} נשמר כ-stdClass — מחליפים במערך עם id
                    $attrs['id'] = $id;
                    $node['attrs'] = $attrs;
                    $n++;
                }
            }
            if (is_array($node['content'] ?? null) && array_is_list($node['content'])) {
                foreach ($node['content'] as &$c) $walk($c, $depth + 1);
                unset($c);
            }
        };
        $walk($doc, 0);
        return $n;
    }

    /** כל שטפי ה-prosemirror במסמך. רץ לבד ב-save. */
    public static function ensureParaIds(mixed &$document): int
    {
        if (!is_array($document) || !is_array($document['flows'] ?? null) || array_is_list($document['flows'])) return 0;
        $n = 0;
        foreach (MbeCanonical::sortedKeys($document['flows']) as $fid) {
            $fl = &$document['flows'][$fid];
            if (is_array($fl) && ($fl['engine'] ?? null) === 'prosemirror' && is_array($fl['doc'] ?? null)) $n += self::ensureIds($fl['doc']);
            unset($fl);
        }
        return $n;
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
     * asCopy ("שמירה כעותק" — id חדש ו-created חדש), who (כינוי לשלב ביומן; שינוי ב-app או ב-who פותח שלב חדש).
     */
    public static function save(MbeFile $f, array $opts): string
    {
        $app = (string)($opts['app'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $app)) throw new MbeError('save צריך app — מזהה האפליקציה ששומרת');
        if (!isset($f->files[self::PREVIEW])) {
            throw new MbeError('חסר renditions/preview.png — חובה בכל שמירה',
                [['level' => 'error', 'code' => 'preview-missing', 'message' => 'חסר preview.png', 'path' => self::PREVIEW]]);
        }
        self::ensureParaIds($f->document);
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
        $who = isset($opts['who']) && $opts['who'] !== '' ? (string)$opts['who'] : null;
        self::applyJournal($f, $files, $docSha, $docBytes, $app, $who, $stamp['at']);
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
        $f->pending = [];
        $f->openedDoc = $docBytes;
        $f->issues = $check->issues;
        return $out;
    }
}
