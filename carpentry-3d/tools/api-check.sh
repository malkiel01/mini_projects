#!/usr/bin/env bash
# בדיקת api.php מקצה לקצה על שרת PHP אמיתי, במסד זמני.
# הרצה: bash carpentry-3d/tools/api-check.sh
#
# עוברת את המסלול שהדפדפן עובר: הקמת המנהל, יצירת נגר, כניסה, פרויקטים
# (שמירה, רשימה, שכפול, שיתוף, צפיית לקוח), ספריית חומרים (diff, תמונה),
# תעריפים, סוגי מוצרים — והרשאות: נגר לא רואה פרויקט של אחר ולא מנהל משתמשים.

set -uo pipefail
cd "$(dirname "$0")/../.."   # שורש הריפו: כך /carpentry-3d/api.php קיים בשרת

PORT=${PORT:-8793}
TMP=$(mktemp -d)
FAIL=0

cat > "$TMP/boot.php" <<PHPBOOT
<?php
define('DB_FILE', '$TMP/t.sqlite');
define('MEDIA_DIR', '$TMP/media');
PHPBOOT

php -S "127.0.0.1:$PORT" -t . -d auto_prepend_file="$TMP/boot.php" >"$TMP/server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null; rm -rf "$TMP"' EXIT
for i in $(seq 1 30); do curl -s "http://127.0.0.1:$PORT/carpentry-3d/api.php?action=me" >/dev/null && break; sleep 0.2; done

API="http://127.0.0.1:$PORT/carpentry-3d/api.php"
# call <jar> <json>  → גוף התשובה
call() { curl -s -b "$1" -c "$1" -H 'Content-Type: application/json' -d "$2" "$API"; }
get()  { curl -s -b "$1" "$API?$2"; }
# ok <name> <json> <jq-expr>  — הביטוי חייב להחזיר true
ok() {
  local name=$1 json=$2 expr=$3
  if printf '%s' "$json" | python3 -c "import sys,json; d=json.load(sys.stdin); sys.exit(0 if ($expr) else 1)" 2>/dev/null; then
    echo "  ✓ $name"
  else
    echo "  ✗ $name"; echo "      $json" | head -c 400; echo; FAIL=1
  fi
}

A="$TMP/admin"; C="$TMP/carp"; C2="$TMP/carp2"; V="$TMP/viewer"; X="$TMP/anon"

echo "הקמה וכניסה"
ok 'מסד ריק → setup' "$(get "$X" action=me)" "d['setup']==True and d['user'] is None"
ok 'סיסמה קצרה נדחית' "$(call "$A" '{"action":"setup","email":"boss@x.il","password":"short","name":"הבוס"}')" "d['success']==False"
ok 'setup יוצר מנהל ומחבר' "$(call "$A" '{"action":"setup","email":"Boss@x.il","password":"secret123","name":"הבוס"}')" "d['user']['role']=='admin' and d['user']['email']=='boss@x.il'"
ok 'setup נסגר' "$(call "$X" '{"action":"setup","email":"b2@x.il","password":"secret123"}')" "d['success']==False"
ok 'me אחרי setup' "$(get "$A" action=me)" "d['user']['name']=='הבוס' and d['setup']==False"
ok 'אנונימי נחסם' "$(call "$X" '{"action":"projects-list"}')" "d['success']==False"

echo "משתמשים"
ok 'מנהל יוצר נגר' "$(call "$A" '{"action":"user-create","email":"dan@x.il","password":"secret123","name":"דן","role":"carpenter"}')" "d['user']['role']=='carpenter'"
ok 'כתובת כפולה נדחית' "$(call "$A" '{"action":"user-create","email":"dan@x.il","password":"secret123"}')" "d['success']==False"
ok 'נגר שני' "$(call "$A" '{"action":"user-create","email":"gil@x.il","password":"secret123","name":"גיל","role":"carpenter"}')" "d['success']"
ok 'צופה' "$(call "$A" '{"action":"user-create","email":"see@x.il","password":"secret123","name":"צופה","role":"viewer"}')" "d['user']['role']=='viewer'"
ok 'סיסמה שגויה' "$(call "$C" '{"action":"login","email":"dan@x.il","password":"wrong1234"}')" "d['success']==False"
ok 'נגר נכנס' "$(call "$C" '{"action":"login","email":"dan@x.il","password":"secret123"}')" "d['user']['name']=='דן'"
ok 'נגר שני נכנס' "$(call "$C2" '{"action":"login","email":"gil@x.il","password":"secret123"}')" "d['success']"
ok 'צופה נכנס' "$(call "$V" '{"action":"login","email":"see@x.il","password":"secret123"}')" "d['success']"
ok 'נגר לא מנהל משתמשים' "$(call "$C" '{"action":"users-list"}')" "d['success']==False"
ok 'רשימת משתמשים למנהל' "$(call "$A" '{"action":"users-list"}')" "len(d['users'])==4"
ok 'מנהל לא חוסם את עצמו' "$(call "$A" '{"action":"user-update","id":1,"patch":{"blocked":true}}')" "d['success']==False"

echo "ניהול: תוקף, מחיקה, מצב המערכת"
ok 'משתמש עם תוקף שפג' "$(call "$A" '{"action":"user-create","name":"פג","email":"old@x.il","password":"password8","role":"carpenter","valid_until":"2020-01-01"}')" "d['user']['id']==5 and d['user']['in_period']==False"
ok 'הוא לא יכול להיכנס' "$(call "$X" '{"action":"login","email":"old@x.il","password":"password8"}')" "d['success']==False and 'בתוקף' in d['error']"
ok 'תאריך לא תקין נדחה' "$(call "$A" '{"action":"user-update","id":5,"patch":{"valid_until":"2030-13-45"}}')" "d['success']==False"
ok 'הארכת התוקף' "$(call "$A" '{"action":"user-update","id":5,"patch":{"valid_until":"2099-12-31","valid_from":"2020-01-01"}}')" "d['user']['in_period']==True and d['user']['valid_until']=='2099-12-31'"
ok 'עכשיו נכנס, ונרשמת כניסה אחרונה' "$(call "$TMP/old.jar" '{"action":"login","email":"old@x.il","password":"password8"}')" "d['user']['last_login_at'] is not None"
ok 'התחלה אחרי סיום נדחית' "$(call "$A" '{"action":"user-update","id":5,"patch":{"valid_from":"2100-01-01"}}')" "d['success']==False"
ok 'קיצור התוקף מנתק סשן קיים' "$(call "$A" '{"action":"user-update","id":5,"patch":{"valid_until":"2020-02-01"}}')" "d['user']['in_period']==False"
ok '…והסשן שלו מת' "$(call "$TMP/old.jar" '{"action":"projects-list"}')" "d['success']==False"
ok 'מנהל לא נועל את עצמו' "$(call "$A" '{"action":"user-update","id":1,"patch":{"valid_until":"2020-01-01"}}')" "d['success']==False"
ok 'מנהל לא מוחק את עצמו' "$(call "$A" '{"action":"user-delete","id":1}')" "d['success']==False"
ok 'נגר לא מוחק' "$(call "$C" '{"action":"user-delete","id":5}')" "d['success']==False"
ok 'מחיקת משתמש' "$(call "$A" '{"action":"user-delete","id":5}')" "d['success']"
ok 'רשימת משתמשים אחרי המחיקה' "$(call "$A" '{"action":"users-list"}')" "len(d['users'])==4 and all('in_period' in u for u in d['users'])"
ok 'מצב המערכת' "$(call "$A" '{"action":"admin-stats"}')" "d['stats']['users']['admin']>=1 and 'db_bytes' in d['stats'] and 'php' in d['stats']"
ok 'נגר לא רואה מצב מערכת' "$(call "$C" '{"action":"admin-stats"}')" "d['success']==False"

echo "סוגי מוצרים"
ok 'מנהל יוצר סוג' "$(call "$A" '{"action":"type-save","template_key":"bookcase","name":"ספריית סלון","defaults":{"width":1800,"columns":4}}')" "d['type']['id']==1 and d['type']['defaults']['width']==1800"
ok 'סוג לא פעיל' "$(call "$A" '{"action":"type-save","template_key":"bookcase","name":"ישן","active":false}')" "d['type']['active']==False"
ok 'נגר רואה רק פעילים' "$(call "$C" '{"action":"types-list"}')" "len(d['types'])==1"
ok 'מנהל רואה הכול' "$(call "$A" '{"action":"types-list"}')" "len(d['types'])==2"
ok 'נגר לא יוצר סוג' "$(call "$C" '{"action":"type-save","template_key":"bookcase","name":"x"}')" "d['success']==False"

echo "פרויקטים"
P=$(call "$C" '{"action":"project-save","name":"ספרייה למשפחת כהן","client":"כהן","template_key":"bookcase","product_type_id":1,"values":{"width":1800,"columns":4}}')
ok 'נגר שומר פרויקט' "$P" "d['project']['id']==1 and d['project']['values']['width']==1800 and d['project']['client_id']==1"
ok 'עדכון' "$(call "$C" '{"action":"project-save","id":1,"name":"ספרייה לכהן","values":{"width":1900}}')" "d['project']['name']=='ספרייה לכהן' and d['project']['values']['width']==1900"
ok 'נגר אחר לא רואה' "$(call "$C2" '{"action":"project-get","id":1}')" "d['success']==False"
ok 'נגר אחר לא מעדכן' "$(call "$C2" '{"action":"project-save","id":1,"name":"גנוב"}')" "d['success']==False"
ok 'מנהל רואה' "$(call "$A" '{"action":"project-get","id":1}')" "d['project']['owner_name']=='דן'"
ok 'צופה לא יוצר' "$(call "$V" '{"action":"project-save","name":"x"}')" "d['success']==False"
ok 'שכפול' "$(call "$C" '{"action":"project-duplicate","id":1}')" "d['project']['id']==2 and 'עותק' in d['project']['name']"
ok 'רשימה של נגר' "$(call "$C" '{"action":"projects-list"}')" "len(d['projects'])==2"
ok 'רשימה של מנהל' "$(call "$A" '{"action":"projects-list"}')" "len(d['projects'])==2 and d['projects'][0]['owner_name']=='דן'"
S=$(call "$C" '{"action":"project-share","id":1,"on":true}')
ok 'שיתוף יוצר אסימון' "$S" "len(d['project']['share_token'])==32"
TOKEN=$(printf '%s' "$S" | python3 -c "import sys,json; print(json.load(sys.stdin)['project']['share_token'])")
ok 'צפיית לקוח בלי כניסה' "$(get "$X" "action=view&t=$TOKEN")" "d['project']['values']['width']==1900 and 'materials' in d"
ok 'אסימון שגוי' "$(get "$X" "action=view&t=00000000000000000000000000000000")" "d['success']==False"
ok 'ביטול שיתוף' "$(call "$C" '{"action":"project-share","id":1,"on":false}')" "d['project']['shared']==False"
ok 'אחרי הביטול הקישור מת' "$(get "$X" "action=view&t=$TOKEN")" "d['success']==False"
ok 'מחיקה' "$(call "$C" '{"action":"project-delete","id":2}')" "d['success']"
ok 'נשאר אחד' "$(call "$C" '{"action":"projects-list"}')" "len(d['projects'])==1"

echo "לקוחות"
ok 'שם חופשי בפרויקט יצר לקוח' "$(call "$C" '{"action":"clients-list"}')" "len(d['clients'])==1 and d['clients'][0]['name']=='כהן' and d['clients'][0]['projects']==1"
ok 'לקוח חדש עם פרטים' "$(call "$C" '{"action":"client-save","name":"לוי","phone":"050-1234567","email":"levi@x.il","address":"רחוב 1","notes":"מרפסת"}')" "d['client']['id']==2 and d['client']['phone']=='050-1234567'"
ok 'לקוח בלי שם נדחה' "$(call "$C" '{"action":"client-save","name":"  "}')" "d['success']==False"
ok 'צופה לא יוצר לקוח' "$(call "$V" '{"action":"client-save","name":"x"}')" "d['success']==False"
ok 'שיוך פרויקט ללקוח לפי id' "$(call "$C" '{"action":"project-save","id":1,"client_id":2}')" "d['project']['client_id']==2 and d['project']['client']=='לוי'"
ok 'שינוי שם לקוח מתעדכן בפרויקט' "$(call "$C" '{"action":"client-save","id":2,"name":"לוי-כץ"}')" "d['client']['name']=='לוי-כץ'"
ok '…והפרויקט מציג את השם החדש' "$(call "$C" '{"action":"project-get","id":1}')" "d['project']['client']=='לוי-כץ'"
ok 'רשימת פרויקטים לפי לקוח' "$(call "$C" '{"action":"projects-list","client_id":2}')" "len(d['projects'])==1"
ok 'ניתוק לקוח (null)' "$(call "$C" '{"action":"project-save","id":1,"client_id":null}')" "d['project']['client_id'] is None and d['project']['client']==''"
ok 'שיוך חזרה' "$(call "$C" '{"action":"project-save","id":1,"client_id":2}')" "d['project']['client_id']==2"

echo "הרכבות"
P2=$(call "$C" '{"action":"project-save","name":"ארונית","client_id":2,"template_key":"bookcase","values":{"width":900,"height":600}}')
ok 'פרויקט שני' "$P2" "d['project']['id']==3"
AS=$(call "$C" '{"action":"assembly-save","name":"מזווה וארונית","client_id":2,"items":[{"project_id":1,"pos":[0,0,0],"rot":0},{"project_id":3,"pos":[0,2000,0],"rot":90,"visible":true}],"joined":false}')
ok 'יצירת הרכבה עם הפרויקטים' "$AS" "d['assembly']['id']==1 and len(d['assembly']['items'])==2 and d['assembly']['items'][1]['rot']==90 and len(d['assembly']['projects'])==2 and d['assembly']['client_name']=='לוי-כץ'"
ok 'סיבוב חופשי נשמר (37.5°), ומחוץ לטווח מנורמל' "$(call "$C" '{"action":"assembly-save","id":1,"name":"מזווה וארונית","client_id":2,"items":[{"project_id":1,"rot":37.5},{"project_id":1,"rot":-90},{"project_id":1,"rot":"x"}],"joined":true}')" "[i['rot'] for i in d['assembly']['items']]==[37.5,270,0] and d['assembly']['joined']==True"
ok 'חזרה לפריט אחד' "$(call "$C" '{"action":"assembly-save","id":1,"name":"מזווה וארונית","client_id":2,"items":[{"project_id":1,"rot":0}],"joined":true}')" "len(d['assembly']['projects'])==1"
ok 'רשימת הרכבות של הלקוח' "$(call "$C" '{"action":"assemblies-list","client_id":2}')" "len(d['assemblies'])==1 and d['assemblies'][0]['joined']==True"
ok 'נגר אחר לא רואה' "$(call "$C2" '{"action":"assembly-get","id":1}')" "d['success']==False"
ok 'מנהל רואה' "$(call "$A" '{"action":"assembly-get","id":1}')" "d['assembly']['owner_name']=='דן'"
ok 'מונה ההרכבות בלקוח' "$(call "$C" '{"action":"clients-list"}')" "[c for c in d['clients'] if c['id']==2][0]['assemblies']==1"
AST=$(call "$C" '{"action":"assembly-share","id":1,"on":true}')
ok 'שיתוף הרכבה' "$AST" "len(d['assembly']['share_token'])==32"
ATOKEN=$(printf '%s' "$AST" | python3 -c "import sys,json; print(json.load(sys.stdin)['assembly']['share_token'])")
ok 'צפיית לקוח בהרכבה' "$(get "$X" "action=view-assembly&t=$ATOKEN")" "d['assembly']['name']=='מזווה וארונית' and len(d['assembly']['projects'])==1 and 'share_token' not in d['assembly'] and 'materials' in d"
ok 'ביטול שיתוף הרכבה' "$(call "$C" '{"action":"assembly-share","id":1,"on":false}')" "d['assembly']['shared']==False"
ok 'מחיקת הרכבה' "$(call "$C" '{"action":"assembly-delete","id":1}')" "d['success']"
ok 'מחיקת לקוח — הפרויקט נשאר בלי שיוך' "$(call "$C" '{"action":"client-delete","id":2}')" "d['success']"
ok '…הפרויקט קיים' "$(call "$C" '{"action":"project-get","id":3}')" "d['project']['client_id'] is None"
ok 'מחיקת הפרויקט השני' "$(call "$C" '{"action":"project-delete","id":3}')" "d['success']"

echo "ספריית חומרים"
ok 'ספרייה ריקה בהתחלה' "$(call "$C" '{"action":"materials-get"}')" "d['diff']==[] and d['images']=={}"
ok 'נגר לא שומר ספרייה' "$(call "$C" '{"action":"materials-save","diff":[]}')" "d['success']==False"
ok 'מנהל שומר diff' "$(call "$A" '{"action":"materials-save","diff":[{"id":"board:melamine-oak-18","price":150},{"id":"board:custom-x","kind":"board","name":"מיוחד","t":25,"active":true}]}')" "len(d['diff'])==2"
# תמונה: PNG 1×1 אמיתי
PNG='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
ok 'תמונה לחומר' "$(call "$A" "{\"action\":\"material-image\",\"id\":\"board:custom-x\",\"image\":\"$PNG\",\"imageMm\":450}")" "d['image']['imageMm']==450 and d['image']['url'].endswith('.jpg')"
ok 'שינוי קנה מידה בלבד' "$(call "$A" '{"action":"material-image","id":"board:custom-x","image":"","imageMm":800}')" "d['image']['imageMm']==800"
ok 'קנה מידה בלי תמונה נדחה' "$(call "$A" '{"action":"material-image","id":"board:melamine-oak-18","image":"","imageMm":800}')" "d['success']==False"
ok 'תמונה מזויפת נדחית' "$(call "$A" '{"action":"material-image","id":"board:custom-x","image":"data:image/png;base64,aGVsbG8="}')" "d['success']==False"
ok 'הקובץ נוצר' "$(ls "$TMP/media" | grep -c jpg)" "d==1"
ok 'ה-diff כולל את התמונה' "$(call "$C" '{"action":"materials-get"}')" "d['images']['board:custom-x']['imageMm']==800 and len(d['diff'])==2"
ok 'diff חדש בלי החומר — התמונה נשארת' "$(call "$A" '{"action":"materials-save","diff":[{"id":"board:melamine-oak-18","price":160}]}')" "len(d['diff'])==1 and 'board:custom-x' in d['images']"
ok 'מחיקת תמונה' "$(call "$A" '{"action":"material-image-delete","id":"board:custom-x"}')" "d['success']"
ok 'ואז השורה נעלמת' "$(call "$C" '{"action":"materials-get"}')" "d['images']=={} and len(d['diff'])==1"

echo "אביזרים"
ok 'נגר שומר אביזרים (hw: בלבד)' "$(call "$C" '{"action":"accessories-save","diff":[{"id":"hw:handle-bar-128","price":30},{"id":"hw:my-bar","kind":"hardware","name":"ידית שלי","price":40,"priceUnit":"unit","active":true,"accessory":{"type":"bar","params":{"length":224},"finish":"brass"}},{"id":"board:hack","kind":"board","name":"x"}]}')" "len([m for m in d['diff'] if m['id'].startswith('hw:')])==2 and not any(m['id']=='board:hack' for m in d['diff']) and [m for m in d['diff'] if m['id']=='hw:my-bar'][0]['accessory']['params']['length']==224"
ok 'שורות הלוחות נשארו' "$(call "$C" '{"action":"materials-get"}')" "any(m['id']=='board:melamine-oak-18' for m in d['diff'])"
ok 'אביזר שהוסר מהרשימה נמחק' "$(call "$C" '{"action":"accessories-save","diff":[{"id":"hw:my-bar","kind":"hardware","name":"ידית שלי","price":40,"priceUnit":"unit","active":true,"accessory":{"type":"bar","params":{"length":224},"finish":"brass"}}]}')" "len([m for m in d['diff'] if m['id'].startswith('hw:')])==1"
ok 'צופה לא שומר אביזרים' "$(call "$V" '{"action":"accessories-save","diff":[]}')" "d['success']==False"
ok 'נגר שומר דוגמת CNC (cnc: בלבד), האביזרים לא נגועים' "$(call "$C" '{"action":"cnc-save","diff":[{"id":"cnc:milled-fine","price":120},{"id":"cnc:my-flutes","kind":"cnc","name":"שלי","price":70,"priceUnit":"m2","active":true,"mill":{"kind":"flutes","groove":8,"rib":12,"depth":5,"margin":0}},{"id":"hw:hack","kind":"hardware","name":"x"}]}')" "len([m for m in d['diff'] if m['id'].startswith('cnc:')])==2 and any(m['id']=='hw:my-bar' for m in d['diff']) and not any(m['id']=='hw:hack' for m in d['diff']) and [m for m in d['diff'] if m['id']=='cnc:my-flutes'][0]['mill']['rib']==12"
ok 'דוגמת CNC שהוסרה מהרשימה נמחקת' "$(call "$C" '{"action":"cnc-save","diff":[{"id":"cnc:milled-fine","price":120}]}')" "len([m for m in d['diff'] if m['id'].startswith('cnc:')])==1"
ok 'צופה לא שומר CNC' "$(call "$V" '{"action":"cnc-save","diff":[]}')" "d['success']==False"
echo "מעבדת המוצרים (התאמות לתבניות)"
ok 'בהתחלה אין התאמות' "$(call "$C" '{"action":"rules-get"}')" "d['rules']=={}"
ok 'נגר לא שומר התאמות' "$(call "$C" '{"action":"rules-save","rules":{"bookcase":{"laborHours":9}}}')" "d['success']==False"
R=$(call "$A" '{"action":"rules-save","rules":{"bookcase":{"laborHours":9,"params":{"width":{"default":1500,"label":"רוחב כולל"}}},"_limits":{"doorWidth":550},"Bad-Key":{"x":1},"table":[]}}')
ok 'מנהל שומר; מפתח לא חוקי וערך ריק נבלעים' "$R" "d['rules']['bookcase']['params']['width']['default']==1500 and d['rules']['_limits']['doorWidth']==550 and 'Bad-Key' not in d['rules'] and 'table' not in d['rules']"
ok 'נגר רואה את ההתאמות' "$(call "$C" '{"action":"rules-get"}')" "d['rules']['bookcase']['laborHours']==9"
ok 'שמירה מחליפה הכול (מה שלא נשלח — נמחק)' "$(call "$A" '{"action":"rules-save","rules":{"_limits":{"doorWidth":580}}}')" "'bookcase' not in d['rules'] and d['rules']['_limits']['doorWidth']==580"
ok 'ניקוי' "$(call "$C" '{"action":"accessories-save","diff":[]}')" "len([m for m in d['diff'] if m['id'].startswith('hw:')])==0"

echo "מתכונים (מעבדת המוצרים, שלב 2)"
ok 'בהתחלה אין מתכונים' "$(call "$C" '{"action":"recipes-get"}')" "d['recipes']==[]"
ok 'נגר לא שומר מתכון' "$(call "$C" '{"action":"recipe-save","recipe":{"key":"r-abc1","name":"x"}}')" "d['success']==False"
ok 'מזהה לא חוקי נדחה' "$(call "$A" '{"action":"recipe-save","recipe":{"key":"bookcase","name":"x"}}')" "d['success']==False"
ok 'בלי שם נדחה' "$(call "$A" '{"action":"recipe-save","recipe":{"key":"r-abc1","name":"  "}}')" "d['success']==False"
ok 'רכיבים חייבים להיות רשימה' "$(call "$A" '{"action":"recipe-save","recipe":{"key":"r-abc1","name":"x","components":"oops"}}')" "d['success']==False"
R=$(call "$A" '{"action":"recipe-save","recipe":{"key":"r-abc1","name":"ארון בדיקה","params":[{"key":"W","type":"mm","default":800}],"components":[{"id":"top","w":"W","h":"18","d":"400"}]}}')
ok 'מנהל שומר' "$R" "d['recipe']['key']=='r-abc1' and d['recipe']['active']==True and len(d['recipes'])==1 and d['recipes'][0]['components'][0]['w']=='W'"
ok 'עדכון באותו מזהה + השבתה' "$(call "$A" '{"action":"recipe-save","recipe":{"key":"r-abc1","name":"ארון בדיקה 2","active":false,"components":[]}}')" "len(d['recipes'])==1 and d['recipes'][0]['name']=='ארון בדיקה 2' and d['recipes'][0]['active']==False"
ok 'נגר רואה את המתכונים (גם מושבתים — לפרויקטים קיימים)' "$(call "$C" '{"action":"recipes-get"}')" "len(d['recipes'])==1"
ok 'נגר לא מוחק' "$(call "$C" '{"action":"recipe-delete","key":"r-abc1"}')" "d['success']==False"
ok 'מנהל מוחק' "$(call "$A" '{"action":"recipe-delete","key":"r-abc1"}')" "d['recipes']==[]"

echo "תעריפים"
ok 'ברירת מחדל ריקה' "$(call "$C" '{"action":"rates-get"}')" "d['rates']['laborHour'] is None"
ok 'שמירה' "$(call "$C" '{"action":"rates-save","rates":{"laborHour":180,"markup":0.25,"materials":{"board:melamine-oak-18":135}}}')" "d['rates']['laborHour']==180 and d['rates']['materials']['board:melamine-oak-18']==135"
ok 'של נגר אחר ריקים' "$(call "$C2" '{"action":"rates-get"}')" "d['rates']['markup'] is None"
ok 'צופה בלי תעריפים' "$(call "$V" '{"action":"rates-get"}')" "d['success']==False"

echo "חסימה ויציאה"
ok 'מנהל חוסם נגר' "$(call "$A" '{"action":"user-update","id":2,"patch":{"blocked":true}}')" "d['user']['blocked']==True"
ok 'הסשן הקיים של הנגר נותק' "$(call "$C" '{"action":"projects-list"}')" "d['success']==False"
ok 'יציאה' "$(call "$A" '{"action":"logout"}')" "d['success']"
ok 'אחרי יציאה' "$(get "$A" action=me)" "d['user'] is None"

if [ "$FAIL" -ne 0 ]; then echo; echo "יש כשלים. יומן השרת:"; tail -20 "$TMP/server.log"; exit 1; fi
echo; echo "הכול עבר ✓"
