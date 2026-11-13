<?php
/**
 * ההקלטות כרשומות, והבאתן מהאתר אל הכלי (פרוסה A2 · docs/spec-v2/05-audio-library.md §3, §4).
 *
 * המודל: רשומה לכל מילה שיש לה הקלטה (המפתח — מזהה המילה). מצב: site (רק כתובת) · tool (יש קובץ) · failed (הבאה
 * שנכשלה, עם הסיבה). המקור (הכתובת באתר) נשמר תמיד. שדה audio של המילה **אינו משתנה** כאן — לא במיגרציה ולא בהבאה:
 * הרשומה נגזרת ממנו (rec_sync), ועד M2 העמוד החי ממשיך להפנות לאתר (§7).
 *
 * הסריקה (HEAD) וההבאה (GET) רצות רק בלחיצה של בעל האתר, בצעדים קצרים שהדפדפן מבקש אחד אחרי השני — כל צעד קצר
 * ממגבלת הזמן של השרת, וכל הקלטה מסומנת כשהסתיימה, כך שעצירה באמצע ממשיכה מאותה נקודה.
 *
 * ⚠️ הגבולות של השרת המוריד (§4.5): רק https, רק הדומיין של האתר, רק תחת /wp-content/uploads/; בלי IP, בלי
 *    localhost ובלי כתובת שנפתרת לרשת פנימית (הכתובת שנפתרה ננעלת — CURLOPT_RESOLVE); הפניה נבדקת מחדש כמו כתובת
 *    חדשה; בלי עוגיות, בלי הרשאה, בלי פרוקסי; רק GET / HEAD; תקרת גודל ותקרת זמן, וקובץ חלקי נמחק.
 *    מסלול ההורדה היחיד: rec_fetch_step ← rec_http. (נבדק ב-tests/cases/85-recordings.php.)
 */

declare(strict_types=1);

require_once __DIR__ . '/page.php';
require_once __DIR__ . '/devlog.php';
require_once __DIR__ . '/project.php';
require_once __DIR__ . '/net.php';         // V16c: בדיקת מארח / IP משותפת לבקשות החוצה

const REC_UPLOADS = '/wp-content/uploads/';
const REC_UA = 'KolMaroko-AudioFetch/1.0 (read-only copy of this site\'s own recordings, started by the site owner)';
const REC_HEAD_TIMEOUT = 15;
const REC_GET_TIMEOUT = 25;        // לכל הורדה; צעד שלם: לכל היותר REC_STEP_BUDGET + REC_GET_TIMEOUT (~30 שניות)
const REC_STEP_BUDGET = 5;         // אחרי כמה שניות צעד אינו מתחיל קבוצה חדשה
const REC_MAX_HOPS = 3;
const REC_BLOCK_RUN = 5;           // כמה חסימות רצופות, בלי אף הצלחה, הן "האתר חוסם את הכלי"
const REC_REASONS = [
    'missing' => 'לא נמצא באתר', 'blocked' => 'האתר חסם את הבקשה', 'too_big' => 'גדול מהתקרה לקובץ', 'not_audio' => 'אינו קובץ שמע',
    'html' => 'הגיע דף אינטרנט במקום קובץ שמע', 'timeout' => 'תם הזמן', 'network' => 'תקלת רשת', 'redirect' => 'הפניה לכתובת מחוץ לאתר',
    'bad_url' => 'כתובת שאינה מותרת', 'partial' => 'ההורדה נקטעה באמצע', 'disk' => 'אין מקום בתקרת הדיסק', 'http' => 'האתר החזיר שגיאה',
    'store' => 'השמירה בכלי נכשלה',
];

// ── המודל ─────────────────────────────────────────────────

/**
 * המסד. הטבלה recordings — בסכמה (lib/db.php, שלב 20: רשומה לכל מקום — רשימה, פריט, שדה). rec_versions — כאן,
 * CREATE IF NOT EXISTS (מוסיף בלבד).
 */
function rec_db(): PDO
{
    static $ready = null;
    $pdo = db();
    if ($ready !== $pdo) {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS rec_versions (
                id        INTEGER PRIMARY KEY,
                rec_id    INTEGER NOT NULL,               -- recordings.id
                file_id   TEXT,                           -- הקובץ של הגרסה; NULL — הגרסה היא ההקלטה שבאתר (source)
                source    TEXT NOT NULL DEFAULT '',
                name      TEXT NOT NULL DEFAULT '',
                duration  REAL,
                how       TEXT NOT NULL DEFAULT '',
                since     TEXT,                           -- מתי הגרסה הזו נעשתה הנוכחית
                at        TEXT NOT NULL,                  -- מתי הוחלפה
                by_name   TEXT NOT NULL DEFAULT ''
            );
            CREATE INDEX IF NOT EXISTS rec_versions_rec ON rec_versions (rec_id);
            SQL);
        $ready = $pdo;
    }
    return $pdo;
}

/** הגרסה הנוכחית של הקלטה → גרסה קודמת (לפני החלפה). בלי קובץ ובלי מקור — אין מה לשמור. */
function rec_version_push(array $r, string $by): void
{
    if (!$r['file_id'] && $r['source'] === '') return;
    rec_db()->prepare('INSERT INTO rec_versions (rec_id, file_id, source, name, duration, how, since, at, by_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$r['id'], $r['file_id'] ?: null, $r['file_id'] ? '' : $r['source'], $r['name'], $r['duration'], $r['how'], $r['at'], now_iso(), $by]);
}

/** קובץ חי בספרייה (לא בסל)? */
function rec_file_ok(?string $fid, array $files): bool
{
    return $fid && isset($files[$fid]) && empty($files[$fid]['deleted_at']);
}

// ── המקומות (N2b) ─────────────────────────────────────────

/**
 * שדות השמע של המילה, לפי סדר השדות: [מפתח => ['key', 'label', 'primary', 'published']]. הראשי — השדה שה-col שלו audio
 * (המפתח שלו יכול להשתנות, N2; הזהות היא col). מקום = (רשימה, פריט, שדה); היום רק 'words' (N3 — רשימות).
 */
function rec_audio_fields(): array
{
    $out = [];
    foreach (fields_all() as $f) {
        if ($f['type'] !== 'audio' && $f['col'] !== 'audio') continue;
        $out[$f['key']] = ['key' => $f['key'], 'label' => $f['label'], 'primary' => $f['col'] === 'audio', 'published' => $f['published']];
    }
    return $out;
}

/** המפתח של השדה הראשי. */
function rec_primary(): string
{
    foreach (rec_audio_fields() as $k => $f) if ($f['primary']) return $k;
    return 'audio';
}

/** מפתח המקום, למפות בזיכרון. */
function rec_slot_key(string $list, int $item, string $field): string
{
    return "$list|$item|$field";
}

// ── רשימות (N3b · N2b §2.6) ───────────────────────────────

/**
 * שדות השמע של כל רשימה (לא המילים): [רשימה => [מפתח => ['key', 'label', 'list_label', 'published']]]. ברשימה אין שדה ראשי —
 * כל שדה שמע הוא "מותאם": הערך ב-list_items.data[מפתח] הוא מזהה קובץ בספרייה (N3).
 */
function rec_list_fields(): array
{
    $out = [];
    foreach (lists_all() as $k => $l) {
        foreach (list_fields($k) as $f) {
            if ($f['type'] !== 'audio') continue;
            $out[$k][$f['key']] = ['key' => $f['key'], 'label' => $f['label'], 'list_label' => $l['label'], 'published' => $f['published']];
        }
    }
    return $out;
}

/**
 * המפתח של שדה כלפי הספרייה (הדפדפן): במילים — מפתח השדה (כמו לפני); ברשימה — "<רשימה>.<מפתח>". נקודה אינה יכולה להופיע
 * במפתח רשימה או שדה ([a-z][a-z0-9_]), ולכן הפירוק חד-משמעי (rec_fsplit).
 */
function rec_fkey(string $list, string $field): string
{
    return $list === 'words' ? $field : "$list.$field";
}

/** "<רשימה>.<מפתח>" ← [רשימה, מפתח]; בלי נקודה — [words, המפתח]. */
function rec_fsplit(string $fk): array
{
    $p = strpos($fk, '.');
    return $p === false ? ['words', $fk] : [substr($fk, 0, $p), substr($fk, $p + 1)];
}

/** הכותרת של פריט ברשימה, לשם ההקלטה ולשורה בספרייה: הערך הטקסטואלי הראשון לפי סדר השדות; בלי — "<שם פריט> #<מזהה>". */
function rec_item_title(array $data, array $fields, string $itemLabel, int $id): string
{
    foreach ($fields as $f) {
        if (in_array($f['type'], ['image', 'audio', 'number', 'bool', 'date'], true)) continue;
        $v = $data[$f['key']] ?? null;
        if (is_array($v)) $v = implode(', ', array_filter($v, 'is_string'));
        if (!is_string($v)) continue;
        if ($f['type'] === 'rich') $v = html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8');     // טקסט רגיל — כמו שהוא (מוצג כטקסט)
        $t = trim((string)preg_replace('/\s+/u', ' ', $v));
        if ($t !== '') return mb_substr($t, 0, 200);
    }
    return trim(($itemLabel !== '' ? $itemLabel : 'פריט') . " #$id");
}

/**
 * המקומות המלאים של כל הרשימות: [מפתח מקום => [פריט, שדה, ערך, ['word' => כותרת, 'updated_by'], רשימה]]. רק מזהה קובץ —
 * ברשימה אין "כתובת באתר".
 */
function rec_list_slots(): array
{
    $out = [];
    foreach (rec_list_fields() as $k => $afs) {
        $l = list_get($k);
        $fields = list_fields($k);
        foreach (list_rows($k) as $r) {
            $title = null;
            foreach ($afs as $fk => $f) {
                $v = $r['data'][$fk] ?? null;
                if (!is_string($v) || !file_id($v)) continue;
                $title ??= rec_item_title($r['data'], $fields, $l['item_label'], $r['id']);
                $out[rec_slot_key($k, $r['id'], $fk)] = [$r['id'], $fk, $v, ['word' => $title, 'updated_by' => (string)($r['updated_by'] ?? '')], $k];
            }
        }
    }
    return $out;
}

/**
 * הרשומות נגזרות מהמילים: לכל מקום שיש בו הקלטה — רשומה אחת. הראשי — words.audio (מזהה קובץ או כתובת באתר); כל שדה
 * audio מותאם — words.extra[מפתח] (מזהה קובץ בלבד, N2 §2.2). מוסיף ומעדכן רק את הטבלה recordings; המילים והקבצים אינם
 * משתנים. כתובת שיש לה עותק בכלי (files.origin) — "בכלי", גם כשהמילה עדיין מצביעה על הכתובת.
 */
function rec_sync(): void
{
    $pdo = rec_db();
    $fields = rec_audio_fields();
    $primary = rec_primary();
    $custom = array_keys(array_filter($fields, fn($f) => !$f['primary']));
    // המקומות המלאים: [מפתח מקום => [wid, field, value, word, updated_by]]
    $slots = [];
    foreach ($pdo->query("SELECT id, word, audio, extra, updated_by FROM words WHERE audio != ''" . ($custom ? " OR extra != '{}'" : ''))->fetchAll() as $w) {
        $wid = (int)$w['id'];
        if ($w['audio'] !== '') $slots[rec_slot_key('words', $wid, $primary)] = [$wid, $primary, (string)$w['audio'], $w];
        if (!$custom) continue;
        $x = json_decode((string)$w['extra'], true);
        foreach ($custom as $k) {
            $v = is_array($x) ? ($x[$k] ?? null) : null;
            if (is_string($v) && file_id($v)) $slots[rec_slot_key('words', $wid, $k)] = [$wid, $k, $v, $w];
        }
    }
    // N3b: כל שדה audio של כל רשימה — אותם כללים (רשימה שנמחקה / פריט שנמחק / שדה שנמחק ⇒ "לא מחוברת"; חזרו ⇒ מתחברת)
    $slots += rec_list_slots();
    $recs = [];
    foreach ($pdo->query('SELECT * FROM recordings WHERE word_id IS NOT NULL')->fetchAll() as $r) $recs[rec_slot_key((string)$r['list'], (int)$r['word_id'], $r['field'])] = $r;
    $moved = [];                                       // N3b: מקומות ברשימה שהקובץ שלהם השתנה מחוץ לספרייה — הנתיב בעץ (אחרי העסקה)
    $files = [];
    $origin = [];
    // A3: הקלטה "לא מחוברת" (word_id NULL, לא בסל) — מקום שמצביע על הקובץ שלה מתחבר אליה מחדש (שם, משך וגרסאות נשמרים)
    $loose = [];
    foreach ($pdo->query('SELECT id, file_id FROM recordings WHERE word_id IS NULL AND deleted_at IS NULL AND file_id IS NOT NULL ORDER BY id')->fetchAll() as $u) $loose[$u['file_id']] ??= (int)$u['id'];
    foreach ($pdo->query("SELECT id, origin, deleted_at FROM files WHERE kind = 'audio'")->fetchAll() as $f) {
        $files[$f['id']] = $f;
        if (empty($f['deleted_at']) && ($f['origin'] ?? '') !== '' && !isset($origin[$f['origin']])) $origin[$f['origin']] = $f['id'];
    }
    $now = now_iso();
    $ins = $pdo->prepare('INSERT INTO recordings (list, word_id, field, state, source, file_id, name, how, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $upd = $pdo->prepare('UPDATE recordings SET state = ?, source = ?, file_id = ?, how = ?, duration = ?, scan = ?, scan_size = ?, scan_note = ?, f_code = ?, f_note = ? WHERE id = ?');
    $relink = $pdo->prepare('UPDATE recordings SET list = ?, word_id = ?, field = ? WHERE id = ?');
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        foreach ($slots as $sk => [$wid, $field, $audio, $w]) {
            $list = $slots[$sk][4] ?? 'words';
            $r = $recs[$sk] ?? null;
            $fid = file_id($audio);
            if ($fid) {                                    // המקום מצביע על קובץ בכלי (הוחלף, הועלה, ייבוא ה-ZIP, או שדה מותאם)
                $source = $r['source'] ?? '';
                if ($source === '' && isset($files[$fid])) $source = (string)($files[$fid]['origin'] ?? '');
                $state = 'tool';
                $how = $r && $r['file_id'] === $fid ? $r['how'] : ($r ? 'replaced' : 'linked');
            } else {                                       // כתובת באתר (רק בשדה הראשי)
                $source = $audio;
                $same = $r && $r['source'] === $source;
                $fid = $same && rec_file_ok($r['file_id'], $files) ? $r['file_id'] : ($origin[$source] ?? null);
                $state = $fid ? 'tool' : ($same && $r['state'] === 'failed' ? 'failed' : 'site');
                $how = $fid ? ($same && $r['file_id'] === $fid ? $r['how'] : 'imported') : '';
            }
            if ($list !== 'words' && $fid && (!$r || $r['file_id'] !== $fid)) $moved[] = [$list, $wid, $field, $fid, (string)($w['updated_by'] ?? '')];
            if (!$r && $fid && isset($loose[$fid])) {
                $relink->execute([$list, $wid, $field, $loose[$fid]]);
                unset($loose[$fid]);
                continue;
            }
            if (!$r) {
                $ins->execute([$list, $wid, $field, $state, $source, $fid, mb_substr((string)$w['word'], 0, 200), $how, $now]);
                continue;
            }
            // A3: הקובץ הוחלף מחוץ לספרייה (📚 מילים) — הקודם נשמר כגרסה. הבאה (אותה כתובת, עותק בכלי) אינה החלפה.
            if ($r['file_id'] !== $fid && $fid && ($r['file_id'] || ($r['state'] !== 'tool' && $r['source'] !== '' && ($files[$fid]['origin'] ?? '') !== $r['source']))) {
                rec_version_push($r, (string)($w['updated_by'] ?? ''));
            }
            $srcChanged = $r['source'] !== $source;
            $dur = $r['file_id'] === $fid ? $r['duration'] : null;
            $row = [$state, $source, $fid, $how, $dur,
                $srcChanged ? '' : $r['scan'], $srcChanged ? null : $r['scan_size'], $srcChanged ? '' : $r['scan_note'],
                $state === 'failed' ? $r['f_code'] : '', $state === 'failed' ? $r['f_note'] : ''];
            $was = [$r['state'], $r['source'], $r['file_id'], $r['how'], $r['duration'], $r['scan'], $r['scan_size'], $r['scan_note'], $r['f_code'], $r['f_note']];
            if ($row != $was) $upd->execute([...$row, $r['id']]);
        }
        // מקום שהתרוקן (גם שדה שנמחק, ומילה שנמחקה): רשומה עם קובץ (או עם גרסאות) נשארת בספרייה כ"לא מחוברת" — עם
        // השדה שלה (לתווית "משדה שנמחק", ולשחזור) — רק כתובת בלי קובץ יורדת
        $del = $pdo->prepare('DELETE FROM recordings WHERE id = ?');
        $loosen = $pdo->prepare('UPDATE recordings SET word_id = NULL WHERE id = ?');
        $hasV = $pdo->prepare('SELECT 1 FROM rec_versions WHERE rec_id = ? LIMIT 1');
        foreach ($recs as $sk => $r) {
            if (isset($slots[$sk])) continue;
            $hasV->execute([$r['id']]);
            (rec_file_ok($r['file_id'], $files) || $hasV->fetchColumn()) ? $loosen->execute([$r['id']]) : $del->execute([$r['id']]);
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    // N3b: הקובץ של מקום ברשימה הוחלף מהטבלה / מהקובץ / מהאקסל — הנתיב בעץ (media/audio/<רשימה>-<פריט>-<שדה>) מצביע עליו.
    // אחרת החבילה (build_lists — נתיב שתפוס בעץ גובר) הייתה יוצאת עם הקובץ הקודם.
    if ($moved && function_exists('rec_tree_point')) foreach ($moved as [$list, $wid, $field, $fid, $by]) rec_tree_point($wid, $fid, ['sub' => 'system', 'name' => $by !== '' ? $by : 'system'], $field, $list);
}

/** הרשומות של השדה הראשי, לפי מזהה המילה (אחרי סנכרון) — כמו לפני N2b. */
function rec_rows(): array
{
    rec_sync();
    $st = rec_db()->prepare("SELECT * FROM recordings WHERE list = 'words' AND field = ? AND word_id IS NOT NULL");
    $st->execute([rec_primary()]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['word_id']] = $r;
    return $out;
}

/** הרשומות של כל השדות המותאמים: [מזהה מילה => [מפתח => רשומה]]. בלי סנכרון (אחרי rec_rows). */
function rec_rows_custom(): array
{
    $st = rec_db()->prepare("SELECT * FROM recordings WHERE list = 'words' AND field != ? AND word_id IS NOT NULL");
    $st->execute([rec_primary()]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['word_id']][$r['field']] = $r;
    return $out;
}

/** משך שנמדד בדפדפן. נשמר רק לקובץ שבכלי, ורק אם זה עדיין הקובץ של ההקלטה (לא מדידה של קובץ שהוחלף). */
function rec_duration(array $user, array $b): array
{
    $wid = (int)($b['word_id'] ?? 0);
    $rid = (int)($b['rec_id'] ?? 0);                 // A3: הקלטה לא מחוברת — לפי מזהה הרשומה
    $fid = file_id($b['file_id'] ?? '');
    $d = $b['duration'] ?? null;
    if ((!$wid && !$rid) || !$fid || !is_numeric($d) || $d <= 0 || $d > 36000) throw new ApiError('משך לא תקין');
    rec_sync();
    // N2b: הקלטה של מילה — לפי המקום (המילה והשדה; בלי שדה — הראשי)
    // N3b: שדה של רשימה — "<רשימה>.<מפתח>" (rec_fkey)
    [$list, $field] = rec_fsplit(is_string($b['field'] ?? null) && $b['field'] !== '' ? $b['field'] : rec_primary());
    $st = rec_db()->prepare("UPDATE recordings SET duration = ? WHERE " . ($rid ? 'id = ?' : 'list = ? AND word_id = ? AND field = ?') . " AND file_id = ? AND state = 'tool'");
    $st->execute($rid ? [round((float)$d, 2), $rid, $fid] : [round((float)$d, 2), $list, $wid, $field, $fid]);
    return ['saved' => $st->rowCount() > 0];
}

// ── הגבולות ───────────────────────────────────────────────

/** כתובת שמותר לפנות אליה: '' — מותרת; אחרת — הסיבה במילים. */
function rec_url_check(string $url): string
{
    if ($url === '' || strlen($url) > 2000 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return 'כתובת לא תקינה';
    $p = parse_url($url);
    if (!$p || !isset($p['scheme'], $p['host'])) return 'כתובת לא תקינה';
    if (strtolower($p['scheme']) !== 'https') return 'רק https';
    if (isset($p['user']) || isset($p['pass'])) return 'כתובת עם שם משתמש';
    if (isset($p['port']) && (int)$p['port'] !== 443) return 'פורט שאינו 443';
    $host = strtolower($p['host']);
    if (str_ends_with($host, '.')) return 'שם מתחם עם נקודה בסוף';      // ההצמדה ב-curl לא חלה עליו (ראו net.php)
    if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) || preg_match('/^[0-9.]+$|^0x/i', $host)) return 'כתובת IP';
    if (net_host($host) === '') return 'שם מתחם לא תקין';
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) return 'localhost';
    if (preg_replace('/^www\./', '', $host) !== lex_host()) return 'דומיין אחר';
    $path = (string)($p['path'] ?? '');
    $dec = rawurldecode(rawurldecode($path));
    if (!str_starts_with($path, REC_UPLOADS) || !str_starts_with($dec, REC_UPLOADS)) return 'לא תחת תיקיית ההעלאות';
    if (preg_match('~(^|/)\.\.?(/|$)~', $dec) || str_contains($dec, '\\') || str_contains($dec, "\0")) return 'נתיב לא תקין';
    return '';
}

/** כתובת IP שמותר להתחבר אליה: לא פרטית, לא שמורה, לא loopback / link-local. */
function rec_ip_public(string $ip): bool
{
    return net_ip_public($ip);
}

/**
 * בבדיקות בלבד: מיפוי https://<האתר> ← שרת מקומי מדומה (http). פועל רק ב-CLI / בשרת המובנה של PHP, ורק כשקוד
 * הבדיקות הגדיר אותו ($GLOBALS — לא מגיע מבקשה). בשרת האמיתי (FPM / CGI) — תמיד null.
 */
function rec_test_map(): ?array
{
    $m = $GLOBALS['kol_rec_test_map'] ?? null;
    return is_array($m) && in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? $m : null;
}

/** לאן מתחברים בפועל: [כתובת ל-curl, רשימת RESOLVE, שגיאה]. הכתובת שנפתרה ננעלת — אין פתרון שני (DNS rebinding). */
function rec_target(string $url): array
{
    $map = rec_test_map();
    if ($map !== null) {
        foreach ($map as $from => $to) if (str_starts_with($url, $from . '/')) return [$to . substr($url, strlen($from)), [], ''];
        return ['', [], 'network'];
    }
    $host = net_host((string)parse_url($url, PHP_URL_HOST));                 // זהה למארח שב-URL ש-curl מקבל — אחרת RESOLVE לא חל
    if ($host === '') return ['', [], 'bad_url'];
    $ips = @gethostbynamel($host) ?: [];
    if (!$ips) return ['', [], 'network'];
    foreach ($ips as $ip) if (!rec_ip_public($ip)) return ['', [], 'bad_url'];
    return [$url, ["$host:443:{$ips[0]}"], ''];
}

/**
 * בקשות HEAD / GET, כמה במקביל. $reqs: key => [method, url, save (נתיב לשמירה, ב-GET), max (בייטים)].
 * מחזיר key => [status, headers (אותיות קטנות), bytes, error (קוד מ-REC_REASONS או ''), url (הסופית), note].
 * הפניה: נבדקת ב-rec_url_check כמו כתובת חדשה; עד REC_MAX_HOPS.
 */
function rec_http(array $reqs, int $conc, int $timeout = REC_GET_TIMEOUT): array
{
    $out = [];
    $todo = [];
    foreach ($reqs as $k => $r) {
        if (!in_array($r['method'], ['GET', 'HEAD'], true)) throw new LogicException('רק GET / HEAD');
        $why = rec_url_check($r['url']);
        if ($why !== '') $out[$k] = ['status' => 0, 'headers' => [], 'bytes' => 0, 'error' => 'bad_url', 'url' => $r['url'], 'note' => $why];
        else $todo[$k] = $r + ['hops' => 0];
    }
    $test = rec_test_map() !== null;
    while ($todo) {
        $next = [];
        foreach (array_chunk($todo, max(1, $conc), true) as $group) {
            $mh = curl_multi_init();
            $hs = [];
            foreach ($group as $k => $r) {
                [$target, $resolve, $err] = rec_target($r['url']);
                if ($err !== '') { $out[$k] = ['status' => 0, 'headers' => [], 'bytes' => 0, 'error' => $err, 'url' => $r['url'], 'note' => $err === 'bad_url' ? 'הדומיין נפתר לכתובת פנימית' : 'הדומיין לא נמצא']; continue; }
                $ch = curl_init($target);
                $st = ['headers' => [], 'fh' => null, 'over' => false, 'max' => (int)($r['max'] ?? 0)];
                if ($r['method'] === 'GET') {
                    $st['fh'] = fopen($r['save'], 'wb');
                    if (!$st['fh']) { $out[$k] = ['status' => 0, 'headers' => [], 'bytes' => 0, 'error' => 'store', 'url' => $r['url'], 'note' => '']; continue; }
                }
                $proto = $test ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS;
                $hs[$k] = ['ch' => $ch, 'st' => &$st];
                curl_setopt_array($ch, [
                    CURLOPT_NOBODY => $r['method'] === 'HEAD',
                    CURLOPT_CUSTOMREQUEST => $r['method'],
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS => $proto, CURLOPT_REDIR_PROTOCOLS => $proto,
                    CURLOPT_PROXY => '',                                   // לא דרך פרוסי מהסביבה (RESOLVE לא היה חל)
                    CURLOPT_RESOLVE => $resolve, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,   // רק הכתובת שנבדקה
                    CURLOPT_USERAGENT => REC_UA,
                    CURLOPT_HTTPHEADER => ['Accept: audio/*, */*;q=0.1'],  // בלי Cookie, בלי Authorization
                    CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                    CURLOPT_TIMEOUT => min($timeout, $r['method'] === 'HEAD' ? REC_HEAD_TIMEOUT : REC_GET_TIMEOUT),
                    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HEADERFUNCTION => function ($c, string $line) use (&$st): int {
                        if (preg_match('~^HTTP/~', $line)) $st['headers'] = [];
                        elseif (str_contains($line, ':')) { [$a, $v] = explode(':', $line, 2); $st['headers'][strtolower(trim($a))] = trim($v); }
                        return strlen($line);
                    },
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_XFERINFOFUNCTION => function ($c, $dlTotal, $dlNow) use (&$st): int {
                        if ($st['max'] && ($dlNow > $st['max'] || $dlTotal > $st['max'])) { $st['over'] = true; return 1; }
                        return 0;
                    },
                ]);
                if ($st['fh']) curl_setopt($ch, CURLOPT_FILE, $st['fh']);
                curl_multi_add_handle($mh, $ch);
                unset($st);
            }
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) curl_multi_select($mh, 0.5);
            } while ($running && $status === CURLM_OK);
            $done = [];
            while ($info = curl_multi_info_read($mh)) $done[(int)$info['handle']] = $info['result'];
            foreach ($hs as $k => $h) {
                $ch = $h['ch'];
                $st = $h['st'];
                $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $bytes = (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
                $res = $done[(int)$ch] ?? CURLE_OK;
                if ($st['fh']) fclose($st['fh']);
                $r = $group[$k];
                $o = ['status' => $code, 'headers' => $st['headers'], 'bytes' => $bytes, 'error' => '', 'url' => $r['url'], 'note' => ''];
                if ($st['over'] || $res === CURLE_FILESIZE_EXCEEDED) $o['error'] = 'too_big';
                elseif ($res === CURLE_OPERATION_TIMEDOUT) $o['error'] = 'timeout';
                elseif ($res === CURLE_PARTIAL_FILE || $res === CURLE_RECV_ERROR) $o['error'] = 'partial';
                elseif ($res !== CURLE_OK) [$o['error'], $o['note']] = ['network', curl_strerror($res)];
                elseif ($code >= 300 && $code < 400 && isset($st['headers']['location'])) {
                    $loc = rec_abs_url($r['url'], $st['headers']['location']);
                    $why = rec_url_check($loc);
                    if ($why !== '' || $r['hops'] >= REC_MAX_HOPS) [$o['error'], $o['note']] = ['redirect', $why !== '' ? "$loc — $why" : 'יותר מדי הפניות'];
                    else { $next[$k] = ['url' => $loc, 'hops' => $r['hops'] + 1] + $r; curl_multi_remove_handle($mh, $ch); curl_close($ch); continue; }
                }
                if ($o['error'] !== '' && !empty($r['save'])) @unlink($r['save']);   // לא נשאר קובץ חלקי
                $out[$k] = $o;
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }
        $todo = $next;
    }
    return $out;
}

/** Location (יחסית או מלאה) → כתובת מלאה. */
function rec_abs_url(string $base, string $loc): string
{
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $loc)) return $loc;
    $p = parse_url($base);
    $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($loc, '//')) return $p['scheme'] . ':' . $loc;
    if (str_starts_with($loc, '/')) return $origin . $loc;
    return $origin . preg_replace('~/[^/]*$~', '/', (string)($p['path'] ?? '/')) . $loc;
}

/** תשובה שהיא חסימה (Cloudflare, או 403 / 429 בכלל) — לא "חסרה". */
function rec_is_block(array $res): bool
{
    $h = $res['headers'];
    if (isset($h['cf-mitigated'])) return true;
    return in_array($res['status'], [401, 403, 429], true) || ($res['status'] === 503 && str_contains(strtolower($h['server'] ?? ''), 'cloudflare'));
}

// ── העבודה (סריקה / הבאה) ─────────────────────────────────

function rec_settings(): array
{
    return [
        'conc' => max(1, min(4, (int)(setting('rec_conc') ?? 2))),
        'delay_ms' => max(0, min(5000, (int)(setting('rec_delay_ms') ?? 300))),
        'file_max' => max(1 << 20, min(IMPORT_FILE_MAX, (int)(setting('rec_file_max') ?? FILE_MAX))),
        // פנימיות (בדיקות / כוונון): כמה שניות צעד ממשיך לקבוצה הבאה (0 — קבוצה אחת), ותקרת הזמן להורדה
        'budget' => max(0, min(REC_STEP_BUDGET, (int)(setting('rec_step_budget') ?? REC_STEP_BUDGET))),
        'timeout' => max(1, min(REC_GET_TIMEOUT, (int)(setting('rec_timeout') ?? REC_GET_TIMEOUT))),
    ];
}

function rec_settings_set(array $owner, array $b): array
{
    if (isset($b['conc'])) setting_set('rec_conc', (string)max(1, min(4, (int)$b['conc'])));
    if (isset($b['delay_ms'])) setting_set('rec_delay_ms', (string)max(0, min(5000, (int)$b['delay_ms'])));
    if (isset($b['file_max_mb'])) setting_set('rec_file_max', (string)(max(1, min(600, (int)$b['file_max_mb'])) << 20));
    audit_as($owner, 'rec_settings', json_encode(rec_settings()));
    return rec_status($owner);
}

function rec_job(): array
{
    $j = json_decode((string)setting('rec_job', ''), true);
    return is_array($j) ? $j : ['phase' => '', 'status' => ''];
}

function rec_job_set(array $j): array
{
    $j['touched'] = time();
    setting_set('rec_job', json_encode($j, JSON_UNESCAPED_UNICODE));
    return $j;
}

/**
 * צעד אחד בכל רגע (שתי לשוניות פתוחות, או לחיצה כפולה, לא מורידות את אותה הקלטה פעמיים). null — צעד אחר רץ עכשיו.
 * הנעילה משתחררת כשהבקשה מסתיימת (גם אם נפלה).
 */
function rec_lock()
{
    $dir = files_dir() . '/tmp';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new ApiError('אין גישה לתיקיית הקבצים', 500);
    $fh = fopen($dir . '/rec.lock', 'c');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) { if ($fh) fclose($fh); return null; }
    return $fh;
}

/** כמה מקום פנוי: התקרה פחות מה שבשימוש. */
function rec_free(): int
{
    return max(0, disk_cap() - disk_used());
}

/** מצב מלא למסך: מונים, נפח, מקום, רשימות, והעבודה הנוכחית. */
function rec_status(array $owner): array
{
    $recs = rec_rows();
    $words = [];
    foreach (rec_db()->query('SELECT id, word FROM words')->fetchAll() as $w) $words[(int)$w['id']] = $w['word'];
    $c = ['total' => count($recs), 'tool' => 0, 'site' => 0, 'failed' => 0, 'scan' => ['' => 0, 'ok' => 0, 'missing' => 0, 'blocked' => 0, 'error' => 0, 'skipped' => 0],
        'bytes' => 0, 'unknown_size' => 0, 'todo' => 0, 'todo_bytes' => 0, 'fetched_bytes' => 0];
    $lists = ['scan' => [], 'failed' => []];
    foreach ($recs as $wid => $r) {
        $c[$r['state']]++;
        $item = ['word_id' => $wid, 'word' => $words[$wid] ?? '', 'url' => $r['source']];
        if ($r['state'] === 'tool') continue;
        $c['scan'][$r['scan']]++;
        if ($r['scan'] === 'ok') {
            $r['scan_size'] === null ? $c['unknown_size']++ : $c['bytes'] += (int)$r['scan_size'];
            if ($r['state'] === 'site') { $c['todo']++; $c['todo_bytes'] += (int)$r['scan_size']; }
        } elseif ($r['scan'] !== '') {
            $lists['scan'][] = $item + ['scan' => $r['scan'], 'note' => $r['scan_note']];
        }
        if ($r['state'] === 'failed') $lists['failed'][] = $item + ['code' => $r['f_code'], 'reason' => REC_REASONS[$r['f_code']] ?? $r['f_code'], 'note' => $r['f_note']];
    }
    $j = rec_job();
    $j['stale'] = ($j['status'] ?? '') === 'running' && time() - (int)($j['touched'] ?? 0) > 60;   // הדפדפן נסגר באמצע
    return ['counts' => $c, 'lists' => $lists, 'job' => $j, 'host' => lex_host(), 'cap' => disk_cap(), 'used' => disk_used(), 'free' => rec_free(),
        'settings' => rec_settings(), 'scanned' => $c['total'] - $c['tool'] > 0 && $c['scan'][''] === 0];
}

/** "בדוק מה יש באתר": מאפס את תוצאות הסריקה של מה שעוד לא בכלי, ומתחיל. */
function rec_scan_start(array $owner): array
{
    rec_sync();
    // סריקה חדשה: גם מה שנכשל בהבאה חוזר לתור (ההבאה הבאה תנסה שוב, אחרי אישור)
    rec_db()->exec("UPDATE recordings SET state = 'site', f_code = '', f_note = '', scan = '', scan_size = NULL, scan_type = '', scan_note = '', scan_at = NULL WHERE state != 'tool'");
    rec_job_set(['phase' => 'scan', 'status' => 'running', 'started' => now_iso(), 'by' => $owner['name'], 'blocked' => false]);
    devlog_write('info', 'audio', 'scan_start', ['message' => 'התחילה סריקת ההקלטות באתר', 'user' => $owner]);
    audit_as($owner, 'rec_scan', 'התחלה');
    return rec_status($owner);
}

/** צעד סריקה: HEAD לקבוצה קטנה, עד REC_STEP_BUDGET שניות. */
function rec_scan_step(array $owner): array
{
    @set_time_limit(120);
    $j = rec_job();
    if (($j['phase'] ?? '') !== 'scan' || ($j['status'] ?? '') !== 'running') return rec_status($owner);
    if (!($lock = rec_lock())) return ['busy' => true] + rec_status($owner);
    $j = rec_job();
    $pdo = rec_db();
    $cfg = rec_settings();
    $t0 = microtime(true);
    $set = $pdo->prepare('UPDATE recordings SET scan = ?, scan_size = ?, scan_type = ?, scan_note = ?, scan_at = ? WHERE id = ?');
    $run = (int)($j['block_run'] ?? 0);
    $okSeen = (int)($j['ok_seen'] ?? 0);
    for ($first = true; $first || microtime(true) - $t0 < $cfg['budget']; $first = false) {
        $batch = $pdo->query("SELECT id, word_id, source FROM recordings WHERE state != 'tool' AND scan = '' ORDER BY word_id LIMIT " . ($cfg['conc'] * 2))->fetchAll();
        if (!$batch) break;
        $reqs = [];
        foreach ($batch as $r) $reqs[$r['id']] = ['method' => 'HEAD', 'url' => $r['source']];
        $all = rec_http($reqs, $cfg['conc'], $cfg['timeout']);
        $block = false;
        foreach ($batch as $row) {                      // לפי הסדר — "חמש חסימות רצופות" נספרות כמו שנשלחו
            $id = $row['id'];
            $res = $all[$id];
            $h = $res['headers'];
            $size = isset($h['content-length']) && ctype_digit($h['content-length']) ? (int)$h['content-length'] : null;
            $type = mb_substr(strtolower(trim(explode(';', $h['content-type'] ?? '')[0])), 0, 60);
            if ($res['error'] === 'bad_url' || $res['error'] === 'redirect') [$s, $note] = ['skipped', ($res['error'] === 'redirect' ? 'הפניה: ' : '') . $res['note']];
            elseif ($res['error'] !== '') [$s, $note] = ['error', REC_REASONS[$res['error']] ?? $res['error']];
            elseif (rec_is_block($res)) [$s, $note] = ['blocked', "HTTP {$res['status']}" . (isset($h['cf-mitigated']) ? ' · Cloudflare' : '')];
            elseif (in_array($res['status'], [404, 410], true)) [$s, $note] = ['missing', "HTTP {$res['status']}"];
            elseif ($res['status'] === 200 && $type === 'text/html') [$s, $note] = ['error', REC_REASONS['html']];
            elseif ($res['status'] >= 200 && $res['status'] < 300) [$s, $note] = ['ok', ''];
            else [$s, $note] = ['error', "HTTP {$res['status']}"];
            $set->execute([$s, $s === 'ok' ? $size : null, $type, mb_substr($note, 0, 300), now_iso(), $id]);
            if ($s === 'ok') $okSeen++;
            $run = $s === 'blocked' ? $run + 1 : 0;
            if ($okSeen === 0 && $run >= REC_BLOCK_RUN) $block = true;
        }
        if ($block) {           // חסימה גורפת: עוצרים — לא 1,494 כישלונות
            $j = rec_job_set(['status' => 'blocked', 'blocked' => true, 'block_run' => $run, 'ok_seen' => $okSeen] + $j);
            devlog_write('warn', 'audio', 'scan_blocked', ['message' => 'האתר חוסם את הכלי — הסריקה נעצרה', 'user' => $owner, 'data' => ['blocked_in_row' => $run]]);
            return rec_status($owner);
        }
        if ($cfg['delay_ms']) usleep($cfg['delay_ms'] * 1000);
    }
    $j = rec_job_set(['block_run' => $run, 'ok_seen' => $okSeen] + $j);
    $left = (int)$pdo->query("SELECT COUNT(*) FROM recordings WHERE state != 'tool' AND scan = ''")->fetchColumn();
    if ($left === 0) {
        $st = rec_status($owner);
        $sc = $st['counts']['scan'];
        rec_job_set(['status' => 'done', 'finished' => now_iso()] + $j);
        devlog_write('info', 'audio', 'scan_done', ['message' => "הסריקה הסתיימה: {$sc['ok']} קיימות, {$sc['missing']} חסרות, {$sc['blocked']} חסומות", 'user' => $owner,
            'data' => $sc + ['bytes' => $st['counts']['bytes']]]);
    }
    return rec_status($owner);
}

/** מה ההבאה תעשה — למסך האישור. אסימון התוכנית קושר את הלחיצה למה שהוצג. */
function rec_fetch_plan(array $owner): array
{
    $st = rec_status($owner);
    $c = $st['counts'];
    $need = $c['todo_bytes'];
    $ids = rec_db()->query("SELECT id FROM recordings WHERE state = 'site' AND scan = 'ok' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $plan = ['count' => $c['todo'], 'host' => $st['host'], 'bytes' => $need, 'unknown_size' => $c['unknown_size'], 'free' => $st['free'],
        'after' => $st['free'] - $need, 'enough' => $need <= $st['free'], 'short' => max(0, $need - $st['free']),
        'scanned' => $st['scanned'], 'blocked' => !empty($st['job']['blocked'])];
    $plan['ok'] = $plan['scanned'] && !$plan['blocked'] && $plan['enough'] && $plan['count'] > 0;
    $plan['token'] = hash('sha256', json_encode([$ids, $need]));
    return ['plan' => $plan] + $st;
}

/** "הבא את ההקלטות" — אחרי מסך האישור. בלי האישור הזה rec_fetch_step אינו מוריד דבר. */
function rec_fetch_start(array $owner, array $b): array
{
    $p = rec_fetch_plan($owner)['plan'];
    if (($b['confirm'] ?? false) !== true || !hash_equals($p['token'], (string)($b['token'] ?? ''))) throw new ApiError('מה שאושר כבר אינו מה שיש — פתחו את מסך האישור שוב', 409);
    if (!$p['scanned']) throw new ApiError('קודם "בדוק מה יש באתר"');
    if ($p['blocked']) throw new ApiError('האתר חוסם את הכלי — ההבאה אינה מתחילה');
    if (!$p['enough']) throw new ApiError('אין מספיק מקום: חסרים ' . round($p['short'] / 1048576, 1) . 'MB. מגדילים את התקרה ב-🗂️ קבצים ← תקרת הקבצים', 507);
    if (!$p['count']) throw new ApiError('אין מה להביא');
    rec_job_set(['phase' => 'fetch', 'status' => 'running', 'started' => now_iso(), 'by' => $owner['name'], 'plan' => $p['token'],
        'total' => $p['count'], 'bytes' => $p['bytes'], 'done' => 0, 'failed' => 0, 'got' => 0]);
    devlog_write('info', 'audio', 'fetch_start', ['message' => "התחילה הבאת {$p['count']} הקלטות מ-{$p['host']}", 'user' => $owner, 'data' => ['count' => $p['count'], 'bytes' => $p['bytes']]]);
    audit_as($owner, 'rec_fetch', "התחלה · {$p['count']} הקלטות");
    return rec_status($owner);
}

/** סוג הקובץ לפי התוכן — רק שמע. '' — אינו שמע. */
function rec_audio_mime(string $path, string $name): string
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
    if (($mime === 'video/mp4' && preg_match('/\.(m4a|aac)$/i', $name)) || ($mime === 'video/webm' && preg_match('/\.(weba|webm)$/i', $name))) return 'audio/' . substr($mime, 6);
    if ($mime === 'application/octet-stream' && preg_match('/\.mp3$/i', $name)) return 'audio/mpeg';
    return isset(FILE_TYPES[$mime]) && FILE_TYPES[$mime][0] === 'audio' ? $mime : '';
}

/** צעד הבאה: GET לקבוצה קטנה (ברירת מחדל — שתיים במקביל), עד REC_STEP_BUDGET שניות. */
function rec_fetch_step(array $owner): array
{
    @set_time_limit(120);
    $j = rec_job();
    if (($j['phase'] ?? '') !== 'fetch' || ($j['status'] ?? '') !== 'running') return rec_status($owner);
    if (!($lock = rec_lock())) return ['busy' => true] + rec_status($owner);
    $j = rec_job();
    $pdo = rec_db();
    $cfg = rec_settings();
    $t0 = microtime(true);
    $dir = files_dir() . '/tmp';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new ApiError('אין גישה לתיקיית הקבצים', 500);
    $fail = $pdo->prepare("UPDATE recordings SET state = 'failed', f_code = ?, f_note = ?, f_at = ? WHERE id = ?");
    $done = $pdo->prepare("UPDATE recordings SET state = 'tool', file_id = ?, how = 'fetched', at = ?, by_name = ?, f_code = '', f_note = '', f_at = ? WHERE id = ?");
    $words = $pdo->prepare('SELECT word FROM words WHERE id = ?');
    for ($first = true; $first || microtime(true) - $t0 < $cfg['budget']; $first = false) {
        $batch = $pdo->query("SELECT * FROM recordings WHERE state = 'site' AND scan = 'ok' ORDER BY word_id LIMIT {$cfg['conc']}")->fetchAll();
        if (!$batch) break;
        if (disk_used() + (int)array_sum(array_column($batch, 'scan_size')) > disk_cap()) {
            $j = rec_job_set(['status' => 'full'] + $j);
            devlog_write('warn', 'audio', 'fetch_full', ['message' => 'ההבאה נעצרה: תקרת הדיסק מלאה', 'user' => $owner]);
            return rec_status($owner);
        }
        $reqs = [];
        foreach ($batch as $r) $reqs[$r['id']] = ['method' => 'GET', 'url' => $r['source'], 'save' => $dir . '/rec-' . bin2hex(random_bytes(8)), 'max' => $cfg['file_max']];
        $res = rec_http($reqs, $cfg['conc'], $cfg['timeout']);
        foreach ($batch as $r) {
            $x = $res[$r['id']];
            $tmp = $reqs[$r['id']]['save'];
            $code = $x['error'];
            $note = $x['note'];
            $len = $x['headers']['content-length'] ?? null;
            if ($code === '' && rec_is_block($x)) $code = 'blocked';
            elseif ($code === '' && in_array($x['status'], [404, 410], true)) $code = 'missing';
            elseif ($code === '' && ($x['status'] < 200 || $x['status'] >= 300)) [$code, $note] = ['http', "HTTP {$x['status']}"];
            elseif ($code === '' && $len !== null && ctype_digit($len) && (int)$len !== (int)@filesize($tmp)) $code = 'partial';
            $words->execute([$r['word_id']]);
            $word = (string)($words->fetchColumn() ?: '');
            if ($code === '') {
                $ext = strtolower((string)pathinfo((string)parse_url($x['url'], PHP_URL_PATH), PATHINFO_EXTENSION));
                $name = str_replace(['/', '\\', "\0"], '_', mb_substr($word !== '' ? $word : 'הקלטה ' . $r['word_id'], 0, 80)) . (preg_match('/^[a-z0-9]{1,5}$/', $ext) ? ".$ext" : '');
                $mime = rec_audio_mime($tmp, $name);
                if ($mime === '') {
                    $code = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) === 'text/html' ? 'html' : 'not_audio';
                } else {
                    try {
                        $fid = file_store($tmp, $name, $owner, $mime);
                        $f = file_row($fid);
                        if ($f['kind'] !== 'audio') {                        // לא אמור לקרות — אבל קובץ שאינו שמע לא נשאר
                            @unlink(file_path($f));
                            $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$fid]);
                            $code = 'not_audio';
                        } else {
                            $pdo->prepare('UPDATE files SET origin = ? WHERE id = ?')->execute([$r['source'], $fid]);
                            $done->execute([$fid, now_iso(), $owner['name'], now_iso(), $r['id']]);
                            rec_tree_link((int)$r['word_id'], $fid, $owner);
                            $j['done'] = (int)($j['done'] ?? 0) + 1;
                            $j['got'] = (int)($j['got'] ?? 0) + (int)$f['size'];
                        }
                    } catch (ApiError $e) {
                        [$code, $note] = ['store', $e->getMessage()];
                    }
                }
            }
            @unlink($tmp);
            if ($code !== '') {
                $fail->execute([$code, mb_substr($note, 0, 300), now_iso(), $r['id']]);
                $j['failed'] = (int)($j['failed'] ?? 0) + 1;
                devlog_write('warn', 'audio', 'fetch_fail', ['message' => "הבאה נכשלה: #{$r['word_id']} — " . (REC_REASONS[$code] ?? $code), 'user' => $owner,
                    'data' => ['word_id' => (int)$r['word_id'], 'url' => $r['source'], 'code' => $code, 'note' => $note, 'status' => $x['status']]]);
            }
        }
        $j = rec_job_set($j);
        if ($cfg['delay_ms']) usleep($cfg['delay_ms'] * 1000);
    }
    $left = (int)$pdo->query("SELECT COUNT(*) FROM recordings WHERE state = 'site' AND scan = 'ok'")->fetchColumn();
    if ($left === 0) {
        rec_job_set(['status' => 'done', 'finished' => now_iso()] + $j);
        devlog_write('info', 'audio', 'fetch_done', ['message' => 'ההבאה הסתיימה: ' . (int)$j['done'] . ' הובאו, ' . (int)$j['failed'] . ' נכשלו', 'user' => $owner,
            'data' => ['done' => (int)$j['done'], 'failed' => (int)$j['failed'], 'bytes' => (int)$j['got']]]);
    } else {
        rec_job_set($j);
    }
    return rec_status($owner);
}

/**
 * הנתיב היציב בעץ (N2b): הראשית — media/audio/<מזהה>.<סיומת>; שדה מותאם — media/audio/<מזהה>-<מפתח>.<סיומת> (06 §2.3).
 * N3b: פריט ברשימה — media/audio/<רשימה>-<מזהה>-<מפתח>.<סיומת> (אותה צורה שהקובץ data/<רשימה>.json והחבילה כותבים, N3 §2.4).
 */
function rec_tree_path(int $wid, string $field, string $ext, string $list = 'words'): string
{
    if ($list !== 'words') return "media/audio/$list-$wid-$field" . ($ext !== '' ? ".$ext" : '');
    return 'media/audio/' . $wid . ($field === '' || $field === rec_primary() ? '' : "-$field") . ($ext !== '' ? ".$ext" : '');
}

/** הקלטה שהובאה (או חוברה) — בעץ הפרויקט בנתיב של המקום (רק כשהעץ כבר קיים; כשל כאן אינו מכשיל את הפעולה). */
function rec_tree_link(int $wid, string $fid, array $user, string $field = '', string $list = 'words'): void
{
    try {
        if (!pf_seeded()) return;
        $f = file_row($fid);
        $path = rec_tree_path($wid, $field, (string)$f['ext'], $list);
        pf_tx(function () use ($path, $fid, $user, $field, $list) {
            if (pf_by_path($path)) return;
            pf_free($path);
            pf_insert($path, 'media', '', $user, $fid, $list === 'words' && ($field === '' || $field === rec_primary()) ? 'הובאה מהאתר' : 'הקלטה');
        });
    } catch (Throwable $e) {
        devlog_write('warn', 'audio', 'tree_link', ['message' => 'ההקלטה הובאה, אך לא נוספה לעץ הפרויקט: ' . $e->getMessage(), 'user' => $user, 'data' => ['list' => $list, 'word_id' => $wid, 'field' => $field]]);
    }
}

/** השהה · המשך · עצור · נסה שוב (אחת, או כל מה שנכשל). */
function rec_job_op(array $owner, array $b): array
{
    $op = (string)($b['op'] ?? '');
    $j = rec_job();
    switch ($op) {
        case 'pause':
            if (($j['status'] ?? '') === 'running') rec_job_set(['status' => 'paused'] + $j);
            break;
        case 'resume':
            if (in_array($j['status'] ?? '', ['paused', 'running', 'full'], true)) {
                if (($j['phase'] ?? '') === 'fetch' && ($j['status'] ?? '') === 'full' && rec_free() <= 0) throw new ApiError('תקרת הדיסק עדיין מלאה', 507);
                rec_job_set(['status' => 'running'] + $j);
            }
            break;
        case 'stop':
            if (in_array($j['status'] ?? '', ['running', 'paused', 'full'], true)) {
                rec_job_set(['status' => 'stopped', 'finished' => now_iso()] + $j);
                devlog_write('info', 'audio', $j['phase'] . '_stop', ['message' => 'נעצר בידי ' . $owner['name'], 'user' => $owner]);
            }
            break;
        case 'retry':
            $wid = (int)($b['word_id'] ?? 0);
            $st = rec_db()->prepare("UPDATE recordings SET state = 'site', f_code = '', f_note = '' WHERE state = 'failed'" . ($wid ? ' AND word_id = ?' : ''));
            $st->execute($wid ? [$wid] : []);
            if (!$st->rowCount()) throw new ApiError('אין מה לנסות שוב');
            rec_job_set(['phase' => 'fetch', 'status' => 'running', 'started' => $j['started'] ?? now_iso(), 'by' => $owner['name']] + $j);
            audit_as($owner, 'rec_fetch', 'נסה שוב · ' . ($wid ? "#$wid" : 'כל מה שנכשל'));
            break;
        default:
            throw new ApiError('פעולה לא מוכרת');
    }
    return rec_status($owner);
}
