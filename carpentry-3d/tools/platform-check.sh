#!/usr/bin/env bash
# בדיקת הכניסה דרך הפלטפורמה, על שרת PHP אמיתי במסד זמני.
# הרצה: bash carpentry-3d/tools/platform-check.sh
#
# השער של הפלטפורמה כותב את הזהות ל-$_SERVER['PLATFORM_USER']. כאן קובץ ה-prepend של הבדיקה מדמה
# אותו מכותרת X-Test-Platform-User — רק בבדיקה; בשרת אין קוד כזה.

set -uo pipefail
cd "$(dirname "$0")/../.."

PORT=${PORT:-8794}
TMP=$(mktemp -d)
FAIL=0

cat > "$TMP/boot.php" <<PHPBOOT
<?php
define('DB_FILE', '$TMP/t.sqlite');
define('MEDIA_DIR', '$TMP/media');
if (!empty(\$_SERVER['HTTP_X_TEST_PLATFORM_USER'])) \$_SERVER['PLATFORM_USER'] = \$_SERVER['HTTP_X_TEST_PLATFORM_USER'];
PHPBOOT

php -S "127.0.0.1:$PORT" -t . -d auto_prepend_file="$TMP/boot.php" >"$TMP/server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null; rm -rf "$TMP"' EXIT
for i in $(seq 1 30); do curl -s "http://127.0.0.1:$PORT/carpentry-3d/api.php?action=me" >/dev/null && break; sleep 0.2; done

API="http://127.0.0.1:$PORT/carpentry-3d/api.php"
# call <jar> <identity|-> <json> — identity הוא ה-JSON של PLATFORM_USER, או - בלי זהות (מנותקת)
call() {
  if [ "$2" = - ]; then curl -s -b "$1" -c "$1" -H 'Content-Type: application/json' -d "$3" "$API"
  else curl -s -b "$1" -c "$1" -H 'Content-Type: application/json' -H "X-Test-Platform-User: $2" -d "$3" "$API"; fi
}
ok() {
  local name=$1 json=$2 expr=$3
  if printf '%s' "$json" | python3 -c "import sys,json; d=json.load(sys.stdin); sys.exit(0 if ($expr) else 1)" 2>/dev/null; then
    echo "  ✓ $name"
  else
    echo "  ✗ $name"; echo "      $json" | head -c 400; echo; FAIL=1
  fi
}

BOSS='{"sub":"p-boss","name":"הבוס","email":"boss@x.il","email_verified":true}'
OLD='{"sub":"p-old","name":"ותיק","email":"Old@X.il","email_verified":true}'
NEWB='{"sub":"p-new","name":"חדש","email":"new@platform.il","email_verified":true}'
NOMAIL='{"sub":"p-nomail","name":"","email":null,"email_verified":false}'
A="$TMP/a"; O="$TMP/o"; N="$TMP/n"; N2="$TMP/n2"; M="$TMP/m"; P="$TMP/p"

echo "מסד ריק"
ok 'הראשון דרך הפלטפורמה — מנהל' "$(call "$A" "$BOSS" '{"action":"me"}')" "d['user']['role']=='admin' and d['platform'] and d['user']['linked'] and not d['offer_link']"
ok 'setup חסום כשמחוברת' "$(call "$A" "$BOSS" '{"action":"setup","email":"z@x.il","password":"secret123"}')" "d['success']==False"
ok 'login חסום כשמחוברת' "$(call "$A" "$BOSS" '{"action":"login","email":"boss@x.il","password":"x"}')" "d['success']==False"
ok 'logout חסום כשמחוברת' "$(call "$A" "$BOSS" '{"action":"logout"}')" "d['success']==False"
ok 'המנהל עובד' "$(call "$A" "$BOSS" '{"action":"users-list"}')" "len(d['users'])==1"

echo "חשבונות ישנים"
call "$A" "$BOSS" '{"action":"user-create","email":"old@x.il","password":"oldpass123","name":"ותיק","role":"viewer","valid_until":"2099-01-01"}' >/dev/null
call "$A" "$BOSS" '{"action":"user-create","email":"legacy@x.il","password":"legacy123","name":"מורשת"}' >/dev/null
ok 'קישור לפי מייל (אותיות גדולות)' "$(call "$O" "$OLD" '{"action":"me"}')" "d['user']['email']=='old@x.il' and d['user']['role']=='viewer' and d['user']['linked'] and not d['can_link']"
ok 'שוב — לפי sub' "$(call "$O" "$OLD" '{"action":"me"}')" "d['user']['email']=='old@x.il'"
ok 'הסיסמה הישנה לא נמחקה — עובדת כשמנותקת' "$(call "$P" - '{"action":"login","email":"old@x.il","password":"oldpass123"}')" "d['user']['email']=='old@x.il'"

echo "חשבון חדש ומסך החיבור"
ok 'חדש — נגר, פעיל, מוצע מסך החיבור' "$(call "$N" "$NEWB" '{"action":"me"}')" "d['user']['role']=='carpenter' and d['user']['valid_until'] is None and d['offer_link'] and d['can_link']"
call "$N" "$NEWB" '{"action":"project-save","name":"מהחשבון החדש","template_key":"bookcase","values":{}}' >/dev/null
for i in 1 2 3 4; do call "$N" "$NEWB" '{"action":"link-account","email":"legacy@x.il","password":"wrong"}' >/dev/null; done
ok 'ניסיון חמישי שגוי' "$(call "$N" "$NEWB" '{"action":"link-account","email":"legacy@x.il","password":"wrong"}')" "d['success']==False"
ok 'נעול — גם עם הסיסמה הנכונה' "$(call "$N" "$NEWB" '{"action":"link-account","email":"legacy@x.il","password":"legacy123"}')" "d['success']==False and 'דקות' in d['error']"
python3 -c "import sqlite3; c=sqlite3.connect('$TMP/t.sqlite'); c.execute(\"UPDATE users SET link_locked_until=0 WHERE platform_sub='p-new'\"); c.commit()"
ok 'לא ניתן לחבר חשבון שכבר מקושר' "$(call "$N" "$NEWB" '{"action":"link-account","email":"old@x.il","password":"oldpass123"}')" "d['success']==False"
ok 'חיבור חשבון ישן' "$(call "$N" "$NEWB" '{"action":"link-account","email":"legacy@x.il","password":"legacy123"}')" "d['user']['email']=='legacy@x.il'"
ok 'מעכשיו — החשבון הישן, בלי הצעה' "$(call "$N2" "$NEWB" '{"action":"me"}')" "d['user']['email']=='legacy@x.il' and not d['can_link']"
ok 'הפרויקט מהחשבון החדש עבר' "$(call "$N2" "$NEWB" '{"action":"projects-list"}')" "[p['name'] for p in d['projects']]==['מהחשבון החדש']"
ok 'החשבון שנפתח לבד נמחק' "$(call "$A" "$BOSS" '{"action":"users-list"}')" "sorted(u['email'] for u in d['users'])==['boss@x.il','legacy@x.il','old@x.il']"

echo "בלי מייל, ויתור על ההצעה"
ok 'אין חשבון ישן פנוי — אין הצעה' "$(call "$TMP/q" '{"sub":"p-q","name":"ק","email":"q@x.il"}' '{"action":"me"}')" "not d['can_link']"
call "$A" "$BOSS" '{"action":"user-create","email":"spare@x.il","password":"spare1234","name":"פנוי"}' >/dev/null
ok 'בלי מייל — חשבון עם כתובת פנימית' "$(call "$M" "$NOMAIL" '{"action":"me"}')" "d['user']['email'].endswith('@platform.invalid') and d['offer_link']"
call "$M" "$NOMAIL" '{"action":"link-dismiss"}' >/dev/null
ok 'אין לי חשבון — ההצעה לא חוזרת, נשארת בתפריט' "$(call "$M" "$NOMAIL" '{"action":"me"}')" "not d['offer_link'] and d['can_link']"

echo "חסימות ותוקף של האפליקציה"
LID=$(call "$A" "$BOSS" '{"action":"users-list"}' | python3 -c "import sys,json; print([u['id'] for u in json.load(sys.stdin)['users'] if u['email']=='legacy@x.il'][0])")
call "$A" "$BOSS" "{\"action\":\"user-update\",\"id\":$LID,\"patch\":{\"blocked\":true}}" >/dev/null
ok 'חסום — me מחזיר את הסיבה' "$(call "$N2" "$NEWB" '{"action":"me"}')" "d['user'] is None and 'חסום' in d['denied']"
ok 'חסום — API נדחה' "$(call "$N2" "$NEWB" '{"action":"projects-list"}')" "d['success']==False"
call "$A" "$BOSS" "{\"action\":\"user-update\",\"id\":$LID,\"patch\":{\"blocked\":false,\"valid_until\":\"2020-01-01\"}}" >/dev/null
ok 'פג תוקף — me מחזיר את הסיבה' "$(call "$N2" "$NEWB" '{"action":"me"}')" "d['user'] is None and 'תוקף' in d['denied']"

echo "מנותקת — רק בסיס"
ok 'בלי זהות — me רגיל' "$(call "$TMP/x" - '{"action":"me"}')" "d['user'] is None and d['platform']==False and d['setup']==False"
ok 'צפיית לקוח בלי כניסה — כמו שהייתה' "$(curl -s "$API?action=view&t=nope")" "d['success']==False"

[ $FAIL -eq 0 ] && echo "הכול עבר" || { echo "יש כשלים"; tail -5 "$TMP/server.log"; }
exit $FAIL
