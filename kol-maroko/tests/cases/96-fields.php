<?php
/**
 * פרוסה N2 — שדות מותאמים למילה ("מבנה המילה"), ערכים ב-words.extra, "מתפרסם" (docs/spec-v2/slices/N2-custom-fields.md).
 * כל בדיקה מול קריטריון של הפרוסה, כולל קלט עוין והרשאה שנדחית. הנתונים — של הבדיקה (n1_seed מ-95-words-file.php).
 */

declare(strict_types=1);

/** מצב נקי של N2: בלי שדות שמורים (ארבעת המובנים — בזיכרון), בלי סל שדות, ונושאים בלי סדר/צבע/תמונה. */
function n2_reset(int $n = 12): array
{
    $ids = n1_seed($n);
    db()->exec("DELETE FROM word_fields; DELETE FROM field_trash; DELETE FROM topic_trash; UPDATE words SET extra = '{}'");
    return $ids;
}

/** הקובץ כפי ש-N1 בנתה אותו (העתק של words_json לפני N2) — להשוואת בייטים. */
function n2_n1_json(): string
{
    $rows = words_rows();
    $map = topic_slugs();
    $count = [];
    foreach ($rows as $w) foreach ($w['topics'] as $t) $count[$t] = ($count[$t] ?? 0) + 1;
    $topics = [];
    foreach ($count as $name => $n) $topics[] = ['name' => (string)$name, 'slug' => topic_slug((string)$name, $map)];
    $topics = array_map(fn($t) => ['id' => $t['slug'], 'label' => $t['name']], lex_sort($topics, 'name'));
    $meta = files_meta(array_values(array_unique(array_filter(array_map(fn($w) => file_id($w['audio']), $rows)))));
    $items = [];
    foreach ($rows as $w) {
        $item = ['id' => $w['id'], 'word' => $w['word'], 'meaning' => $w['meaning'], 'topics' => array_map(fn($t) => topic_slug((string)$t, $map), $w['topics']),
            'audio' => words_audio_ref((string)$w['audio'], $meta)];
        if ($w['wp_id'] !== null) $item['wp_id'] = $w['wp_id'];
        if ((string)$w['external_id'] !== '') $item['external_id'] = $w['external_id'];
        $items[] = $item;
    }
    return words_json_encode(['topics' => $topics, 'items' => $items]);
}

test('N2 מיגרציה: אחרי המיגרציה words.json זהה בבייטים לזה של N1; קריאה אינה כותבת שדות; המילוי אידמפוטנטי', function () {
    n2_reset(40);
    eq(n2_n1_json(), words_json(), 'הקובץ של העורך — זהה בבייטים');
    eq(0, (int)db()->query('SELECT COUNT(*) FROM word_fields')->fetchColumn(), 'קריאה אינה כותבת');
    eq(['word', 'meaning', 'topics', 'audio'], array_column(fields_all(), 'key'), 'ארבעת המובנים בזיכרון');
    fields_fill();
    fields_fill();
    eq(4, (int)db()->query('SELECT COUNT(*) FROM word_fields')->fetchColumn(), 'המילוי אידמפוטנטי');
    topics_fill();
    topics_fill();
    eq(n2_n1_json(), words_json(), 'אחרי המילוי (שדות + סדר נושאים + שורות לנושאים חסרים) — עדיין זהה');
    $t = db()->query("SELECT slug, sort_order FROM topics WHERE name = 'נושא שאינו בטבלה'")->fetch();
    eq('kt-' . substr(md5('נושא שאינו בטבלה'), 0, 10), $t['slug'], 'נושא בלי שורה קיבל שורה עם ה-slug שהיה לו');
    ok($t['sort_order'] > 0, 'ולו סדר');
    $live = words_json('/app/');
    ok(!str_contains($live, '"hidden"') && !str_contains($live, '"color"'), 'בלי צבע/מוסתר — אין מפתחות חדשים');
});
