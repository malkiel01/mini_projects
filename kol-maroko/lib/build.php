<?php
/**
 * בנייה ופרסום של פרויקט הקבצים (פרוסה M1; docs/spec-v2/07-publish-and-mount.md §1–4, slices/M1-build-and-publish.md).
 *
 * הבנייה עצמה — **בשרת, מהמאגר**: כל קובץ נכנס לחבילה לפי ה-sha256 שלו במאגר, כמו שהוא (ע1). שני חריגים בלבד:
 *   (א) data/words.json — נבנה כאן מטבלת המילים, באותה פונקציה שמגישה אותו לתצוגה החיה (pv_words), וההקלטות שאצלנו
 *       מקבלות כתובת בתוך החבילה (media/audio/<id>.<ext>);
 *   (ב) עמודים עם רשימה (data-kol-list) — "כתיבה מראש": הדפדפן של הבונה מריץ את js/kol-data.js **של הפרויקט** במסגרת
 *       המבודדת (preview/, מצב בנייה), ושולח רק את מה שנוצר בתוך כל מכל. **השרת** מרכיב את העמוד: הבייטים של המקור
 *       כמו שהם, ובתוך המכל — אחרי התוכן המקורי — הקטע שנוצר. מחוץ למכל אין לדפדפן שום דרך לשנות בייט.
 *
 * מי רשאי: בנייה — עורך ומעלה (רואה, אינו שולח). שליחה — מפרסם, **ורק גרסה שהוא עצמו בנה**: כך עורך שאינו מורשה לפרסם
 * אינו יכול להגיע לאתר דרך גרסה שהכין, ומה שנשלח הוא מה שהשולח ראה ב"מה ישתנה".
 *
 * השליחה — מהשרת בלבד (אין סוד בדפדפן): asset_has ← asset_put (בחלקים) ← manifest חתום. עד שהמניפסט מתקבל, דבר באתר לא
 * השתנה. תוסף 1.x אינו יודע לקבל — הגרסה נשמרת "מוכנה", והכפתור מושבת (M2).
 *
 * איפה הנתונים: pkg_builds / pkg_site_blobs / notices ב-data/kol.sqlite (מיגרציה 16); תוכן העמודים שנכתבו מראש ושל
 * data/words.json — לפי sha256 ב-data/files/_project/ (pf_blob_put). שני המקומות כבר מוחרגים בפריסה.
 */

declare(strict_types=1);

require_once __DIR__ . '/preview.php';
require_once __DIR__ . '/publish.php';
require_once __DIR__ . '/restore.php';
require_once __DIR__ . '/forms.php';

const BUILD_TTL = 3600;                      // תוקף כתובת המסגרת של בנייה / תצוגה של גרסה בנויה
const BUILD_STALE = 900;                     // בנייה שלא הסתיימה תוך רבע שעה — נכשלה
const BUILD_BIG_FILE = 10 * 1024 * 1024;     // אזהרה: קובץ מעל 10MB
const BUILD_PAGE_MAX = 16 * 1024 * 1024;     // עמוד שנכתב מראש — עד 16MB
const BUILD_PROTOCOL = 2;                    // גרסת הפרוטוקול שהתוסף צריך לדווח ב-ping (M2)
const BUILD_HAS_BATCH = 500;
const BUILD_SEED_KD = __DIR__ . '/../assets/project-seed/kol-data.js';
const BUILD_KD = 'js/kol-data.js';
const BUILD_VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr', 'param', 'keygen', 'command'];
const BUILD_RAW = ['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext'];

// ── עזרים ─────────────────────────────────────────────────

function build_type(string $path): string
{
    $t = PV_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    return trim(explode(';', $t)[0]);
}

function build_is_text(string $type): bool
{
    return str_starts_with($type, 'text/') || in_array($type, ['application/json', 'application/xml', 'image/svg+xml', 'application/manifest+json'], true);
}

/** נתיב במניפסט (07 §4): יחסי, בלי .., בלי \, בלי תווי בקרה, עד 255 תווים. */
function build_path_ok(string $p): bool
{
    if ($p === '' || $p[0] === '/' || str_contains($p, '\\') || mb_strlen($p) > 255 || !mb_check_encoding($p, 'UTF-8') || preg_match('/\p{Cc}/u', $p)) return false;
    foreach (explode('/', $p) as $s) if ($s === '' || $s === '.' || $s === '..') return false;
    return true;
}

function build_line(string $text, int $offset): int
{
    return substr_count(substr($text, 0, $offset), "\n") + 1;
}

/** טביעה של מצב הפרויקט: כל קובץ חי (נתיב, סוג, תוכן) והמילים. שינוי בכל אחד — הגרסה הבנויה "ישנה". */
function build_print(): string
{
    $rows = db()->query('SELECT path, kind, sha, file_id FROM pf_files WHERE deleted_at IS NULL ORDER BY path')->fetchAll();
    $h = hash_init('sha256');
    foreach ($rows as $r) hash_update($h, $r['path'] . "\0" . $r['kind'] . "\0" . $r['sha'] . "\0" . $r['file_id'] . "\n");
    hash_update($h, words_snapshot());
    return hash_final($h);
}

function build_rev(): int
{
    return (int)db()->query('SELECT COALESCE(MAX(id), 0) FROM pf_history')->fetchColumn();
}

function build_row(int $id): array
{
    $st = db()->prepare('SELECT * FROM pkg_builds WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: throw new ApiError('הגרסה הבנויה לא נמצאה', 404);
}

function build_save(int $id, array $set): void
{
    $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($set)));
    db()->prepare("UPDATE pkg_builds SET $cols WHERE id = ?")->execute([...array_values($set), $id]);
}

function build_json(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function build_quota(): int
{
    return (int)(setting('pkg_quota') ?? PUB_MAX_TOTAL);
}

/** בנייה שנתקעה (הדפדפן נסגר באמצע) — נכשלה. */
function build_expire(): void
{
    $old = gmdate('Y-m-d\TH:i:s\Z', time() - BUILD_STALE);
    db()->prepare("UPDATE pkg_builds SET status = 'failed', error = ? WHERE status = 'building' AND at < ?")
        ->execute([build_json(['msg' => 'הבנייה לא הסתיימה (הדף נסגר או נתקע)']), $old]);
}

/** הבונה בלבד ממשיך בנייה שהתחיל. */
function build_mine(array $user, array $b, string $status = 'building'): array
{
    $r = build_row((int)($b['id'] ?? 0));
    if ($r['by_sub'] !== $user['sub']) throw new ApiError('את הבנייה הזו התחיל מישהו אחר', 403);
    if ($r['status'] !== $status) throw new ApiError('הבנייה כבר אינה פתוחה', 409);
    return $r;
}

// ── מה נכנס לחבילה ────────────────────────────────────────

/** האם הקובץ יוצא מהחבילה כברירת מחדל (07 §2): kol.json ו-components/<שם>/component.json. */
function build_is_config(string $path): bool
{
    return $path === 'kol.json' || (bool)preg_match('~^components/[^/]+/component\.json$~', $path);
}

/**
 * החבילה מהמאגר: [קבצים, יצאו, הערות]. קובץ טקסט — לפי ה-sha שלו במאגר; מדיה — מהספרייה. שום קובץ אינו יוצא בשקט:
 * כל מה שלא נכנס מופיע ב"יצאו", עם הסיבה.
 */
function build_collect(array $opts): array
{
    $files = $out = $notes = [];
    $exclude = array_flip($opts['exclude']);
    $rows = db()->query("SELECT * FROM pf_files WHERE deleted_at IS NULL AND kind != 'dir' ORDER BY path")->fetchAll();
    $media = files_meta(array_values(array_filter(array_column($rows, 'file_id'))));
    foreach ($rows as $r) {
        $p = $r['path'];
        if (isset($exclude[$p])) { $out[] = ['path' => $p, 'why' => 'הוצא בידי הבונה']; continue; }
        if (!$opts['include_config'] && build_is_config($p)) { $out[] = ['path' => $p, 'why' => 'קובץ הגדרות — אינו נחוץ לגולש']; continue; }
        if (!build_path_ok($p)) { $out[] = ['path' => $p, 'why' => 'נתיב שאינו חוקי באתר (עד 255 תווים)']; continue; }
        if ($r['kind'] === 'media') {
            $f = $media[$r['file_id']] ?? null;
            if (!$f || $f['deleted_at'] !== null || !is_file(file_path($f))) throw new ApiError("קובץ המדיה \"$p\" חסר בספרייה — בחרו אותו מחדש", 422, ['file' => $p]);
            $files[$p] = ['sha' => $f['sha256'] ?: hash_file('sha256', file_path($f)), 'size' => (int)$f['size'], 'type' => pv_media_type($f, $p), 'src' => 'media', 'file_id' => $f['id']];
            continue;
        }
        $files[$p] = ['sha' => $r['sha'], 'size' => (int)$r['size'], 'type' => build_type($p), 'src' => 'repo'];
    }
    if (build_needs_words($files)) {
        if (isset($files[PV_WORDS])) $notes[] = ['kind' => 'info', 'msg' => PV_WORDS . ' בפרויקט מוחלף בנתונים מטבלת המילים — כמו בתצוגה החיה'];
        [$files, $wn] = build_words($files);
        $notes = array_merge($notes, $wn);
    }
    [$files, $ln] = build_lists($files);                          // N3: כל רשימה רשומה — data/<key>.json, רק "מתפרסם"
    $notes = array_merge($notes, $ln);
    return [$files, $out, $notes];
}

/**
 * N3: כל רשימה רשומה ב-kol.json — הקובץ שלה מ-list_json($key, '') (רק "מתפרסם"), והקבצים שלה בחבילה: תמונה —
 * media/images/<id>.<ext>, הקלטה — media/audio/<list>-<id>-<key>.<ext>. נתיב שתפוס בקובץ רגיל — הקובץ הרגיל נשאר.
 */
function build_lists(array $files): array
{
    $notes = [];
    foreach (pf_list_paths() as $path => $key) {
        if ($key === 'words' || pf_by_path($path)) continue;
        $j = json_decode(list_json($key, ''), true);
        $fields = array_column(array_filter(list_fields($key), fn($f) => $f['published'] && in_array($f['type'], ['image', 'audio'], true)), null, 'key');
        $rows = array_column(list_rows($key), 'data', 'id');
        $meta = files_meta(list_file_ids(array_map(fn($id, $d) => ['id' => $id, 'data' => $d], array_keys($rows), $rows), array_values($fields)));
        $missing = 0;
        foreach ($j['items'] as &$it) foreach ($fields as $fk => $f) {
            $v = $rows[(int)$it['id']][$fk] ?? null;
            if (!file_id($v)) continue;
            $m = $meta[$v] ?? null;
            $p = $m ? ($f['type'] === 'image' ? "media/images/$v.{$m['ext']}" : "media/audio/$key-{$it['id']}-$fk.{$m['ext']}") : '';
            if ($p !== '' && $m['deleted_at'] === null && is_file(file_path($m))) {
                $files[$p] ??= ['sha' => $m['sha256'] ?: hash_file('sha256', file_path($m)), 'size' => (int)$m['size'], 'type' => build_type($p), 'src' => 'media', 'file_id' => $m['id']];
                $it[$fk] = $p;
            } else { unset($it[$fk]); $missing++; }
        }
        unset($it);
        $bytes = words_json_encode($j);
        $files[$path] = ['sha' => pf_blob_put($bytes), 'size' => strlen($bytes), 'type' => 'application/json', 'src' => 'data'];
        if ($missing) $notes[] = ['kind' => 'missing_audio', 'msg' => "$path: $missing קבצים (הקלטות / תמונות) חסרים בספרייה — יצאו בלעדיהם", 'count' => $missing];
    }
    return [$files, $notes];
}

/** כמו בתצוגה החיה: data/words.json קיים כשיש בפרויקט רשימה, או כש-kol.json מצהיר עליו. */
function build_needs_words(array $files): bool
{
    $data = pf_kol()['config']['data'] ?? [];
    if (is_array($data)) foreach ($data as $d) if (is_array($d) && ($d['file'] ?? '') === PV_WORDS) return true;
    foreach ($files as $p => $f) {
        if ($f['src'] === 'repo' && preg_match('/\.html?$/i', $p) && str_contains(pf_blob_get($f['sha']), 'data-kol-list')) return true;
    }
    return false;
}

/**
 * data/words.json מ-pv_words (אותה פונקציה של התצוגה החיה — קריאה בלבד), עם תיקון אחד: הקלטה שאצלנו מקבלת כתובת בתוך
 * החבילה, והקובץ נכנס לחבילה. הקלטה שעדיין "באתר" נשארת כתובת — ומופיעה באזהרות.
 */
function build_words(array $files): array
{
    $notes = [];
    $j = json_decode(pv_words(''), true);                     // N2: רק שדות "מתפרסם" (words_json עם $app)
    $fields = fields_all();
    $pub = array_values(array_filter($fields, fn($f) => $f['published']));
    $audioKey = null;
    foreach ($pub as $f) if ($f['col'] === 'audio') $audioKey = $f['key'];
    // N2: כל שדה קובץ מותאם שמתפרסם — כמו ההקלטה (06 §2.3: media/images/<id>.<ext>, media/audio/<id>-<key>.<ext>)
    $fileFields = array_values(array_filter($pub, fn($f) => $f['col'] === null && in_array($f['type'], ['image', 'audio'], true)));
    $byWord = []; $extra = [];
    foreach (words_rows() as $w) { $byWord[(int)$w['id']] = (string)$w['audio']; $extra[(int)$w['id']] = $w['extra']; }
    $topics = topics_all();
    $ids = array_merge(array_map('file_id', $byWord), array_column($topics, 'image'));
    foreach ($fileFields as $f) foreach ($extra as $x) $ids[] = file_id($x[$f['key']] ?? null);
    $meta = files_meta(array_values(array_unique(array_filter($ids))));
    $site = $missing = 0;
    $add = function (string $id, string $path) use (&$files, $meta): bool {
        $f = $meta[$id] ?? null;
        if (!$f || $f['deleted_at'] !== null || !is_file(file_path($f))) return false;
        if (!isset($files[$path])) $files[$path] = ['sha' => $f['sha256'] ?: hash_file('sha256', file_path($f)), 'size' => (int)$f['size'], 'type' => build_type($path), 'src' => 'media', 'file_id' => $f['id']];
        return true;
    };
    foreach ($j['items'] as &$it) {
        $wid = (int)$it['id'];
        foreach ($fileFields as $f) {
            $v = $extra[$wid][$f['key']] ?? null;
            if (!file_id($v)) continue;
            $m = $meta[$v] ?? null;
            $path = $m ? ($f['type'] === 'image' ? "media/images/$v.{$m['ext']}" : "media/audio/$v-{$f['key']}.{$m['ext']}") : '';
            if ($path !== '' && $add($v, $path)) $it[$f['key']] = $path; else { unset($it[$f['key']]); $missing++; }
        }
        if ($audioKey === null) continue;
        $a = $byWord[$wid] ?? '';
        if ($a === '') continue;
        if (!file_id($a)) { $site++; continue; }
        $f = $meta[$a] ?? null;
        $path = $f ? 'media/audio/' . $f['id'] . '.' . $f['ext'] : '';
        if ($path !== '' && $add($a, $path)) $it[$audioKey] = $path; else { $it[$audioKey] = ''; $missing++; }
    }
    unset($it);
    // תמונות הנושאים — גם הן בחבילה
    if (isset($j['topics'])) foreach ($j['topics'] as &$t) {
        $img = $topics[$t['id']]['image'] ?? '';
        if (!file_id($img)) continue;
        $m = $meta[$img] ?? null;
        $path = $m ? "media/images/$img.{$m['ext']}" : '';
        if ($path !== '' && $add($img, $path)) $t['image'] = $path; else { unset($t['image']); $missing++; }
    }
    unset($t);
    $bytes = json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $files[PV_WORDS] = ['sha' => pf_blob_put($bytes), 'size' => strlen($bytes), 'type' => 'application/json', 'src' => 'data'];
    if ($site) $notes[] = ['kind' => 'site_audio', 'msg' => "$site הקלטות עדיין באתר (כתובת חיצונית) — לא בחבילה. אחרי \"משיכה\" לספריית ההקלטות הן ייכנסו", 'count' => $site];
    if ($missing) $notes[] = ['kind' => 'missing_audio', 'msg' => "$missing קבצים (הקלטות / תמונות) חסרים בספרייה — יצאו בלעדיהם", 'count' => $missing];
    return [$files, $notes];
}

/** עמודים שצריכים כתיבה מראש: קובצי HTML שיש בהם מכל של רשימה. */
function build_pages(array $files): array
{
    $out = [];
    foreach ($files as $p => $f) {
        if ($f['src'] !== 'repo' || !preg_match('/\.html?$/i', $p)) continue;
        if (build_containers(pf_blob_get($f['sha']))) $out[] = $p;
    }
    return $out;
}

// ── HTML: מכלים ובדיקת קטע ────────────────────────────────

/**
 * התגיות שבמסמך, לפי הסדר: [type open|close, name, start, end, attrs]. תוכן של script/style/textarea וכו' — טקסט,
 * לא תגיות; הערות — מדולגות. $ok — false כשיש הערה או אלמנט טקסט שלא נסגרו.
 */
function build_tags(string $s, ?bool &$ok = null): array
{
    $ok = true;
    $out = [];
    $i = 0;
    $n = strlen($s);
    $re = '~<(?:(!--)|(/?)([A-Za-z][A-Za-z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>|![^>]*>|\?[^>]*>)~A';
    while (($at = strpos($s, '<', $i)) !== false) {
        if (!preg_match($re, $s, $m, PREG_OFFSET_CAPTURE, $at)) { $i = $at + 1; continue; }
        $end = $at + strlen($m[0][0]);
        if (($m[1][0] ?? '') === '!--') {
            $close = strpos($s, '-->', $at + 4);
            if ($close === false) { $ok = false; return $out; }
            $i = $close + 3;
            continue;
        }
        if (!isset($m[3]) || $m[3][1] < 0) { $i = $end; continue; }          // <!doctype>, <?…>
        $name = strtolower($m[3][0]);
        $type = $m[2][0] === '/' ? 'close' : 'open';
        $out[] = ['type' => $type, 'name' => $name, 'start' => $at, 'end' => $end, 'attrs' => $m[4][0]];
        $i = $end;
        if ($type === 'open' && in_array($name, BUILD_RAW, true)) {
            if (!preg_match('~</' . preg_quote($name, '~') . '[\s/>]~i', $s, $c, PREG_OFFSET_CAPTURE, $i)) { $ok = false; return $out; }
            $i = $c[0][1];
        }
    }
    return $out;
}

/** המכלים (data-kol-list) במסמך, לפי הסדר: [list, open_start, open_end, close_start, attrs]. */
function build_containers(string $html): array
{
    $tags = build_tags($html);
    $out = [];
    foreach ($tags as $k => $t) {
        if ($t['type'] !== 'open' || !preg_match('/(?:^|\s)data-kol-list\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $t['attrs'], $m)) continue;
        $list = html_entity_decode(($m[1] ?? '') . ($m[2] ?? '') . ($m[3] ?? ''), ENT_QUOTES | ENT_HTML5);
        $depth = 0;
        $close = null;
        for ($j = $k + 1, $c = count($tags); $j < $c; $j++) {
            if ($tags[$j]['name'] !== $t['name']) continue;
            if ($tags[$j]['type'] === 'open') { $depth++; continue; }
            if ($depth === 0) { $close = $tags[$j]['start']; break; }
            $depth--;
        }
        $out[] = ['list' => $list, 'open_start' => $t['start'], 'open_end' => $t['end'], 'close_start' => $close, 'attrs' => $t['attrs'], 'tag' => $t['name']];
    }
    return $out;
}

/** קטע שנכתב מראש חייב להיות סגור בתוך עצמו: כל תגית שנפתחה נסגרת, ואין תגית סוגרת עודפת — כך הוא לא יוצא מהמכל. */
function build_fragment_ok(string $html): bool
{
    $tags = build_tags($html, $ok);
    if (!$ok) return false;
    $stack = [];
    foreach ($tags as $t) {
        if (in_array($t['name'], BUILD_VOID, true)) { if ($t['type'] === 'close') return false; continue; }
        if ($t['type'] === 'open') { $stack[] = $t['name']; continue; }
        if (array_pop($stack) !== $t['name']) return false;
    }
    return !$stack;
}

/**
 * מרכיב עמוד שנכתב מראש: הבייטים של המקור, ובכל מכל — data-kol-prerendered על תגית הפתיחה, והקטע לפני תגית הסגירה.
 * $parts — לפי סדר המכלים: null (המכל נשאר כמו שהוא) או ה-HTML שנוצר. מחזיר [html, כמה מכלים נכתבו].
 */
function build_splice(string $src, array $parts): array
{
    $cs = build_containers($src);
    if (count($parts) !== count($cs)) throw new ApiError('מספר הרשימות שבעמוד שונה ממספר המכלים שבקובץ — רשימה שנוצרה בקוד אינה נכתבת מראש', 422, ['code' => 'containers']);
    $out = '';
    $at = 0;
    $done = 0;
    foreach ($cs as $k => $c) {
        $p = $parts[$k];
        if ($p === null) continue;
        if (!is_array($p) || ($p['list'] ?? null) !== $c['list'] || !is_string($p['html'] ?? null)) throw new ApiError('רשימה שלא תואמת את המכל בקובץ', 422, ['code' => 'containers']);
        if ($c['close_start'] === null) throw new ApiError("המכל של \"{$c['list']}\" אינו נסגר בקובץ", 422, ['code' => 'unclosed', 'line' => build_line($src, $c['open_start'])]);
        if (!build_fragment_ok($p['html'])) throw new ApiError("הרשימה \"{$c['list']}\" שנוצרה אינה HTML סגור", 422, ['code' => 'fragment']);
        $open = substr($src, $c['open_start'], $c['open_end'] - $c['open_start']);
        if (!preg_match('/\sdata-kol-prerendered[\s=>\/]/i', $open . '>')) {
            $tail = str_ends_with($open, '/>') ? 2 : 1;
            $open = substr($open, 0, -$tail) . ' data-kol-prerendered' . substr($open, -$tail);
        }
        $out .= substr($src, $at, $c['open_start'] - $at) . $open . substr($src, $c['open_end'], $c['close_start'] - $c['open_end']) . $p['html'];
        $at = $c['close_start'];
        $done++;
    }
    return [$out . substr($src, $at), $done];
}

// ── kol-data.js ───────────────────────────────────────────

function build_kd_version(string $js): ?string
{
    return preg_match("~version:\s*'([\d.]+)'~", $js, $m) ? $m[1] : null;
}

/** מצב js/kol-data.js בפרויקט מול הזרע: קיים? איזו גרסה? תומך בכתיבה מראש? */
function build_kd_state(): array
{
    $seed = (string)file_get_contents(BUILD_SEED_KD);
    $r = pf_by_path(BUILD_KD);
    $mine = $r && $r['kind'] === 'file' ? pf_blob_get($r['sha']) : null;
    return [
        'present' => $mine !== null, 'id' => $r ? (int)$r['id'] : null, 'rev' => $r ? (int)$r['rev'] : null,
        'version' => $mine === null ? null : build_kd_version($mine), 'seed_version' => build_kd_version($seed),
        'prerender' => $mine !== null && (bool)preg_match('/\bprerender\s*:\s*true\b/', $mine), 'same' => $mine === $seed,
    ];
}

/** "🔄 עדכן את kol-data.js" — בעלים בלבד; נקודת שחזור לפני; שמירה רגילה במאגר (היסטוריה, rev). */
function build_kd_update(array $owner, array $b): array
{
    $st = build_kd_state();
    if (!$st['present']) throw new ApiError('אין ' . BUILD_KD . ' בפרויקט', 404);
    if ($st['same']) return ['kd' => $st];
    if ((int)($b['rev'] ?? 0) !== $st['rev']) throw new ApiError('הקובץ השתנה מאז שנפתח המסך — פתחו אותו שוב', 409);
    $snap = snapshot_create($owner, 'לפני עדכון ' . BUILD_KD . ' ל-' . $st['seed_version'], 'manual');
    pf_write($owner, ['id' => $st['id'], 'rev' => $st['rev'], 'content' => (string)file_get_contents(BUILD_SEED_KD), 'tx' => 'עדכון ' . BUILD_KD . ' לגרסה ' . $st['seed_version']]);
    audit_as($owner, 'kd_update', BUILD_KD . " {$st['version']} ← {$st['seed_version']} · נקודת שחזור #$snap");
    return ['kd' => build_kd_state(), 'snapshot' => $snap];
}

function build_kd_seed(): array
{
    return ['content' => (string)file_get_contents(BUILD_SEED_KD)];
}

// ── התחלה, עמוד, סיום ─────────────────────────────────────

function build_opts(array $b): array
{
    $ex = [];
    foreach (array_slice(is_array($b['exclude'] ?? null) ? $b['exclude'] : [], 0, 500) as $p) if (is_string($p) && build_path_ok($p)) $ex[] = $p;
    return ['prerender' => ($b['prerender'] ?? true) !== false, 'include_config' => !empty($b['include_config']), 'exclude' => array_values(array_unique($ex))];
}

function build_start(array $user, array $b): array
{
    pf_seed($user);
    build_expire();
    $opts = build_opts($b);
    [$files, $out, $notes] = build_collect($opts);
    if (!$files) throw new ApiError('אין בפרויקט קבצים לפרסום', 422);
    $kd = build_kd_state();
    $pages = [];
    if ($opts['prerender']) {
        $pages = build_pages($files);
        if ($pages && !$kd['present']) { $notes[] = ['kind' => 'info', 'msg' => 'אין ' . BUILD_KD . ' בפרויקט — אין כתיבה מראש. הרשימות נבנות אצל הגולש בקוד שלכם']; $pages = []; }
    } else {
        $notes[] = ['kind' => 'info', 'msg' => 'נבנה בלי כתיבה מראש — העמוד עובד אצל הגולש כרגיל, וגוגל רואה פחות'];
    }
    $entry = (string)(pf_kol()['config']['entry'] ?? 'index.html');
    if (!isset($files[$entry])) $entry = isset($files['index.html']) ? 'index.html' : (string)array_key_first($files);
    // בנייה פתוחה קודמת של אותו בונה — מוחלפת
    db()->prepare("UPDATE pkg_builds SET status = 'failed', error = ? WHERE status = 'building' AND by_sub = ?")
        ->execute([build_json(['msg' => 'הוחלפה בבנייה חדשה']), $user['sub']]);
    db()->prepare("INSERT INTO pkg_builds (status, rev, print, opts, files, pages, notes, report, by_sub, by_name, at) VALUES ('building', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([build_rev(), build_print(), build_json($opts + ['entry' => $entry]), build_json($files), build_json($pages), build_json($notes),
            build_json(['excluded' => $out]), $user['sub'], $user['name'] ?? '', now_iso()]);
    $id = (int)db()->lastInsertId();
    audit_as($user, 'build_start', "בנייה $id · " . count($files) . ' קבצים');
    return ['id' => $id, 'pages' => $pages, 'token' => build_token($id, 'p'), 'base' => 'preview/', 'sandbox' => PV_SANDBOX, 'kd' => $kd, 'timeout' => 60];
}

/** הדפדפן מסר עמוד שנכתב מראש: רק הקטעים שבתוך המכלים. השרת מרכיב, בודק ושומר. */
function build_page(array $user, array $b): array
{
    $r = build_mine($user, $b);
    $pages = json_decode($r['pages'], true);
    $path = (string)($b['path'] ?? '');
    if (!in_array($path, $pages, true)) throw new ApiError('העמוד אינו ברשימת הבנייה', 422);
    $files = json_decode($r['files'], true);
    $notes = json_decode($r['notes'], true);
    $f = $files[$path];
    if (!empty($b['unsupported'])) {
        $notes[] = ['kind' => 'info', 'file' => $path, 'msg' => BUILD_KD . ' בפרויקט אינו יודע להמשיך מרשימה כתובה (נדרשת 1.1.0) — אין כתיבה מראש. "🔄 עדכן את kol-data.js" במסך הזה'];
        build_save((int)$r['id'], ['notes' => build_json($notes)]);
        return ['ok' => true, 'written' => 0];
    }
    $parts = is_array($b['containers'] ?? null) ? array_values($b['containers']) : throw new ApiError('חסרות הרשימות');
    $total = 0;
    foreach ($parts as $p) $total += is_array($p) ? strlen((string)($p['html'] ?? '')) : 0;
    if ($total > BUILD_PAGE_MAX) throw new ApiError('הרשימות שנוצרו גדולות מ-16MB', 413);
    $src = pf_blob_get($f['src'] === 'prerender' ? $f['orig'] : $f['sha']);
    [$html, $done] = build_splice($src, $parts);
    if ($done) {
        $files[$path] = ['sha' => pf_blob_put($html), 'size' => strlen($html), 'type' => $f['type'], 'src' => 'prerender', 'orig' => $f['src'] === 'prerender' ? $f['orig'] : $f['sha'], 'lists' => $done];
    }
    build_save((int)$r['id'], ['files' => build_json($files)]);
    return ['ok' => true, 'written' => $done];
}

/** כשל בנייה (שגיאת JS בכתיבה מראש, זמן, קובץ חסר): אין גרסה. השגיאה עם קובץ ושורה. */
function build_fail(array $user, array $b): array
{
    $r = build_mine($user, $b);
    $e = is_array($b['error'] ?? null) ? $b['error'] : [];
    $err = ['msg' => mb_substr((string)($e['msg'] ?? 'הבנייה נכשלה'), 0, 500), 'file' => mb_substr((string)($e['file'] ?? ''), 0, 255),
        'line' => (int)($e['line'] ?? 0), 'page' => mb_substr((string)($e['page'] ?? ''), 0, 255)];
    build_save((int)$r['id'], ['status' => 'failed', 'error' => build_json($err)]);
    audit_as($user, 'build_failed', "בנייה {$r['id']} · {$err['msg']}" . ($err['file'] ? " ({$err['file']}:{$err['line']})" : ''));
    devlog_write('warn', 'build', 'build.failed', ['message' => $err['msg'], 'file' => $err['file'], 'line' => $err['line']]);
    return ['build' => build_public(build_row((int)$r['id']))];
}

function build_finish(array $user, array $b): array
{
    $r = build_mine($user, $b);
    $runtime = [];
    foreach (array_slice(is_array($b['externals'] ?? null) ? $b['externals'] : [], 0, 500) as $u) {
        if (is_string($u) && preg_match('~^https?://~i', $u) && strlen($u) <= 2000) $runtime[] = $u;
    }
    $files = json_decode($r['files'], true);
    [$files, $forms, $fnotes] = forms_build($files);          // M5: רישום הטפסים ו-meta של כתובת form.php (lib/forms.php)
    $report = json_decode($r['report'], true) + build_report($files, [...json_decode($r['notes'], true), ...$fnotes], array_values(array_unique($runtime)));
    build_save((int)$r['id'], ['status' => 'built', 'report' => build_json($report), 'built_at' => now_iso(), 'files' => build_json($files), 'forms' => build_json($forms)]);
    audit_as($user, 'build', "גרסה בנויה {$r['id']} · " . count($files) . ' קבצים · ' . count($report['warnings']) . ' אזהרות');
    return ['build' => build_public(build_row((int)$r['id']), true)];
}

// ── "מה ישתנה" ────────────────────────────────────────────

function build_live(): ?array
{
    return db()->query("SELECT * FROM pkg_builds WHERE status = 'live' ORDER BY version DESC LIMIT 1")->fetch() ?: null;
}

/** קבצים, נתונים, משאבים חיצוניים ואזהרות — מול הגרסה האחרונה שנשלחה במסלול החדש. */
function build_report(array $files, array $notes, array $runtime): array
{
    $live = build_live();
    $old = $live ? json_decode($live['files'], true) : [];
    $diff = ['first' => !$live, 'base' => $live ? (int)$live['version'] : null, 'added' => [], 'changed' => [], 'removed' => [], 'same' => 0];
    foreach ($files as $p => $f) {
        $row = ['path' => $p, 'type' => $f['type'], 'size' => $f['size'], 'text' => build_is_text($f['type'])];
        if (!isset($old[$p])) $diff['added'][] = $row;
        elseif ($old[$p]['sha'] !== $f['sha']) $diff['changed'][] = $row + ['old_size' => $old[$p]['size']];
        else $diff['same']++;
    }
    foreach ($old as $p => $f) if (!isset($files[$p])) $diff['removed'][] = ['path' => $p, 'type' => $f['type'], 'size' => $f['size'], 'text' => build_is_text($f['type'])];

    $warn = array_values(array_filter($notes, fn($n) => $n['kind'] !== 'info'));
    $info = array_values(array_filter($notes, fn($n) => $n['kind'] === 'info'));
    $ext = [];
    $total = 0;
    foreach ($files as $p => $f) {
        $total += $f['size'];
        if ($f['size'] > BUILD_BIG_FILE) $warn[] = ['kind' => 'big', 'file' => $p, 'msg' => "קובץ גדול מ-10MB (" . round($f['size'] / 1048576, 1) . 'MB)'];
        if ($f['src'] === 'media' || !build_is_text($f['type']) || $p === PV_WORDS) continue;
        $text = pf_blob_get($f['sha']);
        if ($f['src'] === 'prerender') $text = pf_blob_get($f['orig']);      // סורקים את מה שבעל העמוד כתב
        build_scan($p, $f['type'], $text, $files, $warn, $ext);
    }
    foreach ($runtime as $u) $ext[] = ['url' => $u, 'kind' => 'בקשה בזמן הבנייה', 'file' => null, 'line' => null, 'seen' => 'runtime'];
    $quota = build_quota();
    if ($total > $quota) $warn[] = ['kind' => 'quota', 'block' => true, 'msg' => 'החבילה (' . round($total / 1048576, 1) . 'MB) גדולה מהמכסה (' . round($quota / 1048576) . 'MB) — אי אפשר לשלוח'];
    return ['diff' => $diff, 'data' => build_words_diff($files, $old), 'externals' => build_dedupe($ext), 'warnings' => $warn, 'info' => $info,
        'bytes' => $total, 'quota' => $quota, 'count' => count($files)];
}

function build_dedupe(array $ext): array
{
    $seen = [];
    $out = [];
    foreach ($ext as $e) {
        $k = $e['url'] . '|' . $e['file'] . '|' . $e['line'];
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $e;
    }
    return array_slice($out, 0, 1000);
}

/** סריקה של קובץ טקסט: הפניות פנימיות שבורות, משאבים חיצוניים, ודברים שלא יעבדו בארגז החול. */
function build_scan(string $path, string $type, string $text, array $files, array &$warn, array &$ext): void
{
    $dir = str_contains($path, '/') ? substr($path, 0, strrpos($path, '/') + 1) : '';
    $refs = [];
    $isHtml = $type === 'text/html';
    $isCss = $type === 'text/css';
    if ($isHtml || $type === 'image/svg+xml') {
        preg_match_all('~\s(src|href|poster|action|data)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+))~i', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $x) {
            $v = ($x[2][0] ?? '') . ($x[3][0] ?? '') . ($x[4][0] ?? '');
            $refs[] = [html_entity_decode($v, ENT_QUOTES | ENT_HTML5), $x[0][1], strtolower($x[1][0])];
        }
        preg_match_all('~\ssrcset\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $x) foreach (explode(',', ($x[1][0] ?? '') . ($x[2][0] ?? '')) as $c) $refs[] = [preg_split('/\s+/', trim($c))[0] ?? '', $x[0][1], 'srcset'];
    }
    if ($isHtml || $isCss) {
        preg_match_all('~url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)\s]*))\s*\)|@import\s+(?:"([^"]*)"|\'([^\']*)\')~i', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $x) $refs[] = [($x[1][0] ?? '') . ($x[2][0] ?? '') . ($x[3][0] ?? '') . ($x[4][0] ?? '') . ($x[5][0] ?? ''), $x[0][1], 'url()'];
    }
    foreach ($refs as [$v, $off, $attr]) {
        $v = trim($v);
        if ($v === '' || $v[0] === '#' || preg_match('~^(data|mailto|tel|javascript|blob|about|sms):~i', $v) || str_contains($v, '{')) continue;
        if (preg_match('~^(https?:)?//~i', $v)) {
            $ext[] = ['url' => $v, 'kind' => build_ext_kind($v, $attr), 'file' => $path, 'line' => build_line($text, $off), 'seen' => 'scan'];
            continue;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $v)) continue;
        if ($attr === 'action') continue;
        $clean = rawurldecode((string)preg_replace('~[?#].*$~s', '', $v));
        if ($clean === '') continue;
        $target = build_resolve($dir, $clean);
        if ($target === null) { $warn[] = ['kind' => 'broken', 'file' => $path, 'line' => build_line($text, $off), 'msg' => "הפניה מחוץ לחבילה: $v"]; continue; }
        if (isset($files[$target]) || isset($files[rtrim($target, '/') . '/index.html']) || ($target === '' && isset($files['index.html']))) continue;
        if ($attr === 'href' && !preg_match('/\.[a-z0-9]{1,6}$/i', $target)) continue;     // קישור לעמוד "יפה" — לא נבדק
        $warn[] = ['kind' => 'broken', 'file' => $path, 'line' => build_line($text, $off), 'msg' => "הפניה לקובץ שאינו בחבילה: $v"];
    }
    $checks = [
        '/\blocalStorage\b|\bsessionStorage\b|\bindexedDB\b|document\.cookie/' => 'אחסון בדפדפן (localStorage / cookie) — לא יעבוד בארגז החול',
        '/\bserviceWorker\b/' => 'Service Worker — לא יעבוד בארגז החול',
        '~<iframe\b[^>]*\b(youtube\.com|youtu\.be|youtube-nocookie\.com|vimeo\.com)~i' => 'הטמעת וידאו (יוטיוב / וימאו) — לא תעבוד במקור אטום',
    ];
    if ($type === 'text/javascript' || $isHtml) {
        foreach ($checks as $re => $msg) {
            if (preg_match_all($re, $text, $m, PREG_OFFSET_CAPTURE)) $warn[] = ['kind' => 'sandbox', 'file' => $path, 'line' => build_line($text, $m[0][0][1]), 'msg' => $msg];
        }
    }
}

function build_ext_kind(string $u, string $attr): string
{
    return match (true) {
        (bool)preg_match('~fonts\.(googleapis|gstatic)\.com|\.woff2?(\?|$)|\.ttf(\?|$)~i', $u) => 'גופן',
        (bool)preg_match('~\.js(\?|$)~i', $u) => 'סקריפט',
        (bool)preg_match('~\.css(\?|$)~i', $u), $attr === 'url()' && str_contains($u, 'css') => 'סגנון',
        (bool)preg_match('~youtube|vimeo~i', $u) => 'הטמעה',
        (bool)preg_match('~\.(png|jpe?g|gif|webp|svg|avif)(\?|$)~i', $u) => 'תמונה',
        (bool)preg_match('~\.(mp3|m4a|ogg|wav|mp4|webm)(\?|$)~i', $u) => 'מדיה',
        $attr === 'href' => 'קישור',
        default => 'משאב',
    };
}

/** נתיב יחסי ← נתיב בחבילה; null — יוצא מהחבילה (../ מעל השורש, או / מוחלט). */
function build_resolve(string $dir, string $ref): ?string
{
    if ($ref[0] === '/') return null;
    $parts = [];
    foreach (explode('/', $dir . $ref) as $s) {
        if ($s === '' || $s === '.') continue;
        if ($s === '..') { if (!$parts) return null; array_pop($parts); continue; }
        $parts[] = $s;
    }
    return implode('/', $parts) . (str_ends_with($ref, '/') && $parts ? '/' : '');
}

/** המילים: כמה נוספו, השתנו, נמחקו, וכמה הקלטות — מהשוואת data/words.json. */
function build_words_diff(array $files, array $old): ?array
{
    if (!isset($files[PV_WORDS])) return null;
    $read = function (?array $f): array {
        if (!$f) return [];
        $j = json_decode((string)@file_get_contents(pf_blob_path($f['sha'])), true);
        $out = [];
        foreach ((array)($j['items'] ?? []) as $it) if (is_array($it)) $out[(string)($it['id'] ?? '')] = $it;
        return $out;
    };
    $a = $read($old[PV_WORDS] ?? null);
    $b = $read($files[PV_WORDS]);
    $changed = 0;
    foreach ($b as $k => $w) if (isset($a[$k]) && json_encode($a[$k]) !== json_encode($w)) $changed++;
    $rec = fn($set) => count(array_filter($set, fn($w) => ($w['audio'] ?? '') !== ''));
    $inside = count(array_filter($b, fn($w) => str_starts_with((string)($w['audio'] ?? ''), 'media/')));
    return ['total' => count($b), 'added' => count(array_diff_key($b, $a)), 'removed' => count(array_diff_key($a, $b)), 'changed' => $changed,
        'audio' => $rec($b), 'audio_inside' => $inside, 'audio_before' => $rec($a)];
}

// ── קריאה ─────────────────────────────────────────────────

function build_public(array $r, bool $full = false): array
{
    $err = $r['error'] ? json_decode($r['error'], true) : null;
    $o = [
        'id' => (int)$r['id'], 'status' => $r['status'], 'rev' => (int)$r['rev'], 'stale' => !in_array($r['status'], ['building', 'failed'], true) && $r['print'] !== build_print(),
        'by' => $r['by_name'], 'by_me' => false, 'at' => $r['at'], 'built_at' => $r['built_at'], 'version' => $r['version'] === null ? null : (int)$r['version'],
        'sent_at' => $r['sent_at'], 'sent_by' => $r['sent_by'], 'error' => $err, 'opts' => json_decode($r['opts'], true),
    ];
    $rep = json_decode($r['report'], true);
    $o['count'] = $rep['count'] ?? count(json_decode($r['files'], true));
    $o['bytes'] = $rep['bytes'] ?? null;
    if ($full) $o['report'] = $rep;
    return $o;
}

function builds_list(array $user): array
{
    build_expire();
    $rows = db()->query('SELECT * FROM pkg_builds ORDER BY id DESC LIMIT 30')->fetchAll();
    $out = array_map(fn($r) => ['by_me' => $r['by_sub'] === $user['sub']] + build_public($r), $rows);
    $live = build_live();
    return ['builds' => $out, 'live' => $live ? build_public($live) : null, 'kd' => pf_seeded() ? build_kd_state() : null,
        'can_send' => ROLE_RANK[$user['role']] >= ROLE_RANK['publisher'], 'is_owner' => $user['role'] === 'owner'];
}

function build_get(array $user, array $q): array
{
    $r = build_row((int)($q['id'] ?? 0));
    return ['build' => ['by_me' => $r['by_sub'] === $user['sub']] + build_public($r, true), 'view' => build_token((int)$r['id'], 'v'),
        'files' => array_map(fn($p, $f) => ['path' => $p, 'size' => $f['size'], 'type' => $f['type'], 'src' => $f['src']], array_keys($m = json_decode($r['files'], true)), $m)];
}

/** תוכן של קובץ בגרסה בנויה (side=new) או בגרסה שבאוויר (side=old) — ל-diff. כנתונים, לא כמסמך. */
function build_file(array $q): array
{
    $r = build_row((int)($q['id'] ?? 0));
    $path = (string)($q['path'] ?? '');
    $src = ($q['side'] ?? 'new') === 'old' ? build_live() : $r;
    $files = $src ? json_decode($src['files'], true) : [];
    $f = $files[$path] ?? null;
    if (!$f) return ['missing' => true];
    if ($f['src'] === 'media' || !build_is_text($f['type'])) return ['binary' => true, 'size' => $f['size']];
    return pf_payload(pf_blob_get($f['sha']));
}

// ── כתובת המסגרת: בנייה (p) ותצוגה של הגרסה הבנויה (v) ──

function build_sig(int $id, string $mode, int $exp): string
{
    return rtrim(strtr(base64_encode(hash_hmac('sha256', "pvb1|$id|$mode|$exp", pv_key(), true)), '+/', '-_'), '=');
}

function build_token(int $id, string $mode): string
{
    $exp = time() + BUILD_TTL;
    return 'b' . base_convert((string)$id, 10, 36) . '-' . $mode . '-' . base_convert((string)$exp, 10, 36) . '.' . build_sig($id, $mode, $exp);
}

/** [id, mode] או null. */
function build_token_parse(string $t): ?array
{
    if (!preg_match('/^b([0-9a-z]{1,9})-([pv])-([0-9a-z]{1,9})\.([A-Za-z0-9_-]{43})$/', $t, $m)) return null;
    $id = (int)base_convert($m[1], 36, 10);
    $exp = (int)base_convert($m[3], 36, 10);
    if ($exp < time() || $exp > time() + BUILD_TTL + 60 || !hash_equals(build_sig($id, $m[2], $exp), $m[4])) return null;
    return [$id, $m[2]];
}

/**
 * הגשה מהחבילה (נקרא מ-pv_serve, אחרי כותרות הבידוד). p — העמודים של המאגר + הסקריפט של הבנייה (assets/js/build.js);
 * v — הקבצים הבנויים בדיוק כמו שייצאו, בלי שום תוספת.
 */
function build_serve(int $id, string $mode, string $root, string $path): never
{
    $st = db()->prepare('SELECT status, files, opts FROM pkg_builds WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r || ($mode === 'p' && $r['status'] !== 'building') || $r['status'] === 'failed') pv_fail(403, '403 - this build is closed.');
    $files = json_decode($r['files'], true);
    if ($mode === 'p' && $path === PV_BRIDGE) pv_send((string)file_get_contents(dirname(__DIR__) . '/assets/js/build.js'), null, 'build.js');
    if ($path === '' || str_ends_with($path, '/')) $path = $path === '' ? (string)(json_decode($r['opts'], true)['entry'] ?? 'index.html') : $path . 'index.html';
    $f = build_path_ok($path) ? ($files[$path] ?? null) : null;
    if (!$f) {
        if (isset($files['404.html'])) pv_send(pf_blob_get($files['404.html']['sha']), null, '404.html', 404);
        pv_fail(404, "404 - not in the package.\nאין קובץ כזה בחבילה: " . $path);
    }
    if ($f['src'] === 'media') {
        $file = file_path(file_row((string)$f['file_id']));
        if (!is_file($file)) pv_fail(404, '404 - the media file is missing on the server.');
        pv_send(null, $file, $path, 200, $f['type'] ?? null);
    }
    $sha = $mode === 'p' && $f['src'] === 'prerender' ? $f['orig'] : $f['sha'];
    if ($mode === 'p' && preg_match('/\.html?$/i', $path)) pv_send(pv_inject(pf_blob_get($sha), $root . PV_BRIDGE), null, $path);
    $file = pf_blob_path($sha);
    if (!is_file($file)) pv_fail(404, '404 - the file content is missing on the server.');
    pv_send(null, $file, $path);
}

// ── השליחה ────────────────────────────────────────────────

/** המספר הבא — מונה משותף לשני המסלולים, כדי שהתוסף לעולם לא יקבל מספר שכבר ראה. */
function pkg_next_version(): int
{
    return pub_next_n();
}

function build_send(array $user, array $b): array
{
    $r = build_row((int)($b['id'] ?? 0));
    if (!in_array($r['status'], ['built', 'sending'], true)) throw new ApiError($r['status'] === 'live' ? 'הגרסה הזו כבר באתר' : 'הגרסה אינה מוכנה לשליחה', 409);
    if ($r['by_sub'] !== $user['sub']) throw new ApiError("את הגרסה הזו בנה/תה {$r['by_name']}. כדי לשלוח — בנו אותה בעצמכם, ועברו על \"מה ישתנה\"", 403, ['code' => 'not_builder']);
    $rep = json_decode($r['report'], true);
    foreach ($rep['warnings'] ?? [] as $w) if (!empty($w['block'])) throw new ApiError($w['msg'], 422, ['code' => 'quota']);
    $live = build_live();
    if ($live && (int)$live['id'] > (int)$r['id']) throw new ApiError('באתר כבר גרסה שנבנתה אחרי הזו. בנו מחדש', 409);

    $ping = site_call('ping', []);
    $proto = (int)($ping['protocol'] ?? 0);
    if ($proto < BUILD_PROTOCOL) {
        throw new ApiError('הגרסה מוכנה ונשמרה. התוסף באתר הוא ' . ($ping['plugin'] ?? '1.x') . ' — לשליחה נדרש 2.0 (M2, מטלה לבעלים)', 409,
            ['code' => 'plugin_v1', 'plugin' => $ping['plugin'] ?? null]);
    }
    @set_time_limit(900);
    $n = $r['version'] === null ? pkg_next_version() : (int)$r['version'];
    build_save((int)$r['id'], ['status' => 'sending', 'version' => $n, 'error' => null]);
    $files = json_decode($r['files'], true);
    $sent = 0;
    $entry = (string)(json_decode($r['opts'], true)['entry'] ?? 'index.html');
    $k = pub_key();
    try {
        for ($try = 0; ; $try++) {
            $sent += build_send_blobs($files);
            $man = ['site' => site_host(), 'page' => PUB_PAGE, 'version' => $n, 'time' => time(), 'nonce' => bin2hex(random_bytes(16)), 'entry' => $entry,
                'published_by' => $user['name'] ?? '', 'files' => array_map(fn($f) => ['sha256' => $f['sha'], 'size' => $f['size'], 'type' => $f['type']], $files)];
            $bytes = build_json($man);
            try {
                $res = site_call('manifest', ['manifest' => $bytes, 'manifest_sig' => base64_encode(sodium_crypto_sign_detached($bytes, base64_decode($k['secret']))), 'kid' => $k['kid']]);
                break;
            } catch (ApiError $e) {
                $code = $e->extra['code'] ?? '';
                // התשובה הקודמת אבדה, והאתר כבר מחזיק בדיוק את המספר הזה — הצלחה
                if ($code === 'old_version' && (int)($e->extra['site']['version'] ?? 0) === $n) { $res = $e->extra['site']; break; }
                // B1: האתר מחק גוש ש-pkg_site_blobs זוכר כ"נשלח" (ניקוי, שחזור). שוכחים את הגושים של הגרסה הזו — asset_has
                // יאשר מחדש את מה שעוד שם, ורק החסר נשלח — ומנסים שוב. פעם אחת בלבד: חסר שוב ← הכשל מגיע לכלי
                if ($code !== 'asset_missing' || $try > 0) throw $e;
                $forget = db()->prepare('DELETE FROM pkg_site_blobs WHERE sha = ? AND site = ?');
                foreach (array_unique(array_column($files, 'sha')) as $sha) $forget->execute([$sha, site_host()]);
                audit_as($user, 'pkg_send_retry', "גרסה $n · האתר דיווח על קובץ חסר — שליחה חוזרת של החסרים");
            }
        }
    } catch (ApiError $e) {
        build_save((int)$r['id'], ['error' => build_json(['msg' => $e->getMessage(), 'code' => $e->extra['code'] ?? null, 'send' => true])]);
        audit_as($user, 'pkg_send_failed', "גרסה $n · " . $e->getMessage());
        throw $e;
    }
    $pdo = db();
    $pdo->prepare("UPDATE pkg_builds SET status = 'superseded' WHERE status = 'live'")->execute();
    build_save((int)$r['id'], ['status' => 'live', 'manifest' => $bytes, 'sent_at' => now_iso(), 'sent_by' => $user['name'] ?? '', 'error' => null]);
    audit_as($user, 'pkg_publish', "גרסה $n · " . count($files) . " קבצים · $sent נשלחו");
    notice_add('owner', 'publish', ($user['name'] ?? '') . " פרסם/ה את גרסה $n (פרויקט הקבצים) — " . count($files) . " קבצים, $sent נשלחו", (int)$r['id'], $user);
    return ['build' => build_public(build_row((int)$r['id'])), 'sent' => $sent, 'site' => $res];
}

/** asset_has ← asset_put. מה שהאתר כבר אישר (pkg_site_blobs, או asset_has) — לא נשלח שוב. מחזיר כמה נשלחו. */
function build_send_blobs(array $files): int
{
    $site = site_host();
    $by = [];
    foreach ($files as $p => $f) $by[$f['sha']] ??= $f + ['path' => $p];
    $st = db()->prepare('SELECT 1 FROM pkg_site_blobs WHERE sha = ? AND site = ?');
    $need = [];
    foreach ($by as $sha => $f) { $st->execute([$sha, $site]); if (!$st->fetchColumn()) $need[$sha] = $f; }
    $mark = db()->prepare('INSERT OR IGNORE INTO pkg_site_blobs (sha, site, at) VALUES (?, ?, ?)');
    foreach (array_chunk(array_keys($need), BUILD_HAS_BATCH) as $chunk) {
        $have = (array)(site_call('asset_has', ['sha256' => $chunk])['have'] ?? []);
        foreach ($have as $sha) if (isset($need[$sha])) { $mark->execute([$sha, $site, now_iso()]); unset($need[$sha]); }
    }
    $sent = 0;
    foreach ($need as $sha => $f) {
        $path = $f['src'] === 'media' ? file_path(file_row((string)$f['file_id'])) : pf_blob_path($sha);
        $fh = @fopen($path, 'rb') ?: throw new ApiError('קובץ חסר בשרת שלנו: ' . $f['path'], 500);
        $size = (int)filesize($path);
        $parts = max(1, (int)ceil($size / PUB_PART));
        for ($i = 0; $i < $parts; $i++) {
            $res = site_call('asset_put', ['sha256' => $sha, 'size' => $size, 'type' => $f['type'], 'part' => $i, 'parts' => $parts, 'data' => base64_encode((string)fread($fh, PUB_PART))]);
            if (!empty($res['have'])) break;
        }
        fclose($fh);
        $mark->execute([$sha, $site, now_iso()]);
        $sent++;
    }
    return $sent;
}

// ── הודעות בכלי ([ש-7]) ───────────────────────────────────

function notice_add(string $role, string $kind, string $text, ?int $build, array $by): void
{
    db()->prepare('INSERT INTO notices (role_to, kind, text, build_id, by_name, at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$role, $kind, mb_substr($text, 0, 500), $build, $by['name'] ?? '', now_iso()]);
}

function notices_list(array $owner): array
{
    $rows = db()->query("SELECT id, kind, text, build_id, by_name, at FROM notices WHERE role_to = 'owner' AND read_at IS NULL ORDER BY id DESC LIMIT 50")->fetchAll();
    return ['notices' => array_map(fn($r) => ['id' => (int)$r['id'], 'build' => $r['build_id'] === null ? null : (int)$r['build_id']] + $r, $rows)];
}

function notice_read(array $owner, array $b): array
{
    $ids = array_map('intval', array_slice(is_array($b['ids'] ?? null) ? $b['ids'] : [(int)($b['id'] ?? 0)], 0, 100));
    $st = db()->prepare("UPDATE notices SET read_at = ?, read_by = ? WHERE id = ? AND role_to = 'owner' AND read_at IS NULL");
    foreach ($ids as $id) $st->execute([now_iso(), $owner['name'] ?? '', $id]);
    return notices_list($owner);
}
