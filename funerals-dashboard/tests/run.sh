#!/usr/bin/env bash
# הבדיקות של ניהול לוויות חברה קדישא — רצות בפריסה (deploy.yml) ועוצרות אותה בכשל.
# שרת פיתוח של PHP + שער מדומה (tests/gate-prepend.php). מתחילים ממסד בגרסה 0 עם 22 תיקים (tests/fixture-v0.php),
# כמו בשרת, ובודקים את קריטריוני הקבלה של פרוסה 01 (A1–A14, A16 חלקית, A17). data/ מתנקה בסוף — חוץ מ-.htaccess.
set -euo pipefail
cd "$(dirname "$0")/../.."
APP=funerals-dashboard
D="$APP/data"
tmp="$(mktemp -d)"
pid=0
wipe(){ find "$D" -mindepth 1 ! -name .htaccess ! -name .gitignore -exec rm -rf {} + 2>/dev/null || true; }
cleanup(){ if [ "$pid" != 0 ]; then kill "$pid" >/dev/null 2>&1 || true; fi; wipe; rm -rf "$tmp"; }
trap cleanup EXIT
fail(){ echo "FAIL: $*"; [ -f "$tmp/out" ] && { echo "--- תשובה אחרונה:"; head -c 600 "$tmp/out"; echo; }; exit 1; }
ok(){ echo "  ✓ $*"; }

# ===== A17: בדיקות סטטיות =====
for f in api.php lib.php ingest.php lib/schema.php lib/access.php lib/events.php lib/secrets.php lib/devtasks.php lib/form.php tests/gate-prepend.php tests/fixture-v0.php tests/form.test.php lib/field.php tests/field.test.php tests/field-dropbox.test.php \
    dropbox-webhook.php bin/dropbox-sync.php dropbox/lib.php dropbox/actions.php dropbox/compat.php tests/dropbox-mock.php tests/dropbox-test.php; do
  php -l "$APP/$f" >/dev/null || fail "php -l $f"
done
node --check "$APP/assets/js/app.js" || fail "node --check app.js"
node --check "$APP/assets/js/form.js" || fail "node --check form.js"
node --check "$APP/assets/js/dropbox.js" || fail "node --check dropbox.js"
# פרוסה 02 — בדיקות יחידה (בלי שרת ובלי data/)
php "$APP/tests/form.test.php" || fail "form.test.php"
node "$APP/tests/form.test.js" || fail "form.test.js"
# פרוסה 04 — עובד שטח: הליבה בדפדפן (הקטנה, GPS, תור) — בלי שרת
node --check "$APP/assets/js/field-core.js" || fail "node --check field-core.js"
node --check "$APP/assets/js/field.js" || fail "node --check field.js"
node --check "$APP/sw.js" || fail "node --check sw.js"
# sw.js: רק Background Sync — בלי fetch handler ובלי מטמון (ביקורת 04 #2)
grep -q "addEventListener('sync'" "$APP/sw.js" && ! grep -qE "addEventListener\('fetch'|caches\." "$APP/sw.js" || fail "sw.js: רק sync, בלי fetch/מטמון"
node "$APP/tests/field.test.js" || fail "field.test.js"
grep -q 'src="assets/js/field.js"' "$APP/index.html" || fail "index.html לא טוען את field.js"
grep -q 'src="assets/js/form.js"' "$APP/index.html" || fail "index.html לא טוען את form.js"
test ! -e "$APP/setup.php" || fail "setup.php עדיין קיים"
php -r '$a=json_decode(file_get_contents($argv[1]),true); $pub=["ingest.php","dropbox-webhook.php","ext.php","openapi.json","sw.js","api.php?action=devapi-feed|devapi-comment|devapi-file|devapi-attach"];
  if (($a["gate"]??null)!==true || ($a["restricted"]??null)!==true || ($a["public"]??null)!==$pub) { fwrite(STDERR,"app.json: gate, restricted, ו-public רק ingest.php, dropbox-webhook.php, ext.php, openapi.json ופעולות devapi\n"); exit(1); }' "$APP/app.json" || fail "app.json"
php platform/check-apps.php >/dev/null || fail "check-apps.php"
php -r 'json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);' "$APP/dev/open-items.json" || fail "dev/open-items.json אינו JSON תקין"
! grep -qE 'type="password"|id="username"' "$APP/index.html" || fail "index.html: נשארו שדות כניסה בסיסמה"
! grep -qE "password_hash|password_verify|action === 'login'" "$APP/api.php" "$APP/lib.php" || fail "נשאר קוד כניסה בסיסמה"
! grep -qE "request\('[a-z_]+&" "$APP/assets/js/app.js" || fail "app.js מדביק פרמטרים לשם הפעולה"
test -f "$APP/assets/css/app.css" || fail "חסר assets/css/app.css"
# A14: השם, ואין קישור לכלים הדיגיטליים
grep -q '<title>ניהול לוויות חברה קדישא</title>' "$APP/index.html" || fail "A14: <title>"
grep -q '<h1>🕯️ ניהול לוויות חברה קדישא</h1>' "$APP/index.html" || fail "A14: כותרת"
! grep -qE 'href="(\.\./|[^"]*index\.html)' "$APP/index.html" || fail "A14: קישור לכלים ב-index.html"
! grep -qE "['\"]\.\./" "$APP/assets/js/app.js" || fail "A14: קישור ../ ב-app.js"
! grep -rqE "require[_a-z]*\s*\(?\s*__DIR__\s*\.\s*'/\.\./\.\." "$APP"/*.php "$APP"/lib/*.php || fail "require מחוץ לתיקייה"
ok "A14, A17 — סטטי"

# ===== מסד בגרסה 0, כמו בשרת =====
wipe
TOK0="fd_$(printf 'fixture-token-%.0s' 1 2 3 | sha256sum | cut -c1-64)"
php "$APP/tests/fixture-v0.php" "$D" "$TOK0"
DB="$D/funerals.sqlite"
sql(){ php -r '$p=new PDO("sqlite:".$argv[1]); $r=$p->query($argv[2])->fetchAll(PDO::FETCH_NUM); foreach($r as $row) echo implode("|",$row),"\n";' "$DB" "$1"; }
sql "SELECT id, body FROM funerals ORDER BY id" > "$tmp/cases0"
sql "SELECT * FROM audit ORDER BY id" > "$tmp/audit0"
test "$(wc -l < "$tmp/cases0")" = 22 || fail "fixture: 22 תיקים"

# ===== השער המדומה =====
idf="$tmp/identity.json"
as(){ printf '%s' "$1" > "$idf"; }
as ''
FD_TEST_IDENTITY_FILE="$idf" php -d opcache.jit=disable -S 127.0.0.1:18964 -t "$APP" -d auto_prepend_file="$PWD/$APP/tests/gate-prepend.php" > "$tmp/server.log" 2>&1 &
pid=$!
for i in $(seq 1 30); do curl -sf http://127.0.0.1:18964/index.html >/dev/null && break || sleep .2; done
base="http://127.0.0.1:18964/api.php"
ingest="http://127.0.0.1:18964/ingest.php"
st(){ curl -sS -o "$tmp/out" -w '%{http_code}' "$@"; }
jv(){ php -r '$v=json_decode(file_get_contents($argv[1]),true); $x=eval("return ".$argv[2].";"); echo is_scalar($x)||$x===null ? var_export($x,true) : json_encode($x,JSON_UNESCAPED_UNICODE);' "$tmp/out" "$1"; }

declare -A ID CSRF
ident(){ printf '{"sub":"%s","name":"%s","email":"%s","email_verified":true,"platform_admin":%s,"granted":true}' "$1" "$2" "$3" "$4"; }
ID[dev]="$(ident 1 'המפתח' 'dev@example.com' true)"
ID[boss]="$(ident 2 'מנהל א' 'boss@example.com' false)"
ID[field]="$(ident 3 'עובד שטח' 'X@EXAMPLE.COM' false)"
ID[office]="$(ident 4 'משרד' 'office@example.com' false)"
ID[a2]="$(ident 5 'מנהל ב' 'a2@example.com' false)"
ID[nobody]="$(ident 6 'אורח' 'nobody@example.com' false)"
# me — ושומרים את ה-CSRF של המשתמש. לא בתוך $(…): המערך CSRF חייב להישאר בתהליך הזה. התוצאה ב-$code.
login(){ as "${ID[$1]}"; code="$(st -b "$tmp/c-$1" -c "$tmp/c-$1" "$base?action=me")"; CSRF[$1]="$(jv '$v["csrf"] ?? ""' | tr -d "'")"; }
get(){ as "${ID[$1]}"; st -b "$tmp/c-$1" -c "$tmp/c-$1" "$base?action=$2${3:-}"; }
post(){ as "${ID[$1]}"; st -b "$tmp/c-$1" -c "$tmp/c-$1" -H "X-CSRF-Token: ${CSRF[$1]:-}" -H 'Content-Type: application/json' --data-binary "$3" "$base?action=$2${4:-}"; }

# ===== A2 (כשל): תיקיית הגיבוי לא ניתנת לכתיבה → 503, והמסד נשאר בגרסה 0 =====
: > "$D/backup"   # קובץ במקום תיקייה — mkdir נכשל גם כשרצים כ-root
login dev; test "$code" = 503 || fail "A2: בלי תיקיית גיבוי ($code)"
test "$(sql 'PRAGMA user_version')" = 0 || fail "A2: המסד שודרג בלי גיבוי"
test "$(sql "SELECT COUNT(*) FROM sqlite_master WHERE name='orgs'")" = 0 || fail "A2: נוצרו טבלאות בלי גיבוי"
rm -f "$D/backup"
ok "A2 — גיבוי נכשל → 503, גרסה 0"

# ===== A1, A2, A8: בקשה ראשונה של המפתח =====
login dev; test "$code" = 200 || fail "A1: me של המפתח ($code)"
test "$(sql 'PRAGMA user_version')" = 3 || fail "A1: user_version (01 → 1, 03 → 2, 04 → 3)"
test "$(sql "SELECT COUNT(*) FROM sqlite_master WHERE name='field_uploads'")" = 1 || fail "A1: field_uploads (v3)"
sql "SELECT id, body FROM funerals ORDER BY id" > "$tmp/cases1"
cmp -s "$tmp/cases0" "$tmp/cases1" || fail "A1: התיקים השתנו (id/body)"
test "$(sql 'SELECT COUNT(*) FROM funerals WHERE org_id = 1')" = 22 || fail "A1: org_id=1 לכל 22"
sql "SELECT * FROM audit ORDER BY id" > "$tmp/audit1"
cmp -s "$tmp/audit0" "$tmp/audit1" || fail "A1: audit השתנה"
ok "A1 — 22 תיקים, אותם id ו-body, org_id=1, audit זהה, user_version=3 (סכמה עדכנית)"
bk=( "$D"/backup/funerals-*-v0.sqlite )
test "${#bk[@]}" = 1 && test -f "${bk[0]}" || fail "A2: אין גיבוי"
test "$(stat -c %a "${bk[0]}")" = 600 || fail "A2: הגיבוי לא 0600"
test "$(php -r '$p=new PDO("sqlite:".$argv[1]); echo $p->query("SELECT COUNT(*) FROM funerals")->fetchColumn();' "${bk[0]}")" = 22 || fail "A2: בגיבוי אין 22 תיקים"
ok "A2 — גיבוי 0600 עם 22 תיקים"
test "$(jv '$v["org"]["id"]')" = 1 && test "$(jv '$v["dev"]')" = true || fail "A8: המפתח בארגון 1"
test "$(sql "SELECT m.role FROM members m JOIN users u ON u.id=m.user_id WHERE u.sub='1' AND m.org_id=1")" = admin || fail "A8: המפתח לא admin של ארגון 1"
code="$(get dev list '&month=2026-10')"; test "$code" = 200 && test "$(jv 'count($v["records"])')" = 22 || fail "A8: המפתח רואה 22 תיקים ($code)"
# ביקורת 5: secret.key נוצר בשימוש הראשון; מפתח פגום לא מפיל את התיקים ואת ingest.php
test ! -e "$D/secret.key" || fail "secret.key נוצר בלי שימוש"
printf 'bad' > "$D/secret.key"
code="$(get dev list '&month=2026-10')"; test "$code" = 200 || fail "מפתח פגום הפיל את list ($code)"
code="$(st -H "Authorization: Bearer $TOK0" "$ingest?action=list&month=2026-10")"; test "$code" = 200 || fail "מפתח פגום הפיל את ingest ($code)"
rm -f "$D/secret.key"
code="$(get dev dev_health)"; test "$code" = 200 || fail "A8: מסך מפתח ($code)"
test "$(jv 'count(array_filter($v["checks"], fn($c) => !$c["ok"]))')" = 0 || fail "A16: בדיקת תקינות אדומה: $(jv 'array_values(array_filter($v["checks"], fn($c) => !$c["ok"]))')"
test "$(stat -c %a "$D/secret.key")" = 600 || fail "A11: secret.key לא 0600"
ok "A8 — המפתח admin של ארגון 1 ורואה הכול; בדיקות התקינות ירוקות"

# ===== A3: אידמפוטנטיות =====
login dev; test "$code" = 200 || fail "A3: me שני"
php -r 'chdir($argv[1]); require "lib.php"; $p=db(); $p->exec("BEGIN"); migrateV1($p); migrateV1($p); $p->exec("COMMIT"); migrate($p);' "$APP" >/dev/null
test "$(sql 'SELECT COUNT(*) FROM orgs')" = 1 || fail "A3: נוסף ארגון"
sql "SELECT id, body FROM funerals ORDER BY id" > "$tmp/cases2"; cmp -s "$tmp/cases0" "$tmp/cases2" || fail "A3: תיק השתנה"
test "$(ls "$D"/backup/*.sqlite | wc -l)" = 1 || fail "A3: גיבוי שני"
ok "A3 — מיגרציה חוזרת לא משנה דבר"

# ===== זהות מהשער (כמו קודם) =====
as ''; code="$(st "$base?action=me")"; test "$code" = 401 || fail "בלי PLATFORM_USER ($code)"
code="$(st -H 'PLATFORM_USER: {"sub":"7","granted":true}' -H 'Platform-User: {"sub":"7","granted":true}' "$base?action=me")"; test "$code" = 401 || fail "זהות מכותרת HTTP ($code)"
as '{"sub":"7","name":"בודק","email":"t@example.com","platform_admin":true,"granted":false}'; code="$(st "$base?action=me")"; test "$code" = 401 || fail "granted:false ($code)"
as '{"sub":"7","name":"בודק","email":"t@example.com","platform_admin":true,"granted":"true"}'; code="$(st "$base?action=me")"; test "$code" = 401 || fail "granted כמחרוזת ($code)"
as '{"sub":"7","granted":true,"platform_admin":"true"}'; code="$(st "$base?action=dev_health")"; test "$code" = 403 || fail "platform_admin כמחרוזת נחשב מפתח ($code)"
code="$(st "http://127.0.0.1:18964/lib.php")"; test "$code" = 404 || fail "lib.php נגיש ($code)"
for f in schema access events secrets devtasks form; do code="$(st "http://127.0.0.1:18964/lib/$f.php")"; test "$code" = 404 || fail "lib/$f.php נגיש ($code)"; done
as ''; code="$(st "$base?action=devapi-feed")"; test "$code" = 401 || fail "devapi בלי אסימון ($code)"
ok "זהות מהשער, קבצי lib חסומים, devapi דורש אסימון"

# ===== A7: בלי ארגון =====
login nobody; test "$code" = 403 && test "$(jv '$v["code"]')" = "'no_org'" || fail "A7: me ($code)"
jv '$v["error"]' | grep -q 'עדיין לא שויכת' || fail "A7: ההודעה"
jv '$v["error"]' | grep -q 'nobody@example.com' || fail "A7: המייל בהודעה"
code="$(get nobody list '&month=2026-10')"; test "$code" = 403 || fail "A7: list ($code)"
! grep -q 'נפטר' "$tmp/out" || fail "A7: דלף נתון"
ok "A7 — בלי ארגון: מסך 'עדיין לא שויכת', list → 403"

# ===== A6: הזמנה — המפתח ממנה מנהל לארגון 1, המנהל מזמין עובד שטח ומשרד =====
code="$(post dev invite '{"email":"boss@example.com","role":"admin"}')"; test "$code" = 200 || fail "הזמנת מנהל ($code)"
login boss; test "$code" = 200 && test "$(jv '$v["role"]')" = "'admin'" || fail "המנהל נכנס ($code)"
code="$(post boss invite '{"email":"x@example.com","role":"field"}')"; test "$code" = 200 || fail "A6: הזמנה ($code)"
code="$(post boss invite '{"email":"office@example.com","role":"office"}')"; test "$code" = 200 || fail "הזמנת משרד ($code)"
test -z "$(sql "SELECT used_at FROM invites WHERE email='x@example.com'")" || fail "A6: ההזמנה סומנה לפני כניסה"
login field; test "$code" = 200 && test "$(jv '$v["role"]')" = "'field'" || fail "A6: עובד השטח נכנס כ-field ($code)"
test -n "$(sql "SELECT used_at FROM invites WHERE email='x@example.com'")" || fail "A6: used_at"
test "$(sql "SELECT email FROM users WHERE sub='3'")" = x@example.com || fail "A6: המייל נשמר באותיות קטנות"
login office; test "$code" = 200 || fail "משרד נכנס ($code)"
# ביקורת 3: מייל לא מאומת לא מממש הזמנה ולא מקשר חשבון
code="$(post boss invite '{"email":"unv@example.com","role":"office"}')"; test "$code" = 200 || fail "הזמנה ל-unv ($code)"
ID[unv]='{"sub":"7","name":"לא מאומת","email":"unv@example.com","email_verified":false,"platform_admin":false,"granted":true}'
login unv; test "$code" = 403 || fail "ביקורת 3: מייל לא מאומת מימש הזמנה ($code)"
test -z "$(sql "SELECT used_at FROM invites WHERE email='unv@example.com'")" || fail "ביקורת 3: ההזמנה סומנה"
test -z "$(sql "SELECT email FROM users WHERE sub='7'")" || fail "ביקורת 3: מייל לא מאומת נשמר"
ok "A6 — הזמנה במייל (באותיות גדולות) → חבר field, used_at"

# ===== A5: תפקידים — עובד שטח =====
code="$(get field list '&month=2026-10')"; test "$code" = 200 || fail "A5: field list ($code)"
php -r '$v=json_decode(file_get_contents($argv[1]),true); $r=$v["records"][0]; file_put_contents($argv[2], json_encode($r, JSON_UNESCAPED_UNICODE));' "$tmp/out" "$tmp/case.json"
CID="$(php -r 'echo json_decode(file_get_contents($argv[1]),true)["id"];' "$tmp/case.json")"
mut(){ php -r '$r=json_decode(file_get_contents($argv[1]),true); eval($argv[2]); echo json_encode($r, JSON_UNESCAPED_UNICODE);' "$tmp/case.json" "$1"; }
before="$(sql "SELECT body FROM funerals WHERE id='$CID'")"
code="$(post field save "$(mut '$r["name"]="שם אחר";')")"; test "$code" = 403 || fail "A5: שינוי שם ($code)"
code="$(post field save "$(mut '$r["payments"][0]["amount"]=1;')")"; test "$code" = 403 || fail "A5: תשלום ($code)"
code="$(post field save "$(mut '$r["id"]=""; $r["name"]="תיק חדש משטח";')")"; test "$code" = 403 || fail "A5: תיק חדש ($code)"
code="$(post field import '{"records":[{"month":"2026-10","name":"ייבוא שטח"}]}')"; test "$code" = 403 || fail "A5: ייבוא ($code)"
code="$(get field export '&month=2026-10')"; test "$code" = 403 || fail "A5: ייצוא ($code)"
code="$(post field invite '{"email":"y@example.com","role":"field"}')"; test "$code" = 403 || fail "A5: הזמנה ($code)"
code="$(get field members)"; test "$code" = 403 || fail "A5: רשימת חברים ($code)"
test "$(sql "SELECT body FROM funerals WHERE id='$CID'")" = "$before" || fail "A5: משהו נשמר אחרי 403"
test "$(sql "SELECT COUNT(*) FROM funerals WHERE name IN ('ייבוא שטח','תיק חדש משטח')")" = 0 || fail "A5: נוצר תיק"
test "$(sql "SELECT COUNT(*) FROM invites WHERE email='y@example.com'")" = 0 || fail "A5: נשמרה הזמנה"
code="$(post field save "$(mut '$r["checks"]["license_number"]=["status"=>"verified","value"=>"5555","note"=>"נבדק בשטח"]; $r["grave"]="גוש 9 חלקה 3"; $r["notes"]="הערה מהשטח";')")"
test "$code" = 200 || fail "A5: עדכון בדיקה ($code)"
ok "A5 — עובד שטח: שם/תשלום/תיק חדש/ייבוא/ייצוא/הזמנה → 403; בדיקה, קבר והערה → 200"

# ===== A10: אירועים =====
ev(){ sql "SELECT org_id, actor, source, action, diff FROM events WHERE case_id='$1' ORDER BY id DESC LIMIT 1"; }
e="$(ev "$CID")"
php -r '[$o,$a,$s,$act,$d]=explode("|",$argv[1],5); $d=json_decode($d,true);
  if($o!=="1"||$a!=="x@example.com"||$s!=="ui"||$act!=="save"||!isset($d["checks.license_number"],$d["grave"],$d["notes"])||isset($d["name"])) exit(1);' "$e" || fail "A10: אירוע save: $e"
code="$(post office import '{"records":[{"month":"2026-10","name":"ייבוא משרד","date":"2026-10-30"}]}')"; test "$code" = 200 || fail "ייבוא משרד ($code)"
IMP="$(sql "SELECT id FROM funerals WHERE name='ייבוא משרד'")"
e="$(ev "$IMP")"; php -r '[$o,$a,$s,$act,$d]=explode("|",$argv[1],5); $d=json_decode($d,true); if($o!=="1"||$a!=="office@example.com"||$s!=="ui"||$act!=="create"||($d["name"][1]??"")!=="ייבוא משרד") exit(1);' "$e" || fail "A10: אירוע import: $e"
code="$(get office export '&month=2026-10')"; test "$code" = 200 || fail "משרד מייצא ($code)"
code="$(post office save "$(mut '$r["payments"][0]["amount"]=777;')")"; test "$code" = 200 || fail "משרד משנה תשלום ($code)"
# ביקורת 4: השוואה מחמירה — תשלום null → 0 בידי עובד שטח הוא שינוי
refresh(){ get boss list '&month=2026-10' >/dev/null; php -r '$v=json_decode(file_get_contents($argv[1]),true); foreach($v["records"] as $r) if($r["id"]===$argv[3]) file_put_contents($argv[2], json_encode($r, JSON_UNESCAPED_UNICODE));' "$tmp/out" "$tmp/case.json" "$CID"; }
refresh; code="$(post office save "$(mut '$r["payments"][0]["amount"]=null;')")"; test "$code" = 200 || fail "משרד מאפס סכום ($code)"
refresh; code="$(post field save "$(mut '$r["payments"][0]["amount"]=0;')")"; test "$code" = 403 || fail "ביקורת 4: null→0 בידי שטח ($code)"
code="$(post field save "$(mut '$r["payments"][0]["reference"]="1e2";')")"; test "$code" = 403 || fail "ביקורת 4: אסמכתה בידי שטח ($code)"
code="$(post field save "$(mut '$r["notes"]="עדיין מותר";')")"; test "$code" = 200 || fail "ביקורת 4: הערה בידי שטח אחרי השוואה מחמירה ($code)"
refresh
ok "A10 — כל save/import נרשם ב-events עם org_id, actor ו-diff; השוואה מחמירה"

# ===== A9: ingest.php עם האסימון שהיה לפני המיגרציה =====
code="$(st -H "Authorization: Bearer $TOK0" "$ingest?action=list&month=2026-10")"; test "$code" = 200 || fail "A9: האסימון הישן ($code)"
code="$(st "$ingest?action=list&month=2026-10")"; test "$code" = 401 || fail "ingest בלי אסימון ($code)"
code="$(st -H 'Authorization: Bearer wrong' "$ingest?action=list&month=2026-10")"; test "$code" = 401 || fail "ingest אסימון שגוי ($code)"
code="$(st -H 'Authorization: Bearer ' "$ingest?action=list&month=2026-10")"; test "$code" = 401 || fail "ingest אסימון ריק ($code)"
code="$(st -H 'Content-Type: application/json' -H "Authorization: Bearer $TOK0" --data '{"records":[{"month":"2026-10","name":"שם בדיקה בלבד","date":"2026-10-01","checks":{"license_number":{"status":"unreadable","value":"","note":"דורש אימות"}}}]}' "$ingest?action=import")"
test "$code" = 200 || fail "A9: ייבוא ב-ingest ($code)"
ING="$(sql "SELECT id FROM funerals WHERE name='שם בדיקה בלבד'")"
test "$(sql "SELECT org_id FROM funerals WHERE id='$ING'")" = 1 || fail "A9: לא בארגון 1"
test "$(sql "SELECT source||'/'||org_id FROM events WHERE case_id='$ING'")" = import/1 || fail "A9: events source import"
ok "A9 — האסימון הקיים עובד; ייבוא לארגון 1, source: import"

# ===== A4: הפרדה בין ארגונים =====
code="$(post dev org_create '{"name":"ארגון בדיקה"}')"; test "$code" = 200 && test "$(jv '$v["created"]')" = 2 || fail "יצירת ארגון ($code)"
code="$(post dev org_switch '{"org_id":2}')"; test "$code" = 200 || fail "מעבר לארגון 2 ($code)"
code="$(get dev list '&month=2026-10')"; test "$(jv 'count($v["records"])')" = 0 || fail "A4: המפתח בארגון 2 רואה תיקים"
code="$(post dev invite '{"email":"a2@example.com","role":"admin"}')"; test "$code" = 200 || fail "הזמנה לארגון 2 ($code)"
login a2; test "$code" = 200 && test "$(jv '$v["org"]["id"]')" = 2 || fail "מנהל ב נכנס לארגון 2 ($code)"
code="$(get a2 list '&month=2026-10')"; test "$code" = 200 && test "$(jv 'count($v["records"])')" = 0 || fail "A4: ארגון 2 רואה תיקים של 1"
before="$(sql "SELECT body FROM funerals WHERE id='$CID'")"
code="$(post a2 save "$(mut '$r["notes"]="פריצה";')")"; test "$code" = 404 || fail "A4: save לתיק של ארגון 1 ($code)"
code="$(post a2 import "{\"records\":[$(mut '$r["notes"]="פריצה";')]}")"; test "$code" = 404 || fail "A4: import לתיק של ארגון 1 ($code)"
test "$(sql "SELECT body FROM funerals WHERE id='$CID'")" = "$before" || fail "A4: התיק השתנה"
code="$(post a2 save '{"month":"2026-10","name":"נפטר בדיקה 1","date":"2026-10-01"}')"; test "$code" = 200 || fail "A4: תיק חדש בארגון 2 ($code)"
test "$(sql "SELECT COUNT(*) FROM funerals WHERE org_id=2")" = 1 || fail "A4: התיק לא בארגון 2"
code="$(get boss list '&month=2026-10')"; test "$(jv 'count($v["records"])')" = 24 || fail "A4: ארגון 1 רואה $(jv 'count($v["records"])')"
code="$(post a2 org_switch '{"org_id":1}')"; test "$code" = 404 || fail "A4: מעבר לארגון זר ($code)"
code="$(get a2 members)"; test "$(jv 'count($v["members"])')" = 1 || fail "A4: חברים של ארגון אחר"
# ביקורת 2: הזמנה של מייל קיים — ממתינה, לא מצרפת ולא חושפת
code="$(post a2 invite '{"email":"boss@example.com","role":"admin"}')"; test "$code" = 200 || fail "a2 מזמין את boss ($code)"
test "$(jv 'count(array_filter($v["members"], fn($m) => $m["email"] === "boss@example.com"))')" = 0 || fail "ביקורת 2: boss ברשימת החברים לפני כניסה"
test "$(sql "SELECT COUNT(*) FROM members WHERE org_id=2 AND user_id=(SELECT id FROM users WHERE sub='2')")" = 0 || fail "ביקורת 2: צורף מיד"
# ביקורת 1: השבתה בארגון ב' לא נוגעת בארגון א'
login boss; test "$(sql "SELECT role FROM members WHERE org_id=2 AND user_id=(SELECT id FROM users WHERE sub='2')")" = admin || fail "boss לא הצטרף לארגון 2 בכניסה"
BOSS="$(sql "SELECT id FROM users WHERE sub='2'")"
code="$(post a2 member_active "{\"user_id\":$BOSS,\"active\":false}")"; test "$code" = 200 || fail "a2 משבית את boss בארגון 2 ($code)"
login boss; test "$code" = 200 && test "$(jv '$v["org"]["id"]')" = 1 || fail "ביקורת 1: boss נחסם בארגון 1 ($code)"
code="$(get boss list '&month=2026-10')"; test "$code" = 200 || fail "ביקורת 1: boss list בארגון 1 ($code)"
test "$(sql "SELECT active FROM users WHERE id=$BOSS")" = 1 || fail "ביקורת 1: users.active השתנה"
code="$(post boss user_active "{\"user_id\":$BOSS,\"active\":false}")"; test "$code" = 403 || fail "ביקורת 1: user_active למנהל ($code)"
code="$(post dev org_switch '{"org_id":1}')"; test "$code" = 200 || fail "חזרה לארגון 1"
ok "A4 — ארגון 2 לא רואה ולא משנה תיק של ארגון 1 (404)"

# ===== A8 (שלילי): מסך המפתח — רק למפתח =====
for a in dev_health dev_requests dev_events orgs dev-items dev-tokens; do
  code="$(get boss "$a")"; test "$code" = 403 || fail "A8: $a למנהל ($code)"
done
code="$(post boss org_create '{"name":"x"}')"; test "$code" = 403 || fail "A8: org_create למנהל ($code)"
code="$(post boss org_rename '{"org_id":1,"name":"x"}')"; test "$code" = 403 || fail "A8: org_rename למנהל ($code)"
code="$(post boss dev-task-create '{"title":"x"}')"; test "$code" = 403 || fail "A8: dev-task-create למנהל ($code)"
code="$(get dev orgs)"; test "$(jv 'count($v["orgs"])')" = 2 || fail "A8: המפתח רואה את כל הארגונים"
code="$(post dev org_rename '{"org_id":2,"name":"ארגון ב"}')"; test "$code" = 200 || fail "שינוי שם ארגון ($code)"
code="$(post dev dev-task-create '{"title":"בדיקת DevLoop"}')"; test "$code" = 200 || fail "DevLoop: מטלה חדשה ($code)"
jv '$v["code"]' | grep -qE "^'FD-[0-9]{3}'$" || fail "DevLoop: קוד FD-"
code="$(get dev dev-items)"; test "$code" = 200 || fail "DevLoop: dev-items ($code)"
ok "A8 — מסך מפתח, ארגונים ו-DevLoop: 403 לכל מי שאינו מפתח"

# ===== A12: מנהל אחרון =====
code="$(post a2 member_role "{\"user_id\":$(sql "SELECT id FROM users WHERE sub='5'"),\"role\":\"field\"}")"; test "$code" = 400 || fail "A12: מנהל אחרון ($code)"
test "$(sql "SELECT role FROM members WHERE org_id=2 AND user_id=(SELECT id FROM users WHERE sub='5')")" = admin || fail "A12: התפקיד השתנה"
ok "A12 — מנהל יחיד שמוריד את עצמו → 400"

# ===== A13: מושבת =====
OFF="$(sql "SELECT id FROM users WHERE sub='4'")"
code="$(post boss member_active "{\"user_id\":$OFF,\"active\":false}")"; test "$code" = 200 || fail "השבתה ($code)"
login office; test "$code" = 403 || fail "A13: me ($code)"
code="$(get office list '&month=2026-10')"; test "$code" = 403 || fail "A13: list ($code)"
code="$(get office export '&month=2026-10')"; test "$code" = 403 || fail "A13: export ($code)"
code="$(post office save "$(mut '$r["notes"]="מושבת";')")"; test "$code" = 403 || fail "A13: save ($code)"
code="$(post office import '{"records":[]}')"; test "$code" = 403 || fail "A13: import ($code)"
code="$(post boss member_active "{\"user_id\":$OFF,\"active\":true}")"; test "$code" = 200 || fail "הפעלה מחדש ($code)"
login office; test "$code" = 200 || fail "A13: אחרי הפעלה מחדש ($code)"
ok "A13 — active=0 → 403 על כל פעולה"

# ===== A11: סודות =====
php -r 'chdir($argv[1]); require "lib.php"; db(); secretPut("t", 1, "abc"); if (secretGet("t", 1) !== "abc" || secretGet("t", 2) !== null) exit(1);' "$APP" || fail "A11: secretGet"
test "$(sql "SELECT COUNT(*) FROM secrets WHERE name='t' AND value_enc LIKE '%abc%'")" = 0 || fail "A11: הערך בגלוי"
test "$(stat -c %a "$D/secret.key")" = 600 && test "$(stat -c %s "$D/secret.key")" = 32 || fail "A11: secret.key"
ok "A11 — secretPut/secretGet, מוצפן, 0600"

# ===== אסימון ingest.php מהממשק (כמו קודם): CSRF, SHA-256, 0600, החלפה מבטלת =====
code="$(as "${ID[boss]}"; st -b "$tmp/c-boss" -X POST -H 'Content-Type: application/json' -d '{}' "$base?action=api_token")"; test "$code" = 403 || fail "api_token בלי CSRF ($code)"
code="$(as "${ID[boss]}"; st -b "$tmp/c-boss" -X POST -H 'X-CSRF-Token: wrong' -H 'Content-Type: application/json' -d '{}' "$base?action=api_token")"; test "$code" = 403 || fail "api_token CSRF שגוי ($code)"
code="$(post field api_token '{}')"; test "$code" = 403 || fail "api_token לעובד שטח ($code)"
code="$(post a2 api_token '{}')"; test "$code" = 403 || fail "api_token למנהל ארגון 2 ($code)"
code="$(post boss api_token '{}')"; test "$code" = 200 || fail "api_token ($code)"
tok1="$(jv '$v["token"]' | tr -d "'")"; test -n "$tok1" || fail "לא הוחזר אסימון"
! grep -q "$tok1" "$D/config.php" || fail "האסימון נשמר בגלוי"
grep -q "$(printf '%s' "$tok1" | sha256sum | cut -d' ' -f1)" "$D/config.php" || fail "ה-SHA-256 לא נשמר"
test "$(stat -c %a "$D/config.php")" = 600 || fail "config.php לא 0600"
code="$(st -H "Authorization: Bearer $TOK0" "$ingest?action=list&month=2026-10")"; test "$code" = 401 || fail "אסימון ישן אחרי החלפה ($code)"
code="$(st -H "Authorization: Bearer $tok1" "$ingest?action=list&month=2026-10")"; test "$code" = 200 || fail "אסימון חדש ($code)"
# session של משתמש אחר: CSRF של boss לא עובר אצל field
code="$(as "${ID[field]}"; st -b "$tmp/c-boss" -H "X-CSRF-Token: ${CSRF[boss]}" -H 'Content-Type: application/json' -d '{}' "$base?action=api_token")"; test "$code" = 403 || fail "CSRF של sub אחר ($code)"
login boss; test "$code" = 200 || fail "boss מחדש"
ok "אסימון ingest.php: CSRF, SHA-256, 0600, רק מנהל ארגון 1, החלפה מבטלת"

# ===== עדכון ידני גובר על ייבוא חוזר (כמו קודם) =====
code="$(get boss list '&month=2026-10')"
php -r '$v=json_decode(file_get_contents($argv[1]),true); foreach($v["records"] as $r) if($r["name"]==="שם בדיקה בלבד"){ $r["checks"]["license_number"]=["status"=>"reported","value"=>"123","note"=>"עדכון משתמש"]; echo json_encode($r,JSON_UNESCAPED_UNICODE);}' "$tmp/out" > "$tmp/update.json"
code="$(post boss save "$(cat "$tmp/update.json")")"; test "$code" = 200 || fail "שמירה ידנית ($code)"
code="$(st -H 'Content-Type: application/json' -H "Authorization: Bearer $tok1" --data '{"records":[{"month":"2026-10","name":"שם בדיקה בלבד","date":"2026-10-01","checks":{"license_number":{"status":"verified","value":"999"}}}]}' "$ingest?action=import")"
test "$code" = 200 || fail "ייבוא חוזר ($code)"
code="$(st -H "Authorization: Bearer $tok1" "$ingest?action=export&month=2026-10")"
php -r '$v=json_decode(file_get_contents($argv[1]),true); foreach($v["records"] as $r) if($r["name"]==="שם בדיקה בלבד" && ($r["checks"]["license_number"]["value"]??"")==="123") exit(0); exit(1);' "$tmp/out" || fail "עדכון ידני נדרס"
ok "עדכון ידני גובר על ייבוא חוזר"

# ===== A16 (חלקי): בקשה שנכשלה מופיעה ביומן השגיאות =====
code="$(get dev nonexistent_action)"; test "$code" = 404 || fail "פעולה לא מוכרת ($code)"
code="$(get dev dev_requests '&errors=1')"; test "$code" = 200 || fail "dev_requests ($code)"
php -r '$v=json_decode(file_get_contents($argv[1]),true); foreach($v["rows"] as $r) if($r["action"]==="nonexistent_action" && (int)$r["status"]===404) exit(0); exit(1);' "$tmp/out" || fail "A16: השגיאה לא ביומן"
php -r '$v=json_decode(file_get_contents($argv[1]),true); foreach($v["rows"] as $r) if((int)$r["status"]<400) exit(1);' "$tmp/out" || fail "A16: סינון רק שגיאות"
code="$(get dev dev_events)"; test "$code" = 200 && test "$(jv 'count($v["rows"]) > 5')" = true || fail "dev_events ($code)"
ok "A16 — שגיאה ביומן הבקשות; יומן פעולות"

# ===== פרוסה 02 — טופס חכם, מול השרת =====
code="$(get boss list '&month=2026-10')"; cp "$tmp/out" "$tmp/l02.json"
pick(){ php -r '$v=json_decode(file_get_contents($argv[1]),true); foreach($v["records"] as $r) if($r["name"]===$argv[2]) { echo json_encode($r,JSON_UNESCAPED_UNICODE); exit; } exit(1);' "$tmp/l02.json" "$1"; }
# B1: שמירה מהממשק — by/via:ui/at רק לבדיקה ששונתה; by/via/at מהקלט מתעלמים
pick 'נפטר בדיקה 1' > "$tmp/c1.json"
code="$(post boss save "$(php -r '$r=json_decode(file_get_contents($argv[1]),true); $r["checks"]["hall_service"]=["status"=>"verified","value"=>"כן","note"=>"","by"=>"hacker","via"=>"import","at"=>"1999-01-01"]; echo json_encode($r,JSON_UNESCAPED_UNICODE);' "$tmp/c1.json")")"
test "$code" = 200 || fail "B1: save ($code)"
php -r '$v=json_decode(file_get_contents($argv[1]),true)["record"]["checks"]; $was=json_decode(file_get_contents($argv[2]),true)["checks"]; $h=$v["hall_service"];
  if ($h["status"]!=="verified" || $h["via"]!=="ui" || $h["by"]!=="boss@example.com" || $h["at"]==="1999-01-01") exit(1);
  if ($v["license_number"]!==$was["license_number"]) exit(2);' "$tmp/out" "$tmp/c1.json" || fail "B1: מקור רק לבדיקה ששונתה, וקלט by/via/at מתעלם"
ok "B1 — by/via:ui/at לבדיקה ששונתה בלבד; קלט מתעלם"
# B2: קריאה ישירה לפונקציית השמירה עם via: api — verified → document_present, וההצעה ב-note; נרשם ב-events כ-api
php -r 'chdir($argv[1]); require "lib.php"; $p=db(); $id=$p->query("SELECT id FROM funerals WHERE name=\x27נפטר בדיקה 3\x27")->fetchColumn();
  $r=existing($p,$id,1); $r["checks"]["license_number"]=["status"=>"verified","value"=>"555","note"=>""];
  $s=formSaveCase($p,$r,"api",["id"=>null,"actor"=>"gpt@example.com"],1)["checks"]["license_number"];
  if ($s["status"]!=="document_present" || !str_contains($s["note"],"555") || $s["via"]!=="api") exit(1);
  if (existing($p,$id,1)["checks"]["license_number"]["status"]!=="document_present") exit(2);
  $e=$p->query("SELECT source FROM events WHERE case_id=\x27$id\x27 ORDER BY id DESC LIMIT 1")->fetchColumn(); if ($e!=="api") exit(3);' "$APP" || fail "B2: via api → לאימות"
ok "B2 — via: api שמסמן verified → document_present, ההצעה ב-note"
# B3: אותו מעבר דרך ingest.php (via: import) → verified
code="$(st -H 'Content-Type: application/json' -H "Authorization: Bearer $tok1" --data '{"records":[{"month":"2026-10","name":"נפטר בדיקה 2","date":"2026-10-02","checks":{"identity_document":{"status":"verified","value":"ת\"ז"}}}]}' "$ingest?action=import")"
test "$code" = 200 || fail "B3: ingest ($code)"
test "$(sql "SELECT json_extract(body,'\$.checks.identity_document.status')||'|'||json_extract(body,'\$.checks.identity_document.via') FROM funerals WHERE name='נפטר בדיקה 2'")" = 'verified|import' || fail "B3: ingest → verified, via=import"
ok "B3 — ui ו-ingest (import) → verified"
# B6: היסטוריה — שלושת השינויים האחרונים בסדר הפוך, עם מקור; ארגון אחר → 404
cid="$(php -r 'echo json_decode(file_get_contents($argv[1]),true)["id"];' "$tmp/c1.json")"
for n in א ב ג; do
  code="$(get boss list '&month=2026-10')"
  code="$(post boss save "$(php -r '$v=json_decode(file_get_contents($argv[1]),true); foreach($v["records"] as $r) if($r["id"]===$argv[2]) { $r["notes"]="הערה ".$argv[3]; echo json_encode($r,JSON_UNESCAPED_UNICODE); }' "$tmp/out" "$cid" "$n")")"
  test "$code" = 200 || fail "B6: save $n ($code)"
done
code="$(get boss history "&id=$cid")"; test "$code" = 200 || fail "B6: history ($code)"
test "$(jv 'implode(",", array_map(fn($e) => $e["source"].":".(array_values(array_filter($e["changes"], fn($c) => $c["field"]==="notes"))[0]["after"] ?? ""), array_slice($v["events"],0,3)))')" = "'ui:הערה ג,ui:הערה ב,ui:הערה א'" || fail "B6: שלושה אחרונים בסדר הפוך עם מקור"
code="$(get a2 history "&id=$cid")"; test "$code" = 404 || fail "B6: ארגון אחר → 404 ($code)"
code="$(get boss history "&id=fd_no_such_case_000")"; test "$code" = 404 || fail "B6: תיק שלא קיים ($code)"
code="$(get boss history '&id=../x')"; test "$code" = 400 || fail "B6: מזהה לא תקין ($code)"
code="$(post boss history '{}' "&id=$cid")"; test "$code" = 405 || fail "B6: POST ($code)"
ok "B6 — היסטוריה הפוכה עם מקור; ארגון אחר → 404"
# B7: 22 התיקים (fixture-v0 של 01, ועוד 22 בצורות ישנות שונות) נפתחים: list, form.js, ו-history לכל אחד
php -r '$p=new PDO("sqlite:".$argv[1]); $a=json_decode(file_get_contents($argv[2]),true); $st=$p->prepare("INSERT INTO funerals(id,month,name,body,updated_at,org_id) VALUES(?,?,?,?,?,1)");
  foreach($a as $r){ $r["month"]="2026-09"; $st->execute([$r["id"],$r["month"],$r["name"],json_encode($r,JSON_UNESCAPED_UNICODE),"2026-10-01T00:00:00Z"]); }' "$DB" "$APP/tests/fixtures/legacy-cases.json"
for m in 2026-10 2026-09; do
  code="$(get boss list "&month=$m")"; test "$code" = 200 || fail "B7: list $m ($code)"; cp "$tmp/out" "$tmp/b7-$m.json"
  node "$APP/tests/form.test.js" "$tmp/b7-$m.json" >/dev/null || fail "B7: form.js על התיקים של $m"
  for lid in $(php -r 'echo implode(" ", array_column(json_decode(file_get_contents($argv[1]),true)["records"],"id"));' "$tmp/b7-$m.json"); do
    code="$(get boss history "&id=$lid")"; test "$code" = 200 || fail "B7: history $lid ($code)"
  done
done
ok "B7 — 22 התיקים הקיימים (ועוד 22 ישנים) נפתחים; בדיקות בלי מקור = מייבוא ישן"

# ===== פרוסה 04 — עובד שטח (HTTP) =====
TODAY="$(php -r 'echo (new DateTime("now", new DateTimeZone("Asia/Jerusalem")))->format("Y-m-d");')"
TOMOR="$(php -r 'echo (new DateTime("tomorrow", new DateTimeZone("Asia/Jerusalem")))->format("Y-m-d");')"
LATER="$(php -r 'echo (new DateTime("+3 days", new DateTimeZone("Asia/Jerusalem")))->format("Y-m-d");')"
for x in "שטח היום|$TODAY|15:00" "שטח בוקר|$TODAY|08:00" "שטח מחר|$TOMOR|" "שטח בעוד שלושה|$LATER|10:00"; do
  IFS='|' read -r n d tm <<< "$x"
  code="$(post boss save "{\"month\":\"${d:0:7}\",\"name\":\"$n\",\"date\":\"$d\",\"time\":\"$tm\"}")"; test "$code" = 200 || fail "04: תיק $n ($code)"
done
code="$(get field field_today)"; test "$code" = 200 || fail "W1: field_today ($code)"
test "$(jv 'implode(",", array_column($v["cases"], "name"))')" = "'שטח בוקר,שטח היום,שטח מחר'" || fail "W1: היום ומחר בלבד, לפי שעה: $(jv 'implode(",", array_column($v["cases"], "name"))')"
test "$(jv 'isset($v["cases"][0]["payments"]) ? 1 : 0')" = 0 || fail "W1: תשלומים במסך השטח"
code="$(get field field_search '&q=%D7%A0%D7%A4%D7%98%D7%A8')"; test "$code" = 200 || fail "W1: חיפוש ($code)"
test "$(jv 'count(array_filter($v["cases"], fn($c) => substr($c["date"],0,7) === "2026-10"))')" -gt 0 || fail "W1: חיפוש מוצא תיק מחודש אחר"
code="$(get a2 field_search '&q=%D7%A9%D7%98%D7%97')"; test "$code" = 200 && test "$(jv 'count($v["cases"] ?? [])')" = 0 || fail "W1: חיפוש לא חוצה ארגון"
FID="$(sql "SELECT id FROM funerals WHERE name='שטח היום'")"
code="$(get field field_case "&id=$FID")"; test "$code" = 200 || fail "04: field_case ($code)"
test "$(jv 'implode(",", $v["needs"])')" = "'grave_photo,grave'" || fail "04: מה חסר מהשטח"
test "$(jv 'isset($v["case"]["payments"]) ? 1 : 0')" = 0 || fail "04: field_case בלי תשלומים"
code="$(get a2 field_case "&id=$FID")"; test "$code" = 404 || fail "04: field_case של ארגון אחר → 404 ($code)"
code="$(get nobody field_today)"; test "$code" = 403 || fail "04: בלי ארגון → 403 ($code)"
# W5: שם או תשלום דרך field_save → 403, ושום דבר לא נשמר
before="$(sql "SELECT body FROM funerals WHERE id='$FID'")"
code="$(post field field_save "{\"id\":\"$FID\",\"name\":\"שם אחר\"}")"; test "$code" = 403 || fail "W5: שם דרך field_save ($code)"
code="$(post field field_save "{\"id\":\"$FID\",\"payments\":[{\"amount\":5}]}")"; test "$code" = 403 || fail "W5: תשלום דרך field_save ($code)"
code="$(post field field_save "{\"id\":\"$FID\",\"notes\":\"x\",\"date\":\"2020-01-01\"}")"; test "$code" = 403 || fail "W5: תאריך דרך field_save ($code)"
test "$(sql "SELECT body FROM funerals WHERE id='$FID'")" = "$before" || fail "W5: משהו נשמר אחרי 403"
code="$(as "${ID[field]}"; st -b "$tmp/c-field" -H 'Content-Type: application/json' -d "{\"id\":\"$FID\",\"notes\":\"x\"}" "$base?action=field_save")"; test "$code" = 403 || fail "04: field_save בלי CSRF ($code)"
# עדכון מהשטח: בדיקה "אומת" בידי העובד (02 §2.3), מיקום קבר והערה → via: field
code="$(post field field_save "{\"id\":\"$FID\",\"checks\":{\"license_document\":{\"status\":\"verified\"}},\"grave\":\"גוש 4 חלקה 2\",\"notes\":\"צולם\"}")"; test "$code" = 200 || fail "04: field_save ($code)"
test "$(sql "SELECT json_extract(body,'\$.checks.license_document.status')||'|'||json_extract(body,'\$.checks.license_document.via')||'|'||json_extract(body,'\$.grave')||'|'||json_extract(body,'\$.name') FROM funerals WHERE id='$FID'")" = 'verified|field|גוש 4 חלקה 2|שטח היום' || fail "04: field_save → verified, via field"
test "$(sql "SELECT source||'|'||action||'|'||actor FROM events WHERE case_id='$FID' ORDER BY id DESC LIMIT 1")" = 'ui|save|x@example.com' || fail "04: field_save ב-events"
# W7: Dropbox לא מחובר (03 עוד לא בקוד, או מנותק) → הודעה ברורה, לא שגיאה שקטה
printf '\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9' > "$tmp/p.jpg"
up(){ as "${ID[$1]}"; st -b "$tmp/c-$1" -H "X-CSRF-Token: ${CSRF[$1]:-}" -F "case_id=$2" -F "upload_id=$3" -F "kind=$4" -F "file=@$5" "$base?action=field_upload"; }
# (ארגון 1 לא מחובר ל-Dropbox בבדיקות האלה — גם אחרי פרוסה 03)
code="$(up field "$FID" up_w7_00000000000000 grave_photo "$tmp/p.jpg")"
  test "$code" = 409 && test "$(jv '$v["error"]')" = "'Dropbox של הארגון לא מחובר — פנה למנהל'" && test "$(jv '$v["code"]')" = "'dropbox_not_connected'" || fail "W7: Dropbox לא מחובר ($code)"
  test "$(sql "SELECT status FROM field_uploads WHERE upload_id='up_w7_00000000000000'")" = failed || fail "W7: לא נרשם כנכשל"
printf '<svg xmlns="http://www.w3.org/2000/svg"/>' > "$tmp/x.svg"
code="$(up field "$FID" up_svg_0000000000000 other "$tmp/x.svg")"; test "$code" = 415 || fail "04: SVG → 415 ($code)"
cp "$tmp/p.jpg" "$tmp/x.html"; code="$(up field "$FID" up_html_000000000000 other "$tmp/x.html")"; test "$code" = 415 || fail "ביקורת 7: JPEG בשם .html → 415 ($code)"
printf '%%PDF-1.4\n%%%%EOF\n' > "$tmp/r.pdf"; code="$(up field "$FID" up_pdfg_000000000000 grave_photo "$tmp/r.pdf")"; test "$code" = 415 || fail "ביקורת 7: PDF כצילום קבר → 415 ($code)"
code="$(up field "$FID" up_pdfo_000000000000 license "$tmp/r.pdf")"; test "$code" = 409 || fail "ביקורת 7: PDF כרישיון — עובר את הבדיקה (409 לא מחובר) ($code)"
code="$(up a2 "$FID" up_a2_00000000000000 other "$tmp/p.jpg")"; test "$code" = 404 || fail "04: העלאה לתיק של ארגון אחר → 404 ($code)"
code="$(up field "$FID" bad other "$tmp/p.jpg")"; test "$code" = 400 || fail "04: מזהה העלאה לא תקין ($code)"
code="$(get field field_upload)"; test "$code" = 405 || fail "04: GET field_upload ($code)"
ok "W1, W5, W7 (HTTP) — היום ומחר, חיפוש, עדכון via field, שם/תשלום → 403, Dropbox מנותק → הודעה ברורה"

# ===== יציאה: עם CSRF בלבד =====
code="$(as "${ID[boss]}"; st -b "$tmp/c-boss" -X POST "$base?action=logout")"; test "$code" = 403 || fail "logout בלי CSRF ($code)"
code="$(post boss logout '{}')"; test "$code" = 200 || fail "logout ($code)"
! grep -E 'PHP (Fatal|Warning|Parse)' "$tmp/server.log" | grep -vq 'in Unknown on line 0' || { grep -E 'PHP (Fatal|Warning|Parse)' "$tmp/server.log" | grep -v 'in Unknown on line 0'; fail "שגיאות PHP בשרת"; }
echo "PASS: פרוסה 01 — A1–A14, A16 (יומן), A17; זהות מהשער, CSRF, אסימון ingest, ייבוא חוזר; פרוסה 02 — B1–B7; פרוסה 04 — W1, W5, W7"

# ===== פרוסה 05 — API חיצוני (שרת נפרד, מסד נקי) =====
if [ "$pid" != 0 ]; then kill "$pid" >/dev/null 2>&1 || true; pid=0; fi
bash "$APP/tests/ext.sh"

# ===== פרוסה 03 — Dropbox מול Dropbox מדומה (שרתים משלה, מסד ריק) =====
wipe
php "$APP/tests/dropbox-test.php" || fail "פרוסה 03 — Dropbox"

# ===== פרוסה 04 — העלאה מהשטח (מזהה חד-פעמי, שיוך לבדיקה) מול העלאה מדומה, מסד נקי =====
wipe
php "$APP/tests/field.test.php" || fail "פרוסה 04 — field.test.php"
wipe
php "$APP/tests/field-dropbox.test.php" || fail "פרוסה 04 — מול Dropbox מדומה"
