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

echo "סוגי מוצרים"
ok 'מנהל יוצר סוג' "$(call "$A" '{"action":"type-save","template_key":"bookcase","name":"ספריית סלון","defaults":{"width":1800,"columns":4}}')" "d['type']['id']==1 and d['type']['defaults']['width']==1800"
ok 'סוג לא פעיל' "$(call "$A" '{"action":"type-save","template_key":"bookcase","name":"ישן","active":false}')" "d['type']['active']==False"
ok 'נגר רואה רק פעילים' "$(call "$C" '{"action":"types-list"}')" "len(d['types'])==1"
ok 'מנהל רואה הכול' "$(call "$A" '{"action":"types-list"}')" "len(d['types'])==2"
ok 'נגר לא יוצר סוג' "$(call "$C" '{"action":"type-save","template_key":"bookcase","name":"x"}')" "d['success']==False"

echo "פרויקטים"
P=$(call "$C" '{"action":"project-save","name":"ספרייה למשפחת כהן","client":"כהן","template_key":"bookcase","product_type_id":1,"values":{"width":1800,"columns":4}}')
ok 'נגר שומר פרויקט' "$P" "d['project']['id']==1 and d['project']['values']['width']==1800"
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
