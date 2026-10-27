<?php
/**
 * דף האירועים של המשתמש
 * dashboard.php
 */

require_once 'config.php';
require_once 'includes/auth_check.php';
require_once 'includes/schema.php';
require_once 'includes/notifications.php';
require_once 'includes/participation.php';
require_once 'includes/admin.php';
require_once 'includes/group_delete.php';
require_once 'includes/layout.php';

$pdo     = getDBConnection();
$user_id = $_SESSION['user_id'];

$featuresReady = eventFeaturesReady($pdo);
$deletedGroups = listDeletedGroups($pdo, $user_id);

// ============================================================
// פעולות AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    switch ($_POST['action']) {

        case 'createGroup':
            $name        = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $eventDate   = trim($_POST['event_date'] ?? '');
            $location    = trim($_POST['event_location'] ?? '');
            $type        = participationTypeFromRequest();
            $value       = participationValueFromRequest($type);

            if ($name === '') {
                echo json_encode(['success' => false, 'message' => 'יש להזין שם לאירוע']);
                exit;
            }
            if ($value <= 0) {
                echo json_encode(['success' => false, 'message' => $type === 'shares'
                    ? 'מספר הנפשות חייב להיות לפחות 1'
                    : 'ערך ההשתתפות חייב להיות חיובי']);
                exit;
            }
            if ($type === 'percentage' && $value > 100) {
                echo json_encode(['success' => false, 'message' => 'לא ניתן להגדיר יותר מ-100% השתתפות']);
                exit;
            }
            if ($eventDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
                echo json_encode(['success' => false, 'message' => 'תאריך לא תקין']);
                exit;
            }

            try {
                $pdo->beginTransaction();

                if ($featuresReady) {
                    $stmt = $pdo->prepare("
                        INSERT INTO purchase_groups (name, description, owner_id, event_date, event_location)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $name,
                        $description !== '' ? $description : null,
                        $user_id,
                        $eventDate !== '' ? $eventDate : null,
                        $location !== '' ? $location : null,
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO purchase_groups (name, description, owner_id) VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$name, $description !== '' ? $description : null, $user_id]);
                }

                $group_id = $pdo->lastInsertId();

                $stmt = $pdo->prepare("
                    INSERT INTO group_members
                        (group_id, user_id, nickname, email, participation_type, participation_value)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $group_id, $user_id, $_SESSION['name'], $_SESSION['email'], $type, $value,
                ]);

                $pdo->commit();
                echo json_encode(['success' => true, 'group_id' => (int)$group_id]);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("createGroup failed: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'יצירת האירוע נכשלה']);
            }
            exit;

        case 'deleteGroup':
            $result = softDeleteGroup($pdo, intval($_POST['group_id'] ?? 0), $user_id);
            echo json_encode([
                'success' => $result['ok'],
                'message' => $result['message'],
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'restoreGroup':
            $result = restoreGroup($pdo, intval($_POST['group_id'] ?? 0), $user_id);
            echo json_encode([
                'success' => $result['ok'],
                'message' => $result['message'],
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'purgeGroup':
            $result = purgeGroup(
                $pdo,
                intval($_POST['group_id'] ?? 0),
                $user_id,
                $_POST['confirm_name'] ?? ''
            );
            echo json_encode([
                'success' => $result['ok'],
                'message' => $result['message'],
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'leaveGroup':
            $group_id = intval($_POST['group_id'] ?? 0);

            $stmt = $pdo->prepare("SELECT owner_id FROM purchase_groups WHERE id = ?");
            $stmt->execute([$group_id]);
            if ((int)$stmt->fetchColumn() === (int)$user_id) {
                echo json_encode([
                    'success' => false,
                    'message' => 'מנהל האירוע אינו יכול לעזוב אותו',
                ]);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM group_purchases gp
                JOIN group_members gm ON gp.member_id = gm.id
                WHERE gm.group_id = ? AND gm.user_id = ?
            ");
            $stmt->execute([$group_id, $user_id]);

            if ($stmt->fetchColumn() > 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'לא ניתן לעזוב אירוע שרשומות על שמך בו קניות',
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE group_members SET is_active = 0 WHERE group_id = ? AND user_id = ?
                ");
                $left = $stmt->execute([$group_id, $user_id]);

                if ($left) {
                    notifyGroupOwner(
                        $pdo, $group_id, $user_id, 'member_left',
                        'משתתף עזב את האירוע',
                        sprintf(
                            '%s עזב את "%s"',
                            $_SESSION['name'] ?? 'משתתף',
                            groupNameById($pdo, $group_id)
                        )
                    );
                }

                echo json_encode(['success' => $left]);
            }
            exit;

        case 'respondInvitation':
            $invitation_id = intval($_POST['invitation_id'] ?? 0);
            $response      = $_POST['response'] ?? '';

            if (!in_array($response, ['accept', 'reject'], true)) {
                echo json_encode(['success' => false, 'message' => 'תגובה לא תקינה']);
                exit;
            }

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    SELECT * FROM group_invitations
                    WHERE id = ? AND email = ? AND status = 'pending'
                ");
                $stmt->execute([$invitation_id, $_SESSION['email']]);
                $invitation = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$invitation) {
                    throw new Exception('הזמנה לא נמצאה או כבר טופלה');
                }

                if ($response === 'accept') {
                    $stmt = $pdo->prepare("
                        SELECT id FROM group_members WHERE group_id = ? AND user_id = ?
                    ");
                    $stmt->execute([$invitation['group_id'], $user_id]);
                    $existing = $stmt->fetch();

                    if ($existing) {
                        $stmt = $pdo->prepare("
                            UPDATE group_members
                            SET is_active = 1, nickname = ?, email = ?,
                                participation_type = ?, participation_value = ?, joined_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $invitation['nickname'], $_SESSION['email'],
                            $invitation['participation_type'], $invitation['participation_value'],
                            $existing['id'],
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO group_members
                                (group_id, user_id, nickname, email, participation_type, participation_value)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $invitation['group_id'], $user_id,
                            $invitation['nickname'], $_SESSION['email'],
                            $invitation['participation_type'], $invitation['participation_value'],
                        ]);
                    }
                }

                $stmt = $pdo->prepare("
                    UPDATE group_invitations SET status = ?, responded_at = NOW() WHERE id = ?
                ");
                $stmt->execute([
                    $response === 'accept' ? 'accepted' : 'rejected',
                    $invitation_id,
                ]);

                $pdo->commit();

                // מנהל האירוע צריך לדעת מי אישר ומי דחה
                $accepted = ($response === 'accept');
                notifyGroupOwner(
                    $pdo, $invitation['group_id'], $user_id,
                    $accepted ? 'invitation_accepted' : 'invitation_rejected',
                    $accepted ? 'הצטרפות לאירוע אושרה' : 'הזמנה נדחתה',
                    sprintf(
                        '%s %s את ההזמנה ל"%s"',
                        $_SESSION['name'] ?? $invitation['nickname'],
                        $accepted ? 'אישר' : 'דחה',
                        groupNameById($pdo, $invitation['group_id'])
                    )
                );

                echo json_encode([
                    'success'  => true,
                    'group_id' => $response === 'accept' ? (int)$invitation['group_id'] : null,
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'פעולה לא מוכרת']);
            exit;
    }
}

// ============================================================
// שליפת נתונים
// ============================================================

// הסטטיסטיקות מחושבות ישירות במקום להישען על טבלת סיכום
// שעלולה להיות לא מסונכרנת.
$eventColumns = $featuresReady
    ? "pg.event_date, pg.event_location, pg.status,
       (SELECT COUNT(*) FROM shopping_items si
        WHERE si.group_id = pg.id AND si.status <> 'bought') AS open_items"
    : "NULL AS event_date, NULL AS event_location, 'active' AS status, 0 AS open_items";

$stmt = $pdo->prepare("
    SELECT
        pg.id, pg.name, pg.description, pg.owner_id, pg.created_at,
        $eventColumns,
        gm.nickname,
        gm.participation_type,
        gm.participation_value,
        u.name AS owner_name,
        (pg.owner_id = ?) AS is_owner,
        (SELECT COUNT(*) FROM group_members m2
         WHERE m2.group_id = pg.id AND m2.is_active = 1) AS member_count,
        (SELECT COUNT(*) FROM group_purchases p2
         WHERE p2.group_id = pg.id) AS purchase_count,
        (SELECT COALESCE(SUM(p3.amount), 0) FROM group_purchases p3
         WHERE p3.group_id = pg.id) AS total_amount
    FROM purchase_groups pg
    JOIN group_members gm ON pg.id = gm.group_id
    JOIN users u ON pg.owner_id = u.id
    WHERE gm.user_id = ? AND gm.is_active = 1 AND pg.is_active = 1
    ORDER BY pg.created_at DESC
");
$stmt->execute([$user_id, $user_id]);
$allGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// אירועים פעילים לפני סגורים; בתוך הפעילים - הקרוב ביותר ראשון
$activeGroups = [];
$closedGroups = [];
foreach ($allGroups as $group) {
    if (($group['status'] ?? 'active') === 'closed') {
        $closedGroups[] = $group;
    } else {
        $activeGroups[] = $group;
    }
}

usort($activeGroups, function ($a, $b) {
    $aDate = $a['event_date'] ?? null;
    $bDate = $b['event_date'] ?? null;

    // אירועים עם תאריך קודמים לאלה שבלי
    if ($aDate && $bDate) return strcmp($aDate, $bDate);
    if ($aDate) return -1;
    if ($bDate) return 1;

    return strcmp($b['created_at'], $a['created_at']);
});

// --- הזמנות ממתינות ---
$stmt = $pdo->prepare("
    SELECT gi.*, pg.name AS group_name
    FROM group_invitations gi
    JOIN purchase_groups pg ON gi.group_id = pg.id
    WHERE gi.email = ? AND gi.status = 'pending' AND pg.is_active = 1
    ORDER BY gi.created_at DESC
");
$stmt->execute([$_SESSION['email']]);
$invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * מציג את תאריך האירוע יחסית להיום.
 */
function eventDateLabel($date) {
    if (empty($date)) {
        return null;
    }

    $days = (int)floor((strtotime($date) - strtotime('today')) / 86400);
    $formatted = date('d/m/Y', strtotime($date));

    if ($days === 0)  return "$formatted · היום!";
    if ($days === 1)  return "$formatted · מחר";
    if ($days > 1)    return "$formatted · עוד $days ימים";

    return $formatted;
}
?>
<?php
// הסיכום בראש המסך: מה פתוח עכשיו, בכל האירועים הפעילים
$openItemsTotal = 0;
$activeSpend    = 0;
foreach ($activeGroups as $g) {
    $openItemsTotal += (int)($g['open_items'] ?? 0);
    $activeSpend    += (float)($g['total_amount'] ?? 0);
}
$isAdmin = isSystemAdmin($pdo, $user_id);
?>
<!DOCTYPE html>
<html dir="rtl" lang="he">
<head>
    <?php layoutHead('האירועים שלי'); ?>
    <link rel="stylesheet" href="<?php echo asset('/css/dashboard.css'); ?>">
</head>
<body>
    <?php renderTopBar([
        'title'   => 'האירועים שלי',
        'sub'     => 'מה מביאים, מי קנה, ומי חייב למי',
    ]); ?>

    <main id="main" tabindex="-1">
        <?php if (!$featuresReady): ?>
            <?php renderMigrationNotice(); ?>
        <?php endif; ?>

        <section class="summary" aria-label="סיכום">
            <dl class="cells">
                <div><dt>אירועים פעילים</dt><dd><?php echo count($activeGroups); ?></dd></div>
                <div><dt>פריטים פתוחים</dt><dd class="<?php echo $openItemsTotal > 0 ? 'warn' : ''; ?>"><?php echo $openItemsTotal; ?></dd></div>
                <div><dt>הוצאות באירועים</dt><dd>₪<?php echo number_format($activeSpend, 0); ?></dd></div>
            </dl>
        </section>

        <div class="toolbar">
            <button class="btn primary grow" type="button" onclick="showCreateGroupModal()">
                <i class="fas fa-plus" aria-hidden="true"></i> אירוע חדש
            </button>
        </div>

        <?php if (count($invitations) > 0): ?>
        <h2 class="sec-title"><i class="fas fa-envelope-open-text" aria-hidden="true"></i> הזמנות שמחכות לך
            <span class="count"><?php echo count($invitations); ?></span></h2>
        <ul class="list">
            <?php foreach ($invitations as $invitation): ?>
            <li class="row invite-row">
                <span class="ic warn" aria-hidden="true"><i class="fas fa-envelope"></i></span>
                <span class="body">
                    <span class="l1"><span><?php echo htmlspecialchars($invitation['group_name']); ?></span></span>
                    <span class="l2">
                        בתור <?php echo htmlspecialchars($invitation['nickname']); ?><i class="sep" aria-hidden="true">·</i>
                        <?php if ($invitation['participation_type'] === 'percentage'): ?>
                            <?php echo $invitation['participation_value']; ?>%
                        <?php elseif ($invitation['participation_type'] === 'shares'): ?>
                            <?php echo (int)$invitation['participation_value']; ?> נפשות
                        <?php else: ?>
                            ₪<?php echo number_format($invitation['participation_value'], 2); ?>
                        <?php endif; ?>
                    </span>
                </span>
                <span class="end">
                    <button class="btn sm primary" type="button" onclick="respondInvitation(<?php echo (int)$invitation['id']; ?>, 'accept')">
                        <i class="fas fa-check" aria-hidden="true"></i> הצטרפות
                    </button>
                    <button class="icon-btn danger" type="button" aria-label="דחייה" title="דחייה"
                            onclick="respondInvitation(<?php echo (int)$invitation['id']; ?>, 'reject')">
                        <i class="fas fa-xmark" aria-hidden="true"></i>
                    </button>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <h2 class="sec-title"><i class="fas fa-calendar-day" aria-hidden="true"></i> אירועים פעילים
            <span class="count"><?php echo count($activeGroups); ?></span></h2>
        <?php if (count($activeGroups) === 0): ?>
        <p class="empty-box">אין לך עדיין אירועים פעילים. "+ אירוע חדש" כדי להתחיל, או חכו להזמנה.</p>
        <?php else: ?>
        <ul class="list">
            <?php foreach ($activeGroups as $group): ?>
                <?php include 'includes/group_card.php'; ?>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if (count($closedGroups) > 0): ?>
        <h2 class="sec-title"><i class="fas fa-box-archive" aria-hidden="true"></i> אירועים שנסגרו
            <span class="count"><?php echo count($closedGroups); ?></span></h2>
        <ul class="list closed-list">
            <?php foreach ($closedGroups as $group): ?>
                <?php include 'includes/group_card.php'; ?>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if (count($deletedGroups) > 0): ?>
        <h2 class="sec-title"><i class="fas fa-trash-can" aria-hidden="true"></i> אירועים שמחקת
            <span class="count"><?php echo count($deletedGroups); ?></span></h2>
        <p class="hint">לא מוצגים לאף משתתף. הנתונים שמורים — אפשר לשחזר, או למחוק לצמיתות.</p>
        <ul class="list">
            <?php foreach ($deletedGroups as $deleted): ?>
            <li class="row">
                <span class="ic muted" aria-hidden="true"><i class="fas fa-trash-can"></i></span>
                <span class="body">
                    <span class="l1"><span><?php echo htmlspecialchars($deleted['name']); ?></span></span>
                    <span class="l2"><?php echo (int)$deleted['member_count']; ?> משתתפים<i class="sep" aria-hidden="true">·</i><?php echo (int)$deleted['purchase_count']; ?> קניות</span>
                </span>
                <span class="end">
                    <button class="btn sm ghost" type="button" onclick="restoreGroup(<?php echo (int)$deleted['id']; ?>)">
                        <i class="fas fa-rotate-left" aria-hidden="true"></i> שחזור
                    </button>
                    <button class="icon-btn danger" type="button" aria-label="מחיקה לצמיתות" title="מחיקה לצמיתות"
                            onclick="purgeGroup(<?php echo (int)$deleted['id']; ?>,
                                <?php echo htmlspecialchars(json_encode($deleted['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)">
                        <i class="fas fa-trash" aria-hidden="true"></i>
                    </button>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <h2 class="sec-title"><i class="fas fa-toolbox" aria-hidden="true"></i> עוד</h2>
        <div class="tiles">
            <a class="tile" href="contacts.php"><i class="fas fa-address-book" aria-hidden="true"></i> אנשי קשר</a>
            <a class="tile" href="offsets.php"><i class="fas fa-scale-balanced" aria-hidden="true"></i> קיזוזים בין אירועים</a>
            <?php if ($isAdmin): ?>
            <a class="tile" href="admin.php"><i class="fas fa-shield-halved" aria-hidden="true"></i> ניהול המערכת</a>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal ליצירת אירוע -->
    <div id="createGroupModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>יצירת אירוע חדש</h2>
                <span class="close" onclick="closeCreateGroupModal()">&times;</span>
            </div>
            <form id="createGroupForm">
                <div class="form-group">
                    <label for="groupName">שם האירוע:</label>
                    <input type="text" id="groupName" placeholder="לדוגמה: ראש השנה אצל סבתא" required>
                </div>
                <div class="form-group">
                    <label for="groupDescription">תיאור (אופציונלי):</label>
                    <textarea id="groupDescription" rows="2"></textarea>
                </div>
                <?php if ($featuresReady): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="groupEventDate">תאריך:</label>
                        <input type="date" id="groupEventDate">
                    </div>
                    <div class="form-group">
                        <label for="groupEventLocation">מיקום:</label>
                        <input type="text" id="groupEventLocation" placeholder="אצל סבתא">
                    </div>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>איך מתחלקים?</label>
                    <div class="type-picker">
                        <input type="radio" name="ownerParticipationType" id="ownerType_shares" value="shares" checked onchange="toggleOwnerParticipationType()">
                        <label for="ownerType_shares"><i class="fas fa-users"></i><span>נפשות</span></label>
                        <input type="radio" name="ownerParticipationType" id="ownerType_percentage" value="percentage" onchange="toggleOwnerParticipationType()">
                        <label for="ownerType_percentage"><i class="fas fa-percentage"></i><span>אחוז</span></label>
                        <input type="radio" name="ownerParticipationType" id="ownerType_fixed" value="fixed" onchange="toggleOwnerParticipationType()">
                        <label for="ownerType_fixed"><i class="fas fa-shekel-sign"></i><span>סכום קבוע</span></label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="ownerParticipationValue" id="ownerValueLabel">כמה נפשות?</label>
                    <div class="input-with-suffix">
                        <input type="number" id="ownerParticipationValue" step="0.01" min="0.01" required>
                        <span id="ownerValueSuffix">%</span>
                    </div>
                    <small class="form-hint">
                        אפשר לשנות בהמשך, ויש כפתור "חלוקה שווה" בתוך האירוע
                    </small>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-plus"></i> צור אירוע
                    </button>
                    <button type="button" class="btn-secondary" onclick="closeCreateGroupModal()">
                        ביטול
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        window.APP_CONFIG = {
            csrfToken:     <?php echo json_encode($_SESSION['csrf_token']); ?>,
            userId:        <?php echo (int)$user_id; ?>,
            basePath:      <?php echo json_encode(APP_BASE_PATH); ?>,
            featuresReady: <?php echo $featuresReady ? 'true' : 'false'; ?>
        };
    </script>
    <script src="<?php echo asset('/js/notification-system.js'); ?>"></script>
    <script src="<?php echo asset('/js/dashboard.js'); ?>"></script>
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
