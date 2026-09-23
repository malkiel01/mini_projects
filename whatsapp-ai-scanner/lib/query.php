<?php
/**
 * שאלה ותשובה על ההודעות.
 *
 * הבעלים שואל בשפה חופשית ("מי התכתב איתי ב-16 בחודש?", "מי שלח אסמכתא
 * על תשלום מעל 500 ₪?"), והמערכת עונה על סמך ההודעות שנקלטו.
 *
 * מגבלת ההקשר היא הלב של הקובץ. היסטוריית וואטסאפ שלמה גדולה מכל חלון
 * הקשר של מודל, ולכן אי אפשר לשפוך הכול לתוך הפנייה. הצמצום נעשה בשני
 * צירים לפני הפנייה למודל:
 *
 *   1. חלון זמן — אם הבעלים ציין טווח תאריכים, נלקחות רק הודעות בתוכו.
 *   2. תקרת כמות — לכל היותר MAX_CONTEXT_MSGS הודעות, העדכניות שבחלון.
 *
 * זו גישת "חלון", לא חיפוש סמנטי על כל ההיסטוריה. חיפוש על פני שנים של
 * התכתבות דורש אינדקס וקטורי (embeddings/RAG) — זה נשאר פתוח, ומתועד
 * ב-README. עד אז, שאלה רחבה בלי טווח תאריכים תתבסס על ההודעות
 * העדכניות בלבד, והתשובה אומרת זאת.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/errors.php';

/** תקרת ההודעות שנשלחות למודל בפנייה אחת. שומר על עלות וזמן סבירים. */
const MAX_CONTEXT_MSGS = 400;

/**
 * שולף הודעות רלוונטיות לשאלה.
 *
 * @param int|null $accountId חשבון יחיד, או null לכל החשבונות
 * @param int|null $from      חותמת זמן תחילת הטווח, או null
 * @param int|null $to        חותמת זמן סוף הטווח, או null
 */
function collectMessages(?int $accountId, ?int $from, ?int $to): array {
    $where = [];
    $args  = [];
    if ($accountId !== null) { $where[] = 'account_id = ?'; $args[] = $accountId; }
    if ($from !== null)      { $where[] = 'sent_at >= ?';   $args[] = $from; }
    if ($to !== null)        { $where[] = 'sent_at <= ?';   $args[] = $to; }
    $clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // העדכניות בחלון קודם, כדי שהתקרה תחתוך את הישנות ולא את החדשות.
    $sql = "SELECT m.*, a.label AS account_label, a.kind AS account_kind
              FROM messages m JOIN accounts a ON a.id = m.account_id
              $clause
             ORDER BY m.sent_at DESC
             LIMIT " . (MAX_CONTEXT_MSGS + 1);

    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();

    $truncated = count($rows) > MAX_CONTEXT_MSGS;
    if ($truncated) array_pop($rows);

    // פענוח בזיכרון בלבד. חוזרים לסדר עולה כדי שהמודל יקרא כרונולוגית.
    $rows = array_reverse($rows);
    $msgs = [];
    foreach ($rows as $r) {
        $msgs[] = [
            'account'   => $r['account_label'],
            'kind'      => $r['account_kind'],
            'chat'      => decryptSecret($r['chat_name_enc']),
            'sender'    => decryptSecret($r['sender_enc']),
            'direction' => $r['direction'],
            'body'      => decryptSecret($r['body_enc']),
            'sent_at'   => (int) $r['sent_at'],
        ];
    }
    return ['messages' => $msgs, 'truncated' => $truncated];
}

/** בונה את בלוק ההקשר שנשלח למודל. */
function buildContext(array $messages): string {
    $lines = [];
    foreach ($messages as $m) {
        $when = $m['sent_at'] ? date('Y-m-d H:i', $m['sent_at']) : 'ללא תאריך';
        $who  = $m['direction'] === 'out' ? 'אני' : ($m['sender'] ?: 'אנשי קשר');
        $chat = $m['chat'] !== '' ? " [{$m['chat']}]" : '';
        $acct = "({$m['account']})";
        $lines[] = "{$when} {$acct}{$chat} {$who}: {$m['body']}";
    }
    return implode("\n", $lines);
}

/**
 * עונה על שאלה. $conn = ['provider','key','model'] כמו ב-ai.php.
 * ‏$transport מוזרק בבדיקות.
 */
function answerQuestion(array $conn, string $question, array $collected, ?callable $transport = null): string {
    $messages  = $collected['messages'];
    $truncated = $collected['truncated'];

    if (!$messages) {
        return 'אין הודעות שתואמות את החיפוש. ודא שהגשר סרק את החשבון, ושטווח התאריכים נכון.';
    }

    $system = implode("\n", [
        'אתה עוזר שעונה על שאלות לגבי היסטוריית הודעות וואטסאפ של המשתמש.',
        'ענה אך ורק על סמך ההודעות שנמסרו לך. אל תמציא פרטים.',
        'אם המידע אינו נמצא בהודעות, אמור זאת במפורש.',
        'כשמזהים סכום, תאריך או שם — צטט את ההודעה שממנה הוא נלקח.',
        'ענה בעברית, בקצרה וללא הקדמות.',
    ]);

    $note = $truncated
        ? "\n\n(שים לב: נכללו רק " . MAX_CONTEXT_MSGS . " ההודעות העדכניות בטווח. אם התשובה עשויה לשבת בהודעות ישנות יותר, ציין שכדאי לצמצם את טווח התאריכים.)"
        : '';

    $prompt = "השאלה:\n{$question}\n\nההודעות (כרונולוגית):\n" . buildContext($messages) . $note;

    return aiComplete($conn, $system, $prompt, 700, $transport);
}
