<?php
// מכניס הודעות בדיקה לתיבה המקומית (dovecot) ב-IMAP APPEND. CLI בלבד.
// php tests/send.php <kind> [count]   kind: code | pdf | evil | html | plain | bulk
if (PHP_SAPI !== 'cli') exit;
$kind = $argv[1] ?? 'code';
$n = (int)($argv[2] ?? 1);
$mb = imap_open('{localhost:993/imap/ssl}INBOX', 'demo@test.local', 'secret123') or exit(imap_last_error() . "\n");
$enc = fn($s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
$date = date('r');
for ($i = 0; $i < $n; $i++) {
    $id = bin2hex(random_bytes(6));
    $h = "Date: $date\r\nMessage-ID: <$id@test.local>\r\nTo: demo@test.local\r\nMIME-Version: 1.0\r\n";
    switch ($kind) {
        case 'code':
            $msg = $h . 'From: ' . $enc('חברה קדישא') . " <noreply@kadisha.test>\r\nSubject: " . $enc('קוד האימות שלך למערכת') . "\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode('<div dir="rtl" style="font-family:Arial"><h2 style="color:#2d4a8a">שלום,</h2><p>קוד האימות שלך: <b style="font-size:22px">482913</b></p><p>הקוד תקף ל-10 דקות. אם לא ביקשת — התעלם מהודעה זו.</p><p><a href="https://example.com/sign">לחתימה על המסמך</a></p></div>'));
            break;
        case 'pdf':
            $pdf = file_get_contents(__DIR__ . '/fixtures/sample.pdf');
            $b = 'b' . $id;
            $msg = $h . 'From: ' . $enc('משרד הקבורה') . " <office@kadisha.test>\r\nSubject: " . $enc('מסמך לחתימה — הסכם רכישת חלקה') . "\r\n"
                . "Content-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
                . "שלום רב,\r\nמצורף ההסכם לעיון ולחתימה.\r\nבברכה, המשרד\r\n--$b\r\nContent-Type: application/pdf; name=\"" . $enc('הסכם.pdf') . "\"\r\n"
                . "Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode('הסכם רכישה.pdf') . "\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($pdf)) . "--$b--\r\n";
            break;
        case 'evil':
            $msg = $h . "From: Evil <evil@bad.test>\r\nSubject: XSS test\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n"
                . '<body onload="parent.document.title=\'PWNED-onload\'"><script>parent.document.title="PWNED-script"</script>'
                . '<iframe src="javascript:parent.document.title=\'PWNED-iframe\'"></iframe><img src=x onerror="parent.document.title=\'PWNED-img\'">'
                . '<a href="javascript:parent.document.title=\'PWNED-link\'">click</a><form action="https://bad.test"><input name=p></form>'
                . '<svg onload="parent.document.title=\'PWNED-svg\'"></svg><div style="background:url(javascript:alert(1))">safe text stays</div>'
                . '<object data="x.swf"></object><embed src="x"><math><mi xlink:href="javascript:alert(1)">m</mi></math><p>END</p></body>';
            break;
        case 'plain':
            $msg = $h . "From: Ploni <ploni@test.local>\r\nSubject: Your code\r\nContent-Type: text/plain; charset=windows-1255\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
                . iconv('UTF-8', 'windows-1255', "שלום,\r\nYour verification code is 7731.\r\nhttps://example.com/x?y=1\r\n");
            break;
        default: // bulk
            $msg = $h . 'From: ' . $enc('שולח ' . ($i + 1)) . " <s$i@test.local>\r\nSubject: " . $enc('הודעה מספר ' . ($i + 1) . ' — עדכון שבועי') . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n\r\nתוכן ההודעה מספר " . ($i + 1) . ". שורה נוספת של טקסט לתקציר.\r\n";
    }
    imap_append($mb, '{localhost:993/imap/ssl}INBOX', $msg) or print(imap_last_error() . "\n");
}
imap_close($mb);
echo "ok $kind x$n\n";
