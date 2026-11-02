<?php
/**
 * 🔎 חיפוש, 🏷 סינון ו-⬇️ ייצוא בשיחה — docs/spec.md §5.11, שלב 12d.
 *
 * סינון: תוויות (כל אחת מהנבחרות), מוצמדות (לכולם או שלי), סוג, מי כתב, וטווח תאריכים.
 * חיפוש טקסט: ‏q — מחזיר את ההודעות שמכילות אותו (הלקוח מדגיש ועובר ביניהן ▲▼).
 * ייצוא: Markdown של כל השיחה או של המסוננות — סימונים פרטיים רק של מי שמייצא.
 */
declare(strict_types=1);

/** הסוגים בממשק ← מה הם בהודעות. */
const SEARCH_KINDS = [
    'question' => "(m.kind IN ('question','choice'))",
    'decision' => "(m.kind IN ('decision','approval'))",
    'answer'   => "(m.kind IN ('answer','choice_answer'))",
    'task'     => '(m.task_id IS NOT NULL)',
    'system'   => "(m.author_role = 'system')",
];
/** מי כתב: הצ'אט, אנשים בלוח, Platform, מערכת. */
const SEARCH_AUTHORS = [
    'chat'     => "(m.author_role = 'chat')",
    'board'    => "(m.author_role NOT IN ('chat','system','platform'))",
    'platform' => "(m.author_role = 'platform')",
    'system'   => "(m.author_role = 'system')",
];

/** מנקה את הסינון מהלקוח. מחזיר [where, args, active?]. */
function searchWhere(array $user, array $channel, array $f): array {
    $where = ['m.channel_id = ?'];
    $args = [(int) $channel['id']];
    $q = trim((string) ($f['q'] ?? ''));
    if ($q !== '') {
        // LIKE של SQLite לא רגיש לרישיות רק באנגלית — ובעברית אין רישיות, כך שזה מספיק
        $where[] = "m.body LIKE ? ESCAPE '\\'";
        $args[] = '%' . addcslashes(mb_substr($q, 0, 200), '%_\\') . '%';
    }
    $labels = array_values(array_filter(array_map('intval', (array) ($f['labels'] ?? []))));
    if ($labels) {
        // רק תוויות שהצופה רואה — פרטית של אחר לא משמשת לסינון
        $ok = array_values(array_filter($labels, function ($id) use ($user, $channel) {
            try { return labelVisibleTo(getLabel($id), $user, (int) $channel['project_id']); } catch (AppError) { return false; }
        }));
        if (!$ok) $ok = [0];
        $where[] = 'EXISTS (SELECT 1 FROM message_labels ml WHERE ml.message_id = m.id AND ml.label_id IN ('
                 . implode(',', array_fill(0, count($ok), '?')) . '))';
        array_push($args, ...$ok);
    }
    if (!empty($f['pinned'])) {
        $where[] = "EXISTS (SELECT 1 FROM message_pins p WHERE p.message_id = m.id AND (p.visibility = 'shared' OR p.user_id = ?))";
        $args[] = (int) $user['id'];
    }
    $kinds = array_values(array_intersect((array) ($f['kinds'] ?? []), array_keys(SEARCH_KINDS)));
    if ($kinds) $where[] = '(' . implode(' OR ', array_map(fn ($k) => SEARCH_KINDS[$k], $kinds)) . ')';
    $authors = array_values(array_intersect((array) ($f['authors'] ?? []), array_keys(SEARCH_AUTHORS)));
    if ($authors) $where[] = '(' . implode(' OR ', array_map(fn ($a) => SEARCH_AUTHORS[$a], $authors)) . ')';
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        $d = (string) ($f[$k] ?? '');
        if ($d === '') continue;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) throw new InvalidArgumentException("$k: תאריך YYYY-MM-DD");
        // ‏to — עד סוף היום (שעון השרת)
        $ts = strtotime($d . ($k === 'to' ? ' 23:59:59' : ' 00:00:00'));
        $where[] = "m.created_at $op ?";
        $args[] = $ts;
    }
    return [implode(' AND ', $where), $args];
}

/** ההודעות שמתאימות (עם 📌 ותוויות), מהישנה לחדשה. עד 500. */
function channelSearch(array $user, int $channelId, array $f): array {
    $c = channelForUser($user, $channelId);
    [$where, $args] = searchWhere($user, $c, $f);
    $rows = messageRows($where, $args, 500);
    return decorateMessages($user, $rows, (int) $c['project_id']);
}

/** ⬇️ Markdown של השיחה (או המסוננות). שם קובץ + תוכן. */
function channelExport(array $user, int $channelId, array $f): array {
    $c = channelForUser($user, $channelId);
    $filtered = array_filter($f, fn ($v) => $v !== '' && $v !== [] && $v !== null && $v !== false);
    $rows = [];
    if ($filtered) {
        $rows = channelSearch($user, $channelId, $f);
    } else {
        // כל השיחה — בדפים, כי messageRows חוסם ב-500
        for ($after = 0; ; ) {
            $page = decorateMessages($user, messageRows('m.channel_id = ? AND m.id > ?', [$channelId, $after], 500), (int) $c['project_id']);
            if (!$page) break;
            array_push($rows, ...$page);
            $after = end($page)['id'];
            if (count($page) < 500) break;
        }
    }
    $who = ['chat' => 'הצ\'אט', 'system' => 'מערכת', 'platform' => 'Platform'];
    $out = ["# {$c['name']} — ערוץ #{$c['id']}", '', projectPathName((int) $c['project_id']) . ' · יוצא ' . date('Y-m-d H:i')
           . ($filtered ? ' · מסונן (' . count($rows) . ' הודעות)' : ' · ' . count($rows) . ' הודעות'), ''];
    foreach ($rows as $m) {
        $author = $who[$m['author_role']] ?? ($m['author_name'] ?: $m['author_role']);
        $marks = [];
        if ($m['pinned']['shared']) $marks[] = '📌';
        if ($m['pinned']['mine']) $marks[] = '📌🔒';
        foreach ($m['labels'] as $l) $marks[] = ($l['visibility'] === 'private' ? '🔒' : '') . "[{$l['name']}]";
        $out[] = '---';
        $out[] = '';
        $out[] = "### $author · " . date('Y-m-d H:i', $m['created_at']) . " · #{$m['id']}" . ($marks ? ' · ' . implode(' ', $marks) : '');
        if ($m['reply_to']) $out[] = "_תשובה להודעה #{$m['reply_to']}_";
        $out[] = '';
        $out[] = (string) $m['body'];
        $out[] = '';
    }
    // שם קובץ באנגלית: דפדפנים מסוימים מחליפים שם בעברית ב-"download"
    return ['filename' => "chat-{$c['id']}-" . date('Y-m-d') . ($filtered ? '-filtered' : '') . '.md',
            'markdown' => implode("\n", $out), 'count' => count($rows)];
}
