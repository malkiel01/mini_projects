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

$open   = all("SELECT a.*, u.username, u.display_name FROM alerts a LEFT JOIN users u ON u.id = a.user_id
               WHERE a.acked_at = '' ORDER BY a.id DESC LIMIT 50");
$recent = all("SELECT a.*, u.username, u.display_name FROM alerts a LEFT JOIN users u ON u.id = a.user_id
               WHERE a.acked_at != '' ORDER BY a.id DESC LIMIT 30");
$csrf   = csrfToken();

/** מתי, בשעון ישראל ובמילים: "היום 19:17", "אתמול 08:02", "06.09 19:17". */
function alertWhen(string $iso): string {
    $tz = new DateTimeZone('Asia/Jerusalem');
    $t = (new DateTimeImmutable($iso))->setTimezone($tz);
    $days = (int) (new DateTimeImmutable('today', $tz))->diff($t->setTime(0, 0))->format('%r%a');
    return ($days === 0 ? 'היום' : ($days === -1 ? 'אתמול' : $t->format('d.m'))) . ' ' . $t->format('H:i');
}

/** איפה, בקצרה: שם האתר, ויוטיוב — במילים. הכתובת המלאה — בריחוף (title). */
function alertPlace(string $url): string {
    $host = (string) (normalizeUrl($url)['host'] ?? '');
    if ($url === '' || platformOf($host) !== PLATFORM_YOUTUBE) return $host ?: $url;
    $yt = parseYouTube($url);
    $label = ['video' => 'סרטון ביוטיוב', 'shorts' => 'Shorts ביוטיוב', 'channel' => 'ערוץ ביוטיוב',
              'handle' => 'ערוץ ביוטיוב', 'playlist' => 'פלייליסט ביוטיוב', 'search' => 'חיפוש ביוטיוב'][$yt['kind']] ?? '';
    return $label !== '' ? $label : $host;
}

/** משפט אחד שאומר מה קרה — במקום כותרת טכנית. */
function alertSentence(array $a): string {
    $who = $a['display_name'] ?: ($a['username'] ?: 'משתמש שנמחק');
    if ($a['kind'] === 'probing' && preg_match('/^(\d+)/', (string) $a['detail'], $m)) {
        return "$who נחסם/ה {$m[1]} פעמים תוך " . PROBE_MINUTES . ' דקות';
    }
    if ($a['kind'] === 'enforcement_gap') return "האפליקציה של $who פתחה משהו שהשרת חוסם";
    return "$who — {$a['title']}";
}

function alertNote(array $a): string {
    return match ($a['kind']) {
        'probing' => 'לידיעה בלבד — שום דבר לא ננעל',
        'enforcement_gap' => 'ננעל אוטומטית עד שתפתחו מחדש',
        default => (string) $a['detail'],
    };
}

layoutTop('התרעות', $admin);
note($msg, $kind);
?>
<style>
  .al-head { display:flex; align-items:center; gap:8px; margin-bottom:10px; }
  .al-head h2 { flex:1; margin:0; }
  .al-list { list-style:none; margin:0; padding:0; border:1px solid var(--line); border-radius:10px; overflow:hidden; }
  .al { display:flex; align-items:center; gap:10px; padding:10px 12px; border-top:1px solid var(--line); min-width:0; }
  .al:first-child { border-top:0; }
  .al-ic { flex:none; width:34px; height:34px; display:grid; place-items:center; border-radius:50%; font-size:16px; }
  .al-ic.warn { background:var(--warn-bg); } .al-ic.high { background:var(--stop-bg); } .al-ic.done { background:var(--bg); }
  .al-body { flex:1; min-width:0; }
  .al-t { font-weight:600; font-size:14.5px; line-height:1.35; }
  .al-s, .al-m { font-size:12.5px; color:var(--dim); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .al-t a { color:inherit; text-decoration:none; }
  .al-n { font-size:12.5px; color:var(--warn); margin-top:2px; }
  .al-n.high { color:var(--stop); }
  .al-acts { flex:none; display:flex; flex-direction:column; gap:6px; }
  .al-acts form { margin:0; }
  .al-acts .btn { white-space:nowrap; }
  .al-what summary { cursor:pointer; color:var(--dim); font-size:13.5px; }
  .al-what p { margin:8px 0 0; color:var(--dim); font-size:13.5px; line-height:1.5; }
</style>

<div class="card">
  <div class="al-head">
    <h2>פתוחות (<?= count($open) ?>)</h2>
    <?php if (count($open) > 1): ?>
      <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <button class="btn btn--sm" name="action" value="ack_all">✓ הכול טופל</button></form>
    <?php endif; ?>
  </div>
  <?php if (!$open): ?>
    <p class="hint" style="margin:0">אין התרעות פתוחות 👍</p>
  <?php else: ?>
    <ul class="al-list">
    <?php foreach ($open as $a): $high = $a['severity'] === 'high'; ?>
      <li class="al">
        <span class="al-ic <?= $high ? 'high' : 'warn' ?>" aria-hidden="true"><?= $high ? '🔒' : '⚠️' ?></span>
        <div class="al-body">
          <div class="al-t"><?php if ($a['username']): ?><a href="user.php?id=<?= (int) $a['user_id'] ?>"><?= h(alertSentence($a)) ?></a><?php else: ?><?= h(alertSentence($a)) ?><?php endif; ?></div>
          <?php if ($a['url']): ?>
            <div class="al-s" title="<?= h($a['url']) ?>"><?= h(alertWhen($a['at'])) ?> · <bdi><?= h(alertPlace($a['url'])) ?></bdi></div>
          <?php else: ?>
            <div class="al-s"><?= h(alertWhen($a['at'])) ?></div>
          <?php endif; ?>
          <div class="al-n <?= $high ? 'high' : '' ?>"><?= h(alertNote($a)) ?></div>
        </div>
        <div class="al-acts">
          <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <button class="btn btn--sm" name="action" value="ack" aria-label="סימון כטופל">✓ טופל</button></form>
          <?php if ($a['kind'] === 'enforcement_gap'): ?>
          <form method="post" onsubmit="return confirm('לפתוח מחדש את הגישה ליוטיוב?')">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="user_id" value="<?= (int) $a['user_id'] ?>">
            <button class="btn btn--sm" name="action" value="reopen">🔓 פתיחה</button></form>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<?php if ($recent): ?>
<div class="card">
  <h2>טופלו לאחרונה</h2>
  <ul class="al-list">
  <?php foreach ($recent as $a): ?>
    <li class="al">
      <span class="al-ic done" aria-hidden="true">✓</span>
      <div class="al-body">
        <div class="al-t" style="font-weight:500"><?= h(alertSentence($a)) ?></div>
        <div class="al-s"><?= h(alertWhen($a['at'])) ?><?= $a['url'] ? ' · ' . h(alertPlace($a['url'])) : '' ?></div>
      </div>
    </li>
  <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="card">
  <details class="al-what">
    <summary>ℹ️ מה נחשב להתרעה?</summary>
    <p><strong>⚠️ ניסיונות חוזרים</strong> — מישהו נחסם הרבה פעמים בזמן קצר. זה רק לידיעה: דפוס אינו
       ראיה, וילד שנתקע על אתר לא אמור למצוא את עצמו נעול.</p>
    <p><strong>🔒 האפליקציה עקפה את השרת</strong> — האכיפה קיימת גם באפליקציה וגם בשרת. כשהאפליקציה
       פותחת משהו שהשרת חוסם, היא כנראה נפרצה — ולכן יוטיוב ננעל לו מיד, בלי לחכות לך.
       "🔓 פתיחה" מחזיר אותו למצב מוגבל.</p>
  </details>
</div>
<?php layoutEnd();
