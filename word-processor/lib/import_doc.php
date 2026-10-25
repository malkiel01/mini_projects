<?php
/**
 * ייבוא ‎.doc — הפורמט הבינארי של Word 97–2003 (MS-DOC), בלי ספרייה חיצונית ובלי LibreOffice בשרת.
 *
 * קובץ OLE (Compound File — MS-CFB) שבתוכו WordDocument (הטקסט וה-FKP), ‏0Table / 1Table (טבלת הקטעים, הסגנונות,
 * הגופנים והרשימות) ו-Data (תמונות). הטקסט — לפי טבלת הקטעים (CLX); עיצוב תווים ופסקאות — מה-FKP (CHPX / PAPX).
 * עובר: פסקאות, כיוון ויישור, כותרות (סגנונות heading 1–9), מודגש / נטוי / קו תחתון / קו חוצה / גודל / צבע / גופן /
 * הדגשה / כתב עילי ותחתי, רשימות (תבליטים ומספור, בעומק), טבלאות, שבירת שורה ועמוד, קישורים (שדה HYPERLINK),
 * שדות אחרים — כתוצאתם, הערות שוליים, ותמונות בשורה (PNG / JPEG / DIB / EMF ‏/ WMF מ-Data).
 * לא עובר (ובדוח): צורות ותמונות צפות, כותרות עליונות / תחתונות, הערות, טבלה בתוך טבלה, קובץ מוצפן או Word 6/95.
 * השמירה — ‎.docx בלבד (אין כתיבה של ‎.doc).
 */

/** חתימת קובץ OLE (גם ‎.xls / ‎.ppt — מזוהים בהמשך לפי הזרם WordDocument). */
function is_doc(string $bytes): bool
{
    return str_starts_with($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
}

function import_doc(string $bytes, string $docId): array
{
    // קובץ פגום (חתוך, מבנה לא צפוי) — הודעה ברורה ולא שגיאת שרת
    set_error_handler(fn($no, $msg) => throw new ErrorException($msg, 0, $no));
    try {
        return (new DocImport($bytes, $docId))->run();
    } catch (ApiError $e) {
        throw $e;
    } catch (Throwable $e) {
        throw new ApiError('קובץ Word ישן (‎.doc) פגום או במבנה שלא נתמך — שמרו אותו ב-Word כ-docx ונסו שוב', 'bad_file');
    } finally {
        restore_error_handler();
    }
}

/** קורא Compound File (MS-CFB): הזרמים לפי שם. */
final class CfbReader
{
    private int $ssz;
    private int $mini;
    private int $cutoff;
    private array $fat = [];
    private array $minifat = [];
    private string $ministream = '';
    /** @var array<string,array{start:int,size:int}> */
    private array $entries = [];

    public function __construct(private string $b)
    {
        if (strlen($b) < 512 || !is_doc($b)) throw new ApiError('הקובץ אינו מסמך Word ישן תקין', 'bad_file');
        $h = unpack('vminor/vmajor/vorder/vshift/vminishift', substr($b, 0x18, 10));
        $this->ssz = 1 << min(16, max(7, $h['shift']));
        $this->mini = 1 << min(12, max(4, $h['minishift']));
        ['nfat' => $nfat, 'dir' => $dir] = unpack('Vnfat/Vdir', substr($b, 0x2C, 8));
        ['cutoff' => $this->cutoff, 'mf' => $mf, 'nmf' => $nmf, 'difat' => $difat, 'ndifat' => $ndifat] = unpack('Vcutoff/Vmf/Vnmf/Vdifat/Vndifat', substr($b, 0x38, 20));
        // מיקומי ה-FAT: 109 בכותרת, והשאר בשרשרת DIFAT
        $fatSecs = array_values(unpack('V109', substr($b, 0x4C, 436)));
        for ($s = $difat, $i = 0; $s < 0xFFFFFFFA && $i < $ndifat && $i < 10000; $i++) {
            $sec = $this->sector($s);
            $per = intdiv($this->ssz, 4) - 1;
            $fatSecs = array_merge($fatSecs, array_values(unpack("V$per", $sec)));
            $s = unpack('V', substr($sec, $per * 4, 4))[1];
        }
        $fatSecs = array_slice(array_filter($fatSecs, fn($s) => $s < 0xFFFFFFFA), 0, max(0, $nfat));
        foreach ($fatSecs as $s) $this->fat = array_merge($this->fat, array_values(unpack('V' . intdiv($this->ssz, 4), $this->sector($s))));
        // הספרייה: רשומות של 128 בתים
        $d = $this->chain($dir);
        for ($i = 0; $i + 128 <= strlen($d); $i += 128) {
            $len = unpack('v', substr($d, $i + 0x40, 2))[1];
            $type = ord($d[$i + 0x42]);
            if ($type === 0 || $len < 2 || $len > 64) continue;
            $name = mb_convert_encoding(substr($d, $i, $len - 2), 'UTF-8', 'UTF-16LE');
            ['start' => $start, 'size' => $size] = unpack('Vstart/Vsize', substr($d, $i + 0x74, 8));
            if ($type === 5) {   // השורש — הזרם המכיל של הזרמים הקטנים
                $this->ministream = substr($this->chain($start), 0, $size);
                $mfData = $this->chain($mf, $nmf);
                $this->minifat = strlen($mfData) >= 4 ? array_values(unpack('V' . intdiv(strlen($mfData), 4), $mfData)) : [];
            } elseif ($type === 2) $this->entries[$name] ??= ['start' => $start, 'size' => $size];
        }
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    public function stream(string $name): ?string
    {
        $e = $this->entries[$name] ?? null;
        if (!$e) return null;
        if ($e['size'] < $this->cutoff) {
            $out = '';
            for ($s = $e['start'], $i = 0; $s < 0xFFFFFFFA && $i < 1000000 && strlen($out) < $e['size']; $i++) {
                $out .= substr($this->ministream, $s * $this->mini, $this->mini);
                $s = $this->minifat[$s] ?? 0xFFFFFFFE;
            }
            return substr($out, 0, $e['size']);
        }
        return substr($this->chain($e['start']), 0, $e['size']);
    }

    private function sector(int $s): string
    {
        return substr($this->b, ($s + 1) * $this->ssz, $this->ssz);
    }

    private function chain(int $s, int $max = PHP_INT_MAX): string
    {
        $out = '';
        $seen = [];
        for ($i = 0; $s < 0xFFFFFFFA && $i < $max && !isset($seen[$s]); $i++) {
            $seen[$s] = true;
            $out .= $this->sector($s);
            $s = $this->fat[$s] ?? 0xFFFFFFFE;
        }
        return $out;
    }
}

final class DocImport
{
    private string $wd;
    private string $tbl;
    private ?string $data;
    private array $fib = [];
    /** @var array<int,array{0:int,1:int,2:int,3:bool}> קטעים: [cp מ, cp עד, fc, דחוס] */
    private array $pieces = [];
    private array $chpx = [];   // [[fc מ, fc עד, grpprl], …]
    private array $papx = [];   // [[fc מ, fc עד, istd, grpprl], …]
    private array $sti = [];    // istd => sti (1–9 — כותרות)
    private array $fonts = [];  // מספר → שם
    private array $lists = [];  // ilfo => [ilvl => nfc]
    private array $notes = [];
    private array $missingFonts = [];
    private array $footnotes = [];   // cp של הסימון בטקסט → ['text', 'content']

    public function __construct(string $bytes, private string $docId)
    {
        $cfb = new CfbReader($bytes);
        $wd = $cfb->stream('WordDocument');
        if ($wd === null) throw new ApiError('קובץ Office שאינו מסמך Word (אולי Excel או PowerPoint)', 'bad_file');
        if (strlen($wd) < 0x200 || unpack('v', $wd)[1] !== 0xA5EC) throw new ApiError('הקובץ אינו מסמך Word ישן תקין', 'bad_file');
        $flags = unpack('v', substr($wd, 0x0A, 2))[1];
        if ($flags & 0x0100) throw new ApiError('המסמך מוגן בסיסמה — פתחו אותו ב-Word ושמרו בלי סיסמה', 'bad_file');
        if (unpack('v', substr($wd, 2, 2))[1] < 0xC1) throw new ApiError('קובץ Word 6 / 95 — ישן מדי; שמרו אותו מחדש ב-Word כ-docx', 'bad_file');
        $this->wd = $wd;
        $this->tbl = (string)$cfb->stream($flags & 0x0200 ? '1Table' : '0Table');
        $this->data = $cfb->stream('Data');
        // FIB: base (32) → csw + fibRgW → cslw + fibRgLw → cbRgFcLcb + fibRgFcLcb
        $p = 32;
        $csw = unpack('v', substr($wd, $p, 2))[1];
        $p += 2 + $csw * 2;
        $cslw = unpack('v', substr($wd, $p, 2))[1];
        $lw = array_values(unpack('l' . $cslw, substr($wd, $p + 2, $cslw * 4)));
        $p += 2 + $cslw * 4;
        $cb = unpack('v', substr($wd, $p, 2))[1];
        $this->fib = ['ccpText' => $lw[3] ?? 0, 'ccpFtn' => $lw[4] ?? 0, 'ccpHdd' => $lw[5] ?? 0, 'ccpAtn' => $lw[7] ?? 0, 'ccpEdn' => $lw[8] ?? 0,
            'ccpTxbx' => $lw[9] ?? 0, 'fcLcb' => array_values(unpack('V' . ($cb * 2), substr($wd, $p + 2, $cb * 8)))];
    }

    /** [fc, lcb] של זוג מספר $i ב-fibRgFcLcb */
    private function fl(int $i): array
    {
        return [$this->fib['fcLcb'][$i * 2] ?? 0, $this->fib['fcLcb'][$i * 2 + 1] ?? 0];
    }

    public function run(): array
    {
        $this->readPieces();
        $this->readFkps(12, false);
        $this->readFkps(13, true);
        $this->readStyles();
        $this->readFonts();
        $this->readLists();
        $this->readFootnotes();
        $n = $this->fib['ccpText'];
        $blocks = $this->blocks(0, $n);
        if ($this->fib['ccpHdd'] > 0 && $this->storyHasText($this->fib['ccpText'] + $this->fib['ccpFtn'], $this->fib['ccpHdd'])) $this->note('doc_hf');
        if ($this->fib['ccpAtn'] > 0) $this->note('doc_comments');
        return ['json' => ['type' => 'doc', 'content' => $blocks ?: [para()]], 'page' => null, 'report' => $this->report()];
    }

    /** יש בטווח טקסט של ממש — בלי סופי פסקה ובלי שדות (מספר עמוד לבד בכותרת — לא תוכן שחסר) */
    private function storyHasText(int $from, int $len): bool
    {
        $t = implode('', array_column($this->chars($from, $from + $len), 0));
        $t = preg_replace('/\x13[^\x13\x15]*\x15/u', '', $t);
        return trim(preg_replace('/[\x00-\x1F]/u', '', $t)) !== '';
    }

    // ---------- הטקסט: טבלת הקטעים ----------

    private function readPieces(): void
    {
        [$fc, $lcb] = $this->fl(33);
        $clx = substr($this->tbl, $fc, $lcb);
        $p = 0;
        while ($p < strlen($clx) && $clx[$p] === "\x01") $p += 3 + unpack('v', substr($clx, $p + 1, 2))[1];   // Prc — מדלגים
        if (($clx[$p] ?? '') !== "\x02") throw new ApiError('הקובץ אינו מסמך Word ישן תקין (אין טבלת קטעים)', 'bad_file');
        $len = unpack('V', substr($clx, $p + 1, 4))[1];
        $plc = substr($clx, $p + 5, $len);
        $n = intdiv($len - 4, 12);
        $cps = array_values(unpack('V' . ($n + 1), $plc));
        for ($i = 0; $i < $n; $i++) {
            $raw = unpack('V', substr($plc, ($n + 1) * 4 + $i * 8 + 2, 4))[1];
            $comp = (bool)($raw & 0x40000000);
            $this->pieces[] = [$cps[$i], $cps[$i + 1], $comp ? intdiv($raw & 0x3FFFFFFF, 2) : $raw, $comp];
        }
    }

    /** התווים בטווח cp — [[תו (UTF-8), fc], …] */
    private function chars(int $from, int $to): array
    {
        $out = [];
        foreach ($this->pieces as [$a, $b, $fc, $comp]) {
            $s = max($a, $from);
            $e = min($b, $to);
            if ($s >= $e) continue;
            $k = $comp ? 1 : 2;
            $bytes = substr($this->wd, $fc + ($s - $a) * $k, ($e - $s) * $k);
            $txt = $comp ? mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252') : mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
            $arr = mb_str_split($txt);
            foreach ($arr as $j => $ch) $out[] = [$ch, $fc + ($s - $a + $j) * $k];
        }
        return $out;
    }

    // ---------- עיצוב: FKP ----------

    private function readFkps(int $idx, bool $para): void
    {
        [$fc, $lcb] = $this->fl($idx);
        if ($lcb < 8) return;
        $plc = substr($this->tbl, $fc, $lcb);
        $n = intdiv($lcb - 4, 8);
        $pns = array_values(unpack('V' . $n, substr($plc, ($n + 1) * 4, $n * 4)));
        foreach ($pns as $pn) {
            $page = substr($this->wd, ($pn & 0x3FFFFF) * 512, 512);
            if (strlen($page) < 512) continue;
            $crun = ord($page[511]);
            $rgfc = array_values(unpack('V' . ($crun + 1), $page));
            for ($i = 0; $i < $crun; $i++) {
                $off = ord($page[4 * ($crun + 1) + ($para ? $i * 13 : $i)]) * 2;
                if ($para) {
                    if (!$off) { $this->papx[] = [$rgfc[$i], $rgfc[$i + 1], 0, '']; continue; }
                    $cb = ord($page[$off]);
                    if ($cb === 0) { $len = ord($page[$off + 1]) * 2; $st = $off + 2; } else { $len = $cb * 2 - 1; $st = $off + 1; }
                    $this->papx[] = [$rgfc[$i], $rgfc[$i + 1], unpack('v', substr($page, $st, 2))[1], substr($page, $st + 2, max(0, $len - 2))];
                } else {
                    $this->chpx[] = [$rgfc[$i], $rgfc[$i + 1], $off ? substr($page, $off + 1, ord($page[$off])) : ''];
                }
            }
        }
        usort($this->chpx, fn($a, $b) => $a[0] <=> $b[0]);
        usort($this->papx, fn($a, $b) => $a[0] <=> $b[0]);
    }

    private static function find(array $runs, int $fc): ?array
    {
        $lo = 0;
        $hi = count($runs) - 1;
        while ($lo <= $hi) {
            $m = ($lo + $hi) >> 1;
            if ($fc < $runs[$m][0]) $hi = $m - 1;
            elseif ($fc >= $runs[$m][1]) $lo = $m + 1;
            else return $runs[$m];
        }
        return null;
    }

    /** grpprl → [[sprm, אופרנד], …] */
    private static function sprms(string $g): array
    {
        $out = [];
        $p = 0;
        $n = strlen($g);
        while ($p + 2 <= $n) {
            $sprm = unpack('v', substr($g, $p, 2))[1];
            $p += 2;
            $size = match ($sprm >> 13) { 0, 1 => 1, 2, 4, 5 => 2, 3 => 4, 7 => 3, default => -1 };
            if ($size < 0) {   // אורך משתנה
                if ($sprm === 0xD608 || $sprm === 0xD606) { $size = unpack('v', substr($g, $p, 2))[1] + 1; }
                else { $size = ord($g[$p] ?? "\0") + 1; }
            }
            $out[] = [$sprm, substr($g, $p, $size)];
            $p += $size;
        }
        return $out;
    }

    private const ICO = [1 => '000000', 2 => '0000ff', 3 => '00ffff', 4 => '00ff00', 5 => 'ff00ff', 6 => 'ff0000', 7 => 'ffff00', 8 => 'ffffff',
        9 => '000080', 10 => '008080', 11 => '008000', 12 => '800080', 13 => '800000', 14 => '808000', 15 => '808080', 16 => 'c0c0c0'];
    private const HIGHLIGHT = [2 => '#00ffff', 3 => '#00ffff', 4 => '#00ff00', 5 => '#ff00ff', 6 => '#ff0000', 7 => '#ffff00', 9 => '#0000ff', 11 => '#00ff00',
        12 => '#ff00ff', 13 => '#ff0000', 14 => '#ffff00', 15 => '#c0c0c0', 16 => '#c0c0c0'];

    /** תכונות תו מ-grpprl */
    private function charProps(string $g): array
    {
        $c = [];
        $on = fn(string $o) => in_array(ord($o), [1, 0x81], true);
        foreach (self::sprms($g) as [$s, $o]) {
            $u16 = fn() => unpack('v', str_pad($o, 2, "\0"))[1];
            match ($s) {
                0x0835 => $c['bold'] = $on($o), 0x0836 => $c['italic'] = $on($o), 0x0837, 0x2A53 => $c['strike'] = $on($o),
                0x085C => $c['boldBi'] = $on($o), 0x085D => $c['italicBi'] = $on($o), 0x085A => $c['bidi'] = $on($o),
                0x2A3E => $c['underline'] = ord($o) !== 0, 0x4A43 => $c['size'] = $u16(), 0x4A61 => $c['sizeBi'] = $u16(),
                0x2A42 => $c['color'] = self::ICO[ord($o)] ?? null,
                0x6870 => $c['color'] = ord($o[3] ?? "\0") === 0xFF ? null : bin2hex(substr($o, 0, 3)),
                0x4A4F => $c['font'] = $u16(), 0x4A5E => $c['fontBi'] = $u16(),
                0x2A0C => $c['highlight'] = self::HIGHLIGHT[ord($o)] ?? null,
                0x2A48 => $c['vert'] = ord($o),
                0x0855 => $c['spec'] = $on($o), 0x6A03 => $c['pic'] = unpack('V', str_pad($o, 4, "\0"))[1],
                0x083C => $c['hidden'] = $on($o),
                default => null,
            };
        }
        return $c;
    }

    /** תכונות פסקה */
    private function paraProps(int $istd, string $g): array
    {
        $p = ['istd' => $istd];
        foreach (self::sprms($g) as [$s, $o]) {
            match ($s) {
                0x2461 => $p['jc'] = ord($o), 0x2403 => $p['jc80'] = ord($o), 0x2441 => $p['bidi'] = ord($o) === 1,
                0x2416 => $p['inTable'] = ord($o) === 1, 0x2417 => $p['ttp'] = ord($o) === 1,
                0x6649 => $p['itap'] = unpack('V', str_pad($o, 4, "\0"))[1],
                0x460B => $p['ilfo'] = unpack('v', str_pad($o, 2, "\0"))[1], 0x260A => $p['ilvl'] = ord($o),
                0x2407 => $p['breakBefore'] = ord($o) === 1,
                default => null,
            };
        }
        return $p;
    }

    // ---------- סגנונות, גופנים, רשימות, הערות שוליים ----------

    private function readStyles(): void
    {
        [$fc, $lcb] = $this->fl(1);
        if ($lcb < 4) return;
        $s = substr($this->tbl, $fc, $lcb);
        $cbStshi = unpack('v', $s)[1];
        $cstd = unpack('v', substr($s, 2, 2))[1];
        $p = 2 + $cbStshi;
        for ($i = 0; $i < $cstd && $p + 2 <= strlen($s); $i++) {
            $cb = unpack('v', substr($s, $p, 2))[1];
            if ($cb >= 2) $this->sti[$i] = unpack('v', substr($s, $p + 2, 2))[1] & 0x0FFF;
            $p += 2 + $cb;
        }
    }

    private function readFonts(): void
    {
        [$fc, $lcb] = $this->fl(15);
        if ($lcb < 4) return;
        $s = substr($this->tbl, $fc, $lcb);
        $n = unpack('v', $s)[1];
        $p = 4;
        if ($n === 0xFFFF) { $n = unpack('v', substr($s, 2, 2))[1]; $p = 6; }
        for ($i = 0; $i < $n && $p < strlen($s); $i++) {
            $cb = ord($s[$p]);
            $name = substr($s, $p + 40, max(0, $cb - 39));
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-16LE');
            $this->fonts[$i] = trim(explode("\0", $name)[0]);
            $p += $cb + 1;
        }
    }

    /** רשימות: ilfo (1…) → lsid → LST → nfc לכל רמה (23 — תבליט) */
    private function readLists(): void
    {
        [$fc, $lcb] = $this->fl(73);
        [$fc2, $lcb2] = $this->fl(74);
        if ($lcb < 2 || $lcb2 < 4) return;
        $s = substr($this->tbl, $fc);   // הרמות (LVL) באות מיד אחרי PlfLst — מחוץ ל-lcb
        $c = unpack('v', $s)[1];
        $lsts = [];
        $p = 2;
        for ($i = 0; $i < $c; $i++) {
            $lsid = unpack('V', substr($s, $p, 4))[1];
            $simple = ord($s[$p + 26] ?? "\0") & 1;
            $lsts[] = [$lsid, $simple ? 1 : 9];
            $p += 28;
        }
        // אחרי PlfLst: הרמות (LVL) של כל רשימה, ברצף
        $p = $lcb;
        $lvlNfc = [];
        foreach ($lsts as [$lsid, $nLvl]) {
            for ($l = 0; $l < $nLvl && $p + 28 <= strlen($s); $l++) {
                $nfc = ord($s[$p + 4]);
                $cbChpx = ord($s[$p + 24]);
                $cbPapx = ord($s[$p + 25]);
                $p += 28 + $cbPapx + $cbChpx;
                $xch = unpack('v', substr($s, $p, 2))[1] ?? 0;
                $p += 2 + $xch * 2;
                $lvlNfc[$lsid][$l] = $nfc;
            }
        }
        $f = substr($this->tbl, $fc2, $lcb2);
        $nLfo = unpack('V', $f)[1];
        for ($i = 0; $i < $nLfo && 4 + $i * 16 + 4 <= strlen($f); $i++) {
            $lsid = unpack('V', substr($f, 4 + $i * 16, 4))[1];
            $this->lists[$i + 1] = $lvlNfc[$lsid] ?? [];
        }
    }

    private function readFootnotes(): void
    {
        if ($this->fib['ccpFtn'] <= 0) return;
        [$fcR, $lcbR] = $this->fl(2);
        [$fcT, $lcbT] = $this->fl(3);
        if ($lcbR < 4 || $lcbT < 8) return;
        $nR = intdiv($lcbR - 4, 6);
        $refs = array_values(unpack('V' . $nR, substr($this->tbl, $fcR, $nR * 4)));
        $nT = intdiv($lcbT, 4);
        $txt = array_values(unpack('V' . $nT, substr($this->tbl, $fcT, $nT * 4)));
        $base = $this->fib['ccpText'];
        foreach ($refs as $i => $cp) {
            if (!isset($txt[$i + 1])) break;
            $s = '';
            foreach ($this->chars($base + $txt[$i], $base + $txt[$i + 1]) as [$ch]) {
                if ($ch === "\x02") continue;   // הסימן של ההערה עצמה
                $s .= in_array($ch, ["\r", "\x0B", "\x07"], true) ? ' ' : ($ch < ' ' ? '' : $ch);
            }
            $this->footnotes[$cp] = ['text' => trim(preg_replace('/\s+/u', ' ', $s)), 'content' => null];
        }
    }

    // ---------- בלוקים ----------

    /** פסקאות → בלוקים (פסקה, כותרת, רשימה, טבלה) */
    private function blocks(int $from, int $to): array
    {
        $chars = $this->chars($from, $to);
        // פסקאות: עד \r (סוף פסקה), \x07 (סוף תא / שורה), \x0C (מעבר עמוד / מקטע)
        $units = [];
        $cur = [];
        $cp = $from;
        $pageBreak = false;
        foreach ($chars as [$ch, $fc]) {
            if ($ch === "\r" || $ch === "\x07" || $ch === "\x0C") {
                $pp = self::find($this->papx, $fc);
                $props = $pp ? $this->paraProps($pp[2], $pp[3]) : ['istd' => 0];
                if ($pageBreak) $props['breakBefore'] = true;
                $pageBreak = $ch === "\x0C";
                if ($ch === "\x0C" && !$cur) { $cp++; continue; }   // מעבר בפסקה ריקה — רק לפסקה הבאה
                $units[] = ['chars' => $cur, 'end' => $ch, 'p' => $props];
                $cur = [];
            } else {
                $cur[] = [$ch, $fc, $cp];
            }
            $cp++;
        }
        if ($cur) $units[] = ['chars' => $cur, 'end' => "\r", 'p' => ['istd' => 0]];

        $blocks = [];
        $listRun = [];
        $flushList = function () use (&$listRun, &$blocks) {
            if ($listRun) { array_push($blocks, ...$this->buildLists($listRun)); $listRun = []; }
        };
        $row = [];
        $cell = [];
        $rows = [];
        foreach ($units as $u) {
            $p = $u['p'];
            $inTable = !empty($p['inTable']) || ($p['itap'] ?? 0) > 0;
            if ($inTable) {
                $flushList();
                if (!empty($p['ttp'])) {   // סוף שורה
                    if ($cell) { $row[] = $cell; $cell = []; }
                    if ($row) $rows[] = $row;
                    $row = [];
                    continue;
                }
                if (($p['itap'] ?? 1) > 1) { $this->note('nested', 1, 'nested'); }
                $cell[] = $this->paragraph($u);
                if ($u['end'] === "\x07") { $row[] = $cell; $cell = []; }
                continue;
            }
            if ($rows || $row || $cell) {
                if ($cell) $row[] = $cell;
                if ($row) $rows[] = $row;
                $blocks[] = $this->table($rows);
                $rows = $row = $cell = [];
            }
            $ilfo = $p['ilfo'] ?? 0;
            if ($ilfo > 0 && isset($this->lists[$ilfo])) {
                $lvl = min(8, $p['ilvl'] ?? 0);
                $nfc = $this->lists[$ilfo][$lvl] ?? ($this->lists[$ilfo][0] ?? 0);
                $listRun[] = ['level' => $lvl, 'type' => $nfc === 23 ? 'bulletList' : 'orderedList', 'node' => $this->paragraph($u, true)];
                continue;
            }
            $flushList();
            $blocks[] = $this->paragraph($u);
        }
        $flushList();
        if ($rows || $row || $cell) {
            if ($cell) $row[] = $cell;
            if ($row) $rows[] = $row;
            $blocks[] = $this->table($rows);
        }
        return $blocks;
    }

    private function table(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $cells = array_map(fn($c) => ['type' => 'tableCell', 'attrs' => ['colspan' => 1, 'rowspan' => 1],
                'content' => array_values(array_map(fn($b) => $b['type'] === 'heading' ? ['type' => 'paragraph'] + array_diff_key($b, ['type' => 1, 'attrs' => 1]) : $b, $c)) ?: [para()]], $r);
            $out[] = ['type' => 'tableRow', 'content' => $cells];
        }
        return ['type' => 'table', 'content' => $out ?: [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [para()]]]]]];
    }

    /** פסקאות של רשימה (רמה, סוג) → רשימות מקוננות */
    private function buildLists(array $items, int $i = 0, int $level = -1, ?int &$next = null): array
    {
        $out = [];
        $n = count($items);
        while ($i < $n) {
            $lv = $items[$i]['level'];
            if ($level >= 0 && $lv <= $level) break;
            $type = $items[$i]['type'];
            $list = ['type' => $type, 'content' => []];
            while ($i < $n && $items[$i]['level'] === $lv && $items[$i]['type'] === $type) {
                $li = ['type' => 'listItem', 'content' => [$items[$i]['node']]];
                $i++;
                if ($i < $n && $items[$i]['level'] > $lv) {
                    $sub = $this->buildLists($items, $i, $lv, $j);
                    array_push($li['content'], ...$sub);
                    $i = $j;
                }
                $list['content'][] = $li;
            }
            $out[] = $list;
            if ($i < $n && $items[$i]['level'] === $lv) continue;   // סוג אחר באותה רמה — רשימה חדשה
            if ($level < 0 && $i < $n && $items[$i]['level'] < $lv) continue;
            if ($level >= 0) break;
        }
        $next = $i;
        return $out;
    }

    /** פסקה: הטקסט עם העיצוב, שדות, תמונות, הערות שוליים, שבירות */
    private function paragraph(array $u, bool $inList = false): array
    {
        $p = $u['p'];
        $inline = [];
        $fields = [];   // מחסנית: ['instr' => '', 'stage' => 0|1, 'href' => null]
        $hidden = 0;
        $latin = true;
        foreach ($u['chars'] as [$ch, $fc, $cp]) {
            if ($ch === "\x13") { $fields[] = ['instr' => '', 'stage' => 0, 'href' => null]; continue; }
            if ($ch === "\x14") {
                if ($fields) {
                    $f = &$fields[count($fields) - 1];
                    $f['stage'] = 1;
                    if (preg_match('/^\s*HYPERLINK\s+"([^"]+)"/i', $f['instr'], $m) && preg_match('#^(https?://|mailto:)#i', $m[1])) $f['href'] = $m[1];
                    elseif (!preg_match('/^\s*(HYPERLINK|PAGE|NUMPAGES)\b/i', $f['instr'])) $this->note('fields');
                    unset($f);
                }
                continue;
            }
            if ($ch === "\x15") { array_pop($fields); continue; }
            if ($fields && $fields[count($fields) - 1]['stage'] === 0) { $fields[count($fields) - 1]['instr'] .= $ch; continue; }
            $run = self::find($this->chpx, $fc);
            $c = $run ? $this->charProps($run[2]) : [];
            if (!empty($c['hidden'])) continue;
            if ($ch === "\x01" && !empty($c['spec'])) { if ($img = $this->picture($c['pic'] ?? null)) $inline[] = $img; continue; }
            if ($ch === "\x08" && !empty($c['spec'])) { $this->note('shapes'); continue; }
            if ($ch === "\x02" && !empty($c['spec'])) {
                if (isset($this->footnotes[$cp])) $inline[] = ['type' => 'footnote', 'attrs' => $this->footnotes[$cp]];
                continue;
            }
            if ($ch === "\x0B") { $inline[] = ['type' => 'hardBreak']; continue; }
            if ($ch === "\x09") $ch = "\t";
            elseif ($ch === "\x1E") $ch = '-';
            elseif ($ch === "\x1F") continue;
            elseif ($ch === "\xC2\xA0") $ch = "\u{00A0}";
            elseif ($ch < ' ' && $ch !== "\t") continue;
            if (preg_match('/[\x{0590}-\x{05FF}\x{0600}-\x{06FF}]/u', $ch)) $latin = false;
            $marks = $this->marks($c);
            $href = null;
            foreach ($fields as $f) if ($f['href']) $href = $f['href'];
            if ($href) $marks[] = ['type' => 'link', 'attrs' => ['href' => $href]];
            $last = count($inline) - 1;
            if ($last >= 0 && ($inline[$last]['type'] ?? '') === 'text' && ($inline[$last]['marks'] ?? []) == $marks) $inline[$last]['text'] .= $ch;
            else $inline[] = ['type' => 'text', 'text' => $ch] + ($marks ? ['marks' => $marks] : []);
        }
        $attrs = [];
        $jc = $p['jc'] ?? $p['jc80'] ?? null;
        $bidi = $p['bidi'] ?? false;
        if ($jc === 1) $attrs['textAlign'] = 'center';
        elseif (in_array($jc, [3, 4, 5], true)) $attrs['textAlign'] = 'justify';
        elseif ($jc === 2) $attrs['textAlign'] = $bidi ? 'left' : 'right';
        elseif ($jc === 0 && !$bidi && !$latin) $attrs['textAlign'] = 'left';
        if (!$bidi && $latin && $inline) $attrs['dir'] = 'ltr';
        if (!empty($p['breakBefore'])) $attrs['breakBefore'] = true;
        $sti = $this->sti[$p['istd']] ?? 0;
        $node = !$inList && $sti >= 1 && $sti <= 9
            ? ['type' => 'heading', 'attrs' => ['level' => min(6, $sti)] + $attrs]
            : ['type' => 'paragraph'] + ($attrs ? ['attrs' => $attrs] : []);
        if ($inline) $node['content'] = $inline;
        return $node;
    }

    private function marks(array $c): array
    {
        $bidi = !empty($c['bidi']);
        $m = [];
        if ($bidi ? ($c['boldBi'] ?? $c['bold'] ?? false) : ($c['bold'] ?? false)) $m[] = ['type' => 'bold'];
        if ($bidi ? ($c['italicBi'] ?? $c['italic'] ?? false) : ($c['italic'] ?? false)) $m[] = ['type' => 'italic'];
        if (!empty($c['underline'])) $m[] = ['type' => 'underline'];
        if (!empty($c['strike'])) $m[] = ['type' => 'strike'];
        if (($c['vert'] ?? 0) === 1) $m[] = ['type' => 'superscript'];
        if (($c['vert'] ?? 0) === 2) $m[] = ['type' => 'subscript'];
        $ts = [];
        $size = $bidi ? ($c['sizeBi'] ?? $c['size'] ?? null) : ($c['size'] ?? null);
        if ($size && $size !== 24) $ts['fontSize'] = ($size / 2) . 'pt';
        if (!empty($c['color']) && $c['color'] !== '000000') $ts['color'] = '#' . $c['color'];
        $fi = $bidi ? ($c['fontBi'] ?? $c['font'] ?? null) : ($c['font'] ?? null);
        $fname = $fi !== null ? ($this->fonts[$fi] ?? '') : '';
        if ($fname !== '') {
            if (isset(FontSet::FAMILIES[$fname])) $ts['fontFamily'] = $fname;
            elseif (!in_array($fname, ['Times New Roman', 'Arial', 'Symbol', 'Wingdings', 'Courier New'], true)) $this->missingFonts[$fname] = true;
        }
        if ($ts) $m[] = ['type' => 'textStyle', 'attrs' => $ts];
        if (!empty($c['highlight'])) $m[] = ['type' => 'highlight', 'attrs' => ['color' => $c['highlight']]];
        return $m;
    }

    // ---------- תמונות (זרם Data) ----------

    private function picture(?int $loc): ?array
    {
        if ($loc === null || $this->data === null || $loc + 68 > strlen($this->data)) { $this->note('images'); return null; }
        ['lcb' => $lcb, 'cbh' => $cbh] = unpack('Vlcb/vcbh', substr($this->data, $loc, 6));
        $pic = substr($this->data, $loc, min($lcb, strlen($this->data) - $loc));
        ['dxa' => $dxa, 'dya' => $dya, 'mx' => $mx, 'my' => $my] = unpack('vdxa/vdya/vmx/vmy', substr($pic, 0x1C, 8));
        $mm = unpack('v', substr($pic, 6, 2))[1];
        $p = $cbh + ($mm === 0x66 ? 1 + ord($pic[$cbh] ?? "\0") : 0);
        $bytes = null;
        // רשומות OfficeArt: מחפשים BLIP — JPEG / PNG / DIB / EMF / WMF
        for ($n = strlen($pic); $p + 8 <= $n && $bytes === null; ) {
            ['vi' => $vi, 'rt' => $rt, 'len' => $len] = unpack('vvi/vrt/Vlen', substr($pic, $p, 8));
            $inst = $vi >> 4;
            if (($vi & 0x0F) === 0x0F) { $p += 8; continue; }   // מכל — נכנסים פנימה
            if ($rt >= 0xF018 && $rt <= 0xF117) {
                $two = in_array($inst, [0x46B, 0x6E1, 0x7A9, 0x6E3, 0x3D5, 0x217], true) ? 32 : 16;
                $body = substr($pic, $p + 8, $len);
                if (in_array($rt, [0xF01D, 0xF01E, 0xF029, 0xF02A], true)) $bytes = substr($body, $two + 1);
                elseif ($rt === 0xF01F) $bytes = self::dibToBmp(substr($body, $two + 1));
                elseif ($rt === 0xF01A || $rt === 0xF01B) {
                    $z = substr($body, $two + 34);
                    $comp = ord($body[$two + 32] ?? "\0");
                    $bytes = $comp === 0 ? (@gzuncompress($z) ?: @gzinflate($z) ?: null) : $z;
                    if ($bytes !== null && $rt === 0xF01B) $bytes = "\xD7\xCD\xC6\x9A" . str_repeat("\0", 18) . $bytes;   // WMF בלי כותרת placeable
                }
            } elseif ($rt === 0xF007) {   // FBSE — ה-BLIP בתוכו, אחרי 36 בתים ושם (cbName בבית 33)
                $p += 8 + 36 + ord($pic[$p + 8 + 33] ?? "\0");
                continue;
            }
            $p += 8 + $len;
        }
        if ($bytes === null) { $this->note('images'); return null; }
        try { $m = media_store($this->docId, $bytes); } catch (ApiError $e) { $this->note('images'); return null; }
        $w = $dxa > 0 ? (int)round($dxa * max(1, $mx) / 1000 / 15) : $m['width'];
        $h = $dya > 0 ? (int)round($dya * max(1, $my) / 1000 / 15) : $m['height'];
        if ($w > 640) { $h = (int)round($h * 640 / $w); $w = 640; }
        return ['type' => 'image', 'attrs' => ['media' => $m['id'], 'width' => max(4, $w), 'height' => max(4, $h), 'wrap' => 'inline', 'align' => 'right']];
    }

    private static function dibToBmp(string $dib): ?string
    {
        if (strlen($dib) < 12) return null;
        $hs = unpack('V', $dib)[1];
        $h = unpack('Vsize/Vw/Vh/vplanes/vbits/Vcomp/Vimg/Vx/Vy/Vused', str_pad(substr($dib, 0, 36), 36, "\0"));
        $pal = ($h['used'] ?: ($h['bits'] <= 8 ? 1 << $h['bits'] : 0)) * 4;
        $masks = $hs === 40 && $h['comp'] === 3 ? 12 : 0;
        $bmp = 'BM' . pack('VvvV', 14 + strlen($dib), 0, 0, 14 + $hs + $masks + $pal) . $dib;
        $im = @imagecreatefrombmp('data://image/bmp;base64,' . base64_encode($bmp));
        if (!$im) return null;
        ob_start();
        imagepng($im);
        imagedestroy($im);
        return (string)ob_get_clean();
    }

    // ---------- הדוח ----------

    private function note(string $k, int $n = 1, ?string $once = null): void
    {
        static $seen = [];
        if ($once !== null) { if (isset($seen[spl_object_id($this) . $k . $once])) return; $seen[spl_object_id($this) . $k . $once] = true; }
        $this->notes[$k] = ($this->notes[$k] ?? 0) + $n;
    }

    private function report(): array
    {
        $out = [];
        foreach (IMPORT_REPORT as $k => [$label, $detail]) {
            $n = $k === 'fonts' ? count($this->missingFonts) : ($this->notes[$k] ?? 0);
            if (!$n) continue;
            if ($k === 'fonts') $detail = implode(', ', array_keys($this->missingFonts)) . ' — ' . $detail;
            $out[] = ['key' => $k, 'label' => $label, 'count' => $n, 'detail' => $detail];
        }
        return $out;
    }
}
