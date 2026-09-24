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
/** תקרת ההודעות שנפרשות ומפוענחות לזיכרון לצורך סינון רלוונטיות. */
const SCAN_CAP = 25000;

/** מילות תשלום — שאלה שמכילה אחת מהן נחשבת "שאלת כסף". */
const MONEY_TERMS = ['תשלום','תשלומ','שילם','שולם','לשלם','העבר','אסמכת','קבלה','חשבונית',
    'סכום','כסף','זיכוי','חיוב','₪','שקל','ש"ח','דולר','$','ביט','פייבוקס','paybox','bit'];

/** מילות מפתch מהשאלה, לצורך התאמה. */
function queryKeywords(string $q): array {
    $q = preg_replace('/[^\p{L}\p{N}₪$"]+/u', ' ', $q);
    $stop = ['מי','מה','מתי','איפה','כמה','האם','את','של','לי','עם','על','אני','זה','יש','לא',
             'או','גם','כל','היה','הם','אלי','אליי','שלח','שלחו','שלחה','שולח'];
    $out = [];
    foreach (preg_split('/\s+/u', trim($q)) as $w) {
        $w = trim($w);
        if (mb_strlen($w) >= 2 && !in_array($w, $stop, true)) $out[] = $w;
    }
    return array_values(array_unique($out));
}

function isMoneyQuestion(string $q): bool {
    foreach (MONEY_TERMS as $t) if (mb_stripos($q, $t) !== false) return true;
    return false;
}

/** האם ההודעה נראית כספית: מכילה ספרה + סימן/מילת תשלום. */
function looksMonetary(string $body): bool {
    if (!preg_match('/\d/u', $body)) return false;
    return (bool) preg_match('/(₪|ש"ח|שקל|\$|דולר|תשלום|העבר|אסמכת|קבלה|חשבונית|ביט|paybox|bit)/ui', $body);
}

/**
 * אוסף הודעות רלוונטיות לשאלה מכל ההיסטוריה (עד SCAN_CAP), ולא רק
 * מהחלון האחרון. פורש ומפענח בזיכרון, מדרג לפי התאמה לשאלה, ושולח
 * למודל את המתאימות ביותר. כך שאלה רחבה מוצאת מידע ישן, לא רק אחרון.
 */
function collectMessages(?int $accountId, ?int $from, ?int $to, string $question = ''): array {
    $where = [];
    $args  = [];
    if ($accountId !== null) { $where[] = 'account_id = ?'; $args[] = $accountId; }
    if ($from !== null)      { $where[] = 'sent_at >= ?';   $args[] = $from; }
    if ($to !== null)        { $where[] = 'sent_at <= ?';   $args[] = $to; }
    $clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT m.*, a.label AS account_label, a.kind AS account_kind
              FROM messages m JOIN accounts a ON a.id = m.account_id
              $clause
             ORDER BY m.sent_at DESC
             LIMIT " . (SCAN_CAP + 1);
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();

    $capped = count($rows) > SCAN_CAP;
    if ($capped) array_pop($rows);

    // פענוח בזיכרון.
    $all = [];
    foreach ($rows as $r) {
        $all[] = [
            'account'   => $r['account_label'],
            'kind'      => $r['account_kind'],
            'chat'      => decryptSecret($r['chat_name_enc']),
            'sender'    => decryptSecret($r['sender_enc']),
            'direction' => $r['direction'],
            'body'      => decryptSecret($r['body_enc']),
            'sent_at'   => (int) $r['sent_at'],
        ];
    }

    $keywords = queryKeywords($question);
    $money    = isMoneyQuestion($question);
    $scannedTotal = count($all);

    // דירוג לפי רלוונטיות. אם אין מילות מפתח כלל — נופלים לחלון האחרון.
    $selected = $all;
    $byRelevance = false;
    if ($keywords || $money) {
        $scored = [];
        foreach ($all as $i => $m) {
            $body = $m['body'];
            $score = 0;
            foreach ($keywords as $kw) if (mb_stripos($body, $kw) !== false) $score++;
            if ($money && looksMonetary($body)) $score += 2;
            if ($score > 0) $scored[] = ['s' => $score, 'i' => $i, 't' => $m['sent_at'], 'm' => $m];
        }
        if ($scored) {
            usort($scored, fn($a, $b) => $b['s'] <=> $a['s'] ?: $b['t'] <=> $a['t']);
            $scored = array_slice($scored, 0, MAX_CONTEXT_MSGS);
            $selected = array_map(fn($x) => $x['m'], $scored);
            $byRelevance = true;
        } else {
            // שום הודעה לא תאמה — נחזיר את החלון האחרון כדי לא להשאיר ריק.
            $selected = array_slice($all, 0, MAX_CONTEXT_MSGS);
        }
    } else {
        $selected = array_slice($all, 0, MAX_CONTEXT_MSGS);
    }

    $truncated = !$byRelevance && count($all) > MAX_CONTEXT_MSGS;

    // סדר כרונולוגי לקריאת המודל.
    usort($selected, fn($a, $b) => $a['sent_at'] <=> $b['sent_at']);

    return [
        'messages'   => $selected,
        'truncated'  => $truncated,
        'by_relevance' => $byRelevance,
        'scanned'    => $scannedTotal,
        'capped'     => $capped,
    ];
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

    $scanned = (int) ($collected['scanned'] ?? count($messages));
    if (!empty($collected['by_relevance'])) {
        $note = "\n\n(נסרקו $scanned הודעות מכל ההיסטוריה, ונבחרו מהן ה"
            . MAX_CONTEXT_MSGS . " הרלוונטיות ביותר לשאלה. אם לא נמצאה תשובה, ייתכן שהמידע בקובץ/תמונה שטרם נסרק, או מחוץ ל-$scanned האחרונות.)";
    } elseif ($truncated) {
        $note = "\n\n(נסרקו $scanned הודעות; נכללו רק ה" . MAX_CONTEXT_MSGS
            . " העדכניות. לשאלה כללית כדאי לנסח מילות מפתח (למשל 'תשלום', 'אסמכתא', שם) או לצמצם תאריכים.)";
    } else {
        $note = '';
    }

    $prompt = "השאלה:\n{$question}\n\nההודעות (כרונולוגית):\n" . buildContext($messages) . $note;

    return aiComplete($conn, $system, $prompt, 700, $transport);
}
