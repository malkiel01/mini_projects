<?php
/**
 * דף האירוע
 * group.php
 */

require_once 'config.php';
require_once 'includes/auth_check.php';
require_once 'includes/schema.php';

$pdo     = getDBConnection();
$user_id = $_SESSION['user_id'];

$group_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($group_id <= 0) {
    header('Location: dashboard.php');
    exit;
}

$featuresReady = eventFeaturesReady($pdo);

// --- הרשאות: חייב להיות חבר פעיל בקבוצה פעילה ---
$stmt = $pdo->prepare("
    SELECT pg.*,
           gm.id AS member_id,
           gm.participation_type,
           gm.participation_value,
           (pg.owner_id = ?) AS is_owner
    FROM purchase_groups pg
    JOIN group_members gm ON pg.id = gm.group_id
    WHERE pg.id = ? AND gm.user_id = ? AND gm.is_active = 1 AND pg.is_active = 1
");
$stmt->execute([$user_id, $group_id, $user_id]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$group) {
    header('Location: dashboard.php');
    exit;
}

$is_owner     = (bool)$group['is_owner'];
$member_id    = (int)$group['member_id'];
$event_status = $featuresReady ? ($group['status'] ?? 'active') : 'active';
$is_closed    = ($event_status === 'closed');

// --- טיפול בבקשות AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if (!verifyCsrfToken()) {
        rejectRequest();
    }

    require_once 'includes/group_actions.php';
    handleGroupActions($pdo, $group_id, $user_id, $is_owner, $member_id, $event_status, $featuresReady);
    exit;
}

require_once 'includes/group_calculations.php';
require_once 'includes/group_modals.php';
require_once 'includes/contacts.php';
require_once 'includes/layout.php';
require_once 'includes/exchange.php';

// קנייה במטבע זר - רק אחרי מיגרציה 017
$currencyReady = $featuresReady && currencyColumnsReady($pdo);

// אנשי הקשר של המשתמש, לבורר שבמסך צירוף משתתף
$myContacts = $is_owner ? listContacts($pdo, $user_id) : [];

// --- משתתפים ---
// עמודות הטלפון נוספו במיגרציה 014, ולכן הן נבחרות רק אחרי
// שנבדק שהן קיימות. בלי הבדיקה כל המסך קורס בשרת שלא עודכן.
$phonesReady = phoneFieldsReady($pdo);
$phoneSelect = $phonesReady
    ? ", u.phone AS user_phone"
    : ", NULL AS user_phone, NULL AS phone";

$stmt = $pdo->prepare("
    SELECT gm.*,
           COALESCE(u.name, gm.nickname) AS user_name,
           u.profile_picture,
           COALESCE(u.email, gm.email) AS email
           $phoneSelect
    FROM group_members gm
    LEFT JOIN users u ON gm.user_id = u.id
    WHERE gm.group_id = ? AND gm.is_active = 1
    ORDER BY gm.joined_at, gm.id
");
$stmt->execute([$group_id]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// המספר של המשתמש עצמו, למסך "המספר שלי"
$myPhone = '';
foreach ($members as $member) {
    if ((int)$member['id'] === $member_id) {
        $myPhone = memberPhone($member);
        break;
    }
}

// --- סך ההשתתפות ---
$totalPercentage = 0.0;
$hasShareMembers = false;
foreach ($members as $member) {
    if ($member['participation_type'] === 'percentage') {
        $totalPercentage += (float)$member['participation_value'];
    } elseif ($member['participation_type'] === 'shares') {
        $hasShareMembers = true;
    }
}

$groupShareRate = isset($group['share_rate']) && $group['share_rate'] !== null
    ? (float)$group['share_rate']
    : null;

// בחלוקה יחסית משתתפי הנפשות סופגים את כל מה שנשאר, ולכן אין
// "אחוזים חסרים". כשנקבע תעריף לנפש הם משלמים סכום ידוע מראש,
// והאחוזים כן צריכים להשלים ל-100 בעצמם.
$usesShareRate        = ($groupShareRate !== null && $groupShareRate > 0);
$available_percentage = ($hasShareMembers && !$usesShareRate)
    ? 0.0
    : round(100 - $totalPercentage, 2);

// מה שכל משתתף נפשות משלם בפועל, לתצוגה בלבד
$shareDisplay = [];
foreach (normalizeShareMembers($members, $groupShareRate) as $normalized) {
    if (!isset($normalized['shares'])) {
        continue;
    }

    $shareDisplay[(int)$normalized['id']] = $usesShareRate
        ? ['type' => 'amount',  'value' => (float)$normalized['participation_value']]
        : ['type' => 'percent', 'value' => (float)$normalized['participation_value']];
}

// --- הזמנות ממתינות (רק למנהל) ---
$pending_invitations = [];
if ($is_owner) {
    $stmt = $pdo->prepare("
        SELECT gi.* FROM group_invitations gi
        WHERE gi.group_id = ? AND gi.status = 'pending'
        ORDER BY gi.created_at DESC
    ");
    $stmt->execute([$group_id]);
    $pending_invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- קניות ---
$stmt = $pdo->prepare("
    SELECT gp.*, gm.nickname, u.name AS purchaser_name
    FROM group_purchases gp
    JOIN group_members gm ON gp.member_id = gm.id
    LEFT JOIN users u ON gp.user_id = u.id
    WHERE gp.group_id = ?
    ORDER BY gp.created_at DESC
");
$stmt->execute([$group_id]);
$purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- החרגות לכל קנייה ---
$exclusionsByPurchase = [];
if ($featuresReady && count($purchases) > 0) {
    $stmt = $pdo->prepare("
        SELECT pe.purchase_id, pe.member_id, gm.nickname
        FROM purchase_exclusions pe
        JOIN group_members gm ON gm.id = pe.member_id
        JOIN group_purchases gp ON gp.id = pe.purchase_id
        WHERE gp.group_id = ?
    ");
    $stmt->execute([$group_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $exclusionsByPurchase[(int)$row['purchase_id']][] = $row;
    }
}

foreach ($purchases as &$purchase) {
    $purchase['excluded'] = $exclusionsByPurchase[(int)$purchase['id']] ?? [];
    $purchase['excluded_ids'] = array_map(function ($row) {
        return (int)$row['member_id'];
    }, $purchase['excluded']);
}
unset($purchase);

// --- רשימת הקניות ---
$shoppingItems = [];
if ($featuresReady) {
    $stmt = $pdo->prepare("
        SELECT si.*, gm.nickname AS assigned_nickname
        FROM shopping_items si
        LEFT JOIN group_members gm ON gm.id = si.assigned_member_id
        WHERE si.group_id = ?
        ORDER BY FIELD(si.status, 'needed', 'claimed', 'bought'), si.sort_order, si.id
    ");
    $stmt->execute([$group_id]);
    $shoppingItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- התחשבנויות ---
$settlements = [];
if ($featuresReady) {
    $stmt = $pdo->prepare("
        SELECT s.*,
               f.nickname AS from_nickname,
               t.nickname AS to_nickname
        FROM settlements s
        JOIN group_members f ON f.id = s.from_member_id
        JOIN group_members t ON t.id = s.to_member_id
        WHERE s.group_id = ?
        ORDER BY s.created_at DESC
    ");
    $stmt->execute([$group_id]);
    $settlements = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$balance      = calculateGroupBalance($members, $purchases, $settlements, $groupShareRate);
$summaryText  = buildSummaryText($group, $balance, $shoppingItems);

$openItemCount   = 0;
$boughtItemCount = 0;
foreach ($shoppingItems as $item) {
    if ($item['status'] === 'bought') {
        $boughtItemCount++;
    } else {
        $openItemCount++;
    }
}

// השיטה הפעילה כרגע, לפי מצב המשתתפים והתעריף
if ($usesShareRate) {
    $currentSplitMode = 'shares_rate';
} elseif ($hasShareMembers) {
    $currentSplitMode = 'shares';
} else {
    $currentSplitMode = 'percentage';
}

$canEdit = !$is_closed;
?>
<?php
// השורה של המשתמש בחישוב - כמה מגיע לו או כמה הוא חייב, לכרטיס הסיכום
$myOpen = null;
foreach ($balance['calculations'] ?? [] as $calc) {
    if ((int)($calc['member']['id'] ?? 0) === (int)$member_id) {
        $myOpen = round($calc['openBalance'], 2);
    }
}

$daysLeft = !empty($group['event_date'])
    ? (int)floor((strtotime($group['event_date']) - strtotime('today')) / 86400) : null;
$subParts = [];
if (!empty($group['event_date'])) {
    $when = date('d/m/Y', strtotime($group['event_date']));
    if (!$is_closed && $daysLeft === 0)     $when .= ' · היום!';
    elseif (!$is_closed && $daysLeft > 0)   $when .= " · עוד $daysLeft ימים";
    $subParts[] = $when;
}
if (!empty($group['event_location'])) {
    $subParts[] = $group['event_location'];
}

$topActions = [['onclick' => 'shareSummary()', 'icon' => 'fab fa-whatsapp', 'label' => 'שיתוף הסיכום בוואטסאפ']];
$moreActions = [['onclick' => 'window.print()', 'icon' => 'fas fa-print', 'label' => 'הדפסת סיכום']];
if ($is_owner && $featuresReady) {
    $topActions[]  = ['onclick' => 'showEventModal()', 'icon' => 'fas fa-pen', 'label' => 'פרטי האירוע'];
    $moreActions[] = ['onclick' => 'duplicateEvent()', 'icon' => 'fas fa-copy', 'label' => 'שכפול האירוע (לשנה הבאה)'];
    $moreActions[] = $is_closed
        ? ['onclick' => 'reopenEvent()', 'icon' => 'fas fa-lock-open', 'label' => 'פתיחה מחדש']
        : ['onclick' => 'closeEvent()', 'icon' => 'fas fa-flag-checkered', 'label' => 'סגירת האירוע'];
}
$topActions[] = ['menu' => $moreActions, 'label' => 'עוד פעולות'];
?>
<!DOCTYPE html>
<html dir="rtl" lang="he">
<head>
    <?php layoutHead($group['name']); ?>
    <link rel="stylesheet" href="<?php echo asset('/css/group.css'); ?>">
</head>
<body>
    <?php renderTopBar([
        'title'   => $group['name'],
        'sub'     => implode(' · ', $subParts),
        'back'    => 'dashboard.php',
        'actions' => $topActions,
    ]); ?>

    <main id="main" class="container" tabindex="-1">
        <?php if (!$featuresReady): ?>
            <?php renderMigrationNotice(); ?>
        <?php endif; ?>

        <!-- סיכום: מה המצב עכשיו -->
        <section class="summary event-summary<?php echo $is_closed ? ' closed' : ''; ?>" aria-label="סיכום האירוע">
            <?php if (!empty($group['description']) || $is_closed || $myOpen !== null): ?>
            <div class="line">
                <?php if (!empty($group['description'])): ?>
                <span class="muted desc"><?php echo htmlspecialchars($group['description']); ?></span>
                <?php endif; ?>
                <?php if ($is_closed): ?>
                <span class="chip"><i class="fas fa-lock" aria-hidden="true"></i> האירוע סגור</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($myOpen !== null && abs($myOpen) >= 0.01): ?>
            <div class="my-balance <?php echo $myOpen > 0 ? 'ok' : 'bad'; ?>">
                <i class="fas <?php echo $myOpen > 0 ? 'fa-arrow-down' : 'fa-arrow-up'; ?>" aria-hidden="true"></i>
                <span><?php echo $myOpen > 0 ? 'מגיע לך' : 'עליך להעביר'; ?></span>
                <b>₪<?php echo number_format(abs($myOpen), 2); ?></b>
                <button type="button" class="btn-link" onclick="showTab('calculations', document.getElementById('tab-calculations'))">למי?</button>
            </div>
            <?php elseif ($myOpen !== null && $balance['totalAmount'] > 0): ?>
            <div class="my-balance ok"><i class="fas fa-check" aria-hidden="true"></i><span>אצלך הכול מאוזן</span></div>
            <?php endif; ?>
            <dl class="cells">
                <div><dt>סך הוצאות</dt><dd>₪<?php echo number_format($balance['totalAmount'], 0); ?></dd></div>
                <div><dt>משתתפים</dt><dd><?php echo count($members); ?></dd></div>
                <?php if ($featuresReady): ?>
                <div><dt>פריטים פתוחים</dt><dd class="<?php echo $openItemCount > 0 ? 'warn' : ''; ?>"><?php echo $openItemCount; ?></dd></div>
                <?php endif; ?>
                <div><dt>העברות פתוחות</dt>
                    <dd class="<?php echo $balance['isBalanced'] ? 'ok' : 'bad'; ?>"><?php echo count($balance['transfers']); ?></dd></div>
            </dl>
        </section>

        <!-- לשוניות -->
        <div class="seg-tabs tabs" role="tablist">
            <?php if ($featuresReady): ?>
            <button class="tab active" role="tab" onclick="showTab('items', this)">
                <i class="fas fa-list-check" aria-hidden="true"></i>
                <span>מה צריך <small><?php echo $openItemCount > 0 ? $openItemCount : ''; ?></small></span>
            </button>
            <?php endif; ?>
            <button class="tab <?php echo $featuresReady ? '' : 'active'; ?>" role="tab" onclick="showTab('members', this)">
                <i class="fas fa-users" aria-hidden="true"></i>
                <span>משתתפים <small><?php echo count($members); ?></small></span>
            </button>
            <button class="tab" role="tab" onclick="showTab('purchases', this)">
                <i class="fas fa-receipt" aria-hidden="true"></i>
                <span>קניות <small><?php echo count($purchases) ?: ''; ?></small></span>
            </button>
            <button class="tab" id="tab-calculations" role="tab" onclick="showTab('calculations', this)">
                <i class="fas fa-scale-balanced" aria-hidden="true"></i>
                <span>מי חייב למי</span>
            </button>
        </div>

        <?php if ($featuresReady): ?>
        <!-- מסך "מה צריך" -->
        <div id="items" class="content">
            <div class="section-header">
                <h2>מה צריך להביא</h2>
                <?php if ($canEdit): ?>
                <button class="btn-icon" onclick="showItemModal()" title="פריט עם הערה" aria-label="פריט עם הערה">
                    <i class="fas fa-pen-to-square" aria-hidden="true"></i>
                </button>
                <?php endif; ?>
            </div>

            <?php if ($canEdit): ?>
            <!-- הוספה מהירה: שורה אחת, כמה פריטים בפסיקים. "יין 6 בקבוקים" = פריט וכמות -->
            <form class="quick-add" id="quickAddForm" autocomplete="off">
                <label class="sr-only" for="quickAddInput">מה עוד צריך?</label>
                <input type="text" id="quickAddInput" enterkeyhint="done"
                       placeholder="מה עוד צריך? למשל: חלב 2, לחם, ביצים 30">
                <button type="submit" class="btn primary"><i class="fas fa-plus" aria-hidden="true"></i> הוספה</button>
            </form>
            <?php endif; ?>

            <?php if (count($shoppingItems) === 0): ?>
            <div class="no-data">
                <i class="fas fa-clipboard-list"></i>
                <p>הרשימה ריקה</p>
                <p>הוסיפו את מה שצריך להביא לאירוע</p>
            </div>
            <?php else: ?>
            <div class="items-list">
                <?php foreach ($shoppingItems as $item): ?>
                <div class="item-row status-<?php echo htmlspecialchars($item['status']); ?>">
                    <span class="ic <?php echo ['needed' => 'muted', 'claimed' => 'warn', 'bought' => 'ok'][$item['status']] ?? 'muted'; ?>" aria-hidden="true">
                        <i class="fas <?php echo ['needed' => 'fa-circle', 'claimed' => 'fa-hand', 'bought' => 'fa-check'][$item['status']] ?? 'fa-circle'; ?>"></i>
                    </span>
                    <div class="item-main">
                        <div class="item-title">
                            <?php echo htmlspecialchars($item['title']); ?>
                            <?php if (!empty($item['quantity'])): ?>
                            <span class="item-quantity"><?php echo htmlspecialchars($item['quantity']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($item['notes'])): ?>
                        <div class="item-notes"><?php echo htmlspecialchars($item['notes']); ?></div>
                        <?php endif; ?>
                        <div class="item-status">
                            <?php if ($item['status'] === 'bought'): ?>
                                <i class="fas fa-check-circle"></i> נקנה
                                <?php if (!empty($item['assigned_nickname'])): ?>
                                    על ידי <?php echo htmlspecialchars($item['assigned_nickname']); ?>
                                <?php endif; ?>
                            <?php elseif ($item['status'] === 'claimed'): ?>
                                <i class="fas fa-hand-paper"></i>
                                <?php echo htmlspecialchars($item['assigned_nickname'] ?? 'מישהו'); ?> לוקח על עצמו
                            <?php else: ?>
                                <i class="fas fa-circle-notch"></i> עוד לא שובץ
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($canEdit): ?>
                    <div class="item-actions">
                        <?php if ($item['status'] === 'needed'): ?>
                        <button class="btn-small" onclick="setItemStatus(<?php echo (int)$item['id']; ?>, 'claimed')">
                            <i class="fas fa-hand-paper"></i> אני אביא
                        </button>
                        <?php elseif ($item['status'] === 'claimed'): ?>
                        <button class="btn-small primary"
                                onclick='buyItem(<?php echo (int)$item["id"]; ?>, <?php echo json_encode($item["title"], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            <i class="fas fa-receipt"></i> קניתי
                        </button>
                        <button class="btn-small" onclick="setItemStatus(<?php echo (int)$item['id']; ?>, 'needed')">
                            <i class="fas fa-undo"></i>
                        </button>
                        <?php else: ?>
                        <button class="btn-small" onclick="setItemStatus(<?php echo (int)$item['id']; ?>, 'needed')">
                            <i class="fas fa-undo"></i>
                        </button>
                        <?php endif; ?>
                        <button class="btn-icon" title="ערוך"
                                onclick='editItem(<?php echo json_encode([
                                    "id"       => (int)$item["id"],
                                    "title"    => $item["title"],
                                    "quantity" => $item["quantity"],
                                    "notes"    => $item["notes"],
                                ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn-icon-danger" title="מחק"
                                onclick="deleteItem(<?php echo (int)$item['id']; ?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($boughtItemCount > 0): ?>
            <p class="items-summary"><?php echo $boughtItemCount; ?> פריטים כבר נקנו</p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- מסך משתתפים -->
        <div id="members" class="content" style="<?php echo $featuresReady ? 'display: none;' : ''; ?>">
            <div class="section-header">
                <h2><?php echo $is_owner ? 'ניהול משתתפים' : 'משתתפי האירוע'; ?></h2>
                <?php if ($is_owner && $canEdit): ?>
                <div class="header-actions">
                    <button class="btn-gear" onclick="showSplitSettings()"
                            title="הגדרות חלוקה" aria-label="הגדרות חלוקה">
                        <i class="fas fa-cog"></i>
                    </button>
                    <button class="btn-add" onclick="showAddMemberModal()">
                        <i class="fas fa-user-plus"></i> הוסף משתתף
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <div class="members-grid">
                <?php foreach ($members as $member): ?>
                <div class="member-card">
                    <div class="member-avatar avatar" style="--h:<?php echo avatarHue($member['nickname']); ?>" aria-hidden="true">
                        <?php if (!empty($member['profile_picture'])): ?>
                            <img src="<?php echo htmlspecialchars($member['profile_picture']); ?>" alt="">
                        <?php else: ?>
                            <?php echo htmlspecialchars(avatarInitials($member['nickname'])); ?>
                        <?php endif; ?>
                    </div>
                    <div class="member-info">
                        <h3><?php echo htmlspecialchars($member['nickname']); ?></h3>
                        <p class="member-email"><?php echo htmlspecialchars($member['email']); ?></p>
                        <?php
                        // הטלפון מוצג למשתתפי האירוע בלבד, וזו כל הנקודה:
                        // בלעדיו אי אפשר לבקש תשלום או להעביר בביט
                        $cardPhone = $phonesReady ? memberPhone($member) : '';
                        $isMe      = ((int)$member['id'] === $member_id);
                        ?>
                        <?php if ($cardPhone !== ''): ?>
                        <p class="member-phone">
                            <i class="fas fa-phone"></i>
                            <?php echo htmlspecialchars(formatPhone($cardPhone)); ?>
                            <?php if ($isMe && $phonesReady): ?>
                            <button type="button" class="btn-link" onclick="openMyPhoneModal()">שינוי</button>
                            <?php endif; ?>
                        </p>
                        <?php elseif ($isMe && $phonesReady): ?>
                        <p class="member-phone missing">
                            <button type="button" class="btn-link" onclick="openMyPhoneModal()">
                                <i class="fas fa-phone"></i> הוסף את המספר שלך
                            </button>
                        </p>
                        <?php endif; ?>
                        <p class="member-participation">
                            <?php if ($member['participation_type'] === 'shares'): ?>
                                <span class="shares-badge">
                                    <i class="fas fa-users"></i>
                                    <?php echo (int)$member['participation_value']; ?> נפשות
                                </span>
                                <?php if (isset($shareDisplay[(int)$member['id']])): ?>
                                    <span class="share-equiv">
                                        <?php $sd = $shareDisplay[(int)$member['id']]; ?>
                                        <?php if ($sd['type'] === 'amount'): ?>
                                            ₪<?php echo number_format($sd['value'], 2); ?>
                                        <?php else: ?>
                                            ≈<?php echo round($sd['value'], 1); ?>%
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            <?php elseif ($member['participation_type'] === 'percentage'): ?>
                                <i class="fas fa-percentage"></i> <?php echo $member['participation_value']; ?>%
                            <?php else: ?>
                                <i class="fas fa-shekel-sign"></i> ₪<?php echo number_format($member['participation_value'], 2); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if ($is_owner && $canEdit): ?>
                    <div class="member-actions">
                        <button class="btn-edit" title="ערוך"
                                onclick="editMember(<?php echo (int)$member['id']; ?>, '<?php echo htmlspecialchars($member['participation_type'], ENT_QUOTES); ?>', <?php echo (float)$member['participation_value']; ?>, <?php echo htmlspecialchars(json_encode(formatPhone($cardPhone), JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)">
                            <i class="fas fa-edit"></i>
                        </button>
                        <?php // את המנהל אי אפשר להסיר מהאירוע שלו, אבל כן אפשר לערוך את חלקו ?>
                        <?php if ($member['user_id'] != $group['owner_id']): ?>
                        <button class="btn-remove" title="הסר"
                                onclick="removeMember(<?php echo (int)$member['id']; ?>)">
                            <i class="fas fa-times"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <?php if ($available_percentage > 0.01 && $totalPercentage > 0): ?>
                <div class="member-card warning">
                    <div class="member-avatar"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="member-info">
                        <h3>חסרים אחוזים להשלמה</h3>
                        <p class="member-status warning">
                            <i class="fas fa-info-circle"></i> סה"כ מוגדר: <?php echo round($totalPercentage, 2); ?>%
                        </p>
                        <p class="member-participation">
                            <i class="fas fa-percentage"></i> חסרים: <?php echo $available_percentage; ?>%
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <?php foreach ($pending_invitations as $invitation): ?>
                <div class="member-card pending">
                    <div class="member-avatar"><i class="fas fa-user-clock"></i></div>
                    <div class="member-info">
                        <h3><?php echo htmlspecialchars($invitation['nickname']); ?></h3>
                        <p class="member-email"><?php echo htmlspecialchars($invitation['email']); ?></p>
                        <p class="member-status pending">
                            <i class="fas fa-clock"></i> ממתין לאישור
                        </p>
                        <p class="member-participation">
                            <?php if ($invitation['participation_type'] === 'shares'): ?>
                                <span class="shares-badge"><i class="fas fa-users"></i> <?php echo (int)$invitation['participation_value']; ?> נפשות</span>
                            <?php elseif ($invitation['participation_type'] === 'percentage'): ?>
                                <i class="fas fa-percentage"></i> <?php echo $invitation['participation_value']; ?>%
                            <?php else: ?>
                                <i class="fas fa-shekel-sign"></i> ₪<?php echo number_format($invitation['participation_value'], 2); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="member-actions">
                        <button class="btn-edit" title="העתק קישור הצטרפות"
                                onclick="showInviteLink('<?php echo htmlspecialchars($invitation['token'], ENT_QUOTES); ?>')">
                            <i class="fas fa-link"></i>
                        </button>
                        <button class="btn-remove" title="בטל הזמנה"
                                onclick="cancelInvitation(<?php echo (int)$invitation['id']; ?>)">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- מסך קניות -->
        <div id="purchases" class="content" style="display: none;">
            <div class="section-header">
                <h2>קניות האירוע</h2>
                <?php if ($canEdit): ?>
                <button class="btn-add" onclick="showAddPurchaseModal()">
                    <i class="fas fa-plus"></i> הוסף קנייה
                </button>
                <?php endif; ?>
            </div>

            <?php if (count($purchases) === 0): ?>
            <div class="no-data">
                <i class="fas fa-receipt"></i>
                <p>עוד לא נרשמו קניות</p>
            </div>
            <?php else: ?>
            <div class="purchases-list">
                <?php foreach ($purchases as $purchase): ?>
                <?php $canEditThis = $canEdit && ($is_owner || $purchase['user_id'] == $user_id); ?>
                <div class="purchase-card">
                    <?php if ($purchase['image_path']): ?>
                    <button type="button" class="purchase-thumb" aria-label="הצגת הקבלה"
                            onclick="showImageModal(<?php echo htmlspecialchars(json_encode($purchase['image_path']), ENT_QUOTES); ?>)">
                        <img src="<?php echo htmlspecialchars($purchase['image_path']); ?>" alt="" loading="lazy">
                    </button>
                    <?php else: ?>
                    <span class="ic muted" aria-hidden="true"><i class="fas fa-receipt"></i></span>
                    <?php endif; ?>
                    <div class="purchase-body">
                        <div class="purchase-title">
                            <?php echo $purchase['description'] !== '' && $purchase['description'] !== null
                                ? htmlspecialchars(strtok($purchase['description'], "\n"))
                                : 'קנייה'; ?>
                        </div>
                        <div class="purchase-meta">
                            <?php echo htmlspecialchars($purchase['nickname']); ?><i class="sep" aria-hidden="true">·</i><?php echo date('d/m/Y', strtotime($purchase['created_at'])); ?>
                        </div>
                        <?php if ($fx = purchaseConversionLabel($purchase, false)): ?>
                        <div class="purchase-fx"><i class="fas fa-coins" aria-hidden="true"></i> <?php echo htmlspecialchars($fx); ?></div>
                        <?php endif; ?>
                        <?php if (count($purchase['excluded']) > 0): ?>
                        <div class="purchase-exclusions">
                            <i class="fas fa-user-slash" aria-hidden="true"></i>
                            בלי:
                            <?php
                            $names = array_map(function ($row) {
                                return htmlspecialchars($row['nickname']);
                            }, $purchase['excluded']);
                            echo implode(', ', $names);
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="purchase-amount">
                        ₪<?php echo number_format($purchase['amount'], 2); ?>
                        <?php if (!empty($purchase['currency'])): ?>
                        <small class="purchase-orig"><?php echo htmlspecialchars(formatMoney($purchase['original_amount'], $purchase['currency'])); ?></small>
                        <?php endif; ?>
                    </div>
                    <?php if ($canEditThis): ?>
                    <div class="purchase-actions">
                        <?php if ($featuresReady): ?>
                        <button class="btn-icon" title="עריכה" aria-label="עריכת הקנייה"
                                onclick='editPurchase(<?php echo json_encode([
                                    "id"          => (int)$purchase["id"],
                                    "amount"      => (float)$purchase["amount"],
                                    "description" => $purchase["description"],
                                    "member_id"   => (int)$purchase["member_id"],
                                    "excluded"    => $purchase["excluded_ids"],
                                    "currency"    => $purchase["currency"] ?? null,
                                    "original"    => isset($purchase["original_amount"]) ? (float)$purchase["original_amount"] : null,
                                    "rate"        => isset($purchase["exchange_rate"]) ? (float)$purchase["exchange_rate"] : null,
                                    "rate_date"   => $purchase["rate_date"] ?? null,
                                    "rate_source" => $purchase["rate_source"] ?? null,
                                ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            <i class="fas fa-pen" aria-hidden="true"></i>
                        </button>
                        <?php endif; ?>
                        <button class="btn-icon-danger" title="מחיקה" aria-label="מחיקת הקנייה"
                                onclick="deletePurchase(<?php echo (int)$purchase['id']; ?>)">
                            <i class="fas fa-trash" aria-hidden="true"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- מסך חישובים -->
        <div id="calculations" class="content" style="display: none;">
            <div class="section-header">
                <h2>חישוב וחלוקת עלויות</h2>
                <button class="btn-secondary" onclick="shareSummary()">
                    <i class="fab fa-whatsapp"></i> שתף
                </button>
            </div>
            <div class="calc-view">
            <?php echo renderCalculationsView($members, $balance, $featuresReady, $settlements, [
                'viewer_member_id' => $member_id,
                'is_owner'         => $is_owner,
                'group_name'       => $group['name'],
            ]); ?>
            </div>
        </div>
    </main>

    <!-- סיכום להדפסה: מוסתר במסך, והיחיד שמודפס (css/group.css, @media print) -->
    <section id="print-root" aria-hidden="true">
        <h1><?php echo htmlspecialchars($group['name']); ?></h1>
        <p class="print-sub">
            <?php echo htmlspecialchars(implode(' · ', $subParts)); ?>
            <?php echo $subParts ? ' · ' : ''; ?>הודפס <?php echo date('d/m/Y'); ?>
        </p>

        <h2>לפי משתתף</h2>
        <table>
            <thead><tr><th>משתתף</th><th>חלק</th><th>צריך לשלם</th><th>שילם</th><th>יתרה</th></tr></thead>
            <tbody>
            <?php foreach ($balance['calculations'] ?? [] as $calc): ?>
                <?php $open = round($calc['openBalance'], 2); ?>
                <tr>
                    <td><?php echo htmlspecialchars($calc['member']['nickname']); ?></td>
                    <td><?php
                        if (isset($calc['member']['shares'])) echo (int)$calc['member']['shares'] . ' נפשות';
                        elseif ($calc['member']['participation_type'] === 'percentage') echo round($calc['member']['participation_value'], 1) . '%';
                        else echo '₪' . number_format($calc['member']['participation_value'], 2);
                    ?></td>
                    <td>₪<?php echo number_format($calc['shouldPay'], 2); ?></td>
                    <td>₪<?php echo number_format($calc['actuallyPaid'], 2); ?></td>
                    <td><?php echo $open >= 0 ? 'מגיע ' : 'חייב '; ?>₪<?php echo number_format(abs($open), 2); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="3">סה"כ הוצאות</td><td colspan="2">₪<?php echo number_format($balance['totalAmount'], 2); ?></td></tr></tfoot>
        </table>

        <h2>העברות שנשארו</h2>
        <?php if (count($balance['transfers']) === 0): ?>
        <p>אין — הכול מאוזן.</p>
        <?php else: ?>
        <table>
            <thead><tr><th>#</th><th>מי מעביר</th><th>למי</th><th>סכום</th><th>✓</th></tr></thead>
            <tbody>
            <?php foreach ($balance['transfers'] as $i => $transfer): ?>
                <tr><td><?php echo $i + 1; ?></td><td><?php echo htmlspecialchars($transfer['from']); ?></td>
                    <td><?php echo htmlspecialchars($transfer['to']); ?></td>
                    <td>₪<?php echo number_format($transfer['amount'], 2); ?></td><td></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h2>קניות (<?php echo count($purchases); ?>)</h2>
        <table>
            <thead><tr><th>תאריך</th><th>מי קנה</th><th>מה</th><th>סכום</th></tr></thead>
            <tbody>
            <?php foreach ($purchases as $purchase): ?>
                <tr><td><?php echo date('d/m/Y', strtotime($purchase['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($purchase['nickname']); ?></td>
                    <td><?php echo htmlspecialchars((string)$purchase['description']); ?>
                        <?php if ($fx = purchaseConversionLabel($purchase)): ?><br><small><?php echo htmlspecialchars($fx); ?></small><?php endif; ?></td>
                    <td>₪<?php echo number_format($purchase['amount'], 2); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <!-- Modals -->
    <?php
    if ($is_owner) {
        renderAddMemberModal($available_percentage, $myContacts);
        renderEditMemberModal();
        renderSplitSettingsModal($currentSplitMode, $groupShareRate ?? 0);
    }
    if ($featuresReady) {
        renderEventModal($group);
        renderItemModal();
        renderEditPurchaseModal($members, $is_owner, $currencyReady);
        renderSettleModal();
        renderDebtTransferModal($members);
    }
    renderAddPurchaseModal($members, $is_owner, $member_id, $featuresReady, $currencyReady);
    renderImageModal();
    if ($phonesReady) {
        renderMyPhoneModal($myPhone);
    }
    ?>

    <script>
        window.APP_CONFIG = {
            groupId:             <?php echo (int)$group_id; ?>,
            isOwner:             <?php echo $is_owner ? 'true' : 'false'; ?>,
            isClosed:            <?php echo $is_closed ? 'true' : 'false'; ?>,
            featuresReady:       <?php echo $featuresReady ? 'true' : 'false'; ?>,
            availablePercentage: <?php echo $available_percentage; ?>,
            csrfToken:           <?php echo json_encode($_SESSION['csrf_token']); ?>,
            basePath:            <?php echo json_encode(APP_BASE_PATH); ?>,
            summaryText:         <?php echo json_encode($summaryText, JSON_UNESCAPED_UNICODE); ?>
        };
    </script>
    <script src="<?php echo asset('/js/contact-picker.js'); ?>"></script>
    <script src="<?php echo asset('/js/group.js'); ?>"></script>
    <script src="<?php echo asset('/js/push-subscribe.js'); ?>"></script>

    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker
                    .register('<?php echo APP_BASE_PATH; ?>/service-worker.js',
                              { scope: '<?php echo APP_BASE_PATH; ?>/' })
                    .catch(err => console.error('Service Worker registration failed:', err));
            });
        }
    </script>
</body>
</html>
