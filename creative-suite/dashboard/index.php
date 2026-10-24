<?php
/**
 * דשבורד חבילת היצירה — סיכום האפיונים וההתקדמות, נקרא מהמסמכים בכל טעינה.
 *
 * המקור: הטבלה "## 3. הפרויקטים" ב-docs/master-spec.md, ולכל פרויקט — המסמך שלו ב-docs/projects/
 * (הקישור הראשון לשם בעמודת הסטטוס). במסמך: הפסקה הראשונה = הסיכום, "## התקדמות" = יעדים
 * `- [x]` / `- [ ]`. אין כאן נתונים משלו — מה שבמסמכים הוא מה שמוצג.
 * הגישה: כל חבילת היצירה "restricted" ב-app.json — רק מי שאושר ישירות בממשק המנהל.
 */
declare(strict_types=1);

const DOCS = __DIR__ . '/../docs';
const ROOT = __DIR__ . '/../..';
/** מצב שלב במסמך מעקב של אפליקציה → משקל. 👤 (ממתין לאימות) לא משנה את המשקל. */
const STAGE_WEIGHT = ['✅' => 1.0, '🟡' => 0.5, '⏳' => 0.5, '⬜' => 0.0];
const KIND_ICON = ['text' => '📝', 'raster' => '🖌️', 'vector' => '✒️', 'layout' => '📰'];

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
    $goals = [];
    foreach (section($md, 'התקדמות') as $line) {
        if (preg_match('/^\s*[-*] \[([ xX])\] (.+)$/u', $line, $m)) {
            $goals[] = ['done' => $m[1] !== ' ', 'text' => trim($m[2])];
        }
    }
    $app = preg_match('/\*\*מעקב האפליקציה:\*\*\s*`([^`]+)`/u', $md, $m) ? roadmap($m[1]) : null;
    return ['file' => $rel, 'missing' => false, 'app' => $app, 'title' => $title, 'summary' => implode(' ', $summary), 'goals' => $goals,
            'updated' => filemtime($file)];
}

/** הפרויקטים מטבלת תוכנית האב. */
function projects(): array
{
    $md = (string)@file_get_contents(DOCS . '/master-spec.md');
    $list = [];
    foreach (section($md, '3.') as $line) {
        if (!preg_match('/^\|\s*(\d+)\s*\|/u', $line)) continue;
        $c = array_map('trim', explode('|', trim($line, " |")));
        if (count($c) < 7) continue;
        [$num, $name, $folder, $style, $kind, $deps, $status] = $c;
        $doc = preg_match('/\]\((projects\/[a-z0-9-]+\.md)\)/', $status, $m) ? project_doc($m[1]) : null;
        $goals = $doc['goals'] ?? [];
        $done = count(array_filter($goals, fn($g) => $g['done']));
        $kindKey = trim(plain($kind), '` ');
        $list[] = [
            'id'      => $doc ? basename($doc['file'], '.md') : 'p' . $num,
            'num'     => $num,
            'name'    => plain($name),
            'folder'  => plain($folder),
            'style'   => plain($style),
            'kind'    => $kindKey,
            'deps'    => plain($deps),
            'status'  => $status,
            'icon'    => KIND_ICON[$kindKey] ?? ($num === '0' ? '⚙️' : '📦'),
            'doc'     => $doc,
            'goals'   => $goals,
            'done'    => $done,
            'gpct'    => $gpct = $goals ? (int)round(100 * $done / count($goals)) : 0,
            'app'     => $app = $doc['app'] ?? null,
            // יש מסמך מעקב: ממוצע של האפליקציה עצמה ושל החיבור לחבילה; אחרת — היעדים בלבד
            'pct'     => $app ? (int)round(($app['pct'] + $gpct) / 2) : $gpct,
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

$all  = projects();
$sel  = null;
foreach ($all as $p) if (($p['id'] === ($_GET['p'] ?? ''))) $sel = $p;
$goalsAll = array_sum(array_map(fn($p) => count($p['goals']), $all));
$doneAll  = array_sum(array_column($all, 'done'));
$avg      = $all ? (int)round(array_sum(array_column($all, 'pct')) / count($all)) : 0;
$ready    = count(array_filter($all, fn($p) => $p['pct'] === 100));
$started  = count(array_filter($all, fn($p) => $p['pct'] > 0 && $p['pct'] < 100));
?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>דשבורד — חבילת היצירה</title>
<link rel="icon" href="../assets/icon.png" type="image/png">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<a class="skip" href="#main">דילוג לתוכן</a>
<header class="top">
<?php if ($sel): ?>
  <a class="icon-btn" href="./" aria-label="חזרה לדשבורד">→</a>
  <div class="titles"><h1 class="one"><?= $sel['icon'] ?> <?= h($sel['name']) ?></h1>
    <div class="sub one"><?= h($sel['style'] !== '—' ? 'בסגנון ' . $sel['style'] : 'משותף לכל החבילה') ?><?= $sel['folder'] !== '—' ? ' · <bdi>' . h($sel['folder']) . '</bdi>' : '' ?></div></div>
<?php else: ?>
  <a class="icon-btn" href="../" aria-label="תוכנית האב">🧰</a>
  <div class="titles"><h1 class="one">דשבורד חבילת היצירה</h1><div class="sub one">מהאפיונים · <?= date('d.m.Y H:i') ?></div></div>
<?php endif; ?>
</header>
<main id="main" tabindex="-1">
<?php if ($sel): $p = $sel; ?>
  <div class="summary">
    <?php if ($p['app']): ?>
    <div class="line"><span class="muted">האפליקציה</span> <b><?= $p['app']['pct'] ?>%</b> <span class="muted">· <bdi dir="ltr">.mbe</bdi></span> <b><?= $p['gpct'] ?>%</b><span class="pct"><?= $p['pct'] ?>%</span></div>
    <?php else: ?>
    <div class="line"><span class="muted">הושלמו</span> <b><?= $p['done'] ?></b> <span class="muted">מתוך <?= count($p['goals']) ?> יעדים</span><span class="pct"><?= $p['pct'] ?>%</span></div>
    <?php endif; ?>
    <span class="track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $p['pct'] ?>"><span style="inline-size:<?= $p['pct'] ?>%"></span></span>
    <div class="chips"><?= chip($p['status']) ?><?php if ($p['kind'] && $p['kind'] !== '—'): ?><span class="chip"><?= h($p['kind']) ?></span><?php endif; ?></div>
  </div>
  <h2 class="sec-title">האפיון</h2>
  <div class="list prose">
    <?php if ($p['doc']): ?>
      <p><?= inline($p['doc']['summary']) ?></p>
      <p class="muted">תלוי ב: <?= h($p['deps']) ?> · מסמך: <code><?= h($p['doc']['file']) ?></code> · עודכן <?= date('d.m.Y', $p['doc']['updated']) ?></p>
    <?php else: ?>
      <p>עוד אין מסמך הנחיות. <?= h($p['style'] !== '—' ? 'אפליקציה בסגנון ' . $p['style'] . '.' : '') ?> תלוי ב: <?= h($p['deps']) ?>.</p>
      <p class="muted">המסמך ייכתב בצ'אט המתכלל לפני שייפתח לפרויקט צ'אט.</p>
    <?php endif; ?>
  </div>
  <?php if ($p['app']): $a = $p['app']; ?>
  <h2 class="sec-title">האפליקציה עצמה — <?= $a['pct'] ?>%</h2>
  <?php if ($a['missing']): ?>
    <p class="empty">⚠ מסמך המעקב לא נמצא בשרת: <code><?= h($a['file']) ?></code></p>
  <?php else: ?>
    <ul class="list goals">
      <?php foreach ($a['stages'] as $st): ?>
        <li class="<?= $st['mark'] === '✅' ? 'done' : '' ?>"><span class="mk" aria-hidden="true"><?= $st['mark'] ?></span><span><b><?= h($st['num'] . ' · ' . $st['name']) ?></b><?php if ($st['short'] !== ''): ?><br><small class="muted"><?= inline($st['short']) ?></small><?php endif; ?></span></li>
      <?php endforeach; ?>
    </ul>
    <p class="hint">מתוך <code><?= h($a['file']) ?></code> (של הצ'אט של האפליקציה — לקריאה בלבד). ✅ = שלב מלא, 🟡 / ⏳ = חצי, ⬜ = אפס.</p>
  <?php endif; ?>
  <h2 class="sec-title">חיבור לחבילה (<bdi dir="ltr">.mbe</bdi>) — <?= $p['gpct'] ?>%</h2>
  <?php else: ?>
  <h2 class="sec-title">יעדים</h2>
  <?php endif; ?>
  <?php if ($p['goals']): ?>
    <ul class="list goals">
      <?php foreach ($p['goals'] as $g): ?>
        <li class="<?= $g['done'] ? 'done' : '' ?>"><span class="mk" aria-label="<?= $g['done'] ? 'הושלם' : 'פתוח' ?>"><?= $g['done'] ? '✅' : '⬜' ?></span><span><?= inline($g['text']) ?></span></li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="empty">אין עדיין יעדים — ייקבעו במסמך הפרויקט, בקטע "התקדמות".</p>
  <?php endif; ?>
<?php else: ?>
  <p class="hint">כל אפליקציה בחבילה, האפיון שלה ומה הושלם מתוך היעדים שבמסמך שלה. מתעדכן לבד בכל פריסה של המסמכים.</p>
  <div class="summary">
    <div class="line"><span class="muted">התקדמות החבילה</span> <b><?= $doneAll ?></b> <span class="muted">מתוך <?= $goalsAll ?> יעדים</span><span class="pct"><?= $avg ?>%</span></div>
    <span class="track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $avg ?>"><span style="inline-size:<?= $avg ?>%"></span></span>
    <div class="chips"><span class="chip"><?= count($all) ?> פרויקטים</span><span class="chip ok"><?= $ready ?> הושלמו</span><span class="chip warn"><?= $started ?> בתהליך</span><span class="chip"><?= count($all) - $ready - $started ?> טרם התחילו</span></div>
  </div>
  <ul class="list">
    <?php foreach ($all as $p): ?>
      <li><a class="use-row" href="?p=<?= h(rawurlencode($p['id'])) ?>">
        <span class="use-ic" aria-hidden="true"><?= $p['icon'] ?></span>
        <span class="use-tx"><b class="one"><?= h($p['name']) ?></b>
          <small class="one"><?= !empty($p['doc']['missing']) ? '⚠ המסמך לא נמצא בשרת · ' : '' ?><?= h($p['style'] !== '—' ? 'בסגנון ' . $p['style'] : 'משותף') ?> · <?= h(status_label($p['status'])) ?></small>
          <?php if ($p['app']): ?>
          <span class="bar"><i>האפליקציה</i><span class="track" role="progressbar" aria-label="האפליקציה עצמה" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $p['app']['pct'] ?>"><span style="inline-size:<?= $p['app']['pct'] ?>%"></span></span><b><?= $p['app']['pct'] ?>%</b></span>
          <span class="bar"><i><bdi dir="ltr">.mbe</bdi></i><span class="track" role="progressbar" aria-label="חיבור לחבילה" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $p['gpct'] ?>"><span style="inline-size:<?= $p['gpct'] ?>%"></span></span><b><?= $p['gpct'] ?>%</b></span>
          <?php else: ?>
          <span class="bar"><span class="track" role="progressbar" aria-label="התקדמות" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $p['pct'] ?>"><span style="inline-size:<?= $p['pct'] ?>%"></span></span><b><?= $p['pct'] ?>%</b></span>
          <?php endif; ?>
        </span>
        <span class="use-go" aria-hidden="true">‹</span>
      </a></li>
    <?php endforeach; ?>
  </ul>
  <p class="hint">האחוז הכולל — ממוצע הפרויקטים (כל פרויקט באותו משקל). אפליקציה עם מסמך מעקב משלה — ממוצע של האפליקציה עצמה ושל החיבור לחבילה (‎.mbe). פרויקט בלי יעדים — 0%.</p>
<?php endif; ?>
</main>
</body>
</html>
