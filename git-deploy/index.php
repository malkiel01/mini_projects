<?php
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/auth.php';

Auth::require_();

$cfg = Config::load();
$cache = $cfg['repos_cache'] ?? ['fetched_at' => 0, 'data' => []];
$repos = $cache['data'] ?? [];
$paths = $cfg['repositories'] ?? [];

// Render an "initial empty" state if no cache yet - JS will fetch right away
$cacheAge = $cache['fetched_at'] ? (time() - $cache['fetched_at']) : null;
$csrf = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>git-deploy · ניהול פריסות</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <div class="brand">
            <span class="brand-icon">🚀</span>
            <span>git-deploy</span>
        </div>
        <nav class="topbar-nav">
            <button id="btn-refresh" class="btn btn-ghost" type="button">🔄 רענן רשימת ריפוז</button>
            <a href="settings.php" class="btn btn-ghost">⚙ הגדרות</a>
            <a href="logout.php" class="btn btn-ghost">יציאה</a>
        </nav>
    </div>
</header>

<main class="container">
    <div class="page-head">
        <h1>הריפוזיטוריז שלי</h1>
        <p class="muted small" id="cache-info">
            <?php if ($cacheAge !== null): ?>
                נשלף לפני <?= self_format_age($cacheAge) ?> · <?= count($repos) ?> ריפוז.
            <?php else: ?>
                טוען מ-GitHub...
            <?php endif; ?>
        </p>
    </div>

    <div id="repo-list" class="repo-list" data-csrf="<?= htmlspecialchars($csrf) ?>">
<?php if (empty($repos)): ?>
        <div class="empty">טוען ריפוזיטוריז...</div>
<?php else: ?>
    <?php foreach ($repos as $repo):
        $fn = $repo['full_name'];
        $repoPaths = $paths[$fn]['paths'] ?? [];
    ?>
        <div class="repo-card" data-repo="<?= htmlspecialchars($fn) ?>" data-default-branch="<?= htmlspecialchars($repo['default_branch']) ?>">
            <div class="repo-card-head">
                <div>
                    <h2><?= htmlspecialchars($repo['name']) ?>
                        <?php if ($repo['private']): ?><span class="tag tag-private">private</span><?php endif; ?>
                    </h2>
                    <p class="muted small"><?= htmlspecialchars($fn) ?> · default: <code><?= htmlspecialchars($repo['default_branch']) ?></code></p>
                    <?php if (!empty($repo['description'])): ?>
                        <p class="repo-desc"><?= htmlspecialchars($repo['description']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="repo-actions">
                    <button class="btn btn-secondary btn-add-path" type="button">➕ הוסף ניתוב</button>
                </div>
            </div>

            <div class="paths" data-paths-for="<?= htmlspecialchars($fn) ?>">
                <?php if (empty($repoPaths)): ?>
                    <div class="path-empty muted small">אין עדיין ניתובים שמורים. לחץ "הוסף ניתוב" כדי לפרוס לתיקייה בשרת.</div>
                <?php else: ?>
                    <?php foreach ($repoPaths as $p): ?>
                        <div class="path-row" data-path="<?= htmlspecialchars($p['path']) ?>" data-branch="<?= htmlspecialchars($p['branch']) ?>">
                            <div class="path-info">
                                <div class="path-line"><code dir="ltr"><?= htmlspecialchars($p['path']) ?></code></div>
                                <div class="path-meta muted small">
                                    <span>branch: <code><?= htmlspecialchars($p['branch']) ?></code></span>
                                    <?php if (!empty($p['last_commit_hash'])): ?>
                                        · <span>commit: <code><?= htmlspecialchars($p['last_commit_hash']) ?></code></span>
                                    <?php endif; ?>
                                    <?php if (!empty($p['last_pulled'])): ?>
                                        · <span>נמשך: <?= htmlspecialchars($p['last_pulled']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($p['last_commit_msg'])): ?>
                                    <div class="path-commit-msg"><?= htmlspecialchars($p['last_commit_msg']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="path-actions">
                                <button class="btn btn-primary btn-deploy" type="button">⬇ Pull</button>
                                <button class="btn btn-danger-ghost btn-remove-path" type="button" title="הסר מהזיכרון">✕</button>
                            </div>
                            <div class="deploy-output" hidden></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
    </div>
</main>

<!-- Modal: add path -->
<div id="modal-add-path" class="modal" hidden>
    <div class="modal-box">
        <h3>הוסף ניתוב חדש</h3>
        <p class="muted small">ל-<code id="add-path-repo"></code></p>
        <div class="field">
            <label>Branch</label>
            <select id="add-path-branch"></select>
        </div>
        <div class="field">
            <label>תיקיית יעד בשרת</label>
            <input type="text" id="add-path-target" dir="ltr" placeholder="/home2/mbeplusc/public_html/some-folder">
            <small>אם התיקייה לא קיימת תיווצר. אם כבר קיימת ויש בה <code>.git</code> - תתבצע משיכה.</small>
        </div>
        <div class="modal-actions">
            <button class="btn btn-ghost" id="add-path-cancel" type="button">ביטול</button>
            <button class="btn btn-primary" id="add-path-confirm" type="button">צור והפרס</button>
        </div>
        <div id="add-path-output" class="deploy-output" hidden></div>
    </div>
</div>

<script src="assets/app.js"></script>
</body>
</html>

<?php
function self_format_age($s) {
    if ($s < 60) return $s . ' שניות';
    if ($s < 3600) return floor($s/60) . ' דקות';
    if ($s < 86400) return floor($s/3600) . ' שעות';
    return floor($s/86400) . ' ימים';
}
?>
