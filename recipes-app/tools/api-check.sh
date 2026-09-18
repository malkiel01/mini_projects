#!/usr/bin/env bash
# בדיקת api.php מקצה לקצה על שרת PHP אמיתי.
# הרצה: bash recipes-app/tools/api-check.sh
#
# זו הבדיקה שהקודמות אינן עושות: היא מדברת HTTP, שומרת עוגיית סשן, ובודקת
# שהמסלול שהדפדפן עובר בו — הרשמה, חסימה עד אימות, כניסה, יציאה — עובד
# בפועל ולא רק ברמת הפונקציות.

set -uo pipefail
cd "$(dirname "$0")/../.."   # שורש הריפו: כך /recipes-app/api.php קיים בשרת

PORT=${PORT:-8791}
TMP=$(mktemp -d)
JAR="$TMP/cookies"
export RECIPES_TEST_DIR="$TMP"
FAIL=0

# מסד זמני: קובץ bootstrap שמגדיר DB_FILE לפני שנטען משהו.
cat > "$TMP/boot.php" <<PHPBOOT
<?php
define('DB_FILE', '$TMP/t.sqlite');
define('MEDIA_DIR', '$TMP/media');
PHPBOOT

# sendmail_path הוא PHP_INI_SYSTEM: ini_set בזמן ריצה אינו משנה אותו, ולכן
# הוא נמסר לשרת בשורת הפקודה. /bin/true מדמה MTA שמקבל — כדי שהבדיקה
# תבחן את מסלול ההצלחה; מסלול הכשל נבדק ב-auth-check.php.
php -S "127.0.0.1:$PORT" -t . \
  -d auto_prepend_file="$TMP/boot.php" \
  -d sendmail_path=/bin/true \
  >"$TMP/server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null; rm -rf "$TMP"' EXIT

for _ in $(seq 1 40); do
  curl -fsS "http://127.0.0.1:$PORT/recipes-app/api.php?action=me" >/dev/null 2>&1 && break
  sleep 0.25
done

call() {  # call <action> <json>
  curl -sS -b "$JAR" -c "$JAR" -X POST \
    -H 'Content-Type: application/json' \
    --data "${2:-{\}}" \
    "http://127.0.0.1:$PORT/recipes-app/api.php?action=$1"
}

check() {  # check <label> <haystack> <needle>
  if printf '%s' "$2" | grep -q -- "$3"; then
    printf '  \xE2\x9C\x85 %s\n' "$1"
  else
    printf '  \xE2\x9D\x8C %s\n     התקבל: %s\n' "$1" "$2"
    FAIL=1
  fi
}

echo
echo "1. אורח"
check 'me מחזיר success עם user=null' "$(call me)" '"user":null'

echo
echo "2. הרשמה"
R=$(call register '{"username":"tester","email":"t@example.com","password":"sod12345","display_name":"בודק"}')
check 'ההרשמה הצליחה' "$R" '"success":true'
check 'נשלח דוא"ל'     "$R" '"mail_sent":true'
check 'שם תפוס נדחה'   "$(call register '{"username":"tester","email":"x@example.com","password":"sod12345"}')" 'כבר בשימוש'
check 'סיסמה קצרה נדחית' "$(call register '{"username":"other","email":"o@example.com","password":"abc"}')" '8 תווים'

echo
echo "3. כניסה נחסמת עד אימות"
check 'כניסה לפני אימות נדחית' "$(call login '{"username":"tester","password":"sod12345"}')" 'טרם אומת'

# אימות דרך הקישור, כמו שהמשתמש עושה — האסימון נשלף מהמסד ולא מהתשובה
TOKEN=$(php -r '
  define("DB_FILE", getenv("RECIPES_TEST_DIR")."/t.sqlite");
  define("MEDIA_DIR", getenv("RECIPES_TEST_DIR")."/media");
  require "recipes-app/lib/auth.php";
  echo issueToken(1, "verify_email", 24);
')
V=$(curl -sS "http://127.0.0.1:$PORT/recipes-app/verify.php?token=$TOKEN")
check 'verify.php מאמת' "$V" 'החשבון אומת'
check 'אסימון שנוצל נדחה' "$(curl -sS "http://127.0.0.1:$PORT/recipes-app/verify.php?token=$TOKEN")" 'כבר נוצל'

echo
echo "4. כניסה, סשן, יציאה"
check 'הכניסה עוברת'        "$(call login '{"username":"tester","password":"sod12345"}')" '"username":"tester"'
check 'העוגייה מחזיקה סשן'  "$(call me)" '"display_name":"בודק"'
check 'סיסמה שגויה נדחית'   "$(call login '{"username":"tester","password":"wrong"}')" 'שם משתמש או סיסמה שגויים'
check 'יציאה'               "$(call logout)" '"success":true'
check 'ואחריה אורח'         "$(call me)" '"user":null'

echo
echo "5. איפוס אינו מדליף מי רשום"
A=$(call request-reset '{"email":"t@example.com"}')
B=$(call request-reset '{"email":"nobody@example.com"}')
check 'שתי התשובות זהות' "$A" "$(printf '%s' "$B" | sed 's/[][\.*^$]/\\&/g')"

echo
echo "6. תגים"
check 'הצירים הסגורים נזרעו' "$(call tags)" 'עוגות'
check 'כשרות קיימת'          "$(call tags)" 'פרווה'

echo
echo "7. פעולה מוגנת ללא התחברות"
check 'נדחית ב-401' "$(call some-protected-thing)" 'לא מוכרת\|נדרשת התחברות'

echo
if [ "$FAIL" -ne 0 ]; then
  echo "❌ נכשלו בדיקות. לוג השרת:"
  tail -20 "$TMP/server.log"
  exit 1
fi
echo "✅ כל הבדיקות עברו"
