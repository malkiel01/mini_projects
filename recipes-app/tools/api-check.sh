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
  -d upload_max_filesize=32M -d post_max_size=40M \
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

# ה-id של המתכון שנשמר. התשובה מכילה גם אובייקטים מקוננים עם id משלהם
# (תגים, מדיה), ולכן "ה-id הראשון בטקסט" הוא המזהה הלא נכון. python3
# קורא את ה-JSON ולוקח את השדה העליון.
top_id() { printf '%s' "$1" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("id",""))'; }

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
echo "2. הרשמה — הראשון הוא המנהל, ומאומת מראש"
R=$(call register '{"username":"owner","email":"owner@example.com","password":"sod12345","display_name":"בעלים"}')
check 'ההרשמה הצליחה'      "$R" '"success":true'
check 'המנהל מאומת מראש'   "$R" '"verified":true'
check 'נשלח דוא"ל בכל זאת' "$R" '"mail_sent":true'
check 'המנהל נכנס מיד'     "$(call login '{"username":"owner","password":"sod12345"}')" '"role":"admin"'
call logout >/dev/null

echo
echo "2ב. הרשמה — השני הוא זר, והאימות חל עליו"
R=$(call register '{"username":"tester","email":"t@example.com","password":"sod12345","display_name":"בודק"}')
check 'ההרשמה הצליחה'  "$R" '"success":true'
check 'אינו מאומת'     "$R" '"verified":false'
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
  echo issueToken(2, "verify_email", 24);
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
check 'אבחון נדחה לאורח' "$(call diag)" 'נדרשת התחברות'

echo
echo "8. מתכון מקצה לקצה דרך ה-API"
call login '{"username":"tester","password":"sod12345"}' >/dev/null
S=$(call recipe-save '{"title":"עוגת גבינה","visibility":"public","servings":8,"sections":[{"name":"בצק","ingredients":[{"free_text":"2 כוסות","amount_min":300,"unit":"gram","product":"קמח"}],"steps":[{"text":"לפורר"}]}]}')
check 'נשמר'                 "$S" '"success":true'
check 'הוחזר עם החלקים'      "$S" '"name":"בצק"'
check 'שתי השכבות ברכיב'     "$S" '"free_text":"2 כוסות".*"amount_min":300'
ID=$(top_id "$S")
check 'חיפוש לפי רכיב'       "$(call search '{"q":"קמח"}')" '"title":"עוגת גבינה"'
check 'השלמת מוצר'           "$(call products '{"q":"קמ"}')" '"קמח"'
U=$(call recipe-save "{\"id\":$ID,\"title\":\"עוגת גבינה קלה\",\"visibility\":\"private\",\"sections\":[{\"ingredients\":[{\"free_text\":\"גבינה\"}],\"steps\":[{\"text\":\"לערבב\"}]}]}")
check 'עדכון'                "$U" '"title":"עוגת גבינה קלה"'
call logout >/dev/null
check 'אורח אינו רואה פרטי'  "$(call recipe "{\"id\":$ID}")" 'אינו זמין'
check 'אורח אינו מוצא פרטי'  "$(call search '{"q":"גבינה"}')" '"recipes":\[\]'
call login '{"username":"tester","password":"sod12345"}' >/dev/null
check 'הבעלים מוחק'          "$(call recipe-delete "{\"id\":$ID}")" '"deleted_comments":0'
check 'ואחרי המחיקה 404'     "$(call recipe "{\"id\":$ID}")" 'אינו זמין'
check 'משתמש רגיל אינו רשאי לאבחון' "$(call diag)" 'אין לך הרשאה'
call logout >/dev/null

echo
echo "8ב. מדיה — ארבע ההחלטות של סעיף 10, עם בייטים אמיתיים"
call login '{"username":"tester","password":"sod12345"}' >/dev/null
S=$(call recipe-save '{"title":"עם תמונות","visibility":"public","sections":[{"ingredients":[{"free_text":"משהו"}],"steps":[{"text":"משהו"}]}]}')
RID=$(top_id "$S")
# תמונת PNG אמיתית (1x1) — כדי ש-finfo יזהה אותה מהבייטים
printf '\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82' > "$TMP/real.png"
# קוד PHP שמתחזה לסרטון — הסיומת אומרת mp4, הבייטים אומרים אחרת
printf '<?php echo "pwned"; ?>' > "$TMP/evil.mp4"
# קובץ גדול מהתקרה לתמונה (5MB + קצת), עם כותרת PNG תקינה
{ cat "$TMP/real.png"; head -c 5300000 /dev/zero; } > "$TMP/huge.png"

up() {  # up <file> <recipe_id>
  curl -sS -b "$JAR" -c "$JAR" -F "file=@$1" -F "recipe_id=$2" "http://127.0.0.1:$PORT/recipes-app/upload.php"
}
U=$(up "$TMP/real.png" "$RID")
check 'תמונה אמיתית מתקבלת'          "$U" '"kind":"image"'
check 'השם בדיסק מחולל, לא real.png' "$U" '"url":"data\\\?/media\\\?/[0-9a-f]\{32\}\.png"'
check 'והיא הראשית כברירת מחדל'       "$(call recipe "{\"id\":$RID}")" '"main_media_id":[1-9]'
check 'PHP בשם clip.mp4 נדחה לפי הבייטים' "$(up "$TMP/evil.mp4" "$RID")" 'לא נתמך'
check 'גדול מהתקרה נדחה'              "$(up "$TMP/huge.png" "$RID")" 'גדול מדי'
check 'קישור מתקבל'                   "$(call media-link "{\"recipe_id\":$RID,\"url\":\"https://youtu.be/abc123\"}")" '"source":"link"'
check 'javascript: נדחה'              "$(call media-link "{\"recipe_id\":$RID,\"url\":\"javascript:alert(1)\"}")" 'אינו כתובת'
check 'המקצב מדווח שימוש'             "$(call media-limits)" '"used":[1-9]'
check 'תקרת תמונה = האפיון (5MB), כי השרת מרשה יותר' "$(call media-limits)" '"image_max":5242880'
check 'תקרת וידאו = האפיון (20MB)'    "$(call media-limits)" '"video_max":20971520'
MID=$(printf '%s' "$U" | python3 -c 'import sys,json; print(json.load(sys.stdin)["media"]["id"])')
check 'מחיקה משחררת מקום'             "$(call media-delete "{\"id\":$MID}")" '"used":0'
call logout >/dev/null
# משתמש אחר לא מעלה למתכון שאינו שלו
call login '{"username":"owner","password":"sod12345"}' >/dev/null
check 'אחר אינו מעלה למתכון של tester' "$(up "$TMP/real.png" "$RID")" 'אינו שלך'
call logout >/dev/null
check 'אורח אינו מעלה'                "$(up "$TMP/real.png" "$RID")" 'נדרשת התחברות'

echo
echo "8ג. הגדרות — מפתח מול מנהל מול משתמש, בגבול ה-HTTP"
# tester הוא משתמש רגיל; owner הוא המפתח (ה-admin הראשון)
call login '{"username":"tester","password":"sod12345"}' >/dev/null
check 'משתמש רגיל קורא הגדרות ציבוריות (לעורך)' "$(call settings-public)" '"video_max_bytes"'
check 'אך אינו מפתח'                     "$(call settings-public)" '"is_developer":false'
check 'ואינו כותב אותן'                  "$(call settings-public-save '{"values":{"video_max_bytes":52428800}}')" 'מפתח'
check 'ואינו רואה משתמשים'               "$(call users)" 'מפתח'
check 'פרטיות — שלו'                     "$(call settings-private)" '"display_name":"בודק"'
check 'שם תצוגה משתנה'                   "$(call settings-private-save '{"display_name":"בודק חדש"}')" '"success":true'
check 'me משקף את זה'                    "$(call me)" '"display_name":"בודק חדש"'
call logout >/dev/null
call login '{"username":"owner","password":"sod12345"}' >/dev/null
check 'המפתח מזוהה'                      "$(call me)" '"is_developer":true'
check 'המפתח כותב הגדרה ציבורית'         "$(call settings-public-save '{"values":{"video_max_bytes":52428800}}')" '"value":52428800'
check 'ערך מחוץ לגבולות נדחה'            "$(call settings-public-save '{"values":{"video_max_bytes":1}}')" 'בין'
check 'רשימת משתמשים'                    "$(call users)" '"username":"tester"'
TID=$(call users | python3 -c 'import sys,json; print([u["id"] for u in json.load(sys.stdin)["users"] if u["username"]=="tester"][0])')
check 'דריסה אישית ל-tester'             "$(call user-limit "{\"user_id\":$TID,\"key\":\"quota_bytes\",\"value\":12582912}")" '"limit_quota":12582912'
check 'המפתח אינו חוסם את עצמו'          "$(call user-block '{"user_id":1,"blocked":true}')" 'המפתח'
check 'חסימת tester'                     "$(call user-block "{\"user_id\":$TID,\"blocked\":true}")" '"blocked":true'
call logout >/dev/null
check 'tester החסום אינו נכנס'           "$(call login '{"username":"tester","password":"sod12345"}')" 'חסום'
call login '{"username":"owner","password":"sod12345"}' >/dev/null
call user-block "{\"user_id\":$TID,\"blocked\":false}" >/dev/null
call logout >/dev/null
# tester חוזר עם המגבלה האישית — והעורך שלו רואה אותה, לא את הציבורית
call login '{"username":"tester","password":"sod12345"}' >/dev/null
check 'המדיה של tester מכבדת את הדריסה (12MB)' "$(call media-limits)" '"quota":12582912'
# ההגדרה הציבורית היא 50MB, אך שרת הבדיקה מרשה 32M — והתקרה בפועל היא
# המינימום. זו הבדיקה שההגדרה לא יכולה להבטיח יותר ממה שהשרת מקבל.
check 'סרטון: min(ציבורי 50MB, שרת 32MB) = 32MB' "$(call media-limits)" '"video_max":33554432'
call logout >/dev/null

echo
echo "9. אבחון למנהל"
call login '{"username":"owner","password":"sod12345"}' >/dev/null
D=$(call diag)
check 'מחזיר מגבלות PHP'     "$D" '"upload_max_filesize"'
check 'מחזיר תקרת וידאו בפועל' "$D" '"video_effective"'
check 'מחזיר נפח פנוי'       "$D" '"free"'
check 'מחזיר מצב mail'       "$D" '"function_exists"'
check 'foreign_keys דלוק'    "$D" '"foreign_keys":true'
call logout >/dev/null

echo
if [ "$FAIL" -ne 0 ]; then
  echo "❌ נכשלו בדיקות. לוג השרת:"
  tail -20 "$TMP/server.log"
  exit 1
fi
echo "✅ כל הבדיקות עברו"
