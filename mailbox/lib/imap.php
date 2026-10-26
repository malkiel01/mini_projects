<?php
/**
 * קריאת התיבה ב-ext-imap — בלי ספרייה ובלי Composer.
 *
 * תמיד TLS עם בדיקת תעודה (בלי novalidate-cert): 993 — SSL ישיר, 143 — STARTTLS חובה.
 * תיקייה אחת בשלב א׳: INBOX. קריאה בלבד, חוץ מסימון "נקרא" (\Seen) כשפותחים הודעה.
 * כל קריאת גוף היא FT_PEEK — רשימת ההודעות לא מסמנת אף הודעה כנקראה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const MB_LIST_LIMIT = 50;
const MB_SNIPPET = 180;
const MB_CID_BUDGET = 6 * 1024 * 1024;   // תמונות מוטמעות (cid:) שנכנסות להודעה כ-data:

final class MbImapError extends RuntimeException {}

function mb_imap_spec(string $host, int $port): string
{
    $flags = $port === 143 ? '/imap/tls' : '/imap/ssl';
    return '{' . $host . ':' . $port . $flags . '}';
}

/** חיבור לתיבה. $box — שורה מהמסד, או [imap_host, imap_port, username, password] בבדיקת תיבה חדשה. */
function mb_imap_open(array $box, int $options = 0)
{
    imap_timeout(IMAP_OPENTIMEOUT, 10);
    imap_timeout(IMAP_READTIMEOUT, 20);
    $pass = $box['password'] ?? mb_decrypt($box['secret']);
    $spec = mb_imap_spec($box['imap_host'], (int)$box['imap_port']) . 'INBOX';
    $mb = @imap_open($spec, $box['username'], $pass, $options, 0, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
    $errors = imap_errors() ?: [];
    imap_alerts();
    if (!$mb) throw new MbImapError(mb_imap_explain($errors));
    return $mb;
}

/** הודעה ברורה למשתמש — בלי פרטים טכניים שמסגירים יותר מדי. */
function mb_imap_explain(array $errors): string
{
    $all = strtolower(implode(' | ', $errors));
    if (str_contains($all, 'authenticat') || str_contains($all, 'login') || str_contains($all, 'password')) {
        return 'ההתחברות לתיבה נכשלה: שם המשתמש או הסיסמה שגויים';
    }
    if (str_contains($all, 'certificate')) return 'התעודה של שרת הדואר לא תקינה לשם הזה. נסו את שם השרת שמופיע בתעודה';
    if (str_contains($all, 'timed out') || str_contains($all, 'timeout')) return 'שרת הדואר לא עונה (פג הזמן)';
    if (str_contains($all, 'host') || str_contains($all, 'connect')) return 'לא ניתן להתחבר לשרת הדואר';
    return 'שגיאה בחיבור לשרת הדואר';
}

function mb_imap_close($mb): void
{
    @imap_close($mb);
    imap_errors();
    imap_alerts();
}

// ── פענוח ──────────────────────────────────────────────

function mb_to_utf8(string $s, ?string $charset): string
{
    $cs = strtolower(trim((string)$charset, " \"'"));
    if ($cs === '' || $cs === 'default' || $cs === 'us-ascii' || $cs === 'utf-8' || $cs === 'utf8') {
        return mb_clean_utf8($s);
    }
    if ($cs === 'iso-8859-8-i') $cs = 'iso-8859-8';
    if ($cs === 'ks_c_5601-1987') $cs = 'cp949';
    $out = @iconv($cs, 'UTF-8//IGNORE', $s);
    return $out === false ? mb_clean_utf8($s) : $out;
}

/** UTF-8 תקין בלבד — json_encode נופל על בית שבור. */
function mb_clean_utf8(string $s): string
{
    if (preg_match('//u', $s)) return $s;
    $out = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
    return $out === false ? '' : $out;
}

function mb_decode_header(?string $raw): string
{
    if ($raw === null || $raw === '') return '';
    $out = '';
    foreach (imap_mime_header_decode($raw) ?: [] as $p) {
        $out .= mb_to_utf8($p->text, $p->charset);
    }
    return trim(preg_replace('/\s+/u', ' ', $out) ?? '');
}

/** "דנה לוי <dana@x.com>" → [name, email]. */
function mb_parse_address(?string $raw): array
{
    if (!$raw) return ['', ''];
    $list = @imap_rfc822_parse_adrlist($raw, 'invalid');
    imap_errors();
    $a = $list[0] ?? null;
    if (!$a || !isset($a->mailbox, $a->host) || $a->host === 'invalid' || $a->host === '.SYNTAX-ERROR.') {
        return [mb_decode_header($raw), ''];
    }
    $email = strtolower($a->mailbox . '@' . $a->host);
    $name = isset($a->personal) ? mb_decode_header($a->personal) : '';
    return [trim($name, " \"'"), $email];
}

function mb_decode_body(string $data, int $encoding): string
{
    return match ($encoding) {
        ENCBASE64 => (string)base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $data) ?? ''),
        ENCQUOTEDPRINTABLE => quoted_printable_decode($data),
        default => $data,
    };
}

/** פרמטרים של חלק (Content-Type ו-Content-Disposition), כולל RFC 2231 (filename*=utf-8''…). */
function mb_part_params(object $p): array
{
    $out = [];
    $cont = [];
    foreach (['parameters', 'dparameters'] as $k) {
        if (empty($p->{'if' . $k})) continue;
        foreach ($p->$k as $x) {
            $attr = strtolower($x->attribute);
            $val = (string)$x->value;
            if (preg_match('/^(.+?)\*(\d+)?\*?$/', $attr, $m)) {          // filename* / filename*0* / filename*1
                $cont[$m[1]][(int)($m[2] ?? 0)] = [$val, str_ends_with($attr, '*')];
                continue;
            }
            $out[$attr] = mb_decode_header($val);
        }
    }
    foreach ($cont as $name => $pieces) {
        ksort($pieces);
        $charset = 'utf-8';
        $s = '';
        foreach ($pieces as $i => [$v, $enc]) {
            if ($i === 0 && $enc && preg_match("/^([^']*)'[^']*'(.*)$/s", $v, $m)) {
                $charset = $m[1] ?: 'utf-8';
                $v = $m[2];
            }
            $s .= $enc ? rawurldecode($v) : $v;
        }
        $out[$name] = mb_to_utf8($s, $charset);
    }
    return $out;
}

const MB_TYPES = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'model', 'other'];

/**
 * המבנה כרשימה שטוחה: גוף טקסט, גוף HTML, צרופות ותמונות מוטמעות.
 * מספרי החלקים כמו ב-IMAP: "1", "1.2" … (הודעה בלי חלקים — "1").
 */
function mb_walk(object $s, string $num = '', array &$out = []): array
{
    $out += ['text' => null, 'html' => null, 'attachments' => [], 'inline' => []];
    $type = MB_TYPES[$s->type ?? 0] ?? 'other';
    $sub = strtolower($s->subtype ?? '');

    if ($type === 'multipart' && !empty($s->parts)) {
        foreach ($s->parts as $i => $child) {
            mb_walk($child, $num === '' ? (string)($i + 1) : $num . '.' . ($i + 1), $out);
        }
        return $out;
    }

    $part = $num === '' ? '1' : $num;
    $params = mb_part_params($s);
    $disp = !empty($s->ifdisposition) ? strtolower($s->disposition) : '';
    $name = $params['filename'] ?? $params['name'] ?? '';
    $cid = !empty($s->ifid) ? trim($s->id, '<> ') : '';
    $info = [
        'part' => $part,
        'type' => $type . '/' . $sub,
        'encoding' => (int)($s->encoding ?? 0),
        'charset' => $params['charset'] ?? null,
        'size' => (int)($s->bytes ?? 0),
        'name' => $name,
        'cid' => $cid,
    ];

    if ($type === 'text' && $disp !== 'attachment' && $name === '' && in_array($sub, ['plain', 'html'], true)) {
        $key = $sub === 'html' ? 'html' : 'text';
        if ($out[$key] === null) $out[$key] = $info;
        return $out;
    }
    if ($type === 'image' && $cid !== '' && $disp !== 'attachment') {
        $out['inline'][] = $info;
        // תמונה מוטמעת עם שם — גם ברשימת הצרופות, כדי שאפשר יהיה להוריד
        if ($name === '') return $out;
    }
    if ($name === '') {
        $name = match (true) {
            $type === 'message' && $sub === 'rfc822' => 'הודעה מצורפת.eml',
            $type === 'text' && $sub === 'calendar' => 'הזמנה.ics',
            default => 'צרופה-' . str_replace('.', '-', $part),
        };
    }
    $info['name'] = mb_safe_filename($name);
    $out['attachments'][] = $info;
    return $out;
}

function mb_safe_filename(string $name): string
{
    $name = preg_replace('/[\x00-\x1f\x7f\/\\\\:*?"<>|]+/u', '_', $name) ?? 'file';
    $name = trim($name, " .\t");
    return mb_substr_safe($name === '' ? 'file' : $name, 150);
}

function mb_substr_safe(string $s, int $len): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $len) : iconv_substr($s, 0, $len, 'UTF-8');
}

function mb_fetch_part($mb, int $uid, array $info): string
{
    $raw = (string)imap_fetchbody($mb, $uid, $info['part'], FT_UID | FT_PEEK);
    return mb_decode_body($raw, $info['encoding']);
}

function mb_fetch_text($mb, int $uid, ?array $info): string
{
    return $info ? mb_to_utf8(mb_fetch_part($mb, $uid, $info), $info['charset']) : '';
}

// ── טקסט, תקציר וקוד אימות ─────────────────────────────

function mb_html_to_text(string $html): string
{
    $html = preg_replace('#<(script|style|head|title)\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? '';
    $html = preg_replace('#<(br|/p|/div|/tr|/h\d|/li|/table)\b[^>]*>#i', "\n", $html) ?? '';
    $text = html_entity_decode(preg_replace('/<[^>]*>/', ' ', $html) ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return str_replace("\u{00A0}", ' ', $text);
}

function mb_snippet(string $text): string
{
    $t = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    return mb_substr_safe($t, MB_SNIPPET);
}

/**
 * קוד אימות: 4–8 ספרות סמוך למילה "קוד" / "אימות" / code / OTP — לפני המספר או אחריו.
 * מספר שנראה כמו שנה (19xx/20xx) נבחר רק כשאין מועמד אחר.
 */
function mb_find_code(string $subject, string $text): ?string
{
    $hay = preg_replace('/[ \t\x{00A0}]+/u', ' ', $subject . "\n" . $text) ?? '';
    $kw = '(?:קוד|אימות|סיסמה\s?חד[\s-]?פעמית|סיסמא\s?חד[\s-]?פעמית|verification|verify|code|otp|passcode|one[\s-]time|pin)';
    $found = [];
    if (preg_match_all('/' . $kw . '[^\d\n]{0,45}?(?<![\d.,\/:-])(\d{4,8})(?![\d.,\/:%]\d|\d)/iu', $hay, $m)) {
        $found = array_merge($found, $m[1]);
    }
    if (preg_match_all('/(?<![\d.,\/:-])(\d{4,8})(?![\d.,\/:%]\d|\d)[^\d\n]{0,25}?' . $kw . '/iu', $hay, $m)) {
        $found = array_merge($found, $m[1]);
    }
    if (!$found) return null;
    foreach ($found as $c) {
        if (!preg_match('/^(19|20)\d\d$/', $c)) return $c;
    }
    return $found[0];
}

// ── רשימת ההודעות ──────────────────────────────────────

function mb_cache_get(int $boxId, int $validity, array $uids): array
{
    if (!$uids) return [];
    $in = implode(',', array_fill(0, count($uids), '?'));
    $st = mb_db()->prepare("SELECT uid, data FROM msg_cache WHERE mailbox_id = ? AND uidvalidity = ? AND uid IN ($in)");
    $st->execute(array_merge([$boxId, $validity], $uids));
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['uid']] = json_decode($r['data'], true);
    return $out;
}

function mb_cache_put(int $boxId, int $validity, int $uid, array $data): void
{
    mb_db()->prepare('INSERT OR REPLACE INTO msg_cache (mailbox_id, uidvalidity, uid, data, at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$boxId, $validity, $uid, json_encode($data, JSON_UNESCAPED_UNICODE), time()]);
}

/** תקציר של הודעה אחת — נכנס למטמון, כי הודעה ב-IMAP לא משתנה. */
function mb_summarize($mb, int $uid, object $ov): array
{
    [$fromName, $fromEmail] = mb_parse_address($ov->from ?? '');
    $subject = mb_decode_header($ov->subject ?? '');
    $snippet = '';
    $code = null;
    $attach = 0;
    $s = @imap_fetchstructure($mb, $uid, FT_UID);
    if ($s) {
        $w = mb_walk($s);
        $attach = count($w['attachments']);
        $text = $w['text'] ? mb_fetch_text($mb, $uid, $w['text']) : mb_html_to_text(mb_fetch_text($mb, $uid, $w['html']));
        $snippet = mb_snippet($text);
        $code = mb_find_code($subject, $text);
    }
    return [
        'from_name' => $fromName,
        'from_email' => $fromEmail,
        'subject' => $subject,
        'date' => (int)($ov->udate ?? 0),
        'snippet' => $snippet,
        'code' => $code,
        'attach' => $attach,
    ];
}

/**
 * 50 ההודעות האחרונות, החדשה למעלה. כותרות ותקציר — מהמטמון; מצב "נקרא" — תמיד טרי מהשרת.
 */
function mb_list(array $box, int $limit = MB_LIST_LIMIT): array
{
    $mb = mb_imap_open($box);
    try {
        $spec = mb_imap_spec($box['imap_host'], (int)$box['imap_port']) . 'INBOX';
        $st = imap_status($mb, $spec, SA_UIDVALIDITY | SA_UNSEEN | SA_MESSAGES);
        $validity = (int)($st->uidvalidity ?? 0);
        $total = imap_num_msg($mb);
        $out = ['total' => $total, 'unseen' => (int)($st->unseen ?? 0), 'messages' => []];
        if ($total === 0) return $out;

        $from = max(1, $total - $limit + 1);
        $ovs = imap_fetch_overview($mb, "$from:$total", 0) ?: [];
        $uids = array_map(fn($o) => (int)$o->uid, $ovs);
        $cached = mb_cache_get((int)$box['id'], $validity, $uids);

        foreach ($ovs as $ov) {
            $uid = (int)$ov->uid;
            if (!isset($cached[$uid])) {
                $cached[$uid] = mb_summarize($mb, $uid, $ov);
                mb_cache_put((int)$box['id'], $validity, $uid, $cached[$uid]);
            }
            $out['messages'][] = ['uid' => $uid, 'seen' => (bool)$ov->seen, 'flagged' => (bool)$ov->flagged] + $cached[$uid];
        }
        usort($out['messages'], fn($a, $b) => $b['uid'] <=> $a['uid']);
        return $out;
    } finally {
        mb_imap_close($mb);
    }
}

// ── הודעה אחת ──────────────────────────────────────────

function mb_message(array $box, int $uid): array
{
    $mb = mb_imap_open($box);
    try {
        $ov = (imap_fetch_overview($mb, (string)$uid, FT_UID) ?: [])[0] ?? null;
        $s = $ov ? @imap_fetchstructure($mb, $uid, FT_UID) : null;
        if (!$ov || !$s) throw new MbImapError('ההודעה לא נמצאה — אולי נמחקה מהתיבה');

        $hdr = @imap_rfc822_parse_headers((string)imap_fetchheader($mb, $uid, FT_UID)) ?: null;
        $w = mb_walk($s);
        $text = mb_fetch_text($mb, $uid, $w['text']);
        $html = $w['html'] ? mb_fetch_text($mb, $uid, $w['html']) : '';

        // תמונות מוטמעות (cid:) — נכנסות כ-data:, כי ה-iframe בלי הרשאות לא שולח עוגיות
        $cids = [];
        $budget = MB_CID_BUDGET;
        if ($html !== '') {
            foreach ($w['inline'] as $img) {
                if ($img['size'] > $budget || !preg_match('#^image/(png|jpe?g|gif|webp)$#', $img['type'])) continue;
                $data = mb_fetch_part($mb, $uid, $img);
                $budget -= strlen($data);
                $cids[strtolower($img['cid'])] = 'data:' . $img['type'] . ';base64,' . base64_encode($data);
            }
        }

        if (!$ov->seen) {
            @imap_setflag_full($mb, (string)$uid, '\\Seen', ST_UID);
        }

        [$fromName, $fromEmail] = mb_parse_address($ov->from ?? '');
        $subject = mb_decode_header($ov->subject ?? '');
        $plain = $text !== '' ? $text : mb_html_to_text($html);
        return [
            'uid' => $uid,
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'to' => mb_addresses($hdr->to ?? []),
            'cc' => mb_addresses($hdr->cc ?? []),
            'subject' => $subject,
            'date' => (int)($ov->udate ?? 0),
            'code' => mb_find_code($subject, $plain),
            'html' => $html,
            'cids' => $cids,
            'text' => $plain,
            'attachments' => array_map(fn($a) => [
                'part' => $a['part'], 'name' => $a['name'], 'type' => $a['type'], 'size' => $a['size'],
            ], $w['attachments']),
        ];
    } finally {
        mb_imap_close($mb);
    }
}

function mb_addresses(array $list): array
{
    $out = [];
    foreach ($list as $a) {
        if (!isset($a->mailbox, $a->host)) continue;
        $out[] = [
            'name' => isset($a->personal) ? trim(mb_decode_header($a->personal), " \"'") : '',
            'email' => strtolower($a->mailbox . '@' . $a->host),
        ];
    }
    return $out;
}

/** צרופה — רק חלק שבאמת מופיע כצרופה במבנה ההודעה. */
function mb_attachment(array $box, int $uid, string $part): array
{
    $mb = mb_imap_open($box);
    try {
        $s = @imap_fetchstructure($mb, $uid, FT_UID);
        if (!$s) throw new MbImapError('ההודעה לא נמצאה');
        foreach (mb_walk($s)['attachments'] as $a) {
            if ($a['part'] === $part) {
                return ['name' => $a['name'], 'type' => $a['type'], 'data' => mb_fetch_part($mb, $uid, $a)];
            }
        }
        throw new MbImapError('הצרופה לא נמצאה');
    } finally {
        mb_imap_close($mb);
    }
}
