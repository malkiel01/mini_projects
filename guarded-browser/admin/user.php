<?php
/**
 * מסך ההרשאות של משתמש יחיד.
 *
 * מחולק לאזורים מתקפלים ולא לדף אחד ארוך: יש כאן שמונה נושאים, ומי
 * שרואה את כולם פרושים בבת אחת אינו רואה אף אחד מהם. הראשון פתוח
 * כי הוא ההחלטה שמכתיבה את כל השאר.
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
        $mode = in_array($_POST['mode'] ?? '', MODES, true) ? $_POST['mode'] : MODE_KIOSK;
        $post = in_array($_POST['posture'] ?? '', POSTURES, true) ? $_POST['posture'] : POSTURE_DENY;

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

        $types = array_values(array_intersect(
            (array) ($_POST['types'] ?? []), array_keys(contentTypeCatalog())));

        q('INSERT INTO policies (user_id, mode, posture, blocked_types, timezone, days_mask,
             window_start, window_end, daily_quota_min, session_max_min, max_devices,
             allow_downloads, block_screenshots, keep_history, updated_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
           ON CONFLICT(user_id) DO UPDATE SET
             mode=excluded.mode, posture=excluded.posture, blocked_types=excluded.blocked_types,
             timezone=excluded.timezone, days_mask=excluded.days_mask,
             window_start=excluded.window_start, window_end=excluded.window_end,
             daily_quota_min=excluded.daily_quota_min, session_max_min=excluded.session_max_min,
             max_devices=excluded.max_devices, allow_downloads=excluded.allow_downloads,
             block_screenshots=excluded.block_screenshots, keep_history=excluded.keep_history,
             updated_at=excluded.updated_at',
          [$uid, $mode, $post, implode(',', $types), $tz, $mask, $ws, $we,
           max(0, (int) ($_POST['daily_quota_min'] ?? 0)),
           max(0, (int) ($_POST['session_max_min'] ?? 0)),
           max(1, (int) ($_POST['max_devices'] ?? 1)),
           isset($_POST['allow_downloads']) ? 1 : 0,
           isset($_POST['block_screenshots']) ? 1 : 0,
           isset($_POST['keep_history']) ? 1 : 0,
           nowIso()]);

        q('UPDATE users SET expires_at = ?, display_name = ?, note = ? WHERE id = ?',
          [trim((string) ($_POST['expires_at'] ?? '')),
           mb_substr(trim((string) ($_POST['display_name'] ?? '')), 0, 80),
           mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500), $uid]);

        // קטגוריות: שורה רק למי שיש עליו החלטה. "ללא" פירושו למחוק,
        // כדי שהטבלה תתאר את מה שהוגדר ולא את מה שלא.
        q('DELETE FROM category_rules WHERE user_id = ?', [$uid]);
        foreach ((array) ($_POST['cat'] ?? []) as $cat => $act) {
            if (!isset(categoryCatalog()[$cat]) || !in_array($act, ['allow', 'deny'], true)) continue;
            q('INSERT INTO category_rules (user_id, category, action, created_at) VALUES (?,?,?,?)',
              [$uid, $cat, $act, nowIso()]);
        }

        audit($uid, 'policy_changed', true, '', $mode . '/' . $post, $admin['username']);
        $msg = 'ההרשאות נשמרו';

    } elseif ($action === 'youtube') {
        $m = in_array($_POST['yt_mode'] ?? '', ['off', 'restricted', 'full'], true)
             ? $_POST['yt_mode'] : 'off';
        q('INSERT INTO platform_rules (user_id, platform, mode, allow_search, allow_shorts, created_at)
           VALUES (?,?,?,?,?,?)
           ON CONFLICT(user_id, platform) DO UPDATE SET
             mode=excluded.mode, allow_search=excluded.allow_search,
             allow_shorts=excluded.allow_shorts',
          [$uid, PLATFORM_YOUTUBE, $m,
           isset($_POST['allow_search']) ? 1 : 0,
           isset($_POST['allow_shorts']) ? 1 : 0, nowIso()]);
        $msg = 'הגדרות יוטיוב נשמרו';

    } elseif ($action === 'yt_add') {
        /*
         * המנהל מדביק קישור, לא מזהה. מי שדורש מזהה ערוץ באורך 24
         * תווים דורש ממנו לחפש אותו בעצמו — וזה בדיוק מה שהמערכת
         * אמורה לעשות במקומו.
         */
        $paste = trim((string) ($_POST['yt_url'] ?? ''));
        $p = parseYouTube(preg_match('#^https?://#i', $paste) ? $paste
                          : 'https://www.youtube.com/' . ltrim($paste, '/'));

        if ($p['kind'] === 'shorts') $p['kind'] = 'video';

        if (!in_array($p['kind'], ['video', 'channel', 'handle', 'playlist'], true) || $p['id'] === '') {
            $msg = 'לא זיהיתי בקישור ערוץ, סרטון או פלייליסט'; $kind = 'bad';
        } else {
            $label = trim((string) ($_POST['yt_label'] ?? ''));

            // סרטון: שואלים את יוטיוב למי הוא שייך, כדי שאפשר יהיה
            // להציע למנהל לאשר את הערוץ כולו במקום סרטון בודד.
            if ($p['kind'] === 'video' && $label === '') {
                $owner = one('SELECT title FROM video_owner WHERE platform = ? AND video_id = ?',
                             [PLATFORM_YOUTUBE, $p['id']]);
                if (!$owner) { youTubeOwner($p['id']); $owner = one(
                    'SELECT title FROM video_owner WHERE platform = ? AND video_id = ?',
                    [PLATFORM_YOUTUBE, $p['id']]); }
                $label = (string) ($owner['title'] ?? '');
            }

            q('INSERT INTO platform_items (user_id, platform, kind, item_id, label, action, created_at)
               VALUES (?,?,?,?,?,?,?)
               ON CONFLICT(user_id, platform, kind, item_id) DO UPDATE SET
                 action=excluded.action, label=excluded.label',
              [$uid, PLATFORM_YOUTUBE, $p['kind'], $p['id'], mb_substr($label, 0, 120),
               ($_POST['yt_action'] ?? 'allow') === 'deny' ? 'deny' : 'allow', nowIso()]);
            $msg = 'נוסף לרשימת יוטיוב';
        }

    } elseif ($action === 'yt_del') {
        q('DELETE FROM platform_items WHERE id = ? AND user_id = ?',
          [(int) ($_POST['item_id'] ?? 0), $uid]);
        $msg = 'הפריט הוסר';

    } elseif ($action === 'add_rule') {
        $pattern = trim((string) ($_POST['pattern'] ?? ''));
        $scope   = in_array($_POST['scope'] ?? '', SCOPES, true) ? $_POST['scope'] : 'domain';

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
        q('DELETE FROM rules WHERE id = ? AND user_id = ?', [(int) ($_POST['rule_id'] ?? 0), $uid]);
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
        q('DELETE FROM usage WHERE user_id = ? AND day = ?', [$uid, todayIn(policyFor($uid)['timezone'])]);
        $msg = 'מכסת היום אופסה';
    }

    $user = one('SELECT * FROM users WHERE id = ?', [$uid]);
}

$policy  = policyFor($uid);
$tz      = (string) $policy['timezone'];
$rules   = all('SELECT * FROM rules WHERE user_id = ? ORDER BY sort_order, id', [$uid]);
$devices = all('SELECT * FROM devices WHERE user_id = ? ORDER BY last_seen_at DESC', [$uid]);
$cats    = categoryRulesFor($uid);
$types   = array_filter(explode(',', (string) $policy['blocked_types']));
$yt      = platformRulesFor($uid)[PLATFORM_YOUTUBE]
           ?? ['mode' => 'off', 'allow_search' => 0, 'allow_shorts' => 0];
$ytItems = all('SELECT * FROM platform_items WHERE user_id = ? AND platform = ? ORDER BY kind, id',
               [$uid, PLATFORM_YOUTUBE]);
$used    = usedTodaySeconds($uid, $tz);
$mask    = (int) $policy['days_mask'];
$csrf    = csrfToken();

// המצב ברגע זה — התשובה לשאלה "למה הוא לא מצליח להיכנס עכשיו".
$live = evaluate($user, $policy, ruleSetFor($uid), nowIn($tz), ['url' => '', 'used_today' => $used]);

$countTag = fn(int $n) => $n > 0 ? "($n)" : '';

layoutTop($user['username'], $admin);
note($msg, $kind);
?>

<div class="card">
  <h2><code><?= h($user['username']) ?></code> <?= statusPill($user['status']) ?></h2>
  <p class="hint" style="margin-bottom:12px">
    <?php if ($live['allow']): ?>
      <span class="pill pill--ok">גישה פתוחה כרגע</span>
    <?php else: ?>
      <span class="pill pill--stop">חסום כרגע</span> <?= h($live['reason'] ?: $live['code']) ?>
    <?php endif; ?>
    <br>נוצלו היום <?= h(fmtSeconds($used)) ?><?php
      if ((int) $policy['daily_quota_min'] > 0) echo ' מתוך ' . (int) $policy['daily_quota_min'] . ' דקות'; ?>
  </p>
  <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <button class="btn btn--sm" name="action" value="reset_usage">איפוס מכסת היום</button>
  </form>
</div>

<form method="post">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>">
<input type="hidden" name="action" value="policy">

<?php secOpen('גישה — ההחלטה המרכזית', '', true); ?>
  <p class="hint">שתי שאלות נפרדות: מה המשתמש רואה, ומה קורה כשאף כלל לא מתאים.</p>

  <p class="hint" style="margin-bottom:8px"><strong>מה הוא רואה</strong></p>
  <div class="pick">
    <?php foreach (MODE_LABELS as $v => [$t, $d]): ?>
      <label><input type="radio" name="mode" value="<?= h($v) ?>"
             <?= $policy['mode'] === $v ? 'checked' : '' ?>>
        <span><b><?= h($t) ?></b><small><?= h($d) ?></small></span></label>
    <?php endforeach; ?>
  </div>

  <hr class="sep">
  <p class="hint" style="margin-bottom:8px"><strong>ברירת המחדל</strong></p>
  <div class="pick">
    <?php foreach (POSTURE_LABELS as $v => [$t, $d]): ?>
      <label><input type="radio" name="posture" value="<?= h($v) ?>"
             <?= $policy['posture'] === $v ? 'checked' : '' ?>>
        <span><b><?= h($t) ?></b><small><?= h($d) ?></small></span></label>
    <?php endforeach; ?>
  </div>
<?php secClose(); ?>

<?php secOpen('קטגוריות אתרים', $countTag(count($cats))); ?>
  <p class="hint">
    היתר לפי <em>סוג</em> ולא לפי כתובת. "ללא" משאיר את ההחלטה לברירת המחדל.
    אתר ששייך לשתי קטגוריות — האוסרת מכריעה.
  </p>
  <div class="cats">
    <?php foreach (categoryCatalog() as $key => [$label, $icon]):
      $cur = $cats[$key] ?? ''; ?>
      <div class="cat">
        <span class="nm"><i><?= $icon ?></i><?= h($label) ?></span>
        <span class="seg">
          <label class="y"><input type="radio" name="cat[<?= h($key) ?>]" value="allow"
                 <?= $cur === 'allow' ? 'checked' : '' ?>>מותר</label>
          <label class="o"><input type="radio" name="cat[<?= h($key) ?>]" value=""
                 <?= $cur === '' ? 'checked' : '' ?>>ללא</label>
          <label class="n"><input type="radio" name="cat[<?= h($key) ?>]" value="deny"
                 <?= $cur === 'deny' ? 'checked' : '' ?>>חסום</label>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="hint" style="margin:14px 0 0">
    הסיווג מגיע מקטלוג מובנה. אפשר להוסיף דומיינים משלך ב<a href="categories.php">קטלוג</a>.
  </p>
<?php secClose(); ?>

<?php secOpen('סוגי תוכן', $countTag(count($types))); ?>
  <p class="hint">
    מה נטען, לא לאן פונים. חסימת ווידאו עוצרת סרטון גם באתר שכולו מותר —
    אלא אם יש לאותו אתר כלל כתובת מפורש, שגובר.
  </p>
  <div class="chips">
    <?php foreach (contentTypeCatalog() as $key => $def): ?>
      <label><input type="checkbox" name="types[]" value="<?= h($key) ?>"
             <?= in_array($key, $types, true) ? 'checked' : '' ?>>
        <?= $def['icon'] ?> <?= h($def['label']) ?></label>
    <?php endforeach; ?>
  </div>
  <p class="hint" style="margin:14px 0 0">מסומן = חסום.</p>
<?php secClose(); ?>

<?php secOpen('זמן ומכסות'); ?>
  <p class="hint">שדה ריק או 0 פירושו "בלי הגבלה".</p>
  <p class="hint" style="margin-bottom:8px"><strong>ימים מותרים</strong></p>
  <div class="chips" style="margin-bottom:18px">
    <?php foreach (DAY_NAMES as $i => $n): ?>
      <label style="border-color:<?= ($mask & (1 << $i)) ? 'var(--brand)' : 'var(--line)' ?>">
        <input type="checkbox" name="days[]" value="<?= $i ?>"
               style="accent-color:var(--brand)" <?= ($mask & (1 << $i)) ? 'checked' : '' ?>>
        <?= h($n) ?></label>
    <?php endforeach; ?>
  </div>
  <div class="grid">
    <label><span class="lbl">משעה</span>
      <input type="time" name="window_start" value="<?= h($policy['window_start']) ?>"></label>
    <label><span class="lbl">עד שעה</span>
      <input type="time" name="window_end" value="<?= h($policy['window_end']) ?>"></label>
    <label><span class="lbl">מכסה יומית (דקות)</span>
      <input type="number" name="daily_quota_min" min="0" inputmode="numeric"
             value="<?= (int) $policy['daily_quota_min'] ?>"></label>
    <label><span class="lbl">משך ישיבה (דקות)</span>
      <input type="number" name="session_max_min" min="0" inputmode="numeric"
             value="<?= (int) $policy['session_max_min'] ?>"></label>
    <label><span class="lbl">תוקף החשבון</span>
      <input type="date" name="expires_at" value="<?= h(substr($user['expires_at'], 0, 10)) ?>"></label>
    <label><span class="lbl">אזור זמן</span>
      <select name="timezone">
        <?php foreach (['Asia/Jerusalem','UTC','Europe/London','America/New_York'] as $z): ?>
          <option value="<?= h($z) ?>" <?= $tz === $z ? 'selected' : '' ?>><?= h($z) ?></option>
        <?php endforeach; ?>
      </select></label>
  </div>
  <p class="hint" style="margin:0">שעת התחלה מאוחרת מהסיום = חלון שחוצה חצות (22:00 עד 02:00).</p>
<?php secClose(); ?>

<?php secOpen('מכשיר והתנהגות'); ?>
  <div class="grid">
    <label><span class="lbl">מספר מכשירים מותר</span>
      <input type="number" name="max_devices" min="1" inputmode="numeric"
             value="<?= (int) $policy['max_devices'] ?>"></label>
    <label><span class="lbl">שם לתצוגה</span>
      <input type="text" name="display_name" value="<?= h($user['display_name']) ?>"></label>
  </div>
  <label class="switch"><input type="checkbox" name="allow_downloads"
    <?= $policy['allow_downloads'] ? 'checked' : '' ?>>לאפשר הורדת קבצים</label>
  <label class="switch"><input type="checkbox" name="block_screenshots"
    <?= $policy['block_screenshots'] ? 'checked' : '' ?>>לחסום צילום מסך והקלטה</label>
  <label class="switch"><input type="checkbox" name="keep_history"
    <?= $policy['keep_history'] ? 'checked' : '' ?>>לשמור היסטוריית גלישה במכשיר</label>
  <label><span class="lbl">הערה פנימית</span>
    <textarea name="note" rows="2"><?= h($user['note']) ?></textarea></label>
<?php secClose(); ?>

<div class="savebar">
  <button class="btn btn--go btn--wide" type="submit">שמירת ההרשאות</button>
</div>
</form>

<?php
/* ── יוטיוב: טופס נפרד, כי הוא נשמר בנפרד ─────────────────────── */
secOpen('יוטיוב', YT_MODE_LABELS[$yt['mode']][0] . ' ' . $countTag(count($ytItems)));
?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="youtube">
    <p class="hint">יוטיוב אינו אתר אחד: דף הבית, החיפוש, ערוץ וסרטון הם דברים שונים.</p>
    <div class="pick">
      <?php foreach (YT_MODE_LABELS as $v => [$t, $d]): ?>
        <label><input type="radio" name="yt_mode" value="<?= h($v) ?>"
               <?= $yt['mode'] === $v ? 'checked' : '' ?>>
          <span><b><?= h($t) ?></b><small><?= h($d) ?></small></span></label>
      <?php endforeach; ?>
    </div>
    <hr class="sep">
    <label class="switch"><input type="checkbox" name="allow_search"
      <?= $yt['allow_search'] ? 'checked' : '' ?>>לאפשר חיפוש ביוטיוב</label>
    <label class="switch"><input type="checkbox" name="allow_shorts"
      <?= $yt['allow_shorts'] ? 'checked' : '' ?>>לאפשר Shorts</label>
    <button class="btn btn--go" type="submit">שמירת הגדרות יוטיוב</button>
  </form>

  <hr class="sep">
  <p class="hint" style="margin-bottom:8px"><strong>ערוצים וסרטונים מאושרים</strong></p>
  <table>
    <thead><tr><th>סוג</th><th>מה</th><th>פעולה</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($ytItems as $it):
      $kindName = ['channel' => 'ערוץ', 'handle' => 'ערוץ (כינוי)',
                   'video' => 'סרטון', 'playlist' => 'פלייליסט'][$it['kind']] ?? $it['kind']; ?>
      <tr>
        <td data-l="סוג"><?= h($kindName) ?></td>
        <td data-l="מה"><?= $it['label'] ? h($it['label']) . '<br>' : '' ?>
            <code><?= h($it['item_id']) ?></code></td>
        <td data-l="פעולה"><?= $it['action'] === 'deny'
              ? '<span class="pill pill--stop">איסור</span>'
              : '<span class="pill pill--ok">היתר</span>' ?></td>
        <td>
          <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
            <button class="btn btn--stop btn--sm" name="action" value="yt_del">הסרה</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ytItems): ?><tr><td>עדיין לא אושר דבר</td></tr><?php endif; ?>
    </tbody>
  </table>

  <hr class="sep">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="yt_add">
    <label><span class="lbl">הדביקו קישור לערוץ, סרטון או פלייליסט</span>
      <input type="text" name="yt_url" dir="ltr" required
             placeholder="https://youtube.com/@channel"></label>
    <div class="grid">
      <label><span class="lbl">שם (לא חובה)</span><input type="text" name="yt_label"></label>
      <label><span class="lbl">פעולה</span>
        <select name="yt_action"><option value="allow">היתר</option><option value="deny">איסור</option></select>
      </label>
    </div>
    <button class="btn btn--go btn--wide" type="submit">הוספה לרשימה</button>
    <p class="hint" style="margin:12px 0 0">
      אישור ערוץ פותח גם את הסרטונים שלו: לפני שסרטון נפתח, השרת בודק מול
      יוטיוב לאיזה ערוץ הוא שייך ושומר את התשובה. סרטון שלא ניתן לוודא —
      נחסם.
    </p>
  </form>
<?php secClose(); ?>

<?php secOpen('כללי כתובות', $countTag(count($rules))); ?>
  <p class="hint">הציר המפורש ביותר, ולכן גובר על קטגוריות ועל סוגי תוכן. איסור גובר על היתר תמיד.</p>
  <table>
    <thead><tr><th>שם</th><th>כתובת</th><th>גבול</th><th>פעולה</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rules as $r): ?>
    <tr style="<?= $r['enabled'] ? '' : 'opacity:.45' ?>">
      <td data-l="שם"><?= h($r['label']) ?: '—' ?></td>
      <td data-l="כתובת"><code><?= h($r['pattern']) ?></code></td>
      <td data-l="גבול"><?= h(SCOPE_LABELS[$r['scope']] ?? $r['scope']) ?></td>
      <td data-l="פעולה"><?= $r['action'] === 'deny'
            ? '<span class="pill pill--stop">איסור</span>'
            : '<span class="pill pill--ok">היתר</span>' ?></td>
      <td><div class="acts">
        <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>">
          <button class="btn btn--sm" name="action" value="toggle_rule">
            <?= $r['enabled'] ? 'נטרול' : 'הפעלה' ?></button></form>
        <form method="post" onsubmit="return confirm('למחוק את הכלל?')">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>">
          <button class="btn btn--stop btn--sm" name="action" value="del_rule">מחיקה</button></form>
      </div></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rules): ?><tr><td>אין כללים</td></tr><?php endif; ?>
    </tbody>
  </table>

  <hr class="sep">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="add_rule">
    <div class="grid">
      <label><span class="lbl">שם (מוצג על האריח)</span>
        <input type="text" name="label" placeholder="ליגת האלופות"></label>
      <label><span class="lbl">כתובת</span>
        <input type="text" name="pattern" dir="ltr" required placeholder="liveball.sx/team/772"></label>
      <label><span class="lbl">גבול הניווט</span>
        <select name="scope">
          <?php foreach (SCOPE_LABELS as $v => $l): ?>
            <option value="<?= h($v) ?>" <?= $v === 'domain_plus' ? 'selected' : '' ?>><?= h($l) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label><span class="lbl">פעולה</span>
        <select name="rule_action"><option value="allow">היתר</option><option value="deny">איסור</option></select>
      </label>
    </div>
    <label class="switch"><input type="checkbox" name="show_tile" checked>להציג כאריח במסך הפתיחה</label>
    <button class="btn btn--go btn--wide" type="submit">הוספת כלל</button>
  </form>
<?php secClose(); ?>

<?php secOpen('מכשירים מחוברים', $countTag(count($devices))); ?>
  <p class="hint">ניתוק מבטל את האסימון מיד.</p>
  <table>
    <thead><tr><th>מכשיר</th><th>נכנס</th><th>נראה</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($devices as $d): ?>
    <tr>
      <td data-l="מכשיר"><?= h($d['device_name']) ?: '—' ?></td>
      <td data-l="נכנס"><?= h(substr($d['created_at'], 0, 10)) ?></td>
      <td data-l="נראה"><?= h(str_replace(['T','Z'], [' ',''], substr($d['last_seen_at'], 0, 16))) ?></td>
      <td><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="device_id" value="<?= (int) $d['id'] ?>">
        <button class="btn btn--stop btn--sm" name="action" value="del_device">ניתוק</button>
      </form></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$devices): ?><tr><td>אין מכשירים מחוברים</td></tr><?php endif; ?>
    </tbody>
  </table>
<?php secClose(); ?>

<?php secOpen('פעילות אחרונה'); ?>
  <table>
    <thead><tr><th>מתי</th><th>מה</th><th>כתובת</th><th>תוצאה</th></tr></thead>
    <tbody>
    <?php foreach (all('SELECT * FROM audit WHERE user_id = ? ORDER BY id DESC LIMIT 25', [$uid]) as $a): ?>
    <tr>
      <td data-l="מתי"><?= h(str_replace(['T','Z'], [' ',''], substr($a['at'], 0, 16))) ?></td>
      <td data-l="מה"><?= h($a['kind']) ?></td>
      <td data-l="כתובת"><code><?= h(mb_substr($a['url'], 0, 60)) ?></code></td>
      <td data-l="תוצאה"><?= $a['allowed'] ? '<span class="pill pill--ok">הותר</span>'
            : '<span class="pill pill--stop">' . h($a['code']) . '</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="hint" style="margin:12px 0 0"><a href="audit.php?user=<?= $uid ?>">ליומן המלא</a></p>
<?php secClose(); ?>
<?php layoutEnd();
