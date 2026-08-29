<?php
/**
 * מסך ההרשאות של משתמש יחיד — מצב גלישה, תנאי זמן, כללים, מכשירים.
 *
 * הכול במסך אחד ולא בלשוניות: ההחלטות תלויות זו בזו. מכסת זמן בלי
 * לראות את חלון הזמן, או כלל בלי לראות את מצב הגלישה, היא החלטה
 * שנלקחת חצי עיוורת.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ui.php';

$admin = requireAdmin();
$uid   = (int) ($_GET['id'] ?? 0);
$user  = $uid > 0 ? one('SELECT * FROM users WHERE id = ?', [$uid]) : null;

if (!$user) {
    layoutTop('משתמש', $admin);
    note('המשתמש לא נמצא', 'bad');
    layoutEnd();
    exit;
}

$msg = ''; $kind = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'policy') {
        $mode = (string) ($_POST['mode'] ?? MODE_KIOSK);
        if (!in_array($mode, MODES, true)) $mode = MODE_KIOSK;

        // מסכת הימים נבנית מתיבות סימון; היעדר סימון = אף יום.
        $mask = 0;
        foreach ((array) ($_POST['days'] ?? []) as $d) {
            $d = (int) $d;
            if ($d >= 0 && $d <= 6) $mask |= (1 << $d);
        }

        $tz = (string) ($_POST['timezone'] ?? 'Asia/Jerusalem');
        if (!in_array($tz, timezone_identifiers_list(), true)) $tz = 'Asia/Jerusalem';

        // שעה ריקה משמעה "בלי חלון". חצי חלון הוא שגיאה שקטה, ולכן
        // שדה אחד ריק מבטל את שניהם.
        $ws = trim((string) ($_POST['window_start'] ?? ''));
        $we = trim((string) ($_POST['window_end'] ?? ''));
        if ($ws === '' || $we === '') { $ws = ''; $we = ''; }

        q('INSERT INTO policies (user_id, mode, timezone, days_mask, window_start, window_end,
             daily_quota_min, session_max_min, max_devices, allow_downloads,
             block_screenshots, keep_history, updated_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
           ON CONFLICT(user_id) DO UPDATE SET
             mode=excluded.mode, timezone=excluded.timezone, days_mask=excluded.days_mask,
             window_start=excluded.window_start, window_end=excluded.window_end,
             daily_quota_min=excluded.daily_quota_min, session_max_min=excluded.session_max_min,
             max_devices=excluded.max_devices, allow_downloads=excluded.allow_downloads,
             block_screenshots=excluded.block_screenshots, keep_history=excluded.keep_history,
             updated_at=excluded.updated_at',
          [$uid, $mode, $tz, $mask, $ws, $we,
           max(0, (int) ($_POST['daily_quota_min'] ?? 0)),
           max(0, (int) ($_POST['session_max_min'] ?? 0)),
           max(1, (int) ($_POST['max_devices'] ?? 1)),
           isset($_POST['allow_downloads']) ? 1 : 0,
           isset($_POST['block_screenshots']) ? 1 : 0,
           isset($_POST['keep_history']) ? 1 : 0,
           nowIso()]);

        $expires = trim((string) ($_POST['expires_at'] ?? ''));
        q('UPDATE users SET expires_at = ?, display_name = ?, note = ? WHERE id = ?',
          [$expires, mb_substr(trim((string) ($_POST['display_name'] ?? '')), 0, 80),
           mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500), $uid]);

        audit($uid, 'policy_changed', true, '', $mode, $admin['username']);
        $msg = 'ההרשאות נשמרו';

    } elseif ($action === 'add_rule') {
        $pattern = trim((string) ($_POST['pattern'] ?? ''));
        $scope   = (string) ($_POST['scope'] ?? 'domain');
        if (!in_array($scope, SCOPES, true)) $scope = 'domain';

        // נרמול לפני שמירה: אותה בדיקה שהמנוע יעשה בזמן אמת, כדי
        // שכלל פסול ייעצר כאן ולא ייכשל בשקט אצל המשתמש.
        if (!normalizeUrl($pattern)) {
            $msg = 'הכתובת אינה תקינה'; $kind = 'bad';
        } else {
            q('INSERT INTO rules (user_id, label, pattern, scope, action, show_tile, sort_order, created_at)
               VALUES (?,?,?,?,?,?,?,?)',
              [$uid, mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 80), $pattern, $scope,
               ($_POST['rule_action'] ?? 'allow') === 'deny' ? 'deny' : 'allow',
               isset($_POST['show_tile']) ? 1 : 0,
               (int) ($_POST['sort_order'] ?? 0), nowIso()]);
            $msg = 'הכלל נוסף';
        }

    } elseif ($action === 'del_rule') {
        q('DELETE FROM rules WHERE id = ? AND user_id = ?',
          [(int) ($_POST['rule_id'] ?? 0), $uid]);
        $msg = 'הכלל נמחק';

    } elseif ($action === 'toggle_rule') {
        q('UPDATE rules SET enabled = 1 - enabled WHERE id = ? AND user_id = ?',
          [(int) ($_POST['rule_id'] ?? 0), $uid]);
        $msg = 'מצב הכלל התחלף';

    } elseif ($action === 'del_device') {
        q('DELETE FROM devices WHERE id = ? AND user_id = ?',
          [(int) ($_POST['device_id'] ?? 0), $uid]);
        $msg = 'המכשיר נותק';

    } elseif ($action === 'reset_usage') {
        $tz = (string) policyFor($uid)['timezone'];
        q('DELETE FROM usage WHERE user_id = ? AND day = ?', [$uid, todayIn($tz)]);
        audit($uid, 'usage_reset', true, '', 'by_admin', $admin['username']);
        $msg = 'מכסת היום אופסה';
    }

    $user = one('SELECT * FROM users WHERE id = ?', [$uid]);
}

$policy  = policyFor($uid);
$tz      = (string) $policy['timezone'];
$rules   = all('SELECT * FROM rules WHERE user_id = ? ORDER BY sort_order, id', [$uid]);
$devices = all('SELECT * FROM devices WHERE user_id = ? ORDER BY last_seen_at DESC', [$uid]);
$used    = usedTodaySeconds($uid, $tz);
$mask    = (int) $policy['days_mask'];
$csrf    = csrfToken();

// המצב ברגע זה — התשובה לשאלה "למה הוא לא מצליח להיכנס עכשיו".
$live = evaluate($user, $policy, rulesFor($uid), nowIn($tz), ['url' => '', 'used_today' => $used]);

layoutTop($user['username'], $admin);
note($msg, $kind);
?>

<div class="card">
  <h2><code><?= h($user['username']) ?></code> <?= statusPill($user['status']) ?></h2>
  <p class="hint">
    מצב ברגע זה:
    <?php if ($live['allow']): ?>
      <strong style="color:var(--ok)">גישה פתוחה</strong>
    <?php else: ?>
      <strong style="color:var(--stop)">חסום</strong> — <?= h($live['reason'] ?: $live['code']) ?>
    <?php endif; ?>
    · נוצלו היום <?= h(fmtSeconds($used)) ?>
    <?php if ((int) $policy['daily_quota_min'] > 0): ?>
      מתוך <?= (int) $policy['daily_quota_min'] ?> דקות
    <?php endif; ?>
  </p>
  <form method="post" style="display:inline">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <button class="btn btn--sm" name="action" value="reset_usage">איפוס מכסת היום</button>
  </form>
</div>

<form method="post">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>">
<input type="hidden" name="action" value="policy">

<div class="card">
  <h2>מצב גלישה</h2>
  <p class="hint">זו ההרשאה המרכזית. היא נקבעת לכל משתמש בנפרד.</p>
  <?php foreach (MODE_LABELS as $value => $label): ?>
    <label style="display:flex;gap:8px;align-items:flex-start">
      <input type="radio" name="mode" value="<?= h($value) ?>"
             <?= $policy['mode'] === $value ? 'checked' : '' ?>>
      <span style="margin:0"><?= h($label) ?></span>
    </label>
  <?php endforeach; ?>
</div>

<div class="card">
  <h2>תנאי זמן</h2>
  <p class="hint">שדה ריק או 0 פירושו "בלי הגבלה".</p>

  <fieldset>
    <legend>ימים מותרים</legend>
    <div class="days">
      <?php foreach (DAY_NAMES as $i => $name): ?>
        <label><input type="checkbox" name="days[]" value="<?= $i ?>"
                      <?= ($mask & (1 << $i)) ? 'checked' : '' ?>><span><?= h($name) ?></span></label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <div class="grid">
    <label><span>מתחילת השעה</span>
      <input type="time" name="window_start" value="<?= h($policy['window_start']) ?>"></label>
    <label><span>ועד</span>
      <input type="time" name="window_end" value="<?= h($policy['window_end']) ?>">
    </label>
    <label><span>מכסת צפייה יומית (דקות)</span>
      <input type="number" name="daily_quota_min" min="0" value="<?= (int) $policy['daily_quota_min'] ?>"></label>
    <label><span>משך ישיבה מרבי (דקות)</span>
      <input type="number" name="session_max_min" min="0" value="<?= (int) $policy['session_max_min'] ?>"></label>
    <label><span>תוקף החשבון</span>
      <input type="date" name="expires_at" value="<?= h(substr($user['expires_at'], 0, 10)) ?>"></label>
    <label><span>אזור זמן</span>
      <select name="timezone">
        <?php foreach (['Asia/Jerusalem', 'UTC', 'Europe/London', 'America/New_York'] as $z): ?>
          <option value="<?= h($z) ?>" <?= $tz === $z ? 'selected' : '' ?>><?= h($z) ?></option>
        <?php endforeach; ?>
      </select></label>
  </div>
  <p class="hint">חלון שבו שעת ההתחלה מאוחרת מהסיום חוצה חצות — למשל 22:00 עד 02:00.</p>
</div>

<div class="card">
  <h2>מכשירים והתנהגות</h2>
  <div class="grid">
    <label><span>מספר מכשירים מותר</span>
      <input type="number" name="max_devices" min="1" value="<?= (int) $policy['max_devices'] ?>"></label>
    <label><span>שם לתצוגה</span>
      <input type="text" name="display_name" value="<?= h($user['display_name']) ?>"></label>
  </div>
  <label style="display:flex;gap:8px"><input type="checkbox" name="allow_downloads"
    <?= $policy['allow_downloads'] ? 'checked' : '' ?>><span style="margin:0">לאפשר הורדת קבצים</span></label>
  <label style="display:flex;gap:8px"><input type="checkbox" name="block_screenshots"
    <?= $policy['block_screenshots'] ? 'checked' : '' ?>><span style="margin:0">לחסום צילום מסך והקלטה</span></label>
  <label style="display:flex;gap:8px"><input type="checkbox" name="keep_history"
    <?= $policy['keep_history'] ? 'checked' : '' ?>><span style="margin:0">לשמור היסטוריית גלישה במכשיר</span></label>
  <label><span>הערה פנימית</span><textarea name="note" rows="2"><?= h($user['note']) ?></textarea></label>
  <button class="btn btn--go" type="submit">שמירת ההרשאות</button>
</div>
</form>

<div class="card">
  <h2>כללי כתובות (<?= count($rules) ?>)</h2>
  <p class="hint">איסור גובר על היתר תמיד, בלי קשר לסדר.</p>
  <table>
    <tr><th>שם</th><th>כתובת</th><th>גבול הניווט</th><th>פעולה</th><th>אריח</th><th></th></tr>
    <?php foreach ($rules as $r): ?>
    <tr style="<?= $r['enabled'] ? '' : 'opacity:.45' ?>">
      <td><?= h($r['label']) ?: '—' ?></td>
      <td><code><?= h($r['pattern']) ?></code></td>
      <td><?= h(SCOPE_LABELS[$r['scope']] ?? $r['scope']) ?></td>
      <td><?= $r['action'] === 'deny'
            ? '<span class="pill pill--stop">איסור</span>'
            : '<span class="pill pill--ok">היתר</span>' ?></td>
      <td><?= $r['show_tile'] ? '✓' : '' ?></td>
      <td class="row-actions">
        <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>">
          <button class="btn btn--sm" name="action" value="toggle_rule">
            <?= $r['enabled'] ? 'נטרול' : 'הפעלה' ?></button>
        </form>
        <form method="post" onsubmit="return confirm('למחוק את הכלל?')">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>">
          <button class="btn btn--stop btn--sm" name="action" value="del_rule">מחיקה</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>

  <form method="post" style="margin-top:18px">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="add_rule">
    <div class="grid">
      <label><span>שם (מוצג על האריח)</span><input type="text" name="label" placeholder="ליגת האלופות"></label>
      <label><span>כתובת</span><input type="text" name="pattern" dir="ltr" required
              placeholder="liveball.sx/team/772"></label>
      <label><span>גבול הניווט</span>
        <select name="scope">
          <?php foreach (SCOPE_LABELS as $v => $l): ?>
            <option value="<?= h($v) ?>" <?= $v === 'domain_plus' ? 'selected' : '' ?>><?= h($l) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label><span>פעולה</span>
        <select name="rule_action">
          <option value="allow">היתר</option>
          <option value="deny">איסור</option>
        </select></label>
      <label><span>סדר</span><input type="number" name="sort_order" value="0"></label>
    </div>
    <label style="display:flex;gap:8px"><input type="checkbox" name="show_tile" checked>
      <span style="margin:0">להציג כאריח במסך הפתיחה</span></label>
    <button class="btn btn--go" type="submit">הוספת כלל</button>
  </form>
</div>

<div class="card">
  <h2>מכשירים מחוברים (<?= count($devices) ?>)</h2>
  <p class="hint">ניתוק מכשיר מבטל את האסימון שלו מיד.</p>
  <table>
    <tr><th>מכשיר</th><th>נכנס</th><th>נראה לאחרונה</th><th></th></tr>
    <?php foreach ($devices as $d): ?>
    <tr>
      <td><?= h($d['device_name']) ?: '—' ?></td>
      <td><?= h(substr($d['created_at'], 0, 16)) ?></td>
      <td><?= h(substr($d['last_seen_at'], 0, 16)) ?></td>
      <td>
        <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="device_id" value="<?= (int) $d['id'] ?>">
          <button class="btn btn--stop btn--sm" name="action" value="del_device">ניתוק</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$devices): ?><tr><td colspan="4">אין מכשירים מחוברים</td></tr><?php endif; ?>
  </table>
</div>

<div class="card">
  <h2>פעילות אחרונה</h2>
  <table>
    <tr><th>מתי</th><th>מה</th><th>כתובת</th><th>תוצאה</th></tr>
    <?php foreach (all('SELECT * FROM audit WHERE user_id = ? ORDER BY id DESC LIMIT 25', [$uid]) as $a): ?>
    <tr>
      <td><?= h(str_replace(['T', 'Z'], [' ', ''], substr($a['at'], 0, 16))) ?></td>
      <td><?= h($a['kind']) ?></td>
      <td><code><?= h(mb_substr($a['url'], 0, 70)) ?></code></td>
      <td><?= $a['allowed'] ? '<span class="pill pill--ok">הותר</span>'
                            : '<span class="pill pill--stop">' . h($a['code']) . '</span>' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php layoutEnd();
