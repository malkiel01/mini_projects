<?php
/**
 * קריאת המסמכים של החבילה — משותף לדשבורד, למרכז ולייבוא ללוח. רק פונקציות, בלי פלט.
 */
declare(strict_types=1);

const DOCS = __DIR__ . '/../../docs';
const ROOT = __DIR__ . '/../../..';
/** מצב שלב במסמך מעקב של אפליקציה → משקל. 👤 (ממתין לאימות) לא משנה את המשקל. */
const STAGE_WEIGHT = ['✅' => 1.0, '🟡' => 0.5, '⏳' => 0.5, '⬜' => 0.0];
const KIND_ICON = ['text' => '📝', 'raster' => '🖌️', 'vector' => '✒️', 'layout' => '📰', 'design' => '🎨'];
/** פרויקט בלי kind — לפי התיקייה. */
const FOLDER_ICON = ['suite-cloud' => '☁️', 'creative-suite/ui-core' => '🧩'];

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** markdown בשורה: מודגש, קוד, קישור (רק הטקסט). */
function inline(string $s): string
{
    $s = h($s);
    $s = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $s);
    $s = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $s);
    return preg_replace('/`([^`]+)`/u', '<code>$1</code>', $s);
}

function plain(string $s): string { return trim(html_entity_decode(strip_tags(inline($s)), ENT_QUOTES, 'UTF-8')); }

/** תחילת טקסט הסטטוס — עד " — " או סוגריים. */
function status_label(string $status): string { return trim(preg_split('/\s+[—(]/u', plain($status))[0]); }

/** שורות הקטע שכותרתו מתחילה ב-$title, עד הכותרת הבאה באותה רמה. */
function section(string $md, string $title): array
{
    $out = []; $in = false;
    foreach (preg_split('/\R/u', $md) as $line) {
        if (str_starts_with($line, '## ')) {
            if ($in) break;
            $in = str_starts_with(trim(substr($line, 3)), $title);
            continue;
        }
        if ($in) $out[] = $line;
    }
    return $out;
}

/**
 * מסמך המעקב של האפליקציה עצמה (למשל word-processor/docs/roadmap.md) — רק קריאה.
 * הטבלה בקטע "תמונת מצב": | שלב | שם | מצב | בקצרה |, והמצב — ✅ / 🟡 / ⏳ / ⬜.
 */
function roadmap(string $rel): ?array
{
    if (!preg_match('#^[a-z0-9-]+/docs/[a-z0-9-]+\.md$#', $rel)) return null;
    $md = @file_get_contents(ROOT . '/' . $rel);
    if ($md === false) return ['file' => $rel, 'missing' => true, 'stages' => [], 'pct' => 0];
    $stages = [];
    foreach (section($md, 'תמונת מצב') as $line) {
        if (!preg_match('/^\|\s*(\d+)\s*\|/u', $line)) continue;
        $c = array_map('trim', explode('|', trim($line, " |")));
        if (count($c) < 3) continue;
        preg_match('/✅|🟡|⏳|⬜/u', $c[2], $m);
        $mark = $m[0] ?? '⬜';
        $stages[] = ['num' => $c[0], 'name' => plain($c[1]), 'mark' => $mark, 'short' => $c[3] ?? '',
                     'w' => STAGE_WEIGHT[$mark]];
    }
    $pct = $stages ? (int)round(100 * array_sum(array_column($stages, 'w')) / count($stages)) : 0;
    return ['file' => $rel, 'missing' => false, 'stages' => $stages, 'pct' => $pct];
}

/** פריטי `- [ ]` / `- [x]` בקטע שכותרתו מתחילה ב-$title. */
function checklist(string $md, string $title): array
{
    // מתחת לפריט, בהזחה: "פרומפט:" ואחריו שורות הפרומפט, ו/או "מדריך:" ואחריו צעדים ממוספרים.
    $out = []; $cur = null; $block = null;
    foreach (section($md, $title) as $line) {
        if (preg_match('/^[-*] \[([ xX])\] (.+)$/u', $line, $m)) {
            $out[] = ['done' => $m[1] !== ' ', 'text' => trim($m[2]), 'prompt' => [], 'guide' => []];
            $cur = count($out) - 1; $block = null;
            continue;
        }
        if ($cur === null) continue;
        if (trim($line) === '') { if ($block === 'prompt') $out[$cur]['prompt'][] = ''; continue; }
        if (!preg_match('/^\s{2,}/u', $line)) { $cur = null; continue; }
        $t = trim($line);
        if ($t === 'פרומפט:') { $block = 'prompt'; continue; }
        if ($t === 'מדריך:') { $block = 'guide'; continue; }
        if ($block === 'prompt') $out[$cur]['prompt'][] = preg_replace('/^\s{4}/u', '', $line);
        elseif ($block === 'guide') $out[$cur]['guide'][] = preg_replace('/^\d+[.)]\s*/u', '', $t);
    }
    foreach ($out as &$i) $i['prompt'] = trim(implode("\n", $i['prompt']));
    return $out;
}

/** פרומפט ברירת מחדל למשימה של צ'אט או לשיפור, כשהמסמך לא כתב אחד. */
function auto_prompt(array $p, string $kind, array $g): string
{
    $file = 'creative-suite/docs/' . ($p['doc']['file'] ?? '');
    $task = plain($g['text']);
    if ($kind === 'idea') {
        return "חבילת היצירה · {$p['name']}: במסמך $file, בקטע \"שיפורים מומלצים\", מופיע:\n«{$task}»\n"
             . "עשה git fetch origin main וקרא את המסמך. אם השיפור עדיין נכון ובהיקף קטן — בצע לפי CLAUDE.md (ענף מ-main, PR, מיזוג, "
             . "conclusion: success) וסמן [x]. אם הוא גדול או דורש החלטה — כתוב תוכנית קצרה ושאל את בעל המערכת בכלי השאלות.";
    }
    return "חבילת היצירה · {$p['name']}: במסמך $file, בקטע \"התקדמות\", פתוחה המשימה:\n«{$task}»\n"
         . "עשה git fetch origin main וקרא את המסמך. בצע לפי CLAUDE.md (ענף מ-main, PR, מיזוג, conclusion: success) וסמן [x] כשנפרס. "
         . "צריך משהו מבעל המערכת — הוסף שורה בקטע \"ממתין לבעל המערכת\" ושאל בכלי השאלות.";
}

/** משימה אחת — מתקפלת; בפנים מדריך ו/או פרומפט להעתקה. $kind: chat | owner | idea */
function task_html(array $p, array $g, string $kind, bool $withProject = false): string
{
    $mark = ['chat' => '⬜', 'owner' => '👤', 'idea' => '💡'][$kind];
    $who = $withProject ? '<b>' . h($p['name']) . ':</b> ' : '';
    if ($g['done']) {
        return '<li class="done"><span class="mk" aria-label="הושלם">✅</span><span>' . $who . inline($g['text']) . '</span></li>';
    }
    $guide = $g['guide'];
    $prompt = $g['prompt'] !== '' ? $g['prompt'] : ($kind === 'owner' ? '' : auto_prompt($p, $kind, $g));
    if ($kind === 'owner' && !$guide) $guide = ['כשביצעת — כתוב לצ\'אט המתכלל: "בוצע: ' . plain($g['text']) . '", והוא יסמן.'];
    $body = '';
    if ($guide) {
        $body .= '<p class="label">📋 מה לעשות</p><ol class="steps">';
        foreach ($guide as $st) $body .= '<li>' . inline($st) . '</li>';
        $body .= '</ol>';
    }
    if ($prompt !== '') {
        $to = $kind === 'owner' ? 'להדבקה בצ\'אט' : 'להדבקה בצ\'אט של ' . h($p['name']);
        $body .= '<div class="prompt"><div class="prompt-head"><span class="label">💬 ' . $to . '</span>'
               . '<button type="button" class="btn copy" data-copy aria-label="העתקת הפרומפט">📋 העתקה</button></div>'
               . '<pre dir="auto" tabindex="0">' . h($prompt) . '</pre></div>';
    }
    return '<li class="task ' . $kind . '"><details><summary><span class="mk" aria-hidden="true">' . $mark . '</span><span class="tx">'
         . $who . inline($g['text']) . '</span></summary><div class="task-body">' . $body . '</div></details></li>';
}

/** בקשות ב-docs/requests/ שעוד אין עליהן הכרעה של הצ'אט המתכלל. */
function pending_requests(): array
{
    $out = [];
    foreach (glob(DOCS . '/requests/*.md') ?: [] as $f) {
        $md = (string)file_get_contents($f);
        if (str_contains($md, "הכרעת הצ'אט המתכלל") || str_contains($md, "**הצ'אט המתכלל (")) continue;
        preg_match('/^# (.+)$/mu', $md, $m);
        $rel = 'creative-suite/docs/requests/' . basename($f);
        $out[] = ['done' => false, 'text' => plain($m[1] ?? basename($f)) . ' — `' . basename($f) . '`',
            'guide' => ["פתח את הצ'אט \"חבילת היצירה · הצ'אט המתכלל\", לחץ \"📋 העתקה\" והדבק.",
                        'הוא יכריע, יעדכן את האפיון אם צריך, ויכתוב את התשובה בקובץ הבקשה — והבקשה תיעלם מכאן.'],
            'prompt' => "יש בקשה לצ'אט המתכלל ב-main: $rel. עשה git fetch origin main, קרא אותה והכרע — עדכן את האפיון והסכמה אם צריך, "
                      . "כתוב את ההכרעה בקובץ הבקשה, ותן לי הודעה מוכנה להעביר לצ'אט שביקש."];
    }
    return $out;
}

/** קבוצה מתקפלת עם כותרת ומונה; $id נזכר בין טעינות. */
function group_open(string $id, string $title, int $count, bool $open): string
{
    return '<details class="group" data-id="' . h($id) . '"' . ($open ? ' open' : '') . '><summary><h2>' . $title
         . ' <span class="count">' . $count . '</span></h2></summary>';
}

/** כמה פתוחים. */
function open_count(array $items): int { return count(array_filter($items, fn($i) => !$i['done'])); }

/** מסמך פרויקט: כותרת, פסקת סיכום, יעדים, ומסמך המעקב של האפליקציה (אם יש). */
function project_doc(string $rel): ?array
{
    // $rel כבר עבר את התבנית projects/[a-z0-9-]+.md — אין צורך ב-realpath (שמחזיר false בחלק מהשרתים)
    $file = DOCS . '/' . $rel;
    $md = @file_get_contents($file);
    if ($md === false) return ['file' => $rel, 'missing' => true, 'title' => '', 'summary' => '', 'goals' => [], 'updated' => 0];
    $lines = preg_split('/\R/u', $md);
    $title = ''; $summary = []; $started = false;
    foreach ($lines as $line) {
        if ($title === '' && str_starts_with($line, '# ')) { $title = trim(substr($line, 2)); continue; }
        if ($title === '') continue;
        if (trim($line) === '') { if ($started) break; continue; }
        if (str_starts_with($line, '#')) break;
        $started = true; $summary[] = trim($line);
    }
    $goals = checklist($md, 'התקדמות');
    $app = preg_match('/\*\*מעקב האפליקציה:\*\*\s*`([^`]+)`/u', $md, $m) ? roadmap($m[1]) : null;
    return ['file' => $rel, 'missing' => false, 'app' => $app, 'title' => $title, 'summary' => implode(' ', $summary), 'goals' => $goals,
            'owner' => checklist($md, 'ממתין לבעל המערכת'), 'ideas' => checklist($md, 'שיפורים מומלצים'),
            'updated' => filemtime($file)];
}

/**
 * מטריצת "קוד אחד לכל החבילה" — docs/integration.md: שורה לכל יכולת משותפת, עמודה לכל אפליקציה (שם התיקייה בכותרת).
 * המקום היחיד שבו מסומן מי כבר משתמשת במה; מכאן הפס "החבילה" של כל אפליקציה.
 */
function integration(): array
{
    $md = (string)@file_get_contents(DOCS . '/integration.md');
    $apps = []; $rows = [];
    foreach (section($md, 'המטריצה') as $line) {
        if (!str_starts_with(trim($line), '|') || preg_match('/^\|\s*-/u', $line)) continue;
        $c = array_map('trim', explode('|', trim(trim($line), '|')));
        if (!$apps && ($c[0] ?? '') === '#') {
            $apps = array_map(fn($x) => trim(plain($x), '` /'), array_slice($c, 3));
            continue;
        }
        if (!$apps || !preg_match('/^\d+$/', $c[0] ?? '')) continue;
        $cells = [];
        foreach ($apps as $i => $app) {
            preg_match('/✅|🟡|⬜|—/u', $c[3 + $i] ?? '', $m);
            $cells[$app] = $m[0] ?? '—';
        }
        $rows[] = ['num' => $c[0], 'name' => $c[1], 'src' => $c[2] ?? '', 'cells' => $cells];
    }
    return ['apps' => $apps, 'rows' => $rows];
}

/** השורות של אפליקציה אחת במטריצה (בלי "—") ואחוז: ✅ = 1, 🟡 = ½. */
function suite_of(array $mx, string $folder): ?array
{
    if (!in_array($folder, $mx['apps'], true)) return null;
    $rows = [];
    foreach ($mx['rows'] as $r) if (($r['cells'][$folder] ?? '—') !== '—') $rows[] = $r + ['mark' => $r['cells'][$folder]];
    if (!$rows) return null;
    $w = array_sum(array_map(fn($r) => ['✅' => 1, '🟡' => 0.5][$r['mark']] ?? 0, $rows));
    return ['rows' => $rows, 'pct' => (int)round(100 * $w / count($rows)),
            'done' => count(array_filter($rows, fn($r) => $r['mark'] === '✅'))];
}

/** הפרויקטים מטבלת תוכנית האב. */
function projects(): array
{
    $md = (string)@file_get_contents(DOCS . '/master-spec.md');
    $list = []; $mx = integration();
    foreach (section($md, '3.') as $line) {
        if (!preg_match('/^\|\s*(\d+)\s*\|/u', $line)) continue;
        $c = array_map('trim', explode('|', trim($line, " |")));
        if (count($c) < 7) continue;
        [$num, $name, $folder, $style, $kind, $deps, $status] = $c;
        $doc = preg_match('/\]\((projects\/[a-z0-9-]+\.md)\)/', $status, $m) ? project_doc($m[1]) : null;
        $goals = $doc['goals'] ?? [];
        $done = count(array_filter($goals, fn($g) => $g['done']));
        $kindKey = trim(plain($kind), '` ');
        $suite = suite_of($mx, preg_split('#[/\s]#u', trim(plain($folder), '` '))[0]);
        $app = $doc['app'] ?? null;
        // "האפליקציה": מסמך המעקב שלה אם יש, אחרת היעדים במסמך הפרויקט. "החבילה": המטריצה.
        $gpct = $goals ? (int)round(100 * $done / count($goals)) : 0;
        $apct = $app ? $app['pct'] : $gpct;
        $list[] = [
            'id'      => $doc ? basename($doc['file'], '.md') : 'p' . $num,
            'num'     => $num,
            'name'    => plain($name),
            'folder'  => plain($folder),
            'style'   => plain($style),
            'kind'    => $kindKey,
            'deps'    => plain($deps),
            'status'  => $status,
            'icon'    => KIND_ICON[$kindKey] ?? FOLDER_ICON[trim(preg_split('#\s#u', trim(plain($folder), '` '))[0], '/')] ?? ($num === '0' ? '⚙️' : '📦'),
            'doc'     => $doc,
            'goals'   => $goals,
            'owner'   => $doc['owner'] ?? [],
            'ideas'   => $doc['ideas'] ?? [],
            'done'    => $done,
            'gpct'    => $gpct,
            'app'     => $app,
            'apct'    => $apct,
            'suite'   => $suite,
            // אפליקציה בחבילה: ממוצע של האפליקציה עצמה ושל החיבור לקוד המשותף; מודול משותף — היעדים שלו
            'pct'     => $suite ? (int)round(($apct + $suite['pct']) / 2) : $apct,
        ];
    }
    return $list;
}

/** תגית מצב מתוך טקסט הסטטוס. */
function chip(string $status): string
{
    $t = plain($status);
    $cls = match (true) {
        str_contains($t, 'מוכנ')  => 'ok',
        str_contains($t, 'בקשה'), str_contains($t, 'הבא'), str_contains($t, 'בבנייה') => 'warn',
        default => '',
    };
    $label = status_label($status);
    return '<span class="chip ' . $cls . '">' . h(mb_strimwidth($label, 0, 22, '…')) . '</span>';
}

