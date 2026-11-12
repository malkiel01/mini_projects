<?php
/**
 * מאגר הפרויקט (פרוסה F2a; docs/spec-v2/02-project-model.md §3): עץ קבצים שמור בשרת, עם rev, היסטוריה וסל.
 *
 * ע1 — קיר, לא מסננת: **מה שנשמר הוא מה שנקרא, בייט בבייט.** אין כאן שום ניקוי, מיקוד או שכתוב של תוכן, ואין רשימת
 * שמות או סיומות. הבידוד מושג בהגשה (pf_raw): קובץ פרויקט לעולם אינו מוגש כמסמך במקור של הפלטפורמה (S4).
 *
 * איפה הנתונים: הרשומות — pf_files / pf_history ב-data/kol.sqlite (מיגרציה 13); התוכן — לפי sha256 תחת
 * data/files/_project/; המגבלות — בטבלת settings (pf_limit_*). מדיה — בספריית המדיה הקיימת, והעץ מחזיק הפניה.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/draft.php';

/** המגבלות: ברירת מחדל, מינימום, מקסימום, שם. הערך בפועל — הגדרה שהמפתח משנה (מסך המפתח ← מגבלות). */
const PF_LIMITS = [
    'path' => [200, 20, 1000, 'אורך נתיב (תווים)'],
    'text' => [2 * 1024 * 1024, 1024, 32 * 1024 * 1024, 'גודל קובץ (בייטים)'],
    'files' => [2000, 10, 100000, 'מספר קבצים בפרויקט'],
    'history' => [200, 5, 5000, 'גרסאות היסטוריה לקובץ'],
];
const PF_WHO_CHANGES = 'את המגבלה משנה המפתח: 🛠️ מפתח ← 📏 מגבלות';
const PF_AUTO_MERGE = 300;          // שמירות אוטומטיות רצופות של אותו עורך מתמזגות לשורת היסטוריה אחת

/** סוגי התוכן היחידים שמוגשים לתצוגה. כל סיומת אחרת — application/octet-stream כקובץ מצורף. */
const PF_INLINE_TYPES = [
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
    'ico' => 'image/x-icon', 'bmp' => 'image/bmp', 'svg' => 'image/svg+xml',
    'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav', 'weba' => 'audio/webm',
    'mp4' => 'video/mp4', 'webm' => 'video/webm',
];

// ── מגבלות ────────────────────────────────────────────────

function pf_limit(string $k): int
{
    return (int)(setting('pf_limit_' . $k) ?? PF_LIMITS[$k][0]);
}

function pf_limits(): array
{
    $out = [];
    foreach (PF_LIMITS as $k => [$def, $min, $max, $label]) $out[] = ['key' => $k, 'label' => $label, 'value' => pf_limit($k), 'default' => $def, 'min' => $min, 'max' => $max];
    return ['limits' => $out, 'used' => ['files' => pf_count()]];
}

function pf_limits_set(array $dev, array $b): array
{
    $k = (string)($b['key'] ?? '');
    if (!isset(PF_LIMITS[$k])) throw new ApiError('מגבלה לא מוכרת');
    [, $min, $max, $label] = PF_LIMITS[$k];
    $v = (int)($b['value'] ?? 0);
    if ($v < $min || $v > $max) throw new ApiError("$label: בין $min ל-$max");
    setting_set('pf_limit_' . $k, (string)$v);
    audit_as($dev, 'pf_limit', "$label ← $v");
    return pf_limits();
}

function pf_over(string $what, int $value, int $limit): never
{
    throw new ApiError("$what. המגבלה: $limit; כאן: $value. " . PF_WHO_CHANGES, 413, ['limit' => $limit, 'value' => $value]);
}

// ── נתיבים ────────────────────────────────────────────────

/** נתיב תקין או שגיאה. אין רשימת שמות ואין רשימת סיומות — רק מה שהופך נתיב לכתובת בטוחה. */
function pf_path(mixed $p): string
{
    if (!is_string($p) || $p === '') throw new ApiError('חסר נתיב');
    if (!mb_check_encoding($p, 'UTF-8')) throw new ApiError('הנתיב אינו טקסט תקין');
    if (str_contains($p, '\\')) throw new ApiError('נתיב לא יכול להכיל \\');
    if (preg_match('/\p{Cc}/u', $p)) throw new ApiError('נתיב לא יכול להכיל תו בקרה');
    if ($p[0] === '/') throw new ApiError('נתיב לא יכול להתחיל ב-/ (נתיב מוחלט)');
    foreach (explode('/', $p) as $seg) {
        if ($seg === '') throw new ApiError('בנתיב יש רכיב ריק (// או / בסוף)');
        if ($seg === '..' || $seg === '.') throw new ApiError('נתיב לא יכול להכיל . או ..');
    }
    $len = mb_strlen($p);
    if ($len > ($max = pf_limit('path'))) pf_over('הנתיב ארוך מדי', $len, $max);
    return $p;
}

function pf_key(string $path): string
{
    return mb_strtolower($path, 'UTF-8');
}

/** מותר ליצור כאן? רישיות, קובץ במקום תיקייה ותיקייה במקום קובץ. $skip — מזהים שממילא זזים (שינוי שם). */
function pf_free(string $path, array $skip = [], bool $dir = false): void
{
    $key = pf_key($path);
    $st = db()->prepare("SELECT id, path, kind FROM pf_files WHERE deleted_at IS NULL AND (path_key = ? OR path_key LIKE ? ESCAPE '!')");
    $st->execute([$key, pf_like($key) . '/%']);
    foreach ($st->fetchAll() as $r) {
        if (in_array((int)$r['id'], $skip, true)) continue;
        if ($r['path'] !== $path && pf_key($r['path']) === $key) throw new ApiError("כבר יש \"{$r['path']}\" — נתיב שנבדל רק באותיות גדולות / קטנות", 409);
        if ($r['path'] === $path) throw new ApiError("כבר יש \"$path\"", 409);
        if (!$dir) throw new ApiError("\"$path\" היא תיקייה", 409);
    }
    $parts = explode('/', $path);
    $st = db()->prepare("SELECT id, path FROM pf_files WHERE deleted_at IS NULL AND kind != 'dir' AND path_key = ?");
    $twin = db()->prepare("SELECT id, path FROM pf_files WHERE deleted_at IS NULL AND (path_key = ? OR path_key LIKE ? ESCAPE '!') AND substr(path, 1, ?) != ?");
    for ($i = 1; $i <= count($parts); $i++) {
        $pre = implode('/', array_slice($parts, 0, $i));
        // תיקייה (או הנתיב עצמו) שכבר קיימת באיות אחר של אותיות גדולות / קטנות
        $twin->execute([pf_key($pre), pf_like(pf_key($pre)) . '/%', mb_strlen($pre), $pre]);
        foreach ($twin->fetchAll() as $r) {
            if (!in_array((int)$r['id'], $skip, true)) throw new ApiError('כבר יש "' . mb_substr($r['path'], 0, mb_strlen($pre)) . '" — נתיב שנבדל רק באותיות גדולות / קטנות', 409);
        }
        if ($i === count($parts)) break;
        $st->execute([pf_key($pre)]);
        foreach ($st->fetchAll() as $r) if (!in_array((int)$r['id'], $skip, true)) throw new ApiError("\"{$r['path']}\" הוא קובץ, לא תיקייה", 409);
    }
}

function pf_like(string $s): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
}

// ── התוכן, לפי sha256 ─────────────────────────────────────

function pf_dir(): string
{
    return files_dir() . '/_project';
}

function pf_blob_path(string $sha): string
{
    return pf_dir() . '/' . substr($sha, 0, 2) . '/' . $sha;
}

function pf_blob_put(string $bytes): string
{
    $sha = hash('sha256', $bytes);
    $path = pf_blob_path($sha);
    if (is_file($path)) return $sha;
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new ApiError('אין גישה לתיקיית הפרויקט', 500);
    $tmp = $path . '.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $bytes) !== strlen($bytes) || !rename($tmp, $path)) { @unlink($tmp); throw new ApiError('הכתיבה לדיסק נכשלה', 500); }
    return $sha;
}

function pf_blob_get(string $sha): string
{
    $d = @file_get_contents(pf_blob_path($sha));
    if ($d === false) throw new ApiError('תוכן הקובץ חסר בשרת', 500);
    return $d;
}

// ── שורות ─────────────────────────────────────────────────

function pf_seeded(): bool
{
    return setting('pf_seeded') !== null;
}

function pf_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM pf_files WHERE deleted_at IS NULL AND kind = 'file'")->fetchColumn();
}

function pf_row(int $id, bool $live = true): array
{
    $st = db()->prepare('SELECT * FROM pf_files WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r || ($live && $r['deleted_at'] !== null)) throw new ApiError($r ? 'הקובץ בסל' : 'הקובץ לא נמצא', 404);
    return $r;
}

function pf_by_path(string $path): ?array
{
    $st = db()->prepare('SELECT * FROM pf_files WHERE deleted_at IS NULL AND path = ?');
    $st->execute([$path]);
    return $st->fetch() ?: null;
}

function pf_public(array $r): array
{
    $o = ['id' => (int)$r['id'], 'path' => $r['path'], 'kind' => $r['kind'], 'size' => (int)$r['size'], 'rev' => (int)$r['rev'], 'at' => $r['updated_at'], 'by' => $r['updated_name']];
    if ($r['kind'] === 'media') $o['media'] = $r['file_id'];
    return $o;
}

/** נעילת העריכה הקיימת חלה: כשמישהו אחר מחזיק אותה — לא כותבים. */
function pf_guard(array $user): void
{
    $l = lock_state();
    if ($l && $l['sub'] !== $user['sub']) throw new ApiError("העמוד בעריכה אצל {$l['name']}. אחרי שיסיים — שוב", 423);
}

function pf_hist(array $r, array $user, string $note): void
{
    $pdo = db();
    $id = (int)$r['id'];
    if ($note === 'auto') {             // שמירה אוטומטית רצופה — מעדכנת את השורה האחרונה במקום להציף את ההיסטוריה
        $st = $pdo->prepare('SELECT id, sub, at, note FROM pf_history WHERE file_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$id]);
        $last = $st->fetch();
        if ($last && $last['note'] === 'auto' && $last['sub'] === $user['sub'] && strtotime($last['at']) > time() - PF_AUTO_MERGE) {
            $pdo->prepare('UPDATE pf_history SET rev = ?, sha = ?, size = ?, path = ? WHERE id = ?')->execute([$r['rev'], $r['sha'], $r['size'], $r['path'], $last['id']]);
            return;
        }
    }
    $pdo->prepare('INSERT INTO pf_history (file_id, rev, path, sha, size, sub, name, at, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $r['rev'], $r['path'], $r['sha'], $r['size'], $user['sub'], $user['name'] ?? '', now_iso(), $note]);
    $pdo->prepare('DELETE FROM pf_history WHERE file_id = ? AND id NOT IN (SELECT id FROM pf_history WHERE file_id = ? ORDER BY id DESC LIMIT ?)')
        ->execute([$id, $id, pf_limit('history')]);
}

function pf_tx(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) return $fn();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $out = $fn();
        $pdo->exec('COMMIT');
        return $out;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/** הבייטים מהבקשה: content (טקסט) או content_b64 (כל דבר אחר). לא נוגעים בהם. */
function pf_bytes(array $b): string
{
    if (isset($b['content_b64'])) {
        $d = base64_decode((string)$b['content_b64'], true);
        if ($d === false) throw new ApiError('התוכן (base64) לא תקין');
        return $d;
    }
    $c = $b['content'] ?? '';
    if (!is_string($c)) throw new ApiError('התוכן חייב להיות טקסט');
    return $c;
}

function pf_insert(string $path, string $kind, string $bytes, array $user, ?string $fileId = null, string $note = 'נוצר'): array
{
    $now = now_iso();
    $sha = $kind === 'file' ? pf_blob_put($bytes) : '';
    $size = $kind === 'media' ? (int)file_row((string)$fileId)['size'] : strlen($bytes);
    db()->prepare('INSERT INTO pf_files (path, path_key, kind, sha, size, file_id, rev, created_by, created_at, updated_by, updated_name, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)')
        ->execute([$path, pf_key($path), $kind, $sha, $size, $fileId, $user['sub'], $now, $user['sub'], $user['name'] ?? '', $now]);
    $r = pf_row((int)db()->lastInsertId());
    if ($kind !== 'dir') pf_hist($r, $user, $note);
    return $r;
}

// ── קריאה ─────────────────────────────────────────────────

function pf_tree(array $user): array
{
    pf_seed($user);
    $rows = db()->query('SELECT * FROM pf_files WHERE deleted_at IS NULL ORDER BY path_key')->fetchAll();
    $media = [];
    $ids = array_values(array_filter(array_column($rows, 'file_id')));
    if ($ids) {
        $st = db()->prepare('SELECT id, name, type, kind, deleted_at FROM files WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        foreach ($st->fetchAll() as $f) $media[$f['id']] = ['type' => $f['type'], 'kind' => $f['kind'], 'missing' => $f['deleted_at'] !== null];
    }
    $files = [];
    foreach ($rows as $r) {
        $o = pf_public($r);
        if ($r['kind'] === 'media') $o['media_info'] = $media[$r['file_id']] ?? ['type' => '', 'kind' => '', 'missing' => true];
        $files[] = $o;
    }
    return [
        'files' => $files,
        'trash' => (int)db()->query('SELECT COUNT(*) FROM pf_files WHERE deleted_at IS NOT NULL')->fetchColumn(),
        'limits' => array_column(pf_limits()['limits'], 'value', 'key'),
        'kol' => pf_kol(),
        'lock' => lock_view($user),
        'can_purge' => $user['role'] === 'owner',
        'seed_only' => pf_seed_only(),
    ];
}

/** הפרויקט עדיין שלד הזריעה: index.html זהה בייט בבייט לזה שנוצר עם הפרויקט — העמוד של היום עוד לא יובא, ואיש לא ערך. */
function pf_seed_only(): bool
{
    $r = pf_by_path('index.html');
    return $r !== null && $r['kind'] === 'file' && pf_blob_get($r['sha']) === pf_seed_files()['index.html'];
}

/** תוכן לעורך — כנתונים (JSON), לא כמסמך. טקסט UTF-8 ב-content; כל דבר אחר ב-b64. */
function pf_read(array $q): array
{
    $r = pf_row((int)($q['id'] ?? 0), empty($q['trash']));
    if ($r['kind'] !== 'file') return ['file' => pf_public($r)];
    return ['file' => pf_public($r)] + pf_payload(pf_blob_get($r['sha']));
}

function pf_payload(string $bytes): array
{
    return mb_check_encoding($bytes, 'UTF-8') && !str_contains($bytes, "\0") ? ['content' => $bytes] : ['b64' => base64_encode($bytes), 'binary' => true];
}

/**
 * הבייטים עצמם (הורדה, תצוגת תמונה). S4: תמיד בארגז חול (CSP sandbox), nosniff, וסוג תוכן מרשימה סגורה; כל סיומת
 * אחרת — octet-stream כקובץ מצורף. גם אם הדפדפן פותח את הכתובת כמסמך, שום סקריפט אינו רץ במקור של הפלטפורמה.
 */
function pf_raw(array $q): never
{
    $r = pf_row((int)($q['id'] ?? 0), empty($q['trash']));
    if ($r['kind'] === 'dir') throw new ApiError('זו תיקייה', 400);
    if ($r['kind'] === 'media') file_send(file_row((string)$r['file_id']), isset($q['download']));     // אותן כותרות, ועם Range
    $name = basename($r['path']);
    $type = isset($q['download']) ? null : (PF_INLINE_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null);
    $path = pf_blob_path($r['sha']);
    if (!is_file($path)) throw new ApiError('תוכן הקובץ חסר בשרת', 404);
    header_remove('Content-Type');
    header('Content-Type: ' . ($type ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: sandbox; default-src 'none'");
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cache-Control: private, no-store');
    header('Content-Disposition: ' . ($type ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/** kol.json: ההגדרות התקפות. קובץ לא תקין — ממשיכים עם הגרסה התקינה האחרונה שבהיסטוריה (02 §5). */
function pf_kol(): array
{
    $r = pf_by_path('kol.json');
    if (!$r || $r['kind'] !== 'file') return ['valid' => false, 'error' => 'אין kol.json בשורש הפרויקט', 'config' => null, 'rev' => null];
    $try = function (string $sha): ?array {
        $c = json_decode(pf_blob_get($sha), true);
        return is_array($c) && !array_is_list($c) ? $c : null;
    };
    if (($c = $try($r['sha'])) !== null) return ['valid' => true, 'error' => null, 'config' => $c, 'rev' => (int)$r['rev']];
    json_decode(pf_blob_get($r['sha']));
    $err = json_last_error() ? json_last_error_msg() : 'kol.json חייב להיות אובייקט';
    $st = db()->prepare('SELECT rev, sha FROM pf_history WHERE file_id = ? ORDER BY id DESC');
    $st->execute([$r['id']]);
    foreach ($st->fetchAll() as $h) {
        if (is_file(pf_blob_path($h['sha'])) && ($c = $try($h['sha'])) !== null) return ['valid' => false, 'error' => $err, 'config' => $c, 'rev' => (int)$h['rev']];
    }
    return ['valid' => false, 'error' => $err, 'config' => null, 'rev' => null];
}

// ── כתיבה ─────────────────────────────────────────────────

function pf_create(array $user, array $b): array
{
    pf_seed($user);
    pf_guard($user);
    $path = pf_path($b['path'] ?? null);
    $bytes = pf_bytes($b);
    if (strlen($bytes) > ($max = pf_limit('text'))) pf_over('הקובץ גדול מדי', strlen($bytes), $max);
    $r = pf_tx(function () use ($path, $bytes, $user) {
        pf_free($path);
        if (($n = pf_count()) >= ($max = pf_limit('files'))) pf_over('יותר מדי קבצים בפרויקט', $n + 1, $max);
        return pf_insert($path, 'file', $bytes, $user);
    });
    audit_as($user, 'pf_create', $path);
    return ['file' => pf_public($r)];
}

function pf_mkdir(array $user, array $b): array
{
    pf_seed($user);
    pf_guard($user);
    $path = pf_path($b['path'] ?? null);
    $r = pf_tx(function () use ($path, $user) {
        pf_free($path, [], true);
        if (pf_children($path)) throw new ApiError("כבר יש \"$path\"", 409);
        return pf_insert($path, 'dir', '', $user);
    });
    audit_as($user, 'pf_mkdir', $path);
    return ['file' => pf_public($r)];
}

/** מדיה שכבר הועלתה לספרייה — נכנסת לעץ כהפניה (02 §4). הבייטים נשמרים פעם אחת, בספרייה. */
function pf_link(array $user, array $b): array
{
    pf_seed($user);
    pf_guard($user);
    $path = pf_path($b['path'] ?? null);
    $f = file_row((string)($b['file_id'] ?? ''));
    $r = pf_tx(function () use ($path, $f, $user) {
        pf_free($path);
        return pf_insert($path, 'media', '', $user, $f['id'], 'הועלה');
    });
    audit_as($user, 'pf_upload', $path);
    return ['file' => pf_public($r)];
}

/** שמירה. rev ישן — 409 עם הגרסה שבשרת, כדי להשוות; לא דריסה שקטה. */
function pf_write(array $user, array $b): array
{
    pf_guard($user);
    $bytes = pf_bytes($b);
    if (strlen($bytes) > ($max = pf_limit('text'))) pf_over('הקובץ גדול מדי', strlen($bytes), $max);
    $r = pf_tx(function () use ($b, $bytes, $user) {
        $r = pf_row((int)($b['id'] ?? 0));
        if ($r['kind'] !== 'file') throw new ApiError('אי אפשר לשמור תוכן כאן');
        if ((int)($b['rev'] ?? 0) !== (int)$r['rev']) {
            throw new ApiError('הקובץ השתנה בינתיים', 409, ['conflict' => ['file' => pf_public($r)] + pf_payload(pf_blob_get($r['sha']))]);
        }
        $sha = pf_blob_put($bytes);
        if ($sha === $r['sha']) return $r;                                  // אותו תוכן — אין גרסה חדשה
        db()->prepare('UPDATE pf_files SET sha = ?, size = ?, rev = rev + 1, updated_by = ?, updated_name = ?, updated_at = ? WHERE id = ?')
            ->execute([$sha, strlen($bytes), $user['sub'], $user['name'] ?? '', now_iso(), $r['id']]);
        $r = pf_row((int)$r['id']);
        pf_hist($r, $user, pf_tx_note($b) ?? (!empty($b['auto']) ? 'auto' : 'שמירה'));
        return $r;
    });
    $out = ['file' => pf_public($r)];
    if ($r['path'] === 'kol.json') $out['kol'] = pf_kol();
    return $out;
}

/**
 * שם העסקה (04 §2): פעולות עם שם שנעשו מאז השמירה הקודמת ("מחיקת h2.sub") — נשמרות כהערת הגרסה, במקום "auto" / "שמירה".
 * גרסה עם שם אינה מתמזגת עם שמירות אוטומטיות שאחריה או שלפניה. טקסט בלבד, מקוצר.
 */
function pf_tx_note(array $b): ?string
{
    $tx = $b['tx'] ?? null;
    if (is_string($tx)) $tx = [$tx];
    if (!is_array($tx)) return null;
    $labels = [];
    foreach (array_slice($tx, 0, 8) as $t) {
        if (!is_string($t)) continue;
        $t = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $t) ?? '');
        if ($t !== '') $labels[] = mb_substr($t, 0, 80);
    }
    if (!$labels) return null;
    $extra = is_array($b['tx']) && count($b['tx']) > 8 ? ' ועוד ' . (count($b['tx']) - 8) : '';
    return mb_substr(implode(' · ', $labels) . $extra, 0, 240);
}

/** "גרסאות" של קובץ: היסטוריית השמירות שלו — גרסה, מתי, מי, ומה נעשה (שם העסקה). קריאה בלבד. */
function pf_versions(array $q): array
{
    $r = pf_row((int)($q['id'] ?? 0));
    $st = db()->prepare('SELECT rev, at, name, note, size FROM pf_history WHERE file_id = ? ORDER BY id DESC');   // ההיסטוריה כבר מוגבלת בשמירה (pf_hist)
    $st->execute([$r['id']]);
    return ['file' => pf_public($r), 'versions' => array_map(fn($h) => ['rev' => (int)$h['rev'], 'at' => $h['at'], 'by' => $h['name'], 'note' => $h['note'] === 'auto' ? 'שמירה אוטומטית' : $h['note'], 'size' => (int)$h['size']], $st->fetchAll())];
}

/** כל השורות החיות שמתחת לתיקייה (לא כולל שורת התיקייה עצמה). */
function pf_children(string $dir): array
{
    $st = db()->prepare("SELECT * FROM pf_files WHERE deleted_at IS NULL AND path LIKE ? ESCAPE '!' ORDER BY path");
    $st->execute([pf_like($dir) . '/%']);
    return $st->fetchAll();
}

/** היעד של פעולה על העץ: קובץ לפי id, או תיקייה לפי path (גם תיקייה שקיימת רק כי יש בה קבצים). */
function pf_target(array $b): array
{
    if (!empty($b['id'])) {
        $r = pf_row((int)$b['id']);
        return $r['kind'] === 'dir' ? [$r['path'], array_merge([$r], pf_children($r['path'])), true] : [$r['path'], [$r], false];
    }
    $path = pf_path($b['path'] ?? null);
    $own = pf_by_path($path);
    if ($own && $own['kind'] !== 'dir') return [$path, [$own], false];
    $rows = array_merge($own ? [$own] : [], pf_children($path));
    if (!$rows) throw new ApiError('לא נמצא', 404);
    return [$path, $rows, true];
}

function pf_rename(array $user, array $b): array
{
    pf_guard($user);
    $to = pf_path($b['to'] ?? null);
    $from = pf_tx(function () use ($b, $to, $user) {
        [$from, $rows, $dir] = pf_target($b);
        if ($from === $to) return $from;
        if ($dir && str_starts_with($to . '/', $from . '/')) throw new ApiError('אי אפשר להעביר תיקייה לתוך עצמה');
        $skip = array_map(fn($r) => (int)$r['id'], $rows);
        $st = db()->prepare('UPDATE pf_files SET path = ?, path_key = ?, updated_by = ?, updated_name = ?, updated_at = ? WHERE id = ?');
        foreach ($rows as $r) {
            $new = pf_path($to . substr($r['path'], strlen($from)));
            pf_free($new, $skip, $r['kind'] === 'dir');
            $st->execute([$new, pf_key($new), $user['sub'], $user['name'] ?? '', now_iso(), $r['id']]);
            if ($r['kind'] !== 'dir') pf_hist(['path' => $new] + $r, $user, "שינוי שם מ-{$r['path']}");
        }
        return $from;
    });
    audit_as($user, 'pf_rename', "$from ← $to");
    return ['renamed' => ['from' => $from, 'to' => $to]];
}

/** מחיקה = העברה לסל. תיקייה — כל מה שבתוכה, באותה קבוצה (משוחזרת יחד). */
function pf_delete(array $user, array $b): array
{
    pf_guard($user);
    [$path, $n] = pf_tx(function () use ($b, $user) {
        [$path, $rows] = pf_target($b);
        $batch = bin2hex(random_bytes(6));
        $st = db()->prepare('UPDATE pf_files SET deleted_at = ?, deleted_by = ?, deleted_name = ?, deleted_batch = ? WHERE id = ?');
        foreach ($rows as $r) $st->execute([now_iso(), $user['sub'], $user['name'] ?? '', $batch, $r['id']]);
        return [$path, count($rows)];
    });
    audit_as($user, 'pf_delete', $path . ($n > 1 ? " · $n פריטים" : ''));
    return ['deleted' => $n];
}

function pf_trash(): array
{
    $rows = db()->query('SELECT * FROM pf_files WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC, path_key')->fetchAll();
    return ['items' => array_map(fn($r) => pf_public($r) + ['deleted_at' => $r['deleted_at'], 'deleted_by' => $r['deleted_name'], 'batch' => $r['deleted_batch']], $rows)];
}

function pf_ids(array $b): array
{
    $ids = array_values(array_unique(array_map('intval', (array)($b['ids'] ?? []))));
    if (!$ids) throw new ApiError('לא נבחר דבר');
    return $ids;
}

/** שחזור מהסל: אותו קובץ (אותו id), אותו נתיב, אותה היסטוריה. נתיב שנתפס בינתיים — נדחה, ולא דורס. */
function pf_restore(array $user, array $b): array
{
    pf_guard($user);
    $ids = pf_ids($b);
    $paths = pf_tx(function () use ($ids, $user) {
        $rows = array_map(fn($id) => pf_row($id, false), $ids);
        usort($rows, fn($a, $b) => strcmp($a['path'], $b['path']));
        $paths = [];
        foreach ($rows as $r) {
            if ($r['deleted_at'] === null) continue;
            pf_free($r['path'], [], $r['kind'] === 'dir');
            db()->prepare('UPDATE pf_files SET deleted_at = NULL, deleted_by = NULL, deleted_name = NULL, deleted_batch = NULL WHERE id = ?')->execute([$r['id']]);
            $paths[] = $r['path'];
        }
        return $paths;
    });
    audit_as($user, 'pf_restore', implode(', ', array_slice($paths, 0, 5)) . (count($paths) > 5 ? ' ועוד ' . (count($paths) - 5) : ''));
    return ['restored' => $paths];
}

/** מחיקה סופית — רק מהסל, רק לבעלים, ורק עם confirm מפורש. התוכן נמחק מהדיסק אם שום קובץ אחר אינו מחזיק אותו. */
function pf_purge(array $owner, array $b): array
{
    if (($b['confirm'] ?? null) !== true) throw new ApiError('מחיקה סופית דורשת אישור מפורש');
    $ids = pf_ids($b);
    $paths = pf_tx(function () use ($ids) {
        $paths = [];
        foreach ($ids as $id) {
            $r = pf_row($id, false);
            if ($r['deleted_at'] === null) throw new ApiError("\"{$r['path']}\" אינו בסל. מחיקה סופית — רק מהסל");
            $st = db()->prepare('SELECT DISTINCT sha FROM pf_history WHERE file_id = ?');
            $st->execute([$id]);
            $shas = array_unique(array_merge(array_column($st->fetchAll(), 'sha'), [$r['sha']]));
            db()->prepare('DELETE FROM pf_history WHERE file_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM pf_files WHERE id = ?')->execute([$id]);
            $used = db()->prepare('SELECT (SELECT COUNT(*) FROM pf_files WHERE sha = ?) + (SELECT COUNT(*) FROM pf_history WHERE sha = ?)');
            foreach (array_filter($shas) as $sha) {
                $used->execute([$sha, $sha]);
                if (!(int)$used->fetchColumn()) @unlink(pf_blob_path($sha));
            }
            $paths[] = $r['path'];
        }
        return $paths;
    });
    audit_as($owner, 'pf_purge', implode(', ', array_slice($paths, 0, 5)) . (count($paths) > 5 ? ' ועוד ' . (count($paths) - 5) : ''));
    return ['purged' => count($paths)];
}

// ── נקודות שחזור (lib/restore.php) ────────────────────────

/** מצב העץ לנקודת שחזור: מה קיים, איפה, עם איזה תוכן, ומה בסל. rev אינו חלק מהמצב — הוא רק עולה. */
function pf_snap_state(): string
{
    $rows = db()->query('SELECT id, path, kind, sha, size, file_id, deleted_at, deleted_by, deleted_name, deleted_batch FROM pf_files ORDER BY id')->fetchAll();
    return (string)json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * החזרת העץ למצב של נקודה. קובץ שנוצר אחרי הנקודה עובר לסל (לא נמחק); קובץ שנמחק סופית ותוכנו כבר אינו בדיסק —
 * חוזר ברשימת החסרים. רץ בתוך הטרנזקציה של snapshot_apply.
 */
function pf_snap_apply(array $user, array $rows, string $note): array
{
    $pdo = db();
    $cur = [];
    foreach ($pdo->query('SELECT * FROM pf_files')->fetchAll() as $r) $cur[(int)$r['id']] = $r;
    $missing = [];
    $now = now_iso();
    $seen = [];
    foreach ($rows as $s) {
        $id = (int)$s['id'];
        $seen[$id] = true;
        if (!isset($cur[$id])) {
            if ($s['kind'] === 'file' && !is_file(pf_blob_path($s['sha']))) { if ($s['deleted_at'] === null) $missing[] = $s['path']; continue; }
            $pdo->prepare('INSERT INTO pf_files (id, path, path_key, kind, sha, size, file_id, rev, created_by, created_at, updated_by, updated_name, updated_at, deleted_at, deleted_by, deleted_name, deleted_batch)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $s['path'], pf_key($s['path']), $s['kind'], $s['sha'], $s['size'], $s['file_id'], $user['sub'], $now, $user['sub'], $user['name'] ?? '', $now,
                    $s['deleted_at'], $s['deleted_by'], $s['deleted_name'], $s['deleted_batch']]);
            if ($s['kind'] !== 'dir') pf_hist(pf_row($id, false), $user, $note);
            continue;
        }
        $c = $cur[$id];
        $changed = $c['sha'] !== $s['sha'] || $c['path'] !== $s['path'] || (string)$c['file_id'] !== (string)$s['file_id'];
        $pdo->prepare('UPDATE pf_files SET path = ?, path_key = ?, kind = ?, sha = ?, size = ?, file_id = ?, deleted_at = ?, deleted_by = ?, deleted_name = ?, deleted_batch = ?' .
            ($changed ? ', rev = rev + 1, updated_by = ?, updated_name = ?, updated_at = ?' : '') . ' WHERE id = ?')
            ->execute(array_merge([$s['path'], pf_key($s['path']), $s['kind'], $s['sha'], $s['size'], $s['file_id'], $s['deleted_at'], $s['deleted_by'], $s['deleted_name'], $s['deleted_batch']],
                $changed ? [$user['sub'], $user['name'] ?? '', $now] : [], [$id]));
        if ($changed && $s['kind'] !== 'dir') pf_hist(pf_row($id, false), $user, $note);
    }
    $st = $pdo->prepare('UPDATE pf_files SET deleted_at = ?, deleted_by = ?, deleted_name = ?, deleted_batch = ? WHERE id = ?');
    foreach ($cur as $id => $c) {
        if (!isset($seen[$id]) && $c['deleted_at'] === null) $st->execute([$now, $user['sub'], $note, 'snap', $id]);
    }
    return $missing;
}

// ── היום הראשון (פרוסה F2 §2) ─────────────────────────────

/** פעם אחת: שלד ברירת המחדל, ו-from-current/ — עותק כמו שהוא של הקוד שהכלי מחזיק היום. */
function pf_seed(array $user): void
{
    if (pf_seeded()) return;
    pf_tx(function () {
        if (pf_seeded()) return;
        $sys = ['sub' => 'system', 'name' => 'קול מרוקו'];
        foreach (pf_seed_files() as $path => $content) pf_insert($path, 'file', $content, $sys, null, 'נוצר עם הפרויקט');
        setting_set('pf_seeded', now_iso());
    });
}

function pf_seed_files(): array
{
    $kol = [
        'version' => 1, 'entry' => 'index.html', 'pages' => 'auto',
        'breakpoints' => [['id' => 'tablet', 'label' => 'טאבלט', 'max' => 1024, 'preview' => 820], ['id' => 'mobile', 'label' => 'טלפון', 'max' => 767, 'preview' => 390]],
        'styles' => ['tokens' => 'css/tokens.css', 'base' => 'css/base.css', 'page' => 'css/page.css'],
        'components' => 'components/',
    ];
    $files = [
        'index.html' => "<!doctype html>\n<html lang=\"he\" dir=\"rtl\">\n<head>\n  <meta charset=\"utf-8\">\n  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n  <title>קול מרוקו</title>\n"
            . "  <link rel=\"stylesheet\" href=\"css/tokens.css\">\n  <link rel=\"stylesheet\" href=\"css/base.css\">\n  <link rel=\"stylesheet\" href=\"css/page.css\">\n</head>\n<body>\n  <main class=\"page\">\n    <h1>קול מרוקו</h1>\n  </main>\n  <script src=\"js/page.js\"></script>\n</body>\n</html>\n",
        'css/tokens.css' => "/* משתנים: צבעים, גופנים, מידות */\n:root {\n  --color-text: #16192b;\n  --color-bg: #ffffff;\n  --color-accent: #a3322a;\n  --font-body: system-ui, sans-serif;\n  --page-width: 1100px;\n}\n",
        'css/base.css' => "/* סגנון לפי תגית */\nbody { margin: 0; font-family: var(--font-body); color: var(--color-text); background: var(--color-bg); line-height: 1.6; }\nh1, h2, h3 { line-height: 1.25; }\na { color: var(--color-accent); }\nimg { max-width: 100%; height: auto; }\n",
        'css/page.css' => "/* סגנון לפי מחלקה */\n.page { max-width: var(--page-width); margin: 0 auto; padding: 0 16px; }\n",
        'js/page.js' => "// ההתנהגות של העמוד\n",
        'kol.json' => json_encode($kol, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
    ];
    // from-current/: לכל קובץ שורת הערה ראשונה — מאיפה הגיע. אחריה התוכן כמו שהוא, בלי שום שינוי.
    $day = gmdate('Y-m-d');
    $html = fn(string $src, string $c) => "<!-- from-current · $day · מקור: $src -->\n" . $c;
    $code = fn(string $src, string $c) => "/* from-current · $day · מקור: $src */\n" . $c;
    $mine = function_exists('code_files') ? (code_files() ?? []) : [];
    $own = fn(string $p) => isset($mine[$p]) ? 'הקובץ שנערך בלשונית </> קוד (code_files)' : null;
    $css = function_exists('theme_lexicon_css') ? theme_lexicon_css() . "\n\n" . LEX_CSS_MARK . "\n" . LEX_TPL_SIZES : '';
    $base = '';
    try {
        $base = (string)(page_render(json_decode((string)draft_row()['json'], true))['css'] ?? '');
    } catch (Throwable) {
    }
    $d = 'from-current/';
    $files += [
        $d . 'lexicon.html' => $html($own('lexicon.html') ?? 'התבנית המובנית LEX_TPL (lib/code.php) — הקובץ עוד לא נוצר בלשונית הקוד', $mine['lexicon.html'] ?? (defined('LEX_TPL') ? LEX_TPL : '')),
        $d . 'lexicon.css' => $code($own('lexicon.css') ?? 'kadisha-assets/lexicon.css של ערכת הנושא, כפי שהגיע בייבוא, עם הגדלים מהפאנל', $mine['lexicon.css'] ?? $css),
        $d . 'lexicon.js' => $code($own('lexicon.js') ?? 'kadisha-assets/lexicon.js של ערכת הנושא, כפי שהגיע בייבוא (preview_js)', $mine['lexicon.js'] ?? (string)setting('preview_js', '')),
        $d . 'lexicon-template.html' => $html('התבנית המובנית של המילון — הקבוע LEX_TPL ב-lib/code.php', defined('LEX_TPL') ? LEX_TPL : ''),
        $d . 'theme-style.css' => $code('style.css של ערכת הנושא, מיום הייבוא (theme_style_css)', function_exists('theme_style_css') ? theme_style_css() : ''),
        $d . 'imported.css' => $code('CSS שיובא מהעמוד באתר (ההגדרה imported_css)', (string)setting('imported_css', '')),
        $d . 'preview.css' => $code('CSS לתצוגה בלבד בסטודיו (ההגדרה preview_css)', (string)setting('preview_css', '')),
        $d . 'page-base.css' => $code('ה-CSS הבסיסי שהכלי מייצר לעמוד (page_render ב-lib/page.php), לפי הטיוטה של היום', $base),
    ];
    return $files;
}
