<?php
/**
 * מה מנהל המערכת רואה (סקירה, 2026-10-20 — שלב 10).
 *
 *   privacy.html מבטיח: "מנהל המערכת רואה מספרים בלבד — לא כתובות ולא היסטוריה". בפועל פאנל הניהול הציג
 *   את יומן הכתובות של כל ילד (user.php, ‏audit.php), את מקום ההתרעות (alerts.php) ורישומי אבחון עם
 *   כתובות (health.php). מכאן:
 *     • ילד בחשבון משפחה (account_id > 0) — רק ההורים שלו רואים כתובות והיסטוריה. בפאנל — מספרים.
 *     • משתמש ישן בלי חשבון משפחה — מנהל המערכת הוא שמנהל אותו (אישר אותו, קבע לו כללים), ולכן רואה.
 */

declare(strict_types=1);

/** האם מנהל המערכת רשאי לראות כתובות והיסטוריה של המשתמש הזה. */
function adminSeesActivity(?array $user): bool {
    return (int) ($user['account_id'] ?? 0) <= 0;
}

/** ביטוי SQL לבדיקה זהה בשאילתה — ‏$alias הוא הטבלה של users. */
function adminSeesActivitySql(string $alias = 'u'): string {
    return "COALESCE($alias.account_id, 0) <= 0";
}

/** כתובות מתוך טקסט חופשי (רישום אבחון) — מוחלפות, כדי שהאבחון יישאר בלי מה שהילד פתח. */
function redactUrls(string $text): string {
    return (string) preg_replace('~\b(?:https?|wss?|ftp|intent|file|content|data|blob)://[^\s"\'<>]+~i', '[כתובת]', $text);
}
