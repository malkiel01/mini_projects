#!/usr/bin/env bash
# הכניסה דרך הפלטפורמה (platform/docs/auth.md), על שרת PHP אמיתי ומסד זמני.
# השער אינו כאן; ה-prepend של הבדיקה ממלא את $_SERVER['PLATFORM_USER'] מכותרת X-Test-Platform
# — כמו שהשער עושה בשרת. בלי הכותרת = אפליקציה מנותקת.
set -euo pipefail
cd "$(dirname "$0")/.."
TMP=$(mktemp -d)
PORT=${PORT:-8766}
trap 'kill $PID 2>/dev/null || true; rm -rf "$TMP"' EXIT

cat > "$TMP/prepend.php" <<PHP
<?php
define('DB_FILE',     '$TMP/db.sqlite');
define('MEDIA_DIR',   '$TMP/media');
define('INBOX_DIR',   '$TMP/inbox');
define('LIVE_DIR',    '$TMP/live');
define('SECRET_FILE', '$TMP/secret.key');
if (!empty(\$_SERVER['HTTP_X_TEST_PLATFORM'])) \$_SERVER['PLATFORM_USER'] = \$_SERVER['HTTP_X_TEST_PLATFORM'];
PHP
mkdir -p "$TMP/media" "$TMP/inbox" "$TMP/live"
php -S 127.0.0.1:$PORT -d auto_prepend_file="$TMP/prepend.php" -t . >"$TMP/server.log" 2>&1 &
PID=$!
for i in $(seq 1 30); do curl -s "http://127.0.0.1:$PORT/api.php?action=me" >/dev/null && break; sleep 0.2; done

# api <who> <action> <json> — who: "" = מנותקת עם עוגיות; אחרת JSON של זהות
api() {
  if [ -z "$1" ]; then curl -s -b "$TMP/j" -c "$TMP/j" -H 'Content-Type: application/json' -d "$3" "http://127.0.0.1:$PORT/api.php?action=$2"
  else curl -s -H "X-Test-Platform: $1" -H 'Content-Type: application/json' -d "$3" "http://127.0.0.1:$PORT/api.php?action=$2"; fi; }
# sql "<SQL>" — ישירות במסד הזמני (אין sqlite3 בכל מכונה). כך נוצרים "משתמשים ותיקים".
sql() { php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec($argv[2]);' "$TMP/db.sqlite" "$1"; }
HASH=$(php -r 'echo password_hash("secret123", PASSWORD_DEFAULT);')
need() {
  if ! echo "$1" | python3 -c "import sys,json; d=json.load(sys.stdin); assert ($2), d" 2>/dev/null; then
    echo "✗ $3"; echo "   ← $1"; exit 1; fi; echo "✓ $3"; }

BOSS='{"sub":"1","name":"בעל הבית","email":"boss@example.com","email_verified":true}'
DANA='{"sub":"17","name":"דנה","email":"Dana@Example.com","email_verified":true}'
YOSI='{"sub":"23","name":"יוסי","email":"yosi@other.com","email_verified":true}'
RUTI='{"sub":"31","name":"רותי","email":"ruti@example.com","email_verified":true}'
EVE='{"sub":"40","name":"ערן","email":"eran@example.com","email_verified":true}'

# מערכת ריקה, מחוברת: הראשון הוא המנהל; התקנה וכניסה בסיסמה חסומות.
R=$(api "$BOSS" me '{}');            need "$R" "d['user']['role']=='admin' and d['platform']['state']=='ok' and d['needs_setup']==False" "מערכת ריקה: הנכנס הראשון מהפלטפורמה הוא המנהל"
R=$(api "$BOSS" setup '{"username":"x","password":"secret123"}'); need "$R" "d['success']==False" "מחוברת: התקנה בסיסמה חסומה"
R=$(api "$BOSS" user-create '{"username":"dana","password":"secret123"}'); need "$R" "d['success']==False" "מחוברת: יצירת משתמש עם סיסמה חסומה"

# מנותקת: המנהל הישן (בסיסמה) נוצר כמו פעם — זה המסלול שעובד היום בשרת.
R=$(api "" login '{"username":"x","password":"y"}'); need "$R" "d['success']==False" "מנותקת: כניסה שגויה נכשלת"
R=$(api "$BOSS" users '{}'); need "$R" "len(d['users'])==1" "רשימת משתמשים"
# משתמשים ותיקים בסיסמה — דרך מצב מנותק, כמו שהם קיימים היום.
sql "INSERT INTO users (username,password_hash,display_name,role,created_at) VALUES
 ('dana','$HASH','דנה','operator','2026-01-01T00:00:00Z'),
 ('yosi','$HASH','יוסי','operator','2026-01-01T00:00:00Z');"
R=$(api "" login '{"username":"dana","password":"secret123"}'); need "$R" "d['user']['username']=='dana'" "מנותקת: הכניסה בסיסמה עובדת"
R=$(api "" me '{}');  need "$R" "d['platform'] is None and d['user']['username']=='dana'" "מנותקת: me בלי פלטפורמה"

# המנהל נותן לדנה מייל → היא מתקשרת לבד, בלי מסך, עם התפקיד והנתונים שלה.
R=$(api "$BOSS" user-update '{"id":2,"email":"dana@example.com","password":"newpass999"}'); need "$R" "d['success']==True" "מנהל מגדיר מייל"
R=$(api "" login '{"username":"dana","password":"secret123"}'); need "$R" "d['success']==True" "מחוברת: איפוס סיסמה מהמנהל לא חל — הסיסמה הישנה נשמרה"
R=$(api "$DANA" me '{}'); need "$R" "d['user']['username']=='dana' and d['user']['role']=='operator'" "איחוד לפי מייל (בלי תלות באותיות)"
R=$(api "$DANA" login '{"username":"dana","password":"secret123"}'); need "$R" "d['success']==False" "מחוברת: כניסה בסיסמה חסומה"
R=$(api "$DANA" change-password '{"current":"secret123","new":"zzzzzzzz"}'); need "$R" "d['success']==False" "מחוברת: שינוי סיסמה חסום"
R=$(api "" login '{"username":"dana","password":"secret123"}'); need "$R" "d['success']==True" "הסיסמה הישנה לא נמחקה — חוזרת לעבוד במנותקת"

# אותו sub, מייל שהשתנה — הקישור לפי sub קובע.
R=$(api '{"sub":"17","name":"דנה","email":"new@else.com"}' me '{}'); need "$R" "d['user']['username']=='dana'" "קישור לפי sub גובר על מייל"

# יוסי — מייל אחר: מסך חד-פעמי. סיסמה שגויה 5 פעמים → נעילה.
R=$(api "$YOSI" me '{}'); need "$R" "d['user'] is None and d['platform']['state']=='pending'" "אין התאמה → מסך החיבור"
R=$(api "$YOSI" overview '{}'); need "$R" "d['success']==False" "לפני הבחירה — אין גישה"
R=$(api "$YOSI" platform-link '{"username":"dana","password":"secret123"}'); need "$R" "d['success']==False and 'כבר מקושר' in d['error']" "חשבון שכבר מקושר — לא ניתן לקשר שוב"
for i in 1 2 3 4; do api "$YOSI" platform-link '{"username":"yosi","password":"bad"}' >/dev/null; done
R=$(api "$YOSI" platform-link '{"username":"yosi","password":"bad"}'); need "$R" "d['success']==False" "ניסיון חמישי שגוי"
R=$(api "$YOSI" platform-link '{"username":"yosi","password":"secret123"}'); need "$R" "d['success']==False and 'ניסיונות' in d['error']" "5 ניסיונות → נעילה"
sql "DELETE FROM settings WHERE key='link_lock:23'"
R=$(api "$YOSI" platform-link '{"username":"yosi","password":"secret123"}'); need "$R" "d['user']['username']=='yosi' and d['user']['role']=='operator'" "יש לי כבר חשבון → החשבון הישן, עם התפקיד שלו"
R=$(api "$YOSI" me '{}'); need "$R" "d['user']['username']=='yosi' and d['platform']['can_link']==False" "יוסי נכנס אוטומטית מעכשיו"

# רותי — אין לי חשבון: חשבון חדש, צופה (אין הרשמה עצמית → התפקיד הנמוך), פעיל, בלי סיסמה.
R=$(api "$RUTI" platform-new '{}'); need "$R" "d['user']['role']=='viewer' and d['user']['username']=='ruti'" "אין לי חשבון → חשבון חדש, צופה"
R=$(api "$RUTI" platform-new '{}'); need "$R" "d['success']==False" "פעם אחת בלבד"
R=$(api "$RUTI" me '{}'); need "$R" "d['platform']['can_link']==True" "ההצעה לחבר חשבון ישן נשארת בחשבון שלי"
R=$(api "" login '{"username":"ruti","password":""}'); need "$R" "d['success']==False" "לחשבון מהפלטפורמה אין סיסמה"

# חסימה אצלנו גוברת.
R=$(api "$BOSS" user-update '{"id":4,"blocked":true}'); need "$R" "d['success']==True" "מנהל חוסם את רותי"
R=$(api "$RUTI" me '{}'); need "$R" "d['user'] is None and d['platform']['state']=='blocked'" "חסום → אין כניסה, והדף יודע למה"

# ערן נכנס כחשבון חדש, ואז נזכר: מחבר את החשבון הישן מ"החשבון שלי" — החדש נמחק.
sql "INSERT INTO users (username,password_hash,display_name,role,created_at) VALUES ('eran-old','$HASH','ערן','operator','2026-01-01T00:00:00Z');"
R=$(api "$EVE" platform-new '{}'); need "$R" "d['user']['role']=='viewer'" "ערן — חשבון חדש"
R=$(api "$EVE" platform-link '{"username":"eran-old","password":"secret123"}'); need "$R" "d['user']['username']=='eran-old' and d['user']['role']=='operator'" "נזכר אחר כך → עובר לחשבון הישן"
R=$(api "$BOSS" users '{}'); need "$R" "not any(u['username']=='eran' for u in d['users'])" "החשבון הריק נמחק"

R=$(api "$BOSS" user-update '{"id":3,"email":"yosi@other.com"}'); need "$R" "d['success']==True" "מייל לחשבון"
R=$(api "$BOSS" user-update '{"id":2,"email":"YOSI@other.com"}'); need "$R" "d['success']==False" "מייל כפול נדחה"
R=$(api "$BOSS" logout '{}'); R=$(api "$BOSS" me '{}'); need "$R" "d['user']['role']=='admin'" "מחוברת: logout לא מנתק — היציאה היא מהפלטפורמה"

grep -q 'PHP Fatal\|PHP Warning' "$TMP/server.log" && { echo "✗ שגיאות בשרת"; cat "$TMP/server.log"; exit 1; }
echo "הכול עבר."
