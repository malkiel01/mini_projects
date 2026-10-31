<?php
/**
 * ספריית הגופנים (docs/spec-2-fonts.md).
 *
 * פריט = קובץ אחד = משפחה + משקל + סגנון. rev = sha256 של הקובץ; גופן שהוחלף — אותו id, rev חדש.
 * התוכן — באותו מאגר כמו הכונן (data/files/ab/cd/<sha256>), ונספר באותה מכסה.
 * הפרטים: מ-ttf / otf / woff — מטבלאות name ו-OS/2; מ-woff2 (דחוס ב-brotli, שאין ב-PHP שלנו) — מהדפדפן, או משם הקובץ.
 * גופן שהמשתמש מעלה מותר גם אם מסחרי, באחריותו (הכרעה 7): אזהרה אחת בהעלאה, ונשמר license_ack.
 */

declare(strict_types=1);

require_once __DIR__ . '/drive.php';

const FONT_MAX = 20 * 1024 * 1024;
const FONT_FORMATS = ['ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2'];

function migrateFonts(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lib_items (
            id          TEXT PRIMARY KEY,
            account     TEXT NOT NULL REFERENCES accounts(id),
            kind        TEXT NOT NULL,
            family      TEXT NOT NULL,
            weight      INTEGER NOT NULL DEFAULT 400,
            style       TEXT NOT NULL DEFAULT 'normal',
            name        TEXT NOT NULL,
            format      TEXT NOT NULL,
            size        INTEGER NOT NULL,
            sha256      TEXT NOT NULL,
            source      TEXT NOT NULL DEFAULT 'user',
            license_ack INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT NOT NULL,
            updated_at  TEXT NOT NULL,
            updated_by  TEXT NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS lib_items_acc ON lib_items(account, kind);
        CREATE INDEX IF NOT EXISTS lib_items_sha ON lib_items(sha256);
    ");
}

// ─── קריאת הפרטים מהקובץ ──────────────────────────────────────────────────

/** הפורמט לפי החתימה בתחילת הקובץ. */
function fontFormat(string $b): ?string {
    $sig = substr($b, 0, 4);
    return match (true) {
        $sig === "\x00\x01\x00\x00", $sig === 'true' => 'ttf',
        $sig === 'OTTO' => 'otf',
        $sig === 'wOFF' => 'woff',
        $sig === 'wOF2' => 'woff2',
        default => null,
    };
}

/** הטבלאות של sfnt (ttf / otf) או woff (פרוס): [tag => bytes]. */
function fontTables(string $b, string $format): array {
    $u16 = fn(int $o) => unpack('n', substr($b, $o, 2))[1] ?? 0;
    $u32 = fn(int $o) => unpack('N', substr($b, $o, 4))[1] ?? 0;
    $out = [];
    if ($format === 'woff') {
        $n = $u16(12);
        for ($i = 0; $i < min($n, 200); $i++) {
            $e = 44 + $i * 20;
            $tag = substr($b, $e, 4);
            if (!in_array($tag, ['name', 'OS/2'], true)) continue;
            [$off, $comp, $orig] = [$u32($e + 4), $u32($e + 8), $u32($e + 12)];
            $raw = substr($b, $off, $comp);
            $out[$tag] = $comp < $orig ? (string) @gzuncompress($raw) : $raw;
        }
        return $out;
    }
    $n = $u16(4);
    for ($i = 0; $i < min($n, 200); $i++) {
        $e = 12 + $i * 16;
        $tag = substr($b, $e, 4);
        if (in_array($tag, ['name', 'OS/2'], true)) $out[$tag] = substr($b, $u32($e + 8), $u32($e + 12));
    }
    return $out;
}

/** משפחה, משקל וסגנון מתוך הקובץ; null למה שלא נמצא. */
function fontMeta(string $b): array {
    $format = fontFormat($b);
    $meta = ['format' => $format, 'family' => null, 'sub' => null, 'weight' => null, 'style' => null];
    if ($format === null || $format === 'woff2') return $meta;
    $t = fontTables($b, $format);

    if (isset($t['name']) && strlen($t['name']) >= 6) {
        $n = $t['name'];
        $u16 = fn(int $o) => unpack('n', substr($n, $o, 2))[1] ?? 0;
        [$count, $strOff] = [$u16(2), $u16(4)];
        $names = [];
        for ($i = 0; $i < min($count, 500); $i++) {
            $r = 6 + $i * 12;
            [$pid, $eid, $lang, $nid, $len, $off] = [$u16($r), $u16($r + 2), $u16($r + 4), $u16($r + 6), $u16($r + 8), $u16($r + 10)];
            if (!in_array($nid, [1, 2, 16, 17], true)) continue;
            $raw = substr($n, $strOff + $off, $len);
            $s = match (true) {
                $pid === 3 || $pid === 0 => mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE'),
                $pid === 1 && $eid === 0 => (@iconv('MACINTOSH', 'UTF-8//IGNORE', $raw) ?: mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1')),
                default => null,
            };
            if ($s === null || trim($s) === '') continue;
            // הדירוג: Windows באנגלית (0x409) → Windows בשפה אחרת → Mac. בכל דרגה — הראשון שנמצא.
            $rank = $pid === 3 ? ($lang === 0x409 ? 3 : 2) : 1;
            if (!isset($names[$nid]) || $rank > $names[$nid][0]) $names[$nid] = [$rank, trim($s)];
        }
        $meta['family'] = ($names[16] ?? $names[1] ?? [0, null])[1];
        $meta['sub'] = ($names[17] ?? $names[2] ?? [0, null])[1];
    }
    if (isset($t['OS/2']) && strlen($t['OS/2']) >= 64) {
        $w = unpack('n', substr($t['OS/2'], 4, 2))[1];
        $sel = unpack('n', substr($t['OS/2'], 62, 2))[1];
        if ($w >= 1 && $w <= 1000) $meta['weight'] = (int) (round($w / 100) * 100) ?: 100;
        $meta['style'] = ($sel & 1) ? 'italic' : 'normal';
    }
    if ($meta['style'] === null && $meta['sub'] !== null) $meta['style'] = preg_match('/italic|oblique/i', $meta['sub']) ? 'italic' : 'normal';
    return $meta;
}

/**
 * משם קובץ: "Rubik-BoldItalic.woff2" → [Rubik, 700, italic]; "heebo-hebrew-100-normal.woff2" → [Heebo, 100, normal].
 * קוראים מהסוף: משקל (מספר או מילה), סגנון, ותת-קבוצה (hebrew, latin…) — ומה שנשאר הוא המשפחה.
 */
function fontFromFilename(string $filename): array {
    $base = preg_replace('/\.[a-z0-9]+$/i', '', basename($filename));
    $words = ['thin' => 100, 'hairline' => 100, 'extralight' => 200, 'ultralight' => 200, 'light' => 300, 'regular' => 400,
              'book' => 400, 'normal' => 400, 'medium' => 500, 'semibold' => 600, 'demibold' => 600, 'bold' => 700,
              'extrabold' => 800, 'ultrabold' => 800, 'black' => 900, 'heavy' => 900];
    $subsets = ['hebrew', 'latin', 'ext', 'cyrillic', 'greek', 'arabic', 'vietnamese', 'all', 'variable', 'vf'];
    $parts = preg_split('/[-_ ]+/', $base, -1, PREG_SPLIT_NO_EMPTY);
    [$weight, $style] = [400, 'normal'];
    while (count($parts) > 1) {
        $t = strtolower(end($parts));
        if (preg_match('/^[1-9]00$/', $t)) { $weight = (int) $t; array_pop($parts); continue; }
        if (preg_match('/(italic|oblique)$/', $t)) { $style = 'italic'; $t = preg_replace('/(italic|oblique)$/', '', $t); }
        if ($t === '') { array_pop($parts); continue; }
        if (isset($words[$t])) { if ($t !== 'normal') $weight = $words[$t]; array_pop($parts); continue; }
        if (in_array($t, $subsets, true)) { array_pop($parts); continue; }
        break;
    }
    $family = implode(' ', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1),
        preg_split('/(?<=[a-z])(?=[A-Z])| /', implode(' ', $parts))));
    return [trim($family) !== '' ? trim($family) : 'גופן', $weight, $style];
}

// ─── הספרייה ──────────────────────────────────────────────────────────────

function fontItem(array $r): array {
    return [
        'id' => $r['id'], 'kind' => 'font', 'family' => $r['family'], 'weight' => (int) $r['weight'], 'style' => $r['style'],
        'name' => $r['name'], 'format' => $r['format'], 'size' => (int) $r['size'], 'rev' => $r['sha256'], 'source' => $r['source'],
        'license_ack' => (int) $r['license_ack'] === 1, 'updated_at' => $r['updated_at'],
        'url' => 'api.php?action=font_file&id=' . rawurlencode($r['id']) . '&rev=' . $r['sha256'],
    ];
}

function fontsList(array $a): array {
    $st = db()->prepare("SELECT * FROM lib_items WHERE account = ? AND kind = 'font' ORDER BY family COLLATE NOCASE, weight, style");
    $st->execute([$a['id']]);
    $fonts = array_map('fontItem', $st->fetchAll());
    $families = [];
    foreach ($fonts as $f) $families[$f['family']][] = $f['id'];
    return ['fonts' => $fonts, 'families' => array_map(fn($k, $v) => ['family' => $k, 'items' => $v], array_keys($families), $families)];
}

function fontRow(array $a, mixed $id): array {
    $st = db()->prepare("SELECT * FROM lib_items WHERE id = ? AND account = ? AND kind = 'font'");
    $st->execute([is_string($id) ? $id : '', $a['id']]);
    $r = $st->fetch();
    if (!$r) throw new AppError('הגופן לא נמצא', 404, 'not_found');
    return $r;
}

function cleanFamily(mixed $f): string {
    $f = trim((string) $f);
    if ($f === '' || mb_strlen($f) > 100 || preg_match('/[\x00-\x1F<>"\\\\]/u', $f)) throw new AppError('שם משפחה לא תקין', 400, 'bad_request');
    return $f;
}

function cleanWeight(mixed $w): int {
    $w = (int) $w;
    if ($w < 100 || $w > 900 || $w % 100 !== 0) throw new AppError('משקל — 100 עד 900', 400, 'bad_request');
    return $w;
}

function cleanStyle(mixed $s): string {
    if (!in_array($s, ['normal', 'italic'], true)) throw new AppError('סגנון — normal או italic', 400, 'bad_request');
    return $s;
}

/**
 * font_add: $bytes + filename (+ family/weight/style מהדפדפן ל-woff2, או כתיקון). אותה משפחה, משקל וסגנון — החלפה.
 */
function fontAdd(array $a, string $bytes, array $in, string $app = ''): array {
    if (empty($in['license_ack'])) throw new AppError('צריך לאשר שהרישיון מתיר הטמעה', 400, 'license');
    if (strlen($bytes) > FONT_MAX) throw new AppError('גופן גדול מ-20MB', 413, 'too_large');
    $meta = fontMeta($bytes);
    if ($meta['format'] === null) throw new AppError('הקובץ אינו גופן (ttf, otf, woff, woff2)', 400, 'bad_format');
    [$fFamily, $fWeight, $fStyle] = fontFromFilename((string) ($in['filename'] ?? 'font'));
    $family = cleanFamily(($in['family'] ?? '') !== '' ? $in['family'] : ($meta['family'] ?? $fFamily));
    $weight = cleanWeight(($in['weight'] ?? '') !== '' ? $in['weight'] : ($meta['weight'] ?? $fWeight));
    $style = cleanStyle(($in['style'] ?? '') !== '' ? $in['style'] : ($meta['style'] ?? $fStyle));
    $name = mb_substr(trim($family . ' ' . ($meta['sub'] && !in_array(strtolower($meta['sub']), ['regular', 'normal'], true) ? $meta['sub'] : '')), 0, 120);

    $sha = hash('sha256', $bytes);
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM lib_items WHERE account = ? AND kind = 'font' AND lower(family) = lower(?) AND weight = ? AND style = ?");
    $st->execute([$a['id'], $family, $weight, $style]);
    $old = $st->fetch() ?: null;
    assertRoom($a, strlen($bytes), $old ? (int) $old['size'] : 0);

    $tmp = stagingFile();
    file_put_contents($tmp, $bytes);
    storeBlob($tmp, $sha);
    $now = nowIso();
    if ($old) {
        $pdo->prepare('UPDATE lib_items SET name = ?, format = ?, size = ?, sha256 = ?, license_ack = 1, updated_at = ?, updated_by = ? WHERE id = ?')
            ->execute([$name, $meta['format'], strlen($bytes), $sha, $now, author($a, $app), $old['id']]);
        if ($old['sha256'] !== $sha) dropBlobIfUnused($old['sha256']);
        return fontItem(fontRow($a, $old['id'])) + ['replaced' => true];
    }
    $id = randomId('fnt_');
    $pdo->prepare("INSERT INTO lib_items (id, account, kind, family, weight, style, name, format, size, sha256, license_ack, created_at, updated_at, updated_by)
                   VALUES (?,?,'font',?,?,?,?,?,?,?,1,?,?,?)")
        ->execute([$id, $a['id'], $family, $weight, $style, $name, $meta['format'], strlen($bytes), $sha, $now, $now, author($a, $app)]);
    return fontItem(fontRow($a, $id)) + ['replaced' => false];
}

function fontUpdate(array $a, array $in): array {
    $r = fontRow($a, $in['id'] ?? null);
    $family = array_key_exists('family', $in) ? cleanFamily($in['family']) : $r['family'];
    $weight = array_key_exists('weight', $in) ? cleanWeight($in['weight']) : (int) $r['weight'];
    $style = array_key_exists('style', $in) ? cleanStyle($in['style']) : $r['style'];
    $st = db()->prepare("SELECT 1 FROM lib_items WHERE account = ? AND kind = 'font' AND lower(family) = lower(?) AND weight = ? AND style = ? AND id <> ?");
    $st->execute([$a['id'], $family, $weight, $style, $r['id']]);
    if ($st->fetchColumn()) throw new AppError('כבר יש גופן עם אותה משפחה, משקל וסגנון', 409, 'name_taken');
    db()->prepare('UPDATE lib_items SET family = ?, weight = ?, style = ?, updated_at = ? WHERE id = ?')->execute([$family, $weight, $style, nowIso(), $r['id']]);
    return fontItem(fontRow($a, $r['id']));
}

function fontDelete(array $a, mixed $id): void {
    $r = fontRow($a, $id);
    db()->prepare('DELETE FROM lib_items WHERE id = ?')->execute([$r['id']]);
    dropBlobIfUnused($r['sha256']);
}

/** [נתיב, פריט] להגשה. */
function fontFile(array $a, mixed $id): array {
    $r = fontRow($a, $id);
    $path = blobPath($r['sha256']);
    if (!is_file($path)) throw new AppError('קובץ הגופן חסר בשרת', 500, 'server');
    return [$path, $r];
}
