<?php
/**
 * השרת של "תפריט קייטרינג ניחוחות".
 *
 * פעולות ציבוריות: order (לקוח שולח הזמנה) ו-config (מגבלות הבחירה לתפריט).
 * כל השאר דורש התחברות מנהל.
 * האחסון: קובץ JSON לכל הזמנה ב-data/orders/, כך ששתי הזמנות שנשלחות באותו
 * רגע לא דורסות זו את זו. data/ מוחרגת מהפריסה ב-deploy.yml.
 *
 * הסיסמה נקבעת בכניסה הראשונה לממשק המנהל ונשמרת כגיבוב ב-data/config.json.
 * לאיפוס: למחוק את הקובץ מהשרת (מנהל הקבצים של cPanel) ולקבוע סיסמה מחדש.
 *
 * הגדרות המנהל (מיקום לזמני היום, מגבלות בחירה, מיילים, פרטי העסק) — data/settings.json.
 * לוגו שהמנהל העלה — data/logo.<png|jpg|webp>; בלעדיו assets/logo.jpg.
 * גופנים שהמנהל העלה (לתפריט להדפסה) — data/fonts/, והרשימה ב-data/fonts/fonts.json.
 * התפריט: כל עוד המנהל לא ערך אותו — assets/menu.json מהריפו. אחרי עריכה הוא
 * נשמר ב-data/menu.json, ומשם והלאה זה התפריט (הפריסה לא נוגעת בו).
 * מיילים יוצאים דרך mail() של PHP, שעובד בשרתי cPanel בלי הגדרה נוספת.
 *
 * התחברות המנהל נשמרת 4 חודשים, ומתחדשת בכל שימוש. קובצי ההתחברות נשמרים
 * ב-data/sessions/ ולא בתיקיית ברירת המחדל של השרת — שם cPanel מוחק אותם
 * אחרי 24 דקות בלי פעילות.
 * התראות לטלפון (Web Push) — push.php. תזכורות לפני אירועים — reminders.php.
 */

declare(strict_types=1);

const DATA_DIR    = __DIR__ . '/data';
const ORDERS_DIR  = DATA_DIR . '/orders';
const CONFIG_FILE = DATA_DIR . '/config.json';
const SETTINGS_FILE = DATA_DIR . '/settings.json';
const MENU_DEFAULT = __DIR__ . '/assets/menu.json';   // התפריט המקורי, בריפו
const MENU_FILE   = DATA_DIR . '/menu.json';             // התפריט שהמנהל ערך (מוחרג מהפריסה)
const MAX_ITEMS   = 200;
const SESSIONS_DIR = DATA_DIR . '/sessions';
const LOGIN_TTL   = 60 * 60 * 24 * 120;   // 4 חודשים
const LOGO_DEFAULT = __DIR__ . '/assets/logo.jpg';      // הלוגו המקורי, בריפו
const LOGO_TYPES  = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];
const FONTS_DIR   = DATA_DIR . '/fonts';                 // גופנים שהמנהל העלה (מוחרג מהפריסה)
const FONTS_FILE  = FONTS_DIR . '/fonts.json';
const FONT_TYPES  = ['ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2'];
const MAX_FONTS   = 20;

require __DIR__ . '/push.php';
require __DIR__ . '/reminders.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/* ── עזרים ──────────────────────────────────────────────────────── */

function fail(string $message, int $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function ok(array $payload = []) {
    echo json_encode(['success' => true] + $payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ensureDirs(): void {
    foreach ([DATA_DIR, ORDERS_DIR] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            fail('תיקיית data/ אינה ניתנת ליצירה — בדקו הרשאות בשרת', 500);
        }
    }
}

function body(): array {
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function text(array $row, string $key, int $max): string {
    $value = $row[$key] ?? '';
    if (!is_string($value)) return '';
    return mb_substr(trim($value), 0, $max);
}

/** מזהה הזמנה משמש כשם קובץ — ולכן נבדק בקפדנות. */
function validOrderId(mixed $id): bool {
    return is_string($id) && preg_match('/^\d{8}-[a-f0-9]{6}$/', $id) === 1;
}

function orderPath(string $id): string {
    return ORDERS_DIR . '/' . $id . '.json';
}

/** כתיבה אטומית — קריסה באמצע לא משאירה קובץ חצי-כתוב. */
function writeJson(string $path, array $data): void {
    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
        fail('שמירת הנתונים בשרת נכשלה', 500);
    }
    rename($tmp, $path);
}

function readJson(string $path): ?array {
    if (!is_file($path)) return null;
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/**
 * התפריט: הערוך (data/menu.json) או המקורי. פרטי העסק (שם, טלפונים, וואטסאפ,
 * הסבר לכוכבית) נלקחים מהגדרות המנהל — ואם עוד לא נשמרו שם, מהתפריט עצמו.
 */
function menu(): array {
    static $menu = null;
    if ($menu === null) {
        $menu = readJson(MENU_FILE) ?? readJson(MENU_DEFAULT);
        if (!$menu) fail('קובץ התפריט חסר בשרת', 500);
        $biz = loadSettings()['business'];
        if ($biz) {
            $menu['extraNote'] = $biz['extraNote'];
            unset($biz['extraNote']);
            $menu['business'] = $biz;
        }
    }
    return $menu;
}

/** הגופנים שהמנהל העלה: [{id, name, ext, size, at}]. */
function customFonts(): array {
    return readJson(FONTS_FILE) ?? [];
}

/** סוג קובץ הגופן לפי החתימה שבתחילתו — לא לפי השם. */
function fontType(string $bin): ?string {
    $sig = substr($bin, 0, 4);
    return match (true) {
        $sig === "\x00\x01\x00\x00", $sig === 'true' => 'ttf',
        $sig === 'OTTO' => 'otf',
        $sig === 'wOFF' => 'woff',
        $sig === 'wOF2' => 'woff2',
        default => null,
    };
}

/** עיצוב הכתב בתפריט להדפסה: גופן מהרשימה, עובי, גודל ורוחב (באחוזים). */
function cleanPrint(mixed $in): array {
    $fonts = array_merge(['noto-serif', 'frank-ruhl', 'david', 'suez', 'secular', 'rubik', 'heebo', 'assistant', 'noto-sans'],
                         array_column(customFonts(), 'id'));
    $def = defaultSettings()['print'];
    $out = [];
    foreach (['title', 'item'] as $part) {
        $p = is_array($in[$part] ?? null) ? $in[$part] : [];
        $d = $def[$part];
        $num = fn($v, $min, $max, $dv) => is_numeric($v) ? (int) max($min, min($max, (int) round((float) $v))) : $dv;
        $out[$part] = [
            'font'   => in_array($p['font'] ?? '', $fonts, true) ? $p['font'] : $d['font'],
            'weight' => (int) (round($num($p['weight'] ?? null, 100, 900, $d['weight']) / 100) * 100),
            'size'   => $num($p['size'] ?? null, 60, 150, $d['size']),
            'width'  => $num($p['width'] ?? null, 70, 130, $d['width']),
        ];
    }
    return $out;
}

/** פרטי העסק כפי שהגיעו מהמנהל — מנוקים. */
function cleanBusiness(array $b, string $extraNote = ''): array {
    $str = fn($v, $max) => is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    $phones = array_values(array_filter(array_map(fn($p) => $str($p, 30), (array) ($b['phones'] ?? [])), 'strlen'));
    return [
        'name'      => $str($b['name'] ?? '', 60) ?: 'ניחוחות',
        'tagline'   => $str($b['tagline'] ?? '', 120),
        'owner'     => $str($b['owner'] ?? '', 60),
        'phones'    => array_slice($phones, 0, 4),
        'whatsapp'  => preg_replace('/\D/', '', $str($b['whatsapp'] ?? '', 20)),
        'extraNote' => $str($b['extraNote'] ?? $extraNote, 40) ?: 'תוספת תשלום',
    ];
}

/** קובץ הלוגו הנוכחי: [נתיב, סוג]. */
function logoFile(): array {
    foreach (LOGO_TYPES as $ext => $mime) {
        if (is_file(DATA_DIR . "/logo.$ext")) return [DATA_DIR . "/logo.$ext", $mime];
    }
    return [LOGO_DEFAULT, 'image/jpeg'];
}

/** התפריט כפי שהלקוח רואה אותו — בלי מנות ובלי קטגוריות מוסתרות. */
function publicMenu(): array {
    $menu = menu();
    $cats = [];
    foreach ($menu['categories'] as $cat) {
        $cat['items'] = array_values(array_filter($cat['items'], fn($i) => empty($i['hidden'])));
        if ($cat['items']) $cats[] = $cat;
    }
    $menu['categories'] = $cats;
    return $menu;
}

/**
 * מנקה תפריט שהגיע מהמנהל: רק שדות מוכרים, אורכים סבירים, ומזהים יציבים.
 * מזהה קיים נשמר (הזמנות וטיוטות של לקוחות נשענות עליו); למנה חדשה נוצר מזהה.
 */
function cleanMenu(array $in): array {
    $str = fn($v, $max) => is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    $validId = fn($v) => is_string($v) && preg_match('/^[a-z0-9-]{1,40}$/', $v) === 1;
    $seen = [];
    $newId = function (string $prefix) use (&$seen) {
        do { $id = $prefix . '-' . bin2hex(random_bytes(3)); } while (isset($seen[$id]));
        return $id;
    };

    $biz = cleanBusiness(is_array($in['business'] ?? null) ? $in['business'] : [], $str($in['extraNote'] ?? '', 40));
    $out = [
        'extraNote'  => $biz['extraNote'],
        'categories' => [],
    ];
    unset($biz['extraNote']);
    $out = ['business' => $biz] + $out;

    foreach (array_slice((array) ($in['categories'] ?? []), 0, 30) as $cat) {
        if (!is_array($cat)) continue;
        $name = $str($cat['name'] ?? '', 60);
        if ($name === '') fail('לכל קטגוריה צריך שם');
        $cid = $validId($cat['id'] ?? null) && !isset($seen[$cat['id']]) ? $cat['id'] : $newId('cat');
        $seen[$cid] = true;

        $items = [];
        foreach (array_slice((array) ($cat['items'] ?? []), 0, 200) as $item) {
            if (!is_array($item)) continue;
            $iname = $str($item['name'] ?? '', 120);
            if ($iname === '') continue;                     // שורה ריקה — מדלגים
            $iid = $validId($item['id'] ?? null) && !isset($seen[$item['id']]) ? $item['id'] : $newId($cid);
            $seen[$iid] = true;
            $row = ['id' => $iid, 'name' => $iname];
            if (!empty($item['extra']))  $row['extra'] = true;
            if (!empty($item['hidden'])) $row['hidden'] = true;
            $opts = array_values(array_unique(array_filter(
                array_map(fn($o) => $str($o, 40), array_slice((array) ($item['options'] ?? []), 0, 10)), 'strlen')));
            if (count($opts) >= 2) {
                $row['optionLabel'] = $str($item['optionLabel'] ?? '', 40) ?: 'בחירה';
                $row['options'] = $opts;
            }
            $items[] = $row;
        }
        $out['categories'][] = ['id' => $cid, 'name' => $name, 'items' => $items];
    }
    if (!$out['categories']) fail('התפריט ריק');
    return $out;
}

function menuIndex(): array {
    $index = [];
    foreach (menu()['categories'] as $cat) {
        foreach ($cat['items'] as $item) {
            if (!empty($item['hidden'])) continue;           // מנה מוסתרת אי אפשר להזמין
            $index[$item['id']] = [
                'itemId'   => $item['id'],
                'name'     => $item['name'],
                'category' => $cat['name'],
                'catId'    => $cat['id'],
                'extra'    => !empty($item['extra']),
                'options'  => $item['options'] ?? [],
            ];
        }
    }
    return $index;
}

/* ── הגדרות המנהל ─────────────────────────────────────────────────── */

function defaultSettings(): array {
    return [
        // ברירת מחדל: ירושלים — הדלקה 40 דקות לפני השקיעה, צאת ב-8.5° מתחת לאופק
        'location' => [
            'id' => 'jerusalem', 'name' => 'ירושלים', 'lat' => 31.778, 'lon' => 35.235,
            'candleOffset' => 40, 'havdalahMode' => 'tzeit', 'havdalahMinutes' => 40,
        ],
        'limits' => [],          // מזהה קטגוריה → כמה מותר לבחור (אין מפתח = ללא הגבלה)
        'email'  => ['from' => '', 'replyTo' => '', 'adminNotify' => ''],
        // מה מוצג ביומן
        'calendar' => ['showShabbat' => true, 'showParasha' => true, 'showSunset' => true, 'showRc' => true],
        // התראה על הזמנה נשארת על המסך עד שנוגעים בה
        'notify' => ['sticky' => true],
        // פרטי העסק — null: עדיין לא נשמרו בהגדרות, ונלקחים מהתפריט
        'business' => null,
        // עיצוב הכתב בתפריט להדפסה — כמו בקובץ המקורי
        'print' => [
            'title' => ['font' => 'noto-serif', 'weight' => 700, 'size' => 100, 'width' => 100],
            'item'  => ['font' => 'rubik', 'weight' => 400, 'size' => 100, 'width' => 100],
        ],
        // תזכורות לפני אירועים, סיכום יומי וממתינות לאישור — reminders.php
        'reminders' => defaultReminders(),
    ];
}

function loadSettings(): array {
    $saved = readJson(SETTINGS_FILE) ?? [];
    $def = defaultSettings();
    return [
        'location' => ($saved['location'] ?? []) + $def['location'],
        'limits'   => $saved['limits'] ?? [],
        'email'    => ($saved['email'] ?? []) + $def['email'],
        'calendar' => ($saved['calendar'] ?? []) + $def['calendar'],
        'notify'   => ($saved['notify'] ?? []) + $def['notify'],
        'reminders' => isset($saved['reminders']) ? cleanReminders($saved['reminders']) : $def['reminders'],
        'business' => is_array($saved['business'] ?? null) ? cleanBusiness($saved['business']) : null,
        'print'    => cleanPrint($saved['print'] ?? null),
    ];
}

function cleanEmail(mixed $v): string {
    $v = is_string($v) ? trim($v) : '';
    return ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) ? mb_substr($v, 0, 120) : '';
}

function cleanSettings(array $in): array {
    $loc = is_array($in['location'] ?? null) ? $in['location'] : [];
    $num = fn($v, $min, $max, $def) => is_numeric($v) ? max($min, min($max, (float) $v)) : $def;
    $def = defaultSettings()['location'];

    $catIds = array_column(menu()['categories'], 'id');
    $limits = [];
    foreach ((array) ($in['limits'] ?? []) as $cat => $n) {
        if (in_array($cat, $catIds, true) && is_numeric($n) && (int) $n > 0) $limits[$cat] = min(99, (int) $n);
    }
    $email = is_array($in['email'] ?? null) ? $in['email'] : [];
    $cal = is_array($in['calendar'] ?? null) ? $in['calendar'] : [];
    $notify = is_array($in['notify'] ?? null) ? $in['notify'] : [];
    $bool = fn(array $a, string $k, bool $def) => array_key_exists($k, $a) ? (bool) $a[$k] : $def;

    return [
        'location' => [
            'id'              => text($loc, 'id', 40),
            'name'            => text($loc, 'name', 60) ?: $def['name'],
            'lat'             => round($num($loc['lat'] ?? null, -66, 66, $def['lat']), 4),
            'lon'             => round($num($loc['lon'] ?? null, -180, 180, $def['lon']), 4),
            'candleOffset'    => (int) $num($loc['candleOffset'] ?? null, 0, 90, 20),
            'havdalahMode'    => ($loc['havdalahMode'] ?? '') === 'minutes' ? 'minutes' : 'tzeit',
            'havdalahMinutes' => (int) $num($loc['havdalahMinutes'] ?? null, 10, 120, 40),
        ],
        'limits' => $limits,
        'email'  => [
            'from'        => cleanEmail($email['from'] ?? ''),
            'replyTo'     => cleanEmail($email['replyTo'] ?? ''),
            'adminNotify' => cleanEmail($email['adminNotify'] ?? ''),
        ],
        'calendar' => [
            'showShabbat' => $bool($cal, 'showShabbat', true),
            'showParasha' => $bool($cal, 'showParasha', true),
            'showSunset'  => $bool($cal, 'showSunset', true),
            'showRc'      => $bool($cal, 'showRc', true),
        ],
        'notify' => ['sticky' => $bool($notify, 'sticky', true)],
        'reminders' => cleanReminders($in['reminders'] ?? null),
        'business' => is_array($in['business'] ?? null) ? cleanBusiness($in['business']) : (loadSettings()['business']),
        'print'    => array_key_exists('print', $in) ? cleanPrint($in['print']) : loadSettings()['print'],
    ];
}

/* ── מיילים ───────────────────────────────────────────────────────── */

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * שולח מייל HTML בעברית. מחזיר true אם mail() קיבל אותו.
 *
 * הלוגו מוטמע בתוך המייל (multipart/related + Content-ID) ולא כקישור לאתר:
 * תוכנות מייל רבות (Outlook במיוחד) חוסמות תמונות מקישור עד שהקורא מאשר,
 * ותמונה מוטמעת מוצגת מיד.
 */
function sendMail(string $to, string $subject, string $bodyHtml): bool {
    if ($to === '') return false;
    $settings = loadSettings();
    $business = menu()['business']['name'] ?? 'קייטרינג';
    $host = preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost'));
    $from = $settings['email']['from'] ?: 'noreply@' . $host;

    [$logoFile, $logoMime] = logoFile();
    $logoName = 'logo.' . array_search($logoMime, LOGO_TYPES, true);
    $logo = is_file($logoFile) ? file_get_contents($logoFile) : false;
    $header = $logo !== false
        ? '<img src="cid:logo@catering" alt="' . h($business) . '" width="300" '
          . 'style="display:block;margin:0 auto;width:300px;max-width:100%;height:auto;border:0">'
        : '<div style="color:#e9b45a;font-size:26px;font-weight:bold">' . h($business) . '</div>';

    $html = '<!DOCTYPE html><html lang="he" dir="rtl"><body style="margin:0;background:#faf7f1;'
          . 'font-family:Arial,sans-serif;color:#232326"><div style="max-width:560px;margin:0 auto;'
          . 'background:#fff;padding:24px;direction:rtl;text-align:right">'
          . '<div style="background:#27282a;padding:10px;text-align:center;border-radius:10px">' . $header . '</div>'
          . $bodyHtml . '</div></body></html>';

    $boundary = 'catering-' . bin2hex(random_bytes(8));
    $headers = [
        'MIME-Version: 1.0',
        'From: ' . mb_encode_mimeheader($business, 'UTF-8') . " <$from>",
    ];
    if ($settings['email']['replyTo']) $headers[] = 'Reply-To: ' . $settings['email']['replyTo'];

    if ($logo === false) {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $body = $html;
    } else {
        $headers[] = 'Content-Type: multipart/related; boundary="' . $boundary . '"; type="text/html"';
        $body = "--$boundary\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html)) . "\r\n"
              . "--$boundary\r\n"
              . "Content-Type: $logoMime; name=\"$logoName\"\r\n"
              . "Content-Transfer-Encoding: base64\r\n"
              . "Content-ID: <logo@catering>\r\n"
              . "Content-Disposition: inline; filename=\"$logoName\"\r\n\r\n"
              . chunk_split(base64_encode($logo)) . "\r\n"
              . "--$boundary--\r\n";
    }

    return @mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, implode("\r\n", $headers), '-f' . $from);
}

function orderItemsHtml(array $order): string {
    $byCat = [];
    $extra = menu()['extraNote'] ?? 'תוספת תשלום';
    foreach ($order['items'] as $i) $byCat[$i['category']][] = $i['name'] . (!empty($i['extra']) ? " ($extra)" : '');
    $out = '';
    foreach ($byCat as $cat => $names) {
        $out .= '<p style="margin:12px 0 4px;color:#c98f2e;font-weight:bold">' . h($cat) . '</p><ul style="margin:0;padding-right:18px">';
        foreach ($names as $n) $out .= '<li>' . h($n) . '</li>';
        $out .= '</ul>';
    }
    return $out;
}

function businessFooter(): string {
    $b = menu()['business'] ?? [];
    $phones = implode(' / ', $b['phones'] ?? []);
    return '<p style="margin-top:20px;color:#77736b;font-size:14px">' . h(($b['owner'] ?? '') . ' · ' . $phones) . '</p>';
}

/** מייל ללקוח על החלטת המנהל. מחזיר 'sent' / 'failed' / 'none' (אין מייל). */
function mailDecision(array $order): string {
    $to = $order['customer']['email'] ?? '';
    if ($to === '') return 'none';
    $c = $order['customer'];
    $when = $c['deliveryAt'] ? ' לתאריך ' . $c['deliveryAt'] : '';

    if ($order['approval'] === 'approved') {
        $subject = 'הזמנתך אושרה';
        $body = '<h2>שלום ' . h($c['name']) . ',</h2><p>שמחים לעדכן: <b>ההזמנה שלך' . h($when) . ' אושרה</b>.</p>'
              . ($c['guests'] ? '<p>מספר סועדים: ' . (int) $c['guests'] . '</p>' : '')
              . orderItemsHtml($order) . businessFooter();
    } else {
        $subject = 'עדכון לגבי הזמנתך';
        $body = '<h2>שלום ' . h($c['name']) . ',</h2><p>לצערנו לא נוכל לקבל את ההזמנה' . h($when) . '.</p>'
              . (!empty($order['rejectReason']) ? '<p>' . nl2br(h($order['rejectReason'])) . '</p>' : '')
              . '<p>נשמח לעזור בתאריך אחר — אפשר ליצור איתנו קשר.</p>' . businessFooter();
    }
    return sendMail($to, $subject, $body) ? 'sent' : 'failed';
}

/** תאריך YYYY-MM-DD ושעה HH:MM — או מחרוזת ריקה, אם הערך לא תקין. */
function cleanDate(mixed $v): string {
    return (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : '';
}
function cleanTime(mixed $v): string {
    return (is_string($v) && preg_match('/^\d{2}:\d{2}$/', $v)) ? $v : '';
}

/** "20/10/2026 19:00" — התצוגה של מועד האירוע, נגזרת מהתאריך והשעה. */
function deliveryLabel(string $date, string $time): string {
    if ($date === '') return $time;
    [$y, $m, $d] = explode('-', $date);
    return trim("$d/$m/$y $time");
}

/** המצב נגזר מהפריטים: אף אחד לא מוכן, חלק, או כולם. */
function derivedStatus(array $items): string {
    $ready = count(array_filter($items, fn($i) => !empty($i['ready'])));
    if ($ready === 0) return 'pending';
    return $ready === count($items) ? 'done' : 'in_progress';
}

/* ── התחברות ─────────────────────────────────────────────────────── */

function cookieOptions(): array {
    return [
        'expires'  => time() + LOGIN_TTL,
        'path'     => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        // Lax ולא Strict: כשהמנהל לוחץ על התראה בטלפון, הממשק נפתח "מבחוץ",
        // ו-Strict היה שולח אותו לכניסה מחדש. ההגנה מ-CSRF היא דרישת JSON (למטה).
        'samesite' => 'Lax',
    ];
}

function startSession(): void {
    if (!is_dir(SESSIONS_DIR)) @mkdir(SESSIONS_DIR, 0700, true);
    session_save_path(SESSIONS_DIR);
    ini_set('session.gc_maxlifetime', (string) LOGIN_TTL);
    ini_set('session.gc_probability', '1');          // ניקוי קבצים ישנים, מדי פעם
    ini_set('session.gc_divisor', '200');
    $opts = cookieOptions();
    session_name('catering_admin');
    session_set_cookie_params(['lifetime' => LOGIN_TTL] + array_diff_key($opts, ['expires' => 1]));
    session_start();
}

/** מאריך את ההתחברות עוד 4 חודשים מהיום — בכל פעם שהמנהל משתמש בממשק. */
function renewLogin(): void {
    if (!isAdmin()) return;
    setcookie(session_name(), session_id(), cookieOptions());
    // הקובץ נכתב מחדש פעם ביום, כדי שהניקוי האוטומטי לא ימחק כניסה פעילה
    if (($_SESSION['touched'] ?? 0) < time() - 86400) $_SESSION['touched'] = time();
}

function config(): ?array {
    $config = readJson(CONFIG_FILE);
    return ($config && !empty($config['passwordHash'])) ? $config : null;
}

/** גרסת ההתחברות: עולה בשינוי סיסמה או ב"ניתוק כל המכשירים", ומנתקת סשנים ישנים. */
function loginVersion(): int {
    return (int) (config()['version'] ?? 0);
}

function isAdmin(): bool {
    return !empty($_SESSION['admin']) && (int) ($_SESSION['pv'] ?? 0) === loginVersion();
}

function bumpLoginVersion(): void {
    $config = config();
    $config['version'] = ($config['version'] ?? 0) + 1;
    writeJson(CONFIG_FILE, $config);
    $_SESSION['pv'] = $config['version'];
}

function requireAdmin(): void {
    startSession();
    if (!isAdmin()) fail('נדרשת התחברות', 401);
    renewLogin();
    session_write_close();
}

/* ── ניתוב ───────────────────────────────────────────────────────── */

ensureDirs();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST' && !str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    // טופס HTML מאתר זר לא יכול לשלוח JSON — זו ההגנה מפני CSRF.
    fail('בקשה לא תקינה');
}

switch ($action) {

    case 'order': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        $in = body();
        if (!empty($in['website'])) ok(['id' => '']);   // מלכודת לבוטים

        $name  = text($in, 'name', 80);
        $phone = text($in, 'phone', 30);
        if ($name === '')  fail('נא למלא שם');
        if (!preg_match('/^[0-9+\-\s()]{9,}$/', $phone)) fail('נא למלא מספר טלפון תקין');

        $ids = $in['items'] ?? [];
        if (!is_array($ids) || !$ids) fail('לא נבחרו מנות');
        if (count($ids) > MAX_ITEMS) fail('יותר מדי פריטים');

        $email = cleanEmail($in['email'] ?? '');
        if (text($in, 'email', 120) === '') fail('נא למלא מייל — אליו נשלח אישור ההזמנה');
        if ($email === '') fail('כתובת המייל אינה תקינה');

        $menu = menuIndex();
        $chosen = is_array($in['options'] ?? null) ? $in['options'] : [];
        $items = [];
        $perCat = [];
        foreach (array_unique(array_filter($ids, 'is_string')) as $id) {
            if (!isset($menu[$id])) continue;
            $perCat[$menu[$id]['catId']] = ($perCat[$menu[$id]['catId']] ?? 0) + 1;
            $item = $menu[$id];
            // מנה עם אפשרויות (למשל סוג הדג) — הבחירה חובה, ונכנסת לשם המנה
            if ($item['options']) {
                $option = $chosen[$id] ?? '';
                if (!in_array($option, $item['options'], true)) fail('נא לבחור ' . $item['name'] . ': ' . implode(' / ', $item['options']));
                $item['option'] = $option;
                $item['name'] .= ' — ' . $option;
            }
            unset($item['catId'], $item['options']);
            $items[] = $item + ['ready' => false];
        }
        if (!$items) fail('לא נבחרו מנות');

        // מגבלות הבחירה נבדקות גם כאן — התפריט בדפדפן הוא נוחות, לא אכיפה
        $limits = loadSettings()['limits'];
        foreach (menu()['categories'] as $cat) {
            $max = $limits[$cat['id']] ?? 0;
            if ($max && ($perCat[$cat['id']] ?? 0) > $max) fail("ב{$cat['name']} אפשר לבחור עד $max");
        }

        $guests = $in['guests'] ?? 0;
        $order = [
            'id'        => date('Ymd') . '-' . bin2hex(random_bytes(3)),
            'createdAt' => date('c'),
            'customer'  => [
                'name'       => $name,
                'phone'      => $phone,
                'email'      => $email,
                'deliveryDate' => cleanDate($in['deliveryDate'] ?? ''),
                'deliveryTime' => cleanTime($in['deliveryTime'] ?? ''),
                'deliveryAt' => deliveryLabel(cleanDate($in['deliveryDate'] ?? ''), cleanTime($in['deliveryTime'] ?? '')),
                'guests'     => is_numeric($guests) ? max(0, min(100000, (int) $guests)) : 0,
                'notes'      => text($in, 'notes', 2000),
            ],
            'items'     => $items,
            'status'    => 'pending',
            'approval'  => 'new',      // new → approved / rejected (החלטת המנהל)
            'read'      => false,      // האם המנהל כבר פתח את ההזמנה
            'updatedAt' => date('c'),
        ];
        writeJson(orderPath($order['id']), $order);

        // הלקוח מקבל תשובה מיד; ההתראות למנהל יוצאות אחרי זה, ברקע
        respondThenContinue(['id' => $order['id']]);

        $when = $order['customer']['deliveryAt'];
        try {
            pushToAll([
                'title' => '🔔 הזמנה חדשה — ' . $name,
                'body'  => count($items) . ' מנות' . ($when ? ' · 📅 ' . $when : '')
                         . ($order['customer']['guests'] ? ' · ' . $order['customer']['guests'] . ' סועדים' : ''),
                'url'   => 'admin/?order=' . $order['id'],
                'tag'   => 'order-' . $order['id'],
            ]);
        } catch (Throwable $e) { /* התראה שנכשלה לא מבטלת הזמנה */ }

        $notify = loadSettings()['email']['adminNotify'];
        if ($notify) {
            sendMail($notify, 'הזמנה חדשה מ' . $name,
                '<h2>הזמנה חדשה</h2><p><b>' . h($name) . '</b> · ' . h($phone)
                . ($when ? ' · ' . h($when) : '') . '</p>'
                . orderItemsHtml($order));
        }
        exit;
    }

    case 'pushKey': {
        requireAdmin();
        ok(['key' => pushStore()['vapid']['public']]);
    }

    case 'pushSubscribe': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        requireAdmin();
        $in = body();
        pushSubscribe((array) ($in['subscription'] ?? []), text($in, 'device', 80));
        ok(['devices' => count(pushStore()['subs'])]);
    }

    case 'pushUnsubscribe': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        requireAdmin();
        pushUnsubscribe(text(body(), 'endpoint', 1000));
        ok();
    }

    case 'pushTest': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        requireAdmin();
        [$sent, $failed, $details] = pushToAll([
            'title' => '🔔 בדיקת התראות — ניחוחות',
            'body'  => 'אם רואים את זה, ההתראות עובדות. כך תגיע כל הזמנה חדשה.',
            'url'   => 'admin/',
            'tag'   => 'test',
        ]);
        ok(['sent' => $sent, 'failed' => $failed, 'details' => $details]);
    }

    case 'menu': {
        // ציבורי: התפריט ללקוחות, בלי מנות מוסתרות
        respondThenContinue(['menu' => publicMenu()]);
        maybeTick('visit');          // כל כניסה לתפריט היא גם הזדמנות לשלוח תזכורות
        exit;
    }

    case 'tick': {
        // ציבורי: ה-cron של cPanel קורא לזה. שולח רק מה שהגיע זמנו, ולא פעמיים.
        respondThenContinue([]);
        maybeTick('cron');
        exit;
    }

    case 'reminders': {
        requireAdmin();
        ok(upcomingReminders(time()));
    }

    case 'reminderTest': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        requireAdmin();
        [$sent, $failed] = pushToAll([
            'title' => '⏰ תזכורת לדוגמה — ניחוחות',
            'body'  => 'כך תיראה תזכורת לפני אירוע: שם הלקוח, מתי, וכמה מנות כבר מוכנות.',
            'url'   => 'admin/',
            'tag'   => 'test',
        ]);
        ok(['sent' => $sent, 'failed' => $failed]);
    }

    case 'menuAdmin': {
        requireAdmin();
        ok(['menu' => menu(), 'custom' => is_file(MENU_FILE)]);
    }

    case 'saveMenu': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        requireAdmin();
        $menu = cleanMenu(body());
        writeJson(MENU_FILE, $menu);
        ok(['menu' => $menu, 'custom' => true]);
    }

    case 'resetMenu': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        requireAdmin();
        @unlink(MENU_FILE);
        ok(['menu' => readJson(MENU_DEFAULT), 'custom' => false]);
    }

    case 'config': {
        // ציבורי: רק מה שהתפריט צריך כדי לאכוף את מגבלות הבחירה
        ok(['limits' => (object) loadSettings()['limits']]);
    }

    case 'settings': {
        requireAdmin();
        $settings = loadSettings();
        $settings['business'] ??= cleanBusiness(menu()['business'], menu()['extraNote']);
        [$file] = logoFile();
        ok(['settings' => $settings, 'logo' => ['custom' => $file !== LOGO_DEFAULT, 'version' => (int) @filemtime($file)]]);
    }

    case 'savePrint': {
        // עיצוב הכתב בתפריט להדפסה — נשמר לבד, בלי לגעת בשאר ההגדרות
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        requireAdmin();
        $all = loadSettings();
        $all['print'] = cleanPrint(body());
        writeJson(SETTINGS_FILE, $all);
        ok(['print' => $all['print']]);
    }

    case 'fonts': {
        requireAdmin();
        ok(['fonts' => customFonts()]);
    }

    case 'font': {
        // ציבורי: קובץ גופן שהועלה (המזהה ייחודי, אז מותר לשמור במטמון לתמיד)
        $id = $_GET['id'] ?? '';
        $font = current(array_filter(customFonts(), fn($f) => $f['id'] === $id));
        $file = $font ? FONTS_DIR . "/{$font['id']}.{$font['ext']}" : '';
        if (!$font || !is_file($file)) fail('הגופן לא נמצא', 404);
        header('Content-Type: ' . FONT_TYPES[$font['ext']]);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    case 'saveFont': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        requireAdmin();
        $in = body();
        $data = is_string($in['data'] ?? null) ? $in['data'] : '';
        $bin = base64_decode(substr($data, (int) strpos($data, ',') + 1), true);
        if ($bin === false || $bin === '') fail('הקובץ לא הגיע');
        if (strlen($bin) > 8 * 1024 * 1024) fail('קובץ הגופן גדול מדי (עד 8MB)');
        $ext = fontType($bin);
        if (!$ext) fail('זה לא קובץ גופן. אפשר להעלות TTF, OTF, WOFF או WOFF2');
        $list = customFonts();
        if (count($list) >= MAX_FONTS) fail('אפשר עד ' . MAX_FONTS . ' גופנים — מחקו אחד קודם');
        $name = text($in, 'name', 60) ?: 'גופן מותאם';
        if (!is_dir(FONTS_DIR) && !@mkdir(FONTS_DIR, 0755, true)) fail('תיקיית הגופנים אינה ניתנת ליצירה', 500);
        $font = ['id' => 'c-' . bin2hex(random_bytes(4)), 'name' => $name, 'ext' => $ext, 'size' => strlen($bin), 'at' => date('c')];
        file_put_contents(FONTS_DIR . "/{$font['id']}.$ext", $bin, LOCK_EX);
        $list[] = $font;
        writeJson(FONTS_FILE, $list);
        ok(['font' => $font, 'fonts' => $list]);
    }

    case 'deleteFont': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        requireAdmin();
        $id = body()['id'] ?? '';
        $list = customFonts();
        foreach ($list as $f) {
            if ($f['id'] === $id) @unlink(FONTS_DIR . "/{$f['id']}.{$f['ext']}");
        }
        $list = array_values(array_filter($list, fn($f) => $f['id'] !== $id));
        writeJson(FONTS_FILE, $list);
        ok(['fonts' => $list]);
    }

    case 'logo': {
        // ציבורי: הלוגו של העסק — בתפריט, בממשק, במיילים ובתפריט להדפסה
        [$file, $mime] = logoFile();
        $etag = '"' . md5($file . filemtime($file)) . '"';
        header('Content-Type: ' . $mime);
        header('Cache-Control: no-cache');            // הדפדפן שומר, אבל בודק אם הלוגו הוחלף
        header('ETag: ' . $etag);
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    case 'saveLogo': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        requireAdmin();
        $data = body()['data'] ?? '';
        if (!is_string($data) || !preg_match('#^data:image/(png|jpeg|webp);base64,#', $data)) fail('אפשר להעלות PNG, JPG או WEBP');
        $bin = base64_decode(substr($data, strpos($data, ',') + 1), true);
        if ($bin === false || strlen($bin) > 3 * 1024 * 1024) fail('הקובץ גדול מדי (עד 3MB)');
        $info = @getimagesizefromstring($bin);
        $ext = array_search($info['mime'] ?? '', LOGO_TYPES, true);
        if (!$info || $ext === false) fail('הקובץ אינו תמונה תקינה');
        if ($info[0] < 200) fail('הלוגו קטן מדי — צריך לפחות 200 פיקסלים ברוחב');
        foreach (array_keys(LOGO_TYPES) as $old) @unlink(DATA_DIR . "/logo.$old");
        file_put_contents(DATA_DIR . "/logo.$ext", $bin, LOCK_EX);
        ok(['logo' => ['custom' => true, 'version' => time()]]);
    }

    case 'resetLogo': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        requireAdmin();
        foreach (array_keys(LOGO_TYPES) as $old) @unlink(DATA_DIR . "/logo.$old");
        ok(['logo' => ['custom' => false, 'version' => (int) filemtime(LOGO_DEFAULT)]]);
    }

    case 'saveSettings': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        requireAdmin();
        $settings = cleanSettings(body());
        writeJson(SETTINGS_FILE, $settings);
        ok(['settings' => $settings]);
    }

    case 'state': {
        startSession();
        renewLogin();
        ok(['configured' => config() !== null, 'loggedIn' => isAdmin()]);
    }

    case 'setup': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        if (config() !== null) fail('הסיסמה כבר נקבעה', 409);
        $password = (string) (body()['password'] ?? '');
        if (mb_strlen($password) < 6) fail('הסיסמה צריכה לפחות 6 תווים');
        writeJson(CONFIG_FILE, ['passwordHash' => password_hash($password, PASSWORD_DEFAULT), 'createdAt' => date('c')]);
        @chmod(CONFIG_FILE, 0600);
        startSession();
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['pv'] = loginVersion();
        renewLogin();
        ok();
    }

    case 'login': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        $config = config();
        if (!$config) fail('עדיין לא נקבעה סיסמה', 409);
        $password = (string) (body()['password'] ?? '');
        if (!password_verify($password, $config['passwordHash'])) {
            sleep(1);   // מאט ניחוש סיסמאות
            fail('סיסמה שגויה', 401);
        }
        startSession();
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['pv'] = loginVersion();
        renewLogin();
        ok();
    }

    case 'changePassword': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        startSession();
        if (!isAdmin()) fail('נדרשת התחברות', 401);
        $in = body();
        $config = config();
        if (!password_verify((string) ($in['current'] ?? ''), $config['passwordHash'])) {
            sleep(1);
            fail('הסיסמה הנוכחית שגויה');
        }
        $new = (string) ($in['password'] ?? '');
        if (mb_strlen($new) < 6) fail('הסיסמה החדשה צריכה לפחות 6 תווים');
        $config['passwordHash'] = password_hash($new, PASSWORD_DEFAULT);
        writeJson(CONFIG_FILE, $config);
        if (!empty($in['logoutOthers'])) bumpLoginVersion();
        renewLogin();
        ok();
    }

    case 'logoutOthers': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        startSession();
        if (!isAdmin()) fail('נדרשת התחברות', 401);
        bumpLoginVersion();          // כל הסשנים הישנים לא תקפים; הסשן הזה מקבל את הגרסה החדשה
        renewLogin();
        ok();
    }

    case 'logout': {
        startSession();
        $_SESSION = [];
        session_destroy();
        setcookie(session_name(), '', ['expires' => time() - 3600] + array_diff_key(cookieOptions(), ['expires' => 1]));
        ok();
    }

    case 'orders': {
        requireAdmin();
        $orders = [];
        foreach (glob(ORDERS_DIR . '/*.json') ?: [] as $file) {
            $order = readJson($file);
            if ($order) $orders[] = $order;
        }
        usort($orders, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
        respondThenContinue(['orders' => $orders]);
        maybeTick('admin');          // הממשק פתוח ומרענן כל 30 שניות — גם זה שעון לתזכורות
        exit;
    }

    case 'update': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        requireAdmin();
        $in = body();
        $id = $in['id'] ?? null;
        if (!validOrderId($id)) fail('מזהה הזמנה שגוי');

        $path = orderPath($id);
        $lock = fopen($path . '.lock', 'c');
        flock($lock, LOCK_EX);
        $order = readJson($path);
        if (!$order) fail('ההזמנה לא נמצאה', 404);

        // סימון פריטים: { itemId: true/false } — המצב נגזר מחדש.
        if (isset($in['ready']) && is_array($in['ready'])) {
            foreach ($order['items'] as &$item) {
                if (array_key_exists($item['itemId'], $in['ready'])) {
                    $item['ready'] = (bool) $in['ready'][$item['itemId']];
                }
            }
            unset($item);
            $order['status'] = derivedStatus($order['items']);
        }
        // שינוי מצב ידני גובר, עד הסימון הבא.
        if (isset($in['status']) && in_array($in['status'], ['pending', 'in_progress', 'done'], true)) {
            $order['status'] = $in['status'];
        }
        // המנהל יכול לקבוע או לתקן את מועד האירוע — זה מה שממקם אותו ביומן.
        if (array_key_exists('deliveryDate', $in) || array_key_exists('deliveryTime', $in)) {
            $c = &$order['customer'];
            if (array_key_exists('deliveryDate', $in)) $c['deliveryDate'] = cleanDate($in['deliveryDate']);
            if (array_key_exists('deliveryTime', $in)) $c['deliveryTime'] = cleanTime($in['deliveryTime']);
            $c['deliveryAt'] = deliveryLabel($c['deliveryDate'] ?? '', $c['deliveryTime'] ?? '');
            unset($c);
        }
        if (isset($in['read'])) {
            $order['read'] = (bool) $in['read'];
        }
        // אישור או סירוב — ומייל ללקוח, אם השאיר כתובת
        $mail = null;
        if (isset($in['approval']) && in_array($in['approval'], ['new', 'approved', 'rejected'], true)) {
            $order['approval'] = $in['approval'];
            $order['rejectReason'] = $in['approval'] === 'rejected' ? text($in, 'reason', 1000) : '';
            $order['decidedAt'] = date('c');
            $order['read'] = true;
            if ($in['approval'] !== 'new' && !empty($in['notify'])) {
                $mail = mailDecision($order);
                $order['mailLog'][] = ['at' => date('c'), 'approval' => $in['approval'], 'result' => $mail];
            }
        }
        if (isset($in['remindersOff'])) {
            $order['remindersOff'] = (bool) $in['remindersOff'];
        }
        if (isset($in['adminNotes'])) {
            $order['adminNotes'] = text($in, 'adminNotes', 2000);
        }
        $order['updatedAt'] = date('c');
        writeJson($path, $order);
        flock($lock, LOCK_UN);
        fclose($lock);
        ok(['order' => $order, 'mail' => $mail]);
    }

    case 'delete': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        requireAdmin();
        $id = body()['id'] ?? null;
        if (!validOrderId($id)) fail('מזהה הזמנה שגוי');
        @unlink(orderPath($id));
        @unlink(orderPath($id) . '.lock');
        ok();
    }

    default:
        fail('פעולה לא מוכרת', 404);
}
