<?php
/**
 * קטלוג אוטומטי — שיוך מטלה חדשה לנושא.
 *
 * שתי דרכים, בכוונה:
 *
 *   מילות מפתח  עובד תמיד, בלי תלות חיצונית ובלי עלות. הפרופיל של כל
 *               נושא נבנה משמו, מתיאורו, ממילות המפתח שהוגדרו לו,
 *               ובעיקר — מהמטלות שכבר שויכו אליו. כלומר הוא לומד
 *               מהשימוש בלי שאיש מלמד אותו.
 *   מודל        מדויק יותר על טקסט חופשי, מזהה נושא שאין לו עדיין
 *               מילות מפתח, ויודע להציע נושא חדש. דורש מפתח API של
 *               Anthropic, ולכן אופציונלי לגמרי.
 *
 * ‏המודל נבדק ראשון כשהוא מוגדר, ומילות המפתח הן רשת הביטחון שמתחתיו:
 * תקלת רשת, מכסה שנגמרה או מפתח שפג — הקטלוג ממשיך לעבוד, פשוט פחות
 * חכם. אין מצב שבו מטלה נופלת בגלל שהמודל לא זמין.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';

/** ניקוד מינימלי לשיוך לפי מילות מפתח, והפער הנדרש מהנושא שאחריו. */
const TOPIC_MIN_SCORE = 4.0;
const TOPIC_MARGIN    = 1.4;


/* ── עיבוד טקסט ────────────────────────────────────────────────
 *
 * עברית כותבת מילית חיבור כאות ראשונה ("לשיפוצים", "והקבלן"), ולכן
 * השוואת מחרוזות גולמית מפספסת התאמות ברורות. חיתוך תחיליות וסופיות
 * מקרב מספיק בלי לגרור מנוע מורפולוגי.
 */

const STOPWORDS = [
    'של','את','על','עם','זה','זו','אני','אתה','הוא','היא','אנחנו','לא','כן','יש','אין',
    'צריך','צריכה','יהיה','היה','גם','כל','מה','איך','למה','כדי','אבל','או','אם','כי',
    'רק','עוד','פעם','בבקשה','תודה','לי','לו','לה','לנו','אז','כמו','אחרי','לפני','בין',
    'תוך','עד','מתי','איפה','כאן','שם','הזה','הזאת','וגם','אפשר','אולי','ממש','יותר',
    'the','a','an','to','for','and','or','is','are','of','in','on','with','it','this',
    'that','be','we','i','you','need','should','can','please','make','do',
];

function normalizeText(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    // ניקוד וטעמים — אותה מילה, ייצוג אחר.
    return (string) preg_replace('/[\x{0591}-\x{05C7}]/u', '', $s);
}

const PREFIX_LETTERS = 'והבלמשכ';
const SUFFIXES       = ['יות', 'ים', 'ות', 'ה'];

/** חותך תחילית אחת וסופית אחת. שומר מילים קצרות כמו שהן. */
function stemWord(string $w): string {
    if (mb_strlen($w) > 4 && mb_strpos(PREFIX_LETTERS, mb_substr($w, 0, 1)) !== false) {
        $w = mb_substr($w, 1);
    }
    foreach (SUFFIXES as $suffix) {
        if (mb_strlen($w) > mb_strlen($suffix) + 2 && str_ends_with($w, $suffix)) {
            return mb_substr($w, 0, mb_strlen($w) - mb_strlen($suffix));
        }
    }
    return $w;
}

/**
 * כל הצורות שבהן אותה מילה עשויה להופיע.
 *
 * הבעיה: "מנוע", "המנוע" ו"מהמנוע" הן מילה אחת, ומספר התחיליות משתנה.
 * חיתוך של מספר קבוע תמיד יפספס — חתך אחד ו"מהמנוע" נשאר "המנוע";
 * חתך שניים ו"מנוע" עצמו נשחק ל"נוע". לכן שומרים את כל צורות הביניים,
 * וההשוואה נעשית "יש צורה משותפת" ולא "אותו שורש".
 *
 * הניקוד מתבצע פעם אחת למילה (המשקל הגבוה מבין הצורות), כדי שריבוי
 * הצורות לא ינפח את הניקוד.
 */
function wordForms(string $w): array {
    $forms = [$w];
    $cur   = $w;
    for ($i = 0; $i < 2; $i++) {
        if (mb_strlen($cur) > 4 && mb_strpos(PREFIX_LETTERS, mb_substr($cur, 0, 1)) !== false) {
            $cur = mb_substr($cur, 1);
            $forms[] = $cur;
        } else break;
    }
    foreach ($forms as $f) {
        foreach (SUFFIXES as $suffix) {
            if (mb_strlen($f) > mb_strlen($suffix) + 2 && str_ends_with($f, $suffix)) {
                $forms[] = mb_substr($f, 0, mb_strlen($f) - mb_strlen($suffix));
                break;
            }
        }
    }
    return array_values(array_unique(array_filter($forms, fn($f) => mb_strlen($f) >= 2)));
}

/** מילים משמעותיות בלבד, כל אחת כרשימת הצורות שלה. */
function textWords(string $s): array {
    $parts = preg_split('/[^\p{L}\p{N}]+/u', normalizeText($s)) ?: [];
    $out = [];
    foreach ($parts as $p) {
        if ($p === '' || mb_strlen($p) < 2) continue;
        if (in_array($p, STOPWORDS, true)) continue;
        $out[] = wordForms($p);
    }
    return $out;
}

/** אותן מילים, שטוחות — לבניית פרופילים ולספירות. */
function textTokens(string $s): array {
    return array_merge(...(textWords($s) ?: [[]]));
}

/* ── פרופיל נושא ───────────────────────────────────────────────── */

/**
 * בונה לכל נושא מפת מונח→משקל.
 *
 * המשקלים משקפים כמה הסימן אמין: מילת מפתח שהוגדרה במפורש שווה יותר
 * ממילה שהופיעה במקרה בתיאור. המונחים הנלמדים מהמטלות הקיימים הם
 * המרכיב שגורם לזה להשתפר מעצמו ככל שמצטברות מטלות.
 */
function topicProfiles(PDO $pdo): array {
    $topics = $pdo->query('SELECT id, name, description, keywords FROM topics WHERE archived = 0')->fetchAll();
    if (!$topics) return [];

    $profiles = [];
    foreach ($topics as $t) {
        $terms  = [];
        $phrases = [];

        foreach (preg_split('/[,\n]+/u', (string) $t['keywords']) ?: [] as $kw) {
            $kw = trim(normalizeText($kw));
            if ($kw === '') continue;
            if (str_contains($kw, ' ')) $phrases[$kw] = 6.0;      // ביטוי שלם — סימן חזק
            foreach (textTokens($kw) as $tok) $terms[$tok] = max($terms[$tok] ?? 0, 4.0);
        }
        foreach (textTokens((string) $t['name']) as $tok)        $terms[$tok] = max($terms[$tok] ?? 0, 3.0);
        foreach (textTokens((string) $t['description']) as $tok) $terms[$tok] = max($terms[$tok] ?? 0, 1.5);

        $profiles[(int) $t['id']] = [
            'id' => (int) $t['id'], 'name' => $t['name'],
            'terms' => $terms, 'phrases' => $phrases,
        ];
    }

    learnFromTasks($pdo, $profiles);
    return $profiles;
}

/**
 * מוסיף לפרופילים מונחים מהמטלות שכבר שויכו.
 *
 * מונח נספר רק אם הופיע בשתי מטלות לפחות (מילה חד־פעמית היא רעש), ורק
 * אם אינו מפוזר על פני רוב הנושאים — מילה שמופיעה בכולם אינה מבדילה
 * בין כלום.
 */
function learnFromTasks(PDO $pdo, array &$profiles): void {
    if (count($profiles) < 1) return;

    $rows = $pdo->query(
        'SELECT topic_id, title, body FROM tasks
          WHERE topic_id IS NOT NULL ORDER BY id DESC LIMIT 400'
    )->fetchAll();

    $docCount = [];   // topic → term → בכמה מטלות הופיע
    foreach ($rows as $r) {
        $tid = (int) $r['topic_id'];
        if (!isset($profiles[$tid])) continue;
        $seen = array_unique(array_merge(
            textTokens((string) $r['title']),
            array_slice(textTokens((string) $r['body']), 0, 60)
        ));
        foreach ($seen as $tok) $docCount[$tid][$tok] = ($docCount[$tid][$tok] ?? 0) + 1;
    }

    $spread = [];     // term → בכמה נושאים הוא מופיע
    foreach ($docCount as $terms) {
        foreach ($terms as $tok => $_) $spread[$tok] = ($spread[$tok] ?? 0) + 1;
    }
    $maxSpread = max(1, (int) floor(count($profiles) / 2));

    foreach ($docCount as $tid => $terms) {
        arsort($terms);
        $added = 0;
        foreach ($terms as $tok => $n) {
            if ($n < 2 || ($spread[$tok] ?? 0) > $maxSpread) continue;
            // ככל שהמונח חוזר ביותר מטלות של הנושא, כך הוא אמין יותר —
            // אבל לעולם לא כמו מילת מפתח שאדם הגדיר במפורש (4.0).
            $profiles[$tid]['terms'][$tok] = max($profiles[$tid]['terms'][$tok] ?? 0, 1.0 + min(1.5, $n / 3));
            if (++$added >= 40) break;
        }
    }
}

/** מנקד טקסט מול הפרופילים. מחזיר [topic_id => score] בסדר יורד. */
function scoreTopics(string $text, array $profiles): array {
    $words  = textWords($text);
    $norm   = normalizeText($text);
    $scores = [];

    foreach ($profiles as $tid => $p) {
        $s = 0.0;
        foreach ($words as $forms) {
            $best = 0.0;
            foreach ($forms as $f) $best = max($best, $p['terms'][$f] ?? 0);
            $s += $best;
        }
        foreach ($p['phrases'] as $phrase => $w) if (str_contains($norm, $phrase)) $s += $w;
        if ($s > 0) $scores[$tid] = round($s, 2);
    }
    arsort($scores);
    return $scores;
}

/**
 * ההחלטה עצמה, לפי מילות מפתח בלבד.
 *
 * דורש גם ניקוד מוחלט וגם פער מהנושא הבא. שיוך שגוי גרוע מאי־שיוך:
 * מטלה בלי נושא בולטת לעין ומתוקנת בקליק, מטלה בנושא הלא נכון נעלמת.
 */
function classifyByKeywords(string $title, string $body, array $profiles): array {
    $scores = scoreTopics($title . ' ' . $title . ' ' . $body, $profiles);   // לכותרת משקל כפול
    if (!$scores) return ['topic_id' => null, 'confidence' => 0.0, 'source' => 'none', 'scores' => []];

    $ids    = array_keys($scores);
    $best   = $scores[$ids[0]];
    $second = count($ids) > 1 ? $scores[$ids[1]] : 0.0;

    $confident = $best >= TOPIC_MIN_SCORE && ($second === 0.0 || $best >= $second * TOPIC_MARGIN);
    $conf = $best / ($best + 4) * ($second > 0 ? $best / ($best + $second) : 1.0);

    return [
        'topic_id'   => $confident ? $ids[0] : null,
        'confidence' => round(min(0.95, $conf), 2),
        'source'     => $confident ? 'keyword' : 'none',
        'scores'     => $scores,
        'runner_up'  => $confident && $second > 0 ? $ids[1] : null,
    ];
}

/* ── המודל ─────────────────────────────────────────────────────── */

function classifyPrompt(array $topics, string $title, string $body): string {
    $lines = [];
    foreach ($topics as $t) {
        $desc = trim((string) $t['description']);
        $lines[] = "- id={$t['id']} | {$t['name']}" . ($desc !== '' ? " | $desc" : '');
    }
    $list = $lines ? implode("\n", $lines) : '(אין עדיין נושאים)';
    $bodyPart = trim($body) !== '' ? "\nפירוט: " . mb_substr($body, 0, 1500) : '';

    return <<<TXT
    להלן רשימת נושאים קיימים בלוח מטלות:
    $list

    מטלה חדשה:
    כותרת: $title$bodyPart

    בחר את הנושא הקיים שהמטלה שייכת לו. אם אף נושא אינו מתאים באמת, אל
    תכפה שיוך — הצע שם לנושא חדש, קצר וכללי מספיק שמטלות דומות ייכנסו
    אליו גם הן.

    החזר JSON בלבד, ללא טקסט נוסף:
    {"topic_id": <מספר או null>, "new_topic": <שם או null>, "confidence": <0 עד 1>, "reason": "<נימוק קצר בעברית>"}
    TXT;
}

/** שיוך בעזרת מודל. חריגה נזרקת כדי שהקורא ייפול חזרה למילות מפתח. */
function classifyByModel(array $topics, string $title, string $body, array $conn,
                         ?callable $transport = null): array {
    $data = extractJson(aiComplete(
        $conn,
        'אתה מקטלג מטלות. אתה עונה JSON בלבד, בלי טקסט לפניו או אחריו.',
        classifyPrompt($topics, $title, $body),
        300, $transport
    ));
    $valid = array_column($topics, 'id');

    $tid = $data['topic_id'] ?? null;
    $tid = (is_numeric($tid) && in_array((int) $tid, array_map('intval', $valid), true)) ? (int) $tid : null;

    $new = $data['new_topic'] ?? null;
    $new = (is_string($new) && trim($new) !== '') ? mb_substr(trim($new), 0, 80) : null;

    $conf = is_numeric($data['confidence'] ?? null) ? max(0.0, min(1.0, (float) $data['confidence'])) : 0.5;

    // מתחת לסף — עדיף להשאיר בלי נושא מאשר לשייך בניחוש.
    if ($tid !== null && $conf < 0.45) { $new = $new ?: null; $tid = null; }

    return [
        'topic_id'   => $tid,
        'hint'       => $tid === null ? $new : null,
        'confidence' => round($conf, 2),
        'source'     => 'llm',
        'provider'   => (string) ($conn['provider'] ?? ''),
        'reason'     => is_string($data['reason'] ?? null) ? mb_substr($data['reason'], 0, 300) : '',
    ];
}

/* ── הכניסה הראשית ─────────────────────────────────────────────── */

/**
 * מקטלג מטלה. מנסה מודל אם יש, ונופל למילות מפתח בכל מקרה אחר.
 *
 * מחזיר תמיד מבנה מלא — גם כשלא נמצא נושא — כדי שהקורא לא יצטרך
 * להבדיל בין "לא הצליח" לבין "נכשל".
 */
function classifyTask(PDO $pdo, string $title, string $body = '', array $opts = []): array {
    $profiles = topicProfiles($pdo);
    $topics   = array_map(
        fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'description' => ''],
        array_values($profiles)
    );
    foreach ($pdo->query('SELECT id, description FROM topics WHERE archived = 0')->fetchAll() as $row) {
        foreach ($topics as &$t) if ($t['id'] === (int) $row['id']) $t['description'] = (string) $row['description'];
        unset($t);
    }

    $conn = $opts['conn'] ?? [];
    if (!empty($conn['key'])) {
        try {
            $r = classifyByModel($topics, $title, $body, $conn, $opts['transport'] ?? null);
            $r['topic_name'] = $r['topic_id'] !== null ? ($profiles[$r['topic_id']]['name'] ?? null) : null;
            $r['fallback']   = null;
            return $r;
        } catch (Throwable $e) {
            $note = $e->getMessage();      // ממשיכים למילות מפתח, אבל מדווחים למה
        }
    }

    $r = classifyByKeywords($title, $body, $profiles);
    return [
        'topic_id'   => $r['topic_id'],
        'topic_name' => $r['topic_id'] !== null ? ($profiles[$r['topic_id']]['name'] ?? null) : null,
        'hint'       => null,
        'confidence' => $r['confidence'],
        'source'     => $r['source'],
        'reason'     => $r['topic_id'] !== null ? 'התאמה למילות המפתח של הנושא' : 'אין התאמה מובהקת לאף נושא',
        'fallback'   => $note ?? null,
        'provider'   => '',
        'scores'     => $r['scores'],
    ];
}
