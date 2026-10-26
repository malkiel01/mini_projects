<?php
/**
 * פנים (mbe-format.md סעיף 7, docs/spec/hub-api.md 2.1–2.2): מחתימים docx / png שיוצאים מהחבילה ב-mbe-doc-id וב-mbe-base,
 * ומזהים קובץ שחוזר. את התוכן עצמו (docx ↔ שטף) ממירה האפליקציה; כאן — רק המזהים. אותו קוד כמו src/js/face.js.
 *
 *   require_once __DIR__ . '/mbe/face.php';
 *   $out = Mbe::save($f, ['app' => 'word-processor']);   // קודם שומרים — mbe-base הוא הגיבוב של מה שנשמר
 *   $docx = MbeFace::stamp($f, 'docx', $bytes);
 *   $id = MbeFace::identify($returned);                   // ['type' => 'mbe'|'face'|'foreign', 'format', 'docId', 'base', 'model', 'document'?, 'issues']
 */

declare(strict_types=1);

require_once __DIR__ . '/mbe.php';

final class MbeFace
{
    public const NS = 'urn:mbe:document:1';
    private const XMP_NS = 'urn:mbe:face:1';
    private const FMTID = '{D5CDD505-2E9C-101B-9397-08002B2CF9AE}';
    private const CT_CUSTOM = 'application/vnd.openxmlformats-officedocument.custom-properties+xml';
    private const CT_ITEMPROPS = 'application/vnd.openxmlformats-officedocument.customXmlProperties+xml';
    private const REL_CUSTOM = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/custom-properties';
    private const REL_CUSTOMXML = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXml';
    private const REL_ITEMPROPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXmlProps';
    private const RELS_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const PNG_SIG = "\x89PNG\r\n\x1a\n";

    private static function fail(string $code, string $message): MbeError
    {
        return new MbeError($message, [['level' => 'error', 'code' => $code, 'message' => $message]]);
    }

    /** המזהים של הפנים — רק אחרי save: המסמך הנוכחי זהה למה שנשמר, ויש לו id. */
    private static function ids(MbeFile $f): array
    {
        $docBytes = MbeCanonical::encode($f->document);
        $base = hash('sha256', $docBytes);
        if ($f->id() === null || $base !== $f->documentSha256()) {
            throw self::fail('face-not-saved', 'מחתימים פנים רק אחרי save — המסמך השתנה מאז השמירה, או שעוד אין לו id');
        }
        return ['docId' => $f->id(), 'base' => $base, 'docBytes' => $docBytes];
    }

    /** מחתים פנים: 'docx' או 'png'. ל-PDF — xmpPacket($f), שהכותב מטמיע בזמן היצירה. */
    public static function stamp(MbeFile $f, string $format, string $bytes): string
    {
        $ids = self::ids($f);
        $out = match ($format) {
            'docx' => self::stampDocx($bytes, $ids),
            'png' => self::stampPng($bytes, $ids),
            default => null,
        };
        if ($out !== null) { self::exported($f, $format); return $out; }
        if ($format === 'pdf') throw self::fail('face-stamp-failed', 'PDF מחתימים בזמן הכתיבה: xmpPacket($f) → מטא-נתונים של המסמך');
        throw self::fail('face-stamp-failed', "פנים מסוג $format אינן נתמכות");
    }

    /** הפנים נרשמות ביומן כשלב export (hub-api.md 2.2) — בשמירה הבאה, פעם אחת לכל פורמט. */
    private static function exported(MbeFile $f, string $format): void
    {
        foreach ($f->pending as $p) if ($p['kind'] === 'export' && $p['note'] === $format) return;
        $f->pending[] = ['kind' => 'export', 'who' => null, 'note' => $format];
    }

    /** חבילת XMP עם המזהים — להטמעה כ-Metadata של ה-PDF (לא דחוס, כדי ש-identify ימצא). */
    public static function xmpPacket(MbeFile $f): string
    {
        ['docId' => $docId, 'base' => $base] = self::ids($f);
        self::exported($f, 'pdf');
        return "<?xpacket begin=\"\u{FEFF}\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n<x:xmpmeta xmlns:x=\"adobe:ns:meta/\"><rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">"
            . '<rdf:Description rdf:about="" xmlns:mbe="' . self::XMP_NS . "\"><mbe:docId>$docId</mbe:docId><mbe:base>$base</mbe:base></rdf:Description>"
            . "</rdf:RDF></x:xmpmeta>\n<?xpacket end=\"w\"?>";
    }

    // ---- docx ----

    private static function esc(string $s): string { return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s); }

    private static function unesc(string $s): string
    {
        return preg_replace_callback('/&(lt|gt|quot|apos|amp|#\d+|#x[0-9a-fA-F]+);/', function ($m) {
            $e = $m[1];
            return match ($e) {
                'lt' => '<', 'gt' => '>', 'quot' => '"', 'apos' => "'", 'amp' => '&',
                default => mb_chr($e[1] === 'x' ? (int)hexdec(substr($e, 2)) : (int)substr($e, 1), 'UTF-8') ?: '',
            };
        }, $s);
    }

    private static function addRel(?string $xml, string $type, string $target): string
    {
        $xml ??= "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<Relationships xmlns=\"" . self::RELS_NS . '"></Relationships>';
        if (str_contains($xml, "Target=\"$target\"")) return $xml;
        $n = 1;
        while (str_contains($xml, "Id=\"rIdMbe$n\"")) $n++;
        return preg_replace('/<\/Relationships>\s*$/', "<Relationship Id=\"rIdMbe$n\" Type=\"$type\" Target=\"$target\"/></Relationships>", $xml);
    }

    private static function ctOverride(string $xml, string $part, string $type): string
    {
        if (str_contains($xml, "PartName=\"$part\"")) return $xml;
        return preg_replace('/<\/Types>\s*$/', "<Override PartName=\"$part\" ContentType=\"$type\"/></Types>", $xml);
    }

    private static function ctDefaultXml(string $xml): string
    {
        if (preg_match('/<Default\b[^>]*\bExtension="xml"/i', $xml)) return $xml;
        return preg_replace('/(<Types\b[^>]*>)/', '$1<Default Extension="xml" ContentType="application/xml"/>', $xml, 1);
    }

    private static function customProps(?string $old, array $props): string
    {
        $xml = $old ?? "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<Properties xmlns=\"http://schemas.openxmlformats.org/officeDocument/2006/custom-properties\" xmlns:vt=\"http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes\"></Properties>";
        $xml = preg_replace('/<property\b[^>]*\bname="mbe-(?:doc-id|base)"[^>]*>[\s\S]*?<\/property>/', '', $xml);
        if (preg_match('/<Properties\b[^>]*\/>/', $xml)) $xml = preg_replace('/<Properties\b([^>]*)\/>/', '<Properties$1></Properties>', $xml, 1);
        preg_match_all('/\bpid="(\d+)"/', $xml, $m);
        $pid = max([1, ...array_map('intval', $m[1])]);
        $add = '';
        foreach ($props as $name => $v) $add .= '<property fmtid="' . self::FMTID . '" pid="' . (++$pid) . "\" name=\"$name\"><vt:lpwstr>" . self::esc($v) . '</vt:lpwstr></property>';
        return preg_replace('/<\/Properties>\s*$/', $add . '</Properties>', $xml);
    }

    /** @return array<string,string> החלקים לפי שם, בסדר המקורי */
    private static function parts(string $bytes): array
    {
        $z = MbeZip::readZip($bytes);
        $issues = $z['issues'];
        $files = [];
        foreach ($z['entries'] as $e) {
            if ($e['dir']) continue;
            $d = MbeZip::read($bytes, $e, $issues);
            if ($d === null) throw self::fail('face-stamp-failed', "החלק {$e['name']} פגום");
            $files[$e['name']] = $d;
        }
        return $files;
    }

    private static function stampDocx(string $bytes, array $ids): string
    {
        $files = self::parts($bytes);
        if (!$files) throw self::fail('face-stamp-failed', 'ה-docx אינו ZIP תקין');
        if (!isset($files['[Content_Types].xml'], $files['word/document.xml'])) throw self::fail('face-stamp-failed', 'זה אינו docx');
        ['docId' => $docId, 'base' => $base, 'docBytes' => $docBytes] = $ids;

        $files['docProps/custom.xml'] = self::customProps($files['docProps/custom.xml'] ?? null, ['mbe-doc-id' => $docId, 'mbe-base' => $base]);
        // המודל בגרסה שיצאה — חלק customXml שלנו (מזוהה לפי ה-namespace); קיים — מחליפים, אחרת — מספר פנוי
        $n = 0; $max = 0;
        foreach ($files as $name => $d) {
            if (!preg_match('/^customXml\/item(\d+)\.xml$/', (string)$name, $m)) continue;
            $max = max($max, (int)$m[1]);
            if (!$n && str_contains($d, self::NS)) $n = (int)$m[1];
        }
        $n = $n ?: $max + 1;
        $files["customXml/item$n.xml"] = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<mbe:document xmlns:mbe=\"" . self::NS . "\" base=\"$base\">" . self::esc($docBytes) . '</mbe:document>';
        $files["customXml/itemProps$n.xml"] ??= "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"no\"?>\n<ds:datastoreItem ds:itemID=\"{" . strtoupper(Mbe::newId()) . '}" xmlns:ds="http://schemas.openxmlformats.org/officeDocument/2006/customXml"><ds:schemaRefs/></ds:datastoreItem>';
        $files["customXml/_rels/item$n.xml.rels"] = self::addRel($files["customXml/_rels/item$n.xml.rels"] ?? null, self::REL_ITEMPROPS, "itemProps$n.xml");
        $files['word/_rels/document.xml.rels'] = self::addRel($files['word/_rels/document.xml.rels'] ?? null, self::REL_CUSTOMXML, "../customXml/item$n.xml");
        $files['_rels/.rels'] = self::addRel($files['_rels/.rels'] ?? null, self::REL_CUSTOM, 'docProps/custom.xml');
        $ct = self::ctDefaultXml($files['[Content_Types].xml']);
        $ct = self::ctOverride($ct, '/docProps/custom.xml', self::CT_CUSTOM);
        $files['[Content_Types].xml'] = self::ctOverride($ct, "/customXml/itemProps$n.xml", self::CT_ITEMPROPS);

        $out = [];
        foreach ($files as $name => $data) $out[] = ['name' => (string)$name, 'data' => $data];
        return MbeZip::writeZip($out);
    }

    // ---- png ----

    /** @return ?list<array{type:string,data:string,raw:string}> */
    private static function pngChunks(string $b): ?array
    {
        if (strlen($b) < 8 || substr($b, 0, 8) !== self::PNG_SIG) return null;
        $out = [];
        for ($p = 8; $p + 12 <= strlen($b);) {
            $len = unpack('N', $b, $p)[1];
            if ($p + 12 + $len > strlen($b)) return null;
            $out[] = ['type' => substr($b, $p + 4, 4), 'data' => substr($b, $p + 8, $len), 'raw' => substr($b, $p, 12 + $len)];
            $p += 12 + $len;
        }
        return $out;
    }

    private static function itxt(string $keyword, string $text): string
    {
        $data = "$keyword\0\0\0\0\0$text"; // keyword, null, compression flag 0, method 0, language "", translated ""
        return pack('N', strlen($data)) . 'iTXt' . $data . pack('N', crc32('iTXt' . $data));
    }

    private static function keyword(string $data): string
    {
        $z = strpos($data, "\0");
        return $z ? substr($data, 0, $z) : '';
    }

    private static function stampPng(string $bytes, array $ids): string
    {
        $chunks = self::pngChunks($bytes);
        if (!$chunks || $chunks[0]['type'] !== 'IHDR') throw self::fail('face-stamp-failed', 'זה אינו PNG תקין');
        $keep = array_values(array_filter($chunks, fn($c) => !($c['type'] === 'iTXt' && preg_match('/^mbe-(doc-id|base)$/', self::keyword($c['data'])))));
        $out = self::PNG_SIG . $keep[0]['raw'] . self::itxt('mbe-doc-id', $ids['docId']) . self::itxt('mbe-base', $ids['base']);
        foreach (array_slice($keep, 1) as $c) $out .= $c['raw'];
        return $out;
    }

    // ---- identify ----

    /** "מה הקובץ הזה?" — קורא רק את המזהים, לא זורק. */
    public static function identify(string $bytes): array
    {
        $r = ['type' => 'foreign', 'format' => null, 'docId' => null, 'base' => null, 'model' => false, 'issues' => []];
        try {
            if (str_starts_with($bytes, 'PK')) self::identifyZip($bytes, $r);
            elseif (str_starts_with($bytes, '%PDF-')) self::identifyPdf($bytes, $r);
            elseif (str_starts_with($bytes, self::PNG_SIG)) self::identifyPng($bytes, $r);
        } catch (Throwable $e) {
            $r['issues'][] = ['level' => 'warning', 'code' => 'face-unknown-doc', 'message' => 'לא הצלחתי לקרוא את המזהים: ' . $e->getMessage()];
        }
        if ($r['docId'] !== null && !preg_match(Mbe::ID_RE, $r['docId'])) {
            $r['issues'][] = ['level' => 'warning', 'code' => 'face-unknown-doc', 'message' => 'mbe-doc-id אינו UUID v4'];
            $r['docId'] = null;
        }
        if ($r['base'] !== null && !preg_match('/^[0-9a-f]{64}$/', $r['base'])) {
            $r['issues'][] = ['level' => 'warning', 'code' => 'face-unknown-doc', 'message' => 'mbe-base אינו sha256'];
            $r['base'] = null;
        }
        if ($r['type'] !== 'mbe') $r['type'] = $r['docId'] !== null && $r['docId'] !== '' ? 'face' : 'foreign';
        return $r;
    }

    private static function identifyZip(string $bytes, array &$r): void
    {
        $z = MbeZip::readZip($bytes);
        $issues = [];
        $by = [];
        foreach ($z['entries'] as $e) $by[$e['name']] = $e;
        $get = fn(string $n) => isset($by[$n]) ? MbeZip::read($bytes, $by[$n], $issues) : null;
        if (isset($by['mimetype'], $by['manifest.json'])) {
            $m = MbeCanonical::decode($get('manifest.json') ?? '');
            $m = is_array($m) ? $m : [];
            $r['type'] = 'mbe'; $r['format'] = 'mbe'; $r['model'] = true;
            $r['docId'] = is_string($m['id'] ?? null) ? $m['id'] : null;
            $d = $m['document'] ?? null;
            $r['base'] = is_array($d) && is_string($d['sha256'] ?? null) ? $d['sha256'] : null;
            return;
        }
        if (!isset($by['[Content_Types].xml'], $by['word/document.xml'])) return;
        $r['format'] = 'docx';
        $custom = $get('docProps/custom.xml');
        if ($custom !== null) {
            $prop = function (string $name) use ($custom) {
                return preg_match('/<property\b[^>]*\bname="' . $name . '"[^>]*>\s*<(?:\w+:)?lpwstr>([^<]*)</', $custom, $m) ? trim(self::unesc($m[1])) : null;
            };
            $r['docId'] = $prop('mbe-doc-id');
            $r['base'] = $prop('mbe-base');
        }
        foreach ($by as $name => $e) {
            if (!preg_match('/^customXml\/item\d+\.xml$/', (string)$name)) continue;
            $t = MbeZip::read($bytes, $e, $issues) ?? '';
            if (!str_contains($t, self::NS)) continue;
            if (!preg_match('/<(\w+:)?document\b[^>]*>([\s\S]*)<\/\1?document>/', $t, $m)) continue;
            try {
                $r['document'] = MbeCanonical::decode(self::unesc($m[2]));
                $r['model'] = true;
            } catch (Throwable $err) {
                $r['issues'][] = ['level' => 'warning', 'code' => 'face-base-missing', 'message' => 'המודל ב-customXml פגום: ' . $err->getMessage()];
            }
            break;
        }
    }

    private static function identifyPdf(string $bytes, array &$r): void
    {
        $r['format'] = 'pdf';
        if (!str_contains($bytes, self::XMP_NS)) return;
        $pick = fn(string $n) => preg_match('/mbe:' . $n . '(?:>|="|=\'|\s*=\s*")([^<"\']+)/', $bytes, $m) ? trim($m[1]) : null;
        $r['docId'] = $pick('docId');
        $r['base'] = $pick('base');
    }

    private static function identifyPng(string $bytes, array &$r): void
    {
        $r['format'] = 'png';
        foreach (self::pngChunks($bytes) ?? [] as $c) {
            if ($c['type'] !== 'iTXt') continue;
            $k = self::keyword($c['data']);
            if ($k !== 'mbe-doc-id' && $k !== 'mbe-base') continue;
            // keyword\0 flag method language\0 translated\0 text
            $p = strlen($k) + 3;
            for ($i = 0; $i < 2 && $p !== false; $i++) $p = ($z = strpos($c['data'], "\0", $p)) === false ? false : $z + 1;
            if ($p === false) continue;
            $v = trim(substr($c['data'], $p));
            if ($k === 'mbe-doc-id') $r['docId'] = $v; else $r['base'] = $v;
        }
    }
}
