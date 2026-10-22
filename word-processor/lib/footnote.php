<?php
/**
 * הערת שוליים — attrs.text: הטקסט הפשוט (תמיד; חיפוש, טקסט, תאימות); attrs.content: אותו טקסט עם עיצוב —
 * צמתי text עם marks מתוך FN_MARKS, או null כשאין עיצוב. משותף ל-Word (export/import) ול-PDF מהשרת.
 */

const FN_MARKS = ['bold', 'italic', 'underline', 'strike', 'superscript', 'subscript'];

/** תוכן ההערה כצמתי text (רק עיצוב מותר); בלי content — הטקסט הפשוט. */
function fn_inline(array $attrs): array
{
    $out = [];
    foreach (is_array($attrs['content'] ?? null) ? $attrs['content'] : [] as $n) {
        if (!is_array($n) || ($n['type'] ?? '') !== 'text' || !is_string($n['text'] ?? null) || $n['text'] === '') continue;
        $marks = [];
        foreach (is_array($n['marks'] ?? null) ? $n['marks'] : [] as $m) {
            $t = is_array($m) ? ($m['type'] ?? '') : '';
            if (in_array($t, FN_MARKS, true)) $marks[] = ['type' => $t];
        }
        $out[] = ['type' => 'text', 'text' => $n['text']] + ($marks ? ['marks' => $marks] : []);
    }
    if (!$out && ($t = trim((string)($attrs['text'] ?? ''))) !== '') $out[] = ['type' => 'text', 'text' => $t];
    return $out;
}

/**
 * המסמך עם מספר לכל הערה (attrs._n: "1", "2"… לשוליים; "i", "ii"… לסיום — כמו בעורך וב-PDF), והרשימות לפי הסדר:
 * [json, [[n, attrs]…] שוליים, [[n, attrs]…] סיום]. ל-HTML ולטקסט, שאין בהם עמודים — ההערות מרוכזות בסוף.
 */
function fn_numbered(array $json): array
{
    $foot = [];
    $end = [];
    $walk = function (array $nodes) use (&$walk, &$foot, &$end) {
        foreach ($nodes as $i => $n) {
            if (!is_array($n)) continue;
            if (($n['type'] ?? '') === 'footnote') {
                $a = is_array($n['attrs'] ?? null) ? $n['attrs'] : [];
                if (!empty($a['end'])) { $a['_n'] = roman_lower(count($end) + 1); $end[] = [$a['_n'], $a]; }
                else { $a['_n'] = (string)(count($foot) + 1); $foot[] = [$a['_n'], $a]; }
                $nodes[$i]['attrs'] = $a;
            } elseif (is_array($n['content'] ?? null)) $nodes[$i]['content'] = $walk($n['content']);
        }
        return $nodes;
    };
    if (is_array($json['content'] ?? null)) $json['content'] = $walk($json['content']);
    return [$json, $foot, $end];
}
