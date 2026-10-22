<?php
/**
 * השוואת גרסאות (שלב 4, כמו "השוואה" ב-Word): שני מסמכים → מסמך אחד שבו מה שהשתנה מסומן כמעקב שינויים — מה שיצא
 * (מהישן) בסימון del, מה שנוסף (בחדש) בסימון ins, עם המחבר "השוואה". את התוצאה מאשרים / דוחים כמו כל שינוי.
 *
 * האלגוריתם: הבלוקים העליונים מיושרים לפי LCS על החתימה שלהם (סוג + תוכן); בין שני בלוקים תואמים, בלוקים
 * שהשתנו מוצמדים לפי הסדר — פסקה מול פסקה (או כותרת מול כותרת) — ובתוכם השוואה מילה-מילה (LCS על מילים, רווחים
 * וצמתים שבשורה). בלוק שנמחק / נוסף כולו — כל הטקסט שבו (גם בטבלה, ברשימה) מסומן. בלוק שאינו טקסט (תמונה בפני
 * עצמה, מעבר עמוד) — נשאר מהחדש.
 */

require_once __DIR__ . '/track.php';

const COMPARE_AUTHOR = 'השוואה';
const COMPARE_MAX_CELLS = 250000;   // טבלת LCS עד גודל זה; מעליו — התאמה חמדנית בחלון (compare_lcs)

/** ההשוואה: $old — המסמך הישן, $new — החדש. מחזיר את המסמך החדש עם הסימונים (ובלי מעקב פועל). */
function track_compare(array $old, array $new, string $date = ''): array
{
    $date = $date ?: gmdate('Y-m-d\TH:i:s\Z');
    $a = array_values(array_filter($old['content'] ?? [], 'is_array'));
    $b = array_values(array_filter($new['content'] ?? [], 'is_array'));
    $sig = fn(array $n) => json_encode(compare_plain($n), JSON_UNESCAPED_UNICODE);
    $pairs = compare_lcs(array_map($sig, $a), array_map($sig, $b));
    $out = [];
    $i = 0; $j = 0;
    foreach (array_merge($pairs, [[count($a), count($b)]]) as [$pi, $pj]) {
        // הקטע שבין שני תואמים: מוצמדים לפי הסדר, מה שנשאר — נמחק / נוסף כולו
        $da = array_slice($a, $i, $pi - $i);
        $db = array_slice($b, $j, $pj - $j);
        $k = 0;
        for (; $k < min(count($da), count($db)); $k++) {
            if (compare_textblock($da[$k]) && compare_textblock($db[$k]) && ($da[$k]['type'] ?? '') === ($db[$k]['type'] ?? '')) {
                $out[] = compare_para($da[$k], $db[$k], $date);
            } else {
                $out[] = compare_mark($da[$k], 'del', $date);
                $out[] = compare_mark($db[$k], 'ins', $date);
            }
        }
        for ($x = $k; $x < count($da); $x++) $out[] = compare_mark($da[$x], 'del', $date);
        for ($x = $k; $x < count($db); $x++) $out[] = compare_mark($db[$x], 'ins', $date);
        if ($pi < count($a)) $out[] = $b[$pj];
        $i = $pi + 1; $j = $pj + 1;
    }
    $new['content'] = $out;
    unset($new['attrs']['track']);   // התוצאה — לסקירה; המעקב לא פועל בה
    return $new;
}

/** בלי סימוני מעקב קיימים (ins / del / fmt) — ההשוואה היא של הטקסט והעיצוב, לא של סימונים ישנים. */
function compare_plain(array $n): array
{
    if (isset($n['marks'])) {
        $n['marks'] = array_values(array_filter($n['marks'], fn($m) => !in_array($m['type'] ?? '', ['ins', 'del', 'fmt'], true)));
        if (!$n['marks']) unset($n['marks']);
    }
    if (is_array($n['content'] ?? null)) $n['content'] = array_map('compare_plain', array_values(array_filter($n['content'], 'is_array')));
    return $n;
}

function compare_textblock(array $n): bool
{
    return in_array($n['type'] ?? '', ['paragraph', 'heading'], true);
}

/** כל הטקסט והצמתים שבשורה בתוך הבלוק — בסימון $type (ins / del). */
function compare_mark(array $n, string $type, string $date): array
{
    if (!is_array($n['content'] ?? null)) return $n;
    $inline = compare_textblock($n);
    $n['content'] = array_map(function ($x) use ($inline, $type, $date) {
        if (!is_array($x)) return $x;
        if (!$inline) return compare_mark($x, $type, $date);
        // צומת בתוך פסקה (טקסט, תמונה בשורה, הערה…): הסימון במקום סימוני מעקב קודמים
        $x['marks'] = array_merge(array_values(array_filter($x['marks'] ?? [], fn($m) => !in_array($m['type'] ?? '', ['ins', 'del', 'fmt'], true))),
            [['type' => $type, 'attrs' => ['author' => COMPARE_AUTHOR, 'date' => $date]]]);
        return $x;
    }, $n['content']);
    return $n;
}

/** שתי גרסאות של אותה פסקה: התוכן — LCS על מילים; המאפיינים (יישור, סגנון) — של החדשה. */
function compare_para(array $old, array $new, string $date): array
{
    $ta = compare_tokens(compare_plain($old)['content'] ?? []);
    $tb = compare_tokens(compare_plain($new)['content'] ?? []);
    // מילה מושווית לפי הטקסט בלבד — מילה שרק העיצוב שלה השתנה היא שינוי עיצוב (fmt), לא מחיקה ותוספת
    $key = fn($t) => ($t['type'] ?? '') === 'text' ? 't:' . $t['text'] : json_encode($t, JSON_UNESCAPED_UNICODE);
    $out = [];
    $push = function (array $t, ?string $type) use (&$out, $date) {
        if ($type) $t['marks'] = array_merge($t['marks'] ?? [], [['type' => $type, 'attrs' => ['author' => COMPARE_AUTHOR, 'date' => $date]]]);
        if (empty($t['marks'])) unset($t['marks']);
        // טקסט צמוד עם אותם סימונים — צומת אחד
        $last = count($out) - 1;
        if ($last >= 0 && ($t['type'] ?? '') === 'text' && ($out[$last]['type'] ?? '') === 'text' && ($out[$last]['marks'] ?? []) == ($t['marks'] ?? [])) $out[$last]['text'] .= $t['text'];
        else $out[] = $t;
    };
    $pairs = compare_lcs(array_map($key, $ta), array_map($key, $tb));
    $i = 0; $j = 0;
    foreach (array_merge($pairs, [[count($ta), count($tb)]]) as [$pi, $pj]) {
        // כמו ב-Word: קודם מה שנמחק, אחריו מה שנוסף
        for (; $i < $pi; $i++) $push($ta[$i], 'del');
        for (; $j < $pj; $j++) $push($tb[$j], 'ins');
        if ($pi < count($ta)) {
            $t = $tb[$pj];
            $om = $ta[$pi]['marks'] ?? [];
            if (($t['type'] ?? '') === 'text' && $om != ($t['marks'] ?? []))
                $t['marks'] = array_merge($t['marks'] ?? [], [['type' => 'fmt', 'attrs' => ['author' => COMPARE_AUTHOR, 'date' => $date, 'old' => array_values(array_filter($om, fn($x) => in_array($x['type'] ?? '', TRACK_FORMAT, true)))]]]);
            $push($t, null);
        }
        $i = $pi + 1; $j = $pj + 1;
    }
    $new['content'] = $out;
    if (!$out) unset($new['content']);
    return $new;
}

/** תוכן פסקה → אסימונים: מילה / רווחים (עם הסימונים שלהם), וכל צומת שבשורה שאינו טקסט — אסימון אחד. */
function compare_tokens(array $content): array
{
    $out = [];
    foreach ($content as $n) {
        if (($n['type'] ?? '') !== 'text') { $out[] = $n; continue; }
        foreach (preg_split('/(\s+)/u', (string)($n['text'] ?? ''), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $w) {
            $t = ['type' => 'text', 'text' => $w];
            if (!empty($n['marks'])) $t['marks'] = $n['marks'];
            $out[] = $t;
        }
    }
    return $out;
}

/**
 * LCS בין שתי רשימות מחרוזות: זוגות [i, j] של איברים שווים, לפי הסדר. תחילה וסוף משותפים נחתכים לפני הטבלה
 * (רוב השינויים קטנים — הטבלה קטנה).
 */
function compare_lcs(array $a, array $b): array
{
    $n = count($a); $m = count($b);
    $pre = 0;
    while ($pre < $n && $pre < $m && $a[$pre] === $b[$pre]) $pre++;
    $suf = 0;
    while ($suf < $n - $pre && $suf < $m - $pre && $a[$n - 1 - $suf] === $b[$m - 1 - $suf]) $suf++;
    $out = [];
    for ($k = 0; $k < $pre; $k++) $out[] = [$k, $k];
    $A = array_slice($a, $pre, $n - $pre - $suf);
    $B = array_slice($b, $pre, $m - $pre - $suf);
    $x = count($A); $y = count($B);
    if ($x && $y && $x * $y <= COMPARE_MAX_CELLS) {
        // טבלת אורכים מהסוף (כולה — לשחזור הזוגות)
        $L = array_fill(0, $x + 1, array_fill(0, $y + 1, 0));
        for ($i = $x - 1; $i >= 0; $i--) {
            for ($j = $y - 1; $j >= 0; $j--) {
                $L[$i][$j] = $A[$i] === $B[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
            }
        }
        $i = 0; $j = 0;
        while ($i < $x && $j < $y) {
            if ($A[$i] === $B[$j]) { $out[] = [$pre + $i, $pre + $j]; $i++; $j++; }
            elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) $i++;
            else $j++;
        }
    }
    elseif ($x && $y) {
        // גדול מדי לטבלה: התאמה חמדנית — לכל איבר, ההופעה הבאה שלו ב-B בחלון של 200
        $j = 0;
        for ($i = 0; $i < $x && $j < $y; $i++) {
            for ($k = $j; $k < min($y, $j + 200); $k++) if ($A[$i] === $B[$k]) { $out[] = [$pre + $i, $pre + $k]; $j = $k + 1; break; }
        }
    }
    for ($k = 0; $k < $suf; $k++) $out[] = [$n - $suf + $k, $m - $suf + $k];
    return $out;
}
