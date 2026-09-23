<?php
/**
 * קליטת קבצי מדיה מהגשר, ואינדוקס תוכנם.
 *
 * הגישה: אינדקס בבליעה. כל קובץ חדש (PDF/תמונה) נשלח למודל פעם אחת,
 * הטקסט שחולץ ממנו — סכומים, תאריכים, שמות, מספרי אסמכתא — נשמר כהודעה
 * רגילה (מוצפנת), וכך ניתן לחפש בו בשאלות בדיוק כמו בהודעת טקסט.
 *
 * ‏dedup לפי גיבוב הקובץ: אותו קובץ לא מחולץ פעמיים ולא מחייב שוב את
 * המודל. הקובץ עצמו אינו נשמר בשרת — רק הטקסט שחולץ והגיבוב.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ingest.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/errors.php';

/** סוגי הקבצים שאנחנו יודעים לחלץ מהם כרגע. וידאו/אודיו — בהמשך. */
const MEDIA_MIMES = [
    'application/pdf',
    'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif',
];

function mediaSupported(string $mime): bool {
    return in_array(strtolower($mime), MEDIA_MIMES, true);
}

/**
 * קולט קובץ אחד: מחלץ, שומר כהודעה, ומסמן כמעובד.
 * מחזיר ['status' => processed|skipped|unsupported, ...].
 */
function ingestMediaFile(int $accountId, string $filename, string $mime,
                         string $bytes, int $sentAt = 0): array {
    $mime = strtolower(trim($mime));
    if (!mediaSupported($mime)) {
        return ['status' => 'unsupported', 'mime' => $mime];
    }

    $hash = hash('sha256', $bytes);

    // כבר עובד? מדלגים בלי לחייב את המודל.
    $st = db()->prepare('SELECT 1 FROM media WHERE account_id = ? AND hash = ?');
    $st->execute([$accountId, $hash]);
    if ($st->fetchColumn()) {
        return ['status' => 'skipped', 'reason' => 'already_processed'];
    }

    // חילוץ דרך המודל של הבעלים.
    $text = aiExtractFile(ownerAiConn(), $mime, $bytes);

    // שמירה כהודעה — כך זה נכנס לחיפוש הרגיל.
    $body = "📎 קובץ: $filename\n" . $text;
    $extId = 'media:' . substr($hash, 0, 24);
    ingestBatch($accountId, [[
        'ext_id'    => $extId,
        'chat_name' => $filename,
        'sender'    => 'קובץ',
        'direction' => 'in',
        'body'      => $body,
        'sent_at'   => $sentAt > 0 ? $sentAt : time(),
    ]]);

    db()->prepare('INSERT OR IGNORE INTO media (account_id, hash, filename, mime, processed_at)
                   VALUES (?, ?, ?, ?, ?)')
        ->execute([$accountId, $hash, mb_substr($filename, 0, 200), $mime, time()]);

    return ['status' => 'processed', 'chars' => mb_strlen($text)];
}
