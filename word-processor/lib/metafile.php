<?php
/**
 * תמונות EMF / WMF (קובצי ציור של Windows — Word שומר כך לוגו ונייר מכתבים) → PNG. דפדפן ו-GD לא מציגים אותן.
 * הרבה מהן הן בפועל מעטפת לתמונת מפת סיביות אחת (EMR_STRETCHDIBITS / META_STRETCHDIB): כאן מוציאים אותה כמו שהיא.
 * קובץ עם ציור וקטורי של ממש (טקסט, קווים, צורות) — null, והתמונה נספרת בדוח הייבוא כ"לא נטענה".
 */

/** PNG, או null — לא EMF / WMF, או שיש בו יותר מתמונה אחת. */
function metafile_to_png(string $bytes): ?string
{
    $dib = null;
    if (strlen($bytes) >= 88 && unpack('V', $bytes)[1] === 1 && substr($bytes, 40, 4) === ' EMF') $dib = emf_dib($bytes);
    elseif (str_starts_with($bytes, "\xD7\xCD\xC6\x9A") || str_starts_with($bytes, "\x01\x00\x09\x00") || str_starts_with($bytes, "\x02\x00\x09\x00")) $dib = wmf_dib($bytes);
    if ($dib === null || !function_exists('imagecreatefrombmp')) return null;
    $hs = unpack('V', $dib)[1];
    if ($hs < 12 || $hs > strlen($dib)) return null;
    // BMP: כותרת קובץ (14 בתים) + ה-DIB. המידע מתחיל אחרי כותרת ה-DIB, הפלטה ומסכות הצבע
    if ($hs === 12) { $bits = unpack('v', substr($dib, 10, 2))[1]; $used = 0; $comp = 0; }
    else { $h = unpack('Vsize/Vw/Vh/vplanes/vbits/Vcomp/Vimg/Vx/Vy/Vused', substr($dib, 0, 36)); $bits = $h['bits']; $used = $h['used']; $comp = $h['comp']; }
    $pal = ($used ?: ($bits <= 8 ? 1 << $bits : 0)) * ($hs === 12 ? 3 : 4);
    $masks = $hs === 40 && in_array($comp, [3, 6], true) ? ($comp === 6 ? 16 : 12) : 0;
    $bmp = 'BM' . pack('VvvV', 14 + strlen($dib), 0, 0, 14 + $hs + $masks + $pal) . $dib;
    $im = @imagecreatefrombmp('data://image/bmp;base64,' . base64_encode($bmp));
    if (!$im) return null;
    ob_start();
    imagepng($im, null, 6);
    imagedestroy($im);
    return (string)ob_get_clean() ?: null;
}

/** EMF: ה-DIB של רשומת EMR_STRETCHDIBITS (81) / EMR_SETDIBITSTODEVICE (80) — אם היא הציור היחיד בקובץ. */
function emf_dib(string $b): ?string
{
    // רשומות של מצב וכותרות (לא מציירות): הכותרת, EOF, מערכת קואורדינטות, מצבי ציור, שמירה ושחזור, הערות
    $state = [1, 14, 9, 10, 11, 12, 13, 17, 18, 19, 20, 21, 22, 33, 34, 35, 36, 37, 39, 40, 48, 70, 98, 115];
    $dib = null;
    for ($p = 0, $n = strlen($b); $p + 8 <= $n;) {
        ['t' => $t, 'n' => $len] = unpack('Vt/Vn', substr($b, $p, 8));
        if ($len < 8 || $p + $len > $n) return null;
        if ($t === 81 || $t === 80) {
            if ($dib !== null) return null;
            // STRETCHDIBITS: offBmiSrc ב-48; SETDIBITSTODEVICE: ב-48 גם הוא (אחרי 6 / 8 שדות אחרים — ראו MS-EMF 2.3.1)
            ['ob' => $ob, 'cb' => $cb, 'obits' => $obits, 'cbits' => $cbits] = unpack('Vob/Vcb/Vobits/Vcbits', substr($b, $p + 48, 16));
            if ($ob + $cb > $len || $obits + $cbits > $len || $cb < 12) return null;
            $dib = substr($b, $p + $ob, $cb) . substr($b, $p + $obits, $cbits);
        } elseif (!in_array($t, $state, true)) return null;
        if ($t === 14) break;
        $p += $len;
    }
    return $dib;
}

/** WMF: ה-DIB של META_STRETCHDIB (0x0F43) / META_DIBSTRETCHBLT (0x0B41) — אם היא הציור היחיד בקובץ. */
function wmf_dib(string $b): ?string
{
    $p = str_starts_with($b, "\xD7\xCD\xC6\x9A") ? 22 : 0;   // כותרת "placeable"
    if ($p + 18 > strlen($b)) return null;
    $p += unpack('v', substr($b, $p + 2, 2))[1] * 2;          // META_HEADER — הגודל במילים
    // מצב ומערכת קואורדינטות: SetWindowOrg/Ext, SetViewportOrg/Ext, SetMapMode, SetBkMode, SetStretchBltMode, Save/RestoreDC, Escape
    $state = [0x020B, 0x020C, 0x020D, 0x020E, 0x0103, 0x0102, 0x0107, 0x001E, 0x0127, 0x0626, 0x0104, 0x0106];
    $dib = null;
    for ($n = strlen($b); $p + 6 <= $n;) {
        ['s' => $words, 'f' => $f] = unpack('Vs/vf', substr($b, $p, 6));
        if ($f === 0) break;                                   // META_EOF
        $len = $words * 2;
        if ($len < 6 || $p + $len > $n) return null;
        if ($f === 0x0F43 || $f === 0x0B41) {
            if ($dib !== null) return null;
            $dib = substr($b, $p + ($f === 0x0F43 ? 28 : 26), $len - ($f === 0x0F43 ? 28 : 26));
        } elseif (!in_array($f, $state, true)) return null;
        $p += $len;
    }
    return $dib;
}
