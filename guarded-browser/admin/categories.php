<?php
/**
 * קטלוג הסיווגים — איזה דומיין שייך לאיזו קטגוריה.
 *
 * הקטלוג המובנה מכסה את מה שנתקלים בו בפועל, ולעולם לא יהיה שלם.
 * המסך הזה הוא ההשלמה: מנהל שראה ביומן אתר שנחסם או נפתח שלא כראוי
 * מסווג אותו כאן, וההגדרה חלה מיד על כל המשתמשים.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ui.php';

$admin = requireAdmin();
$msg = ''; $kind = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));
        // מקבלים גם כתובת מלאה ומחלצים ממנה את הדומיין: מי שמעתיק
        // כתובת מהיומן לא אמור לערוך אותה ביד.
        if ($p = normalizeUrl($domain)) $domain = $p['host'];
        $cat = (string) ($_POST['category'] ?? '');

        if ($domain === '' || !preg_match('/^[a-z0-9.\-]+\.[a-z]{2,}$/', $domain)) {
            $msg = 'הדומיין אינו תקין'; $kind = 'bad';
        } elseif (!isset(categoryCatalog()[$cat])) {
            $msg = 'קטגוריה לא מוכרת'; $kind = 'bad';
        } else {
            q('INSERT OR IGNORE INTO domain_categories (domain, category, source) VALUES (?,?,?)',
              [$domain, $cat, 'admin']);
            $msg = "$domain סווג כ" . categoryLabel($cat);
        }
    } elseif ($action === 'del') {
        q('DELETE FROM domain_categories WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        $msg = 'הסיווג הוסר';
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$where  = $search !== '' ? 'WHERE domain LIKE ?' : '';
$args   = $search !== '' ? ['%' . $search . '%'] : [];
$rows   = all("SELECT * FROM domain_categories $where ORDER BY domain, category LIMIT 300", $args);
$total  = (int) one('SELECT COUNT(*) n FROM domain_categories')['n'];
$csrf   = csrfToken();

layoutTop('קטלוג', $admin);
note($msg, $kind);
?>
<div class="card">
  <h2>סיווג דומיין חדש</h2>
  <p class="hint">אפשר להדביק כתובת מלאה — הדומיין יחולץ ממנה.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="add">
    <div class="grid">
      <label><span class="lbl">דומיין</span>
        <input type="text" name="domain" dir="ltr" required placeholder="example.co.il"></label>
      <label><span class="lbl">קטגוריה</span>
        <select name="category">
          <?php foreach (categoryCatalog() as $k => [$l, $i]): ?>
            <option value="<?= h($k) ?>"><?= $i ?> <?= h($l) ?></option>
          <?php endforeach; ?>
        </select></label>
    </div>
    <button class="btn btn--go btn--wide" type="submit">הוספה לקטלוג</button>
  </form>
</div>

<div class="card">
  <h2>הקטלוג (<?= $total ?> סיווגים)</h2>
  <p class="hint">דומיין יכול להשתייך ליותר מקטגוריה אחת. מוצגים עד 300.</p>
  <form method="get" style="margin-bottom:14px">
    <label style="margin:0"><span class="lbl">חיפוש דומיין</span>
      <input type="search" name="q" dir="ltr" value="<?= h($search) ?>" placeholder="youtube"></label>
  </form>
  <table>
    <thead><tr><th>דומיין</th><th>קטגוריה</th><th>מקור</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td data-l="דומיין"><code><?= h($r['domain']) ?></code></td>
        <td data-l="קטגוריה"><?= categoryIcon($r['category']) ?> <?= h(categoryLabel($r['category'])) ?></td>
        <td data-l="מקור"><span class="pill pill--mute">
          <?= $r['source'] === 'seed' ? 'מובנה' : 'שלי' ?></span></td>
        <td><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button class="btn btn--stop btn--sm" name="action" value="del">הסרה</button>
        </form></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td>לא נמצאו סיווגים</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php layoutEnd();
