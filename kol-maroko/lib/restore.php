<?php
/**
 * שחזור (הכרעת בעל המערכת, 2026-11-01): "כל שינוי ניתן לשחזור, ושום דבר לא נמחק לצמיתות בלי אישור מפורש".
 *
 * נקודות שחזור — צילום מלא של מה שבכלי: הטיוטה, המילים, הנושאים, קבצי הקוד, ה-CSS/JS שיובאו ומצב הקבצים
 * (פעיל / בסל). נוצרות אוטומטית לפני פעולות של צ'אט, לפני כל ייבוא ופעולה גורפת, ופעם ביום; וידנית. כל רכיב נשמר
 * פעם אחת לפי התוכן שלו (blobs, דחוס) — 1,500 מילים שלא השתנו לא נשמרות שוב. נקודות לא נמחקות לעולם (אין API).
 * שחזור יוצר קודם נקודה של המצב הנוכחי — ולכן גם שחזור ניתן לביטול, בדיוק (בייט בבייט).
 *
 * סל המחזור — קבצים, מילים ופעולות על נושאים. מחיקה = לסל; מחיקה סופית רק משם, רק לבעלים ובאישור מפורש. קובץ
 * שגרסה כלשהי מפנה אליו — לא נמחק סופית.
 */

declare(strict_types=1);

const SNAP_SETTINGS = ['imported_css', 'preview_css', 'preview_js', 'theme_lexicon_css', 'theme_style_css', 'imported_from'];
const SNAP_CHAT_IDLE = 1800;             // צ'אט שלא עשה כלום חצי שעה — הפעולה הבאה שלו פותחת נקודה חדשה

// ── התוכן ─────────────────────────────────────────────────

function blob_put(string $data): string
{
    $sha = hash('sha256', $data);
    $st = db()->prepare('INSERT OR IGNORE INTO blobs (sha, data, size) VALUES (?, ?, ?)');
    $st->bindValue(1, $sha);
    $st->bindValue(2, gzcompress($data, 6), PDO::PARAM_LOB);
    $st->bindValue(3, strlen($data), PDO::PARAM_INT);
    $st->execute();
    return $sha;
}

function blob_get(string $sha): string
{
    $st = db()->prepare('SELECT data FROM blobs WHERE sha = ?');
    $st->execute([$sha]);
    $d = $st->fetchColumn();
    if ($d === false) throw new ApiError('חסר תוכן של נקודת השחזור', 500);
    return (string)gzuncompress(is_resource($d) ? stream_get_contents($d) : $d);
}

/** המצב הנוכחי, רכיב רכיב (JSON יציב — אותו מצב נותן בדיוק אותו טקסט). */
function snap_state(): array
{
    $pdo = db();
    $j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $settings = [];
    foreach (SNAP_SETTINGS as $k) $settings[$k] = setting($k);
    return [
        'draft' => (string)draft_row()['json'],
        'words' => $j($pdo->query('SELECT * FROM words ORDER BY id')->fetchAll()),
        'topics' => $j($pdo->query('SELECT * FROM topics ORDER BY slug')->fetchAll()),
        'code' => $j($pdo->query('SELECT * FROM code_files ORDER BY path')->fetchAll()),
        'settings' => $j($settings),
        'files' => $j($pdo->query('SELECT id, deleted_at, deleted_by FROM files ORDER BY id')->fetchAll()),
    ];
}

function snap_parts(): array
{
    return array_map('blob_put', snap_state());
}

/** טביעה של כל המצב — לבדיקה ש"שחזור ובחזרה" מחזיר בדיוק את מה שהיה. */
function snap_fingerprint(): string
{
    return hash('sha256', implode('|', array_map(fn($s) => hash('sha256', $s), snap_state())));
}

// ── נקודות ────────────────────────────────────────────────

/** נקודה חדשה. מצב זהה לנקודה האחרונה — לא נוצרת שנייה (חוזרת האחרונה). */
function snapshot_create(array $user, string $reason, string $kind = 'auto'): int
{
    $parts = snap_parts();
    $pdo = db();
    $last = $pdo->query('SELECT id, parts FROM snapshots ORDER BY id DESC LIMIT 1')->fetch();
    if ($last && json_decode($last['parts'], true) == $parts) return (int)$last['id'];
    $meta = [
        'words' => (int)$pdo->query('SELECT COUNT(*) FROM words')->fetchColumn(),
        'code' => (int)$pdo->query('SELECT COUNT(*) FROM code_files')->fetchColumn(),
        'files' => (int)$pdo->query('SELECT COUNT(*) FROM files WHERE deleted_at IS NULL')->fetchColumn(),
        'rev' => (int)draft_row()['rev'],
    ];
    $pdo->prepare('INSERT INTO snapshots (at, sub, name, reason, kind, parts, meta) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([now_iso(), $user['sub'], $user['name'], mb_substr($reason, 0, 200), $kind, json_encode($parts), json_encode($meta)]);
    return (int)$pdo->lastInsertId();
}

function snapshot_manual(array $user, array $b): array
{
    $id = snapshot_create($user, str($b['note'] ?? '', 200) ?: 'נקודה ידנית', 'manual');
    audit_as($user, 'snapshot', "נקודה #$id");
    return ['id' => $id] + snapshots_list([]);
}

/** פעם ביום — בבקשה הראשונה של היום (בלי משימה מתוזמנת). */
function snapshot_daily(): void
{
    $today = gmdate('Y-m-d');
    if (setting('snap_daily') === $today) return;
    setting_set('snap_daily', $today);
    snapshot_create(['sub' => 'system', 'name' => 'אוטומטי'], 'נקודה יומית', 'daily');
}

function snapshots_list(array $q): array
{
    $off = max(0, (int)($q['offset'] ?? 0));
    $rows = db()->query('SELECT id, at, name, reason, kind, meta FROM snapshots ORDER BY id DESC LIMIT 50 OFFSET ' . $off)->fetchAll();
    return ['snapshots' => array_map(fn($r) => ['id' => (int)$r['id'], 'meta' => json_decode($r['meta'], true)] + $r, $rows),
        'total' => (int)db()->query('SELECT COUNT(*) FROM snapshots')->fetchColumn(), 'offset' => $off];
}

function snapshot_row(int $id): array
{
    $st = db()->prepare('SELECT * FROM snapshots WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: throw new ApiError('הנקודה לא נמצאה', 404);
}

/** תצוגה של נקודה: העמוד שלה, עם המילים וקבצי הקוד שלה. */
function snapshot_preview(array $q): array
{
    $s = snapshot_row((int)($q['id'] ?? 0));
    $p = json_decode($s['parts'], true);
    $words = array_map(fn($w) => ['id' => (int)$w['id'], 'wp_id' => $w['wp_id'] === null ? null : (int)$w['wp_id'], 'word' => $w['word'], 'meaning' => $w['meaning'],
        'topics' => json_decode($w['topics'], true) ?: [], 'audio' => $w['audio'], 'external_id' => $w['external_id']], json_decode(blob_get($p['words']), true));
    $code = json_decode(blob_get($p['code']), true);
    $GLOBALS['kol_words'] = lex_sort($words, 'word');
    $GLOBALS['kol_code'] = $code ? array_column($code, 'content', 'path') : null;
    try { return page_render(page_normalize(json_decode(blob_get($p['draft']), true)), true); }
    finally { unset($GLOBALS['kol_words'], $GLOBALS['kol_code']); }
}

/** החזרת המצב של נקודה. קודם — נקודה של המצב הנוכחי (כך שגם השחזור ניתן לביטול). */
function snapshot_restore(array $user, array $b): array
{
    $s = snapshot_row((int)($b['id'] ?? 0));
    $l = lock_state();
    if ($l && $l['sub'] !== $user['sub']) throw new ApiError("הטיוטה בעריכה אצל {$l['name']}. אחרי שיסיים — שוב", 423);
    $pre = snapshot_create($user, "לפני שחזור לנקודה #{$s['id']}", 'pre_restore');
    $missing = snapshot_apply($user, json_decode($s['parts'], true), "שחזור לנקודה #{$s['id']}");
    audit_as($user, 'snapshot_restore', "נקודה #{$s['id']} · לפני השחזור: #$pre");
    return ['restored' => (int)$s['id'], 'pre' => $pre, 'missing' => $missing];
}

/** כתיבת המצב: שורות כמו שהן (כולל מזהים ותאריכים), כדי שהמצב יהיה זהה בדיוק. */
function snapshot_apply(array $user, array $parts, string $note): array
{
    $pdo = db();
    $rows = fn(string $k) => json_decode(blob_get($parts[$k]), true) ?: [];
    $put = function (string $table, array $list) use ($pdo) {
        $pdo->exec("DELETE FROM $table");
        foreach ($list as $r) {
            $cols = array_keys($r);
            $pdo->prepare("INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($r));
        }
    };
    $missing = [];
    $pdo->beginTransaction();
    try {
        $d = draft_row();
        $json = blob_get($parts['draft']);
        if ($json !== $d['json']) {
            revision_add($user, $d['json'], (int)$d['rev'], 'לפני ' . $note);
            $pdo->prepare('UPDATE draft SET json = ?, rev = rev + 1, updated_by = ?, updated_at = ? WHERE id = 1')->execute([$json, $user['sub'], now_iso()]);
        }
        $put('words', $rows('words'));
        $put('topics', $rows('topics'));
        $put('code_files', $rows('code'));
        foreach (json_decode(blob_get($parts['settings']), true) ?: [] as $k => $v) {
            if (!in_array($k, SNAP_SETTINGS, true)) continue;
            if ($v === null) $pdo->prepare('DELETE FROM settings WHERE k = ?')->execute([$k]);
            else setting_set($k, (string)$v);
        }
        $have = array_column($pdo->query('SELECT id FROM files')->fetchAll(), 'id', 'id');
        $st = $pdo->prepare('UPDATE files SET deleted_at = ?, deleted_by = ? WHERE id = ?');
        foreach ($rows('files') as $f) {
            if (isset($have[$f['id']])) $st->execute([$f['deleted_at'], $f['deleted_by'], $f['id']]);
            elseif ($f['deleted_at'] === null) $missing[] = $f['id'];          // נמחק סופית אחרי הנקודה
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $missing;
}

/** צ'אט: לפני הפעולה הראשונה שלו (או אחרי חצי שעה בלי פעולה) — נקודה. */
function snapshot_before_chat(array $user): void
{
    $key = 'chat_write_' . $user['agent'];
    $last = setting($key);
    setting_set($key, (string)time());
    if ($last !== null && (int)$last > time() - SNAP_CHAT_IDLE) return;
    snapshot_create($user, 'לפני צ\'אט · ' . ($user['agent_label'] ?? $user['agent']), 'chat');
}

// ── סל המחזור ─────────────────────────────────────────────

function trash_list(): array
{
    $pdo = db();
    $files = array_map(fn($f) => file_public($f) + ['deleted_at' => $f['deleted_at'], 'deleted_by' => $f['deleted_by']],
        $pdo->query('SELECT * FROM files WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC')->fetchAll());
    $words = array_map(fn($r) => ['id' => (int)$r['id'], 'at' => $r['at'], 'by' => $r['by_name'], 'word' => (json_decode($r['row'], true)['word'] ?? '?')],
        $pdo->query('SELECT id, at, by_name, row FROM words_trash ORDER BY id DESC LIMIT 500')->fetchAll());
    $topics = array_map(fn($r) => ['id' => (int)$r['id'], 'at' => $r['at'], 'by' => $r['by_name'], 'op' => $r['op'], 'name' => $r['name'], 'to' => $r['to_name'],
        'words' => count(json_decode($r['before'], true) ?: [])], $pdo->query('SELECT * FROM topic_trash ORDER BY id DESC LIMIT 500')->fetchAll());
    return ['files' => $files, 'words' => $words, 'topics' => $topics];
}

function trash_restore(array $user, array $b): array
{
    $pdo = db();
    $kind = (string)($b['kind'] ?? '');
    $id = $b['id'] ?? '';
    if ($kind === 'file') {
        $f = file_row((string)$id);
        if (!$f['deleted_at']) throw new ApiError('הקובץ לא בסל');
        if (!is_file(file_path($f))) throw new ApiError('הקובץ עצמו כבר לא קיים בשרת', 410);
        $pdo->prepare('UPDATE files SET deleted_at = NULL, deleted_by = NULL WHERE id = ?')->execute([$f['id']]);
        audit_as($user, 'trash_restore', 'קובץ · ' . $f['name']);
    } elseif ($kind === 'word') {
        $st = $pdo->prepare('SELECT * FROM words_trash WHERE id = ?');
        $st->execute([(int)$id]);
        $t = $st->fetch() ?: throw new ApiError('לא נמצא בסל', 404);
        $r = json_decode($t['row'], true);
        $exists = $pdo->prepare('SELECT 1 FROM words WHERE id = ?');
        $exists->execute([$r['id']]);
        if ($exists->fetchColumn()) unset($r['id']);                       // המזהה נתפס — מילה עם מזהה חדש
        $cols = array_keys($r);
        $pdo->prepare('INSERT INTO words (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($r));
        $pdo->prepare('DELETE FROM words_trash WHERE id = ?')->execute([(int)$id]);
        audit_as($user, 'trash_restore', 'מילה · ' . $r['word']);
    } elseif ($kind === 'topic') {
        $st = $pdo->prepare('SELECT * FROM topic_trash WHERE id = ?');
        $st->execute([(int)$id]);
        $t = $st->fetch() ?: throw new ApiError('לא נמצא בסל', 404);
        $upd = $pdo->prepare('UPDATE words SET topics = ?, updated_at = ?, updated_by = ? WHERE id = ?');
        foreach (json_decode($t['before'], true) ?: [] as $wid => $topics) $upd->execute([json_encode($topics, JSON_UNESCAPED_UNICODE), now_iso(), $user['name'], (int)$wid]);
        $map = topic_slugs();
        $pdo->prepare('INSERT OR IGNORE INTO topics (slug, name) VALUES (?, ?)')->execute([topic_slug($t['name'], $map), $t['name']]);
        $pdo->prepare('DELETE FROM topic_trash WHERE id = ?')->execute([(int)$id]);
        audit_as($user, 'trash_restore', 'נושא · ' . $t['name']);
    } else {
        throw new ApiError('בקשה לא תקינה');
    }
    return trash_list();
}

/** מחיקה סופית — רק בעלים, רק מהסל, ורק עם אישור מפורש (confirm = השם). */
function trash_purge(array $owner, array $b): array
{
    $pdo = db();
    $kind = (string)($b['kind'] ?? '');
    if (($b['confirm'] ?? null) !== true) throw new ApiError('מחיקה סופית דורשת אישור מפורש');
    if ($kind === 'file') {
        $f = file_row((string)($b['id'] ?? ''));
        if (!$f['deleted_at']) throw new ApiError('אפשר למחוק סופית רק מהסל');
        if (file_in_versions($f['id'])) throw new ApiError('גרסה שמורה מפנה לקובץ הזה — הוא לא נמחק סופית', 409);
        $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$f['id']]);
        @unlink(file_path($f));
        audit_as($owner, 'trash_purge', 'קובץ · ' . $f['name']);
    } elseif ($kind === 'word' || $kind === 'topic') {
        $pdo->prepare('DELETE FROM ' . ($kind === 'word' ? 'words_trash' : 'topic_trash') . ' WHERE id = ?')->execute([(int)($b['id'] ?? 0)]);
        audit_as($owner, 'trash_purge', $kind === 'word' ? 'מילה' : 'נושא');
    } else {
        throw new ApiError('בקשה לא תקינה');
    }
    return trash_list();
}

function file_in_versions(string $id): bool
{
    $st = db()->prepare("SELECT 1 FROM versions WHERE json LIKE ? OR words LIKE ? OR assets LIKE ? LIMIT 1");
    $like = '%' . $id . '%';
    $st->execute([$like, $like, $like]);
    if ($st->fetchColumn()) return true;
    // ובגרסאות — לפי sha (קובץ שנשלח לאתר)
    $sha = db()->prepare('SELECT sha256 FROM files WHERE id = ?');
    $sha->execute([$id]);
    $s = (string)$sha->fetchColumn();
    if ($s === '') return false;
    $st->execute(['%' . $s . '%', '%' . $s . '%', '%' . $s . '%']);
    return (bool)$st->fetchColumn();
}

// ── גיבוי מלא ─────────────────────────────────────────────

/** ZIP אחד: המסד (כל הגרסאות, הנקודות, המילים, הקוד, ההיסטוריה) וכל הקבצים, עם הוראות שחזור. */
function backup_download(array $owner): never
{
    @set_time_limit(1800);
    $dir = files_dir() . '/tmp';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $db = $dir . '/backup-' . bin2hex(random_bytes(6)) . '.sqlite';
    db()->exec('VACUUM INTO ' . db()->quote($db));                     // עותק עקבי של המסד, גם בזמן עבודה
    $zipPath = $db . '.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFile($db, 'kol.sqlite');
    $root = files_dir();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($root) + 1);
        if (str_starts_with($rel, 'tmp/') || !$f->isFile()) continue;
        $zip->addFile($f->getPathname(), 'files/' . $rel);
        $zip->setCompressionName('files/' . $rel, ZipArchive::CM_STORE);
    }
    $zip->addFromString('README.txt', "גיבוי מלא של קול מרוקו — " . gmdate('Y-m-d H:i') . " UTC\n\n"
        . "kol.sqlite — המסד: הגרסאות, נקודות השחזור, המילים, הנושאים, קבצי הקוד, החברים וההיסטוריה.\n"
        . "files/ — כל הקבצים (תמונות, הקלטות, ייבוא).\n\n"
        . "שחזור: מעתיקים את kol.sqlite לתיקייה kol-maroko/data/ ואת התוכן של files/ ל-kol-maroko/data/files/ בשרת.\n");
    $zip->close();
    @unlink($db);
    setting_set('last_backup', now_iso());
    audit_as($owner, 'backup', size_label((int)filesize($zipPath)));
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="kol-maroko-backup-' . gmdate('Y-m-d') . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}
