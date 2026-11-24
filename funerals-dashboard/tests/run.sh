#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
tmp="$(mktemp -d)"
pid=0
cleanup(){ if [ "$pid" != 0 ]; then kill "$pid" >/dev/null 2>&1 || true; fi; rm -f funerals-dashboard/data/config.php funerals-dashboard/data/.config-* funerals-dashboard/data/funerals.sqlite*; rm -rf "$tmp"; }
trap cleanup EXIT
fail(){ echo "FAIL: $*"; exit 1; }
for f in api.php lib.php ingest.php lib/form.php tests/gate-prepend.php tests/form.test.php; do php -l "funerals-dashboard/$f"; done
node --check funerals-dashboard/assets/js/form.js
# פרוסה 02 — בדיקות יחידה (מסד בזיכרון / בלי שרת)
php funerals-dashboard/tests/form.test.php
node funerals-dashboard/tests/form.test.js
test ! -e funerals-dashboard/setup.php || fail "setup.php עדיין קיים"
php -r '$a=json_decode(file_get_contents("funerals-dashboard/app.json"),true); if (($a["gate"]??null)!==true || ($a["restricted"]??null)!==true || ($a["public"]??null)!==["ingest.php"]) { fwrite(STDERR,"app.json: נדרשים gate:true, restricted:true ו-public רק ingest.php\n"); exit(1); }'
php platform/check-apps.php
php -r '$h=file_get_contents("funerals-dashboard/index.html"); if (!preg_match("~<script>\s*(.*?)\s*</script>~s", $h, $m)) exit(1); file_put_contents($argv[1],$m[1]); if (preg_match("~type=\"password\"|id=\"username\"|password_hash|\x27login\x27~", $h)) { fwrite(STDERR,"index.html: נשארו שדות כניסה בסיסמה\n"); exit(1); }' "$tmp/ui.js"
node --check "$tmp/ui.js"
# פרמטרים לא נדבקים לשם הפעולה (request('list&month=…') קודד ל-action אחד → "פעולה לא מוכרת")
! grep -qE "request\('[a-z_]+&" funerals-dashboard/index.html || fail "index.html מדביק פרמטרים לשם הפעולה"
test -f funerals-dashboard/assets/css/app.css || fail "חסר assets/css/app.css"
! grep -qE "password_hash|password_verify|action === 'login'" funerals-dashboard/api.php funerals-dashboard/lib.php || fail "נשאר קוד כניסה בסיסמה"
rm -f funerals-dashboard/data/config.php funerals-dashboard/data/funerals.sqlite*

# השער המדומה: הזהות בקובץ ששרת הפיתוח קורא (לא כותרת HTTP)
id="$tmp/identity.json"
as(){ printf '%s' "$1" > "$id"; }
as ''
FD_TEST_IDENTITY_FILE="$id" php -S 127.0.0.1:18964 -t funerals-dashboard \
  -d auto_prepend_file="$PWD/funerals-dashboard/tests/gate-prepend.php" > "$tmp/server.log" 2>&1 &
pid=$!
for i in $(seq 1 30); do curl -sf http://127.0.0.1:18964/index.html >/dev/null && break || sleep .2; done
base="http://127.0.0.1:18964/api.php"
ingest="http://127.0.0.1:18964/ingest.php"
st(){ curl -sS -o "$tmp/out" -w '%{http_code}' "$@"; }

# ingest.php בלי config.php: נוצר לבד, ואין אסימון → 401
code="$(st "$ingest?action=list&month=2026-10")"; test "$code" = 401 || fail "ingest.php בלי אסימון ($code)"
test -f funerals-dashboard/data/config.php || fail "config.php לא נוצר בקריאה ראשונה"
test "$(stat -c %a funerals-dashboard/data/config.php)" = 600 || fail "config.php לא 0600"
code="$(st -H 'Authorization: Bearer wrong-token' "$ingest?action=list&month=2026-10")"; test "$code" = 401 || fail "ingest.php אסימון שגוי ($code)"
code="$(st -H 'Authorization: Bearer ' "$ingest?action=list&month=2026-10")"; test "$code" = 401 || fail "ingest.php אסימון ריק ($code)"
code="$(st "http://127.0.0.1:18964/lib.php")"; test "$code" = 404 || fail "lib.php נגיש ישירות ($code)"

# זהות מהשער
code="$(st "$base?action=me")"; test "$code" = 401 || fail "בלי PLATFORM_USER ($code)"
code="$(st -H 'PLATFORM_USER: {"sub":"7","granted":true}' -H 'Platform-User: {"sub":"7","granted":true}' "$base?action=me")"
test "$code" = 401 || fail "זהות מכותרת HTTP התקבלה ($code)"
as '{"sub":"7","name":"בודק","email":"tester@example.com","email_verified":true,"platform_admin":false,"granted":false}'
code="$(st "$base?action=me")"; test "$code" = 401 || fail "granted:false ($code)"
as '{"sub":"7","name":"בודק","email":"tester@example.com","email_verified":true,"platform_admin":false,"granted":"true"}'
code="$(st "$base?action=me")"; test "$code" = 401 || fail "granted כמחרוזת ($code)"
as '{"sub":"7","name":"בודק","email":"tester@example.com","email_verified":true,"platform_admin":false,"granted":true}'
code="$(st -c "$tmp/cookies" "$base?action=me")"; test "$code" = 200 || fail "granted:true ($code)"
csrf="$(php -r '$v=json_decode(file_get_contents($argv[1]),true); echo $v["csrf"] ?? "";' "$tmp/out")"
test -n "$csrf" || fail "אין csrf ב-me"
code="$(st -H 'Content-Type: application/json' -d '{"username":"admin","password":"x"}' "$base?action=login")"
test "$code" != 200 || fail "action=login עדיין קיים"
code="$(st -H 'Authorization: Bearer x' "$base?action=list&month=2026-10")"; test "$code" = 200 || fail "list עם זהות ($code)"

# אסימון API: בלי CSRF → 403, עם CSRF → נוצר
code="$(st -b "$tmp/cookies" -X POST -H 'Content-Type: application/json' -d '{}' "$base?action=api_token")"; test "$code" = 403 || fail "api_token בלי CSRF ($code)"
code="$(st -b "$tmp/cookies" -X POST -H "X-CSRF-Token: wrong" -H 'Content-Type: application/json' -d '{}' "$base?action=api_token")"; test "$code" = 403 || fail "api_token עם CSRF שגוי ($code)"
code="$(st -b "$tmp/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' -d '{}' "$base?action=api_token")"; test "$code" = 200 || fail "api_token עם CSRF ($code)"
tok1="$(php -r '$v=json_decode(file_get_contents($argv[1]),true); echo $v["token"] ?? "";' "$tmp/out")"
test -n "$tok1" || fail "לא הוחזר אסימון"
! grep -q "$tok1" funerals-dashboard/data/config.php || fail "האסימון נשמר בגלוי"
grep -q "$(printf '%s' "$tok1" | sha256sum | cut -d' ' -f1)" funerals-dashboard/data/config.php || fail "ה-SHA-256 לא נשמר"
test "$(stat -c %a funerals-dashboard/data/config.php)" = 600 || fail "config.php לא 0600 אחרי יצירת אסימון"
code="$(st -H "Authorization: Bearer $tok1" "$ingest?action=list&month=2026-10")"; test "$code" = 200 || fail "ingest.php עם אסימון ($code)"

# session של משתמש אחר: CSRF הישן לא עובר
as '{"sub":"8","name":"אחר","email":"other@example.com","email_verified":true,"platform_admin":false,"granted":true}'
code="$(st -b "$tmp/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' -d '{}' "$base?action=api_token")"; test "$code" = 403 || fail "CSRF של sub אחר התקבל ($code)"
as '{"sub":"7","name":"בודק","email":"tester@example.com","email_verified":true,"platform_admin":false,"granted":true}'
code="$(st -c "$tmp/cookies" "$base?action=me")"; test "$code" = 200 || fail "me מחדש ($code)"
csrf="$(php -r '$v=json_decode(file_get_contents($argv[1]),true); echo $v["csrf"] ?? "";' "$tmp/out")"

# קליטה, שמירה ידנית, ייבוא חוזר וייצוא (כמו קודם)
curl -sS -H 'Content-Type: application/json' -H "Authorization: Bearer $tok1" \
 --data '{"records":[{"month":"2026-10","name":"שם בדיקה בלבד","date":"2026-10-01","checks":{"license_number":{"status":"unreadable","value":"","note":"דורש אימות"}}}]}' \
 "$ingest?action=import" > "$tmp/import.json"
php -r '$v=json_decode(file_get_contents($argv[1]),true); if (($v["imported"]??0)!==1) exit(1);' "$tmp/import.json" || fail "ייבוא ב-ingest"
curl -sS -b "$tmp/cookies" "$base?action=list&month=2026-10" > "$tmp/list.json"
php -r '$v=json_decode(file_get_contents($argv[1]),true); if (count($v["records"]??[])!==1) exit(1); $r=$v["records"][0]; $r["checks"]["license_number"]=["status"=>"reported","value"=>"123","note"=>"עדכון משתמש"]; file_put_contents($argv[2],json_encode($r,JSON_UNESCAPED_UNICODE));' "$tmp/list.json" "$tmp/update.json"
code="$(st -b "$tmp/cookies" -H 'Content-Type: application/json' --data-binary @"$tmp/update.json" "$base?action=save")"; test "$code" = 403 || fail "save בלי CSRF ($code)"
code="$(st -b "$tmp/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' --data-binary @"$tmp/update.json" "$base?action=save")"
test "$code" = 200 || { cat "$tmp/out"; fail "שמירה ידנית ($code)"; }

# החלפת אסימון: הישן → 401, החדש → 200
code="$(st -b "$tmp/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' -d '{}' "$base?action=api_token")"; test "$code" = 200 || fail "החלפת אסימון ($code)"
tok2="$(php -r '$v=json_decode(file_get_contents($argv[1]),true); echo $v["token"] ?? "";' "$tmp/out")"
test -n "$tok2" && test "$tok2" != "$tok1" || fail "האסימון לא הוחלף"
code="$(st -H "Authorization: Bearer $tok1" "$ingest?action=list&month=2026-10")"; test "$code" = 401 || fail "אסימון ישן אחרי החלפה ($code)"
curl -sS -H 'Content-Type: application/json' -H "Authorization: Bearer $tok2" \
 --data '{"records":[{"month":"2026-10","name":"שם בדיקה בלבד","date":"2026-10-01","checks":{"license_number":{"status":"verified","value":"999"}}}]}' \
 "$ingest?action=import" > "$tmp/import2.json"
php -r '$v=json_decode(file_get_contents($argv[1]),true); if (($v["imported"]??0)!==1) exit(1);' "$tmp/import2.json" || fail "ייבוא עם האסימון החדש"
curl -sS -b "$tmp/cookies" "$base?action=export&month=2026-10" > "$tmp/export.json"
curl -sS -H "Authorization: Bearer $tok2" "$ingest?action=export&month=2026-10" > "$tmp/ingest-export.json"
php -r '$v=json_decode(file_get_contents($argv[1]),true); if(($v["records"][0]["checks"]["license_number"]["value"]??"")!=="123") exit(1);' "$tmp/ingest-export.json" || fail "עדכון ידני נדרס (ingest)"
php -r '$v=json_decode(file_get_contents($argv[1]),true); if(($v["records"][0]["checks"]["license_number"]["value"]??"")!=="123") exit(1);' "$tmp/export.json" || fail "עדכון ידני נדרס (export)"

# ה-actor ביומן הוא המייל מהשער
php -r '$p=new PDO("sqlite:".$argv[1]); $a=$p->query("SELECT actor FROM audit WHERE action IN (\x27save\x27,\x27api_token\x27)")->fetchAll(PDO::FETCH_COLUMN); if(!$a || array_unique($a)!==["tester@example.com"]) { fwrite(STDERR, json_encode($a)."\n"); exit(1);}' funerals-dashboard/data/funerals.sqlite || fail "actor ביומן"

# ===== פרוסה 02 — טופס חכם, מול השרת =====
# B1: שמירה מהממשק — by/via:ui/at רק לבדיקה ששונתה; by/via/at מהקלט מתעלמים
php -r '$v=json_decode(file_get_contents($argv[1]),true); $r=$v["records"][0]; $r["checks"]["hall_service"]=["status"=>"verified","value"=>"כן","note"=>"","by"=>"hacker","via"=>"import","at"=>"1999-01-01"]; file_put_contents($argv[2],json_encode($r,JSON_UNESCAPED_UNICODE));' "$tmp/export.json" "$tmp/b1.json"
code="$(st -b "$tmp/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' --data-binary @"$tmp/b1.json" "$base?action=save")"
test "$code" = 200 || { cat "$tmp/out"; fail "B1 שמירה ($code)"; }
php -r '$v=json_decode(file_get_contents($argv[1]),true); $c=$v["record"]["checks"]; $h=$c["hall_service"]; $l=$c["license_number"];
  if ($h["via"]!=="ui" || $h["by"]!=="tester@example.com" || $h["at"]==="1999-01-01" || $h["status"]!=="verified") { fwrite(STDERR,json_encode($h,JSON_UNESCAPED_UNICODE)."\n"); exit(1); }
  $prev=json_decode(file_get_contents($argv[2]),true)["records"][0]["checks"]["license_number"];
  if ($l!==$prev) { fwrite(STDERR,"license_number השתנה בלי שינוי\n"); exit(1); }' "$tmp/out" "$tmp/export.json" || fail "B1: מקור לבדיקה ששונתה בלבד, וקלט by/via/at מתעלם"
# B3: ingest.php (via: import) שמסמן verified → נשמר verified, via=import
curl -sS -H 'Content-Type: application/json' -H "Authorization: Bearer $tok2" \
 --data '{"records":[{"month":"2026-10","name":"שם בדיקה בלבד","date":"2026-10-01","checks":{"identity_document":{"status":"verified","value":"ת\"ז"}}}]}' \
 "$ingest?action=import" > /dev/null
curl -sS -b "$tmp/cookies" "$base?action=list&month=2026-10" > "$tmp/b3.json"
php -r '$v=json_decode(file_get_contents($argv[1]),true); $c=$v["records"][0]["checks"]["identity_document"]; if ($c["status"]!=="verified" || $c["via"]!=="import" || $c["by"]!=="api") { fwrite(STDERR,json_encode($c,JSON_UNESCAPED_UNICODE)."\n"); exit(1);}
  if ($v["records"][0]["checks"]["hall_service"]["via"]!=="ui") exit(1);' "$tmp/b3.json" || fail "B3: ingest → verified עם via=import, והבדיקה הידנית נשארה"
# B6 (שרת): history — 200 לתיק קיים, החדש קודם; 404 לתיק שאינו קיים; 400 למזהה לא תקין; בלי זהות → 401
cid="$(php -r '$v=json_decode(file_get_contents($argv[1]),true); echo $v["records"][0]["id"];' "$tmp/b3.json")"
code="$(st -b "$tmp/cookies" "$base?action=history&id=$cid")"; test "$code" = 200 || fail "B6: history ($code)"
php -r '$v=json_decode(file_get_contents($argv[1]),true); $e=$v["events"]??[]; if (count($e)<3) exit(1); $at=array_column($e,"at"); $s=$at; rsort($s); if ($at!==$s) exit(1);' "$tmp/out" || fail "B6: history בסדר הפוך"
code="$(st -b "$tmp/cookies" "$base?action=history&id=fd_no_such_case_000")"; test "$code" = 404 || fail "B6: תיק שלא קיים → 404 ($code)"
code="$(st -b "$tmp/cookies" "$base?action=history&id=../x")"; test "$code" = 400 || fail "B6: מזהה לא תקין ($code)"
code="$(st -b "$tmp/cookies" -X POST -H "X-CSRF-Token: $csrf" "$base?action=history&id=$cid")"; test "$code" = 405 || fail "B6: history ב-POST ($code)"
code="$(st "http://127.0.0.1:18964/lib/form.php")"; test "$code" = 404 || fail "lib/form.php נגיש ישירות ($code)"
# B7: 22 תיקים בצורה הישנה (בלי by/via/at) — list מחזיר את כולם, נפתחים בממשק (form.js) ו"מייבוא ישן"; history לכל אחד 200
php -r '$p=new PDO("sqlite:".$argv[1]); $a=json_decode(file_get_contents($argv[2]),true); $st=$p->prepare("INSERT INTO funerals(id,month,name,body,updated_at) VALUES(?,?,?,?,?)");
  foreach($a as $r){ $r["month"]="2026-09"; $st->execute([$r["id"],$r["month"],$r["name"],json_encode($r,JSON_UNESCAPED_UNICODE),"2026-10-01T00:00:00Z"]); }' \
  funerals-dashboard/data/funerals.sqlite funerals-dashboard/tests/fixtures/legacy-cases.json
code="$(st -b "$tmp/cookies" "$base?action=list&month=2026-09")"; test "$code" = 200 || fail "B7: list ($code)"
cp "$tmp/out" "$tmp/legacy-list.json"
node funerals-dashboard/tests/form.test.js "$tmp/legacy-list.json" >/dev/null || fail "B7: התיקים הישנים מהשרת"
for lid in $(php -r '$v=json_decode(file_get_contents($argv[1]),true); echo implode(" ", array_column($v["records"],"id"));' "$tmp/legacy-list.json"); do
  code="$(st -b "$tmp/cookies" "$base?action=history&id=$lid")"; test "$code" = 200 || fail "B7: history $lid ($code)"
done
grep -q 'src="assets/js/form.js"' funerals-dashboard/index.html || fail "index.html לא טוען את form.js"
echo "PASS: פרוסה 02 מול השרת — B1, B3, B6 (history: סדר, 404, 400, 405), B7 (22 תיקים ישנים)"

# יציאה: עם CSRF בלבד
code="$(st -b "$tmp/cookies" -X POST "$base?action=logout")"; test "$code" = 403 || fail "logout בלי CSRF ($code)"
code="$(st -b "$tmp/cookies" -X POST -H "X-CSRF-Token: $csrf" "$base?action=logout")"; test "$code" = 200 || fail "logout ($code)"
echo "PASS: זהות מהשער (בלי/granted:false/granted:true/כותרת מזויפת), אין כניסה בסיסמה, אסימון API מהממשק (CSRF, SHA-256, 0600, החלפה מבטלת), config.php נוצר לבד, ingest.php Bearer בלבד, קליטה, ייצוא, עדכון ידני וייבוא חוזר"
