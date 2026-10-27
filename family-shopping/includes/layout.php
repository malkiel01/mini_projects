<?php
/**
 * המעטפת המשותפת לכל המסכים — שפת העיצוב של הכלים הדיגיטליים
 * includes/layout.php
 *
 * layoutHead()   התגיות המשותפות ב-<head>: viewport, אייקונים, Font Awesome
 *                ו-css/app.css (לפני ה-CSS של הדף).
 * renderTopBar() הכותרת הדביקה: חץ חזרה (רק כשיש לאן — לא במסך הראשי),
 *                כותרת ומשנה, כפתורי אייקון, והאות הראשונה של המשתמש.
 */

/** התגיות המשותפות ב-<head> */
function layoutHead($title) {
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <title><?php echo htmlspecialchars($title); ?> - <?php echo SITE_NAME; ?></title>
    <meta name="theme-color" content="#2d4a8a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="manifest" href="<?php echo APP_BASE_PATH; ?>/manifest.webmanifest">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo APP_BASE_PATH; ?>/images/icons/ios/32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo APP_BASE_PATH; ?>/images/icons/ios/180.png">
    <link rel="stylesheet" href="<?php echo asset('/assets/vendor/fontawesome/css/all.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('/css/app.css'); ?>">
    <script src="<?php echo asset('/js/app.js'); ?>" defer></script>
    <?php
}

/** גוון קבוע לשם (0–359), לצבע האווטאר */
function avatarHue($name) {
    return abs(crc32((string)$name)) % 360;
}

/** שתי אותיות ראשונות לאווטאר */
function avatarInitials($name) {
    $parts = preg_split('/\s+/u', trim((string)$name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $out .= mb_substr($part, 0, 1);
    }

    return $out !== '' ? $out : '?';
}

/**
 * הכותרת הדביקה.
 *
 * @param array $opts title, sub, back (כתובת), actions: [[href|onclick, icon, label] | [menu => [...], label]], me (bool)
 */
function renderTopBar(array $opts) {
    $name = $_SESSION['name'] ?? '';
    ?>
    <a class="skip" href="#main">דילוג לתוכן</a>
    <header class="top">
        <?php if (!empty($opts['back'])): ?>
        <a class="icon-btn" href="<?php echo htmlspecialchars($opts['back']); ?>" aria-label="חזרה">
            <i class="fas fa-arrow-right" aria-hidden="true"></i>
        </a>
        <?php endif; ?>
        <div class="titles">
            <h1><?php echo htmlspecialchars($opts['title']); ?></h1>
            <?php if (!empty($opts['sub'])): ?>
            <div class="sub"><?php echo htmlspecialchars($opts['sub']); ?></div>
            <?php endif; ?>
        </div>
        <?php foreach ($opts['actions'] ?? [] as $action): ?>
            <?php if (!empty($action['menu'])): ?>
            <?php // פעולות פחות שכיחות - בתפריט "עוד", כדי שהכותרת לא תיחתך ?>
            <div class="menu-wrap">
                <button type="button" class="icon-btn" aria-haspopup="menu" aria-expanded="false"
                        aria-label="<?php echo htmlspecialchars($action['label'] ?? 'עוד'); ?>" title="<?php echo htmlspecialchars($action['label'] ?? 'עוד'); ?>"
                        onclick="toggleTopMenu(this)">
                    <i class="fas fa-ellipsis-vertical" aria-hidden="true"></i>
                </button>
                <div class="top-menu" role="menu" hidden>
                    <?php foreach ($action['menu'] as $item): ?>
                    <button type="button" role="menuitem" onclick="<?php echo htmlspecialchars($item['onclick']); ?>">
                        <i class="<?php echo htmlspecialchars($item['icon']); ?>" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($item['label']); ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php elseif (!empty($action['href'])): ?>
            <a class="icon-btn" href="<?php echo htmlspecialchars($action['href']); ?>"
               title="<?php echo htmlspecialchars($action['label']); ?>" aria-label="<?php echo htmlspecialchars($action['label']); ?>">
                <i class="<?php echo htmlspecialchars($action['icon']); ?>" aria-hidden="true"></i>
            </a>
            <?php else: ?>
            <button type="button" class="icon-btn" onclick="<?php echo htmlspecialchars($action['onclick']); ?>"
                    title="<?php echo htmlspecialchars($action['label']); ?>" aria-label="<?php echo htmlspecialchars($action['label']); ?>">
                <i class="<?php echo htmlspecialchars($action['icon']); ?>" aria-hidden="true"></i>
            </button>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (($opts['me'] ?? true) && $name !== ''): ?>
        <span class="me" title="<?php echo htmlspecialchars($name); ?>" aria-label="<?php echo htmlspecialchars($name); ?>">
            <?php if (!empty($_SESSION['profile_picture'])): ?>
                <img src="<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="">
            <?php else: ?>
                <?php echo htmlspecialchars(mb_substr($name, 0, 1)); ?>
            <?php endif; ?>
        </span>
        <?php endif; ?>
    </header>
    <?php
}
