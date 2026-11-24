#!/usr/bin/env bash
# בדיקות פרוסה 05 (API חיצוני): E1–E10 מ-docs/spec/05-external-api.md. רץ מתוך tests/run.sh, או לבד.
set -euo pipefail
cd "$(dirname "$0")/../.."
D=funerals-dashboard
tmp="$(mktemp -d)"
pid=0
wipe(){ find "$D/data" -mindepth 1 ! -name .htaccess -exec rm -rf {} + 2>/dev/null || true; }
cleanup(){ if [ "$pid" != 0 ]; then kill "$pid" >/dev/null 2>&1 || true; fi; wipe; rm -rf "$tmp"; }
trap cleanup EXIT
fail(){ echo "FAIL (05): $*"; [ -f "$tmp/out" ] && head -c 600 "$tmp/out" && echo; exit 1; }
for f in ext.php ext-lib.php tokens.php tools/build-ext.php tests/ext-fixture.php tests/openapi-check.php; do php -l "$D/$f" >/dev/null || fail "php -l $f"; done
php -r '$h=file_get_contents($argv[1]); if (!preg_match("~<script src=\"assets/js/connect.js\"~", $h)) exit(1);' $D/connect.html || fail "connect.html בלי connect.js"
node --check $D/assets/js/connect.js
php $D/tools/build-ext.php --check || fail "openapi.json / gpt-prompt.md לא נוצרו מהקוד"   # E8 (מקור אחד)
php $D/tests/openapi-check.php || fail "E8 openapi.json"
php -r '$a=json_decode(file_get_contents($argv[1]),true); foreach(["ingest.php","ext.php","openapi.json"] as $p) if(!in_array($p,$a["public"]??[],true)) exit(1); if (in_array("tokens.php",$a["public"],true)||in_array("connect.html",$a["public"],true)) exit(1);' $D/app.json || fail "E10 public"
php platform/check-apps.php >/dev/null || fail "E10 check-apps"
wipe
db=$D/data/funerals.sqlite
fx(){ php $D/tests/ext-fixture.php "$db" "$@"; }

id="$tmp/identity.json"
as(){ printf '%s' "$1" > "$id"; }
as ''
FD_TEST_IDENTITY_FILE="$id" php -S 127.0.0.1:18965 -t $D -d auto_prepend_file="$PWD/$D/tests/gate-prepend.php" > "$tmp/server.log" 2>&1 &
pid=$!
for i in $(seq 1 30); do curl -sf http://127.0.0.1:18965/index.html >/dev/null && break || sleep .2; done
H=http://127.0.0.1:18965
st(){ curl -sS -o "$tmp/out" -w '%{http_code}' "$@"; }
j(){ php -r '$v=json_decode(file_get_contents($argv[1]),true); $p=explode(".",$argv[2]); foreach($p as $k){ if(!is_array($v)||!array_key_exists($k,$v)){echo "";exit;} $v=$v[$k];} echo is_scalar($v)?$v:json_encode($v,JSON_UNESCAPED_UNICODE);' "$tmp/out" "$1"; }
ADMIN='{"sub":"7","name":"בודק","email":"tester@example.com","platform_admin":false,"granted":true}'
FIELD='{"sub":"9","name":"עובד שטח","email":"field@example.com","platform_admin":false,"granted":true}'
ORG2='{"sub":"10","name":"משרד שני","email":"org2@example.com","platform_admin":false,"granted":true}'
NOBODY='{"sub":"11","name":"בלי ארגון","email":"nobody@example.com","platform_admin":false,"granted":true}'

# הבקשה הראשונה מריצה את המיגרציה של 01; בלי אסימון → 401
code="$(st "$H/ext.php/whoami")"; test "$code" = 401 || fail "בקשה ראשונה ($code)"
fx setup

# מסך החיבור: CSRF, יצירה, האסימון מוצג פעם אחת
mk(){ # $1 זהות, $2 קובץ עוגיות, $3 שם, $4 ימים → מדפיס אסימון
  as "$1"; st -c "$2" -b "$2" "$H/tokens.php?action=me" >/dev/null; local c; c="$(j csrf)"
  st -b "$2" -H "X-CSRF-Token: $c" -H 'Content-Type: application/json' -d "{\"name\":\"$3\",\"days\":${4:-90}}" "$H/tokens.php?action=create" >/dev/null; j token; }
as "$ADMIN"
code="$(st "$H/tokens.php?action=me")"; test "$code" = 200 || fail "tokens me ($code)"
test "$(j openapi_url)" = "https://mbe-plus.com/mini_projects/funerals-dashboard/openapi.json" || fail "openapi_url"
grep -q 'תמיד `find` לפני עדכון' "$tmp/out" || fail "הפרומפט חסר במסך"
code="$(st -c "$tmp/ca" -X POST -H 'Content-Type: application/json' -d '{"name":"x","days":90}' "$H/tokens.php?action=create")"; test "$code" = 403 || fail "create בלי CSRF ($code)"
A="$(mk "$ADMIN" "$tmp/ca" 'GPT של משה' 90)"
[[ "$A" =~ ^fdx_[a-f0-9]{48}$ ]] || fail "אסימון לא בפורמט ($A)"
as "$ADMIN"; st -b "$tmp/ca" "$H/tokens.php?action=me" >/dev/null
! grep -q "$A" "$tmp/out" || fail "האסימון מוצג שוב ברשימה"
test "$(j tokens.0.state)" = active || fail "רשימת אסימונים"
code="$(st -b "$tmp/ca" -H "X-CSRF-Token: $(j csrf)" -H 'Content-Type: application/json' -d '{"name":"x","days":7}' "$H/tokens.php?action=create")"; test "$code" = 400 || fail "תוקף לא חוקי ($code)"
F="$(mk "$FIELD" "$tmp/cf" 'שטח' 30)"
B="$(mk "$ORG2" "$tmp/cb" 'ארגון 2' 365)"
[ -n "$F" ] && [ -n "$B" ] || fail "יצירת אסימונים"
as "$NOBODY"; code="$(st "$H/tokens.php?action=me")"; test "$code" = 403 || fail "בלי ארגון במסך החיבור ($code)"

# E1: בלי / שגוי / פג / בוטל / עוגייה לבד → 401
code="$(st "$H/ext.php/whoami")"; test "$code" = 401 || fail "E1 בלי אסימון ($code)"
code="$(st -H 'Authorization: Bearer fdx_000000000000000000000000000000000000000000000000' "$H/ext.php/whoami")"; test "$code" = 401 || fail "E1 שגוי ($code)"
code="$(st -H "Authorization: Basic $A" "$H/ext.php/whoami")"; test "$code" = 401 || fail "E1 לא Bearer ($code)"
code="$(st -H "Authorization: Bearer $A" "$H/ext.php/whoami")"; test "$code" = 200 || fail "whoami ($code)"
test "$(j role)" = admin && test "$(j org.id)" = 1 && test "$(j can_delete)" = "" || fail "whoami תוכן"
code="$(st -H "Authorization: Bearer $A" "$H/ext.php?action=whoami")"; test "$code" = 200 || fail "?action= ($code)"
as "$ADMIN"; code="$(st -b "$tmp/ca" "$H/ext.php/whoami")"; test "$code" = 401 || fail "E1 עוגייה + זהות שער בלי אסימון ($code)"
C="$(mk "$ADMIN" "$tmp/ca" 'יפוג' 30)"
cid="$(fx sql "SELECT id FROM tokens WHERE hint='${C: -4}' ORDER BY id DESC LIMIT 1" | php -r 'echo json_decode(stream_get_contents(STDIN),true)[0]["id"];')"
fx expire "$cid"
code="$(st -H "Authorization: Bearer $C" "$H/ext.php/whoami")"; test "$code" = 401 || fail "E1 פג ($code)"
R="$(mk "$ADMIN" "$tmp/ca" 'יבוטל' 30)"
code="$(st -H "Authorization: Bearer $R" "$H/ext.php/whoami")"; test "$code" = 200 || fail "לפני ביטול ($code)"
rid="$(fx sql "SELECT id FROM tokens WHERE hint='${R: -4}' ORDER BY id DESC LIMIT 1" | php -r 'echo json_decode(stream_get_contents(STDIN),true)[0]["id"];')"
as "$FIELD"; st -b "$tmp/cf" "$H/tokens.php?action=me" >/dev/null
code="$(st -b "$tmp/cf" -H "X-CSRF-Token: $(j csrf)" -H 'Content-Type: application/json' -d "{\"id\":$rid}" "$H/tokens.php?action=revoke")"; test "$code" = 404 || fail "עובד שטח מבטל אסימון של מנהל ($code)"
as "$ADMIN"; st -b "$tmp/ca" "$H/tokens.php?action=me" >/dev/null
test "$(php -r '$v=json_decode(file_get_contents($argv[1]),true); echo count(array_filter($v["tokens"], fn($t)=>!$t["mine"]));' "$tmp/out")" -ge 1 || fail "מנהל לא רואה אסימוני הארגון"
code="$(st -b "$tmp/ca" -H "X-CSRF-Token: $(j csrf)" -H 'Content-Type: application/json' -d "{\"id\":$rid}" "$H/tokens.php?action=revoke")"; test "$code" = 200 || fail "ביטול ($code)"
code="$(st -H "Authorization: Bearer $R" "$H/ext.php/whoami")"; test "$code" = 401 || fail "E1 בוטל ($code)"
test "$(fx sql "SELECT COUNT(*) n FROM tokens WHERE id=$rid" | php -r 'echo json_decode(stream_get_contents(STDIN),true)[0]["n"];')" = 1 || fail "ביטול מחק את השורה"

# E2: ארגון א' מול ארגון ב'
code="$(st -H "Authorization: Bearer $B" "$H/ext.php/case?id=fd_case_org1_cohen")"; test "$code" = 404 || fail "E2 ($code)"
code="$(st -H "Authorization: Bearer $B" "$H/ext.php/find?q=%D7%9B%D7%94%D7%9F")"; test "$code" = 200 && test "$(j count)" = 0 || fail "E2 find דולף"
code="$(st -H "Authorization: Bearer $B" -H 'Content-Type: application/json' -d '{"id":"fd_case_org1_cohen","note":"x"}' "$H/ext.php/case_update")"; test "$code" = 404 || fail "E2 update ($code)"
code="$(st -H "Authorization: Bearer $A" "$H/ext.php/case?id=fd_case_org1_cohen")"; test "$code" = 200 || fail "case ($code)"
test "$(j summary.next.check)" = license_document && test "$(j files_note)" = "Dropbox עוד לא חובר" || fail "case תוכן"

# find, cases, schema, 405, 501, אין מחיקה
code="$(st -H "Authorization: Bearer $A" "$H/ext.php/find?q=%D7%9B%D7%94%D7%9F")"; test "$code" = 200 && test "$(j count)" = 2 && [ -n "$(j hint)" ] || fail "find שתי תוצאות + hint"
code="$(st -H "Authorization: Bearer $A" "$H/ext.php/find?q=x")"; test "$code" = 400 || fail "find קצר ($code)"
code="$(st -H "Authorization: Bearer $A" "$H/ext.php/cases?month=2026-10&status=missing")"; test "$code" = 200 && test "$(j count)" = 2 || fail "cases status"
code="$(st -H "Authorization: Bearer $A" "$H/ext.php/schema")"; test "$code" = 200 && test "$(j checks.0.key)" = license_document || fail "schema"
code="$(st -H "Authorization: Bearer $A" "$H/ext.php/case_update")"; test "$code" = 405 || fail "GET case_update ($code)"
code="$(st -H "Authorization: Bearer $A" "$H/ext.php/files?id=fd_case_org1_cohen")"; test "$code" = 501 && test "$(j error)" = "Dropbox עוד לא חובר" || fail "files בלי 03 ($code)"
code="$(st -H "Authorization: Bearer $A" "$H/ext.php/file_text?file=x")"; test "$code" = 501 || fail "file_text בלי 03 ($code)"
for a in delete case_delete remove; do code="$(st -X POST -H "Authorization: Bearer $A" "$H/ext.php/$a")"; test "$code" = 404 || fail "פעולת מחיקה $a ($code)"; done
code="$(st -X DELETE -H "Authorization: Bearer $A" "$H/ext.php/case?id=fd_case_org1_cohen")"; test "$code" = 405 || fail "DELETE ($code)"
code="$(st -H "Authorization: Bearer $A" "$H/ext.php/prompt")"; test "$code" = 200 && grep -q 'לאימות' "$tmp/out" || fail "prompt"

# E3: עובד שטח — תשלום 403 ושום דבר לא נשמר; בדיקה 200
before="$(fx body fd_case_org1_cohen)"
code="$(st -H "Authorization: Bearer $F" -H 'Content-Type: application/json' -d '{"id":"fd_case_org1_cohen","checks":{"hall_service":{"status":"missing"}},"fields":{"payments":[{"amount":5}]}}' "$H/ext.php/case_update")"; test "$code" = 403 || fail "E3 תשלום ($code)"
code="$(st -H "Authorization: Bearer $F" -H 'Content-Type: application/json' -d '{"id":"fd_case_org1_cohen","fields":{"name":"אחר"}}' "$H/ext.php/case_update")"; test "$code" = 403 || fail "E3 שם ($code)"
test "$(fx body fd_case_org1_cohen)" = "$before" || fail "E3 נשמר משהו אחרי 403"
code="$(st -H "Authorization: Bearer $F" -H 'Content-Type: application/json' -d '{"id":"fd_case_org1_cohen","checks":{"hall_service":{"status":"missing","note":"לא נמצא"}},"fields":{"grave":"גוש 5"}}' "$H/ext.php/case_update")"; test "$code" = 200 || fail "E3 בדיקה ($code)"
test "$(j saved.checks.hall_service.status)" = missing && test "$(j saved.fields.grave)" = "גוש 5" || fail "E3 מה נשמר"
code="$(st -H "Authorization: Bearer $F" -H 'Content-Type: application/json' -d '{"name":"חדש"}' "$H/ext.php/case_create")"; test "$code" = 403 || fail "case_create שטח ($code)"

# E4: verified → נשמר "לאימות", והתשובה אומרת
code="$(st -H "Authorization: Bearer $A" -H 'Content-Type: application/json' -d '{"id":"fd_case_org1_cohen","checks":{"license_number":{"status":"verified","value":"12345"}},"note":"הערה בדיקה"}' "$H/ext.php/case_update")"; test "$code" = 200 || fail "E4 ($code)"
test "$(j saved.checks.license_number.status)" = document_present && test "$(j converted.0.requested)" = verified || fail "E4 המרה"
j message | grep -q 'לאימות' || fail "E4 התשובה לא אומרת לאימות"
fx body fd_case_org1_cohen | php -r '$b=json_decode(stream_get_contents(STDIN),true); $c=$b["checks"]["license_number"]; if($c["status"]!=="document_present"||$c["value"]!=="12345"||$c["via"]!=="api"||$c["by"]!=="בודק (צ\x27אט)"||!str_contains($b["notes"],"הערה בדיקה")||!str_contains($b["notes"],"(צ\x27אט)")) exit(1); if(($b["checks"]["hall_service"]["via"]??"")!=="api") exit(1);' || fail "E4 במסד"
# 02: בדיקה שאדם כבר סגר — הצ'אט לא פותח אותה מחדש ולא מחליף ערך; ההצעה בהערה
fx close fd_case_org1_cohen2 license_number 111
code="$(st -H "Authorization: Bearer $A" -H 'Content-Type: application/json' -d '{"id":"fd_case_org1_cohen2","checks":{"license_number":{"status":"verified","value":"222"}}}' "$H/ext.php/case_update")"; test "$code" = 200 || fail "סגור בידי אדם ($code)"
test "$(j saved.checks.license_number.status)" = verified && test "$(j saved.checks.license_number.value)" = 111 && test "$(j saved.checks.license_number.by)" = "מזכירה" || fail "הצ'אט פתח מחדש בדיקה שאדם סגר"
j saved.checks.license_number.note | grep -q 222 && j converted.0.reason | grep -q 'אדם כבר סגר' || fail "ההצעה לא בהערה / התשובה לא אומרת"
code="$(st -H "Authorization: Bearer $A" -H 'Content-Type: application/json' -d '{"id":"fd_case_org1_cohen","fields":{"payments":[{"amount":250,"purpose":"קבר"}]}}' "$H/ext.php/case_update")"; test "$code" = 200 || fail "תשלום מנהל ($code)"
code="$(st -H "Authorization: Bearer $A" -H 'Content-Type: application/json' -d '{"id":"fd_case_org1_cohen","checks":{"nope":{"status":"missing"}}}' "$H/ext.php/case_update")"; test "$code" = 400 || fail "בדיקה לא מוכרת ($code)"
code="$(st -H "Authorization: Bearer $A" -H 'Content-Type: application/json' -d '{"name":"אהרון חדש","date":"2026-11-02","note":"נפתח מהצאט"}' "$H/ext.php/case_create")"; test "$code" = 200 || fail "case_create ($code)"
newid="$(j case.id)"
test "$(fx sql "SELECT org_id FROM funerals WHERE id='$newid'" | php -r 'echo json_decode(stream_get_contents(STDIN),true)[0]["org_id"];')" = 1 || fail "case_create בלי org_id"
code="$(st -H "Authorization: Bearer $B" "$H/ext.php/case?id=$newid")"; test "$code" = 404 || fail "תיק חדש דולף לארגון 2 ($code)"

# E9: כל כתיבה → events עם source api ושם המשתמש
fx sql "SELECT source, actor, action, case_id FROM events WHERE source='api'" | php -r '$r=json_decode(stream_get_contents(STDIN),true); if(count($r)!==5) {fwrite(STDERR,json_encode($r,JSON_UNESCAPED_UNICODE)); exit(1);} foreach($r as $e) if($e["source"]!=="api"||!str_contains($e["actor"],"(צ\x27אט)")) exit(1);' || fail "E9 events"
fx sql "SELECT actor FROM events WHERE source='api' AND actor LIKE 'עובד שטח%'" | grep -q 'עובד שטח' || fail "E9 שם עובד השטח"

# E5: משתמש שהושבת / הורד בתפקיד — מיד
fx role 7 field
code="$(st -H "Authorization: Bearer $A" -H 'Content-Type: application/json' -d '{"id":"fd_case_org1_cohen","fields":{"payments":[]}}' "$H/ext.php/case_update")"; test "$code" = 403 || fail "הורדה בתפקיד לא חלה מיד ($code)"
fx role 7 admin
fx deactivate 9
code="$(st -H "Authorization: Bearer $F" "$H/ext.php/whoami")"; test "$code" = 401 || fail "E5 מושבת ($code)"

# E7: 61 בקשות בדקה → האחרונה 429 (אם הדקה התחלפה באמצע — עוד ניסיון אחד)
G="$(mk "$ADMIN" "$tmp/ca" 'קצב' 30)"
for attempt in 1 2; do
  m0="$(date +%M)"; codes=""
  for i in $(seq 1 61); do codes="$codes $(curl -sS -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $G" "$H/ext.php/whoami")"; done
  [ "$(date +%M)" = "$m0" ] && break
  G="$(mk "$ADMIN" "$tmp/ca" "קצב $attempt" 30)"
done
last="${codes##* }"; first60="$(echo $codes | cut -d' ' -f1-60 | tr ' ' '\n' | sort -u)"
test "$first60" = 200 && test "$last" = 429 || fail "E7 ($first60 / $last)"

# E6: האסימון רק כ-hash; לא במסד, לא ב-req_log, לא בלוג השרת
for t in "$A" "$F" "$B" "$G"; do
  ! grep -aq "$t" $db || fail "E6 אסימון בגלוי במסד"
  ! grep -aq "$t" "$tmp/server.log" || fail "E6 אסימון בלוג השרת"
  h="$(printf '%s' "$t" | sha256sum | cut -d' ' -f1)"
  test "$(fx sql "SELECT COUNT(*) n FROM tokens WHERE hash='$h'" | php -r 'echo json_decode(stream_get_contents(STDIN),true)[0]["n"];')" = 1 || fail "E6 hash"
done
test "$(fx sql "SELECT COUNT(*) n FROM req_log WHERE action LIKE 'ext:%'" | php -r 'echo json_decode(stream_get_contents(STDIN),true)[0]["n"];')" -gt 50 || fail "req_log לא נכתב"
fx sql "SELECT * FROM req_log" | grep -qE 'fdx_[a-f0-9]{48}' && fail "E6 אסימון ב-req_log" || true

# עד 10 פעילים למשתמש
as "$ORG2"; for i in $(seq 2 10); do mk "$ORG2" "$tmp/cb" "n$i" 30 >/dev/null; done
st -b "$tmp/cb" "$H/tokens.php?action=me" >/dev/null
code="$(st -b "$tmp/cb" -H "X-CSRF-Token: $(j csrf)" -H 'Content-Type: application/json' -d '{"name":"11","days":30}' "$H/tokens.php?action=create")"; test "$code" = 400 || fail "אסימון 11 ($code)"

# E5: השבתה בארגון (members.active של 01) — האסימון נופל מיד
fx member_off 10
code="$(st -H "Authorization: Bearer $B" "$H/ext.php/whoami")"; test "$code" = 401 || fail "E5 הושבת בארגון ($code)"

# openapi.json ציבורי (קובץ סטטי)
code="$(st "$H/openapi.json")"; test "$code" = 200 && test "$(j openapi)" = 3.1.0 || fail "openapi.json לא מוגש"
! grep -qE "PHP (Fatal|Warning|Parse|Deprecated)" "$tmp/server.log" || { grep -E "PHP (Fatal|Warning|Parse|Deprecated)" "$tmp/server.log"; fail "שגיאות PHP בשרת"; }
echo "PASS (05): E1–E10 — Bearer בלבד (בלי/שגוי/פג/בוטל/עוגייה → 401), הפרדת ארגונים (404), הרשאות שטח (403, שום דבר לא נשמר), מאומת → לאימות, השבתה ותפקיד מיד, hash בלבד ולא ביומן, 429 אחרי 60, OpenAPI 3.1 = טבלת הקוד, events עם api ושם, public"
