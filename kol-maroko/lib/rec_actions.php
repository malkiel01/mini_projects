<?php
/**
 * הפעולות על הקלטה (פרוסה A3 · docs/spec-v2/slices/A3-audio-actions.md, 05-audio-library.md §3, §5, §6):
 * החלפה עם גרסאות, הוספה (אחת או רבות, עם התאמה למילה ומסך אישור), חיבור וניתוק, שינוי שם, מחיקה לסל ושחזור,
 * הורדה (אחת — הקובץ המקורי; רבות — ZIP בחלקים עם קובץ רשימה), כפולות, וניקוי גרסאות ישנות.
 *
 * הקשר מילה ↔ הקלטה הוא שדה audio של המילה (מזהה קובץ או כתובת באתר) — ממנו העמוד קורא, ולכן פעולה שמחברת,
 * מנתקת או מחליפה כותבת אותו. הרשומה ב-recordings היא ההקלטה עצמה: שם, משך, גרסאות, סל.
 *
 * ⚠️ אין כאן שום פנייה לאתר: לא curl, לא rec_http, לא כתובת חיצונית. שום פעולה כאן אינה נוגעת בהקלטה שבאתר —
 *    "חזור לגרסה שבאתר" רק מחזיר את הכתובת לשדה של המילה. (נבדק ב-tests/cases/86-rec-actions.php.)
 * ⚠️ אין המרה ואין הקטנה: הקובץ נשמר ויורד בדיוק כמו שהועלה (file_store אינו נוגע בשמע; ההורדה — הבייטים מהדיסק).
 * ⚠️ הרשאות (api.php): עורך ומעלה; מחיקה סופית וניקוי גרסאות — בעל העמוד.
 */

declare(strict_types=1);

require_once __DIR__ . '/recordings.php';
require_once __DIR__ . '/restore.php';

const REC_IDS_MAX = 5000;
const REC_NAME_MAX = 200;
const REC_ZIP_MAX = 0xFFFF0000;             // בלי ZIP64: ארכיון עד ~4GB
const REC_ZIP_STEP_BYTES = 192 << 20;       // כמה בייטים צעד אחד של בניית ה-ZIP מעתיק לכל היותר
const REC_ZIP_STEP_SECONDS = 4;
const REC_SITE_ONLY = 'ההקלטה עדיין באתר — הפעולה תיפתח אחרי ההבאה לכלי. "החלף" זמין כבר עכשיו';

// ── עזרים ─────────────────────────────────────────────────

function rec_get(int $id): array
{
    $st = rec_db()->prepare('SELECT * FROM recordings WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: throw new ApiError('ההקלטה לא נמצאה', 404);
}

/** ההקלטה במקום (מילה, שדה; בלי שדה — הראשי), אחרי סנכרון, או null. */
function rec_of_word(int $wid, ?string $field = null): ?array
{
    $st = rec_db()->prepare("SELECT * FROM recordings WHERE list = 'words' AND word_id = ? AND field = ?");
    $st->execute([$wid, $field ?? rec_primary()]);
    return $st->fetch() ?: null;
}

/** השדה מהבקשה: מפתח של שדה שמע; ריק — הראשי. */
function rec_field(mixed $v): string
{
    if ($v === null || $v === '') return rec_primary();
    if (!is_string($v) || !isset(rec_audio_fields()[$v])) throw new ApiError('שדה ההקלטה לא נמצא', 404);
    return $v;
}

/** שם השדה לתצוגה ("הקלטה בצרפתית"). שדה שנמחק — מהסל של השדות. */
function rec_field_label(string $key): string
{
    $f = rec_audio_fields()[$key] ?? null;
    if ($f) return $f['label'];
    foreach (db()->query('SELECT field FROM field_trash ORDER BY id DESC')->fetchAll(PDO::FETCH_COLUMN) as $j) {
        $row = json_decode((string)$j, true);
        if (($row['key'] ?? null) === $key) return (string)$row['label'];
    }
    return $key;
}

/** המקום ביומן הפעולות: words/12/audio_fr. */
function rec_slot_str(string $list, int $item, string $field): string
{
    return "$list/$item/$field";
}

/** הערך במקום: הראשי — words.audio (מזהה קובץ או כתובת באתר); מותאם — words.extra[מפתח] (מזהה קובץ); ריק — ''. */
function rec_slot_get(string $list, int $item, string $field): string
{
    if ($list !== 'words') throw new ApiError('רשימה לא מוכרת');
    $st = rec_db()->prepare('SELECT audio, extra FROM words WHERE id = ?');
    $st->execute([$item]);
    $w = $st->fetch() ?: throw new ApiError('המילה לא נמצאה', 404);
    if ($field === rec_primary()) return (string)$w['audio'];
    $x = json_decode((string)$w['extra'], true);
    $v = is_array($x) ? ($x[$field] ?? null) : null;
    return is_string($v) ? $v : '';
}

/**
 * כותב את המקום (במקום rec_word_audio של A3). הראשי — words.audio. מותאם — words.extra[מפתח] דרך המסלול של N2: הערך נבדק
 * לפי השדה (field_parse — kind=audio, הקובץ חי), ריק ⇒ המפתח נמחק (words_extra_write), ו-updated_at/by של המילה — כך ה-rev
 * של הקובץ הווירטואלי (words_rev, נגזר מהתוכן) משתנה. כתובת באתר — רק בשדה הראשי.
 */
function rec_slot_set(string $list, int $item, string $field, string $value, array $user): void
{
    if ($list !== 'words') throw new ApiError('רשימה לא מוכרת');
    $pdo = rec_db();
    if ($field === rec_primary()) {
        $pdo->prepare('UPDATE words SET audio = ?, updated_at = ?, updated_by = ? WHERE id = ?')->execute([$value, now_iso(), $user['name'], $item]);
        return;
    }
    $f = null;
    foreach (fields_all() as $x) if ($x['key'] === $field && $x['col'] === null && $x['type'] === 'audio') $f = $x;
    if (!$f) throw new ApiError('שדה ההקלטה לא נמצא', 404);
    $val = null;
    if ($value !== '') {
        if (!file_id($value)) throw new ApiError("\"{$f['label']}\": הקלטה שעדיין באתר יכולה להיות רק בשדה הראשי — הביאו אותה לכלי קודם");
        [$val, $err, $warn] = field_parse($f, $value);
        if ($err || $warn) throw new ApiError("{$f['label']}: " . ($err ?: 'הקובץ לא נמצא בספרייה'));
    }
    words_extra_write($field, [$item => $val]);
    $pdo->prepare('UPDATE words SET updated_at = ?, updated_by = ? WHERE id = ?')->execute([now_iso(), $user['name'], $item]);
}

function rec_word(int $wid): array
{
    $st = rec_db()->prepare('SELECT id, word, audio FROM words WHERE id = ?');
    $st->execute([$wid]);
    return $st->fetch() ?: throw new ApiError('המילה לא נמצאה', 404);
}

/** רשימת מזהים מהבקשה: מספרים חיוביים, בלי כפולים, עד REC_IDS_MAX. */
function rec_ids(mixed $v): array
{
    if (!is_array($v)) throw new ApiError('לא נבחרו הקלטות');
    $ids = array_values(array_unique(array_filter(array_map(fn($x) => is_int($x) || (is_string($x) && ctype_digit($x)) ? max(0, (int)$x) : 0, $v))));
    if (!$ids) throw new ApiError('לא נבחרו הקלטות');
    if (count($ids) > REC_IDS_MAX) throw new ApiError('יותר מדי הקלטות בבקשה אחת (עד ' . REC_IDS_MAX . ')');
    return $ids;
}

/** שם לתצוגה: טקסט אחד, בלי תווי בקרה ובלי כיווניות נסתרת, עד REC_NAME_MAX תווים. */
function rec_clean_name(mixed $v): string
{
    $s = is_scalar($v) ? (string)$v : '';
    $s = (string)preg_replace('/[\x00-\x1f\x7f\x{200e}\x{200f}\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', '', $s);
    return mb_substr(trim((string)preg_replace('/\s+/u', ' ', $s)), 0, REC_NAME_MAX);
}

/** קובץ שמע חי בספרייה. */
function rec_audio_file(mixed $id): array
{
    $f = file_row(file_id($id) ?: throw new ApiError('הקובץ לא נמצא', 404));
    if ($f['deleted_at']) throw new ApiError('הקובץ בסל');
    if ($f['kind'] !== 'audio') throw new ApiError('"' . $f['name'] . '" אינו קובץ שמע. סוגים: mp3, m4a / aac, ogg, opus, wav, webm, flac');
    return $f;
}

/** הפעולה דורשת קובץ בכלי (לא הקלטה שעדיין רק כתובת באתר). */
function rec_need_file(array $r): void
{
    if ($r['deleted_at']) throw new ApiError('ההקלטה בסל');
    if ($r['state'] !== 'tool' || !$r['file_id']) throw new ApiError(REC_SITE_ONLY);
}

/** עסקה אחת. rec_sync — לפני (הוא פותח עסקה משלו). */
function rec_tx(callable $fn): mixed
{
    $pdo = rec_db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $out = $fn($pdo);
        $pdo->exec('COMMIT');
        return $out;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/**
 * הקלטה מקבלת קובץ חדש (או חוזרת לכתובת שבאתר — $fid null): הנוכחית נשמרת כגרסה, והמילה — אם מחוברת —
 * מצביעה על החדש. הנתיב בעץ הפרויקט (media/audio/<מזהה>) נשאר, ורק מצביע על הקובץ החדש.
 */
function rec_set_file(array $r, ?string $fid, string $how, array $user, ?array $from = null): void
{
    if ($r['file_id'] === $fid && $fid !== null) return;
    rec_version_push($r, $user['name']);
    $source = $fid === null ? (string)($from['source'] ?? $r['source']) : $r['source'];
    rec_db()->prepare("UPDATE recordings SET file_id = ?, state = ?, source = ?, how = ?, at = ?, by_name = ?, duration = ?, f_code = '', f_note = '' WHERE id = ?")
        ->execute([$fid, $fid ? 'tool' : 'site', $source, $how, now_iso(), $user['name'], $from['duration'] ?? null, $r['id']]);
    if ($r['word_id']) {
        rec_slot_set($r['list'], (int)$r['word_id'], $r['field'], $fid ?? $source, $user);
        if ($fid) rec_tree_point((int)$r['word_id'], $fid, $user, $r['field']);
    }
}

/**
 * הנתיב היציב בעץ: media/audio/<מזהה>.<סיומת> (שדה מותאם — <מזהה>-<מפתח>, rec_tree_path) — קיים? מצביע על הקובץ החדש
 * (השם נשאר). אין? נוצר (A2).
 */
function rec_tree_point(int $wid, string $fid, array $user, string $field = ''): void
{
    try {
        if (!pf_seeded()) return;
        $base = rec_tree_path($wid, $field, '');
        $st = db()->prepare("SELECT * FROM pf_files WHERE deleted_at IS NULL AND kind = 'media' AND (path LIKE ? ESCAPE '!' OR path = ?)");
        $st->execute([pf_like($base) . '.%', $base]);
        $node = $st->fetch();
        if (!$node) { rec_tree_link($wid, $fid, $user, $field); return; }
        if ($node['file_id'] === $fid) return;
        db()->prepare('UPDATE pf_files SET file_id = ?, size = ?, rev = rev + 1, updated_by = ?, updated_name = ?, updated_at = ? WHERE id = ?')
            ->execute([$fid, (int)file_row($fid)['size'], $user['sub'], $user['name'] ?? '', now_iso(), $node['id']]);
        pf_hist(pf_row((int)$node['id']), $user, 'ההקלטה הוחלפה');
    } catch (Throwable $e) {
        devlog_write('warn', 'audio', 'tree_point', ['message' => 'ההקלטה עודכנה, אך לא בעץ הפרויקט: ' . $e->getMessage(), 'user' => $user, 'data' => ['word_id' => $wid, 'field' => $field]]);
    }
}

/** קובץ שאף אחד כבר אינו מפנה אליו — נמחק סופית (מהמסד ומהדיסק). מחזיר כמה בייטים התפנו. */
function rec_file_drop(string $fid): int
{
    $pdo = rec_db();
    foreach (['SELECT 1 FROM recordings WHERE file_id = ?', 'SELECT 1 FROM rec_versions WHERE file_id = ?', 'SELECT 1 FROM words WHERE audio = ?',
                 'SELECT 1 FROM pf_files WHERE file_id = ?', 'SELECT 1 FROM words WHERE instr(extra, ?) > 0'] as $q) {     // N2b: גם שדה מותאם
        $st = $pdo->prepare($q . ' LIMIT 1');
        $st->execute([$fid]);
        if ($st->fetchColumn()) return 0;
    }
    if (isset(files_in_use()[$fid]) || file_in_versions($fid)) return 0;
    $st = $pdo->prepare('SELECT * FROM files WHERE id = ?');
    $st->execute([$fid]);
    if (!($f = $st->fetch())) return 0;
    $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$fid]);
    @unlink(file_path($f));
    return (int)$f['size'];
}

// ── החלפה וגרסאות ─────────────────────────────────────────

/** "החלף" מהספרייה: קובץ שכבר הועלה (או הוקלט) → ההקלטה של המילה / ההקלטה הלא מחוברת. גם להקלטה שעדיין באתר. */
function rec_replace(array $user, array $b): array
{
    $f = rec_audio_file($b['file_id'] ?? '');
    rec_sync();
    $rid = (int)($b['rec_id'] ?? 0);
    $wid = (int)($b['word_id'] ?? 0);
    $field = rec_field($b['field'] ?? null);          // N2b: המקום — מילה ושדה
    rec_tx(function () use ($f, $rid, $wid, $field, $user) {
        $r = $rid ? rec_get($rid) : ($wid ? rec_of_word($wid, $field) : throw new ApiError('לא נבחרה הקלטה'));
        if ($r && $r['deleted_at']) throw new ApiError('ההקלטה בסל — שחזרו אותה קודם');
        if ($r) {
            rec_set_file($r, $f['id'], 'replaced', $user);
        } else {                                   // מקום ריק — הקלטה ראשונה
            rec_word($wid);
            rec_slot_set('words', $wid, $field, $f['id'], $user);
        }
    });
    rec_sync();
    $r = $rid ? rec_get($rid) : rec_of_word($wid, $field);
    if ($r && $r['word_id']) rec_tree_point((int)$r['word_id'], $f['id'], $user, $r['field']);
    audit_as($user, 'rec_replace', ($r && $r['word_id'] ? '#' . $r['word_id'] . ' · ' . rec_slot_str('words', (int)$r['word_id'], $r['field']) : 'לא מחוברת · ' . ($r['name'] ?? '')) . ' ← ' . $f['name']);
    return ['rec_id' => (int)($r['id'] ?? 0)];
}

function rec_versions(array $q): array
{
    rec_sync();
    $r = rec_get((int)($q['rec_id'] ?? 0));
    $st = rec_db()->prepare('SELECT * FROM rec_versions WHERE rec_id = ? ORDER BY id DESC');
    $st->execute([$r['id']]);
    $rows = $st->fetchAll();
    $files = files_meta(array_values(array_filter(array_column($rows, 'file_id'))));
    $out = [];
    foreach ($rows as $v) {
        $f = $v['file_id'] ? ($files[$v['file_id']] ?? null) : null;
        $out[] = ['id' => (int)$v['id'], 'name' => $v['name'], 'how' => $v['how'], 'since' => $v['since'], 'at' => $v['at'], 'by' => $v['by_name'],
            'duration' => $v['duration'] === null ? null : (float)$v['duration'], 'site' => !$v['file_id'], 'source' => $v['source'],
            'file' => $f ? ['id' => $f['id'], 'name' => $f['name'], 'size' => (int)$f['size'], 'url' => media_url($f['id'])] : null,
            'gone' => $v['file_id'] && !$f];
    }
    return ['rec_id' => (int)$r['id'], 'versions' => $out];
}

/** "חזור לגרסה הזו": הנוכחית נשמרת כגרסה, והגרסה שנבחרה נעשית הנוכחית. */
function rec_revert(array $user, array $b): array
{
    rec_sync();
    $out = rec_tx(function ($pdo) use ($b, $user) {
        $r = rec_get((int)($b['rec_id'] ?? 0));
        if ($r['deleted_at']) throw new ApiError('ההקלטה בסל — שחזרו אותה קודם');
        $st = $pdo->prepare('SELECT * FROM rec_versions WHERE id = ? AND rec_id = ?');
        $st->execute([(int)($b['version_id'] ?? 0), $r['id']]);
        $v = $st->fetch() ?: throw new ApiError('הגרסה לא נמצאה', 404);
        if ($v['file_id']) {
            $fs = files_meta([$v['file_id']]);
            if (!isset($fs[$v['file_id']])) throw new ApiError('הקובץ של הגרסה הזו כבר נמחק', 410);
            if ($fs[$v['file_id']]['deleted_at']) $pdo->prepare('UPDATE files SET deleted_at = NULL, deleted_by = NULL WHERE id = ?')->execute([$v['file_id']]);
        } elseif ($v['source'] === '') {
            throw new ApiError('לגרסה הזו אין קובץ');
        }
        $pdo->prepare('DELETE FROM rec_versions WHERE id = ?')->execute([$v['id']]);
        rec_set_file($r, $v['file_id'] ?: null, 'restored', $user, $v);
        return $r;
    });
    rec_sync();
    audit_as($user, 'rec_revert', ($out['word_id'] ? '#' . $out['word_id'] . ' · ' . rec_slot_str($out['list'], (int)$out['word_id'], $out['field']) : $out['name']) . ' · גרסה ' . (int)$b['version_id']);
    return ['rec_id' => (int)$out['id']];
}

// ── שם, חיבור, ניתוק ──────────────────────────────────────

function rec_rename(array $user, array $b): array
{
    rec_sync();
    $r = rec_get((int)($b['rec_id'] ?? 0));
    rec_need_file($r);
    $name = rec_clean_name($b['name'] ?? '');
    if ($name === '') throw new ApiError('השם ריק');
    rec_db()->prepare('UPDATE recordings SET name = ? WHERE id = ?')->execute([$name, $r['id']]);
    audit_as($user, 'rec_rename', "{$r['name']} → $name");
    return ['rec_id' => (int)$r['id'], 'name' => $name];
}

/**
 * "חבר ל…": ההקלטה עוברת למקום (מילה + שדה; בלי שדה — הראשי). המקום הקודם שלה נשאר ריק. במקום היעד כבר יש הקלטה? 409
 * עם פרטיה, ובאישור (replace) — ההקלטה הקודמת שלו נשארת בספרייה כ"לא מחוברת" (לא נמחקת). $act — rec_connect / rec_move.
 */
function rec_connect(array $user, array $b, string $act = 'rec_connect'): array
{
    rec_sync();
    $out = rec_tx(function ($pdo) use ($b, $user) {
        $r = rec_get((int)($b['rec_id'] ?? 0));
        if ($r['deleted_at']) throw new ApiError('ההקלטה בסל — שחזרו אותה קודם');
        $w = rec_word((int)($b['word_id'] ?? 0));
        $to = (int)$w['id'];
        $field = rec_field($b['field'] ?? null);
        $label = rec_field_label($field);
        if ((int)$r['word_id'] === $to && $r['field'] === $field && $r['list'] === 'words') return ['r' => $r, 'w' => $w, 'field' => $field, 'moved' => null];
        if ($field !== rec_primary() && !$r['file_id']) throw new ApiError("\"$label\": הקלטה שעדיין באתר יכולה להיות רק בשדה הראשי — הביאו אותה לכלי קודם");
        $other = rec_of_word($to, $field);
        if ($other && ($b['replace'] ?? false) !== true) {
            throw new ApiError(($field === rec_primary() ? "למילה \"{$w['word']}\" כבר יש הקלטה" : "למילה \"{$w['word']}\" כבר יש $label"), 409,
                ['conflict' => ['word_id' => $to, 'word' => $w['word'], 'field' => $field, 'field_label' => $label, 'rec_id' => (int)$other['id'], 'name' => $other['name'], 'state' => $other['state']]]);
        }
        if ($other) $pdo->prepare('UPDATE recordings SET word_id = NULL WHERE id = ?')->execute([$other['id']]);
        if ($r['word_id']) rec_slot_set($r['list'], (int)$r['word_id'], $r['field'], '', $user);
        $pdo->prepare("UPDATE recordings SET list = 'words', word_id = ?, field = ? WHERE id = ?")->execute([$to, $field, $r['id']]);
        rec_slot_set('words', $to, $field, $r['file_id'] ?: $r['source'], $user);
        return ['r' => $r, 'w' => $w, 'field' => $field, 'moved' => $other];
    });
    rec_sync();
    if ($out['r']['file_id']) rec_tree_point((int)$out['w']['id'], $out['r']['file_id'], $user, $out['field']);
    $from = $out['r']['word_id'] ? rec_slot_str($out['r']['list'], (int)$out['r']['word_id'], $out['r']['field']) . ' ' : '';
    audit_as($user, $act, "{$out['r']['name']} · {$from}→ " . rec_slot_str('words', (int)$out['w']['id'], $out['field']) . " {$out['w']['word']}" . ($out['moved'] ? " (הקודמת: {$out['moved']['name']} — לא מחוברת)" : ''));
    return ['rec_id' => (int)$out['r']['id'], 'word_id' => (int)$out['w']['id'], 'field' => $out['field']];
}

/** "העבר לשדה…": אותה מילה, שדה אחר (למשל הקלטה שהועלתה לראשי בטעות). שם, משך וגרסאות נשמרים — זו אותה רשומה. */
function rec_move(array $user, array $b): array
{
    rec_sync();
    $r = rec_get((int)($b['rec_id'] ?? 0));
    if (!$r['word_id'] || $r['deleted_at']) throw new ApiError('ההקלטה אינה מחוברת למילה — "חבר ל…"');
    return rec_connect($user, ['rec_id' => (int)$r['id'], 'word_id' => (int)$r['word_id'], 'field' => $b['field'] ?? null, 'replace' => ($b['replace'] ?? false) === true], 'rec_move');
}

/** "נתק": המילה נשארת, "בלי הקלטה"; ההקלטה נשארת בספרייה תחת "לא מחוברות". */
function rec_disconnect(array $user, array $b): array
{
    $ids = rec_ids($b['ids'] ?? null);
    rec_sync();
    if (count($ids) > 1) snapshot_create($user, 'לפני ניתוק ' . count($ids) . ' הקלטות מהמילים');
    $n = rec_tx(function ($pdo) use ($ids, $user) {
        $n = 0;
        foreach ($ids as $id) {
            $st = $pdo->prepare('SELECT * FROM recordings WHERE id = ?');
            $st->execute([$id]);
            if (!($r = $st->fetch()) || !$r['word_id']) continue;      // נמחקה בינתיים — מדלגים
            rec_need_file($r);
            $pdo->prepare('UPDATE recordings SET word_id = NULL WHERE id = ?')->execute([$r['id']]);
            rec_slot_set($r['list'], (int)$r['word_id'], $r['field'], '', $user);
            $n++;
        }
        return $n;
    });
    rec_sync();
    audit_as($user, 'rec_disconnect', "$n הקלטות" . (count($ids) === 1 && $n ? ' · ' . rec_get($ids[0])['name'] : ''));
    return ['done' => $n];
}

// ── סל ────────────────────────────────────────────────────

/** מחיקה — לסל. ההקלטה, הקובץ והגרסאות נשארים; המילה (אם הייתה) נשארת "בלי הקלטה", ונזכרת לשחזור. */
function rec_delete(array $user, array $b): array
{
    $ids = rec_ids($b['ids'] ?? null);
    rec_sync();
    if (count($ids) > 1) snapshot_create($user, 'לפני מחיקת ' . count($ids) . ' הקלטות לסל');
    $n = rec_tx(function ($pdo) use ($ids, $user) {
        $n = 0;
        foreach ($ids as $id) {
            $st = $pdo->prepare('SELECT * FROM recordings WHERE id = ?');
            $st->execute([$id]);
            if (!($r = $st->fetch()) || $r['deleted_at']) continue;
            rec_need_file($r);
            $audio = '';
            if ($r['word_id']) {                    // N2b: המקום נזכר — רשימה, פריט, שדה — והשחזור חוזר אליו
                $audio = rec_slot_get($r['list'], (int)$r['word_id'], $r['field']);
                rec_slot_set($r['list'], (int)$r['word_id'], $r['field'], '', $user);
            }
            $pdo->prepare('UPDATE recordings SET deleted_at = ?, deleted_by = ?, deleted_list = ?, deleted_word = ?, deleted_field = ?, deleted_audio = ?, word_id = NULL WHERE id = ?')
                ->execute([now_iso(), $user['name'], $r['word_id'] ? $r['list'] : null, $r['word_id'], $r['word_id'] ? $r['field'] : null, $audio, $r['id']]);
            $n++;
        }
        return $n;
    });
    rec_sync();
    audit_as($user, 'rec_delete', "$n הקלטות → לסל" . (count($ids) === 1 && $n ? ' · ' . rec_get($ids[0])['name'] : ''));
    return ['done' => $n];
}

function rec_trash(): array
{
    $rows = rec_db()->query('SELECT r.*, w.word AS word FROM recordings r LEFT JOIN words w ON w.id = r.deleted_word WHERE r.deleted_at IS NOT NULL ORDER BY r.deleted_at DESC')->fetchAll();
    $files = files_meta(array_values(array_filter(array_column($rows, 'file_id'))));
    $primary = rec_primary();
    return array_map(fn($r) => ['rec' => (int)$r['id'], 'name' => $r['name'], 'word_id' => $r['deleted_word'] === null ? null : (int)$r['deleted_word'],
        'word' => $r['word'], 'at' => $r['deleted_at'], 'by' => $r['deleted_by'],
        'field' => $r['deleted_field'] ?? $r['field'], 'field_label' => rec_field_label((string)($r['deleted_field'] ?? $r['field'])),
        'primary' => ($r['deleted_field'] ?? $r['field']) === $primary,
        'file' => isset($files[$r['file_id']]) ? ['id' => $r['file_id'], 'name' => $files[$r['file_id']]['name'], 'size' => (int)$files[$r['file_id']]['size'], 'url' => media_url($r['file_id'])] : null], $rows);
}

/** שחזור מהסל — גם החיבור למילה. המילה נמחקה, או שיש לה בינתיים הקלטה אחרת? חוזרת כ"לא מחוברת", והתשובה אומרת למה. */
function rec_restore(array $user, array $b): array
{
    rec_sync();
    $out = rec_tx(function ($pdo) use ($b, $user) {
        $r = rec_get((int)($b['id'] ?? 0));
        if (!$r['deleted_at']) throw new ApiError('ההקלטה אינה בסל');
        $note = '';
        $wid = $r['deleted_word'] === null ? 0 : (int)$r['deleted_word'];
        $field = (string)($r['deleted_field'] ?? $r['field']);      // N2b: חוזרת לאותו מקום (לפני N2b — הראשי)
        $list = (string)($r['deleted_list'] ?? 'words');
        if ($wid) {
            $st = $pdo->prepare('SELECT id, word FROM words WHERE id = ?');
            $st->execute([$wid]);
            $w = $st->fetch();
            $label = rec_field_label($field);
            if (!$w) { $note = 'המילה כבר לא קיימת — ההקלטה חזרה כ"לא מחוברת"'; $wid = 0; }
            elseif (!isset(rec_audio_fields()[$field])) { $note = "השדה \"$label\" כבר לא קיים — ההקלטה חזרה כ\"לא מחוברת\""; $wid = 0; }
            elseif ($field !== rec_primary() && !$r['file_id']) { $note = 'ההקלטה חזרה כ"לא מחוברת"'; $wid = 0; }
            elseif (rec_slot_get($list, $wid, $field) !== '' || rec_of_word($wid, $field)) {
                $note = ($field === rec_primary() ? "למילה \"{$w['word']}\" יש בינתיים הקלטה אחרת" : "למילה \"{$w['word']}\" יש בינתיים $label אחרת") . ' — ההקלטה חזרה כ"לא מחוברת"';
                $wid = 0;
            }
        }
        $pdo->prepare("UPDATE recordings SET deleted_at = NULL, deleted_by = '', deleted_list = NULL, deleted_word = NULL, deleted_field = NULL, deleted_audio = '', list = ?, field = ?, word_id = ? WHERE id = ?")
            ->execute([$list, $field, $wid ?: null, $r['id']]);
        if ($wid) rec_slot_set($list, $wid, $field, $r['deleted_audio'] !== '' ? $r['deleted_audio'] : (string)$r['file_id'], $user);
        return ['r' => $r, 'wid' => $wid, 'field' => $field, 'note' => $note];
    });
    rec_sync();
    audit_as($user, 'rec_restore', $out['r']['name'] . ($out['wid'] ? ' → ' . rec_slot_str('words', $out['wid'], $out['field']) : ' (לא מחוברת)'));
    return ['rec_id' => (int)$out['r']['id'], 'word_id' => $out['wid'] ?: null, 'field' => $out['field'], 'note' => $out['note']];
}

/** מחיקה סופית — רק בעל העמוד, רק מהסל, באישור מפורש. הקבצים נמחקים רק אם אף דבר אחר אינו מפנה אליהם. */
function rec_purge(array $owner, array $b): array
{
    if (($b['confirm'] ?? null) !== true) throw new ApiError('מחיקה סופית דורשת אישור מפורש');
    $r = rec_get((int)($b['id'] ?? 0));
    if (!$r['deleted_at']) throw new ApiError('אפשר למחוק סופית רק מהסל');
    $fids = rec_tx(function ($pdo) use ($r) {
        $st = $pdo->prepare('SELECT file_id FROM rec_versions WHERE rec_id = ? AND file_id IS NOT NULL');
        $st->execute([$r['id']]);
        $fids = array_unique(array_filter([$r['file_id'], ...$st->fetchAll(PDO::FETCH_COLUMN)]));
        $pdo->prepare('DELETE FROM rec_versions WHERE rec_id = ?')->execute([$r['id']]);
        $pdo->prepare('DELETE FROM recordings WHERE id = ?')->execute([$r['id']]);
        return $fids;
    });
    $freed = 0;
    foreach ($fids as $fid) $freed += rec_file_drop($fid);
    audit_as($owner, 'rec_purge', $r['name'] . ' · ' . round($freed / 1048576, 2) . 'MB');
    return ['freed' => $freed];
}

// ── גרסאות: המקום שהן תופסות, וניקוי ──────────────────────

function rec_versions_usage(): array
{
    $row = rec_db()->query('SELECT COUNT(*) AS n, COALESCE(SUM(f.size), 0) AS bytes, MIN(v.at) AS oldest FROM rec_versions v
        JOIN files f ON f.id = v.file_id WHERE v.file_id IS NOT NULL')->fetch();
    return ['count' => (int)$row['n'], 'bytes' => (int)$row['bytes'], 'oldest' => $row['oldest']];
}

/** "נקה גרסאות ישנות מ-…" — בעל העמוד, באישור. גרסה שבאתר (בלי קובץ) אינה תופסת מקום ונשארת. */
function rec_versions_clean(array $owner, array $b): array
{
    if (($b['confirm'] ?? null) !== true) throw new ApiError('ניקוי גרסאות דורש אישור מפורש');
    $before = (string)($b['before'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $before) || !strtotime($before)) throw new ApiError('תאריך לא תקין');
    $iso = gmdate('Y-m-d\TH:i:s', strtotime($before . ' 00:00:00 UTC'));
    $fids = rec_tx(function ($pdo) use ($iso) {
        $st = $pdo->prepare('SELECT id, file_id FROM rec_versions WHERE file_id IS NOT NULL AND at < ?');
        $st->execute([$iso]);
        $rows = $st->fetchAll();
        $del = $pdo->prepare('DELETE FROM rec_versions WHERE id = ?');
        foreach ($rows as $v) $del->execute([$v['id']]);
        return array_unique(array_column($rows, 'file_id'));
    });
    $freed = 0;
    foreach ($fids as $fid) $freed += rec_file_drop($fid);
    audit_as($owner, 'rec_versions_clean', "לפני $before · " . count($fids) . ' קבצים · ' . round($freed / 1048576, 2) . 'MB');
    return ['removed' => count($fids), 'freed' => $freed] + ['usage' => rec_versions_usage()];
}

// ── הוספה: התאמה לפי שם הקובץ, ואישור ─────────────────────

function rec_norm(string $s): string
{
    $s = (string)preg_replace('/[\x{0591}-\x{05C7}]/u', '', $s);
    return mb_strtolower(trim((string)preg_replace('/[\s_]+/u', ' ', $s)));
}

/** שם קצר של שדה שמע, לשם קובץ ולמונים: "הקלטה בצרפתית" → "בצרפתית". */
function rec_field_short(string $label): string
{
    return trim((string)preg_replace('/^הקלטה\s+/u', '', $label)) ?: $label;
}

/**
 * מסך האישור, לפני שבייט אחד עולה: לכל שם קובץ — המקום שהוא מתאים לו, והאם כבר יש בו הקלטה. שום דבר לא נכתב כאן.
 * המילה: המזהה ("6.mp3") או המילה עצמה. השדה (N2b): מה שנבחר במסך ההעלאה (field; ריק — הראשי); מפתח או שם של שדה
 * שמע אחרי מקף ("12-audio_fr.mp3", "בסטילה - בצרפתית.mp3") — השדה הזה, וגובר על הבחירה. ההתנגשות — לפי מקום.
 */
function rec_match(array $b): array
{
    $names = $b['names'] ?? null;
    if (!is_array($names) || !$names) throw new ApiError('לא נבחרו קבצים');
    if (count($names) > 2000) throw new ApiError('עד 2,000 קבצים בפעם אחת');
    rec_sync();
    $pdo = rec_db();
    $def = rec_field($b['field'] ?? null);
    $fields = rec_audio_fields();
    $suffix = [];
    foreach ($fields as $k => $f) foreach ([$k, $f['label'], rec_field_short($f['label'])] as $n) $suffix[rec_norm($n)] ??= $k;
    $byId = [];
    $byWord = [];
    foreach ($pdo->query('SELECT id, word FROM words')->fetchAll() as $w) {
        $byId[(int)$w['id']] = $w['word'];
        $byWord[rec_norm((string)$w['word'])][] = (int)$w['id'];
    }
    $recs = [];
    foreach ($pdo->query("SELECT id, word_id, field, name, state FROM recordings WHERE list = 'words' AND word_id IS NOT NULL")->fetchAll() as $r) $recs[(int)$r['word_id'] . '|' . $r['field']] = $r;
    // המילה מתוך שם (בלי סיומת): [מזהה|null, לפי מה, הערה]
    $word = function (string $stem) use ($byId, $byWord): array {
        if (ctype_digit($stem) && isset($byId[(int)$stem])) return [(int)$stem, 'id', ''];
        if ($stem !== '' && count($byWord[$stem] ?? []) === 1) return [$byWord[$stem][0], 'word', ''];
        if (count($byWord[$stem] ?? []) > 1) return [null, '', 'יש ' . count($byWord[$stem]) . ' מילים בשם הזה (' . implode(', ', array_map(fn($x) => "#$x", $byWord[$stem])) . ') — בחרו ידנית'];
        if (ctype_digit($stem)) return [null, '', "אין מילה שהמזהה שלה $stem"];
        return [null, '', ''];
    };
    $out = [];
    $claimed = [];
    foreach (array_values($names) as $i => $n) {
        $name = rec_clean_name(is_string($n) ? basename(str_replace('\\', '/', $n)) : '');
        $stem = rec_norm((string)preg_replace('/\.[a-z0-9]{1,5}$/i', '', $name));
        $field = $def;
        $byField = false;
        [$wid, $by, $note] = $word($stem);
        // "<מילה>-<שדה>": קודם השם כולו (מילה שיש בה מקף), ואחריו החלוקה במקף האחרון
        if (!$wid && preg_match('/^(.+?)\s*-\s*([^-]+)$/u', $stem, $m) && isset($suffix[rec_norm($m[2])])) {
            [$w2, $by2, $note2] = $word(rec_norm($m[1]));
            if ($w2 || $note2 !== '') { [$wid, $by, $note] = [$w2, $by2, $note2]; $field = $suffix[rec_norm($m[2])]; $byField = true; }
        }
        $item = ['i' => $i, 'name' => $name, 'word_id' => $wid, 'word' => $wid ? $byId[$wid] : null, 'by' => $by, 'conflict' => null, 'note' => $note,
            'field' => $field, 'field_label' => $fields[$field]['label'] ?? $field, 'by_field' => $byField];
        if ($wid) {
            $sk = "$wid|$field";
            if (isset($claimed[$sk])) {
                $item['note'] = 'עוד קובץ ברשימה מתאים לאותו מקום';
                $item['dup'] = $claimed[$sk];
            }
            $claimed[$sk] ??= $i;
            if (isset($recs[$sk])) $item['conflict'] = ['rec_id' => (int)$recs[$sk]['id'], 'name' => $recs[$sk]['name'], 'state' => $recs[$sk]['state']];
        }
        $out[] = $item;
    }
    return ['items' => $out, 'limits' => rec_limits()];
}

/** התקרות, כדי שהדפדפן יעצור לפני העלאה — ושההודעה תאמר מה המגבלה ומי משנה אותה. */
function rec_limits(): array
{
    return ['file_max' => min(FILE_MAX, rec_settings()['file_max']), 'free' => rec_free(), 'cap' => disk_cap()];
}

/** הודעת חריגה: מה המגבלה, ומי משנה אותה. */
function rec_limit_msg(string $kind, int $limit): string
{
    return $kind === 'file'
        ? 'גדול מהתקרה לקובץ (' . round($limit / 1048576) . 'MB). את התקרה קובע בעל העמוד — 🎙️ הקלטות ← הבאה מהאתר ← הגדרות'
        : 'אין מקום בתקרת הדיסק (' . round($limit / 1073741824, 1) . 'GB). בעל העמוד מגדיל אותה ב-🗂️ קבצים ← תקרת הקבצים, או מפנה מקום (סל, גרסאות ישנות)';
}

/**
 * אחרי האישור, קובץ אחר קובץ (כל אחד כבר הועלה): word_id — לחבר; בלי — הקלטה "לא מחוברת". למילה כבר יש הקלטה:
 * on_conflict = replace — ההקודמת נשמרת כגרסה; skip (או בלי) — הקובץ נשאר כהקלטה לא מחוברת.
 */
function rec_add(array $user, array $b): array
{
    $f = rec_audio_file($b['file_id'] ?? '');
    $max = rec_limits()['file_max'];
    if ((int)$f['size'] > $max) throw new ApiError('"' . $f['name'] . '" ' . rec_limit_msg('file', $max), 413);
    rec_sync();
    $wid = (int)($b['word_id'] ?? 0);
    $field = rec_field($b['field'] ?? null);          // N2b: המקום — מילה ושדה
    $mode = (string)($b['on_conflict'] ?? 'skip');
    $name = rec_clean_name($b['name'] ?? '') ?: rec_clean_name(preg_replace('/\.[a-z0-9]{1,5}$/i', '', $f['name']));
    $out = rec_tx(function ($pdo) use ($f, $wid, $field, $mode, $name, $user) {
        $st = $pdo->prepare('SELECT id FROM recordings WHERE file_id = ? LIMIT 1');
        $st->execute([$f['id']]);
        if ($st->fetchColumn()) throw new ApiError('הקובץ הזה כבר הקלטה בספרייה');
        if ($wid) {
            $w = rec_word($wid);
            $r = rec_of_word($wid, $field);
            if ($r && $mode === 'replace') {
                if ($r['deleted_at']) throw new ApiError('ההקלטה בסל');
                rec_set_file($r, $f['id'], 'uploaded', $user);
                return ['rec_id' => (int)$r['id'], 'word_id' => $wid, 'field' => $field, 'replaced' => true];
            }
            if (!$r) {
                $pdo->prepare("INSERT INTO recordings (list, word_id, field, state, file_id, name, how, at, by_name) VALUES ('words', ?, ?, 'tool', ?, ?, 'uploaded', ?, ?)")
                    ->execute([$wid, $field, $f['id'], $w['word'], now_iso(), $user['name']]);
                $rid = (int)$pdo->lastInsertId();
                rec_slot_set('words', $wid, $field, $f['id'], $user);
                return ['rec_id' => $rid, 'word_id' => $wid, 'field' => $field, 'replaced' => false];
            }
        }
        $pdo->prepare("INSERT INTO recordings (word_id, state, file_id, name, how, at, by_name) VALUES (NULL, 'tool', ?, ?, 'uploaded', ?, ?)")
            ->execute([$f['id'], $name ?: 'הקלטה', now_iso(), $user['name']]);
        return ['rec_id' => (int)$pdo->lastInsertId(), 'word_id' => null, 'field' => null, 'replaced' => false];
    });
    rec_sync();
    if ($out['word_id']) rec_tree_point($out['word_id'], $f['id'], $user, $out['field']);
    audit_as($user, 'rec_add', $f['name'] . ($out['word_id'] ? " → #{$out['word_id']} · " . rec_slot_str('words', $out['word_id'], $out['field']) . ($out['replaced'] ? ' (החליף; הקודמת — גרסה)' : '') : ' (לא מחוברת)'));
    return $out;
}

// ── כפולות ────────────────────────────────────────────────

/**
 * "השאר אחת" לקבוצה של אותו sha256: מילים שהשתמשו בעותק אחר מצביעות עכשיו על העותק שנשאר (אותו תוכן בדיוק —
 * העותק הקודם נשמר כגרסה), והקלטות לא מחוברות בקבוצה — לסל. הכול הפיך.
 */
function rec_dedupe(array $user, array $b): array
{
    $keep = (int)($b['keep'] ?? 0);
    rec_sync();
    $k = rec_get($keep);
    rec_need_file($k);
    $sha = (string)file_row($k['file_id'])['sha256'];
    $st = rec_db()->prepare("SELECT r.* FROM recordings r JOIN files f ON f.id = r.file_id WHERE f.sha256 = ? AND r.id != ? AND r.file_id != ? AND r.deleted_at IS NULL AND r.state = 'tool'");
    $st->execute([$sha, $k['id'], $k['file_id']]);
    $others = $st->fetchAll();
    if (!$others) throw new ApiError('אין כפולות להקלטה הזו');
    snapshot_create($user, 'לפני "השאר אחת" לכפולות של ' . $k['name']);
    $res = rec_tx(function ($pdo) use ($k, $others, $user) {
        $moved = 0;
        $trashed = 0;
        foreach ($others as $r) {
            if ($r['word_id']) {
                rec_set_file($r, $k['file_id'], 'deduped', $user);
                $moved++;
            } else {
                $pdo->prepare("UPDATE recordings SET deleted_at = ?, deleted_by = ?, deleted_word = NULL, deleted_audio = '' WHERE id = ?")->execute([now_iso(), $user['name'], $r['id']]);
                $trashed++;
            }
        }
        return ['moved' => $moved, 'trashed' => $trashed];
    });
    rec_sync();
    audit_as($user, 'rec_dedupe', "{$k['name']} · {$res['moved']} מילים עברו לעותק הזה · {$res['trashed']} לסל");
    return $res;
}

// ── הורדה: ZIP בחלקים ─────────────────────────────────────

function rec_zip_dir(): string
{
    $d = files_dir() . '/tmp';
    if (!is_dir($d) && !@mkdir($d, 0775, true)) throw new ApiError('אין גישה לתיקיית הקבצים', 500);
    return $d;
}

function rec_zip_job(array $user, mixed $token): array
{
    $t = is_string($token) && preg_match('/^[0-9a-f]{32}$/', $token) ? $token : throw new ApiError('ההורדה לא נמצאה', 404);
    $p = rec_zip_dir() . "/zip-$t.json";
    $j = is_file($p) ? json_decode((string)file_get_contents($p), true) : null;
    if (!is_array($j) || !hash_equals((string)$j['sub'], (string)$user['sub'])) throw new ApiError('ההורדה לא נמצאה. בנו אותה שוב', 404);
    return $j;
}

function rec_zip_save(array $j): void
{
    file_put_contents(rec_zip_dir() . "/zip-{$j['token']}.json", json_encode($j, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** שם בטוח לקובץ בתוך הארכיון: בלי תיקיות, בלי ../, בלי תווים שמערכות הפעלה אוסרות. */
function rec_zip_safe(string $s): string
{
    $s = (string)preg_replace('/[\x00-\x1f\x7f\/\\\\:*?"<>|]+/u', '_', $s);
    $s = trim((string)preg_replace('/^\.+/', '', $s), " .");
    return mb_substr($s, 0, 80) ?: 'הקלטה';
}

/** ערך לקובץ הרשימה (CSV): מרכאות, ובלי נוסחה שגיליון יריץ (=, +, -, @). */
function rec_csv(mixed $v): string
{
    $s = (string)$v;
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) $s = "'" . $s;
    return '"' . str_replace('"', '""', $s) . '"';
}

/** "הורד" לנבחרות או לכולן: רשימת הקבצים נקבעת עכשיו; הבייטים מועתקים בצעדים (rec_zip_step). */
function rec_zip_start(array $user, array $b): array
{
    $dir = rec_zip_dir();
    foreach (glob("$dir/zip-*") ?: [] as $old) if (filemtime($old) < time() - 6 * 3600) @unlink($old);
    rec_sync();
    $all = ($b['all'] ?? false) === true;
    $ids = $all ? [] : array_flip(rec_ids($b['ids'] ?? null));
    // N2b: לכל מקום — הראשי "<מזהה>.<סיומת>", שדה מותאם "<מזהה>-<מפתח>.<סיומת>" (כמו בחבילה), כך שהעלאה של אותו ZIP חזרה
    // מתאימה כל קובץ למקומו (rec_match)
    $primary = rec_primary();
    $fields = rec_audio_fields();
    $rows = rec_db()->prepare("SELECT r.*, w.word AS word FROM recordings r LEFT JOIN words w ON w.id = r.word_id AND r.list = 'words'
        WHERE r.deleted_at IS NULL AND r.state = 'tool' AND r.file_id IS NOT NULL ORDER BY r.word_id IS NULL, r.word_id, r.field != ?, r.field, r.id");
    $rows->execute([$primary]);
    $rows = $rows->fetchAll();
    $files = files_meta(array_values(array_unique(array_column($rows, 'file_id'))));
    $items = [];
    $used = [];
    $bytes = 0;
    foreach ($rows as $r) {
        if (!$all && !isset($ids[(int)$r['id']])) continue;
        $f = $files[$r['file_id']] ?? null;
        if (!$f || !is_file(file_path($f))) continue;
        $path = $r['word_id'] ? $r['word_id'] . ($r['field'] === $primary ? '' : '-' . $r['field']) . ".{$f['ext']}" : 'extra/' . rec_zip_safe($r['name']) . ".{$f['ext']}";
        for ($n = 2, $base = $path; isset($used[mb_strtolower($path)]); $n++) $path = preg_replace('/(\.[a-z0-9]+)$/', " ($n)$1", $base);
        $used[mb_strtolower($path)] = true;
        $items[] = ['rec' => (int)$r['id'], 'fid' => $f['id'], 'path' => $path, 'name' => $r['name'], 'orig' => $f['name'], 'size' => (int)$f['size'],
            'word_id' => $r['word_id'] === null ? null : (int)$r['word_id'], 'word' => (string)($r['word'] ?? ''), 'duration' => $r['duration'], 'sha' => $f['sha256'],
            'list' => $r['list'], 'field' => $r['field'], 'field_label' => $fields[$r['field']]['label'] ?? rec_field_label($r['field'])];
        $bytes += (int)$f['size'];
    }
    if (!$items) throw new ApiError('אין קבצים להוריד — רק הקלטות "בכלי" יורדות');
    if ($bytes > REC_ZIP_MAX) throw new ApiError('הארכיון גדול מ-4GB — בחרו פחות הקלטות בכל פעם');
    $token = bin2hex(random_bytes(16));
    $j = ['token' => $token, 'sub' => $user['sub'], 'at' => now_iso(), 'items' => $items, 'i' => 0, 'bytes' => $bytes, 'written' => 0, 'cd' => [], 'done' => false];
    file_put_contents("$dir/zip-$token.zip", '');
    rec_zip_save($j);
    audit_as($user, 'rec_download', count($items) . ' הקלטות · ZIP');
    return rec_zip_public($j);
}

function rec_zip_public(array $j): array
{
    return ['token' => $j['token'], 'count' => count($j['items']), 'i' => $j['i'], 'bytes' => $j['bytes'], 'written' => $j['written'], 'done' => $j['done']];
}

/** תאריך ושעה בפורמט של ZIP (DOS). */
function rec_zip_dos(int $t): array
{
    $d = getdate($t);
    return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2), (max(0, $d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
}

/** רשומה אחת בארכיון (בלי דחיסה — שמע כבר דחוס, והקובץ נשאר כמו שהוא). */
function rec_zip_put($out, array &$j, string $name, string $src, ?string $bytes = null): void
{
    [$tm, $dt] = rec_zip_dos(time());
    $size = $bytes === null ? (int)filesize($src) : strlen($bytes);
    $crc = $bytes === null ? hexdec(hash_file('crc32b', $src)) : crc32($bytes);
    $off = (int)ftell($out);
    fwrite($out, pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $tm, $dt, $crc, $size, $size, strlen($name), 0) . $name);
    if ($bytes === null) {
        $in = fopen($src, 'rb');
        stream_copy_to_stream($in, $out);
        fclose($in);
    } else {
        fwrite($out, $bytes);
    }
    $j['cd'][] = [$name, $crc, $size, $off, $tm, $dt];
}

function rec_zip_step(array $user, array $b): array
{
    @set_time_limit(120);
    $j = rec_zip_job($user, $b['token'] ?? '');
    if ($j['done']) return rec_zip_public($j);
    $zip = rec_zip_dir() . "/zip-{$j['token']}.zip";
    $out = fopen($zip, 'r+b');
    if (!$out || !flock($out, LOCK_EX | LOCK_NB)) return ['busy' => true] + rec_zip_public($j);
    $j = rec_zip_job($user, $j['token']);                    // אחרי הנעילה — המצב העדכני
    ftruncate($out, $j['written']);                          // צעד קודם שנקטע באמצע קובץ — מתחילים את הקובץ מחדש
    fseek($out, $j['written']);
    $j['cd'] = array_values(array_filter($j['cd'], fn($c) => $c[3] < $j['written']));
    $t0 = microtime(true);
    $copied = 0;
    $max = max(1, (int)(setting('rec_zip_step_bytes') ?? REC_ZIP_STEP_BYTES));     // פנימי — בדיקות: צעד לכל קובץ
    $files = files_meta(array_column(array_slice($j['items'], $j['i']), 'fid'));
    while ($j['i'] < count($j['items']) && ($copied === 0 || ($copied < $max && microtime(true) - $t0 < REC_ZIP_STEP_SECONDS))) {
        $it = $j['items'][$j['i']];
        $f = $files[$it['fid']] ?? null;
        if ($f && is_file(file_path($f))) {
            rec_zip_put($out, $j, $it['path'], file_path($f));
            $copied += $it['size'];
        }
        $j['i']++;
        $j['written'] = (int)ftell($out);
    }
    if ($j['i'] >= count($j['items'])) {
        $csv = "\xEF\xBB\xBF" . implode(',', array_map('rec_csv', ['מזהה המילה', 'המילה', 'שם ההקלטה', 'שם הקובץ המקורי', 'הקובץ בארכיון', 'משך (שניות)', 'גודל (בייטים)', 'sha256',
            'list', 'field', 'field_label'])) . "\r\n";
        foreach ($j['items'] as $it) $csv .= implode(',', array_map('rec_csv', [$it['word_id'] ?? '', $it['word'], $it['name'], $it['orig'], $it['path'],
            $it['duration'] === null ? '' : $it['duration'], $it['size'], $it['sha'], $it['list'] ?? 'words', $it['field'] ?? '', $it['field_label'] ?? ''])) . "\r\n";
        rec_zip_put($out, $j, 'recordings.csv', '', $csv);
        $cdOff = (int)ftell($out);
        $cd = '';
        foreach ($j['cd'] as [$name, $crc, $size, $off, $tm, $dt]) {
            $cd .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $tm, $dt, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $off) . $name;
        }
        $n = count($j['cd']);
        fwrite($out, $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($cd), $cdOff, 0));
        $j['written'] = (int)ftell($out);
        $j['done'] = true;
        $j['cd'] = [];
    }
    fflush($out);
    flock($out, LOCK_UN);
    fclose($out);
    rec_zip_save($j);
    return rec_zip_public($j);
}

/** הארכיון שנבנה. לא חוזר. */
function rec_zip_get(array $user, array $q): never
{
    $j = rec_zip_job($user, $q['token'] ?? '');
    if (!$j['done']) throw new ApiError('הארכיון עוד לא מוכן', 409);
    $zip = rec_zip_dir() . "/zip-{$j['token']}.zip";
    if (!is_file($zip)) throw new ApiError('הארכיון נמחק. בנו אותו שוב', 410);
    header_remove('Content-Type');
    header('Content-Type: application/zip');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode('kol-maroko-recordings-' . gmdate('Y-m-d') . '.zip'));
    header('Content-Length: ' . filesize($zip));
    while (ob_get_level()) ob_end_clean();
    readfile($zip);
    exit;
}

// ── שדה שמע: שינוי מפתח, מחיקה ושחזור (N2b — קריאות מ-lib/fields_bind.php ומ-lib/lexicon.php) ──

/**
 * המפתח של שדה השתנה (field_key_apply, N2 יחידה 5) — בתוך אותה עסקה: recordings.field ו-deleted_field, והנתיבים בעץ של
 * שדה מותאם (media/audio/<מזהה>-<ישן>.<סיומת> → -<חדש>). הראשי — הנתיב שלו בלי מפתח, ולכן רק העמודות.
 */
function rec_field_renamed(string $old, string $new, array $user): void
{
    $pdo = db();
    $pdo->prepare("UPDATE recordings SET field = ? WHERE list = 'words' AND field = ?")->execute([$new, $old]);
    $pdo->prepare("UPDATE recordings SET deleted_field = ? WHERE deleted_list = 'words' AND deleted_field = ?")->execute([$new, $old]);
    $st = $pdo->prepare("SELECT * FROM pf_files WHERE deleted_at IS NULL AND kind = 'media' AND path LIKE ? ESCAPE '!'");
    $st->execute(['media/audio/%-' . pf_like($old) . '.%']);
    $put = $pdo->prepare('UPDATE pf_files SET path = ?, path_key = ?, updated_by = ?, updated_name = ?, updated_at = ? WHERE id = ?');
    foreach ($st->fetchAll() as $r) {
        if (!preg_match('~^media/audio/(\d+)-' . preg_quote($old, '~') . '(\.[A-Za-z0-9]+)$~', $r['path'], $m)) continue;
        $to = "media/audio/{$m[1]}-$new{$m[2]}";
        if (pf_by_path($to)) continue;                        // תפוס (נוצר ביד) — הקודם נשאר; rec_tree_point ימצא את החדש
        $put->execute([$to, pf_key($to), $user['sub'] ?? '', $user['name'] ?? '', now_iso(), $r['id']]);
        pf_hist(['path' => $to] + $r, $user, "שינוי מפתח השדה: {$r['path']}");
    }
}

/** כמה הקלטות מחוברות לשדה — לאישור המחיקה ("23 הקלטות יישארו בספרייה כלא מחוברות"). */
function rec_field_dropped(string $key): int
{
    rec_sync();
    $st = rec_db()->prepare("SELECT COUNT(*) FROM recordings WHERE list = 'words' AND field = ? AND word_id IS NOT NULL AND deleted_at IS NULL");
    $st->execute([$key]);
    return (int)$st->fetchColumn();
}

/**
 * השדה נמחק / שוחזר (field_delete / field_trash_restore): הערכים יצאו מ-extra / חזרו אליו, ו-rec_sync הוא שמנתק או מחבר —
 * הקלטה שהתנתקה נשארת עם השדה שלה (התווית "משדה שנמחק: <שם>"), ושחזור השדה מחזיר את מזהה הקובץ למקום, ו-rec_sync
 * מחבר אליו את אותה רשומה (שם, משך, גרסאות). בתוך העסקה של הפעולה — אחרי שהיא נגמרת.
 */
function rec_field_changed(): void
{
    if (db()->inTransaction()) return;                       // בתוך עסקה — הסנכרון בפתיחה הבאה של הספרייה
    try { rec_sync(); }
    catch (Throwable $e) { devlog_write('warn', 'audio', 'field_sync', ['message' => 'השדה עודכן; הספרייה תסתנכרן בפתיחה הבאה: ' . $e->getMessage()]); }
}
