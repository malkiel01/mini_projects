<?php
// מדמה את בדיקת "אתה אדם?" של האחסון (api-check / scout-check): בלי העוגייה — דף JS ו-409;
// עם העוגייה — "ok". כך נבדק ש-workerKick עונה לבדיקה ושומר את העוגייה.
if (($_COOKIE['humans_21909'] ?? '') === '1') { echo "ok\n"; exit; }
http_response_code(409);
echo '<script>document.cookie = "humans_21909=1"; document.location.reload(true)</script>';
