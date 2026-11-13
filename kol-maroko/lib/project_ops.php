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
function pf_zip_import(array $user, array $q, string $raw): array
{
    pf_seed($user);
    $zip = pf_zip_open($raw);
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

// ── V16: ספריית הרכיבים המוכנים (02 §6, §7.3) ─────────────────
// המקור: assets/kit/components/<שם>/ — אותו מבנה כמו רכיב של בעל העמוד + kit.json (גרסה, קטגוריה, תיאור, תמונה מקדימה).
// בשימוש הראשון התיקייה מועתקת ל-components/<שם>/ דרך מנוע הקבצים, ונרשמת ב-kol.json system:
//   "components/<שם>/": { "version", "modified": false, "kit": "<שם במקור>", "hash" }
// "שונה" = מחושב (hash של הקבצים בפרויקט מול hash של מה שהועתק). modified נכתב true כשבעל העמוד בוחר "השאר את שלי".

const KIT_DIR = __DIR__ . '/../assets/kit/components';
const KIT_FILES = ['component.json', 'template.html', 'style.css', 'script.js'];
const KIT_NAME = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';

function kit_names(): array
{
    $out = [];
    foreach ((array)@scandir(KIT_DIR) as $d) if (is_string($d) && preg_match(KIT_NAME, $d) && is_file(KIT_DIR . "/$d/kit.json")) $out[] = $d;
    sort($out);
    return $out;
}

/** רכיב מוכן מהמקור: kit.json, component.json והקבצים (רק KIT_FILES — kit.json ו-preview אינם מועתקים). */
function kit_read(string $name): array
{
    if (!preg_match(KIT_NAME, $name) || !in_array($name, kit_names(), true)) throw new ApiError("אין רכיב מוכן בשם \"$name\"", 404);
    $dir = KIT_DIR . "/$name";
    $meta = json_decode((string)file_get_contents("$dir/kit.json"), true);
    $schema = json_decode((string)@file_get_contents("$dir/component.json"), true);
    if (!is_array($meta) || !is_array($schema)) throw new ApiError("הרכיב המוכן \"$name\" פגום בכלי", 500);
    $files = [];
    foreach (KIT_FILES as $f) if (is_file("$dir/$f")) $files[$f] = (string)file_get_contents("$dir/$f");
    $ver = is_string($meta['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+$/', $meta['version']) ? $meta['version'] : '1.0.0';
    return ['name' => $name, 'version' => $ver, 'meta' => $meta, 'schema' => $schema, 'files' => $files,
        'label' => pf_label((string)($schema['label'] ?? $name), 60)];
}

/** הקבצים כפי שהם נכתבים ל-components/<תיקייה>/: בשם אחר (accordion-2) — "name" ב-component.json הוא שם התיקייה (02 §7). */
function kit_files_for(array $k, string $folder): array
{
    $files = $k['files'];
    if ($folder !== $k['name'] && isset($files['component.json'])) {
        $files['component.json'] = (string)preg_replace('/("name"\s*:\s*)"' . preg_quote($k['name'], '/') . '"/', '${1}"' . $folder . '"', $files['component.json'], 1);
    }
    return $files;
}

function kit_hash(array $files): string
{
    ksort($files);
    $h = hash_init('sha1');
    foreach ($files as $f => $b) hash_update($h, $f . "\0" . $b . "\0");
    return hash_final($h);
}

/** kol.json system — רק הרשומות של רכיבים מוכנים: [תיקייה => רשומה]. */
function kit_sys(?array $cfg = null): array
{
    $cfg ??= pf_kol()['config'] ?? [];
    $out = [];
    foreach (is_array($cfg['system'] ?? null) ? $cfg['system'] : [] as $k => $v) {
        if (is_string($k) && preg_match('~^components/([a-z][a-z0-9-]*)/$~', $k, $m) && is_array($v) && is_string($v['kit'] ?? null)) $out[$m[1]] = $v;
    }
    return $out;
}

/** הקבצים של components/<תיקייה>/ בפרויקט (רק KIT_FILES שקיימים): [שם => בייטים]. */
function kit_proj(string $folder): array
{
    $out = [];
    foreach (KIT_FILES as $f) { $r = pf_by_path("components/$folder/$f"); if ($r && $r['kind'] === 'file') $out[$f] = pf_blob_get($r['sha']); }
    return $out;
}

function kit_taken(string $folder): bool
{
    return pf_by_path("components/$folder") !== null || pf_children("components/$folder") !== [];
}

/** הספרייה: כל רכיב מוכן + מצבו בפרויקט (לא בפרויקט · שם תפוס · בפרויקט · שונה · יש גרסה חדשה). */
function kit_list(): array
{
    $sys = kit_sys();
    $items = [];
    foreach (kit_names() as $n) {
        try { $k = kit_read($n); } catch (Throwable) { continue; }
        $m = $k['meta'];
        $inst = [];
        foreach ($sys as $folder => $e) {
            if ($e['kit'] !== $n) continue;
            $cur = kit_proj($folder);
            if (!$cur) continue;                                             // נמחק מהפרויקט — כאילו לא הועתק
            $changed = !empty($e['modified']) || kit_hash($cur) !== (string)($e['hash'] ?? '');
            $inst[] = ['folder' => $folder, 'version' => (string)($e['version'] ?? '0.0.0'), 'modified' => $changed,
                'update' => version_compare($k['version'], (string)($e['version'] ?? '0.0.0'), '>'), 'same' => kit_hash($cur) === kit_hash(kit_files_for($k, $folder))];
        }
        $items[] = [
            'name' => $n, 'label' => $k['label'], 'icon' => pf_label((string)($k['schema']['icon'] ?? '🧩'), 8), 'version' => $k['version'],
            'category' => pf_label((string)($m['category'] ?? 'כללי'), 30), 'description' => pf_label((string)($m['description'] ?? ''), 200),
            'preview' => is_string($m['preview'] ?? null) && preg_match('/^[a-z0-9-]+\.svg$/', $m['preview']) ? "assets/kit/components/$n/{$m['preview']}" : null,
            'external' => array_values(array_filter((array)($m['external'] ?? []), 'is_string')),
            'js' => isset($k['files']['script.js']), 'installed' => $inst, 'taken' => !$inst && kit_taken($n),
        ];
    }
    return ['items' => $items, 'categories' => KIT_CATEGORIES];
}
const KIT_CATEGORIES = ['תוכן', 'קופסאות', 'מספרים', 'מדיה', 'ניווט', 'חלונות', 'תנועה', 'טפסים', 'רשתות'];

/** הקבצים של רכיב מוכן (להשוואה "זו לצד זו"). */
function kit_get(array $q): array
{
    $k = kit_read((string)($q['name'] ?? ''));
    $folder = (string)($q['folder'] ?? $k['name']);
    if (!preg_match(KIT_NAME, $folder)) $folder = $k['name'];
    return ['name' => $k['name'], 'version' => $k['version'], 'files' => kit_files_for($k, $folder)];
}

/** kol.json ← system[components/<תיקייה>/] (null — הסרה). בתוך pf_tx; kol.json לא תקין — 409 לפני שנוגעים בדבר. */
function kit_sys_put(array $user, string $folder, ?array $entry, string $note): void
{
    $kol = pf_kol();
    $r = pf_by_path('kol.json');
    if (!$kol['valid'] || !$r) throw new ApiError('kol.json אינו תקין — תקנו אותו קודם (📁 קבצים)', 409);
    $c = $kol['config'];
    $sys = is_array($c['system'] ?? null) ? $c['system'] : [];
    if ($entry === null) unset($sys["components/$folder/"]); else $sys["components/$folder/"] = $entry;
    $c['system'] = $sys;
    pf_put(pf_row((int)$r['id']), json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", $user, $note);
}

/**
 * שימוש ראשון: components/<as>/ ← הקבצים של הרכיב המוכן, ו-kol.json system — עסקה אחת, גרסה בהיסטוריה לכל קובץ.
 * כבר בפרויקט (אותו רכיב מוכן באותה תיקייה) — אין העתקה. תיקייה תפוסה בקבצים של בעל העמוד — 409 עם שם פנוי.
 */
function kit_copy(array $user, array $b): array
{
    pf_seed($user);
    pf_guard($user);
    $k = kit_read((string)($b['name'] ?? ''));
    $as = (string)($b['as'] ?? $k['name']);
    if (!preg_match(KIT_NAME, $as) || strlen($as) > 40) throw new ApiError('שם התיקייה — באנגלית, אותיות קטנות, ספרות ומקפים (kebab-case), עד 40 תווים');
    $res = pf_tx(function () use ($k, $as, $user) {
        $sys = kit_sys();
        if (isset($sys[$as]) && $sys[$as]['kit'] === $k['name'] && kit_proj($as)) return ['copied' => false, 'folder' => $as, 'ids' => []];
        if (kit_taken($as)) {
            $free = $k['name']; for ($i = 2; kit_taken($free) || isset($sys[$free]); $i++) $free = $k['name'] . '-' . $i;
            throw new ApiError("כבר יש בפרויקט components/$as/ משלכם", 409, ['taken' => $as, 'free' => $free]);
        }
        if (($n = pf_count()) + count($k['files']) > ($max = pf_limit('files'))) pf_over('יותר מדי קבצים בפרויקט', $n + count($k['files']), $max);
        $note = 'רכיב מוכן: ' . $k['label'] . ' ' . $k['version'];
        $ids = [];
        $files = kit_files_for($k, $as);
        foreach ($files as $f => $bytes) { $p = "components/$as/$f"; pf_free($p); $ids[] = (int)pf_insert($p, 'file', $bytes, $user, null, $note)['id']; }
        kit_sys_put($user, $as, ['version' => $k['version'], 'modified' => false, 'kit' => $k['name'], 'hash' => kit_hash($files)], $note);
        return ['copied' => true, 'folder' => $as, 'ids' => $ids];
    });
    if ($res['copied']) audit_as($user, 'kit_copy', "{$k['name']} {$k['version']} ← components/$as/");
    return $res + ['kol' => pf_kol()];
}

/** ביטול ההעתקה (Ctrl+Z): הקבצים שנוצרו — לסל, והרשומה ב-kol.json יוצאת. רק קבצים בתוך components/<תיקייה>/. */
function kit_undo(array $user, array $b): array
{
    pf_guard($user);
    $folder = (string)($b['folder'] ?? '');
    if (!preg_match(KIT_NAME, $folder)) throw new ApiError('תיקייה לא תקינה');
    $ids = pf_ids($b);
    pf_tx(function () use ($folder, $ids, $user) {
        $batch = bin2hex(random_bytes(6));
        $st = db()->prepare('UPDATE pf_files SET deleted_at = ?, deleted_by = ?, deleted_name = ?, deleted_batch = ? WHERE id = ? AND deleted_at IS NULL');
        foreach ($ids as $id) {
            $r = pf_row($id, false);
            if (!str_starts_with($r['path'], "components/$folder/")) throw new ApiError('הקובץ אינו חלק מהרכיב', 422);
            $st->execute([now_iso(), $user['sub'], $user['name'] ?? '', $batch, $id]);
        }
        if (isset(kit_sys()[$folder])) kit_sys_put($user, $folder, null, "ביטול רכיב מוכן: components/$folder/");
    });
    audit_as($user, 'kit_undo', "components/$folder/");
    return ['kol' => pf_kol()];
}

/**
 * עדכון מהכלי. action:
 *   take — הקבצים של הגרסה החדשה נכתבים (שינוי קובץ רגיל: rev+1 והיסטוריה; קובץ חדש — נוצר), והרשומה מתעדכנת.
 *          רכיב ששונה — רק עם force (בעל העמוד ראה את ההשוואה ובחר "קח את החדשה").
 *   keep — "השאר את שלי": הרשומה ← הגרסה החדשה, modified: true. הקבצים לא נוגעים.
 * מחזיר before: [נתיב => rev לפני] (לביטול דרך pf_version_restore) ו-created (לסל בביטול).
 */
function kit_update(array $user, array $b): array
{
    pf_guard($user);
    $folder = (string)($b['folder'] ?? '');
    $act = (string)($b['action'] ?? '');
    if (!in_array($act, ['take', 'keep'], true)) throw new ApiError('פעולה לא מוכרת');
    $res = pf_tx(function () use ($folder, $act, $b, $user) {
        $e = kit_sys()[$folder] ?? null;
        if (!$e) throw new ApiError("components/$folder/ אינו רכיב מוכן", 404);
        $k = kit_read((string)$e['kit']);
        $cur = kit_proj($folder);
        $changed = !empty($e['modified']) || kit_hash($cur) !== (string)($e['hash'] ?? '');
        $note = 'עדכון רכיב מוכן: ' . $k['label'] . ' ' . ($e['version'] ?? '?') . ' ← ' . $k['version'];
        if ($act === 'keep') {
            kit_sys_put($user, $folder, ['version' => $k['version'], 'modified' => true] + $e, 'השארתי את שלי: ' . $k['label'] . ' ' . $k['version']);
            return ['before' => [], 'created' => []];
        }
        if ($changed && empty($b['force'])) throw new ApiError('הרכיב שונה בפרויקט — "יש גרסה חדשה — השוואה"', 409, ['modified' => true]);
        $before = []; $created = [];
        $files = kit_files_for($k, $folder);
        foreach ($files as $f => $bytes) {
            $p = "components/$folder/$f";
            $r = pf_by_path($p);
            if ($r && $r['kind'] === 'file') { if (pf_blob_get($r['sha']) !== $bytes) { $before[$p] = (int)$r['rev']; pf_put($r, $bytes, $user, $note); } }
            else { pf_free($p); $created[] = (int)pf_insert($p, 'file', $bytes, $user, null, $note)['id']; }
        }
        $kr = pf_by_path('kol.json');
        if ($kr) $before['kol.json'] = (int)$kr['rev'];
        kit_sys_put($user, $folder, ['version' => $k['version'], 'modified' => false, 'kit' => $k['name'], 'hash' => kit_hash($files)], $note);
        return ['before' => $before, 'created' => $created];
    });
    audit_as($user, 'kit_update', "components/$folder/ · $act");
    return $res + ['kol' => pf_kol()];
}

/** ביטול העדכון (Ctrl+Z): כל קובץ חוזר לתוכן שהיה לו ב-rev שלפני העדכון (מההיסטוריה — שינוי רגיל, rev+1); קבצים שנוצרו — לסל. */
function kit_revert(array $user, array $b): array
{
    pf_guard($user);
    $folder = (string)($b['folder'] ?? '');
    if (!preg_match(KIT_NAME, $folder)) throw new ApiError('תיקייה לא תקינה');
    $prev = is_array($b['before'] ?? null) ? $b['before'] : [];
    $created = array_values(array_unique(array_map('intval', (array)($b['created'] ?? []))));
    pf_tx(function () use ($folder, $prev, $created, $user) {
        foreach ($prev as $p => $rev) {
            if (!is_string($p) || ($p !== 'kol.json' && !preg_match('~^components/' . preg_quote($folder, '~') . '/(' . implode('|', array_map('preg_quote', KIT_FILES)) . ')$~', $p))) throw new ApiError('הקובץ אינו חלק מהרכיב', 422);
            $r = pf_by_path($p);
            if (!$r || $r['kind'] !== 'file') continue;
            $st = db()->prepare('SELECT sha FROM pf_history WHERE file_id = ? AND rev = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$r['id'], (int)$rev]);
            $sha = $st->fetchColumn();
            if (!is_string($sha) || !is_file(pf_blob_path($sha))) throw new ApiError("הגרסה הקודמת של \"$p\" אינה בהיסטוריה", 409);
            pf_put($r, pf_blob_get($sha), $user, 'ביטול עדכון רכיב מוכן');
        }
        $batch = bin2hex(random_bytes(6));
        foreach ($created as $id) {
            $r = pf_row($id, false);
            if (!str_starts_with($r['path'], "components/$folder/")) throw new ApiError('הקובץ אינו חלק מהרכיב', 422);
            db()->prepare('UPDATE pf_files SET deleted_at = ?, deleted_by = ?, deleted_name = ?, deleted_batch = ? WHERE id = ? AND deleted_at IS NULL')->execute([now_iso(), $user['sub'], $user['name'] ?? '', $batch, $id]);
        }
    });
    audit_as($user, 'kit_revert', "components/$folder/");
    return ['kol' => pf_kol()];
}
