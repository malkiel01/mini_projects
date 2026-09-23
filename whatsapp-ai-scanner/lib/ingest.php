<?php
/**
 * קליטת הודעות מהגשר.
 *
 * הגשר (אפליקציית האנדרואיד) קורא את הוואטסאפ במכשיר ושולח לכאן אצווה
 * של הודעות. תפקיד הקובץ הזה הוא לאחסן אותן — מוצפנות, ובלי כפילויות.
 *
 * ‏ext_id הוא מזהה יציב שהגשר מחשב לכל הודעה (גיבוב של חשבון+צ'אט+זמן+
 * טקסט). סריקה חוזרת שולחת שוב הודעות שכבר נשלחו, ו-INSERT OR IGNORE
 * על מפתח (account_id, ext_id) בולע אותן בשקט. כך "לסרוק שוב" הוא
 * תמיד בטוח ואינו מכפיל.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';

/**
 * קולט אצווה. מחזיר [נקלטו, דילגו].
 *
 * @param array $rows כל שורה: ext_id, chat_name, sender, direction, body, sent_at
 */
function ingestBatch(int $accountId, array $rows): array {
    $pdo = db();
    $st = $pdo->prepare(
        'INSERT OR IGNORE INTO messages
            (account_id, ext_id, chat_name_enc, sender_enc, direction, body_enc, sent_at, ingested_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $now = time();
    $inserted = 0;
    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            $extId = trim((string) ($r['ext_id'] ?? ''));
            $body  = (string) ($r['body'] ?? '');
            if ($extId === '' || $body === '') continue;   // הודעה בלי זהות או בלי תוכן אינה נשמרת

            $direction = ($r['direction'] ?? 'in') === 'out' ? 'out' : 'in';
            $sentAt    = (int) ($r['sent_at'] ?? 0);

            $st->execute([
                $accountId,
                mb_substr($extId, 0, 190),
                encryptSecret(mb_substr((string) ($r['chat_name'] ?? ''), 0, 200)),
                encryptSecret(mb_substr((string) ($r['sender'] ?? ''), 0, 200)),
                $direction,
                encryptSecret(mb_substr($body, 0, 20000)),
                $sentAt,
                $now,
            ]);
            $inserted += $st->rowCount();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['ingested' => $inserted, 'skipped' => count($rows) - $inserted];
}
