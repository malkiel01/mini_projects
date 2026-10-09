<?php
/**
 * צפייה ביומן דרך טוקן — בלי כניסה.
 *
 * המפתח יוצר טוקן באזור הפיתוח, בוחר תוקף, ומעביר את הקישור למי שמפתח.
 * הדף קריאה בלבד: מסננים, "טען עוד", וייצוא כטקסט (להדבקה בצ'אט) או JSON.
 * טוקן שפג או בוטל מקבל 403, ולא רמז מה השתבש.
 *
 *   logs.php?token=…                 דף HTML
 *   logs.php?token=…&format=text     טקסט, שורה לאירוע, כרונולוגי
 *   logs.php?token=…&format=json     JSON, החדש ראשון
 * מסננים בשלושתם: level, action, user, q, request_id, since, before, limit.
 *
 *   logs.php?token=…&view=index      מצב האינדקס (JSON): לכל אתר — מתכונים, תמונות, התקדמות העדכון
 *   logs.php?token=…&view=import     יומן הייבוא (lib/import_log.php): מה היה
 *                                    בכל דף ומה חולץ ממנו, עם קישור למקור.
 *                                    גם &format=text|json. מסננים: host, kind,
 *                                    ok, q, since, before, limit.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/log.php';
require_once __DIR__ . '/lib/import_log.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/rewrite_queue.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$raw   = (string) ($_GET['token'] ?? '');
$token = resolveLogToken($raw);
if (!$token) {
    logEvent('warn', 'log-view', 'ניסיון צפייה עם טוקן לא תקף', ['token_prefix' => substr($raw, 0, 6)]);
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "הקישור אינו תקף, פג תוקפו, או בוטל.\n";
    exit;
}

$format  = (string) ($_GET['format'] ?? 'html');
$view    = (string) ($_GET['view'] ?? 'system');
$esc     = fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// ניסוח ידני: התור לניסוח, כ-JSON — כך קלוד קורא אותו ומחזיר קובץ מנוסח (lib/rewrite_queue.php).
// קריאה בלבד; ההחלה — רק מהאפליקציה, בידי המפתח.
if ($view === 'rewrite') {
    $dev = db()->query("SELECT id, username, role FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch();
    logEvent('info', 'log-view', 'תור הניסוח דרך טוקן "' . $token['label'] . '"', ['token_id' => (int) $token['id']]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dev ? rewriteExport($dev, (int) ($_GET['limit'] ?? REWRITE_BATCH)) : ['items' => []],
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// מצב האינדקס: לכל אתר — מתכונים, תמונות, האם מתעדכן ועד כמה. קריאה בלבד.
if ($view === 'index') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['now' => nowIso(), 'sites' => indexSites()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if ($view === 'import') {
    $filters = array_intersect_key($_GET, array_flip(['host', 'kind', 'ok', 'q', 'since', 'before']));
    $limit   = (int) ($_GET['limit'] ?? 50);
    $rows    = importLogList($filters, $limit);
    logEvent('info', 'log-view', 'צפייה ביומן הייבוא דרך טוקן "' . $token['label'] . '"',
             ['token_id' => (int) $token['id'], 'format' => $format, 'rows' => count($rows)]);
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'rows' => $rows, 'stats' => importLogStats()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($format === 'text') {
        header('Content-Type: text/plain; charset=utf-8');
        echo importLogAsText($rows);
        exit;
    }
    $stats = importLogStats();
    $self  = './logs.php?token=' . $esc($raw) . '&view=import';
    $qs    = fn(array $extra) => $self . '&' . http_build_query(array_filter($filters + $extra, fn($v) => $v !== '' && $v !== null));
    ?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>יומן ייבוא · אפליקציית מתכונים</title>
<meta name="theme-color" content="#0f8a4f">
<link rel="icon" href="./assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="./assets/css/app.css?v=2026-10-09h">
</head>
<body>
<header class="bar">
  <span class="bar__title">יומן ייבוא — <?= $esc($token['label']) ?></span>
  <a class="btn btn--ghost" href="./logs.php?token=<?= $esc($raw) ?>">יומן המערכת</a>
</header>
<main class="stage">
<section class="card settings--wide logview importlog">
  <p class="muted">
    <?= (int) $stats['rows'] ?> הבאות · <?= (int) $stats['failed_24h'] ?> נכשלו ב-24 השעות האחרונות ·
    <a href="<?= $qs(['format' => 'text', 'limit' => 100]) ?>">טקסט</a> ·
    <a href="<?= $qs(['format' => 'json', 'limit' => 100]) ?>">JSON</a>
  </p>
  <form class="logfilter" method="get">
    <input type="hidden" name="token" value="<?= $esc($raw) ?>">
    <input type="hidden" name="view" value="import">
    <select name="host" aria-label="אתר">
      <option value="">כל האתרים</option>
      <?php foreach ($stats['hosts'] as $h): ?>
        <option value="<?= $esc($h) ?>" <?= ($filters['host'] ?? '') === $h ? 'selected' : '' ?>><?= $esc($h) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="kind" aria-label="סוג">
      <option value="">כל הסוגים</option>
      <?php foreach (IMPORT_LOG_KIND_HE as $k => $l): ?>
        <option value="<?= $esc($k) ?>" <?= ($filters['kind'] ?? '') === $k ? 'selected' : '' ?>><?= $esc($l) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="ok" aria-label="תוצאה">
      <option value="">הצליח ונכשל</option>
      <option value="0" <?= ($filters['ok'] ?? '') === '0' ? 'selected' : '' ?>>נכשל בלבד</option>
      <option value="1" <?= ($filters['ok'] ?? '') === '1' ? 'selected' : '' ?>>הצליח בלבד</option>
    </select>
    <input type="search" name="q" value="<?= $esc($filters['q'] ?? '') ?>" placeholder="חיפוש בשם / בכתובת / בשגיאה">
    <button class="btn btn--primary" type="submit">סנן</button>
  </form>
  <?php if (!$rows): ?>
    <p class="muted">אין הבאות שתואמות.</p>
  <?php else: ?>
  <div class="importlog__list">
  <?php foreach ($rows as $r): ?>
    <article class="ilog ilog--<?= $r['ok'] ? 'ok' : 'err' ?>">
      <header class="ilog__head">
        <span class="badge badge--<?= $r['ok'] ? 'public' : 'err' ?>"><?= $r['ok'] ? 'הצליח' : 'נכשל' ?></span>
        <span><?= $esc(IMPORT_LOG_KIND_HE[$r['kind']] ?? $r['kind']) ?></span>
        <span class="muted small" dir="ltr"><?= $esc(str_replace('T', ' ', substr($r['at'], 5, 11))) ?></span>
        <span class="muted small"><?= $esc($r['username'] ?? '—') ?></span>
      </header>
      <strong><?= $esc($r['title'] ?: ($r['ok'] ? '(בלי שם)' : 'ההבאה נכשלה — ' . $r['host'])) ?></strong>
      <?php if (!$r['ok']): ?><p class="note note--err small"><?= $esc($r['error']) ?></p><?php endif; ?>
      <p class="ilog__links">
        <a href="<?= $esc($r['source_url']) ?>" target="_blank" rel="noopener nofollow">↗ המקור</a>
        <span class="muted small scout-item__url" dir="ltr"><?= $esc(mb_substr($r['source_url'], 0, 100)) ?></span>
        <?php if ($r['recipe_id']): ?>· <span class="muted small">מתכון #<?= (int) $r['recipe_id'] ?></span><?php endif; ?>
      </p>
      <details><summary class="link">הטקסט המלא — מה היה בדף ומה חולץ</summary><pre class="ilog__text" dir="auto"><?= $esc($r['text']) ?></pre></details>
    </article>
  <?php endforeach; ?>
  </div>
  <p><a class="btn" href="<?= $qs(['before' => end($rows)['id']]) ?>">ישנים יותר ›</a></p>
  <?php endif; ?>
</section>
</main>
</body>
</html>
<?php
    exit;
}

$filters = array_intersect_key($_GET, array_flip(['level', 'action', 'user', 'q', 'request_id', 'since', 'before']));
$limit   = (int) ($_GET['limit'] ?? 200);
$rows    = listLog($filters, $limit);
logEvent('info', 'log-view', 'צפייה ביומן דרך טוקן "' . $token['label'] . '"',
         ['token_id' => (int) $token['id'], 'format' => $format, 'rows' => count($rows)] + logSafeInput($filters));

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'rows' => $rows, 'stats' => logStats()], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($format === 'text') {
    header('Content-Type: text/plain; charset=utf-8');
    echo logAsText($rows);
    exit;
}

$stats = logStats();
$self  = './logs.php?token=' . $esc($raw);
$qs    = fn(array $extra) => $self . '&' . http_build_query(array_filter($filters + $extra, fn($v) => $v !== '' && $v !== null));
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>יומן · אפליקציית מתכונים</title>
<meta name="theme-color" content="#0f8a4f">
<link rel="icon" href="./assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="./assets/css/app.css?v=2026-10-09h">
</head>
<body>
<header class="bar">
  <span class="bar__title">יומן — <?= $esc($token['label']) ?></span>
  <span class="muted small">תקף עד <?= $esc(str_replace('T', ' ', substr($token['expires_at'], 0, 16))) ?> UTC</span>
</header>
<main class="stage">
<section class="card settings--wide logview">
  <p class="muted">
    <?= (int) $stats['rows'] ?> שורות מאז <?= $esc($stats['oldest'] ? substr($stats['oldest'], 0, 10) : '—') ?> ·
    ב-24 השעות האחרונות: <?= (int) $stats['problems_24h'] ?> אזהרות, <?= (int) $stats['errors_24h'] ?> שגיאות ·
    <a href="<?= $qs(['format' => 'text', 'limit' => 500]) ?>">טקסט</a> ·
    <a href="<?= $qs(['format' => 'json', 'limit' => 500]) ?>">JSON</a> ·
    <a href="./logs.php?token=<?= $esc($raw) ?>&amp;view=import">יומן ייבוא</a>
  </p>
  <form class="logfilter" method="get">
    <input type="hidden" name="token" value="<?= $esc($raw) ?>">
    <select name="level" aria-label="רמה">
      <option value="">כל הרמות</option>
      <option value="warn" <?= ($filters['level'] ?? '') === 'warn' ? 'selected' : '' ?>>בעיות (warn + error)</option>
      <option value="error" <?= ($filters['level'] ?? '') === 'error' ? 'selected' : '' ?>>שגיאות בלבד</option>
    </select>
    <select name="action" aria-label="פעולה">
      <option value="">כל הפעולות</option>
      <?php foreach ($stats['actions'] as $a): ?>
        <option value="<?= $esc($a) ?>" <?= ($filters['action'] ?? '') === $a ? 'selected' : '' ?>><?= $esc($a) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="user" aria-label="משתמש">
      <option value="">כל המשתמשים</option>
      <?php foreach ($stats['users'] as $u): ?>
        <option value="<?= $esc($u) ?>" <?= ($filters['user'] ?? '') === $u ? 'selected' : '' ?>><?= $esc($u) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= $esc($filters['q'] ?? '') ?>" placeholder="חיפוש בהודעה / בפרטים">
    <button class="btn btn--primary" type="submit">סנן</button>
  </form>
  <?php if (!$rows): ?>
    <p class="muted">אין שורות שתואמות.</p>
  <?php else: ?>
  <div class="logtable-wrap"><table class="logtable">
    <thead><tr><th>זמן</th><th>רמה</th><th>מי</th><th>פעולה</th><th>הודעה / פרטים</th><th>ms</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="log--<?= $esc($r['level']) ?>">
        <td class="mono" dir="ltr"><?= $esc(str_replace('T', ' ', substr($r['at'], 0, 19))) ?></td>
        <td><span class="badge badge--<?= $r['level'] === 'error' ? 'err' : ($r['level'] === 'warn' ? 'warn' : 'public') ?>"><?= $esc($r['level']) ?></span></td>
        <td><?= $esc($r['username'] ?? '—') ?></td>
        <td class="mono" dir="ltr"><a href="<?= $qs(['request_id' => $r['request_id']]) ?>" title="כל השורות של הבקשה"><?= $esc($r['action']) ?></a></td>
        <td><?= $esc($r['message']) ?>
          <?php if ($r['meta']): ?><code class="mono small" dir="ltr"><?= $esc(json_encode($r['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></code><?php endif; ?>
        </td>
        <td class="mono" dir="ltr"><?= $r['duration_ms'] !== null ? (int) $r['duration_ms'] : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p><a class="btn" href="<?= $qs(['before' => end($rows)['id']]) ?>">שורות ישנות יותר ›</a></p>
  <?php endif; ?>
</section>
</main>
</body>
</html>
