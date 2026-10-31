<?php
/**
 * עזרי תמונה לשכבת ה-AI (GD): בדיקת קלט, התאמה לגודל, מסכה בפורמט של OpenAI, תיבה של מסכה, וגודל נתמך קרוב.
 * כל הפלט PNG ב-sRGB עם אלפא ישר (docs/spec-3-ai.md, סעיף 2).
 */

declare(strict_types=1);

const AI_MAX_BYTES = 20 * 1024 * 1024;
const AI_MAX_SIDE = 4096;

function aiImage(string $bytes): GdImage {
    if (strlen($bytes) > AI_MAX_BYTES) throw new AppError('התמונה גדולה מ-20MB', 413, 'too-big');
    $info = @getimagesizefromstring($bytes);
    if (!$info || $info[0] < 1 || $info[1] < 1) throw new AppError('הקובץ אינו תמונה', 400, 'bad_request');
    if ($info[0] > AI_MAX_SIDE || $info[1] > AI_MAX_SIDE) throw new AppError('התמונה גדולה מ-4096 פיקסלים בצלע', 413, 'too-big');
    $im = @imagecreatefromstring($bytes);
    if (!$im) throw new AppError('לא הצלחנו לקרוא את התמונה', 400, 'bad_request');
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    return $im;
}

function aiPng(GdImage $im): string {
    imagesavealpha($im, true);
    ob_start();
    imagepng($im, null, 6);
    return (string) ob_get_clean();
}

/** [w, h] של תמונה בבתים. */
function aiSize(string $bytes): array {
    $i = @getimagesizefromstring($bytes);
    if (!$i) throw new AppError('הספק החזיר תמונה לא תקינה', 502, 'provider');
    return [(int) $i[0], (int) $i[1]];
}

/** מחזיר PNG בגודל [w, h] בדיוק (מתיחה — הספקים מחזירים את אותו יחס, או קרוב מאוד). */
function aiFit(string $bytes, int $w, int $h): string {
    $src = @imagecreatefromstring($bytes);
    if (!$src) throw new AppError('הספק החזיר תמונה לא תקינה', 502, 'provider');
    if (imagesx($src) === $w && imagesy($src) === $h && str_starts_with($bytes, "\x89PNG")) return $bytes;
    $dst = imagecreatetruecolor($w, $h);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
    return aiPng($dst);
}

/** "1080x1350" → [1080, 1350]. */
function aiParseSize(mixed $s): ?array {
    if (!is_string($s) || !preg_match('/^(\d{2,5})x(\d{2,5})$/', $s, $m)) return null;
    [$w, $h] = [(int) $m[1], (int) $m[2]];
    if ($w > AI_MAX_SIDE * 2 || $h > AI_MAX_SIDE * 2) throw new AppError('גודל לא תקין', 400, 'too-big');
    return [$w, $h];
}

/** מבין גדלים נתמכים — זה שהיחס שלו הכי קרוב לבקשה (סעיף 2, "size ביחס חופשי"). $options: ['1024x1536', …]. */
function aiNearest(array $want, array $options): string {
    $r = $want[0] / $want[1];
    $best = $options[0];
    $bestD = INF;
    foreach ($options as $o) {
        [$w, $h] = aiParseSize($o);
        $d = abs(log(($w / $h) / $r));
        if ($d < $bestD) { $bestD = $d; $best = $o; }
    }
    return $best;
}

/**
 * מסכה שלנו (לבן = למלא) → מסכה של OpenAI: PNG בגודל התמונה, שקוף איפה שעורכים.
 */
function aiMaskToAlpha(string $mask, int $w, int $h): string {
    $m = imagecreatefromstring(aiFit($mask, $w, $h));
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorsforindex($m, imagecolorat($m, $x, $y));
            $lum = ($c['red'] + $c['green'] + $c['blue']) / 3;
            // לבן → שקוף לגמרי (127); שחור → אטום (0)
            imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, 0, 0, 0, (int) round($lum / 255 * 127)));
        }
    }
    return aiPng($out);
}

/**
 * מסכה כלשהי מהספק → מסכת גוונים בגודל [w, h] (לבן = העצם) ותיבה [x, y, w, h] סביב מה שלבן.
 * null — מסכה ריקה.
 */
function aiMaskBox(string $mask, int $w, int $h): ?array {
    $png = aiFit($mask, $w, $h);
    $m = imagecreatefromstring($png);
    // דוגמים כל n פיקסלים — מספיק לתיבה, ומהיר גם ב-4096
    $step = max(1, (int) floor(max($w, $h) / 512));
    [$x0, $y0, $x1, $y1] = [$w, $h, -1, -1];
    for ($y = 0; $y < $h; $y += $step) {
        for ($x = 0; $x < $w; $x += $step) {
            $c = imagecolorsforindex($m, imagecolorat($m, $x, $y));
            $on = $c['alpha'] < 64 && ($c['red'] + $c['green'] + $c['blue']) > 384;
            if ($on) { $x0 = min($x0, $x); $y0 = min($y0, $y); $x1 = max($x1, $x); $y1 = max($y1, $y); }
        }
    }
    if ($x1 < 0) return null;
    $x1 = min($w - 1, $x1 + $step - 1);
    $y1 = min($h - 1, $y1 + $step - 1);
    return ['png' => $png, 'box' => [$x0, $y0, $x1 - $x0 + 1, $y1 - $y0 + 1]];
}

/** האם יש בתמונה פיקסל שקוף (לבדוק אם הספק באמת החזיר רקע שקוף). */
function aiHasAlpha(string $bytes): bool {
    $im = @imagecreatefromstring($bytes);
    if (!$im) return false;
    [$w, $h] = [imagesx($im), imagesy($im)];
    $step = max(1, (int) floor(max($w, $h) / 64));
    for ($y = 0; $y < $h; $y += $step) {
        for ($x = 0; $x < $w; $x += $step) {
            if ((imagecolorat($im, $x, $y) >> 24 & 0x7F) > 0) return true;
        }
    }
    return false;
}
