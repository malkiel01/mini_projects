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
 * counts.audio = מספר המילים שיש להן הקלטה = מספר השורות שהספרייה מציגה כהקלטות.
 */
function library_list(): array
{
    $words = words_rows();
    $recs = rec_rows();                       // A2: המצב, הקובץ והמקור — מהמודל
    $ids = array_values(array_unique(array_filter(array_map(fn($r) => (string)$r['file_id'], $recs))));
    $files = files_meta($ids);

    // מילים שמצביעות על אותה הקלטה — כל אחת רשומה בשורה של האחרת
    $share = [];
    foreach ($words as $w) if ($w['audio'] !== '') $share[$w['audio']][] = $w['id'];

    $rows = [];
    $counts = ['words' => count($words), 'audio' => 0, 'site' => 0, 'tool' => 0, 'none' => 0, 'missing' => 0, 'failed' => 0];
    foreach ($words as $w) {
        $rec = $w['audio'] !== '' ? ($recs[$w['id']] ?? null) : null;
        $state = $rec ? ($rec['state'] === 'tool' ? 'tool' : 'site') : 'none';
        $counts[$state]++;
        if ($state !== 'none') $counts['audio']++;
        $row = ['id' => $w['id'], 'word' => $w['word'], 'meaning' => library_plain($w['meaning']), 'topics' => $w['topics'],
            'external_id' => $w['external_id'], 'state' => $state, 'src' => '', 'missing' => false];
        if ($state === 'site') {
            $row['src'] = $rec['source'];
            if ($rec['state'] === 'failed') {             // ההבאה נכשלה — מתנגנת עדיין מהאתר, עם הסיבה
                $row['fetch'] = ['code' => $rec['f_code'], 'reason' => REC_REASONS[$rec['f_code']] ?? $rec['f_code']];
                $counts['failed']++;
            }
        } elseif ($state === 'tool') {
            $f = $files[$rec['file_id']] ?? null;
            if ($f && empty($f['deleted_at'])) {
                $row['src'] = media_url($f['id']);
                $row['file'] = ['id' => $f['id'], 'name' => $f['name'], 'size' => (int)$f['size'], 'at' => $f['at'], 'by' => $f['by_name'],
                    'origin' => (string)($rec['source'] ?: ($f['origin'] ?? '')), 'type' => $f['type'],
                    'duration' => $rec['duration'] === null ? null : (float)$rec['duration'], 'how' => $rec['how']];
            } else {                                  // המילה מצביעה על קובץ שאינו בספרייה — מופיעה, מסומנת
                $row['missing'] = true;
                $counts['missing']++;
            }
        }
        if ($state !== 'none' && count($share[$w['audio']]) > 1) {
            $row['shared'] = array_values(array_filter($share[$w['audio']], fn($id) => $id !== $w['id']));
        }
        $rows[] = $row;
    }
    $map = topic_slugs();
    return ['rows' => $rows, 'counts' => $counts, 'topics' => array_map(fn($t) => ['name' => $t['name'], 'count' => $t['count']], topics_used($words, $map)),
        'host' => lex_host(), 'images' => library_site_images()];
}
