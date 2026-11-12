<?php
/**
 * ספריית ההקלטות (פרוסה A1): כל הקלטה שהכלי יודע עליה — גם כזו שהיא עדיין רק כתובת באתר — כשורה אחת.
 *
 * המפתח של הקלטה הוא מזהה המילה (docs/spec-v2/02-project-model.md §8.1). הכתובת היא רק המקום שממנו משמיעים היום.
 *
 * ⚠️ קריאה בלבד. הקובץ הזה אינו כותב למסד, אינו נוגע בקבצים, ואינו פונה לשום כתובת חיצונית:
 *    ההשמעה היא של הדפדפן, ישירות מהכתובת הציבורית. (נבדק ב-tests/library_test.php.)
 *    מ-A2 המצב נקרא ממודל ההקלטות (lib/recordings.php — rec_rows מסנכרן את הטבלה recordings בלבד; המילים לא משתנות).
 */

declare(strict_types=1);

require_once __DIR__ . '/page.php';
require_once __DIR__ . '/recordings.php';
require_once __DIR__ . '/rec_actions.php';      // A3: הפעולות על הקלטה

/** פירוש (HTML) → שורה אחת של טקסט, לרשימה ולחיפוש. */
function library_plain(string $html): string
{
    return trim((string)preg_replace('/\s+/u', ' ', lex_plain($html)));
}

/** מצב ההקלטה של מילה: site — כתובת באתר · tool — קובץ בכלי · none — אין. */
function library_state(string $audio): string
{
    return $audio === '' ? 'none' : (file_id($audio) ? 'tool' : 'site');
}

/**
 * תמונות של המילון שהן עדיין כתובת באתר (היום: תמונת הרקע — hero_url ברכיב המילון שבטיוטה).
 * קוראים את הטיוטה כמו שהיא; אם עוד אין טיוטה — לא יוצרים אותה.
 */
function library_site_images(): array
{
    $json = db()->query('SELECT json FROM draft WHERE id = 1')->fetchColumn();
    $page = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($page)) return [];
    $out = [];
    $walk = function (array $node) use (&$walk, &$out): void {
        if (($node['type'] ?? null) === 'lexicon' && is_array($node['props'] ?? null)) {
            $url = lex_url($node['props']['hero_url'] ?? '');
            if ($url !== '' && !isset($out[$url])) {
                $out[$url] = ['url' => $url, 'label' => 'תמונת הרקע של המילון', 'state' => 'site',
                    'replaced' => file_id($node['props']['hero_file'] ?? '') !== ''];
            }
        }
        foreach ($node as $v) if (is_array($v)) $walk($v);
    };
    $walk($page);
    return array_values($out);
}

/**
 * הרשימה המלאה: שורה לכל מילה (גם בלי הקלטה — לסינון "מילים בלי הקלטה"), והמונה.
 * counts.audio = מספר המקומות שיש בהם הקלטה = מספר השורות שהספרייה מציגה כהקלטות.
 * N2b: שורה לכל מקום — מילה × שדה שמע. השדה הראשי — המזהה של השורה הוא מזהה המילה (כמו לפני); שדה מותאם — "<מזהה>:<מפתח>".
 * כל שורה: wid (המילה), field, field_label, internal (שדה שאינו מתפרסם). שדה מותאם הוא תמיד "בכלי" (מזהה קובץ).
 */
function library_list(): array
{
    $words = words_rows();
    $recs = rec_rows();                       // A2: המצב, הקובץ והמקור — מהמודל
    $fields = rec_audio_fields();
    $primary = rec_primary();
    $custom = array_values(array_filter($fields, fn($f) => !$f['primary']));
    $crecs = $custom ? rec_rows_custom() : [];
    $lfields = rec_list_fields();               // N3b: שדות השמע של הרשימות
    $multi = $custom || $lfields;
    $plabel = $fields[$primary]['label'] ?? 'הקלטה';
    // A3: הקלטות שאינן מחוברות למילה (לא בסל), ומספר הגרסאות של כל הקלטה
    $loose = rec_db()->query('SELECT * FROM recordings WHERE word_id IS NULL AND deleted_at IS NULL ORDER BY id')->fetchAll();
    $nv = [];
    foreach (rec_db()->query('SELECT rec_id, COUNT(*) AS n FROM rec_versions GROUP BY rec_id')->fetchAll() as $v) $nv[(int)$v['rec_id']] = (int)$v['n'];
    $all = [...array_values($recs), ...$loose];
    foreach ($crecs as $byKey) foreach ($byKey as $r) $all[] = $r;
    $lrecs = [];
    if ($lfields) foreach (rec_db()->query("SELECT * FROM recordings WHERE list != 'words' AND word_id IS NOT NULL")->fetchAll() as $r) {
        $lrecs[$r['list'] . '|' . (int)$r['word_id'] . '|' . $r['field']] = $r;
        $all[] = $r;
    }
    $ids = array_values(array_unique(array_filter(array_map(fn($r) => (string)$r['file_id'], $all))));
    $files = files_meta($ids);
    $fileOf = function (array $rec) use ($files): ?array {
        $f = $files[$rec['file_id']] ?? null;
        if (!$f || !empty($f['deleted_at'])) return null;
        return ['id' => $f['id'], 'name' => $f['name'], 'size' => (int)$f['size'], 'at' => $f['at'], 'by' => $f['by_name'],
            'origin' => (string)($rec['source'] ?: ($f['origin'] ?? '')), 'type' => $f['type'], 'sha' => $f['sha256'],
            'duration' => $rec['duration'] === null ? null : (float)$rec['duration'], 'how' => $rec['how']];
    };

    // מילים שמצביעות על אותה הקלטה — כל אחת רשומה בשורה של האחרת
    $share = [];
    foreach ($words as $w) if ($w['audio'] !== '') $share[$w['audio']][] = $w['id'];

    $rows = [];
    $counts = ['words' => count($words), 'audio' => 0, 'site' => 0, 'tool' => 0, 'none' => 0, 'missing' => 0, 'failed' => 0, 'loose' => 0, 'trash' => 0];
    $byField = [];
    foreach ($fields as $k => $f) $byField[$k] = ['audio' => 0, 'none' => 0];
    foreach ($words as $w) {
        $rec = $w['audio'] !== '' ? ($recs[$w['id']] ?? null) : null;
        $state = $rec ? ($rec['state'] === 'tool' ? 'tool' : 'site') : 'none';
        $counts[$state]++;
        if ($state !== 'none') $counts['audio']++;
        $row = ['id' => $w['id'], 'word' => $w['word'], 'meaning' => library_plain($w['meaning']), 'topics' => $w['topics'],
            'external_id' => $w['external_id'], 'state' => $state, 'src' => '', 'missing' => false];
        if ($rec) $row += ['rec' => (int)$rec['id'], 'name' => $rec['name'], 'versions' => $nv[(int)$rec['id']] ?? 0];
        if ($state === 'site') {
            $row['src'] = $rec['source'];
            if ($rec['state'] === 'failed') {             // ההבאה נכשלה — מתנגנת עדיין מהאתר, עם הסיבה
                $row['fetch'] = ['code' => $rec['f_code'], 'reason' => REC_REASONS[$rec['f_code']] ?? $rec['f_code']];
                $counts['failed']++;
            }
        } elseif ($state === 'tool') {
            if ($file = $fileOf($rec)) {
                $row['src'] = media_url($file['id']);
                $row['file'] = $file;
            } else {                                  // המילה מצביעה על קובץ שאינו בספרייה — מופיעה, מסומנת
                $row['missing'] = true;
                $counts['missing']++;
            }
        }
        if ($state !== 'none' && count($share[$w['audio']]) > 1) {
            $row['shared'] = array_values(array_filter($share[$w['audio']], fn($id) => $id !== $w['id']));
        }
        $byField[$primary][$state === 'none' ? 'none' : 'audio']++;
        if ($multi) $row += ['wid' => $w['id'], 'field' => $primary, 'field_label' => $plabel, 'internal' => !($fields[$primary]['published'] ?? true)];
        $rows[] = $row;
        // N2b: כל שדה שמע מותאם — שורה (הקלטה, או "חסרה")
        foreach ($custom as $f) {
            $rec = isset($w['extra'][$f['key']]) ? ($crecs[$w['id']][$f['key']] ?? null) : null;
            $st2 = $rec ? 'tool' : 'none';
            $counts[$st2]++;
            if ($rec) $counts['audio']++;
            $byField[$f['key']][$rec ? 'audio' : 'none']++;
            $cr = ['id' => $w['id'] . ':' . $f['key'], 'wid' => $w['id'], 'field' => $f['key'], 'field_label' => $f['label'], 'internal' => !$f['published'],
                'word' => $w['word'], 'meaning' => $row['meaning'], 'topics' => $w['topics'], 'external_id' => $w['external_id'], 'state' => $st2, 'src' => '', 'missing' => false];
            if ($rec) {
                $cr += ['rec' => (int)$rec['id'], 'name' => $rec['name'], 'versions' => $nv[(int)$rec['id']] ?? 0];
                if ($file = $fileOf($rec)) { $cr['src'] = media_url($file['id']); $cr['file'] = $file; }
                else { $cr['missing'] = true; $counts['missing']++; }
            }
            $rows[] = $cr;
        }
    }
    // N3b: כל פריט × כל שדה audio של כל רשימה — שורה (הקלטה, או "חסרה"). המזהה בדפדפן: "<רשימה>:<פריט>:<מפתח>" (מפתח רשימה
    // מתחיל באות — לא מתנגש ב-"<מזהה מילה>:<מפתח>"); השדה — "<רשימה>.<מפתח>" (rec_fkey), וכך כל הפעולות של הספרייה (החלפה,
    // חיבור, גרירה, סינון) עובדות עליו כמו על שדה של המילים.
    if ($lfields) {
        foreach ($lfields as $lk => $afs) {
            $l = list_get($lk);
            $fs = list_fields($lk);
            foreach ($afs as $fk => $f) $byField[rec_fkey($lk, $fk)] = ['audio' => 0, 'none' => 0];
            foreach (list_rows($lk) as $it) {
                $title = rec_item_title($it['data'], $fs, $l['item_label'], $it['id']);
                foreach ($afs as $fk => $f) {
                    $key = rec_fkey($lk, $fk);
                    $rec = file_id($it['data'][$fk] ?? null) ? ($lrecs["$lk|{$it['id']}|$fk"] ?? null) : null;
                    $st2 = $rec ? 'tool' : 'none';
                    $counts[$st2]++;
                    if ($rec) $counts['audio']++;
                    $byField[$key][$rec ? 'audio' : 'none']++;
                    $lr = ['id' => "$lk:{$it['id']}:$fk", 'wid' => $it['id'], 'list' => $lk, 'list_label' => $l['label'], 'field' => $key, 'field_label' => $l['label'] . ' · ' . $f['label'],
                        'internal' => !$f['published'], 'word' => $title, 'meaning' => '', 'topics' => [], 'external_id' => '', 'state' => $st2, 'src' => '', 'missing' => false,
                        'path' => rec_tree_path($it['id'], $fk, '', $lk)];
                    if ($rec) {
                        $lr += ['rec' => (int)$rec['id'], 'name' => $rec['name'], 'versions' => $nv[(int)$rec['id']] ?? 0];
                        if ($file = $fileOf($rec)) { $lr['src'] = media_url($file['id']); $lr['file'] = $file; $lr['path'] .= '.' . $files[$file['id']]['ext']; }
                        else { $lr['missing'] = true; $counts['missing']++; }
                    }
                    $rows[] = $lr;
                }
            }
        }
    }
    // A3: שורה לכל הקלטה לא מחוברת — המפתח שלה בדפדפן שלילי (-מזהה הרשומה), כדי לא להתנגש במזהה מילה
    $extra = [];
    foreach ($loose as $rec) {
        $file = $fileOf($rec);
        $state = $file ? 'tool' : 'site';
        $counts['loose']++;
        $extra[] = ['id' => -(int)$rec['id'], 'rec' => (int)$rec['id'], 'loose' => true, 'word' => $rec['name'] ?: 'הקלטה', 'name' => $rec['name'], 'meaning' => '',
            'topics' => [], 'external_id' => '', 'state' => $state, 'src' => $file ? media_url($file['id']) : $rec['source'],
            'missing' => !$file && $rec['file_id'] !== null, 'versions' => $nv[(int)$rec['id']] ?? 0] + ($file ? ['file' => $file] : [])
            // N2b: השדה שממנו התנתקה; שדה שכבר אינו קיים — "משדה שנמחק: <שם>"; N3b: רשימה שנמחקה — "מרשימה שנמחקה: <שם>"
            + library_loose_field($rec, $fields, $lfields, $multi);
    }
    $trash = rec_trash();
    $counts['trash'] = count($trash);
    $map = topic_slugs();
    $counts['fields'] = $byField;
    $fieldsOut = array_values(array_map(fn($f) => $f + ['short' => rec_field_short($f['label']), 'list' => 'words'], $fields));
    foreach ($lfields as $lk => $afs) foreach ($afs as $fk => $f) {
        $fieldsOut[] = ['key' => rec_fkey($lk, $fk), 'label' => $f['list_label'] . ' · ' . $f['label'], 'primary' => false, 'published' => $f['published'],
            'short' => $f['list_label'] . ' · ' . rec_field_short($f['label']), 'list' => $lk, 'list_label' => $f['list_label'], 'field' => $fk];
    }
    return ['rows' => $rows, 'loose' => $extra, 'fields' => $fieldsOut, 'trash' => $trash, 'versions' => rec_versions_usage(), 'limits' => rec_limits(), 'counts' => $counts, 'topics' => array_map(fn($t) => ['name' => $t['name'], 'count' => $t['count']], topics_used($words, $map)),
        'host' => lex_host(), 'images' => library_site_images()];
}

/**
 * N3b: השדה של הקלטה לא מחוברת. במילים — כמו ב-N2b (רק כשיש יותר משדה אחד, או כשהשדה נמחק: "dropped"); ברשימה — תמיד,
 * כ-"<רשימה>.<מפתח>", ו-gone: "מרשימה שנמחקה: <שם>" / "משדה שנמחק: <רשימה> · <שם>".
 */
function library_loose_field(array $rec, array $fields, array $lfields, bool $multi): array
{
    $list = (string)$rec['list'];
    if ($list === 'words') {
        if (!$multi && isset($fields[$rec['field']])) return [];
        return ['field' => $rec['field'], 'field_label' => rec_field_label($rec['field'])] + (isset($fields[$rec['field']]) ? [] : ['dropped' => rec_field_label($rec['field'])]);
    }
    $out = ['field' => rec_fkey($list, $rec['field']), 'field_label' => rec_field_label($rec['field'], $list), 'list' => $list];
    if (!isset($lfields[$list])) {
        $exists = (bool)array_key_exists($list, lists_all());
        if (!$exists) return $out + ['dropped' => rec_list_label($list), 'gone' => 'מרשימה שנמחקה: ' . rec_list_label($list)];
    }
    if (!isset($lfields[$list][$rec['field']])) return $out + ['dropped' => $out['field_label'], 'gone' => 'משדה שנמחק: ' . $out['field_label']];
    return $out;
}
