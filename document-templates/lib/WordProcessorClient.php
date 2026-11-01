<?php
/**
 * לקוח של עורך התמלילים — לאפליקציה מארחת. **מעתיקים את הקובץ הזה לתיקיית האפליקציה** (לא require מכאן:
 * אפליקציה לא טוענת קבצים מחוץ לתיקייה שלה — CLAUDE.md). המדריך: word-processor/docs/integration.md.
 *
 *   $wp = new WordProcessorClient($pdo, 'org-budget-proposals', 'הצעות תקציב לארגונים',
 *       'https://mbe-plus.com/mini_projects/org-budget-proposals/api.php?action=embed_key');
 *   // api.php?action=embed_key  (ציבורי):   echo json_encode($wp->publicKeys());
 *   $s = $wp->embedSession($docId, ['id' => $userId, 'name' => $userName]);   // → ['embed_url', 'doc']
 *   $html = $wp->export($docId, 'html');                                       // → ['html', 'css']
 *
 * מה הוא עושה בשבילכם: יוצר זוג מפתחות Ed25519 ושומר אותו במסד שלכם (טבלה wp_embed_keys), נרשם בעורך
 * בפעם הראשונה (ושוב אם העורך אומר "לא מכיר"), חותם כל בקריאה (פרק 2 ב-platform/docs/embed.md), ומחזיר
 * מערך או — ל-docx/pdf — את הבתים. שגיאה → WordProcessorError עם code ו-status.
 *
 * דרישות: PHP 8.1+, sodium, curl, PDO (SQLite או MySQL).
 */

final class WordProcessorError extends RuntimeException
{
    public function __construct(string $message, public string $codeName = 'error', public int $status = 0)
    {
        parent::__construct($message);
    }
}

final class WordProcessorClient
{
    public const EDITOR = 'https://mbe-plus.com/mini_projects/word-processor/';

    /**
     * @param PDO    $db      המסד של האפליקציה המארחת (שם נשמר המפתח הפרטי — לא בקובץ ולא בגיט)
     * @param string $client  מזהה האפליקציה = שם התיקייה שלה (org-budget-proposals)
     * @param string $name    השם בעברית (מוצג אצל המנהל של העורך)
     * @param string $keyUrl  הכתובת הציבורית שמחזירה את publicKeys() — חייבת להיות בתיקייה של האפליקציה
     * @param array  $origins המקורות שמהם האפליקציה מציגה את העורך (iframe)
     */
    public function __construct(
        private PDO $db,
        private string $client,
        private string $name,
        private string $keyUrl,
        private array $origins = ['https://mbe-plus.com'],
        private string $editor = self::EDITOR,
    ) {
        $this->editor = rtrim($this->editor, '/') . '/';
    }

    // ------------------------------------------------------------------
    // מפתחות
    // ------------------------------------------------------------------

    private function ensureTable(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS wp_embed_keys (kid VARCHAR(40) PRIMARY KEY, secret TEXT NOT NULL, public_key TEXT NOT NULL, created_at INTEGER NOT NULL, retired INTEGER NOT NULL DEFAULT 0)');
    }

    /** המפתח הפעיל (נוצר בפעם הראשונה). */
    private function activeKey(): array
    {
        $this->ensureTable();
        $row = $this->db->query('SELECT * FROM wp_embed_keys WHERE retired = 0 ORDER BY created_at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        return $row ?: $this->newKey();
    }

    private function newKey(): array
    {
        $kp = sodium_crypto_sign_keypair();
        $row = ['kid' => gmdate('Y-m') . '-' . bin2hex(random_bytes(2)), 'secret' => base64_encode(sodium_crypto_sign_secretkey($kp)),
            'public_key' => base64_encode(sodium_crypto_sign_publickey($kp)), 'created_at' => time(), 'retired' => 0];
        $this->db->prepare('INSERT INTO wp_embed_keys (kid, secret, public_key, created_at, retired) VALUES (?, ?, ?, ?, 0)')
            ->execute([$row['kid'], $row['secret'], $row['public_key'], $row['created_at']]);
        return $row;
    }

    /** מה ש-embed_key מחזיר (ציבורי — אין בו סוד). */
    public function publicKeys(): array
    {
        $this->activeKey();
        $keys = [];
        foreach ($this->db->query('SELECT kid, public_key FROM wp_embed_keys WHERE retired = 0 ORDER BY created_at DESC') as $r) {
            $keys[] = ['kid' => $r['kid'], 'alg' => 'Ed25519', 'key' => $r['public_key']];
        }
        return ['client' => $this->client, 'keys' => $keys];
    }

    /**
     * החלפת מפתח: נוצר מפתח חדש וחותם מעכשיו; הישן נשאר מפורסם עד retireOldKeys() (כמה דקות אחר כך —
     * העורך שומר את המפתחות במטמון שעה, וכשחתימה נכשלת מוריד מחדש).
     */
    public function rotateKey(): string
    {
        return $this->newKey()['kid'];
    }

    public function retireOldKeys(): void
    {
        $active = $this->activeKey()['kid'];
        $this->db->prepare('UPDATE wp_embed_keys SET retired = 1 WHERE kid <> ?')->execute([$active]);
    }

    // ------------------------------------------------------------------
    // רישום
    // ------------------------------------------------------------------

    /** 'active' | 'pending'. בטוח לקרוא בכל עלייה — רישום זהה לא משנה דבר. */
    public function register(): string
    {
        $this->activeKey();
        $r = $this->post('client_register', ['client' => $this->client, 'name' => $this->name, 'key_url' => $this->keyUrl, 'origins' => $this->origins], false);
        return (string)($r['status'] ?? 'pending');
    }

    // ------------------------------------------------------------------
    // ה-API (פרק 3 ב-embed.md)
    // ------------------------------------------------------------------

    /**
     * כתובת חד-פעמית ל-iframe (60 שניות). $doc ריק — מסמך חדש (המזהה חוזר ב-'doc' — שמרו אותו).
     * $user: ['id' => מזהה יציב אצלכם, 'name' => השם להצגה]. $mode: 'document' או 'section' (+ $page).
     */
    public function embedSession(?string $doc, array $user, string $can = 'edit', string $mode = 'document', ?array $page = null, string $title = '', ?string $returnOrigin = null): array
    {
        $body = ['doc' => (string)$doc, 'user' => ['id' => (string)$user['id'], 'name' => (string)($user['name'] ?? $user['id'])],
            'can' => $can, 'mode' => $mode, 'return_origin' => $returnOrigin ?? $this->origins[0]];
        if ($page) $body['page'] = $page;
        if ($title !== '') $body['title'] = $title;
        return $this->call('embed_session', $body);
    }

    public function create(string $title, ?array $page = null, ?array $import = null): string
    {
        $body = ['title' => $title];
        if ($page) $body['page'] = $page;
        if ($import) $body['import'] = $import;   // ['format' => 'lines'|'docx'|'html', 'content' => …]
        return $this->call('doc_create', $body)['doc'];
    }

    /** העתק (למשל הצעה חדשה על בסיס השנה הקודמת): $replace = [['2026', '2027'], ['תשפ"ו', 'תשפ"ז']]. */
    public function copy(string $from, string $title, array $replace = []): string
    {
        return $this->call('doc_copy', ['from' => $from, 'title' => $title, 'replace' => $replace])['doc'];
    }

    public function get(string $doc): array
    {
        return $this->call('doc_get', ['doc' => $doc]);
    }

    /**
     * $format: 'html' → ['html','css'] · 'ooxml' → ['body','styles','numbering','numbering_parts','media','rels','namespaces']
     * · 'text' → ['text'] · 'json' → ['json','page'] · 'docx' / 'pdf' → מחרוזת הבתים של הקובץ.
     */
    public function export(string $doc, string $format, ?int $version = null): array|string
    {
        $body = ['doc' => $doc, 'format' => $format];
        if ($version !== null) $body['version'] = $version;
        return $this->call('doc_export', $body);
    }

    /** $format: 'lines' (# / ## / -), 'html', 'docx' (התוכן — הבתים של הקובץ; נשלח ב-base64). */
    public function import(string $doc, string $format, string $content): array
    {
        return $this->call('doc_import', ['doc' => $doc, 'format' => $format, 'content' => $format === 'docx' ? base64_encode($content) : $content]);
    }

    public function delete(string $doc): void
    {
        $this->call('doc_delete', ['doc' => $doc]);
    }

    /** כל המסמכים של האפליקציה (בעמודים של 200) — לגיבוי ולהתנתקות. */
    public function listAll(): array
    {
        $all = [];
        $after = null;
        do {
            $r = $this->call('doc_list', $after ? ['after' => $after] : []);
            array_push($all, ...$r['docs']);
            $after = $r['next'] ?? null;
        } while ($after);
        return $all;
    }

    /**
     * קריאה חתומה. "לא מכיר את הלקוח" → רישום ונסיון חוזר אחד. ממתין לאישור המנהל של העורך →
     * WordProcessorError עם code 'pending'.
     */
    public function call(string $action, array $payload): array|string
    {
        try {
            return $this->post($action, $payload, true);
        } catch (WordProcessorError $e) {
            if ($e->codeName !== 'unknown_client') throw $e;
            if ($this->register() !== 'active') throw new WordProcessorError('האפליקציה נרשמה בעורך וממתינה לאישור המנהל שלו', 'pending', 401);
            return $this->post($action, $payload, true);
        }
    }

    private function post(string $action, array $payload, bool $signed): array|string
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type: application/json', 'Accept: application/json',
            'Cookie: humans_21909=1'];   // השרת מציג לפעמים אתגר JS ללקוח שאינו דפדפן; העוגייה הזו עוברת אותו
        if ($signed) {
            $k = $this->activeKey();
            $time = (string)time();
            $nonce = bin2hex(random_bytes(16));
            $sig = sodium_crypto_sign_detached("$action\n$time\n$nonce\n" . hash('sha256', $body), base64_decode($k['secret']));
            array_push($headers, 'X-Embed-Client: ' . $this->client, "X-Embed-Time: $time", "X-Embed-Nonce: $nonce",
                'X-Embed-Signature: ' . $k['kid'] . '.' . base64_encode($sig));
        }
        $ch = curl_init($this->editor . 'api.php?action=' . rawurlencode($action));
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 8]);
        $res = curl_exec($ch);
        if ($res === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new WordProcessorError('העורך לא זמין כרגע (' . $err . ')', 'offline', 0);
        }
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        $data = substr($res, $hsize);
        if ($status === 200 && !str_contains($type, 'json')) return $data;   // docx / pdf
        $j = json_decode($data, true);
        if ($status !== 200 || !is_array($j)) {
            throw new WordProcessorError(is_array($j) ? ($j['error'] ?? 'שגיאה') : "תשובה לא צפויה מהעורך ($status)", is_array($j) ? ($j['code'] ?? 'error') : 'server', $status);
        }
        return $j;
    }
}
