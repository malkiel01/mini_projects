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
