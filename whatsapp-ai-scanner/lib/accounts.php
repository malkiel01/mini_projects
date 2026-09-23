<?php
/**
 * חשבונות הוואטסאפ שהבעלים הגדיר.
 *
 * חשבון אינו החיבור עצמו — הוא תווית שהבעלים נותן ("ביזנס", "פרטי"),
 * וכל הודעה נקלטת תחת חשבון אחד. ההפרדה מאפשרת לשאול שאלה על הביזנס
 * בלבד בלי שהפרטי ידלוף לתשובה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const ACCOUNT_KINDS = ['personal', 'business'];

function listAccounts(): array {
    return db()->query('SELECT a.*,
                               (SELECT COUNT(*) FROM messages m WHERE m.account_id = a.id) AS message_count
                          FROM accounts a ORDER BY a.created_at')->fetchAll();
}

function getAccount(int $id): ?array {
    $st = db()->prepare('SELECT * FROM accounts WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function createAccount(string $label, string $kind): array {
    $label = trim($label);
    if ($label === '' || mb_strlen($label) > 60) {
        throw new InvalidArgumentException('שם החשבון: 1–60 תווים');
    }
    if (!in_array($kind, ACCOUNT_KINDS, true)) {
        throw new InvalidArgumentException('סוג חשבון לא מוכר');
    }
    $st = db()->prepare('INSERT INTO accounts (label, kind, created_at) VALUES (?, ?, ?)');
    $st->execute([$label, $kind, time()]);
    return getAccount((int) db()->lastInsertId());
}

function deleteAccount(int $id): void {
    // המחיקה מפילה גם את ההודעות (ON DELETE CASCADE) — הבעלים מבקש
    // למחוק חשבון בדיוק כשהוא רוצה שההיסטוריה שלו לא תישאר בשרת.
    $st = db()->prepare('DELETE FROM accounts WHERE id = ?');
    $st->execute([$id]);
}
