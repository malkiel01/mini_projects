<?php
/**
 * שורת אירוע בדשבורד
 * includes/group_card.php
 *
 * מצפה למשתנה $group מהלולאה בדשבורד. השורה כולה היא קישור לאירוע;
 * מחיקה (למנהל) או עזיבה (למשתתף) — כפתור אייקון בקצה.
 */

$isClosed  = (($group['status'] ?? 'active') === 'closed');
$openItems = (int)($group['open_items'] ?? 0);
$hasDate   = !empty($group['event_date']);
$daysLeft  = $hasDate ? (int)floor((strtotime($group['event_date']) - strtotime('today')) / 86400) : null;

// "אפר׳" — חודש קצר בעברית לריבוע התאריך
$monthsHe = ['ינו׳', 'פבר׳', 'מרץ', 'אפר׳', 'מאי', 'יוני', 'יולי', 'אוג׳', 'ספט׳', 'אוק׳', 'נוב׳', 'דצמ׳'];

if ($group['participation_type'] === 'shares') {
    $myShare = (int)$group['participation_value'] . ' נפשות';
} elseif ($group['participation_type'] === 'percentage') {
    $myShare = rtrim(rtrim((string)$group['participation_value'], '0'), '.') . '%';
} else {
    $myShare = '₪' . number_format($group['participation_value'], 0);
}
?>
<li class="event-row<?php echo $isClosed ? ' closed' : ''; ?>">
    <a class="row" href="group.php?id=<?php echo (int)$group['id']; ?>">
        <?php if ($hasDate): ?>
        <span class="date-ic<?php echo (!$isClosed && $daysLeft !== null && $daysLeft >= 0 && $daysLeft <= 7) ? ' soon' : ''; ?>" aria-hidden="true">
            <b><?php echo date('j', strtotime($group['event_date'])); ?></b>
            <small><?php echo $monthsHe[(int)date('n', strtotime($group['event_date'])) - 1]; ?></small>
        </span>
        <?php else: ?>
        <span class="ic" aria-hidden="true"><i class="fas fa-calendar"></i></span>
        <?php endif; ?>

        <span class="body">
            <span class="l1">
                <span><?php echo htmlspecialchars($group['name']); ?></span>
            </span>
            <span class="l2">
                <?php if (!empty($group['event_location'])): ?>
                    <?php echo htmlspecialchars($group['event_location']); ?><i class="sep" aria-hidden="true">·</i>
                <?php endif; ?>
                <?php echo (int)$group['member_count']; ?> משתתפים<i class="sep" aria-hidden="true">·</i>₪<?php echo number_format($group['total_amount'] ?? 0, 0); ?>
            </span>
            <span class="chips">
                <?php if ($group['is_owner']): ?>
                <span class="chip brand"><i class="fas fa-crown" aria-hidden="true"></i> מנהל</span>
                <?php else: ?>
                <span class="chip">מנהל: <?php echo htmlspecialchars($group['owner_name']); ?></span>
                <?php endif; ?>
                <span class="chip">החלק שלך: <?php echo $myShare; ?></span>
                <?php if (!$isClosed && $daysLeft !== null && $daysLeft >= 0 && $daysLeft <= 14): ?>
                <span class="chip <?php echo $daysLeft <= 7 ? 'warn' : 'brand'; ?>"><?php echo $daysLeft === 0 ? 'היום!' : ($daysLeft === 1 ? 'מחר' : "בעוד $daysLeft ימים"); ?></span>
                <?php endif; ?>
                <?php if ($isClosed): ?>
                <span class="chip"><i class="fas fa-lock" aria-hidden="true"></i> נסגר</span>
                <?php elseif ($openItems > 0): ?>
                <span class="chip warn"><?php echo $openItems; ?> פריטים פתוחים</span>
                <?php endif; ?>
            </span>
        </span>
        <span class="go" aria-hidden="true">‹</span>
    </a>
    <?php if ($group['is_owner']): ?>
    <?php // מנהל אינו יכול לעזוב את האירוע שלו, אבל כן למחוק אותו ?>
    <button class="icon-btn row-act" type="button" title="מחיקת האירוע" aria-label="מחיקת האירוע"
            onclick="deleteGroup(<?php echo (int)$group['id']; ?>,
                <?php echo htmlspecialchars(json_encode($group['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)">
        <i class="fas fa-trash-can" aria-hidden="true"></i>
    </button>
    <?php else: ?>
    <button class="icon-btn row-act" type="button" title="עזיבת האירוע" aria-label="עזיבת האירוע"
            onclick="leaveGroup(<?php echo (int)$group['id']; ?>)">
        <i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i>
    </button>
    <?php endif; ?>
</li>
