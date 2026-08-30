<?php
/**
 * התרעות אבטחה.
 *
 * המסך שאליו מגיעים כשמשהו נשבר: מה קרה, למי, ומה כבר נעשה בנידון.
 * הנעילה כבר בוצעה אוטומטית — כאן מחליטים אם לפתוח מחדש.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ui.php';

$admin = requireAdmin();
$msg = ''; $kind = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'ack') {
        q('UPDATE alerts SET acked_at = ? WHERE id = ?', [nowIso(), (int) ($_POST['id'] ?? 0)]);
        $msg = 'ההתרעה סומנה כטופלה';
    } elseif ($action === 'ack_all') {
        q("UPDATE alerts SET acked_at = ? WHERE acked_at = ''", [nowIso()]);
        $msg = 'כל ההתרעות סומנו כטופלו';
    } elseif ($action === 'reopen') {
        // פתיחה מחדש היא החלטה של אדם, ולעולם לא אוטומטית.
        $uid = (int) ($_POST['user_id'] ?? 0);
        upsert('platform_rules', ['user_id' => $uid, 'platform' => PLATFORM_YOUTUBE],
               ['mode' => 'restricted'], ['created_at' => nowIso()]);
        audit($uid, 'platform_reopened', true, '', 'by_admin', $admin['username']);
        $msg = 'הגישה ליוטיוב נפתחה מחדש במצב מוגבל';
    }
}

$open   = openAlerts();
$recent = all("SELECT a.*, u.username FROM alerts a LEFT JOIN users u ON u.id = a.user_id
               WHERE a.acked_at != '' ORDER BY a.id DESC LIMIT 30");
$csrf   = csrfToken();

layoutTop('התרעות', $admin);
note($msg, $kind);
?>
<div class="card">
  <h2>מה נחשב להתרעה</h2>
  <p class="hint" style="margin:0">
    האכיפה קיימת גם באפליקציה וגם בשרת. כל עוד שניהם מסכימים אין חדש —
    <strong>העניין הוא ברגע שהם חולקים.</strong> אפליקציה שהתירה כתובת שהשרת אוסר
    היא אפליקציה שהאכיפה בה נעקפה, ואז הגישה לפלטפורמה ננעלת מיד, בלי להמתין לך.
    <br><br>
    ניסיונות חוזרים להגיע לתוכן חסום מתריעים אבל <em>אינם</em> נועלים: דפוס אינו ראיה,
    ומשתמש תמים שנתקע אינו אמור למצוא את עצמו חסום.
  </p>
</div>

<div class="card">
  <h2>פתוחות (<?= count($open) ?>)</h2>
  <?php if (!$open): ?>
    <p class="hint" style="margin:0">אין התרעות פתוחות.</p>
  <?php else: ?>
    <form method="post" style="margin-bottom:14px">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <button class="btn btn--sm" name="action" value="ack_all">סימון הכול כטופל</button>
    </form>
    <table>
      <thead><tr><th>מתי</th><th>משתמש</th><th>מה קרה</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($open as $a): ?>
        <tr>
          <td data-l="מתי"><?= h(str_replace(['T','Z'], [' ',''], substr($a['at'], 0, 16))) ?></td>
          <td data-l="משתמש"><?= $a['username']
                ? '<a href="user.php?id=' . (int) $a['user_id'] . '"><code>'
                  . h($a['username']) . '</code></a>' : '—' ?></td>
          <td data-l="מה קרה">
            <?= $a['severity'] === 'high'
                  ? '<span class="pill pill--stop">חמור</span> '
                  : '<span class="pill pill--wait">שים לב</span> ' ?>
            <strong><?= h($a['title']) ?></strong>
            <?php if ($a['detail']): ?><br><small><?= h($a['detail']) ?></small><?php endif; ?>
            <?php if ($a['url']): ?><br><code><?= h(mb_substr($a['url'], 0, 70)) ?></code><?php endif; ?>
          </td>
          <td><div class="acts">
            <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
              <button class="btn btn--sm" name="action" value="ack">טופל</button></form>
            <?php if ($a['kind'] === 'enforcement_gap'): ?>
            <form method="post" onsubmit="return confirm('לפתוח מחדש את הגישה ליוטיוב?')">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="user_id" value="<?= (int) $a['user_id'] ?>">
              <button class="btn btn--sm" name="action" value="reopen">פתיחה מחדש</button></form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>טופלו</h2>
  <table>
    <thead><tr><th>מתי</th><th>משתמש</th><th>מה</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $a): ?>
      <tr>
        <td data-l="מתי"><?= h(str_replace(['T','Z'], [' ',''], substr($a['at'], 0, 16))) ?></td>
        <td data-l="משתמש"><code><?= h($a['username'] ?? '—') ?></code></td>
        <td data-l="מה"><?= h($a['title']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td>אין</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php layoutEnd();
