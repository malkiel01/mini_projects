<?php
/**
 * אבחון סביבה.
 *
 * ‏500 ריק אומר "משהו נשבר" ולא אומר מה, ובאחסון משותף אין גישה ללוג
 * של PHP. המסך הזה עונה על השאלות שמפרידות בין תקלה בקוד לתקלה
 * בסביבה, ומציג את יומן השגיאות שנרשם.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ui.php';

$admin = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    if (($_POST['action'] ?? '') === 'clear_log') { @unlink(errorLogPath()); $msg = 'היומן נוקה'; }
}

/** בדיקה יחידה: שם, האם תקין, ומה נמצא בפועל. */
function probe(string $name, bool $ok, string $detail, string $note = ''): array {
    return ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'note' => $note];
}

$pdo     = db();
$sqlite  = $pdo->query('SELECT sqlite_version()')->fetchColumn();
$cols    = array_column($pdo->query('PRAGMA table_info(policies)')->fetchAll(), 'name');
$tables  = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name');
$dbFile  = dataDir() . '/app.sqlite';

$checks = [
    probe('גרסת PHP', PHP_VERSION_ID >= 80000, PHP_VERSION, 'נדרש 8.0 ומעלה'),
    // הקוד נכתב כך שיעבוד גם בגרסאות ישנות, ולכן זו הערה ולא כשל.
    probe('גרסת SQLite', version_compare($sqlite, '3.7', '>='), $sqlite,
          version_compare($sqlite, '3.24', '<')
            ? 'גרסה ישנה מ-3.24 — הקוד נמנע מ-UPSERT ולכן זה תקין'
            : ''),
    probe('הרחבת PDO SQLite', in_array('sqlite', PDO::getAvailableDrivers(), true),
          implode(', ', PDO::getAvailableDrivers())),
    probe('cURL', function_exists('curl_init'), function_exists('curl_init') ? 'זמין' : 'חסר',
          'נדרש כדי לזהות לאיזה ערוץ שייך סרטון ביוטיוב'),
    probe('תיקיית הנתונים ניתנת לכתיבה', is_writable(dataDir()), dataDir()),
    probe('קובץ בסיס הנתונים', is_writable($dbFile), $dbFile),
    probe('כל הטבלאות קיימות',
          count(array_intersect(['users','policies','rules','devices','usage','audit',
                'category_rules','domain_categories','platform_rules','platform_items',
                'video_owner'], $tables)) === 11,
          implode(', ', $tables)),
    probe('העמודות החדשות ב-policies',
          in_array('posture', $cols, true) && in_array('blocked_types', $cols, true),
          implode(', ', $cols),
          'אם חסרות — המיגרציה לא רצה, וכל שמירת הרשאות תיכשל'),
    probe('הקטלוג נזרע',
          (int) $pdo->query('SELECT COUNT(*) FROM domain_categories')->fetchColumn() > 50,
          $pdo->query('SELECT COUNT(*) FROM domain_categories')->fetchColumn() . ' סיווגים'),
];

$log  = is_file(errorLogPath()) ? (string) file_get_contents(errorLogPath()) : '';
$csrf = csrfToken();

layoutTop('אבחון', $admin);
note($msg ?? '', 'ok');
?>
<div class="card">
  <h2>סביבת השרת</h2>
  <p class="hint">כל שורה אדומה כאן היא סיבה אפשרית לשגיאה 500.</p>
  <table>
    <thead><tr><th>בדיקה</th><th>מצב</th><th>מה נמצא</th></tr></thead>
    <tbody>
    <?php foreach ($checks as $c): ?>
      <tr>
        <td data-l="בדיקה"><?= h($c['name']) ?>
          <?php if ($c['note'] !== ''): ?><br><small style="color:var(--dim)"><?= h($c['note']) ?></small><?php endif; ?>
        </td>
        <td data-l="מצב"><?= $c['ok'] ? '<span class="pill pill--ok">תקין</span>'
                                       : '<span class="pill pill--stop">בעיה</span>' ?></td>
        <td data-l="מה נמצא"><code><?= h(mb_substr($c['detail'], 0, 200)) ?></code></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2>יומן שגיאות</h2>
  <p class="hint">
    <?= $log === '' ? 'ריק — לא נרשמה שגיאה.' : 'השגיאות האחרונות. העתיקו ושלחו לי.' ?>
  </p>
  <?php if ($log !== ''): ?>
    <pre style="direction:ltr;text-align:left;white-space:pre-wrap;font-size:12px;
                background:var(--bg);padding:12px;border-radius:9px;overflow:auto;max-height:60vh"><?=
      h(mb_substr($log, -12000)) ?></pre>
    <form method="post" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <button class="btn btn--stop" name="action" value="clear_log">ניקוי היומן</button>
    </form>
  <?php endif; ?>
</div>
<?php layoutEnd();
