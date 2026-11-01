<?php
// ‎.mbe בשרת: פתיחה מהמכשיר אל הספרייה, ושמירת הקובץ המקורי של המסמך.
//
// הספרייה שומרת את המודל (document.json) במסד ואת התמונות לפי הגיבוב — כמו ב-.mbe. מה שלא שלנו בקובץ
// (apps/ של אחרים, history/, חלקים לא מוכרים, משאבים שאינם תמונות) נשמר כמו שהוא ב"קובץ המקור":
// data/files/<משתמש>/mbe/<מסמך>.mbe. בהורדה הדפדפן פותח אותו בליבה, מחליף רק את מה שלנו ושומר — כך
// manifest.id נשאר, ומה שאפליקציות אחרות כתבו לא הולך לאיבוד. (lib/mbe — עותק מסונכרן של הליבה, לא עורכים כאן.)

require_once __DIR__ . '/model.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/mbe/mbe.php';

const LS_MBE_MAX = 64 * 1024 * 1024;

function ls_mbe_source_path(int $owner, int $docId): string
{
    return ls_media_dir($owner) . '/mbe/' . $docId . '.mbe';
}

/**
 * פתיחת ‎.mbe בליבה ובדיקה שהוא עימוד. מחזיר את הקובץ הפתוח.
 * @throws InvalidArgumentException too_big / mbe_invalid:<קודי הבעיות> / mbe_kind:<kind>
 */
function ls_mbe_open_layout(string $bytes): MbeFile
{
    if (strlen($bytes) > LS_MBE_MAX) throw new InvalidArgumentException('too_big');
    $f = Mbe::open($bytes);
    if (!$f->ok()) {
        $codes = array_unique(array_map(fn($i) => $i['code'], array_filter($f->issues, fn($i) => $i['level'] === 'error')));
        throw new InvalidArgumentException('mbe_invalid:' . implode(',', array_slice($codes, 0, 5)));
    }
    if ($f->kind() !== 'layout') throw new InvalidArgumentException('mbe_kind:' . preg_replace('/[^a-z0-9-]/', '', (string)$f->kind()));
    return $f;
}

/** שמירת קובץ המקור של מסמך (אחרי הורדה — כדי שההורדה הבאה תמשיך ממנו). */
function ls_mbe_source_put(int $owner, int $docId, string $bytes): void
{
    if (!ls_doc_find($owner, $docId)) throw new InvalidArgumentException('not_found');
    ls_mbe_open_layout($bytes);
    $p = ls_mbe_source_path($owner, $docId);
    if (!is_dir(dirname($p)) && !mkdir(dirname($p), 0775, true) && !is_dir(dirname($p))) throw new RuntimeException('mkdir');
    $tmp = $p . '.' . bin2hex(random_bytes(4)) . '.tmp';
    file_put_contents($tmp, $bytes);
    rename($tmp, $p);
}

/** קובץ המקור של מסמך, או null (מסמך שנוצר כאן ועוד לא ירד). */
function ls_mbe_source_get(int $owner, int $docId): ?string
{
    if (!ls_doc_find($owner, $docId)) return null;
    $p = ls_mbe_source_path($owner, $docId);
    return is_file($p) ? file_get_contents($p) : null;
}

/**
 * ‎.mbe מהמכשיר → מסמך חדש בספרייה. התמונות עוברות לאחסון של המשתמש (אותו שם — הגיבוב), המודל נבדק כמו
 * בשמירה, והקובץ עצמו נשמר כקובץ המקור. $name — שם הקובץ (לכותרת).
 * @throws InvalidArgumentException (ראו ls_mbe_open_layout, bad_model:…, title_too_long)
 */
function ls_mbe_import(int $owner, string $bytes, string $name): array
{
    $f = ls_mbe_open_layout($bytes);
    foreach ($f->media() as $m) {
        if (!preg_match('/\.(png|jpg|webp|ttf)$/', $m['path'])) continue;   // לא תמונה ולא TrueType — נשאר בקובץ המקור
        $put = str_ends_with($m['path'], '.ttf') ? ls_font_put($owner, $m['data']) : ls_media_put($owner, $m['data']);
        if ($put['src'] !== $m['path']) throw new InvalidArgumentException('mbe_invalid:media');
    }
    $json = json_encode($f->document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || strlen($json) > LS_MODEL_MAX_BYTES) throw new InvalidArgumentException('too_big');
    [$w, $h] = ls_model_check(json_decode($json, true), fn(string $src) => ls_media_path($owner, substr($src, 6)) !== null);

    $title = trim(preg_replace('/\s+/u', ' ', preg_replace('/\.mbe$/i', '', $name)));
    if ($title === '') $title = 'מסמך';
    $title = mb_substr($title, 0, LS_TITLE_MAX);
    $now = ls_now();
    $db = ls_db();
    $db->prepare('INSERT INTO docs (owner_id, title, page_w, page_h, model, rev, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)')
        ->execute([$owner, $title, $w, $h, $json, $now, $now]);
    $id = (int)$db->lastInsertId();
    ls_mbe_source_put($owner, $id, $bytes);
    $q = $db->prepare('SELECT * FROM docs WHERE id = ?');
    $q->execute([$id]);
    return ls_doc_row($q->fetch());
}

/**
 * "פנים" שחזרו (PDF מוחתם — mbe-format.md סעיף 7): המסמך בספרייה שקובץ המקור שלו נושא את ה-manifest.id הזה, או null.
 * עוברים על קובצי המקור של המשתמש — ספרייה אישית, עשרות מסמכים; כשיגדל — עמודה במסד.
 */
function ls_doc_by_mbe_id(int $owner, string $mbeId): ?array
{
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $mbeId)) return null;
    foreach (ls_docs_list($owner) as $d) {
        $src = ls_mbe_source_get($owner, (int)$d['id']);
        if ($src === null) continue;
        try { $f = Mbe::open($src); } catch (Throwable $e) { continue; }
        if ($f->id() === $mbeId) return $d;
    }
    return null;
}

