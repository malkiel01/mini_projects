#!/usr/bin/env bash
# פרוסה 12b — בדיקת הממשק בדפדפן אמיתי (Playwright), שרת ומסד משלה, ממסד v0 עם 22 תיקים.
# U1 צופה · U2 מונים · U5 פס לפי בדיקות חלות · U6 צילום בלי תיק · U7 המיקום שלי · U9 שפה · 320px בלי גלילה אופקית
# · ביטול קישור אוטומטי · תבנית תיקייה. הצילומים — dev/screens/12b/. רץ מתוך tests/run.sh, או לבד.
# בלי Playwright במכונה — מדלג (ומודיע), כדי ש-run.sh ירוץ גם בלעדיו.
set -euo pipefail
cd "$(dirname "$0")/../.."
APP=funerals-dashboard
D="$APP/data"
if ! node -e "require('playwright')" >/dev/null 2>&1; then echo "  - 12b ממשק — מדלג: אין Playwright"; exit 0; fi
tmp="$(mktemp -d)"
pid=0
wipe(){ find "$D" -mindepth 1 ! -name .htaccess ! -name .gitignore -exec rm -rf {} + 2>/dev/null || true; }
cleanup(){ if [ "$pid" != 0 ]; then kill "$pid" >/dev/null 2>&1 || true; fi; wipe; rm -rf "$tmp"; }
trap cleanup EXIT
fail(){ echo "FAIL (12b ממשק): $*"; tail -5 "$tmp/server.log" 2>/dev/null; exit 1; }

wipe
php "$APP/tests/fixture-v0.php" "$D" "fd_$(printf 'y%.0s' {1..64})" >/dev/null
DB="$D/funerals.sqlite"
sql(){ php -r '$p=new PDO("sqlite:".$argv[1]); $r=$p->query($argv[2])->fetchAll(PDO::FETCH_NUM); foreach($r as $row) echo implode("|",$row),"\n";' "$DB" "$1"; }
idf="$tmp/identity.json"; : > "$idf"
PORT=18977
FD_TEST_IDENTITY_FILE="$idf" php -d opcache.jit=disable -S 127.0.0.1:$PORT -t "$APP" -d auto_prepend_file="$PWD/$APP/tests/gate-prepend.php" > "$tmp/server.log" 2>&1 &
pid=$!
for i in $(seq 1 30); do curl -sf http://127.0.0.1:$PORT/index.html >/dev/null && break || sleep .2; done
base="http://127.0.0.1:$PORT/api.php"
ident(){ printf '{"sub":"%s","name":"%s","email":"%s","email_verified":true,"platform_admin":%s,"granted":true}' "$1" "$2" "$3" "$4"; }
DEV="$(ident 1 'המפתח' 'dev@example.com' true)"
BOSS="$(ident 2 'מנהל א' 'boss@example.com' false)"
printf '%s' "$DEV" > "$idf"
csrf="$(curl -sS -b "$tmp/c" -c "$tmp/c" "$base?action=me" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["csrf"] ?? "";')"
test -n "$csrf" || fail "me של המפתח"
post(){ curl -sS -o "$tmp/out" -w '%{http_code}' -b "$tmp/c" -c "$tmp/c" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' --data-binary "$2" "$base?action=$1"; }
test "$(post invite '{"email":"boss@example.com","role":"admin"}')" = 200 || fail "הזמנת מנהל"
# U5: בדיקה מותנית — "זיהוי בן/בת זוג" חלה רק כשיש "רכישת קבר נוסף"
test "$(post check_save '{"scope":"global","key":"partner_identity","cond":{"check":"grave_purchase"}}')" = 200 || fail "בדיקה מותנית"
MONTH="$(sql "SELECT month FROM funerals GROUP BY month ORDER BY COUNT(*) DESC LIMIT 1")"
# U5: לתיק הראשון — בדיקה שסומנה מקובץ בתיקייה (כמו שהזיהוי של 10 כותב), כדי שלחיצה על המקור תפתח אותו
FIRST="$(php -r '$p=new PDO("sqlite:".$argv[1]); $r=$p->query("SELECT id, body FROM funerals WHERE month=".$p->quote($argv[2])." ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
  $b=json_decode($r["body"],true); $b["checks"]["license_document"]=["status"=>"document_present","value"=>"","note"=>"","source"=>"Dropbox: רישיון קבורה.pdf","via"=>"ai","by"=>"הבינה","at"=>gmdate("c")];
  $b["checks"]["grave_purchase"]=["status"=>"unknown","value"=>"","note"=>"","source"=>""];
  $p->prepare("UPDATE funerals SET body=? WHERE id=?")->execute([json_encode($b,JSON_UNESCAPED_UNICODE),$r["id"]]); echo $b["name"];' "$DB" "$MONTH")"
test -n "$FIRST" || fail "תיק ראשון"

BASE="http://127.0.0.1:$PORT" IDF="$idf" BOSS="$BOSS" MONTH="$MONTH" FIRST="$FIRST" SHOTS="$APP/dev/screens/12b" \
  node "$APP/tests/ui.test.js" || fail "ui.test.js"
