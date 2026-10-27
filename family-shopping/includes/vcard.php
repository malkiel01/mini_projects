<?php
/**
 * פירוק איש קשר (vCard) שמגיע מ"שיתוף" באפליקציית אנשי הקשר
 * includes/vcard.php
 *
 * אנדרואיד שולח קובץ .vcf (לפעמים כמה אנשי קשר בקובץ אחד, לפעמים בקידוד
 * QUOTED-PRINTABLE עם שורות שבורות). לוקחים את הראשון: שם, מייל וטלפון.
 * כשיש כמה מספרים - מעדיפים נייד ישראלי (05… / ‎+9725…).
 */

/**
 * @return array{name: string, email: string, phone: string}
 */
function parseVcard($text) {
    $text = str_replace(["\r\n", "\r"], "\n", (string)$text);

    // רק איש הקשר הראשון
    if (preg_match('/BEGIN:VCARD(.*?)END:VCARD/is', $text, $m)) {
        $text = $m[1];
    }

    // שורות מקופלות: רווח בתחילת שורה (vCard 3/4), או "=" בסוף שורה (QUOTED-PRINTABLE)
    $text = preg_replace("/=\n/", '', $text);
    $text = preg_replace("/\n[ \t]/", '', $text);

    $out    = ['name' => '', 'email' => '', 'phone' => ''];
    $fn     = '';
    $n      = '';
    $phones = [];

    foreach (explode("\n", $text) as $line) {
        if (!preg_match('/^(?:item\d+\.)?([A-Z-]+)((?:;[^:]*)?):(.*)$/i', trim($line), $m)) {
            continue;
        }
        $key    = strtoupper($m[1]);
        $params = strtoupper($m[2]);
        $value  = $m[3];

        if (strpos($params, 'QUOTED-PRINTABLE') !== false) {
            $value = quoted_printable_decode($value);
        }
        $value = trim(str_replace(['\\,', '\;', '\\n'], [',', ';', ' '], $value));

        if ($key === 'FN' && $fn === '') {
            $fn = $value;
        } elseif ($key === 'N' && $n === '') {
            // N:משפחה;פרטי;אמצעי;תחילית;סיומת
            $parts = array_map('trim', explode(';', $value));
            $n = trim(($parts[1] ?? '') . ' ' . ($parts[0] ?? ''));
        } elseif ($key === 'EMAIL' && $out['email'] === '') {
            $value = mb_strtolower($value);
            if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $out['email'] = $value;
            }
        } elseif ($key === 'TEL') {
            $value = preg_replace('/^tel:/i', '', $value);
            if (preg_match('/\d/', $value)) {
                $phones[] = $value;
            }
        }
    }

    $out['name'] = $fn !== '' ? $fn : $n;

    foreach ($phones as $phone) {
        $digits = preg_replace('/[^\d+]/', '', $phone);
        if (preg_match('/^(?:05|\+9725|009725|9725)/', $digits)) {
            $out['phone'] = $phone;
            break;
        }
    }
    if ($out['phone'] === '' && $phones) {
        $out['phone'] = $phones[0];
    }

    return $out;
}

/**
 * טקסט חופשי ששותף (בלי קובץ) - מחפשים בו מייל ומספר טלפון.
 *
 * @return array{name: string, email: string, phone: string}
 */
function parseSharedText($text) {
    $text = (string)$text;
    $out  = ['name' => '', 'email' => '', 'phone' => ''];

    if (stripos($text, 'BEGIN:VCARD') !== false) {
        return parseVcard($text);
    }
    if (preg_match('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', $text, $m)) {
        $out['email'] = mb_strtolower($m[0]);
    }
    if (preg_match('/(?:\+?972[-\s]?|0)5\d(?:[-\s]?\d){7}/', $text, $m)) {
        $out['phone'] = $m[0];
    }
    // השורה הראשונה שאינה מייל או מספר - כנראה השם
    foreach (preg_split('/\n+/', $text) as $line) {
        $line = trim($line);
        if ($line !== '' && strpos($line, '@') === false && !preg_match('/\d{5}/', $line)) {
            $out['name'] = mb_substr($line, 0, 100);
            break;
        }
    }

    return $out;
}
