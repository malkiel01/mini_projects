<?php
/**
 * ייבוא חד-פעמי של המטלות מהמסמכים ללוח "מטלות לקלוד" (האפיון, שלב 4; הכרעה 5 — הלוח הוא מקור האמת).
 *
 * הצד הזה רק קורא את docs/projects ומציג מה ייווצר. היצירה עצמה — בדפדפן של בעל המערכת, על החשבון שלו בלוח
 * (assets/import.js). אפשר להריץ שוב: מה שכבר קיים (אותו פרויקט, נושא וכותרת) — מדולג.
 */
declare(strict_types=1);
require __DIR__ . '/lib/board.php';

$plan = import_plan();
$n = ['topics' => 0, 'open' => 0, 'done' => 0, 'blocked' => 0];
foreach ($plan as $p) foreach ($p['topics'] as $t) {
    $n['topics']++;
    foreach ($t['tasks'] as $k) $n[$k['status']]++;
}
$total = $n['open'] + $n['done'] + $n['blocked'];
$boot = ['api' => '../../claude-tasks/api.php', 'board' => '../../claude-tasks/', 'repo' => SUITE_REPO, 'plan' => $plan];
?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>ייבוא ללוח — חבילת היצירה</title>
<link rel="icon" href="../assets/icon.png" type="image/png">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<a class="skip" href="#main">דילוג לתוכן</a>
<header class="top">
  <a class="icon-btn" href="center.php" aria-label="חזרה למרכז">→</a>
  <div class="titles"><h1 class="one">ייבוא המטלות ללוח</h1><div class="sub one">מ-docs/projects · פעם אחת</div></div>
</header>
<main id="main" tabindex="-1">
  <p class="hint">כל שורת <code>- [ ]</code> / <code>- [x]</code> במסמכי הפרויקטים הופכת למטלה בלוח "מטלות לקלוד": פרויקט לכל אפליקציה, נושא לכל שלב.
    "ממתין לבעל המערכת" — מטלה עם ❓, וההדרכה שלה כהערה הראשונה. "שיפורים מומלצים" נשארים במסמך.</p>
  <div class="summary">
    <div class="line"><b><?= $total ?></b> <span class="muted">מטלות ·</span> <b><?= count($plan) ?></b> <span class="muted">פרויקטים ·</span> <b><?= $n['topics'] ?></b> <span class="muted">נושאים</span></div>
    <div class="chips"><span class="chip">⏳ <?= $n['open'] ?> ממתינות</span><span class="chip ok">✅ <?= $n['done'] ?> הושלמו</span><span class="chip warn">❓ <?= $n['blocked'] ?> ממתינות לך</span></div>
  </div>

  <div id="run" class="summary" hidden>
    <div class="line"><span id="run-text">מתחיל…</span><span class="pct" id="run-pct">0%</span></div>
    <span class="track" role="progressbar" aria-label="התקדמות הייבוא" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="run-bar"><span style="inline-size:0%"></span></span>
    <ul class="list goals" id="run-log" hidden></ul>
  </div>

  <div class="row-btns"><button type="button" class="btn primary" id="go">ייבוא ללוח</button></div>
  <p class="pending-note">⚠ אחרי הייבוא, המטלות ⏳ בתור של הלוח. עובד עם אסימון כללי שמושך <code>claim</code> בלי <code>topic_id</code> (למשל Routine לילי) — יראה גם אותן.
    עד שהלוח יוסיף <code>route</code> ותגית <code>suite</code>, כדאי שעובד כזה ימשוך לפי נושא.</p>
  <p class="hint">רץ על החשבון שלך בלוח (צריך להיות מחובר אליו באותו דפדפן). בטוח להריץ שוב — מה שכבר קיים מדולג.</p>

  <h2 class="sec-title">מה ייווצר</h2>
  <ul class="list">
  <?php foreach ($plan as $p): $cnt = array_sum(array_map(fn($t) => count($t['tasks']), $p['topics'])); ?>
    <li><details class="proj">
      <summary class="use-row"><span class="use-ic" aria-hidden="true"><?= $p['icon'] ?></span><span class="use-tx">
        <b class="one"><?= h($p['name']) ?></b><small class="one"><?= count($p['topics']) ?> נושאים · <?= $cnt ?> מטלות · <bdi><?= h(basename($p['doc'])) ?></bdi></small></span>
        <span class="use-go" aria-hidden="true">‹</span></summary>
      <div class="proj-body">
      <?php foreach ($p['topics'] as $t): ?>
        <details class="group sub"><summary><h3><?= h(str_replace($p['name'] . ' · ', '', $t['name'])) ?> <span class="count"><?= count($t['tasks']) ?></span></h3></summary>
          <ul class="list goals"><?php foreach ($t['tasks'] as $k): ?>
            <li class="<?= $k['status'] === 'done' ? 'done' : '' ?>"><span class="mk" aria-hidden="true"><?= ['open' => '⏳', 'done' => '✅', 'blocked' => '❓'][$k['status']] ?></span><span><?= h($k['title']) ?></span></li>
          <?php endforeach; ?></ul>
        </details>
      <?php endforeach; ?>
      </div>
    </details></li>
  <?php endforeach; ?>
  </ul>
</main>
<script id="boot" type="application/json"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="assets/import.js" defer></script>
</body>
</html>
