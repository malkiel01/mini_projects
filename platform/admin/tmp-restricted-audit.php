<?php
// הוחלף ב"🔒 גישות לאפליקציות חסומות" (lib/restricted-access.php, admin_restricted ב-api.php). הקובץ נשאר רק
// כדי לנטרל את העותק שבשרת — rsync רץ בלי --delete.
http_response_code(410);
