<?php
/**
 * ⏱ קצב ההערות — המסך של בעל המערכת (creative-suite/docs/finish-line.md, "הקצב").
 * ברירת מחדל כללית, דקות לכל צ'אט (ריק = ברירת המחדל), והשהיה לכל צ'אט ולכולם. רק מנהל הפלטפורמה רואה ושומר.
 */
declare(strict_types=1);
require __DIR__ . '/lib/cadence.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$me = json_decode((string)($_SERVER['PLATFORM_USER'] ?? ''), true);
if (!is_array($me) || empty($me['platform_admin'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("המסך הזה לבעל המערכת בלבד.\n");
}

$roster = cadence_roster();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // רק טופס מהדף הזה: Origin (או Sec-Fetch-Site) מאותו אתר.
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $site = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    $sameOrigin = $origin !== '' ? parse_url($origin, PHP_URL_HOST) === parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)
                                 : $site === 'same-origin';
    if (!$sameOrigin) {
        $error = 'השמירה נדחתה: הבקשה לא הגיעה מהדף הזה.';
    } else {
        $c = cadence_load();
        $c['default'] = cadence_clamp($_POST['default'] ?? null) ?? CADENCE_DEFAULT;
        $c['paused'] = !empty($_POST['paused']);
        $chats = [];
        foreach ($roster as $role => $_) {
            $min = cadence_clamp($_POST['m'][$role] ?? null);
            $p = !empty($_POST['p'][$role]);
            if ($min !== null || $p) $chats[$role] = ['minutes' => $min, 'paused' => $p];
        }
        $c['chats'] = $chats;
        try {
            cadence_save($c, (string)($me['name'] ?? ''));
            header('Location: cadence-edit.php?saved=1', true, 303);
            exit;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

$c = cadence_load();
?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>קצב — חבילת היצירה</title>
<link rel="icon" href="../assets/icon.png" type="image/png">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<a class="skip" href="#main">דילוג לתוכן</a>
<header class="top">
  <a class="icon-btn" href="./" aria-label="חזרה לדשבורד">→</a>
  <div class="titles"><h1 class="one">⏱ קצב ההערות</h1><div class="sub one">כל כמה דקות כל צ'אט מתעורר</div></div>
</header>
<main id="main" tabindex="-1">
  <p class="hint">כל צ'אט קורא את הקצב שלו בסוף כל הערה, וקובע את הבאה בהתאם. שינוי כאן חל מההערה הבאה של כל צ'אט. ⏸ — הצ'אט לא קובע הערה; המשימה השעתית עדיין מעירה אותו ובודקת שוב.</p>
  <?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>
  <?php if (isset($_GET['saved'])): ?><p class="saved" role="status">✓ נשמר</p><?php endif; ?>
  <form method="post" class="cadence">
    <div class="summary">
      <div class="fields two">
        <label class="f"><span>ברירת מחדל (דקות)</span>
          <input type="number" name="default" min="<?= CADENCE_MIN ?>" max="<?= CADENCE_MAX ?>" inputmode="numeric" required value="<?= $c['default'] ?>"></label>
        <label class="switch pause-all"><input type="checkbox" name="paused" value="1"<?= $c['paused'] ? ' checked' : '' ?>><span>⏸ השהיה לכולם</span></label>
      </div>
    </div>
    <ul class="list goals chats">
      <?php foreach ($roster as $role => $name): $mine = $c['chats'][$role] ?? []; $eff = cadence_for($c, $role); ?>
      <li>
        <span class="grow"><b class="one"><?= h($name) ?></b><small class="muted one"><?= $eff['paused'] ? '⏸ מושהה' : 'כל ' . $eff['minutes'] . " דק'" ?> · <bdi dir="ltr"><?= h($role) ?></bdi></small></span>
        <label class="mins"><span class="sr-only">דקות ל<?= h($name) ?> (ריק = ברירת המחדל)</span>
          <input type="number" name="m[<?= h($role) ?>]" min="<?= CADENCE_MIN ?>" max="<?= CADENCE_MAX ?>" inputmode="numeric" placeholder="<?= $c['default'] ?>" value="<?= h((string)(cadence_clamp($mine['minutes'] ?? null) ?? '')) ?>"></label>
        <label class="pz" title="השהיה"><input type="checkbox" name="p[<?= h($role) ?>]" value="1"<?= !empty($mine['paused']) ? ' checked' : '' ?>><span aria-hidden="true">⏸</span><span class="sr-only">השהיה ל<?= h($name) ?></span></label>
      </li>
      <?php endforeach; ?>
    </ul>
    <p class="hint">שדה ריק — לפי ברירת המחדל. מינימום <?= CADENCE_MIN ?> דקה.<?php if ($c['updated_at']): ?> עודכן <?= date('d.m.Y H:i', $c['updated_at']) ?><?= $c['updated_by'] !== '' ? ' · ' . h($c['updated_by']) : '' ?>.<?php endif; ?></p>
    <div class="save-bar"><button class="btn primary">שמירה</button></div>
  </form>
</main>
</body>
</html>
