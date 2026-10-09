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
define('IMPORT_ALLOW_LOCAL', true);   // הייבוא מביא מ-127.0.0.1 — רק כאן
define('SECRETS_FILE', '$TMP/secrets.json');
define('AI_ENDPOINT', 'http://127.0.0.1:$((PORT + 1))/ai-mock.php');   // מדמה את Anthropic
define('SCOUT_TEST_ROBOTS', true);   // גם בשרת המקומי — לבדוק את robots.txt של קבצי הדוגמה
PHPBOOT

# sendmail_path הוא PHP_INI_SYSTEM: ini_set בזמן ריצה אינו משנה אותו, ולכן
# הוא נמסר לשרת בשורת הפקודה. /bin/true מדמה MTA שמקבל — כדי שהבדיקה
# תבחן את מסלול ההצלחה; מסלול הכשל נבדק ב-auth-check.php.
# כמה תהליכים: הייבוא ברקע (8ח2) מעיר עובד בבקשת HTTP לאותו שרת, והעובד ישן
# בין פריטים — בשרת חד-תהליכי הוא היה תוקע את כל שאר הבדיקות.
PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$PORT" -t . \
  -d auto_prepend_file="$TMP/boot.php" \
  -d sendmail_path=/bin/true \
  -d upload_max_filesize=32M -d post_max_size=40M \
  >"$TMP/server.log" 2>&1 &
SERVER=$!
# שרת שני לקבצי הדוגמה של הייבוא: השרת המובנה של PHP הוא חד-חוטי, ובקשה
# שמביאה דף מאותו שרת הייתה נתקעת עד timeout.
FXPORT=$((PORT + 1))
echo 1 > "$TMP/fxgen"   # "דור" של אתר הדוגמה לאינדקס (8יא) — הבדיקה מעלה אותו כדי לדמות עדכון שבועי
FX_STATE="$TMP/fxgen" php -S "127.0.0.1:$FXPORT" -t recipes-app/tools/fixtures >"$TMP/fixtures.log" 2>&1 &
FXSERVER=$!
trap 'kill $SERVER $FXSERVER 2>/dev/null; rm -rf "$TMP"' EXIT

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
  # grep -c ולא grep -q: עם pipefail, grep -q שסוגר את הצינור אחרי ההתאמה
  # הראשונה משאיר את printf עם SIGPIPE — והבדיקה נכשלת דווקא על תשובה
  # גדולה שכן מכילה את המחרוזת. נתפס על דף HTML של 128 שורות יומן.
  if [ "$(printf '%s' "$2" | grep -c -- "$3")" -gt 0 ]; then
    printf '  \xE2\x9C\x85 %s\n' "$1"
  else
    printf '  \xE2\x9D\x8C %s\n     התקבל: %s\n' "$1" "$2"
    FAIL=1
  fi
}

echo
echo "1. אורח"
check 'me מחזיר success עם user=null' "$(call me)" '"user":null'
check 'me מדווח את גרסת הנכסים שבשרת'  "$(call me)" '"assets_version":"[0-9]\{4\}-[0-9][0-9]-[0-9][0-9][a-z]*"'

echo
echo "2. הרשמה — הראשון הוא המנהל, ומאומת מראש"
R=$(call register '{"username":"owner","email":"owner@example.com","password":"sod12345","display_name":"בעלים"}')
check 'ההרשמה הצליחה'      "$R" '"success":true'
check 'המנהל מאומת מראש'   "$R" '"verified":true'
check 'נשלח דוא"ל בכל זאת' "$R" '"mail_sent":true'
L=$(call login '{"username":"owner","password":"sod12345"}')
check 'המנהל נכנס מיד'     "$L" '"role":"admin"'
# באג שתוקן: הכניסה החזירה משתמש בלי is_developer, והתפריט הסתיר את פריטי המפתח עד רענון
check 'הכניסה מחזירה is_developer, כמו me' "$L" '"is_developer":true'
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

echo
echo "3א. שליחה חוזרת — תשובה אחידה, בלי לחשוף מי רשום"
A=$(call resend-verification '{"username":"tester"}')
B=$(call resend-verification '{"username":"nobody"}')
check 'למשתמש קיים: הודעה אחידה'   "$A" 'אם החשבון קיים'
check 'לשאינו קיים: אותה תשובה'    "$A" "$(printf '%s' "$B" | sed 's/[][\.*^$]/\\&/g')"

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
echo "8ד. תגובות, פתק ומועדף — דרך ה-API"
# tester נכנס ויוצר מתכון ציבורי; אחר כך המנהל (owner) מגיב ושומר
call login '{"username":"tester","password":"sod12345"}' >/dev/null
SID=$(top_id "$(call recipe-save '{"title":"מרק ציבורי","visibility":"public","sections":[{"ingredients":[{"free_text":"מים"}],"steps":[{"text":"להרתיח"}]}]}')")
check 'המתכון מגיע עם comments/note/is_favorite' "$(call recipe "{\"id\":$SID}")" '"comments":\[\],"note":null,"is_favorite":false'
check 'הבעלים אינו שומר את שלו'   "$(call favorite-toggle "{\"recipe_id\":$SID}")" 'שלך'
check 'פתק פרטי נשמר'             "$(call note-save "{\"recipe_id\":$SID,\"text\":\"פחות מלח\"}")" '"text":"פחות מלח"'
call logout >/dev/null
call login '{"username":"owner","password":"sod12345"}' >/dev/null
C=$(call comment-add "{\"recipe_id\":$SID,\"text\":\"טעים\"}")
check 'המנהל מגיב'                 "$C" '"user_name":"owner"\|"text":"טעים"'
CID=$(top_id "$C")
check 'תשובה'                      "$(call comment-add "{\"recipe_id\":$SID,\"parent_id\":$CID,\"text\":\"תודה\"}")" '"replies":\[{[^]]*"text":"תודה"'
check 'המנהל אינו רואה את הפתק של tester' "$(call recipe "{\"id\":$SID}")" '"note":null'
check 'שמירה למועדפים'             "$(call favorite-toggle "{\"recipe_id\":$SID}")" '"is_favorite":true'
check 'ברשימת המועדפים'            "$(call favorites)" '"status":"ok","id":'"$SID"',"title":"מרק ציבורי"'
call logout >/dev/null
call login '{"username":"tester","password":"sod12345"}' >/dev/null
check 'הבעלים מוחק תגובה של אחר'  "$(call comment-delete "{\"id\":$CID}")" '"comments":\[\]'
check 'הבעלים סוגר תגובות'         "$(call recipe-comments-open "{\"recipe_id\":$SID,\"open\":false}")" '"comments_open":false'
call recipe-delete "{\"id\":$SID}" >/dev/null
call logout >/dev/null
call login '{"username":"owner","password":"sod12345"}' >/dev/null
check 'אחרי מחיקת המקור — המועדף נשאר עם השם' "$(call favorites)" '"status":"gone","id":null,"title":"מרק ציבורי"'
call logout >/dev/null
call login '{"username":"tester","password":"sod12345"}' >/dev/null

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
check 'tester מאומת — אין מה לשלוח שוב'   "$(call user-resend "{\"user_id\":$TID}")" 'כבר מאומת'
call register '{"username":"waiting","email":"w@example.com","password":"sod12345"}' >/dev/null
WID=$(call users | python3 -c 'import sys,json; print([u["id"] for u in json.load(sys.stdin)["users"] if u["username"]=="waiting"][0])')
check 'הרשימה מציגה שהדוא"ל נשלח'         "$(call users)" '"username":"waiting"[^}]*"last_mail_ok":true'
check 'המפתח שולח שוב — בלי קירור'        "$(call user-resend "{\"user_id\":$WID}")" '"mail_sent":true'
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
echo "8ו. ייבוא מהרשת — דף אמיתי שמוגש מהשרת המקומי, ואז שמירה עם קרדיט"
call login '{"username":"tester","password":"sod12345"}' >/dev/null
FX="http://127.0.0.1:$FXPORT/jsonld-10dakot.html"
D=$(call import-preview "{\"url\":\"$FX\"}")
check 'חולץ: שם, מקור, רכיבים'      "$D" '"title":"עוגת גבינה של בית מלון"[^}]*"visibility":"private"'
check 'המקור בטיוטה'                "$D" '"source_url":"http:\\/\\/127.0.0.1'
check 'תמונה ממתינה'                "$D" '"images":\["https:\\/\\/www.10dakot.co.il'
check 'כתובת פנימית נדחית בייצור'   "$(call import-preview '{"url":"ftp://x/y"}')" 'http'
check 'דף בלי מתכון'                "$(call import-preview "{\"url\":\"http://127.0.0.1:$FXPORT/norecipe.html\"}")" 'לא מצאתי'
IMP=$(printf '%s' "$D" | python3 -c 'import sys,json; d=json.load(sys.stdin)["draft"]; d.pop("pending_media"); d.pop("warnings"); print(json.dumps(d,ensure_ascii=False))')
IID=$(top_id "$(call recipe-save "$IMP")")
check 'נשמר עם קרדיט'               "$(call recipe "{\"id\":$IID}")" '"source":{"url":"http:\\/\\/127.0.0.1'
check 'תמונה כקישור'                "$(call media-link "{\"recipe_id\":$IID,\"url\":\"https://www.10dakot.co.il/x.jpg\",\"kind\":\"image\"}")" '"kind":"image","source":"link"'
check 'הרשימה: thumb חיצוני ושם האתר' "$(call search '{"q":"בית מלון"}')" '"thumb":"https:\\/\\/www.10dakot.co.il\\/x.jpg","source_name":"'
check 'סינון מהרשת'                 "$(call search '{"from_web":true}')" '"title":"עוגת גבינה של בית מלון"'
check 'הייבוא נרשם ביומן'           "$(call logout >/dev/null; call login '{"username":"owner","password":"sod12345"}' >/dev/null; call log '{"action":"import-preview"}')" 'json-ld'
IL=$(call import-log '{}')
check 'יומן ייבוא: ההבאה, הכשל, והשמירה מהעורך' "$IL" '"kind":"editor-save"[^}]*"recipe_id":'"$IID"
check 'יומן ייבוא: הכשל עם האבחון'   "$IL" '"ok":false,"error":"לא מצאתי[^}]*"diag":{"http_status":200,"html_bytes"'
check 'יומן ייבוא: ההצלחה עם הטקסט'  "$IL" '"kind":"preview"[^}]*"method":"json-ld"'
check 'יומן ייבוא: סינון לפי תוצאה'  "$(call import-log '{"ok":"0"}' | grep -c '"ok":true')" '^0$'
call logout >/dev/null

echo
echo "8ז. תיעוד פרטי של הייבוא, מפתח API, וניסוח מחדש בבינה (דרך מדמה)"
call login '{"username":"tester","password":"sod12345"}' >/dev/null
check 'התיעוד נשמר עם המתכון המיובא'   "$(call import-snapshot "{\"recipe_id\":$IID}")" '"extracted_by":"json-ld"[^}]*"raw":{"title":"עוגת גבינה של בית מלון"'
check 'מתכון רגיל — בלי תיעוד'          "$(call import-snapshot "{\"recipe_id\":$RID}")" '"snapshot":null'
check 'משיכה חוזרת מהמקור מחליפה את התיעוד' "$(call import-snapshot-refresh "{\"recipe_id\":$IID}")" '"extracted_by":"json-ld"'
check 'משיכה למתכון בלי מקור נדחית'       "$(call import-snapshot-refresh "{\"recipe_id\":$RID}")" 'אין מקור'
check 'בלי מפתח — הודעה ברורה'           "$(call import-rewrite '{"title":"x","sections":[{"name":"","steps":["מערבבים."]}]}')" 'אינו מופעל'
check 'משתמש רגיל אינו רואה סודות'      "$(call secrets)" 'מפתח'
call logout >/dev/null
call login '{"username":"owner","password":"sod12345"}' >/dev/null
check 'מפתח בצורה לא נכונה נדחה'        "$(call secret-set '{"key":"anthropic_api_key","value":"abc"}')" 'לא נראה'
check 'קידומת לא נכונה נדחית'           "$(call secret-set '{"key":"anthropic_api_key","value":"xx-1234567890123456789012345"}')" 'sk-ant-'
check 'מפתח נשמר — מוצג רק כסיומת'      "$(call secret-set '{"key":"anthropic_api_key","value":"sk-ant-test-0000000000000000wxyz"}')" '"set":true,"hint":"…wxyz"'
check 'המפתח עצמו אינו ביומן'           "$(call log '{"action":"secret-set"}' | grep -c 'wxyz')" '^0$'
check 'קובץ הסודות לא קריא לאחרים'      "$(stat -c '%a' "$TMP/secrets.json")" '^600$'
call logout >/dev/null
check 'לא מחובר — אין ניסוח'             "$(call import-rewrite '{"sections":[]}')" 'נדרשת התחברות'
call login '{"username":"tester","password":"sod12345"}' >/dev/null
check 'ai-status: זמין'                  "$(call ai-status)" '"available":true'
check 'התיעוד של אחר נדחה'              "$(call logout >/dev/null; call login '{"username":"owner","password":"sod12345"}' >/dev/null; call import-snapshot "{\"recipe_id\":$IID}")" 'רק למי שייבא'
R=$(call import-rewrite '{"title":"עוגה","sections":[{"name":"","steps":["מחממים תנור ל-180 מעלות.","מערבבים קמח וסוכר בקערה גדולה."]},{"name":"ציפוי","steps":["ממיסים שוקולד."]}]}')
check 'נוסח מחדש — שני חלקים, הסדר נשמר' "$R" '"sections":\[{"name":"","steps":\["בשלב 1[^]]*"בשלב 2[^]]*\]},{"name":"ציפוי"'
check 'דמיון נמוך, ניסיון אחד'           "$R" '"similarity":0,"too_close":false,"model":"claude-opus-5-5","attempts":1'
check 'הניסוח נרשם ביומן עם טוקנים'     "$(call log '{"action":"ai-rewrite"}')" '"in_tokens":300,"out_tokens":200'
call logout >/dev/null; call login '{"username":"tester","password":"sod12345"}' >/dev/null
check 'שמירה כ"מבוסס על"'               "$(call recipe-save "{\"id\":$IID,\"title\":\"עוגת גבינה של בית מלון\",\"source_rewritten\":true,\"sections\":[{\"ingredients\":[{\"free_text\":\"4 ביצים\"}],\"steps\":[{\"text\":\"בשלב 1: מחממים.\"}]}]}")" '"rewritten":true'
call logout >/dev/null

echo
echo "8ח. סורק אתרים — דף רשימה, סימון, ייבוא אחד-אחד, robots.txt"
FXB="http://127.0.0.1:$FXPORT"
call login '{"username":"tester","password":"sod12345"}' >/dev/null
check 'משתמש רגיל אינו רואה את הסורק'   "$(call scout-list)" 'מפתח'
call logout >/dev/null
call login '{"username":"owner","password":"sod12345"}' >/dev/null
P=$(call scout-page "{\"url\":\"$FXB/listing.html\"}")
check 'נמצאו שישה מועמדים (לא ניווט, לא אתר אחר)' "$P" '"found":6'
check 'זה שכבר יובא ידנית מסומן "יובא" מראש'     "$P" '"url":"http:\\/\\/127.0.0.1:'"$FXPORT"'\\/jsonld-10dakot.html"[^}]*"status":"imported"'
check 'קריאה חוזרת — אפס חדשים'                 "$(call scout-page "{\"url\":\"$FXB/listing.html\"}")" '"new":0'
check 'sitemap נקרא'                             "$(call scout-page "{\"url\":\"$FXB/sitemap.xml\"}")" '"found":3'
SC=$(call scout-list '{"status":"new"}' | python3 -c 'import sys,json; d=json.load(sys.stdin); print([i["id"] for i in d["items"] if i["url"].endswith("jsonld-carine.html") and "127.0.0.1:" in i["url"]][0])')
check 'תצוגה מקדימה של מועמד — הטיוטה בלי שמירה' "$(call scout-preview "{\"id\":$SC}")" '"title":"קרין גורן מכינה.*"extracted_by":"json-ld"'
check 'אחרי התצוגה המקדימה — עדיין "חדש"'        "$(call scout-list '{"status":"new"}')" 'jsonld-carine.html'
check 'סימון לייבוא'                             "$(call scout-mark "{\"ids\":[$SC],\"status\":\"wanted\",\"status\":\"wanted\"}")" '"counts":{[^}]*"wanted":1'
R=$(call scout-import "{\"id\":$SC,\"rewrite\":true}")
check 'יובא: מתכון פרטי עם קרדיט, נוסח מחדש'     "$R" '"recipe_id":[0-9]*,"title":"קרין גורן מכינה[^}]*"rewritten":true'
check 'ובסורק מסומן יובא'                        "$(call scout-list '{"status":"imported"}')" 'jsonld-carine.html'
SN=$(call scout-list '{"status":"new"}' | python3 -c 'import sys,json; d=json.load(sys.stdin); print([i["id"] for i in d["items"] if i["url"].endswith("norecipe.html") and "127.0.0.1:" in i["url"]][0])')
check 'robots.txt אוסר → לא מובא, מסומן שגיאה'   "$(call scout-import "{\"id\":$SN}")" 'robots.txt'
# שמירה בעורך של טיוטה עם source_url של מועמד → המועמד מסומן יובא (הקישור האוטומטי)
MID=$(top_id "$(call recipe-save "{\"title\":\"סלט מהעורך\",\"visibility\":\"private\",\"source_url\":\"$FXB/recipe/soup-1/\",\"sections\":[{\"ingredients\":[{\"free_text\":\"מים\"}],\"steps\":[{\"text\":\"להרתיח\"}]}]}")")
check 'שמירה בעורך עם source_url של מועמד → "יובא"' "$(call scout-list '{"status":"imported"}')" '"url":"http:\\/\\/127.0.0.1:'"$FXPORT"'\\/recipe\\/soup-1\\/"[^}]*"status":"imported","recipe_id":'"$MID"

check 'הפריט במצב שגיאה'                         "$(call scout-list '{"status":"error"}')" 'norecipe.html'
check 'הסריקה והייבוא ביומן'                     "$(call log '{"action":"scout-import"}')" '"rewritten":true'
call logout >/dev/null

echo
echo "8ח2. ייבוא ברקע — העובד בשרת ממשיך בלי הדפדפן, ונעצר כשעוצרים"
call login '{"username":"owner","password":"sod12345"}' >/dev/null
call scout-page "{\"url\":\"$FXB/listing-bg.html\"}" >/dev/null
BG=$(call scout-list '{"status":"new"}' | python3 -c 'import sys,json; print(",".join(str(i["id"]) for i in json.load(sys.stdin)["items"] if ":'"$FXPORT"'/" in i["url"] and i["url"].endswith(("/microdata.html","/headings.html"))))')
check 'שני מועמדים חדשים'               "$BG" '^[0-9][0-9]*,[0-9][0-9]*$'
call scout-mark "{\"ids\":[$BG],\"status\":\"wanted\"}" >/dev/null
J=$(call scout-job-start '{"gap":10,"rewrite":false}')
check 'הופעל ברקע: running, נשארו 2'      "$J" '"state":"running"[^}]*"left":2'
check 'כתובת ל-cron, עם מפתח'            "$J" '"cron_url":"http:\\/\\/127.0.0.1:'"$PORT"'\\/recipes-app\\/scout-worker.php?key=[0-9a-f]\{40\}"'
# הדפדפן לא עושה כלום מכאן — רק מסתכל. העובד בשרת מייבא בעצמו.
for _ in $(seq 1 40); do
  DONE=$(call scout-job | python3 -c 'import sys,json; print(json.load(sys.stdin)["job"]["done_n"])')
  [ "$DONE" -ge 1 ] && break; sleep 0.5
done
check 'העובד ייבא בלי שהדפדפן ביקש'      "$(call scout-job)" '"done_n":[12][^}]*"recent":\[{"title":"[^"]*","id":[0-9]*,"at":"[^"]*","ok":true,"recipe_id":[0-9]'
check 'וזה נרשם ביומן הייבוא כייבוא בסורק' "$(call import-log '{"kind":"scout-import"}')" '"kind":"scout-import"'
check 'עצירה'                             "$(call scout-job-stop)" '"state":"stopped"'
KEY=$(call scout-job | python3 -c 'import sys,json; print(json.load(sys.stdin)["job"]["cron_url"].split("key=")[1])')
check 'העובד בלי מפתח — 403'              "$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/recipes-app/scout-worker.php?key=nope")" '^403$'
check 'העובד אחרי עצירה — לא מייבא (או שהקודם עוד ישן)'   "$(curl -sS "http://127.0.0.1:$PORT/recipes-app/scout-worker.php?key=$KEY&once=1")" 'idle\|busy'
check 'משתמש רגיל אינו מפעיל'             "$(call logout >/dev/null; call login '{"username":"tester","password":"sod12345"}' >/dev/null; call scout-job-start '{}')" 'מפתח'
call logout >/dev/null

echo
echo "8יא. חיפוש מתכונים ברשת — אינדקס משלנו מ-sitemap, עדכון ברקע, פתיחה מנוסחת, שמירה"
call login '{"username":"tester","password":"sod12345"}' >/dev/null
check 'משתמש רגיל אינו מנהל את האינדקס'  "$(call index-sites)" 'מפתח'
check 'חיפוש באינדקס ריק'                 "$(call web-search '{"q":"עוגה"}')" '"total":0,"indexed":0,"sites":0'
call logout >/dev/null
call login '{"username":"owner","password":"sod12345"}' >/dev/null
check 'הוספת אתר (ה-sitemap שלו)'         "$(call index-site-add "{\"url\":\"$FXB/sm-index.php\",\"name\":\"אתר הדוגמה\"}")" '"host":"127.0.0.1","name":"אתר הדוגמה"'
check 'אותו אתר פעמיים — נדחה'            "$(call index-site-add "{\"url\":\"$FXB/\"}")" 'כבר באינדקס'
check 'הוספה מרובה: קיים מקבל שם בעברית, שורה בלי כתובת — שגיאה' "$(call index-sites-add "{\"text\":\"$FXB/ אתר הדוגמה המעודכן\\nשורה בלי כתובת\"}")" '"status":"renamed".*"status":"error"'
# העובד בשרת מעדכן לבד: גילוי ה-sitemap, חלק המתכונים, סיום
wait_crawl() {
  for _ in $(seq 1 60); do
    call index-sites | python3 -c 'import sys,json; s=json.load(sys.stdin)["sites"][0]; sys.exit(0 if (not s["crawling"] and s["last_crawl_at"] and s["next_in"]>0 and s["titles_pending"]==0) else 1)' && return 0
    sleep 0.5
  done; return 1
}
wait_crawl
IS=$(call index-sites)
check 'האינדקס נבנה ברקע: 5 מתכונים (בלי זבל, בלי דף רכיב)' "$IS" '"entries_n":5[^}]*"next_in":[0-9]\{6\}'
check 'שמות מהדף: אין ממתינים, דף הרשימה "לא מתכון"' "$IS" '"titles_pending":0,"not_recipes":1'
check 'בלוג בלי עברית בכתובת — נמצא לפי השם מהדף' "$(call web-search '{"q":"לחם מחמצת"}')" '"title":"לחם מחמצת ביתי"'
check 'השם מהדף, בלי שם האתר'            "$(call web-search '{"q":"קרין גורן"}')" '"title":"קרין גורן מכינה עוגת יומולדת שילדים אוהבים"'
check '__trashed בלי דף (404) — נמחק'     "$(call web-search '{"q":"trashed"}')" '"total":0'
check 'רק חלק המתכונים נקרא, לא הדפים'      "$(grep -c 'sm-pages.php' "$TMP/fixtures.log")" '^0$'
call logout >/dev/null
call login '{"username":"tester","password":"sod12345"}' >/dev/null
S=$(call web-search '{"q":"עוגות גבינה"}')
check 'חיפוש "עוגות גבינה" — שתיים, לפי גזעים'  "$S" '"total":2'
check 'הביטוי המדויק ראשון'               "$S" '"results":\[{"id":[0-9]*,"title":"עוגות גבינה קרות"'
WID=$(call web-search '{"q":"carine"}' | python3 -c 'import sys,json; print(json.load(sys.stdin)["results"][0]["id"])')
O=$(call web-open "{\"id\":$WID}")
check 'פתיחה: חולץ ונוסח מחדש (מדמה), עם מקור'  "$O" '"title":"קרין גורן מכינה[^"]*".*"source_rewritten":true'
check 'השלבים מנוסחים'                     "$O" '"steps":\[{"text":"בשלב 1'
check 'מכסה: נשארו 19'                     "$O" '"cached":false,"opens_left":19'
check 'פתיחה שנייה — מהמטמון, בלי מכסה'    "$(call web-open "{\"id\":$WID}")" '"cached":true,"opens_left":19'
check 'השם האמיתי נכנס לאינדקס'            "$(call web-search '{"q":"קרין גורן"}')" '"total":1'
SV=$(call web-save "{\"id\":$WID}")
check 'נשמר אצלי'                          "$SV" '"existing":false'
SRID=$(printf '%s' "$SV" | python3 -c 'import sys,json; print(json.load(sys.stdin)["recipe_id"])')
check 'פרטי, "מבוסס על", עם קרדיט'          "$(call recipe "{\"id\":$SRID}")" '"visibility":"private".*"source":{"url":"http:\\/\\/127.0.0.1:'"$FXPORT"'\\/jsonld-carine.html"[^}]*"rewritten":true'
check 'שמירה שנייה — בלי כפילות'           "$(call web-save "{\"id\":$WID}")" '"recipe_id":'"$SRID"',"existing":true'
call logout >/dev/null
call login '{"username":"owner","password":"sod12345"}' >/dev/null
check 'יומן הייבוא: פתיחה ושמירה מהחיפוש'   "$(call import-log '{"kind":"web-save"}')" '"kind":"web-save"'
# עדכון "שבועי": האתר הוריד מתכון ושינה lastmod → נקרא שוב, והמתכון יוצא מהאינדקס
echo 2 > "$TMP/fxgen"
HITS=$(grep -c 'sm-recipes.php' "$TMP/fixtures.log")
call index-site-refresh "{\"id\":$(printf '%s' "$IS" | python3 -c 'import sys,json; print(json.load(sys.stdin)["sites"][0]["id"])')}" >/dev/null
sleep 1; wait_crawl
check 'דור 2: המתכון שהוסר יצא מהאינדקס'    "$(call index-sites)" '"entries_n":4'
check 'וחלק המתכונים נקרא שוב (lastmod השתנה)' "$(( $(grep -c 'sm-recipes.php' "$TMP/fixtures.log") - HITS ))" '^1$'
HITS=$(grep -c 'sm-recipes.php' "$TMP/fixtures.log")
call index-site-refresh "{\"id\":$(printf '%s' "$IS" | python3 -c 'import sys,json; print(json.load(sys.stdin)["sites"][0]["id"])')}" >/dev/null
sleep 1; wait_crawl
check 'עדכון בלי שינוי: חלק המתכונים לא מובא שוב' "$(( $(grep -c 'sm-recipes.php' "$TMP/fixtures.log") - HITS ))" '^0$'
check 'והמתכונים נשארו, ו"לא מתכון" לא חזר'  "$(call index-sites)" '"entries_n":4,"next_in":[0-9]*,"crawling":false,"progress":null,"titles_pending":0,"not_recipes":1'
check 'השם האמיתי שרד את העדכון'           "$(call web-search '{"q":"קרין גורן"}')" '"total":1'
check 'עובד האינדקס בלי מפתח — 403'        "$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/recipes-app/index-worker.php?key=x")" '^403$'
call logout >/dev/null

echo
echo "8ט. מה יש לי בבית — מזווה, התאמה, וזיהוי בתמונה (מדמה)"
check 'אורח — נדרשת התחברות'            "$(call pantry)" 'נדרשת התחברות'
call login '{"username":"tester","password":"sod12345"}' >/dev/null
check 'מזווה ריק עם מוצרי יסוד'          "$(call pantry)" '"items":\[\],"staples":\[{"name":"מלח","on":true'
check 'הוספה בהקלדה, כפילות נבלעת'       "$(call pantry-add '{"names":["קמח","  ביצים ","קמח"],"source":"text"}')" '"added":2'
check 'התאמה: המתכון המיובא (רכיב יחיד: ביצים) מכוסה' "$(call pantry-match '{"max_missing":5}')" '"title":"עוגת גבינה של בית מלון"[^}]*"need":1,"have":1,"coverage":1,"missing":\[\]'
check 'סינון 0 חסרים — רק המכוסים'        "$(call pantry-match '{"max_missing":0}')" '"pantry_count":2'
check 'כיבוי מוצר יסוד'                  "$(call pantry-staple '{"name":"מלח","on":false}')" '"name":"מלח","on":false'
PH=$(curl -sS -b "$JAR" -c "$JAR" -F "file=@$TMP/real.png" "http://127.0.0.1:$PORT/recipes-app/pantry.php")
check 'זיהוי בתמונה (מדמה): שלושה מוצרים, בלי כפילות' "$PH" '"products":\["עגבנייה","גבינה צהובה","ביצה"\]'
check 'הוספה מהזיהוי כ-photo'            "$(call pantry-add '{"names":["עגבנייה","גבינה צהובה"],"source":"photo"}')" '"source":"photo"'
PID=$(call pantry | python3 -c 'import sys,json; print(json.load(sys.stdin)["items"][0]["id"])')
check 'הסרה'                             "$(call pantry-remove "{\"id\":$PID}")" '"success":true'
check 'הזיהוי ביומן עם טוקנים'           "$(call logout >/dev/null; call login '{"username":"owner","password":"sod12345"}' >/dev/null; call log '{"action":"pantry-photo"}')" '"in_tokens":1500'
call logout >/dev/null

echo
echo "8י. רשימת קניות ומצב בישול"
call login '{"username":"tester","password":"sod12345"}' >/dev/null
SL=$(call shopping-create "{\"recipes\":{\"$RID\":2},\"name\":\"שבת\"}")
check 'רשימה ממתכון ×2'                 "$SL" '"name":"שבת"[^}]*"recipes":\[{"id":'"$RID"',"title":"[^"]*","factor":2}'
LID=$(printf '%s' "$SL" | python3 -c 'import sys,json; print(json.load(sys.stdin)["list"]["id"])')
IT=$(printf '%s' "$SL" | python3 -c 'import sys,json; print(json.load(sys.stdin)["list"]["items"][0]["id"])')
check 'סימון שורה'                      "$(call shopping-check "{\"item_id\":$IT,\"checked\":true}")" '"success":true'
check 'שורה ידנית'                      "$(call shopping-add-item "{\"id\":$LID,\"label\":\"חלב\"}")" '"label":"חלב"[^}]*"manual":true'
check 'טקסט לשיתוף'                     "$(call shopping-text "{\"id\":$LID}")" '🛒 שבת'
check 'רשימת הרשימות'                   "$(call shopping-lists)" '"name":"שבת"[^}]*"recipes":1'
check 'של אחר — לא'                     "$(call logout >/dev/null; call login '{"username":"owner","password":"sod12345"}' >/dev/null; call shopping-get "{\"id\":$LID}")" 'אינה קיימת'
call logout >/dev/null; call login '{"username":"tester","password":"sod12345"}' >/dev/null
STEP=$(call recipe "{\"id\":$RID}" | python3 -c 'import sys,json; print(json.load(sys.stdin)["recipe"]["sections"][0]["steps"][0]["id"])')
check 'בישול: בוצע'                     "$(call cook-done "{\"step_id\":$STEP,\"done\":true}"; call cook-progress "{\"recipe_id\":$RID}")" '"done":\['"$STEP"'\]'
check 'איפוס'                           "$(call cook-reset "{\"recipe_id\":$RID}"; call cook-progress "{\"recipe_id\":$RID}")" '"done":\[\]'
check 'מחיקת רשימה'                     "$(call shopping-delete "{\"id\":$LID}")" '"lists":\[\]'
call logout >/dev/null

echo
echo "8ה. יומן — כל בקשה נרשמה, וטוקן צפייה עם תוקף"
call login '{"username":"owner","password":"sod12345"}' >/dev/null
L=$(call log '{"limit":500}')
check 'הכניסה הראשונה של owner נרשמה'         "$L" '"username":"owner","action":"login"'
check 'כשל הרשמה נרשם כ-warn עם ההודעה'         "$L" '"level":"warn"[^}]*"action":"register","message":"[^}]*כבר בשימוש'
check 'שדה סיסמה אינו ביומן'                   "$(printf '%s' "$L" | grep -c 'sod12345')" '^0$'
check 'סינון לפי פעולה'                        "$(call log '{"action":"recipe-save"}')" '"action":"recipe-save"'
check 'סינון לפי רמה'                          "$(call log '{"level":"error","limit":500}')" '"rows":\['
check 'שגיאת דפדפן נרשמת'                      "$(call client-log '{"level":"error","message":"TypeError: x is null","where":"app.js:12"}')" '"success":true'
check 'ומופיעה ביומן'                          "$(call log '{"action":"client-error"}')" 'TypeError: x is null'
T=$(call log-token-create '{"label":"לקלוד","ttl_minutes":60}')
check 'טוקן נוצר'                              "$T" '"token":"[0-9a-f]\{48\}"'
TOK=$(printf '%s' "$T" | python3 -c 'import sys,json; print(json.load(sys.stdin)["token"]["token"])')
check 'tester (לא מפתח) אינו רואה יומן'        "$(call logout >/dev/null; call login '{"username":"tester","password":"sod12345"}' >/dev/null; call log)" 'מפתח'
check 'ולא יוצר טוקן'                          "$(call log-token-create '{"label":"x","ttl_minutes":60}')" 'מפתח'
call logout >/dev/null
check 'logs.php בלי כניסה, עם הטוקן — JSON'    "$(curl -sS "http://127.0.0.1:$PORT/recipes-app/logs.php?token=$TOK&format=json&action=login")" '"action":"login"'
check 'ובטקסט'                                 "$(curl -sS "http://127.0.0.1:$PORT/recipes-app/logs.php?token=$TOK&format=text&action=login")" 'INFO .* owner login'
check 'ודף HTML'                               "$(curl -sS "http://127.0.0.1:$PORT/recipes-app/logs.php?token=$TOK")" '<table class="logtable"'
check 'אפליקציה להתקנה: manifest'            "$(curl -sS "http://127.0.0.1:$PORT/recipes-app/manifest.webmanifest")" '"display": "standalone"'
check 'ו-service worker'                       "$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/recipes-app/service-worker.js")" '^200$'
check 'בלי קישור חזרה לדף הכלים'              "$(grep -c '\.\./index.html' recipes-app/index.html)" '^0$'
check 'יומן הייבוא דרך הטוקן — טקסט עם המקור'  "$(curl -sS "http://127.0.0.1:$PORT/recipes-app/logs.php?token=$TOK&view=import&format=text")" 'מקור: http://127.0.0.1:'"$FXPORT"'/jsonld-10dakot.html'
check 'יומן הייבוא — HTML'                      "$(curl -sS "http://127.0.0.1:$PORT/recipes-app/logs.php?token=$TOK&view=import&ok=0")" 'class="ilog ilog--err"'
check 'טוקן שגוי — 403'                        "$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/recipes-app/logs.php?token=$(printf '0%.0s' $(seq 48))")" '^403$'
call login '{"username":"owner","password":"sod12345"}' >/dev/null
check 'הצפייה נרשמה ביומן עם שם הטוקן'         "$(call log '{"action":"log-view"}')" 'לקלוד'
TID2=$(call log-tokens | python3 -c 'import sys,json; print(json.load(sys.stdin)["tokens"][0]["id"])')
check 'ביטול'                                  "$(call log-token-revoke "{\"id\":$TID2}")" '"active":false'
check 'אחרי ביטול — 403'                       "$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/recipes-app/logs.php?token=$TOK")" '^403$'
check 'תוקף לא חוקי נדחה'                      "$(call log-token-create '{"label":"x","ttl_minutes":1}')" 'קצר'

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
