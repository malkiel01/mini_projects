<?php
/**
 * N2 §3.3 — שינוי מפתח של שדה (או slug של נושא) והקשירות בקבצי הפרויקט.
 *
 * הסריקה עוברת על כל קובצי הטקסט של הפרויקט (pf_texts — לא media/) ומוצאת את המקומות שבהם המפתח הישן הוא **שם של שדה**
 * בתוך ערך של תכונת data-kol-*: text / html / value / attr-* (שם, או {key} בתוך תבנית), if / class-* (הצד השמאלי של התנאי),
 * each, sort (גם "-key"), search-fields (רשימה), filter (החלק שאחרי ":"). ל-slug של נושא: filter-value, והצד הימני של
 * "topics=slug" ב-if / class-*. כל התאמה היא מיקום מדויק (offset + אורך) — אין חיפוש-והחלפה על הקובץ.
 * בקבצי JS: item.key / ['key'] (ולנושא: 'slug') — "לבדיקה ידנית", מוצגים ואינם מוחלפים לעולם.
 * המפתח החדש עובר את אותה בדיקה של מפתח (FIELD_KEY_RX / TOPIC_SLUG_RX) — רק a-z0-9_ — ולכן אינו יכול לשבור תכונה או תבנית.
 * הכתיבה — דרך project_ops (pf_put): גרסה בהיסטוריה של F2 לכל קובץ, עם הסיבה.
 */

declare(strict_types=1);

require_once __DIR__ . '/project_ops.php';

const BIND_ATTR_RX = '/\bdata-kol-([a-z][a-z0-9_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i';

/** ראש הנתיב ("topics.count" → topics) והמיקום שלו בתוך המחרוזת. */
function bind_head(string $expr, int $base): ?array
{
    $lead = strspn($expr, " \t");
    $rest = substr($expr, $lead);
    $len = strcspn($rest, ". \t");
    return $len ? [substr($rest, 0, $len), $base + $lead] : null;
}

/** המקומות בערך של תכונה אחת: [[offset בתוך הערך, הטקסט]]. $kind — field / topic. */
function bind_in_value(string $attr, string $v, string $kind, string $old): array
{
    $attr = strtolower($attr);
    $out = [];
    $head = function (string $expr, int $base) use (&$out, $old) { $h = bind_head($expr, $base); if ($h && $h[0] === $old) $out[] = $h[1]; };
    if ($kind === 'topic') {
        if ($attr === 'filter-value' && trim($v) === $old) $out[] = strpos($v, $old);
        if ($attr === 'if' || str_starts_with($attr, 'class-')) {
            if (($eq = strpos($v, '=')) !== false && trim(substr($v, $eq + 1)) === $old) $out[] = $eq + 1 + strspn(substr($v, $eq + 1), " \t");
        }
        return $out;
    }
    if (str_contains($v, '{')) {                                    // תבנית: {key} / {key.sub}
        preg_match_all('/\{([^{}]+)\}/', $v, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[1] as [$inner, $off]) $head($inner, $off);
        return $out;
    }
    if (in_array($attr, ['text', 'html', 'value', 'each'], true) || str_starts_with($attr, 'attr-')) $head($v, 0);
    elseif ($attr === 'if' || str_starts_with($attr, 'class-')) {
        $lhs = ($eq = strpos($v, '=')) === false ? $v : substr($v, 0, $eq);
        $neg = strspn($lhs, " \t!");
        $head(substr($lhs, $neg), $neg);
    } elseif ($attr === 'sort') {
        $neg = strspn($v, " \t-");
        $head(substr($v, $neg), $neg);
    } elseif ($attr === 'search-fields') {
        preg_match_all('/\S+/', $v, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$tok, $off]) $head($tok, $off);
    } elseif ($attr === 'filter' && ($c = strpos($v, ':')) !== false) $head(substr($v, $c + 1), $c + 1);
    return $out;
}

/**
 * כל הקשירות למפתח $old. כל פריט: id (קובץ:מיקום), file, path, rev, sha, off, len, line, before (השורה), manual, what.
 * $new — כדי להציג גם "אחרי". בלי $new — רק המקומות (לספירה במחיקת שדה ובמיזוג).
 */
function fields_bind_scan(string $kind, string $old, ?string $new = null): array
{
    if (!function_exists('pf_texts') || !pf_seeded() || $old === '') return [];
    $hits = [];
    foreach (pf_texts() as [$r, $s]) {
        if (str_starts_with($r['path'], 'media/')) continue;
        $js = (bool)preg_match('/\.(?:m?js|cjs|jsx|ts|tsx)$/i', $r['path']);
        $found = [];                                                  // [off, len, manual, what]
        preg_match_all(BIND_ATTR_RX, $s, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $x) {
            $g = isset($x[3]) && $x[3][1] >= 0 ? $x[3] : $x[2];
            foreach (bind_in_value($x[1][0], $g[0], $kind, $old) as $rel) $found[$g[1] + $rel] = [$g[1] + $rel, strlen($old), $js, 'data-kol-' . strtolower($x[1][0])];
        }
        if ($js) {                                                    // לבדיקה ידנית — לא מוחלף
            $q = preg_quote($old, '/');
            $rx = $kind === 'field' ? "/(?<=[\\w\\)\\]]\\.)$q\\b|(?<=\\[['\"]|\\[ ['\"])$q(?=['\"]\\]|['\"] \\])/" : "/(?<=['\"])$q(?=['\"])/";
            preg_match_all($rx, $s, $mm, PREG_OFFSET_CAPTURE);
            foreach ($mm[0] as [, $off]) $found[$off] ??= [$off, strlen($old), true, 'JS'];
        }
        if (!$found) continue;
        ksort($found);
        $nl = pf_lines($s);
        foreach ($found as [$off, $len, $manual, $what]) {
            [$line, , $start] = pf_pos($nl, $s, $off);
            $end = strpos($s, "\n", $start);
            $ln = rtrim(substr($s, $start, ($end === false ? strlen($s) : $end) - $start), "\r");
            $h = ['id' => $r['id'] . ':' . $off, 'file' => (int)$r['id'], 'path' => $r['path'], 'rev' => (int)$r['rev'], 'sha' => $r['sha'], 'off' => $off, 'len' => $len,
                'line' => $line, 'before' => pf_label($ln, 300), 'manual' => $manual, 'what' => $what];
            if ($new !== null) $h['after'] = pf_label(substr_replace($ln, $new, $off - $start, $len), 300);
            $hits[] = $h;
        }
    }
    return $hits;
}

/** בדיקת המפתח החדש. מחזיר [הישן, החדש, תווית] או זורק. */
function bind_target(string $kind, array $b): array
{
    $old = (string)($b['key'] ?? '');
    $new = (string)($b['to'] ?? '');
    if ($kind === 'field') {
        $fields = fields_all();
        $f = array_column($fields, null, 'key')[$old] ?? throw new ApiError('השדה לא נמצא', 404);
        if ($new === $old) throw new ApiError('המפתח לא השתנה');
        if ($e = field_key_error($new, $fields, $old)) throw new ApiError($e);
        return [$old, $new, $f];
    }
    if ($kind !== 'topic') throw new ApiError('בקשה לא תקינה');
    $all = topics_all();
    $t = $all[$old] ?? throw new ApiError('הנושא לא נמצא', 404);
    if ($new === $old) throw new ApiError('המפתח לא השתנה');
    if (!preg_match(TOPIC_SLUG_RX, $new)) throw new ApiError('מפתח לא תקין — אותיות לטיניות קטנות, ספרות ו-_ , מתחיל באות, עד 40');
    if (isset($all[$new])) throw new ApiError("כבר יש נושא עם המפתח \"$new\"", 409);
    return [$old, $new, $t];
}

/** "שנה מפתח": התצוגה — כל הקשירות (קובץ · שורה · לפני ← אחרי), ומה ישתנה בטבלה. שום דבר לא נכתב. */
function field_key_preview(array $user, array $b): array
{
    $kind = (string)($b['kind'] ?? 'field');
    [$old, $new, $x] = bind_target($kind, $b);
    $hits = fields_bind_scan($kind, $old, $new);
    $values = 0;
    if ($kind === 'field' && $x['col'] === null) foreach (words_rows() as $w) if (array_key_exists($old, $w['extra'])) $values++;
    return ['kind' => $kind, 'from' => $old, 'to' => $new, 'hits' => array_map(fn($h) => array_diff_key($h, ['sha' => 1]), $hits), 'values' => $values,
        'words' => $kind === 'topic' ? $x['count'] : null, 'files' => count(array_unique(array_column($hits, 'file')))];
}

/**
 * "החל": המבנה, הערכים בכל המילים (extra) והקבצים — עסקה אחת. revs — {id קובץ: rev} מהתצוגה; קובץ שהשתנה מאז — 409 (כמו F2).
 * skip — מזהי קשירות שבוטל הסימון שלהן. "לבדיקה ידנית" — אף פעם לא מוחלף. לפני — נקודת שחזור.
 */
function field_key_apply(array $user, array $b): array
{
    $kind = (string)($b['kind'] ?? 'field');
    [$old, $new, $x] = bind_target($kind, $b);
    pf_guard($user);
    $skip = array_flip(array_map('strval', is_array($b['skip'] ?? null) ? $b['skip'] : []));
    $revs = is_array($b['revs'] ?? null) ? $b['revs'] : [];
    $note = ($kind === 'field' ? 'שינוי מפתח' : 'שינוי מפתח נושא') . " `$old` → `$new`";
    $snap = snapshot_create($user, "לפני $note");
    $done = pf_tx(function () use ($kind, $old, $new, $x, $skip, $revs, $user, $note) {
        $hits = array_values(array_filter(fields_bind_scan($kind, $old), fn($h) => !$h['manual'] && !isset($skip[$h['id']])));
        $stale = [];
        foreach ($hits as $h) if ((int)($revs[$h['file']] ?? $revs[(string)$h['file']] ?? -1) !== $h['rev']) $stale[$h['path']] = true;
        if ($stale) throw new ApiError('קבצים השתנו מאז התצוגה: ' . implode(', ', array_slice(array_keys($stale), 0, 10)) . '. הציגו שוב', 409, ['stale' => array_keys($stale)]);
        $pdo = db();
        if ($kind === 'field') {
            fields_fill();
            $pdo->prepare('UPDATE word_fields SET key = ? WHERE key = ?')->execute([$new, $old]);
            if ($x['type'] === 'audio' || $x['col'] === 'audio') {   // N2b: ההקלטות של השדה — אותה עסקה
                require_once __DIR__ . '/rec_actions.php';
                rec_field_renamed($old, $new, $user);
            }
            if ($x['col'] === null) {                                 // הערכים: המפתח בכל מילה
                $put = $pdo->prepare('UPDATE words SET extra = ? WHERE id = ?');
                foreach ($pdo->query("SELECT id, extra FROM words WHERE extra <> '{}'")->fetchAll() as $r) {
                    $e = json_decode((string)$r['extra'], true) ?: [];
                    if (!array_key_exists($old, $e)) continue;
                    $e[$new] = $e[$old];
                    unset($e[$old]);
                    ksort($e);
                    $put->execute([json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $r['id']]);
                }
            }
        } else {
            topics_fill();
            $pdo->prepare('UPDATE topics SET slug = ? WHERE slug = ?')->execute([$new, $old]);
        }
        $by = [];
        foreach ($hits as $h) $by[$h['file']][] = $h;
        foreach ($by as $id => $hs) {
            $r = pf_row((int)$id);
            if ($r['sha'] !== $hs[0]['sha']) throw new ApiError("\"{$r['path']}\" השתנה בינתיים", 409);
            $s = pf_blob_get($r['sha']);
            usort($hs, fn($a, $c) => $c['off'] <=> $a['off']);          // מהסוף — המיקומים אינם זזים
            foreach ($hs as $h) {
                if (substr($s, $h['off'], $h['len']) !== $old) throw new ApiError("\"{$r['path']}\" השתנה בינתיים", 409);
                $s = substr_replace($s, $new, $h['off'], $h['len']);
            }
            pf_put($r, $s, $user, $note);
        }
        return ['replaced' => count($hits), 'files' => count($by)];
    });
    audit_as($user, $kind === 'field' ? 'field_key' : 'topic_slug', "$old → $new · {$done['replaced']} קשירות ב-{$done['files']} קבצים");
    return $done + ['snapshot' => $snap, 'from' => $old, 'to' => $new, 'rev' => words_rev()];
}
