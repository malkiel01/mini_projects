<?php
/**
 * יומן כלל-מערכתי, עם סינון לפי משתמש ולפי תוצאה.
 *
 * הסינון ל"נחסם בלבד" הוא העיקר: רשימה של מה שנחסם היא רשימת
 * הכללים שחסרים, או של משתמש שמנסה מה שלא הותר לו.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ui.php';

$admin = requireAdmin();

$who  = (int) ($_GET['user'] ?? 0);
$only = (string) ($_GET['only'] ?? '');

$where = []; $args = [];
if ($who > 0)            { $where[] = 'a.user_id = ?'; $args[] = $who; }
if ($only === 'blocked') { $where[] = 'a.allowed = 0'; }
$sql = 'SELECT a.*, u.username FROM audit a LEFT JOIN users u ON u.id = a.user_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY a.id DESC LIMIT 200';

$rows  = all($sql, $args);
$users = all('SELECT id, username FROM users ORDER BY username');

layoutTop('יומן', $admin);
?>
<div class="card">
  <h2>יומן פעילות</h2>
  <p class="hint">‏200 הרשומות האחרונות.</p>
  <form method="get" class="grid" style="align-items:end">
    <label><span class="lbl">משתמש</span>
      <select name="user">
        <option value="0">כולם</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int) $u['id'] ?>" <?= $who === (int) $u['id'] ? 'selected' : '' ?>>
            <?= h($u['username']) ?></option>
        <?php endforeach; ?>
      </select></label>
    <label><span class="lbl">תוצאה</span>
      <select name="only">
        <option value="">הכול</option>
        <option value="blocked" <?= $only === 'blocked' ? 'selected' : '' ?>>נחסם בלבד</option>
      </select></label>
    <label><span class="lbl">&nbsp;</span><button class="btn btn--go" type="submit">סינון</button></label>
  </form>
</div>

<div class="card">
  <table>
    <thead><tr><th>מתי</th><th>משתמש</th><th>מה</th><th>כתובת</th><th>תוצאה</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $a): ?>
    <tr>
      <td data-l="מתי"><?= h(str_replace(['T', 'Z'], [' ', ''], substr($a['at'], 0, 16))) ?></td>
      <td data-l="משתמש"><?= $a['username']
            ? '<a href="user.php?id=' . (int) $a['user_id'] . '"><code>' . h($a['username']) . '</code></a>'
            : '—' ?></td>
      <td data-l="מה"><?= h($a['kind']) ?></td>
      <td data-l="כתובת"><code><?= h(mb_substr($a['url'], 0, 80)) ?></code></td>
      <td data-l="תוצאה"><?= $a['allowed'] ? '<span class="pill pill--ok">הותר</span>'
                            : '<span class="pill pill--stop">' . h($a['code']) . '</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td>אין רשומות</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php layoutEnd();
