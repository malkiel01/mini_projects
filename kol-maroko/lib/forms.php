<?php
/**
 * טפסים ותיבת פניות (פרוסה M5; docs/spec-v2/06-data.md §3, slices/M5-forms-inbox.md).
 *
 * שלושה חלקים:
 *   (א) הרישום — סריקת HTML: כל data-kol-form בלי action משלו, שמות השדות שבו ומה required. נשמר בגרסה הבנויה
 *       (pkg_builds.forms) ונבנה מהטיוטה לתצוגה החיה. form.php מקבל **רק** טופס ושדות שברישום.
 *   (ב) הקבלה — forms_receive(), הנקודה הציבורית (form.php). אנונימית: בלי session, בלי עוגייה, בלי מודול הכניסה.
 *       כל מגבלה נאכפת כאן, בשרת. התשובה — רק ok ו-reason. ה-IP — HMAC בלבד, בטבלת הקצב בלבד, 30 יום.
 *   (ג) התיבה — "📬 פניות" בסטודיו (api.php), המייל, ושורת המפתח.
 *
 * איפה הנתונים: form_entries / form_hits / form_rejects / forms_trash ב-data/kol.sqlite (מיגרציה 18); מפתח ה-HMAC —
 * data/secrets.json (forms_ip_key); ההגדרות — בטבלת settings. אין קובץ חדש ב-data/. snap_state אינו כולל פניות.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/devlog.php';

/** המגבלות (08 §4.2): ברירת מחדל, מינימום, מקסימום, והנוסח לבעל העמוד. */
const FORMS_LIMITS = [
    'bytes'  => [10240, 1024, 65536, 'גודל פנייה (בייטים)'],
    'fields' => [20, 1, 50, 'שדות בפנייה'],
    'chars'  => [5000, 50, 20000, 'תווים לשדה'],
    'ip_n'   => [5, 1, 100, 'פניות מאותה כתובת ב-10 דקות'],
    'hour_n' => [200, 1, 5000, 'פניות בשעה, בסך הכול'],
    'day_n'  => [1000, 1, 20000, 'פניות ביום (תקרה — מעליה הטופס סגור עד חצות UTC)'],
    'min_t'  => [3, 0, 60, 'שניות מינימום מטעינת העמוד ועד השליחה'],
];
const FORMS_IP_WINDOW = 600;
const FORMS_IP_DAYS = 30;
const FORMS_TEST_DAYS = 7;
const FORMS_REG_BUILDS = 10;                    // האיחוד של 10 הגרסאות האחרונות שנשלחו (חזרה לגרסה נעשית בתוסף)
const FORMS_MAIL_EVERY = 900;                   // לכל היותר מייל אחד ל-15 דקות
const FORMS_MAIL_FAILS = 3;                     // אחרי 3 כישלונות ברצף — כבוי אוטומטית
const FORMS_RESERVED = ['_form', '_t', '_page', '_trap'];
const FORMS_NAME_RE = '/^[\p{L}\p{N}_.:\[\]-]{1,64}$/u';
const FORMS_REJECT_REASONS = ['rate', 'size', 'invalid', 'closed', 'spam', 'unknown'];
const FORMS_PAGE = 50;

// ── (א) הרישום ────────────────────────────────────────────

/** ערך של תכונה מתוך מחרוזת התכונות של תגית, או null. */
function forms_attr(string $attrs, string $name): ?string
{
    if (!preg_match('/(?:^|\s)' . preg_quote($name, '/') . '(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?(?=[\s\/>]|$)/i', $attrs, $m)) return null;
    return html_entity_decode(($m[1] ?? '') . ($m[2] ?? '') . ($m[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * הטפסים שבמסמך: שם ← {fields, required, external, trap, files}. סורק תגיות בלבד: הערות ותוכן של script/style/textarea
 * וכו' — מדולגים. טופס עם action — external (הדפדפן שולח לשם, לא לכאן; [ש-12]) ואינו נרשם לקבלה.
 */
function forms_scan(string $html): array
{
    $out = [];
    $cur = null;
    $i = 0;
    $raw = ['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes'];
    $re = '~<(?:(!--)|(/?)([A-Za-z][A-Za-z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>)~A';
    while (($at = strpos($html, '<', $i)) !== false) {
        if (!preg_match($re, $html, $m, 0, $at)) { $i = $at + 1; continue; }
        $i = $at + strlen($m[0]);
        if (($m[1] ?? '') === '!--') { $c = strpos($html, '-->', $at + 4); if ($c === false) break; $i = $c + 3; continue; }
        $name = strtolower($m[3]);
        $close = $m[2] === '/';
        $attrs = $m[4] ?? '';
        if ($name === 'form') {
            if ($close) { $cur = null; continue; }
            $fname = forms_attr($attrs, 'data-kol-form');
            $cur = null;
            if ($fname !== null && preg_match(FORMS_NAME_RE, $fname)) {
                $action = forms_attr($attrs, 'action');
                $out[$fname] ??= ['fields' => [], 'required' => [], 'external' => false, 'trap' => false, 'files' => false];
                if ($action !== null && trim($action) !== '') $out[$fname]['external'] = true;
                $cur = $fname;
            }
        } elseif (!$close && $cur !== null && in_array($name, ['input', 'textarea', 'select', 'button'], true)) {
            $type = strtolower((string)forms_attr($attrs, 'type'));
            $field = forms_attr($attrs, 'name');
            if ($name === 'button' || in_array($type, ['submit', 'button', 'reset', 'image'], true)) { /* לא שדה */ }
            elseif ($type === 'file') $out[$cur]['files'] = true;
            elseif (forms_attr($attrs, 'data-kol-trap') !== null) $out[$cur]['trap'] = true;
            elseif ($field !== null && preg_match(FORMS_NAME_RE, $field) && !in_array($field, FORMS_RESERVED, true)) {
                if (!in_array($field, $out[$cur]['fields'], true)) $out[$cur]['fields'][] = $field;
                if (forms_attr($attrs, 'required') !== null && !in_array($field, $out[$cur]['required'], true) && !in_array($type, ['checkbox', 'radio'], true)) {
                    $out[$cur]['required'][] = $field;
                }
            }
        }
        if (!$close && in_array($name, $raw, true)) {
            if (!preg_match('~</' . $name . '[\s/>]~i', $html, $c, PREG_OFFSET_CAPTURE, $i)) break;
            $i = $c[0][1];
        }
    }
    return $out;
}

/** איחוד של רישומים: שדות — איחוד; required — רק מה שחובה בכולם (גולש מגרסה ישנה לא נדחה על שדה שנוסף אחר כך). */
function forms_merge(array $a, array $b): array
{
    foreach ($b as $name => $f) {
        if (!isset($a[$name])) { $a[$name] = $f; continue; }
        $a[$name]['fields'] = array_values(array_unique([...$a[$name]['fields'], ...$f['fields']]));
        $a[$name]['required'] = array_values(array_intersect($a[$name]['required'], $f['required']));
        foreach (['external', 'trap', 'files'] as $k) $a[$name][$k] = !empty($a[$name][$k]) || !empty($f[$k]);
    }
    return $a;
}

/** הכתובת של form.php — מוחלטת (העמוד באתר נמצא בדומיין אחר). */
function forms_endpoint(bool $test = false): string
{
    $host = (string)preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'mbe-plus.com'));
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/mini_projects/kol-maroko/api.php'))), '/');
    $dir = (string)preg_replace('~/preview(/.*)?$~', '', $dir);
    $scheme = preg_match('/^(127\.0\.0\.1|localhost)(:\d+)?$/', $host) ? 'http' : 'https';
    return "$scheme://$host$dir/form.php" . ($test ? '?test=1' : '');
}

/** <meta name="kol-form-endpoint"> אחרי <head> — באותה שורה (מספרי השורות נשמרים). מי שכתב meta משלו — נשאר שלו. */
function forms_meta_inject(string $html, string $url): string
{
    if (!str_contains($html, 'data-kol-form') || preg_match('/<meta\b[^>]*\bname\s*=\s*["\']?kol-form-endpoint\b/i', $html)) return $html;
    $tag = '<meta name="kol-form-endpoint" content="' . htmlspecialchars($url, ENT_QUOTES) . '">';
    foreach (['/<head(?:\s[^>]*)?>/i', '/<html(?:\s[^>]*)?>/i', '/<!doctype[^>]*>/i'] as $re) {
        if (preg_match($re, $html, $m, PREG_OFFSET_CAPTURE)) {
            $at = $m[0][1] + strlen($m[0][0]);
            // מיד אחרי תגיות ה-script שהתצוגה החיה הוסיפה באותה שורה
            if (preg_match('~\G(?:<script src="[^"]*"></script>)+~', $html, $s, 0, $at)) $at += strlen($s[0]);
            return substr($html, 0, $at) . $tag . substr($html, $at);
        }
    }
    return $tag . $html;
}

/** הבנייה (M1, build_finish): רישום הטפסים מהחבילה, ו-meta הכתובת בכל עמוד שיש בו טופס. מחזיר [files, forms, notes]. */
function forms_build(array $files): array
{
    $forms = [];
    $notes = [];
    $url = forms_endpoint();
    foreach ($files as $p => $f) {
        if ($f['src'] === 'media' || !preg_match('/\.html?$/i', $p)) continue;
        $html = pf_blob_get($f['sha']);
        if (!str_contains($html, 'data-kol-form')) continue;
        $forms = forms_merge($forms, forms_scan($html));
        $new = forms_meta_inject($html, $url);
        if ($new === $html) continue;
        $files[$p] = ['sha' => pf_blob_put($new), 'size' => strlen($new), 'type' => $f['type'], 'src' => 'prerender',
            'orig' => $f['src'] === 'prerender' ? $f['orig'] : $f['sha'], 'lists' => $f['lists'] ?? 0, 'form_meta' => true];
    }
    foreach ($forms as $name => $f) {
        if ($f['external']) { $notes[] = ['kind' => 'info', 'msg' => "הטופס \"$name\" שולח לכתובת חיצונית (action) — פניות ממנו אינן מגיעות ל-📬 פניות"]; continue; }
        $notes[] = ['kind' => 'info', 'msg' => "טפסים: $name (" . implode(', ', $f['fields']) . ')'];
        if ($f['files']) $notes[] = ['kind' => 'warn', 'msg' => "בטופס \"$name\" יש שדה קובץ — קבצים מצורפים אינם מתקבלים, והשליחה תידחה"];
    }
    return [$files, $forms, $notes];
}

/** הרישום מהטיוטה (תצוגה חיה, ?test=1) — סריקה של קובצי ה-HTML שבמאגר, במטמון לפי הנתיבים וה-sha שלהם. */
function forms_draft(): array
{
    require_once __DIR__ . '/project.php';
    $rows = db()->query("SELECT path, sha FROM pf_files WHERE deleted_at IS NULL AND kind = 'file' ORDER BY path")->fetchAll();
    $rows = array_values(array_filter($rows, fn($r) => preg_match('/\.html?$/i', $r['path'])));
    $key = hash('sha256', json_encode($rows));
    $c = json_decode((string)setting('forms_draft_cache', ''), true);
    if (is_array($c) && ($c['key'] ?? '') === $key) return $c['forms'];
    $forms = [];
    foreach ($rows as $r) {
        try { $html = pf_blob_get($r['sha']); } catch (Throwable) { continue; }
        if (str_contains($html, 'data-kol-form')) $forms = forms_merge($forms, forms_scan($html));
    }
    setting_set('forms_draft_cache', json_encode(['key' => $key, 'forms' => $forms], JSON_UNESCAPED_UNICODE));
    return $forms;
}

/** הרישום של מה שבאתר: איחוד forms של 10 הגרסאות האחרונות שנשלחו. */
function forms_live(): array
{
    $forms = [];
    foreach (db()->query('SELECT forms FROM pkg_builds WHERE sent_at IS NOT NULL AND forms IS NOT NULL ORDER BY sent_at DESC, id DESC LIMIT ' . FORMS_REG_BUILDS)->fetchAll() as $r) {
        $forms = forms_merge($forms, json_decode((string)$r['forms'], true) ?: []);
    }
    return $forms;
}

// ── (ב) הקבלה ─────────────────────────────────────────────

function forms_limits(): array
{
    $saved = json_decode((string)setting('forms_limits', '{}'), true) ?: [];
    $out = [];
    foreach (FORMS_LIMITS as $k => [$def, $min, $max]) $out[$k] = isset($saved[$k]) ? max($min, min($max, (int)$saved[$k])) : $def;
    return $out;
}

/** מפתח ה-HMAC ל-IP — ב-data/secrets.json. נוצר אם אין. */
function forms_ip_key(): string
{
    require_once __DIR__ . '/secrets.php';
    $s = secrets_load();
    if (!is_string($s['forms_ip_key'] ?? null) || strlen($s['forms_ip_key']) < 32) {
        $s['forms_ip_key'] = bin2hex(random_bytes(32));
        secrets_save($s);
    }
    return $s['forms_ip_key'];
}

function forms_ip_hash(string $ip): string
{
    return hash_hmac('sha256', $ip, forms_ip_key());
}

function forms_reject_count(string $reason, int $n = 1): void
{
    db()->prepare('INSERT INTO form_rejects (day, reason, n) VALUES (?, ?, ?) ON CONFLICT(day, reason) DO UPDATE SET n = n + excluded.n')
        ->execute([gmdate('Y-m-d'), $reason, $n]);
}

/** ניקוי זול בכל בקשה: הקצב — 30 יום; פניות בדיקה — 7 ימים. */
function forms_clean(): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM form_hits WHERE at < ?')->execute([time() - FORMS_IP_DAYS * 86400]);
    $pdo->prepare('DELETE FROM form_entries WHERE test = 1 AND at < ?')->execute([gmdate('Y-m-d\TH:i:s\Z', time() - FORMS_TEST_DAYS * 86400)]);
}

/**
 * פנייה אחת. $post — $_POST; $files — $_FILES; $len — Content-Length; $ip — REMOTE_ADDR. מחזיר [קוד HTTP, תשובה].
 * התשובה — רק {"ok":true} או {"ok":false,"reason":…}. ספאם ← ok בלי לשמור.
 */
function forms_receive(array $post, array $files, int $len, string $ip, bool $test): array
{
    $pdo = db();
    forms_clean();
    $L = forms_limits();
    $no = function (string $reason, int $code, string $why) use ($test): array {
        forms_reject_count($reason);
        devlog_write('warn', 'forms', 'form.reject', ['message' => "$reason: $why" . ($test ? ' (בדיקה)' : ''), 'action' => 'form', 'status' => $code]);
        return [$code, $reason === 'spam' ? ['ok' => true] : ['ok' => false, 'reason' => $reason]];
    };

    // הקצב: קודם הכתובת (מי שנחסם אינו נספר בסך הכול — כתובת אחת אינה סוגרת את הטופס לכולם), אחר כך השעה והיום
    $h = forms_ip_hash($ip);
    $now = time();
    $q = $pdo->prepare('SELECT COUNT(*) FROM form_hits WHERE ip_hash = ? AND at >= ?');
    $q->execute([$h, $now - FORMS_IP_WINDOW]);
    if ((int)$q->fetchColumn() >= $L['ip_n']) return $no('rate', 429, 'יותר מ-' . $L['ip_n'] . ' פניות מאותה כתובת ב-10 דקות');
    $c = $pdo->prepare('SELECT COUNT(*) FROM form_hits WHERE at >= ?');
    $c->execute([strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC')]);
    if ((int)$c->fetchColumn() >= $L['day_n']) return $no('closed', 503, 'התקרה היומית (' . $L['day_n'] . ') הושגה');
    $c->execute([$now - 3600]);
    if ((int)$c->fetchColumn() >= $L['hour_n']) return $no('rate', 429, 'יותר מ-' . $L['hour_n'] . ' פניות בשעה האחרונה');
    $pdo->prepare('INSERT INTO form_hits (ip_hash, at) VALUES (?, ?)')->execute([$h, $now]);

    // גודל ומבנה
    if ($files) return $no('invalid', 400, 'קובץ מצורף');
    $bytes = 0;
    foreach ($post as $k => $v) {
        if (!is_string($v)) return $no('invalid', 400, 'שדה שאינו טקסט');
        $bytes += strlen((string)$k) + strlen($v);
    }
    if ($len > $L['bytes'] || $bytes > $L['bytes']) return $no('size', 413, 'פנייה גדולה מ-' . $L['bytes'] . ' בייטים');
    $user = array_diff_key($post, array_flip(FORMS_RESERVED));
    if (count($user) > $L['fields']) return $no('size', 413, 'יותר מ-' . $L['fields'] . ' שדות');
    foreach ($post as $k => $v) {
        if (!mb_check_encoding((string)$k, 'UTF-8') || !mb_check_encoding($v, 'UTF-8')) return $no('invalid', 400, 'קידוד שאינו UTF-8');
        if (mb_strlen($v) > $L['chars']) return $no('size', 413, 'שדה ארוך מ-' . $L['chars'] . ' תווים');
    }

    // הטופס — רק מה שברישום
    $form = (string)($post['_form'] ?? '');
    if (!preg_match(FORMS_NAME_RE, $form)) return $no('invalid', 400, 'בלי שם טופס');
    $reg = ($test ? forms_draft() : forms_live())[$form] ?? null;
    if (!$reg || $reg['external']) return $no('invalid', 400, "הטופס \"$form\" אינו " . ($test ? 'בטיוטה' : 'בגרסה שנשלחה לאתר'));

    // ספאם — עונים ok, לא שומרים
    if (trim((string)($post['_trap'] ?? '')) !== '') return $no('spam', 200, 'שדה המלכודת מלא');
    $t = $post['_t'] ?? '';
    if (!preg_match('/^\d{1,12}$/', $t) || (int)$t < $L['min_t'] * 1000) return $no('spam', 200, 'נשלח מהר מדי אחרי טעינת העמוד');

    $keep = [];
    $unknown = 0;
    foreach ($user as $k => $v) {
        if (!in_array($k, $reg['fields'], true)) { $unknown++; continue; }
        $keep[$k] = str_replace("\0", '', $v);
    }
    foreach ($reg['required'] as $k) if (trim($keep[$k] ?? '') === '') return $no('invalid', 400, "חסר שדה חובה \"$k\"");
    if ($unknown) forms_reject_count('unknown', $unknown);

    $word = null;
    $find = $pdo->prepare('SELECT id FROM words WHERE id = ?');
    if (preg_match('/^\d{1,12}$/', trim($keep['word_id'] ?? ''))) { $find->execute([(int)$keep['word_id']]); $word = $find->fetchColumn() ?: null; }
    if (!$word && preg_match('/^\d{1,12}$/', trim($keep['wp_id'] ?? ''))) {
        $w = $pdo->prepare('SELECT id FROM words WHERE wp_id = ? LIMIT 1');
        $w->execute([(int)$keep['wp_id']]);
        $word = $w->fetchColumn() ?: null;
    }
    $page = mb_substr((string)preg_replace('/[\x00-\x1f\x7f]/', '', (string)($post['_page'] ?? '')), 0, 255);
    $pdo->prepare('INSERT INTO form_entries (form, fields, word_id, page, test, at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$form, json_encode($keep, JSON_UNESCAPED_UNICODE), $word === null ? null : (int)$word, $page, $test ? 1 : 0, now_iso()]);
    if (!$test) forms_mail_tick();
    return [200, ['ok' => true]];
}

// ── (ג) המייל ─────────────────────────────────────────────

function forms_mail_state(): array
{
    $s = json_decode((string)setting('forms_mail', '{}'), true) ?: [];
    return $s + ['on' => false, 'from' => '', 'to' => [], 'fails' => 0, 'last_sent' => null, 'last_sent_id' => 0, 'last_test' => null, 'last_error' => null, 'auto_off' => null];
}

function forms_mail_save(array $s): void
{
    setting_set('forms_mail', json_encode($s, JSON_UNESCAPED_UNICODE));
}

/** כתובת מייל תקינה ובלי שבירת שורה — אין כותרת מהקלט. */
function forms_email_ok(string $e): bool
{
    return strlen($e) <= 254 && !preg_match('/[\r\n\0,;<>"]/', $e) && filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
}

/** שליחה אחת. בבדיקות — לקובץ (KOL_TEST_MAIL), לא mail(). מחזיר [ok, שגיאה]. */
function forms_mail_send(array $to, string $subject, string $body, string $from): array
{
    $to = array_values(array_filter($to, 'forms_email_ok'));
    if (!$to) return [false, 'אין נמענים'];
    if (!forms_email_ok($from)) return [false, 'כתובת השולח אינה תקינה'];
    $subj = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    $headers = "From: $from\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
    $data = chunk_split(base64_encode($body));
    if ($fake = getenv('KOL_TEST_MAIL')) {
        $ok = getenv('KOL_TEST_MAIL_FAIL') ? false : (bool)file_put_contents($fake, json_encode(['to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers]) . "\n", FILE_APPEND | LOCK_EX);
        return [$ok, $ok ? null : 'mail() החזיר false (בדיקה)'];
    }
    $err = null;
    set_error_handler(function (int $no, string $msg) use (&$err): bool { $err = $msg; return true; });
    try { $ok = @mail(implode(', ', $to), $subj, $data, $headers); } finally { restore_error_handler(); }
    return [$ok, $ok ? null : ($err ?: (error_get_last()['message'] ?? 'mail() החזיר false'))];
}

/** הקישור לתיבה בסטודיו. */
function forms_inbox_url(): string
{
    return (string)preg_replace('~/form\.php(\?.*)?$~', '/index.php#/inbox', forms_endpoint());
}

/**
 * צבירה: לכל היותר מייל אחד ל-15 דקות, ורק תקציר ("3 פניות חדשות בטופס feedback") — בלי תוכן הפנייה, בלי פניות 🧪.
 * נקרא אחרי כל פנייה ובכל פתיחה של התיבה (כדי שפנייה אחרונה לא תחכה לפנייה הבאה).
 */
function forms_mail_tick(): void
{
    try {
        $s = forms_mail_state();
        if (!$s['on'] || !$s['to']) return;
        if ($s['last_sent'] && strtotime($s['last_sent']) > time() - FORMS_MAIL_EVERY) return;
        $st = db()->prepare('SELECT form, COUNT(*) n, MAX(id) mx FROM form_entries WHERE test = 0 AND id > ? GROUP BY form ORDER BY form');
        $st->execute([(int)$s['last_sent_id']]);
        $rows = $st->fetchAll();
        if (!$rows) return;
        $total = array_sum(array_column($rows, 'n'));
        $lines = array_map(fn($r) => "{$r['n']} פניות חדשות בטופס \"{$r['form']}\"", $rows);
        $body = "קול מרוקו · 📬 פניות\n\n" . implode("\n", $lines) . "\n\nלתיבה: " . forms_inbox_url() . "\n\n(תוכן הפניות — רק בתיבה.)\n";
        [$ok, $err] = forms_mail_send($s['to'], "קול מרוקו: $total פניות חדשות", $body, $s['from']);
        $s = forms_mail_state();
        if ($ok) {
            $s['fails'] = 0;
            $s['last_sent'] = now_iso();
            $s['last_sent_id'] = (int)max(array_column($rows, 'mx'));
            $s['last_error'] = null;
        } else {
            $s['fails'] = (int)$s['fails'] + 1;
            $s['last_error'] = ['at' => now_iso(), 'error' => mb_substr((string)$err, 0, 300)];
            $s['last_sent'] = now_iso();             // גם כישלון ממתין 15 דקות — לא מנסים בכל פנייה
            devlog_write('error', 'forms', 'form.mail_failed', ['message' => mb_substr((string)$err, 0, 300) . " · כישלון {$s['fails']} ברצף"]);
            if ($s['fails'] >= FORMS_MAIL_FAILS) {
                $s['on'] = false;
                $s['auto_off'] = now_iso();
                db()->prepare('INSERT INTO notices (role_to, kind, text, by_name, at) VALUES (?, ?, ?, ?, ?)')
                    ->execute(['owner', 'mail', 'המייל על פניות חדשות כובה אוטומטית אחרי ' . FORMS_MAIL_FAILS . ' כישלונות ברצף. המונה ב-📬 פניות ממשיך. מסך המפתח ← 📬 טפסים', '', now_iso()]);
            }
        }
        forms_mail_save($s);
    } catch (Throwable $e) {
        devlog_write('error', 'forms', 'form.mail_failed', ['message' => $e->getMessage()]);
    }
}

// ── (ג) התיבה ─────────────────────────────────────────────

/** WHERE לפי הסינון — אותו סינון לרשימה ולייצוא. */
function forms_where(array $q): array
{
    $w = [];
    $a = [];
    $f = (string)($q['filter'] ?? '');
    $w[] = $f === 'test' ? 'e.test = 1' : 'e.test = 0';
    if ($f === 'unread') $w[] = 'e.read_at IS NULL';
    if ($f === 'unhandled') $w[] = 'e.handled_at IS NULL';
    if (($q['form'] ?? '') !== '') { $w[] = 'e.form = ?'; $a[] = (string)$q['form']; }
    $s = trim((string)($q['q'] ?? ''));
    if ($s !== '') { $w[] = "(e.fields LIKE ? ESCAPE '\\' OR w.word LIKE ? ESCAPE '\\')"; $like = '%' . addcslashes(mb_substr($s, 0, 100), '%_\\') . '%'; $a[] = $like; $a[] = $like; }
    return [implode(' AND ', $w), $a];
}

function forms_entry_public(array $r): array
{
    return ['id' => (int)$r['id'], 'form' => $r['form'], 'fields' => json_decode($r['fields'], true) ?: [], 'page' => $r['page'], 'test' => (bool)$r['test'], 'at' => $r['at'],
        'read_at' => $r['read_at'], 'read_by' => $r['read_by'], 'handled_at' => $r['handled_at'], 'handled_by' => $r['handled_by'],
        'word' => $r['word_id'] === null ? null : ['id' => (int)$r['word_id'], 'word' => $r['word'] ?? null]];
}

function forms_unread(): int
{
    return (int)db()->query('SELECT COUNT(*) FROM form_entries WHERE test = 0 AND read_at IS NULL')->fetchColumn();
}

/** "📬 פניות": הרשימה לפי הסינון, הטפסים והמונים. editor ומעלה. ?count=1 — רק המונה (ללשונית). */
function inbox_list(array $user, array $q): array
{
    forms_clean();
    forms_mail_tick();
    if (!empty($q['count'])) return ['unread' => forms_unread()];
    [$where, $args] = forms_where($q);
    $off = max(0, (int)($q['offset'] ?? 0));
    $st = db()->prepare("SELECT e.*, w.word FROM form_entries e LEFT JOIN words w ON w.id = e.word_id WHERE $where ORDER BY e.id DESC LIMIT " . FORMS_PAGE . " OFFSET $off");
    $st->execute($args);
    $rows = array_map('forms_entry_public', $st->fetchAll());
    $cnt = db()->prepare("SELECT COUNT(*) FROM form_entries e LEFT JOIN words w ON w.id = e.word_id WHERE $where");
    $cnt->execute($args);
    $forms = db()->query('SELECT form, COUNT(*) n, SUM(read_at IS NULL) unread FROM form_entries WHERE test = 0 GROUP BY form ORDER BY form')->fetchAll();
    $live = forms_live();
    $ext = array_keys(array_filter($live, fn($f) => $f['external']));
    $mail = forms_mail_state();
    return ['entries' => $rows, 'total' => (int)$cnt->fetchColumn(), 'unread' => forms_unread(),
        'forms' => array_map(fn($f) => ['form' => $f['form'], 'n' => (int)$f['n'], 'unread' => (int)$f['unread']], $forms),
        'registered' => array_keys(array_filter($live, fn($f) => !$f['external'])), 'external' => $ext,
        'tests' => (int)db()->query('SELECT COUNT(*) FROM form_entries WHERE test = 1')->fetchColumn(),
        'can_delete' => ROLE_RANK[$user['role']] >= ROLE_RANK['publisher'],
        'settings' => ['to' => $mail['to'], 'mail_on' => (bool)$mail['on'], 'candidates' => forms_mail_candidates($user)]];
}

function forms_ids(array $b): array
{
    $ids = is_array($b['ids'] ?? null) ? $b['ids'] : [$b['id'] ?? 0];
    return array_values(array_unique(array_filter(array_map('intval', array_slice($ids, 0, 500)))));
}

/** נקרא / לא נקרא, טופל / לא טופל — עם מי ומתי. editor ומעלה. */
function inbox_mark(array $user, array $b): array
{
    $ids = forms_ids($b) ?: throw new ApiError('לא נבחרה פנייה');
    $set = [];
    $args = [];
    if (array_key_exists('read', $b)) { $set[] = 'read_at = ?, read_by = ?'; array_push($args, $b['read'] ? now_iso() : null, $b['read'] ? ($user['name'] ?? '') : null); }
    if (array_key_exists('handled', $b)) { $set[] = 'handled_at = ?, handled_by = ?'; array_push($args, $b['handled'] ? now_iso() : null, $b['handled'] ? ($user['name'] ?? '') : null); }
    if (!$set) throw new ApiError('בקשה לא תקינה');
    $in = implode(',', array_fill(0, count($ids), '?'));
    // "נקרא" אינו דורס את מי שקרא ראשון
    $sql = 'UPDATE form_entries SET ' . implode(', ', $set) . " WHERE id IN ($in)" . (!empty($b['read']) && !array_key_exists('handled', $b) ? ' AND read_at IS NULL' : '');
    db()->prepare($sql)->execute([...$args, ...$ids]);
    if (array_key_exists('handled', $b)) audit_as($user, 'inbox_handled', ($b['handled'] ? 'טופל' : 'לא טופל') . ' · ' . count($ids) . ' פניות');
    return ['unread' => forms_unread()];
}

/** לסל (forms_trash), כאצווה אחת שאפשר לשחזר יחד. */
function forms_to_trash(array $user, array $ids, string $batch): int
{
    if (!$ids) return 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $get = $pdo->prepare('SELECT * FROM form_entries WHERE id = ?');
        $put = $pdo->prepare('INSERT INTO forms_trash (entry_id, row, batch, at, by_name) VALUES (?, ?, ?, ?, ?)');
        $del = $pdo->prepare('DELETE FROM form_entries WHERE id = ?');
        $n = 0;
        foreach ($ids as $id) {
            $get->execute([$id]);
            if (!($r = $get->fetch())) continue;
            $put->execute([$id, json_encode($r, JSON_UNESCAPED_UNICODE), $batch, now_iso(), $user['name'] ?? '']);
            $del->execute([$id]);
            $n++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $n;
}

/** מחיקה לסל — publisher ומעלה. */
function inbox_delete(array $user, array $b): array
{
    $ids = forms_ids($b) ?: throw new ApiError('לא נבחרה פנייה');
    $n = forms_to_trash($user, $ids, 'del-' . bin2hex(random_bytes(6)));
    audit_as($user, 'inbox_delete', "$n פניות לסל");
    return ['deleted' => $n, 'unread' => forms_unread()];
}

/** "מחק פניות ישנות מ-…" — לסל, כאצווה אחת עם המספר. publisher ומעלה. preview — רק כמה. */
function inbox_purge(array $user, array $b): array
{
    $before = (string)($b['before'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $before) || !strtotime($before . ' UTC')) throw new ApiError('תאריך לא תקין');
    $st = db()->prepare('SELECT id FROM form_entries WHERE test = 0 AND at < ? ORDER BY id');
    $st->execute([$before . 'T00:00:00Z']);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!empty($b['preview'])) return ['count' => count($ids)];
    if (($b['confirm'] ?? null) !== count($ids)) throw new ApiError('המספר השתנה מאז האישור — נסו שוב', 409, ['count' => count($ids)]);
    $batch = 'old-' . $before . '-' . bin2hex(random_bytes(4));
    $n = forms_to_trash($user, $ids, $batch);
    audit_as($user, 'inbox_purge', "$n פניות מלפני $before לסל (אצווה $batch)");
    return ['deleted' => $n, 'batch' => $batch, 'unread' => forms_unread()];
}

/** תא CSV שאינו נוסחה: =, +, -, @, טאב ו-CR בתחילתו (גם אחרי רווחים) — מקבל גרש לפניו. */
function forms_csv_cell(mixed $v): string
{
    $v = (string)$v;
    return preg_match('/^[\s]*[=+\-@\t\r]/u', $v) || preg_match('/^[\t\r]/', $v) ? "'" . $v : $v;
}

/** ייצוא CSV של הסינון הנוכחי (UTF-8 עם BOM). editor ומעלה. */
function inbox_csv(array $user, array $q): never
{
    [$where, $args] = forms_where($q);
    $st = db()->prepare("SELECT e.*, w.word FROM form_entries e LEFT JOIN words w ON w.id = e.word_id WHERE $where ORDER BY e.id DESC LIMIT 50000");
    $st->execute($args);
    $rows = array_map('forms_entry_public', $st->fetchAll());
    $keys = [];
    foreach ($rows as $r) foreach (array_keys($r['fields']) as $k) $keys[$k] = true;
    $keys = array_keys($keys);
    audit_as($user, 'inbox_csv', count($rows) . ' פניות');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kol-maroko-inbox-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['id', 'form', 'at', 'word', 'page', 'read_by', 'handled_by', 'handled_at', ...$keys], ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, array_map('forms_csv_cell', [$r['id'], $r['form'], $r['at'], $r['word']['word'] ?? '', $r['page'], $r['read_by'] ?? '', $r['handled_by'] ?? '', $r['handled_at'] ?? '',
            ...array_map(fn($k) => $r['fields'][$k] ?? '', $keys)]), ',', '"', '');
    }
    fclose($out);
    exit;
}

/** מי אפשר לבחור כנמען: חברי האפליקציה עם מייל, ומי ששומר (המייל שלו מהשער). */
function forms_mail_candidates(array $user): array
{
    $out = [];
    foreach (db()->query("SELECT name, email FROM members WHERE email IS NOT NULL AND email <> '' ORDER BY name")->fetchAll() as $m) {
        if (forms_email_ok((string)$m['email'])) $out[strtolower($m['email'])] = ['email' => strtolower($m['email']), 'name' => $m['name']];
    }
    $mine = strtolower((string)($user['email'] ?? ''));
    if ($mine !== '' && forms_email_ok($mine)) $out[$mine] ??= ['email' => $mine, 'name' => ($user['name'] ?? '') . ' (אני)'];
    return array_values($out);
}

/**
 * הגדרות: נמענים (publisher, בהגדרות התיבה — רק מתוך המועמדים); המגבלות, כתובת השולח והדלקת המייל — המפתח בלבד.
 * GET (בלי גוף) — המצב.
 */
function forms_settings(array $user, array $b, bool $developer): array
{
    $s = forms_mail_state();
    if (array_key_exists('to', $b)) {
        if (ROLE_RANK[$user['role']] < ROLE_RANK['publisher']) throw new ApiError('אין לך הרשאה לפעולה הזו', 403);
        $ok = array_column(forms_mail_candidates($user), 'email');
        $to = [];
        foreach (array_slice(is_array($b['to']) ? $b['to'] : [], 0, 20) as $e) {
            $e = strtolower(trim((string)$e));
            if (!in_array($e, $ok, true)) throw new ApiError('נמען שאינו חבר באפליקציה');
            $to[] = $e;
        }
        $s['to'] = array_values(array_unique($to));
        audit_as($user, 'forms_settings', 'נמענים: ' . count($s['to']));
    }
    if (array_key_exists('limits', $b) || array_key_exists('from', $b) || array_key_exists('mail_on', $b)) {
        if (!$developer) throw new ApiError('המסך הזה פתוח למפתח בלבד', 403);
        if (is_array($b['limits'] ?? null)) {
            $cur = forms_limits();
            foreach ($b['limits'] as $k => $v) {
                if (!isset(FORMS_LIMITS[$k])) throw new ApiError('מגבלה לא מוכרת');
                [, $min, $max, $label] = FORMS_LIMITS[$k];
                if (!is_int($v) && !(is_string($v) && preg_match('/^\d{1,6}$/', $v))) throw new ApiError("$label: מספר שלם");
                if ((int)$v < $min || (int)$v > $max) throw new ApiError("$label: בין $min ל-$max");
                $cur[$k] = (int)$v;
            }
            setting_set('forms_limits', json_encode($cur));
            audit_as($user, 'forms_limits', json_encode($cur));
        }
        if (array_key_exists('from', $b)) {
            $from = trim((string)$b['from']);
            if ($from !== '' && !forms_email_ok($from)) throw new ApiError('כתובת השולח אינה תקינה');
            $s['from'] = $from;
        }
        if (array_key_exists('mail_on', $b)) {
            if ($b['mail_on'] && (!$s['from'] || !($s['last_test']['ok'] ?? false))) throw new ApiError('קודם "שלח מייל בדיקה" שהצליח, עם כתובת שולח');
            $s['on'] = (bool)$b['mail_on'];
            if ($s['on']) { $s['fails'] = 0; $s['auto_off'] = null; $s['last_sent_id'] = (int)db()->query('SELECT COALESCE(MAX(id), 0) FROM form_entries')->fetchColumn(); }
            audit_as($user, 'forms_mail', $s['on'] ? 'המייל הודלק' : 'המייל כובה');
        }
    }
    forms_mail_save($s);
    return ['settings' => ['to' => $s['to'], 'mail_on' => (bool)$s['on'], 'candidates' => forms_mail_candidates($user)]];
}

/** "שלח מייל בדיקה" — המפתח בלבד. התוצאה נרשמת ומוצגת. */
function forms_mail_test(array $dev, array $b): array
{
    $s = forms_mail_state();
    $from = trim((string)($b['from'] ?? $s['from']));
    $to = strtolower(trim((string)($b['to'] ?? ($dev['email'] ?? ''))));
    if (!forms_email_ok($from)) throw new ApiError('כתובת השולח אינה תקינה');
    if (!forms_email_ok($to)) throw new ApiError('כתובת הנמען אינה תקינה');
    [$ok, $err] = forms_mail_send([$to], 'קול מרוקו: מייל בדיקה', "זה מייל בדיקה מ-📬 פניות של קול מרוקו.\nאם הוא הגיע — אפשר להדליק את המייל על פניות חדשות במסך המפתח.\n", $from);
    $s = forms_mail_state();
    $s['from'] = $from;
    $s['last_test'] = ['at' => now_iso(), 'ok' => $ok, 'to' => $to, 'error' => $err === null ? null : mb_substr($err, 0, 300)];
    forms_mail_save($s);
    devlog_write($ok ? 'info' : 'error', 'forms', 'form.mail_test', ['message' => $ok ? 'mail() החזיר true' : 'mail() נכשל: ' . $err]);
    audit_as($dev, 'forms_mail_test', ($ok ? 'true' : 'false') . " · $to");
    return ['test' => $s['last_test']];
}

/** שורת M5 במסך המפתח (08 §5): פניות היום / 7 ימים, דחיות לפי סיבה, מצב המייל, המגבלות. */
function forms_dev_state(): array
{
    $pdo = db();
    $today = gmdate('Y-m-d');
    $n = function (string $since) use ($pdo): int {
        $st = $pdo->prepare('SELECT COUNT(*) FROM form_entries WHERE test = 0 AND at >= ?');
        $st->execute([$since]);
        return (int)$st->fetchColumn();
    };
    $rej = array_fill_keys(FORMS_REJECT_REASONS, ['today' => 0, 'week' => 0]);
    $st = $pdo->prepare('SELECT reason, SUM(CASE WHEN day = ? THEN n ELSE 0 END) t, SUM(n) w FROM form_rejects WHERE day >= ? GROUP BY reason');
    $st->execute([$today, gmdate('Y-m-d', time() - 6 * 86400)]);
    foreach ($st->fetchAll() as $r) $rej[$r['reason']] = ['today' => (int)$r['t'], 'week' => (int)$r['w']];
    $m = forms_mail_state();
    $limits = [];
    foreach (forms_limits() as $k => $v) $limits[] = ['id' => $k, 'value' => $v, 'label' => FORMS_LIMITS[$k][3], 'min' => FORMS_LIMITS[$k][1], 'max' => FORMS_LIMITS[$k][2], 'default' => FORMS_LIMITS[$k][0]];
    return ['today' => $n($today . 'T00:00:00Z'), 'week' => $n(gmdate('Y-m-d', time() - 6 * 86400) . 'T00:00:00Z'), 'rejects' => $rej,
        'mail' => ['on' => (bool)$m['on'], 'from' => $m['from'], 'to' => count($m['to']), 'last_sent' => $m['last_sent'], 'fails' => (int)$m['fails'],
            'last_test' => $m['last_test'], 'last_error' => $m['last_error'], 'auto_off' => $m['auto_off']],
        'limits' => $limits, 'endpoint' => forms_endpoint(), 'registered' => array_keys(forms_live())];
}

/** שורה ב"שירותי רקע" (dev_services). */
function forms_dev_service(): array
{
    $m = forms_mail_state();
    $res = $m['on'] ? 'דולק · ' . count($m['to']) . ' נמענים' : ($m['auto_off'] ? 'כובה אוטומטית אחרי ' . FORMS_MAIL_FAILS . ' כישלונות' : 'כבוי');
    if ($m['last_error']) $res .= ' · שגיאה אחרונה: ' . $m['last_error']['error'];
    return ['id' => 'forms_mail', 'name' => '📬 מייל על פניות חדשות', 'how' => 'אחרי פנייה (או כשפותחים את התיבה), לכל היותר פעם ב-15 דקות — תקציר בלי תוכן הפניות',
        'last' => $m['last_sent'], 'ok' => $m['on'] ? ((int)$m['fails'] === 0) : ($m['auto_off'] ? false : null), 'result' => $res, 'next' => null,
        'next_text' => $m['on'] ? 'בפנייה הבאה' : 'אין — המייל כבוי'];
}

// ── הסל (lib/restore.php) ─────────────────────────────────

function forms_trash_list(): array
{
    $rows = db()->query('SELECT batch, MIN(id) id, COUNT(*) n, MAX(at) at, MAX(by_name) by_name, MIN(row) row FROM forms_trash GROUP BY batch ORDER BY MAX(id) DESC LIMIT 500')->fetchAll();
    return array_map(function ($r) {
        $e = json_decode($r['row'], true) ?: [];
        return ['id' => (int)$r['id'], 'batch' => $r['batch'], 'n' => (int)$r['n'], 'at' => $r['at'], 'by' => $r['by_name'], 'form' => (string)($e['form'] ?? '?'),
            'old' => str_starts_with($r['batch'], 'old-')];
    }, $rows);
}

/** שחזור אצווה (פנייה אחת, או "מחק ישנות" כולה) — publisher ומעלה. */
function forms_trash_restore(array $user, int $id): void
{
    if (ROLE_RANK[$user['role']] < ROLE_RANK['publisher']) throw new ApiError('אין לך הרשאה לפעולה הזו', 403);
    $pdo = db();
    $st = $pdo->prepare('SELECT batch FROM forms_trash WHERE id = ?');
    $st->execute([$id]);
    $batch = $st->fetchColumn();
    if ($batch === false) throw new ApiError('לא נמצא בסל', 404);
    $rows = $pdo->prepare('SELECT * FROM forms_trash WHERE batch = ?');
    $rows->execute([$batch]);
    $cols = ['id', 'form', 'fields', 'word_id', 'page', 'test', 'at', 'read_at', 'read_by', 'handled_at', 'handled_by'];
    $ins = $pdo->prepare('INSERT INTO form_entries (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
    $insNew = $pdo->prepare('INSERT INTO form_entries (' . implode(',', array_slice($cols, 1)) . ') VALUES (' . implode(',', array_fill(0, count($cols) - 1, '?')) . ')');
    $exists = $pdo->prepare('SELECT 1 FROM form_entries WHERE id = ?');
    $n = 0;
    $pdo->beginTransaction();
    try {
        foreach ($rows->fetchAll() as $t) {
            $r = json_decode($t['row'], true);
            $vals = array_map(fn($c) => $r[$c] ?? null, $cols);
            $exists->execute([(int)$r['id']]);
            if ($exists->fetchColumn()) $insNew->execute(array_slice($vals, 1)); else $ins->execute($vals);
            $n++;
        }
        $pdo->prepare('DELETE FROM forms_trash WHERE batch = ?')->execute([$batch]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit_as($user, 'trash_restore', "פניות · $n");
}

function forms_trash_purge(array $owner, int $id): void
{
    $pdo = db();
    $st = $pdo->prepare('SELECT batch FROM forms_trash WHERE id = ?');
    $st->execute([$id]);
    $batch = $st->fetchColumn();
    if ($batch === false) throw new ApiError('לא נמצא בסל', 404);
    $pdo->prepare('DELETE FROM forms_trash WHERE batch = ?')->execute([$batch]);
    audit_as($owner, 'trash_purge', 'פניות');
}
