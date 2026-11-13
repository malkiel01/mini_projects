<?php
/**
 * מאגר הפרויקט — פרוסה F2b (docs/spec-v2/slices/F2-project-store-and-code.md §3, הקריטריונים [b]):
 * שכפול; גרסה של קובץ (קריאה, שחזור); חיפוש והחלפה בכל הקבצים; מעקב הפניות בשינוי שם ובמחיקה; ZIP הלוך-חזור.
 *
 * אותם כללים כמו ב-lib/project.php: ע1 — מה שנשמר הוא מה שנקרא; אין סינון של תוכן. המקום היחיד שבו הקוד *כותב* טקסט
 * משלו לתוך קובץ של בעל העמוד הוא עדכון ההפניות — ושם הערך החדש הוא נתיב בלבד, מקודד כך שאינו יכול לצאת מההקשר
 * שלו (מרכאות, <, >, סוגריים, רווחים — pf_ref_enc). אין נתיב חדש תחת data/: קובצי ZIP זמניים — בתיקייה הזמנית של
 * המערכת, ונמחקים בסוף הבקשה.
 */

declare(strict_types=1);

require_once __DIR__ . '/project.php';

const PF_SEARCH_MAX = 1000;          // התאמות לכל היותר בתשובה אחת
const PF_SEARCH_SECONDS = 4.0;       // תקציב זמן לחיפוש (ביטוי רגולרי כבד) — אחריו התשובה מסומנת "קוצר"
const PF_ZIP_LIST = 500;             // שורות לכל היותר בכל רשימה של תצוגת הייבוא

// ── עזרים ─────────────────────────────────────────────────

/** כל קובצי הטקסט החיים: [שורה, תוכן]. קובץ בינארי (לא UTF-8, או עם NUL) — לא נכלל. */
function pf_texts(): array
{
    $out = [];
    foreach (db()->query("SELECT * FROM pf_files WHERE deleted_at IS NULL AND kind = 'file' ORDER BY path_key")->fetchAll() as $r) {
        if (!is_file(pf_blob_path($r['sha']))) continue;
        $s = pf_blob_get($r['sha']);
        if (!mb_check_encoding($s, 'UTF-8') || str_contains($s, "\0")) continue;
        $out[] = [$r, $s];
    }
    return $out;
}

/** טקסט קצר לתיאור (הערת גרסה, יומן): בלי תווי בקרה, מקוצר. מוצג תמיד כטקסט. */
function pf_label(string $s, int $max = 60): string
{
    $s = trim(preg_replace('/\p{Cc}+/u', ' ', $s) ?? '');
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
}

/** כתיבת תוכן חדש לשורה קיימת (rev + 1, שורת היסטוריה). בתוך pf_tx. */
function pf_put(array $r, string $bytes, array $user, string $note): array
{
    if (strlen($bytes) > ($max = pf_limit('text'))) pf_over("\"{$r['path']}\" גדול מדי אחרי השינוי", strlen($bytes), $max);
    $sha = pf_blob_put($bytes);
    if ($sha === $r['sha'] && $r['kind'] === 'file') return $r;
    db()->prepare("UPDATE pf_files SET kind = 'file', file_id = NULL, sha = ?, size = ?, rev = rev + 1, updated_by = ?, updated_name = ?, updated_at = ? WHERE id = ?")
        ->execute([$sha, strlen($bytes), $user['sub'], $user['name'] ?? '', now_iso(), $r['id']]);
    $r = pf_row((int)$r['id']);
    pf_hist($r, $user, $note);
    return $r;
}

/** מספר שורה (מ-1) ועמודה (בתווים, מ-1) של מיקום בבייטים. $nl — מיקומי השורות החדשות (pf_lines). */
function pf_pos(array $nl, string $s, int $off): array
{
    $lo = 0; $hi = count($nl);
    while ($lo < $hi) { $mid = ($lo + $hi) >> 1; if ($nl[$mid] < $off) $lo = $mid + 1; else $hi = $mid; }
    $start = $lo ? $nl[$lo - 1] + 1 : 0;
    return [$lo + 1, mb_strlen(substr($s, $start, $off - $start)) + 1, $start];
}

function pf_lines(string $s): array
{
    preg_match_all('/\n/', $s, $m, PREG_OFFSET_CAPTURE);
    return array_column($m[0], 1);
}

/** השורה שמכילה מיקום — לתצוגה (מקוצרת סביב ההתאמה). */
function pf_line_at(string $s, int $start, int $off, int $len): string
{
    $end = strpos($s, "\n", $start);
    $line = rtrim(substr($s, $start, ($end === false ? strlen($s) : $end) - $start), "\r");
    $pre = mb_strlen(substr($s, $start, $off - $start));
    $from = max(0, $pre - 60);
    $t = mb_substr($line, $from, 200);
    return ($from ? '…' : '') . $t . (mb_strlen($line) > $from + 200 ? '…' : '');
}

// ── שכפול ─────────────────────────────────────────────────

/** שכפול קובץ או תיקייה (כל מה שבתוכה) לנתיב חדש. התוכן משותף (אותו sha); לעותק היסטוריה משלו מגרסה 1. */
function pf_copy(array $user, array $b): array
{
    pf_guard($user);
    $to = pf_path($b['to'] ?? null);
    if (pf_is_words($b)) {                     // N1: שכפול של הקובץ של טבלת המילים = קובץ JSON רגיל (עותק סטטי), לא קשור לטבלה
        $r = pf_tx(function () use ($to, $user) {
            pf_free($to);
            if (($n = pf_count()) >= ($max = pf_limit('files'))) pf_over('יותר מדי קבצים בפרויקט', $n + 1, $max);
            $int = pf_words_internal();
            return pf_insert($to, 'file', words_json(), $user, null, 'עותק סטטי של טבלת המילים — אינו מתעדכן ואינו מעדכן' . ($int ? ' · כולל שדות פנימיים (' . implode(', ', array_column($int, 'key')) . ') — יתפרסם כקובץ רגיל' : ''));
        });
        audit_as($user, 'pf_copy', "טבלת המילים ← $to (עותק סטטי)");
        // N2: העותק כולל גם שדות פנימיים — והוא יתפרסם כקובץ רגיל
        $int = pf_words_internal();
        $warn = $int ? 'העותק כולל ' . (count($int) === 1 ? 'שדה פנימי אחד' : count($int) . ' שדות פנימיים') . ' (' . implode(', ', array_column($int, 'key')) . '), והוא יתפרסם כקובץ רגיל' : null;
        return ['copied' => ['from' => pf_words_path(), 'to' => $to, 'count' => 1], 'file' => pf_public($r), 'static_copy' => true, 'warning' => $warn];
    }
    if (($lk = pf_list_of($b)) !== null) {        // N3: כמו המילים — עותק סטטי של הרשימה
        $int = pf_list_file($lk)['internal'];
        $r = pf_tx(function () use ($to, $user, $lk, $int) {
            pf_free($to);
            if (($n = pf_count()) >= ($max = pf_limit('files'))) pf_over('יותר מדי קבצים בפרויקט', $n + 1, $max);
            return pf_insert($to, 'file', list_json($lk), $user, null, "עותק סטטי של הרשימה $lk — אינו מתעדכן ואינו מעדכן" . ($int ? ' · כולל שדות פנימיים (' . implode(', ', array_column($int, 'key')) . ') — יתפרסם כקובץ רגיל' : ''));
        });
        audit_as($user, 'pf_copy', "הרשימה $lk ← $to (עותק סטטי)");
        $warn = $int ? 'העותק כולל ' . (count($int) === 1 ? 'שדה פנימי אחד' : count($int) . ' שדות פנימיים') . ' (' . implode(', ', array_column($int, 'key')) . '), והוא יתפרסם כקובץ רגיל' : null;
        return ['copied' => ['from' => pf_list_file($lk)['path'], 'to' => $to, 'count' => 1], 'file' => pf_public($r), 'static_copy' => true, 'warning' => $warn];
    }
    [$from, $first, $n] = pf_tx(function () use ($b, $to, $user) {
        [$from, $rows, $dir] = pf_target($b);
        if ($dir && str_starts_with($to . '/', $from . '/')) throw new ApiError('אי אפשר לשכפל תיקייה לתוך עצמה');
        if ($dir && (pf_by_path($to) || pf_children($to))) throw new ApiError("כבר יש \"$to\"", 409);
        $add = count(array_filter($rows, fn($r) => $r['kind'] === 'file'));
        if (($c = pf_count()) + $add > ($max = pf_limit('files'))) pf_over('יותר מדי קבצים בפרויקט', $c + $add, $max);
        $now = now_iso();
        $ins = db()->prepare('INSERT INTO pf_files (path, path_key, kind, sha, size, file_id, rev, created_by, created_at, updated_by, updated_name, updated_at)
                              VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)');
        $first = null;
        foreach ($rows as $r) {
            $new = pf_path($to . substr($r['path'], strlen($from)));
            pf_free($new, [], $r['kind'] === 'dir');
            if ($r['kind'] === 'file' && !is_file(pf_blob_path($r['sha']))) throw new ApiError("תוכן \"{$r['path']}\" חסר בשרת", 500);
            $ins->execute([$new, pf_key($new), $r['kind'], $r['sha'], $r['size'], $r['file_id'], $user['sub'], $now, $user['sub'], $user['name'] ?? '', $now]);
            $c = pf_row((int)db()->lastInsertId());
            if ($c['kind'] !== 'dir') pf_hist($c, $user, "שוכפל מ-{$r['path']}");
            $first ??= $c;
        }
        return [$from, $first, count($rows)];
    });
    audit_as($user, 'pf_copy', "$from ← $to" . ($n > 1 ? " · $n פריטים" : ''));
    return ['copied' => ['from' => $from, 'to' => $to, 'count' => $n], 'file' => $first ? pf_public($first) : null];
}

// ── גרסה של קובץ: קריאה ושחזור ───────────────────────────

function pf_hist_row(int $fileId, mixed $hid): array
{
    $st = db()->prepare('SELECT * FROM pf_history WHERE id = ? AND file_id = ?');
    $st->execute([(int)$hid, $fileId]);
    $h = $st->fetch();
    if (!$h) throw new ApiError('הגרסה לא נמצאה (אולי נמחקה — ההיסטוריה מוגבלת)', 404);
    if (!is_file(pf_blob_path($h['sha']))) throw new ApiError('תוכן הגרסה חסר בשרת', 404);
    return $h;
}

/** תוכן של גרסה ישנה — כנתונים (JSON), להשוואה בעורך. כמו pf_read. */
function pf_version(array $q): array
{
    $r = pf_row((int)($q['id'] ?? 0));
    if ($r['kind'] !== 'file') throw new ApiError('לקובץ הזה אין גרסאות תוכן');
    $h = pf_hist_row((int)$r['id'], $q['hid'] ?? 0);
    return ['version' => ['hid' => (int)$h['id'], 'rev' => (int)$h['rev'], 'at' => $h['at'], 'by' => $h['name'], 'path' => $h['path']]] + pf_payload(pf_blob_get($h['sha']));
}

/** שחזור גרסה: התוכן שלה נשמר כגרסה *חדשה* (ההיסטוריה אינה נמחקת). rev ישן — 409, כמו בשמירה. */
function pf_version_restore(array $user, array $b): array
{
    pf_guard($user);
    $r = pf_tx(function () use ($b, $user) {
        $r = pf_row((int)($b['id'] ?? 0));
        if ($r['kind'] !== 'file') throw new ApiError('לקובץ הזה אין גרסאות תוכן');
        if ((int)($b['rev'] ?? 0) !== (int)$r['rev']) {
            throw new ApiError('הקובץ השתנה בינתיים', 409, ['conflict' => ['file' => pf_public($r)] + pf_payload(pf_blob_get($r['sha']))]);
        }
        $h = pf_hist_row((int)$r['id'], $b['hid'] ?? 0);
        if ($h['sha'] === $r['sha']) throw new ApiError('הגרסה הזו זהה לתוכן הנוכחי — אין מה לשחזר');
        return pf_put($r, pf_blob_get($h['sha']), $user, "שוחזר מגרסה {$h['rev']}");
    });
    audit_as($user, 'pf_version_restore', "{$r['path']} · גרסה {$r['rev']}");
    $out = ['file' => pf_public($r)] + pf_payload(pf_blob_get($r['sha']));
    if ($r['path'] === 'kol.json') $out['kol'] = pf_kol();
    return $out;
}

// ── חיפוש והחלפה בכל הקבצים ──────────────────────────────

/**
 * הביטוי לחיפוש. טקסט רגיל — preg_quote; ביטוי רגולרי — כמו שהוא, עם תוחם \x01 (שאינו יכול להופיע בביטוי, ולכן אין
 * דרך להוסיף דגלים מבחוץ). תמיד /u; בלי רישיות — /i. ביטוי שגוי — הודעה, לא חריגה.
 */
function pf_rx(array $b): string
{
    $q = $b['q'] ?? '';
    if (!is_string($q) || $q === '') throw new ApiError('מה לחפש?');
    if (!mb_check_encoding($q, 'UTF-8')) throw new ApiError('החיפוש אינו טקסט תקין');
    if (strlen($q) > 1000) throw new ApiError('החיפוש ארוך מדי (עד 1000 תווים)');
    $flags = 'u' . (empty($b['case']) ? 'i' : '');
    if (!empty($b['regex'])) {
        if (str_contains($q, "\x01")) throw new ApiError('הביטוי מכיל תו לא חוקי');
        $rx = "\x01" . $q . "\x01m" . $flags;           // m — ^ ו-$ לכל שורה, כמו בחיפוש בעורך
    } else $rx = '/' . preg_quote($q, '/') . '/' . $flags;
    if (@preg_match($rx, '') === false) throw new ApiError('הביטוי הרגולרי לא תקין: ' . (error_get_last()['message'] ?? preg_last_error_msg()));
    return $rx;
}

/** מגבלת הצעדים של PCRE — ביטוי "קטסטרופלי" נעצר בשגיאה במקום לתקוע את השרת. */
function pf_rx_guard(): void
{
    ini_set('pcre.backtrack_limit', '200000');
    ini_set('pcre.recursion_limit', '20000');
}

function pf_rx_fail(string $path): never
{
    throw new ApiError("הביטוי כבד מדי לחיפוש (נעצר ב-\"$path\": " . preg_last_error_msg() . '). פשטו אותו', 422);
}

/** חיפוש בכל קובצי הטקסט: קובץ, שורה, עמודה, והשורה עצמה (טקסט). */
function pf_search(array $q): array
{
    $rx = pf_rx($q);
    pf_rx_guard();
    $t0 = microtime(true);
    $files = []; $total = 0; $cut = false;
    foreach (pf_texts() as [$r, $s]) {
        if ($total >= PF_SEARCH_MAX || microtime(true) - $t0 > PF_SEARCH_SECONDS) { $cut = true; break; }
        $n = preg_match_all($rx, $s, $m, PREG_OFFSET_CAPTURE);
        if ($n === false) pf_rx_fail($r['path']);
        if (!$n) continue;
        $nl = pf_lines($s);
        $hits = [];
        foreach ($m[0] as [$text, $off]) {
            if ($total >= PF_SEARCH_MAX) { $cut = true; break; }
            [$line, $col, $start] = pf_pos($nl, $s, $off);
            $hits[] = ['line' => $line, 'col' => $col, 'len' => mb_strlen($text), 'text' => pf_line_at($s, $start, $off, strlen($text))];
            $total++;
        }
        $files[] = ['id' => (int)$r['id'], 'path' => $r['path'], 'rev' => (int)$r['rev'], 'count' => $n, 'hits' => $hits];
    }
    return ['files' => $files, 'total' => $total, 'cut' => $cut];
}

/**
 * החלפה בכל הקבצים. בלי apply — תצוגה מקדימה: כמה בכל קובץ, ודוגמאות "לפני / אחרי". עם apply — נדרשים revs ({id: rev})
 * מהתצוגה המקדימה: קובץ שהשתנה בינתיים — 409, ושום דבר לא נכתב. לפני הכתיבה — נקודת שחזור; כל קובץ מקבל גרסה חדשה.
 * ids — רק הקבצים האלה (בעל העמוד הוריד סימון מקובץ בתצוגה המקדימה).
 */
function pf_replace(array $user, array $b): array
{
    $rx = pf_rx($b);
    $with = $b['with'] ?? '';
    if (!is_string($with) || !mb_check_encoding($with, 'UTF-8')) throw new ApiError('טקסט ההחלפה אינו תקין');
    $repl = !empty($b['regex']) ? $with : str_replace(['\\', '$'], ['\\\\', '\\$'], $with);   // טקסט רגיל — בלי $1 / \1
    $only = isset($b['ids']) ? array_map('intval', (array)$b['ids']) : null;
    $apply = !empty($b['apply']);
    if ($apply) pf_guard($user);
    pf_rx_guard();
    $plan = [];
    foreach (pf_texts() as [$r, $s]) {
        if ($only !== null && !in_array((int)$r['id'], $only, true)) continue;
        $new = preg_replace($rx, $repl, $s, -1, $n);
        if ($new === null) pf_rx_fail($r['path']);
        if (!$n || $new === $s) continue;
        $plan[] = [$r, $s, $new, $n];
    }
    if (!$apply) {
        $out = [];
        foreach ($plan as [$r, $s, $new, $n]) {
            preg_match_all($rx, $s, $m, PREG_OFFSET_CAPTURE);
            $nl = pf_lines($s);
            $samples = [];
            foreach (array_slice($m[0], 0, 5) as [$text, $off]) {
                [$line, , $start] = pf_pos($nl, $s, $off);
                $end = strpos($s, "\n", $start);
                $ln = substr($s, $start, ($end === false ? strlen($s) : $end) - $start);
                $samples[] = ['line' => $line, 'before' => pf_line_at($s, $start, $off, strlen($text)),
                    'after' => pf_label((string)preg_replace($rx, $repl, $ln), 260)];
            }
            $out[] = ['id' => (int)$r['id'], 'path' => $r['path'], 'rev' => (int)$r['rev'], 'count' => $n, 'samples' => $samples];
        }
        return ['files' => $out, 'total' => array_sum(array_column($out, 'count'))];
    }
    $revs = is_array($b['revs'] ?? null) ? $b['revs'] : [];
    $stale = [];
    foreach ($plan as [$r]) if ((int)($revs[$r['id']] ?? $revs[(string)$r['id']] ?? -1) !== (int)$r['rev']) $stale[] = $r['path'];
    if ($stale) throw new ApiError('קבצים השתנו מאז התצוגה המקדימה: ' . implode(', ', array_slice($stale, 0, 10)) . '. הציגו שוב', 409, ['stale' => $stale]);
    if (!$plan) return ['replaced' => 0, 'files' => []];
    require_once __DIR__ . '/restore.php';
    $snap = snapshot_create($user, 'לפני החלפה בכל הקבצים');
    $note = 'החלפה בכל הקבצים: «' . pf_label((string)$b['q'], 40) . '» ← «' . pf_label($with, 40) . '»';
    $done = pf_tx(function () use ($plan, $user, $note) {
        $done = [];
        foreach ($plan as [$r, , $new, $n]) {
            $cur = pf_row((int)$r['id']);
            if ($cur['sha'] !== $r['sha']) throw new ApiError("\"{$r['path']}\" השתנה בינתיים", 409);
            $w = pf_put($cur, $new, $user, $note);
            $done[] = ['id' => (int)$w['id'], 'path' => $w['path'], 'rev' => (int)$w['rev'], 'count' => $n];
        }
        return $done;
    });
    $total = array_sum(array_column($done, 'count'));
    audit_as($user, 'pf_replace', pf_label((string)$b['q'], 40) . ' ← ' . pf_label($with, 40) . " · $total החלפות ב-" . count($done) . ' קבצים');
    return ['replaced' => $total, 'files' => $done, 'snapshot' => $snap];
}

// ── הפניות בין קבצים (href, src, url(), import) ─────────────

const PF_REF_HTML = '/\b(?:href|src|srcset|action|formaction|poster|data|xlink:href|background)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\'`=]+))/i';
const PF_REF_CSS = '/\burl\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)\s"\']+))\s*\)|@import\s+(?:"([^"]*)"|\'([^\']*)\')/i';
const PF_REF_JS = '/(?:\b(?:import|export)\b[^\'";]*?\bfrom\s*|\bimport\s*\(\s*|\bimport\s+)(?:"([^"\n]*)"|\'([^\'\n]*)\')/';
const PF_REF_ENTRY = '/"entry"\s*:\s*"([^"\\\\]*)"/';

/** הפניות בקובץ: [מיקום בבייטים, אורך, הערך (רק החלק של הנתיב), סוג]. */
function pf_ref_scan(string $path, string $s): array
{
    $x = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $html = in_array($x, ['html', 'htm', 'xhtml', 'svg', 'xml', 'php'], true);
    $sets = [];
    if ($html) $sets[] = [PF_REF_HTML, 'attr'];
    if ($html || in_array($x, ['css', 'scss', 'less'], true)) $sets[] = [PF_REF_CSS, 'css'];
    if ($html || in_array($x, ['js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx'], true)) $sets[] = [PF_REF_JS, 'js'];
    if ($path === 'kol.json') $sets[] = [PF_REF_ENTRY, 'entry'];
    $out = [];
    foreach ($sets as [$rx, $kind]) {
        if (!preg_match_all($rx, $s, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;
        foreach ($m as $g) {
            $v = null;
            for ($i = 1; $i < count($g); $i++) if (isset($g[$i]) && $g[$i][1] >= 0) { $v = $g[$i]; break; }
            if (!$v) continue;
            [$val, $off] = $v;
            $isSet = $kind === 'attr' && stripos($g[0][0], 'srcset') === 0;
            $parts = $isSet ? pf_srcset_parts($val) : [[0, $val]];
            foreach ($parts as [$o, $u]) {
                $lead = strlen($u) - strlen(ltrim($u));
                $u = trim($u);
                $p = preg_split('/[?#]/', $u, 2)[0];            // ?query / #hash — נשארים כמו שהם
                if ($p === '') continue;
                if ($kind === 'js' && !preg_match('#^(\.{1,2}/|/)#', $p)) continue;   // "react" — לא נתיב בפרויקט
                $out[] = [$off + $o + $lead, strlen($p), $p, $kind];
            }
        }
    }
    usort($out, fn($a, $b) => $a[0] <=> $b[0]);
    return $out;
}

/** srcset: "a.png 1x, b.png 2x" — כל כתובת ומיקומה בתוך הערך. */
function pf_srcset_parts(string $v): array
{
    $out = [];
    preg_match_all('/(?:^|,)\s*([^\s,]+)/', $v, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as [$u, $o]) $out[] = [$o, $u];
    return $out;
}

/** לאן מפנה ערך מתוך קובץ: נתיב בפרויקט, או null (כתובת חיצונית, #עוגן, data:, ויציאה מעל השורש). */
function pf_ref_resolve(string $from, string $v, string $kind): ?string
{
    if ($v === '' || $v[0] === '#' || str_starts_with($v, '//') || preg_match('/^[a-z][a-z0-9+.\-]*:/i', $v)) return null;
    if ($kind === 'attr') $v = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // kol.json: נתיב בפרויקט (לא כתובת) — בלי פענוח %XX, ומהשורש
    $v = $kind === 'entry' ? '/' . ltrim($v, '/') : rawurldecode($v);
    $segs = $v[0] === '/' ? [] : array_slice(explode('/', $from), 0, -1);
    foreach (explode('/', $v) as $s) {
        if ($s === '' || $s === '.') continue;
        if ($s === '..') { if (!$segs) return null; array_pop($segs); continue; }
        $segs[] = $s;
    }
    return $segs ? implode('/', $segs) : null;
}

/**
 * נתיב כערך בקובץ — מקודד. שם קובץ חופשי (יכול להכיל " ' < > ( ) רווח # ? & , ;) לעולם אינו יוצא מההקשר שלו
 * (מאפיין HTML, url() של CSS, מחרוזת של JS) — כל תו כזה נכתב כ-%XX. עברית ואמוג'י נשארים כמו שהם.
 */
function pf_ref_enc(string $p): string
{
    return (string)preg_replace_callback('/[\x00-\x20\x7F%"\'<>()`#?&\\\\,;{}|^\[\]]/', fn($m) => sprintf('%%%02X', ord($m[0])), $p);
}

function pf_rel(string $fromFile, string $to): string
{
    $a = array_slice(explode('/', $fromFile), 0, -1);
    $b = explode('/', $to);
    while ($a && count($b) > 1 && $a[0] === $b[0]) { array_shift($a); array_shift($b); }
    return str_repeat('../', count($a)) . implode('/', $b);
}

/** הערך החדש להפניה: באותו סגנון כמו הישן (מהשורש / יחסי / ./), אל היעד החדש, מהמקום החדש של הקובץ המפנה. */
function pf_ref_value(string $old, string $fileNew, string $targetNew, string $kind): string
{
    $slash = str_ends_with($old, '/') ? '/' : '';
    if ($kind === 'entry') return substr((string)json_encode($targetNew, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);   // מחרוזת JSON תקינה
    if ($old[0] === '/') return '/' . pf_ref_enc($targetNew) . $slash;
    $rel = pf_ref_enc(pf_rel($fileNew, $targetNew)) . $slash;
    if ((str_starts_with($old, './') || $kind === 'js') && !str_starts_with($rel, '../')) $rel = './' . $rel;
    return $rel;
}

/** האם נתיב קיים בפרויקט (קובץ, או תיקייה — גם תיקייה שקיימת רק כי יש בה קבצים). */
function pf_exists_in(array $live, string $p): bool
{
    if (isset($live[$p])) return true;
    foreach ($live as $q => $_) if (str_starts_with($q, $p . '/')) return true;
    return false;
}

/**
 * מה נשבר אם $from עובר ל-$to (null — נמחק): כל הפניה שהיום מגיעה ליעד קיים, ואחרי הפעולה כבר לא.
 * לכל אחת: הקובץ, השורה, הערך הישן, ובשינוי שם — הערך החדש המוצע.
 */
function pf_refs_plan(string $from, ?string $to): array
{
    $live = array_flip(array_column(db()->query('SELECT path FROM pf_files WHERE deleted_at IS NULL')->fetchAll(), 'path'));
    $in = fn(string $p) => $p === $from || str_starts_with($p, $from . '/');
    $map = fn(string $p) => $to !== null && $in($p) ? $to . substr($p, strlen($from)) : $p;
    $out = [];
    foreach (pf_texts() as [$r, $s]) {
        $fo = $r['path'];
        if ($to === null && $in($fo)) continue;                         // הקובץ המפנה נמחק בעצמו
        $fn = $map($fo);
        $nl = null;
        foreach (pf_ref_scan($fo, $s) as [$off, $len, $val, $kind]) {
            $t = pf_ref_resolve($fo, $val, $kind);
            if ($t === null || !pf_exists_in($live, $t)) continue;      // כבר שבורה, או חיצונית — לא בגללנו
            if ($to === null) { if (!$in($t)) continue; $new = null; }
            else {
                $tn = $map($t);
                if (pf_ref_resolve($fn, $val, $kind) === $tn) continue;   // עדיין מגיעה (למשל: שניהם זזו יחד)
                $new = pf_ref_value($val, $fn, $tn, $kind);
                if ($new === $val) continue;
            }
            $nl ??= pf_lines($s);
            [$line, $col, $start] = pf_pos($nl, $s, $off);
            $out[] = ['id' => (int)$r['id'], 'path' => $fo, 'rev' => (int)$r['rev'], 'line' => $line, 'col' => $col, 'kind' => $kind,
                'old' => $val, 'new' => $new, 'text' => pf_line_at($s, $start, $off, $len), 'off' => $off, 'len' => $len, 'sha' => $r['sha']];
        }
    }
    return $out;
}

/** ההפניות לקובץ / תיקייה, לפני שינוי שם (to) או מחיקה (בלי to). קריאה בלבד. */
function pf_refs(array $q): array
{
    [$from] = pf_target($q);
    $to = isset($q['to']) && $q['to'] !== '' ? pf_path($q['to']) : null;
    $plan = pf_refs_plan($from, $to);
    return ['from' => $from, 'to' => $to, 'refs' => array_map(fn($x) => array_diff_key($x, ['off' => 1, 'len' => 1, 'sha' => 1]), $plan)];
}

/** כתיבת התוכנית: בכל קובץ, מהסוף להתחלה (המיקומים אינם זזים). בתוך pf_tx, אחרי שהשורות כבר זזו. */
function pf_refs_apply(array $plan, array $user, string $note): int
{
    $by = [];
    foreach ($plan as $x) if ($x['new'] !== null) $by[$x['id']][] = $x;
    $n = 0;
    foreach ($by as $id => $xs) {
        $r = pf_row((int)$id);
        if ($r['sha'] !== $xs[0]['sha']) continue;                       // השתנה בתוך הבקשה — לא נוגעים
        $s = pf_blob_get($r['sha']);
        usort($xs, fn($a, $b) => $b['off'] <=> $a['off']);
        foreach ($xs as $x) { $s = substr_replace($s, $x['new'], $x['off'], $x['len']); $n++; }
        pf_put($r, $s, $user, $note);
    }
    return $n;
}

// ── ZIP ───────────────────────────────────────────────────

function pf_zip_need(): void
{
    if (!class_exists('ZipArchive')) throw new ApiError('ZIP אינו זמין בשרת הזה (חסרה ההרחבה zip של PHP). פנו למפתח', 501);
}

/** כל הפרויקט כ-ZIP: כל קובץ בייט בבייט (כולל מדיה מהספרייה), ותיקיות ריקות. מוגש כקובץ מצורף בלבד. */
function pf_zip(array $user): never
{
    pf_zip_need();
    pf_seed($user);
    $tmp = tempnam(sys_get_temp_dir(), 'kolzip');
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) throw new ApiError('יצירת ה-ZIP נכשלה', 500);
    $n = 0;
    foreach (db()->query('SELECT * FROM pf_files WHERE deleted_at IS NULL ORDER BY path_key')->fetchAll() as $r) {
        if ($r['kind'] === 'dir') { $z->addEmptyDir($r['path']); $n++; continue; }
        $src = $r['kind'] === 'media' ? (function () use ($r) { try { return file_path(file_row((string)$r['file_id'])); } catch (Throwable) { return ''; } })() : pf_blob_path($r['sha']);
        if ($src === '' || !is_file($src)) continue;                  // מדיה שנמחקה מהספרייה — אין בייטים לארוז
        $z->addFile($src, $r['path']);
        $z->setCompressionName($r['path'], preg_match('/\.(png|jpe?g|gif|webp|avif|mp3|m4a|aac|ogg|mp4|webm|woff2?|zip|gz)$/i', $r['path']) ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE);
        $n++;
    }
    if (($wp = pf_words_path()) !== null && !pf_by_path($wp)) { $z->addFromString($wp, words_json()); $n++; }   // N1: הקובץ של טבלת המילים
    foreach (pf_list_paths() as $lp => $lk) if ($lk !== 'words' && !pf_by_path($lp)) { $z->addFromString($lp, list_json($lk)); $n++; }   // N3: הקובץ של כל רשימה
    if (!$z->close() || !$n) file_put_contents($tmp, "PK\x05\x06" . str_repeat("\0", 18));   // פרויקט ריק — ZIP ריק תקין
    audit_as($user, 'pf_zip', "$n פריטים");
    $name = 'kol-project-' . gmdate('Y-m-d') . '.zip';
    header_remove('Content-Type');
    header('Content-Type: application/zip');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: sandbox; default-src 'none'");
    header('Cache-Control: private, no-store');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/**
 * פתיחת ZIP שהועלה — בזהירות. נדחה *כולו*: נתיב שיוצא מהתיקייה (../, נתיב מוחלט, C:, \), קישור סימבולי או קובץ
 * מיוחד, הצפנה, שם שאינו UTF-8, שני שמות שנבדלים ברישיות, קובץ ותיקייה באותו שם, יותר קבצים מהמגבלה, או גודל כולל
 * אחרי פתיחה מעל המגבלה (פצצת ZIP). הקריאה של כל קובץ חסומה בגודל שהוצהר + 1 — הצהרה כוזבת נתפסת ונדחית.
 * קובץ בודד מעל מגבלת הגודל — לא ייובא, ומופיע ברשימה (בלי לעצור את השאר).
 */
function pf_zip_open(string $raw): array
{
    pf_zip_need();
    if (strlen($raw) > ($max = pf_limit('zip'))) pf_over('ה-ZIP גדול מדי', strlen($raw), $max);
    if ($raw === '') throw new ApiError('לא התקבל קובץ');
    $tmp = tempnam(sys_get_temp_dir(), 'kolzip');
    try {
        file_put_contents($tmp, $raw);
        $z = new ZipArchive();
        if ($z->open($tmp, ZipArchive::RDONLY) !== true) throw new ApiError('הקובץ אינו ZIP תקין');
        $bad = fn(string $why) => throw new ApiError("ה-ZIP נדחה כולו: $why. שום דבר לא נכתב", 422);
        if ($z->numFiles > ($lim = pf_limit('files'))) pf_over('יותר מדי קבצים ב-ZIP', $z->numFiles, $lim);
        $files = []; $dirs = []; $skipped = []; $big = []; $keys = []; $total = 0;
        $text = pf_limit('text'); $unzip = pf_limit('unzip');
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i);
            if ($st === false) $bad('רשומה לא קריאה');
            $name = (string)$st['name'];
            if (!mb_check_encoding($name, 'UTF-8')) $bad('יש בו שם קובץ שאינו בקידוד UTF-8');
            if (str_contains($name, '\\')) $bad("נתיב עם \\ (\"$name\")");
            if ($name === '' || $name[0] === '/' || preg_match('/^[a-z]:/i', $name)) $bad("נתיב מוחלט (\"$name\")");
            if (preg_match('/\p{Cc}/u', $name)) $bad('נתיב עם תו בקרה');
            foreach (explode('/', rtrim($name, '/')) as $seg) if ($seg === '..' || $seg === '.') $bad("נתיב שיוצא מהתיקייה (\"$name\")");
            if (($st['encryption_method'] ?? 0) !== 0) $bad("קובץ מוצפן (\"$name\")");
            if ($z->getExternalAttributesIndex($i, $os, $attr) && $os === ZipArchive::OPSYS_UNIX) {
                $type = ($attr >> 16) & 0170000;
                if ($type === 0120000) $bad("קישור סימבולי (\"$name\")");
                if ($type !== 0 && $type !== 0100000 && $type !== 040000) $bad("קובץ מיוחד (\"$name\")");
            }
            $isDir = str_ends_with($name, '/');
            $path = rtrim($name, '/');
            if (str_starts_with($path . '/', '__MACOSX/') || basename($path) === '.DS_Store') { $skipped[] = $name; continue; }
            try { pf_path($path); } catch (ApiError $e) { $bad("\"$name\": " . $e->getMessage()); }
            $key = pf_key($path);
            if (isset($keys[$key])) {
                if ($isDir && $keys[$key] === 'dir') continue;
                $bad("שני שמות זהים (או שנבדלים רק ברישיות): \"$path\"");
            }
            $keys[$key] = $isDir ? 'dir' : 'file';
            if ($isDir) { $dirs[] = $path; continue; }
            $size = (int)$st['size'];
            if (($total += $size) > $unzip) pf_over('ה-ZIP גדול מדי אחרי פתיחה', $total, $unzip);
            if ($size > $text) { $big[] = ['path' => $path, 'size' => $size, 'limit' => $text]; continue; }
            $bytes = $size === 0 ? '' : $z->getFromIndex($i, $size + 1);
            if ($size === 0) { $s = $z->getStream($name); $peek = $s ? fread($s, 1) : ''; if ($s) fclose($s); if ($peek !== '' && $peek !== false) $bad("גודל מוצהר שגוי (\"$path\")"); }
            if ($bytes === false) $bad("\"$path\" לא נקרא — ZIP פגום");
            if (strlen($bytes) !== $size) $bad("גודל מוצהר שגוי (\"$path\") — ZIP פגום או זדוני");
            $files[$path] = $bytes;
        }
        $z->close();
        // קובץ ותיקייה באותו נתיב ("a" ו-"a/b")
        $all = array_merge(array_keys($files), $dirs);
        $fk = []; foreach (array_keys($files) as $p) $fk[pf_key($p)] = $p;
        foreach ($all as $p) {
            $parts = explode('/', pf_key($p));
            for ($j = 1; $j < count($parts); $j++) if (isset($fk[$pre = implode('/', array_slice($parts, 0, $j))])) $bad("\"{$fk[$pre]}\" הוא גם קובץ וגם תיקייה");
        }
        return ['files' => $files, 'dirs' => $dirs, 'skipped' => $skipped, 'big' => $big];
    } finally {
        @unlink($tmp);
    }
}

/**
 * ייבוא ZIP. בלי apply — התוכנית: מה יתווסף, מה יוחלף, מה זהה, ומה בפרויקט לא ישתנה (קבצים שאינם ב-ZIP — אינם נמחקים).
 * עם apply ו-token מהתוכנית — ביצוע; לפני החלפה נוצרת נקודת שחזור. הפרויקט השתנה מאז התוכנית — 409.
 */
/**
 * T2 (V13 🟡A): scope — קידומות מופרדות בפסיק, כל אחת מסתיימת ב-/ (למשל "templates/hero/,media/"). כשהוא נשלח, כל קובץ, קובץ גדול
 * ותיקייה ב-ZIP חייבים להיות בתוכו (תיקייה — גם הורה של קידומת: "templates"); אחרת — הייבוא כולו נדחה, לפני כל תכנון. בלי scope — כמו קודם.
 */
function pf_zip_scope(array $zip, mixed $scope): void
{
    if ($scope === null || $scope === '') return;
    if (!is_string($scope) || strlen($scope) > 2000) throw new ApiError('scope לא תקין');
    $pre = [];
    foreach (explode(',', $scope) as $x) {
        if ($x === '' || !str_ends_with($x, '/')) throw new ApiError('scope לא תקין: כל קידומת מסתיימת ב-/');
        pf_path(substr($x, 0, -1));
        $pre[] = $x;
    }
    $in = function (string $p, bool $dir) use ($pre): bool {
        foreach ($pre as $x) if (str_starts_with($p . ($dir ? '/' : ''), $x) || ($dir && str_starts_with($x, $p . '/'))) return true;
        return false;
    };
    $out = [];
    foreach (array_keys($zip['files']) as $p) if (!$in((string)$p, false)) $out[] = $p;
    foreach ($zip['big'] as $b) if (!$in((string)$b['path'], false)) $out[] = $b['path'];
    foreach ($zip['dirs'] as $d) if (!$in((string)$d, true)) $out[] = $d . '/';
    if ($out) throw new ApiError('ב-ZIP יש קבצים מחוץ למותר (' . implode(', ', $pre) . '): ' . implode(', ', array_slice($out, 0, 5)) . (count($out) > 5 ? ' ועוד ' . (count($out) - 5) : ''), 400);
}

function pf_zip_import(array $user, array $q, string $raw): array
{
    pf_seed($user);
    $zip = pf_zip_open($raw);
    pf_zip_scope($zip, $q['scope'] ?? null);                                     // T2 (V13 🟡A): ייבוא תבנית — הגבול נאכף בשרת
    $rows = [];
    foreach (db()->query('SELECT * FROM pf_files WHERE deleted_at IS NULL')->fetchAll() as $r) $rows[$r['path']] = $r;
    $mediaSha = [];
    if ($ids = array_values(array_filter(array_column($rows, 'file_id')))) {
        $st = db()->prepare('SELECT id, sha256 FROM files WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $mediaSha = array_column($st->fetchAll(), 'sha256', 'id');
    }
    $add = []; $replace = []; $same = []; $errors = [];
    // N1: הקובץ של טבלת המילים (לפי kol.json שב-ZIP, אחרת לפי זה שבפרויקט) אינו נשמר כקובץ — עובר למנוע של הטבלה
    $kz = isset($zip['files']['kol.json']) ? json_decode($zip['files']['kol.json'], true) : null;
    $wd = is_array($kz) ? (is_array($kz['data'] ?? null) ? ($kz['data']['words'] ?? null) : null) : false;
    try { $wpath = $wd === false ? pf_words_path() : (is_array($wd) && ($wd['source'] ?? 'words') === 'words' ? pf_path($wd['file'] ?? 'data/words.json') : null); } catch (ApiError) { $wpath = null; }
    $words = null;
    if ($wpath !== null && isset($zip['files'][$wpath]) && !isset($rows[$wpath])) {
        $wb = $zip['files'][$wpath];
        unset($zip['files'][$wpath]);
        $here = pf_words_path() === $wpath;
        if ($wb === words_json()) $here ? $same[] = $wpath : $add[] = $wpath;
        else {
            try { $words = words_file_save($user, $wb, ['create_topics' => is_array($q['create_topics'] ?? null) ? $q['create_topics'] : []])['plan'] + ['path' => $wpath]; }
            catch (ApiError $e) { $errors[] = "\"$wpath\" (טבלת המילים) — " . $e->getMessage() . ': ' . implode(' · ', array_map(fn($x) => "שורה {$x['line']}: {$x['msg']}", array_slice($e->extra['errors'] ?? [], 0, 3))); }
            if ($words) $here ? $replace[$wpath] = ['words' => true] : $add[] = $wpath;
        }
    }
    // N3: הקובץ של רשימה — זהה לטבלה: "זהה"; שונה — אינו נשמר כקובץ רגיל (שמירה בעורך הקבצים, עם "מה ישתנה")
    foreach (pf_list_paths() as $lp => $lk) {
        if ($lk === 'words' || !isset($zip['files'][$lp]) || isset($rows[$lp])) continue;
        $lb = $zip['files'][$lp];
        unset($zip['files'][$lp]);
        if ($lb === list_json($lk)) $same[] = $lp;
        else $errors[] = "\"$lp\" (הרשימה $lk) — שונה מהטבלה. ייבוא של רשימה מ-ZIP עוד לא נתמך: הסירו אותו מה-ZIP, ושמרו אותו בעורך הקבצים (\"מה ישתנה\")";
    }
    foreach ($zip['files'] as $path => $bytes) {
        $sha = hash('sha256', $bytes);
        $r = $rows[$path] ?? null;
        if ($r && $r['kind'] === 'dir') { $errors[] = "\"$path\" — בפרויקט זו תיקייה"; continue; }
        if ($r) {
            $cur = $r['kind'] === 'media' ? ($mediaSha[$r['file_id']] ?? '') : $r['sha'];
            if ($cur === $sha) $same[] = $path; else $replace[$path] = $r;
            continue;
        }
        try { pf_free($path); } catch (ApiError $e) { $errors[] = "\"$path\" — " . $e->getMessage(); continue; }
        $add[] = $path;
    }
    foreach ($zip['big'] as $b) if (isset($rows[$b['path']]) && $rows[$b['path']]['kind'] === 'media') $same[] = $b['path'];   // מדיה גדולה שכבר בספרייה
    $newDirs = [];
    foreach ($zip['dirs'] as $d) {
        if (isset($rows[$d]) || pf_children($d)) continue;
        try { pf_free($d, [], true); } catch (ApiError $e) { $errors[] = "\"$d/\" — " . $e->getMessage(); continue; }
        if (!array_filter(array_keys($zip['files']), fn($p) => str_starts_with($p, $d . '/'))) $newDirs[] = $d;
    }
    $inZip = array_flip(array_merge(array_keys($zip['files']), $zip['dirs'], array_column($zip['big'], 'path')));
    $keep = array_values(array_filter(array_keys($rows), fn($p) => !isset($inZip[$p]) && $rows[$p]['kind'] !== 'dir'));
    $big = array_values(array_filter($zip['big'], fn($b) => !in_array($b['path'], $same, true)));
    sort($add); ksort($replace); sort($same); sort($keep);
    $token = hash('sha256', json_encode([$add, array_map(fn($r) => [$r['id'] ?? 'words', $r['rev'] ?? ''], $replace), $newDirs,
        array_map(fn($p) => hash('sha256', $zip['files'][$p] ?? ''), array_merge($add, array_keys($replace))), $words['token'] ?? null]));
    $cap = fn(array $a) => array_slice(array_values($a), 0, PF_ZIP_LIST);
    $plan = ['add' => $cap($add), 'replace' => $cap(array_keys($replace)), 'same' => $cap($same), 'keep' => $cap($keep), 'dirs' => $cap($newDirs),
        'skipped' => $cap($zip['skipped']), 'big' => $big, 'errors' => $errors,
        'counts' => ['add' => count($add), 'replace' => count($replace), 'same' => count($same), 'keep' => count($keep), 'dirs' => count($newDirs), 'skipped' => count($zip['skipped']), 'big' => count($big)],
        'token' => $token, 'limit' => ['text' => pf_limit('text'), 'who' => PF_WHO_CHANGES], 'words' => $words];
    if (empty($q['apply'])) return ['plan' => $plan];

    pf_guard($user);
    if ($errors) throw new ApiError('אי אפשר לייבא: ' . implode(' · ', array_slice($errors, 0, 5)), 409, ['plan' => $plan]);
    if (!hash_equals($token, (string)($q['token'] ?? ''))) throw new ApiError('הפרויקט השתנה מאז התצוגה. הציגו שוב', 409, ['plan' => $plan]);
    if (($c = pf_count()) + count($add) > ($max = pf_limit('files'))) pf_over('יותר מדי קבצים בפרויקט', $c + count($add), $max);
    $snap = null;
    if ($replace) { require_once __DIR__ . '/restore.php'; $snap = snapshot_create($user, 'לפני ייבוא ZIP'); }
    // N1: קודם הטבלה (אותו מנוע, אותו אימות, עסקה משלו ונקודת שחזור) — ואז הקבצים
    $wdone = $words ? words_file_save($user, $wb, ['apply' => true, 'token' => $words['token'], 'create_topics' => is_array($q['create_topics'] ?? null) ? $q['create_topics'] : []])['done'] : null;
    $add = array_values(array_filter($add, fn($p) => $p !== $wpath));
    unset($replace[$wpath]);
    pf_tx(function () use ($add, $replace, $newDirs, $zip, $user) {
        foreach ($newDirs as $d) pf_insert($d, 'dir', '', $user);
        foreach ($add as $p) { pf_free($p); pf_insert($p, 'file', $zip['files'][$p], $user, null, 'ייבוא ZIP'); }
        foreach ($replace as $p => $r) {
            $cur = pf_row((int)$r['id']);
            if ((int)$cur['rev'] !== (int)$r['rev']) throw new ApiError("\"$p\" השתנה בינתיים", 409);
            pf_put($cur, $zip['files'][$p], $user, 'ייבוא ZIP (הוחלף)');
        }
    });
    audit_as($user, 'pf_zip_import', count($add) . ' נוספו · ' . count($replace) . ' הוחלפו');
    return ['plan' => $plan, 'done' => ['add' => count($add), 'replace' => count($replace), 'dirs' => count($newDirs), 'words' => $wdone], 'snapshot' => $snap];
}

// ── V15: עמודים (docs/spec-v2/slices/V15-page-settings-pages.md §2.4, §2.6) ──────────────
//
// עמוד = קובץ HTML רגיל בעץ. pages_list קוראת; כל השאר כותבות דרך אותו מנוע (pf_insert / pf_put / pf_hist), בעסקה אחת,
// עם שורת היסטוריה לכל קובץ. הגבול (כלל העל, חצי 1): כתובת של עמוד היא "slug" — PAGE_SLUG בלבד — ולכן אין ערך שיוצא
// מ-/lexicon/: לא .., לא / בהתחלה, לא //. השרת בודק את זה בעצמו (400), לא סומך על הטופס.

const PAGE_SLUG = '/^[a-z0-9-]+(\/[a-z0-9-]+)*$/';
const PAGE_RESERVED = ['components', 'from-current', '__kol'];

/** הכתובת של העמוד באתר (בסיס הכלי). */
function page_base(): string
{
    return '/' . (defined('PUB_PAGE') ? PUB_PAGE : 'lexicon') . '/';
}

/** האם הנתיב הוא עמוד (pages: "auto" — כל .html מחוץ ל-components/, ולא תבנית ישנה). */
function page_is(string $p): bool
{
    return (bool)preg_match('/\.html?$/i', $p) && !str_starts_with($p, 'components/') && !preg_match('#^from-current/[^/]+\.html?$#i', $p);
}

/** about/index.html ← "about/"; index.html ← ""; x.html ← "x.html". */
function page_url(string $p): string
{
    if ($p === 'index.html') return '';
    return str_ends_with($p, '/index.html') ? substr($p, 0, -strlen('index.html')) : $p;
}

function page_title(string $html): string
{
    if (!preg_match('~<title\b[^>]*>(.*?)</title\s*>~is', $html, $m)) return '';
    return mb_substr(trim(preg_replace('/\s+/u', ' ', html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''), 0, 120);
}

/** slug תקין או 400. זה הגבול של "כתובת מחוץ ל-/lexicon/ אינה אפשרית". */
function page_slug(mixed $s): string
{
    if (!is_string($s) || !preg_match(PAGE_SLUG, $s) || strlen($s) > 120) throw new ApiError('כתובת של עמוד: אותיות לטיניות קטנות, ספרות ומקף, ו-/ בין חלקים (למשל about או team/dana). בלי .. ובלי / בהתחלה או בסוף', 400);
    if (in_array(explode('/', $s)[0], PAGE_RESERVED, true)) throw new ApiError("\"$s\" שמורה למערכת", 400);
    return $s;
}

/** kol.json כטקסט + תצורה, לכתיבה. בלי kol.json תקין — אין כתיבה (קודם מתקנים את הקובץ). */
function page_kol_row(): array
{
    $r = pf_by_path('kol.json');
    if (!$r || $r['kind'] !== 'file') throw new ApiError('אין kol.json בשורש הפרויקט', 409);
    $s = pf_blob_get($r['sha']);
    $c = json_decode($s, true);
    if (!is_array($c) || array_is_list($c)) throw new ApiError('kol.json אינו JSON תקין — מתקנים אותו בעורך הקוד, ואז שוב', 409);
    return [$r, $s, $c];
}

/** מחליף ערך עליון אחד ב-kol.json במקום (שאר הקובץ — כמו שהוא); בלי המפתח — נוסף בתחילת האובייקט. */
function page_kol_set(array $user, string $key, mixed $value, string $note): void
{
    [$r, $s, $c] = page_kol_row();
    $enc = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $rx = '/("' . preg_quote($key, '/') . '"\s*:\s*)("(?:[^"\\\\]|\\\\.)*"|\[[^\[\]]*\]|true|false|null|-?\d+(?:\.\d+)?)/';
    $out = array_key_exists($key, $c) ? preg_replace($rx, '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $enc), $s, 1) : preg_replace('/\{/', '{' . "\n  \"$key\": " . str_replace(['\\', '$'], ['\\\\', '\\$'], $enc) . ',', $s, 1);
    $chk = is_string($out) ? json_decode($out, true) : null;
    if (!is_array($chk) || ($chk[$key] ?? null) !== $value) {                // מבנה שהביטוי אינו מכיר — כותבים את האובייקט מחדש (מעוצב)
        $c[$key] = $value;
        $out = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    if ($out !== $s) pf_put($r, $out, $user, $note);
}

/** כל העמודים, לפי הסדר: pages (רשימה מפורשת) ואחריה מה שאינו ברשימה; "auto" — לפי נתיב, הראשי ראשון. */
function pages_rows(): array
{
    $cfg = pf_kol()['config'] ?? [];
    $rows = array_values(array_filter(db()->query("SELECT * FROM pf_files WHERE deleted_at IS NULL AND kind = 'file' ORDER BY path_key")->fetchAll(), fn($r) => page_is($r['path'])));
    $entry = is_string($cfg['entry'] ?? null) ? $cfg['entry'] : 'index.html';
    $list = is_array($cfg['pages'] ?? null) ? array_values(array_filter($cfg['pages'], 'is_string')) : null;
    $pos = fn($p) => $list !== null ? (($i = array_search($p, $list, true)) === false ? 10000 : $i) : ($p === $entry ? -1 : 0);
    usort($rows, fn($a, $b) => $pos($a['path']) <=> $pos($b['path']) ?: strcmp($a['path'], $b['path']));
    return [$rows, $entry, $list];
}

/** 📄 עמודים: רשימה, ההזזות מאז הפרסום האחרון, וקישורים לעמודים שאינם קיימים. קריאה בלבד. */
function pages_list(array $q = []): array
{
    [$rows, $entry, $list] = pages_rows();
    $live = array_flip(array_column(db()->query('SELECT path FROM pf_files WHERE deleted_at IS NULL')->fetchAll(), 'path'));
    $pages = [];
    $texts = [];
    foreach ($rows as $r) {
        $s = pf_blob_get($r['sha']);
        $texts[$r['path']] = $s;
        $pages[] = ['id' => (int)$r['id'], 'path' => $r['path'], 'url' => page_url($r['path']), 'title' => mb_check_encoding($s, 'UTF-8') ? page_title($s) : '',
            'at' => $r['updated_at'], 'by' => $r['updated_name'], 'entry' => $r['path'] === $entry, 'is404' => $r['path'] === '404.html',
            'listed' => $list === null || in_array($r['path'], $list, true), 'rev' => (int)$r['rev'],
            'ids' => mb_check_encoding($s, 'UTF-8') && preg_match_all('/\sid\s*=\s*(?:"([^"<>]{1,80})"|\'([^\'<>]{1,80})\')/', $s, $im) ? array_slice(array_values(array_unique(array_filter(array_map(fn($a, $b) => $a !== '' ? $a : $b, $im[1], $im[2])))), 0, 60) : []];   // §2.5: עוגנים בעמוד
    }
    // §2.5 / §2.6: קישור לעמוד שאינו קיים ("קישור שבור: about/") — גם כתובת "יפה" (תיקייה), שהבנייה אינה בודקת
    $base = page_base();
    $broken = [];
    $exists = fn(string $t) => isset($live[$t]) || isset($live[rtrim($t, '/') . '/index.html']) || $t === '';
    foreach ($texts as $p => $s) {
        if (!mb_check_encoding($s, 'UTF-8')) continue;
        $nl = null;
        foreach (pf_ref_scan($p, $s) as [$off, $len, $val, $kind]) {
            if ($kind !== 'attr' || !preg_match('/\bhref\s*=\s*["\']?$/i', substr($s, max(0, $off - 12), min(12, $off)))) continue;
            $v = str_starts_with($val, $base) ? '/' . substr($val, strlen($base)) : ($val === rtrim($base, '/') ? '/' : $val);
            if (str_starts_with($val, '/') && !str_starts_with($val, $base) && $val !== rtrim($base, '/')) continue;   // מחוץ לעמוד — לא שלנו
            $t = pf_ref_resolve($p, $v, 'attr');
            if ($t === null && $v !== '/' && !str_starts_with($v, './')) continue;
            $t ??= '';
            $pretty = str_ends_with(preg_split('/[?#]/', $val, 2)[0], '/') || !preg_match('/\.[a-z0-9]{1,6}$/i', $t);
            if (!$pretty || $exists($t)) continue;
            $nl ??= pf_lines($s);
            [$line] = pf_pos($nl, $s, $off);
            $broken[] = ['path' => $p, 'line' => $line, 'href' => $val, 'msg' => "קישור שבור: $val"];
        }
    }
    // V13: תבניות עמוד — "מתחיל מ: תבנית"
    $tpls = [];
    foreach (db()->query("SELECT path, sha FROM pf_files WHERE deleted_at IS NULL AND kind = 'file' AND path LIKE 'templates/%/template.json'")->fetchAll() as $t) {
        $m = json_decode(pf_blob_get($t['sha']), true);
        $n = substr($t['path'], strlen('templates/'), -strlen('/template.json'));
        if (is_array($m) && ($m['kind'] ?? '') === 'page' && !str_contains($n, '/') && pf_by_path("templates/$n/template.html")) $tpls[] = ['name' => $n, 'label' => mb_substr((string)($m['label'] ?? $n), 0, 80)];
    }
    return ['pages' => $pages, 'entry' => $entry, 'mode' => $list === null ? 'auto' : 'list', 'base' => $base, 'moves' => pages_moves($rows), 'broken' => array_slice($broken, 0, 200), 'templates' => $tpls];
}

/** §2.6: עמודים שהכתובת שלהם השתנתה מאז הגרסה שבאתר: הנתיב הישן (בגרסה החיה, ואינו קיים היום) ← החדש. */
function pages_moves(array $rows): array
{
    $live = db()->query("SELECT files FROM pkg_builds WHERE status = 'live' ORDER BY version DESC LIMIT 1")->fetch();
    if (!$live) return [];
    $old = json_decode((string)$live['files'], true) ?: [];
    $now = array_flip(array_column(db()->query('SELECT path FROM pf_files WHERE deleted_at IS NULL')->fetchAll(), 'path'));
    $out = [];
    $st = db()->prepare('SELECT DISTINCT path FROM pf_history WHERE file_id = ? AND path IS NOT NULL');
    foreach ($rows as $r) {
        if (isset($old[$r['path']])) continue;
        $st->execute([$r['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) {
            if ($p !== $r['path'] && isset($old[$p]) && !isset($now[$p]) && page_is($p)) { $out[] = ['from' => $p, 'to' => $r['path'], 'from_url' => page_url($p), 'to_url' => page_url($r['path'])]; break; }
        }
    }
    return $out;
}

/** <head> של עמוד חדש: הקבצים המשותפים של kol.json styles, יחסית למקום העמוד. */
function page_blank(string $path, string $title, array $cfg): string
{
    $links = '';
    $seen = [];
    foreach ((array)($cfg['styles'] ?? []) as $css) {
        if (!is_string($css) || isset($seen[$css]) || !pf_by_path($css)) continue;
        $seen[$css] = true;
        $links .= '  <link rel="stylesheet" href="' . htmlspecialchars(pf_ref_enc(pf_rel($path, $css)), ENT_QUOTES) . "\">\n";
    }
    $t = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5);
    return "<!doctype html>\n<html lang=\"he\" dir=\"rtl\">\n<head>\n  <meta charset=\"utf-8\">\n  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n  <title>$t</title>\n$links</head>\n<body>\n  <main>\n    <h1>$t</h1>\n  </main>\n</body>\n</html>\n";
}

/** הפניה מהכתובת הישנה (§2.6 "השאר הפניה"): קובץ רגיל, נראה בעץ. */
function page_redirect_html(string $from, string $to): string
{
    $rel = htmlspecialchars(pf_ref_enc(pf_rel($from, $to === 'index.html' ? 'index.html' : $to)), ENT_QUOTES);
    $rel = preg_replace('#(^|/)index\.html$#', '$1', $rel) ?: './';
    $canon = htmlspecialchars(page_base() . page_url($to), ENT_QUOTES);
    return "<!doctype html>\n<html lang=\"he\" dir=\"rtl\">\n<head>\n  <meta charset=\"utf-8\">\n  <title>הועבר</title>\n  <meta name=\"robots\" content=\"noindex\">\n  <meta http-equiv=\"refresh\" content=\"0; url=$rel\">\n  <link rel=\"canonical\" href=\"$canon\">\n</head>\n<body>\n  <p>העמוד עבר: <a href=\"$rel\">$rel</a></p>\n</body>\n</html>\n";
}

function page_notfound_html(array $cfg): string
{
    $b = htmlspecialchars(page_base(), ENT_QUOTES);
    $links = '';
    foreach ((array)($cfg['styles'] ?? []) as $css) if (is_string($css) && pf_by_path($css)) $links .= '  <link rel="stylesheet" href="' . htmlspecialchars($b . pf_ref_enc($css), ENT_QUOTES) . "\">\n";
    return "<!doctype html>\n<html lang=\"he\" dir=\"rtl\">\n<head>\n  <meta charset=\"utf-8\">\n  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n  <title>העמוד לא נמצא</title>\n  <meta name=\"robots\" content=\"noindex\">\n$links</head>\n<body>\n  <main>\n    <h1>העמוד לא נמצא</h1>\n    <p><a href=\"$b\">לעמוד הראשי</a></p>\n  </main>\n</body>\n</html>\n";
}

/**
 * ➕ עמוד חדש: slug + שם, "מתחיל מ": blank (ה-<head> עם הקבצים המשותפים) / copy (from = נתיב עמוד קיים) / redirect (to = עמוד
 * קיים — "השאר הפניה" בכתובת הישנה). נוצר <slug>/index.html בעסקה אחת; ברשימה מפורשת — נוסף בסופה.
 */
function page_create(array $user, array $b): array
{
    pf_seed($user);
    pf_guard($user);
    $slug = page_slug($b['slug'] ?? null);
    $path = pf_path($slug . '/index.html');
    $kind = (string)($b['start'] ?? 'blank');
    $title = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($b['title'] ?? '')) ?? '');
    if (mb_strlen($title) > 120) throw new ApiError('השם ארוך מדי (עד 120 תווים)');
    $r = pf_tx(function () use ($user, $slug, $path, $kind, $title, $b) {
        $cfg = pf_kol()['config'] ?? [];
        if ($kind === 'copy') {
            $src = pf_by_path(pf_path($b['from'] ?? null));
            if (!$src || $src['kind'] !== 'file' || !page_is($src['path'])) throw new ApiError('העמוד לשכפול לא נמצא', 404);
            $bytes = pf_blob_get($src['sha']);
            // הפניות יחסיות — מהמקום החדש (אותו מנוע של F2b)
            $plan = [];
            foreach (pf_ref_scan($src['path'], $bytes) as [$off, $len, $val, $kd]) {
                $t = pf_ref_resolve($src['path'], $val, $kd);
                if ($t === null || $val[0] === '/' || $kd === 'entry') continue;
                $new = pf_ref_value($val, $path, $t, $kd);
                if ($new !== $val && pf_ref_resolve($path, $val, $kd) !== $t) $plan[] = [$off, $len, $new];
            }
            usort($plan, fn($x, $y) => $y[0] <=> $x[0]);
            foreach ($plan as [$off, $len, $new]) $bytes = substr_replace($bytes, $new, $off, $len);
            if ($title !== '' && preg_match('~(<title\b[^>]*>)(.*?)(</title\s*>)~is', $bytes, $m, PREG_OFFSET_CAPTURE)) $bytes = substr_replace($bytes, htmlspecialchars($title, ENT_QUOTES | ENT_HTML5), $m[2][1], strlen($m[2][0]));
            $note = "שכפול של {$src['path']}";
        } elseif ($kind === 'tpl') {                                         // V13: תבנית עמוד — templates/<שם>/template.html (העמוד כולו)
            $name = (string)($b['template'] ?? '');
            if (!preg_match('/^[\p{L}\p{N}_-]{1,60}$/u', $name)) throw new ApiError('שם תבנית לא תקין', 400);
            $meta = pf_by_path("templates/$name/template.json");
            $tp = pf_by_path("templates/$name/template.html");
            if (!$meta || !$tp) throw new ApiError("התבנית $name לא נמצאה", 404);
            if ((json_decode(pf_blob_get($meta['sha']), true)['kind'] ?? '') !== 'page') throw new ApiError("$name היא תבנית של מקטע, לא של עמוד — מוסיפים אותה בעורך", 400);
            $bytes = pf_blob_get($tp['sha']);
            $plan = [];                                                    // ההפניות בתבנית — יחסיות לשורש (העמוד שנשמר); מהמקום החדש
            foreach (pf_ref_scan('index.html', $bytes) as [$off, $len, $val, $kd]) {
                $t = pf_ref_resolve('index.html', $val, $kd);
                if ($t === null || $val[0] === '/' || $kd === 'entry') continue;
                $new = pf_ref_value($val, $path, $t, $kd);
                if ($new !== $val) $plan[] = [$off, $len, $new];
            }
            usort($plan, fn($x, $y) => $y[0] <=> $x[0]);
            foreach ($plan as [$off, $len, $new]) $bytes = substr_replace($bytes, $new, $off, $len);
            if ($title !== '' && preg_match('~(<title\b[^>]*>)(.*?)(</title\s*>)~is', $bytes, $m, PREG_OFFSET_CAPTURE)) $bytes = substr_replace($bytes, htmlspecialchars($title, ENT_QUOTES | ENT_HTML5), $m[2][1], strlen($m[2][0]));
            $note = "מתבנית $name";
        } elseif ($kind === 'redirect') {
            $to = pf_by_path(pf_path($b['to'] ?? null));
            if (!$to || !page_is($to['path'])) throw new ApiError('העמוד שאליו מפנים לא נמצא', 404);
            $bytes = page_redirect_html($path, $to['path']);
            $note = 'הפניה אל ' . $to['path'];
        } elseif ($kind === 'blank') {
            if ($title === '') throw new ApiError('חסר שם לעמוד');
            $bytes = page_blank($path, $title, $cfg);
            $note = 'עמוד חדש';
        } else throw new ApiError('"מתחיל מ" לא מוכר');
        pf_free($path);
        if (($n = pf_count()) >= ($max = pf_limit('files'))) pf_over('יותר מדי קבצים בפרויקט', $n + 1, $max);
        $r = pf_insert($path, 'file', $bytes, $user, null, $note);
        if (is_array($cfg['pages'] ?? null) && !in_array($path, $cfg['pages'], true)) page_kol_set($user, 'pages', array_values(array_merge($cfg['pages'], [$path])), "עמוד חדש: $path");
        return $r;
    });
    audit_as($user, 'page_create', $path . ($kind !== 'blank' ? " · $kind" : ''));
    return ['file' => pf_public($r), 'url' => page_url($path)];
}

/**
 * שינוי כתובת (§2.4): about/ ← odot/. בלי apply — התוכנית: הקובץ (או התיקייה) שזזים, כל קישור שיתעדכן (עם מספר, שורה,
 * ישן ← חדש), ומה שנמצא בקוד JS ("לבדיקה ידנית" — לא מוחלף). עם apply + token — עסקה אחת: הזזה + הקישורים שנבחרו
 * (pick — מספרי השורות בתוכנית; בלי — כולם) + (redirect) הפניה בכתובת הישנה.
 */
function page_move(array $user, array $b): array
{
    pf_guard($user);
    $from = pf_path($b['from'] ?? null);
    $slug = page_slug($b['to'] ?? null);
    $run = function (bool $apply) use ($user, $b, $from, $slug) {
        $src = pf_by_path($from);
        if (!$src || $src['kind'] !== 'file' || !page_is($from)) throw new ApiError('העמוד לא נמצא', 404);
        if ($from === 'index.html' || $from === '404.html') throw new ApiError($from === 'index.html' ? 'index.html שבשורש הוא הכתובת של העמוד עצמו (/lexicon/) — אי אפשר להזיז אותו. אפשר לקבוע עמוד אחר כראשי' : 'דף ה-404 נקבע בשם 404.html', 400);
        $dirMove = str_ends_with($from, '/index.html');
        $unitFrom = $dirMove ? substr($from, 0, -strlen('/index.html')) : $from;
        $unitTo = $dirMove ? $slug : $slug . '/index.html';
        $newPath = $slug . '/index.html';
        if ($unitFrom === $unitTo) throw new ApiError('זו כבר הכתובת', 400);
        if ($dirMove && str_starts_with($unitTo . '/', $unitFrom . '/')) throw new ApiError('אי אפשר להזיז עמוד לתוך עצמו', 400);
        $rows = $dirMove ? array_values(array_filter(array_merge([pf_by_path($unitFrom)], pf_children($unitFrom)))) : [$src];
        $plan = pf_refs_plan($unitFrom, $unitTo);
        // /lexicon/about/ — כתובת מלאה בתוך העמוד: אותו עדכון, בתוך הבסיס
        $base = page_base();
        $in = fn(string $p) => $p === $unitFrom || str_starts_with($p, $unitFrom . '/');
        $map = fn(string $p) => $in($p) ? $unitTo . substr($p, strlen($unitFrom)) : $p;
        $manual = [];
        foreach (pf_texts() as [$r, $s]) {
            $fo = $r['path'];
            $nl = null;
            foreach (pf_ref_scan($fo, $s) as [$off, $len, $val, $kind]) {
                if ($kind !== 'attr' || !str_starts_with($val, $base)) continue;
                $t = pf_ref_resolve($fo, '/' . substr($val, strlen($base)), 'attr');
                if ($t === null || !$in($t) && !$in(rtrim($t, '/') . '/index.html')) continue;
                $tt = $in($t) ? $t : rtrim($t, '/') . '/index.html';
                $nv = $base . pf_ref_enc(page_url($map($tt)));
                if (str_ends_with($val, '/') && !str_ends_with($nv, '/')) $nv .= '/';
                if ($nv === $val) continue;
                $nl ??= pf_lines($s);
                [$line, $col, $start] = pf_pos($nl, $s, $off);
                $plan[] = ['id' => (int)$r['id'], 'path' => $fo, 'rev' => (int)$r['rev'], 'line' => $line, 'col' => $col, 'kind' => 'attr', 'old' => $val, 'new' => $nv,
                    'text' => pf_line_at($s, $start, $off, $len), 'off' => $off, 'len' => $len, 'sha' => $r['sha']];
            }
            // קישור שנכתב ב-JS — לבדיקה ידנית (לא מוחלף)
            $js = preg_match('/\.(m?js|cjs|jsx|ts)$/i', $fo);
            $chunks = $js ? [[0, $s]] : [];
            if (!$js && page_is($fo)) { preg_match_all('~<script\b[^>]*>(.*?)</script\s*>~is', $s, $mm, PREG_OFFSET_CAPTURE); foreach ($mm[1] as [$c, $o]) $chunks[] = [$o, $c]; }
            $needle = '/(["\'`])[^"\'`\n]*?(?<![a-z0-9-])' . preg_quote($dirMove ? page_url($from) : $from, '/') . '/';
            foreach ($chunks as [$o0, $c]) {
                if (!preg_match_all($needle, $c, $hm, PREG_OFFSET_CAPTURE)) continue;
                foreach ($hm[0] as [$hit, $ho]) {
                    $nl ??= pf_lines($s);
                    [$line] = pf_pos($nl, $s, $o0 + $ho);
                    $manual[] = ['path' => $fo, 'line' => $line, 'text' => mb_substr(trim($hit), 0, 120)];
                }
            }
        }
        foreach ($plan as $i => &$x) $x['n'] = $i;
        unset($x);
        $token = hash('sha256', json_encode([$from, $slug, array_map(fn($x) => [$x['id'], $x['sha'], $x['off'], $x['new']], $plan)]));
        $pub = fn() => array_map(fn($x) => array_diff_key($x, ['off' => 1, 'len' => 1, 'sha' => 1]), $plan);
        $view = ['from' => $from, 'to' => $newPath, 'from_url' => page_url($from), 'to_url' => page_url($newPath), 'unit' => ['from' => $unitFrom, 'to' => $unitTo, 'files' => count(array_filter($rows, fn($r) => $r['kind'] !== 'dir'))],
            'links' => $pub(), 'manual' => array_slice($manual, 0, 200), 'token' => $token];
        if (!$apply) return $view;
        if (!hash_equals($token, (string)($b['token'] ?? ''))) throw new ApiError('הפרויקט השתנה מאז התצוגה — פותחים שוב את "שינוי כתובת"', 409);
        $pick = isset($b['pick']) && is_array($b['pick']) ? array_flip(array_map('intval', $b['pick'])) : null;
        $chosen = array_values(array_filter($plan, fn($x) => $pick === null || isset($pick[$x['n']])));
        $skip = array_map(fn($r) => (int)$r['id'], $rows);
        $st = db()->prepare('UPDATE pf_files SET path = ?, path_key = ?, updated_by = ?, updated_name = ?, updated_at = ? WHERE id = ?');
        foreach ($rows as $r) {
            $np = pf_path($unitTo . substr($r['path'], strlen($unitFrom)));
            pf_free($np, $skip, $r['kind'] === 'dir');
            $st->execute([$np, pf_key($np), $user['sub'], $user['name'] ?? '', now_iso(), $r['id']]);
            if ($r['kind'] !== 'dir') pf_hist(['path' => $np] + $r, $user, "שינוי כתובת מ-{$r['path']}");
        }
        $n = $chosen ? pf_refs_apply($chosen, $user, "עדכון קישורים: " . page_url($from) . ' ← ' . page_url($newPath)) : 0;
        $cfg = pf_kol()['config'] ?? [];
        if (is_array($cfg['pages'] ?? null)) page_kol_set($user, 'pages', array_map(fn($p) => $in($p) ? $map($p) : $p, $cfg['pages']), 'סדר העמודים: ' . $from . ' ← ' . $newPath);
        if (($cfg['entry'] ?? null) === $from) page_kol_set($user, 'entry', $newPath, 'העמוד הראשי: ' . $newPath);
        $redir = null;
        if (!empty($b['redirect'])) {
            pf_free($from);
            $redir = pf_insert($from, 'file', page_redirect_html($from, $newPath), $user, null, 'הפניה אל ' . $newPath);
        }
        return $view + ['done' => ['links' => $n, 'redirect' => $redir ? pf_public($redir) : null]];
    };
    if (empty($b['apply'])) return $run(false);
    $out = pf_tx(fn() => $run(true));
    audit_as($user, 'page_move', "$from ← {$out['to']} · {$out['done']['links']} קישורים" . ($out['done']['redirect'] ? ' · הפניה' : ''));
    return $out;
}

/** "קבע כעמוד הראשי": entry ב-kol.json. */
function page_set_entry(array $user, array $b): array
{
    pf_guard($user);
    $p = pf_path($b['path'] ?? null);
    pf_tx(function () use ($user, $p) {
        $r = pf_by_path($p);
        if (!$r || $r['kind'] !== 'file' || !page_is($p) || $p === '404.html') throw new ApiError('העמוד לא נמצא', 404);
        page_kol_set($user, 'entry', $p, "העמוד הראשי: $p");
    });
    audit_as($user, 'page_set_entry', $p);
    return ['entry' => $p];
}

/**
 * "קבע כדף 404": עמוד קיים ← 404.html (שינוי שם; הקישורים אליו אינם מעודכנים — דף 404 אינו יעד של קישור), או יצירה מתבנית
 * "לא נמצא" עם קישור לעמוד הראשי. 404.html קיים — 409 (קודם מוחקים / משנים אותו).
 */
function page_set_404(array $user, array $b): array
{
    pf_guard($user);
    $from = isset($b['path']) && $b['path'] !== '' ? pf_path($b['path']) : null;
    $r = pf_tx(function () use ($user, $from) {
        if (pf_by_path('404.html')) throw new ApiError('כבר יש 404.html בפרויקט — משנים אותו, או מוחקים קודם', 409);
        $cfg = pf_kol()['config'] ?? [];
        if ($from === null) return pf_insert('404.html', 'file', page_notfound_html($cfg), $user, null, 'דף 404 מתבנית "לא נמצא"');
        $src = pf_by_path($from);
        if (!$src || $src['kind'] !== 'file' || !page_is($from)) throw new ApiError('העמוד לא נמצא', 404);
        if ($from === ($cfg['entry'] ?? 'index.html')) throw new ApiError('זה העמוד הראשי — קודם קובעים עמוד ראשי אחר', 409);
        pf_free('404.html', [(int)$src['id']]);
        db()->prepare('UPDATE pf_files SET path = ?, path_key = ?, updated_by = ?, updated_name = ?, updated_at = ? WHERE id = ?')->execute(['404.html', pf_key('404.html'), $user['sub'], $user['name'] ?? '', now_iso(), $src['id']]);
        $r = pf_row((int)$src['id']);
        pf_hist($r, $user, "דף 404 (היה $from)");
        // דף 404 מוגש בכל עומק כתובת — הפניה יחסית לקובץ בפרויקט נשברת. כל הפניה כזו ← /lexicon/<נתיב> (כמו בתבנית "לא נמצא")
        $s = pf_blob_get($r['sha']);
        if (mb_check_encoding($s, 'UTF-8')) {
            $live = array_flip(array_column(db()->query('SELECT path FROM pf_files WHERE deleted_at IS NULL')->fetchAll(), 'path'));
            $plan = [];
            foreach (pf_ref_scan($from, $s) as [$off, $len, $val, $kd]) {
                if ($val[0] === '/' || $kd === 'entry') continue;
                $t = pf_ref_resolve($from, $val, $kd);
                if ($t === null && preg_match('#^(\./|\.\./)+$#', $val) && substr_count($val, '../') === substr_count($from, '/')) $t = '';   // "../../" — שורש העמוד
                if ($t === null || ($t !== '' && !pf_exists_in($live, $t))) continue;
                $abs = page_base() . ($t === '' || $t === 'index.html' ? '' : pf_ref_enc($t) . (str_ends_with($val, '/') ? '/' : ''));
                $plan[] = [$off, $len, $abs];
            }
            usort($plan, fn($x, $y) => $y[0] <=> $x[0]);
            foreach ($plan as [$off, $len, $abs]) $s = substr_replace($s, $abs, $off, $len);
            if ($plan) { pf_put($r, $s, $user, 'דף 404: הפניות לנתיב מוחלט (' . count($plan) . ')'); $r = pf_row((int)$src['id']); }
        }
        if (is_array($cfg['pages'] ?? null)) page_kol_set($user, 'pages', array_map(fn($p) => $p === $from ? '404.html' : $p, $cfg['pages']), "דף 404: $from");
        return $r;
    });
    audit_as($user, 'page_set_404', $from ?? 'תבנית');
    return ['file' => pf_public($r)];
}

/** סדר העמודים (§2.4): רשימה מפורשת ב-pages, או "auto" ("חזור לאוטומטי"). רק עמודים קיימים. */
function pages_order(array $user, array $b): array
{
    pf_guard($user);
    $want = $b['order'] ?? null;
    pf_tx(function () use ($user, $want) {
        if ($want === 'auto') { page_kol_set($user, 'pages', 'auto', 'סדר העמודים: אוטומטי'); return; }
        if (!is_array($want) || !$want || count($want) > 500) throw new ApiError('סדר: רשימת נתיבים');
        $seen = [];
        foreach ($want as $p) {
            $p = pf_path($p);
            if (isset($seen[$p]) || !page_is($p) || !pf_by_path($p)) throw new ApiError("\"$p\" אינו עמוד בפרויקט", 400);
            $seen[$p] = true;
        }
        page_kol_set($user, 'pages', array_keys($seen), 'סדר העמודים');
    });
    audit_as($user, 'pages_order', is_array($want) ? count($want) . ' עמודים' : 'auto');
    return pages_list();
}
