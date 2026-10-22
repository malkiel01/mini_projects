<?php
/**
 * מלאי מרקט האפליקציות (lib/market.php) — מה ההורים יכולים לשחרר לילדים.
 *
 * שלושה מקורות כאן: מלאי הבסיס (מובנה, מאומת מול Google Play), הוספה מקישור Play (השם והאייקון נמשכים
 * מהדף), ו"מה ילדים מתקינים" — ספירה בלבד, ורק מה שנמצא אצל 2 ילדים לפחות. אפליקציות פרטיות של הורים — אצלם.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/market.php';

$admin = requireAdmin();
$msg = ''; $kind = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        [$id, $err] = marketAdd(0, (string) ($_POST['link'] ?? ''), (string) ($_POST['category'] ?? ''),
                                (int) ($_POST['min_age'] ?? 0), (string) ($_POST['note'] ?? ''), (string) ($_POST['name'] ?? ''));
        if ($err) { $msg = $err; $kind = 'bad'; }
        else $msg = 'נוסף למלאי: ' . (one('SELECT name, play_title FROM market_apps WHERE id = ?', [$id])['play_title'] ?? '');
    } elseif ($action === 'del') {
        $msg = marketRemove((int) ($_POST['id'] ?? 0), 0) ? 'הוסר מהמלאי (ומהילדים שקיבלו אותו)' : 'לא נמצא';
    }
}

$cat = (string) ($_GET['c'] ?? '');
$apps = array_values(array_filter(marketCatalog(0), fn($a) => !$a['private'] && ($cat === '' || $a['category'] === $cat)));
$popular = marketPopular();
$csrf = csrfToken();
$catSelect = function (string $sel = '') {
    $o = '';
    foreach (MARKET_CATEGORIES as $k => [$i, $l]) $o .= '<option value="' . h($k) . '"' . ($k === $sel ? ' selected' : '') . ">$i " . h($l) . '</option>';
    return '<select name="category">' . $o . '</select>';
};

layoutTop('מרקט', $admin);
note($msg, $kind);
?>
<div class="card">
  <h2>הוספה מקישור של Google Play</h2>
  <p class="hint">מדביקים את הקישור (play.google.com/store/apps/details?id=…) או את שם החבילה. השם והאייקון נמשכים מהחנות.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="add">
    <div class="grid">
      <label><span class="lbl">קישור או שם חבילה</span>
        <input type="text" name="link" dir="ltr" required placeholder="https://play.google.com/store/apps/details?id=com.duolingo"></label>
      <label><span class="lbl">שם בעברית (לא חובה)</span><input type="text" name="name" maxlength="80"></label>
      <label><span class="lbl">קטגוריה</span><?= $catSelect() ?></label>
      <label><span class="lbl">גיל מומלץ</span><input type="number" name="min_age" min="0" max="18" value="6"></label>
      <label><span class="lbl">הערה להורים (לא חובה)</span><input type="text" name="note" maxlength="120" placeholder="צ׳אט עם זרים"></label>
    </div>
    <button class="btn btn--go btn--wide" type="submit">הוספה למלאי</button>
  </form>
</div>

<div class="card">
  <h2>מה ילדים מתקינים</h2>
  <p class="hint">אפליקציות שדווחו מהמכשירים ואינן במלאי — רק מה שנמצא אצל <?= MARKET_POPULAR_MIN ?> ילדים לפחות, וספירה בלבד.</p>
  <table>
    <thead><tr><th>אפליקציה</th><th>ילדים</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($popular as $p): ?>
      <tr>
        <td data-l="אפליקציה"><?= h($p['label'] ?: $p['package']) ?> <small dir="ltr"><code><?= h($p['package']) ?></code></small></td>
        <td data-l="ילדים"><?= (int) $p['children'] ?></td>
        <td><form method="post" style="display:flex;gap:6px;flex-wrap:wrap">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="add">
          <input type="hidden" name="link" value="<?= h($p['package']) ?>"><input type="hidden" name="min_age" value="8">
          <?= $catSelect() ?>
          <button class="btn btn--go btn--sm" type="submit">הוספה</button></form></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$popular): ?><tr><td>אין כרגע — יופיע כשאותה אפליקציה תימצא אצל כמה ילדים.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2>המלאי (<?= count($apps) ?>)</h2>
  <p class="hint">
    <a href="market.php">הכול</a>
    <?php foreach (MARKET_CATEGORIES as $k => [$i, $l]): ?> · <a href="?c=<?= h($k) ?>"><?= $i ?> <?= h($l) ?></a><?php endforeach; ?>
  </p>
  <table>
    <thead><tr><th>אפליקציה</th><th>קטגוריה</th><th>גיל</th><th>מקור</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($apps as $a): ?>
      <tr>
        <td data-l="אפליקציה">
          <?php if ($a['icon']): ?><img src="<?= h(marketIcon($a['icon'], 48)) ?>" alt="" width="24" height="24" style="vertical-align:middle;border-radius:6px" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
          <?= h($a['name']) ?> <small dir="ltr"><code><?= h($a['package']) ?></code></small>
          <?= $a['note'] ? '<br><small>⚠️ ' . h($a['note']) . '</small>' : '' ?></td>
        <td data-l="קטגוריה"><?= MARKET_CATEGORIES[$a['category']][0] ?> <?= h(MARKET_CATEGORIES[$a['category']][1]) ?></td>
        <td data-l="גיל"><?= (int) $a['min_age'] ?>+</td>
        <td data-l="מקור"><span class="pill pill--mute"><?= $a['source'] === 'seed' ? 'מובנה' : 'שלי' ?></span></td>
        <td><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
          <button class="btn btn--stop btn--sm" name="action" value="del" onclick="return confirm('להסיר מהמלאי? ההורים שכבר שחררו אותה לילדים — השחרור יתבטל.')">הסרה</button>
        </form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layoutEnd();
