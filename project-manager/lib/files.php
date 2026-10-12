<?php
/**
 * קבצים ותיקיות — שלב 3.
 *
 * לכל פרויקט עץ תיקיות שנבנה לבד:
 *   📥 ממתין למיון · 👤 הלקוח · ⚖️ עו"ד · 🧰 תיקייה לכל ספק / איש צוות ·
 *   📐 תוכניות והיתרים · 📷 תמונות מהשטח · 📁 כללי
 * ובכל מקום אפשר לפתוח תת-תיקיות.
 *
 * קובץ גר בתיקייה אחת (is_home), ויכול להופיע בעוד תיקיות כ**קיצור דרך** — לא
 * העתק (הוכרע 2026-10-12). מחיקה היא סימון (deleted_at), לא מחיקה מהדיסק.
 *
 * הקבצים בדיסק: data/files/<project>/<storage> (מוחרג בפריסה); חלקי העלאה:
 * data/tmp/ (מוחרג). שום קובץ לא נגיש ישירות — רק דרך api.php?action=file,
 * אחרי בדיקת הרשאה.
 *
 * העלאה בחלקים של 2MB: upload-start → upload-chunk × n → upload-finish. כך גם
 * סרטון של 100MB עובר בלי לגעת בהגדרות השרת (השער כבר תופס את ה-.user.ini).
 */

declare(strict_types=1);

require_once __DIR__ . '/projects.php';

const PM_FILE_TYPES = [
    'quote' => 'הצעת מחיר', 'proof' => 'אסמכתת תשלום', 'contract' => 'חוזה', 'plan' => 'תוכנית',
    'photo' => 'תמונה', 'video' => 'סרטון', 'invoice' => 'חשבונית', 'other' => 'אחר',
];
const PM_CHUNK = 2 * 1024 * 1024;
const PM_SYSTEM_FOLDERS = [
    // kind => [שם, אייקון, סדר]
    'inbox'   => ['ממתין למיון', '📥', 10],
    'client'  => ['הלקוח', '👤', 20],
    'lawyer'  => ['עו"ד', '⚖️', 30],
    'plans'   => ['תוכניות והיתרים', '📐', 60],
    'photos'  => ['תמונות מהשטח', '📷', 70],
    'general' => ['כללי', '📁', 80],
];

function pm_files_dir(int $projectId = 0): string
{
    $dir = __DIR__ . '/../data/files' . ($projectId ? "/$projectId" : '');
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

function pm_tmp_dir(): string
{
    $dir = __DIR__ . '/../data/tmp';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

function pm_max_upload(): int
{
    return max(1, (int)pm_setting('max_upload_mb', '100')) * 1024 * 1024;
}

// ---- תיקיות ----

/**
 * התיקיות המערכתיות, ותיקייה לכל ספק / איש צוות שמשויך לפרויקט — נוצרות אם חסרות.
 * רץ בכל פתיחה של עץ התיקיות, וזול: רק INSERT למה שחסר.
 */
function pm_ensure_folders(array $p): void
{
    $db = pm_db();
    $have = $db->prepare('SELECT kind, contact_id FROM folders WHERE project_id = ? AND parent_id IS NULL');
    $have->execute([$p['id']]);
    $existing = [];
    foreach ($have->fetchAll() as $r) $existing[$r['kind'] . ':' . (int)$r['contact_id']] = true;
    $ins = $db->prepare('INSERT INTO folders (project_id, parent_id, kind, contact_id, name, sort, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?)');
    foreach (PM_SYSTEM_FOLDERS as $kind => [$name, , $sort]) {
        if (!isset($existing["$kind:0"])) $ins->execute([$p['id'], $kind, null, $name, $sort, pm_now()]);
    }
    // ספקים: מהשלבים, מהצוות ומההצעות. לקוח ועו"ד — בתיקיות שלהם.
    $st = $db->prepare(
        "SELECT DISTINCT c.id, c.name FROM contacts c
          WHERE c.kind = 'supplier' AND (c.id IN (SELECT supplier_id FROM stages WHERE project_id = ?)
                                      OR c.id IN (SELECT contact_id FROM project_members WHERE project_id = ?)
                                      OR c.id IN (SELECT supplier_id FROM quotes WHERE project_id = ?))"
    );
    $st->execute([$p['id'], $p['id'], $p['id']]);
    foreach ($st->fetchAll() as $c) {
        if (!isset($existing['contact:' . $c['id']])) $ins->execute([$p['id'], 'contact', $c['id'], $c['name'], 40, pm_now()]);
    }
}

/** כל התיקיות של הפרויקט, עם מספר הקבצים (כולל קיצורי דרך) ומספר תת-התיקיות. */
function pm_folders(array $user, int $projectId): array
{
    $p = pm_project_row($user, $projectId);
    pm_ensure_folders($p);
    $st = pm_db()->prepare(
        "SELECT f.*, c.name AS contact_name,
                (SELECT COUNT(*) FROM file_links l JOIN files x ON x.id = l.file_id
                  WHERE l.folder_id = f.id AND x.deleted_at IS NULL) AS files
           FROM folders f LEFT JOIN contacts c ON c.id = f.contact_id
          WHERE f.project_id = ? ORDER BY f.sort, f.name COLLATE NOCASE"
    );
    $st->execute([$projectId]);
    return array_map(fn($f) => [
        'id' => (int)$f['id'], 'parent_id' => $f['parent_id'] !== null ? (int)$f['parent_id'] : null,
        'kind' => $f['kind'], 'contact_id' => $f['contact_id'] !== null ? (int)$f['contact_id'] : null,
        'name' => $f['kind'] === 'contact' && $f['contact_name'] ? $f['contact_name'] : $f['name'],
        'icon' => PM_SYSTEM_FOLDERS[$f['kind']][1] ?? ($f['kind'] === 'contact' ? '🧰' : '📁'),
        'files' => (int)$f['files'], 'system' => $f['kind'] !== 'custom',
    ], $st->fetchAll());
}

/** שם תיקייה לתצוגה: תיקיית ספק — בשם הספק. */
function pm_folder_label(array $f): string
{
    if ($f['kind'] === 'contact' && $f['contact_id']) {
        $n = pm_contact_name((int)$f['contact_id']);
        if ($n !== '') return $n;
    }
    return $f['name'];
}

function pm_folder_row(array $user, int $id): array
{
    $st = pm_db()->prepare('SELECT * FROM folders WHERE id = ?');
    $st->execute([$id]);
    $f = $st->fetch();
    if (!$f) throw new PmError('התיקייה לא נמצאה', 404);
    pm_project_row($user, (int)$f['project_id']);
    return $f;
}

function pm_folder_save(array $user, array $in): array
{
    $name = pm_str($in, 'name', 60, true, 'שם התיקייה');
    if (preg_match('#[/\\\\]#', $name)) throw new PmError('שם תיקייה בלי / או \\', 422);
    $id = (int)($in['id'] ?? 0);
    if ($id) {
        $f = pm_folder_row($user, $id);
        if ($f['kind'] !== 'custom') throw new PmError('אי אפשר לשנות שם של תיקייה מערכתית', 422);
        pm_db()->prepare('UPDATE folders SET name = ? WHERE id = ?')->execute([$name, $id]);
        $pid = (int)$f['project_id'];
    } else {
        $pid = (int)($in['project_id'] ?? 0);
        pm_project_row($user, $pid);
        $parent = (int)($in['parent_id'] ?? 0) ?: null;
        if ($parent && (int)pm_folder_row($user, $parent)['project_id'] !== $pid) throw new PmError('תיקיית אב לא תקינה', 422);
        pm_db()->prepare('INSERT INTO folders (project_id, parent_id, kind, name, sort, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$pid, $parent, 'custom', $name, 90, pm_now()]);
        $id = (int)pm_db()->lastInsertId();
        pm_audit("user:{$user['id']}", 'folder.create', 'folder', $id, ['name' => $name, 'project' => $pid]);
    }
    return ['folders' => pm_folders($user, $pid), 'folder_id' => $id];
}

/** מחיקת תיקייה — רק custom, ורק ריקה (בלי קבצים ובלי תת-תיקיות). */
function pm_folder_delete(array $user, int $id): array
{
    $f = pm_folder_row($user, $id);
    if ($f['kind'] !== 'custom') throw new PmError('אי אפשר למחוק תיקייה מערכתית', 422);
    $db = pm_db();
    $c = $db->prepare('SELECT (SELECT COUNT(*) FROM folders WHERE parent_id = ?) + (SELECT COUNT(*) FROM file_links l JOIN files x ON x.id = l.file_id WHERE l.folder_id = ? AND x.deleted_at IS NULL)');
    $c->execute([$id, $id]);
    if ((int)$c->fetchColumn() > 0) throw new PmError('התיקייה לא ריקה', 422);
    $db->prepare('DELETE FROM folders WHERE id = ?')->execute([$id]);
    pm_audit("user:{$user['id']}", 'folder.delete', 'folder', $id, ['name' => $f['name']]);
    return ['folders' => pm_folders($user, (int)$f['project_id'])];
}

// ---- קבצים: רשימה ----

/**
 * $f: folder_id (תוכן תיקייה) · type · q · recent (האחרונים בפרויקט) — אחד או יותר.
 */
function pm_files_list(array $user, int $projectId, array $f): array
{
    pm_project_row($user, $projectId);
    $where = ['x.project_id = ?', 'x.deleted_at IS NULL'];
    $args = [$projectId];
    $join = '';
    if (!empty($f['folder_id'])) {
        $join = 'JOIN file_links l ON l.file_id = x.id AND l.folder_id = ?';
        array_unshift($args, (int)$f['folder_id']);
    }
    if (!empty($f['type']) && isset(PM_FILE_TYPES[$f['type']])) { $where[] = 'x.type = ?'; $args[] = $f['type']; }
    if (!empty($f['q'])) { $where[] = '(x.name LIKE ? OR x.note LIKE ?)'; $like = '%' . strtr((string)$f['q'], ['%' => '', '_' => '']) . '%'; array_push($args, $like, $like); }
    $limit = !empty($f['recent']) ? 'LIMIT 30' : 'LIMIT 1000';
    $st = pm_db()->prepare("SELECT x.*, u.display_name AS uploader FROM files x $join LEFT JOIN users u ON u.id = x.uploaded_by
                             WHERE " . implode(' AND ', $where) . " ORDER BY x.created_at DESC $limit");
    $st->execute($args);
    $rows = $st->fetchAll();
    $links = [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $l = pm_db()->prepare("SELECT file_id, folder_id, is_home FROM file_links WHERE file_id IN ($in)");
        $l->execute($ids);
        foreach ($l->fetchAll() as $r) $links[$r['file_id']][] = ['folder_id' => (int)$r['folder_id'], 'home' => (bool)$r['is_home']];
    }
    return array_map(fn($x) => pm_file_out($x, $links[$x['id']] ?? []), $rows);
}

function pm_file_out(array $x, array $links): array
{
    return [
        'id' => (int)$x['id'], 'name' => $x['name'], 'mime' => $x['mime'], 'size' => (int)$x['size'],
        'type' => $x['type'], 'visible' => (bool)$x['visible'], 'note' => $x['note'], 'has_thumb' => (bool)$x['thumb'],
        'uploader' => $x['uploader'] ?? '', 'created_at' => $x['created_at'], 'folders' => $links,
    ];
}

function pm_file_row(array $user, int $id): array
{
    $st = pm_db()->prepare('SELECT * FROM files WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$id]);
    $x = $st->fetch();
    if (!$x) throw new PmError('הקובץ לא נמצא', 404);
    pm_project_row($user, (int)$x['project_id']);
    return $x;
}

function pm_file_get(array $user, int $id): array
{
    $x = pm_file_row($user, $id);
    $l = pm_db()->prepare('SELECT folder_id, is_home FROM file_links WHERE file_id = ?');
    $l->execute([$id]);
    $u = pm_db()->prepare('SELECT display_name FROM users WHERE id = ?');
    $u->execute([$x['uploaded_by']]);
    $x['uploader'] = (string)$u->fetchColumn();
    return pm_file_out($x, array_map(fn($r) => ['folder_id' => (int)$r['folder_id'], 'home' => (bool)$r['is_home']], $l->fetchAll()));
}

// ---- העלאה בחלקים ----

function pm_upload_start(array $user, array $in): array
{
    $pid = (int)($in['project_id'] ?? 0);
    pm_project_row($user, $pid);
    $folder = pm_folder_row($user, (int)($in['folder_id'] ?? 0));
    if ((int)$folder['project_id'] !== $pid) throw new PmError('התיקייה לא שייכת לפרויקט', 422);
    $name = pm_clean_filename((string)($in['name'] ?? ''));
    $size = (int)($in['size'] ?? 0);
    if ($size <= 0) throw new PmError('קובץ ריק', 422);
    $max = pm_max_upload();
    if ($size > $max) throw new PmError('הקובץ גדול מדי — עד ' . (int)($max / 1048576) . 'MB', 413);
    pm_upload_cleanup();
    $id = bin2hex(random_bytes(12));
    pm_db()->prepare('INSERT INTO uploads (id, project_id, folder_id, user_id, name, mime, size, created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$id, $pid, (int)$folder['id'], $user['id'], $name, mb_substr((string)($in['mime'] ?? ''), 0, 100), $size, pm_now()]);
    touch(pm_tmp_dir() . "/$id.part");
    pm_log('info', 'data', 'upload.start', ['data' => ['upload' => $id, 'name' => $name, 'size' => $size, 'project' => $pid]]);
    return ['upload_id' => $id, 'chunk_size' => PM_CHUNK];
}

/** חלק אחד — גוף הבקשה הוא הבתים עצמם. החלקים נשלחים בסדר; offset מוודא שלא דילגנו. */
function pm_upload_chunk(array $user, string $id, int $offset): array
{
    $u = pm_upload_row($user, $id);
    if ($offset !== (int)$u['received']) {
        return ['received' => (int)$u['received']]; // הדפדפן ימשיך מהמקום הנכון (חידוש אחרי ניתוק)
    }
    $data = file_get_contents('php://input');
    $len = strlen((string)$data);
    if ($len === 0 || $len > PM_CHUNK) throw new PmError('חלק לא תקין', 422);
    if ($u['received'] + $len > $u['size']) throw new PmError('הקובץ גדול מהמוצהר', 422);
    file_put_contents(pm_tmp_dir() . "/$id.part", $data, FILE_APPEND | LOCK_EX);
    pm_db()->prepare('UPDATE uploads SET received = received + ? WHERE id = ?')->execute([$len, $id]);
    return ['received' => (int)$u['received'] + $len];
}

function pm_upload_row(array $user, string $id): array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $id)) throw new PmError('העלאה לא מוכרת', 404);
    $st = pm_db()->prepare('SELECT * FROM uploads WHERE id = ? AND user_id = ?');
    $st->execute([$id, $user['id']]);
    $u = $st->fetch();
    if (!$u) throw new PmError('ההעלאה לא נמצאה או שפגה — נסו שוב', 404);
    return $u;
}

function pm_upload_finish(array $user, array $in): array
{
    $id = (string)($in['upload_id'] ?? '');
    $u = pm_upload_row($user, $id);
    $part = pm_tmp_dir() . "/$id.part";
    clearstatcache();
    if ((int)$u['received'] !== (int)$u['size'] || filesize($part) !== (int)$u['size']) {
        throw new PmError('ההעלאה לא הושלמה — נסו שוב', 422);
    }
    $mime = pm_detect_mime($part, $u['mime']);
    $type = (string)($in['type'] ?? '');
    if (!isset(PM_FILE_TYPES[$type])) $type = str_starts_with($mime, 'image/') ? 'photo' : (str_starts_with($mime, 'video/') ? 'video' : 'other');
    $ext = strtolower(pathinfo($u['name'], PATHINFO_EXTENSION));
    $storage = bin2hex(random_bytes(12)) . (preg_match('/^[a-z0-9]{1,8}$/', $ext) ? ".$ext" : '');
    $dir = pm_files_dir((int)$u['project_id']);
    if (!rename($part, "$dir/$storage")) throw new PmError('שמירת הקובץ נכשלה', 500);
    $thumb = pm_save_thumb($dir, (string)($in['thumb'] ?? ''));

    $db = pm_db();
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO files (project_id, name, mime, size, type, storage, thumb, visible, note, uploaded_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$u['project_id'], $u['name'], $mime, $u['size'], $type, $storage, $thumb, 0, '', $user['id'], pm_now()]);
        $fileId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO file_links (file_id, folder_id, is_home) VALUES (?,?,1)')->execute([$fileId, $u['folder_id']]);
        $db->prepare('DELETE FROM uploads WHERE id = ?')->execute([$id]);
        $fr = $db->prepare('SELECT * FROM folders WHERE id = ?');
        $fr->execute([$u['folder_id']]);
        // quiet: חלק מדיווח מהשטח — הדיווח עצמו נרשם בציר הזמן, לא כל תמונה
        if (empty($in['quiet'])) {
            pm_event($user, (int)$u['project_id'], 'file', "📎 {$u['name']} ← " . pm_folder_label($fr->fetch()),
                     ['file' => $fileId, 'folder' => (int)$u['folder_id'], 'size' => (int)$u['size'], 'type' => $type]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        @unlink("$dir/$storage");
        throw $e;
    }
    return ['file' => pm_file_get($user, $fileId)];
}

/** תמונה ממוזערת שהדפדפן הכין (data:image/jpeg;base64,…) — עד 200KB. */
/**
 * קובץ שהגיע בבקשה אחת (מהפורטל — אסמכתא), לתיקייה הראשית מסוג $kind
 * (client · lawyer · inbox). מחזיר את מזהה הקובץ. בלי אירוע — הקורא רושם.
 */
function pm_file_store(int $projectId, string $kind, string $name, string $tmpPath, string $type, int $uploadedBy, string $note = ''): int
{
    pm_ensure_folders(['id' => $projectId]);
    $f = pm_db()->prepare('SELECT id FROM folders WHERE project_id = ? AND kind = ? AND parent_id IS NULL');
    $f->execute([$projectId, $kind]);
    $folder = (int)$f->fetchColumn();
    if (!$folder) throw new PmError('אין תיקייה לקובץ', 500);
    $name = pm_clean_filename($name);
    $mime = pm_detect_mime($tmpPath, '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $storage = bin2hex(random_bytes(12)) . (preg_match('/^[a-z0-9]{1,8}$/', $ext) ? ".$ext" : '');
    $dir = pm_files_dir($projectId);
    $size = (int)filesize($tmpPath);
    if (!rename($tmpPath, "$dir/$storage")) throw new PmError('שמירת הקובץ נכשלה', 500);
    $db = pm_db();
    $db->prepare('INSERT INTO files (project_id, name, mime, size, type, storage, thumb, visible, note, uploaded_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$projectId, $name, $mime, $size, $type, $storage, null, 0, $note, $uploadedBy, pm_now()]);
    $id = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO file_links (file_id, folder_id, is_home) VALUES (?,?,1)')->execute([$id, $folder]);
    return $id;
}

function pm_save_thumb(string $dir, string $dataUrl): ?string
{
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#', $dataUrl, $m)) return null;
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) > 200 * 1024 || @getimagesizefromstring($bin) === false) return null;
    $name = 't_' . bin2hex(random_bytes(10)) . '.' . ($m[1] === 'jpeg' ? 'jpg' : $m[1]);
    file_put_contents("$dir/$name", $bin);
    return $name;
}

function pm_detect_mime(string $path, string $claimed): string
{
    $real = function_exists('mime_content_type') ? (string)@mime_content_type($path) : '';
    return $real !== '' && $real !== 'application/octet-stream' ? $real : ($claimed ?: 'application/octet-stream');
}

function pm_clean_filename(string $name): string
{
    $name = trim(str_replace(["\0", '/', '\\'], '', $name));
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    if ($name === '' || $name === '.' || $name === '..') $name = 'קובץ';
    return mb_substr($name, 0, 150);
}

/** חלקי העלאה שננטשו לפני יותר מיום. */
function pm_upload_cleanup(): void
{
    $old = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
    $st = pm_db()->prepare('SELECT id FROM uploads WHERE created_at < ?');
    $st->execute([$old]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) @unlink(pm_tmp_dir() . "/$id.part");
    pm_db()->prepare('DELETE FROM uploads WHERE created_at < ?')->execute([$old]);
}

// ---- הגשה ----

/** מגיש את הקובץ (או את הממוזערת) — עם Range, כדי שסרטון ינוגן ויודלג בטלפון. */
function pm_file_send(array $user, int $id, bool $thumb, bool $download): never
{
    pm_file_output(pm_file_row($user, $id), $thumb, $download);
}

/** הגשת קובץ שההרשאה אליו כבר נבדקה — מהאפליקציה או מהפורטל. */
function pm_file_output(array $x, bool $thumb, bool $download): never
{
    $dir = pm_files_dir((int)$x['project_id']);
    $path = $thumb && $x['thumb'] ? "$dir/{$x['thumb']}" : "$dir/{$x['storage']}";
    if (!is_file($path)) throw new PmError('הקובץ חסר בשרת', 404);
    $size = filesize($path);
    $mime = $thumb && $x['thumb'] ? 'image/jpeg' : ($x['mime'] ?: 'application/octet-stream');
    // רק סוגים שבטוח להציג בדפדפן מוצגים; כל השאר — הורדה (HTML / SVG לא יורצו מכאן)
    $inline = !$download && preg_match('#^(image/(jpeg|png|gif|webp|heic|heif)|video/|audio/|application/pdf$)#', $mime);
    header_remove('Content-Type');
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'");
    header('Cache-Control: private, max-age=86400');
    header('Accept-Ranges: bytes');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($x['name']));
    $start = 0;
    $end = $size - 1;
    if (!$thumb && preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); }
        else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
        if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    $fp = fopen($path, 'rb');
    fseek($fp, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fp)) {
        $chunk = fread($fp, min(262144, $left));
        if ($chunk === false) break;
        echo $chunk;
        $left -= strlen($chunk);
        flush();
    }
    fclose($fp);
    exit;
}

// ---- שינויים ----

function pm_file_update(array $user, array $in): array
{
    $x = pm_file_row($user, (int)($in['id'] ?? 0));
    $type = (string)($in['type'] ?? $x['type']);
    if (!isset(PM_FILE_TYPES[$type])) throw new PmError('סוג קובץ לא מוכר', 422);
    $name = pm_clean_filename((string)($in['name'] ?? $x['name']));
    $visible = !empty($in['visible']) ? 1 : 0;
    $note = pm_str($in, 'note', 2000, false, 'הערה');
    pm_db()->prepare('UPDATE files SET name = ?, type = ?, visible = ?, note = ? WHERE id = ?')->execute([$name, $type, $visible, $note, $x['id']]);
    if ((int)$x['visible'] !== $visible) {
        pm_event($user, (int)$x['project_id'], 'file_visible', ($visible ? '👁 גלוי ללקוח: ' : '🔒 פנימי: ') . $name, ['file' => (int)$x['id']]);
    } else {
        pm_audit("user:{$user['id']}", 'file.update', 'file', (int)$x['id'], ['name' => $name, 'type' => $type]);
    }
    return ['file' => pm_file_get($user, (int)$x['id'])];
}

/** העברה: התיקייה של הקובץ מתחלפת. קיצורי הדרך נשארים. */
function pm_file_move(array $user, int $id, int $folderId): array
{
    $x = pm_file_row($user, $id);
    $f = pm_folder_row($user, $folderId);
    if ((int)$f['project_id'] !== (int)$x['project_id']) throw new PmError('אפשר להעביר רק בתוך אותו פרויקט', 422);
    $db = pm_db();
    $db->prepare('DELETE FROM file_links WHERE file_id = ? AND (is_home = 1 OR folder_id = ?)')->execute([$id, $folderId]);
    $db->prepare('INSERT INTO file_links (file_id, folder_id, is_home) VALUES (?,?,1)')->execute([$id, $folderId]);
    pm_event($user, (int)$x['project_id'], 'file_move', "📂 {$x['name']} → " . pm_folder_label($f), ['file' => $id, 'folder' => $folderId]);
    return ['file' => pm_file_get($user, $id)];
}

/** קיצור דרך: הקובץ מופיע גם בתיקייה נוספת — בלי העתק. */
function pm_file_link(array $user, int $id, int $folderId, bool $add): array
{
    $x = pm_file_row($user, $id);
    $f = pm_folder_row($user, $folderId);
    if ((int)$f['project_id'] !== (int)$x['project_id']) throw new PmError('רק בתוך אותו פרויקט', 422);
    if ($add) {
        pm_db()->prepare('INSERT OR IGNORE INTO file_links (file_id, folder_id, is_home) VALUES (?,?,0)')->execute([$id, $folderId]);
    } else {
        pm_db()->prepare('DELETE FROM file_links WHERE file_id = ? AND folder_id = ? AND is_home = 0')->execute([$id, $folderId]);
    }
    pm_audit("user:{$user['id']}", $add ? 'file.link' : 'file.unlink', 'file', $id, ['folder' => $folderId]);
    return ['file' => pm_file_get($user, $id)];
}

function pm_file_delete(array $user, int $id): array
{
    $x = pm_file_row($user, $id);
    pm_db()->prepare('UPDATE files SET deleted_at = ? WHERE id = ?')->execute([pm_now(), $id]);
    pm_event($user, (int)$x['project_id'], 'file_deleted', "🗑️ נמחק: {$x['name']}", ['file' => $id]);
    return ['ok' => true];
}
