<?php
// שמירת ‎.mbe בשרת, עם גרסאות. הקובץ נבנה בדפדפן (mbe-io.js) ונבדק כאן שוב בעותק הליבה (lib/mbe) —
// השרת לא סומך על הלקוח: קובץ שלא עובר את הליבה, או שאין בו קנבס, לא נשמר.
//
// data/files/<owner>/<doc>/<n>.mbe — כל גרסה בקובץ משלה (ההיסטוריה), ו-preview.png של האחרונה — לרשימה.
// data/files/ מוחרג ב-deploy.yml: הפריסה לא דורסת ולא מוחקת.

require_once __DIR__ . '/docs.php';
require_once __DIR__ . '/mbe/mbe.php';

/** כמה גרסאות נשמרות לכל מסמך. הישנות נמחקות (הקובץ והשורה). */
const RS_KEEP_VERSIONS = 30;

/** קובץ שהליבה דחתה — עם הבעיות שלה, כדי שהלקוח יראה מה בדיוק */
final class RsMbeRejected extends RuntimeException
{
    public function __construct(public array $issues) { parent::__construct('mbe_invalid'); }
}

function rs_files_dir(): string
{
    return getenv('RASTER_STUDIO_FILES') ?: __DIR__ . '/../data/files';
}

/**
 * תקרת הקובץ: 200MB, ולא יותר מרבע מהזיכרון של PHP (הליבה פותחת את כל הקובץ בזיכרון), וגם לא מעל post_max_size —
 * גוף גדול ממנו מגיע ריק, והיה נדחה כ"פגום" במקום כ"גדול מדי".
 */
function rs_mbe_max(): int
{
    $max = 200 * 1024 * 1024;
    $mem = rs_ini_bytes((string)ini_get('memory_limit'));
    if ($mem > 0) $max = min($max, intdiv($mem, 4));
    $post = rs_ini_bytes((string)ini_get('post_max_size'));
    if ($post > 0) $max = min($max, $post);
    return $max;
}

function rs_ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') return -1;
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}

function rs_doc_dir(int $owner, int $id): string
{
    return rs_files_dir() . "/$owner/$id";
}

/**
 * שומר גרסה חדשה. base — הגרסה שהעורך התחיל ממנה: אם בינתיים נשמרה אחרת (מכשיר אחר) — 'conflict', אלא אם force.
 * @throws InvalidArgumentException not_found | too_big | not_raster | conflict
 * @throws RsMbeRejected כשהליבה מוצאת שגיאה
 */
function rs_doc_save(int $owner, int $id, string $bytes, ?int $base, bool $force = false): array
{
    $doc = rs_doc_get($owner, $id);
    if (!$doc) throw new InvalidArgumentException('not_found');
    if (strlen($bytes) > rs_mbe_max()) throw new InvalidArgumentException('too_big');

    $f = Mbe::open($bytes);
    if (!$f->ok()) throw new RsMbeRejected(array_values(array_filter($f->issues, fn($i) => $i['level'] === 'error')));
    $px = rs_mbe_pixel_size($f->document);
    if (!$px) throw new InvalidArgumentException('not_raster');
    [$w, $h] = array_map(fn($v) => max(RS_SIDE_MIN, min(RS_SIDE_MAX, (int)round($v))), $px);

    $db = rs_db();
    $db->exec('BEGIN IMMEDIATE');   // שתי שמירות בו-זמנית לא מקבלות אותו מספר גרסה
    try {
        $q = $db->prepare('SELECT COALESCE(MAX(n), 0) FROM doc_versions WHERE doc_id = ?');
        $q->execute([$id]);
        $last = (int)$q->fetchColumn();
        $q->closeCursor();
        if ($base !== null && $base !== $last && !$force) throw new InvalidArgumentException('conflict');
        $n = $last + 1;

        $dir = rs_doc_dir($owner, $id);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('mkdir');
        // כתיבה לקובץ זמני והחלפה — קובץ חצי-כתוב לא נשאר בשם האמיתי
        $path = "$dir/$n.mbe";
        $tmp = "$path.tmp";
        if (file_put_contents($tmp, $bytes) !== strlen($bytes) || !rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('write'); }
        $preview = $f->files[Mbe::PREVIEW] ?? null;
        if ($preview !== null) {
            file_put_contents("$dir/preview.png.tmp", $preview);
            rename("$dir/preview.png.tmp", "$dir/preview.png");
        }

        $now = rs_now();
        $db->prepare('INSERT INTO doc_versions (doc_id, n, bytes, sha256, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $n, strlen($bytes), hash('sha256', $bytes), $now]);
        $db->prepare('UPDATE docs SET width = ?, height = ?, updated_at = ?, version = ? WHERE id = ?')
            ->execute([$w, $h, $now, $n, $id]);

        // הישנות מעבר לתקרה — יוצאות
        $old = $db->prepare('SELECT n FROM doc_versions WHERE doc_id = ? ORDER BY n DESC LIMIT -1 OFFSET ' . RS_KEEP_VERSIONS);
        $old->execute([$id]);
        $drop = array_map('intval', $old->fetchAll(PDO::FETCH_COLUMN));
        $old->closeCursor();
        foreach ($drop as $k) {
            @unlink("$dir/$k.mbe");
            $db->prepare('DELETE FROM doc_versions WHERE doc_id = ? AND n = ?')->execute([$id, $k]);
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    return ['version' => $n, 'doc' => rs_doc_get($owner, $id)];
}

/**
 * גודל התמונה בפיקסלים — כמו editSurface בדפדפן (engine/formats.js): קנבס — ה-size שלו; אין קנבס — העמוד הראשון
 * שיש בו שכבת רסטר (למשל של סטודיו העיצוב, ב-pt), לפי ה-px של השכבה הכי חדה בו, ובלי px — 96dpi (1px = 0.75pt). אין — null.
 */
function rs_mbe_pixel_size(mixed $document): ?array
{
    $surfaces = is_array($document['surfaces'] ?? null) ? $document['surfaces'] : [];
    $ok = fn($s) => is_array($s) && is_array($s['size'] ?? null) && count($s['size']) === 2
        && is_numeric($s['size'][0]) && is_numeric($s['size'][1]) && $s['size'][0] > 0 && $s['size'][1] > 0;
    foreach ($surfaces as $s) if ($ok($s) && ($s['type'] ?? '') === 'canvas') return [(float)$s['size'][0], (float)$s['size'][1]];
    foreach ($surfaces as $s) {
        if (!$ok($s)) continue;
        $scale = 0.0;
        $rasters = 0;
        $walk = function ($list) use (&$walk, &$scale, &$rasters, $s) {
            foreach (is_array($list) ? $list : [] as $l) {
                if (!is_array($l)) continue;
                if (isset($l['children'])) $walk($l['children']);
                if (($l['type'] ?? '') === 'raster') $rasters++;
                if (($l['type'] ?? '') === 'raster' && is_array($l['px'] ?? null) && is_numeric($l['px'][0] ?? null) && $l['px'][0] > 0) {
                    $w = is_array($l['box'] ?? null) && is_numeric($l['box'][2] ?? null) ? (float)$l['box'][2] : (float)$s['size'][0];
                    if ($w > 0) $scale = max($scale, $l['px'][0] / $w);
                }
            }
        };
        $walk($s['layers'] ?? []);
        if (!$rasters) continue;   // מסמך טקסט — אין כאן מה לערוך
        if ($scale <= 0) $scale = (($document['units'] ?? 'pt') === 'pt') ? 1 / 0.75 : 1.0;
        return [$s['size'][0] * $scale, $s['size'][1] * $scale];
    }
    return null;
}

/** הגרסאות של מסמך, מהחדשה לישנה */
function rs_doc_versions(int $owner, int $id): array
{
    if (!rs_doc_get($owner, $id)) throw new InvalidArgumentException('not_found');
    $q = rs_db()->prepare('SELECT n, bytes, created_at FROM doc_versions WHERE doc_id = ? ORDER BY n DESC');
    $q->execute([$id]);
    return array_map(fn($r) => ['n' => (int)$r['n'], 'bytes' => (int)$r['bytes'], 'created_at' => $r['created_at']], $q->fetchAll());
}

/** הנתיב לקובץ של גרסה (ברירת מחדל — האחרונה), או null */
function rs_doc_file(int $owner, int $id, ?int $n = null): ?string
{
    $doc = rs_doc_get($owner, $id);
    if (!$doc || !$doc['version']) return null;
    $n ??= $doc['version'];
    $q = rs_db()->prepare('SELECT 1 FROM doc_versions WHERE doc_id = ? AND n = ?');
    $q->execute([$id, $n]);
    if (!$q->fetchColumn()) return null;
    $path = rs_doc_dir($owner, $id) . "/$n.mbe";
    return is_file($path) ? $path : null;
}

function rs_doc_preview(int $owner, int $id): ?string
{
    if (!rs_doc_get($owner, $id)) return null;
    $path = rs_doc_dir($owner, $id) . '/preview.png';
    return is_file($path) ? $path : null;
}
