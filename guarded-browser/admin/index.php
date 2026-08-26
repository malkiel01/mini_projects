<?php
/**
 * רשימת המשתמשים, וטיפול בממתינים לאישור.
 *
 * הממתינים מוצגים למעלה ובנפרד: הרשמה עצמית פירושה שיש תור, ותור
 * שמעורבב ברשימה הכללית הוא תור שנשכח.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ui.php';

$admin = requireAdmin();
$msg = ''; $kind = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $uid    = (int) ($_POST['user_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $target = $uid > 0 ? one('SELECT * FROM users WHERE id = ?', [$uid]) : null;

    if (!$target) {
        $msg = 'המשתמש לא נמצא'; $kind = 'bad';
    } elseif ($uid === (int) $admin['id'] && $action !== 'approve') {
        // בלי זה מנהל יכול להשעות או למחוק את עצמו ולנעול את הפאנל.
        $msg = 'אי אפשר לשנות את החשבון שאיתו נכנסת'; $kind = 'bad';
    } else {
        switch ($action) {
            case 'approve':
                q('UPDATE users SET status = ?, approved_at = ? WHERE id = ?',
                  ['active', nowIso(), $uid]);
                q('INSERT OR IGNORE INTO policies (user_id, updated_at) VALUES (?, ?)',
                  [$uid, nowIso()]);
                audit($uid, 'approved', true, '', 'by_admin', $admin['username']);
                $msg = "החשבון {$target['username']} אושר";
                break;

            case 'suspend':
                q('UPDATE users SET status = ? WHERE id = ?', ['suspended', $uid]);
                // ניתוק מיידי: מחיקת האסימונים מפילה את המכשירים בפעימה הבאה.
                q('DELETE FROM devices WHERE user_id = ?', [$uid]);
                audit($uid, 'suspended', false, '', 'by_admin', $admin['username']);
                $msg = "החשבון {$target['username']} הושעה והמכשירים נותקו";
                break;

            case 'activate':
                q('UPDATE users SET status = ? WHERE id = ?', ['active', $uid]);
                $msg = "החשבון {$target['username']} הופעל";
                break;

            case 'delete':
                q('DELETE FROM users WHERE id = ?', [$uid]);
                $msg = "החשבון {$target['username']} נמחק";
                break;

            default:
                $msg = 'פעולה לא מוכרת'; $kind = 'bad';
        }
    }
}

$pending = all("SELECT * FROM users WHERE status = 'pending' ORDER BY created_at");
$others  = all("SELECT u.*, p.mode,
                 (SELECT COUNT(*) FROM devices d WHERE d.user_id = u.id) AS devices,
                 (SELECT COUNT(*) FROM rules r WHERE r.user_id = u.id AND r.enabled = 1) AS rules
                FROM users u LEFT JOIN policies p ON p.user_id = u.id
                WHERE u.status != 'pending' ORDER BY u.username");
$csrf = csrfToken();

layoutTop('משתמשים', $admin);
note($msg, $kind);
?>

<?php if ($pending): ?>
<div class="card">
  <h2>ממתינים לאישור (<?= count($pending) ?>)</h2>
  <p class="hint">עד האישור אין להם גישה לשום דבר.</p>
  <table>
    <tr><th>שם משתמש</th><th>שם מלא</th><th>דוא״ל</th><th>נרשם</th><th></th></tr>
    <?php foreach ($pending as $u): ?>
    <tr>
      <td><code><?= h($u['username']) ?></code></td>
      <td><?= h($u['display_name']) ?: '—' ?></td>
      <td><?= h($u['email']) ?: '—' ?></td>
      <td><?= h(substr($u['created_at'], 0, 10)) ?></td>
      <td class="row-actions">
        <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
          <button class="btn btn--go btn--sm" name="action" value="approve">אישור</button>
        </form>
        <form method="post" onsubmit="return confirm('למחוק את הבקשה?')">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
          <button class="btn btn--stop btn--sm" name="action" value="delete">מחיקה</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <h2>משתמשים (<?= count($others) ?>)</h2>
  <p class="hint">לחיצה על שם פותחת את ההרשאות, הכללים והמכשירים שלו.</p>
  <table>
    <tr><th>שם</th><th>מצב</th><th>מצב גלישה</th><th>כללים</th><th>מכשירים</th><th>תוקף</th><th></th></tr>
    <?php foreach ($others as $u): ?>
    <tr>
      <td><a href="user.php?id=<?= (int) $u['id'] ?>"><code><?= h($u['username']) ?></code></a>
          <?= $u['is_admin'] ? ' <span class="pill">מנהל</span>' : '' ?></td>
      <td><?= statusPill($u['status']) ?></td>
      <td><?= h(explode('—', MODE_LABELS[$u['mode'] ?? MODE_KIOSK] ?? '')[0]) ?></td>
      <td><?= (int) $u['rules'] ?></td>
      <td><?= (int) $u['devices'] ?></td>
      <td><?= h($u['expires_at'] ? substr($u['expires_at'], 0, 10) : '—') ?></td>
      <td class="row-actions">
        <?php if ((int) $u['id'] !== (int) $admin['id']): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
          <?php if ($u['status'] === 'active'): ?>
            <button class="btn btn--stop btn--sm" name="action" value="suspend">השעיה</button>
          <?php else: ?>
            <button class="btn btn--sm" name="action" value="activate">הפעלה</button>
          <?php endif; ?>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php layoutEnd();
